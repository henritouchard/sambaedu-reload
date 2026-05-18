<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 16.12 — AC1.1 / D2.
 *
 * Table `script_execution_logs` — logs centralisés d'exécution de scripts
 * côté postes (Windows/Linux). Alimentée via `POST /api/v1/script-execution-logs`
 * (protégé par JWT `tier=workstation` — middleware 16.10 réutilisé).
 *
 * Conventions importantes :
 *
 *  - **Pas de FK** vers `workstations`, `windows_scripts`, `linux_scripts` :
 *    soft refs polymorphes (iso 16.10 + 16.11). Un log peut arriver pour
 *    un poste pas encore enregistré dans `workstations` Eloquent.
 *  - **UUID natifs Postgres** pour `id` et `correlation_id` (16 bytes,
 *    index plus rapides). `workstation_uuid` reste `string(36)` cohérence
 *    16.10 (`workstation_refresh_tokens.workstation_uuid`).
 *  - **`timestampTz`** partout (timezone-aware) — Postgres recommande.
 *  - **2 index composites** pour les requêtes UI les plus fréquentes :
 *    1. `(workstation_uuid, started_at)` — fiche poste triée date
 *    2. `(status, started_at)` — bandeau "échecs récents"
 *  - **UNIQUE partiel pgsql** sur `(workstation_uuid, correlation_id)
 *    WHERE correlation_id IS NOT NULL` : autorise les rows sans correlation
 *    (legacy postes non-wrapper) tout en dédupliquant les retransmissions
 *    idempotentes du wrapper. SQLite testing : fallback `unique()` standard
 *    (qui n'admet qu'un seul NULL en SQLite — accepté car les tests ne
 *    créent jamais 2 rows null pour le même UUID).
 *
 * @see App\ScriptsOs\Models\ScriptExecutionLog
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::create('script_execution_logs', function (Blueprint $table) use ($driver): void {
            // PK UUID — généré côté Laravel via `Str::uuid()` (portabilité
            // SQLite testing — pas de dépendance `gen_random_uuid()` pgsql).
            if ($driver === 'pgsql') {
                $table->uuid('id')->primary();
            } else {
                $table->string('id', 36)->primary();
            }

            // FK soft (pas de contrainte) — un log peut arriver avant que le
            // poste soit dans la table `workstations` Eloquent.
            $table->string('workstation_uuid', 36);

            // FK soft polymorphe — réfère `windows_scripts.id` OU
            // `linux_scripts.id` selon `os`. Pas de FK contrainte.
            $table->unsignedBigInteger('script_id')->nullable();

            // Enums string (cf. App\ScriptsOs\Enums\*). Pas de CHECK pgsql
            // pour portabilité SQLite — la validation FormRequest +
            // cast Eloquent suffisent.
            $table->string('script_source', 32);
            $table->string('action', 16);
            $table->string('os', 8);
            $table->string('status', 16);

            $table->integer('exit_code')->nullable();

            // stdout/stderr — max 8 KB application-side via mutator du modèle.
            // Stockés `text` (pas `varchar`) car Postgres traite `text` aussi
            // efficacement et n'impose pas de limite stricte côté DB (limite
            // applicative seulement).
            $table->text('stdout_excerpt')->nullable();
            $table->text('stderr_excerpt')->nullable();

            $table->timestampTz('started_at');

            // Toujours non-null : un script qui ne démarre pas a status=skipped
            // avec duration_ms=0, pas null.
            $table->integer('duration_ms');

            $table->timestampTz('reported_at');

            // UUID idempotence — quand fourni, dédupliqué par UNIQUE partiel pgsql.
            if ($driver === 'pgsql') {
                $table->uuid('correlation_id')->nullable();
            } else {
                $table->string('correlation_id', 36)->nullable();
            }

            $table->timestampsTz();

            // Indexes simples
            $table->index('workstation_uuid');
            $table->index('script_id');
            $table->index('correlation_id');

            // Indexes composites — UI fiche poste + bandeau échecs récents
            $table->index(['workstation_uuid', 'started_at'], 'sel_ws_started_idx');
            $table->index(['status', 'started_at'], 'sel_status_started_idx');
        });

        // UNIQUE — partiel pgsql, standard SQLite testing.
        // Pourquoi pas dans la closure ci-dessus ? Le builder Schema ne
        // supporte pas `where(...)` sur les uniques en cross-driver ;
        // on émet le DDL natif.
        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX sel_ws_corr_unique
                 ON script_execution_logs (workstation_uuid, correlation_id)
                 WHERE correlation_id IS NOT NULL'
            );
        } else {
            Schema::table('script_execution_logs', function (Blueprint $table): void {
                $table->unique(
                    ['workstation_uuid', 'correlation_id'],
                    'sel_ws_corr_unique',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('script_execution_logs');
    }
};
