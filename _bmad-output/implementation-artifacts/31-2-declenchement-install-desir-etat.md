# Story 31.2: Déclenchement d'install en désir d'état

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **reprendre les ordres d'install amont (cible + app) sous forme de DÉSIR D'ÉTAT injecté dans l'état cible que l'agent récupère à son check-in**,
so that **une install pilotée par l'autorité amont soit idempotente et reprenable (FR6) — l'app ordonnée figure dans l'`applications` désiré du poste ciblé, et une app déjà présente ne redéclenche aucune réinstallation**.

> Story **2ᵉ et dernière** de l'Epic 31 (« Dépôt applicatif borné & install pilotée »). 31.1 a livré le **bornage** (FR5 : QUI peut être installé par le refnum). 31.2 livre le **PILOTAGE** (FR6 : l'amont ORDONNE l'install d'une app sur une cible, en mode désir d'état). Ce sont deux faces complémentaires : le catalogue (31.1) = liste autoritaire de **filtrage** ; l'ordre d'install (31.2) = directive **active** projetée dans l'état désiré.
>
> **Modèle métier (rappel)** : un « ordre d'install » = `{cible + app}` (handoff controlHub §7, l.124). Côté SE5, il se matérialise par un `controlhub_contract_items` de **`type='applications'`**, `key=<app_id>`, `target_type=instance|label`, `enforcement_state` non-`absent`. Le schéma (28.1) et l'ingestion (28.2) **acceptent DÉJÀ** ce type d'item (la migration liste `applications` comme `type` valide ; `normalizeItems()` ne whiteliste aucun `type`). 31.2 = **projeter** ces ordres dans l'`applications` désiré servi à l'agent — ZÉRO migration, ZÉRO changement d'ingestion.
>
> **« Un tuyau, deux outils »** (architecture desired-state, FR21). L'amont ne pousse PAS un canal WPKG séparé : il AJOUTE des `app_id` cibles à l'ensemble `applications` (type `aggregate`/portée `machine`) déjà projeté par 27.5. L'agent déclenche WPKG sur cet ensemble unifié ; WPKG (moteur déclaratif, `<check>`) garantit l'idempotence réelle de l'install. SE5 ne réimplémente AUCUNE recette d'install.

> **⚠️ Décision Henri (héritée 29.1/31.1) — pas de compat ascendante exigée** : aucun environnement de prod à préserver ; seul invariant intangible = l'enrôlement controlHub + le contrat agent figé. Les garde-fous « non-régression » visent le **bon design** (standalone NFR3 byte-identique, golden/`ContractV1` intacts), pas la rétrocompat d'appelants exotiques.

## Contexte du code (constat vérifié)

**Le contrat porte déjà les ordres d'install — aucune construction de schéma** :
- `controlhub_contract_items` (28.1) : colonnes `type`, `key`, `value`, `enforcement_state` (`locked`/`permissive`/`absent`), `target_type` (`instance`/`label`), `target_label` (NOT NULL DEFAULT `''`). La migration `database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php` **liste explicitement `applications` parmi les `type` valides**. [Source: rapport schéma — controlhub_contract_items]
- `ControlHubContractIngestionService::normalizeItems()` [app/Services/ControlHub/ControlHubContractIngestionService.php:265] **ne whiteliste PAS le `type`** (toute string passe ; seuls `enforcement_state` et `target_type` sont validés comme enums). ⇒ un item `{type:'applications', key:'firefox', enforcement_state:'locked', target_type:'instance'}` est **déjà ingestible et persisté** par 28.2.

**La machinerie desired-state est déjà à 90 % en place** :
- `ApplicationsStateProvider` [app/Services/Agent/Providers/ApplicationsStateProvider.php:113] projette l'ensemble cible WPKG d'un poste (type `applications`, **`aggregate`**, portée **`machine`**, maille `Broadcast`, payload **`{app_id, name}`**). Il construit un ensemble d'`app_id` (`computePackages()` non cachée ∪ apps défaut parc [:128]), le **dédoublonne + trie** [:140-147], puis **hydrate** les libellés via `Application::whereIn('app_id', …)` [:157], en SKIPant+loguant un `app_id` sans ligne `Application` [:170-178].
- `UpstreamAwareProvider` (28.3) [app/Services/ControlHub/Resolution/UpstreamAwareProvider.php:84] **enrobe TOUS les providers** (y compris `ApplicationsStateProvider`, cf. `AgentServiceProvider::class` [app/Providers/AgentServiceProvider.php:148-152]) : `itemsFor() = candidats_internes ∪ candidatesFor(type, scope, ctx)`. **Pour `applications`, ce décorateur est aujourd'hui un no-op** : `UpstreamContractSource` n'a **aucun adaptateur** pour `type='applications'` → les items `applications` du contrat sont **chargés mais ignorés** à la répartition [UpstreamContractSource.php:341-347].
- `UpstreamContractSource::ensureResolved()` [app/Services/ControlHub/Resolution/UpstreamContractSource.php:309] charge en **UNE requête** les items non-`absent` (`whereIn enforcement_state [locked, permissive]` [:332], `whereIn target_type [instance, label]` [:327]), **court-circuit NFR3** si pas de contrat actif [:320]. `labelsCarriedBy($ctx)` [:277] résout les `controlhub_label` des `WorkstationGroup` du poste (mémoïsé, anti-N+1), réutilisé par 30.4.
- `StateCompiler::selectAggregate()` [app/Services/Agent/StateCompiler.php:206] **dédoublonne par contenu** (payload canonicalisé [:235]) : deux candidats de **même payload** (ex. app résolue localement ET ordonnée amont) ne donnent **qu'UN** item → hash stable. C'est l'assise de l'idempotence (AC2).

**Le « trou » exact de FR6** = il manque le **pont entre les items `type='applications'` du contrat et l'ensemble `applications` du poste**. Il faut faire CONTRIBUER les `app_id` ordonnés (cible `instance` ∪ labels portés) à l'ensemble que `ApplicationsStateProvider` hydrate, en réutilisant la résolution de cible 30.4 et la dédup aggregate du compilateur.

## Acceptance Criteria

1. **Given** un contrat amont **actif** porte un ordre d'install `{type:'applications', key:<app_idA>, enforcement_state:'locked', target_type:'instance'}`, et `app_idA` existe comme `Application` locale,
   **When** l'agent fait son check-in (`GET /api/v1/agent/state`) pour un poste P quelconque,
   **Then** la portée **`machine`** de l'état désiré contient un item `{type:'applications', semantics:'aggregate', payload:{app_id:'A', name:…}, hash:…}` pour `app_idA` (l'app cible figure dans l'état désiré renvoyé à l'agent).

2. **Given** un ordre d'install amont `target_type:'label'`, `target_label:'salle-info'`,
   **When** on compile l'état d'un poste **membre** d'un `WorkstationGroup` portant `controlhub_label='salle-info'` (mapping 30.2),
   **Then** l'app ordonnée figure dans son `applications` désiré ;
   **And** pour un poste **ne portant pas** ce label, l'app ordonnée **n'y figure pas** (réutilisation stricte de `labelsCarriedBy()` 30.4 — pas de spécificité inter-parcs).

3. **Given** l'app `app_idA` est **déjà** affectée localement au poste (résolue par `WorkstationPackagesResolver`) **ET** ordonnée par l'amont,
   **When** l'état est compilé,
   **Then** l'`applications` désiré contient **exactement UN** item pour `app_idA` (dédup aggregate par contenu — payload `{app_id, name}` identique des deux sources), **And** le hash d'état est **stable** sur deux compilations (`travel()`) ⇒ **idempotence** : l'agent réconcilie sans déclencher de réinstallation (la présence dans l'ensemble cible, non un acte impératif, pilote WPKG `<check>`).

4. **Given** une instance SE5 **sans contrat actif** (standalone) **ou** un contrat actif **sans aucun item `type='applications'`**,
   **When** l'état d'un poste est compilé,
   **Then** l'`applications` désiré est **strictement inchangé** vs pré-31.2 (byte-identique) : **aucune** lecture de contrat ne modifie l'ensemble, et **aucune** requête `controlhub_contract_items` n'est émise quand il n'y a pas de contrat actif (court-circuit NFR3 prouvé par query log).

5. **Given** un ordre d'install amont,
   **When** le contrat est ré-reçu à l'identique (28.2 no-op) **ou** l'état recompilé,
   **Then** l'ensemble cible et le hash sont **identiques** (NFR4 — le projecteur lit l'état persisté, pas un payload volatil ; ordre de l'union déterministe : tri `strcasecmp` des `app_id`).

6. **Given** le contrat agent figé `se5.desired-state/v1` (golden files, `ContractV1`),
   **When** 31.2 est livrée,
   **Then** **aucun** champ/clé/type du wire format n'est ajouté ni modifié : l'`applications` reste `{type, semantics, payload:{app_id,name}, hash}` (4 clés). Les golden `tests/Fixtures/Agent/state.v1.json`, `ContractV1Test`, `StateCompilerTest` **passent inchangés** (les fixtures golden n'ont pas de contrat actif ⇒ aucun ordre injecté).

7. **Given** la suite de tests HÔTE (php8.4 + sqlite),
   **When** elle s'exécute,
   **Then** sont verts : injection instance (AC1), ciblage label porté / non porté (AC2), dédup + idempotence/travel (AC3), standalone & contrat-sans-app-item court-circuit zéro requête (AC4), déterminisme (AC5), golden/ContractV1 intacts (AC6) — **sans régression** des suites `Agent`/`Application*`/`UpstreamContract*`/`ControlHubContract` existantes.

## Tasks / Subtasks

- [x] **T0 — Acter le cadrage « zéro schéma » (preuve, pas construction)** (AC: #1)
  - [x] Vérifier (et noter en Dev Notes) qu'un item `{type:'applications', key:<app_id>, enforcement_state:'locked', target_type:'instance'|'label'}` est **persisté tel quel** par `ControlHubContractIngestionService::ingest()` (test d'ingestion ad hoc OU réutilisation du harnais 28.2). **NE PAS** ajouter de table, de modèle, d'enum, de section de payload, ni de validation `type` (doublon interdit — la flexibilité du `type` est un choix 28.1). Le seul livrable de cette tâche est la **preuve** + la décision actée.

- [x] **T1 — Accesseur d'ordres d'install dans `UpstreamContractSource`** (AC: #1, #2, #4, #5)
  - [x] Ajouter `public function orderedApplicationAppIds(TargetContext $ctx): array` à `app/Services/ControlHub/Resolution/UpstreamContractSource.php` — **3ᵉ accesseur lecture-seule** à côté de `candidatesFor()` et `lockedLabelCandidates()` (30.5), même discipline.
  - [x] Dans `ensureResolved()` [:340-386], AJOUTER une branche **avant** la répartition par adaptateur : si `$item->type === Application::TYPE_APPLICATIONS`, indexer `$item->key` (= `app_id`) dans deux structures dédiées — `$applicationOrdersInstance[]` (si `target_type=instance`) et `$applicationOrdersByLabel[$item->target_label][]` (si `target_type=label`, avec la **garde symétrique** `target_label` non vide, iso [:376-378]). **Réutiliser la requête items existante** (zéro requête supplémentaire). L'item `applications` n'a **pas** d'adaptateur ⇒ il continue d'être ignoré par la répartition `grouped`/`groupedByLabel` (pas de double comptage côté `candidatesFor`).
  - [x] `orderedApplicationAppIds($ctx)` : `ensureResolved()` ; **court-circuit NFR3** — si `$applicationOrdersInstance === [] && $applicationOrdersByLabel === []`, retour `[]` immédiat (jamais d'appel à `labelsCarriedBy()` / requête WG). Sinon : `instance ∪ (app_id des labels portés par le poste via labelsCarriedBy($ctx))`, **dédupliqué + trié `strcasecmp`** (déterminisme NFR4, iso le tri de `ApplicationsStateProvider`). Retourne `list<string>` d'`app_id`.
  - [x] **Sémantique d'enforcement (décision D1, documenter)** : `applications` est **`aggregate`** — il n'y a **aucune valeur à relaxer/verrouiller**, seule la **présence** dans l'ensemble compte. `ensureResolved()` ne charge déjà que `locked`+`permissive` (l'`absent` est exclu en amont [:332]) : les deux états signifient **« app présente »** (install). On **n'introduit aucune** distinction locked/permissive ici. **Le RETRAIT n'a PAS besoin d'être construit** : `ProfilesXmlController` génère un `profiles.xml` PAR POSTE et WPKG **synchronise** le poste sur ce profil ⇒ une app qui quitte l'union (l'amont cesse de l'ordonner ET aucune source locale ne la réclame) quitte `profiles.xml` ⇒ est désinstallée **par omission**. On ne pose donc **aucun** canal `<remove>`/`absent` ici (cf. Dev Notes D1).
  - [x] PHPDoc : garde-fou R3 (aucun « central » ; vocabulaire « amont »/`Upstream`), caveat long-running (mémoïsation par-conteneur sûre PHP-FPM ; sous Octane brancher `ControlHubContractChanged`, même caveat que les autres accesseurs), rappel `key == applications.app_id`.

- [x] **T2 — `ApplicationsStateProvider` : unionner les ordres amont à l'ensemble cible** (AC: #1, #2, #3, #4)
  - [x] Injecter `UpstreamContractSource` dans le constructeur de `app/Services/Agent/Providers/ApplicationsStateProvider.php` (2ᵉ dépendance, à côté de `WorkstationPackagesResolver`).
  - [x] Dans `itemsFor($ctx)` [:113], récupérer `$orderedAppIds = $this->source->orderedApplicationAppIds($ctx);` et les **concaténer à l'union** existante (à côté de `$resolvedAppIds` et `$parcDefaultAppIds`, l.140-147) **AVANT** la dédup/tri/hydratation. **Zéro autre changement** : la dédup, le tri `strcasecmp`, l'hydratation `Application::whereIn('app_id')` et le SKIP+warning d'un `app_id` sans ligne `Application` [:170-178] restent **inchangés** ⇒ payload `{app_id, name}` **identique** quelle que soit la source ⇒ dédup aggregate du compilateur naturelle (AC3).
  - [x] **Trou D4 (app ordonnée absente de l'inventaire local)** : le SKIP+warning 27.5 est **conservé tel quel en 31.2** (l'ordre visant un `app_id` sans ligne `Application` n'est pas livré, mais journalisé). Ce trou est **comblé par la Story 31.3** (approvisionnement auto de l'`Application` depuis le dépôt SambaEdu, avant entrée dans l'ensemble). 31.2 **ne** matérialise **aucune** `Application` (cf. Dev Notes D4, réf. 31.3).
  - [x] **Garde-fou anti-double-injection (documenter)** : ne **PAS** enregistrer d'`UpstreamPayloadAdapter` pour `applications`. Le décorateur `UpstreamAwareProvider` qui enrobe `ApplicationsStateProvider` reste un **no-op** pour ce type (aucun adaptateur). L'union amont passe **uniquement** par cet accesseur dédié (le `ApplicationsStateProvider` résout un ENSEMBLE mono-sortie, pas des candidats par-maille — cf. son note D4 ; le folder d'`app_id` au niveau ensemble est cohérent avec sa nature). Si un futur dev ajoute un adaptateur `applications`, il y aurait **double injection** : l'interdire explicitement en commentaire dans `AgentServiceProvider` (binding `UpstreamContractSource`).
  - [x] **Maille (documenter)** : les `app_id` amont sont émis comme tous les autres candidats `applications` — maille `Broadcast`. `applications` étant `aggregate`, la maille **n'arbitre rien** (union, pas de précédence) : le tier `Upstream` rang -1 est **sans objet** ici (la présence dans l'union EST le but, il n'y a pas de réglage local concurrent à battre). Cohérent avec la note D4 existante du provider.

- [x] **T3 — Câblage conteneur + ajustement des constructions de test** (AC: #1)
  - [x] Vérifier `AgentServiceProvider` [app/Providers/AgentServiceProvider.php:209-216] : `ApplicationsStateProvider` est construit via `$app->make(...)` ⇒ l'**auto-wiring** résout le 2ᵉ argument `UpstreamContractSource` (singleton déjà bindé [:111]). Ajouter le param explicitement seulement si l'auto-wiring ne suffit pas ; sinon, juste un commentaire pointant le nouveau pont 31.2.
  - [x] Mettre à jour les constructions **manuelles** du provider en test : `tests/Unit/Services/Agent/ApplicationsStateProviderTest.php` instancie `new ApplicationsStateProvider(new WorkstationPackagesResolver())` [setUp] — ajouter l'argument `UpstreamContractSource` (ou `app(UpstreamContractSource::class)`). Vérifier les autres instanciations directes éventuelles.

- [x] **T4 — Tests HÔTE (php8.4 + sqlite)** (AC: #1–#7)
  - [x] `tests/Feature/ControlHub/UpstreamInstallOrderTest.php` (Feature, car compile l'état via le compilateur / le provider décoré). Harnais : factories `ControlHubContract::factory()->create()` (= `link_state=active`), `ControlHubContractItem::factory()->create([...])` / `->forLabel('salle-info')` / `->permissive()` / `->absent()` (cf. `UpstreamContractResolutionTest`), `WpkgSchemaBootstrapper::bootstrap()` (déjà augmenté en 31.1 de `controlhub_contracts`/`controlhub_contract_catalog_apps` — **vérifier/ajouter `controlhub_contract_items`** s'il manque ; le bootstrapper NE combine PAS `RefreshDatabase`, cf. note `ApplicationsStateProviderTest`), `WorkstationGroupObserver::disableSync()`.
    - **AC1 (instance)** : item `applications`/`locked`/`instance` key=`firefox` + `Application{app_id:firefox}` ⇒ `ApplicationsStateProvider::itemsFor()` (ou `StateCompiler::compile()`) émet un item `applications` `{app_id:firefox, name:…}` en portée `machine`.
    - **AC2 (label)** : item `forLabel('salle-info')` ; poste dans WG `controlhub_label='salle-info'` ⇒ app présente ; poste hors label ⇒ absente.
    - **AC3 (dédup + idempotence)** : `firefox` affecté localement (pivot poste/groupe) **ET** ordonné amont ⇒ **un seul** item `applications` pour `firefox` après `StateCompiler::compile()` (assert count == 1 pour cet app_id) ; `travel()` / 2 compilations ⇒ `hashState()` identique.
    - **AC4 (standalone + court-circuit)** : aucun contrat ⇒ ensemble byte-identique au baseline 27.5 ; via `DB::enableQueryLog()`, **zéro** requête `controlhub_contract_items` sans contrat actif. Contrat actif **sans** item `applications` (que des `registry`) ⇒ `orderedApplicationAppIds()` court-circuite (`[]`), ensemble inchangé, `labelsCarriedBy`/requête WG non émise.
    - **AC5 (déterminisme)** : ré-ingestion identique (28.2 no-op) ⇒ même ensemble/hash ; ordre de l'union stable (tri `strcasecmp`).
    - **AC6 (golden intacts)** : exécuter `ContractV1Test` + `StateCompilerTest` + le test golden `state.v1.json` ⇒ **verts inchangés** (aucun contrat dans les fixtures).
    - **Accesseur unitaire** : `orderedApplicationAppIds()` sur 3 états (standalone `[]` ; contrat sans item app `[]` ; contrat avec instance+label ⇒ union dédup triée), + `absent` **non** projeté (exclu).
  - [x] **Pièges** : SQLite n'applique pas varchar/enum PG → tester des **décisions** (présence/absence/count/hash), pas des bornes de colonne. Matcher sur `app_id` (string), jamais l'`id` numérique. [Source: mémoire — sqlite_tests_no_varchar_enforcement]

- [x] **T5 — Runbook QA** (AC: #1–#5)
  - [x] **Append** `## Section 15 — Déclenchement d'install en désir d'état (Story 31.2)` à `docs/qa/domains/controlhub-contract.md` (append-only, sections numérotées stables ; dernière = Section 14 / 31.1). Scénarios manuels : poser un item `applications`/`locked`/`instance` (toute la flotte) puis `curl GET /api/v1/agent/state` d'un poste enrôlé ⇒ l'`app_id` figure dans `machine.applications` ; ciblage `label` (poste portant vs ne portant pas le label) ; app déjà affectée localement ⇒ un seul item (jq count) ; standalone ⇒ inchangé ; rupture future = libération (renvoyer Story 32.1, hors scope).
  - [x] Mettre à jour la **ligne cumulative** du domaine dans `docs/qa/README.md` (mention Story 31.2, suffixe la ligne 31.1 — convention append des libellés cumulés).

- [x] **T6 — Validation finale & garde-fous** (AC: #4, #6)
  - [x] `php artisan test --filter "Agent|Application|UpstreamContract|UpstreamInstallOrder|ControlHubContract|ContractV1|StateCompiler"` sur HÔTE → vert, 0 régression.
  - [x] **R3** : `grep -rin central` sur les fichiers livrés ⇒ uniquement en-têtes garde-fou (aucun identifiant/message « central »).
  - [x] **NFR3** : prouvé par test (query log zéro requête sans contrat) ET par construction (court-circuit `ensureResolved()` + court-circuit `orderedApplicationAppIds()`).
  - [x] **Contrat agent figé** : `git diff` ⇒ **aucune** ligne touchant `StateContract.php`, `StateHasher.php`, `tests/Fixtures/Agent/*.json`, `ContractV1`. Si l'une est touchée, STOP — preuve explicite requise (la story ne le prévoit pas).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 31.2

**DANS** : accesseur `UpstreamContractSource::orderedApplicationAppIds()` (indexation des items `type='applications'` lors de la passe items existante, cible instance ∪ label porté, court-circuit NFR3) ; union de ces `app_id` dans l'ensemble cible d'`ApplicationsStateProvider` (avant hydratation/dédup) ; tests ; runbook QA.

**HORS** (ne pas déborder) :
- **Canal de retrait/désinstall piloté** : AUCUN. Le retrait se fait **déjà par omission** via la synchro WPKG (`ProfilesXmlController` génère un `profiles.xml` par poste ; WPKG synchronise → une app hors profil est désinstallée). On ne construit donc **ni** `<remove>`, **ni** projection de l'`enforcement_state='absent'` (que `ensureResolved()` exclut déjà). Le SEUL cas que `absent` ajouterait n'est PAS « le retrait » (couvert) mais « l'amont **INTERDIT** une app qu'une source LOCALE réclame encore » (ex. défaut-parc) = **soustraction** de l'union → sémantique d'**interdiction/override**, distincte de FR6 (install), reléguée à un FR « interdiction » ultérieur. Voir D1.
- **Schéma / ingestion** : ZÉRO migration, ZÉRO modèle, ZÉRO enum, ZÉRO section de payload, ZÉRO validation `type`. Le contrat (28.1) + l'ingestion (28.2) **acceptent déjà** les items `type='applications'`. Réécrire serait un doublon (interdit, cf. 30.1 « preuve, pas construction »). *(L'**extension** du catalogue pour porter la source du dépôt = Story 31.3, pas 31.2.)*
- **Interdiction / filtrage d'install WPKG côté SE5** : AUCUN. **controlHub devient la SOURCE du dépôt WPKG** et filtre **À LA SOURCE** ce qu'il expose (catalogue = vue filtrée du dépôt SambaEdu) ⇒ un enforcement de bornage côté SE5 sur le canal d'install devient **superflu**. 31.2 ne pose donc **aucun** filtrage/interdiction d'install. L'ordre d'install amont **n'est PAS** filtré par `UpstreamCatalogResolver` (31.1) — l'amont **est** l'autorité, le filtrage est à la source, pas un enforcement SE5. **Conséquence (note de cadrage, hors 31.2)** : la couche `AppProfileService::assertApplicationsInUpstreamCatalog` (31.1) devient **redondante** dès lors que le filtrage est à la source — à traiter **séparément** (ne PAS toucher les fichiers/code de 31.1, en review).
- **Recettes WPKG / packaging** : WPKG reste le moteur déclaratif non absorbé (FR21). SE5 ne livre que l'**ensemble d'`app_id`** (clé de déclenchement + d'inventaire), jamais `<check>/<install>`/versions. L'idempotence d'install **réelle** est garantie par WPKG ; l'idempotence d'**état** (un seul item, hash stable) par la dédup aggregate du compilateur.
- **Authoring controlHub** (UI de composition du contrat côté central) — hors repo SE5.
- **Release à la rupture du lien (FR7)** → **Story 32.1**. En 31.2, `link_state='severed'` ⇒ `ControlHubContract::active()`/`ensureResolved()` ne voient pas de contrat actif ⇒ `orderedApplicationAppIds()` renvoie `[]` ⇒ ordres levés **automatiquement** (rien à coder ici).

### Décisions de cadrage (pré-tranchées)

- **D1 — Ordre d'install = item `type='applications'`, non-`absent` = « app présente » ; le retrait existe déjà par omission**. `aggregate` ⇒ pas de valeur à relaxer : `locked` et `permissive` signifient tous deux « installer/maintenir présente ». Aucune distinction d'enforcement projetée. **Le retrait n'exige AUCUN second canal** : `profiles.xml` est synchronisé par WPKG (une app qui quitte l'union poste — amont + défaut-parc/groupe/profil — quitte le profil et est désinstallée). `absent` ne servirait qu'à une sémantique d'**interdiction** (soustraire une app qu'une source locale réclame encore) — distincte de FR6, hors scope (FR « interdiction » ultérieur).
- **D2 — Cible = `instance` ∪ labels portés** (réutilisation STRICTE de 30.4 : `labelsCarriedBy()`, `controlhub_label` par NOM sans FK, garde symétrique `target_label`/`controlhub_label` non vide). Pas de spécificité inter-parcs (aggregate de toute façon).
- **D3 — Pont au niveau ENSEMBLE, pas via adaptateur**. `ApplicationsStateProvider` résout un ensemble mono-sortie (note D4 existante) : on lui ajoute les `app_id` amont **avant** hydratation → payload `{app_id, name}` **identique** (hydratation unique) → dédup aggregate naturelle (AC3) + gestion du `app_id` sans ligne (warning/skip) **gratuite**. L'alternative « adaptateur `applications` + décorateur » est **écartée** : `toPayload()` est pur (pas d'accès DB) ⇒ le `name` ne pourrait pas être hydraté depuis l'`Application` locale ⇒ payloads divergents ⇒ **doublon** d'item `applications` pour le même `app_id` (hash instable, inventaire pollué). **NE PAS** enregistrer d'adaptateur `applications` (double injection).
- **D4 — App ordonnée absente de l'inventaire local = skip+warn en 31.2, gap comblé par 31.3**. Si l'`app_id` ordonné n'a pas de ligne `Application`, l'hydratation 27.5 le **skip+warn** (comportement inchangé) : l'ordre n'est pas livré mais journalisé. **Story 31.3** comble ce trou en **approvisionnant automatiquement** l'`Application` depuis le **dépôt SambaEdu** (la source que controlHub référence) avant qu'elle entre dans l'ensemble désir d'état. 31.2 **ne matérialise aucune** `Application` (pas d'« auto-stub »). → **voir Story 31.3**.

### Patrons de référence à IMITER (ne rien réinventer)

- **`UpstreamContractSource::candidatesFor()` / `lockedLabelCandidates()`** [app/Services/ControlHub/Resolution/UpstreamContractSource.php:192,240] — patron d'accesseur lecture-seule mémoïsé + court-circuit NFR3 sur `groupedByLabel===[]`. `orderedApplicationAppIds()` en est le **3ᵉ jumeau**.
- **`UpstreamContractSource::ensureResolved()`** [:309-386] — passe items UNIQUE + indexation `grouped`/`groupedByLabel` + garde symétrique `target_label` non vide [:376]. AJOUTER l'indexation `applications` **dans** cette passe (zéro requête de plus).
- **`UpstreamContractSource::labelsCarriedBy()`** [:277] — résolution mémoïsée des labels portés (anti-N+1). Réutiliser tel quel.
- **`ApplicationsStateProvider::itemsFor()`** [app/Services/Agent/Providers/ApplicationsStateProvider.php:113-199] — union d'`app_id` (résolu ∪ défaut parc) → dédup → tri `strcasecmp` → hydratation → candidats `Broadcast`. AJOUTER `orderedApplicationAppIds()` à l'union d'entrée, **rien d'autre**.
- **`StateCompiler::selectAggregate()` / `contentKey()`** [app/Services/Agent/StateCompiler.php:206,235] — dédup par payload canonicalisé (assise idempotence AC3). Ne PAS y toucher.
- **`UpstreamContractResolutionTest`** [tests/Feature/ControlHub/UpstreamContractResolutionTest.php] — harnais factories contrat/items (`->forLabel()`, `->permissive()`, `->absent()`). **`ApplicationsStateProviderTest`** [tests/Unit/Services/Agent/ApplicationsStateProviderTest.php] — harnais `WpkgSchemaBootstrapper` (sans `RefreshDatabase`).

### Garde-fous projet CRITIQUES

- **NFR3 — Standalone byte-identique (BLOQUANT design)** : sans contrat actif (ou sans item `applications`), `orderedApplicationAppIds()` renvoie `[]`, l'union d'`ApplicationsStateProvider` est inchangée, **zéro** requête `controlhub_contract_items`. Tester (AC4, query log).
- **NFR4 — Idempotence/déterminisme** : ré-réception du contrat ⇒ même ensemble (lecture de l'état persisté, pas un payload) ; union triée `strcasecmp` ⇒ ordre/hash stables ; dédup aggregate ⇒ doublon de source collapse. Tester (AC3/AC5, `travel()`).
- **Contrat agent figé (BLOQUANT)** : `se5.desired-state/v1` (golden `state.v1.json`, `ContractV1`, `StateHasher`) **INTACT**. 31.2 ajoute une **SOURCE d'`app_id`**, jamais un champ/type du wire format. L'`applications` reste `{type, semantics, payload:{app_id,name}, hash}`. [Source: epics-agent-desired-state.md#FR5]
- **R3 — Vocabulaire (BLOQUANT)** : aucun « central » dans identifiant/commentaire/message ; « amont »/`Upstream`/`ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
- **NFR1 — prérequis Epic 31** : 29.1 (Gate `wpkg.*` scopé) — non touché ici (31.2 lit, ne pilote pas le canal d'écriture refnum).
- **Tests HÔTE uniquement** (php8.4 + `pdo_sqlite`), **jamais la VM** (sans `pdo_sqlite` ; aucune migration ici de toute façon). [Source: mémoire — phpunit_test_env_host_vs_vm]
- **Racine = projet Laravel** (artisan/app à la racine). [Source: mémoire — root_is_laravel]

### Project Structure Notes

- **Nouveaux** : `tests/Feature/ControlHub/UpstreamInstallOrderTest.php` (+ éventuels cas unitaires de l'accesseur).
- **Modifiés** : `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (+`orderedApplicationAppIds()` + indexation `applications` dans `ensureResolved()` + 2 propriétés d'index + import `Application`) ; `app/Services/Agent/Providers/ApplicationsStateProvider.php` (+dépendance `UpstreamContractSource` + union de l'accesseur) ; éventuellement `app/Providers/AgentServiceProvider.php` (commentaire/explicite si auto-wiring insuffisant + garde anti-adaptateur `applications`) ; `tests/Unit/Services/Agent/ApplicationsStateProviderTest.php` (construction du provider à 2 args) ; `docs/qa/domains/controlhub-contract.md` (Section 15, append) ; `docs/qa/README.md` (ligne cumulative) ; éventuellement `tests/Support/WpkgSchemaBootstrapper.php` (table `controlhub_contract_items` si absente).
- **Aucune** migration, **aucun** modèle/enum nouveau, **aucun** changement de contrat agent/golden.

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 31.2] — AC d'origine (les 2 Given/When/Then : ordre cible+app → état désiré ; déjà installé → pas de réinstall).
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#FR6] — déclenchement d'install en désir d'état (idempotence/reprise via check-in agent).
- [Source: _bmad-output/planning-artifacts/handoff-controlhub-contrat-manage.md §7 l.124] — « ordres de déclenchement d'install (cible + app) » dans le contrat d'interface.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#FR21] — applications = WPKG déclenché par l'agent, moteur conservé (« un tuyau, deux outils »).
- [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php:192,240,277,309-386] — accesseurs, passe items, labels portés, garde symétrique.
- [Source: app/Services/ControlHub/Resolution/UpstreamAwareProvider.php:84] ; UpstreamPayloadAdapter.php:38 — décorateur (no-op pour `applications`) + bridge par adaptateur (écarté pour applications, D3).
- [Source: app/Services/Agent/Providers/ApplicationsStateProvider.php:113-199] — ensemble cible WPKG + hydratation + payload `{app_id,name}`.
- [Source: app/Services/Agent/StateCompiler.php:206-238] — dédup aggregate par contenu (idempotence).
- [Source: app/Providers/AgentServiceProvider.php:111-113,148-216] — binding `UpstreamContractSource` (1 adaptateur registry) + providers enrobés.
- [Source: app/Models/Application.php:62] — `TYPE_APPLICATIONS = 'applications'`.
- [Source: app/Services/ControlHub/ControlHubContractIngestionService.php:265] — `normalizeItems()` sans whitelist de `type`.
- [Source: _bmad-output/implementation-artifacts/31-1-borner-install-refnum-catalogue-amont.md] — story sœur (bornage FR5) ; `UpstreamCatalogResolver`, `WpkgSchemaBootstrapper` augmenté.
- [Source: mémoires projet — agent_desired_state_direction, drift_policy_strict_only, phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement, root_is_laravel, state_precedence_logical_over_physical].

## Dépendances

- **Amont (fonctionnelles, code sur branche `worktree-contract-CH`)** :
  - **31.1** (`review`) — story sœur (bornage catalogue) ; harnais de tests contrat (`WpkgSchemaBootstrapper` augmenté), `UpstreamCatalogResolver`. Pas de dépendance de code dure (31.2 ne lit pas le catalogue), mais même domaine/runbook.
  - **28.1** (`review`) — `controlhub_contract_items` (`type`/`key`/`enforcement_state`/`target_type`/`target_label`), modèles/enums. **Fondation** : porte les ordres d'install sans construction.
  - **28.2** (`review`) — `ControlHubContractIngestionService` ingère les items `type='applications'` (sans whitelist) idempotemment.
  - **28.3** (`review`) — `UpstreamContractSource` + `UpstreamAwareProvider` (décorateur, court-circuit NFR3, maille `Upstream`). Socle réutilisé/étendu.
  - **30.4** (`review`) — résolution d'un item ciblant un **label** : `groupedByLabel`/`labelsCarriedBy()`. **Réutilisé** pour les ordres `target_type=label`.
  - **27.5** (`done`/livré) — `ApplicationsStateProvider` (type `applications` figé, payload `{app_id,name}`, dédup). Point d'extension.
  - **23.x** — `StateCompiler` (aggregate/dédup), contrat v1 figé, `StateController` (check-in).
  - **NFR1 = 29.1** (`review`) — prérequis d'Epic (Gate `wpkg.*` scopé) ; non touché ici.
  - *(Toutes en `review`/livré, code sur la branche unifiée ; pas de blocage dur — 31.2 lit/étend l'existant.)*
- **Aval** :
  - **Story 31.3** (FR6-complétude) — **approvisionnement auto** de l'`Application` depuis le dépôt SambaEdu : **comble le gap D4** de 31.2 (un ordre visant un `app_id` hors inventaire local n'est plus skip+warn mais matérialisé puis livré). 31.2 = le déclencheur ; 31.3 le complète.
  - **Story 32.1** (FR7) — release à la rupture : `severed` ⇒ `active()` null ⇒ ordres levés automatiquement (31.2 ne code rien de spécifique, 32.1 valide la levée). **Clôt l'Epic 31.**
- **Réutilise** : domaine ControlHub (28/29/30) + canal applications/WPKG desired-state (27.5) — livrés.

## Testing

- **Cible : HÔTE** (php8.4 + `pdo_sqlite`), factories contrat/items + `WpkgSchemaBootstrapper` (sans `RefreshDatabase`, cf. note `ApplicationsStateProviderTest`). **Jamais la VM.**
- Filtre : `php artisan test --filter "Agent|Application|UpstreamContract|UpstreamInstallOrder|ControlHubContract|ContractV1|StateCompiler"`.
- Couverture obligatoire : AC1 (injection instance), AC2 (label porté/non porté), AC3 (dédup + idempotence/`travel()`), AC4 (standalone & contrat-sans-app-item, court-circuit zéro requête via query log), AC5 (déterminisme ré-ingestion), AC6 (golden/`ContractV1`/`StateCompilerTest` inchangés).
- **Pièges** : SQLite n'applique pas varchar/enum PG → tester décisions (présence/absence/count/hash), pas bornes de colonne. Matcher sur `app_id` (string). Le bootstrapper schéma doit porter `controlhub_contract_items` (vérifier/ajouter).
- ⚠️ **VM** : aucune migration ici.

## Recommandation Modèle Dev

**`opus`.**

Justification : story **piégeuse côté invariants** malgré une surface de diff réduite. Les écueils sont des régressions silencieuses sur des contrats figés et des garde-fous transverses, pas du volume : (1) **ne PAS toucher** le wire format `se5.desired-state/v1` (golden/`ContractV1`/`StateHasher`) alors qu'on injecte une nouvelle source dans un type existant ; (2) **idempotence d'état** réelle = comprendre que la dédup aggregate par payload exige une **identité de payload** `{app_id, name}` (d'où le choix « union au niveau ensemble + hydratation unique » plutôt que l'adaptateur pur, qui produirait un doublon) — un raisonnement de conception, pas une recette ; (3) **court-circuit NFR3** byte-identique (zéro requête sans contrat) à prouver sur un provider déjà spécial (mono-sortie WPKG) ; (4) cadrage « preuve, pas construction » (ne RIEN ajouter au schéma/ingestion, ne pas enregistrer d'adaptateur, ne PAS construire de canal de retrait — il existe par omission via la synchro `profiles.xml`/WPKG —, ne PAS poser de filtrage/interdiction d'install côté SE5 — filtrage à la source controlHub-as-depot —, ne PAS matérialiser d'`Application` — gap D4 délégué à 31.3). Le jugement requis porte sur **ce qu'il ne faut PAS faire** et sur la préservation du contrat agent — sensibilité d'architecte. Le dev-cycle routera la review vers le modèle opposé (sonnet/fable) pour une 2ᵉ paire d'yeux sur les angles morts golden/NFR3.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story BMAD).

### Debug Log References

- `CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/UpstreamInstallOrderTest.php` → 11 passed (20 assertions).
- `CACHE_DRIVER=array php artisan test --filter "Agent|Application|UpstreamContract|UpstreamInstallOrder|ControlHubContract|ContractV1|StateCompiler"` → **720 passed, 22 skipped, 0 failed** (2714 assertions). Les 22 skips sont les habituels gardés par extension (ext-zip/imagick/LDAP), pré-existants.
- Piège query-log élucidé (AC4 court-circuit label) : `computePackages()` ET `TargetContext::for()` requêtent déjà `workstation_groups` légitimement → on discrimine la requête de `labelsCarriedBy()` sur la colonne `controlhub_label` (jamais lue par les deux autres). Assertion finale `controlhub_label` queries == 0.

### Completion Notes List

- **T0 (preuve, pas construction)** : confirmé qu'un item `{type:'applications', key:<app_id>, enforcement_state:'locked'|'permissive', target_type:'instance'|'label'}` est persisté tel quel — la migration 28.1 liste `applications` comme `type` valide et `normalizeItems()` (28.2) ne whiteliste pas le `type`. AUCUNE table/modèle/enum/validation `type`/section payload ajoutée. Les factories existantes (`ControlHubContractItemFactory`) acceptent `type=Application::TYPE_APPLICATIONS` sans modification → preuve actée par le harnais de `UpstreamInstallOrderTest`.
- **T1** : `UpstreamContractSource::orderedApplicationAppIds(TargetContext): array` ajouté (3ᵉ accesseur lecture-seule mémoïsé). Indexation des items `type='applications'` AJOUTÉE **dans la passe `ensureResolved()` existante** (zéro requête de plus), AVANT la répartition par adaptateur, avec `continue` (l'item `applications` n'a aucun adaptateur → ne peuple ni `grouped` ni `groupedByLabel`, zéro double comptage). Deux propriétés d'index : `$applicationOrdersInstance` (list) + `$applicationOrdersByLabel` (par `target_label`, garde symétrique `target_label` non vide). Court-circuit NFR3 : `[]` immédiat si aucun ordre (jamais d'appel à `labelsCarriedBy()`). Sinon `instance ∪ labels portés`, dédup + tri `strcasecmp`. Sémantique D1 : `locked`/`permissive` = « app présente », aucune distinction, pas de canal `<remove>`/`absent`.
- **T2** : `ApplicationsStateProvider` reçoit `UpstreamContractSource` en 2ᵉ dépendance de constructeur ; `itemsFor()` UNIONNE `orderedApplicationAppIds($ctx)` à l'ensemble (`->concat($orderedAppIds)`) AVANT dédup/tri/hydratation. Rien d'autre changé : dédup, tri `strcasecmp`, hydratation `Application::whereIn('app_id')` et le SKIP+warning d'un `app_id` sans ligne `Application` (gap D4, comblé par 31.3) restent **inchangés** ⇒ payload `{app_id, name}` identique quelle que soit la source ⇒ dédup aggregate naturelle (AC3).
- **T3** : `AgentServiceProvider` — `ApplicationsStateProvider` construit via `$app->make(...)`, **auto-wiring** résout le 2ᵉ argument (singleton `UpstreamContractSource` déjà bindé). Aucun param explicite requis ; ajout de DEUX commentaires : (a) garde anti double-injection (NE PAS enregistrer d'adaptateur `applications`) au binding `UpstreamContractSource` ; (b) pointeur du pont 31.2 au `make(ApplicationsStateProvider)`. Construction manuelle du provider en test (`ApplicationsStateProviderTest`) mise à 2 arguments (`new UpstreamContractSource([])`).
- **T4** : `tests/Feature/ControlHub/UpstreamInstallOrderTest.php` (11 tests) — AC1 injection instance (+ portée machine via compilateur), AC2 label porté/non porté, AC3 dédup (un seul item) + idempotence `travel()` (hash stable), AC4 standalone zéro requête items + contrat-sans-item-applications court-circuit zéro requête `controlhub_label`, AC5 union triée déterministe, accesseur unitaire 3 états + `absent` non projeté. Harnais `WpkgSchemaBootstrapper` (sans `RefreshDatabase`). `WpkgSchemaBootstrapper` augmenté : table `controlhub_contract_items` (absente) + colonne `workstation_groups.controlhub_label` (nécessaire au ciblage label) + ajout au tearDown. AC6 (golden/ContractV1/StateCompiler) couvert par les suites existantes inchangées (FROZEN_STATE_HASH intact, aucun contrat dans les fixtures).
- **T5** : runbook QA — `docs/qa/domains/controlhub-contract.md` Section 15 (append) + ligne cumulative `docs/qa/README.md`.
- **T6 (garde-fous)** : **NFR3** prouvé (query log : zéro `controlhub_contract_items` sans contrat ; zéro `controlhub_label` quand contrat actif sans item `applications`) ET par construction (double court-circuit). **Contrat agent figé** : `git diff` ne touche AUCUN de `StateContract.php`/`StateHasher.php`/`tests/Fixtures/Agent/*.json`/`ContractV1*` ; `ContractV1Test` + golden verts inchangés (5 tests). **R3** : `grep -in central` sur les fichiers livrés ⇒ uniquement le commentaire garde-fou R3 ajouté (« aucun central ») ; les occurrences `app/.../ApplicationsStateProvider.php:46,216` sont du code 27.5 préexistant (« logique métier centrale », « invariant central du contrat »), hors du diff 31.2. **Aucune migration, aucun modèle/enum nouveau, contrat agent intact.**

### File List

**Modifiés**
- `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (+ import `Application`, + 2 propriétés d'index, + indexation `applications` dans `ensureResolved()`, + accesseur `orderedApplicationAppIds()`)
- `app/Services/Agent/Providers/ApplicationsStateProvider.php` (+ dépendance `UpstreamContractSource`, + union de l'accesseur avant hydratation, + PHPDoc 31.2)
- `app/Providers/AgentServiceProvider.php` (commentaires : garde anti-adaptateur `applications` + pointeur pont 31.2 auto-wiring)
- `tests/Support/WpkgSchemaBootstrapper.php` (+ table `controlhub_contract_items`, + colonne `workstation_groups.controlhub_label`, + tearDown)
- `tests/Unit/Services/Agent/ApplicationsStateProviderTest.php` (construction du provider à 2 args)
- `docs/qa/domains/controlhub-contract.md` (Section 15, append)
- `docs/qa/README.md` (ligne cumulative domaine controlhub-contract — Story 31.2)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (31-2 → review)

**Créés**
- `tests/Feature/ControlHub/UpstreamInstallOrderTest.php`
