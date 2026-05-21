<?php

declare(strict_types=1);

namespace App\Services\AppCustomization;

use App\Dto\AppCustomization\AppContext;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Implémentation Cache (Laravel) du contexte AppCustomization.
 *
 * Lit `Cache::store('app_context')->get("apps.$id")` — clé posée par
 * `CacheAppContextWriter` (Story 16.15) iso-legacy `applications.inc.php`
 * (TTL 1800s). La garantie d'interop legacy (`prefix => ''` + driver `apc`)
 * vit côté `config/cache.php` et `CacheAppContextWriter` — voir ces deux endroits.
 *
 * Story 16.15 — migration cache APCu vers la facade Laravel (AC2). Dégradation
 * gracieuse si store indisponible : `findById` retourne `null`, le controller
 * retournera 404.
 *
 * @see \App\Services\AppCustomization\CacheAppContextWriter Écrivain (Story 16.15).
 * @legacy-port path="sambaedu/includes/applications.inc.php:998 (cache read — historiquement apcu)"
 */
class CacheAppContextRepository implements AppContextRepository
{
    public function findById(string $id): ?AppContext
    {
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return null;
        }

        $payload = Cache::store('app_context')->get('apps.' . $id);
        if (! is_array($payload)) {
            return null;
        }

        return AppContext::fromApcuArray($payload);
    }
}
