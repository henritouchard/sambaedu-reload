<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\User as EloquentUser;
use App\Models\Delegation;
use App\Models\WorkstationGroup;
use App\Services\PermissionService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Log;

new #[Title('Gestion des droits - Instance SE4FS')] class extends Component {

    #[Url(as: 'tab')]
    public string $activeTab = 'overview';

    // Recherche utilisateur
    public string $userSearch = '';
    public array $foundUsers = [];
    public ?string $selectedUserLogin = null;
    public ?array $selectedUserDetails = null;

    // Données
    public array $rolesOverview = [];
    public array $delegationsOverview = [];
    public bool $dataLoaded = false;

    public function mount(): void
    {
    }

    public function loadData(): void
    {
        if ($this->dataLoaded) {
            return;
        }

        // Vue d'ensemble des rôles
        $this->rolesOverview = Role::where('guard_name', 'web')
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(function (Role $r) {
                $sambaRole = SambaRole::tryFrom($r->name);
                return [
                    'name' => $r->name,
                    'label' => $sambaRole?->label() ?? ucfirst($r->name),
                    'users_count' => $r->users_count,
                    'permissions' => $r->permissions->pluck('name')->toArray(),
                ];
            })
            ->toArray();

        // Délégations actives
        $this->delegationsOverview = Delegation::with(['user', 'workstationGroup', 'permission'])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn(Delegation $d) => [
                'id' => $d->id,
                'user_login' => $d->user->login ?? '?',
                'user_fullname' => $d->user->fullname ?? $d->user->login ?? '?',
                'workstation_group' => $d->workstationGroup->name ?? '?',
                'permission' => $d->permission->name ?? '?',
                'is_negative' => $d->is_negative,
                'expires_at' => $d->expires_at?->format('d/m/Y H:i'),
                'created_at' => $d->created_at?->format('d/m/Y H:i'),
            ])
            ->toArray();

        $this->dataLoaded = true;
    }

    public function searchUser(): void
    {
        if (strlen($this->userSearch) < 2) {
            $this->foundUsers = [];
            return;
        }

        $this->foundUsers = EloquentUser::where('login', 'ILIKE', "%{$this->userSearch}%")
            ->orWhere('fullname', 'ILIKE', "%{$this->userSearch}%")
            ->limit(10)
            ->get()
            ->map(fn(EloquentUser $u) => [
                'login' => $u->login,
                'fullname' => $u->fullname ?? $u->login,
                'role' => $u->role,
                'roles_spatie' => $u->roles->pluck('name')->toArray(),
            ])
            ->toArray();
    }

    public function selectUser(string $login): void
    {
        $user = EloquentUser::where('login', $login)->first();
        if (!$user) {
            return;
        }

        $this->selectedUserLogin = $login;

        $permissionService = app(PermissionService::class);

        // Permissions directes
        $directPermissions = $user->getDirectPermissions()->pluck('name')->toArray();

        // Permissions via rôles
        $rolePermissions = $user->getPermissionsViaRoles()->pluck('name')->toArray();

        // Toutes les permissions effectives
        $allPermissions = $user->getAllPermissions()->pluck('name')->toArray();

        // Délégations
        $delegations = $permissionService->getUserDelegations($user)
            ->map(fn(Delegation $d) => [
                'id' => $d->id,
                'workstation_group' => $d->workstationGroup->name ?? '?',
                'permission' => $d->permission->name ?? '?',
                'is_negative' => $d->is_negative,
                'expires_at' => $d->expires_at?->format('d/m/Y H:i'),
            ])
            ->toArray();

        // Bitmask legacy
        $bitmask = $permissionService->permissionsToBitmask($user);

        $this->selectedUserDetails = [
            'login' => $user->login,
            'fullname' => $user->fullname ?? $user->login,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'roles_spatie' => $user->roles->pluck('name')->toArray(),
            'direct_permissions' => $directPermissions,
            'role_permissions' => $rolePermissions,
            'all_permissions' => $allPermissions,
            'delegations' => $delegations,
            'bitmask' => sprintf('0x%04X', $bitmask),
            'ad_synced_at' => $user->ad_synced_at?->format('d/m/Y H:i'),
        ];

        $this->foundUsers = [];
        $this->userSearch = '';
    }

    public function clearSelectedUser(): void
    {
        $this->selectedUserLogin = null;
        $this->selectedUserDetails = null;
    }

    public function revokeDelegation(int $delegationId): void
    {
        $delegation = Delegation::find($delegationId);
        if (!$delegation) {
            return;
        }

        $permissionService = app(PermissionService::class);
        $user = $delegation->user;
        $group = $delegation->workstationGroup;
        $permName = $delegation->permission->name;

        $permissionService->revokeDelegation($user, $permName, $group);

        // Rafraîchir les détails
        if ($this->selectedUserLogin) {
            $this->selectUser($this->selectedUserLogin);
        }

        // Rafraîchir la vue d'ensemble
        $this->dataLoaded = false;
        $this->loadData();
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

};
?>

<x-organisms.page title="Gestion des droits" :scrollable="false"
    description="Gérez les rôles, permissions et délégations des utilisateurs">

    <div wire:init="loadData">

        {{-- Navigation par onglets --}}
        <div class="flex-shrink-0 tabs tabs-bordered mb-4">
            <button wire:click="setActiveTab('overview')"
                class="tab tab-lg {{ $activeTab === 'overview' ? 'tab-active' : '' }}">
                <i class="fa-solid fa-chart-pie mr-2"></i>
                Vue d'ensemble
            </button>
            <button wire:click="setActiveTab('user-lookup')"
                class="tab tab-lg {{ $activeTab === 'user-lookup' ? 'tab-active' : '' }}">
                <i class="fa-solid fa-user-magnifying-glass mr-2"></i>
                Droits d'un utilisateur
            </button>
            <button wire:click="setActiveTab('delegations')"
                class="tab tab-lg {{ $activeTab === 'delegations' ? 'tab-active' : '' }}">
                <i class="fa-solid fa-building mr-2"></i>
                Délégations actives
            </button>
        </div>

        {{-- ============================================================ --}}
        {{-- ONGLET VUE D'ENSEMBLE --}}
        {{-- ============================================================ --}}
        @if ($activeTab === 'overview')
            @if (!$dataLoaded)
                <div class="card flex justify-center items-center py-16">
                    <span class="loading loading-spinner loading-lg text-primary mb-4"></span>
                    <p class="text-base-content/60">Chargement des données...</p>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($rolesOverview as $role)
                        <div class="card bg-base-100 border border-base-300 shadow-sm">
                            <div class="card-body p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="font-bold text-sm">{{ $role['label'] }}</h3>
                                    <div class="badge badge-primary badge-sm">{{ $role['users_count'] }} utilisateur(s)</div>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($role['permissions'] as $perm)
                                        <span class="badge badge-outline badge-xs">{{ $perm }}</span>
                                    @endforeach
                                    @if (empty($role['permissions']))
                                        <span class="text-xs text-base-content/40">Aucune permission</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Stats rapides --}}
                <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="stat bg-base-100 rounded-lg border border-base-300 shadow-sm">
                        <div class="stat-figure text-primary">
                            <i class="fa-solid fa-users text-2xl"></i>
                        </div>
                        <div class="stat-title">Utilisateurs avec droits</div>
                        <div class="stat-value text-primary">
                            {{ \App\Models\User::whereHas('roles')->count() }}
                        </div>
                        <div class="stat-desc">sur {{ \App\Models\User::count() }} synchronisés</div>
                    </div>
                    <div class="stat bg-base-100 rounded-lg border border-base-300 shadow-sm">
                        <div class="stat-figure text-secondary">
                            <i class="fa-solid fa-building text-2xl"></i>
                        </div>
                        <div class="stat-title">Délégations actives</div>
                        <div class="stat-value text-secondary">{{ count($delegationsOverview) }}</div>
                        <div class="stat-desc">sur {{ count($availableWorkstationGroups ?? []) }} salles</div>
                    </div>
                    <div class="stat bg-base-100 rounded-lg border border-base-300 shadow-sm">
                        <div class="stat-figure text-accent">
                            <i class="fa-solid fa-key text-2xl"></i>
                        </div>
                        <div class="stat-title">Permissions définies</div>
                        <div class="stat-value text-accent">{{ \Spatie\Permission\Models\Permission::count() }}</div>
                        <div class="stat-desc">{{ \Spatie\Permission\Models\Role::count() }} rôles</div>
                    </div>
                </div>
            @endif
        @endif

        {{-- ============================================================ --}}
        {{-- ONGLET DROITS D'UN UTILISATEUR --}}
        {{-- ============================================================ --}}
        @if ($activeTab === 'user-lookup')
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    {{-- Recherche --}}
                    <div class="flex gap-3 items-end mb-4">
                        <div class="flex-1">
                            <label class="text-sm font-medium mb-1 block">Rechercher un utilisateur</label>
                            <label class="input w-full">
                                <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                                <input type="text" wire:model.live.debounce.300ms="userSearch"
                                    wire:keyup="searchUser"
                                    placeholder="Login ou nom..." class="grow" />
                            </label>
                        </div>
                        @if ($selectedUserLogin)
                            <button class="btn btn-ghost btn-sm" wire:click="clearSelectedUser">
                                <i class="fa-solid fa-xmark"></i> Effacer
                            </button>
                        @endif
                    </div>

                    {{-- Résultats de recherche --}}
                    @if (!empty($foundUsers))
                        <div class="border rounded-lg divide-y divide-base-200 mb-4 max-h-60 overflow-y-auto">
                            @foreach ($foundUsers as $u)
                                <button type="button"
                                    class="w-full flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 text-left transition-colors"
                                    wire:click="selectUser('{{ $u['login'] }}')">
                                    <div class="flex-1">
                                        <div class="font-medium text-sm">{{ $u['fullname'] }}</div>
                                        <div class="text-xs text-base-content/50 font-mono">{{ $u['login'] }}</div>
                                    </div>
                                    <div class="flex gap-1">
                                        @foreach ($u['roles_spatie'] as $r)
                                            <span class="badge badge-primary badge-xs">{{ $r }}</span>
                                        @endforeach
                                        @if (empty($u['roles_spatie']))
                                            <span class="badge badge-ghost badge-xs">{{ $u['role'] }}</span>
                                        @endif
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    {{-- Détails de l'utilisateur sélectionné --}}
                    @if ($selectedUserDetails)
                        <div class="space-y-4">
                            {{-- En-tête utilisateur --}}
                            <div class="flex items-center gap-4 p-4 bg-base-200/50 rounded-lg">
                                <div class="flex-1">
                                    <h3 class="text-lg font-bold">{{ $selectedUserDetails['fullname'] }}</h3>
                                    <div class="text-sm text-base-content/60 font-mono">{{ $selectedUserDetails['login'] }}</div>
                                </div>
                                <div class="text-right text-sm">
                                    <div>Rôle AD : <span class="badge badge-ghost badge-sm">{{ $selectedUserDetails['role'] }}</span></div>
                                    <div>Bitmask : <span class="font-mono text-xs">{{ $selectedUserDetails['bitmask'] }}</span></div>
                                    @if ($selectedUserDetails['ad_synced_at'])
                                        <div class="text-xs text-base-content/40">Sync AD : {{ $selectedUserDetails['ad_synced_at'] }}</div>
                                    @endif
                                </div>
                            </div>

                            {{-- Rôles Spatie --}}
                            <div>
                                <h4 class="text-sm font-bold mb-2">
                                    <i class="fa-solid fa-user-tag mr-1 text-primary"></i> Rôles
                                </h4>
                                <div class="flex flex-wrap gap-2">
                                    @forelse ($selectedUserDetails['roles_spatie'] as $r)
                                        <span class="badge badge-primary">{{ $r }}</span>
                                    @empty
                                        <span class="text-sm text-base-content/40">Aucun rôle assigné</span>
                                    @endforelse
                                </div>
                            </div>

                            {{-- Permissions --}}
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <h4 class="text-sm font-bold mb-2">
                                        <i class="fa-solid fa-key mr-1 text-secondary"></i> Permissions directes
                                    </h4>
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($selectedUserDetails['direct_permissions'] as $p)
                                            <span class="badge badge-secondary badge-sm">{{ $p }}</span>
                                        @empty
                                            <span class="text-xs text-base-content/40">Aucune</span>
                                        @endforelse
                                    </div>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold mb-2">
                                        <i class="fa-solid fa-key mr-1 text-accent"></i> Permissions via rôles
                                    </h4>
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($selectedUserDetails['role_permissions'] as $p)
                                            <span class="badge badge-accent badge-sm badge-outline">{{ $p }}</span>
                                        @empty
                                            <span class="text-xs text-base-content/40">Aucune</span>
                                        @endforelse
                                    </div>
                                </div>
                            </div>

                            {{-- Délégations --}}
                            <div>
                                <h4 class="text-sm font-bold mb-2">
                                    <i class="fa-solid fa-building mr-1 text-warning"></i> Délégations
                                </h4>
                                @if (!empty($selectedUserDetails['delegations']))
                                    <div class="overflow-x-auto">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Salle</th>
                                                    <th>Permission</th>
                                                    <th>Type</th>
                                                    <th>Expiration</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($selectedUserDetails['delegations'] as $d)
                                                    <tr>
                                                        <td class="font-medium">{{ $d['workstation_group'] }}</td>
                                                        <td><span class="font-mono text-xs">{{ $d['permission'] }}</span></td>
                                                        <td>
                                                            @if ($d['is_negative'])
                                                                <span class="badge badge-error badge-xs">Exclusion</span>
                                                            @else
                                                                <span class="badge badge-success badge-xs">Accordée</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-xs">{{ $d['expires_at'] ?? 'Permanente' }}</td>
                                                        <td>
                                                            <button class="btn btn-ghost btn-xs text-error"
                                                                wire:click="revokeDelegation({{ $d['id'] }})"
                                                                wire:confirm="Révoquer cette délégation ?">
                                                                <i class="fa-solid fa-trash-can"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <span class="text-sm text-base-content/40">Aucune délégation active</span>
                                @endif
                            </div>
                        </div>
                    @elseif (!$selectedUserLogin && empty($foundUsers))
                        <div class="text-center py-8">
                            <div class="text-4xl mb-4 opacity-20"><i class="fa-solid fa-user-magnifying-glass"></i></div>
                            <p class="text-base-content/60">Recherchez un utilisateur pour voir ses droits détaillés.</p>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- ONGLET DÉLÉGATIONS ACTIVES --}}
        {{-- ============================================================ --}}
        @if ($activeTab === 'delegations')
            @if (!$dataLoaded)
                <div class="card flex justify-center items-center py-16">
                    <span class="loading loading-spinner loading-lg text-primary mb-4"></span>
                    <p class="text-base-content/60">Chargement...</p>
                </div>
            @elseif (empty($delegationsOverview))
                <div class="card bg-base-100 border border-base-300 shadow-sm">
                    <div class="card-body text-center py-12">
                        <div class="text-4xl mb-4 opacity-20"><i class="fa-solid fa-building"></i></div>
                        <h3 class="text-lg font-semibold mb-2">Aucune délégation active</h3>
                        <p class="text-base-content/60 max-w-md mx-auto">
                            Les délégations permettent d'accorder des droits limités à une salle physique.
                            Utilisez la page <strong>Utilisateurs</strong> pour sélectionner des utilisateurs et leur accorder des délégations.
                        </p>
                    </div>
                </div>
            @else
                <div class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Utilisateur</th>
                                    <th>Salle</th>
                                    <th>Permission</th>
                                    <th>Type</th>
                                    <th>Expiration</th>
                                    <th>Créée le</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($delegationsOverview as $d)
                                    <tr class="hover:bg-base-200/30">
                                        <td>
                                            <div class="font-medium text-sm">{{ $d['user_fullname'] }}</div>
                                            <div class="text-xs text-base-content/50 font-mono">{{ $d['user_login'] }}</div>
                                        </td>
                                        <td class="font-medium">{{ $d['workstation_group'] }}</td>
                                        <td><span class="font-mono text-xs">{{ $d['permission'] }}</span></td>
                                        <td>
                                            @if ($d['is_negative'])
                                                <span class="badge badge-error badge-xs">Exclusion</span>
                                            @else
                                                <span class="badge badge-success badge-xs">Accordée</span>
                                            @endif
                                        </td>
                                        <td class="text-xs">{{ $d['expires_at'] ?? 'Permanente' }}</td>
                                        <td class="text-xs text-base-content/50">{{ $d['created_at'] }}</td>
                                        <td>
                                            <button class="btn btn-ghost btn-xs text-error"
                                                wire:click="revokeDelegation({{ $d['id'] }})"
                                                wire:confirm="Révoquer cette délégation ?">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif

    </div>
</x-organisms.page>
