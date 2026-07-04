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
 * @property string|null $source_xml_url Story 31.3 — URL de la recette WPKG (xml) du dépôt SambaEdu (référence de source, nullable)
 * @property string|null $source_xml_sha Story 31.3 — empreinte attendue de la recette WPKG source (nullable)
 * @property string|null $executable_checksum Story 39.4 — sha256 hex de l'exécutable (persistance SEULE, pull différé — AC7)
 * @property string|null $executable_filename Story 39.4 — nom informatif de l'exécutable (persistance SEULE)
 * @property int|null $executable_size Story 39.4 — taille attendue de l'exécutable en octets (persistance SEULE)
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
        // Story 31.3 — référence de source du dépôt SambaEdu (« Option B par-app », D1).
        // Optionnels (nullable) : un contrat sans source reste accepté (NFR3).
        'source_xml_url',
        'source_xml_sha',
        // Story 39.4 — descripteur d'exécutable (PERSISTANCE SEULE, AC7 ; pull différé).
        // executable_url N'EST PAS une colonne (même piège d'idempotence que artifact_url, AC5).
        'executable_checksum',
        'executable_filename',
        'executable_size',
    ];

    protected $casts = [
        'executable_size' => 'integer',
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
