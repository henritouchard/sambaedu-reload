<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.5 — AC5.6 / T6.3.
 *
 * Tests feature de la route native `POST /ipxe/windows/action` (hook
 * post-install Windows multi-étapes — scope 3.5 = winpe + oobe seuls).
 */
class IpxeWindowsActionEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    private function seedWorkstation(string $uuid = '12345678-1234-1234-1234-aaaaaaaaaaaa', string $mac = 'aa:bb:cc:dd:ee:01', string $name = 'pc-win'): Workstation
    {
        return Workstation::create([
            'name' => $name,
            'uuid' => $uuid,
            'mac' => $mac,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_records_winpe_start_when_etape_winpe_ret_zero(): void
    {
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-win',
            'etape' => 'winpe',
            'ret' => '0',
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());

        $ws->refresh();
        self::assertSame('installation WinPE', $ws->status);

        $log = MachineBootLog::where('action', 'ipxe_win_install')->first();
        self::assertNotNull($log);
    }

    #[Test]
    public function it_records_oobe_complete_when_etape_oobe_ret_zero(): void
    {
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-win',
            'etape' => 'oobe',
            'ret' => '0',
        ]);

        $response->assertStatus(200);

        $ws->refresh();
        self::assertSame('windows', $ws->os);
        self::assertSame('installation Windows terminee', $ws->status);
        self::assertNotNull($ws->last_report_at);

        $log = MachineBootLog::where('action', 'ipxe_win_report')->first();
        self::assertNotNull($log);
    }

    #[Test]
    public function it_returns_empty_body_for_unknown_workstation(): void
    {
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'name' => 'pc-unknown',
            'etape' => 'winpe',
            'ret' => '0',
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());

        // Aucun MachineBootLog inséré (D4 silent unknown).
        self::assertSame(0, MachineBootLog::count());
    }

    #[Test]
    public function it_rejects_unsupported_step(): void
    {
        // Review #8 — MAJ post-3.8 : 'sysprep' est désormais un step VALIDE
        // (étendu en 3.8, plus "déférée 3.7"). Ce test cible donc un step qui
        // restera toujours inconnu ('unknown_v3') pour préserver son intent de
        // non-régression : un step hors des 8 cases enum est rejeté par le
        // FormRequest (Rule::in) → 422, sans toucher la Workstation.
        $ws = $this->seedWorkstation();

        // postJson → 422 JSON (cohérent avec le test 3.8 it_rejects_etape_arbitrary_with_422 ;
        // un POST form classique ferait un redirect 302 web).
        $response = $this->postJson('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-win',
            'etape' => 'unknown_v3',
            'ret' => '0',
        ]);

        $response->assertStatus(422);
        // Workstation status unchanged (le tracker n'est pas appelé).
        $ws->refresh();
        self::assertNotSame('installation WinPE', $ws->status);
        self::assertNotSame('windows', $ws->os);
    }

    #[Test]
    public function it_does_not_update_workstation_when_ret_non_zero(): void
    {
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-win',
            'etape' => 'winpe',
            'ret' => '1',  // échec
        ]);

        $response->assertStatus(200);
        $ws->refresh();
        // Pas de mise à jour status WinPE.
        self::assertNotSame('installation WinPE', $ws->status);
    }

    #[Test]
    public function it_serves_text_plain_with_secure_headers(): void
    {
        $ws = $this->seedWorkstation();
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-win',
            'etape' => 'winpe',
            'ret' => '0',
        ]);

        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }
}
