<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Story 29.5 (NFR5) — Entrée d'audit append-only d'un override de capacité par
 * parc (`workstationGroup`).
 *
 * Consigne chaque pose / mise à jour / retrait d'un override sur la surface
 * `capabilities-tab`, en distinguant l'override d'un item imposé-PERMISSIF amont
 * (`upstream_status = permissive`) d'un override purement LOCAL
 * (`upstream_status = local`). La trace est écrite DANS LA MÊME transaction que la
 * mutation `capability_assignments` (atomicité acte ↔ trace).
 *
 * Table append-only : toute tentative d'UPDATE lève une LogicException (calque
 * {@see DelegationHistory}). Les FK sont `nullOnDelete` pour garder la ligne
 * lisible après suppression des entités référencées ; les colonnes dénormalisées
 * (`actor_login`, `capability_label`, `scope_label`) préservent la lisibilité.
 *
 * Patron MAISON (`QuotaAuditLog::log()` / `DelegationHistory`) — Spatie
 * activitylog n'est PAS une dépendance du projet.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». Vocabulaire « amont » / `Upstream`.
 * [Source: prd-contrat-manage-se5.md#R3]
 *
 * @property int $id
 * @property int|null $actor_user_id
 * @property string|null $actor_login
 * @property string $action            'create' | 'update' | 'delete'
 * @property int|null $capability_id
 * @property string $capability_label
 * @property string $assignable_type
 * @property int $assignable_id
 * @property string|null $scope_label
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string $upstream_status   'permissive' | 'local'
 * @property \Carbon\Carbon $created_at
 */
class CapabilityOverrideAuditLog extends Model
{
    /** @var string */
    protected $table = 'capability_override_audit_logs';

    /** Append-only : un seul created_at, pas d'updated_at. */
    public $timestamps = false;

    // Actions reconnues — dérivées CÔTÉ SERVEUR (jamais du flag client).
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    // Statut amont de l'override au moment de l'acte.
    public const UPSTREAM_PERMISSIVE = 'permissive';
    public const UPSTREAM_LOCAL = 'local';

    protected $fillable = [
        'actor_user_id',
        'actor_login',
        'action',
        'capability_id',
        'capability_label',
        'assignable_type',
        'assignable_id',
        'scope_label',
        'old_value',
        'new_value',
        'upstream_status',
        'created_at',
    ];

    protected $casts = [
        'actor_user_id' => 'integer',
        'capability_id' => 'integer',
        'assignable_id' => 'integer',
        'created_at' => 'datetime',
    ];

    // ========================================================================
    // APPEND-ONLY GUARD (calque DelegationHistory)
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
                'CapabilityOverrideAuditLog est append-only — UPDATE interdit.'
            );
        }

        return parent::save($options);
    }

    // ========================================================================
    // FABRIQUE (calque QuotaAuditLog::log)
    // ========================================================================

    /**
     * Consigne un événement d'audit d'override. Appelé DANS la transaction de la
     * mutation `capability_assignments` (atomicité acte ↔ trace).
     */
    public static function log(
        string $action,
        ?int $actorUserId,
        ?string $actorLogin,
        ?int $capabilityId,
        string $capabilityLabel,
        string $assignableType,
        int $assignableId,
        ?string $scopeLabel,
        ?string $oldValue,
        ?string $newValue,
        string $upstreamStatus,
    ): self {
        return self::create([
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'actor_login' => $actorLogin,
            'capability_id' => $capabilityId,
            'capability_label' => $capabilityLabel,
            'assignable_type' => $assignableType,
            'assignable_id' => $assignableId,
            'scope_label' => $scopeLabel,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'upstream_status' => $upstreamStatus,
            'created_at' => now(),
        ]);
    }

    // ========================================================================
    // RELATIONS
    // ========================================================================

    /** L'utilisateur qui a effectué l'override (peut être null si supprimé). */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** La capacité concernée (peut être null si supprimée). */
    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class, 'capability_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeForCapability(Builder $query, int $capabilityId): Builder
    {
        return $query->where('capability_id', $capabilityId);
    }
}
