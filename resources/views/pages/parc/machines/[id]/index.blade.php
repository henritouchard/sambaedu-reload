<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use App\Services\Parc\WorkstationGroupService;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use App\Models\WorkstationGroup;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

new #[Title('Détails de la Machine - SE4FS')] class extends Component {
    use WithToasts;

    private WorkstationGroupService $parcService;

    public ?Workstation $workstation = null;
    public string|int $id;

    public string $deploymentTab = 'errors';

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
        $this->initDeploymentTab();

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
};
?>

<x-organisms.page title="Détails du Poste" :scrollable="true" description="Détail du poste"
    backUrl="{{ route('app.parc.index') }}" backText="Retour">

    <x-slot:actions>
        <div class="flex gap-2">
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
        @php
            $deployment = $this->deploymentStatuses;
            $deploySuccess    = $deployment['success'];
            $deployErrors     = $deployment['errors'];
            $deployInProgress = $deployment['in_progress'];
            $deployFinished   = $deploySuccess->count() + $deployErrors->count();
            $deployRate       = $deployFinished > 0 ? round(($deploySuccess->count() / $deployFinished) * 100) : 0;
            $statusClass = match ($workstation->status) {
                1 => 'badge-success',
                2 => 'badge-warning',
                default => 'badge-error',
            };
        @endphp

        <div class="space-y-6">

            {{-- Card header : identité + salle physique + warning --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    {{-- En-tête identité --}}
                    <div class="flex items-start gap-4 mb-6">
                            <div class="bg-primary/10 text-primary flex items-center justify-center rounded-xl w-16 h-16">
                                <i class="fa-solid fa-computer text-2xl"></i>
                            </div>
                        <div class="flex-1">
                            <h2 class="text-2xl font-bold">{{ $workstation->name }}</h2>
                            <p class="text-base-content/60 mt-0.5">
                                <code class="bg-base-200 px-2 py-0.5 rounded">{{ $workstation->os }}</code>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="badge badge-lg {{ $statusClass }}">
                                {{ $workstation->getStatusLabel() }}
                            </span>
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
                                    wire:click="$dispatch('open-workstation-group-selector', { drawerId: 'change-physical-room', groups: {{ $availablePhysicalRooms->filter(fn($r) => $r->id !== $workstation->physical_room_id)->values()->toJson() }} })"
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
                                {{ $workstation->date_rapport_poste?->format('d/m/Y H:i') ?? '—' }}
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

            {{-- Card groupes logiques --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="card-title text-base">
                            <i class="fa-solid fa-layer-group text-primary"></i>
                            Groupes logiques
                            <span class="badge badge-ghost">{{ $workstation->groups->count() }}</span>
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

                    @if ($workstation->groups->isEmpty())
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
                            @foreach ($workstation->groups as $group)
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

        </div>{{-- /space-y-6 --}}

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
