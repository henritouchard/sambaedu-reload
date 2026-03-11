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
        Schema::table('irundo_tokens', function (Blueprint $table) {
            $table->string('irundo_base_url')->nullable()->after('instance_id')->comment('URL de base de l\'instance IRUNDO utilisée');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('irundo_tokens', function (Blueprint $table) {
            $table->dropColumn('irundo_base_url');
        });
    }
};
