<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Models\DirectoryTemplate;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\PlanGrant;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.4 — LA MIGRATION Q3, éprouvée sur une recette posée AUX DEUX FORMES.
 *
 * La migration s'est déjà jouée sur la base de test (c'est une migration du
 * dépôt) ; ce qui se teste ici, c'est son COMPORTEMENT sur une recette qu'on lui
 * remet délibérément à l'ancienne forme. On la ré-instancie donc et on la rejoue,
 * exactement comme elle s'exécuterait sur une instance en place.
 *
 * Le mappage Q3 est vérifié EXACTEMENT — ni verbe manquant, ni verbe ajouté —
 * parce que c'est là que se joue la promesse « aucune recette ne perd d'accès ».
 */
class DirectoryTemplateVerbsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_08_140000_migrate_directory_template_access_to_verbs.php';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    /**
     * Une recette écrite dans l'ANCIEN vocabulaire, aux DEUX endroits : ses rôles
     * et les octrois de ses nœuds, `suspendable` compris.
     */
    private function legacyRecipe(): int
    {
        return (int) DB::table('directory_templates')->insertGetId([
            'key' => 'heritee',
            'label' => 'Recette héritée',
            'description' => 'Posée au vocabulaire binaire pour éprouver la montée.',
            'roles_spec' => json_encode([
                ['key' => 'equipe', 'label' => 'Équipe', 'maille' => UserGroup::class, 'access' => 'rw', 'cardinality' => 'one'],
                ['key' => 'classe', 'label' => 'Classe', 'maille' => UserGroup::class, 'access' => 'ro', 'cardinality' => 'one'],
            ]),
            'path_pattern' => 'Classe_{group.bare_name}',
            'nodes_spec' => json_encode([
                [
                    'path' => '_travail',
                    'label' => 'Travail',
                    'nature' => 'partagee',
                    'grants' => [
                        ['role' => 'equipe', 'access' => 'rw'],
                        ['role' => 'classe', 'access' => 'ro'],
                    ],
                ],
                [
                    'path' => '_echange',
                    'label' => 'Échange',
                    'nature' => 'activable',
                    'activable' => true,
                    'grants' => [
                        ['role' => 'classe', 'access' => 'rw', 'suspendable' => true],
                    ],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{roles: array<int,mixed>, nodes: array<int,mixed>} */
    private function specsOf(int $id): array
    {
        $row = DB::table('directory_templates')->where('id', $id)->first(['roles_spec', 'nodes_spec']);

        return [
            'roles' => json_decode(is_string($row->roles_spec) ? $row->roles_spec : '[]', true),
            'nodes' => json_decode(is_string($row->nodes_spec) ? $row->nodes_spec : '[]', true),
        ];
    }

    #[Test]
    public function the_q3_mapping_is_applied_exactly_to_roles_and_to_node_grants(): void
    {
        $id = $this->legacyRecipe();

        $this->migration()->up();

        ['roles' => $roles, 'nodes' => $nodes] = $this->specsOf($id);

        // `rw` → LES QUATRE. Ni plus, ni moins.
        self::assertSame(PlanGrant::VERBS, $roles[0]['verbs']);
        self::assertArrayNotHasKey('access', $roles[0], 'la clé abandonnée doit DISPARAÎTRE, pas cohabiter');

        // `ro` → « lire » SEUL.
        self::assertSame([PlanGrant::VERB_LIRE], $roles[1]['verbs']);
        self::assertArrayNotHasKey('access', $roles[1]);

        // Le reste du rôle est intact — la migration ne touche QUE les droits.
        self::assertSame('equipe', $roles[0]['key']);
        self::assertSame('one', $roles[0]['cardinality']);

        // Les octrois de nœud, même mappage.
        self::assertSame(PlanGrant::VERBS, $nodes[0]['grants'][0]['verbs']);
        self::assertSame([PlanGrant::VERB_LIRE], $nodes[0]['grants'][1]['verbs']);

        // `suspendable` SURVIT : la suspension est orthogonale aux verbes.
        self::assertSame(PlanGrant::VERBS, $nodes[1]['grants'][0]['verbs']);
        self::assertTrue($nodes[1]['grants'][0]['suspendable']);
        self::assertArrayNotHasKey('access', $nodes[1]['grants'][0]);
    }

    #[Test]
    public function the_migration_is_idempotent(): void
    {
        $id = $this->legacyRecipe();

        $this->migration()->up();
        $once = $this->specsOf($id);

        $this->migration()->up();
        self::assertSame($once, $this->specsOf($id), 'une seconde montée ne doit RIEN changer');
    }

    /**
     * La descente est LOSSY, et le test le constate plutôt que de le taire : une
     * liste raffinée (« déposer sans effacer ») redevient un niveau d'écriture
     * plein. C'est le meilleur retour possible sans retirer d'accès, et c'est
     * exactement pour cela que redescendre n'est pas anodin.
     */
    #[Test]
    public function the_down_migration_is_reversible_at_best_and_lossy_by_design(): void
    {
        $id = $this->legacyRecipe();
        $this->migration()->up();

        // On raffine une recette comme 62.6 permettra de le faire.
        $specs = $this->specsOf($id);
        $specs['nodes'][0]['grants'][1]['verbs'] = [PlanGrant::VERB_LIRE, PlanGrant::VERB_CREER];
        DB::table('directory_templates')->where('id', $id)->update([
            'nodes_spec' => json_encode($specs['nodes']),
        ]);

        $this->migration()->down();

        ['roles' => $roles, 'nodes' => $nodes] = $this->specsOf($id);

        self::assertSame('rw', $roles[0]['access']);
        self::assertSame('ro', $roles[1]['access']);
        self::assertArrayNotHasKey('verbs', $roles[0]);

        // La perte, nommée : « lire + créer » ne se dit pas en binaire, et redevient
        // le niveau d'écriture plein — qui accorde AUSSI la suppression.
        self::assertSame('rw', $nodes[0]['grants'][1]['access']);
    }

    /**
     * **AC8 — AUCUNE RECETTE SEEDÉE NE DEMANDE LA RESTRICTION DE SUPPRESSION.**
     *
     * C'est ce qui rend vraie la promesse « rien ne bouge sur une instance en
     * place » : sous le mappage Q3, une recette porte soit « lire » seul, soit les
     * quatre verbes — jamais « créer sans supprimer », la seule combinaison qui
     * ferait poser un drapeau sur le disque. Un mappage plus fin, même bien
     * intentionné, ferait tomber ce test AVANT d'avoir modifié un seul dossier.
     */
    #[Test]
    public function no_seeded_recipe_asks_to_deposit_without_erasing(): void
    {
        (new DirectoryTemplateSeeder())->run();

        $offenders = [];

        foreach (DirectoryTemplate::all() as $template) {
            $lists = [];
            foreach ($template->roles() as $role) {
                $lists['rôle ' . ($role['key'] ?? '?')] = $role['verbs'] ?? [];
            }
            foreach ($template->nodes() as $node) {
                foreach ($node['grants'] ?? [] as $grant) {
                    $lists['nœud ' . ($node['path'] ?? '?') . ' / ' . ($grant['role'] ?? '?')] = $grant['verbs'] ?? [];
                }
            }

            foreach ($lists as $where => $verbs) {
                $canonical = PlanGrant::canonicalize($verbs);

                // Les deux seules formes que la migration produit.
                self::assertContains(
                    $canonical,
                    [[PlanGrant::VERB_LIRE], PlanGrant::VERBS],
                    sprintf('%s (%s) : forme inattendue après migration', $template->key, $where),
                );

                if (in_array(PlanGrant::VERB_CREER, $canonical, true)
                    && ! in_array(PlanGrant::VERB_SUPPRIMER, $canonical, true)) {
                    $offenders[] = $template->key . ' → ' . $where;
                }
            }
        }

        self::assertSame([], $offenders, 'une recette seedée ferait poser une restriction sur le disque');
    }

    /**
     * Le mappage doit être celui de Q3, et rien d'autre. Un test qui se
     * contenterait de vérifier « la clé a changé » passerait au vert avec un
     * mappage inversé.
     */
    #[Test]
    public function no_recipe_loses_an_access_the_mapping_is_monotone(): void
    {
        $id = $this->legacyRecipe();
        $this->migration()->up();

        ['roles' => $roles] = $this->specsOf($id);

        // L'ancien niveau d'écriture donnait, sur le disque, la lecture, l'écriture
        // du contenu, la création et la suppression. Les quatre verbes sont donc le
        // SEUL mappage qui ne retire rien.
        self::assertCount(4, $roles[0]['verbs']);

        // L'ancienne lecture seule ne donnait AUCUN verbe de mutation : y en
        // ajouter un serait ouvrir un droit que personne n'a écrit.
        self::assertSame([], array_intersect(PlanGrant::MUTATION_VERBS, $roles[1]['verbs']));
    }
}
