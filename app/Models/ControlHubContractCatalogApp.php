<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application du catalogue applicatif d'un contrat amont (controlHub).
 *
 * Le catalogue applicatif liste les applications qui font autorité dans le contrat
 * émis par l'autorité amont. `app_key` correspond à `applications.app_id` côté SE5.
 *
 * En, le catalogue est une **liste persistée** sans logique de filtrage d'installation.
 * - Logique de bornage catalogue & install →.
 *
 * ⚠️ Convention de nommage : aucun mot « central » dans ce modèle.
 * Préfixe imposé : `ControlHubContract*`.
 *
 * @property int $id
 * @property int $controlhub_contract_id
 * @property string $app_key Identifiant de l'app faisant autorité (applications.app_id)
 * @property string|null $display_name Nom d'affichage reçu de l'autorité amont (informatif)
 * @property string|null $source_xml_url — URL de la recette WPKG (xml) du dépôt SambaEdu (référence de source, nullable)
 * @property string|null $source_xml_sha — empreinte attendue de la recette WPKG source (nullable)
 * @property string|null $executable_checksum — sha256 hex de l'exécutable (persistance SEULE, pull différé —)
 * @property string|null $executable_filename — nom informatif de l'exécutable (persistance SEULE)
 * @property int|null $executable_size — taille attendue de l'exécutable en octets (persistance SEULE)
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
        // Référence de source du dépôt SambaEdu, par application.
        // Optionnels (nullable) : un contrat sans source reste accepté.
        'source_xml_url',
        'source_xml_sha',
        // Champs d'AFFICHAGE du dépôt imposé (projection JSON → depot_applications).
        // Optionnels (nullable) : absence tolérée, l'affichage dégrade proprement.
        'version',
        'category',
        'icon_url',
        // Descripteur d'exécutable (PERSISTANCE SEULE ; pull différé).
        // Executable_url N'EST PAS une colonne (même piège d'idempotence que artifact_url).
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
