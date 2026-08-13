<?php

declare(strict_types=1);

namespace Tests\Feature\Filesystem\Backend\OpenCloud;

use App\Enums\FileBackendName;
use App\Enums\FileBackendObservation;
use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\FilePolicyService;
use App\Services\Filesystem\Backend\FileBackendRegistry;
use App\Services\Filesystem\Backend\OpenCloud\OpenCloudFileBackend;
use App\Services\Filesystem\Backend\OpenCloud\OpenCloudRoleTable;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LE BACKEND OPENCLOUD, DE BOUT EN BOUT, CONTRE UNE INSTANCE EN MÉMOIRE.
 *
 * Le décor est celui du partage de classe : une racine, un espace de travail, un
 * espace des enseignants (où la classe n'a RIEN — c'est la clôture), un espace
 * d'échange suspendable, et un dossier personnel par membre. C'est le décor qui a
 * fait naître la clôture calculée.
 *
 * **Toutes les réponses rejouées viennent du relevé du 2026-08-13** contre une
 * instance réelle : voir {@see FakeOpenCloudInstance}. Aucun corps n'est inventé.
 */
class OpenCloudFileBackendTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://nuage.exemple.fr';

    private const ROOT = 'Classe_3A';

    private const GROUP_MEMBERS = 'se5_3a_member';

    private const GROUP_MANAGERS = 'se5_3a_manager';

    private const VIEW_ITEM = 'b1e2218d-eef8-4d4c-b82d-0f1a1b48f3b5';

    private const EDIT_ITEM = 'fb6c3e19-e378-47e5-b277-9732f9de6e21';

    private const VIEW_SPACE = 'a8d5fe5e-96e3-418d-825b-534dbdf22b99';

    private const EDIT_SPACE = '58c63c02-1d89-4572-916a-870abc5a1b7d';

    private UserGroup $classe;

    private User $alice;

    private User $bruno;

    private User $prof;

    private FakeOpenCloudInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        // La projection d'annuaire n'a rien à voir avec ce backend : ce sont des
        // groupes OPENCLOUD que la traduction compile, pas des groupes d'annuaire.
        UserGroupObserver::disableSync();
        Queue::fake();

        FilePolicyService::setGlobal(
            true, true, false, '', null, null, null,
            true, self::URL, 'admin', true,
        );
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret');

        $this->classe = UserGroup::query()->create(['name' => '3A', 'type' => 'classe']);

        $this->instance = new FakeOpenCloudInstance();
        $this->instance->baseUrl = self::URL;

        $this->alice = $this->user('alice');
        $this->bruno = $this->user('bruno');
        $this->prof = $this->user('prof1');

        $this->classe->users()->attach($this->alice->id, ['role' => 'member']);
        $this->classe->users()->attach($this->bruno->id, ['role' => 'member']);
        $this->classe->users()->attach($this->prof->id, ['role' => 'manager']);

        $this->instance->install();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function user(string $login): User
    {
        $user = User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true, 'source' => 'ad']);
        $user->opencloud_user_id = $this->instance->withUser($login);
        $user->saveQuietly();

        return $user->fresh();
    }

    private function backend(): OpenCloudFileBackend
    {
        return app(FileBackendRegistry::class)->get(FileBackendName::OpenCloud);
    }

    // =========================================================================
    // Le plan d'épreuve
    // =========================================================================

    /**
     * @param  bool  $grantAtRoot  le plan octroie-t-il sur sa RACINE ? C'est la
     *                             variable qui décide de l'expressivité de la clôture.
     */
    private function plan(bool $echangeSuspended = false, bool $grantAtRoot = false): FilePlan
    {
        $members = PlanSubject::group((int) $this->classe->id, 'member');
        $managers = PlanSubject::group((int) $this->classe->id, 'manager');
        $aliceSubject = PlanSubject::user((int) $this->alice->id);

        $roles = ['classe' => [$members], 'equipe' => [$managers]];

        $rootGrants = $grantAtRoot
            ? [
                new PlanGrant('equipe', $managers, PlanGrant::VERBS),
                new PlanGrant('classe', $members, [PlanGrant::VERB_LIRE]),
            ]
            : [];

        $nodes = [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::Partagee, $rootGrants, true, null,
                $grantAtRoot ? [] : ['classe', 'equipe']),

            new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, [
                new PlanGrant('equipe', $managers, PlanGrant::VERBS),
                new PlanGrant('classe', $members, [PlanGrant::VERB_LIRE]),
            ], true, null, []),

            // LE nœud de la clôture : la classe n'a AUCUN octroi ici.
            new PlanNode('_profs', 'Enseignants', PlanNodeNature::Partagee, [
                new PlanGrant('equipe', $managers, PlanGrant::VERBS),
            ], true, null, ['classe']),

            // LE nœud de la suspension : l'octroi EXISTE et ne donne rien.
            new PlanNode('_echange', 'Échange', PlanNodeNature::Activable, [
                new PlanGrant('equipe', $managers, PlanGrant::VERBS),
                new PlanGrant('classe', $members, PlanGrant::VERBS, true, $echangeSuspended),
            ], ! $echangeSuspended, null, []),

            // LE nœud nominatif : la classe est refermée, l'élève y garde son accès.
            new PlanNode('alice', 'Dossier personnel', PlanNodeNature::ParMembre, [
                new PlanGrant('__member__', $aliceSubject, PlanGrant::VERBS),
                new PlanGrant('equipe', $managers, PlanGrant::VERBS),
            ], true, null, ['classe']),
        ];

        return new FilePlan('classe_share', self::ROOT, $roles, $nodes);
    }

    /**
     * LE PLAN À DEUX ÉTAGES — celui qui rend la propagation d'ancêtre VISIBLE.
     *
     * Un octroi posé sur `_travail` (nœud INTERMÉDIAIRE, pas la racine) rend son
     * enfant navigable : le relevé le dit dans sa propre colonne de preuve
     * (`PROPFIND …/_travail` → `207`, hrefs `_travail/` **et**
     * `_travail/devoirs/`). Un plan à un seul étage ne peut pas voir ce cas.
     *
     * @param  bool  $ancestorGrants  l'ancêtre non racine octroie-t-il à la classe ?
     */
    private function depthTwoPlan(bool $ancestorGrants = true): FilePlan
    {
        $members = PlanSubject::group((int) $this->classe->id, 'member');
        $managers = PlanSubject::group((int) $this->classe->id, 'manager');
        $roles = ['classe' => [$members], 'equipe' => [$managers]];

        $travailGrants = [new PlanGrant('equipe', $managers, PlanGrant::VERBS)];
        if ($ancestorGrants) {
            $travailGrants[] = new PlanGrant('classe', $members, [PlanGrant::VERB_LIRE]);
        }

        $nodes = [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::Partagee, [], true, null, []),
            new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, $travailGrants, true, null, []),
            // Le descendant qui PRÉTEND refermer la classe.
            new PlanNode('_travail/devoirs', 'Devoirs', PlanNodeNature::Partagee, [
                new PlanGrant('equipe', $managers, PlanGrant::VERBS),
            ], true, null, ['classe']),
        ];

        return new FilePlan('classe_share', self::ROOT, $roles, $nodes);
    }

    private function spaceId(): string
    {
        foreach ($this->instance->spaces as $id => $space) {
            if ($space['name'] === self::ROOT) {
                return $id;
            }
        }

        self::fail('aucun espace ne porte le plan');
    }

    // =========================================================================
    // provision
    // =========================================================================

    #[Test]
    public function a_plan_becomes_a_project_space_with_its_groups_tree_and_grants(): void
    {
        $report = $this->backend()->provision($this->plan());

        self::assertSame($this->plan()->nodePaths(), array_column($report->toArray()['nodes'], 'path'));
        self::assertSame([], $report->failures(), json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));

        // L'espace existe, au nom du plan.
        self::assertCount(1, $this->instance->spaces);
        $space = $this->spaceId();

        // Les groupes compilés existent, et leur appartenance reflète le plan.
        $membersGroup = $this->instance->groupIdOf(self::GROUP_MEMBERS);
        $managersGroup = $this->instance->groupIdOf(self::GROUP_MANAGERS);
        self::assertNotNull($membersGroup);
        self::assertNotNull($managersGroup);
        self::assertSame(
            [$this->alice->opencloud_user_id, $this->bruno->opencloud_user_id],
            $this->sorted($this->instance->groups[$membersGroup]['members']),
        );
        self::assertSame([$this->prof->opencloud_user_id], $this->instance->groups[$managersGroup]['members']);

        // L'arborescence est là, un niveau à la fois.
        foreach (['_travail', '_profs', '_echange', 'alice'] as $node) {
            self::assertArrayHasKey($node, $this->instance->items[$space], $node);
        }

        // LA CLÔTURE, PAR CONSTRUCTION : la classe n'a AUCUN octroi sur l'espace
        // des enseignants — et comme rien n'est octroyé à la racine, elle n'y a
        // aucun accès. Il n'y a rien à refermer parce qu'il n'y a rien d'ouvert.
        $profs = $this->instance->grantsOn($space, $this->instance->items[$space]['_profs']);
        self::assertArrayNotHasKey($membersGroup, $profs, 'la classe ne doit RIEN avoir sur l\'espace des enseignants');
        self::assertSame(self::EDIT_ITEM, $profs[$managersGroup] ?? null);

        // Et rien n'est octroyé à la racine : c'est ce qui rend la clôture vraie.
        self::assertSame([], $this->instance->grantsOn($space, null));
    }

    /**
     * **LES DEUX FAMILLES DE RÔLES SONT DISTINGUÉES.** Un rôle de sous-dossier
     * posé sur la racine rend `400 « role not applicable to this resource »` — et
     * un backend qui les confondrait échouerait sur tous les plans qui octroient à
     * leur racine, c'est-à-dire les plus courants.
     */
    #[Test]
    public function the_root_uses_the_space_role_family_and_a_node_uses_the_item_family(): void
    {
        $report = $this->backend()->provision($this->plan(grantAtRoot: true));

        self::assertSame([], $report->failures(), json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));

        $space = $this->spaceId();
        $membersGroup = (string) $this->instance->groupIdOf(self::GROUP_MEMBERS);
        $managersGroup = (string) $this->instance->groupIdOf(self::GROUP_MANAGERS);

        $root = $this->instance->grantsOn($space, null);
        self::assertSame(self::VIEW_SPACE, $root[$membersGroup] ?? null);
        self::assertSame(self::EDIT_SPACE, $root[$managersGroup] ?? null);

        $travail = $this->instance->grantsOn($space, $this->instance->items[$space]['_travail']);
        self::assertSame(self::VIEW_ITEM, $travail[$membersGroup] ?? null);
        self::assertSame(self::EDIT_ITEM, $travail[$managersGroup] ?? null);
    }

    /**
     * **SECOND PASSAGE = ZÉRO ÉCRITURE**, et c'est COMPTÉ.
     *
     * C'est le test pivot de l'idempotence : il ne suffit pas que le second
     * passage rende « conforme », il faut qu'il n'ÉMETTE rien. Un backend qui
     * rejouerait ses écritures « puisque c'est idempotent » serait vert ici sans ce
     * comptage — et sur cette instance, il ne le serait même pas : rejouer une
     * invitation rend `409`.
     */
    #[Test]
    public function a_second_pass_reports_conforming_everywhere_and_writes_nothing(): void
    {
        $this->backend()->provision($this->plan());

        $this->instance->resetWrites();
        $report = $this->backend()->provision($this->plan());

        self::assertSame([], $this->instance->writes, 'un second passage NE DOIT émettre aucune écriture');

        foreach ($report->entries as $entry) {
            self::assertSame(
                FileBackendOutcome::Conforme,
                $entry->outcome,
                $entry->path . ' : ' . (string) $entry->detail,
            );
        }
    }

    /**
     * **UN ESPACE HOMONYME EST LE MÊME OBJET — ET NE PAS L'ADOPTER COÛTERAIT CHER.**
     *
     * Mesuré : ce produit n'a AUCUNE idempotence de création d'espace. Deux
     * créations du même nom rendent deux `201` et laissent deux espaces. Un backend
     * qui créerait sans lire fabriquerait donc un espace de plus à chaque
     * réconciliation.
     */
    #[Test]
    public function an_existing_space_of_the_same_name_is_adopted_never_duplicated(): void
    {
        $adopted = $this->instance->withSpace(self::ROOT);

        $this->backend()->provision($this->plan());

        self::assertCount(1, $this->instance->spaces, 'un second espace a été créé : l\'adoption a échoué');
        self::assertSame($adopted, $this->spaceId());
    }

    /**
     * **UNE ZONE ÉTRANGÈRE N'EST NI TOUCHÉE NI RAPPORTÉE** : hors du plan, hors du
     * geste.
     */
    #[Test]
    public function a_space_outside_the_plan_is_left_strictly_alone(): void
    {
        $foreign = $this->instance->withSpace('Espace_cree_a_la_main', 'rien à voir');
        $this->instance->withFolder($foreign, 'documents');

        $this->backend()->provision($this->plan());

        self::assertArrayHasKey($foreign, $this->instance->spaces);
        self::assertSame(['documents'], array_keys($this->instance->items[$foreign]));
        self::assertSame([], $this->instance->grantsOn($foreign, null));
    }

    /**
     * **UN OCTROI POSÉ À LA MAIN N'EST PAS RETIRÉ, IL EST COMPTÉ.** Sous drift
     * STRICT, c'est un écart que la comparaison doit voir — pas un objet à détruire
     * en silence, parce que SE5 ne l'a pas posé.
     */
    #[Test]
    public function a_grant_posed_by_hand_is_reported_as_a_deviation_and_never_removed(): void
    {
        $this->backend()->provision($this->plan());

        $space = $this->spaceId();
        $stranger = $this->instance->withUser('technicien');
        $this->instance->withPermission($space, $this->instance->items[$space]['_profs'], 'user', $stranger, self::VIEW_ITEM);

        $report = $this->backend()->provision($this->plan());

        $entry = $report->for('_profs');
        self::assertNotNull($entry);
        self::assertStringContainsString('que le plan ne décrit pas', (string) $entry->detail);

        $grants = $this->instance->grantsOn($space, $this->instance->items[$space]['_profs']);
        self::assertArrayHasKey($stranger, $grants, 'un octroi étranger NE DOIT PAS être retiré');
    }

    /**
     * **LA CLÔTURE INEXPRIMABLE EST CONSTATÉE, JAMAIS PRÉSUMÉE.**
     *
     * Quand le plan octroie sur sa RACINE, l'octroi propage à tout le sous-arbre
     * (mesuré) et rien ne le referme (mesuré trois fois). Le nœud qui referme la
     * classe rend donc `non_exprimable` EN NOMMANT le principal dont l'accès
     * survit — jamais `applique` sur la foi d'une enveloppe favorable.
     */
    #[Test]
    public function a_closure_defeated_by_a_root_grant_is_reported_as_inexpressible_and_names_it(): void
    {
        $report = $this->backend()->provision($this->plan(grantAtRoot: true));

        $entry = $report->for('_profs');
        self::assertNotNull($entry);
        self::assertSame(FileBackendOutcome::NonExprimable, $entry->outcome);
        self::assertStringContainsString('cloisonnement non obtenu', (string) $entry->detail);
        self::assertStringContainsString(self::GROUP_MEMBERS, (string) $entry->detail);
        self::assertStringContainsString('N\'EST PAS cloisonné', (string) $entry->detail);
    }

    /**
     * Le pendant du test précédent, et il est aussi important : **sans octroi à la
     * racine, la clôture EST obtenue**, et le nœud ne rend surtout pas
     * `non_exprimable`. Une garde qui crierait toujours ne dirait plus rien.
     */
    #[Test]
    public function without_a_root_grant_the_closure_holds_and_nothing_is_declined(): void
    {
        $report = $this->backend()->provision($this->plan());

        $entry = $report->for('_profs');
        self::assertNotNull($entry);
        self::assertNotSame(FileBackendOutcome::NonExprimable, $entry->outcome);
        self::assertSame([], $report->inexpressible(), json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * **LA PROPAGATION VIENT DE L'OCTROI SUR UN ITEM, PAS DE LA RACINE — ET À LA
     * PROFONDEUR 2, LA DIFFÉRENCE EST TOUT.**
     *
     * Ici, RIEN n'est octroyé à la racine : une garde qui ne regarderait que
     * celle-ci ne trouverait aucun survivant et rendrait le nœud **conforme**,
     * c'est-à-dire afficherait un cloisonnement qui n'existe pas — le seul
     * résultat que le contrat déclare inacceptable. L'octroi vit sur `_travail`,
     * et le relevé dit qu'il rend `_travail/devoirs` navigable.
     * ═══════════════════════════════════════════════════════════════════════
     */
    #[Test]
    public function a_closure_defeated_by_a_grant_on_a_non_root_ancestor_is_reported_and_names_the_ancestor(): void
    {
        $report = $this->backend()->provision($this->depthTwoPlan());

        // Contrôle du décor : la racine ne porte AUCUN octroi. Sans lui, ce test
        // pourrait passer pour la mauvaise raison.
        self::assertSame([], $this->instance->grantsOn($this->spaceId(), null));

        $entry = $report->for('_travail/devoirs');
        self::assertNotNull($entry);
        self::assertSame(
            FileBackendOutcome::NonExprimable,
            $entry->outcome,
            json_encode($report->toArray(), JSON_UNESCAPED_UNICODE),
        );
        self::assertStringContainsString('cloisonnement non obtenu', (string) $entry->detail);
        self::assertStringContainsString(self::GROUP_MEMBERS, (string) $entry->detail);
        self::assertStringContainsString('_travail', (string) $entry->detail);
    }

    /**
     * **LE PENDANT, ET IL COMPTE AUTANT** : sans octroi sur l'ancêtre, la clôture
     * à la profondeur 2 tient, et rien n'est décliné. Une garde qui crierait
     * toujours ne dirait plus rien.
     */
    #[Test]
    public function without_an_ancestor_grant_a_depth_two_closure_holds(): void
    {
        $report = $this->backend()->provision($this->depthTwoPlan(ancestorGrants: false));

        self::assertSame([], $report->inexpressible(), json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * **`inspect` A LE MÊME ANGLE MORT, ET IL EST PIRE ICI** : la clôture observée
     * est ce que l'écran affiche. Si elle listait la classe comme close alors
     * qu'un octroi d'ancêtre lui donne accès, l'administrateur lirait « cloisonné »
     * sur un dossier ouvert.
     */
    #[Test]
    public function inspect_never_declares_a_subject_closed_when_an_ancestor_grants_to_it(): void
    {
        $this->backend()->provision($this->depthTwoPlan());

        $observed = $this->backend()->inspect($this->depthTwoPlan())->for('_travail/devoirs');

        self::assertNotNull($observed);
        self::assertSame(FileBackendObservation::Observe, $observed->status);
        self::assertSame(
            [],
            $observed->closure,
            'la classe accède par l\'octroi de « _travail » : elle NE DOIT PAS figurer comme close',
        );

        // Et sans cet octroi d'ancêtre, elle y figure bien — sinon la garde
        // ci-dessus serait verte en ne regardant rien.
        $this->backend()->provision($this->depthTwoPlan(ancestorGrants: false));
        $held = $this->backend()->inspect($this->depthTwoPlan(ancestorGrants: false))->for('_travail/devoirs');
        self::assertNotNull($held->closure);
        self::assertCount(1, $held->closure);
    }

    /**
     * **UN OCTROI ÉTRANGER AU PLAN, POSÉ SUR UN ANCÊTRE, DÉFAIT LA CLÔTURE AUSSI —
     * et il est NOMMÉ sans être retiré.**
     *
     * Le chemin est réel : un espace homonyme créé à la main est ADOPTÉ avec ses
     * octrois. Sous drift STRICT on ne les touche pas ; mais ils propagent, et
     * taire leur effet afficherait un cloisonnement qui n'existe pas.
     */
    #[Test]
    public function a_foreign_grant_on_an_ancestor_defeats_the_closure_and_is_named_never_removed(): void
    {
        $adopted = $this->instance->withSpace(self::ROOT);
        $strangers = $this->instance->withGroup('groupe_pose_a_la_main');
        $this->instance->withPermission($adopted, null, 'group', $strangers, self::VIEW_SPACE);

        $report = $this->backend()->provision($this->plan());

        $entry = $report->for('_profs');
        self::assertNotNull($entry);
        self::assertSame(
            FileBackendOutcome::NonExprimable,
            $entry->outcome,
            json_encode($report->toArray(), JSON_UNESCAPED_UNICODE),
        );
        self::assertStringContainsString('cloisonnement non obtenu', (string) $entry->detail);
        self::assertStringContainsString('groupe_pose_a_la_main', (string) $entry->detail);

        // HORS DU PLAN, HORS DU GESTE : il est dit, jamais retiré.
        self::assertArrayHasKey($strangers, $this->instance->grantsOn($adopted, null));
    }

    /**
     * **L'EXCEPTION MESURÉE, ET ELLE EST NÉCESSAIRE** : l'instance donne d'office
     * le rôle d'administration de l'espace au compte qui l'a créé. Le compter
     * comme un survivant ferait rendre `non_exprimable` à 100 % des zones — et une
     * garde qui crie toujours ne dit plus rien.
     */
    #[Test]
    public function the_administration_grant_the_instance_gives_the_creator_never_defeats_a_closure(): void
    {
        $adopted = $this->instance->withSpace(self::ROOT);
        $owner = $this->instance->withUser('admin');
        $this->instance->withPermission($adopted, null, 'user', $owner, OpenCloudRoleTable::MANAGE_ROLE_ID);

        $report = $this->backend()->provision($this->plan());

        self::assertSame([], $report->inexpressible(), json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * **LA TRANSITION QUI RETIRE L'OCTROI DE RACINE : le déclin doit DISPARAÎTRE,
     * et l'accès avec lui.**
     *
     * Un plan corrigé (« on n'octroie plus à la racine ») doit rendre la clôture
     * effective au passage suivant. Un backend qui ne retirerait pas l'octroi
     * résiduel garderait un déclin permanent que plus rien n'expliquerait — et un
     * déclin qu'on ne sait plus faire disparaître finit par être ignoré.
     */
    #[Test]
    public function removing_a_root_grant_makes_the_closure_effective_again(): void
    {
        $this->backend()->provision($this->plan(grantAtRoot: true));

        $space = $this->spaceId();
        $membersGroup = (string) $this->instance->groupIdOf(self::GROUP_MEMBERS);
        self::assertArrayHasKey($membersGroup, $this->instance->grantsOn($space, null));
        self::assertSame(
            FileBackendOutcome::NonExprimable,
            $this->backend()->provision($this->plan(grantAtRoot: true))->for('_profs')?->outcome,
        );

        // Le plan est corrigé : plus rien à la racine.
        $report = $this->backend()->provision($this->plan(grantAtRoot: false));

        self::assertSame([], $this->instance->grantsOn($space, null), 'l\'octroi de racine DOIT être retiré');
        self::assertNotSame(FileBackendOutcome::NonExprimable, $report->for('_profs')?->outcome);
        self::assertSame([], $report->inexpressible(), json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * **UN ÉCHEC SUR UN GROUPE NE FAIT PAS ÉCHOUER LES NŒUDS QUI NE L'ATTENDENT
     * PAS.** L'annuaire illisible, lui, prive de tout et le blanket-fail est juste ;
     * mais projeter un échec ponctuel sur l'arbre entier peindrait en rouge des
     * dossiers parfaitement convergés, et l'exploitant cesserait de lire des rouges
     * qui ne disent rien.
     */
    #[Test]
    public function a_failure_on_one_group_only_fails_the_nodes_that_expect_it(): void
    {
        $membersGroup = $this->instance->withGroup(self::GROUP_MEMBERS);
        $this->instance->breakOn('POST', '/groups/' . $membersGroup . '/members');

        $report = $this->backend()->provision($this->plan());

        // Seuls les nœuds où la CLASSE est attendue échouent.
        self::assertSame(
            ['_echange', '_travail'],
            array_map(static fn ($e): string => $e->path, $report->failures()),
            json_encode($report->toArray(), JSON_UNESCAPED_UNICODE),
        );
        self::assertTrue($report->for('_profs')?->outcome->isConverged());
        self::assertTrue($report->for('alice')?->outcome->isConverged());
    }

    /**
     * **UN OCTROI SUSPENDU EST CONSTATÉ INEXPRIMABLE — et l'accès est bien retiré.**
     *
     * Mesuré : un octroi explicitement vide est REFUSÉ (`roles: []` → `400`), et le
     * minimum acceptable rend le dossier visible chez son destinataire. La
     * suspension se matérialise donc comme une absence : l'EFFET est juste, la
     * DISTINCTION est perdue, et c'est cela qui se dit.
     */
    #[Test]
    public function a_suspended_grant_is_declared_inexpressible_and_its_access_is_actually_removed(): void
    {
        $this->backend()->provision($this->plan());

        $space = $this->spaceId();
        $membersGroup = (string) $this->instance->groupIdOf(self::GROUP_MEMBERS);
        self::assertArrayHasKey(
            $membersGroup,
            $this->instance->grantsOn($space, $this->instance->items[$space]['_echange']),
        );

        $report = $this->backend()->provision($this->plan(echangeSuspended: true));

        $entry = $report->for('_echange');
        self::assertNotNull($entry);
        self::assertSame(FileBackendOutcome::NonExprimable, $entry->outcome);
        self::assertStringContainsString('SUSPENDU', (string) $entry->detail);

        self::assertArrayNotHasKey(
            $membersGroup,
            $this->instance->grantsOn($space, $this->instance->items[$space]['_echange']),
            'l\'accès d\'un octroi suspendu DOIT être retiré, même si la suspension ne se dit pas',
        );
    }

    /**
     * **UNE IDENTITÉ MANQUANTE EST UN ÉCHEC NOMMÉ AVEC SA REMÉDIATION**, compté par
     * nœud, qui ne bloque jamais les autres — et surtout jamais une résolution à la
     * volée (la règle de l'homonyme).
     */
    #[Test]
    public function a_missing_identity_fails_the_node_by_name_and_never_resolves_on_the_fly(): void
    {
        $this->alice->opencloud_user_id = null;
        $this->alice->saveQuietly();

        $report = $this->backend()->provision($this->plan());

        $entry = $report->for('alice');
        self::assertNotNull($entry);
        self::assertSame(FileBackendOutcome::Echec, $entry->outcome);
        self::assertStringContainsString('alice', (string) $entry->detail);
        self::assertStringContainsString('Rattachez son compte', (string) $entry->detail);

        // FAIL-SOFT : les autres nœuds ont convergé (premier passage : `applique`).
        self::assertTrue(
            $report->for('_profs')?->outcome->isConverged() ?? false,
            'un nœud sans rapport avec l\'identité manquante DOIT converger',
        );
        self::assertCount(1, $report->failures(), 'un seul nœud doit échouer');
    }

    /**
     * **FAIL-CLOSED SUR LA CONFIGURATION, AVANT LE PREMIER APPEL.** Capacité
     * éteinte ⇒ refus NOMMÉ sur chaque nœud, et aucune requête émise.
     */
    #[Test]
    public function a_disabled_capability_refuses_by_name_before_any_call(): void
    {
        FilePolicyService::setGlobal(true, true, false, '', null, null, null, false);
        $this->instance->resetWrites();

        $report = $this->backend()->provision($this->plan());

        self::assertCount(5, $report->failures());
        self::assertStringContainsString('Accès OpenCloud', (string) $report->failures()[0]->detail);
        self::assertSame([], $this->instance->writes);
        self::assertSame([], $this->instance->spaces);
    }

    // =========================================================================
    // inspect
    // =========================================================================

    #[Test]
    public function inspect_reprojects_every_node_root_included_and_says_the_closure(): void
    {
        $this->backend()->provision($this->plan());

        $report = $this->backend()->inspect($this->plan());

        self::assertSame($this->plan()->nodePaths(), array_column($report->toArray()['nodes'], 'path'));

        $profs = $report->for('_profs');
        self::assertNotNull($profs);
        self::assertSame(FileBackendObservation::Observe, $profs->status);

        // La clôture est OBSERVÉE — pas `null` : rien ne propage hors d'un octroi
        // de racine, et il n'y en a aucun.
        self::assertNotNull($profs->closure);
        self::assertCount(1, $profs->closure);
        self::assertSame((int) $this->classe->id, $profs->closure[0]->id);

        // Les octrois sont reprojetés en VERBES du plan, jamais en rôle natif.
        $travail = $report->for('_travail');
        self::assertNotNull($travail);
        $verbs = [];
        foreach ($travail->grants as $grant) {
            $verbs[$grant->subject->edgeRole ?? 'user'] = $grant->verbs;
        }
        self::assertSame([PlanGrant::VERB_LIRE], $verbs['member'] ?? null);
        self::assertSame(PlanGrant::VERBS, $verbs['manager'] ?? null);
    }

    /** Une zone absente est un FAIT constaté, jamais une observation vide. */
    #[Test]
    public function a_plan_with_no_space_is_reported_absent_never_conforming(): void
    {
        $report = $this->backend()->inspect($this->plan());

        foreach ($report->observations as $observation) {
            self::assertSame(FileBackendObservation::Absent, $observation->status, $observation->path);
        }
    }

    /**
     * **`absent` EST UN FAIT ; « je n'ai pas pu regarder » EN EST UN AUTRE.**
     *
     * Le contrat a un mot pour chacun, et les confondre est le défaut que l'AC6
     * nomme : un nœud rapporté `absent` invite à le recréer, alors qu'il est
     * peut-être là avec tous ses octrois. Seule la lecture ABOUTIE autorise le mot
     * « absent ».
     */
    #[Test]
    public function a_tree_that_could_not_be_read_is_non_observable_never_absent(): void
    {
        $this->backend()->provision($this->plan());

        $this->instance->breakOn('GET', '/children');

        $report = $this->backend()->inspect($this->plan());

        foreach (['_travail', '_profs', '_echange', 'alice'] as $path) {
            $observation = $report->for($path);
            self::assertNotNull($observation);
            self::assertSame(FileBackendObservation::NonObservable, $observation->status, $path);
            self::assertNotNull($observation->detail);
        }
    }

    /** Une relecture impossible rend un ÉCHEC — jamais une observation vide. */
    #[Test]
    public function an_unreadable_instance_never_reports_an_empty_observation(): void
    {
        $this->instance->authenticated = false;

        $report = $this->backend()->inspect($this->plan());

        foreach ($report->observations as $observation) {
            self::assertSame(FileBackendObservation::Echec, $observation->status, $observation->path);
            self::assertNotNull($observation->detail);
        }
    }

    // =========================================================================
    // quota
    // =========================================================================

    #[Test]
    public function the_root_ceiling_becomes_the_space_quota_and_is_compared_on_the_readback(): void
    {
        $this->backend()->provision($this->plan());

        $plan = $this->cappedPlan(PlanNode::ROOT_PATH, 2147483648);
        $report = $this->backend()->quota($plan);

        self::assertSame([PlanNode::ROOT_PATH], array_column($report->toArray()['nodes'], 'path'));
        self::assertSame(FileBackendOutcome::Applique, $report->entries[0]->outcome);
        self::assertSame(2147483648, $this->instance->spaces[$this->spaceId()]['quota']);

        // Second passage : conforme, et AUCUNE écriture.
        $this->instance->resetWrites();
        $again = $this->backend()->quota($plan);
        self::assertSame(FileBackendOutcome::Conforme, $again->entries[0]->outcome);
        self::assertSame([], $this->instance->writes);
    }

    /**
     * **UN PLAFOND DE SOUS-DOSSIER EST `non_exprimable`, ET C'EST MESURÉ** :
     * `405` en `v1.0`, `400 « id does not belong to a share jail »` en `v1beta1`.
     * C'est une limite du MODÈLE, permanente — pas une dette de notre code.
     */
    #[Test]
    public function a_ceiling_on_a_sub_node_is_permanently_inexpressible(): void
    {
        $this->backend()->provision($this->plan());

        $report = $this->backend()->quota($this->cappedPlan('_travail', 1048576));

        self::assertSame(FileBackendOutcome::NonExprimable, $report->entries[0]->outcome);
        self::assertTrue($report->entries[0]->outcome->isModelLimit());
        self::assertFalse($report->entries[0]->outcome->isImplementationDebt());
    }

    /** Un plan sans plafond rend un rapport VIDE et parfaitement valide. */
    #[Test]
    public function a_plan_without_a_ceiling_yields_an_empty_valid_report(): void
    {
        $report = $this->backend()->quota($this->plan());

        self::assertSame(0, $report->count());
    }

    private function cappedPlan(string $path, int $bytes): FilePlan
    {
        $plan = $this->plan();
        $nodes = [];
        foreach ($plan->nodes as $node) {
            $nodes[] = $node->path === $path
                ? new PlanNode($node->path, $node->label, $node->nature, $node->grants, $node->active, $bytes, $node->closure)
                : $node;
        }

        return new FilePlan($plan->templateKey, $plan->rootPath, $plan->roles, $nodes);
    }

    // =========================================================================
    // deprovision
    // =========================================================================

    /**
     * **RÉVOQUER, C'EST RETIRER LES OCTROIS — JAMAIS DÉTRUIRE.** L'espace reste,
     * son arborescence reste, ses données restent.
     */
    #[Test]
    public function deprovision_revokes_the_grants_and_destroys_nothing(): void
    {
        $this->backend()->provision($this->plan());
        $space = $this->spaceId();
        $treeBefore = $this->instance->items[$space];

        $report = $this->backend()->deprovision($this->plan());

        self::assertSame([], $report->failures(), json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));

        foreach (array_keys($treeBefore) as $path) {
            $grants = $this->instance->grantsOn($space, $treeBefore[$path]);
            self::assertSame([], $grants, $path . ' porte encore un octroi');
        }

        self::assertArrayHasKey($space, $this->instance->spaces, 'l\'espace NE DOIT PAS être détruit');
        self::assertSame($treeBefore, $this->instance->items[$space], 'l\'arborescence NE DOIT PAS être détruite');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * **UNE ARBORESCENCE ILLISIBLE NE VAUT PAS « RIEN À RÉVOQUER ».**
     *
     * Un `5xx` transitoire sur UNE requête suffirait sinon à faire disparaître
     * tout un sous-arbre de l'index, et la révocation conclurait `conforme` —
     * « aucun octroi de ce plan n'était en place » — sur des accès parfaitement
     * intacts. C'est le fail-OPEN que le docblock de `deprovision` interdit
     * nommément, dans le sens qui compte le plus : un accès qu'on croit retiré.
     * ═══════════════════════════════════════════════════════════════════════
     */
    #[Test]
    public function a_revocation_that_cannot_re_read_the_tree_fails_and_never_claims_conformity(): void
    {
        $this->backend()->provision($this->plan());
        $space = $this->spaceId();
        $membersGroup = (string) $this->instance->groupIdOf(self::GROUP_MEMBERS);

        $this->instance->breakOn('GET', '/children');

        $report = $this->backend()->deprovision($this->plan());

        foreach (['_travail', '_profs', '_echange', 'alice'] as $path) {
            $entry = $report->for($path);
            self::assertNotNull($entry);
            self::assertSame(
                FileBackendOutcome::Echec,
                $entry->outcome,
                $path . ' : une lecture en échec NE DOIT PAS valoir « rien à révoquer »',
            );
            self::assertStringContainsString('RIEN n\'a été révoqué', (string) $entry->detail);
        }

        // Et les octrois sont bien TOUJOURS là : le rapport ne ment pas.
        self::assertArrayHasKey(
            $membersGroup,
            $this->instance->grantsOn($space, $this->instance->items[$space]['_travail']),
        );
    }

    /** Révoquer un plan qui n'a jamais été provisionné est CONFORME, pas un échec. */
    #[Test]
    public function deprovisioning_an_unknown_plan_is_conforming(): void
    {
        $report = $this->backend()->deprovision($this->plan());

        foreach ($report->entries as $entry) {
            self::assertSame(FileBackendOutcome::Conforme, $entry->outcome);
        }
    }

    // =========================================================================
    // location
    // =========================================================================

    #[Test]
    public function location_is_a_display_string_and_never_leaks_the_secret(): void
    {
        $location = $this->backend()->location($this->plan());

        self::assertNotNull($location);
        self::assertStringContainsString(self::URL, $location);
        self::assertStringContainsString(self::ROOT, $location);
        self::assertStringNotContainsString('secret', $location);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
