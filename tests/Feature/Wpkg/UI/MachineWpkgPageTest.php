<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\UI;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\AppProfile\AppProfileService;
use App\Wpkg\Deployment\Events\AppProfileWorkstationChanged;
use App\Wpkg\Deployment\Events\WorkstationApplicationsChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.4 / AC2, AC7.1 — Onglet WPKG sur la page poste.
 */
class MachineWpkgPageTest extends TestCase
{
    private Workstation $workstation;
    private WorkstationGroup $group;
    private AppProfile $profile;
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        WpkgSchemaBootstrapper::bootstrap();
        Gate::define('wpkg.assign', fn ($user) => true);

        $this->workstation = Workstation::create(['name' => 'PCT1', 'status' => 'active']);
        $this->group = WorkstationGroup::create(['name' => 'parc-1']);
        $this->workstation->groups()->attach($this->group);
        $this->profile = AppProfile::create(['name' => 'p-1', 'is_active' => true]);
        $this->application = Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function attach_profile_directly_to_workstation_dispatches_event(): void
    {
        Event::fake([AppProfileWorkstationChanged::class]);

        $svc = app(AppProfileService::class);
        $svc->addWorkstations($this->profile->id, [$this->workstation->id]);

        Event::assertDispatched(AppProfileWorkstationChanged::class, function ($e) {
            return $e->workstationId === $this->workstation->id
                && $e->direction === 'attached';
        });
    }

    #[Test]
    public function attach_application_directly_to_workstation_dispatches_event(): void
    {
        Event::fake([WorkstationApplicationsChanged::class]);

        $svc = app(AppProfileService::class);
        $svc->addApplicationsToWorkstation($this->workstation->id, [$this->application->id]);

        Event::assertDispatched(WorkstationApplicationsChanged::class);
        self::assertSame(1, $this->workstation->applications()->count());
    }

    #[Test]
    public function inherited_vs_direct_profile_can_coexist(): void
    {
        // Profil hérité via parc.
        $this->profile->workstationGroups()->attach($this->group->id);
        // Profil direct (override).
        $this->profile->workstations()->attach($this->workstation->id);

        $this->workstation->load(['appProfiles', 'groups.appProfiles']);

        // Les deux sources sont présentes — la dédup côté UI est laissée au composant
        // (cf. computed `wpkgAttachedProfiles` qui dédupplique).
        self::assertTrue($this->workstation->appProfiles->contains('id', $this->profile->id));
        self::assertTrue($this->workstation->groups->first()->appProfiles->contains('id', $this->profile->id));
    }
}
