<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Controllers\Api\V1\Agent\EnrollController;
use App\Models\Workstation;
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
 * Tests Feature `POST /api/v1/agent/enrollment` — Story 23.3 (AC3, AC4).
 *
 * Piège route cache (précédent `AuthenticateAgentTokenTest` 23.2) : avec
 * `bootstrap/cache/routes-v7.php` sur la VM, le matcher compilé fait gagner
 * le catch-all legacy `{path}` sur toute route déclarée à l'exécution — et
 * un cache STALE ne contiendrait pas la route fraîchement ajoutée à api.php.
 * On repart donc d'une collection vierge et on re-déclare la route
 * d'enrôlement À L'IDENTIQUE de `routes/api.php` (URI, middlewares, nom) +
 * une route écho éphémère derrière `agent.token` pour vérifier que le token
 * né de l'échange est immédiatement utilisable.
 */
final class EnrollmentEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENROLLMENT_ROUTE = '/api/v1/agent/enrollment';
    private const ECHO_ROUTE = '/_test/agent/echo';

    private EnrollmentService $service;

    private TokenRotationService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = app(TokenRotationService::class);
        $this->service = app(EnrollmentService::class);

        $this->app['router']->setRoutes(new RouteCollection());

        // Re-déclaration iso routes/api.php (Story 23.3) — toute divergence
        // de middleware ici doit être reportée là-bas et inversement.
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

    // ── AC3 — l'échange ticket → token ──────────────────────────────────

    #[Test]
    public function valid_ticket_births_a_usable_token_consumes_ticket_and_sets_no_store(): void
    {
        $ws = Workstation::factory()->create();
        $ticket = $this->service->openTicket($ws);

        $response = $this->enroll(['ticket' => $ticket]);

        $response->assertOk()->assertJson(['success' => true]);
        $token = (string) $response->json('token');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        // Hygiène HTTP : la réponse porte le token en clair, une seule fois.
        self::assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );

        // Ticket consommé, token haché en DB.
        $ws->refresh();
        self::assertNull($ws->agent_enroll_ticket_hash);
        self::assertNull($ws->agent_enroll_ticket_expires_at);
        self::assertSame(hash('sha256', $token), $ws->agent_token_hash);

        // Le token est immédiatement utilisable derrière `agent.token`.
        $this->checkin($token)
            ->assertOk()
            ->assertJson(['workstation_id' => $ws->id]);
    }

    #[Test]
    public function replayed_ticket_returns_403_without_oracle(): void
    {
        $ws = Workstation::factory()->create();
        $ticket = $this->service->openTicket($ws);
        $this->enroll(['ticket' => $ticket])->assertOk();

        $this->enroll(['ticket' => $ticket])
            ->assertStatus(403)
            ->assertJson([
                'error' => 'forbidden',
                'code' => EnrollController::CODE_NOT_ALLOWED,
            ])
            ->assertJsonStructure(['error', 'message', 'code']);
    }

    #[Test]
    public function expired_ticket_returns_403(): void
    {
        $ws = Workstation::factory()->create();
        $ticket = $this->service->openTicket($ws);
        $ws->refresh();
        $ws->agent_enroll_ticket_expires_at = now()->subMinute();
        $ws->save();

        $this->enroll(['ticket' => $ticket])
            ->assertStatus(403)
            ->assertJson(['code' => EnrollController::CODE_NOT_ALLOWED]);

        self::assertNull($ws->refresh()->agent_token_hash);
    }

    // ── AC4 — conflit 409, rien d'écrasé ─────────────────────────────────

    #[Test]
    public function missing_ticket_on_enrolled_workstation_returns_409_and_token_stays_intact(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->tokens->issueFor($ws);

        $this->enroll(['uuid' => $ws->uuid])
            ->assertStatus(409)
            ->assertJson([
                'error' => 'conflict',
                'code' => EnrollController::CODE_CONFLICT,
            ]);

        // Rien n'est écrasé : le token courant reste valide.
        self::assertSame(hash('sha256', $token), $ws->refresh()->agent_token_hash);
        $this->checkin($token)->assertOk();
    }

    #[Test]
    public function missing_ticket_on_unknown_workstation_returns_403(): void
    {
        Workstation::factory()->create();

        $this->enroll(['uuid' => '99999999-9999-9999-9999-999999999999'])
            ->assertStatus(403)
            ->assertJson(['code' => EnrollController::CODE_NOT_ALLOWED]);
    }

    // ── AC2 + AC3 — cycle réinstallation complet ─────────────────────────

    #[Test]
    public function full_reinstall_cycle_revokes_old_token_then_births_a_new_one(): void
    {
        // Poste enrôlé (vie courante).
        $ws = Workstation::factory()->create();
        $oldToken = $this->tokens->issueFor($ws);
        $this->checkin($oldToken)->assertOk();

        // Réinstall : la génération de l'unattend ouvre un ticket → l'ancien
        // token est révoqué IMMÉDIATEMENT (AC2), avant même le premier logon.
        $ticket = $this->service->openTicket($ws->refresh());
        $this->checkin($oldToken)->assertStatus(401);

        // Premier logon : échange ticket → nouveau token utilisable.
        $response = $this->enroll(['ticket' => $ticket])->assertOk();
        $newToken = (string) $response->json('token');
        self::assertNotSame($oldToken, $newToken);
        $this->checkin($newToken)->assertOk()->assertJson(['workstation_id' => $ws->id]);
    }

    // ── AC3 — log de cohérence sans blocage ──────────────────────────────

    #[Test]
    public function diverging_identity_is_logged_as_mismatch_but_does_not_block(): void
    {
        $handler = new TestHandler();
        Log::channel('agent')->getLogger()->pushHandler($handler);

        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);
        $ticket = $this->service->openTicket($ws);

        $this->enroll([
            'ticket' => $ticket,
            'uuid' => $ws->uuid,
            'mac' => '11-22-33-44-55-66',
            'hostname' => 'autre-nom',
        ])->assertOk();

        $mismatchLogged = false;
        foreach ($handler->getRecords() as $record) {
            if (($record['context']['action_type'] ?? '') === 'agent.enroll.identity_mismatch') {
                $mismatchLogged = true;
                self::assertEqualsCanonicalizing(['mac', 'hostname'], $record['context']['fields']);
            }
        }
        self::assertTrue($mismatchLogged, 'agent.enroll.identity_mismatch attendu dans le channel agent.');
    }

    // ── AC7 — jamais de ticket/token en clair dans les logs ──────────────

    #[Test]
    public function ticket_and_token_clear_values_never_reach_the_logs(): void
    {
        $handler = new TestHandler();
        Log::channel('agent')->getLogger()->pushHandler($handler);

        $ws = Workstation::factory()->create();
        $ticket = $this->service->openTicket($ws);
        $token = (string) $this->enroll(['ticket' => $ticket])->json('token');
        // Provoque aussi un rejet loggé (replay).
        $this->enroll(['ticket' => $ticket, 'uuid' => $ws->uuid]);

        self::assertNotEmpty($handler->getRecords());
        foreach ($handler->getRecords() as $record) {
            $payload = (string) json_encode([
                'message' => $record['message'] ?? '',
                'context' => $record['context'] ?? [],
            ]);
            self::assertStringNotContainsString($ticket, $payload);
            self::assertStringNotContainsString($token, $payload);
            // Les hash non plus (convention 23.2).
            self::assertStringNotContainsString(hash('sha256', $ticket), $payload);
            self::assertStringNotContainsString(hash('sha256', $token), $payload);
        }
    }
}
