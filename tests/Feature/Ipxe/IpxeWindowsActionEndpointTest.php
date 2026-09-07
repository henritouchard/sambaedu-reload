<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * T6.3.
 *
 * Tests feature de la route native `POST /ipxe/windows/action` (hook
 * post-install Windows multi-étapes — scope = winpe + oobe seuls).
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
        // Status non touché : la colonne est un domaine fermé varchar(20).
        self::assertSame('active', $ws->status);

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
        // Status non touché : la colonne est un domaine fermé varchar(20).
        self::assertSame('active', $ws->status);
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

        // Aucun MachineBootLog inséré : un poste inconnu est ignoré en silence.
        self::assertSame(0, MachineBootLog::count());
    }

    #[Test]
    public function it_rejects_unsupported_step(): void
    {
        // 'sysprep' est un step VALIDE : ce test cible donc un step qui
        // restera toujours inconnu ('unknown_v3') pour préserver son intent de
        // non-régression : un step hors des 8 cases enum est rejeté par le
        // FormRequest (Rule::in) → 422, sans toucher la Workstation.
        $ws = $this->seedWorkstation();

        // postJson → 422 JSON (cohérent avec le test it_rejects_etape_arbitrary_with_422 ;
        // un POST form classique ferait un redirect 302 web).
        $response = $this->postJson('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-win',
            'etape' => 'unknown_v3',
            'ret' => '0',
        ]);

        $response->assertStatus(422);
        // Workstation inchangée (le tracker n'est pas appelé).
        $ws->refresh();
        self::assertSame('active', $ws->status);
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
        // Aucune mutation (ret != 0 → pas de MachineBootLog d'install).
        self::assertSame('active', $ws->status);
        self::assertSame(0, MachineBootLog::where('action', 'ipxe_win_install')->count());
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
