<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 31.3 — Étend le catalogue applicatif amont (controlHub) pour porter la
 * RÉFÉRENCE DE SOURCE (« Option B par-app », D1) du dépôt SambaEdu.
 *
 * Migration ADDITIVE (jamais une réécriture de
 * `2026_06_26_100000_create_controlhub_contract_tables.php`, en review) :
 *  - `source_xml_url` (string nullable) : URL de la recette WPKG (xml) issue du dépôt
 *    SambaEdu, telle que référencée par l'autorité amont enrôlée (de confiance).
 *  - `source_xml_sha` (string nullable) : empreinte attendue de cette recette.
 *
 * Les deux colonnes sont NULLABLES (rétrocompatibilité NFR3 : un contrat sans champ
 * source reste accepté). La CLÉ NATURELLE `(controlhub_contract_id, app_key)` est
 * INCHANGÉE (idempotence 28.2 préservée).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » ; vocabulaire « amont » / `ControlHub*`.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controlhub_contract_catalog_apps', function (Blueprint $table): void {
            $table->string('source_xml_url')->nullable()->after('display_name')
                ->comment('URL de la recette WPKG (xml) du dépôt SambaEdu référencée par l\'amont (nullable, Story 31.3)');
            $table->string('source_xml_sha')->nullable()->after('source_xml_url')
                ->comment('Empreinte attendue de la recette WPKG source (nullable, Story 31.3)');
        });
    }

    public function down(): void
    {
        Schema::table('controlhub_contract_catalog_apps', function (Blueprint $table): void {
            $table->dropColumn(['source_xml_url', 'source_xml_sha']);
        });
    }
};
