<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\AgentResourceStatus;
use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\AgentReportEvent;
use App\Models\AgentReportHistory;
use App\Models\AgentResourceState;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature `POST /api/v1/agent/report` — Story 24.1 (AC1-AC7).
 *
 * Route RÉELLE (`agent.v1.report`) derrière la chaîne complète
 * `auth.v1.secure-headers` + `throttle:60,1` + `agent.token` (conventions
 * `StateEndpointTest`). Le golden `report.v1.json` (FIGÉ, artefact normatif
 * 23.1) est posté TEL QUEL — il doit passer. La matrice fine des événements
 * vit dans `ReportIngestServiceTest` (unit) ; ici, le contrat HTTP : 200
 * wrapper SE5, 422 sans écriture, 401/403 middleware intouchés, invariant
 * D5 (X-Agent-New-Token sur le 200 du POST), jamais 500 sur rapport forgé.
 */
final class ReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT_ROUTE = '/api/v1/agent/report';

    private TokenRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
    }

    /** @param array<string, mixed> $payload */
    private function report(string $token, array $payload): TestResponse
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

    /** @return array<string, mixed> le payload du golden file, décodé. */
    private function goldenReport(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Agent/report.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Rapport valide minimal pour ce poste (identité déclarée alignée —
     * pas de bruit identity_mismatch dans les tests de logs).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function reportPayload(Workstation $ws, array $items): array
    {
        return [
            'schema' => 'se5.desired-state/v1',
            'generated_at' => now()->toIso8601String(),
            'agent_version' => '1.0.0',
            'workstation' => ['hostname' => $ws->name, 'uuid' => $ws->uuid],
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function item(string $type, string $status, ?string $detail = null): array
    {
        $item = [
            'type' => $type,
            'status' => $status,
            'hash' => str_repeat('a', 63) . substr(md5($type . $status), 0, 1),
        ];
        if ($detail !== null) {
            $item['detail'] = $detail;
        }

        return $item;
    }

    /**
     * Capture les logs du channel `agent` (pattern `StateEndpointTest`,
     * mock étendu error/critical — correctif P2 23.5).
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

    private function assertNothingWritten(): void
    {
        self::assertSame(0, AgentResourceState::query()->count());
        self::assertSame(0, AgentReportEvent::query()->count());
        self::assertSame(0, AgentReportHistory::query()->count());
    }

    // ── AC1 — golden payload + upsert borné ──────────────────────────────

    #[Test]
    public function golden_report_payload_is_accepted_verbatim(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();

        $response = $this->report($token, $this->goldenReport());

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'counts' => [
                    'compliant' => 1,
                    'drift' => 1,
                    'error' => 1,
                ],
            ]);

        // 3 items golden = 3 lignes d'état, par (poste, type).
        // Story 27.8 : l'item `drifted_allowed` a été retiré du golden.
        self::assertSame(3, AgentResourceState::query()->where('workstation_id', $ws->id)->count());
        $printers = AgentResourceState::query()
            ->where('workstation_id', $ws->id)->where('type', 'printers')->sole();
        self::assertSame(AgentResourceStatus::Error, $printers->status);
        self::assertSame('service Spooler indisponible (RPC 0x6ba)', $printers->detail);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $printers->hash);
    }

    #[Test]
    public function successive_reports_keep_at_most_one_state_row_per_type_and_refresh_reported_at(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $payload = $this->reportPayload($ws, [$this->item('wallpaper', 'compliant')]);

        $this->report($token, $payload)->assertOk();
        $first = AgentResourceState::query()->sole();
        $firstReportedAt = $first->reported_at;

        $this->travel(2)->hours();
        $this->report($token, $payload)->assertOk();

        // Volume borné (UNIQUE) : toujours 1 ligne — et fraîcheur rafraîchie
        // même sur rapport IDENTIQUE (décision n° 4).
        self::assertSame(1, AgentResourceState::query()->count());
        self::assertTrue($first->refresh()->reported_at->gt($firstReportedAt));
    }

    #[Test]
    public function empty_items_report_is_valid_and_writes_nothing(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();

        $this->report($token, $this->reportPayload($ws, []))->assertOk();

        $this->assertNothingWritten();
        self::assertNotNull($ws->refresh()->agent_last_checkin_at);
    }

    #[Test]
    public function checkin_is_stamped_by_the_middleware_not_the_story_code(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        self::assertNull($ws->agent_last_checkin_at);

        $this->report($token, $this->reportPayload($ws, [$this->item('wallpaper', 'compliant')]))->assertOk();

        self::assertNotNull($ws->refresh()->agent_last_checkin_at);
    }

    // ── AC2 — journal des changements (vue HTTP — matrice fine en unit) ──

    #[Test]
    public function first_compliant_report_creates_no_event_but_first_drift_does(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();

        $this->report($token, $this->reportPayload($ws, [
            $this->item('wallpaper', 'compliant'),
            $this->item('overlay', 'drift'),
        ]))->assertOk();

        self::assertSame(1, AgentReportEvent::query()->count());
        $event = AgentReportEvent::query()->sole();
        self::assertSame('overlay', $event->type);
        self::assertNull($event->previous_status);
        self::assertSame(AgentResourceStatus::Drift, $event->status);
    }

    #[Test]
    public function identical_report_creates_no_event(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $payload = $this->reportPayload($ws, [$this->item('overlay', 'drift')]);

        $this->report($token, $payload)->assertOk();
        self::assertSame(1, AgentReportEvent::query()->count());

        $this->report($token, $payload)->assertOk();

        self::assertSame(1, AgentReportEvent::query()->count(), 'rapport identique = AUCUN événement');
    }

    // ── AC3 — flag history ────────────────────────────────────────────────

    #[Test]
    public function history_is_not_written_when_flag_is_off_by_default(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();

        $this->report($token, $this->goldenReport())->assertOk();

        self::assertSame(0, AgentReportHistory::query()->count());
    }

    #[Test]
    public function history_stores_the_raw_payload_including_unknown_fields_when_flag_is_on(): void
    {
        config(['agent.report_history' => true]);
        [$ws, $token] = $this->enrolledWorkstation();
        $payload = $this->reportPayload($ws, [$this->item('printers', 'error', 'spooler KO')]);
        // Champ inconnu §9 (forward-compat) : toléré par la validation ET
        // conservé dans l'historique de debug (review 24.1 #2 — brut, pas
        // validated() qui le stripperait).
        $payload['future_field'] = 'v1.1-preview';

        $this->report($token, $payload)->assertOk();

        $history = AgentReportHistory::query()->sole();
        self::assertSame($ws->id, $history->workstation_id);
        self::assertSame('se5.desired-state/v1', $history->payload['schema']);
        self::assertSame('printers', $history->payload['items'][0]['type']);
        self::assertSame('spooler KO', $history->payload['items'][0]['detail']);
        self::assertSame('v1.1-preview', $history->payload['future_field']);
    }

    // ── AC4 — 422 sans écriture, jamais 500 ──────────────────────────────

    /** @return array<string, array{0: callable(array<string,mixed>): array<string,mixed>}> */
    public static function malformedReportProvider(): array
    {
        return [
            'schema inconnu' => [fn (array $p): array => array_merge($p, ['schema' => 'se5.desired-state/v2'])],
            'items absent' => [fn (array $p): array => array_diff_key($p, ['items' => true])],
            'items mal typé' => [fn (array $p): array => array_merge($p, ['items' => 'not-a-list'])],
            'status hors enum' => [function (array $p): array {
                $p['items'][0]['status'] = 'broken';

                return $p;
            }],
            'hash non hex-64' => [function (array $p): array {
                $p['items'][0]['hash'] = 'xyz';

                return $p;
            }],
            'detail absent sur error' => [function (array $p): array {
                unset($p['items'][3]['detail']);

                return $p;
            }],
            'detail vide sur error' => [function (array $p): array {
                $p['items'][3]['detail'] = '';

                return $p;
            }],
            'type hors liste publiée' => [function (array $p): array {
                $p['items'][0]['type'] = 'firmware';

                return $p;
            }],
            'types dupliqués' => [function (array $p): array {
                $p['items'][1]['type'] = $p['items'][0]['type'];

                return $p;
            }],
        ];
    }

    /** @param callable(array<string,mixed>): array<string,mixed> $mutate */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedReportProvider')]
    public function malformed_report_yields_422_with_details_and_writes_nothing(callable $mutate): void
    {
        [, $token] = $this->enrolledWorkstation();

        $this->report($token, $mutate($this->goldenReport()))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);

        $this->assertNothingWritten();
    }

    #[Test]
    public function hash_rule_rejects_trailing_newline_independently_of_trim_middleware(): void
    {
        // Review 24.1 #1 : sans /D, `$` PCRE tolère un \n final — 65 octets
        // passeraient la règle (varchar(64) PG = 22001/500). Via HTTP le
        // middleware global TrimStrings masque le cas (\n trimé avant
        // validation) : on teste donc les règles directement, la validation
        // ne doit PAS dépendre de ce middleware.
        $payload = $this->goldenReport();
        $payload['items'][0]['hash'] = str_repeat('a', 64) . "\n";

        $validator = \Illuminate\Support\Facades\Validator::make(
            $payload,
            (new \App\Http\Requests\Api\V1\Agent\ReportRequest())->rules(),
        );

        self::assertTrue($validator->fails());
        self::assertArrayHasKey('items.0.hash', $validator->errors()->messages());
    }

    #[Test]
    public function malformed_json_body_is_4xx_never_500(): void
    {
        [, $token] = $this->enrolledWorkstation();

        $response = $this->call('POST', self::REPORT_ROUTE, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], '{"schema": "se5.desired-state/v1", "items": [INVALID');

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertLessThan(500, $response->getStatusCode(), 'rapport forgé = 4xx, jamais 500 (defer 23.1)');
        $this->assertNothingWritten();
    }

    #[Test]
    public function invalid_utf8_body_is_4xx_never_500(): void
    {
        [, $token] = $this->enrolledWorkstation();

        $response = $this->call('POST', self::REPORT_ROUTE, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], "{\"schema\": \"se5.desired-state/v1\", \"agent_version\": \"\xC3\x28\", \"items\": []}");

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertLessThan(500, $response->getStatusCode(), 'UTF-8 invalide = 4xx, jamais 500 (defer 23.1)');
        $this->assertNothingWritten();
    }

    // ── AC5 — logs & observabilité ────────────────────────────────────────

    #[Test]
    public function accepted_report_logs_received_with_status_counts(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();

        $logs = $this->captureAgentLogs();
        $this->report($token, $this->reportPayload($ws, [
            $this->item('wallpaper', 'compliant'),
            $this->item('overlay', 'drift'),
        ]))->assertOk();

        $received = $this->logsOfType($logs, 'agent.report.received');
        self::assertCount(1, $received);
        self::assertSame('info', $received[0][0]);
        self::assertSame($ws->id, $received[0][2]['workstation_id']);
        self::assertSame(
            ['compliant' => 1, 'drift' => 1, 'error' => 0],
            $received[0][2]['counts'],
        );
    }

    #[Test]
    public function drift_events_log_one_warning_per_type_without_spam_on_identical_report(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $payload = $this->reportPayload($ws, [
            $this->item('overlay', 'drift'),
            $this->item('printers', 'error', 'spooler KO'),
            $this->item('wallpaper', 'compliant'),
        ]);

        $logs = $this->captureAgentLogs();
        $this->report($token, $payload)->assertOk();

        $drifts = $this->logsOfType($logs, 'agent.report.drift');
        self::assertCount(2, $drifts, 'drift + error = 2 warnings, le compliant n\'en émet pas');
        foreach ($drifts as $drift) {
            self::assertSame('warning', $drift[0]);
            self::assertSame($ws->id, $drift[2]['workstation_id']);
            self::assertContains($drift[2]['type'], ['overlay', 'printers']);
        }

        // Rapport identique → zéro nouveau warning (pas de spam).
        $this->report($token, $payload)->assertOk();
        self::assertCount(2, $this->logsOfType($logs, 'agent.report.drift'));
    }

    #[Test]
    public function declared_identity_divergence_logs_warning_but_ingestion_proceeds(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $payload = $this->reportPayload($ws, [$this->item('wallpaper', 'compliant')]);
        $payload['workstation'] = ['hostname' => 'autre-poste-clone', 'uuid' => $ws->uuid];

        $logs = $this->captureAgentLogs();
        $this->report($token, $payload)->assertOk();

        $mismatch = $this->logsOfType($logs, 'agent.report.identity_mismatch');
        self::assertCount(1, $mismatch);
        self::assertSame('warning', $mismatch[0][0]);
        self::assertSame($ws->id, $mismatch[0][2]['workstation_id']);
        self::assertSame('autre-poste-clone', $mismatch[0][2]['declared_hostname']);
        // L'ingestion a POURSUIVI (décision n° 1) : l'état est écrit.
        self::assertSame(1, AgentResourceState::query()->where('workstation_id', $ws->id)->count());
    }

    // ── AC6 — sécurité du canal ───────────────────────────────────────────

    #[Test]
    public function missing_bearer_returns_401_with_middleware_error_format(): void
    {
        $this->postJson(self::REPORT_ROUTE, [])
            ->assertStatus(401)
            ->assertJson([
                'error' => 'unauthorized',
                'code' => AuthenticateAgentToken::CODE_TOKEN_MISSING,
            ])
            ->assertJsonStructure(['error', 'message', 'code']);
    }

    #[Test]
    public function invalid_token_returns_401(): void
    {
        Workstation::factory()->create();

        $this->report(str_repeat('f', 64), $this->goldenReport())
            ->assertStatus(401)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_TOKEN_INVALID]);
    }

    #[Test]
    public function quarantined_workstation_gets_403_and_nothing_is_written(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $this->service->quarantine($ws->refresh(), 'test');

        $this->report($token, $this->goldenReport())
            ->assertStatus(403)
            ->assertJson([
                'error' => 'forbidden',
                'code' => AuthenticateAgentToken::CODE_QUARANTINED,
            ]);

        $this->assertNothingWritten();
    }

    #[Test]
    public function due_rotation_token_survives_the_report_200_response(): void
    {
        // Invariant D5 (piège n° 4) : le header de rotation posé par le
        // middleware doit survivre à la réponse du POST report.
        [$ws, $token] = $this->enrolledWorkstation();
        $ws->agent_token_rotated_at = now()->subDays((int) config('agent.token_rotation_days') + 1);
        $ws->save();

        $response = $this->report($token, $this->reportPayload($ws, [$this->item('wallpaper', 'compliant')]));

        $response->assertOk();
        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        // Le nouveau token est immédiatement utilisable.
        $this->report($new, $this->reportPayload($ws, [$this->item('wallpaper', 'compliant')]))->assertOk();
    }

    #[Test]
    public function rotation_header_survives_even_a_422_response(): void
    {
        // D5 durci : même une réponse de validation refusée porte le
        // nouveau token — sinon une rotation émise sur un rapport forgé
        // serait perdue (lock-out au retour du rapport suivant).
        [$ws, $token] = $this->enrolledWorkstation();
        $ws->agent_token_rotated_at = now()->subDays((int) config('agent.token_rotation_days') + 1);
        $ws->save();

        $response = $this->report($token, ['schema' => 'wrong']);

        $response->assertStatus(422);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            (string) $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN),
        );
    }
}
