<?php

use App\Components\Traits\WithToasts;
use App\Models\Capability;
use App\Services\ControlHub\UpstreamLockResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 27.17 — Onglet « Registre / capacités » de /admin/settings/parc-defaults.
 *
 * Édite le DÉFAUT DIFFUSÉ de chaque capacité (`capabilities.default_value`) via
 * le flow `saveDefault()` / `toggleLock` (anciennement page /admin/settings/capabilities
 * 27.12, désormais consolidée ici — page doublon supprimée, décision Henri 27.17).
 * Le défaut est diffusé à toute la flotte par la maille Broadcast (provider
 * `Registry{Machine,User}CapabilityProvider`, 27.12). Les OVERRIDES PAR PARC
 * restent sur l'onglet « Options/Capacités » de la page du groupe — NON touchés
 * ici (on n'écrit que `default_value` et `overrides_locked`).
 *
 * Décision Henri : tout en `server.admin` (le flow capabilities l'était déjà).
 * Chaque action mutante re-garde `Gate::authorize('server.admin')`.
 */
new class extends Component {
    use WithToasts;

    public ?int $editingCapabilityId = null;

    public bool $showEditModal = false;

    public string $formValue = '';

    public bool $warningAcknowledged = false;

    public function mount(): void
    {
        $this->guardAdmin();
    }

    /** @return array<int,array<string,mixed>> */
    #[Computed]
    public function capabilities(): array
    {
        // Stories 29.2/29.4 — statut amont pré-calculé une fois (set mémoïsé ;
        // court-circuit NFR3 sans contrat) pour éviter le N+1.
        $lock = app(UpstreamLockResolver::class);

        return Capability::query()
            ->with('projections')
            ->orderBy('category')
            ->orderBy('label')
            ->get()
            ->map(function (Capability $c) use ($lock): array {
                // Story 29.4 — statut tri-état : 'locked'|'permissive'|'local'.
                // `is_upstream_locked` dérivé du statut (évite un double appel).
                $upstreamStatus = $lock->capabilityUpstreamStatus($c);

                return [
                    'id' => (int) $c->id,
                    'label' => (string) $c->label,
                    'description' => (string) ($c->description ?? ''),
                    'category' => (string) ($c->category ?? ''),
                    'value_type' => (string) $c->value_type,
                    'default_display' => $c->optionLabel((string) $c->default_value),
                    'overrides_locked' => (bool) $c->overrides_locked,
                    'is_active' => (bool) $c->is_active,
                    'has_warning' => $c->hasWarning(),
                    'is_upstream_locked' => $upstreamStatus === 'locked',
                    'upstream_status' => $upstreamStatus,
                    // Story 43.2 (D5/D6) — temporalité d'effet ; null = AUCUN
                    // badge (capacité sans clé HKCU registre). Dérivé sur la
                    // relation `projections` DÉJÀ eager-loaded ci-dessus (zéro
                    // requête ajoutée).
                    'effect_timing' => $c->effectTiming(),
                ];
            })
            ->all();
    }

    /**
     * Story 29.4 (#3) — Un contrat amont actif est-il présent ? Aucune requête
     * supplémentaire (singleton mémoïsé — réutilise `ensureResolved()`). Permet
     * de gater l'affichage des badges tri-état : en standalone (aucun contrat),
     * AUCUN badge n'est rendu → UI byte-identique à 27.17 (NFR3).
     */
    #[Computed]
    public function hasUpstreamContract(): bool
    {
        return app(UpstreamLockResolver::class)->hasActiveContract();
    }

    #[Computed]
    public function editingCapability(): ?Capability
    {
        // Story 43.2 — `with('projections')` : la modale affiche AUSSI le
        // badge de temporalité d'effet (effectTiming()) sans requête ajoutée.
        return $this->editingCapabilityId !== null
            ? Capability::query()->with('projections')->find($this->editingCapabilityId)
            : null;
    }

    public function openEdit(int $capabilityId): void
    {
        $this->guardAdmin();

        $capability = Capability::query()->findOrFail($capabilityId);

        // Story 35.5 (review #2) : une capacité inactive (gate is_active) est
        // ignorée par le provider — éditer son défaut serait un réglage sans
        // effet posé silencieusement. Refus explicite, pas d'opacité seule.
        if (! $capability->is_active) {
            $this->toastError('Capacité inactive : le réglage n\'aurait aucun effet tant que le gate n\'est pas levé.');
            return;
        }

        if (! $this->authorizeUpstream($capability)) {
            return;
        }

        $this->resetForm();
        $this->editingCapabilityId = (int) $capability->id;
        $this->formValue = (string) $capability->default_value;
        $this->showEditModal = true;
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->showEditModal = false;
    }

    public function toggleLock(int $capabilityId): void
    {
        $this->guardAdmin();

        $capability = Capability::query()->findOrFail($capabilityId);

        // Story 29.2 — un item verrouillé amont interdit aussi de (dé)geler
        // localement la capacité correspondante (le gel local 27.12 ne doit pas
        // servir de contournement du verrou amont).
        if (! $this->authorizeUpstream($capability)) {
            return;
        }

        $capability->overrides_locked = ! $capability->overrides_locked;
        $capability->save();

        $this->toastSuccess($capability->overrides_locked
            ? 'Capacité gelée : plus de nouveaux overrides (les déviations existantes restent gérées).'
            : 'Capacité dégelée : les parcs peuvent à nouveau la dévier.');

        unset($this->capabilities);
    }

    public function saveDefault(): void
    {
        $this->guardAdmin();

        $capability = $this->editingCapability;
        if ($capability === null) {
            $this->toastError('Capacité introuvable.');
            return;
        }

        // Story 35.5 (review #2) — defense-in-depth : refus même si l'UI est
        // contournée (openEdit refuse déjà, mais l'action reste appelable).
        if (! $capability->is_active) {
            $this->toastError('Capacité inactive : le réglage n\'aurait aucun effet tant que le gate n\'est pas levé.');
            return;
        }

        // Story 29.2 — refus SERVEUR (defense-in-depth) : éditer le défaut diffusé
        // d'une capacité verrouillée amont est refusé même si l'UI est contournée.
        if (! $this->authorizeUpstream($capability)) {
            return;
        }

        if ($capability->hasWarning() && ! $this->warningAcknowledged) {
            $this->addError('warningAcknowledged', 'Vous devez confirmer avoir lu les implications de cette capacité.');
            return;
        }

        $value = $this->validatedValue($capability);

        $capability->default_value = $value;
        $capability->save();

        $this->toastSuccess('Valeur par défaut enregistrée (appliquée à tous les parcs sans override).');
        $this->closeModal();
        unset($this->capabilities);
    }

    private function validatedValue(Capability $capability): string
    {
        $value = trim($this->formValue);

        if ($capability->hasOptions()) {
            if (! in_array($value, $capability->allowedOptionValues(), true)) {
                throw ValidationException::withMessages([
                    'formValue' => 'Choisissez une valeur parmi les options proposées.',
                ]);
            }

            return $value;
        }

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
        $this->formValue = '';
        $this->warningAcknowledged = false;
        $this->resetErrorBag();
    }

    private function guardAdmin(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }
    }

    /**
     * Story 29.2 — garde de VERROU AMONT (defense-in-depth). `server.admin` est
     * DÉJÀ vérifié par guardAdmin() en tête de chaque mutation. Le gate
     * `modify-capability` ajoute le verrou amont : un item `locked`/`instance`/
     * `registry` matchant une clé de la capacité refuse l'édition du défaut ET le
     * (dé)gel local. Refus = toast explicite + arrêt (retourne false). [AC #2, #5, #6]
     */
    private function authorizeUpstream(Capability $capability): bool
    {
        try {
            Gate::authorize('modify-capability', $capability);

            return true;
        } catch (AuthorizationException) {
            // Story 29.8 — depuis le retrait du plancher de droit dans
            // `CapabilityPolicy::modify`, ce gate ne refuse PLUS que pour VERROU
            // AMONT : le droit GLOBAL est filtré EN AMONT par `guardAdmin()`
            // (`server.admin`) qui aborte 403 avant d'atteindre ce point. La branche
            // « pas le droit » ci-dessous est donc théoriquement INATTEIGNABLE par
            // défaut de droit, mais on CONSERVE la double-branche (ceinture +
            // bretelles) comme garde-fou contre un futur appelant non gardé : on
            // afficherait un message correct plutôt qu'un faux « verrouillé amont ».
            if (app(UpstreamLockResolver::class)->isCapabilityLocked($capability)) {
                $this->toastError('Cette capacité est verrouillée par un contrat amont et ne peut pas être modifiée localement.');
            } else {
                $this->toastError("Vous n'avez pas le droit de modifier cette capacité.");
            }

            return false;
        }
    }
};
?>

<div>
    <x-molecules.settings-section
        title="Registre / capacités — valeurs par défaut diffusées"
        icon="fa-solid fa-sliders"
        color="primary"
        description="Valeur par défaut de chaque capacité (option métier des postes), diffusée à TOUTE la flotte (maille Broadcast). Un parc peut dévier une capacité via l'onglet « Options / Capacités » de sa page.">

        <div class="card bg-base-100 shadow-sm border border-base-200 w-full col-span-full">
            <div class="card-body">
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Capacité</th>
                                <th>Catégorie</th>
                                <th>Défaut</th>
                                <th>Nouveaux overrides</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->capabilities as $capability)
                                <tr @class(['opacity-50' => ! $capability['is_active']])>
                                    <td>
                                        <div class="font-medium flex items-center gap-1 flex-wrap">
                                            {{ $capability['label'] }}
                                            @if ($capability['description'] !== '')
                                                <span class="tooltip tooltip-right before:max-w-xs before:whitespace-normal"
                                                    data-tip="{{ $capability['description'] }}">
                                                    <i class="fa-solid fa-circle-info text-xs opacity-40 cursor-help"
                                                        aria-label="{{ $capability['description'] }}"></i>
                                                </span>
                                            @endif
                                            @if ($capability['has_warning'])
                                                <i class="fa-solid fa-triangle-exclamation text-warning text-xs"
                                                    aria-label="Capacité sensible"></i>
                                            @endif
                                            {{-- Story 43.2 (D5/D6) — badge de temporalité d'effet : AUCUN badge
                                                 pour une capacité sans clé HKCU registre (piège n°8). --}}
                                            @if ($capability['effect_timing'] !== null)
                                                <span class="badge badge-sm badge-outline gap-1"
                                                    data-testid="effect-timing-{{ $capability['id'] }}"
                                                    title="{{ $capability['effect_timing']['tooltip'] }}">
                                                    <i class="fa-solid fa-clock text-xs"></i>
                                                    {{ $capability['effect_timing']['label'] }}
                                                </span>
                                            @endif
                                            {{-- Story 29.4 — tri-état : verrouillé > permissif > local (AC #1-4).
                                                 Libellés centrés sur l'ACTION possible (décision 2026-06-27).
                                                 #3 : badges gatés sur hasUpstreamContract() — en standalone,
                                                 AUCUN badge n'est rendu (UI byte-identique à 27.17, NFR3). --}}
                                            @if ($this->hasUpstreamContract)
                                                @if ($capability['is_upstream_locked'])
                                                    <span class="badge badge-sm badge-neutral gap-1"
                                                        data-testid="upstream-locked-{{ $capability['id'] }}"
                                                        title="Amont — non modifiable.">
                                                        <i class="fa-solid fa-lock text-xs"></i> Verrouillé
                                                    </span>
                                                @elseif ($capability['upstream_status'] === 'permissive')
                                                    <span class="badge badge-sm badge-info gap-1"
                                                        data-testid="upstream-permissive-{{ $capability['id'] }}"
                                                        title="Proposé par l'amont mais modifiable : votre réglage local prévaut.">
                                                        <i class="fa-solid fa-pen text-xs"></i> Modifiable
                                                    </span>
                                                @else
                                                    {{-- #1 : surface = défaut diffusé flotte (Broadcast) — tooltip
                                                         différencié de capabilities-tab (« parc/groupe »). --}}
                                                    <span class="badge badge-sm badge-ghost gap-1"
                                                        data-testid="upstream-local-{{ $capability['id'] }}"
                                                        title="Défaut diffusé — aucune contrainte amont.">
                                                        <i class="fa-solid fa-location-dot text-xs"></i> Local
                                                    </span>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-xs opacity-60">{{ $capability['category'] }}</td>
                                    <td class="font-medium">{{ $capability['default_display'] }}</td>
                                    <td>
                                        <label class="flex items-center gap-2 {{ $capability['is_upstream_locked'] ? 'opacity-50' : 'cursor-pointer' }}"
                                            title="Gelé = plus de nouveaux overrides par parc (la diffusion reste inchangée).">
                                            <input type="checkbox" class="toggle toggle-warning toggle-sm"
                                                @checked($capability['overrides_locked'])
                                                @disabled($capability['is_upstream_locked'])
                                                @if (! $capability['is_upstream_locked']) wire:click="toggleLock({{ $capability['id'] }})" @endif
                                                data-testid="toggle-lock-{{ $capability['id'] }}" />
                                            <span class="badge badge-sm {{ $capability['overrides_locked'] ? 'badge-warning' : 'badge-ghost' }}">
                                                {{ $capability['overrides_locked'] ? 'Gelé' : 'Ouvert' }}
                                            </span>
                                        </label>
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        {{-- Story 29.4 — tri-état : locked masque le bouton (29.2) ;
                                             permissif le garde actif + explication FR8 (AC #2). --}}
                                        @if ($capability['is_upstream_locked'])
                                            <span class="text-xs opacity-60 italic">Imposé par contrat amont</span>
                                        @else
                                            {{-- Story 35.5 (review #2) : capacité inactive = bouton désactivé,
                                                 le réglage serait sans effet (garde serveur dans openEdit/saveDefault). --}}
                                            <button type="button" class="btn btn-ghost btn-xs"
                                                wire:click="openEdit({{ $capability['id'] }})"
                                                @disabled(! $capability['is_active'])
                                                @if (! $capability['is_active']) title="Capacité inactive : réglage sans effet" @endif
                                                data-testid="edit-default-{{ $capability['id'] }}">
                                                <i class="fa-solid fa-pen"></i> Éditer le défaut
                                            </button>
                                            @if ($capability['upstream_status'] === 'permissive')
                                                <br><span class="text-xs opacity-60 italic"
                                                    data-testid="upstream-permissive-note-{{ $capability['id'] }}">Votre réglage local s'applique</span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center opacity-60 py-6">
                                        Aucune capacité dans le catalogue.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </x-molecules.settings-section>

    {{-- Modale réutilisable : éditer la valeur par défaut --}}
    <x-molecules.modal wire:model="showEditModal"
        title="{{ $this->editingCapability?->label ?? 'Valeur par défaut' }}"
        icon="fa-pen-to-square text-primary"
        size="max-w-2xl" height="h-auto max-h-[85vh]"
        closeMethod="closeModal">

        @if ($this->editingCapability !== null)
            @php($capability = $this->editingCapability)
            <x-molecules.modal.section title="Valeur par défaut diffusée">
                @if ($capability->description)
                    <p class="text-sm opacity-70 mb-2">{{ $capability->description }}</p>
                @endif
                {{-- Story 43.2 (D5/D6) — badge de temporalité d'effet. --}}
                @php($timing = $capability->effectTiming())
                @if ($timing !== null)
                    <span class="badge badge-sm badge-outline gap-1 mb-2" title="{{ $timing['tooltip'] }}">
                        <i class="fa-solid fa-clock text-xs"></i> {{ $timing['label'] }}
                    </span>
                @endif
                <p class="text-xs opacity-70 mb-3">
                    Modifier ce défaut impacte <strong>tous les parcs sans override</strong> sur cette capacité.
                </p>

                <label class="form-control w-full">
                    <span class="label-text mb-1">Valeur par défaut</span>

                    @if ($capability->hasOptions())
                        <select class="select select-bordered w-full" wire:model="formValue"
                            data-testid="default-select">
                            <option value="" disabled>— Choisir —</option>
                            @foreach ($capability->options as $opt)
                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="text" class="input input-bordered w-full"
                            wire:model="formValue" data-testid="default-text" />
                    @endif

                    @error('formValue')
                        <span class="text-error text-sm mt-1">{{ $message }}</span>
                    @enderror
                </label>
            </x-molecules.modal.section>

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
            <button type="button" class="btn btn-primary" wire:click="saveDefault" data-testid="save-default">
                Enregistrer
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</div>
