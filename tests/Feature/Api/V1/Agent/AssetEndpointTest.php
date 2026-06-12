<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\WallpaperAsset;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Tests Feature `GET /api/v1/agent/assets/wallpaper/{filename}` — Story 24.4
 * (AC6).
 *
 * Route RÉELLE (`agent.v1.assets.wallpaper`) derrière la chaîne complète
 * `auth.v1.secure-headers` + `throttle:60,1` + `agent.token` (conventions
 * `StateEndpointTest`/`ReportEndpointTest`). Bibliothèque réelle sur disque
 * (répertoire temporaire pointé par `wallpapers.library_path`) : le 200 est
 * un VRAI BinaryFileResponse sur le fichier de la biblio. 404 indistinct
 * pour filename malformé / asset inconnu / fichier manquant (pas d'oracle).
 */
final class AssetEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ASSET_ROUTE = '/api/v1/agent/assets/wallpaper/';

    private TokenRotationService $service;

    private string $libraryDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
        $this->libraryDir = storage_path('framework/testing/wallpaper-' . uniqid());
        File::ensureDirectoryExists($this->libraryDir);
        config(['wallpapers.library_path' => $this->libraryDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->libraryDir);

        parent::tearDown();
    }

    private function asset(string $token, string $filename, array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer ' . $token,
        ], $headers))->get(self::ASSET_ROUTE . $filename);
    }

    /** @return array{0: Workstation, 1: string} */
    private function enrolledWorkstation(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        return [$ws->refresh(), $token];
    }

    /**
     * Asset RÉEL de la bibliothèque : fichier sur disque + ligne
     * `wallpaper_assets`, filename content-addressed (`<sha256>.<ext>` —
     * le format produit par WallpaperUploadService).
     *
     * @return array{0: WallpaperAsset, 1: string} asset + contenu binaire
     */
    private function libraryAsset(string $content = "\xFF\xD8\xFFfake-jpeg-bytes-é"): array
    {
        $checksum = hash('sha256', $content);
        $filename = $checksum . '.jpg';
        file_put_contents($this->libraryDir . '/' . $filename, $content);

        $asset = WallpaperAsset::factory()->create([
            'filename' => $filename,
            'checksum' => $checksum,
        ]);

        return [$asset, $content];
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

    // ── AC6 — 200 : contenu binaire de la biblio ─────────────────────────

    #[Test]
    public function valid_token_serves_the_exact_binary_content_of_the_library_file(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        [$asset, $content] = $this->libraryAsset();

        $logs = $this->captureAgentLogs();
        $response = $this->asset($token, $asset->filename);

        $response->assertOk();
        self::assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $served = $response->baseResponse->getFile()->getPathname();
        self::assertSame($this->libraryDir . '/' . $asset->filename, $served);
        self::assertSame($content, (string) file_get_contents($served));

        $servedLogs = $this->logsOfType($logs, 'agent.asset.served');
        self::assertCount(1, $servedLogs);
        self::assertSame('info', $servedLogs[0][0]);
        self::assertSame($ws->id, $servedLogs[0][2]['workstation_id']);
        self::assertSame($asset->filename, $servedLogs[0][2]['filename']);
    }

    #[Test]
    public function checkin_is_stamped_by_the_middleware_on_asset_calls(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        [$asset] = $this->libraryAsset();
        self::assertNull($ws->agent_last_checkin_at);

        $this->asset($token, $asset->filename)->assertOk();

        self::assertNotNull($ws->refresh()->agent_last_checkin_at);
    }

    // ── AC6 — 404 : inconnu / malformé / fichier absent, jamais de traversal ─

    #[Test]
    public function wellformed_but_unknown_filename_returns_404_with_log(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        $unknown = str_repeat('a', 64) . '.jpg';

        $logs = $this->captureAgentLogs();
        $this->asset($token, $unknown)
            ->assertStatus(404)
            ->assertJson(['error' => 'not_found']);

        $notFound = $this->logsOfType($logs, 'agent.asset.not_found');
        self::assertCount(1, $notFound);
        self::assertSame($ws->id, $notFound[0][2]['workstation_id']);
    }

    #[Test]
    public function malformed_filenames_are_rejected_404_before_any_lookup(): void
    {
        [, $token] = $this->enrolledWorkstation();
        [$asset] = $this->libraryAsset();

        $malformed = [
            'abc.jpg',                                    // pas un sha256
            str_repeat('a', 63) . '.jpg',                 // 63 hex
            strtoupper($asset->filename),                 // casse (hex minuscule strict)
            substr($asset->filename, 0, -4) . '.JPG',     // extension majuscule
            substr($asset->filename, 0, -4),              // sans extension
            str_repeat('a', 64) . '.tooolong',            // extension > 5
            '..%5C..%5Cwindows%5Cwin.ini',                // traversal encodé (backslash)
            'passwd',                                     // nom court arbitraire
        ];

        foreach ($malformed as $filename) {
            self::assertSame(
                404,
                $this->asset($token, $filename)->status(),
                "filename '$filename' aurait dû être rejeté en 404",
            );
        }
    }

    #[Test]
    public function known_asset_with_missing_file_on_disk_returns_404(): void
    {
        [, $token] = $this->enrolledWorkstation();
        [$asset] = $this->libraryAsset();
        unlink($this->libraryDir . '/' . $asset->filename);

        $this->asset($token, $asset->filename)->assertStatus(404);
    }

    #[Test]
    public function file_on_disk_without_db_row_returns_404(): void
    {
        // Le lookup DB est la source de vérité : un fichier orphelin dans la
        // biblio (résidu) n'est jamais servi.
        [, $token] = $this->enrolledWorkstation();
        $orphan = hash('sha256', 'orphan') . '.jpg';
        file_put_contents($this->libraryDir . '/' . $orphan, 'orphan-bytes');

        $this->asset($token, $orphan)->assertStatus(404);
    }

    // ── AC6 — sécurité du canal (middleware inchangé) ────────────────────

    #[Test]
    public function missing_bearer_returns_401_with_middleware_error_format(): void
    {
        [$asset] = $this->libraryAsset();

        $this->get(self::ASSET_ROUTE . $asset->filename)
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
        [$asset] = $this->libraryAsset();

        $this->asset($token, $asset->filename)
            ->assertStatus(403)
            ->assertJson(['code' => AuthenticateAgentToken::CODE_QUARANTINED]);
    }

    #[Test]
    public function due_rotation_token_survives_the_binary_200(): void
    {
        // Invariant D5 : le middleware pose X-Agent-New-Token sur TOUTE
        // réponse 2xx — y compris un BinaryFileResponse.
        [$ws, $token] = $this->enrolledWorkstation();
        [$asset] = $this->libraryAsset();

        $ws->refresh();
        $ws->agent_token_rotated_at = now()->subDays((int) config('agent.token_rotation_days') + 1);
        $ws->save();

        $response = $this->asset($token, $asset->filename)->assertOk();

        $new = $response->headers->get(AuthenticateAgentToken::HEADER_NEW_TOKEN);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $new);
        // Le nouveau token est immédiatement utilisable sur la même route.
        $this->asset((string) $new, $asset->filename)->assertOk();
    }
}
