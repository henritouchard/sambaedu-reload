<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Queries;

use App\Models\AppProfile;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Models\WpkgDeployment;
use App\Wpkg\Deployment\Queries\ActiveDeploymentForWorkstationQuery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.5 / AC1.5 — Tests `ActiveDeploymentForWorkstationQuery`.
 *
 * Couvre les 3 axes de matching :
 *   - workstation_ids
 *   - group_ids (et alias legacy `workstation_group_ids` 15.4)
 *   - profile_ids
 *
 * + cas d'ambiguïté : 2+ déploiements actifs → log warning, retourne le
 *   plus récent.
 */
final class ActiveDeploymentForWorkstationQueryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        WpkgSchemaBootstrapper::bootstrap();

        // Bootstrap des tables 15.1 manquantes pour ce test.
        if (! Schema::hasTable('wpkg_deployments')) {
            Schema::create('wpkg_deployments', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('triggered_by')->nullable();
                $t->timestamp('triggered_at');
                $t->json('target_scope')->nullable();
                $t->string('status', 20)->default('pending');
                $t->json('summary')->nullable();
                $t->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wpkg_deployments');
        WpkgSchemaBootstrapper::tearDown();

        parent::tearDown();
    }

    private function makeWorkstation(string $name = 'PC-X'): Workstation
    {
        return Workstation::create([
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function makeGroup(string $name = 'parc-x'): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function makeProfile(string $name = 'profile-x'): AppProfile
    {
        return AppProfile::create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function insertDeployment(array $scope, string $status = 'pending', mixed $when = null): string
    {
        $id = (string) Str::uuid();
        DB::table('wpkg_deployments')->insert([
            'id' => $id,
            'triggered_by' => null,
            'triggered_at' => $when ?? now(),
            'target_scope' => json_encode($scope),
            'status' => $status,
            'summary' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    #[Test]
    public function returns_null_when_no_deployment_active(): void
    {
        $w = $this->makeWorkstation();
        $query = new ActiveDeploymentForWorkstationQuery();

        $this->assertNull($query->find($w->id));
    }

    #[Test]
    public function matches_via_workstation_ids(): void
    {
        $w = $this->makeWorkstation();
        $id = $this->insertDeployment(['workstation_ids' => [$w->id]]);

        $query = new ActiveDeploymentForWorkstationQuery();
        $result = $query->find($w->id);

        $this->assertNotNull($result);
        $this->assertSame($id, $result->id);
    }

    #[Test]
    public function matches_via_group_ids_with_legacy_workstation_group_ids_key(): void
    {
        $w = $this->makeWorkstation();
        $g = $this->makeGroup();
        DB::table('workstation_group_workstation')->insert([
            'workstation_id' => $w->id,
            'workstation_group_id' => $g->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 15.4 utilise `workstation_group_ids` — la query doit l'accepter.
        $id = $this->insertDeployment(['workstation_group_ids' => [$g->id]]);

        $query = new ActiveDeploymentForWorkstationQuery();
        $result = $query->find($w->id);

        $this->assertNotNull($result);
        $this->assertSame($id, $result->id);
    }

    #[Test]
    public function matches_via_profile_ids_direct_assignment(): void
    {
        $w = $this->makeWorkstation();
        $p = $this->makeProfile();
        DB::table('app_profile_workstation')->insert([
            'workstation_id' => $w->id,
            'app_profile_id' => $p->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = $this->insertDeployment(['profile_ids' => [$p->id]]);

        $query = new ActiveDeploymentForWorkstationQuery();
        $result = $query->find($w->id);

        $this->assertNotNull($result);
        $this->assertSame($id, $result->id);
    }

    #[Test]
    public function matches_via_profile_ids_inherited_through_group(): void
    {
        $w = $this->makeWorkstation();
        $g = $this->makeGroup();
        $p = $this->makeProfile();

        DB::table('workstation_group_workstation')->insert([
            'workstation_id' => $w->id,
            'workstation_group_id' => $g->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('app_profile_workstation_group')->insert([
            'app_profile_id' => $p->id,
            'workstation_group_id' => $g->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = $this->insertDeployment(['profile_ids' => [$p->id]]);

        $query = new ActiveDeploymentForWorkstationQuery();
        $result = $query->find($w->id);

        $this->assertNotNull($result);
        $this->assertSame($id, $result->id);
    }

    #[Test]
    public function ignores_completed_deployments(): void
    {
        $w = $this->makeWorkstation();
        $this->insertDeployment(['workstation_ids' => [$w->id]], 'completed');

        $query = new ActiveDeploymentForWorkstationQuery();

        $this->assertNull($query->find($w->id));
    }

    #[Test]
    public function ambiguous_returns_most_recent(): void
    {
        $w = $this->makeWorkstation();
        $oldId = $this->insertDeployment(
            ['workstation_ids' => [$w->id]],
            'pending',
            now()->subHours(2),
        );
        $newId = $this->insertDeployment(
            ['workstation_ids' => [$w->id]],
            'pending',
            now(),
        );

        $query = new ActiveDeploymentForWorkstationQuery();
        $result = $query->find($w->id);

        $this->assertNotNull($result);
        $this->assertSame($newId, $result->id);
        $this->assertNotSame($oldId, $result->id);
    }
}
