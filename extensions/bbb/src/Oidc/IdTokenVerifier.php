<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

use Throwable;

/**
 * Story 57.1 — **LE VÉRIFICATEUR CLIENT D'ID_TOKEN.**
 *
 * Portage de la référence écrite et testée par la Story 55.3 (le témoin SSO du
 * core) : **l'algorithme, pas le fichier** — le témoin vit dans une zone en
 * quarantaine du core, dont l'extension n'a pas le droit de dépendre (FR33), et
 * il s'appuie sur une bibliothèque JWT et sur le cache de l'hôte, dont
 * l'extension ne dispose pas non plus. Ce qui se porte, c'est la liste des
 * refus et l'ordre dans lequel ils tombent.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  GARANTIES (chacune adossée à un test NOMMÉ de la suite d'attaque portée)
 *
 *  1. **RS256 PINNÉ par construction.** La key-map ne contient que des clés
 *     RSA bâties depuis le JWKS PUBLIÉ par l'issuer, et l'algorithme de
 *     vérification est écrit en dur. L'`alg` du header du jeton ne sert JAMAIS
 *     à choisir comment vérifier : il doit valoir `RS256`, sinon refus. Cela
 *     ferme d'un bloc `alg: none` et la confusion d'algorithme symétrique
 *     (HS256 signé avec la clé publique, qui est publique par définition).
 *  2. **JWKS vide ⇒ tout est rejeté.** Ne rien pouvoir vérifier n'est pas une
 *     raison d'accepter.
 *  3. **`iss` === l'issuer des credentials**, pas celui annoncé par le jeton.
 *  4. **`aud` === le `client_id` de l'extension** — défense contre la confusion
 *     de destinataire : une extension voisine ne rejoue pas ici le jeton
 *     qu'elle a légitimement reçu.
 *  5. **`exp` obligatoire**, `nbf`/`iat` honorés s'ils sont présents, leeway
 *     de 60 s — la tolérance d'horloge du contrat, des deux côtés.
 *  6. **`nonce` obligatoire dès lors qu'on en a envoyé un** : c'est ce qui lie
 *     le jeton à CETTE demande d'autorisation.
 *  7. **`jti` à usage unique** ({@see ReplayGuard}), consommé EN DERNIER.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Pourquoi le `jti` se consomme en dernier.** Après toutes les autres
 * validations : sinon un attaquant brûlerait à l'avance le `jti` d'un jeton
 * légitime en présentant un contrefait portant le même identifiant — un déni de
 * service silencieux sur la connexion d'un utilisateur précis.
 *
 * **Zéro dépendance.** L'extension n'a QU'UNE dépendance composer (le fork BBB).
 * La reconstruction d'un PEM depuis les composantes `n`/`e` d'un JWK RSA, et la
 * vérification RS256, se font donc avec `ext-openssl` seul — c'est exactement ce
 * que fait toute bibliothèque cliente OIDC, en moins de lignes.
 */
final class IdTokenVerifier
{
    /** Tolérance d'horloge, en secondes (contrat OIDC de SE5). */
    public const LEEWAY = 60;

    public function __construct(private readonly ReplayGuard $replay)
    {
    }

    /**
     * Vérifie un id_token et rend ses claims.
     *
     * @param  list<array<string, mixed>>  $jwks  Les clés PUBLIÉES par l'issuer
     *                                            (récupérées par HTTP — voir
     *                                            {@see ProviderMetadata}).
     * @param  string  $expectedNonce  Le `nonce` envoyé à l'autorisation. Vide =
     *                                 aucun envoyé (l'extension en envoie
     *                                 toujours un ; le cas vide n'existe que
     *                                 pour les tests de contrat).
     * @return array<string, mixed>
     *
     * @throws InvalidIdTokenException
     */
    public function verify(string $idToken, Credentials $credentials, array $jwks, string $expectedNonce): array
    {
        if ($idToken === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $idToken) !== 1) {
            throw InvalidIdTokenException::malformed();
        }

        // Défense en profondeur : un `clientId` vide rendrait la comparaison
        // d'audience silencieusement permissive (`hash_equals('', '')` vaut
        // `true`). Une propriété de sécurité ne doit pas dépendre en silence
        // d'une garde posée dans une autre classe.
        if ($credentials->clientId === '') {
            throw InvalidIdTokenException::malformed();
        }

        $keyMap = self::buildKeyMap($jwks);
        if ($keyMap === []) {
            throw InvalidIdTokenException::jwksUnusable();
        }

        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw InvalidIdTokenException::malformed();
        }

        [$rawHeader, $rawPayload, $rawSignature] = $segments;

        $header = self::decodeSegment($rawHeader);
        if ($header === null) {
            throw InvalidIdTokenException::malformed();
        }

        // ── La signature : algorithme PINNÉ, `kid` connu, vérification ────
        $alg = isset($header['alg']) && is_string($header['alg']) ? $header['alg'] : '';
        $kid = isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : '';

        if ($alg !== 'RS256' || $kid === '' || ! isset($keyMap[$kid])) {
            throw InvalidIdTokenException::signatureInvalid();
        }

        $signature = self::base64UrlDecode($rawSignature);
        if ($signature === '') {
            throw InvalidIdTokenException::signatureInvalid();
        }

        $verified = @openssl_verify(
            $rawHeader . '.' . $rawPayload,
            $signature,
            $keyMap[$kid],
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw InvalidIdTokenException::signatureInvalid();
        }

        $claims = self::decodeSegment($rawPayload);
        if ($claims === null) {
            throw InvalidIdTokenException::malformed();
        }

        $now = time();

        // ── Le temps ──────────────────────────────────────────────────────
        foreach (['nbf', 'iat'] as $notBefore) {
            if (isset($claims[$notBefore]) && is_numeric($claims[$notBefore])
                && ((int) $claims[$notBefore]) > $now + self::LEEWAY) {
                throw InvalidIdTokenException::notYetValid();
            }
        }

        if (isset($claims['exp']) && is_numeric($claims['exp'])
            && ($now - self::LEEWAY) >= ((int) $claims['exp'])) {
            throw InvalidIdTokenException::expired();
        }

        // ── Claims standards obligatoires ─────────────────────────────────
        $iss = self::stringClaim($claims, 'iss');
        $sub = self::stringClaim($claims, 'sub');
        $jti = self::stringClaim($claims, 'jti');
        $exp = isset($claims['exp']) && is_numeric($claims['exp']) ? (int) $claims['exp'] : 0;

        foreach (['iss' => $iss, 'sub' => $sub, 'jti' => $jti] as $name => $value) {
            if ($value === '') {
                throw InvalidIdTokenException::missingClaim($name);
            }
        }

        if ($exp === 0) {
            // Un id_token sans expiration serait éternel : on ne suppose aucune
            // durée par défaut.
            throw InvalidIdTokenException::missingClaim('exp');
        }

        if (! self::hasAudClaim($claims)) {
            throw InvalidIdTokenException::missingClaim('aud');
        }

        // ── L'instance : `iss` d'abord, `aud` ensuite ─────────────────────
        $expectedIss = rtrim($credentials->issuer, '/');
        if ($expectedIss === '' || ! hash_equals($expectedIss, rtrim($iss, '/'))) {
            throw InvalidIdTokenException::issMismatch();
        }

        if (! hash_equals($credentials->clientId, self::audClaim($claims, $credentials->clientId))) {
            throw InvalidIdTokenException::audMismatch();
        }

        // ── Le `nonce` : lie le jeton à CETTE demande ─────────────────────
        if ($expectedNonce !== '') {
            $nonce = self::stringClaim($claims, 'nonce');

            if ($nonce === '' || ! hash_equals($expectedNonce, $nonce)) {
                throw InvalidIdTokenException::nonceMismatch();
            }
        }

        // ── Usage unique du `jti`, EN DERNIER ─────────────────────────────
        if (! $this->replay->consumeOnce($jti, $exp)) {
            throw InvalidIdTokenException::replayed();
        }

        return $claims;
    }

    // =====================================================================
    // Key-map : du JWKS publié aux clés vérifiables
    // =====================================================================

    /**
     * Construit `kid => ressource de clé publique` depuis le JWKS.
     *
     * ⚠️ **RS256 EXCLUSIVEMENT.** Une entrée qui annoncerait `HS256` ou `none`
     * est IGNORÉE, jamais « supportée » : c'est ce qui ferme la confusion
     * d'algorithme du côté du fournisseur, comme le pinning la ferme du côté du
     * jeton.
     *
     * @param  list<array<string, mixed>>  $jwks
     * @return array<string, \OpenSSLAsymmetricKey>
     */
    private static function buildKeyMap(array $jwks): array
    {
        $map = [];

        foreach ($jwks as $jwk) {
            if (! is_array($jwk)) {
                continue;
            }

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

            $key = @openssl_pkey_get_public($pem);
            if ($key === false) {
                continue;
            }

            $map[$kid] = $key;
        }

        return $map;
    }

    /**
     * Reconstruit un PEM `SubjectPublicKeyInfo` depuis les composantes `n`/`e`
     * d'un JWK RSA — le sens inverse de l'export JWKS du fournisseur.
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

    // =====================================================================
    // Encodage / claims
    // =====================================================================

    /** @return array<string, mixed>|null `null` = segment inexploitable. */
    private static function decodeSegment(string $segment): ?array
    {
        $raw = self::base64UrlDecode($segment);

        if ($raw === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public static function base64UrlDecode(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return '';
        }

        $padded = str_pad(strtr($value, '-_', '+/'), (int) (ceil(strlen($value) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $claims */
    private static function stringClaim(array $claims, string $key): string
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
    private static function audClaim(array $claims, string $expected): string
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
     * `aud` présent, peu importe qu'il corresponde ? Distingue « claim
     * manquant » de « mauvaise audience » — deux diagnostics différents pour
     * l'exploitant.
     *
     * @param  array<string, mixed>  $claims
     */
    private static function hasAudClaim(array $claims): bool
    {
        $aud = $claims['aud'] ?? null;

        if (is_string($aud)) {
            return $aud !== '';
        }

        return is_array($aud) && $aud !== [];
    }
}
