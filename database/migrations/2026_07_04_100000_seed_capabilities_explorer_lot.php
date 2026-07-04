<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 36.3 — lot « registre pures Explorateur » : TÉMOIN DE DOCTRINE de
 * l'Epic 36 (`_bmad-output/planning-artifacts/epics-mecanismes-hors-registre.md
 * #Story-36.3`). Le mécanisme `registry` étant payé (27.12 + Epic 35), une
 * capacité supplémentaire est de la DONNÉE PURE — coût marginal ≈ une
 * migration de seed + tests. Pattern EXACT du lot CD95
 * (`2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php`) : `updateOrInsert`
 * par `key` puis par `(capability_id, os, mechanism)`, idempotent, garde
 * `Schema::hasTable`, `down()` par `whereIn('key')->delete()` (FK cascade →
 * projections/assignments). ZÉRO évolution moteur : ni agent, ni contrat, ni
 * providers, ni StateCompiler ne sont touchés par cette story.
 *
 * ── ⚠️ STATUT DES CLÉS : CANDIDATES DÉCODAGE DOCUMENTAIRE — GATE LAB ────────
 * Les GUID/paths/valeurs ci-dessous proviennent du DÉCODAGE DOCUMENTAIRE
 * (patron `onedrive_hidden` + tweaks Windows documentés), PAS d'une
 * vérification sur poste. Le dev n'a aucun accès à un poste Windows lab
 * (SSH = serveurs seulement). Le « Protocole de vérification lab » de la
 * story 36.3 est un GATE DE REVIEW BLOQUANT à dérouler AVANT `php artisan
 * migrate` sur /vm : toute clé invalidée doit être retirée de cette migration
 * avant merge (jamais de clé « au cas où »). Cette migration N'EST PAS jouée
 * sur /vm par le dev.
 *
 * ── TOUT OPT-IN (default_value = 'unmanaged') ────────────────────────────────
 * Les 4 capacités sont opt-in : sentinelle `unmanaged` hors map ⇒ RIEN n'est
 * émis en broadcast — golden files et `FROZEN_STATE_HASH`/`frozenStateHash`
 * restent STRICTEMENT intacts. L'épuration du volet Explorateur est un choix
 * pédagogique par parc (armement = override de parc via l'UI existante), pas
 * un défaut de flotte.
 *
 * ── MAPS SYMÉTRIQUES, ZÉRO $ensure ───────────────────────────────────────────
 * Toutes les maps sont symétriques à VALEURS RÉELLES (si l'UI propose « off »,
 * off écrit une vraie valeur qui restaure le comportement Windows par défaut,
 * invariant 27.12) — aucun marqueur `{"$ensure": "absent"}` n'est nécessaire
 * dans ce lot.
 *
 * ── ROUTAGE HKCR → HKCU\Software\Classes ────────────────────────────────────
 * Les clés CLSID (« Accueil » Win11 dans `quick_access_hidden`, « Galerie »
 * dans `explorer_gallery_hidden`) sont des vues HKCR ; iso `onedrive_hidden`
 * (`2026_06_18_100300_seed_capabilities_iso_lot.php` l.212-215), elles sont
 * routées `HKCU\Software\Classes\CLSID\{GUID}` (vue per-user écrite par le
 * compagnon de session, portée Session), `System.IsPinnedToNameSpaceTree`
 * REG_DWORD `{on: 0 (masqué), off: 1 (affiché)}`.
 *
 * ── ÉCART DE PORTÉE : capacité 1 seedée Machine (HKLM), PAS Session ─────────
 * L'epic annonce « portée Session » pour `explorer_sidebar_pins_hidden`
 * (extrapolation du patron `onedrive_hidden`). Le décodage documentaire pointe
 * `ThisPCPolicy` sous HKLM (`FolderDescriptions\{GUID}\PropertyBag`) — les 6
 * dossiers utilisateur du volet n'ont pas de CLSID per-user documenté,
 * contrairement à OneDrive/Accueil/Galerie. La capacité est donc seedée
 * portée MACHINE (décision D3 de la story) ; l'écart vs le cadrage epic est
 * assumé et consigné ici + au Dev Agent Record. Si le protocole lab révèle
 * une variante per-user fonctionnelle, la correction se fait AVANT merge.
 *
 * ── CLSID ONEDRIVE INTERDIT DANS CE LOT ──────────────────────────────────────
 * Le CLSID `{018D5C66-4533-4307-9B53-224DE2ED1FE6}` appartient à
 * `onedrive_hidden` (seed ISO) — il n'apparaît dans AUCUNE clé de ce lot (deux
 * capacités écrivant la même clé `{hive|path|name}` seraient arbitrées
 * silencieusement par le compilateur, résultat imprévisible). Verrouillé par
 * un test structurel d'anti-collision (`CapabilitiesSchemaAndSeedTest`).
 *
 * ── RUCHES MIXTES DANS UNE MÊME PROJECTION (D4) ──────────────────────────────
 * `quick_access_hidden` porte 1 clé HKLM (HubMode, provider Machine/SYSTEM) +
 * 2 clés HKCU (LaunchTo + CLSID Accueil, provider Session/compagnon) — chaque
 * provider filtre par `hive`, rien à coder (précédent `numlock_on_logon`
 * HKCU+HKU).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        // Opt-in : 1er choix = « Non géré » (sentinelle `unmanaged` hors map ⇒
        // rien n'est émis en broadcast), les 2 autres arment la capacité par
        // override. Convention libellés « sujet + état » (label = sujet
        // neutre, « Non géré » réservé à la sentinelle).
        $optionsUnmanagedOnOff = fn (string $onLabel, string $offLabel): string => json_encode([
            ['value' => 'unmanaged', 'label' => 'Non géré'],
            ['value' => 'on', 'label' => $onLabel],
            ['value' => 'off', 'label' => $offLabel],
        ], JSON_UNESCAPED_UNICODE);

        $lot = [
            // ── 1. explorer_sidebar_pins_hidden — portée Machine (HKLM, D3) ──
            [
                'key' => 'explorer_sidebar_pins_hidden',
                'label' => 'Dossiers épinglés du volet de navigation',
                'description' => 'Masque les dossiers utilisateur (Bureau, Documents, Images, Musique, Vidéos, Téléchargements) de « Ce PC » et du volet de navigation. Réglage machine, effet au redémarrage d\'Explorer/session suivante.',
                'category' => 'Bureau',
                'value_type' => 'toggle',
                'options' => $optionsUnmanagedOnOff('Masqués', 'Affichés'),
                'default_value' => 'unmanaged',
                'warning' => null,
                // Candidates décodage documentaire — À VÉRIFIER SUR POSTE LAB
                // AVANT migrate /vm. 6 GUID `FolderDescriptions`, toutes HKLM,
                // même name/type/map (symétrique : Show = défaut Windows).
                'keys' => [
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Explorer\\FolderDescriptions\\{f42ee2d3-909f-4907-8871-4c22fc0bf756}\\PropertyBag', 'name' => 'ThisPCPolicy', 'type' => 'REG_SZ', 'value' => ['on' => 'Hide', 'off' => 'Show']],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Explorer\\FolderDescriptions\\{0ddd015d-b06c-45d5-8c4c-f59713854639}\\PropertyBag', 'name' => 'ThisPCPolicy', 'type' => 'REG_SZ', 'value' => ['on' => 'Hide', 'off' => 'Show']],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Explorer\\FolderDescriptions\\{a0c69a99-21c8-4671-8703-7934162fcf1d}\\PropertyBag', 'name' => 'ThisPCPolicy', 'type' => 'REG_SZ', 'value' => ['on' => 'Hide', 'off' => 'Show']],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Explorer\\FolderDescriptions\\{35286a68-3c57-41a1-bbb1-0eae73d76c95}\\PropertyBag', 'name' => 'ThisPCPolicy', 'type' => 'REG_SZ', 'value' => ['on' => 'Hide', 'off' => 'Show']],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Explorer\\FolderDescriptions\\{7d83ee9b-2244-4e70-b1f5-5393042af1e4}\\PropertyBag', 'name' => 'ThisPCPolicy', 'type' => 'REG_SZ', 'value' => ['on' => 'Hide', 'off' => 'Show']],
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Explorer\\FolderDescriptions\\{B4BFCC3A-DB2C-424C-B029-7FE99A87C641}\\PropertyBag', 'name' => 'ThisPCPolicy', 'type' => 'REG_SZ', 'value' => ['on' => 'Hide', 'off' => 'Show']],
                ],
            ],

            // ── 2. quick_access_hidden — portées mixtes HKLM+HKCU (D4) ───────
            [
                'key' => 'quick_access_hidden',
                'label' => 'Accès rapide (volet de navigation)',
                'description' => 'Masque « Accès rapide » (Windows 10) et « Accueil » (Windows 11) du volet, ouvre l\'Explorateur sur « Ce PC ». Effet à la session suivante.',
                'category' => 'Bureau',
                'value_type' => 'toggle',
                'options' => $optionsUnmanagedOnOff('Masqué (volet réduit à Ce PC)', 'Affiché'),
                'default_value' => 'unmanaged',
                'warning' => null,
                // Candidates décodage documentaire — À VÉRIFIER SUR POSTE LAB
                // AVANT migrate /vm. Clé 3 = routage HKCR→HKCU (CLSID Accueil
                // Win11, iso onedrive_hidden) ; HubMode ne gouverne pas
                // « Accueil ». Tree Explorer\Advanced = user-writable standard
                // (PAS HKCU\Software\Policies).
                'keys' => [
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Explorer', 'name' => 'HubMode', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                    ['hive' => 'HKCU', 'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced', 'name' => 'LaunchTo', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 2]],
                    ['hive' => 'HKCU', 'path' => 'Software\\Classes\\CLSID\\{f874310e-b6b7-47dc-bc84-b9e6b38f5903}', 'name' => 'System.IsPinnedToNameSpaceTree', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
                ],
            ],

            // ── 3. explorer_gallery_hidden — portée Session (HKCU), candidat ─
            [
                'key' => 'explorer_gallery_hidden',
                'label' => 'Galerie (volet de navigation, Windows 11)',
                'description' => 'Masque l\'entrée « Galerie » du volet de navigation (Windows 11 ; sans effet sur Windows 10). Effet à la session suivante.',
                'category' => 'Bureau',
                'value_type' => 'toggle',
                'options' => $optionsUnmanagedOnOff('Masquée', 'Affichée'),
                'default_value' => 'unmanaged',
                'warning' => null,
                // Candidate décodage documentaire — À VÉRIFIER SUR POSTE LAB
                // AVANT migrate /vm. Routage HKCR→HKCU iso onedrive_hidden,
                // patron exact System.IsPinnedToNameSpaceTree.
                'keys' => [
                    ['hive' => 'HKCU', 'path' => 'Software\\Classes\\CLSID\\{e88865ea-0e1c-4e20-9aa6-edcd0212c87c}', 'name' => 'System.IsPinnedToNameSpaceTree', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
                ],
            ],

            // ── 4. quick_access_history_hidden — portée Session (HKCU), candidat ─
            [
                'key' => 'quick_access_history_hidden',
                'label' => 'Historique de l\'Accès rapide (récents et fréquents)',
                'description' => 'N\'affiche plus les fichiers récemment utilisés ni les dossiers fréquents dans l\'Accès rapide de l\'Explorateur. Effet à la session suivante.',
                'category' => 'Bureau',
                'value_type' => 'toggle',
                'options' => $optionsUnmanagedOnOff('Masqué', 'Affiché'),
                'default_value' => 'unmanaged',
                'warning' => null,
                // Candidates décodage documentaire — À VÉRIFIER SUR POSTE LAB
                // AVANT migrate /vm. Tree CurrentVersion\Explorer user-writable
                // standard, PAS Software\Policies (garde-fou epic).
                'keys' => [
                    ['hive' => 'HKCU', 'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer', 'name' => 'ShowRecent', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
                    ['hive' => 'HKCU', 'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer', 'name' => 'ShowFrequent', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
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
            'explorer_sidebar_pins_hidden',
            'quick_access_hidden',
            'explorer_gallery_hidden',
            'quick_access_history_hidden',
        ])->delete();
    }
};
