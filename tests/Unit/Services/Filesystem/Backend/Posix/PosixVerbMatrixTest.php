<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixAclCompiler;
use App\Services\Filesystem\Backend\Posix\PosixVerbRendering;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.4 — LA MATRICE DE DÉGRADATION, BRANCHE PAR BRANCHE.
 *
 * Les QUINZE combinaisons non vides des quatre verbes, chacune avec sa
 * classification et le niveau qu'elle produit. Ce n'est pas une table de vérité
 * recopiée depuis le code : c'est l'attendu écrit à la main, dérivé des deux axes
 * du mécanisme, et confronté à ce que la compilation rend vraiment. Une
 * implémentation qui « simplifierait » la règle ferait tomber une ligne au moins.
 *
 * Les trois classifications :
 *  - **exact** — tout ce que l'octroi demande est rendu ;
 *  - **dégradé DÉCLARÉ** — tout est rendu, mais par un mécanisme approché (la
 *    restriction de suppression au propriétaire : le déposant peut encore retirer
 *    SES propres dépôts). Rendu complet, donc aucun déclin ;
 *  - **non exprimable** — un verbe au moins n'est pas rendu, le nœud le DIT.
 */
class PosixVerbMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const L = PlanGrant::VERB_LIRE;

    private const E = PlanGrant::VERB_EDITER;

    private const C = PlanGrant::VERB_CREER;

    private const S = PlanGrant::VERB_SUPPRIMER;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        Process::fake();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    /**
     * LES QUINZE LIGNES.
     *
     * Chaque ligne : les verbes demandés, les verbes rendus, le niveau des
     * DOSSIERS, le niveau des FICHIERS, la restriction de suppression attendue sur
     * un nœud qui ne porte que cet octroi.
     *
     * @return array<string, array{0:list<string>,1:list<string>,2:string,3:string,4:bool}>
     */
    public static function matrix(): array
    {
        return [
            // --- rendu EXACT, et les deux ancres du référentiel figé -----------
            'lire — l\'ancre de la lecture seule' => [[self::L], [self::L], 'rx', 'rx', false],
            'les quatre — l\'ancre de l\'écriture pleine' => [PlanGrant::VERBS, PlanGrant::VERBS, 'rwx', 'rwx', false],
            'editer seul' => [[self::E], [self::E], 'x', 'wx', false],
            'lire + editer' => [[self::L, self::E], [self::L, self::E], 'rx', 'rwx', false],
            'creer + supprimer' => [[self::C, self::S], [self::C, self::S], 'wx', 'x', false],
            'lire + creer + supprimer' => [[self::L, self::C, self::S], [self::L, self::C, self::S], 'rwx', 'rx', false],
            'editer + creer + supprimer' => [
                [self::E, self::C, self::S], [self::E, self::C, self::S], 'wx', 'wx', false,
            ],

            // --- rendu DÉGRADÉ mais COMPLET : la restriction de suppression ----
            'creer seul — déposer sans effacer' => [[self::C], [self::C], 'wx', 'x', true],
            'lire + creer — le dépôt de devoirs' => [[self::L, self::C], [self::L, self::C], 'rwx', 'rx', true],
            'editer + creer' => [[self::E, self::C], [self::E, self::C], 'wx', 'wx', true],
            'lire + editer + creer' => [
                [self::L, self::E, self::C], [self::L, self::E, self::C], 'rwx', 'rwx', true,
            ],

            // --- NON EXPRIMABLE : supprimer sans creer -------------------------
            'supprimer seul — rien n\'est rendu' => [[self::S], [], '', '', false],
            'lire + supprimer' => [[self::L, self::S], [self::L], 'rx', 'rx', false],
            'editer + supprimer' => [[self::E, self::S], [self::E], 'x', 'wx', false],
            'lire + editer + supprimer' => [[self::L, self::E, self::S], [self::L, self::E], 'rx', 'rwx', false],
        ];
    }

    /**
     * La matrice est-elle COMPLÈTE ? Quinze lignes, quinze combinaisons distinctes.
     * Sans ce contrôle, une ligne oubliée passerait inaperçue et la garde ne
     * garderait qu'une partie du problème.
     */
    #[Test]
    public function the_matrix_covers_the_fifteen_non_empty_combinations(): void
    {
        $seen = [];
        foreach (self::matrix() as $row) {
            $seen[] = implode(',', PlanGrant::canonicalize($row[0]));
        }

        self::assertCount(15, array_unique($seen), 'les 15 combinaisons doivent être couvertes, sans doublon');
    }

    /**
     * LA DÉRIVATION, ligne par ligne. La restriction de suppression est supposée
     * DISPONIBLE au niveau du nœud quand la ligne l'attend — c'est le nœud qui la
     * décide, et sa décision est testée à part.
     *
     * @param  list<string>  $verbs
     * @param  list<string>  $rendered
     */
    #[Test]
    #[DataProvider('matrix')]
    public function each_combination_renders_what_the_two_axes_derive(
        array $verbs,
        array $rendered,
        string $directoryMode,
        string $fileMode,
        bool $restriction,
    ): void {
        $canonical = PlanGrant::canonicalize($verbs);
        $rendering = PosixVerbRendering::of($canonical, $restriction);

        self::assertSame($rendered, $rendering->rendered, 'verbes rendus');
        self::assertSame($directoryMode, $rendering->directoryMode, 'niveau des dossiers');
        self::assertSame($fileMode, $rendering->fileMode, 'niveau des fichiers');
        self::assertSame(array_values(array_diff($canonical, $rendered)), $rendering->missing);

        // LA RÈGLE, exercée sur chaque ligne : jamais un verbe de MUTATION que
        // l'octroi ne porte pas. C'est l'invariant qui interdit d'accorder la
        // création pour pouvoir rendre la suppression.
        self::assertSame(
            [],
            array_diff($rendering->rendered, $canonical),
            'un verbe a été rendu que l\'octroi ne demande pas',
        );
    }

    /**
     * La classification des quinze lignes, en trois familles disjointes — et le
     * compte de chacune. Un refactoring qui rendrait « exact » une ligne dégradée
     * (ou l'inverse) tomberait ici.
     */
    #[Test]
    public function the_three_classifications_partition_the_matrix(): void
    {
        $exact = $degraded = $declined = [];

        foreach (self::matrix() as $label => [$verbs, , , , $restriction]) {
            $rendering = PosixVerbRendering::of(PlanGrant::canonicalize($verbs), $restriction);

            if (! $rendering->isExact()) {
                $declined[] = $label;
            } elseif ($restriction) {
                $degraded[] = $label;
            } else {
                $exact[] = $label;
            }
        }

        self::assertCount(7, $exact, 'sept combinaisons se rendent exactement, sans mécanisme approché');
        self::assertCount(4, $degraded, 'quatre demandent la restriction de suppression');
        self::assertCount(4, $declined, 'quatre portent la suppression sans la création : non exprimables');
    }

    // =========================================================================
    // La restriction est une propriété du NŒUD
    // =========================================================================

    #[Test]
    public function a_node_asking_to_deposit_without_erasing_carries_the_restriction(): void
    {
        $compiled = $this->compile([
            $this->grant('classe', [self::L, self::C]),
        ]);

        self::assertTrue($compiled->restrictsDeletion);
        self::assertSame([], $compiled->refusals, 'rendu DÉGRADÉ mais COMPLET : rien à déclarer');
    }

    /**
     * **LE NŒUD MIXTE — le cas que toute conception « par octroi » rate.**
     *
     * Un octroi « déposer sans effacer » ET un octroi qui porte la suppression sur
     * le même dossier. Poser la restriction retirerait au second l'effacement du
     * travail des autres : une régression SILENCIEUSE sur un droit écrit dans la
     * recette. On ne la pose donc pas, le premier retombe sur son intersection
     * exprimable, et le nœud le DIT.
     */
    #[Test]
    public function a_mixed_node_does_not_carry_the_restriction_and_says_so(): void
    {
        $compiled = $this->compile([
            $this->grant('classe', [self::L, self::C]),
            $this->grant('equipe', PlanGrant::VERBS),
        ]);

        self::assertFalse($compiled->restrictsDeletion, 'la restriction retirerait un droit à l\'autre octroi');
        self::assertSame([FileBackendOutcome::NonExprimable], $compiled->refusalOutcomes());

        // L'octroi « déposer sans effacer » est rendu à son intersection
        // exprimable — la lecture — et l'autre garde tout.
        self::assertContains('group:classe_3emea:rx', $compiled->acls);
        self::assertContains('group:equipe_3emea:rwx', $compiled->acls);
    }

    #[Test]
    public function the_decline_detail_names_the_role_and_the_verbs_never_the_mechanism(): void
    {
        $compiled = $this->compile([$this->grant('classe', [self::L, self::S])]);

        self::assertSame([FileBackendOutcome::NonExprimable], $compiled->refusalOutcomes());

        $detail = $compiled->refusalDetails()[0];
        self::assertStringContainsString('classe', $detail, 'le détail doit nommer le RÔLE');
        self::assertStringContainsString('suppression', $detail);
        self::assertStringContainsString('création', $detail);

        foreach (['rwx', 'r-x', ':rx', 'sticky', 'setfacl', 'chmod', 'bit', '+t'] as $mechanism) {
            self::assertStringNotContainsStringIgnoringCase(
                $mechanism,
                $detail,
                'le détail traverse la ligne de coupe : il parle rôles et verbes, jamais mécanisme',
            );
        }
    }

    /**
     * Un octroi dont RIEN n'est rendu n'écrit AUCUNE entrée. Écrire une entrée vide
     * serait pire que rien : c'est la forme matérialisée d'une suspension, et une
     * relecture la lirait comme telle.
     */
    #[Test]
    public function a_grant_that_renders_nothing_writes_no_entry_at_all(): void
    {
        $compiled = $this->compile([$this->grant('classe', [self::S])]);

        self::assertSame(PosixAclCompiler::BASE_ACLS, $compiled->acls, 'aucune entrée d\'audience');
        self::assertSame([FileBackendOutcome::NonExprimable], $compiled->refusalOutcomes());
    }

    // =========================================================================
    // La pose DIFFÉRENCIÉE
    // =========================================================================

    #[Test]
    public function a_grant_whose_files_and_directories_differ_produces_two_lists(): void
    {
        $compiled = $this->compile([$this->grant('classe', [self::L, self::E])]);

        self::assertTrue($compiled->isDifferentiated());
        self::assertContains('group:classe_3emea:rx', $compiled->acls, 'le dossier : lister et traverser, PAS créer');
        self::assertContains('group:classe_3emea:rwx', $compiled->fileAcls, 'le fichier : lire et écrire le contenu');

        // La liste des fichiers ne porte AUCUN miroir d'héritage — un fichier n'en
        // accepte pas, et l'outil refuserait la commande entière.
        foreach ($compiled->fileAcls as $acl) {
            self::assertStringStartsNotWith('default:', $acl);
        }
    }

    #[Test]
    public function the_two_migrated_combinations_never_differentiate_and_never_restrict(): void
    {
        foreach ([[self::L], PlanGrant::VERBS] as $verbs) {
            $compiled = $this->compile([$this->grant('classe', $verbs)]);

            self::assertFalse($compiled->isDifferentiated(), 'une seule liste, posée partout, comme hier');
            self::assertFalse($compiled->restrictsDeletion, 'aucune restriction : rien ne bouge sur une instance en place');
            self::assertSame([], $compiled->refusals, 'rien à déclarer');
        }
    }

    /**
     * La suspension est ORTHOGONALE aux verbes : quatre verbes suspendus donnent la
     * même entrée vide qu'un octroi de lecture suspendu.
     */
    #[Test]
    public function a_suspended_grant_stays_an_explicitly_empty_entry_whatever_its_verbs(): void
    {
        $node = new PlanNode('_echange', 'Échange', PlanNodeNature::Activable, [
            new PlanGrant('classe', PlanSubject::group($this->groupId()), PlanGrant::VERBS, true, true),
        ], active: false);

        $compiled = app(PosixAclCompiler::class)->compile($node);

        self::assertContains('group:classe_3emea:---', $compiled->acls);
        self::assertFalse($compiled->restrictsDeletion, 'un octroi suspendu ne demande rien');
        self::assertSame([], $compiled->refusals);
    }

    // =========================================================================
    // Décor
    // =========================================================================

    private ?int $groupId = null;

    private function groupId(): int
    {
        $this->groupId ??= (int) UserGroup::create([
            'name' => 'Classe_3emeA',
            'type' => 'classe',
        ])->id;

        return $this->groupId;
    }

    private function grant(string $role, array $verbs): PlanGrant
    {
        $id = $role === 'classe' ? $this->groupId() : $this->teamId();

        return new PlanGrant($role, PlanSubject::group($id), $verbs);
    }

    private ?int $teamId = null;

    private function teamId(): int
    {
        $this->teamId ??= (int) UserGroup::create([
            'name' => 'equipe_3emeA',
            'type' => 'equipe',
        ])->id;

        return $this->teamId;
    }

    /** @param list<PlanGrant> $grants */
    private function compile(array $grants): \App\Services\Filesystem\Backend\Posix\CompiledNodeAcl
    {
        return app(PosixAclCompiler::class)->compile(
            new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, $grants),
        );
    }
}
