<?php

use App\Components\Traits\WithToasts;
use App\Models\DepotApplication;
use App\Services\AppStore\AppStoreService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

return new class extends Component
{
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

    public bool $isInstalling = false;

    public ?string $depotSyncMessage = null;

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

        return $this->appStoreService->listDepotApplications(
            depotId: $this->depotId,
            perPage: $this->depotPerPage,
            search: $this->depotSearch ?: null,
            category: $this->depotCategoryFilter ?: null,
            branch: $this->depotBranchFilter ?: null,
        );
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

    #[On('sync-current-depot')]
    public function syncCurrentDepot(): void
    {
        $this->isDepotSyncing = true;

        try {
            $depot = \App\Models\Depot::find($this->depotId);
            if (! $depot) {
                $this->toastError('Dépôt non trouvé');

                return;
            }

            $result = $this->appStoreService->syncDepot($depot);
            $message = "Synchronisation terminée : {$result['new']} nouvelles, {$result['updated']} mises à jour";
            $this->depotSyncMessage = $message;
            $this->toastSuccess($message);
        } catch (\Exception $e) {
            Log::error('[DepotTab] Erreur sync dépôt: '.$e->getMessage());
            $this->toastError('Erreur lors de la synchronisation: '.$e->getMessage());
        } finally {
            $this->isDepotSyncing = false;
        }
    }

    public function installFromDepot(): void
    {
        if (empty($this->selectedDepotInstallApps)) {
            $this->toastWarning('Aucune application sélectionnée');

            return;
        }

        $this->isInstalling = true;
        $installed = 0;
        $errors = 0;

        try {
            foreach ($this->selectedDepotInstallApps as $depotAppId) {
                try {
                    $depotApp = DepotApplication::find($depotAppId);
                    if ($depotApp) {
                        $this->appStoreService->installApplication($depotApp);
                        $installed++;
                    }
                } catch (\Exception $e) {
                    Log::error("[DepotTab] Erreur installation app {$depotAppId}: ".$e->getMessage());
                    $errors++;
                }
            }

            if ($installed > 0) {
                $this->toastSuccess("{$installed} application(s) ajoutée(s) au catalogue");
            }
            if ($errors > 0) {
                $this->toastWarning("{$errors} erreur(s) lors de l'ajout");
            }

            $this->selectedDepotInstallApps = [];
        } catch (\Exception $e) {
            Log::error('[DepotTab] Erreur installation apps: '.$e->getMessage());
            $this->toastError("Erreur lors de l'ajout");
        } finally {
            $this->isInstalling = false;
        }
    }

    public function toggleDepotInstallAppSelection(int $appId): void
    {
        if (in_array($appId, $this->selectedDepotInstallApps)) {
            $this->selectedDepotInstallApps = array_values(array_diff($this->selectedDepotInstallApps, [$appId]));
        } else {
            $this->selectedDepotInstallApps[] = $appId;
        }
    }

    public function selectAllDepotInstallApps(): void
    {
        $this->selectedDepotInstallApps = $this->depotApplications
            ->getCollection()
            ->filter(fn ($app) => ! $app->is_installed)
            ->pluck('id')
            ->toArray();
    }

    public function deselectAllDepotInstallApps(): void
    {
        $this->selectedDepotInstallApps = [];
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
                                <input type="checkbox" class="checkbox checkbox-sm"
                                    wire:click="{{ count($selectedDepotInstallApps) > 0 ? 'deselectAllDepotInstallApps' : 'selectAllDepotInstallApps' }}"
                                    @if (count($selectedDepotInstallApps) > 0) checked @endif />
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
                            <tr wire:key="depot-tab-app-{{ $app->id }}" class="hover cursor-pointer"
                                wire:click="toggleDepotInstallAppSelection({{ $app->id }})">
                                <td>
                                    @if (! $app->is_installed)
                                        <input type="checkbox" class="checkbox checkbox-sm"
                                            @if (in_array($app->id, $selectedDepotInstallApps)) checked @endif />
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="avatar placeholder">
                                            <div class="bg-primary/10 text-primary rounded w-10 h-10">
                                                <i class="fa-solid fa-cube"></i>
                                            </div>
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
                            Installation...
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
</div>
