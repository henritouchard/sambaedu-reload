<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.3 / AC2.1, AC5.1 — Migrations volet 2 :
 * - `add_archived_at_to_workstations_and_groups` (workstations + workstation_groups)
 * - `add_archived_at_and_ad_dn_to_app_profiles` (app_profiles, incluant ad_dn)
 *
 * Vérifie : up + down + up (idempotence), présence des colonnes
 * (`archived_at` partout, `ad_dn` sur `app_profiles`).
 *
 * **Décision post-review (Q1, 2026-05-06)** : la colonne `last_seen_at`
 * a été retirée du scope des migrations 15.3 — pas de besoin métier
 * (cf. doc review §Q1 et migrations).
 */
class WpkgEloquentSchemaMigrationsTest extends TestCase
{
    /** @var array<int,object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createBaseTables();

        $this->migrations = [
            require base_path('database/migrations/2026_05_06_100000_add_archived_at_to_workstations_and_groups.php'),
            require base_path('database/migrations/2026_05_06_100100_add_archived_at_and_ad_dn_to_app_profiles.php'),
        ];

        foreach ($this->migrations as $migration) {
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            try {
                $migration->down();
            } catch (\Throwable) {
                // Idempotent : si le down a déjà été appelé par un test,
                // ignorer pour ne pas masquer les vraies erreurs.
            }
        }
        Schema::dropIfExists('workstations');
        Schema::dropIfExists('workstation_groups');
        Schema::dropIfExists('app_profiles');
        parent::tearDown();
    }

    /**
     * Crée les tables baseline minimales avant de jouer les migrations
     * 15.3 (les vraies migrations cibles ajoutent des colonnes — il faut
     * que les tables existent au préalable). Schéma symboliquement aligné
     * sur la baseline 2026_01_30 + ad_guid 2026_02_06 sans rejouer toute
     * la chaîne legacy (cf. WpkgWorkstationOptionsMigrationTest).
     */
    private function createBaseTables(): void
    {
        if (! Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->string('ad_guid', 36)->nullable();
                $table->string('ad_dn', 512)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->string('ad_guid', 36)->nullable();
                $table->string('ad_dn', 512)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->string('ad_guid', 36)->nullable();
                $table->timestamps();
            });
        }
    }

    #[Test]
    public function workstations_columns_exist(): void
    {
        $columns = Schema::getColumnListing('workstations');
        self::assertContains('archived_at', $columns);
        self::assertNotContains('last_seen_at', $columns, 'last_seen_at supprimé du scope 15.3 (Q1)');
    }

    #[Test]
    public function workstation_groups_columns_exist(): void
    {
        $columns = Schema::getColumnListing('workstation_groups');
        self::assertContains('archived_at', $columns);
        self::assertNotContains('last_seen_at', $columns, 'last_seen_at supprimé du scope 15.3 (Q1)');
    }

    #[Test]
    public function app_profiles_columns_exist(): void
    {
        $columns = Schema::getColumnListing('app_profiles');
        self::assertContains('ad_dn', $columns);
        self::assertContains('archived_at', $columns);
        self::assertNotContains('last_seen_at', $columns, 'last_seen_at supprimé du scope 15.3 (Q1)');
    }

    #[Test]
    public function rollback_then_reapply_succeeds(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }

        // Colonnes effectivement retirées
        self::assertNotContains('archived_at', Schema::getColumnListing('workstations'));
        self::assertNotContains('archived_at', Schema::getColumnListing('workstation_groups'));
        self::assertNotContains('ad_dn', Schema::getColumnListing('app_profiles'));

        foreach ($this->migrations as $migration) {
            $migration->up();
        }

        self::assertContains('archived_at', Schema::getColumnListing('workstations'));
        self::assertContains('archived_at', Schema::getColumnListing('workstation_groups'));
        self::assertContains('ad_dn', Schema::getColumnListing('app_profiles'));
        self::assertContains('archived_at', Schema::getColumnListing('app_profiles'));
    }

    #[Test]
    public function archived_at_accepts_null_and_datetime(): void
    {
        $id = \DB::table('workstations')->insertGetId([
            'name' => 'PCT-archived',
            'archived_at' => null,
        ]);
        self::assertNotNull($id);

        \DB::table('workstations')->where('id', $id)->update([
            'archived_at' => now(),
        ]);

        $row = \DB::table('workstations')->find($id);
        self::assertNotNull($row->archived_at);
    }
}
