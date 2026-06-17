<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3ter — OVERRIDE de valeur par parc sur le pivot d'assignation
 * `registry_setting_assignables` (D2).
 *
 * Évolution sémantique du pivot 27.3 : une ligne ne signifie plus « activer la
 * gestion » mais « ce parc DÉVIE ce réglage vers CETTE valeur ». La nouvelle
 * colonne `value` (texte, NULLABLE, même sérialisation que `registry_settings
 * .value` — DWORD/QWORD décimal, MULTI_SZ JSON array, SZ/EXPAND_SZ littéral)
 * porte l'override.
 *
 * `value = null` ⇒ pas de déviation : le provider replie sur le défaut catalogue
 * (override inerte, no-op). Couvre les assignations 27.3 résiduelles (pivot sans
 * `value`) sans erreur — AC1.
 *
 * La précédence existante (`logique > physique > broadcast`, D-Q3 de 27.3) fait
 * que l'override par maille bat le défaut Broadcast pour cette clé — AUCUNE
 * nouvelle logique de précédence (StateCompiler inchangé).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registry_setting_assignables')) {
            return;
        }
        if (Schema::hasColumn('registry_setting_assignables', 'value')) {
            return;
        }

        Schema::table('registry_setting_assignables', function (Blueprint $table): void {
            // Texte nullable, cohérent avec registry_settings.value. Placée après
            // morphs (assignable_type/_id). NULL = pas de déviation (repli défaut).
            $table->text('value')
                ->nullable()
                ->after('assignable_type')
                ->comment('Override de valeur du réglage pour ce parc — même sérialisation que registry_settings.value ; NULL = repli sur le défaut catalogue (27.3ter)');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('registry_setting_assignables')) {
            return;
        }
        if (! Schema::hasColumn('registry_setting_assignables', 'value')) {
            return;
        }

        Schema::table('registry_setting_assignables', function (Blueprint $table): void {
            $table->dropColumn('value');
        });
    }
};
