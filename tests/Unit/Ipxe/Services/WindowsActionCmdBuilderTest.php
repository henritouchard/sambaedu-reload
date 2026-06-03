<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Exceptions\BatPlaceholderInjectionException;
use App\Ipxe\Services\WindowsActionCmdBuilder;
use App\Models\Workstation;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.8 — AC3.1-3.6 / T3.9.
 *
 * Tests unitaires de {@see WindowsActionCmdBuilder} :
 *  - Structure : 6 méthodes publiques `build*` qui retournent un string.
 *  - CRLF strict : `\r\n` only (D6 / AC3.3).
 *  - Sanitization : placeholders dynamiques sanitisés (AC3.4).
 *  - Content : labels critiques présents (`:gpo`, `:autologon`, curl + uuid).
 */
class WindowsActionCmdBuilderTest extends TestCase
{
    private WindowsActionCmdBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();

        // Configs SE5 utilisées par le builder.
        config([
            'sambaedu.se4install_name' => 'se4install',
            'sambaedu.se4install_passwd' => 'TestPwd1234',
            'sambaedu.windows.adminse_name' => 'admin',
            'sambaedu.windows.adminse_passwd' => 'AdminPwd1234',
            'sambaedu.domain' => 'localdev.fr',
            'sambaedu.se4fs_name' => 'se4fs',
        ]);

        $creds = \Mockery::mock(\App\Services\ServiceCredentials::class);
        $creds->shouldReceive('se4installEffectivePassword')
            ->andReturnUsing(fn () => (string) config('sambaedu.se4install_passwd', ''));

        $this->builder = new WindowsActionCmdBuilder($this->app->make(ViewFactory::class), $creds);
    }

    private function makeWorkstation(): Workstation
    {
        return Workstation::create([
            'name' => 'pc-techno-25',
            'uuid' => '12345678-1234-1234-1234-123456789012',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);
    }

    /**
     * Builders + leur identifiant pour data provider.
     *
     * @return array<string, array{string}>
     */
    public static function buildersProvider(): array
    {
        return [
            'sysprep' => ['sysprep'],
            'nosysprep' => ['nosysprep'],
            'join' => ['join'],
            'renomme' => ['renomme'],
            'post' => ['post'],
            'wpkg' => ['wpkg'],
        ];
    }

    private function invokeBuilder(string $step, Workstation $ws): string
    {
        return match ($step) {
            'sysprep' => $this->builder->buildSysprep($ws),
            'nosysprep' => $this->builder->buildNosysprep($ws),
            'join' => $this->builder->buildJoin($ws, 'pc-techno-25', 'OU=techno,OU=computers,DC=localdev,DC=fr'),
            'renomme' => $this->builder->buildRenomme($ws, 'pc-renamed-01'),
            'post' => $this->builder->buildPost($ws),
            'wpkg' => $this->builder->buildWpkg($ws),
        };
    }

    /* --------------------------- structure ---------------------------- */

    #[Test]
    #[DataProvider('buildersProvider')]
    public function it_returns_non_empty_body_for_each_builder(string $step): void
    {
        $ws = $this->makeWorkstation();
        $body = $this->invokeBuilder($step, $ws);

        self::assertIsString($body);
        self::assertNotSame('', $body, "Builder {$step} doit retourner un body non vide");
        self::assertGreaterThan(200, strlen($body), "Builder {$step} body doit etre >200 bytes");
    }

    #[Test]
    #[DataProvider('buildersProvider')]
    public function it_emits_strict_crlf_line_endings(string $step): void
    {
        $ws = $this->makeWorkstation();
        $body = $this->invokeBuilder($step, $ws);

        // Le body ne doit contenir AUCUN LF orphelin (LF sans CRLF avant).
        // Stratégie : strip tous les CRLF puis vérifier l'absence de LF.
        $withoutCrlf = str_replace("\r\n", '', $body);
        self::assertStringNotContainsString("\n", $withoutCrlf, "Builder {$step}: LF orphelin détecté");
        self::assertStringNotContainsString("\r", $withoutCrlf, "Builder {$step}: CR orphelin détecté");
    }

    #[Test]
    #[DataProvider('buildersProvider')]
    public function it_contains_only_ascii_chars(string $step): void
    {
        $ws = $this->makeWorkstation();
        $body = $this->invokeBuilder($step, $ws);

        // Strip CRLF d'abord (CRLF est ASCII de toute façon, mais simplifie).
        $ascii = preg_match('/^[\x09\x0A\x0D\x20-\x7E]+$/', $body);
        self::assertSame(1, $ascii, "Builder {$step}: chars non-ASCII détectés");
    }

    /* --------------------------- content checks ---------------------------- */

    #[Test]
    public function it_renders_sysprep_with_autologon_block_and_curl_callback(): void
    {
        $ws = $this->makeWorkstation();
        $body = $this->builder->buildSysprep($ws);

        // Structure clé : labels + curl callback ret=1 (parité legacy 113).
        self::assertStringContainsString(":gpo\r\n", $body);
        self::assertStringContainsString(":autologon\r\n", $body);
        self::assertStringContainsString(':sysprep', $body);
        self::assertStringContainsString('sysprep.exe /generalize /oobe', $body);
        self::assertStringContainsString('-F "etape=sysprep" -F "ret=1"', $body);
        // Q-2 refacto clarté — le nosysprep fallback dans sysprep utilise
        // `etape=nosysprep` distinct (PAS `etape=sysprep&ret=2` legacy).
        self::assertStringContainsString('-F "etape=nosysprep"', $body);
        self::assertStringNotContainsString('-F "etape=sysprep" -F "ret=2"', $body);
    }

    #[Test]
    public function it_renders_nosysprep_with_autologon_adminse_and_curl_callback(): void
    {
        $ws = $this->makeWorkstation();
        $body = $this->builder->buildNosysprep($ws);

        self::assertStringContainsString(":gpo\r\n", $body);
        self::assertStringContainsString(":autologon\r\n", $body);
        // Q-2 refacto clarté — utilise `etape=nosysprep` (PAS `etape=sysprep&ret=2`).
        self::assertStringContainsString('-F "etape=nosysprep"', $body);
        self::assertStringNotContainsString('-F "etape=sysprep" -F "ret=2"', $body);
        // Adminse autologon (workgroup clone post-Remove-Computer).
        self::assertStringContainsString('Remove-Computer', $body);
    }

    #[Test]
    public function it_renders_join_with_domain_oupath(): void
    {
        $ws = $this->makeWorkstation();
        $body = $this->builder->buildJoin($ws, 'pc-techno-25', 'OU=techno,DC=localdev,DC=fr');

        self::assertStringContainsString(':join', $body);
        self::assertStringContainsString(':domaine', $body);
        self::assertStringContainsString(':autologon', $body);
        self::assertStringContainsString('Add-Computer', $body);
        self::assertStringContainsString("-OUPath 'OU=techno,DC=localdev,DC=fr'", $body);
        self::assertStringContainsString('-F "etape=join" -F "ret=0"', $body);
        self::assertStringContainsString('-F "etape=join" -F "ret=1"', $body);
        self::assertStringContainsString('-F "etape=join" -F "ret=2"', $body);
    }

    #[Test]
    public function it_renders_renomme_with_powershell_rename_computer(): void
    {
        $ws = $this->makeWorkstation();
        $body = $this->builder->buildRenomme($ws, 'pc-renamed-01');

        self::assertStringContainsString(':gpo', $body);
        self::assertStringContainsString(':autologon', $body);
        self::assertStringContainsString('Rename-Computer -NewName pc-renamed-01', $body);
        self::assertStringContainsString('-F "etape=renomme" -F "ret=0"', $body);
        self::assertStringContainsString('-F "etape=renomme" -F "ret=1"', $body);
    }

    #[Test]
    public function it_renders_post_with_robocopy_and_two_curl_calls(): void
    {
        $ws = $this->makeWorkstation();
        $body = $this->builder->buildPost($ws);

        self::assertStringContainsString(':gpo', $body);
        self::assertStringContainsString(':autologon', $body);
        self::assertStringContainsString('robocopy', $body);
        self::assertStringContainsString('SambaEdu', $body);
        self::assertStringContainsString('-F "etape=post" -F "ret=0"', $body);
        // Second tour : curl avec -o pour télécharger le nouveau script
        // (parité legacy 225).
        self::assertStringContainsString('-o "%windir%\action.cmd"', $body);
    }

    #[Test]
    public function it_renders_wpkg_with_drivers_and_winget_invocation(): void
    {
        $ws = $this->makeWorkstation();
        $body = $this->builder->buildWpkg($ws);

        self::assertStringContainsString(':gpo', $body);
        self::assertStringContainsString(':autologon', $body);
        self::assertStringContainsString('driversAuto.ps1', $body);
        self::assertStringContainsString('winget-install.ps1', $body);
        self::assertStringContainsString('Nettoyage WPKG.cmd', $body);
        self::assertStringContainsString('-F "etape=wpkg" -F "ret=1"', $body);
        // mklink vers SMB share legacy (parité ligne 301).
        self::assertStringContainsString('\\\\se4fs\\install', $body);
    }

    /* --------------------------- sanitization ---------------------------- */

    #[Test]
    public function it_rejects_workstation_name_with_cmd_injection(): void
    {
        $ws = Workstation::create([
            'name' => 'pc;calc.exe',  // Injection
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:99',
            'status' => 'active',
        ]);

        $this->expectException(BatPlaceholderInjectionException::class);
        $this->builder->buildJoin($ws);
    }

    #[Test]
    public function it_rejects_role_with_cmd_injection(): void
    {
        $ws = $this->makeWorkstation();

        $this->expectException(BatPlaceholderInjectionException::class);
        $this->builder->buildRenomme($ws, 'pc-evil&calc.exe');
    }

    #[Test]
    public function it_accepts_valid_ldap_ou_for_join(): void
    {
        $ws = $this->makeWorkstation();
        // OU LDAP valide avec virgules + égal + espaces (parité fixture).
        $body = $this->builder->buildJoin($ws, 'pc-techno-25', 'OU=techno,OU=computers,DC=localdev,DC=fr');
        self::assertStringContainsString('OU=techno,OU=computers,DC=localdev,DC=fr', $body);
    }

    #[Test]
    public function it_rejects_invalid_ou_with_injection(): void
    {
        $ws = $this->makeWorkstation();

        $this->expectException(BatPlaceholderInjectionException::class);
        $this->builder->buildJoin($ws, 'pc-techno-25', 'OU=evil;rm -rf,DC=fr');
    }

    #[Test]
    public function it_passes_through_empty_role_and_ou_for_join(): void
    {
        $ws = $this->makeWorkstation();
        // Empty role + empty OU = OK (le poste re-Rename lui-meme).
        $body = $this->builder->buildJoin($ws);
        self::assertStringContainsString(':join', $body);
        self::assertStringContainsString(":domaine\r\n", $body);
    }

    /* --------------------------- CRLF normalize ---------------------------- */

    #[Test]
    public function it_normalizes_mixed_line_endings_to_strict_crlf(): void
    {
        $mixed = "line1\r\nline2\nline3\rline4\r\n";
        $normalized = WindowsActionCmdBuilder::normalizeCrlf($mixed);

        self::assertSame("line1\r\nline2\r\nline3\r\nline4\r\n", $normalized);
    }

    #[Test]
    public function it_normalizes_lf_only_to_crlf(): void
    {
        $lfOnly = "line1\nline2\nline3\n";
        $normalized = WindowsActionCmdBuilder::normalizeCrlf($lfOnly);

        self::assertSame("line1\r\nline2\r\nline3\r\n", $normalized);
    }

    /* --------------------------- config & name handling ---------------------------- */

    #[Test]
    public function it_interpolates_se4fs_name_from_config(): void
    {
        config(['sambaedu.se4fs_name' => 'custom-se4fs.lan']);
        $ws = $this->makeWorkstation();
        $body = $this->builder->buildJoin($ws, 'pc-x', 'OU=test,DC=local');

        self::assertStringContainsString('http://custom-se4fs.lan/ipxe/windows/action', $body);
    }

    #[Test]
    public function it_includes_workstation_name_in_header_rem(): void
    {
        $ws = $this->makeWorkstation();
        $body = $this->builder->buildJoin($ws, 'pc-techno-25', 'OU=test,DC=local');

        self::assertStringContainsString('REM  pour pc-techno-25', $body);
    }
}
