<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ControlHubLinkState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 28.1 — Modèle de RÉCEPTION du contrat amont émis par l'autorité controlHub.
 *
 * Ce modèle représente la **politique imposée** reçue via le lien controlHub.
 * Il est **distinct** de {@see ControlHubConnection} qui modélise le lien/transport
 * (fédération, handshake, heartbeat, tokens) — deux concepts complémentaires, sans FK en 28.1.
 *
 * Portée de la story 28.1 : persistance uniquement (structure + relations).
 * - Ingestion / upsert d'un payload reçu → Story 28.2.
 * - Branchement dans `StateCompiler` (tier de précédence amont > local) → Story 28.3.
 * - Mapping label → WorkstationGroup local → Epic 30.
 * - Bornage catalogue & install → Epic 31.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans ce modèle, ses colonnes, ses relations.
 * Préfixe imposé : `ControlHubContract*`. [Source: prd-contrat-manage-se5.md#R3]
 *
 * @property int $id
 * @property \App\Enums\ControlHubLinkState $link_state État du lien (active | severed)
 * @property \Illuminate\Support\Carbon|null $received_at Horodatage de la dernière réception
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int,ControlHubContractItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int,ControlHubContractLabel> $labels
 * @property-read \Illuminate\Database\Eloquent\Collection<int,ControlHubContractImposedGroup> $imposedGroups
 * @property-read \Illuminate\Database\Eloquent\Collection<int,ControlHubContractCatalogApp> $catalogApps
 */
class ControlHubContract extends Model
{
    use HasFactory;

    protected $table = 'controlhub_contracts';

    protected $fillable = [
        'link_state',
        'received_at',
    ];

    protected $casts = [
        'link_state' => ControlHubLinkState::class,
        'received_at' => 'datetime',
    ];

    /**
     * Story 30.2 — Résout le contrat amont **actif** (singleton « ≤ 1 contrat
     * actif par instance »), ou null si le lien n'est pas établi (standalone).
     *
     * ⚠️ Résolution IDENTIQUE à celle de
     * {@see \App\Services\ControlHub\ControlHubContractIngestionService::resolveActiveContract()}
     * (28.2) : filtre sur `link_state = active`. Ne pas diverger (heuristique
     * « dernier reçu » interdite). NFR3 : sans contrat actif → null, aucun label
     * proposé, comportement parc strictement inchangé.
     */
    public static function active(): ?self
    {
        return static::query()
            ->where('link_state', ControlHubLinkState::Active->value)
            ->first();
    }

    /**
     * Items imposés par l'autorité amont (type, clé, valeur, état d'enforcement, cible).
     *
     * @return HasMany<ControlHubContractItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ControlHubContractItem::class, 'controlhub_contract_id');
    }

    /**
     * Labels imposés par l'autorité amont (nom, mode libre|réservé).
     *
     * @return HasMany<ControlHubContractLabel>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(ControlHubContractLabel::class, 'controlhub_contract_id');
    }

    /**
     * Groupes de postes dont l'existence est imposée par l'autorité amont.
     *
     * @return HasMany<ControlHubContractImposedGroup>
     */
    public function imposedGroups(): HasMany
    {
        return $this->hasMany(ControlHubContractImposedGroup::class, 'controlhub_contract_id');
    }

    /**
     * Catalogue applicatif faisant autorité (apps imposées par l'autorité amont).
     *
     * @return HasMany<ControlHubContractCatalogApp>
     */
    public function catalogApps(): HasMany
    {
        return $this->hasMany(ControlHubContractCatalogApp::class, 'controlhub_contract_id');
    }
}
