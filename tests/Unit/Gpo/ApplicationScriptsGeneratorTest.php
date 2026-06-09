<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Services\ApplicationScriptsGenerator;
use App\Ldap\AdMachineManager;
use App\LdapModels\MachineModel;
use App\Repositories\UserRepository;
use App\Repositories\WorkstationRepository;
use App\Services\AppCustomization\Contracts\AppContextWriter;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — AC1.3 + AC1.4 + AC1.5 + AC2.1.
 *
 * Tests Unit `ApplicationScriptsGenerator` : mocks AD complets, vérifie :
 *  - cas dégénérés (`Debian-gdm`, `remote-*-system`, machine vide)
 *  - side effects startup-only (check/registerHardware/setOs)
 *  - side effect logon-only (listRemoteConnexion)
 *  - pose APCu (avec structure compatible 4.7/4.8)
 *  - retour `[]` si machine introuvable
 */
class ApplicationScriptsGeneratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeGenerator(
        ?WorkstationRepository $workstations = null,
        ?UserRepository $users = null,
        ?AdMachineManager $adMachines = null,
        ?AppContextWriter $writer = null,
    ): ApplicationScriptsGenerator {
        return new ApplicationScriptsGenerator(
            $workstations ?? Mockery::mock(WorkstationRepository::class),
            $users ?? Mockery::mock(UserRepository::class),
            $adMachines ?? Mockery::mock(AdMachineManager::class),
            $writer ?? Mockery::mock(AppContextWriter::class),
        );
    }

    private function machineMock(string $cn, array $memberOf = [], string $dn = ''): MachineModel
    {
        $m = Mockery::mock(MachineModel::class);
        $m->shouldReceive('getMachineName')->andReturn($cn);
        $m->shouldReceive('getDn')->andReturn($dn !== '' ? $dn : 'CN=' . $cn . ',OU=salle1,DC=test,DC=local');
        $m->shouldReceive('getAttribute')->with('memberof')->andReturn($memberOf);
        $m->shouldReceive('getAttribute')->with('netbootguid')->andReturn([]);
        $m->shouldReceive('getIpAddress')->andReturn(null);
        return $m;
    }

    #[Test]
    public function it_returns_empty_for_debian_gdm_logon(): void
    {
        $gen = $this->makeGenerator();
        $result = $gen->resolveInfo([
            'machine' => 'PC01',
            'action' => 'logon',
            'os' => 'linux',
            'user' => 'Debian-gdm',
            'id' => '',
            'application' => '',
            'uuid' => '',
            'interpreter' => '',
            'speed' => 0,
            'userprofile' => '',
        ]);
        self::assertSame([], $result);
    }

    #[Test]
    public function it_returns_empty_for_root_logoff(): void
    {
        $gen = $this->makeGenerator();
        $result = $gen->resolveInfo([
            'machine' => 'PC01',
            'action' => 'logoff',
            'os' => 'linux',
            'user' => 'root',
            'id' => '',
            'application' => '',
            'uuid' => '',
            'interpreter' => '',
            'speed' => 0,
            'userprofile' => '',
        ]);
        self::assertSame([], $result);
    }

    #[Test]
    public function it_returns_empty_for_remote_system_action(): void
    {
        $gen = $this->makeGenerator();
        $result = $gen->resolveInfo([
            'machine' => 'PC01',
            'action' => 'remote-logon-system',
            'os' => 'windows',
            'user' => '',
            'id' => '',
            'application' => '',
            'uuid' => '',
            'interpreter' => '',
            'speed' => 0,
            'userprofile' => '',
        ]);
        self::assertSame([], $result);
    }

    #[Test]
    public function it_returns_empty_for_logon_system_without_machine(): void
    {
        $gen = $this->makeGenerator();
        $result = $gen->resolveInfo([
            'machine' => '',
            'action' => 'logon-system',
            'os' => 'windows',
            'user' => 'jdoe',
            'id' => '',
            'application' => '',
            'uuid' => '',
            'interpreter' => '',
            'speed' => 0,
            'userprofile' => '',
        ]);
        self::assertSame([], $result);
    }

    #[Test]
    public function it_returns_empty_when_machine_not_in_ldap(): void
    {
        $workstations = Mockery::mock(WorkstationRepository::class);
        $workstations->shouldReceive('findByName')->andReturn(null);
        $ad = Mockery::mock(AdMachineManager::class);
        // check() called at startup only — should be attempted even if absent
        $ad->shouldReceive('check')->andReturn(true);
        $gen = $this->makeGenerator(workstations: $workstations, adMachines: $ad);

        $result = $gen->resolveInfo([
            'machine' => 'pc-ghost',
            'action' => 'startup',
            'os' => 'windows',
            'user' => '',
            'id' => '',
            'application' => '',
            'uuid' => '',
            'interpreter' => '',
            'speed' => 0,
            'userprofile' => '',
        ]);
        self::assertSame([], $result);
    }

    #[Test]
    public function it_invokes_ad_side_effects_at_startup_only(): void
    {
        $workstations = Mockery::mock(WorkstationRepository::class);
        $workstations->shouldReceive('findByName')->andReturn($this->machineMock('pc01'));

        // Review #1 : `registerHardware` est désormais porté par
        // `ApplicationLoggerService` (parité legacy footer `ret=0`), pas par
        // le Generator. Le Generator ne fait plus que `check` au startup.
        $ad = Mockery::mock(AdMachineManager::class);
        $ad->shouldReceive('check')->once()->with('pc01')->andReturn(true);
        $ad->shouldNotReceive('registerHardware');
        $ad->shouldNotReceive('listRemoteConnexion');

        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldReceive('write')->once();

        $gen = $this->makeGenerator($workstations, null, $ad, $writer);
        $result = $gen->resolveInfo([
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'windows',
            'user' => '',
            'id' => '',
            'application' => '',
            'uuid' => '01234567-89ab-cdef-0123-456789abcdef',
            'interpreter' => '',
            'speed' => 0,
            'userprofile' => '',
        ]);
        self::assertNotEmpty($result);
        self::assertSame('startup', $result['action']);
        self::assertSame('cmd', $result['interpreter']);
    }

    #[Test]
    public function it_invokes_list_remote_connexion_at_logon_only(): void
    {
        $workstations = Mockery::mock(WorkstationRepository::class);
        $workstations->shouldReceive('findByName')->andReturn($this->machineMock('pc01'));

        // Mock User concret (return type strict `?App\Types\User`).
        $userMock = Mockery::mock(\App\Types\User::class);
        $userMock->shouldReceive('getLogin')->andReturn('jdoe');
        $userMock->shouldReceive('getAttribute')->with('memberof')->andReturn([]);
        $userMock->shouldReceive('getDn')->andReturn('CN=jdoe,OU=users,DC=test,DC=local');
        $users = Mockery::mock(UserRepository::class);
        $users->shouldReceive('findByLogin')->with('jdoe')->andReturn($userMock);

        $ad = Mockery::mock(AdMachineManager::class);
        $ad->shouldNotReceive('check'); // pas startup
        $ad->shouldReceive('listRemoteConnexion')->once()->with('pc01', 'jdoe')->andReturn('');

        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldReceive('write')->once();

        $gen = $this->makeGenerator($workstations, $users, $ad, $writer);
        $result = $gen->resolveInfo([
            'machine' => 'pc01',
            'action' => 'logon',
            'os' => 'windows',
            'user' => 'jdoe',
            'id' => '',
            'application' => '',
            'uuid' => '',
            'interpreter' => '',
            'speed' => 0,
            'userprofile' => '',
        ]);
        self::assertNotEmpty($result);
        self::assertSame('logon', $result['action']);
        self::assertSame('jdoe', $result['user']['cn']);
    }

    #[Test]
    public function it_writes_apcu_context_with_iso_legacy_structure(): void
    {
        $workstations = Mockery::mock(WorkstationRepository::class);
        $workstations->shouldReceive('findByName')->andReturn($this->machineMock('pc01'));
        $ad = Mockery::mock(AdMachineManager::class);
        $ad->shouldReceive('check')->andReturn(true);
        $ad->shouldReceive('registerHardware')->andReturn(true)->byDefault();

        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldReceive('write')
            ->once()
            ->with(
                Mockery::on(fn(string $id): bool => preg_match('/^[a-f0-9]{32}$/', $id) === 1),
                Mockery::on(function (array $context): bool {
                    // Vérifie la structure iso-legacy attendue par le lecteur 4.8.
                    return isset($context['machine']['cn'])
                        && isset($context['user'])
                        && isset($context['list_u'])
                        && isset($context['os'])
                        && isset($context['time'])
                        && isset($context['action'])
                        && isset($context['salle']);
                }),
                1800
            );

        $gen = $this->makeGenerator($workstations, null, $ad, $writer);
        $result = $gen->resolveInfo([
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'windows',
            'user' => '',
            'id' => '',
            'application' => '',
            'uuid' => '',
            'interpreter' => '',
            'speed' => 0,
            'userprofile' => '',
        ]);
        self::assertNotEmpty($result);
    }

    #[Test]
    public function it_strips_ltsp_l_prefix_from_machine_name(): void
    {
        $workstations = Mockery::mock(WorkstationRepository::class);
        $workstations->shouldReceive('findByName')
            ->once()
            ->with('pc01')   // pas `l-pc01`
            ->andReturn($this->machineMock('pc01'));
        $ad = Mockery::mock(AdMachineManager::class);
        $ad->shouldReceive('check')->andReturn(true);
        $ad->shouldReceive('registerHardware')->andReturn(true)->byDefault();
        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldReceive('write');

        $gen = $this->makeGenerator($workstations, null, $ad, $writer);
        $result = $gen->resolveInfo([
            'machine' => 'l-pc01',
            'action' => 'startup',
            'os' => 'linux',
            'user' => '',
            'id' => '',
            'application' => '',
            'uuid' => '',
            'interpreter' => '',
            'speed' => 0,
            'userprofile' => '',
        ]);
        self::assertNotEmpty($result);
        self::assertSame('pc01', $result['machine']['cn']);
    }
}
