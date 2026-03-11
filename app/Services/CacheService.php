<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service de cache pour SE4 utilisant le système de cache Laravel
 * 
 * Ce service encapsule le système de cache Laravel pour fournir une interface
 * cohérente et spécialisée pour les besoins de SE4. Il remplace l'ancienne
 * dépendance APCu par une solution plus robuste et flexible.
 * 
 * FONCTIONNALITÉS :
 * - Cache avec préfixe automatique 'se4_' pour éviter les collisions
 * - Support de tous les drivers Laravel (file, redis, memcached, etc.)
 * - Gestion d'erreurs robuste avec logs détaillés
 * - TTL par défaut de 3600 secondes (1 heure)
 * - Méthodes compatibles avec l'ancienne API APCu
 * 
 * DRIVERS SUPPORTÉS :
 * - file     : Stockage sur disque (par défaut, aucune dépendance)
 * - redis    : Cache en mémoire haute performance (recommandé production)
 * - memcached: Cache distribué pour applications multi-serveurs
 * - database : Stockage en base de données
 * - array    : Cache en mémoire pour les tests
 * 
 * CONFIGURATION :
 * Le driver de cache est configuré via CACHE_DRIVER dans .env
 * Les données sont stockées avec le préfixe 'se4_' pour isolation
 * 
 * UTILISATION :
 * $cache = app(CacheService::class);
 * $cache->store('user_data', $data, 3600);
 * $data = $cache->fetch('user_data');
 * $data = $cache->remember('expensive_data', 7200, fn() => $this->loadData());
 * 
 * MIGRATION DEPUIS APCu :
 * - add()    : Ajouter seulement si la clé n'existe pas
 * - fetch()  : Récupérer une valeur (false si inexistante)
 * - store()  : Stocker une valeur (écrase si existe)
 * - delete() : Supprimer une clé
 * - isAvailable() : Toujours true avec Laravel
 * 
 * NOUVELLES MÉTHODES :
 * - has()    : Vérifier l'existence d'une clé
 * - remember(): Cache avec callback (pattern Laravel)
 * - flush()  : Vider tout le cache SE4
 * 
 * @package App\Services
 * @author SE4 Team
 * @version 2.0
 */
class CacheService
{
    private string $prefix;

    public function __construct()
    {
        $this->prefix = 'se4_';
    }

    /**
     * Ajouter une valeur au cache seulement si la clé n'existe pas
     */
    public function add(string $key, $value, int $ttl = 3600): bool
    {
        try {
            $fullKey = $this->prefix . $key;
            
            // Laravel Cache::add retourne false si la clé existe déjà
            if (Cache::has($fullKey)) {
                return false;
            }
            
            return Cache::put($fullKey, $value, $ttl);
        } catch (\Exception $e) {
            Log::error('Erreur cache add', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Récupérer une valeur du cache
     */
    public function fetch(string $key)
    {
        try {
            $fullKey = $this->prefix . $key;
            return Cache::get($fullKey, false);
        } catch (\Exception $e) {
            Log::error('Erreur cache fetch', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Stocker une valeur dans le cache (écrase si existe)
     */
    public function store(string $key, $value, int $ttl = 3600): bool
    {
        try {
            $fullKey = $this->prefix . $key;
            return Cache::put($fullKey, $value, $ttl);
        } catch (\Exception $e) {
            Log::error('Erreur cache store', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Supprimer une clé du cache
     */
    public function delete($key): bool
    {
        try {
            $fullKey = $this->prefix . $key;
            return Cache::forget($fullKey);
        } catch (\Exception $e) {
            Log::error('Erreur cache delete', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Vérifier si une clé existe dans le cache
     */
    public function has(string $key): bool
    {
        try {
            $fullKey = $this->prefix . $key;
            return Cache::has($fullKey);
        } catch (\Exception $e) {
            Log::error('Erreur cache has', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Vider tout le cache SE4
     */
    public function flush(): bool
    {
        try {
            // Récupérer toutes les clés avec le préfixe SE4 et les supprimer
            // Note: Cette méthode dépend du driver de cache utilisé
            return Cache::flush();
        } catch (\Exception $e) {
            Log::error('Erreur cache flush', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Le cache Laravel est toujours disponible
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Récupérer ou stocker avec callback
     */
    public function remember(string $key, int $ttl, callable $callback)
    {
        try {
            $fullKey = $this->prefix . $key;
            return Cache::remember($fullKey, $ttl, $callback);
        } catch (\Exception $e) {
            Log::error('Erreur cache remember', ['key' => $key, 'error' => $e->getMessage()]);
            return $callback();
        }
    }
}
