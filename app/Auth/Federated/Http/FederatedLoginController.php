<?php

declare(strict_types=1);

namespace App\Auth\Federated\Http;

use App\Auth\Federated\ExternalIdentityLifecycleService;
use App\Auth\Federated\FederatedRoleMapper;
use App\Auth\Federated\Jwt\Exceptions\InvalidFederatedJwtException;
use App\Auth\Federated\Jwt\FederatedJwtReplayChecker;
use App\Auth\Federated\Jwt\FederatedJwtVerifier;
use App\Auth\Federated\Jwt\FederatedUserClaims;
use App\Auth\Federated\Session\FederatedSession;
use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Story 20.1 — D-3 / D-4 / T4 ; durci par Story 20.3 (pivot Henri 2026-06-03).
 *
 * Controller D'ENTRÉE du login fédéré (POST binding, façon SAML POST binding —
 * D-3 : le jeton arrive en POST, jamais en query string, pour ne pas fuiter
 * dans les logs d'accès / l'historique / le `Referer`).
 *
 * Ce n'est PAS un second binding `AuthGuardInterface` : `AuthGuardInterface`
 * est un middleware de session à binding unique. Ici on valide le JWT UNE
 * fois, on ouvre une session SE5 standard, puis le guard de session reprend
 * (avec le branchement D-5 pour ne pas éjecter l'externe).
 *
 * Flux :
 *  1. Vérifie le JWT (signature RS256, iss/aud/tier/exp/nbf, anti-rejeu jti).
 *  2. Résout `role` → rôle EXISTANT de l'instance (lookup direct, 20.3 D-1) ;
 *     rôle absent/inconnu → 403, aucune session, AUCUNE création de rôle.
 *  3. Upsert `ExternalIdentity` + provisioning user dans une transaction.
 *  4. `Auth::login()` + marque la session « fédérée » + bridge `$_SESSION`.
 *  5. Redirige dans l'app.
 */
class FederatedLoginController
{
    public function __construct(
        private readonly FederatedJwtVerifier $verifier,
        private readonly FederatedRoleMapper $roleMapper,
        private readonly FederatedJwtReplayChecker $replayChecker,
        private readonly ExternalIdentityLifecycleService $lifecycle,
    ) {
    }

    public function callback(Request $request): RedirectResponse
    {
        $jwt = $this->extractToken($request);

        // --- 1. Vérification du jeton ---
        try {
            $claims = $this->verifier->verify($jwt);
        } catch (InvalidFederatedJwtException $e) {
            // Le verifier a déjà loggé le détail (sans secret). On répond avec
            // le status approprié (401 jeton invalide / 403 si jamais).
            throw new HttpException($e->httpStatus, $e->getMessage(), $e);
        }

        // --- 2. Résolution du rôle AVANT toute persistance de session (fail fast) ---
        // Story 20.3 — D-1 : lookup DIRECT du nom asséré (normalisé) dans les
        // rôles EXISTANTS de l'instance. Pas de table de correspondance, pas de
        // création à la volée. Rôle inconnu (absent en base) → 403, aucune
        // session (D-2 / D-3, invariant 20.1 préservé).
        $roleName = $this->roleMapper->resolve($claims->role);
        if ($roleName === null) {
            Log::channel('federated-auth')->warning('[FederatedLoginController] federated.login.role_unknown', [
                'action_type' => 'federated.login.role_unknown',
                'sub' => $claims->sub,
                'iss' => $claims->iss,
                'role' => $claims->role,
            ]);

            // 403 explicite, AUCUNE session ouverte (jamais de fallback privilégié).
            throw new HttpException(403, 'Federated role not authorized on this instance');
        }

        // --- 3. Upsert identité + provisioning user dans une transaction ---
        // L'anti-rejeu `jti` est consommé EN DERNIER, après le provisioning
        // réussi : un échec amont (identité révoquée, panne DB) rollback ET ne
        // brûle pas le `jti`, donc un retry légitime du même jeton (encore
        // valide) reste possible (review M1).
        $user = DB::transaction(function () use ($claims, $roleName): User {
            // Story 20.2 — D-2 : la réconciliation de l'identité (upsert + sync
            // profil + gardes révocation/anonymisation) est déléguée au service
            // de cycle de vie. Comportement observable INCHANGÉ vs 20.1 (le
            // garde anti-résurrection D-4 est un AJOUT, jamais déclenché par
            // une identité 20.1 non encore anonymisée).
            $identity = $this->lifecycle->reconcileOnLogin($claims);
            $user = $this->provisionUser($identity, $claims);
            $this->applyRole($user, $roleName);

            // Consommation jti à usage unique. Si déjà consommé (rejeu /
            // course concurrente) → rollback du provisioning + 401.
            if (! $this->replayChecker->consumeOnce($claims->jti, $claims->exp)) {
                Log::channel('federated-auth')->warning('[FederatedLoginController] federated.login.replayed', [
                    'action_type' => 'federated.login.replayed',
                    'jti' => $claims->jti,
                    'sub' => $claims->sub,
                    'iss' => $claims->iss,
                ]);
                throw new HttpException(401, 'Federated token already used');
            }

            return $user;
        });

        // --- 4. Ouverture de session SE5 standard ---
        Auth::login($user);

        // Marque la session « fédérée » (D-5 : le guard saute le LDAP pour elle).
        FederatedSession::mark($request, $user->external_identity_id);

        // Bridge legacy : `SambaEduAuthGuard`/`AuthenticationService` lisent
        // `$_SESSION['login']` pour `isAlreadyAuthenticated()`/`getCurrentUser()`.
        $this->bridgeLegacySession($user->login);

        Log::channel('federated-auth')->info('[FederatedLoginController] federated.login.success', [
            'action_type' => 'federated.login.success',
            'sub' => $claims->sub,
            'jti' => $claims->jti,
            'iss' => $claims->iss,
            'role' => $roleName,
        ]);

        return redirect()->intended(route('app.dashboard'));
    }

    /**
     * Extrait le JWT du POST. Champ `token` UNIQUEMENT (form auto-soumis rendu
     * par l'IdP, D-3 « POST binding strict »). Pas de fallback `Authorization:
     * Bearer` (path non tranché — review #4) ni de query string (fuite logs/
     * historique/`Referer`). Si Henri veut tolérer Bearer pour des intégrations,
     * cela doit passer par une décision explicite (D-10) documentée en 20.5.
     */
    private function extractToken(Request $request): string
    {
        $token = $request->post('token');
        if (is_string($token) && $token !== '') {
            return $token;
        }

        throw new HttpException(400, 'Missing federated token');
    }

    /**
     * Provisionne (ou recharge) le `User` Eloquent externe lié à l'identité.
     * Marqué `source='federated'`, sans `dn`/`ad_guid`, jamais synchronisé AD
     * (D-4).
     */
    private function provisionUser(ExternalIdentity $identity, FederatedUserClaims $claims): User
    {
        $user = User::where('external_identity_id', $identity->id)
            ->where('source', 'federated')
            ->first();

        if ($user === null) {
            $user = new User();
            $user->external_identity_id = $identity->id;
            $user->source = 'federated';
            // Login unique : préfixe pour éviter toute collision avec un login
            // AD homonyme (les externes ne vivent pas dans l'AD local).
            $user->login = $this->federatedLogin($identity, $claims);
        }

        // Rafraîchit le profil d'affichage (non sécurité-critique).
        $user->fullname = $claims->name !== '' ? $claims->name : $claims->login;
        $user->email = $claims->email !== '' ? $claims->email : $user->email;
        $user->is_active = true;
        // Invariants D-4 : pas d'attache AD.
        $user->dn = null;
        $user->ad_guid = null;
        $user->role = 'federated';
        $user->save();

        return $user;
    }

    /**
     * Construit un login local stable et unique pour l'utilisateur externe.
     * Préfixe `ext:` + external_sub → jamais en collision avec un login AD.
     */
    private function federatedLogin(ExternalIdentity $identity, FederatedUserClaims $claims): string
    {
        return 'ext:' . $identity->external_sub;
    }

    /**
     * Applique le rôle EXISTANT résolu (sync : un externe porte exactement le
     * rôle asséré, ré-évalué à chaque login).
     *
     * Story 20.3 — D-2 : on n'a RIEN à créer. `$roleName` est le nom canonique
     * d'un rôle dont l'EXISTENCE en base a déjà été établie par le mapper
     * (lookup direct). On applique simplement via `syncRoles`. Les Policies/
     * Gates Spatie existants (Epic 7, type-hint `App\Models\User`) s'appliquent
     * ensuite sans duplication. AUCUN `firstOrCreate` : jamais de rôle fantôme.
     */
    private function applyRole(User $user, string $roleName): void
    {
        $user->syncRoles([$roleName]);
    }

    /**
     * Pose l'état de session legacy attendu par `AuthenticationService`
     * (`$_SESSION['login']`) pour que `SambaEduAuthGuard::handle()` considère
     * l'utilisateur authentifié sur les requêtes suivantes.
     */
    private function bridgeLegacySession(string $login): void
    {
        if (session_status() === PHP_SESSION_NONE && ! headers_sent()) {
            @session_start();
        }
        $_SESSION['login'] = $login;
    }
}
