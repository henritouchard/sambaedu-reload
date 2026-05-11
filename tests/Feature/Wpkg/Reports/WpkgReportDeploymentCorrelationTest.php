<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Reports;

use App\Models\Application;
use App\Models\Workstation;
use App\Wpkg\Deployment\Models\WpkgDeploymentWorkstationStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.5 / AC1.4 + AC6.1 — Tests Feature de la corrélation `deployment_id`
 * lors de l'ingestion d'un rapport.
 *
 * Couvre :
 *   - Rapport sur poste avec déploiement actif → ligne wpkg_deployment_workstation_status créée.
 *   - Rapport sur poste sans déploiement actif → uniquement workstation_application_status.
 *   - Recalcul `wpkg_deployments.summary` après ingestion.
 *   - Transition `pending → running` au premier rapport reçu.
 */
final class WpkgReportDeploymentCorrelationTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', '127.0.0.1,::1');
        Config::set('sambaedu.wpkg.reports_archive', sys_get_temp_dir() . '/wpkg-corr-test');

        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('wpkg_deployment_workstation_status');
            Schema::dropIfExists('wpkg_deployments');
            Schema::dropIfExists('workstation_application_status');
            Schema::dropIfExists('applications');
            Schema::dropIfExists('app_profile_workstation_group');
            Schema::dropIfExists('app_profile_workstation');
            Schema::dropIfExists('app_profiles');
            Schema::dropIfExists('workstation_group_workstation');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('workstations');
        }

        parent::tearDown();
    }

    private function createTablesIfNeeded(): void
    {
        if (Schema::hasTable('workstations')) {
            return;
        }

        $this->createdTables = true;

        Schema::create('workstations', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('status', 20)->default('active');
            $t->string('ip', 45)->nullable();
            $t->string('mac', 17)->nullable();
            $t->string('os', 100)->nullable();
            $t->timestamp('last_report_at')->nullable();
            $t->string('report_sha', 64)->nullable();
            $t->text('log_path')->nullable();
            $t->text('report_path')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });

        Schema::create('workstation_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });

        Schema::create('workstation_group_workstation', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workstation_id');
            $t->unsignedBigInteger('workstation_group_id');
            $t->timestamps();
        });

        Schema::create('app_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });

        Schema::create('app_profile_workstation', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('app_profile_id');
            $t->unsignedBigInteger('workstation_id');
            $t->timestamps();
        });

        Schema::create('app_profile_workstation_group', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('app_profile_id');
            $t->unsignedBigInteger('workstation_group_id');
            $t->timestamps();
        });

        Schema::create('applications', function (Blueprint $t) {
            $t->id();
            $t->string('app_id', 100)->unique();
            $t->string('name', 255)->nullable();
            $t->timestamps();
        });

        Schema::create('workstation_application_status', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workstation_id');
            $t->unsignedBigInteger('application_id');
            $t->string('installed_version', 255)->nullable();
            $t->string('status', 20);
            $t->boolean('reboot_required')->default(false);
            $t->timestamp('reported_at')->nullable();
            $t->text('message')->nullable();
            $t->timestamps();
            $t->unique(['workstation_id', 'application_id']);
        });

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
    }

    private function postReport(string $hostname, string $body): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            "/api/wpkg/reports/{$hostname}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $body,
        );
    }

    private function buildReport(string $hostname): string
    {
        return "2026-05-08 09:30:00 {$hostname} AA:BB:CC:DD:EE:FF [10.0.0.42]\n"
            . "ID: firefox\nRevision: 115.0\nReboot: false\nStatus: Installed\n---\n";
    }

    private function buildReportWithError(string $hostname): string
    {
        return "2026-05-08 09:30:00 {$hostname} AA:BB:CC:DD:EE:FF [10.0.0.42]\n"
            . "ID: firefox\nRevision: 115.0\nReboot: false\nStatus: Installed\n---\n"
            . "ID: chromium\nRevision: 120.0\nReboot: false\nStatus: Error\nDuration: 12345\nErrorCode: 1603\n---\n";
    }

    private function makeWorkstation(string $hostname): Workstation
    {
        return Workstation::create([
            'name' => $hostname,
            'status' => 'active',
        ]);
    }

    private function insertDeployment(array $scope, string $status = 'pending', array $summary = []): string
    {
        $id = (string) Str::uuid();
        DB::table('wpkg_deployments')->insert([
            'id' => $id,
            'triggered_by' => null,
            'triggered_at' => now(),
            'target_scope' => json_encode($scope),
            'status' => $status,
            'summary' => json_encode($summary),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    #[Test]
    public function spontaneous_report_has_no_deployment_correlation(): void
    {
        $w = $this->makeWorkstation('PC-CORR-01');
        Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);

        $response = $this->postReport($w->name, $this->buildReport($w->name));
        $response->assertStatus(200);

        $this->assertSame(0, WpkgDeploymentWorkstationStatus::count());
    }

    #[Test]
    public function correlated_report_creates_status_row_and_updates_summary(): void
    {
        $w = $this->makeWorkstation('PC-CORR-02');
        Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);

        $deploymentId = $this->insertDeployment(
            ['workstation_ids' => [$w->id]],
            'pending',
            ['total_targets' => 1],
        );

        $response = $this->postReport($w->name, $this->buildReport($w->name));
        $response->assertStatus(200);

        $row = WpkgDeploymentWorkstationStatus::first();
        $this->assertNotNull($row);
        $this->assertSame($deploymentId, $row->deployment_id);
        $this->assertSame($w->id, $row->workstation_id);
        $this->assertSame('success', $row->client_status);

        $deployment = DB::table('wpkg_deployments')->where('id', $deploymentId)->first();
        $summary = json_decode($deployment->summary, true);
        $this->assertSame(1, $summary['reported']);
        $this->assertSame(1, $summary['success']);
    }

    #[Test]
    public function pending_to_running_transition_at_first_report(): void
    {
        $w = $this->makeWorkstation('PC-CORR-03');
        Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);

        $deploymentId = $this->insertDeployment(
            ['workstation_ids' => [$w->id]],
            'pending',
            ['total_targets' => 2], // un autre poste pas encore reporté
        );

        $this->postReport($w->name, $this->buildReport($w->name))->assertStatus(200);

        $deployment = DB::table('wpkg_deployments')->where('id', $deploymentId)->first();
        $this->assertSame('running', $deployment->status);
    }

    #[Test]
    public function running_to_completed_transition_when_all_reported(): void
    {
        $w = $this->makeWorkstation('PC-CORR-04');
        Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);

        $deploymentId = $this->insertDeployment(
            ['workstation_ids' => [$w->id]],
            'running',
            ['total_targets' => 1],
        );

        $this->postReport($w->name, $this->buildReport($w->name))->assertStatus(200);

        $deployment = DB::table('wpkg_deployments')->where('id', $deploymentId)->first();
        $this->assertSame('completed', $deployment->status);
    }

    #[Test]
    public function client_status_failed_when_all_packages_in_error(): void
    {
        $w = $this->makeWorkstation('PC-CORR-05');
        Application::create(['app_id' => 'chromium', 'name' => 'Chromium']);

        $this->insertDeployment(
            ['workstation_ids' => [$w->id]],
            'pending',
            ['total_targets' => 1],
        );

        $errorOnly = "2026-05-08 09:30:00 PC-CORR-05 AA:BB:CC:DD:EE:FF\n"
            . "ID: chromium\nRevision: 120.0\nReboot: false\nStatus: Error\nErrorCode: 1603\n---\n";

        $this->postReport($w->name, $errorOnly)->assertStatus(200);

        $row = WpkgDeploymentWorkstationStatus::first();
        $this->assertSame('failed', $row->client_status);
    }

    #[Test]
    public function client_status_partial_when_mixed(): void
    {
        $w = $this->makeWorkstation('PC-CORR-06');
        Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);
        Application::create(['app_id' => 'chromium', 'name' => 'Chromium']);

        $this->insertDeployment(
            ['workstation_ids' => [$w->id]],
            'pending',
            ['total_targets' => 1],
        );

        $this->postReport($w->name, $this->buildReportWithError($w->name))->assertStatus(200);

        $row = WpkgDeploymentWorkstationStatus::first();
        $this->assertSame('partial', $row->client_status);
        $details = is_array($row->details) ? $row->details : json_decode($row->details, true);
        $this->assertArrayHasKey('counters', $details);
        $this->assertSame(1, $details['counters']['failed']);
        $this->assertSame(1, $details['counters']['success']);
    }

    #[Test]
    public function archive_path_is_recorded_in_details(): void
    {
        $w = $this->makeWorkstation('PC-CORR-07');
        Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);

        $this->insertDeployment(
            ['workstation_ids' => [$w->id]],
            'pending',
            ['total_targets' => 1],
        );

        $this->postReport($w->name, $this->buildReport($w->name))->assertStatus(200);

        $row = WpkgDeploymentWorkstationStatus::first();
        $details = is_array($row->details) ? $row->details : json_decode($row->details, true);
        $this->assertArrayHasKey('report_archive_path', $details);
    }
}
