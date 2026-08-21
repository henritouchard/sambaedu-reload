<?php

use App\Components\Traits\WithToasts;
use App\Models\Shortcut;
use App\Services\ControlHub\UpstreamMaterializationGuard;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Onglet « Raccourcis » de /admin/settings/parc-defaults.
 *
 * Désigne les raccourcis posés sur TOUS les postes en basculant
 * `shortcuts.is_parc_default`, lu par {@see \App\Services\Agent\Providers\ShortcutsStateProvider}
 * en candidats Broadcast. Pendant exact de l'onglet « Applications ».
 *
 * Un raccourci que le contrat amont impose en `locked` s'affiche mais ne se retire
 * pas : le geste serait défait à la prochaine réception.
 */
new class extends Component {
    use WithToasts;

    /** Filtre de recherche (nom / clé). */
    public string $search = '';

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }
    }

    /**
     * Raccourcis « défaut parc » actuellement actifs (toujours affichés).
     *
     * @return \Illuminate\Support\Collection<int, Shortcut>
     */
    #[Computed]
    public function defaults()
    {
        return Shortcut::query()
            ->where('is_parc_default', true)
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'place', 'is_parc_default', 'controlhub_contract_key']);
    }

    /**
     * Résultats de recherche dans la bibliothèque (pour AJOUTER un raccourci au défaut).
     * Vide tant qu'aucune recherche n'est saisie.
     *
     * @return \Illuminate\Support\Collection<int, Shortcut>
     */
    #[Computed]
    public function searchResults()
    {
        $term = trim($this->search);
        if ($term === '') {
            return collect();
        }

        return Shortcut::query()
            ->search($term)
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'key', 'name', 'place', 'is_parc_default', 'controlhub_contract_key']);
    }

    /** Raccourcis figés par le contrat amont, indexés par id. */
    #[Computed]
    public function lockedIds(): array
    {
        $guard = app(UpstreamMaterializationGuard::class);

        return $this->defaults
            ->concat($this->searchResults)
            ->filter(fn (Shortcut $s): bool => $guard->isLocked(Shortcut::TYPE_SHORTCUTS, $s->controlhub_contract_key))
            ->pluck('id')
            ->flip()
            ->all();
    }

    public function setParcDefault(int $shortcutId, bool $value): void
    {
        Gate::authorize('server.admin');

        $shortcut = Shortcut::query()->find($shortcutId);
        if ($shortcut === null) {
            $this->toastError('Raccourci introuvable.');

            return;
        }

        if (app(UpstreamMaterializationGuard::class)->isLocked(Shortcut::TYPE_SHORTCUTS, $shortcut->controlhub_contract_key)) {
            $this->toastError("« {$shortcut->name} » est imposé par l'autorité amont : il ne peut pas être modifié ici.");

            return;
        }

        $shortcut->is_parc_default = $value;
        $shortcut->save();

        unset($this->defaults, $this->searchResults, $this->lockedIds);

        $this->toastSuccess($value
            ? "« {$shortcut->name} » est désormais posé sur tous les postes."
            : "« {$shortcut->name} » n'est plus posé par défaut.");
    }
};
?>

<div>
    <x-molecules.settings-section
        title="Raccourcis par défaut du parc"
        icon="fa-solid fa-link"
        color="primary"
        description="Raccourcis posés sur TOUS les postes, sans avoir à les assigner parc par parc. Ils s'ajoutent à ceux que chaque poste reçoit déjà via ses parcs, ses groupes ou son utilisateur.">

        <div class="w-full flex flex-col gap-6 col-span-full">

            {{-- Liste des raccourcis déjà en défaut parc --}}
            <div class="card bg-base-100 shadow-sm border border-base-300 w-full">
                <div class="card-body">
                    <h3 class="card-title text-base">Raccourcis par défaut actifs</h3>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Raccourci</th>
                                    <th>Emplacement</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->defaults as $shortcut)
                                    <tr wire:key="default-{{ $shortcut->id }}">
                                        <td class="font-medium">
                                            {{ $shortcut->name }}
                                            @if (isset($this->lockedIds[$shortcut->id]))
                                                <span class="badge badge-outline badge-sm ml-2">imposé</span>
                                            @endif
                                        </td>
                                        <td class="text-xs text-base-content/60">{{ $shortcut->getPlaceLabel() }}</td>
                                        <td class="text-right">
                                            @if (isset($this->lockedIds[$shortcut->id]))
                                                <span class="text-xs opacity-60">Défini par l'autorité amont</span>
                                            @else
                                                <button type="button" class="btn btn-ghost btn-xs text-error"
                                                    wire:click="setParcDefault({{ $shortcut->id }}, false)"
                                                    wire:confirm="Retirer « {{ $shortcut->name }} » du défaut parc ?"
                                                    data-testid="remove-default-{{ $shortcut->id }}">
                                                    <i class="fa-solid fa-xmark"></i> Retirer
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center opacity-60 py-6">
                                            Aucun raccourci par défaut. Recherchez-en un ci-dessous pour l'ajouter.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Recherche + ajout au défaut parc --}}
            <div class="card bg-base-200 w-full">
                <div class="card-body">
                    <h3 class="card-title text-base">Ajouter un raccourci au défaut parc</h3>
                    <div class="max-w-md">
                        <input type="text" wire:model.live.debounce.300ms="search"
                            placeholder="Rechercher par nom ou clé…"
                            class="input input-bordered w-full" data-testid="shortcuts-search" />
                    </div>

                    @if (trim($this->search) !== '')
                        <div class="overflow-x-auto mt-3">
                            <table class="table table-sm">
                                <tbody>
                                    @forelse ($this->searchResults as $shortcut)
                                        <tr wire:key="result-{{ $shortcut->id }}">
                                            <td class="font-medium">{{ $shortcut->name }}</td>
                                            <td class="text-xs text-base-content/60">{{ $shortcut->getPlaceLabel() }}</td>
                                            <td class="text-right">
                                                @if ($shortcut->is_parc_default)
                                                    <span class="badge badge-success badge-sm">déjà par défaut</span>
                                                @elseif (isset($this->lockedIds[$shortcut->id]))
                                                    <span class="text-xs opacity-60">Défini par l'autorité amont</span>
                                                @else
                                                    <button type="button" class="btn btn-ghost btn-xs text-success"
                                                        wire:click="setParcDefault({{ $shortcut->id }}, true)"
                                                        data-testid="add-default-{{ $shortcut->id }}">
                                                        <i class="fa-solid fa-plus"></i> Poser par défaut
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td class="text-center opacity-60 py-4">Aucun résultat.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </x-molecules.settings-section>
</div>
