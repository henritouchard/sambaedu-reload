<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\User;
use App\SystemStatus\Distro;
use App\SystemStatus\DistroInstallTracker;
use App\SystemStatus\Jobs\RunDistroInstallScriptJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests Feature Livewire de la page /admin/settings/system-status.
 *
 * Garde :
 *  - accès bloqué sans server.admin (mount → 403) ;
 *  - rendu : état vide (aucun check au chargement) + inventaire distros ;
 *  - runChecks : toutes les sections produisent des résultats (réseau mocké
 *    via Process::fake — le bind LDAP réel tourne avec timeout court) ;
 *  - installDistro : dispatch du job whitelisté + état running ; refus des
 *    distros Windows (orchestrateur dédié).
 */
class AdminSystemStatusPageTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        // Inventaire distros sur un répertoire temporaire vide (aucune
        // distro disponible → boutons d'action visibles).
        config(['ipxe.iso_management.deployed_os_base_path' => sys_get_temp_dir() . '/se5-sysstatus-' . uniqid()]);

        // Le tracker utilise le store `file` PARTAGÉ (fix review F1) — il
        // persiste entre les runs de tests : reset systématique.
        $tracker = app(DistroInstallTracker::class);
        foreach (Distro::cases() as $distro) {
            $tracker->reset($distro);
        }

        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('model_has_permissions');
            Schema::dropIfExists('model_has_roles');
            Schema::dropIfExists('role_has_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function createTablesIfNeeded(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('password', 255)->nullable();
                $table->string('fullname', 255)->nullable();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'assp_mhp');
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'assp_mhr');
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
            $this->createdTables = true;
        }

        Permission::firstOrCreate(['name' => 'server.admin', 'guard_name' => 'web']);
    }

    private function makeAdmin(string $login): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('server.admin');

        return $u;
    }

    public function test_it_blocks_access_without_server_admin(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-sysstatus', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test('pages::admin.settings.system-status.index')
            ->assertStatus(403);
    }

    public function test_it_renders_loading_state_and_distro_inventory_before_checks_complete(): void
    {
        $this->actingAs($this->makeAdmin('admin-sysstatus-render'));

        // Le premier rendu n'exécute PAS les checks (ils arrivent via
        // wire:init juste après) : skeleton + inventaire distros immédiats.
        Livewire::test('pages::admin.settings.system-status.index')
            ->assertSee('Vérification des connexions en cours')
            ->assertSee('Distros installables')
            ->assertSee('Windows 11')
            ->assertSee('Debian (netboot)')
            ->assertSet('checksRan', false);
    }

    public function test_it_runs_all_check_sections_on_demand(): void
    {
        // Mock des exécutions système (ping, klist, apache2ctl) — le réseau
        // HTTP controlHub n'est pas sollicité (pas de connexion en DB test).
        // Le bind LDAP réel (ext-ldap non mockable) reste : timeout court,
        // niveau non asserté (dépend de l'environnement host/VM).
        Process::fake([
            '*' => Process::result(output: 'Syntax OK'),
        ]);

        // iPXE configuré proprement : racine existante + clés alignées +
        // vars AD présentes → niveau déterministe `ok`.
        $tmp = sys_get_temp_dir() . '/se5-sysstatus-roots-' . uniqid();
        mkdir($tmp, 0755, true);
        config([
            'ipxe.actions.os_assets.roots' => [$tmp],
            'ipxe.iso_management.deployed_os_base_path' => $tmp,
            'sambaedu.domain' => 'localdev.fr',
            'sambaedu.se4install_name' => 'se4install',
        ]);

        $this->actingAs($this->makeAdmin('admin-sysstatus-checks'));

        $component = Livewire::test('pages::admin.settings.system-status.index')
            ->call('runChecks')
            ->assertSet('checksRan', true);

        $results = $component->get('results');

        // Structure : toutes les sections présentes, détails non vides.
        foreach (['Active Directory', 'Base de données', 'controlHub', 'Apache', 'iPXE'] as $section) {
            self::assertArrayHasKey($section, $results, "Section {$section} absente des résultats.");
            self::assertNotEmpty($results[$section]);
            foreach ($results[$section] as $check) {
                self::assertNotSame('', $check['name']);
                self::assertNotSame('', $check['detail']);
            }
        }

        // Niveaux déterministes en environnement de test (fix review F8 —
        // pas d'assertion tautologique) :
        self::assertSame('ok', $results['Base de données'][0]['level']);
        // Pas de table controlhub_connection → warn « non connecté ».
        self::assertSame('warn', $results['controlHub'][0]['level']);
        // Racine existante + clés alignées + vars AD → ok.
        self::assertSame('ok', $results['iPXE'][0]['level']);

        rmdir($tmp);
    }

    public function test_it_dispatches_install_job_for_whitelisted_distro(): void
    {
        Queue::fake();

        $this->actingAs($this->makeAdmin('admin-sysstatus-install'));

        Livewire::test('pages::admin.settings.system-status.index')
            ->call('installDistro', 'debian');

        Queue::assertPushed(RunDistroInstallScriptJob::class, function (RunDistroInstallScriptJob $job): bool {
            return $job->distro === Distro::Debian;
        });

        // `running` posé avant le dispatch — l'UI reflète l'intention.
        self::assertTrue(app(DistroInstallTracker::class)->isRunning(Distro::Debian));
    }

    public function test_it_refuses_concurrent_install_via_lock(): void
    {
        Queue::fake();

        $this->actingAs($this->makeAdmin('admin-sysstatus-lock'));

        // Fix review F2 : deux appels successifs (double-clic / 2 onglets)
        // → un seul job dispatché, le second est refusé par le lock.
        Livewire::test('pages::admin.settings.system-status.index')
            ->call('installDistro', 'debian')
            ->call('installDistro', 'debian');

        Queue::assertPushed(RunDistroInstallScriptJob::class, 1);
    }

    public function test_it_refuses_install_when_distro_already_available(): void
    {
        Queue::fake();

        // Fix review F10 : distro déjà déployée → l'action est refusée même
        // appelée directement (bouton masqué côté UI).
        $base = (string) config('ipxe.iso_management.deployed_os_base_path');
        foreach (Distro::Debian->availabilityMarkers() as $marker) {
            $path = $base . '/' . $marker;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, 'x');
        }

        $this->actingAs($this->makeAdmin('admin-sysstatus-avail'));

        Livewire::test('pages::admin.settings.system-status.index')
            ->call('installDistro', 'debian');

        Queue::assertNothingPushed();
        self::assertFalse(app(DistroInstallTracker::class)->isRunning(Distro::Debian));
    }

    public function test_it_refuses_install_for_windows_and_unknown_distros(): void
    {
        Queue::fake();

        $this->actingAs($this->makeAdmin('admin-sysstatus-refuse'));

        Livewire::test('pages::admin.settings.system-status.index')
            ->call('installDistro', 'win11')
            ->call('installDistro', 'gentoo');

        Queue::assertNothingPushed();
        self::assertFalse(app(DistroInstallTracker::class)->isRunning(Distro::Win11));
    }
}
