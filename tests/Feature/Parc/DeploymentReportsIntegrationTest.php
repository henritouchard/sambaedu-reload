<?php

declare(strict_types=1);

namespace Tests\Feature\Parc;

use App\Models\AgentApplicationInventory;
use App\Models\Application;
use App\Wpkg\Deployment\Services\ApplicationDeploymentCoverage;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use App\Repositories\WorkstationGroupRepository;
use App\Services\AppProfile\AppProfileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature : rapports de déploiement intégrés aux pages existantes
 *
 * Couverture :
 *   T2  — Champ `message` ajouté au modèle WorkstationApplicationStatus
 *   T3  — getMachines() retourne installed_apps_count et error_apps_count
 *   T4  — listApplications() retourne deployed_total/installed/error_count
 *   AC1 — Colonne déploiement liste machines : compteurs corrects
 *   AC4 — Taux de réussite liste applications
 *   AC6 — Routes windows-deploy supprimées (404)
 */
class DeploymentReportsIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('agent_application_inventory');
            Schema::dropIfExists('workstation_application_status');
            Schema::dropIfExists('applications');
            Schema::dropIfExists('workstations');
        }
        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createTablesIfNeeded(): void
    {
        if (Schema::hasTable('workstations')) {
            return;
        }

        $this->createdTables = true;

        Schema::create('workstations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('os', 100)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('mac', 17)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_report_at')->nullable();
            $table->string('report_sha', 64)->nullable();
            $table->text('log_path')->nullable();
            $table->text('report_path')->nullable();
            $table->string('ad_dn', 512)->nullable();
            $table->string('ad_guid', 36)->nullable();
            $table->boolean('managed_by_control_hub')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 100)->unique();
            $table->string('name', 255)->nullable();
            $table->string('version', 50)->nullable();
            $table->string('status', 30)->default('active');
            $table->unsignedBigInteger('depot_id')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('branch', 50)->nullable();
            $table->timestamps();
        });

        // Tables de l'etat cible, lues par WorkstationPackagesResolver.
        Schema::create('workstation_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workstation_group_workstation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workstation_id');
            $table->unsignedBigInteger('workstation_group_id');
            $table->timestamps();
        });

        Schema::create('app_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('app_profile_workstation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_profile_id');
            $table->unsignedBigInteger('workstation_id');
            $table->timestamps();
        });

        Schema::create('app_profile_workstation_group', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_profile_id');
            $table->unsignedBigInteger('workstation_group_id');
            $table->timestamps();
        });

        Schema::create('app_profile_application', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_profile_id');
            $table->unsignedBigInteger('application_id');
            $table->timestamps();
        });

        Schema::create('application_workstation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('workstation_id');
            $table->timestamps();
        });

        Schema::create('application_workstation_group', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('workstation_group_id');
            $table->timestamps();
        });

        Schema::create('application_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('required_application_id');
            $table->timestamps();
        });

        Schema::create('workstation_application_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workstation_id');
            $table->unsignedBigInteger('application_id');
            $table->string('installed_version', 255)->nullable();
            $table->string('status', 20);
            $table->boolean('reboot_required')->default(false);
            $table->timestamp('reported_at')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['workstation_id', 'application_id']);
        });

        // Story 27.5 — inventaire per-app rapporté par l'agent (canal natif),
        // source de la colonne « Déploiement » depuis l'extinction du WPKG.
        Schema::create('agent_application_inventory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workstation_id');
            $table->string('app_id', 191);
            $table->string('status', 32);
            $table->string('detail', 2000)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
            $table->unique(['workstation_id', 'app_id']);
        });

        // Story 16.13bis — table consultée par l'eager-load `migrationStatus`
        // du repo paginateMachines (Workstation::with('migrationStatus')).
        Schema::create('workstations_migration_status', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('workstation_uuid', 36)->unique();
            $table->timestamp('migrated_at');
            $table->string('access_token_emitted_jti', 36)->nullable();
            $table->string('bootstrap_token_hash_prefix', 16)->nullable();
            $table->string('os', 16);
            $table->string('se4fs_name', 255)->nullable();
            $table->timestamps();
        });
    }

    private function makeWorkstation(string $name): Workstation
    {
        return Workstation::create(['name' => $name]);
    }

    private function makeApplication(string $appId): Application
    {
        return Application::create(['app_id' => $appId, 'name' => $appId]);
    }

    private function makeStatus(int $workstationId, int $applicationId, string $status, ?string $message = null): WorkstationApplicationStatus
    {
        return WorkstationApplicationStatus::create([
            'workstation_id'    => $workstationId,
            'application_id'    => $applicationId,
            'installed_version' => '1.0',
            'status'            => $status,
            'reboot_required'   => false,
            'reported_at'       => now(),
            'message'           => $message,
        ]);
    }

    /**
     * Story 27.5 — ligne d'inventaire rapportée par l'agent (canal natif).
     * status ∈ {compliant, drift = installé, error = non installé}.
     */
    private function makeAgentInventory(int $workstationId, string $appId, string $status): AgentApplicationInventory
    {
        return AgentApplicationInventory::create([
            'workstation_id' => $workstationId,
            'app_id'         => $appId,
            'status'         => $status,
            'reported_at'    => now(),
        ]);
    }

    // ─── T2 : Champ message ───────────────────────────────────────────────────

    #[Test]
    public function message_field_is_fillable_and_persisted(): void
    {
        $ws  = $this->makeWorkstation('PC-MSG-01');
        $app = $this->makeApplication('firefox');

        $status = $this->makeStatus($ws->id, $app->id, 'error', 'Package not found in repository');

        $this->assertDatabaseHas('workstation_application_status', [
            'workstation_id' => $ws->id,
            'application_id' => $app->id,
            'message'        => 'Package not found in repository',
        ]);
    }

    #[Test]
    public function message_field_is_nullable(): void
    {
        $ws  = $this->makeWorkstation('PC-MSG-02');
        $app = $this->makeApplication('vlc');

        $status = $this->makeStatus($ws->id, $app->id, 'installed', null);

        $this->assertNull($status->fresh()->message);
    }

    // ─── T3 : getMachines() withCount (canal natif agent — Story 27.5) ────────

    #[Test]
    public function get_machines_returns_installed_and_error_counts(): void
    {
        $ws = $this->makeWorkstation('PC-COUNT-01');

        // compliant = installé ; error = non installé (canal AGENT).
        $this->makeAgentInventory($ws->id, 'app-ok', 'compliant');
        $this->makeAgentInventory($ws->id, 'app-err', 'error');
        $this->makeAgentInventory($ws->id, 'app-missing', 'error');

        $repo   = app(WorkstationGroupRepository::class);
        $result = $repo->getMachines(perPage: 50);

        $machine = $result->firstWhere('id', $ws->id);

        $this->assertNotNull($machine, 'Machine introuvable dans les résultats');
        $this->assertEquals(1, $machine->installed_apps_count, 'installed_apps_count incorrect');
        $this->assertEquals(2, $machine->error_apps_count, 'error_apps_count incorrect');
    }

    #[Test]
    public function get_machines_returns_zero_counts_when_no_statuses(): void
    {
        $ws = $this->makeWorkstation('PC-COUNT-EMPTY');

        $repo   = app(WorkstationGroupRepository::class);
        $result = $repo->getMachines(perPage: 50);

        $machine = $result->firstWhere('id', $ws->id);

        $this->assertNotNull($machine);
        $this->assertEquals(0, $machine->installed_apps_count);
        $this->assertEquals(0, $machine->error_apps_count);
    }

    #[Test]
    public function get_machines_counts_drift_as_installed(): void
    {
        // Une dérive réappliquée (STRICT) reste une app installée : elle doit
        // gonfler installed_apps_count, jamais error_apps_count.
        $ws = $this->makeWorkstation('PC-COUNT-DRIFT');

        $this->makeAgentInventory($ws->id, 'drifted-app', 'drift');

        $repo   = app(WorkstationGroupRepository::class);
        $result = $repo->getMachines(perPage: 50);

        $machine = $result->firstWhere('id', $ws->id);

        $this->assertNotNull($machine);
        $this->assertEquals(1, $machine->installed_apps_count, 'drift doit compter comme installé');
        $this->assertEquals(0, $machine->error_apps_count, 'drift ne doit pas compter comme erreur');
    }

    #[Test]
    public function get_machines_ignores_legacy_wpkg_statuses(): void
    {
        // Preuve du basculement de canal : des rapports WPKG legacy ne doivent
        // plus alimenter la colonne « Déploiement » (source = agent uniquement).
        $ws  = $this->makeWorkstation('PC-COUNT-WPKG');
        $app = $this->makeApplication('legacy-wpkg-app');

        $this->makeStatus($ws->id, $app->id, 'installed');

        $repo   = app(WorkstationGroupRepository::class);
        $result = $repo->getMachines(perPage: 50);

        $machine = $result->firstWhere('id', $ws->id);

        $this->assertNotNull($machine);
        $this->assertEquals(0, $machine->installed_apps_count, 'le canal WPKG legacy ne doit plus être compté');
        $this->assertEquals(0, $machine->error_apps_count);
    }

    // ─── T4 : couverture de déploiement (cible vs constaté) ──────────────────

    private function assign(Application $app, Workstation ...$workstations): void
    {
        foreach ($workstations as $workstation) {
            $workstation->applications()->attach($app->id);
        }
    }

    #[Test]
    public function coverage_counts_targeted_workstations_and_actual_installs(): void
    {
        $app = $this->makeApplication('firefox-deploy');
        $ws1 = $this->makeWorkstation('PC-DEPLOY-01');
        $ws2 = $this->makeWorkstation('PC-DEPLOY-02');
        $ws3 = $this->makeWorkstation('PC-DEPLOY-03');
        $this->assign($app, $ws1, $ws2, $ws3);

        $this->makeStatus($ws1->id, $app->id, 'installed');
        $this->makeStatus($ws2->id, $app->id, 'error');
        $this->makeStatus($ws3->id, $app->id, 'not-installed');

        $coverage = app(ApplicationDeploymentCoverage::class)->forApplications([$app]);

        $this->assertSame(3, $coverage[$app->id]['target']);
        $this->assertSame(1, $coverage[$app->id]['installed']);
    }

    #[Test]
    public function a_targeted_workstation_that_never_reported_still_counts_as_expected(): void
    {
        $app = $this->makeApplication('silent-app');
        $reported = $this->makeWorkstation('PC-SILENT-01');
        $silent = $this->makeWorkstation('PC-SILENT-02');
        $this->assign($app, $reported, $silent);

        $this->makeStatus($reported->id, $app->id, 'installed');

        $coverage = app(ApplicationDeploymentCoverage::class)->forApplications([$app]);

        $this->assertSame(2, $coverage[$app->id]['target'],
            'un poste qui doit l\'avoir compte, meme sans rapport');
        $this->assertSame(1, $coverage[$app->id]['installed']);
    }

    #[Test]
    public function an_install_outside_the_target_is_not_a_success(): void
    {
        $app = $this->makeApplication('stray-app');
        $stray = $this->makeWorkstation('PC-STRAY-01');

        $this->makeStatus($stray->id, $app->id, 'installed');

        $coverage = app(ApplicationDeploymentCoverage::class)->forApplications([$app]);

        $this->assertSame(0, $coverage[$app->id]['target'],
            'aucun poste ne la demande : rien a deployer');
        $this->assertSame(0, $coverage[$app->id]['installed']);
    }

    #[Test]
    public function an_application_nobody_requests_has_no_coverage(): void
    {
        $app = $this->makeApplication('orphan-app');

        $coverage = app(ApplicationDeploymentCoverage::class)->forApplications([$app]);

        $this->assertSame(['target' => 0, 'installed' => 0], $coverage[$app->id]);
    }

    // ─── AC6 : Routes windows-deploy supprimées ───────────────────────────────

    #[Test]
    public function windows_deploy_reports_route_returns_404(): void
    {
        $this->withoutMiddleware();

        $response = $this->get('/app/windows-deploy/reports');

        $response->assertStatus(404);
    }

    #[Test]
    public function windows_deploy_workstation_detail_route_returns_404(): void
    {
        $this->withoutMiddleware();

        $response = $this->get('/app/windows-deploy/reports/1');

        $response->assertStatus(404);
    }
}
