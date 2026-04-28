<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Print;

use App\Services\Print\CupsPrinterService;
use App\Services\Print\Exceptions\CupsCommandException;
use App\Services\Print\Exceptions\CupsDaemonDownException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;

/**
 * Story 6.1 — Tests Unit du Service CupsPrinterService.
 *
 * Couvre :
 *   - Validation regex name/uri (defense in depth, AC8).
 *   - parse_url() post-regex pour les URI structurellement invalides (fix #3).
 *   - Parsing `lpstat -s` + `lpstat -l -p` + `lpinfo -m` + `lpstat -o` (batch, fix #2).
 *   - `isHealthy()` via `lpstat -r` (fix #12).
 *   - `listPrinters()` lève `CupsDaemonDownException` si CUPS down (fix #12).
 *   - Exec sécurisé (escapeshellarg vérifié dans la commande exécutée).
 *   - CupsCommandException structurée sur returnCode != 0.
 *   - Reload Samba retourne bool (fix #15).
 *
 * Pas de DB / Eloquent ici — c'est du shellout pur.
 */
class CupsPrinterServiceTest extends TestCase
{
    private FakeCommandRunner $runner;
    private CupsPrinterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new FakeCommandRunner();
        $this->service = new CupsPrinterService($this->runner);
    }

    private function fixture(string $name): string
    {
        return base_path('tests/fixtures/cups/' . $name);
    }

    // ========================================================================
    // VALIDATION
    // ========================================================================

    #[Test]
    public function it_accepts_valid_printer_names(): void
    {
        $this->service->validateName('imp1');
        $this->service->validateName('A_B-9');
        $this->service->validateName('a');
        $this->service->validateName('aaaaaaaaaaaaaaa'); // 15
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[DataProvider('maliciousNamePayloads')]
    public function it_rejects_malicious_printer_names(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateName($payload);
    }

    public static function maliciousNamePayloads(): array
    {
        return [
            'empty' => [''],
            'too_long' => [str_repeat('a', 16)],
            'space' => ['imp 1'],
            'slash' => ['../etc'],
            'semicolon_inject' => ['imp;rm'],
            'backtick' => ['imp`id`'],
            'dollar_paren' => ['imp$(id)'],
            'pipe' => ['imp|wc'],
            'amp' => ['imp&id'],
            'newline' => ["imp\nrm"],
            'null_byte' => ["imp\0rm"],
            'redirect' => ['imp>file'],
            'utf8_quote' => ["imp'rm"],
            'double_quote' => ['imp"rm'],
        ];
    }

    #[Test]
    #[DataProvider('maliciousUriPayloads')]
    public function it_rejects_malicious_uris(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateUri($payload);
    }

    public static function maliciousUriPayloads(): array
    {
        return [
            'empty' => [''],
            'no_scheme' => ['192.168.1.10:9100'],
            'invalid_scheme' => ['file:///etc/passwd'],
            'space' => ['socket://1.2.3.4:9100 evil'],
            'semicolon' => ['socket://1.2.3.4;rm'],
            'backtick' => ['socket://`id`'],
            'dollar_paren' => ['socket://$(id)'],
            'newline' => ["socket://1.2.3.4\nrm"],
            'pipe' => ['socket://1.2.3.4|wc'],
            'quote' => ["socket://1.2.3.4'rm"],
            // Fix #3 : variantes socket:// sans hôte (structurellement invalides après parse_url).
            'socket_no_host' => ['socket:///etc/passwd'],
            'socket_empty_host' => ['socket://'],
        ];
    }

    #[Test]
    public function it_accepts_valid_uris(): void
    {
        $this->service->validateUri('socket://192.168.1.10:9100');
        $this->service->validateUri('ipp://printer.example.com/ipp/print');
        $this->service->validateUri('ipps://x.example.com/ipp/print');
        $this->service->validateUri('lpd://1.2.3.4/queue');
        $this->service->validateUri('http://1.2.3.4:631/printers/p1');
        $this->expectNotToPerformAssertions();
    }

    // ========================================================================
    // SANTÉ CUPS
    // ========================================================================

    #[Test]
    public function is_healthy_returns_true_when_lpstat_r_succeeds(): void
    {
        $this->runner->whenContains('lpstat -r', 'scheduler is running');
        $this->assertTrue($this->service->isHealthy());
    }

    #[Test]
    public function is_healthy_returns_false_when_lpstat_r_fails(): void
    {
        $this->runner->whenContains('lpstat -r', '', 1, 'Connection refused');
        $this->assertFalse($this->service->isHealthy());
    }

    // ========================================================================
    // LISTING
    // ========================================================================

    #[Test]
    public function it_parses_lpstat_outputs_into_typed_array(): void
    {
        $this->runner
            ->whenContainsFromFixture('lpstat -s', $this->fixture('lpstat-s-multi.txt'))
            ->whenContainsFromFixture('lpstat -l -p', $this->fixture('lpstat-l-p-multi.txt'))
            ->whenContains('lpstat -o', '', 0); // batch jobs count (fix #2)

        $printers = $this->service->listPrinters();

        $this->assertCount(3, $printers);

        $byName = collect($printers)->keyBy('name');
        $this->assertSame('cups-pdf:/', $byName['PDF']['uri']);
        $this->assertSame('idle', $byName['PDF']['state']);
        $this->assertSame('PDF', $byName['PDF']['model']);

        $this->assertSame('socket://192.168.1.10:9100', $byName['hp1']['uri']);
        $this->assertSame('printing', $byName['hp1']['state']);
        $this->assertSame('Salle A', $byName['hp1']['location']);

        $this->assertSame('disabled', $byName['hp2']['state']);
    }

    #[Test]
    public function it_throws_daemon_down_exception_when_lpstat_fails(): void
    {
        $this->runner->whenContains('lpstat -s', '', 1, 'lpstat: No connections to server');
        $this->expectException(CupsDaemonDownException::class);
        $this->service->listPrinters();
    }

    #[Test]
    public function it_counts_jobs_for_a_printer(): void
    {
        $this->runner->whenContains('lpstat -o', "PDF-1 alice 1024 ...\nPDF-2 bob 2048 ...");
        $count = $this->service->getJobsCount('PDF');
        $this->assertSame(2, $count);
    }

    #[Test]
    public function it_lists_available_drivers(): void
    {
        $this->runner->whenContainsFromFixture('lpinfo -m', $this->fixture('lpinfo-m.txt'));
        $drivers = $this->service->listAvailableDrivers();
        $this->assertNotEmpty($drivers);
        $this->assertArrayHasKey('ppd', $drivers[0]);
        $this->assertArrayHasKey('model', $drivers[0]);
    }

    // ========================================================================
    // ADD / UPDATE / DELETE
    // ========================================================================

    #[Test]
    public function it_executes_lpadmin_with_escaped_arguments_on_add(): void
    {
        $this->runner->setDefault(0);

        $sambaOk = $this->service->addPrinter('imp1', 'socket://1.2.3.4:9100', "Salle d'éval", 'A101', null);

        $cmds = $this->runner->executed;
        $lpadmin = collect($cmds)->first(fn($c) => str_starts_with($c, 'sudo lpadmin -p '));

        $this->assertNotNull($lpadmin);
        $this->assertStringContainsString("'imp1'", $lpadmin);
        $this->assertStringContainsString("'socket://1.2.3.4:9100'", $lpadmin);
        $this->assertStringContainsString('-D ', $lpadmin);
        $this->assertStringContainsString('-L ', $lpadmin);
        $this->assertStringContainsString(' -E ', $lpadmin);

        // Fix #15 : addPrinter retourne bool (résultat reload Samba).
        $this->assertTrue($sambaOk);
        $this->assertContains('sudo /usr/bin/smbcontrol smbd reload-printers', $cmds);
    }

    #[Test]
    public function add_printer_returns_false_when_samba_reload_fails(): void
    {
        $this->runner
            ->whenContains('lpadmin -p', '', 0)
            ->whenContains('smbcontrol', '', 1, 'smbd not running')
            ->setDefault(0);

        $sambaOk = $this->service->addPrinter('imp1', 'socket://1.2.3.4:9100');
        $this->assertFalse($sambaOk);
    }

    #[Test]
    public function it_throws_cups_exception_when_lpadmin_fails(): void
    {
        $this->runner
            ->whenContains('lpadmin -p', '', 1, 'lpadmin: Bad device-uri')
            ->setDefault(0);

        try {
            $this->service->addPrinter('imp1', 'socket://1.2.3.4:9100');
            $this->fail('Expected CupsCommandException');
        } catch (CupsCommandException $e) {
            $this->assertSame(1, $e->getReturnCode());
            $this->assertStringContainsString('lpadmin: Bad device-uri', $e->firstStderrLine());
            $this->assertStringContainsString('lpadmin -p', $e->getCommand());
        }
    }

    #[Test]
    public function it_executes_lpadmin_minus_x_on_delete(): void
    {
        $this->runner->setDefault(0);

        $this->service->deletePrinter('imp1');

        $cmds = $this->runner->executed;
        $lpadmin = collect($cmds)->first(fn($c) => str_contains($c, 'lpadmin -x'));
        $this->assertNotNull($lpadmin);
        $this->assertStringContainsString("'imp1'", $lpadmin);
    }

    #[Test]
    public function it_only_passes_changed_options_on_update(): void
    {
        $this->runner->setDefault(0);

        $this->service->updatePrinter('imp1', [
            'description' => 'Nouveau desc',
            'location' => 'Salle Z',
        ]);

        $cmd = collect($this->runner->executed)->first(
            fn($c) => str_starts_with($c, 'sudo lpadmin -p '),
        );
        $this->assertNotNull($cmd);
        $this->assertStringContainsString('-D ', $cmd);
        $this->assertStringContainsString('-L ', $cmd);
        $this->assertStringNotContainsString('-v ', $cmd);
        $this->assertStringNotContainsString('-m ', $cmd);
    }

    #[Test]
    public function it_executes_cupsenable(): void
    {
        $this->runner->setDefault(0);
        $this->service->enablePrinter('imp1');
        $cmd = $this->runner->lastCommand();
        $this->assertStringContainsString('cupsenable', (string) $cmd);
        $this->assertStringContainsString("'imp1'", (string) $cmd);
    }

    #[Test]
    public function it_executes_cupsdisable(): void
    {
        $this->runner->setDefault(0);
        $this->service->disablePrinter('imp1');
        $cmd = $this->runner->lastCommand();
        $this->assertStringContainsString('cupsdisable', (string) $cmd);
        $this->assertStringContainsString("'imp1'", (string) $cmd);
    }

    #[Test]
    public function it_throws_when_disable_fails(): void
    {
        $this->runner->whenContains('cupsdisable', '', 1, 'no such printer');
        $this->expectException(CupsCommandException::class);
        $this->service->disablePrinter('imp1');
    }

    #[Test]
    public function it_throws_invalid_argument_on_invalid_name_during_add(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->addPrinter('imp;rm', 'socket://1.2.3.4:9100');
    }

    #[Test]
    public function it_throws_invalid_argument_on_invalid_uri_during_add(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->addPrinter('imp1', 'file:///etc/passwd');
    }

    #[Test]
    public function it_uses_real_fixture_format_lpstat_s(): void
    {
        $this->runner
            ->whenContainsFromFixture('lpstat -s', $this->fixture('lpstat-s.txt'))
            ->whenContainsFromFixture('lpstat -l -p', $this->fixture('lpstat-l-p.txt'))
            ->whenContains('lpstat -o', '', 0);

        $printers = $this->service->listPrinters();

        $this->assertCount(1, $printers);
        $this->assertSame('PDF', $printers[0]['name']);
        $this->assertSame('cups-pdf:/', $printers[0]['uri']);
        $this->assertSame('idle', $printers[0]['state']);
    }
}
