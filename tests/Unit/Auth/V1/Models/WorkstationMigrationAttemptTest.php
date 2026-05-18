<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Models;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.11 — AC6.2 / AC8.1.
 *
 * Tests modèle `WorkstationMigrationAttempt` — scopes + truncate mutator.
 */
class WorkstationMigrationAttemptTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
    }

    #[Test]
    public function scope_succeeded_returns_only_enrolled(): void
    {
        WorkstationMigrationAttempt::factory()->succeeded()->create();
        WorkstationMigrationAttempt::factory()->succeeded()->create();
        WorkstationMigrationAttempt::factory()->failed()->create();
        WorkstationMigrationAttempt::factory()->started()->create();

        $this->assertSame(2, WorkstationMigrationAttempt::query()->succeeded()->count());
    }

    #[Test]
    public function scope_failed_returns_only_failed(): void
    {
        WorkstationMigrationAttempt::factory()->succeeded()->create();
        WorkstationMigrationAttempt::factory()->failed()->create();
        WorkstationMigrationAttempt::factory()->failed()->create();

        $this->assertSame(2, WorkstationMigrationAttempt::query()->failed()->count());
    }

    #[Test]
    public function scope_recent_filters_by_started_at(): void
    {
        WorkstationMigrationAttempt::factory()->create([
            'started_at' => Carbon::now()->subDays(2),
        ]);
        WorkstationMigrationAttempt::factory()->create([
            'started_at' => Carbon::now()->subDays(10),
        ]);
        WorkstationMigrationAttempt::factory()->create([
            'started_at' => Carbon::now(),
        ]);

        $this->assertSame(2, WorkstationMigrationAttempt::query()->recent(7)->count());
        $this->assertSame(3, WorkstationMigrationAttempt::query()->recent(30)->count());
    }

    #[Test]
    public function error_message_is_truncated_to_1024_chars(): void
    {
        $longMessage = str_repeat('A', 2000);
        $row = WorkstationMigrationAttempt::factory()->failed()->create([
            'error_message' => $longMessage,
        ]);

        $row->refresh();
        $this->assertSame(1024, mb_strlen((string) $row->error_message));
    }

    #[Test]
    public function error_message_null_is_preserved(): void
    {
        $row = WorkstationMigrationAttempt::factory()->create(['error_message' => null]);
        $row->refresh();
        $this->assertNull($row->error_message);
    }

    #[Test]
    public function status_constants_match_storage(): void
    {
        $row = WorkstationMigrationAttempt::factory()->started()->create();
        $this->assertSame(WorkstationMigrationAttempt::STATUS_STARTED, $row->status);
        $this->assertNull($row->workstation_uuid);

        $row2 = WorkstationMigrationAttempt::factory()->succeeded()->create();
        $this->assertSame(WorkstationMigrationAttempt::STATUS_ENROLLED, $row2->status);

        $row3 = WorkstationMigrationAttempt::factory()->failed()->create();
        $this->assertSame(WorkstationMigrationAttempt::STATUS_FAILED, $row3->status);
    }
}
