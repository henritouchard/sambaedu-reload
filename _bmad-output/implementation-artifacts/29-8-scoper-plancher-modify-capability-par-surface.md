# Story 29.8: Scoper le plancher `modify-capability` par surface

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **délégué `app.customize` POSITIF-seul** (refnum non-admin disposant d'une délégation `app.customize` scopée sur SON parc, **sans** droit global Spatie),
I want **pouvoir réellement créer / éditer / retirer un override de capacité sur les parcs de mon périmètre (write-through complet), sans être rebloqué à l'écriture par un plancher de droit GLOBAL caché dans le gate `modify-capability`**,
so that **l'habilitation promise par l'AC#1 de 29.6 soit enfin livrée pour mon persona, tout en préservant le verrou amont (29.2) et la garde globale du défaut diffusé (registry-tab)**.

> Story de **correctif d'autorisation** (follow-up tracé de la review 29.6, **P1🟠**, pertinence opus 3/3). Elle **ne touche ni** le contrat amont (Epic 28/30) **ni** le moteur de résolution : elle **retire un plancher de droit redondant** du gate `modify-capability` et **prouve** que chaque surface ferme déjà l'accès en amont. 29.6 a livré la **SÉCURITÉ** (M4) ; 29.8 livre l'**HABILITATION** restée en suspens.
>
> **⚠️ Décision Henri (rappel 29.1/29.6, 2026-06-26) — pas de compat ascendante exigée** : aucun environnement de prod à préserver ; seul invariant intangible = l'enrôlement controlHub. Les garde-fous « non-régression » ci-dessous visent le **bon design** (verrou amont 29.2, garde globale du défaut, fallback admin), pas la rétrocompat. [Source: mémoires projet — zero_prod_publish_is_test, no_legacy_transition_state]

## Contexte du trou (constat de code vérifié 2026-06-27)

**Cause racine (review 29.6 P1).** `app/Policies/CapabilityPolicy.php::modify()` (gate `modify-capability`) impose aujourd'hui **DEUX conditions cumulées** :

```php
public function modify(?Authenticatable $user, ?Capability $capability = null): bool
{
    if (! $this->hasPermission($user, 'app.customize')) {   // (1) PLANCHER de droit GLOBAL
        return false;
    }
    if ($capability === null) {
        return true;
    }
    return ! $this->lockResolver->isCapabilityLocked($capability);  // (2) VERROU AMONT
}
```

1. **Plancher de droit GLOBAL** : `hasPermission($user, 'app.customize')` = `ChecksPermissions::hasPermission` = `$user->can('app.customize')` Spatie, **JAMAIS scopé par parc** (vérifié L.64-66 + trait `ChecksPermissions`).
2. **Absence de verrou amont** : `! UpstreamLockResolver::isCapabilityLocked($capability)` (vérifié L.72).

**Effet de bord vérifié.** Depuis 29.6, la surface override **par-parc** (`capabilities-tab.blade.php`, `guardCustomize()` L.521-529) garde déjà l'accès via le **gate SCOPÉ** `customize-workstationGroup` (`WorkstationGroupPolicy::customize`, jumeau d'`assignWpkg`). **MAIS** au write-through, chaque mutation appelle `authorizeUpstream()` → `Gate::authorize('modify-capability', $capability)` → le **plancher GLOBAL (1) rebloque** un **délégué POSITIF-seul** (délégation scopée sur son parc, sans droit global). Résultat : l'**habilitation** promise par AC#1 de 29.6 n'est **pas livrée** pour ce persona (seul un détenteur du droit global `app.customize` écrit).

**Ordre d'appel vérifié (defense-in-depth déjà en place — point clé du fix).** Sur les DEUX surfaces, le contrôle de droit propre à la surface s'exécute **AVANT** `authorizeUpstream()` :

- `capabilities-tab.blade.php` : `guardCustomize()` (scopé) **avant** `authorizeUpstream()` dans `openAdd` (L.206 → L.213), `openEdit` (L.227 → L.231), `saveOverride` (L.261 → L.287), `removeOverride` (L.377 → L.384). `guardCustomize()` fait `abort_unless(... Gate::allows('customize-workstationGroup', WorkstationGroup::find($this->groupId)) ..., 403)` → **403 propre AVANT** d'atteindre `modify-capability`.
- `registry-tab.blade.php` : `guardAdmin()` (`Gate::allows('server.admin')` → `abort(403)`) **avant** `authorizeUpstream()` dans `openEdit` (L.99 → L.103), `toggleLock` (L.121 → L.128), `saveDefault` (L.144 → L.154).

**CONTRAINTE CLÉ — `modify-capability` est DUAL-PURPOSE (à ne pas casser).** Le gate est consommé par DEUX surfaces aux exigences de droit **différentes** :
- **capabilities-tab** (override **PAR-PARC**) → contrôle de droit **scopé par parc** = `customize-workstationGroup` (DÉJÀ livré 29.6).
- **registry-tab** (défaut diffusé **Broadcast** = réglage **GLOBAL** d'instance) → contrôle de droit **global** = `server.admin` via `guardAdmin()` (DÉJÀ en place).

C'est pourquoi on **ne peut pas** scoper le plancher *dans* `modify-capability` (il empièterait sur l'une ou l'autre surface). La bonne correction = **retirer le plancher de droit** du gate et laisser **chaque surface porter SON propre contrôle de droit** (déjà le cas), `modify-capability` ne conservant **que le verrou amont**.

**Décision de design retenue (à implémenter).** `modify()` devient :

```php
public function modify(?Authenticatable $user, ?Capability $capability = null): bool
{
    return $capability === null ? true : ! $this->lockResolver->isCapabilityLocked($capability);
}
```

→ plus aucun appel à `hasPermission`. Le `?Authenticatable $user` **reste dans la signature** (contrat Gate : Laravel passe l'utilisateur en 1ᵉʳ argument d'une méthode de policy) mais devient **inutilisé** — à documenter en PHPDoc. Le trait `ChecksPermissions` et son import deviennent inutilisés dans cette policy → **les retirer** (la classe n'a que `modify()`).

## Acceptance Criteria

1. **Given** un **délégué POSITIF-seul** (`app.customize` scopé sur son parc **A** via le système de délégation, **SANS** droit global Spatie), sur une capacité **non verrouillée amont**,
   **When** il déclenche un write-through complet (`openAdd` → `saveOverride`, `openEdit`, `removeOverride`) sur l'onglet capacités **du parc A**,
   **Then** l'opération **réussit** (écriture effective dans `capability_assignments` pour `saveOverride` ; ouverture du formulaire pour `openEdit` ; retrait effectif pour `removeOverride`) — l'**habilitation AC#1 de 29.6 est enfin livrée** pour ce persona.

2. **Given** le même délégué POSITIF-seul de A,
   **When** il tente une mutation sur un **AUTRE parc B** (composant monté `groupId = B`),
   **Then** l'action est **refusée** (403 via `guardCustomize()` scopé, **AVANT** `authorizeUpstream`), **sans** écriture dans `capability_assignments` **ni** trace d'audit 29.5 — **non-régression sécurité M4 de 29.6**.

3. **Given** la surface **registry-tab** (défaut diffusé, réglage global d'instance),
   **When** un acteur **non-`server.admin`** (même porteur d'une délégation `app.customize` par-parc) tente `openEdit` / `saveDefault` / `toggleLock`,
   **Then** l'action est **refusée** (403 via `guardAdmin()`, **AVANT** `authorizeUpstream`) — la garde **globale `server.admin`** du défaut diffusé reste **intacte** (le retrait du plancher ne l'affaiblit pas).

4. **Given** un item amont `registry`/`locked`/`instance` matchant une clé de la capacité,
   **When** un acteur **par ailleurs autorisé** (délégué de A sur capabilities-tab **OU** `server.admin` sur registry-tab) tente une mutation de cette capacité,
   **Then** l'action est **refusée côté serveur** via `Gate::authorize('modify-capability', $capability)` (`AuthorizationException`), **sur LES DEUX surfaces** — le **verrou amont devient le SEUL motif restant** de refus de `modify-capability` (**non-régression 29.2**).

5. **Given** aucun contrat amont actif (standalone) **OU** un contrat `severed` **OU** un item `permissive`/`absent`,
   **When** `modify-capability` s'évalue pour une capacité,
   **Then** le gate **autorise** (la décision = « pas verrouillé », plus de plancher de droit) — court-circuit NFR3 **byte-identique** (la story ne touche **ni** `UpstreamLockResolver` **ni** le rendu).

6. **Given** un **admin global** disposant du droit `app.customize` global,
   **When** il agit sur capabilities-tab (n'importe quel parc, via le fallback global de `customize-workstationGroup`),
   **Then** l'action reste **autorisée** (write-through inchangé) — **non-régression** de l'acteur déjà habilité avant 29.8.

7. **Given** le retrait du plancher,
   **When** on relit le contrat **unitaire** de `CapabilityPolicy::modify`,
   **Then** un utilisateur **sans** `app.customize` sur une capacité **non verrouillée** obtient désormais `modify(...) === true` **au niveau policy** (la responsabilité du droit a **migré vers les surfaces**) ; le seul refus de la policy est le **verrou amont** ; `modify(*, null) === true` toujours. Les tests unitaires qui asseyaient « sans `app.customize` → false » sont **révisés en conséquence** (pas une fausse régression).

8. **Given** la décision « ceinture + bretelles » sur le message d'erreur,
   **When** on examine le `catch (AuthorizationException)` de `authorizeUpstream()` sur les deux surfaces,
   **Then** la **double-branche est CONSERVÉE** (verrou amont → message amont ; sinon → « Vous n'avez pas le droit ») en **defense-in-depth**, avec un **commentaire** expliquant que la branche « pas le droit » est désormais **théoriquement inatteignable par défaut de droit** (le droit est filtré en amont par `guardCustomize`/`guardAdmin` qui abortent 403 avant `authorizeUpstream`), mais reste comme garde-fou contre un futur appelant non gardé.

9. **Given** la suite de tests HÔTE (php8.4 + sqlite),
   **When** elle s'exécute,
   **Then** sont **couverts et verts** : write-through du délégué positif-seul sur A (AC#1), refus sur B sans écriture/trace (AC#2), refus non-admin sur registry-tab (AC#3), verrou amont refusé sur les 2 surfaces (AC#4), allow standalone/permissive (AC#5), admin global (AC#6), contrat unitaire révisé de `modify` (AC#7) ; **aucune régression** des suites `CapabilityPolicy`, `CapabilitiesTab*`, `ParcDefaults*`, `WorkstationGroupPolicy*`, `UpstreamLockResolver`, `CapabilitiesOverrideAudit`.

10. **Given** le garde-fou de vocabulaire R3,
    **When** on relit le code et les PHPDoc modifiés,
    **Then** **aucun** mot « central » n'apparaît : vocabulaire « amont » / `Upstream` / `ControlHub*`.

## Tasks / Subtasks

- [x] **T0 — Cadrage : prouver que le plancher est redondant** (AC: #1, #2, #3)
  - [x] Re-vérifier par lecture de code que **chaque** surface garde SON droit **AVANT** `authorizeUpstream()` : `capabilities-tab` → `guardCustomize()` (openAdd L.206, openEdit L.227, saveOverride L.261, removeOverride L.377) précède `authorizeUpstream()` (L.213/231/287/384) ; `registry-tab` → `guardAdmin()` (openEdit L.99, toggleLock L.121, saveDefault L.144) précède `authorizeUpstream()` (L.103/128/154). **Consigner** : le retrait du plancher ne crée **aucun** chemin d'écriture sans contrôle de droit.
  - [x] Confirmer par grep que `modify-capability` n'est invoqué QUE par ces deux `authorizeUpstream()` (aucun autre `Gate::authorize/allows('modify-capability', …)` ni `@can('modify-capability', …)` non gardé). Si un autre call-site existe, le **documenter** (il devra porter son propre contrôle de droit) — sinon, scope confirmé.

- [x] **T1 — Retirer le plancher de droit de `CapabilityPolicy::modify`** (AC: #4, #5, #7, #10)
  - [x] Réécrire `app/Policies/CapabilityPolicy.php::modify()` → `return $capability === null ? true : ! $this->lockResolver->isCapabilityLocked($capability);` (supprimer le bloc `hasPermission`).
  - [x] Conserver la signature `modify(?Authenticatable $user, ?Capability $capability = null): bool` (contrat Gate). Annoter `$user` comme **inutilisé** (PHPDoc + éventuel commentaire) : le contrôle de droit est porté **par chaque surface en amont** (`customize-workstationGroup` scopé / `server.admin` global) — le gate ne porte plus QUE le **verrou amont**.
  - [x] Retirer `use ChecksPermissions;` (trait) et l'import `App\Policies\Traits\ChecksPermissions` devenus inutiles (la classe n'a que `modify()`). Vérifier qu'aucune autre méthode ne les utilise. Conserver `RegistersGates` + `$gates` + l'enregistrement inchangés.
  - [x] **Mettre à jour le PHPDoc** de la classe et de `modify()` : retirer le passage « Plancher de droit = `app.customize` » et expliquer le **dual-purpose** (capabilities-tab scopé / registry-tab global) + que le droit est désormais filtré **par surface**. ⚠️ **GARDE-FOU R3** : aucun mot « central » ; vocabulaire « amont »/`Upstream`.

- [x] **T2 — Conserver et documenter la double-branche message (defense-in-depth)** (AC: #8)
  - [x] Dans `capabilities-tab.blade.php::authorizeUpstream()` (L.539-560) et `registry-tab.blade.php::authorizeUpstream()` (L.218-236) : **GARDER** la double-branche (`isCapabilityLocked` ? message amont : « Vous n'avez pas le droit … »).
  - [x] **Mettre à jour le commentaire** du `catch` : indiquer que, depuis 29.8, `modify-capability` ne refuse plus que pour **verrou amont** (le droit est filtré en amont par `guardCustomize`/`guardAdmin` qui abortent 403 avant d'atteindre ce point) → la branche « pas le droit » est **théoriquement inatteignable par défaut de droit** mais **conservée** comme garde-fou (ceinture + bretelles) contre un futur appelant non gardé. **Ne pas** simplifier en message unique.

- [x] **T3 — Tests HÔTE — contrat unitaire de la policy (RÉVISION sensible)** (AC: #4, #5, #7)
  - [x] ⚠️ **Le retrait du plancher CHANGE le contrat unitaire de `modify()`.** Réviser `tests/Unit/Policies/CapabilityPolicyTest.php` :
    - `deny_without_app_customize` (L.61-65) : sur une capacité **non verrouillée**, `modify(user(false), $cap)` passe de `false` → **`true`**. **Remplacer** ce test par un test prouvant que **la policy ne gate plus le droit** (p.ex. `right_is_no_longer_enforced_at_policy_level` : `modify(user(false), $capNonLocked) === true`, avec un commentaire « le droit migre vers les surfaces »).
    - `null_capability_falls_back_to_right_only` (L.102-107) : `modify(user(false), null)` passe de `false` → **`true`**. **Renommer/réviser** en `null_capability_is_always_allowed` (`modify(*, null) === true` quel que soit le droit).
    - `allow_when_not_locked_with_right` (L.67-73) et `allow_when_permissive_with_right` (L.90-100) : restent **verts** (true) ; le `user(true)` devient indifférent — laisser ou simplifier au choix, sans casser.
    - `deny_when_capability_is_upstream_locked_even_with_right` (L.75-88) : reste **vert** (locked → false). Optionnellement renommer (« even_with_right » n'a plus de sens — le droit n'est plus évalué), mais la valeur de l'assertion est inchangée : **verrou = seul motif de refus**.
  - [x] **Souligner dans le code/test** (commentaire) que ce changement de contrat est **voulu** (la fermeture du droit se fait dans les surfaces), pour éviter qu'un futur reviewer le lise comme une régression de sécurité.

- [x] **T4 — Tests HÔTE — write-through du délégué positif-seul (habilitation AC#1)** (AC: #1, #2, #6)
  - [x] Étendre `tests/Feature/Livewire/Parc/CapabilitiesTabCustomizeScopingTest.php` (helper `positiveDelegateOfA()` **déjà présent** L.91-98, sur permission Spatie `app.customize` via `PermissionService::grantDelegation`, parcs **physiques**) :
    - **AC#1** : `positive_delegate_can_complete_write_through_on_a` — `actingAs(positiveDelegateOfA())`, monter `groupId = A`, `openAdd($cap->id)` → `set('formValue','off')` → `saveOverride()` → `assertDatabaseHas('capability_assignments', [... assignable_id = A, value=off])`. **Avant 29.8 ce test échoue** (bloqué par le plancher) ; après, il passe. Couvrir aussi `openEdit` (override préexistant → `assertSet('isEditing', true)`) et `removeOverride` (override préexistant → ligne supprimée).
    - **AC#2 (non-régression M4)** : réutiliser/confirmer `positive_delegate_of_a_is_forbidden_on_b_without_write_or_audit_trace` (L.152-163) reste **vert** (403 au mount sur B, 0 écriture, 0 trace).
  - [x] **AC#6** : confirmer que `global_admin_can_save_override_on_a_and_b` (L.251) reste **vert** (fallback global préservé).
  - [x] **AC#4 (verrou amont)** : confirmer que `authorized_actor_is_still_blocked_by_upstream_lock` (L.294) reste **vert** — pour le délégué positif-seul, ajouter (ou adapter) un cas : capacité **verrouillée amont** → `saveOverride` refusé (toast erreur) + **aucune** écriture, prouvant que le **verrou** mord toujours **même sans plancher** et **même pour un délégué scopé**.
  - [x] **Pièges** : bootstrap Spatie before-hook — le helper `positiveDelegateOfA()` utilise de **vrais `User`** + `Permission::firstOrCreate('app.customize')` (déjà câblé dans le `setUp` L.77). SQLite ne borne pas les décisions (tester booléens/écritures/exceptions, pas varchar). [Source: mémoires — test_suite_env_and_systemic_fixes, sqlite_tests_no_varchar_enforcement]

- [x] **T5 — Tests HÔTE — registry-tab reste gardé `server.admin`** (AC: #3, #4)
  - [x] Dans `tests/Feature/Livewire/Admin/ParcDefaultsUpstreamLockTest.php` (surface registry-tab ; helper `actAsAdmin()` avec `Gate::before` ciblé `server.admin → true`) :
    - **AC#3 (non-régression garde globale)** : ajouter `non_admin_is_blocked_on_registry_tab` — un acteur **sans** `server.admin` (ne pas poser le `Gate::before` ; un vrai `User` sans le droit, ou un mock dont `can('server.admin')` = false) → `openEdit`/`saveDefault`/`toggleLock` → **403** (`guardAdmin()`), `capabilities.default_value`/`overrides_locked` **inchangés**. Prouve que le retrait du plancher **n'ouvre pas** la surface défaut au non-admin.
    - **AC#4** : `save_default_is_blocked_for_upstream_locked_capability` (L.100) et `toggle_lock_is_blocked_for_upstream_locked_capability` (L.119) restent **verts** (verrou amont mord toujours, admin présent).
    - `non_locked_capability_default_still_saves_non_regression` (L.138) reste **vert** (write-through admin OK).
  - [x] Si la fermeture non-admin de registry-tab est déjà couverte par une suite existante (`AdminSettingsParcDefaultsPageTest`), **réutiliser** plutôt que dupliquer ; sinon ajouter le cas ici. **Ne pas réinventer.**

- [x] **T6 — Runbook QA (domaine `rights-management`)** (AC: #1–#8)
  - [x] **Append** une **Section 14 — Habilitation du délégué `app.customize` positif-seul (retrait du plancher `modify-capability`) (Story 29.8)** à la **fin** de `docs/qa/domains/rights-management.md` (append-only, après Section 13 de 29.7 ; scénarios `### Scénario 14.N` stables). Couvrir manuellement : (a) délégué positif-seul édite/ajoute/retire un override sur SON parc (désormais OK) ; (b) reste bloqué sur un autre parc ; (c) non-admin bloqué sur le défaut diffusé (registry-tab) ; (d) capacité verrouillée amont refusée sur les deux surfaces ; (e) admin global inchangé. **NE PAS créer de fichier par story.** [Source: docs/qa/README.md — sections numérotées stables]
  - [x] Enrichir le libellé du domaine `rights-management` dans `docs/qa/README.md` (ligne L.7) : ajouter la mention `Story 29.8` (habilitation délégué positif-seul / plancher `modify-capability` retiré). **Ne toucher QUE cette ligne.**

- [x] **T7 — Validation finale** (AC: #9, #10)
  - [x] `php artisan test --filter "CapabilityPolicy|CapabilitiesTab|CapabilitiesOverride|ParcDefaults|WorkstationGroupPolicy|UpstreamLockResolver"` sur **HÔTE** → vert (write-through positif-seul + non-régressions).
  - [x] Grep garde-fou : plus aucun `hasPermission(... 'app.customize')` ni `->can('app.customize')` **dans** `CapabilityPolicy.php` ; les deux `authorizeUpstream()` conservent la double-branche ; `modify-capability` n'a pas d'autre call-site non gardé.
  - [x] R3 : `grep -rin "central" app/Policies/CapabilityPolicy.php resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` → **0** introduit.
  - [x] `git status` : **aucune** migration, **aucune** permission Spatie nouvelle, **aucun** golden/`FROZEN_STATE_HASH`/`ContractV1` modifié.

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 29.8

**DANS** : (a) retrait du plancher de droit GLOBAL de `CapabilityPolicy::modify` (ne reste que le verrou amont) + nettoyage du trait `ChecksPermissions` devenu inutile + PHPDoc ; (b) conservation **documentée** de la double-branche message dans les deux `authorizeUpstream()` ; (c) révision du **contrat unitaire** de `CapabilityPolicyTest` ; (d) test de **write-through** du délégué positif-seul (habilitation AC#1 de 29.6) ; (e) test de **non-régression** registry-tab (`server.admin`) ; (f) runbook QA append.

**HORS** (ne pas déborder) :
- **`UpstreamLockResolver`** : **intact** (le verrou amont reste l'unique motif de refus de `modify-capability` — ne pas y toucher).
- **`WorkstationGroupPolicy::customize` / `customize-workstationGroup`** (29.6) : **intact** (c'est lui qui porte le droit scopé pour capabilities-tab).
- **`guardCustomize()` / `guardAdmin()`** : **inchangés** (ils portent déjà le droit ; on s'appuie dessus, on ne les modifie pas).
- **Audit 29.5 / permissif 29.3 / badges 29.4 / verrou 29.2** : **inchangés** (la story retire un plancher redondant ; elle n'altère ni la trace, ni le rendu, ni le moteur).
- **Aucune migration, aucune nouvelle permission Spatie, aucun nouveau modèle.** **Racine = projet Laravel** (pas de préfixe `laravel/`). [Source: mémoire — root_is_laravel]
- **Autres surfaces `app.customize`** (associations-tab, index, route `/app-customizations`) : hors-scope (report 29.6 inchangé).

### Pourquoi retirer le plancher est SÛR (le raisonnement central)

Le plancher `app.customize` dans `modify-capability` était **redondant** : chaque surface filtre **déjà** le droit **avant** d'appeler le gate, et avec la **bonne granularité** :
- capabilities-tab : `guardCustomize()` = `customize-workstationGroup` **scopé par parc** (29.6) → abort 403 **avant** `authorizeUpstream`.
- registry-tab : `guardAdmin()` = `server.admin` **global** → abort 403 **avant** `authorizeUpstream`.

Le plancher global était non seulement redondant mais **nuisible** : il rebloquait le délégué positif-seul que le guard scopé venait d'autoriser (P1 de 29.6). En le retirant, `modify-capability` redevient ce qu'il doit être : **un pur verrou amont** (sa raison d'être 29.2), réutilisable par n'importe quelle surface **qui porte son propre contrôle de droit**. C'est exactement le **dual-purpose** que la review 29.6 a identifié comme l'argument décisif.

### Effet de bord à traiter — message d'erreur (defense-in-depth)

Après retrait, `modify-capability` ne refuse plus que pour **verrou amont**. La branche « Vous n'avez pas le droit » du `catch` des deux `authorizeUpstream()` devient **théoriquement inatteignable par défaut de droit** (le droit est filtré en amont par `guardCustomize`/`guardAdmin`). **Décision retenue = GARDER la double-branche** (ceinture + bretelles) : si un futur appelant invoquait `modify-capability` **sans** garde de droit en amont, la branche resterait un message correct plutôt qu'un faux « verrouillé amont ». **Tracer ce choix** (T2) en commentaire — ne pas simplifier en message unique.

### ⚠️ Point sensible — le contrat unitaire de `modify()` CHANGE

C'est le **piège n°1** de cette story : `CapabilityPolicyTest` contient des assertions qui asseyaient « user sans `app.customize` → `false` » (`deny_without_app_customize`, `null_capability_falls_back_to_right_only`). Après retrait, ces décisions passent à `true` (la responsabilité du droit a migré vers les surfaces). **Ces tests DOIVENT être révisés** (T3), faute de quoi la suite échoue et un reviewer pressé lira une **fausse régression de sécurité**. La sécurité n'est pas perdue : elle est **portée par `guardCustomize`/`guardAdmin`** (prouvé par AC#2/#3), pas par la policy. Documenter explicitement dans les tests.

### Patron de référence / ne rien réinventer

- **`positiveDelegateOfA()`** [CapabilitiesTabCustomizeScopingTest.php:91-98] : helper **déjà écrit** (vrai `User` + `PermissionService::grantDelegation($user,'app.customize',$parcA)`, sans droit global). Le réutiliser tel quel pour le write-through AC#1.
- **`actAsAdmin()` / `Gate::before` ciblé** [ParcDefaultsUpstreamLockTest.php:59-76] : patron pour évaluer `modify-capability` réellement sans court-circuiter `server.admin`. Pour AC#3, faire l'**inverse** (acteur sans `server.admin`).
- **`CapabilityPolicy::modify`** [app/Policies/CapabilityPolicy.php:62-73] : seul fichier de logique à modifier. Garder `RegistersGates` + `$gates` + DI `UpstreamLockResolver`.
- **`UpstreamLockResolver::isCapabilityLocked`** : ne pas toucher — c'est l'unique motif de refus restant.

### Garde-fous projet CRITIQUES

- **Ne PAS affaiblir registry-tab** : la garde globale `server.admin` du défaut diffusé doit rester opposable à un non-admin (AC#3). Risque #1 = croire que « retirer le plancher » ouvre la surface défaut — c'est faux **uniquement parce que** `guardAdmin()` est en amont ; le **prouver par test**.
- **Verrou amont = seul motif restant** : un test doit montrer le refus sur capacité verrouillée **sur les deux surfaces** (AC#4) après retrait du plancher (non-régression 29.2).
- **Court-circuit NFR3** : la story ne touche **ni** `UpstreamLockResolver` **ni** le rendu → comportement standalone byte-identique (AC#5).
- **R3** : aucun « central » (code, messages, PHPDoc) ; « amont »/`Upstream`.
- **Tests HÔTE** : php8.4 + `pdo_sqlite`, `RefreshDatabase`, **jamais la VM** (worktree git → interdit). [Source: mémoire — phpunit_test_env_host_vs_vm]

### Project Structure Notes

- **Modifiés** :
  - `app/Policies/CapabilityPolicy.php` — `modify()` (retrait plancher), retrait `use ChecksPermissions`, PHPDoc.
  - `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` — `authorizeUpstream()` : **commentaire** du `catch` mis à jour (double-branche conservée).
  - `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` — `authorizeUpstream()` : **commentaire** du `catch` mis à jour (double-branche conservée).
  - `tests/Unit/Policies/CapabilityPolicyTest.php` — **révision** du contrat unitaire (2 tests réécrits).
  - `tests/Feature/Livewire/Parc/CapabilitiesTabCustomizeScopingTest.php` — write-through délégué positif-seul (AC#1).
  - `tests/Feature/Livewire/Admin/ParcDefaultsUpstreamLockTest.php` — non-admin bloqué sur registry-tab (AC#3).
  - `docs/qa/domains/rights-management.md` — Section 14 (append).
  - `docs/qa/README.md` — libellé domaine `rights-management` (ligne uniquement).
- **Nouveaux** : aucun fichier de code/test nouveau **obligatoire** (extension des suites existantes). Créer un nouveau fichier test **seulement** si l'extension d'un fichier existant gêne la lisibilité.
- **Aucune** migration, **aucune** route, **aucune** permission Spatie, **aucun** modèle.
- **⚠️ Working tree partagé** : ne **PAS** toucher au travail des stories voisines (30-x) si non committé.

### References

- [Source: _bmad-output/codeReviews/29-6.md#P1] — origine de la story (plancher GLOBAL `app.customize` rebloque le délégué positif-seul ; `modify-capability` dual-purpose ; P1 et P4 même cause racine).
- [Source: _bmad-output/implementation-artifacts/29-6-scoper-app-customize-par-workstationgroup.md#AC1] — AC#1 annoté « habilitation reportée 29-8 » + note post-review.
- [Source: _bmad-output/implementation-artifacts/29-2-refuser-modification-item-verrouille.md#T2] — pose de `modify-capability` / `CapabilityPolicy::modify` (plancher `app.customize` historique + déviation tracée « server.admin sans app.customize »).
- [Source: app/Policies/CapabilityPolicy.php:62-73] — `modify()` (plancher à retirer).
- [Source: resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php:521-560] — `guardCustomize()` (scopé, avant) + `authorizeUpstream()` (double-branche).
- [Source: resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php:204-236] — `guardAdmin()` (`server.admin`, avant) + `authorizeUpstream()`.
- [Source: tests/Unit/Policies/CapabilityPolicyTest.php:60-107] — contrat unitaire à réviser.
- [Source: tests/Feature/Livewire/Parc/CapabilitiesTabCustomizeScopingTest.php:91-98,140-320] — helper `positiveDelegateOfA` + cas existants.
- [Source: tests/Feature/Livewire/Admin/ParcDefaultsUpstreamLockTest.php:59-152] — patron registry-tab / `Gate::before` ciblé.
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#NFR1] — enforcement par Gates scopés (prérequis Epic 31).
- [Source: mémoires projet — drift_policy_strict_only, permissive_floor_least_specific, delegation_enforcement_wpkg_gap, test_suite_env_and_systemic_fixes, sqlite_tests_no_varchar_enforcement, phpunit_test_env_host_vs_vm, root_is_laravel, zero_prod_publish_is_test, no_legacy_transition_state].

## Dépendances

- **Amont** :
  - **29.6** (review/to-validate) — **patron + AC#1 reporté** : 29.8 livre l'habilitation que 29.6 a explicitement reportée (note post-review AC#1). Le guard scopé `customize-workstationGroup` qu'on **utilise sans modifier** vient de 29.6.
  - **29.2** (review) — **a posé `modify-capability` / `CapabilityPolicy::modify`** : 29.8 en retire le plancher de droit (la frontière 29.2 est **assumée et corrigée**, pas violée — c'est le suivi explicite tracé par la review 29.6). Le **verrou amont 29.2** est préservé (seul motif restant).
  - Système de **délégations Epics 7.x** (`PermissionService::canOnWorkstationGroup`, `grantDelegation`) — livré et stable.
- **Aval** : **Epic 31** (enforcement de l'install par périmètre) — 29.8 est un **prérequis** (sans plancher global parasite, l'enforcement par parc devient cohérent).
- **Indépendant** d'Epic 28/30 (ne lit ni n'écrit le contrat amont ; ne touche pas le résolveur).

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, `RefreshDatabase`. **Jamais la VM.** [Source: mémoire — phpunit_test_env_host_vs_vm]
- Filtres ciblés : `php artisan test --filter "CapabilityPolicy|CapabilitiesTab|CapabilitiesOverride|ParcDefaults|WorkstationGroupPolicy|UpstreamLockResolver"`.
- Couverture obligatoire :
  - **Write-through positif-seul** (AC#1) : délégué de A écrit/édite/retire sur A (échouait avant 29.8).
  - **Refus hors-périmètre** (AC#2) : délégué de A refusé sur B, 0 écriture/trace.
  - **Registry-tab non-admin** (AC#3) : 403 `guardAdmin`, défaut inchangé.
  - **Verrou amont** (AC#4) : refus sur les **deux** surfaces (capacité verrouillée), seul motif restant.
  - **Standalone/permissive** (AC#5) : `modify-capability` autorise (pas de plancher).
  - **Admin global** (AC#6) : write-through inchangé.
  - **Contrat unitaire révisé** (AC#7) : `modify(user(false), capNonLocked) === true`, `modify(*, null) === true`, `modify(*, capLocked) === false`.
  - **Non-régression** : `CapabilityPolicy`, `CapabilitiesTab*`, `ParcDefaults*`, `WorkstationGroupPolicy*`, `UpstreamLockResolver`, `CapabilitiesOverrideAudit` verts.
- **Pièges** : ⚠️ révision du contrat unitaire (sinon fausse régression) ; bootstrap Spatie before-hook (vrais `User` + `Permission::firstOrCreate('app.customize')` — déjà câblé) ou users sans `HasRoles` ; SQLite ne borne pas les décisions (tester booléens/écritures/exceptions). [Source: mémoires — test_suite_env_and_systemic_fixes, sqlite_tests_no_varchar_enforcement]

## Recommandation Modèle Dev

**`opus`.**

Justification : **faible volume** (une condition retirée dans une policy, deux commentaires, des tests révisés) mais **haut risque de régression de sécurité** où le **jugement** prime sur l'exécution. Le dev doit trancher correctement l'**effet de bord defense-in-depth** (garder la double-branche message et savoir *pourquoi*), comprendre que le **contrat unitaire de `modify()` change** et réviser les tests **sans** masquer une vraie régression, **ne pas casser** le **dual-purpose** (`server.admin` global sur registry-tab vs scopé sur capabilities-tab), et **prouver** que la sécurité migre vers les surfaces plutôt que de disparaître. C'est exactement le type de correctif court mais piégeux qui exige un raisonnement d'architecte de sécurité ; la review sera routée vers le modèle opposé pour une seconde paire d'yeux sur les non-régressions.

## Dev Agent Record

### Agent Model Used

opus `claude-opus-4-8[1m]` (Dev BMAD).

### Debug Log References

- `php artisan test --filter "CapabilityPolicy|CapabilitiesTab|CapabilitiesOverride|ParcDefaults|WorkstationGroupPolicy|UpstreamLockResolver"` → **120 passed (257 assertions)**, 0 failed.
- `php artisan test tests/Unit/Policies/CapabilityPolicyTest.php` → **5 passed (6 assertions)** (contrat unitaire révisé).
- Non-régression complète `php artisan test` → **4593 passed, 141 skipped, 1 incomplete, 8 risky, 1 deprecated, 0 failed (24162 assertions)**.
- `php -l app/Policies/CapabilityPolicy.php` → No syntax errors.
- Garde-fous : `grep central` sur les 3 fichiers code → **0 introduit** (seule occurrence = la ligne GARDE-FOU R3 PRÉ-EXISTANTE qui INTERDIT le mot) ; `grep hasPermission|->can('app.customize')|ChecksPermissions` dans `CapabilityPolicy.php` → **0** ; double-branche « Vous n'avez pas le droit » présente 1× dans CHACUNE des 2 surfaces ; `git status` → aucune migration, aucune permission Spatie, aucun golden/`FROZEN_STATE_HASH`/`ContractV1`.

### Completion Notes List

- **T0** — Vérifié par lecture de code + grep que `modify-capability` n'est invoqué QUE par les deux `authorizeUpstream()` (capabilities-tab, registry-tab), chacun précédé de son contrôle de droit (`guardCustomize()` scopé / `guardAdmin()` global). Aucun autre call-site `Gate::authorize/allows('modify-capability')` ni `@can` non gardé. Retrait du plancher → aucun chemin d'écriture sans contrôle de droit.
- **T1** — `CapabilityPolicy::modify()` réécrit : `if ($capability === null) return true; return ! $this->lockResolver->isCapabilityLocked($capability);`. Retiré `use ChecksPermissions;` + l'import `App\Policies\Traits\ChecksPermissions` (plus aucune méthode ne les utilise — `modify()` est la seule). Conservés `RegistersGates`, `$gates`, signature `modify(?Authenticatable $user, …)`, DI `UpstreamLockResolver`. PHPDoc classe + méthode réécrits (dual-purpose, droit filtré par surface, `$user` inutilisé documenté, R3 respecté).
- **T2** — Double-branche du `catch` CONSERVÉE dans les deux `authorizeUpstream()` (code inchangé). Commentaires mis à jour : depuis 29.8 le gate ne refuse plus que pour verrou amont (droit filtré en amont par guardCustomize/guardAdmin) → branche « pas le droit » théoriquement inatteignable mais gardée en defense-in-depth (ceinture+bretelles) contre un futur appelant non gardé.
- **T3** — Contrat unitaire révisé (changement VOULU, documenté) : `deny_without_app_customize` → `right_is_no_longer_enforced_at_policy_level` (`modify(user(false), capNonLocked) === true`) ; `null_capability_falls_back_to_right_only` → `null_capability_is_always_allowed` (`modify(*, null) === true`) ; `allow_when_not_locked_with_right`/`allow_when_permissive_with_right` renommés sans « with_right » (toujours verts, droit indifférent) ; `deny_when_capability_is_upstream_locked_even_with_right` → `deny_when_capability_is_upstream_locked` (verrou = seul motif). Docblock de classe enrichi pour prévenir une fausse lecture en régression de sécurité.
- **T4** — `CapabilitiesTabCustomizeScopingTest` : ajout du write-through du délégué positif-seul via `positiveDelegateOfA()` (déjà présent) — `positive_delegate_can_complete_write_through_on_a` (openAdd→saveOverride), `…can_open_edit_on_a`, `…can_remove_override_on_a` (AC#1, échouaient avant 29.8) ; `positive_delegate_is_forbidden_on_b_without_write_or_audit_trace` (AC#2 M4) ; `positive_delegate_is_still_blocked_by_upstream_lock` (AC#4 — verrou mord même délégué scopé). `global_admin_can_save_override_on_a_and_b` (AC#6) inchangé/vert. Docblock de classe mis à jour (plancher retiré, habilitation livrée).
- **T5** — `ParcDefaultsUpstreamLockTest` : ajout `non_admin_is_blocked_on_registry_tab` (AC#3 — acteur `app.customize` sans `server.admin` → 403 guardAdmin, défaut + overrides_locked inchangés). Helper `actAsNonAdmin()` ajouté. Note : la fermeture au mount est aussi couverte par `AdminSettingsParcDefaultsPageTest::registry_tab_gate_blocks_mount_without_server_admin` (réutilisée, pas dupliquée) ; le test 29.8 prouve en plus « défaut intact après retrait du plancher ». Tests verrou amont admin (`save_default_…`/`toggle_lock_…`) et write-through admin (`non_locked_…`) restent verts.
- **T6** — `docs/qa/domains/rights-management.md` : Section 14 appendée (après Section 13, append-only) avec 5 scénarios manuels (14.1 habilitation positif-seul / 14.2 refus parc B / 14.3 non-admin registry-tab / 14.4 verrou amont sur les 2 surfaces / 14.5 admin global) + bloc couverture automatisée. `docs/qa/README.md` : SEULE la ligne `rights-management` enrichie (mention 29.8 + Story 29.8 dans la liste).
- **T7** — Validation HÔTE (php8.4 + pdo_sqlite) : suites ciblées + non-régression complète vertes ; garde-fous R3/plancher/double-branche/git status OK.
- **Déviation / point d'attention review** : aucune déviation fonctionnelle. (a) Le grep `central` retourne 1 ligne = la directive GARDE-FOU R3 elle-même (PRÉ-EXISTANTE, interdit le mot) — non introduite. (b) `sprint-status.yaml` apparaît modifié sur `last_updated` (2026-06-25→2026-06-27) indépendamment de mon travail (artefact worktree partagé) — bénin, conforme à la date attendue. (c) AC#3 : la fermeture effective du non-admin est au MOUNT (guardAdmin en `mount()`), donc le guard action-level d'openEdit/saveDefault/toggleLock est inatteignable pour un non-admin (defense-in-depth) — testé via le mount + assertions « défaut intact ».

### File List

- `app/Policies/CapabilityPolicy.php` — `modify()` (retrait du plancher de droit), retrait `use ChecksPermissions` + import, PHPDoc classe + méthode (M).
- `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` — commentaire `catch` de `authorizeUpstream()` (double-branche conservée) (M).
- `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` — commentaire `catch` de `authorizeUpstream()` (double-branche conservée) (M).
- `tests/Unit/Policies/CapabilityPolicyTest.php` — révision du contrat unitaire (docblock + 4 tests renommés/révisés) (M).
- `tests/Feature/Livewire/Parc/CapabilitiesTabCustomizeScopingTest.php` — write-through délégué positif-seul (5 tests ajoutés) + docblock (M).
- `tests/Feature/Livewire/Admin/ParcDefaultsUpstreamLockTest.php` — `non_admin_is_blocked_on_registry_tab` + helper `actAsNonAdmin()` (M).
- `docs/qa/domains/rights-management.md` — Section 14 (append) (M).
- `docs/qa/README.md` — libellé domaine `rights-management` (1 ligne) (M).
