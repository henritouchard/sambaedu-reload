<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Jwt;

use App\Auth\V1\Jwt\WorkstationJwtIssuer;
use App\Auth\V1\Jwt\WorkstationJwtRefreshService;
use App\Auth\V1\Models\WorkstationRefreshToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — AC2.3 / D10 / AC7.1.
 *
 * Tests `WorkstationJwtRefreshService` :
 *
 *  - `refresh()` : émet nouvelle paire access+refresh, marque l'ancien
 *    refresh `revoked_at = now, revocation_reason = 'refresh_rotation'`,
 *    crée nouvelle entrée DB avec `client_meta` copié.
 *  - `handleReplay()` : un refresh déjà révoqué présenté → cascade
 *    revocation de tous les refresh actifs du workstation_uuid.
 *  - `persistEnrollmentRefresh()` : insertion DB OK.
 *  - `revokeAllRefreshesForWorkstation()` : marque tous les refresh actifs
 *    `revoked_at = now`, retourne le count.
 */
class WorkstationJwtRefreshServiceTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
    }

    private function service(): WorkstationJwtRefreshService
    {
        return new WorkstationJwtRefreshService(new WorkstationJwtIssuer());
    }

    #[Test]
    public function refresh_rotates_token_and_marks_old_revoked(): void
    {
        $workstationUuid = (string) Str::uuid();
        $old = WorkstationRefreshToken::factory()
            ->forWorkstation($workstationUuid)
            ->create();

        $payload = $this->service()->refresh($old);

        $this->assertNotEmpty($payload['access_token']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/i', $payload['refresh_token']);
        $this->assertSame('Bearer', $payload['token_type']);
        $this->assertSame($workstationUuid, $payload['workstation_uuid']);

        $old->refresh();
        $this->assertNotNull($old->revoked_at);
        $this->assertSame('refresh_rotation', $old->revocation_reason);

        $this->assertSame(
            1,
            WorkstationRefreshToken::query()
                ->where('workstation_uuid', $workstationUuid)
                ->whereNull('revoked_at')
                ->count(),
            'Exactly one active refresh expected after rotation',
        );
    }

    #[Test]
    public function refresh_copies_client_meta_to_new_token(): void
    {
        $meta = ['mac' => 'AA:BB:CC:DD:EE:FF', 'hostname' => 'lab-pc-01', 'os' => 'windows'];
        $old = WorkstationRefreshToken::factory()
            ->state(['client_meta' => $meta])
            ->create();

        $payload = $this->service()->refresh($old);

        $newRecord = WorkstationRefreshToken::query()->find($payload['new_refresh_id']);
        $this->assertNotNull($newRecord);
        $this->assertSame($meta, $newRecord->client_meta);
    }

    #[Test]
    public function handle_replay_cascade_revokes_all_actives_for_workstation(): void
    {
        $workstationUuid = (string) Str::uuid();
        // 3 refresh tokens actifs
        WorkstationRefreshToken::factory()->count(3)->forWorkstation($workstationUuid)->create();
        // 1 déjà révoqué (le "replayed")
        $replayed = WorkstationRefreshToken::factory()
            ->forWorkstation($workstationUuid)
            ->revoked('refresh_rotation')
            ->create();

        $result = $this->service()->handleReplay($replayed);

        $this->assertSame(3, $result['revoked_refresh_count']);
        $this->assertSame(
            0,
            WorkstationRefreshToken::query()
                ->where('workstation_uuid', $workstationUuid)
                ->whereNull('revoked_at')
                ->count(),
        );
        // L'attribut cascade_revoke est utilisé
        $this->assertGreaterThanOrEqual(
            3,
            WorkstationRefreshToken::query()
                ->where('workstation_uuid', $workstationUuid)
                ->where('revocation_reason', 'cascade_revoke')
                ->count(),
        );
    }

    #[Test]
    public function persist_enrollment_refresh_creates_db_row(): void
    {
        $workstationUuid = (string) Str::uuid();
        $now = Carbon::now();

        $record = $this->service()->persistEnrollmentRefresh(
            workstationUuid: $workstationUuid,
            refreshHash: hash('sha256', 'clear-token-here'),
            issuedAt: $now,
            expiresAt: $now->copy()->addDays(30),
            clientMeta: ['mac' => 'AA:BB:CC:DD:EE:FF', 'hostname' => 'pc-001', 'os' => 'linux'],
        );

        $this->assertNotEmpty($record->id);
        $this->assertSame($workstationUuid, $record->workstation_uuid);
        $this->assertNull($record->revoked_at);
        $this->assertTrue($record->isActive());

        $this->assertSame(
            1,
            WorkstationRefreshToken::query()->where('workstation_uuid', $workstationUuid)->count(),
        );
    }

    #[Test]
    public function revoke_all_refreshes_for_workstation_returns_count(): void
    {
        $workstationUuid = (string) Str::uuid();
        WorkstationRefreshToken::factory()->count(2)->forWorkstation($workstationUuid)->create();
        WorkstationRefreshToken::factory()->forWorkstation($workstationUuid)->revoked()->create();

        $count = $this->service()->revokeAllRefreshesForWorkstation($workstationUuid, 'manual_admin', 'admin:test');

        $this->assertSame(2, $count);
    }
}
