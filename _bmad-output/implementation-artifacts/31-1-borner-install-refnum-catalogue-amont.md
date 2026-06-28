# Story 31.1: Borner l'install refnum au catalogue amont

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **refnum**,
I want **que mon canal d'install reste pleinement utilisable mais filtré au catalogue applicatif amont faisant autorité**,
so that **je puisse continuer à ajouter des apps à mes parcs/postes librement, mais uniquement depuis le catalogue que l'autorité amont autorise (FR5)**.

> Story **ouvrant l'Epic 31** (« Dépôt applicatif borné & install pilotée »). Elle livre **uniquement le bornage** du canal d'install refnum au catalogue amont. Elle **ne touche pas** le déclenchement d'install en désir d'état (→ Story 31.2, FR6), ni le verrou/permissif d'items de capacités (Epic 29), ni le ciblage par label (Epic 30).
>
> **Modèle métier (rappel)** : un contrat amont reçu de controlHub porte un **catalogue applicatif faisant autorité** (`controlhub_contract_catalog_apps`, livré en 28.1). Le bornage = « le canal d'install refnum est **conservé** mais **filtré** au catalogue amont » (PRD §5/§6 FR5). Apps hors catalogue → **non proposées** (consultation) et **non installables** (refus explicite « hors catalogue amont »). Sans contrat actif, ou contrat actif sans catalogue, le comportement SE5 reste **strictement inchangé** (NFR3).

> **⚠️ Décision Henri (2026-06-26, héritée de 29.1) — pas de compat ascendante exigée** : aucun environnement de prod à préserver ; seul invariant intangible = l'enrôlement controlHub. Les garde-fous « non-régression » ci-dessous visent le **bon design** (le standalone NFR3 byte-identique en l'absence de contrat), pas la rétrocompat d'appelants exotiques.

## Contexte du code (constat vérifié)

**Le catalogue amont existe déjà** (Epic 28, en `review` sur `main`) :
- `ControlHubContract::active(): ?self` [app/Models/ControlHubContract.php:64] — accesseur canonique du **contrat actif** (singleton « ≤ 1 actif », `link_state = active`), `null` si standalone. **À réutiliser — ne pas réécrire** le filtre `link_state`.
- `ControlHubContract::catalogApps(): HasMany` [app/Models/ControlHubContract.php:106] → `ControlHubContractCatalogApp` (table `controlhub_contract_catalog_apps`, colonne `app_key`).
- **Correspondance de clé confirmée par le code** : `controlhub_contract_catalog_apps.app_key` **==** `applications.app_id` [app/Models/ControlHubContractCatalogApp.php:15,25]. Le bornage matche donc sur `Application.app_id`.

**Le canal d'install refnum** (où le refnum propose/installe des apps sur ses parcs/postes) passe par :
- **Page parc** `resources/views/pages/parc/groups/[id]/index.blade.php` :
  - consultation : `getAvailableWpkgApplicationsProperty()` [:1373] → `Application::query()->whereNotIn('id', $existing)…` (liste des apps assignables au parc) ;
  - install : `addApplicationsToWorkstationGroup(...)` appelé [:1500] (sous `ensureWpkgAssignAuthorized()`) ;
  - bulk catégorie : `bulkPreviewAppIds = Application::query()->where('category', …)` [:1553] puis `addApplications($profile->id, …)` [:1601].
- **Page machine** `resources/views/pages/parc/machines/[id]/index.blade.php` :
  - consultation : `getAvailableWpkgApplicationsProperty()` [:775] → `Application::query()->whereNotIn('id', $existing)…` ;
  - install : `addApplicationsToWorkstation(...)` appelé [:864] (sous `ensureWpkgAssignAuthorized()`).
- **Couche service** `app/Services/AppProfile/AppProfileService.php` :
  - `addApplicationsToWorkstationGroup(int $groupId, array $applicationIds)` [:423] ;
  - `addApplicationsToWorkstation(int $workstationId, array $applicationIds)` [:505] ;
  - `addApplications(int $profileId, array $applicationIds)` [:186] (composition d'un AppProfile, ré-épandu sur les parcs) ;
  - sélecteurs de consultation : `listApplications(...)` [:813] et `listApplicationsForSelect()` [:844].

**Prérequis d'enforcement déjà posé (29.1, en `review`)** : le Gate WPKG est désormais scopé par périmètre (`assign-wpkg-workstationGroup`) et `AppProfileService` porte déjà un **garde defense-in-depth** `assertCanAssignWpkgOnGroup(?WorkstationGroup)` [app/Services/AppProfile/AppProfileService.php] appelé en tête des méthodes d'assignation. **Le bornage catalogue s'ajoute à côté de ce garde, suivant exactement le même patron** (no-op hors contexte / refus explicite sinon). Sans 29.1, un verrou apps serait « du théâtre » (NFR1) — c'est pourquoi 29.1 est prérequis de l'Epic 31.

## Acceptance Criteria

1. **Given** un contrat amont **actif** définit un catalogue applicatif **non vide** (≥ 1 `controlhub_contract_catalog_apps.app_key`),
   **When** le refnum consulte les apps assignables à un parc ou à un poste (page parc / page machine / sélecteur de profil),
   **Then** **seules** les apps dont `app_id` figure dans le catalogue amont sont **proposées** (les apps hors catalogue sont absentes de la liste).

2. **Given** un contrat amont actif avec catalogue non vide,
   **When** le refnum tente d'installer/assigner une app **hors catalogue** (parc, poste, ou composition de profil) — y compris via un payload Livewire forgé visant un `application_id` hors catalogue,
   **Then** l'opération est **refusée** en **couche service** (`AppProfileService` lève une `ApplicationNotInUpstreamCatalogException`), **aucune** ligne pivot n'est écrite,
   **And** l'UI affiche un message explicite « hors catalogue amont » (toast), sans « bouton qui ne fait rien » silencieux (FR8).

3. **Given** un contrat amont actif avec catalogue non vide,
   **When** le refnum installe/assigne une app **présente** dans le catalogue,
   **Then** l'opération **réussit** exactement comme aujourd'hui (le canal d'install reste pleinement utilisable — FR5 « conservé »).

4. **Given** une instance SE5 **sans contrat actif** (standalone),
   **When** le refnum consulte ou installe une app,
   **Then** le comportement est **strictement inchangé** : toutes les apps locales sont proposées et installables (NFR3 — aucune lecture du catalogue, court-circuit ; le résultat des requêtes de consultation est byte-identique au comportement pré-31.1).

5. **Given** un contrat amont **actif** dont le catalogue est **vide** (aucun `app_key`),
   **When** le refnum consulte ou installe une app,
   **Then** **aucun** bornage n'est appliqué (un catalogue vide ne verrouille pas le refnum hors de toutes ses apps) — comportement identique au standalone (décision D1, documentée en Dev Notes).

6. **Given** le bornage est posé,
   **When** une app est installée **hors canal refnum web** (console, agent desired-state, seeder, `Auth::check() === false`),
   **Then** le **garde de couche service** est inerte sur ce chemin (no-op), à l'identique du patron `assertCanAssignWpkgOnGroup` de 29.1 — pas de blocage des appelants non-web (decision Henri : casser un appelant non-web reste acceptable mais on vise le no-op propre).

7. **Given** la suite de tests HÔTE (php8.4 + sqlite, `RefreshDatabase`),
   **When** elle s'exécute,
   **Then** les cas couverts sont verts : consultation filtrée (AC1), refus install hors catalogue sans écriture (AC2), install en catalogue OK (AC3), standalone inchangé (AC4), catalogue vide = pas de bornage (AC5), appelant non authentifié non bloqué (AC6) — **sans régression** des suites `AppProfile` / `Wpkg` / `ControlHubContract` existantes.

## Tasks / Subtasks

- [x] **T1 — Résolveur du catalogue amont (source unique, mémoïsée)** (AC: #1, #4, #5)
  - [x] Créer `app/Services/ControlHub/UpstreamCatalogResolver.php` (`declare(strict_types=1)`, `final`), miroir de la discipline de mémoïsation de `UpstreamLockResolver` / `UpstreamContractSource` (résolution **une fois par requête/conteneur**, court-circuit NFR3) :
    - `isBounded(): bool` — `true` **uniquement** si `ControlHubContract::active()` existe **ET** son catalogue est **non vide**. `false` → aucun bornage (standalone OU catalogue vide, cf. D1).
    - `allowedAppIds(): array<string>` — liste mémoïsée des `app_key` du contrat actif (= `applications.app_id` autorisés). `[]` si `!isBounded()`.
    - `permits(string $appId): bool` — `true` si `!isBounded()` OU `$appId` ∈ `allowedAppIds()`.
    - **Court-circuit NFR3 (CRITIQUE)** : si `ControlHubContract::active()` est `null`, ne JAMAIS requêter `controlhub_contract_catalog_apps` (exactement la même discipline que `UpstreamContractSource::ensureResolved()` [app/Services/ControlHub/Resolution/UpstreamContractSource.php:309]).
  - [x] Réutiliser **strictement** `ControlHubContract::active()` [app/Models/ControlHubContract.php:64] — **ne pas** dupliquer le filtre `link_state`.
  - [x] PHPDoc d'en-tête : garde-fou R3 (aucun « central » ; vocabulaire « amont » / `Upstream` / `ControlHub*`), rappel de la correspondance `app_key == applications.app_id`, et caveat long-running (mémoïsation par-conteneur sûre en PHP-FPM ; sous Octane/worker, brancher `App\Events\ControlHubContractChanged` — même caveat que `UpstreamContractSource`).
  - [x] (Optionnel mais recommandé) Binding singleton dans un provider si l'injection conteneur l'exige ; sinon résolution via `app(UpstreamCatalogResolver::class)`. Vérifier le patron de binding ControlHub existant avant d'en ajouter un.

- [x] **T2 — Scope Eloquent de consultation `Application::scopeInUpstreamCatalog`** (AC: #1, #4, #5)
  - [x] Ajouter `public function scopeInUpstreamCatalog(Builder $query): Builder` dans `app/Models/Application.php` (miroir du patron `scopeUpdatable` [:194] / `Printer::scopeForUser` [app/Models/Printer.php:146]) : si `app(UpstreamCatalogResolver::class)->isBounded()` → `$query->whereIn('app_id', $resolver->allowedAppIds())` ; sinon **pass-through** (no-op, NFR3).
  - [x] Brancher le scope sur **toutes** les surfaces de consultation du canal d'install :
    - `getAvailableWpkgApplicationsProperty()` page **parc** [groups/[id]/index.blade.php:1379] : `Application::query()->inUpstreamCatalog()->whereNotIn('id', $existing)…`.
    - `getAvailableWpkgApplicationsProperty()` page **machine** [machines/[id]/index.blade.php:781] : idem.
    - `bulkPreviewAppIds` page parc [groups/[id]/index.blade.php:1553] : ajouter `->inUpstreamCatalog()` (sinon le bulk catégorie proposerait des apps hors catalogue qui seraient ensuite refusées par T3 — incohérence UX).
    - `AppProfileService::listApplicationsForSelect()` [:844] (sélecteur de composition de profil = canal d'install) : `Application::query()->inUpstreamCatalog()…`.
  - [x] **Décision de bord (D2, documenter dans la story)** : `AppProfileService::listApplications()` [:813] alimente la page **catalogue parc-settings** (gestion/inventaire du catalogue **local** issu du dépôt, pas le canal d'install vers les postes). **Ne pas** la filtrer par défaut (sinon des apps deviendraient invisibles à la gestion). Si l'analyse au dev montre qu'elle alimente AUSSI un canal d'install, la filtrer et le noter. Privilégier : filtrer **seulement** les surfaces qui matérialisent une **assignation vers parc/poste/profil**.

- [x] **T3 — Enforcement defense-in-depth en couche service** (AC: #2, #3, #6)
  - [x] Créer l'exception `app/Exceptions/ControlHub/ApplicationNotInUpstreamCatalogException.php` (miroir de `InvalidUpstreamContractException` / `UpstreamLockCollisionException` existantes dans `app/Exceptions/ControlHub/`), message utilisateur explicite « hors catalogue amont » + liste des `app_id` refusés. Garde-fou R3 dans le message.
  - [x] Ajouter un garde privé `AppProfileService::assertApplicationsInUpstreamCatalog(array $applicationIds): void` :
    - **(a) no-op si `Auth::check() === false`** (console/agent/seed — non-régression AC #6, exactement comme `assertCanAssignWpkgOnGroup`). [`Auth` déjà importé dans le service.]
    - **(b) no-op si `!UpstreamCatalogResolver::isBounded()`** (NFR3 / catalogue vide — AC #4/#5).
    - **(c) sinon** : résoudre les `app_id` des `$applicationIds` (`Application::whereIn('id', …)->pluck('app_id', 'id')`) et si **au moins un** n'est pas `permits()` → lever `ApplicationNotInUpstreamCatalogException` avant toute écriture.
  - [x] Appeler ce garde **en tête** (après `assertCanAssignWpkgOnGroup`, avant la transaction d'écriture) des chemins d'**install/assignation** :
    - `addApplicationsToWorkstationGroup` [:423] ;
    - `addApplicationsToWorkstation` [:505] ;
    - `addApplications` (composition de profil) [:186].
  - [x] **NE PAS** garder les chemins de **retrait** (`removeApplications*`) ni les chemins de **gestion de catalogue local** (création/import d'app depuis dépôt) : borner = filtrer ce qu'on **ajoute**, pas empêcher de **retirer** ou de gérer l'inventaire local. Documenter ce périmètre.

- [x] **T4 — UI : message explicite « hors catalogue amont »** (AC: #2, FR8)
  - [x] Sur les chemins d'install des pages parc/machine [groups:1500, machines:864, groups:1601], envelopper l'appel service d'un `try/catch (ApplicationNotInUpstreamCatalogException $e)` → `toastError($e->getMessage())` (le trait `WithToasts` est déjà mixé ; suivre exactement le patron du `try/catch` de `ensureWpkgAssignAuthorized`). Ne pas laisser l'exception remonter en 500.
  - [x] (Cohérence UX) Comme T2 a déjà retiré les apps hors catalogue des listes proposées, ce `catch` est un filet de sécurité (payload forgé / course) — il **doit** exister (defense-in-depth) mais ne se déclenchera pas en usage nominal.

- [x] **T5 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#7)
  - [x] `tests/Feature/ControlHub/UpstreamCatalogBoundaryTest.php` (ou découpage `tests/Unit` + `tests/Feature`) couvrant :
    - **Consultation filtrée (AC1)** : contrat actif + catalogue {app_id A} ; `Application::query()->inUpstreamCatalog()->get()` ne renvoie que A (B hors catalogue absent). Idem via `listApplicationsForSelect`.
    - **Refus install hors catalogue (AC2)** : `actingAs(refnum)` + `addApplicationsToWorkstationGroup(group, [idB])` → `ApplicationNotInUpstreamCatalogException` **et** pivot inchangé (assert count). Idem `addApplicationsToWorkstation` et `addApplications`.
    - **Install en catalogue OK (AC3)** : `addApplicationsToWorkstationGroup(group, [idA])` → succès, pivot écrit.
    - **Standalone (AC4)** : aucun `ControlHubContract` → toutes apps proposées + toutes installables (résolveur `isBounded() === false`, scope pass-through, garde no-op).
    - **Catalogue vide (AC5)** : contrat actif sans `catalogApps` → `isBounded() === false` → pas de bornage.
    - **Appelant non authentifié (AC6)** : sans `actingAs`, `addApplicationsToWorkstationGroup` ne lève pas pour cause de catalogue (garde inerte `Auth::check()===false`).
    - **Résolveur unitaire** : `isBounded`/`allowedAppIds`/`permits` sur les 3 états (standalone / catalogue vide / catalogue non vide), et **court-circuit NFR3** (aucune requête catalog si pas de contrat actif — vérifiable via `DB::enableQueryLog` ou en s'assurant qu'aucun contrat n'existe).
  - [x] **Piège SQLite** : tester des **décisions** (présence/absence dans la liste, exceptions, count pivot), jamais des longueurs varchar PG (non appliquées). [Source: mémoire projet — sqlite_tests_no_varchar_enforcement]
  - [x] **Bootstrap WPKG en test** : les tests WPKG existants (`tests/Feature/Wpkg/UI/*`) bootstrappent des tables ad hoc (cf. 29.1) — réutiliser/adapter ce patron si une page Livewire est testée ; sinon préférer tester le **service** + le **scope** directement (moins de plomberie).
  - [x] **Non-régression** : `php artisan test --filter "AppProfile|Wpkg|ControlHubContract|UpstreamCatalog"` → vert, 0 régression sur la baseline.

- [x] **T6 — Runbook QA** (AC: #1–#6)
  - [x] **Append** une section `## Story 31.1 — Bornage de l'install au catalogue amont` à `docs/qa/domains/controlhub-contract.md` (le fichier existe, sections numérotées stables — cf. 28.x/29.x/30.x). Scénarios manuels : contrat actif catalogue {A} (le refnum ne voit que A à l'assignation parc/machine ; tenter B = toast « hors catalogue amont ») ; standalone (tout proposé) ; catalogue vide (tout proposé) ; rupture future = libération (renvoyer vers Story 32.1, hors scope).
  - [x] Mettre à jour le libellé du domaine dans `docs/qa/README.md` (mention Story 31.1) si la convention l'exige.

- [x] **T7 — Validation finale**
  - [x] `php artisan test --filter "AppProfile|Wpkg|ControlHubContract|UpstreamCatalog"` sur HÔTE → vert.
  - [x] Grep garde-fou : aucune occurrence du mot « central » introduite (R3). Aucune duplication du filtre `link_state` (tout passe par `ControlHubContract::active()`).
  - [x] Vérifier qu'aucun chemin agent/desired-state ne dépend du garde catalogue en contexte authentifié (le no-op `Auth::check()` neutralise console/agent).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 31.1

**DANS** : résolveur du catalogue amont (`UpstreamCatalogResolver`, source unique mémoïsée), scope de consultation `Application::scopeInUpstreamCatalog`, garde defense-in-depth `AppProfileService::assertApplicationsInUpstreamCatalog` + exception dédiée, branchement UI (filtrage des listes + toast de refus), tests, runbook QA.

**HORS** (ne pas déborder) :
- **Déclenchement d'install en désir d'état** (ordres d'install amont repris par le check-in agent, idempotence/reprise) → **Story 31.2** (FR6). Le catalogue de 31.1 est une **liste autoritaire de filtrage**, pas un ordre d'install.
- **Verrou/permissif d'items** (capacités, registre, etc.) et leur résolution → **Epic 29 / 28.3** (déjà livrés en review). 31.1 ne touche ni `StateCompiler` ni `UpstreamContractSource`.
- **Ciblage par label** (apps imposées par parc via label) → **Epic 30**. Le catalogue applicatif amont (28.1) est **niveau contrat (instance)**, pas label : le bornage est **instance-global**, pas scopé par `workstationGroup`.
- **Release à la rupture du lien** (le bornage tombe, le refnum reprend la main) → **Story 32.1** (FR7). En 31.1, `link_state = severed` ⇒ `active()` renvoie `null` ⇒ `isBounded() === false` ⇒ bornage levé **automatiquement** (rien à coder de plus ici).
- **Gestion du catalogue local** (création/import d'apps depuis le dépôt, page parc-settings) : c'est l'inventaire local, pas le canal d'install vers les postes — non borné (cf. T2/D2).
- **Pas de migration, pas de modèle nouveau** : le schéma du catalogue est livré par 28.1. 31.1 = logique de filtrage + enforcement + UI.

### Décisions de cadrage (à acter, pré-tranchées)

- **D1 — Catalogue vide = pas de bornage** (AC5). Un contrat actif sans aucun `catalogApps` **ne verrouille pas** le refnum hors de toutes ses apps. Sémantique : l'autorité « n'a pas (encore) défini de catalogue autoritaire » ≠ « catalogue autoritaire = ensemble vide ». Choix sûr, aligné NFR3, évite de bricker l'install. `isBounded()` exige **catalogue non vide**.
- **D2 — Matching par `app_id`**. `controlhub_contract_catalog_apps.app_key` == `applications.app_id` (documenté dans le modèle 28.1). Le bornage compare sur `app_id` (string), pas sur l'`id` numérique local (qui n'a pas de sens cross-instance).
- **D3 — Deux couches symétriques à 29.1** : consultation (scope Eloquent, retire les apps hors catalogue des listes proposées) **+** enforcement (garde service, refuse l'écriture). La consultation seule ne suffit pas (payload Livewire forgé) ; l'enforcement seul dégrade l'UX (apps proposées puis refusées). Les deux, comme 29.1 fait pour le Gate scopé.
- **D4 — Filtrer l'assignation, pas le retrait ni l'inventaire**. Borner = contraindre ce qu'on **ajoute** aux parcs/postes/profils. Les `removeApplications*` et la gestion du catalogue local restent libres.

### Patrons de référence existants à IMITER (ne rien réinventer)

- **`ControlHubContract::active()`** [app/Models/ControlHubContract.php:64] — accesseur du contrat actif. **Source unique** du « contrat actif » (28.2/30.2 l'utilisent déjà) ; ne pas diverger.
- **`UpstreamContractSource::ensureResolved()`** [app/Services/ControlHub/Resolution/UpstreamContractSource.php:309] — patron de **mémoïsation + court-circuit NFR3** (si pas de contrat actif, zéro requête enfant). `UpstreamCatalogResolver` calque cette discipline.
- **`UpstreamLockResolver`** [app/Services/ControlHub/UpstreamLockResolver.php] — autre exemple de résolveur mémoïsé `link_state = active` côté ControlHub.
- **`AppProfileService::assertCanAssignWpkgOnGroup()`** (livré 29.1) — patron exact du **garde de couche service** : no-op si `Auth::check() === false`, refus explicite sinon. `assertApplicationsInUpstreamCatalog` en est le jumeau pour le catalogue. **Placer les deux gardes côte à côte** en tête des méthodes d'assignation.
- **`Application::scopeUpdatable()`** [app/Models/Application.php:194] et **`Printer::scopeForUser()`** [app/Models/Printer.php:146] — patron de scope Eloquent (dont un scope qui consulte un service via le conteneur).
- **`app/Exceptions/ControlHub/InvalidUpstreamContractException.php` / `UpstreamLockCollisionException.php`** — patron d'exception du domaine ControlHub (message FR explicite, R3).
- **`ensureWpkgAssignAuthorized()`** [groups/[id]/index.blade.php:1688] — patron `try/catch` + `toastError` à imiter pour le catch de refus catalogue.

### Garde-fous projet CRITIQUES (contraintes de la story)

- **NFR3 — Standalone byte-identique (BLOQUANT côté design)** : sans contrat actif (ou catalogue vide), **aucune** lecture du catalogue ne doit changer un résultat. Le scope est pass-through, le garde est no-op, le résolveur court-circuite (zéro requête `catalog_apps` si `active() === null`). Tester explicitement (AC4/AC5).
- **NFR1 — Verrou réellement opposable** : le bornage **dépend** du Gate WPKG scopé de 29.1 (sinon le canal d'install n'écoute qu'un droit global). Ne pas réintroduire un contrôle global non scopé. (29.1 est `review`, code sur `main`.)
- **NFR4 — Idempotence** : réception répétée du contrat ⇒ même catalogue ⇒ même filtrage (le résolveur lit l'état persisté, pas un payload). Aucun effet de bord.
- **R3 — Vocabulaire (BLOQUANT)** : **aucun** « central » dans tout identifiant/commentaire/message. Vocabulaire « amont » / `Upstream` / `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
- **Tests HÔTE uniquement** (php8.4 + `pdo_sqlite`), `RefreshDatabase`, **jamais la VM** (sans `pdo_sqlite`). [Source: mémoire projet — phpunit_test_env_host_vs_vm]
- **Racine = projet Laravel** (artisan/app à la racine, pas de préfixe `laravel/`). [Source: mémoire projet — root_is_laravel]

### Project Structure Notes

- **Nouveaux** : `app/Services/ControlHub/UpstreamCatalogResolver.php`, `app/Exceptions/ControlHub/ApplicationNotInUpstreamCatalogException.php`, `tests/Feature/ControlHub/UpstreamCatalogBoundaryTest.php` (+ éventuel `tests/Unit/Services/ControlHub/UpstreamCatalogResolverTest.php`).
- **Modifiés** : `app/Models/Application.php` (+scope), `app/Services/AppProfile/AppProfileService.php` (+garde + appels dans 3 méthodes + scope dans `listApplicationsForSelect`), `resources/views/pages/parc/groups/[id]/index.blade.php` (3 sites : 2 listes + try/catch), `resources/views/pages/parc/machines/[id]/index.blade.php` (2 sites : 1 liste + try/catch), `docs/qa/domains/controlhub-contract.md` (append), `docs/qa/README.md` (libellé).
- **Aucune** migration, **aucun** modèle de données nouveau (le catalogue est livré par 28.1).

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 31.1] — AC d'origine (les 2 Given/When/Then repris en AC1–AC3).
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#FR5, #§5, #§10 R1] — bornage catalogue ; dépendance R1 (`wpkg.*` à scoper = 29.1).
- [Source: app/Models/ControlHubContract.php:64,106] — `active()` + `catalogApps()`.
- [Source: app/Models/ControlHubContractCatalogApp.php:15,25] — `app_key == applications.app_id`.
- [Source: app/Models/Application.php:67,194] — table `applications`, `app_id`, patron `scopeUpdatable`.
- [Source: app/Services/AppProfile/AppProfileService.php:186,423,505,813,844] — chemins d'install + sélecteurs ; garde 29.1 `assertCanAssignWpkgOnGroup`.
- [Source: resources/views/pages/parc/groups/[id]/index.blade.php:1373,1500,1553,1601,1688] ; machines/[id]/index.blade.php:775,864] — surfaces UI du canal d'install.
- [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php:309] ; app/Services/ControlHub/UpstreamLockResolver.php] — patrons résolveur mémoïsé + court-circuit NFR3.
- [Source: _bmad-output/implementation-artifacts/29-1-scoper-gate-wpkg-par-perimetre.md] — patron garde defense-in-depth + Gate scopé (prérequis).
- [Source: mémoires projet — delegation_enforcement_wpkg_gap, phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement, root_is_laravel, socle_commun_and_unified_resolution].

## Dépendances

- **Amont (fonctionnelles, code sur `main`)** :
  - **29.1** (`review`) — Gate `wpkg.*` scopé par périmètre + garde `assertCanAssignWpkgOnGroup`. **Prérequis NFR1 explicite de l'Epic 31** : sans lui, le bornage ne serait pas réellement opposable. Le garde catalogue se place à côté du garde 29.1.
  - **28.1** (`review`) — modèle `ControlHubContract` + `ControlHubContractCatalogApp` (table `controlhub_contract_catalog_apps`).
  - **28.2** (`review`) — ingestion idempotente → peuple le catalogue ; `link_state = active`.
  - **28.3** (`review`) — `ControlHubContract::active()` consolidé + discipline résolveur mémoïsé/court-circuit NFR3 (patron réutilisé).
  - *(Toutes en `review`, code mergé sur `main` ; pas de blocage dur — 31.1 lit l'existant.)*
- **Aval** :
  - **Story 31.2** (FR6) — déclenchement d'install amont en désir d'état (réutilisera le catalogue comme borne).
  - **Story 32.1** (FR7) — release à la rupture : `severed` ⇒ `active()` null ⇒ bornage levé automatiquement (31.1 ne code rien de spécifique, mais 32.1 valide la levée).
- **Réutilise** : domaine ControlHub (Epic 28/29/30) + canal WPKG/AppProfile (Epic 27/15.x) — livrés.

## Testing

- **Cible : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, `RefreshDatabase`. **Jamais la VM.**
- Filtre : `php artisan test --filter "AppProfile|Wpkg|ControlHubContract|UpstreamCatalog"`.
- Couverture obligatoire : AC1 (consultation filtrée), AC2 (refus install hors catalogue **sans écriture pivot**), AC3 (install catalogue OK), AC4 (standalone inchangé + court-circuit zéro requête), AC5 (catalogue vide = pas de bornage), AC6 (appelant non authentifié non bloqué par le catalogue).
- **Pièges** : SQLite n'applique pas varchar/enum PG → tester des décisions (présence/absence, exceptions, counts), pas des bornes de colonne. Matcher sur `app_id` (string), pas `id`.
- ⚠️ **VM** : migrations non auto-jouées (aucune migration ici de toute façon).

## Recommandation Modèle Dev

**`opus`.**

Justification : story **transverse et piégeuse côté NFR3** — c'est un correctif d'**enforcement de sécurité fonctionnelle** (bornage du canal d'install) qui doit rester **byte-identique en standalone** tout en couvrant deux couches (consultation Eloquent + garde service) sur ~6 sites répartis sur 2 pages Livewire + service. Les écueils sont des régressions silencieuses : casser le standalone (filtrer alors qu'aucun contrat n'est actif), bloquer les appelants non-web (agent/console), oublier un site d'install (payload forgé), ou mal trancher le périmètre (filtrer le retrait / l'inventaire local). Le jugement requis porte sur la **discipline de court-circuit** (zéro requête sans contrat actif) et l'**exhaustivité** de la couverture — un raisonnement d'architecte, pas une recette. Le dev-cycle routera la review vers le modèle opposé (sonnet/fable), seconde paire d'yeux sur les angles morts NFR3.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- `CACHE_DRIVER=array vendor/bin/phpunit --filter UpstreamCatalogBoundary` → **15/15** (32 assertions), vert au 1ᵉʳ run.
- `CACHE_DRIVER=array vendor/bin/phpunit --filter "AppProfile|Wpkg|ControlHubContract|UpstreamCatalog"` → **416/416** (1199 assertions), 0 régression.
- `CACHE_DRIVER=array vendor/bin/phpunit tests/Feature/Livewire/Parc tests/Feature/Services/AppStore tests/Feature/Services/AppProfile` → **175/175** (501 assertions) — non-régression des pages parc/machine qui rendent désormais `inUpstreamCatalog()`.
- Régression initiale détectée puis corrigée : 2 tests à schéma SQLite ad-hoc (`AppProfileServiceWpkgScopingTest` 29.1 + `ParcGroupWpkgPageTest` via `WpkgSchemaBootstrapper`) ne créaient pas `controlhub_contracts` → `no such table`. Fix : ajout de la table (vide) au bootstrapper partagé + au test 29.1 (même remède que le fix 30.5 PhysicalRoomSwapTest). Court-circuit NFR3 ⇒ table vide suffit, comportement inchangé.

### Completion Notes List

- **T1** — `UpstreamCatalogResolver` (mémoïsé, `final`, `strict_types`) : `isBounded()`/`allowedAppIds()`/`permits()`. Court-circuit NFR3 STRICT via `ControlHubContract::active()` (réutilisé, zéro duplication du filtre `link_state`) : si pas de contrat actif, la table `controlhub_contract_catalog_apps` n'est JAMAIS requêtée. Binding singleton dans `AgentServiceProvider` (à côté de `UpstreamLockResolver`).
- **T2** — `Application::scopeInUpstreamCatalog` (pass-through si `!isBounded()`, sinon `whereIn('app_id', …)`). Branché sur les 4 surfaces de consultation du canal d'install : `getAvailableWpkgApplicationsProperty` (parc + machine), `bulkPreviewAppIds` (parc), `AppProfileService::listApplicationsForSelect`. **D2 respecté** : `listApplications()` (inventaire catalogue local parc-settings) NON filtrée.
- **T3** — Exception `ApplicationNotInUpstreamCatalogException` (message FR « hors catalogue amont » + liste des `app_id` refusés). Garde `assertApplicationsInUpstreamCatalog` : no-op si `Auth::check()===false` (a) puis si `!isBounded()` (b), sinon résout `app_id` par `whereIn('id',…)->pluck('app_id','id')` et lève si au moins un refusé. Appelé en tête (après `assertCanAssignWpkgOnGroup`, avant transaction) des 3 méthodes d'install ; **retrait/inventaire local NON gardés (D4)**.
- **T4** — `catch (ApplicationNotInUpstreamCatalogException $e)` → `toastError($e->getMessage())` ajouté AVANT le `catch` générique sur les 3 sites d'install (groups: attach + bulk catégorie ; machines: attach). Filet defense-in-depth (D3) : ne se déclenche pas en usage nominal car T2 a déjà retiré les apps hors catalogue.
- **T5** — `UpstreamCatalogBoundaryTest` (15 cas, `RefreshDatabase`) : AC1 (scope + `listApplicationsForSelect`), AC2 (refus parc/poste/profil + lot mixte, sans écriture pivot), AC3 (install OK), AC4 (standalone tout proposé/installable + court-circuit zéro requête catalogue via query log), AC5 (catalogue vide = pas de bornage), AC6 (non authentifié non bloqué), + résolveur unitaire 4 états (standalone/vide/non-vide/severed).
- **T6** — Section 14 + Checklist appended à `docs/qa/domains/controlhub-contract.md` (append-only) ; ligne cumulative 31.1 insérée dans `docs/qa/README.md`.
- **T7** — Validation finale OK. **R3** : `grep -rin central` sur les fichiers livrés ⇒ uniquement les en-têtes garde-fou (aucun identifiant/message « central »). **NFR3** : prouvé par test (query log) et par construction (court-circuit `active()===null`). **Agent/desired-state** : non impacté (chemins non authentifiés ⇒ garde no-op ; le canal agent ne passe pas par `AppProfileService` en contexte `Auth::check()===true`).

### Corrections post-review (2026-06-28)

Cf. `_bmad-output/codeReviews/31-1.md` (review sonnet + 2ᵉ avis opus). Corrigés automatiquement :
- **#1** — `cloneConfiguration` AJOUTE un delta d'apps au parc cible : garde `assertApplicationsInUpstreamCatalog($appsAdded)` ajouté avant la transaction + catch FR8 dans `executeClone`.
- **#M1 (manqué Sonnet, le plus important)** — la vraie page de composition de profils (`getAvailableApplicationsProperty`) utilisait `Application::query()` brut (violation AC1) et un catch générique (message opaque, violation AC2/FR8). La story avait par erreur filtré `listApplicationsForSelect` (code mort). Fix : `->inUpstreamCatalog()` + catch dédié.
- **#M3 (manqué Sonnet)** — `createProfile`/`updateProfile` acceptent `application_ids` sans garde (bypass latent) : garde ajouté en tête des deux.
- **#3** — `WpkgSchemaBootstrapper` : table `controlhub_contract_catalog_apps` (vide) ajoutée (anti foot-gun).
- 3 tests ajoutés (clone + create/updateProfile). **Tests** : `UpstreamCatalogBoundary` 18/18 ; suites impactées 419/419 ; `Livewire\Parc|Profile|AppStore` 333 passed/1 skip. 0 régression.

- **#M2 (manqué Sonnet) — décision Henri : borner dans 31.1** — `is_parc_default` (défaut diffusé fleet-wide, `admin/settings/parc-defaults/_partials/apps-tab.blade.php`) installe une app sur tout le parc ⇒ assignation FR5. Fix : `searchResults()` filtré `->inUpstreamCatalog()` + `setParcDefault(...,true)` refuse hors catalogue (message FR8) ; `defaults()` + retrait non bornés (D4). Test `ParcDefaultsCatalogBoundaryTest` (4 cas).

**Tests finaux** : `AppProfile|Wpkg|ControlHubContract|UpstreamCatalog|ParcDefaults` **451/451** (1276 assertions). 0 régression. R3 = 0 « central ». Le bornage couvre tous les canaux d'install (parc/poste, profil, clone, défaut diffusé) ; retrait/inventaire libres.

### File List

**Nouveaux**
- `app/Services/ControlHub/UpstreamCatalogResolver.php`
- `app/Exceptions/ControlHub/ApplicationNotInUpstreamCatalogException.php`
- `tests/Feature/ControlHub/UpstreamCatalogBoundaryTest.php`

**Modifiés**
- `app/Providers/AgentServiceProvider.php` (binding singleton + import)
- `app/Models/Application.php` (scope `scopeInUpstreamCatalog`)
- `app/Services/AppProfile/AppProfileService.php` (garde `assertApplicationsInUpstreamCatalog` + appels dans 3 méthodes + scope dans `listApplicationsForSelect` + imports)
- `resources/views/pages/parc/groups/[id]/index.blade.php` (scope sur 2 listes + try/catch toast sur 2 sites + import)
- `resources/views/pages/parc/machines/[id]/index.blade.php` (scope sur 1 liste + try/catch toast sur 1 site + import)
- `resources/views/pages/parc-settings/profiles/index.blade.php` (post-review #M1 : scope `inUpstreamCatalog` sur la composition de profil + catch FR8 + import)
- `resources/views/pages/admin/settings/parc-defaults/_partials/apps-tab.blade.php` (post-review #M2 : bornage du défaut diffusé fleet-wide — filtre recherche + refus passage à `true` hors catalogue + imports)
- `tests/Feature/Livewire/Admin/ParcDefaultsCatalogBoundaryTest.php` (nouveau, post-review #M2 : 4 cas)
- `tests/Support/WpkgSchemaBootstrapper.php` (table `controlhub_contracts` + `controlhub_contract_catalog_apps` ad-hoc — non-régression, post-review #3)
- `tests/Feature/AppProfile/AppProfileServiceWpkgScopingTest.php` (table `controlhub_contracts` ad-hoc — non-régression)
- `docs/qa/domains/controlhub-contract.md` (Section 14 + checklist, append)
- `docs/qa/README.md` (ligne cumulative domaine, mention Story 31.1)
