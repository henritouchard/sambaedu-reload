<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Wallpaper;

use App\Dto\Wallpaper\WallpaperContext;
use App\Dto\Wallpaper\WallpaperResolution;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\WorkstationGroup;
use App\Services\Filesystem\XfsQuotaService;
use App\Services\Wallpaper\WallpaperResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unitaires du Resolver — 7 niveaux legacy + optimisation requêtes.
 *
 * Story 4.7 — AC 4, AC 12. Refonte bibliothèque (2026-06) : la résolution se
 * fait via les assignations DB jointes aux assets (`asset_id`). Plus de
 * fallback par convention de nom de fichier.
 */
class WallpaperResolverTest extends TestCase
{
    private string $tmpLibrary;

    protected function setUp(): void
    {
        parent::setUp();

        // Désactive les observers Eloquent (synchro AD/LDAP) pour isoler
        // les tests du Resolver.
        Model::unguard();
        \App\Models\WorkstationGroup::flushEventListeners();
        \App\Models\UserGroup::flushEventListeners();
        \App\Models\User::flushEventListeners();

        // Schémas SQLite :memory: (pas de migrations en Unit)
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('login')->unique();
            $t->string('fullname')->nullable();
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

        $this->tmpLibrary = sys_get_temp_dir() . '/wp-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpLibrary, 0755, true);
        config()->set('wallpapers.library_path', $this->tmpLibrary);
        config()->set('wallpapers.system_default_path', '/nonexistent/default.jpg');
        config()->set('wallpapers.perso_wallpaper', false);
        config()->set('wallpapers.main_types', ['Profs', 'Eleves', 'Administratifs']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpLibrary)) {
            foreach (glob($this->tmpLibrary . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpLibrary);
        }
        Mockery::close();
        parent::tearDown();
    }

    private function resolver(?XfsQuotaService $quota = null): WallpaperResolver
    {
        return new WallpaperResolver($quota);
    }

    /** Crée (ou réutilise) un asset de bibliothèque et retourne son id. */
    private function assetId(string $tag): int
    {
        $checksum = hash('sha256', $tag);

        return WallpaperAsset::firstOrCreate(
            ['checksum' => $checksum],
            ['filename' => substr($checksum, 0, 24) . '.jpg', 'original_name' => $tag],
        )->id;
    }

    private function ctx(array $overrides = []): WallpaperContext
    {
        return WallpaperContext::fromApcuArray(array_merge([
            'user' => ['cn' => 'jdoe', 'fullname' => 'John Doe'],
            'admin' => false,
            'machine' => ['cn' => 'post01'],
            'salle' => 'salle_a',
            'list_u' => ['Eleves', 'classe_6A'],
            'os' => 'linux',
            'time' => time(),
        ], $overrides));
    }

    // ========================================================================
    // NIVEAU 1 — default.jpg système seul
    // ========================================================================

    #[Test]
    public function level1_system_default_when_nothing_else(): void
    {
        $ctx = new WallpaperContext(
            userLogin: '',
            userFullname: '',
            userIsAdmin: false,
            machineName: 'post01',
            salleName: '',
            groupsUser: [],
            mainUserType: null,
            os: 'linux',
            timestamp: time(),
        );

        $res = $this->resolver()->resolve($ctx, Wallpaper::TYPE_WALLPAPER);

        $this->assertSame(WallpaperResolution::LEVEL_DEFAULT_SYSTEM, $res->level);
    }

    // ========================================================================
    // NIVEAU 2 — défaut étab DB bat système
    // ========================================================================

    #[Test]
    public function level2_etab_default_from_db_beats_system(): void
    {
        Wallpaper::query()->create([
            'name' => 'default',
            'asset_id' => $this->assetId('etab-default'),
            'type' => 'wallpaper',
            'owner_type' => null,
            'owner_id' => null,
            'is_default' => true,
        ]);

        $ctx = $this->ctx(['salle' => '', 'list_u' => []]);

        $res = $this->resolver()->resolve($ctx, Wallpaper::TYPE_WALLPAPER);

        $this->assertSame(WallpaperResolution::LEVEL_DEFAULT_ETAB, $res->level);
    }

    // ========================================================================
    // NIVEAU 3 — salle bat étab
    // ========================================================================

    #[Test]
    public function level3_salle_beats_etab(): void
    {
        Wallpaper::query()->create([
            'name' => 'default',
            'asset_id' => $this->assetId('etab'),
            'type' => 'wallpaper',
            'is_default' => true,
        ]);

        $salle = WorkstationGroup::query()->create(['name' => 'salle_a', 'is_physical' => true]);
        Wallpaper::query()->create([
            'name' => 'salle_a',
            'asset_id' => $this->assetId('salle_a'),
            'type' => 'wallpaper',
            'owner_type' => WorkstationGroup::class,
            'owner_id' => $salle->id,
        ]);

        $ctx = $this->ctx(['list_u' => []]);

        $res = $this->resolver()->resolve($ctx, Wallpaper::TYPE_WALLPAPER);

        $this->assertSame(WallpaperResolution::LEVEL_SALLE, $res->level);
        $this->assertSame('salle_a', $res->ownerName);
    }

    // ========================================================================
    // NIVEAU 4 — type principal bat salle
    // ========================================================================

    #[Test]
    public function level4_main_type_beats_salle(): void
    {
        $salle = WorkstationGroup::query()->create(['name' => 'salle_a']);
        Wallpaper::query()->create([
            'name' => 'salle_a',
            'asset_id' => $this->assetId('salle'),
            'type' => 'wallpaper',
            'owner_type' => WorkstationGroup::class,
            'owner_id' => $salle->id,
        ]);

        $profs = UserGroup::query()->create(['name' => 'Profs', 'type' => 'classe']);
        Wallpaper::query()->create([
            'name' => 'Profs',
            'asset_id' => $this->assetId('profs'),
            'type' => 'wallpaper',
            'owner_type' => UserGroup::class,
            'owner_id' => $profs->id,
        ]);

        $ctx = $this->ctx(['list_u' => ['Profs']]);

        $res = $this->resolver()->resolve($ctx, Wallpaper::TYPE_WALLPAPER);

        $this->assertSame(WallpaperResolution::LEVEL_MAIN_TYPE, $res->level);
        $this->assertSame('Profs', $res->ownerName);
    }

    // ========================================================================
    // NIVEAU 5 — groupe AD (hors type principal)
    // ========================================================================

    #[Test]
    public function level5_other_group_match(): void
    {
        $group = UserGroup::query()->create(['name' => 'classe_6A']);
        Wallpaper::query()->create([
            'name' => 'classe_6A',
            'asset_id' => $this->assetId('6a'),
            'type' => 'wallpaper',
            'owner_type' => UserGroup::class,
            'owner_id' => $group->id,
        ]);

        $ctx = $this->ctx([
            'list_u' => ['Eleves', 'classe_6A'],
        ]);

        $res = $this->resolver()->resolve($ctx, Wallpaper::TYPE_WALLPAPER);

        $this->assertSame(WallpaperResolution::LEVEL_GROUP, $res->level);
        $this->assertSame('classe_6A', $res->ownerName);
    }

    // ========================================================================
    // NIVEAU 6 — user bat tout
    // ========================================================================

    #[Test]
    public function level6_user_wins(): void
    {
        $user = User::query()->create([
            'login' => 'jdoe',
            'fullname' => 'John Doe',
            'password' => 'x',
            'is_active' => true,
            'ad_rights_bitmask' => 0,
            'role' => 'eleve',
        ]);
        Wallpaper::query()->create([
            'name' => 'jdoe',
            'asset_id' => $this->assetId('jdoe'),
            'type' => 'wallpaper',
            'owner_type' => User::class,
            'owner_id' => $user->id,
        ]);

        $salle = WorkstationGroup::query()->create(['name' => 'salle_a']);
        Wallpaper::query()->create([
            'name' => 'salle_a',
            'asset_id' => $this->assetId('salle'),
            'type' => 'wallpaper',
            'owner_type' => WorkstationGroup::class,
            'owner_id' => $salle->id,
        ]);

        $ctx = $this->ctx();
        $res = $this->resolver()->resolve($ctx, Wallpaper::TYPE_WALLPAPER);

        $this->assertSame(WallpaperResolution::LEVEL_USER, $res->level);
        $this->assertSame('jdoe', $res->ownerName);
    }

    // ========================================================================
    // NIVEAU 7 — perso_wallpaper (home) bat user quand activé
    // ========================================================================

    #[Test]
    public function level7_disabled_falls_back_to_level6_user(): void
    {
        config()->set('wallpapers.perso_wallpaper', false);

        $user = User::query()->create([
            'login' => 'jdoe',
            'password' => 'x',
            'is_active' => true,
            'ad_rights_bitmask' => 0,
            'role' => 'eleve',
        ]);
        Wallpaper::query()->create([
            'name' => 'jdoe',
            'asset_id' => $this->assetId('jdoe'),
            'type' => 'wallpaper',
            'owner_type' => User::class,
            'owner_id' => $user->id,
        ]);

        $res = $this->resolver()->resolve($this->ctx(), Wallpaper::TYPE_WALLPAPER);
        $this->assertSame(WallpaperResolution::LEVEL_USER, $res->level);
    }

    #[Test]
    public function level7_home_perso_wins_over_all(): void
    {
        $tmpdir = sys_get_temp_dir() . '/wp-home-perso-' . bin2hex(random_bytes(4));
        mkdir($tmpdir . '/jdoe/Photos', 0755, true);
        $personalPath = $tmpdir . '/jdoe/Photos/wallpaper.jpg';
        file_put_contents($personalPath, 'fake');

        try {
            config()->set('wallpapers.perso_wallpaper', true);
            config()->set('wallpapers.personal_base_path', $tmpdir);

            $user = User::query()->create([
                'login' => 'jdoe',
                'password' => 'x',
                'is_active' => true,
                'ad_rights_bitmask' => 0,
                'role' => 'eleve',
            ]);
            Wallpaper::query()->create([
                'name' => 'jdoe',
                'asset_id' => $this->assetId('jdoe'),
                'type' => 'wallpaper',
                'owner_type' => User::class,
                'owner_id' => $user->id,
            ]);

            $salle = WorkstationGroup::query()->create(['name' => 'salle_a']);
            Wallpaper::query()->create([
                'name' => 'salle_a',
                'asset_id' => $this->assetId('salle'),
                'type' => 'wallpaper',
                'owner_type' => WorkstationGroup::class,
                'owner_id' => $salle->id,
            ]);

            $res = $this->resolver()->resolve($this->ctx(), Wallpaper::TYPE_WALLPAPER);

            $this->assertSame(WallpaperResolution::LEVEL_HOME_PERSO, $res->level);
            $this->assertSame($personalPath, $res->sourcePath);
            $this->assertSame('jdoe', $res->ownerName);
        } finally {
            @unlink($personalPath);
            @rmdir($tmpdir . '/jdoe/Photos');
            @rmdir($tmpdir . '/jdoe');
            @rmdir($tmpdir);
        }
    }

    // ========================================================================
    // OVERRIDE QUOTA
    // ========================================================================

    #[Test]
    public function quota_override_short_circuits(): void
    {
        $quota = Mockery::mock(XfsQuotaService::class);
        $quota->shouldReceive('isUserOverQuota')->with('jdoe')->andReturn(true);

        $ctx = $this->ctx();
        $res = $this->resolver($quota)->resolve($ctx, Wallpaper::TYPE_WALLPAPER);

        $this->assertTrue($res->isQuotaOverride);
        $this->assertSame(WallpaperResolution::LEVEL_QUOTA_OVERRIDE, $res->level);
    }

    // ========================================================================
    // LOCKSCREEN : niveaux 4/5/6/7 IGNORÉS
    // ========================================================================

    #[Test]
    public function lockscreen_ignores_user_level(): void
    {
        $user = User::query()->create([
            'login' => 'jdoe',
            'password' => 'x',
            'is_active' => true,
            'ad_rights_bitmask' => 0,
            'role' => 'eleve',
        ]);
        Wallpaper::query()->create([
            'name' => 'jdoe',
            'asset_id' => $this->assetId('lockuser'),
            'type' => 'lockscreen',
            'owner_type' => User::class,
            'owner_id' => $user->id,
        ]);

        $ctx = $this->ctx(['salle' => '', 'list_u' => []]);
        $res = $this->resolver()->resolve($ctx, Wallpaper::TYPE_LOCKSCREEN);

        $this->assertNotSame(WallpaperResolution::LEVEL_USER, $res->level);
    }

    #[Test]
    public function lockscreen_ignores_group_level(): void
    {
        $grp = UserGroup::query()->create(['name' => 'Profs']);
        Wallpaper::query()->create([
            'name' => 'Profs',
            'asset_id' => $this->assetId('lockprofs'),
            'type' => 'lockscreen',
            'owner_type' => UserGroup::class,
            'owner_id' => $grp->id,
        ]);

        $ctx = $this->ctx(['salle' => '', 'list_u' => ['Profs']]);
        $res = $this->resolver()->resolve($ctx, Wallpaper::TYPE_LOCKSCREEN);

        $this->assertNotSame(WallpaperResolution::LEVEL_GROUP, $res->level);
        $this->assertNotSame(WallpaperResolution::LEVEL_MAIN_TYPE, $res->level);
    }

    #[Test]
    public function lockscreen_supports_salle_level(): void
    {
        $salle = WorkstationGroup::query()->create(['name' => 'salle_a']);
        Wallpaper::query()->create([
            'name' => 'salle_a',
            'asset_id' => $this->assetId('locksalle'),
            'type' => 'lockscreen',
            'owner_type' => WorkstationGroup::class,
            'owner_id' => $salle->id,
        ]);

        $ctx = $this->ctx();
        $res = $this->resolver()->resolve($ctx, Wallpaper::TYPE_LOCKSCREEN);

        $this->assertSame(WallpaperResolution::LEVEL_SALLE, $res->level);
    }

    // ========================================================================
    // SOURCE PATH — résolu depuis la bibliothèque (library_path/filename)
    // ========================================================================

    #[Test]
    public function source_path_points_to_library(): void
    {
        $salle = WorkstationGroup::query()->create(['name' => 'salle_a']);
        $assetId = $this->assetId('salle_a');
        Wallpaper::query()->create([
            'name' => 'salle_a',
            'asset_id' => $assetId,
            'type' => 'wallpaper',
            'owner_type' => WorkstationGroup::class,
            'owner_id' => $salle->id,
        ]);
        $filename = WallpaperAsset::find($assetId)->filename;

        $res = $this->resolver()->resolve($this->ctx(['list_u' => []]), Wallpaper::TYPE_WALLPAPER);

        $this->assertSame($this->tmpLibrary . '/' . $filename, $res->sourcePath);
    }

    #[Test]
    public function assignment_without_asset_is_ignored(): void
    {
        // Une assignation dont l'asset a disparu (asset_id NULL) ne doit pas
        // être retournée : le INNER JOIN l'écarte, on retombe au niveau système.
        $salle = WorkstationGroup::query()->create(['name' => 'salle_a']);
        Wallpaper::query()->create([
            'name' => 'salle_a',
            'asset_id' => null,
            'type' => 'wallpaper',
            'owner_type' => WorkstationGroup::class,
            'owner_id' => $salle->id,
        ]);

        $res = $this->resolver()->resolve($this->ctx(['list_u' => []]), Wallpaper::TYPE_WALLPAPER);

        $this->assertSame(WallpaperResolution::LEVEL_DEFAULT_SYSTEM, $res->level);
    }

    // ========================================================================
    // PERF — ≤ 4 queries DB
    // ========================================================================

    #[Test]
    public function resolver_issues_at_most_4_queries(): void
    {
        $user = User::query()->create([
            'login' => 'jdoe',
            'password' => 'x',
            'is_active' => true,
            'ad_rights_bitmask' => 0,
            'role' => 'eleve',
        ]);
        UserGroup::query()->create(['name' => 'Profs']);
        UserGroup::query()->create(['name' => 'classe_6A']);
        WorkstationGroup::query()->create(['name' => 'salle_a']);

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        $this->resolver()->resolve($this->ctx(['list_u' => ['Profs', 'classe_6A']]), Wallpaper::TYPE_WALLPAPER);

        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        // ≤ 4 (1 user + 1 user_groups + 1 workstation_group + 1 wallpapers joint)
        $this->assertLessThanOrEqual(
            4,
            count($queries),
            sprintf('Expected ≤ 4 queries, got %d: %s', count($queries), json_encode(array_column($queries, 'query'))),
        );
    }
}
