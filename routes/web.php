<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ParcController;
use App\Http\Controllers\AppPolicyController;
use App\Http\Controllers\WallpaperController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChangePasswordController;
// Story 16.13bis — Module migration SE4 → SE5 (App\Auth\V1\Migration).
use App\Auth\V1\Migration\Http\Controllers\MigrationController as AuthV1MigrationController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Route de test avec middleware sambaedu.auth
Route::get('/test-auth', function () {
    return response()->json(['status' => 'ok', 'authenticated' => true, 'time' => now()->toDateTimeString()]);
})->middleware('sambaedu.auth');

Route::prefix("authentication")->name("auth.")->group(function () {
    // Connexion
    Route::get("login", [AuthController::class, 'showLogin'])->name('login');
    Route::post("login", [AuthController::class, 'authenticate'])->name('authenticate');
    Route::get("signout", [AuthController::class, 'logout'])->name('logout');

    // Callback CAS — validation du ticket retourné par le serveur SSO
    Route::get("cas/callback", [AuthController::class, 'casCallback'])->name('cas.callback');

    // Callback ENT (optionnel pour futures intégrations)
    Route::get("ent/callback", [AuthController::class, 'entCallback'])->name('ent.callback');

    // Modification du mot de passe
    Route::get("change-password", [ChangePasswordController::class, 'index'])
        ->middleware('password.change')
        ->name('change-password');
    Route::post("change-password", [ChangePasswordController::class, 'changePassword'])
        ->middleware('password.change')
        ->name('change-password.submit');

});


// Route pour l'interface utilisateur modernisée
Route::prefix('app')->middleware('sambaedu.auth')->name('app.')->group(function () {
    // Navigation legacy déplacée sous /admin/homelegacy

    Route::livewire('/dashboard', 'pages::dashboard.index')->name('dashboard');
    Route::livewire('/dashboard/activity', 'pages::dashboard.activity.index')->name('dashboard.activity');
    Route::livewire('/workers', 'pages::workers.index')->name('workers.index');
    Route::livewire('/workers/{pid}', 'pages::workers.[pid].index')->whereNumber('pid')->name('workers.show');

    // Story 7.2 — AC8 : middleware can: sur routes sensibles.
    Route::livewire('/users', 'pages::users.index')
        ->middleware('can:user.read')
        ->name('users');

    // Création d'utilisateur (Livewire, nécessite droits admin)
    Route::livewire('/users/new', 'pages::users.new.index')
        ->middleware(['sambaedu.auth', 'sambaedu.admin', 'can:user.modify'])
        ->name('users.new');

    // // Actions groupées sur les utilisateurs (nécessite droits admin)
    // Route::middleware(['sambaedu.auth', 'sambaedu.admin'])->group(function () {
    //     Route::post('/users/bulk-enable', [UsersController::class, 'bulkEnable'])->name('users.bulk-enable');
    //     Route::post('/users/bulk-disable', [UsersController::class, 'bulkDisable'])->name('users.bulk-disable');
    //     Route::post('/users/bulk-reset-password', [UsersController::class, 'bulkResetPassword'])->name('users.bulk-reset-password');
    //     Route::post('/users/bulk-assign-groups', [UsersController::class, 'bulkAssignGroupsStore'])->name('users.bulk-assign-groups.store');
    // });

    // Gestion des droits (nécessite droits admin)
    // Story 7.1 — Review #5 : middleware `can:user.assign.right` pour bloquer
    // tout accès à la page (y compris computed `historyEntries`) par un non-admin.
    Route::livewire('/rights-management', 'pages::rights-management.index')
        ->middleware('can:user.assign.right')
        ->name('rights-management');
    // Story 7.2 — création / édition d'un profil (rôle Spatie).
    Route::livewire('/rights-management/profiles/new', 'pages::rights-management.profiles.new.index')
        ->middleware('can:user.assign.right')
        ->name('rights-management.profiles.create');
    Route::livewire('/rights-management/profiles/{id}', 'pages::rights-management.profiles.[id].index')
        ->whereNumber('id')
        ->middleware('can:user.assign.right')
        ->name('rights-management.profiles.show');

    // Groupes d'utilisateurs — Story 7.2 AC8 : middleware can: user.read
    Route::livewire('/users/groups/new', 'pages::users.groups.new.index')
        ->middleware('can:user.modify')
        ->name('users.groups.new');
    Route::match(['GET', 'POST'], '/users/groups/legacy-new', [\App\Http\Controllers\LegacyEmbedController::class, 'show'])
        ->defaults('module', 'annu2/add_group.php')
        ->name('users.groups.legacy-new');
    Route::livewire('/users/groups/{id}', 'pages::users.groups.[id].index')
        ->whereNumber('id')
        ->middleware('can:user.read')
        ->name('users.groups.edit');
    
    // Gestion des quotas (nécessite droits admin)
    Route::post('/users/groups/{groupCn}/quota', [\App\Http\Controllers\QuotaController::class, 'updateGroupQuota'])
        ->middleware('sambaedu.admin')
        ->name('users.groups.quota.update');
    Route::post('/users/{login}/quota', [\App\Http\Controllers\QuotaController::class, 'updateUserQuota'])
        ->middleware('sambaedu.admin')
        ->name('users.quota.update');

    // Téléchargement des exports post-bulk-reset (token signé, TTL 20 min)
    // IMPORTANT : ces routes DOIVENT être définies AVANT `/users/{login}`
    // sinon Laravel va matcher `{login}` = "password-reset" en premier.
    Route::get('/users/password-reset/{token}/pdf', [\App\Http\Controllers\PasswordResetExportController::class, 'downloadPdf'])
        ->middleware('signed')
        ->name('users.password-reset.pdf');
    Route::get('/users/password-reset/{token}/csv', [\App\Http\Controllers\PasswordResetExportController::class, 'downloadCsv'])
        ->middleware('signed')
        ->name('users.password-reset.csv');

    // Utilisateur individuel — Story 7.2 AC8.
    Route::livewire('/users/{login}', 'pages::users.[login].index')
        ->middleware('can:user.read')
        ->name('user.show');

    // Routes Livewire pour les raccourcis
    Route::livewire('/shortcuts', 'pages::shortcuts.index')->name('shortcuts');
    Route::livewire('/shortcuts/new', 'pages::shortcuts.new.index')->name('shortcuts.new');
    Route::livewire('/shortcuts/{id}', 'pages::shortcuts.[id].index')->name('shortcuts.show');

    // ========================================
    // Paramètres du Parc - Profils applicatifs et catalogue
    // Story 7.2 AC8 : can:computer.install sur l'index.
    // ========================================
    Route::prefix('parc-settings')->name('parc-settings.')->group(function () {
        // Page principale avec onglets profils/applications
        Route::livewire('/', 'pages::parc-settings.index')
            ->middleware('can:computer.install')
            ->name('index');

        // Profils applicatifs
        Route::livewire('/profiles/{id}', 'pages::parc-settings.profiles.index')
            ->middleware('can:computer.install')
            ->name('profiles.show');

        // Applications
        Route::livewire('/applications/{id}', 'pages::parc-settings.applications.index')
            ->middleware('can:computer.install')
            ->name('applications.show');

        // Fonds d'écran défauts établissement (story 4.7 AC 8)
        // Gate wallpaper.manage — page admin (post-review #6).
        Route::livewire('/wallpapers', 'pages::parc-settings.wallpapers.index')
            ->middleware('can:wallpaper.manage')
            ->name('wallpapers');

        // Personnalisation applications (story 4.8 — Firefox, Thunderbird…)
        Route::livewire('/app-customizations', 'pages::parc-settings.app-customizations.index')
            ->middleware('can:app.customize')
            ->name('app-customizations');
    });

    // // Gestion des parcs (Livewire)
    // Route::livewire('/parcs', 'pages::parcs.index')->name('parcs');
    // Route::livewire('/parcs/new', 'pages::parcs.new.index')->middleware(['sambaedu.auth', 'sambaedu.admin'])->name('parcs.new');
    // Route::livewire('/parcs/{parc}', 'pages::parcs.[parc].index')->name('parc.show');



    // ========================================
    // Gestion du Parc (Section 1 - MySQL source)
    // Story 7.2 AC8 : middleware `can:viewAny-workstationGroup` sur les routes
    // de lecture — accepte les droits globaux ET les délégués scopés (au moins
    // une délégation positive active). Le scoping fin par ressource est appliqué
    // dans les mount Livewire via `Gate::allows('view', $group|$machine)`.
    // Actions fines (control, élévation) sont gardées au niveau Policy dans les composants.
    // ========================================
    Route::prefix('parc')->name('parc.')->group(function () {
        // Page principale avec onglets machines/groupes
        Route::livewire('/', 'pages::parc.index')
            ->middleware('can:viewAny-workstationGroup')
            ->name('index');

        // Groupes de machines — scoping fin via WorkstationGroupPolicy dans le mount.
        Route::livewire('/groups/new', 'pages::parc.groups.new.index')
            ->middleware('can:computer.install')
            ->name('groups.new');
        Route::livewire('/groups/{id}', 'pages::parc.groups.[id].index')
            ->middleware('can:viewAny-workstationGroup')
            ->name('groups.show');
        Route::livewire('/groups/{id}/edit', 'pages::parc.groups.[id].edit.index')
            ->middleware('can:computer.install')
            ->name('groups.edit');

        // Historique d'exécution d'une programmation (story 4-4 AC9)
        Route::livewire('/groups/{id}/schedules/{scheduleId}/runs', 'pages::parc.groups.[id].schedules.[scheduleId].runs.index')
            ->whereNumber('id')
            ->whereNumber('scheduleId')
            ->middleware('can:viewAny-workstationGroup')
            ->name('groups.schedules.runs');

        // Machines — scoping fin via MachinePolicy (Story 7.2).
        Route::livewire('/machines/{id}', 'pages::parc.machines.[id].index')
            ->middleware('can:viewAny-workstationGroup')
            ->name('machines.show');
    });

    // ========================================
    // Story 8.1 — Réseau / DHCP (FR20 + FR22)
    // Permissions :
    //   - viewAny-dhcp (= server.admin) : lecture liste + baux + rapport.
    //   - manage-dhcp  (= server.admin) : create / edit / delete / import.
    // (cf. App\Policies\DhcpPolicy, Story 7.2 / Epic 7)
    // ========================================
    Route::prefix('network/dhcp')->name('network.dhcp')->group(function () {
        Route::livewire('/', 'pages::network.dhcp.index')
            ->middleware('can:viewAny-dhcp')
            ->name('');

        // Review code 8.1 #7 (Q3) : page `/new` supprimée — la modale
        // create/edit de `/index` est la voie unique pour créer une
        // réservation (pas de duplication).

        Route::livewire('/import', 'pages::network.dhcp.import.index')
            ->middleware('can:manage-dhcp')
            ->name('.import');

        // Rapport d'import : viewAny-dhcp suffit (lecture seule).
        Route::livewire('/import/{uuid}', 'pages::network.dhcp.import.[uuid].index')
            ->middleware('can:viewAny-dhcp')
            ->name('.import.report');
    });

    // ========================================
    // Story 15.5 — Dashboard d'état déploiement WPKG (transversal aux parcs).
    // Permission lecture : viewAny-workstationGroup (cohérence 15.4).
    // Permission re-évaluation : wpkg.assign (drill-down vue détail poste).
    // ========================================
    Route::prefix('wpkg/deployments')->name('wpkg.deployments')->group(function () {
        // /app/wpkg/deployments
        Route::livewire('/', 'pages::wpkg.deployments.index')
            ->middleware('can:viewAny-workstationGroup')
            ->name('');

        // /app/wpkg/deployments/list
        Route::livewire('/list', 'pages::wpkg.deployments.list')
            ->middleware('can:viewAny-workstationGroup')
            ->name('.list');

        // /app/wpkg/deployments/workstation/{workstation}
        // Drill-down depuis le dashboard. ID numérique uniquement.
        Route::livewire('/workstation/{workstation}', 'pages::wpkg.deployments.[workstation].index')
            ->whereNumber('workstation')
            ->middleware('can:viewAny-workstationGroup')
            ->name('.workstation');
    });

    // Miniature wallpaper (UI admin) — story 4.7 AC 8
    // Gate wallpaper.manage (post-review #6) — principe least-privilege, même
    // si le contenu n'est pas sensible.
    Route::get('/wallpapers/{wallpaper}/thumbnail', [WallpaperController::class, 'thumbnail'])
        ->middleware('can:wallpaper.manage')
        ->name('wallpapers.thumbnail');

});

// ========================================
// Story 16.9 — Redirections 301 des anciennes URLs /app/gpo/* vers
// /admin/settings/gpo/* (les vues Livewire vivent désormais sous le
// groupe admin, cf. plus bas).
//
// Déclarées AU TOP-LEVEL (hors du groupe `app/` middleware `sambaedu.auth`)
// car les redirections HTTP 301 sont publiques par nature — protéger le
// /app/gpo legacy par sambaedu.auth ferait que le middleware intercept la
// requête (302 vers /authentication/login) AVANT que le 301 ne se déclenche.
// La cible /admin/settings/gpo/* est elle-même protégée par sambaedu.auth +
// sambaedu.admin + can:server.admin, donc aucune perte de sécurité.
//
// Conservation des noms `app.gpo.*` pour ne pas casser les appels existants
// `route('app.gpo.index')` qui sont en cours de migration vers
// `route('admin.gpo.index')`. Permanent (301) — aucun retour arrière prévu.
//
// Ordre critique : routes statiques (wine, wpkg-deployment) AVANT la route
// paramétrée `/app/gpo/{guid}` (iso-Piège 1 / Story 16.6 fix #2). La regex
// GUID ne matche pas `wine`/`wpkg-deployment` mais on rend l'ordre explicite.
//
// Sécurité anti open-redirect : la regex GUID stricte (iso-Story 16.2 fix
// #9) est appliquée AUSSI sur les routes de redirection paramétrées pour
// bloquer toute valeur arbitraire.
// ========================================
Route::permanentRedirect('/app/gpo/wine', '/admin/settings/gpo/wine')
    ->name('app.gpo.wine');

Route::permanentRedirect('/app/gpo/wpkg-deployment', '/admin/settings/gpo/wpkg-deployment')
    ->name('app.gpo.wpkg-deployment');

// Routes paramétrées : closure pour interpoler le `{guid}` (Route::permanentRedirect
// ne supporte pas l'interpolation des paramètres). Regex GUID iso 16.2 fix #9.
Route::get('/app/gpo/{guid}', fn (string $guid) => redirect('/admin/settings/gpo/' . $guid, 301))
    ->where('guid', '\{?[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}?')
    ->name('app.gpo.show');

Route::get('/app/gpo/{guid}/links', fn (string $guid) => redirect('/admin/settings/gpo/' . $guid . '/links', 301))
    ->where('guid', '\{?[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}?')
    ->name('app.gpo.links');

Route::permanentRedirect('/app/gpo', '/admin/settings/gpo')
    ->name('app.gpo.index');

/*
|--------------------------------------------------------------------------
| Admin Routes - ControlHub Handshake Management
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware(['sambaedu.auth', 'sambaedu.admin'])->name('admin.')->group(function () {
    // Control Hub - Livewire fullpage component
    Route::livewire('/control-hub', 'pages::control-hub.index')->name('controlHub.control-hub');

    // Legacy Monitor - Dashboard des appels catchall
    Route::livewire('/legacy-monitor', 'pages::admin.legacy-monitor.index')->name('legacy-monitor');

    // Error Logger - Erreurs capturées (legacy PHP & exceptions Laravel)
    Route::livewire('/error-logger', 'pages::admin.error-logger.index')->name('error-logger');

    // Navigation legacy (menus SE4FS)
    Route::livewire('/homelegacy', 'pages::homelegacy.index')->name('homelegacy');

    // Synchronisation depuis l'AD — Story 7.2 AC8 : can:server.admin (action critique).
    Route::livewire('/sync-from-ad', 'pages::sync-from-ad.index')
        ->middleware('can:server.admin')
        ->name('sync-from-ad');

    // /admin/settings — Landing « Réglages ».
    // Page d'index regroupant en sections (Système / GPO / Migration / Réseau)
    // les liens vers les pages de configuration. Les ex-onglets Quotas & FS et
    // Profils itinérants sont désormais leurs propres routes (cf. ci-dessous).
    // La page /admin/migrate a été absorbée par la section Migration de ce landing.
    Route::livewire('/settings', 'pages::admin.settings.index')
        ->middleware('can:server.admin')
        ->name('settings');

    // /admin/quotas — Ex-onglet Quotas & FS (Story 5.1c) promu en route racine.
    Route::livewire('/quotas', 'pages::admin.quotas.index')
        ->middleware('can:server.admin')
        ->name('quotas');

    // /admin/settings/profils-itinerants — Ex-onglet Profils itinérants (1bis.18f)
    // promu en sous-route de /admin/settings (cohérent avec /admin/settings/gpo/*
    // et /admin/settings/system/*).
    Route::livewire('/settings/profils-itinerants', 'pages::admin.settings.profils-itinerants.index')
        ->middleware('can:server.admin')
        ->name('settings.profils-itinerants');
    /*
    |--------------------------------------------------------------------------
    | Story 3.6 — Gestion ISO Windows (D2)
    |--------------------------------------------------------------------------
    | Page admin web SE5 native qui porte iso-fonctionnellement
    | `sambaedu/ipxe/Win10/win_iso.php` (110 LOC) : listing des versions
    | Win{10,11}{,-old} déployées + formulaire URL Microsoft + dispatch async
    | Job Laravel queue (curl + sudo install-win-iso.sh) + polling Livewire
    | conditionnel + modale confirm + annulation.
    |
    | Sécurité : `sambaedu.auth + sambaedu.admin + can:server.admin` —
    | parité iso `/admin/sync-from-ad` et `/admin/settings`.
    |
    | Note : aucune route iPXE firmware n'est touchée — 3.6 est une page admin
    | web SE5, pas un endpoint firmware. Les routes 3.1-3.5 sous le namespace
    | `/ipxe/*` LAN-only restent inchangées.
    |
    | Une seule route Livewire fullpage (D2 décision finale) — les méthodes
    | `submitDownload()`, `confirmDownload()`, `cancelDownload($id)` sont des
    | méthodes intra-composant (parité iso `/admin/sync-from-ad`).
    */
    Route::livewire('/ipxe/iso-windows', 'pages::admin.ipxe.iso-windows.index')
        ->middleware('can:server.admin')
        ->name('ipxe.iso-windows');


    // ========================================
    // Story 16.9 — Exposition UI admin GPO sous `/admin/settings/gpo/*`.
    // Déplacement structurel des 5 pages Livewire SFC GPO livrées en Phase 1
    // (Stories 16.2, 16.3c, 16.5, 16.6) depuis `/app/gpo/*`. Permission
    // `can:server.admin` (iso-Phase 1) + middlewares de groupe `admin`
    // (`sambaedu.auth + sambaedu.admin`). Ordre critique : routes statiques
    // (wine, wpkg-deployment) AVANT la route paramétrée `{guid}` (Piège 1 /
    // iso-pattern Story 16.6 fix #2).
    // Les anciennes URLs `/app/gpo/*` sont conservées en redirection 301
    // permanente (cf. groupe `app/` plus haut dans ce fichier).
    // ========================================
    Route::prefix('settings/gpo')->name('gpo.')->group(function () {
        // Routes statiques Wine et WPKG-deployment AVANT la route {guid} paramétrée.
        Route::livewire('/wine', 'pages::admin.settings.gpo.wine.index')
            ->middleware('can:server.admin')
            ->name('wine');

        Route::livewire('/wpkg-deployment', 'pages::admin.settings.gpo.wpkg-deployment.index')
            ->middleware('can:server.admin')
            ->name('wpkg-deployment');

        // ============================================================
        // Story 16.14 — Nouvelles routes statiques AVANT /{guid}.
        // Ordre critique (anti-régression piège 1 de 16.9) :
        // les routes statiques DOIVENT précéder la route paramétrée.
        // ============================================================

        // C — Vue inverse OU → GPOs (AC3.1).
        Route::livewire('/by-ou', 'pages::admin.settings.gpo.by-ou.index')
            ->middleware('can:server.admin')
            ->name('by-ou');

        // D — Catalogue sections natives (AC4.1).
        Route::livewire('/sections', 'pages::admin.settings.gpo.sections.index')
            ->middleware('can:server.admin')
            ->name('sections');

        // Route détail paramétrée {guid} (regex Microsoft GUID, accolades
        // optionnelles — iso-pattern Story 16.2 fix #9 anti open-redirect).
        Route::livewire('/{guid}', 'pages::admin.settings.gpo.[guid].index')
            ->where('guid', '\{?[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}?')
            ->middleware('can:server.admin')
            ->name('show');

        // Route détail liaisons (segments distincts /links — ordre sans
        // incidence par rapport à `/{guid}`).
        Route::livewire('/{guid}/links', 'pages::admin.settings.gpo.[guid].links.index')
            ->where('guid', '\{?[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}?')
            ->middleware('can:server.admin')
            ->name('links');

        // Route listing (collection — déclarée en dernier, le préfixe `/`
        // ne matche pas les segments statiques au-dessus).
        Route::livewire('/', 'pages::admin.settings.gpo.index')
            ->middleware('can:server.admin')
            ->name('index');
    });

    // ============================================================
    // Story 16.14 — E : Dashboard jobs système (AC5.1).
    // Nouveau groupe settings/system — extensible (futures 16.12/16.13bis).
    // ============================================================
    Route::prefix('settings/system')->name('system.')->group(function () {
        Route::livewire('/jobs', 'pages::admin.settings.system.jobs.index')
            ->middleware('can:server.admin')
            ->name('jobs.index');
    });

    // ========================================
    // Story 16.12 — UI Livewire de consultation des logs d'exécution scripts.
    // `/admin/settings/scripts-logs/` (index paginé + bandeau indicateurs)
    // `/admin/settings/scripts-logs/{id}` (détail UUID-format constraint).
    // Permission `server.admin` (iso 16.9) + double check dans mount().
    // ========================================
    Route::prefix('settings/scripts-logs')->name('scripts-logs.')->group(function () {
        Route::livewire('/', 'pages::admin.settings.scripts-logs.index')
            ->middleware('can:server.admin')
            ->name('index');

        Route::livewire('/{id}', 'pages::admin.settings.scripts-logs.[id].index')
            ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
            ->middleware('can:server.admin')
            ->name('show');
    });

    // Routes de gestion des parcs
    Route::prefix('parcs')->name('parcs.')->group(function () {
        // Actions de masse
        Route::post('/{parc}/mass-action', [ParcController::class, 'massAction'])->name('mass-action');

        // Recherche
        Route::get('/search/machines', [ParcController::class, 'searchMachines'])->name('search.machines');

        // Import/Export
        Route::post('/import/csv', [ParcController::class, 'importCsv'])->name('import.csv');
        Route::get('/export/csv/{parc?}', [ParcController::class, 'exportCsv'])->name('export.csv');

        // Configuration du parc
        Route::get('/{parc}/shortcuts', [ParcController::class, 'shortcuts'])->name('shortcuts');
        Route::get('/{parc}/applications', [ParcController::class, 'applications'])->name('applications');

        // API endpoints
        Route::get('/{parc}/api/stats', [ParcController::class, 'apiStats'])->name('api.stats');
        Route::get('/api/search', [ParcController::class, 'apiSearch'])->name('api.search');
        Route::get('/api/type/{type}', [ParcController::class, 'apiGetByType'])->name('api.by-type');
        Route::get('/api/hierarchy', [ParcController::class, 'apiHierarchy'])->name('api.hierarchy');
        Route::get('/{parc}/api/exists', [ParcController::class, 'apiExists'])->name('api.exists');
    });
});

/*
|--------------------------------------------------------------------------
| Route publique pour les icônes des raccourcis (sans authentification)
|--------------------------------------------------------------------------
*/
Route::get('/shortcuts/icon/{name}', function (string $name) {
    $iconPath = '/etc/sambaedu/applications/shortcuts/' . $name . '.png';

    if (file_exists($iconPath)) {
        return response()->file($iconPath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    // Fallback vers l'icône par défaut
    $defaultIcon = public_path('elements/images/system-run.png');
    if (file_exists($defaultIcon)) {
        return response()->file($defaultIcon, ['Content-Type' => 'image/png']);
    }

    abort(404);
})->name('shortcuts.icon');

/*
|--------------------------------------------------------------------------
| Story 16.13bis — Migration SE4 → SE5 (fragment+reboot stateless)
|--------------------------------------------------------------------------
| Les 8 routes legacy `gpo/*_out.php` (+ `gpo/applications.php`) sont
| transformées en endpoints servant un **fragment de migration** texte
| (cmd Windows ou sh Linux) via `MigrationController::serveFragment` :
|
|   - Poste non-migré : fragment complet (download CA, enroll JWT,
|     write registre/endpoints.conf, shutdown /r /t 30).
|   - Poste déjà migré (lookup `workstations_migration_status`) :
|     fragment no-op (`exit 0` — pas de reboot intempestif).
|
| Le middleware `inject.bootstrap-fragment` 16.11 a été **supprimé** par
| la Story 16.13bis : la logique d'injection en préfixe d'une réponse
| legacy fonctionnelle est remplacée par un fragment autonome qui
| **remplace** la réponse legacy. Les méthodes `legacyOut` /
| `legacyDispatch` / `generate` des controllers métier deviennent code
| mort sur la route legacy (les appels en direct restent possibles via
| les méthodes `apiV1` 16.13 sur `/api/v1/workstation-config/*`).
|
| Pas d'auth, pas de check uuid bloquant : un poste non-migré n'a pas
| encore de JWT, c'est précisément le rôle du fragment d'enrôler.
| Throttle `300,1` conservé (parité rentrée scolaire 300 postes
| simultanés).
*/
Route::match(['GET', 'POST'], 'gpo/shortcuts_out.php',
    fn (\Illuminate\Http\Request $r) => app(AuthV1MigrationController::class)->serveFragment($r, 'shortcuts'))
    ->middleware('throttle:300,1')
    ->name('migration.legacy.shortcuts');

Route::match(['GET', 'POST'], 'gpo/wallpaper_out.php',
    fn (\Illuminate\Http\Request $r) => app(AuthV1MigrationController::class)->serveFragment($r, 'wallpaper'))
    ->middleware('throttle:300,1')
    ->name('migration.legacy.wallpaper');

/*
|--------------------------------------------------------------------------
| Endpoint script de purge profils itinérants (story 1bis.18f)
|--------------------------------------------------------------------------
| GET /admin/gpo/del-roam.sh — script bash text/plain consommé par les
| logon scripts. Auth via middleware AllowSe4FsScript (whitelist IP
| `se4fs_ip` OU paramètre query `se4_key`).
|
| Hors du groupe `sambaedu.admin` car les scripts logon n'ont pas de
| session web admin — l'auth IP/clé est suffisante (port natif du
| legacy `header_authorize_script`).
*/
Route::get('admin/gpo/del-roam.sh', [\App\Http\Controllers\Admin\RoamingProfileController::class, 'delRoamScript'])
    ->middleware(\App\Http\Middleware\AllowSe4FsScript::class)
    ->name('admin.gpo.del-roam-script');

/*
|--------------------------------------------------------------------------
| Story 16.13bis — Migration SE4 → SE5 : 6 routes legacy restantes
|--------------------------------------------------------------------------
| Suite du bloc transformé ci-dessus (shortcuts + wallpaper) : firefox,
| thunderbird, network, veyon, associations, applications.
|
| Les 8 endpoints renvoient désormais **un fragment de migration**
| (cmd Windows ou sh Linux) via `MigrationController::serveFragment`.
| Les noms de routes deviennent `migration.legacy.{endpoint}` (les
| anciens noms `wallpaper.legacy`, `app-policy.firefox.legacy`, etc.
| sont supprimés — grep le repo pour adapter les call-sites).
|
| Note D13 (option β) : `gpo/applications.php` est aussi transformé en
| fragment — la pose APCu `apps.<md5>` côté legacy disparaît (le poste
| migré utilise JWT 16.10, plus md5 APCu). Les méthodes `apiV1` 16.13
| des controllers continuent de servir `/api/v1/workstation-config/*`
| sans modification.
|
| Pas d'auth (poste non-migré sans JWT). Throttle `300,1` préservé.
*/
Route::match(['GET', 'POST'], 'gpo/firefox_out.php',
    fn (\Illuminate\Http\Request $r) => app(AuthV1MigrationController::class)->serveFragment($r, 'firefox'))
    ->middleware('throttle:300,1')
    ->name('migration.legacy.firefox');

Route::match(['GET', 'POST'], 'gpo/thunderbird_out.php',
    fn (\Illuminate\Http\Request $r) => app(AuthV1MigrationController::class)->serveFragment($r, 'thunderbird'))
    ->middleware('throttle:300,1')
    ->name('migration.legacy.thunderbird');

Route::match(['GET', 'POST'], 'gpo/network_out.php',
    fn (\Illuminate\Http\Request $r) => app(AuthV1MigrationController::class)->serveFragment($r, 'network'))
    ->middleware('throttle:300,1')
    ->name('migration.legacy.network');

Route::match(['GET', 'POST'], 'gpo/veyon_out.php',
    fn (\Illuminate\Http\Request $r) => app(AuthV1MigrationController::class)->serveFragment($r, 'veyon'))
    ->middleware('throttle:300,1')
    ->name('migration.legacy.veyon');

// Story 16.3c — POST uniquement legacy ; on garde POST uniquement pour
// parité fonctionnelle (un GET sans `list` retournait 400 legacy). Le
// fragment de migration ne consomme pas le body, mais on préserve la
// shape de la route pour ne pas créer un GET nouveau qui exposerait le
// chemin à d'autres clients.
Route::match(['POST'], 'gpo/associations_out.php',
    fn (\Illuminate\Http\Request $r) => app(AuthV1MigrationController::class)->serveFragment($r, 'associations'))
    ->middleware('throttle:300,1')
    ->name('migration.legacy.associations');

Route::match(['GET', 'POST'], 'gpo/applications.php',
    fn (\Illuminate\Http\Request $r) => app(AuthV1MigrationController::class)->serveFragment($r, 'applications'))
    ->middleware('throttle:300,1')
    ->name('migration.legacy.applications');

/*
|--------------------------------------------------------------------------
| Route canonique /api/policies/{kind}/{id}
|--------------------------------------------------------------------------
| Story 4.8 — AC 10. Route alternative propre en parallèle des iso-contrat.
| Placée dans web.php (pas api.php) pour éviter le préfixe /api/ global —
| en réalité nous préfixons à la main pour que le routing corresponde à
| `/api/policies/{kind}/{id}`. Pas d'auth (même design que iso-contrat).
*/
Route::get('api/policies/{kind}/{id}', [AppPolicyController::class, 'canonical'])
    ->middleware('throttle:300,1')
    ->name('app-policy.canonical');

/*
|--------------------------------------------------------------------------
| Story 15.2 — Endpoints HTTP WPKG hosts.xml / profiles.xml (parité legacy)
|--------------------------------------------------------------------------
| Pas de middleware web/auth/sambaedu.admin : confiance LAN, parité legacy
| stricte (décision user 2026-05-04 #3). Un middleware machine optionnel
| pourra être ajouté en Story 15.5.
| Doivent rester déclarés AVANT la catchall legacy ci-dessous.
*/
Route::get('/wpkg/hosts.xml', \App\Wpkg\Deployment\Http\Controllers\HostsXmlController::class)
    ->name('wpkg.hosts-xml')
    ->withoutMiddleware(['web']);
Route::get('/wpkg/profiles.xml', \App\Wpkg\Deployment\Http\Controllers\ProfilesXmlController::class)
    ->name('wpkg.profiles-xml')
    ->withoutMiddleware(['web']);

/*
|--------------------------------------------------------------------------
| Story 3.1 — iPXE Service Core (endpoint natif de premier boot iPXE)
|--------------------------------------------------------------------------
| Remplace le legacy `/ipxe/boot.php` pour le menu de premier appel iPXE.
| Les autres URLs `/ipxe/*` (admin.php, installation-linux.php,
| enregistrement.php, clonezilla.php, Win10/*, diconf/*, png/*) continuent
| à passer par le catchall legacy jusqu'aux stories 3.2-3.7.
|
| **ORDRE STRICT** : ce bloc doit rester AVANT le catchall ci-dessous —
| sinon la route `{path}` capture toutes les requêtes `/ipxe/*` et rend
| ces 2 routes natives inaccessibles. Cf. test
| `IpxeNamespaceTest::ipxe_boot_route_is_declared_before_catchall`.
|
| **Sécurité** : middleware `auth.v1.lan-only` (16.11) — restreint au LAN
| scolaire RFC1918. Pas de JWT (un firmware iPXE n'a pas d'OS qui puisse
| porter un Authorization Bearer — cf. D3/D8).
|
| **Throttle 600/min/IP** : un poste qui retry iPXE peut générer 5-10
| calls en 10s ; 600/min couvre 60 postes simultanés × 10 retries chacun.
*/
Route::match(['GET', 'POST'], '/ipxe/boot', [
    \App\Ipxe\Http\Controllers\IpxeBootController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.boot')
    ->withoutMiddleware(['web']);

Route::get('/ipxe/boot.ipxe', [
    \App\Ipxe\Http\Controllers\IpxeBootController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.boot.alias')
    ->withoutMiddleware(['web']);

/*
|--------------------------------------------------------------------------
| Story 3.2 — Menu Admin + Maintenance + Action iPXE (D2)
|--------------------------------------------------------------------------
| Remplace les endpoints legacy `/ipxe/admin.php`, `/ipxe/maintenance.php`
| et `/ipxe/action.php` par 3 routes natives. Les autres `/ipxe/*` continuent
| à passer par le catchall jusqu'aux stories 3.3-3.7.
|
| **ORDRE STRICT** : ce bloc doit rester AVANT le catchall ci-dessous —
| sinon la route `{path}` capture toutes les requêtes `/ipxe/*` et rend
| ces routes natives inaccessibles. Cf. test
| `IpxeNamespaceTest::ipxe_3_2_routes_are_declared_before_catchall`.
|
| **Sécurité** : middleware `auth.v1.lan-only` (16.11) — restreint au LAN
| scolaire RFC1918. Pas de JWT (parité 3.1 D3/D8).
|
| **Whitelist action** : `IpxeAdminAction` enum (3 cases stricts en 3.2 —
| rescuecd, winpe, factory_reset). Toute autre valeur retourne 404 + log
| warning `ipxe.action.unknown_action`.
*/
Route::match(['GET', 'POST'], '/ipxe/admin', [
    \App\Ipxe\Http\Controllers\IpxeAdminController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.admin')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/maintenance', [
    \App\Ipxe\Http\Controllers\IpxeMaintenanceController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.maintenance')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/action/{action}', [
    \App\Ipxe\Http\Controllers\IpxeActionController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->where('action', '[a-z_]+')
    ->name('ipxe.action')
    ->withoutMiddleware(['web']);

/*
|--------------------------------------------------------------------------
| Story 3.3 — Enrollment Machine — Parcs, Salles, Nommage (D2)
|--------------------------------------------------------------------------
| Remplace les endpoints legacy `/ipxe/enregistrement.php`,
| `/ipxe/enregistrement_byod.php`, `/ipxe/salles.php`, `/ipxe/parcs.php`
| et `/ipxe/enleveparc.php` par 5 routes natives sous `/ipxe/enrollment/*`.
| Les routes legacy `.php` continuent d'être servies par le catchall jusqu'à
| la story 3.7 cleanup.
|
| **ORDRE STRICT** : ce bloc doit rester AVANT le catchall ci-dessous —
| sinon la route `{path}` capture toutes les requêtes `/ipxe/*` et rend
| ces 5 routes natives inaccessibles. Cf. test
| `IpxeNamespaceTest::ipxe_3_3_enrollment_routes_are_declared_before_catchall`.
|
| **Sécurité** : middleware `auth.v1.lan-only` (16.11) — restreint au LAN
| scolaire RFC1918. Pas de JWT (parité 3.1/3.2 D3 — un firmware iPXE n'a pas
| d'OS qui puisse porter un Authorization Bearer).
|
| **Throttle 600/min/IP** : iso 3.1/3.2.
*/
Route::match(['GET', 'POST'], '/ipxe/enrollment/name', [
    \App\Ipxe\Http\Controllers\IpxeEnrollmentNameController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.enrollment.name')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/enrollment/byod', [
    \App\Ipxe\Http\Controllers\IpxeEnrollmentByodController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.enrollment.byod')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/enrollment/room', [
    \App\Ipxe\Http\Controllers\IpxeEnrollmentRoomController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.enrollment.room')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/enrollment/parc-add', [
    \App\Ipxe\Http\Controllers\IpxeEnrollmentParcAddController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.enrollment.parc-add')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/enrollment/parc-remove', [
    \App\Ipxe\Http\Controllers\IpxeEnrollmentParcRemoveController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.enrollment.parc-remove')
    ->withoutMiddleware(['web']);

/*
|--------------------------------------------------------------------------
| Story 3.4 — Installation Linux (Debian/Ubuntu/NIRD) (D2)
|--------------------------------------------------------------------------
| Remplace les endpoints legacy `/ipxe/installation-linux.php`,
| `/ipxe/linux/preseed.php`, `/ipxe/linux/action.php`,
| `/ipxe/linux/autorun.php` par 4 routes natives.
|
| **ORDRE STRICT** : ce bloc doit rester AVANT le catchall ci-dessous —
| sinon la route `{path}` capture toutes les requêtes `/ipxe/*` et rend
| ces routes natives inaccessibles. Cf. test
| `IpxeNamespaceTest::ipxe_3_4_routes_are_declared_before_catchall`.
|
| **Sécurité** : middleware `auth.v1.lan-only` (16.11) — restreint au LAN
| scolaire RFC1918. Parité 3.1-3.3 D3/D8 — pas de JWT.
|
| **Throttle** : 600/min/IP iso 3.1-3.3 (suffisant pour ~50 postes qui
| re-fetch leur preseed en parallèle à la rentrée).
*/
Route::match(['GET', 'POST'], '/ipxe/installation-linux', [
    \App\Ipxe\Http\Controllers\IpxeInstallationLinuxController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.installation-linux')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/linux/preseed', [
    \App\Ipxe\Http\Controllers\IpxeLinuxPreseedController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.linux.preseed')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/linux/action', [
    \App\Ipxe\Http\Controllers\IpxeLinuxActionController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.linux.action')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/linux/autorun', [
    \App\Ipxe\Http\Controllers\IpxeLinuxAutorunController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.linux.autorun')
    ->withoutMiddleware(['web']);

/*
|--------------------------------------------------------------------------
| Story 3.5 — Installation Windows (Win10/Win11) (D2)
|--------------------------------------------------------------------------
| Remplace les endpoints legacy `/ipxe/installation-windows.php`,
| `/ipxe/Win10/install.bat.php`, `/ipxe/Win10/unattend.xml.php`,
| `/ipxe/Win10/diskpart.php`, `/ipxe/Win10/sysprep.xml.php` et
| `/ipxe/Win10/action.php` (partiel — winpe/oobe seulement, autres
| étapes déférées 3.7) par 6 routes natives.
|
| **ORDRE STRICT** : ce bloc doit rester AVANT le catchall ci-dessous —
| sinon la route `{path}` capture toutes les requêtes `/ipxe/*` et rend
| ces routes natives inaccessibles. Cf. test
| `IpxeNamespaceTest::ipxe_3_5_routes_are_declared_before_catchall`.
|
| **Sécurité** : middleware `auth.v1.lan-only` (16.11) — restreint au LAN
| scolaire RFC1918. Parité 3.1-3.4 D3/D8 — pas de JWT.
|
| **Throttle** : 600/min/IP iso 3.1-3.4 (suffisant pour ~50 postes
| simultanés à la rentrée scolaire).
*/
Route::match(['GET', 'POST'], '/ipxe/installation-windows', [
    \App\Ipxe\Http\Controllers\IpxeInstallationWindowsController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.installation-windows')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/windows/install.bat', [
    \App\Ipxe\Http\Controllers\IpxeWindowsInstallBatController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.windows.install-bat')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/windows/unattend.xml', [
    \App\Ipxe\Http\Controllers\IpxeWindowsUnattendController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.windows.unattend')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/windows/diskpart.txt', [
    \App\Ipxe\Http\Controllers\IpxeWindowsDiskpartController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.windows.diskpart')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/windows/sysprep.xml', [
    \App\Ipxe\Http\Controllers\IpxeWindowsSysprepController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.windows.sysprep')
    ->withoutMiddleware(['web']);

Route::match(['GET', 'POST'], '/ipxe/windows/action', [
    \App\Ipxe\Http\Controllers\IpxeWindowsActionController::class,
    'handle',
])
    ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
    ->name('ipxe.windows.action')
    ->withoutMiddleware(['web']);

/*
|--------------------------------------------------------------------------
| Legacy PHP Fallback Route (DOIT ÊTRE EN DERNIER)
|--------------------------------------------------------------------------
| Cette route catch-all délègue au LegacyCatchallController :
| - Blocage configurable des routes dont l'équivalent SER existe
| - Logging dans legacy_catchall_logs (DB) + channel legacylog (fichier)
| - Résolution legacy : PHP, dossier index, assets statiques
|
*/
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], '{path}', [App\Http\Controllers\LegacyCatchallController::class, 'handle'])
    ->where('path', '.*');




/*
|--------------------------------------------------------------------------
| ⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠ ATTENTION !!!
|--------------------------------------------------------------------------
| Ne pas ajouter de fonctions ici !!!!
| La route catch-all legacy doit rester la dernière route définie
| dans ce fichier 
| ⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠⚠
*/

// ^^^^^^ Non ! Pas là !! ^^^^^^^