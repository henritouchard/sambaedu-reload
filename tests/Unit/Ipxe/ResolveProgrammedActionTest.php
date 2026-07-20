<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe;

use App\Ipxe\Services\IpxeService;
use App\Models\Workstation;
use App\Models\WorkstationReinstallRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 3.11 — Tests unit de IpxeService::resolveProgrammedAction (AC4/9/11).
 */
class ResolveProgrammedActionTest extends TestCase
{
    use RefreshDatabase;

    private IpxeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IpxeService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_returns_null_when_no_request(): void
    {
        $ws = Workstation::factory()->create();

        $this->assertNull($this->service->resolveProgrammedAction($ws));
    }

    public function test_serves_active_request_and_increments(): void
    {
        $ws = Workstation::factory()->create();
        $req = WorkstationReinstallRequest::factory()->armed()->create([
            'workstation_id' => $ws->id,
            'target_action' => 'install_deb_gnome',
        ]);

        $result = $this->service->resolveProgrammedAction($ws);

        $this->assertSame([
            'name' => 'install_deb_gnome',
            'label' => 'Debian + GNOME (defaut)',
        ], $result);

        $fresh = $req->fresh();
        $this->assertSame(1, $fresh->boot_served_count);
        $this->assertSame(WorkstationReinstallRequest::STATUS_SERVING, $fresh->status);
    }

    public function test_expired_request_returns_null_and_fails(): void
    {
        $ws = Workstation::factory()->create();
        $req = WorkstationReinstallRequest::factory()->armed()->expired()->create([
            'workstation_id' => $ws->id,
        ]);

        $this->assertNull($this->service->resolveProgrammedAction($ws));
        $this->assertSame(WorkstationReinstallRequest::STATUS_FAILED, $req->fresh()->status);
    }

    public function test_serve_cap_returns_null_and_fails(): void
    {
        config(['ipxe.reinstall.max_boot_serves' => 3]);
        $ws = Workstation::factory()->create();
        $req = WorkstationReinstallRequest::factory()->serving()->create([
            'workstation_id' => $ws->id,
            'boot_served_count' => 3,
        ]);

        $this->assertNull($this->service->resolveProgrammedAction($ws));
        $this->assertSame(WorkstationReinstallRequest::STATUS_FAILED, $req->fresh()->status);
    }

    /**
     * Fix review #2 — une planification future ne doit JAMAIS être servie avant
     * l'heure : `resolveProgrammedAction` retourne null, sans markFailed, sans
     * incrémenter boot_served_count, sans changer le statut.
     */
    public function test_future_scheduled_request_is_not_served(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $ws = Workstation::factory()->create();
        $req = WorkstationReinstallRequest::factory()->armed()->create([
            'workstation_id' => $ws->id,
            'target_action' => 'install_deb_gnome',
            'scheduled_at' => Carbon::parse('2026-07-17 20:00:00'),
            'expires_at' => Carbon::parse('2026-07-18 02:00:00'),
        ]);

        $this->assertNull($this->service->resolveProgrammedAction($ws));

        $fresh = $req->fresh();
        $this->assertSame(0, $fresh->boot_served_count);
        $this->assertSame(WorkstationReinstallRequest::STATUS_ARMED, $fresh->status);
    }

    public function test_protected_workstation_refuses_and_cancels(): void
    {
        $ws = Workstation::factory()->protected()->create();
        $req = WorkstationReinstallRequest::factory()->armed()->create([
            'workstation_id' => $ws->id,
        ]);

        $this->assertNull($this->service->resolveProgrammedAction($ws));
        $this->assertSame(WorkstationReinstallRequest::STATUS_CANCELED, $req->fresh()->status);
    }

    /**
     * Une requête `installing` n'est PLUS servie : le payload a été délivré et
     * `setup.exe` redémarre la machine lui-même en fin de phase WinPE. Avec le
     * PXE en tête du boot order ce reboot retombe sur `/ipxe/boot` ; re-servir
     * l'action relancerait WinPE de zéro et l'OOBE ne serait jamais atteint
     * (boucle constatée sur `testenrol` le 2026-07-19, 4 cycles de ~10 min).
     */
    public function test_installing_request_is_not_served_again(): void
    {
        $ws = Workstation::factory()->create();
        $req = WorkstationReinstallRequest::factory()->armed()->create([
            'workstation_id' => $ws->id,
            'target_action' => 'install_win11',
            'status' => WorkstationReinstallRequest::STATUS_INSTALLING,
            'boot_served_count' => 1,
        ]);

        $this->assertNull($this->service->resolveProgrammedAction($ws));

        // Ni serve comptabilisé, ni bascule d'état : le poste tombe simplement
        // sur son disque local pour que l'installation se poursuive.
        $fresh = $req->fresh();
        $this->assertSame(1, $fresh->boot_served_count);
        $this->assertSame(WorkstationReinstallRequest::STATUS_INSTALLING, $fresh->status);
    }
}
