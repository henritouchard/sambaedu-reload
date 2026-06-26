# Story 28.1: Modèle et persistance du contrat amont (controlHub)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **un modèle de données qui représente un contrat amont reçu de controlHub** (items imposés `{type, clé, valeur, état verrouillé/permissif/absent}` avec cible `instance|label`, catalogue applicatif, labels `{nom, mode libre/réservé}`, groupes imposés `{nom, label}`, état du lien),
so that **l'instance dispose d'une structure stable et requêtable pour enforcer le contrat**, tout en restant strictement inchangée tant qu'aucun contrat n'est reçu.

> Story **fondatrice** de l'Epic 28. Elle livre **uniquement** le schéma de persistance + les modèles Eloquent + leurs relations. Elle **n'ingère rien** (→ Story 28.2) et **ne touche pas** `StateCompiler` (→ Story 28.3).

> **Décision de nommage (Henri, 2026-06-26)** : préfixe **`controlhub_contract_`** pour les tables et **`ControlHubContract*`** pour les modèles — aligné sur le vocabulaire produit réel (`ControlHubConnection`, `controlhub_tasks` existants). Le garde-fou R3 (aucun « central ») reste pleinement respecté.

## Acceptance Criteria

1. **Given** aucune table de contrat controlHub n'existe, **When** la migration de la story est jouée, **Then** les tables nécessaires sont créées :
   - un **contrat** porteur de l'**état du lien** (`active` / `severed`) ;
   - des **items imposés** `{type, clé, valeur, état d'enforcement ∈ {verrouillé, permissif, absent}}` avec une **cible** `instance | label` (et le nom du label si cible = label) ;
   - un **catalogue applicatif** faisant autorité (liste d'apps rattachées au contrat) ;
   - des **labels** `{nom, mode ∈ {libre, réservé}}` ;
   - des **groupes imposés** `{nom, label associé}`.
2. **Given** les tables créées, **When** on inspecte la migration `down()`, **Then** elle supprime proprement toutes les tables créées (rollback complet, ordre FK respecté).
3. **Given** les modèles Eloquent, **When** on lit leur définition (tables, colonnes, propriétés, relations, casts d'enum), **Then** **aucun** nom de table, colonne, modèle, enum, relation ou classe ne contient le mot **« central »** (garde-fou **R3**). Le vocabulaire de code est `controlhub_contract_*` / `ControlHubContract*`.
4. **Given** un contrat persisté avec des items, labels, groupes imposés et apps de catalogue, **When** on charge le modèle racine, **Then** chaque relation (`items`, `labels`, `imposedGroups`, `catalogApps`) renvoie les enregistrements liés ; l'état d'enforcement, le mode de label, la cible et l'état du lien sont exposés en **enums PHP castés** (pas des strings nues).
5. **Given** un même triplet naturel (item : `contrat + type + clé + cible`; label : `contrat + nom`; groupe imposé : `contrat + nom`; app catalogue : `contrat + clé app`), **When** on tente d'insérer un doublon, **Then** une **contrainte d'unicité** l'en empêche (clé naturelle préparant l'upsert idempotent de la Story 28.2 — **NFR4**).
6. **Given** une installation SE5 sans aucun contrat reçu, **When** le code applicatif s'exécute normalement, **Then** **aucune** ligne de contrat n'existe par défaut (pas de seeder, pas de contrat implicite) et **rien** dans le comportement SE5 ne change (**NFR3** — standalone préservé). Le code ne suppose **jamais** l'existence d'une autorité amont.

## Tasks / Subtasks

- [x] **Task 1 — Enums du contrat controlHub** (AC: #1, #3, #4)
  - [x] Créer `app/Enums/ControlHubLinkState.php` (`string`) : `Active = 'active'`, `Severed = 'severed'`.
  - [x] Créer `app/Enums/ControlHubEnforcementState.php` (`string`) : `Locked = 'locked'`, `Permissive = 'permissive'`, `Absent = 'absent'` (verrouillé / permissif / absent).
  - [x] Créer `app/Enums/ControlHubContractTarget.php` (`string`) : `Instance = 'instance'`, `Label = 'label'`.
  - [x] Créer `app/Enums/ControlHubLabelMode.php` (`string`) : `Free = 'free'`, `Reserved = 'reserved'` (libre / réservé).
  - [x] PHPDoc en tête de chaque enum rappelant le mapping métier FR + le garde-fou R3 (aucun « central »). Miroir du style de `app/Enums/StateMaille.php`.
- [x] **Task 2 — Migration `create_controlhub_contract_tables`** (AC: #1, #2, #5, #6)
  - [x] Fichier `database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php` (timestamp > dernière migration `2026_06_25_120000`).
  - [x] Garde idempotente en tête de `up()` : `if (Schema::hasTable('controlhub_contracts')) { return; }` (cf. migrations capabilities/registry).
  - [x] Table `controlhub_contracts` : `id`, `authority_ref` (string, **nullable**, **unique** — identifiant neutre de l'autorité amont émettrice, jamais « central »), `link_state` (string, défaut `'active'`), `received_at` (timestamp nullable), `timestamps`. Commentaires de colonnes explicites.
  - [x] Table `controlhub_contract_items` : `id`, `controlhub_contract_id` (FK `constrained()->cascadeOnDelete()`), `type` (string — vocabulaire d'entité amont : applications, wallpapers, capabilities, shortcuts, agent_tools…), `key` (string — clé de l'item imposé), `value` (text, **nullable** — sémantique selon `type`), `enforcement_state` (string — `locked|permissive|absent`), `target_type` (string — `instance|label`, défaut `'instance'`), `target_label` (string, **nullable** — nom du label si `target_type = label`), `timestamps`. **Unique** `['controlhub_contract_id','type','key','target_type','target_label']` (clé naturelle idempotente).
  - [x] Table `controlhub_contract_labels` : `id`, `controlhub_contract_id` (FK cascade), `name` (string), `mode` (string — `free|reserved`), `timestamps`. **Unique** `['controlhub_contract_id','name']`.
  - [x] Table `controlhub_contract_imposed_groups` : `id`, `controlhub_contract_id` (FK cascade), `name` (string — nom du `workstationGroup` à garantir), `label_name` (string, **nullable** — label réservé porté), `timestamps`. **Unique** `['controlhub_contract_id','name']`.
  - [x] Table `controlhub_contract_catalog_apps` : `id`, `controlhub_contract_id` (FK cascade), `app_key` (string — identifiant de l'app faisant autorité), `display_name` (string, nullable), `timestamps`. **Unique** `['controlhub_contract_id','app_key']`.
  - [x] `down()` : `Schema::dropIfExists()` des tables dans l'ordre **inverse** (enfants avant parent) pour respecter les FK.
  - [x] **Aucun** appel de seeder ; **aucune** insertion de ligne par défaut (NFR3).
  - [x] ⚠️ Noms d'index/contraintes : les tables `controlhub_contract_*` sont longues — donner un **nom court explicite** au 2e argument de `unique(...)` pour rester sous la limite PG de 63 caractères (ex. `chc_item_natural_key`, `chc_label_unique`).
- [x] **Task 3 — Modèles Eloquent + relations** (AC: #3, #4)
  - [x] `app/Models/ControlHubContract.php` : `$table = 'controlhub_contracts'`, `$fillable`, `$casts` (`link_state` → `ControlHubLinkState::class`, `received_at` → `datetime`). Relations `hasMany` : `items()` (→ `ControlHubContractItem`), `labels()` (→ `ControlHubContractLabel`), `imposedGroups()` (→ `ControlHubContractImposedGroup`), `catalogApps()` (→ `ControlHubContractCatalogApp`). PHPDoc `@property` complet.
  - [x] `app/Models/ControlHubContractItem.php` : `belongsTo` `contract()` ; casts `enforcement_state` → `ControlHubEnforcementState::class`, `target_type` → `ControlHubContractTarget::class`.
  - [x] `app/Models/ControlHubContractLabel.php` : `belongsTo` `contract()` ; cast `mode` → `ControlHubLabelMode::class`.
  - [x] `app/Models/ControlHubContractImposedGroup.php` : `belongsTo` `contract()`. (Pas de FK dure vers `controlhub_contract_labels` : on rattache par `label_name` côté logique amont ; documenter ce choix.)
  - [x] `app/Models/ControlHubContractCatalogApp.php` : `belongsTo` `contract()`.
  - [x] Chaque modèle : `declare(strict_types=1)`, `use HasFactory`, PHPDoc d'en-tête rappelant le périmètre (modèle de **réception** ; l'ingestion = 28.2, la résolution = 28.3) + la distinction avec `ControlHubConnection` (cf. Dev Notes).
- [x] **Task 4 — Factories** (support des tests — AC: #4, #5)
  - [x] `database/factories/ControlHubContractFactory.php` + factories des 4 enfants (état `active`, valeurs d'enum par défaut plausibles). Miroir de `database/factories/CapabilityFactory.php`.
- [x] **Task 5 — Tests unitaires HÔTE (php8.4 + sqlite)** (AC: #1–#6)
  - [x] `tests/Unit/Models/ControlHubContractTest.php` (ou `tests/Feature/...`) avec `RefreshDatabase` :
    - migration jouée → les 5 tables existent (`Schema::hasTable`/`hasColumn`) ;
    - relations chargent les enfants liés (1 contrat → N items/labels/groupes/apps) ;
    - casts d'enum effectifs (lecture renvoie une instance d'enum, pas un string) ;
    - **unicité** : un doublon de clé naturelle lève une `QueryException` (item, label, groupe imposé, app) ;
    - **garde-fou R3** : test asserant qu'aucun nom de colonne des 5 tables ne contient `central` (introspection `Schema::getColumnListing`) ET qu'aucun nom de table créé ne contient `central`.
  - [x] **NE PAS** dépendre de la longueur varchar pour valider (SQLite n'applique pas `varchar(n)` — l'overflow PG 22001 est invisible en test ; cf. mémoire projet). Les contraintes testées sont l'**unicité** et la **présence** de colonnes/enum, pas des longueurs.
- [x] **Task 6 — Validation finale**
  - [x] Lancer la suite ciblée sur HÔTE : `php artisan test --filter ControlHubContract` (et le filtre des nouveaux tests).
  - [x] Vérifier `php artisan migrate` puis `php artisan migrate:rollback` (aller-retour propre) sur la base de dev locale **si pertinent** — sinon s'en remettre au test `RefreshDatabase`.

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 28.1

**DANS** : migration des tables `controlhub_contract_*`, enums, modèles Eloquent + relations, factories, tests.
**HORS** (ne pas déborder) :
- **Ingestion / upsert** d'un payload reçu → **Story 28.2** (cette story ne fait que rendre l'upsert *possible* via clés naturelles uniques).
- **Branchement dans `StateCompiler`** (tier de précédence amont > local) → **Story 28.3**. Ne **pas** créer de `StateProvider` ni toucher `app/Services/Agent/StateCompiler.php`.
- **Mapping label → `WorkstationGroup` local** (1 label max/groupe), **garantie d'existence des groupes imposés**, **résolution par label** → **Epic 30** (Stories 30.x). Ne **pas** ajouter de colonne `label` sur `WorkstationGroup` ici.
- **Ordres de déclenchement d'install** + **bornage catalogue** → **Epic 31**. Le catalogue est ici une simple **liste persistée** ; aucune logique de filtrage d'install.
- **Schéma d'échange versionné** controlHub↔SE5 → **Epic 33**. `authority_ref` est un simple identifiant neutre ; pas de validation de version de schéma ici.

### Garde-fous projet CRITIQUES (inscrits comme contraintes de la story)

- **R3 — Vocabulaire (BLOQUANT)** : **INTERDIT** : le mot `central` dans tout nom de table, colonne, modèle, enum, relation, classe ou commentaire. Préfixe de table imposé : `controlhub_contract_`, classes `ControlHubContract*`. [Source: prd-contrat-manage-se5.md#§2, #R3 ; epics-contrat-manage-se5.md#R3 ; décision Henri 2026-06-26]
  - **Distinction CRITIQUE vs `ControlHubConnection`** : `app/Models/ControlHubConnection.php` (table `controlhub_connection`) modélise le **lien/transport** vers controlHub (fédération, handshake, heartbeat, tokens). `ControlHubContract` modélise la **politique imposée** reçue *via* ce lien. Concepts **distincts et complémentaires** — en 28.1 : **aucune FK** entre les deux, **aucune** fusion. (Un éventuel rattachement `controlhub_connection_id` est explicitement différé.)
  - Ne **pas** confondre non plus avec `app/Services/Agent/StateContract.php` (contrat *desired-state* agent, déjà existant) : homonymie sur « contract », domaine différent.
- **NFR3 — Standalone préservé** : sans contrat, comportement SE5 strictement inchangé. ⇒ **aucun seeder**, **aucune** ligne par défaut, **aucune** lecture du contrat ajoutée dans un chemin de code existant. Tables vides = pas d'autorité amont. [Source: prd#NFR3]
- **NFR4 — Idempotence (préparée, pas implémentée)** : les **clés naturelles uniques** (cf. Task 2) sont le seul livrable d'idempotence de 28.1 ; elles permettront l'`upsert` de 28.2. [Source: prd#NFR4 ; epics#Story 28.2]
- **Réutiliser `StateCompiler::specificity()`** pour la résolution — **en 28.3**, pas ici. Ne rien réinventer côté résolution. [Source: epics#Additional Requirements]

### Conventions de code à respecter (pointeurs exacts)

- **Préfixe table** : `controlhub_` est la convention établie du repo (`controlhub_connection`, `controlhub_tasks` dans `2026_01_30_000000_create_unified_schema.php`). On l'étend en `controlhub_contract_*`.
- **Migrations** : un fichier cohérent pour l'agrégat (`create_*_tables`) est admis — cf. `database/migrations/2026_06_11_140000_create_agent_report_tables.php`. Style de colonnes/commentaires : `database/migrations/2026_06_18_100000_create_capabilities_table.php` et `..._100200_create_capability_assignments_table.php` (FK `constrained()->cascadeOnDelete()`, `$table->unique([...], 'nom_court')`, commentaires métier).
- **Enums** : style `app/Enums/StateMaille.php`, `app/Enums/StateScope.php`, `app/Enums/ResourceSemantics.php` (enum backed `string`, PHPDoc d'en-tête).
- **Modèles** : style `app/Models/Capability.php` (`declare(strict_types=1)`, `$table`, `$fillable`, `$casts` avec enums, PHPDoc `@property`) et `app/Models/WorkstationGroup.php` pour les relations. Casse de classe `ControlHub*` alignée sur `ControlHubConnection`.
- **Factories** : `database/factories/CapabilityFactory.php`.
- **PostgreSQL** est la base de prod ; **SQLite** en test. ⚠️ `varchar(n)` **non appliqué** en SQLite : ne jamais faire reposer une validation sur la longueur. Domaines fermés → enums PHP (casts), pas des `CHECK` SQL spécifiques PG (resteraient invisibles en test). [Source: mémoire projet — sqlite_tests_no_varchar_enforcement]
- **PHP-FPM user = www-admin** : pertinent pour l'exécution VM (chown des fichiers lus par PHP). Sans impact direct sur 28.1 (tests HÔTE), mais à garder en tête si une exécution VM était demandée.

### Modèle de données proposé (référence d'implémentation)

```
controlhub_contracts
  id, authority_ref (uniq, null), link_state ['active'|'severed'], received_at?, timestamps
controlhub_contract_items
  id, controlhub_contract_id →contracts (cascade),
  type, key, value?, enforcement_state ['locked'|'permissive'|'absent'],
  target_type ['instance'|'label'], target_label?,
  UNIQUE(controlhub_contract_id, type, key, target_type, target_label), timestamps
controlhub_contract_labels
  id, controlhub_contract_id (cascade), name, mode ['free'|'reserved'],
  UNIQUE(controlhub_contract_id, name), timestamps
controlhub_contract_imposed_groups
  id, controlhub_contract_id (cascade), name, label_name?,
  UNIQUE(controlhub_contract_id, name), timestamps
controlhub_contract_catalog_apps
  id, controlhub_contract_id (cascade), app_key, display_name?,
  UNIQUE(controlhub_contract_id, app_key), timestamps
```

Correspondance avec la couture controlHub↔SE5 (ce que SE5 attend de recevoir) : items imposés `{type, valeur, état, cible instance|label:<nom>}`, catalogue applicatif, labels `{nom, mode libre/réservé}`, groupes imposés `{nom, label réservé}`, état du lien. [Source: prd#§9 ; handoff-controlhub-contrat-manage.md#§7]

### Project Structure Notes

- Nouveaux fichiers cohérents avec l'arborescence existante : `app/Enums/ControlHub*.php`, `app/Models/ControlHubContract*.php`, `database/migrations/2026_06_26_*`, `database/factories/ControlHubContract*Factory.php`, `tests/Unit/Models/ControlHubContractTest.php`.
- Aucun conflit de structure attendu : aucune table existante n'est altérée (création pure). Aucune route, aucun service, aucun Gate touché en 28.1.
- **Racine = projet Laravel** (artisan/app à la racine ; pas de préfixe `laravel/`). [Source: mémoire projet — root_is_laravel]

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 28.1] — AC d'origine, cible `instance|label`.
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#§5, #§7, #§9] — modèle 3 positions, NFR3/NFR4, couture controlHub.
- [Source: _bmad-output/planning-artifacts/handoff-controlhub-contrat-manage.md#§3, #§7] — état à 3 positions, contrat d'interface attendu.
- [Source: app/Models/ControlHubConnection.php] — modèle du **lien** controlHub (à NE PAS confondre avec le contrat).
- [Source: app/Services/Agent/StateCompiler.php] — `specificity()` (réutilisé en 28.3, pas ici).
- [Source: app/Models/Capability.php ; app/Models/WorkstationGroup.php] — patrons de modèle/relations.
- [Source: database/migrations/2026_06_18_100000_create_capabilities_table.php] — patron de migration (garde `hasTable`, commentaires, FK cascade, unique).

## Dépendances

- **Amont (bloquantes)** : **aucune**. Story **fondatrice** de l'Epic 28 — création de tables pure, ne dépend d'aucune autre story.
- **Aval (dépendent de 28.1)** :
  - **28.2** (`backlog`) — Réception idempotente : a besoin du schéma + clés naturelles uniques de 28.1.
  - **28.3** (`backlog`) — Résolution amont > local dans `StateCompiler` : a besoin des modèles de 28.1.
  - **Epic 30** (labels & mapping) et **Epic 31** (catalogue borné & install) consomment ce modèle ultérieurement.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), **jamais la VM** (VM sans `pdo_sqlite`). [Source: mémoire projet — phpunit_test_env_host_vs_vm]
- `DB_CONNECTION=sqlite` (cf. `phpunit.xml`), trait `RefreshDatabase`.
- Filtre ciblé : `php artisan test --filter ControlHubContract`.
- Couverture : présence des 5 tables/colonnes, relations `hasMany`/`belongsTo`, casts d'enum, contraintes d'unicité (clés naturelles), garde-fou R3 (introspection : aucun `central` dans tables/colonnes).
- **Piège SQLite** : ne pas tester de longueur varchar (non appliquée). Tester l'unicité et les enums, pas les bornes de chaîne.
- ⚠️ **VM** : migrations **pas auto-jouées** par le dev-cycle (migre SQLite uniquement) ; ne pas présumer la table présente côté VM. [Source: mémoire projet — vm_migrations_not_auto_applied]

## Recommandation Modèle Dev

**`sonnet`** (confirmé par Henri).

Justification : story **mécanique et bien bornée** — migration + enums + modèles + relations + factories + tests, sans logique de résolution ni d'enforcement (différés en 28.2/28.3). Le risque principal est de **déborder du périmètre** ou de **violer le garde-fou R3** ; ces deux écueils sont déjà explicitement cadrés dans la story (sections Périmètre et Garde-fous), donc adressables par un modèle sonnet sans raisonnement architectural lourd. La conception du schéma (qui engage l'avenir) est figée ici ; le dev l'implémente. Le dev-cycle route la **review vers opus** (modèle opposé), ce qui place le jugement d'opus sur la fondation là où il compte — en critique de conception.

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6 (confirmé par Henri)

### Debug Log References

- Ajustement test `test_item_natural_key_unique_constraint` : comportement SQLite NULL != NULL dans les index uniques — deux lignes avec target_label=NULL ne déclenchent pas de violation d'unicité, ni en SQLite ni en PG (cf. mémoire projet sqlite_tests_no_varchar_enforcement). Corrigé en utilisant target_type='label' + target_label='salle-info' (valeur non-null) pour tester la contrainte. La contrainte reste correcte côté schéma ; le piège est purement dans le cas d'usage NULL en test.

### Completion Notes List

- 4 enums créés (`ControlHubLinkState`, `ControlHubEnforcementState`, `ControlHubContractTarget`, `ControlHubLabelMode`) — style `StateMaille.php`, `declare(strict_types=1)`, PHPDoc FR + garde-fou R3.
- 1 migration créée (`2026_06_26_100000`) : 5 tables `controlhub_contract_*`, garde idempotente `hasTable`, FK cascade, noms d'index courts (< 63 car. PG), aucun seeder, `down()` ordre inverse FK.
- 5 modèles Eloquent créés : `ControlHubContract` (racine, 4 `hasMany`), `ControlHubContractItem` (2 casts enum), `ControlHubContractLabel` (1 cast enum), `ControlHubContractImposedGroup` (sans FK dure vers labels — choix documenté), `ControlHubContractCatalogApp`. Tous `declare(strict_types=1)` + `HasFactory` + PHPDoc.
- 5 factories créées avec états nommés pertinents (severed, permissive, absent, forLabel, reserved, withLabel, withoutDisplayName…).
- 37 tests verts (`php artisan test --filter ControlHubContract`, 125 assertions) : tables/colonnes, R3 (introspection), casts enum effectifs, relations hasMany/belongsTo, unicité clés naturelles (item/label/groupe/app), NFR3 standalone, cast datetime.
- Déviation mineure vs story : test unicité item utilise target_label non-null (piège SQLite NULL, comportement identique en PG). Noté en Debug Log.
- Aucun seeder, aucune lecture ajoutée dans un chemin existant (NFR3 respecté).
- Runbook QA créé (`docs/qa/domains/controlhub-contract.md`) + entrée dans `docs/qa/README.md`.

### File List

- `app/Enums/ControlHubLinkState.php` (nouveau)
- `app/Enums/ControlHubEnforcementState.php` (nouveau)
- `app/Enums/ControlHubContractTarget.php` (nouveau)
- `app/Enums/ControlHubLabelMode.php` (nouveau)
- `database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php` (nouveau)
- `app/Models/ControlHubContract.php` (nouveau)
- `app/Models/ControlHubContractItem.php` (nouveau)
- `app/Models/ControlHubContractLabel.php` (nouveau)
- `app/Models/ControlHubContractImposedGroup.php` (nouveau)
- `app/Models/ControlHubContractCatalogApp.php` (nouveau)
- `database/factories/ControlHubContractFactory.php` (nouveau)
- `database/factories/ControlHubContractItemFactory.php` (nouveau)
- `database/factories/ControlHubContractLabelFactory.php` (nouveau)
- `database/factories/ControlHubContractImposedGroupFactory.php` (nouveau)
- `database/factories/ControlHubContractCatalogAppFactory.php` (nouveau)
- `tests/Unit/Models/ControlHubContractTest.php` (nouveau)
- `docs/qa/domains/controlhub-contract.md` (nouveau)
- `docs/qa/README.md` (modifié — entrée domaine ajoutée)
