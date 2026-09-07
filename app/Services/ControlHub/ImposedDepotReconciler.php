<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Models\Application;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Services\AppStore\AppStoreService;
use App\Services\ControlHub\Data\ImposedDepotReconciliationResult;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Réconciliation « désir d'état » du dépôt IMPOSÉ par le contrat amont
 * (controlHub) : bascule EXCLUSIVE du canal dépôts.
 *
 * Quand un contrat amont **actif** porte un catalogue applicatif NON vide, ce service :
 *  1. MATÉRIALISE un dépôt imposé unique (`is_imposed`, `is_primary`) — projection
 *     table→table du catalogue (`controlhub_contract_catalog_apps` → `depot_applications`),
 *     ZÉRO HTTP, ZÉRO parsing XML (à la différence de {@see \App\Services\AppStore\DepotSyncService}) ;
 *  2. TRANSFÈRE les apps communes (dépôt non imposé + `app_id` ∈ catalogue) vers le
 *     dépôt imposé — re-pointage `depot_id` SEUL, aucune désinstall/réinstall ;
 *  3. DÉSINSTALLE en cascade les apps hors-catalogue (dépôt non imposé + `app_id` ∉
 *     catalogue) via {@see AppStoreService::deleteApplication()} (RÉUTILISÉ, jamais dupliqué) ;
 *  4. SUPPRIME réellement les anciens dépôts non imposés (`Depot::delete()`).
 *
 * **L'ordre transfert → désinstallation → suppression est un INVARIANT** :
 * `applications.depot_id` est en `onDelete('cascade')` — supprimer un dépôt AVANT le
 * transfert détruirait en cascade des apps encore installées ET leurs pivots, sans la
 * cascade propre (fichiers, regen, invalidation cache).
 *
 * Patron STRUCTUREL {@see ImposedWorkstationGroupReconciler} :
 *  - court-circuit standalone (sans contrat actif → no-op total ; catalogue vide → no-op
 *    bascule — le verrou d'AJOUT, lui, suit le lien et vit dans le SFC/DepotSyncService) ;
 *  - DTO résultat avec compteurs ; try/catch résilient par app/dépôt ;
 *  - commande jumelle {@see \App\Console\Commands\ReconcileImposedDepot} pour re-jeu.
 *
 * ⚠️ Apps `depot_id IS NULL` INTOUCHÉES : matérialisées amont
 * (`managed_by_control_hub`) ET apps locales sans dépôt — le filtre est
 * « `depot_id` ∈ dépôts NON imposés », jamais « `app_id` ∉ catalogue » seul.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » ; vocabulaire « imposé » / « amont » /
 * `Imposed` / `Upstream`.
 */
class ImposedDepotReconciler
{
    /** Nom canonique unique du dépôt imposé. */
    public const IMPOSED_DEPOT_NAME = 'ControlHub';

    /** URL du dépôt imposé : marqueur non joignable (jamais synchronisé en HTTP). */
    public const IMPOSED_DEPOT_URL = 'controlhub://managed';

    public function __construct(
        private readonly AppStoreService $appStoreService,
    ) {
    }

    /**
     * Réconcilie le dépôt imposé avec le catalogue applicatif du contrat amont actif.
     *
     * @return ImposedDepotReconciliationResult Compteurs + erreurs.
     */
    public function reconcile(): ImposedDepotReconciliationResult
    {
        $result = new ImposedDepotReconciliationResult();

        // Standalone : sans contrat amont actif, no-op TOTAL (≤ 1 requête
        // controlhub_contracts). Aucune autre table lue, aucun dépôt touché.
        $contract = ControlHubContract::active();
        if ($contract === null) {
            return $result;
        }

        $catalogApps = $contract->catalogApps()->get();

        // Catalogue VIDE = PAS de bascule (« l'autorité
        // n'a pas encore défini de catalogue » ≠ « catalogue vide autoritaire »). AUCUNE
        // matérialisation, AUCUN transfert, AUCUNE désinstallation, AUCUNE suppression.
        // Seul le verrou d'AJOUT (suit le lien) reste actif — hors de ce service.
        if ($catalogApps->isEmpty()) {
            return $result;
        }

        // ORDRE INVARIANT : transfert → désinstallation → suppression.

        // Étape 0 — Matérialisation du dépôt imposé (point d'entrée unique).
        // Voie bascule = catalogue NON vide (garde ci-dessus) → promotion is_primary.
        $imposedDepot = self::getOrCreateImposedDepot(promote: true);

        // Étape 0bis — Projection JSON du catalogue → depot_applications (upsert + purge,
        // table→table, aucun Http::get, aucun DOMDocument).
        $catalogAppIds = $catalogApps->pluck('app_key')->all();
        $this->projectCatalog($imposedDepot, $catalogApps, $result);

        // Étape 1 — TRANSFERT des apps communes (AVANT toute suppression de dépôt).
        $this->transferCommonApplications($imposedDepot, $catalogAppIds, $result);

        // Étape 2 — DÉSINSTALLATION en cascade des apps hors-catalogue.
        $this->uninstallOutOfCatalogApplications($imposedDepot, $catalogAppIds, $result);

        // Étape 3 — SUPPRESSION des anciens dépôts non imposés (après transfert +
        // désinstall ; un dépôt encore référencé — app en échec — est CONSERVÉ).
        $this->deleteObsoleteDepots($imposedDepot, $result);

        Log::info('[ImposedDepotReconciler] Réconciliation du dépôt imposé terminée', [
            'contract_id' => $contract->id,
            'imposed_depot_id' => $imposedDepot->id,
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    /**
     * Point d'entrée UNIQUE de définition du dépôt imposé. Idempotent
     * (`updateOrCreate` par nom canonique).
     *
     * ⚠️ `$promote` (défaut false) : promouvoir le dépôt imposé en `is_primary` alors
     * que le catalogue amont est VIDE détourne `getDefaultDepot()` vers un dépôt sans
     * application. Seule la réconciliation sur catalogue NON vide promeut ; tout autre
     * appelant garantit l'existence + le marqueur `is_imposed`, rien de plus.
     */
    public static function getOrCreateImposedDepot(bool $promote = false): Depot
    {
        $depot = Depot::firstOrCreate(
            ['name' => self::IMPOSED_DEPOT_NAME],
            [
                'url' => self::IMPOSED_DEPOT_URL,
                'is_imposed' => true,
                'is_primary' => $promote,
                'is_active' => true,
            ],
        );

        // Réparation / promotion idempotente (le dépôt a pu être créé par un chemin
        // antérieur sans les flags canoniques). La promotion `is_primary` n'est appliquée
        // QUE sur la voie bascule (`$promote`), jamais depuis WGSync (fenêtre).
        $attributes = ['url' => self::IMPOSED_DEPOT_URL, 'is_imposed' => true, 'is_active' => true];
        if ($promote) {
            $attributes['is_primary'] = true;
        }
        $depot->fill($attributes);
        if ($depot->isDirty()) {
            $depot->save();
        }

        return $depot;
    }

    /**
     * Projette `controlhub_contract_catalog_apps` → `depot_applications` du dépôt
     * imposé (upsert par clé `(depot_id, app_id)`, branche `stable`) PUIS purge les
     * entrées absentes du catalogue. Transposition table→table du patron
     * {@see \App\Services\AppStore\DepotSyncService::parseAndUpsertApplications()} —
     * SANS le fetch HTTP ni le DOMDocument.
     *
     * @param  \Illuminate\Support\Collection<int, ControlHubContractCatalogApp>  $catalogApps
     */
    private function projectCatalog(
        Depot $imposedDepot,
        \Illuminate\Support\Collection $catalogApps,
        ImposedDepotReconciliationResult $result,
    ): void {
        $seenAppIds = [];

        foreach ($catalogApps as $catalogApp) {
            $appId = (string) $catalogApp->app_key;

            // Champs de contenu (hors `last_checked_at`, volatil) : servent au diff
            // D'idempotence ( : re-jeu sur état convergé = compteurs à zéro, aucune
            // écriture — le patron ImposedWorkstationGroupReconciler garde sur isDirty).
            $data = [
                'name' => ($catalogApp->display_name !== null && $catalogApp->display_name !== '')
                    ? (string) $catalogApp->display_name
                    : $appId,
                'version' => $catalogApp->version,
                'category' => $catalogApp->category,
                'icon_url' => $catalogApp->icon_url,
                'xml_url' => $catalogApp->source_xml_url,
                'xml_sha' => $catalogApp->source_xml_sha,
                'branch' => 'stable',
            ];

            $existing = DepotApplication::query()
                ->where('depot_id', $imposedDepot->id)
                ->where('app_id', $appId)
                ->first();

            if ($existing !== null) {
                $existing->fill($data);

                // No-op strict : aucun contenu modifié ⇒ aucune écriture, aucun
                // compteur (le seul `last_checked_at` ne compte pas comme un changement).
                if ($existing->isDirty()) {
                    $existing->last_checked_at = now();
                    $existing->save();
                    $result->materialized++;
                }
            } else {
                DepotApplication::create(array_merge($data, [
                    'depot_id' => $imposedDepot->id,
                    'app_id' => $appId,
                    'last_checked_at' => now(),
                ]));
                $result->materialized++;
            }

            $seenAppIds[] = $appId;
        }

        // Purge des depot_applications du dépôt imposé absentes du catalogue (miroir de
        // la purge DepotSyncService, mais table→table). Le catalogue étant non vide
        // (garde en amont), $seenAppIds n'est jamais vide.
        $toPurge = DepotApplication::query()
            ->where('depot_id', $imposedDepot->id)
            ->whereNotIn('app_id', $seenAppIds)
            ->get(['id']);

        $purged = $toPurge->count();
        if ($purged > 0) {
            DepotApplication::query()->whereIn('id', $toPurge->pluck('id'))->delete();
        }
        $result->purged += $purged;
    }

    /**
     * TRANSFERT des apps communes : toute `Application` dont `depot_id` pointe un
     * dépôt NON imposé ET dont `app_id` ∈ catalogue amont voit son `depot_id` re-pointé
     * sur le dépôt imposé. RIEN d'autre ne change (status, installed_version, fichiers,
     * pivots profils/parcs/postes intacts). Pas de réalignement de recette (report).
     *
     * ⚠️ Piège d'unicité `unique(depot_id, app_id)` : si une app du MÊME `app_id` existe
     * déjà sur le dépôt imposé (doublon inter-dépôts — dépôt miroir/redondant), le
     * re-pointage violerait la contrainte. Le doublon étant REDONDANT (l'app est déjà
     * représentée sur le dépôt imposé via la 1ʳᵉ ligne), il est DÉTRUIT en cascade
     * afin de libérer son dépôt d'origine. NB : le
     * re-jeu convergé ne repasse jamais ici (les apps déjà sur le dépôt imposé sont
     * exclues par la requête ci-dessous), donc ce chemin est PUREMENT le cas doublon.
     *
     * @param  array<int, string>  $catalogAppIds
     */
    private function transferCommonApplications(
        Depot $imposedDepot,
        array $catalogAppIds,
        ImposedDepotReconciliationResult $result,
    ): void {
        // Apps sur un dépôt NON imposé (donc depot_id NON NULL : les apps depot_id NULL
        // sont INTOUCHÉES) ET dans le catalogue amont.
        $commonApps = Application::query()
            ->whereNotNull('depot_id')
            ->where('depot_id', '!=', $imposedDepot->id)
            ->whereIn('app_id', $catalogAppIds)
            ->get();

        foreach ($commonApps as $app) {
            try {
                // Doublon inter-dépôts : le même app_id est déjà représenté sur le dépôt
                // imposé (miroir/dépôt redondant). Le re-pointage violerait l'unicité →
                // on DÉTRUIT le doublon redondant (cascade propre + invalidation cache) au
                // lieu de le laisser bloquer la suppression de son dépôt.
                $collision = Application::query()
                    ->where('depot_id', $imposedDepot->id)
                    ->where('app_id', $app->app_id)
                    ->exists();

                if ($collision) {
                    $this->purgeApplication($app);
                    $result->duplicatesRemoved++;
                    Log::warning('[ImposedDepotReconciler] Doublon d\'app_id détruit (déjà présent sur le dépôt imposé)', [
                        'app_id' => $app->app_id,
                        'origin_depot_id' => $app->depot_id,
                    ]);

                    continue;
                }

                $app->update(['depot_id' => $imposedDepot->id]);
                $result->transferred++;
            } catch (Throwable $e) {
                $result->failed++;
                $result->errors[] = "Transfert '{$app->app_id}': ".$e->getMessage();
                Log::error('[ImposedDepotReconciler] Échec du transfert d\'une app commune', [
                    'app_id' => $app->app_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * DÉSINSTALLATION en cascade des apps
     * hors-catalogue (opération DESTRUCTIVE de masse). Toute `Application` dont `app_id` ∉
     * catalogue amont ET dont `depot_id` est NON NULL → {@see AppStoreService::deleteApplication()}
     * (RÉUTILISÉ) — Y COMPRIS si elle est portée par le dépôt IMPOSÉ.
     *
     * ⚠️ Convergence stricte : une app transférée sur le dépôt imposé à une version
     * antérieure du catalogue, puis RETIRÉE du catalogue, doit être désinstallée — pas
     * seulement voir sa `depot_application` purgée. Ne cibler que les dépôts NON imposés
     * laisserait ces apps installées à jamais, alors que le parc doit converger
     * STRICTEMENT vers ce que l'autorité autorise. Les apps du catalogue (transférées à
     * l'étape 1) portent un `app_id` ∈ catalogue → EXCLUES ici.
     *
     * ⚠️ `whereNotNull('depot_id')` exclut les apps `depot_id NULL`
     * (matérialisées amont `managed_by_control_hub` + locales sans dépôt) — INTOUCHÉES.
     *
     * ⚠️ Invalidation du cache par-poste : voir {@see self::purgeApplication()}.
     *
     * Résilience par app : une désinstallation en échec n'interrompt NI les autres
     * apps NI la suite (compteur `failed`) — et son dépôt d'origine sera CONSERVÉ (l'app
     * reste référencée, cf. {@see self::deleteObsoleteDepots()}).
     *
     * @param  array<int, string>  $catalogAppIds
     */
    private function uninstallOutOfCatalogApplications(
        Depot $imposedDepot,
        array $catalogAppIds,
        ImposedDepotReconciliationResult $result,
    ): void {
        $outOfCatalog = Application::query()
            ->whereNotNull('depot_id')
            ->whereNotIn('app_id', $catalogAppIds)
            ->get();

        foreach ($outOfCatalog as $app) {
            $appId = $app->app_id;
            $originDepotId = $app->depot_id;

            try {
                $affected = $this->purgeApplication($app);

                $result->uninstalled++;
                Log::info('[ImposedDepotReconciler] App hors-catalogue désinstallée (cascade)', [
                    'app_id' => $appId,
                    'origin_depot_id' => $originDepotId,
                    'affected_hostnames' => $affected,
                ]);
            } catch (Throwable $e) {
                $result->failed++;
                $result->errors[] = "Désinstallation '{$appId}': ".$e->getMessage();
                Log::error('[ImposedDepotReconciler] Échec de désinstallation d\'une app hors-catalogue', [
                    'app_id' => $appId,
                    'origin_depot_id' => $originDepotId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * SUPPRESSION réelle (`Depot::delete`, pas le soft `is_active=false`
     * de l'UI) des dépôts NON imposés, APRÈS transfert + désinstallation. Un dépôt
     * encore RÉFÉRENCÉ par une `Application` (désinstallation en échec) est CONSERVÉ :
     * le supprimer déclencherait la cascade FK `onDelete('cascade')` qui détruirait l'app
     * en échec et ses pivots sans la cascade propre.
     *
     * Les `depot_applications` des anciens dépôts partent par leur propre cascade FK.
     */
    private function deleteObsoleteDepots(
        Depot $imposedDepot,
        ImposedDepotReconciliationResult $result,
    ): void {
        $obsoleteDepots = Depot::query()
            ->where('id', '!=', $imposedDepot->id)
            ->where('is_imposed', false)
            ->get();

        foreach ($obsoleteDepots as $depot) {
            try {
                // Dépôt encore référencé (app en échec de désinstall) ⇒ conservé.
                $stillReferenced = Application::query()->where('depot_id', $depot->id)->exists();
                if ($stillReferenced) {
                    Log::warning('[ImposedDepotReconciler] Dépôt non imposé conservé (encore référencé par une app)', [
                        'depot_id' => $depot->id,
                        'depot_name' => $depot->name,
                    ]);

                    continue;
                }

                $depot->delete();
                $result->depotsDeleted++;
                Log::info('[ImposedDepotReconciler] Ancien dépôt non imposé supprimé', [
                    'depot_id' => $depot->id,
                    'depot_name' => $depot->name,
                ]);
            } catch (Throwable $e) {
                $result->failed++;
                $result->errors[] = "Suppression dépôt '{$depot->name}': ".$e->getMessage();
                Log::error('[ImposedDepotReconciler] Échec de suppression d\'un ancien dépôt', [
                    'depot_id' => $depot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Désinstallation en cascade CANONIQUE d'une `Application` + invalidation du cache
     * par-poste. RÉUTILISE {@see AppStoreService::deleteApplication()} (fichiers
     * + 6 familles de pivots/enregistrements + delete + regen packages.xml/bundle).
     *
     * ⚠️ Les hostnames affectés sont collectés AVANT le detach Eloquent (qui n'émet PAS
     * `WorkstationGroupApplicationsChanged` → pas d'invalidation automatique), puis leurs
     * entrées de cache sont oubliées (mécanique {@see \App\Wpkg\Deployment\Listeners\InvalidateWorkstationPackagesCache}).
     *
     * Partagé par la désinstallation hors-catalogue ET la destruction d'un doublon
     * inter-dépôts. Propage toute exception au caller (résilience).
     *
     * @return int Nombre de postes dont le cache a été invalidé.
     */
    private function purgeApplication(Application $app): int
    {
        $hostnames = $this->hostnamesAffectedBy($app);

        $this->appStoreService->deleteApplication($app);

        foreach ($hostnames as $hostname) {
            Cache::forget(WorkstationPackagesResolver::cacheKey($hostname));
        }

        return count($hostnames);
    }

    /**
     * Postes affectés par une désinstallation d'app : union des postes atteints par
     * TOUTES les voies que {@see WorkstationPackagesResolver} agrège et que
     * {@see AppStoreService::deleteApplication()} détache :
     *  - assignation DIRECTE poste (`application_workstation`) ;
     *  - assignation DIRECTE parc (`application_workstation_group`) ;
     *  - assignation via PROFIL applicatif (`app_profile_application`) — un profil pointe
     *    des postes ET des parcs (`groups.appProfiles.applications` dans le resolver).
     *    ⚠️ Oublier cette voie laisserait un `profiles.xml` caché listant l'app supprimée
     *    jusqu'à expiration du cache (CACHE_TTL) sur les postes servis UNIQUEMENT par
     *    profil.
     *
     * Collecté AVANT le detach. Mécanique alignée sur
     * {@see \App\Wpkg\Deployment\Listeners\InvalidateWorkstationPackagesCache::hostnamesForAppProfile()}.
     *
     * @return list<string>
     */
    private function hostnamesAffectedBy(Application $app): array
    {
        $names = $app->workstations()->pluck('name');

        foreach ($app->workstationGroups()->with('workstations:id,name')->get() as $group) {
            $names = $names->concat($group->workstations->pluck('name'));
        }

        // Voie profil applicatif : postes directs du profil + postes des parcs du profil.
        $profiles = $app->appProfiles()
            ->with(['workstations:id,name', 'workstationGroups.workstations:id,name'])
            ->get();

        foreach ($profiles as $profile) {
            $names = $names->concat($profile->workstations->pluck('name'));

            foreach ($profile->workstationGroups as $group) {
                $names = $names->concat($group->workstations->pluck('name'));
            }
        }

        return $names
            ->filter(fn ($v): bool => is_string($v) && $v !== '')
            ->unique()
            ->values()
            ->all();
    }
}
