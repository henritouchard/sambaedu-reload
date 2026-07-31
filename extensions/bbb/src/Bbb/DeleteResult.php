<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.3 — Le résultat d'une suppression d'enregistrement.
 *
 * ⚠️ Un `SUCCESS` dont le nœud `deleted` vaut `false` n'est **pas** une
 * suppression : le dire réussi ferait disparaître de l'écran un enregistrement
 * toujours présent sur le serveur, et la personne croirait avoir effacé un cours
 * qu'elle n'a pas effacé. Fail-closed jusque dans le message.
 */
final class DeleteResult
{
    private function __construct(
        public readonly CallOutcome $outcome,
        public readonly bool $deleted = false,
        public readonly string $message = '',
    ) {
    }

    public static function deleted(): self
    {
        return new self(CallOutcome::Ok, true);
    }

    /** Le serveur a répondu, et il a répondu « non ». */
    public static function refused(): self
    {
        return new self(
            CallOutcome::Ok,
            false,
            'Le serveur de visioconférence n\'a pas supprimé cet enregistrement.',
        );
    }

    public static function unreachable(): self
    {
        return new self(
            CallOutcome::Unreachable,
            false,
            'Serveur de visioconférence injoignable : rien n\'a été supprimé.',
        );
    }

    public static function invalidSecret(): self
    {
        return new self(
            CallOutcome::InvalidSecret,
            false,
            'Le serveur de visioconférence a refusé le secret enregistré — prévenez l\'administrateur.',
        );
    }

    public static function invalidResponse(string $detail = ''): self
    {
        return new self(
            CallOutcome::InvalidResponse,
            false,
            'Le serveur de visioconférence a répondu de façon inattendue : rien n\'a été supprimé.'
                . ($detail !== '' ? ' (' . $detail . ')' : ''),
        );
    }

    public function isOk(): bool
    {
        return $this->outcome === CallOutcome::Ok && $this->deleted;
    }
}
