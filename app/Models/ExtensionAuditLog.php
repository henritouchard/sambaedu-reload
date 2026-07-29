<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Story 54.2 (FR36 socle) — Entrée d'audit append-only du cycle de vie d'une
 * extension.
 *
 * Écrite EXCLUSIVEMENT par {@see \App\Services\Extensions\ExtensionLifecycleService}
 * (seul écrivain de `extensions.status` du projet), DANS LA MÊME transaction
 * que la mutation de `status` (atomicité acte ↔ trace). Une transition sans sa
 * ligne d'audit ne peut pas exister ; un no-op (état déjà atteint) n'écrit
 * AUCUNE ligne — le journal trace des transitions réelles, pas des clics.
 *
 * Table append-only : toute tentative d'UPDATE lève une LogicException (calque
 * {@see CapabilityOverrideAuditLog} 29.5 / {@see ControlHubLinkAuditLog} 32.1).
 * Les FK sont `nullOnDelete` pour garder la ligne lisible après suppression des
 * entités référencées ; les colonnes dénormalisées (`extension_key`,
 * `extension_name`, `actor_login`) préservent la lisibilité.
 *
 * Patron MAISON (`QuotaAuditLog::log()` lineage) — Spatie activitylog n'est
 * PAS une dépendance du projet.
 *
 * ⚠️ GARDE-FOU : aucun mot « central ». Vocabulaire « amont » / `Upstream`.
 *
 * @property int $id
 * @property int|null $extension_id
 * @property string $extension_key
 * @property string $extension_name
 * @property string $action           'integrate' | 'uninstall' (string libre, extensible Epic 56)
 * @property int|null $actor_user_id
 * @property string|null $actor_login
 * @property \Carbon\Carbon $created_at
 * @property-read Extension|null $extension
 * @property-read User|null $actor
 */
class ExtensionAuditLog extends Model
{
    /** @var string */
    protected $table = 'extension_audit_logs';

    /** Append-only : un seul created_at, pas d'updated_at. */
    public $timestamps = false;

    // Actions reconnues — dérivées CÔTÉ SERVEUR (jamais du flag client).
    // String libre en base (pas d'enum() DB) : l'Epic 56 étend sans migration.
    public const ACTION_INTEGRATE = 'integrate';
    public const ACTION_UNINSTALL = 'uninstall';

    protected $fillable = [
        'extension_id',
        'extension_key',
        'extension_name',
        'action',
        'actor_user_id',
        'actor_login',
        'created_at',
    ];

    protected $casts = [
        'extension_id' => 'integer',
        'actor_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    // ========================================================================
    // APPEND-ONLY GUARD (calque CapabilityOverrideAuditLog / ControlHubLinkAuditLog)
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
                'ExtensionAuditLog est append-only — UPDATE interdit.'
            );
        }

        return parent::save($options);
    }

    // ========================================================================
    // FABRIQUE (calque CapabilityOverrideAuditLog::log)
    // ========================================================================

    /**
     * Consigne une transition du cycle de vie. Appelée DANS la transaction de
     * la mutation `status` (atomicité acte ↔ trace).
     */
    public static function log(
        ?int $extensionId,
        string $extensionKey,
        string $extensionName,
        string $action,
        ?int $actorUserId,
        ?string $actorLogin,
    ): self {
        return self::create([
            'extension_id' => $extensionId,
            'extension_key' => $extensionKey,
            'extension_name' => $extensionName,
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'actor_login' => $actorLogin,
            'created_at' => now(),
        ]);
    }

    // ========================================================================
    // RELATIONS
    // ========================================================================

    /** L'extension concernée (peut être null si supprimée du registre). */
    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_id');
    }

    /** L'acteur de la transition (peut être null si supprimé). */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeForExtension(Builder $query, int $extensionId): Builder
    {
        return $query->where('extension_id', $extensionId);
    }
}
