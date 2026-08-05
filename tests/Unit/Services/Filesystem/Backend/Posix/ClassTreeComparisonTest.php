<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Models\DirectoryTemplate;
use App\Models\NetworkShare;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\Acl\AclFormat;
use App\Services\Filesystem\Backend\Posix\PosixAclCompiler;
use App\Services\Filesystem\ClassTreeShareService;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\ShareService;
use App\Services\Filesystem\TreePlanService;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.5 — **L'ÉPREUVE : les deux arbres, côte à côte.**
 *
 * Le seed est à la fois la livraison et le test. Si le langage de recette sait
 * exprimer le partage de classe historique, il est assez expressif ; sinon, c'est
 * le langage qui est faux, pas le seed.
 *
 * La forme de l'épreuve, depuis que les deux arbres COEXISTENT au lieu de se
 * succéder : **le diff entre l'arbre historique et l'arbre neuf, pour une même
 * classe, doit être EXACTEMENT l'ensemble documenté d'écarts attendus, et rien
 * d'autre.** Chaque écart attendu est nommé, justifié, et asservi par une égalité —
 * pas par une exclusion de la comparaison. Un écart que personne n'avait prévu fait
 * donc tomber ce test, et c'est toute sa valeur : si le langage ne savait pas dire
 * quelque chose, ça se verrait ici.
 *
 * ---------------------------------------------------------------------------
 * **L'ORACLE EST EXERCÉ, PAS RECOPIÉ.**
 *
 * L'état de l'arbre historique est obtenu en rejouant la SÉQUENCE EFFECTIVE du
 * service qui l'écrit — jeu canonique récursif, PUIS ajustements non récursifs —
 * et non en lisant ses constructeurs. Les constructeurs seuls ne donnent PAS
 * l'état final de la racine : c'est justement l'ajustement qui la façonne, et c'est
 * de lui que naît le seul écart documenté.
 *
 * ---------------------------------------------------------------------------
 * **L'UNIQUE ÉCART ATTENDU, mesuré sur instance réelle le 2026-08-05.**
 *
 * Sur la racine de la classe, et là seulement, l'ACL d'HÉRITAGE ne reflète pas
 * l'ACL d'ACCÈS :
 *
 *   accès    : équipe en lecture-traversée · classe en lecture-traversée
 *   héritage : équipe en écriture · AUCUNE entrée pour la classe
 *
 * C'est un ACCIDENT DE SÉQUENCE : le jeu canonique pose l'équipe en écriture avec
 * ses miroirs d'héritage, puis l'ajustement redescend l'accès sans toucher aux
 * miroirs. Inexprimable dans le vocabulaire du plan, où le miroir d'héritage est le
 * reflet de l'accès — et c'est très bien ainsi : c'est la forme SAINE, celle que
 * tous les autres nœuds de l'arbre historique ont déjà.
 *
 * Effet réel de l'écart : l'héritage ne concerne que des enfants créés à la racine
 * HORS plan, et seuls les administrateurs peuvent y créer. Il n'est donc ni
 * reproduit, ni corrigé : il est DOCUMENTÉ, et la décision se prendra à la
 * migration, les deux arbres sous les yeux.
 */
class ClassTreeComparisonTest extends TestCase
{
    use RefreshDatabase;

    private ShareService $legacy;

    private TreePlanService $plans;

    private PosixAclCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        // Aucune écriture disque : la sonde d'existence des groupes système est
        // le seul processus lancé par la chaîne neuve, et elle vit SOUS la ligne.
        //
        // La sonde est simulée par une entrée NOMMÉE, pas par un attrape-tout : un
        // test qui veut la faire échouer remplace cette entrée-là. Un simple
        // `Process::fake()` sans argument poserait un attrape-tout PRIORITAIRE, et
        // toute simulation d'échec ajoutée ensuite serait silencieusement ignorée —
        // le test passerait au vert en éprouvant le chemin heureux.
        Process::fake([
            'getent group *' => Process::result(),
            '*' => Process::result(),
        ]);
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        ClassTreeShareService::disable();

        (new DirectoryTemplateSeeder())->run();

        $this->legacy = app(ShareService::class);
        $this->plans = app(TreePlanService::class);
        $this->compiler = app(PosixAclCompiler::class);
    }

    protected function tearDown(): void
    {
        ClassTreeShareService::enable();
        UserGroupUserPivotObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    // =========================================================================
    // L'arbre HISTORIQUE, par REJEU de sa séquence
    // =========================================================================

    /**
     * Applique la sémantique d'un ajustement additif sur un jeu d'ACL : une entrée
     * de MÊME sujet est remplacée, une entrée nouvelle est ajoutée.
     *
     * C'est exactement ce que fait l'outil du système, et c'est de cette
     * sémantique-là que naît le sédiment : l'ajustement ne porte que sur les
     * entrées d'ACCÈS, les miroirs d'héritage restent ceux du jeu canonique.
     *
     * @param  list<string>  $acls
     * @param  list<string>  $adjustments
     * @return list<string>
     */
    private function applyAdjustment(array $acls, array $adjustments): array
    {
        foreach ($adjustments as $line) {
            $subject = substr($line, 0, (int) strrpos($line, ':'));

            $replaced = false;
            foreach ($acls as $index => $existing) {
                if (substr($existing, 0, (int) strrpos($existing, ':')) === $subject) {
                    $acls[$index] = $line;
                    $replaced = true;
                    break;
                }
            }

            if (! $replaced) {
                $acls[] = $line;
            }
        }

        return array_values($acls);
    }

    /**
     * L'état FINAL de l'arbre historique, nœud par nœud, en rejouant la séquence
     * effective de la création du partage de classe (et de la bascule de l'espace
     * d'échange).
     *
     * @param  list<string>  $logins
     * @return array<string, list<string>> chemin de nœud => jeu d'ACL canonique
     */
    private function legacyTree(UserGroup $group, array $logins, bool $echangeActive = true): array
    {
        $local = $this->legacy->aclGroupLocalPart($group);
        self::assertIsString($local, 'le nom de la classe doit être dérivable');

        $tree = [
            // Racine : jeu canonique récursif PUIS ajustement additif non récursif.
            // Les deux gestes, dans cet ordre — c'est la séquence, pas le
            // constructeur, qui donne l'état final.
            '.' => $this->applyAdjustment(
                $this->legacy->buildCanonicalAcls($local),
                $this->legacy->buildRootRwToRxAdjustment($local),
            ),
            '_travail' => $this->legacy->buildTravailAcls($local),
            '_travail/devoirs' => $this->legacy->buildTravailAcls($local),
            '_profs' => $this->legacy->buildProfsAcls($local),
            '_echange' => $this->legacy->buildEchangeAcls($local, $echangeActive),
        ];

        foreach ($logins as $login) {
            $tree[$login] = $this->legacy->buildEleveAcls($login, $local);
        }

        return array_map(static fn (array $acls): array => AclFormat::normalizeSet($acls), $tree);
    }

    // =========================================================================
    // L'arbre NEUF, par la chaîne complète
    // =========================================================================

    /**
     * @return array<string, list<string>> chemin de nœud => jeu d'ACL canonique
     */
    private function compiledTree(FilePlan $plan): array
    {
        $tree = [];

        foreach ($plan->nodes as $node) {
            $compiled = $this->compiler->compile($node);

            self::assertSame(
                [],
                $compiled->refusalDetails(),
                sprintf('le nœud « %s » a été refusé par la compilation : %s', $node->path, implode(' ', $compiled->refusalDetails())),
            );

            $tree[$node->path] = AclFormat::normalizeSet($compiled->acls);
        }

        return $tree;
    }

    /**
     * Le DIFF complet des deux arbres, nœud par nœud, en forme canonique.
     *
     * L'ordre d'émission n'est pas le critère — le JEU l'est. Un nœud présent d'un
     * seul côté est un écart au même titre qu'une entrée : le taire ferait passer
     * une amputation d'arbre pour une convergence.
     *
     * @param  array<string, list<string>>  $legacy
     * @param  array<string, list<string>>  $neuf
     * @return array<string, array<string, list<string>>>
     */
    private function diff(array $legacy, array $neuf): array
    {
        $paths = array_values(array_unique([...array_keys($legacy), ...array_keys($neuf)]));
        sort($paths);

        $diff = [];
        foreach ($paths as $path) {
            $left = $legacy[$path] ?? null;
            $right = $neuf[$path] ?? null;

            if ($left === null) {
                $diff[$path] = ['nœud absent de l\'arbre historique' => $right ?? []];

                continue;
            }
            if ($right === null) {
                $diff[$path] = ['nœud absent de l\'arbre neuf' => $left];

                continue;
            }

            $onlyLegacy = array_values(array_diff($left, $right));
            $onlyNew = array_values(array_diff($right, $left));

            if ($onlyLegacy === [] && $onlyNew === []) {
                continue;
            }

            $entry = [];
            if ($onlyLegacy !== []) {
                $entry['seulement dans l\'historique'] = $onlyLegacy;
            }
            if ($onlyNew !== []) {
                $entry['seulement dans le neuf'] = $onlyNew;
            }
            $diff[$path] = $entry;
        }

        return $diff;
    }

    // =========================================================================
    // Décor
    // =========================================================================

    /** @param array<string, string> $members login => rôle d'arête */
    private function classGroup(string $name, array $members = [], ?string $adDn = null): UserGroup
    {
        $group = UserGroup::create(['name' => $name, 'type' => 'classe', 'ad_dn' => $adDn]);

        foreach ($members as $login => $role) {
            $user = User::factory()->create(['login' => $login, 'source' => 'ad']);
            $group->users()->attach($user->id, ['role' => $role]);
        }

        return $group->fresh(['users']);
    }

    private function planOf(UserGroup $group, array $nodeActivation = []): FilePlan
    {
        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();

        return $this->plans->planUsing($group, $template, [], $nodeActivation);
    }

    // =========================================================================
    // L'AC DUR
    // =========================================================================

    /**
     * **LE test pivot.** Diff global == l'unique écart documenté, sur la racine
     * seule.
     */
    #[Test]
    public function the_whole_diff_of_the_two_trees_is_exactly_the_documented_root_sediment(): void
    {
        $group = $this->classGroup('Classe_3emeA', [
            'alecoz' => 'manager',
            'bmartin' => 'member',
            'cpetit' => 'member',
            'ddurand' => 'owner',
        ]);

        $plan = $this->planOf($group);

        // Les élèves — et EUX SEULS — ont un dossier personnel : c'est le rôle
        // d'arête qui l'ouvre, pas l'appartenance.
        $eleves = ['bmartin', 'cpetit'];

        $diff = $this->diff($this->legacyTree($group, $eleves), $this->compiledTree($plan));

        self::assertSame(
            [
                '.' => [
                    // Le sédiment : l'héritage de l'équipe est resté en écriture,
                    // vestige du jeu canonique que l'ajustement n'a pas touché.
                    'seulement dans l\'historique' => [
                        'default:group:equipe_3emea:rwx',
                    ],
                    // Et l'arbre neuf, lui, dit la même chose en accès et en
                    // héritage — la forme saine, celle de tous les autres nœuds.
                    'seulement dans le neuf' => [
                        'default:group:classe_3emea:r-x',
                        'default:group:equipe_3emea:r-x',
                    ],
                ],
            ],
            $diff,
            "LE DIFF DES DEUX ARBRES A CHANGÉ. Un écart NON DOCUMENTÉ signifie que le langage de recette "
            . "n'exprime pas ce que l'arbre historique fait — c'est le langage qu'il faut interroger, "
            . "JAMAIS l'ensemble d'écarts attendus qu'il faudrait élargir pour faire passer ce test. "
            . 'Diff observé : ' . json_encode($diff, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /** Le même diff, sur une classe dont l'espace d'échange est SUSPENDU. */
    #[Test]
    public function the_diff_stays_the_same_when_the_exchange_space_is_suspended(): void
    {
        $group = $this->classGroup('Classe_3emeA', ['alecoz' => 'manager', 'bmartin' => 'member']);

        $plan = $this->planOf($group, ['_echange' => false]);

        $diff = $this->diff(
            $this->legacyTree($group, ['bmartin'], echangeActive: false),
            $this->compiledTree($plan),
        );

        self::assertSame(['.'], array_keys($diff), 'suspendre l\'échange ne doit ouvrir AUCUN écart nouveau');
    }

    /**
     * Le même diff, sur une classe au nom NU dont le DN porte une unité
     * d'organisation d'établissement — le cas fédéré, celui du suffixe.
     */
    #[Test]
    public function the_diff_stays_the_same_on_a_federated_establishment_class(): void
    {
        $group = $this->classGroup(
            '3sb',
            ['pdupont' => 'manager', 'eleve01' => 'member'],
            'CN=3sb,OU=0991229y,OU=Etablissements,DC=lab1,DC=irundo,DC=fr',
        );

        $diff = $this->diff(
            $this->legacyTree($group, ['eleve01']),
            $this->compiledTree($this->planOf($group)),
        );

        self::assertSame(
            [
                '.' => [
                    'seulement dans l\'historique' => ['default:group:equipe_3sb-1229y:rwx'],
                    'seulement dans le neuf' => [
                        'default:group:classe_3sb-1229y:r-x',
                        'default:group:equipe_3sb-1229y:r-x',
                    ],
                ],
            ],
            $diff,
        );
    }

    // =========================================================================
    // Les détails DUREMENT ACQUIS, chacun avec son assertion en littéraux
    // =========================================================================

    /**
     * Le groupe d'administration d'annuaire porte un ESPACE, et il est échappé.
     * C'est la seule entrée du jeu de base dont l'écriture littérale peut casser.
     */
    #[Test]
    public function the_directory_admin_group_is_escaped_in_both_trees(): void
    {
        $group = $this->classGroup('Classe_3emeA', ['bmartin' => 'member']);

        $neuf = $this->compiledTree($this->planOf($group));
        $legacy = $this->legacyTree($group, ['bmartin']);

        foreach (['.', '_travail', '_profs', '_echange', 'bmartin'] as $path) {
            self::assertContains('group:domain\040admins:rwx', $neuf[$path], "arbre neuf, nœud « {$path} »");
            self::assertContains('default:group:domain\040admins:rwx', $neuf[$path], "arbre neuf, nœud « {$path} »");
            self::assertContains('group:domain\040admins:rwx', $legacy[$path], "arbre historique, nœud « {$path} »");
        }
    }

    /**
     * Le CHEMIN garde sa casse, les GROUPES sont en minuscules, et le préfixe n'est
     * jamais doublé. Trois pièges d'un seul nom, en une seule assertion littérale.
     */
    #[Test]
    public function the_path_keeps_its_case_while_the_groups_go_lowercase_without_doubling_the_prefix(): void
    {
        $group = $this->classGroup('Classe_3emeA', ['bmartin' => 'member']);
        $plan = $this->planOf($group);

        self::assertSame('Classe_3emeA', $plan->rootPath);

        $racine = $this->compiledTree($plan)['.'];

        self::assertContains('group:classe_3emea:r-x', $racine);
        self::assertContains('group:equipe_3emea:r-x', $racine);
        self::assertNotContains('group:classe_classe_3emea:r-x', $racine, 'le préfixe a été doublé');

        foreach ($racine as $line) {
            self::assertStringNotContainsString(
                'classe_Classe',
                $line,
                'un nom de groupe système ne doit jamais porter la casse du chemin',
            );
        }
    }

    /**
     * Le suffixe d'établissement est COMPILÉ, pas espéré : c'est la dette mesurée
     * de l'incident du groupe d'équipe sans suffixe.
     */
    #[Test]
    public function the_establishment_suffix_reaches_the_compiled_entries(): void
    {
        $group = $this->classGroup(
            '3sb',
            ['pdupont' => 'manager'],
            'CN=3sb,OU=0991229y,OU=Etablissements,DC=lab1,DC=irundo,DC=fr',
        );

        $travail = $this->compiledTree($this->planOf($group))['_travail'];

        self::assertContains('group:equipe_3sb-1229y:rwx', $travail);
        self::assertContains('group:classe_3sb-1229y:r-x', $travail);
        self::assertNotContains('group:equipe_3sb:rwx', $travail, 'le suffixe d\'établissement a été perdu');
    }

    /**
     * **Le dossier des devoirs est en LECTURE pour les élèves**, exactement comme
     * l'espace de travail. Ce n'est PAS une boîte de dépôt : la collecte des copies
     * rendues n'est pas livrée, et l'écrire en dépôt serait une régression
     * fonctionnelle déguisée en amélioration.
     */
    #[Test]
    public function the_homework_folder_is_a_read_only_distribution_not_a_drop_box(): void
    {
        $group = $this->classGroup('Classe_3emeA', ['bmartin' => 'member']);
        $neuf = $this->compiledTree($this->planOf($group));

        self::assertSame($neuf['_travail'], $neuf['_travail/devoirs']);
        self::assertContains('group:classe_3emea:r-x', $neuf['_travail/devoirs']);
        self::assertNotContains('group:classe_3emea:rwx', $neuf['_travail/devoirs']);
    }

    /**
     * L'espace privé des enseignants : la classe n'y a AUCUNE entrée. La clôture est
     * CALCULÉE — elle ne s'écrit pas en refus, elle se constate en absence.
     */
    #[Test]
    public function the_teachers_space_carries_no_entry_at_all_for_the_class(): void
    {
        $group = $this->classGroup('Classe_3emeA', ['bmartin' => 'member']);
        $profs = $this->compiledTree($this->planOf($group))['_profs'];

        foreach ($profs as $line) {
            self::assertStringNotContainsString('classe_3emea', $line, 'la clôture ne doit produire AUCUNE entrée');
        }
        self::assertContains('group:equipe_3emea:rwx', $profs);

        // Et le plan le DIT, plutôt que de le laisser deviner.
        $node = $this->planOf($group)->node('_profs');
        self::assertNotNull($node);
        self::assertSame(['classe'], $node->closure);
    }

    /**
     * L'espace d'échange naît ACTIF, et sa suspension vide l'entrée de la classe
     * SANS retirer quoi que ce soit d'autre — le dossier et les données restent.
     */
    #[Test]
    public function the_exchange_space_is_born_active_and_suspending_it_empties_the_entry(): void
    {
        $group = $this->classGroup('Classe_3emeA', ['bmartin' => 'member']);

        $actif = $this->compiledTree($this->planOf($group))['_echange'];
        self::assertContains('group:classe_3emea:rwx', $actif);
        self::assertContains('default:group:classe_3emea:rwx', $actif);

        $inactif = $this->compiledTree($this->planOf($group, ['_echange' => false]))['_echange'];
        self::assertContains('group:classe_3emea:---', $inactif);
        self::assertContains('default:group:classe_3emea:---', $inactif);
        // L'équipe, elle, n'est pas suspendable : elle garde son accès.
        self::assertContains('group:equipe_3emea:rwx', $inactif);
        self::assertSame(count($actif), count($inactif), 'suspendre ne retire AUCUNE entrée');
    }

    /**
     * Le dossier personnel : une entrée nominative, plus l'équipe. Une seule
     * personne par nœud — jamais une audience.
     */
    #[Test]
    public function a_personal_folder_carries_exactly_one_named_entry_plus_the_teaching_team(): void
    {
        $group = $this->classGroup('Classe_3emeA', ['bmartin' => 'member', 'cpetit' => 'member']);
        $neuf = $this->compiledTree($this->planOf($group));

        self::assertContains('user:bmartin:rwx', $neuf['bmartin']);
        self::assertContains('default:user:bmartin:rwx', $neuf['bmartin']);
        self::assertContains('group:equipe_3emea:rwx', $neuf['bmartin']);
        self::assertNotContains('user:cpetit:rwx', $neuf['bmartin'], 'un dossier personnel n\'est pas partagé');

        // `user::rwx` est l'entrée de base du propriétaire, pas une entrée
        // NOMINATIVE : la compter ferait dire au test le contraire de ce qu'il
        // vérifie.
        $nominatives = array_filter(
            $neuf['bmartin'],
            static fn (string $line): bool => str_starts_with($line, 'user:') && ! str_starts_with($line, 'user::'),
        );
        self::assertCount(1, $nominatives);
    }

    /**
     * Le rôle « équipe » compile en UN SEUL sujet d'audience.
     *
     * C'est l'assertion qui protège l'arbitrage : lister aussi le rôle de
     * propriétaire d'arête émettrait une entrée supplémentaire, jamais éprouvée sur
     * instance réelle, qui ferait tomber le diff. Le critère dur ne dépend donc
     * d'aucun groupe d'annuaire non validé.
     */
    #[Test]
    public function the_teaching_team_role_emits_exactly_one_audience_subject(): void
    {
        $group = $this->classGroup('Classe_3emeA', ['alecoz' => 'manager', 'ddurand' => 'owner']);
        $plan = $this->planOf($group);

        $subjects = $plan->roles['equipe'];
        self::assertCount(1, $subjects);
        self::assertSame('manager', (string) $subjects[0]->edgeRole);

        foreach ($this->compiledTree($plan) as $path => $acls) {
            foreach ($acls as $line) {
                self::assertStringNotContainsString('pp_', $line, "un sujet de professeur principal a été émis sur « {$path} »");
            }
        }
    }

    /**
     * Une classe SANS ÉLÈVE donne un arbre parfaitement valide : cinq nœuds fixes,
     * aucun dossier personnel. Zéro membre n'est pas une erreur.
     */
    #[Test]
    public function a_class_without_any_student_still_yields_a_valid_tree(): void
    {
        $group = $this->classGroup('Classe_Vide');
        $plan = $this->planOf($group);

        self::assertSame(['.', '_echange', '_profs', '_travail', '_travail/devoirs'], $plan->nodePaths());
        self::assertSame([], $this->diff($this->legacyTree($group, []), $this->compiledTree($plan))['_travail'] ?? []);
    }

    // =========================================================================
    // AC8 — ce qui se passe QUAND ÇA SE PASSE MAL
    // =========================================================================

    /**
     * Groupe d'annuaire introuvable : l'octroi n'est PAS écrit, l'échec NOMME le
     * groupe attendu, et **rien n'est purgé**. C'est le pré-contrôle de l'arbre
     * historique, tenu à l'identique par la chaîne neuve.
     */
    #[Test]
    public function an_unresolvable_directory_group_declines_by_name_and_writes_nothing(): void
    {
        Process::fake([
            'getent group *' => Process::result(output: '', errorOutput: '', exitCode: 2),
        ]);

        $group = $this->classGroup('Classe_Dechet', ['bmartin' => 'member']);
        $node = $this->planOf($group)->node('_travail');
        self::assertNotNull($node);

        $compiled = $this->compiler->compile($node);

        $details = implode(' ', $compiled->refusalDetails());
        self::assertStringContainsString('equipe_dechet', $details, 'le nom attendu doit figurer : c\'est ce qui rend l\'incident réparable');
        self::assertStringContainsString('classe_dechet', $details);

        foreach ($compiled->acls as $line) {
            self::assertStringNotContainsString('equipe_dechet', $line, 'aucune entrée ne doit être écrite pour un groupe introuvable');
            self::assertStringNotContainsString('classe_dechet', $line);
        }
    }

    /**
     * **Le plafond nominatif est VÉRIFIÉ, pas affirmé.** Une classe de 250 élèves
     * compile sans refus d'échelle : chaque nœud par membre porte UNE entrée
     * nominative, les nœuds partagés zéro. Le garde-fou vise l'énumération d'une
     * audience sur un même nœud, pas l'effectif d'une classe.
     */
    #[Test]
    public function a_class_of_two_hundred_and_fifty_members_compiles_without_any_scale_refusal(): void
    {
        $group = UserGroup::create(['name' => 'Classe_Massive', 'type' => 'classe']);

        $users = User::factory()->count(250)->sequence(
            fn ($sequence): array => ['login' => sprintf('eleve%03d', $sequence->index), 'source' => 'ad'],
        )->create();
        foreach ($users as $user) {
            $group->users()->attach($user->id, ['role' => 'member']);
        }

        $plan = $this->planOf($group->fresh(['users']));
        self::assertCount(5 + 250, $plan->nodes);

        foreach ($plan->nodes as $node) {
            $compiled = $this->compiler->compile($node);

            self::assertSame([], $compiled->refusalDetails(), "refus d'échelle sur « {$node->path} »");

            $nominatives = count(array_filter(
                $compiled->acls,
                static fn (string $line): bool => str_starts_with($line, 'user:') && $line !== 'user::rwx',
            ));
            self::assertLessThanOrEqual(
                1,
                $nominatives,
                sprintf('le nœud « %s » porte %d entrées nominatives : c\'est une audience énumérée', $node->path, $nominatives),
            );
        }
    }

    // =========================================================================
    // Le chemin RÉEL, et l'arbre auquel on n'écrit pas
    // =========================================================================

    /**
     * Le partage d'arbre matérialisé vit dans la racine NEUVE, et son chemin réel
     * ne touche jamais l'arbre historique.
     */
    #[Test]
    public function the_materialized_tree_lives_in_the_new_root_never_in_the_legacy_one(): void
    {
        config([
            'filesystem.shares_root' => '/var/sambaedu/Partages',
            'filesystem.class_trees_root' => '/var/sambaedu/ClassesSE5',
        ]);

        $group = $this->classGroup('Classe_3emeA', ['bmartin' => 'member']);
        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();

        ClassTreeShareService::enable();
        $share = app(ClassTreeShareService::class)->ensureShare($group, $template);
        ClassTreeShareService::disable();

        self::assertInstanceOf(NetworkShare::class, $share);
        self::assertSame('Classe_3emeA', $share->directory_name);

        $guard = app(\App\Services\Filesystem\Backend\Posix\PosixPathGuard::class);
        $plan = app(\App\Services\Filesystem\NetworkShareService::class)->planFor($share->fresh());

        $root = $guard->planRoot($plan);
        self::assertSame('/var/sambaedu/ClassesSE5/Classe_3emeA', $root);

        foreach ($plan->nodePaths() as $path) {
            $resolved = $guard->resolve($plan, $path);
            self::assertIsString($resolved);
            self::assertStringStartsWith('/var/sambaedu/ClassesSE5/', $resolved);
            self::assertFalse(
                str_starts_with($resolved, '/var/sambaedu/Classes/'),
                "le nœud « {$path} » a produit un chemin dans l'arbre HISTORIQUE : {$resolved}",
            );
        }
    }
}
