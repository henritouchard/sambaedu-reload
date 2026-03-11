# Lien Laravel - Legacy

L'idée est de trouver un moyen propre pour lier le routage Laravel et les pages Livewire avec les fonctions offertes par le système legacy.
Ainsi il faudra pouvoir:
- Créer une nouvelle page avec interface d'administration via Livewire 
- Ouvrir des routes API pour accéder à ces mêmes fonctions legacy.

## Solution : LegacyBridge pour WPKG

### Principe

Créer un **Service Bridge** qui encapsule les appels aux fonctions legacy PHP et les expose via une API propre. Ce bridge :
1. Charge les fichiers legacy nécessaires (comme `LegacyConfigBridge` existant)
2. Appelle les fonctions legacy avec les bons paramètres
3. Transforme les résultats en DTOs/Types Laravel
4. Gère les erreurs et le logging

### Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                         Laravel Application                          │
├─────────────────────────────────────────────────────────────────────┤
│  Controllers (API)          │  Livewire Components                  │
│  ────────────────           │  ────────────────────                 │
│  WpkgLegacyController       │  WpkgApplicationsPage                 │
│       ↓                     │       ↓                               │
├─────────────────────────────┴───────────────────────────────────────┤
│                      WpkgLegacyBridge (Service)                      │
│  ─────────────────────────────────────────────────────────────────  │
│  - Charge les fichiers legacy une seule fois                        │
│  - Expose des méthodes typées                                       │
│  - Transforme array legacy → DTOs Laravel                           │
├─────────────────────────────────────────────────────────────────────┤
│                      Fichiers Legacy PHP                             │
│  ─────────────────────────────────────────────────────────────────  │
│  includes/wpkg_libsql.php   │  includes/applications.inc.php        │
│  wpkg/*.php                 │  gpo/applications.php                 │
└─────────────────────────────────────────────────────────────────────┘
```

### Implémentation

#### 1. Service Bridge - `App\Services\Legacy\WpkgLegacyBridge.php`

```php
<?php

namespace App\Services\Legacy;

use App\Config\LegacyConfigBridge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Bridge vers les fonctions legacy WPKG
 * 
 * Encapsule les appels aux fonctions de includes/wpkg_libsql.php
 * et expose des méthodes typées pour Laravel.
 */
class WpkgLegacyBridge
{
    private const LEGACY_PATH = '/var/www/sambaedu';
    
    private static bool $wpkgLoaded = false;
    private LegacyConfigBridge $configBridge;
    
    public function __construct(LegacyConfigBridge $configBridge)
    {
        $this->configBridge = $configBridge;
    }
    
    /**
     * Charge les fichiers legacy WPKG
     */
    private function loadWpkgFiles(): void
    {
        if (self::$wpkgLoaded) {
            return;
        }
        
        // S'assurer que la config legacy est chargée
        $this->configBridge->getConfig();
        
        $files = [
            self::LEGACY_PATH . '/includes/wpkg_libsql.php',
        ];
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                include_once $file;
            } else {
                Log::warning("WpkgLegacyBridge: Fichier non trouvé: {$file}");
            }
        }
        
        self::$wpkgLoaded = true;
    }
    
    /**
     * Liste toutes les applications WPKG
     */
    public function listApplications(): Collection
    {
        $this->loadWpkgFiles();
        
        if (!function_exists('liste_applications')) {
            Log::error('WpkgLegacyBridge: liste_applications() non disponible');
            return collect([]);
        }
        
        $apps = liste_applications();
        return collect($apps ?? []);
    }
    
    /**
     * Récupère les applications assignées à un poste
     */
    public function getApplicationsForPoste(string $nomPoste): Collection
    {
        $this->loadWpkgFiles();
        
        if (!function_exists('info_poste_applications')) {
            return collect([]);
        }
        
        $apps = info_poste_applications($nomPoste);
        return collect($apps ?? []);
    }
    
    /**
     * Liste tous les postes WPKG
     */
    public function listPostes(): Collection
    {
        $this->loadWpkgFiles();
        
        if (!function_exists('info_postes')) {
            return collect([]);
        }
        
        $postes = info_postes();
        return collect($postes ?? []);
    }
    
    /**
     * Liste tous les parcs WPKG
     */
    public function listParcs(): Collection
    {
        $this->loadWpkgFiles();
        
        if (!function_exists('info_parcs')) {
            return collect([]);
        }
        
        $parcs = info_parcs();
        return collect($parcs ?? []);
    }
    
    /**
     * Assigne une application à un parc
     */
    public function assignApplicationToParc(string $appId, string $parcId): bool
    {
        $this->loadWpkgFiles();
        
        if (!function_exists('add_application_profile')) {
            return false;
        }
        
        return add_application_profile($appId, 'parc', $parcId);
    }
    
    /**
     * Assigne une application à un poste
     */
    public function assignApplicationToPoste(string $appId, string $posteId): bool
    {
        $this->loadWpkgFiles();
        
        if (!function_exists('add_application_profile')) {
            return false;
        }
        
        return add_application_profile($appId, 'poste', $posteId);
    }
    
    /**
     * Supprime une assignation d'application
     */
    public function removeApplicationAssignment(string $appId, string $type, string $entityId): bool
    {
        $this->loadWpkgFiles();
        
        if (!function_exists('del_application_profile')) {
            return false;
        }
        
        return del_application_profile($appId, $type, $entityId);
    }
    
    /**
     * Synchronise les postes et parcs depuis l'AD
     */
    public function syncFromAd(): array
    {
        $this->loadWpkgFiles();
        $config = $this->configBridge->getConfig();
        
        // Charger le fichier de sync
        $syncFile = self::LEGACY_PATH . '/wpkg/wpkg_ldap_update.php';
        if (!file_exists($syncFile)) {
            return ['success' => false, 'error' => 'Fichier de sync non trouvé'];
        }
        
        ob_start();
        try {
            include $syncFile;
            $output = ob_get_clean();
            return ['success' => true, 'output' => $output];
        } catch (\Throwable $e) {
            ob_end_clean();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
```

#### 2. Enregistrement du Service - `App\Providers\AppServiceProvider.php`

```php
// Dans la méthode register()
$this->app->singleton(\App\Services\Legacy\WpkgLegacyBridge::class);
```

#### 3. Controller API - `App\Http\Controllers\Api\v1\Wpkg\WpkgLegacyController.php`

```php
<?php

namespace App\Http\Controllers\Api\v1\Wpkg;

use App\Http\Controllers\Controller;
use App\Services\Legacy\WpkgLegacyBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WpkgLegacyController extends Controller
{
    public function __construct(
        private WpkgLegacyBridge $wpkgBridge
    ) {}
    
    /**
     * GET /api/v1/wpkg/applications
     */
    public function listApplications(): JsonResponse
    {
        $apps = $this->wpkgBridge->listApplications();
        
        return response()->json([
            'success' => true,
            'data' => $apps->values(),
            'count' => $apps->count(),
        ]);
    }
    
    /**
     * GET /api/v1/wpkg/postes
     */
    public function listPostes(): JsonResponse
    {
        $postes = $this->wpkgBridge->listPostes();
        
        return response()->json([
            'success' => true,
            'data' => $postes->values(),
            'count' => $postes->count(),
        ]);
    }
    
    /**
     * GET /api/v1/wpkg/parcs
     */
    public function listParcs(): JsonResponse
    {
        $parcs = $this->wpkgBridge->listParcs();
        
        return response()->json([
            'success' => true,
            'data' => $parcs->values(),
            'count' => $parcs->count(),
        ]);
    }
    
    /**
     * GET /api/v1/wpkg/postes/{nom}/applications
     */
    public function getPosteApplications(string $nom): JsonResponse
    {
        $apps = $this->wpkgBridge->getApplicationsForPoste($nom);
        
        return response()->json([
            'success' => true,
            'data' => $apps->values(),
        ]);
    }
    
    /**
     * POST /api/v1/wpkg/applications/{appId}/assign
     */
    public function assignApplication(Request $request, string $appId): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:parc,poste',
            'entity_id' => 'required|string',
        ]);
        
        $success = match($validated['type']) {
            'parc' => $this->wpkgBridge->assignApplicationToParc($appId, $validated['entity_id']),
            'poste' => $this->wpkgBridge->assignApplicationToPoste($appId, $validated['entity_id']),
        };
        
        return response()->json([
            'success' => $success,
            'message' => $success ? 'Application assignée' : 'Erreur lors de l\'assignation',
        ], $success ? 200 : 500);
    }
    
    /**
     * DELETE /api/v1/wpkg/applications/{appId}/assign
     */
    public function removeAssignment(Request $request, string $appId): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:parc,poste',
            'entity_id' => 'required|string',
        ]);
        
        $success = $this->wpkgBridge->removeApplicationAssignment(
            $appId, 
            $validated['type'], 
            $validated['entity_id']
        );
        
        return response()->json([
            'success' => $success,
        ]);
    }
    
    /**
     * POST /api/v1/wpkg/sync
     */
    public function syncFromAd(): JsonResponse
    {
        $result = $this->wpkgBridge->syncFromAd();
        
        return response()->json($result, $result['success'] ? 200 : 500);
    }
}
```

#### 4. Routes API - `routes/api.php`

```php
/*
|--------------------------------------------------------------------------
| WPKG Legacy Bridge API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('v1/wpkg')->middleware('sambaedu.auth')->group(function () {
    // Liste des applications
    Route::get('/applications', [WpkgLegacyController::class, 'listApplications']);
    
    // Liste des postes
    Route::get('/postes', [WpkgLegacyController::class, 'listPostes']);
    
    // Liste des parcs
    Route::get('/parcs', [WpkgLegacyController::class, 'listParcs']);
    
    // Applications d'un poste
    Route::get('/postes/{nom}/applications', [WpkgLegacyController::class, 'getPosteApplications']);
    
    // Assignation d'application (nécessite droits admin)
    Route::middleware('sambaedu.admin')->group(function () {
        Route::post('/applications/{appId}/assign', [WpkgLegacyController::class, 'assignApplication']);
        Route::delete('/applications/{appId}/assign', [WpkgLegacyController::class, 'removeAssignment']);
        Route::post('/sync', [WpkgLegacyController::class, 'syncFromAd']);
    });
});
```

### Utilisation depuis Livewire

```php
// Dans un composant Livewire
use App\Services\Legacy\WpkgLegacyBridge;

class WpkgApplicationsPage extends Component
{
    public Collection $applications;
    public Collection $parcs;
    
    public function mount(WpkgLegacyBridge $wpkgBridge)
    {
        $this->applications = $wpkgBridge->listApplications();
        $this->parcs = $wpkgBridge->listParcs();
    }
    
    public function assignToParc(string $appId, string $parcId)
    {
        $bridge = app(WpkgLegacyBridge::class);
        
        if ($bridge->assignApplicationToParc($appId, $parcId)) {
            $this->dispatch('notify', message: 'Application assignée');
        } else {
            $this->dispatch('notify', message: 'Erreur', type: 'error');
        }
    }
}
```

### Avantages de cette approche

1. **Isolation** : Le code legacy est encapsulé dans un service dédié
2. **Testabilité** : Le bridge peut être mocké dans les tests
3. **Typage** : Les méthodes retournent des `Collection` Laravel typées
4. **Transition progressive** : On peut remplacer les méthodes du bridge une par une par du code Laravel natif
5. **Réutilisabilité** : Le même bridge sert pour l'API et Livewire
6. **Logging** : Centralisation des logs d'erreurs

### Migration progressive

```
Phase 0 (actuelle) : WpkgLegacyBridge appelle les fonctions legacy
     ↓
Phase 1 : Créer WpkgApplicationRepository (Eloquent) 
     ↓
Phase 2 : WpkgLegacyBridge délègue à WpkgApplicationRepository
     ↓
Phase 3 : Supprimer WpkgLegacyBridge, utiliser directement les Repositories
```

### Fichiers à créer

| Fichier | Description |
|---------|-------------|
| `app/Services/Legacy/WpkgLegacyBridge.php` | Service bridge vers les fonctions legacy |
| `app/Http/Controllers/Api/v1/Wpkg/WpkgLegacyController.php` | Controller API |
| Modifier `routes/api.php` | Ajouter les routes WPKG |
| Modifier `AppServiceProvider.php` | Enregistrer le singleton |

