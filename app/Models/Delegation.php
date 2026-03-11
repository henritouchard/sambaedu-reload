<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les délégations de droits scopées par WorkstationGroup
 * 
 * Une délégation accorde (ou retire si is_negative) une permission Spatie
 * à un utilisateur, limitée à un WorkstationGroup physique spécifique.
 * 
 * @property int $id
 * @property int $user_id
 * @property int $workstation_group_id
 * @property int $permission_id
 * @property bool $is_negative
 * @property int|null $granted_by
 * @property \DateTime|null $expires_at
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class Delegation extends Model implements Wireable
{
    protected $table = 'delegations';

    protected $fillable = [
        'user_id',
        'workstation_group_id',
        'permission_id',
        'is_negative',
        'granted_by',
        'expires_at',
    ];

    protected $casts = [
        'is_negative' => 'boolean',
        'expires_at' => 'datetime',
    ];

    // ========================================================================
    // RELATIONS
    // ========================================================================

    /**
     * L'utilisateur bénéficiaire de la délégation
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Le WorkstationGroup physique sur lequel porte la délégation
     */
    public function workstationGroup(): BelongsTo
    {
        return $this->belongsTo(WorkstationGroup::class, 'workstation_group_id');
    }

    /**
     * La permission Spatie associée
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }

    /**
     * L'utilisateur ayant accordé la délégation
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Délégations positives (accorder un droit)
     */
    public function scopePositive(Builder $query): Builder
    {
        return $query->where('is_negative', false);
    }

    /**
     * Délégations négatives (retirer un droit)
     */
    public function scopeNegative(Builder $query): Builder
    {
        return $query->where('is_negative', true);
    }

    /**
     * Délégations non expirées
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Délégations pour un utilisateur donné
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Délégations pour un WorkstationGroup donné
     */
    public function scopeForWorkstationGroup(Builder $query, WorkstationGroup $group): Builder
    {
        return $query->where('workstation_group_id', $group->id);
    }

    /**
     * Délégations pour une permission donnée (par nom)
     */
    public function scopeForPermission(Builder $query, string $permissionName): Builder
    {
        return $query->whereHas('permission', function (Builder $q) use ($permissionName) {
            $q->where('name', $permissionName);
        });
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Vérifie si la délégation est expirée
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Vérifie si la délégation est active (non expirée)
     */
    public function isActive(): bool
    {
        return !$this->isExpired();
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
