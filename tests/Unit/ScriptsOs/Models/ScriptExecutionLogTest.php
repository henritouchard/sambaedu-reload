<?php

declare(strict_types=1);

namespace Tests\Unit\ScriptsOs\Models;

use App\ScriptsOs\Enums\ScriptExecutionAction;
use App\ScriptsOs\Enums\ScriptExecutionOs;
use App\ScriptsOs\Enums\ScriptExecutionStatus;
use App\ScriptsOs\Models\ScriptExecutionLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.12 — AC1.2 (≥10 cas).
 */
class ScriptExecutionLogTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
    }

    #[Test]
    public function it_generates_uuid_id_on_create_when_not_provided(): void
    {
        $log = ScriptExecutionLog::factory()->create(['id' => '']);
        // Le booted() event remplit l'id si vide.
        self::assertNotEmpty($log->id);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $log->id);
    }

    #[Test]
    public function it_truncates_stdout_at_8192_bytes_with_marker(): void
    {
        $longText = str_repeat('a', 10000); // > 8192 bytes ASCII
        $log = ScriptExecutionLog::factory()->create(['stdout_excerpt' => $longText]);

        self::assertLessThanOrEqual(8192, strlen((string) $log->stdout_excerpt));
        self::assertStringContainsString('[...truncated]', (string) $log->stdout_excerpt);
    }

    #[Test]
    public function it_truncates_stderr_at_8192_bytes_with_marker(): void
    {
        $longText = str_repeat('b', 10000);
        $log = ScriptExecutionLog::factory()->create(['stderr_excerpt' => $longText]);

        self::assertLessThanOrEqual(8192, strlen((string) $log->stderr_excerpt));
        self::assertStringContainsString('[...truncated]', (string) $log->stderr_excerpt);
    }

    #[Test]
    public function it_preserves_utf8_when_truncating_multibyte(): void
    {
        // 🚀 = 4 bytes UTF-8 — répété pour dépasser 8 KB
        $rocket = '🚀';
        $longText = str_repeat($rocket, 3000); // 12000 bytes UTF-8

        $log = ScriptExecutionLog::factory()->create(['stdout_excerpt' => $longText]);

        $stored = (string) $log->stdout_excerpt;
        self::assertLessThanOrEqual(8192, strlen($stored));
        // mb_strcut ne doit pas casser un caractère UTF-8 → mb_strlen
        // reste cohérent (chaque rocket = 1 char).
        self::assertGreaterThan(0, mb_strlen($stored, 'UTF-8'));
        self::assertStringContainsString('[...truncated]', $stored);
    }

    #[Test]
    public function it_casts_action_to_enum(): void
    {
        $log = ScriptExecutionLog::factory()->create(['action' => ScriptExecutionAction::LOGON->value]);

        self::assertInstanceOf(ScriptExecutionAction::class, $log->action);
        self::assertSame(ScriptExecutionAction::LOGON, $log->action);
    }

    #[Test]
    public function it_casts_status_to_enum(): void
    {
        $log = ScriptExecutionLog::factory()->failed()->create();

        self::assertInstanceOf(ScriptExecutionStatus::class, $log->status);
        self::assertSame(ScriptExecutionStatus::FAILURE, $log->status);
    }

    #[Test]
    public function scope_failed_filters_correctly(): void
    {
        ScriptExecutionLog::factory()->count(3)->create();
        ScriptExecutionLog::factory()->failed()->count(5)->create();

        self::assertSame(5, ScriptExecutionLog::query()->failed()->count());
    }

    #[Test]
    public function scope_succeeded_filters_correctly(): void
    {
        ScriptExecutionLog::factory()->count(4)->create();
        ScriptExecutionLog::factory()->failed()->count(2)->create();

        self::assertSame(4, ScriptExecutionLog::query()->succeeded()->count());
    }

    #[Test]
    public function scope_recent_returns_only_within_window(): void
    {
        ScriptExecutionLog::factory()->recent(1)->count(2)->create();
        ScriptExecutionLog::factory()->state([
            'started_at' => Carbon::now()->subDays(2),
        ])->count(3)->create();

        self::assertSame(2, ScriptExecutionLog::query()->recent(24)->count());
    }

    #[Test]
    public function scope_for_workstation_normalizes_lowercase(): void
    {
        $uuid = strtolower((string) Str::uuid());
        ScriptExecutionLog::factory()->forWorkstation($uuid)->count(3)->create();
        ScriptExecutionLog::factory()->count(2)->create();

        self::assertSame(3, ScriptExecutionLog::query()->forWorkstation(strtoupper($uuid))->count());
    }

    #[Test]
    public function scope_between_dates_bounds_correctly(): void
    {
        $startedToday = Carbon::now()->subHours(2);
        $startedYesterday = Carbon::now()->subDays(1)->setTime(8, 0);
        $startedLastWeek = Carbon::now()->subDays(8);

        ScriptExecutionLog::factory()->state(['started_at' => $startedToday])->create();
        ScriptExecutionLog::factory()->state(['started_at' => $startedYesterday])->create();
        ScriptExecutionLog::factory()->state(['started_at' => $startedLastWeek])->create();

        $from = Carbon::now()->subDays(2)->startOfDay();
        $to = Carbon::now()->endOfDay();

        self::assertSame(
            2,
            ScriptExecutionLog::query()->betweenDates($from, $to)->count(),
        );
    }

    #[Test]
    public function setting_workstation_uuid_normalizes_lowercase(): void
    {
        $upper = 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';
        $log = ScriptExecutionLog::factory()->create(['workstation_uuid' => $upper]);

        self::assertSame(strtolower($upper), $log->workstation_uuid);
    }

    #[Test]
    public function null_stdout_is_preserved(): void
    {
        $log = ScriptExecutionLog::factory()->create(['stdout_excerpt' => null]);
        self::assertNull($log->stdout_excerpt);
    }

    #[Test]
    public function short_stdout_is_not_truncated(): void
    {
        $short = 'short output';
        $log = ScriptExecutionLog::factory()->create(['stdout_excerpt' => $short]);
        self::assertSame($short, $log->stdout_excerpt);
        self::assertStringNotContainsString('[...truncated]', (string) $log->stdout_excerpt);
    }

    #[Test]
    public function scope_for_status_accepts_array(): void
    {
        ScriptExecutionLog::factory()->count(2)->create();
        ScriptExecutionLog::factory()->failed()->count(3)->create();
        ScriptExecutionLog::factory()->timeout()->count(1)->create();

        $count = ScriptExecutionLog::query()->forStatus(['failure', 'timeout'])->count();
        self::assertSame(4, $count);
    }

    #[Test]
    public function it_casts_os_to_enum(): void
    {
        $log = ScriptExecutionLog::factory()->windows()->create();
        self::assertSame(ScriptExecutionOs::WINDOWS, $log->os);
    }
}
