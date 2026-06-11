<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\OverlaySignal;
use App\Models\User;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature `GET /api/v1/agent/state` — Story 23.5 (AC1-AC6).
 *
 * Route RÉELLE (`agent.v1.state`) derrière la chaîne complète
 * `auth.v1.secure-headers` + `throttle:60,1` + `agent.token` — pas de route
 * éphémère ici (le piège setRoutes() de 23.2 ne concernait que les routes
 * déclarées à l'exécution). L'état est fabriqué via les tables métier
 * réelles (conventions 23.4) ; la réponse est validée par les INVARIANTS du
 * contrat (iso `ContractV1Test`), jamais par comparaison au golden file.
 */
final class StateEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const STATE_ROUTE = '/api/v1/agent/state';

    private TokenRotationService $service;

    private StateHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
        $this->hasher = app(StateHasher::class);
    }

    private function state(string $token, array $headers = [], string $query = ''): TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer ' . $token,
        ], $headers))->getJson(self::STATE_ROUTE . $query);
    }

    /**
     * Poste enrôlé avec un fond d'écran broadcast — l'état non vide minimal.
     *
     * @return array{0: Workstation, 1: string}
     */
    private function enrolledWorkstationWithBroadcastWallpaper(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        Wallpaper::factory()->default()->create();

        return [$ws->refresh(), $token];
    }

    /**
     * Capture les logs du channel `agent` (pattern `StateCompilerTest`) —
     * `ArrayObject` pour la référence partagée avec les closures.
     *
     * @return \ArrayObject<int, array{0:string,1:string,2:array<string,mixed>}>
     */
    private function captureAgentLogs(): \ArrayObject
    {
        $logs = new \ArrayObject();
        Log::shouldReceive('channel')->with('agent')->andReturnSelf();
        // `error`/`critical` stubés aussi (review 23.5) : un futur log d'erreur
        // dans la requête doit apparaître dans la capture, pas faire échouer le
        // test en BadMethodCallException Mockery opaque.
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

    // ── AC1 — 200 : enveloppe v1 brute + ETag ────────────────────────────

    #[Test]
    public function ok_response_is_the_raw_v1_envelope_without_se5_wrapper(): void
    {
        [$ws, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        $room = WorkstationGroup::factory()->create();
        $ws->groups()->attach($room->id);
        OverlaySignal::create(['kind' => 'info', 'severity' => 'info', 'title' => 'b', 'text' => 'b']);
        OverlaySignal::create([
            'kind' => 'info', 'severity' => 'info', 'title' => 'g', 'text' => 'g',
            'workstation_group_id' => $room->id,
        ]);
        config(['agent.ttl_seconds' => 1234]);

        $response = $this->state($token)->assertOk();
        $state = $response->json();

        // Enveloppe BRUTE : exactement les clés du contrat, aucun wrapper SE5.
        self::assertSame(
            ['schema', 'generated_at', 'ttl_seconds', ...StateContract::scopes()],
            array_keys($state),
        );
        self::assertSame(StateContract::SCHEMA, $state['schema']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $state['generated_at'],
        );
        self::assertSame(1234, $state['ttl_seconds']);
        foreach (StateContract::scopes() as $scope) {
            self::assertIsList($state[$scope]);
        }
        self::assertNotEmpty($state[StateContract::SCOPE_SESSION]);
    }

    #[Test]
    public function every_item_carries_exactly_the_five_contract_keys_with_verifiable_hash(): void
    {
        [$ws, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        OverlaySignal::create(['kind' => 'info', 'severity' => 'info', 'title' => 'b', 'text' => 'b']);

        $state = $this->state($token)->assertOk()->json();

        $items = array_merge(...array_map(fn (string $s): array => $state[$s], StateContract::scopes()));
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertSame(['type', 'semantics', 'mode', 'payload', 'hash'], array_keys($item));
            self::assertSame(
                $this->hasher->hashItem($item),
                $item['hash'],
                'hash d\'item recalculable depuis le corps décodé (StateHasher::hashItem)',
            );
        }
    }

    #[Test]
    public function etag_header_is_the_quoted_state_hash_of_the_body(): void
    {
        [, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();

        $response = $this->state($token)->assertOk();

        // Forme quotée RFC 7232 (piège n° 2) : setEtag() ajoute les guillemets.
        self::assertSame(
            '"' . $this->hasher->hashState($response->json()) . '"',
            $response->headers->get('ETag'),
        );
    }

    // ── AC2 — 304 : réponse conditionnelle ───────────────────────────────

    #[Test]
    public function matching_if_none_match_returns_304_without_body_and_keeps_etag(): void
    {
        [, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        $etag = $this->state($token)->assertOk()->headers->get('ETag');

        $response = $this->state($token, ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        self::assertSame('', $response->getContent(), '304 sans corps');
        self::assertSame($etag, $response->headers->get('ETag'), 'ETag conservé sur la réponse 304');
    }

    #[Test]
    public function not_modified_is_logged_on_agent_channel_with_workstation_context(): void
    {
        [$ws, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        $etag = $this->state($token)->assertOk()->headers->get('ETag');

        $logs = $this->captureAgentLogs();
        $this->state($token, ['If-None-Match' => $etag])->assertStatus(304);

        $notModified = $this->logsOfType($logs, 'agent.state.not_modified');
        self::assertCount(1, $notModified);
        self::assertSame('debug', $notModified[0][0]);
        self::assertSame($ws->id, $notModified[0][2]['workstation_id']);
        self::assertNull($notModified[0][2]['user']);
    }

    #[Test]
    public function same_state_at_different_instants_yields_304_through_http(): void
    {
        // LE test qui valide l'ETag de bout en bout : le déterminisme 23.4
        // (generated_at exclu du hash) prouvé à travers la couche HTTP.
        [, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        $etag = $this->state($token)->assertOk()->headers->get('ETag');

        $this->travel(3)->hours();

        $this->state($token, ['If-None-Match' => $etag])->assertStatus(304);
    }

    #[Test]
    public function rule_change_between_calls_invalidates_the_etag(): void
    {
        [$ws, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        $room = WorkstationGroup::factory()->create();
        $ws->groups()->attach($room->id);
        $etag = $this->state($token)->assertOk()->headers->get('ETag');

        // Nouveau wallpaper sur le WG du poste : maille plus spécifique que
        // broadcast → l'état servi change.
        Wallpaper::factory()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $room->id]);

        $response = $this->state($token, ['If-None-Match' => $etag])->assertOk();
        self::assertNotSame($etag, $response->headers->get('ETag'));
    }

    // ── AC3 — user optionnel ──────────────────────────────────────────────

    #[Test]
    public function machine_only_call_serves_no_user_targeted_rule_in_any_scope(): void
    {
        [$ws, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        $user = User::factory()->create();
        $userAsset = WallpaperAsset::factory()->create();
        Wallpaper::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'asset_id' => $userAsset->id,
        ]);
        OverlaySignal::create([
            'kind' => 'alert', 'severity' => 'warning', 'title' => 'u', 'text' => 'u',
            'user_login' => $user->login,
        ]);

        $state = $this->state($token)->assertOk()->json();

        // Les règles de mailles machine restent servies dans LEUR portée
        // déclarée (wallpaper broadcast → session, décision n° 2)…
        self::assertNotEmpty($state[StateContract::SCOPE_SESSION]);
        // …mais aucune contribution user ne sort, dans aucune portée.
        self::assertStringNotContainsString($userAsset->filename, json_encode($state));
        self::assertStringNotContainsString('"u"', json_encode($state));
    }

    #[Test]
    public function known_user_brings_user_rules_out_and_changes_the_etag(): void
    {
        [$ws, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        $user = User::factory()->create();
        $userAsset = WallpaperAsset::factory()->create();
        Wallpaper::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'asset_id' => $userAsset->id,
        ]);

        $machineOnly = $this->state($token)->assertOk();
        $withUser = $this->state($token, [], '?user=' . $user->login)->assertOk();

        // La maille user (plus spécifique) gagne l'exclusif wallpaper.
        $payloads = array_column($withUser->json()[StateContract::SCOPE_SESSION], 'payload');
        self::assertContains($userAsset->filename, array_column($payloads, 'asset'));
        // Deux contextes = deux états = deux ETags (décision n° 3).
        self::assertNotSame(
            $machineOnly->headers->get('ETag'),
            $withUser->headers->get('ETag'),
        );
    }

    #[Test]
    public function user_lookup_is_case_insensitive(): void
    {
        [, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        $user = User::factory()->create();
        $userAsset = WallpaperAsset::factory()->create();
        Wallpaper::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'asset_id' => $userAsset->id,
        ]);

        $state = $this->state($token, [], '?user=' . strtoupper($user->login))->assertOk()->json();

        $payloads = array_column($state[StateContract::SCOPE_SESSION], 'payload');
        self::assertContains($userAsset->filename, array_column($payloads, 'asset'));
    }

    #[Test]
    public function unknown_user_behaves_machine_only_and_logs_without_error(): void
    {
        [$ws, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        $machineOnlyEtag = $this->state($token)->assertOk()->headers->get('ETag');

        $logs = $this->captureAgentLogs();
        $response = $this->state($token, [], '?user=compte-local-inconnu')->assertOk();

        // Même état que machine-only — une session locale reçoit un état.
        self::assertSame($machineOnlyEtag, $response->headers->get('ETag'));
        $unknown = $this->logsOfType($logs, 'agent.state.unknown_user');
        self::assertCount(1, $unknown);
        self::assertSame('info', $unknown[0][0]);
        self::assertSame($ws->id, $unknown[0][2]['workstation_id']);
        self::assertSame('compte-local-inconnu', $unknown[0][2]['login']);
    }

    #[Test]
    public function empty_user_param_is_machine_only_without_unknown_user_log(): void
    {
        [, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();

        $logs = $this->captureAgentLogs();
        $this->state($token, [], '?user=')->assertOk();

        self::assertCount(0, $this->logsOfType($logs, 'agent.state.unknown_user'));
    }

    // ── AC4 — ttl_seconds durci ───────────────────────────────────────────

    #[Test]
    public function null_ttl_config_key_falls_back_to_3600_not_zero(): void
    {
        // Defer review 23.4 : le défaut de config() ne couvre que l'ABSENCE
        // de clé — une clé null (env vide) casterait en 0 sans le `??`.
        config(['agent.ttl_seconds' => null]);
        [, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();

        $this->state($token)->assertOk()->assertJsonPath('ttl_seconds', 3600);
    }

    // ── AC5 — sécurité du canal ───────────────────────────────────────────

    #[Test]
    public function missing_bearer_returns_401_with_middleware_error_format(): void
    {
        $this->getJson(self::STATE_ROUTE)
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

        $this->state(str_repeat('f', 64))
            ->assertStatus(401)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_TOKEN_INVALID]);
    }

    #[Test]
    public function quarantined_workstation_returns_403(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $this->service->quarantine($ws->refresh(), 'test');

        $this->state($token)
            ->assertStatus(403)
            ->assertJson([
                'error' => 'forbidden',
                'code' => AuthenticateAgentToken::CODE_QUARANTINED,
            ]);
    }

    #[Test]
    public function due_rotation_token_survives_a_304_response(): void
    {
        // Invariant D5 (piège n° 3) : rotation due + état inchangé — la
        // réponse de rotation perdue se ré-émet AUSSI sur un 304, sinon le
        // poste resterait sur l'ancien token pour toujours.
        [$ws, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        $etag = $this->state($token)->assertOk()->headers->get('ETag');

        $ws->refresh();
        $ws->agent_token_rotated_at = now()->subDays((int) config('agent.token_rotation_days') + 1);
        $ws->save();

        $response = $this->state($token, ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        // Le nouveau token est immédiatement utilisable sur le même état.
        $this->state($new, ['If-None-Match' => $etag])->assertStatus(304);
    }

    #[Test]
    public function checkin_is_stamped_by_the_middleware_on_state_calls(): void
    {
        [$ws, $token] = $this->enrolledWorkstationWithBroadcastWallpaper();
        self::assertNull($ws->agent_last_checkin_at);

        $this->state($token)->assertOk();

        self::assertNotNull($ws->refresh()->agent_last_checkin_at);
    }
}
