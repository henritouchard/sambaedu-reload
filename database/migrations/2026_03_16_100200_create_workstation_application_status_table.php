<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration pour le statut de déploiement WPKG par poste
 *
 * Remplace la table `poste_app` du legacy MySQL.
 * Stocke l'état d'installation de chaque application sur chaque poste,
 * tel que rapporté par le client WPKG au démarrage Windows.
 *
 * À ne pas confondre avec `installation_logs` qui trace les téléchargements
 * depuis les dépôts vers le serveur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_application_status', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workstation_id')
                ->constrained('workstations')
                ->onDelete('cascade');

            $table->foreignId('application_id')
                ->constrained('applications')
                ->onDelete('cascade');

            // Version installée sur le poste
            $table->string('installed_version', 255)->nullable();

            // Statut WPKG
            $table->string('status', 20)
                ->comment('installed, not-installed, error, upgrading, downgrading, unknown');

            $table->boolean('reboot_required')->default(false);

            // Horodatage du dernier rapport WPKG reçu
            $table->timestamp('reported_at')->nullable()
                ->comment('Dernière mise à jour par le rapport WPKG du poste');

            $table->timestamps();

            // Un seul statut par poste/application
            $table->unique(['workstation_id', 'application_id'], 'was_workstation_app_unique');

            $table->index('workstation_id');
            $table->index('application_id');
            $table->index('status');
            $table->index('reported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_application_status');
    }
};
