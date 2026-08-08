<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\PlanAnchor;
use App\Models\DirectoryTemplate;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\SharePlanProjector;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.5 — LE ROUTAGE : deux origines, un seul plan.
 *
 * Le garde-fou d'epic est ici : **aucun partage en place ne change de plan**. Un
 * partage sans origine se projette exactement comme avant, et c'est ce qui garantit
 * que la story n'a touché aucune ACL sur une instance existante.
 */
class SharePlanRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Process::fake([
            'getent group *' => Process::result(),
            '*' => Process::result(),
        ]);
        UserGroupObserver::disableSync();

        config([
            'filesystem.shares_root' => '/var/sambaedu/Partages',
            'filesystem.class_trees_root' => '/var/sambaedu/ClassesSE5',
        ]);

        (new DirectoryTemplateSeeder())->run();
    }

    protected function tearDown(): void
    {
        \App\Services\Filesystem\ClassTreeShareService::enable();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function flatShare(): NetworkShare
    {
        $share = NetworkShare::create(['name' => 'Publication', 'directory_name' => 'publication']);
        $group = UserGroup::create(['name' => 'direction', 'type' => 'equipe']);

        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'access' => 'rw',
        ]);

        return $share->fresh();
    }

    // =========================================================================
    // Le garde-fou d'epic : les partages en place ne bougent pas
    // =========================================================================

    /**
     * **NON-RÉGRESSION NOMMÉE.** Le plan d'un partage sans origine est OCTET POUR
     * OCTET celui que la projection plate produisait déjà. On ne compare pas deux
     * chemins qui pourraient dériver ensemble : on compare le plan routé au plan de
     * la projection plate, appelée directement.
     */
    #[Test]
    public function a_share_without_origin_keeps_exactly_its_previous_plan(): void
    {
        $share = $this->flatShare();

        $routed = app(NetworkShareService::class)->planFor($share);
        $direct = app(SharePlanProjector::class)->project($share);

        $this->assertSame($direct->toJson(), $routed->toJson());
        $this->assertSame(PlanAnchor::Reseau, $routed->anchor);
        $this->assertSame('publication', $routed->rootPath);
        $this->assertSame([PlanNode::ROOT_PATH], $routed->nodePaths());
    }

    /** La zone par DÉFAUT est celle des répertoires réseau : rien ne déménage. */
    #[Test]
    public function the_default_zone_is_the_network_directories_one(): void
    {
        $this->assertSame(PlanAnchor::Reseau, PlanAnchor::default());
        $this->assertSame(PlanAnchor::Reseau, app(SharePlanProjector::class)->project($this->flatShare())->anchor);
    }

    // =========================================================================
    // Le partage d'ARBRE : la recette gouverne, la zone suit
    // =========================================================================

    #[Test]
    public function a_share_with_a_tree_origin_is_projected_by_its_recipe_in_its_own_zone(): void
    {
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $share = NetworkShare::where('user_group_id', $group->id)->firstOrFail();

        $plan = app(NetworkShareService::class)->planFor($share);

        $this->assertSame(PlanAnchor::Classes, $plan->anchor);
        $this->assertSame('Classe_3emeA', $plan->rootPath);
        $this->assertSame(
            ['.', '_echange', '_profs', '_travail', '_travail/devoirs'],
            $plan->nodePaths(),
        );
        // Le nom de répertoire recopie le dernier segment de la racine résolue.
        $this->assertSame($plan->rootPath, $share->directory_name);
    }

    /**
     * **La surcharge d'INSTANCE : les assignations ajoutent des octrois sur la
     * racine**, et seulement sur elle. C'est ce qui laisse ouvrir un arbre à un
     * documentaliste sans toucher à la recette de tout le parc.
     */
    #[Test]
    public function instance_assignments_add_grants_on_the_root_and_nowhere_else(): void
    {
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $share = NetworkShare::where('user_group_id', $group->id)->firstOrFail();

        $documentaliste = User::factory()->create(['login' => 'cdi', 'source' => 'ad']);
        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => User::class,
            'assignable_id' => $documentaliste->id,
            'access' => 'rw',
        ]);

        $plan = app(NetworkShareService::class)->planFor($share->fresh());

        $root = $plan->node(PlanNode::ROOT_PATH);
        $this->assertNotNull($root);

        $subjects = array_map(
            static fn (PlanGrant $g): string => $g->subject->type . '#' . $g->subject->id,
            $root->grants,
        );
        $this->assertContains('user#' . $documentaliste->id, $subjects);

        // Et NULLE PART ailleurs : la surcharge est une propriété de la racine.
        foreach ($plan->nodes as $node) {
            if ($node->path === PlanNode::ROOT_PATH) {
                continue;
            }
            foreach ($node->grants as $grant) {
                $this->assertNotSame(
                    $documentaliste->id,
                    $grant->subject->type === 'user' ? $grant->subject->id : -1,
                    sprintf('la surcharge d\'instance a débordé sur « %s »', $node->path),
                );
            }
        }
    }

    /**
     * **Union AU PLUS PERMISSIF, et pas de doublon.** Une assignation qui vise un
     * sujet déjà présent dans la recette ne produit PAS une seconde entrée : elle
     * relève l'accès s'il est plus élevé, et se fond dans l'existante sinon. Deux
     * entrées identiques feraient relire l'état comme non conforme à chaque
     * passage, et le partage se réécrirait sans jamais converger.
     */
    #[Test]
    public function an_assignment_on_a_subject_the_recipe_already_carries_merges_at_the_highest_level(): void
    {
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $share = NetworkShare::where('user_group_id', $group->id)->firstOrFail();

        // La racine accorde déjà la lecture-traversée à la classe (rôle « self »).
        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'access' => 'rw',
        ]);

        $root = app(NetworkShareService::class)->planFor($share->fresh())->node(PlanNode::ROOT_PATH);
        $this->assertNotNull($root);

        $onTheClass = array_values(array_filter(
            $root->grants,
            static fn (PlanGrant $g): bool => $g->subject->type === 'user_group'
                && $g->subject->id === $group->id
                && $g->subject->edgeRole === null,
        ));

        $this->assertCount(1, $onTheClass, 'une seule entrée par sujet : un doublon empêcherait toute convergence');
        $this->assertSame(PlanGrant::VERBS, $onTheClass[0]->verbs, 'union des ENSEMBLES de verbes, au plus permissif');
    }

    /** Sans assignation, la surcharge ne touche à rien. */
    #[Test]
    public function a_tree_share_without_assignments_is_left_untouched_by_the_overlay(): void
    {
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $share = NetworkShare::where('user_group_id', $group->id)->firstOrFail();

        $plan = app(NetworkShareService::class)->planFor($share);
        $this->assertSame($plan->toJson(), app(SharePlanProjector::class)->withInstanceGrants($plan, $share)->toJson());
    }

    /**
     * **Un lien À MOITIÉ EFFACÉ ne se projette pas à moitié.** La suppression d'un
     * groupe met sa colonne à `null` sans emporter le partage : celui-ci retombe
     * alors sur la projection ordinaire, qui décrit l'existant sans rien inventer.
     * Un plan APPAUVRI serait pris pour l'état désiré et retirerait des accès.
     */
    #[Test]
    public function a_half_broken_origin_falls_back_to_the_ordinary_projection(): void
    {
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $share = NetworkShare::where('user_group_id', $group->id)->firstOrFail();

        $group->delete();
        $share = $share->fresh();

        $this->assertNull($share->user_group_id);
        $this->assertFalse($share->hasRecipeOrigin());

        $plan = app(NetworkShareService::class)->planFor($share);
        $this->assertSame(PlanAnchor::Reseau, $plan->anchor);
        $this->assertSame([PlanNode::ROOT_PATH], $plan->nodePaths());
    }

    /**
     * Supprimer la RECETTE délie le partage — elle ne l'emporte pas, et elle ne le
     * laisse pas non plus pointer dans le vide (D9 : aucune destruction implicite,
     * et aucun état intermédiaire à interpréter).
     */
    #[Test]
    public function deleting_the_recipe_unties_the_share_without_destroying_it(): void
    {
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $share = NetworkShare::where('user_group_id', $group->id)->firstOrFail();

        DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->delete();

        $share = $share->fresh();
        $this->assertNotNull($share, 'le partage survit à la disparition de sa recette');
        $this->assertNull($share->directory_template_id);
        $this->assertFalse($share->hasRecipeOrigin());
    }

    /**
     * **Défense en profondeur : un lien qui ne se charge pas fait échouer la
     * projection EXPLICITEMENT.**
     *
     * La clé étrangère rend ce cas inatteignable PAR LA BASE — c'est une bonne
     * nouvelle, et c'est pourquoi l'état est fabriqué en mémoire ici. Il reste
     * atteignable autrement : une restauration de base sans contraintes, une
     * correction manuelle, un modèle assemblé en code. La réponse doit alors être un
     * refus net — un repli sur la projection ordinaire rendrait un plan APPAUVRI
     * (les audiences de la recette manqueraient), que la réconciliation prendrait
     * pour l'état désiré, et matérialiserait en RETIRANT des accès.
     */
    #[Test]
    public function an_unloadable_origin_fails_explicitly_instead_of_falling_back(): void
    {
        $share = $this->flatShare();
        $share->forceFill(['directory_template_id' => 999_999, 'user_group_id' => 999_999]);

        $this->assertTrue($share->hasRecipeOrigin());

        $this->expectException(\App\Exceptions\Filesystem\PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/ne se chargent pas/u');

        app(NetworkShareService::class)->planFor($share);
    }
}
