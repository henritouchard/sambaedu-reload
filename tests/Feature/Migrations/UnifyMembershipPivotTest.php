<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 4.11 — Migration `unify_workstation_membership_pivot`.
 *
 * Couvre AC1 : backfill FK → pivot idempotent, drop colonne, `down()`
 * restaure colonne + données depuis le pivot.
 */
class UnifyMembershipPivotTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSchema();

        $this->migration = require base_path(
            'database/migrations/2026_06_04_120000_unify_workstation_membership_pivot.php'
        );
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('workstation_group_workstation');
        Schema::dropIfExists('workstations');
        Schema::dropIfExists('workstation_groups');

        parent::tearDown();
    }

    private function buildSchema(): void
    {
        Schema::dropIfExists('workstation_group_workstation');
        Schema::dropIfExists('workstations');
        Schema::dropIfExists('workstation_groups');

        Schema::create('workstation_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->boolean('is_physical')->default(false);
            $table->timestamps();
        });

        Schema::create('workstations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('physical_room_id')->nullable();
            $table->index('physical_room_id');
            $table->timestamps();
        });

        Schema::create('workstation_group_workstation', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workstation_group_id');
            $table->unsignedBigInteger('workstation_id');
            $table->timestamps();
            $table->unique(['workstation_group_id', 'workstation_id'], 'wg_ws_unique');
        });
    }

    #[Test]
    public function up_backfills_fk_into_pivot_and_drops_column(): void
    {
        $salleA = DB::table('workstation_groups')->insertGetId(['name' => 'Salle A', 'is_physical' => true]);
        $salleB = DB::table('workstation_groups')->insertGetId(['name' => 'Salle B', 'is_physical' => true]);
        $parc = DB::table('workstation_groups')->insertGetId(['name' => 'Parc', 'is_physical' => false]);

        $ws1 = DB::table('workstations')->insertGetId(['name' => 'pc-1', 'physical_room_id' => $salleA]);
        $ws2 = DB::table('workstations')->insertGetId(['name' => 'pc-2', 'physical_room_id' => $salleB]);
        $ws3 = DB::table('workstations')->insertGetId(['name' => 'pc-3', 'physical_room_id' => null]);

        // ws2 est déjà dans un parc logique via le pivot — le backfill ne doit
        // pas le perturber.
        DB::table('workstation_group_workstation')->insert([
            'workstation_group_id' => $parc,
            'workstation_id' => $ws2,
        ]);

        $this->migration->up();

        // Colonne supprimée
        $this->assertFalse(Schema::hasColumn('workstations', 'physical_room_id'));

        // Backfill : chaque couple FK est désormais une ligne pivot
        $this->assertDatabaseHas('workstation_group_workstation', [
            'workstation_group_id' => $salleA,
            'workstation_id' => $ws1,
        ]);
        $this->assertDatabaseHas('workstation_group_workstation', [
            'workstation_group_id' => $salleB,
            'workstation_id' => $ws2,
        ]);

        // ws3 (pas de salle) n'a aucune ligne pivot
        $this->assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $ws3,
        ]);

        // Pas de doublon pour ws2 (parc + salle = 2 lignes distinctes)
        $this->assertSame(
            1,
            DB::table('workstation_group_workstation')
                ->where('workstation_id', $ws2)
                ->where('workstation_group_id', $salleB)
                ->count()
        );
    }

    #[Test]
    public function up_is_idempotent_when_pivot_row_already_exists(): void
    {
        $salle = DB::table('workstation_groups')->insertGetId(['name' => 'Salle', 'is_physical' => true]);
        $ws = DB::table('workstations')->insertGetId(['name' => 'pc-1', 'physical_room_id' => $salle]);

        // La ligne pivot existe déjà (cas re-run / double exécution).
        DB::table('workstation_group_workstation')->insert([
            'workstation_group_id' => $salle,
            'workstation_id' => $ws,
        ]);

        $this->migration->up();

        $this->assertSame(
            1,
            DB::table('workstation_group_workstation')
                ->where('workstation_id', $ws)
                ->where('workstation_group_id', $salle)
                ->count()
        );
    }

    #[Test]
    public function up_skips_orphan_fk_pointing_to_missing_group(): void
    {
        // FK pointant vers un groupe inexistant (orphelin) — ne doit PAS créer
        // de ligne pivot (sinon violation FK pivot en prod PG).
        $ws = DB::table('workstations')->insertGetId(['name' => 'pc-orphan', 'physical_room_id' => 9999]);

        $this->migration->up();

        $this->assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $ws,
        ]);
    }

    #[Test]
    public function down_restores_column_and_repopulates_from_pivot(): void
    {
        $salle = DB::table('workstation_groups')->insertGetId(['name' => 'Salle', 'is_physical' => true]);
        $parc = DB::table('workstation_groups')->insertGetId(['name' => 'Parc', 'is_physical' => false]);
        $ws = DB::table('workstations')->insertGetId(['name' => 'pc-1', 'physical_room_id' => $salle]);

        $this->migration->up();
        $this->assertFalse(Schema::hasColumn('workstations', 'physical_room_id'));

        // ws appartient désormais à un parc logique aussi (pivot).
        DB::table('workstation_group_workstation')->insert([
            'workstation_group_id' => $parc,
            'workstation_id' => $ws,
        ]);

        $this->migration->down();

        // Colonne restaurée
        $this->assertTrue(Schema::hasColumn('workstations', 'physical_room_id'));

        // Repeuplée depuis le pivot is_physical (salle, PAS le parc logique)
        $restored = DB::table('workstations')->where('id', $ws)->value('physical_room_id');
        $this->assertSame($salle, (int) $restored);
    }
}
