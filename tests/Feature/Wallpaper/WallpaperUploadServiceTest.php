<?php

declare(strict_types=1);

namespace Tests\Feature\Wallpaper;

use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\WorkstationGroup;
use App\Services\Wallpaper\WallpaperUploadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests feature — upload service wallpaper (refonte bibliothèque 2026-06).
 *
 * L'upload normalise l'image, la déduplique en asset content-addressé
 * (`<checksum>.jpg`) sous `library_path`, et crée/maj l'assignation
 * (owner, type) → asset.
 */
class WallpaperUploadServiceTest extends TestCase
{
    private string $tmpLibrary;

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
        Schema::create('wallpaper_assets', function (Blueprint $t): void {
            $t->id();
            $t->string('filename')->unique();
            $t->string('original_name')->nullable();
            $t->string('checksum', 64)->unique();
            $t->unsignedBigInteger('byte_size')->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });
        Schema::create('wallpapers', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('asset_id')->nullable();
            $t->string('type');
            $t->string('owner_type')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->boolean('is_default')->default(false);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });

        $this->tmpLibrary = sys_get_temp_dir() . '/wp-upload-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpLibrary, 0755, true);
        config()->set('wallpapers.library_path', $this->tmpLibrary);
    }

    protected function tearDown(): void
    {
        // setUp() peut markTestSkipped() (Imagick absent) AVANT d'initialiser
        // $tmpLibrary : sans ce garde, l'accès à la propriété typée non
        // initialisée transforme le skip en Error.
        if (isset($this->tmpLibrary) && is_dir($this->tmpLibrary)) {
            foreach (glob($this->tmpLibrary . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpLibrary);
        }
        parent::tearDown();
    }

    private function fakeJpg(string $color = '#336699', int $width = 800, int $height = 600): UploadedFile
    {
        $path = sys_get_temp_dir() . '/wp-upload-in-' . bin2hex(random_bytes(3)) . '.jpg';
        $im = new \Imagick();
        $im->newImage($width, $height, new \ImagickPixel($color));
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
        $this->assertNotNull($wallpaper->asset_id);
        $this->assertFileExists($wallpaper->asset->absolutePath);
        $this->assertStringStartsWith($this->tmpLibrary . '/', $wallpaper->asset->absolutePath);
        $this->assertStringEndsWith('.jpg', $wallpaper->asset->filename);
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
        $this->assertNotNull($wallpaper->asset_id);
        $this->assertFileExists($wallpaper->asset->absolutePath);
    }

    #[Test]
    public function store_user_wallpaper(): void
    {
        $user = User::query()->create(['login' => 'jdoe']);
        $wallpaper = (new WallpaperUploadService())->store($this->fakeJpg(), 'wallpaper', $user);

        $this->assertSame(User::class, $wallpaper->owner_type);
        $this->assertNotNull($wallpaper->asset_id);
    }

    #[Test]
    public function store_user_group_wallpaper(): void
    {
        $grp = UserGroup::query()->create(['name' => 'Profs']);
        $wallpaper = (new WallpaperUploadService())->store($this->fakeJpg(), 'wallpaper', $grp);

        $this->assertSame(UserGroup::class, $wallpaper->owner_type);
        $this->assertNotNull($wallpaper->asset_id);
    }

    #[Test]
    public function store_upsert_keeps_single_assignment(): void
    {
        $salle = WorkstationGroup::query()->create(['name' => 'salle_a']);
        $service = new WallpaperUploadService();
        $first = $service->store($this->fakeJpg('#111111'), 'wallpaper', $salle);
        $second = $service->store($this->fakeJpg('#111111'), 'wallpaper', $salle);

        // Même image (dédup) → même asset, même assignation.
        $this->assertSame($first->id, $second->id, 'Upsert attendu sur (type, owner)');
        $this->assertSame($first->asset_id, $second->asset_id, 'Image identique → asset dédupliqué');
        $this->assertSame(1, Wallpaper::query()->count());
        $this->assertSame(1, WallpaperAsset::query()->count());
    }

    #[Test]
    public function identical_image_for_two_owners_is_deduplicated(): void
    {
        $a = WorkstationGroup::query()->create(['name' => 'salle_a']);
        $b = WorkstationGroup::query()->create(['name' => 'salle_b']);
        $service = new WallpaperUploadService();

        $wa = $service->store($this->fakeJpg('#222222'), 'wallpaper', $a);
        $wb = $service->store($this->fakeJpg('#222222'), 'wallpaper', $b);

        $this->assertSame($wa->asset_id, $wb->asset_id, 'Un seul asset pour une image identique');
        $this->assertSame(2, Wallpaper::query()->count());
        $this->assertSame(1, WallpaperAsset::query()->count());
    }

    #[Test]
    public function replace_with_different_image_gcs_orphan_asset(): void
    {
        $salle = WorkstationGroup::query()->create(['name' => 'salle_a']);
        $service = new WallpaperUploadService();

        $first = $service->store($this->fakeJpg('#aa0000'), 'wallpaper', $salle);
        $firstAsset = WallpaperAsset::find($first->asset_id);
        $firstPath = $firstAsset->absolutePath;
        $this->assertFileExists($firstPath);

        $second = $service->store($this->fakeJpg('#00aa00'), 'wallpaper', $salle);

        $this->assertNotSame($first->asset_id, $second->asset_id, 'Image différente → nouvel asset');
        $this->assertSame(1, Wallpaper::query()->count());
        // L'ancien asset n'est plus référencé → collecté (DB + fichier).
        $this->assertSame(1, WallpaperAsset::query()->count());
        $this->assertNull(WallpaperAsset::find($first->asset_id));
        $this->assertFileDoesNotExist($firstPath);
    }

    #[Test]
    public function shared_asset_not_gced_while_still_referenced(): void
    {
        $a = WorkstationGroup::query()->create(['name' => 'salle_a']);
        $b = WorkstationGroup::query()->create(['name' => 'salle_b']);
        $service = new WallpaperUploadService();

        // Même image assignée à A et B (1 asset, 2 assignations).
        $service->store($this->fakeJpg('#333333'), 'wallpaper', $a);
        $wb = $service->store($this->fakeJpg('#333333'), 'wallpaper', $b);
        $sharedAsset = WallpaperAsset::find($wb->asset_id);
        $sharedPath = $sharedAsset->absolutePath;

        // B change d'image : l'asset partagé reste (A le référence encore).
        $service->store($this->fakeJpg('#444444'), 'wallpaper', $b);

        $this->assertNotNull(WallpaperAsset::find($sharedAsset->id));
        $this->assertFileExists($sharedPath);
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
        $path = sys_get_temp_dir() . '/wp-fakejpg-' . bin2hex(random_bytes(3)) . '.jpg';
        file_put_contents($path, "Ceci n'est pas une image.");
        $file = new UploadedFile($path, 'fake.jpg', 'text/plain', null, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/MIME/');
        (new WallpaperUploadService())->store($file, 'wallpaper', null, true);
    }

    #[Test]
    public function default_upload_does_not_match_orphan_row(): void
    {
        // Un row orphan historique `(type, NULL, NULL, is_default=false)` ne
        // doit PAS être matché lors d'un upload défaut étab (post-review #4).
        $orphanAsset = WallpaperAsset::create([
            'filename' => 'orphan.jpg',
            'checksum' => hash('sha256', 'orphan'),
        ]);
        Wallpaper::query()->create([
            'name' => 'orphelin: legacy',
            'asset_id' => $orphanAsset->id,
            'type' => 'wallpaper',
            'owner_type' => null,
            'owner_id' => null,
            'is_default' => false,
        ]);

        $upload = (new WallpaperUploadService())->store($this->fakeJpg(), 'wallpaper', null, true);

        $this->assertTrue($upload->is_default);
        $this->assertSame(2, Wallpaper::query()->count());

        $orphan = Wallpaper::query()->where('is_default', false)->first();
        $this->assertNotNull($orphan);
        $this->assertSame($orphanAsset->id, $orphan->asset_id);
    }
}
