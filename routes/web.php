<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ParcController;
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

// Route racine - Page d'accueil Laravel
// Route de test simple - sans middleware, sans LDAP
Route::get('/test-simple', function () {
    return response()->json(['status' => 'ok', 'time' => now()->toDateTimeString()]);
});

// Route de test avec middleware sambaedu.auth
Route::get('/test-auth', function () {
    return response()->json(['status' => 'ok', 'authenticated' => true, 'time' => now()->toDateTimeString()]);
})->middleware('sambaedu.auth');

Route::prefix("authentication")->name("auth.")->group(function () {
    // Connexion
    Route::get("login", [AuthController::class, 'showLogin'])->name('login');
    Route::post("login", [AuthController::class, 'authenticate'])->name('authenticate');
    Route::get("signout", [AuthController::class, 'logout'])->name('logout');

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
    Route::livewire('/dashboard', 'pages::dashboard.index')->name('dashboard');
    Route::livewire('/workers', 'pages::workers.index')->name('workers.index');
    Route::livewire('/workers/{pid}', 'pages::workers.[pid].index')->whereNumber('pid')->name('workers.show');

    Route::livewire('/users', 'pages::users.index')->name('users');

    // Création d'utilisateur (Livewire, nécessite droits admin)
    Route::livewire('/users/new', 'pages::users.new.index')->middleware(['sambaedu.auth', 'sambaedu.admin'])->name('users.new');

    // // Actions groupées sur les utilisateurs (nécessite droits admin)
    // Route::middleware(['sambaedu.auth', 'sambaedu.admin'])->group(function () {
    //     Route::post('/users/bulk-enable', [UsersController::class, 'bulkEnable'])->name('users.bulk-enable');
    //     Route::post('/users/bulk-disable', [UsersController::class, 'bulkDisable'])->name('users.bulk-disable');
    //     Route::post('/users/bulk-reset-password', [UsersController::class, 'bulkResetPassword'])->name('users.bulk-reset-password');
    //     Route::post('/users/bulk-assign-groups', [UsersController::class, 'bulkAssignGroupsStore'])->name('users.bulk-assign-groups.store');
    // });

    // Gestion des droits (nécessite droits admin)
    Route::livewire('/rights-management', 'pages::rights-management.index')->name('rights-management');

    // Groupes d'utilisateurs
    Route::livewire('/users/groups/new', 'pages::users.groups.new.index')->name('users.groups.new');
    Route::livewire('/users/groups/{id}', 'pages::users.groups.[id].index')->whereNumber('id')->name('users.groups.edit');
    
    // Gestion des quotas (nécessite droits admin)
    Route::post('/users/groups/{groupCn}/quota', [\App\Http\Controllers\QuotaController::class, 'updateGroupQuota'])
        ->middleware('sambaedu.admin')
        ->name('users.groups.quota.update');
    Route::post('/users/{login}/quota', [\App\Http\Controllers\QuotaController::class, 'updateUserQuota'])
        ->middleware('sambaedu.admin')
        ->name('users.quota.update');

    // Utilisateur individuel
    Route::livewire('/users/{login}', 'pages::users.[login].index')->name('user.show');

    // Routes Livewire pour les raccourcis
    Route::livewire('/shortcuts', 'pages::shortcuts.index')->name('shortcuts');
    Route::livewire('/shortcuts/new', 'pages::shortcuts.new.index')->name('shortcuts.new');
    Route::livewire('/shortcuts/{id}', 'pages::shortcuts.[id].index')->name('shortcuts.show');

    // ========================================
    // Paramètres du Parc - Profils applicatifs et catalogue
    // ========================================
    Route::prefix('parc-settings')->name('parc-settings.')->group(function () {
        // Page principale avec onglets profils/applications
        Route::livewire('/', 'pages::parc-settings.index')->name('index');

        // Profils applicatifs
        Route::livewire('/profiles/{id}', 'pages::parc-settings.profiles.index')->name('profiles.show');

        // Applications
        Route::livewire('/applications/{id}', 'pages::parc-settings.applications.index')->name('applications.show');
    });

    // // Gestion des parcs (Livewire)
    // Route::livewire('/parcs', 'pages::parcs.index')->name('parcs');
    // Route::livewire('/parcs/new', 'pages::parcs.new.index')->middleware(['sambaedu.auth', 'sambaedu.admin'])->name('parcs.new');
    // Route::livewire('/parcs/{parc}', 'pages::parcs.[parc].index')->name('parc.show');



    // ========================================
    // Synchronisation depuis l'AD (Assistant de mise en place)
    // ========================================
    Route::livewire('/sync-from-ad', 'pages::sync-from-ad.index')->name('sync-from-ad');

    // ========================================
    // Gestion du Parc (Section 1 - MySQL source)
    // ========================================
    Route::prefix('parc')->name('parc.')->group(function () {
        // Page principale avec onglets machines/groupes
        Route::livewire('/', 'pages::parc.index')->name('index');

        // Groupes de machines
        Route::livewire('/groups/new', 'pages::parc.groups.new.index')->name('groups.new');
        Route::livewire('/groups/{id}', 'pages::parc.groups.[id].index')->name('groups.show');
        Route::livewire('/groups/{id}/edit', 'pages::parc.groups.[id].edit.index')->name('groups.edit');

        // Machines
        Route::livewire('/machines/{id}', 'pages::parc.machines.[id].index')->name('machines.show');

        // ParcTodo: à remettre en place
        // Route::get('/parcs/{parc}/wallpaper/{type}', [WallpaperController::class, 'getImage'])
        //     ->where('type', 'wallpaper|lockscreen')
        //     ->name('parc.wallpaper.image');
        // Route::get('/parc/{parc}/wallpaper/{type}/thumbnail', [WallpaperController::class, 'getThumbnail'])
        //     ->where('type', 'wallpaper|lockscreen')
        //     ->name('parc.wallpaper.thumbnail');
    });
});

/*
|--------------------------------------------------------------------------
| Admin Routes - ControlHub Handshake Management
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware('sambaedu.admin')->name('admin.')->group(function () {
    // Control Hub - Livewire fullpage component
    Route::livewire('/control-hub', 'pages::control-hub.index')->name('controlHub.control-hub');

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
| Page de test Modal vs Sidebar (z-index debugging)
|--------------------------------------------------------------------------
*/
Route::get('/test-modal', function () {
    return view('pages.test-modal.index');
})->name('test-modal');

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
| Legacy PHP Fallback Route (DOIT ÊTRE EN DERNIER)
|--------------------------------------------------------------------------
| Cette route catch-all gère automatiquement le fallback vers le code PHP legacy
| quand aucune route Laravel n'est trouvée.
|
*/
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], '{path}', function (Illuminate\Http\Request $request, $path = '') {
    $path = $request->path();

    // Empêcher l'accès aux dossiers sensibles
    $forbidden = ['laravel', 'vendor', 'node_modules', '.git', '.env'];
    foreach ($forbidden as $dir) {
        if (strpos($path, $dir) === 0) {
            abort(403, 'Accès interdit');
        }
    }

    // Chemin vers le fichier/dossier legacy  
    $legacyPath = base_path('../' . $path);

    // Si c'est un fichier PHP legacy qui existe
    if (file_exists($legacyPath) && pathinfo($legacyPath, PATHINFO_EXTENSION) === 'php') {
        // Changer le répertoire de travail pour le contexte legacy
        $originalDir = getcwd();
        chdir(dirname($legacyPath));

        // Capturer la sortie du fichier legacy
        ob_start();
        try {
            // Inclure le fichier legacy avec son contexte
            include $legacyPath;
            $content = ob_get_contents();
        } catch (Exception $e) {
            ob_end_clean();
            chdir($originalDir);
            abort(500, 'Erreur dans le code legacy: ' . $e->getMessage());
        }
        ob_end_clean();
        chdir($originalDir);

        return response($content);
    }

    // Si c'est un dossier legacy qui existe avec un index.php ou index.html
    if (is_dir($legacyPath)) {
        $indexFile = $legacyPath . '/index.php';
        $indexHtml = $legacyPath . '/index.html';

        if (file_exists($indexFile)) {
            $originalDir = getcwd();
            chdir($legacyPath);

            // Démarrer la capture de sortie avec gestion des chemins relatifs
            ob_start();
            try {
                // Simuler les variables d'environnement pour le code legacy
                $_SERVER['REQUEST_URI'] = '/' . $path . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '');
                $_SERVER['SCRIPT_NAME'] = '/' . $path . '/index.php';

                include $indexFile;
                $content = ob_get_contents();

                // Corriger les chemins relatifs des assets dans le HTML généré
                $content = preg_replace_callback(
                    '/(href="|src=")([^"]+\.(?:css|js|png|jpg|jpeg|gif|svg|ico))(")/i',
                    function ($matches) use ($path) {
                        $assetPath = $matches[2];
                        // Si c'est un chemin relatif (ne commence pas par http, https, ou /)
                        if (!preg_match('/^(https?:\/\/|\/)/i', $assetPath)) {
                            // Convertir en chemin absolu
                            $assetPath = '/' . $path . '/' . $assetPath;
                        }
                        return $matches[1] . $assetPath . $matches[3];
                    },
                    $content
                );
            } catch (Exception $e) {
                ob_end_clean();
                chdir($originalDir);
                abort(500, 'Erreur dans le code legacy: ' . $e->getMessage());
            }
            ob_end_clean();
            chdir($originalDir);

            return response($content);
        } elseif (file_exists($indexHtml)) {
            // Servir le fichier HTML statique
            return response()->file($indexHtml, [
                'Content-Type' => 'text/html',
                'Cache-Control' => 'public, max-age=3600'
            ]);
        }
    }

    // Si c'est un fichier statique (CSS, JS, images, etc.)
    if (file_exists($legacyPath) && !is_dir($legacyPath)) {
        // Déterminer le type MIME
        $extension = pathinfo($legacyPath, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject'
        ];

        $mimeType = $mimeTypes[$extension] ?? (function_exists('mime_content_type') ? mime_content_type($legacyPath) : 'application/octet-stream');

        return response()->file($legacyPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=3600' // Cache 1h pour les assets
        ]);
    }

    // Aucun fichier legacy trouvé, erreur 404 normale
    abort(404, 'Page non trouvée');
})->where('path', '.*');




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