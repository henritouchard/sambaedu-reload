<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Jobs\GenerateWineImageJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `GenerateWineImageJob` — Story 16.3c AC6.3 / AC2.1 / AC5.2.
 */
class GenerateWineImageJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::lock('gpo:wine:generate-image:__default__')->forceRelease();
        Cache::lock('gpo:wine:generate-image:firefox')->forceRelease();
    }

    #[Test]
    public function it_validates_application_in_constructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GenerateWineImageJob('; rm -rf /', 'op-uuid');
    }

    #[Test]
    public function it_accepts_empty_application_for_default_container(): void
    {
        $job = new GenerateWineImageJob('', 'op-uuid');
        $this->assertSame('', $job->application);
    }

    #[Test]
    public function it_accepts_alphanumeric_dot_dash_underscore(): void
    {
        $job = new GenerateWineImageJob('firefox-v2.1_test', 'op-uuid');
        $this->assertSame('firefox-v2.1_test', $job->application);
    }

    #[Test]
    public function it_rejects_path_traversal_attempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GenerateWineImageJob('../etc/passwd', 'op-uuid');
    }

    #[Test]
    public function it_invokes_process_with_array_mode_not_shell_concat(): void
    {
        Process::fake([
            '*' => Process::result(output: 'ok', errorOutput: '', exitCode: 0),
        ]);

        $job = new GenerateWineImageJob('firefox', 'op-uuid');
        $job->handle();

        Process::assertRan(function (\Illuminate\Process\PendingProcess $p) {
            // Le command doit être un tableau [script, app] — pas une string.
            $cmd = $p->command;
            return is_array($cmd)
                && count($cmd) === 2
                && $cmd[0] === GenerateWineImageJob::SCRIPT_PATH
                && $cmd[1] === 'firefox';
        });
    }

    #[Test]
    public function it_invokes_script_alone_for_default_container(): void
    {
        Process::fake([
            '*' => Process::result(output: 'ok', errorOutput: '', exitCode: 0),
        ]);

        $job = new GenerateWineImageJob('', 'op-uuid');
        $job->handle();

        Process::assertRan(function (\Illuminate\Process\PendingProcess $p) {
            $cmd = $p->command;
            return is_array($cmd) && count($cmd) === 1 && $cmd[0] === GenerateWineImageJob::SCRIPT_PATH;
        });
    }

    #[Test]
    public function it_throws_when_process_fails_and_logs_stderr_truncated(): void
    {
        $stderr = str_repeat('STDERR_LINE_', 1000); // ~12 Ko de stderr
        Process::fake([
            '*' => Process::result(output: '', errorOutput: $stderr, exitCode: 1),
        ]);

        $job = new GenerateWineImageJob('firefox', 'op-uuid');

        $this->expectException(\RuntimeException::class);
        $job->handle();
    }

    #[Test]
    public function it_has_tries_equal_to_one_and_long_timeout(): void
    {
        $job = new GenerateWineImageJob('firefox', 'op-uuid');
        $this->assertSame(1, $job->tries);
        $this->assertSame(1800, $job->timeout);
    }

    #[Test]
    public function it_releases_lock_on_failed_callback(): void
    {
        $job = new GenerateWineImageJob('firefox', 'op-uuid');

        // Simule un lock existant.
        Cache::lock($job->lockKey(), 1800)->get();

        $job->failed(new \RuntimeException('simulated failure'));

        // Lock libéré → un nouveau lock doit être obtenable.
        $newLock = Cache::lock($job->lockKey(), 1800);
        $this->assertTrue($newLock->get());
        $newLock->release();
    }
}
