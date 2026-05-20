<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Printer;
use App\Models\PrinterDriver;
use App\Models\User;
use App\Observers\WorkstationGroupObserver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;
use Tests\Traits\CreatesPrinterDriversSchema;
use Tests\Traits\CreatesPrintersSchema;

/**
 * Story 6.2 — Tests Unit du modèle App\Models\PrinterDriver.
 *
 * Couvre :
 *  - PK composite (`primaryKey = null`, `incrementing = false`) + helper
 *    `findByKey()`.
 *  - Scopes `nonOrphan()` / `orphans()` / `forArchitecture()` / `bySource()`.
 *  - Relation `printer()` BelongsTo via `printer_cups_name`.
 *  - Relation `createdBy()` BelongsTo via `created_by_user_id`.
 */
class PrinterDriverTest extends TestCase
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

    #[Test]
    public function composite_key_lookup_returns_correct_row(): void
    {
        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'imp1',
            'architecture' => 'x64',
            'driver_name' => 'Generic / Generic PostScript Printer',
            'source' => 'upload-w10',
            'orphan' => false,
        ]);

        $found = PrinterDriver::findByKey('imp1', 'x64');
        $this->assertNotNull($found);
        $this->assertSame('Generic / Generic PostScript Printer', $found->driver_name);

        $notFound = PrinterDriver::findByKey('imp_nope', 'x64');
        $this->assertNull($notFound);
    }

    #[Test]
    public function model_metadata_reflects_composite_pk_configuration(): void
    {
        $model = new PrinterDriver();
        $this->assertFalse($model->getIncrementing(), 'PK composite — pas d\'autoincrement.');
        $this->assertNull($model->getKeyName(), 'PK composite — pas de scalar key name.');
        $this->assertSame('printer_drivers', $model->getTable());
    }

    #[Test]
    public function scope_non_orphan_filters_correctly(): void
    {
        Printer::create(['cups_name' => 'impa', 'orphan' => false]);
        Printer::create(['cups_name' => 'impb', 'orphan' => false]);

        PrinterDriver::create([
            'printer_cups_name' => 'impa',
            'architecture' => 'x64',
            'driver_name' => 'Driver Live',
            'source' => 'synced',
            'orphan' => false,
        ]);
        PrinterDriver::create([
            'printer_cups_name' => 'impb',
            'architecture' => 'x64',
            'driver_name' => 'Driver Orphan',
            'source' => 'synced',
            'orphan' => true,
        ]);

        $names = PrinterDriver::nonOrphan()->pluck('driver_name')->all();
        $this->assertContains('Driver Live', $names);
        $this->assertNotContains('Driver Orphan', $names);
    }

    #[Test]
    public function scope_orphans_filters_correctly(): void
    {
        Printer::create(['cups_name' => 'impc', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'impc',
            'architecture' => 'x64',
            'driver_name' => 'Driver Live',
            'source' => 'synced',
            'orphan' => false,
        ]);
        Printer::create(['cups_name' => 'impd', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'impd',
            'architecture' => 'x64',
            'driver_name' => 'Driver Lost',
            'source' => 'synced',
            'orphan' => true,
        ]);

        $names = PrinterDriver::orphans()->pluck('driver_name')->all();
        $this->assertContains('Driver Lost', $names);
        $this->assertNotContains('Driver Live', $names);
    }

    #[Test]
    public function scope_for_architecture_filters_correctly(): void
    {
        // Fix #16 — scopeForArchitecture est défini mais n'avait aucun
        // test. D5 6.2 ne supporte que x64 en pratique, mais le scope
        // doit fonctionner pour le futur 6.2bis.
        Printer::create(['cups_name' => 'imparch', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'imparch',
            'architecture' => 'x64',
            'driver_name' => 'Driver x64',
            'source' => 'synced',
            'orphan' => false,
        ]);

        $x64 = PrinterDriver::forArchitecture('x64')->pluck('driver_name')->all();
        $this->assertContains('Driver x64', $x64);

        $x86 = PrinterDriver::forArchitecture('x86')->pluck('driver_name')->all();
        $this->assertNotContains('Driver x64', $x86);
    }

    #[Test]
    public function scope_by_source_filters_correctly(): void
    {
        Printer::create(['cups_name' => 'impe', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'impe',
            'architecture' => 'x64',
            'driver_name' => 'Uploaded Driver',
            'source' => 'upload-w10',
            'orphan' => false,
        ]);
        Printer::create(['cups_name' => 'impf', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'impf',
            'architecture' => 'x64',
            'driver_name' => 'Synced Driver',
            'source' => 'synced',
            'orphan' => false,
        ]);

        $uploaded = PrinterDriver::bySource('upload-w10')->pluck('driver_name')->all();
        $this->assertContains('Uploaded Driver', $uploaded);
        $this->assertNotContains('Synced Driver', $uploaded);
    }

    #[Test]
    public function printer_relation_returns_associated_printer(): void
    {
        Printer::create(['cups_name' => 'imprel', 'orphan' => false]);
        $drv = PrinterDriver::create([
            'printer_cups_name' => 'imprel',
            'architecture' => 'x64',
            'driver_name' => 'Test Driver',
            'source' => 'upload-w10',
            'orphan' => false,
        ]);

        $printer = $drv->printer;
        $this->assertNotNull($printer);
        $this->assertSame('imprel', $printer->cups_name);
    }

    #[Test]
    public function created_by_relation_returns_user(): void
    {
        $user = User::create(['login' => 'creator', 'role' => 'admin', 'is_active' => true]);
        Printer::create(['cups_name' => 'impcr', 'orphan' => false]);
        $drv = PrinterDriver::create([
            'printer_cups_name' => 'impcr',
            'architecture' => 'x64',
            'driver_name' => 'Created Driver',
            'source' => 'upload-w10',
            'orphan' => false,
            'created_by_user_id' => $user->id,
        ]);

        $this->assertNotNull($drv->createdBy);
        $this->assertSame('creator', $drv->createdBy->login);
    }

    #[Test]
    public function printer_drivers_relation_in_printer_model_returns_drivers(): void
    {
        Printer::create(['cups_name' => 'imphas', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'imphas',
            'architecture' => 'x64',
            'driver_name' => 'Driver A',
            'source' => 'synced',
            'orphan' => false,
        ]);

        $printer = Printer::find('imphas');
        $this->assertCount(1, $printer->drivers);
        $this->assertSame('Driver A', $printer->drivers->first()->driver_name);
    }
}
