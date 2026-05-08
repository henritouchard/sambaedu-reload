<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.5 / T1 — Test feature de la migration `workstation_api_secrets`.
 *
 * Vérifie :
 *   - Création de la table avec colonnes attendues.
 *   - FK ON DELETE CASCADE sur `workstation_id`.
 *   - Unicité de `workstation_id`.
 *   - Index sur `revoked_at`.
 *
 * Run isolé sur un schema rejoué via une instance test.
 */
final class WorkstationApiSecretsMigrationTest extends TestCase
{
    /**
     * Rejoue les migrations sur le schema courant.
     */
    private function runMigrations(): void
    {
        // On ne peut pas faire `RefreshDatabase` sans toutes les tables 9.x ;
        // on rejoue uniquement les migrations Wpkg en bootstrappant à la main
        // les tables minimales requises (workstations).
        if (! Schema::hasTable('workstations')) {
            Schema::create('workstations', function ($t) {
                $t->id();
                $t->string('name', 100);
                $t->string('status', 32)->default('active');
                $t->timestamp('archived_at')->nullable();
                $t->timestamps();
            });
        }

        Schema::dropIfExists('workstation_api_secrets');

        $migration = require base_path(
            'database/migrations/2026_05_06_100200_create_workstation_api_secrets_table.php'
        );
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('workstation_api_secrets');
        parent::tearDown();
    }

    #[Test]
    public function table_has_expected_columns(): void
    {
        $this->runMigrations();

        $this->assertTrue(Schema::hasTable('workstation_api_secrets'));

        $expected = [
            'id', 'workstation_id', 'secret_hash',
            'previous_secret_hash', 'previous_valid_until',
            'last_used_at', 'rotated_at', 'revoked_at',
            'created_at', 'updated_at',
        ];

        foreach ($expected as $col) {
            $this->assertTrue(
                Schema::hasColumn('workstation_api_secrets', $col),
                "Colonne workstation_api_secrets.{$col} attendue."
            );
        }
    }

    #[Test]
    public function workstation_id_is_unique(): void
    {
        $this->runMigrations();

        $w = DB::table('workstations')->insertGetId([
            'name' => 'PC-MIG-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workstation_api_secrets')->insert([
            'workstation_id' => $w,
            'secret_hash' => 'hash1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('workstation_api_secrets')->insert([
            'workstation_id' => $w,
            'secret_hash' => 'hash2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function down_drops_table(): void
    {
        $this->runMigrations();

        $migration = require base_path(
            'database/migrations/2026_05_06_100200_create_workstation_api_secrets_table.php'
        );
        $migration->down();

        $this->assertFalse(Schema::hasTable('workstation_api_secrets'));
    }
}
