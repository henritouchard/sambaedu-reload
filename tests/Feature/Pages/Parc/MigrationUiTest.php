<?php

declare(strict_types=1);

namespace Tests\Feature\Pages\Parc;

use App\Auth\V1\Models\WorkstationMigrationStatus;
use App\Models\Workstation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 16.13bis — AC5.
 *
 * Tests Feature UI parc : colonne « Migration », filtre `migrationFilter`,
 * compteur « X/Y postes migrés » (Eloquent scope + repository).
 *
 * Note : on teste ici la **couche service+repository** plutôt que le
 * rendu Livewire complet (la page est un single-file component avec
 * `WorkstationGroupService` injecté, le rendu blade dépend de
 * permissions/middleware sambaedu.auth qu'on ne mocke pas ici).
 */
final class MigrationUiTest extends TestCase
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
    public function machine_stats_returns_migrated_count_xy(): void
    {
        $u1 = 'aabbccdd-1111-4111-8111-111111111111';
        $u2 = 'aabbccdd-2222-4222-8222-222222222222';
        $u3 = 'aabbccdd-3333-4333-8333-333333333333';

        Workstation::create(['name' => 'm1', 'uuid' => $u1, 'status' => 'active']);
        Workstation::create(['name' => 'm2', 'uuid' => $u2, 'status' => 'active']);
        Workstation::create(['name' => 'm3', 'uuid' => $u3, 'status' => 'active']);

        WorkstationMigrationStatus::create(['workstation_uuid' => $u1, 'migrated_at' => now(), 'os' => 'windows']);
        WorkstationMigrationStatus::create(['workstation_uuid' => $u2, 'migrated_at' => now(), 'os' => 'linux']);

        $service = app(\App\Services\Parc\WorkstationGroupService::class);

        $stats = $service->getMachineStats();

        self::assertSame(3, $stats['total'] ?? 0);
        self::assertSame(2, $stats['migrated'] ?? 0);
    }

    #[Test]
    public function list_machines_with_migration_filter_returns_only_migrated(): void
    {
        $uMig = 'eeeeffff-1111-4111-8111-111111111111';
        $uNot = 'eeeeffff-2222-4222-8222-222222222222';

        Workstation::create(['name' => 'host-migre', 'uuid' => $uMig]);
        Workstation::create(['name' => 'host-non-migre', 'uuid' => $uNot]);

        WorkstationMigrationStatus::create(['workstation_uuid' => $uMig, 'migrated_at' => now(), 'os' => 'windows']);

        $service = app(\App\Services\Parc\WorkstationGroupService::class);

        $paginator = $service->listMachines(
            perPage: 50,
            migrationFilter: 'migrated',
        );

        $names = collect($paginator->items())->pluck('name')->all();
        self::assertSame(['host-migre'], $names, 'Filter `migrated` doit renvoyer uniquement les postes ayant une row status.');

        // Inversement, filter `not-migrated`.
        $paginatorNot = $service->listMachines(
            perPage: 50,
            migrationFilter: 'not-migrated',
        );
        $notNames = collect($paginatorNot->items())->pluck('name')->all();
        self::assertSame(['host-non-migre'], $notNames);
    }

    #[Test]
    public function machine_migrated_accessor_reflects_status_row(): void
    {
        $uuid = 'cccccccc-1234-4234-8234-cccccccccccc';
        $w = Workstation::create(['name' => 'check-accessor', 'uuid' => $uuid]);

        self::assertFalse($w->migrated, 'Sans row migration_status → false');

        WorkstationMigrationStatus::create(['workstation_uuid' => $uuid, 'migrated_at' => now(), 'os' => 'linux']);
        $w->unsetRelation('migrationStatus');

        self::assertTrue($w->migrated, 'Avec row migration_status → true');
    }
}
