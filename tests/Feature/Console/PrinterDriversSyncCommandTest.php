<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Printer;
use App\Models\PrinterDriver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Print\PrintDriverService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;
use Tests\Traits\CreatesPrinterDriversSchema;
use Tests\Traits\CreatesPrintersSchema;

/**
 * Story 6.2 — Tests Feature de la commande `printer-drivers:sync`.
 *
 * Décalque le pattern `PrintersSyncCommandTest` 6.1 (fix #12) :
 *  - dry-run : aucune écriture en DB.
 *  - marquage orphan : SER row non-orphan absente de Samba → orphan=true.
 *  - restauration : SER orphan + Samba la retrouve → orphan=false.
 *  - idempotence : 2 runs consécutifs sur état aligné = 0 modification.
 *  - skip si Samba down (fix #12 décalqué) : RC != 0, aucun row marqué orphan.
 *
 * Note 6.2 : la sync NE CRÉE PAS de lignes SER pour les drivers Samba sans
 * printer_cups_name (cf. note de tête de la commande — rattachement
 * exclusivement via workflow upload UI). Test dédié vérifie le warning log.
 */
class PrinterDriversSyncCommandTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;
    use CreatesPrintersSchema;
    use CreatesPrinterDriversSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        config(['sambaedu.se4fs_name' => 'se4fs']);
        Queue::fake();
        WorkstationGroupObserver::disableSync();

        $this->createPermissionSchema();
        $this->createPrintersSchema();
        $this->createPrinterDriversSchema();
        (new PermissionSeeder())->run();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        $this->dropPrinterDriversSchema();
        $this->dropPrintersSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    /**
     * @param  string[]  $sambaDriverNames
     * @param  array<int, array{cups_name:string,driver_name:string}>  $se4fsAssocs
     */
    private function bindFakePrintDriverService(
        array $sambaDriverNames,
        bool $sambaHealthy = true,
        array $se4fsAssocs = [],
    ): FakeCommandRunner {
        $runner = new FakeCommandRunner();

        // srvinfo → santé Samba.
        if ($sambaHealthy) {
            $runner->whenContains('srvinfo', 'Server: se4fs ok');
        } else {
            $runner->whenContains('srvinfo', '', 1, 'NT_STATUS_PIPE_NOT_AVAILABLE');
        }

        // enumdrivers — output canonique
        if (!empty($sambaDriverNames)) {
            $lines = ["[Windows x64]"];
            foreach ($sambaDriverNames as $name) {
                $lines[] = "Printer Driver Info 1:";
                $lines[] = "\tDriver Name: [{$name}]";
                $lines[] = "";
            }
            $runner->whenContains('enumdrivers', implode("\n", $lines));
        } else {
            $runner->whenContains('enumdrivers', '[Windows x64]');
        }

        // enumprinters — associations cups_name → driver_name côté SE4FS.
        // Format legacy `description:[\\hostname\printer,driver,comment]`.
        if (!empty($se4fsAssocs)) {
            $lines = [];
            foreach ($se4fsAssocs as $assoc) {
                $lines[] = "\tdescription:[\\\\se4fs\\{$assoc['cups_name']},{$assoc['driver_name']},]";
            }
            $runner->whenContains('enumprinters', implode("\n", $lines));
        } else {
            $runner->whenContains('enumprinters', '');
        }

        $runner->setDefault(0, '');

        $service = new PrintDriverService($runner);
        $this->app->instance(PrintDriverService::class, $service);

        return $runner;
    }

    #[Test]
    public function dry_run_emits_report_without_writing(): void
    {
        // 1 ligne SER non-orphan absente de Samba → devrait être marquée
        // orphan en mode normal, mais pas en dry-run.
        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'imp1',
            'architecture' => 'x64',
            'driver_name' => 'Ghost Driver',
            'source' => 'synced',
            'orphan' => false,
        ]);

        $this->bindFakePrintDriverService([]); // Samba retourne 0 drivers.

        $code = Artisan::call('printer-drivers:sync', ['--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertFalse(
            PrinterDriver::findByKey('imp1', 'x64')->orphan,
            'Dry-run ne doit pas écrire en DB',
        );

        $output = Artisan::output();
        $this->assertStringContainsString('dry-run', $output);
        $this->assertStringContainsString('marqués orphan : 1', $output);
    }

    #[Test]
    public function sync_marks_orphan_when_driver_disappeared_from_samba_preserving_audit(): void
    {
        Printer::create(['cups_name' => 'imp2', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'imp2',
            'architecture' => 'x64',
            'driver_name' => 'Disappeared',
            'source' => 'upload-w10',
            'orphan' => false,
            'notes' => 'Driver historique',
        ]);

        $this->bindFakePrintDriverService([]); // Samba: aucun driver publié.

        Artisan::call('printer-drivers:sync');
        $row = PrinterDriver::findByKey('imp2', 'x64');
        $this->assertNotNull($row);
        $this->assertTrue($row->orphan);
        $this->assertSame('Driver historique', $row->notes, 'L\'audit doit être préservé');
        $this->assertSame('upload-w10', $row->source);
    }

    #[Test]
    public function sync_restores_orphan_on_reintroduction(): void
    {
        Printer::create(['cups_name' => 'imp3', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'imp3',
            'architecture' => 'x64',
            'driver_name' => 'Restored Driver',
            'source' => 'synced',
            'orphan' => true,
        ]);

        $this->bindFakePrintDriverService(['Restored Driver']);

        Artisan::call('printer-drivers:sync');
        $row = PrinterDriver::findByKey('imp3', 'x64');
        $this->assertNotNull($row);
        $this->assertFalse($row->orphan);
    }

    #[Test]
    public function sync_is_idempotent_on_aligned_state(): void
    {
        Printer::create(['cups_name' => 'imp4', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'imp4',
            'architecture' => 'x64',
            'driver_name' => 'Aligned Driver',
            'source' => 'synced',
            'orphan' => false,
        ]);

        $this->bindFakePrintDriverService(
            ['Aligned Driver'],
            se4fsAssocs: [['cups_name' => 'imp4', 'driver_name' => 'Aligned Driver']],
        );

        // Fix #22 — assert l'absence d'UPDATE sur printer_drivers, plutôt
        // que de comparer updated_at (Eloquent ne touche pas updated_at
        // si aucune valeur ne change ; le test passerait même avec des
        // UPDATEs no-op inutiles).
        $before = PrinterDriver::findByKey('imp4', 'x64')->updated_at;
        DB::enableQueryLog();
        DB::flushQueryLog();
        Artisan::call('printer-drivers:sync');
        Artisan::call('printer-drivers:sync');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $after = PrinterDriver::findByKey('imp4', 'x64')->updated_at;

        $updates = array_filter(
            $queries,
            fn($q) => stripos($q['query'] ?? '', 'update "printer_drivers"') !== false
                || stripos($q['query'] ?? '', 'update `printer_drivers`') !== false,
        );
        $inserts = array_filter(
            $queries,
            fn($q) => stripos($q['query'] ?? '', 'insert into "printer_drivers"') !== false
                || stripos($q['query'] ?? '', 'insert into `printer_drivers`') !== false,
        );
        $this->assertCount(0, $updates, 'État aligné → aucune requête UPDATE sur printer_drivers');
        $this->assertCount(0, $inserts, 'État aligné → aucune requête INSERT sur printer_drivers');
        $this->assertEquals($before->timestamp, $after->timestamp, 'État aligné → updated_at inchangé');
    }

    #[Test]
    public function sync_skips_orphan_marking_when_samba_down(): void
    {
        Printer::create(['cups_name' => 'imp5', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'imp5',
            'architecture' => 'x64',
            'driver_name' => 'Should Be Safe',
            'source' => 'synced',
            'orphan' => false,
        ]);

        $this->bindFakePrintDriverService([], sambaHealthy: false);

        $code = Artisan::call('printer-drivers:sync');
        $this->assertSame(1, $code, 'RC != 0 attendu quand Samba down');

        $row = PrinterDriver::findByKey('imp5', 'x64');
        $this->assertFalse($row->orphan, 'Samba down → aucun row ne doit être marqué orphan');
    }

    #[Test]
    public function sync_logs_cups_name_absent_when_se4fs_assoc_unresolvable(): void
    {
        // Q1A — la sync interroge enumprinters côté SE4FS. Si une
        // association cups_name → driver est détectée mais le cups_name
        // n'existe pas dans la table Printer SER, on log warning + skip.
        $this->bindFakePrintDriverService(
            ['Brand New Driver'],
            se4fsAssocs: [['cups_name' => 'imp_ghost', 'driver_name' => 'Brand New Driver']],
        );

        $code = Artisan::call('printer-drivers:sync');
        $this->assertSame(0, $code);

        $output = Artisan::output();
        $this->assertStringContainsString('cups_name absent SER : 1', $output);
        $this->assertSame(0, PrinterDriver::count(), 'Aucune ligne SER ne doit avoir été insérée');
    }

    #[Test]
    public function sync_auto_attaches_driver_when_enumprinters_returns_resolvable_association(): void
    {
        // Q1A happy-path : enumprinters retourne (imp1, Generic / PS),
        // imp1 existe dans Printer SER → INSERT auto avec source=`synced`.
        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);

        $this->bindFakePrintDriverService(
            ['Generic / PS'],
            se4fsAssocs: [['cups_name' => 'imp1', 'driver_name' => 'Generic / PS']],
        );

        $code = Artisan::call('printer-drivers:sync');
        $this->assertSame(0, $code);

        $output = Artisan::output();
        $this->assertStringContainsString('auto-attachés : 1', $output);

        $drv = PrinterDriver::findByKey('imp1', 'x64');
        $this->assertNotNull($drv, 'Une ligne SER doit avoir été insérée');
        $this->assertSame('Generic / PS', $drv->driver_name);
        $this->assertSame('synced', $drv->source);
        $this->assertNull($drv->created_by_user_id);
    }

    #[Test]
    public function sync_reports_combined_diff_in_output(): void
    {
        Printer::create(['cups_name' => 'imp6', 'orphan' => false]);
        Printer::create(['cups_name' => 'imp7', 'orphan' => false]);

        PrinterDriver::create([
            'printer_cups_name' => 'imp6',
            'architecture' => 'x64',
            'driver_name' => 'Stays Aligned',
            'source' => 'synced',
            'orphan' => false,
        ]);
        PrinterDriver::create([
            'printer_cups_name' => 'imp7',
            'architecture' => 'x64',
            'driver_name' => 'Comes Back',
            'source' => 'synced',
            'orphan' => true,
        ]);

        $this->bindFakePrintDriverService(
            ['Stays Aligned', 'Comes Back', 'New Samba Driver'],
            se4fsAssocs: [
                ['cups_name' => 'imp6', 'driver_name' => 'Stays Aligned'],
                ['cups_name' => 'imp7', 'driver_name' => 'Comes Back'],
                ['cups_name' => 'imp_ghost', 'driver_name' => 'New Samba Driver'],
            ],
        );

        Artisan::call('printer-drivers:sync');
        $output = Artisan::output();

        // 1 cups_name SER absent (imp_ghost), 0 marqué orphan, 1 restauré, 0 auto-attaché
        // (les 2 associations résolues sont déjà matérialisées en SER).
        $this->assertStringContainsString('cups_name absent SER : 1', $output);
        $this->assertStringContainsString('marqués orphan : 0', $output);
        $this->assertStringContainsString('restaurés : 1', $output);

        $this->assertFalse(PrinterDriver::findByKey('imp7', 'x64')->orphan);
        $this->assertFalse(PrinterDriver::findByKey('imp6', 'x64')->orphan);
    }

    #[Test]
    public function sync_marks_orphan_counts_all_rows_when_driver_attached_to_multiple_printers(): void
    {
        // Fix #5 — un même driver_name rattaché à 2 imprimantes différentes
        // donne 2 lignes SER ; quand le driver disparaît de Samba, les 2
        // lignes doivent passer orphan=true ET le compteur doit afficher 2
        // (pas 1, ce qui serait le bug de keyBy() qui écrase).
        Printer::create(['cups_name' => 'impA', 'orphan' => false]);
        Printer::create(['cups_name' => 'impB', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'impA',
            'architecture' => 'x64',
            'driver_name' => 'Shared Driver',
            'source' => 'upload-w10',
            'orphan' => false,
        ]);
        PrinterDriver::create([
            'printer_cups_name' => 'impB',
            'architecture' => 'x64',
            'driver_name' => 'Shared Driver',
            'source' => 'upload-w10',
            'orphan' => false,
        ]);

        $this->bindFakePrintDriverService([]); // Samba: aucun driver

        Artisan::call('printer-drivers:sync');
        $output = Artisan::output();

        $this->assertStringContainsString('marqués orphan : 2', $output);
        $this->assertTrue(PrinterDriver::findByKey('impA', 'x64')->orphan);
        $this->assertTrue(PrinterDriver::findByKey('impB', 'x64')->orphan);
    }
}
