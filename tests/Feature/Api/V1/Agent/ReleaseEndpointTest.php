<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Tests Feature `GET /api/v1/agent/release` (manifest) et
 * `GET /api/v1/agent/releases/{filename}` (download) — Story 25.1
 * (AC2, AC3, AC4, AC6, AC7).
 *
 * Routes RÉELLES (`agent.v1.release`, `agent.v1.release.download`) derrière
 * la chaîne complète `auth.v1.secure-headers` + `throttle:60,1` +
 * `agent.token` (conventions `AssetEndpointTest`/`StateEndpointTest`).
 * Répertoire de releases réel sur disque (temporaire, pointé par
 * `agent.releases_path`) : le 200 du download est un VRAI
 * BinaryFileResponse dont le SHA-256 du contenu = le `hash` du manifest.
 * 404 indistinct pour filename malformé / inconnu DB / fichier absent.
 */
final class ReleaseEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const MANIFEST_ROUTE = '/api/v1/agent/release';

    private const DOWNLOAD_ROUTE = '/api/v1/agent/releases/';

    private TokenRotationService $service;

    private string $releasesDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Désactive la sync AD des observers : créer un WorkstationGroup via
        // factory dispatcherait WorkstationGroupAdSyncJob inline (queue sync)
        // → LDAP injoignable sur l'hôte de test.
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        $this->service = app(TokenRotationService::class);
        $this->releasesDir = storage_path('framework/testing/releases-' . uniqid());
        File::ensureDirectoryExists($this->releasesDir);
        config(['agent.releases_path' => $this->releasesDir]);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        File::deleteDirectory($this->releasesDir);

        parent::tearDown();
    }

    private function manifest(string $token): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get(self::MANIFEST_ROUTE);
    }

    private function download(string $token, string $filename): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get(self::DOWNLOAD_ROUTE . $filename);
    }

    /** @return array{0: Workstation, 1: string} */
    private function enrolledWorkstation(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        return [$ws->refresh(), $token];
    }

    /**
     * Release RÉELLE : binaire factice sur disque + ligne `agent_releases`
     * au hash exact du fichier (l'invariant garanti par la création AC1).
     */
    private function publishedRelease(string $version, bool $stable = false, ?string $content = null): AgentRelease
    {
        $content ??= "MZ\x90\x00fake-pe-bytes-" . $version;
        $filename = 'sambaedu-agent-' . $version . '.exe';
        file_put_contents($this->releasesDir . '/' . $filename, $content);

        return AgentRelease::query()->create([
            'version' => $version,
            'hash' => hash('sha256', $content),
            'filename' => $filename,
            'is_stable' => $stable,
        ]);
    }

    private function ringFor(WorkstationGroup $group, AgentRelease $release, ?string $updatedAt = null): AgentReleaseRing
    {
        $ring = AgentReleaseRing::query()->create([
            'workstation_group_id' => $group->id,
            'agent_release_id' => $release->id,
        ]);
        if ($updatedAt !== null) {
            // Query builder : bypass des timestamps Eloquent (la récence est
            // LA donnée sous test — décision n° 4).
            DB::table('agent_release_rings')->where('id', $ring->id)->update(['updated_at' => $updatedAt]);
        }

        return $ring->refresh();
    }

    /**
     * Capture les logs du channel `agent` (pattern `StateEndpointTest`).
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

    // ── AC2 — manifest résolu par ring ───────────────────────────────────

    #[Test]
    public function manifest_is_resolved_by_the_ring_of_the_workstation(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $this->publishedRelease('2.0.0', stable: true);
        $canary = $this->publishedRelease('2.1.2');
        $group = WorkstationGroup::factory()->create();
        $ws->groups()->attach($group->id);
        $this->ringFor($group, $canary);

        $response = $this->manifest($token);

        $response->assertOk()->assertJson([
            'success' => true,
            'version' => '2.1.2',
            'hash' => $canary->hash,
        ]);
        $url = (string) $response->json('url');
        self::assertSame(
            route('agent.v1.release.download', ['filename' => $canary->filename]),
            $url,
        );
        // URL ABSOLUE (piège n° 6) — jamais un chemin relatif.
        self::assertStringStartsWith('http', $url);
    }

    #[Test]
    public function manifest_response_conforms_to_the_golden_fixture_shape(): void
    {
        // Forme normative (NFR13 — consommée par les tests croisés Go 25.2),
        // PAS les valeurs : mêmes clés, même ordre, mêmes types/formats.
        [, $token] = $this->enrolledWorkstation();
        $this->publishedRelease('2.1.2', stable: true);

        $payload = $this->manifest($token)->assertOk()->json();
        $golden = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Agent/release-manifest.v1.json')),
            true,
        );

        self::assertSame(array_keys($golden), array_keys($payload));
        self::assertTrue($payload['success']);
        self::assertMatchesRegularExpression('/^[0-9A-Za-z.+~-]{1,32}$/', $payload['version']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['hash']);
        self::assertMatchesRegularExpression('#^https?://.+/api/v1/agent/releases/sambaedu-agent-.+\.exe$#', $payload['url']);
    }

    #[Test]
    public function multiple_rings_the_most_recently_updated_wins_with_a_warning(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $old = $this->publishedRelease('2.1.0');
        $new = $this->publishedRelease('2.1.2');
        $parc = WorkstationGroup::factory()->logical()->create();
        $lab = WorkstationGroup::factory()->create();
        $ws->groups()->attach([$parc->id, $lab->id]);
        // Ciblage parc ANCIEN, ciblage lab RÉCENT : le poste de lab reçoit
        // la canari (le cas canari de la décision n° 4).
        $this->ringFor($parc, $old, now()->subDays(3)->toDateTimeString());
        $this->ringFor($lab, $new, now()->toDateTimeString());

        $logs = $this->captureAgentLogs();
        $this->manifest($token)->assertOk()->assertJson(['version' => '2.1.2']);

        $conflicts = $this->logsOfType($logs, 'agent.release.ring_conflict');
        self::assertCount(1, $conflicts);
        self::assertSame('warning', $conflicts[0][0]);
        self::assertSame($ws->id, $conflicts[0][2]['workstation_id']);
        self::assertEqualsCanonicalizing([$parc->id, $lab->id], $conflicts[0][2]['group_ids']);
    }

    // ── AC3 — sans ring : stable, jamais une canari par accident ─────────

    #[Test]
    public function workstation_without_ring_receives_the_stable_release(): void
    {
        [, $token] = $this->enrolledWorkstation();
        $stable = $this->publishedRelease('2.0.0', stable: true);
        // Une canari ciblée sur un AUTRE groupe ne fuit jamais.
        $canary = $this->publishedRelease('2.1.2');
        $this->ringFor(WorkstationGroup::factory()->create(), $canary);

        $this->manifest($token)->assertOk()->assertJson([
            'success' => true,
            'version' => '2.0.0',
            'hash' => $stable->hash,
        ]);
    }

    #[Test]
    public function no_ring_and_no_stable_returns_404_no_release(): void
    {
        [, $token] = $this->enrolledWorkstation();
        // Une release existe mais n'est NI stable NI ciblée : pas de 200
        // vide ambigu, pas de canari par accident, pas de 500.
        $this->publishedRelease('2.1.2');

        $this->manifest($token)
            ->assertStatus(404)
            ->assertJson(['error' => 'no_release']);
    }

    #[Test]
    public function ring_pointing_to_a_deleted_release_falls_back_to_stable(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $stable = $this->publishedRelease('2.0.0', stable: true);
        $canary = $this->publishedRelease('2.1.2');
        $group = WorkstationGroup::factory()->create();
        $ws->groups()->attach($group->id);
        $this->ringFor($group, $canary);

        // FK cascade : la ligne ring disparaît avec la release (AC3).
        $canary->delete();
        self::assertSame(0, AgentReleaseRing::query()->count());

        $this->manifest($token)->assertOk()->assertJson(['version' => $stable->version]);
    }

    // ── AC4 — download : binaire exact, 404 indistinct ───────────────────

    #[Test]
    public function download_serves_the_binary_whose_sha256_matches_the_manifest_hash(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $release = $this->publishedRelease('2.1.2', stable: true);

        $manifest = $this->manifest($token)->assertOk()->json();
        $filename = basename((string) parse_url($manifest['url'], PHP_URL_PATH));

        $logs = $this->captureAgentLogs();
        $response = $this->download($token, $filename);

        $response->assertOk();
        self::assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $served = $response->baseResponse->getFile()->getPathname();
        self::assertSame($this->releasesDir . '/' . $release->filename, $served);
        // L'invariant AC4 : le SHA-256 du corps reçu = le hash du manifest
        // (l'agent 25.2 refusera tout binaire divergent).
        self::assertSame($manifest['hash'], hash('sha256', (string) file_get_contents($served)));

        $servedLogs = $this->logsOfType($logs, 'agent.release.download_served');
        self::assertCount(1, $servedLogs);
        self::assertSame($ws->id, $servedLogs[0][2]['workstation_id']);
        self::assertSame('2.1.2', $servedLogs[0][2]['version']);
    }

    #[Test]
    public function malformed_or_traversal_filenames_are_rejected_404_before_any_lookup(): void
    {
        [, $token] = $this->enrolledWorkstation();
        $this->publishedRelease('2.1.2', stable: true);

        $malformed = [
            'agent.exe',                                  // pas le préfixe produit par le build
            'sambaedu-agent-.exe',                        // version vide
            'sambaedu-agent-2.1.2.EXE',                   // extension majuscule
            'SAMBAEDU-AGENT-2.1.2.exe',                   // préfixe majuscule
            'sambaedu-agent-2.1.2.exe.txt',               // double extension
            'sambaedu-agent-2.1.2',                       // sans extension
            '..%5C..%5Cwindows%5Cnotepad.exe',            // traversal encodé (backslash)
            'sambaedu-agent-..%2F..%2Fpasswd.exe',        // traversal encodé (slash)
            'passwd',                                     // nom court arbitraire
        ];

        foreach ($malformed as $filename) {
            self::assertSame(
                404,
                $this->download($token, $filename)->status(),
                "filename '$filename' aurait dû être rejeté en 404",
            );
        }
    }

    #[Test]
    public function wellformed_but_unknown_release_returns_indistinct_404_with_log(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $this->publishedRelease('2.1.2', stable: true);

        $logs = $this->captureAgentLogs();
        $this->download($token, 'sambaedu-agent-9.9.9.exe')
            ->assertStatus(404)
            ->assertJson(['error' => 'not_found']);

        $notFound = $this->logsOfType($logs, 'agent.release.download_not_found');
        self::assertCount(1, $notFound);
        self::assertSame($ws->id, $notFound[0][2]['workstation_id']);
    }

    #[Test]
    public function known_release_with_missing_file_on_disk_returns_indistinct_404(): void
    {
        [, $token] = $this->enrolledWorkstation();
        $release = $this->publishedRelease('2.1.2', stable: true);
        unlink($this->releasesDir . '/' . $release->filename);

        $this->download($token, $release->filename)
            ->assertStatus(404)
            ->assertJson(['error' => 'not_found']);
    }

    #[Test]
    public function file_on_disk_without_db_row_is_never_served(): void
    {
        // Lookup DB d'abord (AC4) : un binaire orphelin déposé dans le
        // répertoire (résidu, dépôt manuel raté) n'est jamais servi.
        [, $token] = $this->enrolledWorkstation();
        file_put_contents($this->releasesDir . '/sambaedu-agent-0.0.1.exe', 'orphan-bytes');

        $this->download($token, 'sambaedu-agent-0.0.1.exe')->assertStatus(404);
    }

    // ── AC6 — sécurité du canal (middleware 23.2 inchangé) ───────────────

    #[Test]
    public function missing_bearer_returns_401_with_middleware_error_format(): void
    {
        $this->get(self::MANIFEST_ROUTE)
            ->assertStatus(401)
            ->assertJson([
                'error' => 'unauthorized',
                'code' => AuthenticateAgentToken::CODE_TOKEN_MISSING,
            ]);

        $this->get(self::DOWNLOAD_ROUTE . 'sambaedu-agent-2.1.2.exe')
            ->assertStatus(401)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_TOKEN_MISSING]);
    }

    #[Test]
    public function invalid_token_returns_401_invalid(): void
    {
        $this->manifest(str_repeat('f', 64))
            ->assertStatus(401)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_TOKEN_INVALID]);
    }

    #[Test]
    public function quarantined_workstation_returns_403_and_no_manifest(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $this->service->quarantine($ws->refresh(), 'test');
        $this->publishedRelease('2.1.2', stable: true);

        $this->manifest($token)
            ->assertStatus(403)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_QUARANTINED]);
    }

    #[Test]
    public function due_rotation_token_survives_the_manifest_200(): void
    {
        // Invariant D5 (piège n° 8) : le middleware pose X-Agent-New-Token
        // APRÈS $next() — la réponse manifest 200 doit le porter.
        [$ws, $token] = $this->enrolledWorkstation();
        $this->publishedRelease('2.1.2', stable: true);

        $ws->refresh();
        $ws->agent_token_rotated_at = now()->subDays((int) config('agent.token_rotation_days') + 1);
        $ws->save();

        $response = $this->manifest($token)->assertOk();

        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        // Le nouveau token est immédiatement utilisable sur la même route.
        $this->manifest((string) $new)->assertOk();
    }
}
