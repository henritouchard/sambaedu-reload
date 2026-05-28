<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Ldap\AdMachineManager;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.8 — T5.2 / AC13.1-13.12.
 *
 * Tests feature de la route native `POST /ipxe/windows/action` étendue aux
 * 6 étapes post-OOBE (sysprep/nosysprep/join/renomme/post/wpkg).
 */
class IpxeWindowsActionEndpointPostOobeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
            'sambaedu.se4install_name' => 'se4install',
            'sambaedu.se4install_passwd' => 'TestPwd1234',
            'sambaedu.windows.adminse_name' => 'admin',
            'sambaedu.windows.adminse_passwd' => 'AdminPwd1234',
            'sambaedu.domain' => 'localdev.fr',
            'sambaedu.se4fs_name' => 'se4fs',
            // Story 3.8 D13 — défaut activé.
            'ipxe.windows.post_install.enabled' => true,
            'ipxe.windows.post_install.sysprep_enabled' => true,
            'ipxe.windows.post_install.nosysprep_enabled' => true,
            'ipxe.windows.post_install.join_enabled' => true,
            'ipxe.windows.post_install.renomme_enabled' => true,
            'ipxe.windows.post_install.post_enabled' => true,
            'ipxe.windows.post_install.wpkg_enabled' => true,
        ]);
    }

    private function seedWorkstation(string $uuid = '12345678-1234-1234-1234-aaaaaaaaaaaa', string $mac = 'aa:bb:cc:dd:ee:01', string $name = 'pc-techno-25'): Workstation
    {
        return Workstation::create([
            'name' => $name,
            'uuid' => $uuid,
            'mac' => $mac,
            'status' => 'active',
        ]);
    }

    /* ---------------------------------------------------------------
     * AC13.2 — sysprep initial avec type=clonage → body cmd batch
     * --------------------------------------------------------------- */

    #[Test]
    public function it_returns_cmd_body_for_sysprep_initial_with_clonage(): void
    {
        $ws = $this->seedWorkstation();
        $ws->programmed_action = ['type' => 'clonage'];
        $ws->save();

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'sysprep',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $body = (string) $response->getContent();
        self::assertNotSame('', $body);
        self::assertStringContainsString(':gpo', $body);
        self::assertStringContainsString(':sysprep', $body);
        // CRLF strict.
        self::assertStringContainsString("\r\n", $body);
        // Aucun LF orphelin.
        $woCrlf = str_replace("\r\n", '', $body);
        self::assertStringNotContainsString("\n", $woCrlf);

        $ws->refresh();
        self::assertSame('preparation 1er boot', $ws->status);
        self::assertSame('0%', $ws->progress);
        self::assertSame('modele', $ws->programmed_action['role']);
    }

    /* ---------------------------------------------------------------
     * Review #7 — sysprep initial SANS clonage (type=default) → body vide
     * (cas le plus courant : poste qui démarre une install standard).
     * --------------------------------------------------------------- */

    #[Test]
    public function it_returns_empty_body_for_sysprep_initial_without_clonage(): void
    {
        // Pas de programmed_action → type=default (pas de clonage programmé).
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'sysprep',
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());

        $ws->refresh();
        // progress set à 0% malgré body vide (parité legacy ligne 428).
        self::assertSame('0%', $ws->progress);
    }

    /* ---------------------------------------------------------------
     * Review #3 — l'OU cible (et le role) doivent être persistés à l'init
     * et ré-injectés au 2e curl (ret=0) que le poste envoie SANS role/ou.
     * Sans ça : Add-Computer -OUPath '' → mauvais container AD.
     * --------------------------------------------------------------- */

    #[Test]
    public function it_persists_and_resolves_join_ou_across_curl_steps(): void
    {
        $ws = $this->seedWorkstation();
        $targetOu = 'OU=salle1,OU=computers,DC=localdev,DC=fr';

        // 1er curl (initial — l'admin fournit l'OU cible) → persiste l'OU.
        $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'join',
            'ou' => $targetOu,
        ]);
        $ws->refresh();
        self::assertSame($targetOu, $ws->programmed_action['ou'] ?? null);

        // 2e curl (ret=0 — le poste ne re-envoie PAS l'OU) → body doit
        // contenir l'OU résolue depuis programmed_action, pas une chaîne vide.
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'join',
            'ret' => '0',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString($targetOu, $body);
        // Garde-fou anti-régression #3 : jamais de OUPath vide.
        self::assertStringNotContainsString("-OUPath ''", $body);
    }

    /* ---------------------------------------------------------------
     * AC13.3 — sysprep ret=0 → body vide + state machine avance
     * --------------------------------------------------------------- */

    #[Test]
    public function it_returns_empty_body_for_sysprep_ret_0(): void
    {
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'sysprep',
            'ret' => '0',
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());

        $ws->refresh();
        self::assertSame('preparation image', $ws->status);
        self::assertSame('50%', $ws->progress);
        self::assertSame('clonage2', $ws->programmed_action['type']);
    }

    #[Test]
    public function it_returns_empty_body_for_sysprep_ret_1_generalized(): void
    {
        $ws = $this->seedWorkstation();
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'sysprep',
            'ret' => '1',
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());

        $ws->refresh();
        self::assertSame('sysprep generalisation', $ws->status);
        self::assertSame('50%', $ws->progress);
    }

    #[Test]
    public function it_returns_empty_body_for_sysprep_ret_2_none_clone(): void
    {
        $ws = $this->seedWorkstation();
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'sysprep',
            'ret' => '2',
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());

        $ws->refresh();
        self::assertSame('clonage sans sysprep', $ws->status);
        self::assertSame('100%', $ws->progress);
    }

    /* ---------------------------------------------------------------
     * Q-2 refacto clarté — nosysprep distinct
     * --------------------------------------------------------------- */

    #[Test]
    public function it_returns_cmd_body_for_nosysprep_initial(): void
    {
        $ws = $this->seedWorkstation();
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'nosysprep',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertNotSame('', $body);
        self::assertStringContainsString('etape=nosysprep', $body);
        self::assertStringNotContainsString('etape=sysprep" -F "ret=2"', $body);

        $ws->refresh();
        self::assertSame('50%', $ws->progress);
        self::assertSame('nosysprep', $ws->programmed_action['etape']);
    }

    /* ---------------------------------------------------------------
     * AC13.4 — join initial
     * --------------------------------------------------------------- */

    #[Test]
    public function it_returns_cmd_body_for_join_initial(): void
    {
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'join',
            'role' => 'pc-renamed-01',
            'ou' => 'OU=techno,DC=localdev,DC=fr',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString(':join', $body);
        self::assertStringContainsString('Add-Computer', $body);
        self::assertStringContainsString("-OUPath 'OU=techno,DC=localdev,DC=fr'", $body);

        $ws->refresh();
        self::assertSame('mise au domaine v2', $ws->status);
        self::assertSame('0%', $ws->progress);
    }

    /* ---------------------------------------------------------------
     * AC13.5 — renomme ret=0 → écriture PG `name = role` (observer-driven).
     *
     * Story 4.9 : refactor — plus d'appel direct à AdMachineManager,
     * le rename AD est déclenché par l'observer + WorkstationAdSyncJob.
     * --------------------------------------------------------------- */

    #[Test]
    public function it_invokes_ad_rename_on_renomme_ret_0(): void
    {
        $ws = $this->seedWorkstation();

        $adMock = Mockery::mock(AdMachineManager::class);
        // Story 4.9 : adManager non utilisé par recordRenommeAdRenamed.
        $adMock->shouldNotReceive('renameComputer');
        $this->app->instance(AdMachineManager::class, $adMock);

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'renomme',
            'ret' => '0',
            'role' => 'pc-renamed-01',
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());

        $ws->refresh();
        // Story 4.9 fix root cause : le nom PG est écrit en transaction.
        self::assertSame('pc-renamed-01', $ws->name);
        self::assertSame('renommage dans AD OK', $ws->status);
        self::assertSame('60%', $ws->progress);
    }

    /* ---------------------------------------------------------------
     * AC13.7 — config toggle disabled → body vide
     * --------------------------------------------------------------- */

    #[Test]
    public function it_returns_empty_body_when_post_install_enabled_false(): void
    {
        config(['ipxe.windows.post_install.enabled' => false]);
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'sysprep',
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());
        $ws->refresh();
        // State machine inchangée car step désactivé.
        self::assertSame('active', $ws->status);
    }

    #[Test]
    public function it_returns_empty_body_when_specific_step_disabled(): void
    {
        config(['ipxe.windows.post_install.sysprep_enabled' => false]);
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'sysprep',
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());
        $ws->refresh();
        self::assertSame('active', $ws->status);
    }

    /* ---------------------------------------------------------------
     * AC13.8 — Rule::in 422 sur etape=arbitrary
     * --------------------------------------------------------------- */

    #[Test]
    public function it_rejects_etape_arbitrary_with_422(): void
    {
        $ws = $this->seedWorkstation();

        $response = $this->postJson('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'arbitrary-evil',
        ]);

        // FormRequest avec Rule::in rejette → 422.
        $response->assertStatus(422);
    }

    /* ---------------------------------------------------------------
     * AC13.10 — non-régression 3.5 winpe
     * --------------------------------------------------------------- */

    #[Test]
    public function it_records_winpe_start_unchanged_for_non_regression(): void
    {
        $ws = $this->seedWorkstation();
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'winpe',
            'ret' => '0',
        ]);
        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());
        $ws->refresh();
        self::assertSame('installation WinPE', $ws->status);
    }

    /* ---------------------------------------------------------------
     * AC13.11 — non-régression 3.5 oobe
     * --------------------------------------------------------------- */

    #[Test]
    public function it_records_oobe_complete_unchanged_for_non_regression(): void
    {
        $ws = $this->seedWorkstation();
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'oobe',
            'ret' => '0',
        ]);
        $response->assertStatus(200);
        $ws->refresh();
        self::assertSame('windows', $ws->os);
        self::assertSame('installation Windows terminee', $ws->status);
    }

    /* ---------------------------------------------------------------
     * AC13.12 — sécurité : injection cmd dans `role` → 200 body vide + log
     * --------------------------------------------------------------- */

    #[Test]
    public function it_returns_empty_body_on_role_injection_attempt(): void
    {
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'join',
            'role' => 'pc-evil&calc.exe',  // Injection cmd.exe
        ]);

        // 200 + body vide (BatPlaceholderInjectionException catched).
        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());
    }

    /* ---------------------------------------------------------------
     * Tests complémentaires — post + wpkg flows
     * --------------------------------------------------------------- */

    #[Test]
    public function it_returns_cmd_body_for_post_initial(): void
    {
        $ws = $this->seedWorkstation();
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'post',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertNotSame('', $body);
        self::assertStringContainsString('robocopy', $body);

        $ws->refresh();
        self::assertSame('post-mise au domaine manuelle', $ws->status);
        self::assertSame('20%', $ws->progress);
    }

    #[Test]
    public function it_returns_cmd_body_for_wpkg_initial(): void
    {
        $ws = $this->seedWorkstation();
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'wpkg',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertNotSame('', $body);
        self::assertStringContainsString('driversAuto.ps1', $body);

        $ws->refresh();
        self::assertSame('lancement de wpkg en mode interactif', $ws->status);
        self::assertSame('10%', $ws->progress);
    }

    #[Test]
    public function it_records_renomme_finished_on_ret_1(): void
    {
        $ws = $this->seedWorkstation();
        $response = $this->post('/ipxe/windows/action', [
            'uuid' => $ws->uuid,
            'name' => 'pc-techno-25',
            'etape' => 'renomme',
            'ret' => '1',
        ]);

        $response->assertStatus(200);
        $ws->refresh();
        self::assertSame('Renommage termine', $ws->status);
        self::assertSame('100%', $ws->progress);
    }

    /* ---------------------------------------------------------------
     * MachineBootLog labels distincts (D11 / AC5.3)
     * --------------------------------------------------------------- */

    #[Test]
    public function it_persists_distinct_machine_boot_log_for_each_step(): void
    {
        $ws = $this->seedWorkstation();

        $steps = ['sysprep', 'nosysprep', 'join', 'renomme', 'post', 'wpkg'];
        foreach ($steps as $step) {
            $this->post('/ipxe/windows/action', [
                'uuid' => $ws->uuid,
                'name' => 'pc-techno-25',
                'etape' => $step,
            ]);
        }

        foreach ($steps as $step) {
            self::assertGreaterThanOrEqual(1,
                MachineBootLog::where('action', "ipxe_win_{$step}")->count(),
                "MachineBootLog label ipxe_win_{$step} doit etre persiste"
            );
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
