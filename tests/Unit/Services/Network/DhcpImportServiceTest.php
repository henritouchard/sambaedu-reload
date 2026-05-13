<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Network;

use App\Models\DhcpReservation;
use App\Services\Network\DhcpImportService;
use App\Services\Network\DhcpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;
use Tests\Traits\CreatesDhcpSchema;

/**
 * Story 8.1 — Tests Unit du service `DhcpImportService` (FR22).
 *
 * Couvre :
 *  - import nominal (5 lignes mixtes OK + erreurs) ;
 *  - header invalide → erreur immédiate, aucune ligne insérée ;
 *  - lignes vides + commentées ignorées en `skipped` ;
 *  - doublons intra-fichier rejetés en `error` ;
 *  - reload exécuté UNE SEULE FOIS (à la fin) ;
 *  - rapport persisté en cache (24h) sous `dhcp.import.report.<uuid>` ;
 *  - une ligne en erreur n'avorte JAMAIS l'import (collecte exhaustive).
 */
class DhcpImportServiceTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesDhcpSchema;

    private FakeCommandRunner $runner;
    private DhcpImportService $service;
    private string $tmpReservationsFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDhcpSchema();
        $this->runner = new FakeCommandRunner();
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->runner->whenContains('systemctl', 'active', returnCode: 0);

        $this->tmpReservationsFile = sys_get_temp_dir() . '/dhcp_test_' . uniqid() . '.inc';
        config(['sambaedu.dhcp.reservations_file' => $this->tmpReservationsFile]);

        $dhcpService = new DhcpService($this->runner);
        $this->service = new DhcpImportService($dhcpService);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpReservationsFile);
        $this->dropDhcpSchema();
        parent::tearDown();
    }

    #[Test]
    public function it_imports_valid_csv_with_mixed_results(): void
    {
        $csv = "name,mac,ip,description\n"
            . "posteOk1,00:11:22:33:44:55,10.0.0.50,desc1\n"
            . "posteOk2,11:22:33:44:55:66,10.0.0.52,desc2\n"
            . "posteBadMac,zz:zz:zz:zz:zz:zz,10.0.0.51,bad mac\n"
            . "posteBadIp,33:44:55:66:77:88,not-an-ip,bad ip\n"
            . "posteOk3,44:55:66:77:88:99,10.0.0.53,desc3\n";

        $report = $this->service->importFromCsvContent($csv);

        $this->assertSame(3, $report->ok);
        $this->assertSame(0, $report->updated);
        $this->assertSame(2, $report->errors);
        $this->assertSame(5, $report->total);

        $this->assertSame(3, DhcpReservation::count());
    }

    #[Test]
    public function it_rejects_invalid_header(): void
    {
        $csv = "wrong,header,here,now\n"
            . "posteOk1,00:11:22:33:44:55,10.0.0.50,desc1\n";

        $report = $this->service->importFromCsvContent($csv);

        $this->assertGreaterThanOrEqual(1, $report->errors);
        $this->assertSame(0, DhcpReservation::count());
    }

    #[Test]
    public function it_skips_empty_and_commented_lines(): void
    {
        $csv = "name,mac,ip,description\n"
            . "\n"
            . "# this is a comment\n"
            . "posteOk1,00:11:22:33:44:55,10.0.0.50,desc1\n";

        $report = $this->service->importFromCsvContent($csv);

        $this->assertGreaterThanOrEqual(1, $report->skipped);
        $this->assertSame(1, $report->ok);
    }

    #[Test]
    public function it_reloads_service_only_once_at_the_end(): void
    {
        $csv = "name,mac,ip,description\n"
            . "posteOk1,00:11:22:33:44:55,10.0.0.50,desc1\n"
            . "posteOk2,11:22:33:44:55:66,10.0.0.52,desc2\n"
            . "posteOk3,44:55:66:77:88:99,10.0.0.53,desc3\n";

        $this->service->importFromCsvContent($csv);

        $reloadCalls = 0;
        foreach ($this->runner->executed as $cmd) {
            if (str_contains($cmd, 'make_dhcpd_conf.sh')) {
                $reloadCalls++;
            }
        }
        $this->assertSame(1, $reloadCalls, 'Le reload service doit être appelé exactement 1 fois (atomicité AC5)');
    }

    #[Test]
    public function it_persists_report_in_cache_under_uuid(): void
    {
        $csv = "name,mac,ip,description\n"
            . "posteOk1,00:11:22:33:44:55,10.0.0.50,desc1\n";

        $report = $this->service->importFromCsvContent($csv);

        $fetched = $this->service->fetchReport($report->uuid);
        $this->assertNotNull($fetched);
        $this->assertSame($report->uuid, $fetched->uuid);
        $this->assertSame(1, $fetched->ok);
    }

    #[Test]
    public function it_rejects_intra_file_duplicates(): void
    {
        $csv = "name,mac,ip,description\n"
            . "poste01,00:11:22:33:44:55,10.0.0.50,first\n"
            . "poste02,00:11:22:33:44:55,10.0.0.51,duplicate mac\n";

        $report = $this->service->importFromCsvContent($csv);

        $this->assertSame(1, $report->ok);
        $this->assertGreaterThanOrEqual(1, $report->errors);
        $this->assertSame(1, DhcpReservation::count());
    }

    #[Test]
    public function it_upserts_existing_reservation_by_mac(): void
    {
        DhcpReservation::create([
            'name' => 'oldName',
            'mac' => '00:11:22:33:44:55',
            'ip' => '10.0.0.10',
            'source' => DhcpReservation::SOURCE_MANUAL,
        ]);

        $csv = "name,mac,ip,description\n"
            . "newName,00:11:22:33:44:55,10.0.0.20,updated description\n";

        $report = $this->service->importFromCsvContent($csv);

        $this->assertSame(0, $report->ok);
        $this->assertSame(1, $report->updated);

        $reservation = DhcpReservation::where('mac', '00:11:22:33:44:55')->first();
        $this->assertSame('newName', $reservation->name);
        $this->assertSame('10.0.0.20', $reservation->ip);
    }

    #[Test]
    public function it_returns_empty_report_on_empty_csv(): void
    {
        $report = $this->service->importFromCsvContent('');
        $this->assertSame(0, $report->total);
        $this->assertSame(0, $report->ok);
        // Aucun reload puisque rien n'a été touché
        $reloadCalls = 0;
        foreach ($this->runner->executed as $cmd) {
            if (str_contains($cmd, 'make_dhcpd_conf.sh')) {
                $reloadCalls++;
            }
        }
        $this->assertSame(0, $reloadCalls);
    }
}
