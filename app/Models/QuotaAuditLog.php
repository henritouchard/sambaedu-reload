<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle Eloquent pour les logs d'audit des quotas
 * 
 * @property int $id
 * @property int|null $quota_rule_id
 * @property string $action create, update, delete, apply
 * @property string $performed_by Utilisateur ayant effectué l'action
 * @property string $target_type user, group, default
 * @property string|null $target_name Nom utilisateur ou groupe
 * @property string $partition /home ou /var/sambaedu
 * @property array|null $old_values Valeurs avant modification
 * @property array|null $new_values Valeurs après modification
 * @property bool $fs_applied Quota appliqué sur le filesystem
 * @property string|null $fs_error Erreur lors de l'application
 * @property \DateTime $created_at
 */
class QuotaAuditLog extends Model
{
    protected $table = 'quota_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'quota_rule_id',
        'action',
        'performed_by',
        'target_type',
        'target_name',
        'partition',
        'old_values',
        'new_values',
        'fs_applied',
        'fs_error',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'fs_applied' => 'boolean',
        'created_at' => 'datetime',
    ];

    // Actions possibles
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_APPLY = 'apply';

    /**
     * Relation vers la règle de quota
     */
    public function quotaRule(): BelongsTo
    {
        return $this->belongsTo(QuotaRule::class, 'quota_rule_id');
    }

    /**
     * Scope pour une action spécifique
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope pour un utilisateur spécifique
     */
    public function scopeByUser($query, string $username)
    {
        return $query->where('performed_by', $username);
    }

    /**
     * Scope pour une cible spécifique
     */
    public function scopeForTarget($query, string $targetName)
    {
        return $query->where('target_name', $targetName);
    }

    /**
     * Retourne le label de l'action
     */
    public function getActionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_CREATE => 'Création',
            self::ACTION_UPDATE => 'Modification',
            self::ACTION_DELETE => 'Suppression',
            self::ACTION_APPLY => 'Application',
            default => $this->action,
        };
    }

    /**
     * Crée un log d'audit
     */
    public static function log(
        string $action,
        string $performedBy,
        string $targetType,
        ?string $targetName,
        string $partition,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $quotaRuleId = null,
        bool $fsApplied = false,
        ?string $fsError = null
    ): self {
        return self::create([
            'quota_rule_id' => $quotaRuleId,
            'action' => $action,
            'performed_by' => $performedBy,
            'target_type' => $targetType,
            'target_name' => $targetName,
            'partition' => $partition,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'fs_applied' => $fsApplied,
            'fs_error' => $fsError,
            'created_at' => now(),
        ]);
    }
}
