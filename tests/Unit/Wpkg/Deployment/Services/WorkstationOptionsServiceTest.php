<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Services;

use App\Models\Workstation;
use App\Wpkg\Deployment\Events\WorkstationOptionsChanged;
use App\Wpkg\Deployment\Models\WpkgWorkstationOption;
use App\Wpkg\Deployment\Services\WorkstationOptionsService;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.4 / AC5, AC7.2.
 */
class WorkstationOptionsServiceTest extends TestCase
{
    private Workstation $workstation;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        $this->workstation = Workstation::create(['name' => 'PCT1', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function update_persists_overrides_and_dispatches_event(): void
    {
        Event::fake([WorkstationOptionsChanged::class]);
        $svc = new WorkstationOptionsService();

        $changed = $svc->update($this->workstation->id, ['debug' => true, 'force' => true]);

        self::assertSame(['debug', 'force'], $changed);
        self::assertSame(2, WpkgWorkstationOption::where('workstation_id', $this->workstation->id)->count());
        Event::assertDispatched(WorkstationOptionsChanged::class, function (WorkstationOptionsChanged $e) {
            return $e->workstationId === $this->workstation->id
                && $e->changedKeys === ['debug', 'force'];
        });
    }

    #[Test]
    public function update_to_default_false_deletes_existing_override(): void
    {
        WpkgWorkstationOption::create([
            'workstation_id' => $this->workstation->id,
            'option_key' => 'debug',
            'option_value' => 'true',
        ]);

        $svc = new WorkstationOptionsService();
        $changed = $svc->update($this->workstation->id, ['debug' => false]);

        self::assertSame(['debug'], $changed);
        self::assertSame(0, WpkgWorkstationOption::count());
    }

    #[Test]
    public function update_to_default_when_no_existing_row_does_not_dispatch_event(): void
    {
        Event::fake([WorkstationOptionsChanged::class]);
        $svc = new WorkstationOptionsService();

        $changed = $svc->update($this->workstation->id, ['debug' => false]);

        self::assertSame([], $changed);
        Event::assertNotDispatched(WorkstationOptionsChanged::class);
    }

    #[Test]
    public function update_rejects_unknown_option_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkstationOptionsService())->update(
            $this->workstation->id,
            ['unknown_key' => true],
        );
    }

    #[Test]
    public function update_rejects_invalid_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkstationOptionsService())->update(
            $this->workstation->id,
            ['debug' => 'banana'],
        );
    }

    #[Test]
    public function update_throws_for_unknown_workstation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkstationOptionsService())->update(99999, ['debug' => true]);
    }

    #[Test]
    public function reset_to_defaults_deletes_all_overrides_and_dispatches_event_with_all_keys(): void
    {
        WpkgWorkstationOption::create([
            'workstation_id' => $this->workstation->id,
            'option_key' => 'debug',
            'option_value' => 'true',
        ]);
        WpkgWorkstationOption::create([
            'workstation_id' => $this->workstation->id,
            'option_key' => 'force',
            'option_value' => 'true',
        ]);

        Event::fake([WorkstationOptionsChanged::class]);

        $keys = (new WorkstationOptionsService())->resetToDefaults($this->workstation->id);

        self::assertCount(8, $keys);
        self::assertContains('debug', $keys);
        self::assertContains('noforcedremove', $keys);
        self::assertSame(0, WpkgWorkstationOption::count());
        Event::assertDispatched(WorkstationOptionsChanged::class, function (WorkstationOptionsChanged $e) {
            return $e->workstationId === $this->workstation->id && count($e->changedKeys) === 8;
        });
    }

    #[Test]
    public function reset_with_no_overrides_does_not_dispatch_event(): void
    {
        Event::fake([WorkstationOptionsChanged::class]);

        (new WorkstationOptionsService())->resetToDefaults($this->workstation->id);

        Event::assertNotDispatched(WorkstationOptionsChanged::class);
    }
}
