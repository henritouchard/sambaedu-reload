<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Tests Feature `GET /api/v1/agent/tools/{filename}` — Story 27.1bis (AC1, AC6).
 *
 * Route DÉDIÉE (`agent.v1.tools.download`) pour l'artefact d'OUTIL DE RENDU
 * portable (Rainmeter), distincte de `/releases` (réservé au binaire agent +
 * auto-update 25.2). Chaîne complète `auth.v1.secure-headers` +
 * `throttle:60,1` + `agent.token` (conventions `AssetEndpointTest`/
 * `ReleaseEndpointTest`). Répertoire réel sur disque pointé par
 * `agent.tools_path` : le 200 est un VRAI BinaryFileResponse. 404 INDISTINCT
 * pour filename malformé / absent / répertoire illisible (aucun oracle).
 */
final class ToolEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TOOL_ROUTE = '/api/v1/agent/tools/';

    private const ARTIFACT = 'sambaedu-rainmeter-4.5.18-portable.zip';

    private TokenRotationService $service;

    private string $toolsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
        $this->toolsDir = storage_path('framework/testing/tools-' . uniqid());
        File::ensureDirectoryExists($this->toolsDir);
        config(['agent.tools_path' => $this->toolsDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->toolsDir);

        parent::tearDown();
    }

    private function tool(string $token, string $filename, array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer ' . $token,
        ], $headers))->get(self::TOOL_ROUTE . $filename);
    }

    /** @return array{0: Workstation, 1: string} */
    private function enrolledWorkstation(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        return [$ws->refresh(), $token];
    }

    private function putArtifact(string $content = "PK\x03\x04fake-zip-bytes"): void
    {
        file_put_contents($this->toolsDir . '/' . self::ARTIFACT, $content);
    }

    // ── AC1/AC6 — 200 : contenu binaire de l'artefact ────────────────────

    #[Test]
    public function valid_token_serves_the_exact_binary_content_of_the_artifact(): void
    {
        [, $token] = $this->enrolledWorkstation();
        $content = "PK\x03\x04rainmeter-portable-é";
        $this->putArtifact($content);

        $response = $this->tool($token, self::ARTIFACT);

        $response->assertOk();
        self::assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $served = $response->baseResponse->getFile()->getPathname();
        self::assertSame($this->toolsDir . '/' . self::ARTIFACT, $served);
        self::assertSame($content, (string) file_get_contents($served));
    }

    #[Test]
    public function checkin_is_stamped_by_the_middleware_on_tool_calls(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $this->putArtifact();
        self::assertNull($ws->agent_last_checkin_at);

        $this->tool($token, self::ARTIFACT)->assertOk();

        self::assertNotNull($ws->refresh()->agent_last_checkin_at);
    }

    // ── AC6 — 404 : inconnu / malformé / traversal, jamais d'oracle ──────

    #[Test]
    public function wellformed_but_missing_artifact_returns_404(): void
    {
        [, $token] = $this->enrolledWorkstation();
        // Pattern valide mais aucun fichier sur disque.
        $this->tool($token, 'sambaedu-rainmeter-9.9.9-portable.zip')
            ->assertStatus(404)
            ->assertJson(['error' => 'not_found']);
    }

    #[Test]
    public function malformed_or_traversal_filenames_are_rejected_404_before_any_disk_access(): void
    {
        [, $token] = $this->enrolledWorkstation();
        $this->putArtifact();

        // NB : le traversal ENCODÉ %2F décodé par le routeur Laravel produit un
        // 500 framework AVANT le controller (quirk connu, iso ReleaseEndpoint —
        // le pattern exclut de toute façon tout séparateur). On teste donc les
        // formes que le controller voit réellement.
        $malformed = [
            'rainmeter.zip',              // pas le préfixe sambaedu-rainmeter-
            'sambaedu-rainmeter-4.5.exe', // mauvaise extension
            'sambaedu-agent-2.2.0.exe',   // un binaire agent n'est PAS un outil
            'SAMBAEDU-RAINMETER-4.5.zip', // casse
            'passwd',                     // nom court arbitraire
        ];

        foreach ($malformed as $filename) {
            self::assertSame(
                404,
                $this->tool($token, $filename)->status(),
                "filename '$filename' aurait dû être rejeté en 404",
            );
        }
    }

    #[Test]
    public function unknown_tools_path_returns_404_without_leaking(): void
    {
        [, $token] = $this->enrolledWorkstation();
        // Répertoire d'outils inexistant : realpath() = false → 404 indistinct.
        config(['agent.tools_path' => $this->toolsDir . '/does-not-exist']);

        $this->tool($token, self::ARTIFACT)->assertStatus(404);
    }

    // ── AC6 — sécurité du canal (middleware inchangé) ────────────────────

    #[Test]
    public function missing_bearer_returns_401_with_middleware_error_format(): void
    {
        $this->putArtifact();

        $this->get(self::TOOL_ROUTE . self::ARTIFACT)
            ->assertStatus(401)
            ->assertJson([
                'error' => 'unauthorized',
                'code' => AuthenticateAgentToken::CODE_TOKEN_MISSING,
            ]);
    }

    #[Test]
    public function quarantined_workstation_returns_403_and_nothing_is_served(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $this->service->quarantine($ws->refresh(), 'test');
        $this->putArtifact();

        $this->tool($token, self::ARTIFACT)
            ->assertStatus(403)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_QUARANTINED]);
    }

    #[Test]
    public function due_rotation_token_survives_the_binary_200(): void
    {
        // Invariant D5 : le middleware pose X-Agent-New-Token sur TOUTE
        // réponse 2xx — BinaryFileResponse compris.
        [$ws, $token] = $this->enrolledWorkstation();
        $this->putArtifact();

        $ws->refresh();
        $ws->agent_token_rotated_at = now()->subDays((int) config('agent.token_rotation_days') + 1);
        $ws->save();

        $response = $this->tool($token, self::ARTIFACT)->assertOk();

        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        $this->tool((string) $new, self::ARTIFACT)->assertOk();
    }
}
