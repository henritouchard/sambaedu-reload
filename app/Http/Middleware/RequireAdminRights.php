<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use App\Enums\SambaRole;
use App\Models\User;
use App\Services\AuthenticationService;

/**
 * Middleware pour vérifier que l'utilisateur a les droits d'administration
 * (alias `sambaedu.admin`).
 *
 * **Story 49.2 — Postgres-only.** Le middleware consommait le DTO LDAP posé par
 * le guard (`memberOf`, groupes de droits AD) : une décision d'autorisation
 * prise sur des données annuaire, à chaque requête. Il consomme désormais le
 * User ELOQUENT posé par `SambaEduAuthGuard` et décide sur les données
 * Postgres. **Les motifs sont IDENTIQUES** : c'est une bascule de TRANSPORT,
 * pas de sémantique — la simplification vers du Spatie pur est un chantier
 * d'extinction ultérieur, pas ce cut-over.
 *
 * Doit être utilisé APRÈS le middleware SambaEduAuth
 */
class RequireAdminRights
{
    public function __construct(
        private AuthenticationService $authService
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Récupérer l'utilisateur depuis la requête (déjà vérifié par SambaEduAuth,
        // qui y pose un User Eloquent depuis la Story 49.2).
        $user = $request->attributes->get('sambaedu_user');
        $login = $request->attributes->get('sambaedu_login');

        if (!$user instanceof User) {
            $user = null;
        }

        // Si pas d'utilisateur dans la requête, essayer de le récupérer (SQL).
        if (!$user && $login) {
            $user = $this->findNativeUser($login);
        }

        // Si toujours pas d'utilisateur, récupérer depuis le service d'auth
        if (!$user) {
            $login = $this->authService->getCurrentUser();
            if ($login) {
                $user = $this->findNativeUser($login);
            }
        }

        if (!$user) {
            Log::warning('RequireAdminRights: Aucun utilisateur trouvé');
            return $this->redirectToLogin($request);
        }

        // Vérifier les droits admin (données Postgres)
        if (!$this->hasAdminRights($user)) {
            Log::warning('RequireAdminRights: Accès non autorisé', [
                'login' => $user->login,
                'role' => $user->role
            ]);
            return $this->unauthorized($request, 'Vous n\'avez pas les droits d\'administration nécessaires');
        }

        return $next($request);
    }

    /**
     * Vérifie si l'utilisateur a les droits d'administration.
     *
     * Trois voies, dans l'ordre — mêmes motifs qu'avant 49.2, données Postgres :
     *
     *  1. **Rôle Spatie `super-admin`** (clause AJOUTÉE par 49.2, et VITALE).
     *     Le compte protégé `admin` figure dans
     *     {@see \App\Constants\Ldap\MainGroups::SYSTEM_ACCOUNTS} : il est donc
     *     FILTRÉ du balayage AD→SQL et n'a **aucune appartenance** en base.
     *     Les deux voies suivantes, qui lisent des groupes/profils synchronisés,
     *     le rateraient toutes les deux — et il serait verrouillé hors de ses
     *     propres routes (les routes quota n'ont pas d'autre garde).
     *  2. Motifs historiques sur les **profils de droits AD** (`ad_right_profiles`,
     *     miroir SQL des groupes de la branche `OU=rights`).
     *  3. Motifs historiques sur les **noms de groupes** de l'utilisateur
     *     (`user_groups`, miroir SQL des `memberOf`).
     *
     * Écart assumé et documenté (D4) : un compte « Domain Admins uniquement »,
     * hors des trois groupes principaux, n'est jamais synchronisé — il ne passera
     * donc plus par ce middleware sans délégation Spatie. Population théorique
     * (les vrais administrateurs passent par `admin` ou par une délégation),
     * consignée au runbook QA.
     */
    /**
     * Story 49.2 (correction de review) — résolution SQL bornée aux comptes
     * NON fédérés, symétrique de `SambaEduAuthGuard::findNativeUser()` (D2).
     *
     * Ces deux voies de secours ne sont pas atteintes en usage normal : toutes
     * les routes `sambaedu.admin` sont imbriquées sous `sambaedu.auth`, qui pose
     * toujours `sambaedu_user` en amont. Elles n'en sont pas moins des chemins
     * de résolution d'identité, et l'exclusion des fédérés y tenait jusqu'ici
     * « par construction » — le lookup passait par le LDAP, où un compte externe
     * n'existe pas. En basculant sur SQL, cette garantie implicite disparaît :
     * un login fédéré homonyme d'un compte AD pourrait s'y glisser si une future
     * route appliquait `sambaedu.admin` seul. Défense en profondeur, coût nul.
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

    private function hasAdminRights(User $user): bool
    {
        // (1) Compte protégé / super-admin délégué.
        if ($user->hasRole(SambaRole::SuperAdmin->value)) {
            return true;
        }

        // (2) Droits spécifiques issus des groupes de droits AD (branche "rights").
        $adminRightGroups = [
            'user_admin',
            'computer_admin',
            'admin_se4',
            'admins',
            'administrateurs',
        ];

        $rights = is_array($user->ad_right_profiles) ? $user->ad_right_profiles : [];

        foreach ($rights as $right) {
            $rightLower = strtolower((string) $right);
            foreach ($adminRightGroups as $adminRight) {
                if (str_contains($rightLower, $adminRight)) {
                    return true;
                }
            }
        }

        // (3) Groupes généraux.
        foreach ($user->userGroups()->pluck('name') as $group) {
            $groupLower = strtolower((string) $group);
            if (
                str_contains($groupLower, 'admin') ||
                str_contains($groupLower, 'domain admins')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retourne une réponse non autorisée
     */
    private function unauthorized(Request $request, string $message = 'Accès non autorisé'): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => $message
            ], 403);
        }

        return redirect()->back()->with('error', $message);
    }

    /**
     * Redirige vers la page de login en mémorisant l'URL demandée.
     * Utilisé quand aucun utilisateur n'est résolu — `back()` boucle dans ce
     * cas (pas de Referer sur un accès direct → 302 vers la même URL).
     */
    private function redirectToLogin(Request $request): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Authentification requise',
            ], 401);
        }

        if ($request->hasSession()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('auth.login');
    }
}
