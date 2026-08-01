<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use App\Components\Traits\WithToasts;
use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\User as EloquentUser;
use App\Services\GroupRightsProfileService;
use App\Services\UserService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Drawer "Gérer les droits" : Rôles + Permissions globales non-scopées.
 *
 * Story 7.1.bis : le tab Délégations a été extrait dans la modale
 * `pages/users/_partials/delegation-modal.blade.php` (UX état→action qui
 * nécessitait un format plus large + un flux séquentiel distinct).
 */
new class extends Component {
    use WithToasts;

    public bool $isOpen = false;
    public string $activeTab = 'roles';

    // Utilisateurs sélectionnés (logins AD)
    public array $selectedUsers = [];

    // Onglet Rôles
    public ?string $selectedRole = null;
    public bool $removeRole = false;

    // Onglet Permissions
    public array $selectedPermissions = [];
    public bool $removePermissions = false;
    public string $permissionSearch = '';

    // Données chargées
    public array $availableRoles = [];
    public array $availablePermissions = [];
    public array $permissionCategories = [];

    // État de chargement
    public bool $processing = false;

    public function mount(): void
    {
        $this->loadAvailableData();
    }

    private function loadAvailableData(): void
    {
        // Story 7.2 — la source des rôles est la table Spatie (et non plus
        // l'enum statique SambaRole) pour inclure les profils customs créés
        // depuis /app/rights-management ou rapatriés via la sync AD. Sans ça,
        // un profil custom existait en base mais n'apparaissait pas ici, donc
        // ne pouvait pas être assigné.
        // Story 49.1 (AC8) — l'état « porté » est DÉRIVÉ en lecture (jointure
        // `user_groups.rights_profile_id` → `roles`), aucune colonne ajoutée
        // sur `model_has_roles`, aucune persistance.
        $carriers = app(GroupRightsProfileService::class)->carriersByRoleId();

        $this->availableRoles = \Spatie\Permission\Models\Role::where('guard_name', 'web')
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(function ($r) use ($carriers) {
                $enumCase = SambaRole::tryFrom($r->name);
                return [
                    'name' => $r->name,
                    'label' => $enumCase?->label() ?? $r->name,
                    'is_seeded' => SambaRole::isSeeded($r->name),
                    'permissions_count' => $r->permissions->count(),
                    'permissions' => $r->permissions->pluck('name')->toArray(),
                    // Groupes portant ce profil — non vide ⇒ rôle NON attribuable
                    // et NON décochable ici (AC8).
                    'carried_by' => $carriers[(int) $r->id] ?? [],
                ];
            })
            ->toArray();

        $this->availablePermissions = collect(SambaPermission::cases())
            ->map(fn(SambaPermission $p) => [
                'name' => $p->value,
                'label' => $p->label(),
                'category' => $p->category(),
                'is_delegatable' => $p->isDelegatable(),
                'requires_gpo' => $p->requiresGpoSync(),
            ])
            ->toArray();

        $this->permissionCategories = collect(SambaPermission::groupedByCategory())
            ->map(fn(array $group) => [
                'label' => $group['label'],
                'permissions' => collect($group['permissions'])
                    ->map(fn(SambaPermission $p) => [
                        'name' => $p->value,
                        'label' => $p->label(),
                        'category' => $p->category(),
                    ])
                    ->toArray(),
            ])
            ->toArray();
    }

    #[On('open-rights-drawer')]
    public function open(array $users = []): void
    {
        // Story 7.1 — Review #5b : guard serveur sur l'ouverture du drawer.
        // Empêche un user non-admin de déclencher le drawer via `Livewire.dispatch`.
        abort_unless(Gate::allows('user.assign.right'), 403);

        $this->selectedUsers = $users;
        $this->isOpen = true;
        $this->resetForm();
        $this->loadAvailableData();
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->activeTab = 'roles';
        $this->selectedRole = null;
        $this->removeRole = false;
        $this->selectedPermissions = [];
        $this->removePermissions = false;
        $this->permissionSearch = '';
        $this->processing = false;
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    // ========================================================================
    // ACTIONS : Rôles
    // ========================================================================

    public function applyRoles(): void
    {
        // Story 7.1 — Review #5b : guard serveur — bloque tout appel Livewire forgé.
        abort_unless(Gate::allows('user.assign.right'), 403);

        if (empty($this->selectedUsers)) {
            $this->toastError('Aucun utilisateur sélectionné.');
            return;
        }

        if ($this->selectedRole === null) {
            $this->toastError('Veuillez sélectionner un rôle.');
            return;
        }

        // Story 49.1 (AC8 / D8) — garde SERVEUR : un profil porté par au moins
        // un groupe n'est ni attribuable ni décochable ici, quel que soit le
        // payload reçu. Le `disabled` de l'UI seul serait du théâtre : un
        // payload Livewire forgé écrirait, et la réconciliation (≤ 5 min de
        // sync delta) ferait un retrait silencieux — exactement le sinistre que
        // l'AC prévient. Le RETRAIT est bloqué symétriquement : décocher un
        // profil porté serait re-posé à la réconciliation suivante.
        $carriers = $this->carriersFor($this->selectedRole);
        if (!empty($carriers)) {
            $this->toastError(
                "Le profil « {$this->selectedRole} » est porté par le(s) groupe(s) "
                . implode(', ', $carriers)
                . ' — pour donner ce profil, ajoutez l\'utilisateur au groupe.'
            );
            return;
        }

        $this->processing = true;
        $count = 0;
        $errors = 0;
        $protectedSkipped = 0;

        foreach ($this->selectedUsers as $login) {
            try {
                $user = $this->ensureEloquentUser($login);
                if (!$user) {
                    $errors++;
                    continue;
                }

                // Le modèle refuse le retrait sur ce compte ; écarté ici pour
                // porter un message explicite.
                if ($this->removeRole && $user->isProtectedAdmin()) {
                    $protectedSkipped++;
                    continue;
                }

                if ($this->removeRole) {
                    $user->removeRole($this->selectedRole);
                } else {
                    $user->assignRole($this->selectedRole);
                }
                $count++;
            } catch (\Exception $e) {
                Log::error("[RightsDrawer] Erreur rôle pour {$login}: " . $e->getMessage());
                $errors++;
            }
        }

        $action = $this->removeRole ? 'retiré de' : 'assigné à';
        $message = "Rôle '{$this->selectedRole}' {$action} {$count} utilisateur(s).";
        if ($protectedSkipped > 0) {
            $message .= " Le compte d'administration protégé a été ignoré : ses droits ne peuvent pas être retirés.";
        }
        if ($errors > 0) {
            $message .= " ({$errors} erreur(s))";
            $this->toastWarning($message);
        } elseif ($protectedSkipped > 0) {
            $this->toastWarning($message);
        } else {
            $this->toastSuccess($message);
        }

        // Notifie les pages parentes (ex: profil utilisateur) pour rafraîchir
        // l'affichage des rôles/permissions sans rechargement complet.
        $this->dispatch('rights-applied');

        $this->processing = false;
    }

    // ========================================================================
    // ACTIONS : Permissions
    // ========================================================================

    public function togglePermission(string $permissionName): void
    {
        if (in_array($permissionName, $this->selectedPermissions)) {
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, [$permissionName]));
        } else {
            $this->selectedPermissions[] = $permissionName;
        }
    }

    public function applyPermissions(): void
    {
        // Story 7.1 — Review #5b : guard serveur.
        abort_unless(Gate::allows('user.assign.right'), 403);

        if (empty($this->selectedUsers)) {
            $this->toastError('Aucun utilisateur sélectionné.');
            return;
        }

        if (empty($this->selectedPermissions)) {
            $this->toastError('Veuillez sélectionner au moins une permission.');
            return;
        }

        $this->processing = true;
        $count = 0;
        $errors = 0;
        $protectedSkipped = 0;

        foreach ($this->selectedUsers as $login) {
            try {
                $user = $this->ensureEloquentUser($login);
                if (!$user) {
                    $errors++;
                    continue;
                }

                // Les permissions du compte protégé sont DIRECTES : révocables
                // sans toucher à ses rôles.
                if ($this->removePermissions && $user->isProtectedAdmin()) {
                    $protectedSkipped++;
                    continue;
                }

                if ($this->removePermissions) {
                    $user->revokePermissionTo($this->selectedPermissions);
                } else {
                    $user->givePermissionTo($this->selectedPermissions);
                }
                $count++;
            } catch (\Exception $e) {
                Log::error("[RightsDrawer] Erreur permissions pour {$login}: " . $e->getMessage());
                $errors++;
            }
        }

        $nb = count($this->selectedPermissions);
        $action = $this->removePermissions ? 'retirée(s) de' : 'accordée(s) à';
        $message = "{$nb} permission(s) {$action} {$count} utilisateur(s).";
        if ($protectedSkipped > 0) {
            $message .= " Le compte d'administration protégé a été ignoré : ses droits ne peuvent pas être retirés.";
        }
        if ($errors > 0) {
            $message .= " ({$errors} erreur(s))";
            $this->toastWarning($message);
        } elseif ($protectedSkipped > 0) {
            $this->toastWarning($message);
        } else {
            $this->toastSuccess($message);
        }

        $this->dispatch('rights-applied');

        $this->processing = false;
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Assure qu'un utilisateur Eloquent existe pour un login AD.
     *
     * Story 7.1 — Review #A : avant de créer un EloquentUser fantôme, on vérifie
     * que le login existe réellement dans l'annuaire (AD). Sans ça, un admin
     * peut injecter n'importe quel login dans `selectedUsers` → création d'un
     * enregistrement DB orphelin (aucun objet AD correspondant).
     *
     * Comportement :
     *  - login existe en base SQL → retourne l'user existant (fast path).
     *  - login absent de la base SQL mais présent dans l'AD → création + return.
     *  - login absent des deux → toast warning + return null (skip, l'appelant
     *    incrémente `$errors`).
     */
    private function ensureEloquentUser(string $login): ?EloquentUser
    {
        $user = EloquentUser::where('login', $login)->first();

        if ($user) {
            return $user;
        }

        // Vérification AD : le login existe-t-il dans l'annuaire ?
        try {
            $adUser = app(UserService::class)->getByLogin($login);
        } catch (\Throwable $e) {
            Log::warning("[RightsDrawer] Échec lookup AD pour {$login}: " . $e->getMessage());
            $adUser = null;
        }

        if (!$adUser) {
            $this->toastWarning("Utilisateur {$login} introuvable dans l'annuaire.");
            return null;
        }

        // Créer un enregistrement minimal — sera enrichi par sambaedu:sync-rights
        return EloquentUser::create([
            'login' => $login,
            'role' => 'autre',
            'is_active' => true,
        ]);
    }

    #[Computed]
    public function filteredPermissions(): array
    {
        if (empty($this->permissionSearch)) {
            return $this->permissionCategories;
        }

        $search = strtolower($this->permissionSearch);

        return collect($this->permissionCategories)
            ->map(function ($category) use ($search) {
                $filtered = collect($category['permissions'])->filter(function ($perm) use ($search) {
                    return str_contains(strtolower($perm['name']), $search)
                        || str_contains(strtolower($perm['label']), $search);
                })->toArray();

                return array_merge($category, ['permissions' => $filtered]);
            })
            ->filter(fn($cat) => !empty($cat['permissions']))
            ->toArray();
    }

    /**
     * Story 7.1.bis — état tri-state par permission pour les users sélectionnés.
     *
     * Pour chaque permission, décompte parmi `$selectedUsers` :
     *   - direct : l'user l'a assignée directement (revokable)
     *   - via_role : l'user l'a uniquement via un rôle (non revokable individuellement)
     *   - none : l'user ne l'a pas
     *
     * L'état tri-state dérive du total avec/sans :
     *   - 'none'  → 0 user l'a
     *   - 'all'   → tous les users l'ont (direct OU via rôle)
     *   - 'some'  → entre les deux (indeterminate)
     *
     * Les users absents de la table SQL (présents en AD mais non encore créés
     * par `ensureEloquentUser`) sont comptés comme "sans permission" : c'est
     * cohérent avec leur état Spatie réel (aucun rôle/perm attaché).
     *
     * @return array<string, array{state:string, direct:int, via_role:int, none:int, total:int}>
     */
    #[Computed]
    public function permissionStates(): array
    {
        $total = count($this->selectedUsers);
        if ($total === 0) {
            return [];
        }

        $users = EloquentUser::whereIn('login', $this->selectedUsers)
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->keyBy('login');

        $states = [];
        foreach (SambaPermission::cases() as $perm) {
            $name = $perm->value;
            $direct = 0;
            $viaRole = 0;

            foreach ($this->selectedUsers as $login) {
                $user = $users->get($login);
                if (!$user) {
                    continue;
                }
                if ($user->permissions->contains('name', $name)) {
                    $direct++;
                    continue;
                }
                $hasViaRole = $user->roles->contains(
                    fn($role) => $role->permissions->contains('name', $name)
                );
                if ($hasViaRole) {
                    $viaRole++;
                }
            }

            $has = $direct + $viaRole;
            $state = match (true) {
                $has === 0 => 'none',
                $has === $total => 'all',
                default => 'some',
            };

            $states[$name] = [
                'state' => $state,
                'direct' => $direct,
                'via_role' => $viaRole,
                'none' => $total - $has,
                'total' => $total,
            ];
        }

        return $states;
    }

    /**
     * Story 49.1 (AC8) — noms des groupes portant un profil donné, relus EN
     * BASE au moment du geste (pas depuis l'état Livewire, qui pourrait être
     * forgé ou périmé).
     *
     * @return string[]
     */
    private function carriersFor(string $roleName): array
    {
        $role = \Spatie\Permission\Models\Role::where('name', $roleName)
            ->where('guard_name', 'web')
            ->first();

        if (!$role) {
            return [];
        }

        return app(GroupRightsProfileService::class)->carrierNames((int) $role->id);
    }

    /**
     * Story 7.1.bis — compte le nombre d'users sélectionnés ayant chaque rôle.
     *
     * Story 49.1 (AC8) — enrichi de `carried_by` : les groupes portant ce
     * profil. Non vide ⇒ contrôle désactivé + raison affichée.
     *
     * @return array<string, array{state:string, has:int, total:int, carried_by:string[]}>
     */
    #[Computed]
    public function roleStates(): array
    {
        $total = count($this->selectedUsers);
        if ($total === 0) {
            return [];
        }

        $users = EloquentUser::whereIn('login', $this->selectedUsers)
            ->with('roles')
            ->get()
            ->keyBy('login');

        // Story 7.2 — itère sur tous les rôles Spatie connus, pas seulement
        // les rôles seedés ; sinon les profils customs apparaissent toujours
        // avec un badge "Aucun" même quand un user les porte.
        $roleNames = array_column($this->availableRoles, 'name');
        $carriedBy = array_column($this->availableRoles, 'carried_by', 'name');

        $states = [];
        foreach ($roleNames as $name) {
            $has = 0;
            foreach ($this->selectedUsers as $login) {
                $user = $users->get($login);
                if ($user && $user->roles->contains('name', $name)) {
                    $has++;
                }
            }

            $states[$name] = [
                'state' => match (true) {
                    $has === 0 => 'none',
                    $has === $total => 'all',
                    default => 'some',
                },
                'has' => $has,
                'total' => $total,
                'carried_by' => $carriedBy[$name] ?? [],
            ];
        }

        return $states;
    }

};
?>

<div>
    <div class="drawer drawer-end z-[60]" x-data="{ open: @entangle('isOpen') }">
        <input type="checkbox" class="drawer-toggle" :checked="open" />
        <div class="drawer-side z-[60]" x-show="open" x-cloak>
            <label class="drawer-overlay" wire:click="close"></label>
            <div class="bg-base-200 h-screen w-[480px] flex flex-col z-[60]">

                {{-- Header --}}
                <div class="bg-base-100 p-4 border-b border-base-300 shrink-0">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold">Gestion des droits</h3>
                            <p class="text-sm text-base-content/60">
                                {{ count($selectedUsers) }} utilisateur(s) sélectionné(s)
                            </p>
                        </div>
                        <button wire:click="close" class="btn btn-sm btn-circle btn-ghost">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>

                {{-- Story 7.1 : feedback via WithToasts (ToastMagic) — plus de bloc HTML local --}}

                {{-- Tabs --}}
                <div role="tablist" class="tabs tabs-bordered px-4 pt-2 shrink-0">
                    <a role="tab" class="tab {{ $activeTab === 'roles' ? 'tab-active' : '' }}"
                        wire:click="switchTab('roles')">
                        <i class="fa-solid fa-user-tag mr-1"></i> Rôles
                    </a>
                    <a role="tab" class="tab {{ $activeTab === 'permissions' ? 'tab-active' : '' }}"
                        wire:click="switchTab('permissions')">
                        <i class="fa-solid fa-key mr-1"></i> Permissions
                    </a>
                </div>

                {{-- ============================================================ --}}
                {{-- ONGLET RÔLES --}}
                {{-- ============================================================ --}}
                <div class="flex-1 overflow-hidden flex flex-col p-4 {{ $activeTab !== 'roles' ? 'hidden' : '' }}">
                    <p class="text-sm text-base-content/70 mb-3">
                        Assigner un rôle prédéfini. Le rôle remplace les rôles existants de l'utilisateur.
                    </p>

                    {{-- Toggle retirer --}}
                    <div class="flex gap-3 shrink-0 mb-4">
                        <input type="checkbox" wire:model.live="removeRole"
                            class="toggle toggle-sm border-primary checked:border-error/50 checked:bg-error/50" />
                        <div class="text-sm font-medium">
                            {{ $removeRole ? 'Retirer le rôle sélectionné' : 'Assigner le rôle sélectionné' }}
                        </div>
                    </div>

                    {{-- Liste des rôles --}}
                    <div class="flex-1 overflow-y-auto min-h-0 space-y-2 mb-4">
                        @foreach ($availableRoles as $role)
                            @php
                                $rState = $this->roleStates[$role['name']] ?? ['state' => 'none', 'has' => 0, 'total' => count($selectedUsers), 'carried_by' => $role['carried_by'] ?? []];
                                // Story 49.1 (AC8) — profil PORTÉ par un groupe :
                                // ni attribuable ni décochable ici (le geste est
                                // l'ajout au groupe).
                                $carriedBy = $role['carried_by'] ?? [];
                                $isCarried = !empty($carriedBy);
                            @endphp
                            <label
                                class="flex items-center gap-3 p-3 rounded-lg transition-colors
                                    {{ $isCarried ? 'cursor-not-allowed opacity-70 bg-base-100 border border-base-300' : 'cursor-pointer ' . ($selectedRole === $role['name'] ? 'bg-primary/10 border border-primary/30' : 'bg-base-100 hover:bg-base-300/50 border border-transparent') }}">
                                <input type="radio" wire:model.live="selectedRole" value="{{ $role['name'] }}"
                                    @disabled($isCarried)
                                    class="radio radio-primary radio-sm" />
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-sm">{{ $role['label'] }}</span>
                                        @if ($isCarried)
                                            <span class="badge badge-warning badge-xs"
                                                title="Profil porté par un groupe : l'appartenance l'attribue automatiquement">
                                                <i class="fa-solid fa-users-rectangle mr-1"></i>
                                                porté par un groupe
                                            </span>
                                        @endif
                                        @if (!($role['is_seeded'] ?? true))
                                            <span class="badge badge-accent badge-xs"
                                                title="Profil personnalisé créé via /app/rights-management ou rapatrié AD">
                                                personnalisé
                                            </span>
                                        @endif
                                        @if ($rState['total'] > 0)
                                            @php
                                                $badgeClass = match ($rState['state']) {
                                                    'all' => 'badge-success',
                                                    'some' => 'badge-warning',
                                                    default => 'badge-ghost',
                                                };
                                                $badgeLabel = match ($rState['state']) {
                                                    'all' => 'Tous',
                                                    'none' => 'Aucun',
                                                    default => $rState['has'] . '/' . $rState['total'],
                                                };
                                            @endphp
                                            <span class="badge badge-xs {{ $badgeClass }}"
                                                title="{{ $rState['has'] }}/{{ $rState['total'] }} utilisateur(s) ont ce rôle">
                                                {{ $badgeLabel }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-base-content/50">
                                        {{ $role['permissions_count'] }} permission(s) :
                                        {{ implode(', ', array_slice($role['permissions'], 0, 3)) }}
                                        @if (count($role['permissions']) > 3)
                                            <span class="text-primary">+{{ count($role['permissions']) - 3 }}</span>
                                        @endif
                                    </div>
                                    @if ($isCarried)
                                        <div class="text-xs text-warning mt-1">
                                            Porté par le(s) groupe(s) {{ implode(', ', $carriedBy) }} — pour donner
                                            ce profil, ajoutez l'utilisateur au groupe.
                                        </div>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>

                    {{-- Actions --}}
                    <div class="flex justify-between items-center shrink-0 pt-3 border-t border-base-300">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="close">Annuler</button>
                        <button type="button"
                            class="btn btn-sm {{ $removeRole ? 'btn-error' : 'btn-primary' }}"
                            wire:click="applyRoles"
                            wire:loading.attr="disabled"
                            @disabled($selectedRole === null)>
                            <span wire:loading.remove wire:target="applyRoles">
                                <i class="fa-solid {{ $removeRole ? 'fa-minus' : 'fa-check' }}"></i>
                                {{ $removeRole ? 'Retirer' : 'Assigner' }} le rôle
                            </span>
                            <span wire:loading wire:target="applyRoles">
                                <span class="loading loading-spinner loading-xs"></span> Traitement...
                            </span>
                        </button>
                    </div>
                </div>

                {{-- ============================================================ --}}
                {{-- ONGLET PERMISSIONS --}}
                {{-- ============================================================ --}}
                <div class="flex-1 overflow-hidden flex flex-col p-4 {{ $activeTab !== 'permissions' ? 'hidden' : '' }}">
                    <p class="text-sm text-base-content/70 mb-3">
                        Accorder ou retirer des permissions individuelles (globales, non scopées).
                    </p>

                    {{-- Toggle retirer --}}
                    <div class="flex gap-3 shrink-0 mb-3">
                        <input type="checkbox" wire:model.live="removePermissions"
                            class="toggle toggle-sm border-primary checked:border-error/50 checked:bg-error/50" />
                        <div class="text-sm font-medium">
                            {{ $removePermissions ? 'Retirer les permissions' : 'Accorder les permissions' }}
                        </div>
                    </div>

                    {{-- Recherche --}}
                    <div class="mb-3 shrink-0">
                        <label class="input input-sm w-full">
                            <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                            <input type="text" wire:model.live.debounce.200ms="permissionSearch"
                                placeholder="Rechercher une permission..." class="grow" />
                            @if ($permissionSearch)
                                <button type="button" wire:click="$set('permissionSearch', '')"
                                    class="btn btn-ghost btn-xs btn-circle">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            @endif
                        </label>
                    </div>

                    {{-- Compteur --}}
                    @if (count($selectedPermissions) > 0)
                        <div class="text-xs text-primary font-medium mb-2 shrink-0">
                            {{ count($selectedPermissions) }} permission(s) sélectionnée(s)
                        </div>
                    @endif

                    {{-- Liste par catégorie --}}
                    <div class="flex-1 overflow-y-auto min-h-0 space-y-3 mb-4">
                        @foreach ($this->filteredPermissions as $catKey => $category)
                            <div class="bg-base-100 rounded-lg border border-base-300 overflow-hidden">
                                <div class="px-3 py-2 bg-base-200/50 border-b border-base-300">
                                    <span class="text-xs font-bold uppercase tracking-wider text-base-content/60">
                                        {{ $category['label'] }}
                                    </span>
                                </div>
                                <div class="divide-y divide-base-200">
                                    @foreach ($category['permissions'] as $perm)
                                        @php
                                            $pState = $this->permissionStates[$perm['name']] ?? ['state' => 'none', 'direct' => 0, 'via_role' => 0, 'none' => count($selectedUsers), 'total' => count($selectedUsers)];
                                            $isSelected = in_array($perm['name'], $selectedPermissions);
                                            // Mode Accorder : cliquer fait "passer à all" → cochée.
                                            // Mode Retirer  : cliquer fait "passer à none" → décochée.
                                            $effectiveChecked = $pState['state'] === 'all';
                                            $effectiveIndeterminate = $pState['state'] === 'some';
                                            $displayChecked = $isSelected ? !$removePermissions : $effectiveChecked;
                                            $displayIndeterminate = $isSelected ? false : $effectiveIndeterminate;
                                            $stateBadgeClass = match ($pState['state']) {
                                                'all' => 'badge-success',
                                                'some' => 'badge-warning',
                                                default => 'badge-ghost',
                                            };
                                            $stateBadgeLabel = match ($pState['state']) {
                                                'all' => 'Tous',
                                                'none' => 'Aucun',
                                                default => ($pState['direct'] + $pState['via_role']) . '/' . $pState['total'],
                                            };
                                            $tooltip = sprintf(
                                                '%d direct · %d via rôle · %d sans',
                                                $pState['direct'],
                                                $pState['via_role'],
                                                $pState['none'],
                                            );
                                        @endphp
                                        <div wire:click="togglePermission('{{ $perm['name'] }}')"
                                            class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-base-200/30 transition-colors
                                                {{ $isSelected ? 'bg-primary/5 border-l-4 border-primary' : 'border-l-4 border-transparent' }}">
                                            <input type="checkbox"
                                                wire:key="perm-cb-{{ $perm['name'] }}-{{ $pState['state'] }}-{{ $isSelected ? ($removePermissions ? 'rm' : 'add') : 'off' }}"
                                                x-data
                                                x-init="$el.indeterminate = @js($displayIndeterminate)"
                                                @checked($displayChecked)
                                                class="checkbox checkbox-sm {{ $isSelected ? ($removePermissions ? 'checkbox-error' : 'checkbox-primary') : 'checkbox-primary' }} pointer-events-none"
                                                tabindex="-1"
                                                aria-label="État : {{ $stateBadgeLabel }}{{ $isSelected ? ($removePermissions ? ' — sera retirée' : ' — sera accordée') : '' }}" />
                                            <div class="flex-1 min-w-0">
                                                <div class="text-sm flex items-center gap-2 flex-wrap">
                                                    <span>{{ $perm['label'] }}</span>
                                                    @if ($pState['total'] > 0)
                                                        <span class="badge badge-xs {{ $stateBadgeClass }}"
                                                            title="{{ $tooltip }}">
                                                            {{ $stateBadgeLabel }}
                                                        </span>
                                                    @endif
                                                    @if ($pState['via_role'] > 0)
                                                        <i class="fa-solid fa-shield-halved text-xs text-info/70"
                                                            title="{{ $pState['via_role'] }}/{{ $pState['total'] }} via un rôle (non retirable individuellement)"></i>
                                                    @endif
                                                </div>
                                                <div class="text-xs text-base-content/40 font-mono">{{ $perm['name'] }}</div>
                                            </div>
                                            @if ($isSelected)
                                                <span class="badge badge-sm {{ $removePermissions ? 'badge-error' : 'badge-primary' }} shrink-0"
                                                    title="{{ $removePermissions ? 'Sera retirée' : 'Sera accordée' }}">
                                                    <i class="fa-solid {{ $removePermissions ? 'fa-minus' : 'fa-plus' }}"></i>
                                                </span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Actions --}}
                    <div class="flex justify-between items-center shrink-0 pt-3 border-t border-base-300">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="close">Annuler</button>
                        <button type="button"
                            class="btn btn-sm {{ $removePermissions ? 'btn-error' : 'btn-primary' }}"
                            wire:click="applyPermissions"
                            wire:loading.attr="disabled"
                            @disabled(count($selectedPermissions) === 0)>
                            <span wire:loading.remove wire:target="applyPermissions">
                                <i class="fa-solid {{ $removePermissions ? 'fa-minus' : 'fa-check' }}"></i>
                                {{ $removePermissions ? 'Retirer' : 'Accorder' }}
                                {{ count($selectedPermissions) }} permission(s)
                            </span>
                            <span wire:loading wire:target="applyPermissions">
                                <span class="loading loading-spinner loading-xs"></span> Traitement...
                            </span>
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
