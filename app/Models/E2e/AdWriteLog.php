<?php

declare(strict_types=1);

namespace App\Models\E2e;

use Illuminate\Database\Eloquent\Model;

/**
 * Journal d'une écriture AD capturée par le fake e2e (Story 21.2, DP-LOG).
 *
 * Persisté dans la table `e2e_ad_writes` (créée UNIQUEMENT en `e2e`, cf.
 * migration). Inspectable :
 *  - par un test host via Eloquent (`AdWriteLog::all()`) ;
 *  - par un test Playwright via l'endpoint read-only `GET /e2e/ad-writes`
 *    ({@see \App\Http\Controllers\E2e\AdWriteLogController}), enregistré seulement
 *    si `APP_ENV === 'e2e'`.
 *
 * Survit au cross-process : un job AD asynchrone (`sync` en e2e) écrit, le test
 * relit ensuite — la persistance Postgres garantit la cohérence (contrairement
 * à un store in-memory). Reset gratuit avec la template (21.1).
 *
 * @property int $id
 * @property string $action_type
 * @property string|null $target
 * @property string|null $fake_guid
 * @property array|null $payload
 * @property string|null $channel
 */
class AdWriteLog extends Model
{
    protected $table = 'e2e_ad_writes';

    protected $fillable = [
        'action_type',
        'target',
        'fake_guid',
        'payload',
        'channel',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
