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
        Schema::table('workstation_groups', function (Blueprint $table) {
            $table->uuid('controlhub_id')->nullable()->unique()->after('id');
        });

        Schema::table('shortcuts', function (Blueprint $table) {
            $table->uuid('controlhub_id')->nullable()->unique()->after('id');
        });

        Schema::table('app_profiles', function (Blueprint $table) {
            $table->uuid('controlhub_id')->nullable()->unique()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workstation_groups', function (Blueprint $table) {
            $table->dropColumn('controlhub_id');
        });

        Schema::table('shortcuts', function (Blueprint $table) {
            $table->dropColumn('controlhub_id');
        });

        Schema::table('app_profiles', function (Blueprint $table) {
            $table->dropColumn('controlhub_id');
        });
    }
};
