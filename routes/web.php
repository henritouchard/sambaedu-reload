<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ParcController;
use App\Http\Controllers\AppPolicyController;
use App\Http\Controllers\WallpaperController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChangePasswordController;

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

    // ========================================
    // GPO — Epic 16, Story 16.2
    // Listing et détail lecture seule des GPOs Active Directory.
    // Permission server.admin (Décision D4). Route /app/gpo (Décision D1).
    //
    // La regex stricte du GUID (format Microsoft, accolades optionnelles)
    // dans where('guid', ...) bloque toute valeur non conforme au niveau du
    // routeur (404 retourné avant tout dispatch Livewire). C'est cette regex
    // qui constitue la défense principale contre les injections — la
    // validation défensive dans mount() n'est qu'un filet de sécurité.
    // ========================================
    // Story 16.3c — Page admin native Wine (UI Livewire SFC).
    // Permission server.admin (cohérence 16.2). Déclarée AVANT
    // `/gpo/{guid}` pour éviter qu'elle ne tente d'être matchée comme GUID
    // (la regex stricte de `{guid}` ne matche pas `wine`, mais on évite
    // la dépendance à l'ordre via une déclaration explicite prioritaire).
    Route::livewire('/gpo/wine', 'pages::app.gpo.wine.index')
        ->middleware('can:server.admin')
        ->name('gpo.wine');

    Route::livewire('/gpo/{guid}', 'pages::app.gpo.[guid].index')
        ->where('guid', '\{?[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}?')
        ->middleware('can:server.admin')
        ->name('gpo.show');

    Route::livewire('/gpo', 'pages::app.gpo.index')
        ->middleware('can:server.admin')
        ->name('gpo.index');

});

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

    // Migration - Dashboard d'assistance
    Route::livewire('/migrate', 'pages::admin.migrate.index')->name('migrate');

    // Navigation legacy (menus SE4FS)
    Route::livewire('/homelegacy', 'pages::homelegacy.index')->name('homelegacy');

    // Synchronisation depuis l'AD — Story 7.2 AC8 : can:server.admin (action critique).
    Route::livewire('/sync-from-ad', 'pages::sync-from-ad.index')
        ->middleware('can:server.admin')
        ->name('sync-from-ad');

    // Réglages système — Story 5.1c AC5/AC12 : can:server.admin (action critique).
    // Page Livewire SFC à onglets extensible. Onglet unique en 5.1c : "Quotas & FS".
    Route::livewire('/settings', 'pages::admin.settings.index')
        ->middleware('can:server.admin')
        ->name('settings');

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
| Interception legacy gpo/shortcuts_out.php → nouveau système
|--------------------------------------------------------------------------
| Les postes Windows/Linux appellent gpo/shortcuts_out.php via les GPO.
| Le fichier legacy a été renommé en .legacy, cette route intercepte
| les appels et les redirige vers ShortcutExportController.
*/
Route::match(['GET', 'POST'], 'gpo/shortcuts_out.php', [App\Http\Controllers\Api\v1\ShortcutExportController::class, 'legacyDispatch'])
    ->name('shortcuts.legacy');

/*
|--------------------------------------------------------------------------
| Interception legacy gpo/wallpaper_out.php → nouveau système
|--------------------------------------------------------------------------
| Appelé par logon/startup scripts (Linux + Windows).
| Actions : wallpaper, wallpaper-wait, lockscreen, veyon, icone.
| Auth : $id md5 stocké dans APCu par applications.php.
*/
Route::match(['GET', 'POST'], 'gpo/wallpaper_out.php', [WallpaperController::class, 'legacyOut'])
    ->name('wallpaper.legacy');

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
| Interception legacy gpo/firefox_out.php + gpo/thunderbird_out.php
|--------------------------------------------------------------------------
| Story 4.8 — AC 9. Endpoints iso-contrat appelés par logon/startup
| Linux/Windows avec id=<md5 APCu> + os=linux|windows (Firefox).
| Pas d'auth (postes clients sans cookie). Throttle 300/min/IP
| (300 postes derrière NAT peuvent se loguer simultanément sans 429).
| Doivent être déclarés AVANT le catchall legacy.
*/
Route::match(['GET', 'POST'], 'gpo/firefox_out.php', [AppPolicyController::class, 'legacyFirefoxOut'])
    ->middleware('throttle:300,1')
    ->name('app-policy.firefox.legacy');

Route::match(['GET', 'POST'], 'gpo/thunderbird_out.php', [AppPolicyController::class, 'legacyThunderbirdOut'])
    ->middleware('throttle:300,1')
    ->name('app-policy.thunderbird.legacy');

/*
|--------------------------------------------------------------------------
| Interception legacy gpo/network_out.php + gpo/veyon_out.php (Story 16.3b)
| + gpo/associations_out.php (Story 16.3c)
|--------------------------------------------------------------------------
| Endpoints runtime postes clients : script bash réseau (network_out),
| config JSON Veyon (veyon_out) et JSON des associations d'extensions
| (associations_out). Pattern iso 4.7/4.8.
| Throttle 300/min/IP. Pas d'auth web (id md5 APCu = garde effective).
| Doivent être déclarés AVANT le catchall legacy.
*/
Route::match(['GET', 'POST'], 'gpo/network_out.php', [\App\Http\Controllers\Gpo\NetworkOutController::class, 'legacyOut'])
    ->middleware('throttle:300,1')
    ->name('gpo.network-out.legacy');
Route::match(['GET', 'POST'], 'gpo/veyon_out.php', [\App\Http\Controllers\Gpo\VeyonOutController::class, 'legacyOut'])
    ->middleware('throttle:300,1')
    ->name('gpo.veyon-out.legacy');
// Story 16.3c — POST uniquement (legacy `associations_out.php` n'expose pas GET ;
// le body `$_POST['list']` est obligatoire — un GET sans `list` retournerait 400).
Route::match(['POST'], 'gpo/associations_out.php', [\App\Http\Controllers\Gpo\AssociationsOutController::class, 'legacyOut'])
    ->middleware('throttle:300,1')
    ->name('gpo.associations-out.legacy');

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