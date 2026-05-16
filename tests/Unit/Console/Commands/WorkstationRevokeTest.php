<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Auth\V1\Models\WorkstationJwtRevocation;
use App\Auth\V1\Models\WorkstationRefreshToken;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — T7.3.
 *
 * Tests commande Artisan `workstation:revoke <uuid>`.
 */
class WorkstationRevokeTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
    }

    #[Test]
    public function it_rejects_invalid_uuid(): void
    {
        $this->artisan('workstation:revoke', ['uuid' => 'not-a-uuid'])
            ->expectsOutputToContain('Invalid UUID format')
            ->assertExitCode(2);
    }

    #[Test]
    public function it_revokes_all_active_refreshes(): void
    {
        $uuid = (string) Str::uuid();
        WorkstationRefreshToken::factory()->count(3)->forWorkstation($uuid)->create();
        WorkstationRefreshToken::factory()->forWorkstation($uuid)->revoked()->create();

        $this->artisan('workstation:revoke', ['uuid' => $uuid, '--reason' => 'lost_device', '--by' => 'admin:test'])
            ->assertExitCode(0);

        $active = WorkstationRefreshToken::query()
            ->where('workstation_uuid', $uuid)
            ->whereNull('revoked_at')
            ->count();
        $this->assertSame(0, $active);

        // Marker entry inserted
        $this->assertGreaterThanOrEqual(
            1,
            WorkstationJwtRevocation::query()->where('workstation_uuid', $uuid)->count(),
        );
    }

    #[Test]
    public function dry_run_does_not_touch_db(): void
    {
        $uuid = (string) Str::uuid();
        WorkstationRefreshToken::factory()->count(2)->forWorkstation($uuid)->create();

        $this->artisan('workstation:revoke', ['uuid' => $uuid, '--dry-run' => true])
            ->expectsOutputToContain('No changes applied')
            ->assertExitCode(0);

        $active = WorkstationRefreshToken::query()
            ->where('workstation_uuid', $uuid)
            ->whereNull('revoked_at')
            ->count();
        $this->assertSame(2, $active);
    }

    #[Test]
    public function no_active_refreshes_still_inserts_marker(): void
    {
        $uuid = (string) Str::uuid();
        // Aucun refresh — pour signaler explicitement la révocation, on a quand
        // même besoin du marker.

        $this->artisan('workstation:revoke', ['uuid' => $uuid])
            ->assertExitCode(0);

        $this->assertGreaterThanOrEqual(
            1,
            WorkstationJwtRevocation::query()->where('workstation_uuid', $uuid)->count(),
        );
    }
}
