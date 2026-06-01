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
 * Story 3.3 — AC9.2 / T6.6.
 *
 * Tests Feature des routes natives `GET|POST /ipxe/enrollment/parc-add` et
 * `GET|POST /ipxe/enrollment/parc-remove`.
 */
class IpxeEnrollmentParcEndpointTest extends TestCase
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
    public function it_returns_parc_add_handshake_when_no_params(): void
    {
        $response = $this->get('/ipxe/enrollment/parc-add');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('ipxe/enrollment/parc-add##params', $body);
    }

    #[Test]
    public function it_attaches_workstation_to_logical_parc(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-p-1',
            'uuid' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:a1',
            'status' => 'active',
        ]);
        $parc = WorkstationGroup::create([
            'name' => 'parc-cible',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $response = $this->post('/ipxe/enrollment/parc-add', [
            'mac' => 'aa:bb:cc:dd:ee:a1',
            'uuid' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            'parc' => (string) $parc->id,
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('La machine a ete ajoutee au parc parc-cible', $body);

        self::assertDatabaseHas('workstation_group_workstation', [
            'workstation_id' => $ws->id,
            'workstation_group_id' => $parc->id,
        ]);

        // F13 (review 3.3) : MachineBootLog peuplé pour le flow parc-add (success).
        self::assertDatabaseHas('machine_boot_logs', [
            'workstation_id' => $ws->id,
            'action' => 'ipxe_parc_add',
            'initiated_by' => 'ipxe',
            'success' => true,
        ]);
    }

    #[Test]
    public function it_detaches_workstation_from_logical_parc(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-p-2',
            'uuid' => 'bbbbbbbb-1111-bbbb-1111-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:a2',
            'status' => 'active',
        ]);
        $parc = WorkstationGroup::create([
            'name' => 'parc-detacher',
            'is_physical' => false,
            'is_active' => true,
        ]);
        $ws->groups()->attach($parc->id);

        $response = $this->post('/ipxe/enrollment/parc-remove', [
            'mac' => 'aa:bb:cc:dd:ee:a2',
            'uuid' => 'bbbbbbbb-1111-bbbb-1111-bbbbbbbbbbbb',
            'parc' => (string) $parc->id,
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('La machine a ete enlevee du parc parc-detacher', $body);

        self::assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $ws->id,
            'workstation_group_id' => $parc->id,
        ]);

        // F13 (review 3.3) : MachineBootLog peuplé pour le flow parc-remove (success).
        self::assertDatabaseHas('machine_boot_logs', [
            'workstation_id' => $ws->id,
            'action' => 'ipxe_parc_remove',
            'initiated_by' => 'ipxe',
            'success' => true,
        ]);
    }

    #[Test]
    public function it_returns_error_on_invalid_parc_id(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-p-invalid',
            'uuid' => 'cccccccc-1111-cccc-1111-cccccccccccc',
            'mac' => 'aa:bb:cc:dd:ee:a3',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/enrollment/parc-add', [
            'mac' => 'aa:bb:cc:dd:ee:a3',
            'uuid' => 'cccccccc-1111-cccc-1111-cccccccccccc',
            'parc' => '99999',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString("ERREUR la machine n'a pas ete ajoutee", $body);

        // F13 (review 3.3) : aucun MachineBootLog créé sur invalid_group_id.
        self::assertDatabaseMissing('machine_boot_logs', [
            'workstation_id' => $ws->id,
            'action' => 'ipxe_parc_add',
        ]);
    }

    #[Test]
    public function it_renders_error_when_workstation_unknown_on_parc_add(): void
    {
        $response = $this->post('/ipxe/enrollment/parc-add', [
            'mac' => 'aa:bb:cc:dd:ee:ee',
            'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('poste non encore enregistre', $body);
        self::assertStringContainsString('/ipxe/admin##params', $body);
    }
}
