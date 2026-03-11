<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Refactoring : la table pivot app_profile_application pointe maintenant
     * vers depot_applications au lieu de applications.
     * 
     * La table applications était vide, depot_applications contient les vraies données.
     */
    public function up(): void
    {
        // Supprimer l'ancienne table pivot (vide de toute façon)
        Schema::dropIfExists('app_profile_application');

        // Recréer avec FK vers depot_applications
        Schema::create('app_profile_application', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_profile_id')
                ->constrained('app_profiles')
                ->onDelete('cascade');
            $table->integer('application_id');
            $table->timestamps();

            // Index unique pour éviter les doublons
            $table->unique(['app_profile_id', 'application_id'], 'app_profile_app_unique');

            // Clé étrangère vers depot_applications
            $table->foreign('application_id', 'app_profile_depot_app_fk')
                ->references('id_depot_applications')
                ->on('depot_applications')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_profile_application');

        // Recréer l'ancienne version avec FK vers applications
        Schema::create('app_profile_application', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_profile_id')
                ->constrained('app_profiles')
                ->onDelete('cascade');
            $table->integer('application_id');
            $table->timestamps();

            $table->unique(['app_profile_id', 'application_id'], 'app_profile_app_unique');

            $table->foreign('application_id', 'app_profile_app_fk')
                ->references('id_app')
                ->on('applications')
                ->onDelete('cascade');
        });
    }
};
