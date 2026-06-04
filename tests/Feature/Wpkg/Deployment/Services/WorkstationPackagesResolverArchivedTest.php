<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment\Services;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.3 / D8 — Le `WorkstationPackagesResolver` doit ignorer les
 * postes / groupes / profils marqués `archived_at`.
 *
 * Filtre acté pendant T1 (cf. audit T0 §5, R-T0.3) : l'archivage logique
 * introduit par 15.3 AC3.4 doit produire un comportement « fantôme » côté
 * pipeline déploiement — sans casser les pivots ni supprimer la row, le
 * resolver retourne 0 package.
 *
 * Pas de test de non-régression sur les cas non archivés : couverts par
 * `ProfilesXmlControllerTest` (15.2).
 */
class WorkstationPackagesResolverArchivedTest extends TestCase
{
    private WorkstationPackagesResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();
        $this->resolver = new WorkstationPackagesResolver();
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function archived_workstation_returns_empty_collection(): void
    {
        $w = Workstation::create([
            'name' => 'PC-ARCHIVED',
            'archived_at' => now(),
        ]);
        $app = Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);
        $w->applications()->attach($app);

        $packages = $this->resolver->resolve('PC-ARCHIVED');

        self::assertTrue($packages->isEmpty(), 'un poste archivé ne doit exposer aucun package');
    }

    #[Test]
    public function archived_group_packages_are_filtered_out(): void
    {
        $w = Workstation::create(['name' => 'PCG1']);
        $g = WorkstationGroup::create(['name' => 'parc-archived', 'archived_at' => now()]);
        $w->groups()->attach($g);

        $appParc = Application::create(['app_id' => 'thunderbird', 'name' => 'TB']);
        $g->applications()->attach($appParc);

        $packages = $this->resolver->resolve('PCG1');

        self::assertFalse(
            $packages->contains('thunderbird'),
            'un groupe archivé ne doit pas faire remonter ses packages'
        );
    }

    #[Test]
    public function archived_app_profile_packages_are_filtered_out(): void
    {
        $w = Workstation::create(['name' => 'PCP1']);
        $profile = AppProfile::create(['name' => 'profile-archived', 'archived_at' => now()]);
        $w->appProfiles()->attach($profile);

        $appProfile = Application::create(['app_id' => 'libreoffice', 'name' => 'LibreOffice']);
        $profile->applications()->attach($appProfile);

        $packages = $this->resolver->resolve('PCP1');

        self::assertFalse(
            $packages->contains('libreoffice'),
            'un profil archivé ne doit pas faire remonter ses packages'
        );
    }

    #[Test]
    public function unarchived_entities_remain_visible(): void
    {
        $w = Workstation::create(['name' => 'PC-LIVE']);
        $g = WorkstationGroup::create(['name' => 'parc-live']);
        $w->groups()->attach($g);

        $appParc = Application::create(['app_id' => 'vlc', 'name' => 'VLC']);
        $g->applications()->attach($appParc);

        $packages = $this->resolver->resolve('PC-LIVE');

        self::assertTrue($packages->contains('vlc'));
    }

    /**
     * Story 4.11 / AC4 — un déploiement WPKG porté par une SALLE physique
     * (groupe `is_physical = true`, désormais dans le pivot global) doit se
     * résoudre pour les postes de cette salle. Avant 4.11, la salle vivait
     * dans la FK `physical_room_id` et était invisible du resolver (qui lit
     * `groups()`).
     */
    #[Test]
    public function physical_room_packages_resolve_via_pivot(): void
    {
        $w = Workstation::create(['name' => 'PC-SALLE']);
        $room = WorkstationGroup::create(['name' => 'salle-info', 'is_physical' => true]);
        $w->groups()->attach($room);

        $appRoom = Application::create(['app_id' => 'geogebra', 'name' => 'GeoGebra']);
        $room->applications()->attach($appRoom);

        $packages = $this->resolver->resolve('PC-SALLE');

        self::assertTrue(
            $packages->contains('geogebra'),
            'une salle physique porteuse d\'un package doit le déployer sur ses postes (pivot global)'
        );
    }
}
