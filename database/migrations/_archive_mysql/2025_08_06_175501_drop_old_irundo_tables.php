<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drop old IRUNDO tables after successful refactoring to IrundoConnection
     */
    public function up(): void
    {
        // Drop old IRUNDO token management tables
        Schema::dropIfExists('irundo_tokens');
        Schema::dropIfExists('se4fs_api_tokens');
    }

    /**
     * Reverse the migrations.
     * Recreate the old tables if needed (basic structure only)
     */
    public function down(): void
    {
        // Recreate irundo_tokens table (basic structure)
        Schema::create('irundo_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id')->unique();
            $table->text('api_token');
            $table->string('webhook_token')->nullable();
            $table->string('webhook_url')->nullable();
            $table->string('heartbeat_url')->nullable();
            $table->integer('heartbeat_interval')->default(300);
            $table->timestamp('last_handshake_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('handshake_data')->nullable();
            $table->string('irundo_base_url')->nullable();
            $table->timestamps();
        });

        // Recreate se4fs_api_tokens table (basic structure)
        Schema::create('se4fs_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id');
            $table->string('token_hash');
            $table->string('webhook_token_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
};
