<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use App\Models\WorkstationGroup;
use App\Models\Workstation;
use App\Repositories\GroupRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

new class extends Component {
    public bool $isOpen = false;

    // Onglet actif
    public string $activeTab = 'workstation_groups';

    // Recherche par onglet
    public string $searchWg = '';
    public string $searchWs = '';
    public string $searchUsers = '';
    public string $searchUserGroups = '';

    // Données disponibles
    public array $availableWorkstationGroups = [];
    public array $availableWorkstations = [];
    public array $availableUserGroups = [];
    public array $availableUsers = [];

    // Sélections
    public array $selectedWg = [];
    public array $selectedWs = [];
    public array $selectedUsers = [];
    public array $selectedUserGroups = [];

    // Déjà assignés (pour les exclure)
    public array $alreadyAssignedWgIds = [];
    public array $alreadyAssignedWsIds = [];
    public array $alreadyAssignedUsers = [];
    public array $alreadyAssignedUserGroups = [];

    #[On('open-shortcut-assignment-modal')]
    public function open(
        array $assignedWgIds = [],
        array $assignedWsIds = [],
        array $assignedUsers = [],
        array $assignedUserGroups = []
    ): void {
        $this->alreadyAssignedWgIds = $assignedWgIds;
        $this->alreadyAssignedWsIds = $assignedWsIds;
        $this->alreadyAssignedUsers = $assignedUsers;
        $this->alreadyAssignedUserGroups = $assignedUserGroups;

        $this->resetSelections();
        $this->loadAvailableData();
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    private function resetSelections(): void
    {
        $this->selectedWg = [];
        $this->selectedWs = [];
        $this->selectedUsers = [];
        $this->selectedUserGroups = [];
        $this->searchWg = '';
        $this->searchWs = '';
        $this->searchUsers = '';
        $this->searchUserGroups = '';
        $this->activeTab = 'workstation_groups';
    }

    private function loadAvailableData(): void
    {
        // WorkstationGroups (exclure déjà assignés)
        $this->availableWorkstationGroups = WorkstationGroup::active()
            ->whereNotIn('id', $this->alreadyAssignedWgIds)
            ->orderBy('name')
            ->get()
            ->map(fn(WorkstationGroup $g) => [
                'id' => $g->id,
                'name' => $g->display_name ?? $g->name,
                'description' => $g->description,
                'is_physical' => $g->is_physical,
            ])
            ->toArray();

        // Workstations (exclure déjà assignés)
        $this->availableWorkstations = Workstation::query()
            ->whereNotIn('id', $this->alreadyAssignedWsIds)
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn(Workstation $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'ip' => $w->ip,
                'os' => $w->os,
            ])
            ->toArray();

        // Groupes AD (via GroupRepository)
        try {
            $groupRepo = app(GroupRepository::class);
            $adGroups = $groupRepo->getGroupsByEstablishment();
            $this->availableUserGroups = $adGroups
                ->filter(fn($g) => !in_array($g['cn'], $this->alreadyAssignedUserGroups))
                ->values()
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('ShortcutAssignmentModal: impossible de charger les groupes AD', ['error' => $e->getMessage()]);
            $this->availableUserGroups = [];
        }

        // Users AD : chargés à la demande via recherche (trop nombreux pour tout charger)
        $this->availableUsers = [];
    }

    public function searchAdUsers(): void
    {
        if (strlen($this->searchUsers) < 2) {
            $this->availableUsers = [];
            return;
        }

        try {
            $userRepo = app(UserRepository::class);
            $users = $userRepo->search($this->searchUsers, 50);
            $this->availableUsers = $users
                ->filter(fn($u) => !in_array($u->login, $this->alreadyAssignedUsers) && !in_array($u->login, $this->selectedUsers))
                ->map(fn($u) => [
                    'cn' => $u->login,
                    'fullname' => $u->fullname,
                    'role' => $u->role,
                ])
                ->values()
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('ShortcutAssignmentModal: erreur recherche utilisateurs AD', ['error' => $e->getMessage()]);
            $this->availableUsers = [];
        }
    }

    // Toggle sélections
    public function toggleWg(int $id): void
    {
        $this->selectedWg = in_array($id, $this->selectedWg)
            ? array_values(array_diff($this->selectedWg, [$id]))
            : [...$this->selectedWg, $id];
    }

    public function toggleWs(int $id): void
    {
        $this->selectedWs = in_array($id, $this->selectedWs)
            ? array_values(array_diff($this->selectedWs, [$id]))
            : [...$this->selectedWs, $id];
    }

    public function toggleUser(string $cn): void
    {
        $this->selectedUsers = in_array($cn, $this->selectedUsers)
            ? array_values(array_diff($this->selectedUsers, [$cn]))
            : [...$this->selectedUsers, $cn];
    }

    public function toggleUserGroup(string $cn): void
    {
        $this->selectedUserGroups = in_array($cn, $this->selectedUserGroups)
            ? array_values(array_diff($this->selectedUserGroups, [$cn]))
            : [...$this->selectedUserGroups, $cn];
    }

    #[Computed]
    public function totalSelected(): int
    {
        return count($this->selectedWg) + count($this->selectedWs)
            + count($this->selectedUsers) + count($this->selectedUserGroups);
    }

    #[Computed]
    public function filteredWorkstationGroups(): array
    {
        if (empty($this->searchWg)) {
            return $this->availableWorkstationGroups;
        }
        $s = strtolower($this->searchWg);
        return array_values(array_filter($this->availableWorkstationGroups, fn($g) =>
            str_contains(strtolower($g['name']), $s) || str_contains(strtolower($g['description'] ?? ''), $s)
        ));
    }

    #[Computed]
    public function filteredWorkstations(): array
    {
        if (empty($this->searchWs)) {
            return $this->availableWorkstations;
        }
        $s = strtolower($this->searchWs);
        return array_values(array_filter($this->availableWorkstations, fn($w) =>
            str_contains(strtolower($w['name']), $s) || str_contains(strtolower($w['ip'] ?? ''), $s)
        ));
    }

    #[Computed]
    public function filteredUserGroups(): array
    {
        if (empty($this->searchUserGroups)) {
            return $this->availableUserGroups;
        }
        $s = strtolower($this->searchUserGroups);
        return array_values(array_filter($this->availableUserGroups, fn($g) =>
            str_contains(strtolower($g['cn']), $s) || str_contains(strtolower($g['description'] ?? ''), $s)
        ));
    }

    public function confirm(): void
    {
        $this->dispatch('shortcut-assignments-confirmed',
            workstationGroupIds: $this->selectedWg,
            workstationIds: $this->selectedWs,
            adUsers: $this->selectedUsers,
            adUserGroups: $this->selectedUserGroups,
        );
        $this->close();
    }
};
?>

<div>
    <dialog class="modal" x-data="{ open: @entangle('isOpen') }" :class="{ 'modal-open': open }" x-cloak>
        <div class="modal-box max-w-4xl max-h-[85vh] flex flex-col p-0">

            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b border-base-300 shrink-0">
                <div>
                    <h3 class="text-lg font-semibold">Assigner des cibles au raccourci</h3>
                    <p class="text-sm text-base-content/60">
                        Sélectionnez les groupes, postes ou utilisateurs concernés
                    </p>
                </div>
                <button wire:click="close" class="btn btn-sm btn-circle btn-ghost">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Tabs -->
            <div class="border-b border-base-300 shrink-0">
                <div class="tabs tabs-bordered px-4 flex-nowrap">
                    <button wire:click="setTab('workstation_groups')"
                        class="tab whitespace-nowrap {{ $activeTab === 'workstation_groups' ? 'tab-active' : '' }}">
                        <i class="fa-solid fa-layer-group mr-1"></i>
                        Groupes de postes
                        @if (count($selectedWg) > 0)
                            <span class="badge badge-primary badge-xs ml-1">{{ count($selectedWg) }}</span>
                        @endif
                    </button>
                    <button wire:click="setTab('workstations')"
                        class="tab whitespace-nowrap {{ $activeTab === 'workstations' ? 'tab-active' : '' }}">
                        <i class="fa-solid fa-computer mr-1"></i>
                        Postes
                        @if (count($selectedWs) > 0)
                            <span class="badge badge-primary badge-xs ml-1">{{ count($selectedWs) }}</span>
                        @endif
                    </button>
                    <button wire:click="setTab('user_groups')"
                        class="tab whitespace-nowrap {{ $activeTab === 'user_groups' ? 'tab-active' : '' }}">
                        <i class="fa-solid fa-users mr-1"></i>
                        Groupes utilisateurs
                        @if (count($selectedUserGroups) > 0)
                            <span class="badge badge-primary badge-xs ml-1">{{ count($selectedUserGroups) }}</span>
                        @endif
                    </button>
                    <button wire:click="setTab('users')"
                        class="tab whitespace-nowrap {{ $activeTab === 'users' ? 'tab-active' : '' }}">
                        <i class="fa-solid fa-user mr-1"></i>
                        Utilisateurs
                        @if (count($selectedUsers) > 0)
                            <span class="badge badge-primary badge-xs ml-1">{{ count($selectedUsers) }}</span>
                        @endif
                    </button>
                </div>
            </div>

            <!-- Tab content -->
            <div class="flex-1 overflow-hidden flex flex-col p-4">

                {{-- ===== ONGLET WORKSTATION GROUPS ===== --}}
                @if ($activeTab === 'workstation_groups')
                    @if (count($availableWorkstationGroups) > 3)
                        <div class="mb-3 shrink-0">
                            <label class="input input-bordered flex items-center gap-2 w-full">
                                <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                                <input type="text" wire:model.live.debounce.300ms="searchWg"
                                    placeholder="Rechercher un groupe..." class="grow" />
                            </label>
                        </div>
                    @endif
                    <div class="flex-1 overflow-y-auto min-h-0 border rounded-lg bg-base-100">
                        @if (count($this->filteredWorkstationGroups) > 0)
                            <div class="divide-y divide-base-200">
                                @foreach ($this->filteredWorkstationGroups as $group)
                                    <label wire:key="wg-{{ $group['id'] }}"
                                        class="flex items-center gap-3 p-3 cursor-pointer hover:bg-base-200 transition-colors">
                                        <input type="checkbox" wire:click="toggleWg({{ $group['id'] }})"
                                            @checked(in_array($group['id'], $selectedWg))
                                            class="checkbox checkbox-primary checkbox-sm" />
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0
                                            {{ $group['is_physical'] ? 'bg-warning/20' : 'bg-primary/20' }}">
                                            <i class="fa-solid {{ $group['is_physical'] ? 'fa-door-open text-warning' : 'fa-layer-group text-primary' }}"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium truncate">{{ $group['name'] }}</div>
                                            <div class="text-xs text-base-content/60">
                                                {{ $group['is_physical'] ? 'Salle' : 'Parc' }}
                                                @if ($group['description']) • {{ Str::limit($group['description'], 40) }} @endif
                                            </div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-col items-center justify-center h-32 text-base-content/60">
                                <i class="fa-solid fa-folder-open text-3xl mb-2"></i>
                                <span>{{ $searchWg ? 'Aucun résultat' : 'Tous les groupes sont déjà assignés' }}</span>
                            </div>
                        @endif
                    </div>

                {{-- ===== ONGLET WORKSTATIONS ===== --}}
                @elseif ($activeTab === 'workstations')
                    <div class="mb-3 shrink-0">
                        <label class="input input-bordered flex items-center gap-2 w-full">
                            <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                            <input type="text" wire:model.live.debounce.300ms="searchWs"
                                placeholder="Rechercher un poste (nom, IP)..." class="grow" />
                        </label>
                    </div>
                    <div class="flex-1 overflow-y-auto min-h-0 border rounded-lg bg-base-100">
                        @if (count($this->filteredWorkstations) > 0)
                            <div class="divide-y divide-base-200">
                                @foreach ($this->filteredWorkstations as $ws)
                                    <label wire:key="ws-{{ $ws['id'] }}"
                                        class="flex items-center gap-3 p-3 cursor-pointer hover:bg-base-200 transition-colors">
                                        <input type="checkbox" wire:click="toggleWs({{ $ws['id'] }})"
                                            @checked(in_array($ws['id'], $selectedWs))
                                            class="checkbox checkbox-primary checkbox-sm" />
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 bg-info/20">
                                            <i class="fa-solid fa-computer text-info"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium truncate">{{ $ws['name'] }}</div>
                                            <div class="text-xs text-base-content/60">
                                                @if ($ws['ip']) {{ $ws['ip'] }} @endif
                                                @if ($ws['os']) • {{ $ws['os'] }} @endif
                                            </div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-col items-center justify-center h-32 text-base-content/60">
                                <i class="fa-solid fa-computer text-3xl mb-2"></i>
                                <span>{{ $searchWs ? 'Aucun résultat' : 'Aucun poste disponible' }}</span>
                            </div>
                        @endif
                    </div>

                {{-- ===== ONGLET USER GROUPS AD ===== --}}
                @elseif ($activeTab === 'user_groups')
                    @if (count($availableUserGroups) > 3)
                        <div class="mb-3 shrink-0">
                            <label class="input input-bordered flex items-center gap-2 w-full">
                                <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                                <input type="text" wire:model.live.debounce.300ms="searchUserGroups"
                                    placeholder="Rechercher un groupe utilisateur..." class="grow" />
                            </label>
                        </div>
                    @endif
                    <div class="flex-1 overflow-y-auto min-h-0 border rounded-lg bg-base-100">
                        @if (count($this->filteredUserGroups) > 0)
                            <div class="divide-y divide-base-200">
                                @foreach ($this->filteredUserGroups as $group)
                                    <label wire:key="ug-{{ $group['cn'] }}"
                                        class="flex items-center gap-3 p-3 cursor-pointer hover:bg-base-200 transition-colors">
                                        <input type="checkbox" wire:click="toggleUserGroup('{{ $group['cn'] }}')"
                                            @checked(in_array($group['cn'], $selectedUserGroups))
                                            class="checkbox checkbox-secondary checkbox-sm" />
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 bg-secondary/20">
                                            <i class="fa-solid fa-users text-secondary"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium truncate">{{ $group['cn'] }}</div>
                                            @if (!empty($group['description']))
                                                <div class="text-xs text-base-content/60">{{ Str::limit($group['description'], 50) }}</div>
                                            @endif
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-col items-center justify-center h-32 text-base-content/60">
                                <i class="fa-solid fa-users text-3xl mb-2"></i>
                                <span>{{ $searchUserGroups ? 'Aucun résultat' : 'Aucun groupe utilisateur disponible' }}</span>
                            </div>
                        @endif
                    </div>

                {{-- ===== ONGLET USERS AD ===== --}}
                @elseif ($activeTab === 'users')
                    <div class="mb-3 shrink-0">
                        <label class="input input-bordered flex items-center gap-2 w-full">
                            <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                            <input type="text" wire:model.live.debounce.500ms="searchUsers"
                                wire:change="searchAdUsers"
                                placeholder="Rechercher un utilisateur (min. 2 caractères)..." class="grow" />
                        </label>
                    </div>

                    <!-- Utilisateurs déjà sélectionnés -->
                    @if (count($selectedUsers) > 0)
                        <div class="mb-3 shrink-0">
                            <div class="text-xs text-base-content/60 mb-1">Sélectionnés :</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($selectedUsers as $cn)
                                    <span class="badge badge-accent gap-1">
                                        {{ $cn }}
                                        <button type="button" wire:click="toggleUser('{{ $cn }}')" class="btn btn-ghost btn-xs btn-circle">
                                            <i class="fa-solid fa-xmark text-xs"></i>
                                        </button>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex-1 overflow-y-auto min-h-0 border rounded-lg bg-base-100">
                        @if (strlen($searchUsers) < 2)
                            <div class="flex flex-col items-center justify-center h-32 text-base-content/60">
                                <i class="fa-solid fa-magnifying-glass text-3xl mb-2"></i>
                                <span>Saisissez au moins 2 caractères pour rechercher</span>
                            </div>
                        @elseif (count($availableUsers) > 0)
                            <div class="divide-y divide-base-200">
                                @foreach ($availableUsers as $user)
                                    <label wire:key="user-{{ $user['cn'] }}"
                                        class="flex items-center gap-3 p-3 cursor-pointer hover:bg-base-200 transition-colors">
                                        <input type="checkbox" wire:click="toggleUser('{{ $user['cn'] }}')"
                                            @checked(in_array($user['cn'], $selectedUsers))
                                            class="checkbox checkbox-accent checkbox-sm" />
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 bg-accent/20">
                                            <i class="fa-solid fa-user text-accent"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium truncate">{{ $user['fullname'] }}</div>
                                            <div class="text-xs text-base-content/60">
                                                {{ $user['cn'] }}
                                                @if ($user['role']) • {{ $user['role'] }} @endif
                                            </div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-col items-center justify-center h-32 text-base-content/60">
                                <i class="fa-solid fa-user-slash text-3xl mb-2"></i>
                                <span>Aucun utilisateur trouvé</span>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Footer -->
            <div class="p-4 border-t border-base-300 shrink-0 space-y-3">
                <div class="flex justify-between items-center">
                    <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
                    <button type="button" wire:click="confirm" class="btn btn-primary"
                        @disabled($this->totalSelected === 0)>
                        <i class="fa-solid fa-link"></i>
                        Assigner
                        @if ($this->totalSelected > 0)
                            <span class="badge badge-sm">{{ $this->totalSelected }}</span>
                        @endif
                    </button>
                </div>
            </div>
        </div>

        <!-- Backdrop -->
        <form method="dialog" class="modal-backdrop">
            <button wire:click="close">close</button>
        </form>
    </dialog>
</div>
