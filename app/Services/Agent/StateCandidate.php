<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\StateMaille;
use App\Enums\StateMode;

/**
 * Candidat brut retourné par un `StateProvider` (Story 23.4 — décision n° 4) :
 * un payload étiqueté par maille, PAS un item final du contrat. Le
 * `StateCompiler` applique D2 (spécificité, union, conflit) sur ces candidats
 * puis assemble les items `{type, semantics, mode, payload, hash}`.
 *
 * `updatedAt` + `sourceId` portent la règle de récence du conflit intra-maille
 * (décision n° 2 : `updated_at` desc puis `id` desc — le tiebreak garantit le
 * déterminisme du hash quand deux règles partagent le même `updated_at`).
 * `sourceId` est aussi l'id loggé dans `agent.state.conflict` et l'ordre
 * stable des items aggregate (décision n° 9).
 *
 * `mode` (Story 27.1, révisé Story 27.3) : mode d'application **par candidat** —
 * le toggle strict/default n'est plus une constante par type mais un attribut
 * piloté par l'ASSIGNATION (27.3 : `shortcut_assignables.mode` ; wallpaper /
 * overlay = mode sur leur ligne, déjà « par cible »). `null` = « le candidat ne
 * déclare pas de mode » : le
 * compilateur retombe alors sur le `StateProvider::mode()` (défaut du type,
 * comportement 23.4 préservé). L'agrégation du mode par type (un seul verdict
 * par type côté agent) vit dans le `StateCompiler` SEUL.
 */
final readonly class StateCandidate
{
    /**
     * @param  array<string,mixed>  $payload  contrat §4.1 : jamais de float
     * @param  int  $sourceId  id de la règle métier source (tiebreak + logs)
     * @param  StateMode|null  $mode  mode par assignation (27.3 ; null = défaut du provider, résolu au compilateur)
     */
    public function __construct(
        public StateMaille $maille,
        public array $payload,
        public ?\DateTimeInterface $updatedAt,
        public int $sourceId,
        public ?StateMode $mode = null,
    ) {}
}
