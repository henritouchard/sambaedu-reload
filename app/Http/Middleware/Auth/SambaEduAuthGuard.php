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

/**
 * Guard de session SambaEdu — **Postgres-only depuis la Story 49.2**.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Doctrine (Story 49.2, FR-R4) : le guard n'interroge JAMAIS le LDAP
 * ─────────────────────────────────────────────────────────────────────────────
 * Le guard s'exécute à CHAQUE requête HTTP authentifiée. Y placer un lookup
 * annuaire, c'était un aller-retour réseau par requête (atténué par un cache
 * 60 s qui, lui, retardait d'autant la prise en compte d'une désactivation).
 * Depuis 49.1 (rôles Spatie = miroir des appartenances) et 49.3 (`users.is_active`
 * = miroir de `useraccountcontrol`, écrit par la sync ET par la réconciliation
 * des départs), Postgres porte tout ce dont le guard a besoin :
 *
 *  - **existence** : `users` (lookup unique, borné aux comptes NON fédérés) ;
 *  - **activité**  : `users.is_active`.
 *
 * L'AD reste l'autorité des comptes et de l'authentification, mais son seul
 * point de contact runtime est désormais la **cérémonie de login** (bind, et
 * l'auto-provisioning œuf/poule déplacé dans `AuthController::authenticate`) —
 * 1 fois par session, jamais par requête.
 *
 * **Aucun cache sur ce chemin** (D8) : une lecture SQL indexée est moins chère
 * que l'ancien `Cache::remember` + LDAP, et la fraîcheur EST le bénéfice (un
 * compte désactivé est déconnecté à la requête suivante, sans fenêtre). Piège
 * projet à ne pas réintroduire : APCu n'a pas de `Cache::lock()`.
 *
 * **Branche fédérée strictement inchangée** (Story 20.1 — D-5) : elle est
 * évaluée AVANT le lookup natif et ne touche ni le LDAP ni `users.is_active`
 * (c'est `ExternalIdentity.is_active` qui fait foi pour un externe).
 *
 * Pour swapper vers Keycloak (Phase 2), remplacer le binding dans AppServiceProvider :
 * AuthGuardInterface::class → KeycloakAuthGuard::class
 */
class SambaEduAuthGuard implements AuthGuardInterface
{
    public function __construct(
        private AuthenticationService $authService,
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

        // Story 49.2 — EXISTENCE depuis Postgres (un seul lookup, réutilisé plus
        // bas pour l'alignement `Auth::login`). Aucun appel LDAP ici.
        $user = $this->findNativeUser($login);

        if ($user === null) {
            Log::warning('SambaEduAuth: Utilisateur non trouvé en base', ['login' => $login]);
            $this->authService->logout();
            return $this->unauthorized($request, 'Utilisateur non trouvé');
        }

        // Story 49.2 — ACTIVITÉ depuis `users.is_active`, miroir de
        // `useraccountcontrol` posé par la sync et la réconciliation (49.3).
        //
        // ⚠️ Le `logout` est AJOUTÉ ici : jusqu'à 49.2, cette branche refusait la
        // requête SANS détruire la session. La session survivait donc au refus et
        // l'utilisateur bouclait indéfiniment sur la redirection vers /login. Un
        // compte désactivé doit être DÉCONNECTÉ, pas seulement éconduit.
        if (!$user->is_active) {
            Log::warning('SambaEduAuth: Compte désactivé', ['login' => $login]);
            $this->authService->logout();
            Auth::logout();
            return $this->unauthorized($request, 'Compte désactivé');
        }

        // `sambaedu_user` porte désormais le User ELOQUENT (le DTO LDAP n'existe
        // plus sur ce chemin). Unique consommateur runtime : `RequireAdminRights`.
        $request->attributes->set('sambaedu_user', $user);
        $request->attributes->set('sambaedu_login', $login);

        // Réalignement du guard `web` : à chaque requête où la session legacy
        // courante ne correspond pas à l'Eloquent connecté. Sans ce check, une
        // session web résiduelle (logout legacy sans `Auth::logout`) ferait passer
        // les middlewares `can:*` sur l'identité du user précédent.
        if (Auth::id() !== $user->id) {
            Auth::login($user);
        }
        return $next($request);
    }

    /**
     * Story 49.2 (D2) — résolution SQL d'une session NATIVE.
     *
     * Le lookup est borné aux comptes non fédérés : l'ancien `findByLogin` LDAP
     * les excluait implicitement (un externe n'existe pas dans l'annuaire), et
     * un compte fédéré porte un login de la forme `ext:<sub>` qui pourrait
     * entrer en homonymie. Une session native ne doit JAMAIS s'aligner sur une
     * ligne `source='federated'` (fiche `project_sql_user_pickers_must_exclude_federated`).
     *
     * `source` NULL est traité comme `'ad'` (défaut de colonne, lignes créées
     * avant la migration `add_source_and_external_identity_to_users_table`).
     *
     * Comparaison de login insensible à la casse, comme {@see User::findByLogin()}
     * (AD est case-insensitive sur sAMAccountName, Postgres ne l'est pas).
     */
    private function findNativeUser(string $login): ?User
    {
        return User::query()
            ->whereRaw('LOWER(login) = ?', [strtolower($login)])
            ->where(function ($query): void {
                $query->where('source', '!=', 'federated')
                    ->orWhereNull('source');
            })
            ->first();
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
