<?php

declare(strict_types=1);

namespace App\Services\AppCustomization;

use App\Services\AppCustomization\Contracts\AppContextWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pendant écriture de `CacheAppContextRepository`.
 *
 * (origine)..
 *
 * Écrit la clé `apps.$id` via `Cache::store('app_context')` — store dédié
 * avec `prefix => ''` (interop legacy : le shim `LegacyBootstrapTokenValidator`
 * continue de lire la clé brute en direct via la couche bas-niveau, hors-scope
 * D11, et accède donc à la même donnée). Clé consommée par les endpoints
 * natifs runtime déjà portés :
 *
 * - `wallpaper_out.php` → (`WallpaperController::legacyOut`)
 * - `firefox_out.php` → (`AppPolicyController::legacyFirefoxOut`)
 * - `thunderbird_out.php`→ (`AppPolicyController::legacyThunderbirdOut`)
 * - `network_out.php` → (`NetworkOutController`)
 * - `veyon_out.php` → (`VeyonOutController`)
 * - `associations_out.php` → (`AssociationsOutController`)
 *
 * **Structure attendue par `AppContext::fromApcuArray`** :
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
 * **La clé `uuid` est toujours posée** : `ApplicationScriptsGenerator::resolveInfo()`
 * injecte systématiquement `uuid` (normalisé en minuscules) dans `$info` avant
 * l'appel à `write()`, si bien que tout nouveau payload `apps.$id` la porte. Les
 * payloads plus anciens, écrits quand `uuid` n'était qu'un passthrough facultatif,
 * sont migrés à la lecture par `ApplicationScriptsGenerator::fetchCached()`, qui
 * ré-écrit le payload avec l'uuid courant lorsque la clé manque.
 *
 * @legacy-port path="sambaedu/includes/applications.inc.php:998 (cache write — historiquement apcu)"
 * @see \App\Services\AppCustomization\CacheAppContextRepository Lecteur.
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
