<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;
use Tests\Support\IpxeAuthTestHelper;

/**
 * Story 3.2 — AC3.3 / AC8.2 / T6.5.
 *
 * Tests feature de la route native `GET|POST /ipxe/action/{action}`.
 */
class IpxeActionEndpointTest extends TestCase
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
        $response = $this->get('/ipxe/action/rescuecd');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('/ipxe/action/rescuecd##params', $body);
        // Fix review #6 — assertions headers sécurité complètes au niveau Feature.
        // Symfony normalise `no-store` en `no-store, private` au send (cf.
        // ResponseHeaderBag::computeCacheControlValue) ; on assert l'inclusion.
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_dispatches_rescuecd_action(): void
    {
        $response = $this->post('/ipxe/action/rescuecd', [
            'mac' => 'aa:bb:cc:dd:ee:e1',
            'uuid' => 'eeeeeeee-1111-1111-1111-111111111111',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('sysresccd/boot/x86_64/vmlinuz', $body);
        self::assertStringEndsWith("boot\n", $body);
    }

    #[Test]
    public function it_dispatches_winpe_action(): void
    {
        $response = $this->post('/ipxe/action/winpe', [
            'mac' => 'aa:bb:cc:dd:ee:e2',
            'uuid' => 'eeeeeeee-2222-2222-2222-222222222222',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        // URL absolue (fix 2026-06-04) — un `kernel Win10/wimboot` relatif se
        // résolvait contre `/ipxe/action/` → 410 → abort iPXE.
        self::assertMatchesRegularExpression('#^kernel https?://[^/]+/ipxe/Win10/wimboot$#m', $body);
    }

    #[Test]
    public function it_dispatches_factory_reset_action(): void
    {
        $response = $this->post('/ipxe/action/factory_reset', [
            'mac' => 'aa:bb:cc:dd:ee:e3',
            'uuid' => 'eeeeeeee-3333-3333-3333-333333333333',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('clonezilla/vmlinuz', $body);
        self::assertStringContainsString('restoreparts savesda1 sda1', $body);
    }

    #[Test]
    public function it_returns_404_for_unknown_action(): void
    {
        $response = $this->post('/ipxe/action/install_macos', [
            'mac' => 'aa:bb:cc:dd:ee:e4',
            'uuid' => 'eeeeeeee-4444-4444-4444-444444444444',
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_for_action_with_invalid_format(): void
    {
        // La regex de route `->where('action', '[a-z0-9_]+')` rejette les
        // caractères majuscules / espaces. Le catchall intercepte ensuite
        // la requête et retourne 410 Gone (blocked_legacy_routes `^ipxe/action/`).
        $response = $this->post('/ipxe/action/RESCUECD', [
            'mac' => 'aa:bb:cc:dd:ee:e5',
            'uuid' => 'eeeeeeee-5555-5555-5555-555555555555',
        ]);

        $response->assertStatus(410);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_initiated_by_ipxe_action_value(): void
    {
        $uniqueName = 'pc-act-feat-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => 'eeeeeeee-6666-6666-6666-666666666666',
            'mac' => 'aa:bb:cc:dd:ee:e6',
            'status' => 'active',
        ]);

        $this->post('/ipxe/action/factory_reset', [
            'mac' => 'aa:bb:cc:dd:ee:e6',
            'uuid' => 'eeeeeeee-6666-6666-6666-666666666666',
        ]);

        $row = MachineBootLog::query()
            ->where('machine_name', $uniqueName)
            ->where('action', 'ipxe_action')
            ->first();

        self::assertNotNull($row);
        self::assertSame('ipxe:factory_reset', $row->initiated_by);
    }

    /* ------------------------------------------------------------------
     * Story 3.7 — AC5.2-5.7 / T4.9 — 6 nouvelles actions.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_renders_clonezilla_live_action(): void
    {
        $response = $this->post('/ipxe/action/clonezilla_live', [
            'mac' => 'aa:bb:cc:dd:ee:a1',
            'uuid' => 'eeeeeeee-aaaa-1111-1111-111111111111',
        ]);

        $response->assertStatus(200);
        // Post-review #9 — content-type strict text/plain (contrat firmware iPXE).
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        self::assertStringContainsString('clonezilla/vmlinuz', $body);
        self::assertStringContainsString('clonezilla/initrd.img', $body);
        self::assertStringContainsString('boot', $body);
    }

    #[Test]
    public function it_renders_clonezilla_save_sda1_sda2_action(): void
    {
        $response = $this->post('/ipxe/action/clonezilla_save_sda1_sda2', [
            'mac' => 'aa:bb:cc:dd:ee:a2',
            'uuid' => 'eeeeeeee-aaaa-2222-2222-222222222222',
        ]);

        $response->assertStatus(200);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        self::assertStringContainsString('saveparts savesda1 sda1', $body);
        self::assertStringContainsString('boot', $body);
    }

    #[Test]
    public function it_renders_clonezilla_restore_sda2_sda1_action(): void
    {
        $response = $this->post('/ipxe/action/clonezilla_restore_sda2_sda1', [
            'mac' => 'aa:bb:cc:dd:ee:a3',
            'uuid' => 'eeeeeeee-aaaa-3333-3333-333333333333',
        ]);

        $response->assertStatus(200);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        self::assertStringContainsString('restoreparts savesda1 sda1', $body);
        self::assertStringContainsString('boot', $body);
    }

    #[Test]
    public function it_renders_gparted_action(): void
    {
        $response = $this->post('/ipxe/action/gparted', [
            'mac' => 'aa:bb:cc:dd:ee:a4',
            'uuid' => 'eeeeeeee-aaaa-4444-4444-444444444444',
        ]);

        $response->assertStatus(200);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        self::assertStringContainsString('gparted', $body);
    }

    #[Test]
    public function it_renders_hdt_action(): void
    {
        $response = $this->post('/ipxe/action/hdt', [
            'mac' => 'aa:bb:cc:dd:ee:a5',
            'uuid' => 'eeeeeeee-aaaa-5555-5555-555555555555',
        ]);

        $response->assertStatus(200);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        self::assertStringContainsString('hdt', $body);
    }

    #[Test]
    public function it_renders_memtest86plus_action(): void
    {
        $response = $this->post('/ipxe/action/memtest86plus', [
            'mac' => 'aa:bb:cc:dd:ee:a6',
            'uuid' => 'eeeeeeee-aaaa-6666-6666-666666666666',
        ]);

        $response->assertStatus(200);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('#!ipxe', $body);
        self::assertStringContainsString('memtest86plus', $body);
    }

    #[Test]
    public function it_persists_distinct_boot_log_action_for_3_7_actions(): void
    {
        // D11 / AC8.1 — action clonezilla_live insere 'ipxe_clonezilla' dans boot_log.
        $uniqueName = 'pc-clz-log-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => 'eeeeeeee-cccc-7777-7777-777777777777',
            'mac' => 'aa:bb:cc:ee:07:07',
            'status' => 'active',
        ]);

        $this->post('/ipxe/action/clonezilla_live', [
            'mac' => 'aa:bb:cc:ee:07:07',
            'uuid' => 'eeeeeeee-cccc-7777-7777-777777777777',
        ]);

        $row = MachineBootLog::query()
            ->where('machine_name', $uniqueName)
            ->where('action', 'ipxe_clonezilla')
            ->first();

        self::assertNotNull($row, 'MachineBootLog doit contenir une ligne avec action=ipxe_clonezilla');
        self::assertSame('ipxe:clonezilla_live', $row->initiated_by);
    }

    /**
     * Post-review #14 — différentiel poste connu vs inconnu.
     *
     * Les 6 tests `it_renders_{action}` ci-dessus POSTent avec un MAC arbitraire
     * (poste inconnu = `WorkstationLocator::locate()` retourne null). Ce test
     * exerce explicitement le chemin « poste connu » : on crée le Workstation,
     * POST l'action, et on vérifie que le boot_log est lié au workstation
     * (`machine_name = workstation.name`).
     */
    #[Test]
    public function it_links_boot_log_to_workstation_when_known(): void
    {
        $uniqueName = 'pc-known-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => 'eeeeeeee-1111-1111-1111-111111111111',
            'mac' => 'aa:bb:cc:dd:ee:a1',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/action/clonezilla_live', [
            'mac' => 'aa:bb:cc:dd:ee:a1',
            'uuid' => 'eeeeeeee-1111-1111-1111-111111111111',
        ]);

        $response->assertStatus(200);

        $row = MachineBootLog::query()
            ->where('machine_name', $uniqueName)
            ->first();

        self::assertNotNull($row, 'Workstation connu doit produire boot_log lié au nom.');
        self::assertSame('ipxe_clonezilla', $row->action);
    }

    /**
     * Post-review #1 + #10 — non-régression D2 / FactoryReset label boot_log.
     *
     * `FactoryReset` (3.2) et `ClonezillaRestoreSda2Sda1` (3.7) partagent la
     * MÊME cmdline iPXE — garanti par
     * `it_ensures_factory_reset_and_clonezilla_restore_have_same_kernel_cmdline`.
     * MAIS leurs labels divergent volontairement (cf. PHPDoc bootLogAction()) :
     *
     *  - FactoryReset                 → `'ipxe_action'`     (compat 3.2)
     *  - ClonezillaRestoreSda2Sda1   → `'ipxe_clonezilla'` (audit fin 3.7)
     *
     * Ce test gèle le comportement post-3.7 : un POST `/ipxe/action/factory_reset`
     * doit toujours produire `MachineBootLog.action='ipxe_action'`, jamais
     * `'ipxe_clonezilla'`.
     */
    #[Test]
    public function it_persists_ipxe_action_label_for_factory_reset_post_3_7(): void
    {
        $uniqueName = 'pc-fr-37-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => 'eeeeeeee-fa37-fa37-fa37-fa37fa37fa37',
            'mac' => 'aa:bb:cc:fa:37:fa',
            'status' => 'active',
        ]);

        $this->post('/ipxe/action/factory_reset', [
            'mac' => 'aa:bb:cc:fa:37:fa',
            'uuid' => 'eeeeeeee-fa37-fa37-fa37-fa37fa37fa37',
        ]);

        $row = MachineBootLog::query()
            ->where('machine_name', $uniqueName)
            ->latest('id')
            ->first();

        self::assertNotNull($row, 'MachineBootLog doit contenir une ligne pour factory_reset');
        self::assertSame(
            'ipxe_action',
            $row->action,
            'D2 — FactoryReset doit conserver label `ipxe_action` (compat 3.2), pas `ipxe_clonezilla`.',
        );
        self::assertSame('ipxe:factory_reset', $row->initiated_by);
    }
}
