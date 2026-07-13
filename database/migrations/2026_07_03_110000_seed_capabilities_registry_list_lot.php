<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 35.2 — lot `registry_list` (GPO spéciales CD95, palier B) : les deux
 * GPO à SOUS-VALEURS INDEXÉES `\1..\N` deviennent des capacités. Pattern iso
 * lot CD95 (`2026_07_02_100000`) : `updateOrInsert` par `key` puis par
 * `(capability_id, os, mechanism)`, idempotent, garde `hasTable`, `down()` par
 * suppression des `key` (FK cascade → projections + assignments).
 *
 * ── BI-PROJECTION (D5) ──────────────────────────────────────────────────────
 * `blocked_executables` inaugure la bi-projection : l'unique
 * `(capability_id, os, mechanism)` autorise UNE ligne `registry` (le flag
 * `DisallowRun = 1` qui ARME la policy) + UNE ligne `registry_list` (les
 * entrées `DisallowRun\1..N`). Chaque provider ne voit que la sienne.
 *
 * ── « OFF » HONNÊTE (invariant on/off) ──────────────────────────────────────
 * `off` est une VRAIE action combinée : le flag est SUPPRIMÉ (marqueur 35.1
 * `{"$ensure": "absent"}` — littéral dupliqué ici, les migrations ne
 * référencent pas le code applicatif, décision 35.1) ET les entrées numérotées
 * sont PURGÉES (liste vide `[]` = l'idiome « off » d'une liste — le marqueur
 * $ensure n'existe PAS en registry_list).
 *
 * ── DONNÉES ISO-GPO (vérifiées à la source Registry.xml CD95) ───────────────
 *   - Chrome Forcelist entrée `1` = `pgpjajcmfbfdmcgjlbiengidaknopaok`
 *     (id SEUL — pas d'URL : le Chrome Web Store est le défaut Chrome) ;
 *   - Edge Forcelist entrée `1` = `pgpjajcmfbfdmcgjlbiengidaknopaok;https://
 *     clients2.google.com/service/update2/crx` (Edge exige l'update_url CRX —
 *     l'extension Pix vient du Web Store, y compris pour Edge).
 *
 * ── cmd.exe ≈ DisableCMD (iso-intention CD95) ───────────────────────────────
 * La GPO « Blocages élèves » posait `DisableCMD` en laissant les scripts
 * autorisés (« Désactiver aussi les scripts : Non ») : bloquer l'EXÉCUTABLE
 * interactif via `DisallowRun` + `cmd.exe` est iso-intention. ⚠️ Commentaire
 * d'origine FAUX (corrigé par la Story 35.7) : le tree
 * `HKCU\…\CurrentVersion\Policies` est LUI AUSSI en lecture seule pour
 * l'utilisateur standard sur poste joint au domaine (TOUT `HKCU\…\Policies\*`
 * est durci, pas seulement `Software\Policies`) — défaut « Accès refusé »
 * confirmé en runtime. Ces clés sont appliquées par le SERVICE SYSTEM via le
 * marqueur `writer: system` (retrofit `2026_07_13_100000`, Story 35.7). Zéro
 * broker d'élévation (décision de cadrage epic 35).
 *
 * ── ARMEMENT = DONNÉE, PAS CE SEED ──────────────────────────────────────────
 * Les deux capacités naissent OPT-IN (`default_value = 'unmanaged'`, sentinelle
 * hors map ⇒ rien en broadcast). La cible métier de `blocked_executables` est
 * un OVERRIDE UserGroup élèves : le rattachement effectif (IDs propres à
 * chaque établissement) est de la donnée + le geste UI UserGroup (Story 35.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        // Tree restrictions user. ⚠️ Commentaire d'origine FAUX (« writable
        // par le compagnon ») corrigé par la Story 35.7 : lecture seule pour
        // l'utilisateur standard sur poste joint au domaine — appliqué par
        // SYSTEM via `writer: system` (retrofit 2026_07_13_100000).
        $userPoliciesExplorer = 'Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer';

        // Libellés convention « sujet + état » : le label est un sujet NEUTRE,
        // le statut est porté par la valeur ; « Non géré » réservé à la
        // sentinelle unmanaged.
        $lot = [
            [
                'capability' => [
                    'key' => 'pix_extension_forced',
                    'label' => 'Extension Pix (Chrome/Edge)',
                    'description' => 'Installation forcée de l\'extension Pix dans Google Chrome et Microsoft Edge (ExtensionInstallForcelist). Remplace la GPO CD95 « ExtensionPix ».',
                    'category' => 'Optimisations',
                    'value_type' => 'toggle',
                    'options' => json_encode([
                        ['value' => 'unmanaged', 'label' => 'Non géré'],
                        ['value' => 'on', 'label' => 'Forcée'],
                    ], JSON_UNESCAPED_UNICODE),
                    'default_value' => 'unmanaged',
                    'warning' => null,
                ],
                'projections' => [
                    [
                        'mechanism' => 'registry_list',
                        // Portée MACHINE (HKLM, service SYSTEM) — pas de « off » :
                        // la capacité est opt-in on-only (retirer l'override =
                        // revenir au défaut unmanaged = cesser de gérer ; les
                        // entrées posées restent, iso sémantique registry).
                        'keys' => [
                            [
                                'hive' => 'HKLM',
                                'path' => 'SOFTWARE\\Policies\\Google\\Chrome\\ExtensionInstallForcelist',
                                'entry_type' => 'REG_SZ',
                                // Chrome : id SEUL (Web Store = défaut), iso-GPO CD95.
                                'values' => ['on' => ['pgpjajcmfbfdmcgjlbiengidaknopaok']],
                            ],
                            [
                                'hive' => 'HKLM',
                                'path' => 'SOFTWARE\\Policies\\Microsoft\\Edge\\ExtensionInstallForcelist',
                                'entry_type' => 'REG_SZ',
                                // Edge : id;update_url CRX obligatoire, iso-GPO CD95.
                                'values' => ['on' => ['pgpjajcmfbfdmcgjlbiengidaknopaok;https://clients2.google.com/service/update2/crx']],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'capability' => [
                    'key' => 'blocked_executables',
                    'label' => 'Blocage d\'exécutables (cmd, PowerShell, mstsc)',
                    'description' => 'Interdit le lancement des exécutables sensibles via la restriction Explorer DisallowRun (cmd.exe remplace DisableCMD — les scripts restent autorisés, iso-intention CD95). Cible : élèves via override de groupe utilisateur (Story 35.4).',
                    'category' => 'Sécurité',
                    'value_type' => 'toggle',
                    'options' => json_encode([
                        ['value' => 'unmanaged', 'label' => 'Non géré'],
                        ['value' => 'on', 'label' => 'Activé'],
                        ['value' => 'off', 'label' => 'Désactivé (valeurs supprimées)'],
                    ], JSON_UNESCAPED_UNICODE),
                    'default_value' => 'unmanaged',
                    'warning' => null,
                ],
                // BI-PROJECTION D5 : flag (registry) + entrées (registry_list).
                // NB garde-fou AC3 : le flag vit dans la clé PARENTE
                // (`…\Policies\Explorer`, name `DisallowRun`) — path DISTINCT du
                // conteneur ENFANT (`…\Policies\Explorer\DisallowRun`) : pas de
                // collision scalaire↔conteneur.
                'projections' => [
                    [
                        'mechanism' => 'registry',
                        'keys' => [
                            [
                                'hive' => 'HKCU',
                                'path' => $userPoliciesExplorer,
                                'name' => 'DisallowRun',
                                'type' => 'REG_DWORD',
                                // off = flag SUPPRIMÉ (marqueur 35.1) — Windows
                                // reprend son défaut (aucune restriction).
                                'value' => ['on' => 1, 'off' => ['$ensure' => 'absent']],
                            ],
                        ],
                    ],
                    [
                        'mechanism' => 'registry_list',
                        'keys' => [
                            [
                                'hive' => 'HKCU',
                                'path' => $userPoliciesExplorer.'\\DisallowRun',
                                'entry_type' => 'REG_SZ',
                                'values' => [
                                    // Ordre SIGNIFIANT (entrées 1..5) ; cmd.exe
                                    // remplace DisableCMD (iso-intention CD95).
                                    'on' => ['powershell.exe', 'powershell_ise.exe', 'pwsh.exe', 'mstsc.exe', 'cmd.exe'],
                                    // off = purge des entrées numérotées (liste
                                    // vide = l'idiome « off » d'une liste).
                                    'off' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($lot as $row) {
            DB::table('capabilities')->updateOrInsert(
                ['key' => $row['capability']['key']],
                [
                    'label' => $row['capability']['label'],
                    'description' => $row['capability']['description'],
                    'category' => $row['capability']['category'],
                    'value_type' => $row['capability']['value_type'],
                    'options' => $row['capability']['options'],
                    'default_value' => $row['capability']['default_value'],
                    'warning' => $row['capability']['warning'],
                    'applies_to_os' => json_encode(['windows'], JSON_UNESCAPED_UNICODE),
                    'is_active' => true,
                    'overrides_locked' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            $capabilityId = DB::table('capabilities')->where('key', $row['capability']['key'])->value('id');
            if ($capabilityId === null) {
                continue;
            }

            foreach ($row['projections'] as $projection) {
                DB::table('capability_projections')->updateOrInsert(
                    [
                        'capability_id' => $capabilityId,
                        'os' => 'windows',
                        'mechanism' => $projection['mechanism'],
                    ],
                    [
                        'spec' => json_encode(['keys' => $projection['keys']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('capabilities')) {
            return;
        }

        // FK cascadeOnDelete : supprimer les capacités retire projections + overrides.
        DB::table('capabilities')->whereIn('key', [
            'pix_extension_forced',
            'blocked_executables',
        ])->delete();
    }
};
