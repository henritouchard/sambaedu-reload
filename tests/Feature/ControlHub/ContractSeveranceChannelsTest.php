<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubLinkState;
use App\Events\ControlHubContractChanged;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\ControlHubLinkAuditLog;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 32.1 (Q4) — Les DEUX canaux du signal de rupture (commande artisan
 * `controlhub:sever-link` + endpoint `controlhub.auth`) partagent le service
 * UNIQUE `ControlHubContractSeveranceService`. Ce test prouve que chaque canal
 * applique la rupture de façon idempotente et trace son origine.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`. ⚠️ GARDE-FOU R3 : aucun
 * « central » ; vocabulaire « amont » / `ControlHub*`.
 */
class ContractSeveranceChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();
        Event::fake([ControlHubContractChanged::class]);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ── Canal commande ────────────────────────────────────────────────────────

    #[Test]
    public function the_command_severs_the_active_contract_and_traces_origin_command(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->for($contract, 'contract')->create();

        $this->artisan('controlhub:sever-link', ['--actor' => 'op-refnum', '--reason' => 'fin de contrat'])
            ->assertExitCode(0);

        self::assertSame(ControlHubLinkState::Severed, $contract->fresh()->link_state);
        $log = ControlHubLinkAuditLog::sole();
        self::assertSame(ControlHubLinkAuditLog::ORIGIN_COMMAND, $log->origin);
        self::assertSame('op-refnum', $log->actor_label);
        self::assertSame('fin de contrat', $log->reason);
    }

    #[Test]
    public function the_command_is_a_noop_in_standalone(): void
    {
        $this->artisan('controlhub:sever-link')
            ->expectsOutputToContain('Aucun contrat amont actif')
            ->assertExitCode(0);

        self::assertSame(0, ControlHubLinkAuditLog::count());
    }

    // ── Canal endpoint (controlhub.auth) ──────────────────────────────────────

    #[Test]
    public function the_authenticated_endpoint_severs_the_active_contract(): void
    {
        config(['controlHub.se4fs.instance_api_key' => 'instance-key-0123456789']);

        $contract = ControlHubContract::factory()->create();

        $response = $this->withHeaders(['Authorization' => 'Bearer instance-key-0123456789'])
            ->postJson('/api/v1/controlhub/sever-link', ['reason' => 'révocation']);

        $response->assertOk()
            ->assertJson(['success' => true, 'severed' => true, 'contract_id' => $contract->id]);

        self::assertSame(ControlHubLinkState::Severed, $contract->fresh()->link_state);
        $log = ControlHubLinkAuditLog::sole();
        self::assertSame(ControlHubLinkAuditLog::ORIGIN_API, $log->origin);
        self::assertSame('controlhub:instance', $log->actor_label);
    }

    #[Test]
    public function the_endpoint_rejects_an_unauthenticated_request(): void
    {
        ControlHubContract::factory()->create();

        $this->postJson('/api/v1/controlhub/sever-link')->assertForbidden();

        self::assertSame(0, ControlHubLinkAuditLog::count());
    }
}
