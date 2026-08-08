<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Models\DirectoryTemplate;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixAclCompiler;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\Backend\Posix\PosixTraversalPlanner;
use App\Services\Filesystem\ClassTreeShareService;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Filesystem\PlanStateComparator;
use App\Services\Filesystem\TreePlanService;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.5 — LE COULOIR, ÉCRIT, RELU, ET NE DONNANT RIEN DE PLUS.
 *
 * Le planificateur se teste à nu ailleurs ({@see PosixTraversalPlannerTest}) ; ici
 * on regarde ce qui touche réellement le disque : la FORME de l'entrée, la FORME de
 * la commande, l'idempotence, et la boucle compilation → pose → relecture →
 * comparaison, dans les deux sens.
 *
 * **La simulation d'exécution est posée UNE fois par test, motifs NOMMÉS d'abord.**
 * Un attrape-tout déclaré en premier avale le motif de relecture, et le backend
 * rapporte alors « observé, aucun octroi » — vrai en apparence, faux en silence.
 * C'est la leçon de la story 62.4, et elle vaut aussi ici.
 */
class PosixTraversalBackendTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    /** Le rôle qui ne reçoit quelque chose QU'EN PROFONDEUR. */
    private const DEEP_GROUP = 'profs';

    /** Le rôle servi sur l'ancêtre. */
    private const HEAD_GROUP = 'direction';

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/se5-traverse-' . uniqid();
        @mkdir($this->tempRoot . '/proj/a/b', 0o777, true);
        config(['filesystem.shares_root' => $this->tempRoot]);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot . '/proj/a/b');
        @rmdir($this->tempRoot . '/proj/a');
        @rmdir($this->tempRoot . '/proj');
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    // =========================================================================
    // Décor : un ancêtre qui n'accorde rien au rôle profond
    // =========================================================================

    /** @return array{0:UserGroup,1:UserGroup} [servi sur l'ancêtre, servi en profondeur] */
    private function makeGroups(): array
    {
        return [
            UserGroup::create(['name' => 'Direction', 'type' => 'custom']),
            UserGroup::create(['name' => 'Profs', 'type' => 'custom']),
        ];
    }

    /**
     * `a` accorde à la direction ; `a/b` accorde aux profs, qui n'ont RIEN sur `a`.
     */
    private function plan(UserGroup $head, UserGroup $deep, array $verbs = [PlanGrant::VERB_LIRE]): FilePlan
    {
        return new FilePlan('@arbre', 'proj', [], [
            new PlanNode('a', 'Ancêtre', PlanNodeNature::Partagee, [
                new PlanGrant('direction', PlanSubject::group((int) $head->id), [PlanGrant::VERB_LIRE]),
            ]),
            new PlanNode('a/b', 'Dossier profond', PlanNodeNature::Partagee, [
                new PlanGrant('profs', PlanSubject::group((int) $deep->id), $verbs),
            ]),
        ]);
    }

    /**
     * Simulation d'exécution avec une relecture DIFFÉRENTE par nœud — sans quoi un
     * seul des deux nœuds pourrait jamais se lire conforme.
     *
     * @param  list<string>  $head  état du disque sur `a`
     * @param  list<string>  $deep  état du disque sur `a/b`
     */
    private function fakeDisk(array $head, array $deep): void
    {
        Process::fake([
            'sudo getfacl*a/b*' => Process::result(output: implode("\n", $deep), exitCode: 0),
            'sudo getfacl*' => Process::result(output: implode("\n", $head), exitCode: 0),
            'getent group *' => Process::result(),
            '*' => Process::result(),
        ]);
    }

    /** @return list<string> */
    private function commands(): array
    {
        $commands = [];
        Process::assertRan(function ($process) use (&$commands): bool {
            $commands[] = $process->command;

            return true;
        });

        return $commands;
    }

    // =========================================================================
    // AC3 — LA FORME DE L'ENTRÉE, ET CE QU'ELLE NE PORTE PAS
    // =========================================================================

    /**
     * **LE test de « rien de plus ».** L'entrée dérivée porte la traversée SEULE :
     * pas de lecture, pas d'écriture, pas de miroir d'héritage, pas de contrepartie
     * fichier. Chacune de ces quatre absences est un sur-octroi évité.
     */
    #[Test]
    public function the_derived_entry_is_the_traversal_alone_without_mirror_or_file_counterpart(): void
    {
        Process::fake(['getent group *' => Process::result(), '*' => Process::result()]);
        [$head, $deep] = $this->makeGroups();
        $plan = $this->plan($head, $deep);
        $node = $plan->node('a');

        $compiled = app(PosixAclCompiler::class)->compile(
            $node,
            (new PosixTraversalPlanner())->forNode($plan, $node),
        );

        self::assertSame(['group:' . self::DEEP_GROUP . ':--x'], $compiled->traversalAcls);

        // Aucune trace du rôle profond dans la liste ordinaire : ni entrée d'accès
        // supplémentaire, ni miroir d'héritage.
        foreach ($compiled->acls as $line) {
            self::assertStringNotContainsString(self::DEEP_GROUP, $line, 'ligne fautive : ' . $line);
        }
        // Aucune entrée de FICHIER : la traversée d'un fichier n'existe pas, et
        // l'écrire poserait le bit d'exécution sur des documents.
        self::assertSame([], $compiled->fileAcls);

        // Et le rôle servi sur l'ancêtre garde EXACTEMENT ce qu'il avait.
        self::assertContains('group:' . self::HEAD_GROUP . ':rx', $compiled->acls);
    }

    /**
     * Le rôle qui lit `a/b` **ne peut pas LISTER `a`** : sur l'ancêtre, la seule
     * entrée qui le concerne est le couloir, et le couloir ne porte pas la lecture.
     * C'est le piège central de la story, vérifié sur la chaîne compilée.
     */
    #[Test]
    public function the_deep_role_cannot_list_the_ancestor_it_only_walks_through_it(): void
    {
        Process::fake(['getent group *' => Process::result(), '*' => Process::result()]);
        [$head, $deep] = $this->makeGroups();
        $plan = $this->plan($head, $deep, PlanGrant::VERBS);
        $node = $plan->node('a');

        $compiled = app(PosixAclCompiler::class)->compile(
            $node,
            (new PosixTraversalPlanner())->forNode($plan, $node),
        );

        $forDeepRole = array_values(array_filter(
            $compiled->headAcls(),
            static fn (string $line): bool => str_contains($line, self::DEEP_GROUP),
        ));

        self::assertSame(['group:' . self::DEEP_GROUP . ':--x'], $forDeepRole);

        // Dit autrement, et c'est la formulation qui compte : aucune des lettres qui
        // ouvriraient le dossier n'apparaît.
        self::assertStringNotContainsString('r', substr($forDeepRole[0], strrpos($forDeepRole[0], ':') + 1));
        self::assertStringNotContainsString('w', substr($forDeepRole[0], strrpos($forDeepRole[0], ':') + 1));
    }

    /**
     * **LA POSE EST NON RÉCURSIVE, SUR LA TÊTE SEULE.** Une pose récursive — ou une
     * sélection par type — diffuserait la traversée dans tout le contenu de
     * l'ancêtre, sur ses sous-dossiers non gouvernés comme sur ses fichiers. Et
     * AUCUN binaire nouveau n'est émis pour cela.
     */
    #[Test]
    public function the_corridor_is_posted_on_the_head_directory_only_and_adds_no_binary(): void
    {
        $this->fakeDisk(['user::rwx'], ['user::rwx']);
        [$head, $deep] = $this->makeGroups();

        app(PosixFileBackend::class)->provision($this->plan($head, $deep));

        $posted = array_values(array_filter(
            $this->commands(),
            static fn (string $c): bool => str_contains($c, ':--x'),
        ));

        self::assertCount(1, $posted, 'un couloir, une commande');
        // Review 62.5 #3 — l'oracle gagne `-n` (masque non recalculé). Ce n'est pas
        // un assouplissement : la forme reste épinglée au caractère près, et les
        // deux interdits qu'elle porte — descendre, sélectionner — sont vérifiés
        // juste en dessous, inchangés. `-n` est ce qui empêche un couloir d'élargir
        // les droits effectifs d'une entrée voisine en faisant remonter le masque.
        self::assertMatchesRegularExpression(
            '/^sudo setfacl -P -n -m \'group:' . self::DEEP_GROUP . ':--x\' \S+$/',
            $posted[0],
            'la pose du couloir descend, sélectionne, ou recalcule le masque : ' . $posted[0],
        );
        self::assertStringNotContainsString(' -R ', $posted[0], 'pose récursive');
        self::assertStringNotContainsString('find ', $posted[0], 'sélection d\'objets');

        // Le jeu de binaires est celui d'hier — la garde dédiée l'énumère, on
        // vérifie ici qu'aucune commande nouvelle n'apparaît sur CE chemin.
        foreach ($this->commands() as $command) {
            $tokens = preg_split('/\s+/', trim($command)) ?: [];
            $binary = $tokens[0] === 'sudo' ? ($tokens[1] ?? '') : $tokens[0];
            self::assertContains(
                $binary,
                ['mkdir', 'setfacl', 'getfacl', 'chown', 'chgrp', 'chmod', 'mv', 'getent', 'find'],
                'binaire hors jeu : ' . $command,
            );
        }
    }

    /**
     * **L'IDEMPOTENCE TIENT AVEC LE COULOIR.** Un nœud dont le couloir est déjà en
     * place et le reste conforme rend `conforme` et n'écrit RIEN. Sans le couloir
     * dans l'ensemble comparé, ce nœud se serait cru dérivé à chaque passage.
     */
    #[Test]
    public function a_node_whose_corridor_is_already_in_place_is_conform_and_writes_nothing(): void
    {
        [$head, $deep] = $this->makeGroups();

        $this->fakeDisk(
            [...PosixAclCompiler::BASE_ACLS, 'group:' . self::HEAD_GROUP . ':r-x',
                'default:group:' . self::HEAD_GROUP . ':r-x', 'group:' . self::DEEP_GROUP . ':--x'],
            [...PosixAclCompiler::BASE_ACLS, 'group:' . self::DEEP_GROUP . ':r-x',
                'default:group:' . self::DEEP_GROUP . ':r-x'],
        );

        $report = app(PosixFileBackend::class)->provision($this->plan($head, $deep));

        self::assertSame(FileBackendOutcome::Conforme, $report->for('a')->outcome);
        self::assertSame(FileBackendOutcome::Conforme, $report->for('a/b')->outcome);
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'setfacl'));
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'chown'));
    }

    /**
     * Et la réciproque : un couloir DEVENU CADUC — plus aucun octroi profond ne le
     * motive — rend le nœud non conforme, et le passage qui suit le retire (la
     * repose commence par une purge).
     */
    #[Test]
    public function a_corridor_the_plan_no_longer_derives_is_wiped_away(): void
    {
        [$head] = $this->makeGroups();

        $this->fakeDisk(
            [...PosixAclCompiler::BASE_ACLS, 'group:' . self::HEAD_GROUP . ':r-x',
                'default:group:' . self::HEAD_GROUP . ':r-x', 'group:' . self::DEEP_GROUP . ':--x'],
            [...PosixAclCompiler::BASE_ACLS],
        );

        // Le plan ne porte PLUS le nœud profond : le couloir n'a plus de raison.
        $plan = new FilePlan('@arbre', 'proj', [], [
            new PlanNode('a', 'Ancêtre', PlanNodeNature::Partagee, [
                new PlanGrant('direction', PlanSubject::group((int) $head->id), [PlanGrant::VERB_LIRE]),
            ]),
        ]);

        $report = app(PosixFileBackend::class)->provision($plan);

        self::assertSame(FileBackendOutcome::Applique, $report->for('a')->outcome);
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'setfacl') && str_contains($p->command, '-b'));
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, ':--x'));
    }

    /**
     * Un sujet de couloir que la traduction REFUSE suit le même chemin qu'un octroi
     * refusé — et son détail nomme le rôle ET le nœud profond qu'on voulait rendre
     * atteignable. Sans le second, l'administrateur lirait « quelque chose a échoué
     * ici » sans jamais savoir ce qui, plus bas, en dépendait.
     */
    #[Test]
    public function a_corridor_subject_that_cannot_be_projected_is_reported_on_the_carrying_node(): void
    {
        Process::fake([
            'getent group *' => Process::result(output: '', exitCode: 2),
            '*' => Process::result(output: '', exitCode: 0),
        ]);
        [$head, $deep] = $this->makeGroups();

        $entry = app(PosixFileBackend::class)->provision($this->plan($head, $deep))->for('a');

        self::assertSame(FileBackendOutcome::Echec, $entry->outcome);
        self::assertStringContainsString('couloir', (string) $entry->detail);
        self::assertStringContainsString('a/b', (string) $entry->detail);
        self::assertStringContainsString('profs', (string) $entry->detail);
    }

    // =========================================================================
    // AC4 — LA BOUCLE FERMÉE
    // =========================================================================

    /**
     * **LA BOUCLE, DANS LE BON SENS.** Compilation → pose simulée → relecture →
     * comparaison : le couloir n'est PAS un octroi observé, le comparateur ne voit
     * AUCUNE différence, et le nœud est conforme. Sans le filtre, chaque couloir
     * posé serait compté « entrée en trop » à chaque passage — un bruit de dérive
     * perpétuel sur chaque instance.
     */
    #[Test]
    public function an_expected_corridor_is_structural_never_an_observed_grant(): void
    {
        [$head, $deep] = $this->makeGroups();
        $plan = $this->plan($head, $deep);

        $this->fakeDisk(
            [...PosixAclCompiler::BASE_ACLS, 'group:' . self::HEAD_GROUP . ':r-x', 'group:' . self::DEEP_GROUP . ':--x'],
            [...PosixAclCompiler::BASE_ACLS, 'group:' . self::DEEP_GROUP . ':r-x'],
        );

        $inspection = app(PosixFileBackend::class)->inspect($plan);
        $observation = $inspection->for('a');

        self::assertCount(1, $observation->grants, 'le couloir a été compté comme un octroi observé');
        self::assertSame((int) $head->id, $observation->grants[0]->subject->id);
        self::assertNull($observation->detail);

        $comparison = app(PlanStateComparator::class)->compare($plan, $inspection);

        self::assertSame(PlanStateComparator::NODE_CONFORME, $comparison['nodes'][0]['status']);
        self::assertSame([], $comparison['nodes'][0]['differences']);
    }

    /**
     * **ET DANS L'AUTRE SENS.** Le même passage, le couloir ABSENT du disque : le
     * nœud le DIT, et le comparateur — dont pas une ligne n'a changé — le classe en
     * écart par son mécanisme existant (un détail non vide suffit).
     */
    #[Test]
    public function a_missing_corridor_is_said_out_loud_and_lands_in_drift(): void
    {
        [$head, $deep] = $this->makeGroups();
        $plan = $this->plan($head, $deep);

        $this->fakeDisk(
            [...PosixAclCompiler::BASE_ACLS, 'group:' . self::HEAD_GROUP . ':r-x'],
            [...PosixAclCompiler::BASE_ACLS, 'group:' . self::DEEP_GROUP . ':r-x'],
        );

        $inspection = app(PosixFileBackend::class)->inspect($plan);
        $observation = $inspection->for('a');

        self::assertNotNull($observation->detail);
        self::assertStringContainsString('couloir', (string) $observation->detail);
        self::assertStringContainsString('profs', (string) $observation->detail);

        $comparison = app(PlanStateComparator::class)->compare($plan, $inspection);

        self::assertSame(PlanStateComparator::NODE_ECART, $comparison['nodes'][0]['status']);
    }

    /**
     * Une entrée de traversée ÉTRANGÈRE — un sujet qu'aucune dérivation n'attend —
     * reste comptée en écart. Le filtre porte sur le sujet ATTENDU, jamais sur
     * « tout ce qui ressemble à un couloir ».
     */
    #[Test]
    public function an_unexpected_traversal_entry_is_never_absolved(): void
    {
        [$head, $deep] = $this->makeGroups();
        UserGroup::create(['name' => 'Intrus', 'type' => 'custom']);
        $plan = $this->plan($head, $deep);

        $this->fakeDisk(
            [...PosixAclCompiler::BASE_ACLS, 'group:' . self::HEAD_GROUP . ':r-x',
                'group:' . self::DEEP_GROUP . ':--x', 'group:intrus:--x'],
            [...PosixAclCompiler::BASE_ACLS, 'group:' . self::DEEP_GROUP . ':r-x'],
        );

        $observation = app(PosixFileBackend::class)->inspect($plan)->for('a');

        self::assertNotNull($observation->detail);
        self::assertStringContainsString('1 entrée(s)', (string) $observation->detail);
        self::assertStringNotContainsString('intrus', (string) $observation->detail, 'aucun nom système ne remonte');
    }

    // =========================================================================
    // AC1 — LA DÉCISION, ÉPINGLÉE STRUCTURELLEMENT
    // =========================================================================

    /**
     * **LA DÉCISION EST VÉRIFIABLE, pas seulement écrite.** Le plan d'un rôle
     * octroyé UNIQUEMENT en profondeur ne porte AUCUNE trace de traversée : pas
     * d'octroi sur les ancêtres, et le rôle reste dans leur CLÔTURE. La compilation
     * du même plan, elle, en produit une. C'est exactement ce que « la traversée est
     * un savoir de backend » veut dire.
     */
    #[Test]
    public function the_plan_carries_no_trace_of_the_traversal_while_the_compilation_does(): void
    {
        Process::fake(['getent group *' => Process::result(), '*' => Process::result()]);
        [$head, $deep] = $this->makeGroups();

        $plan = new FilePlan('@arbre', 'proj', [], [
            new PlanNode('a', 'Ancêtre', PlanNodeNature::Partagee, [
                new PlanGrant('direction', PlanSubject::group((int) $head->id), [PlanGrant::VERB_LIRE]),
            ], closure: ['profs']),
            new PlanNode('a/b', 'Profond', PlanNodeNature::Partagee, [
                new PlanGrant('profs', PlanSubject::group((int) $deep->id), PlanGrant::VERBS),
            ], closure: ['direction']),
        ]);

        $ancestor = $plan->node('a');

        // Côté PLAN : rien. L'octroi n'existe pas, et la clôture est inchangée.
        self::assertCount(1, $ancestor->grants);
        self::assertSame('direction', $ancestor->grants[0]->roleKey);
        self::assertSame(['profs'], $ancestor->closure);
        self::assertStringNotContainsString('--x', $plan->toJson());

        // Côté BACKEND : le couloir existe.
        $compiled = app(PosixAclCompiler::class)->compile(
            $ancestor,
            (new PosixTraversalPlanner())->forNode($plan, $ancestor),
        );
        self::assertSame(['group:' . self::DEEP_GROUP . ':--x'], $compiled->traversalAcls);
    }

    // =========================================================================
    // AC7 — LA CLÔTURE RESTE CALCULÉE, ET LE COULOIR NE LA ROUVRE PAS
    // =========================================================================

    /**
     * **DEUX RÔLES EN CLÔTURE, CÔTE À CÔTE.** Celui dont AUCUN descendant ne lui
     * accorde rien n'a strictement aucune entrée — la fermeture d'hier, intacte.
     * Celui dont un descendant lui accorde quelque chose reçoit le couloir, et RIEN
     * D'AUTRE : il passe devant la porte, il n'entre pas dans la pièce. Aucun refus
     * explicite n'apparaît nulle part.
     */
    #[Test]
    public function a_closed_role_gets_nothing_unless_a_descendant_grants_it_something(): void
    {
        Process::fake(['getent group *' => Process::result(), '*' => Process::result()]);
        [$head, $deep] = $this->makeGroups();
        UserGroup::create(['name' => 'Muet', 'type' => 'custom']);

        $plan = new FilePlan('@arbre', 'proj', [], [
            new PlanNode('a', 'Ancêtre', PlanNodeNature::Partagee, [
                new PlanGrant('direction', PlanSubject::group((int) $head->id), [PlanGrant::VERB_LIRE]),
            ], closure: ['profs', 'muet']),
            new PlanNode('a/b', 'Profond', PlanNodeNature::Partagee, [
                new PlanGrant('profs', PlanSubject::group((int) $deep->id), PlanGrant::VERBS),
            ]),
        ]);

        $ancestor = $plan->node('a');
        $compiled = app(PosixAclCompiler::class)->compile(
            $ancestor,
            (new PosixTraversalPlanner())->forNode($plan, $ancestor),
        );
        $all = $compiled->headAcls();

        // Le rôle MUET : rien du tout, nulle part.
        foreach ($all as $line) {
            self::assertStringNotContainsString('muet', $line, 'la clôture a produit une entrée : ' . $line);
        }

        // Le rôle SERVI EN PROFONDEUR : le couloir, et le couloir seul.
        self::assertSame(
            ['group:' . self::DEEP_GROUP . ':--x'],
            array_values(array_filter($all, static fn (string $l): bool => str_contains($l, self::DEEP_GROUP))),
        );

        // Aucun refus explicite : la fermeture reste une ABSENCE.
        foreach ($all as $line) {
            self::assertStringNotContainsStringIgnoringCase('deny', $line);
        }
    }

    // =========================================================================
    // AC6 — L'IMPACT SUR LE SEED : L'ENSEMBLE VIDE, ÉPINGLÉ POSITIVEMENT
    // =========================================================================

    /**
     * **AUCUNE INSTANCE EN PLACE NE BOUGE, ET ON LE PROUVE PAR L'AFFIRMATIVE.**
     *
     * Sur les recettes livrées, tout rôle qui reçoit quelque chose en profondeur a
     * déjà un octroi actif sur chacun de ses ancêtres, et les élèves passent par
     * l'audience de la racine. Le planificateur rend donc l'ensemble VIDE sur CHAQUE
     * nœud — donc aucune entrée nouvelle n'est écrite nulle part, donc les
     * référentiels figés ne pouvaient pas bouger. Un test qui se contenterait de
     * constater que le référentiel n'a pas changé serait vrai pour une raison qu'on
     * n'aurait pas vérifiée.
     */
    #[Test]
    public function the_seeded_recipes_derive_no_corridor_at_all(): void
    {
        Process::fake(['getent group *' => Process::result(), '*' => Process::result()]);
        UserGroupUserPivotObserver::disableSync();
        ClassTreeShareService::disable();

        try {
            (new DirectoryTemplateSeeder())->run();

            $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
            foreach (['alecoz' => 'manager', 'bmartin' => 'member', 'cpetit' => 'member'] as $login => $role) {
                $user = User::factory()->create(['login' => $login, 'source' => 'ad']);
                $group->users()->attach($user->id, ['role' => $role]);
            }

            $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();
            $plan = app(TreePlanService::class)->planUsing($group->fresh(['users']), $template, []);

            self::assertGreaterThanOrEqual(6, count($plan->nodes), 'le décor doit porter un vrai arbre');

            $planner = new PosixTraversalPlanner();
            foreach ($plan->nodes as $node) {
                self::assertSame(
                    [],
                    $planner->forNode($plan, $node),
                    sprintf(
                        'UNE ENTRÉE NOUVELLE SERAIT ÉCRITE sur le nœud « %s » d\'une instance en place. '
                        . 'La dérivation sur-octroie : ce n\'est pas le référentiel figé qu\'il faut '
                        . 'régénérer, c\'est la règle qu\'il faut reprendre.',
                        $node->path,
                    ),
                );
            }
        } finally {
            ClassTreeShareService::enable();
            UserGroupUserPivotObserver::enableSync();
        }
    }
}
