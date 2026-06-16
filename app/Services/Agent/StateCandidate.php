<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\StateMaille;

/**
 * Candidat brut retourné par un `StateProvider` (Story 23.4 — décision n° 4) :
 * un payload étiqueté par maille, PAS un item final du contrat. Le
 * `StateCompiler` applique D2 (spécificité, union, conflit) sur ces candidats
 * puis assemble les items `{type, semantics, payload, hash}`.
 *
 * `updatedAt` + `sourceId` portent la règle de récence du conflit intra-maille
 * (décision n° 2 : `updated_at` desc puis `id` desc — le tiebreak garantit le
 * déterminisme du hash quand deux règles partagent le même `updated_at`).
 * `sourceId` est aussi l'id loggé dans `agent.state.conflict` et l'ordre
 * stable des items aggregate (décision n° 9).
 *
 * Story 27.8 : le mécanisme `mode` strict/default est SUPPRIMÉ (STRICT
 * inconditionnel) — le candidat ne porte plus de mode, l'agent réapplique
 * toujours l'état cible.
 */
final readonly class StateCandidate
{
    /**
     * @param  array<string,mixed>  $payload  contrat §4.1 : jamais de float
     * @param  int  $sourceId  id de la règle métier source (tiebreak + logs)
     */
    public function __construct(
        public StateMaille $maille,
        public array $payload,
        public ?\DateTimeInterface $updatedAt,
        public int $sourceId,
    ) {}
}
