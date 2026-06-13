<?php

use App\Components\Traits\WithToasts;
use App\Enums\WorkstationEnvironment;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 26.1 — AC5 : onglet « Environnement » de parc-settings.
 *
 * L'admin déclare par parc (logique OU physique — le resolver côté serveur
 * traite les deux mailles) si les postes sont partagés / personnels / nomades.
 * L'écriture est un simple `save()` du modèle `WorkstationGroup` (cast enum
 * `environment`). Aucune nouvelle table, aucun service d'écriture dédié : la
 * donnée vit sur `workstation_groups`, lue ensuite par le
 * `WorkstationEnvironmentResolver`.
 *
 * Gate : `update-workstationGroup` (policy `WorkstationGroupPolicy::update` →
 * `computer.install`, droit ADMIN GLOBAL, sans délégation scopée) — la MÊME
 * autorisation que l'édition d'un parc dans `parc/groups/[id]`. À noter :
 * `manage-workstationGroup` (→ `computer.control` + délégation scopée) existe
 * mais n'est PAS utilisée ici (cf. review 26.1, décision Henri en attente).
 * Double protection : la route parc-settings (`can:computer.install`) ET cette
 * action adressable via /livewire/update.
 *
 * `null` (parc non déclaré) reste distinct de `shared_local` : on permet de
 * remettre « Non déclaré » (vide le `<select>`) — le défaut `shared_local` est
 * résolu côté service, jamais persisté ici (décision D2).
 */
return new class extends Component {
    use WithToasts;

    /**
     * Valeur sélectionnée par parc : `[$groupId => 'shared_local'|'personal_local'|'nomade'|'']`.
     *
     * @var array<int, string>
     */
    public array $selection = [];

    public function mount(): void
    {
        foreach ($this->groups as $group) {
            $this->selection[$group->id] = $group->environment?->value ?? '';
        }
    }

    /**
     * Parcs configurables : logiques (parcs) ET physiques (salles), actifs et
     * non archivés. Le resolver résout les deux mailles, on les expose donc
     * toutes les deux ; les parcs logiques sont listés en premier.
     */
    #[Computed]
    public function groups()
    {
        return WorkstationGroup::query()
            ->active()
            ->notArchived()
            ->orderBy('is_physical')
            ->orderBy('name')
            ->get();
    }

    /** Options du `<select>`, peuplées depuis l'enum (label lisible). */
    #[Computed]
    public function environments(): array
    {
        return WorkstationEnvironment::cases();
    }

    public function save(int $groupId): void
    {
        $group = WorkstationGroup::query()->find($groupId);

        if ($group === null) {
            $this->toastError('Parc introuvable.');

            return;
        }

        Gate::authorize('update-workstationGroup', $group);

        $value = $this->selection[$groupId] ?? '';

        // Vide = « Non déclaré » → null en base (distinct de shared_local, D2).
        // Une valeur NON vide hors liste fermée (requête Livewire forgée) est
        // une erreur : on refuse l'écriture et on le signale, plutôt que de la
        // ravaler silencieusement en null avec un faux toast de succès.
        if ($value !== '' && WorkstationEnvironment::tryFrom($value) === null) {
            $this->toastError("Valeur d'environnement invalide.");

            return;
        }

        $group->environment = $value === '' ? null : WorkstationEnvironment::from($value);
        $group->save();

        unset($this->groups);

        $label = $group->environment?->label() ?? 'Non déclaré (défaut partagé résolu côté serveur)';
        $this->toastSuccess("Environnement du parc « {$group->display_name_or_name} » : {$label}.");
    }
};
?>

<div class="flex flex-col gap-4 flex-1 min-h-0">
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <span class="text-sm">
            Déclarez la nature des postes de chaque parc. Un poste appartenant à plusieurs parcs hérite de
            l'environnement le plus « fort » (<strong>nomade &gt; personnel &gt; partagé</strong>) ; un parc
            « Non déclaré » est traité comme <strong>partagé</strong> par défaut.
        </span>
    </div>

    <div class="card bg-base-100 shadow-sm border border-base-200 flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="card-body p-0 flex flex-col flex-1 min-h-0">
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th>Parc</th>
                            <th>Type</th>
                            <th class="w-96">Environnement</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->groups as $group)
                            <tr wire:key="env-group-{{ $group->id }}">
                                <td class="font-medium">{{ $group->display_name_or_name }}</td>
                                <td>
                                    @if ($group->is_physical)
                                        <span class="badge badge-ghost badge-sm">salle physique</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">parc logique</span>
                                    @endif
                                </td>
                                <td>
                                    <select class="select select-bordered select-sm w-full"
                                        wire:model="selection.{{ $group->id }}"
                                        wire:change="save({{ $group->id }})">
                                        <option value="">— Non déclaré (partagé par défaut) —</option>
                                        @foreach ($this->environments as $env)
                                            <option value="{{ $env->value }}">{{ $env->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center py-8 text-base-content/60">
                                    <i class="fa-solid fa-laptop-house text-4xl mb-2 opacity-30"></i>
                                    <p>Aucun parc actif à configurer.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
