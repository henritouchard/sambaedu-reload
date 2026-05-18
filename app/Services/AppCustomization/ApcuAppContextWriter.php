<?php

declare(strict_types=1);

namespace App\Services\AppCustomization;

use App\Services\AppCustomization\Contracts\AppContextWriter;
use Illuminate\Support\Facades\Log;

/**
 * Pendant écriture de `ApcuAppContextRepository` (Story 4.8).
 *
 * Story 16.7 — AC2.2.
 *
 * Écrit la clé `apps.$id` consommée par les endpoints natifs runtime déjà
 * portés :
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
 * Dégradation gracieuse : si APCu indisponible (CLI sans extension), log
 * warning et no-op (parité iso-legacy `apcu_store()` qui retourne `false`).
 *
 * @legacy-port path="sambaedu/includes/applications.inc.php:998 (apcu_store)"
 * @see \App\Services\AppCustomization\ApcuAppContextRepository Lecteur (Story 4.8).
 */
final class ApcuAppContextWriter implements AppContextWriter
{
    /** @inheritDoc */
    public function write(string $id, array $context, int $ttl = 1800): void
    {
        // Validation md5 stricte — même garde que le lecteur 4.8
        // (`ApcuAppContextRepository::findById` :24).
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            Log::channel('gpo')->warning('[ApcuAppContextWriter] invalid id format', [
                'id_hash' => substr(hash('sha256', $id), 0, 12),
            ]);
            return;
        }

        if (! $this->apcuAvailable()) {
            Log::channel('gpo')->warning('[ApcuAppContextWriter] APCu unavailable, context not persisted', [
                'id' => $id,
            ]);
            return;
        }

        // Iso-legacy : clé `apps.$id`, TTL 1800s (cf. `applications.inc.php:998`).
        $ok = apcu_store('apps.' . $id, $context, $ttl);
        if ($ok !== true) {
            Log::channel('gpo')->warning('[ApcuAppContextWriter] apcu_store returned false', [
                'id' => $id,
                'ttl' => $ttl,
            ]);
            return;
        }

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
        if (! $this->apcuAvailable()) {
            return;
        }
        @apcu_delete('apps.' . $id);
        @apcu_delete('scripts.' . $id);
    }

    private function apcuAvailable(): bool
    {
        return function_exists('apcu_store')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }
}
