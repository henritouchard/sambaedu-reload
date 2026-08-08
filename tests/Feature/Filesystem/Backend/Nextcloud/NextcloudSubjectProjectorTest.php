<?php

declare(strict_types=1);

namespace Tests\Feature\Filesystem\Backend\Nextcloud;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\Filesystem\Backend\Nextcloud\NextcloudSubjectProjector;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.3 — LA PROJECTION D'IDENTITÉ : le seul point de rencontre des deux
 * vocabulaires, éprouvé dans LES DEUX SENS.
 */
class NextcloudSubjectProjectorTest extends TestCase
{
    use RefreshDatabase;

    private UserGroup $classe;

    protected function setUp(): void
    {
        parent::setUp();
        UserGroupObserver::disableSync();
        \Illuminate\Support\Facades\Queue::fake();

        $this->classe = UserGroup::query()->create(['name' => '3A', 'type' => 'classe']);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function projector(): NextcloudSubjectProjector
    {
        return app(NextcloudSubjectProjector::class);
    }

    private function user(string $login, ?string $ncId, string $role): User
    {
        $user = User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true, 'source' => 'ad']);
        $user->nextcloud_user_id = $ncId;
        $user->saveQuietly();
        $this->classe->users()->attach($user->id, ['role' => $role]);

        return $user->fresh();
    }

    /** Le nom d'un groupe compilé est DÉTERMINISTE, et il porte son rôle d'arête. */
    #[Test]
    public function a_compiled_group_name_is_deterministic_and_carries_its_edge_role(): void
    {
        $projector = $this->projector();

        self::assertSame('se5_3a', $projector->groupNameFor(PlanSubject::group((int) $this->classe->id)));
        self::assertSame('se5_3a_member', $projector->groupNameFor(PlanSubject::group((int) $this->classe->id, 'member')));
        self::assertSame('se5_3a_manager', $projector->groupNameFor(PlanSubject::group((int) $this->classe->id, 'manager')));
        self::assertSame('se5_3a_owner', $projector->groupNameFor(PlanSubject::group((int) $this->classe->id, 'owner')));
    }

    /**
     * Le nom porte le SUFFIXE D'ÉTABLISSEMENT, parce qu'il est dérivé par la MÊME
     * autorité que le reste du dépôt. En écrire une seconde ferait diverger deux
     * normalisations du même objet — et la divergence ne se verrait qu'au moment où
     * elle coûte le plus cher (deux collèges, deux « 3A », un seul groupe).
     */
    #[Test]
    public function a_federated_group_name_carries_its_establishment_suffix(): void
    {
        $group = UserGroup::query()->create(['name' => '3B', 'type' => 'classe']);
        $group->ad_dn = 'CN=Classe_3B,OU=Groupes,OU=0991229y,DC=exemple,DC=fr';
        $group->saveQuietly();

        self::assertSame('se5_3b-1229y_member', $this->projector()->groupNameFor(PlanSubject::group((int) $group->id, 'member')));
    }

    /**
     * **L'APPARTENANCE EST EXACTE, PAS UN SURENSEMBLE.** Le backend du serveur de
     * fichiers historique recopie un trio de groupes d'annuaire qu'il n'a pas
     * fabriqué, et assume donc des surensembles. Ici, le groupe est FABRIQUÉ : le
     * rôle d'arête se filtre exactement, et le faire est gratuit.
     */
    #[Test]
    public function membership_is_filtered_exactly_by_the_edge_role(): void
    {
        $this->user('alice', 'alice', 'member');
        $this->user('prof1', 'prof1', 'manager');

        $members = $this->projector()->membersFor(PlanSubject::group((int) $this->classe->id, 'member'));
        $managers = $this->projector()->membersFor(PlanSubject::group((int) $this->classe->id, 'manager'));
        $all = $this->projector()->membersFor(PlanSubject::group((int) $this->classe->id));

        self::assertSame(['alice'], $members['members']);
        self::assertSame(['prof1'], $managers['members']);
        self::assertSame(['alice', 'prof1'], $all['members']);
    }

    /**
     * **LE CACHE EST LA SEULE CLÉ DE JOINTURE.** Un compte sans identité connue n'est
     * jamais deviné (« ce doit être le login ») : il est compté à part, avec sa
     * remédiation. C'est la règle de l'homonyme héritée de la revue 61.1 — un
     * rattachement non vérifié rouvre l'écrasement du mot de passe d'un tiers.
     */
    #[Test]
    public function an_account_without_a_cached_identity_is_never_guessed(): void
    {
        $this->user('alice', 'alice', 'member');
        $bruno = $this->user('bruno', null, 'member');

        $membership = $this->projector()->membersFor(PlanSubject::group((int) $this->classe->id, 'member'));

        self::assertSame(['alice'], $membership['members']);
        self::assertSame(['bruno'], $membership['missing']);

        self::assertNull($this->projector()->nextcloudUserIdFor(PlanSubject::user((int) $bruno->id)));
        self::assertStringContainsString(
            'nextcloud:identity bruno',
            $this->projector()->identityRemediation('bruno'),
        );
    }

    /**
     * **L'INDEX INVERSE SE RECALCULE, IL NE SE DÉCOUPE PAS.** Un découpage de nom se
     * casse sur le premier nom court qui contient un souligné — et ils en contiennent
     * tous, à commencer par le suffixe d'établissement.
     */
    #[Test]
    public function the_reverse_index_is_recomputed_from_the_plan_never_parsed(): void
    {
        $alice = $this->user('alice', 'alice-nc', 'member');
        $members = PlanSubject::group((int) $this->classe->id, 'member');

        $plan = new FilePlan('t', 'Classe_3A', ['classe' => [$members]], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', \App\Enums\PlanNodeNature::Partagee, [
                new PlanGrant('classe', $members, [PlanGrant::VERB_LIRE]),
            ]),
            new PlanNode('alice', 'Perso', \App\Enums\PlanNodeNature::ParMembre, [
                new PlanGrant('__member__', PlanSubject::user((int) $alice->id), PlanGrant::VERBS),
            ], true, null, ['classe']),
        ]);

        $index = $this->projector()->reverseIndex($plan);

        self::assertSame($members->sortKey(), $index['groups']['se5_3a_member']->sortKey());
        self::assertSame(
            PlanSubject::user((int) $alice->id)->sortKey(),
            $index['users']['alice-nc']->sortKey(),
        );

        // Un nom qui RESSEMBLE à un nom compilé mais que le plan n'exprime pas reste
        // étranger : rien n'est deviné par découpage.
        self::assertArrayNotHasKey('se5_3a_manager', $index['groups']);
    }

    /**
     * Les sujets d'un plan comprennent CEUX DES RÔLES CLOS : c'est sur eux que la
     * clôture se referme, et les oublier rendrait la fermeture impossible à écrire.
     */
    #[Test]
    public function the_subjects_of_a_plan_include_those_of_its_closed_roles(): void
    {
        $members = PlanSubject::group((int) $this->classe->id, 'member');
        $managers = PlanSubject::group((int) $this->classe->id, 'manager');

        $plan = new FilePlan('t', 'Classe_3A', ['classe' => [$members], 'equipe' => [$managers]], [
            new PlanNode('_profs', 'Profs', \App\Enums\PlanNodeNature::Partagee, [
                new PlanGrant('equipe', $managers, PlanGrant::VERBS),
            ], true, null, ['classe']),
        ]);

        $keys = array_map(
            static fn (PlanSubject $s): string => $s->sortKey(),
            $this->projector()->subjectsOf($plan),
        );

        self::assertContains($members->sortKey(), $keys, 'le sujet du rôle CLOS doit être connu');
        self::assertContains($managers->sortKey(), $keys);
    }
}
