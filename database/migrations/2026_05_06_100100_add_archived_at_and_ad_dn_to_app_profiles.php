<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 15.3 / AC2.1 — Archivage logique + `ad_dn` sur `app_profiles`.
 *
 * Migration corrective : l'audit T0 (§2.4) a constaté que `ad_dn` est
 * absent de la table `app_profiles` (H1 partiellement réfutée). Hypothèse
 * initiale de la story : `ad_dn` déjà présent partout. Réalité : seul
 * `ad_guid` existe sur `app_profiles`. Sans cette colonne, le job durci
 * (volet 3) ne peut pas matérialiser le DN AD côté SQL → drift partiel
 * silencieux.
 *
 * Symétrie volontaire avec `workstations` et `workstation_groups` :
 * `archived_at` posé ici aussi (scope `notArchived()` appliqué à l'eager
 * load resolver pour ignorer les profils archivés — sinon des `<package>`
 * zombies pourraient remonter dans `profiles.xml`).
 *
 * **Décision post-review (Q1, 2026-05-06)** : la colonne `last_seen_at`
 * initialement prévue est **abandonnée** (option C2 retenue) — cf.
 * justification dans `2026_05_06_100000_add_archived_at_to_workstations_and_groups`.
 *
 * @see _bmad-output/planning-artifacts/audit-wpkg-eloquent-schema.md §2.4, §3
 * @see _bmad-output/codeReviews/15-3.md (Q1)
 * @see _bmad-output/implementation-artifacts/15-3-modele-eloquent-suffisant-pour-deploiement-wpkg.md
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
