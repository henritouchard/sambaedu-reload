<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AuthUser;
use App\Models\User;
use App\Services\AuthenticationService;
use App\Services\UserSyncService;
use App\Repositories\UserRepository;

/**
 * Middleware d'authentification SambaEdu
 * 
 * Utilise les services Laravel modernes (AuthenticationService, UserRepository)
 * au lieu des fonctions legacy (get_config, search_user, etc.)
 */
class SambaEduAuth
{
    public function __construct(
        private AuthenticationService $authService,
        private UserRepository $userRepository
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Vérifier si l'utilisateur est déjà authentifié via le service moderne
        if (!$this->authService->isAlreadyAuthenticated()) {
            return $this->unauthorized($request);
        }

        // Récupérer le login depuis la session
        $login = $this->authService->getCurrentUser();

        if (empty($login)) {
            return $this->unauthorized($request);
        }

        // Vérifier que l'utilisateur existe toujours dans LDAP via le repository
        // OPTIMISÉ: Utilise maintenant le cache (60s) et recherche limitée à l'établissement courant
        $user = $this->userRepository->findByLogin($login);

        if (!$user) {
            Log::warning('SambaEduAuth: Utilisateur non trouvé dans LDAP', ['login' => $login]);
            $this->authService->logout();
            return $this->unauthorized($request, 'Utilisateur non trouvé');
        }

        // Vérifier que le compte est actif
        if (!$user->isActive) {
            Log::warning('SambaEduAuth: Compte désactivé', ['login' => $login]);
            return $this->unauthorized($request, 'Compte désactivé');
        }

        // Ajouter les informations utilisateur à la requête
        $request->attributes->set('sambaedu_user', $user);
        $request->attributes->set('sambaedu_login', $login);

        // Auto-provisionner le User Eloquent s'il n'existe pas encore en SQL
        // Résout le problème œuf/poule : l'admin peut se connecter avant le premier sync
        $this->ensureEloquentUser($login, $user);

        // Connecter l'utilisateur au système d'authentification Laravel
        if (!Auth::check()) {
            $laravelUser = AuthUser::findByLogin($login);
            if ($laravelUser) {
                Auth::login($laravelUser);
            }
        }
        return $next($request);
    }

    /**
     * Crée le User Eloquent à la volée si absent, avec droits admin si login='admin'
     */
    private function ensureEloquentUser(string $login, \App\Types\User $adUser): void
    {
        $eloquentUser = User::where('login', $login)->first();

        if ($eloquentUser !== null) {
            return;
        }

        Log::info('SambaEduAuth: Auto-provisioning User Eloquent', ['login' => $login]);

        try {
            $eloquentUser = User::create([
                'login' => $login,
                'fullname' => $adUser->fullname,
                'firstname' => $adUser->firstname,
                'lastname' => $adUser->lastname,
                'email' => $adUser->email,
                'dn' => $adUser->dn,
                'role' => $adUser->role ?? 'autre',
                'is_active' => true,
                'ad_synced_at' => now(),
            ]);

            // Si c'est l'admin, lui donner tous les droits immédiatement
            if ($login === 'admin') {
                app(UserSyncService::class)->grantAdminRights($eloquentUser);
                Log::info('SambaEduAuth: Droits super-admin accordés automatiquement', ['login' => $login]);
            }
        } catch (\Exception $e) {
            Log::error('SambaEduAuth: Erreur auto-provisioning', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retourne une réponse non autorisée
     */
    private function unauthorized(Request $request, string $message = 'Authentication required'): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => $message,
                'auth_url' => '/login'
            ], 401);
        }

        // Stocker l'URL demandée pour redirection après login
        $intendedUrl = $request->path();
        session()->put('url.intended', $intendedUrl);

        return redirect(route('auth.login'));
    }
}