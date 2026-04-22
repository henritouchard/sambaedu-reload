<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Services\Parc\WorkstationGroupService;
use App\Services\Parc\WorkstationGroupScheduleService;
use App\Services\Parc\MachinePowerService;
use App\Models\MachinePowerActionTask;
use App\Models\WorkstationGroup;
use App\Models\WorkstationGroupSchedule;
use App\Components\Traits\WithToasts;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

new #[Title('Détail du Groupe - SE4FS')] class extends Component {
    use WithToasts;

    private WorkstationGroupService $parcService;
    private MachinePowerService $machinePowerService;
    private WorkstationGroupScheduleService $scheduleService;

    public ?WorkstationGroup $group = null;
    public string|int $id;
    public array $selectedMachines = [];
    public bool $showAddMachinesModal = false;
    public array $selectedGroupMachineIds = [];
    public bool $allGroupMachinesSelected = false;

    // ── État modale Programmations (story 4-4) ─────────────────────────────
    public bool $scheduleModalOpen = false;
    public ?int $editingScheduleId = null;
    public string $formMode = 'recurring'; // 'recurring' | 'one_shot' (D7)
    public string $formAction = 'wake'; // D5 : wake | shutdown
    /** @var array<int, int> */
    public array $formDaysOfWeek = [];
    public string $formTimeOfDay = '08:00';
    public string $formTimezone = 'Europe/Paris';
    public ?string $formRunAtDate = null; // one-shot date (Y-m-d)
    public string $formRunAtTime = '08:00'; // one-shot time (H:i)
    public bool $formEnabled = true;

    // ── État batch async (story 4-3) ───────────────────────────────────────
    // Ces propriétés pilotent le polling Livewire `wire:poll.{N}s` de la
    // vue groupe. Tant que $batchRunning est true, un unique `wire:poll`
    // est rendu et appelle pollGroupReadiness() à l'intervalle configuré.
    // Dès que toutes les tasks du batch courant sont terminales (completed
    // ou failed, ou timeoutées), $batchRunning repasse à false et Livewire
    // cesse d'interroger le serveur (le poll n'est plus rendu).
    public bool $batchRunning = false;
    public ?string $batchAction = null; // libellé FR humanisé ("extinction", "redémarrage", etc.)
    // Clé action brute ("wake" / "shutdown" / etc.) — conservée pour les tests Feature
    // et un futur filtrage/affichage granulaire côté UI (icône par action dans le badge).
    public ?string $batchActionKey = null;
    public ?string $batchStartedAt = null; // ISO 8601
    /** @var array<int> */
    public array $currentBatchTaskIds = [];
    public bool $batchSummaryVisible = false;
    public bool $batchTimeoutFired = false;
    public bool $showWallpaperModal = false;

    // Mémoïsation interne des tasks du batch courant — partagée entre les deux
    // propriétés computed (batchSummary + machineActiveTasksById) pour éviter
    // un double SELECT par cycle de rendu. Réinitialisée en début de poll tick.
    private ?Collection $cachedBatchTasks = null;

    public function boot(WorkstationGroupService $parcService, MachinePowerService $machinePowerService, WorkstationGroupScheduleService $scheduleService): void
    {
        $this->parcService = $parcService;
        $this->machinePowerService = $machinePowerService;
        $this->scheduleService = $scheduleService;
    }

    public function mount(string|int $id): void
    {
        $this->id = (int) $id;
        $this->loadGroup();

        if (session()->has('toast')) {
            $toastData = session('toast');
            $this->toast($toastData['type'] ?? 'info', $toastData['title'] ?? 'Notification', $toastData['message'] ?? '');
        }
    }

    public function loadGroup(): void
    {
        try {
            $this->group = $this->parcService->getGroup($this->id);

            $this->syncGroupMachinesSelectionState();

            if (!$this->group) {
                session()->flash('toast', [
                    'type' => 'error',
                    'title' => 'Erreur',
                    'message' => 'Groupe non trouvé',
                ]);
                $this->redirect(route('app.parc.index'));
            }
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur chargement: ' . $e->getMessage());
            $this->toastError('Erreur lors du chargement du groupe');
        }
    }

    public function openAddMachinesModal(): void
    {
        $this->selectedMachines = [];
        $this->showAddMachinesModal = true;
    }

    public function closeAddMachinesModal(): void
    {
        $this->showAddMachinesModal = false;
        $this->selectedMachines = [];
    }

    public function addMachines(): void
    {
        if (empty($this->selectedMachines)) {
            $this->toastError('Aucune machine sélectionnée');
            return;
        }

        try {
            $count = $this->parcService->bulkAddMachinesToGroup($this->selectedMachines, $this->id);
            $this->toastSuccess("{$count} machine(s) ajoutée(s) au groupe");
            $this->showAddMachinesModal = false;
            $this->selectedMachines = [];
            $this->loadGroup();
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur ajout machines: ' . $e->getMessage());
            $this->toastError('Erreur lors de l\'ajout des machines');
        }
    }

    public function removeMachine(int $machineId): void
    {
        try {
            $this->parcService->removeMachineFromGroup($machineId, $this->id);
            $this->toastSuccess('Machine retirée du groupe');
            $this->loadGroup();
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur retrait machine: ' . $e->getMessage());
            $this->toastError('Erreur lors du retrait de la machine');
        }
    }

    public function selectAllGroupMachines(): void
    {
        if (!$this->group) {
            return;
        }

        $this->selectedGroupMachineIds = $this->group->workstations->pluck('id')->map(static fn(mixed $id): int => (int) $id)->values()->all();
        $this->allGroupMachinesSelected = true;
    }

    public function clearSelectedGroupMachines(): void
    {
        $this->selectedGroupMachineIds = [];
        $this->allGroupMachinesSelected = false;
    }

    public function updatedSelectedGroupMachineIds(): void
    {
        $this->selectedGroupMachineIds = array_values(array_unique(array_map('intval', $this->selectedGroupMachineIds)));

        if (!$this->group) {
            $this->allGroupMachinesSelected = false;
            return;
        }

        $totalMachines = $this->group->workstations->count();
        $this->allGroupMachinesSelected = $totalMachines > 0 && count($this->selectedGroupMachineIds) === $totalMachines;
    }

    public function updatedAllGroupMachinesSelected(bool $isSelected): void
    {
        if ($isSelected) {
            $this->selectAllGroupMachines();
            return;
        }

        $this->clearSelectedGroupMachines();
    }

    public function toggleGroupMachineSelection(int $machineId): void
    {
        if (in_array($machineId, $this->selectedGroupMachineIds, true)) {
            $this->selectedGroupMachineIds = array_values(array_diff($this->selectedGroupMachineIds, [$machineId]));
            return;
        }

        $this->selectedGroupMachineIds[] = $machineId;
    }

    private function syncGroupMachinesSelectionState(): void
    {
        if (!$this->group) {
            $this->selectedGroupMachineIds = [];
            $this->allGroupMachinesSelected = false;
            return;
        }

        $availableMachineIds = $this->group->workstations->pluck('id')->map(static fn(mixed $id): int => (int) $id)->values()->all();

        $this->selectedGroupMachineIds = array_values(array_map('intval', array_intersect($this->selectedGroupMachineIds, $availableMachineIds)));

        $totalMachines = count($availableMachineIds);
        $this->allGroupMachinesSelected = $totalMachines > 0 && count($this->selectedGroupMachineIds) === $totalMachines;
    }

    /**
     * Story 4-3 — dispatch batch async.
     *
     * Flow (AC2/AC6/AC7) :
     *  1. Guard gate Spatie `computer.control` + guard $batchRunning (double-dispatch).
     *  2. Sélection non vide + action ≠ 'remote' (remote exclu du dropdown batch).
     *  3. Appel du service qui crée 1 MachinePowerActionTask + 1 job par machine
     *     éligible (idempotence = filtre tasks actives côté service).
     *  4. Flip état batch (batchRunning / batchAction / currentBatchTaskIds…)
     *     → trigge le rendu du wire:poll qui appelle pollGroupReadiness.
     *  5. Toast résumé dispatch (success / warning si idempotence / error si 0 dispatché).
     */
    public function executeSelectedGroupMachinesAction(string $action): void
    {
        // Guard serveur-side : cochée même si @can masque côté Blade (AC11).
        if (!Gate::allows('computer.control')) {
            $this->toastAccessDenied();
            return;
        }

        if ($this->batchRunning) {
            $this->toastWarning('Un batch est déjà en cours sur ce groupe. Attendez la fin de l\'opération courante.');
            return;
        }

        // remote n'est pas batchable (token par machine, AC6) : l'UI n'expose
        // pas l'entrée mais on garde un garde-fou serveur.
        if ($action === 'remote') {
            $this->toastError('L\'accès distant n\'est pas disponible en action batch. Utilisez le dropdown unitaire.');
            return;
        }

        if (empty($this->selectedGroupMachineIds)) {
            $this->toastError('Aucune machine sélectionnée');
            return;
        }

        try {
            $result = $this->parcService->executeGroupMachinesAction($this->id, $this->selectedGroupMachineIds, $action);
            $actionLabel = $this->parcService->getMachineActionLabel($action);

            if ($result['requested_count'] === 0) {
                $this->toastWarning('Aucune machine valide à traiter dans ce groupe');
                return;
            }

            // Récupérer les task_id dispatchés (success=true) pour nourrir le polling.
            $dispatchedTaskIds = [];
            foreach ($result['results'] as $row) {
                if (!empty($row['success']) && !empty($row['task_id'])) {
                    $dispatchedTaskIds[] = (int) $row['task_id'];
                }
            }

            // Compter les machines skippées pour cause d'idempotence (code=409).
            $alreadyRunningCount = 0;
            foreach ($result['results'] as $row) {
                if (($row['code'] ?? null) === 409) {
                    $alreadyRunningCount++;
                }
            }

            $dispatched = $result['success_count'];

            // Cas 1 : aucun dispatch (100% idempotence ou introuvables).
            if ($dispatched === 0) {
                if ($alreadyRunningCount > 0) {
                    $this->toastWarning("Toutes les machines sélectionnées ont déjà une action en cours ({$alreadyRunningCount}).");
                } else {
                    $this->toastError("Échec de l'action de {$actionLabel} : aucune machine n'a pu être dispatchée.");
                }
                return;
            }

            // Garde contrat service : si success_count > 0 mais aucun task_id ne
            // remonte, le polling n'aurait aucune cible → refuser de basculer en
            // batchRunning pour éviter un état incohérent silencieux.
            if (empty($dispatchedTaskIds)) {
                Log::error('[GroupShow] Contrat service rompu : success_count > 0 sans task_id dans results', [
                    'group_id' => $this->id,
                    'action' => $action,
                    'success_count' => $dispatched,
                    'results' => $result['results'],
                ]);
                $this->toastError("Erreur interne — l'action ne peut pas être suivie.");
                return;
            }

            // Basculer UI en état batch en cours — déclenche le wire:poll.
            $this->batchRunning = true;
            $this->batchAction = $actionLabel;
            $this->batchActionKey = $action;
            $this->batchStartedAt = now()->toIso8601String();
            $this->currentBatchTaskIds = $dispatchedTaskIds;
            $this->batchSummaryVisible = true;
            $this->batchTimeoutFired = false;

            // Toast — success si tout a dispatché, warning si mixte (idempotence).
            if ($alreadyRunningCount > 0) {
                $this->toastWarning("Action de {$actionLabel} lancée sur {$dispatched} machine(s) — {$alreadyRunningCount} déjà en cours, ignorée(s).");
            } else {
                $this->toastSuccess("Action de {$actionLabel} lancée sur {$dispatched} machine(s)");
            }

            // Reset de la sélection : parité avec l'ancien comportement, l'opérateur
            // peut scanner la table pour suivre les badges par ligne.
            $this->selectedGroupMachineIds = [];
            $this->allGroupMachinesSelected = false;
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur action groupée machines: ' . $e->getMessage(), [
                'group_id' => $this->id,
                'action' => $action,
                'machines' => $this->selectedGroupMachineIds,
            ]);
            $this->toastError('Erreur lors de l\'exécution de l\'action');
        }
    }

    /**
     * Story 4-3 — action unitaire depuis la vue groupe, async (parité vue machine 4-2).
     *
     * Respecte l'idempotence : si une task est déjà active sur la machine, le
     * service retourne code=409 → on convertit en toast warning sans échouer.
     */
    public function executeMachineAction(int $machineId, string $action): void
    {
        if (!Gate::allows('computer.control')) {
            $this->toastAccessDenied();
            return;
        }

        // Action unitaire async par défaut. On rebâtit le flow pour gérer les
        // réponses structurées (409 / 404 / 202) sans dupliquer la logique
        // avec le batch.
        try {
            // L'accès distant reste synchrone (contrat D5 story 4-3 / AC6).
            if ($action === 'remote') {
                $result = $this->parcService->executeGroupMachinesAction($this->id, [$machineId], 'remote');
                $this->handleRemoteAccessResult($result);
                return;
            }

            $result = $this->parcService->executeGroupMachinesAction($this->id, [$machineId], $action);
            $actionLabel = $this->parcService->getMachineActionLabel($action);

            if ($result['requested_count'] === 0) {
                $this->toastWarning('Machine introuvable dans ce groupe');
                return;
            }

            $row = $result['results'][0] ?? null;
            $code = $row['code'] ?? null;

            if ($code === 409) {
                $this->toastWarning('Une action est déjà en cours sur cette machine.');
                return;
            }

            if ($code === 404) {
                $this->toastWarning('Machine introuvable dans ce groupe');
                return;
            }

            if ($result['failed_count'] === 0 && $result['success_count'] === 1) {
                $this->toastSuccess("Action de {$actionLabel} lancée sur la machine");
                return;
            }

            $this->toastError("Échec de l'action de {$actionLabel} sur la machine");
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur action machine: ' . $e->getMessage(), [
                'group_id' => $this->id,
                'machine_id' => $machineId,
                'action' => $action,
            ]);
            $this->toastError('Erreur lors de l\'exécution de l\'action machine');
        }
    }

    /**
     * Redirection en cas d'accès distant (génération de token Guacamole).
     */
    private function handleRemoteAccessResult(array $result): void
    {
        if (($result['failed_count'] ?? 0) === 0 && ($result['success_count'] ?? 0) > 0) {
            $remoteUrl = $result['results'][0]['url'] ?? null;
            if ($remoteUrl) {
                $this->redirect($remoteUrl);
                return;
            }
            $this->toastError('URL de connexion non générée');
            return;
        }

        $this->toastError('Échec de la génération de la connexion à distance');
    }

    /**
     * Polling Livewire (AC3/AC4/AC5 story 4-3).
     *
     * Appelé par `wire:poll.{N}s` sur la vue tant que $batchRunning est true.
     *
     * Algorithme (inspiré de pollMachineReadiness de la vue machine 4-2) :
     *  1. Guard rapide : no-op si pas de batch en cours.
     *  2. Timeout global : si elapsed ≥ config('parc.machine_readiness_timeout_seconds'),
     *     marquer toutes les tasks encore actives de ce batch comme failed,
     *     appeler logReadinessTimeout() pour chaque machine, toast warning unique.
     *  3. Un SEUL SELECT sur machine_power_action_tasks du batch courant
     *     (with('workstation') pour éviter les N+1 quand on résout la phase restart).
     *  4. Pour chaque task active, appliquer la logique per-action :
     *       wake                      → ping true → completed
     *       shutdown | shutdown-force → ping false → completed
     *       restart (waiting-down)    → ping false → transition waiting-up
     *       restart (waiting-up)      → ping true → completed
     *  5. Si plus aucune task active pour le batch → stopBatchPolling().
     *
     * Invariant (review #8/#12) : on ne ping QUE les tasks en STATUS_RUNNING.
     * Les tasks queued/dispatched sont skippées : tant que le worker n'a pas
     * pris la task (et donc pas envoyé la commande shell), pinger est inutile
     * et coûteux (ping série bloquant — 50 machines × 1.4s = ~70s sur un tick
     * Livewire). Exige en prod `QUEUE_CONNECTION=database` + `php artisan queue:work`.
     */
    public function pollGroupReadiness(): void
    {
        if (!$this->batchRunning || empty($this->currentBatchTaskIds)) {
            return;
        }

        // Reset du cache partagé entre computed properties — le tick polling
        // doit repartir de rows fraîches.
        $this->cachedBatchTasks = null;

        $startedAt = $this->batchStartedAt ? Carbon::parse($this->batchStartedAt) : null;
        if (!$startedAt) {
            $this->stopBatchPolling();
            return;
        }

        $elapsed = (int) now()->diffInSeconds($startedAt, true);
        $timeout = (int) config('parc.machine_readiness_timeout_seconds', 120);

        // (a) Timeout global — on court-circuite le ping avant de chercher les tasks
        //     pour éviter tout appel réseau inutile.
        if ($elapsed >= $timeout) {
            $this->handleBatchTimeout($timeout);
            return;
        }

        // (b) Single SELECT with relation — pas de N+1.
        $tasks = $this->loadCurrentBatchTasks();

        if ($tasks->isEmpty()) {
            // Tasks rehydratées vides (table purgée / corruption) : on coupe proprement.
            $this->stopBatchPolling();
            return;
        }

        foreach ($tasks as $task) {
            if ($task->isTerminal()) {
                continue;
            }

            // Skip les tasks queued/dispatched : le worker n'a pas encore lancé
            // la commande, pinger n'a aucun sens et bloquerait le tick.
            if ($task->status !== MachinePowerActionTask::STATUS_RUNNING) {
                continue;
            }

            $this->resolveTaskReadiness($task);
        }

        // (c) Si plus aucune task active → couper le poll. On recalcule depuis
        //     la collection en mémoire (évite un second SELECT redondant).
        $stillActive = $tasks->filter(fn(MachinePowerActionTask $t) => $t->isActive())->count();

        if ($stillActive === 0) {
            $this->stopBatchPolling();
        }
    }

    /**
     * Charge (et mémoïse pour le cycle courant) les tasks du batch avec la
     * relation workstation — appelé par pollGroupReadiness + les deux
     * propriétés computed pour éviter un double SELECT par rendu.
     */
    private function loadCurrentBatchTasks(): Collection
    {
        if ($this->cachedBatchTasks !== null) {
            return $this->cachedBatchTasks;
        }

        if (empty($this->currentBatchTaskIds)) {
            return $this->cachedBatchTasks = collect();
        }

        return $this->cachedBatchTasks = MachinePowerActionTask::query()->whereIn('id', $this->currentBatchTaskIds)->with('workstation')->get();
    }

    /**
     * Applique la machine à états readiness pour une task donnée.
     *
     * Helper privé factoré pour garder pollGroupReadiness() lisible.
     */
    private function resolveTaskReadiness(MachinePowerActionTask $task): void
    {
        $workstation = $task->workstation;
        if (!$workstation) {
            // Workstation supprimée entre-temps — marquer la task failed pour
            // éviter qu'elle reste coincée en running.
            $task->update([
                'status' => MachinePowerActionTask::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => 'Machine introuvable (supprimée pendant le batch)',
            ]);
            return;
        }

        $ip = (string) ($workstation->ip ?? $workstation->name);
        $ping = $this->machinePowerService->ping($ip);

        if ($task->action === 'restart') {
            if ($task->restart_phase === MachinePowerActionTask::RESTART_PHASE_WAITING_DOWN) {
                if ($ping === false) {
                    $task->update(['restart_phase' => MachinePowerActionTask::RESTART_PHASE_WAITING_UP]);
                }
                return; // on continue à poller, la machine est en train de rebooter
            }

            if ($task->restart_phase === MachinePowerActionTask::RESTART_PHASE_WAITING_UP) {
                if ($ping !== false) {
                    $task->update([
                        'status' => MachinePowerActionTask::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ]);
                }
                return;
            }

            // Phase inconnue — fail-safe.
            $task->update([
                'status' => MachinePowerActionTask::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => 'Phase de restart invalide',
            ]);
            return;
        }

        // wake / shutdown / shutdown-force — readiness par ping simple.
        $isResolved = match ($task->action) {
            'wake' => $ping !== false,
            'shutdown', 'shutdown-force' => $ping === false,
            default => false,
        };

        if ($isResolved) {
            $task->update([
                'status' => MachinePowerActionTask::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * Marque toutes les tasks encore actives du batch comme failed + toast warning unique.
     */
    private function handleBatchTimeout(int $timeout): void
    {
        if ($this->batchTimeoutFired) {
            // Une fois le timeout toasté, on laisse $batchRunning à false pour ne
            // pas rerender le wire:poll.
            $this->stopBatchPolling();
            return;
        }

        $activeTasks = MachinePowerActionTask::query()->whereIn('id', $this->currentBatchTaskIds)->whereIn('status', MachinePowerActionTask::ACTIVE_STATUSES)->with('workstation')->get();

        $timedOutCount = $activeTasks->count();

        foreach ($activeTasks as $task) {
            $machineName = (string) ($task->workstation?->name ?? "id:{$task->workstation_id}");

            $task->update([
                'status' => MachinePowerActionTask::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => "Readiness timeout ({$timeout}s)",
            ]);

            try {
                $this->machinePowerService->logReadinessTimeout($machineName, (string) $task->action);
            } catch (\Throwable $e) {
                Log::warning('[GroupShow] logReadinessTimeout failed pour batch', [
                    'machine' => $machineName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->batchTimeoutFired = true;

        if ($timedOutCount > 0) {
            $this->toastWarning("Batch terminé avec timeout sur {$timedOutCount} machine(s) après {$timeout}s");
        }

        $this->stopBatchPolling();
    }

    /**
     * Stoppe le polling : désactive le rendu du wire:poll côté Blade.
     *
     * Note : on NE touche PAS à $currentBatchTaskIds ni à $batchSummaryVisible ;
     * l'encart résumé doit rester affiché jusqu'au clic "Effacer".
     */
    private function stopBatchPolling(): void
    {
        $this->batchRunning = false;
    }

    /**
     * Résumé du batch courant (AC4) — recalculé à chaque rendu.
     *
     * Compteurs + liste nominative des échecs. Requête unique avec eager-loading
     * pour éviter les N+1. Utilisé par le partial `_partials/batch-summary.blade.php`.
     *
     * @return array{
     *   action: ?string,
     *   total: int,
     *   success: int,
     *   failed: int,
     *   running: int,
     *   failures: array<int, array{machine_name: string, error_message: string}>
     * }
     */
    public function getBatchSummaryProperty(): array
    {
        if (empty($this->currentBatchTaskIds)) {
            return [
                'action' => $this->batchAction,
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'running' => 0,
                'failures' => [],
            ];
        }

        $tasks = $this->loadCurrentBatchTasks();

        $success = 0;
        $failed = 0;
        $running = 0;
        $failures = [];

        foreach ($tasks as $task) {
            if ($task->status === MachinePowerActionTask::STATUS_COMPLETED) {
                $success++;
            } elseif ($task->status === MachinePowerActionTask::STATUS_FAILED) {
                $failed++;
                $failures[] = [
                    'machine_name' => (string) ($task->workstation?->name ?? "id:{$task->workstation_id}"),
                    'error_message' => (string) ($task->error_message ?? 'Échec inconnu'),
                ];
            } else {
                $running++;
            }
        }

        return [
            'action' => $this->batchAction,
            'total' => $tasks->count(),
            'success' => $success,
            'failed' => $failed,
            'running' => $running,
            'failures' => $failures,
        ];
    }

    /**
     * Tasks du batch courant indexées par workstation_id — consommé par le
     * partial `_partials/machines-list.blade.php` pour afficher le badge d'état
     * de chaque ligne. Partage la collection memoïsée avec getBatchSummaryProperty
     * pour éviter un double SELECT par cycle de rendu.
     *
     * Note (review #15 — en attente) : ne couvre PAS les tasks actives hors
     * batch courant (autre opérateur). Extension possible dans une story UX
     * multi-opérateur dédiée.
     *
     * @return array<int, MachinePowerActionTask>
     */
    public function getMachineActiveTasksByIdProperty(): array
    {
        if (empty($this->currentBatchTaskIds)) {
            return [];
        }

        $tasks = $this->loadCurrentBatchTasks();

        $indexed = [];
        foreach ($tasks as $task) {
            if ($task->workstation_id === null) {
                continue;
            }
            $indexed[(int) $task->workstation_id] = $task;
        }

        return $indexed;
    }

    /**
     * True si la machine a une task active dans le batch courant (pour @disabled
     * sur les boutons d'action unitaire de cette ligne).
     */
    public function isMachineActionActive(int $machineId): bool
    {
        $task = $this->machineActiveTasksById[$machineId] ?? null;
        if (!$task) {
            return false;
        }
        return in_array($task->status, MachinePowerActionTask::ACTIVE_STATUSES, true);
    }

    /**
     * Ferme l'encart résumé (AC4 — bouton "Effacer").
     * Les rows machine_power_action_tasks restent en DB pour l'audit.
     */
    public function clearBatchSummary(): void
    {
        $this->currentBatchTaskIds = [];
        $this->batchSummaryVisible = false;
        $this->batchAction = null;
        $this->batchActionKey = null;
        $this->batchStartedAt = null;
        $this->batchTimeoutFired = false;
        // batchRunning est forcément false à ce stade (stopBatchPolling() a été appelé),
        // mais on le force explicitement par sécurité (prog défensive).
        $this->batchRunning = false;
    }

    public function getMachineActionsProperty(): Collection
    {
        return collect($this->parcService->getAvailableMachineActions())
            ->map(static fn(array $action): object => (object) $action)
            ->values();
    }

    /**
     * Actions exposées dans le dropdown BATCH (AC6) : toutes sauf `remote`.
     * Le dropdown unitaire par ligne continue d'exposer les 5 actions.
     */
    public function getBatchMachineActionsProperty(): Collection
    {
        return $this->machineActions->reject(static fn(object $action): bool => $action->key === 'remote')->values();
    }

    public function executeGroupAction(string $action): void
    {
        if (!$this->group) {
            $this->toastError('Groupe non trouvé');
            return;
        }

        $this->selectedGroupMachineIds = $this->group->workstations->pluck('id')->map(static fn(mixed $id): int => (int) $id)->values()->all();

        $this->executeSelectedGroupMachinesAction($action);
    }

    public function openWallpaperModal(): void
    {
        $this->showWallpaperModal = true;
    }

    public function closeWallpaperModal(): void
    {
        $this->showWallpaperModal = false;
    }

    // ========================================
    // Story 4-4 — Programmations (crons)
    // ========================================

    /**
     * Computed : liste des schedules du groupe avec tri AC24.
     *
     * Ordre : récurrents actifs d'abord (par heure), puis one-shots futurs
     * (par run_at asc), puis one-shots terminés (par completed_at desc).
     */
    public function getSchedulesProperty(): Collection
    {
        if (!$this->group) {
            return collect();
        }

        return WorkstationGroupSchedule::query()->where('workstation_group_id', $this->id)->orderByRaw("CASE WHEN completed_at IS NOT NULL THEN 2 WHEN mode = 'one_shot' THEN 1 ELSE 0 END")->orderByRaw('time_of_day ASC NULLS LAST')->orderByRaw('run_at ASC NULLS LAST')->orderByDesc('completed_at')->get();
    }

    public function openScheduleModal(?int $scheduleId = null): void
    {
        if (!Gate::allows('computer.control')) {
            $this->toastError('Accès refusé');
            return;
        }

        if ($scheduleId !== null) {
            $schedule = WorkstationGroupSchedule::find($scheduleId);
            if (!$schedule || $schedule->workstation_group_id !== (int) $this->id) {
                $this->toastError('Programmation introuvable');
                return;
            }
            if (!$schedule->isEditable()) {
                $this->toastError('Cette programmation est terminée et ne peut plus être modifiée.');
                return;
            }
            $this->editingScheduleId = $schedule->id;
            $this->formMode = $schedule->mode;
            $this->formAction = $schedule->action;
            $this->formDaysOfWeek = $schedule->days_of_week ?? [];
            $this->formTimeOfDay = $schedule->time_of_day?->format('H:i') ?? '08:00';
            $this->formTimezone = $schedule->timezone ?? 'Europe/Paris';
            $this->formRunAtDate = $schedule->run_at?->format('d/m/Y');
            $this->formRunAtTime = $schedule->run_at?->format('H:i') ?? '08:00';
            $this->formEnabled = $schedule->enabled;
        } else {
            $this->resetScheduleForm();
        }

        $this->scheduleModalOpen = true;
    }

    public function closeScheduleModal(): void
    {
        $this->scheduleModalOpen = false;
        $this->resetScheduleForm();
    }

    private function resetScheduleForm(): void
    {
        $this->editingScheduleId = null;
        $this->formMode = 'recurring';
        $this->formAction = 'wake';
        $this->formDaysOfWeek = [];
        $this->formTimeOfDay = '08:00';
        $this->formTimezone = 'Europe/Paris';
        $this->formRunAtDate = null;
        $this->formRunAtTime = '08:00';
        $this->formEnabled = true;
    }

    public function toggleDay(int $day): void
    {
        if (in_array($day, $this->formDaysOfWeek, true)) {
            $this->formDaysOfWeek = array_values(array_diff($this->formDaysOfWeek, [$day]));
        } else {
            $this->formDaysOfWeek[] = $day;
        }
    }

    public function toggleFormMode(string $mode): void
    {
        if (!in_array($mode, ['recurring', 'one_shot'], true)) {
            return;
        }
        $this->formMode = $mode;

        if ($mode === 'recurring') {
            $this->formRunAtDate = null;
            $this->formRunAtTime = '08:00';
            if (empty($this->formDaysOfWeek)) {
                $this->formDaysOfWeek = [];
            }
        } else {
            $this->formDaysOfWeek = [];
            $this->formTimeOfDay = '08:00';
            $this->formTimezone = 'Europe/Paris';
        }
    }

    public function saveSchedule(): void
    {
        if (!Gate::allows('computer.control')) {
            $this->toastError('Accès refusé');
            return;
        }

        try {
            if ($this->formMode === 'recurring') {
                $this->validate(
                    [
                        'formDaysOfWeek' => ['required', 'array', 'min:1', 'max:7'],
                        'formDaysOfWeek.*' => ['integer', 'between:1,7'],
                        'formTimeOfDay' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
                        'formTimezone' => ['required', 'timezone'],
                    ],
                    [
                        'formDaysOfWeek.required' => 'Au moins un jour de la semaine est requis.',
                        'formDaysOfWeek.min' => 'Au moins un jour de la semaine est requis.',
                        'formDaysOfWeek.*.between' => 'Chaque jour doit être entre 1 (lundi) et 7 (dimanche).',
                        'formTimeOfDay.required' => "L'heure est requise.",
                        'formTimeOfDay.regex' => "L'heure doit être au format HH:MM.",
                        'formTimezone.required' => 'La timezone est requise.',
                        'formTimezone.timezone' => 'Fuseau horaire invalide (ex : Europe/Paris, UTC).',
                    ],
                );

                $days = array_values(array_map('intval', $this->formDaysOfWeek));

                if ($this->editingScheduleId) {
                    $this->scheduleService->update($this->editingScheduleId, [
                        'mode' => 'recurring',
                        'action' => $this->formAction,
                        'days_of_week' => $days,
                        'time_of_day' => $this->formTimeOfDay,
                        'timezone' => $this->formTimezone,
                        'run_at' => null,
                        'enabled' => $this->formEnabled,
                    ]);
                    $this->toastSuccess('Programmation mise à jour');
                } else {
                    $this->scheduleService->createRecurring((int) $this->id, $this->formAction, $days, $this->formTimeOfDay, $this->formTimezone, ($uid = (int) auth()->id()) > 0 ? $uid : null);
                    $this->toastSuccess('Programmation créée');
                }
            } else {
                $this->validate(
                    [
                        'formRunAtDate' => ['required', 'date_format:d/m/Y'],
                        'formRunAtTime' => ['required', 'regex:/^\d{2}:\d{2}$/'],
                    ],
                    [
                        'formRunAtDate.required' => 'La date est requise.',
                        'formRunAtDate.date_format' => 'Format attendu : JJ/MM/AAAA.',
                        'formRunAtTime.required' => "L'heure est requise.",
                        'formRunAtTime.regex' => "L'heure doit être au format HH:MM.",
                    ],
                );

                $runAt = Carbon::createFromFormat('d/m/Y H:i', $this->formRunAtDate . ' ' . $this->formRunAtTime);

                if (!$runAt->isFuture()) {
                    $this->addError('formRunAtDate', "La date et l'heure d'exécution doivent être dans le futur.");
                    return;
                }

                if ($this->editingScheduleId) {
                    $this->scheduleService->update($this->editingScheduleId, [
                        'mode' => 'one_shot',
                        'action' => $this->formAction,
                        'run_at' => $runAt,
                        'days_of_week' => null,
                        'time_of_day' => null,
                        'timezone' => null,
                        'enabled' => $this->formEnabled,
                    ]);
                    $this->toastSuccess('Programmation mise à jour');
                } else {
                    $this->scheduleService->createOneShot((int) $this->id, $this->formAction, $runAt, ($uid = (int) auth()->id()) > 0 ? $uid : null);
                    $this->toastSuccess('Programmation one-shot créée');
                }
            }

            $this->closeScheduleModal();
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\DomainException $e) {
            $this->toastError($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[GroupShow] Erreur saveSchedule', ['error' => $e->getMessage()]);
            $this->toastError("Échec de l'enregistrement de la programmation.");
        }
    }

    public function toggleSchedule(int $scheduleId): void
    {
        if (!Gate::allows('computer.control')) {
            $this->toastError('Accès refusé');
            return;
        }

        try {
            $schedule = WorkstationGroupSchedule::find($scheduleId);
            if (!$schedule || $schedule->workstation_group_id !== (int) $this->id) {
                $this->toastError('Programmation introuvable');
                return;
            }

            $updated = $this->scheduleService->toggle($scheduleId);
            $this->toastSuccess($updated->enabled ? 'Programmation activée' : 'Programmation désactivée');
        } catch (\DomainException $e) {
            $this->toastError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[GroupShow] Erreur toggleSchedule', ['error' => $e->getMessage()]);
            $this->toastError('Erreur lors du changement d\'état');
        }
    }

    public function deleteSchedule(int $scheduleId): void
    {
        if (!Gate::allows('computer.control')) {
            $this->toastError('Accès refusé');
            return;
        }

        try {
            $schedule = WorkstationGroupSchedule::find($scheduleId);
            if (!$schedule || $schedule->workstation_group_id !== (int) $this->id) {
                $this->toastError('Programmation introuvable');
                return;
            }

            $this->scheduleService->delete($scheduleId);
            $this->toastSuccess('Programmation supprimée');
        } catch (\Throwable $e) {
            Log::error('[GroupShow] Erreur deleteSchedule', ['error' => $e->getMessage()]);
            $this->toastError('Erreur lors de la suppression');
        }
    }

    public function cloneOneShot(int $scheduleId): void
    {
        if (!Gate::allows('computer.control')) {
            $this->toastError('Accès refusé');
            return;
        }

        try {
            $schedule = WorkstationGroupSchedule::find($scheduleId);
            if (!$schedule || $schedule->workstation_group_id !== (int) $this->id) {
                $this->toastError('Programmation introuvable');
                return;
            }

            $new = $this->scheduleService->cloneOneShot($scheduleId, auth()->id());
            $this->toastSuccess('Programmation dupliquée — pensez à ajuster la date');
            $this->openScheduleModal($new->id);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[GroupShow] Erreur cloneOneShot', ['error' => $e->getMessage()]);
            $this->toastError('Erreur lors de la duplication');
        }
    }

    public function deleteGroup(): void
    {
        try {
            $this->parcService->deleteGroup($this->id);

            session()->flash('toast', [
                'type' => 'success',
                'title' => 'Groupe supprimé',
                'message' => 'Le groupe a été supprimé avec succès.',
            ]);

            $this->redirect(route('app.parc.index'));
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur suppression: ' . $e->getMessage());
            $this->toastError($e->getMessage());
        }
    }
};
?>

@php
    $groupTypeLabel = $group?->is_physical ? 'Salle physique' : 'Groupe logique';
    $groupSyncLabel = $group?->isSyncedWithAd() ? 'Synchronisé AD' : 'Sync AD en attente';
    $groupMachinesCount = $group?->workstations?->count() ?? 0;
    $groupChildrenCount = $group?->children?->count() ?? 0;
    $groupHeaderDescription = $group
        ? "{$groupTypeLabel} • {$groupMachinesCount} machine(s) • {$groupChildrenCount} sous-groupe(s) • {$groupSyncLabel}"
        : 'Détail du groupe de machines';
@endphp

<x-organisms.page title="{{ $group?->is_physical ? 'Salle' : 'Groupe de postes' }}" :scrollable="true"
    backUrl="{{ route('app.parc.index') }}" backText="Retour">

    <x-slot:actions>
        <div class="flex gap-2 items-center">
            @if ($batchRunning)
                @php
                    $pollInterval = (int) config('parc.machine_readiness_poll_interval_seconds', 3);
                    $summary = $this->batchSummary;
                @endphp
                <div wire:poll.{{ $pollInterval }}s="pollGroupReadiness"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg bg-info/10 border border-info/30 text-info">
                    <span class="loading loading-spinner loading-sm"></span>
                    <span class="text-sm font-medium">
                        {{ ucfirst($batchAction ?? 'Action') }} en cours
                        ({{ $summary['success'] + $summary['failed'] }}/{{ $summary['total'] }})
                    </span>
                </div>
            @endif

            @if ($group)
                @php $isLocked = $group->isLocked(); @endphp
                <div class="dropdown dropdown-end">
                    <label tabindex="0" class="btn btn-primary">
                        <i class="fa-solid fa-bolt"></i>
                        Actions
                        <i class="fa-solid fa-chevron-down text-xs ml-1"></i>
                    </label>
                    <ul tabindex="0"
                        class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-60 border border-base-300 mt-2">
                        <li>
                            @if ($isLocked)
                                <span class="opacity-40 cursor-not-allowed">
                                    <i class="fa-solid fa-pen"></i>
                                    Modifier
                                    <i class="fa-solid fa-lock text-warning text-xs ml-auto"></i>
                                </span>
                            @else
                                <a href="{{ route('app.parc.groups.edit', $group->id) }}">
                                    <i class="fa-solid fa-pen"></i>
                                    Modifier
                                </a>
                            @endif
                        </li>
                        <hr class="border-zinc-200 my-1">
                        @can('computer.control')
                            <li>
                                <button type="button" wire:click="executeGroupAction('wake')" @disabled($batchRunning)
                                    class="{{ $batchRunning ? 'opacity-50 cursor-not-allowed' : '' }}">
                                    <i class="fa-solid fa-power-off"></i>
                                    Démarrer
                                </button>
                            </li>
                            <li>
                                <button type="button" wire:click="executeGroupAction('shutdown')"
                                    wire:confirm="Confirmer l'extinction de tous les postes du groupe ?"
                                    @disabled($batchRunning)
                                    class="{{ $batchRunning ? 'opacity-50 cursor-not-allowed' : '' }}">
                                    <i class="fa-solid fa-stop"></i>
                                    Éteindre
                                </button>
                            </li>
                        @endcan
                        @can('computer.control')
                            <li>
                                <button type="button" wire:click="openScheduleModal" @disabled($batchRunning)>
                                    <i class="fa-solid fa-calendar-day"></i>
                                    Programmer une action
                                </button>
                            </li>
                        @endcan
                        @if ($group->is_physical)
                            @can('wallpaper.manage')
                                <hr class="border-zinc-200 my-1">
                                <li>
                                    <button type="button" wire:click="openWallpaperModal">
                                        <i class="fa-solid fa-image"></i>
                                        Fonds d'écran
                                    </button>
                                </li>
                            @endcan
                        @endif
                        <hr class="border-zinc-200 my-1">
                        <li>
                            @if ($isLocked)
                                <span class="opacity-40 cursor-not-allowed text-error">
                                    <i class="fa-solid fa-trash"></i>
                                    Supprimer
                                    <i class="fa-solid fa-lock text-warning text-xs ml-auto"></i>
                                </span>
                            @else
                                <button type="button" class="text-error" wire:click="deleteGroup"
                                    wire:confirm="Êtes-vous sûr de vouloir supprimer ce groupe ?">
                                    <i class="fa-solid fa-trash"></i>
                                    Supprimer
                                </button>
                            @endif
                        </li>
                    </ul>
                </div>
            @endif
        </div>
    </x-slot:actions>

    @if ($group)
        <div class="space-y-6">
            {{-- Carte d'identité du groupe --}}
            <div class="card bg-base-100 shadow-sm border border-base-200 mb-6">
                <div class="card-body">
                    <div class="flex items-start gap-4 mb-4">
                        <div
                            class="{{ $group->is_physical ? 'bg-success/10 text-success' : 'bg-primary/10 text-primary' }} flex items-center justify-center rounded-xl w-16 h-16 shrink-0">
                            <i
                                class="fa-solid {{ $group->is_physical ? 'fa-door-open' : 'fa-layer-group' }} text-2xl"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h2 class="text-2xl font-bold">{{ $group->name }}</h2>
                                @if ($group->is_physical)
                                    <span class="badge badge-success gap-1">
                                        <i class="fa-solid fa-door-open text-xs"></i>
                                        Salle physique
                                    </span>
                                @else
                                    <span class="badge badge-info gap-1">
                                        <i class="fa-solid fa-layer-group text-xs"></i>
                                        Groupe logique
                                    </span>
                                @endif
                            </div>
                            @if ($group->description)
                                <p class="text-base-content/60 mt-1 text-sm">{{ $group->description }}</p>
                            @endif
                        </div>
                        @if ($group->isSyncedWithAd())
                            <span class="badge badge-success badge-lg shrink-0">
                                <i class="fa-solid fa-check text-xs mr-1"></i>
                                Synchronisé AD
                            </span>
                        @else
                            <span class="badge badge-warning badge-lg shrink-0">
                                <i class="fa-solid fa-clock text-xs mr-1"></i>
                                Sync AD en attente
                            </span>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <span class="text-xs text-base-content/60 uppercase tracking-wide">Machines</span>
                            <p class="font-medium mt-0.5">{{ $groupMachinesCount }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-base-content/60 uppercase tracking-wide">Sous-groupes</span>
                            <p class="font-medium mt-0.5">{{ $groupChildrenCount }}</p>
                        </div>
                        @if ($group->parent)
                            <div>
                                <span class="text-xs text-base-content/60 uppercase tracking-wide">Groupe parent</span>
                                <a href="{{ route('app.parc.groups.show', $group->parent->id) }}"
                                    class="block font-medium mt-0.5 hover:text-primary truncate">
                                    {{ $group->parent->name }}
                                </a>
                            </div>
                        @endif
                        <div>
                            <span class="text-xs text-base-content/60 uppercase tracking-wide">Créé le</span>
                            <p class="font-medium mt-0.5">{{ $group->created_at->format('d/m/Y') }}</p>
                        </div>
                    </div>

                    @if ($group->is_physical)
                        @can('wallpaper.manage')
                            @php
                                $headerWallpaper = \App\Models\Wallpaper::where('type', 'wallpaper')
                                    ->where('owner_type', \App\Models\WorkstationGroup::class)
                                    ->where('owner_id', $group->id)
                                    ->first();
                                $headerLockscreen = \App\Models\Wallpaper::where('type', 'lockscreen')
                                    ->where('owner_type', \App\Models\WorkstationGroup::class)
                                    ->where('owner_id', $group->id)
                                    ->first();
                            @endphp
                            @if ($headerWallpaper || $headerLockscreen)
                                <div class="flex items-center gap-3 mt-4 pt-4 border-t border-base-200">
                                    <span class="text-xs text-base-content/50 uppercase tracking-wide shrink-0">Fonds
                                        d'écran</span>
                                    @if ($headerWallpaper)
                                        <button type="button" wire:click="openWallpaperModal"
                                            class="group relative rounded-lg overflow-hidden border border-base-300 hover:border-primary transition-colors"
                                            title="Fond d'écran — cliquer pour gérer">
                                            <img src="{{ route('app.wallpapers.thumbnail', $headerWallpaper->id) }}"
                                                alt="Fond d'écran" class="w-16 h-10 object-cover">
                                            <div
                                                class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
                                                <i
                                                    class="fa-solid fa-pen text-white opacity-0 group-hover:opacity-100 text-xs"></i>
                                            </div>
                                            <span
                                                class="absolute bottom-0 left-0 right-0 text-[9px] text-center bg-black/50 text-white py-0.5">Bureau</span>
                                        </button>
                                    @endif
                                    @if ($headerLockscreen)
                                        <button type="button" wire:click="openWallpaperModal"
                                            class="group relative rounded-lg overflow-hidden border border-base-300 hover:border-primary transition-colors"
                                            title="Écran de verrouillage — cliquer pour gérer">
                                            <img src="{{ route('app.wallpapers.thumbnail', $headerLockscreen->id) }}"
                                                alt="Écran de verrouillage" class="w-16 h-10 object-cover">
                                            <div
                                                class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
                                                <i
                                                    class="fa-solid fa-pen text-white opacity-0 group-hover:opacity-100 text-xs"></i>
                                            </div>
                                            <span
                                                class="absolute bottom-0 left-0 right-0 text-[9px] text-center bg-black/50 text-white py-0.5">Verr.</span>
                                        </button>
                                    @endif
                                </div>
                            @endif
                        @endcan
                    @endif
                </div>
            </div>

            @include('pages.parc.groups.[id]._partials.batch-summary')
            @include('pages.parc.groups.[id]._partials.machines-list')
            @include('pages.parc.groups.[id]._partials.schedules-panel')
            @include('pages.parc.groups.[id]._partials.wallpaper-modal')
    </div @else <div class="card bg-base-100 shadow-sm">
        <div class="card-body flex flex-col items-center justify-center py-16">
            <div class="text-6xl mb-6 opacity-20">
                <i class="fa-solid fa-folder-open"></i>
            </div>
            <h3 class="text-xl font-semibold mb-3">Groupe non trouvé</h3>
            <p class="text-base-content/60 mb-6">
                Le groupe demandé n'existe pas ou a été supprimé.
            </p>
            <a href="{{ route('app.parc.index') }}" class="btn btn-primary">
                <i class="fa-solid fa-arrow-left"></i>
                Retour à la liste
            </a>
        </div>
        </div>
    @endif
</x-organisms.page>
