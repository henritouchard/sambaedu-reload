<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 29.9 — Test de régression : `Queue::before` (AppServiceProvider) ne
 * réécrit PAS `created_at` sur un retry (chemin UPDATE — même task_uuid), et
 * le pose correctement lors du premier passage (chemin INSERT).
 *
 * Patron : iso-story 29.7 (préservation `created_at` pivot `capability_assignments`).
 * Approche : fire `JobProcessing` directement via event() — Queue::before() enregistre
 * son listener sur l'event dispatcher ($app['events']->listen(JobProcessing::class, …)).
 * Pas besoin de dispatcher un vrai job.
 */
class QueueTaskRunCreatedAtPreservationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeJobMock(string $uuid, string $displayName = 'TestJob'): Job
    {
        /** @var Job&\Mockery\MockInterface $job */
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'uuid'        => $uuid,
            'displayName' => $displayName,
        ]);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('getRawBody')->andReturn('{}');

        return $job;
    }

    private function fireBeforeHandler(string $uuid, string $displayName = 'TestJob'): void
    {
        event(new JobProcessing('sync', $this->makeJobMock($uuid, $displayName)));
    }

    private function fireAfterHandler(string $uuid, string $displayName = 'TestJob'): void
    {
        event(new JobProcessed('sync', $this->makeJobMock($uuid, $displayName)));
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    #[Test]
    public function inserting_a_new_queue_task_run_sets_created_at(): void
    {
        // AC#2 — chemin INSERT : created_at et updated_at doivent être posés (non nuls).
        $uuid = 'test-insert-' . uniqid();

        $this->fireBeforeHandler($uuid, 'MyJob');

        $row = DB::table('queue_task_runs')->where('task_uuid', $uuid)->first();

        self::assertNotNull($row, 'La ligne doit exister après l\'INSERT');
        self::assertNotNull($row->created_at, 'INSERT : created_at doit être posé (non nul)');
        self::assertNotNull($row->updated_at, 'INSERT : updated_at doit être posé (non nul)');
        // AC#2 — created_at ≈ now() (pas seulement non-nul) et = updated_at à la création.
        // Comparaison via Carbon (robuste cross-driver : SQLite vs PG, microsecondes).
        self::assertTrue(
            Carbon::parse($row->created_at)->diffInSeconds(now()) <= 5,
            'INSERT : created_at doit être posé à ≈ now()',
        );
        self::assertSame(
            Carbon::parse($row->created_at)->toDateTimeString(),
            Carbon::parse($row->updated_at)->toDateTimeString(),
            'INSERT : created_at et updated_at doivent coïncider à la création',
        );
        self::assertSame('running', $row->status, 'INSERT : status doit être running');
        self::assertSame('MyJob', $row->job_name, 'INSERT : job_name doit être posé');
    }

    #[Test]
    public function re_dispatching_a_task_uuid_preserves_original_created_at(): void
    {
        // AC#1 & #4 (cœur) — chemin UPDATE (retry / re-dispatch du même task_uuid).
        // Technique : figer created_at dans le PASSÉ avant le second passage ;
        // si updateOrInsert réécrit created_at à now(), l'assertion échoue.
        // Iso-patron story 29.7 (CapabilitiesOverrideAuditTest::re_editing_an_override…).
        $uuid = 'test-update-' . uniqid();

        // 1 — Premier passage (INSERT).
        $this->fireBeforeHandler($uuid, 'RetryJob');

        // 2 — Figer created_at ET updated_at dans le passé (dates DISTINCTES) :
        //   - created_at -3j prouve la non-réécriture sur UPDATE.
        //   - updated_at -2j (≠ now) prouve que l'UPDATE le fait réellement AVANCER
        //     (sinon l'assertion serait trivialement vraie, le bug passerait inaperçu).
        $frozenCreatedAt  = now()->subDays(3)->toDateTimeString();
        $frozenUpdatedAt  = now()->subDays(2)->toDateTimeString();
        DB::table('queue_task_runs')
            ->where('task_uuid', $uuid)
            ->update(['created_at' => $frozenCreatedAt, 'updated_at' => $frozenUpdatedAt]);

        // 3 — Second passage (chemin UPDATE — même task_uuid).
        $this->fireBeforeHandler($uuid, 'RetryJob');

        // 4 — Assertions.
        $row = DB::table('queue_task_runs')->where('task_uuid', $uuid)->first();

        self::assertNotNull($row, 'La ligne doit toujours exister après l\'UPDATE');

        // `created_at` doit être STRICTEMENT INCHANGÉ (égal à la date figée).
        // Comparaison via Carbon (robuste cross-driver, pas une égalité de chaînes).
        self::assertTrue(
            Carbon::parse($row->created_at)->equalTo(Carbon::parse($frozenCreatedAt)),
            'UPDATE ne doit PAS réécrire created_at (Story 29.9 — bug pré-existant)',
        );

        // `updated_at` doit avoir AVANCÉ par rapport à sa valeur figée (-2j).
        self::assertTrue(
            Carbon::parse($row->updated_at)->greaterThan(Carbon::parse($frozenUpdatedAt)),
            'UPDATE doit rafraîchir updated_at à now() (AC#1)',
        );

        // `started_at` doit également avoir été mis à jour (reset intentionnel).
        self::assertTrue(
            Carbon::parse($row->started_at)->greaterThan(Carbon::parse($frozenUpdatedAt)),
            'UPDATE doit rafraîchir started_at à now() (AC#1 — reset intentionnel)',
        );

        // `status` doit être 'running' (reset intentionnel du retry).
        self::assertSame('running', $row->status, 'AC#1 : status doit être running');
    }

    #[Test]
    public function after_handler_inserting_a_fresh_run_sets_created_at(): void
    {
        // AC#3 / #7 — course rare : si `before` n'a pas tourné, l'INSERT par
        // `after` doit lui aussi poser `created_at` (closure iso-`before`),
        // jamais une ligne `created_at = NULL`.
        $uuid = 'test-after-' . uniqid();

        $this->fireAfterHandler($uuid, 'OrphanAfterJob');

        $row = DB::table('queue_task_runs')->where('task_uuid', $uuid)->first();

        self::assertNotNull($row, 'after seul doit créer la ligne');
        self::assertNotNull(
            $row->created_at,
            'after en INSERT doit poser created_at (jamais NULL — #7)',
        );
        self::assertSame('done', $row->status, 'after : status doit être done');
    }
}
