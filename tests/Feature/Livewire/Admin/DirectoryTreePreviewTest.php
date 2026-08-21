<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\DirectoryTemplate;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\Backend\PreviewBackend;
use App\Services\Filesystem\Plan\PlanGrant;
use Database\Seeders\DirectoryTemplateSeeder;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.6 — L'APERÇU : le premier consommateur VISIBLE du backend d'aperçu.
 *
 * Couvre AC6 entièrement, plus la moitié « aperçu » de l'AC2.
 *
 * **Le test pivot de cette suite épingle le CHEMIN, pas seulement le résultat** :
 * le backend d'aperçu est obtenu par le REGISTRE, qui le résout par le conteneur.
 * On remplace donc la fabrique du conteneur : si l'écran instanciait le backend
 * directement — ou s'il rendait le plan « à la main » sans backend du tout — la
 * fabrique ne serait jamais appelée et le test tomberait, sans qu'aucune
 * assertion d'affichage ne s'en aperçoive.
 */
class DirectoryTreePreviewTest extends TestCase
{
    use RefreshDatabase;

    private const TAB = 'pages::admin.settings.groups._partials.trees-tab';


    private const EDITOR = 'pages::admin.settings.groups.trees.[type].index';

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

        $this->actingAs(User::create(['login' => 'preview-admin', 'role' => 'admin', 'is_active' => true]));
        Gate::before(fn ($user, string $ability) => $ability === 'server.admin' ? true : null);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    private function classWithMembers(int $count = 1): UserGroup
    {
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);

        for ($i = 1; $i <= $count; $i++) {
            $user = User::create(['login' => 'eleve'.$i, 'role' => 'eleve', 'is_active' => true]);
            $group->users()->attach($user->id, ['role' => 'member']);
        }

        return $group;
    }

    // =========================================================================
    // AC6 — le backend d'aperçu, consommé par le REGISTRE
    // =========================================================================

    #[Test]
    public function the_preview_is_produced_by_the_preview_backend_obtained_from_the_registry(): void
    {
        $this->classWithMembers();

        $resolved = 0;
        // Le registre résout par le CONTENEUR : c'est la couture. Une
        // instanciation directe dans l'écran ne passerait pas par ici.
        $this->app->bind(PreviewBackend::class, function () use (&$resolved): PreviewBackend {
            $resolved++;

            return new PreviewBackend;
        });

        $component = Livewire::test(self::EDITOR, ['type' => 'classe'])->call('preview');

        $this->assertSame(1, $resolved, 'le backend d\'aperçu n\'a pas été obtenu par le registre');
        $this->assertSame('', $component->get('previewError'));

        // Et les marqueurs que SEUL ce backend produit.
        foreach ($component->get('previewData')['nodes'] as $row) {
            $this->assertSame('non_execute', $row['outcome']);
            $this->assertStringContainsString('Aucune écriture : aperçu du plan.', $row['detail']);
        }
    }

    /**
     * La CLÔTURE traverse la ligne de contrat intacte, et l'aperçu la montre.
     * C'est la preuve, à l'écran, qu'elle n'est ni filtrée ni résumée au passage.
     */
    #[Test]
    public function the_preview_shows_the_closure_sentence_the_backend_produces(): void
    {
        $this->classWithMembers();

        $component = Livewire::test(self::EDITOR, ['type' => 'classe'])->call('preview');

        $profs = collect($component->get('previewData')['nodes'])->firstWhere('path', '_profs');

        $this->assertNotNull($profs);
        $this->assertStringContainsString('Rôles sans octroi ici (clôture reçue du plan)', $profs['detail']);
        $this->assertStringContainsString('classe', $profs['detail']);
        $this->assertStringContainsString('clôture reçue du plan', $component->html());
    }

    #[Test]
    public function the_resolved_paths_carry_the_real_values_of_the_trial_group(): void
    {
        $this->classWithMembers();

        $component = Livewire::test(self::EDITOR, ['type' => 'classe'])->call('preview');

        $paths = array_column($component->get('previewData')['nodes'], 'path');

        $this->assertContains('eleve1', $paths, 'le jeton du membre n\'a pas été substitué');
        $this->assertSame('Classe_3emeA', $component->get('previewData')['root']);
        $this->assertSame('Classe_3emeA', $component->get('previewData')['group']);
    }

    #[Test]
    public function per_member_nodes_are_folded_with_their_count(): void
    {
        $this->classWithMembers(12);

        $component = Livewire::test(self::EDITOR, ['type' => 'classe'])->call('preview');

        $rows = $component->get('previewData')['nodes'];

        // Six nœuds de recette, dont UN par membre : l'aperçu en montre six, pas
        // dix-sept.
        $this->assertCount(6, $rows);

        $folded = collect($rows)->firstWhere('more', '>', 0);
        $this->assertNotNull($folded, 'les dossiers de membres ne sont pas repliés');
        $this->assertSame(11, $folded['more']);
        $this->assertStringContainsString('et 11 autres dossiers de membres', $component->html());
    }

    /**
     * La note d'atteignabilité se dérive de la STRUCTURE du plan — pas d'un appel
     * au planificateur de traversée, qui est un savoir de backend.
     */
    #[Test]
    public function a_deep_only_grant_carries_the_reachability_note_and_its_tooltip(): void
    {
        $this->classWithMembers();

        $component = Livewire::test(self::EDITOR, ['type' => 'classe']);

        $nodes = $component->get('nodesSpec');
        // `_travail/prive` : servi à l'équipe et à personne d'autre… mais l'équipe
        // n'a rien sur `_travail` ni sur la racine.
        $nodes[0]['grants'] = [['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]]];
        $nodes[1]['grants'] = [['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]]];
        $nodes[] = [
            'path' => '_travail/prive',
            'label' => 'Réservé',
            'nature' => 'partagee',
            'grants' => [['role' => 'equipe', 'verbs' => PlanGrant::VERBS]],
        ];
        // Le dossier personnel garde son audience couvrante ; le nœud `_profs` et
        // `_echange` gardent les leurs.
        $component->set('nodesSpec', $nodes)->call('preview');

        $this->assertSame('', $component->get('previewError'));

        $notes = $component->get('previewData')['traversal'];
        $this->assertNotSame([], $notes, 'aucune note d\'atteignabilité');
        $this->assertStringContainsString('_travail/prive', implode(' ', $notes));
        $this->assertStringContainsString('ATTEIGNABLE', implode(' ', $notes));

        $html = $component->html();
        $this->assertStringContainsString('data-testid="traversal-note"', $html);
        // L'info-bulle qui explique le mot « couloir » (review 62.5).
        $this->assertStringContainsString('couloir', $html);
    }

    #[Test]
    public function a_fully_covered_tree_carries_no_reachability_note(): void
    {
        $this->classWithMembers();

        $component = Livewire::test(self::EDITOR, ['type' => 'classe'])->call('preview');

        $this->assertSame([], $component->get('previewData')['traversal']);
    }

    // =========================================================================
    // AC6 — les états limites
    // =========================================================================

    #[Test]
    public function without_a_group_of_the_type_the_preview_says_what_to_do_and_saving_still_works(): void
    {
        $component = Livewire::test(self::EDITOR, ['type' => 'classe'])->call('preview');

        $this->assertSame([], $component->get('previewData'));
        $this->assertStringContainsString('créez-en un', $component->get('previewError'));

        // L'enregistrement ne dépend pas de l'aperçu.
        $component->set('label', 'Classe (arbre)')->call('save')->assertHasNoErrors();
    }

    /**
     * Un groupe au nom inexploitable donne un message MÉTIER, pas une exception —
     * et pas non plus un préfiltrage silencieux du sélecteur.
     */
    #[Test]
    public function a_trial_group_whose_name_cannot_be_resolved_yields_a_business_message(): void
    {
        $group = UserGroup::create(['name' => '   ', 'type' => 'classe']);

        $component = Livewire::test(self::EDITOR, ['type' => 'classe'])
            ->set('previewGroupId', (int) $group->id)
            ->call('preview');

        $this->assertSame([], $component->get('previewData'));
        $this->assertNotSame('', $component->get('previewError'));
        $this->assertStringContainsString('data-testid="preview-error"', $component->html());
    }

    #[Test]
    public function an_invalid_state_shows_the_refusal_instead_of_a_partial_plan(): void
    {
        $this->classWithMembers();
        $before = DB::table('directory_templates')->where('key', DirectoryTemplate::KEY_CLASSE_SE4)->first();

        $component = Livewire::test(self::EDITOR, ['type' => 'classe']);
        $nodes = $component->get('nodesSpec');
        $nodes[1]['nature'] = 'coffre_fort';
        $component->set('nodesSpec', $nodes)->call('preview');

        $this->assertSame([], $component->get('previewData'), 'un aperçu partiel a été rendu');
        $this->assertStringContainsString('nature inconnue', $component->get('previewError'));

        $this->assertEquals(
            $before,
            DB::table('directory_templates')->where('key', DirectoryTemplate::KEY_CLASSE_SE4)->first(),
        );
    }

    /** Prévisualiser travaille sur un CLONE : la ligne réelle n'est jamais touchée. */
    #[Test]
    public function previewing_an_edited_state_never_persists_it(): void
    {
        $this->classWithMembers();
        $before = DB::table('directory_templates')->where('key', DirectoryTemplate::KEY_CLASSE_SE4)->first();

        Livewire::test(self::EDITOR, ['type' => 'classe'])
            ->set('label', 'Jamais enregistré')
            ->set('pathPattern', 'Autre_{group.bare_name}')
            ->call('preview')
            ->assertSet('previewError', '');

        $this->assertEquals(
            $before,
            DB::table('directory_templates')->where('key', DirectoryTemplate::KEY_CLASSE_SE4)->first(),
            'l\'aperçu a persisté l\'état du formulaire',
        );
    }

    // =========================================================================
    // Le PLAFOND : ce que le backend d'exécution en DÉCLARE
    // =========================================================================

    /**
     * **La déclaration vient du BACKEND, jamais d'une constante d'écran.**
     *
     * Le libellé et le détail affichés sont ceux que le backend produit. Le jour où
     * une arborescence sera servie par un plan de fichiers dont le plafond porte sur
     * la zone entière, la même consultation rendra `non_exprimable` sur un
     * sous-dossier — et l'écran l'affichera sans qu'une ligne de Blade change.
     */
    #[Test]
    public function the_plafond_declaration_is_the_executing_backends_own_words(): void
    {
        $this->classWithMembers();

        $component = Livewire::test(self::EDITOR, ['type' => 'classe']);
        $nodes = $component->get('nodesSpec');
        $nodes[3]['plafond'] = 1073741824;
        $component->set('nodesSpec', $nodes)->call('preview');

        $row = collect($component->get('previewData')['nodes'])->firstWhere('path', '_profs');

        $this->assertSame('1,0 Go', $row['plafond']);
        $this->assertNotNull($row['quota_declaration'], 'le backend n\'a rien déclaré sur ce plafond');
        $this->assertNotSame('', $row['quota_declaration']['detail'], 'un déclin sans raison est un silence');
        $this->assertStringContainsString(
            e($row['quota_declaration']['label']),
            $component->html(),
            'le libellé du backend n\'atteint pas l\'écran',
        );
    }

    #[Test]
    public function a_tree_without_any_plafond_asks_the_backend_nothing(): void
    {
        $this->classWithMembers();

        $component = Livewire::test(self::EDITOR, ['type' => 'classe'])->call('preview');

        foreach ($component->get('previewData')['nodes'] as $row) {
            $this->assertNull($row['quota_declaration']);
        }
    }
}
