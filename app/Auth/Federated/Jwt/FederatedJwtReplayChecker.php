<?php

declare(strict_types=1);

namespace App\Auth\Federated\Jwt;

use App\Auth\Federated\Models\FederatedJwtConsumption;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Story 20.1 — D-6.
 *
 * Anti-rejeu `jti` à USAGE UNIQUE. Calqué sur le pattern double-couche de
 * {@see \App\Auth\V1\Jwt\WorkstationJwtRevocationChecker} mais avec une
 * sémantique inversée : ici un `jti` DÉJÀ VU est rejeté (le tier workstation
 * traite la révocation, pas la consommation unique).
 *
 * Pipeline (atomique côté cache) :
 *
 *  1. `consumeOnce($jti, $exp)` tente d'ENREGISTRER le `jti` comme consommé.
 *     - Cache : `add()` (atomique « set-if-absent » multi-worker APCu) ;
 *       si la clé existe déjà → rejeu détecté.
 *     - DB : insert idempotent (`jti` unique) ; une violation d'unicité →
 *       rejeu détecté (filet de sécurité si le cache a expiré ou redémarré).
 *
 * **Sécurité — fail-CLOSED côté cache atomique** : contrairement au checker
 * de révocation (qui fail-open volontairement pour ne pas bloquer l'API
 * poste), le anti-rejeu d'un jeton d'entrée humain doit refuser en cas de
 * doute raisonnable. On combine néanmoins cache + DB pour qu'une panne d'UNE
 * couche ne casse pas le login légitime.
 */
class FederatedJwtReplayChecker
{
    /**
     * Tente de consommer le `jti`. Retourne `true` si la consommation a
     * réussi (premier usage), `false` si le `jti` était déjà consommé (rejeu).
     *
     * @param int $exp Timestamp d'expiration du jeton (borne le TTL de
     *                 mémorisation : inutile de garder un jti au-delà de exp).
     */
    public function consumeOnce(string $jti, int $exp): bool
    {
        $cacheReserved = $this->reserveInCache($jti, $exp);

        // Si le cache a explicitement détecté le rejeu → stop immédiat.
        if ($cacheReserved === false) {
            return false;
        }

        // Couche DB (filet de sécurité + persistance au-delà du TTL cache).
        return $this->reserveInDatabase($jti, $exp);
    }

    /**
     * Réservation atomique en cache via `add()` (set-if-absent).
     *
     * @return bool|null `true` réservé, `false` déjà présent (rejeu), `null`
     *                   cache indisponible (on délègue la décision à la DB).
     */
    private function reserveInCache(string $jti, int $exp): ?bool
    {
        try {
            $store = $this->cacheStore();
        } catch (Throwable) {
            return null;
        }

        $ttl = $this->ttlFor($exp);
        if ($ttl <= 0) {
            // Jeton déjà expiré : le verifier l'aura rejeté en amont. On ne
            // mémorise rien (pas de TTL négatif) et on laisse la DB trancher.
            return null;
        }

        try {
            // `add()` est atomique et renvoie false si la clé existe déjà.
            return $store->add($this->cacheKey($jti), true, $ttl);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Insertion idempotente en DB. Retourne `false` si le `jti` existait déjà.
     */
    private function reserveInDatabase(string $jti, int $exp): bool
    {
        try {
            // Pré-check (évite une exception sur la plupart des rejeux).
            if (FederatedJwtConsumption::query()->where('jti', $jti)->exists()) {
                return false;
            }

            FederatedJwtConsumption::create([
                'jti' => $jti,
                'iss' => '',
                'consumed_at' => Carbon::now(),
                'expires_at' => Carbon::createFromTimestamp(max($exp, Carbon::now()->getTimestamp())),
            ]);

            return true;
        } catch (Throwable) {
            // Violation d'unicité concurrente (course) ou panne DB. En cas de
            // course sur le même jti, l'insert perd → rejeu. On refuse par
            // prudence (fail-closed) : un doublon ne doit jamais ouvrir 2
            // sessions.
            return false;
        }
    }

    /**
     * TTL de mémorisation cache (secondes). On garde le jti en cache jusqu'à
     * l'expiration du jeton (incl. leeway) — au-delà, un rejeu serait de toute
     * façon rejeté par le verifier (jeton expiré). Plafonné à `replay.cache_ttl`
     * pour borner la mémoire ; la couche DB (`expires_at`) reste le filet
     * durable au-delà du cache. Cf. review #7 (ancienne formule = no-op).
     */
    private function ttlFor(int $exp): int
    {
        $configured = (int) config('federated_auth.replay.cache_ttl', 900);
        $leeway = (int) config('federated_auth.jwt.leeway', 60);
        $remaining = $exp - Carbon::now()->getTimestamp() + $leeway;

        return max(0, min($configured, $remaining));
    }

    public function cacheKey(string $jti): string
    {
        $prefix = (string) config('federated_auth.replay.cache_prefix', 'federated:jti:');

        return $prefix . $jti;
    }

    /**
     * Résout le cache store cible (`apc` en prod, `array` en tests via
     * `federated_auth.replay.cache_store`).
     */
    public function cacheStore(): CacheRepository
    {
        $store = (string) config('federated_auth.replay.cache_store', 'apc');

        /** @var CacheManager $manager */
        $manager = Cache::getFacadeRoot();

        return $manager->store($store);
    }
}
