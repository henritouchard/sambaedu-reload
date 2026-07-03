<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use App\Jobs\DispatchMachinePowerActionJob;
use App\Services\Parc\WorkstationGroupService;
use App\Services\Parc\MachinePowerService;
use App\Services\Parc\WorkstationDebugService;
use App\Services\AppProfile\AppProfileService;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\MachinePowerActionTask;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use App\Models\WorkstationGroup;
use App\Components\Traits\WithToasts;
use App\Exceptions\ControlHub\ApplicationNotInUpstreamCatalogException;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\Reporting\ConformityService;
use App\Services\Agent\SyncRequestService;
use App\Wpkg\Deployment\Generators\WorkstationIniGenerator;
use App\Wpkg\Deployment\Services\WorkstationOptionsService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

new #[Title('Détails de la Machine - SE4FS')] class extends Component {
    use WithToasts;

    private WorkstationGroupService $parcService;
    private MachinePowerService $machinePowerService;

    public ?Workstation $workstation = null;
    public string|int $id;

    public string $deploymentTab = 'errors';

    // Story 15.4 / Décision A 2026-05-07 — onglet de premier niveau (général | wpkg).
    // Le sous-onglet `wpkgSubTab` bascule entre assignation et options `.ini`.
    #[Url(as: 'tab')]
    public string $tab = 'general';

    public string $wpkgSubTab = 'assignment';

    // ── Modales WPKG (assignation directe poste) ───────────────────────────
    public bool $showAttachWpkgProfileModal = false;
    public array $selectedWpkgProfileIdsToAdd = [];
    public string $wpkgProfileSearch = '';

    public bool $showAttachWpkgAppModal = false;
    public array $selectedWpkgAppIdsToAdd = [];
    public string $wpkgAppSearch = '';

    // Options `.ini` per-poste — état UI lu/écrit depuis WpkgWorkstationOption.
    /** @var array<string,bool> */
    public array $wpkgOptionsState = [];

    // Pour la salle physique (unique)
    public ?int $selectedPhysicalRoomId = null;
    public Collection $availablePhysicalRooms;

    // Pour les groupes logiques (multiples)
    public array $selectedLogicalGroupIds = [];
    public Collection $availableLogicalGroups;

    // État de readiness post-action (AC2/AC3/AC4 — story 4-2).
    // Ces 4 propriétés pilotent le polling Livewire `wire:poll.Ns` :
    // le poll n'est rendu dans le Blade que si $statusRunning est vrai,
    // ce qui arrête automatiquement l'interrogation du serveur dès que
    // l'action est résolue (succès ou timeout).
    //
    // $currentTaskId : id de la ligne MachinePowerActionTask associée à
    // l'action en cours (review #1 — corrections 2026-04-20). Permet à
    // pollMachineReadiness() (a) de connaître l'état du job async, (b) de
    // gérer la machine à états `restart_phase` pour éviter le faux succès
    // du restart (review #2).
    public bool $statusRunning = false;
    public ?string $runningAction = null;
    public ?string $runningActionStartedAt = null;
    public ?int $currentTaskId = null;

    public function boot(WorkstationGroupService $parcService, MachinePowerService $machinePowerService): void
    {
        $this->parcService = $parcService;
        $this->machinePowerService = $machinePowerService;
    }

    public function mount(string|int $id): void
    {
        $this->id = (int) $id;
        $this->availablePhysicalRooms = collect();
        $this->availableLogicalGroups = collect();
        $this->loadMachine();
        $this->loadAvailableGroups();
        $this->initDeploymentTab();
        $this->loadWpkgOptionsState();

        if (session()->has('toast')) {
            $toastData = session('toast');
            $this->toast($toastData['type'] ?? 'info', $toastData['title'] ?? 'Notification', $toastData['message'] ?? '');
        }
    }

    public function loadMachine(): void
    {
        try {
            $this->workstation = $this->parcService->getWorkstation($this->id);

            if (!$this->workstation) {
                session()->flash('toast', [
                    'type' => 'error',
                    'title' => 'Erreur',
                    'message' => 'Machine non trouvée',
                ]);
                $this->redirect(route('app.parc.index'));
            }
        } catch (\Exception $e) {
            Log::error('[MachineShow] Erreur chargement: ' . $e->getMessage());
            $this->toastError('Erreur lors du chargement de la machine');
        }
    }

    public function loadAvailableGroups(): void
    {
        try {
            $this->availablePhysicalRooms = $this->parcService->getPhysicalRooms();

            // Story 4.11 — `groups` contient aussi la salle physique (pivot
            // global) ; on filtre via la relation `logicalGroups` pour ne pas
            // exclure/afficher la salle parmi les groupes logiques.
            $currentGroupIds = $this->workstation ? $this->workstation->logicalGroups->pluck('id')->toArray() : [];
            $this->availableLogicalGroups = WorkstationGroup::logical()->active()->whereNotIn('id', $currentGroupIds)->orderBy('name')->get();
        } catch (\Exception $e) {
            Log::error('[MachineShow] Erreur chargement groupes: ' . $e->getMessage());
            $this->availablePhysicalRooms = collect();
            $this->availableLogicalGroups = collect();
        }
    }

    public function assignToPhysicalRoom(?int $roomId = null): void
    {
        $roomId = $roomId ?? $this->selectedPhysicalRoomId;

        if (!$roomId) {
            $this->toastError('Veuillez sélectionner une salle');
            return;
        }

        try {
            $this->parcService->assignMachineToPhysicalRoom($this->id, $roomId);
            $this->toastSuccess('Machine assignée à la salle physique');
            $this->selectedPhysicalRoomId = null;
            $this->loadMachine();
        } catch (\Exception $e) {
            Log::error('[MachineShow] Erreur assignation salle: ' . $e->getMessage());
            $this->toastError($e->getMessage());
        }
    }

    public function removeFromPhysicalRoom(): void
    {
        try {
            $this->parcService->assignMachineToPhysicalRoom($this->id, null);
            $this->toastSuccess('Machine retirée de la salle physique');
            $this->loadMachine();
        } catch (\Exception $e) {
            Log::error('[MachineShow] Erreur retrait salle: ' . $e->getMessage());
            $this->toastError('Erreur lors du retrait de la salle');
        }
    }

    public function addToLogicalGroups(array|null $groupIds = null): void
    {
        $groupIds = $groupIds ?? $this->selectedLogicalGroupIds;

        if (empty($groupIds)) {
            $this->toastError('Veuillez sélectionner au moins un groupe');
            return;
        }

        try {
            $count = 0;
            foreach ($groupIds as $groupId) {
                $this->parcService->addMachineToGroup($this->id, (int) $groupId);
                $count++;
            }
            $this->toastSuccess($count > 1 ? "Machine ajoutée à {$count} groupes logiques" : 'Machine ajoutée au groupe logique');
            $this->selectedLogicalGroupIds = [];
            $this->loadMachine();
            $this->loadAvailableGroups();
        } catch (\App\Exceptions\ControlHub\UpstreamLockCollisionException $e) {
            // Story 30.5 — collision verrou/verrou prédite : message explicite.
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[MachineShow] Erreur ajout au groupe: ' . $e->getMessage());
            $this->toastError('Erreur lors de l\'ajout au groupe');
        }
    }

    public function removeFromLogicalGroup(int $groupId): void
    {
        try {
            $this->parcService->removeMachineFromGroup($this->id, $groupId);
            $this->toastSuccess('Machine retirée du groupe logique');
            $this->loadMachine();
            $this->loadAvailableGroups();
        } catch (\Exception $e) {
            Log::error('[MachineShow] Erreur retrait du groupe: ' . $e->getMessage());
            $this->toastError('Erreur lors du retrait du groupe');
        }
    }

    /**
     * Story 23.2 / AC6 — Révocation par événement du token agent (FR14).
     * Le prochain appel du poste sur le canal agent recevra 401.
     */
    public function revokeAgentToken(TokenRotationService $tokenService): void
    {
        if (!$this->workstation) {
            $this->loadMachine();
        }
        if (!$this->workstation || !$this->workstation->isAgentEnrolled()) {
            $this->toastError('Ce poste n\'a pas de token agent actif');
            return;
        }

        try {
            $tokenService->revokeFor($this->workstation, 'revoked_from_ui_by_admin');
            $this->toastSuccess('Token agent révoqué');
            $this->loadMachine();
        } catch (\Exception $e) {
            Log::error('[MachineShow] Erreur révocation token agent: ' . $e->getMessage(), [
                'machine_id' => $this->id,
            ]);
            $this->toastError('Erreur lors de la révocation du token agent');
        }
    }

    /**
     * Mode debug du poste — bascule unique (canal agent + options WPKG
     * debug/logdebug) via WorkstationDebugService. Même gate de contrôle
     * poste que « forcer la synchro » (re-vérifié serveur-side : une requête
     * Livewire forgée ne contourne pas l'éligibilité).
     */
    public function toggleDebugMode(WorkstationDebugService $debugService): void
    {
        if (! Gate::allows('computer.control')) {
            $this->toastAccessDenied();
            return;
        }

        if (! $this->workstation) {
            $this->loadMachine();
        }
        if (! $this->workstation) {
            $this->toastError('Machine non trouvée');
            return;
        }

        try {
            $enabled = ! $this->workstation->debug;
            $debugService->setDebug($this->workstation, $enabled);
            $this->toastSuccess($enabled
                ? 'Mode debug activé — console agent conservée + logs WPKG verbeux'
                : 'Mode debug désactivé');
            $this->loadMachine();
        } catch (\Exception $e) {
            Log::error('[MachineShow] Erreur bascule mode debug: ' . $e->getMessage(), [
                'machine_id' => $this->id,
            ]);
            $this->toastError('Erreur lors de la bascule du mode debug');
        }
    }

    /**
     * Story 24.7 / AC5 — « Forcer la synchro » d'un poste (mécanique PULL,
     * décision n° 1). Pose `agent_sync_requested_at` via SyncRequestService :
     * le prochain `GET /state` du poste re-télécharge l'état complet (bypass
     * 304), le premier `POST /report` suivant solde la demande. Désactivé
     * (côté Blade) pour un poste non enrôlé ou en quarantaine (piège 6) — on
     * re-garde côté serveur (une requête Livewire forgée ne contourne pas
     * l'éligibilité, le service filtre).
     */
    public function forceSyncWorkstation(SyncRequestService $syncRequests): void
    {
        // Guard serveur-side : forcer la synchro = action de contrôle du
        // poste, même gate que les autres mutations parc (review 24.7 #1).
        if (!Gate::allows('computer.control')) {
            $this->toastAccessDenied();
            return;
        }

        if (!$this->workstation) {
            $this->loadMachine();
        }
        if (!$this->workstation) {
            $this->toastError('Machine non trouvée');
            return;
        }

        if (!$this->workstation->isAgentEnrolled()) {
            $this->toastError('Ce poste n\'a pas de token agent actif');
            return;
        }
        if ($this->workstation->isAgentQuarantined()) {
            $this->toastError('Poste en quarantaine : la synchro ne peut pas être forcée');
            return;
        }

        try {
            $admin = auth()->user();
            $count = $syncRequests->request($this->workstation, $admin);

            if ($count > 0) {
                $this->toastSuccess('Synchro demandée — sera servie au prochain check-in du poste');
            } else {
                $this->toastWarning('Aucune demande posée (poste non éligible)');
            }
            $this->loadMachine();
        } catch (\Exception $e) {
            Log::error('[MachineShow] Erreur demande de synchro: ' . $e->getMessage(), [
                'machine_id' => $this->id,
            ]);
            $this->toastError('Erreur lors de la demande de synchronisation');
        }
    }

    /**
     * Story 24.7 / AC2 — États rapportés COURANTS par type (lecture agrégée
     * via ConformityService, relue à chaque cycle wire:poll pour le retour
     * auto à compliant — AC4).
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\AgentResourceState>
     */
    public function getAgentStatesProperty(): Collection
    {
        if (!$this->workstation || !$this->workstation->isAgentEnrolled()) {
            return collect();
        }

        return app(ConformityService::class)->statesFor($this->workstation);
    }

    /**
     * Story 24.7 / AC2 — Derniers événements de changement (10, datés).
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\AgentReportEvent>
     */
    public function getAgentEventsProperty(): Collection
    {
        if (!$this->workstation || !$this->workstation->isAgentEnrolled()) {
            return collect();
        }

        return app(ConformityService::class)->recentEventsFor($this->workstation, 10);
    }

    /**
     * Version stable publiée du binaire agent (`agent_releases.is_stable`,
     * au plus une ligne — invariant 25.1). Null si aucune release publiée.
     * Sert au badge « à jour » de l'onglet Agent.
     */
    public function getStableAgentVersionProperty(): ?string
    {
        return \App\Models\AgentRelease::query()->where('is_stable', true)->value('version');
    }

    public function executeMachinePowerAction(string $action): void
    {
        // Guard review #14 (2026-04-20) : empêche de lancer une seconde action
        // tant que la précédente n'est pas résolue. Indispensable car
        // `@disabled` côté Blade ne protège pas d'une requête Livewire forgée
        // manuellement (double-click rapide, dev tools, etc.).
        if ($this->statusRunning && $action !== 'remote') {
            $this->toastWarning('Une action est déjà en cours sur cette machine.');
            return;
        }

        // La Workstation est Wireable et se rehydrate via findOrFail(id) entre
        // les requêtes ; on recharge depuis le service en dernier recours pour
        // garder le composant robuste si la rehydratation a échoué.
        if (!$this->workstation) {
            $this->loadMachine();
        }
        if (!$this->workstation) {
            $this->toastError('Machine non trouvée');
            return;
        }

        // L'accès distant reste synchrone — c'est une simple génération de
        // token + redirection, pas une action power à suivre en readiness.
        if ($action === 'remote') {
            try {
                $result = $this->parcService->executeMachineAction((int) $this->workstation->id, 'remote');
                $this->handleRemoteAccessResult($result);
            } catch (\Exception $e) {
                Log::error('[MachineShow] Erreur accès distant: ' . $e->getMessage(), [
                    'machine_id' => $this->workstation->id,
                ]);
                $this->toastError('Erreur lors de la génération de la connexion à distance');
            }
            return;
        }

        // Review #1 (NFR2) — on dispatche le job async puis on retourne
        // immédiatement. Le toast apparaît sans attendre ping/shell (< 500 ms).
        try {
            // Validation de l'action en amont (même logique que WorkstationGroupService).
            if (!in_array($action, ['wake', 'shutdown', 'shutdown-force', 'restart'], true)) {
                throw new \InvalidArgumentException("Action machine non supportée: {$action}");
            }

            $actionLabel = $this->parcService->getMachineActionLabel($action);
            $initiatedBy = auth()->user()?->name ?? session('login') ?? 'system';

            // Review #2 — pour un restart, on initialise la phase 'waiting-down'
            // pour que le polling attende d'abord que la machine cesse de
            // répondre avant de chercher son retour (évite le faux succès à t+3s).
            $restartPhase = $action === 'restart'
                ? MachinePowerActionTask::RESTART_PHASE_WAITING_DOWN
                : null;

            $task = MachinePowerActionTask::create([
                'workstation_id' => (int) $this->workstation->id,
                'action' => $action,
                'status' => MachinePowerActionTask::STATUS_QUEUED,
                'initiated_by' => $initiatedBy,
                'initiated_at' => now(),
                'restart_phase' => $restartPhase,
            ]);

            DispatchMachinePowerActionJob::dispatch($task->id);

            // Bascule UI "en cours" — trigge le rendu du wire:poll qui appellera
            // pollMachineReadiness() toutes les N secondes.
            $this->statusRunning = true;
            $this->runningAction = $action;
            $this->runningActionStartedAt = now()->toIso8601String();
            $this->currentTaskId = (int) $task->id;

            $this->toastSuccess("Action de {$actionLabel} lancée");
        } catch (\InvalidArgumentException $e) {
            $this->stopReadinessPolling();
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            $this->stopReadinessPolling();
            Log::error('[MachineShow] Erreur dispatch action machine: ' . $e->getMessage(), [
                'machine_id' => $this->workstation->id,
                'action' => $action,
            ]);
            $this->toastError('Erreur lors du lancement de l\'action');
        }
    }

    /**
     * Polling de readiness post-action (AC3/AC4 story 4-2).
     *
     * Appelé par wire:poll.{N}s sur la vue tant que $statusRunning est vrai.
     *
     * Responsabilités (review #1 & #2 — 2026-04-20) :
     *  1. Consommer l'état de la task DB (MachinePowerActionTask) pour détecter
     *     les échecs remontés par DispatchMachinePowerActionJob (MAC invalide,
     *     shutdown sur machine off, exception, etc.).
     *  2. Appliquer la machine à états `restart_phase` pour un reboot, afin
     *     d'éviter le faux succès à t+3s (la machine répond encore car pas
     *     encore éteinte).
     *  3. Confirmer la readiness via ping selon l'action :
     *     - wake                   : succès quand ping renvoie un OS (UP).
     *     - shutdown/shutdown-force: succès quand ping renvoie false (DOWN).
     *     - restart                : waiting-down → waiting-up → UP.
     *  4. Couper le polling sur timeout (toast warning + log).
     */
    public function pollMachineReadiness(): void
    {
        if (!$this->statusRunning || !$this->workstation) {
            return;
        }

        $startedAt = $this->runningActionStartedAt ? Carbon::parse($this->runningActionStartedAt) : null;
        if (!$startedAt) {
            $this->stopReadinessPolling();
            return;
        }

        $elapsed = (int) now()->diffInSeconds($startedAt, true);
        $timeout = (int) config('parc.machine_readiness_timeout_seconds', 120);
        $machineName = (string) $this->workstation->name;
        $actionInProgress = (string) ($this->runningAction ?? 'wake');

        // (a) Timeout en premier — évite un ping réseau inutile quand on sait
        // déjà qu'on va couper.
        if ($elapsed >= $timeout) {
            $this->machinePowerService->logReadinessTimeout($machineName, $actionInProgress);
            // On marque aussi la task comme failed pour garder l'audit trail
            // cohérent avec l'état UI (fallback silencieux si la task n'existe
            // plus — edge case de rehydratation).
            if ($this->currentTaskId) {
                MachinePowerActionTask::where('id', $this->currentTaskId)
                    ->whereIn('status', MachinePowerActionTask::ACTIVE_STATUSES)
                    ->update([
                        'status' => MachinePowerActionTask::STATUS_FAILED,
                        'completed_at' => now(),
                        'error_message' => "Readiness timeout ({$timeout}s)",
                    ]);
            }
            $this->toastWarning(
                "Machine {$machineName} non joignable après {$timeout}s — vérifiez l'alimentation, le câble réseau ou la MAC configurée",
            );
            $this->stopReadinessPolling();
            return;
        }

        // (b) Consommation de l'état de la task — échecs remontés par le job.
        //     Le job peut marquer 'failed' pour : MAC invalide, shutdown sur
        //     machine off, exception. Dans ce cas on coupe le polling et
        //     on affiche l'erreur remontée.
        if ($this->currentTaskId) {
            /** @var MachinePowerActionTask|null $task */
            $task = MachinePowerActionTask::find($this->currentTaskId);

            if ($task && $task->status === MachinePowerActionTask::STATUS_FAILED) {
                $this->toastError($task->error_message ?? "Échec de l'action {$actionInProgress}");
                $this->stopReadinessPolling();
                return;
            }

            // Restart = machine à états (review #2).
            // Tant qu'on est en 'waiting-down', on attend que la machine cesse
            // de répondre. Une fois détectée offline on passe à 'waiting-up'.
            // Une fois détectée online, on marque la task completed.
            if ($task && $task->action === 'restart') {
                $ip = (string) ($this->workstation->ip ?? $machineName);
                $ping = $this->machinePowerService->ping($ip);

                if ($task->restart_phase === MachinePowerActionTask::RESTART_PHASE_WAITING_DOWN) {
                    if ($ping === false) {
                        $task->update(['restart_phase' => MachinePowerActionTask::RESTART_PHASE_WAITING_UP]);
                    }
                    // Tant que la machine répond encore, on continue le poll
                    // sans toaster — la machine est en train de redémarrer.
                    return;
                }

                if ($task->restart_phase === MachinePowerActionTask::RESTART_PHASE_WAITING_UP) {
                    if ($ping !== false) {
                        $task->update([
                            'status' => MachinePowerActionTask::STATUS_COMPLETED,
                            'completed_at' => now(),
                        ]);
                        $this->toastSuccess("Redémarrage de {$machineName} confirmé (détectée en {$elapsed}s).");
                        $this->stopReadinessPolling();
                    }
                    return;
                }

                // Phase inconnue — fail-safe : on coupe le polling pour éviter
                // une boucle infinie.
                $this->stopReadinessPolling();
                return;
            }
        }

        // (c) wake / shutdown / shutdown-force — readiness par ping simple.
        $ip = (string) ($this->workstation->ip ?? $machineName);
        $ping = $this->machinePowerService->ping($ip);

        $isResolved = match ($actionInProgress) {
            'wake' => $ping !== false,
            'shutdown', 'shutdown-force' => $ping === false,
            default => false,
        };

        if ($isResolved) {
            if ($this->currentTaskId) {
                MachinePowerActionTask::where('id', $this->currentTaskId)
                    ->whereIn('status', MachinePowerActionTask::ACTIVE_STATUSES)
                    ->update([
                        'status' => MachinePowerActionTask::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ]);
            }
            $label = $this->parcService->getMachineActionLabel($actionInProgress);
            if ($actionInProgress === 'wake') {
                $this->toastSuccess("Machine {$machineName} disponible (détectée en {$elapsed}s)");
            } else {
                $this->toastSuccess("Machine {$machineName} {$label} confirmée en {$elapsed}s");
            }
            $this->stopReadinessPolling();
        }
    }

    /**
     * Arrête le polling de readiness (stop le rendu de wire:poll côté Blade).
     */
    private function stopReadinessPolling(): void
    {
        $this->statusRunning = false;
        $this->runningAction = null;
        $this->runningActionStartedAt = null;
        $this->currentTaskId = null;
    }

    private function handleRemoteAccessResult(array $result): void
    {
        if ($result['failed_count'] === 0 && $result['success_count'] > 0) {
            $remoteUrl = $result['results'][0]['url'] ?? null;
            if ($remoteUrl) {
                $this->redirect($remoteUrl);
            } else {
                $this->toastError('URL de connexion non générée');
            }
        } else {
            $this->toastError('Échec de la génération de la connexion à distance');
        }
    }

    public function getMachineActionsProperty(): Collection
    {
        return collect($this->parcService->getAvailableMachineActions())
            ->map(static fn(array $action): object => (object) $action)
            ->values();
    }

    public function getDeploymentStatusesProperty(): array
    {
        if (!$this->workstation) {
            return ['success' => collect(), 'errors' => collect(), 'in_progress' => collect()];
        }

        $statuses = WorkstationApplicationStatus::query()
            ->with('application')
            ->where('workstation_id', $this->workstation->id)
            ->get();

        return [
            'success'     => $statuses->filter(fn ($s) => $s->status === 'installed'),
            'errors'      => $statuses->filter(fn ($s) => in_array($s->status, ['error', 'not-installed'])),
            'in_progress' => $statuses->filter(fn ($s) => in_array($s->status, ['upgrading', 'downgrading'])),
        ];
    }

    public function initDeploymentTab(): void
    {
        $deployment = $this->deploymentStatuses;
        $this->deploymentTab = $deployment['errors']->isNotEmpty() ? 'errors' : 'success';
    }

    #[On('workstation-group-selected')]
    public function handleGroupSelected(string $drawerId, int|array|null $selected): void
    {
        Log::info('[MachineShow] Group selected', ['drawerId' => $drawerId, 'selected' => $selected]);

        if (empty($selected)) {
            return;
        }

        switch ($drawerId) {
            case 'assign-physical-room':
            case 'change-physical-room':
                $this->assignToPhysicalRoom((int) $selected);
                break;

            case 'add-logical-groups':
                $this->addToLogicalGroups(is_array($selected) ? $selected : [$selected]);
                break;
        }
    }

    // ============================================================
    // Story 15.4 — Onglet Applications WPKG (Décision A)
    // ============================================================

    public function setTab(string $tab): void
    {
        $allowed = ['general', 'wpkg', 'agent'];
        $this->tab = in_array($tab, $allowed, true) ? $tab : 'general';
    }

    public function setWpkgSubTab(string $sub): void
    {
        $allowed = ['assignment', 'options'];
        $this->wpkgSubTab = in_array($sub, $allowed, true) ? $sub : 'assignment';
    }

    public function loadWpkgOptionsState(): void
    {
        $this->wpkgOptionsState = [];

        if (! $this->workstation) {
            return;
        }

        $overrides = $this->workstation->wpkgOptions()->pluck('option_value', 'option_key');

        foreach (WorkstationIniGenerator::LEGACY_OPTIONS as $opt) {
            $key = $opt['name'];
            $this->wpkgOptionsState[$key] = ($overrides[$key] ?? 'false') === 'true';
        }
    }

    /**
     * Calcule la liste des AppProfiles attachés (directs + hérités via parcs)
     * pour affichage badges « hérité » vs « direct ».
     *
     * @return array{direct: \Illuminate\Support\Collection, inherited: \Illuminate\Support\Collection}
     */
    public function getWpkgAttachedProfilesProperty(): array
    {
        if (! $this->workstation) {
            return ['direct' => collect(), 'inherited' => collect()];
        }

        $this->workstation->loadMissing(['appProfiles', 'groups.appProfiles']);

        $direct = $this->workstation->appProfiles;
        $inherited = collect();

        foreach ($this->workstation->groups as $group) {
            foreach ($group->appProfiles as $profile) {
                $existing = $inherited->firstWhere('id', $profile->id);
                if ($existing === null) {
                    $profile->_inheritedFromGroup = $group;
                    $inherited->push($profile);
                }
            }
        }

        return ['direct' => $direct, 'inherited' => $inherited];
    }

    /**
     * Apps directes au poste vs héritées (parcs).
     *
     * @return array{direct: \Illuminate\Support\Collection, inherited: \Illuminate\Support\Collection}
     */
    public function getWpkgAttachedApplicationsProperty(): array
    {
        if (! $this->workstation) {
            return ['direct' => collect(), 'inherited' => collect()];
        }

        $this->workstation->loadMissing(['applications', 'groups.applications']);

        $direct = $this->workstation->applications;
        $inherited = collect();

        foreach ($this->workstation->groups as $group) {
            foreach ($group->applications as $app) {
                if ($inherited->firstWhere('id', $app->id) === null) {
                    $app->_inheritedFromGroup = $group;
                    $inherited->push($app);
                }
            }
        }

        return ['direct' => $direct, 'inherited' => $inherited];
    }

    public function getAvailableWpkgProfilesProperty()
    {
        if (! $this->workstation) {
            return collect();
        }
        $existing = $this->workstation->appProfiles()->pluck('app_profiles.id')->toArray();
        // Story 15.4 / Correction post-review #2 : eager-load `applications`
        // pour le sous-texte « N application(s) » de attach-profiles-modal
        // (évite le N+1).
        $query = AppProfile::query()
            ->where('is_active', true)
            ->whereNotIn('id', $existing)
            ->with('applications:id');
        if ($this->wpkgProfileSearch !== '') {
            $query->where(function ($q) {
                $q->where('name', 'LIKE', "%{$this->wpkgProfileSearch}%")
                    ->orWhere('display_name', 'LIKE', "%{$this->wpkgProfileSearch}%");
            });
        }

        return $query->orderBy('name')->limit(50)->get();
    }

    public function getAvailableWpkgApplicationsProperty()
    {
        if (! $this->workstation) {
            return collect();
        }
        $existing = $this->workstation->applications()->pluck('applications.id')->toArray();
        // Story 31.1 — borne la liste proposée au catalogue applicatif amont
        // (pass-through NFR3 si standalone / catalogue vide).
        $query = Application::query()->inUpstreamCatalog()->whereNotIn('id', $existing);
        if ($this->wpkgAppSearch !== '') {
            $query->where(function ($q) {
                $q->where('name', 'LIKE', "%{$this->wpkgAppSearch}%")
                    ->orWhere('app_id', 'LIKE', "%{$this->wpkgAppSearch}%");
            });
        }

        return $query->orderBy('name')->limit(50)->get();
    }

    // Modales — open/close
    public function openAttachWpkgProfileModal(): void
    {
        $this->ensureWpkgAssignAuthorized();
        $this->selectedWpkgProfileIdsToAdd = [];
        $this->wpkgProfileSearch = '';
        $this->showAttachWpkgProfileModal = true;
    }

    public function closeAttachWpkgProfileModal(): void
    {
        $this->showAttachWpkgProfileModal = false;
    }

    public function openAttachWpkgAppModal(): void
    {
        $this->ensureWpkgAssignAuthorized();
        $this->selectedWpkgAppIdsToAdd = [];
        $this->wpkgAppSearch = '';
        $this->showAttachWpkgAppModal = true;
    }

    public function closeAttachWpkgAppModal(): void
    {
        $this->showAttachWpkgAppModal = false;
    }

    public function attachWpkgProfiles(AppProfileService $appProfileService): void
    {
        $this->ensureWpkgAssignAuthorized();

        if (empty($this->selectedWpkgProfileIdsToAdd)) {
            $this->toastError('Aucun profil sélectionné');
            return;
        }

        try {
            foreach ($this->selectedWpkgProfileIdsToAdd as $profileId) {
                $appProfileService->addWorkstations((int) $profileId, [$this->workstation->id]);
            }
            $this->toastSuccess(count($this->selectedWpkgProfileIdsToAdd).' profil(s) ajouté(s) au poste');
            $this->closeAttachWpkgProfileModal();
            $this->loadMachine();
        } catch (\Exception $e) {
            Log::error('[MachineWpkg] Erreur attach profils: '.$e->getMessage());
            $this->toastError('Erreur lors de l\'ajout des profils');
        }
    }

    public function detachWpkgProfile(int $profileId, AppProfileService $appProfileService): void
    {
        $this->ensureWpkgAssignAuthorized();
        try {
            $appProfileService->removeWorkstations($profileId, [$this->workstation->id]);
            $this->toastSuccess('Profil retiré du poste');
            $this->loadMachine();
        } catch (\Exception $e) {
            Log::error('[MachineWpkg] Erreur detach profil: '.$e->getMessage());
            $this->toastError('Erreur lors du retrait du profil');
        }
    }

    public function attachWpkgApplications(AppProfileService $appProfileService): void
    {
        $this->ensureWpkgAssignAuthorized();

        if (empty($this->selectedWpkgAppIdsToAdd)) {
            $this->toastError('Aucune application sélectionnée');
            return;
        }

        try {
            $appProfileService->addApplicationsToWorkstation(
                $this->workstation->id,
                $this->selectedWpkgAppIdsToAdd,
            );
            $this->toastSuccess(count($this->selectedWpkgAppIdsToAdd).' application(s) ajoutée(s)');
            $this->closeAttachWpkgAppModal();
            $this->loadMachine();
        } catch (ApplicationNotInUpstreamCatalogException $e) {
            // Story 31.1 — refus « hors catalogue amont » : message explicite (FR8).
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[MachineWpkg] Erreur attach apps: '.$e->getMessage());
            $this->toastError('Erreur lors de l\'ajout des applications');
        }
    }

    public function detachWpkgApplication(int $applicationId, AppProfileService $appProfileService): void
    {
        $this->ensureWpkgAssignAuthorized();
        try {
            $appProfileService->removeApplicationsFromWorkstation(
                $this->workstation->id,
                [$applicationId],
            );
            $this->toastSuccess('Application retirée du poste');
            $this->loadMachine();
        } catch (\Exception $e) {
            Log::error('[MachineWpkg] Erreur detach app: '.$e->getMessage());
            $this->toastError('Erreur lors du retrait de l\'application');
        }
    }

    public function toggleWpkgOption(string $key): void
    {
        $this->ensureWpkgAssignAuthorized();
        if (! array_key_exists($key, $this->wpkgOptionsState)) {
            return;
        }
        $this->wpkgOptionsState[$key] = ! ($this->wpkgOptionsState[$key] ?? false);
    }

    public function saveWpkgOptions(WorkstationOptionsService $service): void
    {
        $this->ensureWpkgAssignAuthorized();
        try {
            $changed = $service->update($this->workstation->id, $this->wpkgOptionsState);
            $count = count($changed);
            $this->loadWpkgOptionsState();
            if ($count === 0) {
                $this->toast('info', 'Options WPKG', 'Aucune modification à enregistrer.');
                return;
            }
            $this->toastSuccess("Options WPKG mises à jour ({$count} modification(s))");
        } catch (\Throwable $e) {
            Log::error('[MachineWpkg] Erreur save options: '.$e->getMessage());
            $this->toastError($e->getMessage());
        }
    }

    public function resetWpkgOptions(WorkstationOptionsService $service): void
    {
        $this->ensureWpkgAssignAuthorized();
        try {
            $service->resetToDefaults($this->workstation->id);
            $this->loadWpkgOptionsState();
            $this->toastSuccess('Options WPKG réinitialisées aux défauts');
        } catch (\Throwable $e) {
            Log::error('[MachineWpkg] Erreur reset options: '.$e->getMessage());
            $this->toastError('Erreur lors de la réinitialisation');
        }
    }

    private function ensureWpkgAssignAuthorized(): void
    {
        try {
            // Story 29.1 — Gate SCOPÉ sur la SALLE PHYSIQUE de rattachement du
            // poste (`Workstation::physicalRoom`). Une délégation WPKG sur cette
            // salle devient opposable au niveau poste.
            // Poste sans salle physique (nomade/non rattaché) → `physicalRoom`
            // vaut null : la policy se rabat alors sur le droit GLOBAL seul
            // (AC #5 — pas de fausse ouverture, seul l'admin global passe).
            Gate::authorize('assign-wpkg-workstationGroup', $this->workstation->physicalRoom);
        } catch (AuthorizationException $e) {
            $this->toastError('Vous n\'avez pas la permission de modifier les assignations WPKG.');
            throw $e;
        }
    }
};
?>

<x-organisms.page title="Détails du Poste" :scrollable="true" description="Détail du poste"
    backUrl="{{ route('app.parc.index') }}" backText="Retour">

    <x-slot:actions>
        <div class="flex gap-2 items-center">
            {{--
                Badge "action en cours" (AC2/AC3 story 4-2).
                Ce wrapper est l'unique porteur de wire:poll : tant que
                $statusRunning est vrai Livewire poll toutes les N secondes et
                appelle pollMachineReadiness(). Quand l'action est résolue
                (succès ou timeout), $statusRunning repasse à false, la partie
                @if n'est plus rendue et Livewire cesse d'interroger le serveur.
            --}}
            @if ($statusRunning && $workstation)
                @php
                    $pollInterval = (int) config('parc.machine_readiness_poll_interval_seconds', 3);
                    $runningLabel = $this->parcService->getMachineActionLabel($runningAction ?? 'wake');
                @endphp
                <div wire:poll.{{ $pollInterval }}s="pollMachineReadiness"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg bg-info/10 border border-info/30 text-info">
                    <span class="loading loading-spinner loading-sm"></span>
                    <span class="text-sm font-medium">{{ ucfirst($runningLabel) }} en cours…</span>
                </div>
            @endif

            @if ($workstation)
                <div class="dropdown dropdown-end">
                    <label tabindex="0" class="btn btn-primary">
                        <i class="fa-solid fa-bolt"></i>
                        Actions machine
                        <i class="fa-solid fa-chevron-down text-xs ml-1"></i>
                    </label>
                    <ul tabindex="0"
                        class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-60 border border-base-300 mt-2">
                        @foreach ($this->machineActions as $action)
                            @php
                                $confirmMessage = match ($action->key) {
                                    'shutdown' => 'Confirmer l\'extinction de cette machine ?',
                                    'shutdown-force' => 'Forcer l\'extinction ? Attention : un utilisateur peut perdre son travail non sauvegardé.',
                                    'restart' => 'Confirmer le redémarrage de cette machine ?',
                                    default => null,
                                };
                                $isDangerous = $action->key === 'shutdown-force';
                            @endphp
                            <li>
                                <button type="button"
                                    wire:click="executeMachinePowerAction('{{ $action->key }}')"
                                    @if ($confirmMessage) wire:confirm="{{ $confirmMessage }}" @endif
                                    @disabled($statusRunning && $action->key !== 'remote')
                                    class="{{ $isDangerous ? 'text-error' : '' }} {{ $statusRunning && $action->key !== 'remote' ? 'opacity-50 cursor-not-allowed' : '' }}">
                                    <i class="{{ $action->icon }}"></i>
                                    {{ $action->label }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </x-slot:actions>

    @if ($workstation)
        @php
            $deployment = $this->deploymentStatuses;
            $deploySuccess    = $deployment['success'];
            $deployErrors     = $deployment['errors'];
            $deployInProgress = $deployment['in_progress'];
            $deployFinished   = $deploySuccess->count() + $deployErrors->count();
            $deployRate       = $deployFinished > 0 ? round(($deploySuccess->count() / $deployFinished) * 100) : 0;
            $presence         = $workstation->agentPresence();
        @endphp

        <div class="space-y-6">

            {{-- Card header : identité + salle physique + warning --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    {{-- En-tête identité --}}
                    <div class="flex items-start gap-4 mb-6">
                            {{-- Pin d'état de présence (canal agent) sur l'icône poste --}}
                            <div class="indicator">
                                @if ($presence === 'online')
                                    <span class="indicator-item status status-success status-lg"
                                          title="Allumé — check-in {{ $workstation->agent_last_checkin_at->diffForHumans() }}"
                                          aria-label="Allumé"></span>
                                @elseif ($presence === 'reported_off')
                                    <span class="indicator-item status status-lg"
                                          title="Éteint — extinction signalée {{ $workstation->agent_reported_offline_at->diffForHumans() }}"
                                          aria-label="Éteint"></span>
                                @elseif ($presence === 'silent')
                                    <span class="indicator-item status status-warning status-lg"
                                          title="Injoignable — dernier check-in {{ $workstation->agent_last_checkin_at->diffForHumans() }}"
                                          aria-label="Injoignable"></span>
                                @endif
                                <div class="bg-primary/10 text-primary flex items-center justify-center rounded-xl w-16 h-16">
                                    <i class="fa-solid fa-computer text-2xl"></i>
                                </div>
                            </div>
                        <div class="flex-1">
                            <h2 class="text-2xl font-bold">{{ $workstation->name }}</h2>
                            <p class="text-base-content/60 mt-0.5">
                                <code class="bg-base-200 px-2 py-0.5 rounded">{{ $workstation->os }}</code>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($workstation->isProtected())
                                <span class="badge badge-lg badge-warning" title="Poste protégé">
                                    <i class="fa-solid fa-lock mr-1"></i> Protégé
                                </span>
                            @endif
                            @if ($deployErrors->isNotEmpty())
                                <span class="badge badge-lg badge-error">
                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                    {{ $deployErrors->count() }} échec{{ $deployErrors->count() > 1 ? 's' : '' }} de déploiement
                                </span>
                            @endif
                        </div>
                    </div>

                    {{-- Salle physique --}}
                    <div class="rounded-xl border border-base-200 p-4 mb-6">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-9 h-9 rounded-lg bg-warning/10 flex items-center justify-center">
                                <i class="fa-solid fa-door-open text-warning"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-xs uppercase tracking-wider text-base-content/50 font-semibold">
                                    Salle physique
                                </p>
                                <p class="text-xs text-base-content/40">
                                    Une machine ne peut appartenir qu'à une seule salle.
                                </p>
                            </div>
                        </div>

                        @if ($workstation->physicalRoom)
                            <div class="flex items-center gap-2 flex-wrap">
                                <a href="{{ route('app.parc.groups.show', $workstation->physicalRoom->id) }}"
                                    class="flex-1 min-w-[200px] flex items-center gap-2 px-3 py-2 rounded-lg bg-warning/5 border border-warning/25 hover:border-warning/60 hover:bg-warning/10 transition-colors font-semibold text-warning truncate">
                                    <i class="fa-solid fa-location-dot text-sm"></i>
                                    <span class="truncate">{{ $workstation->physicalRoom->name }}</span>
                                    <i class="fa-solid fa-arrow-up-right-from-square text-xs opacity-40 ml-auto"></i>
                                </a>
                                <button type="button"
                                    wire:click="$dispatch('open-workstation-group-selector', { drawerId: 'change-physical-room', groups: {{ $availablePhysicalRooms->filter(fn($r) => $r->id !== $workstation->physicalRoom?->id)->values()->toJson() }} })"
                                    class="btn btn-warning btn-sm gap-2">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                    Modifier
                                </button>
                                <button type="button"
                                    class="btn btn-ghost btn-sm btn-square text-base-content/40 hover:text-error"
                                    wire:click="removeFromPhysicalRoom"
                                    wire:confirm="Retirer ce poste de la salle physique ?"
                                    title="Retirer de la salle">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        @else
                            <button type="button"
                                wire:click="$dispatch('open-workstation-group-selector', { drawerId: 'assign-physical-room', groups: {{ $availablePhysicalRooms->toJson() }} })"
                                class="w-full flex items-center justify-center gap-2 py-2.5 rounded-lg border-2 border-dashed border-base-300 hover:border-warning hover:bg-warning/5 transition-all text-base-content/60 hover:text-warning font-medium disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:border-base-300 disabled:hover:bg-transparent disabled:hover:text-base-content/60"
                                @disabled($availablePhysicalRooms->isEmpty())>
                                <i class="fa-solid fa-plus"></i>
                                Assigner une salle
                            </button>
                        @endif
                    </div>

                    {{-- Grille infos techniques --}}
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <span class="text-xs text-base-content/60 uppercase tracking-wide">Système</span>
                            <p class="font-medium mt-0.5">{{ $workstation->os ?: '—' }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-base-content/60 uppercase tracking-wide">Adresse IP</span>
                            <p class="font-mono font-medium mt-0.5">{{ $workstation->ip ?: '—' }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-base-content/60 uppercase tracking-wide">Adresse MAC</span>
                            <p class="font-mono text-sm mt-0.5">{{ $workstation->mac ?: '—' }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-base-content/60 uppercase tracking-wide">Dernier rapport</span>
                            <p class="font-medium mt-0.5">
                                {{ $workstation->last_report_at?->format('d/m/Y H:i') ?? '—' }}
                            </p>
                        </div>
                    </div>

                    @if ($workstation->ad_guid)
                        <div class="mt-4">
                            <span class="text-xs text-base-content/60 uppercase tracking-wide">AD GUID</span>
                            <p class="font-mono text-xs bg-base-200 p-2 rounded mt-1 break-all">{{ $workstation->ad_guid }}</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Story 15.4 / Décision A — Onglets de premier niveau (général | wpkg).
                 Accès deep-link via ?tab=wpkg. La card header reste visible
                 dans les 2 modes (identité + statut sont communs). --}}
            <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit">
                <button type="button" role="tab"
                    class="tab {{ $tab === 'general' ? 'tab-active' : '' }}"
                    wire:click="setTab('general')">
                    <i class="fa-solid fa-circle-info mr-2"></i>
                    Général
                </button>
                <button type="button" role="tab"
                    class="tab {{ $tab === 'wpkg' ? 'tab-active' : '' }}"
                    wire:click="setTab('wpkg')">
                    <i class="fa-solid fa-cube mr-2"></i>
                    Applications WPKG
                </button>
                <button type="button" role="tab"
                    class="tab {{ $tab === 'agent' ? 'tab-active' : '' }}"
                    wire:click="setTab('agent')">
                    <i class="fa-solid fa-tower-broadcast mr-2"></i>
                    Agent
                </button>
            </div>

            @if ($tab === 'wpkg')
                @include('pages.parc.machines.[id]._partials.wpkg-assignment-tab')
            @elseif ($tab === 'agent')
            {{-- Onglet Agent — mode debug + canal agent (token, conformité) --}}

            {{-- Card mode debug du poste --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-bug {{ $workstation->debug ? 'text-warning' : 'text-base-content/40' }} text-lg mt-0.5"></i>
                            <div>
                                <h3 class="card-title text-base">
                                    Mode debug
                                    @if ($workstation->debug)
                                        <span class="badge badge-warning gap-1">
                                            <i class="fa-solid fa-circle-dot text-[0.6rem]"></i>
                                            Actif
                                        </span>
                                    @else
                                        <span class="badge badge-ghost">Inactif</span>
                                    @endif
                                </h3>
                                <p class="text-sm text-base-content/60 mt-1 max-w-xl">
                                    En debug, la console de l'agent reste ouverte sur le poste
                                    (quelle que soit la session ouverte) et y recopie ses logs en
                                    direct. Active aussi les logs WPKG détaillés
                                    (<code>debug</code> + <code>logdebug</code> du <code>.ini</code>).
                                </p>
                            </div>
                        </div>
                        @can('computer.control')
                            {{-- Modale réutilisable plutôt que wire:confirm : sur un
                                 checkbox, annuler wire:confirm bloque bien l'action
                                 serveur mais laisse le toggle basculer visuellement
                                 (livewire#8424). @click.prevent fige le visuel : seul
                                 le re-render post-confirmation le fait changer. --}}
                            @php
                                $debugConfirm = $workstation->debug
                                    ? ['message' => 'Désactiver le mode debug de ce poste ?', 'confirm' => 'Désactiver']
                                    : ['message' => 'Activer le mode debug de ce poste ? La console agent restera visible et les logs WPKG passeront en mode verbeux.', 'confirm' => 'Activer'];
                            @endphp
                            {{-- wire:key dépendante de l'état : le morph remplace le
                                 nœud au lieu de patcher l'attribut checked (que le
                                 navigateur ne resynchronise pas avec la propriété
                                 sur un checkbox déjà rendu). --}}
                            <input type="checkbox" class="toggle toggle-warning"
                                wire:key="debug-toggle-{{ $workstation->debug ? 'on' : 'off' }}"
                                x-data
                                @click.prevent="$dispatch('open-confirm-modal', {
                                    title: 'Mode debug',
                                    message: @js($debugConfirm['message']),
                                    confirmText: @js($debugConfirm['confirm']),
                                    cancelText: 'Annuler',
                                    variant: 'warning',
                                    method: 'toggleDebugMode',
                                    params: [],
                                    wireId: @js($this->getId()),
                                })"
                                @checked($workstation->debug) />
                        @else
                            <input type="checkbox" class="toggle toggle-warning" @checked($workstation->debug) disabled />
                        @endcan
                    </div>
                </div>
            </div>

            {{-- Story 23.2 / AC6 — Card canal agent (token desired-state, Epic 23) --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="card-title text-base">
                            <i class="fa-solid fa-tower-broadcast text-primary"></i>
                            Agent
                            @if ($workstation->isAgentQuarantined())
                                <span class="badge badge-error">
                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                    Quarantaine
                                </span>
                            @elseif ($workstation->isAgentEnrolled())
                                <span class="badge badge-success">Enrôlé</span>
                            @else
                                <span class="badge badge-ghost">Jamais enrôlé</span>
                            @endif
                        </h3>
                        @if ($workstation->isAgentEnrolled())
                            <button type="button" class="btn btn-error btn-outline btn-sm gap-2"
                                wire:click="revokeAgentToken"
                                wire:confirm="Révoquer le token agent de ce poste ? Le poste ne pourra plus appeler le canal agent tant qu'il n'aura pas été ré-enrôlé (réinstallation).">
                                <i class="fa-solid fa-ban"></i>
                                Révoquer le token agent
                            </button>
                        @endif
                    </div>

                    @if ($workstation->isAgentEnrolled())
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                            <div>
                                <span class="text-xs text-base-content/60 uppercase tracking-wide">Version de l'agent</span>
                                <p class="font-medium mt-0.5"
                                   @if ($workstation->agent_reported_version_at)
                                       title="Rapportée le {{ $workstation->agent_reported_version_at->format('d/m/Y H:i') }}"
                                   @endif>
                                    {{ $workstation->agent_reported_version ?? '—' }}
                                    @if ($workstation->agent_reported_version !== null
                                        && $workstation->agent_reported_version === $this->stableAgentVersion)
                                        <span class="badge badge-success badge-xs align-middle ml-1"
                                              title="Version stable publiée">Stable</span>
                                    @endif
                                </p>
                            </div>
                            <div>
                                <span class="text-xs text-base-content/60 uppercase tracking-wide">Dernière rotation du token</span>
                                <p class="font-medium mt-0.5">
                                    {{ $workstation->agent_token_rotated_at?->format('d/m/Y H:i') ?? '—' }}
                                </p>
                            </div>
                            <div>
                                <span class="text-xs text-base-content/60 uppercase tracking-wide">Dernier check-in</span>
                                <p class="font-medium mt-0.5">
                                    {{ $workstation->agent_last_checkin_at?->format('d/m/Y H:i') ?? '—' }}
                                </p>
                            </div>
                            @if ($workstation->isAgentQuarantined())
                                <div>
                                    <span class="text-xs text-base-content/60 uppercase tracking-wide">En quarantaine depuis</span>
                                    <p class="font-medium mt-0.5 text-error">
                                        {{ $workstation->agent_quarantined_at?->format('d/m/Y H:i') }}
                                    </p>
                                </div>
                            @endif
                        </div>
                    @else
                        <p class="text-sm text-base-content/60">
                            Ce poste n'a jamais été enrôlé sur le canal agent. Le token est émis
                            à l'installation du poste (enrôlement iPXE).
                        </p>
                    @endif

                    {{-- Story 24.7 — conformité par type + événements + forcer la synchro --}}
                    @include('pages.parc.machines.[id]._partials.agent-conformity')
                </div>
            </div>

            @else
            {{-- Card groupes logiques --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="card-title text-base">
                            <i class="fa-solid fa-layer-group text-primary"></i>
                            Groupes logiques
                            <span class="badge badge-ghost">{{ $workstation->logicalGroups->count() }}</span>
                        </h3>
                        @if ($availableLogicalGroups->isNotEmpty())
                            <button type="button"
                                wire:click="$dispatch('open-workstation-group-selector', { drawerId: 'add-logical-groups', groups: {{ $availableLogicalGroups->toJson() }} })"
                                class="btn btn-primary btn-sm gap-2">
                                <i class="fa-solid fa-plus"></i>
                                Ajouter
                            </button>
                        @endif
                    </div>

                    <p class="text-sm text-base-content/60 mb-4">
                        Une machine peut appartenir à plusieurs groupes logiques simultanément.
                    </p>

                    @if ($workstation->logicalGroups->isEmpty())
                        <div class="flex flex-col items-center justify-center py-8 text-center">
                            <div class="text-4xl mb-4 opacity-20">
                                <i class="fa-solid fa-folder-open"></i>
                            </div>
                            <h4 class="text-base font-semibold mb-2">Aucun groupe logique</h4>
                            <p class="text-base-content/60 text-sm max-w-sm">
                                Ce poste n'appartient à aucun groupe logique.
                            </p>
                        </div>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach ($workstation->logicalGroups as $group)
                                <div class="flex items-center gap-2 pl-3 pr-1 py-1 rounded-lg border border-base-300 bg-base-200/40">
                                    <i class="fa-solid fa-layer-group text-primary text-sm"></i>
                                    <a href="{{ route('app.parc.groups.show', $group->id) }}"
                                        class="font-medium text-sm hover:text-primary">
                                        {{ $group->name }}
                                    </a>
                                    <button type="button" class="btn btn-ghost btn-xs btn-square text-error"
                                        wire:click="removeFromLogicalGroup({{ $group->id }})"
                                        wire:confirm="Retirer ce poste du groupe logique ?">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Card déploiement --}}
            @if ($deploySuccess->isNotEmpty() || $deployErrors->isNotEmpty() || $deployInProgress->isNotEmpty())
                <div class="card bg-base-100 shadow-sm border border-base-200">
                    <div class="card-body">
                        <div class="flex items-center gap-4 mb-4">
                            <h3 class="card-title text-base">
                                <i class="fa-solid fa-chart-bar mr-2"></i>
                                Déploiement des applications
                            </h3>
                            @if ($deployFinished > 0)
                                <div class="flex items-center gap-2 max-w-[200px]">
                                    <progress
                                        class="progress progress-xs {{ $deployRate === 100 ? 'progress-success' : ($deployRate === 0 ? 'progress-error' : 'progress-warning') }} flex-1"
                                        value="{{ $deployRate }}" max="100"></progress>
                                    <span
                                        class="text-xs font-semibold whitespace-nowrap {{ $deployRate === 100 ? 'text-success' : ($deployRate === 0 ? 'text-error' : 'text-warning') }}">
                                        {{ $deploySuccess->count() }}/{{ $deployFinished }} ({{ $deployRate }}%)
                                    </span>
                                </div>
                            @endif
                        </div>

                        {{-- Onglets --}}
                        <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit mb-4 tabs-sm">
                            <button type="button" role="tab"
                                class="tab {{ $deploymentTab === 'success' ? 'tab-active' : '' }}"
                                aria-selected="{{ $deploymentTab === 'success' ? 'true' : 'false' }}"
                                wire:click="$set('deploymentTab', 'success')">
                                <i class="fa-solid fa-check mr-1 text-success"></i>
                                Succès
                                <span
                                    class="badge badge-xs ml-1 {{ $deploySuccess->isNotEmpty() ? 'badge-success' : 'badge-ghost' }}">{{ $deploySuccess->count() }}</span>
                            </button>
                            <button type="button" role="tab"
                                class="tab {{ $deploymentTab === 'errors' ? 'tab-active' : '' }}"
                                aria-selected="{{ $deploymentTab === 'errors' ? 'true' : 'false' }}"
                                wire:click="$set('deploymentTab', 'errors')">
                                <i class="fa-solid fa-xmark mr-1 text-error"></i>
                                Échecs
                                <span
                                    class="badge badge-xs ml-1 {{ $deployErrors->isNotEmpty() ? 'badge-error' : 'badge-ghost' }}">{{ $deployErrors->count() }}</span>
                            </button>
                            <button type="button" role="tab"
                                class="tab {{ $deploymentTab === 'in_progress' ? 'tab-active' : '' }}"
                                aria-selected="{{ $deploymentTab === 'in_progress' ? 'true' : 'false' }}"
                                wire:click="$set('deploymentTab', 'in_progress')">
                                <i class="fa-solid fa-rotate mr-1 text-info"></i>
                                En cours
                                <span
                                    class="badge badge-xs ml-1 {{ $deployInProgress->isNotEmpty() ? 'badge-info' : 'badge-ghost' }}">{{ $deployInProgress->count() }}</span>
                            </button>
                        </div>

                        {{-- Contenu onglets --}}
                        @php
                            $items = match ($deploymentTab) {
                                'success' => $deploySuccess,
                                'in_progress' => $deployInProgress,
                                default => $deployErrors,
                            };
                        @endphp
                        @if ($items->isEmpty())
                            <p class="text-base-content/50 text-sm py-4 text-center">Aucune application dans cette
                                catégorie.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="table table-sm">
                                    <thead>
                                        <tr class="bg-base-200">
                                            <th>Application</th>
                                            <th>Version installée</th>
                                            <th class="text-center">Statut</th>
                                            <th>Dernier rapport</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($items as $status)
                                            <tr class="hover">
                                                <td>
                                                    @if ($status->application)
                                                        <a href="{{ route('app.parc-settings.applications.show', $status->application->id) }}"
                                                            class="font-medium hover:underline">
                                                            {{ $status->application->name ?? $status->application->app_id }}
                                                        </a>
                                                        <div class="text-xs text-base-content/50 font-mono">
                                                            {{ $status->application->app_id }}</div>
                                                    @else
                                                        <div class="font-medium">—</div>
                                                    @endif
                                                </td>
                                                <td class="font-mono text-sm">{{ $status->installed_version ?: '—' }}
                                                </td>
                                                <td class="text-center">
                                                    @if ($status->status === 'installed')
                                                        <span class="badge badge-success badge-sm">Installé</span>
                                                    @elseif ($status->status === 'upgrading')
                                                        <span class="badge badge-info badge-sm">
                                                            <span class="loading loading-spinner loading-xs mr-1"></span>
                                                            Mise à jour
                                                        </span>
                                                    @elseif ($status->status === 'downgrading')
                                                        <span class="badge badge-info badge-sm">
                                                            <span class="loading loading-spinner loading-xs mr-1"></span>
                                                            Rétrogradation
                                                        </span>
                                                    @elseif ($status->status === 'error')
                                                        <button type="button"
                                                            class="badge badge-error badge-sm cursor-pointer hover:badge-outline"
                                                            wire:click="$dispatch('open-install-log-modal', { statusId: {{ $status->id }} })">
                                                            Erreur ↗
                                                        </button>
                                                    @else
                                                        <button type="button"
                                                            class="badge badge-warning badge-sm cursor-pointer hover:badge-outline"
                                                            wire:click="$dispatch('open-install-log-modal', { statusId: {{ $status->id }} })">
                                                            Non installé ↗
                                                        </button>
                                                    @endif
                                                </td>
                                                <td class="text-sm text-base-content/60">
                                                    {{ $status->reported_at?->format('d/m/Y H:i') ?? '—' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
            @endif {{-- /tab === wpkg --}}

        </div>{{-- /space-y-6 --}}

        {{-- Story 15.4 — Modales WPKG (toujours rendues si flag actif) --}}
        @if ($showAttachWpkgProfileModal)
            <x-organisms.wpkg.attach-profiles-modal
                title="Ajouter des profils applicatifs au poste"
                :items="$this->availableWpkgProfiles"
                searchProperty="wpkgProfileSearch"
                selectionProperty="selectedWpkgProfileIdsToAdd"
                :searchValue="$wpkgProfileSearch"
                closeMethod="closeAttachWpkgProfileModal"
                confirmMethod="attachWpkgProfiles"
                :selectionCount="count($selectedWpkgProfileIdsToAdd)"
                keyPrefix="ws-wpkg-profile" />
        @endif
        @if ($showAttachWpkgAppModal)
            <x-organisms.wpkg.attach-apps-modal
                title="Ajouter des applications directement au poste"
                :items="$this->availableWpkgApplications"
                searchProperty="wpkgAppSearch"
                selectionProperty="selectedWpkgAppIdsToAdd"
                :searchValue="$wpkgAppSearch"
                closeMethod="closeAttachWpkgAppModal"
                confirmMethod="attachWpkgApplications"
                :selectionCount="count($selectedWpkgAppIdsToAdd)"
                keyPrefix="ws-wpkg-app"
                context="workstation" />
        @endif

        <!-- Drawer pour sélection de salle physique (unique) -->
        <livewire:components::organisms.workstation-group-selector drawerId="assign-physical-room" :unique="true"
            title="Assigner une salle physique" buttonLabel="Assigner" buttonIcon="fa-plus" buttonClass="btn-warning"
            :showTypeLabel="false" emptyMessage="Aucune salle physique disponible" />

        <!-- Drawer pour changement de salle physique (unique) -->
        <livewire:components::organisms.workstation-group-selector drawerId="change-physical-room" :unique="true"
            title="Changer de salle physique" buttonLabel="Changer" buttonIcon="fa-arrows-rotate"
            buttonClass="btn-warning" :showTypeLabel="false" emptyMessage="Aucune autre salle disponible" />

        <!-- Drawer pour ajout aux groupes logiques (multiple) -->
        <livewire:components::organisms.workstation-group-selector drawerId="add-logical-groups" :unique="false"
            title="Ajouter aux groupes logiques" buttonLabel="Ajouter" buttonIcon="fa-plus" buttonClass="btn-primary"
            :showTypeLabel="false" emptyMessage="Aucun groupe logique disponible" />

        <!-- Modale log d'installation WPKG (partagée) -->
        <livewire:components::organisms.install-log-modal />
    @else
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body flex flex-col items-center justify-center py-16">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-computer"></i>
                </div>
                <h3 class="text-xl font-semibold mb-3">Poste non trouvé</h3>
                <p class="text-base-content/60 mb-6">
                    Le poste demandé n'existe pas ou a été supprimé.
                </p>
                <a href="{{ route('app.parc.index') }}" class="btn btn-primary">
                    <i class="fa-solid fa-arrow-left"></i>
                    Retour à la liste
                </a>
            </div>
        </div>
    @endif

</x-organisms.page>
