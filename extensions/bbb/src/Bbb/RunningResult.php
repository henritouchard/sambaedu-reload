<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.2 — « Ce salon est-il ouvert ? », demandé au seul moment où la
 * question se pose.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  IL N'Y A NI CACHE, NI RAMASSE-MIETTES, ET C'EST UNE DÉCISION
 *
 *  SE4 tenait en APCu un miroir des meetings actifs — durées de vie multiples,
 *  compteur d'échecs, verrou anti-emballement, et injection à la main du
 *  meeting fraîchement créé. Deux conséquences vécues : des meetings fantômes
 *  jamais nettoyés, et un miroir désynchronisé de la réalité.
 *
 *  Ici, la ligne `rooms` décrit un salon (durable) et BigBlueButton décrit un
 *  meeting (éphémère). Le pont entre les deux est **un appel borné, au moment
 *  d'un acte utilisateur** — jamais au rendu d'une page.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class RunningResult
{
    private function __construct(
        public readonly CallOutcome $outcome,
        public readonly bool $running = false,
        public readonly string $message = '',
    ) {
    }

    public static function running(): self
    {
        return new self(CallOutcome::Ok, true);
    }

    /** Réponse claire du serveur : ce meeting n'existe pas (ou plus). État NORMAL. */
    public static function notRunning(): self
    {
        return new self(CallOutcome::Ok, false);
    }

    public static function unreachable(): self
    {
        return new self(
            CallOutcome::Unreachable,
            false,
            'Serveur de visioconférence injoignable. Réessayez dans un instant, puis prévenez l\'administrateur.',
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
            'Le serveur de visioconférence a répondu de façon inattendue.'
                . ($detail !== '' ? ' (' . $detail . ')' : ''),
        );
    }

    /**
     * `true` seulement si le serveur a RÉPONDU. Une panne n'est pas un « salon
     * fermé » : dire à un élève « attendez votre professeur » alors que le
     * serveur est éteint l'enverrait attendre pour rien.
     */
    public function answered(): bool
    {
        return $this->outcome === CallOutcome::Ok;
    }
}
