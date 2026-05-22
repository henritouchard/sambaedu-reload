<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.7 — AC3.1 / AC4.1 / AC4.5 / AC5.1 / T3.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/clonezilla-menu`.
 */
class IpxeClonezillaMenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    #[Test]
    public function it_returns_handshake_when_no_params(): void
    {
        $response = $this->get('/ipxe/clonezilla-menu');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        self::assertStringContainsString('chain --replace --autofree clonezilla-menu##params', $body);
    }

    #[Test]
    public function it_returns_clonezilla_menu_with_all_items_via_post(): void
    {
        Workstation::create([
            'name' => 'PC-CLZ-FEAT',
            'uuid' => '33333333-3333-3333-3333-aabbccddee01',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/clonezilla-menu', [
            'mac' => 'aa:bb:cc:dd:ee:01',
            'uuid' => '33333333-3333-3333-3333-aabbccddee01',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        // Menu items AC5.1
        self::assertStringContainsString('item --key l clonezilla_live', $body);
        self::assertStringContainsString('item --key s clonezilla_save', $body);
        self::assertStringContainsString('item --key r clonezilla_restore', $body);
        self::assertStringContainsString('item --key b retour', $body);
        self::assertStringContainsString('item --key x exit', $body);
    }

    #[Test]
    public function it_returns_clonezilla_menu_via_get_with_params(): void
    {
        Workstation::create([
            'name' => 'PC-CLZ-GET',
            'uuid' => '33333333-3333-3333-3333-aabbccddee02',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'status' => 'active',
        ]);

        $response = $this->get('/ipxe/clonezilla-menu?mac=aa:bb:cc:dd:ee:02&uuid=33333333-3333-3333-3333-aabbccddee02');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        self::assertStringContainsString(':clonezilla_live', $body);
    }

    #[Test]
    public function it_returns_menu_for_unknown_workstation(): void
    {
        // AC4.5 — poste inconnu : menu toujours rendu (parité legacy clonezilla_menu.php).
        $response = $this->post('/ipxe/clonezilla-menu', [
            'mac' => 'aa:bb:cc:ff:ff:ff',
            'uuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        // Le menu clonezilla est affiché même pour un poste inconnu (parité legacy).
        self::assertStringContainsString(':menu', $body);
    }

    #[Test]
    public function it_chains_retour_to_maintenance(): void
    {
        $response = $this->post('/ipxe/clonezilla-menu', [
            'mac' => 'aa:bb:cc:dd:ee:03',
            'uuid' => '33333333-3333-3333-3333-aabbccddee03',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        // Le retour doit pointer vers /ipxe/maintenance.
        self::assertMatchesRegularExpression('#chain --replace --autofree https?://[^/]+/ipxe/maintenance\#\#params#', $body);
    }

    #[Test]
    public function it_chains_actions_to_correct_ipxe_action_endpoint(): void
    {
        $response = $this->post('/ipxe/clonezilla-menu', [
            'mac' => 'aa:bb:cc:dd:ee:04',
            'uuid' => '33333333-3333-3333-3333-aabbccddee04',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertMatchesRegularExpression('#chain --replace --autofree https?://[^/]+/ipxe/action/clonezilla_live\#\#params#', $body);
        self::assertMatchesRegularExpression('#chain --replace --autofree https?://[^/]+/ipxe/action/clonezilla_save_sda1_sda2\#\#params#', $body);
        self::assertMatchesRegularExpression('#chain --replace --autofree https?://[^/]+/ipxe/action/clonezilla_restore_sda2_sda1\#\#params#', $body);
    }
}
