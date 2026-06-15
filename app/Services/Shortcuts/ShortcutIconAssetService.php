<?php

declare(strict_types=1);

namespace App\Services\Shortcuts;

use Illuminate\Support\Facades\Log;

/**
 * Content-adressage des icônes UPLOADÉES de raccourcis (Story 27.7, AC1).
 *
 * Un `.ico` déjà produit par `ImageManagerService` (name-addressed
 * `<name>.ico` dans `$iconsPath` — PRÉSERVÉ pour l'UI/legacy) est ÉGALEMENT
 * copié, content-addressed, dans le dossier servi par Apache
 * (`config('shortcut_icons.served_path')`). Calque le pattern
 * `WallpaperAsset`/`WallpaperLibraryBackfiller` (dédup par checksum, copie
 * jamais déplacement) mais INLINE sur le raccourci (1 icône/raccourci).
 *
 * Le checksum est calculé ICI (à l'upload / au backfill) et persisté en base
 * sur `shortcuts.icon_asset`/`icon_checksum` : le provider ne fait qu'une
 * lecture de colonne (zéro hash au render — invariant perf, piège n° 2).
 *
 * GARDE-FOU SÉCURITÉ (piège n° 1, n° 3) : `<sha>.ico` ne sort JAMAIS du
 * `served_path` — le filename est entièrement dérivé du contenu (hash hex),
 * jamais du nom utilisateur (aucun `..`/séparateur possible).
 */
class ShortcutIconAssetService
{
    /** Répertoire servi par Apache (content-addressed). */
    public function servedDir(): string
    {
        return rtrim((string) config('shortcut_icons.served_path', storage_path('app/shortcut-icons')), '/');
    }

    /**
     * Content-adresse un fichier `.ico` source vers le dossier servi.
     *
     * Retourne `['asset' => '<sha>.ico', 'checksum' => '<sha>']` en cas de
     * succès, ou `null` si la source est absente/illisible (fail-soft : le
     * legacy name-addressed reste, l'UI n'est pas cassée). Idempotent : un
     * `<sha>.ico` déjà présent n'est pas réécrit.
     *
     * @return array{asset:string, checksum:string}|null
     */
    public function contentAddress(string $sourceIcoPath): ?array
    {
        if (! is_file($sourceIcoPath)) {
            return null;
        }

        $checksum = @hash_file('sha256', $sourceIcoPath);
        if ($checksum === false || $checksum === '') {
            Log::warning('[ShortcutIconAssetService] checksum illisible', ['source' => $sourceIcoPath]);
            return null;
        }

        $filename = $checksum . '.ico';
        $dir = $this->servedDir();

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            Log::warning('[ShortcutIconAssetService] dossier servi non créable', ['dir' => $dir]);
            return null;
        }

        $target = $dir . '/' . $filename;
        if (! is_file($target)) {
            if (! @copy($sourceIcoPath, $target)) {
                Log::warning('[ShortcutIconAssetService] copie échouée', [
                    'from' => $sourceIcoPath,
                    'to' => $target,
                ]);
                return null;
            }
            // Lisible par Apache (chown www-admin à appliquer sur le dossier
            // côté provisioning). 0644 : world-read, blob public-safe.
            @chmod($target, 0644);
        }

        return ['asset' => $filename, 'checksum' => $checksum];
    }
}
