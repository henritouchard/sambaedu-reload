<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Models;

use App\Models\Workstation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Override per-poste d'une option `.ini` WPKG (Story 15.2 / AC5.2).
 *
 * Si l'option n'a pas de ligne pour le poste, `WorkstationIniGenerator` applique
 * la valeur par défaut `false` (parité legacy `poste_maintenance_options.php`).
 *
 * @property int $id
 * @property int $workstation_id
 * @property string $option_key
 * @property string $option_value
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class WpkgWorkstationOption extends Model
{
    protected $table = 'wpkg_workstation_options';

    protected $fillable = [
        'workstation_id',
        'option_key',
        'option_value',
    ];

    protected $casts = [
        'workstation_id' => 'integer',
    ];

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }
}
