<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Components\Traits\WithToasts;
use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Validation\Rule;

new #[Title('Nouveau profil — Gestion des droits')] class extends Component {
    use WithToasts;

    public string $name = '';
    public array $permissions = [];
    public array $groupedPermissions = [];

    public function mount(): void
    {
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

    public function save(): void
    {
        abort_unless(\Illuminate\Support\Facades\Gate::allows('user.assign.right'), 403);

        $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->where(fn($q) => $q->where('guard_name', 'web')),
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

        if (SambaRole::isSeeded($this->name)) {
            $this->toastError('Ce nom est réservé à un profil initial.');
            return;
        }

        $role = Role::create(['name' => $this->name, 'guard_name' => 'web']);
        $role->syncPermissions($this->permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        session()->flash('flash_success', "Profil « {$role->name} » créé.");
        $this->redirectRoute('app.rights-management.profiles.show', ['id' => $role->id], navigate: false);
    }
};
?>

<x-organisms.page
    title="Nouveau profil"
    description="Créer un nouveau profil de droits (rôle Spatie)"
    icon="fa-solid fa-id-card-clip"
    backUrl="{{ route('app.rights-management', ['tab' => 'profiles']) }}">

    <x-slot:actions>
        <a href="{{ route('app.rights-management', ['tab' => 'profiles']) }}" class="btn btn-ghost btn-sm">
            Annuler
        </a>
        <button type="button" class="btn btn-primary btn-sm" wire:click="save">
            <i class="fa-solid fa-floppy-disk mr-1"></i>
            Créer le profil
        </button>
    </x-slot:actions>

    @php
        $mode = 'create';
        $isSeeded = false;
        $usersCount = 0;
    @endphp
    @include('pages.rights-management.profiles._partials.form', [
        'mode' => $mode,
        'name' => $name,
        'permissions' => $permissions,
        'groupedPermissions' => $groupedPermissions,
        'isSeeded' => $isSeeded,
        'usersCount' => $usersCount,
    ])
</x-organisms.page>
