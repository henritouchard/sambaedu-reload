<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration {
    /**
     * Met à jour la table pivot app_profile_workstation_group pour pointer
     * vers la nouvelle table workstation_groups au lieu de parc.
     * 
     * La table est vidée car les IDs ne correspondent plus.
     * Les données seront resynchronisées depuis l'AD.
     */
    public function up(): void
    {
        // Vérifier si la FK existe avant de la supprimer
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'app_profile_workstation_group' 
            AND CONSTRAINT_NAME = 'app_profile_wg_parc_fk'
        ");

        if (!empty($foreignKeys)) {
            Schema::table('app_profile_workstation_group', function (Blueprint $table) {
                $table->dropForeign('app_profile_wg_parc_fk');
            });
        }

        // Vider la table car les IDs ne correspondent plus
        $count = DB::table('app_profile_workstation_group')->count();
        if ($count > 0) {
            Log::info("Migration: Vidange de app_profile_workstation_group ({$count} lignes)");
            DB::table('app_profile_workstation_group')->truncate();
        }

        Schema::table('app_profile_workstation_group', function (Blueprint $table) {
            // Changer le type de colonne pour matcher workstation_groups.id (BIGINT)
            $table->unsignedBigInteger('workstation_group_id')->change();
            
            // Ajouter la nouvelle FK vers workstation_groups
            $table->foreign('workstation_group_id', 'app_profile_wg_fk')
                ->references('id')
                ->on('workstation_groups')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_profile_workstation_group', function (Blueprint $table) {
            $table->dropForeign('app_profile_wg_fk');
        });

        Schema::table('app_profile_workstation_group', function (Blueprint $table) {
            $table->integer('workstation_group_id')->change();
            
            $table->foreign('workstation_group_id', 'app_profile_wg_parc_fk')
                ->references('id_parc')
                ->on('parc')
                ->onDelete('cascade');
        });
    }
};
