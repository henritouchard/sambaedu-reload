<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Models\DhcpSubnet;
use App\Models\User;
use App\Services\Network\DhcpService;
use App\Services\Network\DhcpSubnetService;
use App\Services\Print\Contracts\CommandRunner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;
use Tests\Traits\CreatesDhcpSchema;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 8.3 — Tests Feature CRUD des sous-réseaux (VLAN) via l'onglet Livewire
 * `network/dhcp/index` (tab `subnets`).
 *
 * Couvre : création / édition / suppression + gates `manage-dhcp` + mode
 * dégradé (toast warning, réservation SQL conservée — miroir `DhcpDegradedMode`
 * de la 8.1).
 */
class DhcpSubnetsCrudTest extends TestCase
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

        config(['sambaedu.dhcp.subnets_file' => sys_get_temp_dir() . '/dhcp_subnets_feat_' . uniqid() . '.conf']);

        $this->runner = new FakeCommandRunner();
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->runner->whenContains('systemctl', 'active', returnCode: 0);
        $this->bindRunner();
    }

    protected function tearDown(): void
    {
        $this->dropDhcpSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function bindRunner(): void
    {
        $this->app->instance(CommandRunner::class, $this->runner);
        $this->app->forgetInstance(DhcpService::class);
        $this->app->forgetInstance(DhcpSubnetService::class);
    }

    private function makeAdmin(string $login): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    public function test_admin_can_create_subnet_and_reload_is_triggered(): void
    {
        $this->actingAs($this->makeAdmin('admin-subnet-1'));

        Livewire::test('pages::network.dhcp.index')
            ->set('tab', 'subnets')
            ->call('openCreateSubnetModal')
            ->set('vlan_id', 20)
            ->set('network', '192.168.20.0/24')
            ->set('gateway', '192.168.20.254')
            ->set('ranges', [['begin' => '192.168.20.10', 'end' => '192.168.20.100']])
            ->call('saveSubnet')
            ->assertSet('subnetModalOpen', false);

        $this->assertDatabaseHas('dhcp_subnets', ['vlan_id' => 20, 'network' => '192.168.20.0/24']);

        $reload = collect($this->runner->executed)->contains(fn ($c) => str_contains($c, 'make_dhcpd_conf.sh'));
        $this->assertTrue($reload, 'Le reload doit être appelé après création.');
    }

    public function test_admin_can_edit_subnet(): void
    {
        $this->actingAs($this->makeAdmin('admin-subnet-2'));
        $subnet = DhcpSubnet::factory()->create([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '192.168.20.10', 'end' => '192.168.20.100']],
        ]);

        Livewire::test('pages::network.dhcp.index')
            ->call('openEditSubnetModal', $subnet->id)
            ->set('gateway', '192.168.20.1')
            ->call('saveSubnet');

        $this->assertSame('192.168.20.1', $subnet->fresh()->gateway);
    }

    public function test_admin_can_add_and_remove_ranges(): void
    {
        $this->actingAs($this->makeAdmin('admin-subnet-ranges'));

        Livewire::test('pages::network.dhcp.index')
            ->call('openCreateSubnetModal')
            ->call('addRange')
            ->assertCount('ranges', 2)
            ->call('removeRange', 1)
            ->assertCount('ranges', 1);
    }

    public function test_admin_can_delete_subnet(): void
    {
        $this->actingAs($this->makeAdmin('admin-subnet-3'));
        $subnet = DhcpSubnet::factory()->create(['vlan_id' => 20, 'network' => '192.168.20.0/24']);

        Livewire::test('pages::network.dhcp.index')
            ->call('confirmDeleteSubnet', $subnet->id)
            ->call('deleteSubnetConfirmed');

        $this->assertDatabaseMissing('dhcp_subnets', ['id' => $subnet->id]);
    }

    public function test_create_rejects_duplicate_vlan(): void
    {
        $this->actingAs($this->makeAdmin('admin-subnet-4'));
        DhcpSubnet::factory()->create(['vlan_id' => 20, 'network' => '192.168.20.0/24']);

        Livewire::test('pages::network.dhcp.index')
            ->call('openCreateSubnetModal')
            ->set('vlan_id', 20)
            ->set('network', '192.168.30.0/24')
            ->set('gateway', '192.168.30.254')
            ->set('ranges', [['begin' => '192.168.30.10', 'end' => '192.168.30.100']])
            ->call('saveSubnet')
            ->assertHasErrors('subnetForm');

        $this->assertDatabaseMissing('dhcp_subnets', ['network' => '192.168.30.0/24']);
    }

    public function test_non_admin_cannot_save_subnet(): void
    {
        $admin = $this->makeAdmin('admin-subnet-mount');
        $this->actingAs($admin);
        $component = Livewire::test('pages::network.dhcp.index')->call('openCreateSubnetModal');

        $plain = User::query()->create(['login' => 'plain-subnet', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($plain);

        $component
            ->set('vlan_id', 20)
            ->set('network', '192.168.20.0/24')
            ->set('gateway', '192.168.20.254')
            ->set('ranges', [['begin' => '192.168.20.10', 'end' => '192.168.20.100']])
            ->call('saveSubnet');

        $this->assertDatabaseMissing('dhcp_subnets', ['vlan_id' => 20]);
    }

    public function test_degraded_mode_persists_subnet_and_warns(): void
    {
        // Reload en échec : make_dhcpd_conf.sh retourne non-zero.
        $this->runner = new FakeCommandRunner();
        $this->runner->whenContains('systemctl', 'inactive', returnCode: 3);
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 1, stderr: 'isc-dhcp-server: down');
        $this->bindRunner();

        $this->actingAs($this->makeAdmin('admin-subnet-degraded'));

        Livewire::test('pages::network.dhcp.index')
            ->call('openCreateSubnetModal')
            ->set('vlan_id', 20)
            ->set('network', '192.168.20.0/24')
            ->set('gateway', '192.168.20.254')
            ->set('ranges', [['begin' => '192.168.20.10', 'end' => '192.168.20.100']])
            ->call('saveSubnet')
            ->assertDispatched('toastMagic', status: 'warning');

        // AC5 : le sous-réseau est persisté même si le reload a échoué.
        $this->assertDatabaseHas('dhcp_subnets', ['vlan_id' => 20, 'network' => '192.168.20.0/24']);
    }
}
