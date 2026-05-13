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
 * Story 8.1 — Mode dégradé (AC6) : service DHCP injoignable.
 *
 *  - Page liste reste accessible (lecture DB) ;
 *  - bannière rouge + table baux affiche "Lecture indisponible" ;
 *  - une mutation pendant que le service est down :
 *     * persiste la réservation en DB ;
 *     * reload échoue (DhcpCommandException) ;
 *     * un toast d'avertissement non-bloquant est émis (pas d'erreur fatale).
 */
class DhcpDegradedModeTest extends TestCase
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

        // Service DOWN : `systemctl is-active` retourne non-zero,
        // `make_dhcpd_conf.sh` aussi.
        $this->runner = new FakeCommandRunner();
        $this->runner->whenContains('systemctl', 'inactive', returnCode: 3);
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 1, stderr: 'isc-dhcp-server: down');
        $this->app->instance(CommandRunner::class, $this->runner);

        config(['sambaedu.dhcp.reservations_file' => sys_get_temp_dir() . '/dhcp_degraded_' . uniqid() . '.inc']);
        config(['sambaedu.dhcp.leases_file' => '/nonexistent']);
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

    public function test_page_loads_in_degraded_mode_with_inactive_banner(): void
    {
        $this->actingAs($this->makeAdmin('admin-degraded-1'));

        Livewire::test('pages::network.dhcp.index')
            ->assertSee('Réservations DHCP')
            ->assertSee('injoignable');
    }

    public function test_create_persists_in_db_even_when_reload_fails(): void
    {
        $this->actingAs($this->makeAdmin('admin-degraded-2'));

        $component = Livewire::test('pages::network.dhcp.index')
            ->set('name', 'posteDegraded')
            ->set('mac', 'aa:bb:cc:dd:ee:99')
            ->set('ip', '10.0.0.99')
            ->call('save');

        // AC6 : réservation persistée même si reload échoue
        $this->assertDatabaseHas('dhcp_reservations', [
            'name' => 'posteDegraded',
            'ip' => '10.0.0.99',
        ]);

        // Review code 8.1 #8 : c'est un toast WARNING (non bloquant) et
        // PAS un toast ERROR — la mutation a réussi, seul le reload a planté.
        // `WithToasts::toastWarning()` dispatche l'event `toastMagic` avec
        // `status: 'warning'`.
        $component->assertDispatched(
            'toastMagic',
            status: 'warning',
        );
    }

    public function test_leases_table_renders_unavailable_when_file_missing(): void
    {
        $this->actingAs($this->makeAdmin('admin-degraded-3'));

        // listActiveLeases() retourne empty mais $leasesAvailable doit
        // permettre à la vue d'afficher la bannière info "Aucun bail".
        // Le rendering ne doit pas crasher.
        Livewire::test('pages::network.dhcp.index')
            ->assertSuccessful();
    }
}
