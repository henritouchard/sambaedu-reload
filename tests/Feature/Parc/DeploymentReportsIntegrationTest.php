<?php

declare(strict_types=1);

namespace Tests\Feature\Parc;

use App\Models\Application;
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
            $table->unsignedBigInteger('physical_room_id')->nullable();
            $table->string('ad_dn', 512)->nullable();
            $table->string('ad_guid', 36)->nullable();
            $table->boolean('managed_by_control_hub')->default(false);
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

    // ─── T3 : getMachines() withCount ─────────────────────────────────────────

    #[Test]
    public function get_machines_returns_installed_and_error_counts(): void
    {
        $ws  = $this->makeWorkstation('PC-COUNT-01');
        $app1 = $this->makeApplication('app-ok');
        $app2 = $this->makeApplication('app-err');
        $app3 = $this->makeApplication('app-missing');

        $this->makeStatus($ws->id, $app1->id, 'installed');
        $this->makeStatus($ws->id, $app2->id, 'error');
        $this->makeStatus($ws->id, $app3->id, 'not-installed');

        $repo   = app(WorkstationGroupRepository::class);
        $result = $repo->getMachines(perPage: 50);

        $machine = $result->firstWhere('id', $ws->id);

        $this->assertNotNull($machine, 'Machine introuvable dans les résultats');
        $this->assertEquals(1, $machine->installed_apps_count, 'installed_apps_count incorrect');
        $this->assertEquals(2, $machine->error_apps_count, 'error_apps_count incorrect (error + not-installed)');
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
    public function get_machines_excludes_in_progress_from_error_count(): void
    {
        $ws  = $this->makeWorkstation('PC-COUNT-PROG');
        $app = $this->makeApplication('upgrading-app');

        $this->makeStatus($ws->id, $app->id, 'upgrading');

        $repo   = app(WorkstationGroupRepository::class);
        $result = $repo->getMachines(perPage: 50);

        $machine = $result->firstWhere('id', $ws->id);

        $this->assertNotNull($machine);
        $this->assertEquals(0, $machine->installed_apps_count);
        $this->assertEquals(0, $machine->error_apps_count, 'upgrading ne doit pas compter comme erreur');
    }

    // ─── T4 : listApplications() withCount ───────────────────────────────────

    #[Test]
    public function list_applications_returns_deployment_counts(): void
    {
        $app = $this->makeApplication('firefox-deploy');
        $ws1 = $this->makeWorkstation('PC-DEPLOY-01');
        $ws2 = $this->makeWorkstation('PC-DEPLOY-02');
        $ws3 = $this->makeWorkstation('PC-DEPLOY-03');

        $this->makeStatus($ws1->id, $app->id, 'installed');
        $this->makeStatus($ws2->id, $app->id, 'error');
        $this->makeStatus($ws3->id, $app->id, 'not-installed');

        $service = app(AppProfileService::class);
        $result  = $service->listApplications(perPage: 50);

        $found = $result->firstWhere('id', $app->id);

        $this->assertNotNull($found, 'Application introuvable dans les résultats');
        $this->assertEquals(3, $found->deployed_total_count, 'deployed_total_count incorrect');
        $this->assertEquals(1, $found->deployed_installed_count, 'deployed_installed_count incorrect');
        $this->assertEquals(2, $found->deployed_error_count, 'deployed_error_count incorrect');
    }

    #[Test]
    public function list_applications_returns_zero_counts_when_no_deployments(): void
    {
        $app = $this->makeApplication('orphan-app');

        $service = app(AppProfileService::class);
        $result  = $service->listApplications(perPage: 50);

        $found = $result->firstWhere('id', $app->id);

        $this->assertNotNull($found);
        $this->assertEquals(0, $found->deployed_total_count);
        $this->assertEquals(0, $found->deployed_installed_count);
        $this->assertEquals(0, $found->deployed_error_count);
    }

    #[Test]
    public function list_applications_excludes_in_progress_from_total(): void
    {
        $app = $this->makeApplication('upgrading-app-svc');
        $ws  = $this->makeWorkstation('PC-UPGRDING');

        $this->makeStatus($ws->id, $app->id, 'upgrading');

        $service = app(AppProfileService::class);
        $result  = $service->listApplications(perPage: 50);

        $found = $result->firstWhere('id', $app->id);

        $this->assertNotNull($found);
        $this->assertEquals(0, $found->deployed_total_count, 'upgrading ne doit pas compter dans le total');
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
