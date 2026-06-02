<?php

declare(strict_types=1);

namespace App\Auth\Federated\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Story 20.1 — D-6.
 *
 * Trace la consommation d'un jeton fédéré (anti-rejeu `jti` à usage unique).
 * Couche de persistance derrière {@see \App\Auth\Federated\Jwt\FederatedJwtReplayChecker}
 * (la couche cache APCu absorbe la quasi-totalité des lookups ; la DB est le
 * filet de sécurité multi-worker + post-expiration cache).
 *
 * Calqué sur {@see \App\Auth\V1\Models\WorkstationJwtRevocation} (UUID PK,
 * `jti` unique).
 *
 * @property string $id
 * @property string $jti
 * @property string $iss
 * @property Carbon $consumed_at
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FederatedJwtConsumption extends Model
{
    use HasUuids;

    /** @var string */
    protected $table = 'federated_jwt_consumptions';

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /** @var array<int,string> */
    protected $fillable = [
        'id',
        'jti',
        'iss',
        'consumed_at',
        'expires_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'consumed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
