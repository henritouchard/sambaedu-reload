<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Associations de raccourcis vers des groupes et entités.
 * 
 * 1. Table pivot polymorphique shortcut_assignables :
 *    - WorkstationGroup (salles physiques, parcs logiques)
 *    - Workstation (postes individuels)
 *    - Tout futur modèle SQL "assignable"
 * 
 * 2. Colonnes JSON sur shortcuts pour les entités AD (sans table SQL) :
 *    - ad_users : tableau de CN d'utilisateurs AD (ex: ["jean.dupont"])
 *    - ad_user_groups : tableau de CN de groupes AD (ex: ["Profs", "3emeA"])
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pivot polymorphique pour les modèles SQL
        Schema::create('shortcut_assignables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shortcut_id')->constrained('shortcuts')->cascadeOnDelete();
            $table->morphs('assignable'); // assignable_id + assignable_type
            $table->timestamps();

            $table->unique(['shortcut_id', 'assignable_id', 'assignable_type'], 'shortcut_assignable_unique');
        });

        // Colonnes JSON pour les entités AD
        Schema::table('shortcuts', function (Blueprint $table) {
            $table->jsonb('ad_users')->nullable()->default('[]')->comment('CN des utilisateurs AD assignés');
            $table->jsonb('ad_user_groups')->nullable()->default('[]')->comment('CN des groupes AD assignés');
        });
    }

    public function down(): void
    {
        Schema::table('shortcuts', function (Blueprint $table) {
            $table->dropColumn(['ad_users', 'ad_user_groups']);
        });

        Schema::dropIfExists('shortcut_assignables');
    }
};
