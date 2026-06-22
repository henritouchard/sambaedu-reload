<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\Application;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\Depot;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\RegistryMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryUserCapabilityProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.x — HÉRÉDITÉ des CAPACITÉS le long de la chaîne physique
 * (`parent_id`) + conflit entre groupes LOGIQUES arbitré par la DATE
 * d'assignation.
 *
 * Invariants prouvés ici (la POLITIQUE de précédence vit dans le
 * `StateCompiler` seul ; les providers ne fournissent que des FAITS) :
 *   1. une capacité sur la salle PARENTE est héritée par un poste de la salle
 *      ENFANT ;
 *   2. l'enfant OVERRIDE le parent (profondeur la plus faible gagne) ;
 *   3. `logique > physique` CONSERVÉ (régression 27.3) ;
 *   4. deux groupes LOGIQUES en conflit → la plus RÉCENTE gagne ;
 *   5. sans assignation → `default_value` ;
 *   6. GARDE WPKG : une app sur la salle parente n'est PAS installée sur un
 *      poste de la salle enfant (décision explicite : WPKG n'hérite PAS).
 *
 * Capacité de travail : `show_hidden_files` (toggle on/off, défaut on ;
 * HKCU…\Advanced\Hidden, REG_DWORD, on=1 affiche / off=2 masque).
 */
class CapabilityPhysicalInheritanceTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    private Capability $cap;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();

        // Catalogue VIDE (le lot iso seedé par migration brouillerait les
        // assertions) puis UNE capacité de travail contrôlée.
        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        $this->ws = Workstation::factory()->create();
        $this->cap = Capability::factory()->create([
            'key' => 'show_hidden_files',
            'default_value' => Capability::TOGGLE_ON,
        ]);
        CapabilityProjection::factory()->for($this->cap)->keys([
            [
                'hive' => 'HKCU',
                'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced',
                'name' => 'Hidden',
                'type' => 'REG_DWORD',
                'value' => ['on' => 1, 'off' => 2],
            ],
        ])->create();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Chaîne physique « techos » (parent racine) ← « techno » (enfant). Le poste
     * est membre DIRECT de l'enfant uniquement (le parent n'est atteint que par
     * `parent_id`, jamais par le pivot d'appartenance).
     *
     * @return array{0: WorkstationGroup, 1: WorkstationGroup} [parent, enfant]
     */
    private function physicalChain(): array
    {
        $techos = WorkstationGroup::factory()->create(['is_physical' => true]);
        $techno = WorkstationGroup::factory()->create(['is_physical' => true, 'parent_id' => $techos->id]);
        $this->ws->groups()->attach($techno->id);

        return [$techos, $techno];
    }

    private function assign(WorkstationGroup $group, string $value): void
    {
        DB::table('capability_assignments')->insert([
            'capability_id' => $this->cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $group->id,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function compiler(): StateCompiler
    {
        return new StateCompiler(new StateHasher(), [
            new RegistryMachineCapabilityProvider(),
            new RegistryUserCapabilityProvider(),
        ]);
    }

    /**
     * Valeur registre `Hidden` RÉSOLUE pour le poste (1 = on / 2 = off). Assertion
     * d'unicité incluse : la résolution émet TOUJOURS une seule valeur par clé.
     */
    private function resolvedHidden(): int
    {
        $state = $this->compiler()->compile(TargetContext::for($this->ws, null));
        $registry = array_values(array_filter(
            $state[StateContract::SCOPE_SESSION],
            static fn (array $i): bool => $i['type'] === 'registry',
        ));

        self::assertCount(1, $registry, 'une seule clé Hidden résolue (valeur unique par capacité)');

        return $registry[0]['payload']['value'];
    }

    // ── 1. Hérédité physique : le parent est hérité ───────────────────────

    #[Test]
    public function capability_on_parent_room_is_inherited_by_child_room_workstation(): void
    {
        [$techos] = $this->physicalChain();
        $this->assign($techos, 'off'); // salle PARENTE → off

        self::assertSame(2, $this->resolvedHidden(), 'le poste enfant hérite de la capacité de la salle parente');
    }

    // ── 2. L'enfant override le parent (enfant gagne) ─────────────────────

    #[Test]
    public function child_room_overrides_parent_room(): void
    {
        [$techos, $techno] = $this->physicalChain();
        $this->assign($techos, 'on');  // parent = on
        $this->assign($techno, 'off'); // enfant = off → GAGNE (plus proche)

        self::assertSame(2, $this->resolvedHidden(), 'l\'enfant (profondeur 0) bat le parent (profondeur 1)');
    }

    // ── 3. logique > physique (régression 27.3) ───────────────────────────

    #[Test]
    public function logical_group_beats_physical_chain(): void
    {
        [, $techno] = $this->physicalChain();
        $parc = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach($parc->id);

        $this->assign($techno, 'off'); // salle physique = off
        $this->assign($parc, 'on');    // parc logique = on → GAGNE (logique > physique)

        self::assertSame(1, $this->resolvedHidden(), 'le parc logique bat la chaîne physique (D-Q3 conservé)');
    }

    // ── 4. Conflit entre groupes LOGIQUES → la plus récente gagne ─────────

    #[Test]
    public function conflicting_logical_groups_resolve_by_most_recent_assignment(): void
    {
        $nGroup = WorkstationGroup::factory()->logical()->create(['name' => 'parc_n']);
        $gGroup = WorkstationGroup::factory()->logical()->create(['name' => 'parc_g']);
        $this->ws->groups()->attach([$nGroup->id, $gGroup->id]);

        // N=on (ancien) puis G=off (plus récent) → G (off) gagne.
        DB::table('capability_assignments')->insert([
            ['capability_id' => $this->cap->id, 'assignable_type' => WorkstationGroup::class, 'assignable_id' => $nGroup->id, 'value' => 'on', 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)],
            ['capability_id' => $this->cap->id, 'assignable_type' => WorkstationGroup::class, 'assignable_id' => $gGroup->id, 'value' => 'off', 'created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5)],
        ]);

        self::assertSame(2, $this->resolvedHidden(), 'la plus récente (G=off) gagne');

        // N modifié PLUS TARD → N (on) redevient le plus récent → gagne.
        DB::table('capability_assignments')
            ->where('capability_id', $this->cap->id)
            ->where('assignable_id', $nGroup->id)
            ->update(['value' => 'on', 'updated_at' => now()]);

        self::assertSame(1, $this->resolvedHidden(), 'après MAJ, N=on (désormais la plus récente) gagne');
    }

    // ── 5. Sans assignation → default_value ───────────────────────────────

    #[Test]
    public function without_any_assignment_falls_back_to_default_value(): void
    {
        $this->physicalChain(); // poste rattaché, aucune assignation

        self::assertSame(1, $this->resolvedHidden(), 'aucun candidat → default_value (on=1)');
    }

    // ── 5bis. Robustesse (review F1) : parent_id physique → groupe LOGIQUE ─

    #[Test]
    public function physical_room_with_logical_parent_does_not_inherit_that_logical_group(): void
    {
        // Anomalie de données hors invariant : une salle physique dont le
        // `parent_id` pointe vers un groupe LOGIQUE. Ce groupe ne doit PAS être
        // reclassé physique ni faire hériter le poste (qui n'en est pas membre) :
        // la résolution reste sur le défaut.
        $weirdLogicalParent = WorkstationGroup::factory()->logical()->create();
        $techno = WorkstationGroup::factory()->create([
            'is_physical' => true,
            'parent_id' => $weirdLogicalParent->id,
        ]);
        $this->ws->groups()->attach($techno->id);

        $this->assign($weirdLogicalParent, 'off'); // assignation sur le parent LOGIQUE

        self::assertSame(
            1,
            $this->resolvedHidden(),
            'un parent_id physique pointant vers un groupe logique est ignoré → défaut (on=1)',
        );
    }

    // ── 6. GARDE ANTI-RÉGRESSION WPKG : pas d'hérédité d'apps ─────────────

    #[Test]
    public function wpkg_app_on_parent_room_is_not_installed_on_child_room_workstation(): void
    {
        [$techos, $techno] = $this->physicalChain();

        $depot = Depot::create(['name' => 'd', 'url' => 'http://example.test/wpkg']);
        $parentApp = Application::create(['depot_id' => $depot->id, 'app_id' => 'parent-only-app', 'name' => 'Parent App']);
        $childApp = Application::create(['depot_id' => $depot->id, 'app_id' => 'child-app', 'name' => 'Child App']);

        $techos->applications()->attach($parentApp->id); // salle PARENTE
        $techno->applications()->attach($childApp->id);   // salle directe du poste

        $packages = (new WorkstationPackagesResolver())->computePackages($this->ws->name)->all();

        self::assertContains('child-app', $packages, 'contrôle positif : l\'app de la salle directe EST installée');
        self::assertNotContains('parent-only-app', $packages, 'WPKG n\'hérite PAS de la salle parente (décision explicite)');
    }
}
