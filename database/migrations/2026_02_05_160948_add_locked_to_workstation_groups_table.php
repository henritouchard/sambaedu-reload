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
            $table->string('locked')->nullable()->after('is_active')->comment('Si non-null, empêche modification et suppression. Contient la raison du verrouillage.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workstation_groups', function (Blueprint $table) {
            $table->dropColumn('locked');
        });
    }
};
