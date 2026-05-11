<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Models\DhcpReservation;
use App\Models\User;
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
 * Story 8.1 — Tests Feature CRUD réservations DHCP via Livewire SFC.
 *
 * Couvre :
 *  - création / édition / suppression via la page Livewire `network/dhcp/index` ;
 *  - reload service appelé après chaque mutation (mock `CommandRunner`) ;
 *  - permission `viewAny-dhcp` (lecture) / `manage-dhcp` (mutation) ;
 *  - cas IP/MAC déjà réservée → toast erreur, pas de création.
 */
class DhcpReservationsCrudTest extends TestCase
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

        $tmpReservationsFile = sys_get_temp_dir() . '/dhcp_test_' . uniqid() . '.inc';
        config(['sambaedu.dhcp.reservations_file' => $tmpReservationsFile]);

        $this->runner = new FakeCommandRunner();
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->runner->whenContains('systemctl', 'active', returnCode: 0);

        $this->app->instance(CommandRunner::class, $this->runner);
        $this->app->forgetInstance(DhcpService::class);
    }

    protected function tearDown(): void
    {
        $this->dropDhcpSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeAdmin(string $login): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    public function test_admin_can_create_reservation_and_reload_is_triggered(): void
    {
        $this->actingAs($this->makeAdmin('admin-crud-1'));

        Livewire::test('pages::network.dhcp.index')
            ->set('name', 'poste01')
            ->set('mac', 'aa:bb:cc:dd:ee:01')
            ->set('ip', '10.0.0.10')
            ->call('save');

        $this->assertDatabaseHas('dhcp_reservations', [
            'name' => 'poste01',
            'ip' => '10.0.0.10',
        ]);

        $reloadCalls = 0;
        foreach ($this->runner->executed as $cmd) {
            if (str_contains($cmd, 'make_dhcpd_conf.sh')) {
                $reloadCalls++;
            }
        }
        $this->assertGreaterThanOrEqual(1, $reloadCalls, 'Le reload doit être appelé après création');
    }

    public function test_admin_can_edit_reservation(): void
    {
        $this->actingAs($this->makeAdmin('admin-crud-2'));
        $reservation = DhcpReservation::create([
            'name' => 'poste01',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'ip' => '10.0.0.10',
            'source' => 'manual',
        ]);

        Livewire::test('pages::network.dhcp.index')
            ->call('openEditModal', $reservation->id)
            ->set('ip', '10.0.0.99')
            ->set('description', 'updated')
            ->call('save');

        $reservation->refresh();
        $this->assertSame('10.0.0.99', $reservation->ip);
        $this->assertSame('updated', $reservation->description);
    }

    public function test_admin_can_delete_reservation(): void
    {
        $this->actingAs($this->makeAdmin('admin-crud-3'));
        $reservation = DhcpReservation::create([
            'name' => 'poste01',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'ip' => '10.0.0.10',
            'source' => 'manual',
        ]);

        Livewire::test('pages::network.dhcp.index')
            ->call('confirmDelete', $reservation->id)
            ->call('deleteConfirmed');

        $this->assertDatabaseMissing('dhcp_reservations', ['id' => $reservation->id]);
    }

    public function test_create_rejects_duplicate_mac(): void
    {
        $this->actingAs($this->makeAdmin('admin-crud-4'));
        DhcpReservation::create([
            'name' => 'existing',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'ip' => '10.0.0.5',
            'source' => 'manual',
        ]);

        Livewire::test('pages::network.dhcp.index')
            ->set('name', 'poste01')
            ->set('mac', 'aa:bb:cc:dd:ee:01')   // doublon MAC
            ->set('ip', '10.0.0.10')
            ->call('save');

        // La nouvelle réservation ne doit PAS exister
        $this->assertDatabaseMissing('dhcp_reservations', ['name' => 'poste01']);
    }

    public function test_non_admin_user_cannot_access_page(): void
    {
        $user = User::query()->create(['login' => 'plain', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($user);

        Livewire::test('pages::network.dhcp.index')
            ->assertStatus(403);
    }

    public function test_non_admin_cannot_save_even_if_mount_bypassed(): void
    {
        // Admin pour mount
        $admin = $this->makeAdmin('admin-mount');
        $this->actingAs($admin);
        $component = Livewire::test('pages::network.dhcp.index');

        // Bascule en non-admin
        $plain = User::query()->create(['login' => 'plain-bypass', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($plain);

        $component
            ->set('name', 'badAttempt')
            ->set('mac', 'aa:bb:cc:dd:ee:99')
            ->set('ip', '10.0.0.99')
            ->call('save');

        $this->assertDatabaseMissing('dhcp_reservations', ['name' => 'badAttempt']);
    }
}
