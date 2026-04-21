<?php

declare(strict_types=1);

namespace App\Services\AppCustomization;

use App\Dto\AppCustomization\AppContext;
use App\Services\AppCustomization\Contracts\AppContextRepository;

/**
 * Implémentation APCu du contexte AppCustomization.
 *
 * Lit `apcu_fetch("apps.$id")` — clé posée par le legacy
 * `applications.inc.php::get_apps()` (TTL 1800s).
 *
 * Story 4.8 — AC 9. Extension APCu requise (cf. mémoire `apcu_risk.md`) —
 * dégradation gracieuse si absente : `findById` retourne `null`, le controller
 * retournera 404.
 */
class ApcuAppContextRepository implements AppContextRepository
{
    public function findById(string $id): ?AppContext
    {
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return null;
        }

        if (! function_exists('apcu_fetch') || ! function_exists('apcu_enabled') || ! apcu_enabled()) {
            return null;
        }

        $success = false;
        $payload = apcu_fetch('apps.' . $id, $success);

        if (! $success || ! is_array($payload)) {
            return null;
        }

        return AppContext::fromApcuArray($payload);
    }
}
