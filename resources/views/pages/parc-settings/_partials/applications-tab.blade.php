<?php

use App\Components\Traits\WithToasts;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\WorkstationGroup;
use App\Services\AppProfile\AppProfileService;
use App\Services\AppStore\AppStoreService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;
    use WithToasts;

    private AppProfileService $appProfileService;

    private AppStoreService $appStoreService;

    #[Url]
    public string $appSearch = '';

    #[Url]
    public string $categoryFilter = '';

    public array $selectedApps = [];

    #[Url]
    public int $appsPerPage = 20;

    public array $allowedPerPage = [10, 20, 50, 100];

    public array $categories = [];

    // Modal AppStore
    public bool $showAppStoreModal = false;

    public string $appStoreSearch = '';

    public string $appStoreCategoryFilter = '';

    public string $appStoreBranchTab = 'stable';

    public array $depotApps = [];

    public array $selectedDepotApps = [];

    public array $branchCounts = [];

    public bool $isSyncing = false;

    public bool $isInstalling = false;

    public ?string $lastSyncMessage = null;

    // Modal "Ajouter à un profil"
    public bool $showAssignProfileModal = false;

    public array $profileOptions = [];

    public array $selectedProfileIds = [];

    // Modal "Déployer sur un groupe"
    public bool $showDeployGroupModal = false;

    public array $groupOptions = [];

    public array $selectedGroupIds = [];

    public function boot(AppProfileService $appProfileService, AppStoreService $appStoreService): void
    {
        $this->appProfileService = $appProfileService;
        $this->appStoreService = $appStoreService;
    }

    public function mount(): void
    {
        try {
            $this->categories = $this->appProfileService->getCategories()->toArray();
        } catch (\Exception $e) {
            Log::error('[ApplicationsTab] Erreur chargement catégories: '.$e->getMessage());
            $this->categories = [];
        }
    }

    #[Computed]
    public function applications()
    {
        try {
            return $this->appProfileService->listApplications(
                perPage: $this->appsPerPage,
                search: $this->appSearch ?: null,
                category: $this->categoryFilter ?: null,
            );
        } catch (\Exception $e) {
            Log::error('[ApplicationsTab] Erreur chargement applications: '.$e->getMessage());

            return collect();
        }
    }

    #[Computed]
    public function allSelectedAreError(): bool
    {
        if (empty($this->selectedApps)) {
            return false;
        }

        return Application::whereIn('id', $this->selectedApps)
            ->where('status', '!=', ApplicationStatus::Error)
            ->doesntExist();
    }

    public function retryInstallation(): void
    {
        $apps = Application::whereIn('id', $this->selectedApps)
            ->where('status', ApplicationStatus::Error)
            ->get();

        if ($apps->isEmpty()) {
            $this->toastWarning('Aucune application en erreur sélectionnée');
            return;
        }

        $retried = 0;
        $errors = 0;

        foreach ($apps as $app) {
            try {
                $depotApp = DepotApplication::where('app_id', $app->app_id)->first();
                if (!$depotApp) {
                    Log::warning("[ApplicationsTab] DepotApplication introuvable pour retry: {$app->app_id}");
                    $errors++;
                    continue;
                }

                $app->delete();
                $this->appStoreService->installApplication($depotApp);
                $retried++;
            } catch (\Exception $e) {
                Log::error("[ApplicationsTab] Erreur retry {$app->app_id}: " . $e->getMessage());
                $errors++;
            }
        }

        if ($retried > 0) {
            $this->toastSuccess("{$retried} application(s) réinstallée(s)");
        }
        if ($errors > 0) {
            $this->toastWarning("{$errors} erreur(s) lors de la réinstallation");
        }

        $this->selectedApps = [];
    }

    public function deleteSelectedApps(): void
    {
        $apps = Application::whereIn('id', $this->selectedApps)->get();

        if ($apps->isEmpty()) {
            $this->toastWarning('Aucune application sélectionnée');
            return;
        }

        $deleted = 0;
        $errors = 0;

        foreach ($apps as $app) {
            try {
                $this->appStoreService->deleteApplication($app);
                $deleted++;
            } catch (\Exception $e) {
                Log::error("[ApplicationsTab] Erreur suppression {$app->app_id}: " . $e->getMessage());
                $errors++;
            }
        }

        if ($deleted > 0) {
            $this->toastSuccess("{$deleted} installation(s) supprimée(s)");
        }
        if ($errors > 0) {
            $this->toastWarning("{$errors} erreur(s) lors de la suppression");
        }

        $this->selectedApps = [];
        $this->resetPage();
    }

    // ========================================
    // Ajouter les apps sélectionnées à un profil
    // ========================================

    public function addAppsToProfile(): void
    {
        if (empty($this->selectedApps)) {
            $this->toastWarning('Aucune application sélectionnée');

            return;
        }

        $this->selectedProfileIds = [];
        $this->profileOptions = $this->appProfileService->listProfilesForSelect()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->display_name ?? $p->name,
            ])
            ->toArray();
        $this->showAssignProfileModal = true;
    }

    public function toggleProfileSelection(int $profileId): void
    {
        $this->selectedProfileIds = in_array($profileId, $this->selectedProfileIds)
            ? array_values(array_diff($this->selectedProfileIds, [$profileId]))
            : [...$this->selectedProfileIds, $profileId];
    }

    public function closeAssignProfileModal(): void
    {
        $this->showAssignProfileModal = false;
        $this->selectedProfileIds = [];
    }

    public function confirmAddToProfile(): void
    {
        if (empty($this->selectedApps)) {
            $this->toastWarning('Aucune application sélectionnée');

            return;
        }
        if (empty($this->selectedProfileIds)) {
            $this->toastWarning('Aucun profil sélectionné');

            return;
        }

        $added = 0;
        try {
            foreach ($this->selectedProfileIds as $profileId) {
                if ($this->appProfileService->addApplications((int) $profileId, $this->selectedApps)) {
                    $added++;
                }
            }
        } catch (AuthorizationException $e) {
            $this->toastAccessDenied();

            return;
        } catch (\Exception $e) {
            Log::error('[ApplicationsTab] Erreur ajout apps au profil: '.$e->getMessage());
            $this->toastError("Erreur lors de l'ajout au profil");

            return;
        }

        $count = count($this->selectedApps);
        if ($added > 0) {
            $this->toastSuccess("{$count} application(s) ajoutée(s) à {$added} profil(s)");
        }

        $this->showAssignProfileModal = false;
        $this->selectedProfileIds = [];
        $this->selectedApps = [];
    }

    // ========================================
    // Déployer les apps sélectionnées sur un groupe de postes
    // ========================================

    public function deployApps(): void
    {
        if (empty($this->selectedApps)) {
            $this->toastWarning('Aucune application sélectionnée');

            return;
        }

        $this->selectedGroupIds = [];
        $this->groupOptions = WorkstationGroup::active()
            ->orderBy('name')
            ->get(['id', 'name', 'display_name', 'is_physical'])
            ->map(fn (WorkstationGroup $g) => [
                'id' => $g->id,
                'name' => $g->display_name ?? $g->name,
                'is_physical' => $g->is_physical,
            ])
            ->toArray();
        $this->showDeployGroupModal = true;
    }

    public function toggleGroupSelection(int $groupId): void
    {
        $this->selectedGroupIds = in_array($groupId, $this->selectedGroupIds)
            ? array_values(array_diff($this->selectedGroupIds, [$groupId]))
            : [...$this->selectedGroupIds, $groupId];
    }

    public function closeDeployGroupModal(): void
    {
        $this->showDeployGroupModal = false;
        $this->selectedGroupIds = [];
    }

    public function confirmDeployToGroup(): void
    {
        if (empty($this->selectedApps)) {
            $this->toastWarning('Aucune application sélectionnée');

            return;
        }
        if (empty($this->selectedGroupIds)) {
            $this->toastWarning('Aucun groupe sélectionné');

            return;
        }

        $deployed = 0;
        try {
            foreach ($this->selectedGroupIds as $groupId) {
                $attached = $this->appProfileService->addApplicationsToWorkstationGroup((int) $groupId, $this->selectedApps);
                if ($attached !== []) {
                    $deployed++;
                }
            }
        } catch (AuthorizationException $e) {
            $this->toastAccessDenied('Vous n\'avez pas les droits pour déployer sur ce groupe.');

            return;
        } catch (\Exception $e) {
            Log::error('[ApplicationsTab] Erreur déploiement apps sur groupe: '.$e->getMessage());
            $this->toastError('Erreur lors du déploiement');

            return;
        }

        $count = count($this->selectedApps);
        if ($deployed > 0) {
            $this->toastSuccess("{$count} application(s) déployée(s) sur {$deployed} groupe(s)");
        } else {
            $this->toastInfo('Aucune nouvelle application à déployer (déjà présentes sur les groupes choisis)');
        }

        $this->showDeployGroupModal = false;
        $this->selectedGroupIds = [];
        $this->selectedApps = [];
    }

    public function resetAppFilters(): void
    {
        $this->appSearch = '';
        $this->categoryFilter = '';
        $this->selectedApps = [];
        $this->resetPage();
    }

    public function updatedAppsPerPage(): void
    {
        $this->resetPage();
    }

    #[On('reset-pagination')]
    public function onResetPagination(): void
    {
        $this->resetPage();
    }

    // ========================================
    // AppStore Modal
    // ========================================

    #[On('open-app-store-modal')]
    public function openAppStoreModal(): void
    {
        $this->showAppStoreModal = true;
        $this->appStoreSearch = '';
        $this->appStoreCategoryFilter = '';
        $this->selectedDepotApps = [];
        $this->lastSyncMessage = null;
        $this->loadDepotApps();
    }

    public function closeAppStoreModal(): void
    {
        $this->showAppStoreModal = false;
        $this->selectedDepotApps = [];
    }

    public function loadDepotApps(): void
    {
        try {
            $installedAppIds = Application::pluck('app_id')->toArray();
            $activeDepotIds = Depot::active()->pluck('id');

            $this->branchCounts = DepotApplication::query()
                ->whereIn('depot_id', $activeDepotIds)
                ->whereNotIn('app_id', $installedAppIds)
                ->selectRaw('branch, COUNT(DISTINCT app_id) as cnt')
                ->groupBy('branch')
                ->pluck('cnt', 'branch')
                ->toArray();

            $apps = DepotApplication::query()
                ->whereIn('depot_id', $activeDepotIds)
                ->whereNotIn('app_id', $installedAppIds)
                ->where('branch', $this->appStoreBranchTab)
                ->when(
                    $this->appStoreSearch,
                    fn ($q) => $q->where(function ($q2) {
                        $q2->where('name', 'ILIKE', "%{$this->appStoreSearch}%")
                            ->orWhere('app_id', 'ILIKE', "%{$this->appStoreSearch}%");
                    }),
                )
                ->when($this->appStoreCategoryFilter, fn ($q) => $q->where('category', $this->appStoreCategoryFilter))
                ->orderBy('name')
                ->limit(100)
                ->get();

            $this->depotApps = $apps
                ->map(fn ($app) => [
                    'id' => $app->id,
                    'app_id' => $app->app_id,
                    'name' => $app->name,
                    'version' => $app->version,
                    'category' => $app->category,
                    'branch' => $app->branch,
                ])
                ->toArray();
        } catch (\Exception $e) {
            Log::error('[ApplicationsTab] Erreur chargement apps dépôt: '.$e->getMessage());
            $this->depotApps = [];
            $this->branchCounts = [];
        }
    }

    public function switchBranchTab(string $branch): void
    {
        $this->appStoreBranchTab = $branch;
        $this->selectedDepotApps = [];
        $this->loadDepotApps();
    }

    public function updatedAppStoreSearch(): void
    {
        $this->loadDepotApps();
    }

    public function updatedAppStoreCategoryFilter(): void
    {
        $this->loadDepotApps();
    }

    public function syncDepots(): void
    {
        $this->isSyncing = true;

        try {
            $result = $this->appStoreService->syncAllDepots();

            $message = "Synchronisation terminée : {$result['new']} nouvelles, {$result['updated']} mises à jour";
            if (! empty($result['errors'])) {
                $message .= " ({$result['synced']} dépôts avec erreurs)";
            }

            $this->lastSyncMessage = $message;
            $this->toastSuccess($message);
            $this->loadDepotApps();
        } catch (\Exception $e) {
            Log::error('[ApplicationsTab] Erreur sync dépôts: '.$e->getMessage());
            $this->toastError('Erreur lors de la synchronisation: '.$e->getMessage());
        } finally {
            $this->isSyncing = false;
        }
    }

    public function installSelectedApps(): void
    {
        if (empty($this->selectedDepotApps)) {
            $this->toastWarning('Aucune application sélectionnée');

            return;
        }

        $this->isInstalling = true;
        $installed = 0;
        $errors = 0;

        try {
            foreach ($this->selectedDepotApps as $depotAppId) {
                try {
                    $depotApp = DepotApplication::find($depotAppId);
                    if ($depotApp) {
                        $this->appStoreService->installApplication($depotApp);
                        $installed++;
                    }
                } catch (\Exception $e) {
                    Log::error("[ApplicationsTab] Erreur installation app {$depotAppId}: ".$e->getMessage());
                    $errors++;
                }
            }

            if ($installed > 0) {
                $this->toastSuccess("{$installed} application(s) ajoutée(s) au catalogue");
            }
            if ($errors > 0) {
                $this->toastWarning("{$errors} erreur(s) lors de l'ajout");
            }

            $this->selectedDepotApps = [];
            $this->loadDepotApps();
        } catch (\Exception $e) {
            Log::error('[ApplicationsTab] Erreur installation apps: '.$e->getMessage());
            $this->toastError('Erreur lors de l\'ajout');
        } finally {
            $this->isInstalling = false;
        }
    }

    public function toggleDepotAppSelection(int $appId): void
    {
        if (in_array($appId, $this->selectedDepotApps)) {
            $this->selectedDepotApps = array_values(array_diff($this->selectedDepotApps, [$appId]));
        } else {
            $this->selectedDepotApps[] = $appId;
        }
    }
};
?>

<div class="flex flex-col gap-3 flex-1 min-h-0">
    <!-- Filtres -->
    <div class="flex-shrink-0 border-b border-base-300 pb-3">
        <div class="flex flex-wrap gap-x-3 gap-y-2 items-end">
            <!-- Recherche -->
            <div class="form-control flex-1 min-w-[200px]">
                <label class="label py-0">
                    <span class="label-text text-xs">Rechercher</span>
                </label>
                <input type="text" wire:model.live.debounce.300ms="appSearch" class="input input-bordered input-sm"
                    placeholder="Nom, identifiant..." />
            </div>

            <!-- Filtre catégorie -->
            <div class="form-control min-w-[180px]">
                <label class="label py-0">
                    <span class="label-text text-xs">Catégorie</span>
                </label>
                <select wire:model.live="categoryFilter" class="select select-bordered select-sm">
                    <option value="">Toutes les catégories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}">{{ $category }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Bouton reset -->
            <button type="button" class="btn btn-ghost btn-sm" wire:click="resetAppFilters"
                title="Réinitialiser les filtres">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
        </div>
    </div>

    <!-- Tableau des applications -->
    <div class="card bg-base-100 shadow-sm border border-base-300 flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="card-body p-0 flex flex-col flex-1 min-h-0">
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <x-molecules.select-all-checkbox class="checkbox-sm" :ids="$this->applications->pluck('id')"
                                    model="selectedApps" />
                            </th>
                            <th>Application</th>
                            <th>Identifiant</th>
                            <th>Version</th>
                            <th>Catégorie</th>
                            <th class="text-center">Compatibilité</th>
                            <th class="text-center">Dépôt</th>
                            <th class="text-center">Déploiement</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->applications as $app)
                            <tr wire:key="app-{{ $app->id }}" class="hover cursor-pointer"
                                onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.parc-settings.applications.show', ['id' => $app->id, 'from' => route('app.parc-settings.index', ['tab' => 'applications'], false)]) }}'">
                                <td class="checkbox-cell p-0">
                                    <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                        <input type="checkbox" class="checkbox checkbox-sm"
                                            wire:model.live="selectedApps" value="{{ $app->id }}" />
                                    </label>
                                </td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="{{ $app->status === \App\Enums\ApplicationStatus::Error ? 'text-error' : 'text-primary' }}">
                                            <i class="fa-solid {{ $app->status === \App\Enums\ApplicationStatus::Error ? 'fa-triangle-exclamation' : 'fa-cube' }} text-xl"></i>
                                        </div>
                                        <div>
                                            <span class="font-medium">{{ $app->name }}</span>
                                            @if ($app->status === \App\Enums\ApplicationStatus::Error)
                                                <span class="badge badge-error badge-xs ml-1">erreur</span>
                                            @elseif ($app->status === \App\Enums\ApplicationStatus::Downloading)
                                                <span class="badge badge-warning badge-xs ml-1">en cours</span>
                                            @endif
                                            @if ($app->branch && $app->branch !== 'stable')
                                                <span
                                                    class="badge badge-{{ $app->branch === 'testing' ? 'warning' : 'info' }} badge-xs ml-1">
                                                    {{ $app->branch }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <code class="text-xs bg-base-200 px-2 py-1 rounded">{{ $app->app_id }}</code>
                                </td>
                                <td>
                                    <span class="badge badge-ghost">{{ $app->version ?? '-' }}</span>
                                </td>
                                <td>
                                    <span class="text-sm">{{ $app->category ?? '-' }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="text-xs text-base-content/70">
                                        {{ $app->compatibility ?? '-' }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-sm badge-ghost">
                                        {{ $app->depot?->name ?? '-' }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    @php
                                        $deployTotal = ($app->deployed_total_count ?? 0);
                                        $deployInstalled = ($app->deployed_installed_count ?? 0);
                                    @endphp
                                    @if ($deployTotal > 0)
                                        @php $deployRate = round(($deployInstalled / $deployTotal) * 100); @endphp
                                        <span class="text-sm font-medium {{ $deployRate === 100 ? 'text-success' : ($deployRate === 0 ? 'text-error' : 'text-warning') }}">
                                            {{ $deployInstalled }}/{{ $deployTotal }}
                                        </span>
                                        <span class="text-xs text-base-content/50 ml-1">({{ $deployRate }}%)</span>
                                    @else
                                        <span class="text-base-content/30 text-sm">N/A</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-8 text-base-content/60">
                                    <i class="fa-solid fa-cube text-4xl mb-2 opacity-30"></i>
                                    <p>Aucune application trouvée</p>
                                    @if ($appSearch || $categoryFilter)
                                        <button type="button" class="btn btn-ghost btn-sm mt-2"
                                            wire:click="resetAppFilters">
                                            Réinitialiser les filtres
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if ($this->applications instanceof \Illuminate\Pagination\LengthAwarePaginator)
                <x-molecules.pagination :paginator="$this->applications" :allowedPerPage="$allowedPerPage" perPageModel="appsPerPage"
                    itemLabel="application" itemLabelPlural="applications" />
            @endif
        </div>
    </div>

    <!-- Actions groupées -->
    @if (count($selectedApps) > 0)
        <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
            <div class="card bg-base-100 shadow-xl border border-base-300">
                <div class="card-body py-3 px-4 flex-row items-center gap-4">
                    <span class="text-sm font-medium">
                        {{ count($selectedApps) }} application(s) sélectionnée(s)
                    </span>
                    <div class="divider divider-horizontal m-0"></div>
                    @if ($this->allSelectedAreError)
                        <button type="button" class="btn btn-warning btn-sm"
                            wire:click="retryInstallation"
                            wire:loading.attr="disabled"
                            wire:target="retryInstallation">
                            <span wire:loading.remove wire:target="retryInstallation">
                                <i class="fa-solid fa-rotate-right"></i>
                                Réessayer l'installation
                            </span>
                            <span wire:loading wire:target="retryInstallation">
                                <span class="loading loading-spinner loading-xs"></span>
                                Réinstallation...
                            </span>
                        </button>
                    @endif
                    <div class="dropdown dropdown-top">
                        <label tabindex="0" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-cog"></i>
                            Actions
                            <i class="fa-solid fa-chevron-up ml-1"></i>
                        </label>
                        <ul tabindex="0"
                            class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-56 border border-base-300 mb-2">
                            <li>
                                <button type="button" wire:click="addAppsToProfile">
                                    <i class="fa-solid fa-folder-plus"></i>
                                    Ajouter à un profil
                                </button>
                            </li>
                            <li>
                                <button type="button" wire:click="deployApps">
                                    <i class="fa-solid fa-rocket"></i>
                                    Déployer sur un groupe
                                </button>
                            </li>
                            <li>
                                <button type="button" class="text-error"
                                    wire:click="deleteSelectedApps"
                                    wire:confirm="Supprimer définitivement la ou les installation(s) sélectionnée(s) ? Les fichiers locaux et l'entrée du catalogue seront supprimés. Cette action est irréversible.">
                                    <i class="fa-solid fa-trash"></i>
                                    Supprimer l'installation
                                </button>
                            </li>
                        </ul>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('selectedApps', [])">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Modal AppStore -->
    @if ($showAppStoreModal)
        <div class="modal modal-open">
            <div class="modal-box max-w-4xl max-h-[80vh] flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-lg">
                        <i class="fa-solid fa-cloud-arrow-down mr-2 text-primary"></i>
                        Ajouter des applications depuis le dépôt
                    </h3>
                    <button type="button" class="btn btn-ghost btn-sm btn-circle" wire:click="closeAppStoreModal">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <!-- Actions et filtres -->
                <div class="flex flex-wrap gap-3 mb-4 items-end">
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="syncDepots"
                        wire:loading.attr="disabled" wire:target="syncDepots">
                        <span wire:loading.remove wire:target="syncDepots">
                            <i class="fa-solid fa-sync"></i>
                        </span>
                        <span wire:loading wire:target="syncDepots">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                        </span>
                        Synchroniser le dépôt
                    </button>

                    <div class="form-control flex-1 min-w-[200px]">
                        <input type="text" wire:model.live.debounce.300ms="appStoreSearch"
                            class="input input-bordered input-sm" placeholder="Rechercher une application..." />
                    </div>

                    <select wire:model.live="appStoreCategoryFilter" class="select select-bordered select-sm">
                        <option value="">Toutes catégories</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat }}">{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($lastSyncMessage)
                    <div class="alert alert-info alert-sm mb-4">
                        <i class="fa-solid fa-info-circle"></i>
                        <span>{{ $lastSyncMessage }}</span>
                    </div>
                @endif

                <!-- Onglets par branche -->
                <div class="tabs tabs-boxed mb-4">
                    <button type="button" class="tab {{ $appStoreBranchTab === 'stable' ? 'tab-active' : '' }}"
                        wire:click="switchBranchTab('stable')">
                        <i class="fa-solid fa-check-circle mr-1 text-success"></i>
                        Stable
                        <span class="badge badge-sm ml-1">{{ $branchCounts['stable'] ?? 0 }}</span>
                    </button>
                    <button type="button" class="tab {{ $appStoreBranchTab === 'testing' ? 'tab-active' : '' }}"
                        wire:click="switchBranchTab('testing')">
                        <i class="fa-solid fa-flask mr-1 text-warning"></i>
                        Testing
                        <span class="badge badge-sm ml-1">{{ $branchCounts['testing'] ?? 0 }}</span>
                    </button>
                    <button type="button" class="tab {{ $appStoreBranchTab === 'manuel' ? 'tab-active' : '' }}"
                        wire:click="switchBranchTab('manuel')">
                        <i class="fa-solid fa-hand mr-1 text-info"></i>
                        Manuel
                        <span class="badge badge-sm ml-1">{{ $branchCounts['manuel'] ?? 0 }}</span>
                    </button>
                </div>

                <!-- Liste des applications -->
                <div class="flex-1 overflow-auto border border-base-300 rounded-lg">
                    @if (count($depotApps) > 0)
                        <table class="table table-zebra table-sm table-pin-rows">
                            <thead>
                                <tr>
                                    <th class="w-10">
                                        <x-molecules.select-all-checkbox class="checkbox-sm" :ids="array_column($depotApps, 'id')"
                                            model="selectedDepotApps" />
                                    </th>
                                    <th>Application</th>
                                    <th>Version</th>
                                    <th>Catégorie</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($depotApps as $app)
                                    <tr wire:key="depot-app-{{ $app['id'] }}" class="hover cursor-pointer"
                                        wire:click="toggleDepotAppSelection({{ $app['id'] }})">
                                        <td>
                                            {{-- Affichage piloté par la propriété DOM (x-effect) : la ligne
                                                 entière toggle côté serveur via wire:click sur le <tr>. --}}
                                            <input type="checkbox" class="checkbox checkbox-sm"
                                                x-effect="$el.checked = ($wire.selectedDepotApps ?? []).map(String).includes('{{ $app['id'] }}')" />
                                        </td>
                                        <td>
                                            <div class="font-medium">{{ $app['name'] }}</div>
                                            <div class="text-xs text-base-content/60">{{ $app['app_id'] }}</div>
                                        </td>
                                        <td>
                                            <span
                                                class="badge badge-ghost badge-sm">{{ $app['version'] ?? '-' }}</span>
                                        </td>
                                        <td class="text-sm">{{ $app['category'] ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-base-content/60">
                            <i class="fa-solid fa-box-open text-4xl mb-3 opacity-30"></i>
                            <p>Aucune nouvelle application disponible</p>
                            <p class="text-sm">Cliquez sur "Synchroniser le dépôt" pour mettre à jour</p>
                        </div>
                    @endif
                </div>

                <!-- Actions -->
                <div class="modal-action mt-4">
                    <span class="text-sm text-base-content/70 mr-auto">
                        {{ count($selectedDepotApps) }} application(s) sélectionnée(s)
                    </span>
                    <button type="button" class="btn btn-ghost" wire:click="closeAppStoreModal">
                        Annuler
                    </button>
                    <button type="button" class="btn btn-primary" wire:click="installSelectedApps"
                        @if (count($selectedDepotApps) === 0) disabled @endif wire:loading.attr="disabled"
                        wire:target="installSelectedApps">
                        <span wire:loading.remove wire:target="installSelectedApps">
                            <i class="fa-solid fa-download mr-2"></i>
                            Installer
                        </span>
                        <span wire:loading wire:target="installSelectedApps">
                            <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                            Installation...
                        </span>
                    </button>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="closeAppStoreModal"></div>
        </div>
    @endif

    <!-- Modal "Ajouter à un profil" -->
    @if ($showAssignProfileModal)
        <div class="modal modal-open">
            <div class="modal-box max-w-lg max-h-[80vh] flex flex-col">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="font-bold text-lg">
                        <i class="fa-solid fa-folder-plus mr-2 text-primary"></i>
                        Ajouter à un profil
                    </h3>
                    <button type="button" class="btn btn-ghost btn-sm btn-circle" wire:click="closeAssignProfileModal">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <p class="text-sm text-base-content/60 mb-4">
                    {{ count($selectedApps) }} application(s) seront ajoutée(s) au(x) profil(s) sélectionné(s).
                </p>

                <div class="flex-1 overflow-auto border border-base-300 rounded-lg">
                    @if (count($profileOptions) > 0)
                        <div class="divide-y divide-base-200">
                            @foreach ($profileOptions as $profile)
                                <label wire:key="assign-profile-{{ $profile['id'] }}"
                                    class="flex items-center gap-3 p-3 cursor-pointer hover:bg-base-200 transition-colors">
                                    <input type="checkbox" class="checkbox checkbox-primary checkbox-sm"
                                        wire:click="toggleProfileSelection({{ $profile['id'] }})"
                                        @checked(in_array($profile['id'], $selectedProfileIds)) />
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 bg-secondary/20">
                                        <i class="fa-solid fa-layer-group text-secondary"></i>
                                    </div>
                                    <span class="font-medium truncate">{{ $profile['name'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-base-content/60">
                            <i class="fa-solid fa-layer-group text-4xl mb-3 opacity-30"></i>
                            <p>Aucun profil applicatif disponible</p>
                        </div>
                    @endif
                </div>

                <div class="modal-action mt-4">
                    <span class="text-sm text-base-content/70 mr-auto">
                        {{ count($selectedProfileIds) }} profil(s) sélectionné(s)
                    </span>
                    <button type="button" class="btn btn-ghost" wire:click="closeAssignProfileModal">
                        Annuler
                    </button>
                    <button type="button" class="btn btn-primary" wire:click="confirmAddToProfile"
                        @if (count($selectedProfileIds) === 0) disabled @endif
                        wire:loading.attr="disabled" wire:target="confirmAddToProfile">
                        <span wire:loading.remove wire:target="confirmAddToProfile">
                            <i class="fa-solid fa-folder-plus mr-2"></i>
                            Ajouter
                        </span>
                        <span wire:loading wire:target="confirmAddToProfile">
                            <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                            Ajout...
                        </span>
                    </button>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="closeAssignProfileModal"></div>
        </div>
    @endif

    <!-- Modal "Déployer sur un groupe" -->
    @if ($showDeployGroupModal)
        <div class="modal modal-open">
            <div class="modal-box max-w-lg max-h-[80vh] flex flex-col">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="font-bold text-lg">
                        <i class="fa-solid fa-rocket mr-2 text-primary"></i>
                        Déployer sur un groupe
                    </h3>
                    <button type="button" class="btn btn-ghost btn-sm btn-circle" wire:click="closeDeployGroupModal">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <p class="text-sm text-base-content/60 mb-4">
                    {{ count($selectedApps) }} application(s) seront déployée(s) sur le(s) groupe(s) sélectionné(s).
                </p>

                <div class="flex-1 overflow-auto border border-base-300 rounded-lg">
                    @if (count($groupOptions) > 0)
                        <div class="divide-y divide-base-200">
                            @foreach ($groupOptions as $group)
                                <label wire:key="deploy-group-{{ $group['id'] }}"
                                    class="flex items-center gap-3 p-3 cursor-pointer hover:bg-base-200 transition-colors">
                                    <input type="checkbox" class="checkbox checkbox-primary checkbox-sm"
                                        wire:click="toggleGroupSelection({{ $group['id'] }})"
                                        @checked(in_array($group['id'], $selectedGroupIds)) />
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0
                                        {{ $group['is_physical'] ? 'bg-warning/20' : 'bg-primary/20' }}">
                                        <i class="fa-solid {{ $group['is_physical'] ? 'fa-door-open text-warning' : 'fa-layer-group text-primary' }}"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium truncate">{{ $group['name'] }}</div>
                                        <div class="text-xs text-base-content/60">
                                            {{ $group['is_physical'] ? 'Salle' : 'Parc' }}
                                        </div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-base-content/60">
                            <i class="fa-solid fa-layer-group text-4xl mb-3 opacity-30"></i>
                            <p>Aucun groupe de postes disponible</p>
                        </div>
                    @endif
                </div>

                <div class="modal-action mt-4">
                    <span class="text-sm text-base-content/70 mr-auto">
                        {{ count($selectedGroupIds) }} groupe(s) sélectionné(s)
                    </span>
                    <button type="button" class="btn btn-ghost" wire:click="closeDeployGroupModal">
                        Annuler
                    </button>
                    <button type="button" class="btn btn-primary" wire:click="confirmDeployToGroup"
                        @if (count($selectedGroupIds) === 0) disabled @endif
                        wire:loading.attr="disabled" wire:target="confirmDeployToGroup">
                        <span wire:loading.remove wire:target="confirmDeployToGroup">
                            <i class="fa-solid fa-rocket mr-2"></i>
                            Déployer
                        </span>
                        <span wire:loading wire:target="confirmDeployToGroup">
                            <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                            Déploiement...
                        </span>
                    </button>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="closeDeployGroupModal"></div>
        </div>
    @endif
</div>
