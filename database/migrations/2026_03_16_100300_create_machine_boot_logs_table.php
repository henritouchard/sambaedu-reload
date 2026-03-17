<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration pour les logs de démarrage/extinction des postes
 *
 * Remplace la table `machines` du legacy MySQL.
 * Chaque ligne représente un cycle de vie d'un poste (démarrage → extinction).
 * Utilisé pour le monitoring, les statistiques de boot, et le diagnostic réseau.
 *
 * La FK vers workstations est nullable pour conserver les logs historiques
 * même si le poste est supprimé ultérieurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_boot_logs', function (Blueprint $table) {
            $table->id();

            // Référence au poste (nullable pour conserver l'historique)
            $table->foreignId('workstation_id')
                ->nullable()
                ->constrained('workstations')
                ->onDelete('set null');

            // Nom de la machine au moment du log (dénormalisé pour l'historique)
            $table->string('machine_name', 100)
                ->comment('Nom du poste au moment de l\'événement');

            // Cycle de vie
            $table->timestamp('started_at')->nullable()->comment('Heure de démarrage');
            $table->timestamp('stopped_at')->nullable()->comment('Heure d\'extinction');

            // Système
            $table->string('os', 50)->nullable();

            // Scores et diagnostics
            $table->integer('wol_score')->nullable()->comment('Score Wake-on-LAN');
            $table->integer('ipxe_score')->nullable()->comment('Score iPXE');
            $table->integer('error_flags')->nullable()->comment('Bitmask des erreurs de démarrage');
            $table->integer('boot_speed')->nullable()->comment('Durée de démarrage en secondes');

            // Réseau
            $table->string('vlan', 10)->nullable();
            $table->string('switch_port', 30)->nullable();
            $table->string('switch_ip', 45)->nullable();
            $table->string('switch_name', 50)->nullable();

            $table->timestamps();

            $table->index('workstation_id');
            $table->index('machine_name');
            $table->index('started_at');
            $table->index('stopped_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_boot_logs');
    }
};
