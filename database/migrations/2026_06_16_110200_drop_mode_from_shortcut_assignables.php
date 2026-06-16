<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.8 — AC1 (retrait total du mode strict/default — STRICT partout).
 *
 * Annule le déplacement opéré par 27.3 : la colonne `mode` du pivot
 * `shortcut_assignables` (ajoutée par `2026_06_16_110000_add_mode_to_shortcut_assignables`)
 * n'a plus aucun consommateur — le mécanisme `strict|default` est supprimé,
 * l'agent réapplique TOUJOURS (comportement strict inconditionnel). Droppée
 * proprement (zéro prod, mémoire `zero_prod_publish_is_test`, aucune donnée à
 * préserver, pas de back-fill).
 *
 * **Réversibilité** : `down()` RE-CRÉE `mode` VARCHAR(16) nullable (mêmes
 * attributs que 27.3 — pas de default SQL) pour qu'un rollback restaure le
 * schéma 27.3.
 *
 * **Idempotence stricte** : `Schema::hasColumn()` en garde des deux côtés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortcut_assignables', function (Blueprint $table): void {
            if (Schema::hasColumn('shortcut_assignables', 'mode')) {
                $table->dropColumn('mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shortcut_assignables', function (Blueprint $table): void {
            if (! Schema::hasColumn('shortcut_assignables', 'mode')) {
                $table->string('mode', 16)->nullable()
                    ->comment('Mode d\'application PAR ASSIGNATION (réintroduit par rollback 27.8 ; retiré par Story 27.8 — STRICT inconditionnel)');
            }
        });
    }
};
