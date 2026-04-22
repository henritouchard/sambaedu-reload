<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 4-4 (tâche 2.3) — Historique d'exécution des crons WorkstationGroup.
 *
 * 1 row par exécution (recurring ou one_shot). Le JSONB summary stocke :
 *  - success_count, failed_count, skipped_count
 *  - task_ids: int[] (IDs MachinePowerActionTask créés)
 *  - errors: [{machine_id, machine_name, error_message}]
 *  - drift_seconds (one_shot rattrapés après downtime uniquement)
 *
 * L'index unique `(schedule_id, ran_for_date, ran_for_time)` garantit qu'un
 * même créneau ne peut pas être joué deux fois (protection anti-double-fire
 * complémentaire à la garde `exists()` service + `withoutOverlapping` scheduler).
 *
 * Rétention 30 jours via commande `parc:prune-group-schedule-runs` (daily).
 * FK `schedule_id` en nullOnDelete : historique préservé si schedule supprimé
 * (AC6 — l'audit survit à la suppression du cron).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::create('workstation_group_schedule_runs', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->foreign('schedule_id')
                ->references('id')
                ->on('workstation_group_schedules')
                ->nullOnDelete();

            if ($driver === 'pgsql') {
                $table->timestampTz('ran_at');
            } else {
                $table->timestamp('ran_at');
            }
            $table->time('ran_for_time');
            $table->date('ran_for_date');

            $table->json('summary');

            if ($driver === 'pgsql') {
                $table->timestampsTz();
            } else {
                $table->timestamps();
            }

            $table->index('ran_at');
        });

        // Idempotence : un seul run par (schedule, date, créneau).
        // En pgsql on utilise un index partiel WHERE schedule_id IS NOT NULL pour
        // éviter que le UNIQUE classique ne laisse passer des doublons côté runs
        // orphelins (après FK nullOnDelete), puisque pgsql traite NULL != NULL.
        // Sous SQLite (tests), on garde le UNIQUE complet — acceptable car la
        // cascade nullOnDelete n'est pas stricte en test (cf. note service).
        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX wgsr_schedule_date_time_unique '
                . 'ON workstation_group_schedule_runs (schedule_id, ran_for_date, ran_for_time) '
                . 'WHERE schedule_id IS NOT NULL'
            );
        } else {
            Schema::table('workstation_group_schedule_runs', function (Blueprint $table) {
                $table->unique(
                    ['schedule_id', 'ran_for_date', 'ran_for_time'],
                    'wgsr_schedule_date_time_unique'
                );
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Drop l'index partiel avant la table (la table DROP le droperait aussi,
            // mais on est explicite pour la symétrie avec up()).
            DB::statement('DROP INDEX IF EXISTS wgsr_schedule_date_time_unique');
        }

        Schema::dropIfExists('workstation_group_schedule_runs');
    }
};
