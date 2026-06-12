<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\User;
use App\Models\Wallpaper;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature « forcer la synchro » — Story 24.7 (AC5, AC7).
 *
 * Routes RÉELLES `agent.v1.state` / `agent.v1.report` derrière la chaîne
 * complète (`auth.v1.secure-headers` + `throttle:60,1` + `agent.token`),
 * conventions `StateEndpointTest`/`ReportEndpointTest` (factories,
 * `TokenRotationService::issueFor()`).
 *
 * Matrice AC7 : demande pendante + `If-None-Match` concordant → 200 corps
 * complet, même ETag ; même requête SANS demande → 304 (non-régression) ;
 * demande pendante + contexte `?user=` → 200 forcé ; POST /report → demande
 * soldée (colonne null) ; report sans demande → no-op ; quarantaine → 403
 * middleware AVANT toute logique (demande non soldée).
 */
final class SyncRequestTest extends TestCase
{
    use RefreshDatabase;

    private const STATE_ROUTE = '/api/v1/agent/state';

    private const REPORT_ROUTE = '/api/v1/agent/report';

    private TokenRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
    }

    /** @return array{0: Workstation, 1: string} poste enrôlé + état non vide */
    private function enrolledWorkstation(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        Wallpaper::factory()->default()->create();

        return [$ws->refresh(), $token];
    }

    private function state(string $token, array $headers = [], string $query = ''): TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer ' . $token,
        ], $headers))->getJson(self::STATE_ROUTE . $query);
    }

    /** @param array<string, mixed> $payload */
    private function report(string $token, array $payload): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson(self::REPORT_ROUTE, $payload);
    }

    /** @return array<string, mixed> rapport valide minimal pour ce poste */
    private function reportPayload(Workstation $ws): array
    {
        return [
            'schema' => 'se5.desired-state/v1',
            'generated_at' => now()->toIso8601String(),
            'agent_version' => '1.0.0',
            'workstation' => ['hostname' => $ws->name, 'uuid' => $ws->uuid],
            'items' => [[
                'type' => 'wallpaper',
                'status' => 'compliant',
                'hash' => str_repeat('a', 64),
            ]],
        ];
    }

    private function requestSync(Workstation $ws): void
    {
        $ws->agent_sync_requested_at = now();
        $ws->save();
    }

    // ── AC5 / AC7 — bypass 304 pendant une demande ───────────────────────

    #[Test]
    public function pending_request_forces_200_full_body_on_matching_if_none_match(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $etag = $this->state($token)->assertOk()->headers->get('ETag');

        $this->requestSync($ws);

        // Même If-None-Match concordant : sans demande ce serait un 304.
        $response = $this->state($token, ['If-None-Match' => $etag]);

        $response->assertOk();
        self::assertNotSame('', $response->getContent(), 'corps complet re-servi');
        // MÊME ETag (enveloppe brute inchangée, piège 3).
        self::assertSame($etag, $response->headers->get('ETag'));
        // Zéro write au GET : la demande reste pendante (soldée au report
        // uniquement) — c'est ce qui garantit le bypass pour les fetchs
        // `?user=` du même cycle (décision n° 1).
        self::assertNotNull($ws->refresh()->agent_sync_requested_at);
    }

    #[Test]
    public function without_pending_request_matching_if_none_match_returns_304(): void
    {
        // Non-régression : le 304 nominal reste intact hors demande.
        [, $token] = $this->enrolledWorkstation();
        $etag = $this->state($token)->assertOk()->headers->get('ETag');

        $this->state($token, ['If-None-Match' => $etag])->assertStatus(304);
    }

    #[Test]
    public function pending_request_forces_200_in_user_context_too(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $user = User::factory()->create();
        $etag = $this->state($token, [], '?user=' . $user->login)->assertOk()->headers->get('ETag');

        $this->requestSync($ws);

        $response = $this->state($token, ['If-None-Match' => $etag], '?user=' . $user->login);

        $response->assertOk();
        self::assertSame($etag, $response->headers->get('ETag'));
        // Zéro write : le contexte user ne solde pas plus la demande que le
        // contexte machine.
        self::assertNotNull($ws->refresh()->agent_sync_requested_at);
    }

    #[Test]
    public function forced_get_does_not_consume_the_pending_request(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $this->requestSync($ws);

        // GET répétés pendant la demande : tous servis 200, la demande reste
        // pendante (zéro write au GET — seul POST /report la solde).
        $this->state($token, ['If-None-Match' => 'irrelevant'])->assertOk();
        $this->state($token, ['If-None-Match' => 'irrelevant'])->assertOk();

        self::assertNotNull($ws->refresh()->agent_sync_requested_at);
    }

    // ── AC5 / AC7 — solde au POST /report ────────────────────────────────

    #[Test]
    public function first_report_after_request_fulfills_it_and_nulls_the_column(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $this->requestSync($ws);
        self::assertNotNull($ws->refresh()->agent_sync_requested_at);

        $this->report($token, $this->reportPayload($ws))->assertOk();

        self::assertNull($ws->refresh()->agent_sync_requested_at, 'demande soldée au report');
    }

    #[Test]
    public function report_without_pending_request_is_a_noop(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        self::assertNull($ws->refresh()->agent_sync_requested_at);

        $this->report($token, $this->reportPayload($ws))->assertOk();

        self::assertNull($ws->refresh()->agent_sync_requested_at);
    }

    // ── AC5 / AC7 — quarantaine : 403 AVANT toute logique ────────────────

    #[Test]
    public function quarantined_state_call_returns_403_without_consuming_request(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $this->requestSync($ws);
        $this->service->quarantine($ws->refresh(), 'test');

        $this->state($token, ['If-None-Match' => 'irrelevant'])
            ->assertStatus(403)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_QUARANTINED]);

        // La demande reste pendante : un poste en quarantaine ne la solde jamais.
        self::assertNotNull($ws->refresh()->agent_sync_requested_at);
    }

    #[Test]
    public function quarantined_report_returns_403_and_does_not_fulfill(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $this->requestSync($ws);
        $this->service->quarantine($ws->refresh(), 'test');

        $this->report($token, $this->reportPayload($ws))->assertStatus(403);

        self::assertNotNull($ws->refresh()->agent_sync_requested_at);
    }
}
