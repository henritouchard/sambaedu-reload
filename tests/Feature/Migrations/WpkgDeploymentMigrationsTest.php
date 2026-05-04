<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.1 / AC3.1 — vérifie la création + le rollback des deux tables
 * de tracking pipeline déploiement WPKG.
 *
 * Cible test = SQLite :memory:. La stack `migrate:fresh` complète n'est pas
 * praticable ici (la baseline projet a des migrations Postgres-only, ex
 * drop column SQLite-incompatible) ; on exécute donc nos migrations
 * directement en chargeant les fichiers et en appelant `up()` / `down()`.
 *
 * Les FK vers `users`, `workstations`, `app_profiles` sont posées par
 * Laravel uniquement si Schema::create détecte la table cible — sur
 * SQLite, on prépare des tables shim minimales avant exécution de nos
 * migrations.
 */
class WpkgDeploymentMigrationsTest extends TestCase
{
    /** @var array<int,object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createShimTablesForForeignKeys();

        $this->migrations = [
            require base_path('database/migrations/2026_05_03_100000_create_wpkg_deployments_table.php'),
            require base_path('database/migrations/2026_05_03_100100_create_wpkg_deployment_workstation_status_table.php'),
        ];

        foreach ($this->migrations as $migration) {
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        // Down dans l'ordre inverse pour respecter les FK.
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
        Schema::dropIfExists('app_profiles');
        Schema::dropIfExists('workstations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    private function createShimTablesForForeignKeys(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255);
            });
        }
        if (! Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
            });
        }
        if (! Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
            });
        }
    }

    #[Test]
    public function wpkg_deployments_table_has_expected_shape(): void
    {
        $this->assertTrue(Schema::hasTable('wpkg_deployments'));

        $columns = ['id', 'triggered_by', 'triggered_at', 'target_scope', 'status', 'summary', 'created_at', 'updated_at'];
        foreach ($columns as $col) {
            $this->assertTrue(
                Schema::hasColumn('wpkg_deployments', $col),
                "Colonne wpkg_deployments.{$col} attendue."
            );
        }

        // L'id doit accepter un UUID (string), pas un autoincrement int.
        DB::table('wpkg_deployments')->insert([
            'id' => '11111111-1111-1111-1111-111111111111',
            'triggered_at' => now(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            '11111111-1111-1111-1111-111111111111',
            DB::table('wpkg_deployments')->value('id')
        );
    }

    #[Test]
    public function wpkg_deployment_workstation_status_table_has_expected_shape(): void
    {
        $this->assertTrue(Schema::hasTable('wpkg_deployment_workstation_status'));

        $columns = [
            'id', 'deployment_id', 'workstation_id', 'app_profile_id',
            'client_reported_at', 'client_status', 'details', 'error_message',
            'created_at', 'updated_at',
        ];
        foreach ($columns as $col) {
            $this->assertTrue(
                Schema::hasColumn('wpkg_deployment_workstation_status', $col),
                "Colonne wpkg_deployment_workstation_status.{$col} attendue."
            );
        }
    }

    #[Test]
    public function wpkg_deployment_workstation_status_table_has_expected_indexes(): void
    {
        // AC3.1 : indexes (deployment_id, workstation_id) et
        // (workstation_id, client_reported_at) doivent être présents.
        $indexNames = collect(DB::select("PRAGMA index_list('wpkg_deployment_workstation_status')"))
            ->pluck('name')
            ->all();

        $this->assertContains(
            'wdws_deploy_ws_idx',
            $indexNames,
            'Index composite (deployment_id, workstation_id) attendu.'
        );
        $this->assertContains(
            'wdws_ws_reported_idx',
            $indexNames,
            'Index composite (workstation_id, client_reported_at) attendu.'
        );

        $deployWsCols = collect(DB::select("PRAGMA index_info('wdws_deploy_ws_idx')"))
            ->pluck('name')->all();
        $this->assertSame(['deployment_id', 'workstation_id'], $deployWsCols);

        $wsReportedCols = collect(DB::select("PRAGMA index_info('wdws_ws_reported_idx')"))
            ->pluck('name')->all();
        $this->assertSame(['workstation_id', 'client_reported_at'], $wsReportedCols);
    }

    #[Test]
    public function migrations_can_be_rolled_back_and_re_applied(): void
    {
        // run -> down -> up (cycle pour garantir réversibilité).
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
        $this->assertFalse(Schema::hasTable('wpkg_deployment_workstation_status'));
        $this->assertFalse(Schema::hasTable('wpkg_deployments'));

        foreach ($this->migrations as $migration) {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('wpkg_deployments'));
        $this->assertTrue(Schema::hasTable('wpkg_deployment_workstation_status'));
    }
}
