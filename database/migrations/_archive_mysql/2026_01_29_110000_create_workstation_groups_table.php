<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Crée la nouvelle table workstation_groups propre.
     * 
     * Cette table remplace la table legacy 'parc' avec une structure moderne.
     * Le legacy continuera à utiliser 'parc' via le LegacyParcBridgeService
     * qui synchronise les données entre les deux tables.
     * 
     * Différences avec la table legacy 'parc':
     * - Noms de colonnes en snake_case anglais
     * - Pas de colonnes WPKG (gérées via AppProfile)
     * - Structure hiérarchique propre
     * - Timestamps Laravel
     */
    public function up(): void
    {
        Schema::create('workstation_groups', function (Blueprint $table) {
            $table->id();
            
            // Identifiant unique du groupe (slug)
            $table->string('name', 100)->unique();
            
            // Nom d'affichage
            $table->string('display_name', 255)->nullable();
            
            // Description du groupe
            $table->text('description')->nullable();
            
            // Hiérarchie : référence vers le parent
            $table->foreignId('parent_id')->nullable()
                ->constrained('workstation_groups')
                ->onDelete('set null');
            
            // Type de groupe
            $table->boolean('is_physical_room')->default(false)
                ->comment('True = salle physique (OU AD), False = groupe logique (CN AD)');
            
            // Synchronisation AD
            $table->string('ad_dn', 512)->nullable()
                ->comment('Distinguished Name complet dans AD');
            $table->string('ad_guid', 36)->nullable()
                ->comment('objectGUID dans AD');
            
            // Statut
            $table->boolean('is_active')->default(true);
            
            // Gestion par ControlHub
            $table->boolean('managed_by_control_hub')->default(false);
            
            // Timestamps
            $table->timestamps();
            
            // Index
            $table->index('parent_id');
            $table->index('is_physical_room');
            $table->index('is_active');
            $table->index('ad_guid');
        });

        // Table pivot pour les associations WorkstationGroup ↔ Workstation
        Schema::create('workstation_group_workstation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_group_id')
                ->constrained('workstation_groups')
                ->onDelete('cascade');
            $table->integer('workstation_id'); // INT signé pour matcher postes.id_poste
            $table->timestamps();

            // Index unique pour éviter les doublons
            $table->unique(['workstation_group_id', 'workstation_id'], 'wg_ws_unique');

            // Clé étrangère vers postes (table legacy) - INT signé
            $table->foreign('workstation_id', 'wg_ws_poste_fk')
                ->references('id_poste')
                ->on('postes')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workstation_group_workstation');
        Schema::dropIfExists('workstation_groups');
    }
};
