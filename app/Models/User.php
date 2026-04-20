<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'password' => 'hashed',
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
        );
    }

    /**
     * Groupes d'utilisateurs (classes, équipes, etc.)
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            UserGroup::class,
            'user_group_user',
            'user_id',
            'user_group_id'
        );
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
     * Trouve un utilisateur par son login
     */
    public static function findByLogin(string $login): ?self
    {
        return static::where('login', $login)->first();
    }

    /**
     * Vérifie si l'utilisateur a été synchronisé depuis l'AD
     */
    public function isSyncedFromAd(): bool
    {
        return $this->ad_synced_at !== null;
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
