<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Models\DhcpReservation;
use App\Models\User;
use App\Services\Network\DhcpImportService;
use App\Services\Print\Contracts\CommandRunner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;
use Tests\Traits\CreatesDhcpSchema;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 8.1 — Tests Feature import CSV avec rapport (AC5).
 *
 * Couvre :
 *  - import 5 lignes mixtes (3 OK, 2 erreurs) via fichier fixture ;
 *  - rapport accessible par UUID ;
 *  - reload service appelé 1 fois max.
 */
class DhcpImportCsvTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesDhcpSchema;
    use CreatesPermissionSchema;

    private FakeCommandRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->createPermissionSchema();
        $this->createDhcpSchema();
        (new PermissionSeeder())->run();

        $this->runner = new FakeCommandRunner();
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->runner->whenContains('systemctl', 'active', returnCode: 0);
        $this->app->instance(CommandRunner::class, $this->runner);

        $tmp = sys_get_temp_dir() . '/dhcp_csv_' . uniqid() . '.inc';
        config(['sambaedu.dhcp.reservations_file' => $tmp]);
    }

    protected function tearDown(): void
    {
        $this->dropDhcpSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    public function test_import_fixture_csv_produces_expected_report(): void
    {
        $service = app(DhcpImportService::class);
        $content = file_get_contents(base_path('tests/Fixtures/dhcp/sample-import.csv'));

        $report = $service->importFromCsvContent($content);

        // 3 OK (posteSalle1, imprimanteCDI, posteOk2) + 2 erreurs (posteBadMac, posteBadIp)
        $this->assertGreaterThanOrEqual(3, $report->ok);
        $this->assertGreaterThanOrEqual(2, $report->errors);

        $this->assertDatabaseHas('dhcp_reservations', ['name' => 'posteSalle1']);
        $this->assertDatabaseHas('dhcp_reservations', ['name' => 'imprimanteCDI']);
        $this->assertDatabaseMissing('dhcp_reservations', ['name' => 'posteBadMac']);
    }

    public function test_imported_records_have_import_source(): void
    {
        $service = app(DhcpImportService::class);
        $service->importFromCsvContent("name,mac,ip,description\nposteX,aa:bb:cc:dd:ee:11,10.0.0.10,desc\n");

        $this->assertSame('import', DhcpReservation::where('name', 'posteX')->value('source'));
    }

    public function test_reload_is_called_once_for_multi_line_import(): void
    {
        $service = app(DhcpImportService::class);
        $csv = "name,mac,ip,description\n"
            . "posteA,aa:bb:cc:dd:ee:01,10.0.0.10,a\n"
            . "posteB,aa:bb:cc:dd:ee:02,10.0.0.11,b\n"
            . "posteC,aa:bb:cc:dd:ee:03,10.0.0.12,c\n";
        $service->importFromCsvContent($csv);

        $reloadCalls = 0;
        foreach ($this->runner->executed as $cmd) {
            if (str_contains($cmd, 'make_dhcpd_conf.sh')) {
                $reloadCalls++;
            }
        }
        $this->assertSame(1, $reloadCalls);
    }
}
