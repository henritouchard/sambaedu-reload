<?php

namespace App\Models;

use App\LdapModels\LdapUser;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\Access\Authorizable as AuthorizableTrait;

/**
 * Modèle AuthUser Laravel qui wrappe LdapUser (LDAP)
 *
 * Implémente Authenticatable pour être compatible avec le système d'auth Laravel
 * Permet d'utiliser @can, Gate, policies, Auth::user(), etc.
 *
 * Note: Ce modèle est un wrapper autour de LdapUser (app/LdapModels/LdapUser.php)
 * qui contient toute la logique LDAP. AuthUser sert uniquement à l'intégration
 * avec le système d'authentification Laravel.
 */
class AuthUser implements Authenticatable, Authorizable
{
    use AuthorizableTrait;

    /**
     * Le modèle LDAP sous-jacent
     */
    protected ?LdapUser $ldapUser = null;

    /**
     * Le login de l'utilisateur
     */
    protected string $login;

    /**
     * Données cachées de l'utilisateur
     */
    protected array $attributes = [];

    public function __construct(?LdapUser $ldapUser = null, ?string $login = null)
    {
        $this->ldapUser = $ldapUser;
        $this->login = $login ?? $ldapUser?->getLogin() ?? '';

        if ($ldapUser) {
            // Convertir en DTO pour accéder aux méthodes métier
            $user = $ldapUser->toBusinessObject();

            $this->attributes = [
                'login' => $user->login,
                'fullname' => $user->fullname,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'dn' => $user->dn,
                'groups' => $user->groups,
                'rights' => $user->rights, // Noms des groupes de droits
                'role' => $user->role,
                'is_admin' => $user->isAdmin(),
                'is_eleve' => $user->isEleve(),
                'is_prof' => $user->isProf(),
                'is_active' => $user->isActiveAccount(),
            ];
        }
    }

    /**
     * Crée une instance User depuis un login
     */
    public static function findByLogin(string $login): ?static
    {
        $ldapUser = LdapUser::findByLogin($login);

        if (! $ldapUser) {
            return null;
        }

        return new static($ldapUser, $login);
    }

    /**
     * Crée une instance User depuis la session courante
     */
    public static function fromSession(): ?static
    {
        $login = $_SESSION['login'] ?? session('sambaedu_login') ?? null;

        if (! $login) {
            return null;
        }

        return static::findByLogin($login);
    }

    // ============================================
    // Implémentation de Authenticatable
    // ============================================

    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string
    {
        return 'login';
    }

    /**
     * Get the unique identifier for the user.
     */
    public function getAuthIdentifier(): mixed
    {
        return $this->login;
    }

    /**
     * Get the password for the user.
     * Note: Le mot de passe est géré par LDAP, pas stocké localement
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * Get the token value for the "remember me" session.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    /**
     * Set the token value for the "remember me" session.
     */
    public function setRememberToken($value): void
    {
        // Non supporté avec LDAP
    }

    /**
     * Get the column name for the "remember me" token.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * Get the name of the password attribute.
     */
    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    // ============================================
    // Accesseurs pratiques
    // ============================================

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getFullName(): string
    {
        return $this->attributes['fullname'] ?? $this->login;
    }

    public function getFirstName(): ?string
    {
        return $this->attributes['firstname'] ?? null;
    }

    public function getLastName(): ?string
    {
        return $this->attributes['lastname'] ?? null;
    }

    public function getEmail(): ?string
    {
        return $this->attributes['email'] ?? null;
    }

    public function getGroups(): array
    {
        return $this->attributes['groups'] ?? [];
    }

    public function getRole(): string
    {
        return $this->attributes['role'] ?? 'autre';
    }

    public function isAdmin(): bool
    {
        return $this->attributes['is_admin'] ?? false;
    }

    public function isEleve(): bool
    {
        return $this->attributes['is_eleve'] ?? false;
    }

    public function isProf(): bool
    {
        return $this->attributes['is_prof'] ?? false;
    }

    public function isActive(): bool
    {
        return $this->attributes['is_active'] ?? true;
    }

    /**
     * Accès au modèle LDAP sous-jacent si besoin
     */
    public function getLdapUser(): ?LdapUser
    {
        return $this->ldapUser;
    }

    // ============================================
    // Spatie Permission bridge
    // ============================================

    /**
     * Résout le modèle Eloquent User correspondant (cache SQL)
     * Utilisé pour les vérifications Spatie Permission (@can, Gate, etc.)
     */
    public function getEloquentUser(): ?User
    {
        return User::where('login', $this->login)->first();
    }

    /**
     * Pont vers Spatie Permission : vérifie si l'utilisateur a une permission
     *
     * Appelé automatiquement par le Gate::before() de Spatie PermissionRegistrar
     * Délègue au modèle Eloquent User qui porte le trait HasRoles
     */
    public function checkPermissionTo($permission, $guardName = null): bool
    {
        $eloquentUser = $this->getEloquentUser();

        if ($eloquentUser === null) {
            return false;
        }

        return $eloquentUser->checkPermissionTo($permission, $guardName);
    }

    /**
     * Accès magique aux attributs
     */
    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Vérifie si un attribut existe
     */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }
}
