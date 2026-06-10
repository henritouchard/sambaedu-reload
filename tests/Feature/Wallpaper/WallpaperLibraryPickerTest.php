<?php

declare(strict_types=1);

namespace Tests\Feature\Wallpaper;

use App\Models\User;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\WorkstationGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests du sélecteur de fond d'écran en modale (refonte UX 2026-06).
 *
 * La sélection est gérée côté client (Alpine) ; côté serveur on teste
 * l'ouverture (présélection du courant), la validation paramétrée
 * (`validateSelection($assetId)`) et le retrait.
 */
class WallpaperLibraryPickerTest extends TestCase
{
    private string $tmpLibrary;
    private WorkstationGroup $group;

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
            $t->unique(['type', 'owner_type', 'owner_id']);
        });
        // Tables Spatie minimales (HasRoles sur User les requête au check gate).
        Schema::create('permissions', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id'); $t->string('model_type'); $t->unsignedBigInteger('model_id');
        });
        Schema::create('model_has_roles', function (Blueprint $t): void {
            $t->unsignedBigInteger('role_id'); $t->string('model_type'); $t->unsignedBigInteger('model_id');
        });
        Schema::create('role_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id'); $t->unsignedBigInteger('role_id');
        });

        $this->tmpLibrary = sys_get_temp_dir() . '/wp-picker-' . bin2hex(random_bytes(4));
        mkdir($this->tmpLibrary, 0755, true);
        config()->set('wallpapers.library_path', $this->tmpLibrary);

        // Autorisation : utilisateur authentifié + court-circuit Spatie (tables
        // permissions vides en SQLite :memory:) via une gate explicite.
        $this->actingAs(User::query()->create(['login' => 'admin']));
        Gate::define('wallpaper.manage', fn () => true);

        $this->group = WorkstationGroup::query()->create(['name' => 'cdi', 'is_physical' => true]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpLibrary)) {
            foreach (glob($this->tmpLibrary . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpLibrary);
        }
        parent::tearDown();
    }

    private function asset(string $tag): WallpaperAsset
    {
        $checksum = hash('sha256', $tag);
        $filename = substr($checksum, 0, 24) . '.jpg';
        file_put_contents($this->tmpLibrary . '/' . $filename, 'x');

        return WallpaperAsset::create([
            'filename' => $filename,
            'original_name' => $tag . '.jpg',
            'checksum' => $checksum,
        ]);
    }

    private function assign(WallpaperAsset $asset, string $type): Wallpaper
    {
        return Wallpaper::create([
            'name' => 'cdi',
            'asset_id' => $asset->id,
            'type' => $type,
            'owner_type' => WorkstationGroup::class,
            'owner_id' => $this->group->id,
            'is_default' => false,
        ]);
    }

    private function picker(string $type = 'wallpaper')
    {
        return Livewire::test('components::molecules.wallpaper-library-picker', [
            'type' => $type,
            'ownerType' => WorkstationGroup::class,
            'ownerId' => $this->group->id,
        ]);
    }

    #[Test]
    public function open_for_matching_type_exposes_current_asset(): void
    {
        $current = $this->asset('cdi-wall');
        $this->assign($current, 'wallpaper');

        $this->picker('wallpaper')
            ->call('maybeOpen', 'wallpaper', $this->group->id)
            ->assertSet('show', true)
            ->assertSet('currentAssetId', $current->id);
    }

    #[Test]
    public function open_ignores_non_matching_type(): void
    {
        $this->picker('wallpaper')
            ->call('maybeOpen', 'lockscreen', $this->group->id)
            ->assertSet('show', false);
    }

    #[Test]
    public function validate_assigns_selected_asset(): void
    {
        $a = $this->asset('lib-a'); // en bibliothèque, non assigné

        $this->picker('wallpaper')
            ->call('maybeOpen', 'wallpaper', $this->group->id)
            ->call('validateSelection', $a->id)
            ->assertSet('show', false)
            ->assertSet('currentAssetId', $a->id)
            ->assertDispatched('wallpaper-updated');

        $assignment = Wallpaper::query()
            ->where('type', 'wallpaper')
            ->where('owner_type', WorkstationGroup::class)
            ->where('owner_id', $this->group->id)
            ->first();
        $this->assertNotNull($assignment);
        $this->assertSame($a->id, $assignment->asset_id);
    }

    #[Test]
    public function validate_null_removes_existing_assignment(): void
    {
        $current = $this->asset('cur');
        $this->assign($current, 'wallpaper');

        $this->picker('wallpaper')
            ->call('maybeOpen', 'wallpaper', $this->group->id)
            ->call('validateSelection', null)
            ->assertSet('show', false);

        $this->assertSame(0, Wallpaper::query()->where('owner_id', $this->group->id)->count());
    }

    #[Test]
    public function validate_same_asset_is_noop(): void
    {
        $current = $this->asset('cur');
        $this->assign($current, 'wallpaper');

        $this->picker('wallpaper')
            ->call('maybeOpen', 'wallpaper', $this->group->id)
            ->call('validateSelection', $current->id)
            ->assertSet('show', false);

        $this->assertSame(1, Wallpaper::query()->where('owner_id', $this->group->id)->count());
    }

    #[Test]
    public function remove_current_deletes_assignment(): void
    {
        $current = $this->asset('cur');
        $this->assign($current, 'wallpaper');

        $this->picker('wallpaper')
            ->call('removeCurrent')
            ->assertDispatched('wallpaper-updated');

        $this->assertSame(0, Wallpaper::query()->where('owner_id', $this->group->id)->count());
    }
}
