<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.4 — **LA CHARGE MESURÉE D'UN SERVEUR, OU LA RAISON DE NE PAS LA
 * CONNAÎTRE.**
 *
 * Même patron que {@see CreateResult} / {@see RunningResult} : les quatre
 * familles d'issues restent distinguées, et elles le restent ICI parce que le
 * sélecteur en tire deux conduites OPPOSÉES —
 *
 *  - un serveur **injoignable** (ou qui répond n'importe quoi) est écarté du
 *    démarrage en cours, **en silence** : c'est un aléa d'infrastructure, et
 *    l'utilisateur n'a rien à en faire tant qu'un autre serveur répond ;
 *  - un serveur qui **refuse le secret** est écarté lui aussi, mais il est
 *    SIGNALÉ : c'est une erreur de configuration, et l'enterrer sous un
 *    « ça a marché ailleurs » la rendrait éternelle.
 *
 * ⚠️ `participants` vaut **0 pour un serveur qui répond `SUCCESS` sans le
 * moindre meeting** — et c'est le meilleur candidat possible, pas une erreur.
 * Un serveur vide est exactement ce qu'on cherche.
 *
 * ⚠️ Aucun message construit ici ne porte le secret partagé, ni l'URL signée.
 */
final class LoadResult
{
    private function __construct(
        public readonly CallOutcome $outcome,
        public readonly int $participants = 0,
        public readonly string $message = '',
    ) {
    }

    /** La mesure : le nombre de participants ACTUELLEMENT en conférence. */
    public static function ok(int $participants): self
    {
        // Une charge négative n'existe pas ; un serveur qui en annoncerait une
        // deviendrait sinon le favori absolu de toute répartition.
        return new self(CallOutcome::Ok, max(0, $participants));
    }

    public static function unreachable(): self
    {
        return new self(
            CallOutcome::Unreachable,
            0,
            'Serveur de visioconférence injoignable.',
        );
    }

    public static function invalidSecret(): self
    {
        return new self(
            CallOutcome::InvalidSecret,
            0,
            'Le serveur de visioconférence a refusé le secret enregistré — prévenez l\'administrateur.',
        );
    }

    public static function invalidResponse(string $detail = ''): self
    {
        return new self(
            CallOutcome::InvalidResponse,
            0,
            'Le serveur de visioconférence a répondu de façon inattendue.'
                . ($detail !== '' ? ' (' . $detail . ')' : ''),
        );
    }

    public function isOk(): bool
    {
        return $this->outcome === CallOutcome::Ok;
    }
}
