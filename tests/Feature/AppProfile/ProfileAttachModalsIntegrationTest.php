<?php

declare(strict_types=1);

namespace Tests\Feature\AppProfile;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Events\AppProfileApplicationsChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationGroupChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.4 / Correction post-review #5 — Test d'intégration Livewire pour
 * la page `parc-settings/profiles/index`.
 *
 * Complémentaire à `ProfileAttachModalsExtractionTest` (qui ne vérifie que
 * la structure des fichiers). Ici on monte le composant Livewire, on appelle
 * les 3 méthodes d'attachement (apps, groups, workstations) et on assert que
 * les events appropriés sont dispatchés via `AppProfileService`.
 *
 * Périmètre minimal : 1 test par modale (3 tests).
 */
class ProfileAttachModalsIntegrationTest extends TestCase
{
    private AppProfile $profile;
    private WorkstationGroup $group;
    private Workstation $workstation;
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        // Tables minimales + neutralisation des observers AD.
        WpkgSchemaBootstrapper::bootstrap();

        // Permission `wpkg.assign` pas requise par le composant
        // parc-settings/profiles (Décision permissions Story 15.4 :
        // wpkg.assign est utilisé par les pages parc/groups & parc/machines,
        // pas par parc-settings/profiles). On bypass tout de même les
        // gates pour éviter un crash si une protection latente est
        // ajoutée plus tard.
        Gate::before(fn () => true);

        $this->profile = AppProfile::create([
            'name' => 'integration-test-profile',
            'is_active' => true,
        ]);
        $this->application = Application::create([
            'app_id' => 'firefox',
            'name' => 'Firefox',
        ]);
        $this->group = WorkstationGroup::create(['name' => 'parc-integration']);
        $this->workstation = Workstation::create([
            'name' => 'PCT-INT-1',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function add_selected_apps_dispatches_app_profile_applications_changed_event(): void
    {
        Event::fake([AppProfileApplicationsChanged::class]);

        Livewire::test('pages::parc-settings.profiles.index', ['id' => $this->profile->id])
            ->set('selectedAppsToAdd', [$this->application->id])
            ->call('addSelectedApps');

        Event::assertDispatched(AppProfileApplicationsChanged::class, function ($e) {
            return $e->appProfileId === $this->profile->id
                && $e->direction === 'attached'
                && in_array($this->application->id, $e->applicationIds, true);
        });

        // Persistance effective.
        self::assertSame(1, $this->profile->applications()->count());
    }

    #[Test]
    public function add_selected_groups_dispatches_app_profile_workstation_group_changed_event(): void
    {
        Event::fake([AppProfileWorkstationGroupChanged::class]);

        Livewire::test('pages::parc-settings.profiles.index', ['id' => $this->profile->id])
            ->set('selectedGroupsToAdd', [$this->group->id])
            ->call('addSelectedGroups');

        Event::assertDispatched(AppProfileWorkstationGroupChanged::class, function ($e) {
            return $e->appProfileId === $this->profile->id
                && $e->workstationGroupId === $this->group->id
                && $e->direction === 'attached';
        });

        self::assertSame(1, $this->profile->workstationGroups()->count());
    }

    #[Test]
    public function add_selected_workstations_dispatches_app_profile_workstation_changed_event(): void
    {
        Event::fake([AppProfileWorkstationChanged::class]);

        Livewire::test('pages::parc-settings.profiles.index', ['id' => $this->profile->id])
            ->set('selectedWorkstationsToAdd', [$this->workstation->id])
            ->call('addSelectedWorkstations');

        Event::assertDispatched(AppProfileWorkstationChanged::class, function ($e) {
            return $e->appProfileId === $this->profile->id
                && $e->workstationId === $this->workstation->id
                && $e->direction === 'attached';
        });

        self::assertSame(1, $this->profile->workstations()->count());
    }
}
