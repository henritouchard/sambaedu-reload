<?php

declare(strict_types=1);

namespace Tests\Feature\ScriptsOs\Http\Controllers;

use App\ScriptsOs\Models\ScriptExecutionLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.12 — AC2.3 / AC2.4 (≥10 cas).
 */
class ScriptExecutionLogIngestionControllerTest extends TestCase
{
    use IssuesWorkstationJwt;

    private string $workstationUuid = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
        Cache::store('array')->flush();

        $this->workstationUuid = strtolower((string) Str::uuid());
    }

    private function authToken(array $overrides = []): string
    {
        $emitted = $this->issueTestJwt(array_merge(['sub' => $this->workstationUuid], $overrides));

        return $emitted['token'];
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'script_id' => 42,
            'script_source' => 'managed_script',
            'action' => 'logon',
            'os' => 'windows',
            'status' => 'success',
            'exit_code' => 0,
            'stdout' => 'hello',
            'started_at' => Carbon::now()->subMinute()->toIso8601String(),
            'duration_ms' => 1250,
            'correlation_id' => (string) Str::uuid(),
        ], $overrides);
    }

    #[Test]
    public function happy_path_returns_201_and_creates_row(): void
    {
        $token = $this->authToken();

        $this->postJson(
            '/api/v1/script-execution-logs',
            $this->validPayload(),
            ['Authorization' => 'Bearer ' . $token]
        )->assertStatus(201);

        self::assertSame(1, ScriptExecutionLog::query()->count());
        $row = ScriptExecutionLog::query()->first();
        self::assertSame($this->workstationUuid, $row->workstation_uuid);
        self::assertSame('logon', $row->action->value);
    }

    #[Test]
    public function retry_with_same_correlation_id_is_idempotent(): void
    {
        $token = $this->authToken();
        $payload = $this->validPayload();

        $this->postJson('/api/v1/script-execution-logs', $payload, ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(201);

        // Retry — même correlation_id
        $this->postJson('/api/v1/script-execution-logs', $payload, ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(201);

        self::assertSame(1, ScriptExecutionLog::query()->count());
    }

    #[Test]
    public function missing_authorization_returns_401(): void
    {
        $this->postJson('/api/v1/script-execution-logs', $this->validPayload())
            ->assertStatus(401)
            ->assertJsonStructure(['error', 'code']);
    }

    #[Test]
    public function jwt_with_wrong_tier_returns_401(): void
    {
        $token = $this->authToken(['tier' => 'controlhub']);

        $this->postJson(
            '/api/v1/script-execution-logs',
            $this->validPayload(),
            ['Authorization' => 'Bearer ' . $token]
        )->assertStatus(401);
    }

    #[Test]
    public function missing_action_returns_422(): void
    {
        $token = $this->authToken();
        $payload = $this->validPayload();
        unset($payload['action']);

        $this->postJson('/api/v1/script-execution-logs', $payload, ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['action']]);
    }

    #[Test]
    public function invalid_status_enum_returns_422(): void
    {
        $token = $this->authToken();
        $payload = $this->validPayload(['status' => 'foobar']);

        $this->postJson('/api/v1/script-execution-logs', $payload, ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(422);
    }

    #[Test]
    public function started_at_too_far_in_future_returns_422_with_code(): void
    {
        $token = $this->authToken();
        $payload = $this->validPayload([
            'started_at' => Carbon::now()->addHour()->toIso8601String(),
        ]);

        $resp = $this->postJson('/api/v1/script-execution-logs', $payload, ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(422);

        self::assertStringContainsString('started_at.future', json_encode($resp->json('errors.started_at')));
    }

    #[Test]
    public function started_at_too_old_returns_422_with_code(): void
    {
        $token = $this->authToken();
        $payload = $this->validPayload([
            'started_at' => Carbon::now()->subDays(10)->toIso8601String(),
        ]);

        $resp = $this->postJson('/api/v1/script-execution-logs', $payload, ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(422);

        self::assertStringContainsString('started_at.too_old', json_encode($resp->json('errors.started_at')));
    }

    #[Test]
    public function large_stdout_is_truncated_to_8kb_after_save(): void
    {
        $token = $this->authToken();
        $payload = $this->validPayload([
            'stdout' => str_repeat('x', 12000), // 12 KB envoyé
        ]);

        $this->postJson('/api/v1/script-execution-logs', $payload, ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(201);

        $row = ScriptExecutionLog::query()->first();
        self::assertLessThanOrEqual(8192, strlen((string) $row->stdout_excerpt));
        self::assertStringContainsString('[...truncated]', (string) $row->stdout_excerpt);
    }

    #[Test]
    public function missing_correlation_id_returns_422(): void
    {
        // Story 16.12 post-review Q3 (Opus-A) — `correlation_id` est désormais
        // **required** (mitigation replay JWT). L'ancien test happy-path sans
        // correlation_id est remplacé par un test 422 explicite.
        $token = $this->authToken();
        $payload = $this->validPayload();
        unset($payload['correlation_id']);

        $this->postJson('/api/v1/script-execution-logs', $payload, ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['correlation_id']);

        self::assertSame(0, ScriptExecutionLog::query()->count());
    }

    #[Test]
    public function workstation_uuid_is_extracted_from_jwt(): void
    {
        $token = $this->authToken();

        $this->postJson(
            '/api/v1/script-execution-logs',
            $this->validPayload(),
            ['Authorization' => 'Bearer ' . $token]
        )->assertStatus(201);

        $row = ScriptExecutionLog::query()->first();
        self::assertSame($this->workstationUuid, $row->workstation_uuid);
    }
}
