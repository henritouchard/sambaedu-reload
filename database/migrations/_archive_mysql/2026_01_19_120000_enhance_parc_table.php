<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * Enrichit la table legacy 'parc' pour supporter la nouvelle architecture.
     * 
     * TODO: Cette table devra être renommée en 'workstation_groups' une fois
     * que tous les scripts legacy auront été migrés vers Laravel.
     * Le modèle WorkstationGroup utilise déjà cette table via $table = 'parc'.
     * 
     * Stratégie de migration :
     * 1. Vider les données (elles seront réimportées depuis AD)
     * 2. Ajouter les nouvelles colonnes
     * 3. Le Job Laravel SyncWorkstationGroupsFromAd réimportera les données enrichies
     */
    public function up(): void
    {
        // Vider les tables pivot et parc (les données seront réimportées depuis AD)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('parc_profile')->truncate();
        DB::table('parc')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Enrichir la table parc avec les nouvelles colonnes
        Schema::table('parc', function (Blueprint $table) {
            // Description du groupe
            $table->text('description')->nullable()->after('nom_parc_wpkg');

            // Hiérarchie : référence vers le parent (int signé pour matcher id_parc)
            $table->integer('parent_id')->nullable()->after('description');

            // GUID de l'OU dans AD (pour les règles GPO)
            // La colonne 'uuid' existante sert déjà de ad_guid_cn (pour WPKG)
            $table->string('ad_guid_ou', 36)->nullable()->after('uuid')
                ->comment('objectGUID de l\'OU dans AD (si règles GPO associées)');

            // Timestamps Laravel
            $table->timestamps();

            // Index
            $table->index('parent_id');
        });

        // Renommer uuid en ad_guid_cn pour plus de clarté
        Schema::table('parc', function (Blueprint $table) {
            $table->renameColumn('uuid', 'ad_guid_cn');
        });

        // Ajouter un commentaire SQL sur la table
        DB::statement("ALTER TABLE parc COMMENT = 'TODO: Renommer en workstation_groups après migration complète du legacy'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parc', function (Blueprint $table) {
            $table->renameColumn('ad_guid_cn', 'uuid');
        });

        Schema::table('parc', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropColumn(['description', 'parent_id', 'ad_guid_ou', 'created_at', 'updated_at']);
        });
    }
};
