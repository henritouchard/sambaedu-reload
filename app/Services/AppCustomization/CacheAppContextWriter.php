<?php

declare(strict_types=1);

namespace App\Services\AppCustomization;

use App\Services\AppCustomization\Contracts\AppContextWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pendant écriture de `CacheAppContextRepository` (Story 16.15).
 *
 * Story 16.7 — AC2.2 (origine). Story 16.15 — AC3 (migration Cache).
 *
 * Écrit la clé `apps.$id` via `Cache::store('app_context')` — store dédié
 * avec `prefix => ''` (interop legacy : le shim `LegacyBootstrapTokenValidator`
 * continue de lire la clé brute en direct via la couche bas-niveau, hors-scope
 * D11, et accède donc à la même donnée). Clé consommée par les endpoints
 * natifs runtime déjà portés :
 *
 *  - `wallpaper_out.php`  → Story 4.7 (`WallpaperController::legacyOut`)
 *  - `firefox_out.php`    → Story 4.8 (`AppPolicyController::legacyFirefoxOut`)
 *  - `thunderbird_out.php`→ Story 4.8 (`AppPolicyController::legacyThunderbirdOut`)
 *  - `network_out.php`    → Story 16.3b (`NetworkOutController`)
 *  - `veyon_out.php`      → Story 16.3b (`VeyonOutController`)
 *  - `associations_out.php` → Story 16.3c (`AssociationsOutController`)
 *
 * **Structure attendue par `AppContext::fromApcuArray` (Story 4.8)** :
 *
 *  - `user`   : `array{cn: string, …}`   (ou string fallback)
 *  - `machine`: `array{cn: string, …}`   (ou string fallback)
 *  - `salle`  : `string`
 *  - `list_u` : `list<string>`           (groupes user)
 *  - `os`     : `'linux'|'windows'`
 *  - `time`   : `int` (timestamp)
 *
 * Les autres clés (`list`, `list_ue`, `list_m`, `parcs`, `liste_applications`,
 * `action`, `context`, `remote`, `interpreter`, `speed`, `userprofile`,
 * `admin`, `cloud`, `id`) sont **passthrough** : conservées telles quelles
 * dans `raw` (cf. `AppContext::raw`).
 *
 * **Story 16.11 Q1.a — `uuid` désormais TOUJOURS posé** : la clé `uuid` était
 * historiquement listée comme passthrough (Story 16.7) mais en pratique
 * jamais posée par `ApplicationScriptsGenerator` avant le `write()`. Depuis
 * Q1.a (2026-05-18), `ApplicationScriptsGenerator::resolveInfo()` injecte
 * systématiquement `uuid` (lowercase normalisé) dans `$info` AVANT l'appel
 * à `write()`. Conséquence : tous les nouveaux payloads `apps.$id` portent
 * la clé `uuid`. Les anciens payloads (cache hit pré-Q1.a) sont migrés
 * automatiquement par `ApplicationScriptsGenerator::fetchCached()` qui
 * ré-écrit le payload avec l'uuid courant si absent.
 *
 * @legacy-port path="sambaedu/includes/applications.inc.php:998 (cache write — historiquement apcu)"
 * @see \App\Services\AppCustomization\CacheAppContextRepository Lecteur (Story 16.15).
 */
final class CacheAppContextWriter implements AppContextWriter
{
    /** @inheritDoc */
    public function write(string $id, array $context, int $ttl = 1800): void
    {
        // Validation md5 stricte — même garde que le lecteur
        // (`CacheAppContextRepository::findById`).
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            Log::channel('gpo')->warning('[CacheAppContextWriter] invalid id format', [
                'id_hash' => substr(hash('sha256', $id), 0, 12),
            ]);
            return;
        }

        // Iso-legacy : clé `apps.$id`, TTL 1800s (cf. `applications.inc.php:998`).
        // Cache::store('app_context') — store dédié avec prefix '' pour interop legacy.
        Cache::store('app_context')->put('apps.' . $id, $context, $ttl);

        Log::channel('gpo')->info('[gpo] gpo.applications.context.put success', [
            'action_type' => 'gpo.applications.context.put',
            'id' => $id,
            'ttl' => $ttl,
            'keys' => array_keys($context),
        ]);
    }

    /** @inheritDoc */
    public function forget(string $id): void
    {
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return;
        }
        Cache::store('app_context')->forget('apps.' . $id);
        Cache::store('app_context')->forget('scripts.' . $id);
    }
}
