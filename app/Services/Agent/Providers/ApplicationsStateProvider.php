<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Application;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Type `applications` (contrat §7, identifiant DÉJÀ figé — NFR12,
 * {@see Application::TYPE_APPLICATIONS}) — projection en LECTURE SEULE de
 * l'ensemble cible des applications WPKG d'un poste vers des candidats d'état
 * (Story 27.5, AC1).
 *
 * **« Un tuyau, deux outils ».** L'agent unifie le TRANSPORT (le déclencheur),
 * PAS le moteur de paquets. WPKG reste le moteur déclaratif (résolution de
 * dépendances, `<check>/<install>/<upgrade>`, versions) — il n'est PAS absorbé.
 * Ce provider projette l'ENSEMBLE des `app_id` cibles (la clé de déclenchement +
 * d'inventaire), JAMAIS les recettes d'installation : le payload est
 * `{app_id, name}`, sans version, sans `<check>`, sans `<install>` (propriété de
 * `packages.xml`).
 *
 * **Lecture Postgres PURE** (NFR7, critère Keycloak). On réutilise la résolution
 * WPKG existante — single source of truth sur « ce que WPKG va installer » —
 * via {@see WorkstationPackagesResolver::computePackages()} (la méthode NON
 * CACHÉE). On n'appelle JAMAIS le wrapper `resolve()` (il enveloppe
 * `Cache::remember` / APCu — interdit dans un provider). Réimplémenter l'union
 * (4 sources : profils/apps × poste/groupes) + le BFS de dépendances
 * transitives ICI divergerait de WPKG réel (risque agent vs WPKG) — interdit.
 * L'hydratation des libellés (`name`) passe par `Application::whereIn('app_id', …)`
 * (PG direct). AUCUN `Cache::`/APCu/AD/`samba-tool`/`LdapRecord` sur ce fichier.
 *
 * ⚠️ Préempter un faux positif de revue (anti-pattern 27.3bis) : la précédence
 * 27.3bis n'a PAS réutilisé `AssociationsResolver` (APCu) car c'était une lecture
 * de cache pour validation UI. Ici, la résolution de l'ENSEMBLE est la logique
 * métier centrale ; on la réutilise NON CACHÉE → le grep garde
 * `ldap|apcu|samba-tool|Cache::|LdapRecord|PackagesXml` reste vide sur ce
 * fichier.
 *
 * **Sémantique `aggregate` / portée `machine`.** Un poste reçoit N applications
 * (union poste + groupes + dépendances). WPKG installe MACHINE-WIDE → portée
 * `machine` (service SYSTEM ; leçon 🔴 27.4 #1 : portée de livraison = machine,
 * jamais session/compagnon — un user ne peut pas installer machine-wide). Un
 * item `applications` par `app_id` affecté ; le compilateur (aggregate) fait
 * l'union/dédup par contenu, sans précédence à arbitrer.
 *
 * **Maille `Broadcast`** (Décision D4). `computePackages($hostname)` résout DÉJÀ
 * l'union poste + groupes + dépendances — c'est la résolution FINALE, mono-sortie
 * (pas une liste de candidats par maille à composer). On émet donc chaque app
 * comme candidat `StateMaille::Broadcast` (tous au même rang) ; le compilateur en
 * `aggregate` fait l'union sans précédence (sans incidence : la précédence ne joue
 * pas pour un type aggregate). Adaptation documentée (iso le collapse mono-WG de
 * 27.4) du modèle « liste de mailles » d'Epic 27 à une API de résolution
 * mono-sortie. Alternative écartée : ré-étiqueter chaque app par sa maille
 * d'origine (coûteux, sans valeur — aggregate ⇒ union de toute façon).
 *
 * **Zéro tri/précédence/dédup dans le provider** (discipline D2 : seul
 * `StateCompiler` le fait). Le provider étiquette ses candidats par maille et
 * s'arrête là.
 */
final class ApplicationsStateProvider implements StateProvider
{
    public function __construct(
        private readonly WorkstationPackagesResolver $resolver,
    ) {}

    public function type(): string
    {
        return Application::TYPE_APPLICATIONS;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Aggregate;
    }

    public function scope(): StateScope
    {
        // MACHINE : WPKG installe machine-wide (service SYSTEM). Leçon 🔴 27.4 #1
        // — la portée de livraison est machine, jamais session/compagnon.
        return StateScope::Machine;
    }

    /**
     * Un candidat PAR `app_id` affecté au poste. L'ensemble cible (union poste +
     * groupes + dépendances transitives) est résolu par la méthode WPKG NON
     * CACHÉE (`computePackages`) — single source of truth, jamais une
     * réimplémentation de l'union/BFS. Les libellés (`name`) sont hydratés par
     * `Application::whereIn('app_id', …)` (PG-pur). Chaque candidat est étiqueté
     * `Broadcast` (la résolution est déjà finale — D4) ; `sourceId` =
     * `Application::id` (PK stable, déterministe & injectif → ordre aggregate /
     * ETag stable).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        // Résolution WPKG NON CACHÉE (NFR7) : ensemble final des app_id (déjà
        // dédupliqué + trié alpha par le resolver) applicables au poste.
        $appIds = $this->resolver
            ->computePackages($ctx->workstation->name)
            ->all();

        if ($appIds === []) {
            return collect();
        }

        // Hydratation PG-pure des libellés/PK. `app_id` est l'identifiant de
        // paquet WPKG ( = `package-id` de `profiles.xml`). Plusieurs lignes
        // partageant un même `app_id` (improbable) : on retient la première (PK
        // la plus petite — déterminisme).
        $apps = Application::query()
            ->whereIn('app_id', $appIds)
            ->orderBy('id')
            ->get(['id', 'app_id', 'name', 'updated_at']);

        $byAppId = [];
        foreach ($apps as $app) {
            $byAppId[(string) $app->app_id] ??= $app;
        }

        $candidates = [];
        foreach ($appIds as $appId) {
            $app = $byAppId[(string) $appId] ?? null;
            if ($app === null) {
                // app_id résolu sans ligne Application correspondante (corruption /
                // archivage entre la résolution et l'hydratation). L'intégrité
                // référentielle des pivots devrait l'empêcher — loguer pour diagnostic.
                Log::warning('ApplicationsStateProvider: app_id sans ligne Application (incohérence de données)', [
                    'app_id' => $appId,
                    'workstation' => $ctx->workstation->name,
                ]);
                continue;
            }

            $candidates[] = new StateCandidate(
                maille: StateMaille::Broadcast,
                payload: [
                    // Identifiant de paquet WPKG concret — jamais un id de
                    // catalogue/pivot/scope (invariant central du contrat).
                    'app_id' => (string) $app->app_id,
                    // Libellé d'affichage (colonne `Application::name`). Strings
                    // only (contrat §4.1 : jamais de float).
                    'name' => (string) $app->name,
                ],
                updatedAt: $app->updated_at,
                // sourceId déterministe & injectif (PK stable) : ordre aggregate
                // stable → ETag stable.
                sourceId: (int) $app->id,
            );
        }

        return collect($candidates);
    }
}
