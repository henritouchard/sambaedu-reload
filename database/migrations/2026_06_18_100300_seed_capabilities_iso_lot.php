<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.12 (AC5) — seed du LOT ISO de capacités + migration des 3 réglages
 * 27.3ter vers le modèle capability-first.
 *
 * IDEMPOTENT : `updateOrInsert` par `key` pour la capacité, puis `updateOrInsert`
 * par `(capability_id, os, mechanism)` pour la projection registry (rejouable,
 * zéro doublon). Source autoritaire des valeurs = inventaire GPO décodé
 * (`project_legacy_gpo_registry_inventory`, templates `se4_*` JSON sur la VM).
 *
 * Modèle de la `spec` (D5) : `{ "keys": [ {hive, path, name, type, value}, … ] }`
 * où `value` est SOIT un littéral (toujours émis) SOIT une MAP valeur-capacité →
 * donnée (`{"on":0,"off":1}` ; clé absente ⇒ clé non émise = cesser de gérer).
 *
 * MIGRATION DES 3 EXISTANTS (27.3ter → 27.12) :
 *   - `show_file_extensions` (HideFileExt HKCU) : défaut `on` (= afficher, valeur 0) ;
 *   - `show_hidden_files`    (Hidden HKCU)      : défaut `on` (= afficher, valeur 1) ;
 *   - `uac_enabled`          (EnableLUA HKLM)   : défaut `on` (= UAC ACTIVÉ, valeur 1,
 *     posture sûre 27.3ter D6), warning conservé. (L'ancien `disable_uac` est
 *     reformulé positivement : « UAC activé » avec défaut on.)
 *
 * EXCLUS du lot (pièges n°6/n°7) : `windows_telemetry_off` (verbe legacy `**del.`
 * AllowTelemetry, non supporté par le handler) et `printers_point_and_print`
 * (substitution `%SE4FS%`).
 *
 * ⚠️ BUNDLE WindowsUpdate (`windows_updates_managed`) : la source autoritaire
 * (`/usr/share/sambaedu/gpo/sambaedu-gpo/se4_windows-update-ON/Machine/Registry.pol`)
 * est sur la VM, INACCESSIBLE depuis le worktree, et AUCUNE copie locale n'existe
 * dans le repo. Les clés ci-dessous sont une transcription PARTIELLE des clés
 * Windows Update / AU canoniques (sûres) ; le bundle complet (~34 clés) est À
 * COMPLÉTER par Henri depuis la source — cf. Dev Agent Record de la story.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        // Conventions réutilisées : maps de toggle pour les capacités à 2 états
        // (afficher/masquer, activé/désactivé) — UI pilotée par `options`.
        $optionsOnOff = json_encode([
            ['value' => 'on', 'label' => 'Activé'],
            ['value' => 'off', 'label' => 'Désactivé'],
        ], JSON_UNESCAPED_UNICODE);

        $optionsAfficherMasquer = json_encode([
            ['value' => 'on', 'label' => 'Afficher'],
            ['value' => 'off', 'label' => 'Masquer'],
        ], JSON_UNESCAPED_UNICODE);

        // Capacité on-only HONNÊTE : pas de valeur registre « off » possible sans le
        // verbe `delete` (exclu MVP, piège n°6). On n'expose donc PAS d'« off »
        // trompeur — le seul geste est « géré » (ou « Retirer » l'override = défaut).
        $optionsManagedOnly = json_encode([
            ['value' => 'on', 'label' => 'Géré'],
        ], JSON_UNESCAPED_UNICODE);

        $uacWarning = "Désactiver l'UAC (contrôle de compte d'utilisateur) : "
            . "trou de sécurité (tout processus admin s'exécute élevé sans invite), "
            . "casse le menu Démarrer / Paramètres sur Windows 10/11 (applications UWP), "
            . "et nécessite un redémarrage du poste.";

        $explorerAdvanced = 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced';

        // Chemins WindowsUpdate (machine) pour le bundle.
        $wuPath = 'SOFTWARE\\Policies\\Microsoft\\Windows\\WindowsUpdate';
        $auPath = 'SOFTWARE\\Policies\\Microsoft\\Windows\\WindowsUpdate\\AU';

        // ── LOT ISO ─────────────────────────────────────────────────────────
        // Chaque capacité = [meta, projection registry windows (spec.keys)].
        $lot = [
            [
                'key' => 'show_file_extensions',
                'label' => 'Afficher les extensions de fichiers',
                'description' => 'Affiche l\'extension des fichiers connus dans l\'Explorateur.',
                'category' => 'Bureau',
                'value_type' => 'toggle',
                'options' => $optionsAfficherMasquer,
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    ['hive' => 'HKCU', 'path' => $explorerAdvanced, 'name' => 'HideFileExt', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
                ],
            ],
            [
                'key' => 'show_hidden_files',
                'label' => 'Afficher les fichiers cachés',
                'description' => 'Affiche les fichiers et dossiers cachés dans l\'Explorateur.',
                'category' => 'Bureau',
                'value_type' => 'toggle',
                'options' => $optionsAfficherMasquer,
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    ['hive' => 'HKCU', 'path' => $explorerAdvanced, 'name' => 'Hidden', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                ],
            ],
            [
                'key' => 'uac_enabled',
                'label' => 'Contrôle de compte (UAC) activé',
                'description' => 'Contrôle de compte d\'utilisateur Windows. Réglage machine (redémarrage requis).',
                'category' => 'Sécurité',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'on', // posture sûre (27.3ter D6 : EnableLUA=1)
                'warning' => $uacWarning,
                'keys' => [
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System', 'name' => 'EnableLUA', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                ],
            ],
            [
                'key' => 'windows_consumer_features_off',
                'label' => 'Désactiver les fonctionnalités grand public',
                'description' => 'Bloque les suggestions/installations automatiques d\'applications grand public (Windows Consumer Features).',
                'category' => 'Optimisations',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    // symétrique : off réécrit 0 = fonctionnalités grand public réactivées (défaut Windows).
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Windows\\CloudContent', 'name' => 'DisableWindowsConsumerFeatures', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Windows\\CloudContent', 'name' => 'DisableSoftLanding', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                ],
            ],
            [
                'key' => 'windows_updates_managed',
                'label' => 'Mises à jour Windows gérées',
                'description' => 'Active la gestion des mises à jour Windows (planification / téléchargement automatique). '
                    . 'BUNDLE PARTIEL — clés WindowsUpdate à compléter depuis le GPO legacy se4_windows-update-ON (source VM).',
                'category' => 'Mises à jour',
                'value_type' => 'toggle',
                'options' => $optionsManagedOnly, // on-only honnête : pas d'« off » registre (cf. piège n°6).
                'default_value' => 'on',
                'warning' => null,
                // on-only : géré seulement si `on` ; « ne plus gérer » = retirer les clés
                // (verbe `delete`, hors MVP) → pas de valeur `off`. Transcription PARTIELLE
                // des clés Windows Update / AU canoniques — bundle complet à compléter (Henri).
                'keys' => [
                    ['hive' => 'HKLM', 'path' => $auPath, 'name' => 'NoAutoUpdate', 'type' => 'REG_DWORD', 'value' => ['on' => 0]],
                    ['hive' => 'HKLM', 'path' => $auPath, 'name' => 'AUOptions', 'type' => 'REG_DWORD', 'value' => ['on' => 4]],
                    ['hive' => 'HKLM', 'path' => $auPath, 'name' => 'ScheduledInstallDay', 'type' => 'REG_DWORD', 'value' => ['on' => 0]],
                    ['hive' => 'HKLM', 'path' => $auPath, 'name' => 'ScheduledInstallTime', 'type' => 'REG_DWORD', 'value' => ['on' => 3]],
                    ['hive' => 'HKLM', 'path' => $auPath, 'name' => 'NoAutoRebootWithLoggedOnUsers', 'type' => 'REG_DWORD', 'value' => ['on' => 1]],
                    ['hive' => 'HKLM', 'path' => $wuPath, 'name' => 'ElevateNonAdmins', 'type' => 'REG_DWORD', 'value' => ['on' => 0]],
                ],
            ],
            [
                'key' => 'offline_files_disabled',
                'label' => 'Fichiers hors connexion désactivés',
                'description' => 'Désactive le cache des fichiers hors connexion (NetCache). Postes en environnement local.',
                'category' => 'Optimisations',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    // symétrique : off réactive le cache hors-connexion (No*=0, Enabled=1 = défaut Windows).
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Windows\\NetCache', 'name' => 'NoCacheViewer', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Windows\\NetCache', 'name' => 'NoConfigCache', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Windows\\NetCache', 'name' => 'NoMakeAvailableOffline', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Windows\\NetCache', 'name' => 'Enabled', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
                ],
            ],
            [
                'key' => 'remote_desktop_enabled',
                'label' => 'Bureau à distance (RDP)',
                'description' => 'Autorise les connexions Bureau à distance (Terminal Services).',
                'category' => 'Sécurité',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    // fDenyTSConnections : 0 = RDP autorisé (on), 1 = refusé (off).
                    ['hive' => 'HKLM', 'path' => 'SYSTEM\\CurrentControlSet\\Control\\Terminal Server', 'name' => 'fDenyTSConnections', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
                ],
            ],
            [
                'key' => 'windows_copilot_off',
                'label' => 'Désactiver Windows Copilot',
                'description' => 'Masque/désactive l\'assistant Windows Copilot.',
                'category' => 'Optimisations',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    // symétrique : off réécrit 0 = Copilot réautorisé (défaut Windows).
                    ['hive' => 'HKCU', 'path' => 'Software\\Policies\\Microsoft\\Windows\\WindowsCopilot', 'name' => 'TurnOffWindowsCopilot', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                ],
            ],
            [
                'key' => 'onedrive_hidden',
                'label' => 'Masquer OneDrive de l\'Explorateur',
                'description' => 'Retire l\'entrée OneDrive de l\'arbre de navigation de l\'Explorateur.',
                'category' => 'Bureau',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    // HKCR routé en HKCU\Software\Classes (vue per-user, écrite par le
                    // compagnon de session — cf. RegistryUserCapabilityProvider).
                    // symétrique : on (masquer) = 0, off (afficher) = 1 (System.IsPinnedToNameSpaceTree).
                    ['hive' => 'HKCU', 'path' => 'Software\\Classes\\CLSID\\{018D5C66-4533-4307-9B53-224DE2ED1FE6}', 'name' => 'System.IsPinnedToNameSpaceTree', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
                ],
            ],
        ];

        foreach ($lot as $row) {
            DB::table('capabilities')->updateOrInsert(
                ['key' => $row['key']],
                [
                    'label' => $row['label'],
                    'description' => $row['description'],
                    'category' => $row['category'],
                    'value_type' => $row['value_type'],
                    'options' => $row['options'],
                    'default_value' => $row['default_value'],
                    'warning' => $row['warning'],
                    'applies_to_os' => json_encode(['windows'], JSON_UNESCAPED_UNICODE),
                    'is_active' => true,
                    'overrides_locked' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            $capabilityId = DB::table('capabilities')->where('key', $row['key'])->value('id');
            if ($capabilityId === null) {
                continue;
            }

            DB::table('capability_projections')->updateOrInsert(
                [
                    'capability_id' => $capabilityId,
                    'os' => 'windows',
                    'mechanism' => 'registry',
                ],
                [
                    'spec' => json_encode(['keys' => $row['keys']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('capabilities')) {
            return;
        }

        // La FK `capability_id` des projections/assignments est en cascadeOnDelete :
        // supprimer les capacités du lot retire leurs projections + overrides. Zéro
        // prod, aucune donnée à préserver.
        DB::table('capabilities')->whereIn('key', [
            'show_file_extensions',
            'show_hidden_files',
            'uac_enabled',
            'windows_consumer_features_off',
            'windows_updates_managed',
            'offline_files_disabled',
            'remote_desktop_enabled',
            'windows_copilot_off',
            'onedrive_hidden',
        ])->delete();
    }
};
