<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * Ajoute la table pivot pour lier les profils applicatifs directement aux postes.
     * Cela permet d'appliquer un profil à un poste spécifique en plus des groupes.
     */
    public function up(): void
    {
        Schema::create('app_profile_workstation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_profile_id')
                ->constrained('app_profiles')
                ->onDelete('cascade');
            $table->integer('workstation_id'); // INT signé pour matcher postes.id_poste
            $table->timestamps();

            // Index unique pour éviter les doublons
            $table->unique(['app_profile_id', 'workstation_id'], 'app_profile_ws_unique');

            // Clé étrangère vers postes (table legacy) - INT signé
            $table->foreign('workstation_id', 'app_profile_ws_poste_fk')
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
        Schema::dropIfExists('app_profile_workstation');
    }
};
