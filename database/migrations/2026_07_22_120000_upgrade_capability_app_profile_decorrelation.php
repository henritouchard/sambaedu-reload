<?php

use App\Models\Capability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 36.7 — mise à niveau de la capacité de catalogue `roaming_app_profile`
 * (seedée en 36.5) SANS toucher le schéma :
 *
 *  1. **`enabled: true` par entrée du catalogue (AC2).** Chaque app du `spec`
 *     (`apps[]`) gagne le booléen d'activation par entrée (« off réel » : une
 *     entrée `enabled:false` n'émet plus d'item sans supprimer physiquement la
 *     ligne — la suppression orphelinerait les profils déjà posés sur les homes).
 *     Idempotent : n'ajoute `enabled` qu'aux entrées qui n'en portent pas, et
 *     PRÉSERVE toute entrée ajoutée entre-temps via l'UI (Story 36.7, AC1).
 *
 *  2. **Warning DÉCORRÉLÉ du gate K: (AC3).** Le warning 36.5 pointait la
 *     dépendance au montage du home K: (« si le home est désactivé, la
 *     redirection n'a aucun effet »). Cette dépendance est SUPPRIMÉE (le lien
 *     pointe l'UNC direct, Firefox le traverse — K: est cosmétique). Le nouveau
 *     texte énonce la finalité + la limite d'honnêteté (rien dans l'Explorateur
 *     ≠ inaccessible). ≤ 255 (contrainte varchar PG — invisible en SQLite).
 *
 *  3. **`options` on/off (AC4).** La capacité `toggle` gagne ses deux options
 *     étiquetées — sans elles, la section « Capacités » d'un groupe
 *     d'utilisateurs afficherait un champ texte au lieu d'un sélecteur on/off
 *     (patron toggle iso `windows_store_disabled`). `default_value` reste `on`
 *     (comportement 36.5 préservé au déploiement — le basculer à `off` inverse la
 *     politique sans code).
 *
 * `update()` via Query Builder (n'émet AUCUN événement Eloquent — l'observer
 * d'authoring 36.5 n'est donc pas déclenché ; le catalogue seedé est déjà propre).
 * `down()` : réversion best-effort du warning et des options (le champ `enabled`
 * est laissé — inoffensif, défaut `true`).
 */
return new class extends Migration
{
    private const KEY = 'roaming_app_profile';

    private const WARNING = 'Suit les signets et préférences Firefox/Thunderbird de l\'utilisateur '
        .'d\'un poste à l\'autre, via son home réseau — indépendamment du lecteur K: (la '
        .'redirection fonctionne même si le home est masqué dans l\'Explorateur). Activez par '
        .'groupe d\'utilisateurs.';

    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $capabilityId = DB::table('capabilities')->where('key', self::KEY)->value('id');
        if ($capabilityId === null) {
            return; // seed 36.5 non joué (instance greenfield sans ce catalogue).
        }

        // (2)+(3) Warning décorrélé + options on/off. `default_value` inchangé (`on`).
        DB::table('capabilities')->where('id', $capabilityId)->update([
            'warning' => self::WARNING,
            'options' => json_encode([
                ['value' => Capability::TOGGLE_ON, 'label' => 'Activé'],
                ['value' => Capability::TOGGLE_OFF, 'label' => 'Désactivé'],
            ], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        // (1) `enabled: true` par entrée — idempotent, préserve les entrées UI.
        $projection = DB::table('capability_projections')
            ->where('capability_id', $capabilityId)
            ->where('os', 'windows')
            ->where('mechanism', 'app_profile')
            ->first();
        if ($projection === null) {
            return;
        }

        $spec = json_decode((string) $projection->spec, true);
        if (! is_array($spec) || ! isset($spec['apps']) || ! is_array($spec['apps'])) {
            return;
        }

        $spec['apps'] = array_map(static function ($app): mixed {
            if (is_array($app) && ! array_key_exists('enabled', $app)) {
                $app['enabled'] = true;
            }

            return $app;
        }, $spec['apps']);

        DB::table('capability_projections')->where('id', $projection->id)->update([
            'spec' => json_encode($spec, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('capabilities')) {
            return;
        }

        // Réversion best-effort : warning 36.5 (dépendance K:) + options nulle.
        // Le champ `enabled` du spec est laissé (inoffensif, défaut `true`).
        DB::table('capabilities')->where('key', self::KEY)->update([
            'warning' => 'Dépend du montage du home réseau K: (politique de gestion des fichiers, '
                ."/admin/settings/files) : si le home est désactivé, la redirection n'a aucun effet. "
                .'Le cache est épinglé en local ; les bases (signets, préférences) vivent sur le home réseau.',
            'options' => null,
            'updated_at' => now(),
        ]);
    }
};
