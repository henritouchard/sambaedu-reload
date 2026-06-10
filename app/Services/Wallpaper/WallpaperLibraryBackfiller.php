<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Models\User;
use App\Models\UserGroup;
use App\Models\WallpaperAsset;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rapatrie les fichiers wallpaper legacy (dossier `/etc/...`) vers la
 * bibliothèque `storage/`, crée les assets dédupliqués (checksum) et relie
 * les assignations.
 *
 * Extrait de la migration `backfill_wallpaper_assets` pour être testable
 * (review F5) : la migration n'est qu'un appelant fin.
 *
 *  - Pass A : chaque ligne `wallpapers` existante (qui porte encore `path`)
 *    → import du fichier en asset + `asset_id` renseigné.
 *  - Pass B : fichiers `<type>@<key>.jpg` orphelins sur disque → import en
 *    asset ; si la clé résout un owner, création de l'assignation manquante ;
 *    sinon asset non assigné (assignable via l'UI).
 *
 * Les fichiers legacy sont COPIÉS (jamais supprimés) — rollback-safe.
 * Idempotent (dédup checksum + `insertOrIgnore`).
 *
 * @phpstan-type BackfillStats array{assets:int, linked:int, orphans:int, missing:int}
 */
class WallpaperLibraryBackfiller
{
    /**
     * @return BackfillStats
     */
    public function run(string $legacyDir, string $libraryDir): array
    {
        $stats = ['assets' => 0, 'linked' => 0, 'orphans' => 0, 'missing' => 0];

        if (! is_dir($libraryDir) && ! @mkdir($libraryDir, 0755, true) && ! is_dir($libraryDir)) {
            Log::warning('[WallpaperLibraryBackfiller] library dir non créable', ['dir' => $libraryDir]);
            return $stats;
        }

        // ---- Pass A : lignes wallpapers existantes (colonne `path` encore là) ----
        $importedPaths = [];
        foreach (DB::table('wallpapers')->get() as $row) {
            $path = (string) ($row->path ?? '');
            if ($path === '') {
                continue;
            }
            $asset = $this->importFile($path, $libraryDir, $stats);
            if ($asset !== null) {
                DB::table('wallpapers')->where('id', $row->id)->update(['asset_id' => $asset->id]);
                $importedPaths[$path] = true;
                $stats['linked']++;
            } else {
                $stats['missing']++;
                Log::warning('[WallpaperLibraryBackfiller] fichier source absent', [
                    'wallpaper_id' => $row->id,
                    'path' => $path,
                ]);
            }
        }

        // ---- Pass B : fichiers orphelins sur disque (sans ligne DB) ----
        if (! is_dir($legacyDir)) {
            return $stats;
        }

        $files = glob($legacyDir . '/*.{jpg,jpeg,png}', GLOB_BRACE) ?: [];
        foreach ($files as $sourcePath) {
            if (isset($importedPaths[$sourcePath])) {
                continue; // déjà relié en Pass A
            }
            $filename = basename($sourcePath);
            if ($filename === 'logo.png' || $filename === 'default.jpg' || $filename === 'default.png') {
                continue; // branding / fallback système — hors bibliothèque
            }
            if (! preg_match('/^(wallpaper|lockscreen)(@(.+))?\.(jpg|jpeg|png)$/i', $filename, $m)) {
                continue;
            }

            $type = strtolower($m[1]);
            $key = $m[3] ?? '';

            $asset = $this->importFile($sourcePath, $libraryDir, $stats);
            if ($asset === null) {
                continue;
            }

            if ($key === '') {
                $exists = DB::table('wallpapers')
                    ->where('type', $type)->whereNull('owner_id')->where('is_default', true)
                    ->exists();
                if (! $exists) {
                    $this->insertAssignment($type, null, null, true, $asset->id, $type, $sourcePath);
                    $stats['linked']++;
                }
                continue;
            }

            $owner = $this->lookupOwner($key);
            if ($owner === null) {
                $stats['orphans']++;
                Log::info('[WallpaperLibraryBackfiller] orphelin en bibliothèque (non assigné)', [
                    'file' => $filename,
                    'key' => $key,
                    'asset_id' => $asset->id,
                ]);
                continue;
            }

            $exists = DB::table('wallpapers')
                ->where('type', $type)
                ->where('owner_type', $owner::class)
                ->where('owner_id', $owner->getKey())
                ->exists();
            if (! $exists) {
                $this->insertAssignment($type, $owner::class, (int) $owner->getKey(), false, $asset->id, $key, $sourcePath);
                $stats['linked']++;
            }
        }

        return $stats;
    }

    /**
     * Importe un fichier disque en asset (dédup par checksum). Copie le
     * fichier dans la bibliothèque sous `<checksum>.<ext>` si absent.
     *
     * @param  BackfillStats  $stats
     */
    private function importFile(string $sourcePath, string $libraryDir, array &$stats): ?WallpaperAsset
    {
        if (! is_file($sourcePath)) {
            return null;
        }
        $checksum = hash_file('sha256', $sourcePath);
        if ($checksum === false) {
            return null;
        }
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'jpg';
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        $filename = $checksum . '.' . $ext;
        $target = $libraryDir . '/' . $filename;

        if (! is_file($target)) {
            if (! @copy($sourcePath, $target)) {
                Log::warning('[WallpaperLibraryBackfiller] copie échouée', [
                    'from' => $sourcePath,
                    'to' => $target,
                ]);
                return null;
            }
            @chmod($target, 0644);
        }

        $size = filesize($sourcePath);

        $asset = WallpaperAsset::firstOrCreate(
            ['checksum' => $checksum],
            [
                'filename' => $filename,
                'original_name' => basename($sourcePath),
                'byte_size' => $size !== false ? $size : null,
            ],
        );

        if ($asset->wasRecentlyCreated) {
            $stats['assets']++;
        }

        return $asset;
    }

    /**
     * Insère une assignation. `path` (colonne legacy, supprimée par la
     * migration suivante) est rempli le temps de la migration. `insertOrIgnore`
     * évite qu'une collision unique avorte la migration (review F3).
     */
    private function insertAssignment(
        string $type,
        ?string $ownerType,
        ?int $ownerId,
        bool $isDefault,
        int $assetId,
        string $name,
        string $legacyPath,
    ): void {
        DB::table('wallpapers')->insertOrIgnore([
            'name' => $name,
            'path' => $legacyPath,
            'asset_id' => $assetId,
            'type' => $type,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'is_default' => $isDefault,
            'uploaded_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lookupOwner(string $key): User|UserGroup|WorkstationGroup|null
    {
        return User::query()->where('login', $key)->first()
            ?? UserGroup::query()->where('name', $key)->first()
            ?? WorkstationGroup::query()->where('name', $key)->first();
    }
}
