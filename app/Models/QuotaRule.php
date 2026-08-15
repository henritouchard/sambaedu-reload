<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les règles de quotas
 * 
 * @property int $id
 * @property string $type user, group, default
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

    /**
     * LE DÉFAUT D'INSTANCE — une ligne par partition, `target` à `null`.
     *
     * ---------------------------------------------------------------------------
     * **IL A REMPLACÉ QUATRE TYPES** (`…_eleve`, `…_prof`, `…_admin`,
     * `…_itinerant`), et ce n'est pas une simplification cosmétique : ces quatre-là
     * n'étaient attachés à RIEN. Le type retenu pour un compte se devinait par deux
     * heuristiques divergentes sur des noms de groupes — l'une côté fiche
     * utilisateur, l'autre côté service — si bien qu'un groupe `profs-techno`
     * donnait un plafond d'enseignant d'un côté et rien de l'autre, et qu'un groupe
     * `administration` basculait en administrateur par accident.
     *
     * Le plafond par défaut est désormais un réglage d'INSTANCE : il s'applique à
     * tout compte qu'aucune règle nominative ni règle de groupe ne couvre. Un
     * budget plus large pour une population donnée se pose en RÈGLE DE GROUPE —
     * qui, elle, est explicite et se voit.
     *
     * La bascule des lignes existantes est faite UNE FOIS par la migration
     * `2026_08_15_100000_collapse_quota_profile_defaults`.
     * ---------------------------------------------------------------------------
     */
    public const TYPE_DEFAULT = 'default';

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
     * Scope pour le défaut d'instance (une ligne par partition).
     */
    public function scopeDefaults($query)
    {
        return $query->where('type', self::TYPE_DEFAULT);
    }

    /**
     * Vérifie si c'est le défaut d'instance.
     */
    public function isDefault(): bool
    {
        return $this->type === self::TYPE_DEFAULT;
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
            self::TYPE_DEFAULT => 'Défaut',
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
