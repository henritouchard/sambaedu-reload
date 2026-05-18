<?php

declare(strict_types=1);

namespace App\Auth\V1\Support;

/**
 * Story 16.10 — D8.
 *
 * Catalogue des codes d'erreur retournés par les middlewares et services
 * d'auth v1 (format réponse `{error, message, code}`).
 *
 * Codes consommés par les scripts cmd/bash (et plus tard l'agent Go) pour
 * discriminer programmatiquement les modes d'échec sans parser de free-text.
 *
 * Note : on garde des constantes string (pas un enum) pour permettre l'usage
 * direct dans les réponses JSON sans cast ni `->value`.
 */
final class JwtErrorCodes
{
    // --- JWT (access token) ---
    public const JWT_MISSING = 'jwt.missing';
    public const JWT_MALFORMED = 'jwt.malformed';
    public const JWT_SIGNATURE_INVALID = 'jwt.signature_invalid';
    public const JWT_EXPIRED = 'jwt.expired';
    public const JWT_REVOKED = 'jwt.revoked';
    public const JWT_WRONG_TIER = 'jwt.wrong_tier';
    public const JWT_UNKNOWN_WORKSTATION = 'jwt.unknown_workstation';

    // --- Bootstrap token (transitoire md5/APCu legacy) ---
    public const BOOTSTRAP_TOKEN_MISSING = 'bootstrap_token.missing';
    public const BOOTSTRAP_TOKEN_INVALID = 'bootstrap_token.invalid';

    // --- Bootstrap durci 16.11 (couple token↔UUID + LAN whitelist) ---
    public const BOOTSTRAP_TOKEN_UUID_MISMATCH = 'bootstrap_token.uuid_mismatch';
    public const BOOTSTRAP_NOT_LAN = 'bootstrap.not_lan';

    // --- Refresh token (DB) ---
    public const REFRESH_MISSING = 'refresh.missing';
    public const REFRESH_INVALID = 'refresh.invalid';
    public const REFRESH_EXPIRED = 'refresh.expired';
    public const REFRESH_REVOKED = 'refresh.revoked';
    public const REFRESH_REPLAY_DETECTED = 'refresh.replay_detected';

    /**
     * Liste exhaustive (utile pour tests d'invariance + audits).
     *
     * @return array<int,string>
     */
    public static function all(): array
    {
        return [
            self::JWT_MISSING,
            self::JWT_MALFORMED,
            self::JWT_SIGNATURE_INVALID,
            self::JWT_EXPIRED,
            self::JWT_REVOKED,
            self::JWT_WRONG_TIER,
            self::JWT_UNKNOWN_WORKSTATION,
            self::BOOTSTRAP_TOKEN_MISSING,
            self::BOOTSTRAP_TOKEN_INVALID,
            self::BOOTSTRAP_TOKEN_UUID_MISMATCH,
            self::BOOTSTRAP_NOT_LAN,
            self::REFRESH_MISSING,
            self::REFRESH_INVALID,
            self::REFRESH_EXPIRED,
            self::REFRESH_REVOKED,
            self::REFRESH_REPLAY_DETECTED,
        ];
    }
}
