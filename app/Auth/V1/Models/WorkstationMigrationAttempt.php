<?php

declare(strict_types=1);

namespace App\Auth\V1\Models;

use Database\Factories\Auth\V1\WorkstationMigrationAttemptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Story 16.11 — AC6.2.
 *
 * Modèle Eloquent pour la table `workstation_migration_attempts`.
 *
 * Trace chaque tentative de migration (succès ou échec) pour permettre :
 *  - le calcul de ratio d'échec (commande `migration:health-check`).
 *  - l'audit forensique (corrélation IP/UA/OS/error_code).
 *
 * Statuts admis :
 *  - `started`  : le poste a appelé `/bootstrap.{cmd,sh}` (workstation_uuid nullable).
 *  - `enrolled` : l'enrôlement a réussi (upsert depuis `EnrollController`).
 *  - `failed`   : l'enrôlement a échoué (uuid_mismatch, LAN block, etc.).
 *
 * Conventions :
 *  - **Pas de FK** (un attempt peut précéder l'existence du status / workstation).
 *  - `error_message` truncate à 1024 chars (mutator) — défense contre stderr
 *    massifs côté poste.
 *
 * @property int $id
 * @property string|null $workstation_uuid
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string $client_ip
 * @property string|null $user_agent
 * @property string|null $os
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WorkstationMigrationAttempt extends Model
{
    use HasFactory;

    /** Statuts admis (enum string ouverte côté DB pour portabilité). */
    public const STATUS_STARTED = 'started';
    public const STATUS_ENROLLED = 'enrolled';
    public const STATUS_FAILED = 'failed';

    /** Limite max de `error_message` (caractères). */
    private const ERROR_MESSAGE_MAX_LENGTH = 1024;

    /** @var string */
    protected $table = 'workstation_migration_attempts';

    /** @var array<int,string> */
    protected $fillable = [
        'workstation_uuid',
        'started_at',
        'finished_at',
        'status',
        'error_code',
        'error_message',
        'client_ip',
        'user_agent',
        'os',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Attempts réussis (`status='enrolled'`).
     *
     * @param Builder<self> $query
     */
    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ENROLLED);
    }

    /**
     * Attempts échoués (`status='failed'`).
     *
     * @param Builder<self> $query
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Attempts récents (started_at > now - $days jours).
     *
     * @param Builder<self> $query
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('started_at', '>', Carbon::now()->subDays($days));
    }

    /**
     * Mutator `error_message` : truncate à 1024 chars max pour éviter qu'un
     * stderr massif côté poste fasse exploser la row.
     */
    public function setErrorMessageAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['error_message'] = null;

            return;
        }
        $this->attributes['error_message'] = mb_substr($value, 0, self::ERROR_MESSAGE_MAX_LENGTH);
    }

    /**
     * Override : factory sous sous-namespace `Auth\V1`.
     */
    protected static function newFactory(): Factory
    {
        return WorkstationMigrationAttemptFactory::new();
    }
}
