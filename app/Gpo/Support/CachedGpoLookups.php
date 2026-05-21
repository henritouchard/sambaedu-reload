<?php

declare(strict_types=1);

namespace App\Gpo\Support;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Services\GpoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cache portable des appels santé GPO (Story 16.14 — arbitrage Q2 Henri 2026-05-20).
 *
 * Wrappe les appels `GpoService::getLinks()` et `GpoService::get()` (versionNumber)
 * derrière un `Cache::remember()` 24 h. Sans ça, le filtre "Statut santé" et les
 * exports CSV/JSON nécessiteraient N appels samba-tool par render (cap 100 GPOs)
 * ce qui est inacceptable au niveau UX (finding #1 / #23 review opus).
 *
 * **Driver portable** : on n'utilise PAS `Cache::tags()` car les drivers `file`,
 * `database` et `apc` (default Sambaedu) ne supportent pas les tags. À la place,
 * on maintient une clé index `gpo:cache:index` (tableau de GUIDs) qui sert à
 * itérer et invalider par lots dans `forgetAll()`.
 *
 * **Hooks d'invalidation** :
 *  - `GpoService::setLink/removeLink/reorderLinks/setInheritance` → `forgetGpo($guid)`
 *  - `WpkgGpoSynchronizer::publish` → `forgetGpo($guid)` après réussite
 *  - `RoamingProfileService::setExclusions` (applyVersionBump=true) → `forgetAll()`
 *    (legacy ne donne pas le GUID au caller)
 *  - `GenerateWineImageJob::handle()` post-succès → `forgetAll()` (image partagée,
 *    on ne sait pas à quelle GPO précise on a touché)
 *
 * **Warm-up** : `gpo:warm-cache` planifié 22:00 chaque jour repeuple le cache
 * avant la journée admin (cf. `app/Console/Kernel.php`).
 */
class CachedGpoLookups
{
    /** Préfixe des clés de cache liens par GUID. */
    private const KEY_LINKS_PREFIX = 'gpo:links:';

    /** Préfixe des clés de cache versionNumber par GUID. */
    private const KEY_VERSION_PREFIX = 'gpo:version:';

    /** Clé index — liste des GUIDs actuellement présents en cache. */
    private const KEY_INDEX = 'gpo:cache:index';

    /** TTL des entrées cache (24 h). */
    private const TTL_HOURS = 24;

    /**
     * TTL de la clé index — légèrement supérieur au TTL des entrées pour
     * éviter la perte de l'index alors que des entrées sont encore en cache.
     */
    private const INDEX_TTL_HOURS = 25;

    public function __construct(
        private readonly GpoService $svc,
    ) {}

    /**
     * Retourne les containers liés à une GPO (cache 24h).
     *
     * @return list<GpoLink>
     */
    public function getLinksFor(string $guid): array
    {
        $key = self::KEY_LINKS_PREFIX . $guid;

        return Cache::remember($key, Carbon::now()->addHours(self::TTL_HOURS), function () use ($guid): array {
            // GpoService::getLinks(string $containerDn) prend un DN container,
            // pas un GUID. Pour récupérer les containers d'une GPO, on doit
            // d'abord lister les containers via listContainers(GUID), puis
            // retourner directement la liste (le compte = nombre de liens).
            try {
                $containers = $this->svc->listContainers($guid);
                $links = [];
                foreach ($containers as $containerDn) {
                    foreach ($this->svc->getLinks($containerDn) as $link) {
                        if ($link->gpoName === $guid) {
                            $links[] = $link;
                        }
                    }
                }
                $this->registerGuid($guid);
                return $links;
            } catch (Throwable $e) {
                // Best-effort : un échec samba-tool ne doit pas casser le cache.
                // On log et renvoie [] — le filtre santé tombera en orphaned.
                Log::channel('gpo')->warning('[CachedGpoLookups] getLinksFor failed', [
                    'guid' => $guid,
                    'error' => $e->getMessage(),
                ]);
                return [];
            }
        });
    }

    /**
     * Retourne le versionNumber d'une GPO (cache 24h).
     *
     * Renvoie `null` si la GPO est introuvable ou si samba-tool a échoué.
     */
    public function getVersionNumberFor(string $guid): ?int
    {
        $key = self::KEY_VERSION_PREFIX . $guid;

        return Cache::remember($key, Carbon::now()->addHours(self::TTL_HOURS), function () use ($guid): ?int {
            try {
                $summary = $this->svc->get($guid);
                $this->registerGuid($guid);
                return $summary?->versionNumber;
            } catch (Throwable $e) {
                Log::channel('gpo')->warning('[CachedGpoLookups] getVersionNumberFor failed', [
                    'guid' => $guid,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    /**
     * Invalide les 2 clés (links + version) pour une GPO précise.
     *
     * Appelée par les services qui mutent la GPO (setLink, setInheritance,
     * WpkgGpoSynchronizer::publish, etc.).
     */
    public function forgetGpo(string $guid): void
    {
        Cache::forget(self::KEY_LINKS_PREFIX . $guid);
        Cache::forget(self::KEY_VERSION_PREFIX . $guid);
        $this->unregisterGuid($guid);

        // Log de l'invalidation ciblée (action audit Phase 2 — léger).
        try {
            GpoLogger::action('gpo.cache.invalidate', context: ['guid' => $guid, 'scope' => 'single'])->success();
        } catch (Throwable) {
            // log silencieux — l'invalidation ne doit jamais bloquer.
        }
    }

    /**
     * Invalide toutes les entrées cache (utilisé par le bouton "Rafraîchir
     * cache santé" en haut du panneau filtres + commande `--force`).
     */
    public function forgetAll(): void
    {
        $index = $this->loadIndex();
        $count = count($index);

        foreach ($index as $guid) {
            Cache::forget(self::KEY_LINKS_PREFIX . $guid);
            Cache::forget(self::KEY_VERSION_PREFIX . $guid);
        }
        Cache::forget(self::KEY_INDEX);

        try {
            GpoLogger::action('gpo.cache.invalidate', context: ['scope' => 'all', 'count' => $count])->success();
        } catch (Throwable) {
            // log silencieux.
        }
    }

    /**
     * Warm-up batch : itère toutes les GPOs via `GpoService::list()` et
     * pré-remplit le cache pour chacune (links + versionNumber).
     *
     * Retourne un tableau `['count' => N, 'duration_ms' => ms, 'errors' => [...]]`.
     *
     * @return array{count:int, duration_ms:int, errors:list<string>}
     */
    public function warmAll(): array
    {
        $start = microtime(true);
        $count = 0;
        $errors = [];

        try {
            $gpos = $this->svc->list();
        } catch (Throwable $e) {
            return [
                'count' => 0,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'errors' => ['list() failed: ' . $e->getMessage()],
            ];
        }

        foreach ($gpos as $gpo) {
            $guid = $gpo->name;
            try {
                $this->getLinksFor($guid);
                $this->getVersionNumberFor($guid);
                $count++;
            } catch (Throwable $e) {
                $errors[] = sprintf('%s: %s', $guid, $e->getMessage());
            }
        }

        return [
            'count' => $count,
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            'errors' => $errors,
        ];
    }

    /**
     * Ajoute un GUID à l'index si absent.
     */
    private function registerGuid(string $guid): void
    {
        $index = $this->loadIndex();
        if (!in_array($guid, $index, true)) {
            $index[] = $guid;
            Cache::put(self::KEY_INDEX, $index, Carbon::now()->addHours(self::INDEX_TTL_HOURS));
        }
    }

    /**
     * Retire un GUID de l'index.
     */
    private function unregisterGuid(string $guid): void
    {
        $index = $this->loadIndex();
        $filtered = array_values(array_filter($index, fn($g) => $g !== $guid));
        if (count($filtered) !== count($index)) {
            Cache::put(self::KEY_INDEX, $filtered, Carbon::now()->addHours(self::INDEX_TTL_HOURS));
        }
    }

    /**
     * Charge l'index (liste des GUIDs cachés).
     *
     * @return list<string>
     */
    private function loadIndex(): array
    {
        $raw = Cache::get(self::KEY_INDEX, []);
        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
    }
}
