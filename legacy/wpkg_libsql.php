<?php

/**
 * Shim SQL MySQL→Eloquent — Story 1bis.3
 *
 * Remplace toutes les fonctions globales de wpkg_libsql.php
 * par des équivalents Eloquent/PostgreSQL.
 *
 * Le paramètre $config est systématiquement ignoré — Eloquent gère la connexion.
 * Le cache APCu est conservé pour compatibilité (mêmes clés que le legacy).
 *
 * Chargé par legacy/bootstrap.php AVANT que les modules legacy ne chargent
 * wpkg_libsql.php — PHP refuse de redéfinir des fonctions déjà déclarées.
 */

// NOTE: L'original `sambaedu/includes/wpkg_libsql.php` exécute deux side effects
// au chargement qui ne sont PAS reproduits par ce shim :
//   - test_mef($config)
//   - $mise_en_forme_perso = mise_en_forme_personnalisee($config)
// Non utilisés par 1bis-18e (associations_out.php). Dette à lever si un futur
// module GPO shimmé dépend de `$mise_en_forme_perso`.

use App\Models\Application;
use App\Models\AppProfile;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\WorkstationApplicationStatus;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\ErrorLoggerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ─── Guard : empêcher le double-chargement ───────────────────────────────────
// Ce fichier porte le même nom que l'original (wpkg_libsql.php) :
// les modules legacy qui font include("wpkg_libsql.php") résolvent vers
// cette version via l'include_path, comme pour ldap.inc.php.
if (defined('SQL_SHIM_LOADED')) {
    return;
}
define('SQL_SHIM_LOADED', true);

// ─── Helper interne : log des fonctions non shimmées ─────────────────────────
function _sql_shim_not_implemented(string $functionName): array
{
    try {
        app(ErrorLoggerService::class)->log('legacy', "Fonction SQL non shimmée : {$functionName}");
    } catch (\Throwable $e) {
        Log::warning("sql_shim: impossible de logger fonction non shimmée: {$functionName}");
    }
    return [];
}

// ─── Couleurs par défaut mise en forme (hardcoded — table mise_en_forme non migrée) ──
function _sql_shim_default_mef(): array
{
    return [
        'warning_bg' => '#EE0000',
        'warning_txt' => '#FFFFFF',
        'warning_lnk' => '#FFFF00',
        'error_bg' => '#EEEE00',
        'error_txt' => '#000000',
        'error_lnk' => '#415594',
        'ok_bg' => '#00DD00',
        'ok_txt' => '#000000',
        'ok_lnk' => '#415594',
        'unknown_bg' => '#FFFFFF',
        'unknown_txt' => '#000000',
        'unknown_lnk' => '#415594',
        'regular_lnk' => '#0080FF',
        'wintype_txt' => '#FFF8DC',
        'dep_entite_bg' => '#0000FF',
        'dep_entite_txt' => '#FFFFFF',
        'dep_entite_lnk' => '#FFFF00',
        'dep_parc_bg' => '#0077FF',
        'dep_parc_txt' => '#FFFFFF',
        'dep_parc_lnk' => '#FFFF00',
        'dep_depend_bg' => '#00EEFF',
        'dep_depend_txt' => '#000000',
        'dep_depend_lnk' => '#FF0000',
        'dep_no_bg' => '#FFFFFF',
        'dep_no_txt' => '#000000',
        'dep_no_lnk' => '#FF0000',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// CONNEXION — no-ops
// ═══════════════════════════════════════════════════════════════════════════════

function connexion_db_wpkg($config)
{
    // Eloquent gère la connexion — retourne un objet factice
    return new \stdClass();
}

function deconnexion_db_wpkg($link)
{
    // no-op
}

// ═══════════════════════════════════════════════════════════════════════════════
// LECTURE — Postes
// ═══════════════════════════════════════════════════════════════════════════════

function info_postes($config)
{
    $tab = [];
    $workstations = Workstation::orderBy('name')->get();

    foreach ($workstations as $ws) {
        $key = strtolower($ws->name);
        $tab[$key] = [
            'id' => $ws->id,
            'nom_poste' => strtolower($ws->name),
            'OS_poste' => $ws->os ?? '',
            'date_rapport_poste' => $ws->last_report_at ? $ws->last_report_at->format('Y-m-d H:i:s') : '',
            'IP_poste' => $ws->ip ?? '',
            'mac_address_poste' => $ws->mac ?? '',
            'sha_rapport_poste' => $ws->report_sha ?? '',
            'file_log_poste' => $ws->log_path ?? '',
            'file_rapport_poste' => $ws->report_path ?? '',
            'date_modification_poste' => $ws->updated_at ? $ws->updated_at->format('Y-m-d H:i:s') : '',
            'uuid_poste' => $ws->uuid ?? '',
            'flag_poste' => match ($ws->status) {
                'protected' => 1,
                'active' => 0,
                default => 0,
            },
        ];
    }

    return $tab;
}

function info_postes_uuid($config)
{
    $tab = [];
    $workstations = Workstation::withUuid()->get();

    foreach ($workstations as $ws) {
        $tab[$ws->uuid] = [
            'id' => $ws->id,
            'nom_poste' => strtolower($ws->name),
            'uuid_poste' => $ws->uuid,
            'flag_poste' => $ws->status === 'protected' ? 1 : 0,
            'check_poste' => 0,
        ];
    }

    return $tab;
}

function info_poste_parcs($config, $nom_poste)
{
    $ws = Workstation::where('name', $nom_poste)->first();
    if (!$ws) {
        return [];
    }

    $tab = [];
    $groups = $ws->groups()->orderBy('name')->get();

    foreach ($groups as $group) {
        $tab[$group->name] = [
            'id_parc' => $group->id,
            'id_poste' => $ws->id,
            'nom_parc' => $group->name,
            'nom_parc_wpkg' => $group->display_name ?? $group->name,
        ];
    }

    return $tab;
}

function info_postes_parcs($config)
{
    $tab = [];
    $pivots = DB::table('workstation_group_workstation as wgw')
        ->join('workstation_groups as wg', 'wg.id', '=', 'wgw.workstation_group_id')
        ->select('wgw.workstation_id', 'wg.id as id_parc', 'wg.name as nom_parc', 'wg.display_name as nom_parc_wpkg')
        ->orderBy('wg.name')
        ->get();

    foreach ($pivots as $row) {
        $tab[$row->workstation_id][$row->nom_parc] = [
            'id_parc' => $row->id_parc,
            'id_poste' => $row->workstation_id,
            'nom_parc' => $row->nom_parc,
            'nom_parc_wpkg' => $row->nom_parc_wpkg ?? $row->nom_parc,
        ];
    }

    return $tab;
}

function info_poste_applications($config, $nom_poste)
{
    $cacheKey = "wpkg_poste_" . $nom_poste;
    if (function_exists('apcu_fetch') && ($cached = apcu_fetch($cacheKey)) !== false) {
        return $cached;
    }

    $ws = Workstation::where('name', $nom_poste)->first();
    if (!$ws) {
        return [];
    }

    $tab = [];
    $listAppDep = [];

    // Applications assignées au poste via ses groupes → appProfiles
    // DÉVIATION : l'original renseignait aussi $tab[$id]['poste'] pour les apps
    // assignées directement au poste (type_entite='poste'). Le schéma Eloquent
    // n'a pas cette relation — la clé 'poste' sera absente du tableau.
    $groups = $ws->groups()->with('appProfiles.applications')->get();

    foreach ($groups as $group) {
        foreach ($group->appProfiles as $profile) {
            foreach ($profile->applications as $app) {
                $depCount = DB::table('application_dependencies')
                    ->where('application_id', $app->id)
                    ->count();

                $tab[$app->id]['info_app'] = [
                    'id_app' => $app->id,
                    'id_nom_app' => $app->app_id,
                    'nom_app' => $app->name,
                    'version_app' => $app->version ?? '',
                    'compatibilite_app' => $app->compatibility ?? '',
                    'categorie_app' => $app->category ?? '',
                    'prorite_app' => 0,
                    'reboot_app' => 0,
                    'sha_app' => $app->xml_sha ?? '',
                ];

                $tab[$app->id]['parc'][$group->name] = [
                    'id_parc' => $group->id,
                    'nom_parc' => $group->name,
                    'nom_parc_wpkg' => $group->display_name ?? $group->name,
                ];

                if ($depCount > 0) {
                    $listAppDep[$app->id] = $app->id;
                }
            }
        }
    }

    // Résoudre les dépendances
    if ($listAppDep) {
        foreach ($listAppDep as $appId => $tmp) {
            $deps = DB::table('application_dependencies as d')
                ->join('applications as a', 'a.id', '=', 'd.required_application_id')
                ->where('d.application_id', $appId)
                ->select('a.id', 'a.app_id', 'a.name', 'a.version', 'a.compatibility', 'a.category', 'a.xml_sha')
                ->get();

            foreach ($deps as $dep) {
                $tab[$dep->id]['info_app'] = [
                    'id_app' => $dep->id,
                    'id_nom_app' => $dep->app_id,
                    'nom_app' => $dep->name,
                    'version_app' => $dep->version ?? '',
                    'compatibilite_app' => $dep->compatibility ?? '',
                    'categorie_app' => $dep->category ?? '',
                    'prorite_app' => 0,
                    'reboot_app' => 0,
                    'sha_app' => $dep->xml_sha ?? '',
                ];
                $tab[$dep->id]['required_by'][$appId] = $tab[$appId]['info_app'];
            }
        }
    }

    ksort($tab);

    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $tab, 1000);
    }

    return $tab;
}

function info_poste_appli_full($config, $nom_poste)
{
    $ws = Workstation::where('name', $nom_poste)->first();
    if (!$ws) {
        return [];
    }

    $tab = [];
    $groups = $ws->groups()->with('appProfiles.applications')->get();

    foreach ($groups as $group) {
        foreach ($group->appProfiles as $profile) {
            foreach ($profile->applications as $app) {
                $tab[$app->id]['poste'] = $nom_poste;

                // Résoudre les dépendances
                $deps = DB::table('application_dependencies')
                    ->where('application_id', $app->id)
                    ->pluck('required_application_id');

                foreach ($deps as $reqId) {
                    if (Application::find($reqId)) {
                        $tab[$reqId]['depends'][] = $app->app_id;
                    }
                }
            }
        }
    }

    // Dédupliquer les dépendances
    foreach ($tab as &$entry) {
        if (isset($entry['depends'])) {
            $entry['depends'] = array_values(array_unique($entry['depends']));
        }
    }
    unset($entry);

    return $tab;
}

function info_poste_rapport($config, $nom_poste)
{
    $ws = Workstation::where('name', $nom_poste)->first();
    if (!$ws) {
        return ['statut' => 0];
    }

    $wasTable = (new WorkstationApplicationStatus)->getTable();
    $reports = WorkstationApplicationStatus::where('workstation_id', $ws->id)
        ->join('applications', 'applications.id', '=', "{$wasTable}.application_id")
        ->orderBy('applications.app_id')
        ->select("{$wasTable}.*", 'applications.app_id', 'applications.name as app_name')
        ->get();

    $statusMap = ['installed' => 1, 'not-installed' => 0, 'error' => 2, 'upgrading' => 1, 'downgrading' => 1, 'unknown' => 0];
    $tab = [];
    $statut = 0;

    foreach ($reports as $report) {
        $tab[hash('md5', $report->app_id)] = [
            'id_nom_app' => $report->app_id,
            'nom_app' => $report->app_name,
            'revision_poste_app' => $report->installed_version,
            'statut_poste_app' => $report->status,
            'reboot_poste_app' => $report->reboot_required ? 1 : 0,
        ];
        $statut = max($statut, $statusMap[$report->status] ?? 0);
    }

    $tab['statut'] = $statut;

    return $tab;
}

function info_poste_statut($config, $id_poste, $list_app)
{
    $hash = md5($id_poste . serialize($list_app));
    $cacheKey = "wpkg_statut_" . $hash;

    if (function_exists('apcu_fetch') && ($cached = apcu_fetch($cacheKey)) !== false) {
        return $cached;
    }

    $tab = [
        'MaJ' => 0,
        'Not_Ok-' => 0,
        'Ok' => 0,
        'Not_Ok+' => 0,
        'Status' => 0,
    ];

    if (!$list_app) {
        return $tab;
    }

    $appIds = array_map('intval', array_values($list_app));

    // Applications requises installées avec bon version
    $reports = WorkstationApplicationStatus::where('workstation_id', $id_poste)->get()->keyBy('application_id');
    $apps = Application::whereIn('id', $appIds)->get()->keyBy('id');

    foreach ($appIds as $appId) {
        $app = $apps->get($appId);
        $report = $reports->get($appId);

        if (!$app) {
            continue;
        }

        if (!$report) {
            $tab['Not_Ok-']++;
            $tab['Status'] = 2;
        } elseif ($report->status === 'not-installed') {
            $tab['Not_Ok-']++;
            $tab['Status'] = 2;
        } elseif ($app->version === $report->installed_version) {
            $tab['Ok']++;
        } else {
            $tab['MaJ']++;
            $tab['Status'] = max($tab['Status'], 1);
        }
    }

    // Apps installées mais non requises (Not_Ok+)
    foreach ($reports as $report) {
        if ($report->status === 'installed' && !in_array($report->application_id, $appIds)) {
            $tab['Not_Ok+']++;
            $tab['Status'] = 2;
        }
    }

    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $tab, 120);
    }

    return $tab;
}

// ═══════════════════════════════════════════════════════════════════════════════
// LECTURE — Parcs
// ═══════════════════════════════════════════════════════════════════════════════

function info_parcs($config)
{
    $tab = [];
    $groups = WorkstationGroup::all();

    foreach ($groups as $group) {
        $tab[$group->name] = [
            'id' => $group->id,
            'nom_parc' => $group->name,
            'nom_parc_wpkg' => $group->display_name ?? $group->name,
            'uuid' => $group->ad_guid,
        ];
    }

    return $tab;
}

function info_parc_postes($config, $nom_parc)
{
    $group = WorkstationGroup::where('name', $nom_parc)->first();
    if (!$group) {
        return [];
    }

    $tab = [];
    $workstations = $group->workstations()->get();

    foreach ($workstations as $ws) {
        $tab[$ws->name] = [
            'nom_poste' => $ws->name,
            'id_poste' => $ws->id,
            'OS_poste' => $ws->os ?? '',
            'date_rapport_poste' => $ws->last_report_at ? $ws->last_report_at->format('Y-m-d H:i:s') : '',
            'ip_poste' => $ws->ip ?? '',
            'mac_address_poste' => $ws->mac ?? '',
            'file_log_poste' => $ws->log_path ?? '',
        ];
    }

    return $tab;
}

function info_parc_appli($config, $nom_parc)
{
    $group = WorkstationGroup::where('name', $nom_parc)->first();
    if (!$group) {
        return [];
    }

    $tab = [];
    foreach ($group->appProfiles as $profile) {
        foreach ($profile->applications as $app) {
            $tab[$app->id] = [
                'id_app' => $app->id,
                'id_nom_app' => $app->app_id,
                'nom_app' => $app->name,
                'version_app' => $app->version ?? '',
                'compatibilite_app' => $app->compatibility ?? '',
                'categorie_app' => $app->category ?? '',
                'prorite_app' => 0,
                'reboot_app' => 0,
                'sha_app' => $app->xml_sha ?? '',
            ];
        }
    }

    return $tab;
}

function info_parc_appli_full($config, $nom_parc)
{
    $group = WorkstationGroup::where('name', $nom_parc)->first();
    if (!$group) {
        return [];
    }

    $tab = [];
    foreach ($group->appProfiles as $profile) {
        foreach ($profile->applications as $app) {
            $tab[$app->id]['parc'] = $nom_parc;

            $deps = DB::table('application_dependencies')
                ->where('application_id', $app->id)
                ->pluck('required_application_id');

            foreach ($deps as $reqId) {
                if (Application::find($reqId)) {
                    $tab[$reqId]['depends'][] = $app->app_id;
                }
            }
        }
    }

    foreach ($tab as &$entry) {
        if (isset($entry['depends'])) {
            $entry['depends'] = array_values(array_unique($entry['depends']));
        }
    }
    unset($entry);

    return $tab;
}

function info_sha_postes($config)
{
    $tab = [];
    $workstations = Workstation::whereNotNull('report_path')->get();

    foreach ($workstations as $ws) {
        $tab[$ws->report_path] = $ws->report_sha ?? '';
    }

    return $tab;
}

// ═══════════════════════════════════════════════════════════════════════════════
// LECTURE — Applications
// ═══════════════════════════════════════════════════════════════════════════════

function liste_applications($config)
{
    $tab = [];
    $temp = [];
    $applications = Application::orderBy('name')->get();

    foreach ($applications as $app) {
        $md5Key = hash('md5', $app->app_id);
        $tab[$md5Key] = [
            'id_app' => $app->id,
            'id_nom_app' => $app->app_id,
            'nom_app' => $app->name,
            'version_app' => $app->version ?? '',
            'compatibilite_app' => $app->compatibility ?? '',
            'categorie_app' => $app->category ?? '',
            'prorite_app' => 0,
            'reboot_app' => 0,
            'sha_app' => $app->xml_sha ?? '',
            'date_modif_app' => $app->updated_at ? $app->updated_at->format('Y-m-d H:i:s') : '',
            'user_modif_app' => $app->author ?? '',
            'active_app' => 1,
        ];
        $temp[$app->id] = [
            'id_nom_app' => $app->app_id,
            'nom_app' => $app->name,
        ];
    }

    // Dépendances
    $deps = DB::table('application_dependencies')->get();
    foreach ($deps as $dep) {
        $reqApp = $temp[$dep->required_application_id] ?? null;
        $parentApp = $temp[$dep->application_id] ?? null;
        if ($reqApp && $parentApp) {
            $reqMd5 = hash('md5', $reqApp['id_nom_app']);
            $parentMd5 = hash('md5', $parentApp['id_nom_app']);
            if (isset($tab[$reqMd5])) {
                $tab[$reqMd5]['required_by'][$dep->application_id] = $parentApp;
            }
            if (isset($tab[$parentMd5])) {
                $tab[$parentMd5]['depends'][$dep->required_application_id] = $reqApp;
            }
        }
    }

    return $tab;
}

function info_application_postes($config, $id_nom_appli)
{
    $md5 = hash('md5', $id_nom_appli);
    $app = Application::where('app_id', $id_nom_appli)->first();
    if (!$app) {
        return [];
    }

    $tab = [];

    // Dépendances : apps qui dépendent de $app
    $dependAppIds = DB::table('application_dependencies')
        ->where('required_application_id', $app->id)
        ->pluck('application_id')
        ->toArray();

    $allAppIds = array_merge([$app->id], $dependAppIds);

    // Postes assignés directement (via appProfile → group → workstation)
    $profiles = AppProfile::whereHas('applications', function ($q) use ($allAppIds) {
        $q->whereIn('applications.id', $allAppIds);
    })->get();

    foreach ($profiles as $profile) {
        foreach ($profile->workstationGroups as $group) {
            foreach ($group->workstations as $ws) {
                $tab[$ws->name]['info_poste'] = [
                    'id_poste' => $ws->id,
                    'nom_poste' => $ws->name,
                    'OS_poste' => $ws->os ?? '',
                    'date_rapport_poste' => $ws->last_report_at ? $ws->last_report_at->format('Y-m-d H:i:s') : '',
                    'ip_poste' => $ws->ip ?? '',
                    'mac_address_poste' => $ws->mac ?? '',
                    'file_log_poste' => $ws->log_path ?? '',
                ];

                // Déterminer le contexte d'assignation
                $appInProfile = $profile->applications()->where('applications.id', $app->id)->exists();
                if ($appInProfile) {
                    $tab[$ws->name]['parc'][$group->name] = [
                        'id_parc' => $group->id,
                        'nom_parc' => $group->name,
                        'nom_parc_wpkg' => $group->display_name ?? $group->name,
                    ];
                } else {
                    // App assignée via dépendance
                    foreach ($dependAppIds as $depId) {
                        if ($profile->applications()->where('applications.id', $depId)->exists()) {
                            $depApp = Application::find($depId);
                            if ($depApp) {
                                $tab[$ws->name]['required_by'][$depApp->app_id] = [
                                    'id_app' => $depApp->id,
                                    'id_nom_app' => $depApp->app_id,
                                    'nom_app' => $depApp->name,
                                ];
                            }
                        }
                    }
                }
            }
        }
    }

    ksort($tab);
    return $tab;
}

function info_application_parcs($config, $id_nom_appli)
{
    $app = Application::where('app_id', $id_nom_appli)->first();
    if (!$app) {
        return [];
    }

    $tab = [];
    $profiles = $app->appProfiles;

    foreach ($profiles as $profile) {
        foreach ($profile->workstationGroups as $group) {
            $tab[] = $group->name;
        }
    }

    sort($tab);
    return array_unique($tab);
}

function info_application_rapport($config, $id_nom_appli)
{
    $app = Application::where('app_id', $id_nom_appli)->first();
    if (!$app) {
        return [];
    }

    $tab = [];
    $wasTable = (new WorkstationApplicationStatus)->getTable();
    $reports = WorkstationApplicationStatus::where('application_id', $app->id)
        ->join('workstations', 'workstations.id', '=', "{$wasTable}.workstation_id")
        ->orderBy('workstations.name')
        ->select("{$wasTable}.*", 'workstations.name as ws_name', 'workstations.id as ws_id')
        ->get();

    foreach ($reports as $report) {
        $tab[$report->ws_name] = [
            'nom_poste' => $report->ws_name,
            'id_poste' => $report->ws_id,
            'revision_poste_app' => $report->installed_version,
            'statut_poste_app' => $report->status,
            'reboot_poste_app' => $report->reboot_required ? 1 : 0,
        ];
    }

    return $tab;
}

function info_application_requiered_parc($config, $id_appli)
{
    // Parcs où l'application est requise via une dépendance
    $parentAppIds = DB::table('application_dependencies')
        ->where('required_application_id', $id_appli)
        ->pluck('application_id');

    if ($parentAppIds->isEmpty()) {
        return [];
    }

    $tab = [];
    $profiles = AppProfile::whereHas('applications', function ($q) use ($parentAppIds) {
        $q->whereIn('applications.id', $parentAppIds);
    })->get();

    foreach ($profiles as $profile) {
        foreach ($profile->workstationGroups as $group) {
            $tab[] = $group->name;
        }
    }

    sort($tab);
    return array_values(array_unique($tab));
}

// ═══════════════════════════════════════════════════════════════════════════════
// LECTURE — Mise en forme
// ═══════════════════════════════════════════════════════════════════════════════

function mise_en_forme_personnalisee($config)
{
    // Table mise_en_forme non migrée — retourne les valeurs par défaut
    return _sql_shim_default_mef();
}

function mise_en_forme_info($config)
{
    $defaults = _sql_shim_default_mef();
    $tab = [];

    foreach ($defaults as $label => $value) {
        $tab[$label] = [
            'label' => $label,
            'value' => $value,
            'test' => $value,
            'default' => $value,
        ];
    }

    return $tab;
}

// ═══════════════════════════════════════════════════════════════════════════════
// LECTURE — Dépôts
// ═══════════════════════════════════════════════════════════════════════════════

function info_depot($config)
{
    $tab = [];
    $depots = Depot::active()
        ->orderByDesc('is_primary')
        ->orderBy('id')
        ->get();

    foreach ($depots as $depot) {
        $tab[$depot->id] = [
            'id_depot' => $depot->id,
            'nom_depot' => $depot->name,
            'url_depot' => $depot->url,
            'depot_principal' => $depot->is_primary ? 1 : 0,
            'hash_xml' => $depot->xml_hash ?? '',
        ];
    }

    return $tab;
}

function info_all_depot($config)
{
    $tab = [];
    $depots = Depot::orderBy('id')->get();

    foreach ($depots as $depot) {
        $tab[$depot->id] = [
            'id_depot' => $depot->id,
            'nom_depot' => $depot->name,
            'url_depot' => $depot->url,
            'depot_actif' => $depot->is_active ? 1 : 0,
            'depot_principal' => $depot->is_primary ? 1 : 0,
            'hash_xml' => $depot->xml_hash ?? '',
        ];
    }

    return $tab;
}

function info_depot_appli($config, $id_depot)
{
    $tab = [];
    $apps = DepotApplication::where('depot_id', $id_depot)->get();

    foreach ($apps as $app) {
        $tab[] = [
            'id_depot_applications' => $app->id,
            'id_nom_app' => $app->app_id,
            'nom_app' => $app->name,
            'xml' => $app->xml ?? '',
            'url_xml' => $app->xml_url ?? '',
            'sha_xml' => $app->xml_sha ?? '',
            'url_log' => $app->log_url ?? '',
            'categorie' => $app->category ?? '',
            'compatibilite' => $app->compatibility ?? '',
            'version' => $app->version ?? '',
            'branche' => $app->branch ?? 'stable',
            'date' => $app->updated_at ? $app->updated_at->format('Y-m-d H:i:s') : '',
        ];
    }

    return $tab;
}

function info_depot_principal($config)
{
    $tab = [];
    $depots = Depot::active()->primary()->get();

    foreach ($depots as $depot) {
        $tab[] = ['id_depot' => $depot->id];
    }

    return $tab;
}

function info_depot_id_appli($config, $id_depot_applications)
{
    $app = DepotApplication::find($id_depot_applications);
    if (!$app) {
        return [];
    }

    return [
        'id_depot_applications' => $app->id,
        'id_nom_app' => $app->app_id,
        'nom_app' => $app->name,
        'xml' => $app->xml ?? '',
        'url_xml' => $app->xml_url ?? '',
        'sha_xml' => $app->xml_sha ?? '',
        'url_log' => $app->log_url ?? '',
        'categorie' => $app->category ?? '',
        'compatibilite' => $app->compatibility ?? '',
        'version' => $app->version ?? '',
        'branche' => $app->branch ?? 'stable',
        'date' => $app->updated_at ? $app->updated_at->format('Y-m-d H:i:s') : '',
    ];
}

function info_appli_version_depot($config, $id_depot, $id_nom_appli)
{
    $tab = [];
    $apps = DepotApplication::where('depot_id', $id_depot)
        ->where('app_id', $id_nom_appli)
        ->get();

    foreach ($apps as $app) {
        $tab[] = [
            'id_depot_applications' => $app->id,
            'id_nom_app' => $app->app_id,
            'nom_app' => $app->name,
            'xml' => $app->xml ?? '',
            'url_xml' => $app->xml_url ?? '',
            'sha_xml' => $app->xml_sha ?? '',
            'url_log' => $app->log_url ?? '',
            'categorie' => $app->category ?? '',
            'compatibilite' => $app->compatibility ?? '',
            'version' => $app->version ?? '',
            'branche' => $app->branch ?? 'stable',
            'date' => $app->updated_at ? $app->updated_at->format('Y-m-d H:i:s') : '',
        ];
    }

    return $tab;
}

function info_categorie($config)
{
    $tab = [];
    $categories = Application::select('category', DB::raw('count(distinct id) as nb'))
        ->groupBy('category')
        ->orderBy('category')
        ->get();

    foreach ($categories as $row) {
        $key = strtolower($row->category ?? 'sans catégorie');
        $tab[$key] = [
            'categorie' => $row->category ?? '',
            'nb_app' => (string) $row->nb,
        ];
    }

    return $tab;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉCRITURE — Postes
// ═══════════════════════════════════════════════════════════════════════════════

function insert_poste_info_wpkg($config, $info)
{
    $ws = Workstation::create([
        'name' => $info['nom_poste'],
        'os' => $info['typewin'] ?? null,
        'last_report_at' => $info['datetime'] ?? null,
        'ip' => $info['ip'] ?? null,
        'mac' => $info['mac_address'] ?? null,
        'report_sha' => $info['sha256'] ?? null,
        'log_path' => $info['logfile'] ?? null,
        'report_path' => $info['rapportfile'] ?? null,
        'uuid' => $info['uuid_poste'] ?? null,
        'status' => isset($info['flag_poste']) && $info['flag_poste'] == 1 ? 'protected' : 'active',
    ]);

    if (function_exists('apcu_delete')) {
        apcu_delete("wpkg_poste_" . $info['nom_poste']);
    }

    return $ws->id;
}

function update_poste_info_wpkg($config, $info)
{
    $ws = Workstation::where('name', $info['nom_poste'])->first();
    if (!$ws) {
        return 0;
    }

    $ws->update([
        'os' => $info['typewin'] ?? $ws->os,
        'last_report_at' => $info['datetime'] ?? $ws->last_report_at,
        'ip' => $info['ip'] ?? $ws->ip,
        'mac' => $info['mac_address'] ?? $ws->mac,
        'report_sha' => $info['sha256'] ?? $ws->report_sha,
        'log_path' => $info['logfile'] ?? $ws->log_path,
        'report_path' => $info['rapportfile'] ?? $ws->report_path,
    ]);

    if (function_exists('apcu_delete')) {
        apcu_delete("wpkg_poste_" . $info['nom_poste']);
    }

    return $ws->id;
}

function update_poste_uuid_wpkg($config, $id_poste, $uuid_poste, $flag_poste)
{
    Workstation::where('id', $id_poste)->update([
        'uuid' => $uuid_poste,
        'status' => $flag_poste == 1 ? 'protected' : 'active',
    ]);
}

function update_poste_nom_wpkg($config, $id_poste, $nom_poste)
{
    Workstation::where('id', $id_poste)->update(['name' => $nom_poste]);

    if (function_exists('apcu_delete')) {
        apcu_delete("wpkg_poste_" . $nom_poste);
    }
}

function delete_poste_info_wpkg($config, $id_poste)
{
    $ws = Workstation::find($id_poste);
    if (!$ws) {
        return;
    }

    $nom = $ws->name;

    // Supprimer les associations
    WorkstationApplicationStatus::where('workstation_id', $id_poste)->delete();
    $ws->groups()->detach();
    $ws->delete();

    if (function_exists('apcu_delete')) {
        apcu_delete("wpkg_poste_" . $nom);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉCRITURE — Rapports poste_app
// ═══════════════════════════════════════════════════════════════════════════════

function insert_info_app_poste($config, $id_poste, $id_app, $info)
{
    WorkstationApplicationStatus::create([
        'workstation_id' => $id_poste,
        'application_id' => $id_app,
        'installed_version' => $info['Revision'] ?? '',
        'status' => strtolower($info['Status'] ?? 'not-installed'),
        'reboot_required' => (bool) ($info['Reboot'] ?? false),
        'reported_at' => now(),
    ]);

    $ws = Workstation::find($id_poste);
    if ($ws && function_exists('apcu_delete')) {
        apcu_delete("wpkg_poste_" . $ws->name);
    }
}

function insert_mass_info_app_poste($config, $id_poste, $liste_app, $info)
{
    DB::beginTransaction();
    try {
        foreach ($info as $tmpInfo) {
            $md5 = hash('md5', $tmpInfo['id_nom_app']);
            $id_app = isset($liste_app[$md5]) ? $liste_app[$md5]['id_app'] : 0;

            WorkstationApplicationStatus::create([
                'workstation_id' => $id_poste,
                'application_id' => $id_app,
                'installed_version' => $tmpInfo['Revision'] ?? '',
                'status' => strtolower($tmpInfo['Status'] ?? 'not-installed'),
                'reboot_required' => (bool) ($tmpInfo['Reboot'] ?? false),
                'reported_at' => now(),
            ]);
        }
        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('sql_shim: insert_mass_info_app_poste failed', ['error' => $e->getMessage()]);
    }

    $ws = Workstation::find($id_poste);
    if ($ws && function_exists('apcu_delete')) {
        apcu_delete("wpkg_poste_" . $ws->name);
    }
}

function delete_info_app_poste($config, $id_poste)
{
    $ws = Workstation::find($id_poste);

    WorkstationApplicationStatus::where('workstation_id', $id_poste)->delete();

    if ($ws && function_exists('apcu_delete')) {
        apcu_delete("wpkg_poste_" . $ws->name);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉCRITURE — Applications
// ═══════════════════════════════════════════════════════════════════════════════

function insert_applications($config, $list_appli)
{
    $app = Application::create([
        'app_id' => $list_appli['id_nom_app'],
        'name' => $list_appli['nom_app'],
        'version' => $list_appli['version_app'] ?? null,
        'compatibility' => $list_appli['compatibilite_app'] ?? null,
        'category' => $list_appli['categorie_app'] ?? null,
    ]);

    return $app->id;
}

function update_applications($config, $id_app, $list_appli)
{
    Application::where('id', $id_app)->update([
        'app_id' => $list_appli['id_nom_app'],
        'name' => $list_appli['nom_app'],
        'version' => $list_appli['version_app'] ?? null,
        'compatibility' => $list_appli['compatibilite_app'] ?? null,
        'category' => $list_appli['categorie_app'] ?? null,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉCRITURE — Dépendances
// ═══════════════════════════════════════════════════════════════════════════════

function delete_dependances($config)
{
    DB::transaction(function () {
        DB::table('application_dependencies')->truncate();
    });
}

function insert_dependance($config, $id_appli, $id_required)
{
    DB::table('application_dependencies')->insertOrIgnore([
        'application_id' => $id_appli,
        'required_application_id' => $id_required,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉCRITURE — Journal (installation_logs)
// ═══════════════════════════════════════════════════════════════════════════════

function insert_journal_app($config, $id_appli, $info)
{
    DB::table('installation_logs')->insert([
        'application_id' => $id_appli,
        'status' => $info['operation_journal_app'] ?? 'pending',
        'version' => null,
        'message' => ($info['user_journal_app'] ?? '') . ' - ' . ($info['date_journal_app'] ?? ''),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function update_sha_xml_journal($config, $url_xml_tmp)
{
    // Récupère le dernier journal par application pour mettre à jour les SHA
    $journals = DB::table('installation_logs as il1')
        ->leftJoin('installation_logs as il2', function ($join) {
            $join->on('il1.application_id', '=', 'il2.application_id')
                ->whereColumn('il1.id', '<', 'il2.id');
        })
        ->whereNull('il2.id')
        ->where('il1.application_id', '!=', 0)
        ->select('il1.application_id', 'il1.message', 'il1.id')
        ->orderBy('il1.application_id')
        ->get();

    foreach ($journals as $journal) {
        // Extraire le nom xml du message (convention legacy)
        $xmlFile = $url_xml_tmp . basename($journal->message);
        $resolved = realpath($xmlFile);
        $allowedDir = realpath($url_xml_tmp);
        if ($resolved && $allowedDir && str_starts_with($resolved, $allowedDir . DIRECTORY_SEPARATOR) && file_exists($resolved)) {
            $sha512 = hash_file('sha512', $resolved);
            Application::where('id', $journal->application_id)->update([
                'xml_sha' => $sha512,
            ]);
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉCRITURE — Profils (applications_profile legacy → app_profile pivots)
// ═══════════════════════════════════════════════════════════════════════════════

function truncate_table_profiles($config)
{
    DB::transaction(function () {
        DB::table('app_profile_application')->truncate();
        DB::table('app_profile_workstation_group')->truncate();
        DB::table('workstation_group_workstation')->truncate();
    });
}

function insert_application_profile($config, $type_entite, $id_entite, $id_appli)
{
    if ($type_entite === 'parc') {
        $group = WorkstationGroup::find($id_entite);
        if (!$group) {
            return 0;
        }

        // Trouver ou créer un profil pour ce groupe
        $profile = $group->appProfiles()->first();
        if (!$profile) {
            $profile = AppProfile::create([
                'name' => 'profile_' . $group->name,
                'display_name' => 'Profil ' . ($group->display_name ?? $group->name),
                'is_active' => true,
            ]);
            $group->appProfiles()->attach($profile->id);
        }

        if (!$profile->applications()->where('applications.id', $id_appli)->exists()) {
            $profile->applications()->attach($id_appli);
        }

        _sql_shim_clear_wpkg_cache();
        return $profile->id;

    } elseif ($type_entite === 'poste') {
        // DÉVIATION : le schéma Eloquent n'a pas de relation directe poste→application
        // (l'original MySQL utilisait type_entite='poste' dans applications_profile).
        // On attache l'app au profil du premier groupe du poste — cela affecte
        // tous les postes du groupe, pas uniquement ce poste.
        $ws = Workstation::find($id_entite);
        if (!$ws) {
            return 0;
        }

        $groups = $ws->groups;
        foreach ($groups as $group) {
            $profile = $group->appProfiles()->first();
            if ($profile) {
                if (!$profile->applications()->where('applications.id', $id_appli)->exists()) {
                    $profile->applications()->attach($id_appli);
                }
                Log::info('sql_shim: insert_application_profile type=poste redirigé vers profil groupe', [
                    'id_poste' => $id_entite,
                    'id_appli' => $id_appli,
                    'group' => $group->name,
                    'profile' => $profile->id,
                ]);
                _sql_shim_clear_wpkg_cache();
                return $profile->id;
            }
        }

        Log::warning('sql_shim: insert_application_profile poste sans profil', [
            'id_poste' => $id_entite,
            'id_appli' => $id_appli,
        ]);
        return 0;
    }

    return 0;
}

function insert_parc_profile($config, $id_poste, $id_parc)
{
    $group = WorkstationGroup::find($id_parc);
    if (!$group) {
        return 0;
    }

    if (!$group->workstations()->where('workstation_id', $id_poste)->exists()) {
        $group->workstations()->attach($id_poste);
    }

    _sql_shim_clear_wpkg_cache();
    return 1;
}

function delete_parc_profile($config, $id_poste, $id_parc)
{
    $group = WorkstationGroup::find($id_parc);
    if ($group) {
        $group->workstations()->detach($id_poste);
    }

    _sql_shim_clear_wpkg_cache();
}

function insert_parc($config, $nom_parc, $uuid = null)
{
    $group = WorkstationGroup::create([
        'name' => $nom_parc,
        'display_name' => $nom_parc,
        'ad_guid' => $uuid,
        'is_active' => true,
    ]);

    return $group->id;
}

function update_parc($config, $id, $nom_parc, $uuid)
{
    WorkstationGroup::where('id', $id)->update([
        'name' => $nom_parc,
        'display_name' => $nom_parc,
        'ad_guid' => $uuid,
    ]);
}

function delete_parc_wpkg($config, $id_parc)
{
    $group = WorkstationGroup::find($id_parc);
    if (!$group) {
        return;
    }

    // Détacher les appProfiles et leurs applications
    foreach ($group->appProfiles as $profile) {
        $profile->applications()->detach();
    }
    $group->appProfiles()->detach();
    $group->workstations()->detach();
    $group->delete();

    _sql_shim_clear_wpkg_cache();
}

function set_entite_apps($config, $list_id_appli, $nom_entite, $type_entite)
{
    $result = ['out' => 0, 'in' => 0];

    if ($type_entite === 'parc') {
        $group = WorkstationGroup::where('name', $nom_entite)->first();
        if (!$group) {
            return $result;
        }

        $profile = $group->appProfiles()->first();
        if (!$profile) {
            $profile = AppProfile::create([
                'name' => 'profile_' . $group->name,
                'display_name' => 'Profil ' . ($group->display_name ?? $group->name),
                'is_active' => true,
            ]);
            $group->appProfiles()->attach($profile->id);
        }

        $currentIds = $profile->applications()->pluck('applications.id')->toArray();
        $newIds = array_map('intval', array_filter($list_id_appli));

        $changes = $profile->applications()->sync($newIds);
        $result['in'] = count($changes['attached'] ?? []);
        $result['out'] = count($changes['detached'] ?? []);

    } elseif ($type_entite === 'poste') {
        // Pour les postes, pas de mapping direct simple
        Log::info('sql_shim: set_entite_apps pour poste — opération limitée', [
            'nom_entite' => $nom_entite,
        ]);
    }

    _sql_shim_clear_wpkg_cache();
    return $result;
}

function set_appli_entites($config, $list_id_entite, $type_entite, $id_nom_appli)
{
    $app = Application::where('app_id', $id_nom_appli)->first();
    if (!$app) {
        return ['out' => 0, 'in' => 0];
    }

    $result = ['out' => 0, 'in' => 0];

    if ($type_entite === 'parc') {
        $entityIds = array_map('intval', array_filter($list_id_entite));

        foreach ($entityIds as $groupId) {
            $group = WorkstationGroup::find($groupId);
            if (!$group) {
                continue;
            }

            $profile = $group->appProfiles()->first();
            if (!$profile) {
                $profile = AppProfile::create([
                    'name' => 'profile_' . $group->name,
                    'display_name' => 'Profil ' . ($group->display_name ?? $group->name),
                    'is_active' => true,
                ]);
                $group->appProfiles()->attach($profile->id);
            }

            if (!$profile->applications()->where('applications.id', $app->id)->exists()) {
                $profile->applications()->attach($app->id);
                $result['in']++;
            }
        }

        // Retirer des groupes non listés
        $allGroups = WorkstationGroup::all();
        foreach ($allGroups as $group) {
            if (in_array($group->id, $entityIds)) {
                continue;
            }
            foreach ($group->appProfiles as $profile) {
                if ($profile->applications()->where('applications.id', $app->id)->exists()) {
                    $profile->applications()->detach($app->id);
                    $result['out']++;
                }
            }
        }
    }

    _sql_shim_clear_wpkg_cache();
    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉCRITURE — Mise en forme (no-ops — table non migrée)
// ═══════════════════════════════════════════════════════════════════════════════

function update_mef($config, $label, $type, $valeur)
{
    // Table mise_en_forme non migrée — no-op
    Log::debug('sql_shim: update_mef ignored (table not migrated)', [
        'label' => $label, 'type' => $type,
    ]);
}

function update_mef_defaut($config)
{
    // no-op
}

function update_mef_test($config)
{
    // no-op
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉCRITURE — Dépôts / depot_applications
// ═══════════════════════════════════════════════════════════════════════════════

function truncate_depot_applications($config)
{
    DepotApplication::truncate();
}

function delete_info_pkg_depot($config, $id_depot)
{
    DepotApplication::where('depot_id', $id_depot)->delete();
}

function insert_appli_depot($config, $tab)
{
    DepotApplication::updateOrCreate(
        [
            'depot_id' => $tab['id_depot'],
            'app_id' => $tab['id_nom_app'],
        ],
        [
            'name' => $tab['nom_app'],
            'xml' => $tab['xml'] ?? null,
            'xml_url' => $tab['url_xml'] ?? null,
            'xml_sha' => $tab['sha_xml'] ?? null,
            'log_url' => $tab['url_log'] ?? null,
            'category' => $tab['categorie'] ?? null,
            'compatibility' => $tab['compatibilite'] ?? null,
            'version' => $tab['version'] ?? null,
            'branch' => $tab['branche'] ?? 'stable',
        ]
    );
}

function desactive_depot_applis($config, $id_depot)
{
    // La table depot_applications n'a pas de champ "active" dans le nouveau schéma
    // On marque en supprimant (sera recréé au prochain sync)
    Log::debug('sql_shim: desactive_depot_applis — marking for deletion', ['depot_id' => $id_depot]);
}

function delete_depot_applis_inactives($config, $id_depot)
{
    // Pas de champ active dans le nouveau schéma — no-op
    Log::debug('sql_shim: delete_depot_applis_inactives — no-op (no active flag)', ['depot_id' => $id_depot]);
}

function update_hash_depot($config, $id_depot, $hash_xml)
{
    Depot::where('id', $id_depot)->update(['xml_hash' => $hash_xml]);
}

function update_depot_activation($config, $id_depot, $depot_actif)
{
    $depot = Depot::find($id_depot);
    if (!$depot) {
        return 0;
    }

    // Ne pas désactiver le dépôt principal
    if ($depot->is_primary && !$depot_actif) {
        return 0;
    }

    // Vérifier qu'il reste au moins un dépôt actif
    $activeCount = Depot::active()->count();
    if (!$depot_actif && $activeCount <= 1) {
        return 0;
    }

    $depot->update(['is_active' => (bool) $depot_actif]);
    return 1;
}

function update_depot_principal($config, $id_depot_principal)
{
    $depot = Depot::where('id', $id_depot_principal)->active()->first();
    if (!$depot) {
        return 0;
    }

    // Désactiver l'ancien principal
    Depot::where('is_primary', true)->update(['is_primary' => false]);
    // Activer le nouveau
    $depot->update(['is_primary' => true]);

    return 1;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MAINTENANCE — Postes
// ═══════════════════════════════════════════════════════════════════════════════

function maintenance_liste_poste($config, $flag, $uuid)
{
    $query = Workstation::query();

    if ($flag != -1) {
        $statusMap = [0 => 'active', 1 => 'protected'];
        if (isset($statusMap[$flag])) {
            $query->where('status', $statusMap[$flag]);
        }
    }

    switch ($uuid) {
        case 0:
            // uuid=0 : postes sans UUID ou marqués pour suppression
            $query->where(function ($q) {
                $q->whereNull('uuid')
                    ->orWhere('status', 'pending-deletion');
            });
            break;
        case 1:
            // uuid=1 : postes avec UUID et non marqués pour suppression
            $query->whereNotNull('uuid')
                ->where('status', '!=', 'pending-deletion');
            break;
    }

    $query->orderBy('updated_at');
    $workstations = $query->get();

    $tab = [];
    foreach ($workstations as $ws) {
        // Compter les rapports
        $reportCounts = WorkstationApplicationStatus::where('workstation_id', $ws->id)
            ->selectRaw("count(distinct application_id) as nb_appli, sum(case when status = 'installed' then 1 else 0 end) as nb_appli_installed")
            ->first();

        $tab[strtolower($ws->name)] = [
            'id' => $ws->id,
            'nom_poste' => strtolower($ws->name),
            'OS_poste' => $ws->os ?? '',
            'date_rapport_poste' => $ws->last_report_at ? $ws->last_report_at->format('Y-m-d H:i:s') : '',
            'IP_poste' => $ws->ip ?? '',
            'mac_address_poste' => $ws->mac ?? '',
            'file_log_poste' => $ws->log_path ?? '',
            'file_rapport_poste' => $ws->report_path ?? '',
            'date_modification_poste' => $ws->updated_at ? $ws->updated_at->format('Y-m-d H:i:s') : '',
            'uuid_poste' => $ws->uuid,
            'flag_poste' => $ws->status === 'protected' ? 1 : 0,
            'nb_appli_installed' => $reportCounts->nb_appli_installed ?? 0,
            'nb_appli' => $reportCounts->nb_appli ?? 0,
        ];
    }

    return $tab;
}

function maintenance_poste_protection($config, $id_poste)
{
    $ws = Workstation::find($id_poste);
    if (!$ws) {
        return 0;
    }

    if ($ws->status !== 'protected') {
        $ws->update(['status' => 'protected']);
        return $ws->name;
    }

    return 0;
}

function maintenance_poste_deprotection($config, $id_poste)
{
    $ws = Workstation::find($id_poste);
    if (!$ws) {
        return 0;
    }

    if ($ws->status !== 'active' && !empty($ws->uuid)) {
        $ws->update(['status' => 'active']);
        return $ws->name;
    }

    return 0;
}

function maintenance_poste_suppression($config, $id_poste)
{
    $ws = Workstation::find($id_poste);
    if (!$ws) {
        return 0;
    }

    if (empty($ws->uuid) || $ws->uuid === null) {
        $ws->update([
            'status' => 'pending-deletion',
        ]);
        return $ws->name;
    }

    // Idempotence : déjà marqué pour suppression
    if ($ws->status === 'pending-deletion') {
        return $ws->name;
    }

    return 0;
}

function maintenance_poste_reset_wpkg($config, $id_poste)
{
    $ws = Workstation::find($id_poste);
    if (!$ws) {
        return 0;
    }

    $ws->update(['report_sha' => 'miaou']);

    _sql_shim_clear_wpkg_cache();
    return $ws->name;
}

// ═══════════════════════════════════════════════════════════════════════════════
// STRUCTURE / UTILITAIRES
// ═══════════════════════════════════════════════════════════════════════════════

function test_parent($config)
{
    // La structure parent/enfant est déjà gérée par workstation_groups.parent_id
    return 1;
}

function test_mef($config)
{
    // Table mise_en_forme non migrée — no-op
    // Les valeurs par défaut sont retournées par mise_en_forme_personnalisee()
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER INTERNE — Cache APCu
// ═══════════════════════════════════════════════════════════════════════════════

function _sql_shim_clear_wpkg_cache()
{
    if (function_exists('apcu_delete') && class_exists('APCUIterator')) {
        try {
            $iterator = new \APCUIterator('/^wpkg_/');
            foreach ($iterator as $item) {
                apcu_delete($item['key']);
            }
        } catch (\Throwable $e) {
            // silencieux
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// AUTO-EXECUTE — reproduit le comportement du début de wpkg_libsql.php
// ═══════════════════════════════════════════════════════════════════════════════
// Le fichier original exécute au chargement :
//   test_mef($config);
//   $mise_en_forme_perso = mise_en_forme_personnalisee($config);
//   foreach ($mise_en_forme_perso as $key => $value) { ${$key} = $value; }
//
// On reproduit ce comportement avec $config vide (ignoré par le shim).
// Les variables globales ($warning_bg, $ok_txt, etc.) sont utilisées
// par ~15 fichiers legacy (wpkg/mef_*, wpkg/parc_statuts, etc.).
// Risque de collision faible : les noms sont spécifiques (préfixés par contexte couleur).

test_mef([]);
$mise_en_forme_perso = mise_en_forme_personnalisee([]);
foreach ($mise_en_forme_perso as $key => $value) {
    ${$key} = $value;
}
