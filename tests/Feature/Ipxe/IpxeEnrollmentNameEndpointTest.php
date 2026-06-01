<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Ipxe\Services\WorkstationEnrollmentService;
use App\Ldap\AdMachineManager;
use App\Models\Workstation;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;
use Tests\Support\IpxeAuthTestHelper;

/**
 * Story 3.3 — AC9.2 / T6.3.
 *
 * Tests Feature de la route native `GET|POST /ipxe/enrollment/name`.
 *
 * `AdMachineManager` est **mocké** dans le container pour éviter tout appel
 * `samba-tool` réel (iso pattern 16.7).
 */
class IpxeEnrollmentNameEndpointTest extends TestCase
{
    use IpxeAuthTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bypassIpxeAuth();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
            'sambaedu.legacy_ldap.suffix' => '',
        ]);

        // Mock AD universel (par défaut tout OK).
        $adMock = Mockery::mock(AdMachineManager::class);
        $adMock->shouldReceive('check')->andReturn(true)->byDefault();
        $adMock->shouldReceive('registerHardware')->andReturn(true)->byDefault();
        $adMock->shouldReceive('renameComputer')->andReturn(true)->byDefault();
        $this->app->instance(AdMachineManager::class, $adMock);

        // Reset des singletons qui dépendent transitivement d'AdMachineManager
        // pour qu'ils soient ré-instanciés avec le mock.
        $this->app->forgetInstance(WorkstationEnrollmentService::class);
        $this->app->forgetInstance(\App\Ipxe\Services\IpxeEnrollmentOrchestrator::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_handshake_when_no_params(): void
    {
        $response = $this->get('/ipxe/enrollment/name');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('ipxe/enrollment/name##params', $body);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_returns_input_prompt_when_mac_uuid_set_but_no_new_name(): void
    {
        $response = $this->post('/ipxe/enrollment/name', [
            'mac' => 'aa:bb:cc:dd:ee:11',
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'platform' => 'legacy',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('read name', $body);
        self::assertStringContainsString('ipxe/enrollment/name##params', $body);
    }

    #[Test]
    public function it_creates_workstation_when_uuid_unknown_and_new_name_provided(): void
    {
        $response = $this->post('/ipxe/enrollment/name', [
            'mac' => 'aa:bb:cc:dd:ee:22',
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'platform' => 'legacy',
            'new_name' => 'pc-create-22',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('OK ! nom pc-create-22 reserve', $body);

        self::assertDatabaseHas('workstations', [
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'name' => 'pc-create-22',
        ]);

        // F13 (review 3.3) : MachineBootLog peuplé pour le flow name (created).
        self::assertDatabaseHas('machine_boot_logs', [
            'action' => 'ipxe_enroll_name',
            'machine_name' => 'pc-create-22',
            'initiated_by' => 'ipxe',
            'success' => true,
        ]);
    }

    #[Test]
    public function it_returns_idempotent_message_when_uuid_already_owns_name(): void
    {
        Workstation::create([
            'name' => 'pc-existing-33',
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'mac' => 'aa:bb:cc:dd:ee:33',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/enrollment/name', [
            'mac' => 'aa:bb:cc:dd:ee:33',
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'new_name' => 'pc-existing-33',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('deja enregistree', $body);

        // F13 (review 3.3) : MachineBootLog peuplé même sur cas idempotent (same_name).
        self::assertDatabaseHas('machine_boot_logs', [
            'action' => 'ipxe_enroll_name',
            'machine_name' => 'pc-existing-33',
            'initiated_by' => 'ipxe',
            'success' => true,
        ]);
    }

    #[Test]
    public function it_returns_error_message_when_name_already_taken(): void
    {
        Workstation::create([
            'name' => 'pc-taken-44',
            'uuid' => '44444444-4444-4444-4444-444444444444',
            'mac' => 'aa:bb:cc:dd:ee:44',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/enrollment/name', [
            'mac' => 'aa:bb:cc:dd:ee:55',
            'uuid' => '55555555-5555-5555-5555-555555555555',
            'new_name' => 'pc-taken-44',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('ERREUR', $body);
        self::assertStringContainsString('pc-taken-44', $body);

        // F13 (review 3.3) : aucun MachineBootLog créé pour le poste rejeté (UUID `...555`).
        self::assertDatabaseMissing('machine_boot_logs', [
            'action' => 'ipxe_enroll_name',
            'machine_name' => 'pc-taken-44',
            'workstation_id' => null,
        ]);
    }

    #[Test]
    public function it_returns_error_when_injection_attempt(): void
    {
        $response = $this->post('/ipxe/enrollment/name', [
            'mac' => 'aa:bb:cc:dd:ee:99',
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'new_name' => "evil; rm -rf /",
        ]);

        // Doit toujours répondre 200 (parité service — un firmware iPXE doit
        // recevoir un menu, pas une 422).
        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('ERREUR', $body);
        self::assertStringNotContainsString('rm -rf', $body);

        // Aucune Workstation créée.
        self::assertDatabaseMissing('workstations', [
            'uuid' => '99999999-9999-9999-9999-999999999999',
        ]);

        // F13 (review 3.3) : aucun MachineBootLog créé sur tentative d'injection.
        self::assertDatabaseMissing('machine_boot_logs', [
            'action' => 'ipxe_enroll_name',
            'workstation_id' => null,
        ]);
    }

    #[Test]
    public function it_renames_workstation_when_uuid_known_and_new_name_unique(): void
    {
        Workstation::create([
            'name' => 'old-name-66',
            'uuid' => '66666666-6666-6666-6666-666666666666',
            'mac' => 'aa:bb:cc:dd:ee:66',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/enrollment/name', [
            'mac' => 'aa:bb:cc:dd:ee:66',
            'uuid' => '66666666-6666-6666-6666-666666666666',
            'new_name' => 'new-name-66',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('OK ! nom new-name-66 reserve', $body);

        self::assertDatabaseHas('workstations', [
            'uuid' => '66666666-6666-6666-6666-666666666666',
            'name' => 'new-name-66',
        ]);

        // F13 (review 3.3) : MachineBootLog peuplé pour le flow name (renamed).
        self::assertDatabaseHas('machine_boot_logs', [
            'action' => 'ipxe_enroll_name',
            'machine_name' => 'new-name-66',
            'initiated_by' => 'ipxe',
            'success' => true,
        ]);
    }
}
