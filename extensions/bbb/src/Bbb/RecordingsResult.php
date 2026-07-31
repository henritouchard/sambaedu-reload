<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.3 — Le résultat d'une demande d'enregistrements.
 *
 * ⚠️ **`noRecordings` n'est PAS une erreur.** BigBlueButton répond `SUCCESS`
 * avec cette clé quand la requête est parfaitement valide et que rien n'y
 * correspond : c'est une liste vide, et une liste vide s'affiche. La confondre
 * avec une panne ferait dire « serveur injoignable » à un serveur en pleine
 * forme, exactement le jour où un professeur cherche pourquoi son cours n'est
 * pas là.
 *
 * ⚠️ Le message ne porte jamais le secret partagé, ni l'URL signée.
 */
final class RecordingsResult
{
    /**
     * @param  list<RecordingItem>  $items
     */
    private function __construct(
        public readonly CallOutcome $outcome,
        public readonly array $items = [],
        public readonly string $message = '',
    ) {
    }

    /**
     * @param  list<RecordingItem>  $items
     */
    public static function ok(array $items): self
    {
        return new self(CallOutcome::Ok, $items);
    }

    public static function unreachable(): self
    {
        return new self(
            CallOutcome::Unreachable,
            [],
            'Serveur de visioconférence injoignable : ses enregistrements ne peuvent pas être listés.',
        );
    }

    public static function invalidSecret(): self
    {
        return new self(
            CallOutcome::InvalidSecret,
            [],
            'Le serveur de visioconférence a refusé le secret enregistré — prévenez l\'administrateur.',
        );
    }

    public static function invalidResponse(string $detail = ''): self
    {
        return new self(
            CallOutcome::InvalidResponse,
            [],
            'Le serveur de visioconférence a répondu de façon inattendue : liste indisponible.'
                . ($detail !== '' ? ' (' . $detail . ')' : ''),
        );
    }

    public function isOk(): bool
    {
        return $this->outcome === CallOutcome::Ok;
    }
}
