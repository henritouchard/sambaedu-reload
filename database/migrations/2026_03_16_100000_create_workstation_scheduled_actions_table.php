<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration pour les actions planifiées sur les groupes de postes
 *
 * Remplace la table `actions` du legacy MySQL.
 * Permet de programmer des allumages/extinctions/redémarrages
 * sur un WorkstationGroup à un jour et une heure donnés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_scheduled_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workstation_group_id')
                ->constrained('workstation_groups')
                ->onDelete('cascade');

            // Action à effectuer
            $table->string('action', 30)
                ->comment('wol (allumage), stop (extinction), reboot (redémarrage)');

            // Planification
            $table->string('day', 10)
                ->comment('Jour : l (lundi), ma, me, j, v, s, d — ou "all" pour tous les jours');
            $table->time('time')
                ->comment('Heure d\'exécution');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Un seul événement par groupe/jour/heure (cohérent avec le legacy)
            $table->unique(['workstation_group_id', 'day', 'time'], 'wsa_group_day_time_unique');

            $table->index('workstation_group_id');
            $table->index('is_active');
            $table->index(['day', 'time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_scheduled_actions');
    }
};
