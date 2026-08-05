<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Shares;

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
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 60.5 — la FICHE d'un partage d'ARBRE.
 *
 * Trois choses y arrivent, et chacune répond à une question que l'arbre a rendue
 * pressante : où ce partage vit-il vraiment, quels dossiers sont suspendus, et —
 * puisqu'un arbre a quatre nœuds plus un par élève — LEQUEL a échoué.
 */
class ClassTreeShareDetailTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.shares.[id].index';

    private UserGroup $classe;

    private NetworkShare $share;

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

        foreach (['networkshare.view', 'networkshare.manage'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        (new DirectoryTemplateSeeder())->run();

        $this->classe = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $this->share = NetworkShare::where('user_group_id', $this->classe->id)->firstOrFail();
    }

    protected function tearDown(): void
    {
        ClassTreeShareService::enable();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function manager(): User
    {
        $u = User::create(['login' => 'mgr-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $u->givePermissionTo('networkshare.view');
        $u->givePermissionTo('networkshare.manage');

        return $u;
    }

    private function viewer(): User
    {
        $u = User::create(['login' => 'view-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $u->givePermissionTo('networkshare.view');

        return $u;
    }

    // =========================================================================
    // AC5 — le partage d'arbre EST un partage ordinaire, avec son chemin réel
    // =========================================================================

    #[Test]
    public function the_sheet_shows_the_origin_and_the_real_server_location(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => (string) $this->share->id])
            ->assertSee('Classe (arbre de partage)')
            ->assertSee('Classe_3emeA')
            ->assertSee('/var/sambaedu/ClassesSE5/Classe_3emeA');
    }

    #[Test]
    public function a_tree_share_appears_in_the_list_like_any_other(): void
    {
        $this->actingAs($this->manager());

        Livewire::test('pages::admin.shares.index')
            ->assertSee('Classe_3emeA');
    }

    // =========================================================================
    // AC7 — l'activation est une donnée d'instance
    // =========================================================================

    #[Test]
    public function the_activable_nodes_of_the_recipe_are_listed_with_their_state(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => (string) $this->share->id])
            ->assertSee('Dossiers activables')
            ->assertSee('Espace d\'échange')
            ->assertSee('Actif');
    }

    #[Test]
    public function toggling_a_node_persists_the_state_and_queues_the_reconciliation(): void
    {
        $this->actingAs($this->manager());
        Queue::fake();

        Livewire::test(self::PAGE, ['id' => (string) $this->share->id])
            ->call('toggleNode', '_echange')
            ->assertSee('Suspendu');

        $this->assertSame(['_echange' => false], $this->share->fresh()->nodeActivation());
        Queue::assertPushed(ReconcileNetworkShareJob::class);
    }

    /**
     * Suspendre VIDE l'octroi ; le dossier et les données restent. On l'éprouve là
     * où c'est vrai — sur les entrées compilées — pas sur une intention d'écran.
     */
    #[Test]
    public function suspending_a_node_empties_the_grant_without_removing_anything(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => (string) $this->share->id])->call('toggleNode', '_echange');

        $plan = app(NetworkShareService::class)->planFor($this->share->fresh());
        $node = $plan->node('_echange');

        $this->assertNotNull($node);
        $this->assertFalse($node->active);
        $this->assertNotSame([], $node->suspendedGrants(), 'l\'octroi existe encore : il est vidé, pas retiré');

        $acls = app(\App\Services\Filesystem\Backend\Posix\PosixAclCompiler::class)->compile($node)->acls;
        $this->assertContains('group:classe_3emea:---', $acls);
    }

    #[Test]
    public function toggling_back_restores_the_active_state(): void
    {
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE, ['id' => (string) $this->share->id]);
        $component->call('toggleNode', '_echange');
        $component->call('toggleNode', '_echange');

        $this->assertSame(['_echange' => true], $this->share->fresh()->nodeActivation());
    }

    /** Un chemin qui n'est pas un nœud activable est REFUSÉ, jamais enregistré. */
    #[Test]
    public function an_unknown_node_path_is_refused_instead_of_creating_an_orphan_entry(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => (string) $this->share->id])
            ->call('toggleNode', '_travail');

        $this->assertSame([], $this->share->fresh()->nodeActivation());
    }

    /** Double garde : l'écran cache le bouton, le serveur refuse le geste. */
    #[Test]
    public function a_viewer_cannot_toggle_a_node(): void
    {
        $this->actingAs($this->viewer());

        Livewire::test(self::PAGE, ['id' => (string) $this->share->id])
            ->call('toggleNode', '_echange')
            ->assertForbidden();

        $this->assertSame([], $this->share->fresh()->nodeActivation());
    }

    // =========================================================================
    // T6 — le dernier rapport, PAR NŒUD
    // =========================================================================

    #[Test]
    public function the_queued_reconciliation_shows_a_pending_report_for_every_node(): void
    {
        $this->actingAs($this->manager());
        app(NetworkShareService::class)->queueReconciliation($this->share);

        Livewire::test(self::PAGE, ['id' => (string) $this->share->id])
            ->assertSee('Dernier passage sur les droits')
            ->assertSee('(racine)')
            ->assertSee('_echange');
    }

    /**
     * Les TRANSITIONS : en attente → rapport réel → échec de préparation → rapport
     * sain. Un rapport périmé qui survivrait à un échec ferait dire à l'écran
     * « engagé » pour toujours.
     */
    #[Test]
    public function the_report_follows_the_transitions_without_ever_going_stale(): void
    {
        $this->actingAs($this->manager());
        $service = app(NetworkShareService::class);

        $service->queueReconciliation($this->share);
        $pending = Livewire::test(self::PAGE, ['id' => (string) $this->share->id])->get('lastReport');
        $this->assertNotNull($pending);
        $this->assertSame(
            ['en_attente'],
            array_values(array_unique(array_column($pending['nodes'], 'outcome'))),
        );

        $service->reconcile($this->share, 'test');
        $applied = Livewire::test(self::PAGE, ['id' => (string) $this->share->id])->get('lastReport');
        $this->assertNotNull($applied);
        $this->assertNotContains('en_attente', array_column($applied['nodes'], 'outcome'));

        // Échec de PRÉPARATION : le rapport périmé doit CÉDER LA PLACE. On rend le
        // nom du groupe inexploitable — la résolution refuse alors le plan entier,
        // plutôt que d'en rendre un amputé.
        $this->classe->forceFill(['name' => '..'])->saveQuietly();

        $service->reconcile($this->share->fresh(), 'test');

        $after = Livewire::test(self::PAGE, ['id' => (string) $this->share->id]);
        $this->assertNull($after->get('lastReport'), 'un rapport périmé ne survit pas à un échec de préparation');
        $this->assertNotNull($after->get('reconciliationFailure'));
    }

    /**
     * **Neutralité du HTML rendu** : aucun mode de permission, aucun nom de groupe
     * système ne remonte à l'écran. Le vocabulaire du serveur de fichiers vit sous
     * la ligne de contrat, y compris quand il s'agit de rendre compte d'un échec.
     */
    #[Test]
    public function the_rendered_page_never_speaks_the_file_server_vocabulary(): void
    {
        $this->actingAs($this->manager());
        app(NetworkShareService::class)->reconcile($this->share, 'test');

        $html = Livewire::test(self::PAGE, ['id' => (string) $this->share->id])->html();

        foreach (['rwx', 'r-x', 'setfacl', 'getfacl', 'classe_3emea', 'equipe_3emea', 'domain\040admins'] as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $html,
                sprintf('le marqueur « %s » de la couche du dessous a fuité dans le HTML rendu', $marker),
            );
        }
    }
}
