<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\v1\EcowattController;
use App\Http\Controllers\Api\v1\ControlHub\ShortcutController;
use App\Http\Controllers\Api\v1\ControlHub\InstanceStatusController;
use App\Http\Controllers\Api\v1\ControlHub\GreetmeController;
use App\Http\Controllers\Api\v1\ControlHub\TaskController;
use App\Http\Controllers\Api\v1\ControlHub\WorkstationGroupController;
use App\Http\Controllers\Api\v1\ControlHub\SnapshotController;
use App\Http\Controllers\Api\v1\ControlHub\ApplicationController;
use App\Http\Controllers\Api\v1\ControlHub\AppProfileController;
use App\Http\Controllers\Api\v1\ControlHub\SyncManifestController;
use App\Http\Controllers\Api\v1\ShortcutExportController;
use App\Http\Controllers\Api\WpkgReportController;

// Story 16.10 — Auth v1 poste ↔ serveur local (HTTPS + JWT RS256)
use App\Auth\V1\Http\Controllers\EnrollController as AuthV1EnrollController;
use App\Auth\V1\Http\Controllers\RefreshController as AuthV1RefreshController;
use App\Auth\V1\Http\Controllers\PingController as AuthV1PingController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| SE4FS API Routes - PRIORITÉ 1 : Découverte
|--------------------------------------------------------------------------
| APIs pour l'intégration d'applications tierces selon Discovery.md
| TODO: vu qu'il n'y a plus de discovery, vérifier si on peut supprimer ce service
*/
// Route legacy
Route::get('/ecowatt/status', [EcowattController::class, 'status']);
Route::prefix('v1')->middleware('controlhub.auth')->group(function () {
    // Routes ControlHub pour l'état et les métriques de l'instance (authentifiées par clé API)
    // Health check basique
    Route::get('/health', [InstanceStatusController::class, 'check'])->name('health');
    // Statistiques complètes de l'instance
    Route::get('/stats', [InstanceStatusController::class, 'getStats'])->name('stats');
    // Données statiques de l'instance (UAI, nom, coordonnées, version)
    Route::get('/static', [InstanceStatusController::class, 'getStaticData'])->name('static');
    // Health check détaillé avec services
    Route::get('/health/detailed', [InstanceStatusController::class, 'getHealthCheck'])->name('health.detailed');
    // Métriques système (CPU, RAM, utilisateurs)
    Route::get('/metrics', [InstanceStatusController::class, 'getMetricsData'])->name('metrics');
    // Données historiques
    Route::get('/historical/{period}', [InstanceStatusController::class, 'getHistoricalData'])->name('historical');

    Route::post('/greetme', [GreetmeController::class, 'greetme'])->name('greetme');

    // Routes pour la gestion des tâches ControlHub
    Route::prefix('tasks')->name('task.')->group(function () {
        Route::post('/cancel', [TaskController::class, 'cancel'])->name('cancel');
    });

    // Routes pour les tâches ControlHub (authentifiées par ControlHub)
    Route::prefix('shortcuts')->name('shortcut.')->group(function () {
        Route::post('/sync', [ShortcutController::class, 'syncShortcut'])->name('sync');
        Route::post('/delete', [ShortcutController::class, 'deleteShortcut'])->name('delete');
    });

    Route::prefix('workstation-groups')->name('workstation-group.')->group(function () {
        Route::post('/sync', [WorkstationGroupController::class, 'syncWorkstationGroup'])->name('sync');
        Route::post('/sync-tree', [WorkstationGroupController::class, 'syncWorkstationGroupTree'])->name('sync-tree');
        Route::post('/delete', [WorkstationGroupController::class, 'deleteWorkstationGroup'])->name('delete');
        Route::get('/{controlhubId}', [SnapshotController::class, 'showWorkstationGroup'])->name('show');
    });

    // Routes pour les applications ControlHub
    Route::prefix('applications')->name('application.')->group(function () {
        Route::post('/sync', [ApplicationController::class, 'syncApplication'])->name('sync');
    });

    // Routes pour les profils applicatifs ControlHub
    Route::prefix('app-profiles')->name('app-profile.')->group(function () {
        Route::post('/sync', [AppProfileController::class, 'syncAppProfile'])->name('sync');
    });

    // Snapshot et GET par entité
    Route::get('/snapshot', [SnapshotController::class, 'snapshot'])->name('snapshot');
    Route::post('/snapshot', [SnapshotController::class, 'snapshotAsync'])->name('snapshot.async');
    Route::get('/shortcuts/{controlhubId}', [SnapshotController::class, 'showShortcut'])->name('shortcut.show');
    Route::get('/applications/{controlhubId}', [SnapshotController::class, 'showApplication'])->name('application.show');
    Route::get('/app-profiles/{controlhubId}', [SnapshotController::class, 'showAppProfile'])->name('app-profile.show');

    // Sync Manifest (convergence complète)
    Route::post('/sync-manifest', [SyncManifestController::class, 'syncManifest'])->name('sync-manifest');
});

/*
|--------------------------------------------------------------------------
| API Routes Privées (Legacy)
|--------------------------------------------------------------------------
| Ces routes nécessitent une authentification SambaEdu
*/
Route::prefix('v1')->middleware('sambaedu.auth')->group(function () {
    // Health check détaillé (nécessite authentification)
    Route::get('/health/detailed', [InstanceStatusController::class, 'getHealthCheck']);
});

/*
|--------------------------------------------------------------------------
| Routes de compatibilité
|--------------------------------------------------------------------------
| Pour maintenir la compatibilité avec les anciennes URLs
*/
Route::get('/health-check', [InstanceStatusController::class, 'check']);

/*
|--------------------------------------------------------------------------
| Routes publiques d'export des raccourcis (GPO)
|--------------------------------------------------------------------------
| Appelées par les scripts GPO Windows/Linux au logon/startup.
| Pas d'authentification : les postes ne sont pas encore connectés.
| Remplace le legacy gpo/shortcuts_out.php.
*/
/*
|--------------------------------------------------------------------------
| Routes WPKG — Ingestion des rapports d'installation
|--------------------------------------------------------------------------
| Appelée par le worker local `wpkg:process-reports` (Story 9.4) qui lit
| les rapports déposés sur le partage SMB et POST vers cet endpoint.
| Auth machine = jointure AD + ACL Samba côté partage. Côté HTTP, le
| middleware `local.request` (IP allowlist) protège l'endpoint.
*/
Route::prefix('wpkg')->middleware('local.request')->group(function () {
    Route::post('/reports/{hostname}', [WpkgReportController::class, 'store'])->name('wpkg.reports.store');
});

/*
|--------------------------------------------------------------------------
| Story 16.10 — Auth v1 poste ↔ serveur local (HTTPS + JWT RS256)
|--------------------------------------------------------------------------
| Endpoints `/api/v1/agent/*` — discrimination par claim `tier=workstation`.
| Cohabite avec `/api/v1/snapshot` (controlHub) sans collision (sous-namespace
| dédié `/agent`).
|
| - `POST /enroll`  : protégé `auth.v1.bootstrap` (X-Bootstrap-Token md5) + throttle 10/min
| - `POST /refresh` : protégé `auth.v1.refresh` (refresh_token DB) + throttle 30/min + replay detection
| - `GET /ping`     : protégé `auth.v1.workstation` (JWT RS256 valid + tier=workstation)
*/
Route::prefix('v1/agent')->name('agent.v1.')
    // Headers de sécurité (Cache-Control no-store + HSTS + nosniff) sur toutes les
    // réponses agent — les responses enroll/refresh portent des tokens clear, on
    // doit empêcher tout caching intermédiaire. Cf. review 16.10 finding #A.
    ->middleware('auth.v1.secure-headers')
    ->group(function () {
        Route::post('/enroll', [AuthV1EnrollController::class, 'store'])
            ->middleware(['auth.v1.bootstrap', 'throttle:10,1'])
            ->name('enroll');

        Route::post('/refresh', [AuthV1RefreshController::class, 'store'])
            ->middleware(['auth.v1.refresh', 'throttle:30,1'])
            ->name('refresh');

        Route::middleware('auth.v1.workstation')->group(function () {
            Route::get('/ping', [AuthV1PingController::class, 'show'])->name('ping');
            // Futurs endpoints (16.12 logs, futures stories scripts) ajoutent leurs routes ici.
        });
    });

Route::prefix('v1/shortcuts/export')->name('shortcuts.export.')->group(function () {
    // Script complet (.cmd/.sh) pour un poste
    Route::match(['get', 'post'], '/script', [ShortcutExportController::class, 'script'])->name('script');
    // Fichier .lnk ou .desktop individuel
    Route::match(['get', 'post'], '/file', [ShortcutExportController::class, 'file'])->name('file');
    // Icône d'un raccourci (.ico/.png)
    Route::match(['get', 'post'], '/icon', [ShortcutExportController::class, 'icon'])->name('icon');
});


