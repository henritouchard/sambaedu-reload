<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 60.1 — « la recette devient un arbre » : vocabulaire d'ARBRE ajouté À CÔTÉ
 * du vocabulaire de rôles de 34.3, jamais à sa place.
 *
 * **Pourquoi deux colonnes ADDITIVES et NULLABLES plutôt qu'une refonte de
 * `roles_spec`.** `roles_spec` est consommé par `DirectoryTemplate::roles()`,
 * `role()`, `respectsMountOnlyInvariant()` et le plan d'assignations de 34.3 : le
 * restructurer casserait un socle livré pour zéro gain. L'arbre est un vocabulaire
 * NOUVEAU dont les octrois RÉFÉRENCENT les rôles existants par leur `key`. Les 4
 * recettes seedées 34.3 restent donc des recettes « sans arbre » (`path_pattern`
 * et `nodes_spec` à `null`) : aucune reprise de données, aucun changement de
 * comportement, le seeder n'est pas modifié.
 *
 *  - `path_pattern` : motif de chemin RELATIF avec substitution, ex.
 *    `Classes/Classe_{group.bare_name}`. Vocabulaire de substitution FERMÉ :
 *    `{group.name}` (nom brut, casse préservée) et `{group.bare_name}` (nom
 *    dé-préfixé du préfixe de type, casse préservée — sans lui, on retombe dans
 *    le double préfixe `Classe_Classe_X`). Le motif est RELATIF : la racine
 *    absolue est un savoir de backend (story 60.4), jamais une donnée de recette.
 *
 *  - `nodes_spec` : JSON, liste ORDONNÉE de nœuds. Chaque nœud :
 *      {
 *        "path":      chemin relatif au motif, ex. "_travail" ou "{member.login}",
 *        "label":     libellé métier FR,
 *        "nature":    "partagee" | "activable" | "par_membre" | "contenu_libre"
 *                     (enum FERMÉE {@see App\Enums\PlanNodeNature}),
 *        "edge_role": rôle d'arête énuméré — OBLIGATOIRE et EXCLUSIF à `par_membre`,
 *        "grants":    [ {"role": <key de roles_spec | "@member">,
 *                        "access": "ro"|"rw",
 *                        "suspendable": bool} ],
 *        "plafond":   entier d'octets ou null,
 *        "activable": bool OPTIONNEL et REDONDANT — s'il est présent il DOIT
 *                     valoir `nature === "activable"`. La nature reste la source
 *                     unique : deux champs concurrents pour la même propriété
 *                     seraient une invitation à la contradiction stockée.
 *      }
 *
 * **`plafond` est posé DÈS MAINTENANT alors qu'il ne sera exécuté qu'en 60.6.**
 * Ajouter un champ après coup à des recettes déjà stockées coûte plus cher que de
 * le porter vide : aucun code de cette story ne le lit pour agir, il traverse le
 * plan et attend son exécutant.
 *
 * **Aucun changement d'exécution.** La matérialisation 34.3, le provisioning
 * générique 34.1 et le chemin figé 5.2 sont INTOUCHÉS ; le résolveur de plan
 * (60.1) n'a pour consommateur que ses tests — ses consommateurs réels arrivent
 * en 60.2/60.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directory_templates', function (Blueprint $table) {
            $table->string('path_pattern')->nullable()->after('description');
            $table->json('nodes_spec')->nullable()->after('roles_spec');
        });
    }

    public function down(): void
    {
        Schema::table('directory_templates', function (Blueprint $table) {
            $table->dropColumn(['path_pattern', 'nodes_spec']);
        });
    }
};
