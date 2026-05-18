<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\V1;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
use App\Auth\V1\Models\WorkstationMigrationStatus;
use App\Auth\V1\Pki\CaInitializer;
use App\Auth\V1\Services\LegacyBootstrapTokenValidator;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.11 — AC6 / T6.2.
 *
 * Tests Feature : vérifie que `POST /api/v1/agent/enroll` upsert bien
 * `workstations_migration_status` + insère un attempt `enrolled` après
 * succès, conformément au D7.
 */
class MigrationStatusUpsertTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();

        $caMock = Mockery::mock(CaInitializer::class);
        $caMock->shouldReceive('getCaCertPem')->andReturn(
            "-----BEGIN CERTIFICATE-----\nFAKE-CA-FOR-TESTS\n-----END CERTIFICATE-----\n",
        );
        $this->app->instance(CaInitializer::class, $caMock);

        $vMock = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $vMock->shouldReceive('isValid')->andReturn(true);
        $vMock->shouldReceive('checkMismatch')->andReturn(false);
        $this->app->instance(LegacyBootstrapTokenValidator::class, $vMock);

        config([
            'sambaedu.se4fs_name' => 'se4fs-test001',
            'auth_v1.server.host_suffix' => 'lab.local',
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function body(string $uuid): array
    {
        return [
            'uuid' => $uuid,
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'hostname' => 'pc-migrate-' . substr($uuid, 0, 8),
            'os' => 'linux',
        ];
    }

    #[Test]
    public function premier_enroll_cree_workstation_migration_status(): void
    {
        $uuid = (string) Str::uuid();

        $this->postJson('/api/v1/agent/enroll', $this->body($uuid), [
            'X-Bootstrap-Token' => md5('valid-token-1'),
        ])->assertStatus(200);

        $row = WorkstationMigrationStatus::query()
            ->where('workstation_uuid', $uuid)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('linux', $row->os);
        $this->assertSame('se4fs-test001', $row->se4fs_name);
        $this->assertNotEmpty($row->access_token_emitted_jti);
        $this->assertSame(
            substr(hash('sha256', md5('valid-token-1')), 0, 16),
            $row->bootstrap_token_hash_prefix,
        );
    }

    #[Test]
    public function re_enroll_meme_uuid_upsert_pas_dup(): void
    {
        $uuid = (string) Str::uuid();

        $this->postJson('/api/v1/agent/enroll', $this->body($uuid), [
            'X-Bootstrap-Token' => md5('token-1'),
        ])->assertStatus(200);

        $this->postJson('/api/v1/agent/enroll', $this->body($uuid), [
            'X-Bootstrap-Token' => md5('token-2'),
        ])->assertStatus(200);

        // Une seule row dans workstations_migration_status (unique uuid).
        $count = WorkstationMigrationStatus::query()
            ->where('workstation_uuid', $uuid)
            ->count();
        $this->assertSame(1, $count);

        // La row a été updated — bootstrap_token_hash_prefix doit refléter le dernier appel.
        $row = WorkstationMigrationStatus::query()
            ->where('workstation_uuid', $uuid)
            ->first();
        $this->assertSame(
            substr(hash('sha256', md5('token-2')), 0, 16),
            $row->bootstrap_token_hash_prefix,
        );
    }

    #[Test]
    public function enroll_insert_attempt_status_enrolled(): void
    {
        $uuid = (string) Str::uuid();

        $this->postJson('/api/v1/agent/enroll', $this->body($uuid), [
            'X-Bootstrap-Token' => md5('valid-token-3'),
        ])->assertStatus(200);

        $attempt = WorkstationMigrationAttempt::query()
            ->where('workstation_uuid', $uuid)
            ->where('status', WorkstationMigrationAttempt::STATUS_ENROLLED)
            ->first();

        $this->assertNotNull($attempt);
        $this->assertSame('linux', $attempt->os);
        $this->assertNotNull($attempt->finished_at);
    }
}
