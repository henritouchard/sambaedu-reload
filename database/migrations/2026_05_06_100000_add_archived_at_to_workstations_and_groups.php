<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 15.3 / AC2.1 — Archivage logique sur `workstations` et
 * `workstation_groups`.
 *
 * - `archived_at` : archivage logique (catégorie A) en remplacement de tout
 *   `DELETE` sec (cf. AC3.4). Le scope `notArchived()` est appliqué côté
 *   resolver (AC2.1 D8) et listings UI (15.4).
 *
 * **Décision post-review (Q1, 2026-05-06)** : la colonne `last_seen_at`
 * initialement prévue est **abandonnée** (option C2 retenue). Pas de besoin
 * métier réel : l'archivage des orphans est calculé dans le même run du
 * `SyncAllFromAdJob` (par diff `preExistingGuidIds` ↔ `matchedDbIds`), le
 * scope `staleSince()` n'était utilisé nulle part et `saveQuietly()` à
 * chaque run violait l'idempotence AC3.6 (cf. doc review #3).
 *
 * @see _bmad-output/planning-artifacts/audit-wpkg-eloquent-schema.md §3, §5
 * @see _bmad-output/codeReviews/15-3.md (Q1)
 * @see _bmad-output/implementation-artifacts/15-3-modele-eloquent-suffisant-pour-deploiement-wpkg.md
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
        });

        Schema::table('workstation_groups', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at']);
        });

        Schema::table('workstation_groups', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at']);
        });
    }
};
