<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Dto\Wallpaper\WallpaperContext;
use App\Dto\Wallpaper\WallpaperResolution;
use App\Services\QuotaService;
use App\Services\Wallpaper\WallpaperComposer;
use Illuminate\Console\Command;
use Mockery;

/**
 * Génère une galerie de prévisualisation (9 états AC 5 ter) pour review visuelle.
 *
 * Story 4.7 — Task 5.5 bis.
 *
 * Output : `storage/app/wallpaper-previews/preview-<state>.png`
 *
 * Usage :
 *   php artisan wallpaper:preview [--base=PATH]
 */
class WallpaperPreviewCommand extends Command
{
    protected $signature = 'wallpaper:preview {--base= : Chemin d\'une image de fond personnalisée (JPG 1920×1080)}';

    protected $description = 'Génère les 9 états de la matrice AC 5 ter sous forme de PNG.';

    public function handle(): int
    {
        if (! class_exists('Imagick')) {
            $this->error('Imagick indisponible.');
            return self::FAILURE;
        }

        $outputDir = storage_path('app/wallpaper-previews');
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
            $this->error("Impossible de créer {$outputDir}");
            return self::FAILURE;
        }

        $basePath = (string) ($this->option('base') ?: '');
        if ($basePath === '' || ! is_file($basePath)) {
            $basePath = $outputDir . '/_base.jpg';
            $im = new \Imagick();
            $im->newImage(1920, 1080, new \ImagickPixel('#445569'));
            $im->setImageFormat('jpg');
            $im->writeImage($basePath);
            $im->destroy();
        }

        $resolution = new WallpaperResolution(
            sourcePath: $basePath,
            level: WallpaperResolution::LEVEL_SALLE,
            ownerType: null,
            ownerName: 'salle_demo',
        );

        $quotaBlock = Mockery::mock(QuotaService::class);
        $quotaBlock->shouldReceive('getOverQuotaPartitionsFormatted')->andReturn([
            ['label' => 'Espace perso', 'used_mb' => 1024, 'soft_mb' => 500, 'grace_days' => 3],
            ['label' => 'Espace Classe', 'used_mb' => 5120, 'soft_mb' => 4000, 'grace_days' => 7],
        ]);
        $quotaSoftOnly = Mockery::mock(QuotaService::class);
        $quotaSoftOnly->shouldReceive('isUserOverQuota')->andReturn(false);
        $quotaSoftOnly->shouldReceive('getDiskUsage')->andReturn([
            'home' => ['is_over_soft' => true, 'is_over_hard' => false],
            'sambaedu' => ['is_over_soft' => false, 'is_over_hard' => false],
        ]);

        $composer = new WallpaperComposer();
        $composerSoftQuota = new WallpaperComposer($quotaSoftOnly);
        $composerHardQuota = new WallpaperComposer($quotaBlock);

        $ctxBase = [
            'user' => 'jdoe',
            'fullname' => 'John Doe',
            'admin' => false,
            'machine' => 'post01',
            'salle' => 'salle_demo',
            'list_u' => ['Eleves'],
            'os' => 'linux',
            'time' => time(),
        ];

        $states = [
            'normal' => ['ctx' => $ctxBase, 'wait' => false, 'veyon' => false, 'composer' => $composer, 'res' => $resolution],
            'admin' => ['ctx' => array_merge($ctxBase, ['admin' => true]), 'wait' => false, 'veyon' => false, 'composer' => $composer, 'res' => $resolution],
            'veyon' => ['ctx' => $ctxBase, 'wait' => false, 'veyon' => true, 'composer' => $composer, 'res' => $resolution],
            'admin-veyon' => ['ctx' => array_merge($ctxBase, ['admin' => true]), 'wait' => false, 'veyon' => true, 'composer' => $composer, 'res' => $resolution],
            'quota-warning' => ['ctx' => $ctxBase, 'wait' => false, 'veyon' => false, 'composer' => $composerSoftQuota, 'res' => $resolution],
            'quota-blocked' => ['ctx' => $ctxBase, 'wait' => false, 'veyon' => false, 'composer' => $composerHardQuota, 'res' => WallpaperResolution::quotaOverride()],
            'wait' => ['ctx' => $ctxBase, 'wait' => true, 'veyon' => false, 'composer' => $composer, 'res' => $resolution],
        ];

        foreach ($states as $name => $state) {
            $ctx = WallpaperContext::fromApcuArray($state['ctx']);
            /** @var WallpaperComposer $cmp */
            $cmp = $state['composer'];
            try {
                $blob = $cmp->composeWallpaper($state['res'], $ctx, $state['wait'], $state['veyon'], 'png');
                $path = $outputDir . '/preview-' . $name . '.png';
                file_put_contents($path, $blob);
                $this->info("✓ {$name} → {$path}");
            } catch (\Throwable $e) {
                $this->error("✗ {$name} : " . $e->getMessage());
            }
        }

        // Bonus lockscreen
        try {
            $ctxLock = WallpaperContext::fromApcuArray(array_merge($ctxBase, ['user' => '', 'fullname' => '']));
            $blob = $composer->composeLockscreen($resolution, $ctxLock, 'png');
            file_put_contents($outputDir . '/preview-lockscreen.png', $blob);
            $this->info('✓ lockscreen');
        } catch (\Throwable $e) {
            $this->error('✗ lockscreen : ' . $e->getMessage());
        }

        $this->info("Previews → {$outputDir}");
        Mockery::close();
        return self::SUCCESS;
    }
}
