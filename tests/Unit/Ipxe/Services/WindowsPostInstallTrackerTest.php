<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\WindowsPostInstallTracker;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.5 — AC3.3.
 *
 * Tests unitaires de {@see WindowsPostInstallTracker} :
 *  - recordWinpeStart() : set status WinPE + MachineBootLog.
 *  - recordOobeComplete() : set os=windows + status terminée + last_report_at.
 *  - recordInstallBatGenerated() : audit only.
 *  - recordUnknown() : log warning sans side effect DB.
 */
class WindowsPostInstallTrackerTest extends TestCase
{
    private WindowsPostInstallTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config(['ipxe.log.channel' => 'stack']);
        $this->tracker = new WindowsPostInstallTracker();
    }

    private function makeWorkstation(string $name = 'PC-101'): Workstation
    {
        return Workstation::create([
            'name' => $name,
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_records_winpe_start_with_status_and_machine_boot_log(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordWinpeStart($ws, 'PC-101', '192.168.1.5');

        $ws->refresh();
        self::assertSame('installation WinPE', $ws->status);
        // ASCII strict — pas d'accent.
        self::assertStringNotContainsString('é', $ws->status);

        // MachineBootLog avec action='ipxe_win_install'.
        $log = MachineBootLog::where('workstation_id', $ws->id)
            ->where('action', 'ipxe_win_install')
            ->first();
        self::assertNotNull($log);
        self::assertSame('ipxe', $log->initiated_by);
        self::assertTrue((bool) $log->success);
    }

    #[Test]
    public function it_records_oobe_complete_with_os_and_progress(): void
    {
        $ws = $this->makeWorkstation();
        Carbon::setTestNow('2026-05-21 12:34:56');

        $this->tracker->recordOobeComplete($ws, 'PC-101', '192.168.1.5');

        $ws->refresh();
        self::assertSame('windows', $ws->os);
        self::assertSame('installation Windows terminee', $ws->status);
        self::assertStringNotContainsString('é', $ws->status);
        self::assertSame('2026-05-21 12:34:56', $ws->last_report_at?->format('Y-m-d H:i:s'));

        // MachineBootLog avec action='ipxe_win_report'.
        $log = MachineBootLog::where('workstation_id', $ws->id)
            ->where('action', 'ipxe_win_report')
            ->first();
        self::assertNotNull($log);

        Carbon::setTestNow();
    }

    #[Test]
    public function it_records_install_bat_generated_audit_only(): void
    {
        $ws = $this->makeWorkstation();
        $beforeStatus = $ws->status;

        $this->tracker->recordInstallBatGenerated($ws, '192.168.1.5');

        $ws->refresh();
        // Pas d'update status (audit only).
        self::assertSame($beforeStatus, $ws->status);
        self::assertNull($ws->os);

        // MachineBootLog avec action='ipxe_win_install'.
        $log = MachineBootLog::where('workstation_id', $ws->id)
            ->where('action', 'ipxe_win_install')
            ->first();
        self::assertNotNull($log);
    }

    #[Test]
    public function it_records_unknown_workstation_with_log_only(): void
    {
        // `Log::spy()` ne mock pas les channels via `Log::channel(...)` — on
        // stub explicitement le channel ipxe pour retourner self afin que
        // `->warning(...)` soit chainable.
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning');

        $this->tracker->recordUnknown(
            '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'PC-101',
            '192.168.1.5',
        );

        // Aucun MachineBootLog inséré.
        self::assertSame(0, MachineBootLog::count());

        Log::shouldHaveReceived('channel')->atLeast()->once();
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    #[Test]
    public function it_oobe_complete_is_idempotent(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordOobeComplete($ws, 'PC-101', '192.168.1.5');
        $this->tracker->recordOobeComplete($ws, 'PC-101', '192.168.1.5');

        $ws->refresh();
        // os reste 'windows' (idempotent).
        self::assertSame('windows', $ws->os);
        // 2 MachineBootLog (1 par appel — audit traçabilité).
        $count = MachineBootLog::where('workstation_id', $ws->id)
            ->where('action', 'ipxe_win_report')
            ->count();
        self::assertSame(2, $count);
    }

    #[Test]
    public function it_status_strings_are_ascii_strict(): void
    {
        // Garde-fou : éviter qu'un futur dev change les constantes en
        // « installation Windows terminée » (avec accent é).
        self::assertSame('installation WinPE', WindowsPostInstallTracker::STATUS_WINPE);
        self::assertSame('installation Windows terminee', WindowsPostInstallTracker::STATUS_OOBE_COMPLETE);
        // Test bytes-level : aucun byte > 0x7E.
        self::assertMatchesRegularExpression(
            '/^[\x20-\x7E]+$/',
            WindowsPostInstallTracker::STATUS_WINPE,
        );
        self::assertMatchesRegularExpression(
            '/^[\x20-\x7E]+$/',
            WindowsPostInstallTracker::STATUS_OOBE_COMPLETE,
        );
    }

    /* ------------------------------------------------------------------
     * Post-review #6 — parité 3.4 post-review #M3 (Linux).
     *
     * Préserver `status='protected'` post-install Windows. Le marqueur
     * `protected` sert d'anti-suppression DB lors des resync AD et ne
     * doit pas être écrasé silencieusement par un boot WinPE / OOBE.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_preserves_protected_status_after_winpe_start(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-101',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'protected',
        ]);

        $this->tracker->recordWinpeStart($ws, 'PC-101', '192.168.1.5');

        $fresh = $ws->fresh();
        self::assertNotNull($fresh);
        // Status `protected` restauré au lieu de `installation WinPE`.
        self::assertSame('protected', $fresh->status);

        // MachineBootLog quand même inséré (audit conservé).
        $count = MachineBootLog::query()
            ->where('action', 'ipxe_win_install')
            ->where('workstation_id', $ws->id)
            ->count();
        self::assertSame(1, $count);
    }

    #[Test]
    public function it_preserves_protected_status_after_oobe_complete(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-101',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'protected',
        ]);

        $this->tracker->recordOobeComplete($ws, 'PC-101', '192.168.1.5');

        $fresh = $ws->fresh();
        self::assertNotNull($fresh);
        // Status `protected` restauré au lieu de `installation Windows terminee`.
        self::assertSame('protected', $fresh->status);
        // Les autres effets de l'install sont conservés (os + last_report_at).
        self::assertSame('windows', $fresh->os);
        self::assertNotNull($fresh->last_report_at);

        // MachineBootLog quand même inséré (audit conservé).
        $count = MachineBootLog::query()
            ->where('action', 'ipxe_win_report')
            ->where('workstation_id', $ws->id)
            ->count();
        self::assertSame(1, $count);
    }
}
