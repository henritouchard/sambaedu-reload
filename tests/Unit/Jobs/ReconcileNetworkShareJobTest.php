<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\InvalidBackendReportException;
use App\Jobs\ReconcileNetworkShareJob;
use App\Models\NetworkShare;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Support\RecordingBackend;

/**
 * Story 60.4 — LA CHARGE UTILE DU TRAITEMENT ENFILÉ.
 *
 * Ce que ces tests tiennent : des identifiants, rien d'autre — et la
 * démonstration que la garde de la story 60.3 échoue BRUYAMMENT le jour où
 * quelqu'un y glisserait un rapport ou un plan.
 */
class ReconcileNetworkShareJobTest extends TestCase
{
    use RefreshDatabase;

    private RecordingBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->backend = new RecordingBackend();
        $this->app->instance(PosixFileBackend::class, $this->backend);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    /**
     * Le contrôle DIRECT : aucune propriété du traitement n'est autre chose qu'un
     * scalaire. Un rapport ou un plan glissé ici serait vu immédiatement, sans
     * dépendre d'une inspection de chaîne sérialisée.
     */
    #[Test]
    public function the_payload_carries_identifiers_and_nothing_else(): void
    {
        $job = new ReconcileNetworkShareJob(12, 'qa');

        foreach ((new ReflectionObject($job))->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($job);

            self::assertTrue(
                $value === null || is_scalar($value) || is_array($value),
                sprintf(
                    'la propriété « %s » du traitement enfilé porte un objet (%s) : la charge utile doit '
                    . 'être faite d\'identifiants — la source autoritaire est la base, et un plan sérialisé '
                    . 'serait un instantané périmé au moment de l\'exécution.',
                    $property->getName(),
                    get_debug_type($value),
                ),
            );
        }
    }

    #[Test]
    public function the_payload_serialises_without_any_report_or_plan_in_it(): void
    {
        $serialized = serialize(new ReconcileNetworkShareJob(12, 'qa'));

        foreach (['ReconciliationReport', 'InspectionReport', 'FilePlan', 'PlanNode', 'NodeReconciliation'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized, 'objet interdit dans la charge utile : ' . $forbidden);
        }
        self::assertStringContainsString('12', $serialized);
    }

    /**
     * MÉTA-TEST — la garde de la story 60.3 est bien celle qui protège cette file.
     *
     * Le jour où quelqu'un mettra un rapport dans une charge utile, il ne verra pas
     * un test rouge quelque part : la sérialisation ÉCHOUERA, au point exact du
     * mésusage. On le PROUVE ici, plutôt que de le promettre en commentaire.
     */
    #[Test]
    public function putting_a_report_in_a_queued_payload_fails_loudly_at_the_point_of_misuse(): void
    {
        $plan = new FilePlan('@partage', 'proj', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
        ]);
        $report = $this->backend->provision($plan);

        // Une charge utile de file EST une structure sérialisée. On en fabrique
        // une qui porte un rapport, exactement comme le ferait une propriété de
        // traitement enfilé — et la sérialisation refuse.
        $this->expectException(InvalidBackendReportException::class);
        serialize(['shareId' => 12, 'report' => $report]);
    }

    #[Test]
    public function it_reconciles_the_directory_by_reprojecting_from_the_authoritative_source(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'enfile']);

        (new ReconcileNetworkShareJob((int) $share->id, 'file'))->handle(app(\App\Services\Filesystem\NetworkShareService::class));

        self::assertSame(['provision'], $this->backend->calls);
        self::assertSame('enfile', $this->backend->plans[0]->rootPath);
    }

    /**
     * Supprimé entre l'enfilage et l'exécution : rien à réconcilier, et surtout
     * rien à RECRÉER. Le déprovisionnement a eu lieu synchronement avant la
     * suppression de la ligne.
     */
    #[Test]
    public function a_vanished_directory_is_a_no_op_never_a_recreation(): void
    {
        (new ReconcileNetworkShareJob(999_999))->handle(app(\App\Services\Filesystem\NetworkShareService::class));

        self::assertSame([], $this->backend->calls);
    }

    #[Test]
    public function it_runs_on_a_persistent_queue_connection(): void
    {
        self::assertSame('database', (new ReconcileNetworkShareJob(1))->connection);
    }
}
