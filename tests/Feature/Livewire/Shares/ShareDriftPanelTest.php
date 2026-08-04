<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Shares;

use App\Enums\FileBackendName;
use App\Jobs\ReconcileNetworkShareJob;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Support\RecordingBackend;

/**
 * Story 60.4 — L'ENCART DE CONFORMITÉ ASSAINI.
 *
 * Depuis l'Epic 34, cet encart affichait les écarts en ENTRÉES DE LISTE D'ACCÈS
 * BRUTES, en chasse fixe, à l'écran d'un administrateur — ce qui frôlait le
 * garde-fou « pas d'éditeur de droits bruts » de l'epic. La descente de
 * l'exécution sous la ligne de contrat permet enfin de le dire autrement : un
 * nœud, un destinataire par son nom SE5, un accès attendu et un accès constaté.
 *
 * Le test est BORNÉ sur le marqueur du conteneur (`data-share-drift`) — même
 * patron que celui de l'aperçu : une frontière dessinée là où elle est annoncée.
 *
 * Aucune simulation d'exécution ici : le backend est remplacé par un double, ce
 * qui laisse l'écran emprunter tout son vrai chemin sans qu'aucune commande n'ait
 * à être feinte.
 */
class ShareDriftPanelTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.shares.[id].index';

    private RecordingBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        Queue::fake();

        $this->backend = new RecordingBackend();
        $this->app->instance(PosixFileBackend::class, $this->backend);

        foreach (['networkshare.view', 'networkshare.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    protected function tearDown(): void
    {
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

    /** @return array{0:NetworkShare,1:User,2:UserGroup} */
    private function driftedShare(): array
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'devoirs', 'name' => 'Devoirs', 'letter' => 'P:']);
        $alice = User::create(['login' => 'alice', 'role' => 'prof', 'is_active' => true]);
        $classe = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe', 'display_name' => '3e A']);

        foreach ([[User::class, $alice->id, 'rw'], [UserGroup::class, $classe->id, 'ro']] as [$type, $id, $access]) {
            NetworkShareAssignable::create([
                'network_share_id' => $share->id,
                'assignable_type' => $type,
                'assignable_id' => $id,
                'access' => $access,
            ]);
        }

        // Sur le disque : l'utilisatrice n'a plus que la lecture, le groupe a
        // disparu, et une entrée inconnue traîne.
        $this->backend->inspectUsing = fn (FilePlan $plan): InspectionReport => InspectionReport::covering(
            FileBackendName::Posix,
            $plan,
            [NodeObservation::observed(PlanNode::ROOT_PATH, [
                new ObservedGrant(PlanSubject::user((int) $alice->id), 'ro'),
            ], null, false, '1 entrée(s) relue(s) ne correspondent à aucune identité connue de SE5.')],
        );

        return [$share, $alice, $classe];
    }

    /** Le bloc de conformité, isolé du reste de la page par son marqueur. */
    private function driftSection(string $html): string
    {
        $start = strpos($html, 'data-share-drift');
        self::assertNotFalse($start, 'le bloc de conformité doit être rendu');

        $end = strpos($html, 'Assignations', $start);

        return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
    }

    #[Test]
    public function the_panel_names_the_recipients_by_their_se5_name_and_the_root_by_its_word(): void
    {
        [$share, , ] = $this->driftedShare();
        $this->actingAs($this->manager());

        $html = Livewire::test(self::PAGE, ['id' => $share->id])->call('refreshConformity')->html();
        // Le rendu échappe les entités : on décode pour comparer aux libellés
        // métier tels qu'un administrateur les lit.
        $section = html_entity_decode($this->driftSection($html), ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString('(racine)', $section);
        self::assertStringContainsString('alice (utilisateur)', $section);
        self::assertStringContainsString("3e A (groupe d'utilisateurs)", $section);
        self::assertStringContainsString('Modifier', $section);
        self::assertStringContainsString('Lire', $section);
    }

    /**
     * LA NEUTRALITÉ DU BLOC. Aucune entrée brute, aucun mode de permission, aucun
     * nom de groupe système, aucun chemin absolu, aucune commande.
     */
    #[Test]
    public function the_panel_shows_no_raw_permission_no_system_name_and_no_absolute_path(): void
    {
        [$share, , ] = $this->driftedShare();
        $this->actingAs($this->manager());

        $section = strtolower($this->driftSection(
            Livewire::test(self::PAGE, ['id' => $share->id])->call('refreshConformity')->html()
        ));

        foreach ([
            'rwx', 'r-x', ':rx', 'setfacl', 'getfacl', 'user::', 'group:', 'default:', 'mask::', 'other::',
            'domain', 'classe_3emea', 'www-admin', '/var/', 'sudo', 'font-mono',
        ] as $marker) {
            self::assertStringNotContainsString($marker, $section, 'marqueur système dans l\'encart : ' . $marker);
        }
    }

    /**
     * Le bouton de resynchronisation reste, et il ENFILE — il n'écrit pas dans le
     * cycle de la requête.
     */
    #[Test]
    public function the_resync_button_engages_the_reconciliation_without_writing_anything(): void
    {
        Process::fake();
        [$share, , ] = $this->driftedShare();
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $share->id])
            ->call('resync')
            ->assertSet('reconciliationEngaged', true);

        Queue::assertPushed(
            ReconcileNetworkShareJob::class,
            fn (ReconcileNetworkShareJob $job): bool => $job->shareId === (int) $share->id,
        );
        Process::assertNothingRan();
    }

    #[Test]
    public function a_conform_directory_says_so_without_listing_anything(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'conforme', 'name' => 'Conforme']);
        $this->actingAs($this->manager());

        $section = $this->driftSection(
            Livewire::test(self::PAGE, ['id' => $share->id])->call('refreshConformity')->html()
        );

        self::assertStringContainsString('correspondent au paramétrage', $section);
    }
}
