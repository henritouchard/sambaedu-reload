<?php

declare(strict_types=1);

namespace App\Services\Agent\Enrollment;

use App\Ipxe\Support\MacAddressNormalizer;
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
 */
class EnrollmentService
{
    public function __construct(
        private readonly TokenRotationService $tokens,
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
            return $this->reject(
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
            return $this->reject('ticket_already_consumed', $identity);
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
     * Échec d'échange (AC4) : 409 réservé au cas « poste identifiable ET
     * déjà enrôlé » (conflit réel, son token reste intact) ; tout le reste →
     * 403 indistinct — pas d'oracle sur l'état des tickets. La porte 2
     * (Story 25.3) transformera ce 403 en demande d'approbation.
     */
    private function reject(string $reason, array $identity): EnrollmentResult
    {
        $target = $this->resolveByIdentity($identity);
        $conflict = $target !== null && $target->agent_token_hash !== null;

        $this->log('warning', 'agent.enroll.rejected', [
            'reason' => $reason,
            'workstation_id' => $target?->id,
            'conflict' => $conflict,
        ]);

        return $conflict ? EnrollmentResult::conflict() : EnrollmentResult::notAllowed();
    }

    /**
     * Résout le poste visé par uuid, à défaut mac — uniquement pour le choix
     * 409/403 et le contexte de log, jamais pour autoriser.
     */
    private function resolveByIdentity(array $identity): ?Workstation
    {
        $uuid = strtolower(trim((string) ($identity['uuid'] ?? '')));
        if ($uuid !== '') {
            $found = Workstation::query()->whereRaw('LOWER(uuid) = ?', [$uuid])->first();
            if ($found !== null) {
                return $found;
            }
        }

        $mac = MacAddressNormalizer::normalize((string) ($identity['mac'] ?? ''));
        if ($mac !== null) {
            // `workstations.mac` est canonique lowercase `:` (mutator modèle).
            return Workstation::query()->where('mac', $mac)->first();
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
