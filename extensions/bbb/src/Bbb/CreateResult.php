<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.2 — Le résultat d'une ouverture de meeting, prêt à afficher.
 *
 * ⚠️ Le message ne contient JAMAIS le secret partagé du serveur, ni les mots de
 * passe du salon, ni l'URL signée. Le legacy, lui, renvoyait un
 * `print_r($response)` complet dans la page en cas d'échec.
 */
final class CreateResult
{
    private function __construct(
        public readonly CallOutcome $outcome,
        public readonly string $message = '',
    ) {
    }

    public static function started(): self
    {
        return new self(CallOutcome::Ok);
    }

    public static function invalidSecret(): self
    {
        return new self(
            CallOutcome::InvalidSecret,
            'Le serveur de visioconférence a refusé le secret enregistré — prévenez l\'administrateur.',
        );
    }

    public static function unreachable(): self
    {
        return new self(
            CallOutcome::Unreachable,
            'Serveur de visioconférence injoignable. Réessayez dans un instant, puis prévenez l\'administrateur.',
        );
    }

    public static function invalidResponse(string $detail = ''): self
    {
        return new self(
            CallOutcome::InvalidResponse,
            'Le serveur de visioconférence a répondu de façon inattendue : le salon n\'a pas pu être ouvert.'
                . ($detail !== '' ? ' (' . $detail . ')' : ''),
        );
    }

    public function isOk(): bool
    {
        return $this->outcome === CallOutcome::Ok;
    }
}
