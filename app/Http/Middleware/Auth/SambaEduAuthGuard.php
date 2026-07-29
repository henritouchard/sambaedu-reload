<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use App\Auth\Federated\ExternalIdentityLifecycleService;
use App\Auth\Federated\Session\FederatedSession;
use App\Models\ExternalIdentity;
use App\Models\User;
use App\Services\AuthenticationService;
use App\Services\UserSyncService;
use App\Repositories\UserRepository;

/**
 * Implémentation MVP de l'AuthGuard pour SambaEdu
 *
 * Reproduit exactement le comportement du middleware SambaEduAuth original :
 * session, LDAP, auto-provisioning Eloquent, Auth::login.
 *
 * Pour swapper vers Keycloak (Phase 2), remplacer le binding dans AppServiceProvider :
 * AuthGuardInterface::class → KeycloakAuthGuard::class
 */
class SambaEduAuthGuard implements AuthGuardInterface
{
    public function __construct(
        private AuthenticationService $authService,
        private UserRepository $userRepository,
        private ExternalIdentityLifecycleService $federatedLifecycle
    ) {
    }

    /**
     * Représentation NON ré-identifiable d'un `sub` fédéré pour les logs (M-2 /
     * review 20.2 — doctrine AC16/D-5 : jamais de `sub` clair). Un `external_sub`
     * déjà anonymisé (`anon:<hmac>`) est opaque → loggé tel quel sans re-hasher
     * (évite le double hash latent P-9).
     */
    private function subForLog(?string $sub): ?string
    {
        if ($sub === null || $sub === '') {
            return null;
        }
        if (str_starts_with($sub, ExternalIdentityLifecycleService::ANON_PREFIX)) {
            return $sub;
        }

        return $this->federatedLifecycle->hashSub($sub);
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

        // Story 20.1 — D-5 : RÉCONCILIATION du guard pour les sessions
        // FÉDÉRÉES. Un utilisateur externe n'existe PAS dans le LDAP — la
        // vérification `findByLogin` ci-dessous le déconnecterait à chaque
        // requête. Si la session est marquée « fédérée », on valide
        // `ExternalIdentity.is_active` et on SAUTE entièrement la vérif LDAP.
        // Le flux LDAP reste STRICTEMENT INCHANGÉ pour les sessions non
        // fédérées (AC15).
        if (FederatedSession::isFederated($request)) {
            return $this->handleFederatedSession($request, $next, $login);
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
        // auth()->user() renverra un `App\Models\User` Eloquent (source de vérité
        // Spatie + délégations scopées). L'auto-provisioning DB est déjà fait
        // ci-dessus par `ensureEloquentUser`.
        //
        // On réaligne le guard `web` à chaque requête où la session LDAP
        // courante ne correspond pas à l'Eloquent connecté. Sans ce check,
        // une session web résiduelle (logout LDAP sans Auth::logout) ferait
        // passer les middlewares `can:*` sur l'identité du user précédent.
        $eloquentUser = User::findByLogin($login);
        if ($eloquentUser && Auth::id() !== $eloquentUser->id) {
            Auth::login($eloquentUser);
        }
        return $next($request);
    }

    /**
     * Story 20.1 — D-5. Traite une requête authentifiée dont la session est
     * marquée « fédérée ».
     *
     * Au lieu de revérifier le LDAP (l'externe n'y existe pas), on :
     *  1. recharge le `User` Eloquent fédéré ;
     *  2. valide que l'`ExternalIdentity` liée est toujours active ;
     *  3. réaligne le guard Laravel (`Auth::login`) si besoin.
     *
     * Si l'identité externe est désactivée → logout + 401 (révocation
     * effective côté SE5, indépendante de l'AD).
     */
    private function handleFederatedSession(Request $request, Closure $next, string $login): Response
    {
        $user = User::where('login', $login)
            ->where('source', 'federated')
            ->first();

        if ($user === null) {
            // AC16 : ne logger que des claims non sensibles. On dérive le `sub`
            // du login fédéré (`ext:<sub>`) et on le HASHE (M-2 : jamais en clair).
            Log::channel('federated-auth')->warning('[SambaEduAuthGuard] federated.session.user_missing', [
                'action_type' => 'federated.session.user_missing',
                'sub_hash' => $this->subForLog(str_starts_with($login, 'ext:') ? substr($login, 4) : null),
            ]);
            $this->logoutFederated($request);
            return $this->unauthorized($request, 'Session fédérée invalide');
        }

        $identity = $user->external_identity_id !== null
            ? ExternalIdentity::find($user->external_identity_id)
            : null;

        if ($identity === null || !$identity->is_active) {
            Log::channel('federated-auth')->warning('[SambaEduAuthGuard] federated.session.deactivated', [
                'action_type' => 'federated.session.deactivated',
                'sub_hash' => $this->subForLog($identity?->external_sub),
            ]);
            $this->logoutFederated($request);
            return $this->unauthorized($request, 'Identité externe désactivée');
        }

        // Expose l'utilisateur courant à la requête (parité flux LDAP).
        $request->attributes->set('sambaedu_user', $user);
        $request->attributes->set('sambaedu_login', $login);

        // Réaligne le guard web sur l'utilisateur fédéré (les `can:*` doivent
        // évaluer SES rôles Spatie). Aucune vérif LDAP n'est effectuée.
        if (Auth::id() !== $user->id) {
            Auth::login($user);
        }

        return $next($request);
    }

    /**
     * Déconnecte proprement une session fédérée (purge marqueur + session
     * legacy + guard Laravel).
     */
    private function logoutFederated(Request $request): void
    {
        FederatedSession::forget($request);
        $this->authService->logout();
        Auth::logout();
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
            if ($login === User::PROTECTED_ADMIN_LOGIN) {
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

        // Stocker l'URL demandée pour redirection après login.
        //
        // ⚠️ `getRequestUri()` et NON `path()` (Story 55.1) : `path()` amputait
        // la QUERY STRING. Or tout le flux OIDC vit dans la query
        // (`client_id`, `state`, `code_challenge`, `nonce`…) — un utilisateur
        // sans session dirigé vers `/oidc/authorize?…` était « repris » après
        // login sur `/oidc/authorize` NU, donc refusé systématiquement. Le SSO
        // ne pouvait jamais aboutir au premier accès de la journée.
        //
        // ⚠️ Et NON `fullUrl()` (correctif review 55.1) : `fullUrl()` reconstruit
        // une URL ABSOLUE à partir du header `Host` de la requête, non filtré ici
        // (`TrustHosts` est désactivé dans le Kernel et le vhost Apache répond à
        // n'importe quel `Host`). Cette URL absolue serait ensuite suivie telle
        // quelle par `redirect()->intended()`, qui ne vérifie aucun host : un
        // `Host` détourné ferait de `url.intended` un open-redirect emportant
        // toute la query OIDC. `getRequestUri()` rend un chemin RELATIF
        // (chemin + query, jamais de scheme ni d'hôte) — le besoin de la story
        // est couvert sans jamais faire confiance à un en-tête entrant, et sans
        // toucher à la configuration transverse de l'application.
        //
        // Précédent interne à corriger un jour de la même façon :
        // `app/Http/Middleware/RequireAdminRights.php:145`. On répare le
        // mécanisme standard (`url.intended` + `redirect()->intended()` de
        // `AuthController`) plutôt que d'inventer un canal parallèle.
        $intendedUrl = $request->getRequestUri();
        session()->put('url.intended', $intendedUrl);

        return redirect(route('auth.login'));
    }
}
