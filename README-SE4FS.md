# API SE4FS pour l'intégration d'applications tierces

Cette documentation décrit l'implémentation des API SE4FS pour l'intégration avec des applications externes selon les spécifications de `Discovery.md`.

## 🎯 Objectif

Permettre la communication bidirectionnelle entre SambaÉdu 4 File Server (SE4FS) et des applications tierces pour :
1. **Synchronisation des données** utilisateurs, fichiers et événements système
2. **Collecte de localisation et monitoring** pour affichage sur cartes interactives

## 🆕 Nouvelles Fonctionnalités de Localisation

**5 nouveaux endpoints** pour la collecte périodique automatisée :

| Endpoint | Authentification | Fréquence | Usage |
|----------|------------------|-----------|--------|
| `/api/v1/static` | ✅ | 1x | Coordonnées, établissement, données statiques |
| `/api/v1/health` | ✅ | 30s | État système, détection pannes |
| `/api/v1/metrics` | ✅ | 5min | CPU, RAM, disque, utilisateurs |
| `/api/v1/historical/{period}` | ✅ | 1h | Données historiques et tendances |
| `/api/v1/public/location/summary` | ❌ | À la demande | Discovery public, scanning réseau |

**🎯 Cas d'usage :** Permettre à une application externe de collecter automatiquement les données de N instances SE4FS pour affichage sur une carte interactive avec monitoring en temps réel.

## 🚀 Installation et Configuration

### 1. Configuration de l'environnement

Copiez le fichier `.env.example` et configurez les variables SE4FS :

```bash
cp .env.example .env
```

### Variables importantes à configurer :

```env
# Activation de l'API SE4FS
SE4FS_API_ENABLED=true

# Informations sur l'établissement
SE4FS_ESTABLISHMENT_NAME="Votre Établissement"
SE4FS_ESTABLISHMENT_UAI=0123456A
SE4FS_ESTABLISHMENT_DN="DC=votre-etab,DC=ac-academie,DC=fr"

# Clé secrète (générer une clé forte)
SE4FS_SECRET_KEY=votre-cle-secrete-forte

# Configuration application tierce (après handshake)
CLIENT_WEBHOOK_URL=https://app.votre-etab.fr/api/sambaedu/webhook/uuid

# Configuration des APIs de localisation (optionnel)
SE4FS_ESTABLISHMENT_COORDINATES_LAT=48.8566
SE4FS_ESTABLISHMENT_COORDINATES_LNG=2.3522
SE4FS_ESTABLISHMENT_ACADEMIE="Paris"
SE4FS_ESTABLISHMENT_TYPE="lycee"
SE4FS_ESTABLISHMENT_ADDRESS_STREET="123 Rue de l'Education"
SE4FS_ESTABLISHMENT_ADDRESS_CITY="Paris"
SE4FS_ESTABLISHMENT_ADDRESS_POSTAL_CODE="75001"
SE4FS_ESTABLISHMENT_CONTACT_PHONE="01.23.45.67.89"
SE4FS_ESTABLISHMENT_CONTACT_EMAIL="admin@etablissement.fr"
```

### 2. Installation des dépendances

```bash
composer install
php artisan key:generate
```

### 3. Génération de la documentation API

```bash
php artisan sambaedu:update-docs
```

## 📚 Endpoints API

### 1. Discovery (Public) - Priorité 1

**Endpoint :** `GET /api/v1/discovery`

Permet aux applications tierces de découvrir automatiquement cette instance SE4FS.

**Headers requis :**
- `Accept: application/json`
- `User-Agent: CLIENT-Discovery/1.0` (recommandé)

**User-Agents acceptés :**
- `CLIENT-Discovery/` - Format générique recommandé
- `IRUNDO-Discovery/` - Compatibilité IRUNDO
- `SE4FS-Client/` - Format SE4FS spécifique
- `curl/`, `PostmanRuntime/`, `Insomnia/` - Pour les tests

**Rate limiting :** 10 req/min par IP

**Exemple de réponse :**
```json
{
  "se4fs_instance": true,
  "name": "SE4FS - Lycée Jean Moulin",
  "se4fs_version": "4.2.1",
  "api_version": "1.0",
  "establishment": {
    "name": "Lycée Jean Moulin",
    "uai": "0751234A",
    "dn": "DC=etablissement,DC=ac-paris,DC=fr"
  },
  "system_info": {
    "hostname": "se4fs-prod",
    "ip_address": "192.168.122.50",
    "disk_usage": {
      "total_gb": 2000,
      "used_gb": 856,
      "percentage": 42.8
    }
  },
  "capabilities": {
    "file_sharing": true,
    "user_management": true,
    "quota_management": true,
    "webhook_support": true
  }
}
```

### 2. Handshake (Public) - Priorité 1

**Endpoint :** `POST /api/v1/handshake`

Établit la connexion sécurisée avec l'application tierce et échange les tokens.

**Headers requis :**
- `Content-Type: application/json`
- `User-Agent: CLIENT-API/1.0` (ou autre format accepté)

**User-Agents acceptés :**
- `CLIENT-API/` - Format générique recommandé
- `IRUNDO-API/` - Compatibilité IRUNDO
- `SE4FS-Client/` - Format SE4FS spécifique
- `curl/`, `PostmanRuntime/`, `Insomnia/` - Pour les tests

**Rate limiting :** 5 req/min par IP

**Corps de requête :**
```json
{
  "client_instance": {
    "id": "uuid-client-instance", 
    "name": "Application Production",
    "url": "https://app.etablissement.fr",
    "version": "1.0.0"
  },
  "authentication": {
    "token": "client_abc123...",
    "secret": "shared-secret-64-chars",
    "webhook_url": "https://app.etablissement.fr/api/sambaedu/webhook/uuid",
    "webhook_token": "webhook-token-64-chars"
  },
  "capabilities": {
    "user_sync": true,
    "group_sync": true,
    "file_sharing": true,
    "notifications": true
  }
}
```

**Réponse de succès :**
```json
{
  "success": true,
  "message": "Handshake successful",
  "se4fs_instance": {
    "id": "uuid-se4fs-instance",
    "api_token": "se4fs_xyz789...",
    "webhook_url": "https://192.168.122.50/api/v1/webhook",
    "webhook_token": "se4fs-webhook-token-64-chars"
  },
  "api_version": "1.0",
  "capabilities": {
    "user_events": true,
    "file_events": true,
    "system_monitoring": true,
    "quota_alerts": true
  }
}
```

### 3. Webhook entrant (Authentifié) - Priorité 2

**Endpoint :** `POST /api/v1/webhook`

Reçoit les notifications depuis les applications tierces.

**Headers requis :**
- `X-Webhook-Token: token-de-verification`
- `Content-Type: application/json`

**Rate limiting :** 100 req/min par token

### 4. Liste des utilisateurs (Authentifié) - Priorité 3

**Endpoint :** `GET /api/v1/users`

Retourne la liste des utilisateurs avec pagination.

**Headers requis :**
- `Authorization: Bearer se4fs_xyz789...`

**Paramètres :**
- `since` (optionnel) : Date ISO pour récupérer les utilisateurs modifiés
- `limit` (optionnel) : Nombre max d'utilisateurs (défaut: 100, max: 1000)

**Rate limiting :** 100 req/min par token

### 5. Statistiques système (Authentifié) - Priorité 3

**Endpoint :** `GET /api/v1/stats`

Retourne les statistiques complètes du système.

**Headers requis :**
- `Authorization: Bearer se4fs_xyz789...`

**Rate limiting :** 100 req/min par token

## 🗺️ APIs de Localisation et Monitoring

Ces nouvelles APIs permettent la collecte périodique des données de localisation et de monitoring pour l'affichage sur des cartes interactives.

### 6. Données statiques de localisation (Authentifié)

**Endpoint :** `GET /api/v1/static`

Retourne les données statiques qui ne changent pas souvent (coordonnées, établissement, etc.). À collecter **1 seule fois** ou lors de changements.

**Headers requis :**
- `Authorization: Bearer se4fs_xyz789...`

**Intervalle de collecte :** Une seule fois ou sur changement

**Rate limiting :** 100 req/min par token

**Exemple de réponse :**
```json
{
  "success": true,
  "timestamp": "2025-01-17T10:30:00Z",
  "collection_interval": null,
  "note": "Collect only once or when changed",
  "instance": {
    "uai": "0751234A",
    "name": "Lycée Jean Moulin",
    "coordinates": {
      "latitude": 48.8566,
      "longitude": 2.3522
    },
    "version": "4.2.1",
    "install_date": "2024-09-15",
    "last_update": "2024-12-01"
  },
  "establishment": {
    "type": "lycee",
    "academie": "Paris",
    "address": {
      "street": "123 Rue de l'Education",
      "city": "Paris",
      "postal_code": "75001"
    },
    "contact": {
      "phone": "01.23.45.67.89",
      "email": "admin@lycee-moulin.ac-paris.fr"
    },
    "stats": {
      "total_users": 1250,
      "total_computers": 150,
      "total_classes": 45
    }
  },
  "network": {
    "ip_addresses": {
      "se4fs": "192.168.1.10",
      "se4ad": "192.168.1.11"
    },
    "domain": "lycee-moulin.ac-paris.fr"
  }
}
```

### 7. Contrôle de santé système (Authentifié)

**Endpoint :** `GET /api/v1/health`

Retourne l'état de santé du système pour détection rapide des pannes. À collecter **toutes les 30 secondes**.

**Headers requis :**
- `Authorization: Bearer se4fs_xyz789...`

**Intervalle de collecte :** 30 secondes

**Rate limiting :** 100 req/min par token

**Exemple de réponse :**
```json
{
  "success": true,
  "timestamp": "2025-01-17T10:30:00Z",
  "collection_interval": 30,
  "uai": "0751234A",
  "status": "active",
  "response_time": 145,
  "services": {
    "samba": "running",
    "ldap": "running",
    "apache": "running"
  },
  "quick_check": {
    "cpu_critical": false,
    "memory_critical": false,
    "disk_critical": false,
    "services_ok": 3,
    "services_error": 0
  },
  "critical_alerts": []
}
```

### 8. Métriques détaillées (Authentifié)

**Endpoint :** `GET /api/v1/metrics`

Retourne les métriques système détaillées (CPU, RAM, disque, utilisateurs). À collecter **toutes les 5 minutes**.

**Headers requis :**
- `Authorization: Bearer se4fs_xyz789...`

**Intervalle de collecte :** 5 minutes

**Rate limiting :** 100 req/min par token

**Exemple de réponse :**
```json
{
  "success": true,
  "timestamp": "2025-01-17T10:30:00Z",
  "collection_interval": 300,
  "uai": "0751234A",
  "status": "active",
  "system": {
    "cpu_usage": 45.2,
    "memory_usage": 68.1,
    "disk_usage": {
      "home": 42.8,
      "sambaedu": 15.3
    },
    "load_average": [0.85, 0.92, 0.78],
    "uptime": 86400,
    "network_io": {
      "bytes_in": 1234567890,
      "bytes_out": 987654321
    }
  },
  "activity": {
    "users_connected": 23,
    "active_sessions": {
      "samba": 23,
      "ldap": 15
    },
    "recent_logins": 5
  }
}
```

### 9. Données historiques (Authentifié)

**Endpoint :** `GET /api/v1/historical/{period}`

Retourne les données historiques sur une période donnée. À collecter **toutes les heures**.

**Paramètres d'URL :**
- `period` : `1h`, `24h`, `7d`, `30d`

**Headers requis :**
- `Authorization: Bearer se4fs_xyz789...`

**Intervalle de collecte :** 1 heure

**Rate limiting :** 100 req/min par token

**Exemple de réponse :**
```json
{
  "success": true,
  "timestamp": "2025-01-17T10:30:00Z",
  "collection_interval": 3600,
  "uai": "0751234A",
  "period": "24h",
  "data_points": 144,
  "sampling_interval": 600,
  "metrics": {
    "cpu_usage": [
      {"timestamp": "2025-01-16T10:30:00Z", "value": 45.2},
      {"timestamp": "2025-01-16T11:30:00Z", "value": 42.1}
    ],
    "memory_usage": [
      {"timestamp": "2025-01-16T10:30:00Z", "value": 68.1}
    ],
    "users_connected": [
      {"timestamp": "2025-01-16T10:30:00Z", "value": 23}
    ]
  },
  "summary": {
    "cpu_avg": 43.5,
    "cpu_max": 67.8,
    "memory_avg": 65.2,
    "users_max": 45
  }
}
```

### 10. Résumé de localisation public (Sans authentification)

**Endpoint :** `GET /api/v1/public/location/summary`

Endpoint public pour la découverte et le scanning réseau. Retourne un résumé simplifié des informations de localisation.

**Headers requis :**
- `Accept: application/json`

**Rate limiting :** 10 req/min par IP

**Exemple de réponse :**
```json
{
  "success": true,
  "timestamp": "2025-01-17T10:30:00Z",
  "instance": {
    "uai": "0751234A",
    "name": "Lycée Jean Moulin",
    "coordinates": {
      "latitude": 48.8566,
      "longitude": 2.3522
    },
    "status": "active"
  },
  "discovery": {
    "endpoints": {
      "static": "/api/v1/static",
      "health": "/api/v1/health",
      "metrics": "/api/v1/metrics",
      "historical": "/api/v1/historical/{period}"
    },
    "authentication_required": true,
    "collection_intervals": {
      "static": "once",
      "health": "30s",
      "metrics": "5min",
      "historical": "1h"
    }
  }
}
```

## 🔒 Sécurité

### Authentification API

- **Tokens SE4FS :** Format `se4fs_` + 32 caractères aléatoires
- **Durée de vie :** 90 jours avec rotation automatique  
- **Stockage :** Hash SHA-256 en base de données

### Validation webhook

```php
$providedToken = $request->header('X-Webhook-Token');
$storedToken = 'webhook-token-stored-in-db';

if (!hash_equals(hash('sha256', $storedToken), hash('sha256', $providedToken))) {
    // Token invalide
}
```

### Rate limiting

- **Discovery :** 10 req/min par IP
- **Handshake :** 5 req/min par IP
- **APIs authentifiées :** 100 req/min par token

## 🔄 Webhooks sortants

SE4FS envoie des webhooks vers les applications tierces lors d'événements importants :

### Événements utilisateur
- `user.login` - Connexion utilisateur
- `user.logout` - Déconnexion utilisateur

### Événements fichiers
- `file.uploaded` - Fichier téléchargé
- `file.shared` - Fichier partagé

### Événements système
- `quota.exceeded` - Quota dépassé
- `system.status` - Statut système

## 📊 Logs et monitoring

Tous les événements SE4FS sont loggés avec le format :

```json
{
  "timestamp": "2025-01-17T10:30:00Z",
  "level": "info",
  "component": "se4fs",
  "action": "handshake",
  "client_instance_id": "uuid-client",
  "ip_address": "192.168.1.16",
  "success": true
}
```

## 🧪 Tests

### Test de découverte
```bash
curl -H "Accept: application/json" \
     -H "User-Agent: CLIENT-Discovery/1.0" \
     http://votre-se4fs/api/v1/discovery
```

### Test d'authentification
```bash
curl -H "Authorization: Bearer se4fs_votre_token" \
     http://votre-se4fs/api/v1/users?limit=5
```

### Test des APIs de localisation

#### Test du résumé public (sans authentification)
```bash
curl -H "Accept: application/json" \
     http://votre-se4fs/api/v1/public/location/summary
```

#### Test des données statiques (authentifié)
```bash
curl -H "Authorization: Bearer se4fs_votre_token" \
     http://votre-se4fs/api/v1/static
```

#### Test du contrôle de santé (authentifié)
```bash
curl -H "Authorization: Bearer se4fs_votre_token" \
     http://votre-se4fs/api/v1/health
```

#### Test des métriques détaillées (authentifié)
```bash
curl -H "Authorization: Bearer se4fs_votre_token" \
     http://votre-se4fs/api/v1/metrics
```

#### Test des données historiques (authentifié)
```bash
curl -H "Authorization: Bearer se4fs_votre_token" \
     http://votre-se4fs/api/v1/historical/24h
```

### Scripts de test automatisés

Pour tester rapidement toutes les nouvelles APIs :

```bash
# Script de test complet (unitaires + intégration)
./test-se4fs.sh

# Script de test sécurisé pour production
./test-se4fs-production.sh
```

## 🐛 Dépannage

### Vérifier la configuration
```bash
php artisan config:cache
php artisan route:cache
```

### Vérifier les logs
```bash
tail -f storage/logs/laravel.log | grep SE4FS
```

### Vérifier la documentation API
```bash
php artisan sambaedu:update-docs --check
```

Accès à la documentation Swagger : `https://votre-se4fs/api/documentation`

## 📝 TODO Implémentation

### APIs SE4FS principales (Existantes)

Les éléments suivants nécessitent une implémentation complète en production :

- [ ] Intégration LDAP réelle pour les utilisateurs
- [ ] Calcul des quotas réels
- [ ] Stockage des tokens en base de données
- [ ] Envoi HTTP réel des webhooks avec Guzzle
- [ ] Gestion des erreurs et retry pour les webhooks
- [ ] Configuration HTTPS en production

### APIs de Localisation (Nouvelles)

**🔄 RESTANT :**
- [ ] Intégration InfluxDB pour données historiques réelles
- [ ] Cache Redis pour optimisation des performances
- [ ] Configuration en production avec variables d'environnement

## 📞 Support

Pour toute question sur l'implémentation, consultez :
- Les logs Laravel dans `storage/logs/`
- La documentation Swagger générée
- Les spécifications complètes dans `devSteps/Discovery.md` 