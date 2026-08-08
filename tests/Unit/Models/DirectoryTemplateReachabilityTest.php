<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Exceptions\Filesystem\InvalidTreeSpecException;
use App\Models\DirectoryTemplate;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\PlanGrant;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\ClassTreeRecipe;

/**
 * Story 62.5 — **UNE RECETTE QUI DÉCRIT UN DOSSIER OÙ PERSONNE N'ARRIVE EST
 * REFUSÉE.**
 *
 * Le compilateur travaille nœud par nœud : il ne voit jamais l'arbre. Une recette
 * peut donc être irréprochable nœud à nœud et produire un mirage. Le couloir
 * d'accès dérivé répare le cas des AUDIENCES ; ces quatre règles ferment le reste,
 * à l'écriture, en nommant le chemin fautif.
 *
 * Chaque règle a son refus ET son passage : une règle qui ne saurait que refuser
 * serait indiscernable d'une règle trop large.
 */
class DirectoryTemplateReachabilityTest extends TestCase
{
    use ClassTreeRecipe;
    use RefreshDatabase;

    /**
     * Une recette d'arbre à deux rôles, dont la règle de résolution est PARAMÉTRABLE
     * — c'est elle que la quatrième règle interroge.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, mixed>|null  $audienceResolution
     */
    private function template(array $nodes, ?array $audienceResolution = ['strategy' => 'self']): DirectoryTemplate
    {
        $audience = [
            'key' => 'audience',
            'label' => 'Audience',
            'maille' => UserGroup::class,
            'group_type' => 'classe',
            'verbs' => [PlanGrant::VERB_LIRE],
            'cardinality' => 'one',
        ];
        if ($audienceResolution !== null) {
            $audience['resolution'] = $audienceResolution;
        }

        return new DirectoryTemplate([
            'key' => 'sonde_atteignabilite',
            'label' => 'Sonde',
            'roles_spec' => [$audience],
            'path_pattern' => 'Classe_{group.bare_name}',
            'nodes_spec' => $nodes,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function node(array $overrides = []): array
    {
        return array_merge([
            'path' => '_travail',
            'label' => 'Travail',
            'nature' => 'partagee',
            'grants' => [['role' => 'audience', 'verbs' => [PlanGrant::VERB_LIRE]]],
        ], $overrides);
    }

    private function assertRejected(DirectoryTemplate $template, string ...$fragments): void
    {
        try {
            $template->assertValidTreeSpec();
        } catch (InvalidTreeSpecException $e) {
            foreach ($fragments as $fragment) {
                self::assertStringContainsString($fragment, $e->getMessage());
            }

            return;
        }

        self::fail('la recette aurait dû être refusée');
    }

    private function assertAccepted(DirectoryTemplate $template): void
    {
        $template->assertValidTreeSpec();
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // RÈGLE 1 — l'ancêtre doit être déclaré
    // =========================================================================

    #[Test]
    public function a_node_whose_ancestor_is_not_declared_is_refused_by_name(): void
    {
        $this->assertRejected(
            $this->template([
                $this->node(),
                $this->node(['path' => '_travail/devoirs/copies', 'label' => 'Copies']),
            ]),
            'inatteignable',
            '_travail/devoirs/copies',
            '_travail/devoirs',
        );
    }

    #[Test]
    public function the_same_tree_with_every_ancestor_declared_is_accepted(): void
    {
        $this->assertAccepted($this->template([
            $this->node(),
            $this->node(['path' => '_travail/devoirs', 'label' => 'Devoirs']),
            $this->node(['path' => '_travail/devoirs/copies', 'label' => 'Copies']),
        ]));
    }

    /**
     * **LE NŒUD RACINE N'EST PAS EXIGÉ**, et ce n'est pas un oubli : c'est l'état
     * livré par la story 60.5, et les décors de test purs du plan en dépendent. La
     * règle ne porte que sur les préfixes STRICTS.
     */
    #[Test]
    public function a_tree_that_never_declares_its_root_stays_valid(): void
    {
        $this->assertAccepted($this->template([
            $this->node(),
            $this->node(['path' => '_travail/devoirs', 'label' => 'Devoirs']),
        ]));

        // Et le décor réel des tests purs du plan, qui n'a pas de racine non plus.
        $this->assertAccepted($this->classTreeTemplate());
    }

    // =========================================================================
    // RÈGLE 2 — rien sous un contenu libre
    // =========================================================================

    #[Test]
    public function a_node_declared_under_a_free_content_node_is_refused(): void
    {
        $this->assertRejected(
            $this->template([
                $this->node(['nature' => 'contenu_libre']),
                $this->node(['path' => '_travail/devoirs', 'label' => 'Devoirs']),
            ]),
            '_travail/devoirs',
            'n\'est pas gouverné par le plan',
        );
    }

    /**
     * Un nœud à contenu libre en FEUILLE reste parfaitement valide — c'est la forme
     * de la recette livrée (`_travail/devoirs`), et la règle ne doit pas y toucher.
     */
    #[Test]
    public function a_free_content_node_without_declared_children_stays_valid(): void
    {
        $this->assertAccepted($this->template([
            $this->node(),
            $this->node(['path' => '_travail/devoirs', 'label' => 'Devoirs', 'nature' => 'contenu_libre']),
        ]));
    }

    // =========================================================================
    // RÈGLE 3 — deux énumérations qui ne parlent pas des mêmes personnes
    // =========================================================================

    #[Test]
    public function two_nested_per_member_nodes_targeting_different_edge_roles_are_refused(): void
    {
        $this->assertRejected(
            $this->template([
                $this->node([
                    'path' => '{member.login}',
                    'label' => 'Dossier personnel',
                    'nature' => 'par_membre',
                    'edge_role' => 'member',
                    'grants' => [['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'verbs' => PlanGrant::VERBS]],
                ]),
                $this->node([
                    'path' => '{member.login}/{member.login}',
                    'label' => 'Sous-dossier',
                    'nature' => 'par_membre',
                    'edge_role' => 'manager',
                    'grants' => [['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'verbs' => PlanGrant::VERBS]],
                ]),
            ]),
            'n\'existe pas pour ces personnes-là',
            'manager',
            'member',
        );
    }

    /**
     * **ÉPINGLÉ plutôt que cru** : un nœud NON par membre sous un nœud par membre est
     * déjà impossible par construction — son chemin porterait le jeton du membre, qui
     * est interdit hors d'un nœud par membre. La règle 3 n'a donc pas à s'en occuper,
     * et ce test le prouve au lieu de l'affirmer.
     */
    #[Test]
    public function a_shared_node_under_a_per_member_node_was_already_impossible(): void
    {
        $this->assertRejected(
            $this->template([
                $this->node([
                    'path' => '{member.login}',
                    'label' => 'Dossier personnel',
                    'nature' => 'par_membre',
                    'edge_role' => 'member',
                    'grants' => [['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'verbs' => PlanGrant::VERBS]],
                ]),
                $this->node(['path' => '{member.login}/commun', 'label' => 'Commun']),
            ]),
            'placeholder inconnu',
        );
    }

    // =========================================================================
    // RÈGLE 4 — la couverture des membres énumérés
    // =========================================================================

    /**
     * **LA CONTREPARTIE STATIQUE DU « LE NOMINATIF NE DÉRIVE PAS ».** Sans audience
     * couvrante sur la racine, chaque élève aurait un dossier personnel dont il ne
     * franchirait jamais la porte d'entrée — et la dérivation refuse de le rattraper
     * en posant une entrée par personne.
     */
    #[Test]
    public function a_per_member_node_whose_ancestor_covers_nobody_is_refused(): void
    {
        $this->assertRejected(
            $this->template(
                [
                    $this->node(['path' => '.', 'label' => 'Racine']),
                    $this->node([
                        'path' => '{member.login}',
                        'label' => 'Dossier personnel',
                        'nature' => 'par_membre',
                        'edge_role' => 'member',
                        'grants' => [['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'verbs' => PlanGrant::VERBS]],
                    ]),
                ],
                // Une cible désignée à la matérialisation ne dit RIEN de qui en fait
                // partie : elle ne peut pas garantir l'atteignabilité.
                audienceResolution: null,
            ),
            'inatteignable',
            '{member.login}',
            'member',
        );
    }

    /** Le groupe LUI-MÊME couvre tous ses membres, quel que soit leur rôle d'arête. */
    #[Test]
    public function an_ancestor_granting_the_group_itself_covers_every_edge_role(): void
    {
        foreach (['member', 'manager', 'owner'] as $edgeRole) {
            $this->assertAccepted($this->template([
                $this->node(['path' => '.', 'label' => 'Racine']),
                $this->node([
                    'path' => '{member.login}',
                    'label' => 'Dossier personnel',
                    'nature' => 'par_membre',
                    'edge_role' => $edgeRole,
                    'grants' => [['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'verbs' => PlanGrant::VERBS]],
                ]),
            ]));
        }
    }

    /** Une audience d'ARÊTE ne couvre que le rôle qu'elle liste — pas les autres. */
    #[Test]
    public function an_edge_role_audience_covers_its_own_role_and_only_it(): void
    {
        $perMember = static fn (string $edgeRole): array => [
            'path' => '{member.login}',
            'label' => 'Dossier personnel',
            'nature' => 'par_membre',
            'edge_role' => $edgeRole,
            'grants' => [['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'verbs' => PlanGrant::VERBS]],
        ];

        $this->assertAccepted($this->template(
            [$this->node(['path' => '.', 'label' => 'Racine']), $perMember('manager')],
            audienceResolution: ['strategy' => 'edge_role', 'edge_roles' => ['manager']],
        ));

        $this->assertRejected(
            $this->template(
                [$this->node(['path' => '.', 'label' => 'Racine']), $perMember('member')],
                audienceResolution: ['strategy' => 'edge_role', 'edge_roles' => ['manager']],
            ),
            'inatteignable',
            'member',
        );
    }

    /**
     * La couverture est exigée sur CHAQUE ancêtre déclaré, pas seulement le plus
     * proche : un couloir manquant au milieu suffit à rendre le dossier inatteignable.
     */
    #[Test]
    public function the_coverage_is_required_on_every_declared_ancestor(): void
    {
        $this->assertRejected(
            $this->template([
                $this->node(['path' => '.', 'label' => 'Racine']),
                $this->node(['path' => 'eleves', 'label' => 'Élèves', 'grants' => []]),
                $this->node([
                    'path' => 'eleves/{member.login}',
                    'label' => 'Dossier personnel',
                    'nature' => 'par_membre',
                    'edge_role' => 'member',
                    'grants' => [['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'verbs' => PlanGrant::VERBS]],
                ]),
            ]),
            'eleves/{member.login}',
            '« eleves »',
        );
    }

    // =========================================================================
    // Non-régression : l'existant reste valide, et les messages d'hier survivent
    // =========================================================================

    /**
     * **LES CINQ RECETTES LIVRÉES RESTENT VALIDES, SANS UNE MODIFICATION.** C'est
     * l'oracle de calibrage des quatre règles : elles ont été écrites pour attraper
     * ce qui n'existe pas encore, jamais pour condamner ce qui tourne.
     */
    #[Test]
    public function the_five_seeded_recipes_stay_valid_without_a_single_change(): void
    {
        (new DirectoryTemplateSeeder())->run();

        $templates = DirectoryTemplate::orderBy('key')->get();
        self::assertCount(5, $templates);

        foreach ($templates as $template) {
            $template->assertValidTreeSpec();
        }
        $this->addToAssertionCount(1);
    }

    /**
     * **L'ORDRE DES RÈGLES NE VOLE AUCUN MESSAGE.** Un nœud partagé nommé
     * `depots/{member.login}` doit s'entendre dire que le jeton du membre n'a rien à
     * faire là — pas que son ancêtre « depots » n'est pas déclaré. Le second message
     * est vrai, il est simplement moins utile.
     */
    #[Test]
    public function the_per_node_validations_keep_speaking_first(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['path' => 'depots/{member.login}', 'label' => 'Dépôt'])]),
            'placeholder inconnu',
        );
    }
}
