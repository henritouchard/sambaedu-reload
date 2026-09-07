<?php

declare(strict_types=1);

namespace App\Services\Agent\Reporting;

use App\Enums\AgentResourceStatus;
use App\Models\AgentApplicationInventory;
use App\Models\AgentReportEvent;
use App\Models\AgentReportHistory;
use App\Models\AgentResourceState;
use App\Models\Application;
use App\Models\Workstation;
use App\Services\Agent\StateCompiler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingestion des rapports de conformité agent et stockage de leur état
 * courant. Le SEUL écrivain des tables `agent_resource_states` /
 * `agent_report_events` / `agent_report_history`.
 *
 * Reçoit un rapport DÉJÀ validé ({@see \App\Http\Requests\Api\V1\Agent\ReportRequest})
 * pour un poste DÉJÀ authentifié (middleware `agent.token` — identité = le
 * token, jamais le payload). Par item :
 *
 *  1. upsert de l'état courant par (workstation, type) — UNIQUE en base,
 *     volume borné ; `reported_at` rafraîchi à CHAQUE rapport, même
 *     identique (la fraîcheur est une donnée d'UI) ;
 *  2. journal {@see AgentReportEvent} pour les SEULS changements :
 *      - ligne absente (premier rapport du type) : événement seulement si
 *        `status ≠ compliant` (un premier « tout va bien » n'est pas un
 *        changement) ;
 *      - `(status, hash)` identiques : AUCUN événement (rapport identique) ;
 *      - différents : événement, SAUF transition compliant → compliant
 *        (la cible a bougé et l'agent a convergé silencieusement — le hash
 *        de la ligne d'état est mis à jour, c'est suffisant).
 *
 * Si `config('agent.report_history')` (défaut off) : le payload BRUT complet
 * (`$rawPayload`, champs inconnus §9 inclus) est conservé en append-only
 * ({@see AgentReportHistory}). C'est une table de debug — le brut diagnostique
 * un agent qui émettrait des champs futurs.
 *
 * Le tout sous `DB::transaction` : un rapport est atomique — jamais d'état
 * sans son événement. La transaction s'ouvre par un `lockForUpdate` sur la
 * ligne `workstations` : l'agent est séquentiel par design, mais un retry
 * réseau peut doubler un POST — sans verrou, deux
 * ingestions concurrentes liraient toutes deux « ligne absente » et la
 * seconde insertion violerait l'UNIQUE (workstation_id, type) → 500. Le
 * verrou ligne sérialise par poste (no-op SQLite de test, réel Postgres —
 * pattern middleware) sans bloquer les autres postes.
 *
 * Invariants de sécurité : AUCUN hash calculé
 * ici — les hashes du rapport sont les chaînes opaques `StateHasher` émises
 * par `GET /state`, stockées et comparées en égalité de chaînes. Aucune
 * écriture hors `agent_*` (`agent_last_checkin_at` = middleware). Logs
 * channel `agent`, jamais de payload complet ni de token.
 */
class ReportIngestService
{
    public function __construct(
        // Source de vérité des types PAR SESSION (scope ≠ machine) pour le
        // nettoyage level-triggered des fantômes — voir {@see ingest()}.
        private readonly StateCompiler $compiler,
    ) {}

    /**
     * Ingère un rapport validé et retourne les comptes d'items par statut
     * (réponse ack + log `agent.report.received`).
     *
     * @param  array<string, mixed>  $report payload validé (ReportRequest)
     * @param  array<string, mixed>|null  $rawPayload payload brut (champs inconnus
     *         §9 inclus) pour l'historique de debug ; null = fallback $report
     * @return array<string, int> comptes par valeur de {@see AgentResourceStatus}
     */
    public function ingest(Workstation $workstation, array $report, ?array $rawPayload = null): array
    {
        $counts = [];
        foreach (AgentResourceStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        /** @var list<array{type: string, status: AgentResourceStatus}> $driftEvents */
        $driftEvents = [];

        // Nombre de lignes d'inventaire upsertées (log
        // `agent.applications.reported`). Collecté APRÈS commit.
        $inventoryReported = 0;

        // Fix fantômes — nombre de lignes d'état de types PAR SESSION purgées
        // (absentes du rapport courant). Collecté APRÈS commit.
        $sessionPruned = 0;

        DB::transaction(function () use ($workstation, $report, $rawPayload, &$counts, &$driftEvents, &$inventoryReported, &$sessionPruned): void {
            // Sérialisation per-poste : verrou sur la ligne
            // workstation AVANT toute lecture d'état — deux POST concurrents
            // du même poste ne peuvent plus courser l'updateOrCreate (UNIQUE
            // workstation_id+type). Aucune colonne workstations n'est écrite.
            Workstation::query()
                ->whereKey($workstation->getKey())
                ->lockForUpdate()
                ->first();

            /** @var list<array<string, mixed>> $items */
            $items = $report['items'] ?? [];

            foreach ($items as $item) {
                $status = AgentResourceStatus::from($item['status']);
                $counts[$status->value]++;

                $existing = AgentResourceState::query()
                    ->where('workstation_id', $workstation->id)
                    ->where('type', $item['type'])
                    ->first();

                $eventDue = $this->eventDue($existing, $status, $item['hash']);

                AgentResourceState::query()->updateOrCreate(
                    ['workstation_id' => $workstation->id, 'type' => $item['type']],
                    [
                        'status' => $status,
                        'hash' => $item['hash'],
                        'detail' => $item['detail'] ?? null,
                        // Rafraîchi MÊME si identique.
                        'reported_at' => now(),
                    ],
                );

                if ($eventDue) {
                    AgentReportEvent::create([
                        'workstation_id' => $workstation->id,
                        'type' => $item['type'],
                        'previous_status' => $existing?->status,
                        'status' => $status,
                        'hash' => $item['hash'],
                        'detail' => $item['detail'] ?? null,
                    ]);

                    if ($status !== AgentResourceStatus::Compliant) {
                        // Logs émis APRÈS commit (pas de trace d'un rollback).
                        $driftEvents[] = ['type' => $item['type'], 'status' => $status];
                    }
                }

                // Inventaire PAR APP additif sur l'item
                // `applications` (champ `inventory`). DONNÉE sous la ligne d'état
                // par type (déjà upsertée ci-dessus, inchangée) — JAMAIS un
                // verdict per-app : le grain par type reste intact. Même
                // transaction.
                if ($item['type'] === Application::TYPE_APPLICATIONS) {
                    $inventoryReported += $this->ingestApplicationsInventory(
                        $workstation,
                        $item['inventory'] ?? [],
                    );
                }
            }

            // Fix fantômes — nettoyage LEVEL-TRIGGERED des lignes d'état de types
            // PAR SESSION (compagnon) ABSENTES du rapport courant. Un type session
            // présent en base mais plus rapporté = plus AUCUNE session active ne le
            // porte (utilisateur délogué/parti) → la ligne traînerait à jamais
            // (la table n'a pas de dimension session, aucune expiration). Les types
            // MACHINE (rapportés in-process CHAQUE cycle par le service SYSTEM)
            // n'apparaissent jamais dans `perSessionReportedTypes()` → jamais
            // purgés, même transitoirement absents. Même esprit que le nettoyage
            // de l'inventaire applications ci-dessus. L'agent purge le drop mort à
            // la source (`PurgeOrphanDrops`) ; ce nettoyage efface la ligne déjà
            // figée côté serveur. Le drop de chaque session vivante reste rapporté
            // → seuls les types réellement disparus sont purgés.
            /** @var list<string> $reportedTypes */
            $reportedTypes = array_values(array_unique(array_map(
                static fn (array $reported): string => (string) $reported['type'],
                $items,
            )));
            if ($reportedTypes !== []) {
                $sessionPruned = AgentResourceState::query()
                    ->where('workstation_id', $workstation->id)
                    ->whereIn('type', $this->compiler->perSessionReportedTypes())
                    ->whereNotIn('type', $reportedTypes)
                    ->delete();
            }

            if ((bool) config('agent.report_history', false)) {
                AgentReportHistory::create([
                    'workstation_id' => $workstation->id,
                    'payload' => $rawPayload ?? $report,
                ]);
            }
        });

        // Un warning par item créant un événement de dérive
        // (drift/error entrant) : jamais de spam sur rapport identique
        // (eventDue = false → rien collecté).
        foreach ($driftEvents as $event) {
            Log::channel('agent')->warning('[ReportIngestService] agent.report.drift', [
                'action_type' => 'agent.report.drift',
                'workstation_id' => $workstation->id,
                'type' => $event['type'],
                'status' => $event['status']->value,
            ]);
        }

        Log::channel('agent')->info('[ReportIngestService] agent.report.received', [
            'action_type' => 'agent.report.received',
            'workstation_id' => $workstation->id,
            'counts' => $counts,
        ]);

        // Fix fantômes — trace des lignes d'état session purgées (level-triggered).
        // Émis APRÈS commit ; silencieux si rien à purger (régime stable).
        if ($sessionPruned > 0) {
            Log::channel('agent')->info('[ReportIngestService] agent.report.session_pruned', [
                'action_type' => 'agent.report.session_pruned',
                'workstation_id' => $workstation->id,
                'pruned' => $sessionPruned,
            ]);
        }

        // Trace de l'inventaire applications upserté. Émis
        // APRÈS commit (pas de trace d'un rollback). Silencieux si aucun item
        // `applications` (les autres types ne portent pas d'inventaire).
        if ($inventoryReported > 0) {
            Log::channel('agent')->info('[ReportIngestService] agent.applications.reported', [
                'action_type' => 'agent.applications.reported',
                'workstation_id' => $workstation->id,
                'apps' => $inventoryReported,
            ]);
        }

        return $counts;
    }

    /**
     * Upsert l'inventaire PAR APP du poste (champ additif
     * `inventory` de l'item `applications`) puis NETTOIE les lignes d'apps
     * absentes du rapport (level-triggered : une app retirée n'occupe plus de
     * siège). DONNÉE additive sous la ligne d'état par type — JAMAIS un verdict
     * per-app (grain intact). Appelée DANS la transaction d'ingestion.
     *
     * @param  list<array<string, mixed>>  $inventory `[{app_id, status, detail?}]`
     * @return int nombre de lignes upsertées
     */
    private function ingestApplicationsInventory(Workstation $workstation, array $inventory): int
    {
        $reportedAppIds = [];

        foreach ($inventory as $row) {
            $appId = (string) ($row['app_id'] ?? '');
            if ($appId === '') {
                continue;
            }
            $status = AgentResourceStatus::from($row['status']);

            AgentApplicationInventory::query()->updateOrCreate(
                ['workstation_id' => $workstation->id, 'app_id' => $appId],
                [
                    'status' => $status,
                    // Message d'erreur WPKG (ex. code 1603) — null si status ≠ error.
                    'detail' => ($row['detail'] ?? null) ?: null,
                    // Rafraîchi MÊME si identique (fraîcheur, iso ligne d'état).
                    'reported_at' => now(),
                ],
            );

            $reportedAppIds[] = $appId;
        }

        // Level-triggered : retirer les lignes d'apps qui ne sont PLUS dans
        // l'inventaire rapporté (l'app a été désassignée → libère son siège).
        AgentApplicationInventory::query()
            ->where('workstation_id', $workstation->id)
            ->when($reportedAppIds !== [], fn ($q) => $q->whereNotIn('app_id', $reportedAppIds))
            ->delete();

        return count($reportedAppIds);
    }

    /**
     * Règle de création d'événement : seuls les changements font un
     * événement. Comparaison `(status, hash)` en égalité
     * de chaînes OPAQUES — jamais de recalcul.
     */
    private function eventDue(?AgentResourceState $existing, AgentResourceStatus $status, string $hash): bool
    {
        if ($existing === null) {
            // Premier rapport du type : un premier « tout va bien » n'est
            // pas un changement ; un premier drift/error en est un.
            return $status !== AgentResourceStatus::Compliant;
        }

        if ($existing->status === $status && $existing->hash === $hash) {
            // Rapport identique au précédent : aucun événement.
            return false;
        }

        // Transition compliant → compliant (hash changé) : la cible a bougé
        // et l'agent a convergé silencieusement — pas une dérive.
        return ! ($existing->status === AgentResourceStatus::Compliant
            && $status === AgentResourceStatus::Compliant);
    }
}
