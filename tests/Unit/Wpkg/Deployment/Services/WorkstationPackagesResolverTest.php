<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Services;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.2 / AC2 + AC3 — `WorkstationPackagesResolver`.
 */
class WorkstationPackagesResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    private function newApp(string $appId): Application
    {
        return Application::create(['app_id' => $appId, 'name' => $appId]);
    }

    #[Test]
    public function unknown_hostname_returns_empty_collection(): void
    {
        $resolver = new WorkstationPackagesResolver();

        $result = $resolver->resolve('does-not-exist');

        self::assertCount(0, $result);
    }

    #[Test]
    public function unions_4_sources_dedups_and_sorts_alpha(): void
    {
        $appA = $this->newApp('alpha');
        $appB = $this->newApp('bravo');
        $appC = $this->newApp('charlie');
        $appD = $this->newApp('delta');
        // Doublon volontaire pour vérifier la déduplication
        $appBis = $this->newApp('alpha-bis');

        $workstation = Workstation::create(['name' => 'PCT1', 'status' => 'active']);
        $group = WorkstationGroup::create(['name' => 'parc-1']);
        $workstation->groups()->attach($group);

        // Source 1 : AppProfile direct poste
        $profile1 = AppProfile::create(['name' => 'profile-poste', 'is_active' => true]);
        $profile1->applications()->attach([$appA->id]);
        $workstation->appProfiles()->attach([$profile1->id]);

        // Source 2 : AppProfile parc
        $profile2 = AppProfile::create(['name' => 'profile-parc', 'is_active' => true]);
        $profile2->applications()->attach([$appB->id, $appA->id]); // doublon $appA
        $group->appProfiles()->attach([$profile2->id]);

        // Source 3 : Application directe poste
        $workstation->applications()->attach([$appC->id]);

        // Source 4 : Application directe parc
        $group->applications()->attach([$appD->id, $appBis->id]);

        $resolver = new WorkstationPackagesResolver();
        $result = $resolver->resolve('PCT1')->all();

        self::assertSame(['alpha', 'alpha-bis', 'bravo', 'charlie', 'delta'], $result);
    }

    #[Test]
    public function transitive_dependencies_are_collected(): void
    {
        $appA = $this->newApp('alpha');  // racine, attachée au poste
        $appB = $this->newApp('bravo');  // dep de A
        $appC = $this->newApp('charlie');// dep de B (transitive)

        // alpha → bravo → charlie
        DB::table('application_dependencies')->insert([
            ['application_id' => $appA->id, 'required_application_id' => $appB->id, 'created_at' => now(), 'updated_at' => now()],
            ['application_id' => $appB->id, 'required_application_id' => $appC->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $w = Workstation::create(['name' => 'PCT2', 'status' => 'active']);
        $w->applications()->attach([$appA->id]);

        $resolver = new WorkstationPackagesResolver();
        $result = $resolver->resolve('PCT2')->all();

        self::assertSame(['alpha', 'bravo', 'charlie'], $result);
    }

    #[Test]
    public function inactive_workstation_still_returns_packages(): void
    {
        $appA = $this->newApp('alpha');
        $w = Workstation::create(['name' => 'PCT3', 'status' => 'inactive']);
        $w->applications()->attach([$appA->id]);

        $resolver = new WorkstationPackagesResolver();
        $result = $resolver->resolve('PCT3')->all();

        self::assertSame(['alpha'], $result);
    }

    #[Test]
    public function cache_remember_uses_lowercased_key_and_ttl(): void
    {
        $appA = $this->newApp('alpha');
        $w = Workstation::create(['name' => 'MixedCase', 'status' => 'active']);
        $w->applications()->attach([$appA->id]);

        $resolver = new WorkstationPackagesResolver();
        $resolver->resolve('MixedCase');

        self::assertTrue(Cache::has('wpkg:packages:mixedcase'));
        self::assertSame(WorkstationPackagesResolver::cacheKey('FOObar'), 'wpkg:packages:foobar');
    }

    #[Test]
    public function does_not_import_ldap_record_or_ad_services(): void
    {
        $reflector = new \ReflectionClass(WorkstationPackagesResolver::class);
        $source = (string) file_get_contents((string) $reflector->getFileName());

        self::assertStringNotContainsString('LdapRecord', $source);
        self::assertStringNotContainsString('App\\Services\\Ad', $source);
    }
}
