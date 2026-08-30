<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use App\Services\Parc\WorkstationGroupService;
use App\Services\ControlHub\WorkstationGroupLabelService;
use App\Exceptions\ControlHub\LabelAssignmentException;
use App\Exceptions\ControlHub\UpstreamLockCollisionException;
use App\Enums\WorkstationEnvironment;
use App\Enums\ControlHubLabelMode;
use App\Models\ControlHubContract;
use App\Models\WorkstationGroup;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Modale réutilisable de création / édition d'un groupe de postes.
 *
 * Pilotée par l'event `open-group-modal` :
 *   - sans argument            → mode création (redirige vers le groupe créé).
 *   - avec `{ id: <int> }`      → mode édition (redirige vers le groupe modifié).
 *
 * C'est le SEUL chemin de création et d'édition d'un groupe : les pages
 * /groups/new et /groups/{id}/edit ont été retirées, ainsi que leurs routes.
 * Tout champ du formulaire vit donc ici, y compris le label de contrat amont
 * ({@see \App\Services\ControlHub\WorkstationGroupLabelService}) : le porter
 * ailleurs le rendrait inatteignable.
 */
new class extends Component {
    use WithToasts;

    private WorkstationGroupService $parcService;

    public bool $isOpen = false;
    /** null = création ; sinon id du groupe édité. */
    public ?int $editingId = null;

    // Champs du formulaire. Le nom technique (`name`) n'est plus saisi : il est
    // auto-généré (slug) et immuable côté service. L'utilisateur ne renseigne
    // que le nom affiché.
    public string $display_name = '';
    public string $description = '';
    public ?int $parent_id = null;
    public bool $is_physical = true;
    // Nature des postes (Story 26.1). Défaut « partagé » (shared_local) appliqué
    // dans resetForm() ; le choix « non déclaré » n'est plus exposé en UI.
    public string $environment = '';

    // Label de contrat amont (Story 30.2). '' = aucun → null en base (miroir exact
    // du pattern `environment`). Section masquée si pas de contrat amont actif.
    public string $controlhubLabel = '';
    // Propriétés DÉRIVÉES côté serveur (loadControlHubLabels) : #[Locked] interdit
    // leur mutation par requête Livewire forgée — sinon un client pourrait neutraliser
    // l'affichage lecture seule ou injecter un label assignable (review 30.2 M2).
    #[Locked]
    public bool $hasActiveContract = false;
    /** @var array<int,string> Noms des labels libres assignables du contrat actif. */
    #[Locked]
    public array $freeLabelNames = [];
    /** Label réservé/hors-liste actuellement porté (affiché en lecture seule), ou null. */
    #[Locked]
    public ?string $reservedLabelHeld = null;

    public Collection $availableParents;

    public function boot(WorkstationGroupService $parcService): void
    {
        $this->parcService = $parcService;
    }

    public function mount(): void
    {
        $this->availableParents = collect();
    }

    #[On('open-group-modal')]
    public function open(?int $id = null): void
    {
        abort_unless(Gate::allows('computer.install'), 403);

        $this->resetForm();
        $this->editingId = $id;

        if ($id !== null) {
            $group = $this->parcService->getGroup($id);
            if (!$group) {
                $this->toastError('Groupe introuvable.');
                return;
            }
            // Repli sur le nom technique pour un groupe legacy sans display_name.
            $this->display_name = $group->display_name ?? $group->name;
            $this->description = $group->description ?? '';
            $this->parent_id = $group->parent_id;
            $this->is_physical = (bool) $group->is_physical;
            // Pas de choix « non déclaré » en UI : un groupe historique sans
            // environnement (null) retombe sur « partagé » coché.
            $this->environment = $group->environment?->value ?? WorkstationEnvironment::SharedLocal->value;
            $this->controlhubLabel = $group->controlhub_label ?? '';
        }

        $this->loadParents();
        $this->loadControlHubLabels();
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['display_name', 'description', 'parent_id', 'environment', 'editingId', 'controlhubLabel']);
        $this->hasActiveContract = false;
        $this->freeLabelNames = [];
        $this->reservedLabelHeld = null;
        $this->is_physical = true;
        // « Partagé » coché par défaut (plus d'option « non déclaré »).
        $this->environment = WorkstationEnvironment::SharedLocal->value;
        $this->resetValidation();
    }

    private function loadParents(): void
    {
        try {
            // Édition : tous les groupes sauf lui-même. Création : groupes racine.
            if ($this->editingId !== null) {
                $this->availableParents = WorkstationGroup::where('id', '!=', $this->editingId)
                    ->orderBy('name')
                    ->get();
            } else {
                $this->availableParents = $this->parcService->getRootGroupsForSelect();
            }
        } catch (\Exception $e) {
            Log::error('[GroupFormModal] Erreur chargement parents: ' . $e->getMessage());
            $this->availableParents = collect();
        }
    }

    /**
     * Story 30.2 — Charge le contrat amont actif et les labels assignables (free).
     *
     * NFR3 : sans contrat actif, la section UI est masquée (hasActiveContract=false)
     * et aucune contrainte n'est ajoutée. Le label actuellement porté qui n'est PAS
     * dans la liste free (réservé — cf. 30.3 — ou « dangling ») est exposé en lecture
     * seule via $reservedLabelHeld, jamais sélectionnable par le refnum.
     */
    private function loadControlHubLabels(): void
    {
        $activeContract = ControlHubContract::active();
        $this->hasActiveContract = $activeContract !== null;

        if ($activeContract === null) {
            $this->freeLabelNames = [];
            $this->reservedLabelHeld = null;
            return;
        }

        $this->freeLabelNames = $activeContract->labels()
            ->where('mode', ControlHubLabelMode::Free)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $this->reservedLabelHeld = ($this->controlhubLabel !== ''
            && !in_array($this->controlhubLabel, $this->freeLabelNames, true))
            ? $this->controlhubLabel
            : null;
    }

    public function rules(): array
    {
        return [
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'parent_id' => 'nullable|integer|exists:workstation_groups,id',
            'is_physical' => 'boolean',
            // Le nom seul est validé ici ; l'appartenance au contrat et le mode
            // (free/reserved/inconnu) sont tranchés par WorkstationGroupLabelService.
            'controlhubLabel' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'display_name.required' => 'Le nom du groupe est requis.',
            'display_name.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'description.max' => 'La description ne peut pas dépasser 500 caractères.',
            'parent_id.exists' => 'Le groupe parent sélectionné n\'existe pas.',
        ];
    }

    /**
     * Aligne le label amont du groupe édité sur la saisie. Renvoie false si le
     * service a refusé — l'appelant s'arrête alors sans rediriger.
     */
    private function applyLabel(WorkstationGroupLabelService $labelService, WorkstationGroup $group): bool
    {
        try {
            if ($this->controlhubLabel === '') {
                $labelService->detachLabel($group);
            } else {
                $labelService->assignLabel($group, $this->controlhubLabel);
            }
        } catch (LabelAssignmentException | UpstreamLockCollisionException $e) {
            // Story 30.5 — collision verrou/verrou prédite : message explicite
            // (item / périmètre / valeurs) en toast, sans redirection.
            $this->toastError($e->getMessage());
            return false;
        }

        return true;
    }

    public function save(WorkstationGroupLabelService $labelService): void
    {
        abort_unless(Gate::allows('computer.install'), 403);

        $validated = $this->validate();

        // Un groupe logique n'a pas de parent (hiérarchie d'OU réservée au physique).
        $parentId = $this->is_physical ? ($validated['parent_id'] ?: null) : null;

        if ($this->editingId !== null && $parentId === $this->editingId) {
            $this->toastError('Un groupe ne peut pas être son propre parent');
            return;
        }

        // Environnement : '' = « non déclaré » → null. Une valeur non vide doit
        // appartenir à l'enum fermé (sinon requête forgée) — on refuse plutôt que
        // de ravaler en null silencieusement.
        $environment = null;
        if ($this->environment !== '') {
            $environment = WorkstationEnvironment::tryFrom($this->environment);
            if ($environment === null) {
                $this->toastError("Valeur d'environnement invalide.");
                return;
            }
        }

        try {
            if ($this->editingId !== null) {
                // `name` (technique) est immuable : on ne l'envoie jamais en
                // édition, seul le nom affiché est modifiable.
                $this->parcService->updateGroup($this->editingId, [
                    'display_name' => $validated['display_name'],
                    'description' => $validated['description'] ?: null,
                    'parent_id' => $parentId,
                    'is_physical' => $validated['is_physical'],
                    'environment' => $environment,
                ]);

                // Story 30.2 — Mapping du label de contrat amont via le service dédié
                // (jamais via updateGroup, qui throw sur isLocked — concern distinct).
                // '' = détacher ; sinon assigner. Un refus métier laisse le reste de
                // l'édition enregistré : on reste dans la modale pour corriger.
                $group = WorkstationGroup::find($this->editingId);

                if ($group !== null && !$this->applyLabel($labelService, $group)) {
                    return;
                }

                session()->flash('toast', [
                    'type' => 'success',
                    'title' => 'Groupe modifié',
                    'message' => "Le groupe \"{$validated['display_name']}\" a été modifié avec succès.",
                ]);

                $this->redirect(route('app.parc.groups.show', $this->editingId));
                return;
            }

            // `name` est dérivé (slug) par le service depuis `display_name`.
            $group = $this->parcService->createGroup([
                'display_name' => $validated['display_name'],
                'description' => $validated['description'] ?: null,
                'parent_id' => $parentId,
                'is_physical' => $validated['is_physical'],
                'environment' => $environment,
            ]);

            // Story 30.2 (AC #3) — Rattacher le label libre choisi. Un refus laisse
            // le groupe créé : on redirige vers sa fiche avec le motif du refus,
            // plutôt que de garder ouverte une modale de création déjà consommée.
            if ($this->controlhubLabel !== '') {
                try {
                    $labelService->assignLabel($group, $this->controlhubLabel);
                } catch (LabelAssignmentException | UpstreamLockCollisionException $e) {
                    // Le toast DOIT survivre au redirect : session flash, pas
                    // toastError (event navigateur, perdu à la navigation).
                    session()->flash('toast', [
                        'type' => 'error',
                        'title' => 'Label non attribué',
                        'message' => $e->getMessage(),
                    ]);
                    $this->redirect(route('app.parc.groups.show', $group->id));
                    return;
                }
            }

            session()->flash('toast', [
                'type' => 'success',
                'title' => 'Groupe créé',
                'message' => "Le groupe \"{$group->display_name_or_name}\" a été créé avec succès.",
            ]);

            $this->redirect(route('app.parc.groups.show', $group->id));
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[GroupFormModal] Erreur enregistrement: ' . $e->getMessage());
            $this->toastError('Une erreur est survenue lors de l\'enregistrement du groupe.');
        }
    }
};
?>

<div>
    <x-molecules.modal wire:model="isOpen" size="max-w-2xl" height="h-auto max-h-[90vh]"
        :title="$editingId ? 'Modifier le groupe' : 'Nouveau groupe'"
        :subtitle="$editingId ? $display_name : 'Créer un groupe de postes'" icon="fa-layer-group text-primary">

        {{-- Nom affiché (le nom technique est auto-généré) --}}
        <div class="form-control w-full">
            <label class="label py-2">
                <span class="label-text font-medium">Nom du groupe <span class="text-error">*</span></span>
            </label>
            <input type="text" wire:model="display_name"
                class="input input-bordered w-full @error('display_name') input-error @enderror"
                placeholder="Ex: Salle Info 101, Parc Portables">
            @error('display_name')
                <label class="label py-1">
                    <span class="label-text-alt text-error">{{ $message }}</span>
                </label>
            @enderror
        </div>

        {{-- Description --}}
        <div class="form-control w-full">
            <label class="label py-2">
                <span class="label-text font-medium">Description</span>
            </label>
            <textarea wire:model="description"
                class="textarea textarea-bordered w-full @error('description') textarea-error @enderror"
                placeholder="Description optionnelle du groupe" rows="3"></textarea>
            @error('description')
                <label class="label py-1">
                    <span class="label-text-alt text-error">{{ $message }}</span>
                </label>
            @enderror
        </div>

        {{-- Type de groupe --}}
        <div class="form-control w-full">
            <label class="label py-2">
                <x-atoms.tooltip label="Type de groupe" labelClass="label-text font-medium" icon="true"
                    iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                    Les groupes physiques sont synchronisés avec l'AD et appliquent les GPO selon la hiérarchie des
                    salles. Les groupes logiques sont gérés localement pour les applications, indépendamment de l'emplacement
                    physique.
                </x-atoms.tooltip>
                <span class="text-error">*</span>
            </label>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Groupe physique --}}
                <label
                    class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ $is_physical ? 'ring-2 ring-primary' : '' }}">
                    <div class="card-body p-4">
                        <div class="flex items-start gap-3">
                            <input type="radio" wire:model.live="is_physical" value="1"
                                class="radio radio-primary mt-1" />
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <i class="fa-solid fa-building text-info"></i>
                                    <span class="font-semibold">Groupe physique</span>
                                </div>
                                <p class="text-xs text-base-content/70 leading-relaxed">
                                    Salle ou bâtiment (OU dans Active Directory). Utilisé pour l'application des GPO
                                    et la hiérarchie des salles.
                                </p>
                            </div>
                        </div>
                    </div>
                </label>

                {{-- Groupe logique --}}
                <label
                    class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ !$is_physical ? 'ring-2 ring-primary' : '' }}">
                    <div class="card-body p-4">
                        <div class="flex items-start gap-3">
                            <input type="radio" wire:model.live="is_physical" value="0"
                                class="radio radio-primary mt-1" />
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <i class="fa-solid fa-network-wired text-warning"></i>
                                    <span class="font-semibold">Groupe logique</span>
                                </div>
                                <p class="text-xs text-base-content/70 leading-relaxed">
                                    Parc de machines (CN dans OU=Parcs). Utilisé pour les applications et les permissions,
                                    indépendamment de l'emplacement physique.
                                </p>
                            </div>
                        </div>
                    </div>
                </label>
            </div>
        </div>

        {{-- Groupe parent (uniquement pour les groupes physiques) --}}
        @if ($is_physical)
            <div class="form-control w-full">
                <label class="label py-2">
                    <span class="label-text font-medium">Groupe parent</span>
                </label>
                <select wire:model="parent_id" class="select select-bordered w-full">
                    <option value="">Aucun (groupe racine)</option>
                    @foreach ($availableParents as $parent)
                        <option value="{{ $parent->id }}">{{ $parent->display_name_or_name }}</option>
                    @endforeach
                </select>
                <label class="label py-1">
                    <span class="label-text-alt text-base-content/60 whitespace-normal">
                        Le parent définit la hiérarchie des OU dans Active Directory.
                    </span>
                </label>
            </div>
        @endif

        {{-- Label de contrat amont (Story 30.2) — masqué si pas de contrat actif (NFR3). --}}
        @if ($hasActiveContract)
            <div class="form-control w-full">
                <label class="label py-2">
                    <x-atoms.tooltip label="Label de contrat amont" labelClass="label-text font-medium" icon="true"
                        iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                        Rattache ce parc à un label « libre » défini par l'autorité amont, pour cibler les
                        politiques imposées. Au plus un label par groupe. Les labels réservés à l'autorité amont ne
                        sont pas attribuables.
                    </x-atoms.tooltip>
                </label>

                @if ($reservedLabelHeld !== null)
                    {{-- Label réservé porté (cas 30.3) : lecture seule, jamais éditable par le refnum. --}}
                    <select class="select select-bordered w-full" disabled>
                        <option>{{ $reservedLabelHeld }}</option>
                    </select>
                    <label class="label py-1">
                        <span class="label-text-alt text-warning whitespace-normal">
                            <i class="fa-solid fa-lock text-xs"></i>
                            Label réservé — imposé par l'autorité amont, non modifiable.
                        </span>
                    </label>
                @else
                    <select wire:model="controlhubLabel" class="select select-bordered w-full">
                        <option value="">Aucun</option>
                        @foreach ($freeLabelNames as $labelName)
                            <option value="{{ $labelName }}">{{ $labelName }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        @endif

        {{-- Environnement / nature des postes (Story 26.1) --}}
        <div class="form-control w-full">
            <label class="label py-2">
                <x-atoms.tooltip label="Environnement des postes" labelClass="label-text font-medium" icon="true"
                    iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                    Détermine le comportement du bureau et des profils des postes. Un poste appartenant à plusieurs
                    parcs hérite du plus « fort » : <strong>nomade &gt; personnel &gt; partagé</strong>.
                </x-atoms.tooltip>
            </label>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                @foreach (\App\Enums\WorkstationEnvironment::cases() as $env)
                    <label
                        class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ $environment === $env->value ? 'ring-2 ring-primary' : '' }}">
                        <div class="card-body p-3 flex-row items-center gap-2">
                            <input type="radio" wire:model.live="environment" value="{{ $env->value }}"
                                class="radio radio-primary radio-sm" />
                            <i class="fa-solid {{ $env->icon() }} text-primary"></i>
                            <span class="font-medium text-sm flex-1">{{ $env->shortLabel() }}</span>
                            <x-atoms.tooltip icon="true"
                                iconClass="fa-solid fa-circle-info text-base-content/40 text-xs">
                                {{ $env->description() }}
                            </x-atoms.tooltip>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled"
                wire:target="save">
                <span wire:loading.remove wire:target="save">
                    <i class="fa-solid fa-check"></i>
                    {{ $editingId ? 'Enregistrer' : 'Créer le groupe' }}
                </span>
                <span wire:loading wire:target="save">
                    <span class="loading loading-spinner loading-xs"></span> Enregistrement...
                </span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</div>
