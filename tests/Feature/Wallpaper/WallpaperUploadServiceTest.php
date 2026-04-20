<?php

declare(strict_types=1);

namespace Tests\Feature\Wallpaper;

use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WorkstationGroup;
use App\Services\Wallpaper\WallpaperUploadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests feature — upload service wallpaper.
 *
 * Story 4.7 — AC 8.
 */
class WallpaperUploadServiceTest extends TestCase
{
    private string $tmpStorage;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('Imagick')) {
            $this->markTestSkipped('Imagick non disponible.');
        }

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

        $this->tmpStorage = sys_get_temp_dir() . '/wp-upload-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0755, true);
        config()->set('wallpapers.storage_path', $this->tmpStorage);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpStorage)) {
            foreach (glob($this->tmpStorage . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpStorage);
        }
        parent::tearDown();
    }

    private function fakeJpg(int $width = 800, int $height = 600): UploadedFile
    {
        $path = sys_get_temp_dir() . '/wp-upload-in-' . bin2hex(random_bytes(3)) . '.jpg';
        $im = new \Imagick();
        $im->newImage($width, $height, new \ImagickPixel('#336699'));
        $im->setImageFormat('jpg');
        $im->writeImage($path);
        $im->destroy();
        return new UploadedFile($path, 'upload.jpg', 'image/jpeg', null, true);
    }

    #[Test]
    public function store_default_etab(): void
    {
        $service = new WallpaperUploadService();
        $wallpaper = $service->store($this->fakeJpg(), 'wallpaper', null, true);

        $this->assertTrue($wallpaper->is_default);
        $this->assertNull($wallpaper->owner_type);
        $this->assertFileExists($wallpaper->path);
        $this->assertStringEndsWith('wallpaper.jpg', $wallpaper->path);
    }

    #[Test]
    public function store_workstation_group_wallpaper(): void
    {
        $salle = WorkstationGroup::query()->create(['name' => 'salle_42']);
        $wallpaper = (new WallpaperUploadService())->store(
            $this->fakeJpg(),
            'wallpaper',
            $salle,
            false,
        );

        $this->assertSame(WorkstationGroup::class, $wallpaper->owner_type);
        $this->assertSame($salle->id, $wallpaper->owner_id);
        $this->assertStringEndsWith('wallpaper@salle_42.jpg', $wallpaper->path);
    }

    #[Test]
    public function store_user_wallpaper_with_login_as_key(): void
    {
        $user = User::query()->create(['login' => 'jdoe']);
        $wallpaper = (new WallpaperUploadService())->store(
            $this->fakeJpg(),
            'wallpaper',
            $user,
        );

        $this->assertSame(User::class, $wallpaper->owner_type);
        $this->assertStringEndsWith('wallpaper@jdoe.jpg', $wallpaper->path);
    }

    #[Test]
    public function store_user_group_wallpaper(): void
    {
        $grp = UserGroup::query()->create(['name' => 'Profs']);
        $wallpaper = (new WallpaperUploadService())->store(
            $this->fakeJpg(),
            'wallpaper',
            $grp,
        );

        $this->assertStringEndsWith('wallpaper@Profs.jpg', $wallpaper->path);
    }

    #[Test]
    public function store_replaces_existing_wallpaper(): void
    {
        $salle = WorkstationGroup::query()->create(['name' => 'salle_a']);
        $service = new WallpaperUploadService();
        $first = $service->store($this->fakeJpg(), 'wallpaper', $salle);
        $second = $service->store($this->fakeJpg(), 'wallpaper', $salle);

        $this->assertSame($first->id, $second->id, 'Upsert attendu sur (type, owner)');
        $this->assertSame(1, Wallpaper::query()->count());
    }

    #[Test]
    public function invalid_extension_rejected(): void
    {
        $path = sys_get_temp_dir() . '/wp-bad-' . bin2hex(random_bytes(3)) . '.exe';
        file_put_contents($path, 'fake');
        $file = new UploadedFile($path, 'bad.exe', 'application/octet-stream', null, true);

        $this->expectException(\InvalidArgumentException::class);
        (new WallpaperUploadService())->store($file, 'wallpaper', null, true);
    }

    #[Test]
    public function too_large_file_rejected(): void
    {
        config()->set('wallpapers.max_upload_size', 100); // 100 octets
        $file = $this->fakeJpg();

        $this->expectException(\InvalidArgumentException::class);
        (new WallpaperUploadService())->store($file, 'wallpaper', null, true);
    }

    #[Test]
    public function invalid_mime_rejected_even_with_valid_extension(): void
    {
        // Post-review #5 : un fichier .jpg qui n'est en fait pas une image
        // (contenu texte) doit être rejeté par le MIME check.
        $path = sys_get_temp_dir() . '/wp-fakejpg-' . bin2hex(random_bytes(3)) . '.jpg';
        file_put_contents($path, "Ceci n'est pas une image.");
        $file = new UploadedFile($path, 'fake.jpg', 'text/plain', null, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/MIME/');
        (new WallpaperUploadService())->store($file, 'wallpaper', null, true);
    }

    #[Test]
    public function default_upload_does_not_overwrite_orphan(): void
    {
        // Post-review #4 : un row orphan historique `(type, NULL, NULL, is_default=false)`
        // ne doit PAS être matché lors d'un upload défaut étab — sinon le
        // fichier orphan disque serait écrasé.
        //
        // Depuis le fix #D, les orphans ne sont plus importés en DB. Mais on
        // garde le test en simulant manuellement un orphan historique (rétro-
        // compat avec installations antérieures).
        Wallpaper::query()->create([
            'name' => 'orphelin: legacy',
            'path' => '/etc/sambaedu/applications/wallpaper/wallpaper@legacy.jpg',
            'type' => 'wallpaper',
            'owner_type' => null,
            'owner_id' => null,
            'is_default' => false,
        ]);

        $upload = (new WallpaperUploadService())->store($this->fakeJpg(), 'wallpaper', null, true);

        // L'orphan existe toujours (pas écrasé), le défaut étab est un NEW row
        $this->assertTrue($upload->is_default);
        $this->assertSame(2, Wallpaper::query()->count());
        $this->assertStringEndsWith('wallpaper.jpg', $upload->path);
        // Vérifie explicitement que l'orphan est intact
        $orphan = Wallpaper::query()->where('is_default', false)->first();
        $this->assertNotNull($orphan);
        $this->assertStringContainsString('wallpaper@legacy.jpg', $orphan->path);
    }
}
