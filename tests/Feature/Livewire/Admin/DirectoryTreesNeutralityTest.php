<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\Plan\PlanGrant;
use Database\Seeders\DirectoryTemplateSeeder;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\PlanNeutralityMarkers;

/**
 * Story 62.6 — LA LIGNE DE COUPE TIENT JUSQU'AU HTML.
 *
 * Les gardes de neutralité de l'epic s'exercent sur les PLANS (60.1) et sur les
 * RAPPORTS (60.3/60.4). Cet écran est le premier à rendre les deux à un
 * administrateur, dans un document HTML qui porte aussi ses propres textes : les
 * explications du grisé, les notes de dégradation, les phrases d'aide. C'est
 * exactement là que le vocabulaire du mécanisme se réintroduit — dans une
 * info-bulle écrite « pour être précis ».
 *
 * Le scan porte donc sur le rendu COMPLET de l'onglet, éditeur ouvert : matrice
 * grisée, notes de nœud et aperçu résolu compris. La liste de marqueurs est celle
 * des stories précédentes, RÉUTILISÉE — deux listes qui divergeraient seraient
 * pires qu'une seule.
 */
class DirectoryTreesNeutralityTest extends TestCase
{
    use PlanNeutralityMarkers;
    use RefreshDatabase;

    private const TAB = 'pages::admin.settings.groups._partials.trees-tab';

    /**
     * Les mots de MÉCANISME qu'aucune explication de cet écran ne doit employer.
     *
     * Ils s'ajoutent aux marqueurs partagés : ceux-ci visent la sérialisation d'un
     * plan, ceux-là visent la PROSE d'un écran, qui a ses propres tentations.
     *
     * @var list<string>
     */
    private const SCREEN_MARKERS = [
        'sticky',
        'setfacl',
        'getfacl',
        'chmod',
        'ACL',
        'liste d\'accès',
        'masque',
        'bit ',
        'POSIX',
        'Nextcloud',
        'umask',
        'domain admins',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GroupRoleSeeder::class);
        $this->seed(GroupTypeSeeder::class);
        $this->seed(DirectoryTemplateSeeder::class);

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();

        $this->actingAs(User::create(['login' => 'neutral-admin', 'role' => 'admin', 'is_active' => true]));
        Gate::before(fn ($user, string $ability) => $ability === 'server.admin' ? true : null);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    /**
     * Le rendu le plus RICHE que l'écran sache produire : éditeur ouvert, une case
     * grisée, une dégradation déclarée, un nœud mixte, un plafond déclaré par le
     * backend, et l'aperçu résolu avec sa note d'atteignabilité.
     */
    private function richestHtml(): string
    {
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        foreach (['eleve1', 'eleve2', 'eleve3'] as $login) {
            $user = User::create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
            $group->users()->attach($user->id, ['role' => 'member']);
        }

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');

        $nodes = $component->get('nodesSpec');
        // Un dépôt « créer sans supprimer » : la note de dégradation déclarée.
        $nodes[3]['grants'][] = ['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE, PlanGrant::VERB_CREER]];
        // Un plafond : la déclaration du backend d'exécution.
        $nodes[3]['plafond'] = 1073741824;
        // Un nœud PROFOND servi à la seule équipe : la note d'atteignabilité.
        $nodes[] = [
            'path' => '_travail/prive',
            'label' => 'Réservé',
            'nature' => 'partagee',
            'grants' => [['role' => 'equipe', 'verbs' => PlanGrant::VERBS]],
        ];
        // …et ses ancêtres n'accordent rien à l'équipe : le passage sera dérivé.
        $nodes[0]['grants'] = [['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]]];
        $nodes[1]['grants'] = [['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]]];

        // Un dépôt PUR (aucune autre audience n'y supprime) : la dégradation
        // approchée, déclarée telle quelle.
        $nodes[] = [
            'path' => '_depots',
            'label' => 'Dépôts',
            'nature' => 'partagee',
            'grants' => [['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE, PlanGrant::VERB_CREER]]],
        ];

        $component->set('nodesSpec', $nodes)->call('preview');
        $this->assertSame('', $component->get('previewError'));

        $html = $component->html();

        // Le rendu doit bien être le rendu RICHE : un scan qui ne regarde rien
        // passerait éternellement au vert.
        $this->assertStringContainsString('data-testid="preview-table"', $html);
        $this->assertStringContainsString('data-testid="traversal-note"', $html);
        $this->assertStringContainsString('non exprimable', $html);
        $this->assertStringContainsString('retirer ses propres fichiers', $html);
        $this->assertStringContainsString('data-testid="node-note-3"', $html);

        return $html;
    }

    #[Test]
    public function the_rendered_tab_carries_no_marker_of_the_layer_below(): void
    {
        $html = $this->richestHtml();

        foreach (self::forbiddenMarkers() as $label => $marker) {
            $this->assertStringNotContainsStringIgnoringCase(
                $marker,
                $html,
                sprintf('LIGNE DE COUPE FRANCHIE dans le HTML : %s (« %s »).', $label, $marker),
            );
        }
    }

    #[Test]
    public function no_explanation_of_this_screen_names_a_mechanism(): void
    {
        $html = $this->richestHtml();

        foreach (self::SCREEN_MARKERS as $marker) {
            $this->assertStringNotContainsStringIgnoringCase(
                $marker,
                $html,
                sprintf('Le mot de mécanisme « %s » a été employé dans une explication de l\'écran.', $marker),
            );
        }
    }

    /**
     * Aucun EMPLACEMENT n'est affiché : l'aperçu ne vise aucun endroit réel, et le
     * contrat le dit en rendant `null`. La liste fermée des consommateurs de cette
     * chaîne ne bouge donc pas — un test d'architecture la tient, celui-ci vérifie
     * simplement que l'écran ne la demande pas.
     */
    #[Test]
    public function the_screen_never_asks_the_backend_where_it_would_write(): void
    {
        $sources = [
            'resources/views/pages/admin/settings/groups/_partials/trees-tab.blade.php',
            'resources/views/pages/admin/settings/groups/_partials/trees/identity.blade.php',
            'resources/views/pages/admin/settings/groups/_partials/trees/audiences.blade.php',
            'resources/views/pages/admin/settings/groups/_partials/trees/editor.blade.php',
            'resources/views/pages/admin/settings/groups/_partials/trees/preview.blade.php',
        ];

        foreach ($sources as $relative) {
            $contents = (string) file_get_contents(base_path($relative));

            $this->assertStringNotContainsString('->location(', $contents, $relative);
            $this->assertStringNotContainsString('directoryMode', $contents, $relative);
            $this->assertStringNotContainsString('fileMode', $contents, $relative);
            $this->assertStringNotContainsString('TraversalPlanner', $contents, $relative);
        }
    }

    /**
     * D4/D9 : aucun champ de traversée, aucun champ d'interdiction, aucune
     * priorité. Ne pas les proposer est la moitié UI de la garde que le
     * vocabulaire de clés fermé tient côté modèle.
     */
    #[Test]
    public function the_form_offers_no_traversal_no_denial_and_no_priority(): void
    {
        $html = $this->richestHtml();

        foreach (['traversee', 'traversée', 'interdi', 'priorité', 'refuser'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $html,
                sprintf('Un champ ou un mot « %s » est proposé : l\'octroi est POSITIF, la traversée est dérivée.', $forbidden),
            );
        }
    }
}
