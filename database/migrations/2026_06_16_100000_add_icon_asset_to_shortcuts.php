<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.7 — AC1 (icône uploadée content-addressed, sous-décision A).
 *
 * Greffe deux colonnes sur `shortcuts` pour porter l'asset content-addressed
 * de l'icône UPLOADÉE (≠ chemin d'icône réel `firefox.exe,0` qui reste dans
 * `windows_icon`). Calque `WallpaperAsset` (filename + checksum) mais INLINE
 * sur le raccourci (1 icône par raccourci, pas de dédup multi-owner ici).
 *
 *  - `icon_asset` VARCHAR(72) **NULL** — le filename content-addressed
 *    `<sha256>.ico` = 64 hex + `.ico` (68 car.) ; marge à 72. Null = pas
 *    d'asset (raccourci sans icône uploadée OU pas encore backfillé → le
 *    provider retombe sur `icon` brut, ancien comportement).
 *  - `icon_checksum` VARCHAR(64) **NULL** — SHA-256 hex du `.ico`, lu PAR le
 *    provider (zéro hash au render — invariant perf) et VÉRIFIÉ par l'agent
 *    AVANT écriture locale.
 *  - PAS de default SQL : null distingue « pas d'asset » d'une valeur vide.
 *    Pas de backfill dans la migration (commande artisan dédiée,
 *    `shortcuts:backfill-icons` — fail-soft, rollback-safe, AC5).
 *  - Type varchar simple (pas d'enum Postgres) → compatible SQLite des tests
 *    sans branche driver (project_sqlite_tests_no_varchar_enforcement).
 *  - Strings sur le modèle (pas de cast spécial), iso `mode` 27.1.
 *
 * **Idempotence stricte** : `Schema::hasColumn()` avant création. `down()`
 * symétrique (drop conditionnel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortcuts', function (Blueprint $table): void {
            if (! Schema::hasColumn('shortcuts', 'icon_asset')) {
                $table->string('icon_asset', 72)->nullable()
                    ->comment('Filename content-addressed `<sha256>.ico` de l\'icône uploadée (Story 27.7) — servi en statique par Apache ; null = pas d\'asset, provider retombe sur icon brut');
            }
            if (! Schema::hasColumn('shortcuts', 'icon_checksum')) {
                $table->string('icon_checksum', 64)->nullable()
                    ->comment('SHA-256 hex du `.ico` content-addressed (Story 27.7) — lu par le provider, vérifié par l\'agent avant écriture locale');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shortcuts', function (Blueprint $table): void {
            if (Schema::hasColumn('shortcuts', 'icon_checksum')) {
                $table->dropColumn('icon_checksum');
            }
            if (Schema::hasColumn('shortcuts', 'icon_asset')) {
                $table->dropColumn('icon_asset');
            }
        });
    }
};
