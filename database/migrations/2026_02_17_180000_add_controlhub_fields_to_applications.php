<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute les champs ControlHub à la table applications
     * pour permettre la synchronisation depuis le ControlHub.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (!Schema::hasColumn('applications', 'managed_by_control_hub')) {
                $table->boolean('managed_by_control_hub')->default(false)->after('controlhub_version');
            }
        });

        // Rendre depot_id nullable pour les apps poussées par ControlHub sans dépôt
        Schema::table('applications', function (Blueprint $table) {
            $table->bigInteger('depot_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['controlhub_id', 'controlhub_version', 'managed_by_control_hub']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('depot_id')->nullable(false)->change();
        });
    }
};
