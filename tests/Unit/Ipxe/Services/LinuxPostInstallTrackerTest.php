<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\LinuxPostInstallTracker;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.4 — AC3.2 / T3.2.
 *
 * Tests unitaires de {@see LinuxPostInstallTracker}.
 */
class LinuxPostInstallTrackerTest extends TestCase
{
    private LinuxPostInstallTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        // Post-review #M4 — clock freeze pour rendre les assertions
        // `last_report_at` déterministes (sinon `Carbon::now()` change à
        // chaque appel et un bug `Carbon::yesterday()` resterait invisible).
        Carbon::setTestNow('2026-05-21 10:00:00');
        $this->tracker = new LinuxPostInstallTracker();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeWorkstation(string $status = 'active'): Workstation
    {
        return Workstation::create([
            'name' => 'PC-101',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => $status,
        ]);
    }

    #[Test]
    public function it_updates_workstation_on_success_ret_zero(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->record($ws, 0, 'PC-101', '192.168.1.42');

        $ws->refresh();
        self::assertSame('linux', $ws->os);
        // Fix install-debian — `status` n'est PLUS écrasé (domaine fermé
        // varchar(20) ; la phrase d'issue provoquait un SQLSTATE 22001).
        self::assertSame('active', $ws->status);
        // Marqueur one-shot posé pour l'écran post-install (consommé au boot).
        self::assertIsArray($ws->programmed_action);
        self::assertSame(LinuxPostInstallTracker::ACTION_INSTALL_DONE, $ws->programmed_action['type']);
        self::assertSame(0, $ws->programmed_action['ret']);
        self::assertNotNull($ws->last_report_at);
        // Post-review #M4 — assertion déterministe sur le timestamp.
        self::assertTrue(
            Carbon::parse((string) $ws->last_report_at)->equalTo(Carbon::parse('2026-05-21 10:00:00')),
            'last_report_at doit valoir Carbon::now() au moment du record',
        );
    }

    #[Test]
    public function it_updates_workstation_on_failure_ret_nonzero(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->record($ws, 42, 'PC-101', '192.168.1.42');

        $ws->refresh();
        self::assertSame('linux', $ws->os);
        // `status` lifecycle préservé ; l'échec est porté par le marqueur
        // `programmed_action` (+ MachineBootLog success=false).
        self::assertSame('active', $ws->status);
        self::assertSame(LinuxPostInstallTracker::ACTION_INSTALL_FAILED, $ws->programmed_action['type']);
        self::assertSame(42, $ws->programmed_action['ret']);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_linux_report(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->record($ws, 0, 'PC-101', '');

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_linux_report')
            ->where('workstation_id', $ws->id)
            ->count();

        self::assertSame(1, $count);
    }

    #[Test]
    public function it_handles_unknown_workstation_via_record_unknown(): void
    {
        // recordUnknown ne touche pas la DB — juste log warning.
        $this->tracker->recordUnknown('aa:bb:cc:dd:ee:01', 'unknown-uuid', '192.168.1.42');

        // Aucune row MachineBootLog créée.
        $count = MachineBootLog::query()->where('action', 'ipxe_linux_report')->count();
        self::assertSame(0, $count);
    }

    #[Test]
    public function it_does_not_overwrite_lifecycle_status(): void
    {
        // Fix install-debian — le record post-install ne doit jamais toucher
        // la colonne `status` (domaine fermé active|inactive|protected). Ici un
        // poste `inactive` doit le rester après un record réussi.
        $ws = $this->makeWorkstation('inactive');
        $this->tracker->record($ws, 0, 'PC-101', '');
        $ws->refresh();

        self::assertSame('inactive', $ws->status);
        self::assertSame('linux', $ws->os);
    }

    /* ------------------------------------------------------------------
     * Post-review #M3 — préservation `status='protected'` post-install.
     *
     * Décision Henri : le legacy `flag_poste=1` ne bloque JAMAIS la
     * réinstall iPXE — il sert uniquement de protection anti-suppression
     * DB. Depuis le fix install-debian, `record()` ne touche plus du tout
     * `status` : la préservation de `'protected'` (comme de toute valeur
     * lifecycle) est donc native. Les autres effets de l'install — os,
     * last_report_at, programmed_action, MachineBootLog — sont conservés.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_preserves_protected_status_after_successful_install(): void
    {
        $ws = $this->makeWorkstation('protected');
        $this->tracker->record($ws, 0, 'PC-101', '192.168.1.42');

        $fresh = $ws->fresh();
        self::assertNotNull($fresh);
        // Status `protected` préservé (record() ne touche plus `status`).
        self::assertSame('protected', $fresh->status);
        // Les autres effets de l'install sont conservés (os + last_report_at).
        self::assertSame('linux', $fresh->os);
        self::assertNotNull($fresh->last_report_at);

        // Row MachineBootLog avec succès créée.
        $count = MachineBootLog::query()
            ->where('action', 'ipxe_linux_report')
            ->where('workstation_id', $ws->id)
            ->where('success', true)
            ->count();
        self::assertSame(1, $count);
    }

    #[Test]
    public function it_preserves_protected_status_after_failed_install(): void
    {
        $ws = $this->makeWorkstation('protected');
        $this->tracker->record($ws, 1, 'PC-101', '192.168.1.42');

        $fresh = $ws->fresh();
        self::assertNotNull($fresh);
        // Status `protected` préservé (record() ne touche plus `status`).
        self::assertSame('protected', $fresh->status);
        self::assertSame('linux', $fresh->os);
        self::assertNotNull($fresh->last_report_at);

        // Row MachineBootLog avec succès=false créée.
        $count = MachineBootLog::query()
            ->where('action', 'ipxe_linux_report')
            ->where('workstation_id', $ws->id)
            ->where('success', false)
            ->count();
        self::assertSame(1, $count);
    }
}
