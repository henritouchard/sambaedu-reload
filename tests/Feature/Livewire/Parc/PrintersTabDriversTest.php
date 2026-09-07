<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Printer;
use App\Models\PrinterDriver;
use App\Models\User;
use App\Observers\WorkstationGroupObserver;
use App\Services\Print\CupsPrinterService;
use App\Services\Print\Exceptions\SambaUnavailableException;
use App\Services\Print\PrintDriverService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;
use Tests\Traits\CreatesPrinterDriversSchema;
use Tests\Traits\CreatesPrintersSchema;

/**
 * Tests Feature Livewire de l'onglet Imprimantes avec la
 * section Drivers Windows dans la modale édit.
 *
 * Couvre (upload happy-path / pivot down) (detach),
 * (delete protection) (Samba unavailable) (gate forgé).
 */
class PrintersTabDriversTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;
    use CreatesPrintersSchema;
    use CreatesPrinterDriversSchema;

    private string $component = 'pages::parc._partials.printers-tab';

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
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
        Mockery::close();
        parent::tearDown();
    }

    private function makeAdmin(string $login = 'admin'): User
    {
        $u = User::create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'autre', 'is_active' => true]);
    }

    private function bindCupsMock(array $cupsPrinters = [], array $drivers = []): \Mockery\MockInterface
    {
        $mock = Mockery::mock(CupsPrinterService::class);
        $rows = array_map(fn(array $p) => [
            'name' => $p['name'],
            'uri' => $p['uri'] ?? 'socket://192.0.2.10:9100',
            'state' => $p['state'] ?? 'idle',
            'description' => $p['description'] ?? null,
            'location' => $p['location'] ?? null,
            'model' => $p['model'] ?? null,
            'jobs_count' => $p['jobs_count'] ?? 0,
        ], $cupsPrinters);

        $mock->shouldReceive('listPrinters')->andReturn($rows)->byDefault();
        $mock->shouldReceive('listAvailableDrivers')->andReturn($drivers)->byDefault();
        $this->app->instance(CupsPrinterService::class, $mock);
        return $mock;
    }

    private function bindDriverMock(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(PrintDriverService::class);
        // valeurs par défaut souples — les tests précisent leurs attentes
        $mock->shouldReceive('listDriversForPrinter')->andReturn(['samba' => null, 'ser' => []])->byDefault();
        $mock->shouldReceive('unlinkDriverFiles')->andReturn(['removed' => [], 'failed' => []])->byDefault();
        $this->app->instance(PrintDriverService::class, $mock);
        return $mock;
    }

    #[Test]
    public function drivers_section_visible_for_admin_in_edit_modal(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([
            ['name' => 'imp1', 'uri' => 'socket://192.0.2.10:9100', 'state' => 'idle'],
        ]);
        $driverMock = $this->bindDriverMock();
        $driverMock->shouldReceive('listDriversForPrinter')
            ->with('imp1')
            ->andReturn([
                'samba' => ['smb_name' => 'imp1', 'smb_driver' => 'Generic / Generic PostScript Printer', 'smb_comment' => ''],
                'ser' => [
                    [
                        'driver_name' => 'Generic / Generic PostScript Printer',
                        'architecture' => 'x64',
                        'source' => 'upload-w10',
                        'orphan' => false,
                        'notes' => null,
                        'created_at' => null,
                        'created_by_user_id' => null,
                    ],
                ],
            ]);

        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);

        Livewire::test($this->component)
            ->call('openEditModal', 'imp1')
            ->assertSet('showEditModal', true)
            ->assertSet('sambaAvailable', true)
            ->assertSee('Generic / Generic PostScript Printer');
    }

    #[Test]
    public function drivers_section_masked_for_lambda_user(): void
    {
        $lambda = $this->makeUser('lambda');
        $this->actingAs($lambda);

        $this->bindCupsMock([['name' => 'imp1', 'uri' => 'socket://192.0.2.10:9100']]);
        $this->bindDriverMock();

        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);

        Livewire::test($this->component)
            ->call('openEditModal', 'imp1')
            // lambda → toastAccessDenied + showEditModal reste false
            ->assertSet('showEditModal', false);
    }

    #[Test]
    public function samba_unavailable_shows_banner_and_disables_actions(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([['name' => 'imp1', 'uri' => 'socket://192.0.2.10:9100']]);
        $driverMock = $this->bindDriverMock();
        $driverMock->shouldReceive('listDriversForPrinter')
            ->andThrow(new SambaUnavailableException('Samba down test'));

        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);

        Livewire::test($this->component)
            ->call('openEditModal', 'imp1')
            ->assertSet('sambaAvailable', false)
            ->assertSet('showEditModal', true);
    }

    #[Test]
    public function upload_driver_happy_path_calls_service_in_order_and_inserts_ser_row(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([['name' => 'imp1', 'uri' => 'socket://192.0.2.10:9100']]);
        $driverMock = $this->bindDriverMock();
        $driverMock->shouldReceive('listDriversForPrinter')->andReturn(['samba' => null, 'ser' => []]);
        $driverMock->shouldReceive('getDriverDefinition')
            ->once()
            ->with('w10pivot', 'Generic PS')
            ->andReturn([
                'Driver Name' => 'Generic / Generic PostScript Printer',
                'Driver Path' => 'pscript5.dll',
                'Datafile' => 'PSCRIPT.PPD',
                'Configfile' => 'ps5ui.dll',
                'Helpfile' => 'pscript.hlp',
                'Dependentfiles' => ['ps5ui.dll', 'pscript5.dll', 'PSCRIPT.PPD'],
                'Architecture' => 'Windows x64',
            ]);
        $driverMock->shouldReceive('copyDriverFile')->atLeast()->once()->andReturn(true);
        $driverMock->shouldReceive('registerDriver')->once()->andReturn(true);
        $driverMock->shouldReceive('attachDriverToPrinter')
            ->once()
            ->with('imp1', 'Generic / Generic PostScript Printer')
            ->andReturn(true);

        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);

        Livewire::test($this->component)
            ->call('openEditModal', 'imp1')
            ->set('newDriverPivot', 'w10pivot')
            ->set('newDriverName', 'Generic PS')
            ->set('newDriverDisplayName', 'Driver salle A')
            ->call('uploadDriver')
            ->assertHasNoErrors()
            ->assertDispatched('toastMagic');

        $drv = PrinterDriver::findByKey('imp1', 'x64');
        $this->assertNotNull($drv);
        $this->assertSame('Generic / Generic PostScript Printer', $drv->driver_name);
        $this->assertSame('upload-w10', $drv->source);
        $this->assertSame('Driver salle A', $drv->notes);
        $this->assertSame($admin->id, $drv->created_by_user_id);
    }

    #[Test]
    public function upload_driver_pivot_unreachable_shows_toast_and_no_db_write(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([['name' => 'imp1', 'uri' => 'socket://192.0.2.10:9100']]);
        $driverMock = $this->bindDriverMock();
        $driverMock->shouldReceive('listDriversForPrinter')->andReturn(['samba' => null, 'ser' => []]);
        $driverMock->shouldReceive('getDriverDefinition')
            ->andThrow(new \App\Services\Print\Exceptions\WindowsPivotUnreachableException(
                'pivot down',
                'rpcclient ...',
                ['NT_STATUS_HOST_UNREACHABLE'],
                1,
            ));

        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);

        Livewire::test($this->component)
            ->call('openEditModal', 'imp1')
            ->set('newDriverPivot', 'w10pivot')
            ->set('newDriverName', 'Generic PS')
            ->call('uploadDriver')
            ->assertDispatched('toastMagic');

        $this->assertSame(0, PrinterDriver::count(), 'Aucune ligne SER ne doit avoir été insérée');
    }

    #[Test]
    public function upload_driver_pivot_name_forge_returns_validation_error(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([['name' => 'imp1', 'uri' => 'socket://192.0.2.10:9100']]);
        $this->bindDriverMock();

        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);

        Livewire::test($this->component)
            ->call('openEditModal', 'imp1')
            ->set('newDriverPivot', '; rm -rf /')
            ->set('newDriverName', 'Generic PS')
            ->call('uploadDriver')
            ->assertHasErrors('newDriverPivot');

        $this->assertSame(0, PrinterDriver::count());
    }

    #[Test]
    public function detach_driver_calls_service_and_deletes_ser_row(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([['name' => 'impd', 'uri' => 'socket://192.0.2.10:9100']]);
        $driverMock = $this->bindDriverMock();
        $driverMock->shouldReceive('listDriversForPrinter')->andReturn(['samba' => null, 'ser' => []]);
        $driverMock->shouldReceive('detachDriverFromPrinter')
            ->once()
            ->with('impd')
            ->andReturn(true);

        Printer::create(['cups_name' => 'impd', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'impd',
            'architecture' => 'x64',
            'driver_name' => 'Driver to detach',
            'source' => 'upload-w10',
            'orphan' => false,
        ]);

        Livewire::test($this->component)
            ->call('openEditModal', 'impd')
            ->call('detachDriver', 'Driver to detach', 'x64')
            ->assertDispatched('toastMagic');

        $this->assertNull(PrinterDriver::findByKey('impd', 'x64'));
    }

    #[Test]
    public function delete_driver_rejects_if_printer_attachments_exist(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([['name' => 'impatt', 'uri' => 'socket://192.0.2.10:9100']]);
        $driverMock = $this->bindDriverMock();
        $driverMock->shouldReceive('listDriversForPrinter')->andReturn(['samba' => null, 'ser' => []]);
        // deleteDriver côté Service ne doit PAS être appelé (refus côté Livewire).
        $driverMock->shouldNotReceive('deleteDriver');

        Printer::create(['cups_name' => 'impatt', 'orphan' => false]);
        PrinterDriver::create([
            'printer_cups_name' => 'impatt',
            'architecture' => 'x64',
            'driver_name' => 'Still Attached',
            'source' => 'upload-w10',
            'orphan' => false,
        ]);

        Livewire::test($this->component)
            ->call('openEditModal', 'impatt')
            ->call('deleteDriver', 'Still Attached', 'x64')
            ->assertDispatched('toastMagic');

        // La ligne SER doit toujours exister : supprimer un driver encore
        // rattaché est refusé.
        $this->assertNotNull(PrinterDriver::findByKey('impatt', 'x64'));
    }

    #[Test]
    public function retry_attach_driver_inserts_ser_and_calls_attach(): void
    {
        // Q3A — quand `$pendingAttachDriver` est posé suite à un upload
        // partiel, l'admin clique « Réessayer association ». L'action
        // doit INSERT la ligne SER + appeler `attachDriverToPrinter`.
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([['name' => 'impretry', 'uri' => 'socket://192.0.2.10:9100']]);
        $driverMock = $this->bindDriverMock();
        $driverMock->shouldReceive('listDriversForPrinter')->andReturn(['samba' => null, 'ser' => []]);
        $driverMock->shouldReceive('attachDriverToPrinter')
            ->once()
            ->with('impretry', 'Stuck Driver')
            ->andReturn(true);

        Printer::create(['cups_name' => 'impretry', 'orphan' => false]);

        Livewire::test($this->component)
            ->call('openEditModal', 'impretry')
            ->set('pendingAttachDriver', [
                'driver_name' => 'Stuck Driver',
                'display_name' => 'Driver bureau',
            ])
            ->call('retryAttachDriver')
            ->assertSet('pendingAttachDriver', null)
            ->assertDispatched('toastMagic');

        $drv = PrinterDriver::findByKey('impretry', 'x64');
        $this->assertNotNull($drv);
        $this->assertSame('Stuck Driver', $drv->driver_name);
        $this->assertSame('upload-w10', $drv->source);
        $this->assertSame('Driver bureau', $drv->notes);
    }

    #[Test]
    public function delete_driver_happy_path_calls_service_when_no_attachments_exist(): void
    {
        // Un delete driver SANS rattachement appelle bien le service
        // `deleteDriver` (le cas avec rattachement est refusé, couvert à part).
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([['name' => 'impdel', 'uri' => 'socket://192.0.2.10:9100']]);
        $driverMock = $this->bindDriverMock();
        $driverMock->shouldReceive('listDriversForPrinter')->andReturn(['samba' => null, 'ser' => []]);
        $driverMock->shouldReceive('getDriverDefinitionFromSe4fs')
            ->andReturn([
                'Driver Name' => 'Removable Driver',
                'Driver Path' => 'rem.dll',
                'Datafile' => 'rem.ppd',
                'Configfile' => 'rem-ui.dll',
                'Helpfile' => 'NULL',
                'Dependentfiles' => [],
                'Architecture' => 'Windows x64',
            ]);
        $driverMock->shouldReceive('deleteDriver')
            ->once()
            ->with('Removable Driver', 'x64', \Mockery::type('array'))
            ->andReturn(true);

        Printer::create(['cups_name' => 'impdel', 'orphan' => false]);
        // Pas de PrinterDriver pour `Removable Driver` → rien ne protège sa suppression.

        Livewire::test($this->component)
            ->call('openEditModal', 'impdel')
            ->call('deleteDriver', 'Removable Driver', 'x64')
            ->assertDispatched('toastMagic');
    }

    #[Test]
    public function upload_driver_samba_down_shows_toast_and_no_db_write(): void
    {
        // Si Samba tombe en plein upload (e.g.
        // après getDriverDefinition réussi, registerDriver lève
        // SambaUnavailableException), le toast s'affiche et aucune ligne
        // SER ne doit avoir été créée.
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([['name' => 'impsd', 'uri' => 'socket://192.0.2.10:9100']]);
        $driverMock = $this->bindDriverMock();
        $driverMock->shouldReceive('listDriversForPrinter')->andReturn(['samba' => null, 'ser' => []]);
        $driverMock->shouldReceive('getDriverDefinition')
            ->andReturn([
                'Driver Name' => 'Generic / PS',
                'Driver Path' => 'pscript5.dll',
                'Datafile' => 'PSCRIPT.PPD',
                'Configfile' => 'ps5ui.dll',
                'Helpfile' => 'NULL',
                'Dependentfiles' => [],
                'Architecture' => 'Windows x64',
            ]);
        $driverMock->shouldReceive('copyDriverFile')->andReturn(true);
        $driverMock->shouldReceive('registerDriver')
            ->andThrow(new SambaUnavailableException('Samba daemon down'));
        $driverMock->shouldReceive('unlinkDriverFiles')->andReturn(['removed' => [], 'failed' => []]);

        Printer::create(['cups_name' => 'impsd', 'orphan' => false]);

        Livewire::test($this->component)
            ->call('openEditModal', 'impsd')
            ->set('newDriverPivot', 'w10pivot')
            ->set('newDriverName', 'Generic PS')
            ->call('uploadDriver')
            ->assertDispatched('toastMagic');

        $this->assertSame(0, PrinterDriver::count(), 'Aucune ligne SER ne doit avoir été insérée');
    }

    #[Test]
    public function gate_forged_upload_driver_returns_403(): void
    {
        $lambda = $this->makeUser('lambda');
        $this->actingAs($lambda);

        $this->bindCupsMock([['name' => 'imp1', 'uri' => 'socket://192.0.2.10:9100']]);
        $this->bindDriverMock();

        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);

        Livewire::test($this->component)
            ->set('editingCupsName', 'imp1') // forge directe
            ->set('newDriverPivot', 'w10pivot')
            ->set('newDriverName', 'Forged Driver')
            ->call('uploadDriver')
            ->assertStatus(403);

        $this->assertSame(0, PrinterDriver::count());
    }
}
