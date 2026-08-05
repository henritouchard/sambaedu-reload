<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 60.5 — « ce partage EST l'arbre de ce groupe, d'après cette recette ».
 *
 * Trois colonnes ADDITIVES et NULLABLES, et une unicité conditionnelle. Elles
 * relient un partage à son ORIGINE ; leur absence est l'état normal de tous les
 * partages en place, qui n'ont pas d'origine et n'en auront jamais.
 *
 *  - `directory_template_id` — la recette dont ce partage est la matérialisation ;
 *  - `user_group_id` — le groupe de cloisonnement dont il porte l'arbre ;
 *  - `node_activation` — JSON `{ "<chemin de nœud écrit>": bool }`. Une entrée
 *    ABSENTE vaut ACTIF (décision D6=A, iso l'espace d'échange historique, créé
 *    actif). Le JSON ne porte donc que les écarts au défaut, et un partage neuf
 *    n'a rien à écrire.
 *
 * **Pourquoi l'unicité est conditionnelle.** « Un groupe n'a qu'un arbre par
 * recette » n'a de sens que lorsque les deux colonnes sont renseignées. Une
 * contrainte inconditionnelle aurait fait de `(null, null)` — l'état de tous les
 * partages existants — une valeur unique, et le second partage ordinaire créé
 * aurait été refusé par la base. L'index PARTIEL dit exactement la règle voulue,
 * sur les seules lignes qu'elle concerne.
 *
 * **Suppression : `nullOnDelete` des deux côtés.** Supprimer une recette ou un
 * groupe ne doit pas emporter le partage ni ses données (D9 — aucune destruction
 * implicite) : la ligne survit, orpheline et visible, et l'administrateur décide
 * depuis l'écran des partages. Une cascade aurait fait disparaître un répertoire
 * réel sur un geste qui ne parlait que d'un groupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_shares', function (Blueprint $table) {
            $table->foreignId('directory_template_id')
                ->nullable()
                ->after('backend')
                ->constrained('directory_templates')
                ->nullOnDelete();

            $table->foreignId('user_group_id')
                ->nullable()
                ->after('directory_template_id')
                ->constrained('user_groups')
                ->nullOnDelete();

            $table->json('node_activation')->nullable()->after('user_group_id');
        });

        // Unicité PARTIELLE : elle ne porte que sur les partages qui ont une
        // origine. Écrite en SQL brut parce que l'index conditionnel n'a pas
        // d'expression portable dans le constructeur de schéma.
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX network_shares_tree_origin_unique '
                . 'ON network_shares (directory_template_id, user_group_id) '
                . 'WHERE directory_template_id IS NOT NULL AND user_group_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS network_shares_tree_origin_unique');
        }

        Schema::table('network_shares', function (Blueprint $table) {
            // Les contraintes de clé étrangère tombent AVANT les colonnes : SQLite
            // ne sait pas supprimer une colonne encore contrainte, et les laisser
            // derrière ferait échouer un `up()` rejoué.
            $table->dropConstrainedForeignId('directory_template_id');
            $table->dropConstrainedForeignId('user_group_id');
            $table->dropColumn('node_activation');
        });
    }
};
