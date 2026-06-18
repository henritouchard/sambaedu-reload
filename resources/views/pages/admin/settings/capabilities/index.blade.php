<?php

use App\Components\Traits\WithToasts;
use App\Models\Capability;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 27.12 — /admin/settings/capabilities : édition des VALEURS PAR DÉFAUT
 * diffusées des capacités (rewrite capability-first de /admin/settings/registry).
 *
 * L'admin fixe ici la valeur par défaut (`capabilities.default_value`) de chaque
 * capacité — le défaut DIFFUSÉ à TOUTE LA FLOTTE via la maille Broadcast. Il peut
 * aussi GELER une capacité (`overrides_locked`) : verrouiller l'ajout de NOUVEAUX
 * overrides sans rien cesser de gérer (la diffusion reste inchangée ; les parcs
 * qui dévient déjà gardent leur override). À NE PAS confondre avec « cesser de
 * gérer ». Même contrôle adapté au value_type + validation serveur que l'onglet
 * parc, + confirmation explicite si la capacité porte un `warning`.
 *
 * La page édite les défauts du CATALOGUE EXISTANT ; elle ne crée pas de capacité
 * arbitraire (le catalogue grossit par seed/migration de données).
 *
 * Sécurité : middleware `can:server.admin` sur la route + double guard mount().
 */
new #[Title('Capacités — valeurs par défaut')] class extends Component {
    use WithToasts;

    /** Modale d'édition du défaut d'une capacité (id) ; null = fermé. */
    public ?int $editingCapabilityId = null;

    public bool $showEditModal = false;

    public string $formValue = '';

    public bool $warningAcknowledged = false;

    public function mount(): void
    {
        $this->guardAdmin();
    }

    /**
     * Catalogue complet (actifs + inactifs) avec défaut formaté.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function capabilities(): array
    {
        return Capability::query()
            ->orderBy('category')
            ->orderBy('label')
            ->get()
            ->map(fn (Capability $c): array => [
                'id' => (int) $c->id,
                'label' => (string) $c->label,
                'description' => (string) ($c->description ?? ''),
                'category' => (string) ($c->category ?? ''),
                'value_type' => (string) $c->value_type,
                'default_display' => $c->optionLabel((string) $c->default_value),
                'overrides_locked' => (bool) $c->overrides_locked,
                'is_active' => (bool) $c->is_active,
                'has_warning' => $c->hasWarning(),
            ])
            ->all();
    }

    #[Computed]
    public function editingCapability(): ?Capability
    {
        return $this->editingCapabilityId !== null
            ? Capability::query()->find($this->editingCapabilityId)
            : null;
    }

    public function openEdit(int $capabilityId): void
    {
        $this->guardAdmin();

        $capability = Capability::query()->findOrFail($capabilityId);
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

    /**
     * Gèle / dégèle une capacité : verrouille l'ajout de NOUVEAUX overrides par
     * parc (`overrides_locked`). NE coupe PAS la diffusion (le défaut + les
     * overrides existants restent gérés).
     */
    public function toggleLock(int $capabilityId): void
    {
        $this->guardAdmin();

        $capability = Capability::query()->findOrFail($capabilityId);
        $capability->overrides_locked = ! $capability->overrides_locked;
        $capability->save();

        $this->toastSuccess($capability->overrides_locked
            ? 'Capacité gelée : plus de nouveaux overrides (les déviations existantes restent gérées).'
            : 'Capacité dégelée : les parcs peuvent à nouveau la dévier.');

        unset($this->capabilities);
    }

    /**
     * Enregistre la valeur par DÉFAUT de la capacité : validation
     * (value_type/options), confirmation du `warning`, écriture de `default_value`.
     */
    public function saveDefault(): void
    {
        $this->guardAdmin();

        $capability = $this->editingCapability;
        if ($capability === null) {
            $this->toastError('Capacité introuvable.');
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

    // ── Helpers (mêmes règles que l'onglet parc) ──────────────────────────

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
};
?>

<x-organisms.page title="Capacités — valeurs par défaut"
    icon="fa-solid fa-sliders"
    description="Fixez la valeur par défaut de chaque capacité — diffusée à TOUTE la flotte (sauf override de parc)."
    back="{{ route('admin.settings') }}">

    <div class="space-y-6 pt-4">
        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">Valeurs par défaut diffusées</p>
                <p class="text-sm opacity-80">
                    La valeur par défaut d'une capacité est appliquée à <strong>tous les parcs sans override</strong>.
                    Un parc peut dévier une capacité via l'onglet « Options / Capacités » de sa page.
                    Les capacités non listées appliquent leur valeur par défaut.
                </p>
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <h2 class="card-title text-base">Catalogue de capacités</h2>

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
                                        <div class="font-medium flex items-center gap-1">
                                            {{ $capability['label'] }}
                                            @if ($capability['has_warning'])
                                                <i class="fa-solid fa-triangle-exclamation text-warning text-xs"
                                                    aria-label="Capacité sensible"></i>
                                            @endif
                                        </div>
                                        @if ($capability['description'] !== '')
                                            <div class="text-sm opacity-70">{{ $capability['description'] }}</div>
                                        @endif
                                    </td>
                                    <td class="text-xs opacity-60">{{ $capability['category'] }}</td>
                                    <td class="font-medium">{{ $capability['default_display'] }}</td>
                                    <td>
                                        <label class="flex items-center gap-2 cursor-pointer"
                                            title="Gelé = plus de nouveaux overrides par parc (la diffusion reste inchangée).">
                                            <input type="checkbox" class="toggle toggle-warning toggle-sm"
                                                @checked($capability['overrides_locked'])
                                                wire:click="toggleLock({{ $capability['id'] }})"
                                                data-testid="toggle-lock-{{ $capability['id'] }}" />
                                            <span class="badge badge-sm {{ $capability['overrides_locked'] ? 'badge-warning' : 'badge-ghost' }}">
                                                {{ $capability['overrides_locked'] ? 'Gelé' : 'Ouvert' }}
                                            </span>
                                        </label>
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <button type="button" class="btn btn-ghost btn-xs"
                                            wire:click="openEdit({{ $capability['id'] }})"
                                            data-testid="edit-default-{{ $capability['id'] }}">
                                            <i class="fa-solid fa-pen"></i> Éditer le défaut
                                        </button>
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
</x-organisms.page>
