<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use App\Services\AppProfile\AppProfileService;
use App\Exceptions\ControlHub\ApplicationNotInUpstreamCatalogException;
use App\Components\Traits\WithToasts;
use App\Components\Traits\WithReturnBack;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\WorkstationGroup;
use App\Models\Workstation;
use Illuminate\Support\Facades\Log;

new #[Title('Détail du Profil - SE4FS')] class extends Component {
    use WithToasts;
    use WithPagination;
    use WithReturnBack;

    private AppProfileService $appProfileService;

    public int $profileId;
    public ?AppProfile $profile = null;

    // Onglet d'origine (URL relative) pour le bouton retour — voir WithReturnBack.
    #[Url]
    public ?string $from = null;

    // Onglet actif — toujours reflété dans l'URL, deep-link supporté.
    #[Url(keep: true)]
    public string $tab = 'applications';

    /** Onglets valides (allow-list du switch). */
    private const TABS = ['applications', 'groups', 'workstations'];

    /** URL de retour : provenance dynamique, repli sur l'onglet Profils. */
    public function backUrl(): string
    {
        return $this->resolveBack(route('app.parc-settings.index', ['tab' => 'profiles']));
    }

    // Recherche applications
    public string $appSearch = '';

    // Recherche groupes
    public string $groupSearch = '';
    public bool $showInheritedGroups = false;

    // Recherche postes
    public string $workstationSearch = '';

    // Modal ajout applications
    public bool $showAddAppsModal = false;
    public array $selectedAppsToAdd = [];
    public string $addAppSearch = '';

    // Modal ajout groupes
    public bool $showAddGroupsModal = false;
    public array $selectedGroupsToAdd = [];
    public string $addGroupSearch = '';

    // Modal ajout postes
    public bool $showAddWorkstationsModal = false;
    public array $selectedWorkstationsToAdd = [];
    public string $addWorkstationSearch = '';

    // Édition
    public bool $isEditing = false;
    public string $editName = '';
    public string $editDisplayName = '';
    public string $editDescription = '';

    public function boot(AppProfileService $appProfileService): void
    {
        $this->appProfileService = $appProfileService;
    }

    public function mount(int $id): void
    {
        $this->profileId = $id;
        $this->loadProfile();

        if (!$this->profile) {
            abort(404, 'Profil non trouvé');
        }

        if (session()->has('toast')) {
            $toastData = session('toast');
            $this->toast($toastData['type'] ?? 'info', $toastData['title'] ?? 'Notification', $toastData['message'] ?? '');
        }
    }

    public function loadProfile(): void
    {
        $this->profile = $this->appProfileService->getProfile($this->profileId);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'applications';
        $this->resetPage();
    }

    // Édition du profil
    public function startEditing(): void
    {
        $this->editName = $this->profile->name;
        $this->editDisplayName = $this->profile->display_name ?? '';
        $this->editDescription = $this->profile->description ?? '';
        $this->isEditing = true;
    }

    public function cancelEditing(): void
    {
        $this->isEditing = false;
    }

    public function saveProfile(): void
    {
        $this->validate([
            'editName' => 'required|string|max:100|unique:app_profiles,name,' . $this->profileId,
            'editDisplayName' => 'nullable|string|max:255',
            'editDescription' => 'nullable|string',
        ]);

        try {
            $this->appProfileService->updateProfile($this->profileId, [
                'name' => $this->editName,
                'display_name' => $this->editDisplayName ?: null,
                'description' => $this->editDescription ?: null,
            ]);

            $this->loadProfile();
            $this->isEditing = false;
            $this->toastSuccess('Profil mis à jour avec succès');
        } catch (\Exception $e) {
            Log::error('[ProfileDetail] Erreur mise à jour: ' . $e->getMessage());
            $this->toastError('Erreur lors de la mise à jour');
        }
    }

    // Applications du profil
    public function getProfileApplicationsProperty()
    {
        if (!$this->profile) {
            return collect();
        }

        $query = $this->profile->applications();

        if ($this->appSearch) {
            $query->where(function ($q) {
                $q->where('name', 'LIKE', "%{$this->appSearch}%")->orWhere('app_id', 'LIKE', "%{$this->appSearch}%");
            });
        }

        return $query->orderBy('name')->paginate(15);
    }

    // Groupes du profil (directs uniquement)
    public function getProfileGroupsProperty()
    {
        if (!$this->profile) {
            return collect();
        }

        $query = $this->profile->workstationGroups();

        if ($this->groupSearch) {
            $query->where('name', 'LIKE', "%{$this->groupSearch}%");
        }

        return $query->orderBy('name')->paginate(15);
    }

    // Groupes hérités (descendants des groupes directs)
    public function getInheritedGroupsProperty()
    {
        if (!$this->profile || !$this->showInheritedGroups) {
            return collect();
        }

        $directGroupIds = $this->profile->workstationGroups->pluck('id')->toArray();

        if (empty($directGroupIds)) {
            return collect();
        }

        // Récupérer tous les descendants des groupes directs
        $inheritedGroups = collect();

        foreach ($this->profile->workstationGroups as $group) {
            $descendants = $this->getDescendants($group);
            foreach ($descendants as $descendant) {
                // Éviter les doublons
                if (!$inheritedGroups->contains('id', $descendant->id)) {
                    $inheritedGroups->push($descendant);
                }
            }
        }

        // Filtrer par recherche si nécessaire
        if ($this->groupSearch) {
            $inheritedGroups = $inheritedGroups->filter(function ($group) {
                return str_contains(strtolower($group->name), strtolower($this->groupSearch));
            });
        }

        return $inheritedGroups->sortBy('name')->values();
    }

    // Récupère récursivement tous les descendants d'un groupe
    private function getDescendants(WorkstationGroup $group): array
    {
        $descendants = [];
        $children = WorkstationGroup::where('parent_id', $group->id)->get();

        foreach ($children as $child) {
            $descendants[] = $child;
            $descendants = array_merge($descendants, $this->getDescendants($child));
        }

        return $descendants;
    }

    // Applications disponibles (depuis depot_applications, exclut celles déjà dans le profil)
    public function getAvailableApplicationsProperty()
    {
        if (!$this->profile) {
            return collect();
        }

        // IDs des applications déjà dans le profil
        $existingAppIds = $this->profile->applications->pluck('id')->toArray();

        // Story 31.1 — canal d'install (composition de profil) borné au catalogue
        // applicatif amont. Pass-through si standalone / catalogue vide (NFR3).
        $query = Application::query()->inUpstreamCatalog();

        // Exclure les applications déjà présentes
        if (!empty($existingAppIds)) {
            $query->whereNotIn('id', $existingAppIds);
        }

        if ($this->addAppSearch) {
            $query->where(function ($q) {
                $q->where('name', 'LIKE', "%{$this->addAppSearch}%")->orWhere('app_id', 'LIKE', "%{$this->addAppSearch}%");
            });
        }

        return $query->orderBy('name')->limit(50)->get();
    }

    // Groupes disponibles (non encore liés au profil)
    public function getAvailableGroupsProperty()
    {
        if (!$this->profile) {
            return collect();
        }

        $existingIds = $this->profile->workstationGroups()->pluck('workstation_groups.id')->toArray();

        $query = WorkstationGroup::whereNotIn('id', $existingIds);

        if ($this->addGroupSearch) {
            $query->where('name', 'LIKE', "%{$this->addGroupSearch}%");
        }

        return $query->orderBy('name')->limit(50)->get();
    }

    // Postes du profil
    public function getProfileWorkstationsProperty()
    {
        if (!$this->profile) {
            return collect();
        }

        $query = $this->profile->workstations();

        if ($this->workstationSearch) {
            $query->where('name', 'LIKE', "%{$this->workstationSearch}%");
        }

        return $query->orderBy('name')->paginate(15);
    }

    // Postes disponibles (non encore liés au profil)
    public function getAvailableWorkstationsProperty()
    {
        if (!$this->profile) {
            return collect();
        }

        $existingIds = $this->profile->workstations()->pluck('workstations.id')->toArray();

        $query = Workstation::whereNotIn('id', $existingIds);

        if ($this->addWorkstationSearch) {
            $query->where('name', 'LIKE', "%{$this->addWorkstationSearch}%");
        }

        return $query->orderBy('name')->limit(50)->get();
    }

    // Modal applications
    public function openAddAppsModal(): void
    {
        $this->selectedAppsToAdd = [];
        $this->addAppSearch = '';
        $this->showAddAppsModal = true;
    }

    public function closeAddAppsModal(): void
    {
        $this->showAddAppsModal = false;
    }

    public function addSelectedApps(): void
    {
        if (empty($this->selectedAppsToAdd)) {
            $this->toastError('Aucune application sélectionnée');
            return;
        }

        try {
            $this->appProfileService->addApplications($this->profileId, $this->selectedAppsToAdd);
            $this->loadProfile();
            $this->closeAddAppsModal();
            $this->toastSuccess(count($this->selectedAppsToAdd) . ' application(s) ajoutée(s)');
        } catch (ApplicationNotInUpstreamCatalogException $e) {
            // Story 31.1 — refus explicite « hors catalogue amont » (FR8), pas un
            // échec opaque. Filet defense-in-depth (la liste proposée est déjà filtrée).
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[ProfileDetail] Erreur ajout apps: ' . $e->getMessage());
            $this->toastError('Erreur lors de l\'ajout des applications');
        }
    }

    public function removeApplication(int $appId): void
    {
        try {
            $this->appProfileService->removeApplications($this->profileId, [$appId]);
            $this->loadProfile();
            $this->toastSuccess('Application retirée du profil');
        } catch (\Exception $e) {
            Log::error('[ProfileDetail] Erreur retrait app: ' . $e->getMessage());
            $this->toastError('Erreur lors du retrait de l\'application');
        }
    }

    // Modal groupes
    public function openAddGroupsModal(): void
    {
        $this->selectedGroupsToAdd = [];
        $this->addGroupSearch = '';
        $this->showAddGroupsModal = true;
    }

    public function closeAddGroupsModal(): void
    {
        $this->showAddGroupsModal = false;
    }

    public function addSelectedGroups(): void
    {
        if (empty($this->selectedGroupsToAdd)) {
            $this->toastError('Aucun groupe sélectionné');
            return;
        }

        try {
            $this->appProfileService->addWorkstationGroups($this->profileId, $this->selectedGroupsToAdd);
            $this->loadProfile();
            $this->closeAddGroupsModal();
            $this->toastSuccess(count($this->selectedGroupsToAdd) . ' groupe(s) ajouté(s)');
        } catch (\Exception $e) {
            Log::error('[ProfileDetail] Erreur ajout groupes: ' . $e->getMessage());
            $this->toastError('Erreur lors de l\'ajout des groupes');
        }
    }

    public function removeGroup(int $groupId): void
    {
        try {
            $this->appProfileService->removeWorkstationGroups($this->profileId, [$groupId]);
            $this->loadProfile();
            $this->toastSuccess('Groupe retiré du profil');
        } catch (\Exception $e) {
            Log::error('[ProfileDetail] Erreur retrait groupe: ' . $e->getMessage());
            $this->toastError('Erreur lors du retrait du groupe');
        }
    }

    // Modal postes
    public function openAddWorkstationsModal(): void
    {
        $this->selectedWorkstationsToAdd = [];
        $this->addWorkstationSearch = '';
        $this->showAddWorkstationsModal = true;
    }

    public function closeAddWorkstationsModal(): void
    {
        $this->showAddWorkstationsModal = false;
    }

    public function addSelectedWorkstations(): void
    {
        if (empty($this->selectedWorkstationsToAdd)) {
            $this->toastError('Aucun poste sélectionné');
            return;
        }

        try {
            $this->appProfileService->addWorkstations($this->profileId, $this->selectedWorkstationsToAdd);
            $this->loadProfile();
            $this->closeAddWorkstationsModal();
            $this->toastSuccess(count($this->selectedWorkstationsToAdd) . ' poste(s) ajouté(s)');
        } catch (\Exception $e) {
            Log::error('[ProfileDetail] Erreur ajout postes: ' . $e->getMessage());
            $this->toastError('Erreur lors de l\'ajout des postes');
        }
    }

    public function removeWorkstation(int $workstationId): void
    {
        try {
            $this->appProfileService->removeWorkstations($this->profileId, [$workstationId]);
            $this->loadProfile();
            $this->toastSuccess('Poste retiré du profil');
        } catch (\Exception $e) {
            Log::error('[ProfileDetail] Erreur retrait poste: ' . $e->getMessage());
            $this->toastError('Erreur lors du retrait du poste');
        }
    }
};
?>

<x-organisms.page title="Profil applicatif" :scrollable="false" backUrl="{{ $this->backUrl() }}"
    backText="Retour aux paramètres">

    <x-slot:actions>
        <div class="flex gap-2">
            @if (!$isEditing)
                <button type="button" class="btn btn-outline btn-sm" wire:click="startEditing">
                    <i class="fa-solid fa-pen"></i>
                    Modifier
                </button>
            @endif
        </div>
    </x-slot:actions>

    @if ($profile)
        <div class="h-full flex flex-col gap-4">
            <!-- En-tête du profil -->
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    @if ($isEditing)
                        <form wire:submit="saveProfile" class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">Nom technique *</span>
                                    </label>
                                    <input type="text" wire:model="editName" class="input input-bordered" required />
                                    @error('editName')
                                        <label class="label">
                                            <span class="label-text-alt text-error">{{ $message }}</span>
                                        </label>
                                    @enderror
                                </div>
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">Nom d'affichage</span>
                                    </label>
                                    <input type="text" wire:model="editDisplayName" class="input input-bordered" />
                                </div>
                            </div>
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">Description</span>
                                </label>
                                <textarea wire:model="editDescription" class="textarea textarea-bordered" rows="2"></textarea>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" class="btn btn-ghost" wire:click="cancelEditing">Annuler</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-check mr-1"></i>
                                    Enregistrer
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="flex items-center gap-4 mb-2">
                                    <div class="bg-primary/10 text-primary rounded-xl w-16 h-16 flex items-center justify-center">
                                        <i class="fa-solid fa-layer-group text-2xl"></i>
                                    </div>
                                    <h2 class="text-xl font-bold">{{ $profile->display_name ?? $profile->name }}</h2>
                                    @if ($profile->display_name)
                                        <p class="text-sm text-base-content/60">
                                            <code class="bg-base-200 px-2 py-0.5 rounded">{{ $profile->name }}</code>
                                        </p>
                                    @endif
                                </div>
                                @if ($profile->description)
                                    <p class="mt-2 text-base-content/70">{{ $profile->description }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="stat p-0">
                                    <div class="stat-title text-xs">Applications</div>
                                    <div class="stat-value text-2xl">{{ $profile->applications->count() }}</div>
                                </div>
                                <div class="stat p-0">
                                    <div class="stat-title text-xs">Groupes</div>
                                    <div class="stat-value text-2xl">{{ $profile->workstationGroups->count() }}</div>
                                </div>
                                <div class="stat p-0">
                                    <div class="stat-title text-xs">Postes</div>
                                    <div class="stat-value text-2xl">{{ $profile->workstations->count() }}</div>
                                </div>
                                @if ($profile->is_active)
                                    <span class="badge badge-success">Actif</span>
                                @else
                                    <span class="badge badge-warning">Inactif</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Onglets -->
            @php
                $profileTabs = [
                    'applications' => ['label' => 'Applications', 'icon' => 'fa-solid fa-cube', 'badge' => $profile->applications->count()],
                    'groups' => ['label' => 'Groupes de postes', 'icon' => 'fa-solid fa-folder-tree', 'badge' => $profile->workstationGroups->count()],
                    'workstations' => ['label' => 'Postes', 'icon' => 'fa-solid fa-computer', 'badge' => $profile->workstations->count()],
                ];
            @endphp
            <x-molecules.tabs :tabs="$profileTabs" :active="$tab" class="bg-base-200 w-fit" />

            <!-- Contenu des onglets -->
            <div class="flex-1 min-h-0">
                @if ($tab === 'applications')
                    @include('pages.parc-settings.profiles._partials.applications-tab')
                @elseif ($tab === 'groups')
                    @include('pages.parc-settings.profiles._partials.groups-tab')
                @else
                    @include('pages.parc-settings.profiles._partials.workstations-tab')
                @endif
            </div>
        </div>

        {{-- Story 15.4 / Décision B 2026-05-07 — Modales partagées sous
             `components/organisms/wpkg/`. Comportement strictement équivalent
             aux anciens partials @include (test de non-régression
             ProfileAttachModalsRegressionTest). --}}
        @if ($showAddAppsModal)
            <x-organisms.wpkg.attach-apps-modal
                title="Ajouter des applications au profil"
                :items="$this->availableApplications"
                searchProperty="addAppSearch"
                selectionProperty="selectedAppsToAdd"
                :searchValue="$addAppSearch"
                closeMethod="closeAddAppsModal"
                confirmMethod="addSelectedApps"
                :selectionCount="count($selectedAppsToAdd)"
                keyPrefix="add-app"
                context="profile" />
        @endif

        @if ($showAddGroupsModal)
            <x-organisms.wpkg.attach-groups-modal
                title="Ajouter des groupes au profil"
                :items="$this->availableGroups"
                searchProperty="addGroupSearch"
                selectionProperty="selectedGroupsToAdd"
                :searchValue="$addGroupSearch"
                closeMethod="closeAddGroupsModal"
                confirmMethod="addSelectedGroups"
                :selectionCount="count($selectedGroupsToAdd)"
                keyPrefix="add-group" />
        @endif

        @if ($showAddWorkstationsModal)
            <x-organisms.wpkg.attach-workstations-modal
                title="Ajouter des postes au profil"
                :items="$this->availableWorkstations"
                searchProperty="addWorkstationSearch"
                selectionProperty="selectedWorkstationsToAdd"
                :searchValue="$addWorkstationSearch"
                closeMethod="closeAddWorkstationsModal"
                confirmMethod="addSelectedWorkstations"
                :selectionCount="count($selectedWorkstationsToAdd)"
                keyPrefix="add-ws" />
        @endif
    @else
        <div class="alert alert-error">
            <i class="fa-solid fa-exclamation-triangle"></i>
            <span>Profil non trouvé</span>
        </div>
    @endif
</x-organisms.page>
