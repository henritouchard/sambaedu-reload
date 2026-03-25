<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_boot_logs', function (Blueprint $table) {
            $table->string('action', 20)->nullable()->after('machine_name')
                ->comment('Type d\'action: wake, shutdown, reboot');
            $table->string('initiated_by', 100)->nullable()->after('action')
                ->comment('Utilisateur ayant déclenché l\'action');
            $table->boolean('success')->nullable()->after('initiated_by')
                ->comment('Résultat de l\'action');

            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::table('machine_boot_logs', function (Blueprint $table) {
            $table->dropIndex(['action']);
            $table->dropColumn(['action', 'initiated_by', 'success']);
        });
    }
};
