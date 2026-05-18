<?php

declare(strict_types=1);

namespace App\Auth\V1\Jwt\Exceptions;

use App\Auth\V1\Support\JwtErrorCodes;
use RuntimeException;
use Throwable;

/**
 * Story 16.10 — AC2.2 / D8.
 *
 * Exception levée par `WorkstationJwtVerifier` (et services connexes) avec
 * un code stable du catalogue {@see JwtErrorCodes}. Le middleware
 * `EnsureWorkstationJwt` la capture et formate la réponse `{error, message,
 * code}`.
 *
 * **Convention** : pas de message technique brut dans `$message` exposé au
 * client (le message des factory methods est court et fonctionnel). Le
 * détail technique va dans le log via `$context` (cf. callers).
 *
 * Factory methods :
 *
 *  - `missing()` : JWT absent du header `Authorization`.
 *  - `malformed()` : JWT mal formé (segments invalides).
 *  - `signatureInvalid()` : signature ou `kid` inconnu.
 *  - `expired()` : claim `exp` dépassé.
 *  - `revoked()` : jti présent dans `workstation_jwt_revocations`.
 *  - `wrongTier(string $expected, string $found)` : claim `tier` incorrect.
 */
class InvalidJwtException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 401,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function missing(): self
    {
        return new self(
            JwtErrorCodes::JWT_MISSING,
            'Missing Authorization header',
        );
    }

    public static function malformed(?Throwable $previous = null): self
    {
        return new self(
            JwtErrorCodes::JWT_MALFORMED,
            'Malformed JWT',
            401,
            $previous,
        );
    }

    public static function signatureInvalid(?Throwable $previous = null): self
    {
        return new self(
            JwtErrorCodes::JWT_SIGNATURE_INVALID,
            'JWT signature invalid',
            401,
            $previous,
        );
    }

    public static function expired(?Throwable $previous = null): self
    {
        return new self(
            JwtErrorCodes::JWT_EXPIRED,
            'JWT expired',
            401,
            $previous,
        );
    }

    public static function revoked(): self
    {
        return new self(
            JwtErrorCodes::JWT_REVOKED,
            'JWT revoked',
        );
    }

    public static function wrongTier(string $expected, string $found): self
    {
        return new self(
            JwtErrorCodes::JWT_WRONG_TIER,
            sprintf('JWT tier mismatch (expected %s, got %s)', $expected, $found),
        );
    }

    public static function unknownWorkstation(string $sub): self
    {
        return new self(
            JwtErrorCodes::JWT_UNKNOWN_WORKSTATION,
            'Unknown workstation (sub=' . $sub . ')',
        );
    }
}
