<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Modifier la contrainte unique pour inclure la branche
     * Car une même app peut exister dans plusieurs branches (stable, testing, manuel)
     */
    public function up(): void
    {
        Schema::table('depot_applications', function (Blueprint $table) {
            // Supprimer l'ancienne contrainte
            $table->dropUnique('depot_app_unique');
            
            // Créer la nouvelle contrainte incluant branch
            $table->unique(['depot_id', 'app_id', 'branch'], 'depot_app_branch_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('depot_applications', function (Blueprint $table) {
            $table->dropUnique('depot_app_branch_unique');
            $table->unique(['depot_id', 'app_id'], 'depot_app_unique');
        });
    }
};
