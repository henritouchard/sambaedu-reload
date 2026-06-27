# Story 30.1: Réception des labels et des groupes imposés

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **(a) GARANTIR — et PROUVER par test — que les labels amont (nom + mode `libre`/`réservé`) et les groupes imposés (nom + label associé) sont reçus et persistés de façon idempotente par le canal d'ingestion existant, et (b) DURCIR la réception en refusant un payload incohérent où un groupe imposé désigne un label associé qui n'est pas déclaré dans le même contrat**,
so that **l'instance connaisse un vocabulaire de ciblage amont fiable et cohérent (FR9), sur lequel s'appuieront le mapping (30.2), la garantie d'existence (30.3) et la résolution par label (30.4)**.

> **Story à DEUX moitiés de nature opposée (patron 29.5).**
> **Moitié FR9 (réception/persistance) = PREUVE, pas construction.** Les labels et groupes imposés sont **DÉJÀ** reçus et persistés **par construction**. La table `controlhub_contract_labels` (nom + `mode` casté `ControlHubLabelMode` free/reserved) et la table `controlhub_contract_imposed_groups` (nom + `label_name` nullable) ont été livrées en **28.1** ; le service `ControlHubContractIngestionService::ingest()` les normalise et les upsert/prune via `reconcileChildren()` (4 agrégats : items, labels, groupes imposés, catalogue) en **28.2** ; la couverture existe déjà dans `tests/Feature/ControlHub/ControlHubContractIngestionTest.php` (persistance 2 labels + 1 groupe imposé, idempotence no-op, upsert/prune avec `mode` et `label_name`, rejet `mode` hors-domaine). 30.1 **prouve cette chaîne** par un test de réception dédié verrouillant FR9 ; elle **ne crée AUCUNE** table/modèle/enum/migration (ce serait un doublon de 28.1).
> **Moitié durcissement (intégrité référentielle) = la SEULE nouvelle livraison.** Aujourd'hui `normalizeImposedGroups()` stocke `label_name` comme **chaîne libre sans vérification** (constat vérifié ci-dessous : aucun cross-check `label_name` → label déclaré n'existe dans `app/`). Or « groupe imposé **avec son label associé** » (FR9) présuppose que ce label fait partie du vocabulaire reçu. 30.1 ajoute donc un **garde-fou de réception** dans la **même couche et le même patron** que les validations 28.2 existantes (`InvalidUpstreamContractException` levée **avant** la transaction → rollback total) : un `label_name` non-nul doit désigner un label déclaré dans le **même payload**, sinon l'ingestion est refusée.

> **⚠️ Décision Henri (rappel) — pas de compat ascendante exigée** : aucun environnement de prod à préserver (seul invariant intangible = enrôlement controlHub). Les garde-fous « non-régression » visent le **bon design** (sans contrat amont, comportement strictement inchangé — NFR3), pas la rétrocompat d'appelants exotiques. [Source: mémoires projet — zero_prod_publish_is_test, no_legacy_transition_state]

## Contexte du code (constat vérifié 2026-06-27)

### A. Réception/persistance FR9 — la chaîne EXISTE déjà, à PROUVER (ne rien construire)

- **Tables livrées (28.1)** — `database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php` :
  - `controlhub_contract_labels` (L.108-131) : `name`, `mode` (string `free`|`reserved`), clé naturelle `UNIQUE(controlhub_contract_id, name)` = `chc_label_unique`.
  - `controlhub_contract_imposed_groups` (L.134-162) : `name`, `label_name` **nullable** (commentaire L.150-153 : « PAS de FK dure vers `controlhub_contract_labels` : rattachement par nom côté logique amont ; mapping groupe↔label local différé → Epic 30 »), clé naturelle `UNIQUE(controlhub_contract_id, name)` = `chc_imposed_group_unique`, FK courte `chc_imposed_group_contract_fk`.
- **Modèles + enum livrés (28.1)** — `app/Models/ControlHubContractLabel.php` (cast `mode => ControlHubLabelMode::class`, relation `contract()`), `app/Models/ControlHubContractImposedGroup.php` (`label_name` fillable nullable, **pas** de relation label — déférée Epic 30), `app/Enums/ControlHubLabelMode.php` (`Free='free'` / `Reserved='reserved'`, PHPDoc « libre/réservé »).
- **Ingestion idempotente livrée (28.2)** — `app/Services/ControlHub/ControlHubContractIngestionService.php` :
  - `ingest(array $payload): ContractIngestionResult` (L.75) — payload documenté L.47-57 incluant `'labels' => [['name'=>…, 'mode'=>…]]` et `'imposed_groups' => [['name'=>…, 'label_name'=>…]]`.
  - `normalizeLabels()` (L.341-369) : valide `name` requis + `mode` ∈ `ControlHubLabelMode` (sinon `InvalidUpstreamContractException`), construit `{key:[contract_id,name], attrs:[mode]}`.
  - `normalizeImposedGroups()` (L.377-398) : valide `name` requis, normalise `label_name` (`'' | null → null`), construit `{key:[contract_id,name], attrs:[label_name]}` — **aucun** contrôle que `label_name` référence un label déclaré.
  - `reconcileChildren()` upsert (`updateOrCreate` clé naturelle) + prune (`whereNotIn`) des 4 agrégats dans **une** `DB::transaction` (L.94-142) ; labels & groupes imposés traités L.110-122. Normalisation/validation PURE **avant** transaction (L.79-82) → rollback total sur rejet.
  - DTO `app/Services/ControlHub/Data/ContractIngestionResult.php` : compteurs `labels`/`imposedGroups` `{created,updated,deleted}` + `mutated` (no-op NFR4).
- **Couverture existante (28.2)** — `tests/Feature/ControlHub/ControlHubContractIngestionTest.php` : payload de référence (L.50-69) avec 2 labels (`salle-info`=reserved, `nomade`=free) + 1 groupe imposé (`parc-terminales`→`salle-info`) ; asserte persistance (L.84-85), no-op (L.117-118), upsert/prune + compteurs labels & imposedGroups (L.146-188), rejet `mode` hors-domaine (L.279-280, `test_invalid_enum_rejected_and_no_partial_write`), prune-à-zéro (L.343-352). `tests/Unit/Models/ControlHubContractTest.php` : tables/colonnes/casts/clés naturelles.
- **Factories** : `ControlHubContractLabelFactory` (state `reserved()`), `ControlHubContractImposedGroupFactory` (state `withLabel(string)`), `ControlHubContractFactory` (states `severed()`, `notYetReceived()`).

> **Conclusion FR9 :** labels (nom + mode libre/réservé) et groupes imposés (nom + label_name) sont **déjà** reçus et persistés idempotemment par 28.1+28.2. Le livrable FR9 de 30.1 est un **test de preuve / non-régression dédié côté SE5** qui verrouille cette chaîne (un payload labels+groupes imposés → lignes persistées avec le bon `mode` casté et le bon `label_name`, idempotence + prune). **Aucune** table/modèle/enum/migration.

### B. Intégrité référentielle `imposed_groups.label_name` → label déclaré — ABSENTE, à CONSTRUIRE

- **Grep vérifié** : `grep -rniE "label_name.*exist|labelNames|declaredLabel|label.*introuv" app/` → **0 résultat**. Aucun cross-check n'existe : un groupe imposé dont `label_name` ne correspond à **aucun** label déclaré dans le payload est aujourd'hui **persisté silencieusement** (chaîne libre).
- **Patron de validation 28.2 à IMITER** : `normalizeItems()` (L.265-333) lève déjà `InvalidUpstreamContractException::for(champ, raison)` pour un enum hors-domaine et pour l'**incohérence cible** (`target_type=label` exige `target_label` non vide L.297-302 ; `target_type=instance` exige `target_label` vide L.304-308) — **avant** la transaction. Le durcissement de 30.1 s'aligne **exactement** sur ce patron (même exception, même couche, même garantie de rollback total).
- **Placement** : le cross-check exige de connaître l'ensemble des **noms de labels déclarés** du même payload. Il doit donc s'exécuter dans `ingest()` **après** `normalizeLabels()` et `normalizeImposedGroups()` mais **avant** `DB::transaction` (zone L.79-92), p.ex. une méthode `assertImposedGroupLabelsDeclared(array $labels, array $imposedGroups): void` qui, pour chaque groupe imposé à `label_name` non-nul, vérifie l'appartenance à l'ensemble des `labels[].name` ; sinon lève `InvalidUpstreamContractException`.

## Acceptance Criteria

### Réception/persistance FR9 — preuve par construction

1. **Given** un contrat amont **valide** contenant des labels (chacun `{name, mode}` avec `mode ∈ {free, reserved}`) et des groupes imposés (chacun `{name, label_name}` avec `label_name` déclaré ou nul),
   **When** l'ingestion s'exécute (`ControlHubContractIngestionService::ingest()`),
   **Then** chaque label est persisté dans `controlhub_contract_labels` avec son `mode` correctement casté en `ControlHubLabelMode` (`free`=libre / `reserved`=réservé), et chaque groupe imposé est persisté dans `controlhub_contract_imposed_groups` avec son `name` et son `label_name` associé (ou `null`) — **ce qui prouve** que le vocabulaire de ciblage amont (labels + groupes imposés) est reçu et requêtable.

2. **Given** le même contrat (labels + groupes imposés inchangés) reçu une seconde fois,
   **When** l'ingestion s'exécute,
   **Then** l'opération est un **no-op** sur ces agrégats (aucune écriture fonctionnelle, `ContractIngestionResult::$mutated === false`, aucun `ControlHubContractChanged` émis) — idempotence NFR4 préservée pour labels & groupes imposés.

3. **Given** un contrat ultérieur modifie le vocabulaire (label ajouté/retiré, `mode` changé, groupe imposé ajouté/retiré, `label_name` changé),
   **When** l'ingestion s'exécute,
   **Then** les labels et groupes imposés sont **réconciliés** (upsert + prune) sur leurs clés naturelles, les compteurs `result.labels` / `result.imposedGroups` `{created,updated,deleted}` reflètent exactement le delta, et aucune table/modèle/enum/migration **nouvelle** n'est introduite par 30.1 (la chaîne 28.1/28.2 est **citée comme preuve**, non réimplémentée).

### Durcissement réception — intégrité référentielle `label_name`

4. **Given** un payload où un groupe imposé porte un `label_name` **non-nul** qui désigne un label **déclaré** dans le même contrat (`imposed_groups[].label_name ∈ labels[].name`),
   **When** l'ingestion s'exécute,
   **Then** l'ingestion réussit et le groupe imposé est persisté avec ce `label_name` (comportement actuel préservé — le payload de référence des tests 28.2 reste valide et vert).

5. **Given** un payload où un groupe imposé porte un `label_name` **non-nul** qui ne correspond à **aucun** label déclaré dans le même contrat,
   **When** l'ingestion s'exécute,
   **Then** l'ingestion est **refusée** par une `InvalidUpstreamContractException` (champ = `imposed_groups.label_name (<nom_groupe>)`, raison explicite citant le label introuvable), levée **avant** la transaction → **aucune écriture partielle** (rollback total, l'état préexistant est intact).

6. **Given** un groupe imposé sans label associé (`label_name` nul, absent ou `''`),
   **When** l'ingestion s'exécute,
   **Then** aucun cross-check n'est appliqué (un groupe imposé sans label est légitime) et le groupe est persisté avec `label_name = null` — le durcissement ne contraint **que** les `label_name` non-nuls.

### Garde-fous transverses

7. **Given** aucun contrat amont (standalone), **OU** un payload sans labels/groupes imposés,
   **When** l'ingestion s'exécute (ou n'a jamais lieu),
   **Then** le comportement est **strictement inchangé** (NFR3) : tables vides, aucune erreur, le court-circuit « listes vides » de `ingest()` est préservé, le durcissement (AC#5) ne se déclenche jamais (aucun groupe imposé à `label_name` non-nul à vérifier).

8. **Given** la suite de tests HÔTE (php8.4 + sqlite, `RefreshDatabase`),
   **When** elle s'exécute,
   **Then** sont **couverts et verts** : (a) **preuve FR9** AC#1-#3 (persistance labels avec `mode` casté + groupes imposés avec `label_name` ; idempotence no-op ; upsert/prune avec compteurs) ; (b) **durcissement** AC#4 (label_name cohérent → succès), AC#5 (label_name orphelin → `InvalidUpstreamContractException` + rollback total, aucune ligne écrite), AC#6 (label_name nul → succès) ; (c) **non-régression** : `ControlHubContractIngestionTest` (10 cas existants), `ControlHubContractTest`, suites 28.3/29.2/29.3/29.4/29.5 et `ControlHubContract*` **vertes** ; golden / `FROZEN_STATE_HASH` / `ContractV1` **inchangés** (30.1 ne touche pas le desired-state compilé).

9. **Given** le garde-fou de vocabulaire R3,
   **When** on lit le code, les libellés et les identifiants introduits (méthode de validation, messages d'exception FR, doc QA),
   **Then** **aucun** mot « central » n'apparaît : vocabulaire « amont » / `Upstream` / `ControlHub*` ; libellés FR « label », « imposé », « libre », « réservé », « groupe imposé ».

## Tasks / Subtasks

- [x] **T0 — Cadrage : prouver (FR9) vs construire (durcissement)** (AC: #1, #2, #3, #5)
  - [x] Confirmer par lecture la chaîne de réception/persistance existante (tables 28.1 `controlhub_contract_labels`/`controlhub_contract_imposed_groups` + enum `ControlHubLabelMode` + `ControlHubContractIngestionService::normalizeLabels()`/`normalizeImposedGroups()`/`reconcileChildren()` 28.2 + couverture `ControlHubContractIngestionTest`). **Ne RIEN créer** côté schéma/modèle/enum/migration. Documenter en Dev Notes que FR9 = preuve.
  - [x] Confirmer par grep l'**absence** de tout cross-check `label_name` → label déclaré dans `app/`. Figer le placement du durcissement (dans `ingest()`, après normalisation, avant transaction) et le patron (`InvalidUpstreamContractException`, calque `normalizeItems()` cohérence-cible).
  - [x] Vérifier que le payload de référence des tests 28.2 (`imposed_groups: parc-terminales→salle-info`, labels incluant `salle-info`/`labo`) reste **cohérent** sous le nouveau garde-fou (aucune régression attendue).

- [x] **T1 — Test de preuve réception FR9 (labels + groupes imposés)** (AC: #1, #2, #3)
  - [x] Ajouter un test Feature dédié (p.ex. `tests/Feature/ControlHub/UpstreamLabelsImposedGroupsReceptionTest.php`) : ingérer un contrat avec labels (`free` + `reserved`) et groupes imposés (avec et sans `label_name`) ; **asserter** la persistance avec `mode` correctement **casté** en `ControlHubLabelMode` (relire via le modèle, pas seulement `assertDatabaseHas`), le `label_name` associé/`null`, l'idempotence (2ᵉ réception identique → `mutated=false`, aucun `ControlHubContractChanged`), et l'upsert/prune (label/groupe ajouté+retiré → compteurs `result.labels`/`result.imposedGroups` exacts). Réutiliser les factories `ControlHubContract*` et le harnais d'ingestion 28.2.
  - [x] **Ne pas** dupliquer les cas déjà couverts par `ControlHubContractIngestionTest` ; ce test **verrouille spécifiquement** la lecture FR9 (mode casté + label_name) comme non-régression du vocabulaire de ciblage.

- [x] **T2 — Durcissement : intégrité référentielle `imposed_groups.label_name`** (AC: #4, #5, #6, #9)
  - [x] Dans `ControlHubContractIngestionService`, ajouter une validation (p.ex. `assertImposedGroupLabelsDeclared(array $labels, array $imposedGroups): void`) appelée dans `ingest()` **après** `normalizeLabels()`/`normalizeImposedGroups()` et **avant** `DB::transaction` (zone L.79-92) : construire l'ensemble des noms de labels déclarés ; pour chaque groupe imposé à `label_name` **non-nul** absent de cet ensemble, lever `InvalidUpstreamContractException::for("imposed_groups.label_name ({$groupName})", "label associé « {$labelName} » non déclaré dans le contrat")`.
  - [x] **Ne contraindre QUE** les `label_name` non-nuls (un groupe imposé sans label reste valide — AC#6). **Ne PAS** exiger que le label soit en mode `reserved` (l'enum dit « *typiquement* associé à un groupe imposé » — pas une obligation ; sur-contrainte spéculative à éviter — voir Dev Notes). **Ne PAS** ajouter de FK dure (couplage prématuré explicitement refusé en 28.1 ; mapping local = Epic 30.2/30.3).
  - [x] R3 : message FR sans « central ». PHPDoc « amont »/« label »/« groupe imposé ».

- [x] **T3 — Tests HÔTE du durcissement** (AC: #4, #5, #6, #7)
  - [x] Étendre le test Feature (T1 ou cas ajoutés à `ControlHubContractIngestionTest`) : (a) `label_name` cohérent → ingestion réussit (AC#4) ; (b) `label_name` orphelin → `InvalidUpstreamContractException` levée **et** `assertDatabaseCount` à 0 sur labels+groupes imposés (rollback total, AC#5 — calque `test_invalid_enum_rejected_and_no_partial_write`) ; (c) `label_name` nul/absent/`''` → ingestion réussit, `label_name = null` (AC#6) ; (d) standalone / payload sans groupes imposés → durcissement inerte (AC#7).
  - [x] Vérifier la **non-régression** : `ControlHubContractIngestionTest` (10 cas), `ControlHubContractTest`, `UpstreamContractResolutionTest`, suites 29.x, `ContractV1Test` → **0 régression**.

- [x] **T4 — Runbook QA (domaine `controlhub-contract`, Section 11)** (AC: #1, #4, #5)
  - [x] **Append** une **Section 11** à `docs/qa/domains/controlhub-contract.md` (29.5 = Section 10 ; numérotation stable, scénarios `### Scénario 11.M`). Couvrir : (a) réception d'un contrat → labels (libre/réservé) et groupes imposés (avec label associé) persistés et requêtables ; (b) re-réception identique → no-op ; (c) contrat dont un groupe imposé référence un label inconnu → ingestion **refusée** (rien n'est persisté). Enrichir le libellé du domaine dans `docs/qa/README.md` (mention 30.1 — réception labels + groupes imposés prouvée + intégrité `label_name`, FR9).

- [x] **T5 — Validation finale**
  - [x] `php artisan test --filter "UpstreamLabelsImposedGroupsReception|ControlHubContractIngestion|ControlHubContract|UpstreamContractResolution|ContractV1"` sur HÔTE → vert (76 tests, 506 assertions).
  - [x] Grep R3 : `grep -rin "central"` sur le service modifié, le test ajouté, la doc QA → **0** (hors commentaires garde-fou).
  - [x] Vérifier que **aucune migration/modèle/enum n'a été créé** (`git status` : seuls le service + test + doc QA + sprint-status touchés) et que le **contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1` sont inchangés** (30.1 n'influence pas le desired-state compilé). `php -l` sur le service modifié → OK.

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 30.1

**DANS** :
- **FR9 (preuve)** : un **test de réception/non-régression côté SE5** prouvant que labels (nom + `mode` libre/réservé casté) et groupes imposés (nom + `label_name`) sont reçus, persistés, idempotents et réconciliés par la chaîne 28.1/28.2 existante. **Aucun** schéma/modèle/enum/migration nouveau.
- **Durcissement réception (construction)** : un garde-fou d'intégrité référentielle dans `ControlHubContractIngestionService` refusant un `imposed_groups[].label_name` non-nul orphelin (label non déclaré dans le même contrat), via `InvalidUpstreamContractException` levée avant transaction (rollback total), dans le **même patron** que les validations 28.2. Tests HÔTE + runbook QA Section 11.

**HORS** (ne pas déborder) :
- **Créer une table/modèle/enum/migration pour les labels ou groupes imposés** : ce serait un **doublon de 28.1**. Tout existe (`controlhub_contract_labels`, `controlhub_contract_imposed_groups`, `ControlHubLabelMode`).
- **Mapping d'un label sur un `WorkstationGroup` local** (assignation d'un label libre à un groupe / création d'un groupe le portant ; refus du mode réservé) → **Story 30.2** (FR10).
- **Garantie d'existence des `WorkstationGroups` imposés** (création/réconciliation, non-suppression tant que le contrat l'exige) → **Story 30.3** (FR11). 30.1 ne crée **aucun** groupe local.
- **Résolution d'un item ciblant un label** (`target_type=label` → propagation aux postes des groupes portant ce label ; règle verrou/permissif) → **Story 30.4** (FR12). 30.1 ne touche **pas** `StateCompiler`. L'intégrité de `items[].target_label` → label déclaré relève de ce ciblage (résolution) et reste **hors 30.1** (30.1 durcit uniquement `imposed_groups.label_name`).
- **Validation prédictive à l'assignation** (collision de verrous amont contradictoires à l'assignation d'un label / liaison de parc) → **Story 30.5** (FR13).
- **Aucune** FK dure `imposed_groups.label_name` → `controlhub_contract_labels` (couplage prématuré refusé en 28.1 ; le rattachement reste par nom). **Racine = projet Laravel** (pas de préfixe `laravel/`). [Source: mémoire projet — root_is_laravel]

### Décisions de conception

- **FR9 ≠ nouvelle fonctionnalité** : résister à la tentation de « créer le modèle de labels ». Il existe (28.1). Toute nouvelle table/migration de labels/groupes imposés est un doublon et une régression de cadrage. Le livrable FR9 est une **preuve** (patron 29.5 NFR2).
- **Durcissement = bon design, pas gold-plating** : « groupe imposé **avec son label associé** » (FR9) est incohérent si le label n'est pas déclaré. Le contrôle vit dans la **même couche** que les validations enum/cohérence-cible déjà présentes en 28.2 (`normalizeItems`), avec la **même** exception et la **même** garantie de rollback total. C'est de la **réception** (validation du payload entrant), **pas** du mapping local (30.2), ni de la garantie d'existence (30.3), ni de la résolution (30.4), ni de la validation à l'assignation (30.5).
- **Ne pas exiger `mode=reserved`** : l'enum documente que `reserved` est « *typiquement* associé à un groupe imposé » — « typiquement » ≠ obligatoire. Exiger spécifiquement `reserved` serait une **sur-contrainte spéculative** (mémoire projet : pas de question sur-conçue ; règle dérivable → énoncer la règle minimale et avancer). Le garde-fou minimal et suffisant = « le label doit être déclaré ».
- **Placement avant transaction** : le cross-check a besoin de l'ensemble des labels normalisés ; il s'exécute dans `ingest()` après `normalizeLabels()`/`normalizeImposedGroups()` et avant `DB::transaction` — garantissant qu'un payload incohérent ne provoque **aucune écriture partielle** (calque exact de la validation enum 28.2).
- **Idempotence préservée** : le durcissement ne modifie pas les attributs persistés (un payload valide passe à l'identique) → aucun impact sur le no-op NFR4.

### Garde-fous projet CRITIQUES

- **Pas de doublon de schéma** : aucune migration/modèle/enum nouveau. [Source: 28.1 — migration `2026_06_26_100000_create_controlhub_contract_tables.php` ; `ControlHubContractLabel`/`ControlHubContractImposedGroup`/`ControlHubLabelMode`]
- **NFR3 — standalone inchangé** : sans contrat / sans groupes imposés, le durcissement est inerte ; tables vides, aucune erreur. [Source: 28.2 — court-circuit listes vides dans `ingest()`]
- **NFR4 — idempotence** : le garde-fou ne touche pas les valeurs persistées ; un payload valide reste un no-op à la 2ᵉ réception. [Source: `ContractIngestionResult::$mutated`]
- **Patron de validation imposé** : `InvalidUpstreamContractException::for(champ, raison)` levée **avant** transaction, calque `normalizeItems()` (cohérence cible). **Ne pas** valider dans la transaction (perdrait la garantie « pas d'écriture partielle » qui devient alors « rollback » — fonctionnellement OK mais le patron 28.2 valide en amont). [Source: `ControlHubContractIngestionService` L.79-92, 297-308]
- **Vocabulaire R3** : aucun « central ». [Source: prd-contrat-manage-se5.md#R3]
- **Tests HÔTE uniquement** : php8.4 + `pdo_sqlite`, `RefreshDatabase`, **jamais la VM** (worktree git → interdit). SQLite n'applique pas varchar/enum PG → tester décisions/contenus de lignes (rejet, valeurs castées), pas des bornes. [Source: mémoires projet — phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement]

### Patrons de référence à IMITER (ne rien réinventer)

- **`ControlHubContractIngestionService::normalizeItems()`** [L.265-333] : validation enum + cohérence cible levant `InvalidUpstreamContractException` avant transaction → **calque direct** pour `assertImposedGroupLabelsDeclared()`.
- **`ControlHubContractIngestionService::normalizeLabels()`/`normalizeImposedGroups()`** [L.341-398] : structure `{key, attrs}` + normalisation `null/'' → null` du `label_name`.
- **`tests/Feature/ControlHub/ControlHubContractIngestionTest.php`** : harnais `payload()` (L.50-69), `assertDatabaseCount`/`assertDatabaseHas`, `test_invalid_enum_rejected_and_no_partial_write` (L.270-306) → **calque** pour le cas « label_name orphelin → rollback total ».
- **Factories** `ControlHubContractLabelFactory::reserved()` / `ControlHubContractImposedGroupFactory::withLabel()` → construction des données de test.

### Architecture & conventions

- **Ingestion = service back-end pur** : `ControlHubContractIngestionService::ingest()` est le point d'entrée immuable ; 30.1 enrichit sa validation amont. **Aucune** UI, **aucune** route, **aucune** modale, **aucun** Livewire (30.1 = back-end pur). Le mapping côté refnum (UI sur les pages de groupes) viendra en 30.2. [Source: CLAUDE.md — routing filesystem ; epic 30]
- **PHP-FPM = www-admin** : sans impact (tests HÔTE). **VM** : 30.1 n'ajoute **aucune** migration → rien à jouer en VM (contrairement à 29.5). [Source: mémoires projet — php_fpm_user_www_admin, vm_migrations_not_auto_applied]

### Project Structure Notes

- **Nouveaux** :
  - `tests/Feature/ControlHub/UpstreamLabelsImposedGroupsReceptionTest.php`
- **Modifiés** :
  - `app/Services/ControlHub/ControlHubContractIngestionService.php` (méthode `assertImposedGroupLabelsDeclared()` + appel dans `ingest()`)
  - `tests/Feature/ControlHub/ControlHubContractIngestionTest.php` (cas « label_name orphelin → rollback » + « label_name cohérent/nul » si non couverts par le test dédié)
  - `docs/qa/domains/controlhub-contract.md` (Section 11 append) + `docs/qa/README.md` (libellé domaine)
  - `_bmad-output/implementation-artifacts/sprint-status.yaml` (epic-30 + 30-1 ajoutés)
- **Inchangés (à PROUVER)** : migration/modèles/enum 28.1 (labels & groupes imposés — **aucune** modif), `StateCompiler`, contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1`. **Racine = projet Laravel**. [Source: mémoire projet — root_is_laravel]

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 30.1 (L.242-252)] — AC d'origine (réception + persistance labels nom+mode libre/réservé ; groupes imposés nom+label associé).
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md L.69-73] — FR9 (réception, 30.1) vs FR10 (mapping, 30.2) vs FR11 (existence, 30.3) vs FR12 (résolution, 30.4) vs FR13 (validation à l'assignation, 30.5) : bornes de scope.
- [Source: database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php L.108-162] — tables `controlhub_contract_labels` (`mode`) et `controlhub_contract_imposed_groups` (`label_name` nullable, pas de FK).
- [Source: app/Models/ControlHubContractLabel.php ; app/Models/ControlHubContractImposedGroup.php ; app/Enums/ControlHubLabelMode.php] — modèles + enum free/reserved (28.1).
- [Source: app/Services/ControlHub/ControlHubContractIngestionService.php L.75-157 (ingest), L.265-333 (normalizeItems — patron validation), L.341-398 (normalizeLabels/normalizeImposedGroups)] — ingestion idempotente + point d'insertion du durcissement.
- [Source: app/Services/ControlHub/Data/ContractIngestionResult.php] — compteurs labels/imposedGroups + `mutated` (no-op NFR4).
- [Source: tests/Feature/ControlHub/ControlHubContractIngestionTest.php L.50-69, L.270-306, L.335-354] — harnais payload + patron rejet/rollback + prune.
- [Source: tests/Unit/Models/ControlHubContractTest.php] — couverture schéma/casts/clés naturelles (28.1).
- [Source: _bmad-output/implementation-artifacts/29-5-drift-strict-et-audit-des-overrides.md] — patron « story à deux moitiés : preuve d'existant + construction bornée ».
- [Source: mémoires projet — phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement, root_is_laravel, understand_business_before_design, no_overengineered_choices, zero_prod_publish_is_test, no_legacy_transition_state, vm_migrations_not_auto_applied].

## Dépendances

- **Amont (consommées — code livré sur la branche `worktree-contract-CH`)** :
  - **Story 28.1** (modèle + migration du contrat amont : tables `controlhub_contract_labels`/`controlhub_contract_imposed_groups`, enum `ControlHubLabelMode`, modèles). **Dépendance de fondation FR9** : 30.1 **prouve** la persistance livrée par 28.1, ne la réimplémente pas. **Statut : `review`** (⚠️ PAS `done`).
  - **Story 28.2** (ingestion idempotente `ControlHubContractIngestionService` : `normalizeLabels`/`normalizeImposedGroups`/`reconcileChildren`). **Dépendance dure** : c'est le service que 30.1 prouve et durcit. **Statut : `review`** (⚠️ PAS `done`).
  - **Epic 28** (ensemble du modèle `ControlHubContract*` + enums + factories + `ControlHubContractIngestionTest`). **Dépendance dure** (preuve + harnais de test). **Statut epic : `in-progress`** ; 28.1/28.2/28.3 toutes `review`.
- **Prérequis fourni à (aval)** :
  - **Story 30.2** (mapping label→`WorkstationGroup`), **30.3** (garantie d'existence des groupes imposés), **30.4** (résolution d'un item ciblant un label) — consommeront le vocabulaire reçu (labels + groupes imposés) dont 30.1 garantit la cohérence.
- **⚠️ Alerte statut** : **aucune** dépendance n'est `done`. 28.1 et 28.2 (dépendances dures) sont en **`review`** ; leur **code est livré** sur la branche courante `worktree-contract-CH` → 30.1 peut démarrer. **Re-synchroniser** si une correction de review de 28.1/28.2 modifie la migration des labels/groupes imposés, `ControlHubContractIngestionService` (signature/normalisation) ou le harnais `ControlHubContractIngestionTest`.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, trait `RefreshDatabase`. **Jamais la VM.** [Source: mémoire projet — phpunit_test_env_host_vs_vm]
- Filtres ciblés : `php artisan test --filter "UpstreamLabelsImposedGroupsReception|ControlHubContractIngestion|ControlHubContract|UpstreamContractResolution|ContractV1"`.
- Couverture obligatoire :
  - **Preuve réception FR9** : labels persistés avec `mode` casté `ControlHubLabelMode` (libre/réservé) ; groupes imposés persistés avec `label_name` associé/`null` ; idempotence no-op (2ᵉ réception → `mutated=false`, aucun event) ; upsert/prune avec compteurs `result.labels`/`result.imposedGroups` exacts.
  - **Durcissement** : `label_name` cohérent → succès (AC#4) ; `label_name` orphelin → `InvalidUpstreamContractException` + `assertDatabaseCount` 0 sur labels+groupes imposés (rollback total, AC#5) ; `label_name` nul/`''`/absent → succès `label_name=null` (AC#6) ; standalone/sans groupes imposés → garde-fou inerte (AC#7).
  - **Non-régression** : `ControlHubContractIngestionTest` (10 cas), `ControlHubContractTest`, suites 28.3/29.x, `ContractV1Test` → 0 régression ; golden/`FROZEN_STATE_HASH`/`ContractV1` → 0 changement.
- **Pièges** : SQLite n'applique pas varchar/enum PG → tester contenus/décisions (valeurs castées, rejet), pas bornes ; vérifier que le payload de référence 28.2 reste cohérent sous le nouveau garde-fou (labels `salle-info`/`labo` déclarés) ; placer le cross-check **avant** la transaction (sinon écriture partielle).
- ✅ **VM** : 30.1 n'ajoute **aucune** migration → rien à jouer en VM (contrairement à 29.5).

## Recommandation Modèle Dev

**`opus`.**

Justification : la difficulté de 30.1 n'est **pas** volumétrique mais de **jugement de cadrage** — exactement le profil pour lequel 29.5 a été routée opus. (1) La moitié FR9 exige de **reconnaître qu'il ne faut RIEN construire** (labels et groupes imposés sont déjà reçus/persistés depuis 28.1/28.2) et de **résister** à la tentation de créer une table/migration/modèle de labels qui ferait doublon et régresserait le cadrage Epic 28. (2) La moitié durcissement exige de **borner précisément** un garde-fou de réception sans **empiéter** sur 30.2 (mapping local), 30.3 (création de groupes), 30.4 (résolution `target_label`) ni 30.5 (validation à l'assignation), de choisir la **règle minimale** (label déclaré, pas `reserved` spéculatif), de **ne pas réintroduire de FK dure** (refusée en 28.1), et de le placer dans la **bonne couche** (avant transaction, patron `InvalidUpstreamContractException` de 28.2). Ces décisions touchent à l'intégrité d'un modèle de données contractuel et à des frontières de scope inter-stories, cohérentes avec le routage **opus** d'Epic 28/29. Le dev-cycle routera la review vers le modèle opposé (sonnet) pour vérifier les angles morts (doublon de schéma créé par mégarde, durcissement débordant sur les items/le mapping, FK dure réintroduite, validation placée dans la transaction, sur-contrainte `reserved`).

## Dev Agent Record

### Agent Model Used

opus `claude-opus-4-8[1m]`

### Debug Log References

- `php -l app/Services/ControlHub/ControlHubContractIngestionService.php` → No syntax errors detected.
- `php artisan test --filter "UpstreamLabelsImposedGroupsReception|ControlHubContractIngestion|ControlHubContract|UpstreamContractResolution|ContractV1"` → **76 passed (506 assertions)**, 0 régression.
- `grep -rin "central"` sur service + test ajouté → uniquement les commentaires garde-fou R3 (0 identifiant/libellé).

### Completion Notes List

- **Moitié FR9 (preuve, AUCUNE construction)** : ajout du test Feature dédié `UpstreamLabelsImposedGroupsReceptionTest` verrouillant la réception/persistance livrée par 28.1/28.2 — labels relus **via le modèle** (`mode` casté `ControlHubLabelMode` Free/Reserved, pas seulement `assertDatabaseHas`), groupes imposés avec `label_name` associé/`null`, idempotence no-op (`mutated=false` + aucun `ControlHubContractChanged`), upsert/prune avec compteurs `result.labels`/`result.imposedGroups` exacts. **Aucune** table/modèle/enum/migration créée (vérifié `git status`).
- **Moitié durcissement (SEULE construction)** : méthode privée `assertImposedGroupLabelsDeclared(array $labels, array $imposedGroups): void` ajoutée à `ControlHubContractIngestionService`, appelée dans `ingest()` **après** `normalizeLabels()`/`normalizeImposedGroups()` et **AVANT** `DB::transaction` (calque exact du patron cohérence-cible de `normalizeItems()`). Pour chaque groupe imposé à `label_name` **non-nul** absent de l'ensemble des labels déclarés, lève `InvalidUpstreamContractException::for("imposed_groups.label_name ({$groupName})", "label associé « {$labelName} » non déclaré dans le contrat")` → rollback total, aucune écriture partielle.
- **Règle minimale respectée** : seul le « label déclaré » est exigé ; **pas** d'exigence `reserved` (sur-contrainte spéculative écartée — l'enum dit « *typiquement* »). **Pas** de FK dure (refusée 28.1). `label_name` nul/`''`/absent reste légitime (non contraint). Lecture de `attrs.label_name` (déjà normalisé `'' → null` par `normalizeImposedGroups`) → la chaîne vide est traitée comme « sans label ».
- **Frontières inter-stories respectées** : aucun mapping local (30.2), aucune création de groupe (30.3), `StateCompiler` intact + intégrité `items[].target_label` hors scope (30.4), aucune validation prédictive à l'assignation (30.5). Back-end pur : aucune UI/route/modale/Livewire.
- **Non-régression** : payload de référence 28.2 (`parc-terminales → salle-info`, et version « changed » `→ labo`) reste cohérent sous le nouveau garde-fou ; `ControlHubContractIngestionTest` (10 cas), `ControlHubContractTest`, `UpstreamContractResolutionTest`, `ContractV1` → tous verts. Golden / `FROZEN_STATE_HASH` / `ContractV1` inchangés.

### File List

**Modifiés** :
- `app/Services/ControlHub/ControlHubContractIngestionService.php` (méthode `assertImposedGroupLabelsDeclared()` + appel dans `ingest()` avant transaction)
- `docs/qa/domains/controlhub-contract.md` (Section 11 append + checklist rapide)
- `docs/qa/README.md` (libellé du domaine controlhub-contract enrichi mention 30.1)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (30-1 → review + last_updated)
- `_bmad-output/implementation-artifacts/30-1-reception-labels-et-groupes-imposes.md` (Tasks/Subtasks, Dev Agent Record, Status)

**Nouveaux** :
- `tests/Feature/ControlHub/UpstreamLabelsImposedGroupsReceptionTest.php` (10 tests : preuve FR9 #1-#3, durcissement #4-#6, garde inerte #7)

**Inchangés (prouvés)** : migration/modèles/enum 28.1 (labels & groupes imposés — aucune modif), `StateCompiler`, contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1`.

## Change Log

- 2026-06-27 — Story 30.1 implémentée (Dev BMAD opus `claude-opus-4-8[1m]`) → review. FR9 prouvée par `UpstreamLabelsImposedGroupsReceptionTest` (aucune construction de schéma) ; durcissement réception = garde-fou `assertImposedGroupLabelsDeclared()` refusant un `imposed_groups[].label_name` non-nul orphelin avant transaction (rollback total). 76 tests ciblés verts (506 assertions), 0 régression. Aucune migration/modèle/enum. Runbook QA Section 11 + README.
