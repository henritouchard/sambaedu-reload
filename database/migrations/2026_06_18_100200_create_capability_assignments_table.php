<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic 27 — Override de VALEUR d'une capacité par maille (remplace
 * `registry_setting_assignables`). Calqué sur le pivot polymorphe 27.3 :
 *   - WorkstationGroup (salles physiques, parcs logiques) — geste UI v1 par PARC ;
 *   - Workstation / UserGroup / User (extensibles sans migration, hors UI v1).
 *
 * Différence clé avec 27.3 : l'override remonte au niveau de la CAPACITÉ (« ce
 * parc applique telle valeur pour cette capacité »), plus au niveau d'une clé de
 * registre. `value` = valeur de capacité surchargée (sémantique selon
 * `capabilities.value_type`) ; `null` = repli sur le défaut diffusé (Broadcast).
 * « Retirer » un override = supprimer la ligne → le poste re-converge vers le
 * défaut au cycle suivant (PAS « cesser de gérer »).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('capability_assignments')) {
            return;
        }

        Schema::create('capability_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capability_id')
                ->constrained('capabilities')
                ->cascadeOnDelete();
            $table->morphs('assignable'); // assignable_id + assignable_type

            // Override de la VALEUR de capacité (null = repli sur le défaut diffusé).
            $table->text('value')->nullable()->comment('Valeur de capacité surchargée pour cette maille ; null = repli défaut Broadcast');

            $table->timestamps();

            $table->unique(
                ['capability_id', 'assignable_id', 'assignable_type'],
                'capability_assignment_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_assignments');
    }
};
