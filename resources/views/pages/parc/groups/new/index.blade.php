<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use App\Services\Parc\WorkstationGroupService;
use App\Services\ControlHub\WorkstationGroupLabelService;
use App\Exceptions\ControlHub\LabelAssignmentException;
use App\Enums\ControlHubLabelMode;
use App\Models\ControlHubContract;
use App\Components\Traits\WithToasts;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

new #[Title('Nouveau Groupe - SE4FS')] class extends Component {
    use WithToasts;
    use AuthorizesRequests;

    private WorkstationGroupService $parcService;

    // Données du formulaire
    public string $name = '';
    public string $description = '';
    public ?int $parent_id = null;
    public bool $is_physical = true;
    public bool $createAppProfile = false;
    public string $appProfileName = '';
    public array $selectedMachines = [];
    public bool $showMachineModal = false;

    // Label de contrat amont (Story 30.2). '' = aucun. Section masquée si pas de
    // contrat amont actif (NFR3). Seuls les labels libres sont assignables ici.
    public string $controlhubLabel = '';
    // Propriétés DÉRIVÉES côté serveur (#[Locked] : non mutables par requête forgée — review 30.2 M2).
    #[Locked]
    public bool $hasActiveContract = false;
    /** @var array<int,string> Noms des labels libres assignables du contrat actif. */
    #[Locked]
    public array $freeLabelNames = [];

    // Données pour les sélecteurs
    public Collection $availableParents;

    public function boot(WorkstationGroupService $parcService): void
    {
        $this->parcService = $parcService;
    }

    public function mount(): void
    {
        $this->availableParents = collect();
        $this->loadParents();
        $this->loadControlHubLabels();
    }

    /**
     * Story 30.2 — Charge le contrat amont actif et ses labels libres (free).
     * NFR3 : sans contrat actif, la section est masquée et aucun label proposé.
     */
    public function loadControlHubLabels(): void
    {
        $activeContract = ControlHubContract::active();
        $this->hasActiveContract = $activeContract !== null;

        $this->freeLabelNames = $activeContract === null
            ? []
            : $activeContract->labels()
                ->where('mode', ControlHubLabelMode::Free)
                ->orderBy('name')
                ->pluck('name')
                ->all();
    }

    public function loadParents(): void
    {
        try {
            $this->availableParents = $this->parcService->getRootGroupsForSelect();
        } catch (\Exception $e) {
            Log::error('[GroupCreate] Erreur chargement parents: ' . $e->getMessage());
            $this->availableParents = collect();
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'parent_id' => 'nullable|integer|exists:workstation_groups,id',
            'is_physical' => 'boolean',
            'createAppProfile' => 'boolean',
            'appProfileName' => 'nullable|string|max:255',
            // Story 30.2 — borne défensive ; l'appartenance au contrat actif est
            // tranchée par WorkstationGroupLabelService::assignLabel().
            'controlhubLabel' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom du groupe est requis.',
            'name.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'description.max' => 'La description ne peut pas dépasser 500 caractères.',
            'parent_id.exists' => 'Le groupe parent sélectionné n\'existe pas.',
            'appProfileName.max' => 'Le nom du profil applicatif ne peut pas dépasser 255 caractères.',
        ];
    }

    public function updatedName($value): void
    {
        // Pré-remplir le nom du profil applicatif avec le nom du groupe
        if (empty($this->appProfileName) || $this->appProfileName === $this->getOriginal('name')) {
            $this->appProfileName = $value;
        }
    }

    public function updatedCreateAppProfile($value): void
    {
        // Si on active le toggle et que le nom du profil est vide, le pré-remplir
        if ($value && empty($this->appProfileName)) {
            $this->appProfileName = $this->name;
        }
    }

    public function save(WorkstationGroupLabelService $labelService): void
    {
        // Story 30.2 (AC #8) — Gate scopé AVANT toute écriture. La création d'un
        // groupe est protégée par `create-workstationGroup` (= canAdminComputers,
        // même socle que `update-workstationGroup` utilisé en édition) ; un délégué
        // hors périmètre est refusé avant toute création/assignation de label.
        $this->authorize('create-workstationGroup');

        $validated = $this->validate();

        try {
            // Déterminer le nom du profil applicatif à créer
            $appProfileName = null;
            if ($this->createAppProfile && !empty($this->appProfileName)) {
                $appProfileName = trim($this->appProfileName);
            }

            $group = $this->parcService->createGroup([
                'name' => $validated['name'],
                'description' => $validated['description'] ?: null,
                'parent_id' => $validated['parent_id'] ?: null,
                'is_physical' => $validated['is_physical'],
                'app_profile_name' => $appProfileName,
            ]);

            // Story 30.2 (AC #3) — Rattacher le label libre choisi via le service
            // dédié (chemin de création parc existant réutilisé, pas un chemin
            // parallèle). Un refus métier annule l'assignation mais pas la création
            // (le groupe existe déjà) → toast + on reste sur place.
            if ($this->controlhubLabel !== '') {
                try {
                    $labelService->assignLabel($group, $this->controlhubLabel);
                } catch (LabelAssignmentException $e) {
                    // Le groupe est créé mais le label a été refusé. On redirige vers
                    // l'édition pour corriger → le toast DOIT survivre au redirect :
                    // session flash (toastError = event navigateur, perdu au redirect,
                    // review 30.2 M3).
                    session()->flash('toast', [
                        'type' => 'error',
                        'title' => 'Label non attribué',
                        'message' => $e->getMessage(),
                    ]);
                    $this->redirect(route('app.parc.groups.edit', $group->id));
                    return;
                }
            }

            // Ajouter les machines sélectionnées au groupe
            if (!empty($this->selectedMachines)) {
                $this->parcService->bulkAddMachinesToGroup($this->selectedMachines, $group->id);
            }

            $message = "Le groupe \"{$group->name}\" a été créé avec succès.";
            if ($appProfileName) {
                $message .= " Un profil applicatif \"{$appProfileName}\" a également été créé.";
            }

            session()->flash('toast', [
                'type' => 'success',
                'title' => 'Groupe créé',
                'message' => $message,
            ]);

            $this->redirect(route('app.parc.groups.show', $group->id));
        } catch (\App\Exceptions\ControlHub\UpstreamLockCollisionException $e) {
            // Story 30.5 — collision verrou/verrou prédite au rattachement des
            // machines initiales : message explicite, sans redirection.
            $this->toastError($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[GroupCreate] Erreur création: ' . $e->getMessage());
            $this->toastError('Une erreur est survenue lors de la création du groupe.');
        }
    }
};
?>

<x-organisms.page title="Nouveau Groupe" :scrollable="true" description="Créer un nouveau groupe de machines"
    backUrl="{{ route('app.parc.index') }}" backText="Retour">

    <div class="max-w-2xl mx-auto">
        <form wire:submit="save" class="space-y-6">
            <!-- Informations de base -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h3 class="card-title text-lg mb-4">
                        <i class="fa-solid fa-info-circle text-primary"></i>
                        Informations générales
                    </h3>

                    <!-- Nom -->
                    <div class="form-control w-full">
                        <label class="label py-2">
                            <span class="label-text font-medium">Nom du groupe <span class="text-error">*</span></span>
                        </label>
                        <input type="text" wire:model="name"
                            class="input input-bordered w-full @error('name') input-error @enderror"
                            placeholder="Ex: Salle-Info-101, Parc-Portables">
                        @error('name')
                            <label class="label py-1">
                                <span class="label-text-alt text-error">{{ $message }}</span>
                            </label>
                        @enderror
                    </div>

                    <!-- Description -->
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

                    <!-- Type de groupe -->
                    <div class="form-control w-full">
                        <label class="label py-2">
                            <x-atoms.tooltip label="Type de groupe" labelClass="label-text font-medium" icon="true"
                                iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                                Les groupes physiques permettent d'appliquer les GPO selon la hiérarchie des salles. Les
                                groupes logiques sont utilisés pour appliquer les profils applicatifs.
                            </x-atoms.tooltip>
                            <span class="text-error">*</span>
                        </label>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Groupe physique -->
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
                                                Salle, partie de salle ou groupe de salles. Utilisé pour l'application
                                                des GPO.
                                                Si une salle fait partie d'un groupe de salle sur lequel une GPO
                                                s'applique, cette GPO s'applique également à la salle.
                                                On parle d'héritage
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </label>

                            <!-- Groupe logique -->
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
                                                groupe de machines indépendant et transversal.
                                                Utilisé pour L'installation des applications.
                                                On peut associer une ou plusieurs machines ou des groupes physiques
                                                indépendamment de leur emplacement.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Groupe parent (uniquement pour les groupes physiques) -->
                    @if ($is_physical)
                        <div class="form-control w-full">
                            <label class="label py-2">
                                <span class="label-text font-medium">Groupe parent</span>
                            </label>
                            <select wire:model="parent_id" class="select select-bordered w-full">
                                <option value="">Aucun (groupe racine)</option>
                                @foreach ($availableParents as $parent)
                                    <option value="{{ $parent->id }}">{{ $parent->name }}</option>
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
                                <x-atoms.tooltip label="Label de contrat amont" labelClass="label-text font-medium"
                                    icon="true"
                                    iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                                    Rattache ce parc à un label « libre » défini par l'autorité amont. Au plus un label
                                    par groupe. Les labels réservés à l'autorité amont ne sont pas attribuables.
                                </x-atoms.tooltip>
                            </label>
                            <select wire:model="controlhubLabel" class="select select-bordered w-full">
                                <option value="">Aucun</option>
                                @foreach ($freeLabelNames as $labelName)
                                    <option value="{{ $labelName }}">{{ $labelName }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Profil applicatif -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h3 class="card-title text-lg mb-4">
                        <i class="fa-solid fa-boxes-stacked text-primary"></i>
                        Profil applicatif
                    </h3>

                    <!-- Toggle créer un profil applicatif -->
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-4">
                            <input type="checkbox" wire:model.live="createAppProfile" class="toggle toggle-primary" />
                            <div>
                                <span class="label-text font-medium">Créer un profil applicatif</span>
                                <p class="text-sm text-base-content/60 whitespace-normal">
                                    Un profil applicatif permet d'associer des applications à ce groupe de machines
                                </p>
                            </div>
                        </label>
                    </div>

                    <!-- Nom du profil applicatif (affiché si toggle activé) -->
                    @if ($createAppProfile)
                        <div class="form-control w-full mt-4" wire:transition>
                            <label class="label py-2">
                                <span class="label-text font-medium">Nom du profil applicatif</span>
                            </label>
                            <input type="text" wire:model="appProfileName"
                                class="input input-bordered w-full @error('appProfileName') input-error @enderror"
                                placeholder="Nom du profil applicatif">
                            @error('appProfileName')
                                <label class="label py-1">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                            <label class="label py-1">
                                <span class="label-text-alt text-base-content/60">
                                    Par défaut, le nom du groupe est utilisé. Vous pouvez le modifier si nécessaire.
                                </span>
                            </label>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Sélection des machines -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="card-title text-lg">
                            <i class="fa-solid fa-desktop text-primary"></i>
                            Machines du groupe
                            @if (count($selectedMachines) > 0)
                                <span class="badge badge-primary">{{ count($selectedMachines) }}</span>
                            @endif
                        </h3>
                        <button type="button" class="btn btn-primary btn-sm"
                            wire:click="$set('showMachineModal', true)">
                            <i class="fa-solid fa-plus"></i>
                            Sélectionner
                        </button>
                    </div>

                    @if (empty($selectedMachines))
                        <div class="flex flex-col items-center justify-center py-8 text-center">
                            <div class="text-4xl mb-3 opacity-20">
                                <i class="fa-solid fa-desktop"></i>
                            </div>
                            <p class="text-base-content/60 text-sm">
                                Aucune machine sélectionnée. Vous pourrez en ajouter d'autres plus tard.
                            </p>
                        </div>
                    @else
                        @php
                            $machineNames = \App\Models\Workstation::whereIn('id', $selectedMachines)->pluck(
                                'name',
                                'id',
                            );
                        @endphp
                        <div class="flex flex-wrap gap-2">
                            @foreach ($selectedMachines as $machineId)
                                <div class="badge badge-lg gap-2 pr-1">
                                    <i class="fa-solid fa-desktop text-xs"></i>
                                    {{ $machineNames[$machineId] ?? "#$machineId" }}
                                    <button type="button" class="btn btn-ghost btn-xs btn-circle"
                                        wire:click="$set('selectedMachines', {{ json_encode(array_values(array_filter($selectedMachines, fn($id) => $id !== $machineId))) }})">
                                        <i class="fa-solid fa-xmark text-xs"></i>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            @teleport('body')
                <dialog class="modal" x-data="{ open: @entangle('showMachineModal') }" :class="{ 'modal-open': open }" x-cloak>
                    <div class="modal-box w-11/12 max-w-2xl">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold flex items-center gap-2">
                                <i class="fa-solid fa-desktop text-primary"></i>
                                Sélectionner des machines
                            </h3>
                            <button type="button" wire:click="$set('showMachineModal', false)"
                                class="btn btn-sm btn-circle btn-ghost">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        <p class="text-sm text-base-content/60 mb-4">
                            Sélectionnez les machines à ajouter à ce groupe.
                        </p>
                        <x-molecules.machine-selector wire:model="selectedMachines" maxHeight="400px" />
                        <div class="modal-action">
                            <button type="button" class="btn btn-primary" wire:click="$set('showMachineModal', false)">
                                <i class="fa-solid fa-check"></i>
                                Valider la sélection
                            </button>
                        </div>
                    </div>
                    <form method="dialog" class="modal-backdrop">
                        <button wire:click="$set('showMachineModal', false)">close</button>
                    </form>
                </dialog>
            @endteleport

            <!-- Actions -->
            <div class="flex justify-end gap-3">
                <a href="{{ route('app.parc.index') }}" class="btn btn-ghost">
                    Annuler
                </a>
                <button type="submit" class="btn btn-primary">
                    <span wire:loading.remove wire:target="save">
                        <i class="fa-solid fa-check"></i>
                        Créer le groupe
                    </span>
                    <span wire:loading wire:target="save" class="loading loading-spinner loading-sm"></span>
                </button>
            </div>
        </form>
    </div>
</x-organisms.page>
