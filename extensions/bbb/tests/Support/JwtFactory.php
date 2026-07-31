<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests\Support;

use RuntimeException;
use SambaEdu\ExtBbb\Oidc\IdTokenVerifier;

/**
 * Story 57.1 — Fabrique de jetons pour la suite d'attaque.
 *
 * Elle sait forger ce qu'une bibliothèque JWT honnête REFUSERAIT d'émettre —
 * `alg: none`, HS256 signé avec une clé publique, `kid` inconnu. C'est la
 * condition pour prouver que le vérificateur, lui, les rejette.
 *
 * Les paires RSA sont générées UNE fois par processus (une RSA 2048 coûte des
 * centaines de millisecondes) et mémorisées en statique.
 */
final class JwtFactory
{
    /** @var array<string, array{private: string, public: string}> */
    private static array $keyPairs = [];

    /** @return array{private: string, public: string} */
    public static function keyPair(string $name = 'default'): array
    {
        if (isset(self::$keyPairs[$name])) {
            return self::$keyPairs[$name];
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new RuntimeException('ext-openssl requis pour la suite d\'attaque');
        }

        $private = '';
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);

        return self::$keyPairs[$name] = [
            'private' => $private,
            'public' => (string) ($details['key'] ?? ''),
        ];
    }

    /**
     * Le JWK tel que le fournisseur le publie (forme de `OidcKeyManager::jwks()`).
     *
     * @return array<string, mixed>
     */
    public static function jwk(string $publicPem, string $kid): array
    {
        $resource = openssl_pkey_get_public($publicPem);

        if ($resource === false) {
            throw new RuntimeException('clé publique illisible');
        }

        $details = openssl_pkey_get_details($resource);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => IdTokenVerifier::base64UrlEncode((string) $details['rsa']['n']),
            'e' => IdTokenVerifier::base64UrlEncode((string) $details['rsa']['e']),
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  string  $algorithm  `RS256`, `HS256` (confusion) ou `none`.
     * @param  string|null  $key  Clé privée PEM (RS256) ou secret HMAC (HS256).
     */
    public static function sign(
        array $claims,
        string $algorithm = 'RS256',
        ?string $key = null,
        string $kid = 'test-oidc-kid',
    ): string {
        $header = IdTokenVerifier::base64UrlEncode(
            (string) json_encode(['alg' => $algorithm, 'typ' => 'JWT', 'kid' => $kid])
        );
        $payload = IdTokenVerifier::base64UrlEncode((string) json_encode($claims));
        $input = $header . '.' . $payload;

        if ($algorithm === 'none') {
            return $input . '.';
        }

        if ($algorithm === 'HS256') {
            return $input . '.' . IdTokenVerifier::base64UrlEncode(
                hash_hmac('sha256', $input, (string) $key, true)
            );
        }

        $signature = '';
        openssl_sign($input, $signature, $key ?? self::keyPair()['private'], OPENSSL_ALGO_SHA256);

        return $input . '.' . IdTokenVerifier::base64UrlEncode($signature);
    }
}
