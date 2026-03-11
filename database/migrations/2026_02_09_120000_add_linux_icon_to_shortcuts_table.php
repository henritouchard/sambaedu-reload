<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortcuts', function (Blueprint $table) {
            $table->string('linux_icon', 512)->nullable()->after('linux_path')->comment('Chemin de l\'icône Linux');
        });
    }

    public function down(): void
    {
        Schema::table('shortcuts', function (Blueprint $table) {
            $table->dropColumn('linux_icon');
        });
    }
};
