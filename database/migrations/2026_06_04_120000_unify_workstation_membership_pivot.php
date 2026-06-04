<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 4.11 — Unification de l'appartenance poste↔groupe dans le pivot global.
 *
 * Backfill : chaque couple (poste, salle FK) `workstations.physical_room_id`
 * devient une ligne du pivot `workstation_group_workstation`, puis la colonne
 * FK est supprimée. La salle d'un poste se lit désormais
 * `$ws->groups()->where('is_physical', true)`.
 *
 * Idempotent : la contrainte unique `wg_ws_unique (workstation_group_id,
 * workstation_id)` + `insertOrIgnore` évitent les doublons si la ligne pivot
 * existait déjà. Le JOIN sur `workstation_groups` écarte les FK orphelines
 * (poste pointant vers un groupe supprimé) — sinon la FK pivot serait violée.
 *
 * Cible Postgres (prod SE5) ; `insertOrIgnore` reste compatible SQLite (tests).
 */
return new class extends Migration {
    public function up(): void
    {
        // --- Backfill FK → pivot ---------------------------------------
        // On lit les couples valides (JOIN écarte les orphelins) puis on
        // insère avec timestamps explicites via insertOrIgnore (cross-driver :
        // ON CONFLICT DO NOTHING en PG, INSERT OR IGNORE en SQLite).
        $now = now();

        $couples = DB::table('workstations as w')
            ->join('workstation_groups as g', 'g.id', '=', 'w.physical_room_id')
            ->whereNotNull('w.physical_room_id')
            ->select('w.id as workstation_id', 'w.physical_room_id as workstation_group_id')
            ->get();

        if ($couples->isNotEmpty()) {
            $rows = $couples->map(fn ($c) => [
                'workstation_id' => $c->workstation_id,
                'workstation_group_id' => $c->workstation_group_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('workstation_group_workstation')->insertOrIgnore($rows);
        }

        // --- Drop de la FK + index + colonne ---------------------------
        if (Schema::hasColumn('workstations', 'physical_room_id')) {
            Schema::table('workstations', function (Blueprint $table) {
                // L'ordre importe : FK puis index puis colonne.
                try {
                    $table->dropForeign(['physical_room_id']);
                } catch (\Throwable $e) {
                    // SQLite de test : pas de FK nommée à dropper.
                }
                try {
                    $table->dropIndex(['physical_room_id']);
                } catch (\Throwable $e) {
                    // idem.
                }
            });

            Schema::table('workstations', function (Blueprint $table) {
                $table->dropColumn('physical_room_id');
            });
        }
    }

    public function down(): void
    {
        // --- Recréer la colonne + FK -----------------------------------
        if (!Schema::hasColumn('workstations', 'physical_room_id')) {
            Schema::table('workstations', function (Blueprint $table) {
                $table->foreignId('physical_room_id')->nullable()
                    ->comment('Salle physique où se trouve la machine');
                $table->index('physical_room_id');
            });

            Schema::table('workstations', function (Blueprint $table) {
                $table->foreign('physical_room_id')
                    ->references('id')
                    ->on('workstation_groups')
                    ->onDelete('set null');
            });
        }

        // --- Repeupler la FK depuis le pivot (groupes physiques) -------
        // Un poste peut être dans plusieurs groupes logiques + au plus une
        // salle physique (invariant service) : on ne restaure que la salle.
        $physicalLinks = DB::table('workstation_group_workstation as pivot')
            ->join('workstation_groups as g', 'g.id', '=', 'pivot.workstation_group_id')
            ->where('g.is_physical', true)
            ->select('pivot.workstation_id', 'pivot.workstation_group_id')
            ->get();

        foreach ($physicalLinks as $link) {
            DB::table('workstations')
                ->where('id', $link->workstation_id)
                ->update(['physical_room_id' => $link->workstation_group_id]);
        }
    }
};
