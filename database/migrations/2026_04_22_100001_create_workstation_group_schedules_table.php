<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 4-4 (tâche 2.2) — Programmations de WorkstationGroup (crons récurrents + one-shot).
 *
 * Deux modes mutuellement exclusifs (D7) :
 *  - `recurring` : triplet `days_of_week` (ISO 8601 SMALLINT[]) + `time_of_day` + `timezone`.
 *  - `one_shot`  : `run_at` TIMESTAMPTZ unique futur, auto-complétion post-exécution.
 *
 * Contrainte CHECK garantit l'exclusivité des deux représentations au niveau DB,
 * indépendamment de la validation FormRequest côté app (défense en profondeur).
 *
 * Actions autorisées MVP (D5) : `wake` + `shutdown` seulement.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::create('workstation_group_schedules', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->foreignId('workstation_group_id')
                ->constrained('workstation_groups')
                ->cascadeOnDelete();

            // Actions MVP (D5) — enum stocké en VARCHAR pour portabilité SQLite.
            $table->string('action', 16);

            // Discriminant mode (D7)
            $table->string('mode', 16)->default('recurring');

            // days_of_week est ajouté plus bas via DB::statement (pgsql) ou
            // Schema::table (sqlite fallback) — Laravel 11 n'expose pas SMALLINT[].

            // Triplet récurrent complémentaire (nullable si one_shot)
            $table->time('time_of_day')->nullable();
            $table->string('timezone', 64)->nullable();

            // One-shot (nullable si recurring)
            if ($driver === 'pgsql') {
                $table->timestampTz('run_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
            } else {
                $table->timestamp('run_at')->nullable();
                $table->timestamp('completed_at')->nullable();
            }

            $table->boolean('enabled')->default(true);

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            if ($driver === 'pgsql') {
                $table->timestampsTz();
            } else {
                $table->timestamps();
            }

            // Index utiles pour le SELECT scheduler
            $table->index(['workstation_group_id', 'enabled']);
            $table->index(['mode', 'enabled']);
            $table->index(['enabled', 'time_of_day']);
            $table->index(['mode', 'enabled', 'completed_at', 'run_at'], 'wgs_one_shot_due_idx');
        });

        // v0.2 D7 — Colonne days_of_week : type-specifique pgsql
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE workstation_group_schedules ADD COLUMN days_of_week SMALLINT[]");
        } else {
            // Fallback SQLite / autres drivers : JSON via ALTER (cast array côté modèle)
            Schema::table('workstation_group_schedules', function (Blueprint $table) {
                $table->json('days_of_week')->nullable();
            });
        }

        // v0.2 D7 — Contrainte CHECK exclusivité recurring / one_shot (pgsql only).
        // SQLite n'a pas de CHECK contrainte compatible ARRAY, on laisse la
        // validation FormRequest + service faire le garde-fou.
        if ($driver === 'pgsql') {
            DB::statement("
                ALTER TABLE workstation_group_schedules
                ADD CONSTRAINT wgs_mode_exclusivity CHECK (
                    (mode = 'recurring' AND days_of_week IS NOT NULL AND time_of_day IS NOT NULL AND timezone IS NOT NULL AND run_at IS NULL)
                    OR
                    (mode = 'one_shot' AND run_at IS NOT NULL AND days_of_week IS NULL AND time_of_day IS NULL AND timezone IS NULL)
                )
            ");

            // Contrainte d'actions autorisées (D5)
            DB::statement("
                ALTER TABLE workstation_group_schedules
                ADD CONSTRAINT wgs_action_allowed CHECK (action IN ('wake', 'shutdown'))
            ");

            // Contrainte mode autorisé
            DB::statement("
                ALTER TABLE workstation_group_schedules
                ADD CONSTRAINT wgs_mode_allowed CHECK (mode IN ('recurring', 'one_shot'))
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_group_schedules');
    }
};
