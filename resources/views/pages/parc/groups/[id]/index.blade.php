<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Services\Parc\WorkstationGroupService;
use App\Models\WorkstationGroup;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

new #[Title('Détail du Groupe - SE4FS')] class extends Component {
    use WithToasts;

    private WorkstationGroupService $parcService;

    public ?WorkstationGroup $group = null;
    public string|int $id;
    public array $selectedMachines = [];
    public bool $showAddMachinesModal = false;
    public array $selectedGroupMachineIds = [];
    public bool $allGroupMachinesSelected = false;

    public function boot(WorkstationGroupService $parcService): void
    {
        $this->parcService = $parcService;
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

    public function executeSelectedGroupMachinesAction(string $action): void
    {
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

            if ($result['failed_count'] === 0) {
                $this->toastSuccess("Action de {$actionLabel} lancée sur {$result['success_count']}/{$result['requested_count']} machine(s)");
            } elseif ($result['success_count'] > 0) {
                $this->toastWarning("Action partielle ({$actionLabel}) : {$result['success_count']}/{$result['requested_count']} machine(s)");
            } else {
                $this->toastError("Échec de l'action de {$actionLabel} sur les machines sélectionnées");
            }

            $this->selectedGroupMachineIds = [];
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

    public function executeMachineAction(int $machineId, string $action): void
    {
        try {
            $result = $this->parcService->executeGroupMachinesAction($this->id, [$machineId], $action);
            $actionLabel = $this->parcService->getMachineActionLabel($action);

            if ($result['requested_count'] === 0) {
                $this->toastWarning('Machine introuvable dans ce groupe');
                return;
            }

            if ($result['failed_count'] === 0) {
                $this->toastSuccess("Action de {$actionLabel} lancée sur la machine");
            } else {
                $this->toastError("Échec de l'action de {$actionLabel} sur la machine");
            }
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

    public function getMachineActionsProperty(): Collection
    {
        return collect($this->parcService->getAvailableMachineActions())
            ->map(static fn(array $action): object => (object) $action)
            ->values();
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
        <div class="flex gap-2">
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
        @include('pages.parc.groups.[id]._partials.machines-list')
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
