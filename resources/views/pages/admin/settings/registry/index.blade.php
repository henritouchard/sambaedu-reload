<?php

use App\Components\Traits\WithToasts;
use App\Models\RegistrySetting;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 27.3ter — /admin/settings/registry : édition des VALEURS PAR DÉFAUT du
 * catalogue de réglages registre.
 *
 * L'admin fixe ici la valeur par défaut (`registry_settings.value`) de chaque
 * réglage — le défaut DIFFUSÉ à TOUTE LA FLOTTE via la maille Broadcast (D1). Il
 * peut aussi GELER un réglage (`overrides_locked`) : verrouiller l'ajout de
 * NOUVEAUX overrides sans rien cesser de gérer (la diffusion reste inchangée ;
 * les parcs qui dévient déjà gardent leur override). À NE PAS confondre avec
 * « cesser de gérer » (décommissionnement, gaté sur la convergence → story de
 * suivi). Même contrôle adapté au type + validation serveur que l'onglet parc,
 * + confirmation explicite si le réglage porte un `warning` (D7).
 *
 * La page édite les défauts du catalogue EXISTANT ; elle NE CRÉE PAS de clé brute
 * arbitraire (éditeur de clés brutes = v2, hors-scope).
 *
 * Sécurité : middleware `can:server.admin` sur la route + double guard mount().
 */
new #[Title('Registre — valeurs par défaut')] class extends Component {
    use WithToasts;

    /** Modale d'édition du défaut d'un réglage (id catalogue) ; null = fermé. */
    public ?int $editingSettingId = null;

    public bool $showEditModal = false;

    public string $formValue = '';

    public array $formMultiLines = [''];

    public bool $warningAcknowledged = false;

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }
    }

    /**
     * Catalogue complet (actifs + inactifs) avec défaut formaté.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function settings(): array
    {
        return RegistrySetting::query()
            ->orderBy('label')
            ->get()
            ->map(fn (RegistrySetting $s): array => [
                'id' => (int) $s->id,
                'label' => (string) $s->label,
                'description' => (string) ($s->description ?? ''),
                'hive' => (string) $s->hive,
                'path' => (string) $s->path,
                'name' => (string) $s->name,
                'type' => (string) $s->type,
                'default_display' => $this->displayValue($s, (string) $s->value),
                'overrides_locked' => (bool) $s->overrides_locked,
                'has_warning' => $s->hasWarning(),
            ])
            ->all();
    }

    #[Computed]
    public function editingSetting(): ?RegistrySetting
    {
        return $this->editingSettingId !== null
            ? RegistrySetting::query()->find($this->editingSettingId)
            : null;
    }

    public function openEdit(int $settingId): void
    {
        $this->guardAdmin();

        $setting = RegistrySetting::query()->findOrFail($settingId);
        $this->resetForm();
        $this->editingSettingId = (int) $setting->id;
        $this->seedFormFromValue($setting, (string) $setting->value);
        $this->showEditModal = true;
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->showEditModal = false;
    }

    /**
     * Gèle / dégèle un réglage : verrouille l'ajout de NOUVEAUX overrides par parc
     * (`overrides_locked`). NE coupe PAS la diffusion (le défaut + les overrides
     * existants restent gérés) — ce n'est PAS un décommissionnement.
     */
    public function toggleLock(int $settingId): void
    {
        $this->guardAdmin();

        $setting = RegistrySetting::query()->findOrFail($settingId);
        $setting->overrides_locked = ! $setting->overrides_locked;
        $setting->save();

        $this->toastSuccess($setting->overrides_locked
            ? 'Réglage gelé : plus de nouveaux overrides (les déviations existantes restent gérées).'
            : 'Réglage dégelé : les parcs peuvent à nouveau le dévier.');

        unset($this->settings);
    }

    /**
     * Enregistre la valeur par DÉFAUT du réglage : validation (type + options),
     * confirmation du `warning` (D7), puis écriture de `registry_settings.value`.
     */
    public function saveDefault(): void
    {
        $this->guardAdmin();

        $setting = $this->editingSetting;
        if ($setting === null) {
            $this->toastError('Réglage introuvable.');
            return;
        }

        if ($setting->hasWarning() && ! $this->warningAcknowledged) {
            $this->addError('warningAcknowledged', 'Vous devez confirmer avoir lu les implications de ce réglage.');
            return;
        }

        $serialized = $this->validatedSerializedValue($setting);

        $setting->value = $serialized;
        $setting->save();

        $this->toastSuccess('Valeur par défaut enregistrée (appliquée à tous les parcs sans override).');
        $this->closeModal();
        unset($this->settings);
    }

    // ── Helpers (mêmes règles que l'onglet parc) ──────────────────────────

    private function validatedSerializedValue(RegistrySetting $setting): string
    {
        $type = strtoupper((string) $setting->type);

        if ($type === 'REG_MULTI_SZ') {
            $lines = array_values(array_filter(
                array_map(static fn ($l): string => (string) $l, $this->formMultiLines),
                static fn (string $l): bool => $l !== '',
            ));

            return json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = trim($this->formValue);

        if ($setting->hasOptions()) {
            if (! in_array($value, $setting->allowedOptionValues(), true)) {
                throw ValidationException::withMessages([
                    'formValue' => 'Choisissez une valeur parmi les options proposées.',
                ]);
            }

            return $value;
        }

        if ($type === 'REG_DWORD' || $type === 'REG_QWORD') {
            if (! preg_match('/^-?\d+$/', $value)) {
                throw ValidationException::withMessages([
                    'formValue' => 'La valeur doit être un nombre entier.',
                ]);
            }

            // Borne SANS (int) : (int) CLAMPE silencieusement à PHP_INT_MAX au-delà
            // (un QWORD à 20 chiffres serait accepté tronqué). Comparaison sur la
            // chaîne de chiffres (iso onglet parc). REG_DWORD = uint32 ; REG_QWORD
            // borné serveur à 2^63-1 (PHP int 64 bits signé, iso provider).
            $maxStr = $type === 'REG_DWORD' ? '4294967295' : (string) PHP_INT_MAX;
            $digits = ltrim(ltrim($value, '-'), '0');
            $digits = $digits === '' ? '0' : $digits;
            $negative = str_starts_with($value, '-') && $digits !== '0';
            $overMax = strlen($digits) > strlen($maxStr)
                || (strlen($digits) === strlen($maxStr) && strcmp($digits, $maxStr) > 0);
            if ($negative || $overMax) {
                throw ValidationException::withMessages([
                    'formValue' => "La valeur doit être comprise entre 0 et {$maxStr}.",
                ]);
            }

            return $digits;
        }

        if ($value === '') {
            throw ValidationException::withMessages([
                'formValue' => 'La valeur ne peut pas être vide.',
            ]);
        }

        return $value;
    }

    private function seedFormFromValue(RegistrySetting $setting, string $serialized): void
    {
        if (strtoupper((string) $setting->type) === 'REG_MULTI_SZ') {
            $decoded = json_decode($serialized, true);
            $this->formMultiLines = is_array($decoded) && $decoded !== []
                ? array_values(array_map(static fn ($v): string => (string) $v, $decoded))
                : [''];
            $this->formValue = '';

            return;
        }

        $this->formValue = $serialized;
        $this->formMultiLines = [''];
    }

    private function displayValue(RegistrySetting $setting, string $serialized): string
    {
        if ($setting->hasOptions()) {
            return $setting->optionLabel($serialized);
        }

        if (strtoupper((string) $setting->type) === 'REG_MULTI_SZ') {
            $decoded = json_decode($serialized, true);

            return is_array($decoded) ? implode(', ', array_map('strval', $decoded)) : $serialized;
        }

        return $serialized;
    }

    public function addMultiLine(): void
    {
        $this->formMultiLines[] = '';
    }

    public function removeMultiLine(int $index): void
    {
        unset($this->formMultiLines[$index]);
        $this->formMultiLines = array_values($this->formMultiLines);
        if ($this->formMultiLines === []) {
            $this->formMultiLines = [''];
        }
    }

    private function resetForm(): void
    {
        $this->editingSettingId = null;
        $this->formValue = '';
        $this->formMultiLines = [''];
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

<x-organisms.page title="Registre — valeurs par défaut"
    icon="fa-solid fa-sliders"
    description="Fixez la valeur par défaut de chaque réglage registre — diffusée à TOUS les postes (sauf override de parc)."
    back="{{ route('admin.settings') }}">

    <div class="space-y-6 pt-4">
        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">Valeurs par défaut diffusées</p>
                <p class="text-sm opacity-80">
                    La valeur par défaut d'un réglage est appliquée à <strong>tous les parcs sans override</strong>
                    sur cette clé. Un parc peut dévier un réglage via l'onglet « Registre » de sa page.
                </p>
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <h2 class="card-title text-base">Catalogue de réglages</h2>

                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Réglage</th>
                                <th>Ruche</th>
                                <th>Défaut</th>
                                <th>Nouveaux overrides</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->settings as $setting)
                                <tr>
                                    <td>
                                        <div class="font-medium flex items-center gap-1">
                                            {{ $setting['label'] }}
                                            @if ($setting['has_warning'])
                                                <i class="fa-solid fa-triangle-exclamation text-warning text-xs"
                                                    aria-label="Réglage sensible"></i>
                                            @endif
                                        </div>
                                        @if ($setting['description'] !== '')
                                            <div class="text-sm opacity-70">{{ $setting['description'] }}</div>
                                        @endif
                                        <div class="text-xs opacity-50 font-mono">{{ $setting['path'] }}\{{ $setting['name'] }}</div>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm @class([
                                            'badge-warning' => $setting['hive'] === 'HKLM',
                                            'badge-ghost' => $setting['hive'] === 'HKCU',
                                        ])">{{ $setting['hive'] }}</span>
                                    </td>
                                    <td class="font-medium">{{ $setting['default_display'] }}</td>
                                    <td>
                                        <label class="flex items-center gap-2 cursor-pointer"
                                            title="Gelé = plus de nouveaux overrides par parc (la diffusion reste inchangée).">
                                            <input type="checkbox" class="toggle toggle-warning toggle-sm"
                                                @checked($setting['overrides_locked'])
                                                wire:click="toggleLock({{ $setting['id'] }})"
                                                data-testid="toggle-lock-{{ $setting['id'] }}" />
                                            <span class="badge badge-sm {{ $setting['overrides_locked'] ? 'badge-warning' : 'badge-ghost' }}">
                                                {{ $setting['overrides_locked'] ? 'Gelé' : 'Ouvert' }}
                                            </span>
                                        </label>
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <button type="button" class="btn btn-ghost btn-xs"
                                            wire:click="openEdit({{ $setting['id'] }})"
                                            data-testid="edit-default-{{ $setting['id'] }}">
                                            <i class="fa-solid fa-pen"></i> Éditer le défaut
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center opacity-60 py-6">
                                        Aucun réglage dans le catalogue.
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
            title="{{ $this->editingSetting?->label ?? 'Valeur par défaut' }}"
            icon="fa-pen-to-square text-primary"
            size="max-w-2xl" height="h-auto max-h-[85vh]"
            closeMethod="closeModal">

            @if ($this->editingSetting !== null)
                @php($setting = $this->editingSetting)
                <x-molecules.modal.section title="Valeur par défaut diffusée">
                    @if ($setting->description)
                        <p class="text-sm opacity-70 mb-2">{{ $setting->description }}</p>
                    @endif
                    <div class="text-xs opacity-50 font-mono mb-3">
                        {{ $setting->hive }}\{{ $setting->path }}\{{ $setting->name }} ({{ $setting->type }})
                    </div>
                    <p class="text-xs opacity-70 mb-3">
                        Modifier ce défaut impacte <strong>tous les parcs sans override</strong> sur cette clé.
                    </p>

                    @php($type = strtoupper((string) $setting->type))

                    <label class="form-control w-full">
                        <span class="label-text mb-1">Valeur par défaut</span>

                        @if ($setting->hasOptions())
                            <select class="select select-bordered w-full" wire:model="formValue"
                                data-testid="default-select">
                                <option value="" disabled>— Choisir —</option>
                                @foreach ($setting->options as $opt)
                                    <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                @endforeach
                            </select>
                        @elseif ($type === 'REG_MULTI_SZ')
                            <div class="flex flex-col gap-2">
                                @foreach ($formMultiLines as $i => $line)
                                    <div class="flex items-center gap-2">
                                        <input type="text" class="input input-bordered w-full"
                                            wire:model="formMultiLines.{{ $i }}"
                                            data-testid="default-multi-{{ $i }}" />
                                        <button type="button" class="btn btn-ghost btn-sm"
                                            wire:click="removeMultiLine({{ $i }})" aria-label="Retirer">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                @endforeach
                                <button type="button" class="btn btn-ghost btn-sm self-start" wire:click="addMultiLine">
                                    <i class="fa-solid fa-plus"></i> Ajouter une ligne
                                </button>
                            </div>
                        @elseif ($type === 'REG_DWORD' || $type === 'REG_QWORD')
                            <input type="number" min="0" step="1" class="input input-bordered w-full"
                                wire:model="formValue" data-testid="default-number" />
                        @else
                            <input type="text" class="input input-bordered w-full"
                                wire:model="formValue" data-testid="default-text" />
                        @endif

                        @error('formValue')
                            <span class="text-error text-sm mt-1">{{ $message }}</span>
                        @enderror
                    </label>
                </x-molecules.modal.section>

                @if ($setting->hasWarning())
                    <x-molecules.modal.section>
                        <div class="alert alert-warning">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <div class="text-sm">{{ $setting->warning }}</div>
                        </div>
                        <label class="label cursor-pointer justify-start gap-2 mt-2">
                            <input type="checkbox" class="checkbox checkbox-warning checkbox-sm"
                                wire:model="warningAcknowledged" data-testid="ack-warning" />
                            <span class="label-text">J'ai lu et compris les implications de ce réglage.</span>
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
