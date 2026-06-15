<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\User;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\StateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature du compagnon de session — Story 24.3 (AC7).
 *
 * Simule, CÔTÉ SERVEUR uniquement (jamais de test Windows ici), le chemin
 * HTTP du sous-système compagnon : le fetch SYSTEM déclenché au logon tire
 * `GET /state?user=<login court>` avec l'`If-None-Match` DU contexte
 * (poste, user) et alimente le cache per-user que le processus user lit en
 * lecture seule (`agent/windows/SessionStateFetch.ps1` + `SessionCompanion.ps1`,
 * vue serveur dans docs/agent/session-companion.md). AUCUN code serveur
 * n'est modifié par la story : le `?user=` existe depuis 23.5 — ces tests
 * FIGENT le comportement que le compagnon consomme.
 *
 * Invariants couverts : un ETag PAR couple (poste, user) — jamais de
 * revalidation cross-contexte ; login inconnu/compte local → 200
 * machine-only + `agent.state.unknown_user`, jamais d'erreur (piège n° 3) ;
 * lookup case-insensitive (sémantique AD) ; rotation D5 sur le chemin
 * compagnon (200 ET 304) ; quarantaine 403 = aucun fetch de session. La
 * matrice fine du `?user=` vit dans `StateEndpointTest` (23.5) — ici, le
 * CONTRAT DU SOUS-SYSTÈME compagnon.
 */
final class SessionCompanionE2eTest extends TestCase
{
    use RefreshDatabase;

    private const STATE_ROUTE = '/api/v1/agent/state';

    private TokenRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
    }

    /**
     * GET /state tel que le fetch SYSTEM l'appelle : machine-only (service,
     * 24.2) ou contexte user (`?user=<login court>`, fetch de session 24.3).
     */
    private function state(string $token, array $headers = [], ?string $user = null): TestResponse
    {
        $query = $user === null ? '' : '?user=' . rawurlencode($user);

        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer ' . $token,
        ], $headers))->getJson(self::STATE_ROUTE . $query);
    }

    /**
     * Poste enrôlé + wallpaper broadcast (l'état machine-only non vide) +
     * user du domaine portant un wallpaper ciblé : le contexte user diffère
     * STRUCTURELLEMENT du contexte machine (deux états, deux ETags).
     *
     * @return array{0: Workstation, 1: string, 2: User, 3: WallpaperAsset}
     */
    private function enrolledWorkstationAndTargetedUser(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        Wallpaper::factory()->default()->create();

        $user = User::factory()->create();
        $userAsset = WallpaperAsset::factory()->create();
        Wallpaper::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'asset_id' => $userAsset->id,
        ]);

        return [$ws->refresh(), $token, $user, $userAsset];
    }

    /** Décale l'échéance de rotation D5 du poste (pattern AgentSkeletonE2eTest). */
    private function makeRotationDue(Workstation $ws): void
    {
        $ws->refresh();
        $ws->agent_token_rotated_at = now()->subDays((int) config('agent.token_rotation_days') + 1);
        $ws->save();
    }

    /**
     * Capture les logs du channel `agent` (pattern 23.5/24.2 — mock étendu
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

    // ── Logon nominal — `?user=` connu : enveloppe v1, ETag DU contexte ──

    #[Test]
    public function session_fetch_for_a_known_user_gets_the_envelope_with_its_own_context_etag(): void
    {
        [, $token, $user, $userAsset] = $this->enrolledWorkstationAndTargetedUser();
        $machineEtag = $this->state($token)->assertOk()->headers->get('ETag');

        $response = $this->state($token, [], $user->login)->assertOk();

        // Enveloppe v1 BRUTE (Parse-State du compagnon) : mêmes clés que le
        // contexte machine — le compagnon reçoit les 3 portées et n'en
        // traite que deux (session + machine_user), la partition est à LUI.
        $state = $response->json();
        self::assertSame(
            ['schema', 'generated_at', 'ttl_seconds', 'debug', ...StateContract::scopes()],
            array_keys($state),
        );
        self::assertSame(StateContract::SCHEMA, $state['schema']);

        // La règle user-ciblée sort dans la portée session du contexte user.
        $payloads = array_column($state[StateContract::SCOPE_SESSION], 'payload');
        self::assertContains($userAsset->filename, array_column($payloads, 'asset'));

        // Un ETag PAR couple (poste, user) : quoté RFC 7232, stocké VERBATIM
        // dans cache\sessions\<SID>\etag.txt — différent de l'ETag machine.
        $userEtag = $response->headers->get('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', (string) $userEtag);
        self::assertNotSame($machineEtag, $userEtag);
    }

    // ── Revalidation PAR contexte — jamais cross-contexte (piège n° 2) ───

    #[Test]
    public function if_none_match_revalidates_its_own_context_and_never_the_other(): void
    {
        [, $token, $user] = $this->enrolledWorkstationAndTargetedUser();
        $machineEtag = $this->state($token)->assertOk()->headers->get('ETag');
        $userEtag = $this->state($token, [], $user->login)->assertOk()->headers->get('ETag');

        // Chaque contexte revalide avec SON ETag (c'est le cycle nominal :
        // service ET fetch de session voient un 304 quand rien ne change).
        $this->state($token, ['If-None-Match' => $machineEtag])->assertStatus(304);
        $this->state($token, ['If-None-Match' => $userEtag], $user->login)->assertStatus(304);

        // Cross-contexte → 200 : réutiliser cache\etag.txt (machine) pour un
        // fetch `?user=` (ou l'inverse) casserait la revalidation — c'est le
        // bug que le fichier etag.txt PAR répertoire de session prévient.
        $this->state($token, ['If-None-Match' => $userEtag])->assertOk();
        $this->state($token, ['If-None-Match' => $machineEtag], $user->login)->assertOk();
    }

    // ── Login inconnu / compte local — machine-only, jamais d'erreur ─────

    #[Test]
    public function unknown_login_gets_the_machine_only_state_and_logs_unknown_user(): void
    {
        // Le cas LÉGITIME du compagnon : session d'un admin local (compte
        // hors SE5). Le fetch part quand même — le serveur répond l'état
        // machine-only (broadcasts possibles), 200, jamais d'erreur.
        [$ws, $token] = $this->enrolledWorkstationAndTargetedUser();
        $machineEtag = $this->state($token)->assertOk()->headers->get('ETag');

        $logs = $this->captureAgentLogs();
        $response = $this->state($token, [], 'admin-local')->assertOk();

        // Même état (et même ETag) que machine-only : la session locale
        // reçoit un état exploitable, le compagnon le traite sans bruit.
        self::assertSame($machineEtag, $response->headers->get('ETag'));
        $unknown = $this->logsOfType($logs, 'agent.state.unknown_user');
        self::assertCount(1, $unknown);
        self::assertSame('info', $unknown[0][0]);
        self::assertSame($ws->id, $unknown[0][2]['workstation_id']);
        self::assertSame('admin-local', $unknown[0][2]['login']);
    }

    #[Test]
    public function empty_user_param_yields_the_machine_context_without_unknown_user_noise(): void
    {
        // Garde-fou serveur du cas que la liste blanche SID de
        // Get-InteractiveSessions prévient côté poste (review 24.3 #1) : si
        // un login VIDE passait quand même (`?user=`), le serveur doit rester
        // un contexte machine PROPRE — 200, même ETag que machine-only, et
        // surtout AUCUN log agent.state.unknown_user (un login vide n'est pas
        // « inconnu », il est absent).
        [, $token] = $this->enrolledWorkstationAndTargetedUser();
        $machineEtag = $this->state($token)->assertOk()->headers->get('ETag');

        $logs = $this->captureAgentLogs();
        $response = $this->state($token, [], '')->assertOk();

        self::assertSame($machineEtag, $response->headers->get('ETag'));
        self::assertCount(0, $this->logsOfType($logs, 'agent.state.unknown_user'));
        // Et la revalidation du contexte machine traverse le `?user=` vide.
        $this->state($token, ['If-None-Match' => $machineEtag], '')->assertStatus(304);
    }

    #[Test]
    public function user_lookup_is_case_insensitive_and_yields_the_canonical_context(): void
    {
        // L'énumération CIM donne la casse SAM du compte ; le serveur résout
        // case-insensitive (sémantique AD) : JDOE et jdoe = MÊME contexte,
        // même état, même ETag — le cache per-SID reste cohérent quelle que
        // soit la casse remontée par Windows.
        [, $token, $user] = $this->enrolledWorkstationAndTargetedUser();
        $canonicalEtag = $this->state($token, [], $user->login)->assertOk()->headers->get('ETag');

        $logs = $this->captureAgentLogs();
        $response = $this->state($token, [], strtoupper($user->login))->assertOk();

        self::assertSame($canonicalEtag, $response->headers->get('ETag'));
        // Et le 304 du contexte traverse la casse : un seul ETag par couple.
        $this->state($token, ['If-None-Match' => $canonicalEtag], strtoupper($user->login))
            ->assertStatus(304);
        self::assertCount(0, $this->logsOfType($logs, 'agent.state.unknown_user'));
    }

    // ── Rotation D5 sur le chemin compagnon — 200 ET 304 ─────────────────

    #[Test]
    public function due_rotation_surfaces_on_a_session_fetch_200_and_the_new_token_works(): void
    {
        [$ws, $token, $user] = $this->enrolledWorkstationAndTargetedUser();
        $this->makeRotationDue($ws);

        $response = $this->state($token, [], $user->login)->assertOk();

        // Le fetch de session est un acteur réseau À PART ENTIÈRE du canal :
        // la rotation peut tomber sur LUI (Update-TokenIfRotated partagé) —
        // c'est l'origine de la course deux-acteurs que le durcissement 401
        // (relecture disque) couvre côté poste.
        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        $this->state($new, [], $user->login)->assertOk();
    }

    #[Test]
    public function due_rotation_survives_a_session_fetch_304(): void
    {
        // Cycle nominal du compagnon = souvent un 304 (état stable) : la
        // rotation doit y survivre aussi sur le chemin `?user=` (invariant
        // D5), sinon un poste dont seules les sessions check-in entre deux
        // cycles machine ne rotaterait jamais.
        [$ws, $token, $user] = $this->enrolledWorkstationAndTargetedUser();
        $userEtag = $this->state($token, [], $user->login)->assertOk()->headers->get('ETag');
        $this->makeRotationDue($ws);

        $response = $this->state($token, ['If-None-Match' => $userEtag], $user->login);

        $response->assertStatus(304);
        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        // Le nouveau token revalide immédiatement le MÊME contexte user.
        $this->state($new, ['If-None-Match' => $userEtag], $user->login)->assertStatus(304);
    }

    // ── Quarantaine — aucun fetch de session (piège n° 11) ───────────────

    #[Test]
    public function quarantined_workstation_gets_403_on_a_session_fetch(): void
    {
        // Côté poste, l'agent NE FAIT PAS de fetch de session en quarantaine
        // (les check-ins légers restent le GET /state machine du service) ;
        // si un agent bogué le tentait, le serveur refuse — même format 23.2.
        [$ws, $token, $user] = $this->enrolledWorkstationAndTargetedUser();
        $this->service->quarantine($ws->refresh(), 'test');

        $this->state($token, [], $user->login)
            ->assertStatus(403)
            ->assertJson([
                'error' => 'forbidden',
                'code' => AuthenticateAgentToken::CODE_QUARANTINED,
            ]);
    }

    // ── Séquence logon complète vue serveur ──────────────────────────────

    #[Test]
    public function full_logon_sequence_machine_and_session_contexts_live_side_by_side(): void
    {
        // LA séquence d'un poste 24.3 en régime établi, vue serveur : cycle
        // machine (service) puis fetch de session (logon), chacun sur SON
        // ETag — deux caches, zéro interférence, et le cache user revalide
        // toujours après un passage machine (rien ne l'écrase).
        [, $token, $user] = $this->enrolledWorkstationAndTargetedUser();

        $machineEtag = $this->state($token)->assertOk()->headers->get('ETag');
        $userEtag = $this->state($token, [], $user->login)->assertOk()->headers->get('ETag');

        $this->state($token, ['If-None-Match' => $machineEtag])->assertStatus(304);
        $this->state($token, ['If-None-Match' => $userEtag], $user->login)->assertStatus(304);
        $this->state($token, ['If-None-Match' => $machineEtag])->assertStatus(304);
        $this->state($token, ['If-None-Match' => $userEtag], $user->login)->assertStatus(304);
    }
}
