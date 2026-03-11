<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration {
    /**
     * Supprime la table legacy 'parc' et toutes ses dépendances.
     * 
     * Cette migration marque la transition complète vers le nouveau modèle
     * workstation_groups. Les données seront resynchronisées depuis l'AD
     * qui est la source de vérité.
     * 
     * Tables supprimées :
     * - parc_profile (pivot postes ↔ parcs)
     * - applications_profile (associations apps ↔ entités, legacy polymorphique)
     * - parc (table principale legacy)
     */
    public function up(): void
    {
        // Log pour traçabilité
        Log::info('Migration: Suppression de la table legacy parc et ses dépendances');

        // 1. Supprimer la table pivot parc_profile
        if (Schema::hasTable('parc_profile')) {
            $count = DB::table('parc_profile')->count();
            Log::info("Suppression de parc_profile ({$count} lignes)");
            Schema::dropIfExists('parc_profile');
        }

        // 2. Supprimer la table applications_profile (système polymorphique legacy)
        if (Schema::hasTable('applications_profile')) {
            $count = DB::table('applications_profile')->count();
            Log::info("Suppression de applications_profile ({$count} lignes)");
            Schema::dropIfExists('applications_profile');
        }

        // 3. Supprimer la table principale parc
        if (Schema::hasTable('parc')) {
            $count = DB::table('parc')->count();
            Log::info("Suppression de parc ({$count} lignes)");
            Schema::dropIfExists('parc');
        }

        Log::info('Migration: Tables legacy supprimées. Resynchroniser depuis l\'AD via les services.');
    }

    /**
     * Reverse the migrations.
     * 
     * ATTENTION: Le rollback n'est pas supporté car il nécessiterait
     * de recréer toute la structure legacy. En cas de problème,
     * restaurer depuis une sauvegarde de la base de données.
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'Le rollback de cette migration n\'est pas supporté. ' .
            'Restaurer depuis une sauvegarde de la base de données si nécessaire.'
        );
    }
};
