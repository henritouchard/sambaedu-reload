<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature `POST /api/v1/agent/shutdown` (détection d'extinction).
 *
 * Route réelle derrière la chaîne complète (`auth.v1.secure-headers` +
 * `throttle:60,1` + `agent.token`), conventions `SyncRequestTest`. Vérifie :
 * signal → `agent_reported_offline_at` posé + présence 'reported_off'
 * immédiate (sans attendre le seuil de silence) ; check-in ultérieur plus
 * récent → le signal devient inopérant (présence 'online') ; sans token →
 * 401 et timestamp intact.
 */
final class ShutdownEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const SHUTDOWN_ROUTE = '/api/v1/agent/shutdown';

    private TokenRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
    }

    /** @return array{0: Workstation, 1: string} poste enrôlé + son token */
    private function enrolledWorkstation(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        return [$ws->refresh(), $token];
    }

    private function shutdown(string $token): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson(self::SHUTDOWN_ROUTE);
    }

    #[Test]
    public function shutdown_signal_marks_workstation_reported_off(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $this->assertNull($ws->agent_reported_offline_at);

        $this->shutdown($token)->assertNoContent();

        $ws->refresh();
        $this->assertNotNull($ws->agent_reported_offline_at);
        // Le middleware a posé le check-in DANS la même requête : le signal,
        // postérieur ou simultané, doit primer (gte) → éteint immédiat.
        $this->assertSame('reported_off', $ws->agentPresence());
    }

    #[Test]
    public function later_checkin_supersedes_shutdown_signal(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();

        $this->travelTo(now()->subMinutes(30), function () use ($token) {
            $this->shutdown($token)->assertNoContent();
        });

        // Boot suivant : n'importe quelle requête authentifiée repose le
        // check-in via le middleware — ici un second POST /shutdown ferait
        // aussi l'affaire, mais on simule le cas nominal (check-in nu).
        $ws->refresh();
        $ws->agent_last_checkin_at = now();
        $ws->save();

        $this->assertSame('online', $ws->refresh()->agentPresence());
    }

    #[Test]
    public function shutdown_requires_agent_token(): void
    {
        [$ws] = $this->enrolledWorkstation();

        $this->postJson(self::SHUTDOWN_ROUTE)->assertUnauthorized();
        $this->shutdown(str_repeat('f', 64))->assertUnauthorized();

        $this->assertNull($ws->refresh()->agent_reported_offline_at);
    }

    #[Test]
    public function silent_workstation_without_signal_reports_silent(): void
    {
        [$ws] = $this->enrolledWorkstation();

        // Coupure brutale : dernier check-in au-delà de 2 × ttl, aucun signal.
        $ws->agent_last_checkin_at = now()->subSeconds(3 * (int) config('agent.ttl_seconds', 3600));
        $ws->save();

        $this->assertSame('silent', $ws->refresh()->agentPresence());
    }
}
