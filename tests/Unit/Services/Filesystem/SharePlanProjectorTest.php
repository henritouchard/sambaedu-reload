<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Filesystem\SharePlanProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\PlanNeutralityMarkers;

/**
 * Story 60.3 — un répertoire réseau PLAT projeté en plan NEUTRE.
 *
 * Deux propriétés à tenir, et elles tirent dans des sens opposés : le plan doit
 * décrire fidèlement les assignations réelles (donc lire le pivot polymorphe) ET
 * ne dériver aucun nom système (donc ne rien emprunter à la couche d'exécution).
 * C'est exactement la coupe de l'epic, appliquée au cas le plus simple.
 */
class SharePlanProjectorTest extends TestCase
{
    use PlanNeutralityMarkers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function projector(): SharePlanProjector
    {
        return app(SharePlanProjector::class);
    }

    private function assign(NetworkShare $share, string $type, int $id, string $access): void
    {
        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'access' => $access,
        ]);
    }

    #[Test]
    public function a_flat_share_projects_to_a_single_free_content_root_node(): void
    {
        $share = NetworkShare::factory()->create([
            'name' => 'Echange Direction',
            'directory_name' => 'echange_direction',
        ]);

        $plan = $this->projector()->project($share);

        $this->assertSame(SharePlanProjector::TEMPLATE_KEY, $plan->templateKey);
        $this->assertSame('echange_direction', $plan->rootPath);
        $this->assertSame([PlanNode::ROOT_PATH], $plan->nodePaths());

        $root = $plan->node(PlanNode::ROOT_PATH);
        $this->assertSame(PlanNodeNature::ContenuLibre, $root->nature);
        $this->assertFalse($root->governsChildren(), 'un partage plat ne gouverne pas ses enfants');
        $this->assertSame([], $root->closure, 'sans recette, il n\'y a aucun rôle à refermer');
        $this->assertSame([], $plan->roles);
        $this->assertNull($root->plafond);
    }

    #[Test]
    public function the_three_meshes_project_the_two_that_grant_and_ignore_the_third(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'depot']);
        $user = User::create(['login' => 'alice', 'role' => 'prof', 'is_active' => true]);
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $wg = WorkstationGroup::create(['name' => 'salle-101', 'display_name' => 'Salle 101']);

        $this->assign($share, User::class, (int) $user->id, 'rw');
        $this->assign($share, UserGroup::class, (int) $group->id, 'ro');
        $this->assign($share, WorkstationGroup::class, (int) $wg->id, 'rw');

        $grants = $this->projector()->project($share->fresh())->node(PlanNode::ROOT_PATH)->grants;

        $this->assertCount(2, $grants, 'le parc est MONTAGE-SEUL : il n\'octroie rien');

        $subjects = array_map(static fn (PlanGrant $g): array => [
            $g->subject->type,
            $g->subject->id,
            $g->verbs,
        ], $grants);

        $this->assertContains([PlanSubject::TYPE_USER, (int) $user->id, PlanGrant::VERBS], $subjects);
        $this->assertContains([PlanSubject::TYPE_USER_GROUP, (int) $group->id, [PlanGrant::VERB_LIRE]], $subjects);
    }

    /**
     * L'invariant montage-seul, isolé : un partage assigné à un SEUL parc produit
     * un plan honnête — une racine, aucun octroi. L'aperçu le dira.
     */
    #[Test]
    public function a_share_assigned_only_to_a_workstation_group_grants_nothing(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'depot']);
        $wg = WorkstationGroup::create(['name' => 'salle-102', 'display_name' => 'Salle 102']);
        $this->assign($share, WorkstationGroup::class, (int) $wg->id, 'rw');

        $this->assertSame([], $this->projector()->project($share->fresh())->node(PlanNode::ROOT_PATH)->grants);
    }

    #[Test]
    public function a_share_without_any_assignment_projects_to_a_bare_root(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'vide']);

        $this->assertSame([], $this->projector()->project($share)->node(PlanNode::ROOT_PATH)->grants);
    }

    /**
     * Un type polymorphe hors vocabulaire (le pivot n'a pas de clé étrangère) est
     * ignoré plutôt que deviné : il n'y a rien à comprendre d'une ligne dont on ne
     * sait pas ce qu'elle désigne.
     */
    #[Test]
    public function an_assignment_of_an_unexpected_type_grants_nothing(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'depot']);
        DB::table('network_share_assignables')->insert([
            'network_share_id' => $share->id,
            'assignable_type' => 'App\\Models\\Inconnu',
            'assignable_id' => 42,
            'access' => 'rw',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], $this->projector()->project($share->fresh())->node(PlanNode::ROOT_PATH)->grants);
    }

    #[Test]
    public function an_unsafe_directory_name_fails_explicitly_never_a_partial_plan(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'depot']);
        DB::table('network_shares')->where('id', $share->id)->update(['directory_name' => '../evasion']);

        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessage('n\'est pas un segment de chemin sûr');

        $this->projector()->project($share->fresh());
    }

    #[Test]
    public function two_projections_of_the_same_share_are_identical_byte_for_byte(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'depot']);
        $a = User::create(['login' => 'zoe', 'role' => 'prof', 'is_active' => true]);
        $b = User::create(['login' => 'adam', 'role' => 'prof', 'is_active' => true]);
        $g = UserGroup::create(['name' => 'Equipe_Direction', 'type' => 'equipe']);

        $this->assign($share, User::class, (int) $a->id, 'ro');
        $this->assign($share, UserGroup::class, (int) $g->id, 'rw');
        $this->assign($share, User::class, (int) $b->id, 'rw');

        $first = $this->projector()->project($share->fresh())->toJson();
        $second = $this->projector()->project($share->fresh())->toJson();

        $this->assertSame($first, $second);
    }

    /**
     * LA COUPE, sur le cas le plus simple : le plan projeté ne porte aucun terme
     * de la couche d'exécution — pas de mode, pas de nom de groupe système, pas de
     * chemin absolu. Le groupe du décor s'appelle « Classe_3emeA », dont la
     * dérivation historique donnerait un nom de groupe système ; il n'apparaît pas.
     */
    #[Test]
    public function the_projected_plan_is_neutral(): void
    {
        $share = NetworkShare::factory()->create([
            'name' => 'Depot pedagogique',
            'directory_name' => 'depot_pedagogique',
        ]);
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $user = User::create(['login' => 'alice', 'role' => 'prof', 'is_active' => true]);
        $this->assign($share, UserGroup::class, (int) $group->id, 'ro');
        $this->assign($share, User::class, (int) $user->id, 'rw');

        $plan = $this->projector()->project($share->fresh());

        $this->assertPlanIsNeutral($plan, 'plan projeté depuis un partage plat');

        // Ni le login de l'utilisateur, ni le nom du groupe : le plan ne connaît
        // que des identités internes.
        $haystack = $this->plainTextOf($plan);
        $this->assertStringNotContainsString('alice', $haystack);
        $this->assertStringNotContainsString('Classe_3emeA', $haystack);
    }
}
