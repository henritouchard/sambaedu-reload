<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Workstation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Story 24.7 — Mécanique PULL du bouton « forcer la synchro » (FR11, AC5,
 * décision n° 1). Le SEUL écrivain UI de la colonne
 * `workstations.agent_sync_requested_at` (l'autre écrivain est
 * {@see \App\Http\Controllers\Api\V1\Agent\ReportController} via {@see fulfill()},
 * canal agent — invariant « 2 écrivains », décision n° 2).
 *
 * Modèle : l'admin POSE une demande (timestamp) ; tant qu'elle est pendante,
 * `GET /api/v1/agent/state` (tous contextes) bypasse le 304 et re-sert
 * l'enveloppe complète (même ETag, zéro write au GET — {@see \App\Http\Controllers\Api\V1\Agent\StateController}) ;
 * le premier `POST /api/v1/agent/report` suivant la SOLDE (remise à null).
 * C'est du pull : la demande est servie au prochain contact (timer agent
 * ≤ 60 min + jitter, ou boot/login immédiats) — pas de push.
 *
 * Périmètre groupe : on ne pose la demande que sur les postes ENRÔLÉS et
 * NON en quarantaine (un poste en quarantaine ne POST jamais de report —
 * la demande ne serait jamais soldée, piège 6). Le compte retourné permet
 * un toast récapitulatif (demandés / ignorés).
 *
 * Logs channel `agent`, actions namespacées `agent.sync.*`, contexte
 * `workstation_id` + admin — JAMAIS de token (convention architecture).
 */
class SyncRequestService
{
    /**
     * Pose une demande de resynchronisation sur un poste ou une collection
     * de postes (membres d'un groupe). Filtre les postes éligibles (enrôlés,
     * non quarantaine) et retourne le compte de demandes effectivement posées.
     *
     * @param  Workstation|Collection<int, Workstation>  $target
     * @param  Authenticatable|null  $admin auteur de la demande (canal web
     *         authentifié) — id/login tracés ; null toléré (jamais bloquant).
     * @return int nombre de postes pour lesquels une demande a été posée
     */
    public function request(Workstation|Collection $target, ?Authenticatable $admin = null): int
    {
        $workstations = $target instanceof Workstation
            ? collect([$target])
            : $target;

        $adminId = $admin?->getAuthIdentifier();
        $adminLogin = is_object($admin) && isset($admin->login) ? $admin->login : null;

        $requested = 0;

        foreach ($workstations as $workstation) {
            if (! $this->isEligible($workstation)) {
                continue;
            }

            // Écriture explicite hors $fillable (anti mass-assignment, iso
            // les autres colonnes agent_*). Idempotent : reposer une demande
            // déjà pendante rafraîchit simplement l'horodatage.
            $workstation->agent_sync_requested_at = now();
            $workstation->save();
            $requested++;

            Log::channel('agent')->info('[SyncRequestService] agent.sync.requested', [
                'action_type' => 'agent.sync.requested',
                'workstation_id' => $workstation->id,
                'admin_id' => $adminId,
                'admin_login' => $adminLogin,
            ]);
        }

        return $requested;
    }

    /**
     * Vrai si une demande est pendante pour ce poste (lecture UI).
     */
    public function isPending(Workstation $workstation): bool
    {
        return $workstation->agent_sync_requested_at !== null;
    }

    /**
     * Solde une demande pendante (canal agent, appelé par ReportController
     * après ingestion d'un rapport). No-op si aucune demande n'était
     * pendante (le cas nominal d'un report sans demande). Retourne true si
     * une demande a effectivement été soldée.
     */
    public function fulfill(Workstation $workstation): bool
    {
        if ($workstation->agent_sync_requested_at === null) {
            return false;
        }

        $workstation->agent_sync_requested_at = null;
        $workstation->save();

        Log::channel('agent')->info('[SyncRequestService] agent.sync.fulfilled', [
            'action_type' => 'agent.sync.fulfilled',
            'workstation_id' => $workstation->id,
        ]);

        return true;
    }

    /**
     * Éligibilité d'un poste à une demande (piège 6) : enrôlé ET non en
     * quarantaine. Un poste non enrôlé ne porte pas d'agent ; un poste en
     * quarantaine ne POST jamais de report → la demande ne serait jamais
     * soldée.
     */
    private function isEligible(Workstation $workstation): bool
    {
        return $workstation->isAgentEnrolled() && ! $workstation->isAgentQuarantined();
    }
}
