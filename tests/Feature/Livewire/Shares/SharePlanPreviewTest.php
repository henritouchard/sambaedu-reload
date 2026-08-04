<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Shares;

use App\Enums\FileBackendName;
use App\Enums\FileBackendOutcome;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\PlanNeutralityMarkers;

/**
 * Story 60.3 — LE PREMIER LIVRABLE VISIBLE de l'epic : voir avant d'appliquer.
 *
 * Deux propriétés se tiennent ici, et elles sont de nature différente :
 *  - le backend est AFFICHÉ (il détermine le chemin d'accès de l'utilisateur, ce
 *    n'est pas un détail d'implémentation) mais il n'est PAS éditable — tant
 *    qu'aucun flux ne route par la colonne, un sélecteur serait une propriété qui
 *    ment, la signature de défaut déjà rencontrée deux epics de suite ;
 *  - l'aperçu est NEUTRE : il montre des dossiers, des personnes et des groupes
 *    SE5, jamais un mode de permission, une commande système ou un chemin absolu.
 */
class SharePlanPreviewTest extends TestCase
{
    use PlanNeutralityMarkers;
    use RefreshDatabase;

    private const PAGE = 'pages::admin.shares.[id].index';

    private const LIST_PAGE = 'pages::admin.shares.index';

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        Queue::fake();
        Process::fake();

        $this->tempRoot = sys_get_temp_dir() . '/netshare-preview-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);

        foreach (['networkshare.view', 'networkshare.manage'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function manager(): User
    {
        $u = User::create(['login' => 'mgr-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $u->givePermissionTo('networkshare.view');
        $u->givePermissionTo('networkshare.manage');

        return $u;
    }

    private function shareWithAssignments(): NetworkShare
    {
        $share = NetworkShare::factory()->create([
            'name' => 'Depot pedagogique',
            'directory_name' => 'depot_pedagogique',
            'letter' => 'P:',
        ]);

        $user = User::create(['login' => 'alecoz', 'role' => 'prof', 'is_active' => true]);
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'display_name' => '3eme A', 'type' => 'classe']);
        $wg = WorkstationGroup::create(['name' => 'salle-101', 'display_name' => 'Salle 101']);

        foreach ([
            [User::class, (int) $user->id, 'rw'],
            [UserGroup::class, (int) $group->id, 'ro'],
            [WorkstationGroup::class, (int) $wg->id, 'rw'],
        ] as [$type, $id, $access]) {
            NetworkShareAssignable::create([
                'network_share_id' => $share->id,
                'assignable_type' => $type,
                'assignable_id' => $id,
                'access' => $access,
            ]);
        }

        return $share->fresh();
    }

    // =========================================================================
    // Le backend est VISIBLE
    // =========================================================================

    #[Test]
    public function the_detail_page_shows_the_backend_by_its_label_never_its_raw_value(): void
    {
        $share = NetworkShare::factory()->create();
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $share->id])
            ->assertSet('backendLabel', FileBackendName::Posix->label())
            ->assertSee(FileBackendName::Posix->label())
            ->assertDontSee('posix');
    }

    #[Test]
    public function the_list_page_shows_the_backend_of_each_share(): void
    {
        NetworkShare::factory()->create(['name' => 'Alpha']);
        $this->actingAs($this->manager());

        Livewire::test(self::LIST_PAGE)
            ->assertSee(FileBackendName::Posix->label())
            ->assertDontSee('>posix<');
    }

    /**
     * AUCUN contrôle d'édition. On ne se contente pas de ne pas en ajouter : on
     * vérifie qu'il n'y en a pas, parce que c'est le raccourci « tant qu'à faire »
     * le plus probable de la story suivante.
     */
    #[Test]
    public function the_backend_is_not_editable_anywhere(): void
    {
        $share = NetworkShare::factory()->create();
        $this->actingAs($this->manager());

        $html = Livewire::test(self::PAGE, ['id' => $share->id])->html();

        foreach (['wire:model="backend"', 'wire:model.live="backend"', 'changeBackend', 'name="backend"'] as $control) {
            $this->assertStringNotContainsString($control, $html, 'contrôle d\'édition du backend : ' . $control);
        }

        // Et la colonne reste hors du remplissage de masse.
        $this->assertNotContains('backend', (new NetworkShare())->getFillable());
    }

    // =========================================================================
    // L'aperçu
    // =========================================================================

    #[Test]
    public function the_preview_shows_the_plan_and_the_report_of_the_preview_backend(): void
    {
        $share = $this->shareWithAssignments();
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE, ['id' => $share->id])
            ->assertSet('isPlanPreviewOpen', false)
            ->call('openPlanPreview')
            ->assertSet('isPlanPreviewOpen', true);

        $preview = $component->get('planPreview');

        $this->assertSame(FileBackendName::Preview->label(), $preview['backend']['label']);
        $this->assertSame('depot_pedagogique', $preview['root']);
        $this->assertCount(1, $preview['nodes']);

        $node = $preview['nodes'][0];
        $this->assertSame('(racine)', $node['display_path']);
        $this->assertSame(FileBackendOutcome::NonExecute->value, $node['outcome']);

        // Les sujets sont rendus par leurs noms SE5, résolus depuis les identités
        // internes du plan — et le parc n'octroie rien.
        $labels = array_column($node['grants'], 'label');
        sort($labels);
        $this->assertSame(['3eme A (groupe d\'utilisateurs)', 'alecoz (utilisateur)'], $labels);
    }

    #[Test]
    public function the_rendered_preview_names_the_root_and_the_subjects(): void
    {
        $share = $this->shareWithAssignments();
        $this->actingAs($this->manager());

        $html = Livewire::test(self::PAGE, ['id' => $share->id])
            ->call('openPlanPreview')
            ->html();

        $this->assertStringContainsString('(racine)', $html);
        $this->assertStringContainsString('alecoz (utilisateur)', $html);
        $this->assertStringContainsString('3eme A (groupe', $html);
        $this->assertStringContainsString(FileBackendOutcome::NonExecute->label(), $html);
    }

    /**
     * L'ÉCRAN EST NEUTRE. Le partage porte pourtant un groupe classe, dont la
     * dérivation historique produirait un nom de groupe système : il n'apparaît
     * pas, parce que la coupe passe avant cette dérivation, y compris ici.
     */
    #[Test]
    public function the_rendered_preview_carries_no_vocabulary_of_the_layer_below(): void
    {
        $share = $this->shareWithAssignments();
        $this->actingAs($this->manager());

        $html = Livewire::test(self::PAGE, ['id' => $share->id])
            ->call('openPlanPreview')
            ->html();

        $preview = $this->previewSection($html);

        foreach (self::forbiddenMarkers() as $label => $marker) {
            $this->assertStringNotContainsStringIgnoringCase(
                $marker,
                $preview,
                sprintf('LIGNE DE COUPE FRANCHIE À L\'ÉCRAN : %s (« %s »)', $label, $marker),
            );
        }

        $this->assertStringNotContainsString('classe_3emea', strtolower($preview));
    }

    /**
     * Le bloc d'aperçu, isolé du reste de la page.
     *
     * L'isolement était VOLONTAIRE, et sa raison a DISPARU : la page portait,
     * depuis l'Epic 34, un encart de conformité qui affichait des entrées de liste
     * d'accès brutes, hors du périmètre de la story 60.3. La story 60.4 l'a
     * assaini, et il a désormais sa propre garde de neutralité, bornée sur son
     * propre marqueur ({@see \Tests\Feature\Livewire\Shares\ShareDriftPanelTest}).
     * On garde deux zones bornées plutôt qu'une mesure de page entière : chaque
     * test dit alors exactement ce qu'il couvre, et un ajout de bloc ne le rend pas
     * silencieusement plus faible.
     */
    private function previewSection(string $html): string
    {
        // On borne sur le marqueur du conteneur RACINE du partial, pas sur un texte
        // rendu à l'intérieur : démarrer sur « Racine du plan » laissait le bandeau
        // de backend hors de la zone mesurée, donc le test couvrait un div de moins
        // que ce qu'il affirmait couvrir.
        $start = strpos($html, 'data-plan-preview');
        $this->assertNotFalse($start, 'le bloc d\'aperçu doit être rendu');

        $end = strpos($html, 'Ajouter une assignation', $start);

        return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
    }

    // =========================================================================
    // Les sept états, rendus distinctement
    // =========================================================================

    /**
     * Le rendu est alimenté par un rapport de FIXTURE portant chacun des sept
     * états : sept libellés distincts, et les DEUX déclins rendus différemment —
     * « non supporté par ce backend » n'est pas « non piloté par SE5 pour
     * l'instant ».
     */
    #[Test]
    public function the_seven_outcomes_render_with_seven_distinct_labels(): void
    {
        $nodes = [];
        foreach (FileBackendOutcome::cases() as $index => $outcome) {
            $nodes[] = [
                'path' => 'n' . $index,
                'display_path' => 'n' . $index,
                'label' => 'Nœud ' . $index,
                'nature' => 'Dossier partagé',
                'plafond' => $index === 0 ? 2147483648 : null,
                'closure' => $index === 1 ? ['classe'] : [],
                'grants' => [],
                'outcome' => $outcome->value,
                'detail' => $outcome->requiresDetail() ? 'raison ' . $index : null,
            ];
        }

        $html = view('pages::admin.shares._partials.plan-preview', [
            'preview' => [
                'backend' => [
                    'label' => FileBackendName::Preview->label(),
                    'description' => FileBackendName::Preview->description(),
                ],
                'root' => 'depot',
                'template' => '@partage',
                'nodes' => $nodes,
            ],
        ])->render();

        // Blade échappe les apostrophes : on compare sur le texte, pas sur son
        // encodage — la propriété testée est « sept libellés distincts à l'écran ».
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        foreach (FileBackendOutcome::cases() as $outcome) {
            $this->assertStringContainsString($outcome->label(), $html, $outcome->value);
        }

        // Les deux déclins portent en plus une explication DIFFÉRENTE.
        $this->assertStringContainsString('Limite du modèle de ce backend', $html);
        $this->assertStringContainsString('SE5 ne le pilote pas encore', $html);

        // La clôture et le plafond traversent jusqu'à l'écran.
        $this->assertStringContainsString('N\'a rien reçu ici', $html);
        $this->assertStringContainsString('Mo', $html);
    }

    #[Test]
    public function a_share_whose_directory_name_is_unusable_says_so_instead_of_showing_half_a_plan(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'depot']);
        \Illuminate\Support\Facades\DB::table('network_shares')
            ->where('id', $share->id)
            ->update(['directory_name' => '../evasion']);

        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $share->id])
            ->call('openPlanPreview')
            ->assertSet('isPlanPreviewOpen', false)
            ->assertSet('planPreview', null);
    }
}
