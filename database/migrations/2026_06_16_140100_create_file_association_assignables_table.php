<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3bis — Pivot d'assignation des associations de fichiers, calqué
 * EXACTEMENT sur `shortcut_assignables` (2026_02_09_173400) et
 * `registry_setting_assignables` (27.3). Morph polymorphe :
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
        if (Schema::hasTable('file_association_assignables')) {
            return;
        }

        Schema::create('file_association_assignables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_association_id')
                ->constrained('file_associations')
                ->cascadeOnDelete();
            $table->morphs('assignable'); // assignable_id + assignable_type
            $table->timestamps();

            $table->unique(
                ['file_association_id', 'assignable_id', 'assignable_type'],
                'file_association_assignable_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_association_assignables');
    }
};
