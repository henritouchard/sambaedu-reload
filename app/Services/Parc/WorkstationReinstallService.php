<?php

declare(strict_types=1);

namespace App\Services\Parc;

use App\Ipxe\Enums\IpxeAdminAction;
use App\Jobs\DispatchMachinePowerActionJob;
use App\Models\MachinePowerActionTask;
use App\Models\Workstation;
use App\Models\WorkstationReinstallRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 3.11 — Service métier de la réinstallation OS pilotée.
 *
 * Responsabilités :
 *  - **Armement** poste unique {@see armForMachine} + fan-out salle/groupe/
 *    multi-sélection {@see armForMachines} (skip `protected` D10, skip doublon
 *    actif, `insert()` bulk chunké D3/D11).
 *  - **Annulation** {@see cancel}.
 *  - **Résolution** de la requête active d'un poste {@see activeRequestFor}
 *    (lue par {@see \App\Ipxe\Services\IpxeService::resolveProgrammedAction()}).
 *  - **Transitions de statut** + garde anti-boucle (D5) : {@see markServed},
 *    {@see markInstalling}, {@see markDone}, {@see markFailed}.
 *  - **Déclenchement throttlé par vagues** (D6/D11) : {@see triggerReboot}
 *    (infra async 4.2) + {@see triggerDue} (plafond de concurrence, FIFO,
 *    idempotence `triggered_at`).
 *
 * **Ne touche JAMAIS** `Workstation::status` (varchar(20) domaine fermé →
 * SQLSTATE 22001) ni `Workstation::programmed_action` (réservé post-install).
 */
class WorkstationReinstallService
{
    /**
     * Catalogue OS exposé = whitelist install-only (D9). Exclut explicitement
     * la maintenance/diagnostic (rescuecd, winpe, factory_reset, clonezilla_*,
     * gparted, hdt, memtest86plus) : ce ne sont pas des réinstallations OS.
     *
     * @return list<string> Valeurs enum IpxeAdminAction install-only.
     */
    public function installOnlyActions(): array
    {
        return array_values(array_filter(
            array_map(static fn (IpxeAdminAction $a): string => $a->value, IpxeAdminAction::cases()),
            static fn (string $value): bool => str_starts_with($value, 'install_'),
        ));
    }

    public function isInstallOnly(string $targetAction): bool
    {
        return IpxeAdminAction::tryFrom($targetAction) !== null
            && in_array($targetAction, $this->installOnlyActions(), true);
    }

    /**
     * Libellé ASCII de l'OS (issu de `menu_items` config) pour l'UI et le
     * rendu iPXE. Fallback sur la valeur brute si absent.
     */
    public function labelFor(string $targetAction): string
    {
        foreach ($this->osCatalog() as $item) {
            if (($item['enum'] ?? null) === $targetAction) {
                return (string) ($item['label'] ?? $targetAction);
            }
        }

        return $targetAction;
    }

    /**
     * Catalogue OS exposé en UI = `ipxe.linux.menu_items` + `ipxe.windows.menu_items`
     * (D9). Chaque entrée : `['enum' => <valeur>, 'label' => <ASCII>, 'os' => 'linux'|'windows']`.
     *
     * @return list<array{enum:string, label:string, os:string}>
     */
    public function osCatalog(): array
    {
        $catalog = [];
        foreach ((array) config('ipxe.linux.menu_items', []) as $item) {
            $catalog[] = [
                'enum' => (string) ($item['enum'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'os' => 'linux',
            ];
        }
        foreach ((array) config('ipxe.windows.menu_items', []) as $item) {
            $catalog[] = [
                'enum' => (string) ($item['enum'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'os' => 'windows',
            ];
        }

        return $catalog;
    }

    // ========================================================================
    // Armement
    // ========================================================================

    /**
     * Arme la réinstallation d'un poste unique.
     *
     * @throws \InvalidArgumentException  Action hors whitelist install-only (D9).
     * @throws \DomainException           Poste `protected` (D10) ou requête active déjà présente.
     */
    public function armForMachine(
        Workstation $ws,
        string $targetAction,
        ?Carbon $scheduledAt,
        string $initiatedBy,
        ?int $createdByUserId = null,
    ): WorkstationReinstallRequest {
        if (! $this->isInstallOnly($targetAction)) {
            throw new \InvalidArgumentException("OS non installable : {$targetAction}");
        }

        // D10 niveau 2 — un poste protégé n'est JAMAIS armé.
        if ($ws->isProtected()) {
            throw new \DomainException('Ce poste est protégé et ne peut pas être réinstallé.');
        }

        if ($this->activeRequestFor($ws) !== null) {
            throw new \DomainException('Ce poste a déjà une réinstallation en cours.');
        }

        $now = Carbon::now();

        return WorkstationReinstallRequest::create([
            'workstation_id' => (int) $ws->id,
            'target_action' => $targetAction,
            'status' => WorkstationReinstallRequest::STATUS_ARMED,
            'boot_served_count' => 0,
            'initiated_by' => $initiatedBy,
            'created_by_user_id' => $createdByUserId,
            'scheduled_at' => $scheduledAt ?? $now,
            'triggered_at' => null,
            'boot_served_at' => null,
            'expires_at' => $this->computeExpiresAt($now, $scheduledAt),
        ]);
    }

    /**
     * Fan-out salle/groupe/multi-sélection (poste unique = cas N=1). Résout la
     * liste **à l'instant de l'armement** (D3 liste figée), skip les postes
     * `protected` (D10) et ceux déjà porteurs d'une requête active (pas de
     * doublon), puis crée les lignes en `insert()` bulk chunké (D3/D11).
     *
     * @param  iterable<Workstation>  $workstations
     * @return array{armed_count:int, skipped_duplicate:int, skipped_protected:int, armed_workstation_ids:list<int>}
     */
    public function armForMachines(
        iterable $workstations,
        string $targetAction,
        ?Carbon $scheduledAt,
        string $initiatedBy,
        ?int $createdByUserId = null,
    ): array {
        if (! $this->isInstallOnly($targetAction)) {
            throw new \InvalidArgumentException("OS non installable : {$targetAction}");
        }

        $now = Carbon::now();
        $scheduledAt ??= $now;
        $expiresAt = $this->computeExpiresAt($now, $scheduledAt);

        // Normalise l'itérable en collection de postes uniques par id.
        $machines = [];
        foreach ($workstations as $ws) {
            if ($ws instanceof Workstation && $ws->id !== null) {
                $machines[(int) $ws->id] = $ws;
            }
        }

        $skippedProtected = 0;
        $candidateIds = [];
        foreach ($machines as $id => $ws) {
            if ($ws->isProtected()) {
                $skippedProtected++;
                continue;
            }
            $candidateIds[] = $id;
        }

        // Un seul SELECT pour repérer les postes déjà porteurs d'une requête
        // active (skip doublon).
        $alreadyActiveIds = [];
        if (! empty($candidateIds)) {
            $alreadyActiveIds = WorkstationReinstallRequest::query()
                ->whereIn('workstation_id', $candidateIds)
                ->whereIn('status', WorkstationReinstallRequest::ACTIVE_STATUSES)
                ->pluck('workstation_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->all();
        }

        $toInsert = [];
        foreach ($candidateIds as $id) {
            if (in_array($id, $alreadyActiveIds, true)) {
                continue;
            }
            $toInsert[] = [
                'workstation_id' => $id,
                'target_action' => $targetAction,
                'status' => WorkstationReinstallRequest::STATUS_ARMED,
                'boot_served_count' => 0,
                'initiated_by' => $initiatedBy,
                'created_by_user_id' => $createdByUserId,
                'scheduled_at' => $scheduledAt,
                'triggered_at' => null,
                'boot_served_at' => null,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // insert() bulk chunké — pas de N saves Eloquent (D3/D11).
        foreach (array_chunk($toInsert, 500) as $chunk) {
            WorkstationReinstallRequest::query()->insert($chunk);
        }

        // Les ids réellement armés (nouvellement insérés).
        $armedIds = array_column($toInsert, 'workstation_id');

        return [
            'armed_count' => count($toInsert),
            'skipped_duplicate' => count($alreadyActiveIds),
            'skipped_protected' => $skippedProtected,
            // Fix review #7 — la clé contient des workstation_id (pas des ids de
            // requêtes) : nom explicite pour éviter toute confusion côté appelant.
            'armed_workstation_ids' => $armedIds,
        ];
    }

    // ========================================================================
    // Annulation & résolution
    // ========================================================================

    /**
     * Annule une requête non terminale. No-op si déjà terminale (D5/AC8).
     */
    public function cancel(WorkstationReinstallRequest $req): void
    {
        if ($req->isTerminal()) {
            return;
        }

        $req->update(['status' => WorkstationReinstallRequest::STATUS_CANCELED]);
    }

    /**
     * Abandonne la tentative en cours et en réarme une neuve sur le même OS.
     *
     * Sortie de secours pour une installation qui n'aboutit pas : une fois en
     * `installing` la requête n'est plus annulable (annuler n'arrêterait pas la
     * machine, {@see WorkstationReinstallRequest::isCancelable}), et sans ça le
     * poste resterait bloqué jusqu'à l'expiration du TTL — 6 h par défaut —
     * avant de pouvoir être réarmé.
     *
     * La tentative abandonnée est marquée `failed` et non `canceled` : elle
     * n'a effectivement pas abouti, et l'historique doit distinguer « l'admin a
     * renoncé avant le départ » de « la tentative a échoué, on rejoue ».
     *
     * Aucun reboot n'est déclenché ici : comme pour un armement normal (D2,
     * chemin unique), c'est le tick `parc:reinstall-due` qui s'en charge.
     *
     * @throws \DomainException  Poste devenu `protected` entre-temps (D10).
     */
    public function relaunchForWorkstation(
        Workstation $ws,
        string $initiatedBy,
        ?int $createdByUserId = null,
    ): ?WorkstationReinstallRequest {
        $current = $this->activeRequestFor($ws);
        if ($current === null) {
            return null;
        }

        $target = $current->target_action;
        $this->markFailed($current);

        // `markFailed` a rendu la précédente terminale : le garde anti-doublon
        // d'`armForMachine` laisse donc passer le nouvel armement.
        return $this->armForMachine($ws, $target, null, $initiatedBy, $createdByUserId);
    }

    /**
     * Requête active (non terminale) d'un poste. Lue par `resolveProgrammedAction`
     * au boot et par le skip-doublon de l'armement. La plus récente en cas
     * d'anomalie (ne devrait jamais y en avoir plus d'une active à la fois).
     */
    public function activeRequestFor(Workstation $ws): ?WorkstationReinstallRequest
    {
        return WorkstationReinstallRequest::query()
            ->where('workstation_id', (int) $ws->id)
            ->active()
            ->orderByDesc('id')
            ->first();
    }

    // ========================================================================
    // Transitions de statut (garde anti-boucle D5)
    // ========================================================================

    /**
     * Incrémente le compteur de serves + horodate + bascule `armed → serving`.
     * Appelé par `resolveProgrammedAction` à chaque PXE boot servi.
     */
    public function markServed(WorkstationReinstallRequest $req): void
    {
        $req->boot_served_count = $req->boot_served_count + 1;
        $req->boot_served_at = Carbon::now();
        if ($req->status === WorkstationReinstallRequest::STATUS_ARMED) {
            $req->status = WorkstationReinstallRequest::STATUS_SERVING;
        }
        $req->save();
    }

    /**
     * `serving → installing` : le payload d'installation a été délivré au poste,
     * l'installeur a la main. À partir de là la requête ne doit PLUS être servie
     * au boot (cf. `IpxeService::resolveProgrammedAction`) — voir
     * {@see markInstallingForWorkstation} pour le pourquoi.
     */
    public function markInstalling(WorkstationReinstallRequest $req): void
    {
        if ($req->isTerminal()) {
            return;
        }
        $req->update(['status' => WorkstationReinstallRequest::STATUS_INSTALLING]);
    }

    /**
     * Helper appelé par les trackers quand l'installeur a pris la main
     * (`install.bat` WinPE délivré) : bascule la requête active en `installing`.
     *
     * Sans ça, `setup.exe` — qui redémarre la machine lui-même en fin de phase
     * WinPE et ne rend jamais la main — retombe sur le PXE (premier dans le boot
     * order), se fait re-servir l'action, et WinPE repart de zéro : l'OOBE n'est
     * jamais atteint, donc `markDoneForWorkstation` n'est jamais appelé, et la
     * boucle n'est bornée que par le serve cap. Constaté sur `testenrol` le
     * 2026-07-19 (4 cycles de ~10 min).
     *
     * `installing` reste dans `ACTIVE_STATUSES` : le poste ne peut pas être
     * réarmé en double, et si l'installation échoue sans jamais rapporter l'OOBE,
     * le sweep TTL la passera `failed` et libèrera le poste.
     *
     * Enveloppé best-effort par l'appelant.
     */
    public function markInstallingForWorkstation(Workstation $ws): void
    {
        $req = $this->activeRequestFor($ws);
        if ($req !== null) {
            $this->markInstalling($req);
        }
    }

    public function markFailed(WorkstationReinstallRequest $req): void
    {
        if ($req->isTerminal()) {
            return;
        }
        $req->update(['status' => WorkstationReinstallRequest::STATUS_FAILED]);
    }

    /**
     * Consommation one-shot : la requête active du poste passe `done` (elle ne
     * sera plus servie). Appelé À CÔTÉ depuis les trackers post-install
     * (LinuxPostInstallTracker succès / WindowsPostInstallTracker terminal),
     * sans toucher `programmed_action`. Best-effort.
     */
    public function markDone(WorkstationReinstallRequest $req): void
    {
        if ($req->isTerminal()) {
            return;
        }
        $req->update(['status' => WorkstationReinstallRequest::STATUS_DONE]);
    }

    /**
     * Helper appelé par les trackers : marque `done` la requête active du poste
     * si elle existe. Enveloppé best-effort par l'appelant.
     */
    public function markDoneForWorkstation(Workstation $ws): void
    {
        $req = $this->activeRequestFor($ws);
        if ($req !== null) {
            $this->markDone($req);
        }
    }

    // ========================================================================
    // Déclenchement throttlé par le tick (D6/D11)
    // ========================================================================

    /**
     * Enqueue un reboot forcé (fallback WOL si éteint) via l'infra async 4.2 —
     * pas de nouveau worker. Pose `triggered_at` (idempotence du tick).
     */
    public function triggerReboot(WorkstationReinstallRequest $req): void
    {
        $task = MachinePowerActionTask::create([
            'workstation_id' => $req->workstation_id,
            'action' => 'restart',
            'status' => MachinePowerActionTask::STATUS_QUEUED,
            'initiated_by' => 'reinstall:' . $req->id,
            'initiated_at' => Carbon::now(),
            'restart_phase' => MachinePowerActionTask::RESTART_PHASE_WAITING_DOWN,
        ]);

        DispatchMachinePowerActionJob::dispatch($task->id);

        $req->update(['triggered_at' => Carbon::now()]);
    }

    /**
     * Déclenche les requêtes dûes au tick courant, borné par le plafond de
     * concurrence (D11).
     *
     * Algorithme :
     *  1. `in_flight` = requêtes actives déjà déclenchées (`triggered_at` non null).
     *  2. `slots` = max(0, max_concurrent − in_flight).
     *  3. Sélectionne les `slots` premières requêtes dûes
     *     (`status=armed AND triggered_at IS NULL AND scheduled_at <= now`),
     *     FIFO (`scheduled_at`, `id`).
     *  4. `triggerReboot()` pour chacune.
     *
     * Aucun I/O réseau direct — tick → enqueue seulement.
     *
     * @return int  Nombre de requêtes déclenchées.
     */
    public function triggerDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $maxConcurrent = max(0, (int) config('ipxe.reinstall.max_concurrent', 40));

        // Fix review #3 — sweep temporel : une requête active dont le TTL est
        // dépassé libère son slot par le TEMPS (pas par un boot). Sans ça, une
        // machine réellement morte (jamais bootée après triggerReboot) resterait
        // `serving`/`armed` en vol et bloquerait indéfiniment le plafond de
        // concurrence. On la passe `failed` avant de recalculer `in_flight`.
        WorkstationReinstallRequest::query()
            ->whereIn('status', WorkstationReinstallRequest::ACTIVE_STATUSES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->update(['status' => WorkstationReinstallRequest::STATUS_FAILED]);

        $inFlight = WorkstationReinstallRequest::query()
            ->whereIn('status', WorkstationReinstallRequest::IN_FLIGHT_STATUSES)
            ->whereNotNull('triggered_at')
            ->count();

        $slots = max(0, $maxConcurrent - $inFlight);
        if ($slots === 0) {
            return 0;
        }

        $due = WorkstationReinstallRequest::query()
            ->where('status', WorkstationReinstallRequest::STATUS_ARMED)
            ->whereNull('triggered_at')
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($slots)
            ->get();

        $triggered = 0;
        foreach ($due as $req) {
            try {
                $this->triggerReboot($req);
                $triggered++;
            } catch (\Throwable $e) {
                Log::error('[WorkstationReinstallService] triggerReboot a échoué', [
                    'reinstall_request_id' => $req->id,
                    'workstation_id' => $req->workstation_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $triggered;
    }

    /**
     * Purge des requêtes terminales > N jours (patron `parc:prune-group-schedule-runs`).
     */
    public function prune(int $retentionDays = 30): int
    {
        return WorkstationReinstallRequest::query()
            ->whereIn('status', WorkstationReinstallRequest::TERMINAL_STATUSES)
            ->where('updated_at', '<', Carbon::now()->subDays($retentionDays))
            ->delete();
    }

    /**
     * Calcule l'échéance TTL de la requête.
     *
     * Fix review #4 — l'échéance est ancrée sur `max($now, $scheduledAt)` : une
     * planification future (« ce soir ») armée le matin ne doit pas expirer
     * avant l'heure prévue du déclenchement. `$scheduledAt` null (armement
     * immédiat) revient à ancrer sur `$now`.
     */
    private function computeExpiresAt(Carbon $now, ?Carbon $scheduledAt = null): Carbon
    {
        $ttlHours = max(1, (int) config('ipxe.reinstall.ttl_hours', 6));

        $anchor = ($scheduledAt !== null && $scheduledAt->greaterThan($now))
            ? $scheduledAt
            : $now;

        return $anchor->copy()->addHours($ttlHours);
    }
}
