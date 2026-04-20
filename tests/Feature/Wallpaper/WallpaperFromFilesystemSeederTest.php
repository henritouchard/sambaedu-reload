<?php

declare(strict_types=1);

namespace Tests\Feature\Wallpaper;

use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WorkstationGroup;
use Database\Seeders\WallpaperFromFilesystemSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests feature — seeder filesystem → DB.
 *
 * Story 4.7 — AC 6.
 */
class WallpaperFromFilesystemSeederTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();
        \App\Models\WorkstationGroup::flushEventListeners();
        \App\Models\UserGroup::flushEventListeners();
        \App\Models\User::flushEventListeners();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('login')->unique();
            $t->string('password')->nullable();
            $t->string('role')->default('eleve');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('ad_rights_bitmask')->default(0);
            $t->timestamps();
        });
        Schema::create('user_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->string('type')->default('classe');
            $t->timestamps();
        });
        Schema::create('workstation_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_physical')->default(true);
            $t->timestamps();
        });
        Schema::create('wallpapers', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('path');
            $t->string('type');
            $t->string('owner_type')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->boolean('is_default')->default(false);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });

        $this->tmpDir = sys_get_temp_dir() . '/wp-seeder-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        config()->set('wallpapers.storage_path', $this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function touch(string $filename): void
    {
        file_put_contents($this->tmpDir . '/' . $filename, 'fake');
    }

    #[Test]
    public function seeds_default_etab_from_wallpaper_jpg(): void
    {
        $this->touch('wallpaper.jpg');
        $this->touch('lockscreen.jpg');

        (new WallpaperFromFilesystemSeeder())->run();

        $this->assertSame(2, Wallpaper::query()->where('is_default', true)->count());
    }

    #[Test]
    public function seeds_salle_wallpaper_when_workstation_group_exists(): void
    {
        WorkstationGroup::query()->create(['name' => 'salle_a']);
        $this->touch('wallpaper@salle_a.jpg');

        (new WallpaperFromFilesystemSeeder())->run();

        $row = Wallpaper::query()
            ->where('owner_type', WorkstationGroup::class)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('salle_a', $row->name);
    }

    #[Test]
    public function seeds_user_wallpaper(): void
    {
        User::query()->create(['login' => 'jdoe']);
        $this->touch('wallpaper@jdoe.jpg');

        (new WallpaperFromFilesystemSeeder())->run();

        $this->assertSame(1, Wallpaper::query()->where('owner_type', User::class)->count());
    }

    #[Test]
    public function seeds_user_group_wallpaper(): void
    {
        UserGroup::query()->create(['name' => 'Profs']);
        $this->touch('wallpaper@Profs.jpg');

        (new WallpaperFromFilesystemSeeder())->run();

        $this->assertSame(1, Wallpaper::query()->where('owner_type', UserGroup::class)->count());
    }

    #[Test]
    public function orphan_file_is_not_imported_to_db(): void
    {
        // Post-review #D : les orphans ne sont plus importés en DB (évite
        // le conflit unique sur (type, NULL, NULL) si 2+ orphans coexistent).
        // Le fichier reste sur disque, un warning est loggué.
        $this->touch('wallpaper@ghost.jpg');

        (new WallpaperFromFilesystemSeeder())->run();

        $this->assertSame(0, Wallpaper::query()->count());
        // Deux orphans ne crashent pas non plus
        $this->touch('wallpaper@phantom.jpg');
        (new WallpaperFromFilesystemSeeder())->run();
        $this->assertSame(0, Wallpaper::query()->count());
    }

    #[Test]
    public function logo_png_is_skipped(): void
    {
        $this->touch('logo.png');
        (new WallpaperFromFilesystemSeeder())->run();
        $this->assertSame(0, Wallpaper::query()->count());
    }

    #[Test]
    public function default_jpg_is_skipped(): void
    {
        // Post-review #3 : `default.jpg` est le fallback système, géré hors DB.
        // Le seeder doit l'ignorer pour éviter le conflit avec `wallpaper.jpg`
        // sur l'index partiel wallpapers_default_per_type.
        $this->touch('default.jpg');
        $this->touch('wallpaper.jpg'); // défaut étab coexistant

        (new WallpaperFromFilesystemSeeder())->run();

        // Seul wallpaper.jpg est importé (1 row is_default), default.jpg skippé
        $this->assertSame(1, Wallpaper::query()->where('is_default', true)->count());
    }

    #[Test]
    public function default_png_is_skipped(): void
    {
        $this->touch('default.png');
        (new WallpaperFromFilesystemSeeder())->run();
        $this->assertSame(0, Wallpaper::query()->count());
    }

    #[Test]
    public function idempotent_on_second_run(): void
    {
        WorkstationGroup::query()->create(['name' => 'salle_a']);
        $this->touch('wallpaper@salle_a.jpg');

        $seeder = new WallpaperFromFilesystemSeeder();
        $seeder->run();
        $countAfterFirst = Wallpaper::query()->count();
        $seeder->run();
        $countAfterSecond = Wallpaper::query()->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }
}
