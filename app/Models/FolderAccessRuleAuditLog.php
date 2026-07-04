<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Story 36.4 (D7) — Entrée d'audit APPEND-ONLY d'une règle d'accès aux dossiers.
 *
 * Consigne chaque create / update / delete (activation-désactivation et mutation
 * d'assignations = `update`). La trace est écrite DANS LA MÊME transaction que la
 * mutation `folder_access_rules` (atomicité acte ↔ trace). Calque
 * {@see CapabilityOverrideAuditLog} (patron MAISON `QuotaAuditLog` /
 * `DelegationHistory` — Spatie activitylog ABSENT du projet).
 *
 * Table append-only : toute tentative d'UPDATE lève une LogicException. Les FK
 * sont `nullOnDelete` ; `actor_login` / `rule_label` dénormalisés préservent la
 * lisibilité après suppression. `old_state`/`new_state` = snapshots JSON (champs
 * + ids de parcs assignés).
 *
 * @property int $id
 * @property int|null $actor_user_id
 * @property string|null $actor_login
 * @property string $action            'create' | 'update' | 'delete'
 * @property int|null $rule_id
 * @property string $rule_label
 * @property array<string,mixed>|null $old_state
 * @property array<string,mixed>|null $new_state
 * @property \Illuminate\Support\Carbon $created_at
 */
class FolderAccessRuleAuditLog extends Model
{
    protected $table = 'folder_access_rule_audit_logs';

    /** Append-only : un seul created_at, pas d'updated_at. */
    public $timestamps = false;

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    protected $fillable = [
        'actor_user_id',
        'actor_login',
        'action',
        'rule_id',
        'rule_label',
        'old_state',
        'new_state',
        'created_at',
    ];

    protected $casts = [
        'actor_user_id' => 'integer',
        'rule_id' => 'integer',
        'old_state' => 'array',
        'new_state' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Bloque tout UPDATE : la table est append-only (calque
     * {@see CapabilityOverrideAuditLog::save()}). Les INSERT restent autorisés
     * ($this->exists vaut false tant que la ligne n'est pas persistée).
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException(
                'FolderAccessRuleAuditLog est append-only — UPDATE interdit.'
            );
        }

        return parent::save($options);
    }

    /**
     * Consigne un événement d'audit. Appelé DANS la transaction de la mutation
     * `folder_access_rules` (atomicité acte ↔ trace).
     *
     * @param  array<string,mixed>|null  $oldState
     * @param  array<string,mixed>|null  $newState
     */
    public static function log(
        string $action,
        ?int $actorUserId,
        ?string $actorLogin,
        ?int $ruleId,
        string $ruleLabel,
        ?array $oldState,
        ?array $newState,
    ): self {
        return self::create([
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'actor_login' => $actorLogin,
            'rule_id' => $ruleId,
            'rule_label' => $ruleLabel,
            'old_state' => $oldState,
            'new_state' => $newState,
            'created_at' => now(),
        ]);
    }

    /** L'utilisateur qui a effectué l'acte (null si supprimé). */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** La règle concernée (null si supprimée). */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(FolderAccessRule::class, 'rule_id');
    }

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeForRule(Builder $query, int $ruleId): Builder
    {
        return $query->where('rule_id', $ruleId);
    }
}
