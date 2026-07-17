<?php

use App\Components\Traits\WithToasts;
use App\Models\Application;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 27.17 — Onglet « Applications » de /admin/settings/parc-defaults.
 *
 * Désigne les applications appliquées PAR DÉFAUT à tous les postes (couche
 * Broadcast) en basculant `applications.is_parc_default`. Lu par
 * {@see \App\Services\Agent\Providers\ApplicationsStateProvider} qui unionne ces
 * apps à l'ensemble résolu par poste/groupe/profil, en candidats Broadcast —
 * SANS modifier la précédence ni le resolver.
 *
 * ⚠️ Hors scope (acté) : l'override des apps PAR POSTE. On ne fait que marquer
 * « cette app = défaut établissement » (équivalent du `is_default` du wallpaper).
 *
 * Décision Henri : tout en `server.admin`. Chaque action mutante re-garde
 * `Gate::authorize('server.admin')`.
 */
new class extends Component {
    use WithToasts;

    /** Filtre de recherche (nom / app_id). */
    public string $search = '';

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }
    }

    /**
     * Applications « défaut parc » actuellement actives (toujours affichées).
     *
     * @return \Illuminate\Support\Collection<int, Application>
     */
    #[Computed]
    public function defaults()
    {
        return Application::query()
            ->parcDefault()
            ->orderBy('name')
            ->get(['id', 'app_id', 'name', 'is_parc_default']);
    }

    /**
     * Résultats de recherche dans le catalogue (pour AJOUTER une app au défaut).
     * Vide tant qu'aucune recherche n'est saisie (catalogue potentiellement large).
     *
     * @return \Illuminate\Support\Collection<int, Application>
     */
    #[Computed]
    public function searchResults()
    {
        $term = trim($this->search);
        if ($term === '') {
            return collect();
        }

        // Assigner une app « par défaut parc » = assignation à une entité (le parc
        // entier) : TOUTE app du catalogue local est proposée. Le bornage au catalogue
        // amont (controlHub) ne concerne QUE l'administration des applications.
        return Application::query()
            ->search($term)
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'app_id', 'name', 'is_parc_default']);
    }

    public function setParcDefault(int $applicationId, bool $value): void
    {
        Gate::authorize('server.admin');

        $app = Application::query()->find($applicationId);
        if ($app === null) {
            $this->toastError('Application introuvable.');
            return;
        }

        $app->is_parc_default = $value;
        $app->save();

        unset($this->defaults, $this->searchResults);

        $this->toastSuccess($value
            ? "« {$app->name} » est désormais appliquée par défaut à tous les postes."
            : "« {$app->name} » n'est plus appliquée par défaut.");
    }
};
?>

<div>
    <x-molecules.settings-section
        title="Applications par défaut du parc"
        icon="fa-solid fa-cube"
        color="primary"
        description="Applications déployées par défaut à TOUS les postes (couche Broadcast). Elles s'ajoutent à ce que chaque poste reçoit déjà via ses profils/parcs. Le déploiement effectif reste assuré par WPKG.">

        <div class="w-full flex flex-col gap-6 col-span-full">

            {{-- Liste des apps déjà en défaut parc --}}
            <div class="card bg-base-100 shadow-sm border border-base-200 w-full">
                <div class="card-body">
                    <h3 class="card-title text-base">Applications par défaut actives</h3>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Application</th>
                                    <th>Identifiant application</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->defaults as $app)
                                    <tr wire:key="default-{{ $app->id }}">
                                        <td class="font-medium">{{ $app->name }}</td>
                                        <td class="font-mono text-xs text-base-content/60">{{ $app->app_id }}</td>
                                        <td class="text-right">
                                            <button type="button" class="btn btn-ghost btn-xs text-error"
                                                wire:click="setParcDefault({{ $app->id }}, false)"
                                                wire:confirm="Retirer « {{ $app->name }} » du défaut parc ?"
                                                data-testid="remove-default-{{ $app->id }}">
                                                <i class="fa-solid fa-xmark"></i> Retirer
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center opacity-60 py-6">
                                            Aucune application par défaut. Recherchez-en une ci-dessous pour l'ajouter.
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
                    <h3 class="card-title text-base">Ajouter une application au défaut parc</h3>
                    <div class="form-control max-w-md">
                        <input type="text" wire:model.live.debounce.300ms="search"
                            placeholder="Rechercher par nom ou identifiant (ex. 7za, NirCmd)…"
                            class="input input-bordered w-full" data-testid="apps-search" />
                    </div>

                    @if (trim($this->search) !== '')
                        <div class="overflow-x-auto mt-3">
                            <table class="table table-sm">
                                <tbody>
                                    @forelse ($this->searchResults as $app)
                                        <tr wire:key="result-{{ $app->id }}">
                                            <td class="font-medium">{{ $app->name }}</td>
                                            <td class="font-mono text-xs text-base-content/60">{{ $app->app_id }}</td>
                                            <td class="text-right">
                                                @if ($app->is_parc_default)
                                                    <span class="badge badge-success badge-sm">déjà par défaut</span>
                                                @else
                                                    <button type="button" class="btn btn-ghost btn-xs text-success"
                                                        wire:click="setParcDefault({{ $app->id }}, true)"
                                                        data-testid="add-default-{{ $app->id }}">
                                                        <i class="fa-solid fa-plus"></i> Appliquer par défaut
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
