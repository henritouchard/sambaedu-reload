<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clé de l'item de contrat amont qui a matérialisé ce raccourci.
 *
 * Marqueur de provenance ET clé de réconciliation. `is_global` ne pouvait pas
 * jouer ce rôle : il marque déjà les raccourcis posés par le canal de tâches
 * historique (`SyncShortcutJob`), et le désir d'état du contrat y aurait supprimé
 * des raccourcis venus d'ailleurs. Une colonne dédiée borne le prune aux seules
 * lignes que le contrat a lui-même créées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortcuts', function (Blueprint $table): void {
            $table->string('controlhub_contract_key')->nullable()
                ->comment('Clé de l\'item du contrat amont qui a matérialisé ce raccourci ; null = origine locale');
            $table->index('controlhub_contract_key', 'shortcuts_chc_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('shortcuts', function (Blueprint $table): void {
            $table->dropIndex('shortcuts_chc_key_index');
            $table->dropColumn('controlhub_contract_key');
        });
    }
};
