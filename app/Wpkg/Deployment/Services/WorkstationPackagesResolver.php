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
     * Calcul Eloquent (4 sources + dépendances transitives + dédup + tri),
     * **SANS aucun cache** — le `Cache::remember` reste exclusivement dans
     * {@see resolve()}.
     *
     * **PUBLIC pour le canal agent (Story 27.5, NFR7 — critère Keycloak).** Le
     * provider d'état `ApplicationsStateProvider` projette l'ensemble cible WPKG
     * en items d'état : il DOIT lire la résolution NON CACHÉE (un provider ne
     * touche jamais l'APCu — interdit). C'est la SEULE source de vérité sur « ce
     * que WPKG va installer » (union 4 sources + BFS de dépendances) ; la
     * réimplémenter dans le provider divergerait de WPKG réel. Eloquent-pur :
     * aucun LDAP/AD, aucun cache.
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
    public function computePackages(string $hostname): Collection
    {
        // Story 37.1 — `computePackages()` est désormais ré-exprimée sur
        // {@see explainPackages()} : les CLÉS de la map d'origines sont exactement
        // l'ensemble cible des `app_id`. Sortie BYTE-IDENTIQUE au comportement
        // historique (dédup implicite par les clés d'array + tri alpha `strcasecmp`),
        // garantie par l'invariant testé `array_keys(explainPackages($h)) ==
        // computePackages($h)->all()`. Le refactor n'ajoute AUCUNE app_id et n'en
        // retire aucune : `explainPackages()` reproduit la même union (4 sources) +
        // BFS de dépendances, en ATTRIBUANT en plus une provenance par app_id (pour
        // le chemin de CONSULTATION UI, jamais le chemin agent).
        return collect(array_keys($this->explainPackages($hostname)))
            ->map(fn ($v): string => (string) $v)
            ->sort(fn (string $a, string $b): int => strcasecmp($a, $b))
            ->values();
    }

    /**
     * Story 37.1 — Résout l'ensemble cible WPKG d'un poste EN ATTRIBUANT une
     * provenance à chaque `app_id` : `app_id => list<origin>` où chaque origine
     * est `{source: workstation|group|dependency, group_id?, profile_id?,
     * via_app_id?}`.
     *
     * Même union que {@see computePackages()} (single source of truth) — 4 sources
     * (profils/apps × poste/groupes) + BFS de dépendances transitives — SANS aucun
     * cache (NON CACHÉE, comme `computePackages()` ; le `Cache::remember` reste
     * exclusif à {@see resolve()}). C'est le chemin de CONSULTATION parallèle
     * réutilisé par {@see \App\Services\Agent\Reporting\DesiredStateOriginService}
     * pour peindre les badges d'origine — le pipeline agent
     * ({@see \App\Services\Agent\Providers\ApplicationsStateProvider}) reste sur
     * `computePackages()` (enveloppe 4-clés inchangée).
     *
     * Un même `app_id` peut porter PLUSIEURS origines (app présente en direct ET
     * via un parc, ou tirée aussi comme dépendance) — c'est l'intérêt de la vue :
     * le compilateur (aggregate) dédup par contenu et l'origine disparaît, ici on
     * l'agrège.
     *
     * INVARIANT (Story 37.1, AC3/AC5) : `array_keys()` de la valeur retournée,
     * triées `strcasecmp`, EST l'ensemble retourné par `computePackages()` (clés
     * ordonnées ici par `uksort`/`strcasecmp` pour que l'égalité tienne SANS
     * re-tri). Les postes/groupes/profils `archived_at` sont ignorés (fantômes),
     * strictement comme `computePackages()` historique.
     *
     * @return array<string, list<array{source:string, group_id?:int, profile_id?:int, via_app_id?:?string}>>
     */
    public function explainPackages(string $hostname): array
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
            return [];
        }

        /** @var array<string, list<array{source:string, group_id?:int, profile_id?:int, via_app_id?:?string}>> $origins */
        $origins = [];
        // Application PK => app_id des SOURCES DIRECTES (racines du BFS). On y met
        // TOUTES les racines (app_id vide compris) : le BFS historique fait le
        // closure sur les PK, indépendamment de l'app_id — on reproduit ce périmètre.
        $rootPk = [];

        $record = function (string $appId, array $origin) use (&$origins): void {
            // Filtre iso `computePackages()` (l'ancien `filter(is_string && !== '')`) :
            // un app_id vide n'entre jamais dans l'ensemble cible.
            if ($appId === '') {
                return;
            }
            $origins[$appId][] = $origin;
        };

        // Source 1 — AppProfiles directs poste.
        foreach ($workstation->appProfiles as $profile) {
            foreach ($profile->applications as $app) {
                $appId = (string) $app->app_id;
                $rootPk[(int) $app->id] = $appId;
                $record($appId, ['source' => 'workstation', 'profile_id' => (int) $profile->id]);
            }
        }

        // Source 3 — Applications directes poste.
        foreach ($workstation->applications as $app) {
            $appId = (string) $app->app_id;
            $rootPk[(int) $app->id] = $appId;
            $record($appId, ['source' => 'workstation']);
        }

        // Sources 2 + 4 — via les parcs.
        foreach ($workstation->groups as $group) {
            foreach ($group->appProfiles as $profile) {
                foreach ($profile->applications as $app) {
                    $appId = (string) $app->app_id;
                    $rootPk[(int) $app->id] = $appId;
                    $record($appId, ['source' => 'group', 'group_id' => (int) $group->id, 'profile_id' => (int) $profile->id]);
                }
            }
            foreach ($group->applications as $app) {
                $appId = (string) $app->app_id;
                $rootPk[(int) $app->id] = $appId;
                $record($appId, ['source' => 'group', 'group_id' => (int) $group->id]);
            }
        }

        // Source 5 — Dépendances transitives (BFS avec suivi du parent découvreur).
        $parents = $this->collectDependencyParents(array_keys($rootPk));

        $transitivePks = [];
        foreach ($parents as $pk => $parentPk) {
            if (! array_key_exists($pk, $rootPk)) {
                $transitivePks[$pk] = $parentPk;
            }
        }

        if ($transitivePks !== []) {
            $rows = Application::query()
                ->whereIn('id', array_keys($transitivePks))
                ->get(['id', 'app_id']);

            // PK → app_id pour résoudre l'app_id d'une dépendance ET l'app_id de son
            // parent (racine OU dépendance intermédiaire).
            $pkToAppId = $rootPk;
            foreach ($rows as $r) {
                $pkToAppId[(int) $r->id] = (string) $r->app_id;
            }

            foreach ($transitivePks as $pk => $parentPk) {
                $appId = $pkToAppId[$pk] ?? '';
                $viaAppId = $parentPk !== null ? ($pkToAppId[$parentPk] ?? null) : null;
                // Une dépendance à app_id vide est skippée (comme les racines vides).
                $record($appId, ['source' => 'dependency', 'via_app_id' => $viaAppId !== '' ? $viaAppId : null]);
            }
        }

        // Clés triées alpha (insensible casse) — pour que l'invariant
        // `array_keys(explainPackages) == computePackages->all()` tienne sans re-tri.
        uksort($origins, fn ($a, $b): int => strcasecmp((string) $a, (string) $b));

        return $origins;
    }

    /**
     * BFS des dépendances applicatives transitives, en enregistrant pour chaque
     * PK visité son PARENT découvreur (racines : parent `null`). Même parcours et
     * même ENSEMBLE visité que l'ancien `collectDependenciesTransitive()` (roots
     * pré-marquées, chaque enfant ajouté une seule fois) — on récupère en plus la
     * colonne `application_id` pour attribuer « Dépendance de <app parente> ».
     *
     * @param  list<int>  $rootIds
     * @return array<int,int|null>  PK visité → PK parent (null pour une racine)
     */
    private function collectDependencyParents(array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }

        $parent = [];
        foreach ($rootIds as $pk) {
            $parent[(int) $pk] = null;
        }

        $queue = array_map('intval', $rootIds);

        while ($queue !== []) {
            $batch = $queue;
            $queue = [];

            // Story 37.1 (review #3) — ORDER BY déterministe : si une dépendance a
            // ≥ 2 parents dans le MÊME batch BFS, le parent attribué (« Dépendance de
            // X ») ne doit pas dépendre du plan SQL. Le tri par `application_id`
            // (PK parente) fait gagner la PLUS PETITE PK parente — tiebreak stable,
            // purement cosmétique (l'ENSEMBLE des app_id / l'invariant AC3 est
            // inchangé, seul le libellé du tooltip est stabilisé).
            $rows = DB::table('application_dependencies')
                ->whereIn('application_id', $batch)
                ->orderBy('application_id')
                ->get(['application_id', 'required_application_id']);

            foreach ($rows as $row) {
                $childId = (int) $row->required_application_id;
                if (! array_key_exists($childId, $parent)) {
                    $parent[$childId] = (int) $row->application_id;
                    $queue[] = $childId;
                }
            }
        }

        return $parent;
    }
}
