# Story 31.3: Approvisionnement d'une app ordonnée depuis le dépôt SambaEdu

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **matérialiser automatiquement une `Application` locale depuis le dépôt SambaEdu (la source que controlHub référence) dès qu'un ordre d'install amont (31.2) vise un `app_id` absent de mon inventaire**,
so that **l'ordre soit PLEINEMENT honoré — l'app entre dans l'ensemble désir d'état servi à l'agent au lieu d'être ignorée (skip+warn) — ce qui clôt FR6 et comble le gap D4 de la Story 31.2**.

> Story **3ᵉ et dernière** de l'Epic 31 (« Dépôt applicatif borné & install pilotée »), **complétant FR6**. 31.2 livre le **pilotage** (l'ordre amont → ensemble désir d'état), mais laisse un trou (D4) : si l'`app_id` ordonné n'a **pas** de ligne `Application` locale, l'hydratation 27.5 le **skip+warn** et l'ordre n'est pas livré. 31.3 **comble ce trou** en approvisionnant l'`Application` depuis le **dépôt SambaEdu**, source canonique des installeurs.
>
> **Modèle métier (rappel)** : le **dépôt SambaEdu** est la SOURCE canonique des recettes/installeurs (les colonnes `Application.installer_url`/`installer_sha256`/`installer_filename`/`xml_url`/`xml_sha`/`depot_id` sont peuplées depuis un `Depot` via `DepotApplication`). **controlHub n'expose qu'une VUE FILTRÉE** de ce dépôt (le catalogue amont = sous-ensemble autorisé), **stable** (mêmes données à chaque réception). Aujourd'hui `controlhub_contract_catalog_apps` ne porte que `{app_key, display_name}` — **pas la source**. 31.3 **étend** le catalogue pour porter la **référence de source**, puis **réutilise** le pipeline de dépôt existant (`DepotSyncService` / `DepotApplication` / `Depot`) pour matérialiser la ligne `Application`.
>
> **Matérialisation ≠ installation serveur (CRITIQUE).** 31.3 crée **uniquement la ligne d'inventaire `Application`** (status `Available`, métadonnées + recette WPKG) pour que l'**agent** l'installe sur le poste via WPKG (FR21, « un tuyau, deux outils »). Elle ne déclenche **PAS** le téléchargement/installation côté serveur — donc **PAS** `AppStoreService::installApplication()` (qui, lui, télécharge + lance le pipeline d'install serveur).

> **⚠️ Décision Henri (héritée 31.1/31.2) — pas de compat ascendante** : seul invariant intangible = enrôlement controlHub + contrat agent figé. Garde-fous « non-régression » = bon design (NFR3 standalone, golden/`ContractV1` intacts).

## Contexte du code (constat vérifié)

**Le pipeline de dépôt existe et matérialise déjà des `Application` — NE PAS réinventer la sync** :
- `DepotSyncService::syncDepot(Depot $depot): array` [app/Services/AppStore/DepotSyncService.php:62] — synchro **CIBLÉE** d'UN dépôt : récupère son XML distant, compare `depot.xml_hash`, upsert `depot_applications` sur la clé naturelle `(depot_id, app_id, branch)` + prune. `syncAllDepots()` [:30] pour tous les `Depot::active()`.
- `DepotApplication` [app/Models/DepotApplication.php] — miroir du catalogue **distant** (non installé) : `depot_id, app_id, name, version, category, compatibility, branch, xml, xml_url, xml_sha, log_url, icon_url`. Unique `(depot_id, app_id)`.
- `Depot` [app/Models/Depot.php] — `name, url, is_primary, is_active, xml_hash` ; `Depot::active()`.
- **Le chemin de matérialisation `DepotApplication → Application` EXISTE** : `AppStoreService::installApplication(DepotApplication $depotApp, …): InstallationLog` [app/Services/AppStore/AppStoreService.php:84] fait `Application::firstOrCreate(['app_id' => $depotApp->app_id], [depot_id, name, version, category, compatibility, branch, xml, xml_url, xml_sha, log_url, icon_url, status: Downloading])` [:93-111]. **⚠️ MAIS** il enchaîne ensuite un **téléchargement + install serveur** [:120+] — donc **non réutilisable tel quel** pour 31.3 (qui ne veut QUE la ligne d'inventaire). Le **bloc `firstOrCreate`** [:93-111] est le **seed réutilisable** (à extraire en méthode « matérialisation seule »).

**Le catalogue amont est trop maigre pour matérialiser** :
- Migration `database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php:165-188` — `controlhub_contract_catalog_apps` = `{id, controlhub_contract_id, app_key, display_name, timestamps}`, clé naturelle `(controlhub_contract_id, app_key)`. **Aucune** colonne de source/dépôt.
- `ControlHubContractCatalogApp` [app/Models/ControlHubContractCatalogApp.php] — fillable `{controlhub_contract_id, app_key, display_name}`.
- `ControlHubContractIngestionService::normalizeCatalogApps()` [app/Services/ControlHub/ControlHubContractIngestionService.php:459-480] — lit `app_key` (requis) + `display_name` (optionnel) ; `reconcileChildren()` [:221-260] upsert+prune sur la clé naturelle (idempotence 28.2, NFR4).

**Le patron « réconcilier l'état imposé à la réception du contrat » existe (30.3)** :
- Event `App\Events\ControlHubContractChanged` [app/Events/ControlHubContractChanged.php] émis par `ControlHubContractIngestionService::ingest()` quand le contrat change.
- Listener `App\Listeners\ReconcileImposedWorkstationGroups` (enregistré `EventServiceProvider.php:38-39`) + commande `App\Console\Commands\ReconcileImposedWorkstationGroups` — réconciliation idempotente, re-jouable, court-circuit NFR3 sans contrat actif. **31.3 calque exactement ce patron** pour « les apps ordonnées doivent exister en inventaire ».

**Les ordres d'install (31.2)** = `controlhub_contract_items` `type='applications'`, `key=<app_id>`. 31.3 lit ces app_id et matérialise ceux absents de `applications`, **avant** que 31.2 ne les hydrate.

## Acceptance Criteria

1. **Given** un contrat amont **actif** porte (a) un ordre d'install `controlhub_contract_items{type:'applications', key:<app_idX>}` et (b) une entrée catalogue `controlhub_contract_catalog_apps{app_key:<app_idX>}` portant la **référence de source** du dépôt SambaEdu, **et** `app_idX` **n'existe pas** en `applications`,
   **When** le contrat est ingéré (`ControlHubContractChanged`) — ou la commande de réconciliation jouée,
   **Then** une ligne `Application{app_id:<app_idX>}` est **matérialisée** depuis la source (status `Available`, métadonnées + recette WPKG `xml_url`/`xml_sha`), **sans** téléchargement ni install serveur.

2. **Given** AC1 a matérialisé `app_idX`,
   **When** l'agent du poste ciblé par l'ordre fait son check-in (chaîne 31.2),
   **Then** `app_idX` figure désormais dans l'`applications` désiré (l'hydratation 27.5 trouve la ligne — **plus de skip+warn**), l'ordre est **pleinement honoré**.

3. **Given** `app_idX` **existe déjà** en `applications` (matérialisé antérieurement ou présent localement),
   **When** le contrat est ré-ingéré / la réconciliation rejouée,
   **Then** **aucune** nouvelle ligne ni écrasement (`firstOrCreate` no-op) — **idempotence** (NFR4) ; la matérialisation ne **touche jamais** une `Application` locale préexistante (status/métadonnées préservés).

4. **Given** une instance SE5 **sans contrat actif** (standalone) **ou** un contrat **sans ordre d'install**,
   **When** un changement de contrat survient (ou rien),
   **Then** **aucune** matérialisation, **aucune** synchro de dépôt déclenchée par ce mécanisme (court-circuit NFR3) ; le comportement dépôt/AppStore est **strictement inchangé**.

5. **Given** le catalogue amont est **étendu** pour porter la référence de source,
   **When** un contrat est ingéré,
   **Then** l'ingestion (28.2) lit et persiste ces champs **idempotemment** sur la **même clé naturelle** `(controlhub_contract_id, app_key)` (upsert+prune inchangés) ; un contrat sans champ source reste accepté (rétrocompatibilité du payload — champs **optionnels**, NFR3/NFR4 préservés) ; aucun « central » (R3).

6. **Given** un ordre vise un `app_id` **sans entrée catalogue** (ou dont la source est introuvable/sync en échec),
   **When** la réconciliation s'exécute,
   **Then** l'app est **laissée non matérialisée** + **journalisée** (`agent`/`controlhub` log), **sans** exception qui casserait l'ingestion ni les autres matérialisations — re-tentable via la commande (résilience). (31.2 retombe sur son skip+warn pour cette app.)

7. **Given** le contrat agent figé `se5.desired-state/v1`,
   **When** 31.3 est livrée,
   **Then** **aucun** champ/clé/type du wire format n'est touché (31.3 matérialise une `Application` en DB, le payload `applications` `{app_id,name}` est inchangé) : golden `state.v1.json`, `ContractV1`, `StateHasher` **intacts**.

8. **Given** la suite de tests HÔTE (php8.4 + sqlite),
   **When** elle s'exécute,
   **Then** sont verts : matérialisation depuis source (AC1), honneur via 31.2 (AC2), idempotence/non-écrasement (AC3), standalone/sans-ordre court-circuit (AC4), ingestion source idempotente (AC5), ordre sans source = log sans crash (AC6), golden intacts (AC7) — **sans régression** des suites `AppStore`/`Depot*`/`ControlHubContract*`/`Agent` existantes.

## Tasks / Subtasks

- [x] **T0 — Cadrage & décision de la forme de source (D1 — TRANCHÉE : Option B par-app)** (AC: #1, #5)
  - [x] **DÉCISION HENRI (2026-06-29)** : **Option B — source par-app**. Le catalogue porte `source_xml_url` (string nullable) + `source_xml_sha` (string nullable) [+ métadonnées optionnelles `version`/`category` si déjà présentes au payload]. **Matérialisation DIRECTE** : SE5 crée la ligne `Application` à partir de ces champs de source **SANS** synchro de dépôt complet (`DepotSyncService::syncDepot()` **NON utilisé** dans 31.3) et **SANS** créer de `Depot`/`DepotApplication`. Plus léger, hors pipeline de sync de dépôt. (Impacte le payload d'échange controlHub↔SE5 — territoire Epic 33.)
  - [x] Garde-fou de confiance : `source_xml_url` provient du **contrat amont actif** (autorité **enrôlée**, de confiance) — fetch acceptable ; documenter (pas de fetch d'URL arbitraire hors contrat actif). NB : 31.3 ne fait **aucun** fetch réseau lui-même — `xml_url`/`xml_sha` sont stockés dans la ligne `Application` (recette WPKG consommée par l'agent).

- [x] **T1 — Étendre le catalogue amont pour porter la source** (AC: #5) — *extension transverse 28.1/28.2 (en `review`), assumée*
  - [x] **Migration** additive (NOUVELLE migration, **ne pas réécrire** `2026_06_26_100000_*`) : ajouter les colonnes de source **Option B** — `source_xml_url` (string nullable) + `source_xml_sha` (string nullable) à `controlhub_contract_catalog_apps`, **nullable** (rétrocompat NFR3). **NE PAS** changer la clé naturelle `(controlhub_contract_id, app_key)`.
  - [x] `ControlHubContractCatalogApp` : ajouter les colonnes au `$fillable`.
  - [x] `ControlHubContractIngestionService::normalizeCatalogApps()` [:459-480] : lire les champs de source (optionnels, `null`/'' → `null` comme `display_name`) et les inclure dans `attrs` ; `reconcileChildren()` upsert+prune **inchangé** (même clé). Idempotence (28.2) préservée — un contrat sans source = accepté, no-op sur ces colonnes.
  - [x] Tests d'ingestion : un `catalog_apps[]` avec source → persistée ; ré-réception identique = no-op ; sans source = accepté. **NE PAS** dupliquer le schéma (extension, pas réécriture) ; R3.

- [x] **T2 — Méthode de matérialisation SEULE (sans install serveur)** (AC: #1, #3) — *Option B : depuis champs de source par-app, PAS de `DepotApplication`*
  - [x] Ajouter une méthode de matérialisation directe acceptant les **champs de source par-app** (pas un `DepotApplication`). Forme recommandée : `AppStoreService::materializeFromSource(string $appId, array $source): Application` (ou service dédié `UpstreamOrderProvisioner`), où `$source` = `{name, xml_url, xml_sha[, version, category]}` issus de l'entrée catalogue. **Extraire/adapter** le bloc `Application::firstOrCreate(['app_id' => $appId], [...])` de `installApplication()` [:93-111] en l'alimentant depuis `$source` au lieu d'un `DepotApplication`, status **`Available`** (PAS `Downloading`), **SANS** la suite (download/parse/install) et **SANS** créer de `Depot`/`DepotApplication`. Idempotent (`firstOrCreate` no-op si la ligne existe — AC3, ne touche jamais une `Application` locale préexistante).
  - [x] **Garde-fou (documenter)** : 31.3 **n'appelle JAMAIS** `installApplication()` (install serveur). La pose réelle sur le poste = agent + WPKG (la ligne `Application` porte `xml_url`/`xml_sha` = recette WPKG).

- [x] **T3 — Provisionneur d'ordres (listener + commande, patron 30.3)** (AC: #1, #2, #4, #6)
  - [x] Listener `App\Listeners\ProvisionOrderedApplications` sur `ControlHubContractChanged` (enregistrer dans `EventServiceProvider.php:38`, à côté de `ReconcileImposedWorkstationGroups`) **+** commande `controlhub:provision-ordered-apps` (calquer `App\Console\Commands\ReconcileImposedWorkstationGroups`) — réconciliation **idempotente, re-jouable**.
  - [x] Logique (**Option B — matérialisation directe**) : **court-circuit NFR3** si pas de contrat actif (`ControlHubContract::active()` null) → no-op. Sinon : lire les `app_id` des items `type='applications'` du contrat actif ; pour chaque `app_id` **absent** de `applications` :
    1. résoudre l'entrée catalogue `controlhub_contract_catalog_apps{app_key}` → champs de source par-app (`source_xml_url`/`source_xml_sha`) ; entrée **absente** OU `source_xml_url` vide → log + `continue` (AC6) ;
    2. `AppStoreService::materializeFromSource($appId, $source)` → `Application` matérialisée (status `Available`, `xml_url`/`xml_sha` peuplés). **PAS** de `Depot`/`DepotApplication`/`syncDepot`.
  - [x] **Résilience** : une app en échec (source absente/`source_xml_url` vide, exception de matérialisation) **n'interrompt PAS** l'ingestion ni les autres matérialisations (try/catch par app + log `agent.applications.provisioned` / `…provision_failed`). L'ingestion 28.2 ne doit **jamais** être cassée par 31.3 (le listener s'exécute **après** la transaction d'ingestion).
  - [x] **Idempotence globale** : `Application::firstOrCreate` ⇒ réconciliation rejouable sans effet ni doublon (AC3). (Option B = pas de dépendance à l'idempotence de `DepotSyncService`.)

- [x] **T4 — Tests HÔTE (php8.4 + sqlite)** (AC: #1–#8)
  - [x] Harnais : factories `ControlHubContract`/`ControlHubContractItem`/`ControlHubContractCatalogApp`, `Depot`/`DepotApplication`, `Application` ; `WpkgSchemaBootstrapper` (tables controlhub + dépôt/applications). Mocker/faker la résolution HTTP de `DepotSyncService` (pas d'appel réseau réel en test — injecter un `DepotApplication` pré-créé pour court-circuiter le fetch, ou stub HTTP).
    - **AC1** : ordre + catalogue avec source + pas d'`Application` ⇒ après listener/commande, `Application{app_id}` existe (status `Available`, `xml_url` peuplé).
    - **AC2** : enchaînement avec 31.2 — après matérialisation, `ApplicationsStateProvider`/`StateCompiler` émet l'app dans `machine.applications` (plus de skip).
    - **AC3** : `Application` préexistante (status `Installed`, métadonnées custom) ⇒ réconciliation = **no-op**, ligne **inchangée** (assert status/colonnes).
    - **AC4** : pas de contrat (ou pas d'item `applications`) ⇒ aucune `Application` créée, **aucune** synchro de dépôt (spy `DepotSyncService` non appelé ; query log).
    - **AC5** : ingestion d'un `catalog_apps[]` avec source ⇒ colonnes persistées ; ré-ingestion identique = no-op (28.2) ; sans source = accepté.
    - **AC6** : ordre sans entrée catalogue / source introuvable ⇒ pas de crash, log émis, autres apps du lot **quand même** matérialisées.
    - **AC7** : `ContractV1Test` + golden `state.v1.json` ⇒ **verts inchangés**.
  - [x] **Pièges** : SQLite n'applique pas varchar/enum PG → tester décisions (présence/absence/status/count), pas bornes de colonne. Matcher sur `app_id` (string).

- [x] **T5 — Runbook QA** (AC: #1–#6)
  - [x] **Append** `## Section 16 — Approvisionnement auto d'une app ordonnée (Story 31.3)` à `docs/qa/domains/controlhub-contract.md` (append-only ; dernière = Section 15 / 31.2). Scénarios : poser ordre + catalogue-avec-source pour un app_id absent localement → ingérer → vérifier `Application` matérialisée (status Available) → `curl GET /api/v1/agent/state` ⇒ app présente ; ordre sans source ⇒ app non matérialisée + log ; ré-ingestion ⇒ idempotent ; standalone ⇒ inchangé ; commande `php artisan controlhub:provision-ordered-apps` rejouable.
  - [x] Mettre à jour la **ligne cumulative** du domaine dans `docs/qa/README.md` (mention Story 31.3, suffixe la ligne 31.2).

- [x] **T6 — Validation finale & garde-fous** (AC: #4, #7)
  - [x] `php artisan test --filter "AppStore|Depot|ControlHubContract|Provision|Agent|ContractV1|StateCompiler"` sur HÔTE → vert, 0 régression.
  - [x] **R3** : `grep -rin central` sur les fichiers livrés ⇒ uniquement en-têtes garde-fou.
  - [x] **NFR3** : court-circuit prouvé (pas de contrat → listener no-op, `DepotSyncService` jamais appelé).
  - [x] **Contrat agent figé** : `git diff` ⇒ aucune ligne sur `StateContract.php`/`StateHasher.php`/`tests/Fixtures/Agent/*.json`/`ContractV1`.

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 31.3

**DANS** : extension du catalogue amont pour porter la **référence de source** (migration additive + `$fillable` + `normalizeCatalogApps`) ; méthode de **matérialisation seule** `materializeFromDepot` (status Available, sans install serveur) ; **provisionneur** (listener `ControlHubContractChanged` + commande, patron 30.3) résolvant source → (sync dépôt ciblé) → `DepotApplication` → `Application` ; tests ; runbook QA.

**HORS** (ne pas déborder) :
- **Installation serveur de l'app** (download binaire/recette côté SE5) : NON. 31.3 ne crée que la **ligne d'inventaire** ; l'install réelle sur le poste = agent + WPKG (FR21). NE PAS appeler `installApplication()`.
- **Retrait / interdiction piloté** : autre FR (cf. 31.2 — le retrait existe par omission via `profiles.xml`/WPKG ; l'interdiction = soustraction = FR ultérieur).
- **Interdiction/filtrage d'install WPKG côté SE5** : le filtrage est **à la source** (controlHub-as-depot, vue filtrée) — pas d'enforcement SE5 (cf. re-cadrage 31.2).
- **Authoring controlHub** (UI de composition + génération de la vue filtrée du dépôt) — hors repo SE5.
- **Refonte de `DepotSyncService`/AppStore** : on **réutilise** (sync ciblée + seed `firstOrCreate`), on ne réinvente pas.
- **Release à la rupture (FR7)** → Story 32.1 (les `Application` matérialisées sont des ajouts locaux **conservés** à la rupture — D4 de 32.1).

### Décisions de cadrage

- **D1 — Forme de la source (TRANCHÉE 2026-06-29 : Option B par-app)** : `source_xml_url` + `source_xml_sha` portés par le catalogue → **matérialisation directe** de l'`Application` (status Available, recette WPKG), **SANS** `Depot`/`DepotApplication`/`DepotSyncService::syncDepot`. Plus léger, hors pipeline de sync de dépôt. (Impacte le payload d'échange — Epic 33.) ⇒ `DepotSyncService` **jamais appelé** par 31.3 (AC4 trivialement satisfait : aucune synchro de dépôt déclenchée par ce mécanisme — le spy de test ne doit JAMAIS voir d'appel).
- **D2 — Déclenchement à l'ingestion, pas au check-in** : la matérialisation (qui peut faire un fetch HTTP de dépôt) vit dans un **listener `ControlHubContractChanged`** (+ commande re-jouable), **JAMAIS** dans `ApplicationsStateProvider`/`GET /state` (hot path ~0,7 req/s, NFR4 — pas de HTTP dépôt synchrone dans la requête agent). Envisager une **queue** pour la synchro de dépôt.
- **D3 — `firstOrCreate`, jamais d'écrasement** : une `Application` locale préexistante (même `app_id`) est **intouchée** (AC3) — la matérialisation ne fait qu'ajouter ce qui manque. Idempotence par construction.
- **D4 — Ordre sans source = dégradé gracieux** : log + skip (AC6), pas d'exception. L'app retombe sur le skip+warn de 31.2. Re-tentable via la commande.

### Patrons de référence à IMITER (ne rien réinventer)

- **`ReconcileImposedWorkstationGroups`** (listener `app/Listeners/` + commande `app/Console/Commands/`, 30.3) — **patron exact** du provisionneur 31.3 : réconciliation idempotente sur `ControlHubContractChanged`, court-circuit NFR3, commande jumelle re-jouable.
- **`DepotSyncService::syncDepot()`** [app/Services/AppStore/DepotSyncService.php:62] — synchro CIBLÉE d'un dépôt (réutiliser, ne pas réimplémenter le fetch/parse/upsert).
- **`AppStoreService::installApplication()` bloc `firstOrCreate`** [app/Services/AppStore/AppStoreService.php:93-111] — **seed** de matérialisation à extraire (status Available, sans la suite install).
- **`ControlHubContractIngestionService::normalizeCatalogApps()` / `reconcileChildren()`** [:459-480, :221-260] — patron upsert+prune idempotent sur clé naturelle (étendre, ne pas changer la clé).
- **31.1 `WpkgSchemaBootstrapper` augmenté** (`controlhub_contracts`/`controlhub_contract_catalog_apps`) — harnais de test à réutiliser/compléter (colonnes source + `depot_applications`/`depots` si absentes).

### Garde-fous projet CRITIQUES

- **NFR3 — standalone byte-identique** : pas de contrat actif (ou pas d'ordre) ⇒ provisionneur no-op, `DepotSyncService` non appelé, AppStore inchangé. Tester (AC4).
- **NFR4 — idempotence/déterminisme** : `firstOrCreate` (Depot+Application) + `DepotSyncService` + ré-ingestion 28.2 ⇒ réconciliation rejouable sans effet ni doublon. Tester (AC3).
- **Contrat agent figé (BLOQUANT)** : `se5.desired-state/v1` (golden/`ContractV1`/`StateHasher`) INTACT — 31.3 matérialise une `Application` (DB), jamais le wire format. [Source: epics-agent-desired-state.md#FR5]
- **R3 — vocabulaire (BLOQUANT)** : aucun « central » ; « amont »/`Upstream`/`ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
- **Extension transverse 28.1/28.2 assumée** : migration **additive** (jamais réécrire la migration en review), clé naturelle préservée, champs source **optionnels** (rétrocompat). Re-synchroniser si une correction review de 28.1/28.2 touche `controlhub_contract_catalog_apps`/`normalizeCatalogApps`.
- **Tests HÔTE uniquement** (php8.4 + `pdo_sqlite`), **jamais la VM**. Mocker la résolution HTTP de dépôt (pas de réseau en test). [Source: mémoire — phpunit_test_env_host_vs_vm]
- **Racine = projet Laravel**. [Source: mémoire — root_is_laravel]

### Project Structure Notes

- **Nouveaux** : migration additive `database/migrations/2026_06_2x_*_add_source_to_controlhub_catalog_apps.php` ; `app/Listeners/ProvisionOrderedApplications.php` ; `app/Console/Commands/ProvisionOrderedApplications.php` (ou nom proche) ; éventuel `app/Services/ControlHub/UpstreamOrderProvisioner.php` ; tests `tests/Feature/ControlHub/UpstreamOrderProvisioningTest.php`.
- **Modifiés** : `app/Models/ControlHubContractCatalogApp.php` (+`$fillable`) ; `app/Services/ControlHub/ControlHubContractIngestionService.php` (`normalizeCatalogApps` +champs source) ; `app/Services/AppStore/AppStoreService.php` (+`materializeFromDepot`) ; `app/Providers/EventServiceProvider.php` (+listener) ; `tests/Support/WpkgSchemaBootstrapper.php` (colonnes source + tables dépôt si absentes) ; `docs/qa/domains/controlhub-contract.md` (Section 16) ; `docs/qa/README.md`.

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 31.2 / FR6] — FR6 « install désir d'état » ; 31.3 en complète le gap D4.
- [Source: _bmad-output/planning-artifacts/handoff-controlhub-contrat-manage.md §7] — controlHub = autorité du catalogue/dépôt, vue filtrée.
- [Source: _bmad-output/implementation-artifacts/31-2-declenchement-install-desir-etat.md] — déclencheur (gap D4 explicite).
- [Source: app/Services/AppStore/DepotSyncService.php:30,62] — sync dépôt (ciblée).
- [Source: app/Services/AppStore/AppStoreService.php:84,93-111] — `installApplication` (seed `firstOrCreate`, à NE PAS appeler en entier).
- [Source: app/Models/Depot.php ; app/Models/DepotApplication.php ; app/Models/Application.php] — schéma dépôt/installeur (`installer_url`/`xml_url`/`depot_id`).
- [Source: database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php:165-188] — `controlhub_contract_catalog_apps` (à étendre).
- [Source: app/Services/ControlHub/ControlHubContractIngestionService.php:459-480,221-260] — `normalizeCatalogApps` + upsert/prune.
- [Source: app/Events/ControlHubContractChanged.php ; app/Listeners/ReconcileImposedWorkstationGroups.php ; app/Console/Commands/ReconcileImposedWorkstationGroups.php ; app/Providers/EventServiceProvider.php:38] — patron listener+commande 30.3.
- [Source: mémoires projet — agent_desired_state_direction, iso_storage_relocated_and_pipeline_gaps, phpunit_test_env_host_vs_vm, root_is_laravel].

## Dépendances

- **Amont** :
  - **31.2** (`ready-for-dev`) — **le déclencheur** : 31.2 pose les ordres d'install + l'union dans l'ensemble désir d'état ; 31.3 garantit l'existence de la `Application`. Dépendance **fonctionnelle directe** (31.3 comble le gap D4 de 31.2). Développer 31.2 **d'abord**.
  - **28.1 + 28.2** (`review`) — schéma/ingestion du catalogue à **étendre** (migration additive + `normalizeCatalogApps`). Re-synchroniser si correction review.
  - **`DepotSyncService`/`Depot`/`DepotApplication`/`AppStoreService`** (livrés, AppStore) — pipeline de dépôt réutilisé.
  - **30.3** (`review`) — patron listener `ControlHubContractChanged` + commande réconciliation (calqué).
  - **27.5** (livré) — `ApplicationsStateProvider` (consommateur final, via 31.2).
- **Aval** :
  - **Story 32.1** (FR7) — release à la rupture : les `Application` matérialisées = **ajouts locaux conservés** (32.1 garantit la conservation). **Clôt l'Epic 31.**
- **Réutilise** : domaine ControlHub (28/30/31) + AppStore/dépôt (15.x/AppStore) — livrés.

## Testing

- **Cible : HÔTE** (php8.4 + `pdo_sqlite`), factories contrat/catalogue/dépôt/applications + `WpkgSchemaBootstrapper`. **Jamais la VM.** **Mocker** la résolution HTTP de `DepotSyncService` (pré-créer le `DepotApplication` cible pour court-circuiter le fetch réseau).
- Couverture : AC1 (matérialisation), AC2 (honneur via 31.2), AC3 (idempotence/non-écrasement), AC4 (standalone/sans-ordre court-circuit + `DepotSyncService` non appelé), AC5 (ingestion source idempotente), AC6 (ordre sans source = log sans crash), AC7 (golden/`ContractV1` intacts).
- **Pièges** : SQLite ≠ contraintes PG → tester décisions. Matcher `app_id` (string). Pas de réseau en test.

## Recommandation Modèle Dev

**`opus`.**

Justification : story **transverse et à frontières fines**. Elle touche **deux sous-systèmes** (catalogue ControlHub + pipeline dépôt AppStore) et **étend des migrations/ingestion en review** (28.1/28.2) — risque d'incohérence de clé naturelle / d'idempotence. Les pièges sont conceptuels : (1) **matérialiser sans installer** (ne PAS appeler `installApplication` qui télécharge+installe serveur — confusion des deux canaux d'install = bug structurel) ; (2) **hot-path vs réception** (la synchro HTTP de dépôt doit vivre dans un listener/queue, jamais dans `GET /state` — NFR4) ; (3) **idempotence multi-niveaux** (`firstOrCreate` Depot+Application + sync + ré-ingestion) sans écraser une `Application` locale ; (4) **contrat agent figé** intact alors qu'on alimente sa source de données ; (5) un **point de design ouvert** (forme de la source, D1) à arbitrer proprement. Jugement d'architecte (réutilisation stricte du pipeline existant + séparation des responsabilités), pas une recette. Review par modèle opposé (sonnet/fable) sur les angles morts idempotence/install-serveur.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story)

### Debug Log References

- `php artisan test --filter "UpstreamOrderProvisioning|ControlHubContractIngestion"` → **28 passed** (179 assertions) — net-new + AC5 ingestion source.
- `php artisan test --filter "AppStore|Depot|ControlHubContract|Provision|Agent|ContractV1|StateCompiler"` → **674 passed, 22 skipped** (env : LDAP/zip/imagick), **0 régression**, 2340 assertions.
- R3 : `grep -rin central` sur les 11 fichiers livrés ⇒ uniquement 4 en-têtes garde-fou (« aucun mot central »). Aucun identifiant/message « central ».
- Contrat agent figé : `git status`/`git diff` ⇒ **aucune** ligne sur `StateContract.php`/`StateHasher.php`/`tests/Fixtures/Agent/*.json`/`ContractV1` (inclus dans le filtre de non-régression, verts).

### Completion Notes List

- **T0 (D1 = Option B par-app)** : déjà tranché ; aucune création de `Depot`/`DepotApplication`/`DepotSyncService::syncDepot` — matérialisation DIRECTE depuis les champs de source du catalogue.
- **T1** : migration ADDITIVE `2026_06_29_100000_add_source_to_controlhub_catalog_apps.php` (`source_xml_url`+`source_xml_sha` nullables, clé naturelle inchangée) ; `ControlHubContractCatalogApp` +`$fillable`+docblock ; `normalizeCatalogApps()` lit les champs optionnels (`null/''→null`), `reconcileChildren()` upsert+prune INCHANGÉ. Idempotence 28.2 préservée (tests AC5).
- **T2** : `AppStoreService::materializeFromSource(string $appId, array $source): Application` — `firstOrCreate` sur `app_id`, status **`Available`**, `xml_url`/`xml_sha` posés ; **n'appelle JAMAIS** `installApplication()` ni le pipeline de dépôt.
- **T3** : service `OrderedApplicationProvisioner` (patron 30.3) + DTO `OrderedApplicationProvisioningResult` + listener `ProvisionOrderedApplications` (enregistré dans `EventServiceProvider` à côté de `ReconcileImposedWorkstationGroups`) + commande `controlhub:provision-ordered-apps`. Court-circuit NFR3 (pas de contrat actif → no-op). Pour chaque ordre `type='applications'` non-`absent` dont l'`app_id` manque : résout la source catalogue → matérialise ; entrée absente/source vide → log `agent.applications.provision_skipped` + `continue` (AC6) ; résilience try/catch par app (`…provisioned`/`…provision_failed`). L'ingestion 28.2 n'est jamais cassée (listener post-commit + try/catch).
- **T4** : `tests/Feature/ControlHub/UpstreamOrderProvisioningTest.php` (14 tests, AC1–AC4/AC6 + listener + commande) sur HÔTE via `WpkgSchemaBootstrapper` (offline) ; AC5 (4 tests) ajoutés à `ControlHubContractIngestionTest`. `DepotSyncService` **spy** prouve qu'il n'est JAMAIS appelé (AC4). `WpkgSchemaBootstrapper` augmenté (colonnes source sur catalog_apps + colonnes dépôt/recette sur `applications`).
- **T5** : Section 16 appendée à `docs/qa/domains/controlhub-contract.md` + checklist 31.3 ; ligne cumulative du domaine suffixée (31.3) dans `docs/qa/README.md`.
- **T6** : suites ciblées vertes (0 régression) ; R3 OK ; NFR3 court-circuit prouvé (AC4 + commande standalone) ; contrat agent figé intact.
- **Écart vs Dev Notes** : la section « Périmètre » mentionne historiquement `materializeFromDepot`/sync dépôt (rédigée avant D1) ; la décision tranchée D1 (Option B par-app) prime — méthode nommée `materializeFromSource`, AUCUN `DepotSyncService`/`Depot`/`DepotApplication`. Conforme à T0/T1/T2/T3 et à D1.

### File List

**Créés :**
- `database/migrations/2026_06_29_100000_add_source_to_controlhub_catalog_apps.php`
- `database/migrations/2026_06_29_110000_add_materialized_app_id_unique_to_applications.php` *(review #A — index unique partiel `app_id` WHERE `depot_id IS NULL`)*
- `app/Services/ControlHub/OrderedApplicationProvisioner.php`
- `app/Services/ControlHub/Data/OrderedApplicationProvisioningResult.php`
- `app/Listeners/ProvisionOrderedApplications.php`
- `app/Console/Commands/ProvisionOrderedApplications.php`
- `tests/Feature/ControlHub/UpstreamOrderProvisioningTest.php`
- `_bmad-output/codeReviews/31-3.md` *(document de review)*

**Modifiés :**
- `app/Models/ControlHubContractCatalogApp.php` (+`$fillable` source + docblock)
- `app/Services/ControlHub/ControlHubContractIngestionService.php` (`normalizeCatalogApps` +champs source)
- `app/Services/AppStore/AppStoreService.php` (+`materializeFromSource`)
- `app/Services/ControlHub/OrderedApplicationProvisioner.php` *(review #7 — simplification garde source vide)*
- `app/Listeners/ProvisionOrderedApplications.php` *(review #C — log de synthèse `provision_summary`)*
- `app/Providers/EventServiceProvider.php` (+listener `ProvisionOrderedApplications`)
- `tests/Support/WpkgSchemaBootstrapper.php` (colonnes source catalog_apps + colonnes dépôt/recette `applications` + index unique partiel review #A)
- `tests/Feature/ControlHub/ControlHubContractIngestionTest.php` (+4 tests AC5)
- `tests/Feature/ControlHub/UpstreamOrderProvisioningTest.php` *(review #1/#2/#5 — spy réel, chemin exception + exit FAILURE, R3 réflexion)*
- `docs/qa/domains/controlhub-contract.md` (Section 16 + checklist 31.3)
- `docs/qa/README.md` (ligne cumulative domaine controlhub-contract suffixée 31.3)
- `_bmad-output/planning-artifacts/epics-contrat-manage-se5.md` *(report backlog 32.x — review #6/#B)*

### Review Record (corrections post-review 2026-06-29)

Review sonnet (7 findings) + second avis opus (pertinence + 4 findings manqués). **Corrigés** : #1 (spy `DepotSyncService` dans chemin de matérialisation réel), #2 🔴 (test exception par-app + exit FAILURE commande), #5 (test R3 réflexion sur les 4 classes 31.3), #7 (cleanup garde), #C (log synthèse listener), **#A 🟠** (index unique partiel `app_id` — décision utilisateur). **Reportés backlog 32.x** : #6/#B (réalignement recette + flag `managed_by_control_hub`). **Non retenus** (négligeables) : #3 (double `active()`), #4 (N+1 cold path), #D (`permissive` intentionnel). Validation : provisioning **17/17**, périmètre review **195 passed**, 0 régression. Détail : `_bmad-output/codeReviews/31-3.md`.
