<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Services;

use App\Models\Application;
use App\Models\Workstation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @legacy-port path="sambaedu/includes/wpkg_libsql.php"
 * @legacy-port-fn="info_poste_applications"
 * @see _bmad-output/implementation-artifacts/15-2-generators-xml-ini-par-poste.md
 *
 * Story 15.2 — Résout la liste des `package-id` applicables à un poste.
 *
 * Eloquent-only (invariant fort Epic 15) : aucune lecture LDAP/AD en chemin critique.
 * La synchro AD → Eloquent est un job périodique (Story 15.3).
 *
 * Sources unionnées (cf. AC2.2) :
 *   1. AppProfiles rattachés directement au poste → leurs Applications.
 *   2. AppProfiles rattachés aux parcs (WorkstationGroup) du poste → leurs Applications.
 *   3. Applications rattachées directement au poste (équivalent legacy
 *      `applications_profile.type_entite='poste'`).
 *   4. Applications rattachées directement aux parcs (équivalent legacy
 *      `applications_profile.type_entite='parc'`).
 *   5. Dépendances applicatives transitives (BFS sur `Application::dependencies`).
 *
 * Cache key-value `wpkg:packages:{strtolower($hostname)}`, TTL 1000s (parité APCu legacy).
 */
class WorkstationPackagesResolver
{
    /** TTL cache, en secondes (parité legacy APCu, cf. wpkg_libsql.php). */
    public const CACHE_TTL = 1000;

    /** Préfixe clé cache. Toujours suffixé par `strtolower($hostname)`. */
    public const CACHE_KEY_PREFIX = 'wpkg:packages:';

    /**
     * Retourne la collection (string) de `package-id` applicables au poste.
     *
     * Dédupliquée et triée alpha ASC. Hostname inconnu → collection vide.
     *
     * @return Collection<int, string>
     */
    public function resolve(string $hostname): Collection
    {
        $cacheKey = self::cacheKey($hostname);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($hostname): Collection {
            $packages = $this->computePackages($hostname);

            Log::channel('wpkg-deploy')->info('[WorkstationPackagesResolver] cache miss', [
                'hostname' => $hostname,
                'count' => $packages->count(),
            ]);

            return $packages;
        });
    }

    /**
     * Construit la clé cache canonique pour un hostname donné.
     */
    public static function cacheKey(string $hostname): string
    {
        return self::CACHE_KEY_PREFIX . strtolower($hostname);
    }

    /**
     * Calcul Eloquent (4 sources + dépendances transitives + dédup + tri).
     *
     * Les postes, groupes et profils marqués `archived_at` sont
     * **silencieusement ignorés** : on les traite comme des fantômes
     * côté pipeline. Cela évite que des packages zombies remontent dans
     * `profiles.xml` après archivage. Filtre non-breaking : un archivage
     * manuel (UPDATE SQL ou flash card UI) suffit à exclure proprement
     * une entité du déploiement, sans la supprimer ni casser ses pivots.
     *
     * @return Collection<int, string>
     */
    private function computePackages(string $hostname): Collection
    {
        /** @var Workstation|null $workstation */
        $workstation = Workstation::query()
            ->where('name', $hostname)
            ->whereNull('archived_at')
            ->with([
                'appProfiles' => fn ($q) => $q->whereNull('archived_at'),
                'appProfiles.applications:id,app_id',
                'applications:id,app_id',
                'groups' => fn ($q) => $q->whereNull('archived_at'),
                'groups.appProfiles' => fn ($q) => $q->whereNull('archived_at'),
                'groups.appProfiles.applications:id,app_id',
                'groups.applications:id,app_id',
            ])
            ->first();

        if ($workstation === null) {
            return collect();
        }

        $applicationIds = collect();
        $appIds = collect();

        // Source 1 — AppProfiles directs poste
        foreach ($workstation->appProfiles as $profile) {
            foreach ($profile->applications as $app) {
                $applicationIds->push($app->id);
                $appIds->push($app->app_id);
            }
        }

        // Source 3 — Applications directes poste
        foreach ($workstation->applications as $app) {
            $applicationIds->push($app->id);
            $appIds->push($app->app_id);
        }

        // Sources 2 + 4 — via les parcs
        foreach ($workstation->groups as $group) {
            foreach ($group->appProfiles as $profile) {
                foreach ($profile->applications as $app) {
                    $applicationIds->push($app->id);
                    $appIds->push($app->app_id);
                }
            }
            foreach ($group->applications as $app) {
                $applicationIds->push($app->id);
                $appIds->push($app->app_id);
            }
        }

        // Source 5 — Dépendances transitives (BFS).
        $allIds = $this->collectDependenciesTransitive($applicationIds->unique()->all());

        if (! empty($allIds)) {
            $appIdsFromDeps = Application::query()
                ->whereIn('id', $allIds)
                ->pluck('app_id');
            $appIds = $appIds->concat($appIdsFromDeps);
        }

        return $appIds
            ->filter(fn ($v): bool => is_string($v) && $v !== '')
            ->map(fn ($v): string => (string) $v)
            ->unique()
            ->sort(fn (string $a, string $b): int => strcasecmp($a, $b))
            ->values();
    }

    /**
     * BFS des dépendances applicatives transitives.
     *
     * @param  list<int>  $rootIds
     * @return list<int>  IDs union (racines + transitives), dédupliqué.
     */
    private function collectDependenciesTransitive(array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }

        $visited = array_flip($rootIds);
        $queue = $rootIds;

        while ($queue !== []) {
            $batch = $queue;
            $queue = [];

            $childrenByParent = DB::table('application_dependencies')
                ->whereIn('application_id', $batch)
                ->pluck('required_application_id')
                ->all();

            foreach ($childrenByParent as $childId) {
                $childId = (int) $childId;
                if (! array_key_exists($childId, $visited)) {
                    $visited[$childId] = true;
                    $queue[] = $childId;
                }
            }
        }

        return array_keys($visited);
    }
}
