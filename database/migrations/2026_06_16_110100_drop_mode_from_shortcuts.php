<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3 — AC1 (drift policy PAR ASSIGNATION).
 *
 * Symétrie de `2026_06_15_100000_add_mode_to_shortcuts.php` : le mode quitte la
 * RÈGLE. Avec le déplacement vers `shortcut_assignables.mode` (migration sœur
 * `add_mode_to_shortcut_assignables`), la colonne `shortcuts.mode` posée par
 * 27.1 n'a plus de consommateur — droppée proprement (zéro prod, mémoire
 * `zero_prod_publish_is_test`, aucune donnée à préserver).
 *
 * **Réversibilité** : `down()` RE-CRÉE `mode` VARCHAR(16) nullable sur
 * `shortcuts` (mêmes attributs que 27.1 — pas de default SQL, comment d'origine)
 * pour qu'un rollback restaure exactement le schéma 27.1.
 *
 * **Idempotence stricte** : `Schema::hasColumn()` en garde des deux côtés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortcuts', function (Blueprint $table): void {
            if (Schema::hasColumn('shortcuts', 'mode')) {
                $table->dropColumn('mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shortcuts', function (Blueprint $table): void {
            if (! Schema::hasColumn('shortcuts', 'mode')) {
                $table->string('mode', 16)->nullable()
                    ->comment('Mode d\'application de la règle (Story 27.1) — strict/default ; null = non déclaré, défaut strict résolu côté provider');
            }
        });
    }
};
