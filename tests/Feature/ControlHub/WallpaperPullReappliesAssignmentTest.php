<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubArtifactPullStatus;
use App\Enums\ControlHubContractApplyStatus;
use App\Enums\ControlHubLinkState;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\ControlHub\ArtifactPullService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Le fond d'écran imposé est posé même quand son image n'était pas encore tirée.
 *
 * Cas nominal ET non couvert avant : à la PREMIÈRE réception d'une image inédite,
 * la pose (synchrone) précède toujours le tirage (asynchrone), donc le fond restait
 * `pending` sans jamais se résorber — une ré-émission identique est un no-op, donc
 * sans événement pour rejouer la pose.
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM.
 */
class WallpaperPullReappliesAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private string $wallpaperDir;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();

        $this->wallpaperDir = sys_get_temp_dir().'/ch-wp-reapply-'.bin2hex(random_bytes(6));
        @mkdir($this->wallpaperDir, 0o755, true);
        config(['wallpapers.library_path' => $this->wallpaperDir]);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->wallpaperDir.'/*') as $f) {
            if (is_string($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->wallpaperDir);
        parent::tearDown();
    }

    /** PNG 1×1 valide : le pull refuse ce qui n'est pas une image, même au bon sha256. */
    private function minimalPng(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }

    private function activeContract(): ControlHubContract
    {
        $contract = new ControlHubContract();
        $contract->link_state = ControlHubLinkState::Active;
        $contract->received_at = now();
        $contract->schema_version = '1.0';
        $contract->save();

        return $contract;
    }

    private function item(
        ControlHubContract $contract,
        string $checksum,
        string $targetType,
        string $targetLabel,
    ): ControlHubContractItem {
        return ControlHubContractItem::query()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'wallpapers',
            'key' => 'fond-impose',
            'value' => null,
            'enforcement_state' => 'locked',
            'target_type' => $targetType,
            'target_label' => $targetLabel,
            'artifact_checksum' => $checksum,
            'artifact_filename' => 'declared.png',
            'artifact_size' => null,
            'pull_status' => ControlHubArtifactPullStatus::Pending->value,
            'apply_status' => ControlHubContractApplyStatus::Pending->value,
        ]);
    }

    #[Test]
    public function pulling_the_image_after_reception_poses_the_etab_default(): void
    {
        $body = $this->minimalPng();
        $checksum = hash('sha256', $body);
        Http::fake(['*' => Http::response($body, 200)]);

        $contract = $this->activeContract();
        $item = $this->item($contract, $checksum, 'instance', '');

        self::assertSame(0, WallpaperAsset::query()->count(), 'L\'image ne doit PAS préexister : c\'est tout le scénario.');

        (new ArtifactPullService())->pull(
            $item->id,
            'wallpapers',
            'fond-impose',
            'https://cdn.example/w?sig=1',
            $checksum,
            'declared.png',
            strlen($body),
        );

        $asset = WallpaperAsset::query()->where('checksum', $checksum)->firstOrFail();

        self::assertDatabaseHas('wallpapers', [
            'owner_type' => null,
            'owner_id' => null,
            'type' => Wallpaper::TYPE_WALLPAPER,
            'asset_id' => $asset->id,
            'is_default' => true,
            'managed_by_control_hub' => true,
        ]);

        $item->refresh();
        self::assertSame(ControlHubArtifactPullStatus::Downloaded, $item->pull_status);
        self::assertSame(ControlHubContractApplyStatus::Applied, $item->apply_status);
    }

    #[Test]
    public function pulling_the_image_after_reception_poses_the_wallpaper_on_the_labeled_group(): void
    {
        $body = $this->minimalPng();
        $checksum = hash('sha256', $body);
        Http::fake(['*' => Http::response($body, 200)]);

        $contract = $this->activeContract();
        $group = WorkstationGroup::factory()->create([
            'name' => 'parc-cible',
            'controlhub_label' => 'CDIX',
        ]);
        $item = $this->item($contract, $checksum, 'label', 'CDIX');

        (new ArtifactPullService())->pull(
            $item->id,
            'wallpapers',
            'fond-impose',
            'https://cdn.example/w?sig=1',
            $checksum,
            'declared.png',
            strlen($body),
        );

        $asset = WallpaperAsset::query()->where('checksum', $checksum)->firstOrFail();

        self::assertDatabaseHas('wallpapers', [
            'owner_type' => WorkstationGroup::class,
            'owner_id' => $group->id,
            'type' => Wallpaper::TYPE_WALLPAPER,
            'asset_id' => $asset->id,
            'managed_by_control_hub' => true,
        ]);

        self::assertSame(ControlHubContractApplyStatus::Applied, $item->refresh()->apply_status);
    }
}
