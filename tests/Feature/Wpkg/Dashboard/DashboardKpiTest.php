<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Dashboard;

use App\Models\AppProfile;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
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
 * Story 15.5 / AC3.2 + AC6.3 — Tests des KPIs globaux du dashboard.
 */
final class DashboardKpiTest extends TestCase
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

        // Ajout colonne last_report_at attendue par le service.
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

    private function addStatus(string $deploymentId, int $workstationId, string $status, ?\DateTimeInterface $reportedAt = null): void
    {
        WpkgDeploymentWorkstationStatus::create([
            'deployment_id' => $deploymentId,
            'workstation_id' => $workstationId,
            'client_reported_at' => $reportedAt ?? now(),
            'client_status' => $status,
        ]);
    }

    #[Test]
    public function kpis_aggregate_latest_status_per_workstation(): void
    {
        $w1 = Workstation::create(['name' => 'W1', 'status' => 'active']);
        $w2 = Workstation::create(['name' => 'W2', 'status' => 'active']);
        $w3 = Workstation::create(['name' => 'W3', 'status' => 'active']);

        $deploymentId = $this->makeDeployment();

        $this->addStatus($deploymentId, $w1->id, 'success');
        $this->addStatus($deploymentId, $w2->id, 'partial');
        $this->addStatus($deploymentId, $w3->id, 'failed');

        $svc = new WpkgDashboardQueryService();
        $kpis = $svc->kpis();

        $this->assertSame(3, $kpis['total']);
        $this->assertSame(1, $kpis['success']);
        $this->assertSame(1, $kpis['partial']);
        $this->assertSame(1, $kpis['failed']);
    }

    #[Test]
    public function distinct_on_picks_most_recent_status_per_workstation(): void
    {
        $w1 = Workstation::create(['name' => 'W1', 'status' => 'active']);
        $deploymentId = $this->makeDeployment();

        // Old failed → recent success.
        $this->addStatus($deploymentId, $w1->id, 'failed', now()->subHours(2));
        $this->addStatus($deploymentId, $w1->id, 'success', now());

        $svc = new WpkgDashboardQueryService();
        $kpis = $svc->kpis();

        $this->assertSame(1, $kpis['success']);
        $this->assertSame(0, $kpis['failed']);
    }

    #[Test]
    public function silent_count_includes_workstations_with_old_or_null_last_report(): void
    {
        $wActive = Workstation::create([
            'name' => 'W-Active', 'status' => 'active', 'last_report_at' => now()->subDay(),
        ]);
        $wSilent = Workstation::create([
            'name' => 'W-Silent', 'status' => 'active', 'last_report_at' => now()->subDays(10),
        ]);
        $wNever = Workstation::create([
            'name' => 'W-Never', 'status' => 'active', 'last_report_at' => null,
        ]);

        $svc = new WpkgDashboardQueryService();
        $kpis = $svc->kpis();

        $this->assertSame(3, $kpis['total']);
        // 2 silencieux : W-Silent (>7j) + W-Never (NULL).
        $this->assertSame(2, $kpis['silent']);
        $this->assertSame(1, $kpis['never_reported']);
    }

    #[Test]
    public function archived_workstations_excluded(): void
    {
        Workstation::create([
            'name' => 'W-Active', 'status' => 'active',
        ]);
        Workstation::create([
            'name' => 'W-Archived', 'status' => 'active', 'archived_at' => now(),
        ]);

        $svc = new WpkgDashboardQueryService();
        $kpis = $svc->kpis();

        $this->assertSame(1, $kpis['total']);
    }
}
