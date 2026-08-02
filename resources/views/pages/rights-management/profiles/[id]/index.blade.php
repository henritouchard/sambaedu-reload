<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Components\Traits\WithToasts;
use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\User as EloquentUser;
use App\Services\GroupRightsProfileService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Validation\Rule;

new #[Title('Édition d\'un profil — Gestion des droits')] class extends Component {
    use WithToasts;

    public string $originalName = '';
    public bool $isSeeded = false;
    public int $usersCount = 0;

    public string $name = '';
    public array $permissions = [];
    public array $groupedPermissions = [];

    /** Liste des utilisateurs portant ce profil (chargée à mount + après mutation). */
    public array $assignedUsers = [];

    /** Story 49.1 (AC6) — id du rôle édité (le nom est renommable). */
    public int $roleId = 0;

    /**
     * Story 49.1 (AC6) — groupes qui PORTENT ce profil. Non vide ⇒ suppression
     * refusée : la suppression silencieuse d'un profil porté retirerait des
     * droits à tout un parc.
     *
     * @var array<int, array{id:int, label:string}>
     */
    public array $carrierGroups = [];

    /** Snapshot post-mount/post-save pour détecter les changements (bouton Enregistrer). */
    public string $initialName = '';
    public array $initialPermissions = [];

    public function mount(int $id): void
    {
        $role = Role::where('id', $id)->where('guard_name', 'web')->first();
        if (!$role) {
            abort(404);
        }

        $this->roleId = (int) $role->id;
        $this->originalName = $role->name;
        $this->name = $role->name;
        $this->isSeeded = SambaRole::isSeeded($role->name);
        $this->loadCarrierGroups();
        $this->usersCount = $role->users()->count();
        $this->permissions = $role->permissions->pluck('name')->toArray();
        sort($this->permissions);

        $this->initialName = $this->name;
        $this->initialPermissions = $this->permissions;

        $this->loadAssignedUsers();

        $this->groupedPermissions = collect(SambaPermission::groupedByCategory())
            ->map(fn($cat) => [
                'label' => $cat['label'],
                'permissions' => array_map(
                    fn(SambaPermission $p) => ['name' => $p->value, 'label' => $p->label()],
                    $cat['permissions']
                ),
            ])
            ->toArray();
    }

    /**
     * Recharge la liste des utilisateurs portant ce profil. Appelée à mount
     * et après chaque mutation susceptible d'affecter le rattachement
     * (rights-applied dispatché par le drawer Spatie).
     */
    public function loadAssignedUsers(): void
    {
        $this->assignedUsers = EloquentUser::role($this->originalName)
            ->orderBy('login')
            ->get()
            ->map(fn(EloquentUser $u) => [
                'login' => $u->login,
                'fullname' => $u->fullname ?: $u->login,
                'is_active' => (bool) $u->is_active,
            ])
            ->toArray();

        $this->usersCount = count($this->assignedUsers);
    }

    #[\Livewire\Attributes\On('rights-applied')]
    public function onRightsApplied(): void
    {
        $this->loadAssignedUsers();
    }

    /**
     * Story 49.1 (AC6) — groupes portant ce profil. Information affichée sur la
     * page (avec lien vers l'onglet Profils) et base de la garde de suppression.
     */
    public function loadCarrierGroups(): void
    {
        $this->carrierGroups = app(GroupRightsProfileService::class)
            ->groupsCarrying($this->roleId)
            ->map(fn(\App\Models\UserGroup $g) => [
                'id' => $g->id,
                'label' => $g->display_name_or_name,
            ])
            ->values()
            ->toArray();
    }

    public function save(): void
    {
        abort_unless(\Illuminate\Support\Facades\Gate::allows('user.assign.right'), 403);

        // Garde-fou : un rôle seedé ne peut pas être modifié via l'UI. Pour
        // changer ses permissions, éditer le PermissionSeeder puis relancer
        // `php artisan sambaedu:app:update --resync-seeded-roles` (le simple
        // `db:seed --force` ne re-synchronise PAS les rôles existants).
        if ($this->isSeeded) {
            abort(403, 'Les permissions des rôles initiaux ne sont pas éditables via l\'UI.');
        }

        $role = Role::where('name', $this->originalName)->where('guard_name', 'web')->firstOrFail();

        $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')
                    ->where(fn($q) => $q->where('guard_name', 'web'))
                    ->ignore($role->id),
            ],
            'permissions' => ['array'],
            'permissions.*' => [
                'string',
                Rule::in(array_map(fn($p) => $p->value, SambaPermission::cases())),
            ],
        ], [
            'name.required' => 'Le nom du profil est obligatoire.',
            'name.unique' => 'Ce nom de profil est déjà utilisé.',
        ]);

        if ($role->name !== $this->name) {
            $role->name = $this->name;
            $role->save();
            $this->originalName = $role->name;
        }

        $role->syncPermissions($this->permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Re-snapshoter pour que le bouton Enregistrer redevienne désactivé.
        sort($this->permissions);
        $this->initialName = $this->name;
        $this->initialPermissions = $this->permissions;

        $this->toastSuccess("Profil « {$role->name} » mis à jour.");
    }

    /**
     * Computed pour le bouton Enregistrer : actif uniquement si nom ou
     * permissions ont changé par rapport au snapshot post-mount/post-save.
     */
    public function getIsDirtyProperty(): bool
    {
        if ($this->name !== $this->initialName) {
            return true;
        }
        $current = $this->permissions;
        sort($current);
        return $current !== $this->initialPermissions;
    }

    public function delete(): void
    {
        abort_unless(\Illuminate\Support\Facades\Gate::allows('user.assign.right'), 403);

        // Story 49.1 (AC6) — garde « profil PORTÉ », AVANT la garde seedé. Le
        // message NOMME les groupes porteurs : sans ça, la suppression
        // retirerait silencieusement des droits à tout un parc. La FK
        // `restrictOnDelete` fait filet au niveau DB pour les chemins hors UI.
        $this->loadCarrierGroups();
        if (!empty($this->carrierGroups)) {
            $names = implode(', ', array_column($this->carrierGroups, 'label'));
            $this->toastError(
                "Suppression refusée — porté par : {$names}. Retirez d'abord le profil de ces groupes."
            );
            return;
        }

        if ($this->isSeeded) {
            $this->toastError('Le profil est initial et ne peut pas être supprimé.');
            return;
        }
        if ($this->usersCount > 0) {
            $this->toastError("Impossible : {$this->usersCount} utilisateur(s) portent ce profil. Retirez-le d'abord.");
            return;
        }

        $role = Role::where('name', $this->originalName)->where('guard_name', 'web')->first();
        if (!$role) {
            return;
        }
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        session()->flash('flash_success', "Profil « {$this->originalName} » supprimé.");
        $this->redirectRoute('app.rights-management', ['tab' => 'profiles'], navigate: false);
    }
};
?>

<x-organisms.page
    :title="$originalName"
    description="Édition d'un profil de droits (rôle Spatie)"
    icon="fa-solid fa-id-card-clip"
    backUrl="{{ route('app.rights-management', ['tab' => 'profiles']) }}">

    <x-slot:actions>
        <div class="dropdown dropdown-end">
            <label tabindex="0" class="btn btn-ghost">
                <i class="fa-solid fa-ellipsis-vertical"></i>
            </label>
            <ul tabindex="0"
                class="dropdown-content menu p-2 shadow bg-base-100 rounded-box w-56 border border-base-300 z-[1]">
                <li class="{{ $isSeeded || $usersCount > 0 || !empty($carrierGroups) ? 'disabled' : '' }}">
                    <button type="button"
                        class="text-error"
                        @disabled($isSeeded || $usersCount > 0 || !empty($carrierGroups))
                        wire:click="delete"
                        wire:confirm="Supprimer ce profil ? Action irréversible.">
                        <i class="fa-solid fa-trash-can"></i>
                        Supprimer le profil
                    </button>
                </li>
            </ul>
        </div>
        @if (!$isSeeded)
            <button type="button" class="btn btn-primary"
                wire:click="save"
                @disabled(!$this->isDirty)>
                <i class="fa-solid fa-floppy-disk mr-1"></i>
                Enregistrer
            </button>
        @endif
    </x-slot:actions>

    @include('pages.rights-management.profiles._partials.form', [
        'mode' => 'edit',
        'name' => $name,
        'permissions' => $permissions,
        'groupedPermissions' => $groupedPermissions,
        'isSeeded' => $isSeeded,
        'usersCount' => $usersCount,
    ])

    {{-- Story 49.1 (AC6/AC7) — groupes PORTANT ce profil : l'appartenance à
         l'un d'eux attribue ce profil, et tant qu'il en existe un, le profil
         n'est pas supprimable. --}}
    @if (!empty($carrierGroups))
        <div class="mt-6">
            <div class="alert alert-info">
                <i class="fa-solid fa-users-rectangle"></i>
                <div>
                    <div class="font-semibold">Profil porté par un groupe</div>
                    <div class="text-sm">
                        Ce profil est attribué automatiquement aux membres de :
                        <strong>{{ implode(', ', array_column($carrierGroups, 'label')) }}</strong>.
                        Il n'est ni supprimable ni attribuable individuellement tant qu'un groupe le porte.
                    </div>
                    <a class="link link-hover text-sm"
                        href="{{ route('app.rights-management', ['tab' => 'profiles']) }}">
                        Gérer les groupes porteurs
                    </a>
                </div>
            </div>
        </div>
    @endif

    {{-- Utilisateurs portant ce profil. Le scroll vertical est géré par la page
         (organisms.page :scrollable=true par défaut) ; ce tableau reste donc
         en flux normal et ne doit pas avoir son propre overflow-y. --}}
    <div class="mt-6">
        <div class="card bg-base-100 border border-base-300 shadow-sm">
            <div class="card-body">
                <h3 class="card-title text-base">
                    <i class="fa-solid fa-users text-primary"></i>
                    Utilisateurs portant ce profil
                    <span class="badge badge-primary badge-sm ml-2">{{ count($assignedUsers) }}</span>
                </h3>

                @if (empty($assignedUsers))
                    <p class="text-sm text-base-content/50 py-4">
                        Aucun utilisateur ne porte ce profil.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Utilisateur</th>
                                    <th>Login</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($assignedUsers as $u)
                                    <tr class="cursor-pointer"
                                        onclick="window.location.href='{{ route('app.user.show', $u['login']) }}'">
                                        <td class="font-medium">{{ $u['fullname'] }}</td>
                                        <td><span class="font-mono text-xs">{{ $u['login'] }}</span></td>
                                        <td>
                                            @if ($u['is_active'])
                                                <span class="badge badge-success badge-xs">Actif</span>
                                            @else
                                                <span class="badge badge-error badge-xs">Inactif</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-organisms.page>
