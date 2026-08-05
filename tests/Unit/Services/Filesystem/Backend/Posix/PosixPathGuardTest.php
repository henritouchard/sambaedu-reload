<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\PlanAnchor;
use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Services\Filesystem\Backend\Posix\PosixPathGuard;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.4 → 60.5 — la garde de chemin, DESCENDUE puis DOUBLEMENT ANCRÉE.
 *
 * Les assertions de la story 60.4 viennent des tests de l'Epic 34 : elles
 * survivent, leur emplacement suit le code. La story 60.5 les rend SYMÉTRIQUES —
 * chacune vaut désormais pour chaque zone, parce qu'une garde qui ne tiendrait que
 * sur la zone historique laisserait la zone neuve sans protection le jour où elle
 * devient la seule écrite.
 */
class PosixPathGuardTest extends TestCase
{
    private PosixPathGuard $guard;

    private string $reseauRoot = '/tmp/se5-guard-reseau';

    private string $classesRoot = '/tmp/se5-guard-classes';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'filesystem.shares_root' => $this->reseauRoot,
            'filesystem.class_trees_root' => $this->classesRoot,
        ]);
        $this->guard = new PosixPathGuard();
    }

    /** @return list<array{PlanAnchor}> */
    public static function anchors(): array
    {
        return array_map(static fn (PlanAnchor $a): array => [$a], PlanAnchor::cases());
    }

    private function rootOf(PlanAnchor $anchor): string
    {
        return $anchor === PlanAnchor::Classes ? $this->classesRoot : $this->reseauRoot;
    }

    private function plan(PlanAnchor $anchor, string $rootPath, string ...$nodes): FilePlan
    {
        $planNodes = [new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre)];
        foreach ($nodes as $node) {
            $planNodes[] = new PlanNode($node, $node, PlanNodeNature::ContenuLibre);
        }

        return new FilePlan('@partage', $rootPath, [], $planNodes, $anchor);
    }

    // =========================================================================
    // Les gardes, zone par zone
    // =========================================================================

    #[Test]
    #[DataProvider('anchors')]
    public function it_resolves_a_plan_root_under_the_root_of_its_own_zone(PlanAnchor $anchor): void
    {
        self::assertSame(
            $this->rootOf($anchor) . '/direction',
            $this->guard->planRoot($this->plan($anchor, 'direction')),
        );
    }

    /**
     * DEUX frontières, et il faut les deux.
     *
     * Un plan REFUSE déjà de porter une racine non sûre : le rejet a donc lieu en
     * amont, et c'est la bonne place. Mais la garde de chemin ne s'appuie pas
     * là-dessus — elle revérifie, parce qu'un invariant qui ne tient qu'à une
     * seule frontière ne tient pas, et qu'un plan peut aussi arriver par
     * reconstruction. Le test dit les deux, plutôt que d'affirmer une garde qu'on
     * ne peut plus atteindre.
     */
    #[Test]
    #[DataProvider('anchors')]
    public function traversal_is_refused_by_the_plan_upstream_and_by_the_path_guard_itself(PlanAnchor $anchor): void
    {
        // Refusées EN AMONT : le plan lui-même ne se construit pas.
        foreach (['../etc', 'foo bar', 'evil;rm', '.hidden', 'a$b'] as $bad) {
            try {
                $this->plan($anchor, $bad);
                self::fail("une racine de plan non sûre aurait dû être refusée : {$bad}");
            } catch (\App\Exceptions\Filesystem\PlanResolutionException) {
                self::assertTrue(true);
            }
        }

        // Acceptée par le plan (chemin relatif sûr), REFUSÉE ICI : la racine d'un
        // répertoire géré est UN segment, pas une arborescence. C'est exactement le
        // cas qui prouve que la seconde frontière n'est pas décorative.
        self::assertNull($this->guard->planRoot($this->plan($anchor, 'a/b')));

        // La garde elle-même, sur les chemins concrets correspondants.
        $root = $this->rootOf($anchor);
        foreach (['/etc/passwd', $root . '/../etc', $root . '/foo bar', $root . '/evil;rm'] as $bad) {
            self::assertFalse($this->guard->isValidPath($anchor, $bad), "doit refuser : {$bad}");
        }
    }

    #[Test]
    #[DataProvider('anchors')]
    public function it_refuses_paths_outside_the_root_and_beyond_the_depth_bound(PlanAnchor $anchor): void
    {
        $root = $this->rootOf($anchor);

        self::assertFalse($this->guard->isValidPath($anchor, '/etc/passwd'));
        self::assertFalse($this->guard->isValidPath($anchor, $root . '/../evasion'));
        self::assertFalse($this->guard->isValidPath($anchor, $root . '/a/b/c/d/e'));
        self::assertTrue($this->guard->isValidPath($anchor, $root . '/ok'));
    }

    /**
     * L'ajustement de la story 60.4 : un nœud à deux niveaux sous la racine du plan
     * est résolvable. L'ancienne borne (deux segments en tout) l'aurait refusé, et
     * la chaîne recette→arbre serait morte à la première tentative.
     */
    #[Test]
    #[DataProvider('anchors')]
    public function the_depth_bound_now_follows_the_plan_and_still_bounds_it(PlanAnchor $anchor): void
    {
        $root = $this->rootOf($anchor);
        $plan = $this->plan($anchor, 'classe3a', '_travail', '_travail/devoirs');

        self::assertSame($root . '/classe3a/_travail', $this->guard->resolve($plan, '_travail'));
        self::assertSame($root . '/classe3a/_travail/devoirs', $this->guard->resolve($plan, '_travail/devoirs'));

        // Toujours borné : au-delà de la profondeur de plan admise, refus.
        self::assertNull($this->guard->resolve($this->plan($anchor, 'classe3a'), 'a/b/c/d'));
    }

    #[Test]
    #[DataProvider('anchors')]
    public function the_root_token_resolves_to_the_plan_root_itself_never_to_a_dot_segment(PlanAnchor $anchor): void
    {
        $plan = $this->plan($anchor, 'commun');

        self::assertSame($this->rootOf($anchor) . '/commun', $this->guard->resolve($plan, PlanNode::ROOT_PATH));
    }

    #[Test]
    #[DataProvider('anchors')]
    public function it_refuses_a_node_path_that_walks_back_up(PlanAnchor $anchor): void
    {
        $plan = $this->plan($anchor, 'commun');

        foreach (['../evasion', 'a/../../b', '/absolu'] as $bad) {
            self::assertNull($this->guard->resolve($plan, $bad), "doit refuser : {$bad}");
        }
    }

    #[Test]
    #[DataProvider('anchors')]
    public function the_archive_target_is_dated_stays_under_its_own_zone_and_refuses_a_forged_stamp(PlanAnchor $anchor): void
    {
        $plan = $this->plan($anchor, 'proj');

        self::assertSame(
            $this->rootOf($anchor) . '/.trash/proj-20260804-141530',
            $this->guard->trashTarget($plan, '20260804-141530'),
        );
        self::assertNull($this->guard->trashTarget($plan, '../evasion'));
        self::assertNull($this->guard->trashTarget($plan, 'nimporte'));
    }

    // =========================================================================
    // Ce que la seconde ancre apporte, et ce qu'elle n'ouvre PAS
    // =========================================================================

    /**
     * Les deux zones sont DISJOINTES : un chemin de l'une n'est jamais valide dans
     * l'autre. C'est ce qui fait qu'« une autorité d'écriture par zone » n'est pas
     * qu'une intention.
     */
    #[Test]
    public function a_path_of_one_zone_is_never_valid_in_the_other(): void
    {
        self::assertFalse($this->guard->isValidPath(PlanAnchor::Reseau, $this->classesRoot . '/Classe_3emeA'));
        self::assertFalse($this->guard->isValidPath(PlanAnchor::Classes, $this->reseauRoot . '/direction'));
    }

    /**
     * L'archivage d'un arbre de classe ne passe JAMAIS par la corbeille des
     * répertoires réseau — celle-ci vit sous un espace exposé en SMB.
     */
    #[Test]
    public function each_zone_archives_into_its_own_trash(): void
    {
        self::assertSame($this->reseauRoot . '/.trash', $this->guard->trashRoot(PlanAnchor::Reseau));
        self::assertSame($this->classesRoot . '/.trash', $this->guard->trashRoot(PlanAnchor::Classes));
    }

    /**
     * La racine de plan reste MONO-SEGMENT dans les deux zones : c'est l'ancre qui
     * porte la distinction de zone, jamais un chemin plus profond.
     */
    #[Test]
    #[DataProvider('anchors')]
    public function a_plan_root_stays_a_single_segment_in_every_zone(PlanAnchor $anchor): void
    {
        self::assertNotNull($this->guard->planRoot($this->plan($anchor, 'Classe_3emeA')));
        self::assertNull($this->guard->planRoot($this->plan($anchor, 'Classes/Classe_3emeA')));
    }

    // =========================================================================
    // L'INTERDIT CENTRAL DE LA STORY
    // =========================================================================

    /**
     * **LA promesse de la story 60.5, mécanisée.**
     *
     * SE5 n'écrit jamais un octet dans l'arbre de classe HISTORIQUE. Ce n'est pas
     * une précaution mais une propriété : cette racine n'a AUCUN jeton de zone,
     * donc la table fermée de la garde ne peut pas la fabriquer. On l'éprouve en
     * balayant tout ce qu'un appelant contrôle — la zone, la racine de plan, le
     * chemin de nœud, l'estampille d'archivage — sur les racines RÉELLES de
     * production, celles où la question se pose.
     *
     * **Le test compare avec le séparateur, pas par simple préfixe** : la racine
     * neuve `/var/sambaedu/ClassesSE5` commence par les mêmes caractères que
     * l'arbre historique `/var/sambaedu/Classes`. Une comparaison de préfixe nu
     * aurait donc échoué sur un chemin parfaitement légitime, et la façon commode
     * de « réparer » ce test aurait été de l'affaiblir. On dit donc exactement ce
     * qu'on veut dire : le chemin est-il l'arbre historique, ou DEDANS ?
     */
    #[Test]
    public function no_combination_of_inputs_ever_produces_a_path_inside_the_legacy_class_tree(): void
    {
        // Les racines RÉELLES : c'est là que l'interdit doit tenir.
        config([
            'filesystem.shares_root' => '/var/sambaedu/Partages',
            'filesystem.class_trees_root' => '/var/sambaedu/ClassesSE5',
        ]);
        $guard = new PosixPathGuard();

        $legacy = '/var/sambaedu/Classes';

        $rootPaths = [
            'Classe_3emeA', 'Classes', 'Classe_3sb', '3emeA', '_travail',
            'Classe_3emeA.old', 'a-b_c.d',
        ];
        $nodePaths = [
            PlanNode::ROOT_PATH, '_travail', '_travail/devoirs', '_profs', '_echange',
            'bmartin', '../../Classes/Classe_3emeA', '/var/sambaedu/Classes',
            '..', '.', 'a/b/c/d', 'Classes/Classe_3emeA',
        ];
        $stamps = ['20260805-101112', '../evasion', 'nimporte', ''];

        $produced = [];

        foreach (PlanAnchor::cases() as $anchor) {
            foreach ($rootPaths as $rootPath) {
                $plan = new FilePlan(
                    '@epreuve',
                    $rootPath,
                    [],
                    [new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre)],
                    $anchor,
                );

                $produced[] = $guard->planRoot($plan);
                $produced[] = $guard->rootFor($anchor);
                $produced[] = $guard->trashRoot($anchor);

                foreach ($nodePaths as $nodePath) {
                    $produced[] = $guard->resolve($plan, $nodePath);
                }
                foreach ($stamps as $stamp) {
                    $produced[] = $guard->trashTarget($plan, $stamp);
                }
            }
        }

        $inside = array_values(array_filter(
            array_filter($produced, static fn (?string $p): bool => $p !== null),
            static fn (string $p): bool => $p === $legacy || str_starts_with($p, $legacy . '/'),
        ));

        self::assertSame(
            [],
            $inside,
            'PROMESSE DE LA STORY 60.5 ROMPUE : la garde a fabriqué un chemin dans l\'arbre de classe '
            . 'HISTORIQUE. SE5 ne doit y écrire aucun octet — cette racine n\'a pas de jeton de zone, '
            . 'donc aucune entrée ne doit pouvoir la produire. Chemins fautifs : '
            . json_encode($inside, JSON_UNESCAPED_SLASHES),
        );

        // Méta-test : le balayage doit avoir réellement produit des chemins —
        // sans quoi l'assertion ci-dessus serait vide pour la pire des raisons.
        $resolved = array_filter($produced, static fn (?string $p): bool => $p !== null);
        self::assertGreaterThan(50, count($resolved), 'le balayage doit produire des chemins à éprouver');
    }

    // =========================================================================
    // Les zones doivent être DISJOINTES — et c'est réglable par environnement
    // =========================================================================

    /**
     * LE BALAYAGE CI-DESSUS NE REJOUE QUE LES RACINES QU'IL A LUI-MÊME FIXÉES.
     *
     * Il prouve qu'aucune combinaison d'ENTRÉES ne sort de sa zone ; il ne dit
     * rien de la CONFIGURATION d'une instance réelle, où la zone des arbres de
     * classe se règle par variable d'environnement. Un copier-coller malheureux y
     * suffirait à faire écrire SE5 dans l'arbre historique — en silence, alors que
     * toute la story repose sur l'idée qu'aucun chemin ne mène là.
     *
     * La garde refuse donc de servir une racine qui coïncide, et le dit.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function forbiddenClassRoots(): array
    {
        return [
            'l\'arbre historique lui-même' => ['/var/sambaedu/Classes', 'historique'],
            'un sous-dossier de l\'arbre historique' => ['/var/sambaedu/Classes/SE5', 'historique'],
            'la zone des répertoires réseau' => ['/tmp/se5-guard-reseau', 'réseaux'],
            'un sous-dossier de la zone réseau' => ['/tmp/se5-guard-reseau/classes', 'réseaux'],
        ];
    }

    #[Test]
    public function a_class_zone_that_collides_with_a_forbidden_root_is_refused_loudly(): void
    {
        foreach (self::forbiddenClassRoots() as $case => [$root, $_]) {
            config(['filesystem.class_trees_root' => $root]);

            try {
                (new PosixPathGuard())->rootFor(PlanAnchor::Classes);
                self::fail(sprintf('« %s » (%s) aurait dû être refusé', $root, $case));
            } catch (PlanResolutionException $e) {
                self::assertStringContainsString('disjointes', $e->getMessage(), $case);
                self::assertStringContainsString($root, $e->getMessage(), $case);
            }
        }
    }

    /**
     * Le pendant : une racine légitime passe. Sans lui, le test ci-dessus serait
     * vert avec une garde qui refuserait TOUT.
     */
    #[Test]
    public function a_disjoint_class_zone_is_served_normally(): void
    {
        config(['filesystem.class_trees_root' => '/var/sambaedu/ClassesSE5']);

        self::assertSame(
            '/var/sambaedu/ClassesSE5',
            (new PosixPathGuard())->rootFor(PlanAnchor::Classes),
        );
    }

    /**
     * Le préfixe partagé n'est PAS une collision : `ClassesSE5` commence par
     * `Classes` sans être dedans. Une garde qui confondrait les deux refuserait
     * précisément l'emplacement livré par défaut.
     */
    #[Test]
    public function a_shared_prefix_is_not_a_collision(): void
    {
        config(['filesystem.class_trees_root' => '/var/sambaedu/ClassesAutrement']);

        self::assertSame(
            '/var/sambaedu/ClassesAutrement',
            (new PosixPathGuard())->rootFor(PlanAnchor::Classes),
        );
    }
}
