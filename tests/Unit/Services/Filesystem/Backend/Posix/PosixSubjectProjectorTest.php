<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendOutcome;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixSubjectProjection;
use App\Services\Filesystem\Backend\Posix\PosixSubjectProjector;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.4 — LA TABLE DE TRADUCTION DES SUJETS, branche par branche, refus
 * compris.
 */
class PosixSubjectProjectorTest extends TestCase
{
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

    private function projector(): PosixSubjectProjector
    {
        return app(PosixSubjectProjector::class);
    }

    private function classe(): UserGroup
    {
        return UserGroup::create([
            'name' => 'Classe_3SB',
            'type' => 'classe',
            'ad_dn' => 'CN=Classe_3SB,OU=Groupes,OU=0991229y,DC=lab,DC=lan',
        ]);
    }

    // =========================================================================
    // Comptes
    // =========================================================================

    #[Test]
    public function a_user_subject_becomes_its_login_with_its_case_preserved(): void
    {
        Process::fake();
        $user = User::factory()->create(['login' => 'Bob.Martin']);

        $projection = $this->projector()->project(PlanSubject::user((int) $user->id));

        self::assertTrue($projection->isResolved());
        self::assertSame(PosixSubjectProjection::TYPE_USER, $projection->type);
        self::assertSame('Bob.Martin', $projection->name);
    }

    #[Test]
    public function a_vanished_account_is_a_failure_that_names_the_internal_identity(): void
    {
        Process::fake();

        $projection = $this->projector()->project(PlanSubject::user(999_999));

        self::assertFalse($projection->isResolved());
        self::assertSame(FileBackendOutcome::Echec, $projection->refusal);
        self::assertStringContainsString('999999', (string) $projection->detail);
    }

    #[Test]
    public function an_unsafe_login_is_a_failure_and_never_a_written_entry(): void
    {
        Process::fake();
        $user = User::factory()->create(['login' => 'in valid']);

        $projection = $this->projector()->project(PlanSubject::user((int) $user->id));

        self::assertFalse($projection->isResolved());
        self::assertSame(FileBackendOutcome::Echec, $projection->refusal);
        self::assertNull($projection->name);
    }

    // =========================================================================
    // Groupes — mappage historique (sans rôle d'arête)
    // =========================================================================

    #[Test]
    public function a_group_without_an_edge_role_uses_the_historic_mapping(): void
    {
        Process::fake();

        $classe = $this->classe();
        $equipe = UserGroup::create([
            'name' => 'equipe_3SB', 'type' => 'equipe',
            'ad_dn' => 'CN=equipe_3SB,OU=Groupes,OU=0991229y,DC=lab,DC=lan',
        ]);
        $custom = UserGroup::create(['name' => 'Direction', 'type' => 'custom']);

        self::assertSame('classe_3sb-1229y', $this->projector()->project(PlanSubject::group((int) $classe->id))->name);
        self::assertSame('equipe_3sb-1229y', $this->projector()->project(PlanSubject::group((int) $equipe->id))->name);
        self::assertSame('direction', $this->projector()->project(PlanSubject::group((int) $custom->id))->name);
    }

    // =========================================================================
    // Groupes — mappage d'arête (le trio de compatibilité)
    // =========================================================================

    #[Test]
    public function the_three_edge_roles_of_a_class_map_onto_the_legacy_trio(): void
    {
        Process::fake();
        $classe = $this->classe();

        self::assertSame('classe_3sb-1229y', $this->projector()->project(PlanSubject::group((int) $classe->id, 'member'))->name);
        self::assertSame('equipe_3sb-1229y', $this->projector()->project(PlanSubject::group((int) $classe->id, 'manager'))->name);
        self::assertSame('pp_3sb-1229y', $this->projector()->project(PlanSubject::group((int) $classe->id, 'owner'))->name);
    }

    /**
     * Sur un autre type, le rôle de membre se projette sur la CIBLE PRIMAIRE — le
     * collectif entier. Surensemble ASSUMÉ (les gestionnaires en sont aussi
     * membres), jamais un sous-ensemble silencieux.
     */
    #[Test]
    public function on_another_type_the_member_role_falls_back_to_the_primary_collective(): void
    {
        Process::fake();
        $projet = UserGroup::create(['name' => 'Projet_Robotique', 'type' => 'projet']);

        self::assertSame(
            $this->projector()->project(PlanSubject::group((int) $projet->id))->name,
            $this->projector()->project(PlanSubject::group((int) $projet->id, 'member'))->name,
        );
    }

    /**
     * LA DISTINCTION QUI COMPTE. Un rôle de gestion sur un type que SE5 ne projette
     * pas est une DETTE (temporaire, propriété de notre code), jamais une limite du
     * modèle POSIX (permanente). Les confondre inverserait l'affichage : on
     * masquerait un réglage qu'il faut griser.
     */
    #[Test]
    public function an_unprojected_management_role_is_a_debt_never_a_model_limit(): void
    {
        Process::fake();

        foreach (['cours', 'matiere', 'projet', 'custom'] as $type) {
            $group = UserGroup::create(['name' => 'G' . $type, 'type' => $type]);

            foreach (['manager', 'owner'] as $role) {
                $projection = $this->projector()->project(PlanSubject::group((int) $group->id, $role));

                self::assertFalse($projection->isResolved(), "{$type}/{$role}");
                self::assertSame(FileBackendOutcome::NonImplemente, $projection->refusal, "{$type}/{$role}");
                self::assertTrue($projection->refusal->isImplementationDebt());
                self::assertFalse($projection->refusal->isModelLimit());
                self::assertStringContainsString($role, (string) $projection->detail);
                self::assertStringContainsString($type, (string) $projection->detail);
            }
        }
    }

    // =========================================================================
    // Jamais un nom inventé
    // =========================================================================

    #[Test]
    public function a_name_the_system_cannot_resolve_is_refused_and_named(): void
    {
        Process::fake([
            'getent group *' => Process::result(output: '', exitCode: 2),
            '*' => Process::result(output: '', exitCode: 0),
        ]);
        $classe = $this->classe();

        $projection = $this->projector()->project(PlanSubject::group((int) $classe->id));

        self::assertFalse($projection->isResolved());
        self::assertSame(FileBackendOutcome::Echec, $projection->refusal);
        self::assertStringContainsString('classe_3sb-1229y', (string) $projection->detail);
    }

    /**
     * « La réponse est non » et « il n'y a pas eu de réponse » ne sont PAS le
     * même fait, et la suite le prouve : la pose commence par purger les droits
     * étendus du répertoire. Traiter un silence comme une absence transformerait
     * une panne de résolution de noms en retrait d'accès, sur tous les
     * répertoires réconciliés pendant la panne.
     *
     * Le refus est donc BLOQUANT : le nœud n'est pas touché du tout.
     */
    #[Test]
    public function a_probe_that_could_not_answer_blocks_the_node_instead_of_dropping_the_grant(): void
    {
        foreach ([1, 3, 127] as $exitCode) {
            Process::fake([
                'getent group *' => Process::result(output: '', exitCode: $exitCode),
                '*' => Process::result(output: '', exitCode: 0),
            ]);
            $classe = $this->classe();

            $projection = $this->projector()->project(PlanSubject::group((int) $classe->id));

            self::assertFalse($projection->isResolved(), 'code ' . $exitCode);
            self::assertSame(FileBackendOutcome::Echec, $projection->refusal, 'code ' . $exitCode);
            self::assertTrue(
                $projection->blocking,
                'un doute sur la résolution (code ' . $exitCode . ') doit arrêter le nœud, pas le dégrader',
            );
            self::assertStringContainsString('impossible de savoir', (string) $projection->detail);

            $classe->delete();
        }
    }

    /**
     * Le pendant du test ci-dessus : l'absence FERME (le code de sortie que
     * l'outil réserve à la clé introuvable) reste un refus ordinaire — les autres
     * octrois du nœud s'écrivent, et l'état le dit.
     */
    #[Test]
    public function a_group_the_system_firmly_reports_as_absent_is_a_non_blocking_refusal(): void
    {
        Process::fake([
            'getent group *' => Process::result(output: '', exitCode: 2),
            '*' => Process::result(output: '', exitCode: 0),
        ]);
        $classe = $this->classe();

        $projection = $this->projector()->project(PlanSubject::group((int) $classe->id));

        self::assertFalse($projection->isResolved());
        self::assertFalse($projection->blocking);
    }

    /**
     * Le DOUTE ne se mémorise pas : il est transitoire par nature, et le retenir
     * propagerait la panne d'un instant à tout le reste du passage.
     */
    #[Test]
    public function the_doubt_is_never_memoised_unlike_a_firm_verdict(): void
    {
        Process::fake([
            'getent group *' => Process::result(output: '', exitCode: 127),
            '*' => Process::result(output: '', exitCode: 0),
        ]);
        $classe = $this->classe();

        $projector = $this->projector();
        $projector->project(PlanSubject::group((int) $classe->id));
        $projector->project(PlanSubject::group((int) $classe->id));

        Process::assertRanTimes(fn ($p): bool => str_starts_with($p->command, 'getent group '), 2);
    }

    /**
     * La sonde d'existence est une LECTURE : sans élévation de privilège, et
     * mémorisée pour ne pas interroger le système une fois par nœud.
     */
    #[Test]
    public function the_existence_probe_is_read_only_without_privilege_and_memoised(): void
    {
        Process::fake();
        $classe = $this->classe();

        $projector = $this->projector();
        $projector->project(PlanSubject::group((int) $classe->id));
        $projector->project(PlanSubject::group((int) $classe->id));
        $projector->project(PlanSubject::group((int) $classe->id));

        // UNE seule sonde pour trois projections du même nom : une même audience
        // revient sur plusieurs nœuds d'un arbre, et redemander au système à chaque
        // fois n'apprendrait rien de plus.
        Process::assertRanTimes(fn ($p): bool => str_starts_with($p->command, 'getent group '), 1);
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'sudo getent'));
    }

    // =========================================================================
    // Projection inverse
    // =========================================================================

    #[Test]
    public function the_reverse_index_resolves_the_ambiguous_name_onto_the_subject_the_plan_expresses(): void
    {
        Process::fake();
        $classe = $this->classe();

        $plan = new \App\Services\Filesystem\Plan\FilePlan('@partage', 'classe3a', [], [
            new \App\Services\Filesystem\Plan\PlanNode(
                \App\Services\Filesystem\Plan\PlanNode::ROOT_PATH,
                'Racine',
                \App\Enums\PlanNodeNature::ContenuLibre,
                [new \App\Services\Filesystem\Plan\PlanGrant(
                    '@role',
                    PlanSubject::group((int) $classe->id, 'member'),
                    'rw',
                )],
            ),
        ]);

        $index = $this->projector()->reverseIndex($plan);

        // `classe_3sb-1229y` est à la fois le mappage nu de la classe ET son rôle
        // de membre. L'index du plan tranche vers ce que le plan exprime — sans
        // quoi la comparaison désiré/observé comparerait deux sujets différents.
        self::assertSame('member', $index['groups']['classe_3sb-1229y']->edgeRole);
    }

    #[Test]
    public function the_general_index_still_names_a_group_the_plan_does_not_express(): void
    {
        Process::fake();
        $this->classe();
        $autre = UserGroup::create(['name' => 'Direction', 'type' => 'custom']);

        $plan = new \App\Services\Filesystem\Plan\FilePlan('@partage', 'vide', [], [
            new \App\Services\Filesystem\Plan\PlanNode(
                \App\Services\Filesystem\Plan\PlanNode::ROOT_PATH,
                'Racine',
                \App\Enums\PlanNodeNature::ContenuLibre,
            ),
        ]);

        $index = $this->projector()->reverseIndex($plan);

        self::assertSame((int) $autre->id, $index['groups']['direction']->id);
        self::assertNull($index['groups']['direction']->edgeRole);
    }
}
