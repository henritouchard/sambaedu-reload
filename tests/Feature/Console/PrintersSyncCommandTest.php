<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Printer;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Print\CupsPrinterService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;
use Tests\Traits\CreatesPrintersSchema;

/**
 * Story 6.1 — Tests Feature de la commande `printers:sync`.
 *
 * Couvre AC9 :
 *  - dry-run : aucune écriture en DB.
 *  - ajout : CUPS retourne N imprimantes absentes en SER → INSERT (orphan=false).
 *  - marquage orphan : SER retourne M imprimantes non-orphan absentes de CUPS → UPDATE orphan=true.
 *  - restauration : SER orphan + CUPS la retrouve → UPDATE orphan=false.
 *  - idempotence : 2 runs consécutifs sur état aligné = 0 modification.
 *  - fix #12 : CUPS down → skip (aucun row SER marqué orphan).
 */
class PrintersSyncCommandTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;
    use CreatesPrintersSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        Queue::fake();
        WorkstationGroupObserver::disableSync();

        $this->createPermissionSchema();
        $this->createPrintersSchema();
        (new PermissionSeeder())->run();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        $this->dropPrintersSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    /**
     * Bind un `CupsPrinterService` qui retourne une liste programmable
     * d'imprimantes via un `FakeCommandRunner`.
     *
     * Fix #12 : programme également `lpstat -r` → success (CUPS healthy)
     * pour que `isHealthy()` retourne true et laisse passer la synchronisation.
     *
     * @param  array<int, array{name:string,uri:string}>  $cupsPrinters
     */
    private function bindFakeCupsService(array $cupsPrinters): FakeCommandRunner
    {
        $runner = new FakeCommandRunner();

        // Fix #12 : lpstat -r → CUPS est healthy.
        $runner->whenContains('lpstat -r', 'scheduler is running');

        // `lpstat -s` → "device for X: socket://..."
        $sLines = ['system default destination: none'];
        foreach ($cupsPrinters as $p) {
            $sLines[] = "device for {$p['name']}: {$p['uri']}";
        }
        $runner->whenContains('lpstat -s', implode("\n", $sLines));

        // `lpstat -l -p` → bloc multi-lignes par imprimante.
        $lpLines = [];
        foreach ($cupsPrinters as $p) {
            $lpLines[] = "printer {$p['name']} is idle.  enabled since 2026-04-27";
            $lpLines[] = "    Description: " . ($p['description'] ?? '');
            $lpLines[] = "    Location: " . ($p['location'] ?? '');
            $lpLines[] = "    Interface: /etc/cups/ppd/{$p['name']}.ppd";
        }
        $runner->whenContains('lpstat -l -p', implode("\n", $lpLines));

        // `lpstat -o` → 0 jobs (batch, fix #2).
        $runner->whenContains('lpstat -o', '');
        $runner->setDefault(0, '');

        $service = new CupsPrinterService($runner);
        $this->app->instance(CupsPrinterService::class, $service);

        return $runner;
    }

    #[Test]
    public function dry_run_does_not_write_to_database(): void
    {
        $this->bindFakeCupsService([
            ['name' => 'imp_drya', 'uri' => 'socket://192.0.2.10:9100'],
            ['name' => 'imp_dryb', 'uri' => 'socket://192.0.2.11:9100'],
        ]);

        $this->assertSame(0, Printer::count());

        $code = Artisan::call('printers:sync', ['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(0, Printer::count(), 'Dry-run a écrit en DB');

        $output = Artisan::output();
        $this->assertStringContainsString('dry-run', $output);
        $this->assertStringContainsString('ajoutées : 2', $output);
    }

    #[Test]
    public function adds_new_cups_printers_to_ser_table(): void
    {
        $this->bindFakeCupsService([
            ['name' => 'imp_a', 'uri' => 'socket://192.0.2.10:9100'],
            ['name' => 'imp_b', 'uri' => 'socket://192.0.2.11:9100'],
        ]);

        $this->assertSame(0, Printer::count());

        $code = Artisan::call('printers:sync');
        $this->assertSame(0, $code);

        $this->assertSame(2, Printer::count());
        $impA = Printer::find('imp_a');
        $this->assertNotNull($impA);
        $this->assertFalse($impA->orphan);
        $this->assertNull($impA->created_by_user_id);
        $this->assertNull($impA->description_ser);
    }

    #[Test]
    public function marks_ser_rows_orphan_when_absent_from_cups(): void
    {
        $user = User::create(['login' => 'admin', 'role' => 'admin', 'is_active' => true]);
        $group = WorkstationGroup::create([
            'name' => 'salle-a',
            'is_physical' => true,
            'is_active' => true,
        ]);
        $printer = Printer::create([
            'cups_name' => 'imp_orph',
            'orphan' => false,
            'created_by_user_id' => $user->id,
        ]);
        $printer->workstationGroups()->attach($group->id, [
            'attached_at' => now(),
            'attached_by_user_id' => $user->id,
        ]);

        // CUPS est healthy mais retourne 0 imprimantes (CUPS vide ≠ CUPS down).
        $this->bindFakeCupsService([]);

        Artisan::call('printers:sync');

        $printer->refresh();
        $this->assertTrue($printer->orphan, "L'imprimante absente de CUPS doit être marquée orphan");
        $this->assertCount(1, $printer->workstationGroups, 'Les rattachements doivent être préservés');
    }

    #[Test]
    public function restores_orphan_to_non_orphan_when_cups_reintroduces_printer(): void
    {
        Printer::create([
            'cups_name' => 'imp_back',
            'orphan' => true,
        ]);

        $this->bindFakeCupsService([
            ['name' => 'imp_back', 'uri' => 'socket://192.0.2.10:9100'],
        ]);

        Artisan::call('printers:sync');

        $printer = Printer::find('imp_back');
        $this->assertNotNull($printer);
        $this->assertFalse($printer->orphan);
    }

    #[Test]
    public function command_is_idempotent_on_aligned_state(): void
    {
        Printer::create(['cups_name' => 'imp_idem', 'orphan' => false]);

        $this->bindFakeCupsService([
            ['name' => 'imp_idem', 'uri' => 'socket://192.0.2.10:9100'],
        ]);

        $beforeUpdated = Printer::find('imp_idem')->updated_at;
        Artisan::call('printers:sync');
        Artisan::call('printers:sync');

        $printer = Printer::find('imp_idem');
        $this->assertSame(1, Printer::count());
        $this->assertFalse($printer->orphan);
        $this->assertEquals($beforeUpdated->timestamp, $printer->updated_at->timestamp);
    }

    #[Test]
    public function reports_full_diff_in_output(): void
    {
        Printer::create(['cups_name' => 'imp_keep', 'orphan' => false]);
        Printer::create(['cups_name' => 'imp_orph', 'orphan' => false]);
        Printer::create(['cups_name' => 'imp_back', 'orphan' => true]);

        $this->bindFakeCupsService([
            ['name' => 'imp_keep', 'uri' => 'socket://192.0.2.10:9100'],
            ['name' => 'imp_back', 'uri' => 'socket://192.0.2.11:9100'],
            ['name' => 'imp_new', 'uri' => 'socket://192.0.2.12:9100'],
        ]);

        Artisan::call('printers:sync');
        $output = Artisan::output();

        $this->assertStringContainsString('ajoutées : 1', $output);
        $this->assertStringContainsString('marquées orphan : 1', $output);
        $this->assertStringContainsString('restaurées : 1', $output);

        $this->assertTrue(Printer::find('imp_orph')->orphan);
        $this->assertFalse(Printer::find('imp_back')->orphan);
        $this->assertNotNull(Printer::find('imp_new'));
    }

    #[Test]
    public function sync_skips_orphan_marking_when_cups_daemon_is_down(): void
    {
        // SER contient 1 imprimante non-orphan.
        Printer::create(['cups_name' => 'imp_safe', 'orphan' => false]);

        // CUPS est DOWN : lpstat -r échoue.
        $runner = new FakeCommandRunner();
        $runner->whenContains('lpstat -r', '', 1, 'Connection refused');
        $runner->setDefault(1, '');

        $service = new CupsPrinterService($runner);
        $this->app->instance(CupsPrinterService::class, $service);

        $code = Artisan::call('printers:sync');

        // La commande doit retourner FAILURE (non zéro) et ne pas marquer l'imprimante orphan.
        $this->assertSame(1, $code);
        $printer = Printer::find('imp_safe');
        $this->assertFalse($printer->orphan, 'CUPS down → aucun row SER ne doit être marqué orphan');
    }
}
