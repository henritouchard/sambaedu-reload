<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment\Listeners;

use App\Models\AppProfile;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Events\AppProfileApplicationsChanged;
use App\Wpkg\Deployment\Events\WorkstationApplicationsChanged;
use App\Wpkg\Deployment\Events\WorkstationGroupApplicationsChanged;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.4 / AC7.4 — Les 3 nouveaux events additifs câblés sur le
 * listener générique `InvalidateWorkstationPackagesCache`.
 */
class InvalidateWorkstationPackagesCacheNewEventsTest extends TestCase
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
    public function workstation_group_applications_changed_invalidates_all_group_workstations(): void
    {
        $this->primeCache('PCT1');

        event(new WorkstationGroupApplicationsChanged($this->group->id, [42], 'attached'));

        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCT1')));
    }

    #[Test]
    public function workstation_applications_changed_invalidates_target_workstation(): void
    {
        $this->primeCache('PCT1');

        event(new WorkstationApplicationsChanged($this->workstation->id, [42], 'attached'));

        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCT1')));
    }

    #[Test]
    public function app_profile_applications_changed_invalidates_all_profile_workstations(): void
    {
        $this->primeCache('PCT1');

        event(new AppProfileApplicationsChanged($this->profile->id, [42, 43], 'attached'));

        self::assertFalse(Cache::has(WorkstationPackagesResolver::cacheKey('PCT1')));
    }

    #[Test]
    public function unrelated_workstation_cache_is_not_invalidated(): void
    {
        $other = Workstation::create(['name' => 'PCT2', 'status' => 'active']);
        $this->primeCache('PCT2');

        event(new WorkstationApplicationsChanged($this->workstation->id, [42], 'attached'));

        self::assertTrue(Cache::has(WorkstationPackagesResolver::cacheKey('PCT2')));
    }
}
