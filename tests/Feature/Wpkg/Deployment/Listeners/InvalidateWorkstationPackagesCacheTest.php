<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment\Listeners;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Events\AppProfileApplicationChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationGroupChanged;
use App\Wpkg\Deployment\Events\WorkstationActivated;
use App\Wpkg\Deployment\Events\WorkstationArchived;
use App\Wpkg\Deployment\Events\WorkstationGroupMembershipChanged;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.2 / AC4.4, AC7.4 — Listener générique InvalidateWorkstationPackagesCache.
 *
 * Vérifie qu'un dispatch d'event impactant un poste invalide bien la clé cache
 * `wpkg:packages:{lower(hostname)}` correspondante.
 */
class InvalidateWorkstationPackagesCacheTest extends TestCase
{
    private Workstation $workstation;
    private WorkstationGroup $group;
    private AppProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();

        $this->workstation = Workstation::create(['name' => 'PCT1', 'status' => 'active']);
        $this->group = WorkstationGroup::create(['name' => 'parc-1']);
        $this->workstation->groups()->attach($this->group);
        $this->profile = AppProfile::create(['name' => 'profile-1', 'is_active' => true]);
        $this->profile->workstations()->attach([$this->workstation->id]);
        $this->profile->workstationGroups()->attach([$this->group->id]);
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    private function primeCache(string $hostname): void
    {
        Cache::put(
            WorkstationPackagesResolver::cacheKey($hostname),
            collect(['stale']),
            1000,
        );
    }

    #[Test]
    public function workstation_group_membership_changed_invalidates_target(): void
    {
        $this->primeCache('PCT1');

        event(new WorkstationGroupMembershipChanged($this->workstation->id, $this->group->id, 'joined'));

        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCT1')));
    }

    #[Test]
    public function workstation_activated_invalidates_target(): void
    {
        $this->primeCache('PCT1');

        event(new WorkstationActivated($this->workstation->id));

        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCT1')));
    }

    #[Test]
    public function workstation_archived_invalidates_target(): void
    {
        $this->primeCache('PCT1');

        event(new WorkstationArchived($this->workstation->id));

        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCT1')));
    }

    #[Test]
    public function app_profile_workstation_changed_invalidates_target(): void
    {
        $this->primeCache('PCT1');

        event(new AppProfileWorkstationChanged($this->profile->id, $this->workstation->id, 'attached'));

        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCT1')));
    }

    #[Test]
    public function app_profile_workstation_group_changed_invalidates_all_group_workstations(): void
    {
        $other = Workstation::create(['name' => 'PCT2', 'status' => 'active']);
        $other->groups()->attach($this->group);
        $this->primeCache('PCT1');
        $this->primeCache('PCT2');

        event(new AppProfileWorkstationGroupChanged($this->profile->id, $this->group->id, 'attached'));

        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCT1')));
        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCT2')));
    }

    #[Test]
    public function app_profile_application_changed_invalidates_direct_and_group_workstations(): void
    {
        $directWs = Workstation::create(['name' => 'PCD', 'status' => 'active']);
        $this->profile->workstations()->attach([$directWs->id]);
        $this->primeCache('PCT1');
        $this->primeCache('PCD');

        $app = Application::create(['app_id' => 'firefox']);
        event(new AppProfileApplicationChanged($this->profile->id, $app->id, 'attached'));

        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCT1')));
        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCD')));
    }
}
