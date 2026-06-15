<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3 — AC1 (drift policy PAR ASSIGNATION, révise la décision n° 2 de
 * 27.1).
 *
 * Greffe la colonne `mode` sur le pivot polymorphe `shortcut_assignables` : le
 * mode strict|default n'est plus une propriété de la RÈGLE (`shortcuts.mode`,
 * supprimée par la migration sœur `drop_mode_from_shortcuts`) mais du LIEN
 * (règle ↔ cible). Un même raccourci peut donc être `strict` (verrouillé) sur
 * un parc et `default` (dérive humaine tolérée) sur un autre. Lue PAR MAILLE par
 * le `ShortcutsStateProvider` (`shortcut_assignables.mode`), puis agrégée par
 * type au `StateCompiler` (posture sûre : `strict` dès qu'une assignation
 * retenue est stricte — agrégation inchangée fonctionnellement).
 *
 *  - `mode` VARCHAR(16) **NULL** — null = « non déclaré ». PAS de default SQL :
 *    le défaut `strict` (= la cible fait loi) est résolu côté provider, on
 *    distingue ainsi « assignation non configurée » d'un choix explicite et on
 *    évite tout backfill (zéro prod, mémoire `zero_prod_publish_is_test`).
 *  - Type varchar simple (pas d'enum Postgres natif) → compatible SQLite des
 *    tests sans branche driver.
 *
 * **Idempotence stricte** : `Schema::hasColumn()` avant création. `down()`
 * symétrique (drop conditionnel). Style calqué sur
 * `2026_06_15_100000_add_mode_to_shortcuts.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortcut_assignables', function (Blueprint $table): void {
            if (! Schema::hasColumn('shortcut_assignables', 'mode')) {
                $table->string('mode', 16)->nullable()
                    ->comment('Mode d\'application PAR ASSIGNATION (Story 27.3) — strict/default ; null = non déclaré, défaut strict résolu côté provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shortcut_assignables', function (Blueprint $table): void {
            if (Schema::hasColumn('shortcut_assignables', 'mode')) {
                $table->dropColumn('mode');
            }
        });
    }
};
