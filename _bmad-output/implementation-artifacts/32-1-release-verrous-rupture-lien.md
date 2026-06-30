# Story 32.1: Release des verrous à la rupture du lien

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **refnum**,
I want **qu'à réception d'un signal de rupture du lien de management, SE5 passe le contrat amont en `severed` de façon TRACÉE, ce qui lève AUTOMATIQUEMENT tous les verrous et le bornage catalogue (le refnum retrouve un droit de modification plein), tout en CONSERVANT mes ajouts locaux ET la valeur courante effective des items qui étaient imposés**,
so that **je reprenne la main sur le parc sans rien casser sur les postes (FR7), avec une transition `active → severed` auditable (NFR5)**.

> Story **1ʳᵉ de l'Epic 32 (« Cycle de vie du lien & release »)** — ouvre l'epic. FR7 + NFR5. Elle **clôt fonctionnellement l'Epic 31** : 31.2/31.3 ont noté à plusieurs reprises que la rupture relève de 32.1 (« `severed` ⇒ `active()` null ⇒ ordres/verrous levés automatiquement »).
>
> **« Preuve ET construction » (verdict d'investigation, vérifié).** Contrairement à 31.2 (qui était « preuve, pas construction »), 32.1 est un **MÉLANGE** :
> - **PREUVE (acquis par construction)** — La **levée des verrous** et la **chute du bornage catalogue** sont déjà automatiques : dès que `link_state = severed`, `ControlHubContract::active()` renvoie `null` [app/Models/ControlHubContract.php:64] et **TOUS** les consommateurs court-circuitent — `UpstreamContractSource::ensureResolved()` [app/Services/ControlHub/Resolution/UpstreamContractSource.php:416], `UpstreamCatalogResolver` [app/Services/ControlHub/UpstreamCatalogResolver.php:121-122], `UpstreamLockResolver` [app/Services/ControlHub/UpstreamLockResolver.php:124,289], `CapabilityPolicy` (refus locked) [app/Policies/CapabilityPolicy.php:79], le tier `StateMaille::Upstream` de `StateCompiler`. ⇒ verrous + bornage tombent **passivement**. À PROUVER, pas à construire.
> - **CONSTRUCTION RÉELLE (le cœur de 32.1)** — (a) **AUCUN chemin ne POSE `link_state = severed` aujourd'hui** : l'ingestion (28.2) ne fait QUE passer à `Active` [ControlHubContractIngestionService.php:144,195] ; le cas `Severed` n'existe que dans l'enum [app/Enums/ControlHubLinkState.php:21] et la factory [database/factories/ControlHubContractFactory.php:29-32]. Il faut **construire la réception du signal de rupture**. (b) **L'audit NFR5** de la transition `active → severed`. (c) La **conservation de la valeur courante effective** des items qui étaient purement imposés (sans ligne locale de support) — voir « Questions/Décisions Henri », point de design **central**.

> **⚠️ Décision Henri (héritée 29.1/31.1/31.2/31.3) — pas de compat ascendante** : aucun environnement de prod à préserver ; seul invariant intangible = l'enrôlement controlHub + le contrat agent figé. Les garde-fous « non-régression » visent le **bon design** (NFR3 standalone byte-identique, golden/`FROZEN_STATE_HASH`/`ContractV1` intacts), pas la rétrocompat d'appelants exotiques.

## Contexte du code (constat vérifié 2026-06-29)

### A. La levée est AUTOMATIQUE (preuve, pas construction)

- **`ControlHubContract::active()`** [app/Models/ControlHubContract.php:64-69] — filtre `link_state = active`, renvoie `null` sinon. C'est le **chokepoint unique** de tout le domaine contrat.
- **Consommateurs qui court-circuitent quand `active()` est null** (vérifiés par grep) :
  - `UpstreamContractSource::ensureResolved()` [Resolution/UpstreamContractSource.php:416] — `where('link_state', Active)` ⇒ pas de contrat ⇒ aucun item amont chargé ⇒ verrous/permissifs/ordres d'install levés (y compris `orderedApplicationAppIds()` 31.2 → `[]`).
  - `UpstreamCatalogResolver` [UpstreamCatalogResolver.php:121-122] — `severed ⇒ null ⇒ court-circuit, zéro requête catalogue (NFR3)` ⇒ **bornage catalogue tombe**, le refnum réinstalle librement (FR5 levé).
  - `UpstreamLockResolver` [UpstreamLockResolver.php:124,289] — `standalone ou severed ⇒ AUCUN badge` ⇒ UI rend la main.
  - `CapabilityPolicy` [Policies/CapabilityPolicy.php:79] — `locked amont → refus ; permissive/absent/standalone/severed → autorisé` ⇒ **droit de modification plein** restauré (FR3 levé).
  - `StateCompiler` — tier `StateMaille::Upstream` (rang -1) [StateCompiler.php:383-397] : sans candidat amont, l'item résout sur la maille locale.

### B. AUCUN chemin ne passe le lien à `severed` (à construire)

- `ControlHubContractIngestionService` : `ingest()` met `link_state = Active` à la création [:195] **et** au rafraîchissement [:144] ; commentaire explicite L.71 « La rupture (`severed`) relève d'Epic 32 (hors scope) ». **Aucun** `severed` posé nulle part dans `app/` (grep confirme : uniquement enum + factory + commentaires).
- **Transport du lien** = `ControlHubConnection` [app/Models/ControlHubConnection.php] (handshake/tokens/heartbeat, `updateFromHandshake()` L.68) — **distinct** de `ControlHubContract` (la politique). 28.1 note « deux concepts complémentaires, sans FK ». Le signal de rupture peut donc arriver par : un **endpoint authentifié controlHub** (groupe `controlhub.auth` de routes/api.php:77), une **commande artisan** (re-jouable, cohérent avec le patron 30.3/31.3), ou une **méthode de service** déclenchée par la couche handshake/instance-status. → **Question Henri Q4**.
- **Patron event** : `ControlHubContractChanged::dispatch()` est émis APRÈS commit par `ingest()` [ControlHubContractIngestionService.php:158] et consommé par `ReconcileImposedWorkstationGroups` + `ProvisionOrderedApplications` (invalidation de cache, réconciliation). Une rupture **doit** émettre le même event (invalider les mémoïsations `UpstreamContractSource`/`UpstreamCatalogResolver`/`UpstreamLockResolver`).

### C. « Ajouts locaux conservés » — état des supports de persistance

- **Applications matérialisées (31.3)** : `AppStoreService::materializeFromSource()` [app/Services/AppStore/AppStoreService.php:89-104] crée une **ligne `Application` persistante** (status `Available`, recette WPKG). Ces lignes **survivent trivialement** à la rupture (aucune suppression) ⇒ « ajouts locaux conservés » **gratuit** côté inventaire.
  - **MAIS** `materializeFromSource()` **ne pose PAS** `managed_by_control_hub` — alors que **la colonne EXISTE** sur `Application` (`$fillable` [Application.php:75] + cast bool [:110], défaut `false`). Les autres chemins amont la posent (`SyncApplicationJob.php:105,125`, `SyncAppProfileJob.php:176`, `BulkCreateWorkstationGroupsJob.php:202`). ⇒ une app matérialisée depuis l'amont est aujourd'hui **indistinguable** d'une app purement locale (review 31.3 #B). → **Question Henri Q2**.
- **Overrides de capacité (refnum)** : `capability_assignments` (pivot polymorphe `assignable_type`/`assignable_id`, `value`) — surfaces `saveOverride()`/`removeOverride()` (29.5). Ces lignes locales **survivent** à la rupture.
- **Items purement imposés (registry/wallpaper/shortcut/agent-tools `locked`)** : injectés à la maille `StateMaille::Upstream` **SANS ligne locale de support**. À la rupture, `active()` → null ⇒ le candidat amont disparaît ⇒ la valeur effective **revient au local/défaut**. Pour **CONSERVER la valeur courante effective** (lettre de l'AC FR7), il faudrait **matérialiser** cette valeur dans le store local correspondant (ex. `capability_assignments`). C'est une **construction transverse** et un **arbitrage** → **Question Henri Q1 (centrale)**.

### D. Audit (NFR5) — patron maison établi

- `CapabilityOverrideAuditLog` (29.5) [app/Models/CapabilityOverrideAuditLog.php] = patron canonique : table **append-only** (UPDATE → exception L.101), `public $timestamps=false` + `created_at` manuel, **fabrique statique `log(...)`** [:113-145], calque `QuotaAuditLog`/`DelegationHistory`. `spatie/laravel-activitylog` **n'est PAS** une dépendance (ne pas l'introduire). La transition `active → severed` doit produire **un** événement horodaté (qui, quand, contrat concerné, nb d'items/verrous levés). → **Question Henri Q5** (table dédiée vs log structuré).

## Acceptance Criteria

1. **Given** un contrat amont **actif** (`link_state = active`) avec des items `locked`, des items `permissive`, un catalogue applicatif, des ordres d'install, et des ajouts locaux (overrides `capability_assignments`, apps matérialisées 31.3),
   **When** SE5 **reçoit le signal de rupture** du lien,
   **Then** le contrat passe à `link_state = severed` (transition **idempotente** : un 2ᵉ signal sur un contrat déjà `severed` est un no-op qui n'émet pas de nouvel audit), **And** l'événement `ControlHubContractChanged` est émis (invalidation des résolveurs mémoïsés).

2. **Given** la rupture appliquée (AC1),
   **When** `StateCompiler` recompile l'état d'un poste / `UpstreamContractSource` / `UpstreamCatalogResolver` / `UpstreamLockResolver` / `CapabilityPolicy` sont sollicités,
   **Then** **tous les items quittent l'état imposé** (plus aucun candidat `StateMaille::Upstream`/`UpstreamPermissive`), le **bornage catalogue tombe** (le refnum peut consulter/installer hors catalogue, FR5 levé), le **refus de modification d'un item verrouillé tombe** (`CapabilityPolicy` autorise, FR3 levé) — **prouvé par construction** (`active()` → null court-circuite partout) **et** par test.

3. **Given** un item était imposé `locked` (valeur amont X) et avait **un support local** (override `capability_assignments` / ligne `Application` matérialisée),
   **When** la rupture est appliquée,
   **Then** la valeur/ressource locale est **conservée telle quelle** (aucune suppression de `capability_assignments`, aucune suppression d'`Application`) ⇒ « les ajouts locaux antérieurs sont conservés ».

4. **Given** un item était imposé `locked` (valeur amont X) **SANS** support local (purement amont),
   **When** la rupture est appliquée,
   **Then** [comportement selon **décision Henri Q1**] — par défaut de cette story : la **valeur courante effective X est matérialisée** comme réglage **local-libre** (le refnum la voit, peut la modifier ou la supprimer) ; à défaut de validation Q1, l'item **revient au local/défaut** (option B documentée). Le test verrouille la décision retenue.

5. **Given** une instance SE5 **sans contrat actif** (standalone),
   **When** un signal de rupture arrive (ou n'arrive pas),
   **Then** le comportement standalone reste **strictement inchangé** (NFR3) : aucune table contrat lue/écrite hors du chemin de rupture, golden `state.v1.json` / `FROZEN_STATE_HASH` (PHP & Go) / `ContractV1Test` **inchangés** (les fixtures golden n'ont pas de contrat).

6. **Given** la transition `active → severed` (NFR5),
   **When** elle survient,
   **Then** **un et un seul** événement d'audit horodaté est consigné [forme selon **décision Henri Q5**] contenant au minimum : l'**horodatage**, l'**acteur/origine** du signal, l'**identifiant du contrat** rompu, et un **récapitulatif** (nb d'items levés / apps conservées). Un signal sur contrat déjà `severed` **n'émet aucun** nouvel audit (idempotence AC1).

7. **Given** le contrat agent figé `se5.desired-state/v1`,
   **When** 32.1 est livrée,
   **Then** **aucun** champ/clé/type du wire format n'est ajouté ni modifié (la rupture agit sur l'état serveur, pas sur le format) : golden `state.v1.json`, `ContractV1`, `StateHasher`, `tests/Fixtures/Agent/*.json` **intacts** ; **aucun bump** de `agent/shared/version.go` (rien sous `agent/**` touché).

8. **Given** la suite de tests HÔTE (php8.4 + sqlite, `CACHE_DRIVER=array`),
   **When** elle s'exécute,
   **Then** sont verts : réception/idempotence de la rupture (AC1), levée automatique verrous+catalogue (AC2), conservation des supports locaux (AC3), conservation de la valeur effective ou revert documenté (AC4), standalone byte-identique (AC5), audit unique de transition (AC6), golden/`ContractV1` intacts (AC7) — **sans régression** des suites `Agent`/`Application*`/`UpstreamContract*`/`UpstreamCatalog*`/`UpstreamLock*`/`Capability*`/`ControlHubContract*`.

## Tasks / Subtasks

- [x] **T0 — Acter le cadrage « preuve + construction » & trancher les questions Henri** (AC: #2, #4)
  - [x] Prouver (test) qu'un contrat passé `severed` fait court-circuiter `active()` → tous les consommateurs (verrous, catalogue, badges, policy) — **AUCUN câblage de levée à construire** (la levée est automatique). Documenter la liste exacte des chokepoints (cf. Contexte A).
  - [x] **Verrouiller les décisions Q1 (conservation de valeur), Q2 (flag d'origine), Q3 (réalignement recette → autre story ?), Q4 (canal du signal), Q5 (forme d'audit)** AVANT de coder T2/T3/T4 — voir section « Questions/Décisions Henri ». NE PAS sur-construire : si Q1 = option B (revert), T2 se réduit à un test de comportement.

- [x] **T1 — Réception du signal de rupture (construction réelle)** (AC: #1, #6) — *canal selon Q4*
  - [x] Ajouter un **service de rupture** (ex. `ControlHubContractSeveranceService::sever(?actor, ?reason): result`) OU une méthode dédiée : résout le contrat **actif** (`ControlHubContract::active()`) ; s'il est null OU déjà `severed` → **no-op idempotent** (pas d'audit, AC1) ; sinon `link_state = Severed`, `save()`, puis (T4) audit, puis **`ControlHubContractChanged::dispatch($contract)`** (invalidation mémoïsations, patron `ingest()` post-commit).
  - [x] **Canal d'entrée (Q4)** : exposer via (a) commande artisan re-jouable `controlhub:sever-link` (patron `controlhub:provision-ordered-apps` 31.3) ET/OU (b) endpoint authentifié sous `Route::prefix('v1')->middleware('controlhub.auth')` [routes/api.php:77] (nouveaux blocs API **APRÈS** le groupe 16.12 — fenêtre 1500 chars, cf. mémoire `api_routes_arch_test_window_trap`). Suivre la décision Q4.
  - [x] **R3** : vocabulaire `amont`/`ControlHub*`/`Upstream` ; aucun « central » dans identifiant/message/commentaire.

- [x] **T2 — Conservation de la valeur courante effective (selon Q1)** (AC: #3, #4) — *construction CONDITIONNELLE*
  - [x] **Si Q1 = option A (matérialisation)** : AVANT de poser `severed` (tant que `active()` voit encore le contrat), pour chaque item imposé **sans support local**, calculer la valeur effective courante et l'**écrire dans le store local** correspondant (capacités → `capability_assignments` à la maille appropriée ; réutiliser le patron `saveOverride()` 29.5, NE PAS réinventer). Idempotent. Cibler d'abord les types réellement imposables (capacités/registry) ; les `applications` sont déjà conservées comme lignes (AC3).
  - [x] **Si Q1 = option B (revert)** : AUCUNE matérialisation ; T2 = **test** prouvant que les supports locaux (AC3) survivent et que les items purement amont reviennent proprement au local/défaut, documenté comme comportement assumé.
  - [x] **Garde-fou** : ne JAMAIS toucher `StateCompiler`/`StateMaille` (la levée passe par `active()`→null, pas par une modif du compilateur).

- [x] **T3 — Traçabilité d'origine des apps matérialisées (selon Q2)** (AC: #3) — *report review 31.3 #B*
  - [x] **Si Q2 retenu** : poser `managed_by_control_hub = true` dans `AppStoreService::materializeFromSource()` (la colonne **existe déjà** — `$fillable`+cast) pour distinguer une app matérialisée amont d'une app locale. Migration **inutile** (colonne présente).
  - [x] Décider le comportement du flag **à la rupture** : (i) le flag retombe `false` (« devient locale ») OU (ii) le flag est conservé comme **marqueur d'origine historique** mais ne porte plus d'enforcement (l'enforcement vient de `active()`, déjà null). Recommandation : (ii) — trace d'origine sans coût d'enforcement. Verrouiller par test.

- [x] **T4 — Audit NFR5 de la transition (selon Q5)** (AC: #6) — *patron `CapabilityOverrideAuditLog` 29.5*
  - [x] **Si Q5 = table dédiée** : créer `ControlHubLinkAuditLog` (append-only, `log()` statique, `created_at` manuel, calque `CapabilityOverrideAuditLog`/`QuotaAuditLog`) + migration **additive** : champs min. `contract_id`, `from_state`, `to_state`, `actor` (id+login dénormalisé / origine signal), `summary` (json : items levés, apps conservées), `created_at`. Appelé **dans** la transaction de la rupture (atomicité acte↔trace).
  - [x] **Si Q5 = log structuré** : `Log::info('controlhub.link.severed', [...])` (cohérent avec les logs `provision_summary` 31.3) — moins interrogeable. Recommandation : **table dédiée** (NFR5 = « auditable », pattern maison déjà établi 29.5).
  - [x] Un signal sur contrat déjà `severed` n'écrit **aucune** ligne (idempotence AC1/AC6).

- [x] **T5 — Tests HÔTE (php8.4 + sqlite, `CACHE_DRIVER=array`)** (AC: #1–#8)
  - [x] `tests/Feature/ControlHub/ContractSeveranceTest.php`. Harnais : `ControlHubContract::factory()->create()` (= `active`), `->severed()`, `ControlHubContractItem::factory()` (`->forLabel()`/`->permissive()`/`->absent()`), `WpkgSchemaBootstrapper::bootstrap()` (tables controlhub + applications, **sans** `RefreshDatabase`, cf. note `ApplicationsStateProviderTest`), `WorkstationGroupObserver::disableSync()`.
    - **AC1** : signal → `severed` + `ControlHubContractChanged` émis (`Event::fake`) ; 2ᵉ signal → no-op (pas d'event, pas d'audit).
    - **AC2** : après rupture, `UpstreamContractSource::candidatesFor()`/`orderedApplicationAppIds()` = `[]`, `UpstreamCatalogResolver::isBounded()` = false, `CapabilityPolicy` autorise un `locked` antérieur, `StateCompiler::compile()` ne contient plus de candidat amont (query log : zéro requête items après `severed`, via `active()` null).
    - **AC3** : override `capability_assignments` + `Application` matérialisée présents AVANT → toujours présents APRÈS (assert count/colonnes inchangées).
    - **AC4** : selon Q1 — (A) valeur amont X matérialisée en `capability_assignments` local-libre et modifiable ; (B) item purement amont revient au défaut (assert effectif).
    - **AC5** : standalone (aucun contrat) → signal no-op, AppStore/StateCompiler inchangés ; golden non touché.
    - **AC6** : exactement 1 ligne/événement d'audit sur transition ; 0 sur re-signal.
    - **AC7** : `ContractV1Test` + golden `state.v1.json` + `StateCompilerTest` **verts inchangés** ; `git diff` ne touche pas `agent/**` ni les fixtures agent.
  - [x] **Pièges** : SQLite n'applique pas varchar/enum PG → tester décisions (état/présence/count/hash), pas bornes de colonne ; matcher `app_id` (string) ; `CACHE_DRIVER=array` ; `Cache::lock()` non dispo en APCu (cf. mémoires). Migration additive si T4=table → `migrate:status` avant tout e2e VM (non requis ici).

- [x] **T6 — Runbook QA** (AC: #1–#6)
  - [x] **Append** `## Section 17 — Release des verrous à la rupture du lien (Story 32.1)` à `docs/qa/domains/controlhub-contract.md` (append-only ; dernière = Section 16 / 31.3). Scénarios : contrat actif avec verrous+catalogue+ordres → déclencher la rupture (commande/endpoint Q4) → vérifier `link_state=severed`, `curl GET /api/v1/agent/state` (plus d'item amont), refnum réinstalle hors catalogue, capacité verrouillée redevient éditable, overrides/apps matérialisées **conservés**, audit consigné ; re-signal = idempotent.
  - [x] Mettre à jour la **ligne cumulative** du domaine dans `docs/qa/README.md` (mention Story 32.1, suffixe la ligne 31.3).

- [x] **T7 — Validation finale & garde-fous** (AC: #5, #7)
  - [x] `php artisan test --filter "ControlHubContract|UpstreamContract|UpstreamCatalog|UpstreamLock|Capability|ContractSeverance|Agent|Application|ContractV1|StateCompiler"` sur HÔTE → vert, 0 régression (filtres ciblés, **jamais** run massif VM).
  - [x] **R3** : `grep -rin central` sur les fichiers livrés ⇒ uniquement en-têtes garde-fou.
  - [x] **NFR3** : standalone prouvé (signal no-op sans contrat ; `active()` null court-circuite).
  - [x] **Contrat agent figé** : `git diff` ⇒ **aucune** ligne sur `StateContract.php`/`StateHasher.php`/`tests/Fixtures/Agent/*.json`/`ContractV1`/`agent/**` ; `FROZEN_STATE_HASH` (PHP & Go) inchangé.

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 32.1

**DANS** : réception du signal de rupture (service + canal Q4) posant `link_state = severed` idempotemment + `ControlHubContractChanged` ; audit NFR5 de la transition `active → severed` (Q5) ; conservation des supports locaux (AC3, gratuit) ; conservation de la valeur effective (T2, selon Q1) ; flag d'origine `managed_by_control_hub` sur `materializeFromSource` (T3, selon Q2) ; tests ; runbook QA.

**HORS** (ne pas déborder) :
- **Modifier `StateCompiler`/`StateMaille`/le tier `Upstream`** : INTERDIT. La levée passe **exclusivement** par `active()` → null (déjà câblé partout). 32.1 ne touche pas le compilateur.
- **Distinguer « amont indisponible » de « lien rompu »** + trace fine des transitions : c'est la **Story 32.2** (une panne ≠ rupture ; le dernier contrat reste en vigueur). 32.1 ne traite QUE le signal **explicite** de rupture.
- **Réalignement de recette WPKG** (`source_xml_url`/`sha` republié pour une app déjà matérialisée, review 31.3 #6) : concerne le **lien ACTIF** (drift de recette), pas la rupture → **Question Henri Q3** (probablement story 32.x/31.x distincte, PAS 32.1).
- **Contrat agent / wire format** : ZÉRO touche (la rupture agit sur l'état serveur).
- **Authoring controlHub** (émission du signal côté central) — hors repo SE5. 32.1 ne fait que **recevoir**.

### Décisions de cadrage (pré-établies par l'investigation)

- **D-A — La levée est automatique (preuve)** : verrous + bornage catalogue + refus de modif tombent dès `severed` via `active()`→null. Aucun code de « déverrouillage » à écrire. C'est l'invariant noté en 31.2/31.3.
- **D-B — Idempotence stricte** : un signal sur contrat null ou déjà `severed` = no-op (pas d'audit, pas d'event). Calque la discipline no-op de l'ingestion 28.2.
- **D-C — Event obligatoire** : la rupture **doit** émettre `ControlHubContractChanged` pour invalider les mémoïsations par-conteneur de `UpstreamContractSource`/`UpstreamCatalogResolver`/`UpstreamLockResolver` (mêmes consommateurs que l'ingestion).
- **D-D — Supports locaux conservés gratuitement** : `capability_assignments` et lignes `Application` matérialisées sont persistants → survivent sans code. Seuls les items **purement amont** posent la question de la valeur (Q1).

### Patrons de référence à IMITER (ne rien réinventer)

- **`ControlHubContractIngestionService::ingest()`** [app/Services/ControlHub/ControlHubContractIngestionService.php:144-160] — pose de `link_state`, save, **dispatch `ControlHubContractChanged` post-commit**. La rupture est le miroir (Active→Severed).
- **`ReconcileImposedWorkstationGroups` / `ProvisionOrderedApplications`** (listeners + commandes, 30.3/31.3) — patron commande artisan re-jouable + listener `ControlHubContractChanged` ; canal Q4 si commande.
- **`CapabilityOverrideAuditLog`** [app/Models/CapabilityOverrideAuditLog.php:113-145] — patron audit maison append-only (`log()` statique, `created_at` manuel, UPDATE→exception) pour T4.
- **`AppStoreService::materializeFromSource()`** [app/Services/AppStore/AppStoreService.php:89-104] — point d'ajout du flag `managed_by_control_hub` (T3) ; `firstOrCreate`, ne touche jamais une `Application` préexistante.
- **`saveOverride()`** (capabilities-tab.blade.php, 29.5) — patron d'écriture `capability_assignments` à réutiliser pour T2 option A.

### Garde-fous projet CRITIQUES

- **NFR3 — standalone byte-identique (BLOQUANT)** : sans contrat, signal no-op ; `active()` null court-circuite. Golden/`FROZEN_STATE_HASH`/`ContractV1` intacts. [Source: mémoire — drift_policy_strict_only, agent_desired_state_direction]
- **NFR7 — Postgres-only** : aucun AD/LdapRecord dans le chemin contrat/rupture.
- **Contrat agent figé (BLOQUANT)** : `se5.desired-state/v1` (golden/`ContractV1`/`StateHasher`/`tests/Fixtures/Agent/*.json`) INTACT ; **pas de bump** `agent/shared/version.go` (rien sous `agent/**` n'est touché). [Source: mémoire — feedback_agent_edit_bump_version]
- **R3 — vocabulaire (BLOQUANT)** : aucun « central » ; `amont`/`Upstream`/`ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
- **Migrations ADDITIVES uniquement** (si T4=table d'audit) — jamais réécrire une migration en review. [Source: mémoire — vm_migrations_not_auto_applied]
- **Tests HÔTE uniquement** (php8.4 + `pdo_sqlite`, `CACHE_DRIVER=array`), filtres ciblés, **jamais** run massif VM (faux échecs connus). [Source: mémoires — phpunit_test_env_host_vs_vm, vm_phpunit_bulk_run_false_failures]
- **Racine = projet Laravel** (artisan/app à la racine). [Source: mémoire — root_is_laravel]
- **routes/api.php** (si endpoint Q4) — nouveaux blocs API **APRÈS** le groupe 16.12 (fenêtre 1500 chars du test d'archi). [Source: mémoire — api_routes_arch_test_window_trap]

### Project Structure Notes

- **Nouveaux** : `app/Services/ControlHub/ControlHubContractSeveranceService.php` (ou nom proche) ; `app/Console/Commands/SeverControlHubLink.php` (si Q4=commande) ; éventuel endpoint dans `app/Http/Controllers/Api/v1/ControlHub/` + route ; `app/Models/ControlHubLinkAuditLog.php` + migration additive (si Q5=table) ; `tests/Feature/ControlHub/ContractSeveranceTest.php`.
- **Modifiés** : `app/Services/AppStore/AppStoreService.php` (flag `managed_by_control_hub` dans `materializeFromSource`, si Q2) ; éventuellement la surface d'écriture `capability_assignments` réutilisée par T2 (option A) ; `tests/Support/WpkgSchemaBootstrapper.php` (table d'audit si T4) ; `docs/qa/domains/controlhub-contract.md` (Section 17) ; `docs/qa/README.md` (ligne cumulative) ; `routes/api.php` (si endpoint Q4).
- **AUCUNE** modification de `StateCompiler`/`StateMaille`/contrat agent/golden/`agent/**`.

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 32.1] — AC d'origine (release verrous + conservation valeur effective + chute catalogue + ajouts locaux conservés) ; note de report review 31.3 (#B traçabilité, #6 réalignement).
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#FR7] (l.123) — « release à la rupture, tracée/auditée » ; §6 cycle de vie (l.89-91) ; §3 (l.53) « sans perte des ajouts ».
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#NFR5] — auditabilité (pose de verrou, override, rupture de lien tracés).
- [Source: _bmad-output/codeReviews/31-3.md #6 / #B] — réalignement recette + flag `managed_by_control_hub` reportés à 32.x (décision Henri 2026-06-29).
- [Source: app/Models/ControlHubContract.php:64-69] — `active()` (chokepoint unique).
- [Source: app/Services/ControlHub/ControlHubContractIngestionService.php:71,144,158,195] — pose Active, dispatch event post-commit, commentaire « rupture = Epic 32 ».
- [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php:416 ; UpstreamCatalogResolver.php:121-122 ; UpstreamLockResolver.php:124,289 ; Policies/CapabilityPolicy.php:79] — consommateurs qui court-circuitent sur `active()` null.
- [Source: app/Enums/ControlHubLinkState.php:21 ; database/factories/ControlHubContractFactory.php:29-32] — `Severed` (enum + factory) ; aucun chemin applicatif ne le pose.
- [Source: app/Services/AppStore/AppStoreService.php:89-104] — `materializeFromSource` (ne pose pas `managed_by_control_hub`).
- [Source: app/Models/Application.php:75,110] — colonne `managed_by_control_hub` existante (fillable + cast).
- [Source: app/Models/CapabilityOverrideAuditLog.php:113-145] — patron audit maison append-only (T4).
- [Source: app/Models/ControlHubConnection.php:68] — transport du lien (handshake), distinct du contrat (Q4).
- [Source: app/Services/Agent/StateCompiler.php:383-397] — tier `StateMaille::Upstream`/`UpstreamPermissive` (NE PAS toucher).
- [Source: mémoires projet — drift_policy_strict_only, agent_desired_state_direction, phpunit_test_env_host_vs_vm, vm_phpunit_bulk_run_false_failures, root_is_laravel, api_routes_arch_test_window_trap, feedback_agent_edit_bump_version, vm_migrations_not_auto_applied].

## Dépendances

- **Amont (toutes `done`, code sur la branche unifiée)** :
  - **28.1** (`done`) — schéma `controlhub_contracts` + enum `ControlHubLinkState` (`active`/`severed`) + factory `severed()`. **Fondation** : la cible `severed` existe déjà.
  - **28.2** (`done`) — `ControlHubContractIngestionService` (patron pose `link_state` + dispatch `ControlHubContractChanged` post-commit, miroité par la rupture).
  - **28.3** (`done`) — `UpstreamContractSource`/`UpstreamAwareProvider` + tier `StateMaille::Upstream` (court-circuit sur `active()` null = mécanisme de levée).
  - **29.x** (`done`) — `CapabilityPolicy` (refus locked, levé à la rupture) ; `UpstreamLockResolver` (badges) ; **`CapabilityOverrideAuditLog`** (29.5, patron audit pour T4) ; `capability_assignments` (support local conservé / cible T2 option A).
  - **30.x** (`done`) — résolution par label / labelsCarriedBy (court-circuités à la rupture).
  - **31.1** (`done`) — `UpstreamCatalogResolver` (bornage catalogue, levé à la rupture).
  - **31.2** (`done`) — `orderedApplicationAppIds()` (ordres d'install levés à la rupture, déjà noté « 32.1 valide la levée »).
  - **31.3** (`done`) — `AppStoreService::materializeFromSource()` (apps matérialisées = ajouts locaux conservés ; cible T3 flag d'origine) ; reports review #6/#B traités ici.
- **Aval** :
  - **Story 32.2** (FR/NFR5) — distinguer « amont indisponible » de « lien rompu » + trace fine des transitions. 32.1 traite le signal **explicite** ; 32.2 la **résilience** (panne ≠ rupture).
  - **Story 33.x** — schéma d'échange versionné (la forme du signal de rupture, Q4, touche le payload d'interface controlHub↔SE5).

## Questions/Décisions Henri

> **TRANCHÉ par Henri le 2026-06-30 (validation dev-cycle). Décisions verrouillées ci-dessous — T0 acte, ne pas re-poser.**
>
> - **Q1 = Option A (matérialisation), bornée capacités/registry.** Réutiliser `saveOverride()`/`capability_assignments` (29.5). Les `applications` restent conservées comme lignes (AC3). AC4 = lettre exacte de l'AC.
> - **Q2 = OUI — poser `managed_by_control_hub=true` dans `materializeFromSource` ET conserver le flag à la rupture** (trace d'origine, pas de retombée à false). ⚠️ **Contrainte Henri** : tout libellé **front-facing** dérivé de ce flag doit être en **français intelligible** (ex. « Géré par controlHub » / « Origine : controlHub », jamais le nom brut de colonne).
> - **Q3 = HORS 32.1** (réalignement de recette = story 32.x distincte).
> - **Q4 = commande artisan `controlhub:sever-link` + endpoint `controlhub.auth`** partageant `ControlHubContractSeveranceService`.
> - **Q5 = table dédiée append-only** (patron `CapabilityOverrideAuditLog` 29.5 ; migration additive).

> **🔴 CORRECTION DE CADRAGE Henri — post-review (2026-06-30, finding M1 second avis opus). Remplace l'interprétation initiale de Q1.**
>
> **Principe (Henri, verbatim)** : « à la rupture, l'état du parc reste identique à ce qu'il était avec le lien, déverrouillé — comme dupliquer l'état du parc en retirant tous les verrous. » La matérialisation N'est PAS bornée à `type='capabilities'` — elle doit figer l'**état effectif COMPLET** de **tous les canaux réellement imposés**, déverrouillé.
>
> Réalité architecturale (cartographie vérifiée) : le SEUL canal imposé câblé en prod = **`registry`** (`RegistryUpstreamAdapter`) ; localement tout le registre dérive des capacités (capability-first 27.12 — pas de store registre brut) ; `shortcuts` non câblé (Epic 33, hors prod) ; `applications` = ensemble d'ordres d'install. Le code initial matérialisait `type='capabilities'` qui **n'a aucun adaptateur** → fige un canal mort ET rate le vrai canal `registry`.
>
> **Décisions correctives verrouillées :**
> - **M1 — matérialisation CAPABILITY-CENTRIC sur le vrai canal `registry`** : pour chaque capacité **verrouillée** par l'amont (détection par identité de clé registre via `UpstreamLockResolver::capabilityUpstreamStatus()`/`isCapabilityLocked()`), recouvrer la valeur de capacité imposée à partir de la **valeur connue** envoyée par controlHub (map de projection `CapabilityProjection.spec`, sens valeur-registre → valeur-capacité) et l'**écrire dans `capability_assignments`** à la maille du parc cible (réutiliser le patron `saveOverride()` 29.5). NE PAS se limiter à `type='capabilities'` (canal mort).
> - **Capacités — valeur TOUJOURS connue** (Henri) : controlHub envoie toujours une valeur correspondant à un état de capacité connu (on/off) → la conversion réussit toujours en pratique. Le **skip+warn** nominatif n'est qu'un **filet de sécurité jamais censé se déclencher** (limite documentée 32.x si jamais).
> - **Apps — matérialiser l'AFFECTATION locale** : à la rupture, projeter chaque app **ordonnée par l'amont** (instance/label, `orderedApplicationAppIds()`/`labelsCarriedBy` 31.2) en **affectation locale** (pivot app_profile/parc) pour qu'un poste qui ne la recevait QUE via l'ordre amont la **CONSERVE** (état identique). Les lignes `Application` restaient déjà conservées (AC3) ; ici on conserve aussi l'**affectation**.
> - **Permissif** : no-op (plancher déjà local). Seuls les **locked** sont matérialisés.
> - **Preuve = PARITÉ D'ÉTAT** : les tests doivent partir d'une capacité **réellement verrouillée par l'amont** (canal registry, comme l'AC2) et prouver que la sortie compilée (`StateCompiler`) est **identique avant/après rupture** pour le parc — pas seulement que des lignes sont écrites (corrige les tests circulaires M2).

> Reco initiale = recommandation d'architecte (conservée pour traçabilité ; toutes confirmées par Henri).

**Q1 (CENTRALE) — « Conserver la valeur courante effective » : matérialiser ou laisser revenir ?**
Un item imposé `locked` **sans support local** (registry/wallpaper/shortcut/agent-tools) impose sa valeur uniquement via le tier `Upstream`. À la rupture, `active()`→null ⇒ la valeur **revient au local/défaut** (pas de ligne locale). La lettre de l'AC FR7 (« conservant leur valeur courante effective ») exige l'**option A**.
- **Option A (matérialisation)** : à la rupture, écrire la valeur effective courante dans le store local (`capability_assignments` etc.) → le refnum garde **exactement** ce qui tournait, désormais éditable. **Coût** : construction transverse (un chemin de matérialisation par type imposable), à borner aux types réellement utilisés.
- **Option B (revert)** : ne conserver que les **supports locaux persistants** (apps matérialisées, overrides) ; les items purement amont reviennent au défaut local. Plus léger, fidèle à « preuve, pas construction », mais **diverge de la lettre de l'AC**.
- **Reco** : **Option A bornée aux capacités/registry** (le gros des items imposés ; réutilise `saveOverride()`/`capability_assignments`), les `applications` étant déjà conservées comme lignes. Si tu veux minimiser, Option B avec AC4 reformulé. → **décide la portée.**

**Q2 — Flag `managed_by_control_hub` sur les apps matérialisées (report 31.3 #B).** La colonne existe mais `materializeFromSource` ne la pose pas. La poser à `true` à la matérialisation ? Et à la rupture, le flag retombe (`false`, « devient locale ») ou reste (marqueur d'origine historique sans enforcement) ?
- **Reco** : poser `true` à la matérialisation (traçabilité restaurée, coût nul) ; **conserver** le flag à la rupture comme **trace d'origine** (l'enforcement vient de `active()`, déjà neutralisé) — pas de retombée à false.

**Q3 — Réalignement de recette WPKG (report 31.3 #6) : dans 32.1 ou story distincte ?** Si l'amont republie une `source_xml_url`/`sha` différente pour une app déjà matérialisée (`firstOrCreate` no-op → figée). Ça concerne le **lien ACTIF** (drift de recette), pas la rupture.
- **Reco** : **HORS 32.1** → story 32.x ou suivi Epic 31 dédié (réalignement seulement tant que l'app est `Available` ET `managed_by_control_hub`). 32.1 ne traite que la rupture.

**Q4 — Canal du signal de rupture** : commande artisan re-jouable (`controlhub:sever-link`, patron 31.3) ? Endpoint authentifié `controlhub.auth` ? Méthode branchée sur la couche handshake/`ControlHubConnection` ?
- **Reco** : **commande artisan + endpoint `controlhub.auth`** partageant le même service (`ControlHubContractSeveranceService`). La forme exacte du payload côté wire relève d'Epic 33.

**Q5 — Forme de l'audit NFR5** : table dédiée append-only (`ControlHubLinkAuditLog`, patron `CapabilityOverrideAuditLog`) vs log structuré (`Log::info('controlhub.link.severed', …)`) ?
- **Reco** : **table dédiée** (NFR5 = « auditable/interrogeable » ; le patron maison existe depuis 29.5 ; migration additive).

## Recommandation Modèle Dev

**`opus`.**

Justification : story à **jugement d'architecte dense** malgré une surface modeste. (1) **Cadrage « preuve vs construction »** non trivial — reconnaître que la levée des verrous/catalogue est **acquise gratuitement** (ne RIEN construire côté déverrouillage) tout en identifiant la **vraie construction** (réception du signal `severed` — inexistante aujourd'hui — + audit NFR5 + conservation de valeur). Un modèle moins fin risque soit de re-câbler un « déverrouillage » redondant, soit de rater que `link_state=severed` n'est posé nulle part. (2) **Arbitrages de design ouverts** (Q1 matérialisation vs revert ; Q2 flag d'origine ; Q3 frontière avec 32.x ; Q4 canal ; Q5 audit) à trancher proprement sans sur-construire — sensibilité métier + parcimonie. (3) **Préservation du contrat agent figé** (golden/`ContractV1`/`FROZEN_STATE_HASH`/`agent/**`) alors qu'on touche au cycle de vie du contrat. (4) **NFR3 standalone byte-identique** + idempotence stricte du signal. (5) Réutilisation disciplinée de patrons existants (audit 29.5, dispatch event 28.2, écriture `capability_assignments` 29.5) plutôt que réinvention. Le dev-cycle routera la review vers le modèle opposé (sonnet/fable) pour une 2ᵉ paire d'yeux sur les angles morts golden/NFR3/idempotence.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- `CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/ContractSeveranceTest.php` → **13/13** (76 assertions avec les channels) — dont les tests de PARITÉ d'état (registry locked + app ordonnée)
- `CACHE_DRIVER=array php artisan test tests/Feature/ControlHub/ContractSeveranceChannelsTest.php` → **4/4**
- `--filter "UpstreamCatalog|UpstreamContract|UpstreamLock|UpstreamOrder|UpstreamInstall|ControlHubContract|Provision|ScriptsOsNamespace|ContractSeverance"` → **200/200** (1363 assertions), 0 régression
- `--filter "ContractV1|StateCompiler|Capability|Capabilities"` → **150/150** (618 assertions) — contrat agent figé + golden intacts
- `--filter "Agent|Application|AppStore"` → **677 passés / 22 skip** (skips d'environnement connus), 0 régression
- R3 : `grep -rin central` sur les fichiers livrés ⇒ uniquement les en-têtes garde-fou.
- `git status` : aucun fichier sous `agent/**`, `StateContract`, `StateHasher`, `ContractV1`, `tests/Fixtures/Agent/*` modifié.

### Completion Notes List

**Cadrage « preuve + construction » respecté.** La levée des verrous + bornage catalogue + refus de modif n'est PAS re-construite : elle est ACQUISE via `ControlHubContract::active()` → null. 32.1 construit uniquement (a) la réception du signal, (b) la conservation de valeur, (c) l'audit NFR5, (d) le dispatch d'event. `StateCompiler`/`StateMaille` JAMAIS touchés.

**🔴 REPRISE post-review (2026-06-30) — correction de cadrage M1 + apps + correctifs auto.**

- **M1 (refonte de `materializeEffectiveValues()`) — matérialisation CAPABILITY-CENTRIC sur le VRAI canal `registry`.** L'implémentation initiale matérialisait `where('type','capabilities')` : un pseudo-canal SANS adaptateur amont (`UpstreamContractSource` n'a que `RegistryUpstreamAdapter` câblé) ⇒ **canal mort** (figeait un canal jamais imposé ET ratait le vrai canal `registry`). La refonte : on lit les items `type='registry'`/`locked` (instance ET label), on retrouve par **identité de clé registre** (`strtolower(hive|path|name)`, iso `UpstreamLockResolver`) la/les capacité(s) locale(s) qui projettent la clé, on **recouvre la valeur de capacité** imposée en **inversant** la map de projection (`CapabilityProjection.spec`, sens valeur-registre → valeur-capacité, à partir de la valeur connue de l'item — Henri : toujours recouvrable en pratique), puis on l'écrit en `capability_assignments` (patron `saveOverride()` 29.5). `instance` → tous les parcs actifs ; `label` → parcs portant le `controlhub_label`. **Filet** : aucune capacité matchée OU valeur non recouvrable (littéral) → `Log::warning` nominatif + `continue` (jamais d'exception). Le permissif reste no-op (plancher déjà battu par le défaut local). **AC3** : un override local préexistant n'est JAMAIS écrasé (skip).
- **Apps — matérialisation de l'AFFECTATION locale (`materializeApplicationAssignments()`).** À la rupture, chaque app `ordonnée` amont (item `type='applications'`, locked+permissive = « présente ») est projetée en **affectation locale** par parc (`instance` → tous parcs actifs ; `label` → parcs porteurs) via le chemin CANONIQUE 15.4 `AppProfileService::addApplicationsToWorkstationGroup()` (pivot `application_workstation_group` lu par `WorkstationPackagesResolver`, idempotent `syncWithoutDetaching`, invalide le cache resolver via `WorkstationGroupApplicationsChanged`). Ses gardes 29.1/31.1 sont des no-op hors session web authentifiée (`Auth::check()` false en commande/endpoint controlHub — action SYSTÈME). But : un poste qui ne recevait l'app QUE via l'ordre amont la **CONSERVE** après rupture. Le contrat agent figé (`applications {app_id,name}`) reste inchangé.
- **Correctifs auto de la review :**
  - **#1 (TOCTOU)** : écritures `capability_assignments` via `insertOrIgnore()` (robuste vs la contrainte UNIQUE `capability_assignment_unique` en PG ; invisible en SQLite) ; le compteur `values_materialized` est basé sur le nb RÉELLEMENT inséré.
  - **#2 (`items_lifted`)** : `summary.items_lifted` ne compte plus QUE les items réellement imposés (`locked`+`permissive`) — l'`absent` est exclu (test d'audit ajusté avec un item `absent` témoin).
  - **#3 (`created_at`)** : `WpkgSchemaBootstrapper` aligne la colonne `created_at` de `controlhub_link_audit_logs` sur la migration (`useCurrent()`, non-null) au lieu de `nullable()`.
  - **#5 (test Q2)** : test exerçant le VRAI `AppStoreService::materializeFromSource()` (pas un mock) — assert `managed_by_control_hub === true` à la création + un 2ᵉ appel (`firstOrCreate`) ne réinitialise PAS le flag/les métadonnées d'une app locale préexistante.
- **Tests de PARITÉ D'ÉTAT (corrige M2 — tests circulaires).** Les AC4 partent désormais d'une capacité **réellement verrouillée par l'amont via un item `type='registry'`** (défaut local distinct de la valeur imposée) et prouvent par `StateCompiler::compile()` (providers registry décorés `UpstreamAwareProvider`) que la clé compilée est **identique avant/après** rupture (valeur conservée via l'override matérialisé), que la capacité redevient éditable (`CapabilityPolicy::modify`=true), et idem côté apps (app reçue uniquement via ordre amont → toujours dans la sortie compilée après rupture via l'affectation locale). Cas `label` (parc porteur vs non porteur) couvert.
- **Sortie enrichie** : `ContractSeveranceResult.applicationsAssigned` + `summary.apps_assigned` (commande + endpoint le rapportent).

**🔵 PASSAGE DE CORRECTION FINAL (2026-06-30) — correctifs review #7/#8/#9/#10.**

- **#7 — Portée des écritures `target_type = instance` (DÉCISION HENRI : défaut d'instance).** L'ancienne implémentation matérialisait un verrou `instance` en itérant **tous les parcs actifs** (logiques + salles physiques) → portée over-wide, valeurs sur salles physiques potentiellement non éditables, compteur gonflé, et **trou de couverture** pour un poste hors de tout parc. Décision Henri : figer la valeur dans le **DÉFAUT D'INSTANCE**.
  - **Capacités** : `materializeEffectiveValues()` écrit désormais la valeur recouvrée dans `capabilities.default_value` (patron `saveDefault()` des parc-defaults) pour un item `instance` — UNE écriture, couvre uniformément tous les postes (même hors parc), éditable sur la page des défauts (helper `materializeInstanceDefault()`, idempotent). Le cas `label` est INCHANGÉ (override `capability_assignments` par parc porteur). Les **overrides locaux par parc plus spécifiques** restent intacts et continuent de primer (`effective = assignment.value ?? default_value`) — c'est AC3.
  - **Apps** : un mécanisme « défaut d'instance » EXISTE déjà (`Application.is_parc_default`, couche Broadcast 27.17 — l'app est appliquée par défaut à tous les postes via `ApplicationsStateProvider`). `materializeApplicationAssignments()` l'utilise pour un ordre `instance` (helper `materializeInstanceAppDefault()`, idempotent) — PAS d'affectation par groupe. Le cas `label` reste une affectation par parc porteur (pivot `application_workstation_group`).
  - **`targetGroupIds()`** restreint au **cas `label` uniquement** (instance géré en amont par les défauts d'instance) — ne renvoie plus jamais les salles physiques (élimine la portée over-wide + compteur gonflé). Un item `instance` reçu par ce chemin renvoie `[]` (défensif).
- **#8 — Garde `is_scalar` dans `recoverCapabilityValue()`** : ajout d'`is_scalar($mappedRegistryValue)` avant le `(string)` — une valeur de map non-scalaire (spec malformée) provoquait un `TypeError` fatal en PHP 8 strict. Désormais l'entrée non-scalaire est ignorée (`continue`), pas de crash.
- **#9 — Cohérence de clé audit** : la clé du résumé d'audit passe de `'apps_assigned'` à `'applications_assigned'`, cohérente avec `ContractSeveranceResult::toArray()`.
- **#10 — Test d'audit complété** : `the_audit_log_records_origin_actor_contract_and_summary` contient désormais un VRAI item `registry/locked` ET un VRAI item `applications` (plus seulement des `shortcuts`) ; assertions ajoutées sur `summary['values_materialized']` (=1) et `summary['applications_assigned']` (=1).
- **Tests couverture #7** : nouveau test de **PARITÉ pour un poste HORS de tout parc** (`instance_locked_registry_parity_holds_for_a_workstation_outside_any_parc`) — avant rupture l'item `instance` est broadcast à toute la flotte (valeur imposée), après rupture `StateCompiler::compile()` émet la MÊME valeur via `capabilities.default_value` (et AUCUN override de salle physique). Tests `instance` existants migrés vers le défaut d'instance ; AC3 prouve que l'override par parc prime sur le nouveau défaut ; apps `instance` prouvent `is_parc_default` sans pivot ; nouveau test apps `label` prouve l'affectation par parc porteur sans `is_parc_default`.
- **Résultats (HÔTE php8.4+sqlite, `CACHE_DRIVER=array`, filtres ciblés)** : `ContractSeverance` **19/19** ; `UpstreamCatalog|UpstreamContract|UpstreamLock|UpstreamOrder|UpstreamInstall|ControlHubContract|Provision|ScriptsOsNamespace|ContractSeverance` **202/202** ; `ContractV1|StateCompiler|Capability|Capabilities` **150/150** ; `Agent|Application|AppStore` **678 passés / 22 skip** (env). 0 régression. Contrat agent figé intact (`agent/**`/golden/`ContractV1` non touchés).

**Décisions Henri verrouillées (rappel) :**
- **Q1 = Option A** appliquée selon la **correction M1** ci-dessus (canal registry réel, pas `capabilities`).
- **Q2 (oui + flag conservé)** — `AppStoreService::materializeFromSource()` pose désormais `managed_by_control_hub = true` (colonne existante) à la CRÉATION (`firstOrCreate` → app locale préexistante intouchée, AC3). Aucun code de rupture ne réinitialise ce flag → conservé comme trace d'origine. Cette story ne crée AUCUN affichage front du flag ; la contrainte « libellé FR » est notée dans les PHPDoc pour la surface UI future (« Origine : controlHub »).
- **Q3 (hors 32.1)** — réalignement de recette WPKG non traité.
- **Q4 (commande + endpoint, service unique)** — `controlhub:sever-link` (`--actor`, `--reason`) ET `POST /api/v1/controlhub/sever-link` (`controlhub.auth`) partagent `ControlHubContractSeveranceService`. Route placée à la FIN de `routes/api.php` (après le groupe 16.12) → fenêtre 1500 chars `ScriptsOsNamespaceTest` préservée (vérifié vert).
- **Q5 (table dédiée)** — `controlhub_link_audit_logs` + modèle `ControlHubLinkAuditLog` append-only (UPDATE → `LogicException`, `log()` statique, `created_at` manuel — calque 29.5). Migration **additive**. Écriture DANS la transaction de la rupture (atomicité acte ↔ trace).

**Idempotence stricte (AC1/AC6/NFR3)** : `sever()` résout `ControlHubContract::active()` ; null (standalone OU déjà `severed`) ⇒ no-op TOTAL retourné via `ContractSeveranceResult::noop()` (aucune matérialisation/audit/event/écriture). Event `ControlHubContractChanged` dispatché APRÈS commit (patron `ingest()`).

**Harnais de test** : `RefreshDatabase` (patron des suites capacité+contrat — `CapabilitiesOverrideAuditTest`, `UpstreamLockResolverTest` — qui ont besoin des tables `capabilities`/`capability_assignments`/`capability_projections` absentes de `WpkgSchemaBootstrapper`). `WpkgSchemaBootstrapper` néanmoins **augmenté** (instruction) : table miroir `controlhub_link_audit_logs` + colonne `managed_by_control_hub` sur `applications` (sinon `materializeFromSource` cassait 7 tests bootstrapper) + entrée `tearDown`.

**Reste à valider en VM/lab** : e2e réel du signal de rupture (commande + endpoint avec clé controlHub réelle), `migrate:status` puis `php artisan migrate` de la migration additive `controlhub_link_audit_logs`, observation `GET /api/v1/agent/state` avant/après rupture sur un poste enrôlé (cf. runbook QA Section 17). Différé tant qu'aucun controlHub émetteur n'est branché (cohérent 31.3).

### File List

**Créés :**
- `database/migrations/2026_06_30_100000_create_controlhub_link_audit_logs_table.php`
- `app/Models/ControlHubLinkAuditLog.php`
- `app/Services/ControlHub/ControlHubContractSeveranceService.php`
- `app/Services/ControlHub/Data/ContractSeveranceResult.php`
- `app/Console/Commands/SeverControlHubLink.php`
- `app/Http/Controllers/Api/v1/ControlHub/LinkSeveranceController.php`
- `tests/Feature/ControlHub/ContractSeveranceTest.php`
- `tests/Feature/ControlHub/ContractSeveranceChannelsTest.php`

**Modifiés :**
- `app/Services/ControlHub/ControlHubContractSeveranceService.php` (REPRISE M1 — refonte `materializeEffectiveValues()` canal `registry` + `materializeApplicationAssignments()` + correctifs #1/#2 ; injecte `AppProfileService`)
- `app/Services/ControlHub/Data/ContractSeveranceResult.php` (champ `applicationsAssigned` + `apps_assigned` dans `toArray()`)
- `app/Console/Commands/SeverControlHubLink.php` (ligne récap « Affectations app/parc »)
- `app/Http/Controllers/Api/v1/ControlHub/LinkSeveranceController.php` (clé JSON `applications_assigned`)
- `app/Services/AppStore/AppStoreService.php` (T3/Q2 — `managed_by_control_hub = true` dans `materializeFromSource`)
- `routes/api.php` (T1/Q4 — import + route `controlhub.link.sever` en fin de fichier)
- `tests/Feature/ControlHub/ContractSeveranceTest.php` (REPRISE — AC4 réécrits en tests de PARITÉ d'état registry/apps, AC3 registry, #5 vrai AppStoreService, #2 item `absent` témoin)
- `tests/Support/WpkgSchemaBootstrapper.php` (correctif #3 `created_at` `useCurrent()` ; miroir table audit + colonne `managed_by_control_hub` + tearDown)
- `docs/qa/domains/controlhub-contract.md` (Section 17 — scénarios de PARITÉ d'état ; checklist rapide)
- `docs/qa/README.md` (ligne cumulative du domaine suffixée Story 32.1)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (32-1 → review)

**Réutilisés (non modifiés)** :
- `app/Services/AppProfile/AppProfileService::addApplicationsToWorkstationGroup()` (chemin d'affectation app↔parc canonique 15.4) ;
- `app/Services/ControlHub/UpstreamLockResolver` (algèbre d'identité de clé registre, répliquée) ;
- `app/Services/Agent/Providers/AbstractCapabilityStateProvider` (sens projection capacité→registre, inversé ici).

## Change Log

| Date       | Version | Description                                  | Author |
|------------|---------|----------------------------------------------|--------|
| 2026-06-29 | 0.1     | Création de la story (SM/architecte BMAD, create-story) — ready-for-dev ; ouvre l'Epic 32 (FR7 + NFR5). Intègre les reports review 31.3 (#B traçabilité, #6 réalignement) en Questions Henri. | claude-opus-4-8[1m] |
| 2026-06-30 | 1.0     | Implémentation complète (dev-story) → review. Service unique `ControlHubContractSeveranceService` (réception signal idempotente + matérialisation Q1=A + event post-commit) ; commande `controlhub:sever-link` + endpoint `controlhub.auth` (Q4) ; audit append-only `controlhub_link_audit_logs` (Q5, migration additive) ; flag `managed_by_control_hub` posé par `materializeFromSource` (Q2). Levée des verrous prouvée par construction (chokepoint `active()`→null) + tests. NFR3 standalone + idempotence + contrat agent figé prouvés. 15 tests neufs verts ; 0 régression (filtres ControlHub/Upstream/Capability/Agent/Application/ContractV1/StateCompiler). Runbook QA Section 17. | claude-opus-4-8[1m] |
| 2026-06-30 | 1.2     | **Correctifs review : portée instance=défaut, is_scalar, clé audit, tests couverture** (passage de correction final, findings #7/#8/#9/#10). #7 (décision Henri) : un verrou `target_type=instance` est désormais figé dans le DÉFAUT D'INSTANCE — `capabilities.default_value` pour les capacités (patron `saveDefault()`), `Application.is_parc_default` pour les apps (mécanisme Broadcast 27.17 existant) — au lieu d'itérer tous les parcs actifs (salles physiques incluses). `targetGroupIds()` restreint au cas `label`. Les overrides par parc plus spécifiques priment toujours (AC3). #8 : garde `is_scalar` avant cast dans `recoverCapabilityValue()` (anti-TypeError). #9 : clé audit `apps_assigned` → `applications_assigned`. #10 : test d'audit complété (item `registry/locked` + `applications`, assertions `values_materialized`/`applications_assigned`). Nouveau test de PARITÉ pour poste HORS parc (défaut d'instance, pas d'override salle physique) ; tests instance migrés vers le défaut. 19/19 ContractSeverance ; 202/202 cluster ControlHub/Upstream/Provision ; 150/150 Capability/ContractV1/StateCompiler ; 678/22-skip Agent/Application/AppStore ; 0 régression. Contrat agent figé intact. | claude-opus-4-8[1m] |
| 2026-06-30 | 1.1     | **Refonte matérialisation canal `registry` + apps + correctifs review** (post-review, finding M1). `materializeEffectiveValues()` passe du pseudo-canal mort `type='capabilities'` (sans adaptateur amont) au VRAI canal `registry` : recouvrement capability-centric de la valeur de capacité par inversion de projection (identité de clé registre). Ajout `materializeApplicationAssignments()` (affectation locale des apps ordonnées amont via `AppProfileService`, pivot `application_workstation_group`). Correctifs auto #1 (`insertOrIgnore` TOCTOU), #2 (`items_lifted` locked+permissive uniquement), #3 (`created_at useCurrent()` bootstrapper), #5 (test vrai `materializeFromSource`). Tests AC4 réécrits en **PARITÉ D'ÉTAT** (`StateCompiler` identique avant/après — corrige M2 tests circulaires) : registry locked instance+label, app ordonnée. `ContractSeveranceResult.applicationsAssigned` + `apps_assigned`. Runbook Section 17 mis à jour (parité). 13/13 + 4/4 ; 200/200 cluster ControlHub/Upstream/Provision ; 150/150 Capability/ContractV1/StateCompiler ; 677/22-skip Agent/Application/AppStore ; 0 régression. Contrat agent figé intact (`agent/**`/golden/`ContractV1` non touchés). | claude-opus-4-8[1m] |
