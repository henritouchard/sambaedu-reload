# Redis Configuration pour Production

## Pourquoi Redis ?

Par défaut, SambaEdu utilise du stockage fichier et base de données :
- **Cache** : fichiers sur disque (lent, pas partagé entre serveurs)
- **Sessions** : fichiers (perdues au redémarrage)
- **Queues** : database avec polling (inefficace)
- **Broadcast** : logs uniquement (pas de real-time)

**Redis** centralise tout cela en mémoire :
- ✅ Ultra-rapide
- ✅ Partagé entre plusieurs serveurs
- ✅ Persistance optionnelle
- ✅ Real-time capabilities

---

## Installation via Docker Compose

### 1. Ajouter Redis au `docker-compose.yml`

```yaml
services:
  redis:
    image: redis:7-alpine
    container_name: sambaedu-redis
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data
    command: redis-server --appendonly yes
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 5

volumes:
  redis_data:
```

### 2. Démarrer le service

```bash
docker-compose up -d redis
```

### 3. Vérifier la connexion

```bash
docker-compose exec redis redis-cli ping
# Réponse attendue: PONG
```

---

## Configuration Laravel

### 1. Mettre à jour le `.env`

```env
# Cache
CACHE_DRIVER=redis

# Sessions
SESSION_DRIVER=redis

# Queues
QUEUE_CONNECTION=redis

# Broadcast
BROADCAST_DRIVER=redis

# Redis connection
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

### 2. Vérifier la configuration dans `config/database.php`

Redis devrait être pré-configuré. Vérifier la section `redis` :

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => 0,
    ],
    'cache' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => 1,
    ],
],
```

---

## Tester la configuration

### 1. Test basique

```bash
php artisan tinker
> Redis::set('test', 'value');
> Redis::get('test');
// Affiche: "value"
```

### 2. Test du cache

```bash
php artisan cache:clear
php artisan tinker
> Cache::put('key', 'value', 60);
> Cache::get('key');
// Affiche: "value"
```

### 3. Monitorer Redis en live

```bash
docker-compose exec redis redis-cli monitor
```

---

## Sécurité

### En production, ajouter un mot de passe Redis

#### 1. Générer un mot de passe sécurisé

```bash
openssl rand -base64 32
```

#### 2. Mettre à jour `docker-compose.yml`

```yaml
redis:
  command: redis-server --requirepass "YOUR_SECURE_PASSWORD"
```

#### 3. Mettre à jour le `.env`

```env
REDIS_PASSWORD=YOUR_SECURE_PASSWORD
```

#### 4. Redémarrer Redis

```bash
docker-compose restart redis
```

---

## Persister les données

### Actuellement configuré avec `--appendonly yes`

Cela crée un fichier `appendonly.aof` dans le volume qui :
- ✅ Survit aux redémarrages
- ✅ Stocke chaque commande
- ⚠️ Plus lent qu'une simple sauvegarde RDB

### Alternativement, utiliser RDB (snapshots)

```yaml
redis:
  command: redis-server --save 900 1 --save 300 10
  # Sauvegarde toutes les 15 min (si 1+ changement)
  # Sauvegarde toutes les 5 min (si 10+ changements)
```

---

## Dépannage

### Redis refuse la connexion

```bash
# Vérifier que Redis est en cours d'exécution
docker-compose ps redis

# Vérifier les logs
docker-compose logs redis

# Tester la connexion
docker-compose exec redis redis-cli ping
```

### Données Redis corrompues

```bash
# Nettoyer Redis complètement
docker-compose exec redis redis-cli FLUSHALL

# Ou redémarrer le conteneur
docker-compose restart redis
```

### Performances dégradées

```bash
# Vérifier l'utilisation mémoire
docker-compose exec redis redis-cli INFO memory

# Nettoyer les clés expirées
docker-compose exec redis redis-cli MEMORY PURGE
```

---

## Ressources

- [Redis Documentation](https://redis.io/documentation)
- [Laravel Redis Documentation](https://laravel.com/docs/cache#redis)
- [redis-cli Commands](https://redis.io/commands/)
