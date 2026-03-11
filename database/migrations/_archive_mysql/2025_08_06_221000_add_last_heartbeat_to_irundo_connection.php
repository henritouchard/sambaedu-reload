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
            $table->timestamp('last_heartbeat_at')->nullable()->comment('Date du dernier heartbeat réussi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('controlhub_connection', function (Blueprint $table) {
            $table->dropColumn('last_heartbeat_at');
        });
    }
}; 