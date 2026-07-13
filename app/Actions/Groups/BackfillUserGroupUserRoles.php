<?php

declare(strict_types=1);

namespace App\Actions\Groups;

use App\Models\Pivot\UserGroupUserPivot;
use Illuminate\Support\Facades\DB;

/**
 * Story 42.1 — Backfill DÉTERMINISTE et IDEMPOTENT de la colonne d'arête
 * `user_group_user.role` depuis l'existant (`is_head_teacher` + `users.role`).
 *
 * Chaque arête préexistante reçoit exactement un rôle du vocabulaire borné
 * {@see UserGroupUserPivot::ROLES} selon la précédence `owner > manager > member` :
 *  - `owner`   si `is_head_teacher = true` (le professeur principal — 4.14) ;
 *  - `manager` sinon si le user a `users.role = 'prof'` (professeur membre) ;
 *  - `member`  sinon (élève, admin, autre, null).
 *
 * Pourquoi une action invocable (pas la logique inline dans la migration) :
 * la data-migration est ainsi TESTABLE sur un état monté à la main (patron
 * {@see MergeLegacyUserGroups}) et rejouable. La logique est PURE SQL
 * cross-driver, SANS aucune dépendance LDAP/service :
 *
 * - la dérivation `manager` lit la COLONNE `users.role` via une sous-requête
 *   (jointure/`whereExists`), JAMAIS `User::isProf()` qui ferait un round-trip
 *   LDAP PAR user (`project_isprof_iseleve_ldap_first_cost`) ;
 * - la dérivation `owner` compare `is_head_teacher` via le query builder
 *   (`where('is_head_teacher', true)`), JAMAIS en SQL brut `= true` — SQLite
 *   stocke les booléens en 0/1.
 *
 * Idempotence : les 3 passes réécrivent l'ÉTAT FINAL déterministe de chaque
 * arête (base member → surcharge manager → surcharge owner). Un 2ᵉ run produit
 * exactement le même état, sans exception. Appelée par la migration APRÈS la
 * création de la colonne (la colonne est donc garantie présente à l'exécution).
 */
class BackfillUserGroupUserRoles
{
    /**
     * Exécute le backfill. Retourne un compte-rendu par bucket (utile aux tests
     * et au log de la migration).
     *
     * @return array{member:int, manager:int, owner:int, total:int}
     */
    public function __invoke(): array
    {
        // Passe 1 — base : toutes les arêtes valent `member`. On repart d'une
        // base neutre à chaque run pour que la surcharge manager/owner soit
        // idempotente même après un changement de rôle global amont.
        DB::table('user_group_user')->update([
            'role' => UserGroupUserPivot::ROLE_MEMBER,
        ]);

        // Passe 2 — `manager` pour les arêtes dont le user est un professeur
        // (`users.role = 'prof'`). Sous-requête SQL sur la COLONNE (zéro LDAP).
        DB::table('user_group_user')
            ->whereIn('user_id', function ($query): void {
                $query->select('id')
                    ->from('users')
                    ->where('role', 'prof');
            })
            ->update(['role' => UserGroupUserPivot::ROLE_MANAGER]);

        // Passe 3 — `owner` (précédence maximale) pour les arêtes marquées
        // professeur principal. Comparaison booléenne cross-driver.
        DB::table('user_group_user')
            ->where('is_head_teacher', true)
            ->update(['role' => UserGroupUserPivot::ROLE_OWNER]);

        return $this->report();
    }

    /**
     * Compte-rendu par bucket sur l'état final (post-passes).
     *
     * @return array{member:int, manager:int, owner:int, total:int}
     */
    private function report(): array
    {
        $counts = DB::table('user_group_user')
            ->select('role', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        $member = (int) ($counts[UserGroupUserPivot::ROLE_MEMBER] ?? 0);
        $manager = (int) ($counts[UserGroupUserPivot::ROLE_MANAGER] ?? 0);
        $owner = (int) ($counts[UserGroupUserPivot::ROLE_OWNER] ?? 0);

        return [
            'member' => $member,
            'manager' => $manager,
            'owner' => $owner,
            'total' => $member + $manager + $owner,
        ];
    }
}
