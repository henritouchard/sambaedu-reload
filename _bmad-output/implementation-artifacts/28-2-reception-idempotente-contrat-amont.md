# Story 28.2: Réception idempotente du contrat amont (controlHub)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **un point d'ingestion qui reçoit un contrat amont (payload controlHub) et le persiste de façon idempotente** (upsert sur les clés naturelles de la Story 28.1, normalisation et validation à l'entrée, passage du lien à « actif »),
so that **une diffusion répétée ou une reprise après indisponibilité du lien n'a aucun effet de bord** (pas de doublon, pas de churn, aucun événement de changement émis à tort — **NFR4**), tout en laissant le comportement standalone strictement inchangé tant qu'aucun contrat n'est reçu (**NFR3**).

> Story **2/3** de l'Epic 28. Elle s'appuie sur le schéma + les modèles livrés par la **Story 28.1** (`controlhub_contract_*`, `ControlHubContract*`, enums). Elle livre **uniquement** la couche d'**ingestion / upsert idempotent**. Elle **ne branche pas** `StateCompiler` (→ Story 28.3) et **ne mappe pas** les labels sur les `WorkstationGroup` locaux (→ Epic 30).

> **HANDOFF de la review 28.1 (codeReviews/28-1.md) — à implémenter impérativement ici** :
> 1. **Normaliser `null → ''` sur `target_label`** *avant écriture* (la clé naturelle 28.1 repose sur `target_label NOT NULL DEFAULT ''` ; sans normalisation à l'ingestion, le cas dominant `instance` recasse l'idempotence — finding #1).
> 2. **Enforcer « au plus un contrat actif par instance »** (singleton tenu par `link_state` — différé de 28.1, finding Q2). _Note post-review : `authority_ref` supprimé (décision Henri 2026-06-26, modèle mono-autorité) — le singleton ne dépend d'aucune référence d'émetteur._
> 3. **Valider les valeurs d'enum à l'ingestion** (il n'y a **pas** de `CHECK` DB — finding #4 ; valider `enforcement_state` / `target_type` / `mode` à l'entrée).

## Acceptance Criteria

1. **Given** une installation SE5 sans aucun contrat persisté et un payload de contrat amont valide reçu, **When** l'ingestion s'exécute, **Then** un `ControlHubContract` est persisté avec ses `items` / `labels` / `imposedGroups` / `catalogApps`, **et** `link_state` passe à **`active`** (`ControlHubLinkState::Active`), **et** `received_at` est horodaté. Les enregistrements enfants reflètent **exactement** le payload (ni en trop, ni en moins).

2. **Given** un contrat déjà persisté, **When** le **même** payload (sémantiquement identique) est reçu une seconde fois, **Then** l'opération est un **no-op fonctionnel** : **aucune** ligne créée/supprimée, **aucune** colonne fonctionnelle modifiée (`updated_at` des lignes inchangés, `received_at` inchangé), **et aucun** événement de changement n'est émis (**NFR4**).

3. **Given** un contrat déjà persisté, **When** un payload **modifié** est reçu (item ajouté, valeur/état d'un item changé, item disparu, label/groupe/app ajouté ou retiré), **Then** l'ingestion **réconcilie** l'état persisté vers le désir d'état du payload (**upsert** des présents sur la clé naturelle 28.1 + **prune** des disparus), **sans** créer de doublon (la contrainte d'unicité 28.1 n'est jamais violée), **et** un **événement de changement** est émis exactement une fois.

4. **Given** un item de payload ciblant l'instance avec `target_label` **absent ou `null`** (cas dominant `instance`), **When** l'ingestion persiste l'item, **Then** `target_label` est normalisé en **chaîne vide `''`** avant écriture, **et** une seconde réception du **même** payload (où `target_label` est tantôt `null`, tantôt `''`, tantôt absent) reste un **no-op** (preuve que la normalisation préserve l'idempotence — HANDOFF #1).

5. **Given** un contrat actif déjà persisté, **When** un nouveau payload est reçu, **Then** il existe **toujours au plus un** contrat avec `link_state = active` pour cette instance : l'ingestion **réutilise** le contrat actif existant plutôt que d'en créer un second (singleton — HANDOFF #2). Aucune duplication de ligne `controlhub_contracts` active. _Post-review : `authority_ref` supprimé ; le singleton repose uniquement sur `link_state`._

6. **Given** un payload contenant une valeur **hors domaine** pour `enforcement_state` (≠ `locked|permissive|absent`), `target_type` (≠ `instance|label`), `mode` de label (≠ `free|reserved`), **ou** une incohérence de cible (`target_type=label` avec `target_label` vide / `target_type=instance` avec `target_label` non vide), **When** l'ingestion s'exécute, **Then** elle **rejette** le payload (exception de domaine dédiée) **et** la base reste **strictement inchangée** (transaction rollback — aucune écriture partielle), puisqu'il n'existe **aucun** `CHECK` DB pour rattraper l'erreur (HANDOFF #3).

7. **Given** le code livré, **When** on l'inspecte, **Then** (a) le service d'ingestion est le **seul** chemin écrivant dans `controlhub_contract_*` ; (b) **aucun** chemin de code SE5 **existant** (controllers, services, vues, `StateCompiler`) n'est modifié pour lire/écrire le contrat — sans réception, le comportement SE5 est **strictement inchangé** (**NFR3**) ; (c) **aucun** identifiant (classe, méthode, propriété, événement, exception, colonne) ne contient le mot **« central »** (garde-fou **R3**).

## Tasks / Subtasks

- [x] **Task 1 — Service d'ingestion `ControlHubContractIngestionService`** (AC: #1, #3, #7)
  - [x] Créer `app/Services/ControlHub/ControlHubContractIngestionService.php` (`declare(strict_types=1)`). Signature publique : `ingest(array $payload): ContractIngestionResult`. **Patron** : `app/Services/ControlHub/WorkstationGroupSyncService.php` (méthode publique → `DB::transaction(...)` → passes upsert puis relations → `Log::info` début/fin → renvoie un objet `*Result`).
  - [x] Toute l'ingestion s'exécute dans **un seul** `DB::transaction(...)` (rollback total en cas de validation KO — AC #6).
  - [x] **Résolution du contrat racine (singleton, Task 3)** puis réconciliation des 4 agrégats enfants :
    - `items` → upsert sur la clé naturelle `['controlhub_contract_id','type','key','target_type','target_label']` (= index `chc_item_natural_key` de 28.1) + **prune** des items absents du payload.
    - `labels` → upsert sur `['controlhub_contract_id','name']` (`chc_label_unique`) + prune.
    - `imposedGroups` → upsert sur `['controlhub_contract_id','name']` (`chc_imposed_group_unique`) + prune.
    - `catalogApps` → upsert sur `['controlhub_contract_id','app_key']` (`chc_catalog_app_unique`) + prune.
  - [x] Réconciliation = **désir d'état** (full replace par contrat) : ce qui est dans le payload est upserté, ce qui n'y est plus est **supprimé** (les FK `cascadeOnDelete` de 28.1 ne suppriment QUE sur suppression du parent ; le prune des enfants est explicite ici). Utiliser `updateOrCreate(<clé naturelle>, <attributs>)` puis `whereNotIn(...)->delete()` sur les ids conservés — cf. `WorkstationGroupSyncService` pour le style upsert/relations.
  - [x] Mettre `link_state = ControlHubLinkState::Active` et `received_at = now()` **uniquement** si le contrat est créé ou si une mutation fonctionnelle a eu lieu (cf. Task 4 — préserver le no-op).
  - [x] **NE PAS** émettre, lire ni écrire quoi que ce soit hors des tables `controlhub_contract_*` (NFR3). **NE PAS** toucher `app/Services/Agent/StateCompiler.php` (→ 28.3).

- [x] **Task 2 — Normalisation + validation du payload** (AC: #4, #6)
  - [x] **Normalisation `target_label` (HANDOFF #1)** : pour chaque item, `target_label = $item['target_label'] ?? ''` puis `(string)`; si `null` → `''`. Ainsi le cas dominant `target_type=instance` écrit `''` et la clé naturelle 28.1 reste effective (sinon `NULL ≠ NULL` ⇒ doublons à chaque réception). Forcer `target_label = ''` quand `target_type = instance`.
  - [x] **Validation d'enum (HANDOFF #3)** — il n'y a **aucun** `CHECK` DB (finding #4 de 28.1) : valider via `ControlHubEnforcementState::tryFrom()`, `ControlHubContractTarget::tryFrom()`, `ControlHubLabelMode::tryFrom()`. Une valeur hors domaine ⇒ lever l'exception de Task 2.bis. (`type` reste une chaîne de vocabulaire libre — applications/wallpapers/capabilities/shortcuts/agent_tools… — **pas** d'enum fermée, ne pas valider sa valeur.)
  - [x] **Validation de cohérence de cible** : `target_type=label` exige `target_label` non vide ; `target_type=instance` exige `target_label` vide (après normalisation). Sinon ⇒ exception.
  - [x] Créer `app/Exceptions/ControlHub/InvalidUpstreamContractException.php` (extends `\RuntimeException` ou `\InvalidArgumentException`) avec un message portant la clé fautive. **R3** : aucun « central » dans le nom/message. La levée doit survenir **avant ou pendant** la transaction de façon à garantir le **rollback total** (aucune écriture partielle — AC #6).
  - [x] Le `link_state` n'est **jamais** pris du payload : à la **réception**, le lien passe à `active` par définition. La **rupture** (`severed`) relève d'Epic 32, hors scope ici.

- [x] **Task 3 — Enforcement du singleton « au plus un contrat actif par instance »** (AC: #5, HANDOFF #2)
  - [x] Résoudre le contrat à mettre à jour ainsi : `ControlHubContract::query()->where('link_state', ControlHubLinkState::Active)->first()`. S'il existe, **le réutiliser** ; sinon **en créer un seul**. Conséquence : une 2e réception **ne crée jamais** un 2e contrat actif (SE5 ↔ une seule autorité amont à la fois). Documenter ce choix en PHPDoc. _Post-review : `authority_ref` supprimé (décision Henri) — le singleton repose uniquement sur `link_state`._
  - [x] Exécuter cette résolution **dans la transaction** (cohérence avec la réconciliation enfants). Le singleton est tenu en code via `link_state` (aucune référence d'émetteur stockée).
  - [x] (Défense en profondeur — **optionnel**, à ne faire que si trivba portable PG+SQLite) : un index unique **partiel** `WHERE link_state='active'` durcirait l'invariant côté DB. Laissé en option : l'enforcement applicatif + test (AC #5) est le livrable requis ; ne pas ajouter de migration si elle n'est pas portable proprement (cf. piège SQLite, mémoire projet).

- [x] **Task 4 — Détection du no-op + événement de changement** (AC: #2, #3)
  - [x] Tracer une mutation fonctionnelle pendant la réconciliation : un enfant `wasRecentlyCreated`, un `updateOrCreate` dont le modèle `wasChanged()`, ou ≥ 1 suppression au prune ⇒ `$mutated = true`. Idem si le contrat racine est créé.
  - [x] **No-op (AC #2)** : si `$mutated === false`, **ne pas** toucher `received_at`/`link_state`/`updated_at` du contrat, et **ne pas** émettre d'événement. (`received_at` n'est rafraîchi qu'à la création ou à une mutation — le « dernier contact » du lien relève de `ControlHubConnection`/heartbeat, pas du contrat.)
  - [x] **Mutation (AC #3)** : émettre **une seule fois** un événement de domaine `App\Events\ControlHubContractChanged` (payload : l'`ControlHubContract` concerné). Créer `app/Events/ControlHubContractChanged.php` (style event Laravel simple, `Dispatchable`). **Aucun listener** n'est branché en 28.2 (NFR3 : event inerte ; 28.3+ pourra s'y abonner). **R3** : aucun « central » dans le nom de l'event.
  - [x] Renseigner le résultat (Task 5) : créé/mis à jour/inchangé + compteurs.

- [x] **Task 5 — DTO de résultat `ContractIngestionResult`** (support tests + observabilité)
  - [x] Créer `app/Services/ControlHub/Data/ContractIngestionResult.php`. **Patron** : `app/Services/ControlHub/Data/WorkstationGroupSyncResult.php` (objet de résultat + `toArray()`). Exposer au minimum : `bool $mutated` (false ⇒ no-op), `bool $contractCreated`, et des compteurs par agrégat (items/labels/groups/apps : created/updated/deleted). Sert aux assertions de test et au `Log::info` final.

- [x] **Task 6 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#7)
  - [x] Créer `tests/Feature/ControlHub/ControlHubContractIngestionTest.php`. **Patron** : `tests/Feature/ControlHub/WorkstationGroupSyncTest.php`. Construire des payloads via tableaux (et/ou réutiliser les factories `ControlHubContract*Factory` de 28.1 pour préparer l'état initial).
  - [x] `test_first_reception_persists_contract_and_activates_link` (AC #1) : payload → 1 contrat, N items/labels/groupes/apps exacts, `link_state=Active`, `received_at` non null.
  - [x] `test_identical_reception_is_noop` (AC #2) : 2e ingestion du même payload ⇒ `result->mutated === false`, comptes de lignes inchangés, `updated_at`/`received_at` du contrat **inchangés** (capturer la valeur avant/après). Utiliser `Event::fake()` et asserter `assertNotDispatched(ControlHubContractChanged::class)` sur la 2e passe.
  - [x] `test_changed_reception_reconciles_and_emits_event` (AC #3) : ajouter/modifier/retirer items+labels+groupes+apps ⇒ upsert + prune corrects, **aucune** `QueryException` (pas de violation d'unicité), `Event::assertDispatched(ControlHubContractChanged::class)` **une fois**.
  - [x] `test_target_label_null_is_normalized_and_idempotent` (AC #4) : item `instance` avec `target_label` `null`/absent → persisté `''` ; ré-réception (mélange null/''/absent) ⇒ no-op. **C'est le test révélateur du finding #1** : il échouerait si la normalisation manquait (doublon ou churn). Ne **pas** « adapter » ce test au cas `label` (cf. erreur #2 de la review 28.1).
  - [x] `test_singleton_active_contract` (AC #5) : 2 réceptions (la 2e au contenu modifié) ⇒ réutilisation du contrat actif (`contractCreated===false`), `ControlHubContract::where('link_state',Active)->count() === 1`.
  - [x] `test_invalid_enum_rejected_and_no_partial_write` (AC #6) : payload avec `enforcement_state='bogus'` (puis variantes `target_type`, `mode`, incohérence cible) ⇒ `InvalidUpstreamContractException` levée **et** aucune ligne `controlhub_contract_*` écrite (compter = 0 / inchangé). Vérifier le rollback (état initial préservé si un contrat préexistait).
  - [x] `test_r3_no_central_identifier` (AC #7c) : introspection — aucun nom de classe/méthode/event/exception livré ne contient `central` (peut être un simple `assertStringNotContainsStringIgnoringCase('central', ...)` sur les FQCN/fichiers livrés).
  - [x] **Piège SQLite** : ne tester ni longueur `varchar`, ni unicité reposant sur `NULL` (cf. finding #1/#2 de 28.1 et mémoire `sqlite_tests_no_varchar_enforcement`). Tester l'idempotence par **comptage de lignes + `mutated` + dispatch d'event**, pas par contrainte de chaîne.

- [x] **Task 7 — Validation finale**
  - [x] `php artisan test --filter ControlHubContractIngestion` (HÔTE) → vert.
  - [x] Relancer aussi `php artisan test --filter ControlHubContract` (les 38 tests de 28.1 doivent **rester verts** — non-régression du schéma/modèles).
  - [x] Vérifier (grep) qu'aucun fichier **existant** hors `controlhub_contract_*` n'a été modifié pour lire le contrat (NFR3) et qu'aucun identifiant livré ne contient « central » (R3).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 28.2

**DANS** : service d'ingestion idempotent (upsert + prune sur les clés naturelles 28.1), normalisation `null→''` de `target_label`, validation des enums + cohérence de cible à l'entrée, passage du lien à `active`, singleton « ≤ 1 contrat actif », détection du no-op + événement de changement, DTO de résultat, tests HÔTE.
**HORS** (ne pas déborder) :
- **Branchement `StateCompiler`** (tier de précédence amont > local via `specificity()`) → **Story 28.3**. Ne **pas** toucher `app/Services/Agent/StateCompiler.php`, ne **pas** créer de `StateProvider`. L'event `ControlHubContractChanged` reste **sans listener** en 28.2.
- **Mapping label → `WorkstationGroup` local**, garantie d'existence des groupes imposés, résolution par label → **Epic 30**.
- **Bornage du catalogue / déclenchement d'install** → **Epic 31**. Le catalogue reste une simple liste persistée.
- **Rupture du lien (`severed`) / release des verrous** → **Epic 32**. À la réception, le lien passe à `active` ; on ne consomme **pas** de `severed` depuis le payload.
- **Schéma d'échange versionné controlHub↔SE5** → **Epic 33**. Le format de payload accepté ici est introduit **unilatéralement** par SE5 (cf. epics-contrat-manage-se5.md#Epic 28 : « définit unilatéralement le format d'ingestion attendu ») ; pas de validation de version de schéma.
- **Transport / endpoint HTTP / handler `ControlHubTask`** : la **liaison du canal de diffusion** (qui appelle `ingest()`) n'est **pas** le cœur de cette story. Le livrable testable et stable est le **service** `ingest(array $payload)`. Si un point d'entrée est nécessaire, le câbler de façon minimale **sans** figer un schéma versionné (réservé Epic 33). Ne pas inventer d'authentification ad hoc : le canal controlHub existant (`app/Http/Middleware/ControlHubAuth.php`, `app/Http/Controllers/Api/v1/ControlHub/*`) est la surface d'accroche le jour où le transport sera branché.

### Code réel livré par 28.1 (ancrage exact — noms à réutiliser tels quels)

**Tables** (migration `database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php`) :
- `controlhub_contracts` : `id`, `link_state` (string, défaut `'active'`), `received_at` (timestamp nullable), `timestamps`. _(Post-review : colonne `authority_ref` supprimée — décision Henri, modèle mono-autorité.)_
- `controlhub_contract_items` : `id`, `controlhub_contract_id` (FK cascade), `type` (string), `key` (string), `value` (text nullable), `enforcement_state` (string), `target_type` (string, défaut `'instance'`), **`target_label` (string `NOT NULL DEFAULT ''`)**, `timestamps`. Unique **`chc_item_natural_key`** = `(controlhub_contract_id, type, key, target_type, target_label)`.
- `controlhub_contract_labels` : `id`, `controlhub_contract_id` (FK cascade), `name`, `mode` (string), `timestamps`. Unique **`chc_label_unique`** = `(controlhub_contract_id, name)`.
- `controlhub_contract_imposed_groups` : `id`, `controlhub_contract_id` (FK cascade, nom `chc_imposed_group_contract_fk`), `name`, `label_name` (string nullable), `timestamps`. Unique **`chc_imposed_group_unique`** = `(controlhub_contract_id, name)`.
- `controlhub_contract_catalog_apps` : `id`, `controlhub_contract_id` (FK cascade), `app_key`, `display_name` (string nullable), `timestamps`. Unique **`chc_catalog_app_unique`** = `(controlhub_contract_id, app_key)`.

> ⚠️ **`target_label` est `NOT NULL DEFAULT ''`** (corrigé par la review 28.1, finding #1). C'est précisément pourquoi la normalisation `null → ''` à l'ingestion (Task 2) est **obligatoire** : un payload qui omet ou met `null` casserait sinon la clé naturelle (le cas `instance` est le dominant).

**Modèles** (`app/Models/`) — `$fillable` et casts à respecter :
- `ControlHubContract` : `$fillable = ['link_state','received_at']` ; casts `link_state → ControlHubLinkState`, `received_at → datetime`. Relations `hasMany` : `items()`, `labels()`, `imposedGroups()`, `catalogApps()` (toutes FK `controlhub_contract_id`).
- `ControlHubContractItem` : `$fillable = ['controlhub_contract_id','type','key','value','enforcement_state','target_type','target_label']` ; casts `enforcement_state → ControlHubEnforcementState`, `target_type → ControlHubContractTarget`. `@property string $target_label` (NOT NULL, NFR4). `belongsTo contract()`.
- `ControlHubContractLabel` : cast `mode → ControlHubLabelMode` ; `belongsTo contract()`.
- `ControlHubContractImposedGroup` : `belongsTo contract()` ; rattachement label par **`label_name`** (string), **pas** de FK dure vers les labels (différé Epic 30).
- `ControlHubContractCatalogApp` : `belongsTo contract()`.

**Enums** (`app/Enums/`, backed `string`) — utiliser `::tryFrom()` pour la validation :
- `ControlHubLinkState` : `Active='active'`, `Severed='severed'`.
- `ControlHubEnforcementState` : `Locked='locked'`, `Permissive='permissive'`, `Absent='absent'`.
- `ControlHubContractTarget` : `Instance='instance'`, `Label='label'`.
- `ControlHubLabelMode` : `Free='free'`, `Reserved='reserved'`.

**Factories** (`database/factories/ControlHubContract*Factory.php`) : disponibles pour préparer l'état initial des tests (états nommés : `severed`, `permissive`, `absent`, `forLabel`, `reserved`, `withLabel`…).

### Patrons de code à réutiliser (pointeurs exacts — ne rien réinventer)

- **Service d'ingestion / désir d'état** : `app/Services/ControlHub/WorkstationGroupSyncService.php` est le **modèle de référence** : méthode publique `sync*(array $payload): *Result`, corps en `DB::transaction(function () { /* pass 1 upsert, pass 2 relations */ })`, `Log::info` début/fin, `updateOrCreate(...)` / `firstOrCreate(...)`. Reproduire ce style.
- **DTO de résultat** : `app/Services/ControlHub/Data/WorkstationGroupSyncResult.php` (+ les autres DTO du dossier `Data/`, ex. `HandshakeResult`, `SyncManifestResult`) — objet immuable/mutable simple avec `toArray()`.
- **Tests Feature ControlHub** : `tests/Feature/ControlHub/WorkstationGroupSyncTest.php` (préparation de payloads en tableaux, assertions sur la persistance après sync). `tests/Feature/ControlHub/ShortcutSyncTest.php` pour les cas upsert/prune.
- **Événement de domaine** : `Dispatchable` Laravel standard ; chercher un event existant dans `app/Events/` comme gabarit. `Event::fake()` / `assertDispatched` / `assertNotDispatched` côté tests.
- **PostgreSQL en prod, SQLite en test** : domaines fermés validés en **PHP** (enums + `tryFrom`), **jamais** via `CHECK` SQL (invisible en SQLite). [mémoire projet — `sqlite_tests_no_varchar_enforcement`]

### Garde-fous projet CRITIQUES (contraintes de la story)

- **R3 — Vocabulaire (BLOQUANT)** : **INTERDIT** — le mot `central` dans tout nom de classe, méthode, propriété, événement, exception, colonne, message ou commentaire d'identifiant. Vocabulaire : « amont » / `ControlHub*` / `authority` / `upstream` (FR : « autorité amont »), jamais « central ». [mémoires `project_contrat_manage_se5_upstream`, `legacy_central_vs_local_split` ; prd-contrat-manage-se5.md#R3]
- **NFR3 — Standalone préservé** : sans contrat reçu, comportement SE5 **strictement inchangé**. ⇒ le service d'ingestion est le **seul** écrivain de `controlhub_contract_*` ; **aucun** chemin de code existant n'est modifié pour lire le contrat ; l'event reste **sans listener** ; `StateCompiler` intact. [prd#NFR3 ; epics-contrat-manage-se5.md#Story 28.3 AC2]
- **NFR4 — Idempotence (cœur de la story)** : réception répétée du même contrat = **no-op** (aucune écriture fonctionnelle, aucun événement) ; reprise après indisponibilité du lien sans effet de bord. La clé naturelle 28.1 est l'outil ; la **normalisation `null→''`** est ce qui la rend effective sur le cas dominant. [prd#NFR4 ; epics#Story 28.2 ; codeReviews/28-1.md#1]
- **Cycle de vie du lien** : controlHub indisponible ⇒ **pas** de MAJ d'état, le dernier contrat reste en vigueur (rien ne saute). À la réception, le lien passe à `active`. La rupture (`severed`) = action délibérée → **Epic 32** (hors scope). [handoff-controlhub-contrat-manage.md#§4]

### Distinctions à NE PAS confondre (homonymies)

- `app/Models/ControlHubConnection.php` (`controlhub_connection`) = **lien/transport** (fédération, handshake, heartbeat, tokens). Le contrat (`ControlHubContract`) = **politique imposée** reçue *via* ce lien. **Aucune FK** entre les deux en Epic 28.
- `app/Services/Agent/StateContract.php` = contrat *desired-state* **agent** (domaine différent ; homonymie « contract »). Ne pas y toucher.
- `app/Models/ControlHubTask.php` (`controlhub_tasks`) = tâches ordonnées par controlHub. Mécanisme distinct ; ne pas y greffer l'ingestion du contrat.

### Project Structure Notes

- Nouveaux fichiers : `app/Services/ControlHub/ControlHubContractIngestionService.php`, `app/Services/ControlHub/Data/ContractIngestionResult.php`, `app/Events/ControlHubContractChanged.php`, `app/Exceptions/ControlHub/InvalidUpstreamContractException.php`, `tests/Feature/ControlHub/ControlHubContractIngestionTest.php`.
- **Aucune** migration nouvelle attendue : le schéma vient de 28.1 (sauf décision d'ajouter l'index partiel optionnel de Task 3 — à éviter si non portable proprement).
- **Aucune** route, controller, vue, Gate, seeder modifié ni ajouté côté chemins existants (NFR3). Si un point d'entrée transport est jugé indispensable, le garder minimal et explicitement isolé.
- **Racine = projet Laravel** (artisan/app à la racine ; pas de préfixe `laravel/`). [mémoire `root_is_laravel`]

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 28.2] — AC d'origine (upsert idempotent, lien→actif, 2e réception = no-op, aucun événement).
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#NFR3, #NFR4, #§7, #§9] — standalone, idempotence, couture controlHub↔SE5.
- [Source: _bmad-output/planning-artifacts/handoff-controlhub-contrat-manage.md#§4, #§7] — cycle de vie du lien, contrat d'interface (ce que SE5 reçoit).
- [Source: _bmad-output/implementation-artifacts/28-1-modele-et-persistance-du-contrat-amont.md] — schéma, modèles, enums, factories livrés (fondation).
- [Source: _bmad-output/codeReviews/28-1.md] — HANDOFF #1 (null→''), #2 (singleton), #3 (validation enum à l'ingestion).
- [Source: app/Services/ControlHub/WorkstationGroupSyncService.php] — patron service + transaction + upsert/prune (désir d'état).
- [Source: app/Services/ControlHub/Data/WorkstationGroupSyncResult.php] — patron DTO de résultat.
- [Source: tests/Feature/ControlHub/WorkstationGroupSyncTest.php] — patron de test Feature ControlHub.
- [Source: database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php] — clés naturelles `chc_*` exactes.

## Dépendances

- **Amont (bloquantes)** :
  - **28.1** (`review`/`to-validate`) — **LIVRÉE** : schéma `controlhub_contract_*`, modèles, enums, factories, clés naturelles. **Prérequis indispensable.** (Story committée sur `main` selon sprint-status ; statut review n'empêche pas le build de 28.2 — le code est en place.)
- **Aval (dépendent de 28.2)** :
  - **28.3** (`backlog`) — Résolution amont > local dans `StateCompiler` : consommera le contrat persisté (et pourra s'abonner à `ControlHubContractChanged`).
  - **Epic 29** (enforcement verrou/permissif), **Epic 30** (labels & mapping), **Epic 31** (catalogue borné & install), **Epic 32** (rupture du lien) consomment cette persistance ultérieurement.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), **jamais la VM** (VM sans `pdo_sqlite`). [mémoire `phpunit_test_env_host_vs_vm`]
- `DB_CONNECTION=sqlite` (cf. `phpunit.xml`), trait `RefreshDatabase`.
- Filtre ciblé : `php artisan test --filter ControlHubContractIngestion` ; non-régression : `--filter ControlHubContract` (38 tests de 28.1 verts).
- Couverture : 1re réception (persistance + lien actif + received_at), **no-op** (mutated=false + comptes + updated_at/received_at inchangés + event non émis), réconciliation (upsert+prune + event émis 1×), **normalisation `null→''` + idempotence** (test révélateur du finding #1), **singleton** (≤1 contrat actif), **validation enum + rollback total** (aucune écriture partielle), R3 (aucun « central »).
- **Pièges SQLite** : ne pas tester de longueur `varchar` ; ne pas tester d'unicité reposant sur `NULL` (cf. findings #1/#2 de 28.1). Mesurer l'idempotence par **comptage de lignes + `mutated` + dispatch d'event**.
- ⚠️ **VM** : migrations **pas auto-jouées** par le dev-cycle (migre SQLite uniquement) ; ne pas présumer les tables présentes côté VM. [mémoire `vm_migrations_not_auto_applied`]

## Recommandation Modèle Dev

**`opus`.**

Justification : story à **logique métier critique** et à **idempotence subtile**. Les points durs ne sont pas mécaniques :
1. **Détection fidèle du no-op** (distinguer « réception identique » de « changement » sans churn, sans toucher `updated_at`/`received_at`, sans émettre d'event à tort) demande un raisonnement précis sur l'état Eloquent (`wasChanged`/`wasRecentlyCreated`) et le périmètre de l'écriture.
2. La **normalisation `null→''`** est un piège non trivial (le test révélateur du finding #1 de 28.1 doit échouer si elle manque) — un dev pressé reproduirait exactement l'erreur #2 de la review (test « adapté » tautologique).
3. Le **singleton** et le **rollback total sur validation KO** engagent l'intégrité (transaction, invariant « ≤1 actif »).
4. **Trois garde-fous transverses** (NFR3 standalone, NFR4 idempotence, R3 vocabulaire) doivent être tenus simultanément.
Le dev-cycle routera la review vers le **modèle opposé** (sonnet/fable) ; placer **opus** sur l'implémentation met le raisonnement là où le risque d'effet de bord est le plus élevé.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- `vendor/bin/phpunit --filter ControlHubContractIngestionTest` → **10 tests, 110 assertions, OK** (HÔTE php8.4 + sqlite).
- `vendor/bin/phpunit --filter ControlHubContract` → **48 tests, 236 assertions, OK** (38 de 28.1 + 10 de 28.2 ; non-régression schéma/modèles préservée).
- Échec transitoire corrigé en cours de dev : la version initiale du test R3 scannait aussi le **contenu** des fichiers livrés, ce qui matchait le mot « central » présent dans les **commentaires garde-fou** (« aucun mot central »). Recadré sur l'introspection des seuls identifiants (FQCN, méthodes, propriétés, constantes) — le vrai contrôle R3 demandé par la story.

### Completion Notes List

- **Service `ControlHubContractIngestionService::ingest(array): ContractIngestionResult`** : validation/normalisation PURE en amont (aucune écriture), puis écritures dans **un seul** `DB::transaction`. Patron `WorkstationGroupSyncService` respecté (méthode publique → transaction → `Log::info` début/fin → DTO de résultat).
- **Normalisation `null → '' ` de `target_label` (HANDOFF #1)** : implémentée dans `normalizeItems()`. Cohérence de cible vérifiée sur la valeur **brute** (avant normalisation), puis `target_label` forcé à `''` pour `target_type=instance`. **Test révélateur en place** : `test_target_label_null_is_normalized_and_idempotent` — la 2e réception mélange null/''/absent et doit rester un no-op ; il échouerait si la normalisation était retirée (la 1re réception planterait déjà sur la colonne `NOT NULL DEFAULT ''`, et la clé naturelle ne matcherait plus).
- **Validation enum (HANDOFF #3)** via `tryFrom()` sur `ControlHubEnforcementState` / `ControlHubContractTarget` / `ControlHubLabelMode`. `type` d'item laissé en vocabulaire libre (non validé). Valeur hors domaine OU incohérence de cible ⇒ `InvalidUpstreamContractException` levée **avant la transaction** ⇒ rollback total garanti (aucune écriture partielle). Couvert par `test_invalid_enum_rejected_and_no_partial_write` (5 variantes) + `test_invalid_payload_leaves_existing_state_unchanged` (état préexistant inchangé, `updated_at` du contrat non touché malgré l'avance de l'horloge).
- **Singleton (HANDOFF #2)** : `resolveActiveContract()` réutilise le contrat `link_state=active` existant, sinon en crée un seul. Choix documenté en PHPDoc. Couvert par `test_singleton_active_contract` (1re réception puis réception modifiée ⇒ réutilisation, 1 seul contrat actif). Pas d'index partiel DB (option non portable proprement SQLite/PG — laissée de côté conformément à Task 3). _Post-review : `authority_ref` supprimé (décision Henri, modèle mono-autorité) — le singleton ne dépend d'aucune référence d'émetteur, ce qui clôt aussi le risque latent S1._
- **No-op + event (Task 4)** : `reconcileChildren()` générique (upsert sur clé naturelle 28.1 + prune `whereNotIn` des disparus) trace `$mutated` via `wasRecentlyCreated` / `wasChanged()` / `deleted > 0`. `received_at`/`link_state`/`save()` ne sont touchés QUE sur création ou mutation ; `ControlHubContractChanged` émis **exactement une fois** sur mutation, **jamais** sur no-op.
- **NFR3** : seuls de **nouveaux** fichiers ont été créés sous `app/` (vérifié `git status`). Aucun chemin existant (controllers/services/vues/`StateCompiler`) modifié. L'événement reste **sans listener**.
- **R3** : aucun identifiant livré (classe/méthode/propriété/constante/event/exception) ne contient « central » (grep + test d'introspection `test_r3_no_central_identifier`).
- **Transport** : volontairement non câblé (hors cœur de la story) — le livrable stable est le service `ingest(array)`. Pas de route/controller/endpoint ajouté.

### File List

**Créés :**
- `app/Services/ControlHub/ControlHubContractIngestionService.php`
- `app/Services/ControlHub/Data/ContractIngestionResult.php`
- `app/Events/ControlHubContractChanged.php`
- `app/Exceptions/ControlHub/InvalidUpstreamContractException.php`
- `tests/Feature/ControlHub/ControlHubContractIngestionTest.php`

**Modifiés :**
- `docs/qa/domains/controlhub-contract.md` (append Section 5 — ingestion idempotente, Story 28.2)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (28-2 → review)
- `_bmad-output/implementation-artifacts/28-2-reception-idempotente-contrat-amont.md` (tasks cochées, Dev Agent Record, status)

### Change Log

- 2026-06-26 — Implémentation Story 28.2 (couche d'ingestion idempotente du contrat amont) : service + DTO + event + exception + tests HÔTE (10 tests verts) ; runbook QA enrichi (Section 5). NFR3/NFR4/R3 tenus.
