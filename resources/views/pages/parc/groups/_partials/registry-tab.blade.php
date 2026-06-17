<?php

use App\Components\Traits\WithToasts;
use App\Models\RegistrySetting;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 27.3ter — Onglet « Registre » de la page d'un WorkstationGroup.
 *
 * MODÈLE 27.3ter (évolution de 27.3) : chaque réglage du catalogue porte une
 * VALEUR PAR DÉFAUT diffusée à TOUS les postes (Broadcast). Cet onglet édite les
 * **OVERRIDES de valeur par parc** : « ce parc DÉVIE ce réglage vers CETTE
 * valeur ». Il ne liste donc QUE les overrides (lignes de pivot avec une valeur),
 * + « ajouter / éditer / retirer ».
 *
 * ⚠️ « Retirer » = supprimer l'override = REVENIR À LA VALEUR PAR DÉFAUT (l'agent
 * réapplique le défaut Broadcast au cycle suivant — re-convergence), PAS « cesser
 * de gérer » (sémantique 27.3 ABANDONNÉE, D3). Les réglages non listés appliquent
 * leur valeur par défaut.
 *
 * Saisie ADAPTÉE AU TYPE (sélecteur/toggle si `options`, nombre pour DWORD/QWORD,
 * texte pour SZ/EXPAND_SZ, liste pour MULTI_SZ) + VALIDATION SERVEUR de la valeur
 * (type + options). Si le réglage porte un `warning` (D7), un encart de
 * confirmation explicite est exigé avant persistance.
 *
 * Gate `app.customize` (iso autres réglages parc). Persistance sur le pivot
 * `registry_setting_assignables` (assignable = WorkstationGroup), colonne `value`.
 */
new class extends Component {
    use WithToasts;

    /** WorkstationGroup (parc/salle) édité — passé par la page parente. */
    public int $groupId;

    /** Modale ajouter/éditer un override. */
    public bool $showOverrideModal = false;

    /** Réglage en cours d'ajout/édition (id de catalogue) ; null = fermé. */
    public ?int $editingSettingId = null;

    /** Édite-t-on un override EXISTANT (true) ou en ajoute-t-on un (false) ? */
    public bool $isEditing = false;

    /** Valeur saisie (toujours en string côté formulaire, sérialisée à la persistance). */
    public string $formValue = '';

    /** Lignes saisies pour un MULTI_SZ (une chaîne par entrée). */
    public array $formMultiLines = [''];

    /** Confirmation explicite quand le réglage porte un `warning` (D7). */
    public bool $warningAcknowledged = false;

    public function mount(int $groupId): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('app.customize'),
            403,
            'Permission app.customize requise.',
        );

        $this->groupId = $groupId;
    }

    /**
     * Overrides du parc courant : lignes de pivot (assignable = ce WG) PORTANT
     * une valeur. Libellé + valeur d'override formatée (lisible selon le type /
     * `options`) + défaut catalogue (pour rappel). N'affiche QUE les overrides.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function overrides(): array
    {
        $rows = DB::table('registry_setting_assignables as a')
            ->join('registry_settings as s', 's.id', '=', 'a.registry_setting_id')
            ->where('a.assignable_type', WorkstationGroup::class)
            ->where('a.assignable_id', $this->groupId)
            ->whereNotNull('a.value')
            ->orderBy('s.label')
            ->get(['a.registry_setting_id as setting_id', 'a.value as override_value']);

        if ($rows->isEmpty()) {
            return [];
        }

        // Une seule requête pour TOUS les réglages overridés (évite le N+1).
        $settings = RegistrySetting::query()
            ->whereIn('id', $rows->pluck('setting_id')->all())
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($settings): array {
            $setting = $settings->get($row->setting_id);
            if ($setting === null) {
                return [];
            }

            return [
                'id' => (int) $setting->id,
                'label' => (string) $setting->label,
                'description' => (string) ($setting->description ?? ''),
                'hive' => (string) $setting->hive,
                'path' => (string) $setting->path,
                'name' => (string) $setting->name,
                'type' => (string) $setting->type,
                'override_raw' => (string) $row->override_value,
                'override_display' => $this->displayValue($setting, (string) $row->override_value),
                'default_display' => $this->displayValue($setting, (string) $setting->value),
                'has_warning' => $setting->hasWarning(),
            ];
        })->filter()->values()->all();
    }

    /**
     * Catalogue des réglages SANS override pour ce parc (proposés à l'ajout), avec
     * leur valeur par défaut affichée.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function addableSettings(): array
    {
        $overriddenIds = DB::table('registry_setting_assignables')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->groupId)
            ->whereNotNull('value')
            ->pluck('registry_setting_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return RegistrySetting::query()
            ->where('is_active', true)
            // Gelé (27.3ter) = plus de NOUVEAUX overrides : retiré de l'ajout (les
            // overrides EXISTANTS restent listés/éditables via overrides()).
            ->where('overrides_locked', false)
            ->when($overriddenIds !== [], fn ($q) => $q->whereNotIn('id', $overriddenIds))
            ->orderBy('label')
            ->get()
            ->map(fn (RegistrySetting $s): array => [
                'id' => (int) $s->id,
                'label' => (string) $s->label,
                'description' => (string) ($s->description ?? ''),
                'hive' => (string) $s->hive,
                'type' => (string) $s->type,
                'default_display' => $this->displayValue($s, (string) $s->value),
            ])
            ->all();
    }

    /** Réglage en cours d'édition dans la modale (ou null). */
    #[Computed]
    public function editingSetting(): ?RegistrySetting
    {
        return $this->editingSettingId !== null
            ? RegistrySetting::query()->find($this->editingSettingId)
            : null;
    }

    /**
     * Ouvre la modale en mode AJOUT d'un override sur le réglage choisi : pré-
     * remplit le formulaire avec la valeur par défaut du catalogue.
     */
    public function openAdd(int $settingId): void
    {
        $this->guardCustomize();

        // Ajout interdit sur un réglage inactif OU gelé (défense : l'UI ne les
        // propose déjà pas via addableSettings).
        $setting = RegistrySetting::query()
            ->where('is_active', true)
            ->where('overrides_locked', false)
            ->findOrFail($settingId);
        $this->resetForm();
        $this->editingSettingId = (int) $setting->id;
        $this->isEditing = false;
        $this->seedFormFromValue($setting, (string) $setting->value);
        $this->showOverrideModal = true;
    }

    /**
     * Ouvre la modale en mode ÉDITION d'un override existant : pré-remplit avec la
     * valeur d'override actuelle.
     */
    public function openEdit(int $settingId): void
    {
        $this->guardCustomize();

        $setting = RegistrySetting::query()->findOrFail($settingId);
        $current = DB::table('registry_setting_assignables')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->groupId)
            ->where('registry_setting_id', $settingId)
            ->value('value');

        $this->resetForm();
        $this->editingSettingId = (int) $setting->id;
        $this->isEditing = true;
        $this->seedFormFromValue($setting, (string) ($current ?? $setting->value));
        $this->showOverrideModal = true;
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->showOverrideModal = false;
    }

    /**
     * Persiste l'override : valide la valeur saisie contre le `type` (et `options`
     * si présent), exige la confirmation du `warning` (D7), puis écrit la colonne
     * `value` du pivot (upsert).
     */
    public function saveOverride(): void
    {
        $this->guardCustomize();

        $setting = $this->editingSetting;
        if ($setting === null) {
            $this->toastError('Réglage introuvable.');
            return;
        }

        // Confirmation explicite si le réglage porte un warning (D7).
        if ($setting->hasWarning() && ! $this->warningAcknowledged) {
            $this->addError('warningAcknowledged', 'Vous devez confirmer avoir lu les implications de ce réglage.');
            return;
        }

        $serialized = $this->validatedSerializedValue($setting);

        $parc = WorkstationGroup::query()->findOrFail($this->groupId);

        DB::table('registry_setting_assignables')->updateOrInsert(
            [
                'registry_setting_id' => $setting->id,
                'assignable_type' => WorkstationGroup::class,
                'assignable_id' => $parc->id,
            ],
            [
                'value' => $serialized,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $this->toastSuccess($this->isEditing
            ? 'Override mis à jour pour ce parc.'
            : 'Override ajouté pour ce parc.');

        $this->closeModal();
        unset($this->overrides, $this->addableSettings);
    }

    /**
     * Retire l'override = supprime la ligne de pivot = REVENIR AU DÉFAUT. L'agent
     * réapplique la valeur par défaut (Broadcast) au cycle suivant (D3).
     */
    public function removeOverride(int $settingId): void
    {
        $this->guardCustomize();

        DB::table('registry_setting_assignables')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->groupId)
            ->where('registry_setting_id', $settingId)
            ->delete();

        $this->toastSuccess('Override retiré — le parc revient à la valeur par défaut (l\'agent la réapplique au cycle suivant).');
        unset($this->overrides, $this->addableSettings);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Valide la valeur saisie contre le type (et options) du réglage, puis la
     * sérialise dans la convention catalogue (DWORD/QWORD décimal, MULTI_SZ JSON
     * array, SZ/EXPAND_SZ littéral). Lève une ValidationException propre en cas
     * d'incohérence (jamais d'exception au render).
     */
    private function validatedSerializedValue(RegistrySetting $setting): string
    {
        $type = strtoupper((string) $setting->type);

        // MULTI_SZ : liste de chaînes → JSON array (lignes vides retirées).
        if ($type === 'REG_MULTI_SZ') {
            $lines = array_values(array_filter(
                array_map(static fn ($l): string => (string) $l, $this->formMultiLines),
                static fn (string $l): bool => $l !== '',
            ));

            return json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = trim($this->formValue);

        // Choix fermé : la valeur doit appartenir aux options autorisées.
        if ($setting->hasOptions()) {
            if (! in_array($value, $setting->allowedOptionValues(), true)) {
                throw ValidationException::withMessages([
                    'formValue' => 'Choisissez une valeur parmi les options proposées.',
                ]);
            }

            return $value;
        }

        // DWORD/QWORD : entier décimal (borné par les bornes du type).
        if ($type === 'REG_DWORD' || $type === 'REG_QWORD') {
            if (! preg_match('/^-?\d+$/', $value)) {
                throw ValidationException::withMessages([
                    'formValue' => 'La valeur doit être un nombre entier.',
                ]);
            }

            // Borne SANS (int) : (int) CLAMPE silencieusement à PHP_INT_MAX au-delà
            // (un QWORD à 20 chiffres serait accepté tronqué). Comparaison sur la
            // chaîne de chiffres. REG_DWORD = uint32 [0, 2^32-1] ; REG_QWORD borné
            // côté serveur à [0, 2^63-1] (PHP int 64 bits signé, iso provider).
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

        // SZ / EXPAND_SZ : chaîne littérale (non vide).
        if ($value === '') {
            throw ValidationException::withMessages([
                'formValue' => 'La valeur ne peut pas être vide.',
            ]);
        }

        return $value;
    }

    /** Pré-remplit le formulaire depuis une valeur sérialisée (selon le type). */
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

    /** Valeur LISIBLE pour l'affichage (libellé d'option / liste / brute). */
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
        $this->isEditing = false;
        $this->formValue = '';
        $this->formMultiLines = [''];
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
};
?>

<div class="space-y-6 mt-4">
    <div class="alert alert-info shadow-sm">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <p class="font-medium">Overrides de réglages registre</p>
            <p class="text-sm opacity-80">
                Chaque réglage du catalogue a une <strong>valeur par défaut</strong> appliquée à tous les
                postes. Ici vous <strong>déviez</strong> certains réglages pour ce parc uniquement. Les
                réglages non listés appliquent leur valeur par défaut.
                <strong>Retirer un override = revenir à la valeur par défaut</strong> :
                l'agent réapplique le défaut au cycle suivant (pas de valeur figée).
            </p>
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body">
            <div class="flex items-center justify-between gap-3">
                <h2 class="card-title text-base">Réglages registre déviés pour ce parc</h2>
                @if (count($this->addableSettings) > 0)
                    <button type="button" class="btn btn-sm btn-primary" wire:click="$set('showOverrideModal', true)"
                        data-testid="open-add-override">
                        <i class="fa-solid fa-plus"></i> Ajouter un réglage
                    </button>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Réglage</th>
                            <th>Ruche</th>
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
                                                aria-label="Réglage sensible"></i>
                                        @endif
                                    </div>
                                    @if ($override['description'] !== '')
                                        <div class="text-sm opacity-70">{{ $override['description'] }}</div>
                                    @endif
                                    <div class="text-xs opacity-50 font-mono">{{ $override['path'] }}\{{ $override['name'] }}</div>
                                </td>
                                <td>
                                    <span class="badge badge-sm @class([
                                        'badge-warning' => $override['hive'] === 'HKLM',
                                        'badge-ghost' => $override['hive'] === 'HKCU',
                                    ])">{{ $override['hive'] }}</span>
                                </td>
                                <td class="font-medium">{{ $override['override_display'] }}</td>
                                <td class="text-xs opacity-60">{{ $override['default_display'] }}</td>
                                <td class="text-right whitespace-nowrap">
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
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center opacity-60 py-6">
                                    Aucun override pour ce parc — tous les réglages appliquent leur valeur par défaut.
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
        title="{{ $this->editingSetting ? ($isEditing ? 'Éditer l\'override' : 'Ajouter un override') : 'Ajouter un réglage' }}"
        icon="fa-pen-to-square text-primary"
        size="max-w-2xl" height="h-auto max-h-[85vh]"
        closeMethod="closeModal">

        @if ($this->editingSetting === null)
            {{-- Étape 1 : choix du réglage à dévier (catalogue restant). --}}
            <x-molecules.modal.section title="Choisir un réglage à dévier">
                @if (count($this->addableSettings) === 0)
                    <p class="opacity-60 text-sm">Tous les réglages du catalogue ont déjà un override sur ce parc.</p>
                @else
                    <div class="flex flex-col gap-2">
                        @foreach ($this->addableSettings as $setting)
                            <button type="button"
                                class="flex items-start justify-between gap-3 p-3 rounded-lg border border-base-200 hover:bg-base-200 text-left"
                                wire:click="openAdd({{ $setting['id'] }})"
                                data-testid="pick-setting-{{ $setting['id'] }}">
                                <span class="min-w-0">
                                    <span class="font-medium">{{ $setting['label'] }}</span>
                                    @if ($setting['description'] !== '')
                                        <span class="block text-sm opacity-70">{{ $setting['description'] }}</span>
                                    @endif
                                    <span class="block text-xs opacity-50">Défaut : {{ $setting['default_display'] }}</span>
                                </span>
                                <span class="badge badge-sm @class([
                                    'badge-warning' => $setting['hive'] === 'HKLM',
                                    'badge-ghost' => $setting['hive'] === 'HKCU',
                                ]) shrink-0">{{ $setting['hive'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </x-molecules.modal.section>
        @else
            @php($setting = $this->editingSetting)
            {{-- Étape 2 : saisie de la valeur d'override (contrôle adapté au type). --}}
            <x-molecules.modal.section title="{{ $setting->label }}">
                @if ($setting->description)
                    <p class="text-sm opacity-70 mb-2">{{ $setting->description }}</p>
                @endif
                <div class="text-xs opacity-50 font-mono mb-3">
                    {{ $setting->hive }}\{{ $setting->path }}\{{ $setting->name }} ({{ $setting->type }})
                </div>

                @php($type = strtoupper((string) $setting->type))

                <label class="form-control w-full">
                    <span class="label-text mb-1">Valeur pour ce parc</span>

                    @if ($setting->hasOptions())
                        {{-- Choix fermé : sélecteur. --}}
                        <select class="select select-bordered w-full" wire:model="formValue"
                            data-testid="override-select">
                            <option value="" disabled>— Choisir —</option>
                            @foreach ($setting->options as $opt)
                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                    @elseif ($type === 'REG_MULTI_SZ')
                        {{-- Liste de chaînes (MULTI_SZ). --}}
                        <div class="flex flex-col gap-2">
                            @foreach ($formMultiLines as $i => $line)
                                <div class="flex items-center gap-2">
                                    <input type="text" class="input input-bordered w-full"
                                        wire:model="formMultiLines.{{ $i }}"
                                        data-testid="override-multi-{{ $i }}" />
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
                        {{-- Nombre. --}}
                        <input type="number" min="0" step="1" class="input input-bordered w-full"
                            wire:model="formValue" data-testid="override-number" />
                    @else
                        {{-- SZ / EXPAND_SZ : texte. --}}
                        <input type="text" class="input input-bordered w-full"
                            wire:model="formValue" data-testid="override-text" />
                    @endif

                    @error('formValue')
                        <span class="text-error text-sm mt-1">{{ $message }}</span>
                    @enderror
                </label>
            </x-molecules.modal.section>

            {{-- Encart de warning (D7) : confirmation explicite avant persistance. --}}
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
            @if ($this->editingSetting !== null)
                <button type="button" class="btn btn-primary" wire:click="saveOverride" data-testid="save-override">
                    Enregistrer
                </button>
            @endif
        </x-slot:footer>
    </x-molecules.modal>
</div>
