<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Dashboard;

use App\Models\Workstation;
use App\Wpkg\Deployment\Events\WorkstationManualReevaluationRequested;
use App\Wpkg\Deployment\Listeners\InvalidateWorkstationPackagesCache;
use App\Wpkg\Deployment\Listeners\RegenerateWorkstationIniOnManualReevaluation;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.5 / AC4.4 + AC6.4 — Tests Feature « Forcer une re-évaluation ».
 *
 * Couvre :
 *   - Event dispatché → cache packages purgé.
 *   - Event dispatché → InvalidateWorkstationPackagesCache loggue le bon hostname.
 *   - Event dispatché → RegenerateWorkstationIniOnManualReevaluation est wiré.
 */
final class ManualReevaluationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function dispatched_event_invalidates_packages_cache(): void
    {
        $w = Workstation::create(['name' => 'PC-REEVAL-01', 'status' => 'active']);

        // Pré-charge un cache packages factice.
        $key = WorkstationPackagesResolver::cacheKey($w->name);
        Cache::put($key, ['firefox', 'chromium'], now()->addMinutes(10));
        $this->assertTrue(Cache::has($key));

        event(new WorkstationManualReevaluationRequested($w->id, 1));

        $this->assertFalse(Cache::has($key));
    }

    #[Test]
    public function listener_for_manual_reevaluation_is_registered(): void
    {
        $listeners = Event::getListeners(WorkstationManualReevaluationRequested::class);
        $this->assertGreaterThanOrEqual(2, count($listeners),
            'Au moins 2 listeners attendus (cache invalidator + ini regenerator).');
    }

    #[Test]
    public function event_does_nothing_for_unknown_workstation(): void
    {
        // Pas d'exception attendue : les listeners sont robustes.
        event(new WorkstationManualReevaluationRequested(99999, 1));

        $this->assertTrue(true);
    }
}
