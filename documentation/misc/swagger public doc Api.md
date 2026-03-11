# Documentation API SambaEdu avec L5-Swagger

## Vue d'ensemble

Ce projet utilise **L5-Swagger** pour générer automatiquement la documentation API interactive basée sur OpenAPI 3.0. La documentation est générée à partir des annotations PHP directement dans le code des contrôleurs.

## Installation et Configuration

### 1. Installation du package
```bash
composer require "darkaonline/l5-swagger"
```

### 2. Publication des fichiers de configuration
```bash
php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider"
```

### 3. Configuration dans `config/l5-swagger.php`
Le titre de l'API a été personnalisé :
```php
'api' => [
    'title' => 'SambaEdu API Documentation',
],
```

## Structure de la Documentation

### Contrôleur de base (`app/Http/Controllers/Controller.php`)
Contient les informations générales de l'API :
- Informations de base (titre, version, description)
- Configuration du serveur
- Définition des tags
- Schémas de sécurité

```php
#[OA\Info(
    version: "1.0.0",
    title: "SambaEdu API",
    description: "API REST pour l'application SambaEdu - Gestion des services éducatifs"
)]
#[OA\Server(
    url: "/api/v1",
    description: "Serveur API SambaEdu v1"
)]
```

### Endpoints documentés

#### 1. Health Check (`HealthCheckController`)
- **Route** : `GET /api/v1/public/health`
- **Description** : Vérification de l'état de santé du système
- **Réponses** : 200 (succès), 503 (système dégradé)

#### 2. Gestion des utilisateurs (`UserController`)
- **Route** : `GET /api/v1/user` (authentifié)
  - Informations de l'utilisateur connecté
- **Route** : `GET /api/v1/admin/users` (admin requis)
  - Liste des utilisateurs avec pagination

#### 3. Service EcoWatt (`EcowattController`)
- **Route** : `GET /api/v1/ecowatt/status`
- **Description** : État du réseau électrique français (données RTE)

## Génération de la Documentation

### Commande de génération
```bash
php artisan l5-swagger:generate
```

### Fichiers générés
- `storage/api-docs/api-docs.json` : Spécification OpenAPI en JSON
- Interface web accessible via : `http://localhost:8000/api/documentation`

## Exemples d'Annotations

### Endpoint simple
```php
#[OA\Get(
    path: "/api/v1/public/health",
    summary: "Vérification de l'état de santé du système",
    description: "Retourne l'état de santé de l'application et de ses dépendances",
    tags: ["health"]
)]
```

### Réponse avec schéma détaillé
```php
#[OA\Response(
    response: 200,
    description: "Système en bonne santé",
    content: new OA\JsonContent(
        type: "object",
        properties: [
            "status" => new OA\Property(property: "status", type: "boolean", example: true),
            "timestamp" => new OA\Property(property: "timestamp", type: "string", format: "date-time"),
            // ...
        ]
    )
)]
```

### Paramètres de requête
```php
#[OA\Parameter(
    name: "limit",
    in: "query",
    description: "Nombre maximum d'utilisateurs à retourner",
    required: false,
    schema: new OA\Schema(type: "integer", minimum: 1, maximum: 100, default: 20)
)]
```

### Authentification
```php
#[OA\SecurityScheme(
    securityScheme: "sambaedu_auth",
    type: "apiKey",
    description: "Authentification via cookies de session SambaEdu",
    name: "Cookie",
    in: "header"
)]
```

## Organisation des Tags

Les endpoints sont organisés par tags :
- **health** : Vérifications de santé
- **users** : Gestion des utilisateurs
- **admin** : Endpoints d'administration
- **ecowatt** : Service EcoWatt

## Accès à la Documentation

### Interface web
- URL : `http://localhost:8000/api/documentation`
- Interface interactive Swagger UI
- Test des endpoints directement depuis l'interface

### Fichiers de spécification
- JSON : `http://localhost:8000/docs/api-docs.json`
- Peut être importé dans Postman, Insomnia, etc.

## Développement et Maintenance

### Bonnes pratiques

1. **Documenter tous les endpoints** : Ajouter les annotations OpenAPI à chaque nouvelle méthode de contrôleur
2. **Utiliser des exemples réalistes** : Fournir des exemples de données cohérents
3. **Décrire les erreurs** : Documenter tous les codes de réponse possibles
4. **Regrouper par tags** : Organiser logiquement les endpoints

### Workflow de mise à jour

1. Modifier/ajouter les annotations dans les contrôleurs
2. Régénérer la documentation : `php artisan l5-swagger:generate`
3. Vérifier l'interface web
4. Commiter les changements

### Commandes utiles

```bash
# Génération de la documentation
php artisan l5-swagger:generate

# Nettoyage des fichiers générés
php artisan l5-swagger:generate --all

# Démarrage du serveur de développement
php artisan serve --host=127.0.0.1 --port=8000
```

## Intégration Continue

La génération de documentation peut être intégrée dans le pipeline CI/CD :

```bash
# Dans le script de déploiement
composer install --no-dev --optimize-autoloader
php artisan l5-swagger:generate
```

## Avantages de L5-Swagger

1. **Documentation vivante** : Se met à jour avec le code
2. **Interface interactive** : Test direct des APIs
3. **Standard OpenAPI** : Compatible avec tous les outils modernes
4. **Intégration native Laravel** : Utilise les features Laravel (routes, middleware, etc.)
5. **Support des attributs PHP 8** : Syntaxe moderne et lisible

## Ressources

- [Documentation L5-Swagger](https://github.com/DarkaOnLine/L5-Swagger)
- [Spécification OpenAPI](https://swagger.io/specification/)
- [Swagger-PHP](https://zircote.github.io/swagger-php/) 