<?php

use App\Actions\Groups\MergeLegacyUserGroups;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 4.14 — Colonne d'arête `is_head_teacher` + fusion des lignes
 * `user_groups` héritées (convergence vers le modèle « 1 ligne = 1 classe »
 * de 4.13).
 *
 * `up()` en deux temps, l'ordre est garanti :
 *  1. Ajoute la colonne `is_head_teacher` (bool, défaut false, non null) sur le
 *     pivot `user_group_user` (PK composite, pas de timestamps — on ne touche
 *     ni à la PK ni aux timestamps). Le défaut `false` est rétro-rempli sur les
 *     arêtes existantes (Laravel/SQLite + PG).
 *  2. Lance la fusion des lignes héritées (`Classe_X`/`Equipe_X`/`PP_X` → `X`)
 *     via {@see MergeLegacyUserGroups} — la colonne doit exister AVANT pour que
 *     l'action pose le flag PP. L'action est idempotente/rejouable et
 *     cross-driver (`insertOrIgnore`).
 *
 * `down()` : supprime la colonne (idempotent via `Schema::hasColumn`). La partie
 * data n'est PAS ré-éclatée — la fusion est convergente, l'information « 3 lignes
 * d'origine » est volontairement perdue (réimportable depuis l'AD, source de
 * vérité). C'est un `down()` no-op sur les données, documenté ici.
 *
 * Note exécution (D5) : les migrations VM ne sont PAS auto-jouées par le
 * dev-cycle (SQLite migré pour les tests uniquement ; la VM reste `Pending`).
 * L'exécution réelle sur PG est un geste post-merge MANUEL (`php artisan migrate`
 * + `migrate:status` sur /vm) — voir runbook QA Section 8.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) Colonne d'arête.
        if (!Schema::hasColumn('user_group_user', 'is_head_teacher')) {
            Schema::table('user_group_user', function (Blueprint $table): void {
                $table->boolean('is_head_teacher')->default(false);
            });
        }

        // 2) Fusion des lignes héritées (idempotente — no-op si déjà foldé).
        (new MergeLegacyUserGroups())();
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_group_user', 'is_head_teacher')) {
            Schema::table('user_group_user', function (Blueprint $table): void {
                $table->dropColumn('is_head_teacher');
            });
        }

        // Partie data : pas de restauration des lignes éclatées (convergence
        // assumée — réimportable depuis l'AD). No-op volontaire.
    }
};
