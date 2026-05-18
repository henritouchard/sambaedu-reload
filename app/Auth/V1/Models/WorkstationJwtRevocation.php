<?php

declare(strict_types=1);

namespace App\Auth\V1\Models;

use Database\Factories\Auth\V1\WorkstationJwtRevocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Story 16.10 — AC3.2.
 *
 * Modèle Eloquent pour la table `workstation_jwt_revocations`.
 *
 *  - `id`  UUID v4 généré par `HasUuids`.
 *  - `jti` = claim JWT révoqué (unique).
 *  - Scope `active()` = `expires_at > now()` (cf. note migration : les
 *    entrées dont `expires_at < now()` peuvent être purgées).
 *
 * @property string $id
 * @property string $jti
 * @property string $workstation_uuid
 * @property Carbon $revoked_at
 * @property string $reason
 * @property string|null $revoked_by
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WorkstationJwtRevocation extends Model
{
    use HasFactory;
    use HasUuids;

    /** @var string */
    protected $table = 'workstation_jwt_revocations';

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /** @var array<int,string> */
    protected $fillable = [
        'id',
        'jti',
        'workstation_uuid',
        'revoked_at',
        'reason',
        'revoked_by',
        'expires_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Révocations encore actives (le JWT révoqué n'aurait pas encore expiré
     * de toute façon — au-delà, la révocation est redondante).
     *
     * @param Builder<self> $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', Carbon::now());
    }

    /**
     * Override : factory sous le sous-namespace `Auth\V1`.
     */
    protected static function newFactory(): Factory
    {
        return WorkstationJwtRevocationFactory::new();
    }
}
