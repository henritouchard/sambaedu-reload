<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\FileBackendOutcome;
use App\Jobs\ReconcileNetworkShareJob;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\QuotaAuditLog;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Support\RecordingBackend;

/**
 * Story 34.1 → 60.4 — l'ORCHESTRATEUR, testé AU-DESSUS DE LA LIGNE.
 *
 * **Aucune simulation de processus dans ce fichier, et c'est une garde.** Les
 * tests historiques de ce service simulaient `mkdir`, `setfacl`, `chown` et
 * `chgrp` : c'était cohérent, puisque le service les lançait. Il ne les lance
 * plus. Un test d'orchestrateur qui aurait encore besoin d'une simulation
 * d'exécution signalerait que la coupe a fui — et il existe donc ici un test qui
 * VÉRIFIE qu'aucune commande n'est lancée par ce chemin. Les gestes système sont
 * testés là où ils vivent, sous la ligne.
 *
 * L'exécution est remplacée en substituant un double à l'implémentation que le
 * registre résout : l'orchestrateur emprunte le vrai chemin (projection,
 * résolution par la colonne, délégation), et seule la couche qui écrit change.
 */
class NetworkShareServiceTest extends TestCase
{
    use RefreshDatabase;

    private NetworkShareService $service;

    private RecordingBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->backend = new RecordingBackend();
        $this->app->instance(PosixFileBackend::class, $this->backend);

        $this->service = app(NetworkShareService::class);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function assign(NetworkShare $share, string $type, int $id, string $access = 'ro'): void
    {
        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'access' => $access,
        ]);
    }

    // =========================================================================
    // Nommage — la seule règle qui reste au-dessus de la ligne
    // =========================================================================

    #[Test]
    public function the_directory_name_rule_rejects_traversal_and_metacharacters(): void
    {
        foreach (['../etc', 'a/b', 'foo bar', 'evil;rm', '.hidden', 'a$b', ''] as $bad) {
            self::assertFalse($this->service->isValidDirectoryName($bad), "doit refuser : {$bad}");
        }
        foreach (['direction', 'Classe_3A', 'docs.v2', 'a-b_c'] as $good) {
            self::assertTrue($this->service->isValidDirectoryName($good), "doit accepter : {$good}");
        }
        self::assertFalse($this->service->isValidDirectoryName(null));
    }

    // =========================================================================
    // Délégation
    // =========================================================================

    #[Test]
    public function provisioning_projects_a_neutral_plan_and_delegates_to_the_backend_of_the_column(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'commun', 'name' => 'Commun']);
        $alice = User::factory()->create(['login' => 'alice']);
        $this->assign($share, User::class, $alice->id, 'rw');

        self::assertTrue($this->service->provision($share, 'qa'));

        self::assertSame(['provision'], $this->backend->calls);
        $plan = $this->backend->plans[0];
        self::assertSame('commun', $plan->rootPath);
        self::assertCount(1, $plan->nodes);
        self::assertSame(PlanSubject::TYPE_USER, $plan->nodes[0]->grants[0]->subject->type);
        self::assertSame((int) $alice->id, $plan->nodes[0]->grants[0]->subject->id);
    }

    /**
     * LA GARDE DE LA COUPE, côté tests : ce chemin ne lance AUCUNE commande. Si
     * un jour il en lance une, c'est que la dérivation des permissions est
     * remontée au-dessus de la ligne.
     */
    #[Test]
    public function the_orchestrator_itself_never_runs_a_single_command(): void
    {
        Process::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'aucunecommande']);

        $this->service->provision($share, 'qa');
        $this->service->deprovision($share, 'qa');
        $this->service->computeDrift($share);

        Process::assertNothingRan();
    }

    #[Test]
    public function a_parc_assignment_never_becomes_a_grant_in_the_plan(): void
    {
        $wg = WorkstationGroup::factory()->logical()->create();
        $share = NetworkShare::factory()->create(['directory_name' => 'montage']);
        $this->assign($share, WorkstationGroup::class, $wg->id, 'rw');

        $this->service->provision($share);

        self::assertSame([], $this->backend->plans[0]->nodes[0]->grants);
    }

    #[Test]
    public function a_group_assignment_becomes_an_internal_identity_never_a_system_name(): void
    {
        $group = UserGroup::create(['name' => '3emeA', 'type' => 'classe']);
        $share = NetworkShare::factory()->create(['directory_name' => 'classe3a']);
        $this->assign($share, UserGroup::class, $group->id, 'rw');

        $this->service->provision($share);

        $subject = $this->backend->plans[0]->nodes[0]->grants[0]->subject;
        self::assertSame(PlanSubject::TYPE_USER_GROUP, $subject->type);
        self::assertSame((int) $group->id, $subject->id);
    }

    // =========================================================================
    // Le booléen d'adaptation est CALCULÉ, jamais lu dans un rapport
    // =========================================================================

    #[Test]
    public function the_boolean_kept_for_historic_callers_is_derived_from_the_report_lists(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'derive']);

        $this->backend->provisionOutcome = FileBackendOutcome::Conforme;
        self::assertTrue($this->service->provision($share));

        $this->backend->provisionOutcome = FileBackendOutcome::Echec;
        self::assertFalse($this->service->provision($share));

        // Un déclin n'est pas un échec, mais ce n'est pas non plus la convergence :
        // l'appelant historique doit le savoir.
        $this->backend->provisionOutcome = FileBackendOutcome::NonImplemente;
        self::assertFalse($this->service->provision($share));
    }

    #[Test]
    public function an_unprojectable_directory_fails_explicitly_and_delegates_nothing(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'valide']);
        $share->directory_name = '../evasion';

        self::assertFalse($this->service->provision($share));
        self::assertSame([], $this->backend->calls);
    }

    // =========================================================================
    // Régimes d'exécution (AC4)
    // =========================================================================

    #[Test]
    public function a_screen_enqueues_and_writes_nothing_in_the_request(): void
    {
        Queue::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'enfile']);

        self::assertTrue($this->service->queueReconciliation($share, 'ui'));

        Queue::assertPushed(
            ReconcileNetworkShareJob::class,
            fn (ReconcileNetworkShareJob $job): bool => $job->shareId === (int) $share->id && $job->performedBy === 'ui',
        );
        self::assertSame([], $this->backend->calls, 'aucune écriture ne doit avoir lieu dans le cycle de la requête');
    }

    #[Test]
    public function the_enqueued_state_is_immediately_readable_and_says_engaged_not_done(): void
    {
        Bus::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'attente']);

        $this->service->queueReconciliation($share);

        $report = $this->service->lastReport($share);
        self::assertNotNull($report);
        self::assertSame(FileBackendOutcome::EnAttente->value, $report['nodes'][0]['outcome']);
    }

    #[Test]
    public function a_direct_reconciliation_replaces_the_pending_state_by_the_real_one(): void
    {
        Bus::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'remplace']);
        $this->service->queueReconciliation($share);

        $this->service->reconcile($share, 'cli');

        $report = $this->service->lastReport($share);
        self::assertSame(FileBackendOutcome::Applique->value, $report['nodes'][0]['outcome']);
    }

    /**
     * L'ÉCHEC D'UN GESTE ENFILÉ DOIT AVOIR UN DESTINATAIRE.
     *
     * Le service absorbe l'erreur (il rend `null`), donc rien ne remonte au
     * traitement en file : ni réessai, ni consignation d'échec. Si le rapport
     * « en attente » posé à l'enfilage restait en place, l'écran dirait « c'est
     * engagé » pour toujours, et le seul témoin serait une ligne de journal que
     * personne ne lit. C'est la signature de défaut que cet epic traque.
     */
    #[Test]
    public function a_queued_reconciliation_that_fails_replaces_the_pending_state_by_a_readable_failure(): void
    {
        Bus::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'echoue']);
        $this->service->queueReconciliation($share);
        self::assertNotNull($this->service->lastReport($share), 'l\'état « en attente » doit exister avant');

        $this->backend->provisionThrows = true;
        self::assertNull($this->service->reconcile($share, 'file'));

        self::assertNull(
            $this->service->lastReport($share),
            'le rapport « en attente » ne doit pas survivre à l\'échec du geste qu\'il annonçait',
        );
        self::assertNotNull($this->service->lastFailure($share));
    }

    /**
     * Et il ne DOIT PAS survivre à la réconciliation suivante qui aboutit :
     * un échec qui resterait affiché après correction serait un second mensonge,
     * symétrique du premier.
     */
    #[Test]
    public function a_later_successful_reconciliation_clears_the_recorded_failure(): void
    {
        Bus::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'reprend']);

        $this->backend->provisionThrows = true;
        $this->service->reconcile($share);
        self::assertNotNull($this->service->lastFailure($share));

        $this->backend->provisionThrows = false;
        $this->service->reconcile($share);

        self::assertNull($this->service->lastFailure($share));
        self::assertNotNull($this->service->lastReport($share));
    }

    // =========================================================================
    // Trace applicative
    // =========================================================================

    #[Test]
    public function the_audit_row_keeps_its_shape_and_its_author(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'audite']);

        $this->service->provision($share, 'qa-runbook');

        $row = QuotaAuditLog::where('target_type', 'share')
            ->where('target_name', 'audite')
            ->latest('id')
            ->first();

        self::assertNotNull($row);
        self::assertSame('provision_share', $row->action);
        self::assertSame('/var/sambaedu', $row->partition);
        self::assertSame('qa-runbook', $row->performed_by);
    }

    #[Test]
    public function deprovisioning_delegates_and_traces_too(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'revoque']);

        self::assertTrue($this->service->deprovision($share, 'qa'));

        self::assertSame(['deprovision'], $this->backend->calls);
        self::assertNotNull(QuotaAuditLog::where('target_name', 'revoque')->where('action', 'deprovision_share')->first());
    }
}
