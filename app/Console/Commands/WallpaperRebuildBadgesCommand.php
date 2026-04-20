<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Rasterise les SVG sources de `resources/assets/wallpaper-icons/sources/`
 * en PNG 48×48 dans `resources/assets/wallpaper-icons/`.
 *
 * Story 4.7 — Task 2.4 bis. Build-time, les PNG sont commités pour éviter
 * une dépendance runtime à librsvg sur la VM.
 */
class WallpaperRebuildBadgesCommand extends Command
{
    protected $signature = 'wallpaper:rebuild-badges';

    protected $description = 'Rasterise les SVG sources des badges wallpaper en PNG 48×48 (build time).';

    public function handle(): int
    {
        if (! class_exists('Imagick')) {
            $this->error('Imagick non disponible.');
            return self::FAILURE;
        }

        $sourceDir = resource_path('assets/wallpaper-icons/sources');
        $targetDir = resource_path('assets/wallpaper-icons');

        if (! is_dir($sourceDir)) {
            $this->error("Source directory introuvable : {$sourceDir}");
            return self::FAILURE;
        }

        $map = [
            'admin.svg' => 'badge-admin.png',
            'veyon.svg' => 'badge-veyon.png',
            'quota-warning.svg' => 'badge-quota-warning.png',
            'multi-session.svg' => 'badge-multi-session.png',
        ];

        $count = 0;
        foreach ($map as $source => $target) {
            $sourcePath = $sourceDir . '/' . $source;
            $targetPath = $targetDir . '/' . $target;
            if (! is_file($sourcePath)) {
                $this->warn("Source manquante : {$sourcePath}");
                continue;
            }

            try {
                $svg = (string) file_get_contents($sourcePath);
                $imagick = new \Imagick();
                $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
                $imagick->readImageBlob($svg);
                $imagick->setImageFormat('png32');
                $imagick->resizeImage(48, 48, \Imagick::FILTER_LANCZOS, 1, true);

                // Écriture atomique (cf. mémoire feedback_atomic_write.md)
                $tmp = $targetPath . '.tmp-' . bin2hex(random_bytes(4));
                $imagick->writeImage($tmp);
                $imagick->destroy();
                @chmod($tmp, 0644);
                if (! @rename($tmp, $targetPath)) {
                    @unlink($tmp);
                    $this->error("Échec rename : {$tmp} → {$targetPath}");
                    continue;
                }

                $count++;
                $this->info("✓ {$target}");
            } catch (\Throwable $e) {
                $this->error("Erreur rasterisation {$source} : " . $e->getMessage());
            }
        }

        $this->info("Terminé — {$count} badge(s) régénéré(s).");
        return $count > 0 ? self::SUCCESS : self::FAILURE;
    }
}
