<?php

declare(strict_types=1);

namespace App\Services\Agent\Reporting;

use App\Enums\AgentResourceStatus;
use App\Models\AgentReportEvent;
use App\Models\AgentResourceState;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Support\Collection;

/**
 * Story 24.7 — Lecture AGRÉGÉE de la conformité agent pour les pages parc
 * (FR10, AC1-AC3, décision n° 3). Service de LECTURE PURE partagé par les 3
 * composants Livewire (parc/index, machines/[id], groups/[id]) : jamais de
 * requête agrégée dupliquée dans les vues.
 *
 * Périmètre = postes ENRÔLÉS (les non-enrôlés sont hors conformité, affichés
 * neutres côté UI). Les hashes sont OPAQUES (lus, jamais recalculés —
 * piège 9). Aucune écriture, aucun accès AD/LDAP.
 *
 * États affichés (3 enum + 2 dérivés) :
 *  - `compliant` / `drift` / `error` (lus de `agent_resource_states`) ;
 *  - dérivé « jamais rapporté » : poste enrôlé, zéro ligne d'état ;
 *  - dérivé « muet » : `agent_last_checkin_at` > 2 × `agent.ttl_seconds`
 *    ({@see Workstation::isAgentSilent()}, décision n° 7).
 *
 * « En écart » (compteur d'alerte) = `drift` + `error`. Story 27.8 : le statut
 * `drifted_allowed` (dérive tolérée) est SUPPRIMÉ — la cible fait toujours loi.
 */
class ConformityService
{
    /** Statut dérivé : poste enrôlé sans aucune ligne d'état rapportée. */
    public const DERIVED_NEVER_REPORTED = 'never_reported';

    /** Statut dérivé : poste enrôlé mais muet (check-in > 2 × ttl). */
    public const DERIVED_SILENT = 'silent';

    /**
     * Précédence du worst-status (décision n° 3) : `error` domine tout,
     * puis `drift`, puis `compliant`. Les dérivés (jamais rapporté / muet)
     * sont calculés à part (ils ne vivent pas dans `agent_resource_states`).
     */
    private const STATUS_PRECEDENCE = [
        AgentResourceStatus::Error->value => 3,
        AgentResourceStatus::Drift->value => 2,
        AgentResourceStatus::Compliant->value => 1,
    ];

    /**
     * Compteurs de conformité sur un périmètre (parc complet ou un groupe).
     * Calculés sur les postes ENRÔLÉS, en requêtes agrégées (zéro N+1).
     *
     * Clés retournées :
     *  - `enrolled` : total des postes enrôlés du périmètre ;
     *  - `compliant` : postes dont le worst-status est `compliant` ;
     *  - `exceptions` : worst-status `drift` ou `error` (EN ÉCART) ;
     *  - `never_reported` : enrôlés sans aucune ligne d'état ;
     *  - `silent` : enrôlés muets (check-in > 2 × ttl).
     *
     * @return array{enrolled:int, compliant:int, exceptions:int, never_reported:int, silent:int}
     */
    public function summary(?WorkstationGroup $group = null): array
    {
        $enrolled = $this->enrolledWorkstations($group);
        $enrolledIds = $enrolled->pluck('id')->all();

        $base = [
            'enrolled' => count($enrolledIds),
            'compliant' => 0,
            'exceptions' => 0,
            'never_reported' => 0,
            'silent' => 0,
        ];

        if (empty($enrolledIds)) {
            return $base;
        }

        $worst = $this->worstStatusFor($enrolledIds);

        foreach ($enrolled as $workstation) {
            // Le « muet » prime visuellement sur le contenu rapporté : un
            // poste qui ne parle plus est suspect quel que soit son dernier
            // état connu (décision n° 7).
            if ($workstation->isAgentSilent()) {
                $base['silent']++;
                continue;
            }

            $status = $worst[$workstation->id] ?? null;

            if ($status === null) {
                $base['never_reported']++;
                continue;
            }

            match ($status) {
                AgentResourceStatus::Error->value,
                AgentResourceStatus::Drift->value => $base['exceptions']++,
                default => $base['compliant']++,
            };
        }

        return $base;
    }

    /**
     * Worst-status par poste pour un lot d'ids — UNE requête agrégée
     * (`groupBy workstation_id`), jamais une relation lazy par ligne
     * (piège 11). Retourne `[workstation_id => status string]` ; un poste
     * sans aucune ligne d'état est ABSENT du tableau (dérivé « jamais
     * rapporté », à traiter par l'appelant).
     *
     * @param  array<int, int>  $workstationIds
     * @return array<int, string> map id → valeur AgentResourceStatus
     */
    public function worstStatusFor(array $workstationIds): array
    {
        if (empty($workstationIds)) {
            return [];
        }

        $rows = AgentResourceState::query()
            ->whereIn('workstation_id', $workstationIds)
            ->get(['workstation_id', 'status']);

        $worst = [];
        foreach ($rows as $row) {
            $id = (int) $row->workstation_id;
            $value = $row->status instanceof AgentResourceStatus
                ? $row->status->value
                : (string) $row->status;

            $current = $worst[$id] ?? null;
            if ($current === null || self::rank($value) > self::rank($current)) {
                $worst[$id] = $value;
            }
        }

        return $worst;
    }

    /**
     * Exceptions par type de ressource sur un périmètre (vue groupe,
     * décision n° 4 : « penser en règles » = type × périmètre). Pour chaque
     * type RAPPORTÉ, retourne le nombre total de postes enrôlés et la liste
     * des SEULS postes en exception (statut ≠ compliant, jamais rapporté,
     * muet), datés.
     *
     * Structure par type :
     *  - `type` : identifiant du type ;
     *  - `total` : nb de postes enrôlés du périmètre ;
     *  - `compliant` : nb de postes conformes sur ce type ;
     *  - `exceptions` : list de
     *    `{workstation_id, name, status, reported_at, detail}` triée par
     *    nom — `status` ∈ enum ∪ {never_reported, silent}.
     *
     * @return array<int, array{type:string, total:int, compliant:int, exceptions:list<array{workstation_id:int, name:string, status:string, reported_at:?\Illuminate\Support\Carbon, detail:?string}>}>
     */
    public function exceptionsFor(?WorkstationGroup $group = null): array
    {
        $enrolled = $this->enrolledWorkstations($group);
        $enrolledIds = $enrolled->pluck('id')->all();

        if (empty($enrolledIds)) {
            return [];
        }

        /** @var Collection<int, Workstation> $byId */
        $byId = $enrolled->keyBy('id');

        // Tous les états rapportés du périmètre, indexés par (poste, type).
        $states = AgentResourceState::query()
            ->whereIn('workstation_id', $enrolledIds)
            ->get();

        // Types effectivement RAPPORTÉS (pas la liste exhaustive du contrat —
        // pas de lignes vides pour des types sans handler, Dev Notes 24.7).
        $types = $states->pluck('type')->unique()->sort()->values();

        $statesByType = $states->groupBy('type');

        $result = [];
        foreach ($types as $type) {
            /** @var Collection<int, AgentResourceState> $typeStates */
            $typeStates = $statesByType->get($type, collect());
            $stateByWorkstation = $typeStates->keyBy('workstation_id');

            $compliant = 0;
            $exceptions = [];

            foreach ($enrolledIds as $wid) {
                /** @var Workstation $workstation */
                $workstation = $byId->get($wid);
                $state = $stateByWorkstation->get($wid);

                // Le « muet » est une exception transverse (le poste ne parle
                // plus) — listé sur chaque type rapporté du périmètre.
                if ($workstation->isAgentSilent()) {
                    $exceptions[] = $this->exceptionRow($workstation, self::DERIVED_SILENT, $state?->reported_at, $state?->detail);
                    continue;
                }

                if ($state === null) {
                    // Jamais rapporté CE type : exception « jamais rapporté ».
                    $exceptions[] = $this->exceptionRow($workstation, self::DERIVED_NEVER_REPORTED, null, null);
                    continue;
                }

                $status = $state->status instanceof AgentResourceStatus
                    ? $state->status->value
                    : (string) $state->status;

                if ($status === AgentResourceStatus::Compliant->value) {
                    $compliant++;
                    continue;
                }

                $exceptions[] = $this->exceptionRow($workstation, $status, $state->reported_at, $state->detail);
            }

            // Tri par nom de poste (lisibilité, stabilité du rendu).
            usort($exceptions, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            $result[] = [
                'type' => (string) $type,
                'total' => count($enrolledIds),
                'compliant' => $compliant,
                'exceptions' => $exceptions,
            ];
        }

        return $result;
    }

    /**
     * États rapportés COURANTS d'un poste, par type (fiche poste, AC2). Triés
     * par type. Lecture pure de `agent_resource_states`.
     *
     * @return Collection<int, AgentResourceState>
     */
    public function statesFor(Workstation $workstation): Collection
    {
        return AgentResourceState::query()
            ->where('workstation_id', $workstation->id)
            ->orderBy('type')
            ->get();
    }

    /**
     * Depuis quand le statut COURANT de chaque type tient, indexé par type.
     *
     * Pourquoi : la politique de drift est STRICT (27.8) — `Test()` négatif vaut
     * `drift` même quand l'`Apply` qui suit répare aussitôt, et il n'existe aucun
     * statut « corrigé ». Un `drift` affiché ne dit donc pas s'il s'agit d'une
     * divergence transitoire déjà réparée (typiquement le PREMIER passage sur un
     * poste réinstallé : rien n'est encore appliqué, tout est non conforme par
     * construction) ou d'un écart INSTALLÉ qui demande une intervention. La date
     * de la dernière TRANSITION vers ce statut fait exactement cette
     * distinction : quelques minutes = en cours de convergence, plusieurs jours
     * = bloqué.
     *
     * Null (type absent) = aucune transition enregistrée : le statut n'a jamais
     * changé depuis le premier rapport, ou l'événement est sorti de la rétention
     * (14 j, PruneAgentReportsCommand). On n'affiche alors rien plutôt que
     * d'inventer une ancienneté.
     *
     * @return Collection<string, AgentReportEvent> indexée par type
     */
    public function statusHeldSinceFor(Workstation $workstation): Collection
    {
        return AgentReportEvent::query()
            ->where('workstation_id', $workstation->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['type', 'status', 'created_at'])
            // Ordre décroissant : le premier de chaque type est le plus récent.
            ->unique('type')
            ->keyBy('type');
    }

    /**
     * Derniers événements de changement d'un poste (fiche poste, AC2),
     * datés, du plus récent au plus ancien.
     *
     * @return Collection<int, AgentReportEvent>
     */
    public function recentEventsFor(Workstation $workstation, int $limit = 10): Collection
    {
        return AgentReportEvent::query()
            ->where('workstation_id', $workstation->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{workstation_id:int, name:string, status:string, reported_at:?\Illuminate\Support\Carbon, detail:?string}
     */
    private function exceptionRow(Workstation $workstation, string $status, $reportedAt, ?string $detail): array
    {
        return [
            'workstation_id' => (int) $workstation->id,
            'name' => (string) $workstation->name,
            'status' => $status,
            'reported_at' => $reportedAt,
            'detail' => $detail,
        ];
    }

    /**
     * Postes ENRÔLÉS du périmètre (parc complet ou membres d'un groupe).
     * Périmètre = `agent_token_hash` non null. Charge les colonnes utiles à
     * la dérivation (`isAgentSilent`, nom). Groupe = relation `workstations`.
     *
     * @return Collection<int, Workstation>
     */
    private function enrolledWorkstations(?WorkstationGroup $group): Collection
    {
        $query = $group === null
            ? Workstation::query()
            : $group->workstations()->getQuery();

        // Colonnes qualifiées (la relation groupe joint le pivot) et bornées
        // au nécessaire : id/name (exceptionRow), agent_last_checkin_at
        // (isAgentSilent), agent_token_hash (isAgentEnrolled) — review 24.7 #5.
        return $query
            ->whereNotNull('agent_token_hash')
            ->get([
                'workstations.id',
                'workstations.name',
                'workstations.agent_token_hash',
                'workstations.agent_last_checkin_at',
            ]);
    }

    private static function rank(string $status): int
    {
        return self::STATUS_PRECEDENCE[$status] ?? 0;
    }
}
