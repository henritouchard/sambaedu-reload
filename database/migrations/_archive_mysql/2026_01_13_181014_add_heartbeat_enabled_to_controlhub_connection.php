<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('controlhub_connection', function (Blueprint $table) {
            $table->boolean('heartbeat_enabled')->default(true)->after('heartbeat_interval');
            $table->integer('heartbeat_failures')->default(0)->after('heartbeat_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('controlhub_connection', function (Blueprint $table) {
            $table->dropColumn(['heartbeat_enabled', 'heartbeat_failures']);
        });
    }
};
