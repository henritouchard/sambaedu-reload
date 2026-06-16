<?php

declare(strict_types=1);

namespace App\Services\Agent\Reporting;

use App\Enums\AgentResourceStatus;
use App\Models\AgentReportEvent;
use App\Models\AgentReportHistory;
use App\Models\AgentResourceState;
use App\Models\Workstation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 24.1 — Ingestion des rapports de conformité agent (FR8) + stockage
 * D3 (FR9). Le SEUL écrivain des tables `agent_resource_states` /
 * `agent_report_events` / `agent_report_history`.
 *
 * Reçoit un rapport DÉJÀ validé ({@see \App\Http\Requests\Api\V1\Agent\ReportRequest})
 * pour un poste DÉJÀ authentifié (middleware `agent.token` — identité = le
 * token, jamais le payload). Par item :
 *
 *  1. upsert de l'état courant par (workstation, type) — UNIQUE en base,
 *     volume borné D3 ; `reported_at` rafraîchi à CHAQUE rapport, même
 *     identique (fraîcheur = donnée UI, décision n° 4) ;
 *  2. journal {@see AgentReportEvent} pour les SEULS changements
 *     (décision n° 2) :
 *      - ligne absente (premier rapport du type) : événement seulement si
 *        `status ≠ compliant` (un premier « tout va bien » n'est pas un
 *        changement) ;
 *      - `(status, hash)` identiques : AUCUN événement (rapport identique) ;
 *      - différents : événement, SAUF transition compliant → compliant
 *        (la cible a bougé et l'agent a convergé silencieusement — le hash
 *        de la ligne d'état est mis à jour, c'est suffisant).
 *
 * Si `config('agent.report_history')` (flag D3, défaut off) : le payload
 * BRUT complet (`$rawPayload`, champs inconnus §9 inclus — décision Henri
 * review 24.1 #2 : table de debug, le brut diagnostique un agent émettant
 * des champs futurs) est conservé en append-only ({@see AgentReportHistory}).
 *
 * Le tout sous `DB::transaction` : un rapport est atomique — jamais d'état
 * sans son événement. La transaction s'ouvre par un `lockForUpdate` sur la
 * ligne `workstations` (review 24.1 #5) : l'agent est séquentiel par design
 * (FR18) mais un retry réseau peut doubler un POST — sans verrou, deux
 * ingestions concurrentes liraient toutes deux « ligne absente » et la
 * seconde insertion violerait l'UNIQUE (workstation_id, type) → 500. Le
 * verrou ligne sérialise par poste (no-op SQLite de test, réel Postgres —
 * pattern middleware 23.2) sans bloquer les autres postes.
 *
 * Invariants de sécurité (defer review 23.1 résolu) : AUCUN hash calculé
 * ici — les hashes du rapport sont les chaînes opaques `StateHasher` émises
 * par `GET /state`, stockées et comparées en égalité de chaînes. Aucune
 * écriture hors `agent_*` (`agent_last_checkin_at` = middleware). Logs
 * channel `agent`, jamais de payload complet ni de token.
 */
class ReportIngestService
{
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

        DB::transaction(function () use ($workstation, $report, $rawPayload, &$counts, &$driftEvents): void {
            // Sérialisation per-poste (review 24.1 #5) : verrou sur la ligne
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
                        // Rafraîchi MÊME si identique (décision n° 4).
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
            }

            if ((bool) config('agent.report_history', false)) {
                AgentReportHistory::create([
                    'workstation_id' => $workstation->id,
                    'payload' => $rawPayload ?? $report,
                ]);
            }
        });

        // AC5 — un warning par item créant un événement de dérive
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

        return $counts;
    }

    /**
     * Règle de création d'événement (décision n° 2 — D3 : « seuls les
     * événements de changement »). Comparaison `(status, hash)` en égalité
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
            // Rapport identique au précédent : aucun événement (AC epic).
            return false;
        }

        // Transition compliant → compliant (hash changé) : la cible a bougé
        // et l'agent a convergé silencieusement — pas une dérive.
        return ! ($existing->status === AgentResourceStatus::Compliant
            && $status === AgentResourceStatus::Compliant);
    }
}
