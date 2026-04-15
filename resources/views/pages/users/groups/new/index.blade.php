<?php

use App\Services\UserGroupService;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Création groupe utilisateur')] class extends Component {
    private UserGroupService $userGroupService;

    public string $name = '';
    public string $displayName = '';
    public string $type = 'custom';

    public function boot(UserGroupService $userGroupService): void
    {
        $this->userGroupService = $userGroupService;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._@-]+$/'],
            'displayName' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
        ]);

        $group = $this->userGroupService->createGroup([
            'name' => $this->name,
            'display_name' => $this->displayName,
            'type' => $this->type,
        ]);

        session()->flash('toast', [
            'type' => 'success',
            'title' => 'Groupe créé',
            'message' => 'Le groupe utilisateur a été créé avec succès.',
        ]);

        $this->redirectRoute('app.users.groups.edit', ['id' => $group->id]);
    }
};
?>

<x-organisms.page title="Créer un groupe utilisateur" :scrollable="false" description="Créez un groupe utilisateur"
    backUrl="{{ route('app.users') }}" backText="Retour">

    <div class="max-w-4xl">
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body space-y-4">
                <div class="grid md:grid-cols-2 gap-4">
                    <div class="form-control">
                        <label class="label"><span class="label-text">Nom technique</span></label>
                        <input type="text" class="input input-bordered" wire:model="name"
                            placeholder="ex: classe_6a" />
                        @error('name')
                            <span class="text-error text-xs mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-control">
                        <label class="label"><span class="label-text">Nom affiché</span></label>
                        <input type="text" class="input input-bordered" wire:model="displayName"
                            placeholder="ex: Classe 6A" />
                        @error('displayName')
                            <span class="text-error text-xs mt-1">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="form-control max-w-xs">
                    <label class="label"><span class="label-text">Type</span></label>
                    <select class="select select-bordered" wire:model="type">
                        <option value="custom">Custom</option>
                        <option value="classe">Classe</option>
                        <option value="cours">Cours</option>
                        <option value="matiere">Matière</option>
                        <option value="matiere_classe">Matière classe (Matiere_x@y)</option>
                        <option value="projet">Projet</option>
                        <option value="equipe">Équipe</option>
                    </select>
                    @error('type')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>

                <div class="pt-2">
                    <button type="button" class="btn btn-primary" wire:click="save">
                        <i class="fa-solid fa-save"></i>
                        Créer
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-organisms.page>
