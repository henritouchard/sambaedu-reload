<?php

declare(strict_types=1);

namespace App\Auth\Federated\Support;

/**
 * Story 20.1.
 *
 * Catalogue des codes d'erreur retournés par le vérificateur de JWT fédéré
 * et le controller de login. Calqué sur {@see \App\Auth\V1\Support\JwtErrorCodes}.
 *
 * Codes string stables (pas un enum) pour usage direct dans les réponses /
 * logs sans cast.
 */
final class FederatedJwtErrorCodes
{
    public const JWT_MISSING = 'federated.jwt.missing';
    public const JWT_MALFORMED = 'federated.jwt.malformed';
    public const JWT_SIGNATURE_INVALID = 'federated.jwt.signature_invalid';
    public const JWT_EXPIRED = 'federated.jwt.expired';
    public const JWT_NOT_YET_VALID = 'federated.jwt.not_yet_valid';
    public const JWT_REPLAYED = 'federated.jwt.replayed';

    // Claims fonctionnels.
    public const ISS_MISMATCH = 'federated.jwt.iss_mismatch';
    public const AUD_MISMATCH = 'federated.jwt.aud_mismatch';
    public const WRONG_TIER = 'federated.jwt.wrong_tier';
    public const MISSING_CLAIM = 'federated.jwt.missing_claim';

    // Autorisation (post-vérification).
    public const ROLE_UNKNOWN = 'federated.role_unknown';

    /**
     * Liste exhaustive (tests d'invariance + audits).
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
            self::JWT_NOT_YET_VALID,
            self::JWT_REPLAYED,
            self::ISS_MISMATCH,
            self::AUD_MISMATCH,
            self::WRONG_TIER,
            self::MISSING_CLAIM,
            self::ROLE_UNKNOWN,
        ];
    }
}
