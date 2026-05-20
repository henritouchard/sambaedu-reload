<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Gpo\Dto\GpoSummary;
use App\Gpo\Services\GpoService;
use App\Gpo\Support\CachedGpoLookups;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature — Commande `gpo:warm-cache` (Story 16.14 Q2).
 */
class GpoWarmCacheCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();
        parent::tearDown();
    }

    private function bindMockGpoService(array $gpos = [], bool $expectGet = true): void
    {
        $mock = Mockery::mock(GpoService::class);
        $collection = collect($gpos);
        $mock->shouldReceive('list')->andReturn($collection);
        $mock->shouldReceive('listContainers')->andReturn([]);
        if ($expectGet) {
            $mock->shouldReceive('get')->andReturn(new GpoSummary(
                name: '{TEST}',
                displayName: 'Test',
                versionNumber: 65539,
                dn: null,
                path: null,
            ));
        }
        $this->app->bind(GpoService::class, fn() => $mock);
        // Rebind cache singleton avec le mock fraichement bindé.
        $this->app->forgetInstance(CachedGpoLookups::class);
    }

    #[Test]
    public function warm_cache_succeeds_with_zero_gpos(): void
    {
        $this->bindMockGpoService([], expectGet: false);

        $exit = $this->artisan('gpo:warm-cache')
            ->expectsOutputToContain('Warm-up cache santé GPO')
            ->assertExitCode(0);
    }

    #[Test]
    public function warm_cache_iterates_all_gpos(): void
    {
        $gpos = [
            new GpoSummary('{A1}', 'GPO A', 65539, null, null),
            new GpoSummary('{A2}', 'GPO B', 131072, null, null),
        ];
        $this->bindMockGpoService($gpos);

        $exit = $this->artisan('gpo:warm-cache')
            ->expectsOutputToContain('2 GPOs')
            ->assertExitCode(0);
    }

    #[Test]
    public function warm_cache_force_flushes_before_warming(): void
    {
        $gpos = [new GpoSummary('{F1}', 'GPO F', 65539, null, null)];
        $this->bindMockGpoService($gpos);

        // Pré-peupler le cache avec des valeurs vieilles.
        Cache::put('gpo:version:{F1}', 0, 3600);
        Cache::put('gpo:cache:index', ['{F1}'], 3600);

        $this->artisan('gpo:warm-cache --force')
            ->expectsOutputToContain('flush du cache complet')
            ->assertExitCode(0);
    }
}
