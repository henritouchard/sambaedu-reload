<?php

declare(strict_types=1);

namespace Tests\Unit\SystemStatus;

use App\SystemStatus\Distro;
use App\SystemStatus\DistroInstallTracker;
use App\SystemStatus\Jobs\RunDistroInstallScriptJob;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unitaires de {@see RunDistroInstallScriptJob} — whitelist enum,
 * suivi d'état via {@see DistroInstallTracker}, Process mocké.
 *
 * Hermétiques (fix review F9) : la présence du script sur la machine est
 * neutralisée via le seam `scriptExists()` — les branches done / failed /
 * script-absent sont couvertes indépendamment de l'environnement (host/VM).
 * Le tracker utilisant le store `file` partagé, chaque test reset ses clés.
 */
class RunDistroInstallScriptJobTest extends TestCase
{
    private DistroInstallTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = app(DistroInstallTracker::class);
        foreach (Distro::cases() as $distro) {
            $this->tracker->reset($distro);
        }
    }

    /**
     * Job de test avec présence du script forcée (seam scriptExists).
     */
    private function makeJob(Distro $distro, bool $scriptExists): RunDistroInstallScriptJob
    {
        return new class($distro, $scriptExists) extends RunDistroInstallScriptJob {
            public function __construct(Distro $distro, private readonly bool $forcedExistence)
            {
                parent::__construct($distro);
            }

            protected function scriptExists(string $script): bool
            {
                return $this->forcedExistence;
            }
        };
    }

    #[Test]
    public function it_refuses_windows_distros_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RunDistroInstallScriptJob(Distro::Win10);
    }

    #[Test]
    public function it_marks_done_when_script_succeeds(): void
    {
        Process::fake([
            '*' => Process::result(output: 'install ok'),
        ]);

        $this->tracker->start(Distro::Debian);
        $this->makeJob(Distro::Debian, scriptExists: true)->handle($this->tracker);

        self::assertSame('done', $this->tracker->stateFor(Distro::Debian)['status']);
        Process::assertRan(fn ($process) => str_contains(
            is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
            'install-debian-64-iso.sh',
        ));
    }

    #[Test]
    public function it_marks_failed_when_script_exits_non_zero(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'miroir injoignable', exitCode: 1),
        ]);

        $this->tracker->start(Distro::Debian);
        $this->makeJob(Distro::Debian, scriptExists: true)->handle($this->tracker);

        $state = $this->tracker->stateFor(Distro::Debian);
        self::assertSame('failed', $state['status']);
        self::assertStringContainsString('miroir injoignable', (string) $state['detail']);
    }

    #[Test]
    public function it_marks_failed_when_script_is_absent_without_running_process(): void
    {
        Process::fake();

        $this->tracker->start(Distro::Ubuntu);
        $this->makeJob(Distro::Ubuntu, scriptExists: false)->handle($this->tracker);

        $state = $this->tracker->stateFor(Distro::Ubuntu);
        self::assertSame('failed', $state['status']);
        self::assertStringContainsString('script absent', (string) $state['detail']);
        Process::assertNothingRan();
    }

    #[Test]
    public function it_releases_lock_after_handle_even_on_failure(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
        ]);

        // Lock acquis comme le ferait la page avant dispatch.
        self::assertTrue($this->tracker->tryAcquireLock(Distro::Debian));
        $this->tracker->start(Distro::Debian);

        $this->makeJob(Distro::Debian, scriptExists: true)->handle($this->tracker);

        // Fix review F2 : le job relâche le lock en sortie — un nouvel
        // install est immédiatement possible.
        self::assertTrue($this->tracker->tryAcquireLock(Distro::Debian));
        $this->tracker->releaseLock(Distro::Debian);
    }

    #[Test]
    public function it_tracker_round_trip_running_then_done(): void
    {
        self::assertNull($this->tracker->stateFor(Distro::PrimTux));
        self::assertFalse($this->tracker->anyRunning());

        $this->tracker->start(Distro::PrimTux);
        self::assertTrue($this->tracker->isRunning(Distro::PrimTux));
        self::assertTrue($this->tracker->anyRunning());

        $this->tracker->finish(Distro::PrimTux);
        self::assertFalse($this->tracker->isRunning(Distro::PrimTux));
        self::assertSame('done', $this->tracker->stateFor(Distro::PrimTux)['status']);
        self::assertNotNull($this->tracker->stateFor(Distro::PrimTux)['finished_at']);
    }

    #[Test]
    public function it_tracker_sanitizes_failure_detail(): void
    {
        // Fix review F6 : stderr brut de script root → chars de contrôle
        // retirés + tronqué (le stderr complet reste dans les logs).
        $this->tracker->start(Distro::Nird);
        $this->tracker->fail(Distro::Nird, "curl\x1b[31m: error\x07\n" . str_repeat('x', 600));

        $detail = (string) $this->tracker->stateFor(Distro::Nird)['detail'];
        self::assertStringNotContainsString("\x1b", $detail);
        self::assertStringNotContainsString("\x07", $detail);
        self::assertLessThanOrEqual(300, strlen($detail));
    }

    #[Test]
    public function it_tracker_notified_flag_is_idempotent(): void
    {
        // Fix review F13 : le flag notified persiste l'émission du toast.
        $this->tracker->start(Distro::PrimTux);
        $this->tracker->finish(Distro::PrimTux);

        self::assertFalse((bool) ($this->tracker->stateFor(Distro::PrimTux)['notified'] ?? false));

        $this->tracker->markNotified(Distro::PrimTux);
        self::assertTrue((bool) $this->tracker->stateFor(Distro::PrimTux)['notified']);
    }
}
