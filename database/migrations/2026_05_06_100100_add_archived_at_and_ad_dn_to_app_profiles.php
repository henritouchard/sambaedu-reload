<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivage logique + `ad_dn` sur `app_profiles`.
 *
 * Migration corrective : l'audit T0 (§2.4) a constaté que `ad_dn` est
 * absent de la table `app_profiles` (H1 partiellement réfutée). Hypothèse
 * initiale : `ad_dn` déjà présent partout. Réalité : seul
 * `ad_guid` existe sur `app_profiles`. Sans cette colonne, le job durci
 * (volet 3) ne peut pas matérialiser le DN AD côté SQL → drift partiel
 * silencieux.
 *
 * Symétrie volontaire avec `workstations` et `workstation_groups` :
 * `archived_at` posé ici aussi (scope `notArchived()` appliqué à l'eager
 * load resolver pour ignorer les profils archivés — sinon des `<package>`
 * zombies pourraient remonter dans `profiles.xml`).
 *
 * Pas de colonne `last_seen_at` ici : la justification est donnée dans
 * `2026_05_06_100000_add_archived_at_to_workstations_and_groups`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_profiles', function (Blueprint $table): void {
            $table->string('ad_dn', 512)->nullable()->after('ad_guid');
            $table->timestamp('archived_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('app_profiles', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['ad_dn', 'archived_at']);
        });
    }
};
