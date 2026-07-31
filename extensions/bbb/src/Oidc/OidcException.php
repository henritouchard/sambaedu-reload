<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

use RuntimeException;
use Throwable;

/**
 * Story 57.1 — Échec du parcours OIDC, porteur d'un code stable
 * ({@see ErrorCodes}).
 *
 * Le message éventuel sert le journal du service ; la page d'erreur ne montre
 * QUE le code.
 */
class OidcException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
    }

    public static function of(string $code, string $message = '', ?Throwable $previous = null): self
    {
        return new self($code, $message, $previous);
    }
}
