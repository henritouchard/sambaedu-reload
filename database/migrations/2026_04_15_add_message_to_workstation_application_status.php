<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstation_application_status', function (Blueprint $table) {
            $table->text('message')->nullable()->after('reboot_required');
        });
    }

    public function down(): void
    {
        Schema::table('workstation_application_status', function (Blueprint $table) {
            $table->dropColumn('message');
        });
    }
};
