<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use App\Services\Parc\WorkstationGroupService;
use App\Jobs\SyncWorkstationGroupsFromAd;
use App\Components\Traits\WithToasts;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

new #[Title('Gestion du Parc - SE4FS')] class extends Component {
    use WithToasts;
    use WithPagination;

    private WorkstationGroupService $parcService;

    // Onglet actif
    #[Url]
    public string $tab = 'groups';

    // Filtres machines
    #[Url]
    public string $machineSearch = '';
    #[Url]
    public string $osFilter = '';
    #[Url]
    public ?int $groupFilter = null;

    // Filtres groupes
    #[Url]
    public string $groupSearch = '';
    #[Url]
    public bool $showLogical = false;

    // Sélection
    public array $selectedMachines = [];
    public array $selectedGroups = [];
    public bool $selectAllMachines = false;

    // Pagination
    #[Url]
    public int $machinesPerPage = 20;
    #[Url]
    public int $groupsPerPage = 20;
    public array $allowedPerPage = [10, 20, 50, 100];

    // Données
    public array $availableOs = [];
    /** @var Collection<WorkstationGroup> */
    public Collection $availableGroups;
    public array $machineStats = [];
    public array $groupStats = [];

    // États
    public bool $statsLoaded = false;
    public bool $showGroupModal = false;

    public function boot(WorkstationGroupService $parcService): void
    {
        $this->parcService = $parcService;
    }

    public function mount(): void
    {
        $this->availableGroups = collect();
        $this->loadFiltersData();

        if (session()->has('toast')) {
            $toastData = session('toast');
            $this->toast($toastData['type'] ?? 'info', $toastData['title'] ?? 'Notification', $toastData['message'] ?? '');
        }
    }

    public function loadFiltersData(): void
    {
        try {
            $this->availableOs = $this->parcService->getAvailableOs()->toArray();
            $this->availableGroups = $this->parcService->getRootGroupsForSelect();
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur chargement filtres: ' . $e->getMessage());
            $this->availableOs = [];
            $this->availableGroups = collect();
        }
    }

    public function getMachineActionsProperty(): Collection
    {
        return collect($this->parcService->getAvailableMachineActions())
            ->map(static fn(array $action): object => (object) $action)
            ->values();
    }

    public function loadStats(): void
    {
        if ($this->statsLoaded) {
            return;
        }

        try {
            $this->machineStats = $this->parcService->getMachineStats();
            $this->groupStats = $this->parcService->getGroupStats();
            $this->statsLoaded = true;
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur chargement stats: ' . $e->getMessage());
            $this->machineStats = [];
            $this->groupStats = [];
            $this->statsLoaded = true;
        }
    }

    public function getMachinesProperty()
    {
        try {
            return $this->parcService->listMachines(perPage: $this->machinesPerPage, search: $this->machineSearch ?: null, os: $this->osFilter ?: null, groupId: $this->groupFilter);
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur chargement machines: ' . $e->getMessage());
            return collect();
        }
    }

    public function getGroupsProperty()
    {
        try {
            return $this->parcService->listGroups(perPage: $this->groupsPerPage, search: $this->groupSearch ?: null, isPhysical: !$this->showLogical);
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur chargement groupes: ' . $e->getMessage());
            return collect();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function resetMachineFilters(): void
    {
        $this->machineSearch = '';
        $this->osFilter = '';
        $this->groupFilter = null;
        $this->selectedMachines = [];
        $this->resetPage();
    }

    public function resetGroupFilters(): void
    {
        $this->groupSearch = '';
        $this->showLogical = false;
        $this->selectedGroups = [];
        $this->resetPage();
    }

    public function updatedMachinesPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedGroupsPerPage(): void
    {
        $this->resetPage();
    }

    // Actions sur les machines
    public function addMachinesToGroup(int $groupId): void
    {
        if (empty($this->selectedMachines)) {
            $this->toastError('Aucune machine sélectionnée');
            return;
        }

        try {
            $count = $this->parcService->bulkAddMachinesToGroup($this->selectedMachines, $groupId);
            $this->toastSuccess("{$count} machine(s) ajoutée(s) au groupe");
            $this->selectedMachines = [];
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur ajout machines au groupe: ' . $e->getMessage());
            $this->toastError('Erreur lors de l\'ajout des machines');
        }
    }

    public function removeMachinesFromGroup(int $groupId): void
    {
        if (empty($this->selectedMachines)) {
            $this->toastError('Aucune machine sélectionnée');
            return;
        }

        try {
            $count = $this->parcService->bulkRemoveMachinesFromGroup($this->selectedMachines, $groupId);
            $this->toastSuccess("{$count} machine(s) retirée(s) du groupe");
            $this->selectedMachines = [];
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur retrait machines du groupe: ' . $e->getMessage());
            $this->toastError('Erreur lors du retrait des machines');
        }
    }

    public function executeSelectedMachinesAction(string $action): void
    {
        if (empty($this->selectedMachines)) {
            $this->toastError('Aucune machine sélectionnée');
            return;
        }

        try {
            $result = $this->parcService->executeMachinesAction($this->selectedMachines, $action);
            $actionLabel = $this->parcService->getMachineActionLabel($action);

            if ($result['requested_count'] === 0) {
                $this->toastWarning('Aucune machine valide à traiter');
                return;
            }

            if ($result['failed_count'] === 0) {
                $this->toastSuccess("Action de {$actionLabel} lancée sur {$result['success_count']}/{$result['requested_count']} machine(s)");
            } elseif ($result['success_count'] > 0) {
                $this->toastWarning("Action partielle ({$actionLabel}) : {$result['success_count']}/{$result['requested_count']} machine(s) traitée(s)");
            } else {
                $this->toastError("Échec de l'action de {$actionLabel} sur les machines sélectionnées");
            }

            $this->selectedMachines = [];
            $this->selectAllMachines = false;
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur action machines: ' . $e->getMessage(), [
                'action' => $action,
                'machines' => $this->selectedMachines,
            ]);
            $this->toastError('Erreur lors de l\'exécution de l\'action');
        }
    }

    // Actions sur les groupes
    public function deleteGroup(int $groupId): void
    {
        try {
            $this->parcService->deleteGroup($groupId);
            $this->toastSuccess('Groupe supprimé avec succès');
            $this->loadFiltersData();
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur suppression groupe: ' . $e->getMessage());
            $this->toastError($e->getMessage());
        }
    }

    public function deleteGroups(): void
    {
        if (empty($this->selectedGroups)) {
            $this->toastError('Aucun groupe sélectionné');
            return;
        }

        try {
            $count = 0;
            foreach ($this->selectedGroups as $groupId) {
                $this->parcService->deleteGroup((int) $groupId);
                $count++;
            }
            $this->toastSuccess("{$count} groupe(s) supprimé(s) avec succès");
            $this->selectedGroups = [];
            $this->loadFiltersData();
            $this->statsLoaded = false;
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur suppression groupes: ' . $e->getMessage());
            $this->toastError($e->getMessage());
        }
    }

    // Synchronisation depuis AD
    public function syncFromAd(): void
    {
        try {
            // Utiliser dispatch_sync pour exécuter le job de manière synchrone
            // Laravel injectera automatiquement le WorkstationGroupRepository
            SyncWorkstationGroupsFromAd::dispatchSync();

            // Rafraîchir le statut de synchronisation des WorkstationGroups
            $this->dispatch('refresh-sync-status-workstation-groups');

            $this->toastSuccess('Synchronisation depuis l\'AD terminée avec succès');
            $this->loadFiltersData();
            $this->statsLoaded = false;
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur sync AD: ' . $e->getMessage());
            $this->toastError('Erreur lors de la synchronisation: ' . $e->getMessage());
        }
    }
};
?>

<x-organisms.page title="Gestion du Parc" :scrollable="false"
    description="Gérez les postes et groupes de postes de votre établissement">

    <x-slot:actions>
        <div class="flex gap-2">
            <a href="{{ route('app.parc.groups.new') }}" class="btn btn-primary">
                <i class="fa-solid fa-plus"></i>
                Nouveau Groupe
            </a>
            <!-- <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-outline">
                    <i class="fa-solid fa-ellipsis-vertical"></i>
                    Actions
                    <i class="fa-solid fa-chevron-down text-xs ml-1"></i>
                </label>
                
            </div> -->
        </div>
    </x-slot:actions>

    <!-- Chargement asynchrone des stats -->
    <div wire:init="loadStats"></div>

    <div class="h-full flex flex-col gap-4">
        <!-- Onglets -->
        <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit">
            <button type="button" role="tab" class="tab {{ $tab === 'groups' ? 'tab-active' : '' }}"
                wire:click="setTab('groups')">
                <i class="fa-solid fa-folder-tree mr-2"></i>
                Groupes
            </button>
            <button type="button" role="tab" class="tab {{ $tab === 'machines' ? 'tab-active' : '' }}"
                wire:click="setTab('machines')">
                <i class="fa-solid fa-computer mr-2"></i>
                Postes
            </button>
        </div>

        <!-- Contenu des onglets -->
        <div class="flex-1 min-h-0 flex flex-col">
            @if ($tab === 'machines')
                @include('pages.parc._partials.machines-tab')
            @else
                {{-- Vérification synchronisation AD/SQL --}}
                <div class="flex-shrink-0">
                    <livewire:components::molecules.workstation-group-sync-status />
                </div>
                @include('pages.parc._partials.groups-tab')
            @endif
        </div>
    </div>
</x-organisms.page>
