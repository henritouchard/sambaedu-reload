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
        Schema::table('shortcuts', function (Blueprint $table) {
            $table->timestamp('controlhub_version')->nullable()->after('controlhub_id');
        });

        Schema::table('workstation_groups', function (Blueprint $table) {
            $table->timestamp('controlhub_version')->nullable()->after('controlhub_id');
        });

        Schema::table('app_profiles', function (Blueprint $table) {
            $table->timestamp('controlhub_version')->nullable()->after('controlhub_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shortcuts', function (Blueprint $table) {
            $table->dropColumn('controlhub_version');
        });

        Schema::table('workstation_groups', function (Blueprint $table) {
            $table->dropColumn('controlhub_version');
        });

        Schema::table('app_profiles', function (Blueprint $table) {
            $table->dropColumn('controlhub_version');
        });
    }
};
