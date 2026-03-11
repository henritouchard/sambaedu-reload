<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Retire la notion de salle physique et ajoute un champ optionnel
     * pour créer un AppProfile associé au groupe.
     */
    public function up(): void
    {
        Schema::table('workstation_groups', function (Blueprint $table) {
            // Ajouter le champ app_profile_name (nullable)
            // Si rempli, un AppProfile sera créé avec ce nom
            $table->string('app_profile_name')->nullable()->after('description');
            
            // Retirer la colonne is_physical_room
            $table->dropColumn('is_physical_room');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workstation_groups', function (Blueprint $table) {
            // Restaurer is_physical_room
            $table->boolean('is_physical_room')->default(false)->after('description');
            
            // Retirer app_profile_name
            $table->dropColumn('app_profile_name');
        });
    }
};
