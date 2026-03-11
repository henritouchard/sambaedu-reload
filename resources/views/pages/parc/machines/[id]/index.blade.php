<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use App\Services\Parc\WorkstationGroupService;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

new #[Title('Détail de la Machine - SE4FS')] class extends Component {
    use WithToasts;

    private WorkstationGroupService $parcService;

    public ?Workstation $workstation = null;
    public string|int $id;

    // Pour la salle physique (unique)
    public ?int $selectedPhysicalRoomId = null;
    public Collection $availablePhysicalRooms;

    // Pour les groupes logiques (multiples)
    public array $selectedLogicalGroupIds = [];
    public Collection $availableLogicalGroups;

    public function boot(WorkstationGroupService $parcService): void
    {
        $this->parcService = $parcService;
    }

    public function mount(string|int $id): void
    {
        $this->id = (int) $id;
        $this->availablePhysicalRooms = collect();
        $this->availableLogicalGroups = collect();
        $this->loadMachine();
        $this->loadAvailableGroups();

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
            // Charger les salles physiques
            $this->availablePhysicalRooms = $this->parcService->getPhysicalRooms();

            // Charger les groupes logiques (exclure ceux auxquels la machine appartient déjà)
            $currentGroupIds = $this->workstation ? $this->workstation->groups->pluck('id')->toArray() : [];
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

    public function executeMachinePowerAction(string $action): void
    {
        if (!$this->workstation) {
            $this->toastError('Machine non trouvée');
            return;
        }

        try {
            $result = $this->parcService->executeMachineAction((int) $this->workstation->id, $action);
            $actionLabel = $this->parcService->getMachineActionLabel($action);

            if ($result['requested_count'] === 0) {
                $this->toastWarning('Aucune machine valide à traiter');
                return;
            }

            // Gestion spéciale pour l'accès distant
            if ($action === 'remote') {
                $this->handleRemoteAccessResult($result);
                return;
            }

            if ($result['failed_count'] === 0) {
                $this->toastSuccess("Action de {$actionLabel} lancée avec succès");
            } elseif ($result['success_count'] > 0) {
                $this->toastWarning("Action partielle ({$actionLabel}) : {$result['success_count']}/{$result['requested_count']}");
            } else {
                $this->toastError("Échec de l'action de {$actionLabel} sur la machine");
            }
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[MachineShow] Erreur action machine: ' . $e->getMessage(), [
                'machine_id' => $this->workstation->id,
                'action' => $action,
            ]);
            $this->toastError('Erreur lors de l\'exécution de l\'action');
        }
    }

    /**
     * Gère le résultat de l'action d'accès distant
     */
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

    /**
     * Gère la sélection d'un groupe de postes depuis le drawer
     */
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
};
?>

<x-organisms.page title="{{ $workstation?->name ?? 'Poste' }}" :scrollable="true" description="Détail du poste">

    <x-slot:actions>
        <div class="flex gap-2">
            <a href="{{ route('app.parc.index') }}" class="btn btn-ghost">
                <i class="fa-solid fa-arrow-left"></i>
                Retour
            </a>

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
                                    'restart' => 'Confirmer le redémarrage de cette machine ?',
                                    default => null,
                                };
                            @endphp
                            <li>
                                <button type="button" wire:click="executeMachinePowerAction('{{ $action->key }}')"
                                    @if ($confirmMessage) wire:confirm="{{ $confirmMessage }}" @endif>
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
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            @include('pages.parc.machines.[id]._partials.machine-info')
            @include('pages.parc.machines.[id]._partials.groups-list')
        </div>

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
