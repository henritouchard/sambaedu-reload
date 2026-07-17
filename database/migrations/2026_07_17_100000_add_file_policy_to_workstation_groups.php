<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Politique de gestion des fichiers — OVERRIDE PAR PARC (décision Henri
 * 2026-07-17).
 *
 * Le défaut d'instance vit en `SystemSetting` (clé `files.policy`, cf.
 * {@see \App\Services\FilePolicyService}). Ces colonnes portent la SURCHARGE
 * facultative d'un parc (`WorkstationGroup`, physique OU logique) : chaque champ
 * `null` = « hérite du défaut global ». Un parc « tout-Nextcloud web » pose
 * `files_policy_mode = 'autre_web'` → l'agent ne monte plus aucun lecteur pour
 * ses postes ({@see \App\Services\Agent\Providers\DrivesStateProvider}).
 *
 * On greffe les colonnes SUR `workstation_groups` (et non une table dédiée) —
 * iso `environment` (Story 26.1) : un parc porte déjà ses propriétés de config,
 * la résolution ne fait qu'une lecture sur les WG du poste (zéro join).
 *
 *  - `files_policy_mode` VARCHAR(32) NULL — enum {@see App\Enums\FilePolicyMode}
 *    ; null = hérite. Défaut global résolu côté service (pas de default SQL).
 *  - `files_nextcloud_server_url` / `files_nextcloud_web_url` VARCHAR(255) NULL —
 *    surcharge par parc de la config Nextcloud (consommée par le futur
 *    provisioning du client Desktop ; posée dès maintenant pour éviter un
 *    re-schéma). null = hérite du global.
 *
 * Défaut global = `Partages` (aucune ligne SystemSetting) ET aucune colonne
 * renseignée ⇒ sortie `DrivesStateProvider` byte-identique au jeu fixe : golden
 * `state.v1.json` / `FROZEN_STATE_HASH` (PHP+Go) INCHANGÉS (NFR12).
 *
 * **Idempotence stricte** : `Schema::hasColumn()` par colonne. `down()` symétrique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstation_groups', function (Blueprint $table): void {
            if (! Schema::hasColumn('workstation_groups', 'files_policy_mode')) {
                $table->string('files_policy_mode', 32)->nullable()
                    ->comment('Override par parc du mode de gestion des fichiers (App\\Enums\\FilePolicyMode) — null = hérite du défaut global SystemSetting files.policy. Coupe les lecteurs si != partages.');
            }
            if (! Schema::hasColumn('workstation_groups', 'files_nextcloud_server_url')) {
                $table->string('files_nextcloud_server_url', 255)->nullable()
                    ->comment('Override par parc de l\'URL du serveur Nextcloud — null = hérite du global. Consommé par le provisioning client (à venir).');
            }
            if (! Schema::hasColumn('workstation_groups', 'files_nextcloud_web_url')) {
                $table->string('files_nextcloud_web_url', 255)->nullable()
                    ->comment('Override par parc de l\'URL web Nextcloud — null = hérite du global.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workstation_groups', function (Blueprint $table): void {
            foreach (['files_policy_mode', 'files_nextcloud_server_url', 'files_nextcloud_web_url'] as $col) {
                if (Schema::hasColumn('workstation_groups', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
