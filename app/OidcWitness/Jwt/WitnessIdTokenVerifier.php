<?php

declare(strict_types=1);

namespace App\OidcWitness\Jwt;

use App\OidcWitness\Jwt\Exceptions\InvalidWitnessIdTokenException;
use App\OidcWitness\Support\WitnessCredentials;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Story 55.3 — **LE VÉRIFICATEUR CLIENT D'ID_TOKEN.**
 *
 * Miroir client de `FederatedJwtVerifier` (Epic 20), appliqué à un id_token
 * OIDC. C'est la pièce dont chaque laxisme deviendrait une faille de toutes les
 * extensions qui s'en inspireront : elle est donc écrite en refus par défaut, et
 * chaque vecteur d'attaque a SON test nommé
 * ({@see \Tests\Unit\OidcWitness\WitnessIdTokenVerifierTest}).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  GARANTIES
 *
 *  1. **RS256 PINNÉ par construction.** La key-map ne contient QUE des
 *     `Key($pem, 'RS256')` bâties depuis le JWKS PUBLIÉ. `firebase/php-jwt`
 *     n'accepte alors QUE RS256 : `alg: none` et la confusion d'algorithme
 *     symétrique (HS256 signé avec la clé publique comme secret) sont rejetés
 *     AVANT toute opération cryptographique. On ne déduit JAMAIS l'algorithme
 *     du header du jeton.
 *  2. **JWKS vide ⇒ tout est rejeté.** Ne rien pouvoir vérifier n'est pas une
 *     raison d'accepter.
 *  3. **`iss` === l'issuer du fichier de credentials**, pas celui annoncé par
 *     le jeton. Un id_token du collège voisin est rejeté même si son `aud`
 *     coïncidait.
 *  4. **`aud` === le `client_id` du témoin.** Un jeton émis pour un autre
 *     client de la MÊME instance est rejeté — c'est la défense contre la
 *     confusion de destinataire.
 *  5. **`exp` obligatoire**, `nbf` honoré s'il est présent, leeway
 *     `config('oidc.leeway')` — la même tolérance d'horloge des deux côtés.
 *  6. **`nonce` obligatoire dès lors que le témoin en a envoyé un** : c'est ce
 *     qui lie le jeton à CETTE demande d'autorisation.
 *  7. **`jti` à usage unique** ({@see WitnessJtiReplayGuard}).
 *
 *  ⚠️ Une clé étrangère est couverte par (1) : la key-map ne contient que les
 *  clés publiées par l'instance interrogée, donc une signature d'ailleurs
 *  échoue en signature — même si tous les claims étaient « bons ».
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Où la consommation du `jti` a lieu — décision, et pourquoi elle diverge de
 * l'Epic 20.** Là-bas (M1), le vérificateur ne consommait PAS : le contrôleur
 * le faisait APRÈS le provisioning de l'identité, pour ne pas brûler un `jti`
 * si une étape ultérieure échouait et rendait un retry légitime impossible.
 * Ici, il n'existe aucune étape ultérieure faillible : le témoin n'ouvre pas
 * de compte, il affiche. La consommation se fait donc DANS `verify()`, en
 * DERNIER — après toutes les autres validations, pour qu'un jeton invalide ne
 * consomme jamais rien (un attaquant ne doit pas pouvoir brûler par avance le
 * `jti` d'un jeton légitime).
 *
 * **Germe du SDK (Epic 58), pas le SDK.** Rien ici n'est extrait, publié ni
 * rendu générique : le kit sera extrait de BBB le moment venu (AR10).
 */
class WitnessIdTokenVerifier
{
    public function __construct(
        private readonly WitnessJtiReplayGuard $replay,
    ) {
    }

    /**
     * Vérifie un id_token et rend ses claims. Lève
     * {@see InvalidWitnessIdTokenException} avec un code stable en cas d'échec.
     *
     * @param  list<array<string, mixed>>  $jwks  Les clés PUBLIÉES par le
     *                                            fournisseur (récupérées par
     *                                            HTTP — voir
     *                                            `WitnessProviderMetadata`).
     * @param  string  $expectedNonce  Le `nonce` envoyé à l'autorisation.
     *                                 Vide = aucun n'a été envoyé (le témoin en
     *                                 envoie toujours un : le cas vide n'existe
     *                                 que pour les tests de contrat).
     * @return array<string, mixed>
     *
     * @throws InvalidWitnessIdTokenException
     */
    public function verify(
        string $idToken,
        WitnessCredentials $credentials,
        array $jwks,
        string $expectedNonce,
    ): array {
        if ($idToken === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $idToken) !== 1) {
            throw $this->reject(InvalidWitnessIdTokenException::malformed(), $idToken);
        }

        // Tolérance d'horloge — variable STATIQUE de la bibliothèque.
        JWT::$leeway = (int) config('oidc.leeway', 60);

        $keyMap = $this->buildKeyMap($jwks);
        if ($keyMap === []) {
            throw $this->reject(InvalidWitnessIdTokenException::jwksUnusable(), $idToken);
        }

        try {
            $decoded = JWT::decode($idToken, $keyMap);
        } catch (ExpiredException $e) {
            throw $this->reject(InvalidWitnessIdTokenException::expired($e), $idToken);
        } catch (BeforeValidException $e) {
            throw $this->reject(InvalidWitnessIdTokenException::notYetValid($e), $idToken);
        } catch (SignatureInvalidException $e) {
            throw $this->reject(InvalidWitnessIdTokenException::signatureInvalid($e), $idToken);
        } catch (UnexpectedValueException $e) {
            // Couvre `alg: none`, tout algorithme symétrique, un `kid` absent
            // de la key-map, un segment JSON invalide. De l'extérieur : quatre
            // façons de ne pas savoir signer.
            throw $this->reject(InvalidWitnessIdTokenException::signatureInvalid($e), $idToken);
        } catch (Throwable $e) {
            throw $this->reject(InvalidWitnessIdTokenException::malformed($e), $idToken);
        }

        /** @var array<string, mixed> $claims */
        $claims = (array) $decoded;

        // ── Claims standards obligatoires ────────────────────────────────
        $iss = $this->stringClaim($claims, 'iss');
        $sub = $this->stringClaim($claims, 'sub');
        $jti = $this->stringClaim($claims, 'jti');
        $exp = (int) ($claims['exp'] ?? 0);

        foreach (['iss' => $iss, 'sub' => $sub, 'jti' => $jti] as $name => $value) {
            if ($value === '') {
                throw $this->reject(InvalidWitnessIdTokenException::missingClaim($name), $idToken);
            }
        }

        if ($exp === 0) {
            throw $this->reject(InvalidWitnessIdTokenException::missingClaim('exp'), $idToken);
        }

        if (! $this->hasAudClaim($claims)) {
            throw $this->reject(InvalidWitnessIdTokenException::missingClaim('aud'), $idToken);
        }

        // ── L'instance : `iss` d'abord, `aud` ensuite ────────────────────
        $expectedIss = rtrim($credentials->issuer, '/');
        if ($expectedIss === '' || ! hash_equals($expectedIss, rtrim($iss, '/'))) {
            throw $this->reject(InvalidWitnessIdTokenException::issMismatch(), $idToken);
        }

        if (! hash_equals($credentials->clientId, $this->audClaim($claims, $credentials->clientId))) {
            throw $this->reject(InvalidWitnessIdTokenException::audMismatch(), $idToken);
        }

        // ── Le `nonce` : lie le jeton à CETTE demande ────────────────────
        if ($expectedNonce !== '') {
            $nonce = $this->stringClaim($claims, 'nonce');

            if ($nonce === '' || ! hash_equals($expectedNonce, $nonce)) {
                throw $this->reject(InvalidWitnessIdTokenException::nonceMismatch(), $idToken);
            }
        }

        // ── Usage unique du `jti`, EN DERNIER ────────────────────────────
        // Un jeton invalide n'a rien consommé : les refus ci-dessus sont tous
        // passés avant. Sinon un attaquant pourrait brûler à l'avance le `jti`
        // d'un jeton légitime avec un jeton contrefait.
        if (! $this->replay->consumeOnce($jti, $exp)) {
            throw $this->reject(InvalidWitnessIdTokenException::replayed(), $idToken);
        }

        return $claims;
    }

    // =====================================================================
    // Key-map : du JWKS publié aux clés vérifiables
    // =====================================================================

    /**
     * Construit `kid => Key($pem, 'RS256')` depuis le JWKS.
     *
     * ⚠️ **RS256 EXCLUSIVEMENT** — c'est ce qui ferme la confusion d'algorithme.
     * On n'honore JAMAIS un champ `alg` du JWKS qui vaudrait autre chose : une
     * clé annoncée `HS256` ou `none` est simplement ignorée, pas « supportée ».
     *
     * @param  list<array<string, mixed>>  $jwks
     * @return array<string, Key>
     */
    private function buildKeyMap(array $jwks): array
    {
        $map = [];

        foreach ($jwks as $jwk) {
            $kty = isset($jwk['kty']) && is_string($jwk['kty']) ? $jwk['kty'] : '';
            $alg = isset($jwk['alg']) && is_string($jwk['alg']) ? $jwk['alg'] : 'RS256';
            $use = isset($jwk['use']) && is_string($jwk['use']) ? $jwk['use'] : 'sig';
            $kid = isset($jwk['kid']) && is_string($jwk['kid']) ? $jwk['kid'] : '';
            $n = isset($jwk['n']) && is_string($jwk['n']) ? $jwk['n'] : '';
            $e = isset($jwk['e']) && is_string($jwk['e']) ? $jwk['e'] : '';

            if ($kty !== 'RSA' || $alg !== 'RS256' || $use !== 'sig' || $kid === '' || $n === '' || $e === '') {
                continue;
            }

            $pem = self::pemFromModulusAndExponent($n, $e);
            if ($pem === null) {
                continue;
            }

            $map[$kid] = new Key($pem, 'RS256');
        }

        return $map;
    }

    /**
     * Reconstruit un PEM `SubjectPublicKeyInfo` depuis les composantes `n`/`e`
     * d'un JWK RSA — le sens inverse de l'export JWKS du fournisseur, et
     * exactement ce que fait toute bibliothèque cliente OIDC. Refait à la main
     * pour n'ajouter AUCUNE dépendance (contrainte de la story).
     *
     * `null` ⇒ composantes inexploitables : la clé est ignorée, jamais
     * remplacée par un repli.
     */
    private static function pemFromModulusAndExponent(string $n, string $e): ?string
    {
        $modulus = self::base64UrlDecode($n);
        $exponent = self::base64UrlDecode($e);

        if ($modulus === '' || $exponent === '') {
            return null;
        }

        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
        $rsaPublicKey = self::asn1(0x30, self::asn1Integer($modulus) . self::asn1Integer($exponent));

        // AlgorithmIdentifier ::= SEQUENCE { OID rsaEncryption, NULL }
        $algorithm = (string) hex2bin('300d06092a864886f70d0101010500');

        // SubjectPublicKeyInfo ::= SEQUENCE { algorithm, subjectPublicKey BIT STRING }
        $spki = self::asn1(0x30, $algorithm . self::asn1(0x03, "\x00" . $rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function asn1Integer(string $bytes): string
    {
        // Un INTEGER DER est SIGNÉ : un premier octet ≥ 0x80 exige un 0x00 de
        // tête, sinon la valeur serait lue comme négative.
        if ($bytes !== '' && ord($bytes[0]) > 0x7F) {
            $bytes = "\x00" . $bytes;
        }

        return self::asn1(0x02, $bytes);
    }

    private static function asn1(int $tag, string $value): string
    {
        return chr($tag) . self::asn1Length(strlen($value)) . $value;
    }

    private static function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function base64UrlDecode(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return '';
        }

        $padded = str_pad(strtr($value, '-_', '+/'), (int) (ceil(strlen($value) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }

    // =====================================================================
    // Claims
    // =====================================================================

    /** @param array<string, mixed> $claims */
    private function stringClaim(array $claims, string $key): string
    {
        return isset($claims[$key]) && is_scalar($claims[$key]) ? (string) $claims[$key] : '';
    }

    /**
     * `aud` peut être une chaîne ou un tableau (RFC 7519). On rend l'audience
     * ATTENDUE si elle figure dans le tableau, la chaîne sinon — la comparaison
     * en temps constant reste à l'appelant.
     *
     * @param  array<string, mixed>  $claims
     */
    private function audClaim(array $claims, string $expected): string
    {
        $aud = $claims['aud'] ?? null;

        if (is_string($aud)) {
            return $aud;
        }

        if (is_array($aud)) {
            foreach ($aud as $value) {
                if (is_string($value) && $expected !== '' && hash_equals($expected, $value)) {
                    return $expected;
                }
            }
        }

        return '';
    }

    /**
     * `aud` présent (peu importe qu'il corresponde) ? Sert à distinguer
     * « claim manquant » de « mauvaise audience » — code d'erreur fidèle.
     *
     * @param  array<string, mixed>  $claims
     */
    private function hasAudClaim(array $claims): bool
    {
        $aud = $claims['aud'] ?? null;

        if (is_string($aud)) {
            return $aud !== '';
        }

        return is_array($aud) && $aud !== [];
    }

    /**
     * Journalise le refus et rend l'exception à lever.
     *
     * ⚠️ JAMAIS le jeton brut, JAMAIS les claims, JAMAIS de PII : un préfixe de
     * hash suffit à corréler un refus avec l'émission correspondante (patron
     * `FederatedJwtVerifier::logRejection()`).
     */
    private function reject(InvalidWitnessIdTokenException $e, string $idToken): InvalidWitnessIdTokenException
    {
        Log::channel('oidc')->warning('[WitnessIdTokenVerifier] oidc.witness.id_token.rejected', [
            'action_type' => 'oidc.witness.id_token.rejected',
            'code' => $e->errorCode,
            'token_hash_prefix' => substr(hash('sha256', $idToken), 0, 8),
        ]);

        return $e;
    }
}
