<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 29.10 — Test de la commande `queue-task-runs:prune`.
 *
 * Couvre :
 *  - runs done > done_days  → supprimés ;
 *  - runs failed > failed_days → supprimés ;
 *  - runs done < done_days  → conservés ;
 *  - runs failed < failed_days → conservés ;
 *  - runs running (quel que soit leur âge, sans finished_at/failed_at) → TOUJOURS conservés ;
 *  - seuils config sambaedu.workers.retention.{done,failed}_days respectés.
 */
class PruneQueueTaskRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function insertRun(
        string $uuid,
        string $status,
        ?string $finishedAt = null,
        ?string $failedAt = null,
        ?string $startedAt = null,
    ): void {
        DB::table('queue_task_runs')->insert([
            'task_uuid'  => $uuid,
            'queue'      => 'default',
            'job_name'   => 'TestJob',
            'status'     => $status,
            'started_at' => $startedAt ?? now()->toDateTimeString(),
            'finished_at' => $finishedAt,
            'failed_at'  => $failedAt,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    #[Test]
    public function done_runs_older_than_done_days_are_deleted(): void
    {
        $old = 'done-old-' . uniqid();
        $recent = 'done-recent-' . uniqid();

        // done, 15j — doit être supprimé (> 14j par défaut)
        $this->insertRun($old, 'done', now()->subDays(15)->toDateTimeString());
        // done, 5j — doit être conservé
        $this->insertRun($recent, 'done', now()->subDays(5)->toDateTimeString());

        $this->artisan('queue-task-runs:prune')->assertSuccessful();

        self::assertNull(
            DB::table('queue_task_runs')->where('task_uuid', $old)->first(),
            'Le run done ancien (>14j) doit être supprimé',
        );
        self::assertNotNull(
            DB::table('queue_task_runs')->where('task_uuid', $recent)->first(),
            'Le run done récent (<14j) doit être conservé',
        );
    }

    #[Test]
    public function failed_runs_older_than_failed_days_are_deleted(): void
    {
        $old = 'failed-old-' . uniqid();
        $recent = 'failed-recent-' . uniqid();

        // failed, 31j — doit être supprimé (> 30j par défaut)
        $this->insertRun($old, 'failed', null, now()->subDays(31)->toDateTimeString());
        // failed, 10j — doit être conservé
        $this->insertRun($recent, 'failed', null, now()->subDays(10)->toDateTimeString());

        $this->artisan('queue-task-runs:prune')->assertSuccessful();

        self::assertNull(
            DB::table('queue_task_runs')->where('task_uuid', $old)->first(),
            'Le run failed ancien (>30j) doit être supprimé',
        );
        self::assertNotNull(
            DB::table('queue_task_runs')->where('task_uuid', $recent)->first(),
            'Le run failed récent (<30j) doit être conservé',
        );
    }

    #[Test]
    public function running_runs_are_never_deleted_regardless_of_age(): void
    {
        $veryOld = 'running-very-old-' . uniqid();
        $normal = 'running-normal-' . uniqid();

        // running, 60j, sans finished_at ni failed_at — doit toujours être conservé
        $this->insertRun($veryOld, 'running', null, null, now()->subDays(60)->toDateTimeString());
        // running, 1j — doit être conservé
        $this->insertRun($normal, 'running', null, null, now()->subDays(1)->toDateTimeString());

        $this->artisan('queue-task-runs:prune')->assertSuccessful();

        self::assertNotNull(
            DB::table('queue_task_runs')->where('task_uuid', $veryOld)->first(),
            'Le run running très ancien doit être préservé (worker potentiellement bloqué)',
        );
        self::assertNotNull(
            DB::table('queue_task_runs')->where('task_uuid', $normal)->first(),
            'Le run running récent doit être préservé',
        );
    }

    #[Test]
    public function config_thresholds_are_respected(): void
    {
        // On override les seuils : done=3j, failed=5j
        Config::set('sambaedu.workers.retention.done_days', 3);
        Config::set('sambaedu.workers.retention.failed_days', 5);

        $doneOver  = 'done-over-' . uniqid();
        $doneUnder = 'done-under-' . uniqid();
        $failedOver  = 'failed-over-' . uniqid();
        $failedUnder = 'failed-under-' . uniqid();

        // done > 3j → supprimé
        $this->insertRun($doneOver, 'done', now()->subDays(4)->toDateTimeString());
        // done < 3j → conservé
        $this->insertRun($doneUnder, 'done', now()->subDays(2)->toDateTimeString());
        // failed > 5j → supprimé
        $this->insertRun($failedOver, 'failed', null, now()->subDays(6)->toDateTimeString());
        // failed < 5j → conservé
        $this->insertRun($failedUnder, 'failed', null, now()->subDays(4)->toDateTimeString());

        $this->artisan('queue-task-runs:prune')->assertSuccessful();

        self::assertNull(
            DB::table('queue_task_runs')->where('task_uuid', $doneOver)->first(),
            'done >3j (config override) doit être supprimé',
        );
        self::assertNotNull(
            DB::table('queue_task_runs')->where('task_uuid', $doneUnder)->first(),
            'done <3j (config override) doit être conservé',
        );
        self::assertNull(
            DB::table('queue_task_runs')->where('task_uuid', $failedOver)->first(),
            'failed >5j (config override) doit être supprimé',
        );
        self::assertNotNull(
            DB::table('queue_task_runs')->where('task_uuid', $failedUnder)->first(),
            'failed <5j (config override) doit être conservé',
        );
    }
}
