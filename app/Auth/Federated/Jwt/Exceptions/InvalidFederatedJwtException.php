<?php

declare(strict_types=1);

namespace App\Auth\Federated\Jwt\Exceptions;

use App\Auth\Federated\Support\FederatedJwtErrorCodes;
use RuntimeException;
use Throwable;

/**
 * Story 20.1.
 *
 * Exception levée par {@see \App\Auth\Federated\Jwt\FederatedJwtVerifier} avec
 * un code stable du catalogue {@see FederatedJwtErrorCodes}. Calquée sur
 * {@see \App\Auth\V1\Jwt\Exceptions\InvalidJwtException}.
 *
 * **Convention** : pas de message technique brut exposé au client ; le détail
 * va dans le log (channel `federated-auth`) côté caller. Le `$httpStatus` est
 * 401 par défaut (jeton invalide), 403 pour le rôle inconnu (jeton valide mais
 * non autorisé).
 */
class InvalidFederatedJwtException extends RuntimeException
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
        return new self(FederatedJwtErrorCodes::JWT_MISSING, 'Missing federated JWT');
    }

    public static function malformed(?Throwable $previous = null): self
    {
        return new self(FederatedJwtErrorCodes::JWT_MALFORMED, 'Malformed federated JWT', 401, $previous);
    }

    public static function signatureInvalid(?Throwable $previous = null): self
    {
        return new self(FederatedJwtErrorCodes::JWT_SIGNATURE_INVALID, 'Federated JWT signature invalid', 401, $previous);
    }

    public static function expired(?Throwable $previous = null): self
    {
        return new self(FederatedJwtErrorCodes::JWT_EXPIRED, 'Federated JWT expired', 401, $previous);
    }

    public static function notYetValid(?Throwable $previous = null): self
    {
        return new self(FederatedJwtErrorCodes::JWT_NOT_YET_VALID, 'Federated JWT not yet valid', 401, $previous);
    }

    public static function replayed(): self
    {
        return new self(FederatedJwtErrorCodes::JWT_REPLAYED, 'Federated JWT already consumed (replay)', 401);
    }

    public static function issMismatch(): self
    {
        return new self(FederatedJwtErrorCodes::ISS_MISMATCH, 'Federated JWT issuer not trusted', 401);
    }

    public static function audMismatch(): self
    {
        return new self(FederatedJwtErrorCodes::AUD_MISMATCH, 'Federated JWT audience mismatch', 401);
    }

    public static function wrongTier(string $expected, string $found): self
    {
        return new self(
            FederatedJwtErrorCodes::WRONG_TIER,
            sprintf('Federated JWT tier mismatch (expected %s, got %s)', $expected, $found),
            401,
        );
    }

    public static function missingClaim(string $claim): self
    {
        return new self(
            FederatedJwtErrorCodes::MISSING_CLAIM,
            sprintf('Federated JWT missing required claim: %s', $claim),
            401,
        );
    }

    public static function roleUnknown(string $role): self
    {
        return new self(
            FederatedJwtErrorCodes::ROLE_UNKNOWN,
            sprintf('Federated role not mapped: %s', $role),
            403,
        );
    }
}
