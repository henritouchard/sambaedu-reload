<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use App\Components\Traits\WithToasts;
use App\Enums\SambaRole;
use App\Models\DelegationHistory;
use App\Models\User as EloquentUser;
use App\Models\Delegation;
use App\Models\UserGroup;
use App\Services\GroupRightsProfileService;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

new #[Title('Gestion des droits - Instance SE4FS')] class extends Component {
    use WithToasts;
    use WithPagination;

    #[Url(as: 'tab', keep: true)]
    public string $activeTab = 'profiles';

    // Recherche utilisateur
    public string $userSearch = '';
    public array $foundUsers = [];
    public ?string $selectedUserLogin = null;
    public ?array $selectedUserDetails = null;

    // Données
    public array $delegationsOverview = [];
    public bool $dataLoaded = false;
    /** Sélection multi sur l'onglet Délégations actives. */
    public array $selectedDelegations = [];

    // Story 7.1 — Onglet Historique (4ᵉ onglet)
    #[Url]
    public string $historyActionFilter = '';
    #[Url]
    public string $historyTargetFilter = '';
    #[Url]
    public string $historyFromFilter = '';
    #[Url]
    public string $historyToFilter = '';
    public int $historyPerPage = 25;

    // ------------------------------------------------------------------
    // Story 7.2 / 49.1 — Onglet Profils
    // ------------------------------------------------------------------

    /**
     * Story 49.1 (AC7) — section PRINCIPALE : les groupes qui PORTENT un profil
     * de droits. C'est la réponse au recadrage « l'onglet liste les groupes
     * porteurs, pas des objets indépendants ».
     */
    public array $carrierGroupsList = [];

    /**
     * Story 49.1 (AC7 / D7) — section secondaire : les profils portés par AUCUN
     * groupe (délégations `user-admin`/`technicien`, réserve de profils custom).
     * Sans elle, ces profils n'auraient plus AUCUN point d'entrée d'édition ni
     * de suppression, alors que les drawers continuent de les attribuer.
     */
    public array $unattachedProfilesList = [];

    /** Sélection multi pour suppression depuis le menu actions de la page. */
    public array $selectedProfiles = [];

    // --- Modale « Donner des permissions à un groupe » / « Changer le profil »
    public bool $showProfileAssignModal = false;
    /** 'assign' (groupe à choisir) | 'change' (groupe figé). */
    public string $profileAssignMode = 'assign';
    public ?int $profileAssignGroupId = null;
    public string $profileAssignGroupLabel = '';
    public string $profileGroupSearch = '';
    public array $profileGroupResults = [];
    public ?int $profileAssignRoleId = null;
    /** Profils sélectionnables dans la modale (tous les rôles guard `web`). */
    public array $profileChoices = [];

    // --- Modale de confirmation de retrait
    public bool $showProfileRemoveModal = false;
    public ?int $profileRemoveGroupId = null;
    public string $profileRemoveGroupLabel = '';
    public string $profileRemoveProfileLabel = '';

    public function mount(): void
    {
    }

    public function loadData(): void
    {
        if ($this->dataLoaded) {
            return;
        }

        // Délégations actives
        $this->delegationsOverview = Delegation::with(['user', 'workstationGroup', 'permission'])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn(Delegation $d) => [
                'id' => $d->id,
                'user_login' => $d->user->login ?? '?',
                'user_fullname' => $d->user->fullname ?? $d->user->login ?? '?',
                'workstation_group' => $d->workstationGroup->name ?? '?',
                'workstation_group_id' => $d->workstation_group_id,
                'permission' => $d->permission->name ?? '?',
                'is_negative' => $d->is_negative,
                'expires_at' => $d->expires_at?->format('d/m/Y H:i'),
                'expires_at_iso' => $d->expires_at?->toIso8601String(),
                'created_at' => $d->created_at?->format('d/m/Y H:i'),
            ])
            ->toArray();

        $this->dataLoaded = true;
    }

    public function searchUser(): void
    {
        if (strlen($this->userSearch) < 2) {
            $this->foundUsers = [];
            return;
        }

        // Story 7.1 — Review #8 : ILIKE n'existe pas en SQLite (CI/tests).
        // On réutilise le même pattern qu'au niveau `getHistoryEntriesProperty`.
        $likeOp = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        $this->foundUsers = EloquentUser::where('login', $likeOp, "%{$this->userSearch}%")
            ->orWhere('fullname', $likeOp, "%{$this->userSearch}%")
            ->limit(10)
            ->get()
            ->map(fn(EloquentUser $u) => [
                'login' => $u->login,
                'fullname' => $u->fullname ?? $u->login,
                'role' => $u->role,
                'roles_spatie' => $u->roles->pluck('name')->toArray(),
            ])
            ->toArray();
    }

    public function selectUser(string $login): void
    {
        $user = EloquentUser::where('login', $login)->first();
        if (!$user) {
            return;
        }

        $this->selectedUserLogin = $login;

        $permissionService = app(PermissionService::class);

        // Permissions directes
        $directPermissions = $user->getDirectPermissions()->pluck('name')->toArray();

        // Permissions via rôles
        $rolePermissions = $user->getPermissionsViaRoles()->pluck('name')->toArray();

        // Toutes les permissions effectives
        $allPermissions = $user->getAllPermissions()->pluck('name')->toArray();

        // Délégations
        $delegations = $permissionService->getUserDelegations($user)
            ->map(fn(Delegation $d) => [
                'id' => $d->id,
                'workstation_group' => $d->workstationGroup->name ?? '?',
                'permission' => $d->permission->name ?? '?',
                'is_negative' => $d->is_negative,
                'expires_at' => $d->expires_at?->format('d/m/Y H:i'),
            ])
            ->toArray();

        // Bitmask legacy
        $bitmask = $permissionService->permissionsToBitmask($user);

        // Story 7.1 — historique des 10 dernières opérations sur ce user cible.
        $userHistory = DelegationHistory::forTarget($user)
            ->with(['actor', 'workstationGroup'])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn(DelegationHistory $h) => [
                'id' => $h->id,
                'created_at' => $h->created_at?->format('d/m/Y H:i'),
                'action' => $h->action,
                'actor_login' => $h->actor?->login ?? '—',
                'workstation_group' => $h->workstationGroup?->name ?? '—',
                'permission_name' => $h->permission_name,
                'is_negative' => $h->is_negative,
            ])
            ->toArray();

        $this->selectedUserDetails = [
            'login' => $user->login,
            'fullname' => $user->fullname ?? $user->login,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'roles_spatie' => $user->roles->pluck('name')->toArray(),
            'direct_permissions' => $directPermissions,
            'role_permissions' => $rolePermissions,
            'all_permissions' => $allPermissions,
            'delegations' => $delegations,
            'history' => $userHistory,
            'bitmask' => sprintf('0x%04X', $bitmask),
            'ad_synced_at' => $user->ad_synced_at?->format('d/m/Y H:i'),
        ];

        $this->foundUsers = [];
        $this->userSearch = '';
    }

    public function clearSelectedUser(): void
    {
        $this->selectedUserLogin = null;
        $this->selectedUserDetails = null;
    }

    public function revokeDelegation(int $delegationId): void
    {
        // Story 7.1 — Review #C / #5c : defense-in-depth. Le middleware route
        // bloque déjà l'accès à la page, mais un guard explicite protège contre
        // un appel Livewire forgé côté admin compromis (log explicite + 403).
        abort_unless(\Illuminate\Support\Facades\Gate::allows('user.assign.right'), 403);

        $delegation = Delegation::find($delegationId);
        if (!$delegation) {
            return;
        }

        $permissionService = app(PermissionService::class);
        $user = $delegation->user;
        $group = $delegation->workstationGroup;
        $permName = $delegation->permission->name;
        $isNegative = (bool) $delegation->is_negative;

        // Story 7.1 — passer l'acteur explicite (auth()->user()) pour l'historique.
        $actor = auth()->user();
        $actorEloquent = $actor instanceof EloquentUser ? $actor : null;

        $deleted = $isNegative
            ? $permissionService->revokeNegativeDelegation($user, $permName, $group, $actorEloquent)
            : $permissionService->revokeDelegation($user, $permName, $group, $actorEloquent);

        if (!$deleted) {
            $this->toastError("Délégation introuvable ou déjà révoquée.");
            return;
        }

        // Toast succès (WithToasts) — AC8
        $label = $isNegative ? 'Exclusion' : 'Délégation';
        $this->toastSuccess("{$label} {$permName} révoquée sur {$group->name}");

        // Story 7.1 — Review #4 (Option B) : alerte si l'audit best-effort a échoué.
        if ($permissionService->lastAuditFailed) {
            $this->toastWarning("Délégation révoquée mais la traçabilité n'a pas été enregistrée. Contactez l'administrateur.");
        }

        // Rafraîchir les détails
        if ($this->selectedUserLogin) {
            $this->selectUser($this->selectedUserLogin);
        }

        // Rafraîchir la vue d'ensemble
        $this->dataLoaded = false;
        $this->loadData();
    }

    /**
     * Révocation groupée des délégations sélectionnées dans l'onglet
     * Délégations actives. Réutilise la même mécanique d'audit que
     * `revokeDelegation` (toast warning agrégé en cas d'échec d'audit).
     */
    public function revokeSelectedDelegations(): void
    {
        abort_unless(\Illuminate\Support\Facades\Gate::allows('user.assign.right'), 403);

        if (empty($this->selectedDelegations)) {
            return;
        }

        $service = app(PermissionService::class);
        $actor = auth()->user();
        $actorEloquent = $actor instanceof EloquentUser ? $actor : null;

        $revoked = 0;
        $failed = 0;

        foreach ($this->selectedDelegations as $id) {
            $delegation = Delegation::find((int) $id);
            if (!$delegation) {
                $failed++;
                continue;
            }

            $isNegative = (bool) $delegation->is_negative;
            $deleted = $isNegative
                ? $service->revokeNegativeDelegation($delegation->user, $delegation->permission->name, $delegation->workstationGroup, $actorEloquent)
                : $service->revokeDelegation($delegation->user, $delegation->permission->name, $delegation->workstationGroup, $actorEloquent);

            if ($deleted) {
                $revoked++;
            } else {
                $failed++;
            }
        }

        if ($revoked > 0) {
            $this->toastSuccess("{$revoked} délégation(s) révoquée(s).");
        }
        if ($failed > 0) {
            $this->toastWarning("{$failed} délégation(s) introuvable(s) ou déjà révoquée(s).");
        }

        $this->selectedDelegations = [];
        $this->dataLoaded = false;
        $this->loadData();
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage('historyPage');
    }

    /** Onglets valides (allow-list du switch). */
    private const TABS = ['profiles', 'user-lookup', 'delegations', 'history'];

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, self::TABS, true) ? $tab : 'profiles';
    }

    /**
     * Story 7.2 — recharge la liste des délégations actives quand la modale
     * partagée a appliqué une action (édition ouverte par clic ligne).
     */
    #[\Livewire\Attributes\On('delegations-changed')]
    public function onDelegationsChanged(): void
    {
        $this->selectedDelegations = [];
        $this->dataLoaded = false;
        $this->loadData();
    }

    // ========================================================================
    // Story 7.1 — Onglet Historique (AC6)
    // ========================================================================

    /**
     * Propriété computed : paginator de l'historique filtrable.
     *
     * Filtres disponibles :
     *  - `historyActionFilter` : grant / revoke / negate / expire
     *  - `historyTargetFilter` : login cible (LIKE)
     *  - `historyFromFilter` / `historyToFilter` : bornes de date (YYYY-MM-DD)
     */
    public function getHistoryEntriesProperty()
    {
        $query = DelegationHistory::query()
            ->with(['actor', 'target', 'workstationGroup']);

        if (!empty($this->historyActionFilter)) {
            $query->where('action', $this->historyActionFilter);
        }

        if (!empty($this->historyTargetFilter)) {
            $search = "%{$this->historyTargetFilter}%";
            $query->whereHas('target', function ($q) use ($search) {
                // ILIKE n'existe pas en SQLite — fallback LIKE sans accents.
                $op = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
                $q->where('login', $op, $search);
            });
        }

        if (!empty($this->historyFromFilter)) {
            try {
                $from = \Carbon\Carbon::parse($this->historyFromFilter)->startOfDay();
                $query->where('created_at', '>=', $from);
            } catch (\Exception $e) {
                // Filtre ignoré si date invalide.
            }
        }

        if (!empty($this->historyToFilter)) {
            try {
                $to = \Carbon\Carbon::parse($this->historyToFilter)->endOfDay();
                $query->where('created_at', '<=', $to);
            } catch (\Exception $e) {
                // Filtre ignoré si date invalide.
            }
        }

        return $query->orderByDesc('created_at')
            ->paginate($this->historyPerPage, ['*'], 'historyPage');
    }

    /**
     * Reset des filtres de l'onglet Historique.
     */
    public function resetHistoryFilters(): void
    {
        $this->historyActionFilter = '';
        $this->historyTargetFilter = '';
        $this->historyFromFilter = '';
        $this->historyToFilter = '';
        $this->resetPage('historyPage');
    }

    public function updatedHistoryActionFilter(): void
    {
        $this->resetPage('historyPage');
    }

    public function updatedHistoryTargetFilter(): void
    {
        $this->resetPage('historyPage');
    }

    public function updatedHistoryFromFilter(): void
    {
        $this->resetPage('historyPage');
    }

    public function updatedHistoryToFilter(): void
    {
        $this->resetPage('historyPage');
    }

    // ========================================================================
    // Story 7.2 — Onglet Profils (AC3)
    // ========================================================================

    /**
     * Recharge les DEUX sections de l'onglet Profils (Story 49.1 AC7).
     *
     * Appelée à la 1ère ouverture de l'onglet, au retour depuis les pages
     * dédiées de création/édition, et après chaque pose/changement/retrait de
     * profil porté.
     */
    public function loadProfiles(): void
    {
        $carriedIds = app(GroupRightsProfileService::class)->carriedRoleIds();

        $roles = Role::where('guard_name', 'web')
            ->withCount(['users', 'permissions'])
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        // Section principale : les GROUPES porteurs (pas les ~200 groupes).
        $this->carrierGroupsList = UserGroup::query()
            ->carryingProfile()
            ->orderBy('name')
            ->get()
            ->map(function (UserGroup $group) use ($roles) {
                $role = $roles->get((int) $group->rights_profile_id);

                return [
                    'group_id' => $group->id,
                    'group_label' => $group->display_name_or_name,
                    'group_name' => $group->name,
                    'group_type' => $group->type,
                    'profile_id' => $role?->id,
                    'profile_name' => $role?->name ?? '—',
                    'profile_label' => $role
                        ? (SambaRole::tryFrom($role->name)?->label() ?? $role->name)
                        : '—',
                    'is_seeded' => $role ? SambaRole::isSeeded($role->name) : false,
                    'permissions_count' => (int) ($role?->permissions_count ?? 0),
                    'users_count' => (int) ($role?->users_count ?? 0),
                ];
            })
            ->values()
            ->toArray();

        // Section secondaire : les profils portés par AUCUN groupe.
        $this->unattachedProfilesList = $roles
            ->reject(fn(Role $r) => in_array((int) $r->id, $carriedIds, true))
            ->map(fn(Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => SambaRole::tryFrom($r->name)?->label() ?? $r->name,
                'is_seeded' => SambaRole::isSeeded($r->name),
                'permissions_count' => (int) $r->permissions_count,
                'users_count' => (int) $r->users_count,
            ])
            ->values()
            ->toArray();

        $this->profileChoices = $roles
            ->map(fn(Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => SambaRole::tryFrom($r->name)?->label() ?? $r->name,
                'permissions_count' => (int) $r->permissions_count,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Supprime plusieurs profils sélectionnés depuis le menu actions de la page.
     *
     * Story 49.1 (AC6) — la garde « profil PORTÉ par au moins un groupe » passe
     * AVANT la garde `isSeeded` existante, et son message NOMME les groupes
     * porteurs : une suppression silencieuse retirerait des droits à tout un
     * parc. Le filet DB (`restrictOnDelete`) couvre les chemins hors UI.
     */
    public function deleteSelectedProfiles(): void
    {
        abort_unless(Gate::allows('user.assign.right'), 403);

        if (empty($this->selectedProfiles)) {
            return;
        }

        $service = app(GroupRightsProfileService::class);

        $deleted = 0;
        $skippedSeeded = 0;
        $skippedAssigned = 0;
        $carriedMessages = [];

        foreach ($this->selectedProfiles as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if (!$role) {
                continue;
            }

            $carriers = $service->carrierNames((int) $role->id);
            if (!empty($carriers)) {
                $carriedMessages[] = "« {$roleName} » — porté par : " . implode(', ', $carriers);
                continue;
            }

            if (SambaRole::isSeeded($roleName)) {
                $skippedSeeded++;
                continue;
            }
            if ($role->users()->count() > 0) {
                $skippedAssigned++;
                continue;
            }
            $role->delete();
            $deleted++;
        }

        if ($deleted > 0) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->toastSuccess("{$deleted} profil(s) supprimé(s).");
        }
        if (!empty($carriedMessages)) {
            $this->toastError(
                'Suppression refusée. ' . implode(' ; ', $carriedMessages)
                . ' — retirez d\'abord le profil de ces groupes.'
            );
        }
        if ($skippedSeeded > 0) {
            $this->toastWarning("{$skippedSeeded} profil(s) initial(aux) ignoré(s).");
        }
        if ($skippedAssigned > 0) {
            $this->toastError("{$skippedAssigned} profil(s) ignoré(s) car portés par des utilisateurs.");
        }

        $this->selectedProfiles = [];
        $this->loadProfiles();
    }

    // ========================================================================
    // Story 49.1 (AC7) — donner / changer / retirer le profil porté d'un groupe
    // ========================================================================

    /** Ouvre la modale « Donner des permissions à un groupe ». */
    public function openAssignProfileModal(): void
    {
        abort_unless(Gate::allows('user.assign.right'), 403);

        $this->resetProfileModal();
        $this->profileAssignMode = 'assign';
        $this->showProfileAssignModal = true;

        if (empty($this->profileChoices)) {
            $this->loadProfiles();
        }
    }

    /** Ouvre la même modale, groupe FIGÉ, pour changer son profil. */
    public function openChangeProfileModal(int $groupId): void
    {
        abort_unless(Gate::allows('user.assign.right'), 403);

        $group = UserGroup::find($groupId);
        if (!$group) {
            $this->toastError('Groupe introuvable.');
            return;
        }

        $this->resetProfileModal();
        $this->profileAssignMode = 'change';
        $this->profileAssignGroupId = $group->id;
        $this->profileAssignGroupLabel = $group->display_name_or_name;
        $this->profileAssignRoleId = $group->rights_profile_id === null
            ? null
            : (int) $group->rights_profile_id;
        $this->showProfileAssignModal = true;

        if (empty($this->profileChoices)) {
            $this->loadProfiles();
        }
    }

    public function closeProfileAssignModal(): void
    {
        $this->showProfileAssignModal = false;
        $this->resetProfileModal();
    }

    private function resetProfileModal(): void
    {
        $this->profileAssignMode = 'assign';
        $this->profileAssignGroupId = null;
        $this->profileAssignGroupLabel = '';
        $this->profileGroupSearch = '';
        $this->profileGroupResults = [];
        $this->profileAssignRoleId = null;
    }

    /**
     * Recherche SERVER-SIDE du groupe destinataire. Les groupes DÉJÀ porteurs
     * sont exclus : pour eux, le geste est « Changer le profil » depuis leur
     * ligne (`whereNull('rights_profile_id')`).
     */
    public function updatedProfileGroupSearch(): void
    {
        $term = trim($this->profileGroupSearch);

        if (mb_strlen($term) < 2) {
            $this->profileGroupResults = [];
            return;
        }

        // ILIKE n'existe pas en SQLite (tests hôte) — pattern projet.
        $likeOp = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        $this->profileGroupResults = UserGroup::query()
            ->whereNull('rights_profile_id')
            ->where(function ($q) use ($likeOp, $term) {
                $q->where('name', $likeOp, "%{$term}%")
                    ->orWhere('display_name', $likeOp, "%{$term}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn(UserGroup $g) => [
                'id' => $g->id,
                'label' => $g->display_name_or_name,
                'name' => $g->name,
                'type' => $g->type,
            ])
            ->toArray();
    }

    public function selectProfileGroup(int $groupId): void
    {
        $match = collect($this->profileGroupResults)->firstWhere('id', $groupId);
        if (!$match) {
            return;
        }

        $this->profileAssignGroupId = $groupId;
        $this->profileAssignGroupLabel = $match['label'];
        $this->profileGroupResults = [];
        $this->profileGroupSearch = '';
    }

    /**
     * Pose ou change le profil porté par le groupe, et re-projette ses membres
     * DANS LE MÊME GESTE (AC4, piège du dernier porteur couvert par le service).
     */
    public function submitProfileAssignment(): void
    {
        abort_unless(Gate::allows('user.assign.right'), 403);

        if ($this->profileAssignGroupId === null) {
            $this->toastError('Sélectionnez un groupe.');
            return;
        }
        if ($this->profileAssignRoleId === null) {
            $this->toastError('Sélectionnez un profil de droits.');
            return;
        }

        $group = UserGroup::find($this->profileAssignGroupId);
        if (!$group) {
            $this->toastError('Groupe introuvable.');
            return;
        }

        $actor = auth()->user();

        try {
            $stats = app(GroupRightsProfileService::class)->setProfile(
                $group,
                (int) $this->profileAssignRoleId,
                $actor instanceof EloquentUser ? $actor : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
            return;
        }

        $choice = collect($this->profileChoices)->firstWhere('id', (int) $this->profileAssignRoleId);
        $profileName = $choice['name'] ?? (Role::find($this->profileAssignRoleId)?->name ?? '?');
        $groupLabel = $group->display_name_or_name;

        $this->closeProfileAssignModal();
        $this->loadProfiles();

        if (!$stats['changed']) {
            $this->toastInfo("Le groupe « {$groupLabel} » portait déjà le profil « {$profileName} ».");
            return;
        }

        $this->toastSuccess(
            "Profil « {$profileName} » donné au groupe « {$groupLabel} » — "
            . "{$stats['users']} utilisateur(s) re-projeté(s)."
        );
    }

    public function openRemoveProfileModal(int $groupId): void
    {
        abort_unless(Gate::allows('user.assign.right'), 403);

        $group = UserGroup::with('rightsProfile')->find($groupId);
        if (!$group || !$group->carriesProfile()) {
            $this->toastError('Ce groupe ne porte aucun profil.');
            return;
        }

        $this->profileRemoveGroupId = $group->id;
        $this->profileRemoveGroupLabel = $group->display_name_or_name;
        $this->profileRemoveProfileLabel = $group->rightsProfile?->name ?? '?';
        $this->showProfileRemoveModal = true;
    }

    public function closeProfileRemoveModal(): void
    {
        $this->showProfileRemoveModal = false;
        $this->profileRemoveGroupId = null;
        $this->profileRemoveGroupLabel = '';
        $this->profileRemoveProfileLabel = '';
    }

    /** Retrait du profil porté → re-projection immédiate des membres (AC4). */
    public function confirmRemoveProfile(): void
    {
        abort_unless(Gate::allows('user.assign.right'), 403);

        if ($this->profileRemoveGroupId === null) {
            return;
        }

        $group = UserGroup::find($this->profileRemoveGroupId);
        if (!$group) {
            $this->closeProfileRemoveModal();
            $this->toastError('Groupe introuvable.');
            return;
        }

        $actor = auth()->user();
        $groupLabel = $group->display_name_or_name;
        $profileLabel = $this->profileRemoveProfileLabel;

        $stats = app(GroupRightsProfileService::class)->setProfile(
            $group,
            null,
            $actor instanceof EloquentUser ? $actor : null,
        );

        $this->closeProfileRemoveModal();
        $this->loadProfiles();

        $this->toastSuccess(
            "Profil « {$profileLabel} » retiré du groupe « {$groupLabel} » — "
            . "{$stats['users']} utilisateur(s) re-projeté(s)."
        );
    }

};
?>

<x-organisms.page title="Gestion des droits" :scrollable="false"
    icon="fa-solid fa-shield-halved"
    description="Gérez les rôles, permissions et délégations des utilisateurs">

    @if (in_array($activeTab, ['profiles', 'delegations'], true))
        <x-slot:actions>
            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-bolt mr-1"></i>
                    Actions
                    <i class="fa-solid fa-chevron-down text-xs ml-1"></i>
                </label>
                <ul tabindex="0"
                    class="dropdown-content menu p-2 shadow bg-base-100 rounded-box w-72 border border-base-300 z-[1]">
                    @if ($activeTab === 'profiles')
                        <li>
                            <button type="button" wire:click="openAssignProfileModal">
                                <i class="fa-solid fa-user-plus"></i>
                                Donner des permissions à un groupe
                            </button>
                        </li>
                        <li>
                            <a href="{{ route('app.rights-management.profiles.create') }}">
                                <i class="fa-solid fa-plus"></i>
                                Nouveau profil
                            </a>
                        </li>
                        <li class="{{ empty($selectedProfiles) ? 'menu-disabled' : '' }}">
                            <button type="button"
                                class="text-error"
                                @disabled(empty($selectedProfiles))
                                wire:click="deleteSelectedProfiles"
                                wire:confirm="Supprimer les profils sélectionnés ? Cette action est irréversible.">
                                <i class="fa-solid fa-trash-can"></i>
                                Supprimer la sélection
                                @if (!empty($selectedProfiles))
                                    <span class="badge badge-error badge-xs ml-1">{{ count($selectedProfiles) }}</span>
                                @endif
                            </button>
                        </li>
                    @elseif ($activeTab === 'delegations')
                        <li class="{{ empty($selectedDelegations) ? 'menu-disabled' : '' }}">
                            <button type="button"
                                class="text-error"
                                @disabled(empty($selectedDelegations))
                                wire:click="revokeSelectedDelegations"
                                wire:confirm="Révoquer les délégations sélectionnées ?">
                                <i class="fa-solid fa-trash-can"></i>
                                Révoquer la sélection
                                @if (!empty($selectedDelegations))
                                    <span class="badge badge-error badge-xs ml-1">{{ count($selectedDelegations) }}</span>
                                @endif
                            </button>
                        </li>
                    @endif
                </ul>
            </div>
        </x-slot:actions>
    @endif

    <div wire:init="loadData" class="flex flex-col flex-1 min-h-0">

        {{-- Navigation par onglets --}}
        @php
            $rightsTabs = [
                'profiles' => ['label' => 'Profils', 'icon' => 'fa-solid fa-id-card-clip'],
                'user-lookup' => ['label' => "Droits d'un utilisateur", 'icon' => 'fa-solid fa-user-shield'],
                'delegations' => ['label' => 'Délégations actives', 'icon' => 'fa-solid fa-building'],
                'history' => ['label' => 'Historique', 'icon' => 'fa-solid fa-clock-rotate-left'],
            ];
        @endphp
        <x-molecules.tabs :tabs="$rightsTabs" :active="$activeTab" class="flex-shrink-0 mb-4" />

        {{-- ============================================================ --}}
        {{-- ONGLET DROITS D'UN UTILISATEUR --}}
        {{-- ============================================================ --}}
        @if ($activeTab === 'user-lookup')
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    {{-- Recherche --}}
                    <div class="flex gap-3 items-end mb-4">
                        <div class="flex-1">
                            <label class="text-sm font-medium mb-1 block">Rechercher un utilisateur</label>
                            <label class="input w-full">
                                <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                                <input type="text" wire:model.live.debounce.300ms="userSearch"
                                    wire:keyup="searchUser"
                                    placeholder="Login ou nom..." class="grow" />
                            </label>
                        </div>
                        @if ($selectedUserLogin)
                            <button class="btn btn-ghost btn-sm" wire:click="clearSelectedUser">
                                <i class="fa-solid fa-xmark"></i> Effacer
                            </button>
                        @endif
                    </div>

                    {{-- Résultats de recherche --}}
                    @if (!empty($foundUsers))
                        <div class="border rounded-lg divide-y divide-base-200 mb-4 max-h-60 overflow-y-auto">
                            @foreach ($foundUsers as $u)
                                <button type="button"
                                    class="w-full flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 text-left transition-colors"
                                    wire:click="selectUser('{{ $u['login'] }}')">
                                    <div class="flex-1">
                                        <div class="font-medium text-sm">{{ $u['fullname'] }}</div>
                                        <div class="text-xs text-base-content/50 font-mono">{{ $u['login'] }}</div>
                                    </div>
                                    <div class="flex gap-1">
                                        @foreach ($u['roles_spatie'] as $r)
                                            <span class="badge badge-primary badge-xs">{{ $r }}</span>
                                        @endforeach
                                        @if (empty($u['roles_spatie']))
                                            <span class="badge badge-ghost badge-xs">{{ $u['role'] }}</span>
                                        @endif
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    {{-- Détails de l'utilisateur sélectionné --}}
                    @if ($selectedUserDetails)
                        <div class="space-y-4">
                            {{-- En-tête utilisateur --}}
                            <div class="flex items-center gap-4 p-4 bg-base-200/50 rounded-lg">
                                <div class="flex-1">
                                    <h3 class="text-lg font-bold">{{ $selectedUserDetails['fullname'] }}</h3>
                                    <div class="text-sm text-base-content/60 font-mono">{{ $selectedUserDetails['login'] }}</div>
                                </div>
                                <div class="text-right text-sm">
                                    <div>Rôle AD : <span class="badge badge-ghost badge-sm">{{ $selectedUserDetails['role'] }}</span></div>
                                    <div>Bitmask : <span class="font-mono text-xs">{{ $selectedUserDetails['bitmask'] }}</span></div>
                                    @if ($selectedUserDetails['ad_synced_at'])
                                        <div class="text-xs text-base-content/40">Sync AD : {{ $selectedUserDetails['ad_synced_at'] }}</div>
                                    @endif
                                </div>
                            </div>

                            {{-- Rôles Spatie --}}
                            <div>
                                <h4 class="text-sm font-bold mb-2">
                                    <i class="fa-solid fa-user-tag mr-1 text-primary"></i> Rôles
                                </h4>
                                <div class="flex flex-wrap gap-2">
                                    @forelse ($selectedUserDetails['roles_spatie'] as $r)
                                        <span class="badge badge-primary">{{ $r }}</span>
                                    @empty
                                        <span class="text-sm text-base-content/40">Aucun rôle assigné</span>
                                    @endforelse
                                </div>
                            </div>

                            {{-- Permissions --}}
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <h4 class="text-sm font-bold mb-2">
                                        <i class="fa-solid fa-key mr-1 text-secondary"></i> Permissions directes
                                    </h4>
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($selectedUserDetails['direct_permissions'] as $p)
                                            <span class="badge badge-secondary badge-sm">{{ $p }}</span>
                                        @empty
                                            <span class="text-xs text-base-content/40">Aucune</span>
                                        @endforelse
                                    </div>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold mb-2">
                                        <i class="fa-solid fa-key mr-1 text-accent"></i> Permissions via rôles
                                    </h4>
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($selectedUserDetails['role_permissions'] as $p)
                                            <span class="badge badge-accent badge-sm badge-outline">{{ $p }}</span>
                                        @empty
                                            <span class="text-xs text-base-content/40">Aucune</span>
                                        @endforelse
                                    </div>
                                </div>
                            </div>

                            {{-- Délégations --}}
                            <div>
                                <h4 class="text-sm font-bold mb-2">
                                    <i class="fa-solid fa-building mr-1 text-warning"></i> Délégations
                                </h4>
                                @if (!empty($selectedUserDetails['delegations']))
                                    <div class="overflow-x-auto">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Salle</th>
                                                    <th>Permission</th>
                                                    <th>Type</th>
                                                    <th>Expiration</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($selectedUserDetails['delegations'] as $d)
                                                    <tr>
                                                        <td class="font-medium">{{ $d['workstation_group'] }}</td>
                                                        <td><span class="font-mono text-xs">{{ $d['permission'] }}</span></td>
                                                        <td>
                                                            @if ($d['is_negative'])
                                                                <span class="badge badge-error badge-xs">Exclusion</span>
                                                            @else
                                                                <span class="badge badge-success badge-xs">Accordée</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-xs">{{ $d['expires_at'] ?? 'Permanente' }}</td>
                                                        <td>
                                                            <button class="btn btn-ghost btn-xs text-error"
                                                                wire:click="revokeDelegation({{ $d['id'] }})"
                                                                wire:confirm="Révoquer cette délégation ?">
                                                                <i class="fa-solid fa-trash-can"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <span class="text-sm text-base-content/40">Aucune délégation active</span>
                                @endif
                            </div>

                            {{-- Story 7.1 — Historique des 10 dernières opérations sur ce user cible (AC6) --}}
                            <div>
                                <h4 class="text-sm font-bold mb-2">
                                    <i class="fa-solid fa-clock-rotate-left mr-1 text-info"></i>
                                    Historique des délégations
                                    <span class="text-xs text-base-content/40 font-normal">
                                        (10 dernières opérations)
                                    </span>
                                </h4>
                                @if (!empty($selectedUserDetails['history']))
                                    <div class="overflow-x-auto">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Acteur</th>
                                                    <th>Action</th>
                                                    <th>Salle</th>
                                                    <th>Permission</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($selectedUserDetails['history'] as $h)
                                                    <tr>
                                                        <td class="text-xs text-base-content/70">{{ $h['created_at'] }}</td>
                                                        <td class="text-xs font-mono">{{ $h['actor_login'] }}</td>
                                                        <td>
                                                            @php
                                                                $badgeClass = match ($h['action']) {
                                                                    'grant' => 'badge-success',
                                                                    'revoke' => 'badge-warning',
                                                                    'negate' => 'badge-error',
                                                                    'expire' => 'badge-ghost',
                                                                    default => 'badge-ghost',
                                                                };
                                                            @endphp
                                                            <span class="badge {{ $badgeClass }} badge-xs">{{ $h['action'] }}</span>
                                                            @if ($h['is_negative'] && $h['action'] !== 'negate')
                                                                <span class="badge badge-error badge-xs ml-1">neg</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-xs">{{ $h['workstation_group'] }}</td>
                                                        <td><span class="font-mono text-xs">{{ $h['permission_name'] }}</span></td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <span class="text-sm text-base-content/40">Aucun historique pour cet utilisateur</span>
                                @endif
                            </div>
                        </div>
                    @elseif (!$selectedUserLogin && empty($foundUsers))
                        <div class="text-center py-8">
                            <div class="text-4xl mb-4 opacity-20"><i class="fa-solid fa-user-shield"></i></div>
                            <p class="text-base-content/60">Recherchez un utilisateur pour voir ses droits détaillés.</p>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- ONGLET DÉLÉGATIONS ACTIVES --}}
        {{-- ============================================================ --}}
        @if ($activeTab === 'delegations')
            @if (!$dataLoaded)
                <div class="card flex justify-center items-center py-16">
                    <span class="loading loading-spinner loading-lg text-primary mb-4"></span>
                    <p class="text-base-content/60">Chargement...</p>
                </div>
            @elseif (empty($delegationsOverview))
                <div class="card bg-base-100 border border-base-300 shadow-sm">
                    <div class="card-body text-center py-12">
                        <div class="text-4xl mb-4 opacity-20"><i class="fa-solid fa-building"></i></div>
                        <h3 class="text-lg font-semibold mb-2">Aucune délégation active</h3>
                        <p class="text-base-content/60 max-w-md mx-auto">
                            Les délégations permettent d'accorder des droits limités à une salle physique.
                            Utilisez la page <strong>Utilisateurs</strong> pour sélectionner des utilisateurs et leur accorder des délégations.
                        </p>
                    </div>
                </div>
            @else
                <div class="card bg-base-100 shadow-sm flex-1 min-h-0 flex flex-col overflow-hidden">
                    <x-organisms.data-table
                        colgroup="<colgroup><col style='width: 3rem'><col style='width: 22%'><col style='width: 16%'><col style='width: 16%'><col style='width: 8rem'><col style='width: 9rem'><col style='width: 9rem'></colgroup>">
                        <x-slot:header>
                            <th>
                                <label>
                                    <input type="checkbox"
                                        class="checkbox checkbox-sm"
                                        @checked(count($selectedDelegations) === count($delegationsOverview) && count($delegationsOverview) > 0)
                                        onclick="
                                            const checked = this.checked;
                                            document.querySelectorAll('.delegation-row-checkbox').forEach(cb => {
                                                if (cb.checked !== checked) cb.click();
                                            });
                                        " />
                                </label>
                            </th>
                            <th>Utilisateur</th>
                            <th>Salle</th>
                            <th>Permission</th>
                            <th>Type</th>
                            <th>Expiration</th>
                            <th>Créée le</th>
                        </x-slot:header>

                        @foreach ($delegationsOverview as $d)
                            <tr class="cursor-pointer"
                                onclick="
                                    if (event.target.closest('.checkbox-cell')) return;
                                    Livewire.dispatch('open-delegation-modal', {
                                        users: ['{{ $d['user_login'] }}'],
                                        workstationGroupId: {{ $d['workstation_group_id'] }},
                                        permission: '{{ $d['permission'] }}',
                                        expiresAt: {{ $d['expires_at_iso'] ? "'".$d['expires_at_iso']."'" : 'null' }}
                                    });
                                ">
                                <td class="checkbox-cell p-0">
                                    <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                        <input type="checkbox"
                                            class="checkbox checkbox-sm delegation-row-checkbox"
                                            wire:model.live="selectedDelegations"
                                            value="{{ $d['id'] }}" />
                                    </label>
                                </td>
                                <td>
                                    <div class="font-medium text-sm">{{ $d['user_fullname'] }}</div>
                                    <div class="text-xs text-base-content/50 font-mono">{{ $d['user_login'] }}</div>
                                </td>
                                <td class="font-medium">{{ $d['workstation_group'] }}</td>
                                <td><span class="font-mono text-xs">{{ $d['permission'] }}</span></td>
                                <td>
                                    @if ($d['is_negative'])
                                        <span class="badge badge-error badge-xs">Exclusion</span>
                                    @else
                                        <span class="badge badge-success badge-xs">Accordée</span>
                                    @endif
                                </td>
                                <td class="text-xs">{{ $d['expires_at'] ?? 'Permanente' }}</td>
                                <td class="text-xs text-base-content/50">{{ $d['created_at'] }}</td>
                            </tr>
                        @endforeach
                    </x-organisms.data-table>
                </div>
            @endif
        @endif

        {{-- ============================================================ --}}
        {{-- ONGLET HISTORIQUE — Story 7.1 (AC6) --}}
        {{-- ============================================================ --}}
        @if ($activeTab === 'history')
            @include('pages.rights-management._partials.history-tab')
        @endif

        {{-- ============================================================ --}}
        {{-- ONGLET PROFILS — Story 7.2 (AC3) --}}
        {{-- ============================================================ --}}
        @if ($activeTab === 'profiles')
            <div wire:init="loadProfiles" class="flex flex-col flex-1 min-h-0">
                @include('pages.rights-management._partials.profiles-tab')
            </div>
        @endif

    </div>

    {{-- ================================================================ --}}
    {{-- Story 49.1 (AC7) — modale « Donner des permissions à un groupe » --}}
    {{-- (mode `assign`) / « Changer le profil » (mode `change`, groupe figé) --}}
    {{-- ================================================================ --}}
    <x-molecules.modal wire:model="showProfileAssignModal"
        :title="$profileAssignMode === 'change' ? 'Changer le profil de droits du groupe' : 'Donner des permissions à un groupe'"
        subtitle="L'appartenance au groupe attribue automatiquement le profil à ses membres."
        icon="fa-users-rectangle text-primary"
        size="max-w-2xl"
        height="h-auto max-h-[85vh]"
        closeMethod="closeProfileAssignModal">

        <x-molecules.modal.section title="Groupe">
            @if ($profileAssignMode === 'change' || $profileAssignGroupId !== null)
                <div class="flex items-center justify-between gap-3 p-3 rounded-lg bg-base-200/60">
                    <div class="min-w-0">
                        <div class="font-medium truncate">{{ $profileAssignGroupLabel }}</div>
                        <div class="text-xs text-base-content/50">Groupe destinataire</div>
                    </div>
                    @if ($profileAssignMode !== 'change')
                        <button type="button" class="btn btn-ghost btn-xs"
                            wire:click="$set('profileAssignGroupId', null)">
                            <i class="fa-solid fa-xmark"></i> Changer
                        </button>
                    @endif
                </div>
            @else
                <div class="flex flex-col w-full gap-1">
                    <label class="text-sm font-medium" for="profile-group-search">
                        Rechercher un groupe <span class="text-error">*</span>
                    </label>
                    <label class="input w-full">
                        <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                        <input id="profile-group-search" type="text" class="grow"
                            placeholder="Nom du groupe (2 caractères minimum)…"
                            wire:model.live.debounce.300ms="profileGroupSearch" />
                    </label>

                    @if (!empty($profileGroupResults))
                        <div class="border border-base-300 rounded-lg divide-y divide-base-200 mt-2 max-h-60 overflow-y-auto">
                            @foreach ($profileGroupResults as $result)
                                <button type="button"
                                    wire:key="group-result-{{ $result['id'] }}"
                                    class="w-full flex items-center gap-3 px-3 py-2 hover:bg-base-200/50 text-left transition-colors"
                                    wire:click="selectProfileGroup({{ $result['id'] }})">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium text-sm truncate">{{ $result['label'] }}</div>
                                        <div class="text-xs text-base-content/50 font-mono truncate">{{ $result['name'] }}</div>
                                    </div>
                                    <span class="badge badge-ghost badge-xs">{{ $result['type'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    @elseif (mb_strlen(trim($profileGroupSearch)) >= 2)
                        <p class="text-xs text-base-content/50 mt-2">
                            Aucun groupe sans profil ne correspond. Un groupe déjà porteur se modifie
                            depuis son action « Changer ».
                        </p>
                    @endif
                </div>
            @endif
        </x-molecules.modal.section>

        <x-molecules.modal.section title="Profil de droits">
            <div class="flex flex-col w-full gap-1">
                <label class="text-sm font-medium" for="profile-assign-role">
                    Profil attribué aux membres <span class="text-error">*</span>
                </label>
                <select id="profile-assign-role" class="select w-full" wire:model.live="profileAssignRoleId">
                    <option value="">— Choisir un profil —</option>
                    @foreach ($profileChoices as $choice)
                        <option value="{{ $choice['id'] }}">
                            {{ $choice['label'] }} ({{ $choice['permissions_count'] }} permission(s))
                        </option>
                    @endforeach
                </select>
            </div>
        </x-molecules.modal.section>

        <x-slot:footerNote>
            Les membres du groupe seront re-projetés immédiatement. Les droits attribués
            individuellement (délégations) ne sont pas modifiés.
        </x-slot:footerNote>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeProfileAssignModal">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="submitProfileAssignment"
                @disabled($profileAssignGroupId === null || $profileAssignRoleId === null)>
                <i class="fa-solid fa-check mr-1"></i>
                {{ $profileAssignMode === 'change' ? 'Changer le profil' : 'Donner les permissions' }}
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- Story 49.1 (AC7) — confirmation de RETRAIT du profil porté. --}}
    <x-molecules.modal wire:model="showProfileRemoveModal"
        title="Retirer le profil de droits du groupe"
        icon="fa-link-slash text-error"
        size="max-w-xl"
        height="h-auto max-h-[85vh]"
        closeMethod="closeProfileRemoveModal">

        <x-molecules.modal.section>
            <p class="text-sm">
                Le profil <strong>{{ $profileRemoveProfileLabel }}</strong> ne sera plus porté par
                le groupe <strong>{{ $profileRemoveGroupLabel }}</strong>.
            </p>
            <div class="alert alert-warning mt-3 text-sm">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>
                    Le profil sera retiré de tous les membres du groupe. Les droits attribués
                    individuellement (délégations) restent intacts.
                </span>
            </div>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeProfileRemoveModal">Annuler</button>
            <button type="button" class="btn btn-error" wire:click="confirmRemoveProfile">
                <i class="fa-solid fa-link-slash mr-1"></i>
                Retirer le profil
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- Story 7.2 — modale délégation partagée avec /app/users (clic ligne sur
         le tableau Délégations actives ouvre cette modale en mode édition). --}}
    <livewire:pages::users._partials.delegation-modal />
</x-organisms.page>
