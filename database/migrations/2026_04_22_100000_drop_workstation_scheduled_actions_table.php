<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Story 4-4 (tâche 2.1) — Drop de la table orpheline `workstation_scheduled_actions`.
 *
 * La table créée par 2026_03_16_100000 n'a jamais été utilisée par aucun modèle,
 * service ni UI. Son schéma (`day` VARCHAR unitaire, pas de `days_of_week` ARRAY,
 * pas de timezone, pas d'audit, pas de mode `one_shot`) est incompatible avec la
 * représentation retenue (D3 + D7). Drop idempotent — safe si déjà absente.
 *
 * Rollback : recrée le schéma d'origine (pour éviter un migrate:rollback cassé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('workstation_scheduled_actions');
    }

    public function down(): void
    {
        // Rollback cosmétique : on reconstruit le schéma orphelin pour ne pas
        // casser un éventuel migrate:rollback. Personne ne devrait revenir
        // dessus — la table n'a jamais servi.
        Schema::create('workstation_scheduled_actions', function ($table) {
            $table->id();
            $table->foreignId('workstation_group_id')
                ->constrained('workstation_groups')
                ->onDelete('cascade');
            $table->string('action', 30);
            $table->string('day', 10);
            $table->time('time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['workstation_group_id', 'day', 'time'], 'wsa_group_day_time_unique');
        });
    }
};
