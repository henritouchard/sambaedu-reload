# Story 29.5: Drift STRICT sur item verrouillé + audit des overrides

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **(a) GARANTIR — et PROUVER par test — qu'un item verrouillé amont est déjà soumis à la politique de drift STRICT inconditionnelle (réappliqué en cas de dérive, sans tolérance), et (b) CONSIGNER un événement d'audit horodaté à chaque fois qu'un refnum pose ou retire un override (par `workstationGroup`) sur une capacité — en distinguant l'override d'un item imposé-permissif d'un override purement local**,
so that **un réglage imposé ne puisse pas dériver en silence (NFR2) et que les écarts décidés localement par le refnum soient traçables (acteur, item, périmètre, horodatage) à des fins d'auditabilité (NFR5)**.

> **Story à DEUX moitiés de nature opposée.**
> **Moitié NFR2 (drift STRICT) = PREUVE, pas construction.** Un item `locked` est **DÉJÀ** soumis au drift STRICT **par construction** — il n'y a **aucun câblage d'enforcement à ajouter**. La politique « drift STRICT inconditionnel » a été livrée en **27.8** (suppression du mode `strict/default`, suppression de `drifted_allowed`, item du desired-state à **4 clés** `{type, semantics, payload, hash}` sans marqueur de mode). Un item amont `locked` est injecté à la maille `StateMaille::Upstream` (rang -1, inbattable — 28.3/29.3), compilé en item standard à 4 clés, **indistinguable** d'un item local côté contrat ; l'agent Go le réapplique sur **toute** divergence de hash (moteur `provision.Reconcile`). 29.5 **prouve cette chaîne** par un test de non-régression côté SE5 ; elle ne réintroduit **aucun** marqueur de drift, **aucun** toggle, **aucun** mode par-item (ce serait une régression de 27.8).
> **Moitié NFR5 (audit) = la VRAIE nouvelle livraison.** Aujourd'hui `saveOverride()` / `removeOverride()` écrivent `capability_assignments` **sans aucune trace d'audit** (constat vérifié ci-dessous). 29.5 ajoute une **table d'audit append-only maison** (patron `QuotaAuditLog` / `DelegationHistory` — `spatie/laravel-activitylog` **n'est pas** une dépendance du projet) + un modèle `CapabilityOverrideAuditLog::log()`, câblés dans les deux actions d'override.

> **⚠️ Décision Henri (rappel) — pas de compat ascendante exigée** : aucun environnement de prod à préserver (seul invariant intangible = enrôlement controlHub). Les garde-fous « non-régression » visent le **bon design** (sans contrat amont, le comportement est strictement celui d'avant 29.5 — NFR3 ; le contrat agent / golden / `FROZEN_STATE_HASH` restent intacts), pas la rétrocompat d'appelants exotiques. [Source: mémoires projet — zero_prod_publish_is_test, no_legacy_transition_state]

## Contexte du code (constat vérifié 2026-06-27)

### A. Drift STRICT — la chaîne EXISTE déjà, à PROUVER (ne rien construire)

- **Compilation à 4 clés, STRICT implicite** — `app/Services/Agent/StateCompiler.php` L.151-183 : commentaire L.152-154 « Story 27.8 : la clé `mode` est retirée (STRICT inconditionnel — plus d'agrégation de mode) » ; assemblage des **4 clés exactes** `type`, `semantics`, `payload`, `hash` (L.172-179). Le contrat d'item est figé à 4 clés dans `app/Services/Agent/Contracts/StateProvider.php` L.28-30 et **gardé par test** : `tests/Unit/Services/Agent/StateCompilerTest.php` L.128 `assertSame(['type','semantics','payload','hash'], array_keys($item))`. **Aucune** clé `drift` / `drift_policy` / `mode` n'existe — le STRICT est la règle **par défaut, sans marqueur**.
- **Injection d'un item amont `locked` à la maille inbattable** — `app/Services/ControlHub/Resolution/UpstreamContractSource.php` : `ensureResolved()` aiguille `enforcement_state === Permissive ? StateMaille::UpstreamPermissive : StateMaille::Upstream` (≈L.157-159). Un `locked` part donc à `StateMaille::Upstream` (rang **-1**, `StateCompiler::specificity()` L.405) — **plus spécifique que toute maille locale**, il gagne au compilé (prouvé par `tests/Feature/ControlHub/UpstreamContractResolutionTest.php` `upstream_beats_local_same_key`, `locked_wins_over_local_but_permissive_is_overridden_by_local`).
- **Statut serveur sans tolérance** — `app/Enums/AgentResourceStatus.php` : **3** statuts seulement `compliant` / `drift` / `error` (plus de `drifted_allowed`). `app/Services/Agent/Reporting/ConformityService.php` L.30-31 « Story 27.8 : `drifted_allowed` SUPPRIMÉ — la cible fait toujours loi ». `app/Services/Agent/StateCandidate.php` L.21-23 « mode strict/default SUPPRIMÉ — l'agent réapplique toujours l'état cible ».
- **Réapplication STRICT côté agent Go (déjà couverte)** — `agent/provision/provision.go` `Reconcile()` (≈L.89-144) : idempotence VRAIE par hash (skip si présent + hash valide), `apply` sur absence OU hash divergent. Tests `agent/shared/handler_printers_test.go` / `handler_shortcuts_test.go` : cas « dérive même dernier=cible → réapplique (strict) » (Story 27.8). Un item compilé étant **source-agnostique** (un item issu de l'amont est, après compilation, de forme identique à un item local), **ces tests couvrent déjà** la réapplication STRICT d'un item verrouillé — **aucun nouveau test Go n'est requis**.

> **Conclusion NFR2 :** un item `locked` est **déjà** soumis au drift STRICT **par construction** (maille Upstream inbattable → compilation 4 clés STRICT → moteur agent inconditionnel). Le seul livrable NFR2 de 29.5 est un **test de preuve / non-régression côté SE5** qui verrouille cette chaîne (un item amont `locked` produit un item de desired-state à 4 clés, sans marqueur de mode, portant la valeur amont).

### B. Audit des overrides — ABSENT aujourd'hui, à CONSTRUIRE

- **Surface d'écriture override-par-parc** — `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (Livewire SFC, `use WithToasts`) :
  - `saveOverride()` L.242-309 : recharge la capacité (`is_active`), applique le **refus amont 29.2** `authorizeUpstream($capability)` (L.270-272 — un `locked` est refusé ici, donc une écriture ne concerne JAMAIS un item verrouillé), calcule `$hasExistingOverride` (L.274-278 — **distingue déjà create vs update**), valide la valeur, puis `DB::table('capability_assignments')->updateOrInsert([... capability_id, assignable_type=WorkstationGroup::class, assignable_id=$parc->id], ['value'=>$value, ...])` L.294-301. **Aucune trace d'audit.** Acteur disponible via `Auth` ; périmètre = `$parc` (`WorkstationGroup`).
  - `removeOverride(int $capabilityId)` L.312-333 : refus amont 29.2 (L.321), puis `DB::table('capability_assignments')->where(...)->delete()` L.325-329. **Aucune trace d'audit.**
  - Statut amont d'une capacité déjà lisible via `app(UpstreamLockResolver::class)` : `isCapabilityPermissive()` / `capabilityUpstreamStatus()` (29.3/29.4) — réutilisable pour taguer l'audit `permissive` vs `local`.
- **Pivot écrit** — `capability_assignments` (migration `database/migrations/2026_06_18_100200_create_capability_assignments_table.php`) : polymorphe `assignable_type`/`assignable_id`, `value` nullable. **Aucune** colonne d'audit (et il ne doit pas en recevoir — l'audit est une table append-only séparée).
- **Patrons d'audit maison du projet (à IMITER)** :
  - `app/Models/QuotaAuditLog.php` : `public $timestamps=false`, `created_at` posé à la main, `casts` JSON pour `old_values`/`new_values`, **fabrique statique `log(...)`** (L.106-131), constantes `ACTION_*`. Migration `database/migrations/2026_02_20_100000_create_quota_tables.php` (`useCurrent()` sur `created_at`).
  - `app/Models/DelegationHistory.php` : table **append-only** (UPDATE → `LogicException`), FKs `nullOnDelete` pour survivre à la suppression des entités référencées, pas d'`updated_at`.
- **Pourquoi PAS le middleware HTTP** — `app/Http/Middleware/Auth/AuditExternalAction.php` (`ExternalActionAuditLog::record()`) n'audite **que les sessions fédérées** (`FederatedSession::isFederated()`) ; le refnum AD-local n'est **pas** couvert, et le canal Livewire (`POST /livewire/update`) n'est pas un point d'audit fiable [mémoire projet : audit_http_misses_livewire, story 20.6]. **L'audit de 29.5 doit donc être écrit explicitement dans le code métier** (dans `saveOverride()`/`removeOverride()`), pas délégué à un middleware.
- **`spatie/laravel-activitylog` ABSENT** — `composer.json` n'embarque que `spatie/laravel-permission`. Ne pas l'introduire (dépendance nouvelle hors scope) : suivre le patron maison.

## Acceptance Criteria

### Drift STRICT (NFR2) — preuve par construction

1. **Given** un contrat amont **actif** impose un item `registry` / **`locked`** / `instance` dont une clé correspond à une projection d'une `Capability`,
   **When** le desired-state est compilé pour un poste cible (via `StateCompiler`),
   **Then** l'item compilé correspondant porte la **valeur amont** (la maille `Upstream` gagne), expose **exactement les 4 clés** `type`, `semantics`, `payload`, `hash`, et **ne contient AUCUN** marqueur `mode` / `drift` / `drift_policy` (le STRICT est implicite et inconditionnel) — ce qui **prouve** que l'item verrouillé entre dans le pipeline de réapplication STRICT côté agent.

2. **Given** la politique drift STRICT inconditionnelle livrée en 27.8 (statuts `compliant`/`drift`/`error` ; pas de `drifted_allowed` ; moteur `provision.Reconcile` réapplique sur toute divergence de hash),
   **When** on vérifie le comportement de réconciliation côté agent,
   **Then** **aucune** modification du moteur agent ni du contrat n'est introduite par 29.5, et la couverture STRICT existante (`agent/shared/handler_*_test.go`, 27.8) est **citée comme preuve** de la réapplication d'un item verrouillé (un item compilé est source-agnostique) — **aucun** nouveau code/test Go n'est requis.

### Audit des overrides (NFR5)

3. **Given** un refnum enregistre un override par `workstationGroup` sur une capacité (via `saveOverride()`),
   **When** l'écriture de `capability_assignments` réussit,
   **Then** **un et un seul** événement d'audit horodaté est consigné dans une table dédiée append-only, contenant au minimum : l'**acteur** (id utilisateur + login dénormalisé), l'**item** (capability id + libellé dénormalisé), le **périmètre** (`assignable_type` = `WorkstationGroup`, `assignable_id`, + nom de groupe dénormalisé), l'**action** (`create` si nouvel override, `update` si override préexistant — dérivée de `$hasExistingOverride` déjà calculé L.274-278), les valeurs **avant/après** (`old_value`/`new_value`), le **statut amont** au moment de l'acte (`permissive` ou `local`, via `UpstreamLockResolver`) et un **horodatage** (`created_at`).

4. **Given** un refnum retire un override par `workstationGroup` (via `removeOverride()`),
   **When** la suppression de `capability_assignments` réussit,
   **Then** un événement d'audit horodaté est consigné avec `action = delete`, l'ancienne valeur en `old_value`, `new_value = null`, et les mêmes champs acteur/item/périmètre/statut-amont.

5. **Given** l'AC NFR5 vise explicitement l'**override permissif** (`enforcement_state = permissive`),
   **When** le refnum pose/retire un override sur une capacité imposée-permissive,
   **Then** l'événement d'audit porte `upstream_status = 'permissive'` ; **et** par cohérence (et pour un coût nul — la résolution du statut amont est faite de toute façon), un override sur une capacité **sans** contrainte amont est **aussi** audité avec `upstream_status = 'local'` (l'audit couvre tous les overrides de cette surface, en distinguant le cas permissif requis du cas local).

6. **Given** la table d'audit doit être un **registre fiable** (auditabilité NFR5),
   **When** un override est enregistré/retiré,
   **Then** l'écriture du pivot **et** l'écriture de l'audit sont effectuées dans une **même transaction** (`DB::transaction`) — si l'audit échoue, l'override n'est pas confirmé (cohérence acte ↔ trace) ; la table est **append-only** (pas d'`updated_at` ; un UPDATE est interdit/levé, patron `DelegationHistory`), et les FKs (`actor_user_id`, `capability_id`) sont `nullOnDelete` pour survivre à la suppression des entités référencées (les colonnes dénormalisées préservent la lisibilité).

7. **Given** aucun contrat amont actif (standalone), **OU** un item `absent`, **OU** un contrat `severed`,
   **When** un refnum pose/retire un override,
   **Then** l'audit est **toujours** consigné (l'audit trace l'acte local du refnum, indépendant du contrat) avec `upstream_status = 'local'`, et le **court-circuit NFR3 de `UpstreamLockResolver` est préservé** (sans contrat actif → aucune requête `controlhub_contract_items` supplémentaire pour résoudre le statut).

8. **Given** la suite de tests HÔTE (php8.4 + sqlite, `RefreshDatabase`),
   **When** elle s'exécute,
   **Then** sont **couverts et verts** : (a) **preuve drift** AC#1 (item amont `locked` → item compilé à 4 clés, valeur amont, sans marqueur de mode) ; (b) audit `create`/`update`/`delete` (champs acteur/item/périmètre/old/new/statut/horodatage) ; (c) tag `permissive` vs `local` (AC#5) ; (d) atomicité (échec d'audit simulé → override non persisté, AC#6) ; (e) append-only (UPDATE interdit) ; (f) audit présent en standalone avec `local` + court-circuit NFR3 (AC#7) ; (g) **non-régression** : `StateCompilerTest` (item 4 clés), suites 28.3/29.2/29.3/29.4 et `ControlHubContract*` **vertes** ; golden / `FROZEN_STATE_HASH` / `ContractV1` **inchangés**.

9. **Given** le garde-fou de vocabulaire R3,
   **When** on lit le code, les libellés et les identifiants introduits (table, modèle, colonnes, libellés FR),
   **Then** **aucun** mot « central » n'apparaît : vocabulaire « amont » / `Upstream` / `ControlHub*` ; libellés FR « override », « imposé », « permissif », « local », « verrouillé ».

## Tasks / Subtasks

- [x] **T0 — Cadrage : prouver (NFR2) vs construire (NFR5)** (AC: #1, #2, #3)
  - [x] Confirmer par lecture la chaîne drift STRICT existante (StateCompiler 4 clés / Upstream maille / AgentResourceStatus / provision.Reconcile) — **ne RIEN ajouter** côté enforcement/moteur/contrat. Documenter en Dev Notes que NFR2 = preuve.
  - [x] Confirmer l'absence d'audit dans `saveOverride()`/`removeOverride()` et le choix du patron maison (`QuotaAuditLog`/`DelegationHistory`), **pas** Spatie activitylog, **pas** le middleware fédéré.
  - [x] Figer le schéma d'audit (colonnes ci-dessous) et la décision d'atomicité (transaction pivot+audit).

- [x] **T1 — Test de preuve drift STRICT (NFR2)** (AC: #1, #2)
  - [x] Ajouter un test Feature (p.ex. `tests/Feature/ControlHub/UpstreamLockedDriftStrictTest.php`) : ingérer un contrat actif avec un item `registry`/`locked`/`instance` matchant une projection de capacité ; compiler le desired-state via `StateCompiler` pour un `TargetContext` ; **asserter** que l'item compilé a `array_keys === ['type','semantics','payload','hash']`, **ne possède pas** de clé `mode`/`drift`, et porte la **valeur amont** (maille Upstream gagne). Réutiliser les factories `ControlHubContract*` et le harnais 28.3.
  - [x] **Ne pas** dupliquer la couverture Go (citer `handler_*_test.go` 27.8 en référence). **Ne pas** introduire de marqueur de drift dans la sérialisation.

- [x] **T2 — Migration + modèle d'audit append-only** (AC: #3, #4, #5, #6, #9)
  - [x] Migration `database/migrations/2026_06_27_xxxxxx_create_capability_override_audit_logs_table.php` — table `capability_override_audit_logs` :
    - `id` ;
    - `actor_user_id` (`foreignId` nullable, `nullOnDelete`) + `actor_login` (string nullable, **dénormalisé**) ;
    - `action` (string : `create` | `update` | `delete`) ;
    - `capability_id` (`foreignId` nullable, `nullOnDelete`) + `capability_label` (string, **dénormalisé**) ;
    - `assignable_type` (string) + `assignable_id` (unsignedBigInteger) + `scope_label` (string nullable, **nom du groupe dénormalisé** = périmètre) ;
    - `old_value` (string/text nullable) + `new_value` (string/text nullable) ;
    - `upstream_status` (string : `permissive` | `local`) ;
    - `created_at` (`timestamp`, `useCurrent()`), **pas** d'`updated_at` ;
    - index : `actor_user_id`, `capability_id`, (`assignable_type`,`assignable_id`), `action`, `upstream_status`, `created_at`.
  - [x] Modèle `app/Models/CapabilityOverrideAuditLog.php` : `$timestamps=false`, `$fillable`, constantes `ACTION_CREATE|UPDATE|DELETE`, fabrique statique **`log(...)`** (calque `QuotaAuditLog::log`), garde **append-only** (UPDATE → `LogicException`, calque `DelegationHistory`). PHPDoc « amont »/«override», **aucun** « central ».

- [x] **T3 — Câblage de l'audit dans la surface override-par-parc** (AC: #3, #4, #5, #6, #7)
  - [x] `capabilities-tab.blade.php` `saveOverride()` (L.290-308) : envelopper `updateOrInsert` + appel `CapabilityOverrideAuditLog::log(...)` dans un `DB::transaction`. Dériver `action` de `$hasExistingOverride` (déjà calculé L.274-278). Capturer `old_value` (valeur d'override existante **avant** l'écriture — la lire avant l'`updateOrInsert`) et `new_value` (`$value`). Résoudre `upstream_status` via `app(UpstreamLockResolver::class)` (`isCapabilityPermissive($capability) ? 'permissive' : 'local'`). Acteur = `Auth::id()` + `Auth::user()?->login` (ou champ identifiant équivalent du modèle User). Périmètre = `$parc->id` + nom du groupe.
  - [x] `removeOverride()` (L.325-329) : même enveloppe transactionnelle ; lire la capacité + l'ancienne valeur **avant** le `delete` ; `action=delete`, `new_value=null`, `upstream_status` résolu de même.
  - [x] **Ne PAS** auditer la surface `parc-defaults` (défaut diffusé d'instance) : ce n'est pas un « override par workstationGroup » (hors scope NFR5/FR4 — voir Dev Notes). **Ne PAS** ajouter de toast/UX nouvelle (l'audit est invisible pour le refnum).

- [x] **T4 — Tests HÔTE de l'audit** (AC: #3–#8)
  - [x] Unit `tests/Unit/Models/CapabilityOverrideAuditLogTest.php` : `log()` crée la ligne avec tous les champs + `created_at` ; append-only (UPDATE → `LogicException`) ; FKs `nullOnDelete` (suppression user/capability → ligne conservée, dénormalisés intacts).
  - [x] Feature `tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php` (Livewire `Livewire::test`) : `saveOverride` nouvel item → 1 ligne `action=create`, old/new corrects, acteur/périmètre/statut corrects ; ré-`saveOverride` → `action=update`, `old_value` = valeur précédente ; `removeOverride` → `action=delete`, `new_value=null` ; capacité permissive → `upstream_status=permissive` ; capacité sans contrainte → `local` ; **standalone** (aucun contrat) → audit présent + `local` + **0 requête `controlhub_contract_items`** supplémentaire (compteur `DB::getQueryLog`) ; **atomicité** : forcer l'échec de l'insert d'audit (p.ex. contrainte) → l'override n'est **pas** persisté (rollback).
  - [x] Non-régression : `StateCompilerTest`, `UpstreamContractResolutionTest`, suites 29.2/29.3/29.4, `ControlHubContract*` → **0 régression**. Piège bootstrap tables Spatie en feature (users mockés sans `HasRoles` évitent le before-hook — cf. 29.2/29.4).

- [x] **T5 — Runbook QA (domaine `controlhub-contract`, Section 10)** (AC: #1, #3, #4, #5, #7)
  - [x] **Append** une **Section 10** à `docs/qa/domains/controlhub-contract.md` (29.4 = Section 9 ; numérotation stable, scénarios `### Scénario 10.M`). Couvrir : (a) preuve drift — un item `locked` amont gagne au compilé et est réappliqué (référence comportement agent STRICT 27.8) ; (b) override permissif posé → ligne d'audit (acteur/item/périmètre/horodatage, `upstream_status=permissive`) ; (c) override local → audit `local` ; (d) retrait d'override → audit `delete` ; (e) standalone → override audité `local`, aucun badge/contrainte amont. Enrichir le libellé du domaine dans `docs/qa/README.md` (mention 29.5 — drift STRICT prouvé + audit append-only des overrides, NFR2/NFR5).

- [x] **T6 — Validation finale**
  - [x] `php artisan test --filter "UpstreamLockedDriftStrict|CapabilityOverrideAuditLog|CapabilitiesOverrideAudit|StateCompiler|UpstreamContractResolution|CapabilitiesTab|ControlHubContract"` sur HÔTE → vert.
  - [x] Grep R3 : `grep -rin "central"` sur la migration, le modèle, `capabilities-tab.blade.php`, la doc QA ajoutée → **0** (hors commentaires garde-fou).
  - [x] Vérifier que le **contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1` sont inchangés** (aucun item de desired-state n'a gagné de clé ; l'audit est une table latérale). `php -l` sur PHP touché + compilation Blade du partial modifié.

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 29.5

**DANS** :
- **NFR2 (preuve)** : un **test de non-régression côté SE5** prouvant qu'un item amont `locked` produit un item de desired-state à **4 clés** sans marqueur de mode, portant la valeur amont (donc soumis au drift STRICT inconditionnel de 27.8). **Aucun** code d'enforcement nouveau.
- **NFR5 (construction)** : table append-only `capability_override_audit_logs` + modèle `CapabilityOverrideAuditLog::log()` + câblage transactionnel dans `saveOverride()`/`removeOverride()` (override **par workstationGroup**), traçant acteur/item/périmètre/old-new/statut-amont/horodatage, en distinguant `permissive` (requis) et `local`. Tests HÔTE + runbook QA Section 10.

**HORS** (ne pas déborder) :
- **Réintroduire un mode de drift / un `drifted_allowed` / un marqueur par-item** : ce serait une **régression de 27.8**. Le STRICT est inconditionnel et sans marqueur. Ne rien toucher au `StateCompiler` (4 clés), à `AgentResourceStatus`, au moteur Go.
- **Audit de la pose de verrou amont** : la pose/levée de verrou est une décision **amont** (ingestion du contrat, Epic 28) ; elle n'est pas un acte du refnum sur cette surface. Hors 29.5.
- **Audit de la rupture de lien (`severed`)** : NFR5 « rupture » → **Epic 32** (FR7, cycle de vie du lien). 29.5 ne trace que les **overrides** (FR4).
- **Audit de la surface `parc-defaults` (défaut diffusé d'instance)** : ce n'est pas un « override par workstationGroup ». Hors scope (note de couture documentée).
- **Ciblage par label / `target_type=label`** → Epic 30. 29.5 reste `instance`.
- **Aucune** modification du contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1`. **Racine = projet Laravel** (pas de préfixe `laravel/`). [Source: mémoire projet — root_is_laravel]

### Décisions de conception

- **Atomicité acte ↔ trace** : pivot + audit dans un même `DB::transaction` (AC#6). Pour un registre d'auditabilité (NFR5), la trace ne doit pas pouvoir manquer quand l'acte a eu lieu ; et inversement, une trace fantôme sans acte serait trompeuse. Le rollback en cas d'échec d'audit est le comportement correct pour un registre de conformité.
- **Audit `local` en plus du `permissif` requis** : résoudre `upstream_status` impose déjà un appel `UpstreamLockResolver` ; auditer aussi les overrides locaux est **gratuit**, plus robuste, et évite une branche conditionnelle fragile (« n'auditer que si permissif »). Ce n'est pas du gold-plating : c'est le design le plus simple ET conforme (le cas permissif requis par NFR5 est un sous-ensemble explicitement testé).
- **Dénormalisation** (`actor_login`, `capability_label`, `scope_label`) : patron `ExternalActionAuditLog`/`DelegationHistory` — la trace survit à la suppression/anonymisation des entités. FKs `nullOnDelete` (pas `cascade` : on conserve l'historique).
- **create vs update** : dériver de `$hasExistingOverride` (déjà calculé en L.274-278), **pas** du flag client `isEditing` (propriété publique hydratée, non fiable — même raisonnement que le garde serveur 29.2).
- **old_value** : à **lire avant** la mutation (`updateOrInsert`/`delete`) — sinon perdue.

### Garde-fous projet CRITIQUES

- **NFR2 ≠ nouvelle fonctionnalité** : résister à la tentation de « câbler le drift STRICT sur le verrou ». Il l'est déjà. Tout marqueur `drift`/`mode` ajouté à un item casse l'assertion `StateCompilerTest` (4 clés) et régresse 27.8. [Source: StateCompiler.php L.152-179 ; StateCompilerTest L.128]
- **NFR3 — court-circuit préservé** : la résolution `upstream_status` réutilise le `UpstreamLockResolver` mémoïsé ; sans contrat actif, **0 requête `controlhub_contract_items`**. Test révélateur (compteur). [Source: UpstreamLockResolver.php — court-circuit 29.2/29.4]
- **Pas de middleware** : l'audit s'écrit dans le code métier (action Livewire), car `AuditExternalAction` ne couvre que le fédéré et le canal Livewire n'est pas audité. [Source: app/Http/Middleware/Auth/AuditExternalAction.php ; mémoire projet — audit_http_misses_livewire]
- **Pas de Spatie activitylog** : dépendance absente ; suivre le patron maison. [Source: composer.json]
- **Append-only** : pas d'`updated_at` ; UPDATE interdit (LogicException). [Source: DelegationHistory.php]
- **Vocabulaire R3** : aucun « central ». [Source: prd-contrat-manage-se5.md#R3]
- **Tests HÔTE uniquement** : php8.4 + `pdo_sqlite`, `RefreshDatabase`, **jamais la VM** (worktree git → interdit). SQLite n'applique pas varchar/enum PG → tester des décisions/contenus de lignes, pas des bornes. [Source: mémoires projet — phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement]

### Patrons de référence à IMITER (ne rien réinventer)

- **`QuotaAuditLog`** [app/Models/QuotaAuditLog.php L.106-131] : `$timestamps=false`, `created_at` à la main, casts JSON, fabrique statique `log(...)`, constantes `ACTION_*`. → calque direct pour `CapabilityOverrideAuditLog`.
- **`DelegationHistory`** [app/Models/DelegationHistory.php] : append-only (UPDATE → LogicException), FKs `nullOnDelete`, pas d'`updated_at`. → garde append-only.
- **Migration `quota_audit_logs`** [database/migrations/2026_02_20_100000_create_quota_tables.php L.52-87] : `useCurrent()` sur `created_at`, index sur action/acteur/cible/created_at. → calque migration.
- **Statut amont** [UpstreamLockResolver::isCapabilityPermissive() — 29.3/29.4] : réutiliser pour le tag `permissive`/`local` (singleton mémoïsé, court-circuit NFR3).

### Architecture & conventions

- **Filesystem-router + Livewire SFC** : `saveOverride()`/`removeOverride()` sont des actions du composant Livewire en tête de `capabilities-tab.blade.php` ; 29.5 enveloppe leurs écritures d'une transaction + appel d'audit — **aucune** nouvelle route, **aucune** nouvelle modale. [Source: CLAUDE.md — routing]
- **`WithToasts`** déjà câblé ; 29.5 n'ajoute **pas** de toast (l'audit est silencieux). [Source: capabilities-tab.blade.php L.38]
- **PHP-FPM = www-admin** : sans impact (tests HÔTE). **VM** : 29.5 ajoute une migration → non auto-jouée en VM (sans impact tant que non déployée). [Source: mémoires projet — php_fpm_user_www_admin, vm_migrations_not_auto_applied]

### Project Structure Notes

- **Nouveaux** :
  - `database/migrations/2026_06_27_xxxxxx_create_capability_override_audit_logs_table.php`
  - `app/Models/CapabilityOverrideAuditLog.php`
  - `tests/Feature/ControlHub/UpstreamLockedDriftStrictTest.php`
  - `tests/Unit/Models/CapabilityOverrideAuditLogTest.php`
  - `tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php`
- **Modifiés** :
  - `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (transaction + audit dans `saveOverride()`/`removeOverride()`)
  - `docs/qa/domains/controlhub-contract.md` (Section 10 append) + `docs/qa/README.md` (libellé domaine)
- **Inchangés (à PROUVER)** : `StateCompiler` (4 clés), `AgentResourceStatus`, moteur Go, contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1`, `capability_assignments` (pas de colonne ajoutée). **Racine = projet Laravel**. [Source: mémoire projet — root_is_laravel]

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 29.5 (L.220-234)] — AC d'origine (NFR2 drift STRICT réapplication ; NFR5 audit horodaté acteur/item/périmètre).
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md L.41, L.44, L.75, L.78, L.88-89] — NFR2 « item verrouillé soumis au drift STRICT existant » ; NFR5 « overrides » (Epic 29) / « rupture » (Epic 32).
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md L.136 (NFR2), L.139 (NFR5)] — drift STRICT « réapplication, pas de dérive tolérée » ; auditabilité « pose de verrou, override permissif, rupture de lien → tracés ».
- [Source: app/Services/Agent/StateCompiler.php L.151-183 ; Contracts/StateProvider.php L.28-30 ; tests/Unit/Services/Agent/StateCompilerTest.php L.128] — item à 4 clés, STRICT implicite (27.8).
- [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php (injection locked→Upstream rang -1) ; app/Enums/StateMaille.php ; StateCompiler::specificity() L.405] — l'item verrouillé gagne au compilé.
- [Source: app/Enums/AgentResourceStatus.php ; app/Services/Agent/Reporting/ConformityService.php L.30-31 ; app/Services/Agent/StateCandidate.php L.21-23 ; agent/provision/provision.go ; agent/shared/handler_printers_test.go] — STRICT inconditionnel, plus de `drifted_allowed` (27.8).
- [Source: resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php L.242-333] — `saveOverride()`/`removeOverride()` (écritures sans audit ; `$hasExistingOverride` L.274-278 ; refus amont 29.2).
- [Source: app/Models/QuotaAuditLog.php ; app/Models/DelegationHistory.php ; database/migrations/2026_02_20_100000_create_quota_tables.php] — patrons d'audit append-only maison.
- [Source: app/Http/Middleware/Auth/AuditExternalAction.php ; composer.json] — audit fédéré only ; pas de Spatie activitylog → audit en code métier, patron maison.
- [Source: app/Services/ControlHub/UpstreamLockResolver.php — isCapabilityPermissive/capabilityUpstreamStatus (29.3/29.4)] — tag `permissive`/`local`, court-circuit NFR3.
- [Source: _bmad-output/implementation-artifacts/29-2-…, 29-3-…, 29-4-….md] — gate amont, relaxation permissive, dispositif badge, pièges tests (bootstrap Spatie, SQLite).
- [Source: mémoires projet — phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement, root_is_laravel, audit_http_misses_livewire, drift_policy_strict_only, permissive_floor_least_specific, zero_prod_publish_is_test, no_legacy_transition_state].

## Dépendances

- **Amont (consommées — code livré sur la branche `worktree-contract-CH`)** :
  - **Story 27.8** (drift STRICT inconditionnel — item 4 clés, plus de `drifted_allowed`). **Dépendance de fondation NFR2** : 29.5 **prouve** son effet sur un item verrouillé, ne le réimplémente pas. Code sur `main`.
  - **Story 28.3** (résolution AMONT > local dans `StateCompiler` via `UpstreamContractSource`). **Dépendance dure NFR2** : c'est elle qui fait gagner l'item `locked` au compilé. `review`.
  - **Story 29.2** (refus d'override d'un item verrouillé — `authorizeUpstream`). **Cohérence** : garantit qu'un override audité ne concerne jamais un `locked`. `review`.
  - **Story 29.3/29.4** (`UpstreamLockResolver::isCapabilityPermissive`/`capabilityUpstreamStatus`). **Dépendance dure NFR5** : tag `permissive`/`local` + court-circuit NFR3. `review`/`backlog`.
  - **Epic 28** (modèle `ControlHubContract*` + enums + factories). **Dépendance dure** (preuve drift + résolution statut). `review`.
  - **Epic 27** (capacités, `capability_assignments`, surface `capabilities-tab`). **Livré et stable.**
- **Prérequis fourni à (aval)** :
  - **Epic 32** (cycle de vie du lien & release) — réutilisera le **patron d'audit append-only** de 29.5 pour tracer la rupture (NFR5 « rupture »).
- **Note de statut** : les stories 28.x/29.x consommées sont en `review`/`backlog` mais leur **code est livré** sur la branche courante ; 29.5 peut démarrer. Re-synchroniser si une correction de review modifie `StateCompiler`/`UpstreamContractSource`/`UpstreamLockResolver`/`capabilities-tab`.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, trait `RefreshDatabase`. **Jamais la VM.** [Source: mémoire projet — phpunit_test_env_host_vs_vm]
- Filtres ciblés : `php artisan test --filter "UpstreamLockedDriftStrict|CapabilityOverrideAuditLog|CapabilitiesOverrideAudit|StateCompiler|UpstreamContractResolution|CapabilitiesTab|ControlHubContract"`.
- Couverture obligatoire :
  - **Preuve drift STRICT** : item amont `locked` → item compilé à 4 clés exactes, sans marqueur `mode`/`drift`, valeur amont (Upstream gagne).
  - **Audit** : `create`/`update`/`delete` avec acteur/item/périmètre/old-new/statut/horodatage ; tag `permissive` vs `local` ; atomicité (échec audit → rollback override) ; append-only (UPDATE interdit) ; FKs `nullOnDelete` ; standalone → audit `local` + 0 requête `items` (court-circuit NFR3).
  - **Non-régression** : `StateCompilerTest` (4 clés), 28.3/29.2/29.3/29.4, `ControlHubContract*`, golden/`FROZEN_STATE_HASH`/`ContractV1` → 0 changement.
- **Pièges** : SQLite n'applique pas varchar/enum PG → tester contenus/décisions, pas bornes ; bootstrap tables Spatie en feature (users sans `HasRoles` l'évitent — cf. 29.2/29.4) ; lire `old_value` **avant** la mutation ; ne pas réintroduire de marqueur de drift (régression 27.8).
- ⚠️ **VM** : 29.5 ajoute **une migration** → non auto-jouée (sans impact tant que non déployée ; `migrate:status` avant tout e2e base). [Source: mémoire projet — vm_migrations_not_auto_applied]

## Recommandation Modèle Dev

**`opus`.**

Justification : la difficulté de 29.5 n'est **pas** volumétrique mais de **jugement**. (1) La moitié NFR2 exige de **reconnaître qu'il ne faut RIEN construire** (l'item verrouillé est déjà STRICT par construction depuis 27.8) et de **résister** à la tentation d'ajouter un marqueur/toggle de drift qui régresserait 27.8 et casserait l'invariant « item à 4 clés » — c'est un raisonnement de préservation d'architecture, pas d'exécution. (2) La moitié NFR5 est un **registre d'auditabilité de conformité** : atomicité acte↔trace (transaction + rollback), append-only, dénormalisation pour survie, dérivation fiable create/update (côté serveur, pas du flag client), tag `permissive`/`local` sans casser le court-circuit NFR3. Ces choix touchent à la fiabilité d'un registre de sécurité et à des invariants moteur (golden/contrat agent intacts), cohérents avec le routage **opus** de 29.2/29.3. Le dev-cycle routera la review vers le modèle opposé (sonnet) pour vérifier les angles morts (marqueur de drift réintroduit par mégarde, audit non-atomique, `old_value` lue après mutation, 2ᵉ requête `items` cassant NFR3, audit débordant sur `parc-defaults`/rupture).

## Dev Agent Record

### Modèle Dev

`claude-opus-4-8[1m]` (Dev BMAD). Review recommandée : modèle opposé `sonnet`.

### Implementation Plan / Décisions

- **NFR2 = preuve, pas construction (T0/T1)** : confirmation par lecture que la chaîne drift STRICT existe déjà (StateCompiler item 4 clés L.151-183 ; `UpstreamContractSource` injecte `locked → StateMaille::Upstream` rang -1 ; `AgentResourceStatus` 3 statuts ; moteur Go inconditionnel). **AUCUN** ajout côté moteur/contrat. Livrable = `UpstreamLockedDriftStrictTest` (1 test) calqué sur le harnais `UpstreamContractResolutionTest` : item amont `locked`/`registry`/`instance` vs candidat local divergent → item compilé `array_keys === ['type','semantics','payload','hash']`, **aucune** clé `mode`/`drift`/`drift_policy`, valeur amont gagne. La couverture Go 27.8 est citée (item compilé source-agnostique) — aucun test Go.
- **NFR5 = construction (T2/T3/T4)** : table append-only `capability_override_audit_logs` + modèle `CapabilityOverrideAuditLog` (calque `QuotaAuditLog::log()` + garde append-only `DelegationHistory`). Câblage transactionnel dans `saveOverride()`/`removeOverride()` : `old_value` lue AVANT la mutation, `action` dérivée de `$hasExistingOverride` (serveur, pas du flag client), `upstream_status` via `app(UpstreamLockResolver::class)->isCapabilityPermissive()` (court-circuit NFR3 préservé). `parc-defaults` NON audité ; aucun toast nouveau.
- **Déviation assumée — résolution de l'acteur (`resolveActor()`)** : l'acteur n'est persisté (`actor_user_id`) que si `Auth::user() instanceof App\Models\User`. Motif : **intégrité de la FK** `actor_user_id` (`nullOnDelete`). En production `Auth::user()` est toujours un `User` (refnum AD-local) → id + login capturés ; les tests sœurs 29.2/29.4 (mock `Authenticatable` sans HasRoles, pour éviter le before-hook Spatie) écrivent désormais un audit avec acteur `null` (FK respectée) — d'où **zéro régression** sur ces suites. Le test d'audit `CapabilitiesOverrideAuditTest` utilise un **vrai** `User` (permission `app.customize` seedée) pour prouver la capture acteur + satisfaire la FK.
- **Atomicité (AC#6)** : pivot + audit dans un seul `DB::transaction`. Test révélateur : `Schema::drop` de la table d'audit → l'INSERT lève → rollback → `capability_assignments` non persisté.

### Completion Notes

- Tests **ciblés** : 11/11 verts (`UpstreamLockedDriftStrict` 1, `CapabilityOverrideAuditLog` 3, `CapabilitiesOverrideAudit` 7).
- **Non-régression** : 181/181 verts sur `StateCompiler|UpstreamContractResolution|CapabilitiesTab|ControlHubContract|PermissiveOverride|UpstreamLockResolver|ParcDefaults|ContractV1|CapabilityPolicy` — **0 régression** (27.8 item 4 clés / 28.3 / 29.2 / 29.3 / 29.4 intacts).
- **Invariants** : `StateCompilerTest` (item 4 clés) vert ; `ContractV1Test` vert ; **aucun** fichier golden/`FROZEN_STATE_HASH`/`ContractV1` modifié (vérifié par `git status`). Court-circuit NFR3 préservé (0 requête `controlhub_contract_items` en standalone, prouvé par `DB::getQueryLog`).
- **R3** : `grep -rin "central"` sur les fichiers nouveaux/modifiés → uniquement commentaires garde-fou (zéro identifiant/libellé).
- `php -l` OK sur le modèle + la migration ; le SFC Blade est validé par compilation (tests Livewire `CapabilitiesTab*` verts).
- ⚠️ **À la charge de Henri (VM)** : la migration `2026_06_27_120000_create_capability_override_audit_logs_table.php` n'est PAS auto-jouée en VM (worktree git → pas d'interaction VM). `php artisan migrate` requis avant tout e2e base sur la VM.

### File List

**Créés** :
- `database/migrations/2026_06_27_120000_create_capability_override_audit_logs_table.php`
- `app/Models/CapabilityOverrideAuditLog.php`
- `tests/Feature/ControlHub/UpstreamLockedDriftStrictTest.php`
- `tests/Unit/Models/CapabilityOverrideAuditLogTest.php`
- `tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php`

**Modifiés** :
- `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (imports `Auth`/`User`/`CapabilityOverrideAuditLog` ; `saveOverride()`/`removeOverride()` enveloppés en `DB::transaction` + audit ; helper `resolveActor()`)
- `docs/qa/domains/controlhub-contract.md` (Section 10 append)
- `docs/qa/README.md` (libellé domaine — mention 29.5)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (29-5 → review)

### Change Log

- 2026-06-27 — Story 29.5 implémentée (Dev BMAD `claude-opus-4-8[1m]`). NFR2 prouvé par test (item amont `locked` → desired-state 4 clés sans marqueur de mode) ; NFR5 livré (table + modèle d'audit append-only `CapabilityOverrideAuditLog` câblé transactionnellement dans les overrides par parc). 11 tests ciblés + 181 non-régression verts, 0 régression. Status → review.
