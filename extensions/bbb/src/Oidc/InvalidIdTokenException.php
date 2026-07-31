<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

use Throwable;

/**
 * Story 57.1 — Refus d'un id_token par le vérificateur client.
 *
 * Chaque fabrique correspond à UN vecteur nommé de la suite d'attaque portée de
 * la Story 55.3 ({@see \SambaEdu\ExtBbb\Tests\IdTokenVerifierTest}) : un refus
 * « pour la mauvaise raison » est une régression silencieuse, donc chaque test
 * affirme le code, pas seulement le refus.
 */
final class InvalidIdTokenException extends OidcException
{
    public static function malformed(?Throwable $previous = null): self
    {
        return new self(ErrorCodes::ID_TOKEN_MALFORMED, 'id_token structurellement invalide', $previous);
    }

    /** Le bucket FUSIONNÉ — voir {@see ErrorCodes::ID_TOKEN_SIGNATURE_INVALID}. */
    public static function signatureInvalid(?Throwable $previous = null): self
    {
        return new self(ErrorCodes::ID_TOKEN_SIGNATURE_INVALID, 'signature non vérifiable', $previous);
    }

    public static function jwksUnusable(?Throwable $previous = null): self
    {
        return new self(ErrorCodes::JWKS_UNUSABLE, 'aucune clé RS256 exploitable au JWKS', $previous);
    }

    public static function expired(): self
    {
        return new self(ErrorCodes::ID_TOKEN_EXPIRED, 'id_token expiré');
    }

    public static function notYetValid(): self
    {
        return new self(ErrorCodes::ID_TOKEN_NOT_YET_VALID, 'id_token pas encore valide');
    }

    public static function missingClaim(string $claim): self
    {
        return new self(ErrorCodes::MISSING_CLAIM, 'claim obligatoire absent : ' . $claim);
    }

    public static function issMismatch(): self
    {
        return new self(ErrorCodes::ISS_MISMATCH, 'émetteur inattendu');
    }

    public static function audMismatch(): self
    {
        return new self(ErrorCodes::AUD_MISMATCH, 'audience inattendue');
    }

    public static function nonceMismatch(): self
    {
        return new self(ErrorCodes::NONCE_MISMATCH, 'nonce divergent');
    }

    public static function replayed(): self
    {
        return new self(ErrorCodes::JTI_REPLAYED, 'jti déjà consommé');
    }
}
