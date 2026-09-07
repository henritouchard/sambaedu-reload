<?php

namespace App\Models;

use App\Models\Concerns\HasAppCustomizations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Wireable;
use Spatie\Permission\Models\Role;

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
 * @property int|null $rights_profile_id
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
        // Profil de droits PORTÉ par ce groupe (FK
        // `roles.id`, nullable ; le cas normal est l'absence de lien).
        // L'appartenance au groupe matérialise ce rôle Spatie chez ses membres.
        'rights_profile_id',
    ];

    /**
     * Relation N:N avec les utilisateurs
     *
     * `->using(UserGroupUserPivot::class)` active les events
     * Eloquent sur les rows pivot pour l'Observer
     * `UserGroupUserPivotObserver` qui synchronise les ACLs FS lors d'un
     * changement de classe d'élève.
     *
     * `->withPivot('is_head_teacher')` : SANS ce withPivot, Laravel
     * IGNORE l'attribut d'arête lors d'un `sync([$id => ['is_head_teacher' => …]])`
     * (il ne le persiste pas). C'est la relation d'ÉCRITURE du fold de 4.13.
     * Le flag n'est PLUS écrit par le chemin vivant (le
     * read-back ne pose que `role`) : colonne STALE, `withPivot` conservé
     * (fixtures de tests, bases brownfield) jusqu'à la migration destructive
     * post-42.4. `withPivot` n'introduit pas de timestamps (le pivot custom
     * reste `$timestamps=false`).
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
            // `'role'` est l'attribut d'arête VIVANT (le
            // miroir booléen n'est plus écrit). SANS ce withPivot,
            // `sync([$id => ['role'=>…]])` IGNORE silencieusement l'attribut
            // d'arête. `withPivot` n'introduit pas de timestamps (le pivot
            // custom reste `$timestamps=false`).
            ->withPivot('is_head_teacher', 'role');
    }

    /**
     * Profil de droits porté par ce groupe (rôle Spatie).
     *
     * Référencé par **id**, jamais par nom : les profils custom sont
     * renommables depuis `/app/rights-management/profiles/[id]`, un nom stocké
     * deviendrait pendant. `null` dans le cas normal (la très grande majorité
     * des groupes ne porte aucun profil).
     */
    public function rightsProfile(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'rights_profile_id');
    }

    public function wallpapers(): MorphMany
    {
        return $this->morphMany(Wallpaper::class, 'owner');
    }

    /**
     * Répertoires réseau assignés à ce groupe d'utilisateurs.
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

    /**
     * Groupes PORTEURS d'un profil de droits (section principale
     * de l'onglet Profils). Le complément (`whereNull`) est le cas normal.
     */
    public function scopeCarryingProfile(Builder $query): Builder
    {
        return $query->whereNotNull('rights_profile_id');
    }

    /**
     * Ce groupe porte-t-il un profil de droits ?
     *
     * C'est l'information « qualifiant vs regroupement », DÉRIVÉE : aucune
     * colonne de nature, aucune constante.
     */
    public function carriesProfile(): bool
    {
        return $this->rights_profile_id !== null;
    }

    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    public function getDisplayNameOrNameAttribute(): string
    {
        return $this->display_name ?? $this->name;
    }

    public function toLivewire(): array
    {
        return ['id' => $this->id];
    }

    public static function fromLivewire($value): static
    {
        return static::findOrFail($value['id']);
    }
}
