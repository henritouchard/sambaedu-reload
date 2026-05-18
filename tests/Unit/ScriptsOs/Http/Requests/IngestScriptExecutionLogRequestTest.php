<?php

declare(strict_types=1);

namespace Tests\Unit\ScriptsOs\Http\Requests;

use App\ScriptsOs\Http\Requests\IngestScriptExecutionLogRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.12 — AC2.2 (≥6 cas).
 */
class IngestScriptExecutionLogRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'scriptsos.started_at_skew_seconds_future' => 300,
            'scriptsos.started_at_skew_seconds_past' => 7 * 86400,
        ]);
    }

    private function buildValidator(array $payload): \Illuminate\Validation\Validator
    {
        $req = new IngestScriptExecutionLogRequest();
        $req->merge($payload);

        $factory = $this->app['validator'];
        /** @var \Illuminate\Validation\Validator $validator */
        $validator = $factory->make($payload, $req->rules());
        // Apply custom afterValidation.
        $req->withValidator($validator);

        return $validator;
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
            'stderr' => null,
            'started_at' => Carbon::now()->subMinute()->toIso8601String(),
            'duration_ms' => 1250,
            'correlation_id' => '11111111-2222-4333-8444-555555555555',
        ], $overrides);
    }

    #[Test]
    public function valid_payload_passes(): void
    {
        $v = $this->buildValidator($this->validPayload());
        self::assertTrue($v->passes(), 'errors: ' . implode(', ', $v->errors()->all()));
    }

    #[Test]
    public function missing_action_fails(): void
    {
        $p = $this->validPayload();
        unset($p['action']);
        $v = $this->buildValidator($p);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('action', $v->errors()->toArray());
    }

    #[Test]
    public function missing_started_at_fails(): void
    {
        $p = $this->validPayload();
        unset($p['started_at']);
        $v = $this->buildValidator($p);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('started_at', $v->errors()->toArray());
    }

    #[Test]
    public function invalid_status_fails(): void
    {
        $p = $this->validPayload(['status' => 'foobar']);
        $v = $this->buildValidator($p);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('status', $v->errors()->toArray());
    }

    #[Test]
    public function started_at_too_far_in_future_fails_with_code(): void
    {
        $p = $this->validPayload([
            'started_at' => Carbon::now()->addHour()->toIso8601String(),
        ]);
        $v = $this->buildValidator($p);
        self::assertTrue($v->fails());
        self::assertContains('started_at.future', $v->errors()->get('started_at'));
    }

    #[Test]
    public function started_at_too_old_fails_with_code(): void
    {
        $p = $this->validPayload([
            'started_at' => Carbon::now()->subDays(10)->toIso8601String(),
        ]);
        $v = $this->buildValidator($p);
        self::assertTrue($v->fails());
        self::assertContains('started_at.too_old', $v->errors()->get('started_at'));
    }

    #[Test]
    public function duration_ms_negative_fails(): void
    {
        $p = $this->validPayload(['duration_ms' => -1]);
        $v = $this->buildValidator($p);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('duration_ms', $v->errors()->toArray());
    }

    #[Test]
    public function stdout_max_16k_enforced(): void
    {
        $p = $this->validPayload(['stdout' => str_repeat('a', 20000)]);
        $v = $this->buildValidator($p);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('stdout', $v->errors()->toArray());
    }

    /**
     * Story 16.12 post-review Q3 (Opus-A) — `correlation_id` est désormais
     * required (mitigation replay JWT — l'attaquant qui modifie le
     * correlation_id casse l'idempotence du wrapper légitime → forcé à
     * réutiliser celui capturé → dédupliqué par UNIQUE pgsql).
     */
    #[Test]
    public function missing_correlation_id_fails(): void
    {
        $p = $this->validPayload();
        unset($p['correlation_id']);
        $v = $this->buildValidator($p);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('correlation_id', $v->errors()->toArray());
    }

    #[Test]
    public function invalid_correlation_id_uuid_fails(): void
    {
        $p = $this->validPayload(['correlation_id' => 'not-a-uuid']);
        $v = $this->buildValidator($p);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('correlation_id', $v->errors()->toArray());
    }
}
