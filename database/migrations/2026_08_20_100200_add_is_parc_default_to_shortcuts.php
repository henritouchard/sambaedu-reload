<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raccourci posé sur TOUS les postes, sans passer par une assignation.
 *
 * Pendant exact de `applications.is_parc_default` et de `wallpapers.is_default` :
 * la page « Configuration par défaut du parc » réunit ce qui s'applique à toute la
 * flotte. Les raccourcis y manquaient — un raccourci ne pouvait viser que des
 * cibles nommées, si bien qu'un parc créé plus tard ne l'héritait jamais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortcuts', function (Blueprint $table): void {
            $table->boolean('is_parc_default')->default(false)
                ->comment('Raccourci appliqué à tous les postes sans assignation (défaut de parc)');
            $table->index('is_parc_default', 'shortcuts_is_parc_default_index');
        });
    }

    public function down(): void
    {
        Schema::table('shortcuts', function (Blueprint $table): void {
            $table->dropIndex('shortcuts_is_parc_default_index');
            $table->dropColumn('is_parc_default');
        });
    }
};
