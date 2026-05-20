<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Dto\GpoSummary;
use App\Gpo\Services\GpoService;
use App\Gpo\Support\CachedGpoLookups;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;

/**
 * Tests Feature — Statut santé GPO avec cache (Story 16.14 Q1 + Q2).
 *
 * Vérifie que le filtre santé multi-valeur fonctionne avec les vraies
 * valeurs précomputées via CachedGpoLookups (résolution finding #1 review).
 */
class GpoIndexHealthStatusTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->bootstrapSpatieTables();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function makeAdmin(string $login = 'admin-health-test'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function bindOuRepo(): void
    {
        $this->app->bind(\App\Repositories\OrganizationalUnitRepository::class, function () {
            $mock = Mockery::mock(\App\Repositories\OrganizationalUnitRepository::class);
            $mock->shouldReceive('listAll')->andReturn([]);
            return $mock;
        });
    }

    private function bindCachedLookupsWith(array $linksByGuid, array $versionByGuid): void
    {
        $mock = Mockery::mock(CachedGpoLookups::class);
        foreach ($linksByGuid as $guid => $links) {
            $mock->shouldReceive('getLinksFor')->with($guid)->andReturn($links);
        }
        foreach ($versionByGuid as $guid => $version) {
            $mock->shouldReceive('getVersionNumberFor')->with($guid)->andReturn($version);
        }
        // Catch-all defensive.
        $mock->shouldReceive('getLinksFor')->andReturn([]);
        $mock->shouldReceive('getVersionNumberFor')->andReturn(null);
        $mock->shouldReceive('forgetAll')->andReturnNull();
        $mock->shouldReceive('forgetGpo')->andReturnNull();

        $this->app->forgetInstance(CachedGpoLookups::class);
        $this->app->bind(CachedGpoLookups::class, fn() => $mock);
    }

    #[Test]
    public function healthy_status_matches_when_version_positive_and_links_present(): void
    {
        $admin = $this->makeAdmin('admin-healthy');
        $this->actingAs($admin);
        $this->bindOuRepo();

        $healthyGuid = '{HEALTHY-001}';
        $healthyGpo = new GpoSummary($healthyGuid, 'GPO Saine', 65539, null, null);

        FakesGpoService::make()->withGpos([$healthyGpo])->bind($this->app);

        $this->bindCachedLookupsWith(
            linksByGuid: [
                $healthyGuid => [new GpoLink('OU=Test,DC=ex,DC=org', $healthyGuid, 'GPO Saine', false, false, 0)],
            ],
            versionByGuid: [$healthyGuid => 65539],
        );

        $component = Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterHealthStatuses', ['healthy']);

        $component->assertSet('totalFiltered', 1);
    }

    #[Test]
    public function orphaned_status_matches_when_no_links(): void
    {
        $admin = $this->makeAdmin('admin-orphaned');
        $this->actingAs($admin);
        $this->bindOuRepo();

        $orphanGuid = '{ORPHAN-001}';
        $orphanGpo = new GpoSummary($orphanGuid, 'GPO Orpheline', 65539, null, null);

        FakesGpoService::make()->withGpos([$orphanGpo])->bind($this->app);

        $this->bindCachedLookupsWith(
            linksByGuid: [$orphanGuid => []],
            versionByGuid: [$orphanGuid => 65539],
        );

        $component = Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterHealthStatuses', ['orphaned']);

        $component->assertSet('totalFiltered', 1);
    }

    #[Test]
    public function stale_status_matches_when_version_zero(): void
    {
        $admin = $this->makeAdmin('admin-stale');
        $this->actingAs($admin);
        $this->bindOuRepo();

        $staleGuid = '{STALE-001}';
        $staleGpo = new GpoSummary($staleGuid, 'GPO Obsolète', 0, null, null);

        FakesGpoService::make()->withGpos([$staleGpo])->bind($this->app);

        $this->bindCachedLookupsWith(
            linksByGuid: [$staleGuid => []],
            versionByGuid: [$staleGuid => 0],
        );

        $component = Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterHealthStatuses', ['stale']);

        $component->assertSet('totalFiltered', 1);
    }

    #[Test]
    public function multiple_health_statuses_filter_simultaneously(): void
    {
        // Q1 — checkbox group (multi) : on coche healthy + stale → on doit voir les 2.
        $admin = $this->makeAdmin('admin-multi');
        $this->actingAs($admin);
        $this->bindOuRepo();

        $healthyGuid = '{H-001}';
        $orphanGuid = '{O-001}';
        $staleGuid = '{S-001}';

        $gpos = [
            new GpoSummary($healthyGuid, 'Saine', 65539, null, null),
            new GpoSummary($orphanGuid, 'Orpheline', 65539, null, null),
            new GpoSummary($staleGuid, 'Obsolète', 0, null, null),
        ];

        FakesGpoService::make()->withGpos($gpos)->bind($this->app);

        $this->bindCachedLookupsWith(
            linksByGuid: [
                $healthyGuid => [new GpoLink('OU=X,DC=ex,DC=org', $healthyGuid, 'Saine', false, false, 0)],
                $orphanGuid => [],
                $staleGuid => [],
            ],
            versionByGuid: [
                $healthyGuid => 65539,
                $orphanGuid => 65539,
                $staleGuid => 0,
            ],
        );

        // Coche 2 statuts → on attend 2 GPOs sur 3.
        $component = Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterHealthStatuses', ['healthy', 'stale']);

        $component->assertSet('totalFiltered', 2);
    }

    #[Test]
    public function refresh_health_cache_action_flushes_and_reloads(): void
    {
        $admin = $this->makeAdmin('admin-refresh');
        $this->actingAs($admin);
        $this->bindOuRepo();

        $guid = '{REFR-001}';
        $gpo = new GpoSummary($guid, 'GPO', 65539, null, null);

        FakesGpoService::make()->withGpos([$gpo])->bind($this->app);

        $cacheMock = Mockery::mock(CachedGpoLookups::class);
        $cacheMock->shouldReceive('getLinksFor')->andReturn([]);
        $cacheMock->shouldReceive('getVersionNumberFor')->andReturn(65539);
        $cacheMock->shouldReceive('forgetAll')->atLeast()->once();
        $cacheMock->shouldReceive('forgetGpo')->andReturnNull();

        $this->app->forgetInstance(CachedGpoLookups::class);
        $this->app->bind(CachedGpoLookups::class, fn() => $cacheMock);

        $component = Livewire::test('pages::admin.settings.gpo.index')
            ->call('refreshHealthCache');

        $component->assertHasNoErrors();
        $component->assertDispatched('toastMagic');
    }
}
