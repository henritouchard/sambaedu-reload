<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use App\Components\Traits\WithToasts;
use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\User as EloquentUser;
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
        $this->availableRoles = collect(SambaRole::cases())
            ->map(fn(SambaRole $r) => [
                'name' => $r->value,
                'label' => $r->label(),
                'permissions_count' => count($r->permissions()),
                'permissions' => $r->permissionNames(),
            ])
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

        $this->processing = true;
        $count = 0;
        $errors = 0;

        foreach ($this->selectedUsers as $login) {
            try {
                $user = $this->ensureEloquentUser($login);
                if (!$user) {
                    $errors++;
                    continue;
                }

                if ($this->removeRole) {
                    $user->removeRole($this->selectedRole);
                } else {
                    $user->syncRoles([$this->selectedRole]);
                }
                $count++;
            } catch (\Exception $e) {
                Log::error("[RightsDrawer] Erreur rôle pour {$login}: " . $e->getMessage());
                $errors++;
            }
        }

        $action = $this->removeRole ? 'retiré de' : 'assigné à';
        $message = "Rôle '{$this->selectedRole}' {$action} {$count} utilisateur(s).";
        if ($errors > 0) {
            $message .= " ({$errors} erreur(s))";
            $this->toastWarning($message);
        } else {
            $this->toastSuccess($message);
        }
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

        foreach ($this->selectedUsers as $login) {
            try {
                $user = $this->ensureEloquentUser($login);
                if (!$user) {
                    $errors++;
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
        if ($errors > 0) {
            $message .= " ({$errors} erreur(s))";
            $this->toastWarning($message);
        } else {
            $this->toastSuccess($message);
        }
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
                            <label
                                class="flex items-center gap-3 p-3 rounded-lg cursor-pointer transition-colors
                                    {{ $selectedRole === $role['name'] ? 'bg-primary/10 border border-primary/30' : 'bg-base-100 hover:bg-base-300/50 border border-transparent' }}">
                                <input type="radio" wire:model.live="selectedRole" value="{{ $role['name'] }}"
                                    class="radio radio-primary radio-sm" />
                                <div class="flex-1">
                                    <div class="font-medium text-sm">{{ $role['label'] }}</div>
                                    <div class="text-xs text-base-content/50">
                                        {{ $role['permissions_count'] }} permission(s) :
                                        {{ implode(', ', array_slice($role['permissions'], 0, 3)) }}
                                        @if (count($role['permissions']) > 3)
                                            <span class="text-primary">+{{ count($role['permissions']) - 3 }}</span>
                                        @endif
                                    </div>
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
                                        <label class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-base-200/30">
                                            <input type="checkbox"
                                                wire:click="togglePermission('{{ $perm['name'] }}')"
                                                @checked(in_array($perm['name'], $selectedPermissions))
                                                class="checkbox checkbox-sm checkbox-primary" />
                                            <div class="flex-1">
                                                <div class="text-sm">{{ $perm['label'] }}</div>
                                                <div class="text-xs text-base-content/40 font-mono">{{ $perm['name'] }}</div>
                                            </div>
                                        </label>
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
