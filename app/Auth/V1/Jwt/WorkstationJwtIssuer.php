<?php

declare(strict_types=1);

namespace App\Auth\V1\Jwt;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Story 16.10 — AC2.1.
 *
 * Émet des access tokens JWT RS256 et des refresh tokens (clear + sha256
 * hash) pour les postes Sambaedu lors d'un enrôlement / refresh.
 *
 * **Frontière** : seul fichier (avec `WorkstationJwtVerifier`) autorisé à
 * importer `Firebase\JWT\JWT` (cf. test archi `AuthV1NamespaceTest`).
 *
 * **Émission centralisée** : seuls `EnrollController` et `RefreshController`
 * invoquent `issueAccessToken()` / `issueRefreshToken()`. Le test archi
 * surveille cette contrainte.
 *
 * **Sécurité** :
 *  - Clé privée RS256 lue depuis le path config (`auth_v1.jwt.keys.{kid}.private`),
 *    chmod 0600 attendu (warn boot si > 0600).
 *  - Garde-fou `forbid_test_keys_in_production` : refuse de signer en prod
 *    avec une clé pointant sur `tests/fixtures/auth-v1/*`.
 *  - jti = UUID v4 (random, non corrélé au workstation_uuid pour défense
 *    en profondeur).
 *  - Refresh token = `random_bytes(32)` (256 bits CSPRNG) → 64 hex chars.
 *
 * **Logging** : channel `auth-v1`, `action_type=auth.token.issued`. Aucun
 * secret (jamais le clear refresh ni le JWT complet).
 */
class WorkstationJwtIssuer
{
    /**
     * Émet un access token JWT RS256 pour un workstation_uuid donné.
     * Claims : `iss`, `sub`, `iat`, `exp`, `jti`, `tier=workstation`.
     * Header : `kid` (= active_kid).
     *
     * @return array{token: string, jti: string, expires_at: Carbon, kid: string, exp: int, iat: int}
     */
    public function issueAccessToken(string $workstationUuid): array
    {
        $kid = $this->activeKid();
        $privateKey = $this->loadPrivateKey($kid);
        $iss = $this->resolveIssuer();
        $ttl = (int) config('auth_v1.jwt.access_ttl', 86400);
        $now = Carbon::now();
        $exp = $now->copy()->addSeconds($ttl);
        $jti = (string) Str::uuid();

        $payload = [
            'iss' => $iss,
            'sub' => $workstationUuid,
            'iat' => $now->getTimestamp(),
            'exp' => $exp->getTimestamp(),
            'jti' => $jti,
            'tier' => 'workstation',
            'kid' => $kid,
        ];

        $token = JWT::encode($payload, $privateKey, 'RS256', $kid);

        Log::channel('auth-v1')->debug('[WorkstationJwtIssuer] auth.token.issued', [
            'action_type' => 'auth.token.issued',
            'workstation_uuid' => $workstationUuid,
            'jti' => $jti,
            'kid' => $kid,
            'expires_at' => $exp->toIso8601String(),
            // pas de token loggé — c'est un secret.
        ]);

        return [
            'token' => $token,
            'jti' => $jti,
            'expires_at' => $exp,
            'kid' => $kid,
            'exp' => $exp->getTimestamp(),
            'iat' => $now->getTimestamp(),
        ];
    }

    /**
     * Émet un refresh token (clear + hash sha256). Le clear est renvoyé une
     * seule fois au caller (= controller, qui le renvoie au poste). Seul le
     * hash est stocké en DB.
     *
     * @return array{clear: string, hash: string, expires_at: Carbon, issued_at: Carbon}
     */
    public function issueRefreshToken(string $workstationUuid): array
    {
        $ttl = (int) config('auth_v1.jwt.refresh_ttl', 2592000);
        $now = Carbon::now();
        $expiresAt = $now->copy()->addSeconds($ttl);

        // 32 bytes CSPRNG = 64 hex chars (256 bits d'entropie).
        $clear = bin2hex(random_bytes(32));
        $hash = hash('sha256', $clear);

        // Pas de log debug du refresh hash ici — c'est le caller (DB insert)
        // qui logge l'enregistrement, avec workstation_uuid + expires_at.
        unset($workstationUuid); // hint statique pour éviter le warn unused.

        return [
            'clear' => $clear,
            'hash' => $hash,
            'expires_at' => $expiresAt,
            'issued_at' => $now,
        ];
    }

    /**
     * Lit la clé privée RS256 du `kid` actif. Garde-fou test keys en prod.
     */
    private function loadPrivateKey(string $kid): string
    {
        $path = (string) config('auth_v1.jwt.keys.' . $kid . '.private', '');
        if ($path === '') {
            throw new RuntimeException(
                'JWT private key path not configured for kid=' . $kid
                . ' (check config/auth_v1.php — jwt.keys)'
            );
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                'JWT private key not found or not readable at ' . $path
                . ' — run php artisan auth:ca:init'
            );
        }

        // Garde-fou prod (D3 / dev notes).
        $forbidTestInProd = (bool) config('auth_v1.safety.forbid_test_keys_in_production', true);
        if ($forbidTestInProd && app()->environment() !== 'testing' && app()->environment() !== 'local') {
            if (str_contains($path, '/tests/fixtures/')) {
                throw new RuntimeException(
                    'SECURITY : JWT private key points to a test fixture in non-testing env : ' . $path
                );
            }
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read JWT private key : ' . $path);
        }

        return $contents;
    }

    private function activeKid(): string
    {
        $kid = (string) config('auth_v1.jwt.active_kid', '');
        if ($kid === '') {
            throw new RuntimeException('auth_v1.jwt.active_kid not configured');
        }

        return $kid;
    }

    private function resolveIssuer(): string
    {
        $iss = (string) config('auth_v1.jwt.issuer', '');
        if ($iss !== '') {
            return $iss;
        }

        $se4fs = (string) config('sambaedu.se4fs_name', '');
        if ($se4fs !== '') {
            return $se4fs;
        }

        // Fallback générique
        return 'sambaedu-local';
    }
}
