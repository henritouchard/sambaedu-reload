<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Application;
use App\Services\Agent\CloudSyncClient;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Services\Filesystem\FileLocationService;
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
 *
 * **Apps « défaut parc » (Story 27.17).** Les applications marquées
 * `applications.is_parc_default = true` sont appliquées PAR DÉFAUT à TOUS les
 * postes (équivalent applicatif du `is_default` du wallpaper). Le provider les
 * UNIONNE à l'ensemble résolu par poste/groupe/profil, toujours en candidats
 * `Broadcast`. Le resolver WPKG reste inchangé (il ne connaît que les
 * rattachements poste/groupe/profil) ; la précédence n'est pas modifiée — le
 * type `applications` est `aggregate`, l'union ne crée jamais de conflit.
 *
 * **Ordres d'install amont (Story 31.2 — FR6).** L'autorité amont (controlHub)
 * peut ORDONNER l'install d'une app — un item de contrat `type='applications'`,
 * cible `instance` (toute la flotte) ∪ labels portés par le poste. Ces `app_id`
 * sont UNIONNÉS à l'ensemble cible AVANT hydratation, via l'accesseur LECTURE
 * SEULE {@see UpstreamContractSource::orderedApplicationAppIds()} : le payload
 * `{app_id, name}` hydraté est IDENTIQUE quelle que soit la source ⇒ une app
 * aussi résolue localement collapse en UN item (dédup aggregate du compilateur =
 * idempotence d'état). Pont au niveau ENSEMBLE (décision D3) — JAMAIS un
 * `UpstreamPayloadAdapter` (un adaptateur ne pourrait hydrater le `name` depuis
 * l'`Application` locale → doublon). Court-circuit NFR3 : sans contrat actif (ou
 * sans ordre d'install), l'accesseur renvoie `[]` et l'ensemble reste
 * byte-identique au 27.5. Le moteur d'install (WPKG) n'est pas absorbé : SE5 ne
 * livre que l'ensemble d'`app_id`.
 *
 * **Client de synchronisation du cloud (Story 63.5).** Quand l'instance a placé
 * un espace au cloud ET choisi d'y accéder « par le client de synchronisation »
 * plutôt que par le navigateur, l'application du catalogue DÉSIGNÉE comme client
 * du cloud actif est UNIONNÉE à l'ensemble cible — TROISIÈME source, exactement
 * le patron des deux précédentes, via l'accesseur LECTURE SEULE
 * {@see CloudSyncClient::appIdFor()}. Le payload hydraté est le même
 * `{app_id, name}` : une app à la fois désignée ET assignée par ailleurs
 * collapse en UN item (dédup aggregate). Court-circuit : sans cloud actif, en
 * position `web` (le DÉFAUT), ou lorsque la désignation ne résout aucune ligne
 * de catalogue, l'accesseur rend `null` et l'ensemble reste byte-identique.
 *
 * ⚠️ **La compilation ne rejoue PAS la garde de SAISIE** (statut installé,
 * recette portant un `<remove>`) : elle vit à l'écriture, et là seulement. Le
 * catalogue bouge sans qu'aucun administrateur ne décide — une réinstallation
 * passe par `Downloading`, un échec laisse `Error`, une synchro amont réécrit
 * `xml` — et rejouer cette garde ici ferait SORTIR l'`app_id` de l'ensemble
 * cible, donc désinstaller le client de tout le parc pendant une mise à jour.
 * Voir {@see CloudSyncClient::appIdFor()}.
 *
 * ⚠️ **AUCUN code SE5 ne « désinstalle ».** Repasser au navigateur fait
 * simplement SORTIR l'`app_id` de l'ensemble cible ; le handler agent constate
 * que le desired set ne vaut plus le profil déposé, redépose un `profiles.xml`
 * amaigri et relance WPKG, qui désinstalle par le `<remove>` de la recette. La
 * frontière « un tuyau, deux outils » n'est pas franchie — et l'union restant
 * une union, une app aussi assignée par un profil ou un groupe RESTE installée :
 * le plan de fichiers ajoute une raison d'installer, il ne gouverne pas les
 * affectations d'applications.
 */
final class ApplicationsStateProvider implements StateProvider
{
    public function __construct(
        private readonly WorkstationPackagesResolver $resolver,
        // Story 31.2 — SOURCE des ordres d'install amont (contrat actif). Singleton
        // mémoïsé partagé (≤ 1 requête « contrat actif ? », court-circuit NFR3 sans
        // lien actif). N'enregistre AUCUN adaptateur `applications` (pont au niveau
        // ensemble, pas par décorateur — anti double-injection, cf. AgentServiceProvider).
        private readonly UpstreamContractSource $source,
        // Story 63.5 — POSABILITÉ + désignation du client de synchronisation du
        // cloud actif. Service sans état, résolu par auto-wiring (le provider est
        // instancié par le conteneur dans AgentServiceProvider). Il ne lit que des
        // réglages et le catalogue : aucun cache, aucun réseau.
        private readonly CloudSyncClient $syncClient,
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
        // dédupliqué + trié alpha par le resolver) applicables au poste via ses
        // profils/apps × poste/groupes + dépendances transitives.
        $resolvedAppIds = $this->resolver
            ->computePackages($ctx->workstation->name)
            ->all();

        // Story 27.17 — apps DÉFAUT PARC : marquées `is_parc_default=true`, elles
        // sont appliquées par défaut à TOUS les postes (couche Broadcast — iso
        // `is_default` du wallpaper). On les UNIONNE à l'ensemble résolu, sans
        // toucher au resolver (qui reste poste/groupe/profil) ni à la précédence
        // (`applications` est un type aggregate : l'union ne crée pas de conflit).
        // Lecture PG-pure (NFR7) — aucun cache/AD.
        $parcDefaultAppIds = Application::query()
            ->parcDefault()
            ->whereNotNull('app_id')
            ->where('app_id', '!=', '')
            ->orderBy('app_id')
            ->pluck('app_id')
            ->all();

        // Story 31.2 — ORDRES D'INSTALL amont (FR6) : `app_id` qu'un contrat actif
        // ORDONNE d'installer sur ce poste (cible `instance` ∪ labels portés). On
        // les UNIONNE à l'ensemble cible AVANT dédup/hydratation : le payload
        // {app_id, name} hydraté est IDENTIQUE quelle que soit la source ⇒ dédup
        // aggregate naturelle (idempotence AC3). Pont au niveau ENSEMBLE (D3) — pas
        // via UpstreamPayloadAdapter (toPayload ne pourrait hydrater le name local).
        // Court-circuit NFR3 : sans contrat actif / sans ordre, l'accesseur renvoie
        // [] (zéro requête items, ensemble byte-identique au 27.5).
        $orderedAppIds = $this->source->orderedApplicationAppIds($ctx);

        // Story 63.5 — CLIENT DE SYNCHRONISATION du cloud actif : l'`app_id` de
        // l'application DÉSIGNÉE comme client, quand l'instance a choisi
        // d'atteindre son cloud par le client plutôt que par le navigateur. On
        // l'UNIONNE à l'ensemble cible AVANT dédup/hydratation, exactement comme
        // les ordres amont : le payload {app_id, name} hydraté est IDENTIQUE
        // quelle que soit la source ⇒ une app aussi assignée par un profil, un
        // groupe ou `is_parc_default` collapse en UN item.
        //
        // AUCUN try/catch sur la lecture des emplacements, et c'est le point (iso
        // DrivesStateProvider) : une ligne `files.locations` forgée LÈVE. Un repli
        // silencieux inventerait une décision que personne n'a prise. L'ABSENCE de
        // ligne, elle, n'est pas une corruption : elle rend les défauts
        // (`cloud.actif = aucun`) et ne lève jamais.
        //
        // Court-circuit : `aucun` ⇒ zéro requête ; `web` (le DÉFAUT) ou
        // désignation qui ne résout aucune ligne de catalogue ⇒ `null`, ensemble
        // byte-identique au 31.2.
        $syncClientAppId = $this->syncClient->appIdFor(FileLocationService::current()->cloudActif);

        // Union dédupliquée + ré-ordonnée (déterminisme : alpha insensible casse,
        // iso le tri du resolver → ordre aggregate / ETag stables). Un poste sans
        // config spécifique ET sans aucune app défaut parc retombe sur exactement
        // le résultat antérieur (non-régression du state Broadcast).
        $appIds = collect($resolvedAppIds)
            ->concat($parcDefaultAppIds)
            ->concat($orderedAppIds)
            ->concat($syncClientAppId === null ? [] : [$syncClientAppId])
            ->filter(fn ($v): bool => is_string($v) && $v !== '')
            ->map(fn ($v): string => (string) $v)
            ->unique()
            ->sort(fn (string $a, string $b): int => strcasecmp($a, $b))
            ->values()
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
