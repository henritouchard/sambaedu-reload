<?php

use App\Components\Traits\WithToasts;
use App\Models\Application;
use App\Models\AppProfile;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Services\AppProfile\AppProfileService;
use App\Services\AppStore\AppStoreService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Paramètres du Parc - SE4FS')] class extends Component
{
    use WithPagination;
    use WithToasts;

    private AppProfileService $appProfileService;

    private AppStoreService $appStoreService;

    // Onglet actif
    #[Url(keep: true)]
    public string $tab = 'profiles';

    // Filtres profils
    #[Url]
    public string $profileSearch = '';

    #[Url]
    public ?bool $activeOnly = null;

    // Filtres applications
    #[Url]
    public string $appSearch = '';

    #[Url]
    public string $categoryFilter = '';

    // Sélection
    public array $selectedProfiles = [];

    public array $selectedApps = [];

    // Pagination
    #[Url]
    public int $profilesPerPage = 20;

    #[Url]
    public int $appsPerPage = 20;

    public array $allowedPerPage = [10, 20, 50, 100];

    // Données
    public array $stats = [];

    public array $categories = [];

    // États
    public bool $statsLoaded = false;

    // Modal création profil
    public bool $showCreateModal = false;

    public string $newProfileName = '';

    public string $newProfileDisplayName = '';

    public string $newProfileDescription = '';

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

    public function boot(AppProfileService $appProfileService, AppStoreService $appStoreService): void
    {
        $this->appProfileService = $appProfileService;
        $this->appStoreService = $appStoreService;
    }

    public function mount(): void
    {
        $this->loadFiltersData();

        if (session()->has('toast')) {
            $toastData = session('toast');
            $this->toast($toastData['type'] ?? 'info', $toastData['title'] ?? 'Notification', $toastData['message'] ?? '');
        }
    }

    public function loadFiltersData(): void
    {
        try {
            $this->categories = $this->appProfileService->getCategories()->toArray();
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur chargement filtres: '.$e->getMessage());
            $this->categories = [];
        }
    }

    public function loadStats(): void
    {
        if ($this->statsLoaded) {
            return;
        }

        try {
            $this->stats = $this->appProfileService->getStats();
            $this->statsLoaded = true;
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur chargement stats: '.$e->getMessage());
            $this->stats = [];
            $this->statsLoaded = true;
        }
    }

    public function getProfilesProperty()
    {
        try {
            return $this->appProfileService->listProfiles(perPage: $this->profilesPerPage, search: $this->profileSearch ?: null, activeOnly: $this->activeOnly);
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur chargement profils: '.$e->getMessage());

            return collect();
        }
    }

    public function getApplicationsProperty()
    {
        try {
            return $this->appProfileService->listApplications(perPage: $this->appsPerPage, search: $this->appSearch ?: null, category: $this->categoryFilter ?: null);
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur chargement applications: '.$e->getMessage());

            return collect();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function resetProfileFilters(): void
    {
        $this->profileSearch = '';
        $this->activeOnly = null;
        $this->selectedProfiles = [];
        $this->resetPage();
    }

    public function resetAppFilters(): void
    {
        $this->appSearch = '';
        $this->categoryFilter = '';
        $this->selectedApps = [];
        $this->resetPage();
    }

    public function updatedProfilesPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedAppsPerPage(): void
    {
        $this->resetPage();
    }

    // Actions sur les profils
    public function openCreateModal(): void
    {
        $this->newProfileName = '';
        $this->newProfileDisplayName = '';
        $this->newProfileDescription = '';
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
    }

    public function createProfile(): void
    {
        $this->validate([
            'newProfileName' => 'required|string|max:100|unique:app_profiles,name',
            'newProfileDisplayName' => 'nullable|string|max:255',
            'newProfileDescription' => 'nullable|string',
        ]);

        try {
            $profile = $this->appProfileService->createProfile([
                'name' => $this->newProfileName,
                'display_name' => $this->newProfileDisplayName ?: null,
                'description' => $this->newProfileDescription ?: null,
                'is_active' => true,
            ]);

            $this->toastSuccess("Profil '{$profile->name}' créé avec succès");
            $this->closeCreateModal();
            $this->statsLoaded = false;
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur création profil: '.$e->getMessage());
            $this->toastError('Erreur lors de la création du profil');
        }
    }

    public function deleteProfile(int $profileId): void
    {
        try {
            $profile = AppProfile::find($profileId);
            if (! $profile) {
                $this->toastError('Profil non trouvé');

                return;
            }

            $name = $profile->name;
            $this->appProfileService->deleteProfile($profileId);
            $this->toastSuccess("Profil '{$name}' supprimé avec succès");
            $this->statsLoaded = false;
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur suppression profil: '.$e->getMessage());
            $this->toastError($e->getMessage());
        }
    }

    public function toggleProfileActive(int $profileId): void
    {
        try {
            $profile = AppProfile::find($profileId);
            if (! $profile) {
                $this->toastError('Profil non trouvé');

                return;
            }

            $this->appProfileService->updateProfile($profileId, [
                'is_active' => ! $profile->is_active,
            ]);

            $status = ! $profile->is_active ? 'activé' : 'désactivé';
            $this->toastSuccess("Profil '{$profile->name}' {$status}");
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur toggle profil: '.$e->getMessage());
            $this->toastError('Erreur lors de la modification du profil');
        }
    }

    // ========================================
    // AppStore Modal
    // ========================================

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

            // Charger les compteurs par branche
            $this->branchCounts = DepotApplication::query()->whereIn('depot_id', $activeDepotIds)->whereNotIn('app_id', $installedAppIds)->selectRaw('branch, COUNT(DISTINCT app_id) as cnt')->groupBy('branch')->pluck('cnt', 'branch')->toArray();

            // Récupérer les apps de la branche sélectionnée
            $apps = DepotApplication::query()
                ->whereIn('depot_id', $activeDepotIds)
                ->whereNotIn('app_id', $installedAppIds)
                ->where('branch', $this->appStoreBranchTab)
                ->when(
                    $this->appStoreSearch,
                    fn ($q) => $q->where(function ($q2) {
                        $q2->where('name', 'ILIKE', "%{$this->appStoreSearch}%")->orWhere('app_id', 'ILIKE', "%{$this->appStoreSearch}%");
                    }),
                )
                ->when($this->appStoreCategoryFilter, fn ($q) => $q->where('category', $this->appStoreCategoryFilter))
                ->orderBy('name')
                ->limit(100)
                ->get();

            $this->depotApps = $apps
                ->map(
                    fn ($app) => [
                        'id' => $app->id,
                        'app_id' => $app->app_id,
                        'name' => $app->name,
                        'version' => $app->version,
                        'category' => $app->category,
                        'branch' => $app->branch,
                    ],
                )
                ->toArray();
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur chargement apps dépôt: '.$e->getMessage());
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
            $this->statsLoaded = false;
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur sync dépôts: '.$e->getMessage());
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
                    Log::error("[ParcSettings] Erreur installation app {$depotAppId}: ".$e->getMessage());
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
            $this->statsLoaded = false;
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur installation apps: '.$e->getMessage());
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

    public function selectAllDepotApps(): void
    {
        $this->selectedDepotApps = array_column($this->depotApps, 'id');
    }

    public function deselectAllDepotApps(): void
    {
        $this->selectedDepotApps = [];
    }
};
?>

<x-organisms.page title="Paramètres du Parc" :scrollable="false"
    description="Gérez les profils applicatifs et le catalogue d'applications">

    <x-slot:actions>
        <div class="flex gap-2">
            @if ($tab === 'profiles')
                <button type="button" class="btn btn-primary" wire:click="openCreateModal">
                    <i class="fa-solid fa-plus"></i>
                    Nouveau Profil
                </button>
            @elseif ($tab === 'applications')
                <button type="button" class="btn btn-primary" wire:click="openAppStoreModal">
                    <i class="fa-solid fa-cloud-arrow-down"></i>
                    Ajouter des applications
                </button>
            @endif
        </div>
    </x-slot:actions>

    <!-- Chargement asynchrone des stats -->
    <div wire:init="loadStats"></div>

    <div class="h-full flex flex-col gap-4">
        <!-- Onglets -->
        <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit">
            <button type="button" role="tab" class="tab {{ $tab === 'profiles' ? 'tab-active' : '' }}"
                wire:click="setTab('profiles')">
                <i class="fa-solid fa-layer-group mr-2"></i>
                Profils Applicatifs
                @if ($statsLoaded && isset($stats['profiles_count']))
                    <span class="badge badge-sm ml-2">{{ $stats['profiles_count'] }}</span>
                @endif
            </button>
            <button type="button" role="tab" class="tab {{ $tab === 'applications' ? 'tab-active' : '' }}"
                wire:click="setTab('applications')">
                <i class="fa-solid fa-cube mr-2"></i>
                Catalogue d'Applications
                @if ($statsLoaded && isset($stats['applications_count']))
                    <span class="badge badge-sm ml-2">{{ $stats['applications_count'] }}</span>
                @endif
            </button>
        </div>

        <!-- Contenu des onglets -->
        <div class="flex-1 min-h-0 flex flex-col">
            @if ($tab === 'profiles')
                {{-- Vérification synchronisation AD/SQL --}}
                <div class="flex-shrink-0">
                    <livewire:components::molecules.app-profile-sync-status />
                </div>
                @include('pages.parc-settings._partials.profiles-tab')
            @else
                @include('pages.parc-settings._partials.applications-tab')
            @endif
        </div>
    </div>

    <!-- Modal création profil -->
    @if ($showCreateModal)
        <div class="modal modal-open">
            <div class="modal-box">
                <h3 class="font-bold text-lg mb-4">Nouveau Profil Applicatif</h3>
                <form wire:submit="createProfile">
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text">Nom technique *</span>
                        </label>
                        <input type="text" wire:model="newProfileName" class="input input-bordered"
                            placeholder="ex: salle-info-101" required />
                        @error('newProfileName')
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $message }}</span>
                            </label>
                        @enderror
                    </div>
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text">Nom d'affichage</span>
                        </label>
                        <input type="text" wire:model="newProfileDisplayName" class="input input-bordered"
                            placeholder="ex: Salle Informatique 101" />
                    </div>
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text">Description</span>
                        </label>
                        <textarea wire:model="newProfileDescription" class="textarea textarea-bordered" rows="3"
                            placeholder="Description du profil..."></textarea>
                    </div>
                    <div class="modal-action">
                        <button type="button" class="btn" wire:click="closeCreateModal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-check mr-2"></i>
                            Créer
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-backdrop" wire:click="closeCreateModal"></div>
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
                                        <input type="checkbox" class="checkbox checkbox-sm"
                                            @if (count($selectedDepotApps) === count($depotApps) && count($depotApps) > 0) checked @endif
                                            wire:click="{{ count($selectedDepotApps) === count($depotApps) ? 'deselectAllDepotApps' : 'selectAllDepotApps' }}" />
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
                                            <input type="checkbox" class="checkbox checkbox-sm"
                                                @if (in_array($app['id'], $selectedDepotApps)) checked @endif />
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
</x-organisms.page>
