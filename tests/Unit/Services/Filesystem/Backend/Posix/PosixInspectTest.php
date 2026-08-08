<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendObservation;
use App\Enums\PlanNodeNature;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.4 — LA RELECTURE et la REPROJECTION INVERSE.
 *
 * Ce que ce fichier tient : rien ne remonte en nom système, rien n'est inventé,
 * rien n'est tu, et une entrée vide est une observation à part entière.
 */
class PosixInspectTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/se5-inspect-' . uniqid();
        @mkdir($this->tempRoot . '/proj', 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot . '/proj');
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    /** @param list<PlanGrant> $grants */
    private function plan(string $root = 'proj', array $grants = []): FilePlan
    {
        return new FilePlan('@partage', $root, [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre, $grants),
        ]);
    }

    private function fakeAcl(string ...$lines): void
    {
        Process::fake([
            'sudo getfacl *' => Process::result(output: implode("\n", $lines), exitCode: 0),
            '*' => Process::result(output: '', exitCode: 0),
        ]);
    }

    private const STRUCTURAL = [
        'user::rwx',
        'group::---',
        'group:domain\\040admins:rwx',
        'mask::rwx',
        'other::---',
        'default:user::rwx',
        'default:group:domain\\040admins:rwx',
        'default:mask::rwx',
        'default:other::---',
    ];

    #[Test]
    public function the_structural_base_is_never_an_observed_grant(): void
    {
        $this->fakeAcl(...self::STRUCTURAL);

        $observation = app(PosixFileBackend::class)->inspect($this->plan())->for(PlanNode::ROOT_PATH);

        self::assertSame(FileBackendObservation::Observe, $observation->status);
        self::assertSame([], $observation->grants);
        self::assertNull($observation->detail);
    }

    #[Test]
    public function a_named_entry_is_reprojected_onto_an_internal_identity_never_a_system_name(): void
    {
        $user = User::factory()->create(['login' => 'alice']);
        $this->fakeAcl(...[...self::STRUCTURAL, 'user:alice:r-x', 'default:user:alice:r-x']);

        $observation = app(PosixFileBackend::class)->inspect($this->plan())->for(PlanNode::ROOT_PATH);

        self::assertCount(1, $observation->grants, 'le miroir d\'héritage n\'est pas un octroi observé');
        self::assertSame(PlanSubject::TYPE_USER, $observation->grants[0]->subject->type);
        self::assertSame((int) $user->id, $observation->grants[0]->subject->id);
        self::assertSame([PlanGrant::VERB_LIRE], $observation->grants[0]->verbs);
    }

    #[Test]
    public function a_group_entry_is_reprojected_by_forward_projection(): void
    {
        $classe = UserGroup::create([
            'name' => 'Classe_3SB', 'type' => 'classe',
            'ad_dn' => 'CN=Classe_3SB,OU=Groupes,OU=0991229y,DC=lab,DC=lan',
        ]);
        $this->fakeAcl(...[...self::STRUCTURAL, 'group:classe_3sb-1229y:rwx']);

        $observation = app(PosixFileBackend::class)->inspect($this->plan())->for(PlanNode::ROOT_PATH);

        self::assertCount(1, $observation->grants);
        self::assertSame((int) $classe->id, $observation->grants[0]->subject->id);
        self::assertSame(PlanGrant::VERBS, $observation->grants[0]->verbs);
    }

    /**
     * L'ACCÈS « AUCUN ». Une entrée présente et vide est la forme matérialisée
     * d'une suspension : elle se relit, elle ne s'écarte pas en silence, et elle ne
     * se confond pas avec l'absence d'entrée.
     */
    #[Test]
    public function an_empty_entry_reads_as_the_access_none_never_as_an_absence(): void
    {
        $user = User::factory()->create(['login' => 'alice']);
        $this->fakeAcl(...[...self::STRUCTURAL, 'user:alice:---']);

        $observation = app(PosixFileBackend::class)->inspect($this->plan())->for(PlanNode::ROOT_PATH);

        self::assertCount(1, $observation->grants);
        self::assertSame([], $observation->grants[0]->verbs);
    }

    /**
     * Ce qui ne se reprojette pas est COMPTÉ, pas inventé ni tu — et compté SANS
     * nommer ce que le système, lui, connaît.
     */
    #[Test]
    public function what_cannot_be_reprojected_is_counted_neutrally_and_never_named(): void
    {
        $this->fakeAcl(...[...self::STRUCTURAL, 'user:fantome:rwx', 'group:etranger:r-x', 'user:exec:--x']);

        $observation = app(PosixFileBackend::class)->inspect($this->plan())->for(PlanNode::ROOT_PATH);

        self::assertSame([], $observation->grants);
        self::assertStringContainsString('3', (string) $observation->detail);
        self::assertStringNotContainsString('fantome', (string) $observation->detail);
        self::assertStringNotContainsString('etranger', (string) $observation->detail);
    }

    #[Test]
    public function a_missing_node_is_absent(): void
    {
        Process::fake();

        $absent = app(PosixFileBackend::class)->inspect($this->plan('fantome'))->for(PlanNode::ROOT_PATH);

        self::assertSame(FileBackendObservation::Absent, $absent->status);
    }

    /**
     * Une relecture en échec DIT sa cause — la redirection d'erreur de l'ancien
     * audit la jetait, et il ne restait qu'un statut sans explication.
     *
     * (Un seul appel de simulation d'exécution par test : sur une simulation déjà
     * active, un second appel est ignoré — d'où la séparation d'avec le test
     * ci-dessus.)
     */
    #[Test]
    public function a_failed_read_is_a_failure_that_names_its_cause(): void
    {
        Process::fake([
            'sudo getfacl *' => Process::result(output: '', errorOutput: 'permission denied', exitCode: 1),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $failed = app(PosixFileBackend::class)->inspect($this->plan())->for(PlanNode::ROOT_PATH);

        self::assertSame(FileBackendObservation::Echec, $failed->status);
        self::assertNotNull($failed->detail);
    }

    /**
     * Le plafond n'est PAS regardé, partout : dette datée, jamais une valeur
     * affirmée sans lecture.
     */
    #[Test]
    public function the_cap_is_never_claimed_to_have_been_looked_at(): void
    {
        $this->fakeAcl(...self::STRUCTURAL);

        $observation = app(PosixFileBackend::class)->inspect($this->plan())->for(PlanNode::ROOT_PATH);

        self::assertFalse($observation->plafondObserve);
        self::assertNull($observation->plafond);
    }

    /**
     * Le balayage couvre la RACINE — la fuite mesurée en ouverture d'epic était
     * une relecture qui rendait les enfants sans elle.
     */
    #[Test]
    public function the_sweep_covers_every_node_of_the_plan_root_included(): void
    {
        @mkdir($this->tempRoot . '/proj/_travail', 0o755, true);
        $this->fakeAcl(...self::STRUCTURAL);

        $report = app(PosixFileBackend::class)->inspect(new FilePlan('@arbre', 'proj', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
            new PlanNode('_travail', 'Travail', PlanNodeNature::ContenuLibre),
        ]));

        self::assertSame(2, $report->count());
        self::assertNotNull($report->for(PlanNode::ROOT_PATH));
        self::assertNotNull($report->for('_travail'));

        @rmdir($this->tempRoot . '/proj/_travail');
    }

    /**
     * L'index du PLAN d'abord : `classe_x` est à la fois le mappage nu d'une classe
     * et son rôle de membre. Sans cette priorité, la comparaison désiré/observé
     * comparerait deux sujets différents et crierait à l'écart sur un état
     * parfaitement conforme.
     */
    #[Test]
    public function the_plan_index_settles_the_ambiguity_between_the_bare_mapping_and_the_edge_role(): void
    {
        $classe = UserGroup::create([
            'name' => 'Classe_3SB', 'type' => 'classe',
            'ad_dn' => 'CN=Classe_3SB,OU=Groupes,OU=0991229y,DC=lab,DC=lan',
        ]);
        $this->fakeAcl(...[...self::STRUCTURAL, 'group:classe_3sb-1229y:rwx']);

        $observation = app(PosixFileBackend::class)->inspect($this->plan('proj', [
            new PlanGrant('@role', PlanSubject::group((int) $classe->id, 'member'), PlanGrant::VERBS),
        ]))->for(PlanNode::ROOT_PATH);

        self::assertSame('member', $observation->grants[0]->subject->edgeRole);
    }
}
