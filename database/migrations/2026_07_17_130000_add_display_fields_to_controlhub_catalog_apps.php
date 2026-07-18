<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 51.1 — Champs d'AFFICHAGE du dépôt imposé (projection du catalogue amont
 * controlHub en dépôt SE5).
 *
 * Migration ADDITIVE (patron
 * `2026_06_29_100000_add_source_to_controlhub_catalog_apps.php`, jamais une
 * réécriture de `2026_06_26_100000_create_controlhub_contract_tables.php`) :
 *  - `version`  (string nullable) : version d'affichage de l'app imposée ;
 *  - `category` (string nullable) : catégorie d'affichage ;
 *  - `icon_url` (string nullable) : URL de l'icône d'affichage.
 *
 * Tous NULLABLES et OPTIONNELS (rétrocompat NFR3 : un contrat sans ces champs
 * reste accepté ; l'affichage du dépôt imposé dégrade proprement). La clé
 * naturelle `(controlhub_contract_id, app_key)` est INCHANGÉE (idempotence 28.2 /
 * NFR4). Aucun bump `schema_version` (doctrine additive 31.3/39.4).
 *
 * Garde `Schema::hasColumn` + `down()` symétrique (patron cité par la story).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » ; vocabulaire « amont » / `ControlHub*`.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controlhub_contract_catalog_apps', function (Blueprint $table): void {
            if (! Schema::hasColumn('controlhub_contract_catalog_apps', 'version')) {
                $table->string('version')->nullable()->after('source_xml_sha')
                    ->comment('Version d\'affichage de l\'app imposée (nullable, Story 51.1)');
            }
            if (! Schema::hasColumn('controlhub_contract_catalog_apps', 'category')) {
                $table->string('category')->nullable()->after('version')
                    ->comment('Catégorie d\'affichage de l\'app imposée (nullable, Story 51.1)');
            }
            if (! Schema::hasColumn('controlhub_contract_catalog_apps', 'icon_url')) {
                // 512 (comme `source_xml_url`/`depots.url`) : une URL d'icône dépasse
                // couramment 255 → un varchar(255) provoquerait un 22001 PG à l'ingestion,
                // INVISIBLE en test SQLite (project_sqlite_tests_no_varchar_enforcement).
                $table->string('icon_url', 512)->nullable()->after('category')
                    ->comment('URL de l\'icône d\'affichage de l\'app imposée (nullable, Story 51.1)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('controlhub_contract_catalog_apps', function (Blueprint $table): void {
            foreach (['version', 'category', 'icon_url'] as $column) {
                if (Schema::hasColumn('controlhub_contract_catalog_apps', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
