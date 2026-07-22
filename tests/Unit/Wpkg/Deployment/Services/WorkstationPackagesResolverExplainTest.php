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
 * Story 37.1 — `explainPackages()` (provenance par `app_id`) et son INVARIANT
 * vis-à-vis de `computePackages()` (AC3/AC5) : les clés de la map d'origines,
 * dans l'ordre, sont EXACTEMENT l'ensemble cible byte-identique historique.
 */
class WorkstationPackagesResolverExplainTest extends TestCase
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

    /**
     * Construit un poste avec assignations MIXTES (direct poste, via profil poste,
     * app parc, profil parc, dépendance transitive) — le scénario AC4 complet.
     *
     * @return array{0:Workstation, 1:WorkstationGroup}
     */
    private function seedMixedWorkstation(string $name): array
    {
        $direct = $this->newApp('alpha');       // app directe poste
        $viaProfilePoste = $this->newApp('bravo'); // via profil poste
        $groupApp = $this->newApp('charlie');   // app directe parc
        $groupProfileApp = $this->newApp('delta'); // via profil parc
        $dep = $this->newApp('echo');           // dépendance de alpha
        $depTransitive = $this->newApp('foxtrot'); // dépendance de echo

        DB::table('application_dependencies')->insert([
            ['application_id' => $direct->id, 'required_application_id' => $dep->id, 'created_at' => now(), 'updated_at' => now()],
            ['application_id' => $dep->id, 'required_application_id' => $depTransitive->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ws = Workstation::create(['name' => $name, 'status' => 'active']);
        $group = WorkstationGroup::create(['name' => 'parc-'.$name]);
        $ws->groups()->attach($group);

        $ws->applications()->attach([$direct->id]);

        $profilePoste = AppProfile::create(['name' => 'profile-poste-'.$name]);
        $profilePoste->applications()->attach([$viaProfilePoste->id]);
        $ws->appProfiles()->attach([$profilePoste->id]);

        $group->applications()->attach([$groupApp->id]);
        $profileParc = AppProfile::create(['name' => 'profile-parc-'.$name]);
        $profileParc->applications()->attach([$groupProfileApp->id]);
        $group->appProfiles()->attach([$profileParc->id]);

        return [$ws, $group];
    }

    #[Test]
    public function invariant_explain_keys_equal_compute_packages(): void
    {
        [$ws] = $this->seedMixedWorkstation('PCTINV');
        $resolver = new WorkstationPackagesResolver();

        // Invariant AC3 (ordre compris).
        self::assertSame(
            $resolver->computePackages($ws->name)->all(),
            array_keys($resolver->explainPackages($ws->name)),
        );
    }

    #[Test]
    public function invariant_holds_on_unknown_and_empty_hosts(): void
    {
        $resolver = new WorkstationPackagesResolver();

        self::assertSame([], $resolver->explainPackages('nope'));
        self::assertSame(
            $resolver->computePackages('nope')->all(),
            array_keys($resolver->explainPackages('nope')),
        );

        // Poste connu SANS aucune assignation.
        Workstation::create(['name' => 'PCEMPTY', 'status' => 'active']);
        self::assertSame([], $resolver->explainPackages('PCEMPTY'));
    }

    #[Test]
    public function origins_expose_each_source_kind(): void
    {
        [$ws, $group] = $this->seedMixedWorkstation('PCTORIG');
        $resolver = new WorkstationPackagesResolver();
        $explained = $resolver->explainPackages($ws->name);

        // alpha = app directe poste
        self::assertContains('workstation', array_column($explained['alpha'], 'source'));
        // bravo = via profil poste (source workstation + profile_id présent)
        self::assertContains('workstation', array_column($explained['bravo'], 'source'));
        self::assertNotNull($explained['bravo'][0]['profile_id']);
        // charlie = app directe parc
        $charlie = $explained['charlie'][0];
        self::assertSame('group', $charlie['source']);
        self::assertSame($group->id, $charlie['group_id']);
        // delta = via profil parc
        $delta = $explained['delta'][0];
        self::assertSame('group', $delta['source']);
        self::assertSame($group->id, $delta['group_id']);
        self::assertNotNull($delta['profile_id']);
        // echo = dépendance de alpha
        $echo = $explained['echo'][0];
        self::assertSame('dependency', $echo['source']);
        self::assertSame('alpha', $echo['via_app_id']);
        // foxtrot = dépendance transitive (de echo)
        $foxtrot = $explained['foxtrot'][0];
        self::assertSame('dependency', $foxtrot['source']);
        self::assertSame('echo', $foxtrot['via_app_id']);
    }

    #[Test]
    public function dependency_with_two_parents_attributes_smallest_parent_pk(): void
    {
        // Review #3 — une dépendance partagée par DEUX apps racines dans le même
        // batch BFS : le parent attribué (« Dépendance de X ») doit être
        // DÉTERMINISTE = la plus petite `application_id` (PK) parente, grâce à
        // l'`orderBy('application_id')`. On choisit des app_id dont l'ordre
        // alphabétique est INVERSE de l'ordre de PK, pour prouver que c'est bien la
        // PK (et non l'app_id) qui départage.
        $parentSmallPk = $this->newApp('zzz-parent'); // créé en 1er ⇒ PK la plus petite
        $parentLargePk = $this->newApp('aaa-parent'); // créé en 2ᵉ ⇒ PK plus grande
        $shared = $this->newApp('shared-dep');

        DB::table('application_dependencies')->insert([
            ['application_id' => $parentSmallPk->id, 'required_application_id' => $shared->id, 'created_at' => now(), 'updated_at' => now()],
            ['application_id' => $parentLargePk->id, 'required_application_id' => $shared->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ws = Workstation::create(['name' => 'PCTDEP2', 'status' => 'active']);
        $ws->applications()->attach([$parentSmallPk->id, $parentLargePk->id]);

        $resolver = new WorkstationPackagesResolver();
        $explained = $resolver->explainPackages('PCTDEP2');

        $depOrigin = collect($explained['shared-dep'])->firstWhere('source', 'dependency');
        self::assertNotNull($depOrigin);
        // Plus petite PK parente ⇒ 'zzz-parent' (créé en 1er), indépendamment de l'alpha.
        self::assertSame('zzz-parent', $depOrigin['via_app_id']);
    }

    #[Test]
    public function same_app_from_two_sources_accumulates_origins(): void
    {
        $shared = $this->newApp('shared');
        $ws = Workstation::create(['name' => 'PCTMULTI', 'status' => 'active']);
        $group = WorkstationGroup::create(['name' => 'parc-multi']);
        $ws->groups()->attach($group);

        // Même app assignée AU POSTE et AU PARC → deux origines, une seule clé.
        $ws->applications()->attach([$shared->id]);
        $group->applications()->attach([$shared->id]);

        $resolver = new WorkstationPackagesResolver();
        $explained = $resolver->explainPackages('PCTMULTI');

        self::assertArrayHasKey('shared', $explained);
        self::assertCount(2, $explained['shared']);
        $sources = array_column($explained['shared'], 'source');
        self::assertContains('workstation', $sources);
        self::assertContains('group', $sources);

        // Invariant : une seule app_id malgré deux origines.
        self::assertSame(['shared'], $resolver->computePackages('PCTMULTI')->all());
    }
}
