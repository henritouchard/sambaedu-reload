<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\Workstation;
use App\Models\WorkstationReinstallRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeAuthTestHelper;
use Tests\TestCase;

/**
 * Story 3.11 — Fix review #1 — round-trip HTTP `POST /ipxe/action/{action}`.
 *
 * Le chain automatique du menu `known` (bloc `:action`) chaine vers
 * `/ipxe/action/{action}` SANS credentials AD. Ce test exerce le VRAI flux HTTP
 * (auth 4.10 réelle, PAS de bypass) pour vérifier que :
 *
 *  - une action `install_*` est pré-autorisée SANS auth SI le poste porte une
 *    requête de réinstall ACTIVE dont `target_action` === l'action demandée
 *    → rend le script d'install (jamais `auth_failed`) ;
 *  - sinon (action différente, pas de requête, poste protégé), la garde AD
 *    s'applique → `auth_failed` (« Acces refuse »).
 */
class IpxeReinstallActionAuthTest extends TestCase
{
    use IpxeAuthTestHelper;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Auth AD réelle (PAS de bypass). Un bind qui échoue suffit : sans
        // username/password, IpxeAuthService retourne MissingCredentials avant
        // même d'appeler le bind. On stubbe pour éviter toute résolution LDAP.
        $this->stubAdAuth(false);
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
            'ipxe.admin.enabled' => true,
        ]);
    }

    private function seedWorkstation(string $mac, string $uuid, string $status = 'active'): Workstation
    {
        return Workstation::create([
            'name' => 'PC-REINST-' . substr(bin2hex(random_bytes(3)), 0, 6),
            'uuid' => $uuid,
            'mac' => $mac,
            'status' => $status,
        ]);
    }

    #[Test]
    public function it_serves_install_without_credentials_when_active_request_matches(): void
    {
        $mac = 'aa:bb:cc:11:22:01';
        $uuid = '11111111-1111-1111-1111-111111111101';
        $ws = $this->seedWorkstation($mac, $uuid);

        WorkstationReinstallRequest::factory()->armed()->create([
            'workstation_id' => $ws->id,
            'target_action' => 'install_deb_gnome',
        ]);

        // POST sans username/password (chain automatique du menu known).
        $response = $this->post('/ipxe/action/install_deb_gnome', [
            'mac' => $mac,
            'uuid' => $uuid,
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        self::assertStringNotContainsString(
            'Acces refuse',
            $body,
            'La requête active correspondante doit pré-autoriser l\'action sans auth AD.',
        );
    }

    #[Test]
    public function it_refuses_without_credentials_when_action_differs_from_armed_target(): void
    {
        $mac = 'aa:bb:cc:11:22:02';
        $uuid = '11111111-1111-1111-1111-111111111102';
        $ws = $this->seedWorkstation($mac, $uuid);

        // Armé sur install_deb_gnome, mais on demande install_win11.
        WorkstationReinstallRequest::factory()->armed()->create([
            'workstation_id' => $ws->id,
            'target_action' => 'install_deb_gnome',
        ]);

        $response = $this->post('/ipxe/action/install_win11', [
            'mac' => $mac,
            'uuid' => $uuid,
        ]);

        $response->assertStatus(200);
        self::assertStringContainsString(
            'Acces refuse',
            (string) $response->getContent(),
            'Une action différente de la cible armée ne doit PAS être pré-autorisée.',
        );
    }

    #[Test]
    public function it_refuses_without_credentials_when_no_active_request(): void
    {
        $mac = 'aa:bb:cc:11:22:03';
        $uuid = '11111111-1111-1111-1111-111111111103';
        $this->seedWorkstation($mac, $uuid);

        $response = $this->post('/ipxe/action/install_deb_gnome', [
            'mac' => $mac,
            'uuid' => $uuid,
        ]);

        $response->assertStatus(200);
        self::assertStringContainsString(
            'Acces refuse',
            (string) $response->getContent(),
            'Sans requête active, la garde AD 4.10 doit s\'appliquer.',
        );
    }

    #[Test]
    public function it_refuses_bypass_for_protected_workstation_even_with_matching_request(): void
    {
        $mac = 'aa:bb:cc:11:22:04';
        $uuid = '11111111-1111-1111-1111-111111111104';
        $ws = $this->seedWorkstation($mac, $uuid, 'protected');

        WorkstationReinstallRequest::factory()->armed()->create([
            'workstation_id' => $ws->id,
            'target_action' => 'install_deb_gnome',
        ]);

        $response = $this->post('/ipxe/action/install_deb_gnome', [
            'mac' => $mac,
            'uuid' => $uuid,
        ]);

        $response->assertStatus(200);
        self::assertStringContainsString(
            'Acces refuse',
            (string) $response->getContent(),
            'Un poste protégé (D10) ne doit jamais être pré-autorisé.',
        );
    }
}
