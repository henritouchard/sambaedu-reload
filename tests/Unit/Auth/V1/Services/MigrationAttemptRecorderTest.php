<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Services;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
use App\Auth\V1\Services\MigrationAttemptRecorder;
use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.11 — Q2 (Opus-B + Opus-D).
 *
 * Tests `MigrationAttemptRecorder` — helper d'insertion `failed` row pour
 * `migration:health-check`.
 */
class MigrationAttemptRecorderTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
    }

    private function makeRequest(): Request
    {
        $req = Request::create('/api/v1/agent/enroll', 'POST');
        $req->server->set('REMOTE_ADDR', '192.168.10.42');
        $req->headers->set('User-Agent', 'Mozilla/5.0 SambaEduTest');

        return $req;
    }

    #[Test]
    public function it_inserts_a_failed_row_with_full_context(): void
    {
        $recorder = new MigrationAttemptRecorder();

        $recorder->recordFailure(
            $this->makeRequest(),
            JwtErrorCodes::BOOTSTRAP_TOKEN_UUID_MISMATCH,
            '11111111-1111-4111-8111-111111111111',
            'token mismatch',
            'windows',
        );

        $row = WorkstationMigrationAttempt::query()->first();
        $this->assertNotNull($row);
        $this->assertSame(WorkstationMigrationAttempt::STATUS_FAILED, $row->status);
        $this->assertSame(JwtErrorCodes::BOOTSTRAP_TOKEN_UUID_MISMATCH, $row->error_code);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $row->workstation_uuid);
        $this->assertSame('token mismatch', $row->error_message);
        $this->assertSame('192.168.10.42', $row->client_ip);
        $this->assertSame('Mozilla/5.0 SambaEduTest', $row->user_agent);
        $this->assertSame('windows', $row->os);
        $this->assertNotNull($row->started_at);
        $this->assertNotNull($row->finished_at);
    }

    #[Test]
    public function it_inserts_with_null_uuid_when_not_declared(): void
    {
        $recorder = new MigrationAttemptRecorder();

        $recorder->recordFailure(
            $this->makeRequest(),
            JwtErrorCodes::BOOTSTRAP_NOT_LAN,
            null,
            'lan block',
        );

        $row = WorkstationMigrationAttempt::query()->first();
        $this->assertNotNull($row);
        $this->assertNull($row->workstation_uuid);
        $this->assertSame(JwtErrorCodes::BOOTSTRAP_NOT_LAN, $row->error_code);
    }

    #[Test]
    public function it_inserts_with_null_error_message_when_omitted(): void
    {
        $recorder = new MigrationAttemptRecorder();

        $recorder->recordFailure(
            $this->makeRequest(),
            JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING,
        );

        $row = WorkstationMigrationAttempt::query()->first();
        $this->assertNotNull($row);
        $this->assertNull($row->error_message);
        $this->assertNull($row->workstation_uuid);
        $this->assertNull($row->os);
    }

    #[Test]
    public function it_truncates_error_message_via_mutator(): void
    {
        $recorder = new MigrationAttemptRecorder();
        $huge = str_repeat('A', 2048);

        $recorder->recordFailure(
            $this->makeRequest(),
            JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID,
            null,
            $huge,
        );

        $row = WorkstationMigrationAttempt::query()->first();
        $this->assertNotNull($row);
        $this->assertSame(1024, mb_strlen((string) $row->error_message));
    }

    #[Test]
    public function it_truncates_user_agent_to_1024_chars(): void
    {
        $recorder = new MigrationAttemptRecorder();
        $req = Request::create('/api/v1/agent/enroll', 'POST');
        $req->server->set('REMOTE_ADDR', '10.42.0.1');
        $req->headers->set('User-Agent', str_repeat('X', 2048));

        $recorder->recordFailure($req, JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING);

        $row = WorkstationMigrationAttempt::query()->first();
        $this->assertNotNull($row);
        $this->assertSame(1024, strlen((string) $row->user_agent));
    }

    #[Test]
    public function it_falls_back_to_127_0_0_1_when_remote_addr_missing(): void
    {
        $recorder = new MigrationAttemptRecorder();
        $req = Request::create('/api/v1/agent/enroll', 'POST');
        // Pas de REMOTE_ADDR explicite — Laravel met '127.0.0.1' par défaut.

        $recorder->recordFailure($req, JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING);

        $row = WorkstationMigrationAttempt::query()->first();
        $this->assertNotNull($row);
        $this->assertNotEmpty((string) $row->client_ip);
    }

    #[Test]
    public function it_swallows_db_exception_and_logs_error(): void
    {
        // Simuler une exception DB : drop la table avant l'appel.
        \Schema::drop('workstation_migration_attempts');

        // Log::spy() seul ne suffit pas : Log::channel('auth-v1')->error(...)
        // chaîne deux appels — un spy nu retourne null sur channel() et le
        // ->error() crashe avec "Call to member function on null". On mocke
        // explicitement la chaîne channel()->error() via andReturnSelf().
        Log::shouldReceive('channel')
            ->with('auth-v1')
            ->atLeast()->once()
            ->andReturnSelf();
        Log::shouldReceive('error')->atLeast()->once();

        $recorder = new MigrationAttemptRecorder();
        // Ne doit PAS throw — best-effort.
        $recorder->recordFailure(
            $this->makeRequest(),
            JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING,
        );

        // Restaure la table pour la suite (tearDown).
        $this->ensureAuthV1Tables();
    }

    #[Test]
    public function each_call_creates_a_new_row(): void
    {
        $recorder = new MigrationAttemptRecorder();

        $recorder->recordFailure($this->makeRequest(), JwtErrorCodes::BOOTSTRAP_NOT_LAN);
        $recorder->recordFailure($this->makeRequest(), JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID);
        $recorder->recordFailure($this->makeRequest(), JwtErrorCodes::BOOTSTRAP_TOKEN_UUID_MISMATCH);

        $count = WorkstationMigrationAttempt::query()->failed()->count();
        $this->assertSame(3, $count);
    }
}
