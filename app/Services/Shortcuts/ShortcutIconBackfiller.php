<?php

declare(strict_types=1);

namespace App\Services\Shortcuts;

use App\Models\Shortcut;
use Illuminate\Support\Facades\Log;

/**
 * Backfill des icônes UPLOADÉES existantes name-addressed → content-addressed
 * (Story 27.7, AC5). Calque `WallpaperLibraryBackfiller` : extrait de la
 * commande pour être testable, copie (jamais déplace), idempotent (dédup
 * checksum + re-run no-op), fail-soft.
 *
 * Les icônes legacy vivent name-addressed dans
 * `config('shortcut_icons.legacy_path')/<name>.ico` (= `ShortcutsService::$iconsPath`).
 * Pour chaque `<name>.ico`, on résout le(s) raccourci(s) DB dont l'icône
 * uploadée (nom nu : `windows_icon` OU `icon_path`) == `<name>`, on content-
 * adresse le `.ico` (via {@see ShortcutIconAssetService}, le MÊME que l'upload)
 * et on persiste `icon_asset`/`icon_checksum`.
 *
 * Les fichiers legacy sont COPIÉS, JAMAIS supprimés (rollback-safe). Un
 * raccourci dont le `.ico` legacy est absent → compté `missing`, jamais un
 * échec.
 *
 * @phpstan-type BackfillStats array{assets:int, linked:int, missing:int}
 */
class ShortcutIconBackfiller
{
    public function __construct(
        private readonly ShortcutIconAssetService $iconAssetService = new ShortcutIconAssetService(),
    ) {}

    /**
     * @return BackfillStats
     */
    public function run(?string $legacyDir = null): array
    {
        $legacyDir = rtrim($legacyDir ?? (string) config('shortcut_icons.legacy_path', '/etc/sambaedu/applications/shortcuts'), '/');
        $stats = ['assets' => 0, 'linked' => 0, 'missing' => 0];

        // On parcourt les raccourcis dont l'icône uploadée est un NOM NU (la
        // même détection que le provider : aucun séparateur de chemin/index).
        // On ne touche JAMAIS un raccourci à icône réelle (`firefox.exe,0`).
        $seenChecksums = [];

        Shortcut::query()
            ->where(function ($q): void {
                $q->whereNotNull('windows_icon')->orWhereNotNull('icon_path');
            })
            ->orderBy('id')
            ->chunkById(200, function ($shortcuts) use ($legacyDir, &$stats, &$seenChecksums): void {
                foreach ($shortcuts as $shortcut) {
                    $bareName = $this->bareIconName($shortcut);
                    if ($bareName === null) {
                        continue; // chemin réel ou pas d'icône : hors backfill
                    }

                    $source = $legacyDir . '/' . $bareName . '.ico';
                    $asset = $this->iconAssetService->contentAddress($source);
                    if ($asset === null) {
                        $stats['missing']++;
                        Log::info('[ShortcutIconBackfiller] .ico legacy absent', [
                            'shortcut_id' => $shortcut->id,
                            'name' => $bareName,
                            'source' => $source,
                        ]);
                        continue;
                    }

                    if (! isset($seenChecksums[$asset['checksum']])) {
                        $seenChecksums[$asset['checksum']] = true;
                        $stats['assets']++;
                    }

                    // Idempotent : ne réécrit que si la valeur change (re-run no-op).
                    if ($shortcut->icon_asset !== $asset['asset']
                        || $shortcut->icon_checksum !== $asset['checksum']
                    ) {
                        $shortcut->forceFill([
                            'icon_asset' => $asset['asset'],
                            'icon_checksum' => $asset['checksum'],
                        ])->save();
                    }
                    $stats['linked']++;
                }
            });

        return $stats;
    }

    /**
     * Nom nu de l'icône uploadée (≠ chemin réel) — regex iso-legacy/provider
     * `!preg_match('#[\\/.,%]#', $icon)`. null si chemin réel ou vide.
     */
    private function bareIconName(Shortcut $shortcut): ?string
    {
        $icon = (string) ($shortcut->windows_icon ?? $shortcut->icon_path ?? '');
        if ($icon === '' || preg_match('#[\\\\/.,%]#', $icon)) {
            return null;
        }

        return $icon;
    }
}
