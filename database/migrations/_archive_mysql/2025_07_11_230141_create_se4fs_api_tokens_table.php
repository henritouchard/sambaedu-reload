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
        Schema::create('se4fs_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('instance_id')->unique()->comment('Identifiant unique de l\'instance client');
            $table->string('token_hash', 64)->unique()->comment('Hash SHA-256 du token API');
            $table->string('client_name')->comment('Nom de l\'application cliente');
            $table->string('client_url')->comment('URL de l\'application cliente');
            $table->string('client_version', 20)->comment('Version de l\'application cliente');
            $table->string('webhook_url')->comment('URL webhook du client');
            $table->string('webhook_token_hash', 64)->comment('Hash SHA-256 du token webhook');
            $table->json('capabilities')->comment('Capacités de l\'application cliente');
            $table->timestamp('last_used_at')->nullable()->comment('Dernière utilisation du token');
            $table->timestamp('expires_at')->nullable()->comment('Date d\'expiration du token');
            $table->boolean('is_active')->default(true)->comment('Token actif ou révoqué');
            $table->string('created_by_ip', 45)->nullable()->comment('IP de création du token');
            $table->timestamps();
            
            // Index pour optimiser les requêtes fréquentes
            $table->index(['token_hash', 'is_active']);
            $table->index(['instance_id', 'is_active']);
            $table->index(['expires_at', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('se4fs_api_tokens');
    }
};
