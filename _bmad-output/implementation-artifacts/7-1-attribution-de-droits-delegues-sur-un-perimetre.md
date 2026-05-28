# Story 7.1 : Attribution de Droits Délégués sur un Périmètre

Status: done

<!-- Note: Validation optionnelle. Lancer validate-create-story pour un check qualité avant dev-story. -->

## Story

As a **administrateur SER**,
I want **attribuer des droits délégués à un utilisateur sur un périmètre limité (groupe physique ou WorkstationGroup) avec une UX ergonomique, une traçabilité complète et une isolation stricte du périmètre**,
So que **un enseignant ou responsable délégué puisse gérer son périmètre dans SER sans accéder au reste — avec un audit trail consultable pour toute opération grant / revoke / negate, et sans jamais voir une ressource hors périmètre**.

---

## Contexte — Socle déjà livré (NE PAS re-implémenter)

Cette story finalise uniquement le **reste à faire** sur les délégations Spatie scopées. Le socle technique est livré, testé et utilisé en production SER :

- `app/Services/PermissionService.php` — méthodes `grantDelegation`, `revokeDelegation`, `negateDelegation`, `canOnWorkstationGroup`, `getUserDelegations`, `getAuthorizedWorkstationGroups`.
- `app/Models/Delegation.php` — modèle Eloquent avec scopes `positive`, `negative`, `active`, `forUser`, `forWorkstationGroup`, `forPermission`. Relations `user`, `workstationGroup`, `permission`, `granter`.
- Migration `database/migrations/2026_02_06_115500_create_rights_management_tables.php` — table `delegations` (clé unique `user_id + workstation_group_id + permission_id + is_negative`, FK `granted_by`, `expires_at` nullable).
- Page Livewire `resources/views/pages/rights-management/index.blade.php` (513 lignes) — 3 onglets : vue d'ensemble des rôles, détail d'un utilisateur (avec révocation unitaire), liste des délégations actives.
- Drawer de création Livewire `resources/views/pages/users/_partials/rights-drawer.blade.php` (674 lignes) — onglet "Délégations" pour grant / remove sur sélection multiple d'users.
- Enum `app/Enums/SambaPermission.php` — 18 permissions atomiques, méthode `isDelegatable()`, catégorisation.
- Enum `app/Enums/SambaRole.php` — 9 rôles seed (cf. matrice `profiles-rights-matrix.md` §5.2).
- Policies câblées : `WorkstationGroupPolicy` (délégations scopées via `canOnWorkstationGroup`), `UserPolicy`, `GroupPolicy`, `ShortcutPolicy`, `AppCustomizationPolicy` — via traits `ChecksPermissions` + `RegistersGates`.
- Trait `app/Components/Traits/WithToasts.php` — notifications `toastSuccess`, `toastError`, `toastWarning`, `toastInfo`, `toastPermissionDenied`.

> **ATTENTION — Scope hors story :** les profils de droits dynamiques (édition runtime de `SambaRole`), les Policies manquantes (`Delegation`, `Machine`, `Printer`, `Share`, `Dhcp`), le middleware `sambaedu.can:permission`, le cache Spatie — **c'est Story 7.2, pas ici**. La migration bitmask → Spatie et l'Observer de projection AD — **c'est Story 7.3, pas ici**.

---

## Acceptance Criteria

### AC1 — Attribution d'une délégation (grant) — déjà fonctionnel, à renforcer

**Given** je suis administrateur SER avec `user.delegate`
**When** je sélectionne un ou plusieurs utilisateurs cibles, un périmètre (WorkstationGroup physique) et une ou plusieurs permissions à déléguer
**Then** la délégation est persistée via `PermissionService::grantDelegation` (permission individuelle via Spatie + scope workstation_group)
**And** un toast de succès `WithToasts::toastSuccess` confirme le nombre de délégations accordées et cible(s)
**And** une entrée d'audit trail est créée (cf. AC5)

### AC2 — Isolation visuelle des ressources hors périmètre

**Given** un utilisateur délégué (rôle `Prof` / `Technicien` / `ReferentNumerique` + délégations scopées) est connecté
**When** il navigue dans **`/parc`** (liste machines + liste groupes), **`/parc/groups/{id}`**, **`/app/users/groups/[id]`** (UI groupes physiques si exposée ailleurs)
**Then** les listings filtrent les ressources : seuls les WorkstationGroups sur lesquels il a `computer.view` **via délégation positive non négateée** sont affichés (ou via droit global Spatie s'il en a un)
**And** les machines listées sont uniquement celles appartenant à ces WorkstationGroups autorisés

### AC3 — Blocage d'accès direct hors périmètre

**Given** un utilisateur délégué
**When** il tente d'accéder directement à l'URL d'une ressource hors périmètre (ex: `/parc/groups/{id}` ou `/parc/machines/{id}` dont le group n'est pas délégué)
**Then** l'accès est refusé via `WorkstationGroupPolicy::view` (retour 403) ou redirection silencieuse vers `/parc` (pas de divulgation d'existence de la ressource)
**And** une entrée technique est loggée (channel `security` ou Log info) avec user, URL, ressource cible — **sans stack trace visible à l'utilisateur**

### AC4 — Révocation effective immédiate (pas de cache stale)

**Given** une délégation active accordée à un utilisateur
**When** un administrateur la révoque via `revokeDelegation` (bouton corbeille page `/rights-management` ou onglet "Délégations" du drawer)
**Then** la ligne `delegations` est supprimée en base
**And** la **prochaine requête HTTP** de l'utilisateur révoqué constate la perte d'accès (pas de persistance session / cache obsolète) — cela implique de **ne pas mettre en cache** les résultats de `canOnWorkstationGroup` plus longtemps qu'une requête (le cache Spatie global est hors scope, Story 7.2)
**And** un toast `WithToasts::toastSuccess` confirme la révocation
**And** une entrée d'audit trail `revoke` est créée

### AC5 — Audit trail best-effort grant / revoke / negate

**Given** toute opération de délégation (grant, revoke, negate) effectuée via `PermissionService`
**When** la méthode s'exécute (succès ou échec métier)
**Then** une entrée est persistée avec : `actor_user_id` (qui agit), `target_user_id` (cible), `workstation_group_id` (périmètre), `permission_name` (nom Spatie), `action` (`grant` / `revoke` / `negate` / `expire`), `is_negative` (bool), `context` (JSONB, ex: IP, user-agent, batch_id si opération multi-users), `created_at`
**And** l'audit est **non modifiable** (table append-only, pas de `updated_at` ni d'UPDATE applicatif)
**And** l'écriture de l'audit est **best-effort** (décision Henri 2026-04-23 — Option B) :
- si l'écriture échoue, l'opération de délégation est **quand même validée** (pas de rollback) ;
- `PermissionService::$lastAuditFailed` passe à `true` et l'UI Livewire émet un toast warning ("Délégation appliquée mais la traçabilité n'a pas été enregistrée. Contactez l'administrateur.") ;
- l'échec est loggé côté serveur via `Log::error` (dans `DelegationHistoryService::log`).

**Rationale** : une défaillance de l'audit (DB indisponible, FK violée…) ne doit pas empêcher la prise d'un grant légitime. Le toast warning donne un signal immédiat à l'admin pour remonter le ticket.

> **Décision produit tranchée :** table dédiée `delegation_history` (requêtable, filtrable, projetable en UI).

### AC6 — Consultation de l'audit trail dans l'UI

**Given** je suis sur `/rights-management`
**When** j'ouvre l'onglet **"Historique"** (nouvel onglet à ajouter, en plus de `overview` / `user-lookup` / `delegations`)
**Then** je vois les N dernières entrées d'audit (pagination, filtres : action, user cible, période)
**And** je peux filtrer par utilisateur cible (via l'onglet `user-lookup`, l'historique de ce user est affiché sous ses délégations actives)
**And** les colonnes sont : date, acteur, action, cible, périmètre, permission, type (positive / négative)

### AC7 — Ergonomie création délégation (drawer existant `rights-drawer`)

**Given** j'ouvre le drawer de délégations depuis `/app/users` (sélection d'users puis bouton "Gérer les droits")
**When** je passe à l'onglet Délégations
**Then** le sélecteur de périmètre affiche clairement les WorkstationGroups **physiques + actifs** avec leur libellé `display_name`, triés, avec option de recherche si > 20 entrées
**And** les permissions proposées sont **uniquement celles marquées `isDelegatable()`** (catégories `computer` + `wpkg`) — comportement existant, à conserver
**And** un avertissement explicite reste affiché pour `computer.elevate` (sync GPO déclenchée) — comportement existant, à conserver
**And** après succès, un toast `WithToasts::toastSuccess` remplace le bloc `successMessage` HTML actuel (alignement avec le pattern projet, cf. `CLAUDE.md`)
**And** après succès, le drawer ne se ferme pas (permet l'enchaînement d'opérations sur la même sélection) — comportement actuel à préserver

### AC8 — Révocation one-click ergonomique

**Given** je consulte les délégations actives d'un user (onglet `user-lookup` page `/rights-management` OU onglet "Délégations actives")
**When** je clique sur la corbeille d'une ligne
**Then** une confirmation native s'affiche (`wire:confirm="Révoquer cette délégation ?"`) — comportement existant
**And** la révocation est exécutée, l'UI est rafraîchie **sans rechargement complet** de la page (Livewire)
**And** un toast confirme la révocation
**And** si la permission révoquée est `computer.elevate`, le `SyncGpoJob` est dispatché (comportement existant dans `PermissionService::revokeDelegation`)

### AC9 — Délégation négative (exclusion) exposée en UI

**Given** je suis administrateur SER
**When** dans le drawer de délégations, je bascule un toggle "Marquer comme exclusion (négative)" avant d'accorder
**Then** la délégation est créée via `negateDelegation` (flag `is_negative = true`)
**And** elle apparaît avec un badge "Exclusion" (badge-error) dans tous les listings
**And** la logique `canOnWorkstationGroup` continue de fonctionner : `droit_global OR (délégation_positive AND NOT délégation_négative)`

### AC10 — Coverage tests PROD

**Given** la story est livrée
**When** `phpunit` tourne
**Then** les suites suivantes existent et passent :
- `tests/Unit/Services/PermissionServiceTest.php` : grant / revoke / negate / `canOnWorkstationGroup` (droit global, délégation positive seule, négative seule, positive + négative, expirée, cascade delete user / group)
- `tests/Unit/Services/Parc/WorkstationGroupServiceScopingTest.php` : `listGroups` et `listMachines` retournent uniquement le périmètre autorisé pour un user non-admin avec délégations
- `tests/Feature/Livewire/RightsManagementPageTest.php` : onglets, recherche user, révocation, affichage audit trail
- `tests/Feature/Policies/WorkstationGroupScopingTest.php` : 403 sur accès direct à un group hors périmètre, OK sur group délégué, OK sur droit global
- `tests/Feature/Services/DelegationAuditTest.php` : chaque opération `PermissionService` persiste une entrée audit avec les bons champs
- `tests/Feature/Livewire/UserRightsDrawerTest.php` : applyDelegations grant / remove / négative + toast + audit + ensureEloquentUser fallback

**And** aucun des tests existants ne régresse (base actuelle avant story).

---

## Tâches / Sous-tâches

### Phase 1 — Décisions validées (rappel pour le dev)

> Toutes les décisions produit sont tranchées (cf. §Décisions produit tranchées). Résumé appliqué au dev :
> - Table dédiée **`delegation_history`**
> - Accès hors périmètre : **toast + redirect** (fallback page 403 si non-faisable)
> - Pas d'observer cascade delete (action `cascade_delete` non implémentée)
> - Toggle négative dans le drawer existant
> - Scoping listings : `computer.view` uniquement

### Phase 2 — Historique des délégations (foundation pour le reste)

- [x] **Tâche 2** — Table `delegation_history` + modèle + migration (AC: #5)
  - [x] 2.1 Créer migration `database/migrations/YYYY_MM_DD_HHMMSS_create_delegation_history_table.php` avec colonnes : `id`, `actor_user_id` (FK users nullable set null — l'acteur peut être supprimé), `target_user_id` (FK users nullable set null), `workstation_group_id` (FK workstation_groups nullable set null — le group peut être supprimé après écriture), `permission_name` (string 255, pas FK car permission peut être renommée/supprimée Story 7.2), `action` (enum / check : `grant`, `revoke`, `negate`, `expire`), `is_negative` (bool), `context` (jsonb nullable), `created_at` (pas d'`updated_at`). Index sur `target_user_id`, `workstation_group_id`, `action`, `created_at`.
  - [x] 2.2 Créer `app/Models/DelegationHistory.php` : modèle append-only (override `save()` pour empêcher `UPDATE`), relations `actor`, `target`, `workstationGroup`, scopes `forTarget`, `forActor`, `forAction`, `forPeriod`.
  - [x] 2.3 Ajouter un service `app/Services/DelegationHistoryService.php` avec méthode `log(action, actor, target, group, permissionName, isNegative, context = [])`.
  - [x] 2.4 Câbler dans `PermissionService::grantDelegation` (post-persist), `revokeDelegation` (post-delete), `negateDelegation` (post-persist). Passer l'acteur explicitement : **modifier la signature** de `revokeDelegation` et `negateDelegation` pour accepter un `?User $actor = null` (comme `grantDelegation` l'a déjà avec `$grantedBy`). Fallback sur `auth()->user()` si null.
  - [x] 2.5 ~~Observer cascade delete~~ — **non implémenté** (décision 2026-04-23 : on ignore les cascades FK). Les lignes `delegations` supprimées par cascade disparaissent silencieusement de l'historique (tracé métier uniquement, pas de tracé technique).

### Phase 3 — Scoping des ressources hors périmètre

- [x] **Tâche 3** — Ajouter filtres de périmètre aux services Parc (AC: #2, #3)
  - [x] 3.1 Dans `app/Services/Parc/WorkstationGroupService::listGroups` : accepter un paramètre optionnel `?User $scopeFor = null`. Si fourni et si l'user n'a pas de droit global `computer.view`, contraindre le résultat via `PermissionService::getAuthorizedWorkstationGroups($user, 'computer.view')`.
  - [x] 3.2 Idem `listMachines` : filtrer les machines appartenant à un WorkstationGroup autorisé (join sur `workstation_group_id` dans `workstations` si schéma le permet, sinon whereIn sur les ids des groupes autorisés).
  - [x] 3.3 Dans `resources/views/pages/parc/index.blade.php` : passer `auth()->user()` au service via un helper `scopedUser()` (vérifie le type `App\Models\User`).
  - [x] 3.4 Dans `resources/views/pages/parc/groups/[id]/index.blade.php` : ajouter vérification Policy `can('view', $group)` dès le mount — redirect vers `/parc` avec toast d'erreur.

- [x] **Tâche 4** — Renforcer `WorkstationGroupPolicy` (AC: #3)
  - [x] 4.1 Vérifier que `view($user, $group)` appelle bien `canOnWorkstationGroup($user, 'computer.view', $group)` — **déjà OK**.
  - [x] 4.2 Ajouter `manage($user, $group)` qui retourne `canOnWorkstationGroup($user, 'computer.control', $group)` ou droit global.
  - [x] 4.3 Câbler `manage-workstationGroup` dans `$gates`.
  - [ ] 4.4 Audit des 3 pages Livewire parc pour remplacer les `@can('computer.view')` globaux par `@can('view', $group)` — **différé** : les pages actuelles utilisent déjà les gates correctement, remplacement systématique non-requis pour livrer la story. À faire en Story 7.2 avec le middleware `sambaedu.can:permission`.

- [x] **Tâche 5** — Toast + redirection sur accès hors périmètre (AC: #3)
  - [x] 5.1 Dans le `mount()` / `loadGroup()` des pages Livewire sensibles (`/parc/groups/{id}`), si la Policy refuse : `session()->flash('toast', …)` puis redirect silencieux vers `/parc`. Le layout Livewire lit le flash au chargement suivant et affiche le toast via `WithToasts`.
  - [ ] 5.2 Fallback page 403 : **non implémenté** — aucun chemin non-Livewire exposé hors périmètre aujourd'hui. La page `resources/views/errors/403.blade.php` existe déjà (pattern Laravel standard). Sera étendue si besoin en Story 7.2.
  - [x] 5.3 Logger chaque refus dans le log applicatif `info` avec user, URL, ressource cible (via `Log::info('[GroupShow] Accès refusé hors périmètre', ...)`).

### Phase 4 — Améliorations UI

- [x] **Tâche 6** — Remplacer le feedback HTML par `WithToasts` (AC: #7, #1)
  - [x] 6.1 `resources/views/pages/users/_partials/rights-drawer.blade.php` : importer `use App\Components\Traits\WithToasts;` + `use WithToasts;` dans le composant.
  - [x] 6.2 Remplacer `$this->successMessage = ...` par `$this->toastSuccess($message)` dans `applyRoles`, `applyPermissions`, `applyDelegations`.
  - [x] 6.3 Idem `$this->errorMessage` → `$this->toastError($message)` (avec fallback `toastWarning` si succès partiel).
  - [x] 6.4 Supprimer les blocs HTML `@if ($successMessage)` / `@if ($errorMessage)` et leurs déclarations de propriétés associées.
  - [x] 6.5 Drawer reste ouvert après l'opération (comportement préservé).

- [x] **Tâche 7** — Ergonomie sélecteur de périmètre (AC: #7)
  - [x] 7.1 Dans le drawer, onglet Délégations : si `count($availableWorkstationGroups) > 20`, affichage combobox filtrable via input `workstationGroupSearch` + computed `filteredWorkstationGroups`.
  - [x] 7.2 Le `display_name` est utilisé comme libellé, le `name` technique reste accessible dans le modèle sous-jacent.

- [x] **Tâche 8** — Toggle "Délégation négative" (AC: #9)
  - [x] 8.1 Dans le drawer onglet Délégations : ajout d'un toggle `$isNegative` (bool) au-dessus du toggle `removeDelegation`. Exclusif mutuel via hooks `updatedIsNegative` / `updatedRemoveDelegation`.
  - [x] 8.2 Dans `applyDelegations`, routage sur `negateDelegation(...)` quand `$isNegative && !$removeDelegation`.
  - [x] 8.3 Badges "Exclusion" dans les listings — cohérence vérifiée.

- [x] **Tâche 9** — Onglet "Historique" dans `/rights-management` (AC: #6)
  - [x] 9.1 Ajout d'un 4ᵉ onglet `$activeTab = 'history'` dans `resources/views/pages/rights-management/index.blade.php`.
  - [x] 9.2 Composant affiche table paginée (Livewire `WithPagination`) des `DelegationHistory` récents, filtres : `action`, `target`, période (from/to). Partial `_partials/history-tab.blade.php`.
  - [x] 9.3 Dans l'onglet `user-lookup` (détail d'un user), section "Historique des délégations" avec 10 dernières entrées (via `DelegationHistory::forTarget($user)->latest()->limit(10)`).
  - [x] 9.4 Colonnes : date (`d/m/Y H:i`), acteur (login), action (badge coloré grant=success / revoke=warning / negate=error / expire=ghost), cible (login), périmètre, permission, type (positive/Exclusion).

### Phase 5 — Tests

- [x] **Tâche 10** — Tests unitaires PermissionService (AC: #10)
  - [x] 10.1 Créé `tests/Unit/Services/PermissionServiceTest.php` avec `DatabaseTransactions` + `createTablesIfNeeded()` SQLite.
  - [x] 10.2 Couvert (15 tests / 27 assertions) :
    - `test_grant_delegation_persists_and_is_idempotent`
    - `test_grant_delegation_creates_history_entry_only_on_first_call`
    - `test_grant_delegation_dispatches_gpo_sync_for_computer_elevate_only`
    - `test_revoke_delegation_deletes_row_and_creates_history`
    - `test_revoke_without_existing_delegation_does_not_log`
    - `test_revoke_delegation_accepts_explicit_actor`
    - `test_negate_delegation_creates_negative_flag`
    - `test_negate_delegation_accepts_explicit_actor`
    - `test_can_on_workstation_group_with_global_permission`
    - `test_can_on_workstation_group_with_positive_delegation`
    - `test_can_on_workstation_group_without_any_grant_is_false`
    - `test_can_on_workstation_group_blocked_by_negative_delegation`
    - `test_can_on_workstation_group_with_expired_delegation`
    - `test_get_authorized_workstation_groups_filters_correctly`
    - `test_get_authorized_workstation_groups_with_global_returns_all`

- [x] **Tâche 11** — Tests scoping services Parc (AC: #10, AC: #2)
  - [x] 11.1 Créé `tests/Unit/Services/Parc/WorkstationGroupServiceScopingTest.php`.
  - [x] 11.2 Setup : 3 groupes physiques (A, B, C), 2 machines par group.
  - [x] 11.3 Couvert (9 tests / 20 assertions) :
    - `test_list_groups_without_scope_user_returns_all`
    - `test_list_groups_scoped_by_user_returns_only_authorized`
    - `test_list_groups_scoped_by_admin_returns_all`
    - `test_list_groups_scoped_by_user_with_no_delegation_returns_empty`
    - `test_list_groups_with_negative_delegation_is_excluded`
    - `test_list_machines_without_scope_returns_all`
    - `test_list_machines_scoped_by_user_returns_only_machines_in_authorized_groups`
    - `test_list_machines_scoped_by_admin_returns_all`
    - `test_list_machines_with_unauthorized_group_filter_returns_empty`

- [x] **Tâche 12** — Tests Feature Policies (AC: #10, AC: #3)
  - [x] 12.1 Créé `tests/Feature/Policies/WorkstationGroupScopingTest.php`.
  - [x] 12.2 Couvert (7 tests / 10 assertions) directement via `Gate::forUser($user)->allows(...)` (au lieu de GET HTTP — les pages Livewire `/parc/*` ont un pipeline setup lourd non-adapté à un test headless isolé) :
    - `test_delegated_user_can_view_authorized_group`
    - `test_delegated_user_cannot_view_unauthorized_group`
    - `test_admin_with_global_permission_can_view_all_groups`
    - `test_revoked_user_loses_access_immediately` (**AC4 — pas de cache stale**)
    - `test_negative_delegation_overrides_positive`
    - `test_manage_gate_requires_computer_control`
    - `test_manage_gate_allowed_with_global_computer_control`

- [x] **Tâche 13** — Tests Feature historique des délégations (AC: #10, AC: #5)
  - [x] 13.1 Créé `tests/Feature/Services/DelegationHistoryTest.php`.
  - [x] 13.2 Couvert (7 tests / 20 assertions) :
    - `test_grant_creates_history_entry_with_all_fields`
    - `test_revoke_creates_history_entry`
    - `test_negate_creates_history_entry_with_is_negative_true`
    - `test_history_is_append_only_via_save`
    - `test_history_is_append_only_via_update`
    - `test_actor_is_resolved_from_auth_when_not_explicit`
    - `test_direct_history_service_log_accepts_null_actor`

- [x] **Tâche 14** — Tests Feature Livewire UI (AC: #10, AC: #6, AC: #7, AC: #8)
  - [x] 14.1 Créé `tests/Feature/Livewire/RightsManagementPageTest.php` (7 tests / 22 assertions).
  - [x] 14.2 Couvert :
    - `test_can_switch_between_4_tabs`
    - `test_history_tab_displays_recent_audits`
    - `test_history_tab_filters_by_action`
    - `test_history_tab_filters_by_target`
    - `test_reset_history_filters_clears_all`
    - `test_revoke_delegation_button_removes_delegation_and_creates_audit`
    - `test_user_lookup_loads_delegation_history_for_selected_user`
  - [x] 14.3 Créé `tests/Feature/Livewire/UserRightsDrawerTest.php` (8 tests / 16 assertions).
  - [x] 14.4 Couvert :
    - `test_apply_delegations_grant_creates_row_and_audit`
    - `test_apply_delegations_remove_deletes_row_and_audits`
    - `test_apply_delegations_negative_creates_row_with_is_negative_flag`
    - `test_apply_delegations_with_computer_elevate_dispatches_gpo_job`
    - `test_apply_delegations_for_nonexistent_user_creates_minimal_eloquent_user`
    - `test_toggling_is_negative_disables_remove`
    - `test_toggling_remove_disables_is_negative`
    - `test_apply_delegations_without_users_does_nothing`

### Phase 6 — Documentation & closing

- [x] **Tâche 15** — Documentation domaine
  - [x] 15.1 Créé `docs/domains/rights-management.md` avec : matrice permissions × catégories, flux grant/revoke/negate, append-only audit trail, scoping, Policy `manage` (Story 7.1).
  - [x] 15.2 Ajouté section "Scoping des listings (Story 7.1)" dans `docs/domains/parc.md` décrivant le paramètre `$scopeFor` et le check `Gate::allows('view')` dans le mount.

- [x] **Tâche 16** — Scénario QA manuel VM
  - [x] 16.1 Créé `docs/qa/7-1-e2e-manual.md` — 7 scénarios (grant simple, multi-users, négative, revoke one-click, user délégué voit son périmètre, blocage URL directe hors périmètre, filtres historique) + non-régressions + follow-ups.

---

## Dev Notes

### Architecture patterns & contraintes

- **Règle projet (CLAUDE.md)** : filesystem-based routing via `resources/views/pages/`. Les modifications UI doivent respecter ce pattern. Page `/rights-management` → `resources/views/pages/rights-management/index.blade.php`. Partials spécifiques dans `_partials/`.
- **Livewire SFC** (single-file components) : la logique PHP est dans le même fichier que le template, dans un bloc `<?php ... ?>`.
- **Toasts** : impératif `use App\Components\Traits\WithToasts;` + `use WithToasts;` dans la classe — **jamais** de HTML custom success/error (cf. CLAUDE.md).
- **Modale réutilisable** : si besoin d'une confirmation complexe, utiliser `<x-molecules.modal>` + bouton de déclenchement, mais ici `wire:confirm` natif Livewire suffit.
- **VM remote** : code sur host, run sur VM via `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`. Les commandes `php artisan migrate` doivent tourner sur VM. **Ne jamais rsync manuellement** (auto-sync host → VM déjà en place).

### Source tree à toucher

```
app/
├── Models/
│   └── DelegationHistory.php                           [NEW]
├── Services/
│   ├── DelegationHistoryService.php                    [NEW]
│   ├── PermissionService.php                           [MODIFIED — câbler history + signatures]
│   └── Parc/
│       └── WorkstationGroupService.php                 [MODIFIED — param $scopeFor]
└── Policies/
    └── WorkstationGroupPolicy.php                      [MODIFIED — ajouter manage()]

database/
└── migrations/
    └── YYYY_MM_DD_HHMMSS_create_delegation_history_table.php  [NEW]

resources/views/
├── errors/
│   └── 403.blade.php                                   [NEW ou MODIFIED]
└── pages/
    ├── parc/
    │   ├── index.blade.php                             [MODIFIED — passer $scopeFor = auth()->user()]
    │   └── groups/[id]/
    │       └── index.blade.php                         [MODIFIED — can('view', $group) au mount]
    ├── rights-management/
    │   ├── index.blade.php                             [MODIFIED — 4ᵉ onglet history]
    │   └── _partials/
    │       └── history-tab.blade.php                   [NEW]
    └── users/_partials/
        └── rights-drawer.blade.php                     [MODIFIED — WithToasts + toggle négative + search group]

tests/
├── Unit/Services/
│   ├── PermissionServiceTest.php                       [NEW]
│   └── Parc/WorkstationGroupServiceScopingTest.php     [NEW]
├── Feature/Policies/
│   └── WorkstationGroupScopingTest.php                 [NEW]
├── Feature/Services/
│   └── DelegationHistoryTest.php                       [NEW]
└── Feature/Livewire/
    ├── RightsManagementPageTest.php                    [NEW ou MODIFIED]
    └── UserRightsDrawerTest.php                        [NEW]

docs/
├── domains/
│   ├── rights-management.md                            [NEW]
│   └── parc.md                                         [MODIFIED — section scoping]
└── qa/
    └── 7-1-e2e-manual.md                               [NEW]
```

### Testing standards

- Framework : **PHPUnit** via `phpunit.xml`. Base SQLite memory (`:memory:`) pour Unit, base PostgreSQL de test pour Feature si la migration JSONB l'exige — **valider au démarrage** si `delegation_audits.context` en JSONB casse SQLite (fallback JSON string avec cast).
- Convention : `tests/Unit/` pour pur service sans DB complexe, `tests/Feature/` pour HTTP/Livewire/Policies/cascade FK.
- Traits : `RefreshDatabase`, `WithFaker` si besoin. Pour Livewire : `use Livewire\Livewire;` et `Livewire::test(Component::class)`.
- Coverage attendu : **tous les AC ont ≥ 1 test**. Ne pas poursuivre 100% coverage — focus sur les invariants métier.
- Pas de mock du `PermissionService` dans les tests Feature : laisser les DB operations réelles pour valider les contraintes SQL.

### Points d'attention

- **Idempotence `grantDelegation`** : `updateOrCreate` sur `user_id + workstation_group_id + permission_id + is_negative` — déjà en place, ne pas toucher.
- **GPO sync** : `SyncGpoJob` est dispatché pour `computer.elevate` uniquement (via `requiresGpoSync()` sur l'enum). Ne pas étendre dans cette story.
- **Cascade DB** : la FK `user_id` a `onDelete('cascade')`, idem `workstation_group_id` → la table `delegations` se vide toute seule. **Décision 2026-04-23 : on ignore**, pas de trace métier des cascades. La table `delegation_history` utilise `onDelete('set null')` sur ses FK `actor_user_id`, `target_user_id`, `workstation_group_id` pour rester lisible après suppression des entités référencées. Le `permission_name` est stocké en string (pas FK) pour la même raison.
- **Auth user resolution** : dans `rights-drawer.blade.php`, `auth()->user()->getAuthIdentifier()` peut renvoyer un login (pas un id numérique) selon le `AuthGuard` en place. Le code actuel fait déjà une lookup `User::where('login', ...)` — conserver ce pattern.
- **Permissions delegatables** : filtrer sur `SambaPermission::isDelegatable()` + catégories `computer` + `wpkg` — déjà en place dans le drawer, ne pas étendre.
- **Pas de middleware `sambaedu.can:permission`** : c'est Story 7.2. On reste sur `@can` Blade + Policy appelée manuellement dans le `mount()` des composants Livewire.

### Décisions produit tranchées (2026-04-23)

| # | Décision | Choix acté |
|---|----------|------------|
| 1 | Audit : table dédiée vs Log channel | **Table dédiée `delegation_history`** (nom validé Henri — cohérent avec l'onglet UI "Historique") |
| 2 | Accès hors périmètre : 403 vs redirect | **Toast d'erreur + redirection** vers `/parc` (ou page par défaut du rôle). Implémentation : `session()->flash('toast', ...)` OU `redirect()->with('toast', ...)` + lecture du flash dans le layout. **Fallback** : page 403 dédiée uniquement si le combo toast+redirect s'avère non-faisable. |
| 3 | Observer cascade delete : logger ou ignorer ? | **Ignorer** — pas d'observer Eloquent, pas d'action `cascade_delete` dans l'enum. Simplifie la story. |
| 4 | Toggle négative dans drawer existant OU page dédiée ? | **Toggle dans drawer existant** (pas de page neuve) |
| 5 | Filtrage scope uniquement `computer.view` ou étendre à toutes les perms du namespace `computer.*` ? | **Uniquement `computer.view`** sur les filtres de listing. Les autres perms (`computer.control`, `computer.elevate`, `computer.install`) restent gérées par des `@can` Blade ligne par ligne (boutons conditionnels — pattern déjà en place). |

### Dépendances

- **Aucune dépendance bloquante**. La matrice `profiles-rights-matrix.md` est livrée (prérequis levé 2026-04-22).
- **Pas de blocage sur Story 7.2 / 7.3** : cette story ne touche ni au middleware, ni au cache, ni aux Policies manquantes, ni à la migration bitmask.
- **Migration à appliquer sur VM** après livraison : `php artisan migrate` (nouvelle table `delegation_history`).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-7.1] — User story + AC de référence
- [Source: _bmad-output/planning-artifacts/profiles-rights-matrix.md#7] — Sémantique négation & délégations
- [Source: _bmad-output/planning-artifacts/architecture.md#Délégations-FR27-29] — Positionnement architectural
- [Source: app/Services/PermissionService.php] — Socle déjà livré, méthodes à enrichir
- [Source: app/Models/Delegation.php] — Modèle + scopes
- [Source: database/migrations/2026_02_06_115500_create_rights_management_tables.php] — Schéma `delegations`
- [Source: resources/views/pages/rights-management/index.blade.php] — Page UI existante 513 L
- [Source: resources/views/pages/users/_partials/rights-drawer.blade.php] — Drawer création 674 L
- [Source: app/Components/Traits/WithToasts.php] — Pattern notifications à suivre
- [Source: CLAUDE.md projet] — Conventions routing / Livewire / toasts

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-7

### Debug Log References

- 53/53 tests Story 7.1 verts (115 assertions) : PermissionServiceTest 15/15, WorkstationGroupServiceScopingTest 9/9, DelegationHistoryTest 7/7, WorkstationGroupScopingTest 7/7, RightsManagementPageTest 7/7, UserRightsDrawerTest 8/8.
- Non-régression vérifiée sur suites Parc (GroupShowPageTest / MachineShowPageTest / WorkstationGroupServicePowerActionTest) : 32/32 OK, 0 régression introduite. Les 5 échecs observés sur GroupSchedulesPageTest pré-existent à la Story 7.1 (vérifié en `git stash` + rerun).
- **Après corrections review 2026-04-23** : 64/64 tests Story 7.1 verts (139 assertions) — +11 tests (#1, #3, #5, #7, #8, #A, #C couverts). Détail : PermissionServiceTest 16/16, WorkstationGroupServiceScopingTest 13/13, DelegationHistoryTest 7/7, WorkstationGroupScopingTest 9/9, RightsManagementPageTest 9/9, UserRightsDrawerTest 10/10.

### Completion Notes List

**Décisions techniques implémentées** (5 décisions produit respectées) :

1. **Table dédiée `delegation_history`** — nom `delegation_history` (aligné avec nom UI "Historique"), append-only via override `save()` qui throw `LogicException` sur UPDATE. FK en `ON DELETE SET NULL` pour garder l'audit lisible après suppression des entités. `permission_name` en string (pas FK) pour résister au renommage de permissions (Story 7.2). JSONB conditionnel (pgsql) / JSON (sqlite) via `DB::getDriverName()`.
2. **Toast + redirect** pour accès hors périmètre — `session()->flash('toast', ...)` + `$this->redirect(route('app.parc.index'))` dans `loadGroup()` de `/parc/groups/[id]/index.blade.php`. Log `info` avec user/URL/group. Pas de page 403 dédiée ajoutée (AC3 — fallback non implémenté car non requis).
3. **Pas d'observer cascade_delete** — les lignes `delegations` supprimées par cascade FK disparaissent silencieusement de l'historique.
4. **Toggle négative dans drawer existant** — propriété `$isNegative` avec hooks `updatedIsNegative()` / `updatedRemoveDelegation()` pour exclusivité mutuelle avec `$removeDelegation`. Routage dans `applyDelegations` vers `negateDelegation()` quand le toggle est actif.
5. **Scope `computer.view` uniquement** — `WorkstationGroupService::listGroups/listMachines` acceptent `?User $scopeFor = null`. Si l'user n'a pas `computer.view` global → contraint via `PermissionService::getAuthorizedWorkstationGroups($user, 'computer.view')`. Les perms `computer.control`, `computer.elevate`, `computer.install` restent gérées par `@can` Blade ligne par ligne (pattern existant).

**Points d'attention techniques** :

- **Signatures `PermissionService` étendues** : `revokeDelegation` et `negateDelegation` acceptent désormais un `?User $actor = null` (rétrocompat : paramètre optionnel). Le service `DelegationHistoryService::resolveActor()` retombe sur `auth()->user()` si l'actor est null et que l'user courant est un `App\Models\User`.
- **Idempotence auditée** : `grantDelegation` utilise `updateOrCreate` avec la clé unique composite. L'entrée d'historique n'est écrite que si `$delegation->wasRecentlyCreated` est true — un appel ×2 = 1 seule ligne delegation + 1 seule ligne historique. Idem pour `negateDelegation`.
- **`context` JSONB/JSON cross-database** : le cast `'array'` sur le modèle normalise les deux backends. Migration choisit `jsonb` si pgsql, `json` sinon.
- **Tests sans observer AD** : désactivation explicite de `WorkstationGroupObserver::disableSync()` dans le setUp des tests qui créent des WorkstationGroups — sinon dispatch vers `WorkstationGroupAdSyncJob` qui tente LDAP réel.
- **Scoping Repository** : ajout de `getGroupsScoped()` / `getMachinesScoped()` en plus des méthodes historiques — préserve la signature existante, ajoute une variante scope-aware.
- **Tests Policy** : j'ai testé via `Gate::forUser($user)->allows(...)` plutôt que via GET HTTP. Les pages Livewire `/parc/*` ont un pipeline setup lourd (sidebar, auth guards legacy, livewire runtime) qui ne se prête pas à un test Feature HTTP headless isolé — la Policy étant le socle logique d'AC3, tester directement ses invariants donne la garantie métier.

**Follow-ups / limitations** :

- **Tâche 4.4 différée** : audit systématique des `@can('computer.view')` globaux dans les 3 pages Livewire parc pour remplacement par `@can('view', $group)` granulaire. À faire en Story 7.2 avec l'introduction du middleware `sambaedu.can:permission`.
- **Tâche 5.2 non implémentée** : page 403 dédiée non nécessaire aujourd'hui (tous les accès hors périmètre passent par Livewire et utilisent le pattern toast+redirect). À étendre si un controller API non-Livewire est exposé hors périmètre.
- **Migration prod** : à lancer manuellement sur VM : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "cd /var/www/sambaedu-reload && php artisan migrate"`. Le fichier `2026_04_23_100000_create_delegation_history_table.php` crée la table avec contraintes FK pgsql.
- **Tests tournent sur host (worktree)** : Le vendor de sambaedu-reload est symliké dans e7-permissions et `composer dump-autoload` a été exécuté localement pour les tests. Sur VM : les tests tourneront via `php artisan test` après la sync auto-propagée (une fois la branche e7-permissions mergée).

**Fichiers non-testés** :

- Les blocs Blade purs (ex: partial `history-tab.blade.php`) ne sont pas testés isolément — la logique métier est dans le composant parent `RightsManagementPageTest` qui exerce le rendu du partial via `Livewire::test`.
- Le `resolveActor()` du `DelegationHistoryService` pour Authenticatable non-User Eloquent (guard legacy) : non testé (pattern degraded avec return null, couvert par les assertions `assertNull($entry->actor_user_id)`).

**Corrections review appliquées le 2026-04-23** (post code review complète) :

1. **#1 Gate serveur parc/groups** — `Gate::authorize('update-workstationGroup'|'delete-workstationGroup', $group)` câblé sur `addMachines` / `removeMachine` / `deleteGroup`.
2. **#2 `created_at` fillable** — retiré de `$fillable` dans `DelegationHistory` (append-only strict).
3. **#3 `.active()` sur négatives** — chaîne corrigée dans `canOnWorkstationGroup` + `getAuthorizedWorkstationGroups`. Test de non-régression ajouté.
4. **#4 Audit best-effort + toast warning (Option B)** — `PermissionService::$lastAuditFailed` ajoutée + toast warning dans drawer + rights-management. AC5 mis à jour pour refléter la sémantique best-effort.
5. **#5 Guards admin (user.assign.right)** — middleware `can:user.assign.right` sur `/app/rights-management` + `abort_unless` dans `open`/`applyRoles`/`applyPermissions`/`applyDelegations` du drawer + `revokeDelegation` rights-management.
6. **#7 `getRootGroupsForSelect()` scopé** — paramètre `?User $scopeFor = null` ajouté + appel depuis `/parc/index.blade.php` avec `$this->scopedUser()`. 4 tests unit ajoutés.
7. **#8 LIKE/ILIKE cross-driver** — pattern `DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE'` appliqué à `searchUser`. Test SQLite ajouté.
8. **#9 Documentation limitation groupes logiques** — PHPDoc dans `WorkstationGroupPolicy::view` + section "Limitations connues" dans `docs/domains/rights-management.md`.
9. **#A Check AD dans `ensureEloquentUser`** — lookup via `UserService::getByLogin()` avant création Eloquent. Toast warning + skip si login absent. Test ajouté avec mock UserService.
10. **#C Guard `revokeDelegation`** — `abort_unless(Gate::allows('user.assign.right'))` en tête, defense-in-depth même si la route est déjà protégée.

**Non corrigés (reportés ou refusés)** :

- **#6** (trigger Postgres append-only) — TODO ajouté dans la migration, reporté Story 7.2.
- **#10** (whitelist historyActionFilter) — dette acceptée.
- **#B** (`scopeForPeriod` code mort) — non touché.

### File List

**Créés (10)** :
- `app/Models/DelegationHistory.php`
- `app/Services/DelegationHistoryService.php`
- `database/migrations/2026_04_23_100000_create_delegation_history_table.php`
- `resources/views/pages/rights-management/_partials/history-tab.blade.php`
- `tests/Unit/Services/PermissionServiceTest.php`
- `tests/Unit/Services/Parc/WorkstationGroupServiceScopingTest.php`
- `tests/Feature/Services/DelegationHistoryTest.php`
- `tests/Feature/Policies/WorkstationGroupScopingTest.php`
- `tests/Feature/Livewire/RightsManagementPageTest.php`
- `tests/Feature/Livewire/UserRightsDrawerTest.php`

**Créés documentation (2)** :
- `docs/domains/rights-management.md`
- `docs/qa/7-1-e2e-manual.md`

**Modifiés (10)** :
- `app/Services/PermissionService.php` — câblage audit, signatures `revokeDelegation` / `negateDelegation` étendues avec `?User $actor = null`, helper `buildContext()`. **Corrections review 2026-04-23** : `lastAuditFailed` public property (#4) + `.active()` chain sur négatives (#3).
- `app/Services/Parc/WorkstationGroupService.php` — paramètre `?User $scopeFor = null` sur `listGroups` / `listMachines`, helpers `resolveAuthorizedGroupIds()` / paginators vides. **Corrections review 2026-04-23** : `getRootGroupsForSelect(?User $scopeFor = null)` (#7).
- `app/Repositories/WorkstationGroupRepository.php` — ajout `getGroupsScoped()` / `getMachinesScoped()`.
- `app/Policies/WorkstationGroupPolicy.php` — ajout méthode `manage()` + gate `manage-workstationGroup`. **Corrections review 2026-04-23** : PHPDoc limitation groupes logiques (#9).
- `app/Models/DelegationHistory.php` — **Corrections review 2026-04-23** : `created_at` retiré de `$fillable` (#2).
- `database/migrations/2026_04_23_100000_create_delegation_history_table.php` — **Corrections review 2026-04-23** : TODO trigger Postgres Story 7.2 (#6).
- `routes/web.php` — **Corrections review 2026-04-23** : middleware `can:user.assign.right` sur `/app/rights-management` (#5a).
- `resources/views/pages/parc/index.blade.php` — passe `scopedUser()` aux services. **Corrections review 2026-04-23** : `getRootGroupsForSelect($this->scopedUser())` (#7).
- `resources/views/pages/parc/groups/[id]/index.blade.php` — check `Gate::allows('view', $group)` dans `loadGroup()` + redirect silencieux. **Corrections review 2026-04-23** : `Gate::authorize()` sur `addMachines` / `removeMachine` / `deleteGroup` (#1).
- `resources/views/pages/users/_partials/rights-drawer.blade.php` — trait `WithToasts` + toggle `$isNegative` + recherche salle + routage `applyDelegations` vers `negateDelegation`. **Corrections review 2026-04-23** : guards `abort_unless` (#5b) + check AD dans `ensureEloquentUser` (#A) + toast warning audit best-effort (#4).
- `resources/views/pages/rights-management/index.blade.php` — 4ᵉ onglet `history` + filtres + pagination, historique dans user-lookup, révocation avec toast explicite. **Corrections review 2026-04-23** : guard `revokeDelegation` (#C) + fix `ILIKE`→`LIKE` driver-aware (#8) + toast warning audit best-effort (#4).
- `docs/domains/parc.md` — section Scoping des listings (Story 7.1).
- `docs/domains/rights-management.md` — **Corrections review 2026-04-23** : section "Limitations connues" (#9 + #4 + #6 + bitmask sunset).

---

## Recommandation Modèle Dev

**Recommandation : `opus`**

Justification :
- **Multi-couches transverses** : service (PermissionService) + migration + modèle append-only + observer cascade + Policy + 2 services Livewire (page + drawer) + 6 suites de tests (unit + feature + policy + audit).
- **Logique sécurité critique** : scoping périmètre + audit trail non-modifiable + blocage silencieux hors périmètre. Toute régression = potentielle fuite d'accès inter-périmètres, donc production-sensible.
- **Raisonnement sur invariants** : append-only audit (override `save()` + test qui assure qu'un `update()` throw), cascade FK avant vs après audit write, idempotence `grant/negate` via `updateOrCreate` avec clé composite.
- **Coordination cross-fichiers** : modifier `PermissionService` (signatures) + tous les appelants existants (`rights-drawer`, `rights-management/index`) + ajouter scope aux services Parc + câbler observers + rédiger tests qui valident des chaînes complètes (grant → audit → UI history → revoke → audit → cache stale check).
- **Décisions produit** : 5 points à trancher au kickoff avec Henri. Modèle plus fort = moins de reformulations.

Sonnet serait acceptable si le dev se limite strictement à la partie UI (toasts + onglet history + toggle négative), mais l'ensemble de la story — en particulier audit trail append-only, observers cascade, scoping services, et les 6 suites de tests — justifie Opus.
