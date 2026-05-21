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
            $table->string('app_profile_name')->nullable()->after('description');
        });

        // SQLite refuse de dropper une colonne indexée — dropper l'index d'abord
        // (créé par 2026_01_30_000000_create_unified_schema.php:99).
        Schema::table('workstation_groups', function (Blueprint $table) {
            $table->dropIndex(['is_physical_room']);
        });

        Schema::table('workstation_groups', function (Blueprint $table) {
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
