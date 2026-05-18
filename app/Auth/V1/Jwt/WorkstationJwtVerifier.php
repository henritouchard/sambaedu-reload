<?php

declare(strict_types=1);

namespace App\Auth\V1\Jwt;

use App\Auth\V1\Jwt\Exceptions\InvalidJwtException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Story 16.10 — AC2.2.
 *
 * Vérifie cryptographiquement et fonctionnellement un JWT poste.
 *
 * **Frontière** : seul fichier (avec `WorkstationJwtIssuer`) autorisé à
 * importer `Firebase\JWT\*` (cf. test archi `AuthV1NamespaceTest`).
 *
 * **Étapes** :
 *
 *  1. Décode le JWT via `JWT::decode($jwt, $keyMap)`. La lib `firebase/php-jwt`
 *     prend en charge `kid` automatiquement (regarde le header, lookup la
 *     key dans `$keyMap`). Si `kid` absent ou inconnu → exception ressort en
 *     `jwt.signature_invalid` (refus strict — D9).
 *  2. Vérifie le claim `tier` (= `expected_tier`, default `workstation`).
 *  3. Vérifie la révocation via `WorkstationJwtRevocationChecker` (cache + DB).
 *  4. Retourne un DTO `WorkstationJwtClaims` immuable.
 *
 * **Logging** : channel `auth-v1`, `auth.jwt.rejected` avec `code` et un
 * sous-ensemble de claims (jamais le JWT complet).
 */
class WorkstationJwtVerifier
{
    public function __construct(
        private readonly WorkstationJwtRevocationChecker $revocationChecker,
    ) {
    }

    /**
     * Vérifie un JWT. Lève {@see InvalidJwtException} avec un code stable
     * en cas d'échec.
     *
     * @param array{expected_tier?: string} $options
     */
    public function verify(string $jwt, array $options = []): WorkstationJwtClaims
    {
        $expectedTier = $options['expected_tier'] ?? 'workstation';

        if ($jwt === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $jwt)) {
            throw InvalidJwtException::malformed();
        }

        $keyMap = $this->buildKeyMap();
        if ($keyMap === []) {
            throw InvalidJwtException::signatureInvalid();
        }

        try {
            $decoded = JWT::decode($jwt, $keyMap);
        } catch (ExpiredException $e) {
            $this->logRejection('jwt.expired', $jwt);
            throw InvalidJwtException::expired($e);
        } catch (SignatureInvalidException | BeforeValidException $e) {
            $this->logRejection('jwt.signature_invalid', $jwt);
            throw InvalidJwtException::signatureInvalid($e);
        } catch (UnexpectedValueException $e) {
            // Couvre : kid inconnu (lib renvoie UnexpectedValueException),
            // header alg non supporté, JSON segments invalides.
            // Pour le `kid` inconnu = on assimile à signature_invalid (D9).
            $msg = $e->getMessage();
            if (stripos($msg, 'kid') !== false || stripos($msg, 'key') !== false) {
                $this->logRejection('jwt.signature_invalid', $jwt, ['lib_error' => $msg]);
                throw InvalidJwtException::signatureInvalid($e);
            }
            $this->logRejection('jwt.malformed', $jwt, ['lib_error' => $msg]);
            throw InvalidJwtException::malformed($e);
        } catch (Throwable $e) {
            $this->logRejection('jwt.malformed', $jwt, ['lib_error' => $e->getMessage()]);
            throw InvalidJwtException::malformed($e);
        }

        $payload = (array) $decoded;

        $sub = (string) ($payload['sub'] ?? '');
        $jti = (string) ($payload['jti'] ?? '');
        $tier = (string) ($payload['tier'] ?? '');
        $kid = (string) ($payload['kid'] ?? '');
        $iat = (int) ($payload['iat'] ?? 0);
        $exp = (int) ($payload['exp'] ?? 0);
        $iss = (string) ($payload['iss'] ?? '');

        if ($sub === '' || $jti === '' || $tier === '' || $kid === '' || $exp === 0) {
            $this->logRejection('jwt.malformed', $jwt, ['reason' => 'missing_required_claim']);
            throw InvalidJwtException::malformed();
        }

        // Vérifie le tier
        if ($tier !== $expectedTier) {
            $this->logRejection('jwt.wrong_tier', $jwt, [
                'expected' => $expectedTier,
                'found' => $tier,
                'sub' => $sub,
            ]);
            throw InvalidJwtException::wrongTier($expectedTier, $tier);
        }

        // Vérifie la révocation (cache APC + fallback DB).
        // Q3 review 16.10 : check workstation-wide via `sub` + `iat` — un JWT
        // émis avant un `workstation:revoke` est désormais effectivement invalidé.
        if ($this->revocationChecker->isRevoked($jti, $sub, $iat)) {
            $this->logRejection('jwt.revoked', $jwt, ['jti' => $jti, 'sub' => $sub]);
            throw InvalidJwtException::revoked();
        }

        return new WorkstationJwtClaims(
            sub: $sub,
            jti: $jti,
            tier: $tier,
            kid: $kid,
            iat: $iat,
            exp: $exp,
            iss: $iss,
        );
    }

    /**
     * Construit la map `kid => Key` à partir de config('auth_v1.jwt.keys').
     *
     * @return array<string, Key>
     */
    private function buildKeyMap(): array
    {
        $keys = config('auth_v1.jwt.keys', []);
        if (! is_array($keys) || $keys === []) {
            return [];
        }

        // Garde-fou symétrique au Issuer : en prod on refuse les fixtures test
        // (cf. WorkstationJwtIssuer::readPrivateKey). Sans ça, un déploiement
        // prod mal configuré pointant sur tests/fixtures/ accepterait des JWT
        // forgés avec la clé privée committée au repo.
        $forbidTestInProd = (bool) config('auth_v1.safety.forbid_test_keys_in_production', true);
        $env = app()->environment();
        $blockTestFixtures = $forbidTestInProd && $env !== 'testing' && $env !== 'local';

        $map = [];
        foreach ($keys as $kid => $paths) {
            if (! is_array($paths) || ! isset($paths['public'])) {
                continue;
            }
            $publicPath = (string) $paths['public'];
            if ($blockTestFixtures && str_contains($publicPath, '/tests/fixtures/')) {
                Log::channel('auth-v1')->critical('[WorkstationJwtVerifier] SECURITY : test fixture key referenced in non-testing env', [
                    'kid' => $kid,
                    'path' => $publicPath,
                    'env' => $env,
                ]);
                continue;
            }
            if (! is_file($publicPath) || ! is_readable($publicPath)) {
                continue;
            }
            $publicPem = file_get_contents($publicPath);
            if ($publicPem === false) {
                continue;
            }
            $map[(string) $kid] = new Key($publicPem, 'RS256');
        }

        return $map;
    }

    /**
     * Log une rejection — pas de JWT complet, seulement un préfixe + un
     * sous-ensemble de claims si exposables sans risque.
     *
     * @param array<string,mixed> $extra
     */
    private function logRejection(string $code, string $jwt, array $extra = []): void
    {
        Log::channel('auth-v1')->warning('[WorkstationJwtVerifier] auth.jwt.rejected', array_merge([
            'action_type' => 'auth.jwt.rejected',
            'code' => $code,
            // 8 premiers chars du hash sha256(jwt) — utile pour corréler les
            // logs sans révéler le token.
            'jwt_hash_prefix' => substr(hash('sha256', $jwt), 0, 8),
        ], $extra));
    }
}
