<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\Workstation;
use App\Models\WorkstationGroup;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;
use Tests\Support\IpxeAuthTestHelper;

/**
 * Story 3.3 — AC9.2 / T6.5.
 *
 * Tests Feature de la route native `GET|POST /ipxe/enrollment/room`.
 */
class IpxeEnrollmentRoomEndpointTest extends TestCase
{
    use IpxeAuthTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bypassIpxeAuth();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    #[Test]
    public function it_returns_handshake_when_no_params(): void
    {
        $response = $this->get('/ipxe/enrollment/room');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('ipxe/enrollment/room##params', $body);
    }

    #[Test]
    public function it_returns_room_menu_listing_for_known_workstation(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-r-1',
            'uuid' => '11111111-2222-1111-1111-111111111111',
            'mac' => 'aa:bb:cc:dd:ee:11',
            'status' => 'active',
        ]);
        WorkstationGroup::create([
            'name' => 'salle-A',
            'is_physical' => true,
            'is_active' => true,
        ]);
        WorkstationGroup::create([
            'name' => 'salle-B',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $response = $this->post('/ipxe/enrollment/room', [
            'mac' => 'aa:bb:cc:dd:ee:11',
            'uuid' => '11111111-2222-1111-1111-111111111111',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('Enregistrement de la salle', $body);
        self::assertStringContainsString('salle-A', $body);
        self::assertStringContainsString('salle-B', $body);
    }

    #[Test]
    public function it_assigns_room_when_room_id_provided(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-r-2',
            'uuid' => '11111111-2222-1111-1111-222222222222',
            'mac' => 'aa:bb:cc:dd:ee:12',
            'status' => 'active',
        ]);
        $room = WorkstationGroup::create([
            'name' => 'salle-cible',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $response = $this->post('/ipxe/enrollment/room', [
            'mac' => 'aa:bb:cc:dd:ee:12',
            'uuid' => '11111111-2222-1111-1111-222222222222',
            'room' => (string) $room->id,
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('La machine a ete ajoutee a la salle salle-cible', $body);

        self::assertDatabaseHas('workstations', [
            'id' => $ws->id,
            'physical_room_id' => $room->id,
        ]);

        // F13 (review 3.3) : MachineBootLog peuplé pour le flow room (success).
        self::assertDatabaseHas('machine_boot_logs', [
            'workstation_id' => $ws->id,
            'action' => 'ipxe_enroll_room',
            'initiated_by' => 'ipxe',
            'success' => true,
        ]);
    }

    #[Test]
    public function it_renders_error_when_workstation_unknown(): void
    {
        $response = $this->post('/ipxe/enrollment/room', [
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('poste non encore enregistre', $body);
        self::assertStringContainsString('/ipxe/admin##params', $body);
    }

    #[Test]
    public function it_renders_error_on_invalid_room_id(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-r-invalid',
            'uuid' => '11111111-2222-1111-1111-333333333333',
            'mac' => 'aa:bb:cc:dd:ee:13',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/enrollment/room', [
            'mac' => 'aa:bb:cc:dd:ee:13',
            'uuid' => '11111111-2222-1111-1111-333333333333',
            'room' => '99999',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString("ERREUR la machine n'a pas ete affectee a la salle", $body);

        // F13 (review 3.3) : aucun MachineBootLog créé sur invalid_room_id.
        self::assertDatabaseMissing('machine_boot_logs', [
            'workstation_id' => $ws->id,
            'action' => 'ipxe_enroll_room',
        ]);
    }
}
