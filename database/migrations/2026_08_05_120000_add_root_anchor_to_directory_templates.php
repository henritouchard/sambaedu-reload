<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 60.5 — « dans quelle ZONE cet arbre vit-il ? ».
 *
 * Une colonne ADDITIVE et NULLABLE : `root_anchor`. Elle porte un jeton d'un
 * vocabulaire FERMÉ ({@see App\Enums\PlanAnchor} — `reseau|classes`), JAMAIS un
 * chemin. La traduction du jeton en racine réelle vit sous la ligne de contrat,
 * dans la garde de chemin du backend, et nulle part ailleurs : c'est ce qui permet
 * à SE5 de gouverner deux zones disjointes sans qu'un plan cesse d'être portable.
 *
 * **Pourquoi la zone appartient à la RECETTE et non à l'appelant.** Une recette dit
 * ce qu'elle décrit, y compris où cela vit ; si la zone était choisie par
 * l'orchestrateur, la même recette matérialiserait des arbres à deux endroits selon
 * qui l'appelle, et rien dans la donnée ne dirait lequel est le bon.
 *
 * **`null` a un sens EXACT** : la zone par défaut, celle des répertoires réseau
 * nommés. Les 4 recettes seedées de 34.3 ne se prononcent pas et continuent donc de
 * vivre là où elles ont toujours vécu — aucune reprise de données.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directory_templates', function (Blueprint $table) {
            $table->string('root_anchor')->nullable()->after('nodes_spec');
        });
    }

    public function down(): void
    {
        Schema::table('directory_templates', function (Blueprint $table) {
            $table->dropColumn('root_anchor');
        });
    }
};
