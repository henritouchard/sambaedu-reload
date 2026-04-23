# Domaine : Gestion des droits (rights-management)

**Status** : livré (Story 7.1 — 2026-04-23)
**Prérequis Epic 7** : Spatie Permission 6.24, PostgreSQL, matrice `profiles-rights-matrix.md`.

## Vue d'ensemble

Le système de gestion des droits SER combine trois couches :

1. **Rôles Spatie** — assignation statique par seed (cf. enum `App\Enums\SambaRole`).
2. **Permissions Spatie globales** — granularité fine, attribution directe ou via rôle.
3. **Délégations scopées par WorkstationGroup** — droit applicable uniquement à un périmètre physique (salle).

Toute Policy, directive Blade `@can(...)`, ou appel `$user->can(...)` doit **uniquement** s'appuyer sur ces trois couches. Les colonnes `ad_*` et le bitmask legacy ne servent que pour l'interop transitoire (Story 7.3).

## Permissions atomiques

Définies dans `App\Enums\SambaPermission` (18 permissions réparties en 7 catégories) :

| Catégorie           | Permissions                                                             | Délégable |
| ------------------- | ----------------------------------------------------------------------- | --------- |
| user                | `user.password.init`, `user.read`, `user.modify`, …                     | non       |
| share               | `share.view`, `share.refresh`                                           | non       |
| **computer**        | `computer.view`, `computer.control`, `computer.elevate`, `computer.install` | **oui** |
| **wpkg**            | `wpkg.assign`, `wpkg.add`, `wpkg.create`                                | **oui**   |
| server              | `server.admin`                                                          | non       |
| wallpaper           | `wallpaper.manage`                                                      | non       |
| app-customization   | `app.customize`                                                         | non       |

Seules les permissions `computer.*` et `wpkg.*` sont marquées `isDelegatable()` — un rôle `Prof` ou `Technicien` ne peut recevoir que ces permissions scopées sur un WorkstationGroup physique précis.

## Modèle de délégation

```
┌──────┐        ┌──────────────────┐        ┌────────────────────┐
│ User │───┬────│ delegation (row) │────────│  WorkstationGroup  │
└──────┘   │    └──────────────────┘        └────────────────────┘
           │            │
           │            │  permission_id → Spatie Permission
           │            │  is_negative (bool)
           │            │  granted_by → User (optionnel)
           │            │  expires_at (optionnel)
```

**Clé unique composite** : `(user_id, workstation_group_id, permission_id, is_negative)`.
Permet `updateOrCreate` idempotent : un même grant répété → 1 seule ligne, 1 seul audit `grant`.

**Délégation négative (`is_negative = true`)** : exclusion explicite. Surcharge une délégation positive — utilisée pour un admin qui a `computer.view` global mais qu'on veut exclure d'une salle particulière. La logique `canOnWorkstationGroup()` renvoie `false` si une négative existe, même en présence d'une positive.

## Flux `grant / revoke / negate / canOnWorkstationGroup`

```
PermissionService::grantDelegation(User, 'computer.view', Group, GrantedBy?)
   └── Delegation::updateOrCreate(...)                [clé unique composite]
   └── wasRecentlyCreated ? DelegationHistoryService::log('grant', ...)
   └── perm.requiresGpoSync() ? SyncGpoJob::dispatch()

PermissionService::revokeDelegation(User, 'computer.view', Group, Actor?)
   └── Delegation::where(...)->delete()
   └── deleted > 0 ? DelegationHistoryService::log('revoke', ...)
   └── perm.requiresGpoSync() ? SyncGpoJob::dispatch()

PermissionService::negateDelegation(User, 'computer.view', Group, Actor?)
   └── Delegation::updateOrCreate(is_negative=true)
   └── wasRecentlyCreated ? DelegationHistoryService::log('negate', ...)

PermissionService::canOnWorkstationGroup(User, 'computer.view', Group): bool
   ├─ $user->can('computer.view')          → true (droit global wins)
   ├─ positive delegation + active         → OK sauf si négative
   └─ sinon                                → false
```

**Fallback acteur** (Story 7.1) : `revokeDelegation` et `negateDelegation` acceptent un `?User $actor = null`. Si null, on retombe sur `auth()->user()` (via `DelegationHistoryService::resolveActor`). Les appelants existants qui n'injectent pas `$actor` restent compatibles.

## Audit trail — `delegation_history` (append-only)

Chaque mutation d'une délégation écrit une ligne dans `delegation_history` :

| Colonne              | Type      | Notes                                                            |
| -------------------- | --------- | ---------------------------------------------------------------- |
| `actor_user_id`      | FK `users` ON DELETE SET NULL | Qui a agi                                        |
| `target_user_id`     | FK `users` ON DELETE SET NULL | Cible de l'opération                             |
| `workstation_group_id` | FK `workstation_groups` ON DELETE SET NULL | Périmètre                          |
| `permission_name`    | string    | Nom Spatie (string, pas FK : résiste au renommage permissions)   |
| `action`             | string    | `grant` / `revoke` / `negate` / `expire`                         |
| `is_negative`        | bool      | Pour tracer une exclusion vs une délégation positive             |
| `context`            | JSONB     | IP, user-agent, batch_id, …                                      |
| `created_at`         | timestamp | Pas d'`updated_at`                                               |

**Append-only** : le modèle `App\Models\DelegationHistory` override `save()` pour throw `LogicException` si `$this->exists`. Tout `UPDATE` applicatif est donc impossible — les entrées sont immuables une fois écrites.

**Cascade delete** : les FK sont en `ON DELETE SET NULL`. Supprimer un user ou un WorkstationGroup nullifie les FK mais laisse la ligne d'audit lisible. Les lignes `delegations` supprimées par cascade FK ne sont **pas** tracées dans l'audit (décision produit 2026-04-23 : pas d'observer cascade).

## Scoping des listings Parc

`App\Services\Parc\WorkstationGroupService::listGroups()` et `listMachines()` acceptent un paramètre optionnel `?User $scopeFor = null` (Story 7.1) :

- `$scopeFor === null` → comportement historique (toutes les ressources visibles).
- `$scopeFor` avec `computer.view` global via Spatie → toutes les ressources visibles (admin).
- `$scopeFor` sans droit global → contraint à `getAuthorizedWorkstationGroups($user, 'computer.view')` (WorkstationGroups avec délégation positive active non négateée).

Utilisation côté UI : `resources/views/pages/parc/index.blade.php` passe `auth()->user()` via un helper `scopedUser()` qui vérifie le type `App\Models\User` avant de déléguer.

## Policy `WorkstationGroupPolicy`

| Méthode   | Logique                                                                                       |
| --------- | --------------------------------------------------------------------------------------------- |
| `viewAny` | `$user->can('computer.view')` (droit global)                                                  |
| `view`    | Délégation scopée via `canOnWorkstationGroup('computer.view', $group)` si group physique      |
| `create`  | `$user->can('computer.modify')`                                                               |
| `update`  | `$user->can('computer.modify')`                                                               |
| `delete`  | `$user->can('computer.modify')`                                                               |
| `manage`  | **Story 7.1** — `canOnWorkstationGroup('computer.control', $group)` ou droit global           |

Le gate `manage-workstationGroup` contrôle les actions batch, les schedules et l'upload de wallpapers sur un groupe — disponibles pour les délégués avec `computer.control`.

## Blocage d'accès direct hors périmètre

Dans le `mount()` des pages Livewire sensibles (`/parc/groups/{id}`), après résolution du `$group`, on vérifie `Gate::allows('view', $this->group)`. En cas de refus :

1. **Toast d'erreur** flash : `session()->flash('toast', ['type' => 'error', 'title' => 'Accès refusé', 'message' => "Vous n'avez pas accès à cette ressource."])`.
2. **Redirect silencieux** vers `/parc` (AC3 — pas de fuite d'info sur l'existence de la ressource).
3. **Log applicatif info** : user, group_id, group_name, URL.

## UI — `/rights-management`

Page Livewire 4-onglets : `overview` / `user-lookup` / `delegations` / **`history`** (Story 7.1).

- **Historique** : table paginée filtrable (action, user cible, période from/to) des entrées `delegation_history`. Badges colorés par action.
- **Lookup user** : ajoute une section "Historique des délégations" sous "Délégations actives" — 10 dernières entrées pour le user sélectionné.
- **Toggle exclusif dans `rights-drawer.blade.php`** : `isNegative` et `removeDelegation` sont mutuellement exclusifs via les hooks `updatedIsNegative()` et `updatedRemoveDelegation()`.
- **Feedback** : utilise `WithToasts` (plus de bloc HTML `successMessage`/`errorMessage`).

## Liste des fichiers clés

| Fichier                                                         | Rôle                              |
| --------------------------------------------------------------- | --------------------------------- |
| `app/Services/PermissionService.php`                            | Orchestration délégations         |
| `app/Services/DelegationHistoryService.php`                     | Écriture audit trail              |
| `app/Models/Delegation.php`                                     | Modèle délégation                 |
| `app/Models/DelegationHistory.php`                              | Modèle audit append-only          |
| `app/Policies/WorkstationGroupPolicy.php`                       | Policy (view + manage scopés)     |
| `app/Enums/SambaPermission.php`                                 | Permissions atomiques             |
| `app/Enums/SambaRole.php`                                       | Rôles seed                        |
| `resources/views/pages/rights-management/index.blade.php`       | Page SFC Livewire 4 onglets       |
| `resources/views/pages/rights-management/_partials/history-tab.blade.php` | Onglet Historique       |
| `resources/views/pages/users/_partials/rights-drawer.blade.php` | Drawer grant/remove/negate        |
| `database/migrations/2026_04_23_100000_create_delegation_history_table.php` | Migration            |

## Tests PROD

| Suite                                                             | Focus                              |
| ----------------------------------------------------------------- | ---------------------------------- |
| `tests/Unit/Services/PermissionServiceTest.php`                   | idempotence / positive / négative / expirée / GPO |
| `tests/Unit/Services/Parc/WorkstationGroupServiceScopingTest.php` | scope listGroups / listMachines    |
| `tests/Feature/Services/DelegationHistoryTest.php`                | audit append-only / acteur auth    |
| `tests/Feature/Policies/WorkstationGroupScopingTest.php`          | 403 hors périmètre / revoke immédiat / negate |
| `tests/Feature/Livewire/RightsManagementPageTest.php`             | 4 onglets / filtres historique / revoke |
| `tests/Feature/Livewire/UserRightsDrawerTest.php`                 | grant / remove / negate / GPO / autoCreate |

## Limitations connues

### Groupes logiques parents (Review #9, décision Henri 2026-04-23)

Un délégué sur une salle physique **ne voit PAS** les groupes logiques
parents de cette salle, même s'ils sont accessibles via breadcrumb. La
`WorkstationGroupPolicy::view` retombe sur le droit global `computer.view`
pour tout groupe `!is_physical`.

**Rationale** : les salles physiques ont des hiérarchies mais les groupes
logiques de rangement ne sont pas cible de délégation en 7.1. Conséquence
UX acceptée : un lien breadcrumb "Groupe parent" peut donner 403 au
délégué — acceptable tant que le nom du groupe parent n'est pas rendu
côté UI pour les non-admins.

### Audit best-effort (Review #4, décision Henri 2026-04-23 — Option B)

L'écriture dans `delegation_history` est best-effort : un échec d'écriture
(DB indisponible, violation de constraint…) **ne bloque PAS** l'opération
de délégation. En cas d'échec, `PermissionService::$lastAuditFailed` passe
à `true` et l'UI affiche un toast warning ("délégation appliquée mais
traçabilité non enregistrée"). L'admin a ainsi un signal immédiat pour
contacter l'équipe d'exploitation.

### Append-only guard applicatif uniquement (Review #6, reporté Story 7.2)

Le modèle `DelegationHistory::save()` lève `LogicException` si `$exists`
vaut true → protège les `->save()` / `->update()` Eloquent. Mais
`DB::table('delegation_history')->update()` passe outre. En prod Postgres,
il faudra ajouter un trigger `BEFORE UPDATE` pour hardening — hors scope
7.1, reporté à 7.2.

### Bitmask legacy sunset

La conversion `permissionsToBitmask` / `bitmaskToPermissions` reste
exposée pour la compatibilité legacy (console commands, rapports). Aucun
code web ne la consomme — elle disparaîtra définitivement après 7.3.


---

## Story 7.2 — Profils dynamiques, Policies complètes, cache durci (2026-04-23)

### Profils dynamiques (rôles éditables à chaud)

Depuis Story 7.2, les rôles Spatie ne sont plus seulement issus de l'enum
`SambaRole`. Ils se décomposent en deux origines :

- **Seedés** (9 rôles livrés par défaut, cf. `SambaRole::cases()`) :
  `Eleve`, `Prof`, `EleveAdmin`, `ShareAdmin`, `UserAdmin`, `Technicien`,
  `ReferentNumerique`, `ComputerAdmin`, `SuperAdmin`. Source de vérité =
  l'enum. `SambaRole::isSeeded(string $name): bool` distingue ces rôles
  des profils custom.

- **Custom** (créés à l'UI ou rapatriés de la branche LDAP `rights_rdn`) :
  ex. "Animateur CDI", "Référent RGPD", "Documentaliste 2nde". Composés
  librement de permissions `SambaPermission` (décision 0.5 — pas de
  restriction `isDelegatable()`).

#### CRUD via UI

Onglet "Profils" (5ᵉ) de `/app/rights-management`. Actions :

| Action      | Seedé | Custom |
| ----------- | ----- | ------ |
| Renommer    | ❌    | ✅     |
| Éditer perms| ✅    | ✅     |
| Dupliquer   | ✅    | ✅     |
| Supprimer   | ❌    | ✅     |

Garde-fou suppression : refus si des users sont assignés au rôle. L'admin
doit d'abord les retirer via le drawer `rights-drawer`.

Après chaque mutation : `app(PermissionRegistrar::class)->forgetCachedPermissions()`
est appelé explicitement dans le composant Livewire (`saveProfile` /
`duplicateProfile` / `deleteProfile`).

#### Seed idempotent non-destructif

`PermissionSeeder::run(bool $force = false)` :
- Crée les 19 permissions via `findOrCreate`.
- Crée les 9 rôles seedés via `firstOrCreate`.
- Attache les permissions canoniques **seulement si le rôle vient d'être créé**
  (`wasRecentlyCreated`). Sinon, ne touche pas — respecte les modifications
  admin.
- `--force` (param méthode) → resynchronise explicitement (cas migration
  d'enum).
- Les rôles custom (non-seedés) sont ignorés par le seeder.

**Commande** : `php artisan db:seed --class=PermissionSeeder`.

#### Rapatriement non-destructif depuis LDAP

`PermissionService::importCustomProfilesFromAd()` :
- Scanne la branche `rights_rdn` via `LdapRightGroup::getAllRightsValues()`.
- Ignore les 5 profils seedés (`se3_is_admin`, `computer_is_admin`,
  `Annu_is_admin`, `password_is_admin`, `RefNum`).
- Mappe les 3 profils historiques reconnus (`sovajon_is_admin`,
  `annu_can_read`, `password_can_reinit`) vers les rôles Spatie.
- Pour les profils custom inédits : `firstOrCreate` + attachement des
  permissions via `SambaPermission::fromBitmask($info)` **seulement si
  `wasRecentlyCreated`**.
- Profils custom déjà en base SER : jamais modifiés.
- Bug legacy `Annu_is_admin` sans `info` → évité (profil seedé ignoré).

Invoqué depuis `UserSyncService::importUsersFromAd` après
`ensurePermissionsExist`, et depuis `/admin/sync-from-ad` étape 8
"Rapatrier les profils LDAP custom".

### Policies complètes (AC5)

Les 5 Policies manquantes sont livrées en Story 7.2 :

| Policy              | Modèle adossé       | Gates principales                                          |
| ------------------- | ------------------- | ---------------------------------------------------------- |
| `DelegationPolicy`  | `Delegation`        | `view-delegation`, `create-delegation`, `delete-delegation` |
| `MachinePolicy`     | `Workstation`       | `view-workstation`, `control-workstation`, `elevate-workstation` |
| `PrinterPolicy`     | —                   | `viewAny-printer`, `manage-printer` (→ `server.admin`)      |
| `SharePolicy`       | —                   | `viewAny-share`, `refresh-share` (→ `share.*`)             |
| `DhcpPolicy`        | —                   | `viewAny-dhcp`, `manage-dhcp` (→ `server.admin`)           |

Toutes enregistrées dans `AuthServiceProvider::boot()` via
`::registerGates()`. `DelegationPolicy` et `MachinePolicy` sont aussi
mappées dans `$policies[]` pour activer les `@can('view', $model)` Blade
natifs Laravel.

### Scoping classe pour `Prof` (décisions a + b)

`UserPolicy::resetPassword` et `UserPolicy::view` sont scopées classe pour
le rôle `Prof` seul :

```php
// Si actor a user.password.init ET joue (seulement) le rôle Prof :
$actorClasses = $actor->userGroups()->where('type', 'class')->pluck('id');
$targetClasses = $target->userGroups()->where('type', 'class')->pluck('id');
return $actorClasses->intersect($targetClasses)->isNotEmpty();
```

Les rôles administratifs globaux (`super-admin`, `user-admin`,
`eleve-admin`, `referent-numerique`) bypassent ce scoping. Les rôles
custom avec `user.password.init` (hors Prof) sont traités comme globaux
par défaut.

**Cas itinérant (décision c)** : pas de traitement spécial. Un Prof
rattaché à 3 classes sur 3 établissements fonctionne de la même manière.
Les droits sont rattachés aux users + classes, pas à la localisation.

### Cache Spatie

Configuration par défaut conservée :
- `cache.expiration_time = 24h`
- `cache.key = spatie.permission.cache`
- `cache.store = default` (Redis en prod via `CACHE_STORE`, array en tests)

Invalidation explicite post-mutation :

| Lieu                                          | Méthode                                    |
| --------------------------------------------- | ------------------------------------------ |
| `saveProfile` / `duplicateProfile` / `deleteProfile` | `forgetCachedPermissions()`         |
| `UserRightsDrawer::applyRoles/Permissions`    | déjà câblé (Story 7.1)                    |
| `UserSyncService::ensurePermissionsExist`     | début et fin de bloc                      |
| `PermissionSeeder::run`                       | début et fin                              |
| `PermissionService::importCustomProfilesFromAd` | fin de batch                            |

Reset manuel : `php artisan permission:cache-reset`.

### Routes protégées (AC8)

| Route                        | Middleware                       |
| ---------------------------- | -------------------------------- |
| `/app/users`                 | `can:user.read`                  |
| `/app/users/new`             | `can:user.modify`                |
| `/app/users/{login}`         | `can:user.read`                  |
| `/app/users/groups/new`      | `can:user.modify`                |
| `/app/users/groups/{id}`     | `can:user.read`                  |
| `/app/rights-management`     | `can:user.assign.right`          |
| `/app/parc`                  | `can:computer.view`              |
| `/app/parc/groups/new`       | `can:computer.modify`            |
| `/app/parc/groups/{id}`      | `can:computer.view`              |
| `/app/parc/groups/{id}/edit` | `can:computer.modify`            |
| `/app/parc/machines/{id}`    | `can:computer.view`              |
| `/app/parc-settings/*`       | `can:computer.install`           |
| `/app/parc-settings/wallpapers` | `can:wallpaper.manage`         |
| `/app/parc-settings/app-customizations` | `can:app.customize`     |
| `/admin/sync-from-ad`        | `can:server.admin`               |

Toute route sensible retournant 403 sans permission → log automatique par
`Illuminate\Auth\Middleware\Authorize`. Décision 0.7 actée : pas de
wrapper `sambaedu.can` dédié.

### Décisions produit 2026-04-23 actées

| # | Décision                             | Choix retenu                                      |
| - | ------------------------------------ | ------------------------------------------------- |
| a | `resetPassword` Prof                 | Scopé classe (intersection `UserGroup.type=class`) |
| b | `view` Prof                          | Scopé classe (cohérent avec a)                    |
| c | Cas itinérant                        | Pas de traitement spécial (droits rattachés user+classes) |
| d | Profils dynamiques — UI              | Nouvel onglet "Profils" dans `/app/rights-management` |
| e | Permissions dans profils custom      | Toutes les `SambaPermission` (pas seulement delegatables) |
| f | Rapatriement LDAP custom             | Non-destructif uniquement (jamais d'écrasement)   |
| 0.7 | Wrapper `sambaedu.can`              | Non — `can:` Laravel suffit                        |
| 0.8 | Perms `printer.manage`/`dhcp.manage` | Réutiliser `server.admin` (pas de nouvelle perm)   |
| 0.9 | Colonne `origin` sur `roles`         | Non — `SambaRole::isSeeded()` en enum             |
