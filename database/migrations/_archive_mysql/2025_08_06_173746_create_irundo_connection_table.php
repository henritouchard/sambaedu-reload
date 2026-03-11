<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('controlhub_connection', function (Blueprint $table) {
            $table->id();
            
            // Connexion ControlHub - Strictement essentiels
            $table->string('base_url')->comment('URL de base de l\'application ControlHub');
            $table->text('api_token')->comment('Token pour appeler les APIs ControlHub');
            $table->string('se4fs_api_token', 64)->comment('Token pour valider les appels de ControlHub vers SE4FS');
            
            // Configuration optionnelle
            $table->integer('heartbeat_interval')->default(300)->comment('Intervalle heartbeat en secondes (défini par ControlHub)');
            
            // Métadonnées
            $table->timestamp('last_handshake_at')->nullable()->comment('Date du dernier handshake réussi');
            $table->timestamp('expires_at')->nullable()->comment('Date d\'expiration des tokens');
            $table->boolean('is_active')->default(true)->comment('Connexion active');
            
            $table->timestamps();
            
            // Index pour performance
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('controlhub_connection');
    }
};
