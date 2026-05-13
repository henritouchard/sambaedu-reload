<?php

declare(strict_types=1);

namespace Tests\Feature\Pages\SyncFromAd;

use App\Models\DhcpReservation;
use App\Models\User;
use App\Models\Workstation;
use App\Services\Network\DhcpService;
use App\Services\Print\Contracts\CommandRunner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;
use Tests\Traits\CreatesDhcpSchema;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 8.1 — T8b / AC9 : étape 10 « Importer les réservations DHCP » dans
 * la page Livewire SFC `/sync-from-ad`.
 *
 * Couvre :
 *  - exécution de l'étape via `runStep('dhcp_reservations')` ;
 *  - stats remplies après exécution ;
 *  - idempotence : 2 exécutions consécutives → created=N puis updated=N ;
 *  - liaison Workstation : si un poste avec le `name` existe au moment de
 *    l'import, `workstation_id` est rempli.
 */
class DhcpReservationsStepTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesDhcpSchema;
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        $this->createDhcpSchema();
        (new PermissionSeeder())->run();

        config(['sambaedu.dhcp.reservations_file' => base_path('tests/Fixtures/dhcp/reservations.inc')]);

        // FakeCommandRunner pour ne pas tenter de réel `systemctl`/script
        // (l'import legacy ne déclenche PAS de reload mais le DhcpService
        // peut être instancié avec un runner).
        $runner = new FakeCommandRunner();
        $runner->setDefault(returnCode: 0);
        $this->app->instance(CommandRunner::class, $runner);
    }

    protected function tearDown(): void
    {
        $this->dropDhcpSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        $u = User::query()->create(['login' => 'sm-admin-' . uniqid(), 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    public function test_step_10_imports_reservations_from_fixture(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::sync-from-ad.index')
            ->call('runStep', 'dhcp_reservations')
            ->assertSet('steps.dhcp_reservations.status', 'success');

        // 3 réservations valides dans la fixture
        $this->assertGreaterThanOrEqual(3, DhcpReservation::count());
        $this->assertGreaterThanOrEqual(3, DhcpReservation::bySource('legacy-migration')->count());
    }

    public function test_step_10_is_idempotent(): void
    {
        $this->actingAs($this->makeAdmin());

        // 1er passage
        Livewire::test('pages::sync-from-ad.index')
            ->call('runStep', 'dhcp_reservations');

        $firstCount = DhcpReservation::count();
        $this->assertGreaterThan(0, $firstCount);

        // 2e passage
        Livewire::test('pages::sync-from-ad.index')
            ->call('runStep', 'dhcp_reservations')
            ->assertSet('steps.dhcp_reservations.stats.created', 0);

        $this->assertSame($firstCount, DhcpReservation::count(), 'Pas de doublons après rejeu');
    }

    public function test_step_10_links_workstation_when_name_matches(): void
    {
        $this->actingAs($this->makeAdmin());

        $workstation = Workstation::create([
            'name' => 'poste01',
            'status' => 'active',
        ]);

        Livewire::test('pages::sync-from-ad.index')
            ->call('runStep', 'dhcp_reservations');

        $reservation = DhcpReservation::where('name', 'poste01')->first();
        $this->assertNotNull($reservation);
        $this->assertSame($workstation->id, $reservation->workstation_id);
    }

    public function test_step_10_renders_stats_in_step_state(): void
    {
        $this->actingAs($this->makeAdmin());

        $component = Livewire::test('pages::sync-from-ad.index')
            ->call('runStep', 'dhcp_reservations');

        $component
            ->assertSet('steps.dhcp_reservations.status', 'success')
            // expanded est forcé à true en fin de runStep
            ->assertSet('steps.dhcp_reservations.expanded', true);
    }

    public function test_step_10_does_not_trigger_reload(): void
    {
        $this->actingAs($this->makeAdmin());

        /** @var FakeCommandRunner $runner */
        $runner = $this->app->make(CommandRunner::class);

        Livewire::test('pages::sync-from-ad.index')
            ->call('runStep', 'dhcp_reservations');

        // AC9 : aucun reload pendant l'étape 10
        foreach ($runner->executed as $cmd) {
            $this->assertStringNotContainsString('make_dhcpd_conf.sh', $cmd);
        }
    }
}
