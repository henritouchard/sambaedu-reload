# Story 29.1: Scoper le Gate `wpkg.*` par périmètre

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **que les permissions `wpkg.*` soient évaluées par périmètre (`workstationGroup` / poste rattaché) et non par un Gate global**,
so that **un verrou amont sur les apps soit réellement opposable et non du théâtre, et qu'une délégation WPKG par-salle ait enfin un effet (NFR1, prérequis d'Epic 31)**.

> Story **d'enforcement / correctif d'architecture** ouvrant l'Epic 29. Elle **ne touche pas** le contrat amont (Epic 28) : c'est un **prérequis structurel** au verrou applicatif. Sans ce correctif, un item amont verrouillé sur les apps resterait inopposable (le canal WPKG n'écoute aujourd'hui qu'un droit Spatie **global**, aveugle au périmètre).
>
> **Indépendance vs Epic 28 (en review)** : aucune dépendance dure. La 29.1 corrige une dette d'autorisation **préexistante** du domaine WPKG/délégations. Elle se fait sans aucune table ni modèle du contrat amont.
>
> **⚠️ Décision Henri (2026-06-26) — pas de compat ascendante exigée** : il n'y a **aucun environnement de prod à préserver**. Le **seul** invariant intangible est l'**enrôlement controlHub** (qui reste identique). Tout le reste peut être **intégralement cassé** sans impact. Conséquence pour cette story : les garde-fous estampillés « non-régression » ci-dessous ne sont **pas bloquants** — on vise le bon design, pas la rétrocompat. En particulier le no-op `Auth::check()===false` du garde service (AC #6 / T4) est une **propreté**, pas une contrainte dure : casser un appelant non-web (console/seed/clone) est acceptable. Le fallback global admin (AC #2) reste, lui, conservé — non par compat, mais parce que c'est la **sémantique voulue** des délégations. [Source: mémoires projet — zero_prod_publish_is_test, no_legacy_transition_state]

## Contexte du trou (constat de code vérifié 2026-06-26)

SE5 possède **déjà** un système de délégation scopé mûr (Epics 7.x) :
- table `delegations` (`user_id`, `workstation_group_id`, `permission_id` Spatie, `is_negative`, `expires_at`, `granted_by`) + `delegation_history` append-only ;
- primitive `PermissionService::canOnWorkstationGroup(User, string $permissionName, WorkstationGroup): bool` [app/Services/PermissionService.php:368] qui honore **déjà** : exclusion négative active (prévaut), droit global Spatie (fallback), délégation positive active, et l'expiration via le scope `->active()` ;
- primitive `PermissionService::getAuthorizedWorkstationGroups(User, string): Collection` [app/Services/PermissionService.php:502].

**Or l'enforcement WPKG ignore tout cela.** Il passe par un Gate **global** :
- Page groupe : `ensureWpkgAssignAuthorized()` [resources/views/pages/parc/groups/[id]/index.blade.php:1688] fait `Gate::authorize('wpkg.assign')` (ligne 1691), **sans** passer `$this->group`. Appelé ~11× (lignes 1427, 1440, 1455, 1478, 1491, 1512, 1530, 1560, 1630, 1663).
- Page machine : `ensureWpkgAssignAuthorized()` [resources/views/pages/parc/machines/[id]/index.blade.php:930] fait `Gate::authorize('wpkg.assign')` (ligne 933), **sans** résoudre la salle du poste. Appelé ~9× (lignes 792, 805, 818, 840, 853, 876, 892, 901, 919).
- Partials Blade : `@can('wpkg.assign')` **sans modèle** dans `resources/views/pages/parc/groups/[id]/_partials/wpkg-assignment-tab.blade.php` (lignes 12, 36, 68, 91, 118) et dans `resources/views/pages/parc/machines/[id]/_partials/wpkg-assignment-tab.blade.php` (50, 78, 121, 151) + `…/wpkg-options-tab.blade.php` (`@can` ligne 14, `@cannot` ligne 48).
- `AppProfileService` [app/Services/AppProfile/AppProfileService.php] : **aucun** contrôle d'autorisation (couche service nue).

`wpkg.assign` n'a **pas** de `Gate::define` explicite : c'est une permission Spatie exposée comme gate global par le `PermissionServiceProvider` de Spatie. Le Gate est donc **strictement global**, aveugle à `delegations` et au périmètre.

**Conséquences métier :**
1. Une délégation WPKG accordée sur **une salle** à un user non-admin ne fait **rien** (bloqué partout, faute du droit Spatie global). → délégation par-salle = mort.
2. Un technicien avec le droit global `wpkg.assign` agit sur **toutes** les salles. → aucun périmètre.
3. Un futur verrou amont sur les apps (Epic 31) ne peut pas être opposé par salle. → NFR1 non tenu.

## Acceptance Criteria

1. **Given** un utilisateur non-admin disposant d'une délégation **positive active** `wpkg.assign` sur la salle A (et pas sur la salle B),
   **When** il tente une action d'assignation WPKG **sur la salle A** (page groupe ou page machine d'un poste de A),
   **Then** l'action est **autorisée** ;
   **And When** il tente la même action **sur la salle B** (ou un poste de B),
   **Then** l'action est **refusée** par le Gate scopé.

2. **Given** un administrateur/technicien disposant du droit **global** Spatie `wpkg.assign`,
   **When** il agit sur **n'importe quelle** salle ou poste,
   **Then** l'action reste **autorisée partout** (non-régression : le droit global continue de tout couvrir — fallback préservé, cf. patron `WorkstationGroupPolicy::manage`).

3. **Given** un utilisateur avec une délégation `wpkg.assign` sur la salle A **mais une exclusion négative active** `wpkg.assign` sur cette même salle A,
   **When** il tente une action WPKG sur A,
   **Then** l'action est **refusée** (l'exclusion négative prévaut, même sur droit global — comportement déjà porté par `canOnWorkstationGroup`).

4. **Given** un utilisateur dont la délégation `wpkg.assign` sur la salle A est **expirée** (`expires_at` dépassé),
   **When** il tente une action WPKG sur A,
   **Then** l'action est **refusée** (le scope `->active()` exclut les délégations expirées).

5. **Given** une action WPKG est tentée **au niveau page machine**,
   **When** le Gate scopé s'évalue,
   **Then** le périmètre retenu est la **salle physique de rattachement du poste** (`Workstation::physicalRoom`) ;
   **And** si le poste n'a **aucune** salle physique (poste nomade/non rattaché), le comportement se rabat sur le droit **global** (pas de scope ⇒ comportement actuel pour ce cas marginal, pas de fausse ouverture).

6. **Given** l'enforcement est aussi posé en **couche service** (`AppProfileService`, defense-in-depth),
   **When** une mutation WPKG scopée par parc/poste est appelée **dans un contexte utilisateur authentifié** (web/Livewire) sans droit sur ce périmètre,
   **Then** le service **refuse** (`AuthorizationException`) ;
   **And When** la même méthode est appelée **hors contexte utilisateur** (console/agent/seed/`cloneConfiguration` système, `Auth::check() === false`),
   **Then** **aucun** contrôle n'est appliqué (non-régression des appelants non-web).

7. **Given** les surfaces UI (partials WPKG groupe + machine),
   **When** l'utilisateur n'a pas le droit sur le périmètre courant,
   **Then** les contrôles d'écriture WPKG sont **masqués/désactivés** de façon cohérente avec l'enforcement serveur (`@can`/`@cannot` reçoivent désormais le **modèle de périmètre**), sans « bouton qui ne fait rien ».

8. **Given** la suite de tests HÔTE (php8.4 + sqlite),
   **When** elle s'exécute,
   **Then** les cas positifs (délégué dans son périmètre), négatifs (hors périmètre / négative / expirée) et de non-régression (admin global partout, poste sans salle, appelant non authentifié) sont **couverts et verts**, **sans aucune régression** des suites existantes (`PermissionService`, délégations, WPKG/AppProfile).

## Tasks / Subtasks

- [x] **T0 — Cadrage du périmètre des permissions `wpkg.*`** (AC: #1, #2)
  - [x] Confirmer dans le code que **seul** `wpkg.assign` est effectivement enforcé sur les chemins d'écriture (grep `wpkg.assign|wpkg.add|wpkg.create` dans `resources/views/pages/parc/**` et `app/**`). [Ancrages connus : SambaPermission.php:53-55 = `WpkgAssign='wpkg.assign'`, `WpkgAdd='wpkg.add'`, `WpkgCreate='wpkg.create'`.]
  - [x] **Décision** : scoper `wpkg.assign` (chemin d'écriture réel). Si `wpkg.add`/`wpkg.create` s'avèrent enforcés sur un chemin scopable, les traiter au même patron ; sinon **documenter** qu'ils restent globaux (création de catalogue = acte serveur global, pas par-salle) — ne pas sur-scoper.

- [x] **T1 — Introduire le Gate scopé via `WorkstationGroupPolicy`** (AC: #1, #2, #3, #4)
  - [x] Ajouter une méthode `assignWpkg(?Authenticatable $user, ?WorkstationGroup $group = null): bool` dans `app/Policies/WorkstationGroupPolicy.php`, **calquée à l'identique** sur `manage()` [WorkstationGroupPolicy.php:123-131] :
    - si `$group !== null` **et** `canCheckDelegation($user, $group)` (i.e. user `App\Models\User` + `$group->is_physical`) → `app(PermissionService::class)->canOnWorkstationGroup($user, 'wpkg.assign', $group)` ;
    - sinon → fallback global `hasPermission($user, 'wpkg.assign')` (trait `ChecksPermissions`).
  - [x] Enregistrer le gate : ajouter `'assign-wpkg-workstationGroup' => 'assignWpkg'` dans `WorkstationGroupPolicy::$gates` [WorkstationGroupPolicy.php:30-38]. (Enregistrement auto via `RegistersGates::registerGates()` déjà appelé dans `AuthServiceProvider` ligne 56 — **rien d'autre à câbler**.)
  - [x] PHPDoc de la méthode : rappeler que l'exclusion négative et l'expiration sont **déjà** honorées par `canOnWorkstationGroup` (ne pas réimplémenter), et que le fallback global préserve la non-régression admin/technicien.
  - [x] **Ne pas** créer de `WpkgPolicy` dédiée ni de nouveau service : réutiliser intégralement le patron `manage`/`computer.control` existant.

- [x] **T2 — Brancher le Gate scopé sur la page GROUPE** (AC: #1, #2, #7)
  - [x] `resources/views/pages/parc/groups/[id]/index.blade.php` : dans `ensureWpkgAssignAuthorized()` (ligne 1688), remplacer `Gate::authorize('wpkg.assign')` (1691) par `Gate::authorize('assign-wpkg-workstationGroup', $this->group)`. Conserver le `try/catch` + toast existant.
  - [x] Partial `…/groups/[id]/_partials/wpkg-assignment-tab.blade.php` : remplacer les `@can('wpkg.assign')` (12, 36, 68, 91, 118) par `@can('assign-wpkg-workstationGroup', $group)` (le `$group` est disponible dans la page groupe).
  - [x] Vérifier qu'aucun **autre** point de la page groupe ne court-circuite l'autorisation WPKG (les 11 sites appellent bien `ensureWpkgAssignAuthorized()` : 1427/1440/1455/1478/1491/1512/1530/1560/1630/1663 — confirmer aucun ajout récent hors helper).

- [x] **T3 — Brancher le Gate scopé sur la page MACHINE (résolution de la salle du poste)** (AC: #1, #5, #7)
  - [x] `resources/views/pages/parc/machines/[id]/index.blade.php` : dans `ensureWpkgAssignAuthorized()` (ligne 930), remplacer `Gate::authorize('wpkg.assign')` (933) par `Gate::authorize('assign-wpkg-workstationGroup', $this->workstation->physicalRoom)`. (`Workstation::getPhysicalRoomAttribute()` [app/Models/Workstation.php:176] renvoie la salle physique de rattachement, `null` si aucune.)
  - [x] Cas `physicalRoom === null` (poste nomade/non rattaché) : le Gate reçoit `null` ⇒ la policy se rabat sur le droit **global** (AC #5). Ajouter un commentaire explicite (pas de fausse ouverture : sans salle, seul l'admin global passe).
  - [x] Partials machine : `@can('wpkg.assign')` → `@can('assign-wpkg-workstationGroup', $this->workstation->physicalRoom)` dans `…/machines/[id]/_partials/wpkg-assignment-tab.blade.php` (50, 78, 121, 151) et `…/wpkg-options-tab.blade.php` (`@can` 14, `@cannot('wpkg.assign')` 48 → `@cannot('assign-wpkg-workstationGroup', $this->workstation->physicalRoom)`). S'assurer que `$this->workstation` est chargé (relation `physicalRooms` eager-loadée pour éviter N+1 dans la boucle d'options).

- [x] **T4 — Defense-in-depth : enforcement en couche service `AppProfileService`** (AC: #6)
  - [x] Ajouter un garde privé, ex. `assertCanAssignWpkgOnGroup(?WorkstationGroup $group): void`, qui : (a) **no-op** si `Auth::check() === false` (console/agent/seed — préserve les appelants non-web, NFR de non-régression) ; (b) sinon `Gate::authorize('assign-wpkg-workstationGroup', $group)` (lève `AuthorizationException` si refus). [`Auth` est déjà importé : AppProfileService.php:18.]
  - [x] Appeler ce garde **en tête** des méthodes scopées par périmètre :
    - `addApplicationsToWorkstationGroup(int $groupId, …)` [:387] et `removeApplicationsFromWorkstationGroup(…)` [:426] → résoudre `WorkstationGroup::find($groupId)` puis garder.
    - `addApplicationsToWorkstation(int $workstationId, …)` [:463] et `removeApplicationsFromWorkstation(…)` [:500] → résoudre `Workstation::find($workstationId)?->physicalRoom` puis garder.
    - Méthodes profil→parcs `addWorkstationGroups(int $profileId, array $groupIds)` [:252] / `removeWorkstationGroups(…)` [:284] : garder **par groupe** sur chaque `$groupId` (un refus sur un seul groupe = refus de l'opération). Si trop intrusif pour le périmètre de cette story, **documenter** le report explicite (mais privilégier la couverture : c'est un chemin d'assignation WPKG par parc).
  - [x] **Attention non-régression** : ne **pas** garder les méthodes purement profil-niveau non scopées par parc (`createProfile`, `updateProfile`, `deleteProfile`, `addApplications`/`removeApplications` sur profil) — elles relèvent d'un droit global de gestion de catalogue, pas d'un périmètre de salle. Garder **uniquement** les chemins qui matérialisent une assignation **sur un parc/poste**.
  - [x] `cloneConfiguration` [:556] : appelée potentiellement en contexte système — vérifier le contexte ; si elle reste appelée depuis l'UI sous un user, le garde `Auth::check()` la couvre naturellement (scoper sur le **parc cible**). Documenter le choix.

- [x] **T5 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#8)
  - [x] **Policy unitaire** — `tests/Unit/Policies/WorkstationGroupPolicyWpkgTest.php` (ou étendre un test policy existant) :
    - délégué positif actif sur salle A → `assignWpkg(user, A) === true`, `assignWpkg(user, B) === false` (AC #1) ;
    - admin global `wpkg.assign` → `true` sur A, B, et `assignWpkg(user, null) === true` (AC #2) ;
    - exclusion négative active sur A (même avec global) → `false` (AC #3) ;
    - délégation expirée sur A → `false` (AC #4) ;
    - groupe **logique** (`is_physical = false`) → fallback global uniquement (cf. `canCheckDelegation`).
  - [x] **Feature page groupe / machine** (ou Livewire `Livewire::test(...)`) :
    - délégué de A : action WPKG sur la page de A passe ; sur la page de B lève `AuthorizationException` (AC #1) ;
    - page machine d'un poste de A : autorisé pour le délégué de A, refusé pour le délégué de B (AC #5) ;
    - poste sans `physicalRoom` : seul l'admin global passe (AC #5).
  - [x] **Service `AppProfileService`** — `tests/Feature/AppProfile/AppProfileServiceWpkgScopingTest.php` :
    - sous `actingAs(délégué de A)` : `addApplicationsToWorkstationGroup(A, …)` OK, `addApplicationsToWorkstationGroup(B, …)` lève `AuthorizationException` (AC #6) ;
    - **sans** utilisateur authentifié (`Auth::check() === false`) : la même méthode **ne lève pas** et s'exécute (AC #6, non-régression agent/console).
  - [x] **Non-régression** : exécuter les suites existantes du domaine (`PermissionService`, délégations, AppProfile/WPKG) et confirmer 0 régression.
  - [x] **Piège SQLite** : ne **pas** s'appuyer sur des contraintes varchar/enum PG (non appliquées en SQLite). Tester les **décisions d'autorisation** (booléens / exceptions), pas des bornes de colonnes. Pour `expires_at`, fixer une date passée explicite (`now()->subDay()`), pas une longueur. [Source: mémoire projet — sqlite_tests_no_varchar_enforcement]

- [x] **T6 — Runbook QA (domaine `rights-management`)** (AC: #1–#7)
  - [x] **Append** une section `## Story 29.1 — Scoping du Gate WPKG par périmètre` à la fin de `docs/qa/domains/rights-management.md` (le fichier existe), en suivant la convention `docs/qa/README.md` (sections numérotées stables, scénarios `### Scénario N.M`). Couvrir manuellement : délégué WPKG sur une salle (voit/agit sur sa salle, pas les autres), technicien global (agit partout), exclusion négative, expiration, page machine (salle du poste), poste nomade.
  - [x] Mettre à jour la ligne du domaine `rights-management` dans `docs/qa/README.md` (« Domaines couverts ») en ajoutant la mention `Story 29.1` (scoping WPKG) au libellé existant.

- [x] **T7 — Validation finale**
  - [x] `php artisan test --filter "Wpkg|AppProfile|Delegation|Permission"` sur HÔTE → vert.
  - [x] Relecture grep de garde-fou : plus aucun `Gate::authorize('wpkg.assign')` **global** ni `@can('wpkg.assign')` **sans modèle** dans les pages parc (groupe + machine + partials).
  - [x] Vérifier qu'aucun chemin agent/desired-state ne dépend d'`AppProfileService` en contexte authentifié (le garde `Auth::check()` neutralise ces appels).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 29.1

**DANS** : un Gate scopé `assign-wpkg-workstationGroup` (méthode policy `WorkstationGroupPolicy::assignWpkg`), branchement sur les 2 pages WPKG (groupe + machine avec résolution de salle) et leurs partials, enforcement defense-in-depth dans `AppProfileService`, tests, runbook QA.

**HORS** (ne pas déborder) :
- **Lecture/écriture du contrat amont** (items verrouillés/permissifs) → Epic 28 (modèle) / Story 29.2 (refus d'édition d'un item verrouillé). La 29.1 livre **seulement** l'infrastructure d'autorisation scopée que 29.2+ et Epic 31 consommeront.
- **Refus de modification d'un item verrouillé** (FR3) → Story 29.2. Ne pas implémenter ici la notion de verrou amont.
- **Override permissif par WG** (FR4) → Story 29.3.
- **Bornage de l'install au catalogue amont** (FR5) → Epic 31 (qui **dépend** de cette story).
- **Drift STRICT / audit des overrides** (NFR2/NFR5) → Story 29.5.
- Pas de nouvelle table, pas de migration, pas de nouveau modèle. Réutilisation pure de l'existant (`delegations`, `PermissionService`, `WorkstationGroupPolicy`).

### Patrons de référence existants à IMITER (ne rien réinventer)

- **`WorkstationGroupPolicy::manage()`** [app/Policies/WorkstationGroupPolicy.php:123-131] : exactement le patron cible — `canOnWorkstationGroup($user, 'computer.control', $group)` si group physique, sinon fallback global. `assignWpkg` en est la copie pour `wpkg.assign`.
- **`WorkstationGroupPolicy::$gates`** [:30-38] + **`RegistersGates`** [app/Policies/Traits/RegistersGates.php] : ajout d'une entrée suffit, l'enregistrement est automatique (`AuthServiceProvider` ligne 56).
- **`ChecksPermissions::hasPermission()`** [app/Policies/Traits/ChecksPermissions.php:15] : fallback global propre (`$user->can($perm)`), gère `$user === null`.
- **`PermissionService::canOnWorkstationGroup()`** [app/Services/PermissionService.php:368] : **honore déjà** négative active (prévaut sur global), global Spatie (fallback), positive active, expiration (`->active()`). **NE PAS** réimplémenter cette logique.
- **`Printer::scopeForUser()`** [app/Models/Printer.php:146] : exemple de **liste filtrée** par `getAuthorizedWorkstationGroups($user, 'server.admin')` — utile si une liste WPKG devait être filtrée (pas requis ici, mais montre le 2e usage de la primitive).
- **`computer.view` / `computer.control`** : déjà scopés par salle de cette manière (cf. `WorkstationGroupPolicy::view`/`manage`) — `wpkg.assign` s'aligne dessus.

### Garde-fous projet CRITIQUES (contraintes de la story)

- **NON-RÉGRESSION admin/technicien (BLOQUANT)** : le **fallback global** doit rester intact. Un user avec le droit Spatie global `wpkg.assign` continue de tout couvrir (AC #2). Le risque #1 de cette story est de transformer un droit global en droit scopé et de **casser les techniciens existants**. Le patron `manage` garantit ce fallback — **ne pas s'en écarter**.
- **Appelants non-web (PROPRETÉ, non bloquant)** : `AppProfileService` est aussi appelé hors contexte HTTP (console, agent desired-state, seeders, `cloneConfiguration` système). Le garde de couche service **devrait** être inerte si `Auth::check() === false` — c'est le design propre. Mais **suite à la décision Henri (pas de compat ascendante, pas de prod)**, en casser un n'est **pas bloquant** : préférer le no-op pour rester propre, sans en faire un critère d'échec de la story. [Source: mémoire projet — delegation_enforcement_wpkg_gap : « délégations scopées WorkstationGroup MAIS wpkg.* Gate global non scopé » — c'est précisément ce trou.]
- **Exclusion négative & expiration** : déjà gérées par `canOnWorkstationGroup` via `.negative()->active()` / `.positive()->active()` — la story doit **prouver par test** qu'elles tiennent à travers le nouveau gate (AC #3, #4), pas les recoder.
- **Vocabulaire R3 (garde-fou projet contrat amont)** : aucun mot « central » introduit. (Sans objet ici — aucun nouvel identifiant amont — mais la règle reste.)
- **Page machine = scope = salle du poste** : ne pas confondre `Workstation::groups` (tous groupes, logiques inclus) avec `Workstation::physicalRoom` (la salle physique). Le scope d'autorisation = **salle physique** (`is_physical = true`), cohérent avec `canCheckDelegation` qui n'accepte que les groupes physiques. [app/Models/Workstation.php:157 (`physicalRooms`), :176 (`getPhysicalRoomAttribute`).]

### Pourquoi `assign-wpkg-workstationGroup` (nom du gate)

Convention repo `<verbe>-workstationGroup` (cf. `manage-workstationGroup`, `update-workstationGroup`). Le gate accepte le `WorkstationGroup` en 2ᵉ argument (`Gate::authorize($name, $group)` / `@can($name, $group)`). On **conserve** la permission Spatie `wpkg.assign` comme atome de droit ; on ajoute seulement une **enveloppe de policy scopée** par-dessus. Le Gate global Spatie `wpkg.assign` continue d'exister (utilisé en fallback par la policy) ; on cesse simplement de l'**invoquer directement** sur les chemins par-périmètre.

### Architecture & conventions

- **Filesystem-router** : pages sous `resources/views/pages/**`, Livewire SFC (logique PHP en tête du `.blade.php`). Les helpers `ensureWpkgAssignAuthorized()` sont des méthodes privées du composant Livewire de page — modification ponctuelle de leur corps.
- **Toasts** : trait `WithToasts` déjà utilisé (le `catch` de `ensureWpkgAssignAuthorized` appelle `toastError`) — conserver le message d'erveur utilisateur, l'`AuthorizationException` est relancée pour bloquer l'action.
- **Tests HÔTE** : php8.4 + `pdo_sqlite`, `RefreshDatabase`, **jamais la VM** (sans `pdo_sqlite`). [Source: mémoire projet — phpunit_test_env_host_vs_vm]
- **PHP-FPM user = www-admin** : sans impact direct (tests HÔTE), pertinent seulement si exécution VM demandée (worktree git → **interdit** ici).

### Project Structure Notes

- **Modifiés** : `app/Policies/WorkstationGroupPolicy.php` (+1 méthode, +1 entrée `$gates`), `resources/views/pages/parc/groups/[id]/index.blade.php` (corps du helper), `resources/views/pages/parc/groups/[id]/_partials/wpkg-assignment-tab.blade.php`, `resources/views/pages/parc/machines/[id]/index.blade.php` (corps du helper), `resources/views/pages/parc/machines/[id]/_partials/wpkg-assignment-tab.blade.php`, `resources/views/pages/parc/machines/[id]/_partials/wpkg-options-tab.blade.php`, `app/Services/AppProfile/AppProfileService.php` (+1 garde privé + appels), `docs/qa/domains/rights-management.md` (append), `docs/qa/README.md` (libellé domaine).
- **Nouveaux** : fichiers de test sous `tests/Unit/Policies/` et `tests/Feature/AppProfile/` (+ feature Livewire pages si pertinent).
- **Aucune** migration, **aucun** modèle, **aucune** route nouvelle. **Racine = projet Laravel** (pas de préfixe `laravel/`). [Source: mémoire projet — root_is_laravel]

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 29.1] — AC d'origine (NFR1, prérequis Epic 31).
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#NFR1] — « `wpkg.*` gaté par un Gate global non scopé → à corriger sinon verrou apps inopérant ».
- [Source: app/Services/PermissionService.php:368, :502] — `canOnWorkstationGroup` / `getAuthorizedWorkstationGroups` (primitives à réutiliser).
- [Source: app/Policies/WorkstationGroupPolicy.php:123-131, :30-38] — patron `manage` + `$gates` (à copier pour `assignWpkg`).
- [Source: app/Policies/Traits/RegistersGates.php ; app/Policies/Traits/ChecksPermissions.php] — enregistrement auto + fallback global.
- [Source: app/Enums/SambaPermission.php:53-55] — `wpkg.assign` / `wpkg.add` / `wpkg.create`.
- [Source: resources/views/pages/parc/groups/[id]/index.blade.php:1688-1696] — helper global à scoper (page groupe).
- [Source: resources/views/pages/parc/machines/[id]/index.blade.php:930-933] — helper global à scoper (page machine).
- [Source: app/Models/Workstation.php:157, :176] — `physicalRooms()` / `physicalRoom` (résolution du scope poste).
- [Source: app/Services/AppProfile/AppProfileService.php:252, :284, :387, :426, :463, :500] — chemins d'assignation à garder.
- [Source: app/Models/Printer.php:146] — 2ᵉ usage de la primitive (liste filtrée), pour contexte.
- [Source: mémoire projet — delegation_enforcement_wpkg_gap, phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement, root_is_laravel].

## Dépendances

- **Amont (bloquantes)** : **aucune**. Correctif d'enforcement **autonome** sur une dette préexistante du domaine WPKG/délégations. **Pas** de dépendance dure sur Epic 28 (28.1/28.2/28.3 en `review`, code sur `main`) — la 29.1 ne lit ni n'écrit le contrat amont.
- **Prérequis fourni à (aval)** :
  - **Story 29.2** (refus d'édition d'un item verrouillé) — s'appuiera sur le gate scopé pour opposer un verrou par périmètre.
  - **Epic 31** (bornage de l'install au catalogue amont, NFR1) — **dépend** explicitement de ce correctif (sinon le verrou apps reste « du théâtre »).
- **Réutilise** : système de délégation Epics 7.x (`delegations`, `PermissionService`, `WorkstationGroupPolicy`) — **livré et stable**.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, trait `RefreshDatabase`. **Jamais la VM.** [Source: mémoire projet — phpunit_test_env_host_vs_vm]
- Filtres ciblés : `php artisan test --filter "Wpkg|AppProfile|Delegation|Permission"`.
- Couverture obligatoire :
  - **Positif scopé** : délégué de A autorisé sur A (page groupe, page machine d'un poste de A, service).
  - **Négatif scopé** : délégué de A refusé sur B / poste de B.
  - **Négative active** : refus malgré global (AC #3).
  - **Expirée** : refus (AC #4).
  - **Non-régression admin global** : autorisé partout, et `assignWpkg(user, null) === true` (AC #2).
  - **Poste sans salle physique** : seul l'admin global passe (AC #5).
  - **Service hors contexte user** (`Auth::check() === false`) : pas de blocage (AC #6).
- **Pièges** : SQLite n'applique pas varchar/enum PG — tester des décisions (booléens/exceptions), pas des bornes de colonnes ; pour les délégations, utiliser les scopes `->active()`/`->positive()`/`->negative()` et `expires_at` explicite (date passée). [Source: mémoire projet — sqlite_tests_no_varchar_enforcement]
- ⚠️ **VM** : migrations non auto-jouées par le dev-cycle (SQLite uniquement) — sans impact ici (aucune migration). [Source: mémoire projet — vm_migrations_not_auto_applied]

## Recommandation Modèle Dev

**`opus`.**

Justification : bien que chaque modification soit mécanique, la story est **transverse et piégeuse côté non-régression** — c'est un correctif de **sécurité d'autorisation** sur un chemin existant largement appelé (~20 sites d'enforcement répartis sur 2 pages + partials + couche service). Les écueils sont des **régressions silencieuses** : casser les techniciens à droit global (fallback), bloquer les appelants non-web d'`AppProfileService` (agent/console/seed), mal résoudre le scope sur la page machine (salle physique vs groupes logiques), ou laisser un site d'écriture non gardé. Le jugement requis porte sur le **périmètre exact à scoper** (assign vs add/create ; quelles méthodes service garder sans casser le catalogue global) et sur l'**exhaustivité** de la couverture — un raisonnement d'architecte de sécurité, pas une exécution de recette. Le dev-cycle routera ensuite la **review vers le modèle opposé** (sonnet/fable), ce qui place une seconde paire d'yeux sur les angles morts de non-régression.

## Dev Agent Record

### Agent Model Used

opus / claude-opus-4-8[1m]

### Debug Log References

- `php artisan test tests/Unit/Policies/WorkstationGroupPolicyWpkgTest.php` → 7 passed (15 assertions).
- `php artisan test tests/Feature/AppProfile/AppProfileServiceWpkgScopingTest.php` → 3 passed (7 assertions).
- `php artisan test --filter "Wpkg|AppProfile|Delegation|Permission"` → **39 failed, 1 skipped, 547 passed** (1384 assertions). Baseline AVANT story (stash) = **39 failed, 537 passed** → **0 régression** ; les 39 échecs sont pré-existants et étrangers à la story (ViewException Vite : Gpo*, Ipxe, Livewire ParcSettings/RightsManagement/ClassShareSection/DelegationModal ; RuntimeException env : Agent ReleasesRings/ToolsCatalog ; Http RoutesProtection/DirectAccessDenied ; WpkgReportApi ; EloquentFirstChemiCritiqueTest). Les +10 passants = les 10 nouveaux tests de la story.
- Régression transitoire corrigée : `Tests\Feature\Wpkg\UI\ParcGroupWpkgPageTest` (3 tests) cassait après T4 (le garde service invoque le Gate scopé sous user authentifié → before-hook Spatie lit la table `permissions`, non bootstrappée par `WpkgSchemaBootstrapper`). Fix : bootstrap des 5 tables Spatie (vides) + stub `Gate::define('assign-wpkg-workstationGroup', fn => true)` + drop en tearDown. Vert (4/4).
- `php -l` : OK sur les 5 fichiers PHP modifiés/créés. Compilation Blade (`Blade::compileString`) : OK sur les 3 partials modifiés.

### Completion Notes List

- **T0** : grep confirme que **seul `wpkg.assign`** est enforcé (pages parc + service). `wpkg.add`/`wpkg.create` n'apparaissent QUE dans l'enum `SambaPermission` (aucun `Gate`/`@can`) → laissés **globaux** (création de catalogue = acte serveur, pas par-salle). Non sur-scopés.
- **T1** : `WorkstationGroupPolicy::assignWpkg(?Authenticatable, ?WorkstationGroup = null): bool` calquée à l'identique sur `manage()` (délégation scopée si group physique, sinon fallback global `hasPermission`). Entrée `'assign-wpkg-workstationGroup' => 'assignWpkg'` ajoutée à `$gates` (enregistrement auto via `RegistersGates`/`AuthServiceProvider`). Décision négative/expiration **non réimplémentées** (déléguées à `canOnWorkstationGroup`).
- **T2** : page groupe — helper `ensureWpkgAssignAuthorized()` passe désormais `$this->group` au Gate scopé ; 5 `@can('wpkg.assign')` → `@can('assign-wpkg-workstationGroup', $group)` dans le partial. Les 11 sites d'écriture passent tous par le helper (vérifié).
- **T3** : page machine — helper scope `$this->workstation->physicalRoom` (null → fallback global, pas de fausse ouverture) ; 4 `@can` du partial assignment + `@can`/`@cannot` du partial options → Gate scopé. Le périmètre `$wpkgScope = $this->workstation?->physicalRoom` est résolu **une seule fois** par partial (`@php`) pour éviter le N+1 de l'accessor (notamment la boucle d'options où `@cannot` est dans le `@foreach`).
- **T4** : garde privé `AppProfileService::assertCanAssignWpkgOnGroup(?WorkstationGroup): void` — **no-op si `Auth::check()===false`** (console/agent/seed/clone système, non-régression AC #6), sinon `Gate::authorize('assign-wpkg-workstationGroup', $group)`. Appelé en tête des chemins qui **matérialisent une assignation sur un parc/poste** : `addApplicationsToWorkstationGroup`/`removeApplicationsFromWorkstationGroup` (scope = group), `addApplicationsToWorkstation`/`removeApplicationsFromWorkstation` (scope = `physicalRoom`), `addWorkstationGroups`/`removeWorkstationGroups` (garde **par groupe** cible, un refus = échec global), `cloneConfiguration` (scope = **parc cible**, la source est lue seule). **NON gardées** (catalogue/profil pur, droit global) : `createProfile`/`updateProfile`/`deleteProfile`/`addApplications`/`removeApplications`/`addWorkstations`/`removeWorkstations`/`previewCloneConfiguration`.
- **T5** : 2 fichiers de tests neufs — `tests/Unit/Policies/WorkstationGroupPolicyWpkgTest.php` (7 cas : délégué A oui / B non, admin global partout + null, négative prévalant sur global, expirée, groupe logique → fallback global seul, user nu refusé, résolution via façade `Gate::allows`) et `tests/Feature/AppProfile/AppProfileServiceWpkgScopingTest.php` (3 cas : délégué A OK / B `AuthorizationException` sans écriture, appelant non authentifié non bloqué). Piège SQLite respecté : décisions booléennes/exceptions, `expires_at = now()->subDay()`.
- **T6** : `docs/qa/domains/rights-management.md` — **Section 11** ajoutée (append, scénarios 11.1→11.7 stables) ; libellé du domaine `rights-management` enrichi dans `docs/qa/README.md`.
- **T7** : grep garde-fou OK — **0** `Gate::authorize('wpkg.assign')` global et **0** `@can('wpkg.assign')` sans modèle dans `resources/views/pages/parc/` (la seule occurrence résiduelle de `wpkg.assign` est un commentaire documentant l'ancien code). 15 usages scopés `assign-wpkg-workstationGroup`.

**Décisions de cadrage** : (1) `wpkg.add`/`wpkg.create` restent globaux (non enforcés nulle part — cf. T0). (2) `addWorkstationGroups`/`removeWorkstationGroups` **gardées** (chemin d'assignation par-parc bien réel) avec garde par groupe sous `if (Auth::check())`. (3) `cloneConfiguration` gardée sur le **parc cible** uniquement. (4) Méthodes catalogue/profil pures **écartées** du garde (droit global de gestion de catalogue, pas un périmètre de salle).

### File List

**Modifiés :**
- `app/Policies/WorkstationGroupPolicy.php` — méthode `assignWpkg` + entrée `$gates`.
- `app/Services/AppProfile/AppProfileService.php` — import `Gate`, garde `assertCanAssignWpkgOnGroup` + appels dans 7 méthodes scopées.
- `resources/views/pages/parc/groups/[id]/index.blade.php` — helper `ensureWpkgAssignAuthorized` scopé.
- `resources/views/pages/parc/groups/[id]/_partials/wpkg-assignment-tab.blade.php` — `@can` scopés (`$group`).
- `resources/views/pages/parc/machines/[id]/index.blade.php` — helper scopé sur `physicalRoom`.
- `resources/views/pages/parc/machines/[id]/_partials/wpkg-assignment-tab.blade.php` — `$wpkgScope` + `@can` scopés.
- `resources/views/pages/parc/machines/[id]/_partials/wpkg-options-tab.blade.php` — `$wpkgScope` + `@can`/`@cannot` scopés.
- `tests/Feature/Wpkg/UI/ParcGroupWpkgPageTest.php` — bootstrap tables Spatie + stub Gate scopé (adaptation à T4).
- `docs/qa/domains/rights-management.md` — Section 11 (append).
- `docs/qa/README.md` — libellé domaine `rights-management`.

**Nouveaux :**
- `tests/Unit/Policies/WorkstationGroupPolicyWpkgTest.php`
- `tests/Feature/AppProfile/AppProfileServiceWpkgScopingTest.php`
