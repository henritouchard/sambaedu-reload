<?php

use App\Components\Traits\WithToasts;
use App\Models\RegistrySetting;
use App\Models\WorkstationGroup;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 27.3 — Onglet « Réglages registre » de la page d'un WorkstationGroup.
 *
 * Le réglage registre s'applique PAR workstationGroup (parc logique OU salle) : le
 * geste vit donc dans la page de gestion du groupe (onglet), scopé à `$groupId`,
 * et non dans une page globale à sélecteur de parc.
 *
 * L'admin (non-expert) coche/décoche des réglages PRÉDÉTERMINÉS du catalogue
 * `registry_settings`. Persistance sur le pivot polymorphe
 * `registry_setting_assignables` (assignable = WorkstationGroup) via attach/detach.
 * « Désactiver » = retirer l'assignation = l'agent CESSE de gérer la clé (elle garde
 * sa dernière valeur ; PAS de reset OFF — piège n° 5, sémantique claire dans l'UI).
 *
 * Gate `app.customize` (iso autres réglages parc).
 */
new class extends Component {
    use WithToasts;

    /** WorkstationGroup (parc/salle) édité — passé par la page parente. */
    public int $groupId;

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
     * Catalogue des réglages actifs + drapeau « assigné au groupe courant ».
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function settings(): array
    {
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
     * Active/désactive un réglage pour le groupe courant (pivot attach/detach).
     */
    public function toggle(int $settingId): void
    {
        $setting = RegistrySetting::query()->findOrFail($settingId);
        $parc = WorkstationGroup::query()->findOrFail($this->groupId);

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
     * Ids des réglages assignés au groupe courant (une requête pivot).
     *
     * @return list<int>
     */
    private function assignedSettingIds(): array
    {
        return \Illuminate\Support\Facades\DB::table('registry_setting_assignables')
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->groupId)
            ->pluck('registry_setting_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
};
?>

<div class="space-y-6 mt-4">
    <div class="alert alert-info shadow-sm">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <p class="font-medium">Catalogue de réglages registre</p>
            <p class="text-sm opacity-80">
                Cochez les réglages à appliquer pour ce parc. Ils sont réimposés par l'agent
                (un réglage modifié manuellement sur un poste est corrigé au cycle suivant).
                <strong>Désactiver un réglage = cesser de le gérer</strong> : la valeur déjà
                appliquée reste en place (pas de retour automatique à la valeur d'origine).
            </p>
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body">
            <h2 class="card-title text-base">Réglages registre disponibles</h2>

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
                                    Aucun réglage dans le catalogue.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
