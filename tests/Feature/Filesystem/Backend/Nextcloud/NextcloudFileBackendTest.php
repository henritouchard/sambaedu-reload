<?php

declare(strict_types=1);

namespace Tests\Feature\Filesystem\Backend\Nextcloud;

use App\Enums\FileBackendName;
use App\Enums\FileBackendObservation;
use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\FilePolicyService;
use App\Services\Filesystem\Backend\FileBackendRegistry;
use App\Services\Filesystem\Backend\Nextcloud\NextcloudFileBackend;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Filesystem\PlanStateComparator;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.3 — LE BACKEND, DE BOUT EN BOUT, CONTRE UNE INSTANCE EN MÉMOIRE.
 *
 * Le décor est celui du partage de classe : une racine, un espace de travail, un
 * espace des enseignants (où la classe n'a RIEN — c'est la clôture), un espace
 * d'échange suspendable, et un dossier personnel par membre.
 *
 * C'est le décor qui a fait naître la clôture calculée : sans elle, l'espace des
 * enseignants serait lisible par toute la classe, l'instruction de retrait serait
 * acceptée sans effet, et l'écran serait vert.
 */
class NextcloudFileBackendTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://nuage.exemple.fr';

    private const ROOT = 'Classe_3A';

    /** Les noms de groupes COMPILÉS : recalculés, jamais découpés d'un nom observé. */
    private const GROUP_MEMBERS = 'se5_3a_member';

    private const GROUP_MANAGERS = 'se5_3a_manager';

    private const ADMIN_GROUP = 'se5_administration';

    private UserGroup $classe;

    private User $alice;

    private User $bruno;

    private User $prof;

    private FakeNextcloudInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        // La projection d'annuaire n'a rien à voir avec ce backend : ce sont des
        // groupes NEXTCLOUD que la story compile, pas des groupes d'annuaire.
        UserGroupObserver::disableSync();
        \Illuminate\Support\Facades\Queue::fake();

        FilePolicyService::setGlobal(true, true, true, self::URL, 'admin', 'se4fs', true);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'secret');

        $this->classe = UserGroup::query()->create(['name' => '3A', 'type' => 'classe']);

        $this->alice = $this->user('alice', 'alice');
        $this->bruno = $this->user('bruno', 'bruno');
        $this->prof = $this->user('prof1', 'prof1');

        $this->classe->users()->attach($this->alice->id, ['role' => 'member']);
        $this->classe->users()->attach($this->bruno->id, ['role' => 'member']);
        $this->classe->users()->attach($this->prof->id, ['role' => 'manager']);

        $this->instance = (new FakeNextcloudInstance())->withGroup('does-not-matter');
        $this->instance->groups = [];
        $this->instance->install();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function user(string $login, ?string $nextcloudId): User
    {
        $user = User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true, 'source' => 'ad']);
        $user->nextcloud_user_id = $nextcloudId;
        $user->saveQuietly();

        return $user->fresh();
    }

    private function backend(): NextcloudFileBackend
    {
        return app(FileBackendRegistry::class)->get(FileBackendName::Nextcloud);
    }

    // =========================================================================
    // Le plan d'épreuve
    // =========================================================================

    private function plan(bool $echangeSuspended = false): FilePlan
    {
        $members = PlanSubject::group((int) $this->classe->id, 'member');
        $managers = PlanSubject::group((int) $this->classe->id, 'manager');
        $aliceSubject = PlanSubject::user((int) $this->alice->id);

        $roles = ['classe' => [$members], 'equipe' => [$managers]];

        $nodes = [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::Partagee, [
                new PlanGrant('equipe', $managers, PlanGrant::VERBS),
                new PlanGrant('classe', $members, [PlanGrant::VERB_LIRE]),
            ], true, null, []),

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

    // =========================================================================
    // AC2 — provision
    // =========================================================================

    /** Le plan devient un dossier d'équipe, ses groupes, son arborescence et ses règles. */
    #[Test]
    public function a_plan_becomes_a_team_folder_with_its_groups_tree_and_rules(): void
    {
        $report = $this->backend()->provision($this->plan());

        self::assertSame($this->plan()->nodePaths(), array_column($report->toArray()['nodes'], 'path'));
        self::assertSame([], $report->failures(), json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));

        // Le dossier d'équipe existe, au point de montage du plan.
        self::assertCount(1, $this->instance->folders);
        $folder = array_values($this->instance->folders)[0];
        self::assertSame(self::ROOT, $folder['mount_point']);

        // Les permissions avancées sont ACTIVÉES : sans elles, les règles n'ont
        // AUCUN effet et le cloisonnement serait affiché sans exister.
        self::assertTrue($folder['acl']);

        // Les groupes compilés existent, et leur appartenance reflète le plan.
        self::assertSame(['alice', 'bruno'], $this->instance->groups[self::GROUP_MEMBERS]);
        self::assertSame(['prof1'], $this->instance->groups[self::GROUP_MANAGERS]);

        // L'arborescence est là, un niveau à la fois.
        foreach (['_travail', '_profs', '_echange', 'alice'] as $node) {
            self::assertArrayHasKey(self::ROOT . '/' . $node, $this->instance->collections, $node);
        }

        // La clôture est POSÉE sur l'espace des enseignants : la classe y est
        // refermée, mask complet, permissions nulles.
        $rules = $this->instance->acl[self::ROOT . '/_profs'] ?? [];
        $closure = array_values(array_filter($rules, static fn (array $r): bool => $r['id'] === self::GROUP_MEMBERS));
        self::assertCount(1, $closure, 'la classe DOIT être refermée sur l\'espace des enseignants');
        self::assertSame(0, $closure[0]['permissions']);
        self::assertSame(31, $closure[0]['mask']);
    }

    /**
     * L'ÉLÈVE ENTRE CHEZ LUI : sa règle nominative est POSÉE, pas déduite.
     *
     * L'instance résout les permissions d'un compte en réunissant les règles qui le
     * visent lui ou l'un de ses groupes ; le plafond du montage n'est atteint que si
     * aucune ne le vise. Sans règle à son nom, l'élève hérite donc du zéro que la
     * clôture pose sur sa classe — sur son PROPRE dossier, qu'il ne voit plus.
     */
    #[Test]
    public function the_personal_folder_carries_its_owner_rule_and_not_only_the_class_closure(): void
    {
        $this->backend()->provision($this->plan());

        $rules = $this->instance->acl[self::ROOT . '/alice'] ?? [];

        $own = array_values(array_filter(
            $rules,
            static fn (array $r): bool => $r['type'] === 'user' && $r['id'] === 'alice',
        ));
        self::assertCount(1, $own, 'l\'élève DOIT porter sa propre règle sur son dossier');
        self::assertSame(15, $own[0]['permissions']);
        self::assertSame(31, $own[0]['mask']);

        // Et la clôture de la classe reste posée à côté : c'est leur COEXISTENCE
        // qui referme le dossier pour les camarades sans en fermer le titulaire.
        $closure = array_values(array_filter(
            $rules,
            static fn (array $r): bool => $r['id'] === self::GROUP_MEMBERS,
        ));
        self::assertCount(1, $closure);
        self::assertSame(0, $closure[0]['permissions']);
    }

    /**
     * La RELECTURE ne prend pas le plafond pour un octroi : un compte sans règle
     * propre est constaté à ce que ses groupes lui laissent, clôture comprise.
     */
    #[Test]
    public function reading_back_never_credits_a_person_with_the_mount_ceiling(): void
    {
        $this->backend()->provision($this->plan());

        // On retire la règle nominative SANS toucher au reste : l'état devient
        // celui qu'une instance mal réconciliée présente.
        $path = self::ROOT . '/alice';
        $this->instance->acl[$path] = array_values(array_filter(
            $this->instance->acl[$path] ?? [],
            static fn (array $r): bool => ! ($r['type'] === 'user' && $r['id'] === 'alice'),
        ));

        $node = $this->backend()->inspect($this->plan())->for('alice');

        $observed = [];
        foreach ($node?->grants ?? [] as $grant) {
            $observed[$grant->subject->sortKey()] = $grant->verbs;
        }

        self::assertSame(
            [],
            $observed[PlanSubject::user((int) $this->alice->id)->sortKey()] ?? null,
            'un élève que la clôture de sa classe met à zéro ne doit JAMAIS être relu comme servi',
        );
    }

    /**
     * **`rw` VAUT 15, JAMAIS 31** — le bit de re-partage n'est jamais accordé.
     *
     * Le plan n'a aucun verbe pour le re-partage : l'accorder donnerait un droit que
     * personne n'a écrit, et un partage créé par un utilisateur serait un état que le
     * plan ne sait pas décrire. On n'accorde pas un droit dont le modèle ne peut pas
     * rendre compte.
     */
    #[Test]
    public function the_reshare_bit_is_never_granted(): void
    {
        $this->backend()->provision($this->plan());

        $folder = array_values($this->instance->folders)[0];

        self::assertSame(15, $folder['groups'][self::GROUP_MANAGERS], 'les quatre verbes valent 15, pas 31');

        foreach ($this->instance->acl as $rules) {
            foreach ($rules as $rule) {
                self::assertSame(0, $rule['permissions'] & 16, 'aucune règle n\'accorde le re-partage');
            }
        }
    }

    /** **IDEMPOTENCE : un second passage rend conforme et n'émet AUCUNE écriture.** */
    #[Test]
    public function a_second_pass_on_a_conforming_state_writes_nothing(): void
    {
        $this->backend()->provision($this->plan());

        $this->instance->reset();
        $report = $this->backend()->provision($this->plan());

        self::assertSame(
            [],
            $this->instance->writes(),
            'la comparaison se fait sur les valeurs RELUES, en ignorant les champs que le serveur ajoute : '
            . 'un second passage ne doit rien réécrire',
        );

        foreach ($report->toArray()['nodes'] as $node) {
            self::assertSame(FileBackendOutcome::Conforme->value, $node['outcome'], $node['path']);
        }
    }

    /**
     * **UN DOSSIER HOMONYME EST LE MÊME OBJET : on l'ADOPTE, on ne le double pas.**
     * La reconnaissance porte sur le point de montage RELU — que l'instance rend avec
     * une barre oblique de tête que personne n'a écrite.
     */
    #[Test]
    public function an_existing_folder_at_the_same_mount_point_is_adopted_never_duplicated(): void
    {
        $this->instance->withFolder(self::ROOT);

        $this->backend()->provision($this->plan());

        self::assertCount(1, $this->instance->folders, 'aucun second dossier ne doit naître');
    }

    /** Un dossier ÉTRANGER n'est ni supprimé, ni modifié, ni touché : hors du plan, hors du geste. */
    #[Test]
    public function a_foreign_team_folder_is_never_touched(): void
    {
        $this->instance->withFolder('Cree_A_La_Main', true, ['un_groupe_a_nous' => 31]);

        $this->backend()->provision($this->plan());

        self::assertSame(
            ['id' => 1, 'mount_point' => 'Cree_A_La_Main', 'quota' => -3, 'acl' => true, 'groups' => ['un_groupe_a_nous' => 31]],
            $this->instance->folders[1],
        );
    }

    /**
     * CORRECTION DE REVUE 61.3 #4 — **LA RÉCONCILIATION NE RETIRE JAMAIS LE COMPTE
     * D'ADMINISTRATION D'UN GROUPE DU PLAN.**
     *
     * Le commentaire de `convergeGroups()` promettait cette protection ; la boucle
     * appelait le retrait INCONDITIONNELLEMENT. Le compte d'administration n'est pas
     * membre des groupes du plan — s'il s'y trouve, c'est un geste de l'exploitant,
     * pas une dérive — et l'en retirer lui ferait perdre l'accès qu'il s'est donné.
     *
     * La classe de défaut est la pire de toutes : le retrait RÉUSSIT, le passage est
     * vert, et la panne n'apparaît qu'au passage SUIVANT — sous la forme d'un dossier
     * d'équipe soudain inatteignable par le compte qui écrit.
     *
     * Ce que le test prouve DES DEUX CÔTÉS : le compte d'administration reste, et
     * l'appartenance réellement périmée est bien retirée (une garde qui protégerait
     * tout le monde n'en serait plus une).
     */
    #[Test]
    public function the_administration_account_is_never_removed_from_a_plan_group(): void
    {
        $this->instance->withGroup(self::GROUP_MEMBERS, ['admin', 'alice', 'bruno', 'parti_l_an_dernier']);

        $this->backend()->provision($this->plan());

        self::assertContains(
            'admin',
            $this->instance->groups[self::GROUP_MEMBERS],
            'le compte qui POSE les règles ne peut pas se voir retirer son accès par la réconciliation',
        );
        self::assertNotContains(
            'parti_l_an_dernier',
            $this->instance->groups[self::GROUP_MEMBERS],
            'la garde protège le compte d\'administration, PAS toutes les appartenances périmées',
        );
    }

    /**
     * La garde compare SANS TENIR COMPTE DE LA CASSE, et c'est délibéré : les deux
     * erreurs n'ont pas le même prix. Sauter un retrait laisse une appartenance
     * périmée, qui se voit ; retirer à tort retire l'accès du compte qui écrit, ce
     * qui ne se voit qu'au passage suivant.
     */
    #[Test]
    public function the_administration_account_guard_ignores_case(): void
    {
        $this->instance->withGroup(self::GROUP_MEMBERS, ['Admin', 'alice', 'bruno']);

        $this->backend()->provision($this->plan());

        self::assertContains('Admin', $this->instance->groups[self::GROUP_MEMBERS]);
    }

    /**
     * **UN OCTROI SUSPENDU EST UN OCTROI EXPLICITEMENT VIDE, jamais une absence.**
     * C'est ce qui empêche une désactivation d'être relue comme une suppression.
     */
    #[Test]
    public function a_suspended_grant_materialises_as_an_explicitly_empty_rule(): void
    {
        $this->backend()->provision($this->plan(echangeSuspended: true));

        $rules = $this->instance->acl[self::ROOT . '/_echange'] ?? [];
        $suspended = array_values(array_filter($rules, static fn (array $r): bool => $r['id'] === self::GROUP_MEMBERS));

        self::assertCount(1, $suspended, 'la suspension se MATÉRIALISE : une règle présente, à zéro');
        self::assertSame(0, $suspended[0]['permissions']);
    }

    /**
     * **UNE IDENTITÉ MANQUANTE EST UN ÉCHEC NOMMÉ AVEC SA REMÉDIATION**, jamais une
     * résolution à la volée : la règle de l'homonyme (revue 61.1) interdit de deviner
     * qu'un compte « doit bien être » le login.
     */
    #[Test]
    public function a_missing_identity_fails_the_node_and_names_its_remediation(): void
    {
        $this->alice->nextcloud_user_id = null;
        $this->alice->saveQuietly();

        $report = $this->backend()->provision($this->plan());

        $node = $report->for('alice');

        self::assertSame(FileBackendOutcome::Echec, $node?->outcome);
        self::assertStringContainsString('alice', (string) $node?->detail);
        self::assertStringContainsString('nextcloud:identity', (string) $node?->detail);

        // FAIL-SOFT : les autres nœuds ne sont pas bloqués par celui-là.
        self::assertTrue($report->for('_travail')?->outcome->isConverged());
    }

    /**
     * **FAIL-CLOSED SUR LA CONFIGURATION : aucun appel n'est émis.** Une capacité
     * éteinte n'est pas une panne réseau, et partir écrire au hasard serait pire que
     * de refuser en nommant ce qui manque.
     */
    #[Test]
    public function a_disabled_capability_refuses_before_the_first_call(): void
    {
        FilePolicyService::setGlobal(true, true, false, self::URL, 'admin', 'se4fs', true);

        $report = $this->backend()->provision($this->plan());

        self::assertSame([], $this->instance->calls, 'fail-closed : rien ne part sur le réseau');
        self::assertCount(5, $report->failures());
    }

    // =========================================================================
    // AC3 — la clôture EFFECTIVE, et son constat
    // =========================================================================

    /**
     * **« NON EXPRIMABLE » SE CONSTATE, IL NE SE DÉCIDE PAS.**
     *
     * On pose, PUIS ON RELIT. Ici l'instance accepte la règle de clôture en succès
     * et n'en fait rien — c'est le mode de rupture MESURÉ au sondage d'ouverture
     * d'epic, celui qui a fait naître la clôture calculée. Le nœud doit DIRE que le
     * cloisonnement n'a pas été obtenu, en nommant le principal dont l'accès survit :
     * jamais « appliqué » sur la foi d'une enveloppe verte.
     */
    #[Test]
    public function a_closure_accepted_without_effect_is_reported_as_inexpressible(): void
    {
        $this->instance->swallowRulesFor[self::ROOT . '/_profs'] = [self::GROUP_MEMBERS];

        $report = $this->backend()->provision($this->plan());

        $node = $report->for('_profs');

        self::assertSame(
            FileBackendOutcome::NonExprimable,
            $node?->outcome,
            'un retrait accepté SANS EFFET ne doit jamais se rapporter « appliqué »',
        );
        self::assertTrue($node?->outcome->isModelLimit());
        self::assertStringContainsString(self::GROUP_MEMBERS, (string) $node?->detail);
        self::assertStringContainsString('N\'EST PAS cloisonné', (string) $node?->detail);

        // Les autres nœuds ne sont pas contaminés : fail-soft par nœud.
        self::assertSame(FileBackendOutcome::Applique->value, $report->for('_travail')?->outcome->value);
    }

    // =========================================================================
    // AC4 — inspect et la reprojection
    // =========================================================================

    /** La relecture reprojette en vocabulaire de plan : aucun identifiant distant ne remonte. */
    #[Test]
    public function inspect_reprojects_the_state_into_plan_vocabulary(): void
    {
        $plan = $this->plan();
        $this->backend()->provision($plan);

        $inspection = $this->backend()->inspect($plan);

        self::assertSame($plan->nodePaths(), array_column($inspection->toArray()['nodes'], 'path'));

        $travail = $inspection->for('_travail');
        self::assertSame(FileBackendObservation::Observe, $travail?->status);

        $observed = [];
        foreach ((array) $travail?->grants as $grant) {
            $observed[$grant->subject->sortKey()] = $grant->verbs;
        }

        $members = PlanSubject::group((int) $this->classe->id, 'member');
        $managers = PlanSubject::group((int) $this->classe->id, 'manager');

        self::assertSame([PlanGrant::VERB_LIRE], $observed[$members->sortKey()] ?? null);
        self::assertSame(PlanGrant::VERBS, $observed[$managers->sortKey()] ?? null);

        // Aucun identifiant distant ne traverse la ligne.
        self::assertStringNotContainsString('se5_', json_encode($inspection->toArray(), JSON_UNESCAPED_UNICODE));
    }

    /** Un plan provisionné puis relu se compare CONFORME — clôtures comprises. */
    #[Test]
    public function a_provisioned_plan_compares_as_conforming(): void
    {
        $plan = $this->plan();
        $this->backend()->provision($plan);

        $comparison = app(PlanStateComparator::class)->compare($plan, $this->backend()->inspect($plan));

        self::assertSame(PlanStateComparator::STATUS_CONFORME, $comparison['status'], json_encode($comparison, JSON_UNESCAPED_UNICODE));
    }

    /**
     * **LA FUITE QUE LE DRIFT STRICT EXISTE POUR MONTRER** : une règle de masque
     * retirée à la main sur l'espace des enseignants. L'octroi de l'équipe, lui, reste
     * parfaitement conforme — sans la comparaison de clôture, l'écran serait tout
     * vert pendant que la classe lit le dossier privé des enseignants.
     */
    #[Test]
    public function a_hand_removed_closure_rule_is_a_drift(): void
    {
        $plan = $this->plan();
        $this->backend()->provision($plan);

        $this->instance->acl[self::ROOT . '/_profs'] = array_values(array_filter(
            $this->instance->acl[self::ROOT . '/_profs'] ?? [],
            static fn (array $r): bool => $r['id'] !== self::GROUP_MEMBERS,
        ));

        $comparison = app(PlanStateComparator::class)->compare($plan, $this->backend()->inspect($plan));

        self::assertSame(PlanStateComparator::STATUS_DRIFTED, $comparison['status']);

        $profs = null;
        foreach ($comparison['nodes'] as $node) {
            if ($node['path'] === '_profs') {
                $profs = $node;
            }
        }

        self::assertSame(PlanStateComparator::NODE_ECART, $profs['status']);
        self::assertNotSame([], $profs['closure'] ?? [], 'la divergence de CLÔTURE doit être nommée');
        self::assertTrue($profs['closure'][0]['expected_closed']);
        self::assertFalse($profs['closure'][0]['observed_closed']);
    }

    /** Un dossier absent est un FAIT constaté, pas une ignorance. */
    #[Test]
    public function a_plan_without_a_folder_reads_as_absent_everywhere(): void
    {
        $inspection = $this->backend()->inspect($this->plan());

        foreach ($inspection->toArray()['nodes'] as $node) {
            self::assertSame(FileBackendObservation::Absent->value, $node['status'], $node['path']);
        }
    }

    /**
     * **« CONFORME » ET « NON MESURABLE » NE SE CONFONDENT JAMAIS** : une instance
     * injoignable rend un échec de relecture, jamais une observation vide.
     */
    #[Test]
    public function an_unreachable_instance_never_reads_as_an_empty_observation(): void
    {
        \Illuminate\Support\Facades\Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7'));

        $inspection = $this->backend()->inspect($this->plan());

        foreach ($inspection->toArray()['nodes'] as $node) {
            self::assertSame(FileBackendObservation::Echec->value, $node['status'], $node['path']);
            self::assertNotNull($node['detail']);
        }
    }

    /**
     * **UN PRINCIPAL IRRÉSOLVABLE N'EST JAMAIS SILENCIEUSEMENT OMIS.** Une règle
     * ajoutée à la main sur un nœud DU PLAN est un écart sous drift STRICT : la
     * comparaison doit la voir, pas la reprojection l'avaler.
     */
    #[Test]
    public function a_foreign_principal_on_a_plan_node_is_counted_never_dropped(): void
    {
        $plan = $this->plan();
        $this->backend()->provision($plan);

        $this->instance->acl[self::ROOT . '/_travail'][] = [
            'type' => 'group', 'id' => 'un_groupe_pose_a_la_main', 'mask' => 31, 'permissions' => 15,
        ];

        $observation = $this->backend()->inspect($plan)->for('_travail');

        self::assertNotNull($observation?->detail, 'le principal étranger doit REMONTER');
        self::assertStringContainsString('aucune identité connue', (string) $observation?->detail);
        self::assertStringNotContainsString('un_groupe_pose_a_la_main', (string) $observation?->detail);

        $comparison = app(PlanStateComparator::class)->compare($plan, $this->backend()->inspect($plan));
        self::assertSame(PlanStateComparator::STATUS_DRIFTED, $comparison['status']);
    }

    /**
     * Un membre SANS compte est un CONSTAT, jamais un échec de nœud : l'octroi du
     * groupe est bien écrit, c'est la personne qui n'a pas encore de compte. Le taire
     * ferait croire à un octroi qui atteint tout le monde ; le rendre bloquant
     * peindrait en rouge une zone parfaitement provisionnée.
     */
    #[Test]
    public function a_member_without_an_account_is_a_notice_not_a_node_failure(): void
    {
        $this->bruno->nextcloud_user_id = null;
        $this->bruno->saveQuietly();

        $report = $this->backend()->provision($this->plan());

        self::assertSame([], $report->failures());

        $root = $report->for(PlanNode::ROOT_PATH);
        self::assertStringContainsString('nextcloud:provision', (string) $root?->detail);
        self::assertTrue($root?->outcome->isConverged());

        // Le groupe compilé ne contient QUE les comptes connus : jamais un
        // identifiant deviné.
        self::assertSame(['alice'], $this->instance->groups[self::GROUP_MEMBERS]);
    }

    /**
     * **UN NŒUD À CONTENU LIBRE N'INSPECTE PAS SES ENFANTS.** Le dossier qu'un
     * enseignant crée dans l'espace de dépôt n'est pas un écart : le plan ne le
     * gouverne pas, donc il n'a rien à en dire.
     */
    #[Test]
    public function a_free_content_node_never_reports_its_children(): void
    {
        $plan = $this->plan();
        $withDrop = new FilePlan($plan->templateKey, $plan->rootPath, $plan->roles, [
            ...$plan->nodes,
            new PlanNode('_travail/devoirs', 'Dépôt', PlanNodeNature::ContenuLibre, [
                new PlanGrant('equipe', PlanSubject::group((int) $this->classe->id, 'manager'), PlanGrant::VERBS),
                new PlanGrant('classe', PlanSubject::group((int) $this->classe->id, 'member'), [PlanGrant::VERB_LIRE]),
            ], true, null, []),
        ]);

        $this->backend()->provision($withDrop);

        // L'enseignant crée un dossier dedans, et personne ne le lui a demandé.
        $this->instance->collections[self::ROOT . '/_travail/devoirs/rendu-bruno'] = true;

        $inspection = $this->backend()->inspect($withDrop);

        self::assertSame($withDrop->nodePaths(), array_column($inspection->toArray()['nodes'], 'path'));

        $comparison = app(PlanStateComparator::class)->compare($withDrop, $inspection);
        self::assertSame(PlanStateComparator::STATUS_CONFORME, $comparison['status'], json_encode($comparison, JSON_UNESCAPED_UNICODE));
    }

    // =========================================================================
    // AC5 — les deux quotas
    // =========================================================================

    /** Le plafond de la RACINE se projette sur le plafond du dossier, comparé au RELU. */
    #[Test]
    public function the_root_cap_becomes_the_team_folder_quota(): void
    {
        $plan = $this->plan();
        $withCap = new FilePlan($plan->templateKey, $plan->rootPath, $plan->roles, array_map(
            static fn (PlanNode $n): PlanNode => $n->path === PlanNode::ROOT_PATH
                ? new PlanNode($n->path, $n->label, $n->nature, $n->grants, $n->active, 5368709120, $n->closure)
                : $n,
            $plan->nodes,
        ));

        $this->backend()->provision($withCap);
        $report = $this->backend()->quota($withCap);

        self::assertSame([PlanNode::ROOT_PATH], array_column($report->toArray()['nodes'], 'path'));
        self::assertSame(FileBackendOutcome::Applique, $report->for(PlanNode::ROOT_PATH)?->outcome);
        self::assertSame(5368709120, array_values($this->instance->folders)[0]['quota']);
    }

    /**
     * Un plafond porté par un SOUS-nœud est `non_exprimable` : le plafond d'un
     * dossier d'équipe porte sur le dossier ENTIER. C'est une limite du MODÈLE,
     * permanente — l'affichage la MASQUE, il ne la grise pas.
     */
    #[Test]
    public function a_sub_node_cap_is_a_permanent_model_limit(): void
    {
        $plan = $this->plan();
        $withCap = new FilePlan($plan->templateKey, $plan->rootPath, $plan->roles, array_map(
            static fn (PlanNode $n): PlanNode => $n->path === 'alice'
                ? new PlanNode($n->path, $n->label, $n->nature, $n->grants, $n->active, 2147483648, $n->closure)
                : $n,
            $plan->nodes,
        ));

        $entry = $this->backend()->quota($withCap)->for('alice');

        self::assertSame(FileBackendOutcome::NonExprimable, $entry?->outcome);
        self::assertTrue($entry?->outcome->isModelLimit());
        self::assertStringContainsString('dossier ENTIER', (string) $entry?->detail);
    }

    /** Un plan sans plafond rend un rapport VIDE et parfaitement valide. */
    #[Test]
    public function a_plan_without_a_cap_yields_an_empty_valid_report(): void
    {
        self::assertSame(0, $this->backend()->quota($this->plan())->count());
        self::assertSame([], $this->instance->calls, 'rien à plafonner : aucun appel');
    }

    // =========================================================================
    // AC7 — deprovision
    // =========================================================================

    /** **RÉVOQUER N'EST PAS DÉTRUIRE** : le dossier et son contenu survivent (D9). */
    #[Test]
    public function deprovision_revokes_without_destroying(): void
    {
        $plan = $this->plan();
        $this->backend()->provision($plan);

        $report = $this->backend()->deprovision($plan);

        self::assertSame([], $report->failures());
        self::assertCount(1, $this->instance->folders, 'le dossier d\'équipe SURVIT');
        self::assertArrayHasKey(self::ROOT . '/_profs', $this->instance->collections, 'les données SURVIVENT');

        $folder = array_values($this->instance->folders)[0];
        self::assertSame([self::ADMIN_GROUP], array_keys($folder['groups']), 'plus personne ne monte le dossier');

        foreach ($plan->nodePaths() as $path) {
            $remote = $path === PlanNode::ROOT_PATH ? self::ROOT : self::ROOT . '/' . $path;
            self::assertSame([], $this->instance->acl[$remote] ?? [], 'les règles sont retirées : ' . $path);
        }

        self::assertSame(
            [],
            array_values(array_filter(
                $this->instance->calls,
                static fn (array $c): bool => $c['method'] === 'DELETE' && preg_match('#/folders/\d+$#', (string) parse_url($c['url'], PHP_URL_PATH)) === 1,
            )),
            'AUCUN chemin de production ne supprime un dossier d\'équipe',
        );
    }

    /** Un plan qui n'a jamais été posé se révoque en `conforme` : rien à faire. */
    #[Test]
    public function deprovisioning_an_unknown_plan_is_conforming(): void
    {
        $report = $this->backend()->deprovision($this->plan());

        foreach ($report->toArray()['nodes'] as $node) {
            self::assertSame(FileBackendOutcome::Conforme->value, $node['outcome']);
        }
    }

    // =========================================================================
    // location
    // =========================================================================

    #[Test]
    public function the_display_location_names_the_instance_and_the_folder(): void
    {
        $location = $this->backend()->location($this->plan());

        self::assertStringContainsString(self::URL, (string) $location);
        self::assertStringContainsString(self::ROOT, (string) $location);
    }
}
