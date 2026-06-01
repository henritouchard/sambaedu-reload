<?php

namespace App\Models;

use App\Models\Concerns\HasAppCustomizations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Livewire\Wireable;

/**
 * Modèle Eloquent User pour le système de droits SambaEdu 4.6
 * 
 * Remplace progressivement AuthUser (wrapper LDAP).
 * Utilise la table PostgreSQL 'users' comme cache SQL des utilisateurs AD.
 * Porte le trait HasRoles de Spatie pour la gestion des permissions et rôles.
 * 
 * Transition prévue :
 * - Phase A : AD = source de vérité, cette table = cache
 * - Phase B : Double-écriture, SQL prend le relais
 * - Phase C : SQL = source de vérité, AD = proxy Windows
 * 
 * @property int $id
 * @property string $login
 * @property string|null $password
 * @property string|null $fullname
 * @property string|null $firstname
 * @property string|null $lastname
 * @property string|null $email
 * @property string|null $dn
 * @property string|null $ad_guid
 * @property string $role
 * @property string|null $school_code
 * @property string|null $school_name
 * @property bool $is_active
 * @property array|null $ad_right_profiles
 * @property int $ad_rights_bitmask
 * @property \DateTime|null $ad_synced_at
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class User extends Authenticatable implements Wireable
{
    use HasFactory;
    use HasRoles;
    use HasAppCustomizations;

    protected $guard_name = 'web';

    protected $table = 'users';

    protected $fillable = [
        'login',
        'password',
        'fullname',
        'firstname',
        'lastname',
        'email',
        'phone',
        'description',
        'dn',
        'ad_guid',
        'role',
        'school_code',
        'school_name',
        'is_active',
        'ad_right_profiles',
        'ad_rights_bitmask',
        'ad_synced_at',
        'pwd_reset_at',
        'password_changed_at',
        'quota_snapshot',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ad_right_profiles' => 'array',
        'ad_rights_bitmask' => 'integer',
        'ad_synced_at' => 'datetime',
        'pwd_reset_at' => 'datetime',
        // Timestamp du dernier changement de mdp effectif (depuis pwdLastSet AD).
        // NULL = jamais changé ou pwdLastSet=0 (filtre « mdp par défaut » D3/D7 — story 14.4).
        'password_changed_at' => 'datetime',
        'password' => 'hashed',
        // Snapshot quota quotidien (story 5.1b) — structure documentée dans
        // la migration `add_quota_snapshot_to_users_table` et dans la
        // commande `QuotaSnapshotCommand`.
        'quota_snapshot' => 'array',
    ];

    // ========================================================================
    // RELATIONS
    // ========================================================================

    /**
     * Relation N:N avec les groupes d'utilisateurs
     */
    public function userGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            UserGroup::class,
            'user_group_user',
            'user_id',
            'user_group_id'
        )->using(\App\Models\Pivot\UserGroupUserPivot::class);
    }

    /**
     * Groupes d'utilisateurs (classes, équipes, etc.)
     *
     * Story 5.2 (D5=A) — `->using(UserGroupUserPivot::class)` permet à
     * `App\Observers\UserGroupUserPivotObserver` d'écouter les events
     * `created`/`deleted` sur les rows pivot. Aucune autre conséquence
     * fonctionnelle (le pivot ne porte ni timestamps ni colonnes additionnelles).
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            UserGroup::class,
            'user_group_user',
            'user_id',
            'user_group_id'
        )->using(\App\Models\Pivot\UserGroupUserPivot::class);
    }

    /**
     * Délégations accordées à cet utilisateur
     */
    public function delegations(): HasMany
    {
        return $this->hasMany(Delegation::class, 'user_id');
    }

    /**
     * Délégations accordées par cet utilisateur
     */
    public function grantedDelegations(): HasMany
    {
        return $this->hasMany(Delegation::class, 'granted_by');
    }

    public function wallpapers(): MorphMany
    {
        return $this->morphMany(Wallpaper::class, 'owner');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($search) {
            $q->where('login', 'ILIKE', "%{$search}%")
                ->orWhere('fullname', 'ILIKE', "%{$search}%")
                ->orWhere('firstname', 'ILIKE', "%{$search}%")
                ->orWhere('lastname', 'ILIKE', "%{$search}%")
                ->orWhere('email', 'ILIKE', "%{$search}%");
        });
    }

    public function scopeSyncedFromAd(Builder $query): Builder
    {
        return $query->whereNotNull('ad_synced_at');
    }

    public function scopeExternal(Builder $query, string $currentSchoolCode): Builder
    {
        return $query->whereNotNull('school_code')
            ->where('school_code', '!=', '0')
            ->whereRaw('LOWER(school_code) != LOWER(?)', [$currentSchoolCode]);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Trouve un utilisateur par son login.
     *
     * Story 4.10 (correctif review #14) — comparaison case-insensitive.
     * AD est case-insensitive sur sAMAccountName ; Postgres `=` est
     * case-sensitive. Sans `LOWER()`, un user POSTant `JDOE` ne matche pas
     * l'enregistrement `jdoe` → faux `permission_denied` lors d'un POST iPXE
     * alors que le bind LDAP a réussi.
     */
    public static function findByLogin(string $login): ?self
    {
        return static::whereRaw('LOWER(login) = ?', [strtolower($login)])->first();
    }

    /**
     * Vérifie si l'utilisateur a été synchronisé depuis l'AD
     */
    public function isSyncedFromAd(): bool
    {
        return $this->ad_synced_at !== null;
    }

    // ========================================================================
    // Helpers d'authentification (ex-AuthUser wrapper)
    //
    // Why: le guard web injecte désormais directement l'Eloquent User (cf.
    // `LdapUserProvider`). Ces méthodes exposent l'API historique d'`AuthUser`
    // pour que les consommateurs (@auth->user()->isAdmin(), getLogin(), …)
    // continuent de fonctionner sans avoir à résoudre un Eloquent à la main.
    // Les flags dynamiques (admin/prof/eleve) sont lazy-loadés depuis LDAP
    // via `ldapBusinessObject()` avec cache par-request.
    // ========================================================================

    /** @var array<string, mixed> Cache LDAP par login, scope request. */
    private static array $ldapCache = [];

    public function getLogin(): string
    {
        return (string) $this->login;
    }

    public function getFullName(): string
    {
        return $this->fullname !== null && $this->fullname !== ''
            ? $this->fullname
            : (string) $this->login;
    }

    public function getFirstName(): ?string
    {
        return $this->firstname;
    }

    public function getLastName(): ?string
    {
        return $this->lastname;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role ?? 'autre';
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->ldapBusinessObject()?->isAdmin() ?? false;
    }

    public function isProf(): bool
    {
        return $this->ldapBusinessObject()?->isProf() ?? ($this->role === 'prof');
    }

    public function isEleve(): bool
    {
        return $this->ldapBusinessObject()?->isEleve() ?? ($this->role === 'eleve');
    }

    public function getGroups(): array
    {
        $bo = $this->ldapBusinessObject();
        return is_object($bo) && isset($bo->groups) && is_array($bo->groups) ? $bo->groups : [];
    }

    /**
     * Charge (avec cache request-scope) le modèle LDAP sous-jacent.
     * Retourne null si le user SQL n'a plus de correspondance AD.
     */
    public function getLdapUser(): ?\App\LdapModels\LdapUser
    {
        $key = 'ldap:' . $this->login;
        if (!array_key_exists($key, self::$ldapCache)) {
            self::$ldapCache[$key] = \App\LdapModels\LdapUser::findByLogin((string) $this->login);
        }
        return self::$ldapCache[$key];
    }

    /**
     * Business object LDAP (DTO métier avec isAdmin/isProf/groups/…), lazy + cache.
     */
    public function ldapBusinessObject(): ?object
    {
        $key = 'bo:' . $this->login;
        if (!array_key_exists($key, self::$ldapCache)) {
            self::$ldapCache[$key] = $this->getLdapUser()?->toBusinessObject();
        }
        return self::$ldapCache[$key];
    }

    /**
     * Vérifie si l'utilisateur est externe (rattaché à un autre établissement)
     */
    public function isExternal(): bool
    {
        if (empty(trim($this->school_code ?? '')) || $this->school_code === '0') {
            return false;
        }

        $currentCode = \App\Facades\SEConfig::getCurrentEstablishmentCode();

        if (empty($currentCode) || $currentCode === '0') {
            return false;
        }

        return strtolower($this->school_code) !== strtolower($currentCode);
    }

    /**
     * Retourne le nom d'affichage (fullname ou login)
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->fullname ?? $this->login;
    }

    /**
     * Retourne le groupe de rôle (Eleves, Profs, Administratifs) de l'utilisateur.
     */
    public function roleGroup(): ?UserGroup
    {
        return $this->groups()->where('type', 'role')->first();
    }

    /**
     * Retourne le groupe de fonction (Direction, Agent, etc.) de l'utilisateur.
     */
    public function functionGroup(): ?UserGroup
    {
        return $this->groups()->where('type', 'function')->first();
    }

    // ========================================================================
    // WIREABLE (Livewire)
    // ========================================================================

    public function toLivewire(): array
    {
        return ['id' => $this->id];
    }

    public static function fromLivewire($value): static
    {
        return static::findOrFail($value['id']);
    }
}
