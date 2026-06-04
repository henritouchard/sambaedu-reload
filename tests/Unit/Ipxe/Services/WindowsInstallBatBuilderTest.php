<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Enums\WindowsVersion;
use App\Ipxe\Services\WindowsInstallBatBuilder;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.5 — AC3.1 / T2.6.
 *
 * Tests unitaires de {@see WindowsInstallBatBuilder} — line endings `\r\n`
 * stricts + URL native + sanitization shell-arg.
 */
class WindowsInstallBatBuilderTest extends TestCase
{
    private WindowsInstallBatBuilder $service;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();

        config([
            'sambaedu.se4fs_ip' => '192.168.122.50',
            'sambaedu.se4fs_name' => 'se4fs.lan',
            'sambaedu.se4install_name' => 'se4install',
            'sambaedu.se4install_passwd' => 'install-secret',
            'sambaedu.domain' => 'example.org',
        ]);

        // se4install : mock renvoyant le mot de passe config en live (comportement
        // iso pré-TOTP). La rotation TOTP est testée dans ServiceCredentialTotpReconcilerTest.
        $creds = \Mockery::mock(\App\Services\ServiceCredentials::class);
        $creds->shouldReceive('se4installEffectivePassword')
            ->andReturnUsing(fn () => (string) config('sambaedu.se4install_passwd', ''));

        $this->service = new WindowsInstallBatBuilder($creds);
    }

    private function makeWorkstation(string $name = 'PC-101'): Workstation
    {
        return Workstation::create([
            'name' => $name,
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_builds_win10_install_bat_legacy_bios(): void
    {
        $ws = $this->makeWorkstation();
        $bash = $this->service->build(
            $ws,
            WindowsVersion::Win10,
            ['bios' => 'legacy', 'debug' => 0, 'perso' => 0],
        );

        // Header iso-legacy.
        self::assertStringStartsWith("::cmd\r\n", $bash);
        // Commandes WinPE de base.
        self::assertStringContainsString("wpeutil InitializeNetwork\r\n", $bash);
        self::assertStringContainsString("IPCONFIG /RENEW\r\n", $bash);
        self::assertStringContainsString('@PING 192.168.122.50', $bash);
        // Mount SMB share.
        self::assertStringContainsString(
            '@net use z: \\\\se4fs.lan\\install /user:se4install@example.org install-secret',
            $bash,
        );
        // Setup invocation Win10.
        self::assertStringContainsString(
            'z:\\os\\Win10\\sources\\setup.exe /unattend:x:\\windows\\system32\\unattend.xml',
            $bash,
        );
        // Pas de bcdboot en mode legacy.
        self::assertStringNotContainsString('bcdboot', $bash);
    }

    #[Test]
    public function it_builds_win11_install_bat_with_setup_as_last_command(): void
    {
        $ws = $this->makeWorkstation();
        $bash = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'debug' => 0, 'perso' => 0],
        );

        // Divergence legacy assumée (2026-06-04) : setup.exe lancé depuis
        // WinPE reboote lui-même sans rendre la main — toute commande après
        // lui est du code mort. Il DOIT être la dernière commande du script.
        self::assertStringEndsWith(
            "z:\\os\\Win11\\sources\\setup.exe /unattend:x:\\windows\\system32\\unattend.xml\r\n\r\n",
            $bash,
        );
        // Les lignes post-setup legacy (mortes) ne doivent pas réapparaître.
        self::assertStringNotContainsString('bcdboot', $bash);
        self::assertStringNotContainsString('curl', $bash);
        self::assertStringNotContainsString('etape=winpe', $bash);
        self::assertStringNotContainsString('wpeutil reboot', $bash);
        self::assertStringNotContainsString('/noreboot', $bash);
    }

    #[Test]
    public function it_pauses_when_debug_enabled(): void
    {
        $ws = $this->makeWorkstation();
        $bash = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'debug' => 1, 'perso' => 0],
        );

        // Au moins 2 PAUSE (entre sections — parité legacy install.bat.php:21-28).
        $count = substr_count($bash, "PAUSE\r\n");
        self::assertGreaterThanOrEqual(2, $count, 'debug=1 doit injecter PAUSE entre sections.');
    }

    #[Test]
    public function it_omits_pauses_when_debug_disabled(): void
    {
        $ws = $this->makeWorkstation();
        $bash = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'debug' => 0, 'perso' => 0],
        );

        self::assertStringNotContainsString('PAUSE', $bash);
    }

    #[Test]
    public function it_contains_only_crlf_line_endings(): void
    {
        // CRITIQUE WinPE — toute ligne en LF only fait silencieusement
        // s'arrêter l'exécution.
        $ws = $this->makeWorkstation();
        $bash = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'debug' => 0, 'perso' => 0],
        );

        // Beaucoup de \r\n (au moins 15 lignes).
        self::assertGreaterThanOrEqual(15, substr_count($bash, "\r\n"));
        // Pas de double LF (= LF sans CR — invalid pour WinPE).
        self::assertStringNotContainsString("\n\n", $bash);
        // Pas de LF orphelin (= LF sans CR avant).
        // On vérifie qu'aucun "\n" n'est précédé par autre chose que "\r".
        self::assertSame(
            substr_count($bash, "\n"),
            substr_count($bash, "\r\n"),
            'Tous les \n doivent être précédés d\'un \r (CRLF strict).',
        );
    }

    #[Test]
    public function it_contains_no_client_callback(): void
    {
        // 2026-06-04 : le callback winpe post-setup (legacy `Win10/action.php`,
        // natif `/ipxe/windows/action`) a été retiré — code mort, setup.exe
        // ne rend jamais la main. Aucune URL de callback ne doit subsister.
        $ws = $this->makeWorkstation();
        $bash = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'debug' => 0, 'perso' => 0],
        );

        self::assertStringNotContainsString('/ipxe/windows/action', $bash);
        self::assertStringNotContainsString('action.php', $bash);
    }

    #[Test]
    public function it_sanitizes_shell_args_against_injection(): void
    {
        // Defense in depth : si un name AD bypass la sanitization amont,
        // le builder doit remplacer les chars d'injection par `_`.
        config(['sambaedu.se4fs_name' => 'evil;malicious']);

        $ws = $this->makeWorkstation();
        $bash = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'debug' => 0, 'perso' => 0],
        );

        // Le `;` est remplacé par `_`.
        self::assertStringNotContainsString('evil;malicious', $bash);
        self::assertStringContainsString('evil_malicious', $bash);
    }

    #[Test]
    public function it_builds_diskpart_body_with_crlf(): void
    {
        $body = $this->service->buildDiskpart();
        self::assertSame(
            "select disk O\r\nselect partition 1\r\nassign letter=U\r\n",
            $body,
        );
        // Pas de LF orphelin.
        self::assertSame(
            substr_count($body, "\n"),
            substr_count($body, "\r\n"),
        );
    }


    /**
     * Post-review code-review #4 — AC2.3 CRITICAL : pas de secret loggué.
     *
     * On injecte des CANARY secrets dans la config, on capture les logs Monolog
     * via TestHandler, et on assert qu'AUCUN log ne contient une canary.
     * Le install.bat lui-même contient nécessairement les credentials (le
     * `net use` les passe à `\\se4fs\install`) — c'est le LOG qui ne doit pas
     * les contenir, jamais le body bat. Pattern iso LinuxPreseedServiceTest.
     */
    #[Test]
    public function it_does_not_leak_secrets_in_logs(): void
    {
        config([
            'sambaedu.se4install_passwd' => 'CANARY-install-pwd-123',
            'ipxe.log.channel' => 'ipxe',
        ]);

        // Push un TestHandler Monolog sur le logger existant du channel `ipxe`.
        $handler = new \Monolog\Handler\TestHandler();
        $logger = \Illuminate\Support\Facades\Log::channel('ipxe');
        $logger->getLogger()->pushHandler($handler);

        $ws = $this->makeWorkstation();
        $bash = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'debug' => 0, 'perso' => 0],
        );

        // Sanity check : la canary est bien dans le bat (sinon test tautologique).
        self::assertStringContainsString('CANARY-install-pwd-123', $bash);

        // Assertion principale : aucun secret ne fuite dans les logs.
        $records = $handler->getRecords();
        foreach ($records as $record) {
            $payload = json_encode([
                'message' => $record['message'] ?? '',
                'context' => $record['context'] ?? [],
            ]);
            self::assertStringNotContainsString('CANARY-install-pwd-123', (string) $payload);
        }
    }
}
