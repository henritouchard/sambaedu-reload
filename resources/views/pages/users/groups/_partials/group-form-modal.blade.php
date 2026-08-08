<?php

use App\Services\UserGroupService;
use App\Components\Traits\WithToasts;
use Livewire\Attributes\On;
use Livewire\Component;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Modale de création d'un groupe d'utilisateurs.
 *
 * Pilotée par l'event `open-user-group-modal` (dispatché par le bouton
 * « Nouveau groupe » de la page users). Remplace l'ancienne page
 * /users/groups/new (page + route retirées).
 *
 * Création seule : l'édition (membres, quota, capacités, PP, partage de classe)
 * reste une page dédiée, trop riche pour une modale.
 *
 * **Story 62.2 — le choix de type vient du CATALOGUE, et c'est un changement de
 * comportement ASSUMÉ.** La liste d'`<option>` était écrite en dur ici, et
 * divergeait déjà de celle de l'édition SQL. Depuis la bascule, les types
 * proposés sont ceux de `/admin/settings/groups` — donc `role` et `function`
 * (que le balayage d'annuaire écrit depuis toujours) et les types créés à
 * l'écran deviennent sélectionnables à la création. Les cacher referait un
 * vocabulaire à deux vitesses : des types réels, portés par des groupes réels,
 * qu'un formulaire ferait mine d'ignorer.
 *
 * Sécurité : la page hôte /users est protégée par `can:user.read`, mais la
 * création exige `can:user.modify` (garde portée jusqu'ici par la middleware de
 * la route /users/groups/new). La modale n'étant plus derrière cette route, on
 * ré-affirme l'autorisation à l'ouverture ET à l'enregistrement.
 */
new class extends Component {
    use WithToasts;

    private UserGroupService $userGroupService;

    public bool $isOpen = false;
    public string $name = '';
    public string $type = 'custom';

    public function boot(UserGroupService $userGroupService): void
    {
        $this->userGroupService = $userGroupService;
    }

    #[On('open-user-group-modal')]
    public function open(): void
    {
        abort_unless(Gate::allows('user.modify'), 403);

        $this->reset(['name', 'type']);
        $this->type = 'custom';
        $this->resetValidation();
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function save(): void
    {
        abort_unless(Gate::allows('user.modify'), 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._@-]+$/'],
            // Story 62.2 — le vocabulaire vient du CATALOGUE, plus d'une liste
            // d'`<option>` recopiée. Le service refuse de toute façon une valeur
            // hors catalogue ; la règle `in:` est là pour que le refus se lise
            // sous le champ plutôt qu'en toast d'exception.
            'type' => ['required', 'string', 'max:50', \Illuminate\Validation\Rule::in(\App\Support\GroupTypeCatalog::keys())],
        ]);

        try {
            // Un seul nom à la création : il sert d'identifiant (CN AD, d'où la
            // contrainte regex) ET de libellé. `display_name` est aligné sur
            // `name` ; il reste éditable séparément ensuite sur la fiche.
            $group = $this->userGroupService->createGroup([
                'name' => $validated['name'],
                'display_name' => $validated['name'],
                'type' => $validated['type'],
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
            return;
        } catch (\Throwable $e) {
            Log::error('[UserGroupCreate] Erreur création: ' . $e->getMessage());
            $this->toastError('Une erreur est survenue lors de la création du groupe.');
            return;
        }

        session()->flash('toast', [
            'type' => 'success',
            'title' => 'Groupe créé',
            'message' => 'Le groupe utilisateur a été créé avec succès.',
        ]);

        // On enchaîne sur la fiche d'édition pour configurer membres/capacités.
        $this->redirectRoute('app.users.groups.edit', ['id' => $group->id]);
    }
};
?>

<div>
    <x-molecules.modal wire:model="isOpen" size="max-w-lg" height="h-auto max-h-[90vh]"
        title="Nouveau groupe utilisateur" subtitle="Créer un groupe d'utilisateurs"
        icon="fa-user-group text-primary">

        {{-- Nom --}}
        <div class="form-control w-full">
            <label class="label py-2">
                <x-atoms.tooltip label="Nom" labelClass="label-text font-medium" icon="true"
                    iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                    Caractères autorisés : lettres, chiffres et <code>. _ - @</code> (sans espace ni accent).
                </x-atoms.tooltip>
                <span class="text-error">*</span>
            </label>
            <input type="text" wire:model="name"
                class="input input-bordered w-full @error('name') input-error @enderror"
                placeholder="ex: arts-plastiques">
            @error('name')
                <label class="label py-1">
                    <span class="label-text-alt text-error">{{ $message }}</span>
                </label>
            @enderror
        </div>

        {{-- Type --}}
        <div class="form-control w-full">
            <label class="label py-2">
                <span class="label-text font-medium">Type</span>
            </label>
            <select wire:model="type" class="select select-bordered w-full @error('type') select-error @enderror">
                @foreach (\App\Support\GroupTypeCatalog::options() as $typeKey => $typeLabel)
                    <option value="{{ $typeKey }}">{{ $typeLabel }}</option>
                @endforeach
            </select>
            @error('type')
                <label class="label py-1">
                    <span class="label-text-alt text-error">{{ $message }}</span>
                </label>
            @enderror
        </div>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled"
                wire:target="save">
                <span wire:loading.remove wire:target="save">
                    <i class="fa-solid fa-check"></i>
                    Créer le groupe
                </span>
                <span wire:loading wire:target="save">
                    <span class="loading loading-spinner loading-xs"></span> Enregistrement...
                </span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</div>
