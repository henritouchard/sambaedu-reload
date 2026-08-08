<?php

use App\Services\UserGroupService;
use App\Support\GroupTypeCatalog;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Édition SQL d'un groupe d'utilisateurs.
 *
 * **Story 62.2 — le choix de type vient du CATALOGUE.** La liste d'`<option>`
 * écrite en dur ici OMETTAIT `matiere_classe` — un type que le balayage
 * d'annuaire produit pourtant tout seul : rouvrir cette page sur un groupe
 * `Matiere_x@y` et enregistrer sans y toucher le déclassait silencieusement.
 * Depuis la bascule, les types proposés sont ceux de `/admin/settings/groups`,
 * `role` et `function` compris — changement de comportement ASSUMÉ, le même que
 * sur la modale de création.
 */
new #[Title('Modification groupe utilisateur')] class extends Component {
    private UserGroupService $userGroupService;

    public int $groupId;
    public string $name = '';
    public string $displayName = '';
    public string $type = 'custom';
    public array $selectedUserIds = [];

    public function boot(UserGroupService $userGroupService): void
    {
        $this->userGroupService = $userGroupService;
    }

    public function mount(int $id): void
    {
        $group = $this->userGroupService->getById($id);

        if ($group === null) {
            abort(404, 'Groupe introuvable');
        }

        $this->groupId = $group->id;
        $this->name = $group->name;
        $this->displayName = $group->display_name ?? '';
        $this->type = $group->type;
        $this->selectedUserIds = $group->users->pluck('id')->map(fn(mixed $v): int => (int) $v)->values()->all();
    }

    #[Computed]
    public function usersOptions(): Collection
    {
        return $this->userGroupService->getAssignableUsers()->map(function ($user): array {
            $label = $user->fullname ?: trim((string) (($user->firstname ?? '') . ' ' . ($user->lastname ?? '')));
            if ($label === '') {
                $label = $user->login;
            }

            return [
                'value' => $user->id,
                'label' => $label,
                'hint' => $user->login,
                'disabled' => false,
            ];
        });
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'displayName' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50', \Illuminate\Validation\Rule::in(GroupTypeCatalog::keys())],
            'selectedUserIds' => ['array'],
            'selectedUserIds.*' => ['integer', 'exists:users,id'],
        ]);

        $this->userGroupService->updateGroup($this->groupId, [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'type' => $this->type,
            'user_ids' => $this->selectedUserIds,
        ]);

        session()->flash('toast', [
            'type' => 'success',
            'title' => 'Groupe mis à jour',
            'message' => 'Les modifications ont été enregistrées.',
        ]);

        $this->redirectRoute('app.users');
    }
};
?>

<x-organisms.page title="Modifier un groupe utilisateur" :scrollable="false" description="Éditez un groupe et ses membres"
    backUrl="{{ route('app.users', ['tab' => 'groups']) }}" backText="Retour">

    <div class="max-w-4xl">
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body space-y-4">
                <div class="grid md:grid-cols-2 gap-4">
                    <div class="form-control">
                        <label class="label"><span class="label-text">Nom technique</span></label>
                        <input type="text" class="input input-bordered" wire:model="name" />
                        @error('name')
                            <span class="text-error text-xs mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-control">
                        <label class="label"><span class="label-text">Nom affiché</span></label>
                        <input type="text" class="input input-bordered" wire:model="displayName" />
                        @error('displayName')
                            <span class="text-error text-xs mt-1">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="form-control max-w-xs">
                    <label class="label"><span class="label-text">Type</span></label>
                    <select class="select select-bordered" wire:model="type">
                        @foreach (\App\Support\GroupTypeCatalog::options() as $typeKey => $typeLabel)
                            <option value="{{ $typeKey }}">{{ $typeLabel }}</option>
                        @endforeach
                    </select>
                    @error('type')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text">Membres</span></label>
                    <livewire:components::molecules.smart-select wire:model.live="selectedUserIds" :options="$this->usersOptions->toArray()"
                        :multiple="true" :filterable="true" :clearable="true" :inline="true" :show-trigger="false"
                        panel-class="border border-base-300 rounded-xl bg-base-100" />
                    @error('selectedUserIds')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>

                <div class="pt-2">
                    <button type="button" class="btn btn-primary" wire:click="save">
                        <i class="fa-solid fa-save"></i>
                        Enregistrer
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-organisms.page>
