<?php

declare(strict_types=1);

namespace App\Auth\Federated\Jwt;

use App\Auth\Federated\Jwt\Exceptions\InvalidFederatedJwtException;
use App\Models\ControlHubConnection;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Story 20.1 — vérificateur de JWT fédéré (tier « utilisateur fédéré »).
 *
 * Calqué sur {@see \App\Auth\V1\Jwt\WorkstationJwtVerifier}, durci selon la
 * section archi « Sécurité du jeton JWT » (constats IR H1/M1/M4).
 *
 * **Frontière** : avec {@see FederatedJwtReplayChecker}, seul fichier du
 * namespace `App\Auth\Federated` autorisé à importer `Firebase\JWT\*` (cf.
 * test archi `FederatedRouteTest`).
 *
 * **Garanties de sécurité (H1)** :
 *
 *  - **Algorithme pinné `RS256`** : la key-map ne contient QUE des
 *    `Key($pem, 'RS256')`. `firebase/php-jwt` n'accepte alors QUE RS256 ;
 *    `alg:none` et tout algo symétrique (`HS256` signé avec la clé publique
 *    comme secret) sont rejetés AVANT toute vérification cryptographique.
 *    On ne déduit JAMAIS l'algo du header du jeton.
 *  - **Claims validés** : `sub`, `jti`, `kid`, `exp`, `nbf`, `iat`, `iss`
 *    (= émetteur configuré), `aud` (= identifiant de CETTE instance), `tier`,
 *    `role`. La lib valide `exp`/`nbf`/`iat` (avec `JWT::$leeway`). Le
 *    verifier valide explicitement `iss`/`aud`/`tier` + présence des claims.
 *  - **Anti-rejeu `jti`** : usage unique via {@see FederatedJwtReplayChecker}.
 *    ⚠️ La CONSOMMATION du `jti` n'est PAS faite ici : elle est déclenchée par
 *    {@see \App\Auth\Federated\Http\FederatedLoginController} EN DERNIER, après
 *    le provisioning réussi de l'identité/utilisateur, pour ne pas « brûler »
 *    un `jti` si le provisioning échoue (sinon un retry légitime serait bloqué
 *    — review M1). Ce vérificateur ne fait QUE valider (crypto + claims).
 *  - **Leeway ±60 s** : `JWT::$leeway = config('federated_auth.jwt.leeway')`.
 *
 * **Logging** : channel `federated-auth`, événement `federated.jwt.rejected`
 * avec un `code` + un préfixe de hash du jeton (JAMAIS le JWT brut, JAMAIS
 * les clés, JAMAIS de PII login/name/email).
 */
class FederatedJwtVerifier
{
    /**
     * Vérifie un JWT fédéré (signature RS256 + claims). Lève
     * {@see InvalidFederatedJwtException} avec un code stable en cas d'échec.
     * Retourne un DTO immuable si tout est valide.
     *
     * NB : ne consomme PAS le `jti` (anti-rejeu déclenché par le controller
     * après provisioning — review M1).
     */
    public function verify(string $jwt): FederatedUserClaims
    {
        if ($jwt === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $jwt)) {
            throw InvalidFederatedJwtException::malformed();
        }

        // Leeway d'horloge global (±60s). Variable statique de la lib.
        JWT::$leeway = (int) config('federated_auth.jwt.leeway', 60);

        $keyMap = $this->buildKeyMap();
        if ($keyMap === []) {
            // Aucune clé publique configurée → on ne peut rien vérifier.
            // Refus strict (pas de bypass).
            $this->logRejection('federated.jwt.signature_invalid', $jwt, ['reason' => 'no_keys_configured']);
            throw InvalidFederatedJwtException::signatureInvalid();
        }

        try {
            // La key-map impose RS256 par construction. `alg:none` / `HS*` →
            // UnexpectedValueException AVANT toute vérif crypto.
            $decoded = JWT::decode($jwt, $keyMap);
        } catch (ExpiredException $e) {
            $this->logRejection('federated.jwt.expired', $jwt);
            throw InvalidFederatedJwtException::expired($e);
        } catch (BeforeValidException $e) {
            $this->logRejection('federated.jwt.not_yet_valid', $jwt);
            throw InvalidFederatedJwtException::notYetValid($e);
        } catch (SignatureInvalidException $e) {
            $this->logRejection('federated.jwt.signature_invalid', $jwt);
            throw InvalidFederatedJwtException::signatureInvalid($e);
        } catch (UnexpectedValueException $e) {
            // Couvre : algo non autorisé (alg:none, HS256), kid inconnu, JSON
            // de segment invalide. Tout cela = refus signature (D9 iso-V1).
            $this->logRejection('federated.jwt.signature_invalid', $jwt, ['lib_error' => $e->getMessage()]);
            throw InvalidFederatedJwtException::signatureInvalid($e);
        } catch (Throwable $e) {
            $this->logRejection('federated.jwt.malformed', $jwt, ['lib_error' => $e->getMessage()]);
            throw InvalidFederatedJwtException::malformed($e);
        }

        $payload = (array) $decoded;

        // --- Extraction + validation de présence des claims requis ---
        $sub = $this->stringClaim($payload, 'sub');
        $jti = $this->stringClaim($payload, 'jti');
        $kid = $this->stringClaim($payload, 'kid');
        $iss = $this->stringClaim($payload, 'iss');
        $aud = $this->audClaim($payload);
        $tier = $this->stringClaim($payload, 'tier');
        $role = $this->stringClaim($payload, 'role');
        $iat = (int) ($payload['iat'] ?? 0);
        $exp = (int) ($payload['exp'] ?? 0);

        // Profil d'affichage (non sécurité-critique, mais requis fonctionnel).
        // Review 39.3 #E22 — passer par `stringClaim()` (garde `is_scalar`) comme tous
        // les autres claims : un `name`/`email` array JSON casterait sinon en `"Array"`
        // (warning PHP 8 + donnée corrompue silencieuse en base).
        $login = $this->stringClaim($payload, 'login');
        $name = $this->stringClaim($payload, 'name');
        $email = $this->stringClaim($payload, 'email');

        foreach (['sub' => $sub, 'jti' => $jti, 'kid' => $kid, 'iss' => $iss, 'tier' => $tier, 'role' => $role, 'login' => $login] as $claim => $value) {
            if ($value === '') {
                $this->logRejection('federated.jwt.missing_claim', $jwt, ['claim' => $claim]);
                throw InvalidFederatedJwtException::missingClaim($claim);
            }
        }
        if ($exp === 0) {
            $this->logRejection('federated.jwt.missing_claim', $jwt, ['claim' => 'exp']);
            throw InvalidFederatedJwtException::missingClaim('exp');
        }
        // `aud` réellement ABSENT (ni string ni array non-vide) → claim manquant.
        // `aud` PRÉSENT mais sans correspondance (string ≠ ou array sans match) →
        // `audClaim()` renvoie '' et la validation `hash_equals` ci-dessous lève
        // `aud_mismatch` (et non `missing_claim`) : code d'erreur fidèle (#3).
        if (! $this->hasAudClaim($payload)) {
            $this->logRejection('federated.jwt.missing_claim', $jwt, ['claim' => 'aud']);
            throw InvalidFederatedJwtException::missingClaim('aud');
        }

        // --- Validation fonctionnelle stricte (H1) ---

        // iss : émetteur attendu. Source DB (IdP réellement provisionné au
        // handshake) prioritaire, config env en repli explicite (cf. expectedIss()).
        $expectedIss = $this->expectedIss();
        if ($expectedIss === '' || ! hash_equals($expectedIss, $iss)) {
            $this->logRejection('federated.jwt.iss_mismatch', $jwt, ['iss' => $iss]);
            throw InvalidFederatedJwtException::issMismatch();
        }

        // aud : lie le jeton à CETTE instance (anti-rejeu inter-instance).
        $expectedAud = $this->expectedAud();
        if ($expectedAud === '' || ! hash_equals($expectedAud, $aud)) {
            $this->logRejection('federated.jwt.aud_mismatch', $jwt, ['sub' => $sub]);
            throw InvalidFederatedJwtException::audMismatch();
        }

        // tier : défense en profondeur — un JWT workstation ne doit pas ouvrir
        // une session humaine.
        $expectedTier = (string) config('federated_auth.expected_tier', 'federated-user');
        if (! hash_equals($expectedTier, $tier)) {
            $this->logRejection('federated.jwt.wrong_tier', $jwt, ['expected' => $expectedTier, 'found' => $tier]);
            throw InvalidFederatedJwtException::wrongTier($expectedTier, $tier);
        }

        // Anti-rejeu jti : NON consommé ici. Le controller appelle
        // `FederatedJwtReplayChecker::consumeOnce()` après le provisioning
        // réussi (review M1) — voir le docblock de classe.

        return new FederatedUserClaims(
            sub: $sub,
            jti: $jti,
            kid: $kid,
            iss: $iss,
            aud: $aud,
            tier: $tier,
            role: $role,
            login: $login,
            name: $name,
            email: $email,
            iat: $iat,
            exp: $exp,
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function stringClaim(array $payload, string $key): string
    {
        return isset($payload[$key]) && is_scalar($payload[$key]) ? (string) $payload[$key] : '';
    }

    /**
     * `aud` peut être string ou array (RFC 7519). On normalise : si array,
     * on accepte si l'audience attendue y figure (renvoie l'audience attendue
     * pour que `hash_equals` passe) ; sinon string directe.
     *
     * @param array<string,mixed> $payload
     */
    private function audClaim(array $payload): string
    {
        $aud = $payload['aud'] ?? null;

        if (is_string($aud)) {
            return $aud;
        }

        if (is_array($aud)) {
            $expected = $this->expectedAud();
            foreach ($aud as $value) {
                if (is_string($value) && $expected !== '' && hash_equals($expected, $value)) {
                    return $expected;
                }
            }
        }

        return '';
    }

    /**
     * Le claim `aud` est-il présent dans le payload (peu importe qu'il matche) ?
     * Sert à distinguer « aud absent » (claim manquant) de « aud présent mais
     * sans correspondance » (aud_mismatch). Cf. #3.
     *
     * @param array<string,mixed> $payload
     */
    private function hasAudClaim(array $payload): bool
    {
        $aud = $payload['aud'] ?? null;

        if (is_string($aud)) {
            return $aud !== '';
        }

        return is_array($aud) && $aud !== [];
    }

    /**
     * Émetteur (`iss`) attendu. Story 39.3 — symétrique à {@see expectedAud()} :
     * si l'IdP fédéré a réellement été provisionné au handshake
     * (`ControlHubConnection::current()?->hasFederatedIdp()`), la DB est la SEULE
     * source de vérité (`idp_iss`) ; sinon repli EXPLICITE sur la config env.
     * Aucune fusion : la config ne corrige jamais un handshake présent, elle ne
     * couvre que son ABSENCE (cf. commentaire F8 de `ControlHubConnection`).
     */
    private function expectedIss(): string
    {
        $connection = ControlHubConnection::current();
        if ($connection !== null && $connection->hasFederatedIdp()) {
            return (string) $connection->idp_iss;
        }

        return (string) config('federated_auth.expected_iss', '');
    }

    /**
     * Audience attendue = identifiant de CETTE instance SE5. `expected_aud`
     * (override explicite env) reste prioritaire ; à défaut, Story 39.3 fait
     * porter le repli par l'uuid d'instance (`controlHub.se4fs.instance_id`) —
     * l'identifiant que l'IdP amont emploie réellement comme `aud` — et non plus
     * `sambaedu.se4fs_name` (qui n'est pas cet identifiant).
     *
     * ⚠️ Rappel opérationnel (hors périmètre 39.3) : si `SE4FS_INSTANCE_ID`
     * n'est pas figé en `.env`, `config('controlHub.se4fs.instance_id')` par
     * défaut régénère un UUID aléatoire à chaque chargement de config
     * (`Str::uuid()`, cf. config/controlHub.php). Le provisioning
     * (`scripts/create-env.sh`) doit écrire cette valeur.
     */
    private function expectedAud(): string
    {
        $configured = (string) config('federated_auth.expected_aud', '');
        if ($configured !== '') {
            return $configured;
        }

        return (string) config('controlHub.se4fs.instance_id', '');
    }

    /**
     * Construit la key-map `kid => Key('RS256')`. Story 39.3 — source DB
     * prioritaire : si l'IdP fédéré a été provisionné au handshake
     * (`ControlHubConnection::current()?->hasFederatedIdp()`), la map n'a QU'UNE
     * entrée, bâtie DIRECTEMENT depuis la colonne PEM en clair (`idp_public_key`
     * n'est PAS un chemin de fichier) ; aucune fusion avec les clés de config.
     * Sinon, repli sur la résolution config existante ({@see buildKeyMapFromConfig()}),
     * strictement inchangée.
     *
     * @return array<string, Key>
     */
    private function buildKeyMap(): array
    {
        $connection = ControlHubConnection::current();
        if ($connection !== null && $connection->hasFederatedIdp()) {
            // PEM lu littéralement depuis la colonne texte (pas un path).
            // RS256 EXCLUSIVEMENT — parité stricte avec le chemin config.
            return [
                (string) $connection->idp_kid => new Key((string) $connection->idp_public_key, 'RS256'),
            ];
        }

        return $this->buildKeyMapFromConfig();
    }

    /**
     * Construit la map `kid => Key('RS256')` depuis
     * `config('federated_auth.jwt.keys')`. Garde-fou : refuse les fixtures de
     * test hors env testing/local (parité `WorkstationJwtVerifier`).
     *
     * @return array<string, Key>
     */
    private function buildKeyMapFromConfig(): array
    {
        $keys = config('federated_auth.jwt.keys', []);
        if (! is_array($keys) || $keys === []) {
            return [];
        }

        $forbidTestInProd = (bool) config('federated_auth.safety.forbid_test_keys_in_production', true);
        $env = app()->environment();
        $blockTestFixtures = $forbidTestInProd && $env !== 'testing' && $env !== 'local';

        $map = [];
        foreach ($keys as $kid => $paths) {
            if (! is_array($paths) || ! isset($paths['public'])) {
                continue;
            }
            $publicPath = (string) $paths['public'];
            if ($publicPath === '') {
                continue;
            }
            if ($blockTestFixtures && str_contains($publicPath, '/tests/fixtures/')) {
                Log::channel('federated-auth')->critical('[FederatedJwtVerifier] SECURITY: test fixture key referenced in non-testing env', [
                    'kid' => $kid,
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
            // ⚠️ RS256 EXCLUSIVEMENT — c'est ce qui ferme la confusion d'algo.
            $map[(string) $kid] = new Key($publicPem, 'RS256');
        }

        return $map;
    }

    /**
     * Log une rejection sur le channel `federated-auth`. JAMAIS le JWT brut,
     * JAMAIS les clés, JAMAIS de PII (login/name/email).
     *
     * @param array<string,mixed> $extra
     */
    private function logRejection(string $code, string $jwt, array $extra = []): void
    {
        Log::channel('federated-auth')->warning('[FederatedJwtVerifier] federated.jwt.rejected', array_merge([
            'action_type' => 'federated.jwt.rejected',
            'code' => $code,
            'jwt_hash_prefix' => substr(hash('sha256', $jwt), 0, 8),
        ], $extra));
    }
}
