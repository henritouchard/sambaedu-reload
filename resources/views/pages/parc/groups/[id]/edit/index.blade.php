<?php

use Livewire\Component;
use Livewire\Attributes\Title;
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
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

new #[Title('Modifier le Groupe - SE4FS')] class extends Component {
    use WithToasts;
    use AuthorizesRequests;

    private WorkstationGroupService $parcService;

    public string|int $id;
    public ?WorkstationGroup $group = null;

    // Données du formulaire. Le nom technique (`name`) est immuable : il n'est
    // ni saisi ni renvoyé au service. Seul le nom affiché est modifiable.
    public string $display_name = '';
    public string $description = '';
    public ?int $parent_id = null;
    public bool $is_physical = false;
    // Nature des postes du parc (Story 26.1). '' = « non déclaré » → null en base
    // (distinct de shared_local, le défaut étant résolu côté serveur).
    public string $environment = '';

    // Override PAR PARC de la politique de gestion des fichiers (décision Henri
    // 2026-07-17). '' = hérite du défaut global (SystemSetting `files.policy`).
    // Le mode gouverne le montage des lecteurs (DrivesStateProvider) ; les URLs
    // Nextcloud sont saisies ici mais consommées par le provisioning à venir.
    public string $files_policy_mode = '';
    public string $files_nextcloud_server_url = '';
    public string $files_nextcloud_web_url = '';

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

    // Données pour les sélecteurs
    public Collection $availableParents;

    public function boot(WorkstationGroupService $parcService): void
    {
        $this->parcService = $parcService;
    }

    public function mount(string|int $id): void
    {
        $this->id = (int) $id;
        $this->availableParents = collect();
        $this->loadGroup();
        $this->loadParents();
    }

    public function loadGroup(): void
    {
        try {
            $this->group = $this->parcService->getGroup($this->id);

            if (!$this->group) {
                session()->flash('toast', [
                    'type' => 'error',
                    'title' => 'Erreur',
                    'message' => 'Groupe non trouvé',
                ]);
                $this->redirect(route('app.parc.index'));
                return;
            }

            // Remplir le formulaire (repli sur le nom technique pour un groupe
            // legacy sans display_name).
            $this->display_name = $this->group->display_name ?? $this->group->name;
            $this->description = $this->group->description ?? '';
            $this->parent_id = $this->group->parent_id;
            $this->is_physical = (bool) $this->group->is_physical;
            $this->environment = $this->group->environment?->value ?? '';
            $this->files_policy_mode = $this->group->files_policy_mode?->value ?? '';
            $this->files_nextcloud_server_url = $this->group->files_nextcloud_server_url ?? '';
            $this->files_nextcloud_web_url = $this->group->files_nextcloud_web_url ?? '';
            $this->controlhubLabel = $this->group->controlhub_label ?? '';

            $this->loadControlHubLabels();
        } catch (\Exception $e) {
            Log::error('[GroupEdit] Erreur chargement: ' . $e->getMessage());
            $this->toastError('Erreur lors du chargement du groupe');
        }
    }

    public function loadParents(): void
    {
        try {
            $this->availableParents = WorkstationGroup::where('id', '!=', $this->id)->orderBy('name')->get();
        } catch (\Exception $e) {
            Log::error('[GroupEdit] Erreur chargement parents: ' . $e->getMessage());
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
    public function loadControlHubLabels(): void
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

        $current = $this->group->controlhub_label;
        $this->reservedLabelHeld = ($current !== null && !in_array($current, $this->freeLabelNames, true))
            ? $current
            : null;
    }

    public function rules(): array
    {
        return [
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'parent_id' => 'nullable|integer|exists:workstation_groups,id',
            'is_physical' => 'boolean',
            // Story 30.2 — borne défensive ; l'appartenance réelle au contrat actif
            // (free/reserved/inconnu) est tranchée par WorkstationGroupLabelService.
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

    public function save(WorkstationGroupLabelService $labelService): void
    {
        // Story 30.2 (AC #8) — Gate scopé AVANT toute écriture : le refnum (admin
        // instance) passe ; un délégué hors périmètre est refusé. Le mapping de
        // label EST une modification du parc → on réutilise `update-workstationGroup`.
        $this->authorize('update-workstationGroup', $this->group);

        $validated = $this->validate();

        if ($validated['parent_id'] == $this->id) {
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

        // Override politique fichiers : '' = hérite (null). Une valeur non vide
        // doit appartenir à l'enum fermé (sinon requête forgée).
        $filesPolicyMode = null;
        if ($this->files_policy_mode !== '') {
            $filesPolicyMode = \App\Enums\FilePolicyMode::tryFrom($this->files_policy_mode);
            if ($filesPolicyMode === null) {
                $this->toastError('Mode de gestion des fichiers invalide.');
                return;
            }
        }

        try {
            // `name` (technique) est immuable : on ne l'envoie jamais en édition.
            $this->parcService->updateGroup($this->id, [
                'display_name' => $validated['display_name'],
                'description' => $validated['description'] ?: null,
                'parent_id' => $validated['parent_id'] ?: null,
                'is_physical' => $validated['is_physical'],
                'environment' => $environment,
                'files_policy_mode' => $filesPolicyMode,
                'files_nextcloud_server_url' => trim($this->files_nextcloud_server_url) ?: null,
                'files_nextcloud_web_url' => trim($this->files_nextcloud_web_url) ?: null,
            ]);

            // Story 30.2 — Mapping du label de contrat amont via le service dédié
            // (jamais via updateGroup, qui throw sur isLocked — concern distinct).
            // '' = détacher ; sinon assigner. Capture des refus métier → toast,
            // sans redirection (on reste sur le formulaire). NFR3 : sans contrat
            // actif, $controlhubLabel reste '' et detachLabel() est un no-op.
            try {
                if ($this->controlhubLabel === '') {
                    $labelService->detachLabel($this->group);
                } else {
                    $labelService->assignLabel($this->group, $this->controlhubLabel);
                }
            } catch (LabelAssignmentException | UpstreamLockCollisionException $e) {
                // Story 30.5 — collision verrou/verrou prédite : message explicite
                // (item/périmètre/valeurs) en toast, sans redirection.
                $this->toastError($e->getMessage());
                $this->loadGroup();
                return;
            }

            session()->flash('toast', [
                'type' => 'success',
                'title' => 'Groupe modifié',
                'message' => "Le groupe \"{$validated['display_name']}\" a été modifié avec succès.",
            ]);

            $this->redirect(route('app.parc.groups.show', $this->id));
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[GroupEdit] Erreur modification: ' . $e->getMessage());
            $this->toastError('Une erreur est survenue lors de la modification du groupe.');
        }
    }
};
?>

<x-organisms.page title="Modifier {{ $group?->display_name_or_name ?? 'Groupe' }}" :scrollable="true"
    description="Modifier les informations du groupe"
    backUrl="{{ route('app.parc.groups.show', $id) }}" backText="Retour">

    @if ($group)
        <div class="max-w-2xl mx-auto">
            @include('pages.parc.groups.[id].edit._partials.form')
        </div>
    @else
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body flex flex-col items-center justify-center py-16">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <h3 class="text-xl font-semibold mb-3">Groupe non trouvé</h3>
                <a href="{{ route('app.parc.index') }}" class="btn btn-primary">
                    <i class="fa-solid fa-arrow-left"></i>
                    Retour à la liste
                </a>
            </div>
        </div>
    @endif
</x-organisms.page>
