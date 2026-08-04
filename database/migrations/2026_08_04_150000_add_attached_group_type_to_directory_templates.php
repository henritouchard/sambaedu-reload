<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 60.2 — « la recette s'accroche à un TYPE de groupe ».
 *
 * Une colonne, nullable, unique. Ce qu'elle dit : « quand un groupe de ce type
 * doit matérialiser son arbre, c'est CETTE recette qui décrit l'arbre ».
 *
 * **Pourquoi NULLABLE.** L'accrochage est l'exception, pas la règle : les quatre
 * recettes seedées en 34.3 restent non accrochées et se matérialisent exactement
 * comme avant (matérialisation manuelle, cibles désignées à la main). Aucune
 * reprise de données n'est nécessaire, et le seeder n'est pas modifié.
 *
 * **Pourquoi UNIQUE.** Deux recettes accrochées au même type poseraient une
 * question à laquelle rien ne peut répondre : laquelle matérialise ? Un « ordre de
 * priorité » serait une règle de plus à comprendre et à maintenir, pour un besoin
 * qui n'existe pas. Un type, une recette. Plusieurs `null` restent autorisés — la
 * contrainte d'unicité ne contraint que les valeurs présentes, sur PostgreSQL
 * comme sur SQLite.
 *
 * **L'invariant que cette colonne matérialise : la maille du groupe EST la maille
 * du cloisonnement.** L'isolation vient de l'APPARTENANCE, pas de l'arborescence :
 * deux classes ne se cloisonnent pas parce que leurs dossiers sont voisins, mais
 * parce que leurs groupes diffèrent. Une recette accrochée à un type produit donc
 * un arbre PAR GROUPE de ce type. Conséquence directe pour les matières : la
 * maille est `matiere_classe` — le groupe « cette matière, dans cette classe »,
 * reconnu au « @ » de son nom (`Matiere_Maths@6A`) — et JAMAIS `matiere` nu, qui
 * désigne les enseignants d'une discipline tous niveaux confondus et qui a sa
 * propre recette, s'il lui en faut une.
 *
 * **Aucune donnée d'accrochage n'est écrite par cette story** : la recette classe
 * est seedée et accrochée en 60.5, quand un backend saura exécuter un arbre. La
 * colonne arrive avant, parce que le modèle doit savoir l'accueillir.
 *
 * Le type est stocké tel qu'il l'est dans `user_groups.type` (chaîne libre bornée
 * par l'applicatif : `classe`, `equipe`, `cours`, `projet`, `matiere`,
 * `matiere_classe`, …). Pas de clé étrangère : il n'existe pas de table des types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directory_templates', function (Blueprint $table) {
            $table->string('attached_group_type')->nullable()->unique()->after('key');
        });
    }

    public function down(): void
    {
        Schema::table('directory_templates', function (Blueprint $table) {
            // L'index unique doit tomber AVANT la colonne : SQLite ne sait pas
            // supprimer une colonne encore indexée, et le laisser derrière ferait
            // échouer un `up()` rejoué (nom d'index déjà pris).
            $table->dropUnique(['attached_group_type']);
            $table->dropColumn('attached_group_type');
        });
    }
};
