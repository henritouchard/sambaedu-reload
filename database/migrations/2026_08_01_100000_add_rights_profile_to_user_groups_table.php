<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * **Aucun seed (décision Henri, 2026-08-03).** Cette migration est PUREMENT
 * structurelle : elle ajoute la colonne, rien d'autre. Une version antérieure
 * posait `prof` sur le groupe `Profs` et `eleve` sur `Eleves` — un seed
 * « iso-comportement » pour les parcs scolaires existants. Deux raisons de
 * l'avoir retiré :
 *
 *  1. **Aucune installation ne le justifie.** Le produit n'est déployé que sur
 *     les environnements de développement et de lab ; embarquer à perpétuité,
 *     dans l'histoire du schéma, une migration de données qui ne s'appliquera
 *     jamais nulle part est une dette gratuite. Le geste a été fait à la main
 *     là où il avait un sens.
 *  2. **Un profil de droits ne s'attribue pas par surprise.** Poser des droits
 *     sur un groupe est une décision d'administrateur, pas un effet de bord de
 *     migration — et `Profs`/`Eleves` sont des noms du vertical scolaire, que
 *     rien ne rend universels : dans une administration, ces groupes n'existent
 *     pas, et un groupe qui porterait par hasard l'un de ces noms recevrait des
 *     droits que personne n'a demandés.
 *
 * Le geste d'amorçage est donc celui du produit : onglet Profils de
 * `/app/rights-management` → « Donner des permissions à un groupe », qui pose le
 * lien ET re-projette les membres dans le même geste (`setProfile()`).
 *
 * La migration reste idempotente (gardes `hasTable`/`hasColumn`).
 */
return new class extends Migration
{
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
};
