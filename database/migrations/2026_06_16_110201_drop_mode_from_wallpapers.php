<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.8 — AC1 (retrait total du mode strict/default — STRICT partout).
 *
 * Annule l'ajout opéré par 27.1 : la colonne `mode` sur `wallpapers` (ajoutée
 * par `2026_06_15_100100_add_mode_to_wallpapers`) n'a plus aucun consommateur —
 * le mécanisme `strict|default` est supprimé, le fond cible est TOUJOURS
 * réimposé (comportement strict inconditionnel). Droppée proprement (zéro prod,
 * mémoire `zero_prod_publish_is_test`, aucune donnée à préserver, pas de
 * back-fill).
 *
 * **Réversibilité** : `down()` RE-CRÉE `mode` VARCHAR(16) nullable (mêmes
 * attributs que 27.1 — pas de default SQL).
 *
 * **Idempotence stricte** : `Schema::hasColumn()` en garde des deux côtés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallpapers', function (Blueprint $table): void {
            if (Schema::hasColumn('wallpapers', 'mode')) {
                $table->dropColumn('mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallpapers', function (Blueprint $table): void {
            if (! Schema::hasColumn('wallpapers', 'mode')) {
                $table->string('mode', 16)->nullable()
                    ->comment('Mode d\'application de la règle (réintroduit par rollback 27.8 ; retiré par Story 27.8 — STRICT inconditionnel)');
            }
        });
    }
};
