<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute `asset_id` (FK bibliothèque) à `wallpapers`. Nullable de façon
 * transitoire : le backfill (migration suivante) le renseigne, puis la
 * colonne legacy `path` est supprimée. Reste nullable après coup pour
 * tolérer une assignation dont l'asset aurait disparu (le Resolver ignore
 * alors la ligne plutôt que de planter).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallpapers', function (Blueprint $table): void {
            $table->foreignId('asset_id')
                ->nullable()
                ->after('id')
                ->constrained('wallpaper_assets')
                ->nullOnDelete();

            // Rend `path` nullable dès maintenant : le nouveau code applicatif
            // (upsertAssignment) n'écrit plus `path`. Si le code synced est
            // déployé avant que `drop_path` (100300) ne tourne, un upload dans
            // la fenêtre insérerait sans `path` → violation NOT NULL (review F6).
            // Nullable ferme cette fenêtre.
            $table->string('path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wallpapers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('asset_id');
        });
    }
};
