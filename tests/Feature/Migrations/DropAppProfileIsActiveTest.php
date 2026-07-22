<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suppression de `app_profiles.is_active`.
 *
 * Le drapeau ne conditionnait aucun déploiement — le resolver ne filtre que
 * `archived_at` — donc rien à reprendre : les lignes survivent telles quelles,
 * quelle que soit la valeur qu'elles portaient.
 */
class DropAppProfileIsActiveTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('app_profiles');
        Schema::create('app_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->migration = require base_path(
            'database/migrations/2026_07_22_140000_drop_is_active_from_app_profiles.php'
        );
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('app_profiles');

        parent::tearDown();
    }

    #[Test]
    public function it_drops_the_column(): void
    {
        $this->migration->up();

        self::assertNotContains('is_active', Schema::getColumnListing('app_profiles'));
    }

    #[Test]
    public function it_keeps_every_profile_including_the_inactive_ones(): void
    {
        DB::table('app_profiles')->insert([
            ['name' => 'base-windows', 'is_active' => true],
            ['name' => 'ancien-profil', 'is_active' => false],
        ]);

        $this->migration->up();

        self::assertSame(2, DB::table('app_profiles')->count());
        self::assertNotNull(DB::table('app_profiles')->where('name', 'ancien-profil')->first());
    }

    #[Test]
    public function it_is_reversible(): void
    {
        $this->migration->up();
        $this->migration->down();

        self::assertContains('is_active', Schema::getColumnListing('app_profiles'));
    }
}
