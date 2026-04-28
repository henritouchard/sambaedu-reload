<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Printer;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\PermissionService;
use App\Services\Print\CupsPrinterService;
use App\Services\Print\Exceptions\CupsCommandException;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;
use Tests\Traits\CreatesPrintersSchema;

/**
 * Story 6.1 — Tests Feature Livewire de l'onglet Imprimantes /parc?tab=printers.
 *
 * Couvre AC1 (liste), AC2 (ajout + insertion SER + pivot + rollback SER fix #5),
 * AC3 (edit pre-fill), AC4 (delete cascade), AC5 (erreur toast), AC6 (admin filtres),
 * AC7 (lambda scopé), AC8 (gate forgé).
 *
 * Fix #19 : les tests toggle mockent également `getPrinter()` (état live CUPS).
 */
class PrintersTabTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;
    use CreatesPrintersSchema;

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
        (new PermissionSeeder())->run();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
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

    private function makeGroup(string $name): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => $name,
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array{name:string,uri?:string,state?:string,description?:?string,location?:?string,model?:?string,jobs_count?:int}>  $cupsPrinters
     */
    private function bindCupsMock(array $cupsPrinters = [], array $drivers = [], array $extra = []): \Mockery\MockInterface
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

        foreach ($extra as $method => $callback) {
            $expect = $mock->shouldReceive($method);
            if (is_callable($callback)) {
                $callback($expect);
            }
        }

        $this->app->instance(CupsPrinterService::class, $mock);
        return $mock;
    }

    // ========================================================================
    // AC1 — Listing depuis le service
    // ========================================================================

    #[Test]
    public function it_lists_printers_from_cups_service_for_admin(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([
            ['name' => 'imp1', 'uri' => 'socket://192.0.2.10:9100', 'state' => 'idle'],
            ['name' => 'imp2', 'uri' => 'socket://192.0.2.11:9100', 'state' => 'printing'],
        ]);

        Printer::create(['cups_name' => 'imp1', 'orphan' => false]);
        Printer::create(['cups_name' => 'imp2', 'orphan' => false]);

        Livewire::test($this->component)
            ->assertSet('cupsAvailable', true)
            ->assertSee('imp1')
            ->assertSee('imp2');
    }

    #[Test]
    public function it_shows_warning_when_cups_is_unreachable(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $mock = Mockery::mock(CupsPrinterService::class);
        $mock->shouldReceive('listPrinters')->andThrow(new \RuntimeException('CUPS down'));
        $mock->shouldReceive('listAvailableDrivers')->andReturn([]);
        $this->app->instance(CupsPrinterService::class, $mock);

        Livewire::test($this->component)
            ->assertSet('cupsAvailable', false);
    }

    // ========================================================================
    // AC7 — Lambda voit uniquement ses parcs (filtrage scopé)
    // ========================================================================

    #[Test]
    public function lambda_user_sees_only_printers_attached_to_their_groups(): void
    {
        $delegate = $this->makeUser('delegate');
        $myGroup = $this->makeGroup('salle_a');
        $otherGroup = $this->makeGroup('salle_b');

        app(PermissionService::class)->grantDelegation($delegate, 'server.admin', $myGroup);
        $this->actingAs($delegate);

        $this->bindCupsMock([
            ['name' => 'imp_mine', 'uri' => 'socket://192.0.2.10:9100'],
            ['name' => 'imp_other', 'uri' => 'socket://192.0.2.11:9100'],
            ['name' => 'imp_orph', 'uri' => 'socket://192.0.2.12:9100'],
        ]);

        $mine = Printer::create(['cups_name' => 'imp_mine', 'orphan' => false]);
        $mine->workstationGroups()->attach($myGroup->id, [
            'attached_at' => now(),
            'attached_by_user_id' => $delegate->id,
        ]);

        $other = Printer::create(['cups_name' => 'imp_other', 'orphan' => false]);
        $other->workstationGroups()->attach($otherGroup->id, [
            'attached_at' => now(),
            'attached_by_user_id' => $delegate->id,
        ]);

        Printer::create(['cups_name' => 'imp_orph', 'orphan' => false]);

        $component = Livewire::test($this->component);
        $component->assertSee('imp_mine');
        $component->assertDontSee('imp_other');
        $component->assertDontSee('imp_orph');
    }

    // ========================================================================
    // AC2 — Ajout via modale (CUPS-first + insert SER + pivot)
    // ========================================================================

    #[Test]
    public function add_printer_creates_cups_then_ser_row_with_pivot(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $group = $this->makeGroup('salle_z');

        $mock = $this->bindCupsMock();
        $mock->shouldReceive('addPrinter')
            ->once()
            ->with('imp_new', 'socket://192.0.2.99:9100', 'desc', 'salle Z', null)
            ->andReturn(true);

        Livewire::test($this->component)
            ->set('newName', 'imp_new')
            ->set('newUri', 'socket://192.0.2.99:9100')
            ->set('newDescription', 'desc')
            ->set('newLocation', 'salle Z')
            ->set('newWorkstationGroupIds', [$group->id])
            ->call('addPrinter')
            ->assertHasNoErrors()
            ->assertDispatched('toastMagic')
            ->assertSet('showAddModal', false);

        $printer = Printer::find('imp_new');
        $this->assertNotNull($printer);
        $this->assertSame($admin->id, $printer->created_by_user_id);
        $this->assertFalse($printer->orphan);
        $this->assertCount(1, $printer->workstationGroups);
        $this->assertSame($group->id, $printer->workstationGroups->first()->id);
    }

    #[Test]
    public function add_printer_rolls_back_when_cups_fails(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $mock = $this->bindCupsMock();
        $mock->shouldReceive('addPrinter')
            ->andThrow(new CupsCommandException(
                'lpadmin failed',
                'sudo lpadmin -p imp_fail',
                ['Permission denied'],
                1,
            ));

        Livewire::test($this->component)
            ->set('newName', 'imp_fail')
            ->set('newUri', 'socket://192.0.2.50:9100')
            ->call('addPrinter')
            ->assertDispatched('toastMagic');

        $this->assertNull(Printer::find('imp_fail'), 'Aucune row SER ne doit être créée si CUPS échoue');
    }

    #[Test]
    public function add_printer_rolls_back_cups_when_ser_fails(): void
    {
        // Fix #5 : quand l'INSERT SER échoue après que CUPS a réussi,
        // on doit appeler deletePrinter() pour rollback CUPS.
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $mock = $this->bindCupsMock();
        $mock->shouldReceive('addPrinter')
            ->once()
            ->with('imp_rb', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(true);
        // Le rollback CUPS doit être appelé car le SER insert va échouer (duplicate).
        $mock->shouldReceive('deletePrinter')
            ->once()
            ->with('imp_rb')
            ->andReturn(true);

        // Créer une row SER pré-existante pour déclencher l'erreur de contrainte unique.
        Printer::create(['cups_name' => 'imp_rb', 'orphan' => false]);

        Livewire::test($this->component)
            ->set('newName', 'imp_rb')
            ->set('newUri', 'socket://192.0.2.90:9100')
            ->call('addPrinter')
            ->assertDispatched('toastMagic'); // toast d'erreur générique (fix #1)

        // La row pré-existante doit toujours être là (non altérée par le rollback CUPS).
        $this->assertNotNull(Printer::find('imp_rb'));
    }

    #[Test]
    public function add_printer_validates_name_regex(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock();

        Livewire::test($this->component)
            ->set('newName', '; rm -rf /')
            ->set('newUri', 'socket://192.0.2.50:9100')
            ->call('addPrinter')
            ->assertHasErrors('newName');

        $this->assertSame(0, Printer::count());
    }

    // ========================================================================
    // AC3 — Edit pre-fill
    // ========================================================================

    #[Test]
    public function open_edit_modal_pre_fills_existing_config_and_attachments(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $group = $this->makeGroup('salle_y');

        $this->bindCupsMock([
            ['name' => 'imp_edit', 'uri' => 'socket://192.0.2.20:9100', 'description' => 'D-CUPS', 'location' => 'L-CUPS'],
        ]);

        $printer = Printer::create([
            'cups_name' => 'imp_edit',
            'orphan' => false,
            'description_ser' => 'D-SER',
        ]);
        $printer->workstationGroups()->attach($group->id, [
            'attached_at' => now(),
            'attached_by_user_id' => $admin->id,
        ]);

        Livewire::test($this->component)
            ->call('openEditModal', 'imp_edit')
            ->assertSet('editingCupsName', 'imp_edit')
            ->assertSet('editUri', 'socket://192.0.2.20:9100')
            ->assertSet('editDescription', 'D-CUPS')
            ->assertSet('editLocation', 'L-CUPS')
            ->assertSet('editDescriptionSer', 'D-SER')
            ->assertSet('editWorkstationGroupIds', [$group->id])
            ->assertSet('showEditModal', true);
    }

    // ========================================================================
    // AC4 — Delete cascade SER + pivot
    // ========================================================================

    #[Test]
    public function delete_printer_removes_cups_and_ser_row(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $group = $this->makeGroup('salle_x');

        $mock = $this->bindCupsMock([
            ['name' => 'imp_del'],
        ]);
        $mock->shouldReceive('deletePrinter')->once()->with('imp_del')->andReturn(true);

        $printer = Printer::create(['cups_name' => 'imp_del', 'orphan' => false]);
        $printer->workstationGroups()->attach($group->id, [
            'attached_at' => now(),
            'attached_by_user_id' => $admin->id,
        ]);

        Livewire::test($this->component)
            ->call('deletePrinter', 'imp_del')
            ->assertDispatched('toastMagic');

        $this->assertNull(Printer::find('imp_del'), 'La row SER doit être supprimée');
    }

    // ========================================================================
    // AC6 — Toggle enable/disable (fix #19 : getPrinter pour état live)
    // ========================================================================

    #[Test]
    public function toggle_printer_state_calls_disable_when_idle(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $mock = $this->bindCupsMock([
            ['name' => 'imp_t', 'state' => 'idle'],
        ]);
        // Fix #19 : getPrinter() est appelé pour refetch l'état live CUPS.
        $mock->shouldReceive('getPrinter')->with('imp_t')->andReturn([
            'name' => 'imp_t', 'uri' => 'socket://192.0.2.10:9100',
            'state' => 'idle', 'description' => null, 'location' => null,
            'model' => null, 'jobs_count' => 0,
        ]);
        $mock->shouldReceive('disablePrinter')->once()->with('imp_t')->andReturn(true);

        Printer::create(['cups_name' => 'imp_t', 'orphan' => false]);

        Livewire::test($this->component)
            ->call('togglePrinterState', 'imp_t')
            ->assertDispatched('toastMagic');
    }

    #[Test]
    public function toggle_printer_state_calls_enable_when_disabled(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $mock = $this->bindCupsMock([
            ['name' => 'imp_t', 'state' => 'disabled'],
        ]);
        // Fix #19 : getPrinter() refetch l'état live.
        $mock->shouldReceive('getPrinter')->with('imp_t')->andReturn([
            'name' => 'imp_t', 'uri' => 'socket://192.0.2.10:9100',
            'state' => 'disabled', 'description' => null, 'location' => null,
            'model' => null, 'jobs_count' => 0,
        ]);
        $mock->shouldReceive('enablePrinter')->once()->with('imp_t')->andReturn(true);

        Printer::create(['cups_name' => 'imp_t', 'orphan' => false]);

        Livewire::test($this->component)
            ->call('togglePrinterState', 'imp_t')
            ->assertDispatched('toastMagic');
    }

    // ========================================================================
    // AC8 — Gate forgé
    // ========================================================================

    #[Test]
    public function lambda_without_delegation_cannot_force_add_printer(): void
    {
        $lambda = $this->makeUser('lambda');
        $this->actingAs($lambda);

        $this->bindCupsMock();

        Livewire::test($this->component)
            ->set('newName', 'imp_x')
            ->set('newUri', 'socket://192.0.2.10:9100')
            ->call('addPrinter')
            ->assertStatus(403);

        $this->assertSame(0, Printer::count());
    }

    #[Test]
    public function lambda_without_delegation_cannot_force_delete_printer(): void
    {
        $lambda = $this->makeUser('lambda');
        $this->actingAs($lambda);

        Printer::create(['cups_name' => 'imp_p', 'orphan' => false]);
        $this->bindCupsMock([['name' => 'imp_p']]);

        Livewire::test($this->component)
            ->call('deletePrinter', 'imp_p')
            ->assertStatus(403);

        $this->assertNotNull(Printer::find('imp_p'), 'L\'imprimante ne doit pas avoir été supprimée');
    }

    // ========================================================================
    // AC6 — Admin filtres orphans/unattached
    // ========================================================================

    #[Test]
    public function admin_can_filter_orphans(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([
            ['name' => 'imp_live'],
        ]);

        Printer::create(['cups_name' => 'imp_live', 'orphan' => false]);
        Printer::create(['cups_name' => 'imp_orph', 'orphan' => true]);

        $component = Livewire::test($this->component)
            ->set('filter', 'orphans');

        $printersData = $component->get('printers');
        $cupsNames = array_column($printersData, 'cups_name');
        $this->assertContains('imp_orph', $cupsNames);
        $this->assertNotContains('imp_live', $cupsNames);
    }

    #[Test]
    public function admin_sees_orphans_in_all_filter(): void
    {
        // Fix #6 (solution 2) : le filtre 'all' inclut les orphans pour les admins.
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->bindCupsMock([
            ['name' => 'imp_live'],
        ]);

        Printer::create(['cups_name' => 'imp_live', 'orphan' => false]);
        Printer::create(['cups_name' => 'imp_orph', 'orphan' => true]);

        $component = Livewire::test($this->component)
            ->set('filter', 'all');

        $printersData = $component->get('printers');
        $cupsNames = array_column($printersData, 'cups_name');
        $this->assertContains('imp_live', $cupsNames, 'Filtre all doit inclure les imprimantes normales');
        $this->assertContains('imp_orph', $cupsNames, 'Filtre all doit inclure les orphans (fix #6)');
    }
}
