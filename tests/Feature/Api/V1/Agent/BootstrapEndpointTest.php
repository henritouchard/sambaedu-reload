<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Auth\V1\Pki\CaInitializer;
use App\Models\AgentRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Story 25.4 — Endpoints d'amorçage LAN NON authentifiés (AC4) :
 * `GET /api/v1/agent/stable`, `GET /api/v1/agent/stable/download`,
 * `GET /api/v1/agent/ca`.
 *
 * Routes RÉELLES (`agent.v1.stable*`, `agent.v1.ca`) derrière
 * `local.request` + `auth.v1.secure-headers` + `throttle:60,1`, HORS du groupe
 * `agent.token` (pas de bearer requis). Confinement realpath iso 25.1, 404
 * indistinct, 503 si CA non initialisée. `local.request` rejette hors LAN.
 */
final class BootstrapEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const STABLE_ROUTE = '/api/v1/agent/stable';

    private const DOWNLOAD_ROUTE = '/api/v1/agent/stable/download';

    private const CA_ROUTE = '/api/v1/agent/ca';

    private string $releasesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->releasesDir = storage_path('framework/testing/bootstrap-releases-' . uniqid());
        File::ensureDirectoryExists($this->releasesDir);
        config(['agent.releases_path' => $this->releasesDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->releasesDir);
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Release stable RÉELLE : binaire factice sur disque + ligne
     * `agent_releases` au hash exact (invariant de création 25.1).
     */
    private function publishedStable(string $version, bool $stable = true, ?string $content = null): AgentRelease
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

    /**
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

    private function fromLan(string $uri): TestResponse
    {
        // 127.0.0.1 toujours autorisé par EnsureLocalRequest.
        return $this->call('GET', $uri, server: ['REMOTE_ADDR' => '127.0.0.1']);
    }

    // ── AC4 — manifest stable ────────────────────────────────────────────

    #[Test]
    public function stable_manifest_serves_the_stable_release_with_absolute_url(): void
    {
        $stable = $this->publishedStable('2.0.0');
        // Une canari publiée NON stable ne doit jamais fuir par cet endpoint.
        $this->publishedStable('2.1.2', stable: false);

        $response = $this->fromLan(self::STABLE_ROUTE);

        $response->assertOk()->assertJson([
            'success' => true,
            'version' => '2.0.0',
            'hash' => $stable->hash,
        ]);
        $url = (string) $response->json('url');
        self::assertSame(route('agent.v1.stable.download'), $url);
        self::assertStringStartsWith('http', $url);
        // URL FIXE : pas de filename dans l'URL.
        self::assertStringEndsWith('/api/v1/agent/stable/download', $url);
    }

    #[Test]
    public function stable_manifest_returns_404_when_no_stable_published(): void
    {
        // Une release existe mais n'est pas stable : pas de 200 vide, pas de
        // canari par accident.
        $this->publishedStable('2.1.2', stable: false);

        $this->fromLan(self::STABLE_ROUTE)
            ->assertStatus(404)
            ->assertJson(['error' => 'no_release']);
    }

    #[Test]
    public function stable_manifest_logs_stable_served(): void
    {
        $this->publishedStable('2.0.0');

        $logs = $this->captureAgentLogs();
        $this->fromLan(self::STABLE_ROUTE)->assertOk();

        $served = $this->logsOfType($logs, 'agent.release.stable_served');
        self::assertCount(1, $served);
        self::assertSame('2.0.0', $served[0][2]['version']);
    }

    // ── AC4 — download du binaire stable ─────────────────────────────────

    #[Test]
    public function download_serves_the_stable_binary_whose_sha256_matches_the_manifest(): void
    {
        $stable = $this->publishedStable('2.0.0');

        $manifest = $this->fromLan(self::STABLE_ROUTE)->assertOk()->json();
        $response = $this->fromLan(self::DOWNLOAD_ROUTE);

        $response->assertOk();
        self::assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $served = $response->baseResponse->getFile()->getPathname();
        self::assertSame($this->releasesDir . '/' . $stable->filename, $served);
        self::assertSame($manifest['hash'], hash('sha256', (string) file_get_contents($served)));
    }

    #[Test]
    public function download_returns_indistinct_404_when_no_stable_published(): void
    {
        $this->publishedStable('2.1.2', stable: false);

        $this->fromLan(self::DOWNLOAD_ROUTE)
            ->assertStatus(404)
            ->assertJson(['error' => 'not_found']);
    }

    #[Test]
    public function download_returns_indistinct_404_when_file_missing_on_disk(): void
    {
        $stable = $this->publishedStable('2.0.0');
        unlink($this->releasesDir . '/' . $stable->filename);

        $this->fromLan(self::DOWNLOAD_ROUTE)
            ->assertStatus(404)
            ->assertJson(['error' => 'not_found']);
    }

    #[Test]
    public function download_never_serves_a_canary_binary(): void
    {
        // Seule la stable est servie : une canari publiée (non stable) avec un
        // binaire bien présent sur disque ne doit jamais sortir par l'amorçage.
        $this->publishedStable('2.1.2', stable: false);

        $this->fromLan(self::DOWNLOAD_ROUTE)->assertStatus(404);
    }

    #[Test]
    public function download_returns_404_when_stable_release_has_pathological_filename(): void
    {
        // Défense en profondeur (piège n° 8) : l'URL download est FIXE (aucun
        // input client), mais si une ligne `agent_releases` portait un filename
        // pathologique (traversal injecté en DB), la re-validation par le
        // pattern strict + le confinement realpath doivent sortir 404 indistinct
        // — jamais servir hors `releases_path`. Régression-test du garde-fou.
        AgentRelease::query()->create([
            'version' => '9.9.9',
            'hash' => str_repeat('a', 64),
            'filename' => 'sambaedu-agent-2.0.0.exe/../../../etc/passwd',
            'is_stable' => true,
        ]);

        $this->fromLan(self::DOWNLOAD_ROUTE)
            ->assertStatus(404)
            ->assertJson(['error' => 'not_found']);
    }

    // ── AC4 — racine CA ──────────────────────────────────────────────────

    #[Test]
    public function ca_endpoint_serves_pem_as_text_plain(): void
    {
        $pem = "-----BEGIN CERTIFICATE-----\nFAKECABODY\n-----END CERTIFICATE-----\n";
        $this->mock(CaInitializer::class, function ($mock) use ($pem): void {
            $mock->shouldReceive('getCaCertPem')->andReturn($pem);
        });

        $response = $this->fromLan(self::CA_ROUTE);

        $response->assertOk();
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertSame($pem, $response->getContent());
    }

    #[Test]
    public function ca_endpoint_returns_503_when_ca_not_initialized(): void
    {
        // Piège n° 9 : CA non initialisée → 503 (config serveur incomplète),
        // jamais 500.
        $this->mock(CaInitializer::class, function ($mock): void {
            $mock->shouldReceive('getCaCertPem')
                ->andThrow(new RuntimeException('CA root cert not initialized'));
        });

        $this->fromLan(self::CA_ROUTE)->assertStatus(503);
    }

    // ── AC4 — frontière LAN ──────────────────────────────────────────────

    #[Test]
    public function endpoints_reject_non_lan_callers_with_403(): void
    {
        $this->publishedStable('2.0.0');

        foreach ([self::STABLE_ROUTE, self::DOWNLOAD_ROUTE, self::CA_ROUTE] as $uri) {
            $this->call('GET', $uri, server: ['REMOTE_ADDR' => '8.8.8.8'])
                ->assertStatus(403);
        }
    }
}
