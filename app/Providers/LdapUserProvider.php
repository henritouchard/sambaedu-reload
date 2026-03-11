<?php

namespace App\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use App\Models\AuthUser;
use App\Services\AuthenticationService;
use Illuminate\Support\Facades\Log;

/**
 * UserProvider personnalisé pour l'authentification LDAP SambaEdu
 * 
 * Permet d'intégrer l'authentification LDAP avec le système d'auth Laravel
 * pour utiliser @can, Gate, policies, etc.
 */
class LdapUserProvider implements UserProvider
{
    public function __construct(
        private AuthenticationService $authService
    ) {
    }

    /**
     * Retrieve a user by their unique identifier.
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        if (empty($identifier)) {
            return null;
        }

        return AuthUser::findByLogin($identifier);
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     */
    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        // Remember me non supporté avec LDAP
        return null;
    }

    /**
     * Update the "remember me" token for the given user in storage.
     */
    public function updateRememberToken(Authenticatable $user, $token): void
    {
        // Remember me non supporté avec LDAP
    }

    /**
     * Retrieve a user by the given credentials.
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (empty($credentials['login']) && empty($credentials['username'])) {
            return null;
        }

        $login = $credentials['login'] ?? $credentials['username'];

        return AuthUser::findByLogin($login);
    }

    /**
     * Validate a user against the given credentials.
     */
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

    /**
     * Rehash the user's password if required and supported.
     */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        // Le mot de passe est géré par LDAP, pas de rehash nécessaire
    }
}
