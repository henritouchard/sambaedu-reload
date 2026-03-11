<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Représente une tâche ordonnée par le ControlHub et exécutée localement sur cette instance SEFS.
 *
 * @property string $id
 * @property string $controlhub_task_id
 * @property string $name
 * @property string $type
 * @property array|null $payload
 * @property string $status
 * @property array|null $result
 * @property string|null $error_message
 * @property \Carbon\Carbon|null $scheduled_at
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property bool $callback_sent
 * @property \Carbon\Carbon|null $callback_sent_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ControlHubTask extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'controlhub_tasks';

    protected $fillable = [
        'controlhub_task_id',
        'name',
        'type',
        'payload',
        'status',
        'result',
        'error_message',
        'scheduled_at',
        'started_at',
        'completed_at',
        'callback_sent',
        'callback_sent_at',
        'callback_response',
        'callback_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'callback_sent' => 'boolean',
        'callback_sent_at' => 'datetime',
        'callback_response' => 'array',
    ];

    // Statuts possibles
    public const STATUS_RECEIVED = 'received';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    /**
     * Vérifie si la tâche est terminée
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_CANCELED]);
    }

    /**
     * Vérifie si la tâche a réussi
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Vérifie si le callback a été envoyé
     */
    public function isCallbackSent(): bool
    {
        return $this->callback_sent === true;
    }

    /**
     * Vérifie si la tâche nécessite un retry de callback
     */
    public function needsCallbackRetry(): bool
    {
        return $this->isCompleted() && !$this->isCallbackSent();
    }

    /**
     * Marque la tâche comme en file d'attente
     */
    public function markAsQueued(): void
    {
        $this->update(['status' => self::STATUS_QUEUED]);
    }

    /**
     * Marque la tâche comme en cours d'exécution
     */
    public function markAsInProgress(): void
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
    }

    /**
     * Marque la tâche comme réussie
     */
    public function markAsSuccess(array $result = []): void
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'result' => $result,
            'completed_at' => now(),
        ]);
    }

    /**
     * Marque la tâche comme échouée
     */
    public function markAsFailed(string $errorMessage, array $result = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'result' => $result,
            'completed_at' => now(),
        ]);
    }

    /**
     * Marque le callback comme envoyé avec succès
     */
    public function markCallbackSent(array $response = []): void
    {
        $this->update([
            'callback_sent' => true,
            'callback_sent_at' => now(),
            'callback_response' => $response,
            'callback_error' => null,
        ]);
    }

    /**
     * Marque le callback comme échoué
     */
    public function markCallbackFailed(string $error, array $response = []): void
    {
        $this->update([
            'callback_sent' => false,
            'callback_response' => $response,
            'callback_error' => $error,
        ]);
    }

    /**
     * Marque la tâche comme annulée
     */
    public function markAsCanceled(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Vérifie si la tâche peut être annulée (non débutée)
     */
    public function canBeCanceled(): bool
    {
        return in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_QUEUED]);
    }

    /**
     * Vérifie si la tâche est annulée
     */
    public function isCanceled(): bool
    {
        return $this->status === self::STATUS_CANCELED;
    }

    /**
     * Calcule la durée d'exécution en secondes
     */
    public function getExecutionDurationAttribute(): ?int
    {
        if ($this->started_at && $this->completed_at) {
            return $this->started_at->diffInSeconds($this->completed_at);
        }

        return null;
    }

    /**
     * Scope pour les tâches en attente de callback
     */
    public function scopePendingCallback($query)
    {
        return $query->whereIn('status', [self::STATUS_SUCCESS, self::STATUS_FAILED])
            ->where('callback_sent', false);
    }

    /**
     * Scope pour les tâches en cours
     */
    public function scopeInProgress($query)
    {
        return $query->whereIn('status', [self::STATUS_RECEIVED, self::STATUS_QUEUED, self::STATUS_IN_PROGRESS]);
    }
}
