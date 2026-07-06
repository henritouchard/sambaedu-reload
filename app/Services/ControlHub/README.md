# Architecture ControlHub

Ce module gère la communication avec l'API ControlHub en suivant une architecture propre avec séparation des responsabilités.

## 📁 Structure

```
app/Services/ControlHub/
├── ControlHubService.php          # Service principal - Logique métier
├── ControlHubApiClient.php        # Client HTTP - Appels API uniquement
└── Data/                          # DTOs (Data Transfer Objects)
    ├── HandshakeRequest.php
    ├── HandshakeResponse.php
    ├── HeartbeatResponse.php
    └── ApiResponse.php

app/Repositories/
└── ControlHubConnectionRepository.php  # Gestion des requêtes BDD

app/Providers/
└── ControlHubServiceProvider.php      # Injection de dépendances
```

## 🎯 Responsabilités

### 1. **ControlHubApiClient** - Communication HTTP
- Effectue les appels HTTP vers l'API ControlHub
- Gère les erreurs réseau
- Retourne des objets `ApiResponse`
- **NE FAIT PAS** : logique métier, accès BDD, cache

**Exemple :**
```php
$client = new ControlHubApiClient('http://hub.example.com');
$response = $client->sendHandshake($handshakeRequest);
```

### 2. **ControlHubService** - Logique métier
- Orchestre les appels entre l'API client et le repository
- Gère la logique métier (handshake, heartbeat, renouvellement token)
- Transforme les données (array → DTO)
- Gère le cache et les états
- **NE FAIT PAS** : appels HTTP directs, requêtes SQL directes

**Exemple :**
```php
$service = app(ControlHubService::class);
$response = $service->performHandshake($masterKey, $baseUrl);
if ($response->success) {
    // ...
}
```

### 3. **ControlHubConnectionRepository** - Accès aux données
- Effectue les requêtes à la base de données
- Méthodes CRUD pour `ControlHubConnection`
- Abstraction de la couche de persistance
- **NE FAIT PAS** : logique métier, appels HTTP

**Exemple :**
```php
$repository = app(ControlHubConnectionRepository::class);
$connection = $repository->getCurrentConnection();
$repository->updateStatus('online');
```

### 4. **DTOs (Data Transfer Objects)** - Typage des données
- Objets `readonly` immuables
- Validation et transformation des données
- Type safety pour les API
- Méthodes factory (`fromArray`, `create`)

**Exemple :**
```php
$response = HandshakeResponse::fromArray($apiData);
echo $response->apiToken;  // Propriété typée
```

## 🔧 Utilisation

### Dans un contrôleur

```php
use App\Services\ControlHub\ControlHubService;

class ControlHubController extends Controller
{
    public function __construct(
        private ControlHubService $controlHubService
    ) {}
    
    public function executeHandshake(Request $request)
    {
        $response = $this->controlHubService->performHandshake(
            $request->master_api_key,
            $request->controlHub_url
        );
        
        if ($response->success) {
            // Accès typé aux propriétés
            Log::info('Token reçu', [
                'token' => substr($response->apiToken, 0, 20),
                'interval' => $response->heartbeatInterval
            ]);
        }
    }
}
```

### Dans une commande Artisan

```php
use App\Services\ControlHub\ControlHubService;

class HeartbeatCommand extends Command
{
    public function handle()
    {
        $service = app(ControlHubService::class);
        
        try {
            $service->performHeartbeat();
            $this->info('Heartbeat réussi');
        } catch (\Exception $e) {
            $this->error('Heartbeat échoué: ' . $e->getMessage());
        }
    }
}
```

## 📝 Bonnes pratiques

### ✅ À FAIRE

- Utiliser le service via injection de dépendances
- Typer les retours avec les DTOs
- Logger dans chaque couche (API, Service, Repository)
- Gérer les exceptions dans le service
- Valider les données avant de créer des DTOs

### ❌ À ÉVITER

- Accéder directement au modèle `ControlHubConnection` (utiliser le Repository)
- Faire des appels HTTP depuis le contrôleur (utiliser le Service)
- Mélanger logique métier et appels HTTP (séparer)
- Utiliser des tableaux au lieu de DTOs pour les réponses API

## 🧪 Tests

Pour tester chaque couche séparément :

```php
// Tester l'API Client (avec mock HTTP)
$client = new ControlHubApiClient('http://test.com');
$response = $client->sendHandshake($request);

// Tester le Service (avec mock du client et repository)
$service = new ControlHubService($mockClient, $mockRepository);
$response = $service->performHandshake('key', 'url');

// Tester le Repository (avec base de test)
$repository = new ControlHubConnectionRepository();
$connection = $repository->getCurrentConnection();
```

## 🔄 Migration depuis l'ancien code

L'ancien `ControlHubClientService` a été remplacé par cette nouvelle architecture.

**Avant :**
```php
$service = app(ControlHubClientService::class);
$result = $service->performHandshakeWithMasterKey($key, $url);
if ($result['success']) { ... }
```

**Après :**
```php
$service = app(ControlHubService::class);
$result = $service->performHandshake($key, $url);
if ($result->success) { ... }  // Objet DTO au lieu d'array
```

## 📚 Ressources

- [Service Layer Pattern](https://martinfowler.com/eaaCatalog/serviceLayer.html)
- [Repository Pattern](https://martinfowler.com/eaaCatalog/repository.html)
- [DTO Pattern](https://martinfowler.com/eaaCatalog/dataTransferObject.html)

