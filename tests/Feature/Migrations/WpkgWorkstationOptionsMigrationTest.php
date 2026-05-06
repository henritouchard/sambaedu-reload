<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.2 / AC5.1 — Migration `wpkg_workstation_options` + pivots
 * resolver `application_workstation` / `application_workstation_group`.
 */
class WpkgWorkstationOptionsMigrationTest extends TestCase
{
    /** @var array<int,object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createShimTables();

        $this->migrations = [
            require base_path('database/migrations/2026_05_05_100000_add_wpkg_resolver_pivots.php'),
            require base_path('database/migrations/2026_05_05_100100_create_wpkg_workstation_options_table.php'),
        ];

        foreach ($this->migrations as $migration) {
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
        Schema::dropIfExists('applications');
        Schema::dropIfExists('workstation_groups');
        Schema::dropIfExists('workstations');

        parent::tearDown();
    }

    private function createShimTables(): void
    {
        if (! Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
            });
        }
        if (! Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
            });
        }
        if (! Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $table): void {
                $table->id();
                $table->string('app_id', 100);
            });
        }
    }

    #[Test]
    public function tables_are_created(): void
    {
        self::assertTrue(Schema::hasTable('application_workstation'));
        self::assertTrue(Schema::hasTable('application_workstation_group'));
        self::assertTrue(Schema::hasTable('wpkg_workstation_options'));
    }

    #[Test]
    public function wpkg_workstation_options_columns_exist(): void
    {
        $columns = Schema::getColumnListing('wpkg_workstation_options');
        foreach (['id', 'workstation_id', 'option_key', 'option_value', 'created_at', 'updated_at'] as $col) {
            self::assertContains($col, $columns, "Colonne {$col} manquante");
        }
    }

    #[Test]
    public function unique_composite_violation_blocks_duplicate(): void
    {
        $wsId = DB::table('workstations')->insertGetId(['name' => 'PCT1']);
        DB::table('wpkg_workstation_options')->insert([
            'workstation_id' => $wsId,
            'option_key' => 'debug',
            'option_value' => 'true',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('wpkg_workstation_options')->insert([
            'workstation_id' => $wsId,
            'option_key' => 'debug',
            'option_value' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function rollback_then_reapply_succeeds(): void
    {
        // Down
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
        self::assertFalse(Schema::hasTable('wpkg_workstation_options'));
        self::assertFalse(Schema::hasTable('application_workstation'));

        // Up à nouveau pour vérifier l'idempotence du up()
        foreach ($this->migrations as $migration) {
            $migration->up();
        }
        self::assertTrue(Schema::hasTable('wpkg_workstation_options'));
        self::assertTrue(Schema::hasTable('application_workstation'));
    }
}
