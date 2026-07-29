<?php

declare(strict_types=1);

namespace App\OidcWitness\Jwt\Exceptions;

use App\OidcWitness\Support\WitnessErrorCodes;
use RuntimeException;
use Throwable;

/**
 * Story 55.3 — Exception du vérificateur client d'id_token.
 *
 * Calque littéral d'`InvalidFederatedJwtException` (Epic 20) : un code STABLE
 * du catalogue {@see WitnessErrorCodes}, un message technique qui ne sort jamais
 * à l'écran, et rien du jeton lui-même.
 */
class InvalidWitnessIdTokenException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function malformed(?Throwable $previous = null): self
    {
        return new self(WitnessErrorCodes::ID_TOKEN_MALFORMED, 'id_token malformé', $previous);
    }

    public static function signatureInvalid(?Throwable $previous = null): self
    {
        return new self(
            WitnessErrorCodes::ID_TOKEN_SIGNATURE_INVALID,
            'Signature d\'id_token invalide (algorithme non autorisé, kid inconnu ou clé étrangère)',
            $previous,
        );
    }

    public static function expired(?Throwable $previous = null): self
    {
        return new self(WitnessErrorCodes::ID_TOKEN_EXPIRED, 'id_token expiré', $previous);
    }

    public static function notYetValid(?Throwable $previous = null): self
    {
        return new self(WitnessErrorCodes::ID_TOKEN_NOT_YET_VALID, 'id_token pas encore valide', $previous);
    }

    public static function issMismatch(): self
    {
        return new self(WitnessErrorCodes::ISS_MISMATCH, 'id_token émis par un autre issuer');
    }

    public static function audMismatch(): self
    {
        return new self(WitnessErrorCodes::AUD_MISMATCH, 'id_token destiné à un autre client');
    }

    public static function missingClaim(string $claim): self
    {
        return new self(
            WitnessErrorCodes::MISSING_CLAIM,
            sprintf('id_token sans le claim requis « %s »', $claim),
        );
    }

    public static function nonceMismatch(): self
    {
        return new self(WitnessErrorCodes::NONCE_MISMATCH, 'nonce divergent de celui envoyé à l\'autorisation');
    }

    public static function replayed(): self
    {
        return new self(WitnessErrorCodes::JTI_REPLAYED, 'id_token déjà consommé (rejeu de jti)');
    }

    public static function jwksUnusable(?Throwable $previous = null): self
    {
        return new self(
            WitnessErrorCodes::JWKS_UNUSABLE,
            'Aucune clé exploitable dans le JWKS — vérification impossible',
            $previous,
        );
    }
}
