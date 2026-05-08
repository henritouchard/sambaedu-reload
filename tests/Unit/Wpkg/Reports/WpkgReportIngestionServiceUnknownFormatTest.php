<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Reports;

use App\Models\Application;
use App\Models\Workstation;
use App\Services\Windows\WpkgReportIngestionService;
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
 * Story 15.5 / AC2.3 + AC2.4 — Tests parser graceful (format inconnu, champs additionnels).
 */
final class WpkgReportIngestionServiceUnknownFormatTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('sambaedu.wpkg.reports_archive', sys_get_temp_dir() . '/wpkg-unknown-test');

        if (! Schema::hasTable('workstations')) {
            $this->createdTables = true;
            $this->createSchema();
        }
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

    private function createSchema(): void
    {
        // Tables nécessaires pour ActiveDeploymentForWorkstationQuery (eager-load).
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

        Schema::create('workstations', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
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

    #[Test]
    public function unknown_keys_dont_block_ingestion_and_log_warning(): void
    {
        Workstation::create(['name' => 'PC-UNK-01', 'status' => 'active']);
        Application::create(['app_id' => 'firefox', 'name' => 'firefox']);

        $reportContent = file_get_contents(base_path('tests/Fixtures/wpkg/reports/unknown-format.txt'));
        $this->assertNotFalse($reportContent);

        /** @var WpkgReportIngestionService $svc */
        $svc = app(WpkgReportIngestionService::class);
        $result = $svc->ingest('PC-UNK-01', $reportContent);

        $this->assertTrue($result->isProcessed());
        $this->assertSame(1, $result->packagesCount);
    }

    #[Test]
    public function additional_fields_are_captured_in_details(): void
    {
        $w = Workstation::create(['name' => 'PC-EXT-01', 'status' => 'active']);
        Application::create(['app_id' => 'firefox', 'name' => 'firefox']);
        Application::create(['app_id' => 'chromium', 'name' => 'chromium']);

        DB::table('wpkg_deployments')->insert([
            'id' => Str::uuid(),
            'triggered_by' => null,
            'triggered_at' => now(),
            'target_scope' => json_encode(['workstation_ids' => [$w->id]]),
            'status' => 'pending',
            'summary' => json_encode(['total_targets' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reportContent = file_get_contents(base_path('tests/Fixtures/wpkg/reports/with-error-and-extra-fields.txt'));
        $this->assertNotFalse($reportContent);

        /** @var WpkgReportIngestionService $svc */
        $svc = app(WpkgReportIngestionService::class);
        $svc->ingest('PC-EXT-01', $reportContent);

        $row = WpkgDeploymentWorkstationStatus::first();
        $this->assertNotNull($row);

        $details = is_array($row->details) ? $row->details : json_decode($row->details, true);
        $this->assertNotNull($details);
        $this->assertSame('partial', $row->client_status);

        // Vérifier les champs additionnels.
        $packages = $details['packages'] ?? [];
        $this->assertCount(2, $packages);

        $errorPkg = collect($packages)->firstWhere('id', 'chromium');
        $this->assertNotNull($errorPkg);
        $this->assertSame('1603', $errorPkg['error_code']);
        $this->assertSame(9876, $errorPkg['duration_ms']);
    }

    #[Test]
    public function malformed_report_returns_parse_failed(): void
    {
        Workstation::create(['name' => 'PC-MAL-01', 'status' => 'active']);

        // Header invalide (pas de date).
        $invalid = "garbage garbage garbage garbage\n";

        /** @var WpkgReportIngestionService $svc */
        $svc = app(WpkgReportIngestionService::class);
        $result = $svc->ingest('PC-MAL-01', $invalid);

        $this->assertTrue($result->isParseFailed());
    }
}
