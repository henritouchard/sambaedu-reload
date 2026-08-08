<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\FileBackendName;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Filesystem\PlanStateComparator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Support\RecordingBackend;

/**
 * Epic 34 → story 60.4 — l'AUDIT D'ÉCART, vu depuis l'orchestrateur.
 *
 * Les quatre statuts agrégés survivent (un contrôleur d'environnement les
 * consomme), mais le chemin a changé de nature : on ne compare plus des lignes de
 * permission brutes, on compare un PLAN à une RELECTURE, en vocabulaire de plan.
 * Ce fichier vérifie l'agrégation et l'absence de vocabulaire système ; la table
 * de comparaison elle-même est vérifiée ligne à ligne dans le test du
 * comparateur.
 *
 * Aucune simulation de processus : ce chemin ne lance rien depuis l'orchestrateur.
 */
class NetworkShareDriftTest extends TestCase
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

    /** @param callable(FilePlan): list<NodeObservation> $factory */
    private function observing(callable $factory): void
    {
        $this->backend->inspectUsing = fn (FilePlan $plan): InspectionReport => InspectionReport::covering(
            FileBackendName::Posix,
            $plan,
            $factory($plan),
        );
    }

    private function shareWithUser(string $access = 'rw'): array
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'proj', 'name' => 'Projet']);
        $user = User::factory()->create(['login' => 'alice']);
        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => User::class,
            'assignable_id' => $user->id,
            'access' => $access,
        ]);

        return [$share, $user];
    }

    #[Test]
    public function it_reports_absent_when_the_backend_finds_nothing(): void
    {
        [$share] = $this->shareWithUser();
        $this->observing(static fn (FilePlan $plan): array => [NodeObservation::absent(PlanNode::ROOT_PATH)]);

        self::assertSame(PlanStateComparator::STATUS_ABSENT, $this->service->computeDrift($share)['status']);
    }

    #[Test]
    public function it_reports_conforme_when_the_observed_access_matches_the_plan(): void
    {
        [$share, $user] = $this->shareWithUser('rw');
        $this->observing(static fn (FilePlan $plan): array => [
            NodeObservation::observed(PlanNode::ROOT_PATH, [
                new ObservedGrant(PlanSubject::user((int) $user->id), PlanGrant::VERBS),
            ]),
        ]);

        $drift = $this->service->computeDrift($share);

        self::assertSame(PlanStateComparator::STATUS_CONFORME, $drift['status']);
        self::assertSame([], $drift['nodes'][0]['differences']);
    }

    #[Test]
    public function it_reports_a_drift_naming_the_subject_by_its_internal_identity(): void
    {
        [$share, $user] = $this->shareWithUser('rw');
        $this->observing(static fn (FilePlan $plan): array => [
            NodeObservation::observed(PlanNode::ROOT_PATH, [
                new ObservedGrant(PlanSubject::user((int) $user->id), [PlanGrant::VERB_LIRE]),
            ]),
        ]);

        $drift = $this->service->computeDrift($share);

        self::assertSame(PlanStateComparator::STATUS_DRIFTED, $drift['status']);
        self::assertSame(
            [['subject' => ['type' => 'user', 'id' => (int) $user->id, 'edge_role' => null], 'expected' => PlanGrant::VERBS, 'observed' => [PlanGrant::VERB_LIRE]]],
            $drift['nodes'][0]['differences'],
        );
    }

    #[Test]
    public function it_reports_an_error_when_the_backend_could_not_read(): void
    {
        [$share] = $this->shareWithUser();
        $this->observing(static fn (FilePlan $plan): array => [
            NodeObservation::echec(PlanNode::ROOT_PATH, 'relecture impossible'),
        ]);

        self::assertSame(PlanStateComparator::STATUS_ERROR, $this->service->computeDrift($share)['status']);
    }

    /**
     * Un répertoire dont le nom n'est même pas projetable ne fait pas remonter une
     * exception jusqu'à un écran : c'est un ÉTAT.
     */
    #[Test]
    public function an_unprojectable_directory_is_an_error_state_not_an_exception(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'valide']);
        $share->directory_name = '../evasion';

        self::assertSame(PlanStateComparator::STATUS_ERROR, $this->service->computeDrift($share)['status']);
    }

    /**
     * L'ASSAINISSEMENT : plus une seule ligne de permission dans ce que l'audit
     * rend. C'est ce que la page de détail affichait depuis l'Epic 34.
     */
    #[Test]
    public function the_drift_carries_no_system_vocabulary_at_all(): void
    {
        [$share, $user] = $this->shareWithUser('rw');
        $this->observing(static fn (FilePlan $plan): array => [
            NodeObservation::observed(PlanNode::ROOT_PATH, [
                new ObservedGrant(PlanSubject::user((int) $user->id), [PlanGrant::VERB_LIRE]),
            ]),
        ]);

        $serialized = json_encode($this->service->computeDrift($share), JSON_UNESCAPED_UNICODE);

        foreach (['rwx', ':rx', 'setfacl', 'getfacl', 'user::', 'default:', 'mask::', 'domain', '/var/'] as $marker) {
            self::assertStringNotContainsStringIgnoringCase($marker, (string) $serialized, 'marqueur système : ' . $marker);
        }
    }

    #[Test]
    public function computing_a_drift_runs_no_command_from_the_orchestrator(): void
    {
        Process::fake();
        [$share] = $this->shareWithUser();

        $this->service->computeDrift($share);

        Process::assertNothingRan();
    }
}
