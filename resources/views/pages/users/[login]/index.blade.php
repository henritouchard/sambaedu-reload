<?php
use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use App\Services\UserService;
use App\Services\AuthenticationService;
use App\Services\AdDataTransformer;
use App\Services\PermissionService;
use App\Types\User;
use App\Repositories\GroupRepository;
use App\Repositories\UserRepository;
use App\Services\UserGroupService;
use App\Models\User as SqlUserModel;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\Delegation;
use Illuminate\Support\Facades\Gate;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use App\Components\Traits\WithToasts;

new #[Title('Profil utilisateur - Instance SE4FS')] class extends Component {
    use WithToasts;
    public ?User $user = null;
    #[Locked]
    public ?SqlUserModel $sqlUserModel = null;
    public bool $accountDisabled = false;
    public bool $homeExists = true;
    public bool $isOwnProfile = false;
    public bool $wallpaperSectionVisible = false;
    /** Mot de passe fraîchement généré (lu depuis session flash après réinit en mode display). */
    private ?string $resetPasswordValue = null;
    public ?array $localAdminInfo = null;
    public array $listCurrentGroups = [];
    public array $listCurrentRights = [];

    // Story 7.x — Permissions Spatie + délégations (remplace le bitmask legacy
    // pour la card "Permissions" de la page profil utilisateur).
    public array $spatieRoles = [];
    public array $directPermissions = [];
    public array $rolePermissions = [];
    public array $delegations = [];

    private UserService $userService;
    private AuthenticationService $authService;
    private AdDataTransformer $adTransformer;
    private GroupRepository $groupRepository;
    private UserRepository $userRepository;
    private UserGroupService $userGroupService;

    public function boot(UserService $userService, AuthenticationService $authService, AdDataTransformer $adTransformer, GroupRepository $groupRepository, UserRepository $userRepository, UserGroupService $userGroupService)
    {
        $this->userService = $userService;
        $this->authService = $authService;
        $this->adTransformer = $adTransformer;
        $this->groupRepository = $groupRepository;
        $this->userRepository = $userRepository;
        $this->userGroupService = $userGroupService;
    }

    public function mount(string $login): void
    {
        $this->user = $this->userService->getByLoginFromSql($login);

        if (!$this->user) {
            abort(404);
        }

        $this->sqlUserModel = SqlUserModel::query()->where('login', $this->user->login)->first();
        if (!$this->sqlUserModel) {
            abort(404);
        }

        // Story 7.2 — scoping classe : Prof/EleveAdmin ne consulte que ses élèves
        // (rôles globaux bypass, sinon match Equipe_X/PP_X ↔ Classe_X).
        Gate::authorize('view-user', $this->sqlUserModel);

        // Vérifier si c'est le profil de l'utilisateur connecté
        $currentLogin = $this->authService->getCurrentUser();
        $this->isOwnProfile = $currentLogin && $currentLogin === $this->user->login;

        // Mot de passe affiché après création
        if (session('created_password')) {
            $this->resetPasswordValue = session('created_password');
        }

        // Statut du compte
        $this->accountDisabled = $this->user->isDisabled();

        // Fond d'écran : visible si un wallpaper utilisateur est configuré
        if (config('wallpapers.allow_per_user', true)) {
            $sqlUser = SqlUserModel::query()->where('login', $this->user->login)->first();
            $this->wallpaperSectionVisible = $sqlUser && Wallpaper::query()
                ->where('owner_type', SqlUserModel::class)
                ->where('owner_id', $sqlUser->id)
                ->where('type', Wallpaper::TYPE_WALLPAPER)
                ->exists();
        }

        // Groupes et droits (legacy — toujours utilisé pour l'en-tête)
        $this->listCurrentGroups = $this->user->groups;
        $this->listCurrentRights = $this->user->rights;

        // Story 7.x — charger l'état Spatie pour la card Permissions.
        $this->loadSpatieState();
    }

    /**
     * Recharge l'état Spatie + délégations affiché dans la card Permissions.
     * Réagit aux events dispatchés par les drawers (rights-drawer pour rôles
     * et permissions, delegation-modal pour délégations scopées).
     */
    public function loadSpatieState(): void
    {
        if (!$this->user) {
            return;
        }

        $sqlUser = SqlUserModel::query()->where('login', $this->user->login)->first();
        if (!$sqlUser) {
            $this->spatieRoles = [];
            $this->directPermissions = [];
            $this->rolePermissions = [];
            $this->delegations = [];
            return;
        }

        $this->spatieRoles = $sqlUser->roles
            ->map(fn($r) => ['id' => $r->id, 'name' => $r->name])
            ->toArray();
        $this->directPermissions = $sqlUser->getDirectPermissions()->pluck('name')->toArray();
        $this->rolePermissions = $sqlUser->getPermissionsViaRoles()->pluck('name')->toArray();

        $this->delegations = app(PermissionService::class)
            ->getUserDelegations($sqlUser)
            ->map(fn(Delegation $d) => [
                'id' => $d->id,
                'workstation_group' => $d->workstationGroup->name ?? '?',
                'workstation_group_id' => $d->workstation_group_id,
                'permission' => $d->permission->name ?? '?',
                'is_negative' => (bool) $d->is_negative,
                'expires_at' => $d->expires_at?->format('d/m/Y H:i'),
                'expires_at_iso' => $d->expires_at?->toIso8601String(),
            ])
            ->toArray();
    }

    #[On('delegations-changed')]
    public function onDelegationsChanged(): void
    {
        $this->loadSpatieState();
    }

    #[On('rights-applied')]
    public function onRightsApplied(): void
    {
        $this->loadSpatieState();
    }

    public function removeFromGroup(string $group): void
    {
        if (!Gate::allows('update-user')) {
            ToastMagic::error('Vous n\'avez pas les droits pour cette action.');
            return;
        }

        $groupCn = $group;

        $ldapUser = $this->userRepository->findLdapModelByLogin($this->user->login);
        if (!$ldapUser) {
            ToastMagic::error('Utilisateur introuvable dans l\'annuaire.');
            return;
        }

        $this->groupRepository->removeMember($groupCn, $ldapUser->getDn());
        $this->userRepository->invalidateCache($this->user->login);

        // Sync pivot SQL
        $sqlUser = SqlUserModel::query()->where('login', $this->user->login)->first();
        if ($sqlUser) {
            $userGroup = UserGroup::query()->where('name', $groupCn)->first();
            if ($userGroup) {
                $sqlUser->userGroups()->detach($userGroup->id);
            }
        }

        $this->listCurrentGroups = array_values(array_filter(
            $this->listCurrentGroups,
            fn($g) => $g !== $group
        ));

        ToastMagic::success("Retiré du groupe « {$groupCn} ».");
    }

    public function syncGroupsFromAd(): void
    {
        if (!Gate::allows('update-user')) {
            ToastMagic::error('Vous n\'avez pas les droits pour cette action.');
            return;
        }

        $ldapUser = $this->userRepository->findLdapModelByLogin($this->user->login);
        if (!$ldapUser) {
            ToastMagic::error('Utilisateur introuvable dans l\'annuaire.');
            return;
        }

        // Lire les groupes LDAP actuels du user
        $memberOf = $ldapUser->getAttribute('memberof') ?? [];
        $adGroupCns = [];
        foreach ($memberOf as $dn) {
            if (preg_match('/^CN=([^,]+),/i', $dn, $m)) {
                $adGroupCns[] = $m[1];
            }
        }

        // Synchroniser le pivot avec les UserGroups connus en SQL
        $sqlUser = SqlUserModel::query()->where('login', $this->user->login)->first();
        if ($sqlUser) {
            $matchingGroupIds = UserGroup::query()
                ->whereIn('name', $adGroupCns)
                ->pluck('id')
                ->all();
            $sqlUser->userGroups()->sync($matchingGroupIds);
        }

        // Rafraîchir l'affichage
        $this->listCurrentGroups = $sqlUser
            ? $sqlUser->userGroups()->pluck('name')->all()
            : [];

        ToastMagic::success('Groupes synchronisés depuis l\'AD.');
    }

    public function showWallpaperSection(): void
    {
        $this->wallpaperSectionVisible = true;
        $this->js("setTimeout(() => document.getElementById('wallpaper-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150)");
    }

    public function getPasswordForDisplay(): ?string
    {
        return $this->resetPasswordValue;
    }

    public function disableUser(): void
    {
        if (!$this->user) {
            $this->toastError('Utilisateur introuvable.');
            return;
        }

        if (!Gate::allows('delete-user')) {
            $this->toastAccessDenied();
            return;
        }

        $result = $this->userService->disableUser($this->user->login);

        if ($result['success']) {
            $this->toastSuccess($result['message']);
            $this->redirect(route('app.user.show', $this->user->login), navigate: true);
            return;
        }

        $this->toastError($result['message']);
    }

    public function enableUser(): void
    {
        if (!$this->user) {
            $this->toastError('Utilisateur introuvable.');
            return;
        }

        if (!Gate::allows('delete-user')) {
            $this->toastAccessDenied();
            return;
        }

        $result = $this->userService->enableUser($this->user->login);

        if ($result['success']) {
            $this->toastSuccess($result['message']);
            $this->redirect(route('app.user.show', $this->user->login), navigate: true);
            return;
        }

        $this->toastError($result['message']);
    }

    public function deleteUserPermanently(): void
    {
        if (!$this->user) {
            $this->toastError('Utilisateur introuvable.');
            return;
        }

        if (!Gate::allows('delete-user')) {
            $this->toastAccessDenied();
            return;
        }

        $result = $this->userService->deleteUserPermanently($this->user->login);

        if ($result['success']) {
            $this->toastSuccess($result['message']);
            $this->redirect(route('app.users'), navigate: true);
            return;
        }

        $this->toastError($result['message']);
    }
};

?>

<x-organisms.page :backUrl="route('app.users')" title="Utilisateur" backText="Retour à la liste">

    <!-- Composant Livewire de gestion des groupes -->
    <livewire:components::organisms.groups-drawer />
    <!-- Story 7.x — drawer Spatie (rôles + permissions globales) + modale délégations
         remplace l'ancien rights-drawer LDAP/bitmask. -->
    <livewire:pages::users._partials.rights-drawer />
    <livewire:pages::users._partials.delegation-modal />
    <!-- Composant Livewire de réinitialisation de mdp avec export (story 2.6) -->
    <livewire:components::organisms.password-reset-modal />

    <x-slot:actions>
        <!-- Actions principales -->
        <div class="flex flex-col gap-3 flex-shrink-0">
            @php
                // Story 7.2 — scoping classe : un Prof n'a pas `user.modify` mais
                // doit voir le menu pour réinitialiser le mdp de ses propres élèves.
                // On ouvre le dropdown dès qu'au moins une action est autorisée.
                $canShowActions = $user->login !== 'Administrator' && (
                    Gate::allows('update-user')
                    || Gate::allows('delete-user')
                    || Gate::allows('resetPassword-user', $sqlUserModel)
                );
            @endphp
            @if ($canShowActions)
                    <div class="dropdown dropdown-left">
                        <label tabindex="0" class="btn btn-primary gap-2 min-w-32">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                </path>
                            </svg>
                            Actions
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7">
                                </path>
                            </svg>
                        </label>
                        <ul tabindex="0"
                            class="dropdown-content menu p-2 shadow-lg bg-base-100 rounded-box w-64 border border-base-200">
                            @can('resetPassword-user', $sqlUserModel)
                                <li>
                                    <button type="button" class="flex items-center gap-3 w-full"
                                        @click="Livewire.dispatch('open-password-reset-modal', { users: ['{{ $user->login }}'], groups: [] }); document.activeElement.blur();">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z">
                                            </path>
                                        </svg>
                                        <div class="flex flex-col items-start">
                                            <span class="font-medium">Réinitialiser le mot de passe</span>
                                        </div>
                                    </button>
                                </li>
                            @endcan
                            <li>
                                <button type="button" class="flex items-center gap-3 w-full"
                                    @click="Livewire.dispatch('open-groups-drawer', { users: ['{{ $user->login }}'] }); document.activeElement.blur();">
                                    <i class="fas fa-users mr-1"></i>
                                    <div class="flex flex-col items-start">
                                        <span class="font-medium">Gérer les groupes</span>
                                    </div>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="flex items-center gap-3 w-full"
                                    @click="Livewire.dispatch('open-rights-drawer', { users: ['{{ $user->login }}'] }); document.activeElement.blur();">
                                    <i class="fa-solid fa-shield-halved w-4 h-4 flex items-center justify-center"></i>
                                    <div class="flex flex-col items-start">
                                        <span class="font-medium">Rôles & permissions</span>
                                        <span class="text-xs opacity-70">Assigner un profil ou des permissions globales</span>
                                    </div>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="flex items-center gap-3 w-full"
                                    @click="Livewire.dispatch('open-delegation-modal', { users: ['{{ $user->login }}'] }); document.activeElement.blur();">
                                    <i class="fa-solid fa-building w-4 h-4 flex items-center justify-center"></i>
                                    <div class="flex flex-col items-start">
                                        <span class="font-medium">Délégation sur une salle</span>
                                        <span class="text-xs opacity-70">Accorder ou exclure un droit scopé</span>
                                    </div>
                                </button>
                            </li>
                            @can('wallpaper.manage')
                                @if (!$wallpaperSectionVisible && config('wallpapers.allow_per_user', true))
                                <li>
                                    <button type="button" class="flex items-center gap-3 w-full"
                                        wire:click="showWallpaperSection" @click="document.activeElement.blur()">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z">
                                            </path>
                                        </svg>
                                        <div class="flex flex-col items-start">
                                            <span class="font-medium">Fond d'écran</span>
                                            <span class="text-xs opacity-70">Configurer un fond personnalisé</span>
                                        </div>
                                    </button>
                                </li>
                                @endif
                            @endcan
                            <li>
                                <button type="button" class="flex items-center gap-3 w-full"
                                    wire:click="syncGroupsFromAd"
                                    wire:confirm="Synchroniser les groupes de cet utilisateur depuis l'AD ?">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                        </path>
                                    </svg>
                                    <div class="flex flex-col items-start">
                                        <span class="font-medium">Sync groupes AD</span>
                                        <span class="text-xs opacity-70">Relire les groupes depuis l'annuaire</span>
                                    </div>
                                </button>
                            </li>
                            <li class="menu-title">
                                <span>Actions système</span>
                            </li>
                            @can('delete-user')
                            @if (!$accountDisabled)
                                <li>
                                    <button type="button" class="flex items-center gap-3 w-full text-warning"
                                        wire:click="disableUser"
                                        wire:confirm="Êtes-vous sûr de vouloir désactiver ce compte ? Le home directory sera archivé dans /home/trash/.">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636">
                                            </path>
                                        </svg>
                                        <div class="flex flex-col items-start">
                                            <span class="font-medium">Désactiver le compte</span>
                                            <span class="text-xs opacity-70">Bloquer l'accès et archiver le home</span>
                                        </div>
                                    </button>
                                </li>
                            @else
                                <li>
                                    <button type="button" class="flex items-center gap-3 w-full text-success"
                                        wire:click="enableUser"
                                        wire:confirm="Réactiver ce compte utilisateur ?">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        <div class="flex flex-col items-start">
                                            <span class="font-medium">Activer le compte</span>
                                            <span class="text-xs opacity-70">Réactiver l'accès</span>
                                        </div>
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="flex items-center gap-3 w-full text-error"
                                        wire:click="deleteUserPermanently"
                                        wire:confirm="ATTENTION : Cette action est IRRÉVERSIBLE. Le compte et toutes ses données seront supprimés définitivement. Confirmer la suppression ?">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                            </path>
                                        </svg>
                                        <div class="flex flex-col items-start">
                                            <span class="font-medium">Supprimer définitivement</span>
                                            <span class="text-xs opacity-70">Suppression AD, SQL et home</span>
                                        </div>
                                    </button>
                                </li>
                            @endif
                            @endcan
                            <li>
                                <a href="/annu/del_nt_profile.php?cn={{ $user->login }}&action=del"
                                    onclick="return confirm('Régénérer le profil Windows de cet utilisateur ?')"
                                    class="flex items-center gap-3">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                        </path>
                                    </svg>
                                    <div class="flex flex-col items-start">
                                        <span class="font-medium">Régénérer profil Windows</span>
                                        <span class="text-xs opacity-70">Recréer le profil</span>
                                    </div>
                                </a>
                            </li>
                            @if (!$homeExists)
                                <li>
                                    <a href="/annu/people.php?cn={{ $user->login }}&create_home=y"
                                        class="flex items-center gap-3">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z">
                                            </path>
                                        </svg>
                                        <div class="flex flex-col items-start">
                                            <span class="font-medium">Créer dossier personnel</span>
                                            <span class="text-xs opacity-70">Home directory</span>
                                        </div>
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </div>
            @endif
        </div>
    </x-slot:actions>

    <div>
        <!-- En-tête avec actions principales -->
        @include('pages.users.[login]._partials.user-header', ['resetPasswordValue' => $this->getPasswordForDisplay()])

        <!-- Changement de rôle/catégorie -->
        <div class="mb-8">
            @livewire('pages::users.[login]._partials.role-change-form', ['user' => $user], key('role-change-' . $user->login))
        </div>

        <!-- Groupes et Permissions -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Groupes -->
            @include('pages.users.[login]._partials.groups')
            <!-- Permissions -->
            @include('pages.users.[login]._partials.permissions')
        </div>

        <!-- Quotas disque (story 5.1b) — Livewire SFC remplaçant le Blade pur -->
        <div class="mb-6">
            @livewire('pages::users.[login]._partials.quota-section', ['login' => $user->login], key('quota-'.$user->login))
        </div>

        <!-- Fond d'écran personnel (story 4.7 AC 11) -->
        @if ($wallpaperSectionVisible)
        <div id="wallpaper-section" class="mb-6">
            @include('pages.users.[login]._partials.wallpaper-info')
        </div>
        @endif

        <!-- Identifiants techniques et activité -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Identifiants techniques -->
            @include('pages.users.[login]._partials.technical-identifiers')
            <!-- Activité de l'utilisateur -->
            @include('pages.users.[login]._partials.user-activity')
        </div>

        {{-- <!-- Administration locale -->
        @include('pages.users.[login]._partials.local-admin-rights') --}}

    </div>
</x-organisms.page>
