<?php

declare(strict_types=1);

namespace App\Auth\V1\Jwt;

use App\Auth\V1\Models\WorkstationJwtRevocation;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
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
     * Vérifie si un JWT est révoqué.
     *
     * Deux checks effectués (Q3 review 16.10 — révocation par workstation_uuid) :
     *
     *  1. **Check par `jti`** (legacy) : la row `(jti=$jti)` existe ?
     *  2. **Check workstation-wide** : si `$workstationUuid` et `$iat` fournis, il existe
     *     une row `(workstation_uuid=$workstationUuid, revoked_at >= $iat)` ?
     *     Tous les JWT émis avant la dernière révocation workstation sont invalidés.
     *
     * Le check 2 résout la limitation Phase 2 du `workstation:revoke` qui n'invalidait
     * que les refresh tokens — désormais il invalide effectivement aussi tous les
     * access JWT émis avant la commande (sous réserve cache 60s).
     *
     * Garde-fou Phase 2 : en l'état actuel, **seule** la commande `workstation:revoke`
     * insère dans `workstation_jwt_revocations`. `handleReplay` (cascade replay) ne
     * touche que `workstation_refresh_tokens`. Donc pas de faux positif possible.
     * Si Phase 3+ ajoute des révocations `jti`-only dans cette table, ajouter une
     * colonne `scope` ('jti'|'workstation') pour distinguer.
     */
    public function isRevoked(string $jti, ?string $workstationUuid = null, ?int $iat = null): bool
    {
        if ($this->isJtiRevoked($jti)) {
            return true;
        }
        if ($workstationUuid !== null && $iat !== null) {
            return $this->isWorkstationRevokedAfter($workstationUuid, $iat);
        }

        return false;
    }

    /**
     * Check par `jti` (cache APCu 60s + fallback DB).
     */
    private function isJtiRevoked(string $jti): bool
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
            // DB en panne — fail-open documenté
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
     * Check workstation-wide : true si une row de révocation existe pour ce
     * `workstation_uuid` avec `revoked_at` postérieur à `$iat` (= JWT émis
     * AVANT une révocation workstation-level → invalide).
     *
     * Cache : `jwt:revoked_ws:<workstation_uuid>` = timestamp Unix du MAX
     * revoked_at (ou `0` si aucune révocation). TTL 60s.
     */
    private function isWorkstationRevokedAfter(string $workstationUuid, int $iat): bool
    {
        $cacheKey = $this->workstationCacheKey($workstationUuid);

        try {
            $cached = $this->cacheStore()->get($cacheKey);
            if (is_int($cached)) {
                // 0 = no revocation known. Else : last revoked_at timestamp.
                return $cached > 0 && $cached >= $iat;
            }
        } catch (Throwable) {
            // miss cache → on continue en DB
        }

        try {
            $latest = WorkstationJwtRevocation::query()
                ->where('workstation_uuid', $workstationUuid)
                ->max('revoked_at');
        } catch (Throwable) {
            // DB en panne — fail-open
            return false;
        }

        $cutoffTs = 0;
        if ($latest !== null) {
            $cutoffTs = $latest instanceof Carbon
                ? $latest->timestamp
                : Carbon::parse($latest)->timestamp;
        }

        try {
            $ttl = (int) config('auth_v1.revocation.cache_ttl', 60);
            $this->cacheStore()->put($cacheKey, $cutoffTs, $ttl);
        } catch (Throwable) {
            // pas bloquant
        }

        return $cutoffTs > 0 && $cutoffTs >= $iat;
    }

    /**
     * Calcule la clé cache workstation-wide.
     */
    public function workstationCacheKey(string $workstationUuid): string
    {
        $prefix = (string) config('auth_v1.revocation.cache_prefix', 'jwt:revoked:');

        return $prefix . 'ws:' . $workstationUuid;
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
     * Push une révocation workstation-wide dans le cache (Q3 review 16.10).
     * Stocke le timestamp `revoked_at` sous la clé `jwt:revoked_ws:<uuid>` ;
     * le checker invalide tous les JWT de ce poste dont `iat <= revoked_at`.
     */
    public function pushWorkstationRevocation(string $workstationUuid, int $revokedAtTs): void
    {
        $cacheKey = $this->workstationCacheKey($workstationUuid);
        $ttl = (int) config('auth_v1.revocation.manual_revoke_cache_ttl', 3600);
        try {
            $this->cacheStore()->put($cacheKey, $revokedAtTs, $ttl);
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
