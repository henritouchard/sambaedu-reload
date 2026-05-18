<?php

declare(strict_types=1);

namespace App\ScriptsOs\Models;

use App\ScriptsOs\Enums\ScriptExecutionAction;
use App\ScriptsOs\Enums\ScriptExecutionOs;
use App\ScriptsOs\Enums\ScriptExecutionSource;
use App\ScriptsOs\Enums\ScriptExecutionStatus;
use Database\Factories\ScriptsOs\ScriptExecutionLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Story 16.12 — AC1.2 / D2.
 *
 * Modèle Eloquent pour la table `script_execution_logs`. Représente une
 * exécution unitaire d'un script côté poste (Windows ou Linux), traçée
 * via le wrapper rendu par `WrapperScriptRenderer` puis POST sur
 * `/api/v1/script-execution-logs`.
 *
 * Conventions :
 *
 *  - **UUID pk** : `id` généré côté Laravel via `Str::uuid()->toString()`
 *    dans l'event `creating` (portabilité SQLite testing — pas de
 *    dépendance `gen_random_uuid()` pgsql).
 *  - **Casts enums** : sérialisation/désérialisation transparente
 *    via `protected $casts = ['action' => ScriptExecutionAction::class, ...]`.
 *  - **Mutators UTF-8 safe** : `setStdoutExcerptAttribute` /
 *    `setStderrExcerptAttribute` truncate ≤ 8192 bytes via `mb_strcut`
 *    + marqueur `[...truncated]`. **PAS** `substr` qui peut casser UTF-8.
 *  - **8 scopes** pour requêtes filtrées (UI Livewire + stats service).
 *  - Factory sous le sous-namespace `Database\Factories\ScriptsOs\`
 *    (pattern iso 16.10 / 16.11).
 *
 * @property string $id
 * @property string $workstation_uuid
 * @property int|null $script_id
 * @property ScriptExecutionSource $script_source
 * @property ScriptExecutionAction $action
 * @property ScriptExecutionOs $os
 * @property ScriptExecutionStatus $status
 * @property int|null $exit_code
 * @property string|null $stdout_excerpt
 * @property string|null $stderr_excerpt
 * @property Carbon $started_at
 * @property int $duration_ms
 * @property Carbon $reported_at
 * @property string|null $correlation_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ScriptExecutionLog extends Model
{
    use HasFactory;

    /** @var string */
    protected $table = 'script_execution_logs';

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /**
     * Max bytes pour stdout/stderr (mutator truncate). Garde-fou applicatif —
     * la colonne DB est `text` (illimité côté pgsql).
     */
    public const EXCERPT_MAX_BYTES = 8192;

    /**
     * Marqueur (UTF-8 safe, ≤ 16 bytes) appendé lors d'une truncation.
     */
    public const TRUNCATION_MARKER = "\n[...truncated]\n";

    /** @var array<int,string> */
    protected $fillable = [
        'id',
        'workstation_uuid',
        'script_id',
        'script_source',
        'action',
        'os',
        'status',
        'exit_code',
        'stdout_excerpt',
        'stderr_excerpt',
        'started_at',
        'duration_ms',
        'reported_at',
        'correlation_id',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'started_at' => 'datetime',
        'reported_at' => 'datetime',
        'exit_code' => 'integer',
        'duration_ms' => 'integer',
        'action' => ScriptExecutionAction::class,
        'os' => ScriptExecutionOs::class,
        'status' => ScriptExecutionStatus::class,
        'script_source' => ScriptExecutionSource::class,
    ];

    /**
     * Boot — auto-génération UUID `id` côté Laravel (portabilité SQLite).
     */
    protected static function booted(): void
    {
        static::creating(static function (self $log): void {
            if (empty($log->id)) {
                $log->id = (string) Str::uuid();
            }
        });
    }

    // -------------------------------------------------------------------------
    // Mutators UTF-8 safe — truncate stdout/stderr à 8192 bytes max
    // -------------------------------------------------------------------------

    /**
     * Truncate UTF-8 safe à 8192 bytes max. Appendu `\n[...truncated]\n`
     * (≤ 16 bytes) → on découpe à `EXCERPT_MAX_BYTES - strlen(marker)`
     * pour rester sous la limite totale.
     *
     * `mb_strcut($value, 0, $bytes)` : découpe au byte, sans casser un
     * caractère multibyte (ex: `🚀` = 4 bytes).
     */
    public function setStdoutExcerptAttribute(?string $value): void
    {
        $this->attributes['stdout_excerpt'] = $this->truncateExcerpt($value);
    }

    public function setStderrExcerptAttribute(?string $value): void
    {
        $this->attributes['stderr_excerpt'] = $this->truncateExcerpt($value);
    }

    private function truncateExcerpt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $bytes = strlen($value);
        if ($bytes <= self::EXCERPT_MAX_BYTES) {
            return $value;
        }

        $markerLen = strlen(self::TRUNCATION_MARKER);
        $cutBytes = self::EXCERPT_MAX_BYTES - $markerLen;

        // mb_strcut respecte les frontières UTF-8 sans corrompre les
        // caractères multibyte. Le 4ème argument est encoding ; on force
        // UTF-8 (entrée wrapper).
        $cut = mb_strcut($value, 0, $cutBytes, 'UTF-8');

        return $cut . self::TRUNCATION_MARKER;
    }

    /**
     * Normalisation lowercase iso 16.11 (workstation_uuid stocké lowercase).
     */
    public function setWorkstationUuidAttribute(?string $value): void
    {
        $this->attributes['workstation_uuid'] = $value === null
            ? null
            : strtolower($value);
    }

    // -------------------------------------------------------------------------
    // Scopes (D2 — 8 scopes pour UI Livewire + stats service)
    // -------------------------------------------------------------------------

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', ScriptExecutionStatus::FAILURE->value);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', ScriptExecutionStatus::SUCCESS->value);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('started_at', '>=', Carbon::now()->subHours($hours));
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForWorkstation(Builder $query, string $uuid): Builder
    {
        return $query->where('workstation_uuid', strtolower($uuid));
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForScript(Builder $query, int $scriptId): Builder
    {
        return $query->where('script_id', $scriptId);
    }

    /**
     * @param Builder<self> $query
     * @param string|array<int,string> $action
     * @return Builder<self>
     */
    public function scopeForAction(Builder $query, string|array $action): Builder
    {
        return $query->whereIn('action', (array) $action);
    }

    /**
     * @param Builder<self> $query
     * @param string|array<int,string> $status
     * @return Builder<self>
     */
    public function scopeForStatus(Builder $query, string|array $status): Builder
    {
        return $query->whereIn('status', (array) $status);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeBetweenDates(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('started_at', [$from, $to]);
    }

    /**
     * Override : factory sous sous-namespace `Database\Factories\ScriptsOs\`.
     */
    protected static function newFactory(): Factory
    {
        return ScriptExecutionLogFactory::new();
    }
}
