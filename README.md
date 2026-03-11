
// TODO: mettre à jour la documentation

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

readme.md

# Laravel

Le code de laravel est dans le dossier `sources/var/www/sambaedu/laravel`.
Vous pourrez trouver toutes les informations sur laravel sur le site https://laravel.com/ et plus particulièrement sur la documentation officielle https://laravel.com/docs/12.x

## Prise en main

### Structure d'une API Laravel

La structure suivante est recommandée pour organiser votre code API :

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/              # Tous les contrôleurs API ici
│   │       ├── v1/          # Versionnement des APIs (optionnel)
│   │       └── Controller.php
│   ├── Requests/            # Validation des requêtes
│   │   └── Api/
│   └── Resources/           # Transformation des données (JSON)
│       └── Api/
├── Services/                # Logique métier
├── Repositories/           # Accès aux données
└── Models/                # Modèles Eloquent
```

### Composants principaux

#### 1. Controllers (`app/Http/Controllers/Api/`)
Les contrôleurs sont responsables de :
- Recevoir la requête
- Valider les données
- Déléguer au Service
- Retourner la réponse

```php
class EcowattController extends Controller
{
    public function __construct(
        private EcowattService $ecowattService
    ) {}

    public function status()
    {
        return response()->json(
            $this->ecowattService->getStatus()
        );
    }
}
```

#### 2. Services (`app/Services/`)
Les services contiennent la logique métier :
```php
class EcowattService
{
    public function getStatus()
    {
        // Logique métier ici
        return [/*...*/];
    }
}
```

#### 3. Requests (`app/Http/Requests/Api/`)
Validation des données entrantes :
```php
class EcowattRequest extends FormRequest
{
    public function rules()
    {
        return [
            'date' => 'required|date'
        ];
    }
}
```

#### 4. Resources (`app/Http/Resources/Api/`)
Transformation des données en JSON :
```php
class EcowattResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'status' => $this->status,
            'timestamp' => $this->created_at
        ];
    }
}
```

### Routes API (`routes/api.php`)
```php
Route::prefix('v1')->group(function () {
    Route::get('/ecowatt', [EcowattController::class, 'status']);
});
```

### Migration du code legacy

#### 1. Identifier les dépendances
- Lister les includes PHP nécessaires
- Noter les variables globales utilisées
- Identifier les fonctions helpers

#### 2. Créer des Services


#### 3. Utiliser l'injection de dépendances
Cette pratique consiste à injecter les dépendances dans la classe lieu de les instancier systématiquement avant de les utiliser.

Cela permet de rendre le code plus flexible, plus performant et plus facile à maintenir.

```php
class EcowattController
{
    public function __construct(
        private ConfigService $config,
        private LdapService $ldap
    ) {}
}
```

### Bonnes pratiques

1. **Architecture**
   - Utiliser les interfaces pour découpler le code
   - Centraliser la configuration dans `config/`
   - Préférer l'injection de dépendances aux facades
   - Utiliser les migrations pour la base de données

2. **Gestion des erreurs**
```php
// app/Exceptions/Handler.php
class Handler extends ExceptionHandler
{
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*')) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
        return parent::render($request, $e);
    }
}
```

3. **Tests**
   - Écrire des tests unitaires pour les Services
   - Écrire des tests d'intégration pour les APIs
   - Utiliser les factories pour les données de test

### Exemple complet d'une API

Pour créer une nouvelle API :

1. **Créer le contrôleur**
```bash
php artisan make:controller Api/v1/EcowattController
```

2. **Créer le service**
```php
// app/Services/EcowattService.php
class EcowattService
{
    public function getStatus()
    {
        // Implémentation
    }
}
```

3. **Définir la route**
```php
// routes/api.php
Route::get('/v1/ecowatt', [EcowattController::class, 'status']);
```

4. **Ajouter la validation si nécessaire**
```bash
php artisan make:request Api/EcowattRequest
```

5. **Créer une resource si nécessaire**
```bash
php artisan make:resource Api/EcowattResource
```
