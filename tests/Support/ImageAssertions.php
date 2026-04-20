<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Helpers d'assertions sur les blobs image (Imagick).
 *
 * Story 4.7 — tests structurels, pas de comparaison binaire (Imagick varie
 * entre versions). Vérifie format, dimensions, dominance de couleur par zone.
 */
trait ImageAssertions
{
    protected function assertImageBlobValid(string $blob, int $minBytes = 5_000): void
    {
        $this->assertGreaterThan($minBytes, strlen($blob), 'Image blob too small');
        $info = @getimagesizefromstring($blob);
        $this->assertNotFalse($info, 'Blob non reconnu comme image par getimagesizefromstring');
    }

    protected function assertImageDimensions(string $blob, int $width, int $height): void
    {
        $info = @getimagesizefromstring($blob);
        $this->assertNotFalse($info);
        $this->assertSame($width, $info[0], "Largeur attendue {$width}");
        $this->assertSame($height, $info[1], "Hauteur attendue {$height}");
    }

    protected function assertImageFormat(string $blob, string $expected): void
    {
        $info = @getimagesizefromstring($blob);
        $this->assertNotFalse($info);
        $mime = $info['mime'] ?? '';
        $this->assertSame("image/{$expected}", $mime);
    }

    /**
     * Vérifie qu'une couleur hexa représente au moins `$minPixelRatio` de
     * l'image totale (0.001 = 0.1%).
     */
    protected function assertImageContainsColor(
        string $blob,
        string $hex,
        float $minPixelRatio = 0.001,
        int $tolerance = 30,
    ): void {
        $this->assertTrue(
            $this->colorRatio($blob, $hex, $tolerance) >= $minPixelRatio,
            sprintf('Couleur %s absente (<%.3f%% des pixels, tol %d)', $hex, $minPixelRatio * 100, $tolerance),
        );
    }

    protected function assertDominantColor(
        string $blob,
        string $hex,
        float $minPixelRatio = 0.5,
        int $tolerance = 40,
    ): void {
        $ratio = $this->colorRatio($blob, $hex, $tolerance);
        $this->assertTrue(
            $ratio >= $minPixelRatio,
            sprintf('Couleur %s non dominante (%.1f%% < %.1f%%)', $hex, $ratio * 100, $minPixelRatio * 100),
        );
    }

    /**
     * Vérifie qu'une couleur est absente (ou très rare) de l'image.
     * Complément symétrique de `assertImageContainsColor`.
     */
    protected function assertImageDoesNotContainColor(
        string $blob,
        string $hex,
        float $maxPixelRatio = 0.0005,
        int $tolerance = 30,
    ): void {
        $ratio = $this->colorRatio($blob, $hex, $tolerance);
        $this->assertLessThan(
            $maxPixelRatio,
            $ratio,
            sprintf('Couleur %s présente (%.3f%% >= %.3f%%, tol %d) alors qu\'elle ne devrait pas',
                $hex, $ratio * 100, $maxPixelRatio * 100, $tolerance),
        );
    }

    /**
     * Charge le blob et compte la proportion de pixels proches de $hex.
     */
    private function colorRatio(string $blob, string $hex, int $tolerance): float
    {
        $img = imagecreatefromstring($blob);
        if ($img === false) {
            return 0.0;
        }
        $target = $this->hexToRgb($hex);
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) floor(min($w, $h) / 160)); // sample grid
        $hits = 0;
        $total = 0;
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $total++;
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if (
                    abs($r - $target[0]) <= $tolerance
                    && abs($g - $target[1]) <= $tolerance
                    && abs($b - $target[2]) <= $tolerance
                ) {
                    $hits++;
                }
            }
        }
        imagedestroy($img);
        return $total > 0 ? $hits / $total : 0.0;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
