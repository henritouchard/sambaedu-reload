<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Models;

use App\Models\AppProfile;
use App\Models\Workstation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 15.5 — Modèle Eloquent de la table `wpkg_deployment_workstation_status`
 * (créée par 15.1, sans modèle).
 *
 * Une ligne = un (deployment, workstation). `app_profile_id` est nullable
 * (un déploiement peut cibler un poste sans profil dédié, ex bulk machine).
 *
 * @property string $id UUID
 * @property string $deployment_id UUID FK
 * @property int $workstation_id
 * @property int|null $app_profile_id
 * @property \Illuminate\Support\Carbon|null $client_reported_at
 * @property string $client_status pending|success|partial|failed|unknown
 * @property array<string,mixed>|null $details
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read WpkgDeployment $deployment
 * @property-read Workstation $workstation
 * @property-read AppProfile|null $appProfile
 */
final class WpkgDeploymentWorkstationStatus extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'wpkg_deployment_workstation_status';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'deployment_id',
        'workstation_id',
        'app_profile_id',
        'client_reported_at',
        'client_status',
        'details',
        'error_message',
    ];

    protected $casts = [
        'client_reported_at' => 'datetime',
        'details' => 'array',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(WpkgDeployment::class, 'deployment_id', 'id');
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function appProfile(): BelongsTo
    {
        return $this->belongsTo(AppProfile::class);
    }
}
