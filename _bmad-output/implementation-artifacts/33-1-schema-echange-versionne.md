# Story 33.1: Schéma d'échange versionné

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **un schéma d'échange formalisé et versionné pour le contrat amont** : le payload reçu déclare une **version de schéma**, l'ingestion vérifie sa conformité, persiste la version sur le contrat, et le format est figé dans un **artefact partagé** servant de source unique,
so that **les deux BMAD (controlHub et SE5) partagent une référence vérifiable du format d'échange et qu'une évolution future soit traçable et coordonnée (R2)**.

> Story **1/2** de l'Epic 33 (« Contrat de données d'intégration controlHub↔SE5 »). Elle livre la **partie heureuse** : un payload **conforme** au schéma versionné est **accepté** et sa version est **enregistrée**, et le schéma est **documenté en artefact partagé**. Le **rejet gracieux d'une version incompatible** (négociation stricte, trace de l'écart) est la **Story 33.2** — explicitement **hors scope ici**.

> **Contexte d'ancrage (Epic 28 — DONE)** : l'ingestion idempotente existe déjà (`ControlHubContractIngestionService::ingest()`). Son PHPDoc dit littéralement *« le format n'est PAS un schéma versionné — réservé Epic 33 »* (commentaire en tête de classe + Story 28.2 §HORS). **C'est cette story qui lève ce report.** On **étend** la couture existante ; on ne réinvente pas l'ingestion.

## Acceptance Criteria

1. **Given** un payload de contrat amont déclarant une `schema_version` **supportée** (= version courante du schéma), **When** l'ingestion valide le payload contre le schéma versionné, **Then** le payload est **accepté** (persisté exactement comme en 28.2 : items/labels/imposedGroups/catalogApps, lien→`active`), **et** la **version de schéma reçue est enregistrée** sur le `ControlHubContract` (colonne dédiée).

2. **Given** un contrat ingéré, **When** on inspecte l'état persisté, **Then** la version de schéma est **lisible** (a) sur le modèle `ControlHubContract` (colonne + propriété/cast) et (b) dans le `ContractIngestionResult` retourné par `ingest()` (observabilité + assertions de test).

3. **Given** un payload **sans** champ `schema_version` (cas de transition — les payloads 28.2 n'en portent pas), **When** l'ingestion s'exécute, **Then** le payload est **accepté** et la version enregistrée **prend par défaut la version courante** ; **et** les tests d'ingestion 28.2 (`ControlHubContractIngestionTest`) **restent verts** (rétro-compatibilité — aucune régression). _[Comportement gated Q1 ; reco = défaut tolérant. Le **rejet** d'une version absente/inconnue est l'affaire de 33.2.]_

4. **Given** un contrat déjà ingéré à la version courante, **When** le **même** payload (même version, même contenu) est reçu une seconde fois, **Then** l'opération reste un **no-op fonctionnel** (NFR4) : `schema_version` **inchangée**, aucune écriture, **aucun** événement `ControlHubContractChanged` émis. La persistance de la version **ne doit pas** casser la détection du no-op de 28.2.

5. **Given** un contrat déjà ingéré, **When** un payload **par ailleurs identique** déclare une version de schéma **supportée mais différente** de celle enregistrée, **Then** l'ingestion traite le changement de version comme une **mutation** : nouvelle version enregistrée + événement émis **exactement une fois**. _[Cas tourné vers l'avenir — devient testable dès qu'il existe ≥ 2 versions supportées ; couvrir au minimum la logique, sans inventer une 2ᵉ version factice si Q2 fige une version unique.]_

6. **Given** le livrable, **When** on cherche la spécification du format, **Then** le **schéma versionné est documenté en un artefact partagé** (`_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md`) décrivant : la **version courante** + la **politique de compatibilité**, les **5 agrégats** (contract/items/labels/imposed_groups/catalog_apps) avec leurs champs et domaines de valeurs, le placement du champ `schema_version`, **et** une **note de coordination** rappelant que les **deux BMAD** doivent pointer cet artefact (référence ajoutée côté SE5 dans `prd-contrat-manage-se5.md#9` ; le handoff controlHub §7 — autre BMAD — doit y renvoyer symétriquement). [R2]

7. **Given** le code livré, **When** on l'inspecte, **Then** (a) l'ingestion reste le **seul** chemin écrivant `controlhub_contract_*` (NFR3) ; (b) **sans** contrat reçu, le comportement SE5 est **strictement inchangé** (NFR3) ; (c) le **contrat agent figé** est intact — `app/Services/Agent/StateCompiler.php`, `StateContract`/`ContractV1`, golden tests, `FROZEN_STATE_HASH`, `agent/**` **non touchés** (le versionnement de schéma est **serveur-only**, invisible de l'agent ; **pas** de bump `agent/shared/version.go`) ; (d) **aucun** identifiant/colonne/message livré ne contient le mot **« central »** (R3).

## Tasks / Subtasks

- [x] **Task 0 — Questions Henri (TRANCHÉES 2026-06-30 par Henri via dev-cycle)**
  - [x] **Q1 — Payload sans `schema_version`** : **(A) défaut = version courante** ✅ (rétro-compat 28.2, partie heureuse ; rejet = 33.2).
  - [x] **Q2 — Format de version** : **(B) chaîne semver `"1.0"` avec compat sur le MAJOR** ✅ (prépare la négociation 33.2 sans la livrer). ⇒ `CURRENT_VERSION = '1.0'`.
  - [x] **Q3 — Lieu d'enregistrement** : **(A) colonne `schema_version` sur `controlhub_contracts`** ✅ (attribut du contrat actif, lue par 33.2).
  - [x] **Q4 — Artefact partagé** : **(A) nouveau doc dédié** `schema-echange-controlhub-se5.md` ✅ (source unique référencée des deux côtés).
  - [x] **Q5 — Couture de négociation** : **point d'extension `negotiate()` posé** ✅ (résout conforme/absent, **sans** chemin de rejet — réservé 33.2).

- [x] **Task 1 — Référentiel de version `ControlHubContractSchema`** (AC: #1, #3, #7d)
  - [x] Créer `app/Services/ControlHub/ControlHubContractSchema.php` (`final class`, `declare(strict_types=1)`). Exposer : `CURRENT_VERSION` (`'1.0'`), `SUPPORTED_VERSIONS` (la seule version courante), et `negotiate(?string $declared): string` (`null`/`''` → `CURRENT_VERSION` ; version supportée → elle-même). Seam de rejet balisé `// Story 33.2 — rejet gracieux d'une version incompatible` (non implémenté). Helper `isSupported()`. **R3** OK. _Note : constantes NON typées (`const CURRENT_VERSION`) — composer cible PHP ^8.2, les typed class constants exigent 8.3._
  - [x] PHPDoc documente la **politique de compatibilité** (Q2) : égalité stricte en 33.1 ; compat MAJOR ouverte à 33.2.

- [x] **Task 2 — Migration ADDITIVE : colonne `schema_version`** (AC: #1, #2) _(Q3=A)_
  - [x] Créé `database/migrations/2026_06_30_120000_add_schema_version_to_controlhub_contracts.php` : `string('schema_version')->nullable()->after('received_at')` sous gardes `hasTable`+`hasColumn` ; `down()` = `dropColumn` sous gardes. Portable PG+SQLite, additive, aucun CHECK SQL.
  - [x] `'schema_version'` ajouté à `$fillable` + `@property ?string $schema_version` dans `app/Models/ControlHubContract.php` (pas de cast — chaîne libre).

- [x] **Task 3 — Branchement dans l'ingestion** (AC: #1, #3, #4, #5, #7a, #7b)
  - [x] `ingest()` : négociation en phase de validation PURE (`$schemaVersion = ControlHubContractSchema::negotiate(...)` après cast robuste de `$payload['schema_version']`), aucune écriture à ce stade ; `$result->schemaVersion` renseigné.
  - [x] Persistance respectant le no-op 28.2 : `resolveActiveContract($result, $schemaVersion)` pose la version à la création ; sur réutilisation, `$versionChanged = $contract->schema_version !== $schemaVersion` intégré au calcul `$mutated` (changement de version seul = mutation, AC #5), écriture conditionnée à `$mutated` (no-op AC #4 préservé).
  - [x] PHPDoc de classe + `ingest()` mis à jour : mention « réservé Epic 33 » LEVÉE, sémantique versionnée + renvoi artefact partagé ; exemple de payload enrichi de `schema_version`. Logique upsert/prune/normalisation/cohérence-cible inchangée.
  - [x] **NFR3** : seul `controlhub_contract_*` lu/écrit ; `StateCompiler` non touché.

- [x] **Task 4 — DTO de résultat** (AC: #2)
  - [x] `public ?string $schemaVersion = null;` ajouté à `ContractIngestionResult` + exposé dans `toArray()` (`'schema_version'`) ; renseigné dans `ingest()` après résolution.

- [x] **Task 5 — Artefact partagé + référence croisée** (AC: #6)
  - [x] Créé `_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md` : version courante `1.0` + politique de compat ; placement racine de `schema_version` ; 5 agrégats (items/labels/imposed_groups/catalog_apps + contrat) avec champs/domaines ; idempotence NFR4 ; note de coordination R2 (deux BMAD). R3 OK.
  - [x] Référence croisée additive ajoutée dans `prd-contrat-manage-se5.md#9` (ligne 📐 renvoyant à l'artefact). Handoff controlHub §7 (autre BMAD, worktree E30) hors édition ; nécessité de renvoi symétrique consignée dans l'artefact (§5).

- [x] **Task 6 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#5, #7)
  - [x] Créé `tests/Feature/ControlHub/ControlHubContractSchemaVersionTest.php` (8 tests, 561 assertions).
  - [x] `test_conforming_version_accepted_and_recorded` (AC #1/#2) — modèle + DTO.
  - [x] `test_absent_version_defaults_to_current` + `test_blank_version_defaults_to_current` (AC #3).
  - [x] `test_identical_reception_is_noop_with_version` + `test_identical_reception_without_version_then_with_current_is_noop` (AC #4) : mutated=false, timestamps+version inchangés, aucun event.
  - [x] `test_version_change_is_mutation` (AC #5) : **conditionnel** ; en 33.1 `SUPPORTED_VERSIONS` = 1 version → assertion documentant la couverture par construction (pas de 2ᵉ version factice) + branche réelle prête dès ≥ 2 versions. `test_negotiate_resolves_conforming_and_absent` couvre le seam.
  - [x] `test_r3_no_central_identifier` (AC #7d) : introspection FQCN/méthodes/propriétés/constantes des fichiers livrés + colonne `schema_version`.
  - [x] **Non-régression** verte : `--filter "ControlHubContractIngestion|ControlHubContract|ContractV1|StateCompiler"` = 94/94.
  - [x] **Piège SQLite** : idempotence mesurée par comptage + `mutated` + event + timestamps.

- [x] **Task 7 — Validation finale & garde-fous**
  - [x] `vendor/bin/phpunit --filter ControlHubContractSchemaVersion` → **8/8 verts** (HÔTE php8.4 + sqlite, `CACHE_DRIVER=array`).
  - [x] `vendor/bin/phpunit --filter "ControlHubContract|ContractV1|StateCompiler|Capability"` → **169/169, 0 régression**.
  - [x] `git status` : nouveaux fichiers `app/`/`tests/`/`database/migrations/`/`_bmad-output/` + édits ciblés (ingestion, DTO, modèle, PRD §9, doc QA). Aucun `agent/**`/`golden`/`FROZEN_STATE_HASH`/`StateCompiler`/`ContractV1` modifié (vérifié `git status --porcelain`).
  - [x] `grep -ri central` sur les fichiers livrés (identifiants) → **0** ; « central » n'apparaît que dans les commentaires de garde-fou R3 (mot cité comme interdit).
  - [ ] **VM (différé)** : `migrate:status` + `migrate` de `schema_version` sur `/vm` à exécuter **hors dev-cycle** (le dev-cycle ne migre que SQLite). Scénario QA 19.2 documenté. [mémoire `vm_migrations_not_auto_applied`]

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 33.1

**DANS** : référentiel de version `ControlHubContractSchema` (version courante + politique de compat + `negotiate()` pour conforme/absent) ; colonne additive `schema_version` sur `controlhub_contracts` (Q3) ; **enregistrement** de la version à l'ingestion **sans casser le no-op 28.2** ; exposition sur le DTO ; **artefact partagé** documentant le schéma + référence croisée côté SE5 ; tests HÔTE.

**HORS** (ne pas déborder) :
- **Rejet gracieux d'une version incompatible** (négociation stricte, trace `reçue vs supportées`, état inchangé) → **Story 33.2**. En 33.1, on **accepte** le conforme/absent et on **pose le seam** ; on n'écrit **aucun** chemin de rejet.
- **Diff/migration de schéma applicatif** (transformer un ancien payload vers le nouveau format) → non spécifié, hors scope.
- **Branchement transport/endpoint** : comme en 28.2, le livrable stable est le **service** `ingest(array $payload)`. Ne pas câbler de route ni inventer d'auth (le canal `ControlHubAuth` existant est la surface le jour venu).
- **Modifier `StateCompiler`/`StateMaille`/contrat agent** : le versionnement est **serveur-only**, invisible de l'agent. **Pas** de bump `agent/shared/version.go`.
- **Éditer le handoff controlHub §7** (autre BMAD, worktree E30) : hors périmètre d'édition ; la coordination R2 est **consignée** dans l'artefact partagé.

### Code réel à étendre (ancrage exact — réutiliser, ne pas réinventer)

- **`app/Services/ControlHub/ControlHubContractIngestionService.php`** (28.2) : méthode publique `ingest(array $payload): ContractIngestionResult` → validation PURE (`normalizeItems/Labels/ImposedGroups/CatalogApps`, `assertImposedGroupLabelsDeclared`) **avant** `DB::transaction`, puis `resolveActiveContract()` (singleton `link_state=active`), `reconcileChildren()` (upsert clé naturelle + prune), gating `$mutated` de `received_at`/`link_state`/`save()`, dispatch `ControlHubContractChanged` **post-commit** uniquement sur mutation. **Le PHPDoc de tête contient la mention à lever** (« réservé Epic 33 »). C'est le **seul écrivain** de `controlhub_contract_*` (NFR3).
- **`app/Models/ControlHubContract.php`** (28.1/30.2) : `$fillable = ['link_state','received_at']` (→ ajouter `schema_version`) ; `active()` = singleton `link_state=active` (ne pas diverger). Casts `link_state`/`received_at`.
- **`app/Services/ControlHub/Data/ContractIngestionResult.php`** (28.2) : DTO simple (`$mutated`, `$contractCreated`, `$contractId`, compteurs par agrégat, `toArray()`) → ajouter `schemaVersion`.
- **`app/Exceptions/ControlHub/InvalidUpstreamContractException.php`** (28.2) : exception de domaine existante (`::for($field,$reason)`). **NE PAS** l'utiliser pour rejeter une version en 33.1 (le rejet = 33.2). Disponible si Henri tranche Q1=B (déconseillé).
- **`database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php`** (28.1) : source de vérité des 5 tables + clés naturelles `chc_*` (à refléter dans l'artefact partagé). Style migration : garde `hasTable`/`hasColumn`, commentaires de colonne.
- **`tests/Feature/ControlHub/ControlHubContractIngestionTest.php`** (28.2) : patron des tests (payloads tableaux, `Event::fake()`, mesure no-op par comptage + `mutated` + dispatch).

### NFR4 — le piège central de cette story (idempotence × enregistrement de version)

Enregistrer `schema_version` **ne doit pas** transformer chaque réception en mutation. Discipline :
1. **Réception identique (même version)** → **no-op** : ne pas réécrire `schema_version`, ne pas toucher `received_at`/`updated_at`, ne pas émettre d'event (AC #4).
2. **Version différente (supportée) sur contenu sinon identique** → **mutation** : la version fait partie de l'état du contrat ⇒ `$mutated = true`, nouvelle version + event (AC #5).
3. Intégrer la comparaison `schema_version` persistée vs négociée **dans** le calcul `$mutated` du contrat racine — calquer le gating existant de `received_at`/`link_state` (28.2, `ingest()` lignes ~141-147). **Ne pas** poser `schema_version` inconditionnellement sur le contrat réutilisé.

### Garde-fous projet CRITIQUES

- **R3 — Vocabulaire (BLOQUANT)** : **INTERDIT** — `central` dans tout nom de classe/méthode/propriété/constante/colonne/message/commentaire d'identifiant. Vocabulaire : « amont »/`ControlHub*`/`upstream`/`authority`. [mémoires `project_contrat_manage_se5_upstream`, `legacy_central_vs_local_split` ; prd#R3]
- **NFR3 — Standalone préservé** : sans contrat reçu, comportement SE5 **strictement inchangé** ; ingestion = seul écrivain ; pas de chemin existant modifié pour lire le contrat. [prd#NFR3]
- **NFR4 — Idempotence** : voir encadré ci-dessus (cœur du risque). [prd#NFR4]
- **NFR7 — Postgres prod / SQLite test** : domaine de version validé en **PHP** (jamais `CHECK` SQL) ; migration **portable** PG+SQLite, **additive** (nullable, pas de NOT NULL sans défaut). [mémoire `sqlite_tests_no_varchar_enforcement`]
- **Contrat agent figé (BLOQUANT)** : `StateCompiler`, `StateContract`/`ContractV1`, golden tests, `FROZEN_STATE_HASH`, `agent/**` **intacts** ; le versionnement de schéma est **serveur-only**, invisible de l'agent ; **pas** de bump version agent. [mémoires `feedback_agent_edit_bump_version` (a contrario : on ne touche pas l'agent), `project_epic23_e2e_validated`]
- **Routes/transport** : aucune route ajoutée ; si un point d'entrée s'avérait nécessaire, le garder minimal et **après** le groupe 16.12 de `routes/api.php` (fenêtre 1500 chars). [mémoire `api_routes_arch_test_window_trap`] — *a priori non requis en 33.1.*

### Distinctions à NE PAS confondre (homonymies)

- **Version de schéma d'ÉCHANGE** (cette story : format du payload controlHub↔SE5, champ `schema_version` racine) ≠ **`ContractV1`/version du contrat AGENT** (`app/Services/Agent/`, desired-state émis vers l'agent, figé/golden). Deux versionnements distincts ; ne **jamais** coupler l'un à l'autre.
- `ControlHubConnection` (lien/transport, heartbeat, tokens) ≠ `ControlHubContract` (politique imposée reçue). La version de schéma est un attribut du **contrat**, pas du transport.

### Project Structure Notes

- **Nouveaux fichiers** : `app/Services/ControlHub/ControlHubContractSchema.php` ; `database/migrations/2026_06_30_120000_add_schema_version_to_controlhub_contracts.php` ; `tests/Feature/ControlHub/ControlHubContractSchemaVersionTest.php` ; `_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md`.
- **Édits ciblés** : `ControlHubContractIngestionService.php` (négociation + enregistrement + PHPDoc) ; `ContractIngestionResult.php` (champ `schemaVersion`) ; `ControlHubContract.php` (`$fillable` + property) ; `prd-contrat-manage-se5.md` (référence croisée §9) ; cocher cette story + `sprint-status.yaml`.
- **Racine = projet Laravel** (artisan/app à la racine ; pas de préfixe `laravel/`). [mémoire `root_is_laravel`]
- **Tests HÔTE** (php8.4 + `pdo_sqlite`), jamais la VM. [mémoire `phpunit_test_env_host_vs_vm`]

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 33.1] — AC d'origine (payload conforme accepté + version enregistrée + artefact partagé référencé par les deux BMAD).
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#9, #R2] — couture controlHub↔SE5 ; schéma d'échange = contrat de données partagé versionné ; risque R2 (désync entre BMAD).
- [Source: _bmad-output/planning-artifacts/handoff-controlhub-contrat-manage.md#7] — contrat d'interface à garder synchronisé (artefact partagé, schéma versionné = source unique) — **autre BMAD** (worktree E30).
- [Source: app/Services/ControlHub/ControlHubContractIngestionService.php] — ingestion 28.2 à étendre ; PHPDoc « réservé Epic 33 » à lever ; discipline no-op (lignes ~141-147).
- [Source: _bmad-output/implementation-artifacts/28-2-reception-idempotente-contrat-amont.md] — patron service + DTO + tests + garde-fous R3/NFR3/NFR4.
- [Source: database/migrations/2026_06_26_100000_create_controlhub_contract_tables.php] — schéma des 5 agrégats + clés naturelles (vérité pour l'artefact partagé).
- [Source: app/Services/ControlHub/Data/ContractIngestionResult.php] — DTO de résultat à enrichir.

## Dépendances

- **Amont (toutes DONE/branche unifiée)** :
  - **28.1** — schéma `controlhub_contract_*`, modèles, enums, factories (fondation).
  - **28.2** — `ControlHubContractIngestionService` (point d'extension de cette story) + DTO + event + exception.
  - **28.3 / 29.x / 30.x / 31.x** — consommateurs en aval ; non impactés (versionnement serveur-only, invisible).
- **Aval** :
  - **33.2** — Négociation et **rejet gracieux** d'une version incompatible : **réutilise** `ControlHubContractSchema` (seam `negotiate()`), la colonne `schema_version` et l'artefact partagé livrés ici. ⚠️ 33.1 doit poser ces fondations **proprement** pour que 33.2 ne réécrive pas le seam.
- **Coordination R2 (autre BMAD)** : le handoff controlHub §7 doit pointer le même artefact partagé (action consignée, hors édition ici).

## Testing

- **Cible : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, `RefreshDatabase`, `CACHE_DRIVER=array`. Jamais la VM. [mémoires `phpunit_test_env_host_vs_vm`, `apcu_cache_no_lock`]
- Filtre ciblé : `--filter ControlHubContractSchemaVersion`. Non-régression : `--filter "ControlHubContractIngestion|ControlHubContract|ContractV1|StateCompiler"`.
- Couverture : conforme→accepté+enregistré (AC #1/#2), absent→défaut courant + 28.2 verts (AC #3), no-op avec version (AC #4), changement de version = mutation (AC #5, conditionnel ≥2 versions), R3 (AC #7d).
- **Pièges SQLite** : idempotence mesurée par **comptage + `mutated` + dispatch d'event**, pas par contrainte de chaîne. Migration additive portable.
- ⚠️ **VM** : migration `schema_version` **pas auto-jouée** par le dev-cycle ; `migrate:status`+`migrate` à part sur `/vm`. [mémoire `vm_migrations_not_auto_applied`]

## Recommandation Modèle Dev

**`opus`.**

Justification : story **schéma + validation + intégration** au cœur du contrat de données — exactement le profil « complexe » (logique critique, versionnement, intégration cross-BMAD, garde-fous transverses). Les points durs ne sont pas mécaniques :
1. **Idempotence × enregistrement de version (NFR4)** : poser `schema_version` sans transformer chaque réception en mutation, tout en faisant du **changement de version** une mutation légitime — raisonnement Eloquent fin sur le gating `$mutated` de 28.2 (le piège central).
2. **Conception du seam de négociation** sans déborder sur 33.2 : livrer un point d'extension `negotiate()` propre (conforme/absent accepté, rejet **non** implémenté) que 33.2 prolongera — sous/sur-construction toutes deux pénalisantes.
3. **Rétro-compatibilité 28.2** : les payloads sans version doivent rester acceptés et tous les tests d'ingestion existants verts — régression silencieuse facile à introduire.
4. **Garde-fous simultanés** : R3 (vocabulaire), NFR3 (standalone), contrat agent figé (ne pas coupler version d'échange et `ContractV1`), migration portable additive.
Le dev-cycle routera la review vers le **modèle opposé** (sonnet/fable) ; placer **opus** sur l'implémentation met le raisonnement là où le risque d'effet de bord (idempotence, couture 33.1/33.2) est le plus élevé.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- `CACHE_DRIVER=array DB_CONNECTION=sqlite vendor/bin/phpunit --filter ControlHubContractSchemaVersion` → OK (8 tests, 561 assertions).
- `CACHE_DRIVER=array DB_CONNECTION=sqlite vendor/bin/phpunit --filter "ControlHubContractIngestion|ControlHubContract|ContractV1|StateCompiler"` → OK (94 tests, 1034 assertions).
- `CACHE_DRIVER=array DB_CONNECTION=sqlite vendor/bin/phpunit --filter "ControlHubContract|ContractV1|StateCompiler|Capability"` → OK (169 tests, 1247 assertions).
- `grep -rin central` sur les fichiers livrés → seules occurrences = commentaires de garde-fou R3 (mot cité comme interdit) ; aucun identifiant/colonne/message.
- `git status --porcelain | grep -i "agent/|golden|StateCompiler|ContractV1|version.go"` → vide (contrat agent figé intact).

### Completion Notes List

- **Référentiel `ControlHubContractSchema`** (net-new) : `CURRENT_VERSION='1.0'` (Q2 — semver chaîne), `SUPPORTED_VERSIONS=['1.0']`, `negotiate(?string): string` (Q1=A : absent/`''`→courant ; supporté→lui-même ; seam de rejet 33.2 balisé sans l'implémenter), `isSupported()`. Constantes NON typées (composer cible PHP ^8.2 ; typed class constants = 8.3+).
- **Migration additive** (net-new) `schema_version` nullable `after('received_at')`, gardes `hasTable`+`hasColumn`, portable PG+SQLite, aucun CHECK SQL (domaine validé en PHP — NFR7).
- **Idempotence × version (cœur NFR4)** : la version est négociée en phase de validation PURE (aucune écriture, rollback total préservé) ; posée d'emblée à la création ; sur réutilisation, `$versionChanged = schema_version !== négociée` est intégré au calcul `$mutated` (calque de `received_at`/`link_state`) → changement de version seul = mutation (AC #5), réception identique = no-op total sans réécriture (AC #4). `schema_version` jamais posé inconditionnellement.
- **DTO** `ContractIngestionResult::$schemaVersion` exposé dans `toArray()`, renseigné à chaque ingestion (même sur no-op — observabilité).
- **PHPDoc d'ingestion** : mention « réservé Epic 33 » LEVÉE, remplacée par la sémantique versionnée + renvoi artefact partagé ; exemple de payload enrichi de `schema_version`.
- **Artefact partagé** (net-new) `schema-echange-controlhub-se5.md` : version courante + politique de compat + placement racine + 5 agrégats détaillés + idempotence + note de coordination R2 ; ref croisée additive dans `prd-contrat-manage-se5.md#9`. Handoff controlHub §7 (autre BMAD) hors édition — renvoi symétrique consigné dans l'artefact.
- **Garde-fous** : R3 (0 identifiant « central »), NFR3 (ingestion seul écrivain, standalone inchangé), contrat agent FIGÉ intact (aucun fichier `agent/**`/`StateCompiler`/`ContractV1`/golden/`FROZEN_STATE_HASH` touché ; pas de bump `agent/shared/version.go`).
- **AC #5 partiellement constructif** : `SUPPORTED_VERSIONS` ne contient qu'une version en 33.1 (Q2 fige une version unique) → le test `test_version_change_is_mutation` documente la couverture par construction (gating `$mutated`) sans fabriquer de 2ᵉ version factice ; la branche réelle s'active automatiquement dès qu'une 2ᵉ version est ajoutée.
- **RESTE (différé, hors dev-cycle)** : `migrate` de `schema_version` sur `/vm` (le dev-cycle ne migre que SQLite). Scénario QA 19.2 fournit la procédure. La migration ajoute un nouveau schéma → **la VM n'est PAS auto-migrée**.

### File List

**Nouveaux fichiers :**
- `app/Services/ControlHub/ControlHubContractSchema.php`
- `database/migrations/2026_06_30_120000_add_schema_version_to_controlhub_contracts.php`
- `tests/Feature/ControlHub/ControlHubContractSchemaVersionTest.php`
- `_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md`

**Fichiers modifiés :**
- `app/Services/ControlHub/ControlHubContractIngestionService.php` (négociation + enregistrement gaté `$mutated` + PHPDoc levé)
- `app/Services/ControlHub/Data/ContractIngestionResult.php` (`$schemaVersion` + `toArray()`)
- `app/Models/ControlHubContract.php` (`$fillable` + `@property schema_version`)
- `_bmad-output/planning-artifacts/prd-contrat-manage-se5.md` (référence croisée §9)
- `docs/qa/domains/controlhub-contract.md` (Section 19 + checklist 33.1, append-only)
- `_bmad-output/implementation-artifacts/33-1-schema-echange-versionne.md` (cette story)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (33-1 → review)
