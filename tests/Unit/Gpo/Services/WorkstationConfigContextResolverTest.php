<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo\Services;

use App\Dto\AppCustomization\AppContext;
use App\Dto\Wallpaper\WallpaperContext;
use App\Gpo\Services\WorkstationConfigContextResolver;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.13 — AC4.4.
 *
 * Tests unit du `WorkstationConfigContextResolver` : ≥6 cas couvrant
 *  - happy path machine + groupe physique + user
 *  - machine sans groupe attaché
 *  - machine sans user (query `user` vide)
 *  - UUID inconnu → null
 *  - mapper `toWallpaperContext()` retourne un `WallpaperContext` valide
 *  - mapper `toAppContext()` retourne un `AppContext` valide
 *  - heuristique de sélection du groupe principal (physical > logical)
 */
class WorkstationConfigContextResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Opus-14 post-review : garde-fou destructif Postgres/MySQL.
        // Iso pattern `SeedsWorkstationConfig::seedWorkstationContextSchemas`
        // — on n'exécute `Schema::dropIfExists` qu'en sqlite, sinon on skip.
        if (config('database.default') !== 'sqlite') {
            self::markTestSkipped('Test requires SQLite (uses Schema::dropIfExists destructif).');
        }

        Model::unguard();

        // Neutralise les jobs AdSync dispatchés par WorkstationGroupObserver
        // (queue sync en test → écrirait ad_guid/ad_dn sur le schéma inline
        // minimal créé plus bas, qui n'a pas ces colonnes). Iso pattern
        // PermissionServiceUnionTest / PrinterTest.
        WorkstationGroupObserver::disableSync();

        Schema::dropIfExists('workstation_group_workstation');
        Schema::dropIfExists('workstation_groups');
        Schema::dropIfExists('workstations');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('login')->unique();
            $t->string('password')->nullable();
            $t->string('role')->default('eleve');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('ad_rights_bitmask')->default(0);
            $t->timestamps();
        });

        Schema::create('workstations', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('uuid')->nullable()->index();
            $t->string('status')->default('active');
            $t->string('os')->nullable();
            $t->string('ip')->nullable();
            $t->string('mac')->nullable();
            $t->unsignedBigInteger('physical_room_id')->nullable();
            $t->boolean('managed_by_control_hub')->default(false);
            $t->timestamps();
        });

        Schema::create('workstation_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_physical')->default(true);
            $t->boolean('is_active')->default(true);
            $t->boolean('managed_by_control_hub')->default(false);
            $t->timestamps();
        });

        Schema::create('workstation_group_workstation', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('workstation_id');
            $t->unsignedBigInteger('workstation_group_id');
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    private function resolver(): WorkstationConfigContextResolver
    {
        return new WorkstationConfigContextResolver();
    }

    #[Test]
    public function happy_path_workstation_with_physical_group_and_user(): void
    {
        $ws = Workstation::create([
            'name' => 'post-01',
            'uuid' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
            'status' => 'active',
        ]);
        $wg = WorkstationGroup::create(['name' => 'salle-a', 'is_physical' => true]);
        $ws->groups()->attach($wg->id);
        User::create(['login' => 'jdoe']);

        $ctx = $this->resolver()->resolve(
            'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
            'linux',
            'jdoe',
            '',
        );

        $this->assertNotNull($ctx);
        $this->assertSame('post-01', $ctx->machineName);
        $this->assertSame('salle-a', $ctx->salleName);
        $this->assertSame('jdoe', $ctx->userLogin);
        $this->assertSame('linux', $ctx->os);
        $this->assertSame((int) $ws->id, $ctx->machineId);
        $this->assertNotNull($ctx->userId);
        $this->assertSame((int) $wg->id, $ctx->groupId);
    }

    #[Test]
    public function workstation_without_any_group_returns_null_group(): void
    {
        Workstation::create([
            'name' => 'orphan',
            'uuid' => 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb',
            'status' => 'active',
        ]);

        $ctx = $this->resolver()->resolve('bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb');

        $this->assertNotNull($ctx);
        $this->assertSame('orphan', $ctx->machineName);
        $this->assertSame('', $ctx->salleName);
        $this->assertNull($ctx->groupId);
    }

    #[Test]
    public function workstation_with_no_user_in_query_returns_null_user_id(): void
    {
        $ws = Workstation::create([
            'name' => 'post-02',
            'uuid' => 'cccccccc-cccc-4ccc-cccc-cccccccccccc',
            'status' => 'active',
        ]);

        $ctx = $this->resolver()->resolve('cccccccc-cccc-4ccc-cccc-cccccccccccc', 'linux', '');

        $this->assertNotNull($ctx);
        $this->assertSame('', $ctx->userLogin);
        $this->assertNull($ctx->userId);
    }

    #[Test]
    public function unknown_uuid_returns_null(): void
    {
        $ctx = $this->resolver()->resolve('00000000-0000-4000-0000-000000000000');

        $this->assertNull($ctx);
    }

    #[Test]
    public function to_wallpaper_context_returns_valid_wallpaper_context(): void
    {
        $ws = Workstation::create([
            'name' => 'post-03',
            'uuid' => 'dddddddd-dddd-4ddd-dddd-dddddddddddd',
            'status' => 'active',
        ]);
        $wg = WorkstationGroup::create(['name' => 'salle-b', 'is_physical' => true]);
        $ws->groups()->attach($wg->id);

        $wpCtx = $this->resolver()->toWallpaperContext(
            'dddddddd-dddd-4ddd-dddd-dddddddddddd',
            'windows',
            'alice',
            'C:\\Users\\alice',
        );

        $this->assertInstanceOf(WallpaperContext::class, $wpCtx);
        $this->assertSame('post-03', $wpCtx->machineName);
        $this->assertSame('salle-b', $wpCtx->salleName);
        $this->assertSame('alice', $wpCtx->userLogin);
        $this->assertSame('windows', $wpCtx->os);
        $this->assertSame([], $wpCtx->groupsUser);
        $this->assertNull($wpCtx->mainUserType);
    }

    #[Test]
    public function to_app_context_returns_valid_app_context(): void
    {
        $ws = Workstation::create([
            'name' => 'post-04',
            'uuid' => 'eeeeeeee-eeee-4eee-eeee-eeeeeeeeeeee',
            'status' => 'active',
        ]);

        $appCtx = $this->resolver()->toAppContext(
            'eeeeeeee-eeee-4eee-eeee-eeeeeeeeeeee',
            'linux',
            'bob',
            '',
        );

        $this->assertInstanceOf(AppContext::class, $appCtx);
        $this->assertSame('post-04', $appCtx->machineName);
        $this->assertSame('bob', $appCtx->userLogin);
        $this->assertSame('linux', $appCtx->os);
        $this->assertSame([], $appCtx->groupsUser);
        $this->assertArrayHasKey('uuid', $appCtx->raw);
        $this->assertSame('eeeeeeee-eeee-4eee-eeee-eeeeeeeeeeee', $appCtx->raw['uuid']);
    }

    #[Test]
    public function to_wallpaper_context_returns_null_when_uuid_unknown(): void
    {
        $this->assertNull($this->resolver()->toWallpaperContext('00000000-0000-4000-0000-000000000000'));
    }

    #[Test]
    public function to_app_context_returns_null_when_uuid_unknown(): void
    {
        $this->assertNull($this->resolver()->toAppContext('00000000-0000-4000-0000-000000000000'));
    }

    #[Test]
    public function primary_group_prefers_physical_over_logical(): void
    {
        $ws = Workstation::create([
            'name' => 'post-05',
            'uuid' => 'ffffffff-ffff-4fff-ffff-ffffffffffff',
            'status' => 'active',
        ]);
        $logical = WorkstationGroup::create(['name' => 'profs', 'is_physical' => false]);
        $physical = WorkstationGroup::create(['name' => 'salle-c', 'is_physical' => true]);
        // attache logique d'abord, puis physique — pour vérifier que la
        // priorité est is_physical=true (pas l'ordre d'attachement).
        $ws->groups()->attach([$logical->id, $physical->id]);

        $ctx = $this->resolver()->resolve('ffffffff-ffff-4fff-ffff-ffffffffffff');

        $this->assertNotNull($ctx);
        $this->assertSame('salle-c', $ctx->salleName);
        $this->assertSame((int) $physical->id, $ctx->groupId);
    }

    #[Test]
    public function resolve_app_policy_scope_returns_wg_and_user(): void
    {
        $ws = Workstation::create([
            'name' => 'post-06',
            'uuid' => '11111111-1111-4111-1111-111111111111',
            'status' => 'active',
        ]);
        $wg = WorkstationGroup::create(['name' => 'salle-d', 'is_physical' => true]);
        $ws->groups()->attach($wg->id);
        User::create(['login' => 'carol']);

        $scope = $this->resolver()->resolveAppPolicyScope('11111111-1111-4111-1111-111111111111', 'carol');

        $this->assertNotNull($scope['wg']);
        $this->assertSame('salle-d', $scope['wg']->name);
        $this->assertNotNull($scope['user']);
        $this->assertSame('carol', $scope['user']->login);
    }

    #[Test]
    public function resolve_app_policy_scope_unknown_uuid_returns_nulls(): void
    {
        $scope = $this->resolver()->resolveAppPolicyScope('00000000-0000-4000-0000-000000000000', 'who');

        $this->assertNull($scope['wg']);
        $this->assertNull($scope['user']);
    }
}
