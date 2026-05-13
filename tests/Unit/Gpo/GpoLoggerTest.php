<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Support\GpoActionLog;
use App\Gpo\Support\GpoLogger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests unitaires {@see GpoLogger} et {@see GpoActionLog} (Story 16.1 / AC1.4).
 *
 * Capture les écritures sur le channel `gpo` via un fake LogManager pour
 * vérifier que les conventions de logging Epic 16 sont respectées :
 *
 * - chaque action émet au minimum `start` + `success` (ou `failure`)
 * - `operation_id` auto-généré (UUID v4) et propagé sur tous les logs
 * - durée mesurée (`elapsed_ms`)
 * - diff structuré (`diff()`)
 * - troncature stdout/stderr à 8 Ko (`sambaToolExec()`)
 */
class GpoLoggerTest extends TestCase
{
    /** @var array<int, array{level: string, message: string, context: array<string,mixed>}> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->captured = [];

        // On intercepte les écritures Log::channel('gpo') via un custom driver
        // factory. Le LogManager appelle le callback pour résoudre le channel
        // et wrappe la valeur retournée dans un `Illuminate\Log\Logger` (PSR-3).
        // Notre fake (AbstractLogger PSR-3) est appelé en bout de chaîne par
        // `log($level, $message, $context)`.
        $captureRef = &$this->captured;
        $fake = new class($captureRef) extends \Psr\Log\AbstractLogger
        {
            public function __construct(private array &$ref) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->ref[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $manager = $this->app->make('log');
        \assert($manager instanceof LogManager);

        // Important : on enregistre un driver custom appelé `gpo-fake-driver`
        // puis on reconfigure le channel `gpo` pour l'utiliser. Si on
        // surchargeait directement `extend('gpo', …)`, on ne gérerait pas
        // proprement la config.driver=daily existante côté config.
        $manager->extend('gpo-fake-driver', fn () => $fake);
        $manager->forgetChannel('gpo');
        config()->set('logging.channels.gpo', [
            'driver' => 'gpo-fake-driver',
        ]);
    }

    #[Test]
    public function action_emits_start_then_success(): void
    {
        $log = GpoLogger::action('gpo.list');
        $log->success(['count' => 5]);

        $this->assertCount(2, $this->captured);

        $start = $this->captured[0];
        $this->assertSame('info', $start['level']);
        $this->assertStringContainsString('gpo.list start', $start['message']);
        $this->assertSame('start', $start['context']['outcome']);
        $this->assertSame('gpo.list', $start['context']['action_type']);
        $this->assertArrayHasKey('operation_id', $start['context']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $start['context']['operation_id'],
        );

        $end = $this->captured[1];
        $this->assertSame('info', $end['level']);
        $this->assertSame('success', $end['context']['outcome']);
        $this->assertSame(5, $end['context']['count']);
        $this->assertSame($start['context']['operation_id'], $end['context']['operation_id']);
        $this->assertArrayHasKey('elapsed_ms', $end['context']);
    }

    #[Test]
    public function action_emits_failure_with_error_details(): void
    {
        $log = GpoLogger::action('gpo.create', context: ['display_name' => 'foo']);
        $log->failure(new RuntimeException('boom'));

        $this->assertCount(2, $this->captured);

        $end = $this->captured[1];
        $this->assertSame('error', $end['level']);
        $this->assertSame('failure', $end['context']['outcome']);
        $this->assertSame('foo', $end['context']['display_name']);
        $this->assertSame(RuntimeException::class, $end['context']['error']['class']);
        $this->assertSame('boom', $end['context']['error']['message']);
        $this->assertArrayHasKey('trace', $end['context']['error']);
    }

    #[Test]
    public function operation_id_can_be_provided_explicitly(): void
    {
        $providedId = '00000000-0000-4000-8000-000000000001';
        $log = GpoLogger::action('gpo.show', operationId: $providedId);
        $log->success();

        $this->assertSame($providedId, $this->captured[0]['context']['operation_id']);
        $this->assertSame($providedId, $this->captured[1]['context']['operation_id']);
        $this->assertSame($providedId, $log->operationId());
    }

    #[Test]
    public function step_emits_intermediate_log_between_start_and_end(): void
    {
        $log = GpoLogger::action('gpo.fetch');
        $log->step('downloading from sysvol');
        $log->success();

        $this->assertCount(3, $this->captured);
        $this->assertStringContainsString('step: downloading from sysvol', $this->captured[1]['message']);
        $this->assertSame('info', $this->captured[1]['level']);
    }

    #[Test]
    public function diff_logs_before_and_after_payload(): void
    {
        $log = GpoLogger::action('gpo.section.write');
        $log->diff('exclude_profile_dirs', ['a', 'b'], ['a', 'b', 'c']);
        $log->success();

        $diffEntry = $this->captured[1];
        $this->assertSame(['a', 'b'], $diffEntry['context']['diff']['before']);
        $this->assertSame(['a', 'b', 'c'], $diffEntry['context']['diff']['after']);
        $this->assertSame('exclude_profile_dirs', $diffEntry['context']['diff']['what']);
    }

    #[Test]
    public function sambatool_exec_log_truncates_large_stdio(): void
    {
        $log = GpoLogger::action('gpo.list');
        // 10 Ko de stdout → doit être tronqué à 8 Ko + marker.
        $bigStdout = str_repeat('x', 10 * 1024);
        $log->sambaToolExec(
            command: ['/usr/bin/samba-tool', 'gpo', 'listall'],
            exitCode: 0,
            stdout: $bigStdout,
            stderr: '',
            durationMs: 42.5,
        );

        // start + sambaToolExec
        $this->assertCount(2, $this->captured);
        $execLog = $this->captured[1];

        $this->assertSame('debug', $execLog['level']);
        $this->assertSame(0, $execLog['context']['exit_code']);
        $this->assertSame(42.5, $execLog['context']['duration_ms']);

        // 8 Ko + "\n[truncated]" suffix
        $stdoutCaptured = $execLog['context']['stdout'];
        $this->assertStringEndsWith('[truncated]', $stdoutCaptured);
        $this->assertSame(GpoActionLog::STDIO_TRUNCATE_BYTES + strlen("\n[truncated]"), strlen($stdoutCaptured));
    }

    #[Test]
    public function success_and_failure_are_idempotent(): void
    {
        $log = GpoLogger::action('gpo.list');
        $log->success();
        $log->success(); // no-op
        $log->failure(new RuntimeException('late')); // no-op aussi

        // start + 1 success (les deux suivants sont no-op).
        $this->assertCount(2, $this->captured);
        $this->assertSame('success', $this->captured[1]['context']['outcome']);
    }
}
