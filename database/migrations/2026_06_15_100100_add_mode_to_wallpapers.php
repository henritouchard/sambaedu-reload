<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.1 — AC4 (mode strict|default par règle, décision n° 2).
 *
 * Greffe la colonne `mode` sur `wallpapers` : le toggle strict/default est
 * exposé DÈS 27.1 sur les 3 types (shortcuts + wallpaper + overlay) — on traite
 * la dette en une fois, l'UI ne ment pas. Le `WallpaperStateProvider` lit
 * désormais le mode de SA table au lieu d'un mode constant.
 *
 *  - `mode` VARCHAR(16) **NULL** — null = non déclaré, défaut résolu côté
 *    provider. ⚠️ Non-régression : le défaut historique du wallpaper était
 *    `StateMode::Default` (constante `WallpaperStateProvider::mode()`) ; le
 *    provider continue de retourner `default` quand la colonne est null (le
 *    comportement actuel est préservé tant qu'aucune règle n'est mise en
 *    `strict` via l'UI).
 *  - Varchar simple (compat SQLite tests, pas d'enum Postgres natif).
 *
 * **Idempotence stricte** : `Schema::hasColumn()`. `down()` symétrique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallpapers', function (Blueprint $table): void {
            if (! Schema::hasColumn('wallpapers', 'mode')) {
                $table->string('mode', 16)->nullable()
                    ->comment('Mode d\'application de la règle (Story 27.1) — strict/default ; null = non déclaré, défaut wallpaper = default résolu côté provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallpapers', function (Blueprint $table): void {
            if (Schema::hasColumn('wallpapers', 'mode')) {
                $table->dropColumn('mode');
            }
        });
    }
};
