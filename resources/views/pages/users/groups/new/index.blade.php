<?php

use App\Services\UserGroupService;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Création groupe utilisateur')] class extends Component {
    private UserGroupService $userGroupService;

    public string $name = '';
    public string $type = 'custom';

    public function boot(UserGroupService $userGroupService): void
    {
        $this->userGroupService = $userGroupService;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._@-]+$/'],
            'type' => ['required', 'string', 'max:50'],
        ]);

        // Un seul nom à la création : il sert d'identifiant (CN AD, d'où la
        // contrainte regex) ET de libellé. `display_name` est aligné sur `name`
        // ; il reste éditable séparément ensuite sur la fiche du groupe.
        $group = $this->userGroupService->createGroup([
            'name' => $this->name,
            'display_name' => $this->name,
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
                <div class="form-control max-w-md">
                    <label class="label"><span class="label-text">Nom</span></label>
                    <input type="text" class="input input-bordered" wire:model="name"
                        placeholder="ex: arts-plastiques" />
                    <label class="label">
                        <span class="label-text-alt text-base-content/60">
                            Lettres, chiffres et <code>. _ - @</code> uniquement (sans espace ni
                            accent). Pour une classe, saisissez le nom nu (ex&nbsp;: <code>6A</code>).
                        </span>
                    </label>
                    @error('name')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
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
