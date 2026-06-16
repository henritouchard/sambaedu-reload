<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3 — Set initial du catalogue (D-Q1, 3 réglages choisis parmi les GPO
 * SambaEdu, tous vérifiables sans infra). IDEMPOTENT : `updateOrInsert` par `key`
 * (rejouable, zéro doublon). Le catalogue grossit ensuite par DATA (zéro release
 * agent — payoff de la couture générique).
 *
 * Deux HKCU (compagnon, effet Explorer immédiat) + un HKLM (SYSTEM) → couvre les
 * deux portées pour valider les deux providers ET les deux moteurs Go.
 *
 * « Désactiver un réglage » = cesser de le gérer (item ABSENT) ; jamais de reset
 * OFF explicite (limite connue, contrat §8 — type/clé absent = non géré).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registry_settings')) {
            return;
        }

        $now = now();

        $catalog = [
            [
                'key' => 'show_file_extensions',
                'label' => 'Afficher les extensions de fichiers',
                'description' => 'Affiche l\'extension des fichiers connus dans l\'Explorateur (GPO « optimisations »/Bureau).',
                'hive' => 'HKCU',
                'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced',
                'name' => 'HideFileExt',
                'type' => 'REG_DWORD',
                'value' => '0',
            ],
            [
                'key' => 'show_hidden_files',
                'label' => 'Afficher les fichiers cachés',
                'description' => 'Affiche les fichiers et dossiers cachés dans l\'Explorateur (GPO « optimisations »/Bureau).',
                'hive' => 'HKCU',
                'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced',
                'name' => 'Hidden',
                'type' => 'REG_DWORD',
                'value' => '1',
            ],
            [
                'key' => 'disable_uac',
                'label' => 'Désactiver l\'UAC',
                'description' => 'Désactive le contrôle de compte d\'utilisateur (GPO « desactivation uac »). Réglage machine.',
                'hive' => 'HKLM',
                'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System',
                'name' => 'EnableLUA',
                'type' => 'REG_DWORD',
                'value' => '0',
            ],
        ];

        foreach ($catalog as $row) {
            DB::table('registry_settings')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, [
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]),
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('registry_settings')) {
            return;
        }

        // La FK `registry_setting_id` du pivot est en cascadeOnDelete : supprimer
        // ces réglages retire AUSSI leurs assignations de parc (les postes
        // cesseront de recevoir l'item au cycle suivant). Acceptable — zéro prod
        // (mémoire zero_prod_publish_is_test), aucune donnée à préserver.
        DB::table('registry_settings')
            ->whereIn('key', ['show_file_extensions', 'show_hidden_files', 'disable_uac'])
            ->delete();
    }
};
