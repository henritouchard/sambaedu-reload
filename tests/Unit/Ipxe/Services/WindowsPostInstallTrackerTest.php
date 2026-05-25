<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\WindowsPostInstallTracker;
use App\Ldap\AdMachineManager;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery;
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

    /* ==================================================================
     * Story 3.8 — AC5.1-5.7 / T4.4 — Tests des 14+ méthodes record*.
     * ================================================================== */

    #[Test]
    public function it_records_sysprep_initiated_with_clonage_type(): void
    {
        $ws = $this->makeWorkstation();
        $ws->programmed_action = ['type' => 'clonage'];
        $ws->save();

        $this->tracker->recordSysprepInitiated($ws);

        $fresh = $ws->fresh();
        self::assertSame('preparation 1er boot', $fresh->status);
        self::assertSame('0%', $fresh->progress);
        $pa = $fresh->programmed_action;
        self::assertSame('clonage', $pa['type']);
        self::assertSame('modele', $pa['role']);
        self::assertSame('sysprep', $pa['etape']);
        self::assertSame(1, MachineBootLog::where('action', 'ipxe_win_sysprep')->count());
    }

    #[Test]
    public function it_records_sysprep_initiated_without_clonage_keeps_status(): void
    {
        $ws = $this->makeWorkstation();

        $this->tracker->recordSysprepInitiated($ws);

        $fresh = $ws->fresh();
        // Status non modifié (legacy : seul progress=0%).
        self::assertSame('active', $fresh->status);
        self::assertSame('0%', $fresh->progress);
        // etape ajouté dans programmed_action.
        self::assertSame('sysprep', $fresh->programmed_action['etape']);
    }

    #[Test]
    public function it_records_sysprep_gpo_start_with_clonage2(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordSysprepGpoStart($ws);

        $fresh = $ws->fresh();
        self::assertSame('preparation image', $fresh->status);
        self::assertSame('50%', $fresh->progress);
        $pa = $fresh->programmed_action;
        self::assertSame('clonage2', $pa['type']);
        self::assertSame('modele', $pa['role']);
        self::assertSame('windows', $pa['script']);
        self::assertSame(0, $pa['ret']);
    }

    #[Test]
    public function it_records_sysprep_generalized(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordSysprepGeneralized($ws);

        $fresh = $ws->fresh();
        self::assertSame('sysprep generalisation', $fresh->status);
        self::assertSame('50%', $fresh->progress);
        $pa = $fresh->programmed_action;
        self::assertSame('modele', $pa['role']);
        self::assertSame('rescuecd', $pa['script']);
        self::assertSame(-1, $pa['ret']);
        self::assertSame('init-modele', $pa['etape']);
    }

    #[Test]
    public function it_records_sysprep_none_clone(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordSysprepNoneClone($ws);

        $fresh = $ws->fresh();
        self::assertSame('clonage sans sysprep', $fresh->status);
        self::assertSame('100%', $fresh->progress);
        $pa = $fresh->programmed_action;
        self::assertSame('clonage2', $pa['type']);
        self::assertSame('rescuecd', $pa['script']);
        self::assertSame('init-modele', $pa['etape']);
    }

    #[Test]
    public function it_records_nosysprep_with_etape_distinct_q2(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordNosysprep($ws);

        $fresh = $ws->fresh();
        self::assertSame('50%', $fresh->progress);
        // Q-2 refacto clarté — etape='nosysprep' distinct (PAS sysprep).
        self::assertSame('nosysprep', $fresh->programmed_action['etape']);
        self::assertSame(1, MachineBootLog::where('action', 'ipxe_win_nosysprep')->count());
    }

    #[Test]
    public function it_records_join_initiated(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordJoinInitiated($ws);

        $fresh = $ws->fresh();
        self::assertSame('mise au domaine v2', $fresh->status);
        self::assertSame('0%', $fresh->progress);
        self::assertSame('windows', $fresh->programmed_action['role']);
        self::assertSame('join', $fresh->programmed_action['etape']);
    }

    #[Test]
    public function it_records_join_adminse_started_ret_0(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordJoinAdminseStarted($ws);

        $fresh = $ws->fresh();
        self::assertSame('renommage sans sysprep OK', $fresh->status);
        self::assertSame('30%', $fresh->progress);
        $pa = $fresh->programmed_action;
        self::assertSame('clonage2', $pa['type']);
        self::assertSame(0, $pa['ret']);
    }

    #[Test]
    public function it_records_join_domained_ret_1(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordJoinDomained($ws);

        $fresh = $ws->fresh();
        self::assertSame('mise au domaine sans sysprep OK', $fresh->status);
        self::assertSame('60%', $fresh->progress);
        self::assertSame(1, $fresh->programmed_action['ret']);
    }

    #[Test]
    public function it_records_join_complete_ret_2(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordJoinComplete($ws);

        $fresh = $ws->fresh();
        self::assertSame('clonage termine', $fresh->status);
        self::assertSame('100%', $fresh->progress);
        self::assertSame('default', $fresh->programmed_action['etape']);
        self::assertSame(-1, $fresh->programmed_action['ret']);
    }

    #[Test]
    public function it_records_renomme_initiated(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordRenommeInitiated($ws);

        $fresh = $ws->fresh();
        self::assertSame('renommage au domaine', $fresh->status);
        self::assertSame('20%', $fresh->progress);
        self::assertSame('renomme', $fresh->programmed_action['etape']);
    }

    #[Test]
    public function it_records_renomme_ad_renamed_success(): void
    {
        $ws = $this->makeWorkstation();

        $adManager = Mockery::mock(AdMachineManager::class);
        $adManager->shouldReceive('renameComputer')
            ->once()
            ->with('PC-101', 'pc-renamed-01')
            ->andReturn(true);

        $this->tracker->recordRenommeAdRenamed($ws, $adManager, 'pc-renamed-01');

        $fresh = $ws->fresh();
        self::assertSame('renommage dans AD OK', $fresh->status);
        self::assertSame('60%', $fresh->progress);
        $pa = $fresh->programmed_action;
        self::assertSame('renomme', $pa['type']);
        self::assertSame('pc-renamed-01', $pa['role']);
        self::assertSame(0, $pa['ret']);
    }

    #[Test]
    public function it_records_renomme_ad_renamed_failure(): void
    {
        $ws = $this->makeWorkstation();

        $adManager = Mockery::mock(AdMachineManager::class);
        $adManager->shouldReceive('renameComputer')
            ->once()
            ->andReturn(false);

        $this->tracker->recordRenommeAdRenamed($ws, $adManager, 'pc-renamed-01');

        $fresh = $ws->fresh();
        self::assertSame('ERREUR renommage AD impossible', $fresh->status);
        self::assertSame('40%', $fresh->progress);
    }

    #[Test]
    public function it_records_renomme_ad_renamed_throws_handled_as_failure(): void
    {
        $ws = $this->makeWorkstation();

        $adManager = Mockery::mock(AdMachineManager::class);
        $adManager->shouldReceive('renameComputer')
            ->once()
            ->andThrow(new \RuntimeException('samba-tool not available'));

        $this->tracker->recordRenommeAdRenamed($ws, $adManager, 'pc-renamed-01');

        $fresh = $ws->fresh();
        // Exception catched + traité comme failure (40%).
        self::assertSame('ERREUR renommage AD impossible', $fresh->status);
        self::assertSame('40%', $fresh->progress);
    }

    #[Test]
    public function it_records_renomme_ad_renamed_with_empty_role(): void
    {
        $ws = $this->makeWorkstation();
        $adManager = Mockery::mock(AdMachineManager::class);
        // renameComputer NE DOIT PAS être appelé si role vide.
        $adManager->shouldNotReceive('renameComputer');

        $this->tracker->recordRenommeAdRenamed($ws, $adManager, '');

        $fresh = $ws->fresh();
        self::assertSame('ERREUR pas de nouveau nom', $fresh->status);
        self::assertSame('20%', $fresh->progress);
    }

    #[Test]
    public function it_records_renomme_finished(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordRenommeFinished($ws);

        $fresh = $ws->fresh();
        self::assertSame('Renommage termine', $fresh->status);
        self::assertSame('100%', $fresh->progress);
        self::assertSame('default', $fresh->programmed_action['type']);
        self::assertSame(-1, $fresh->programmed_action['ret']);
    }

    #[Test]
    public function it_records_post_initiated(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordPostInitiated($ws);

        $fresh = $ws->fresh();
        self::assertSame('post-mise au domaine manuelle', $fresh->status);
        self::assertSame('20%', $fresh->progress);
        self::assertSame('post', $fresh->programmed_action['etape']);
    }

    #[Test]
    public function it_records_post_autologon(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordPostAutologon($ws);

        $fresh = $ws->fresh();
        self::assertSame('script de demarrage post-install OK', $fresh->status);
        self::assertSame('50%', $fresh->progress);
        self::assertSame(0, $fresh->programmed_action['ret']);
    }

    #[Test]
    public function it_records_post_finished(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordPostFinished($ws);

        $fresh = $ws->fresh();
        self::assertSame('100%', $fresh->progress);
        self::assertSame(-1, $fresh->programmed_action['ret']);
    }

    #[Test]
    public function it_records_wpkg_initiated(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordWpkgInitiated($ws);

        $fresh = $ws->fresh();
        self::assertSame('lancement de wpkg en mode interactif', $fresh->status);
        self::assertSame('10%', $fresh->progress);
        self::assertSame('wpkg', $fresh->programmed_action['etape']);
    }

    #[Test]
    public function it_records_wpkg_autologon(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordWpkgAutologon($ws);

        $fresh = $ws->fresh();
        self::assertSame('lancement de wpkg interactif', $fresh->status);
        self::assertSame('50%', $fresh->progress);
        self::assertSame(0, $fresh->programmed_action['ret']);
    }

    #[Test]
    public function it_records_wpkg_finished(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordWpkgFinished($ws);

        $fresh = $ws->fresh();
        self::assertSame('exec wpkg fini', $fresh->status);
        self::assertSame('100%', $fresh->progress);
        self::assertSame(-1, $fresh->programmed_action['ret']);
    }

    #[Test]
    public function it_records_default_sets_os_windows_and_termine(): void
    {
        $ws = $this->makeWorkstation();
        $this->tracker->recordDefault($ws);

        $fresh = $ws->fresh();
        self::assertSame('windows', $fresh->os);
        self::assertSame('termine', $fresh->status);
        self::assertSame('100%', $fresh->progress);
        self::assertSame('default', $fresh->programmed_action['etape']);
        self::assertSame(-1, $fresh->programmed_action['ret']);
    }

    #[Test]
    public function it_merges_programmed_action_preserves_unrelated_keys(): void
    {
        $ws = $this->makeWorkstation();
        // Pre-existing custom key in programmed_action.
        $ws->programmed_action = ['custom_field' => 'preserved', 'type' => 'old'];
        $ws->save();

        $this->tracker->recordJoinInitiated($ws);

        $fresh = $ws->fresh();
        $pa = $fresh->programmed_action;
        // Custom key préservé (merge sémantique cohérent).
        self::assertSame('preserved', $pa['custom_field']);
        // type 'old' aussi préservé (recordJoinInitiated ne touche pas 'type').
        self::assertSame('old', $pa['type']);
        // Nouvelles clés ajoutées.
        self::assertSame('windows', $pa['role']);
        self::assertSame('join', $pa['etape']);
    }

    #[Test]
    public function it_preserves_protected_status_on_sysprep_initiated(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-101',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'status' => 'protected',
            'programmed_action' => ['type' => 'clonage'],
        ]);

        $this->tracker->recordSysprepInitiated($ws);

        $fresh = $ws->fresh();
        // Status 'protected' préservé malgré l'update logique.
        self::assertSame('protected', $fresh->status);
        // Mais progress + programmed_action mis à jour.
        self::assertSame('0%', $fresh->progress);
        self::assertSame('modele', $fresh->programmed_action['role']);
    }

    #[Test]
    public function it_preserves_protected_status_on_renomme_ad_renamed(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-101',
            'uuid' => '12345678-1234-1234-1234-cccccccccccc',
            'mac' => 'aa:bb:cc:dd:ee:03',
            'status' => 'protected',
        ]);

        $adManager = Mockery::mock(AdMachineManager::class);
        $adManager->shouldReceive('renameComputer')->andReturn(true);

        $this->tracker->recordRenommeAdRenamed($ws, $adManager, 'pc-renamed-01');

        $fresh = $ws->fresh();
        // Status 'protected' préservé.
        self::assertSame('protected', $fresh->status);
        self::assertSame('60%', $fresh->progress);
    }

    #[Test]
    public function it_persists_six_distinct_machine_boot_log_labels(): void
    {
        // Validation D11 — les 6 labels sont émis distinctement.
        $ws = $this->makeWorkstation();

        $this->tracker->recordSysprepInitiated($ws);
        $this->tracker->recordNosysprep($ws);
        $this->tracker->recordJoinInitiated($ws);
        $this->tracker->recordRenommeInitiated($ws);
        $this->tracker->recordPostInitiated($ws);
        $this->tracker->recordWpkgInitiated($ws);

        self::assertSame(1, MachineBootLog::where('action', 'ipxe_win_sysprep')->count());
        self::assertSame(1, MachineBootLog::where('action', 'ipxe_win_nosysprep')->count());
        self::assertSame(1, MachineBootLog::where('action', 'ipxe_win_join')->count());
        self::assertSame(1, MachineBootLog::where('action', 'ipxe_win_renomme')->count());
        self::assertSame(1, MachineBootLog::where('action', 'ipxe_win_post')->count());
        self::assertSame(1, MachineBootLog::where('action', 'ipxe_win_wpkg')->count());

        // Tous ≤ 20 chars (varchar(20) parité D11).
        foreach (['ipxe_win_sysprep', 'ipxe_win_nosysprep', 'ipxe_win_join', 'ipxe_win_renomme', 'ipxe_win_post', 'ipxe_win_wpkg'] as $label) {
            self::assertLessThanOrEqual(20, strlen($label), "Label {$label} > 20 chars (varchar(20) overflow).");
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
