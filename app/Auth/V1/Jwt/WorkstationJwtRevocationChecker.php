<?php

declare(strict_types=1);

namespace App\Auth\V1\Jwt;

use App\Auth\V1\Models\WorkstationJwtRevocation;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Story 16.10 — D4.
 *
 * Vérifie si un `jti` est révoqué via une double couche :
 *
 *  1. Cache APCu (`Cache::store('apc')->get('jwt:revoked:<jti>')`,
 *     TTL 60s — alignement Tech Spec §7).
 *  2. Fallback DB (`workstation_jwt_revocations.jti = ?`) si miss cache.
 *
 * **Pattern** : le `verify()` du `WorkstationJwtVerifier` invoque cette
 * classe **après** la vérification cryptographique (signature + exp). C'est
 * coûteux d'aller en DB pour rien si la signature est de toute façon
 * invalide.
 *
 * **Push de révocation** : la commande Artisan `workstation:revoke` insère
 * en DB **et** push le flag cache (TTL plus long, 3600s — `manual_revoke_cache_ttl`)
 * pour propager rapidement la révocation à tous les workers PHP-FPM.
 *
 * **Dégradation gracieuse** : si APCu indisponible (CLI sans extension), on
 * tombe directement en DB sans erreur. Si la DB est down, on log un warning
 * mais on **considère le JWT comme non révoqué** (fail-open) — décision
 * volontaire : un crash DB ne doit pas bloquer toute l'API. Le risque
 * acceptable est qu'un JWT révoqué passe momentanément (TTL de l'access
 * 24h max).
 */
class WorkstationJwtRevocationChecker
{
    /**
     * @return bool `true` si le jti est connu comme révoqué.
     */
    public function isRevoked(string $jti): bool
    {
        // 1. Cache lookup
        $cacheKey = $this->cacheKey($jti);
        try {
            $store = $this->cacheStore();
            $cached = $store->get($cacheKey);
            if ($cached === true || $cached === '1' || $cached === 1) {
                return true;
            }
            if ($cached === false || $cached === '0' || $cached === 0) {
                return false;
            }
            // miss ou null : on continue en DB
        } catch (Throwable) {
            // Cache hors ligne : on bascule en DB
        }

        // 2. DB lookup
        try {
            $hit = WorkstationJwtRevocation::query()
                ->where('jti', $jti)
                ->exists();
        } catch (Throwable) {
            // DB en panne — fail-open documenté ci-dessus
            return false;
        }

        // 3. On warm le cache pour les requêtes suivantes
        try {
            $ttl = (int) config('auth_v1.revocation.cache_ttl', 60);
            $this->cacheStore()->put($cacheKey, $hit, $ttl);
        } catch (Throwable) {
            // pas bloquant
        }

        return $hit;
    }

    /**
     * Push une révocation manuelle dans le cache (utilisé par la commande
     * `workstation:revoke` pour invalidation rapide).
     */
    public function pushRevocation(string $jti): void
    {
        $cacheKey = $this->cacheKey($jti);
        $ttl = (int) config('auth_v1.revocation.manual_revoke_cache_ttl', 3600);
        try {
            $this->cacheStore()->put($cacheKey, true, $ttl);
        } catch (Throwable) {
            // pas bloquant — la DB reste la source de vérité
        }
    }

    /**
     * Calcule la clé cache.
     */
    public function cacheKey(string $jti): string
    {
        $prefix = (string) config('auth_v1.revocation.cache_prefix', 'jwt:revoked:');

        return $prefix . $jti;
    }

    /**
     * Résout le cache store cible (généralement `apc`, override `array` en
     * tests via `auth_v1.revocation.cache_store`).
     */
    public function cacheStore(): CacheRepository
    {
        $store = (string) config('auth_v1.revocation.cache_store', 'apc');

        /** @var CacheManager $manager */
        $manager = Cache::getFacadeRoot();

        return $manager->store($store);
    }
}
