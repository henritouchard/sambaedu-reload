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
        Schema::create('irundo_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id')->unique()->comment('Identifiant unique de l\'instance SE4FS');
            $table->text('api_token')->comment('Token API IRUNDO (chiffré)');
            $table->text('webhook_token')->nullable()->comment('Token webhook IRUNDO (chiffré)');
            $table->string('webhook_url')->nullable()->comment('URL webhook IRUNDO');
            $table->string('heartbeat_url')->nullable()->comment('URL heartbeat IRUNDO');
            $table->integer('heartbeat_interval')->default(120)->comment('Intervalle heartbeat en secondes');
            $table->timestamp('last_handshake_at')->comment('Date du dernier handshake réussi');
            $table->timestamp('expires_at')->nullable()->comment('Date d\'expiration du token');
            $table->boolean('is_active')->default(true)->comment('Token actif ou révoqué');
            $table->json('handshake_data')->nullable()->comment('Données complètes du handshake');
            $table->timestamps();
            
            // Index pour optimiser les requêtes
            $table->index(['instance_id', 'is_active']);
            $table->index(['expires_at', 'is_active']);
            $table->index('last_handshake_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('irundo_tokens');
    }
}; 