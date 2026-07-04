<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubArtifactPullStatus;
use App\Enums\ControlHubLinkState;
use App\Models\AgentTool;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\WallpaperAsset;
use App\Services\ControlHub\ArtifactPullService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Story 39.4 — Canal ④ : téléchargement + vérification sha256 SERVEUR + matérialisation locale.
 *
 * Couverture ciblée (AC9) :
 * - sha256 OK  → WallpaperAsset / AgentTool matérialisé, fichier sur disque, pull_status=downloaded.
 * - sha256 KO  → AUCUNE écriture d'asset, fichier temporaire supprimé, pull_status=error + pull_error.
 * - Précédence locale (par checksum / par clé) → AUCUN appel HTTP (Http::assertNothingSent).
 * - Ré-pull au même checksum → no-op (aucun 2e téléchargement).
 * - Filename dérivé SERVEUR (jamais artifact.filename brut — anti-traversal).
 * - AgentTool créé DÉSACTIVÉ par défaut.
 *
 * ⚠️ Tests HÔTE (php8.4 + pdo_sqlite). Foyers de stockage redirigés vers des dossiers temporaires
 *    (jamais le storage réel) ; nettoyés en tearDown.
 */
class ArtifactPullServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $wallpaperDir;

    private string $toolsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/ch-pull-' . bin2hex(random_bytes(6));
        $this->wallpaperDir = $base . '/wallpapers';
        $this->toolsDir = $base . '/tools';
        @mkdir($this->wallpaperDir, 0o755, true);
        @mkdir($this->toolsDir, 0o755, true);
        config([
            'wallpapers.library_path' => $this->wallpaperDir,
            'agent.tools_path' => $this->toolsDir,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->wallpaperDir, $this->toolsDir] as $dir) {
            if (is_dir($dir)) {
                foreach ((array) glob($dir . '/*') as $f) {
                    if (is_string($f)) {
                        @unlink($f);
                    }
                }
                @rmdir($dir);
            }
        }
        @rmdir(dirname($this->wallpaperDir));
        parent::tearDown();
    }

    private function service(): ArtifactPullService
    {
        return new ArtifactPullService();
    }

    /**
     * PNG 1×1 valide (octets réels) — un wallpaper pullé doit être une vraie image
     * (review 39.4 #2 : getimagesize/ping en lecture seule avant matérialisation).
     */
    private function minimalPng(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }

    private function makeItem(string $type, string $key, string $checksum): ControlHubContractItem
    {
        $contract = new ControlHubContract();
        $contract->link_state = ControlHubLinkState::Active;
        $contract->received_at = now();
        $contract->schema_version = '1.0';
        $contract->save();

        return ControlHubContractItem::query()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => $type,
            'key' => $key,
            'value' => null,
            'enforcement_state' => 'locked',
            'target_type' => 'instance',
            'target_label' => '',
            'artifact_checksum' => $checksum,
            'artifact_filename' => 'declared.png',
            'artifact_size' => null,
            'pull_status' => ControlHubArtifactPullStatus::Pending->value,
        ]);
    }

    // ── sha256 OK → matérialisation ───────────────────────────────────────────

    public function test_sha256_ok_materializes_wallpaper_content_addressed(): void
    {
        $body = $this->minimalPng();
        $checksum = hash('sha256', $body);
        Http::fake(['*' => Http::response($body, 200)]);

        $item = $this->makeItem('wallpapers', 'wp_default', $checksum);

        $this->service()->pull($item->id, 'wallpapers', 'wp_default', 'https://cdn.example/w?sig=1', $checksum, 'declared.png', strlen($body));

        // Filename DÉRIVÉ SERVEUR = <checksum>.jpg (jamais declared.png), SANS re-normaliser.
        $target = $this->wallpaperDir . '/' . $checksum . '.jpg';
        $this->assertFileExists($target);
        $this->assertSame($body, file_get_contents($target), 'Le contenu vérifié ne doit PAS être re-normalisé (checksum préservé).');

        $this->assertDatabaseHas('wallpaper_assets', [
            'checksum' => $checksum,
            'filename' => $checksum . '.jpg',
            'uploaded_by' => null,
        ]);
        $item->refresh();
        $this->assertSame(ControlHubArtifactPullStatus::Downloaded, $item->pull_status);
        $this->assertNull($item->pull_error);
    }

    public function test_sha256_ok_materializes_agent_tool_disabled_by_default(): void
    {
        $body = 'PORTABLE-TOOL-ZIP-BYTES';
        $checksum = hash('sha256', $body);
        Http::fake(['*' => Http::response($body, 200)]);

        $item = $this->makeItem('agent_tools', 'myTool', $checksum);

        $this->service()->pull($item->id, 'agent_tools', 'myTool', 'https://cdn.example/t?sig=1', $checksum, 'evil/../tool.zip', strlen($body));

        $tool = AgentTool::query()->where('key', 'myTool')->first();
        $this->assertNotNull($tool);
        $this->assertSame($checksum, $tool->sha256);
        $this->assertFalse((bool) $tool->enabled, 'Un outil pullé reste DÉSACTIVÉ à la création (l\'admin active).');

        // Filename dérivé serveur : jamais le nom brut (anti-traversal) ; extension whitelistée.
        $this->assertStringContainsString('sambaedu-tool-mytool-', $tool->filename);
        $this->assertStringNotContainsString('..', $tool->filename);
        $this->assertFileExists($this->toolsDir . '/' . $tool->filename);

        $item->refresh();
        $this->assertSame(ControlHubArtifactPullStatus::Downloaded, $item->pull_status);
    }

    // ── sha256 KO → aucune matérialisation ────────────────────────────────────

    public function test_sha256_mismatch_writes_no_asset_and_flags_error(): void
    {
        $expected = hash('sha256', 'THE-GOOD-CONTENT');
        Http::fake(['*' => Http::response('A-DIFFERENT-EVIL-CONTENT', 200)]);

        $item = $this->makeItem('wallpapers', 'wp_default', $expected);

        $this->service()->pull($item->id, 'wallpapers', 'wp_default', 'https://cdn.example/w?sig=1', $expected, 'declared.png', 10);

        // Aucune écriture d'asset, aucun fichier matérialisé, aucun résidu temporaire.
        $this->assertDatabaseCount('wallpaper_assets', 0);
        $this->assertFileDoesNotExist($this->wallpaperDir . '/' . $expected . '.jpg');
        $this->assertCount(0, (array) glob($this->wallpaperDir . '/.chpull-*'), 'Le fichier temporaire doit être supprimé.');

        $item->refresh();
        $this->assertSame(ControlHubArtifactPullStatus::Error, $item->pull_status);
        $this->assertNotNull($item->pull_error);
        // NFR-A3 : l'URL signée n'apparaît jamais dans le message d'erreur persisté.
        $this->assertStringNotContainsString('sig=1', (string) $item->pull_error);
    }

    public function test_sha256_ok_but_non_image_wallpaper_is_rejected(): void
    {
        // Review 39.4 #2 — sha256 concordant mais contenu NON-image (bombe/binaire arbitraire) :
        // rejeté en lecture seule AVANT matérialisation, aucun WallpaperAsset créé, tmp supprimé.
        $body = 'NOT-AN-IMAGE-DECOMPRESSION-BOMB-PAYLOAD';
        $checksum = hash('sha256', $body);
        Http::fake(['*' => Http::response($body, 200)]);

        $item = $this->makeItem('wallpapers', 'wp_default', $checksum);
        $this->service()->pull($item->id, 'wallpapers', 'wp_default', 'https://cdn.example/w?sig=1', $checksum, 'declared.png', strlen($body));

        $this->assertDatabaseCount('wallpaper_assets', 0);
        $this->assertFileDoesNotExist($this->wallpaperDir . '/' . $checksum . '.jpg');
        $this->assertCount(0, (array) glob($this->wallpaperDir . '/.chpull-*'), 'tmp supprimé après rejet.');
        $item->refresh();
        $this->assertSame(ControlHubArtifactPullStatus::Error, $item->pull_status);
        $this->assertNotNull($item->pull_error);
    }

    public function test_http_failure_flags_error_without_asset(): void
    {
        $checksum = hash('sha256', 'whatever');
        Http::fake(['*' => Http::response('nope', 404)]);

        $item = $this->makeItem('wallpapers', 'wp_default', $checksum);
        $this->service()->pull($item->id, 'wallpapers', 'wp_default', 'https://cdn.example/w?sig=1', $checksum, null, null);

        $this->assertDatabaseCount('wallpaper_assets', 0);
        $item->refresh();
        $this->assertSame(ControlHubArtifactPullStatus::Error, $item->pull_status);
    }

    // ── Précédence locale → aucun HTTP ────────────────────────────────────────

    public function test_local_wallpaper_precedence_skips_http(): void
    {
        $checksum = str_repeat('a', 64);
        WallpaperAsset::query()->create([
            'filename' => $checksum . '.jpg',
            'checksum' => $checksum,
            'byte_size' => 5,
            'uploaded_by' => null,
        ]);
        Http::fake();

        $item = $this->makeItem('wallpapers', 'wp_default', $checksum);
        $this->service()->pull($item->id, 'wallpapers', 'wp_default', 'https://cdn.example/w?sig=1', $checksum, 'x.png', 5);

        Http::assertNothingSent();
        $this->assertDatabaseCount('wallpaper_assets', 1); // aucun doublon
        $item->refresh();
        $this->assertSame(ControlHubArtifactPullStatus::Downloaded, $item->pull_status);
    }

    public function test_uppercase_upstream_checksum_matches_lowercase_local_asset(): void
    {
        // Review 39.4 #1 — un checksum amont en MAJUSCULE doit reconnaître l'asset local
        // stocké en minuscule (hash_file) : precedence OK, AUCUN pull, AUCUN doublon.
        $lower = str_repeat('a', 60) . 'beef';
        WallpaperAsset::query()->create([
            'filename' => $lower . '.jpg', 'checksum' => $lower, 'byte_size' => 5, 'uploaded_by' => null,
        ]);
        Http::fake();

        $item = $this->makeItem('wallpapers', 'wp_default', $lower);
        // Appel avec le MÊME checksum en MAJUSCULE.
        $this->service()->pull($item->id, 'wallpapers', 'wp_default', 'https://cdn.example/w?sig=1', strtoupper($lower), 'x.png', 5);

        Http::assertNothingSent();
        $this->assertDatabaseCount('wallpaper_assets', 1);
        $item->refresh();
        $this->assertSame(ControlHubArtifactPullStatus::Downloaded, $item->pull_status);
    }

    public function test_local_agent_tool_precedence_by_key_skips_http(): void
    {
        AgentTool::query()->create([
            'key' => 'rainmeter', 'name' => 'Rainmeter', 'filename' => 'sambaedu-rainmeter-1.0.zip',
            'sha256' => str_repeat('a', 64), 'size' => 5, 'enabled' => true, 'uploaded_at' => now(), 'uploaded_by' => null,
        ]);
        Http::fake();

        $item = $this->makeItem('agent_tools', 'rainmeter', str_repeat('b', 64));
        $this->service()->pull($item->id, 'agent_tools', 'rainmeter', 'https://cdn.example/t?sig=1', str_repeat('b', 64), 'rm.zip', 5);

        Http::assertNothingSent();
        // L'outil local n'est PAS remplacé (précédence par-clé, checksum différent ignoré).
        $this->assertSame(1, AgentTool::query()->count());
        $this->assertTrue((bool) AgentTool::query()->where('key', 'rainmeter')->value('enabled'));
    }

    // ── Ré-pull au même checksum → no-op ──────────────────────────────────────

    public function test_repull_same_checksum_is_noop(): void
    {
        $body = $this->minimalPng();
        $checksum = hash('sha256', $body);
        Http::fake(['*' => Http::response($body, 200)]);

        $item = $this->makeItem('wallpapers', 'wp_default', $checksum);

        // 1er pull → matérialise (1 requête HTTP).
        $this->service()->pull($item->id, 'wallpapers', 'wp_default', 'https://cdn.example/w?sig=1', $checksum, 'x.png', strlen($body));
        // 2e pull même checksum → précédence (asset présent) → AUCUN nouveau téléchargement.
        $this->service()->pull($item->id, 'wallpapers', 'wp_default', 'https://cdn.example/w?sig=2', $checksum, 'x.png', strlen($body));

        Http::assertSentCount(1);
        $this->assertDatabaseCount('wallpaper_assets', 1);
    }
}
