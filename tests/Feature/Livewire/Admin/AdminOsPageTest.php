<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\User;
use App\SystemStatus\Distro;
use App\SystemStatus\DistroInstallTracker;
use App\SystemStatus\Jobs\RunDistroInstallScriptJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests Feature Livewire de la page /admin/settings/os.
 *
 * Page extraite de « État du système » (2026-07-18) : grille d'une card par
 * distro installable via iPXE.
 *
 * Garde :
 *  - accès bloqué sans server.admin (mount → 403) ;
 *  - rendu : inventaire distros (une card par distro) ;
 *  - installDistro : dispatch du job whitelisté + état running ; lock
 *    anti-concurrence ; refus si déjà disponible ; refus Windows / inconnues.
 */
class AdminOsPageTest extends TestCase
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
        config(['ipxe.iso_management.deployed_os_base_path' => sys_get_temp_dir() . '/se5-ospage-' . uniqid()]);

        // Le tracker utilise le store `file` PARTAGÉ — il persiste entre les
        // runs de tests : reset systématique.
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'aosp_mhp');
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'aosp_mhr');
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
        $viewer = User::query()->create(['login' => 'viewer-ospage', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test('pages::admin.settings.os.index')
            ->assertStatus(403);
    }

    public function test_it_renders_distro_inventory(): void
    {
        $this->actingAs($this->makeAdmin('admin-ospage-render'));

        Livewire::test('pages::admin.settings.os.index')
            // Grille sans encadré : plus de titre de section « Distros installables ».
            ->assertDontSee('Distros installables')
            // Windows est une card UNIQUE (Win10 + Win11 regroupés), sans bouton
            // (le clic sur la card suffit).
            ->assertSee('Windows')
            ->assertDontSee('Windows 11')
            ->assertDontSee('Gérer les sources Windows')
            ->assertSee('Debian (netboot)')
            // La card Windows pointe vers la page de gestion des ISO.
            ->assertSeeHtml(route('admin.ipxe.iso-windows'));
    }

    public function test_it_dispatches_install_job_for_whitelisted_distro(): void
    {
        Queue::fake();

        $this->actingAs($this->makeAdmin('admin-ospage-install'));

        Livewire::test('pages::admin.settings.os.index')
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

        $this->actingAs($this->makeAdmin('admin-ospage-lock'));

        // Deux appels successifs (double-clic / 2 onglets) → un seul job
        // dispatché, le second est refusé par le lock.
        Livewire::test('pages::admin.settings.os.index')
            ->call('installDistro', 'debian')
            ->call('installDistro', 'debian');

        Queue::assertPushed(RunDistroInstallScriptJob::class, 1);
    }

    public function test_it_refuses_install_when_distro_already_available(): void
    {
        Queue::fake();

        // Distro déjà déployée → l'action est refusée même appelée directement
        // (bouton masqué côté UI).
        $base = (string) config('ipxe.iso_management.deployed_os_base_path');
        foreach (Distro::Debian->availabilityMarkers() as $marker) {
            $path = $base . '/' . $marker;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, 'x');
        }

        $this->actingAs($this->makeAdmin('admin-ospage-avail'));

        Livewire::test('pages::admin.settings.os.index')
            ->call('installDistro', 'debian');

        Queue::assertNothingPushed();
        self::assertFalse(app(DistroInstallTracker::class)->isRunning(Distro::Debian));
    }

    public function test_it_refuses_install_for_windows_and_unknown_distros(): void
    {
        Queue::fake();

        $this->actingAs($this->makeAdmin('admin-ospage-refuse'));

        Livewire::test('pages::admin.settings.os.index')
            ->call('installDistro', 'win11')
            ->call('installDistro', 'gentoo');

        Queue::assertNothingPushed();
        self::assertFalse(app(DistroInstallTracker::class)->isRunning(Distro::Win11));
    }
}
