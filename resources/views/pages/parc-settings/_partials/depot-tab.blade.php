<?php

use App\Components\Traits\WithToasts;
use App\Jobs\InstallApplicationJob;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\InstallationLog;
use App\Services\AppStore\AppStoreService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

return new class extends Component {
    use WithPagination;
    use WithToasts;

    private AppStoreService $appStoreService;

    #[Url]
    public int $depotId = 0;

    #[Url]
    public string $depotSearch = '';

    #[Url]
    public string $depotCategoryFilter = '';

    #[Url]
    public string $depotBranchFilter = '';

    #[Url]
    public int $depotPerPage = 20;

    public array $allowedPerPage = [10, 20, 50, 100];

    public array $selectedDepotInstallApps = [];

    public bool $isDepotSyncing = false;

    public ?string $depotSyncMessage = null;

    // Modal création dépôt
    public bool $showCreateDepotModal = false;

    // Modal suppression dépôt
    public bool $showDeleteDepotModal = false;

    public ?int $deleteDepotId = null;

    public string $deleteDepotName = '';

    public string $newDepotName = '';

    public string $newDepotUrl = '';

    public bool $newDepotIsPrimary = false;

    public function boot(AppStoreService $appStoreService): void
    {
        $this->appStoreService = $appStoreService;
    }

    public function mount(): void
    {
        if ($this->depotId === 0) {
            $defaultDepot = $this->appStoreService->getDefaultDepot();
            if ($defaultDepot) {
                $this->depotId = $defaultDepot->id;
            }
        }
    }

    #[Computed]
    public function depots()
    {
        return $this->appStoreService->listDepots();
    }

    #[Computed]
    public function depotApplications()
    {
        if ($this->depotId === 0) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->depotPerPage);
        }

        return $this->appStoreService->listDepotApplications(depotId: $this->depotId, perPage: $this->depotPerPage, search: $this->depotSearch ?: null, category: $this->depotCategoryFilter ?: null, branch: $this->depotBranchFilter ?: null);
    }

    #[Computed]
    public function depotCategories(): array
    {
        if ($this->depotId === 0) {
            return [];
        }

        return $this->appStoreService->getDepotCategories($this->depotId);
    }

    #[Computed]
    public function depotBranches(): array
    {
        if ($this->depotId === 0) {
            return [];
        }

        return $this->appStoreService->getDepotBranches($this->depotId);
    }

    #[Computed]
    public function depotStats(): array
    {
        if ($this->depotId === 0) {
            return ['total' => 0, 'installed' => 0, 'updatable' => 0];
        }

        return $this->appStoreService->getDepotStats($this->depotId);
    }

    public function updatedDepotId(): void
    {
        $this->depotSearch = '';
        $this->depotCategoryFilter = '';
        $this->depotBranchFilter = '';
        $this->selectedDepotInstallApps = [];
        $this->resetPage();
    }

    public function updatedDepotSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDepotCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDepotBranchFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDepotPerPage(): void
    {
        $this->resetPage();
    }

    public function resetDepotFilters(): void
    {
        $this->depotSearch = '';
        $this->depotCategoryFilter = '';
        $this->depotBranchFilter = '';
        $this->selectedDepotInstallApps = [];
        $this->resetPage();
    }

    #[On('reset-pagination')]
    public function onResetPagination(): void
    {
        $this->resetPage();
    }

    #[On('sync-current-depot')]
    public function syncCurrentDepot(): void
    {
        $this->isDepotSyncing = true;

        try {
            $depot = \App\Models\Depot::find($this->depotId);
            if (!$depot) {
                $this->toastError('Dépôt non trouvé');

                return;
            }

            $result = $this->appStoreService->syncDepot($depot);
            $message = "Synchronisation terminée : {$result['new']} nouvelles, {$result['updated']} mises à jour";
            $this->depotSyncMessage = $message;
            $this->toastSuccess($message);
        } catch (\Exception $e) {
            Log::error('[DepotTab] Erreur sync dépôt: ' . $e->getMessage());
            $this->toastError('Erreur lors de la synchronisation: ' . $e->getMessage());
        } finally {
            $this->isDepotSyncing = false;
        }
    }

    /**
     * Story 8.2.7 — Dispatch NON-BLOQUANT : pour chaque app sélectionnée on
     * dispatche un {@see InstallApplicationJob} sur la file `default`. La
     * méthode rend la main immédiatement — aucun téléchargement synchrone ne
     * gèle la requête. Le worker exécute le flow `installApplication()`
     * existant (option A « tout dans le Job ») ; le panneau de progression
     * (AC6) rattrape la latence d'apparition du log via `wire:poll`.
     */
    public function installFromDepot(): void
    {
        if (empty($this->selectedDepotInstallApps)) {
            $this->toastWarning('Aucune application sélectionnée');

            return;
        }

        // Convention projet : identité = User.login. Fallback 'system' si
        // non authentifié (cohérent avec le défaut de installApplication()).
        $initiatedBy = auth()->user()?->login ?? 'system';

        $count = 0;
        foreach ($this->selectedDepotInstallApps as $depotAppId) {
            // On ne sérialise pas le modèle : le Job re-find() par id.
            $depotApp = DepotApplication::find($depotAppId);
            if ($depotApp === null) {
                continue;
            }

            InstallApplicationJob::dispatch($depotApp->id, $initiatedBy);
            $count++;
        }

        $this->selectedDepotInstallApps = [];

        if ($count > 0) {
            $this->toastSuccess("{$count} installation(s) lancée(s) en arrière-plan");
        } else {
            $this->toastWarning('Aucune application valide à installer');
        }
    }

    /**
     * Story 8.2.7 (AC6) — Installations actives de l'utilisateur courant.
     *
     * Lit les `InstallationLog` non-terminaux (scopeInProgress) initiés par
     * le login courant, avec la relation `application` chargée. Pilote le
     * panneau de progression et son `wire:poll` conditionnel.
     *
     * @return \Illuminate\Support\Collection<int, InstallationLog>
     */
    #[Computed]
    public function activeInstallations()
    {
        $login = auth()->user()?->login ?? 'system';

        return InstallationLog::query()
            ->inProgress()
            ->where('initiated_by', $login)
            ->with('application')
            ->latest('id')
            ->get();
    }

    public function toggleDepotInstallAppSelection(int $appId): void
    {
        if (in_array($appId, $this->selectedDepotInstallApps)) {
            $this->selectedDepotInstallApps = array_values(array_diff($this->selectedDepotInstallApps, [$appId]));
        } else {
            $this->selectedDepotInstallApps[] = $appId;
        }
    }

    #[On('open-create-depot-modal')]
    public function openCreateDepotModal(): void
    {
        $this->newDepotName = '';
        $this->newDepotUrl = '';
        $this->newDepotIsPrimary = false;
        $this->showCreateDepotModal = true;
    }

    public function closeCreateDepotModal(): void
    {
        $this->showCreateDepotModal = false;
    }

    #[On('open-delete-depot-modal')]
    public function openDeleteDepotModal(): void
    {
        $depot = Depot::find($this->depotId);
        if (!$depot) {
            $this->toastError('Aucun dépôt sélectionné');
            return;
        }

        $this->deleteDepotId = $depot->id;
        $this->deleteDepotName = $depot->name;
        $this->showDeleteDepotModal = true;
    }

    public function closeDeleteDepotModal(): void
    {
        $this->showDeleteDepotModal = false;
        $this->deleteDepotId = null;
        $this->deleteDepotName = '';
    }

    public function deleteDepot(): void
    {
        try {
            $depot = Depot::find($this->deleteDepotId);
            if (!$depot) {
                $this->toastError('Dépôt non trouvé');
                return;
            }

            $name = $depot->name;
            $depot->update(['is_active' => false, 'is_primary' => false]);

            $this->closeDeleteDepotModal();

            // Basculer sur un autre dépôt actif
            $defaultDepot = $this->appStoreService->getDefaultDepot();
            $this->depotId = $defaultDepot?->id ?? 0;

            $this->toastSuccess("Dépôt '{$name}' désactivé");
        } catch (\Exception $e) {
            Log::error('[DepotTab] Erreur désactivation dépôt: ' . $e->getMessage());
            $this->toastError('Erreur lors de la désactivation du dépôt');
        }
    }

    public function createDepot(): void
    {
        $this->validate([
            'newDepotName' => 'required|string|max:255|unique:depots,name',
            'newDepotUrl' => 'required|url|max:512',
            'newDepotIsPrimary' => 'boolean',
        ]);

        try {
            // Si marqué comme principal, retirer le flag des autres
            if ($this->newDepotIsPrimary) {
                Depot::where('is_primary', true)->update(['is_primary' => false]);
            }

            $depot = Depot::create([
                'name' => $this->newDepotName,
                'url' => $this->newDepotUrl,
                'is_primary' => $this->newDepotIsPrimary,
                'is_active' => true,
            ]);

            $this->depotId = $depot->id;
            $this->closeCreateDepotModal();

            // Synchroniser immédiatement pour remplir xml_hash et les applications
            $result = $this->appStoreService->syncDepot($depot);
            $this->toastSuccess("Dépôt '{$depot->name}' créé — {$result['new']} application(s) importée(s)");
        } catch (\Exception $e) {
            Log::error('[DepotTab] Erreur création dépôt: ' . $e->getMessage());
            $this->toastError('Erreur lors de la création du dépôt: ' . $e->getMessage());
        }
    }
};
?>

<div class="flex flex-col gap-4 flex-1 min-h-0">
    <!-- Stats du dépôt -->
    @php $dStats = $this->depotStats; @endphp
    <div class="flex-shrink-0 flex gap-3">
        <div class="stat bg-base-100 shadow-sm border border-base-200 rounded-lg p-3 min-w-[140px]">
            <div class="stat-title text-xs">Total</div>
            <div class="stat-value text-lg">{{ $dStats['total'] }}</div>
        </div>
        <div class="stat bg-base-100 shadow-sm border border-base-200 rounded-lg p-3 min-w-[140px]">
            <div class="stat-title text-xs">Installées</div>
            <div class="stat-value text-lg text-success">{{ $dStats['installed'] }}</div>
        </div>
        <div class="stat bg-base-100 shadow-sm border border-base-200 rounded-lg p-3 min-w-[140px]">
            <div class="stat-title text-xs">Mises à jour</div>
            <div class="stat-value text-lg text-warning">{{ $dStats['updatable'] }}</div>
        </div>
    </div>

    {{-- ============================================================
         Story 8.2.7 (AC6) — Panneau de progression des installations
         en arrière-plan. wire:poll.3s CONDITIONNEL : actif uniquement
         s'il reste au moins une installation non-terminale (modèle
         iso-windows). Quand tout est terminal, le bloc disparaît et le
         polling s'arrête.
         ============================================================ --}}
    @php $activeInstalls = $this->activeInstallations; @endphp
    @if ($activeInstalls->isNotEmpty())
        <div class="flex-shrink-0 card bg-base-100 shadow-sm border border-info" wire:poll.3s>
            <div class="card-body p-4 space-y-3">
                <h2 class="card-title text-base">
                    <span class="loading loading-spinner loading-sm text-info"></span>
                    Installations en cours ({{ $activeInstalls->count() }})
                </h2>

                <div class="space-y-2">
                    @foreach ($activeInstalls as $install)
                        <div wire:key="active-install-{{ $install->id }}"
                            class="border border-base-200 rounded-lg p-3">
                            <div class="flex items-center justify-between gap-3 mb-1">
                                <span class="font-medium text-sm">
                                    {{ $install->application?->name ?? $install->application?->app_id ?? 'Application' }}
                                </span>
                                <span class="badge badge-{{ $install->status->color() }} badge-sm">
                                    {{ $install->status->label() }}
                                </span>
                            </div>
                            <progress class="progress progress-info w-full" value="{{ $install->progress }}"
                                max="100"></progress>
                            @if ($install->message)
                                <div class="text-xs text-base-content/60 mt-1">{{ $install->message }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <!-- Résumé dépôt + Sélecteur -->
    <div class="flex-shrink-0 card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4">
            <div class="flex flex-wrap gap-4 items-end">
                <!-- Sélection du dépôt -->
                <div class="form-control min-w-[250px]">
                    <label class="label py-1">
                        <span class="label-text text-xs">Dépôt</span>
                    </label>
                    <select wire:model.live="depotId" class="select select-bordered select-sm">
                        @foreach ($this->depots as $depot)
                            <option value="{{ $depot->id }}">
                                {{ $depot->name }}
                                @if ($depot->is_primary)
                                    (principal)
                                @endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Recherche -->
                <div class="form-control flex-1 min-w-[200px]">
                    <label class="label py-1">
                        <span class="label-text text-xs">Rechercher</span>
                    </label>
                    <input type="text" wire:model.live.debounce.300ms="depotSearch"
                        class="input input-bordered input-sm" placeholder="Nom, identifiant..." />
                </div>

                <!-- Filtre catégorie -->
                <div class="form-control min-w-[180px]">
                    <label class="label py-1">
                        <span class="label-text text-xs">Catégorie</span>
                    </label>
                    <select wire:model.live="depotCategoryFilter" class="select select-bordered select-sm">
                        <option value="">Toutes les catégories</option>
                        @foreach ($this->depotCategories as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Filtre branche -->
                <div class="form-control min-w-[150px]">
                    <label class="label py-1">
                        <span class="label-text text-xs">Branche</span>
                    </label>
                    <select wire:model.live="depotBranchFilter" class="select select-bordered select-sm">
                        <option value="">Toutes</option>
                        @foreach ($this->depotBranches as $branch)
                            <option value="{{ $branch }}">{{ ucfirst($branch) }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Bouton reset -->
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resetDepotFilters"
                    title="Réinitialiser les filtres">
                    <i class="fa-solid fa-rotate-left"></i>
                </button>

            </div>

            <div wire:loading wire:target="syncCurrentDepot" class="alert alert-info alert-sm mt-3">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <span>Synchronisation en cours...</span>
            </div>

            @if ($depotSyncMessage)
                <div wire:loading.remove wire:target="syncCurrentDepot" class="alert alert-info alert-sm mt-3">
                    <i class="fa-solid fa-info-circle"></i>
                    <span>{{ $depotSyncMessage }}</span>
                </div>
            @endif
        </div>
    </div>

    <!-- Tableau des applications du dépôt -->
    <div class="card bg-base-100 shadow-sm border border-base-200 flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="card-body p-0 flex flex-col flex-1 min-h-0">
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <x-molecules.select-all-checkbox class="checkbox-sm"
                                    :ids="$this->depotApplications->getCollection()->filter(fn($app) => !$app->is_installed)->pluck('id')"
                                    model="selectedDepotInstallApps" />
                            </th>
                            <th>Application</th>
                            <th>Version</th>
                            <th>Catégorie</th>
                            <th>Branche</th>
                            <th class="text-center">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->depotApplications as $app)
                            <tr wire:key="depot-tab-app-{{ $app->id }}"
                                @class(['hover', 'cursor-pointer' => !$app->is_installed])
                                @if (!$app->is_installed) wire:click="toggleDepotInstallAppSelection({{ $app->id }})" @endif>
                                <td>
                                    @if (!$app->is_installed)
                                        {{-- Affichage piloté par la propriété DOM (x-effect) : la ligne
                                             entière toggle côté serveur via wire:click sur le <tr>. --}}
                                        <input type="checkbox" class="checkbox checkbox-sm"
                                            x-effect="$el.checked = ($wire.selectedDepotInstallApps ?? []).map(String).includes('{{ $app->id }}')" />
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="text-primary ">
                                            <i class="fa-solid fa-cubes-stacked text-xl"></i>
                                        </div>
                                        <div>
                                            <div class="font-medium">{{ $app->name }}</div>
                                            <div class="text-xs text-base-content/60">
                                                <code class="bg-base-200 px-1 rounded">{{ $app->app_id }}</code>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-ghost badge-sm">{{ $app->version ?? '-' }}</span>
                                    @if ($app->is_installed && $app->has_update)
                                        <div class="text-xs text-warning mt-1">
                                            Local: {{ $app->local_version ?? '?' }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="text-sm">{{ $app->category ?? '-' }}</span>
                                </td>
                                <td>
                                    @if ($app->branch)
                                        @php
                                            $branchColor = match ($app->branch) {
                                                'stable' => 'badge-success',
                                                'testing' => 'badge-warning',
                                                'manuel' => 'badge-info',
                                                default => 'badge-ghost',
                                            };
                                        @endphp
                                        <span
                                            class="badge {{ $branchColor }} badge-sm">{{ ucfirst($app->branch) }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($app->is_installed && $app->has_update)
                                        <span class="badge badge-warning badge-sm gap-1">
                                            <i class="fa-solid fa-arrow-up text-xs"></i>
                                            Mise à jour
                                        </span>
                                    @elseif ($app->is_installed)
                                        <span class="badge badge-success badge-sm gap-1">
                                            <i class="fa-solid fa-check text-xs"></i>
                                            Installée
                                        </span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">
                                            Non installée
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-8 text-base-content/60">
                                    <i class="fa-solid fa-warehouse text-4xl mb-2 opacity-30"></i>
                                    <p>Aucune application trouvée sur ce dépôt</p>
                                    @if ($depotSearch || $depotCategoryFilter || $depotBranchFilter)
                                        <button type="button" class="btn btn-ghost btn-sm mt-2"
                                            wire:click="resetDepotFilters">
                                            Réinitialiser les filtres
                                        </button>
                                    @else
                                        <p class="text-sm mt-1">Cliquez sur "Synchroniser" pour récupérer les
                                            applications</p>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if ($this->depotApplications instanceof \Illuminate\Pagination\LengthAwarePaginator)
                <x-molecules.pagination :paginator="$this->depotApplications" :allowedPerPage="$allowedPerPage" perPageModel="depotPerPage"
                    itemLabel="application" itemLabelPlural="applications" />
            @endif
        </div>
    </div>

    <!-- Actions groupées -->
    @if (count($selectedDepotInstallApps) > 0)
        <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
            <div class="card bg-base-100 shadow-xl border border-base-300">
                <div class="card-body py-3 px-4 flex-row items-center gap-4">
                    <span class="text-sm font-medium">
                        {{ count($selectedDepotInstallApps) }} application(s) sélectionnée(s)
                    </span>
                    <div class="divider divider-horizontal m-0"></div>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="installFromDepot"
                        wire:loading.attr="disabled" wire:target="installFromDepot">
                        <span wire:loading.remove wire:target="installFromDepot">
                            <i class="fa-solid fa-download mr-1"></i>
                            Ajouter au catalogue
                        </span>
                        <span wire:loading wire:target="installFromDepot">
                            <i class="fa-solid fa-spinner fa-spin mr-1"></i>
                            Lancement...
                        </span>
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm"
                        wire:click="$set('selectedDepotInstallApps', [])">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Modal création dépôt -->
    @if ($showCreateDepotModal)
        <div class="modal modal-open">
            <div class="modal-box">
                <h3 class="font-bold text-lg mb-4">Nouveau Dépôt</h3>
                <form wire:submit="createDepot">
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text">Nom *</span>
                        </label>
                        <input type="text" wire:model="newDepotName" class="input input-bordered"
                            placeholder="ex: SambaÉdu Officiel" required />
                        @error('newDepotName')
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $message }}</span>
                            </label>
                        @enderror
                    </div>
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text">URL du dépôt *</span>
                        </label>
                        <input type="url" wire:model="newDepotUrl" class="input input-bordered"
                            placeholder="https://wawadeb.crdp.ac-caen.fr/packages.xml" required />
                        @error('newDepotUrl')
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $message }}</span>
                            </label>
                        @enderror
                    </div>
                    <div class="form-control mb-4">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model="newDepotIsPrimary"
                                class="checkbox checkbox-primary" />
                            <span class="label-text">Dépôt principal</span>
                        </label>
                        <p class="text-xs text-base-content/60 ml-10">
                            Le dépôt principal est sélectionné par défaut lors de la navigation.
                        </p>
                    </div>
                    <div class="modal-action">
                        <button type="button" class="btn" wire:click="closeCreateDepotModal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-check mr-2"></i>
                            Créer
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-backdrop" wire:click="closeCreateDepotModal"></div>
        </div>
    @endif

    <!-- Modal suppression dépôt -->
    @if ($showDeleteDepotModal)
        <div class="modal modal-open">
            <div class="modal-box">
                <h3 class="font-bold text-lg mb-4 text-warning">
                    <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                    Désactiver le dépôt
                </h3>
                <p class="mb-2">
                    Êtes-vous sûr de vouloir désactiver le dépôt
                    <strong>{{ $deleteDepotName }}</strong> ?
                </p>
                <p class="text-sm text-base-content/60 mb-4">
                    Le dépôt ne sera plus visible et ne sera plus synchronisé.
                    Les applications déjà installées dans le catalogue local ne seront pas affectées.
                </p>
                <div class="modal-action">
                    <button type="button" class="btn" wire:click="closeDeleteDepotModal">Annuler</button>
                    <button type="button" class="btn btn-warning" wire:click="deleteDepot">
                        <i class="fa-solid fa-eye-slash mr-2"></i>
                        Désactiver
                    </button>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="closeDeleteDepotModal"></div>
        </div>
    @endif
</div>
