<?php

use App\Models\User;
use App\Models\UserGroup;
use App\Policies\UserPolicy;
use App\Services\UserGroupService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    private UserGroupService $userGroupService;

    public string $activeTab = 'users';
    public string $search = '';
    public string $groupsSearch = '';
    #[Url(as: 'per')]
    public int $perPage = 20;
    public const PER_PAGE_OPTIONS = [10, 20, 50, 100];
    public array $role = [];
    public array $status = [];
    public array $group = [];
    // Story 14.4 — filtres audit (D12 : props bool directes, pas array)
    public bool $quotaOverflow = false;
    public bool $passwordDefault = false;
    public array $selectedUsers = [];
    public array $selectedUserGroups = [];
    public bool $isFiltersModalOpen = false;

    public function boot(UserGroupService $userGroupService): void
    {
        $this->userGroupService = $userGroupService;
    }

    public function toggleUserSelection(string $login): void
    {
        if (in_array($login, $this->selectedUsers, true)) {
            $this->selectedUsers = array_values(array_diff($this->selectedUsers, [$login]));
            return;
        }

        $this->selectedUsers[] = $login;
    }

    public function selectAllVisibleUsers(): void
    {
        $this->selectedUsers = collect($this->users->items())
            ->map(fn(User $userItem): string => $userItem->login)
            ->values()
            ->all();
    }

    public function clearSelectedUsers(): void
    {
        $this->selectedUsers = [];
    }

    public function updatedSearch(): void
    {
        $this->selectedUsers = [];
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (!in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 20;
        }

        $this->selectedUsers = [];
        $this->selectedUserGroups = [];
        $this->resetPage();
        $this->resetPage(pageName: 'groupsPage');
    }

    public function updatedGroupsSearch(): void
    {
        $this->selectedUserGroups = [];
        $this->resetPage(pageName: 'groupsPage');
    }

    public function toggleGroupSelection(int $groupId): void
    {
        if (in_array($groupId, $this->selectedUserGroups, true)) {
            $this->selectedUserGroups = array_values(array_diff($this->selectedUserGroups, [$groupId]));
            return;
        }

        $this->selectedUserGroups[] = $groupId;
    }

    public function selectAllVisibleGroups(): void
    {
        $this->selectedUserGroups = collect($this->groups->items())
            ->map(fn(UserGroup $groupItem): int => $groupItem->id)
            ->values()
            ->all();
    }

    public function clearSelectedGroups(): void
    {
        $this->selectedUserGroups = [];
    }

    public function deleteSelectedGroups(): void
    {
        if (empty($this->selectedUserGroups)) {
            return;
        }

        $this->userGroupService->bulkDelete($this->selectedUserGroups);

        $this->selectedUserGroups = [];
        unset($this->groups);
    }

    public function syncSelectedGroups(): void
    {
        if (empty($this->selectedUserGroups)) {
            return;
        }

        $this->userGroupService->syncGroupsWithAd($this->selectedUserGroups);

        unset($this->groups);
    }

    public function updatedRole(): void
    {
        $this->selectedUsers = [];
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->selectedUsers = [];
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        $this->selectedUsers = [];
        $this->resetPage();
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['users', 'groups'], true) ? $tab : 'users';
        $this->selectedUsers = [];
        $this->selectedUserGroups = [];

        if ($this->activeTab === 'users') {
            $this->resetPage();
            return;
        }

        $this->resetPage(pageName: 'groupsPage');
    }

    public function resetFilters(): void
    {
        $this->role = [];
        $this->status = [];
        $this->group = [];
        // Story 14.4 — Tâche 5.3
        $this->quotaOverflow = false;
        $this->passwordDefault = false;
        // Post-review #3 : reset selectedUsers (parité avec les updated* qui le font déjà)
        $this->selectedUsers = [];
        $this->resetPage();
    }

    #[On('toggle-users-filters-modal')]
    public function toggleFiltersModal(): void
    {
        $this->isFiltersModalOpen = !$this->isFiltersModalOpen;
    }

    public function closeFiltersModal(): void
    {
        $this->isFiltersModalOpen = false;
    }

    public function removeRoleFilter(string $value): void
    {
        $this->role = array_values(array_filter($this->role, fn(string $item) => $item !== $value));
        $this->resetPage();
    }

    public function removeStatusFilter(string $value): void
    {
        $this->status = array_values(array_filter($this->status, fn(string $item) => $item !== $value));
        $this->resetPage();
    }

    public function removeGroupFilter(string $value): void
    {
        $this->group = array_values(array_filter($this->group, fn(string $item) => $item !== $value));
        $this->resetPage();
    }

    // Story 14.4 — méthodes updated* + remove pour les filtres audit
    // Post-review #3 : reset selectedUsers pour parité avec updatedRole/Status/Group
    // (évite l'état incohérent si une bulk action est en cours pendant un changement de filtre).
    public function updatedQuotaOverflow(): void
    {
        $this->selectedUsers = [];
        $this->resetPage();
    }

    public function updatedPasswordDefault(): void
    {
        $this->selectedUsers = [];
        $this->resetPage();
    }

    public function removeQuotaOverflowFilter(): void
    {
        $this->quotaOverflow = false;
        $this->selectedUsers = [];
        $this->resetPage();
    }

    public function removePasswordDefaultFilter(): void
    {
        $this->passwordDefault = false;
        $this->selectedUsers = [];
        $this->resetPage();
    }

    #[Computed]
    public function availableRoles(): Collection
    {
        return User::query()->whereNotNull('role')->where('role', '!=', '')->select('role')->distinct()->orderBy('role')->pluck('role');
    }

    #[Computed]
    public function roleFilterOptions(): array
    {
        return $this->availableRoles
            ->map(
                fn(string $roleValue) => [
                    'value' => $roleValue,
                    'label' => $roleValue,
                    'hint' => '',
                    'disabled' => false,
                ],
            )
            ->values()
            ->all();
    }

    #[Computed]
    public function availableGroups(): Collection
    {
        return \App\Models\UserGroup::query()->orderBy('name')->pluck('name');
    }

    #[Computed]
    public function groupFilterOptions(): array
    {
        return $this->availableGroups
            ->map(
                fn(string $groupValue) => [
                    'value' => $groupValue,
                    'label' => $groupValue,
                    'hint' => '',
                    'disabled' => false,
                ],
            )
            ->values()
            ->all();
    }

    #[Computed]
    public function statusFilterOptions(): array
    {
        $options = [
            ['value' => 'active', 'label' => 'Actifs', 'hint' => '', 'disabled' => false],
            ['value' => 'inactive', 'label' => 'Inactifs', 'hint' => '', 'disabled' => false],
            ['value' => 'trash', 'label' => 'Corbeille', 'hint' => '', 'disabled' => false],
        ];

        $currentCode = \App\Facades\SEConfig::getCurrentEstablishmentCode();
        if (!empty($currentCode) && $currentCode !== '0') {
            $options[] = ['value' => 'externe', 'label' => 'Externes', 'hint' => '', 'disabled' => false];
        }

        return $options;
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $term = trim($this->search);

        $query = User::query()->select(['id', 'login', 'firstname', 'lastname', 'fullname', 'is_active', 'school_code', 'quota_snapshot', 'profile_snapshot']);

        if (mb_strlen($term) >= 4) {
            $normalizedSearch = '%' . mb_strtolower($term) . '%';

            $query->where(function (Builder $builder) use ($normalizedSearch) {
                $builder
                    ->whereRaw("LOWER(COALESCE(lastname, '')) LIKE ?", [$normalizedSearch])
                    ->orWhereRaw("LOWER(COALESCE(firstname, '')) LIKE ?", [$normalizedSearch])
                    ->orWhereRaw("LOWER(COALESCE(login, '')) LIKE ?", [$normalizedSearch]);
            });
        }

        if (!empty($this->role)) {
            $query->whereIn('role', $this->role);
        }

        if (!empty($this->status)) {
            $query->where(function (Builder $builder) {
                if (in_array('active', $this->status, true)) {
                    $builder->orWhere('is_active', true);
                }

                if (in_array('inactive', $this->status, true) || in_array('trash', $this->status, true)) {
                    $builder->orWhere('is_active', false);
                }

                if (in_array('externe', $this->status, true)) {
                    $currentCode = \App\Facades\SEConfig::getCurrentEstablishmentCode();
                    if (!empty($currentCode) && $currentCode !== '0') {
                        $builder->orWhere(function (Builder $q) use ($currentCode) {
                            $q->whereNotNull('school_code')
                                ->where('school_code', '!=', '0')
                                ->whereRaw('LOWER(school_code) != LOWER(?)', [$currentCode]);
                        });
                    }
                }
            });
        }

        if (!empty($this->group)) {
            $query->whereHas('userGroups', function (Builder $builder) {
                $builder->whereIn('name', $this->group);
            });
        }

        // Story 14.4 — Tâche 5.4 : filtres audit (D1/D2 quota, D3 mdp)
        if ($this->quotaOverflow) {
            // D1 : OR sur is_over_soft|is_over_hard sur les 2 partitions (home + sambaedu)
            // D2 : les users avec quota_snapshot IS NULL sont exclus implicitement (comparaison JSON = true ne matche pas NULL)
            $query->where(function (Builder $b) {
                $b->where('quota_snapshot->home->is_over_soft', true)
                    ->orWhere('quota_snapshot->home->is_over_hard', true)
                    ->orWhere('quota_snapshot->sambaedu->is_over_soft', true)
                    ->orWhere('quota_snapshot->sambaedu->is_over_hard', true);
            });
        }

        if ($this->passwordDefault) {
            // D3 : NULL inclus (= « jamais changé » présumé)
            $query->whereNull('password_changed_at');
        }

        // Correction review 7.2 #3 — RGPD : un Prof (ou EleveAdmin) scopé classe
        // ne doit voir que les élèves de ses propres classes. Sans ce filtre
        // Eloquent, le listing contourne la Policy `UserPolicy::view()` qui
        // n'est appliquée que sur les targets individuels.
        //
        // Story 4.13 — Recâblage post-fold. L'import AD→SQL replie désormais
        // les classes en UNE ligne au NOM NU (`type='classe'`) ; prof ET élève
        // sont co-membres de cette même ligne. On résout les noms nus de
        // classes de l'acteur via le helper PARTAGÉ `User::classGroupNames()`
        // (le même qu'utilise `UserPolicy::sharesClassWithTarget`), puis on
        // restreint le listing aux users co-membres de ces classes. La logique
        // vestige (filtrage `Equipe_%`/`PP_%` + reconstruction `Classe_X`)
        // renvoyait un ensemble vide après fold → `1=0` (déni total).
        $actor = auth()->user();

        if ($actor instanceof User
            && $actor->hasAnyRole(['prof', 'eleve-admin'])
            && !$actor->hasAnyRole(UserPolicy::GLOBAL_USER_ROLES)
        ) {
            $classNames = $actor->classGroupNames();

            if ($classNames->isEmpty()) {
                // Acteur scopé classe sans classe attachée : aucun user visible.
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas(
                    'userGroups',
                    fn(Builder $q) => $q->where('type', 'classe')
                        ->whereIn('name', $classNames->all())
                );
            }
        }

        return $query->orderByRaw("COALESCE(lastname, '')")->orderByRaw("COALESCE(firstname, '')")->orderBy('login')->paginate($this->perPage);
    }

    #[Computed]
    public function groups(): LengthAwarePaginator
    {
        return $this->userGroupService->listPaginated($this->groupsSearch, $this->perPage);
    }
};
?>

<x-organisms.page title="Utilisateurs" :scrollable="false" description="Liste des utilisateurs synchronisés en base SQL">
    <x-slot:actions>
        @if ($activeTab === 'groups')
            @can('user.modify')
                <button type="button" class="btn btn-primary" wire:click="$dispatch('open-user-group-modal')">
                    <i class="fa-solid fa-plus"></i>
                    Nouveau groupe
                </button>
            @endcan
        @else
            <a href="{{ route('app.users.new') }}" class="btn btn-primary">
                <i class="fa-solid fa-plus"></i>
                Nouvel utilisateur
            </a>
        @endif
    </x-slot:actions>

    <div class="tabs tabs-boxed mb-4 w-fit bg-base-200/60 p-1">
        <button type="button" class="tab {{ $activeTab === 'users' ? 'tab-active' : '' }}"
            wire:click="switchTab('users')">
            <i class="fa-solid fa-user mr-1"></i>
            Utilisateurs
        </button>
        <button type="button" class="tab {{ $activeTab === 'groups' ? 'tab-active' : '' }}"
            wire:click="switchTab('groups')">
            <i class="fa-solid fa-users mr-1"></i>
            Groupes
        </button>
    </div>

    <div class="card bg-base-100 shadow-sm mb-4">
        <div class="card-body py-4">
            @if ($activeTab === 'users')
                <label class="label">
                    <span class="label-text font-medium">Recherche utilisateur</span>
                    <span class="label-text-alt text-xs text-base-content/60">Déclenchement à partir de 4 lettres</span>
                </label>

                <div class="flex gap-2">
                    <input type="text" wire:model.live.debounce.350ms="search" class="input input-bordered w-full"
                        placeholder="Nom, prénom ou login..." />
                    <select wire:model.live="perPage" class="select select-bordered w-auto" title="Lignes par page">
                        @foreach (self::PER_PAGE_OPTIONS as $option)
                            <option value="{{ $option }}">{{ $option }} / page</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-outline" wire:click="$dispatch('toggle-users-filters-modal')">
                        <i class="fa-solid fa-filter"></i>
                        Filtres
                    </button>
                </div>

                @if (!empty($role) || !empty($status) || !empty($group) || $quotaOverflow || $passwordDefault)
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="text-xs text-base-content/60">Filtres actifs :</span>

                        @foreach ($role as $roleItem)
                            <button type="button" class="badge badge-outline gap-1"
                                wire:click="removeRoleFilter('{{ $roleItem }}')">
                                role: {{ $roleItem }}
                                <i class="fa-solid fa-xmark text-[10px]"></i>
                            </button>
                        @endforeach

                        @foreach ($status as $statusItem)
                            <button type="button" class="badge badge-outline gap-1"
                                wire:click="removeStatusFilter('{{ $statusItem }}')">
                                statut: {{ $statusItem }}
                                <i class="fa-solid fa-xmark text-[10px]"></i>
                            </button>
                        @endforeach

                        @foreach ($group as $groupItem)
                            <button type="button" class="badge badge-outline gap-1"
                                wire:click="removeGroupFilter('{{ $groupItem }}')">
                                groupe: {{ $groupItem }}
                                <i class="fa-solid fa-xmark text-[10px]"></i>
                            </button>
                        @endforeach

                        {{-- Story 14.4 — Tâche 5.6 : chips actifs filtres audit --}}
                        @if ($quotaOverflow)
                            <button type="button" class="badge badge-outline gap-1"
                                wire:click="removeQuotaOverflowFilter">
                                audit: quota dépassé
                                <i class="fa-solid fa-xmark text-[10px]"></i>
                            </button>
                        @endif

                        @if ($passwordDefault)
                            <button type="button" class="badge badge-outline gap-1"
                                wire:click="removePasswordDefaultFilter">
                                audit: mdp par défaut
                                <i class="fa-solid fa-xmark text-[10px]"></i>
                            </button>
                        @endif

                        <button type="button" class="btn btn-ghost btn-xs" wire:click="resetFilters">
                            Tout effacer
                        </button>
                    </div>
                @endif
            @else
                <label class="label">
                    <span class="label-text font-medium">Recherche groupe</span>
                    <span class="label-text-alt text-xs text-base-content/60">Déclenchement à partir de 2 lettres</span>
                </label>

                <div class="flex gap-2">
                    <input type="text" wire:model.live.debounce.300ms="groupsSearch"
                        class="input input-bordered w-full" placeholder="Nom, nom affiché ou type..." />
                    <select wire:model.live="perPage" class="select select-bordered w-auto" title="Lignes par page">
                        @foreach (self::PER_PAGE_OPTIONS as $option)
                            <option value="{{ $option }}">{{ $option }} / page</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    </div>

    @if ($activeTab === 'users')
        <x-molecules.modal wire:model="isFiltersModalOpen" size="max-w-2xl" height="h-auto max-h-[85vh]"
            title="Filtres utilisateurs" icon="fa-filter text-primary" closeMethod="closeFiltersModal">
            <x-molecules.modal.section title="Rôles" icon="fa-user-tag text-primary">
                <livewire:components::molecules.smart-select wire:model="role" :options="$this->roleFilterOptions"
                    :multiple="true" :filterable="true" :clearable="true" :inline="true" :show-trigger="false"
                    panel-class="border-0 rounded-none bg-transparent" list-class="p-0"
                    placeholder="Sélectionner un ou plusieurs rôles" />
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Statuts" icon="fa-toggle-on text-primary">
                <livewire:components::molecules.smart-select wire:model="status" :options="$this->statusFilterOptions"
                    :multiple="true" :filterable="true" :clearable="true" :inline="true" :show-trigger="false"
                    panel-class="border-0 rounded-none bg-transparent" list-class="p-0"
                    placeholder="Sélectionner un ou plusieurs statuts" />
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Groupes" icon="fa-users text-primary">
                <livewire:components::molecules.smart-select wire:model="group" :options="$this->groupFilterOptions"
                    :multiple="true" :filterable="true" :clearable="true" :inline="true" :show-trigger="false"
                    panel-class="border-0 rounded-none bg-transparent" list-class="p-0"
                    placeholder="Rechercher et sélectionner des groupes" />
            </x-molecules.modal.section>

            {{-- Story 14.4 — Tâche 5.5 : section Audit avec 2 toggles --}}
            <x-molecules.modal.section title="Audit" icon="fa-shield-halved text-primary">
                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" class="toggle toggle-primary" wire:model.live="quotaOverflow">
                        <span class="label-text">Quota dépassé</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" class="toggle toggle-primary" wire:model.live="passwordDefault">
                        <span class="label-text">Mot de passe par défaut</span>
                    </label>
                </div>
            </x-molecules.modal.section>

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="resetFilters">Réinitialiser</button>
                <button type="button" class="btn btn-primary" wire:click="closeFiltersModal">Appliquer</button>
            </x-slot:footer>
        </x-molecules.modal>
    @endif

    @if ($activeTab === 'users')
        @php
            $searchLength = mb_strlen(trim($search));
        @endphp

        <div class="card bg-base-100 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-base-300 text-sm text-base-content/70">
                @if ($searchLength >= 4)
                    Résultats filtrés sur "{{ $search }}" ({{ $this->users->total() }} résultat(s))
                @else
                    Affichage des utilisateurs synchronisés (page {{ $this->users->currentPage() }})
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <input type="checkbox" class="checkbox" @checked(count($selectedUsers) > 0 && count($selectedUsers) === count($this->users->items()))
                                    wire:click="{{ count($selectedUsers) > 0 ? 'clearSelectedUsers' : 'selectAllVisibleUsers' }}">
                            </th>
                            <th>Nom</th>
                            <th>Prénom</th>
                            <th>Login</th>
                            <th>Utilisation</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->users as $user)
                            <tr class="hover:bg-sky-50 cursor-pointer"
                                onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.user.show', $user->login) }}'">
                                <td class="checkbox-cell p-0">
                                    <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                        <input type="checkbox" class="checkbox" @checked(in_array($user->login, $selectedUsers, true))
                                            wire:click.stop="toggleUserSelection('{{ $user->login }}')">
                                    </label>
                                </td>
                                <td>{{ $user->lastname ?: '-' }}</td>
                                <td>{{ $user->firstname ?: '-' }}</td>
                                <td class="font-mono text-sm">{{ $user->login }}</td>
                                <td>
                                    @php
                                        // Story 5.1b — colonne Utilisation (D6 : /home uniquement).
                                        // Lecture du snapshot JSON directement (zéro shellout).
                                        $homeSnap = $user->quota_snapshot['home'] ?? null;
                                        $percent = is_array($homeSnap) ? ($homeSnap['percent'] ?? null) : null;
                                        $overSoft = is_array($homeSnap) ? (bool) ($homeSnap['is_over_soft'] ?? false) : false;
                                        $badgeClass = 'badge-success';
                                        if ($percent !== null) {
                                            if ($percent >= 90 || $overSoft) {
                                                $badgeClass = 'badge-error';
                                            } elseif ($percent >= 70) {
                                                $badgeClass = 'badge-warning';
                                            }
                                        }
                                    @endphp
                                    @php
                                        // Story 26.3 — pastille « profil itinérant volumineux ».
                                        // Lecture du cache JSON profile_snapshot (zéro shellout,
                                        // alimenté par le job nocturne profiles:snapshot). Badge
                                        // affiché uniquement au-delà du seuil ; rien sinon.
                                        $profileSnap = $user->profile_snapshot ?? null;
                                        $profileMb = is_array($profileSnap) ? ($profileSnap['size_mb'] ?? null) : null;
                                        $largeThreshold = \App\Services\RoamingProfileService::LARGE_PROFILE_THRESHOLD_MB;
                                        $isLargeProfile = $profileMb !== null && (float) $profileMb >= $largeThreshold;
                                    @endphp
                                    <div class="flex items-center gap-1 flex-wrap">
                                        @if ($percent === null)
                                            <span class="text-base-content/40" title="Aucun snapshot disponible">—</span>
                                        @else
                                            <span class="badge {{ $badgeClass }}" title="Utilisation /home">{{ $percent }}%</span>
                                        @endif
                                        @if ($isLargeProfile)
                                            <span class="badge badge-warning gap-1"
                                                title="Profil itinérant volumineux ({{ $profileMb }} Mo, seuil {{ $largeThreshold }} Mo)">
                                                <i class="fa-solid fa-folder-tree text-[10px]"></i>
                                                {{ $profileMb }} Mo
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="flex gap-1 flex-wrap">
                                        @if ($user->is_active)
                                            <span class="badge badge-success">Actif</span>
                                        @else
                                            <span class="badge badge-error">Inactif</span>
                                        @endif
                                        @if ($user->isExternal())
                                            <span class="badge badge-warning">Externe</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-8 text-base-content/60">
                                    Aucun utilisateur trouvé.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-base-300">
                {{ $this->users->links() }}
            </div>
        </div>

        @if (count($selectedUsers) > 0)
            <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
                <div class="card bg-base-100 shadow-xl border border-base-300">
                    <div class="card-body py-3 px-4 flex-row items-center gap-4">
                        <span class="text-sm font-medium">{{ count($selectedUsers) }} utilisateur(s)
                            sélectionné(s)</span>
                        <div class="divider divider-horizontal m-0"></div>
                        <div class="dropdown dropdown-top">
                            <label tabindex="0" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-cog"></i>
                                Actions
                                <i class="fa-solid fa-chevron-up ml-1"></i>
                            </label>
                            <ul tabindex="0"
                                class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-64 border border-base-300 mb-2">
                                <li>
                                    <button type="button"
                                        @click="Livewire.dispatch('open-groups-drawer', { users: $wire.selectedUsers }); document.activeElement.blur();">
                                        <i class="fa-solid fa-users"></i>
                                        Gérer les groupes
                                    </button>
                                </li>
                                @can('user.assign.right')
                                    <li>
                                        <button type="button"
                                            @click="Livewire.dispatch('open-rights-drawer', { users: $wire.selectedUsers }); document.activeElement.blur();">
                                            <i class="fa-solid fa-shield-halved"></i>
                                            Gérer les droits
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button"
                                            @click="Livewire.dispatch('open-delegation-modal', { users: $wire.selectedUsers }); document.activeElement.blur();">
                                            <i class="fa-solid fa-building"></i>
                                            Déléguer un droit sur une salle
                                        </button>
                                    </li>
                                @endcan
                                @can('user.password.init')
                                    <li>
                                        <button type="button"
                                            @click="Livewire.dispatch('open-password-reset-modal', { users: $wire.selectedUsers, groups: [] }); document.activeElement.blur();">
                                            <i class="fa-solid fa-key"></i>
                                            Réinitialiser les mots de passe
                                        </button>
                                    </li>
                                @endcan
                            </ul>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="clearSelectedUsers">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @else
        @php
            $groupsSearchLength = mb_strlen(trim($groupsSearch));
        @endphp

        <div class="card bg-base-100 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-base-300 text-sm text-base-content/70">
                @if ($groupsSearchLength >= 2)
                    Résultats filtrés sur "{{ $groupsSearch }}" ({{ $this->groups->total() }} résultat(s))
                @else
                    Affichage des groupes utilisateurs (page {{ $this->groups->currentPage() }})
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <input type="checkbox" class="checkbox" @checked(count($selectedUserGroups) > 0 && count($selectedUserGroups) === count($this->groups->items()))
                                    wire:click="{{ count($selectedUserGroups) > 0 ? 'clearSelectedGroups' : 'selectAllVisibleGroups' }}">
                            </th>
                            <th>Nom affiché</th>
                            <th>Nom technique</th>
                            <th>Type</th>
                            <th>Membres</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->groups as $groupItem)
                            <tr class="hover cursor-pointer"
                                onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.users.groups.edit', $groupItem->id) }}'">
                                <td class="checkbox-cell p-0">
                                    <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                        <input type="checkbox" class="checkbox" @checked(in_array($groupItem->id, $selectedUserGroups, true))
                                            wire:click.stop="toggleGroupSelection({{ $groupItem->id }})">
                                    </label>
                                </td>
                                <td>{{ $groupItem->display_name ?: $groupItem->name }}</td>
                                <td class="font-mono text-sm">{{ $groupItem->name }}</td>
                                <td>
                                    <span class="badge badge-outline">{{ $groupItem->type ?: '-' }}</span>
                                </td>
                                <td>
                                    <span class="badge badge-ghost">{{ $groupItem->users_count }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-8 text-base-content/60">
                                    Aucun groupe trouvé.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-base-300">
                {{ $this->groups->links() }}
            </div>
        </div>

        @if (count($selectedUserGroups) > 0)
            <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
                <div class="card bg-base-100 shadow-xl border border-base-300">
                    <div class="card-body py-3 px-4 flex-row items-center gap-4">
                        <span class="text-sm font-medium">{{ count($selectedUserGroups) }} groupe(s)
                            sélectionné(s)</span>
                        <div class="divider divider-horizontal m-0"></div>
                        <div class="dropdown dropdown-top">
                            <label tabindex="0" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-cog"></i>
                                Actions
                                <i class="fa-solid fa-chevron-up ml-1"></i>
                            </label>
                            <ul tabindex="0"
                                class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-64 border border-base-300 mb-2">
                                <li>
                                    <button type="button" wire:click="syncSelectedGroups">
                                        <i class="fa-solid fa-rotate text-info"></i>
                                        Resynchroniser AD
                                    </button>
                                </li>
                                @can('user.password.init')
                                    <li>
                                        <button type="button"
                                            @click="Livewire.dispatch('open-password-reset-modal', { users: [], groups: $wire.selectedUserGroups }); document.activeElement.blur();">
                                            <i class="fa-solid fa-key"></i>
                                            Réinitialiser les mots de passe
                                        </button>
                                    </li>
                                @endcan
                                <div class="divider my-1"></div>
                                <li>
                                    <button type="button" class="text-error" wire:click="deleteSelectedGroups"
                                        wire:confirm="Confirmer la suppression des groupes sélectionnés ?">
                                        <i class="fa-solid fa-trash"></i>
                                        Supprimer
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="clearSelectedGroups">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>
            </div>
        @endif

    @endif

    <livewire:components::organisms.groups-drawer />
    @livewire('pages::users._partials.rights-drawer')
    <livewire:pages::users._partials.delegation-modal />
    <livewire:pages::users.groups._partials.group-form-modal />
    <livewire:components::organisms.password-reset-modal />
    <livewire:components::organisms.password-reset-banner />
</x-organisms.page>
