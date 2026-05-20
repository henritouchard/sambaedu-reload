<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Auth\V1\Models\WorkstationMigrationStatus;
use App\Models\Workstation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 16.13bis — AC4.
 *
 * Tests unit pour `Workstation::migrationStatus()` (HasOne) + accessor
 * `migrated` + scopes `migrated()` / `notMigrated()`.
 */
final class WorkstationMigrationTest extends TestCase
{
    use IssuesWorkstationJwt;
    use RefreshDatabase;
    use SeedsWorkstationConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorkstationContextSchemas();
        $this->ensureAuthV1Tables();
    }

    #[Test]
    public function migration_status_relation_returns_null_when_no_row(): void
    {
        $w = Workstation::create([
            'name' => 'post-no-status',
            'uuid' => 'aaaaaaaa-1111-4aaa-8aaa-aaaaaaaaaaaa',
        ]);

        self::assertNull($w->migrationStatus);
    }

    #[Test]
    public function migration_status_relation_returns_instance_when_row_exists(): void
    {
        $uuid = 'bbbbbbbb-2222-4bbb-8bbb-bbbbbbbbbbbb';
        $w = Workstation::create([
            'name' => 'post-with-status',
            'uuid' => $uuid,
        ]);
        WorkstationMigrationStatus::create([
            'workstation_uuid' => $uuid,
            'migrated_at' => now(),
            'os' => 'linux',
        ]);

        $w->refresh();

        self::assertNotNull($w->migrationStatus);
        self::assertSame($uuid, $w->migrationStatus->workstation_uuid);
        self::assertSame('linux', $w->migrationStatus->os);
    }

    #[Test]
    public function migrated_accessor_returns_false_then_true_after_status_created(): void
    {
        $uuid = 'cccccccc-3333-4ccc-8ccc-cccccccccccc';
        $w = Workstation::create([
            'name' => 'post-flipping',
            'uuid' => $uuid,
        ]);

        self::assertFalse($w->migrated);

        WorkstationMigrationStatus::create([
            'workstation_uuid' => $uuid,
            'migrated_at' => now(),
            'os' => 'windows',
        ]);

        // Force refresh pour invalider la relation cache éventuelle.
        $w->unsetRelation('migrationStatus');

        self::assertTrue($w->migrated);
    }

    #[Test]
    public function migrated_accessor_returns_false_when_uuid_null(): void
    {
        $w = Workstation::create([
            'name' => 'post-no-uuid',
            'uuid' => null,
        ]);

        self::assertFalse($w->migrated);
    }

    #[Test]
    public function scope_migrated_returns_only_migrated_workstations(): void
    {
        $u1 = 'dddddddd-4444-4ddd-8ddd-ddddddddddd1';
        $u2 = 'dddddddd-4444-4ddd-8ddd-ddddddddddd2';
        $u3 = 'dddddddd-4444-4ddd-8ddd-ddddddddddd3';

        Workstation::create(['name' => 'p1', 'uuid' => $u1]);
        Workstation::create(['name' => 'p2', 'uuid' => $u2]);
        Workstation::create(['name' => 'p3', 'uuid' => $u3]);

        // Seul p1 et p2 migrés.
        WorkstationMigrationStatus::create(['workstation_uuid' => $u1, 'migrated_at' => now(), 'os' => 'windows']);
        WorkstationMigrationStatus::create(['workstation_uuid' => $u2, 'migrated_at' => now(), 'os' => 'linux']);

        $migratedNames = Workstation::migrated()->pluck('name')->sort()->values()->all();

        self::assertSame(['p1', 'p2'], $migratedNames);
    }

    #[Test]
    public function scope_not_migrated_returns_only_non_migrated_workstations(): void
    {
        $u1 = 'eeeeeeee-5555-4eee-8eee-eeeeeeeeeee1';
        $u2 = 'eeeeeeee-5555-4eee-8eee-eeeeeeeeeee2';

        Workstation::create(['name' => 'pa', 'uuid' => $u1]);
        Workstation::create(['name' => 'pb', 'uuid' => $u2]);

        WorkstationMigrationStatus::create(['workstation_uuid' => $u1, 'migrated_at' => now(), 'os' => 'windows']);

        $notMigratedNames = Workstation::notMigrated()->pluck('name')->all();

        self::assertSame(['pb'], $notMigratedNames);
    }
}
