<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\AgentTool;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Story 25.6 — `GET /api/v1/agent/tools-manifest` (D8b) + `GET /api/v1/agent/overlay-skin` (D7).
 *
 * Le manifest expose l'outil ACTIF `{key, filename, sha256, size}` (le SHA-256
 * que l'agent vérifie AVANT extraction) + la skin `{filename, sha256}`. Outil
 * absent ou désactivé → `tool: null` (no-op gracieux côté agent — D4). La skin
 * est SERVIE par la route agent authentifiée token (PAS d'alias public),
 * filename FIXE (anti-traversal par construction), 404 INDISTINCT, intégrité
 * SHA-256 exposée au manifest. Chaîne `auth.v1.secure-headers` + `throttle` +
 * `agent.token` (iso `ToolEndpointTest`).
 */
final class ToolManifestSkinEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const MANIFEST_ROUTE = '/api/v1/agent/tools-manifest';

    private const SKIN_ROUTE = '/api/v1/agent/overlay-skin';

    private TokenRotationService $service;

    private string $skinDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
        $this->skinDir = storage_path('framework/testing/overlay-skin-' . uniqid());
        File::ensureDirectoryExists($this->skinDir);
        config(['agent.overlay_skin_path' => $this->skinDir . '/SambaEduOverlay.ini']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->skinDir);

        parent::tearDown();
    }

    /** @return array{0: Workstation, 1: string} */
    private function enrolledWorkstation(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        return [$ws->refresh(), $token];
    }

    private function authGet(string $route, string $token, array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge(['Authorization' => 'Bearer ' . $token], $headers))->getJson($route);
    }

    private function getSkin(string $token, array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge(['Authorization' => 'Bearer ' . $token], $headers))->get(self::SKIN_ROUTE);
    }

    private function putSkin(string $content = "[Variables]\nLabel=Salle B-12 · élève\n"): string
    {
        file_put_contents($this->skinDir . '/SambaEduOverlay.ini', $content);

        return hash('sha256', $content);
    }

    private function tool(bool $enabled, string $version = '4.5.18'): AgentTool
    {
        return AgentTool::query()->create([
            'key' => 'rainmeter',
            'name' => 'Rainmeter (overlay)',
            'filename' => "sambaedu-rainmeter-{$version}.zip",
            'sha256' => str_repeat('a', 64),
            'size' => 12345,
            'enabled' => $enabled,
        ]);
    }

    // ── AC3/AC4 — manifest : tool actif + skin ───────────────────────────

    #[Test]
    public function manifest_exposes_active_tool_and_skin_with_checksums(): void
    {
        [, $token] = $this->enrolledWorkstation();
        $this->tool(enabled: true);
        // La skin servie est TOUJOURS alignée sur la canonique versionnée
        // (le provisioner réaligne la cible servie depuis resources/...) — le
        // SHA-256 exposé au manifest est donc celui de la canonique.
        $canonical = resource_path('overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini');
        $sha = hash_file('sha256', $canonical);

        $this->authGet(self::MANIFEST_ROUTE, $token)
            ->assertOk()
            ->assertJson([
                'success' => true,
                'tool' => [
                    'key' => 'rainmeter',
                    'filename' => 'sambaedu-rainmeter-4.5.18.zip',
                    'sha256' => str_repeat('a', 64),
                    'size' => 12345,
                ],
                'skin' => [
                    'filename' => 'SambaEduOverlay.ini',
                    'sha256' => $sha,
                ],
            ]);
    }

    #[Test]
    public function disabled_tool_is_null_in_manifest_no_op_graceful(): void
    {
        [, $token] = $this->enrolledWorkstation();
        $this->tool(enabled: false);
        $this->putSkin();

        $this->authGet(self::MANIFEST_ROUTE, $token)
            ->assertOk()
            ->assertJson(['success' => true, 'tool' => null]);
    }

    #[Test]
    public function absent_tool_is_null_in_manifest(): void
    {
        [, $token] = $this->enrolledWorkstation();
        // Aucun tool en base.
        $this->authGet(self::MANIFEST_ROUTE, $token)
            ->assertOk()
            ->assertJson(['tool' => null]);
    }

    // ── AC4 — serving skin authentifié, intégrité, anti-traversal ────────

    #[Test]
    public function skin_is_served_with_exact_content_to_authenticated_agent(): void
    {
        [, $token] = $this->enrolledWorkstation();
        // Cible servie divergente : le provisioner la réaligne sur la canonique
        // (autorité). Le serving rend donc TOUJOURS le contenu canonique.
        $this->putSkin("contenu-obsolete-a-realigner");
        $canonical = resource_path('overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini');

        $response = $this->getSkin($token);

        $response->assertOk();
        self::assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $served = $response->baseResponse->getFile()->getPathname();
        // Intégrité : le SHA-256 servi = celui de la canonique = celui du manifest.
        self::assertSame(hash_file('sha256', $canonical), hash_file('sha256', $served));
    }

    #[Test]
    public function unprovisioned_skin_falls_back_to_canonical_resource(): void
    {
        [, $token] = $this->enrolledWorkstation();
        // Cible servie absente : le provisioner aligne depuis la canonique
        // versionnée (resources/overlay/...) — le serving converge (jamais un
        // 404 bloquant en dev/test où storage n'est pas encore provisionné).
        config(['agent.overlay_skin_path' => $this->skinDir . '/freshly-provisioned.ini']);

        $response = $this->getSkin($token)->assertOk();
        self::assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        // Le fichier servi porte le contenu de la canonique (copie idempotente).
        $canonical = resource_path('overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini');
        self::assertSame(hash_file('sha256', $canonical), hash_file('sha256', $response->baseResponse->getFile()->getPathname()));
    }

    // ── sécurité du canal (middleware inchangé) ──────────────────────────

    #[Test]
    public function manifest_requires_bearer_token(): void
    {
        $this->getJson(self::MANIFEST_ROUTE)
            ->assertStatus(401)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_TOKEN_MISSING]);
    }

    #[Test]
    public function skin_requires_bearer_token(): void
    {
        $this->putSkin();

        $this->get(self::SKIN_ROUTE)
            ->assertStatus(401)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_TOKEN_MISSING]);
    }

    #[Test]
    public function quarantined_workstation_gets_403_on_skin(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);
        $this->service->quarantine($ws->refresh(), 'test');
        $this->putSkin();

        $this->getSkin($token)
            ->assertStatus(403)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_QUARANTINED]);
    }

    #[Test]
    public function checkin_is_stamped_on_manifest_calls(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        self::assertNull($ws->agent_last_checkin_at);

        $this->authGet(self::MANIFEST_ROUTE, $token)->assertOk();

        self::assertNotNull($ws->refresh()->agent_last_checkin_at);
    }
}
