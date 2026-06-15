<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.1 — AC4 (mode strict|default par règle, décision n° 2).
 *
 * Greffe la colonne `mode` sur `shortcuts` : une règle de raccourci déclare si
 * sa dérive humaine est réappliquée (`strict`, la cible fait loi) ou tolérée
 * (`default`, un raccourci supprimé par un prof n'est pas recréé). Lue par le
 * `ShortcutsStateProvider` (portée par candidat) puis agrégée par type au
 * `StateCompiler`.
 *
 *  - `mode` VARCHAR(16) **NULL** — null = « non déclaré ». PAS de default SQL :
 *    le défaut `strict` (= comportement actuel : la cible fait loi) est résolu
 *    côté provider, on distingue ainsi « règle non encore configurée » d'un
 *    choix explicite et on évite un backfill (iso décision D2 de la migration
 *    `add_environment_to_workstation_groups`, Story 26.1).
 *  - Type varchar simple (pas d'enum Postgres natif) → compatible SQLite des
 *    tests sans branche driver.
 *  - Cast enum `App\Enums\StateMode` ajouté sur le modèle `Shortcut`.
 *
 * **Idempotence stricte** : `Schema::hasColumn()` avant création. `down()`
 * symétrique (drop conditionnel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortcuts', function (Blueprint $table): void {
            if (! Schema::hasColumn('shortcuts', 'mode')) {
                $table->string('mode', 16)->nullable()
                    ->comment('Mode d\'application de la règle (Story 27.1) — strict/default ; null = non déclaré, défaut strict résolu côté provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shortcuts', function (Blueprint $table): void {
            if (Schema::hasColumn('shortcuts', 'mode')) {
                $table->dropColumn('mode');
            }
        });
    }
};
