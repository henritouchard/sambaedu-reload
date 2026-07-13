<?php

use App\Actions\Groups\BackfillUserGroupUserRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 42.1 — Colonne d'arête `role` sur le pivot `user_group_user` (socle du
 * rôle sur l'arête user↔groupe).
 *
 * `up()` ajoute la colonne `role` (`string(20)`, non null, défaut `'member'`,
 * indexée) sur le pivot (PK composite `(user_group_id, user_id)`, pas de
 * timestamps — on ne touche NI à la PK NI aux timestamps). Le défaut `'member'`
 * est rétro-rempli sur les arêtes existantes (Laravel/SQLite + PG). Le backfill
 * déterministe (`owner`/`manager`/`member` depuis `is_head_teacher` + `users.role`)
 * est ensuite appliqué via l'action invocable {@see BackfillUserGroupUserRoles}
 * (extraite pour la testabilité — patron 4.14 `MergeLegacyUserGroups`).
 *
 * Le vocabulaire est BORNÉ APPLICATIVEMENT ({@see \App\Models\Pivot\UserGroupUserPivot::assertValidRole})
 * et non par un enum SQL : SQLite ne borne pas les varchar (les tests ne
 * verraient pas un dépassement 22001 PG).
 *
 * `down()` : supprime la colonne (l'index part avec ; idempotent via
 * `Schema::hasColumn`). Partie data no-op documentée — les rôles se
 * reconstruisent par backfill / re-import (`syncFromAd` autoritaire, transitoire).
 *
 * Note exécution (D5, `project_vm_migrations_not_auto_applied`) : les migrations
 * VM ne sont PAS auto-jouées par le dev-cycle (SQLite migré pour les tests
 * uniquement ; la VM reste `Pending`). L'exécution réelle sur PG est un geste
 * post-merge MANUEL (`php artisan migrate` + `migrate:status` sur /vm) — voir
 * runbook QA (domaine rights-management).
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) Colonne d'arête `role`.
        if (!Schema::hasColumn('user_group_user', 'role')) {
            Schema::table('user_group_user', function (Blueprint $table): void {
                $table->string('role', 20)->default('member')->index();
            });
        }

        // 2) Backfill déterministe idempotent APRÈS la création de colonne.
        //    Sur une install fraîche la base est vide → no-op ; sur une base
        //    déjà peuplée (upgrade), rétro-remplit owner/manager/member.
        (new BackfillUserGroupUserRoles())();
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_group_user', 'role')) {
            Schema::table('user_group_user', function (Blueprint $table): void {
                // SQLite laisse un index orphelin si la colonne indexée est
                // droppée sans retirer l'index d'abord → on le retire
                // explicitement (no-op si absent selon le driver). `dropIndex`
                // par colonnes recompose le nom conventionnel `_role_index`.
                $table->dropIndex(['role']);
                $table->dropColumn('role');
            });
        }

        // Partie data : pas de restauration — les rôles sont reconstructibles
        // par backfill / re-import depuis l'AD (source transitoire). No-op
        // volontaire.
    }
};
