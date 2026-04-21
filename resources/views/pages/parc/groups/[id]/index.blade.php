<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Services\Parc\WorkstationGroupService;
use App\Services\Parc\MachinePowerService;
use App\Models\MachinePowerActionTask;
use App\Models\WorkstationGroup;
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

    public ?WorkstationGroup $group = null;
    public string|int $id;
    public array $selectedMachines = [];
    public bool $showAddMachinesModal = false;
    public array $selectedGroupMachineIds = [];
    public bool $allGroupMachinesSelected = false;

    // ── État batch async (story 4-3) ───────────────────────────────────────
    // Ces propriétés pilotent le polling Livewire `wire:poll.{N}s` de la
    // vue groupe. Tant que $batchRunning est true, un unique `wire:poll`
    // est rendu et appelle pollGroupReadiness() à l'intervalle configuré.
    // Dès que toutes les tasks du batch courant sont terminales (completed
    // ou failed, ou timeoutées), $batchRunning repasse à false et Livewire
    // cesse d'interroger le serveur (le poll n'est plus rendu).
    public bool $batchRunning = false;
    public ?string $batchAction = null;           // libellé FR humanisé ("extinction", "redémarrage", etc.)
    // Clé action brute ("wake" / "shutdown" / etc.) — conservée pour les tests Feature
    // et un futur filtrage/affichage granulaire côté UI (icône par action dans le badge).
    public ?string $batchActionKey = null;
    public ?string $batchStartedAt = null;        // ISO 8601
    /** @var array<int> */
    public array $currentBatchTaskIds = [];
    public bool $batchSummaryVisible = false;
    public bool $batchTimeoutFired = false;

    // Mémoïsation interne des tasks du batch courant — partagée entre les deux
    // propriétés computed (batchSummary + machineActiveTasksById) pour éviter
    // un double SELECT par cycle de rendu. Réinitialisée en début de poll tick.
    private ?Collection $cachedBatchTasks = null;

    public function boot(WorkstationGroupService $parcService, MachinePowerService $machinePowerService): void
    {
        $this->parcService = $parcService;
        $this->machinePowerService = $machinePowerService;
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
        $stillActive = $tasks->filter(fn (MachinePowerActionTask $t) => $t->isActive())->count();

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

        return $this->cachedBatchTasks = MachinePowerActionTask::query()
            ->whereIn('id', $this->currentBatchTaskIds)
            ->with('workstation')
            ->get();
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

        $activeTasks = MachinePowerActionTask::query()
            ->whereIn('id', $this->currentBatchTaskIds)
            ->whereIn('status', MachinePowerActionTask::ACTIVE_STATUSES)
            ->with('workstation')
            ->get();

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

<x-organisms.page title="{{ $group?->name ?? 'Groupe' }}" :scrollable="true" description="{{ $groupHeaderDescription }}"
    backUrl="{{ route('app.parc.index') }}" backText="Retour">

    <x-slot:actions>
        <div class="flex gap-2 items-center">
            {{--
                Badge "batch en cours" (AC2/AC3 story 4-3).
                Unique porteur de wire:poll sur la vue groupe. Tant que
                $batchRunning est true Livewire poll toutes les N secondes et
                appelle pollGroupReadiness(). Quand toutes les tasks du batch
                courant sont terminales (ou timeoutées), $batchRunning repasse
                à false, la partie @if n'est plus rendue et Livewire cesse
                d'interroger le serveur.
            --}}
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
                @if ($group->is_physical)
                    <span class="badge badge-success badge-lg hidden lg:inline-flex">
                        <i class="fa-solid fa-door-open text-xs"></i>
                        Salle physique
                    </span>
                @else
                    <span class="badge badge-info badge-lg hidden lg:inline-flex">
                        <i class="fa-solid fa-layer-group text-xs"></i>
                        Groupe logique
                    </span>
                @endif

                @php
                    $isLocked = $group->isLocked();
                @endphp

                <div class="relative group">
                    <a href="{{ $isLocked ? '#' : route('app.parc.groups.edit', $group->id) }}"
                        class="btn btn-outline {{ $isLocked ? 'btn-disabled pointer-events-none group-hover:opacity-40' : '' }}"
                        @if ($isLocked) tabindex="-1" aria-disabled="true" @endif>
                        <i class="fa-solid fa-pen"></i>
                        Modifier
                    </a>
                    @if ($isLocked)
                        <div class="group-hover:block hidden absolute left-1/2 top-2 tooltip tooltip-bottom"
                            data-tip="{{ $group->getLockDescription() }}">
                            <i class="fa-solid fa-lock text-warning text-xl"></i>
                        </div>
                    @endif
                </div>

                <div class="relative group">
                    <button type="button"
                        class="btn btn-error btn-outline {{ $isLocked ? 'btn-disabled group-hover:opacity-40' : '' }}"
                        @if (!$isLocked) wire:click="deleteGroup" wire:confirm="Êtes-vous sûr de vouloir supprimer ce groupe ?" @endif
                        @if ($isLocked) disabled @endif>
                        <i class="fa-solid fa-trash"></i>
                        Supprimer
                    </button>
                    @if ($isLocked)
                        <div class="group-hover:block hidden absolute left-1/2 top-2 tooltip tooltip-bottom"
                            data-tip="{{ $group->getLockDescription() }}">
                            <i class="fa-solid fa-lock text-warning text-xl"></i>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </x-slot:actions>

    @if ($group)
        @include('pages.parc.groups.[id]._partials.batch-summary')
        @include('pages.parc.groups.[id]._partials.machines-list')
        @include('pages.parc.groups.[id]._partials.wallpaper-tab')
    @else
        <div class="card bg-base-100 shadow-sm">
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
