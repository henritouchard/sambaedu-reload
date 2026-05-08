<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 15.5 — Modèle Eloquent de la table `wpkg_deployments` (créée
 * par 15.1 mais sans modèle Eloquent).
 *
 * Représente un déploiement administré (clone parc, bulk catégorie, etc.)
 * avec son périmètre cible (`target_scope`) et son agrégat de statuts
 * (`summary`).
 *
 * @property string $id UUID
 * @property int|null $triggered_by FK users.id (nullable on delete)
 * @property \Illuminate\Support\Carbon $triggered_at
 * @property array<string,mixed>|null $target_scope
 * @property string $status pending|running|completed|partial|failed
 * @property array<string,mixed>|null $summary
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User|null $triggeredBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WpkgDeploymentWorkstationStatus> $workstationStatuses
 */
final class WpkgDeployment extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'wpkg_deployments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'triggered_by',
        'triggered_at',
        'target_scope',
        'status',
        'summary',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'target_scope' => 'array',
        'summary' => 'array',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function workstationStatuses(): HasMany
    {
        return $this->hasMany(WpkgDeploymentWorkstationStatus::class, 'deployment_id', 'id');
    }
}
