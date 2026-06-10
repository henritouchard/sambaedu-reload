<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supprime la colonne legacy `wallpapers.path` : le chemin disque est
 * désormais porté par l'asset de bibliothèque (`asset_id` → wallpaper_assets).
 * Jouée après le backfill (`100200`) qui a recopié `path` vers les assets.
 *
 * ATTENTION — migration forward-only : `down()` recrée `path` (nullable) mais
 * NE restaure PAS les valeurs d'origine (le backfill `100200` est irréversible).
 * Un rollback complet laisse donc les assignations sans `path` ET, après
 * rollback de `100100`, sans `asset_id` → liens fichiers perdus. Ne pas
 * compter sur le rollback pour récupérer les données (review F4).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallpapers', function (Blueprint $table): void {
            $table->dropColumn('path');
        });
    }

    public function down(): void
    {
        Schema::table('wallpapers', function (Blueprint $table): void {
            // Réintroduit nullable : le backfill inverse n'est pas reconstitué.
            $table->string('path')->nullable()->after('name');
        });
    }
};
