<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 34.3 — catalogue des « templates de répertoire » (recettes d'échange).
 *
 * Une ligne = une RECETTE figée paramétrable (Q3, arbitrage Henri 2026-06-30 :
 * option B = table + seeder PROD, PAS d'enum en dur, PAS de CRUD admin). La
 * variabilité métier est dans les CIBLES sélectionnées à la matérialisation, pas
 * dans la structure de la recette ; l'admin CONSOMME ces recettes, il ne les
 * édite pas (l'édition future = 34.x, option C).
 *
 *  - `key`         : clé stable (snake_case) consommée par l'UI + le service.
 *  - `label`       : libellé FR affiché dans le sélecteur.
 *  - `description` : POURQUOI métier (qui dépose / qui lit).
 *  - `roles_spec`  : JSON — liste ordonnée des RÔLES-cibles du pattern. Chaque
 *                    rôle = {key,label,maille (`App\Models\User`|`App\Models\UserGroup`),
 *                    group_type (`classe`|`equipe`|null), access (`ro`|`rw`),
 *                    cardinality (`one`|`many`)}. INVARIANT : aucun rôle ne porte
 *                    de maille `WorkstationGroup` (WG = montage-seul, jamais d'ACL).
 *
 * Peuplée par {@see Database\Seeders\DirectoryTemplateSeeder} (idempotent, iso
 * `PermissionSeeder`). ⚠️ Pré-déploiement VM : `db:seed --class=DirectoryTemplateSeeder`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->json('roles_spec');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_templates');
    }
};
