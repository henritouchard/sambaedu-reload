<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Story 7.1 — Entrée d'historique (audit trail) d'une opération de délégation.
 *
 * Table append-only : toute tentative d'UPDATE lève une LogicException.
 * Les FK sont en ON DELETE SET NULL pour garder l'historique lisible après
 * suppression des entités référencées. Le `permission_name` est une string
 * (pas de FK vers spatie permissions) pour ne pas casser lors d'un renommage.
 *
 * @property int $id
 * @property int|null $actor_user_id
 * @property int|null $target_user_id
 * @property int|null $workstation_group_id
 * @property string $permission_name
 * @property string $action           'grant' | 'revoke' | 'negate' | 'expire'
 * @property bool $is_negative
 * @property array|null $context
 * @property \Carbon\Carbon $created_at
 */
class DelegationHistory extends Model
{
    /** @var string */
    protected $table = 'delegation_history';

    /** Actions métier reconnues. */
    public const ACTION_GRANT = 'grant';
    public const ACTION_REVOKE = 'revoke';
    public const ACTION_NEGATE = 'negate';
    public const ACTION_EXPIRE = 'expire';

    public const ACTIONS = [
        self::ACTION_GRANT,
        self::ACTION_REVOKE,
        self::ACTION_NEGATE,
        self::ACTION_EXPIRE,
    ];

    /** Pas d'updated_at : la table n'a qu'un created_at. */
    public const UPDATED_AT = null;

    /**
     * Story 7.1 — Review #2 : `created_at` retiré de $fillable pour empêcher
     * toute entrée antidatée. Eloquent gère le timestamp automatiquement
     * à l'insertion (append-only). Les appelants doivent passer par le
     * service `DelegationHistoryService::log()` qui n'inclut pas `created_at`
     * dans son payload.
     */
    protected $fillable = [
        'actor_user_id',
        'target_user_id',
        'workstation_group_id',
        'permission_name',
        'action',
        'is_negative',
        'context',
    ];

    protected $casts = [
        'actor_user_id' => 'integer',
        'target_user_id' => 'integer',
        'workstation_group_id' => 'integer',
        'is_negative' => 'boolean',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    // ========================================================================
    // APPEND-ONLY GUARD
    // ========================================================================

    /**
     * Bloque tout UPDATE : la table est append-only.
     *
     * Les INSERT restent autorisés ($this->exists vaut false tant que la ligne
     * n'a pas été persistée). Une fois la ligne écrite, ->save() / ->update()
     * lève une LogicException.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException(
                'DelegationHistory est append-only — UPDATE interdit.'
            );
        }

        return parent::save($options);
    }

    // ========================================================================
    // RELATIONS
    // ========================================================================

    /**
     * L'utilisateur qui a effectué l'opération (peut être null si supprimé).
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * L'utilisateur cible de la délégation (peut être null si supprimé).
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * Le WorkstationGroup concerné (peut être null si supprimé).
     */
    public function workstationGroup(): BelongsTo
    {
        return $this->belongsTo(WorkstationGroup::class, 'workstation_group_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeForTarget(Builder $query, User $target): Builder
    {
        return $query->where('target_user_id', $target->id);
    }

    public function scopeForActor(Builder $query, User $actor): Builder
    {
        return $query->where('actor_user_id', $actor->id);
    }

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeForPeriod(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function scopeForWorkstationGroup(Builder $query, WorkstationGroup $group): Builder
    {
        return $query->where('workstation_group_id', $group->id);
    }
}
