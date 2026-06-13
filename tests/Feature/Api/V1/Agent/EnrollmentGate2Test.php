<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Controllers\Api\V1\Agent\EnrollController;
use App\Models\AgentEnrollmentRequest;
use App\Models\SystemSetting;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\EnrollmentCampaign;
use App\Services\Agent\Enrollment\EnrollmentService;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature porte 2 — Story 25.3 (AC1-AC4, AC6, AC7).
 *
 * Le poste migré rejoue `POST /api/v1/agent/enrollment` SANS ticket : la branche
 * d'échec de `redeem()` crée/rafraîchit une demande pending (403 indistinct,
 * sans oracle), l'admin (ou une campagne bornée concordante) l'approuve, et le
 * PROCHAIN re-POST matérialise le token (200) — il ne transite jamais par l'UI.
 *
 * Setup route iso `EnrollmentEndpointTest` (route cache piège) : collection
 * vierge + re-déclaration à l'identique de `routes/api.php` + route écho
 * derrière `agent.token` pour prouver l'utilisabilité immédiate du token.
 */
final class EnrollmentGate2Test extends TestCase
{
    use RefreshDatabase;

    private const ENROLLMENT_ROUTE = '/api/v1/agent/enrollment';
    private const ECHO_ROUTE = '/_test/agent/echo';

    private EnrollmentService $service;

    private TokenRotationService $tokens;

    private EnrollmentCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = app(TokenRotationService::class);
        $this->service = app(EnrollmentService::class);
        $this->campaign = app(EnrollmentCampaign::class);

        $this->app['router']->setRoutes(new RouteCollection());

        Route::post(self::ENROLLMENT_ROUTE, [EnrollController::class, 'store'])
            ->middleware(['local.request', 'auth.v1.secure-headers', 'throttle:10,1'])
            ->name('agent.v1.enrollment');

        Route::middleware('agent.token')->get(self::ECHO_ROUTE, function (Request $request) {
            return response()->json([
                'workstation_id' => $request->attributes->get('agent.workstation')->id,
            ]);
        });
    }

    /**
     * @param array<string, string|null> $payload
     */
    private function enroll(array $payload): TestResponse
    {
        return $this->postJson(self::ENROLLMENT_ROUTE, $payload);
    }

    private function checkin(string $token): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson(self::ECHO_ROUTE);
    }

    /**
     * Active la campagne (échéance future) via le réglage system_settings réel.
     */
    private function enableCampaign(): void
    {
        $this->campaign->enableUntil(now()->addDay());
    }

    // ── AC1 — demande pending + 403 indistinct + idempotence ────────────

    #[Test]
    public function unknown_workstation_without_ticket_creates_pending_request_and_returns_403(): void
    {
        $handler = new TestHandler();
        Log::channel('agent')->getLogger()->pushHandler($handler);

        $this->enroll(['mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'PC-NEW', 'uuid' => 'u-1'])
            ->assertStatus(403)
            ->assertJson(['code' => EnrollController::CODE_NOT_ALLOWED])
            ->assertJsonStructure(['error', 'message', 'code']);

        $req = AgentEnrollmentRequest::query()->first();
        self::assertNotNull($req);
        self::assertSame(AgentEnrollmentRequest::STATUS_PENDING, $req->status);
        self::assertSame('aa:bb:cc:dd:ee:ff', $req->mac);
        self::assertNull($req->matched_workstation_id);

        self::assertTrue($this->logged($handler, 'agent.enroll.requested'));
    }

    #[Test]
    public function replayed_same_identity_refreshes_request_without_duplicating(): void
    {
        $payload = ['mac' => 'AA-BB-CC-DD-EE-FF', 'hostname' => 'PC-NEW'];

        $this->enroll($payload)->assertStatus(403);
        $first = AgentEnrollmentRequest::query()->first();
        $firstSeen = $first->last_seen_at;

        $this->travel(2)->minutes();
        $this->enroll($payload)->assertStatus(403);

        self::assertSame(1, AgentEnrollmentRequest::query()->count());
        $refreshed = AgentEnrollmentRequest::query()->first();
        self::assertTrue($refreshed->last_seen_at->greaterThan($firstSeen));
        // MAC normalisée des deux côtés malgré les tirets présentés.
        self::assertSame('aa:bb:cc:dd:ee:ff', $refreshed->mac);
    }

    // ── AC3 — campagne ON + concordance → auto-approbation ──────────────

    #[Test]
    public function campaign_on_concordant_known_workstation_is_auto_approved_then_births_token(): void
    {
        $handler = new TestHandler();
        Log::channel('agent')->getLogger()->pushHandler($handler);

        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);
        $this->enableCampaign();

        $identity = ['mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'PC-LAB-01'];

        // Premier POST : auto-approuvé (mais le token ne transite pas) → 403.
        $this->enroll($identity)->assertStatus(403);

        $req = AgentEnrollmentRequest::query()->first();
        self::assertSame(AgentEnrollmentRequest::STATUS_APPROVED, $req->status);
        self::assertTrue($req->auto_approved);
        self::assertSame($ws->id, $req->matched_workstation_id);
        self::assertTrue($this->logged($handler, 'agent.enroll.auto_approved'));

        // Prochain check-in du poste : le token naît → 200 + demande consommée.
        $response = $this->enroll($identity)->assertOk()->assertJson(['success' => true]);
        $token = (string) $response->json('token');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame(0, AgentEnrollmentRequest::query()->count());

        $this->checkin($token)->assertOk()->assertJson(['workstation_id' => $ws->id]);
    }

    #[Test]
    public function campaign_off_concordant_known_workstation_stays_pending_manual(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);

        $identity = ['mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'PC-LAB-01'];
        $this->enroll($identity)->assertStatus(403);

        $req = AgentEnrollmentRequest::query()->first();
        self::assertSame(AgentEnrollmentRequest::STATUS_PENDING, $req->status);
        self::assertFalse($req->auto_approved);
        self::assertSame($ws->id, $req->matched_workstation_id);

        // Aucun token émis tant que non approuvé.
        $this->enroll($identity)->assertStatus(403);
        $ws->refresh();
        self::assertFalse($ws->isAgentEnrolled());
    }

    // ── AC3 — invariant : divergence/inconnu/multi-candidat = manuel ────

    #[Test]
    public function campaign_on_diverging_hostname_never_auto_approves(): void
    {
        Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);
        $this->enableCampaign();

        $this->enroll(['mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'POSTE-PIRATE'])
            ->assertStatus(403);

        $req = AgentEnrollmentRequest::query()->first();
        self::assertSame(AgentEnrollmentRequest::STATUS_PENDING, $req->status);
        self::assertFalse($req->auto_approved);
    }

    #[Test]
    public function campaign_on_unknown_workstation_never_auto_approves(): void
    {
        $this->enableCampaign();

        $this->enroll(['mac' => '99:99:99:99:99:99', 'hostname' => 'PC-INCONNU'])
            ->assertStatus(403);

        $req = AgentEnrollmentRequest::query()->first();
        self::assertSame(AgentEnrollmentRequest::STATUS_PENDING, $req->status);
        self::assertNull($req->matched_workstation_id);
    }

    #[Test]
    public function campaign_on_multi_candidate_mac_never_auto_approves(): void
    {
        Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-A']);
        Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-B']);
        $this->enableCampaign();

        $this->enroll(['mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'PC-A'])
            ->assertStatus(403);

        $req = AgentEnrollmentRequest::query()->first();
        self::assertSame(AgentEnrollmentRequest::STATUS_PENDING, $req->status);
        self::assertNull($req->matched_workstation_id);
    }

    // ── AC4 / piège n° 4 — poste connu déjà enrôlé = conflit 409 ────────

    #[Test]
    public function enrolled_known_workstation_returns_409_and_never_creates_pending(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);
        $token = $this->tokens->issueFor($ws);
        $this->enableCampaign();

        $this->enroll(['mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'PC-LAB-01'])
            ->assertStatus(409)
            ->assertJson(['code' => EnrollController::CODE_CONFLICT]);

        self::assertSame(0, AgentEnrollmentRequest::query()->count());
        // Token courant intact.
        self::assertSame(hash('sha256', $token), $ws->refresh()->agent_token_hash);
    }

    // ── AC3 — campagne expirée → manuel ─────────────────────────────────

    #[Test]
    public function expired_campaign_falls_back_to_manual(): void
    {
        Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);
        // Échéance dans le passé.
        SystemSetting::set(EnrollmentCampaign::SETTING_UNTIL, now()->subHour()->toIso8601String());

        $this->enroll(['mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'PC-LAB-01'])
            ->assertStatus(403);

        $req = AgentEnrollmentRequest::query()->first();
        self::assertSame(AgentEnrollmentRequest::STATUS_PENDING, $req->status);
        self::assertFalse($req->auto_approved);
    }

    // ── AC6 — non-régression porte 1 : ticket valide enrôle directement ─

    #[Test]
    public function valid_ticket_still_enrolls_directly_without_any_request(): void
    {
        $ws = Workstation::factory()->create();
        $ticket = $this->service->openTicket($ws);

        $response = $this->enroll(['ticket' => $ticket])->assertOk()->assertJson(['success' => true]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $response->json('token'));

        // La porte 1 ne crée AUCUNE demande d'enrôlement.
        self::assertSame(0, AgentEnrollmentRequest::query()->count());
    }

    // ── AC2 — approbation manuelle (service) → token au prochain redeem ─

    #[Test]
    public function manual_approval_then_next_redeem_births_token_and_logs_approved(): void
    {
        $handler = new TestHandler();
        Log::channel('agent')->getLogger()->pushHandler($handler);

        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);
        $identity = ['mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'PC-LAB-01'];

        $this->enroll($identity)->assertStatus(403);
        $req = AgentEnrollmentRequest::query()->first();

        // L'admin approuve d'un clic (id admin 42).
        $this->service->approveManually($req, 42);
        self::assertTrue($this->logged($handler, 'agent.enroll.approved'));

        $req->refresh();
        self::assertSame(AgentEnrollmentRequest::STATUS_APPROVED, $req->status);
        self::assertFalse($req->auto_approved);
        self::assertSame(42, $req->resolved_by);

        // Prochain check-in → 200 token.
        $token = (string) $this->enroll($identity)->assertOk()->json('token');
        $this->checkin($token)->assertOk()->assertJson(['workstation_id' => $ws->id]);
        self::assertSame(0, AgentEnrollmentRequest::query()->count());
    }

    // ── AC4 — rejet manuel : poste hors système, pas de ré-ouverture ─────

    #[Test]
    public function manual_reject_keeps_post_out_and_replay_does_not_reopen(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);
        $identity = ['mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'PC-LAB-01'];

        $this->enroll($identity)->assertStatus(403);
        $req = AgentEnrollmentRequest::query()->first();

        $this->service->rejectManually($req, 7);
        $req->refresh();
        self::assertSame(AgentEnrollmentRequest::STATUS_REJECTED, $req->status);

        // Re-POST : 403, aucun token, demande NON ré-ouverte.
        $this->enroll($identity)->assertStatus(403);
        self::assertSame(1, AgentEnrollmentRequest::query()->count());
        self::assertSame(
            AgentEnrollmentRequest::STATUS_REJECTED,
            AgentEnrollmentRequest::query()->first()->status,
        );
        self::assertFalse($ws->refresh()->isAgentEnrolled());
    }

    // ── AC6 — sans-oracle / pas de token/hash en log ────────────────────

    #[Test]
    public function gate2_logs_never_leak_a_token(): void
    {
        $handler = new TestHandler();
        Log::channel('agent')->getLogger()->pushHandler($handler);

        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);
        $this->enableCampaign();
        $identity = ['mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'PC-LAB-01'];

        $this->enroll($identity);
        $token = (string) $this->enroll($identity)->json('token');

        self::assertNotEmpty($handler->getRecords());
        foreach ($handler->getRecords() as $record) {
            $payload = (string) json_encode([
                'message' => $record['message'] ?? '',
                'context' => $record['context'] ?? [],
            ]);
            self::assertStringNotContainsString($token, $payload);
            self::assertStringNotContainsString(hash('sha256', $token), $payload);
        }
    }

    private function logged(TestHandler $handler, string $action): bool
    {
        foreach ($handler->getRecords() as $record) {
            if (($record['context']['action_type'] ?? '') === $action) {
                return true;
            }
        }

        return false;
    }
}
