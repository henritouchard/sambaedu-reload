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
        Schema::table('controlhub_connection', function (Blueprint $table) {
            $table->string('status')->default('offline'); // 'online', 'offline', 'error'
            $table->string('error_type')->nullable(); // 'connection', 'heartbeat', 'handshake'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('controlhub_connection', function (Blueprint $table) {
            $table->dropColumn(['status', 'error_type']);
        });
    }
};
