# Story 32.2: Indisponibilité amont et trace du lien

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **distinguer « amont indisponible » (panne transitoire, pas de MAJ reçue) de « lien rompu » (signal `severed` explicite, 32.1) — une simple indisponibilité NE LIBÈRE PAS les verrous (le dernier contrat reste en vigueur) — et garantir que TOUTE transition d'état du lien est tracée de façon auditable**,
so that **une panne réseau/serveur amont ne déverrouille jamais le parc par accident (seule une rupture explicite le fait), et que le cycle de vie du lien soit auditable de bout en bout (NFR5)**.

> Story **2ᵉ de l'Epic 32 (« Cycle de vie du lien & release »)**, **clôt l'Epic 32**. Couvre la distinction panne/rupture du PRD §5.3 (l.90-91) + l'auditabilité NFR5. Construite **DESSUS 32.1** (service `ControlHubContractSeveranceService`, table append-only `controlhub_link_audit_logs` + modèle `ControlHubLinkAuditLog`, event `ControlHubContractChanged`, enum `ControlHubLinkState{Active,Severed}`).
>
> **⚠️ ALERTE DÉPENDANCE — 32.1 est en `review`, PAS `done`.** 32.2 réutilise la table d'audit, le service de rupture, l'event et l'enum livrés par 32.1. **Le dev de 32.2 DOIT rebaser** si la review de 32.1 modifie le service / la table / l'enum / l'event. Vérifier l'état de 32-1 dans `sprint-status.yaml` avant de coder T1.

> **VERDICT D'INVESTIGATION — story « PREUVE » à très large dominante (vérifié 2026-06-30).** Contrairement à 32.1 (« preuve + construction »), 32.2 est **quasi entièrement PREUVE** :
> - **PREUVE (cœur)** — La **non-libération à l'indisponibilité est ACQUISE GRATUITEMENT** : `ControlHubContract::active()` [app/Models/ControlHubContract.php:64-69] filtre **uniquement** `link_state = active` et **IGNORE `received_at`**. Aucun chemin de **décroissance** (« decay ») n'existe : rien ne repasse un contrat `active` à un autre état au fil du temps. Donc « pas de MAJ → le dernier contrat reste en vigueur, verrous maintenus » (PRD §5.3, l.90) est **déjà le comportement par construction**. À PROUVER, pas à construire.
> - **PREUVE (auditabilité NFR5)** — La **seule transition d'état du lien qui existe** est `active → severed`, et 32.1 la **trace déjà** (`controlhub_link_audit_logs`). Tant qu'aucun **3ᵉ état** n'est introduit (cf. Q1), il n'y a **aucune autre transition à auditer** : la couverture NFR5 du cycle de vie est donc **complète par construction** (à prouver).
> - **CONSTRUCTION ÉVENTUELLE (gated Q1, à minimiser)** — Une **observabilité** de la fraîcheur (« amont injoignable depuis X ») est *optionnelle* : un **indicateur DÉRIVÉ lecture seule** calculé depuis `received_at` (+ TTL), **sans jamais toucher `active()`** ni l'enforcement. PAS de nouvel état d'enum, PAS de job de décroissance, PAS de migration — sauf si Henri tranche explicitement (Q1 = option C, déconseillée).

> **🔴 ANTI-PATTERN CENTRAL À INTERDIRE (le seul vrai risque de cette story).** Ne **JAMAIS** coupler `ControlHubContract::active()` (ni `resolveActiveContract()`) à `received_at` / à un TTL / à un statut de fraîcheur. Faire dépendre `active()` de la fraîcheur **libérerait les verrous sur une simple panne** → viole frontalement l'AC1 de l'Epic et le PRD §5.3. L'indisponibilité doit rester **strictement observationnelle**. La SEULE chose qui libère les verrous est le signal de rupture **explicite** de 32.1.

## Contexte du code (constat vérifié 2026-06-30)

### A. La non-libération est AUTOMATIQUE (preuve, pas construction)

- **`ControlHubContract::active()`** [app/Models/ControlHubContract.php:64-69] — `where('link_state', Active)->first()`. **Ne lit pas `received_at`.** Un contrat `active` le reste indéfiniment, quelle que soit l'ancienneté de la dernière réception.
- **`received_at`** [ControlHubContract.php:30,46,51 ; cast `datetime`] — **horodatage de fraîcheur déjà existant**, posé à `now()` à la création [ControlHubContractIngestionService.php:196] **et** sur mutation d'un contrat réutilisé [:145]. **Jamais** rafraîchi sur no-op (NFR4, réception identique). C'est **le signal de fraîcheur du contrat** (inbound). La factory expose `notYetReceived()` (`received_at = null`) [database/factories/ControlHubContractFactory.php:36-40].
- **Aucun chemin de décroissance** : grep `app/` ⇒ aucune notion `stale`/`staleness`/`unavailable`/`ttl`/`fraîcheur` côté **contrat**. Rien ne désactive un contrat au fil du temps. ⇒ indisponibilité = **silence pur** (absence de MAJ), jamais matérialisée.

### B. Transport (heartbeat) ≠ politique (contrat) — NE PAS CONFONDRE

- **`ControlHubConnection`** [app/Models/ControlHubConnection.php] = le **transport/lien** : `last_handshake_at`, `last_heartbeat_at`, `status` (`online`/`offline`/`error`), `is_active`, `expires_at`, `heartbeat_*`. Le heartbeat est **OUTBOUND** (SE5 → controlHub, `ControlHubHeartbeatJob` [app/Jobs/ControlHubHeartbeatJob.php] + `performHeartbeat()`) — il maintient l'**enregistrement de l'instance**, ce n'est **PAS** la fraîcheur du **contrat reçu**.
- **`ControlHubContract`** = la **politique imposée** reçue. PRD/28.1 : « deux concepts complémentaires, **sans FK** ». ⇒ La fraîcheur pertinente pour « amont indisponible (pas de MAJ de **contrat**) » est **`ControlHubContract.received_at`**, pas le heartbeat de connexion. Si Henri veut une vue d'indisponibilité « réseau » plutôt que « contrat », `ControlHubConnection.status`/`last_heartbeat_at` existe déjà (Q2/Q4). Ne pas inventer de FK entre les deux.

### C. La seule transition d'état du lien est `active → severed` (déjà tracée)

- **Enum `ControlHubLinkState`** [app/Enums/ControlHubLinkState.php:18-22] = `{Active, Severed}`. **Pas** de `stale`/`unavailable`.
- **`ControlHubLinkAuditLog`** [app/Models/ControlHubLinkAuditLog.php] (livré 32.1) — table **append-only** (`save()` sur ligne existante → `LogicException` [:84-93]), fabrique statique `log(contractId, fromState, toState, origin, actorLabel, reason, summary)` [:105-124], `created_at` manuel. Colonnes **génériques** `from_state`/`to_state` (string libres) ⇒ la table peut **déjà** consigner d'autres transitions sans migration de schéma. Constantes d'origine **`ORIGIN_COMMAND`/`ORIGIN_API`** seulement [:53-54] ⇒ une transition non déclenchée par commande/api exigerait une **nouvelle constante** (ex. `ORIGIN_SYSTEM`) — additif côté modèle, **pas** de migration.
- **Migration** [database/migrations/2026_06_30_100000_create_controlhub_link_audit_logs_table.php] — `from_state`/`to_state`/`origin` = `string` libres, `summary` json nullable, `created_at` `useCurrent()`. **Aucune contrainte CHECK** ne borne les valeurs ⇒ extensible sans schéma.
- ⇒ Tant qu'aucun **3ᵉ état** n'est introduit, **`active → severed` est l'UNIQUE transition possible** et 32.1 la trace. NFR5 « les changements d'état soient auditables » est **satisfait par construction**.

### D. Réception après indisponibilité (reprise) — déjà NFR4-safe

- `ControlHubContractIngestionService::ingest()` : une réception **identique** après une longue panne = **no-op** (NFR4), `received_at` **non touché** [:142-147]. Une réception **différente** rafraîchit `received_at` + émet `ControlHubContractChanged` [:143-159]. ⇒ « reprise après indisponibilité sans effet de bord » (PRD NFR4, l.138) est **déjà acquis** : pas de « réveil » spécial à coder.

## Acceptance Criteria

1. **(PREUVE — non-libération)** **Given** un contrat amont **actif** (`link_state = active`) avec des items `locked`, un `received_at` **arbitrairement ancien** (aucune MAJ depuis longtemps, quel que soit un TTL),
   **When** le temps passe **sans** signal de rupture explicite,
   **Then** `ControlHubContract::active()` reste **non-null**, le contrat **reste en vigueur**, et les verrous / le bornage catalogue / le refus de modification **restent appliqués** (`StateCompiler` émet toujours les candidats `StateMaille::Upstream` ; `UpstreamCatalogResolver::isBounded()` reste `true` ; `CapabilityPolicy` refuse toujours un `locked`) — **prouvé par construction** (`active()` filtre `link_state` seul, ignore `received_at` ; aucun chemin de décroissance) **et** par test.

2. **(PREUVE — panne ≠ rupture)** **Given** le même contrat actif et « ancien »,
   **When** (a) **rien** n'arrive (panne/silence) **versus** (b) un signal de rupture **explicite** arrive (chemin 32.1),
   **Then** seul (b) pose `link_state = severed` et lève les verrous ; (a) est **strictement no-op** (aucune écriture, aucun audit, aucun event, contrat reste `active`). **La rupture explicite (32.1) est le SEUL chemin qui libère.**

3. **(CONSTRUCTION conditionnelle — observabilité fraîcheur, selon décision Q1)**
   **Given** un contrat actif,
   **When** on interroge la fraîcheur du lien,
   **Then** [selon **Q1**] :
   - **Q1 = A (pur preuve, défaut de cette story)** : **aucune** représentation persistée ni dérivée ; l'indisponibilité = absence de `received_at` frais, jamais exposée. T3 = test verrouillant cette absence assumée.
   - **Q1 = B (dérivé lecture seule)** : un **indicateur DÉRIVÉ** read-only (helper `isStale(?Ttl)` / `lastSeenAt()` calculé depuis `received_at` + TTL Q2) est disponible pour observabilité, **sans** modifier `active()` ni l'enforcement (les verrous restent appliqués même « stale »).
   - **Q1 = C (état explicite — DÉCONSEILLÉ)** : nouvel état d'enum + chemin de bascule ; **seulement** si Henri l'exige (alors AC4 trace `active↔stale`).
   Le test verrouille la décision retenue.

4. **(NFR5 — trace des transitions)** **Given** une transition d'état du lien,
   **When** elle survient,
   **Then** elle est consignée dans `controlhub_link_audit_logs` (table 32.1, append-only). La transition `active → severed` est **déjà tracée** (32.1, non régressée).
   - Si **Q1 ≠ C** : il n'existe **aucune autre** transition ⇒ la couverture NFR5 du cycle de vie est **prouvée complète** (test affirmant que `active → severed` est l'unique transition auditée et que l'indisponibilité n'en est PAS une).
   - Si **Q1 = C** : `active → stale` et `stale → active` sont tracées via la **même** table (origine `ORIGIN_SYSTEM` additive ; aucune migration de schéma).

5. **(NFR4 — reprise après silence)** **Given** un contrat actif resté longtemps sans MAJ,
   **When** une MAJ **identique** arrive (reprise), **Then** no-op (`received_at` inchangé) ;
   **When** une MAJ **différente** arrive, **Then** `received_at` rafraîchi + `ControlHubContractChanged` émis — reprise normale **sans effet de bord** (pas de traitement spécial « sortie d'indisponibilité »).

6. **(NFR3 — standalone byte-identique)** **Given** une instance **sans contrat** (standalone),
   **When** la fraîcheur est interrogée ou que le temps passe,
   **Then** comportement **strictement inchangé** : aucune table contrat lue/écrite, golden `state.v1.json` / `FROZEN_STATE_HASH` (PHP & Go) / `ContractV1` **intacts**.

7. **(Contrat agent figé)** **Given** le contrat agent `se5.desired-state/v1`,
   **When** 32.2 est livrée,
   **Then** l'indisponibilité est **SERVEUR-only**, invisible de l'agent : `active()` reste non-null en cas de panne ⇒ **le dernier état compilé reste servi** ; **aucune** touche `ContractV1`/`StateHasher`/golden/`tests/Fixtures/Agent/*.json` ; **aucun bump** `agent/shared/version.go` (rien sous `agent/**`).

8. **(Tests HÔTE)** **Given** la suite HÔTE (php8.4 + sqlite, `CACHE_DRIVER=array`),
   **When** elle s'exécute,
   **Then** sont verts : non-libération (AC1), séparation panne/rupture (AC2), décision Q1 (AC3), trace NFR5 du cycle (AC4), reprise NFR4 (AC5), standalone (AC6), agent figé (AC7) — **sans régression** des suites `ControlHubContract*`/`ContractSeverance*`/`UpstreamContract*`/`UpstreamCatalog*`/`UpstreamLock*`/`Capability*`/`Agent`/`Application*`/`ContractV1`/`StateCompiler`.

## Tasks / Subtasks

- [x] **T0 — Acter le cadrage « preuve dominante » & trancher les Questions Henri** (AC: #1, #3, #4)
  - [x] Acter que la **non-libération** (AC1) et la **couverture NFR5** (AC4) sont **acquises par construction** : `active()` ignore `received_at` ; `active → severed` (32.1) est l'unique transition. **NE PAS sur-construire.**
  - [x] Trancher **Q1=A (pur preuve)**, **Q2=sans objet**, **Q3=preuve de couverture seule**, **Q4=passive/silence** — tranchés par Henri avant T1.

- [x] **T1 — PREUVE : l'indisponibilité ne libère pas les verrous** (AC: #1) — *test, aucune construction*
  - [x] Test : contrat `active` + item `registry`/`locked` + `received_at` posé très ancien (`travel()`). Assert `ControlHubContract::active()` **non-null** ; `UpstreamCatalogResolver::isBounded()` = `true` ; `UpstreamLockResolver::isCapabilityLocked()` = `true` ; `CapabilityPolicy::modify` = `false`.
  - [x] Test de **non-décroissance** : faire passer le temps (aucun appel de service) → zéro écriture sur `controlhub_contracts` (query-log).

- [x] **T2 — PREUVE : panne ≠ rupture (séparation stricte)** (AC: #2) — *test*
  - [x] Test : sur le même contrat « ancien », (a) silence → `active()` non-null, **0** audit, **0** event ; (b) `ControlHubContractSeveranceService::sever()` → `severed`, verrous levés, **1** audit. Seul (b) libère — prouvé.

- [x] **T3 — Observabilité de la fraîcheur (Q1=A)** (AC: #3)
  - [x] **Q1=A** : test GARDE-FOU ANTI-RÉGRESSION prouvant que `active()` ignore `received_at` nul/très ancien/10 ans — aucune API de fraîcheur introduite.

- [x] **T4 — Traçabilité NFR5 du cycle de vie (preuve de couverture)** (AC: #4) — *réutilise la table 32.1*
  - [x] Test : enum `{Active, Severed}` = exactement 2 cas → 1 seule transition → 32.1 la trace. Silence de 6 mois → 0 audit. Rupture → 1 audit `active→severed`. Couverture NFR5 complète prouvée sans rien construire.

- [x] **T5 — PREUVE : reprise après silence (NFR4)** (AC: #5) — *test*
  - [x] Test réception identique : `ingest()` → no-op, `received_at` inchangé, aucun event supplémentaire (`Event::assertDispatchedTimes(1)` total).
  - [x] Test réception différente : `ingest()` → `received_at` rafraîchi + `ControlHubContractChanged` émis (2 events total).

- [x] **T6 — Tests HÔTE (php8.4 + sqlite, `CACHE_DRIVER=array`)** (AC: #1–#8)
  - [x] `tests/Feature/ControlHub/UpstreamUnavailabilityTest.php` — 13 tests, 53 assertions. Harnais : `RefreshDatabase`, `WorkstationGroupObserver::disableSync()`, `Event::fake`, `travel()`. Tous verts.
  - [x] Tests HÔTE exclusivement (filtres ciblés). Aucun run massif VM.

- [x] **T7 — Runbook QA** (AC: #1–#5)
  - [x] `## Section 18 — Indisponibilité amont et trace du lien (Story 32.2, 2026-06-30)` appendée à `docs/qa/domains/controlhub-contract.md` (4 scénarios : 18.1 panne verrous maintenus, 18.2 panne vs rupture, 18.3 reprise NFR4, 18.4 enum NFR5 + checklist rapide 32.2).
  - [x] Ligne cumulative `docs/qa/README.md` suffixée (Story 32.2, clôt l'Epic 32).

- [x] **T8 — Validation finale & garde-fous** (AC: #6, #7)
  - [x] Non-régression HÔTE : `ControlHubContract|ContractSeverance|UpstreamCatalog|UpstreamContract|UpstreamLock|CapabilityPolicy|UpstreamUnavailability` → 182/182. `ContractV1|StateCompiler|Capability` → 109/109. `Agent|Application|AppStore` → 678 passed / 22 skip.
  - [x] **🔴 Garde-fou central** : `active()` ne référence jamais `received_at` — vérifié (code non modifié, anti-régression = test dédié).
  - [x] **R3** : aucun « central » dans les fichiers livrés par 32.2 — vérifié.
  - [x] **NFR3** standalone prouvé (2 tests dédiés). **Contrat agent figé** : `agent/**`/golden/`FROZEN_STATE_HASH`/`ContractV1` NON modifiés.

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 32.2

**DANS** : **prouver** par tests que (a) l'indisponibilité (silence) ne libère pas les verrous (AC1), (b) la rupture explicite est le seul chemin de libération (AC2), (c) la couverture NFR5 du cycle de vie est complète (AC4), (d) la reprise est NFR4-safe (AC5) ; **éventuellement** (gated Q1=B) un helper DÉRIVÉ lecture seule de fraîcheur ; runbook QA Section 18.

**HORS** (ne pas déborder) :
- **Coupler `active()`/l'enforcement à la fraîcheur** : **INTERDIT** (anti-pattern central — libérerait sur panne).
- **Refaire la rupture** : la réception du signal `severed`, l'audit `active→severed`, la matérialisation de valeur = **32.1** (déjà livré). 32.2 ne re-construit rien de 32.1.
- **Job de décroissance / scheduler qui marque les contrats stale** : **HORS** sauf décision Q1=C explicite (déconseillé — coût + risque, AC ne l'exige pas).
- **Toucher le transport** (`ControlHubConnection`/heartbeat outbound) : hors scope ; ne pas inventer de FK contrat↔connexion.
- **Contrat agent / wire format / golden** : ZÉRO touche.

### Décisions de cadrage (pré-établies par l'investigation)

- **D-A — Non-libération gratuite** : `active()` ignore `received_at` (l.64-69) ; aucun chemin de décroissance ⇒ AC1 est PREUVE.
- **D-B — Audit du cycle complet par construction** : `active → severed` est l'unique transition ; 32.1 la trace ⇒ AC4 est PREUVE (sauf Q1=C).
- **D-C — Reprise NFR4-safe** : `ingest()` no-op sur réception identique, refresh sur différente (l.142-159) ⇒ AC5 acquis.
- **D-D — Indisponibilité = silence pur** : jamais signalée par l'amont (la rupture l'est ; la panne ne l'est pas) ⇒ détection **passive** (Q4) par défaut.

### Patrons de référence à IMITER (ne rien réinventer)

- **`ControlHubContract::active()`** [app/Models/ControlHubContract.php:64-69] — chokepoint à **ne PAS modifier**.
- **`ControlHubLinkAuditLog`** [app/Models/ControlHubLinkAuditLog.php] — table append-only **à réutiliser telle quelle** (colonnes `from_state`/`to_state`/`origin` génériques). Constante `ORIGIN_SYSTEM` additive **seulement** si Q1=C/Q3 élargi.
- **`ControlHubContractSeveranceService`** [app/Services/ControlHub/ControlHubContractSeveranceService.php] (32.1) — le seul chemin de libération ; 32.2 le **réutilise** dans les tests AC2, ne le modifie pas.
- **`ControlHubContractIngestionService::ingest()`** [:142-159] — comportement de reprise NFR4 à prouver (AC5).
- **Harnais de test 32.1** — `tests/Feature/ControlHub/ContractSeveranceTest.php` (factory contrat/items, `RefreshDatabase`, `travel()`) à calquer.

### Garde-fous projet CRITIQUES

- **🔴 `active()` indépendant de la fraîcheur (BLOQUANT)** — l'enforcement ne dépend JAMAIS de `received_at`/TTL. [le seul vrai risque de régression de cette story]
- **NFR3 — standalone byte-identique (BLOQUANT)** : golden/`FROZEN_STATE_HASH`/`ContractV1` intacts. [Source: mémoire — drift_policy_strict_only, agent_desired_state_direction]
- **NFR4 — idempotence/reprise** : pas de traitement spécial « sortie d'indisponibilité ».
- **NFR7 — Postgres-only** : aucun AD/LdapRecord dans le chemin contrat.
- **Contrat agent figé (BLOQUANT)** : `se5.desired-state/v1` intact ; **pas de bump** `agent/shared/version.go`. [Source: mémoire — feedback_agent_edit_bump_version]
- **R3 — vocabulaire (BLOQUANT)** : aucun « central » ; `amont`/`Upstream`/`ControlHub*`. [Source: prd#R3]
- **Migrations ADDITIVES uniquement** (seulement si Q1=C) — jamais réécrire une migration. [Source: mémoire — vm_migrations_not_auto_applied]
- **Tests HÔTE uniquement** (php8.4 + `pdo_sqlite`, `CACHE_DRIVER=array`), filtres ciblés, jamais run massif VM. [Source: mémoires — phpunit_test_env_host_vs_vm, vm_phpunit_bulk_run_false_failures]
- **routes/api.php** — aucun endpoint attendu pour 32.2 ; si jamais ajouté, **APRÈS** le groupe 16.12 (fenêtre 1500 chars). [Source: mémoire — api_routes_arch_test_window_trap]
- **Racine = projet Laravel**. [Source: mémoire — root_is_laravel]

### Project Structure Notes

- **Nouveaux** : `tests/Feature/ControlHub/UpstreamUnavailabilityTest.php`. Si **Q1=B** : un helper sur `ControlHubContract` (ex. `isStale()`/`lastSeenAt()`) + clé de config TTL (ex. `config/controlHub.php`). Si **Q1=C** (déconseillé) : valeur d'enum + migration additive.
- **Modifiés** : potentiellement `app/Models/ControlHubContract.php` (helper dérivé, Q1=B) ; `app/Models/ControlHubLinkAuditLog.php` (constante `ORIGIN_SYSTEM`, **uniquement** Q1=C) ; `docs/qa/domains/controlhub-contract.md` (Section 18) ; `docs/qa/README.md` (ligne cumulative) ; `tests/Support/WpkgSchemaBootstrapper.php` (uniquement si Q1=C ajoute une colonne).
- **AUCUNE** modification de `active()`/`resolveActiveContract()` (chokepoint), `StateCompiler`/`StateMaille`, contrat agent/golden/`agent/**`, `ControlHubContractSeveranceService` (32.1).

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 32.2 (l.377-391)] — AC d'origine : panne → dernier contrat reste en vigueur (verrous maintenus) ; transition d'état → audit horodaté.
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#5.3 (l.88-91)] — cycle de vie : actif / **amont indisponible (dernier contrat reste en vigueur)** / rupture (release).
- [Source: prd-contrat-manage-se5.md#NFR3 (l.137), #NFR4 (l.138), #NFR5 (l.139)] — standalone / idempotence-reprise / auditabilité.
- [Source: _bmad-output/implementation-artifacts/32-1-release-verrous-rupture-lien.md] — service de rupture, table d'audit, event, enum (DÉPENDANCE en `review`).
- [Source: _bmad-output/codeReviews/32-1.md] — table d'audit append-only validée ; aucun finding bloquant repris en 32.2.
- [Source: app/Models/ControlHubContract.php:64-69] — `active()` ignore `received_at` (preuve non-libération).
- [Source: app/Services/ControlHub/ControlHubContractIngestionService.php:142-159,196] — pose/refresh `received_at`, no-op sur réception identique (preuve NFR4).
- [Source: app/Enums/ControlHubLinkState.php:18-22] — `{Active, Severed}` ; pas de `stale`.
- [Source: app/Models/ControlHubLinkAuditLog.php:53-54,84-124] — append-only, `from_state`/`to_state`/`origin` génériques, `ORIGIN_COMMAND`/`ORIGIN_API`.
- [Source: app/Models/ControlHubConnection.php:26-42,166-169] — transport/heartbeat outbound (DISTINCT du contrat — ne pas confondre).
- [Source: database/factories/ControlHubContractFactory.php:25,36-40] — `received_at = now()` par défaut, `notYetReceived()`.
- [Source: mémoires projet — drift_policy_strict_only, agent_desired_state_direction, feedback_agent_edit_bump_version, phpunit_test_env_host_vs_vm, vm_phpunit_bulk_run_false_failures, root_is_laravel, api_routes_arch_test_window_trap, feedback_no_overengineered_choices].

## Dépendances

- **Amont** :
  - **28.1** (`done`) — enum `ControlHubLinkState` + colonne `received_at` (fraîcheur) + factory `notYetReceived()`.
  - **28.2** (`done`) — `ControlHubContractIngestionService` : pose/refresh `received_at`, no-op sur réception identique (preuve AC5/NFR4).
  - **28.3** (`done`) — tier `StateMaille::Upstream` court-circuité par `active()` null (mécanisme prouvé en AC1 : tant qu'`active`, les verrous tiennent).
  - **29.x / 31.x** (`done`) — `CapabilityPolicy`, `UpstreamLockResolver`, `UpstreamCatalogResolver` (consommateurs qui restent verrouillés tant que le contrat est `active`).
  - **🔴 32.1** (`review` — **PAS `done`**) — `ControlHubContractSeveranceService` (chemin de rupture, réutilisé en test AC2), table append-only `controlhub_link_audit_logs` + modèle `ControlHubLinkAuditLog` (réutilisée telle quelle), event `ControlHubContractChanged`, enum `Severed`. **32.2 réutilise tout ceci ⇒ rebaser obligatoirement si la review 32.1 modifie le service / la table / l'enum / l'event.**
- **Aval** :
  - **Story 33.x** — schéma d'échange versionné (la forme d'un éventuel signal d'indisponibilité, si jamais l'amont en émet un, relèverait du contrat de données).

## Questions/Décisions Henri

> **T0 doit trancher ces points AVANT T3/T4.** Le SM propose des options tranchées (recommandation d'architecte) ; **ne pas présupposer**. La parcimonie est recherchée (mémoire `feedback_no_overengineered_choices`) : l'AC n'exige qu'une **non-libération** (gratuite) — tout marquage est optionnel.

**Q1 (CENTRALE) — Représentation de l'« indisponibilité » : rien / dérivé / état explicite ?**
L'indisponibilité = absence de MAJ de contrat. Le `received_at` existe déjà mais n'est exposé nulle part ; `active()` l'ignore.
- **Option A — pur preuve (rien de persisté ni dérivé)** : l'indisponibilité reste un **silence non matérialisé** ; la story se borne à **prouver** la non-libération + la couverture NFR5. Coût quasi nul, fidèle à « preuve, pas construction ». **Aucune observabilité** de la fraîcheur côté UI/API.
- **Option B — indicateur DÉRIVÉ lecture seule** : helper `isStale(?TTL)`/`lastSeenAt()` calculé depuis `received_at` (+ TTL Q2), **sans** toucher `active()` ni l'enforcement. Donne une observabilité (« amont injoignable depuis X »). Coût modéré, **aucune migration**, aucun risque d'enforcement si le garde-fou est respecté.
- **Option C — nouvel état d'enum `stale`/`unavailable` + bascule (job)** : matérialise l'indisponibilité. **Déconseillé** : migration, scheduler de décroissance, risque de couplage à l'enforcement, et l'AC **ne l'exige pas**.
- **Reco** : **Option A** (l'AC ne demande que la non-libération, déjà gratuite) ; **Option B** si tu veux une observabilité refnum, à condition que `active()` reste indépendant de la fraîcheur. **Pas d'Option C** sauf besoin métier explicite.

**Q2 — Critère de bascule « indisponible » / TTL (uniquement si Q1=B ou C).**
- **Reco** : aucun critère si Q1=A. Si Q1=B : TTL **dérivé de config** (ex. multiple de `heartbeat_interval` / clé `config/controlHub.php`), **lecture seule**, jamais persisté. Pas de défaut « dur » imposé : l'indicateur est informatif.

**Q3 — Périmètre de l'audit NFR5 : preuve de couverture vs audit élargi ?**
32.1 trace `active → severed`. Tant qu'il n'y a pas de 3ᵉ état, c'est l'**unique** transition.
- **Reco** : **preuve de couverture** (réutiliser `controlhub_link_audit_logs` telle quelle, prouver qu'`active → severed` est l'unique transition et que l'indisponibilité n'écrit aucun audit). **Élargir** (constante `ORIGIN_SYSTEM`, transitions `active↔stale`) **seulement** si Q1=C. **Pas** de nouvelle table dans tous les cas.

**Q4 — Détection panne vs rupture côté réception : passive (silence) ou signal explicite ?**
- **Reco** : **passive** au MVP. La rupture est un **signal explicite** (32.1) ; l'indisponibilité est l'**absence** de signal (silence) — SE5 ne reçoit jamais un « je suis indisponible ». Aucun nouveau chemin d'ingestion. (Un signal explicite d'indisponibilité relèverait d'Epic 33 / serait redondant.)

## Recommandation Modèle Dev

**`sonnet`.**

Justification : story à **dominante PREUVE**, surface étroite et **arbitrages déjà bornés** par l'investigation. (1) Le cœur (AC1/AC2/AC4/AC5) est de la **vérification par tests** d'un comportement **acquis par construction** — pas de design ouvert dense comme en 32.1. (2) Le **seul risque réel** (coupler `active()` à la fraîcheur) est **explicitement nommé et interdit** dans la story ; un modèle de coût moyen le respecte sans difficulté dès lors que le garde-fou est lu. (3) La construction n'est qu'**éventuelle et minimale** (helper dérivé lecture seule, Q1=B) — pas de transverse comme la matérialisation 32.1. (4) Les patrons à réutiliser (table d'audit 32.1, harnais de test, `ingest()` NFR4) sont **déjà établis**. (5) La discipline « ne pas sur-construire » est **inscrite** (Q1=A par défaut). Le dev-cycle routera la review vers le modèle **opposé** (opus/fable) pour vérifier l'angle mort « `active()` resté indépendant de la fraîcheur » + NFR3/agent figé. *(Bascule vers `opus` si Henri tranche Q1=C — l'état explicite réintroduit du design ouvert et des implications contrat agent.)*

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

Aucun debug log pertinent (13 tests verts dès le premier run, 0 erreur de construction).

### Completion Notes List

- Q1=A (pur preuve) actée par Henri : aucune construction, aucune migration, aucune API de fraîcheur.
- `tests/Feature/ControlHub/UpstreamUnavailabilityTest.php` créé — 13 tests, 53 assertions, tous verts.
- Preuve T1 : `active()` retourne non-null avec `received_at` vieux de 365 jours ; `isBounded()`/`isCapabilityLocked()` / `modify()` inchangés.
- Preuve T2 : silence = 0 écriture / 0 audit / 0 event ; seule la rupture explicite (32.1) libère.
- Preuve T3 (GARDE-FOU) : test anti-régression prouvant que `active()` ignore `received_at` nul/vieux/10 ans.
- Preuve T4 : enum à 2 cas → 1 seule transition → 32.1 la trace → couverture NFR5 complète par construction.
- Preuve T5 NFR4 : réception identique = no-op (`received_at` inchangé) ; réception différente = refresh + event.
- NFR3 standalone : 2 tests (zéro écriture, zéro requête table items en l'absence de contrat).
- QA : Section 18 appendée à `docs/qa/domains/controlhub-contract.md` ; ligne cumulative README suffixée.
- Intouchables confirmés : `active()`, enum, `ControlHubContractSeveranceService`, `ControlHubLinkAuditLog`, `agent/**`, golden, `FROZEN_STATE_HASH`, `ContractV1` — aucune ligne modifiée.
- Non-régression : 182 + 109 + 678 passed, 0 régression.

### File List

**Créé :**
- `tests/Feature/ControlHub/UpstreamUnavailabilityTest.php`

**Modifié :**
- `docs/qa/domains/controlhub-contract.md` (Section 18 appendée)
- `docs/qa/README.md` (ligne cumulative suffixée avec Story 32.2)
- `_bmad-output/implementation-artifacts/32-2-indisponibilite-amont-trace-lien.md` (status → review, T0-T8 cochés, Dev Agent Record)

**Aucune migration, aucun fichier de code applicatif modifié.**

## Change Log

| Date       | Version | Description                                  | Author |
|------------|---------|----------------------------------------------|--------|
| 2026-06-30 | 0.1     | Création de la story (SM/architecte BMAD, create-story) — ready-for-dev ; clôt l'Epic 32 (panne≠rupture + NFR5). Verdict : PREUVE dominante (non-libération gratuite via `active()` ignorant `received_at` ; `active→severed` unique transition déjà tracée par 32.1). Construction éventuelle minimale (helper dérivé lecture seule, gated Q1). Anti-pattern central interdit : coupler `active()` à la fraîcheur. ⚠️ Dépendance 32.1 en `review` (rebase si modif service/table/enum/event). 4 Questions Henri (Q1 représentation, Q2 TTL, Q3 audit, Q4 détection). Reco dev SONNET (→ opus si Q1=C). | claude-opus-4-8[1m] |
