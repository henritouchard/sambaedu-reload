<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 3.8 — D12 / D-A10 / AC7.1.
 *
 * Ajoute 2 colonnes à `workstations` pour piloter la state machine post-OOBE
 * Windows (port natif de `legacy/modules/ipxe/Win10/action.php` set_action +
 * set_progress) :
 *
 *  - `progress VARCHAR(8) NULL` après `status` — progression install Windows
 *    iPXE `0%`-`100%` (parité legacy `set_progress($id, "50%")`).
 *  - `programmed_action JSONB NULL DEFAULT '{}'` après `progress` — action
 *    programmée Windows post-OOBE sérialisée (parité legacy `apcu actions[uuid]`).
 *    Schema applicatif : `{"type":"clonage|clonage2|renomme|postinst|default",
 *    "role":"...","script":"...","etape":"...","ret":-1|0|1|2}`.
 *  - Index GIN sur `(programmed_action->>'etape')` (Postgres natif JSONB) pour
 *    permettre des lookups efficaces par étape (`WHERE programmed_action->>'etape'
 *    = 'sysprep'`).
 *
 * **Idempotence stricte** (D12 + T1.1) : la migration vérifie l'existence des
 * colonnes via `Schema::hasColumn()` avant création — un environnement où une
 * migration legacy aurait déjà ajouté `progress` (peu probable mais possible
 * sur VM dev) est géré silencieusement.
 *
 * **Compatibilité SQLite (testing)** : `jsonb` n'existe pas en SQLite ; on
 * fallback sur `text` (les casts Eloquent `'array'` font la sérialisation
 * applicative — le DB natif type n'est utile que pour les index GIN qui ne
 * fonctionnent qu'en Postgres). L'index GIN est skip en SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('workstations', function (Blueprint $table) use ($driver): void {
            if (! Schema::hasColumn('workstations', 'progress')) {
                $table->string('progress', 8)->nullable()->after('status')
                    ->comment('Progress install Windows iPXE 0%-100% (Story 3.8 D12)');
            }
            if (! Schema::hasColumn('workstations', 'programmed_action')) {
                if ($driver === 'pgsql') {
                    // Postgres natif : JSONB + default '{}'.
                    $table->jsonb('programmed_action')->nullable()->default('{}')
                        ->after('progress')
                        ->comment('State machine post-OOBE Windows (Story 3.8 D3/D12)');
                } else {
                    // SQLite/MySQL : fallback text — l'Eloquent cast 'array'
                    // assure la sérialisation applicative.
                    $table->text('programmed_action')->nullable()
                        ->after('progress')
                        ->comment('State machine post-OOBE Windows (Story 3.8 D3/D12)');
                }
            }
        });

        // Index GIN sur `(programmed_action->>'etape')` — Postgres seulement.
        // Le test `Schema::hasColumn` + index custom évite les doublons sur
        // les replays de migration.
        if ($driver === 'pgsql') {
            $exists = DB::selectOne(
                "SELECT 1 FROM pg_indexes WHERE tablename = 'workstations' AND indexname = 'workstations_pa_etape_idx'"
            );
            if ($exists === null) {
                // B-tree expression index sur la clé JSON `etape` (lookup
                // exact `WHERE programmed_action->>'etape' = 'sysprep'`).
                // Note D-A10 : GIN trgm pas nécessaire — les lookups sont
                // toujours par valeur exacte, pas par pattern matching.
                DB::statement(
                    "CREATE INDEX workstations_pa_etape_idx ON workstations ((programmed_action->>'etape'))"
                );
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS workstations_pa_etape_idx');
        }

        Schema::table('workstations', function (Blueprint $table): void {
            if (Schema::hasColumn('workstations', 'programmed_action')) {
                $table->dropColumn('programmed_action');
            }
            if (Schema::hasColumn('workstations', 'progress')) {
                $table->dropColumn('progress');
            }
        });
    }
};
