<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Services\Agent\Enrollment\EnrollmentCampaign;
use App\Services\Agent\Enrollment\EnrollmentMatchService;
use App\Services\Agent\Enrollment\EnrollmentService;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\Providers\AppConfigStateProvider;
use App\Services\Agent\Providers\ApplicationsStateProvider;
use App\Services\Agent\Providers\AssociationsStateProvider;
use App\Services\Agent\Providers\DrivesStateProvider;
use App\Services\Agent\Providers\OverlayMachineStateProvider;
use App\Services\Agent\Providers\OverlayStateProvider;
use App\Services\Agent\Providers\PrintersStateProvider;
use App\Services\Agent\Providers\RegistryListMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryListUserCapabilityProvider;
use App\Services\Agent\Providers\RegistryMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryUserCapabilityProvider;
use App\Services\Agent\Providers\ShortcutsStateProvider;
use App\Services\Agent\Providers\LockscreenStateProvider;
use App\Services\Agent\Providers\WallpaperStateProvider;
use App\Services\Agent\Releases\ReleaseCreationService;
use App\Services\Agent\Releases\ReleaseManifestService;
use App\Services\Agent\Reporting\ConformityService;
use App\Services\Agent\Reporting\ReportIngestService;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateHasher;
use App\Services\Agent\SyncRequestService;
use App\Services\Agent\WorkstationEnvironmentResolver;
use App\Services\ControlHub\Resolution\RegistryUpstreamAdapter;
use App\Services\ControlHub\Resolution\UpstreamAwareProvider;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Services\ControlHub\Resolution\UpstreamLockCollisionDetector;
use App\Services\ControlHub\UpstreamCatalogResolver;
use App\Services\ControlHub\UpstreamLockResolver;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Story 23.2 — Service Provider du canal agent desired-state (Epic 23).
 *
 * Frontière nette ancien/nouveau : ce provider est le foyer du canal NEUF
 * (bearer token custom, zéro AD), distinct d'`AuthV1ServiceProvider` (canal
 * JWT legacy-migration, intouché pendant la transition).
 *
 *  - Binding singleton `TokenRotationService` (stateless réutilisable).
 *  - Binding singleton `EnrollmentService` (Story 23.3 — enrôlement porte 1).
 *  - Binding singleton `ReportIngestService` (Story 24.1 — ingestion des
 *    rapports de conformité, POST /report).
 *  - Registry des StateProviders + binding singleton `StateCompiler`
 *    (Story 23.4) : ajouter un type de ressource = ajouter UNE ligne au
 *    tableau ci-dessous (Epic 27), zéro modification du compilateur.
 *  - Alias middleware `agent.token` (toujours, y compris tests — les Feature
 *    tests montent des routes éphémères derrière cet alias).
 *
 * Évolutions prévues : complétion `config/agent.php` (23.5).
 */
class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenRotationService::class, fn () => new TokenRotationService());
        // Story 25.3 — Porte 2 : rapprochement du faisceau + mode campagne
        // (anti-usurpation jamais débrayé) injectés dans EnrollmentService.
        $this->app->singleton(EnrollmentMatchService::class, fn () => new EnrollmentMatchService());
        $this->app->singleton(EnrollmentCampaign::class, fn () => new EnrollmentCampaign());
        $this->app->singleton(
            EnrollmentService::class,
            fn ($app) => new EnrollmentService(
                $app->make(TokenRotationService::class),
                $app->make(EnrollmentMatchService::class),
                $app->make(EnrollmentCampaign::class),
            ),
        );
        // Story 24.1 — ingestion des rapports de conformité (POST /report).
        // Le StateCompiler fournit les types PAR SESSION (nettoyage des fantômes).
        $this->app->singleton(ReportIngestService::class, fn ($app) => new ReportIngestService(
            $app->make(StateCompiler::class),
        ));
        // Story 24.7 — « forcer la synchro » (UI request / report fulfill) +
        // lecture agrégée de conformité pour les pages parc (stateless).
        $this->app->singleton(SyncRequestService::class, fn () => new SyncRequestService());
        $this->app->singleton(ConformityService::class, fn () => new ConformityService());
        // Story 25.1 — distribution des releases (D6) : création vérifiée
        // hash + manifest résolu par ring (stateless tous les deux).
        $this->app->singleton(ReleaseCreationService::class, fn () => new ReleaseCreationService());
        $this->app->singleton(ReleaseManifestService::class, fn () => new ReleaseManifestService());
        // Story 26.1 — résolution de la nature du poste (précédence
        // nomade>personal_local>shared_local, défaut shared_local, Postgres-only),
        // consommable par les StateProviders de l'Epic 27. Stateless.
        $this->app->singleton(WorkstationEnvironmentResolver::class, fn () => new WorkstationEnvironmentResolver());
        $this->app->singleton(StateHasher::class, fn () => new StateHasher());
        // Story 28.3 — SOURCE des candidats AMONT (contrat controlHub actif) +
        // adaptateurs de payload (bridge minimal type-agnostique). Singleton ⇒
        // résolution du contrat MÉMOÏSÉE et PARTAGÉE par tous les providers d'une
        // compilation (≤ 1 requête « contrat actif ? », court-circuitée quand
        // aucun lien actif — NFR3). PAS de cache ; l'event ControlHubContractChanged
        // reste sans listener (28.2).
        //
        // ⚠️ SEUL `registry` (exclusive-par-clé) est enregistré en prod. L'adaptateur
        // `shortcuts` (aggregate) EXISTE et démontre que le bridge est type-agnostique
        // (test unitaire), mais n'est PAS câblé ici : son payload minimal {name,target}
        // est INCOMPLET pour l'agent (manque `place`/`args`/`icon` — handler_shortcuts.go
        // rejette en bloc tout spec sans `place`, cassant TOUTE la convergence shortcuts
        // du poste). L'expansion par-type complète + le schéma d'échange figé relèvent
        // d'Epic 33 (décision review 28.3, finding #1). Réenregistrer `shortcuts` ICI
        // une fois le payload aligné sur ShortcutsStateProvider::payloadFor().
        //
        // ⚠️ STORY 31.2 — GARDE ANTI DOUBLE-INJECTION (NE PAS enregistrer d'adaptateur
        // `applications` ici). Les ORDRES D'INSTALL amont (items `type='applications'`)
        // sont unionnés à l'ensemble cible DIRECTEMENT par
        // `ApplicationsStateProvider` via l'accesseur `orderedApplicationAppIds()`
        // (pont au niveau ENSEMBLE — D3). Le décorateur `UpstreamAwareProvider` qui
        // enrobe ce provider reste donc un NO-OP pour ce type. Ajouter un
        // `UpstreamPayloadAdapter` pour `applications` produirait une DOUBLE injection
        // (accesseur + décorateur) ET un doublon d'item (le `toPayload` pur ne peut
        // hydrater le `name` depuis l'`Application` locale → payloads divergents, hash
        // instable). Interdit.
        $this->app->singleton(UpstreamContractSource::class, fn () => new UpstreamContractSource([
            new RegistryUpstreamAdapter(),
        ]));
        // Story 29.2 — VERROU d'écriture amont (pendant côté édition de
        // UpstreamContractSource). Singleton ⇒ set des clés `locked`/`instance`/
        // `registry` résolu UNE fois et partagé par les surfaces capacité (override
        // parc + défaut instance) ; court-circuit NFR3 sans contrat actif (≤ 1
        // requête, jamais la table `items`). Mémoïsation == par-requête (PHP-FPM).
        $this->app->singleton(UpstreamLockResolver::class, fn () => new UpstreamLockResolver());
        // Story 31.1 — BORNAGE du canal d'install refnum au catalogue applicatif
        // amont. Singleton ⇒ catalogue (`app_key` du contrat actif) résolu UNE fois
        // et partagé par le scope de consultation (Application::scopeInUpstreamCatalog)
        // ET le garde service (AppProfileService::assertApplicationsInUpstreamCatalog).
        // Court-circuit NFR3 sans contrat actif (≤ 1 requête `controlhub_contracts`,
        // jamais la table catalog). Mémoïsation == par-requête (PHP-FPM).
        $this->app->singleton(UpstreamCatalogResolver::class, fn () => new UpstreamCatalogResolver());
        // Story 30.5 — DÉTECTEUR de collision verrou/verrou à l'assignation
        // (prévention prédictive, FR13). Singleton par-requête : réutilise le
        // singleton UpstreamContractSource (28.3, contrat mémoïsé) + les providers
        // EXCLUSIFS `registry` (KeyedExclusiveProvider) pour DÉLÉGUER `exclusiveKey()`
        // — aucune dérivation de clé réinventée, aucune écriture. Court-circuit NFR3
        // sans item label locked. NE touche NI StateCompiler NI StateMaille NI la
        // décoration des providers (D2 confiné, AC #5b) : on AJOUTE seulement ce binding.
        $this->app->singleton(UpstreamLockCollisionDetector::class, fn ($app) => new UpstreamLockCollisionDetector(
            $app->make(UpstreamContractSource::class),
            [
                $app->make(RegistryMachineCapabilityProvider::class),
                $app->make(RegistryUserCapabilityProvider::class),
            ],
        ));
        $this->app->singleton(StateCompiler::class, fn ($app) => new StateCompiler(
            $app->make(StateHasher::class),
            // Story 28.3 — chaque provider est ENROBÉ par le décorateur amont :
            // itemsFor() = candidats_internes ∪ candidats_amont(maille Upstream).
            // L'ordre et la liste des providers sont préservés (zéro retiré/
            // ajouté) ; le marqueur KeyedExclusiveProvider est relayé (registry).
            // Sans contrat actif, pass-through STRICT (compilé byte-identique).
            array_map(
                fn (StateProvider $p): StateProvider => UpstreamAwareProvider::wrap(
                    $p,
                    $app->make(UpstreamContractSource::class),
                ),
                [
                $app->make(WallpaperStateProvider::class),
                // Fond de l'écran de VERROUILLAGE (type `lockscreen`, portée
                // machine) — pendant pré-login du wallpaper de bureau (session).
                // Posé machine-wide par le service SYSTEM (PersonalizationCSP) ;
                // owners défaut étab + WorkstationGroup seulement (pas de user
                // au verrouillage). Une ligne, zéro modif du compilateur.
                $app->make(LockscreenStateProvider::class),
                $app->make(OverlayStateProvider::class),
                // Story 27.10 — volet MACHINE de l'overlay : la salle
                // (`{kind:"machine", room}`) passe en portée machine (cache
                // persistant) pour précharger poste+salle au logon sans attendre
                // le fetch per-user. `room` retiré de l'item identity session.
                $app->make(OverlayMachineStateProvider::class),
                // Story 27.1 — type `shortcuts` (aggregate / machine_user) :
                // union des raccourcis des mailles, chemin du bureau résolu
                // serveur (fix Bug C). Une ligne, zéro modif du compilateur.
                $app->make(ShortcutsStateProvider::class),
                // Story 27.2 — types `printers` (aggregate / session) : union
                // des imprimantes des mailles POSTE, défaut exclusif réglé par
                // WG (physique > logique) ; et `drives` (aggregate / session) :
                // projection des partages de classe en montages réseau (MVP-A,
                // pas de table). Deux lignes, zéro modif du compilateur.
                $app->make(PrintersStateProvider::class),
                $app->make(DrivesStateProvider::class),
                // Story 27.12 — type `registry` CAPABILITY-FIRST (exclusive PAR
                // IDENTITÉ DE CLÉ). Rewrite de 27.3/27.3ter : la table d'authoring
                // devient `capabilities` (intention métier), le registre est UNE
                // projection (`capability_projections.mechanism = registry`). Le
                // provider EXPANSE une capacité → items concrets
                // {hive,path,name,type,value} (interpréteur de `spec` D5, map/
                // littéral). Broadcast (défaut diffusé) + override de VALEUR de
                // capacité par maille (D4). DEUX providers, UN handler Go : HKLM →
                // portée machine (service SYSTEM), HKCU → portée session
                // (compagnon). Le `key`/`id` de capacité/projection ne fuit JAMAIS
                // au payload (invariant central). Contrat + agent INCHANGÉS (D3).
                // Zéro modif du routage compilateur (le scope() de chaque provider
                // suffit). Remplace Registry{Machine,User}StateProvider (retirés).
                $app->make(RegistryMachineCapabilityProvider::class),
                $app->make(RegistryUserCapabilityProvider::class),
                // Story 35.2 — type `registry_list` (exclusive PAR CLÉ-CONTENEUR,
                // contrat §7.6) : listes registre à sous-valeurs indexées `\1..\N`
                // (Forcelist Chrome/Edge, DisallowRun). Même modèle capability-first
                // que `registry` (projection `capability_projections.mechanism =
                // registry_list`, bi-projection D5 admise), mais l'agent POSSÈDE la
                // clé-conteneur (D3 : écrit `1..N`, supprime les noms numériques
                // hors canon). `exclusiveKey() = {hive|path}` (2 segments) : la
                // maille la plus spécifique gagne le conteneur ENTIER — jamais
                // d'union de listes entre mailles, StateCompiler INTOUCHÉ (D2).
                // DEUX providers, UN handler Go `registry_list` : HKLM → portée
                // machine (SYSTEM), HKCU → portée session (compagnon). Le canal
                // amont (UpstreamLockCollisionDetector / RegistryUpstreamAdapter)
                // reste registry-only — aucun adaptateur ajouté. Deux lignes, zéro
                // modif du compilateur.
                $app->make(RegistryListMachineCapabilityProvider::class),
                $app->make(RegistryListUserCapabilityProvider::class),
                // Story 27.3bis — type `associations` (exclusive PAR IDENTIFIANT,
                // portée session/compagnon HKCU) : catalogue d'associations de
                // fichiers/protocoles par défaut activables par parc, compilées
                // en items concrets {identifier, progid, type}. Le hash UserChoice
                // est calculé 100 % côté agent (jamais au payload). Une ligne,
                // zéro modif du compilateur (exclusiveKey()=identifier suffit).
                $app->make(AssociationsStateProvider::class),
                // Story 27.4 — type `app_config` (aggregate PAR app_kind /
                // session) : projection en LECTURE SEULE des policies d'app
                // résolues (`app_customizations`, story 4.8) via
                // AppCustomizationService::resolvePoliciesForMachine (PG +
                // config-pur, NFR7). UN item par app (Firefox/Thunderbird),
                // policies CONCRÈTES au payload (jamais un id de scope). Le
                // handler agent écrit le SEUL mécanisme enterprise natif
                // `policies.json` au chemin natif de l'install. Une ligne, zéro
                // modif du compilateur.
                $app->make(AppConfigStateProvider::class),
                // Story 27.5 — type `applications` (aggregate / machine) :
                // projection en LECTURE SEULE de l'ensemble cible WPKG d'un poste
                // (WorkstationPackagesResolver::computePackages, méthode NON
                // CACHÉE — NFR7, jamais l'APCu de resolve()). UN item par app_id
                // affecté, payload concret {app_id, name} (jamais une recette
                // d'install : WPKG reste le moteur déclaratif, non absorbé). Le
                // handler agent DÉCLENCHE WPKG (service SYSTEM = portée machine) ;
                // l'ensemble cible est aussi la clé d'inventaire par poste (AC4).
                // Une ligne, zéro modif du compilateur.
                //
                // Story 31.2 — le 2ᵉ argument `UpstreamContractSource` (pont des
                // ORDRES D'INSTALL amont, FR6) est résolu par AUTO-WIRING (singleton
                // déjà bindé ci-dessus). Le décorateur `UpstreamAwareProvider` reste
                // un no-op pour `applications` (aucun adaptateur — garde anti
                // double-injection ci-dessus) : l'union amont passe UNIQUEMENT par
                // l'accesseur dédié du provider, jamais par la décoration.
                $app->make(ApplicationsStateProvider::class),
                ],
            ),
        ));
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('agent.token', AuthenticateAgentToken::class);
    }
}
