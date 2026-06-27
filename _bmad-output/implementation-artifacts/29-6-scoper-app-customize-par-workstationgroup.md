# Story 29.6: Scoper `app.customize` par WorkstationGroup

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **que l'écriture d'overrides de capacité par parc (onglet « Options / Capacités ») soit autorisée par `WorkstationGroup` et non par un droit `app.customize` GLOBAL, et que `groupId` ne soit plus falsifiable côté client**,
so that **un refnum disposant d'`app.customize` ne puisse écrire/retirer (ni faire auditer) un override que sur les parcs de son périmètre, jamais sur un autre parc en altérant `groupId` (NFR1, cohérence de l'enforcement par périmètre, prérequis d'Epic 31)**.

> Story **de durcissement / correctif d'autorisation** — follow-up tracé de la review 29.5 (**M4🟠**, signalé par le 2ᵉ avis Opus, manqué par sonnet). Elle **ne touche pas** le contrat amont (Epic 28/30) ni le moteur de résolution : c'est l'alignement de la **dernière surface d'écriture de capacité** sur le patron de Gate scopé déjà livré par **29.1** (`wpkg.*`) et **29.2** (`modify-capability`).
>
> **⚠️ Décision Henri (rappel 29.1, 2026-06-26) — pas de compat ascendante exigée** : aucun environnement de prod à préserver ; seul invariant intangible = l'enrôlement controlHub. Les garde-fous « non-régression » ci-dessous visent le **bon design** (fallback global admin = sémantique *voulue* des délégations), pas la rétrocompat. [Source: mémoires projet — zero_prod_publish_is_test, no_legacy_transition_state]

## Contexte du trou (constat de code vérifié 2026-06-27)

La review 29.5 (`_bmad-output/codeReviews/29-5.md`, **M4**) a tracé un gap **pré-existant** (gap connu `project_delegation_enforcement_wpkg_gap`) sur l'onglet capacités d'un parc :

- `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` autorise les 4 mutations d'override (`openAdd` L.190, `openEdit` L.211, `saveOverride` L.245, `removeOverride` L.354) via le garde privé `guardCustomize()` [L.487-494], qui vérifie `app.customize` **GLOBALEMENT** :
  ```php
  abort_unless(auth()->check() && auth()->user()->can('app.customize'), 403, …);
  ```
  → jamais par `WorkstationGroup`.
- `groupId` [L.44] est une propriété **publique Livewire hydratée côté client**, **sans** `#[Locked]`. Un refnum peut donc (a) **falsifier `groupId`** pour viser un autre parc ET (b) bénéficier du fait que l'autorisation est **globale** → écrire/retirer/auditer un override **hors de son périmètre**.

**Chaîne d'autorisation actuelle (vérifiée) — pourquoi la page parente ne protège pas ce composant :**
- Route `routes/web.php` L.211 (`/groups/{id}`) : middleware `can:viewAny-workstationGroup` = **grossier** (tout délégué avec ≥1 délégation positive passe — Story 7.2 AC8).
- Page `resources/views/pages/parc/groups/[id]/index.blade.php` `mount()` L.142 : `Gate::allows('view', $this->group)` = fin par ressource MAIS c'est `computer.view` (**lecture**), pas `app.customize`.
- `capabilities-tab` est un composant Livewire **séparé**, monté `:group-id="$group->id"` [index.blade.php:2026]. Ses requêtes Livewire (`saveOverride`/`removeOverride`/…) ont leur **propre cycle** : le `mount()` gaté de la page parente **NE re-garde PAS** ces requêtes. D'où la nécessité d'un garde scopé **propre au composant** + `#[Locked]`.

**Conclusion d'analyse (intangible — à respecter) :** `#[Locked]` **seul NE SUFFIT PAS** (il fige le tampering de `groupId`, mais l'autorisation reste globale et adossée à un droit non scopé) ; un gate scopé **seul** ne suffit pas non plus si `groupId` reste falsifiable. **Le fix = LES DEUX** : `#[Locked]` sur `groupId` (figer le périmètre serveur-autoritatif) **+** rendre `guardCustomize()` per-scope via une policy/gate scopé (defense-in-depth, comme 29.2).

**Important — `app.customize` reste un droit Spatie GLOBAL** (route `/app-customizations` L.175-177 `middleware('can:app.customize')`). **Aucune migration ni nouvelle permission** : la délégation per-groupe est portée par le **système de délégation existant** (`PermissionService::canOnWorkstationGroup`), pas par une nouvelle permission Spatie.

## Acceptance Criteria

1. **Given** un refnum non-admin disposant d'une délégation **positive active** `app.customize` sur le parc **physique A** (et pas sur le parc B),
   **When** il déclenche une mutation d'override (`openAdd` / `openEdit` / `saveOverride` / `removeOverride`) sur l'onglet capacités **du parc A**,
   **Then** l'action est **autorisée** ;
   **And When** il déclenche la même mutation **sur le parc B** (composant monté avec `groupId = B`),
   **Then** l'action est **refusée** (403 / `AuthorizationException`), **sans** écriture dans `capability_assignments` **ni** trace d'audit 29.5.

   > **⚠️ Note post-review (review 29.6, P1 — 2 avis concordants).** Le volet **SÉCURITÉ** d'AC#1 est satisfait et prouvé : le guard scopé `customize-workstationGroup` refuse le parc B (403, 0 écriture, 0 trace), l'exclusion négative est honorée, `#[Locked]` interdit le tampering de `groupId`. En revanche le volet **HABILITATION** (« l'action est autorisée sur A ») n'est livré que pour un acteur portant le droit **global** `app.customize` (admin/technicien, ou détenteur global restreint par négative = l'acteur de la menace M4). Un **délégué positif-seul** (délégation scopée sur A, SANS droit global) passe le guard scopé (mount/onglet OK) mais reste **bloqué à l'écriture** par le **plancher GLOBAL** `app.customize` du gate `modify-capability` (29.2), appelé par `authorizeUpstream()`. Ce plancher n'est PAS scopable dans 29.6 sans empiéter sur 29.2 : `modify-capability` est **dual-purpose** (override par-parc ici + défaut diffusé global dans `parc-defaults/registry-tab`). L'habilitation pleine du délégué positif-seul est donc **reportée au suivi `29-8`** (« scoper le plancher de `modify-capability` par surface »), prérequis réel de cet AC et de l'enforcement par périmètre d'Epic 31. 29.6 reste DONE en tant que **correctif de sécurité M4**.

2. **Given** un administrateur/technicien disposant du droit **global** Spatie `app.customize`,
   **When** il agit sur **n'importe quel** parc,
   **Then** l'action reste **autorisée partout** (non-régression : fallback global préservé, iso patron `WorkstationGroupPolicy::assignWpkg`/`manage`).

3. **Given** un refnum avec une délégation `app.customize` sur le parc A **ET une exclusion négative active** `app.customize` sur ce même parc A,
   **When** il tente une mutation d'override sur A,
   **Then** l'action est **refusée** (l'exclusion négative prévaut, même sur droit global — comportement déjà porté par `canOnWorkstationGroup`).

4. **Given** un refnum dont la délégation `app.customize` sur le parc A est **expirée** (`expires_at` dépassé),
   **When** il tente une mutation d'override sur A,
   **Then** l'action est **refusée** (le scope `->active()` exclut les délégations expirées).

5. **Given** un parc **LOGIQUE** (`is_physical = false`),
   **When** le gate scopé s'évalue,
   **Then** la délégation **ne s'applique pas** et la décision se rabat sur le **droit global** `app.customize` (convention `canCheckDelegation` — identique à `view`/`manage`/`assignWpkg`, **à porter telle quelle, ne pas « corriger »**).

6. **Given** `groupId` est désormais `#[Locked]`,
   **When** un client tente de muter `groupId` via une requête Livewire (`$set('groupId', …)` / payload falsifié),
   **Then** Livewire lève `CannotUpdateLockedPropertyException` (le périmètre est figé à la valeur d'hydratation du `mount`) ;
   **And** (defense-in-depth) `guardCustomize()` résout le `WorkstationGroup` **côté serveur** depuis `$this->groupId` et passe ce modèle au gate scopé — **sans se fier** à aucun flag client.

7. **Given** les durcissements de la story,
   **When** la suite tourne,
   **Then** **aucune nouvelle permission Spatie** ni **migration** n'est introduite ; l'**audit 29.5** trace toujours fidèlement `assignable_id` (inchangé) ; le **verrou amont 29.2** (`locked` refusé via `authorizeUpstream`), l'**override permissif 29.3** et les **badges tri-état 29.4** restent **intacts** ; le **court-circuit NFR3** (standalone sans contrat) reste **byte-identique** (la story ne touche ni `UpstreamLockResolver` ni le rendu).

8. **Given** la suite de tests HÔTE (php8.4 + sqlite),
   **When** elle s'exécute,
   **Then** les cas positif scopé / négatif hors-périmètre / négative active / expirée / admin global (fallback) / parc logique (global) / tentative de tampering `groupId` (`CannotUpdateLockedPropertyException`) sont **couverts et verts**, **sans aucune régression** des suites existantes (`CapabilitiesTab`, `CapabilityPolicy`, `CapabilitiesOverrideAudit`, `PermissiveOverride`, `ParcDefaults`, `UpstreamLockResolver`).

## Tasks / Subtasks

- [x] **T0 — Cadrage du périmètre de `app.customize`** (AC: #1, #7)
  - [x] Confirmer par grep que la surface à scoper dans cette story est **UNIQUEMENT** `capabilities-tab.blade.php` (override de capacité par parc, `assignable = WorkstationGroup`). Sites de `guardCustomize()` à scoper : L.63 (`mount`), L.192 (`openAdd`), L.213 (`openEdit`), L.247 (`saveOverride`), L.356 (`removeOverride`).
  - [x] **Documenter** les surfaces `app.customize` **HORS-scope** (gardes SÉPARÉES, non servies par ce `guardCustomize`) : `resources/views/pages/parc/groups/_partials/associations-tab.blade.php` (sa **propre** copie `guardCustomize` L.53), et `resources/views/pages/parc/groups/[id]/index.blade.php` (`@can('app.customize')` L.1969, `->can('app.customize')` L.1239). Elles relèvent du **même gap** mais ont leur propre garde → **report explicite** (à scoper séparément si requis ; ne pas déborder ici). La route `/app-customizations` (L.175-177) reste **globale** (réglage produit par-établissement, pas par-parc) — **ne pas sur-scoper**.
  - [x] **Décision** : scoper la **seule** surface override-par-parc (`capabilities-tab`), la seule où `groupId` client-hydraté ouvre une écriture hors-périmètre.

- [x] **T1 — Gate scopé `customize-workstationGroup` via `WorkstationGroupPolicy`** (AC: #1, #2, #3, #4, #5)
  - [x] Ajouter une méthode `customize(?Authenticatable $user, ?WorkstationGroup $group = null): bool` dans `app/Policies/WorkstationGroupPolicy.php`, **calquée à l'identique** sur `assignWpkg()` [L.156-164] / `manage()` [L.124-132] :
    - si `$group !== null` **et** `canCheckDelegation($user, $group)` (i.e. `App\Models\User` + `$group->is_physical`) → `app(PermissionService::class)->canOnWorkstationGroup($user, 'app.customize', $group)` ;
    - sinon → fallback global `hasPermission($user, 'app.customize')` (trait `ChecksPermissions`).
  - [x] Enregistrer le gate : ajouter `'customize-workstationGroup' => 'customize'` dans `WorkstationGroupPolicy::$gates` [L.30-39]. (Enregistrement **auto** via `RegistersGates::registerGates()` déjà appelé `AuthServiceProvider` L.57 — **rien d'autre à câbler** ; pas de singleton `AgentServiceProvider` requis ici, contrairement à 29.2 dont le DI servait le `UpstreamLockResolver`, pas le gate.)
  - [x] PHPDoc : rappeler que l'exclusion négative et l'expiration sont **déjà** honorées par `canOnWorkstationGroup` (ne pas réimplémenter), que le fallback global préserve la non-régression admin, et que la convention **physique→délégation / logique→global** est volontaire (`canCheckDelegation`).
  - [x] **Ne pas** créer de policy/service dédié ni de permission Spatie : réutilisation pure du patron `assignWpkg`.

- [x] **T2 — `#[Locked]` sur `groupId` + réordonnancement du `mount`** (AC: #6)
  - [x] Importer `use Livewire\Attributes\Locked;` et annoter `#[Locked] public int $groupId;` [L.44] (Livewire 4.3 — `vendor/livewire/livewire/src/Attributes/Locked.php` présent ; lève `CannotUpdateLockedPropertyException` sur toute mutation client). L'hydratation initiale via le **paramètre du `mount`** reste autorisée.
  - [x] **Réordonner `mount()`** [L.61-65] : assigner `$this->groupId = $groupId;` **AVANT** `$this->guardCustomize();` (aujourd'hui l'inverse — le garde scopé a besoin de `$this->groupId` pour résoudre le `WorkstationGroup`). Vérifier qu'aucune autre lecture de `$this->groupId` ne précède l'assignation.

- [x] **T3 — `guardCustomize()` scopé (résolution serveur-autoritative + gate scopé)** (AC: #1, #5, #6)
  - [x] Réécrire `guardCustomize()` [L.487-494] pour : (a) résoudre le `WorkstationGroup` **côté serveur** depuis `$this->groupId` (`WorkstationGroup::find($this->groupId)`) — **jamais** depuis un flag/argument client ; (b) abort 403 si non authentifié OU si le gate scopé refuse :
    ```php
    abort_unless(
        auth()->check() && Gate::allows('customize-workstationGroup', WorkstationGroup::find($this->groupId)),
        403,
        'Permission app.customize requise sur ce parc.',
    );
    ```
  - [x] Conserver la **sémantique d'abort 403** existante (les 4 mutations + le `mount` s'appuient dessus ; pas de nouveau toast — l'abort 403 est le comportement actuel). `Gate` est **déjà importé** [L.12].
  - [x] `WorkstationGroup::find()` renvoyant `null` (id inexistant) → le gate reçoit `null` → fallback global (seul l'admin passe) : pas de fausse ouverture. Ajouter un commentaire explicite (cas marginal, cohérent avec le rabat `null` de `assignWpkg`).
  - [x] **Ne pas** modifier `authorizeUpstream()` (29.2) ni le câblage d'audit 29.5 : `guardCustomize()` reste appelé **en tête** de chaque mutation, **avant** `authorizeUpstream()` (ordre actuel inchangé).

- [x] **T4 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#8)
  - [x] **Policy unitaire** — `tests/Unit/Policies/WorkstationGroupPolicyCustomizeTest.php` (ou étendre `WorkstationGroupPolicyWpkgTest`) :
    - délégué positif actif `app.customize` sur A → `customize(user, A) === true`, `customize(user, B) === false` (AC #1) ;
    - admin global `app.customize` → `true` sur A, B, et `customize(user, null) === true` (AC #2) ;
    - exclusion négative active sur A (même avec global) → `false` (AC #3) ;
    - délégation expirée sur A (`expires_at = now()->subDay()`) → `false` (AC #4) ;
    - parc **logique** (`is_physical = false`) → fallback global uniquement, délégation ignorée (AC #5) ;
    - user nu (sans droit) → `false`.
  - [x] **Feature Livewire** — `tests/Feature/Livewire/Parc/CapabilitiesTabCustomizeScopingTest.php` (`Livewire::test(... , ['groupId' => …])`) :
    - `actingAs(délégué de A)` : `saveOverride`/`removeOverride`/`openAdd`/`openEdit` sur le composant monté `groupId = A` passent ; montés `groupId = B` → 403 (`assertForbidden()` ou exception), **et** `assertDatabaseCount('capability_assignments', …)` / `assertDatabaseMissing('capability_override_audit_logs', …)` confirment **aucune écriture ni trace** (AC #1) ;
    - `actingAs(admin global)` : passe sur A et B (AC #2) ;
    - **tampering** : `Livewire::test(..., ['groupId' => A])->set('groupId', B)` → `CannotUpdateLockedPropertyException` (AC #6). [`Livewire\Exceptions\CannotUpdateLockedPropertyException`]
  - [x] **Non-régression** : exécuter `CapabilitiesTab|CapabilityPolicy|CapabilitiesOverrideAudit|PermissiveOverride|ParcDefaults|UpstreamLockResolver` et confirmer **0 régression** (verrou 29.2, permissif 29.3, badges 29.4, audit 29.5 intacts).
  - [x] **Piège bootstrap Spatie (29.2/29.4)** : le gate scopé déclenche le **before-hook** Spatie (lecture de la table `permissions`). Les utilisateurs de test qui **n'utilisent pas `HasRoles`** évitent ce hook ; sinon **bootstrapper les 5 tables Spatie** (vides) dans le `setUp` (cf. patron `ParcGroupWpkgPageTest` de 29.1) **ou** stub `Gate::define('customize-workstationGroup', fn() => true)` selon le scénario. [Source: mémoires — test_suite_env_and_systemic_fixes]
  - [x] **Piège SQLite** : tester des **décisions** (booléens/exceptions/403), pas des bornes varchar/enum (non appliquées en SQLite) ; `expires_at` = date passée explicite. [Source: mémoire — sqlite_tests_no_varchar_enforcement]

- [x] **T5 — Runbook QA (domaine `rights-management`)** (AC: #1–#6)
  - [x] **Append** une section `## Story 29.6 — Scoping de `app.customize` (override capacité par parc)` à la **fin** de `docs/qa/domains/rights-management.md` (section append-only, scénarios `### Scénario N.M` numérotés stables, à la suite de la Section 11 de 29.1). Couvrir manuellement : délégué `app.customize` sur un parc (édite ses capacités, pas les autres), technicien global (édite partout), exclusion négative, expiration, parc logique (global), tentative de tampering `groupId` (refus). **NE PAS créer de fichier par story.**
  - [x] Enrichir le libellé du domaine `rights-management` dans `docs/qa/README.md` en ajoutant la mention `Story 29.6` (scoping `app.customize`).

- [x] **T6 — Validation finale** (AC: #7, #8)
  - [x] `php artisan test --filter "CapabilitiesTab|CapabilityPolicy|CapabilitiesOverrideAudit|WorkstationGroupPolicy|PermissiveOverride|ParcDefaults|UpstreamLockResolver"` sur HÔTE → vert.
  - [x] Grep garde-fou : plus aucun `auth()->user()->can('app.customize')` **global** dans `capabilities-tab.blade.php` (la décision passe par le gate scopé) ; `groupId` porte bien `#[Locked]` ; `git status` confirme **aucune** migration et **aucun** golden/`FROZEN_STATE_HASH`/`ContractV1` modifié.
  - [x] R3 : grep « central » = 0 introduit (vocabulaire amont/Upstream).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 29.6

**DANS** : (a) `#[Locked]` sur `groupId` (+ réordonnancement `mount`) ; (b) `WorkstationGroupPolicy::customize()` jumelle de `assignWpkg` ; (c) enregistrement du gate `customize-workstationGroup` ; (d) `guardCustomize()` résout le `WorkstationGroup` côté serveur et appelle le gate scopé (defense-in-depth) ; (e) convention groupe physique→délégation / logique→global **préservée** ; tests + runbook QA.

**HORS** (ne pas déborder) :
- **Audit 29.5** : **ne pas toucher** (`saveOverride`/`removeOverride` tracent déjà `assignable_id` fidèlement ; le fix de scope ne change pas la trace, il **empêche** simplement l'acte hors-périmètre en amont).
- **Aucune nouvelle permission Spatie, aucune migration** : la délégation per-groupe est portée par `PermissionService::canOnWorkstationGroup` sur la permission **existante** `app.customize`.
- **Moteur de résolution / compilé** (StateCompiler, `UpstreamLockResolver`, mailles, golden/`FROZEN_STATE_HASH`/`ContractV1`) : **intact**.
- **Autres surfaces `app.customize`** : `associations-tab.blade.php` (sa propre `guardCustomize` L.53) et `index.blade.php` (`@can('app.customize')` L.1969 / `->can` L.1239) sont le **même gap** mais des gardes **séparées** non servies par le `guardCustomize` de `capabilities-tab` — **report explicite** (à scoper hors de cette story si requis). Route `/app-customizations` = réglage produit global, **ne pas scoper**.
- **Verrou 29.2 / permissif 29.3 / badges 29.4** : inchangés (`authorizeUpstream` reste appelé après `guardCustomize`).

### Patron de référence existant à IMITER (ne rien réinventer)

- **`WorkstationGroupPolicy::assignWpkg()`** [app/Policies/WorkstationGroupPolicy.php:156-164] (29.1) = le **gabarit EXACT**. `customize()` en est la copie pour `app.customize`.
- **`WorkstationGroupPolicy::$gates`** [:30-39] + **`RegistersGates`** : ajout d'une entrée suffit, enregistrement auto (`AuthServiceProvider` L.57). Aucun `Gate::define` manuel, aucun `AgentServiceProvider`.
- **`WorkstationGroupPolicy::canCheckDelegation()`** [:169-172] = `$user instanceof User && $group->is_physical` : **la délégation ne s'applique qu'aux groupes PHYSIQUES** ; sur un parc logique on retombe sur le global. C'est la convention en place (view/manage/assignWpkg font tous pareil) — **à PORTER telle quelle, NE PAS réinventer ni « corriger »**.
- **`PermissionService::canOnWorkstationGroup(User, string, WorkstationGroup): bool`** [app/Services/PermissionService.php:368] : **honore déjà** négative active (prévaut sur global), global Spatie (fallback), positive active, expiration (`->active()`). **NE PAS** réimplémenter.
- **`ChecksPermissions::hasPermission()`** : fallback global propre (`$user->can($perm)`), gère `$user === null`.
- **Defense-in-depth « propriété publique hydratée »** (29.2/29.5) : `saveOverride`/`removeOverride` rechargent déjà la capacité côté serveur (`is_active`, existence en base) plutôt que de se fier au flag client `isEditing` — `guardCustomize` scopé applique **le même principe** à `groupId` (résolution serveur + `#[Locked]`).

### Pourquoi `#[Locked]` ET le gate scopé (les DEUX, pas l'un OU l'autre)

- `#[Locked]` **seul** : fige le tampering de `groupId`, mais l'autorisation reste **globale** → un délégué de A pourrait quand même agir sur A *et* sur tout parc où le composant est légitimement monté pour lui ? Non — mais surtout l'écriture hors-périmètre via un droit global mal cadré resterait possible. Le `#[Locked]` ne **scope** rien.
- Gate scopé **seul** : sans `#[Locked]`, `groupId` reste falsifiable ; or `guardCustomize` résout le WG **depuis `$this->groupId`** — si la valeur est altérée par le client *après* le mount, le gate s'évaluerait sur le mauvais parc. `#[Locked]` garantit que `$this->groupId` **est** la valeur d'hydratation serveur.
- **Ensemble** : `groupId` figé (serveur-autoritatif) + gate per-scope = l'écriture est gatée sur **le parc réel du composant**, conforme à la délégation. C'est exactement le modèle de menace défense-in-depth de 29.2.

### Détail d'implémentation critique — ordre du `mount`

`mount()` appelle aujourd'hui `guardCustomize()` **avant** d'assigner `$this->groupId` [L.61-65]. Le garde scopé lit `$this->groupId` → **inverser** l'ordre (assigner d'abord). Sans ça, `guardCustomize` résoudrait `WorkstationGroup::find(null)` → fallback global → scope cassé au mount. (Les autres call-sites — openAdd/openEdit/saveOverride/removeOverride — s'exécutent après hydratation, `$this->groupId` y est déjà peuplé.)

### Garde-fous projet CRITIQUES

- **NON-RÉGRESSION admin/technicien** : le fallback global doit rester intact (AC #2). Risque #1 = transformer un droit global en droit scopé et casser les techniciens. Le patron `assignWpkg` garantit le fallback — **ne pas s'en écarter**.
- **Convention physique/logique** : ne pas tenter de scoper les parcs logiques (ils retombent sur le global via `canCheckDelegation`). C'est volontaire et cohérent avec toute la policy (AC #5).
- **Court-circuit NFR3** : la story ne touche **ni** `UpstreamLockResolver` **ni** le rendu — le comportement standalone (sans contrat) reste byte-identique. Aucun nouveau chemin de résolution amont (AC #7).
- **Vocabulaire R3** : aucun mot « central » (sans objet ici — aucun nouvel identifiant amont — mais la règle reste).
- **Tests HÔTE** : php8.4 + `pdo_sqlite`, `RefreshDatabase`, **jamais la VM** (worktree git → interdit). [Source: mémoire — phpunit_test_env_host_vs_vm]

### Livewire 4.3 — `#[Locked]`

`use Livewire\Attributes\Locked;` (présent dans `vendor/livewire/livewire/src/Attributes/Locked.php`). Une propriété `#[Locked]` peut être initialisée par le `mount` mais **toute mutation côté client** (`$set`, payload falsifié) lève `Livewire\Exceptions\CannotUpdateLockedPropertyException`. C'est le mécanisme natif de figement de périmètre.

### Project Structure Notes

- **Modifiés** : `app/Policies/WorkstationGroupPolicy.php` (+1 méthode `customize`, +1 entrée `$gates`), `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (`#[Locked]` + `use Locked`, réordonnancement `mount`, corps de `guardCustomize`), `docs/qa/domains/rights-management.md` (append), `docs/qa/README.md` (libellé domaine).
- **Nouveaux** : tests sous `tests/Unit/Policies/` et `tests/Feature/Livewire/Parc/`.
- **Aucune** migration, **aucun** modèle, **aucune** route, **aucune** permission Spatie nouvelle. **Racine = projet Laravel** (pas de préfixe `laravel/`). [Source: mémoire — root_is_laravel]
- **⚠️ Working tree partagé** : ne **PAS** toucher au travail non committé de la story 30-1 (`ControlHubContractIngestionService.php`, `UpstreamLabelsImposedGroupsReceptionTest.php`, docs QA controlhub-contract). 29.6 ne concerne QUE policy + capabilities-tab + tests + sprint-status + ce fichier.

### References

- [Source: _bmad-output/codeReviews/29-5.md#M4] — gap tracé (scope `app.customize` global, `groupId` client-hydraté).
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#NFR1] — enforcement par Gates scopés (par `workstationGroup`), prérequis Epic 31.
- [Source: _bmad-output/implementation-artifacts/29-1-scoper-gate-wpkg-par-perimetre.md] — story MODÈLE (patron Gate scopé + `$gates` + tests + runbook).
- [Source: app/Policies/WorkstationGroupPolicy.php:156-164 (`assignWpkg`), :124-132 (`manage`), :30-39 (`$gates`), :169-172 (`canCheckDelegation`)] — gabarit à copier.
- [Source: app/Services/PermissionService.php:368] — `canOnWorkstationGroup` (négative/expiration/global déjà gérés).
- [Source: app/Providers/AuthServiceProvider.php:57] — `WorkstationGroupPolicy::registerGates()` (enregistrement auto).
- [Source: resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php:44 (`groupId`), :61-65 (`mount`), :487-494 (`guardCustomize`), :190/:211/:245/:354 (sites de mutation)] — surface à durcir.
- [Source: resources/views/pages/parc/groups/[id]/index.blade.php:2026] — montage `:group-id` du composant Livewire séparé (cycle propre non re-gardé par le mount parent).
- [Source: routes/web.php:175-177 (`app-customizations`), :211 (`/groups/{id}` middleware grossier)] — `app.customize` global + chaîne d'autorisation.
- [Source: vendor/livewire/livewire/src/Attributes/Locked.php] — `#[Locked]` (Livewire 4.3).
- [Source: mémoires projet — delegation_enforcement_wpkg_gap, permissive_floor_least_specific, sqlite_tests_no_varchar_enforcement, test_suite_env_and_systemic_fixes, phpunit_test_env_host_vs_vm, root_is_laravel, zero_prod_publish_is_test].

## Dépendances

- **Amont** :
  - **29.1** (review) — patron du Gate scopé (`assignWpkg` + `$gates` + `RegistersGates`) **directement copié**. Réutilisation, pas dépendance dure (le patron est sur la branche courante).
  - **29.5** (review/to-validate) — d'où provient le constat (M4). 29.6 **ne modifie pas** son audit ; il empêche en amont l'acte hors-périmètre. Pas de couplage de code dur.
  - Système de délégation **Epics 7.x** (`delegations`, `PermissionService`, `WorkstationGroupPolicy`) — livré et stable.
- **Aval** : **Epic 31** (bornage de l'install au catalogue amont par périmètre) — cohérence de l'enforcement par parc (le scope `app.customize` complète le scope `wpkg.*` de 29.1).
- **Indépendant** d'Epic 28/30 (ne lit ni n'écrit le contrat amont).

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, `RefreshDatabase`. **Jamais la VM.** [Source: mémoire — phpunit_test_env_host_vs_vm]
- Filtres ciblés : `php artisan test --filter "CapabilitiesTab|CapabilityPolicy|CapabilitiesOverrideAudit|WorkstationGroupPolicy|PermissiveOverride|ParcDefaults|UpstreamLockResolver"`.
- Couverture obligatoire :
  - **Positif scopé** : délégué de A autorisé sur A (policy + Livewire) ;
  - **Négatif scopé** : délégué de A refusé sur B (403 + **aucune** écriture/trace) ;
  - **Négative active** : refus malgré global (AC #3) ;
  - **Expirée** : refus (AC #4) ;
  - **Admin global** : autorisé partout + `customize(user, null) === true` (AC #2) ;
  - **Parc logique** : fallback global uniquement (AC #5) ;
  - **Tampering `groupId`** : `CannotUpdateLockedPropertyException` (AC #6) ;
  - **Non-régression** : 29.2/29.3/29.4/29.5 intacts.
- **Pièges** : bootstrap tables Spatie en feature (le before-hook lit `permissions`) — utiliser des users sans `HasRoles` ou bootstrapper les 5 tables vides (patron 29.2/29.4) ; SQLite n'applique pas varchar/enum → tester des décisions, pas des bornes ; `expires_at = now()->subDay()`.

## Recommandation Modèle Dev

**`opus`.**

Justification : le **volume** de code est faible (une méthode policy jumelle, une entrée `$gates`, un `#[Locked]`, un réordonnancement de `mount`, un corps de garde), mais c'est un **correctif de sécurité d'autorisation** sur une surface d'écriture sensible où le **raisonnement** prime sur l'exécution : comprendre **pourquoi** `#[Locked]` ET le gate scopé sont **tous deux** nécessaires (l'un sans l'autre laisse un trou), respecter la **convention physique/logique** sans la « corriger », **ne pas casser** le fallback global (régression silencieuse des techniciens), **ne pas déborder** sur l'audit 29.5 / le moteur / les autres surfaces, et écrire des tests qui **prouvent réellement** le refus hors-périmètre (aucune écriture **ni** trace) plutôt que de passer vacuitement. Les pièges (ordre du `mount`, bootstrap Spatie du before-hook, `find(null)`→fallback) sont exactement le type d'angle mort qui exige un jugement d'architecte de sécurité. Le dev-cycle routera ensuite la **review vers le modèle opposé** (sonnet/fable) pour une seconde paire d'yeux sur les non-régressions.

## Dev Agent Record

### Agent Model Used

opus / claude-opus-4-8[1m]

### Debug Log References

- `php artisan test --filter "CapabilitiesTabCustomizeScopingTest|WorkstationGroupPolicyCustomize"` → **16 passed (29 assertions)** (7 unit policy + 9 feature Livewire).
- `php artisan test --filter "WorkstationGroupPolicy|CapabilitiesTab|CapabilitiesOverride|Delegation|Customize|Permission|PermissiveOverride|ParcDefaults|UpstreamLockResolver"` → **352 passed, 3 skipped, 0 failed** (778 assertions). **0 régression** (29.1/29.2/29.3/29.4/29.5 + policies existantes verts).
- `php -l` : OK sur les 3 fichiers PHP créés/modifiés (`WorkstationGroupPolicy.php`, les 2 tests).
- Compilation Blade (`Blade::compileString`) : OK sur `capabilities-tab.blade.php`.
- Grep R3 « central » sur les fichiers touchés → **0** occurrence.
- `git status` : aucune migration, aucun golden/`FROZEN_STATE_HASH`/`ContractV1` modifié ; travail 30-1 non touché.

### Completion Notes List

- **T0** : grep confirme que la **seule** surface servie par ce `guardCustomize()` est `capabilities-tab.blade.php` (5 sites : `mount`, `openAdd`, `openEdit`, `saveOverride`, `removeOverride`). Surfaces HORS-scope documentées et **non touchées** : `associations-tab.blade.php` (sa propre `guardCustomize`), `index.blade.php` (`@can('app.customize')` / `->can`), route globale `/app-customizations`.
- **T1** : `WorkstationGroupPolicy::customize(?Authenticatable, ?WorkstationGroup = null): bool` — **copie exacte** de `assignWpkg()` (délégation scopée `canOnWorkstationGroup($user,'app.customize',$group)` si group physique, sinon fallback global `hasPermission`). Entrée `'customize-workstationGroup' => 'customize'` ajoutée à `$gates` (enregistrement **auto** via `RegistersGates`/`AuthServiceProvider` L.57 — pas d'`AgentServiceProvider`). Négative/expiration **non réimplémentées** (déléguées à `canOnWorkstationGroup`). Convention `canCheckDelegation` **portée telle quelle** (physique→délégation / logique→global).
- **T2** : `#[Locked] public int $groupId` (import `Livewire\Attributes\Locked`). `mount()` **réordonné** : `$this->groupId = $groupId;` assigné **AVANT** `$this->guardCustomize();` (le garde scopé lit `$this->groupId` pour résoudre le WG).
- **T3** : `guardCustomize()` réécrit — résout `WorkstationGroup::find($this->groupId)` **côté serveur** et appelle `Gate::allows('customize-workstationGroup', …)` (defense-in-depth, ne se fie à aucun flag client). `find(null)`/id inexistant → gate reçoit `null` → fallback global (seul l'admin passe, pas de fausse ouverture, commenté). `authorizeUpstream()` (29.2) et le câblage d'audit 29.5 **inchangés** ; `guardCustomize()` reste appelé **en tête** de chaque mutation.
- **T4** : 2 fichiers de tests neufs. `tests/Unit/Policies/WorkstationGroupPolicyCustomizeTest.php` (7 cas : délégué A oui / B non, admin global partout + null, négative prévalant, expirée, groupe logique → global seul, user nu refusé, résolution via façade `Gate::allows`). `tests/Feature/Livewire/Parc/CapabilitiesTabCustomizeScopingTest.php` (9 cas : accès délégué positif sur A / refus 403 sur B sans écriture ni trace ; write-through + refus B de l'acteur menace M4 ; admin global A+B ; tampering `groupId` → `CannotUpdateLockedPropertyException` ; non-régression verrou amont 29.2 ; non-régression audit 29.5). Piège SQLite respecté (décisions/exceptions, `expires_at = now()->subDay()`). Pièges bootstrap Spatie gérés via `CreatesPermissionSchema`+`PermissionSeeder` (unit) et `Permission::firstOrCreate('app.customize')` + vrais `User` (feature, patron 29.5).
- **T5** : `docs/qa/domains/rights-management.md` — **Section 12** ajoutée (append, scénarios 12.1→12.7 stables, après Section 11 / la checklist de 29.1, sans renumérotation). Libellé du domaine `rights-management` enrichi dans `docs/qa/README.md` (ligne rights-management uniquement ; ligne controlhub-contract de 30-1 **non touchée**).
- **T6** : grep garde-fou — plus aucun `auth()->user()->can('app.customize')` global dans `capabilities-tab.blade.php` (décision via gate scopé) ; `#[Locked]` présent sur `groupId` ; aucune migration ; grep « central » = 0.

**Décisions / déviations** :
1. **Interaction 29.2 découverte (importante).** `CapabilityPolicy::modify` (gate `modify-capability`, appelé par `authorizeUpstream`) exige le droit **global** `app.customize` comme **plancher**. Conséquence : un délégué **positif-seul** passe le `guardCustomize()` scopé (mount/openAdd) mais **ne peut pas finaliser une écriture** tant que ce plancher reste global. C'est **hors-scope 29.6** (interdit de toucher 29.2). En conséquence, le write-through bout-en-bout est prouvé avec **l'acteur de la menace M4** (droit global `app.customize` scopé par **exclusion négative** par parc) — ce qui démontre le cœur du fix (le guard scopé honore désormais la négative, là où l'ancien contrôle global l'ignorait), tandis que la **décision d'autorisation du délégué positif** est prouvée au niveau **policy unitaire** (`customize(delegate, A)===true`) et au niveau **guard feature** (mount sur A autorisé, mount sur B 403). Le plancher scopé de `modify-capability` relève d'un **suivi 29.2 / Epic 31** (recommandation review).
2. `WorkstationGroup::find(null)` → fallback global (pas de fausse ouverture), aligné sur le rabat `null` de `assignWpkg`.
3. Surfaces `app.customize` HORS `capabilities-tab` (associations-tab, index, route) **non élargies** (report explicite, gardes séparées).

**Points d'attention review (modèle opposé = sonnet)** :
- Valider la déviation #1 (plancher `modify-capability` global) : est-elle acceptable en l'état (scoping effectif via négative + anti-tampering) ou faut-il ouvrir un suivi pour scoper `modify-capability` ?
- Vérifier qu'aucune autre surface n'invoque le `guardCustomize` de `capabilities-tab` (composant isolé).
- Confirmer non-régression byte-identique du court-circuit NFR3 (aucune touche `UpstreamLockResolver`/rendu).

### File List

**Modifiés :**
- `app/Policies/WorkstationGroupPolicy.php` — méthode `customize()` + entrée `$gates` `customize-workstationGroup`.
- `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` — `use Locked` + `#[Locked]` sur `groupId`, réordonnancement `mount()`, corps de `guardCustomize()` (gate scopé serveur-autoritatif).
- `docs/qa/domains/rights-management.md` — Section 12 (append).
- `docs/qa/README.md` — libellé domaine `rights-management` (ligne uniquement).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — statut 29-6 → review.

**Nouveaux :**
- `tests/Unit/Policies/WorkstationGroupPolicyCustomizeTest.php`
- `tests/Feature/Livewire/Parc/CapabilitiesTabCustomizeScopingTest.php`
