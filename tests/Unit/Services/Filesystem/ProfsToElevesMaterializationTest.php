<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\PlanAnchor;
use App\Models\DirectoryTemplate;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\Backend\Posix\PosixAclCompiler;
use App\Services\Filesystem\ClassTreeShareService;
use App\Services\Filesystem\DirectoryTemplateService;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\Plan\PlanNode;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.5 — **« profs → élèves » est PROUVÉE matérialisable, pas juste
 * re-seedée.**
 *
 * La recette était inutilisable depuis cinq semaines : elle contraignait un type de
 * groupe que l'import d'annuaire ne produit plus. La réparer, c'est la recâbler sur
 * le rôle d'arête ; le PROUVER, c'est aller jusqu'aux entrées compilées — un
 * re-seed qui se contenterait de changer la donnée laisserait exactement le même
 * doute qu'avant.
 *
 * Le test descend donc toute la chaîne : classe choisie → partage matérialisé →
 * plan projeté → entrées compilées.
 */
class ProfsToElevesMaterializationTest extends TestCase
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
        UserGroupUserPivotObserver::disableSync();
        ClassTreeShareService::disable();

        config(['filesystem.shares_root' => '/var/sambaedu/Partages']);

        (new DirectoryTemplateSeeder())->run();
    }

    protected function tearDown(): void
    {
        ClassTreeShareService::enable();
        UserGroupUserPivotObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    /**
     * De bout en bout : une classe choisie donne DEUX octrois compilés — les
     * enseignants en écriture, les élèves en lecture.
     */
    #[Test]
    public function a_chosen_class_yields_exactly_two_compiled_audience_entries(): void
    {
        $classe = UserGroup::create(['name' => 'Classe_6eB', 'type' => 'classe']);
        $prof = User::factory()->create(['login' => 'pdupont', 'source' => 'ad']);
        $eleve = User::factory()->create(['login' => 'eleve01', 'source' => 'ad']);
        $classe->users()->attach($prof->id, ['role' => 'manager']);
        $classe->users()->attach($eleve->id, ['role' => 'member']);

        $result = app(DirectoryTemplateService::class)->materialize(
            DirectoryTemplate::where('key', DirectoryTemplate::KEY_PROFS_TO_ELEVES)->firstOrFail(),
            [
                'name' => 'Devoirs 6eB',
                'directory_name' => 'devoirs_6eb',
                'group_id' => $classe->id,
            ],
            deferProvisioning: true,
        );

        $plan = app(NetworkShareService::class)->planFor($result->share->fresh());

        // Le partage reste PLAT et dans la zone des répertoires réseau : un seul
        // nœud, sa racine, nommée par l'administrateur.
        $this->assertSame(PlanAnchor::Reseau, $plan->anchor);
        $this->assertSame('devoirs_6eb', $plan->rootPath);
        $this->assertSame([PlanNode::ROOT_PATH], $plan->nodePaths());

        $node = $plan->node(PlanNode::ROOT_PATH);
        $this->assertNotNull($node);

        $acls = app(PosixAclCompiler::class)->compile($node)->acls;

        $audience = array_values(array_filter(
            $acls,
            static fn (string $line): bool => str_starts_with($line, 'group:')
                && ! str_starts_with($line, 'group::')
                && ! str_contains($line, 'domain'),
        ));

        sort($audience);
        $this->assertSame(
            ['group:classe_6eb:rx', 'group:equipe_6eb:rwx'],
            $audience,
            'la recette réparée doit compiler exactement deux audiences : les enseignants de la classe '
            . 'en écriture, les élèves en lecture.',
        );
    }

    /**
     * L'audience « enseignants » vient du RÔLE D'ARÊTE, et elle existe même quand
     * personne ne le porte encore : c'est un sujet STRUCTUREL, pas un effectif.
     */
    #[Test]
    public function the_teaching_audience_survives_a_class_without_any_teacher_yet(): void
    {
        $classe = UserGroup::create(['name' => 'Classe_6eC', 'type' => 'classe']);

        $result = app(DirectoryTemplateService::class)->materialize(
            DirectoryTemplate::where('key', DirectoryTemplate::KEY_PROFS_TO_ELEVES)->firstOrFail(),
            ['name' => 'Devoirs 6eC', 'directory_name' => 'devoirs_6ec', 'group_id' => $classe->id],
            deferProvisioning: true,
        );

        $plan = app(NetworkShareService::class)->planFor($result->share->fresh());
        $node = $plan->node(PlanNode::ROOT_PATH);
        $this->assertNotNull($node);

        $acls = app(PosixAclCompiler::class)->compile($node)->acls;
        $this->assertContains('group:equipe_6ec:rwx', $acls);
    }

    /**
     * **Elle ne se matérialise PAS toute seule.** Son accrochage lui donne
     * l'auto-résolution de ses cibles, pas la matérialisation automatique : sinon
     * chacune des classes d'une instance naîtrait avec un partage que personne n'a
     * demandé.
     */
    #[Test]
    public function attaching_a_flat_recipe_never_materializes_anything_on_group_creation(): void
    {
        ClassTreeShareService::enable();

        // On retire la recette d'ARBRE : il ne reste que la recette PLATE accrochée
        // au type `classe`. Si le déclencheur n'était pas scopé, la création
        // ci-dessous produirait un partage.
        DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->delete();

        $group = UserGroup::create(['name' => 'Classe_6eD', 'type' => 'classe']);

        $this->assertNull(app(ClassTreeShareService::class)->recipeFor($group));
        $this->assertSame(0, \App\Models\NetworkShare::count());
    }
}
