<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.1 — AC4 (mode strict|default par règle, décision n° 2).
 *
 * Greffe la colonne `mode` sur `overlay_signals` : le toggle strict/default est
 * exposé DÈS 27.1 sur les 3 types (shortcuts + wallpaper + overlay). Le
 * `OverlayStateProvider` lit désormais le mode de SA table au lieu de la
 * constante `StateMode::Strict`.
 *
 *  - `mode` VARCHAR(16) **NULL** — null = non déclaré, défaut résolu côté
 *    provider. ⚠️ Non-régression : le défaut historique de l'overlay était
 *    `StateMode::Strict` (constante `OverlayStateProvider::mode()`) ; le
 *    provider continue de retourner `strict` quand la colonne est null.
 *    Le candidat synthétique `identity` (sans ligne en base) reste `strict`.
 *  - Varchar simple (compat SQLite tests, pas d'enum Postgres natif).
 *
 * **Idempotence stricte** : `Schema::hasColumn()`. `down()` symétrique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overlay_signals', function (Blueprint $table): void {
            if (! Schema::hasColumn('overlay_signals', 'mode')) {
                $table->string('mode', 16)->nullable()
                    ->comment('Mode d\'application du signal (Story 27.1) — strict/default ; null = non déclaré, défaut overlay = strict résolu côté provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('overlay_signals', function (Blueprint $table): void {
            if (Schema::hasColumn('overlay_signals', 'mode')) {
                $table->dropColumn('mode');
            }
        });
    }
};
