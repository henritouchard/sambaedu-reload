<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Support\GpoLogger;
use App\Gpo\Support\SambaToolRunner;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unitaires {@see SambaToolRunner} (Story 16.1 / AC3.2).
 *
 * - Argument passing en mode array (pas concat string)
 * - bin_path configurable + global args (`--use-kerberos=required`)
 * - Mode dry-run : pas d'exec, résultat synthétique
 * - Timeout configurable
 * - Capture stdout / stderr / exit code
 */
class SambaToolRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // On force la config gpo en valeurs maitrisées pour les tests.
        config()->set('sambaedu.gpo.bin_path', '/usr/bin/samba-tool');
        config()->set('sambaedu.gpo.kerb_option', '--use-kerberos=required');
        config()->set('sambaedu.gpo.samba_tool_timeout', 30);
    }

    #[Test]
    public function it_builds_command_in_array_mode_with_global_args(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);

        $runner = new SambaToolRunner();
        $runner->run(['gpo', 'listall']);

        // Vérifie que Process::fake a vu une commande contenant bin + args + kerb.
        Process::assertRan(function ($process) {
            $cmd = $process->command;
            if (! is_array($cmd)) {
                return false;
            }

            return $cmd[0] === '/usr/bin/samba-tool'
                && $cmd[1] === 'gpo'
                && $cmd[2] === 'listall'
                && in_array('--use-kerberos=required', $cmd, true);
        });
    }

    #[Test]
    public function it_uses_configured_bin_path(): void
    {
        config()->set('sambaedu.gpo.bin_path', '/custom/path/samba-tool');
        Process::fake([
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);

        $runner = new SambaToolRunner();
        $runner->run(['gpo', 'listall']);

        Process::assertRan(fn ($process) => is_array($process->command) && $process->command[0] === '/custom/path/samba-tool');
    }

    #[Test]
    public function dry_run_does_not_execute_real_command(): void
    {
        // Garde-fou : aucun processus réel ne doit être lancé en dry-run.
        Process::fake();
        Process::preventStrayProcesses();

        $runner = (new SambaToolRunner())->withDryRun();

        $this->assertTrue($runner->isDryRun());

        $result = $runner->run(['gpo', 'create', 'test-gpo']);

        $this->assertSame(0, $result->exitCode());
        $this->assertStringContainsString('[dry-run]', $result->output());
        $this->assertStringContainsString('gpo', $result->output());
        $this->assertStringContainsString('create', $result->output());

        // Aucun appel à Process::run ne doit avoir eu lieu — dry-run pur.
        Process::assertNothingRan();
    }

    #[Test]
    public function withDryRun_returns_clone_not_mutating_original(): void
    {
        $runner = new SambaToolRunner();
        $dryRunner = $runner->withDryRun(true);

        $this->assertFalse($runner->isDryRun(), 'Original ne doit pas être muté');
        $this->assertTrue($dryRunner->isDryRun());
    }

    #[Test]
    public function it_captures_stdout_stderr_and_exit_code(): void
    {
        Process::fake([
            '*' => Process::result(output: 'standard output', errorOutput: 'standard error', exitCode: 42),
        ]);

        $runner = new SambaToolRunner();
        $result = $runner->run(['gpo', 'listall']);

        $this->assertSame(42, $result->exitCode());
        $this->assertSame('standard output', $result->output());
        $this->assertSame('standard error', $result->errorOutput());
        $this->assertFalse($result->successful());
    }

    #[Test]
    public function it_logs_to_gpo_action_log_when_provided(): void
    {
        Process::fake([
            '*' => Process::result(output: 'ok', errorOutput: '', exitCode: 0),
        ]);

        // On capte les logs en swappant le channel.
        $captured = [];
        $captureRef = &$captured;
        $fake = new class($captureRef) extends \Psr\Log\AbstractLogger
        {
            public function __construct(private array &$ref) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->ref[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $manager = $this->app->make('log');
        $manager->extend('gpo-fake-driver', fn () => $fake);
        $manager->forgetChannel('gpo');
        config()->set('logging.channels.gpo', ['driver' => 'gpo-fake-driver']);

        $log = GpoLogger::action('gpo.list');
        $runner = new SambaToolRunner();
        $runner->run(['gpo', 'listall'], $log);

        $execLogs = array_values(array_filter($captured, fn ($e) => str_contains($e['message'], 'samba-tool exec')));
        $this->assertCount(1, $execLogs, 'Un seul log gpo.sambatool.exec attendu');
        $this->assertSame('debug', $execLogs[0]['level']);
        $this->assertSame(0, $execLogs[0]['context']['exit_code']);
        $this->assertSame(['/usr/bin/samba-tool', 'gpo', 'listall', '--use-kerberos=required'], $execLogs[0]['context']['command']);
    }

    #[Test]
    public function withTimeout_returns_immutable_clone(): void
    {
        $runner = new SambaToolRunner();
        $custom = $runner->withTimeout(120);

        $this->assertNotSame($runner, $custom);
    }

    #[Test]
    public function it_applies_timeout_override_to_process(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);

        $runner = (new SambaToolRunner())->withTimeout(120);
        $runner->run(['gpo', 'listall']);

        // Vérifie que le timeout override (120s) a bien été appliqué à Process.
        Process::assertRan(fn ($process) => $process->timeout === 120);
    }

    #[Test]
    public function it_applies_default_timeout_from_config(): void
    {
        config()->set('sambaedu.gpo.samba_tool_timeout', 45);
        Process::fake([
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);

        $runner = new SambaToolRunner();
        $runner->run(['gpo', 'listall']);

        Process::assertRan(fn ($process) => $process->timeout === 45);
    }
}
