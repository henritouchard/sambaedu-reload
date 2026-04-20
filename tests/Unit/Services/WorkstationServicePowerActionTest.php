<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\LdapModels\MachineModel;
use App\Repositories\WorkstationRepository;
use App\Services\Parc\MachinePowerService;
use App\Services\WorkstationService;
use Mockery;
use Tests\TestCase;

class WorkstationServicePowerActionTest extends TestCase
{
    private WorkstationService $service;
    private WorkstationRepository $workstationRepository;
    private MachinePowerService $machinePowerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workstationRepository = Mockery::mock(WorkstationRepository::class);
        $this->machinePowerService = Mockery::mock(MachinePowerService::class);

        $this->service = new WorkstationService(
            $this->workstationRepository,
            $this->machinePowerService,
        );
    }

    public function test_execute_wake_action_dispatches_to_wol(): void
    {
        $machine = $this->mockMachine('pc-01', '192.168.1.50', 'aa:bb:cc:dd:ee:ff');
        $this->workstationRepository->shouldReceive('findByName')->with('pc-01')->andReturn($machine);

        $this->machinePowerService->shouldReceive('wakeOnLan')
            ->with('aa:bb:cc:dd:ee:ff', '192.168.1.50', 'pc-01')
            ->once()
            ->andReturn(['success' => true, 'code' => 202, 'message' => 'WOL envoyé']);

        $result = $this->service->executePowerAction(['pc-01'], 'wake');

        $this->assertEquals(1, $result['requested_count']);
        $this->assertEquals(1, $result['success_count']);
        $this->assertEquals(0, $result['failed_count']);
        $this->assertTrue($result['results'][0]['success']);
        $this->assertEquals(202, $result['results'][0]['code']);
    }

    public function test_execute_shutdown_action_dispatches_to_shutdown(): void
    {
        $machine = $this->mockMachine('pc-01', '192.168.1.50', 'aa:bb:cc:dd:ee:ff');
        $this->workstationRepository->shouldReceive('findByName')->with('pc-01')->andReturn($machine);

        $this->machinePowerService->shouldReceive('shutdown')
            ->with('pc-01', '192.168.1.50', false)
            ->once()
            ->andReturn(['success' => true, 'code' => 201, 'message' => 'OK']);

        $result = $this->service->executePowerAction(['pc-01'], 'shutdown');

        $this->assertEquals(1, $result['success_count']);
    }

    public function test_execute_shutdown_force_dispatches_to_shutdown_with_force_true(): void
    {
        // AC6 story 4-2 — l'action `shutdown-force` doit arriver au service
        // avec $force = true.
        $machine = $this->mockMachine('pc-01', '192.168.1.50', 'aa:bb:cc:dd:ee:ff');
        $this->workstationRepository->shouldReceive('findByName')->with('pc-01')->andReturn($machine);

        $this->machinePowerService->shouldReceive('shutdown')
            ->with('pc-01', '192.168.1.50', true)
            ->once()
            ->andReturn(['success' => true, 'code' => 201, 'message' => 'Arrêt (forcée) OK']);

        $result = $this->service->executePowerAction(['pc-01'], 'shutdown-force');

        $this->assertEquals(1, $result['success_count']);
        $this->assertEquals(201, $result['results'][0]['code']);
    }

    public function test_execute_restart_action_dispatches_to_reboot(): void
    {
        $machine = $this->mockMachine('pc-01', '192.168.1.50', 'aa:bb:cc:dd:ee:ff');
        $this->workstationRepository->shouldReceive('findByName')->with('pc-01')->andReturn($machine);

        $this->machinePowerService->shouldReceive('reboot')
            ->with('pc-01', '192.168.1.50', 'aa:bb:cc:dd:ee:ff')
            ->once()
            ->andReturn(['success' => true, 'code' => 201, 'message' => 'OK']);

        $result = $this->service->executePowerAction(['pc-01'], 'restart');

        $this->assertEquals(1, $result['success_count']);
    }

    public function test_invalid_action_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->executePowerAction(['pc-01'], 'invalid');
    }

    public function test_machine_not_found_returns_404(): void
    {
        $this->workstationRepository->shouldReceive('findByName')->with('unknown-pc')->andReturn(null);
        $this->workstationRepository->shouldReceive('findByHostname')->with('unknown-pc')->andReturn(null);

        $result = $this->service->executePowerAction(['unknown-pc'], 'wake');

        $this->assertEquals(1, $result['requested_count']);
        $this->assertEquals(0, $result['success_count']);
        $this->assertEquals(1, $result['failed_count']);
        $this->assertEquals(404, $result['results'][0]['code']);
    }

    public function test_multiple_machines_are_processed(): void
    {
        $machine1 = $this->mockMachine('pc-01', '192.168.1.50', 'aa:bb:cc:dd:ee:f1');
        $machine2 = $this->mockMachine('pc-02', '192.168.1.51', 'aa:bb:cc:dd:ee:f2');

        $this->workstationRepository->shouldReceive('findByName')->with('pc-01')->andReturn($machine1);
        $this->workstationRepository->shouldReceive('findByName')->with('pc-02')->andReturn($machine2);

        $this->machinePowerService->shouldReceive('wakeOnLan')
            ->with('aa:bb:cc:dd:ee:f1', '192.168.1.50', 'pc-01')
            ->andReturn(['success' => true, 'code' => 202, 'message' => 'OK']);
        $this->machinePowerService->shouldReceive('wakeOnLan')
            ->with('aa:bb:cc:dd:ee:f2', '192.168.1.51', 'pc-02')
            ->andReturn(['success' => true, 'code' => 202, 'message' => 'OK']);

        $result = $this->service->executePowerAction(['pc-01', 'pc-02'], 'wake');

        $this->assertEquals(2, $result['requested_count']);
        $this->assertEquals(2, $result['success_count']);
    }

    public function test_error_code_203_is_counted_as_failure(): void
    {
        $machine = $this->mockMachine('pc-01', '192.168.1.50', 'aa:bb:cc:dd:ee:ff');
        $this->workstationRepository->shouldReceive('findByName')->with('pc-01')->andReturn($machine);

        $this->machinePowerService->shouldReceive('shutdown')
            ->andReturn(['success' => false, 'code' => 203, 'message' => 'Échec']);

        $result = $this->service->executePowerAction(['pc-01'], 'shutdown');

        $this->assertEquals(0, $result['success_count']);
        $this->assertEquals(1, $result['failed_count']);
    }

    public function test_no_require_once_in_workstation_service(): void
    {
        $source = file_get_contents(app_path('Services/WorkstationService.php'));
        $this->assertStringNotContainsString('require_once', $source);
    }

    private function mockMachine(string $name, string $ip, string $mac): MachineModel
    {
        $machine = Mockery::mock(MachineModel::class);
        $machine->shouldReceive('getMachineName')->andReturn($name);
        $machine->shouldReceive('getIpAddress')->andReturn($ip);
        $machine->shouldReceive('getMacAddress')->andReturn($mac);
        return $machine;
    }
}
