<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ControlHubArtifactPullStatus;
use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 28.1 — Item imposé d'un contrat amont (controlHub).
 *
 * Un item représente une directive émise par l'autorité amont sur un type d'entité
 * (applications, wallpapers, capabilities, shortcuts, agent_tools…) avec sa clé,
 * sa valeur, son état d'enforcement et sa cible (instance | label).
 *
 * Ce modèle est un modèle de **réception** — il ne génère aucune logique d'enforcement.
 * - L'ingestion / upsert → Story 28.2.
 * - La résolution `StateCompiler` → Story 28.3.
 *
 * Distinctions :
 * - {@see ControlHubConnection} : modélise le lien/transport (fédération, handshake) — DISTINCT.
 * - {@see \App\Services\Agent\StateContract} : contrat desired-state agent — domaine différent (homonymie).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans ce modèle.
 * Préfixe imposé : `ControlHubContract*`. [Source: prd-contrat-manage-se5.md#R3]
 *
 * @property int $id
 * @property int $controlhub_contract_id
 * @property string $type Vocabulaire d'entité amont (applications, wallpapers, capabilities…)
 * @property string $key Clé de l'item imposé
 * @property string|null $value Valeur de l'item (sémantique selon type)
 * @property \App\Enums\ControlHubEnforcementState $enforcement_state État d'enforcement
 * @property \App\Enums\ControlHubContractTarget $target_type Cible (instance | label)
 * @property string $target_label Nom du label ciblé si target_type=label ; '' = cible instance (NOT NULL, NFR4)
 * @property string|null $delivery_mode Story 39.4 — mode de livraison amont (capturé, non arbitré)
 * @property string|null $artifact_checksum Story 39.4 — sha256 hex = identité stable du binaire imposé
 * @property string|null $artifact_filename Story 39.4 — nom informatif (jamais utilisé pour le nommage disque)
 * @property int|null $artifact_size Story 39.4 — taille attendue du binaire en octets
 * @property \App\Enums\ControlHubArtifactPullStatus|null $pull_status Story 39.4 — état du pull (pending|downloaded|error)
 * @property string|null $pull_error Story 39.4 — message court d'échec du pull
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read ControlHubContract $contract
 */
class ControlHubContractItem extends Model
{
    use HasFactory;

    protected $table = 'controlhub_contract_items';

    protected $fillable = [
        'controlhub_contract_id',
        'type',
        'key',
        'value',
        'enforcement_state',
        'target_type',
        'target_label',
        // Story 39.4 — canal ④ (additifs nullables ; artifact_url N'EST PAS une colonne, cf. AC5).
        'delivery_mode',
        'artifact_checksum',
        'artifact_filename',
        'artifact_size',
        'pull_status',
        'pull_error',
    ];

    protected $casts = [
        'enforcement_state' => ControlHubEnforcementState::class,
        'target_type' => ControlHubContractTarget::class,
        // Story 39.4
        'artifact_size' => 'integer',
        'pull_status' => ControlHubArtifactPullStatus::class,
    ];

    /**
     * Contrat amont auquel appartient cet item.
     *
     * @return BelongsTo<ControlHubContract, ControlHubContractItem>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ControlHubContract::class, 'controlhub_contract_id');
    }
}
