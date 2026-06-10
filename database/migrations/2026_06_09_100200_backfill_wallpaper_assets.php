<?php

declare(strict_types=1);

use App\Models\WallpaperAsset;
use App\Services\Wallpaper\WallpaperLibraryBackfiller;
use Illuminate\Database\Migrations\Migration;

/**
 * Migration de données : rapatrie les fichiers wallpaper legacy vers la
 * bibliothèque `storage/` (assets dédupliqués + assignations reliées).
 *
 * La logique vit dans {@see WallpaperLibraryBackfiller} (testable, review F5) ;
 * cette migration n'est qu'un appelant. No-op en environnement de test (les
 * fixtures sont construites par les tests eux-mêmes).
 */
return new class extends Migration {
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        app(WallpaperLibraryBackfiller::class)->run(
            rtrim((string) config('wallpapers.storage_path'), '/'),
            WallpaperAsset::libraryPath(),
        );
    }

    public function down(): void
    {
        // Migration de données forward-only : non réversible. Cf. note dans
        // `drop_path_from_wallpapers` — un rollback complet perd le lien
        // fichier↔assignation (pas de restauration des `path` d'origine).
    }
};
