<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 49.1 (AC1 + AC9) — le groupe d'utilisateurs porte le profil de droits.
 *
 * Décisions figées (create-story) :
 *
 *  - **D1 — `rights_profile_id`, PAS `role_id`.** Trois notions homonymes
 *    coexistent déjà : `user_group_user.role` (attribut d'ARÊTE member/manager/
 *    owner, Story 42.1) et `users.role` (enum legacy scolaire). Nommer la
 *    colonne `role_id` aurait créé une collision sémantique frontale.
 *  - **D1 (bis) — FK vers `roles.id`, jamais un nom.** Les profils custom sont
 *    renommables depuis `profiles/[id]` (`save()`) : un nom stocké deviendrait
 *    pendant au premier renommage.
 *  - **`restrictOnDelete`** : filet DB SOUS la garde applicative d'AC6 (les
 *    deux chemins UI de suppression refusent explicitement un profil porté,
 *    avec le nom des groupes porteurs). Un `Role::delete()` hors UI échoue
 *    aussi — la suppression silencieuse d'un profil porté retirerait des
 *    droits à tout un parc.
 *  - **Nullable, sans unicité** : le cas NORMAL est l'absence de lien (la très
 *    grande majorité des ~200 groupes — classes, équipes, matières — ne porte
 *    rien), et un même profil peut être porté par PLUSIEURS groupes.
 *
 * Volet DONNÉES (AC9 — seed brownfield, volet (a) du D6) : sur un parc scolaire
 * existant dont les groupes `Profs`/`Eleves` sont déjà importés, on pose le
 * défaut iso-comportement (`prof` / `eleve`) SI ET SEULEMENT SI le groupe existe
 * et ne porte encore rien. `Administratifs` ne reçoit RIEN (aucun rôle Spatie
 * `administratif` n'existe — le lien nullable est fait pour ça). Le greenfield
 * (groupes pas encore importés à la migration) est couvert par le volet (b) :
 * défaut à la CRÉATION du groupe dans `UserGroupService::projectFoldedGroup()`.
 *
 * La migration est idempotente (gardes `hasColumn`) et n'écrase JAMAIS un choix
 * admin : `whereNull('rights_profile_id')` uniquement.
 */
return new class extends Migration
{
    /**
     * Défauts iso-comportement : nom de groupe SQL → nom de rôle Spatie.
     *
     * `Administratifs` est ABSENT volontairement (AC9).
     */
    private const SEED_DEFAULTS = [
        'Profs' => 'prof',
        'Eleves' => 'eleve',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('user_groups')) {
            return;
        }

        if (! Schema::hasColumn('user_groups', 'rights_profile_id')) {
            Schema::table('user_groups', function (Blueprint $table) {
                $table->foreignId('rights_profile_id')
                    ->nullable()
                    ->constrained('roles')
                    ->restrictOnDelete();
            });
        }

        $this->seedBrownfieldDefaults();
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_groups') || ! Schema::hasColumn('user_groups', 'rights_profile_id')) {
            return;
        }

        Schema::table('user_groups', function (Blueprint $table) {
            // `dropConstrainedForeignId` gère le drop de la FK puis de la
            // colonne (no-op sur SQLite, qui n'a pas de contrainte nommée).
            $table->dropConstrainedForeignId('rights_profile_id');
        });
    }

    /**
     * Volet données (AC9 / D6-a). Requêtes portables SQLite ⇄ Postgres (aucun
     * SQL brut spécifique) — les tests hôtes tournent sur SQLite.
     */
    private function seedBrownfieldDefaults(): void
    {
        if (! Schema::hasTable('roles')) {
            return; // socle Spatie absent : rien à lier (no-op silencieux).
        }

        foreach (self::SEED_DEFAULTS as $groupName => $roleName) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->value('id');

            if ($roleId === null) {
                continue; // greenfield / installation personnalisée : no-op.
            }

            // `LOWER(name)` : l'AD legacy stocke des CN en minuscules (`profs`)
            // aussi souvent qu'en capitale — et `LOWER()` est portable
            // SQLite ⇄ Postgres (même pattern que `FederatedRoleMapper`).
            DB::table('user_groups')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($groupName)])
                ->whereNull('rights_profile_id')
                ->update(['rights_profile_id' => $roleId]);
        }
    }
};
