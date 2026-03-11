<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * Crée la table app_profiles et ses tables pivot pour remplacer
     * le système polymorphique legacy de applications_profile.
     * 
     * Architecture :
     * - app_profiles : Profils applicatifs (groupes d'applications)
     * - app_profile_workstation_group : Pivot AppProfile ↔ WorkstationGroup (parc)
     * - app_profile_application : Pivot AppProfile ↔ Application
     * 
     * Note: La table legacy applications_profile utilisait un système polymorphique
     * avec type_entite ('parc'|'poste') et id_entite. Cette nouvelle architecture
     * utilise des relations ManyToMany propres.
     */
    public function up(): void
    {
        // Table principale des profils applicatifs
        Schema::create('app_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('display_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('ad_guid', 36)->nullable()->comment('objectGUID dans AD (après sync)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('ad_guid');
            $table->index('is_active');
        });

        // Pivot AppProfile ↔ WorkstationGroup (parc)
        Schema::create('app_profile_workstation_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_profile_id')
                ->constrained('app_profiles')
                ->onDelete('cascade');
            $table->integer('workstation_group_id'); // INT signé pour matcher parc.id_parc
            $table->timestamps();

            // Index unique pour éviter les doublons
            $table->unique(['app_profile_id', 'workstation_group_id'], 'app_profile_wg_unique');

            // Clé étrangère vers parc (table legacy) - INT signé
            $table->foreign('workstation_group_id', 'app_profile_wg_parc_fk')
                ->references('id_parc')
                ->on('parc')
                ->onDelete('cascade');
        });

        // Pivot AppProfile ↔ Application
        Schema::create('app_profile_application', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_profile_id')
                ->constrained('app_profiles')
                ->onDelete('cascade');
            $table->integer('application_id'); // INT signé pour matcher applications.id_app
            $table->timestamps();

            // Index unique pour éviter les doublons
            $table->unique(['app_profile_id', 'application_id'], 'app_profile_app_unique');

            // Clé étrangère vers applications (table legacy) - INT signé
            $table->foreign('application_id', 'app_profile_app_fk')
                ->references('id_app')
                ->on('applications')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_profile_application');
        Schema::dropIfExists('app_profile_workstation_group');
        Schema::dropIfExists('app_profiles');
    }
};
