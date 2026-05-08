<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Services;

use App\Models\Application;
use App\Models\AppProfile;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Windows\WpkgReportIngestionService;
use App\Wpkg\Deployment\Models\WpkgDeployment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.5 / Fix #9 — Vérifie que `WpkgReportIngestionService::guessTotalTargets()`
 * fait bien un fanout DB sur `target_scope.group_ids` (et `profile_ids`),
 * et ne retombe plus à 0 quand seuls les groupes sont dans le scope.
 *
 * Cas testé :
 *   - 1 déploiement `pending` avec `target_scope = {"group_ids": [X]}` où X
 *     est un parc de 5 postes.
 *   - 1 rapport WPKG ingéré.
 *   - Attendu : `total_targets = 5`, `reported = 1`,
 *     `status = running` (PAS `completed`).
 *
 * Avant le fix, `guessTotalTargets()` ne comptait que `workstation_ids` direct
 * → total_targets = 0 → `reported >= total_targets` → status = completed
 * (incorrect, mais bloqué par `total_targets > 0` check).
 * Néanmoins le summary remontait `total_targets = max(reported, 0) = 1` ce qui
 * était trompeur dans l'UI (1 reported / 1 target).
 */
final class WpkgReportIngestionServiceTotalTargetsTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Évite les jobs LDAP/AD synchrones déclenchés par les observers (AppProfileObserver).
        Queue::fake();
        Bus::fake();

        Config::set('sambaedu.wpkg.reports_archive', sys_get_temp_dir() . '/wpkg-fanout-test');

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
    public function group_targets_are_fanned_out_to_workstation_count(): void
    {
        // Parc de 5 postes
        $group = WorkstationGroup::create(['name' => 'parc-fanout', 'is_active' => true]);
        $workstations = [];
        for ($i = 1; $i <= 5; $i++) {
            $w = Workstation::create(['name' => "PC-FAN-{$i}", 'status' => 'active']);
            DB::table('workstation_group_workstation')->insert([
                'workstation_id' => $w->id,
                'workstation_group_id' => $group->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $workstations[] = $w;
        }

        // Application requise pour parser le rapport sans warning.
        Application::create(['app_id' => 'firefox', 'name' => 'firefox']);

        // Déploiement pending avec target_scope = group_ids
        $deploymentId = (string) Str::uuid();
        DB::table('wpkg_deployments')->insert([
            'id' => $deploymentId,
            'triggered_by' => null,
            'triggered_at' => now(),
            'target_scope' => json_encode(['group_ids' => [$group->id]]),
            'status' => 'pending',
            'summary' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Premier rapport reçu (sur PC-FAN-1)
        $report = "2026-05-08 10:00:00 PC-FAN-1 00:11:22:33:44:55 [192.168.1.10]\n"
                . "ID: firefox\n"
                . "Revision: 100\n"
                . "Reboot: false\n"
                . "Status: Installed\n"
                . "---\n";

        /** @var WpkgReportIngestionService $svc */
        $svc = app(WpkgReportIngestionService::class);
        $result = $svc->ingest('PC-FAN-1', $report);

        $this->assertTrue($result->isProcessed(), 'Le rapport doit avoir été ingéré.');

        $deployment = WpkgDeployment::find($deploymentId);
        $this->assertNotNull($deployment);

        $summary = is_array($deployment->summary) ? $deployment->summary : [];

        $this->assertSame(5, $summary['total_targets'] ?? null, 'total_targets doit être 5 (5 postes du groupe).');
        $this->assertSame(1, $summary['reported'] ?? null, 'reported doit être 1 (1 rapport reçu).');

        // Status doit rester running (1/5 reported), PAS completed.
        $this->assertSame(WpkgDeployment::STATUS_RUNNING, $deployment->status);
    }

    #[Test]
    public function profile_targets_via_groups_are_fanned_out(): void
    {
        // Profil avec 1 groupe direct (3 postes) + 1 lien direct (2 postes).
        $profile = AppProfile::create(['name' => 'profile-fanout', 'is_active' => true]);
        $group = WorkstationGroup::create(['name' => 'parc-via-profile', 'is_active' => true]);
        DB::table('app_profile_workstation_group')->insert([
            'app_profile_id' => $profile->id,
            'workstation_group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $w = Workstation::create(['name' => "PC-PROFG-{$i}", 'status' => 'active']);
            DB::table('workstation_group_workstation')->insert([
                'workstation_id' => $w->id,
                'workstation_group_id' => $group->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        for ($i = 1; $i <= 2; $i++) {
            $w = Workstation::create(['name' => "PC-PROFD-{$i}", 'status' => 'active']);
            DB::table('app_profile_workstation')->insert([
                'app_profile_id' => $profile->id,
                'workstation_id' => $w->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Application::create(['app_id' => 'firefox', 'name' => 'firefox']);

        $deploymentId = (string) Str::uuid();
        DB::table('wpkg_deployments')->insert([
            'id' => $deploymentId,
            'triggered_by' => null,
            'triggered_at' => now(),
            'target_scope' => json_encode(['profile_ids' => [$profile->id]]),
            'status' => 'pending',
            'summary' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = "2026-05-08 10:00:00 PC-PROFG-1 00:11:22:33:44:55 [192.168.1.10]\n"
                . "ID: firefox\n"
                . "Revision: 100\n"
                . "Reboot: false\n"
                . "Status: Installed\n"
                . "---\n";

        /** @var WpkgReportIngestionService $svc */
        $svc = app(WpkgReportIngestionService::class);
        $svc->ingest('PC-PROFG-1', $report);

        $deployment = WpkgDeployment::find($deploymentId);
        $summary = is_array($deployment->summary) ? $deployment->summary : [];

        $this->assertSame(5, $summary['total_targets'] ?? null, 'total_targets = 3 (groupe) + 2 (direct) = 5 (déduplication ok car distincts).');
    }
}
