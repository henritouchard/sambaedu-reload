<?php

declare(strict_types=1);

namespace Tests\Feature\AppProfile;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\AppProfile\AppProfileService;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 29.1 — Defense-in-depth : enforcement WPKG en couche service.
 *
 * Couvre AC #6 :
 *  - sous actingAs(délégué de A) : addApplicationsToWorkstationGroup(A) OK,
 *    addApplicationsToWorkstationGroup(B) lève AuthorizationException ;
 *  - sans utilisateur authentifié (Auth::check()===false) : aucune exception,
 *    la mutation s'exécute (non-régression agent/console/seed).
 *
 * Piège SQLite (mémoire projet) : on teste des décisions (exception/exécution),
 * pas des bornes de colonnes.
 */
class AppProfileServiceWpkgScopingTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private AppProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        Queue::fake();
        // Isole le test du listener WPKG (InvalidateWorkstationPackagesCache) et
        // des observers : seul le garde d'autorisation + l'écriture pivot sont
        // exercés.
        Event::fake();
        WorkstationGroupObserver::disableSync();

        $this->createPermissionSchema();
        (new PermissionSeeder())->run();
        $this->createWpkgPivotSchema();

        $this->service = app(AppProfileService::class);
    }

    protected function tearDown(): void
    {
        $this->dropWpkgPivotSchema();
        WorkstationGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function createWpkgPivotSchema(): void
    {
        if (!Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $table) {
                $table->id();
                $table->string('app_id')->nullable();
                $table->string('name')->nullable();
                $table->string('version')->nullable();
                $table->string('category')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('application_workstation_group')) {
            Schema::create('application_workstation_group', function (Blueprint $table) {
                $table->unsignedBigInteger('application_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->timestamps();
                $table->primary(['application_id', 'workstation_group_id'], 'awg_primary');
            });
        }
        // MANQUÉ-1 (review 29.1) : schéma minimal pour le chemin profil→poste.
        if (!Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('uuid')->nullable();
                $table->string('mac')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $table) {
                $table->unsignedBigInteger('workstation_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->timestamps();
                $table->primary(['workstation_id', 'workstation_group_id'], 'wgw_primary');
            });
        }
        if (!Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('app_profile_workstation')) {
            Schema::create('app_profile_workstation', function (Blueprint $table) {
                $table->unsignedBigInteger('app_profile_id');
                $table->unsignedBigInteger('workstation_id');
                $table->timestamps();
                $table->primary(['app_profile_id', 'workstation_id'], 'apw_primary');
            });
        }
        // Story 31.1 — le garde catalogue (assertApplicationsInUpstreamCatalog) résout
        // ControlHubContract::active() ; table requise même si vide (court-circuit NFR3
        // → null → aucun bornage, comportement 29.1 inchangé).
        if (!Schema::hasTable('controlhub_contracts')) {
            Schema::create('controlhub_contracts', function (Blueprint $table) {
                $table->id();
                $table->string('link_state')->default('active');
                $table->timestamp('received_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function dropWpkgPivotSchema(): void
    {
        Schema::dropIfExists('controlhub_contracts');
        Schema::dropIfExists('app_profile_workstation');
        Schema::dropIfExists('app_profiles');
        Schema::dropIfExists('workstation_group_workstation');
        Schema::dropIfExists('workstations');
        Schema::dropIfExists('application_workstation_group');
        Schema::dropIfExists('applications');
    }

    private function makeUser(string $login, array $perms = []): User
    {
        $user = User::create(['login' => $login, 'role' => 'autre', 'is_active' => true]);
        foreach ($perms as $p) {
            $user->givePermissionTo($p);
        }
        return $user;
    }

    private function makeGroup(string $name): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => $name,
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    private function makeApp(string $appId): Application
    {
        return Application::create(['app_id' => $appId, 'name' => $appId]);
    }

    #[Test]
    public function delegate_of_a_can_assign_on_a(): void
    {
        $delegate = $this->makeUser('delegate');
        $salleA = $this->makeGroup('salle_a');
        $app = $this->makeApp('firefox');

        app(PermissionService::class)->grantDelegation($delegate, 'wpkg.assign', $salleA);

        $this->actingAs($delegate);

        $attached = $this->service->addApplicationsToWorkstationGroup($salleA->id, [$app->id]);

        $this->assertSame([$app->id], $attached, 'AC#6 : assignation autorisée dans le périmètre');
        $this->assertDatabaseHas('application_workstation_group', [
            'application_id' => $app->id,
            'workstation_group_id' => $salleA->id,
        ]);
    }

    #[Test]
    public function delegate_of_a_cannot_assign_on_b(): void
    {
        $delegate = $this->makeUser('delegate');
        $salleA = $this->makeGroup('salle_a');
        $salleB = $this->makeGroup('salle_b');
        $app = $this->makeApp('firefox');

        app(PermissionService::class)->grantDelegation($delegate, 'wpkg.assign', $salleA);

        $this->actingAs($delegate);

        $this->expectException(AuthorizationException::class);

        try {
            $this->service->addApplicationsToWorkstationGroup($salleB->id, [$app->id]);
        } finally {
            // Aucune écriture ne doit avoir eu lieu (garde AVANT la transaction).
            $this->assertDatabaseMissing('application_workstation_group', [
                'application_id' => $app->id,
                'workstation_group_id' => $salleB->id,
            ]);
        }
    }

    #[Test]
    public function delegate_of_a_can_attach_profile_to_workstation_in_a(): void
    {
        // MANQUÉ-1 (review 29.1) : le chemin profil→poste matérialise une assignation
        // WPKG par-poste, scopée sur la salle physique du poste.
        $delegate = $this->makeUser('delegate');
        $salleA = $this->makeGroup('salle_a');
        $wsA = Workstation::factory()->create();
        $wsA->groups()->attach($salleA->id); // salle physique → physicalRoom = salleA
        $profile = AppProfile::create(['name' => 'profil_test']);

        app(PermissionService::class)->grantDelegation($delegate, 'wpkg.assign', $salleA);
        $this->actingAs($delegate);

        $ok = $this->service->addWorkstations($profile->id, [$wsA->id]);

        $this->assertTrue($ok, 'AC#6 : attache autorisée dans le périmètre du délégué');
        $this->assertDatabaseHas('app_profile_workstation', [
            'app_profile_id' => $profile->id,
            'workstation_id' => $wsA->id,
        ]);
    }

    #[Test]
    public function delegate_of_a_cannot_attach_profile_to_workstation_in_b(): void
    {
        // MANQUÉ-1 : un délégué de la salle A ne peut PAS attacher un profil d'apps
        // à un poste de la salle B (gate scopé sur physicalRoom). Avant le correctif,
        // ce chemin contournait entièrement le verrou WPKG.
        $delegate = $this->makeUser('delegate');
        $salleA = $this->makeGroup('salle_a');
        $salleB = $this->makeGroup('salle_b');
        $wsB = Workstation::factory()->create();
        $wsB->groups()->attach($salleB->id);
        $profile = AppProfile::create(['name' => 'profil_test']);

        app(PermissionService::class)->grantDelegation($delegate, 'wpkg.assign', $salleA);
        $this->actingAs($delegate);

        $this->expectException(AuthorizationException::class);

        try {
            $this->service->addWorkstations($profile->id, [$wsB->id]);
        } finally {
            // Garde AVANT la transaction → aucune écriture pivot.
            $this->assertDatabaseMissing('app_profile_workstation', [
                'app_profile_id' => $profile->id,
                'workstation_id' => $wsB->id,
            ]);
        }
    }

    #[Test]
    public function unauthenticated_caller_is_not_blocked(): void
    {
        // AC#6 — appelant non-web (console/agent/seed) : Auth::check()===false →
        // aucun contrôle, la mutation s'exécute (non-régression).
        $salleB = $this->makeGroup('salle_b');
        $app = $this->makeApp('firefox');

        $this->assertFalse(auth()->check(), 'pré-condition : aucun utilisateur authentifié');

        $attached = $this->service->addApplicationsToWorkstationGroup($salleB->id, [$app->id]);

        $this->assertSame([$app->id], $attached, 'AC#6 : non-régression appelant non authentifié');
        $this->assertDatabaseHas('application_workstation_group', [
            'application_id' => $app->id,
            'workstation_group_id' => $salleB->id,
        ]);
    }
}
