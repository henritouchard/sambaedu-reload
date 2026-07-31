<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.4 — Le résultat du choix : une liste ORDONNÉE, du moins chargé au
 * plus chargé — ou un message qui dit pourquoi il n'y a personne.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI UNE LISTE, ET PAS « LE » SERVEUR
 *
 *  Parce que la sonde et la création sont deux instants différents. Entre les
 *  deux, le serveur le moins chargé peut tomber ; et un Scalelite, lui, n'est
 *  JAMAIS sondé — sa présence dans la liste ne prouve rien de sa santé. La
 *  bascule sur panne (un réessai, borné) n'est possible que si le choix rend
 *  l'ordre entier, pas seulement son vainqueur.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class Selection
{
    /** @param  list<ServerCandidate>  $candidates  Du moins chargé au plus chargé. */
    private function __construct(
        public readonly array $candidates,
        public readonly string $message = '',
        /**
         * Au moins un serveur a refusé le secret enregistré. Distinct de
         * « injoignable » **partout** : un secret refusé est une erreur de
         * configuration, elle appelle un administrateur, pas de la patience.
         */
        public readonly bool $secretRefused = false,
    ) {
    }

    /** @param  list<ServerCandidate>  $candidates */
    public static function of(array $candidates, bool $secretRefused = false): self
    {
        return new self($candidates, '', $secretRefused);
    }

    public static function none(string $message, bool $secretRefused = false): self
    {
        return new self([], $message, $secretRefused);
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    /** Le moins chargé. `null` seulement si personne n'a été retenu. */
    public function best(): ?ServerCandidate
    {
        return $this->candidates[0] ?? null;
    }
}
