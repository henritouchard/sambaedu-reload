<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 31.3 (review #A) — Garde-fou d'unicité pour la MATÉRIALISATION amont.
 *
 * `AppStoreService::materializeFromSource()` insère une `Application` avec
 * `depot_id = null` (app poussée par le contrat amont, sans dépôt local). Or la seule
 * contrainte d'unicité de la table est `unique(depot_id, app_id)` : en PostgreSQL les
 * NULL sont DISTINCTS dans un index unique → deux exécutions concurrentes (listener
 * post-commit + commande manuelle, ou deux changements de contrat rapprochés)
 * pourraient toutes deux passer le `exists()` puis insérer `(null, '<app_id>')` =
 * DOUBLON. SQLite ne l'aurait jamais révélé.
 *
 * INDEX UNIQUE PARTIEL sur `app_id` LORSQUE `depot_id IS NULL` : ferme exactement la
 * fenêtre de concurrence du chemin de matérialisation (depot_id null) SANS contraindre
 * les apps adossées à un dépôt (depot_id renseigné, déjà couvertes par
 * `unique(depot_id, app_id)`) — donc aucun risque sur d'éventuels doublons d'`app_id`
 * historiques inter-dépôts. PostgreSQL et SQLite supportent tous deux les index partiels.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » ; vocabulaire « amont » / `ControlHub*`.
 * [Source: prd-contrat-manage-se5.md#R3 ; _bmad-output/codeReviews/31-3.md #A]
 */
return new class extends Migration
{
    private const INDEX = 'applications_materialized_app_id_unique';

    public function up(): void
    {
        if (! Schema::hasTable('applications')) {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            .' ON applications (app_id) WHERE depot_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
