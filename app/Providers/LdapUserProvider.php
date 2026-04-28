<?php

namespace App\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use App\LdapModels\LdapUser;
use App\Models\User;
use App\Services\AuthenticationService;
use Illuminate\Support\Facades\Log;

/**
 * UserProvider personnalisé pour l'authentification LDAP SambaEdu.
 *
 * Renvoie directement un `App\Models\User` Eloquent. L'Eloquent est la source
 * de vérité pour les permissions Spatie, les délégations scopées et les
 * relations DB. Les attributs "vivants" de l'AD (groupes, flags admin/prof)
 * sont lazy-loadés depuis LDAP à la demande via `User::ldapBusinessObject()`.
 *
 * Why: avant, on renvoyait un `AuthUser` (wrapper LDAP) qui forçait tous les
 * checks scopés à résoudre l'Eloquent par un `instanceof User` + bridge. Les
 * oublis (ex. `parc/index.blade.php::scopedUser`) laissaient passer des users
 * LDAP non-scopés en ignorant leurs délégations négatives.
 */
class LdapUserProvider implements UserProvider
{
    public function __construct(
        private AuthenticationService $authService
    ) {
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        if (empty($identifier)) {
            return null;
        }

        // L'identifier provient de Auth::login() → c'est la PK Eloquent (id),
        // pas un login. Avant le passage à Eloquent (commit 56ba789), AuthUser
        // utilisait le login comme identifier — d'où le résolveur par login.
        return User::find($identifier);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        // Remember me non supporté avec LDAP
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
        // Remember me non supporté avec LDAP
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (empty($credentials['login']) && empty($credentials['username'])) {
            return null;
        }

        $login = $credentials['login'] ?? $credentials['username'];

        return $this->resolveUser((string) $login);
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (empty($credentials['password'])) {
            return false;
        }

        $login = $user->getAuthIdentifier();
        $password = $credentials['password'];
        $ip = request()->ip() ?? '127.0.0.1';

        try {
            $result = $this->authService->authenticate($login, $password, $ip);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            Log::error('LdapUserProvider: Erreur lors de la validation', [
                'login' => $login,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        // Le mot de passe est géré par LDAP, pas de rehash nécessaire
    }

    /**
     * Résout un login en `App\Models\User`. Auto-provisionne la row SQL
     * depuis LDAP si elle manque (œuf/poule : 1re connexion avant sync batch).
     */
    private function resolveUser(string $login): ?User
    {
        $user = User::findByLogin($login);
        if ($user !== null) {
            return $user;
        }

        $ldapUser = LdapUser::findByLogin($login);
        if ($ldapUser === null) {
            return null;
        }

        try {
            $bo = $ldapUser->toBusinessObject();

            return User::updateOrCreate(
                ['login' => $login],
                [
                    'fullname' => $bo->fullname ?? $login,
                    'firstname' => $bo->firstname ?? null,
                    'lastname' => $bo->lastname ?? null,
                    'email' => $bo->email ?? null,
                    'dn' => $bo->dn ?? null,
                    'role' => $bo->role ?? 'autre',
                    'is_active' => method_exists($bo, 'isActiveAccount') ? $bo->isActiveAccount() : true,
                    'ad_synced_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('LdapUserProvider: auto-provisioning échoué', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
