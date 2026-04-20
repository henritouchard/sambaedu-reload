<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les groupes d'utilisateurs
 * 
 * Distinct de WorkstationGroup (groupes de machines).
 * Représente les classes, équipes pédagogiques, groupes admin, etc.
 * 
 * @property int $id
 * @property string $name
 * @property string|null $display_name
 * @property string $type
 * @property string|null $ad_dn
 * @property string|null $ad_guid
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class UserGroup extends Model implements Wireable
{
    use HasFactory;

    protected $table = 'user_groups';

    protected $fillable = [
        'name',
        'display_name',
        'type',
        'ad_dn',
        'ad_guid',
    ];

    // ========================================================================
    // RELATIONS
    // ========================================================================

    /**
     * Relation N:N avec les utilisateurs
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_group_user',
            'user_group_id',
            'user_id'
        );
    }

    public function wallpapers(): MorphMany
    {
        return $this->morphMany(Wallpaper::class, 'owner');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('display_name', 'ILIKE', "%{$search}%");
        });
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    public function getDisplayNameOrNameAttribute(): string
    {
        return $this->display_name ?? $this->name;
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
