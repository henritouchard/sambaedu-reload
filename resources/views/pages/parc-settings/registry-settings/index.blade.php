<?php

use App\Components\Traits\WithToasts;
use App\Models\RegistrySetting;
use App\Models\WorkstationGroup;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 27.3 — Page Livewire SFC « Réglages registre » (catalogue par parc).
 *
 * L'admin d'établissement (non-expert) ACTIVE par parc un ensemble PRÉDÉTERMINÉ
 * de réglages de registre Windows (le catalogue `registry_settings`). Il ne tape
 * jamais un chemin `HKLM\…` : il coche/décoche des réglages connus pour un parc.
 *
 * Persistance sur le pivot polymorphe `registry_setting_assignables` (assignable
 * = WorkstationGroup) via attach/detach. « Désactiver » = retirer l'assignation
 * = l'item disparaît de l'état → l'agent CESSE de gérer la clé (elle garde sa
 * dernière valeur ; PAS de reset OFF — piège n° 5, sémantique claire dans l'UI).
 *
 * Calqué sur `overlay-messages` (WithToasts, geste par parc). Gate
 * `app.customize` (iso autres réglages parc — voir routes/web.php).
 */
new #[Title('Réglages registre — par parc')] class extends Component {
    use WithToasts;

    /** Parc (WorkstationGroup) actuellement édité. */
    public ?int $parcId = null;

    /** @var array<int,array{id:int,name:string,is_physical:bool}> */
    public array $parcs = [];

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('app.customize'),
            403,
            'Permission app.customize requise.',
        );

        // Parcs (logiques ET physiques — geste UI v1 par PARC, D-ciblage).
        $this->parcs = WorkstationGroup::query()
            ->where('is_active', true)
            ->orderBy('is_physical')
            ->orderBy('name')
            ->get(['id', 'name', 'display_name', 'is_physical'])
            ->map(fn (WorkstationGroup $g): array => [
                'id' => (int) $g->id,
                'name' => (string) ($g->display_name ?? $g->name),
                'is_physical' => (bool) $g->is_physical,
            ])
            ->all();

        $this->parcId = $this->parcs[0]['id'] ?? null;
    }

    /**
     * Catalogue des réglages actifs + drapeau « assigné au parc courant ».
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function settings(): array
    {
        if ($this->parcId === null) {
            return [];
        }

        $assignedIds = $this->assignedSettingIds();

        return RegistrySetting::query()
            ->where('is_active', true)
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
                'value' => (string) $s->value,
                'assigned' => in_array((int) $s->id, $assignedIds, true),
            ])
            ->all();
    }

    /**
     * Active/désactive un réglage pour le parc courant (pivot attach/detach).
     */
    public function toggle(int $settingId): void
    {
        if ($this->parcId === null) {
            return;
        }

        $setting = RegistrySetting::query()->findOrFail($settingId);
        $parc = WorkstationGroup::query()->findOrFail($this->parcId);

        if (in_array($settingId, $this->assignedSettingIds(), true)) {
            // Désassigner = cesser de gérer (l'item disparaît, la clé garde sa
            // valeur — PAS de reset OFF, piège n° 5).
            $setting->workstationGroups()->detach($parc->id);
            $this->toastSuccess('Réglage retiré du parc (la valeur déjà appliquée reste en place).');
        } else {
            // syncWithoutDetaching : idempotent, ne touche pas les autres
            // assignations de ce réglage.
            $setting->workstationGroups()->syncWithoutDetaching([$parc->id]);
            $this->toastSuccess('Réglage activé pour le parc.');
        }

        unset($this->settings);
    }

    /**
     * Ids des réglages assignés au parc courant (une requête pivot).
     *
     * @return list<int>
     */
    private function assignedSettingIds(): array
    {
        if ($this->parcId === null) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('registry_setting_assignables')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->parcId)
            ->pluck('registry_setting_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
};
?>

<x-organisms.page
    title="Réglages registre"
    :scrollable="true"
    description="Activez par parc un ensemble prédéterminé de réglages de registre Windows, appliqués et maintenus par l'agent.">

    <div class="space-y-6">
        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">Catalogue de réglages</p>
                <p class="text-sm opacity-80">
                    Cochez les réglages à appliquer pour ce parc. Ils sont réimposés par l'agent
                    (un réglage modifié manuellement sur un poste est corrigé au cycle suivant).
                    <strong>Désactiver un réglage = cesser de le gérer</strong> : la valeur déjà
                    appliquée reste en place (pas de retour automatique à la valeur d'origine).
                </p>
            </div>
        </div>

        {{-- Sélecteur de parc --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <div class="form-control max-w-md">
                    <label class="label"><span class="label-text">Parc</span></label>
                    <select wire:model.live="parcId" class="select select-bordered">
                        @forelse ($parcs as $parc)
                            <option value="{{ $parc['id'] }}">
                                {{ $parc['name'] }} ({{ $parc['is_physical'] ? 'salle' : 'parc logique' }})
                            </option>
                        @empty
                            <option value="">Aucun parc</option>
                        @endforelse
                    </select>
                </div>
            </div>
        </div>

        {{-- Catalogue --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <h2 class="card-title text-base">Réglages disponibles</h2>

                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Activé</th>
                                <th>Réglage</th>
                                <th>Ruche</th>
                                <th>Clé</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->settings as $setting)
                                <tr>
                                    <td>
                                        <input type="checkbox" class="toggle toggle-primary toggle-sm"
                                            @checked($setting['assigned'])
                                            wire:click="toggle({{ $setting['id'] }})" />
                                    </td>
                                    <td>
                                        <div class="font-medium">{{ $setting['label'] }}</div>
                                        @if ($setting['description'] !== '')
                                            <div class="text-sm opacity-70">{{ $setting['description'] }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge badge-sm @class([
                                            'badge-warning' => $setting['hive'] === 'HKLM',
                                            'badge-ghost' => $setting['hive'] === 'HKCU',
                                        ])">{{ $setting['hive'] }}</span>
                                    </td>
                                    <td class="text-xs opacity-70 font-mono">
                                        {{ $setting['path'] }}\{{ $setting['name'] }}
                                        = {{ $setting['value'] }} ({{ $setting['type'] }})
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center opacity-60 py-6">
                                        Aucun réglage dans le catalogue (ou aucun parc sélectionné).
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-organisms.page>
