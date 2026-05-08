<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Dashboard;

use App\Models\AppProfile;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Services\WpkgDashboardQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.5 / Fix #12 — Tests des agrégats par profil.
 *
 * Vérifie spécifiquement que la jointure restructurée en UNION ALL
 * exclut bien les workstations archivées (lien direct ou via groupe).
 */
final class DashboardProfileAggregatesTest extends TestCase
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

    #[Test]
    public function profile_aggregates_excludes_archived_workstations_via_group(): void
    {
        $profile = AppProfile::create(['name' => 'profile-archived-test', 'is_active' => true]);

        // 1 poste actif lié direct au profil
        $wActive = Workstation::create(['name' => 'PC-ACTIVE-DIRECT', 'status' => 'active']);
        DB::table('app_profile_workstation')->insert([
            'app_profile_id' => $profile->id,
            'workstation_id' => $wActive->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1 poste archivé lié au profil VIA un groupe
        $group = WorkstationGroup::create(['name' => 'parc-mixte', 'is_active' => true]);
        DB::table('app_profile_workstation_group')->insert([
            'app_profile_id' => $profile->id,
            'workstation_group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wArchived = Workstation::create([
            'name' => 'PC-ARCHIVED',
            'status' => 'active',
            'archived_at' => now(),
        ]);
        DB::table('workstation_group_workstation')->insert([
            'workstation_id' => $wArchived->id,
            'workstation_group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = new WpkgDashboardQueryService();
        $aggregates = $svc->profileAggregates();

        $row = collect($aggregates)->firstWhere('profile_name', 'profile-archived-test');
        $this->assertNotNull($row);
        $this->assertSame(
            1,
            $row['total'],
            'profileAggregates() ne doit compter que le poste actif (lien direct), pas l\'archivé via groupe.',
        );
    }

    #[Test]
    public function profile_aggregates_dedups_workstation_via_direct_and_group(): void
    {
        $profile = AppProfile::create(['name' => 'profile-dedup', 'is_active' => true]);
        $group = WorkstationGroup::create(['name' => 'parc-dedup', 'is_active' => true]);
        DB::table('app_profile_workstation_group')->insert([
            'app_profile_id' => $profile->id,
            'workstation_group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Même workstation liée direct ET via groupe → doit compter 1.
        $w = Workstation::create(['name' => 'PC-DUAL', 'status' => 'active']);
        DB::table('app_profile_workstation')->insert([
            'app_profile_id' => $profile->id,
            'workstation_id' => $w->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('workstation_group_workstation')->insert([
            'workstation_id' => $w->id,
            'workstation_group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = new WpkgDashboardQueryService();
        $aggregates = $svc->profileAggregates();

        $row = collect($aggregates)->firstWhere('profile_name', 'profile-dedup');
        $this->assertNotNull($row);
        $this->assertSame(1, $row['total'], 'COUNT(DISTINCT workstation_id) doit dédupliquer le poste lié 2 fois.');
    }
}
