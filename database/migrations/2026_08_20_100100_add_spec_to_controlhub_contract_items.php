<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attributs typés d'un item du contrat amont, au-delà de sa seule valeur scalaire.
 *
 * `key` + `value` suffisent à un item registre (une clé, une valeur), pas à un
 * raccourci : l'agent rejette en bloc un raccourci sans `place`, et un raccourci
 * utile porte aussi sa cible, ses arguments, son répertoire de travail. Plutôt
 * qu'une colonne par attribut et par type, un document JSON par item — le
 * vocabulaire de chaque type reste défini dans le schéma d'échange.
 *
 * Les clés sont triées à la réception : sans ordre stable, deux réceptions
 * identiques produiraient deux JSON différents et casseraient le no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controlhub_contract_items', function (Blueprint $table): void {
            $table->json('spec')->nullable()->after('value')
                ->comment('Attributs typés de l\'item selon son `type` (clés triées) ; null = aucun');
        });
    }

    public function down(): void
    {
        Schema::table('controlhub_contract_items', function (Blueprint $table): void {
            $table->dropColumn('spec');
        });
    }
};
