<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3ter — métadonnées d'éditeur au catalogue `registry_settings` :
 *   - `options` (JSON/texte NULLABLE) : choix d'un réglage à valeur FERMÉE,
 *     forme `[{ "value": "0", "label": "Afficher" }, …]`. Présent ⇒ l'UI rend un
 *     sélecteur/toggle (libellés lisibles) et valide la valeur ∈ valeurs
 *     autorisées ; `null` = saisie libre selon le `type`. N'AFFECTE PAS le
 *     payload (détail UI/validation).
 *   - `warning` (texte NULLABLE — D7) : message d'implications affiché AU
 *     DÉCLENCHEMENT du réglage (ajout/édition d'override côté parc ET édition du
 *     défaut côté serveur), en encart de confirmation explicite avant
 *     persistance. `null` = pas d'encart (réglage inoffensif). N'AFFECTE PAS le
 *     payload (détail UI).
 *
 * La valeur par défaut diffusée à toute la flotte reste `registry_settings.value`
 * (Broadcast) — voir 27.3ter D1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registry_settings')) {
            return;
        }

        Schema::table('registry_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('registry_settings', 'options')) {
                // JSON nullable : portable PG/SQLite (cast array côté modèle).
                $table->json('options')
                    ->nullable()
                    ->after('value')
                    ->comment('Choix d\'un réglage à valeur fermée [{value,label}] ; NULL = saisie libre selon le type (27.3ter)');
            }
            if (! Schema::hasColumn('registry_settings', 'warning')) {
                $table->text('warning')
                    ->nullable()
                    ->after('options')
                    ->comment('Message d\'implications affiché au déclenchement (confirmation explicite) ; NULL = pas d\'encart (27.3ter D7)');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('registry_settings')) {
            return;
        }

        Schema::table('registry_settings', function (Blueprint $table): void {
            foreach (['warning', 'options'] as $col) {
                if (Schema::hasColumn('registry_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
