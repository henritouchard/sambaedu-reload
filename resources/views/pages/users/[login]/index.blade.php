<?php
use Livewire\Component;
use Livewire\Attributes\Title;
use App\Services\UserService;
use App\Services\AuthenticationService;
use App\Services\AdDataTransformer;
use App\Types\User;
use Illuminate\Support\Facades\Gate;
use Devrabiul\ToastMagic\Facades\ToastMagic;

new #[Title('Profil utilisateur - Instance SE4FS')] class extends Component {
    public ?User $user = null;
    public bool $accountDisabled = false;
    public bool $homeExists = true;
    public bool $isOwnProfile = false;
    public ?string $resetPasswordValue = null;
    public ?array $localAdminInfo = null;
    public array $listCurrentGroups = [];
    public array $listCurrentRights = [];

    private UserService $userService;
    private AuthenticationService $authService;
    private AdDataTransformer $adTransformer;

    public function boot(UserService $userService, AuthenticationService $authService, AdDataTransformer $adTransformer)
    {
        $this->userService = $userService;
        $this->authService = $authService;
        $this->adTransformer = $adTransformer;
    }

    public function mount(string $login): void
    {
        $this->user = $this->userService->getByLoginFromSql($login);

        if (!$this->user) {
            abort(404);
        }

        // Vérifier si c'est le profil de l'utilisateur connecté
        $currentLogin = $this->authService->getCurrentUser();
        $this->isOwnProfile = $currentLogin && $currentLogin === $this->user->login;

        // Statut du compte
        $this->accountDisabled = $this->user->isDisabled();

        // Groupes et droits
        $this->listCurrentGroups = $this->user->groups;
        $this->listCurrentRights = $this->user->rights;
    }

    public function resetPassword(): void
    {
        if (!$this->user) {
            ToastMagic::error('Utilisateur introuvable.');
            return;
        }

        if (!Gate::allows('update-user')) {
            ToastMagic::error('Vous n\'avez pas les droits pour cette action.');
            return;
        }

        $result = $this->userService->resetPasswordInAd($this->user->login);

        if (!($result['success'] ?? false)) {
            ToastMagic::error($result['message'] ?? 'Erreur lors de la réinitialisation du mot de passe.');
            return;
        }

        $this->resetPasswordValue = $result['password'] ?? null;
        ToastMagic::success($result['message'] ?? 'Mot de passe réinitialisé avec succès.');
    }
};

?>

<x-organisms.page :backUrl="route('app.users')" title="Utilisateur" backText="Retour à la liste">

    <!-- Composant Livewire de gestion des groupes -->
    <livewire:components::organisms.groups-drawer />
    <!-- Composant Livewire de gestion des permissions -->
    <livewire:components::organisms.rights-drawer />

    <x-slot:actions>
        <!-- Actions principales -->
        <div class="flex flex-col gap-3 flex-shrink-0">
            @can('update-user')
                @if ($user->login !== 'Administrator')
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
                            <li>
                                <a href="/annu/mod_user_entry.php?cn={{ $user->login }}" class="flex items-center gap-3">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                        </path>
                                    </svg>
                                    <div class="flex flex-col items-start">
                                        <span class="font-medium">Modifier le compte</span>
                                        <span class="text-xs opacity-70">Informations personnelles</span>
                                    </div>
                                </a>
                            </li>
                            <li>
                                <button type="button" class="flex items-center gap-3 w-full" wire:click="resetPassword"
                                    wire:confirm="Réinitialiser le mot de passe de cet utilisateur ?">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z">
                                        </path>
                                    </svg>
                                    <div class="flex flex-col items-start">
                                        <span class="font-medium">Réinitialiser le mot de passe</span>
                                        <span class="text-xs opacity-70">Nouveau mot de passe</span>
                                    </div>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="flex items-center gap-3 w-full"
                                    @click="Livewire.dispatch('open-groups-drawer', { users: ['{{ $user->login }}'] }); document.activeElement.blur();">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    <div class="flex flex-col items-start">
                                        <span class="font-medium">Gérer les groupes</span>
                                        <span class="text-xs opacity-70">Ajouter ou retirer des groupes</span>
                                    </div>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="flex items-center gap-3 w-full"
                                    @click="Livewire.dispatch('open-rights-drawer', { login: '{{ $user->login }}' }); document.activeElement.blur();">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z">
                                        </path>
                                    </svg>
                                    <div class="flex flex-col items-start">
                                        <span class="font-medium">Gérer les permissions</span>
                                        <span class="text-xs opacity-70">Droits d'administration</span>
                                    </div>
                                </button>
                            </li>
                            <li class="menu-title">
                                <span>Actions système</span>
                            </li>
                            @if (!$accountDisabled)
                                <li>
                                    <a href="/annu/desac_user_entry.php?cn={{ $user->login }}"
                                        onclick="return confirm('Êtes-vous sûr de vouloir désactiver ce compte ?')"
                                        class="flex items-center gap-3 text-warning">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636">
                                            </path>
                                        </svg>
                                        <div class="flex flex-col items-start">
                                            <span class="font-medium">Désactiver le compte</span>
                                            <span class="text-xs opacity-70">Bloquer l'accès</span>
                                        </div>
                                    </a>
                                </li>
                            @else
                                <li>
                                    <a href="/annu/desac_user_entry.php?cn={{ $user->login }}&action=activ"
                                        class="flex items-center gap-3 text-success">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        <div class="flex flex-col items-start">
                                            <span class="font-medium">Activer le compte</span>
                                            <span class="text-xs opacity-70">Réactiver l'accès</span>
                                        </div>
                                    </a>
                                </li>
                            @endif
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
            @endcan
        </div>
    </x-slot:actions>

    @if ($resetPasswordValue)
        <div class="alert alert-success mb-4">
            <i class="fa-solid fa-key"></i>
            <div>
                <div class="font-medium">Mot de passe réinitialisé (AD)</div>
                <div class="text-sm">Nouveau mot de passe : <span class="font-mono">{{ $resetPasswordValue }}</span>
                </div>
            </div>
        </div>
    @endif

    <!-- En-tête avec actions principales -->
    @include('pages.users.[login]._partials.user-header')

    <!-- Groupes et Permissions -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Groupes -->
        @include('pages.users.[login]._partials.groups')
        <!-- Permissions -->
        @include('pages.users.[login]._partials.permissions')
    </div>

    <!-- Quotas disque -->
    <div class="mb-6">
        @include('pages.users.[login]._partials.quota-info')
    </div>

    <!-- Identifiants techniques et activité -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Identifiants techniques -->
        @include('pages.users.[login]._partials.technical-identifiers')
        <!-- Activité de l'utilisateur -->
        @include('pages.users.[login]._partials.user-activity')
    </div>

    {{-- <!-- Administration locale -->
    @include('pages.users.[login]._partials.local-admin-rights') --}}

</x-organisms.page>
