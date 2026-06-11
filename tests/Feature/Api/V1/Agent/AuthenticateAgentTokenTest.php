<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature `AuthenticateAgentToken` — Story 23.2 (AC1-AC6).
 *
 * Aucun endpoint métier du canal n'existe encore (23.5/24.1) : le middleware
 * est exercé via une route éphémère déclarée dans le test, derrière l'alias
 * `agent.token` enregistré par `AgentServiceProvider`. La route renvoie l'id
 * du workstation injecté (`agent.workstation`) — vérifie la résolution
 * d'identité par token (AC1).
 */
final class AuthenticateAgentTokenTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/_test/agent/echo';

    private TokenRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);

        // Si un cache de routes existe (bootstrap/cache/routes-v7.php sur la
        // VM), le matcher compilé fait gagner le catch-all legacy `{path}`
        // sur toute route déclarée à l'exécution → 404. On repart d'une
        // collection vierge : seul ce test-route existe, avec ou sans cache.
        $this->app['router']->setRoutes(new RouteCollection());

        Route::middleware('agent.token')->get(self::ROUTE, function (Request $request) {
            return response()->json([
                'workstation_id' => $request->attributes->get('agent.workstation')->id,
            ]);
        });
    }

    private function checkin(string $token, array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer ' . $token,
        ], $headers))->getJson(self::ROUTE);
    }

    // ── AC2 — 401 sans oracle ────────────────────────────────────────────

    #[Test]
    public function missing_bearer_returns_401_with_se5_error_format(): void
    {
        $response = $this->getJson(self::ROUTE);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'unauthorized',
                'code' => AuthenticateAgentToken::CODE_TOKEN_MISSING,
            ])
            ->assertJsonStructure(['error', 'message', 'code']);
    }

    #[Test]
    public function unknown_token_returns_401(): void
    {
        Workstation::factory()->create();

        $this->checkin(str_repeat('f', 64))
            ->assertStatus(401)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_TOKEN_INVALID]);
    }

    #[Test]
    public function revoked_token_returns_401_indistinguishable_from_unknown(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $this->service->revokeFor($ws->refresh(), 'test');

        $revoked = $this->checkin($token);
        $unknown = $this->checkin(str_repeat('f', 64));

        $revoked->assertStatus(401);
        self::assertSame($unknown->json(), $revoked->json());
    }

    #[Test]
    public function deleted_workstation_token_returns_401(): void
    {
        // AC6 — révocation par construction : les colonnes vivent sur la ligne.
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $ws->delete();

        $this->checkin($token)->assertStatus(401);
    }

    // ── AC1 — résolution d'identité + check-in ──────────────────────────

    #[Test]
    public function valid_token_resolves_its_own_workstation_and_stamps_checkin(): void
    {
        $other = Workstation::factory()->create();
        $ws = Workstation::factory()->create();
        $this->service->issueFor($other);
        $token = $this->service->issueFor($ws);

        self::assertNull($ws->refresh()->agent_last_checkin_at);

        $this->checkin($token)
            ->assertOk()
            ->assertJson(['workstation_id' => $ws->id]);

        self::assertNotNull($ws->refresh()->agent_last_checkin_at);
    }

    // ── AC3 — quarantaine ────────────────────────────────────────────────

    #[Test]
    public function quarantined_workstation_gets_403_but_checkin_is_still_stamped(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $this->service->quarantine($ws->refresh(), 'test');

        $this->checkin($token)
            ->assertStatus(403)
            ->assertJson([
                'error' => 'forbidden',
                'code' => AuthenticateAgentToken::CODE_QUARANTINED,
            ]);

        // FR15 — le poste poursuit des check-ins légers, il reste visible.
        self::assertNotNull($ws->refresh()->agent_last_checkin_at);
    }

    // ── AC4 — rotation à échéance + fenêtre de grâce ─────────────────────

    private function makeRotationDue(Workstation $ws): void
    {
        $ws->agent_token_rotated_at = now()->subDays((int) config('agent.token_rotation_days') + 1);
        $ws->save();
    }

    #[Test]
    public function due_rotation_returns_new_token_header_and_keeps_old_token_valid(): void
    {
        $ws = Workstation::factory()->create();
        $old = $this->service->issueFor($ws);
        $this->makeRotationDue($ws->refresh());

        $response = $this->checkin($old)->assertOk();
        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        // Grâce ouverte : l'ancien hash a glissé en previous.
        self::assertSame(hash('sha256', $old), $ws->refresh()->agent_previous_token_hash);
        // Le nouveau token est valide.
        $this->checkin($new)->assertOk()->assertJson(['workstation_id' => $ws->id]);
    }

    #[Test]
    public function lost_rotation_response_reissues_token_and_old_one_stays_valid(): void
    {
        $ws = Workstation::factory()->create();
        $old = $this->service->issueFor($ws);
        $this->makeRotationDue($ws->refresh());

        // Première rotation — réponse « perdue » côté poste.
        $first = $this->checkin($old)->assertOk()
            ->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);

        // Le poste re-check-in avec l'ancien token : ré-émission d'un
        // NOUVEAU token, l'ancien reste l'unique grâce — jamais de lock-out.
        $response = $this->checkin($old)->assertOk();
        $second = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);

        self::assertNotNull($second);
        self::assertNotSame($first, $second);
        self::assertSame(hash('sha256', $old), $ws->refresh()->agent_previous_token_hash);
        $this->checkin($second)->assertOk();
    }

    #[Test]
    public function first_use_of_new_token_closes_grace_window_then_old_token_gets_401(): void
    {
        $ws = Workstation::factory()->create();
        $old = $this->service->issueFor($ws);
        $this->makeRotationDue($ws->refresh());

        $new = $this->checkin($old)->assertOk()
            ->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);

        // Premier usage du nouveau : confirmation, previous effacé.
        $this->checkin($new)->assertOk();
        self::assertNull($ws->refresh()->agent_previous_token_hash);

        // L'ancien token est mort.
        $this->checkin($old)->assertStatus(401);
    }

    #[Test]
    public function six_months_old_token_authenticates_and_rotates_instead_of_dying(): void
    {
        // AC4 — pas d'expiration calendaire sèche : le poste vivant après
        // les vacances se rotate, ne meurt pas.
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $ws->refresh();
        $ws->agent_token_rotated_at = now()->subMonths(6);
        $ws->save();

        $response = $this->checkin($token)->assertOk();

        self::assertNotNull($response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN));
    }

    #[Test]
    public function fresh_token_gets_no_rotation_header(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        $response = $this->checkin($token)->assertOk();

        self::assertNull($response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN));
    }

    #[Test]
    public function future_rotated_at_triggers_immediate_rotation(): void
    {
        // Review 23.2 — snapshot DB restauré / horloge corrigée : un
        // `rotated_at` futur figerait le token pour toujours. État
        // incohérent → rotation immédiate, qui repose une date saine.
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $ws->refresh();
        $ws->agent_token_rotated_at = now()->addYear();
        $ws->save();

        $response = $this->checkin($token)->assertOk();

        self::assertNotNull($response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN));
        self::assertTrue($ws->refresh()->agent_token_rotated_at->lessThanOrEqualTo(now()));
    }

    #[Test]
    public function zero_rotation_days_misconfig_does_not_rotate_every_checkin(): void
    {
        // Review 23.2 — plancher à 1 jour : AGENT_TOKEN_ROTATION_DAYS=0
        // (fat-finger) ne doit pas déclencher une rotation par check-in.
        config(['agent.token_rotation_days' => 0]);
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        $response = $this->checkin($token)->assertOk();

        self::assertNull($response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN));
    }

    // ── AC5 — anti-clonage ───────────────────────────────────────────────

    #[Test]
    public function diverging_mac_quarantines_workstation_and_returns_403(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);
        $token = $this->service->issueFor($ws);

        $this->checkin($token, [AuthenticateAgentToken::HEADER_MAC => '11:22:33:44:55:66'])
            ->assertStatus(403)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_QUARANTINED]);

        self::assertTrue($ws->refresh()->isAgentQuarantined());
    }

    #[Test]
    public function matching_mac_in_uppercase_is_canonicalized_and_accepted(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);
        $token = $this->service->issueFor($ws);

        $this->checkin($token, [AuthenticateAgentToken::HEADER_MAC => 'AA:BB:CC:DD:EE:FF'])
            ->assertOk();

        self::assertFalse($ws->refresh()->isAgentQuarantined());
    }

    #[Test]
    public function mac_with_dash_separators_is_canonicalized_and_accepted(): void
    {
        // Review 23.2 — l'agent Windows émettra naturellement le format
        // ipconfig `AA-BB-CC-DD-EE-FF` : un mismatch de séparateur ne doit
        // pas quarantainer un poste légitime (comparaison via
        // MacAddressNormalizer::normalize, forme canonique commune).
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);
        $token = $this->service->issueFor($ws);

        $this->checkin($token, [AuthenticateAgentToken::HEADER_MAC => 'AA-BB-CC-DD-EE-FF'])
            ->assertOk();

        self::assertFalse($ws->refresh()->isAgentQuarantined());
    }

    #[Test]
    public function unrecognized_mac_format_skips_detection(): void
    {
        // Review 23.2 — format non parseable ≠ divergence : pas de détection
        // (iso header absent), pas de fausse quarantaine.
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);
        $token = $this->service->issueFor($ws);

        $this->checkin($token, [AuthenticateAgentToken::HEADER_MAC => 'not-a-mac'])
            ->assertOk();

        self::assertFalse($ws->refresh()->isAgentQuarantined());
    }

    #[Test]
    public function diverging_hostname_alone_warns_without_quarantine(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);
        $token = $this->service->issueFor($ws);

        $this->checkin($token, [
            AuthenticateAgentToken::HEADER_MAC => 'aa:bb:cc:dd:ee:ff',
            AuthenticateAgentToken::HEADER_HOSTNAME => 'PC-RENOMME-AILLEURS',
        ])->assertOk();

        self::assertFalse($ws->refresh()->isAgentQuarantined());
    }

    #[Test]
    public function absent_identity_headers_skip_clone_detection(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);
        $token = $this->service->issueFor($ws);

        $this->checkin($token)->assertOk();

        self::assertFalse($ws->refresh()->isAgentQuarantined());
    }

    // ── AC6 — révocation ────────────────────────────────────────────────

    #[Test]
    public function revocation_makes_next_call_return_401(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $this->checkin($token)->assertOk();

        $this->service->revokeFor($ws->refresh(), 'revoked_from_ui_by_admin');

        $this->checkin($token)->assertStatus(401);
    }
}
