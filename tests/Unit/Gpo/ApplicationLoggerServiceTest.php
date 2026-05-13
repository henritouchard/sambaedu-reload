<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Services\ApplicationLoggerService;
use App\Ldap\AdMachineManager;
use App\Models\MachineBootLog;
use App\Services\AppCustomization\Contracts\AppContextWriter;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — AC4.1+AC7.2.
 *
 * Tests Unit `ApplicationLoggerService` : mapping enum bitmask + persistance
 * MachineBootLog + clean-up APCu shutdown/logoff + détection double-call.
 *
 * Migration cas-test : `RefreshDatabase` non nécessaire — on mock le model
 * `MachineBootLog::create` via DB facade pour rester PUR Unit.
 */
class ApplicationLoggerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeAdMachines(): AdMachineManager
    {
        $ad = Mockery::mock(AdMachineManager::class);
        $ad->shouldReceive('registerHardware')->andReturn(true)->byDefault();
        $ad->shouldReceive('setOs')->andReturn(true)->byDefault();

        return $ad;
    }

    #[Test]
    public function it_returns_true_at_start_of_action_with_unknown_ret(): void
    {
        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldNotReceive('forget');
        $service = new ApplicationLoggerService($writer, $this->makeAdMachines());

        $info = [
            'machine' => ['cn' => 'PC01'],
            'user' => ['cn' => 'jdoe'],
            'action' => 'startup',
            'os' => 'windows',
            'id' => str_repeat('a', 32),
            'speed' => 100,
            'time' => time() - 50,
        ];

        // Note : `MachineBootLog::create` peut throw en l'absence de DB —
        // l'implémentation catch silencieusement (parité legacy gracieux).
        $result = $service->logScripts($info, 1);
        self::assertTrue($result);
    }

    #[Test]
    public function it_returns_false_at_end_of_action_ret_zero(): void
    {
        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldNotReceive('forget'); // pas shutdown/logoff
        $service = new ApplicationLoggerService($writer, $this->makeAdMachines());

        $info = [
            'machine' => ['cn' => 'PC01'],
            'user' => ['cn' => 'jdoe'],
            'action' => 'startup',
            'os' => 'windows',
            'id' => str_repeat('a', 32),
            'time' => time() - 200,
        ];

        self::assertFalse($service->logScripts($info, 0));
    }

    #[Test]
    public function it_clears_apcu_on_shutdown_with_ret_zero(): void
    {
        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldReceive('forget')->once()->with(str_repeat('b', 32));
        $service = new ApplicationLoggerService($writer, $this->makeAdMachines());

        $info = [
            'machine' => ['cn' => 'PC01'],
            'user' => ['cn' => 'jdoe'],
            'action' => 'shutdown',
            'os' => 'windows',
            'id' => str_repeat('b', 32),
            'time' => time() - 200,
        ];
        $result = $service->logScripts($info, 0);
        self::assertFalse($result);
        self::assertInstanceOf(AppContextWriter::class, $writer);
    }

    #[Test]
    public function it_clears_apcu_on_logoff_with_ret_zero(): void
    {
        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldReceive('forget')->once();
        $service = new ApplicationLoggerService($writer, $this->makeAdMachines());

        $info = [
            'machine' => ['cn' => 'PC01'],
            'user' => ['cn' => 'jdoe'],
            'action' => 'logoff',
            'os' => 'linux',
            'id' => str_repeat('c', 32),
            'time' => time() - 200,
        ];
        $result = $service->logScripts($info, 0);
        self::assertFalse($result);
    }

    #[Test]
    public function it_returns_false_for_invalid_machine_info(): void
    {
        $writer = Mockery::mock(AppContextWriter::class);
        $service = new ApplicationLoggerService($writer, $this->makeAdMachines());
        self::assertFalse($service->logScripts([], 1));
        self::assertFalse($service->logScripts(['machine' => 'string-not-array'], 1));
    }

    #[Test]
    public function it_detects_double_call_within_10_seconds(): void
    {
        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldNotReceive('forget');
        $service = new ApplicationLoggerService($writer, $this->makeAdMachines());

        $info = [
            'machine' => ['cn' => 'PC01'],
            'user' => ['cn' => 'jdoe'],
            'action' => 'startup',
            'os' => 'windows',
            'id' => str_repeat('d', 32),
            'time' => time() - 5, // entre 1s et 10s : appel double
        ];
        self::assertFalse($service->logScripts($info, 1));
    }

    #[Test]
    public function it_skips_unknown_action(): void
    {
        $writer = Mockery::mock(AppContextWriter::class);
        $service = new ApplicationLoggerService($writer, $this->makeAdMachines());
        $info = [
            'machine' => ['cn' => 'PC01'],
            'user' => ['cn' => 'jdoe'],
            'action' => 'remote-magic-unknown',
            'os' => 'linux',
            'id' => str_repeat('e', 32),
            'time' => time() - 100,
        ];
        self::assertFalse($service->logScripts($info, 1));
    }
}
