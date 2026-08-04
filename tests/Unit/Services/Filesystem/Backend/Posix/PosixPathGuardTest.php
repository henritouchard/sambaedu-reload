<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\PlanNodeNature;
use App\Services\Filesystem\Backend\Posix\PosixPathGuard;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.4 — la garde de chemin, DESCENDUE. Ses assertions viennent des tests
 * de l'Epic 34 : elles survivent, leur emplacement suit le code.
 */
class PosixPathGuardTest extends TestCase
{
    private PosixPathGuard $guard;

    private string $root = '/tmp/se5-guard-root';

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystem.shares_root' => $this->root]);
        $this->guard = new PosixPathGuard();
    }

    private function plan(string $rootPath, string ...$nodes): FilePlan
    {
        $planNodes = [new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre)];
        foreach ($nodes as $node) {
            $planNodes[] = new PlanNode($node, $node, PlanNodeNature::ContenuLibre);
        }

        return new FilePlan('@partage', $rootPath, [], $planNodes);
    }

    #[Test]
    public function it_resolves_a_plan_root_under_the_dedicated_root(): void
    {
        self::assertSame($this->root . '/direction', $this->guard->planRoot($this->plan('direction')));
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
    public function traversal_is_refused_by_the_plan_upstream_and_by_the_path_guard_itself(): void
    {
        // Refusées EN AMONT : le plan lui-même ne se construit pas.
        foreach (['../etc', 'foo bar', 'evil;rm', '.hidden', 'a$b'] as $bad) {
            try {
                $this->plan($bad);
                self::fail("une racine de plan non sûre aurait dû être refusée : {$bad}");
            } catch (\App\Exceptions\Filesystem\PlanResolutionException) {
                self::assertTrue(true);
            }
        }

        // Acceptée par le plan (chemin relatif sûr), REFUSÉE ICI : la racine d'un
        // répertoire géré est UN segment, pas une arborescence. C'est exactement le
        // cas qui prouve que la seconde frontière n'est pas décorative.
        self::assertNull($this->guard->planRoot($this->plan('a/b')));

        // La garde elle-même, sur les chemins concrets correspondants.
        foreach (['/etc/passwd', $this->root . '/../etc', $this->root . '/foo bar', $this->root . '/evil;rm'] as $bad) {
            self::assertFalse($this->guard->isValidPath($bad), "doit refuser : {$bad}");
        }
    }

    #[Test]
    public function it_refuses_paths_outside_the_root_and_beyond_the_depth_bound(): void
    {
        self::assertFalse($this->guard->isValidPath('/etc/passwd'));
        self::assertFalse($this->guard->isValidPath($this->root . '/../evasion'));
        self::assertFalse($this->guard->isValidPath($this->root . '/a/b/c/d/e'));
        self::assertTrue($this->guard->isValidPath($this->root . '/ok'));
    }

    /**
     * L'ajustement de la story : un nœud à deux niveaux sous la racine du plan est
     * désormais résolvable. L'ancienne borne (deux segments en tout) l'aurait
     * refusé, et la chaîne recette→arbre serait morte à la première tentative.
     */
    #[Test]
    public function the_depth_bound_now_follows_the_plan_and_still_bounds_it(): void
    {
        $plan = $this->plan('classe3a', '_travail', '_travail/devoirs');

        self::assertSame($this->root . '/classe3a/_travail', $this->guard->resolve($plan, '_travail'));
        self::assertSame($this->root . '/classe3a/_travail/devoirs', $this->guard->resolve($plan, '_travail/devoirs'));

        // Toujours borné : au-delà de la profondeur de plan admise, refus.
        self::assertNull($this->guard->resolve($this->plan('classe3a'), 'a/b/c/d'));
    }

    #[Test]
    public function the_root_token_resolves_to_the_plan_root_itself_never_to_a_dot_segment(): void
    {
        $plan = $this->plan('commun');

        self::assertSame($this->root . '/commun', $this->guard->resolve($plan, PlanNode::ROOT_PATH));
    }

    #[Test]
    public function it_refuses_a_node_path_that_walks_back_up(): void
    {
        $plan = $this->plan('commun');

        foreach (['../evasion', 'a/../../b', '/absolu'] as $bad) {
            self::assertNull($this->guard->resolve($plan, $bad), "doit refuser : {$bad}");
        }
    }

    #[Test]
    public function the_archive_target_is_dated_stays_under_the_root_and_refuses_a_forged_stamp(): void
    {
        $plan = $this->plan('proj');

        self::assertSame(
            $this->root . '/.trash/proj-20260804-141530',
            $this->guard->trashTarget($plan, '20260804-141530'),
        );
        self::assertNull($this->guard->trashTarget($plan, '../evasion'));
        self::assertNull($this->guard->trashTarget($plan, 'nimporte'));
    }
}
