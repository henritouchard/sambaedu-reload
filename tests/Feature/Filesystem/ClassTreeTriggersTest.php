<?php

declare(strict_types=1);

namespace Tests\Feature\Filesystem;

use App\Jobs\ReconcileNetworkShareJob;
use App\Models\DirectoryTemplate;
use App\Models\NetworkShare;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\Filesystem\ClassTreeShareService;
use App\Services\Filesystem\NetworkShareService;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.5 — LES DÉCLENCHEURS de l'arbre NEUF.
 *
 * Trois entrées, trois propriétés à tenir :
 *  - créer un groupe matérialise son arbre — mais SEULEMENT si son type porte une
 *    recette d'ARBRE, et sans jamais casser la création du groupe ;
 *  - un changement d'appartenance ou de rôle d'arête enfile la réconciliation des
 *    arbres EXISTANTS — il n'en crée aucun ;
 *  - la voie de l'arbre historique reste STRICTEMENT en place, à côté.
 */
class ClassTreeTriggersTest extends TestCase
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
        // Seule la projection d'annuaire est suspendue : le canal FICHIERS a son
        // propre interrupteur, et c'est LUI qu'on éprouve ici.
        UserGroupObserver::disableSync();

        config([
            'filesystem.shares_root' => '/var/sambaedu/Partages',
            'filesystem.class_trees_root' => '/var/sambaedu/ClassesSE5',
        ]);

        (new DirectoryTemplateSeeder())->run();
    }

    protected function tearDown(): void
    {
        ClassTreeShareService::enable();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function treeShareOf(UserGroup $group): ?NetworkShare
    {
        return NetworkShare::where('user_group_id', $group->id)->first();
    }

    // =========================================================================
    // Créer un groupe matérialise son arbre
    // =========================================================================

    #[Test]
    public function creating_a_class_group_materializes_its_tree_and_queues_the_reconciliation(): void
    {
        $group = UserGroup::create(['name' => 'Classe_5emeB', 'type' => 'classe']);

        $share = $this->treeShareOf($group);

        $this->assertNotNull($share, 'la création d\'une classe doit relier son arbre');
        $this->assertSame('Classe_5emeB', $share->directory_name);
        $this->assertSame(
            DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->value('id'),
            $share->directory_template_id,
        );

        // Un partage d'arbre naît SANS LETTRE : rien ne dit qu'il doit être monté,
        // et une lettre est une ressource rare et globale.
        $this->assertNull($share->letter);

        Queue::assertPushed(ReconcileNetworkShareJob::class);
    }

    #[Test]
    public function the_materialization_is_idempotent_and_never_duplicates_a_tree(): void
    {
        $group = UserGroup::create(['name' => 'Classe_5emeB', 'type' => 'classe']);
        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();

        app(ClassTreeShareService::class)->ensureShare($group, $template);
        app(ClassTreeShareService::class)->ensureShare($group, $template);

        $this->assertSame(1, NetworkShare::where('user_group_id', $group->id)->count());
    }

    /** Un type sans recette d'arbre ne produit RIEN. */
    #[Test]
    public function a_group_type_without_a_tree_recipe_materializes_nothing(): void
    {
        UserGroup::create(['name' => 'Robotique', 'type' => 'projet']);

        $this->assertSame(0, NetworkShare::count());
        Queue::assertNotPushed(ReconcileNetworkShareJob::class);
    }

    /**
     * **Le déclencheur est SCOPÉ aux recettes d'ARBRE.** Deux recettes s'accrochent
     * au type `classe` ; si l'accrochage suffisait, chaque classe créée naîtrait
     * AUSSI avec un partage plat « profs → élèves » que personne n'a demandé.
     */
    #[Test]
    public function an_attached_flat_recipe_is_never_materialized_by_the_creation_trigger(): void
    {
        $group = UserGroup::create(['name' => 'Classe_5emeB', 'type' => 'classe']);

        $shares = NetworkShare::where('user_group_id', $group->id)->get();

        $this->assertCount(1, $shares, 'une seule matérialisation automatique : l\'ARBRE');
        $this->assertSame(
            DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->value('id'),
            $shares->first()->directory_template_id,
        );
    }

    /** L'interrupteur du canal fichiers est DÉDIÉ, et il suspend bien ce canal-là. */
    #[Test]
    public function the_dedicated_switch_suspends_the_file_channel_alone(): void
    {
        ClassTreeShareService::disable();

        UserGroup::create(['name' => 'Classe_5emeB', 'type' => 'classe']);

        $this->assertSame(0, NetworkShare::count());
    }

    /**
     * **FAIL-SOFT : un échec de matérialisation ne casse JAMAIS la création du
     * groupe**, et il reste visible.
     */
    #[Test]
    public function a_failing_materialization_never_breaks_the_group_creation(): void
    {
        // Un nom dont la racine de plan est indérivable : la résolution échoue.
        $group = UserGroup::create(['name' => '..', 'type' => 'classe']);

        $this->assertTrue($group->exists, 'la création du groupe doit aboutir malgré l\'échec de l\'arbre');
        $this->assertSame(0, NetworkShare::count());
    }

    /** La SUPPRESSION d'un groupe ne déprovisionne rien (D9). */
    #[Test]
    public function deleting_a_group_never_deprovisions_its_tree(): void
    {
        $group = UserGroup::create(['name' => 'Classe_5emeB', 'type' => 'classe']);
        $shareId = $this->treeShareOf($group)?->id;
        $this->assertNotNull($shareId);

        $group->delete();

        $share = NetworkShare::find($shareId);
        $this->assertNotNull($share, 'la ligne du partage survit : l\'administrateur décide');
        $this->assertNull($share->user_group_id, 'le lien est délié, jamais suivi d\'une destruction');
    }

    // =========================================================================
    // L'appartenance tient l'arbre à jour
    // =========================================================================

    #[Test]
    public function attaching_a_member_queues_the_reconciliation_of_the_existing_tree(): void
    {
        $group = UserGroup::create(['name' => 'Classe_5emeB', 'type' => 'classe']);
        Queue::fake();

        $user = User::factory()->create(['login' => 'eleve01', 'source' => 'ad']);
        $group->users()->attach($user->id, ['role' => 'member']);

        Queue::assertPushed(ReconcileNetworkShareJob::class);
    }

    #[Test]
    public function changing_an_edge_role_queues_the_reconciliation_too(): void
    {
        $group = UserGroup::create(['name' => 'Classe_5emeB', 'type' => 'classe']);
        $user = User::factory()->create(['login' => 'pdupont', 'source' => 'ad']);
        $group->users()->attach($user->id, ['role' => 'member']);

        Queue::fake();
        $group->users()->updateExistingPivot($user->id, ['role' => 'manager']);

        Queue::assertPushed(ReconcileNetworkShareJob::class);
    }

    /**
     * **Un rattachement ne CRÉE jamais de partage.** Sans arbre existant, il n'y a
     * rien à réconcilier — et le fabriquer ici serait la matérialisation par
     * surprise que la story refuse partout ailleurs.
     */
    #[Test]
    public function a_membership_change_never_creates_a_tree(): void
    {
        ClassTreeShareService::disable();
        $group = UserGroup::create(['name' => 'Classe_5emeB', 'type' => 'classe']);
        ClassTreeShareService::enable();

        $this->assertSame(0, NetworkShare::count());

        $user = User::factory()->create(['login' => 'eleve01', 'source' => 'ad']);
        $group->users()->attach($user->id, ['role' => 'member']);

        $this->assertSame(0, NetworkShare::count(), 'un rattachement n\'est pas une demande de partage');
    }

    /**
     * Le nœud personnel d'un nouvel élève apparaît PAR LE PLAN, jamais par un
     * chemin parallèle qui saurait créer un dossier tout seul.
     */
    #[Test]
    public function a_new_student_gets_a_personal_node_through_the_plan(): void
    {
        $group = UserGroup::create(['name' => 'Classe_5emeB', 'type' => 'classe']);
        $share = $this->treeShareOf($group);
        $this->assertNotNull($share);

        $user = User::factory()->create(['login' => 'eleve01', 'source' => 'ad']);
        $group->users()->attach($user->id, ['role' => 'member']);

        $plan = app(NetworkShareService::class)->planFor($share->fresh());

        $this->assertContains('eleve01', $plan->nodePaths());
    }
}
