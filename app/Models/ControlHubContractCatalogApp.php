<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 28.1 — Application du catalogue applicatif d'un contrat amont (controlHub).
 *
 * Le catalogue applicatif liste les applications qui font autorité dans le contrat
 * émis par l'autorité amont. `app_key` correspond à `applications.app_id` côté SE5.
 *
 * En 28.1, le catalogue est une **liste persistée** sans logique de filtrage d'installation.
 * - Logique de bornage catalogue & install → Epic 31.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans ce modèle.
 * Préfixe imposé : `ControlHubContract*`. [Source: prd-contrat-manage-se5.md#R3]
 *
 * @property int $id
 * @property int $controlhub_contract_id
 * @property string $app_key Identifiant de l'app faisant autorité (applications.app_id)
 * @property string|null $display_name Nom d'affichage reçu de l'autorité amont (informatif)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read ControlHubContract $contract
 */
class ControlHubContractCatalogApp extends Model
{
    use HasFactory;

    protected $table = 'controlhub_contract_catalog_apps';

    protected $fillable = [
        'controlhub_contract_id',
        'app_key',
        'display_name',
    ];

    /**
     * Contrat amont auquel appartient cette app de catalogue.
     *
     * @return BelongsTo<ControlHubContract, ControlHubContractCatalogApp>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ControlHubContract::class, 'controlhub_contract_id');
    }
}
