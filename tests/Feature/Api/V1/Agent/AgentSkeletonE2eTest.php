<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\AgentResourceState;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\StateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature de la boucle agent squelette — Story 24.2 (AC7).
 *
 * Simule, CÔTÉ SERVEUR uniquement (jamais de test Windows ici), la séquence
 * exacte qu'exécute `agent/windows/SambaEduAgent.ps1` : GET /state (ETag) →
 * 304 If-None-Match → POST /report `items: []` (hostname COURT + uuid).
 * Les endpoints sont les routes RÉELLES (`agent.v1.state`/`agent.v1.report`)
 * derrière la chaîne complète `auth.v1.secure-headers` + `throttle:60,1` +
 * `agent.token` — la vue serveur de la boucle décrite dans
 * docs/agent/agent-skeleton.md.
 *
 * Invariants couverts : enveloppe v1 brute + ETag quoté, 304 sans corps,
 * rapport vide valide (counts à zéro, rien d'écrit, check-in stampé),
 * rotation D5 (X-Agent-New-Token sur GET 200/304 ET POST 200, nouveau token
 * immédiatement utilisable), defer 24.1 #8 (FQDN → warning identity_mismatch
 * mais 200 — la règle hostname COURT existe pour éviter ce spam), quarantaine
 * 403 = check-in léger possible. La matrice fine de chaque endpoint vit dans
 * `StateEndpointTest` / `ReportEndpointTest` — ici, l'ENCHAÎNEMENT.
 */
final class AgentSkeletonE2eTest extends TestCase
{
    use RefreshDatabase;

    private const STATE_ROUTE = '/api/v1/agent/state';
    private const REPORT_ROUTE = '/api/v1/agent/report';

    /** Version déclarée par l'agent squelette (agent/shared/ContractV1.ps1). */
    private const SKELETON_AGENT_VERSION = '1.0.0';

    private TokenRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
    }

    /** GET /state tel que l'agent l'appelle (machine-only : jamais de ?user=). */
    private function getState(string $token, array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer ' . $token,
        ], $headers))->getJson(self::STATE_ROUTE);
    }

    /** @param array<string, mixed> $payload */
    private function postReport(string $token, array $payload): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson(self::REPORT_ROUTE, $payload);
    }

    /** @return array{0: Workstation, 1: string} */
    private function enrolledWorkstation(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        return [$ws->refresh(), $token];
    }

    /**
     * Le rapport EXACT du squelette (Build-Report PowerShell) : `items: []`,
     * hostname COURT = workstations.name, uuid SMBIOS tel quel.
     *
     * @return array<string, mixed>
     */
    private function skeletonReport(Workstation $ws, ?string $hostname = null): array
    {
        return [
            'schema' => StateContract::SCHEMA,
            'generated_at' => now()->toIso8601String(),
            'agent_version' => self::SKELETON_AGENT_VERSION,
            'workstation' => [
                'hostname' => $hostname ?? $ws->name,
                'uuid' => $ws->uuid,
            ],
            'items' => [],
        ];
    }

    /** Décale l'échéance de rotation D5 du poste (pattern ReportEndpointTest). */
    private function makeRotationDue(Workstation $ws): void
    {
        $ws->refresh();
        $ws->agent_token_rotated_at = now()->subDays((int) config('agent.token_rotation_days') + 1);
        $ws->save();
    }

    /**
     * Capture les logs du channel `agent` (pattern 23.5/24.1 — mock étendu
     * debug/info/warning/error/critical).
     *
     * @return \ArrayObject<int, array{0:string,1:string,2:array<string,mixed>}>
     */
    private function captureAgentLogs(): \ArrayObject
    {
        $logs = new \ArrayObject();
        Log::shouldReceive('channel')->with('agent')->andReturnSelf();
        foreach (['debug', 'info', 'warning', 'error', 'critical'] as $level) {
            Log::shouldReceive($level)->andReturnUsing(
                function (string $message, array $context = []) use ($logs, $level): void {
                    $logs->append([$level, $message, $context]);
                },
            );
        }

        return $logs;
    }

    /**
     * @param  \ArrayObject<int, array{0:string,1:string,2:array<string,mixed>}>  $logs
     * @return list<array{0:string,1:string,2:array<string,mixed>}>
     */
    private function logsOfType(\ArrayObject $logs, string $actionType): array
    {
        return array_values(array_filter(
            $logs->getArrayCopy(),
            fn (array $log): bool => ($log[2]['action_type'] ?? null) === $actionType,
        ));
    }

    // ── Étape 1 de la boucle — GET /state : 200 + ETag ───────────────────

    #[Test]
    public function first_cycle_gets_the_raw_v1_envelope_with_a_quoted_etag(): void
    {
        [, $token] = $this->enrolledWorkstation();

        $response = $this->getState($token)->assertOk();

        // Enveloppe BRUTE du contrat (jamais le wrapper SE5) — c'est ce que
        // Parse-State (agent) valide : schema + 3 portées en listes.
        $state = $response->json();
        self::assertSame(
            ['schema', 'generated_at', 'ttl_seconds', ...StateContract::scopes()],
            array_keys($state),
        );
        self::assertSame(StateContract::SCHEMA, $state['schema']);
        foreach (StateContract::scopes() as $scope) {
            self::assertIsList($state[$scope]);
        }

        // ETag quoté RFC 7232 : l'agent le stocke VERBATIM (cache\etag.txt).
        $etag = $response->headers->get('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', (string) $etag);
    }

    // ── Étape 2 — re-call avec If-None-Match : 304 ───────────────────────

    #[Test]
    public function second_cycle_with_cached_etag_gets_304_without_body(): void
    {
        [, $token] = $this->enrolledWorkstation();
        $etag = $this->getState($token)->assertOk()->headers->get('ETag');

        // L'agent renvoie l'ETag verbatim (guillemets inclus) — cycle suivant.
        $response = $this->getState($token, ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        self::assertSame('', $response->getContent(), '304 sans corps : le cache local reste la source');
        self::assertSame($etag, $response->headers->get('ETag'));
    }

    // ── Étape 3 — POST /report items:[] : 200 {success: true} ────────────

    #[Test]
    public function skeleton_empty_report_returns_200_with_zero_counts_and_writes_no_state(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        self::assertNull($ws->agent_last_checkin_at);

        $response = $this->postReport($token, $this->skeletonReport($ws));

        $response->assertOk()->assertJson([
            'success' => true,
            'counts' => ['compliant' => 0, 'drift' => 0, 'drifted_allowed' => 0, 'error' => 0],
        ]);
        // Aucun handler = aucune ligne d'état — mais la boucle est FERMÉE :
        // le check-in (signal de vie AC8) est stampé par le middleware.
        self::assertSame(0, AgentResourceState::query()->count());
        self::assertNotNull($ws->refresh()->agent_last_checkin_at);
    }

    #[Test]
    public function full_skeleton_cycle_state_then_304_then_empty_report_closes_the_loop(): void
    {
        // LA séquence complète d'un cycle agent (boot puis timer) vue serveur :
        // GET 200 → cache ETag → GET 304 → POST report vide → 200.
        [$ws, $token] = $this->enrolledWorkstation();

        $etag = $this->getState($token)->assertOk()->headers->get('ETag');
        $this->getState($token, ['If-None-Match' => $etag])->assertStatus(304);
        $this->postReport($token, $this->skeletonReport($ws))
            ->assertOk()
            ->assertJsonPath('success', true);

        self::assertNotNull($ws->refresh()->agent_last_checkin_at);
    }

    // ── Rotation D5 — X-Agent-New-Token sur GET state ET POST report ─────

    #[Test]
    public function due_rotation_surfaces_on_get_state_and_the_new_token_runs_the_next_cycle(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $this->makeRotationDue($ws);

        $response = $this->getState($token)->assertOk();

        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        // L'agent écrit le nouveau token sur disque et l'utilise dès le cycle
        // suivant (Update-TokenIfRotated) : state + report passent avec lui.
        $this->getState($new)->assertOk();
        $this->postReport($new, $this->skeletonReport($ws))->assertOk();
    }

    #[Test]
    public function due_rotation_survives_a_304_check_in(): void
    {
        // Invariant D5 vu de la boucle : le cycle nominal du squelette est
        // souvent un 304 — la rotation doit y survivre, sinon un poste à
        // l'état stable ne rotaterait jamais.
        [$ws, $token] = $this->enrolledWorkstation();
        $etag = $this->getState($token)->assertOk()->headers->get('ETag');
        $this->makeRotationDue($ws);

        $response = $this->getState($token, ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        $this->getState($new, ['If-None-Match' => $etag])->assertStatus(304);
    }

    #[Test]
    public function due_rotation_surfaces_on_the_empty_report_response(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $this->makeRotationDue($ws);

        $response = $this->postReport($token, $this->skeletonReport($ws));

        $response->assertOk();
        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        $this->postReport($new, $this->skeletonReport($ws))->assertOk();
    }

    // ── Defer 24.1 #8 — hostname COURT obligatoire ────────────────────────

    #[Test]
    public function short_hostname_report_emits_no_identity_mismatch_warning(): void
    {
        // La raison d'être de la règle : le squelette envoie $env:COMPUTERNAME
        // (nom court = workstations.name) → zéro bruit sur le channel agent.
        [$ws, $token] = $this->enrolledWorkstation();

        $logs = $this->captureAgentLogs();
        $this->postReport($token, $this->skeletonReport($ws))->assertOk();

        self::assertCount(0, $this->logsOfType($logs, 'agent.report.identity_mismatch'));
    }

    #[Test]
    public function fqdn_hostname_report_is_accepted_but_logs_identity_mismatch_warning(): void
    {
        // Comportement 24.1 vérifié côté boucle : un agent qui enverrait le
        // FQDN serait accepté (identité = token) mais spammerait ce warning à
        // CHAQUE rapport — d'où le contrat hostname court (agent-skeleton.md).
        [$ws, $token] = $this->enrolledWorkstation();
        $fqdn = strtolower($ws->name) . '.sambaedu.local';

        $logs = $this->captureAgentLogs();
        $this->postReport($token, $this->skeletonReport($ws, $fqdn))->assertOk();

        $mismatch = $this->logsOfType($logs, 'agent.report.identity_mismatch');
        self::assertCount(1, $mismatch);
        self::assertSame('warning', $mismatch[0][0]);
        self::assertSame($ws->id, $mismatch[0][2]['workstation_id']);
        self::assertSame($fqdn, $mismatch[0][2]['declared_hostname']);
    }

    // ── Quarantaine — check-ins légers (AC4 vu du serveur) ───────────────

    #[Test]
    public function quarantined_workstation_gets_403_on_state_but_its_light_checkin_is_recorded(): void
    {
        // Vue serveur du mode « check-ins légers » : le GET /state d'un poste
        // en quarantaine répond 403 AGENT_QUARANTINED mais STAMPE le check-in
        // (FR15 : le poste reste visible — c'est ce qui permet à l'admin de
        // lever la quarantaine en confiance).
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $this->service->quarantine($ws->refresh(), 'test');
        $before = $ws->refresh()->agent_last_checkin_at;

        $this->getState($token)
            ->assertStatus(403)
            ->assertJson([
                'error' => 'forbidden',
                'code' => AuthenticateAgentToken::CODE_QUARANTINED,
            ]);

        self::assertNotEquals($before, $ws->refresh()->agent_last_checkin_at);
    }

    #[Test]
    public function quarantined_workstation_report_is_rejected_with_403_and_writes_nothing(): void
    {
        // L'agent CESSE de rapporter en quarantaine ; si un agent bogué
        // postait quand même, le serveur refuse et n'écrit rien.
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $this->service->quarantine($ws->refresh(), 'test');

        $this->postReport($token, $this->skeletonReport($ws))
            ->assertStatus(403)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_QUARANTINED]);

        self::assertSame(0, AgentResourceState::query()->count());
    }

    // ── Arrêt sur 401 — jamais de re-enrôlement automatique ──────────────

    #[Test]
    public function revoked_token_gets_401_invalid_the_agent_stop_condition(): void
    {
        // Le 401 que l'agent traite comme irrécupérable (arrêt + log local,
        // re-enrôlement MANUEL) : format du middleware 23.2, intouché.
        Workstation::factory()->create();

        $this->getState(str_repeat('f', 64))
            ->assertStatus(401)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_TOKEN_INVALID]);
    }
}
