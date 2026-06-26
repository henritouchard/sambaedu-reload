<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ControlHubLabelMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 28.1 — Label imposé d'un contrat amont (controlHub).
 *
 * Un label représente une étiquette déclarée par l'autorité amont avec son mode :
 * - `free`     : l'admin local peut étiqueter librement des postes avec ce label.
 * - `reserved` : le label est réservé à l'autorité amont (typiquement porté par un groupe imposé).
 *
 * Ce modèle est un modèle de **réception** — il ne génère aucune logique.
 * - Mapping label → WorkstationGroup local → Epic 30.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans ce modèle.
 * Préfixe imposé : `ControlHubContract*`. [Source: prd-contrat-manage-se5.md#R3]
 *
 * @property int $id
 * @property int $controlhub_contract_id
 * @property string $name Nom du label
 * @property \App\Enums\ControlHubLabelMode $mode Mode (free | reserved)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read ControlHubContract $contract
 */
class ControlHubContractLabel extends Model
{
    use HasFactory;

    protected $table = 'controlhub_contract_labels';

    protected $fillable = [
        'controlhub_contract_id',
        'name',
        'mode',
    ];

    protected $casts = [
        'mode' => ControlHubLabelMode::class,
    ];

    /**
     * Contrat amont auquel appartient ce label.
     *
     * @return BelongsTo<ControlHubContract, ControlHubContractLabel>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ControlHubContract::class, 'controlhub_contract_id');
    }
}
