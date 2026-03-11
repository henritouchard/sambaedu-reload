<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use App\Services\UserService;
use App\Config\SambaEduConfig;
use App\Repositories\GroupRepository;

new class extends Component {
    public bool $isOpen = false;
    public $availableClasses = [];
    public $availableGroups = [];
    public $availableRoles = [];
    public bool $groupsLoaded = false;

    // Propriétés synchronisées avec l'URL (mais pas de déclenchement automatique)
    #[Url]
    public string $search = '';
    #[Url(as: 'classes')]
    public array $selectedClasses = [];
    #[Url(as: 'groupes')]
    public array $selectedGroups = [];
    #[Url(as: 'roles')]
    public array $selectedRoles = [];
    #[Url(as: 'statuses')]
    public array $selectedStatuses = [];

    private UserService $userService;
    private SambaEduConfig $config;
    private GroupRepository $groupRepository;

    public function boot(UserService $userService, SambaEduConfig $config, GroupRepository $groupRepository)
    {
        $this->userService = $userService;
        $this->config = $config;
        $this->groupRepository = $groupRepository;
    }

    public function mount()
    {
        // Ne PAS charger les groupes au mount - chargement asynchrone
        // Les groupes seront reçus via l'événement 'groups-loaded' de la page parente
    }

    /**
     * Reçoit toutes les données de filtres chargées par la page parente
     * Événement dispatché par la page users après le chargement asynchrone
     */
    #[On('filters-data-loaded')]
    public function onFiltersDataLoaded($data)
    {
        $this->availableGroups = $data['groups'] ?? [];
        $this->availableClasses = $data['classes'] ?? [];
        $this->availableRoles = $data['roles'] ?? [];
        $this->groupsLoaded = true;
    }
    

    #[On('open-filter-drawer')]
    public function open()
    {
        $this->isOpen = true;
    }

    // Fermer le drawer
    public function close()
    {
        $this->isOpen = false;
    }

    public function applyFilters()
    {
        // Construire l'URL avec les filtres et rediriger
        $params = [];

        if (!empty($this->search)) {
            $params['search'] = $this->search;
        }

        if (!empty($this->selectedClasses)) {
            $params['classes'] = $this->selectedClasses;
        }

        if (!empty($this->selectedGroups)) {
            $params['groupes'] = $this->selectedGroups;
        }

        if (!empty($this->selectedRoles)) {
            $params['roles'] = $this->selectedRoles;
        }

        if (!empty($this->selectedStatuses)) {
            $params['statuses'] = $this->selectedStatuses;
        }

        // Rediriger vers la page users avec les filtres
        $this->redirect(route('app.users', $params));
    }

    public function resetFilters()
    {
        // Réinitialiser tous les filtres
        $this->search = '';
        $this->selectedClasses = [];
        $this->selectedGroups = [];
        $this->selectedRoles = [];
        $this->selectedStatuses = [];

        // Rediriger vers la page users sans filtres
        $this->redirect(route('app.users'));
    }
}; ?>

<div>
    <!-- Drawer pour les filtres avancés -->
    <div class="drawer drawer-end z-[60]" x-data="{ open: @entangle('isOpen') }">
        <input type="checkbox" class="drawer-toggle" :checked="open" />
        <div class="drawer-side z-[60]" x-show="open" x-cloak>
            <label class="drawer-overlay" wire:click="close"></label>
            <div class="bg-base-200 h-screen w-96 flex flex-col z-[60]">
                <!-- Header du drawer -->
                <div class="bg-base-100 p-4 border-b border-base-300 shrink-0">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Filtres avancés</h3>
                        <button wire:click="close" class="btn btn-sm btn-circle btn-ghost">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <!-- Contenu du formulaire -->
                <div class="flex-1 overflow-y-auto p-4 space-y-2">
                    <!-- Recherche textuelle -->
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text font-medium">Recherche</span>
                        </label>
                        <input type="text" wire:model.live="search" placeholder="Nom, prénom, login..."
                            class="input input-bordered w-full" />
                    </div>

                    <!-- Classes (dropdown) -->
                    <div class="collapse bg-base-100 border border-base-300 rounded-lg {{ empty($availableClasses) ? '' : 'collapse-arrow' }}">
                        <input type="checkbox" />
                        <div class="collapse-title font-medium flex items-center justify-between pr-12">
                            <span>Classes</span>
                            <div class="flex items-center gap-2">
                                @if (count($selectedClasses) > 0)
                                    <span class="badge badge-primary badge-sm">{{ count($selectedClasses) }}</span>
                                @endif
                                @if (empty($availableClasses))
                                    <span class="loading loading-spinner loading-xs"></span>
                                @endif
                            </div>
                        </div>
                        <div class="collapse-content">
                            <div class="space-y-1 max-h-48 overflow-y-auto pt-2">
                                @if (!empty($availableClasses))
                                    @foreach ($availableClasses as $classe)
                                        <label
                                            class="flex items-center gap-2 cursor-pointer hover:bg-base-200 p-1 rounded">
                                            <input type="checkbox" wire:model="selectedClasses"
                                                value="{{ $classe }}"
                                                class="checkbox checkbox-sm checkbox-primary" />
                                            <span class="text-sm">{{ $classe }}</span>
                                        </label>
                                    @endforeach
                                @else
                                    <p class="text-sm text-base-content/60">Aucune classe disponible</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Groupes (dropdown) -->
                    <div class="collapse bg-base-100 border border-base-300 rounded-lg {{ empty($availableGroups) ? '' : 'collapse-arrow' }}">
                        <input type="checkbox" />
                        <div class="collapse-title font-medium flex items-center justify-between pr-12">
                            <span>Groupes</span>
                            <div class="flex items-center gap-2">
                                @if (count($selectedGroups) > 0)
                                    <span class="badge badge-primary badge-sm">{{ count($selectedGroups) }}</span>
                                @endif
                                @if (empty($availableGroups))
                                    <span class="loading loading-spinner loading-xs"></span>
                                @endif
                            </div>
                        </div>
                        <div class="collapse-content">
                            <div class="space-y-1 max-h-48 overflow-y-auto pt-2">
                                @if (!empty($availableGroups))
                                    @foreach ($availableGroups as $group)
                                        <label
                                            class="flex items-center gap-2 cursor-pointer hover:bg-base-200 p-1 rounded">
                                            <input type="checkbox" wire:model="selectedGroups"
                                                value="{{ $group['cn'] ?? $group }}"
                                                class="checkbox checkbox-sm checkbox-primary" />
                                            <span class="text-sm">{{ $group['name'] ?? $group }}</span>
                                        </label>
                                    @endforeach
                                @else
                                    <p class="text-sm text-base-content/60">Aucun groupe disponible</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Rôles (dropdown) -->
                    <div class="collapse bg-base-100 border border-base-300 rounded-lg {{ empty($availableRoles) ? '' : 'collapse-arrow' }}">
                        <input type="checkbox" />
                        <div class="collapse-title font-medium flex items-center justify-between pr-12">
                            <span>Rôles</span>
                            <div class="flex items-center gap-2">
                                @if (count($selectedRoles) > 0)
                                    <span class="badge badge-primary badge-sm">{{ count($selectedRoles) }}</span>
                                @endif
                                @if (empty($availableRoles))
                                    <span class="loading loading-spinner loading-xs"></span>
                                @endif
                            </div>
                        </div>
                        <div class="collapse-content">
                            <div class="space-y-1 pt-2">
                                @if (!empty($availableRoles))
                                    @foreach ($availableRoles as $role)
                                        <label
                                            class="flex items-center gap-2 cursor-pointer hover:bg-base-200 p-1 rounded {{ !empty($role['description']) ? 'tooltip tooltip-right' : '' }}"
                                            @if (!empty($role['description'])) title="{{ $role['description'] }}" @endif>
                                            <input type="checkbox" wire:model="selectedRoles"
                                                value="{{ $role['value'] }}"
                                                class="checkbox checkbox-sm checkbox-primary" />
                                            <span class="text-sm">{{ $role['label'] }}</span>
                                        </label>
                                    @endforeach
                                @else
                                    <p class="text-sm text-base-content/60">Aucun rôle disponible</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Statuts (dropdown) -->
                    <div class="collapse collapse-arrow bg-base-100 border border-base-300 rounded-lg">
                        <input type="checkbox" />
                        <div class="collapse-title font-medium flex items-center justify-between pr-12">
                            <span>Statuts</span>
                            @if (count($selectedStatuses) > 0)
                                <span class="badge badge-primary badge-sm">{{ count($selectedStatuses) }}</span>
                            @endif
                        </div>
                        <div class="collapse-content">
                            <div class="space-y-1 pt-2">
                                <label class="flex items-center gap-2 cursor-pointer hover:bg-base-200 p-1 rounded">
                                    <input type="checkbox" wire:model="selectedStatuses" value="actif"
                                        class="checkbox checkbox-sm checkbox-success" />
                                    <span class="text-sm">Actif</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer hover:bg-base-200 p-1 rounded">
                                    <input type="checkbox" wire:model="selectedStatuses" value="inactif"
                                        class="checkbox checkbox-sm checkbox-error" />
                                    <span class="text-sm">Inactif</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer hover:bg-base-200 p-1 rounded">
                                    <input type="checkbox" wire:model="selectedStatuses" value="suspendu"
                                        class="checkbox checkbox-sm checkbox-warning" />
                                    <span class="text-sm">Suspendu</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-between items-center shrink-0 p-4 border-t border-base-300 bg-base-100">
                    <button type="button" class="btn btn-ghost" wire:click="resetFilters">
                        <i class="fa-solid fa-times"></i>
                        Réinitialiser
                    </button>
                    <button type="button" class="btn btn-primary" wire:click="applyFilters">
                        <i class="fa-solid fa-filter"></i>
                        Appliquer
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
