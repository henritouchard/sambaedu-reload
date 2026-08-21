<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 28.1 — Groupe imposé d'un contrat amont (controlHub).
 *
 * Un groupe imposé représente un `WorkstationGroup` dont l'existence est garantie
 * par l'autorité amont. Le champ `label_name` désigne optionnellement le label
 * réservé associé à ce groupe.
 *
 * ⚠️ PAS de FK dure vers `controlhub_contract_labels` : le rattachement se fait par
 * nom (`label_name`) côté logique amont — un éventuel mapping groupe↔label local est
 * différé en Epic 30 (Stories 30.x). Ce choix évite un couplage structurel prématuré
 * entre les entités du contrat et le modèle local.
 *
 * Ce modèle est un modèle de **réception** — il ne génère aucune logique de création de groupe.
 * - Garantie d'existence des groupes imposés → Epic 30.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans ce modèle.
 * Préfixe imposé : `ControlHubContract*`. [Source: prd-contrat-manage-se5.md#R3]
 *
 * @property int $id
 * @property int $controlhub_contract_id
 * @property string $name Nom du workstationGroup à garantir
 * @property string|null $label_name Nom du label réservé associé (nullable — rattachement logique)
 * @property bool|null $is_physical Nature du parc réclamée par l'amont ; null = non déclarée
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read ControlHubContract $contract
 */
class ControlHubContractImposedGroup extends Model
{
    use HasFactory;

    protected $table = 'controlhub_contract_imposed_groups';

    protected $fillable = [
        'controlhub_contract_id',
        'name',
        'label_name',
        'is_physical',
    ];

    protected $casts = [
        'is_physical' => 'boolean',
    ];

    /**
     * Contrat amont auquel appartient ce groupe imposé.
     *
     * @return BelongsTo<ControlHubContract, ControlHubContractImposedGroup>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ControlHubContract::class, 'controlhub_contract_id');
    }
}
