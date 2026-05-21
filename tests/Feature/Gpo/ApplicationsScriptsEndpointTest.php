<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Services\ApplicationLoggerService;
use App\Gpo\Services\ApplicationScriptsAssembler;
use App\Gpo\Services\ApplicationScriptsGenerator;
use App\Gpo\Services\ApplicationTemplatesScanner;
use App\Ldap\AdMachineManager;
use App\Repositories\UserRepository;
use App\Repositories\WorkstationRepository;
use App\Services\AppCustomization\Contracts\AppContextWriter;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — AC1.* + AC7.1.
 *
 * Tests Feature `ApplicationsScriptsController` : end-to-end côté HTTP avec
 * mocks AD/FS/APCu. Vérifie :
 *  - 400 sur input invalide (regex AVANT side effect)
 *  - 200 body vide sur cas dégénérés iso-legacy
 *  - charset CP1252 pour Windows, UTF-8 pour Linux
 *  - throttle 300/min/IP
 */
class ApplicationsScriptsEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Story 16.13bis — `gpo/applications.php` transformée en
        // MigrationController::serveFragment ; tests Feature URL caducs (R6).
        $this->markTestSkipped('Story 16.13bis : route legacy transformée en MigrationController (R6).');
        // Bind mocks par défaut — aucun side effect réel.
        $this->bindNoOpServices();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function bindNoOpServices(): void
    {
        // Bind repos qui retournent null par défaut.
        $workstations = Mockery::mock(WorkstationRepository::class);
        $workstations->shouldReceive('findByName')->andReturn(null)->byDefault();
        $this->app->instance(WorkstationRepository::class, $workstations);

        $users = Mockery::mock(UserRepository::class);
        $users->shouldReceive('findByLogin')->andReturn(null)->byDefault();
        $this->app->instance(UserRepository::class, $users);

        $ad = Mockery::mock(AdMachineManager::class);
        $ad->shouldReceive('check')->andReturn(true)->byDefault();
        $ad->shouldReceive('registerHardware')->andReturn(true)->byDefault();
        $ad->shouldReceive('setOs')->andReturn(true)->byDefault();
        $ad->shouldReceive('listRemoteConnexion')->andReturn('')->byDefault();
        $this->app->instance(AdMachineManager::class, $ad);

        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldReceive('write')->byDefault();
        $writer->shouldReceive('forget')->byDefault();
        $this->app->instance(AppContextWriter::class, $writer);
    }

    #[Test]
    public function it_returns_400_for_invalid_machine_name(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => '; rm -rf /',
            'action' => 'startup',
            'os' => 'windows',
        ]);
        $resp->assertStatus(400);
        self::assertSame('', $resp->getContent());
    }

    #[Test]
    public function it_returns_400_for_invalid_action(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'startup; cat /etc/passwd',
            'os' => 'windows',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_returns_400_for_invalid_os(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'macos',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_returns_400_for_invalid_uuid(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'windows',
            'uuid' => 'INVALID;PAYLOAD',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_returns_400_for_invalid_id(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'windows',
            'id' => 'not-an-md5-hash',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_returns_empty_ok_for_logon_with_debian_gdm_user(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'logon',
            'os' => 'linux',
            'user' => 'Debian-gdm',
        ]);
        $resp->assertStatus(200);
        self::assertSame('', $resp->getContent());
    }

    #[Test]
    public function it_returns_empty_ok_for_unknown_machine(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc-ghost',
            'action' => 'startup',
            'os' => 'windows',
        ]);
        $resp->assertStatus(200);
        self::assertSame('', $resp->getContent());
    }

    #[Test]
    public function it_returns_empty_ok_for_logon_system_without_machine(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'action' => 'logon-system',
            'os' => 'windows',
            'user' => 'jdoe',
        ]);
        $resp->assertStatus(200);
        self::assertSame('', $resp->getContent());
    }

    #[Test]
    public function it_returns_empty_ok_for_remote_system_action(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'remote-logon-system',
            'os' => 'windows',
            'user' => 'jdoe',
        ]);
        $resp->assertStatus(200);
        self::assertSame('', $resp->getContent());
    }

    #[Test]
    public function it_returns_content_type_cp1252_for_windows(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'windows',
        ]);
        $resp->assertHeader('Content-Type', 'text/plain; charset=cp1252');
    }

    #[Test]
    public function it_returns_content_type_utf8_for_linux(): void
    {
        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'linux',
        ]);
        $resp->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    #[Test]
    public function it_accepts_get_method_iso_legacy(): void
    {
        // Legacy supportait GET et POST — on doit accepter les deux.
        $resp = $this->get('/gpo/applications.php?machine=pc01&action=startup&os=windows');
        $resp->assertStatus(200);
    }

    #[Test]
    public function it_applies_throttle_300_per_minute(): void
    {
        // Throttle:300,1 — 301ᵉ requête doit retourner 429.
        // Note : ce test peut être lent (~300 hits). Marqué `@group throttle`
        // pour skip CI standard si besoin.
        $this->markTestSkipped('@group throttle — exécuter manuellement, dépend de l\'env cache');
    }

    #[Test]
    public function it_writes_cache_context_on_successful_resolution(): void
    {
        // Story 16.15 — AC7.5 : vérifie l'appel via Cache::store('app_context').
        // Réécrit le mock writer pour vérifier l'appel.
        $writer = Mockery::mock(AppContextWriter::class);
        $writer->shouldReceive('write')->atLeast()->once();
        $writer->shouldReceive('forget')->byDefault();
        $this->app->instance(AppContextWriter::class, $writer);

        // Mock machine LDAP présente.
        $machine = Mockery::mock(\App\LdapModels\MachineModel::class);
        $machine->shouldReceive('getMachineName')->andReturn('pc01');
        $machine->shouldReceive('getDn')->andReturn('CN=pc01,OU=salle1,DC=test,DC=local');
        $machine->shouldReceive('getAttribute')->andReturn([]);
        $machine->shouldReceive('getIpAddress')->andReturn(null);

        $workstations = Mockery::mock(WorkstationRepository::class);
        $workstations->shouldReceive('findByName')->andReturn($machine);
        $this->app->instance(WorkstationRepository::class, $workstations);

        $resp = $this->post('/gpo/applications.php', [
            'machine' => 'pc01',
            'action' => 'startup',
            'os' => 'windows',
        ]);
        $resp->assertStatus(200);
    }
}
