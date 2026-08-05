<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 60.5 — l'accrochage cesse d'être EXCLUSIF, parce qu'il a cessé de vouloir
 * dire la même chose.
 *
 * **Ce que l'unicité de 60.2 protégeait.** « Deux recettes accrochées au même type
 * poseraient une question à laquelle rien ne peut répondre : laquelle matérialise ? »
 * — et c'était juste, tant qu'accrochage signifiait « c'est CETTE recette que la
 * création d'un groupe matérialise ».
 *
 * **Ce que l'accrochage signifie depuis 60.5.** « À partir d'un groupe de ce type,
 * cette recette sait trouver toutes ses cibles seule. » C'est une propriété
 * d'ÉLIGIBILITÉ, et plusieurs recettes peuvent parfaitement l'avoir sur le même
 * type : l'arbre de classe et la recette plate « profs → élèves » s'accrochent
 * toutes deux au type `classe`. La question de 60.2 ne disparaît pas pour autant —
 * elle se rétrécit à ce qu'elle visait vraiment : **un type de groupe n'a qu'une
 * recette d'ARBRE**, seule matérialisée automatiquement. Cet invariant-là est tenu
 * par une garde d'écriture applicative
 * ({@see App\Models\DirectoryTemplate::assertSingleTreeAttachment()}), qui peut
 * dire QUELLE recette occupe déjà la place — ce qu'un index n'aurait jamais su
 * faire.
 *
 * L'index unique cède donc la place à un index ORDINAIRE : l'appariement par type
 * reste indexé, il n'est simplement plus exclusif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directory_templates', function (Blueprint $table) {
            $table->dropUnique(['attached_group_type']);
            $table->index('attached_group_type', 'directory_templates_attached_group_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('directory_templates', function (Blueprint $table) {
            $table->dropIndex('directory_templates_attached_group_type_index');
            $table->unique('attached_group_type');
        });
    }
};
