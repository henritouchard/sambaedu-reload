<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3ter — « gel » d'un réglage du catalogue : `overrides_locked`
 * (booléen, défaut false).
 *
 * GELER = VERROUILLER L'AJOUT DE NOUVEAUX OVERRIDES, sans rien cesser de gérer :
 *   - un réglage gelé n'est plus PROPOSÉ à l'ajout sur les parcs qui ne le
 *     dévient pas encore (retiré de `addableSettings`) ;
 *   - les parcs qui le dévient DÉJÀ gardent leur override (visible, éditable,
 *     retirable) ;
 *   - la DIFFUSION est INCHANGÉE (le provider continue d'émettre le défaut
 *     Broadcast + les overrides) → AUCUN poste ne se fige (pas de stranding).
 *
 * À NE PAS CONFONDRE avec `is_active` (qui, lui, coupe la diffusion). Le vrai
 * décommissionnement (cesser de gérer une clé) exige une convergence observée de
 * la flotte → story de suivi dédiée, hors 27.3ter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registry_settings')) {
            return;
        }

        Schema::table('registry_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('registry_settings', 'overrides_locked')) {
                $table->boolean('overrides_locked')
                    ->default(false)
                    ->after('is_active')
                    ->comment('Gelé = plus de NOUVEAUX overrides (diffusion inchangée) ; ≠ is_active (27.3ter)');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('registry_settings')) {
            return;
        }

        Schema::table('registry_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('registry_settings', 'overrides_locked')) {
                $table->dropColumn('overrides_locked');
            }
        });
    }
};
