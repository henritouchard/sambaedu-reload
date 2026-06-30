# Story 33.2: Négociation et rejet gracieux d'une version incompatible

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **rejeter proprement, à l'ingestion, un contrat amont dont la `schema_version` déclarée est incompatible** : la négociation devient **stricte** (une version déclarée non supportée n'est plus tolérée par repli), le payload est **rejeté sans modifier l'état existant**, et l'**incompatibilité est tracée** (« version reçue vs versions supportées »),
so that **une évolution non coordonnée du format côté amont (controlHub) ne corrompe pas l'état local et que l'écart soit immédiatement diagnostiquable (R2)**.

> Story **2/2** de l'Epic 33 (« Contrat de données d'intégration controlHub↔SE5 »). La 33.1 a livré la **partie heureuse** (conforme/absent **acceptés**, version enregistrée, artefact partagé) **et a posé le seam** de rejet. Cette story **ferme** l'Epic 33 : elle **remplace le repli tolérant** d'une version déclarée non supportée par un **rejet gracieux** (exception dédiée, état inchangé, trace de l'écart). On **ne touche pas** au chemin heureux (absent → courant, conforme → accepté) : on ne durcit **que** la branche « version **déclarée** non supportée ».

> **Contexte d'ancrage (Story 33.1 — review, branche unifiée)** : le point d'extension `ControlHubContractSchema::negotiate(?string): string` **existe déjà**. Aujourd'hui, une version **déclarée non supportée** retombe sur `CURRENT_VERSION` avec un `Log::warning(... 'seam Story 33.2' ...)` (lignes ~80-94). **C'est ce repli que 33.2 substitue par un rejet.** On **étend** la couture 33.1 ; on ne réinvente ni l'ingestion (28.2) ni le référentiel de version (33.1).

## Acceptance Criteria

1. **Given** un payload de contrat amont déclarant une `schema_version` **non supportée** (≠ `CURRENT_VERSION`, hors `SUPPORTED_VERSIONS`), **When** l'ingestion s'exécute, **Then** le payload est **rejeté** (l'ingestion lève une exception **dédiée** et ne retourne **pas** de `ContractIngestionResult`), **et aucune écriture n'a lieu** : aucun nouveau `ControlHubContract`, aucun enfant `controlhub_contract_*` créé/modifié/supprimé, **aucun** événement `ControlHubContractChanged` émis.

2. **Given** un contrat **déjà ingéré** (état existant non vide), **When** un payload portant une version **non supportée** est reçu, **Then** l'**état persisté est strictement inchangé** : `schema_version`, `received_at`, `link_state` du contrat actif et la totalité des agrégats enfants sont **identiques avant/après** la tentative rejetée (le rejet survient en **phase de validation pure**, **avant** `DB::transaction` ⇒ rollback total trivial, comme `InvalidUpstreamContractException`).

3. **Given** le rejet, **When** on inspecte la trace, **Then** l'**incompatibilité est tracée** en nommant **la version reçue** et **les versions supportées** : (a) le **message** de l'exception dédiée porte « reçue ‹X› vs supportées ‹…› », **et** (b) un **log structuré** (champs `declared` + `supported`) est émis au moment du rejet. _[Niveau de log + éventuelle persistance audit = Q3.]_

4. **Given** le chemin heureux 33.1, **When** un payload **sans** `schema_version` (absent/`''`) **ou** portant une version **supportée** est reçu, **Then** le comportement est **inchangé** : absent → défaut `CURRENT_VERSION` (rétro-compat 28.2, Q1=A), supporté → accepté et enregistré exactement comme en 33.1. **Seule** la branche « version **déclarée** non supportée » bascule du repli au rejet. **Tous** les tests 33.1 (`ControlHubContractSchemaVersion`) et 28.2 (`ControlHubContractIngestion`) **restent verts**.

5. **Given** le besoin de distinguer les causes de refus, **When** un payload est rejeté **pour incompatibilité de version**, **Then** l'exception est d'un **type dédié** (ex. `UnsupportedSchemaVersionException`) **distinct** de `InvalidUpstreamContractException` (rejet de **contenu** : enum/cohérence/intégrité), afin qu'un appelant futur puisse différencier « format de version incompatible » de « payload hors domaine ». Les deux partagent le **patron** : levée **avant** la transaction, rollback total, vocabulaire R3.

6. **Given** la politique de compatibilité, **When** on lit le code et l'artefact partagé, **Then** la décision Q1 est **explicite et appliquée** : en l'absence d'une 2ᵉ version réelle, `isSupported()` reste en **égalité stricte** (`1.0` seul) — la **compat MAJOR** (`1.x`) n'est **pas** implémentée spéculativement (anti sur-engineering) ; l'artefact partagé `schema-echange-controlhub-se5.md` est mis à jour : le rejet gracieux passe de « livré par 33.2 » à **décrit comme livré** (politique stricte + forme de la trace).

7. **Given** le code livré, **When** on l'inspecte, **Then** (a) l'ingestion reste le **seul** chemin écrivant `controlhub_contract_*` (NFR3) et **sans** contrat reçu le comportement SE5 est **strictement inchangé** ; (b) le **contrat agent figé** est intact — `app/Services/Agent/StateCompiler.php`, `StateContract`/`ContractV1`, golden tests, `FROZEN_STATE_HASH`, `agent/**` **non touchés** (versionnement du schéma d'échange **serveur-only**, invisible de l'agent ; **pas** de bump `agent/shared/version.go`) ; (c) **aucun** identifiant/classe/méthode/propriété/constante/message livré ne contient le mot **« central »** (R3) ; (d) **aucune** route n'est ajoutée : `ControlHubContractIngestionService::ingest()` n'est appelé par **aucun** contrôleur HTTP aujourd'hui — le rejet reste **purement service** (Q4) ; (e) **aucune** migration n'est requise (rejet = logique pure, zéro changement de schéma).

## Tasks / Subtasks

- [x] **Task 0 — Questions Henri (à TRANCHER en T0, avant implémentation)** — TRANCHÉES (T0, Henri)
  - [x] **Q1** = ÉGALITÉ STRICTE (`1.0` seul) ; compat MAJOR **différée**.
  - [x] **Q2** = exception dédiée `UnsupportedSchemaVersionException` levée **DANS `negotiate()`**, propagée par `ingest()`.
  - [x] **Q3** = `warning` + log structuré `{declared, supported}` ; **PAS** de table audit.
  - [x] **Q4** = purement service ; **aucune** route, **aucun** mapping HTTP.

- [x] **Task 1 — Exception dédiée `UnsupportedSchemaVersionException`** (AC: #1, #5, #7c) _(Q2)_
  - [x] Créé `app/Exceptions/ControlHub/UnsupportedSchemaVersionException.php` (`final … extends RuntimeException`, `declare(strict_types=1)`), distincte d'`InvalidUpstreamContractException`. Fabrique `::for(string $declared, array $supported): self` ; message FR « Version de schéma d'échange amont incompatible — reçue « {declared} » ; supportées : {liste} ».
  - [x] Données structurées exposées : propriétés `readonly $declared`/`$supported` + getters `declared()`/`supported()`.
  - [x] PHPDoc : cause (version déclarée non supportée), levée avant transaction, distinction AC #5, garde-fou R3.

- [x] **Task 2 — Rejet strict dans `negotiate()`** (AC: #1, #3, #4, #6) _(Q1=égalité stricte, Q2)_
  - [x] Repli remplacé par log structuré `{declared, supported}` (`warning`) puis `throw UnsupportedSchemaVersionException::for(...)`. Branches `null`/`''` → `CURRENT_VERSION` et `isSupported()` → `$declared` inchangées (AC #4).
  - [x] `isSupported()` reste en égalité stricte. PHPDoc de classe MAJ (« compat MAJOR différée tant qu'une seule version existe ») + PHPDoc `negotiate()` (rejet désormais livré). R3 préservé.
  - [x] `@throws UnsupportedSchemaVersionException` ajouté au PHPDoc de `negotiate()`.

- [x] **Task 3 — Propagation par l'ingestion (zéro écriture)** (AC: #1, #2, #7a)
  - [x] `negotiate()` appelé en validation pure AVANT `DB::transaction` : l'exception se propage sans try/catch ⇒ zéro écriture, état inchangé.
  - [x] PHPDoc de `ingest()` : `@throws UnsupportedSchemaVersionException` ajouté ; PHPDoc de classe et commentaire inline MAJ (mention « réservé Story 33.2 » levée — rejet livré).
  - [x] Aucun try/catch ajouté ; négociation laissée hors transaction.

- [x] **Task 4 — Trace de l'incompatibilité** (AC: #3) _(Q3)_
  - [x] Log structuré `warning` au point de rejet : message identifiable + `['declared' => $declared, 'supported' => self::SUPPORTED_VERSIONS]`.
  - [x] Q3 = log seul (pas de table audit).

- [x] **Task 5 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#7)
  - [x] Créé `tests/Feature/ControlHub/UnsupportedSchemaVersionRejectionTest.php` (8 tests, 49 assertions, verts).
  - [x] `test_unsupported_declared_version_is_rejected` (AC #1).
  - [x] `test_rejection_writes_nothing` (AC #1/#2) : comptes des 5 tables + `assertNotDispatched`.
  - [x] `test_rejection_leaves_existing_contract_unchanged` (AC #2) : contrat pré-existant inchangé.
  - [x] `test_exception_message_names_received_and_supported` + `test_rejection_is_logged_with_declared_and_supported` (AC #3).
  - [x] `test_dedicated_type_distinct_from_invalid_contract` (AC #5).
  - [x] `test_happy_path_unchanged` (AC #4).
  - [x] `test_r3_no_central_identifier` (AC #7c). Test 33.1 `test_unsupported_version_falls_back_to_current_and_is_logged` réécrit en `test_unsupported_version_is_rejected_and_logged` (le repli n'existe plus).
  - [x] **Non-régression** verte : `--filter 'ControlHubContractSchemaVersion|ControlHubContractIngestion|ControlHubContract|ContractV1|StateCompiler'` → 96/96 ; aucun payload 33.1/28.2 ne bascule en rejet.

- [x] **Task 6 — Doc QA + artefact partagé** (AC: #6)
  - [x] `docs/qa/domains/controlhub-contract.md` : Section 20 + checklist rapide 33.2 ajoutées (append-only, Section 19 préservée).
  - [x] `_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md` : §1 (politique stricte, MAJOR différée) + en-tête (périmètre 33.2) + note finale (rejet livré, Epic 33 clos) MAJ.

- [x] **Task 7 — Validation finale & garde-fous**
  - [x] `--filter UnsupportedSchemaVersionRejection` → 8/8 verts.
  - [x] Non-régression `…|Capability` → 75/75 verts (0 régression).
  - [x] `grep -rin central` sur les fichiers livrés → 0 identifiant (uniquement commentaires R3).
  - [x] `git status … agent/golden/StateCompiler/ContractV1/version.go/FROZEN_STATE_HASH` → vide (agent figé intact).
  - [x] Confirmé : `ControlHubContractIngestionService::ingest()` appelé par aucun contrôleur HTTP (Q4 service-only).
  - [ ] **VM (différé, hors dev-cycle)** : scénario QA 20.2 (tinker rejet) à dérouler hors dev-cycle sur `/vm` — aucune migration, seul le comportement de rejet à confirmer.

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 33.2

**DANS** : exception dédiée `UnsupportedSchemaVersionException` (patron `InvalidUpstreamContractException`) ; **bascule du repli au rejet** dans `ControlHubContractSchema::negotiate()` pour une version **déclarée non supportée** ; propagation par `ingest()` en phase de validation pure (zéro écriture) ; **trace** de l'écart (message + log structuré) ; mise à jour de l'artefact partagé + doc QA ; tests HÔTE. **Clôt l'Epic 33.**

**HORS** (ne pas déborder) :
- **Compat MAJOR / négociation multi-versions réelle** : tant qu'une seule version existe, on **n'implémente pas** `1.x` (Q1=reco). Ajouter une 2ᵉ version + sa compat sera une story future si le besoin se matérialise. Ne **pas** fabriquer une 2ᵉ version factice.
- **Migration de schéma applicatif** (transformer un ancien payload vers le nouveau format) : non spécifié, hors scope.
- **Endpoint/transport HTTP** : `ingest(array $payload)` reste le livrable stable (comme 28.2/33.1). Aucune route, aucun mapping `4xx` (Q4=service-only ; vérifié par grep). Le jour où un transport est câblé, il traduira l'exception.
- **Modifier `StateCompiler`/`StateMaille`/contrat agent** : le versionnement d'échange est **serveur-only**, invisible de l'agent. **Pas** de bump `agent/shared/version.go`.
- **Persistance audit** : par défaut **non** (log seul). Uniquement si Henri tranche Q3 en ce sens.

### Code réel — seam EXACT à modifier (réutiliser, ne pas réinventer)

- **`app/Services/ControlHub/ControlHubContractSchema.php`** (33.1) — **cœur de la story** :
  - `negotiate(?string $declared): string`, lignes ~69-95. Branches à **préserver** : `null`/`''` → `CURRENT_VERSION` (~71-74) ; `isSupported($declared)` → `$declared` (~76-78). **Branche à remplacer** : lignes ~80-94 (`Log::warning(... 'seam Story 33.2' ...)` + `return self::CURRENT_VERSION;`) → log structuré + `throw UnsupportedSchemaVersionException::for($declared, self::SUPPORTED_VERSIONS);`.
  - `CURRENT_VERSION = '1.0'` (~44), `SUPPORTED_VERSIONS = [self::CURRENT_VERSION]` (~54), `isSupported()` égalité stricte `in_array(..., true)` (~101-104) — **inchangés** (Q1=reco).
- **`app/Services/ControlHub/ControlHubContractIngestionService.php`** (28.2/33.1) : appel `negotiate()` en **ligne ~113**, **avant** `DB::transaction` (ligne ~126). C'est le **patron exact** de `normalizeItems()`/`InvalidUpstreamContractException` : validation pure pré-transaction ⇒ une levée garantit **zéro écriture** (rollback total trivial, AC #2). **Ne rien ajouter dans la transaction.** PHPDoc `@throws` à compléter (ligne ~86) ; mention « réservé Story 33.2 » à lever (~30-31).
- **`app/Exceptions/ControlHub/InvalidUpstreamContractException.php`** (28.2) : **modèle** de la nouvelle exception (`final`, `extends RuntimeException`, `::for(...)`, garde-fou R3). La nouvelle exception doit être **distincte** (AC #5) — ne **pas** sous-classer ni étendre `InvalidUpstreamContractException`.
- **`app/Services/ControlHub/Data/ContractIngestionResult.php`** (33.1) : **inchangé** (un rejet ne retourne pas de DTO).
- **`tests/Feature/ControlHub/ControlHubContractSchemaVersionTest.php`** (33.1) : patron des tests de version (payloads tableaux, `Event::fake()`, comptage no-op). Le test 33.1 `test_negotiate_resolves_conforming_and_absent` couvre le seam — vérifier qu'il **reste vert** (il n'exerce pas la branche non supportée).
- **`tests/Feature/ControlHub/ControlHubContractIngestionTest.php`** (28.2) : non-régression — ses payloads ne déclarent jamais une version non supportée.

### Le piège central de cette story (régression silencieuse du chemin heureux)

Le risque **n°1** n'est pas le rejet lui-même (mécaniquement simple) mais de **basculer par erreur une réception légitime en rejet** :
1. **Version absente** (`null`/`''`) **doit rester acceptée** (défaut `CURRENT_VERSION`, Q1=A, rétro-compat 28.2). Ne **jamais** rejeter une version absente — seules les versions **déclarées** non supportées sont rejetées. C'est la frontière explicite posée par 33.1.
2. **Version supportée** (`1.0`) **doit rester acceptée**. Ne pas durcir `isSupported()`.
3. Toutes les suites 33.1/28.2 doivent **rester vertes** : leurs payloads ne portent jamais une version non supportée ⇒ aucun ne doit changer de verdict. **Vérifier** (Task 5 / Task 7) — une régression ici serait invisible sans non-régression ciblée.
4. **Cast robuste** : `ingest()` caste déjà `$payload['schema_version']` en `?string` (lignes ~109-112 : `is_string||is_int ? (string) : null`). Une `schema_version` non scalaire (tableau/objet) devient `null` ⇒ **défaut**, pas rejet. C'est cohérent (on ne rejette que sur une version **déclarée en chaîne** non supportée). Ne pas changer ce cast.

### Garde-fous projet CRITIQUES

- **R3 — Vocabulaire (BLOQUANT)** : **INTERDIT** — `central` dans tout nom de classe/méthode/propriété/constante/colonne/message/commentaire d'identifiant. Vocabulaire : « amont »/`upstream`/`authority`/`ControlHub*`. [mémoires `project_contrat_manage_se5_upstream`, `legacy_central_vs_local_split` ; prd#R3]
- **Contrat agent figé (BLOQUANT)** : `StateCompiler`, `StateContract`/`ContractV1`, golden tests, `FROZEN_STATE_HASH`, `agent/**` **intacts** ; versionnement d'échange **serveur-only**, invisible de l'agent ; **pas** de bump `agent/shared/version.go`. [mémoires `feedback_agent_edit_bump_version` (a contrario : on ne touche pas l'agent), `project_epic23_e2e_validated`]
- **NFR3 — Standalone préservé** : sans contrat reçu, comportement SE5 **strictement inchangé** ; ingestion = seul écrivain ; aucun chemin de lecture modifié. [prd#NFR3]
- **NFR4 — Idempotence non régressée** : le rejet **précède** toute écriture ; le no-op/mutation de 33.1 reste intact pour les payloads acceptés. [prd#NFR4]
- **Pas de migration** : le rejet est de la **logique pure** ; aucune colonne, aucun schéma. NFR7 sans objet ici.
- **Routes/transport** : aucune route ajoutée (Q4=service-only). Si — et seulement si — un endpoint devait apparaître, le garder minimal et **après** le groupe 16.12 de `routes/api.php`. [mémoire `api_routes_arch_test_window_trap`] — *a priori non requis.*
- **Pas de choix sur-conçu** : compat MAJOR et table d'audit sont des extensions **différées** tant que le besoin réel n'existe pas (une seule version, un rejet ne mute rien). [mémoire `feedback_no_overengineered_choices`]

### Distinctions à NE PAS confondre (homonymies)

- **Version de schéma d'ÉCHANGE** (cette story : `schema_version` racine du payload controlHub→SE5) ≠ **`ContractV1`/version du contrat AGENT** (`app/Services/Agent/`, desired-state émis vers l'agent, figé/golden). Deux versionnements distincts ; ne **jamais** coupler.
- **Rejet de VERSION** (`UnsupportedSchemaVersionException`, cette story) ≠ **rejet de CONTENU** (`InvalidUpstreamContractException`, 28.2 : enum/cohérence-cible/intégrité `label_name`). Types **distincts** (AC #5), même patron (levée pré-transaction).
- **Indisponibilité amont** (32.2 : silence, dernier contrat maintenu) ≠ **version incompatible** (cette story : payload reçu mais format incompatible → rejeté). Un rejet **ne libère pas** les verrous (l'état existant est préservé, AC #2).

### Project Structure Notes

- **Nouveaux fichiers** : `app/Exceptions/ControlHub/UnsupportedSchemaVersionException.php` ; `tests/Feature/ControlHub/UnsupportedSchemaVersionRejectionTest.php`.
- **Édits ciblés** : `ControlHubContractSchema.php` (repli → rejet + PHPDoc) ; `ControlHubContractIngestionService.php` (PHPDoc `@throws` + mention levée) ; `schema-echange-controlhub-se5.md` (§1/§6 : rejet livré) ; `docs/qa/domains/controlhub-contract.md` (Section 20 append-only) ; cocher cette story + `sprint-status.yaml`.
- **Aucune** migration, **aucun** modèle, **aucun** DTO modifié.
- **Racine = projet Laravel** (artisan/app à la racine). [mémoire `root_is_laravel`]
- **Tests HÔTE** (php8.4 + `pdo_sqlite`), jamais la VM. [mémoire `phpunit_test_env_host_vs_vm`]

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 33.2] — AC d'origine (version non supportée → rejet sans modifier l'état + incompatibilité tracée « reçue vs supportées »).
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#R2, #R3] — désync entre BMAD (R2) ; garde-fou « zéro central » (R3).
- [Source: _bmad-output/implementation-artifacts/33-1-schema-echange-versionne.md] — seam `negotiate()` posé, colonne `schema_version`, patron de tests, garde-fous (R3/NFR3/contrat agent figé).
- [Source: app/Services/ControlHub/ControlHubContractSchema.php#negotiate] — repli lignes ~80-94 à substituer par le rejet ; `isSupported()` égalité stricte ~101-104.
- [Source: app/Services/ControlHub/ControlHubContractIngestionService.php] — `negotiate()` ligne ~113, **avant** `DB::transaction` ligne ~126 ⇒ rejet en validation pure (zéro écriture).
- [Source: app/Exceptions/ControlHub/InvalidUpstreamContractException.php] — patron de l'exception de domaine (`::for()`, R3) ; la nouvelle exception en est **distincte**.
- [Source: _bmad-output/planning-artifacts/schema-echange-controlhub-se5.md#1, #6] — politique de compat + note finale « rejet livré par 33.2 » à mettre à jour.

## Questions pour Henri

> À trancher en **T0** (avant implémentation). Recommandations entre crochets — toutes vont dans le sens « minimal et dérivable, pas de sur-engineering ».

- **Q1 — Compat MAJOR maintenant, ou égalité stricte + report ?**
  Implémenter dès 33.2 l'acceptation de toute `1.x` (compat sur le MAJOR), ou **rester en égalité stricte** (`1.0` seul) et différer la compat MAJOR jusqu'à l'existence d'une 2ᵉ version réelle ?
  **[reco : ÉGALITÉ STRICTE.** Une seule version existe ; coder une négociation MAJOR sans 2ᵉ version à confronter est spéculatif et non testable de bout en bout. La couture `SUPPORTED_VERSIONS`/`isSupported()` reste le point d'extension propre le jour venu.]**

- **Q2 — Forme du rejet : où lever l'exception ?**
  Exception **dédiée** `UnsupportedSchemaVersionException` levée **dans `negotiate()`** (qui devient le point de décision unique conforme/absent/**rejeté**), ou levée plus haut dans `ingest()` après un retour signal de `negotiate()` ?
  **[reco : EXCEPTION DÉDIÉE LEVÉE DANS `negotiate()`.** C'est exactement le seam balisé par 33.1 ; `ingest()` la propage sans `try/catch`, comme il propage déjà `InvalidUpstreamContractException`. Cohérent, minimal, testable au niveau du référentiel **et** du service.]**

- **Q3 — Trace : niveau de log + persistance ?**
  Niveau `warning` ou `error` ? Et faut-il **persister** la trace (réutiliser `controlhub_link_audit_logs` de 32.1) ou un **log structuré** suffit-il ?
  **[reco : `warning` + LOG STRUCTURÉ `{declared, supported}`, PAS de table.** Un rejet est une condition **attendue et gérée** (pas une panne), donc `warning` plutôt qu'`error`. Un rejet **ne mute pas** l'état : persister une ligne d'audit serait du sur-engineering et détonnerait avec la table 32.1 (dédiée aux transitions de lien). À revoir seulement si tu veux une preuve durable côté exploitant.]**

- **Q4 — Réponse HTTP (4xx) ou purement service ?**
  L'ingestion est-elle (ou sera-t-elle bientôt) exposée via une route, auquel cas mapper le rejet en `4xx` (422/409) ? Ou le rejet reste-t-il **purement service** ?
  **[reco : PUREMENT SERVICE.** Vérifié par grep : `ControlHubContractIngestionService::ingest()` n'est appelé par aucun contrôleur HTTP (seuls `WpkgReportController` et l'`Agent/ReportController` appellent d'**autres** services `ingest`). Comme 28.2/33.1, le livrable stable est le **service**. Le jour où un transport est câblé, il traduira l'exception en `4xx` — hors scope ici.]**

## Dépendances

- **Amont (toutes done/branche unifiée)** :
  - **28.1 / 28.2** — schéma `controlhub_contract_*`, ingestion idempotente (patron validation-pure-pré-transaction, exception de domaine).
  - **33.1** (review) — référentiel `ControlHubContractSchema` + seam `negotiate()` + colonne `schema_version` + artefact partagé. **33.2 prolonge ce seam.** ⚠️ Si la review 33.1 modifie la signature de `negotiate()` ou le branchement dans `ingest()`, **rebaser** cette story.
- **Aval** : aucune (33.2 **clôt** l'Epic 33). Une éventuelle 2ᵉ version + compat MAJOR serait une story future, déclenchée par un besoin réel.
- **Coordination R2 (autre BMAD)** : l'artefact partagé mis à jour (rejet livré) reste la source unique ; le handoff controlHub §7 (worktree E30) doit y pointer — **consigné**, hors édition ici.

## Testing

- **Cible : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, `RefreshDatabase`, `CACHE_DRIVER=array`. Jamais la VM. [mémoires `phpunit_test_env_host_vs_vm`, `apcu_cache_no_lock`]
- Filtre ciblé : `--filter UnsupportedSchemaVersionRejection`. Non-régression : `--filter 'ControlHubContractSchemaVersion|ControlHubContractIngestion|ControlHubContract|ContractV1|StateCompiler'`.
- Couverture : version non supportée → exception + **zéro écriture** + **état inchangé** (AC #1/#2), trace nommant reçue vs supportées (AC #3), chemin heureux intact (AC #4), type **distinct** d'`InvalidUpstreamContractException` (AC #5), R3 (AC #7c).
- **Piège** : prouver le « rien écrit » par **comptage des 5 tables avant/après** + `Event::fake()`/`assertNotDispatched`, et le « état inchangé » en comparant un contrat **pré-existant** avant/après la tentative. Vérifier en priorité que **toutes** les suites 33.1/28.2 restent vertes (frontière absent/supporté/non-supporté).
- ⚠️ **VM (différé)** : aucune migration ⇒ rien à `migrate` ; seul le **comportement de rejet** est à confirmer via tinker (Scénario QA 20). [mémoire `vm_migrations_not_auto_applied`]

## Recommandation Modèle Dev

**`opus`.**

Justification : la **mécanique** du rejet est simple (remplacer un repli par un `throw`), mais le **risque réel** est ailleurs et il est subtil :
1. **Couture 33.1↔33.2 sans casser la frontière** : la valeur de la story est de durcir **uniquement** la branche « version déclarée non supportée » en laissant **strictement intacts** absent→courant (Q1=A) et supporté→accepté. La régression silencieuse (basculer une réception légitime en rejet) est facile à introduire et invisible sans non-régression ciblée — exactement le profil où opus paie.
2. **Idempotence / zéro-écriture à prouver** : garantir que le rejet précède toute écriture (validation pure pré-transaction) et le **démontrer** par des tests de comptage + état pré-existant inchangé — raisonnement fin sur le placement (`negotiate()` ligne ~113 vs `DB::transaction` ligne ~126) et sur le no-op 28.2/33.1 à ne pas perturber.
3. **Discipline de typage des exceptions (AC #5)** + **trace** + **garde-fous transverses simultanés** (R3, contrat agent figé, NFR3, anti sur-engineering Q1/Q3) + **mise à jour cross-BMAD** de l'artefact partagé.
4. C'est la **story de clôture** d'un epic d'intégration sensible (R2) : mieux vaut le jugement le plus solide sur la couture et les effets de bord.

Le dev-cycle routera la review vers le **modèle opposé** (sonnet/fable) ; placer **opus** sur l'implémentation met le raisonnement là où le risque (frontière du chemin heureux, zéro-écriture, typage d'exception) est le plus élevé. _(Reco alignée sur 33.1, déjà développée en opus.)_

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story, exécution directe des workflows BMAD).

### Debug Log References

- Coquille de parsing PHP initiale : la séquence `**absente**/` dans le PHPDoc de l'exception
  contenait `*/` qui fermait le docblock prématurément (`ParseError: unexpected identifier "n"`).
  Corrigé en reformulant « absente (`null` ou chaîne vide) ». Leçon : éviter `**/` en fin
  d'emphase Markdown dans un docblock PHP.

### Completion Notes List

- **Q1-Q4 tranchées par Henri (T0)** appliquées telles quelles : égalité stricte, exception dédiée
  dans `negotiate()`, `warning` + log structuré sans table, service-only.
- **Bascule repli → rejet** confinée à la branche « version DÉCLARÉE non supportée » de
  `negotiate()`. Chemin heureux 33.1 (absente → courante, supportée → acceptée) strictement intact
  (prouvé par `test_happy_path_unchanged` + non-régression 96/96).
- **Zéro écriture / état inchangé** : la négociation reste en validation pure AVANT
  `DB::transaction` ; l'exception se propage sans try/catch. Prouvé par comptage des 5 tables +
  `Event::assertNotDispatched` + capture/recompare d'un contrat pré-existant (timestamps inclus).
- **Type dédié distinct** (AC #5) : `UnsupportedSchemaVersionException` n'étend pas
  `InvalidUpstreamContractException` (ni l'inverse) ; vérifié par `is_a(..., true)`.
- **Test 33.1 obsolète réécrit** : `test_unsupported_version_falls_back_to_current_and_is_logged`
  (qui asséchait l'ancien repli) → `test_unsupported_version_is_rejected_and_logged`. C'était la
  seule assertion 33.1 portant sur la branche désormais modifiée ; les autres tests 33.1 restent
  inchangés et verts.
- **Garde-fous confirmés** : R3 (0 « central » hors commentaires de garde), contrat agent figé non
  touché (`git status` filtré vide ; pas de bump `version.go`), aucune migration, aucune route.
- **Résultats tests (HÔTE php8.4 + sqlite)** : `UnsupportedSchemaVersionRejection` 8/8 (49 assert) ;
  non-régression `ControlHubContractSchemaVersion|ControlHubContractIngestion|ControlHubContract|ContractV1|StateCompiler`
  96/96 (1047 assert) ; `Capability` 75/75 (213 assert). 0 rouge.

### File List

**Nouveaux**
- `app/Exceptions/ControlHub/UnsupportedSchemaVersionException.php`
- `tests/Feature/ControlHub/UnsupportedSchemaVersionRejectionTest.php`

**Modifiés**
- `app/Services/ControlHub/ControlHubContractSchema.php` (repli → rejet + log structuré + PHPDoc classe/`negotiate()`)
- `app/Services/ControlHub/ControlHubContractIngestionService.php` (PHPDoc classe + `@throws` `ingest()` + commentaire inline négociation)
- `tests/Feature/ControlHub/ControlHubContractSchemaVersionTest.php` (test du repli réécrit en test de rejet)
- `_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md` (rejet décrit comme livré, Epic 33 clos)
- `docs/qa/domains/controlhub-contract.md` (Section 20 + checklist rapide 33.2, append-only)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (33-2 → review)
- `_bmad-output/implementation-artifacts/33-2-rejet-version-incompatible.md` (cette story : cases, Status, Dev Agent Record)
