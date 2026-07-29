<?php

declare(strict_types=1);

namespace App\OidcWitness\Jwt;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Story 55.3 — **L'ANTI-REJEU `jti` CÔTÉ CLIENT** (l'AC « jti rejoué » de
 * l'Epic 55).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI IL EST ICI ET PAS CHEZ LE FOURNISSEUR
 *
 *  SE5 ÉMET un `jti` (UUID v4) dans chaque id_token, mais il ne REVOIT jamais
 *  un id_token : les seuls jetons qu'il reçoit sont des codes d'autorisation
 *  (usage unique sous verrou, déjà testé en 55.1) et des access tokens
 *  OPAQUES. Un anti-rejeu serveur d'id_token n'aurait donc aucun point
 *  d'application. L'usage unique se joue chez le CONSOMMATEUR — exactement
 *  comme l'Epic 20 l'a construit quand SE5 était, lui, le consommateur.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Calque de `FederatedJwtReplayChecker::consumeOnce()` **sans sa couche base
 * de données** : le témoin n'a pas le droit d'y toucher (FR24). Reste la
 * réservation atomique `add()` (set-if-absent), bornée par `exp + leeway`.
 *
 * **Fail-CLOSED.** Store indisponible, TTL nul, exception : on REFUSE. Un
 * jeton d'entrée humain ne s'accepte pas dans le doute (doctrine D-6 de
 * l'Epic 20). C'est la différence assumée avec le checker de révocation des
 * postes, qui fail-open pour ne pas bloquer l'API du parc.
 *
 * **Limite ASSUMÉE et documentée** : le store `file` est local au serveur. Il
 * suffit à une sonde de contrat mono-instance, et il ne suffirait pas à une
 * vraie extension répartie — laquelle aura SON stockage (le SDK de l'Epic 58,
 * extrait de BBB). Écrire ici un filet partagé (base, Redis) reviendrait à
 * donner au témoin une capacité qu'une extension n'a pas : la sonde mentirait.
 */
class WitnessJtiReplayGuard
{
    /**
     * Tente de consommer le `jti`. `true` = premier usage, `false` = rejeu
     * détecté OU impossibilité de trancher (fail-closed).
     *
     * @param  int  $exp  Expiration du jeton : inutile de mémoriser un `jti`
     *                    au-delà, le vérificateur rejetterait de toute façon.
     */
    public function consumeOnce(string $jti, int $exp): bool
    {
        if ($jti === '') {
            return false;
        }

        $ttl = $this->ttlFor($exp);
        if ($ttl <= 0) {
            // Jeton déjà expiré : le vérificateur l'a rejeté en amont. On ne
            // mémorise rien (pas de TTL négatif) et on refuse.
            return false;
        }

        try {
            $store = $this->cacheStore();
        } catch (Throwable) {
            return false;
        }

        try {
            // `add()` est atomique : `false` si la clé existe déjà.
            return (bool) $store->add($this->cacheKey($jti), true, $ttl);
        } catch (Throwable) {
            return false;
        }
    }

    public function cacheKey(string $jti): string
    {
        $prefix = (string) config('oidc.witness.replay_cache_prefix', 'oidc-witness:jti:');

        return $prefix . hash('sha256', $jti);
    }

    /**
     * Store cible : `file` en production (le témoin n'a pas la base), `array`
     * en tests. Patron `federated_auth.replay.cache_store`.
     */
    public function cacheStore(): CacheRepository
    {
        $store = (string) config('oidc.witness.replay_cache_store', 'file');

        return Cache::store($store);
    }

    /**
     * TTL de mémorisation : jusqu'à l'expiration du jeton, leeway inclus.
     * Au-delà, un rejeu serait déjà rejeté comme expiré.
     */
    private function ttlFor(int $exp): int
    {
        $leeway = (int) config('oidc.leeway', 60);

        return max(0, $exp - Carbon::now()->getTimestamp() + $leeway);
    }
}
