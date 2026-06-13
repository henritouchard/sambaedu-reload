<?php

declare(strict_types=1);

namespace App\Services\Agent\Enrollment;

use App\Ipxe\Support\MacAddressNormalizer;
use App\Models\AgentEnrollmentRequest;
use App\Models\Workstation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 23.3 — Enrôlement porte 1 : le token naît à l'install iPXE (FR16).
 *
 * Cycle du ticket d'enrôlement one-time :
 *
 *  1. {@see openTicket()} à la génération de l'unattend.xml (l'admin est déjà
 *     authentifié au menu iPXE — story 4.10) : 64 hex aléatoires, seul le
 *     SHA-256 est persisté (`agent_enroll_ticket_hash` + expiry TTL
 *     `config('agent.enroll_ticket_ttl_minutes')`), le clair est interpolé
 *     dans l'unattend uniquement. Si le poste était déjà enrôlé
 *     (réinstallation), son token est révoqué immédiatement (AC2 — le clone
 *     éventuel meurt au début de la réinstall, pas à la fin).
 *  2. {@see redeem()} au premier logon du poste (`POST /api/v1/agent/enrollment`) :
 *     résolution par hash du ticket — le ticket EST l'identité, uuid/mac ne
 *     servent jamais à l'autorisation (spoofables sur le LAN) — consommation
 *     atomique, puis naissance du token via
 *     {@see TokenRotationService::issueFor()}.
 *
 * Conventions iso 23.2 : jamais de ticket/token en clair persisté ni loggé ;
 * transitions loggées channel `agent`, actions `agent.enroll.*`, contexte
 * `workstation_id`. Flux SQL-only — aucune dépendance annuaire (critère
 * Keycloak, AC7).
 *
 * Story 25.3 — Porte 2 (poste migré sans ticket, FR16) : la branche d'échec de
 * {@see redeem()} (jadis un 403 sec) accueille désormais une **demande
 * d'enrôlement** ({@see AgentEnrollmentRequest}). Le poste reste 403 (indistinct,
 * sans oracle) ; l'admin approuve d'un clic dans l'UI (ou une campagne bornée
 * auto-approuve un poste connu concordant), et c'est le **prochain redeem()**
 * du poste (faisceau re-présenté) qui matérialise `issueFor()` et renvoie le
 * token — le token ne transite jamais par l'UI (décision n° 4). Le flux ticket
 * (porte 1) est strictement inchangé.
 */
class EnrollmentService
{
    public function __construct(
        private readonly TokenRotationService $tokens,
        private readonly EnrollmentMatchService $matcher,
        private readonly EnrollmentCampaign $campaign,
    ) {
    }

    /**
     * Émet un ticket d'enrôlement one-time pour le poste (génération de
     * l'unattend.xml). Une re-génération (re-fetch WinPE) écrase simplement
     * le ticket précédent — pas d'erreur (AC1).
     *
     * @return string le ticket en clair (64 hex) — à interpoler dans
     *                l'unattend uniquement, jamais persisté, jamais loggé.
     */
    public function openTicket(Workstation $workstation): string
    {
        $ticket = bin2hex(random_bytes(32));

        // Transaction (review 23.3) : révocation + écriture du ticket sont
        // atomiques — un échec entre les deux laisserait le poste sans token
        // NI ticket utilisable.
        $revoked = DB::transaction(function () use ($workstation, $ticket): bool {
            // AC2 — réinstall = événement de révocation (FR14) : révoquer au
            // début de la réinstall ferme la fenêtre où un clone de l'ancien
            // token vivrait pendant que le disque est formaté.
            $revoked = $workstation->agent_token_hash !== null;
            if ($revoked) {
                $this->tokens->revokeFor($workstation, 'reinstall');
            }

            $workstation->agent_enroll_ticket_hash = hash('sha256', $ticket);
            $workstation->agent_enroll_ticket_expires_at = now()->addMinutes($this->ttlMinutes());
            $workstation->save();

            return $revoked;
        });

        if ($revoked) {
            $this->log('info', 'agent.enroll.reinstall_revoked', [
                'workstation_id' => $workstation->id,
            ]);
        }

        $this->log('info', 'agent.enroll.ticket_opened', [
            'workstation_id' => $workstation->id,
            'expires_at' => $workstation->agent_enroll_ticket_expires_at->toIso8601String(),
        ]);

        return $ticket;
    }

    /**
     * Échange le ticket contre un token agent (endpoint
     * `POST /api/v1/agent/enrollment`).
     *
     * Résolution par hash du ticket exclusivement — uuid/mac/hostname de
     * `$identity` ne servent qu'au log de cohérence (AC3) et au choix
     * 409/403 en cas d'échec (AC4), jamais à l'autorisation.
     *
     * @param array{uuid?: string|null, mac?: string|null, hostname?: string|null} $identity
     */
    public function redeem(string $ticket, array $identity = []): EnrollmentResult
    {
        $workstation = $ticket === '' ? null : Workstation::query()
            ->where('agent_enroll_ticket_hash', hash('sha256', $ticket))
            ->first();

        $expired = $workstation !== null && (
            $workstation->agent_enroll_ticket_expires_at === null
            || $workstation->agent_enroll_ticket_expires_at->isPast()
        );

        if ($workstation === null || $expired) {
            return $this->handleGate2(
                $ticket === '' ? 'ticket_missing' : ($expired ? 'ticket_expired' : 'ticket_unknown'),
                $identity,
            );
        }

        // Consommation atomique : un seul redeem gagne le ticket, même en cas
        // de requêtes concurrentes (UPDATE conditionnel sur le hash — le
        // perdant retombe sur le chemin d'échec sans oracle).
        $claimed = Workstation::query()
            ->whereKey($workstation->getKey())
            ->where('agent_enroll_ticket_hash', $workstation->agent_enroll_ticket_hash)
            ->update([
                'agent_enroll_ticket_hash' => null,
                'agent_enroll_ticket_expires_at' => null,
            ]);

        if ($claimed !== 1) {
            return $this->handleGate2('ticket_already_consumed', $identity);
        }

        $workstation->refresh();
        $this->warnIfIdentityMismatch($workstation, $identity);

        $token = $this->tokens->issueFor($workstation);

        $this->log('info', 'agent.enroll.enrolled', [
            'workstation_id' => $workstation->id,
        ]);

        return EnrollmentResult::enrolled($token);
    }

    /**
     * Échec d'échange du ticket — point d'accueil de la porte 2 (Story 25.3).
     *
     * Ordre figé (anti-usurpation + sans-oracle) :
     *
     *  1. **Conflit** (AC4 / piège n° 4) : un poste identifiable par le faisceau
     *     est déjà enrôlé → 409, son token reste intact, AUCUNE demande pending
     *     n'est créée (ce n'est pas un poste migré qui rejoint, c'est un
     *     clone/ré-enrôlement potentiel).
     *  2. **Demande approuvée concordante** (AC2/AC3, décision n° 4) : si une
     *     demande `approved` existe pour ce faisceau ET que la concordance tient
     *     toujours (poste connu, non enrôlé, hostname cohérent) → `issueFor()`,
     *     consommation de la demande (statut terminal) → 200 token. C'est ici que
     *     le token naît, jamais dans l'UI.
     *  3. **Sinon** (AC1) : enregistrer/rafraîchir une demande (idempotence
     *     `updateOrCreate` sur la clé du faisceau), rapprocher, auto-approuver si
     *     campagne active ET concordance ET candidat unique — sinon `pending`.
     *
     * Dans tous les cas (2 mis à part), le poste reçoit un **403 indistinct**
     * (`notAllowed`) : la demande est un effet de bord serveur invisible (pas
     * d'oracle — le poste n'apprend pas s'il est attendu / refusé / inconnu).
     *
     * @param array{uuid?: string|null, mac?: string|null, hostname?: string|null} $identity
     */
    private function handleGate2(string $reason, array $identity): EnrollmentResult
    {
        // (1) Conflit : un poste DÉJÀ ENRÔLÉ partage l'ancre MAC → 409, jamais de
        // demande pending (clone / ré-enrôlement potentiel, pas un poste migré
        // qui rejoint). Review #M2/#M3 : conflit fondé sur la SEULE MAC (ancre) —
        // l'uuid (preuve faible/spoofable) ne sert jamais d'oracle de présence
        // (AC6, sans-oracle) — ET sur l'EXISTENCE d'un enrôlé partageant la MAC
        // (`exists()`, pas `.first()`) : un clone enrôlé sous MAC partagée est
        // toujours détecté quel que soit l'ordre des lignes en base.
        $mac = MacAddressNormalizer::normalize((string) ($identity['mac'] ?? ''));
        if ($mac !== null
            && Workstation::query()->where('mac', $mac)->whereNotNull('agent_token_hash')->exists()) {
            $this->log('warning', 'agent.enroll.rejected', [
                'reason' => $reason,
                'mac' => $mac,
                'conflict' => true,
            ]);

            return EnrollmentResult::conflict();
        }

        // (2) Demande déjà approuvée et toujours concordante → le token naît.
        $request = $this->findRequestByIdentity($identity);
        if ($request !== null
            && $request->status === AgentEnrollmentRequest::STATUS_APPROVED
            && $request->matched_workstation_id !== null) {
            $workstation = $request->matchedWorkstation()->first();
            if ($workstation !== null
                && ! $workstation->isAgentEnrolled()
                && $this->matcher->isConcordant($workstation, $identity)) {
                // Claim atomique (review #1, miroir porte 1) : la consommation de
                // la demande EST le verrou. Un DELETE conditionnel sur `approved`
                // garantit qu'un seul redeem concurrent gagne et émet le token —
                // le perdant retombe sur `notAllowed()` (403 sans oracle), jamais
                // de double `issueFor()` sur la même demande.
                $claimed = AgentEnrollmentRequest::query()
                    ->whereKey($request->id)
                    ->where('status', AgentEnrollmentRequest::STATUS_APPROVED)
                    ->delete();

                if ($claimed !== 1) {
                    return EnrollmentResult::notAllowed();
                }

                $token = $this->tokens->issueFor($workstation);

                $this->log('info', 'agent.enroll.enrolled', [
                    'workstation_id' => $workstation->id,
                    'gate' => 2,
                ]);

                return EnrollmentResult::enrolled($token);
            }
        }

        // (3) Enregistrer/rafraîchir la demande + auto-approbation éventuelle.
        // Une demande `rejected` n'est PAS ré-ouverte par un re-POST (anti-bruit,
        // décision n° 2 — l'admin garde la main pour re-armer).
        if ($request === null || $request->status === AgentEnrollmentRequest::STATUS_PENDING) {
            $this->recordRequest($reason, $identity, $request);
        } else {
            // Demande déjà résolue (rejetée, ou approuvée mais non concordante /
            // poste désormais enrôlé) : on rafraîchit seulement la récence sans
            // ré-ouvrir ni ré-approuver.
            $request->forceFill(['last_seen_at' => now()])->save();

            // (review #M4) Une demande `approved` qui se re-présente sans se
            // matérialiser (poste devenu enrôlé entre-temps, hostname divergent,
            // ou cible nulle) est un angle mort : elle est invisible (hors scope
            // pending) et le poste reste 403 indéfiniment. On le signale en
            // warning distinct pour le SIEM/admin (observabilité), sans bloquer.
            if ($request->status === AgentEnrollmentRequest::STATUS_APPROVED) {
                $this->log('warning', 'agent.enroll.stale_approval', [
                    'request_id' => $request->id,
                    'workstation_id' => $request->matched_workstation_id,
                ]);
            } else {
                $this->log('info', 'agent.enroll.requested', [
                    'request_id' => $request->id,
                    'status' => $request->status,
                    'workstation_id' => $request->matched_workstation_id,
                    'refresh' => true,
                ]);
            }
        }

        return EnrollmentResult::notAllowed();
    }

    /**
     * Crée ou rafraîchit la demande pending (idempotence — décision n° 2) puis,
     * si campagne active ET concordance ET candidat unique, l'auto-approuve.
     *
     * @param array{uuid?: string|null, mac?: string|null, hostname?: string|null} $identity
     */
    private function recordRequest(string $reason, array $identity, ?AgentEnrollmentRequest $existing): void
    {
        $mac = MacAddressNormalizer::normalize((string) ($identity['mac'] ?? ''));
        $hostname = trim((string) ($identity['hostname'] ?? '')) ?: null;
        $uuid = trim((string) ($identity['uuid'] ?? '')) ?: null;

        // Candidat unique rapproché (lecture seule workstations — zéro AD).
        $candidate = $this->matcher->match($identity);

        // Clé d'idempotence du faisceau : MAC si présente, sinon hostname. Sur du
        // vide intégral, on ne déduplique pas (demande tracée mais non rejouable).
        $key = $this->idempotencyKey($mac, $hostname);

        $attributes = [
            'mac' => $mac,
            'hostname' => $hostname,
            'uuid' => $uuid,
            'matched_workstation_id' => $candidate?->id,
            'last_seen_at' => now(),
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();
            $request = $existing;
        } elseif ($key !== null) {
            $request = AgentEnrollmentRequest::query()->updateOrCreate(
                $key,
                array_merge($attributes, ['status' => AgentEnrollmentRequest::STATUS_PENDING]),
            );
        } else {
            // Faisceau intégralement vide : tracé sans déduplication possible.
            $request = AgentEnrollmentRequest::query()->create(
                array_merge($attributes, ['status' => AgentEnrollmentRequest::STATUS_PENDING]),
            );
        }

        $this->log('info', 'agent.enroll.requested', [
            'request_id' => $request->id,
            'reason' => $reason,
            'workstation_id' => $request->matched_workstation_id,
            'matched' => $candidate !== null,
        ]);

        // Auto-approbation : campagne active ET concordance ET candidat unique.
        // L'anti-usurpation ne se débraye JAMAIS — toute divergence/conflit/inconnu
        // reste manuel même campagne ON (piège n° 3/4, invariant verrouillé).
        if ($request->status !== AgentEnrollmentRequest::STATUS_PENDING) {
            return;
        }

        if ($candidate !== null
            && $this->campaign->isActive()
            && $this->matcher->isConcordant($candidate, $identity)) {
            $request->forceFill([
                'status' => AgentEnrollmentRequest::STATUS_APPROVED,
                'auto_approved' => true,
                'matched_workstation_id' => $candidate->id,
                'resolved_at' => now(),
                'resolved_by' => null,
            ])->save();

            $this->log('info', 'agent.enroll.auto_approved', [
                'request_id' => $request->id,
                'workstation_id' => $candidate->id,
            ]);
        }
    }

    /**
     * Approbation un-clic depuis l'UI (AC2) : arme la demande. Le token naîtra
     * au prochain `redeem()` du poste (décision n° 4) — il ne transite pas ici.
     *
     * Le `$target` permet à l'admin de fixer le poste cible quand le faisceau
     * n'a pas rapproché de candidat unique (demande manuelle d'un poste
     * ambigu/inconnu) ; sinon le rapprochement existant est conservé.
     */
    public function approveManually(AgentEnrollmentRequest $request, ?int $resolvedBy, ?Workstation $target = null): void
    {
        // (review #2) Garde de statut : seule une demande `pending` s'approuve.
        // Défense en profondeur — l'UI filtre déjà via `->pending()`, mais une
        // demande déjà résolue ne doit pas être ré-armée silencieusement (AC4 :
        // re-armer est un acte explicite, pas un effet de bord d'un re-appel).
        if ($request->status !== AgentEnrollmentRequest::STATUS_PENDING) {
            return;
        }

        $workstationId = $target?->id ?? $request->matched_workstation_id;

        $request->forceFill([
            'status' => AgentEnrollmentRequest::STATUS_APPROVED,
            'auto_approved' => false,
            'matched_workstation_id' => $workstationId,
            'resolved_at' => now(),
            'resolved_by' => $resolvedBy,
        ])->save();

        $this->log('info', 'agent.enroll.approved', [
            'request_id' => $request->id,
            'workstation_id' => $workstationId,
            'resolved_by' => $resolvedBy,
        ]);
    }

    /**
     * Rejet manuel d'une demande douteuse (AC4) : le poste reste hors système.
     * Un re-POST ne ré-ouvre pas la demande (décision n° 2). Log distinct du
     * rejet technique porte 1 par `reason = manual_reject`.
     */
    public function rejectManually(AgentEnrollmentRequest $request, ?int $resolvedBy): void
    {
        // (review #2) Garde de statut : seule une demande `pending` se rejette
        // (idempotence ; pas de double rejet ni de rejet d'une demande approuvée).
        if ($request->status !== AgentEnrollmentRequest::STATUS_PENDING) {
            return;
        }

        $request->forceFill([
            'status' => AgentEnrollmentRequest::STATUS_REJECTED,
            'resolved_at' => now(),
            'resolved_by' => $resolvedBy,
        ])->save();

        $this->log('warning', 'agent.enroll.rejected', [
            'reason' => 'manual_reject',
            'request_id' => $request->id,
            'workstation_id' => $request->matched_workstation_id,
            'resolved_by' => $resolvedBy,
        ]);
    }

    /**
     * Retrouve la demande vivante du faisceau par sa clé d'idempotence (MAC, à
     * défaut hostname). Null si faisceau vide ou aucune demande.
     *
     * @param array{uuid?: string|null, mac?: string|null, hostname?: string|null} $identity
     */
    private function findRequestByIdentity(array $identity): ?AgentEnrollmentRequest
    {
        $mac = MacAddressNormalizer::normalize((string) ($identity['mac'] ?? ''));
        $hostname = trim((string) ($identity['hostname'] ?? '')) ?: null;

        $key = $this->idempotencyKey($mac, $hostname);
        if ($key === null) {
            return null;
        }

        return AgentEnrollmentRequest::query()->where($key)->first();
    }

    /**
     * Clé d'idempotence du faisceau : MAC normalisée si présente, sinon hostname
     * (lowercase). Null si tout est vide (demande non dédupliquable).
     *
     * @return array<string, string>|null
     */
    private function idempotencyKey(?string $mac, ?string $hostname): ?array
    {
        if ($mac !== null) {
            return ['mac' => $mac];
        }

        if ($hostname !== null) {
            return ['hostname' => mb_strtolower($hostname)];
        }

        return null;
    }

    /**
     * Log de cohérence (AC3) : uuid/mac/hostname reçus confrontés à la fiche.
     * Warning sans blocage — la fiche peut être en avance sur le poste en
     * cours d'install (rename programmé, MAC remplacée).
     */
    private function warnIfIdentityMismatch(Workstation $workstation, array $identity): void
    {
        $mismatches = [];

        $uuid = strtolower(trim((string) ($identity['uuid'] ?? '')));
        if ($uuid !== '' && $workstation->uuid !== null && strtolower($workstation->uuid) !== $uuid) {
            $mismatches[] = 'uuid';
        }

        $presentedMac = MacAddressNormalizer::normalize((string) ($identity['mac'] ?? ''));
        $expectedMac = MacAddressNormalizer::normalize((string) ($workstation->mac ?? ''));
        if ($presentedMac !== null && $expectedMac !== null && $presentedMac !== $expectedMac) {
            $mismatches[] = 'mac';
        }

        $hostname = trim((string) ($identity['hostname'] ?? ''));
        if ($hostname !== '' && $workstation->name !== null && strcasecmp($hostname, $workstation->name) !== 0) {
            $mismatches[] = 'hostname';
        }

        if ($mismatches === []) {
            return;
        }

        $this->log('warning', 'agent.enroll.identity_mismatch', [
            'workstation_id' => $workstation->id,
            'fields' => $mismatches,
        ]);
    }

    /**
     * TTL plancher 1 minute : une valeur 0/négative (fat-finger env) rendrait
     * tout ticket mort-né (iso plancher rotation 23.2).
     */
    private function ttlMinutes(): int
    {
        return max(1, (int) config('agent.enroll_ticket_ttl_minutes', 240));
    }

    /**
     * Log channel `agent`, action namespacée — jamais de ticket/token en
     * clair ni de hash (iso-convention 23.2).
     *
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $action, array $context = []): void
    {
        Log::channel('agent')->{$level}('[EnrollmentService] ' . $action, array_merge([
            'action_type' => $action,
        ], $context));
    }
}
