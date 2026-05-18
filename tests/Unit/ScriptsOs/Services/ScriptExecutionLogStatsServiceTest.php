<?php

declare(strict_types=1);

namespace Tests\Unit\ScriptsOs\Services;

use App\ScriptsOs\Models\ScriptExecutionLog;
use App\ScriptsOs\Services\ScriptExecutionLogStatsService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

class ScriptExecutionLogStatsServiceTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
        // Force cache array pour les tests TTL
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    #[Test]
    public function dashboard_with_empty_db_returns_zero(): void
    {
        $svc = $this->app->make(ScriptExecutionLogStatsService::class);
        $out = $svc->dashboard24h();

        self::assertSame(['total' => 0, 'failures' => 0, 'rate' => 0.0], $out);
    }

    #[Test]
    public function dashboard_returns_correct_rate(): void
    {
        ScriptExecutionLog::factory()->recent(1)->count(80)->create();
        ScriptExecutionLog::factory()->failed()->recent(1)->count(20)->create();

        $svc = $this->app->make(ScriptExecutionLogStatsService::class);
        $out = $svc->dashboard24h();

        self::assertSame(100, $out['total']);
        self::assertSame(20, $out['failures']);
        self::assertSame(0.2, $out['rate']);
    }

    #[Test]
    public function top_failing_workstations_orders_desc(): void
    {
        ScriptExecutionLog::factory()->forWorkstation('aaaaaaaa-bbbb-cccc-dddd-111111111111')
            ->failed()->recent(1)->count(5)->create();
        ScriptExecutionLog::factory()->forWorkstation('aaaaaaaa-bbbb-cccc-dddd-222222222222')
            ->failed()->recent(1)->count(3)->create();
        ScriptExecutionLog::factory()->forWorkstation('aaaaaaaa-bbbb-cccc-dddd-333333333333')
            ->failed()->recent(1)->count(7)->create();

        $svc = $this->app->make(ScriptExecutionLogStatsService::class);
        $top = $svc->topFailingWorkstations(2);

        self::assertCount(2, $top);
        self::assertSame(7, (int) $top[0]->failures_count);
        self::assertSame(5, (int) $top[1]->failures_count);
    }

    #[Test]
    public function top_failing_scripts_excludes_null_script_id(): void
    {
        ScriptExecutionLog::factory()->forScript(10)->failed()->recent(1)->count(3)->create();
        ScriptExecutionLog::factory()->forScript(20)->failed()->recent(1)->count(2)->create();
        // Échecs sans script_id — ne doivent pas apparaître.
        ScriptExecutionLog::factory()->failed()->recent(1)->count(5)->create();

        $svc = $this->app->make(ScriptExecutionLogStatsService::class);
        $top = $svc->topFailingScripts(5);

        self::assertCount(2, $top);
        self::assertSame(10, (int) $top[0]->script_id);
        self::assertSame(3, (int) $top[0]->failures_count);
    }

    #[Test]
    public function dashboard_is_cached(): void
    {
        ScriptExecutionLog::factory()->failed()->recent(1)->count(5)->create();
        $svc = $this->app->make(ScriptExecutionLogStatsService::class);

        $first = $svc->dashboard24h();
        // Ajout d'une row entre 2 appels : si cache fonctionne, le total ne bouge pas.
        ScriptExecutionLog::factory()->failed()->recent(1)->count(10)->create();
        $second = $svc->dashboard24h();

        self::assertSame($first, $second);

        // Après flush, on a la nouvelle valeur.
        $svc->flushCache();
        $third = $svc->dashboard24h();
        self::assertNotSame($first['total'], $third['total']);
    }
}
