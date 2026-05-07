<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppProfile;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\AppProfile\AppProfileService;
use App\Wpkg\Deployment\Events\AppProfileApplicationsChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationGroupChanged;
use App\Wpkg\Deployment\Events\WorkstationApplicationsChanged;
use App\Wpkg\Deployment\Events\WorkstationGroupApplicationsChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.4 / AC6, AC7.2 — Events dispatchés par les méthodes mutatrices
 * d'AppProfileService.
 *
 * Invariant AC6.3 : aucun event si la mutation échoue (transaction rollback).
 */
class AppProfileServiceEventsTest extends TestCase
{
    private AppProfileService $service;
    private AppProfile $profile;
    private Workstation $workstation;
    private WorkstationGroup $group;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        $this->service = new AppProfileService();

        $this->profile = AppProfile::create(['name' => 'p-1', 'is_active' => true]);
        $this->workstation = Workstation::create(['name' => 'PCT1', 'status' => 'active']);
        $this->group = WorkstationGroup::create(['name' => 'parc-1']);
        $this->app = Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function add_applications_dispatches_plural_event_with_attached_direction(): void
    {
        Event::fake([AppProfileApplicationsChanged::class]);

        $this->service->addApplications($this->profile->id, [$this->app->id]);

        Event::assertDispatched(AppProfileApplicationsChanged::class, function ($e) {
            return $e->appProfileId === $this->profile->id
                && $e->applicationIds === [$this->app->id]
                && $e->direction === 'attached';
        });
    }

    #[Test]
    public function remove_applications_dispatches_plural_event_with_detached_direction(): void
    {
        $this->profile->applications()->attach([$this->app->id]);
        Event::fake([AppProfileApplicationsChanged::class]);

        $this->service->removeApplications($this->profile->id, [$this->app->id]);

        Event::assertDispatched(AppProfileApplicationsChanged::class, function ($e) {
            return $e->direction === 'detached'
                && $e->applicationIds === [$this->app->id];
        });
    }

    #[Test]
    public function add_workstation_groups_dispatches_one_event_per_group(): void
    {
        $g2 = WorkstationGroup::create(['name' => 'parc-2']);
        Event::fake([AppProfileWorkstationGroupChanged::class]);

        $this->service->addWorkstationGroups($this->profile->id, [$this->group->id, $g2->id]);

        Event::assertDispatched(AppProfileWorkstationGroupChanged::class, 2);
        Event::assertDispatched(AppProfileWorkstationGroupChanged::class, function ($e) use ($g2) {
            return $e->workstationGroupId === $g2->id && $e->direction === 'attached';
        });
    }

    #[Test]
    public function add_workstations_dispatches_one_event_per_workstation(): void
    {
        $w2 = Workstation::create(['name' => 'PCT2', 'status' => 'active']);
        Event::fake([AppProfileWorkstationChanged::class]);

        $this->service->addWorkstations($this->profile->id, [$this->workstation->id, $w2->id]);

        Event::assertDispatched(AppProfileWorkstationChanged::class, 2);
    }

    #[Test]
    public function add_applications_to_workstation_group_dispatches_plural_event(): void
    {
        Event::fake([WorkstationGroupApplicationsChanged::class]);

        $attached = $this->service->addApplicationsToWorkstationGroup($this->group->id, [$this->app->id]);

        self::assertSame([$this->app->id], $attached);
        Event::assertDispatched(WorkstationGroupApplicationsChanged::class, function ($e) {
            return $e->workstationGroupId === $this->group->id
                && $e->direction === 'attached';
        });
    }

    #[Test]
    public function add_applications_to_workstation_group_idempotent_no_event_when_already_attached(): void
    {
        $this->group->applications()->attach([$this->app->id]);
        Event::fake([WorkstationGroupApplicationsChanged::class]);

        $attached = $this->service->addApplicationsToWorkstationGroup($this->group->id, [$this->app->id]);

        self::assertSame([], $attached);
        Event::assertNotDispatched(WorkstationGroupApplicationsChanged::class);
    }

    #[Test]
    public function add_applications_to_workstation_dispatches_plural_event(): void
    {
        Event::fake([WorkstationApplicationsChanged::class]);

        $attached = $this->service->addApplicationsToWorkstation($this->workstation->id, [$this->app->id]);

        self::assertSame([$this->app->id], $attached);
        Event::assertDispatched(WorkstationApplicationsChanged::class);
    }

    #[Test]
    public function remove_applications_from_workstation_dispatches_event_only_if_pivot_existed(): void
    {
        Event::fake([WorkstationApplicationsChanged::class]);

        // Pas de pivot → pas d'event.
        $count = $this->service->removeApplicationsFromWorkstation($this->workstation->id, [$this->app->id]);
        self::assertSame(0, $count);
        Event::assertNotDispatched(WorkstationApplicationsChanged::class);

        // Avec pivot → event détaché.
        $this->workstation->applications()->attach([$this->app->id]);
        $count = $this->service->removeApplicationsFromWorkstation($this->workstation->id, [$this->app->id]);
        self::assertSame(1, $count);
        Event::assertDispatched(WorkstationApplicationsChanged::class, function ($e) {
            return $e->direction === 'detached';
        });
    }

    #[Test]
    public function add_applications_does_not_dispatch_when_profile_missing(): void
    {
        Event::fake([AppProfileApplicationsChanged::class]);

        $ok = $this->service->addApplications(99999, [$this->app->id]);

        self::assertFalse($ok);
        Event::assertNotDispatched(AppProfileApplicationsChanged::class);
    }

    #[Test]
    public function add_applications_does_not_dispatch_when_ids_empty(): void
    {
        Event::fake([AppProfileApplicationsChanged::class]);
        $ok = $this->service->addApplications($this->profile->id, []);
        self::assertFalse($ok);
        Event::assertNotDispatched(AppProfileApplicationsChanged::class);
    }

    #[Test]
    public function clone_configuration_creates_deployment_row_and_dispatches_events(): void
    {
        $g2 = WorkstationGroup::create(['name' => 'parc-2']);
        $this->group->appProfiles()->attach([$this->profile->id]);
        $this->group->applications()->attach([$this->app->id]);

        // Crée le shim wpkg_deployments si absent (15.1 — non couvert par bootstrapper 15.2).
        if (! \Illuminate\Support\Facades\Schema::hasTable('wpkg_deployments')) {
            \Illuminate\Support\Facades\Schema::create('wpkg_deployments', function ($t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('triggered_by')->nullable();
                $t->timestamp('triggered_at')->nullable();
                $t->json('target_scope')->nullable();
                $t->string('status', 20)->default('pending');
                $t->json('summary')->nullable();
                $t->timestamps();
            });
        }

        Event::fake([
            AppProfileWorkstationGroupChanged::class,
            WorkstationGroupApplicationsChanged::class,
        ]);

        $result = $this->service->cloneConfiguration($this->group->id, $g2->id);

        self::assertNotEmpty($result['deployment_id']);
        self::assertSame([$this->profile->id], $result['profiles']['added']);
        self::assertSame([$this->app->id], $result['applications']['added']);

        // Ligne wpkg_deployments insérée
        self::assertSame(1, DB::table('wpkg_deployments')->count());

        // Events ciblés
        Event::assertDispatched(AppProfileWorkstationGroupChanged::class);
        Event::assertDispatched(WorkstationGroupApplicationsChanged::class, function ($e) use ($g2) {
            return $e->workstationGroupId === $g2->id
                && $e->direction === 'attached';
        });

        \Illuminate\Support\Facades\Schema::dropIfExists('wpkg_deployments');
    }

    #[Test]
    public function clone_configuration_rejects_same_source_and_target(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->cloneConfiguration($this->group->id, $this->group->id);
    }

    /**
     * Story 15.4 / AC6.3 + Correction post-review #1 + #3 :
     * Vérifie que les events sont dispatchés via DB::afterCommit, donc qu'un
     * rollback de la transaction parente empêche le dispatch.
     *
     * Pattern : on enveloppe l'appel service dans une transaction parente
     * volontairement rollback. Le service ouvre une transaction imbriquée
     * (savepoint) et planifie son event via DB::afterCommit. Comme la
     * transaction racine est rollback, afterCommit n'est jamais déclenché
     * et l'event n'est PAS dispatché.
     */
    #[Test]
    public function no_event_dispatched_if_transaction_rolls_back(): void
    {
        Event::fake([AppProfileApplicationsChanged::class]);

        DB::beginTransaction();
        try {
            $this->service->addApplications($this->profile->id, [$this->app->id]);
        } finally {
            DB::rollBack();
        }

        Event::assertNotDispatched(AppProfileApplicationsChanged::class);

        // Et le pivot doit être absent (rollback effectif).
        self::assertSame(0, $this->profile->applications()->count());
    }
}
