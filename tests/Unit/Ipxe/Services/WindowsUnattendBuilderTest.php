<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Enums\WindowsVersion;
use App\Ipxe\Exceptions\UnattendGenerationException;
use App\Ipxe\Services\WindowsUnattendBuilder;
use App\Models\Workstation;
use DOMDocument;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.5 — AC2.1 / AC2.2 / AC2.3 / T2.3.
 *
 * Tests unitaires de {@see WindowsUnattendBuilder} — DOMDocument transforms +
 * interpolation placeholders + anti-injection XML.
 */
class WindowsUnattendBuilderTest extends TestCase
{
    private WindowsUnattendBuilder $service;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();

        config([
            'ipxe.windows.unattend_template_path' => resource_path('ipxe/windows/unattend.xml'),
            'sambaedu.domain' => 'example.org',
            'sambaedu.se4fs_name' => 'se4fs.lan',
            'sambaedu.se4install_name' => 'se4install',
            'sambaedu.se4install_passwd' => 'install-secret',
            'sambaedu.windows.adminse_name' => 'adminse',
            'sambaedu.windows.adminse_passwd' => 'admin-secret',
            'sambaedu.windows.win_key' => 'VK7JG-NPHTM-C97JM-9MPGT-3V66T',
            'sambaedu.windows.win_user' => 'perso-user',
            'sambaedu.windows.win_user_passwd' => 'perso-secret',
            'sambaedu.windows.win_autologon' => 0,
            'sambaedu.computers_rdn' => 'CN=Computers,DC=example,DC=org',
            'ipxe.log.channel' => 'stack',
        ]);

        // se4install : mock renvoyant le mot de passe config en live (iso pré-TOTP).
        $creds = \Mockery::mock(\App\Services\ServiceCredentials::class);
        $creds->shouldReceive('se4installEffectivePassword')
            ->andReturnUsing(fn () => (string) config('sambaedu.se4install_passwd', ''));

        $this->service = new WindowsUnattendBuilder($creds);
    }

    private function makeWorkstation(string $name = 'PC-101', string $uuid = '12345678-1234-1234-1234-aaaaaaaaaaaa'): Workstation
    {
        return Workstation::create([
            'name' => $name,
            'uuid' => $uuid,
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_builds_win10_unattend_with_legacy_bios(): void
    {
        $ws = $this->makeWorkstation();
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win10,
            ['bios' => 'legacy', 'disk' => 0, 'perso' => 0],
        );

        self::assertStringStartsWith('<?xml version="1.0"', $xml);
        self::assertStringContainsString('<DiskConfiguration>', $xml);
        // Legacy bios → 1 seule CreatePartition.
        self::assertStringContainsString('<Label>OS</Label>', $xml);
        // ComputerName injecté (lowercase — convention SambaEdu).
        self::assertStringContainsString('pc-101', $xml);
    }

    #[Test]
    public function it_builds_win11_unattend_with_uefi_bios(): void
    {
        $ws = $this->makeWorkstation();
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );

        // UEFI : ESP + Windows partitions.
        self::assertStringContainsString('<Type>EFI</Type>', $xml);
        self::assertStringContainsString('<Label>System</Label>', $xml);
        self::assertStringContainsString('<Label>Windows</Label>', $xml);
    }

    #[Test]
    public function it_includes_tpm_bypass_for_win11_only(): void
    {
        $ws = $this->makeWorkstation();
        $xmlWin11 = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );
        $xmlWin10 = $this->service->build(
            $ws,
            WindowsVersion::Win10,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );

        self::assertStringContainsString('BypassTPMCheck', $xmlWin11);
        self::assertStringContainsString('BypassSecureBootCheck', $xmlWin11);
        self::assertStringContainsString('BypassRAMCheck', $xmlWin11);
        self::assertStringContainsString('BypassCPUCheck', $xmlWin11);
        self::assertStringContainsString('BypassStorageCheck', $xmlWin11);
        self::assertStringNotContainsString('BypassTPMCheck', $xmlWin10);
    }

    #[Test]
    public function it_excludes_disk_configuration_when_disk_flag_set(): void
    {
        // Parité legacy `unattend.xml.php:28` : disk=1 → bios='' → pas
        // d'injection DiskConfiguration.
        $ws = $this->makeWorkstation();
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'legacy', 'disk' => 1, 'perso' => 0],
        );

        self::assertStringNotContainsString('<DiskConfiguration>', $xml);
        self::assertStringNotContainsString('<WillWipeDisk>', $xml);
    }

    #[Test]
    public function it_uses_join_credentials_when_perso_zero(): void
    {
        $ws = $this->makeWorkstation();
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'legacy', 'disk' => 0, 'perso' => 0],
        );

        // AutoLogon Username = se4install (mode join).
        self::assertMatchesRegularExpression('@<Username>se4install</Username>@', $xml);
        // UnattendedJoin component injecté.
        self::assertStringContainsString('Microsoft-Windows-UnattendedJoin', $xml);
        self::assertStringContainsString('<JoinDomain>example.org</JoinDomain>', $xml);
    }

    #[Test]
    public function it_uses_local_credentials_when_perso_one(): void
    {
        $ws = $this->makeWorkstation();
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'legacy', 'disk' => 0, 'perso' => 1],
        );

        // AutoLogon Username = win_user.
        self::assertMatchesRegularExpression('@<Username>perso-user</Username>@', $xml);
        // PAS de UnattendedJoin (mode perso, hors domaine).
        self::assertStringNotContainsString('Microsoft-Windows-UnattendedJoin', $xml);
    }

    #[Test]
    public function it_injects_workstation_name_in_computer_name_nodes(): void
    {
        $ws = $this->makeWorkstation('SALLE-A-PC07');
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );

        // Au moins 1 occurrence dans <ComputerName>.
        self::assertMatchesRegularExpression(
            '@<ComputerName>salle-a-pc07</ComputerName>@',
            $xml,
        );
    }

    #[Test]
    public function it_interpolates_admin_name_and_se4fs_name_in_commandlines(): void
    {
        $ws = $this->makeWorkstation('PC-101');
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );

        // ###_SE4FS_NAME_### dans une commandline (curl OOBE).
        self::assertStringNotContainsString('###_SE4FS_NAME_###', $xml);
        self::assertStringNotContainsString('###_NAME_###', $xml);
        self::assertStringContainsString('se4fs.lan', $xml);
        // ###_NAME_### remplacé par hostname.
        self::assertStringContainsString('name=pc-101', $xml);
        // Fix 2026-06-04 — uuid/mac dans le curl OOBE : `/ipxe/windows/action`
        // résout par UUID/MAC uniquement (name non trusted) ; sans eux le
        // rapport OOBE part en `unknown_workstation` et les actions
        // programmées ne sont jamais délivrées.
        self::assertStringNotContainsString('###_UUID_###', $xml);
        self::assertStringNotContainsString('###_MAC_###', $xml);
        self::assertStringContainsString('uuid=12345678-1234-1234-1234-aaaaaaaaaaaa', $xml);
        self::assertStringContainsString('mac=aa:bb:cc:dd:ee:01', $xml);
        // Fix 2026-06-04 (bis) — `ret=0` requis : sans lui le controller
        // traite l'absence de ret comme -1 → warning `non_zero_ret` au lieu
        // de `recordOobeComplete` (pas de ligne ipxe_win_report en DB).
        self::assertStringContainsString('-F "ret=0"', $xml);
    }

    // ── Story 23.3 — enrôlement agent porte 1 (AC1/AC5) ─────────────────

    /**
     * @return array<int, string> map Order → CommandLine des FirstLogonCommands.
     */
    private function firstLogonCommandsByOrder(string $xml): array
    {
        $dom = new DOMDocument();
        self::assertTrue(@$dom->loadXML($xml));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ns', 'urn:schemas-microsoft-com:unattend');

        $commands = [];
        $nodes = $xpath->query('//ns:FirstLogonCommands/ns:SynchronousCommand');
        self::assertNotFalse($nodes);
        foreach ($nodes as $node) {
            $order = (int) $xpath->evaluate('string(ns:Order)', $node);
            $commands[$order] = (string) $xpath->evaluate('string(ns:CommandLine)', $node);
        }
        ksort($commands);

        return $commands;
    }

    #[Test]
    public function it_interpolates_enroll_ticket_with_absolute_enrollment_url(): void
    {
        $ticket = str_repeat('ab', 32);
        $xml = $this->service->build(
            $this->makeWorkstation(),
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0, 'enroll_ticket' => $ticket],
        );

        self::assertStringNotContainsString('###_AGENT_ENROLL_TICKET_###', $xml);
        self::assertStringContainsString("ticket='" . $ticket . "'", $xml);
        // URL ABSOLUE obligatoire (piège fetch relatif → 410, mémoire projet).
        self::assertStringContainsString(
            "http://se4fs.lan/api/v1/agent/enrollment",
            $xml,
        );
    }

    #[Test]
    public function it_orders_enrollment_commands_before_the_oobe_curl(): void
    {
        // Le bug le plus probable de la story : l'action.cmd récupéré par le
        // curl oobe peut rebooter en ~5 s — l'enrôlement DOIT passer avant.
        $xml = $this->service->build(
            $this->makeWorkstation(),
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0, 'enroll_ticket' => str_repeat('cd', 32)],
        );

        $commands = $this->firstLogonCommandsByOrder($xml);
        // Story 25.4 : un Order d'install agent (CA + binaire + service) s'insère
        // entre le durcissement ACL (2) et le curl oobe (désormais 4).
        self::assertSame([1, 2, 3, 4, 5], array_keys($commands));
        // 1 : échange ticket → token (dépôt C:\ProgramData\SambaEdu\Agent\token).
        self::assertStringContainsString('/api/v1/agent/enrollment', $commands[1]);
        self::assertStringContainsString('C:\ProgramData\SambaEdu\Agent\token', $commands[1]);
        // 1 (review 23.3) : le dossier est verrouillé AVANT l'écriture du
        // token — le token n'existe jamais sous ACL héritées (Users-readable),
        // et un échec de verrouillage abandonne SANS consommer le ticket.
        $lockPos = strpos($commands[1], 'icacls');
        $writePos = strpos($commands[1], 'Set-Content');
        self::assertNotFalse($lockPos);
        self::assertNotFalse($writePos);
        self::assertLessThan($writePos, $lockPos, 'Le verrouillage ACL doit précéder l\'écriture du token.');
        self::assertStringContainsString('$LASTEXITCODE -ne 0', $commands[1]);
        // 1 (review 23.3) : retry discriminé — seul un 4xx définitif (hors
        // 429) arrête la boucle ; 5xx/429 transitoires sont retentés.
        self::assertStringContainsString('StatusCode', $commands[1]);
        self::assertStringContainsString('-ne 429', $commands[1]);
        // 2 : re-verrouillage ACL ceinture-et-bretelles — héritage coupé,
        // SYSTEM + Administrators (SID).
        self::assertStringContainsString('icacls', $commands[2]);
        self::assertStringContainsString('/inheritance:r', $commands[2]);
        self::assertStringContainsString('*S-1-5-18', $commands[2]);
        self::assertStringContainsString('*S-1-5-32-544', $commands[2]);
        // 3 (Story 25.4, AC3) : dépôt CA + binaire stable + install service,
        // APRÈS le token (convergence immédiate possible), AVANT le curl oobe.
        self::assertStringContainsString('/api/v1/agent/ca', $commands[3]);
        self::assertStringContainsString('certutil', $commands[3]);
        self::assertStringContainsString('-addstore', $commands[3]);
        self::assertStringContainsString('Root', $commands[3]);
        self::assertStringContainsString('/api/v1/agent/stable/download', $commands[3]);
        // Binaire déposé à son emplacement DÉFINITIF avant `install` (piège n° 10) :
        // le dossier littéral + l'exe résolu via Join-Path depuis ce dossier (le
        // SCM enregistre ce chemin, jamais %temp%).
        self::assertStringContainsString("\$dir='C:\\Program Files\\SambaEdu\\Agent'", $commands[3]);
        self::assertStringContainsString("Join-Path \$dir 'agent.exe'", $commands[3]);
        self::assertStringContainsString('install -server-url', $commands[3]);
        // Échec non bloquant : exit 0 (le filet GPO rattrape).
        self::assertStringContainsString('exit 0', $commands[3]);
        // La CA précède le download du binaire (la confiance Authenticode du
        // binaire repose sur la CA déployée).
        $caPos = strpos($commands[3], '/api/v1/agent/ca');
        $binPos = strpos($commands[3], '/api/v1/agent/stable/download');
        self::assertNotFalse($caPos);
        self::assertNotFalse($binPos);
        self::assertLessThan($binPos, $caPos, 'La CA doit être déployée avant le download du binaire.');
        // 4-5 : les commandes historiques glissent, inchangées.
        self::assertStringContainsString('etape=oobe', $commands[4]);
        self::assertStringContainsString('call %windir%\action.cmd', $commands[5]);
    }

    #[Test]
    public function it_drops_non_hex_enroll_ticket_instead_of_interpolating(): void
    {
        // Review 23.3 — invariant hex strict : le ticket atterrit entre
        // quotes simples PowerShell ; un ticket non-hex (impossible via
        // openTicket — défense en profondeur) est vidé, jamais interpolé.
        foreach (["evil\nticket", "x'; calc; '", 'UPPERHEX', '$(/inj)'] as $forged) {
            $xml = $this->service->build(
                $this->makeWorkstation(),
                WindowsVersion::Win11,
                ['bios' => 'uefi', 'disk' => 0, 'perso' => 0, 'enroll_ticket' => $forged],
            );

            $commands = $this->firstLogonCommandsByOrder($xml);
            self::assertStringContainsString("ticket=''", $commands[1]);
        }
    }

    #[Test]
    public function it_leaves_ticket_empty_when_none_provided(): void
    {
        // Sans ticket (migration 23.3 absente) : placeholder vidé, pas de
        // résidu — le POST partira avec ticket vide → 403 non bloquant.
        $xml = $this->service->build(
            $this->makeWorkstation(),
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );

        self::assertStringNotContainsString('###_AGENT_ENROLL_TICKET_###', $xml);
        self::assertStringContainsString("ticket=''", $xml);
    }

    #[Test]
    public function it_throws_unattend_generation_exception_when_template_missing(): void
    {
        config(['ipxe.windows.unattend_template_path' => '/nonexistent/path/unattend.xml']);

        $this->expectException(UnattendGenerationException::class);
        $this->service->build(
            $this->makeWorkstation(),
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );
    }

    #[Test]
    public function it_returns_well_formed_xml_parseable_by_domdocument(): void
    {
        $ws = $this->makeWorkstation();
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );

        $dom = new DOMDocument();
        $loaded = @$dom->loadXML($xml);
        self::assertTrue(
            $loaded,
            'XML généré doit être bien formé pour DOMDocument::loadXML().',
        );
    }

    #[Test]
    public function it_escapes_xml_special_chars_in_computer_name(): void
    {
        // Defense in depth : si un name AD bypass la sanitization amont
        // (3.3), le builder doit lui-même ne PAS injecter `<EVIL>` comme
        // balise XML.
        $ws = $this->makeWorkstation('PC-101&EVIL');
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );

        // DOMDocument escape automatiquement `&` lors de la sérialisation
        // (nodeValue set → escape implicite à `saveXML()`).
        $dom = new DOMDocument();
        self::assertTrue(@$dom->loadXML($xml));
        // Pas de balise <EVIL> active.
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ns', 'urn:schemas-microsoft-com:unattend');
        $evilNodes = $dom->getElementsByTagName('EVIL');
        self::assertSame(0, $evilNodes->length);
    }

    #[Test]
    public function it_logs_template_missing_with_error_level(): void
    {
        config(['ipxe.windows.unattend_template_path' => '/nonexistent/path/unattend.xml']);

        // `Log::spy()` ne mock pas les channels — on stub explicitement.
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('error');
        Log::shouldReceive('info');
        Log::shouldReceive('warning');

        try {
            $this->service->build(
                $this->makeWorkstation(),
                WindowsVersion::Win11,
                ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
            );
        } catch (UnattendGenerationException $e) {
            // ok.
        }

        Log::shouldHaveReceived('channel')->atLeast()->once();
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    #[Test]
    public function it_sets_administrator_password(): void
    {
        $ws = $this->makeWorkstation();
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );

        // AdministratorPassword/Value set à `adminse_passwd`.
        self::assertMatchesRegularExpression(
            '@<AdministratorPassword>\s*<Value>admin-secret</Value>@s',
            $xml,
        );
    }

    #[Test]
    public function it_uses_provided_ad_ou_for_machine_object_ou(): void
    {
        $ws = $this->makeWorkstation();
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            [
                'bios' => 'legacy',
                'disk' => 0,
                'perso' => 0,
                'ou' => 'OU=Salle-A,DC=example,DC=org',
            ],
        );

        self::assertStringContainsString(
            '<MachineObjectOU>OU=Salle-A,DC=example,DC=org</MachineObjectOU>',
            $xml,
        );
    }

    /**
     * Post-review code-review #3 (defense in depth D6) — un credential contenant
     * `&` ou `<` doit produire du XML well-formed (sans warning + sans node
     * vide). `setNodeValue()` doit appliquer `WindowsXmlPlaceholders::sanitize()`
     * AVANT d'assigner `nodeValue =` (qui attend du XML déjà escapé).
     */
    #[Test]
    public function it_produces_well_formed_xml_with_special_chars_in_credentials(): void
    {
        config([
            // Credential avec un `&` ET un `<` — sans sanitize, le node serait vidé.
            'sambaedu.windows.adminse_passwd' => 'P&ssw0rd<test>',
            'sambaedu.windows.win_user_passwd' => 'foo"bar',
        ]);

        $ws = $this->makeWorkstation();
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 1],
        );

        // 1. Le XML doit être well-formed (parsable sans erreur).
        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'XML output n\'est pas well-formed.');

        // 2. Les credentials sont présents (encodés correctement).
        // `<` et `&` sont escapés à la sérialisation par DOMDocument.
        self::assertStringContainsString('P&amp;ssw0rd&lt;test&gt;', $xml);
        // Note : DOMDocument décode `&quot;` à l'assignation `nodeValue =`
        // puis ne ré-escape pas `"` en text content (caractère valide).
        // Le test asserte donc la version brute, pas l'entité.
        self::assertStringContainsString('foo"bar', $xml);
    }

    /**
     * Post-review code-review #3 — non-régression : `interpolateTextNodes` ne
     * doit PAS double-escape les valeurs (textContent fait l'escape natif).
     * Un placeholder remplacé par une valeur contenant `&` doit produire
     * `&amp;` UNE fois à la sérialisation, pas `&amp;amp;`.
     */
    #[Test]
    public function it_does_not_double_escape_in_command_lines(): void
    {
        // Le hostname workstation est interpolé dans des CommandLine
        // (`-F "name=###_NAME_###"`). Si le nom contient `&`, le résultat
        // doit être `name=PC&amp;101` UNE fois escapé.
        $ws = $this->makeWorkstation('pc&101');

        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 0],
        );

        // 1. XML well-formed.
        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml));

        // 2. Pas de double-escape `&amp;amp;`.
        self::assertStringNotContainsString('&amp;amp;', $xml);
    }

    /**
     * Post-review code-review #4 — AC2.3 CRITICAL : pas de secret loggué.
     *
     * On injecte des CANARY secrets connus dans la config, on capture tous les
     * events Monolog via TestHandler, et on assert qu'AUCUN log ne contient
     * une canary. Pattern iso `LinuxPreseedServiceTest::it_logs_preseed_*`.
     */
    #[Test]
    public function it_does_not_leak_secrets_in_logs(): void
    {
        config([
            'sambaedu.se4install_passwd' => 'CANARY-install-pwd-123',
            'sambaedu.windows.adminse_passwd' => 'CANARY-admin-pwd-456',
            'sambaedu.windows.win_user_passwd' => 'CANARY-user-pwd-789',
            'sambaedu.windows.win_key' => 'CANARY-product-key-XYZ',
            'ipxe.log.channel' => 'ipxe',
        ]);

        // Push un TestHandler Monolog sur le logger existant du channel `ipxe`
        // (channel `daily` côté config — Log::extend ne fonctionne pas).
        $handler = new \Monolog\Handler\TestHandler();
        $logger = \Illuminate\Support\Facades\Log::channel('ipxe');
        $logger->getLogger()->pushHandler($handler);

        $ws = $this->makeWorkstation();
        $xml = $this->service->build(
            $ws,
            WindowsVersion::Win11,
            ['bios' => 'uefi', 'disk' => 0, 'perso' => 1],
        );

        // Sanity check : les canaries sont bien interpolées dans le XML output
        // (sinon le test serait tautologique).
        self::assertStringContainsString('CANARY-admin-pwd-456', $xml);

        // Assertion principale : aucun secret ne fuite dans les logs.
        $records = $handler->getRecords();
        foreach ($records as $record) {
            $payload = json_encode([
                'message' => $record['message'] ?? '',
                'context' => $record['context'] ?? [],
            ]);
            self::assertStringNotContainsString('CANARY-install-pwd-123', (string) $payload);
            self::assertStringNotContainsString('CANARY-admin-pwd-456', (string) $payload);
            self::assertStringNotContainsString('CANARY-user-pwd-789', (string) $payload);
            self::assertStringNotContainsString('CANARY-product-key-XYZ', (string) $payload);
        }
    }
}
