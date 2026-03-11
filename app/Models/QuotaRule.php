<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les règles de quotas
 * 
 * @property int $id
 * @property string $type user, group, default_eleve, default_prof, default_admin
 * @property string|null $target Nom utilisateur ou groupe AD
 * @property string $partition /home ou /var/sambaedu
 * @property int $quota_soft_mb Quota soft en Mo (0 = illimité)
 * @property int $quota_hard_mb Quota hard en Mo (0 = illimité)
 * @property bool $is_active
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class QuotaRule extends Model implements Wireable
{
    protected $table = 'quota_rules';

    protected $fillable = [
        'type',
        'target',
        'partition',
        'quota_soft_mb',
        'quota_hard_mb',
        'is_active',
    ];

    protected $casts = [
        'quota_soft_mb' => 'integer',
        'quota_hard_mb' => 'integer',
        'is_active' => 'boolean',
    ];

    // Types de règles
    public const TYPE_USER = 'user';
    public const TYPE_GROUP = 'group';
    public const TYPE_DEFAULT_ELEVE = 'default_eleve';
    public const TYPE_DEFAULT_PROF = 'default_prof';
    public const TYPE_DEFAULT_ADMIN = 'default_admin';

    // Partitions supportées
    public const PARTITION_HOME = '/home';
    public const PARTITION_SAMBAEDU = '/var/sambaedu';

    /**
     * Relation vers les logs d'audit
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(QuotaAuditLog::class, 'quota_rule_id');
    }

    /**
     * Scope pour les règles actives
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope pour une partition spécifique
     */
    public function scopeForPartition($query, string $partition)
    {
        return $query->where('partition', $partition);
    }

    /**
     * Scope pour les règles utilisateur
     */
    public function scopeUsers($query)
    {
        return $query->where('type', self::TYPE_USER);
    }

    /**
     * Scope pour les règles groupe
     */
    public function scopeGroups($query)
    {
        return $query->where('type', self::TYPE_GROUP);
    }

    /**
     * Scope pour les politiques par défaut
     */
    public function scopeDefaults($query)
    {
        return $query->whereIn('type', [
            self::TYPE_DEFAULT_ELEVE,
            self::TYPE_DEFAULT_PROF,
            self::TYPE_DEFAULT_ADMIN,
        ]);
    }

    /**
     * Vérifie si c'est une politique par défaut
     */
    public function isDefault(): bool
    {
        return in_array($this->type, [
            self::TYPE_DEFAULT_ELEVE,
            self::TYPE_DEFAULT_PROF,
            self::TYPE_DEFAULT_ADMIN,
        ]);
    }

    /**
     * Vérifie si le quota est illimité
     */
    public function isUnlimited(): bool
    {
        return $this->quota_hard_mb === 0;
    }

    /**
     * Calcule le pourcentage de dépassement autorisé
     */
    public function getOveragePercent(): int
    {
        if ($this->quota_soft_mb === 0) {
            return 0;
        }
        return (int) round(($this->quota_hard_mb - $this->quota_soft_mb) / $this->quota_soft_mb * 100);
    }

    /**
     * Retourne le label du type
     */
    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_USER => 'Utilisateur',
            self::TYPE_GROUP => 'Groupe',
            self::TYPE_DEFAULT_ELEVE => 'Défaut élèves',
            self::TYPE_DEFAULT_PROF => 'Défaut professeurs',
            self::TYPE_DEFAULT_ADMIN => 'Défaut administrateurs',
            default => $this->type,
        };
    }

    /**
     * Retourne le label de la partition
     */
    public function getPartitionLabel(): string
    {
        return match ($this->partition) {
            self::PARTITION_HOME => 'Espace personnel (K:)',
            self::PARTITION_SAMBAEDU => 'Partages (Classes/Docs)',
            default => $this->partition,
        };
    }

    /**
     * Implémentation Wireable pour Livewire
     */
    public function toLivewire(): array
    {
        return $this->toArray();
    }

    public static function fromLivewire($value): static
    {
        if (is_array($value) && isset($value['id'])) {
            return static::find($value['id']) ?? new static($value);
        }
        return new static($value);
    }
}
