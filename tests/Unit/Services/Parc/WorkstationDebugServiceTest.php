<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Parc;

use App\Models\Workstation;
use App\Services\Parc\WorkstationDebugService;
use App\Wpkg\Deployment\Events\WorkstationOptionsChanged;
use App\Wpkg\Deployment\Models\WpkgWorkstationOption;
use App\Wpkg\Deployment\Services\WorkstationOptionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `WorkstationDebugService` — point d'entrée UNIQUE du toggle debug.
 *
 * Vérifie l'asservissement atomique des DEUX canaux : `workstations.debug`
 * (exposé à l'agent) et les options WPKG `.ini` `debug`/`logdebug`. L'event
 * de régénération `.ini` est faké (pas d'écriture filesystem en test).
 */
class WorkstationDebugServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WorkstationDebugService
    {
        return new WorkstationDebugService(new WorkstationOptionsService());
    }

    #[Test]
    public function enabling_debug_sets_flag_and_wpkg_debug_logdebug_options(): void
    {
        Event::fake([WorkstationOptionsChanged::class]);
        $ws = Workstation::factory()->create(['debug' => false]);

        $this->service()->setDebug($ws, true);

        self::assertTrue($ws->fresh()->debug);

        $options = WpkgWorkstationOption::where('workstation_id', $ws->id)
            ->pluck('option_value', 'option_key');
        self::assertSame('true', $options['debug'] ?? null);
        self::assertSame('true', $options['logdebug'] ?? null);

        Event::assertDispatched(WorkstationOptionsChanged::class);
    }

    #[Test]
    public function disabling_debug_clears_flag_and_removes_wpkg_overrides(): void
    {
        Event::fake([WorkstationOptionsChanged::class]);
        $ws = Workstation::factory()->create(['debug' => true]);
        foreach (['debug', 'logdebug'] as $key) {
            WpkgWorkstationOption::create([
                'workstation_id' => $ws->id,
                'option_key' => $key,
                'option_value' => 'true',
            ]);
        }

        $this->service()->setDebug($ws, false);

        self::assertFalse($ws->fresh()->debug);
        // 'false' = défaut legacy → l'override est supprimé (parité 15.4).
        self::assertSame(
            0,
            WpkgWorkstationOption::where('workstation_id', $ws->id)
                ->whereIn('option_key', ['debug', 'logdebug'])
                ->count(),
        );
    }
}
