<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Enums\PlanAnchor;
use App\Enums\RoleResolutionStrategy;
use App\Models\DirectoryTemplate;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\ClassTreeRecipe;

/**
 * Story 34.3 → 60.5 — catalogue des recettes et idempotence du seeder (Q3 option B).
 *
 * La story 60.5 ajoute la 5ᵉ recette (l'ARBRE de partage de classe) et RÉPARE
 * « profs → élèves ». Les trois autres ne doivent pas bouger d'un octet, et c'est
 * épinglé ci-dessous plutôt que promis.
 */
class DirectoryTemplateSeederTest extends TestCase
{
    use ClassTreeRecipe;
    use RefreshDatabase;

    #[Test]
    public function seeds_exactly_the_five_expected_recipes(): void
    {
        (new DirectoryTemplateSeeder())->run();

        $this->assertSame(5, DirectoryTemplate::count());

        foreach ([
            DirectoryTemplate::KEY_DIRECTION_TO_ALL,
            DirectoryTemplate::KEY_PROFS_TO_ELEVES,
            DirectoryTemplate::KEY_USER_TO_USER,
            DirectoryTemplate::KEY_GROUP_SPACE,
            DirectoryTemplate::KEY_CLASSE_SE4,
        ] as $key) {
            $this->assertDatabaseHas('directory_templates', ['key' => $key]);
        }
    }

    #[Test]
    public function eleves_to_profs_template_is_not_seeded(): void
    {
        // Q1 — casiers « élèves → profs » REPORTÉ à 34.x : pas de recette livrée.
        (new DirectoryTemplateSeeder())->run();

        $this->assertDatabaseMissing('directory_templates', ['key' => 'eleves_to_profs']);
        $this->assertDatabaseMissing('directory_templates', ['key' => 'rendus']);
    }

    #[Test]
    public function every_recipe_respects_the_mount_only_invariant(): void
    {
        (new DirectoryTemplateSeeder())->run();

        foreach (DirectoryTemplate::all() as $tpl) {
            $this->assertTrue(
                $tpl->respectsMountOnlyInvariant(),
                "La recette {$tpl->key} ne doit grant aucune ACL sur un WorkstationGroup.",
            );
            $this->assertNotEmpty($tpl->roles());
        }
    }

    #[Test]
    public function db_contains_only_the_canonical_keys_after_seed(): void
    {
        // M-2 (review opus) — garde-fou anti-dérive : le sélecteur UI lit
        // `DirectoryTemplate::all()` sans filtre, donc toute clé orpheline
        // apparaîtrait. On épingle l'ensemble EXACT des clés en DB ; ce test
        // casse si le catalogue dérive (recette ajoutée/retirée non intentionnelle).
        (new DirectoryTemplateSeeder())->run();

        $expected = [
            DirectoryTemplate::KEY_DIRECTION_TO_ALL,
            DirectoryTemplate::KEY_PROFS_TO_ELEVES,
            DirectoryTemplate::KEY_USER_TO_USER,
            DirectoryTemplate::KEY_GROUP_SPACE,
            DirectoryTemplate::KEY_CLASSE_SE4,
        ];
        sort($expected);

        $actual = DirectoryTemplate::pluck('key')->all();
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function reseed_is_idempotent_and_does_not_duplicate(): void
    {
        $first = (new DirectoryTemplateSeeder())->run();
        $this->assertSame(5, $first['created']);
        $this->assertSame(0, $first['updated']);

        $second = (new DirectoryTemplateSeeder())->run();
        $this->assertSame(0, $second['created']);
        $this->assertSame(5, $second['updated']);

        $this->assertSame(5, DirectoryTemplate::count());
    }

    // =========================================================================
    // Story 60.5 — ce qui change, et surtout ce qui ne change PAS
    // =========================================================================

    /**
     * **Les TROIS recettes hors périmètre sont inchangées, octet pour octet.**
     *
     * Le référentiel est figé ici en littéraux : recalculer l'attendu depuis le
     * seeder ferait passer au vert n'importe quelle dérive, puisque les deux côtés
     * bougeraient ensemble.
     */
    #[Test]
    public function the_three_untouched_recipes_keep_their_exact_specification(): void
    {
        (new DirectoryTemplateSeeder())->run();

        $expected = [
            DirectoryTemplate::KEY_DIRECTION_TO_ALL => [
                ['source', UserGroup::class, null, 'rw', 'one'],
                ['destinataires', UserGroup::class, null, 'ro', 'many'],
            ],
            DirectoryTemplate::KEY_USER_TO_USER => [
                ['user_a', User::class, null, 'rw', 'one'],
                ['user_b', User::class, null, 'rw', 'one'],
            ],
            DirectoryTemplate::KEY_GROUP_SPACE => [
                ['group', UserGroup::class, null, 'rw', 'one'],
            ],
        ];

        foreach ($expected as $key => $roles) {
            $template = DirectoryTemplate::where('key', $key)->firstOrFail();

            $this->assertNull($template->path_pattern, "{$key} ne doit porter aucun arbre");
            $this->assertNull($template->nodes_spec, "{$key} ne doit porter aucun nœud");
            $this->assertNull($template->attached_group_type, "{$key} ne doit être accrochée à rien");
            $this->assertNull($template->root_anchor, "{$key} ne doit se prononcer sur aucune zone");

            $actual = array_map(
                static fn (array $r): array => [
                    $r['key'], $r['maille'], $r['group_type'], $r['access'], $r['cardinality'],
                ],
                $template->roles(),
            );
            $this->assertSame($roles, $actual, "la recette {$key} a changé");

            foreach ($template->roles() as $role) {
                $this->assertArrayNotHasKey('resolution', $role, "{$key} doit rester en cible désignée");
            }
        }
    }

    /**
     * **Un re-seed ne détache ni ne double rien** — et il resynchronise la baseline
     * ENTIÈRE, colonnes d'arbre et d'accrochage comprises. Une recette modifiée à la
     * main sur une instance revient à son état canonique.
     */
    #[Test]
    public function a_reseed_restores_the_canonical_baseline_including_the_tree_columns(): void
    {
        (new DirectoryTemplateSeeder())->run();

        DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)
            ->update(['attached_group_type' => null, 'root_anchor' => null, 'path_pattern' => 'Bricolage']);

        (new DirectoryTemplateSeeder())->run();

        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();
        $this->assertSame('classe', $template->attached_group_type);
        $this->assertSame(PlanAnchor::Classes->value, $template->root_anchor);
        $this->assertSame('Classe_{group.bare_name}', $template->path_pattern);
        $this->assertSame(5, DirectoryTemplate::count());
    }

    /**
     * **LE TEST D'ÉQUIVALENCE : le décor de test et le seed disent la même chose.**
     *
     * Toute la lignée 60.1 → 60.4 s'appuie sur un décor de recette classe. S'il
     * divergeait du seed, tous ces tests seraient faussement rassurants : ils
     * vérifieraient une recette que la production ne porte pas.
     */
    #[Test]
    public function the_seeded_class_tree_matches_the_test_fixture_exactly(): void
    {
        (new DirectoryTemplateSeeder())->run();

        $seeded = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();
        $fixture = $this->autoResolvableClassTreeTemplate();

        $this->assertSame($fixture->path_pattern, $seeded->path_pattern);
        $this->assertSame($fixture->root_anchor, $seeded->root_anchor);
        $this->assertSame($fixture->attached_group_type, $seeded->attached_group_type);

        // Les NŒUDS : mêmes chemins, mêmes natures, mêmes octrois. Les libellés
        // sont du texte d'affichage et n'entrent pas dans l'équivalence.
        $shape = static fn (array $nodes): array => array_map(
            static fn (array $n): array => [
                'path' => $n['path'],
                'nature' => $n['nature'],
                'edge_role' => $n['edge_role'] ?? null,
                'grants' => $n['grants'] ?? [],
            ],
            $nodes,
        );
        $this->assertSame($shape($fixture->nodes()), $shape($seeded->nodes()));

        // Les RÔLES : mêmes clés, mêmes stratégies, mêmes rôles d'arête.
        $roles = static fn (DirectoryTemplate $t): array => array_map(
            static fn (array $r): array => [
                'key' => $r['key'],
                'access' => $r['access'],
                'resolution' => $r['resolution'] ?? null,
            ],
            $t->roles(),
        );
        $this->assertSame($roles($fixture), $roles($seeded));
    }

    /**
     * **Le rôle « équipe » ne liste QUE le rôle d'arête d'encadrement.**
     *
     * Y ajouter le rôle de responsabilité émettrait un second sujet d'audience — un
     * groupe d'annuaire jamais éprouvé sur instance réelle — et l'arbre neuf
     * porterait une entrée que l'arbre historique n'a pas. Le même arbitrage vaut
     * pour « profs → élèves » : on l'épingle aux DEUX endroits, parce que c'est aux
     * deux endroits qu'on serait tenté de l'« améliorer ».
     */
    #[Test]
    public function both_class_recipes_list_the_management_edge_role_alone(): void
    {
        (new DirectoryTemplateSeeder())->run();

        foreach ([
            DirectoryTemplate::KEY_CLASSE_SE4 => 'equipe',
            DirectoryTemplate::KEY_PROFS_TO_ELEVES => 'profs',
        ] as $key => $roleKey) {
            $template = DirectoryTemplate::where('key', $key)->firstOrFail();
            $resolution = $template->resolutionForRole($roleKey);

            $this->assertSame(RoleResolutionStrategy::EdgeRole, $resolution['strategy'], $key);
            $this->assertSame(['manager'], $resolution['edge_roles'], $key);
        }
    }

    /**
     * **Rien ne laisse croire que la COLLECTE DES COPIES RENDUES est livrée.**
     *
     * Le dossier des devoirs distribue des sujets : les élèves y sont en LECTURE.
     * L'écrire en boîte de dépôt serait une régression fonctionnelle déguisée en
     * amélioration, et l'annoncer dans un libellé serait une promesse que rien ne
     * tient.
     */
    #[Test]
    public function nothing_in_the_recipe_promises_a_homework_collection_workflow(): void
    {
        (new DirectoryTemplateSeeder())->run();

        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();

        $texte = mb_strtolower(
            (string) $template->label . ' ' . (string) $template->description . ' ' .
            json_encode($template->nodes_spec, JSON_UNESCAPED_UNICODE)
        );

        foreach (['dépôt', 'depot', 'rendu', 'collecte', 'ramassage', 'casier'] as $promesse) {
            $this->assertStringNotContainsString(
                $promesse,
                $texte,
                sprintf('le mot « %s » promet un atelier de collecte qui n\'est pas livré', $promesse),
            );
        }

        // Et sur le fond : les élèves LISENT dans le dossier des devoirs.
        foreach ($template->nodes() as $node) {
            if (($node['path'] ?? null) !== '_travail/devoirs') {
                continue;
            }
            foreach ($node['grants'] as $grant) {
                if ($grant['role'] === 'classe') {
                    $this->assertSame('ro', $grant['access']);
                }
            }
        }
    }
}
