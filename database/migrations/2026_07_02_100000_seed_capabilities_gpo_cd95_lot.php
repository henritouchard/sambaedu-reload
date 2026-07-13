<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot « GPO spéciales CD95 » — transformation des GPO ad-hoc du CD95 en capacités
 * (modèle capability-first, cf. lot ISO 27.12 dont ce seed reprend le pattern EXACT :
 * `updateOrInsert` par `key` puis par `(capability_id, os, mechanism)`, idempotent).
 *
 * Source : arborescence GPO décodée (../GPO_spécialesCD95). Ne sont seedées ici que
 * les capacités PROJETABLES par le modèle actuel (mécanisme `registry`, ruches
 * HKLM/HKCU, types DWORD/SZ, valeur littérale ou MAP valeur-capacité → donnée).
 *
 * ── DÉFAUTS DE DIFFUSION ────────────────────────────────────────────────────
 * Deux régimes :
 *   1. Hardening fleet-wide (iso convention du lot ISO : `default_value = on`,
 *      appliqué à toute la flotte) → news/LLMNR/OnlyOffice/numlock ;
 *   2. Opt-in par maille (`default_value = 'unmanaged'`, valeur ABSENTE de la map
 *      ⇒ rien n'est émis en broadcast — cf. `AbstractCapabilityStateProvider::
 *      resolveKeyValue`, sentinelle UNMANAGED). Le geste est alors un OVERRIDE par
 *      parc / groupe utilisateur (`capability_assignments`). Utilisé pour les
 *      capacités contextuelles (masquage lecteurs, action capot, ouvertures en
 *      cache) et pour les capacités CIBLÉES PAR GROUPE (Outlook, blocages élèves).
 *
 * ── SCALAR NON PROJETABLE ───────────────────────────────────────────────────
 * `CachedLogonsCount` est un nombre libre, mais l'interpréteur de `spec` ne sait
 * faire que littéral | map (pas de passthrough de la valeur saisie). On le modélise
 * donc en `enum` de préréglages (10/25/50) → passe par la map. Un vrai `scalar`
 * paramétrable nécessiterait d'étendre l'interpréteur (hors lot).
 *
 * ── EXCLUS DU LOT (nécessitent une évolution moteur — cf. analyse CD95) ──────
 *   - ExtensionInstallForcelist (Pix Chrome/Edge) & DisallowRun (élèves) : listes
 *     à SOUS-CLÉS INDEXÉES `\1 \2` — non supportées (seul REG_MULTI_SZ mono-valeur
 *     existe). Palier B.
 *   - SeDenyRemoteInteractiveLogonRight (blocage RDP élèves) : Privilege Right LSA,
 *     PAS du registre → nouveau mécanisme `secedit`. Palier C.
 *   - DisableCMD (`HKCU\Software\Policies\...\System`) : ruche HKCU\Policies NON
 *     écrivable par le companion de session (cf. lesson HKCU Policies / fix Copilot).
 *     Nécessite une résolution HKLM comme `windows_copilot_off`. Palier B.
 *   - Restauration Windows Photo Viewer (HKCR command EXPAND_SZ) : relève du modèle
 *     « associations / application par défaut », pas d'un toggle de capacité.
 *   - Numlock écran de logon (`HKU\.DEFAULT`) : ruche ni HKLM ni HKCU → non projetée
 *     (seule la partie HKCU par session est seedée ci-dessous).
 *
 * ── CIBLAGE PAR GROUPE (décision produit : assignment UserGroup) ─────────────
 * Outlook (Direction/Secrétariat/VieScol) et blocage regedit (élèves) sont ciblés
 * par GROUPE : ce seed crée les capacités ASSIGNABLES (défaut `unmanaged` = rien en
 * broadcast) ; le rattachement effectif à des UserGroups (IDs propres à chaque
 * établissement) est de la DONNÉE + un geste UI UserGroup — story de suivi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        $optionsOnOff = json_encode([
            ['value' => 'on', 'label' => 'Activé'],
            ['value' => 'off', 'label' => 'Désactivé'],
        ], JSON_UNESCAPED_UNICODE);

        // Opt-in : 1er choix = « Non géré » (sentinelle `unmanaged` hors map → rien
        // n'est émis en broadcast) ; le 2e choix arme la capacité par override.
        $optionsUnmanagedOn = json_encode([
            ['value' => 'unmanaged', 'label' => 'Non géré'],
            ['value' => 'on', 'label' => 'Activé'],
        ], JSON_UNESCAPED_UNICODE);

        // Tree restrictions user (HKCU\Software\Microsoft\...\CurrentVersion\Policies).
        // ⚠️ Commentaire d'origine FAUX (« écrivable par le companion,
        // CONTRAIREMENT à HKCU\Software\Policies ») corrigé par la Story 35.7 :
        // sur poste joint au domaine, TOUT `HKCU\…\Policies\*` — y compris
        // CurrentVersion\Policies — est en LECTURE SEULE pour l'utilisateur
        // standard. Les clés Session de ce tree sont appliquées par le SERVICE
        // SYSTEM via `writer: system` (retrofit 2026_07_13_100000).
        $userPoliciesExplorer = 'Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer';
        $userPoliciesSystem = 'Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\System';

        $lot = [
            // ── Hardening fleet-wide (default on) ────────────────────────────
            [
                'key' => 'news_and_interests_off',
                'label' => 'Désactiver Actualités et centres d\'intérêt',
                'description' => 'Coupe le widget « Actualités et centres d\'intérêt » de la barre des tâches, les widgets de l\'écran de verrouillage et les flux (Windows Feeds).',
                'category' => 'Optimisations',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Dsh', 'name' => 'AllowNewsAndInterests', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Dsh', 'name' => 'DisableWidgetsOnLockScreen', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Windows\\Windows Feeds', 'name' => 'EnableFeeds', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
                ],
            ],
            [
                'key' => 'llmnr_disabled',
                'label' => 'Désactiver LLMNR',
                'description' => 'Durcissement réseau : désactive la résolution de noms multidiffusion (LLMNR) et force le NetBIOS node-type P (pas de diffusion).',
                'category' => 'Sécurité',
                // Managed-only (iso windows_updates_managed) : NodeType ne peut pas
                // restaurer proprement sa valeur d'origine (dépend du réseau) sans le
                // verbe `delete` → on n'expose PAS d'« off » trompeur (invariant : un
                // « off » proposé doit écrire une vraie valeur, pas un no-op).
                'value_type' => 'toggle',
                'options' => json_encode([['value' => 'on', 'label' => 'Géré']], JSON_UNESCAPED_UNICODE),
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Windows NT\\DNSClient', 'name' => 'EnableMulticast', 'type' => 'REG_DWORD', 'value' => ['on' => 0]],
                    ['hive' => 'HKLM', 'path' => 'SYSTEM\\CurrentControlSet\\Services\\NetBT\\Parameters', 'name' => 'NodeType', 'type' => 'REG_DWORD', 'value' => ['on' => 2]],
                ],
            ],
            [
                'key' => 'onlyoffice_auto_update_off',
                'label' => 'Désactiver la mise à jour auto OnlyOffice',
                'description' => 'Empêche OnlyOffice Desktop Editors de vérifier/télécharger ses mises à jour (parc à version figée).',
                'category' => 'Mises à jour',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\ONLYOFFICE\\DesktopEditors', 'name' => 'CheckForUpdates', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
                ],
            ],
            [
                'key' => 'numlock_on_logon',
                'label' => 'Verrouillage numérique à l\'ouverture de session',
                'description' => 'Active le pavé numérique (NumLock) à l\'ouverture de la session utilisateur.',
                'category' => 'Bureau',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'on',
                'warning' => null,
                'keys' => [
                    // Control Panel\Keyboard : tree utilisateur STANDARD (pas \Policies)
                    // → écrivable par le companion de session. La partie écran de logon
                    // (HKU\.DEFAULT) n'est PAS projetée (ruche hors HKLM/HKCU).
                    ['hive' => 'HKCU', 'path' => 'Control Panel\\Keyboard', 'name' => 'InitialKeyboardIndicators', 'type' => 'REG_SZ', 'value' => ['on' => '2', 'off' => '0']],
                ],
            ],

            // ── Enablers / hardening opt-in (default off, symétrique) ────────
            [
                'key' => 'appx_special_profiles_allowed',
                'label' => 'Autoriser le déploiement Appx dans les profils spéciaux',
                'description' => 'Permet le déploiement d\'applications Appx/UWP dans les profils spéciaux (obligatoires / itinérants).',
                'category' => 'Optimisations',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'off',
                'warning' => null,
                'keys' => [
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Windows\\Appx', 'name' => 'AllowDeploymentInSpecialProfiles', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                ],
            ],
            [
                'key' => 'hide_last_username',
                'label' => 'Masquer le dernier utilisateur connecté',
                'description' => 'N\'affiche pas le nom du dernier compte connecté sur l\'écran d\'ouverture de session (l\'utilisateur saisit son identifiant).',
                'category' => 'Sécurité',
                'value_type' => 'toggle',
                'options' => $optionsOnOff,
                'default_value' => 'off',
                'warning' => null,
                'keys' => [
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System', 'name' => 'DontDisplayLastUserName', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                ],
            ],

            // ── Contextuel par parc : opt-in via enum (default unmanaged) ────
            [
                'key' => 'hide_drives',
                'label' => 'Masquer des lecteurs dans l\'Explorateur',
                'description' => 'Masque certains lecteurs dans « Ce PC » (l\'accès reste possible en tapant le chemin). Valeur bitmask NoDrives.',
                'category' => 'Bureau',
                'value_type' => 'enum',
                'options' => json_encode([
                    ['value' => 'unmanaged', 'label' => 'Non géré'],
                    ['value' => 'none', 'label' => 'Ne rien masquer'],
                    ['value' => 'c', 'label' => 'Masquer C'],
                    ['value' => 'cl', 'label' => 'Masquer C et L'],
                ], JSON_UNESCAPED_UNICODE),
                'default_value' => 'unmanaged',
                'warning' => null,
                'keys' => [
                    // HKCU\...\CurrentVersion\Policies\Explorer : tree restrictions
                    // user-writable. bitmask : C=4, C+L=2052, aucun=0.
                    ['hive' => 'HKCU', 'path' => $userPoliciesExplorer, 'name' => 'NoDrives', 'type' => 'REG_DWORD', 'value' => ['none' => 0, 'c' => 4, 'cl' => 2052]],
                ],
            ],
            [
                'key' => 'laptop_lid_action',
                'label' => 'Action à la fermeture du capot',
                'description' => 'Définit l\'action à la fermeture du capot (secteur ET batterie) pour les portables.',
                'category' => 'Optimisations',
                'value_type' => 'enum',
                'options' => json_encode([
                    ['value' => 'unmanaged', 'label' => 'Non géré'],
                    ['value' => 'do_nothing', 'label' => 'Ne rien faire'],
                    ['value' => 'sleep', 'label' => 'Veille'],
                    ['value' => 'hibernate', 'label' => 'Veille prolongée'],
                    ['value' => 'shutdown', 'label' => 'Arrêter'],
                ], JSON_UNESCAPED_UNICODE),
                'default_value' => 'unmanaged',
                'warning' => null,
                'keys' => [
                    // GUID lid-close-action ; AC = secteur, DC = batterie. 0=rien,1=veille,2=hibernation,3=arrêt.
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Power\\PowerSettings\\5CA83367-6E45-459F-A27B-476B1D01C936', 'name' => 'ACSettingIndex', 'type' => 'REG_DWORD', 'value' => ['do_nothing' => 0, 'sleep' => 1, 'hibernate' => 2, 'shutdown' => 3]],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Power\\PowerSettings\\5CA83367-6E45-459F-A27B-476B1D01C936', 'name' => 'DCSettingIndex', 'type' => 'REG_DWORD', 'value' => ['do_nothing' => 0, 'sleep' => 1, 'hibernate' => 2, 'shutdown' => 3]],
                ],
            ],
            [
                'key' => 'cached_logons_count',
                'label' => 'Ouvertures de session en cache (hors DC)',
                'description' => 'Nombre d\'ouvertures de session mises en cache pour ouvrir une session sans contrôleur de domaine joignable. Préréglages (scalaire libre non supporté par le modèle).',
                'category' => 'Sécurité',
                'value_type' => 'enum',
                'options' => json_encode([
                    ['value' => 'unmanaged', 'label' => 'Non géré'],
                    ['value' => '10', 'label' => '10 (défaut Windows)'],
                    ['value' => '25', 'label' => '25'],
                    ['value' => '50', 'label' => '50'],
                ], JSON_UNESCAPED_UNICODE),
                'default_value' => 'unmanaged',
                'warning' => null,
                'keys' => [
                    // CachedLogonsCount est un REG_SZ (chaîne numérique).
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon', 'name' => 'CachedLogonsCount', 'type' => 'REG_SZ', 'value' => ['10' => '10', '25' => '25', '50' => '50']],
                ],
            ],

            // ── Ciblé par groupe (opt-in, on-only) : override UserGroup ──────
            [
                'key' => 'outlook_disable_o365_account_creation',
                'label' => 'Bloquer la création de compte Office 365 simplifiée (Outlook)',
                'description' => 'Empêche l\'assistant Outlook de proposer la création de compte Microsoft 365 simplifiée. Cible : personnels (Direction/Secrétariat/Vie scolaire) via override de groupe.',
                'category' => 'Optimisations',
                'value_type' => 'toggle',
                'options' => $optionsUnmanagedOn,
                'default_value' => 'unmanaged',
                'warning' => null,
                'keys' => [
                    // HKCU\Software\Microsoft\Office : tree applicatif user-writable.
                    ['hive' => 'HKCU', 'path' => 'SOFTWARE\\Microsoft\\Office\\16.0\\Outlook\\Setup', 'name' => 'DisableOffice365SimplifiedAccountCreation', 'type' => 'REG_DWORD', 'value' => ['on' => 1]],
                ],
            ],
            [
                'key' => 'registry_editing_disabled',
                'label' => 'Désactiver l\'éditeur du Registre',
                'description' => 'Bloque l\'accès aux outils de modification du Registre (regedit). Cible : élèves via override de groupe.',
                'category' => 'Sécurité',
                'value_type' => 'toggle',
                'options' => $optionsUnmanagedOn,
                'default_value' => 'unmanaged',
                'warning' => null,
                'keys' => [
                    // ⚠️ Commentaire d'origine FAUX (« user-writable → OK
                    // companion ») corrigé par la Story 35.7 : ce tree
                    // (`…\CurrentVersion\Policies\System`) est en lecture
                    // seule pour l'utilisateur standard sur poste joint au
                    // domaine — appliqué par SYSTEM via `writer: system`
                    // (retrofit 2026_07_13_100000, défaut latent iso
                    // blocked_executables).
                    ['hive' => 'HKCU', 'path' => $userPoliciesSystem, 'name' => 'DisableRegistryTools', 'type' => 'REG_DWORD', 'value' => ['on' => 1]],
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

        // FK cascadeOnDelete : supprimer les capacités retire projections + overrides.
        DB::table('capabilities')->whereIn('key', [
            'news_and_interests_off',
            'llmnr_disabled',
            'onlyoffice_auto_update_off',
            'numlock_on_logon',
            'appx_special_profiles_allowed',
            'hide_last_username',
            'hide_drives',
            'laptop_lid_action',
            'cached_logons_count',
            'outlook_disable_o365_account_creation',
            'registry_editing_disabled',
        ])->delete();
    }
};
