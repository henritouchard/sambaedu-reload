<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Models;

use App\Auth\V1\Models\WorkstationMigrationStatus;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.11 — AC6.1 / AC8.1.
 * Correction post-review #10 : bootstrap_token_used_md5 → bootstrap_token_hash_prefix.
 *
 * Tests modèle `WorkstationMigrationStatus`.
 */
class WorkstationMigrationStatusTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
    }

    #[Test]
    public function factory_creates_a_valid_row(): void
    {
        $row = WorkstationMigrationStatus::factory()->create();

        $this->assertSame(1, WorkstationMigrationStatus::query()->count());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f-]{36}$/i',
            $row->workstation_uuid,
        );
    }

    #[Test]
    public function migrated_at_is_cast_to_carbon(): void
    {
        $row = WorkstationMigrationStatus::factory()->create([
            'migrated_at' => '2026-05-18 10:00:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $row->migrated_at);
        $this->assertSame('2026-05-18 10:00:00', $row->migrated_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function scope_migrated_returns_rows_with_migrated_at_not_null(): void
    {
        WorkstationMigrationStatus::factory()->count(3)->create();

        $this->assertSame(3, WorkstationMigrationStatus::query()->migrated()->count());
    }

    #[Test]
    public function workstation_uuid_is_unique(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        WorkstationMigrationStatus::factory()->forUuid($uuid)->create();

        $this->expectException(\Illuminate\Database\QueryException::class);
        WorkstationMigrationStatus::factory()->forUuid($uuid)->create();
    }

    #[Test]
    public function for_uuid_state_pins_workstation_uuid(): void
    {
        $uuid = '22222222-2222-4222-8222-222222222222';
        $row = WorkstationMigrationStatus::factory()->forUuid($uuid)->create();

        $this->assertSame($uuid, $row->workstation_uuid);
    }

    #[Test]
    public function for_os_state_pins_os(): void
    {
        $row = WorkstationMigrationStatus::factory()->forOs('linux')->create();
        $this->assertSame('linux', $row->os);
    }
}
