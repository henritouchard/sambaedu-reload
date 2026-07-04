<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 39.4 — Canal ④ : étend `controlhub_contract_catalog_apps` pour porter l'IDENTITÉ STABLE
 * d'un `executable` d'app (même forme que `artifact` sur les items).
 *
 * ⚠️ PERSISTANCE SEULE (AC7) — AUCUN pull, AUCUNE matérialisation n'est déclenché pour
 * `executable` en 39.4. Justification (note de risque de la story) : ce mécanisme recouvre
 * `applications.installer_url/installer_sha256/installer_filename/installer_size/local_installer_path`
 * (`2026_02_16_180000_add_appstore_fields_to_applications.php`), un design SE5 **déjà tenté et
 * abandonné** (marqué destruction séparée) au profit du modèle WPKG multi-fichiers. Aucun exemple
 * JSON ni colonne ER ne démontre `executable` côté amont. On persiste pour traçabilité/évolution
 * sans réintroduire un mécanisme jugé inadapté. Pas de `pull_status`/`pull_error` : aucun traitement
 * ne les consomme ici.
 *
 * Migration ADDITIVE, patron `2026_06_29_100000_add_source_to_controlhub_catalog_apps.php`.
 * Colonnes NULLABLES ; `executable_url` VOLONTAIREMENT non persistée (même piège d'idempotence que
 * `artifact_url`, AC5). Clé naturelle `(controlhub_contract_id, app_key)` INCHANGÉE.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » ; vocabulaire « amont » / `ControlHub*`.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controlhub_contract_catalog_apps', function (Blueprint $table): void {
            if (! Schema::hasColumn('controlhub_contract_catalog_apps', 'executable_checksum')) {
                $table->string('executable_checksum')->nullable()->after('source_xml_sha')
                    ->comment('sha256 hex de l\'exécutable d\'app (persistance seule, pull différé — Story 39.4)');
            }
            if (! Schema::hasColumn('controlhub_contract_catalog_apps', 'executable_filename')) {
                $table->string('executable_filename')->nullable()->after('executable_checksum')
                    ->comment('Nom informatif de l\'exécutable (persistance seule — Story 39.4)');
            }
            if (! Schema::hasColumn('controlhub_contract_catalog_apps', 'executable_size')) {
                $table->bigInteger('executable_size')->nullable()->after('executable_filename')
                    ->comment('Taille attendue de l\'exécutable en octets (persistance seule — Story 39.4)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('controlhub_contract_catalog_apps', function (Blueprint $table): void {
            $table->dropColumn([
                'executable_checksum',
                'executable_filename',
                'executable_size',
            ]);
        });
    }
};
