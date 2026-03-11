<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Ajoute la distinction groupe physique (OU dans OU=Computers) vs logique (CN dans OU=Parcs)
     * et la hiérarchie pour les groupes physiques.
     */
    public function up(): void
    {
        Schema::table('workstation_groups', function (Blueprint $table) {
            // Groupe physique = OU dans OU=Computers (pour GPO)
            // Groupe logique = CN dans OU=Parcs (pour WPKG)
            $table->boolean('is_physical')->default(true)->after('name')
                ->comment('true = groupe physique (OU dans Computers), false = groupe logique (CN dans Parcs)');
            
            // parent_id existe déjà dans la table
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workstation_groups', function (Blueprint $table) {
            $table->dropColumn('is_physical');
        });
    }
};
