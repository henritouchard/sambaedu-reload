<?php

namespace App\Models;

use App\Models\Concerns\HasAppCustomizations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
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
    use HasAppCustomizations;

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
     *
     * Story 5.2 (D5=A) — `->using(UserGroupUserPivot::class)` active les events
     * Eloquent sur les rows pivot pour l'Observer
     * `UserGroupUserPivotObserver` qui synchronise les ACLs FS lors d'un
     * changement de classe d'élève.
     *
     * Story 4.14 — `->withPivot('is_head_teacher')` : SANS ce withPivot, Laravel
     * IGNORE l'attribut d'arête lors d'un `sync([$id => ['is_head_teacher' => …]])`
     * (il ne le persiste pas). C'est la relation d'ÉCRITURE du fold de 4.13.
     * Story 42.2 (D5) — le flag n'est PLUS écrit par le chemin vivant (le
     * read-back ne pose que `role`) : colonne STALE, `withPivot` conservé
     * (fixtures de tests, bases brownfield) jusqu'à la migration destructive
     * post-42.4. `withPivot` n'introduit pas de timestamps (le pivot custom
     * 5.2 reste `$timestamps=false`).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_group_user',
            'user_group_id',
            'user_id'
        )
            ->using(\App\Models\Pivot\UserGroupUserPivot::class)
            // Story 42.1/42.2 — `'role'` est l'attribut d'arête VIVANT (le
            // miroir booléen 4.14 n'est plus écrit). SANS ce withPivot,
            // `sync([$id => ['role'=>…]])` IGNORE silencieusement l'attribut
            // d'arête. `withPivot` n'introduit pas de timestamps (le pivot
            // custom 5.2 reste `$timestamps=false`).
            ->withPivot('is_head_teacher', 'role');
    }

    public function wallpapers(): MorphMany
    {
        return $this->morphMany(Wallpaper::class, 'owner');
    }

    /**
     * Story 34.1 — répertoires réseau assignés à ce groupe d'utilisateurs.
     * Maille `UserGroup` : la lettre s'affiche pour ses membres ET l'ACL POSIX
     * réelle est dérivée (`group:<unix>` rx/rwx selon `access`). Porte le pivot
     * `access`.
     */
    public function networkShares(): MorphToMany
    {
        return $this->morphToMany(
            NetworkShare::class,
            'assignable',
            'network_share_assignables',
            'assignable_id',
            'network_share_id',
        )->withPivot('access')->withTimestamps();
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
