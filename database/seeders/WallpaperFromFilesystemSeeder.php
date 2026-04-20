<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WorkstationGroup;
use Illuminate\Database\Seeder;

/**
 * Importe les fichiers wallpaper/lockscreen pré-existants du filesystem legacy
 * `/etc/sambaedu/applications/wallpaper/` en DB.
 *
 * Story 4.7 — AC 6. Idempotent (run-safe : `updateOrCreate` sur `path`).
 *
 * Conventions de nom :
 *   - `default.jpg` / `wallpaper.jpg` / `lockscreen.jpg` → défauts étab (owner=NULL, is_default=true)
 *   - `wallpaper@<key>.jpg` / `lockscreen@<key>.jpg` → lookup (User, UserGroup, WorkstationGroup)
 *   - `logo.png` → non importé (reste fichier disque pour lockscreen compose)
 *
 * Les fichiers ne sont **pas déplacés** — le path stocké pointe vers
 * l'emplacement legacy (rollback-safe).
 */
class WallpaperFromFilesystemSeeder extends Seeder
{
    public function run(): void
    {
        $dir = (string) config('wallpapers.storage_path', '/etc/sambaedu/applications/wallpaper');

        if (! is_dir($dir)) {
            $this->command?->warn("Dossier {$dir} absent — rien à seeder.");
            return;
        }

        $imported = 0;
        $skipped = 0;
        $orphans = 0;
        $processed = 0;

        $files = glob($dir . '/*.{jpg,jpeg,png}', GLOB_BRACE) ?: [];
        foreach ($files as $path) {
            $processed++;
            $filename = basename($path);

            if ($filename === 'logo.png') {
                continue;
            }

            // `default.jpg`/`default.png` sont le fallback système géré hors DB
            // (fallbackDefaultSystem du Resolver sur `/usr/share/.../default.jpg`).
            // Les importer créerait un conflit avec `wallpaper.jpg` sur l'index
            // partiel `wallpapers_default_per_type`. Skip défensif (post-review #3).
            if ($filename === 'default.jpg' || $filename === 'default.png') {
                $skipped++;
                continue;
            }

            if (! preg_match('/^(wallpaper|lockscreen)(@(.+))?\.(jpg|jpeg|png)$/i', $filename, $matches)) {
                continue;
            }

            $type = strtolower($matches[1]);
            $key = $matches[3] ?? '';

            if ($key === '') {
                // `wallpaper.jpg` / `lockscreen.jpg` = défaut étab
                $row = Wallpaper::updateOrCreate(
                    ['path' => $path],
                    [
                        'name' => $type,
                        'type' => $type,
                        'owner_type' => null,
                        'owner_id' => null,
                        'is_default' => true,
                    ],
                );
                $row->wasRecentlyCreated ? $imported++ : $skipped++;
                continue;
            }

            // Lookup owner : User → UserGroup → WorkstationGroup (premier match)
            $owner = $this->lookupOwner($key);

            if ($owner === null) {
                // Post-review #D : on N'IMPORTE PAS les orphans en DB. Raison :
                //   - 2+ orphans entrent en conflit sur la contrainte unique
                //     `(type, owner_type=NULL, owner_id=NULL)` (un seul row
                //     `owner` NULL possible).
                //   - Le Resolver retombera sur fallbackFs() si le key matche
                //     ultérieurement un new owner en DB.
                // Le fichier reste sur disque, aucune perte — juste non tracé en DB.
                $orphans++;
                $this->command?->warn(
                    "Orphan ignoré (non importé en DB) : {$filename} — aucun owner pour '{$key}'"
                );
                \Illuminate\Support\Facades\Log::warning('[WallpaperSeeder] orphan skipped', [
                    'file' => $filename,
                    'key' => $key,
                ]);
                continue;
            }

            $row = Wallpaper::updateOrCreate(
                ['path' => $path],
                [
                    'name' => $key,
                    'type' => $type,
                    'owner_type' => $owner::class,
                    'owner_id' => $owner->getKey(),
                    'is_default' => false,
                ],
            );
            $row->wasRecentlyCreated ? $imported++ : $skipped++;
        }

        $this->command?->info(sprintf(
            'Wallpaper seed — %d scannés, %d importés, %d skippés, %d orphans',
            $processed,
            $imported,
            $skipped,
            $orphans,
        ));
    }

    /**
     * Lookup par nom dans l'ordre User(login) → UserGroup(name) → WorkstationGroup(name).
     */
    private function lookupOwner(string $key): User|UserGroup|WorkstationGroup|null
    {
        $user = User::query()->where('login', $key)->first();
        if ($user !== null) {
            return $user;
        }

        $userGroup = UserGroup::query()->where('name', $key)->first();
        if ($userGroup !== null) {
            return $userGroup;
        }

        $workstationGroup = WorkstationGroup::query()->where('name', $key)->first();
        if ($workstationGroup !== null) {
            return $workstationGroup;
        }

        return null;
    }
}
