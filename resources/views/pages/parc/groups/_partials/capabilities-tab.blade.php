<?php

use App\Components\Traits\WithToasts;
use App\Models\Capability;
use App\Models\WorkstationGroup;
use App\Services\ControlHub\UpstreamLockResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 27.12 — Onglet « Options / Capacités » de la page d'un WorkstationGroup.
 *
 * Rewrite capability-first de l'onglet « Registre » (27.3ter) : l'admin manipule
 * une CAPACITÉ (intention métier — « Afficher les extensions », « Bureau à
 * distance »…), jamais une clé de registre (mécanisme caché par la projection).
 *
 * Chaque capacité porte une VALEUR PAR DÉFAUT diffusée à tous les postes
 * (Broadcast). Cet onglet édite les OVERRIDES de VALEUR DE CAPACITÉ par parc :
 * « ce parc applique telle valeur pour cette capacité ». Il ne liste donc QUE les
 * overrides (lignes de `capability_assignments`), + « ajouter / éditer / retirer ».
 *
 * ⚠️ « Retirer » = supprimer l'override = REVENIR AU DÉFAUT (re-convergence au
 * cycle suivant), PAS « cesser de gérer ». Les capacités non listées appliquent
 * leur valeur par défaut.
 *
 * Saisie ADAPTÉE au `value_type` (toggle/select si `options`, sinon champ
 * scalaire) + VALIDATION SERVEUR (value_type/options). Si la capacité porte un
 * `warning` (ex. UAC), un encart + confirmation explicite est exigé.
 *
 * Gate `app.customize` (iso autres réglages parc). Persistance sur le pivot
 * `capability_assignments` (assignable = WorkstationGroup), colonne `value`.
 */
new class extends Component {
    use WithToasts;

    /** WorkstationGroup (parc/salle) édité — passé par la page parente. */
    public int $groupId;

    /** Modale ajouter/éditer un override. */
    public bool $showOverrideModal = false;

    /** Capacité en cours d'ajout/édition (id) ; null = fermé. */
    public ?int $editingCapabilityId = null;

    /** Édite-t-on un override EXISTANT (true) ou en ajoute-t-on un (false) ? */
    public bool $isEditing = false;

    /** Valeur de capacité saisie (string côté formulaire). */
    public string $formValue = '';

    /** Confirmation explicite quand la capacité porte un `warning`. */
    public bool $warningAcknowledged = false;

    public function mount(int $groupId): void
    {
        $this->guardCustomize();
        $this->groupId = $groupId;
    }

    /**
     * Overrides du parc courant : lignes de `capability_assignments` (assignable =
     * ce WG). N'affiche QUE les overrides (capacité + valeur d'override lisible +
     * défaut pour rappel). Repli sur le défaut si `value` est null.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function overrides(): array
    {
        $rows = DB::table('capability_assignments')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->groupId)
            ->get(['capability_id', 'value']);

        if ($rows->isEmpty()) {
            return [];
        }

        $capabilities = Capability::query()
            ->whereIn('id', $rows->pluck('capability_id')->all())
            ->with('projections')
            ->orderBy('label')
            ->get()
            ->keyBy('id');

        // Story 29.2 — pré-calcul du verrou amont une seule fois (set des clés
        // `locked` mémoïsé ; court-circuit NFR3 sans contrat) pour éviter le N+1.
        $lock = app(UpstreamLockResolver::class);

        return $rows->map(function ($row) use ($capabilities, $lock): array {
            $capability = $capabilities->get($row->capability_id);
            if ($capability === null) {
                return [];
            }

            $effective = $row->value ?? (string) $capability->default_value;

            return [
                'id' => (int) $capability->id,
                'label' => (string) $capability->label,
                'description' => (string) ($capability->description ?? ''),
                'category' => (string) ($capability->category ?? ''),
                'override_raw' => (string) ($row->value ?? ''),
                'override_display' => $capability->optionLabel((string) $effective),
                'default_display' => $capability->optionLabel((string) $capability->default_value),
                'has_warning' => $capability->hasWarning(),
                'is_upstream_locked' => $lock->isCapabilityLocked($capability),
            ];
        })->filter()->sortBy('label')->values()->all();
    }

    /**
     * Capacités SANS override pour ce parc (proposées à l'ajout), avec leur valeur
     * par défaut affichée. Exclut les capacités inactives et gelées.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function addableCapabilities(): array
    {
        $overriddenIds = DB::table('capability_assignments')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->groupId)
            ->pluck('capability_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Story 29.2 — verrou amont pré-calculé une fois (court-circuit NFR3).
        $lock = app(UpstreamLockResolver::class);

        return Capability::query()
            ->where('is_active', true)
            ->where('overrides_locked', false)
            ->when($overriddenIds !== [], fn ($q) => $q->whereNotIn('id', $overriddenIds))
            ->with('projections')
            ->orderBy('label')
            ->get()
            // Une capacité verrouillée amont n'est PAS proposée à l'ajout (le geste
            // d'override serait défait au compilé ET refusé au serveur).
            ->reject(fn (Capability $c): bool => $lock->isCapabilityLocked($c))
            ->map(fn (Capability $c): array => [
                'id' => (int) $c->id,
                'label' => (string) $c->label,
                'description' => (string) ($c->description ?? ''),
                'category' => (string) ($c->category ?? ''),
                'default_display' => $c->optionLabel((string) $c->default_value),
            ])
            ->values()
            ->all();
    }

    /** Capacité en cours d'édition dans la modale (ou null). */
    #[Computed]
    public function editingCapability(): ?Capability
    {
        return $this->editingCapabilityId !== null
            ? Capability::query()->find($this->editingCapabilityId)
            : null;
    }

    /** Ouvre la modale en mode AJOUT : pré-remplit avec le défaut de la capacité. */
    public function openAdd(int $capabilityId): void
    {
        $this->guardCustomize();

        $capability = Capability::query()
            ->where('is_active', true)
            ->where('overrides_locked', false)
            ->findOrFail($capabilityId);

        if (! $this->authorizeUpstream($capability)) {
            return;
        }

        $this->resetForm();
        $this->editingCapabilityId = (int) $capability->id;
        $this->isEditing = false;
        $this->formValue = (string) $capability->default_value;
        $this->showOverrideModal = true;
    }

    /** Ouvre la modale en mode ÉDITION : pré-remplit avec l'override actuel. */
    public function openEdit(int $capabilityId): void
    {
        $this->guardCustomize();

        $capability = Capability::query()->findOrFail($capabilityId);

        if (! $this->authorizeUpstream($capability)) {
            return;
        }

        $current = DB::table('capability_assignments')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->groupId)
            ->where('capability_id', $capabilityId)
            ->value('value');

        $this->resetForm();
        $this->editingCapabilityId = (int) $capability->id;
        $this->isEditing = true;
        $this->formValue = (string) ($current ?? $capability->default_value);
        $this->showOverrideModal = true;
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->showOverrideModal = false;
    }

    /**
     * Persiste l'override : valide la valeur saisie contre le `value_type` (et
     * `options`), exige la confirmation du `warning`, puis upsert la colonne
     * `value` du pivot.
     */
    public function saveOverride(): void
    {
        $this->guardCustomize();

        // Re-validation SERVEUR : `editingCapabilityId`/`isEditing` sont des
        // propriétés publiques (hydratées depuis le client en Livewire). Le garde
        // `is_active`/`overrides_locked` ne peut donc PAS vivre seulement dans
        // openAdd()/addableCapabilities() (front). On recharge la capacité filtrée
        // sur `is_active`, et on dérive « nouvel override » de l'EXISTENCE EN BASE
        // (pas du flag client `isEditing`) : une capacité gelée déjà overridée
        // reste éditable (re-convergence), mais aucune gelée ne peut RECEVOIR un
        // nouvel override.
        if ($this->editingCapabilityId === null) {
            $this->toastError('Capacité introuvable.');
            return;
        }

        $capability = Capability::query()
            ->where('is_active', true)
            ->find($this->editingCapabilityId);

        if ($capability === null) {
            $this->toastError('Capacité introuvable ou inactive.');
            return;
        }

        // Story 29.2 — verrou amont (defense-in-depth) : refus SERVEUR même si
        // l'UI est contournée (propriété publique hydratée / rejeu Livewire).
        if (! $this->authorizeUpstream($capability)) {
            return;
        }

        $hasExistingOverride = DB::table('capability_assignments')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->groupId)
            ->where('capability_id', $capability->id)
            ->exists();

        if ($capability->overrides_locked && ! $hasExistingOverride) {
            $this->toastError('Cette capacité est gelée : aucun nouvel override par parc n\'est autorisé.');
            return;
        }

        if ($capability->hasWarning() && ! $this->warningAcknowledged) {
            $this->addError('warningAcknowledged', 'Vous devez confirmer avoir lu les implications de cette capacité.');
            return;
        }

        $value = $this->validatedValue($capability);

        $parc = WorkstationGroup::query()->findOrFail($this->groupId);

        DB::table('capability_assignments')->updateOrInsert(
            [
                'capability_id' => $capability->id,
                'assignable_type' => WorkstationGroup::class,
                'assignable_id' => $parc->id,
            ],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
        );

        $this->toastSuccess($this->isEditing
            ? 'Override mis à jour pour ce parc.'
            : 'Override ajouté pour ce parc.');

        $this->closeModal();
        unset($this->overrides, $this->addableCapabilities);
    }

    /** Retire l'override = REVENIR AU DÉFAUT (re-convergence au cycle suivant). */
    public function removeOverride(int $capabilityId): void
    {
        $this->guardCustomize();

        // Story 29.2 — bloquer AUSSI le retrait d'un item verrouillé amont : pour
        // une UX « refus explicite » cohérente, le refnum ne « touche » pas un item
        // verrouillé (le retrait serait de toute façon inerte, l'amont gagne au
        // compilé). Même message que add/edit.
        $capability = Capability::query()->find($capabilityId);
        if ($capability !== null && ! $this->authorizeUpstream($capability)) {
            return;
        }

        DB::table('capability_assignments')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->groupId)
            ->where('capability_id', $capabilityId)
            ->delete();

        $this->toastSuccess('Override retiré — le parc revient à la valeur par défaut (réappliquée au cycle suivant).');
        unset($this->overrides, $this->addableCapabilities);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Valide la valeur de capacité saisie contre `value_type`/`options` (SQLite
     * n'applique aucune contrainte — validation serveur obligatoire). Lève une
     * ValidationException propre (jamais d'exception au render).
     */
    private function validatedValue(Capability $capability): string
    {
        $value = trim($this->formValue);

        // Choix fermé (toggle/enum) : la valeur doit appartenir aux options.
        if ($capability->hasOptions()) {
            if (! in_array($value, $capability->allowedOptionValues(), true)) {
                throw ValidationException::withMessages([
                    'formValue' => 'Choisissez une valeur parmi les options proposées.',
                ]);
            }

            return $value;
        }

        // Scalaire : non vide.
        if ($value === '') {
            throw ValidationException::withMessages([
                'formValue' => 'La valeur ne peut pas être vide.',
            ]);
        }

        return $value;
    }

    private function resetForm(): void
    {
        $this->editingCapabilityId = null;
        $this->isEditing = false;
        $this->formValue = '';
        $this->warningAcknowledged = false;
        $this->resetErrorBag();
    }

    private function guardCustomize(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('app.customize'),
            403,
            'Permission app.customize requise.',
        );
    }

    /**
     * Story 29.2 — garde de VERROU AMONT (defense-in-depth). `app.customize` est
     * DÉJÀ vérifié par guardCustomize() en amont de chaque mutation ; ici le seul
     * motif de refus du gate `modify-capability` est donc le verrou amont
     * (item `locked`/`instance`/`registry` matchant une clé de la capacité). Le
     * refus se traduit par un toast explicite (pas un échec silencieux) et
     * l'arrêt de la mutation (retourne false). [AC #1, #5, #6]
     */
    private function authorizeUpstream(Capability $capability): bool
    {
        try {
            Gate::authorize('modify-capability', $capability);

            return true;
        } catch (AuthorizationException) {
            $this->toastError('Cette capacité est verrouillée par un contrat amont et ne peut pas être modifiée localement.');

            return false;
        }
    }
};
?>

<div class="space-y-6 mt-4">
    <div class="alert alert-info shadow-sm">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <p class="font-medium">Options / Capacités du parc</p>
            <p class="text-sm opacity-80">
                Chaque capacité a une <strong>valeur par défaut</strong> appliquée à tous les postes. Ici vous
                <strong>déviez</strong> certaines capacités pour ce parc uniquement. Les capacités non listées
                appliquent leur valeur par défaut.
                <strong>Retirer un override = revenir à la valeur par défaut</strong> (réappliquée au cycle suivant).
            </p>
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body">
            <div class="flex items-center justify-between gap-3">
                <h2 class="card-title text-base">Capacités déviées pour ce parc</h2>
                @if (count($this->addableCapabilities) > 0)
                    <button type="button" class="btn btn-sm btn-primary" wire:click="$set('showOverrideModal', true)"
                        data-testid="open-add-override">
                        <i class="fa-solid fa-plus"></i> Ajouter une capacité
                    </button>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Capacité</th>
                            <th>Catégorie</th>
                            <th>Valeur (parc)</th>
                            <th>Défaut</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->overrides as $override)
                            <tr>
                                <td>
                                    <div class="font-medium flex items-center gap-1">
                                        {{ $override['label'] }}
                                        @if ($override['has_warning'])
                                            <i class="fa-solid fa-triangle-exclamation text-warning text-xs"
                                                aria-label="Capacité sensible"></i>
                                        @endif
                                        @if ($override['is_upstream_locked'])
                                            <span class="badge badge-sm badge-neutral gap-1"
                                                data-testid="upstream-locked-{{ $override['id'] }}">
                                                <i class="fa-solid fa-lock text-xs"></i> Verrouillé par contrat amont
                                            </span>
                                        @endif
                                    </div>
                                    @if ($override['description'] !== '')
                                        <div class="text-sm opacity-70">{{ $override['description'] }}</div>
                                    @endif
                                </td>
                                <td class="text-xs opacity-60">{{ $override['category'] }}</td>
                                <td class="font-medium">{{ $override['override_display'] }}</td>
                                <td class="text-xs opacity-60">{{ $override['default_display'] }}</td>
                                <td class="text-right whitespace-nowrap">
                                    @if ($override['is_upstream_locked'])
                                        <span class="text-xs opacity-60 italic">Imposé par contrat amont</span>
                                    @else
                                        <button type="button" class="btn btn-ghost btn-xs"
                                            wire:click="openEdit({{ $override['id'] }})"
                                            data-testid="edit-override-{{ $override['id'] }}">
                                            <i class="fa-solid fa-pen"></i> Éditer
                                        </button>
                                        <button type="button" class="btn btn-ghost btn-xs text-error"
                                            wire:click="removeOverride({{ $override['id'] }})"
                                            data-testid="remove-override-{{ $override['id'] }}">
                                            <i class="fa-solid fa-rotate-left"></i> Retirer
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center opacity-60 py-6">
                                    Aucun override pour ce parc — toutes les capacités appliquent leur valeur par défaut.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modale réutilisable : ajouter / éditer un override --}}
    <x-molecules.modal wire:model="showOverrideModal"
        title="{{ $this->editingCapability ? ($isEditing ? 'Éditer l\'override' : 'Ajouter un override') : 'Ajouter une capacité' }}"
        icon="fa-pen-to-square text-primary"
        size="max-w-2xl" height="h-auto max-h-[85vh]"
        closeMethod="closeModal">

        @if ($this->editingCapability === null)
            {{-- Étape 1 : choix de la capacité à dévier. --}}
            <x-molecules.modal.section title="Choisir une capacité à dévier">
                @if (count($this->addableCapabilities) === 0)
                    <p class="opacity-60 text-sm">Toutes les capacités ont déjà un override sur ce parc.</p>
                @else
                    <div class="flex flex-col gap-2">
                        @foreach ($this->addableCapabilities as $capability)
                            <button type="button"
                                class="flex items-start justify-between gap-3 p-3 rounded-lg border border-base-200 hover:bg-base-200 text-left"
                                wire:click="openAdd({{ $capability['id'] }})"
                                data-testid="pick-capability-{{ $capability['id'] }}">
                                <span class="min-w-0">
                                    <span class="font-medium">{{ $capability['label'] }}</span>
                                    @if ($capability['description'] !== '')
                                        <span class="block text-sm opacity-70">{{ $capability['description'] }}</span>
                                    @endif
                                    <span class="block text-xs opacity-50">Défaut : {{ $capability['default_display'] }}</span>
                                </span>
                                @if ($capability['category'] !== '')
                                    <span class="badge badge-sm badge-ghost shrink-0">{{ $capability['category'] }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif
            </x-molecules.modal.section>
        @else
            @php($capability = $this->editingCapability)
            {{-- Étape 2 : saisie de la valeur d'override (contrôle adapté au value_type). --}}
            <x-molecules.modal.section title="{{ $capability->label }}">
                @if ($capability->description)
                    <p class="text-sm opacity-70 mb-2">{{ $capability->description }}</p>
                @endif

                <label class="form-control w-full">
                    <span class="label-text mb-1">Valeur pour ce parc</span>

                    @if ($capability->hasOptions())
                        {{-- Toggle / enum : sélecteur. --}}
                        <select class="select select-bordered w-full" wire:model="formValue"
                            data-testid="override-select">
                            <option value="" disabled>— Choisir —</option>
                            @foreach ($capability->options as $opt)
                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                    @else
                        {{-- Scalaire : texte. --}}
                        <input type="text" class="input input-bordered w-full"
                            wire:model="formValue" data-testid="override-text" />
                    @endif

                    @error('formValue')
                        <span class="text-error text-sm mt-1">{{ $message }}</span>
                    @enderror
                </label>
            </x-molecules.modal.section>

            {{-- Encart de warning : confirmation explicite avant persistance. --}}
            @if ($capability->hasWarning())
                <x-molecules.modal.section>
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div class="text-sm">{{ $capability->warning }}</div>
                    </div>
                    <label class="label cursor-pointer justify-start gap-2 mt-2">
                        <input type="checkbox" class="checkbox checkbox-warning checkbox-sm"
                            wire:model="warningAcknowledged" data-testid="ack-warning" />
                        <span class="label-text">J'ai lu et compris les implications de cette capacité.</span>
                    </label>
                    @error('warningAcknowledged')
                        <span class="text-error text-sm">{{ $message }}</span>
                    @enderror
                </x-molecules.modal.section>
            @endif
        @endif

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeModal">Annuler</button>
            @if ($this->editingCapability !== null)
                <button type="button" class="btn btn-primary" wire:click="saveOverride" data-testid="save-override">
                    Enregistrer
                </button>
            @endif
        </x-slot:footer>
    </x-molecules.modal>
</div>
