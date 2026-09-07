<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Services\Agent\AgentTtlResolver;
use App\Services\Agent\Enrollment\EnrollmentCampaign;
use App\Services\Agent\Enrollment\EnrollmentMatchService;
use App\Services\Agent\Enrollment\EnrollmentService;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\Providers\AppConfigStateProvider;
use App\Services\Agent\Providers\ApplicationsStateProvider;
use App\Services\Agent\Providers\AppProfileCapabilityProvider;
use App\Services\Agent\Providers\AssociationsStateProvider;
use App\Services\Agent\Providers\DrivesStateProvider;
use App\Services\Agent\Providers\FirewallCapabilityProvider;
use App\Services\Agent\Providers\FolderAccessRulesStateProvider;
use App\Services\Agent\Providers\LegacyCleanupCapabilityProvider;
use App\Services\Agent\Providers\OverlayMachineStateProvider;
use App\Services\Agent\Providers\OverlayStateProvider;
use App\Services\Agent\Providers\PrintersStateProvider;
use App\Services\Agent\Providers\PrivilegeCapabilityProvider;
use App\Services\Agent\Providers\RegistryListMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryListUserCapabilityProvider;
use App\Services\Agent\Providers\RegistryMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryUserCapabilityProvider;
use App\Services\Agent\Providers\ShellFoldersStateProvider;
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
 * Service Provider du canal agent desired-state.
 *
 * Frontière nette ancien/nouveau : ce provider est le foyer du canal NEUF
 * (bearer token custom, zéro AD), distinct d'`AuthV1ServiceProvider` (canal
 * JWT legacy-migration, intouché pendant la transition).
 *
 *  - Binding singleton `TokenRotationService` (stateless réutilisable).
 * - Binding singleton `EnrollmentService` (enrôlement porte 1).
 * - Binding singleton `ReportIngestService` (ingestion des
 *    rapports de conformité, POST /report).
 *  - Registry des StateProviders + binding singleton `StateCompiler`
 * ajouter un type de ressource = ajouter UNE ligne au
 * tableau ci-dessous, zéro modification du compilateur.
 *  - Alias middleware `agent.token` (toujours, y compris tests — les Feature
 *    tests montent des routes éphémères derrière cet alias).
 *
 * Évolutions prévues : complétion `config/agent.php`.
 */
class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenRotationService::class, fn () => new TokenRotationService());
        // Porte 2 : rapprochement du faisceau + mode campagne
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
        // Ingestion des rapports de conformité (POST /report).
        // Le StateCompiler fournit les types PAR SESSION (nettoyage des fantômes).
        $this->app->singleton(ReportIngestService::class, fn ($app) => new ReportIngestService(
            $app->make(StateCompiler::class),
        ));
        // « forcer la synchro » (UI request / report fulfill) +
        // lecture agrégée de conformité pour les pages parc (stateless).
        $this->app->singleton(SyncRequestService::class, fn () => new SyncRequestService());
        $this->app->singleton(ConformityService::class, fn () => new ConformityService());
        // Distribution des releases : création vérifiée
        // hash + manifest résolu par ring (stateless tous les deux).
        $this->app->singleton(ReleaseCreationService::class, fn () => new ReleaseCreationService());
        $this->app->singleton(ReleaseManifestService::class, fn () => new ReleaseManifestService());
        // Résolution de la nature du poste (précédence
        // nomade>personal_local>shared_local, défaut shared_local, Postgres-only),
        // consommable par les StateProviders de l'agent. Stateless.
        $this->app->singleton(WorkstationEnvironmentResolver::class, fn () => new WorkstationEnvironmentResolver());
        $this->app->singleton(StateHasher::class, fn () => new StateHasher());
        // Résolveur du TTL de poll PAR CONTEXTE :
        // lecture Postgres pure (capability_assignments), stateless (aucun
        // cache du verdict — cf. docblock AgentTtlResolver).
        $this->app->singleton(AgentTtlResolver::class, fn () => new AgentTtlResolver());
        // SOURCE des candidats AMONT (contrat controlHub actif) +
        // adaptateurs de payload (bridge minimal type-agnostique). Singleton ⇒
        // résolution du contrat MÉMOÏSÉE et PARTAGÉE par tous les providers d'une
        // compilation (≤ 1 requête « contrat actif ? », court-circuitée quand
        // aucun lien actif). PAS de cache.
        //
        // ⚠️ SEUL `registry` (exclusive-par-clé) est enregistré en prod. L'adaptateur
        // `shortcuts` (aggregate) EXISTE et démontre que le bridge est type-agnostique
        // (test unitaire), mais n'est PAS câblé ici : son payload minimal {name,target}
        // est INCOMPLET pour l'agent (manque `place`/`args`/`icon` — handler_shortcuts.go
        // rejette en bloc tout spec sans `place`, cassant TOUTE la convergence shortcuts
        // du poste). L'expansion par-type complète et le schéma d'échange figé
        // restent à faire : réenregistrer `shortcuts` ICI une fois le payload
        // aligné sur `ShortcutsStateProvider::payloadFor()`.
        //
        // ⚠️ — GARDE ANTI DOUBLE-INJECTION (NE PAS enregistrer d'adaptateur
        // `applications` ici). Les ORDRES D'INSTALL amont (items `type='applications'`)
        // sont unionnés à l'ensemble cible DIRECTEMENT par
        // `ApplicationsStateProvider` via l'accesseur `orderedApplicationAppIds()`
        // (pont au niveau ENSEMBLE). Le décorateur `UpstreamAwareProvider` qui
        // enrobe ce provider reste donc un NO-OP pour ce type. Ajouter un
        // `UpstreamPayloadAdapter` pour `applications` produirait une DOUBLE injection
        // (accesseur + décorateur) ET un doublon d'item (le `toPayload` pur ne peut
        // hydrater le `name` depuis l'`Application` locale → payloads divergents, hash
        // instable). Interdit.
        $this->app->singleton(UpstreamContractSource::class, fn () => new UpstreamContractSource([
            new RegistryUpstreamAdapter(),
        ]));
        // VERROU d'écriture amont (pendant côté édition de
        // UpstreamContractSource). Singleton ⇒ set des clés `locked`/`instance`/
        // `registry` résolu UNE fois et partagé par les surfaces capacité (override
        // parc + défaut instance) ; court-circuit sans contrat actif (≤ 1
        // requête, jamais la table `items`). Mémoïsation == par-requête (PHP-FPM).
        $this->app->singleton(UpstreamLockResolver::class, fn () => new UpstreamLockResolver());
        // Catalogue applicatif amont — outillage de l'ADMINISTRATION des applications
        // (scope Application::scopeInUpstreamCatalog). L'assignation d'apps aux entités
        // n'est PAS bornée. Singleton ⇒ catalogue (`app_key` du contrat actif) résolu
        // UNE fois. Court-circuit sans contrat actif (≤ 1 requête
        // `controlhub_contracts`, jamais la table catalog). Mémoïsation == par-requête (PHP-FPM).
        $this->app->singleton(UpstreamCatalogResolver::class, fn () => new UpstreamCatalogResolver());
        // DÉTECTEUR de collision verrou/verrou à l'assignation
        // (prévention prédictive). Singleton par-requête : réutilise le
        // singleton UpstreamContractSource (contrat mémoïsé) + les providers
        // EXCLUSIFS `registry` (KeyedExclusiveProvider) pour DÉLÉGUER `exclusiveKey()`
        // — aucune dérivation de clé réinventée, aucune écriture. Court-circuit
        // sans item label locked. NE touche NI StateCompiler NI StateMaille NI la
        // décoration des providers : on AJOUTE seulement ce binding.
        $this->app->singleton(UpstreamLockCollisionDetector::class, fn ($app) => new UpstreamLockCollisionDetector(
            $app->make(UpstreamContractSource::class),
            [
                $app->make(RegistryMachineCapabilityProvider::class),
                $app->make(RegistryUserCapabilityProvider::class),
            ],
        ));
        $this->app->singleton(StateCompiler::class, fn ($app) => new StateCompiler(
            $app->make(StateHasher::class),
            // Chaque provider est ENROBÉ par le décorateur amont :
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
                // Volet MACHINE de l'overlay : la salle
                // (`{kind:"machine", room}`) passe en portée machine (cache
                // persistant) pour précharger poste+salle au logon sans attendre
                // le fetch per-user. `room` retiré de l'item identity session.
                $app->make(OverlayMachineStateProvider::class),
                // Type `shortcuts` (aggregate / machine_user) :
                // union des raccourcis des mailles, chemin du bureau résolu
                // serveur (fix Bug C). Une ligne, zéro modif du compilateur.
                $app->make(ShortcutsStateProvider::class),
                // Type `folders` (exclusive / machine_user) :
                // REDIRECTION du Bureau (`User Shell Folders\Desktop`) vers le
                // MÊME chemin que celui où `shortcuts` pose les `.lnk`. Sans
                // elle, l'agent dépose des raccourcis dans un dossier que le
                // shell ne regarde pas — panne réelle depuis que le blocage
                // d'héritage GPO (2026-07-20) a coupé le script legacy qui
                // écrivait cette valeur, sans successeur. Voisin IMMÉDIAT de
                // ShortcutsStateProvider ci-dessus : les deux partagent le
                // DesktopPathResolver et ne doivent jamais diverger.
                $app->make(ShellFoldersStateProvider::class),
                // Types `printers` (aggregate / session) : union
                // des imprimantes des mailles POSTE, défaut exclusif réglé par
                // WG (physique > logique) ; et `drives` (aggregate / session) :
                // projection des partages de classe en montages réseau (MVP-A,
                // pas de table). Deux lignes, zéro modif du compilateur.
                $app->make(PrintersStateProvider::class),
                $app->make(DrivesStateProvider::class),
                // Type `registry` CAPABILITY-FIRST (exclusive PAR
                // IDENTITÉ DE CLÉ). Rewrite : la table d'authoring
                // devient `capabilities` (intention métier), le registre est UNE
                // projection (`capability_projections.mechanism = registry`). Le
                // provider EXPANSE une capacité → items concrets
                // {hive,path,name,type,value} (interpréteur de `spec` : map ou
                // littéral). Broadcast (défaut diffusé) + override de VALEUR de
                // capacité par maille. DEUX providers, UN handler Go : HKLM →
                // portée machine (service SYSTEM), HKCU → portée session
                // (compagnon). Le `key`/`id` de capacité/projection ne fuit JAMAIS
                // au payload (invariant central). Contrat + agent INCHANGÉS.
                // Zéro modif du routage compilateur (le scope() de chaque provider
                // suffit). Remplace Registry{Machine,User}StateProvider (retirés).
                $app->make(RegistryMachineCapabilityProvider::class),
                $app->make(RegistryUserCapabilityProvider::class),
                // Type `registry_list` (exclusive PAR CLÉ-CONTENEUR,
                // contrat §7.6) : listes registre à sous-valeurs indexées `\1..\N`
                // (Forcelist Chrome/Edge, DisallowRun). Même modèle capability-first
                // que `registry` (projection `capability_projections.mechanism =
                // registry_list`, bi-projection admise), mais l'agent POSSÈDE la
                // clé-conteneur (il écrit `1..N`, supprime les noms numériques
                // hors canon). `exclusiveKey() = {hive|path}` (2 segments) : la
                // maille la plus spécifique gagne le conteneur ENTIER — jamais
                // d'union de listes entre mailles, StateCompiler INTOUCHÉ.
                // DEUX providers, UN handler Go `registry_list` : HKLM → portée
                // machine (SYSTEM), HKCU → portée session (compagnon). Le canal
                // amont (UpstreamLockCollisionDetector / RegistryUpstreamAdapter)
                // reste registry-only — aucun adaptateur ajouté. Deux lignes, zéro
                // modif du compilateur.
                $app->make(RegistryListMachineCapabilityProvider::class),
                $app->make(RegistryListUserCapabilityProvider::class),
                // Type `firewall` (exclusive PAR rule_id / portée
                // MACHINE, deuxième mécanisme HORS-REGISTRE). Le provider EXPANSE
                // une capacité → items concrets {rule_id, direction, action,
                // remote_scope, protocol, ensure} (+ remote_addresses/ports
                // conditionnels — interpréteur de `spec` surchargé, `StateCompiler`
                // INTOUCHÉ). `exclusiveKey() = rule_id` : la maille la plus
                // spécifique gagne CETTE règle, les rule_id distincts s'accumulent
                // dans le groupe `SambaEdu-Agent`. La traduction
                // `remote_scope: internet` en plages inverses-RFC1918 vit dans le
                // handler. Postgres pur (la propriété par groupe + la
                // convergence sont côté POSTE). UN seul provider (portée Machine).
                // Une ligne, zéro modif du compilateur (⚠️ fichier partagé avec la
                // conflit de merge trivial, garder les deux lignes).
                $app->make(FirewallCapabilityProvider::class),
                // + — type `fs_acl` (exclusive PAR ACE / portée
                // MACHINE, premier mécanisme HORS-REGISTRE). Le provider EXPANSE
                // une capacité → items concrets {path, trustee, ace_type, rights,
                // applies_to, ensure} (6 clés, interpréteur de `spec` surchargé —
                // `StateCompiler` INTOUCHÉ). `exclusiveKey() =
                // {path|trustee|ace_type}` : la maille la plus spécifique gagne
                // CETTE ACE, les ACE distinctes s'accumulent. Jetons d'audience
                // @eleves|@profs|@personnels résolus par AudienceTokens (enum
                // FERMÉ). Postgres pur (résolution SID côté POSTE, via la LSA).
                //
                // BI-ALIMENTATION : la ligne
                // `FsAclCapabilityProvider` est REMPLACÉE par le composite
                // `FolderAccessRulesStateProvider`, qui l'enveloppe et unionne les
                // candidats-RÈGLES (formulaire refnum) aux candidats-CAPACITÉS.
                // UN SEUL provider `fs_acl` compilé = condition STRUCTURELLE de
                // l'arbitrage règle↔capacité par le compilateur (selectExclusive
                // arbitre PAR provider — deux providers = collision non arbitrée).
                // `exclusiveKey()`/type/semantics/scope DÉLÉGUÉS ;
                // byte-identité golden sans règles. Zéro modif du
                // compilateur, zéro changement agent/contrat (2.6.0 porte déjà le
                // handler). ⚠️ Fichier partagé avec (firewall AJOUTE sa ligne
                // ici) — conflit de merge trivial, garder les deux.
                $app->make(FolderAccessRulesStateProvider::class),
                // Type `privilege` (exclusive PAR nom de privilège /
                // portée MACHINE, troisième mécanisme HORS-REGISTRE). Le provider
                // EXPANSE une capacité → AU PLUS un item concret 2 clés
                // {privilege, accounts} (interpréteur de `spec` surchargé,
                // `StateCompiler` INTOUCHÉ). `exclusiveKey() = <privilège>`
                // minuscule (1 segment) : la maille la plus spécifique gagne la
                // liste `accounts` ENTIÈRE (NON cumulatif — le ciblage « qui est
                // refusé » vit DANS la liste). Enum FERMÉ SeDeny*-only (un grant
                // verrouillerait la machine — refus guard + agent). Jetons
                // d'audience @eleves|@profs|@personnels résolus par AudienceTokens
                // (réutilisé tel quel). Postgres pur (résolution SID côté POSTE via la
                // LSA, conteneur SANS store). UN seul provider (portée Machine).
                // Une ligne, zéro modif du compilateur.
                $app->make(PrivilegeCapabilityProvider::class),
                // Type `legacy_cleanup` (exclusive à IDENTITÉ FIXE /
                // portée MACHINE, quatrième mécanisme HORS-REGISTRE). Le provider
                // EXPANSE la capacité de gating `legacy_hooks_cleanup` → AU PLUS
                // un item concret 1 clé {mozilla: "vanilla"} (enum FERMÉ §7.10,
                // valeur VANILLA ; interpréteur de `spec` surchargé, `StateCompiler`
                // INTOUCHÉ). `exclusiveKey() = "legacy_cleanup"` FIXE : UN seul
                // nettoyage par poste, la maille la plus spécifique gagne l'item
                // ENTIER (patron défaut Broadcast + override parc). Le
                // CATALOGUE d'artefacts legacy est versionné DANS l'agent —
                // le serveur ne fait que GATER. Une ligne, zéro modif du
                // compilateur.
                $app->make(LegacyCleanupCapabilityProvider::class),
                // Type `app_profile` (aggregate / portée SESSION,
                // sixième mécanisme HORS-REGISTRE mais le SEUL côté compagnon).
                // Le provider projette le CATALOGUE des applications redirigeables
                // (spec de la capacité `app_profile` windows) → un item concret
                // {app, link, server, profile_name}(+ install_hash/cache_local) par
                // app, maille User, chemin serveur en TOKEN `\\<se4fs>\users\<user>\…`
                // (jamais résolu —). Gate d'instance FilePolicyService['home']
                // ( : rediriger vers une cible non montée n'a pas de sens) et
                // `$ctx->user === null` ⇒ VIDE (iso DrivesStateProvider). Nom de
                // profil `managed.default` NEUF/hors radical sambaedu ⇒ jamais
                // matché par referencesSambaeduProfile du legacy_cleanup :
                // les deux canaux coexistent. Une ligne, zéro modif du compilateur.
                $app->make(AppProfileCapabilityProvider::class),
                // Type `associations` (exclusive PAR IDENTIFIANT,
                // portée session/compagnon HKCU) : catalogue d'associations de
                // fichiers/protocoles par défaut activables par parc, compilées
                // en items concrets {identifier, progid, type}. Le hash UserChoice
                // est calculé 100 % côté agent (jamais au payload). Une ligne,
                // zéro modif du compilateur (exclusiveKey()=identifier suffit).
                $app->make(AssociationsStateProvider::class),
                // Type `app_config` (aggregate PAR app_kind /
                // session) : projection en LECTURE SEULE des policies d'app
                // résolues (`app_customizations`) via
                // AppCustomizationService::resolvePoliciesForMachine (PG +
                // config-pur, aucune requête sortante). UN item par app (Firefox/Thunderbird),
                // policies CONCRÈTES au payload (jamais un id de scope). Le
                // handler agent écrit le SEUL mécanisme enterprise natif
                // `policies.json` au chemin natif de l'install. Une ligne, zéro
                // modif du compilateur.
                $app->make(AppConfigStateProvider::class),
                // Type `applications` (aggregate / machine) :
                // projection en LECTURE SEULE de l'ensemble cible WPKG d'un poste
                // (WorkstationPackagesResolver::computePackages, méthode NON
                // CACHÉE — jamais l'APCu de resolve()). UN item par app_id
                // affecté, payload concret {app_id, name} (jamais une recette
                // d'install : WPKG reste le moteur déclaratif, non absorbé). Le
                // handler agent DÉCLENCHE WPKG (service SYSTEM = portée machine) ;
                // L'ensemble cible est aussi la clé d'inventaire par poste.
                // Une ligne, zéro modif du compilateur.
                //
                // Le 2ᵉ argument `UpstreamContractSource` (pont des
                // ORDRES D'INSTALL amont) est résolu par AUTO-WIRING (singleton
                // déjà bindé ci-dessus). Le décorateur `UpstreamAwareProvider` reste
                // un no-op pour `applications` (aucun adaptateur — garde anti
                // double-injection ci-dessus) : l'union amont passe UNIQUEMENT par
                // l'accesseur dédié du provider, jamais par la décoration.
                //
                // Le 3ᵉ argument `CloudSyncClient` (désignation du
                // client de synchronisation du cloud actif) est lui aussi résolu
                // par AUTO-WIRING : service sans état ni dépendance, aucun binding
                // à déclarer. Même figure que le 2ᵉ — l'union passe par
                // l'accesseur dédié du provider, jamais par un adaptateur de
                // payload (qui ne pourrait pas hydrater le `name` local).
                $app->make(ApplicationsStateProvider::class),
                ],
            ),
            // 3ᵉ argument : résolveur du TTL par contexte.
            $app->make(AgentTtlResolver::class),
        ));
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('agent.token', AuthenticateAgentToken::class);
    }
}
