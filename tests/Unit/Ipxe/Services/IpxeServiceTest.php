<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\IpxeService;
use App\Ipxe\Services\LinuxPostInstallTracker;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.1 — AC4.1 / AC4.2 / T4.2.
 *
 * Tests unitaires de l'orchestrateur `IpxeService::handleBoot()`.
 *
 * 3 paths principaux :
 *
 *  - Handshake (mac+uuid vides) → préambule iPXE + log handshake + PAS
 *    d'insert MachineBootLog.
 *  - Locate unknown → menu default + log unknown_workstation + insert
 *    MachineBootLog avec workstation_id=null, machine_name='unknown:$ip'.
 *  - Locate known → menu known + log known_workstation + insert
 *    MachineBootLog avec workstation_id=$ws->id, machine_name=lowercased.
 */
class IpxeServiceTest extends TestCase
{
    private IpxeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        $this->service = $this->app->make(IpxeService::class);
    }

    private function makeRequest(array $params = []): Request
    {
        $request = Request::create('/ipxe/boot', 'POST', $params);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');

        return $request;
    }

    #[Test]
    public function it_returns_handshake_when_mac_and_uuid_missing(): void
    {
        $response = $this->service->handleBoot($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('param mac ${net0/mac}', $body);
        self::assertStringContainsString('chain --replace --autofree boot##params', $body);
    }

    #[Test]
    public function it_does_not_persist_machine_boot_log_on_handshake(): void
    {
        $this->service->handleBoot($this->makeRequest());

        self::assertSame(0, MachineBootLog::query()->count());
    }

    #[Test]
    public function it_returns_unknown_menu_when_workstation_not_found(): void
    {
        $response = $this->service->handleBoot($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'product' => 'Unknown',
        ]));

        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('item --key 0 exit', $body);
        // Le menu inconnu propose l'item admin (key 1 login) depuis la story
        // 4.10 (parité boot.php:82 — l'admin reste accessible aux machines non
        // enrôlées). Il se distingue du menu `known` par l'item de boot disque
        // en `key 0 exit` (le menu known utilise `key 3 default`).
        self::assertStringNotContainsString('item --key 3 default', $body);
    }

    #[Test]
    public function it_persists_machine_boot_log_for_unknown_workstation(): void
    {
        $this->service->handleBoot($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'product' => 'Unknown',
        ]));

        $log = MachineBootLog::query()->first();
        self::assertNotNull($log);
        self::assertNull($log->workstation_id);
        self::assertSame('unknown:192.168.1.42', $log->machine_name);
        self::assertSame('ipxe_boot', $log->action);
        self::assertSame('ipxe', $log->initiated_by);
        self::assertTrue((bool) $log->success);
    }

    #[Test]
    public function it_returns_known_menu_when_workstation_found(): void
    {
        Workstation::create([
            'name' => 'PC-SALLE-101',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $response = $this->service->handleBoot($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'product' => 'OptiPlex 3050',
        ]));

        $body = (string) $response->getContent();
        self::assertStringContainsString('PC-SALLE-101', $body);
        self::assertStringContainsString('item --key 1 login', $body);
        self::assertStringContainsString('item --key 3 default', $body);
    }

    #[Test]
    public function it_returns_install_done_screen_when_workstation_freshly_installed(): void
    {
        // Fix install-debian — marqueur one-shot posé par
        // LinuxPostInstallTracker::record() en fin d'install Linux.
        Workstation::create([
            'name' => 'PC-NEUF-01',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
            'programmed_action' => ['type' => LinuxPostInstallTracker::ACTION_INSTALL_DONE, 'ret' => 0],
        ]);

        $response = $this->service->handleBoot($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'product' => 'OptiPlex 3050',
        ]));

        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('Installation Linux terminee avec succes', $body);
        self::assertStringContainsString('PC-NEUF-01', $body);
        // Fallback boot disk : retour boot manager UEFI (plus de sanboot).
        self::assertStringContainsString('exit 1 || sleep 100', $body);
        // Ce n'est PAS le menu known habituel.
        self::assertStringNotContainsString('item --key 1 login', $body);
    }

    #[Test]
    public function it_clears_the_install_done_marker_after_serving_screen_once(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-NEUF-01',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
            'programmed_action' => ['type' => LinuxPostInstallTracker::ACTION_INSTALL_DONE, 'ret' => 0],
        ]);

        // 1er boot → écran post-install + effacement du marqueur.
        $first = (string) $this->service->handleBoot($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]))->getContent();
        self::assertStringContainsString('Installation Linux terminee avec succes', $first);

        $ws->refresh();
        self::assertNotSame(
            LinuxPostInstallTracker::ACTION_INSTALL_DONE,
            $ws->programmed_action['type'] ?? null,
            'le marqueur one-shot doit être effacé après le 1er affichage',
        );

        // 2e boot → menu known normal (plus l'écran post-install).
        $second = (string) $this->service->handleBoot($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]))->getContent();
        self::assertStringNotContainsString('Installation Linux terminee avec succes', $second);
        self::assertStringContainsString('item --key 3 default', $second);
    }

    #[Test]
    public function it_persists_machine_boot_log_for_known_workstation(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-SALLE-101',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $this->service->handleBoot($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'product' => 'OptiPlex 3050',
        ]));

        $log = MachineBootLog::query()->first();
        self::assertNotNull($log);
        self::assertSame($ws->id, $log->workstation_id);
        // machine_name lowercased iso D5.
        self::assertSame('pc-salle-101', $log->machine_name);
        self::assertSame('ipxe_boot', $log->action);
    }

    #[Test]
    public function it_returns_text_plain_content_type_in_all_paths(): void
    {
        $expected = 'text/plain; charset=utf-8';

        // Handshake
        $r1 = $this->service->handleBoot($this->makeRequest());
        self::assertSame($expected, $r1->headers->get('Content-Type'));

        // Unknown
        $r2 = $this->service->handleBoot($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'product' => 'X',
        ]));
        self::assertSame($expected, $r2->headers->get('Content-Type'));

        // Known
        Workstation::create([
            'name' => 'PC-1',
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'status' => 'active',
        ]);
        $r3 = $this->service->handleBoot($this->makeRequest([
            'mac' => '00:00:00:00:00:00',
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'product' => 'OptiPlex 3050',
        ]));
        self::assertSame($expected, $r3->headers->get('Content-Type'));
    }

    #[Test]
    public function it_returns_no_store_cache_control_in_all_paths(): void
    {
        $r = $this->service->handleBoot($this->makeRequest());
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    #[Test]
    public function it_returns_x_robots_tag_noindex(): void
    {
        $r = $this->service->handleBoot($this->makeRequest());
        self::assertSame('noindex', $r->headers->get('X-Robots-Tag'));
    }

    #[Test]
    public function resolve_programmed_action_returns_null_in_story_3_1(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-1',
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'status' => 'active',
        ]);

        // Story 3.1 — AC4.2 : placeholder qui retourne TOUJOURS null.
        self::assertNull($this->service->resolveProgrammedAction($ws));
    }
}
