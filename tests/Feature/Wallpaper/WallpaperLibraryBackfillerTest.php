<?php

declare(strict_types=1);

namespace Tests\Feature\Wallpaper;

use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\WorkstationGroup;
use App\Services\Wallpaper\WallpaperLibraryBackfiller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests du backfill bibliothèque (review F5 — la migration de données la plus
 * risquée doit être couverte). On exerce la logique via le service extrait,
 * avec des dossiers legacy/bibliothèque temporaires.
 */
class WallpaperLibraryBackfillerTest extends TestCase
{
    private string $legacyDir;
    private string $libraryDir;

    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();
        \App\Models\WorkstationGroup::flushEventListeners();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('login')->unique();
            $t->timestamps();
        });
        Schema::create('user_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
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
            $t->string('path')->nullable();
            $t->unsignedBigInteger('asset_id')->nullable();
            $t->string('type');
            $t->string('owner_type')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->boolean('is_default')->default(false);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
            $t->unique(['type', 'owner_type', 'owner_id']);
        });

        $base = sys_get_temp_dir() . '/wp-backfill-' . bin2hex(random_bytes(4));
        $this->legacyDir = $base . '/legacy';
        $this->libraryDir = $base . '/library';
        mkdir($this->legacyDir, 0755, true);
        mkdir($this->libraryDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->legacyDir, $this->libraryDir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function legacyFile(string $name, string $content): string
    {
        $path = $this->legacyDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    private function backfiller(): WallpaperLibraryBackfiller
    {
        return new WallpaperLibraryBackfiller();
    }

    #[Test]
    public function pass_a_links_existing_row_and_copies_file(): void
    {
        $src = $this->legacyFile('wallpaper.jpg', 'ETAB-DEFAULT');
        $row = Wallpaper::query()->create([
            'name' => 'default', 'path' => $src, 'type' => 'wallpaper',
            'owner_type' => null, 'owner_id' => null, 'is_default' => true,
        ]);

        $this->backfiller()->run($this->legacyDir, $this->libraryDir);

        $row->refresh();
        $this->assertNotNull($row->asset_id);
        $asset = WallpaperAsset::find($row->asset_id);
        $this->assertFileExists($this->libraryDir . '/' . $asset->filename);
        $this->assertSame(hash('sha256', 'ETAB-DEFAULT'), $asset->checksum);
    }

    #[Test]
    public function pass_b_links_orphan_file_to_matching_owner(): void
    {
        // Le cas « fantôme cdi » : fichier sur disque, owner existant, aucune ligne DB.
        $salle = WorkstationGroup::query()->create(['name' => 'cdi']);
        $this->legacyFile('wallpaper@cdi.jpg', 'CDI-WALL');

        $this->backfiller()->run($this->legacyDir, $this->libraryDir);

        $assignment = Wallpaper::query()
            ->where('owner_type', WorkstationGroup::class)
            ->where('owner_id', $salle->id)
            ->where('type', 'wallpaper')
            ->first();
        $this->assertNotNull($assignment, 'L\'orphelin rattachable doit créer une assignation');
        $this->assertNotNull($assignment->asset_id);
    }

    #[Test]
    public function pass_b_imports_unmatched_orphan_as_unassigned_asset(): void
    {
        // Pas de WorkstationGroup « test_parc » → asset en bibliothèque, non assigné.
        $this->legacyFile('wallpaper@test_parc.jpg', 'TESTPARC');

        $this->backfiller()->run($this->legacyDir, $this->libraryDir);

        $asset = WallpaperAsset::query()->where('checksum', hash('sha256', 'TESTPARC'))->first();
        $this->assertNotNull($asset, 'L\'orphelin non rattachable doit exister en bibliothèque');
        $this->assertSame(0, $asset->wallpapers()->count(), 'Aucune assignation pour un orphelin sans owner');
    }

    #[Test]
    public function identical_files_are_deduplicated_into_one_asset(): void
    {
        // Même contenu sous deux noms (cf. lockscreen.jpg == lockscreen@cdi.jpg en prod).
        WorkstationGroup::query()->create(['name' => 'cdi']);
        $this->legacyFile('lockscreen.jpg', 'SAME-BYTES');
        $this->legacyFile('lockscreen@cdi.jpg', 'SAME-BYTES');

        $this->backfiller()->run($this->legacyDir, $this->libraryDir);

        $this->assertSame(
            1,
            WallpaperAsset::query()->where('checksum', hash('sha256', 'SAME-BYTES'))->count(),
            'Un seul asset pour un contenu identique',
        );
    }

    #[Test]
    public function run_is_idempotent(): void
    {
        WorkstationGroup::query()->create(['name' => 'cdi']);
        $this->legacyFile('wallpaper@cdi.jpg', 'CDI-WALL');

        $this->backfiller()->run($this->legacyDir, $this->libraryDir);
        $afterFirst = [Wallpaper::query()->count(), WallpaperAsset::query()->count()];
        $this->backfiller()->run($this->legacyDir, $this->libraryDir);
        $afterSecond = [Wallpaper::query()->count(), WallpaperAsset::query()->count()];

        $this->assertSame($afterFirst, $afterSecond, 'Un 2e run ne doit rien dupliquer');
    }

    #[Test]
    public function missing_source_file_is_counted_not_fatal(): void
    {
        Wallpaper::query()->create([
            'name' => 'ghost', 'path' => $this->legacyDir . '/disparu.jpg', 'type' => 'wallpaper',
            'is_default' => true,
        ]);

        $stats = $this->backfiller()->run($this->legacyDir, $this->libraryDir);

        $this->assertSame(1, $stats['missing']);
    }
}
