<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3 — Pivot d'assignation des réglages de registre, calqué EXACTEMENT
 * sur `shortcut_assignables` (2026_02_09_173400). Morph polymorphe :
 *   - WorkstationGroup (salles physiques, parcs logiques) — geste UI v1 par PARC ;
 *   - Workstation (postes individuels) ;
 *   - UserGroup (groupes user) ;
 *   - User (utilisateur).
 *
 * D-ciblage : l'UI v1 n'expose que le ciblage PAR PARC (`WorkstationGroup`,
 * physique ET logique), mais le pivot reste COMPLET → extensible vers
 * poste/groupe-user/user SANS migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('registry_setting_assignables')) {
            return;
        }

        Schema::create('registry_setting_assignables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_setting_id')
                ->constrained('registry_settings')
                ->cascadeOnDelete();
            $table->morphs('assignable'); // assignable_id + assignable_type
            $table->timestamps();

            $table->unique(
                ['registry_setting_id', 'assignable_id', 'assignable_type'],
                'registry_setting_assignable_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_setting_assignables');
    }
};
