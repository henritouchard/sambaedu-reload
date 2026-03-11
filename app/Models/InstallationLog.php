<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les logs d'installation d'applications
 * 
 * Trace chaque tentative d'installation/mise à jour d'une application
 * depuis le dépôt vers le serveur SE4FS.
 * 
 * @property int $id
 * @property int $application_id
 * @property InstallationStatus $status
 * @property string|null $version
 * @property string|null $message
 * @property int $progress
 * @property int $downloaded_bytes
 * @property int $total_bytes
 * @property string|null $sha256_computed
 * @property bool $sha256_verified
 * @property string|null $initiated_by
 * @property \DateTime|null $started_at
 * @property \DateTime|null $completed_at
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class InstallationLog extends Model implements Wireable
{
    protected $table = 'installation_logs';

    protected $fillable = [
        'application_id',
        'status',
        'version',
        'message',
        'progress',
        'downloaded_bytes',
        'total_bytes',
        'sha256_computed',
        'sha256_verified',
        'initiated_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'application_id' => 'integer',
        'status' => InstallationStatus::class,
        'progress' => 'integer',
        'downloaded_bytes' => 'integer',
        'total_bytes' => 'integer',
        'sha256_verified' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Relation avec l'application
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Scope pour les logs en cours
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            InstallationStatus::Success,
            InstallationStatus::Failed,
        ]);
    }

    /**
     * Scope pour les logs terminés
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InstallationStatus::Success,
            InstallationStatus::Failed,
        ]);
    }

    /**
     * Vérifie si le log est terminé
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Retourne la durée de l'installation
     */
    public function getDurationAttribute(): ?string
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }

        $diff = $this->started_at->diff($this->completed_at);

        if ($diff->h > 0) {
            return $diff->format('%hh %imin %ss');
        }

        if ($diff->i > 0) {
            return $diff->format('%imin %ss');
        }

        return $diff->format('%ss');
    }

    /**
     * Retourne la progression formatée du téléchargement
     */
    public function getDownloadProgressAttribute(): string
    {
        if ($this->total_bytes <= 0) {
            return '0%';
        }

        return round(($this->downloaded_bytes / $this->total_bytes) * 100) . '%';
    }

    /**
     * Sérialise le modèle pour Livewire
     */
    public function toLivewire(): array
    {
        return ['id' => $this->id];
    }

    /**
     * Désérialise depuis Livewire
     */
    public static function fromLivewire($value): static
    {
        return static::findOrFail($value['id']);
    }
}
