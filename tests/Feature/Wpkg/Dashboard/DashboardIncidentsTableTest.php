<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Dashboard;

use App\Models\Workstation;
use App\Wpkg\Deployment\Models\WpkgDeploymentWorkstationStatus;
use App\Wpkg\Deployment\Services\WpkgDashboardQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.5 / Fix #11 — Tests de la table « incidents 24h » du dashboard.
 *
 * Vérifie que la déduplication par `workstation_id` fonctionne : un poste
 * qui rapporte 3 fois `failed` ne produit qu'une seule ligne (le dernier
 * statut).
 */
final class DashboardIncidentsTableTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        WpkgSchemaBootstrapper::bootstrap();

        Schema::create('wpkg_deployments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('triggered_by')->nullable();
            $t->timestamp('triggered_at');
            $t->json('target_scope')->nullable();
            $t->string('status', 20)->default('pending');
            $t->json('summary')->nullable();
            $t->timestamps();
        });

        Schema::create('wpkg_deployment_workstation_status', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('deployment_id');
            $t->unsignedBigInteger('workstation_id');
            $t->unsignedBigInteger('app_profile_id')->nullable();
            $t->timestamp('client_reported_at')->nullable();
            $t->string('client_status', 20)->default('pending');
            $t->json('details')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamps();
        });

        Schema::table('workstations', function (Blueprint $t) {
            if (! Schema::hasColumn('workstations', 'last_report_at')) {
                $t->timestamp('last_report_at')->nullable();
            }
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wpkg_deployment_workstation_status');
        Schema::dropIfExists('wpkg_deployments');
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    private function makeDeployment(): string
    {
        $id = (string) Str::uuid();
        DB::table('wpkg_deployments')->insert([
            'id' => $id,
            'triggered_by' => null,
            'triggered_at' => now(),
            'target_scope' => json_encode([]),
            'status' => 'completed',
            'summary' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    #[Test]
    public function incidents_table_dedups_by_workstation_keeping_latest(): void
    {
        $workstation = Workstation::create(['name' => 'PC-INC-1', 'status' => 'active']);
        $deploymentId = $this->makeDeployment();

        // 3 rapports failed à 5 min d'intervalle pour le MÊME poste.
        $now = now();
        WpkgDeploymentWorkstationStatus::create([
            'deployment_id' => $deploymentId,
            'workstation_id' => $workstation->id,
            'client_reported_at' => $now->copy()->subMinutes(15),
            'client_status' => 'failed',
        ]);
        WpkgDeploymentWorkstationStatus::create([
            'deployment_id' => $deploymentId,
            'workstation_id' => $workstation->id,
            'client_reported_at' => $now->copy()->subMinutes(10),
            'client_status' => 'failed',
        ]);
        WpkgDeploymentWorkstationStatus::create([
            'deployment_id' => $deploymentId,
            'workstation_id' => $workstation->id,
            'client_reported_at' => $now->copy()->subMinutes(5),
            'client_status' => 'failed',
        ]);

        $svc = new WpkgDashboardQueryService();
        $paginator = $svc->recentIncidentsPaginated(50);

        $this->assertSame(
            1,
            $paginator->total(),
            'La table incidents doit afficher 1 seule ligne par poste (dernier statut), pas 3.',
        );
    }

    #[Test]
    public function incidents_table_filters_by_status(): void
    {
        $w1 = Workstation::create(['name' => 'PC-INC-A', 'status' => 'active']);
        $w2 = Workstation::create(['name' => 'PC-INC-B', 'status' => 'active']);
        $deploymentId = $this->makeDeployment();

        WpkgDeploymentWorkstationStatus::create([
            'deployment_id' => $deploymentId,
            'workstation_id' => $w1->id,
            'client_reported_at' => now(),
            'client_status' => 'failed',
        ]);
        WpkgDeploymentWorkstationStatus::create([
            'deployment_id' => $deploymentId,
            'workstation_id' => $w2->id,
            'client_reported_at' => now(),
            'client_status' => 'partial',
        ]);

        $svc = new WpkgDashboardQueryService();

        $failedOnly = $svc->recentIncidentsPaginated(50, ['failed']);
        $this->assertSame(1, $failedOnly->total());

        $allIncidents = $svc->recentIncidentsPaginated(50);
        $this->assertSame(2, $allIncidents->total());
    }

    #[Test]
    public function incidents_table_excludes_outside_24h_window(): void
    {
        $workstation = Workstation::create(['name' => 'PC-OLD', 'status' => 'active']);
        $deploymentId = $this->makeDeployment();

        WpkgDeploymentWorkstationStatus::create([
            'deployment_id' => $deploymentId,
            'workstation_id' => $workstation->id,
            'client_reported_at' => now()->subDays(2),
            'client_status' => 'failed',
        ]);

        $svc = new WpkgDashboardQueryService();
        $paginator = $svc->recentIncidentsPaginated(50);

        $this->assertSame(0, $paginator->total());
    }
}
