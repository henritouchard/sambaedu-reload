<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de démarrage/extinction des postes
 *
 * Chaque ligne représente un cycle de vie d'un poste (démarrage → extinction).
 * Remplace la table legacy `machines` / `log_connexion()`.
 *
 * @property int $id
 * @property int|null $workstation_id
 * @property string $machine_name
 * @property string|null $action
 * @property string|null $initiated_by
 * @property bool|null $success
 * @property \DateTime|null $started_at
 * @property \DateTime|null $stopped_at
 * @property string|null $os
 * @property int|null $wol_score
 * @property int|null $ipxe_score
 * @property int|null $error_flags
 * @property int|null $boot_speed
 * @property string|null $vlan
 * @property string|null $switch_port
 * @property string|null $switch_ip
 * @property string|null $switch_name
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class MachineBootLog extends Model
{
    protected $table = 'machine_boot_logs';

    protected $fillable = [
        'workstation_id',
        'machine_name',
        'action',
        'initiated_by',
        'success',
        'started_at',
        'stopped_at',
        'os',
        'wol_score',
        'ipxe_score',
        'error_flags',
        'boot_speed',
        'vlan',
        'switch_port',
        'switch_ip',
        'switch_name',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'success' => 'boolean',
        'wol_score' => 'integer',
        'ipxe_score' => 'integer',
        'error_flags' => 'integer',
        'boot_speed' => 'integer',
    ];

    /**
     * Le poste de travail associé à ce log
     */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }
}
