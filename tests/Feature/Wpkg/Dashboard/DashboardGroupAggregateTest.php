<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Dashboard;

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
 * Story 15.5 / AC3.3 — Tests des agrégats par parc.
 */
final class DashboardGroupAggregateTest extends TestCase
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

    private function attach(int $workstationId, int $groupId): void
    {
        DB::table('workstation_group_workstation')->insert([
            'workstation_id' => $workstationId,
            'workstation_group_id' => $groupId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
    public function group_aggregates_count_workstations_by_status(): void
    {
        $g1 = WorkstationGroup::create(['name' => 'parc-A', 'is_active' => true]);
        $g2 = WorkstationGroup::create(['name' => 'parc-B', 'is_active' => true]);

        $w1 = Workstation::create(['name' => 'PC-A1', 'status' => 'active']);
        $w2 = Workstation::create(['name' => 'PC-A2', 'status' => 'active']);
        $w3 = Workstation::create(['name' => 'PC-B1', 'status' => 'active']);

        $this->attach($w1->id, $g1->id);
        $this->attach($w2->id, $g1->id);
        $this->attach($w3->id, $g2->id);

        $deployment = $this->makeDeployment();
        WpkgDeploymentWorkstationStatus::create([
            'deployment_id' => $deployment,
            'workstation_id' => $w1->id,
            'client_reported_at' => now(),
            'client_status' => 'success',
        ]);
        WpkgDeploymentWorkstationStatus::create([
            'deployment_id' => $deployment,
            'workstation_id' => $w2->id,
            'client_reported_at' => now(),
            'client_status' => 'failed',
        ]);

        $svc = new WpkgDashboardQueryService();
        $aggregates = $svc->groupAggregates();

        $this->assertCount(2, $aggregates);

        $a = collect($aggregates)->firstWhere('group_name', 'parc-A');
        $this->assertSame(2, $a['total']);
        $this->assertSame(1, $a['success']);
        $this->assertSame(1, $a['failed']);

        $b = collect($aggregates)->firstWhere('group_name', 'parc-B');
        $this->assertSame(1, $b['total']);
        $this->assertSame(0, $b['success']); // pas de status reporté
    }
}
