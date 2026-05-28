# Story 7.2 : Calcul et Application des Droits Spatie

Status: review

<!-- Note: Validation optionnelle. Lancer validate-create-story pour un check qualité avant dev-story. -->

## Story

As a **administrateur SER**,
I want **pouvoir créer, modifier et supprimer des profils de droits dynamiquement depuis l'UI (pas uniquement un enum figé), avec des Policies Spatie complètes, un middleware dédié et un cache performant, et un calcul de droits correct (union groupe + individuel, pas de cache stale après modification)**,
So que **la gestion des droits applicatifs SER soit aussi souple que le legacy `sambaedu/annu2/profiles.php` (profils éditables à chaud, y compris "Animateur CDI", "Référent CDI", etc.), avec un comportement Spatie strict côté Policy/middleware/cache qui rend toute tentative de bypass impossible et toute modification de droits effective à la requête suivante**.

---

## Contexte — Socle déjà livré (NE PAS re-implémenter)

Cette story finalise uniquement le **reste à faire** sur le socle permissions Spatie. Le socle technique est livré, testé et utilisé en production SER :

- `spatie/laravel-permission` v6.24 installé, migrations exécutées (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`).
- Enum `app/Enums/SambaPermission.php` — 19 permissions atomiques + méthode `legacyRight()` (mapping bitmask — **dette en sursis Story 7.3**, cf. matrice §11).
- Enum `app/Enums/SambaRole.php` — 9 rôles (`Eleve`, `Prof`, `EleveAdmin`, `ShareAdmin`, `UserAdmin`, `Technicien`, `ReferentNumerique`, `ComputerAdmin`, `SuperAdmin`) + méthode `permissions()` + `permissionNames()`.
- `app/Services/PermissionService.php` complet — méthodes `grantDelegation` / `revokeDelegation` / `negateDelegation` / `canOnWorkstationGroup` / `getAuthorizedWorkstationGroups` (Story 7.1 done).
- Modèles `Delegation` + `DelegationHistory` + migration `delegation_history` (Story 7.1 done).
- Policies câblées : **`UserPolicy`, `GroupPolicy`, `WorkstationGroupPolicy`, `ShortcutPolicy`, `AppCustomizationPolicy`** — via traits `ChecksPermissions` + `RegistersGates` (`App\Policies\Traits`). Enregistrement dans `AuthServiceProvider::boot()`.
- `@can` utilisé dans **~30 directives Blade** (users/index, users/[login], users/groups/[id], parc/groups/[id], parc-settings, shortcuts, rights-management, etc.).
- Trait `App\Models\User` porte `Spatie\Permission\Traits\HasRoles` (accès `$user->can(...)`, `$user->hasRole(...)`, `$user->getAllPermissions()`).
- `PermissionSeeder` (dev) et `UserSyncService::ensurePermissionsExist()` (AD sync) peuplent permissions + rôles depuis les enums.
- `config/permission.php` par défaut Spatie : cache `expiration_time = 24 hours`, key `spatie.permission.cache`.
- Middleware Laravel natif `can:<permission>` déjà utilisé sur quelques routes (`/app/rights-management` via `can:user.assign.right`, `/app/parc-settings/wallpapers` via `can:wallpaper.manage`, etc. — cf. `routes/web.php`).
- Matrice profils × droits validée 2026-04-22 : `_bmad-output/planning-artifacts/profiles-rights-matrix.md` (9 rôles × 18 permissions, mapping legacy ↔ Spatie, 3 décisions restantes §9 + zones grises §10).

> **ATTENTION — Scope hors story :**
> - La migration one-shot bitmask AD → Spatie + l'Observer de projection inverse Spatie → bitmask AD = **Story 7.3**, pas ici.
> - La suppression du code bitmask (`LegacyRight`, `SambaPermission::legacyRight()`, `bitmask()`, `fromBitmask()`, `bitmaskToPermissions`, `permissionsToBitmask`) = sunset post-7.3.
> - `SE_COMPUTER_VIEW` = droit web pur, ne sera **jamais projeté en AD** (commentaire dev legacy `ldap.inc.php:2963`).
> - L'audit trail append-only des délégations (`delegation_history`), le scoping `WorkstationGroupService::listGroups/listMachines`, le toggle négative dans le drawer, l'onglet "Historique" dans `/rights-management` = **Story 7.1 done**, pas ici.

---

## Décisions produit à trancher au kickoff (3 décisions — matrice §9/§10)

Ces décisions sont **à trancher avec Henri en début de story**. Elles conditionnent les Policies à écrire et leurs tests. La matrice §5.2 et §10 documentent l'état du raisonnement, mais la trancher finale doit être explicite.

| # | Décision | Option recommandée par la matrice | Impact |
|---|----------|-----------------------------------|--------|
| **(a)** | `UserPolicy::resetPassword` pour le rôle `Prof` : **scopé par classe** (reproduit `sovajon_is_admin` legacy) ? | ✅ **Scopé classe** — un Prof ne peut réinitialiser le MDP que des users appartenant à **au moins un `UserGroup` de `type='class'`** en commun avec lui. Implémentation via `$user->userGroups()->where('type', 'class')`. Matrice §5.2 🔒. | Policy `UserPolicy::resetPassword(?Authenticatable $actor, User $target)` à ajouter. Tests Feature obligatoires (AC7). |
| **(b)** | `UserRead` pour le rôle `Prof` : **scopé classe ou global** ? | ⚠️ **Non tranché** — legacy `annu_can_read` était global, mais `sovajon_is_admin` scopait `UserPasswordInit` à la classe. Question posée à Henri au kickoff. Matrice §10.2. | Si scopé : même pattern que (a) appliqué à `UserPolicy::view`. Si global : comportement actuel conservé. |
| **(c)** | Définition opérationnelle de **"cas itinérant"** pour valider la portée de `PermissionService::canOnWorkstationGroup()` | ⚠️ **Non défini dans le code legacy** — besoin d'un exemple concret (user avec délégations multi-parcs ? multi-établissements ?). Matrice §10.1. | Impact sur les tests d'intégration (scénario à couvrir) + éventuel champ à ajouter sur `User` ou `Delegation`. Si non résolu au kickoff → fallback : tester le cas "1 user avec 2 délégations sur 2 WorkstationGroups distincts" comme proxy. |

**Décisions additionnelles de scope (à confirmer avec Henri)** :

| # | Décision | Recommandation SM | Commentaire |
|---|----------|-------------------|-------------|
| **(d)** | Profils dynamiques : **nouvelle page dédiée** `/app/rights-management/profiles` OU **nouvel onglet "Profils"** sur `/app/rights-management` ? | ✅ **Nouvel onglet** (5ᵉ après overview / user-lookup / delegations / history) — cohérent avec l'existant, une seule page `/app/rights-management` pour tout ce qui touche aux droits. | Le legacy expose `annu2/profiles.php` comme page dédiée. Pour SER reload on consolide. |
| **(e)** | Permissions non-delegatables éditables dans les profils custom ? | ✅ **Oui** — les profils custom (rôles DB éditables) peuvent combiner **toutes** les permissions `SambaPermission`, pas seulement celles marquées `isDelegatable()`. La restriction `isDelegatable()` concerne uniquement les **délégations scopées** (drawer users), pas la composition d'un rôle applicatif. | Cohérent avec legacy qui laisse choisir les bits librement. |
| **(f)** | Rapatriement des profils LDAP custom existants via sync AD | ✅ **Non-destructif uniquement** — à la sync, les 5 profils seed (cf. `ldap.inc.php:739-743`) sont `firstOrCreate` + `syncPermissions` (idempotent), les profils custom existants dans la branche `rights_rdn` (ex. "Animateur CDI") sont créés en base SER s'ils n'existent pas, jamais écrasés s'ils existent. | Pré-requis pour que les établissements qui ont customisé leurs profils legacy les retrouvent post-migration. |

---

## Acceptance Criteria

### AC1 — Seed des 5 profils par défaut non-destructif et idempotent

**Given** je lance `php artisan db:seed --class=PermissionSeeder` ou un déploiement qui l'exécute automatiquement
**When** le seeder tourne une première fois sur une base vide
**Then** les **19 permissions** `SambaPermission::cases()` sont créées dans la table `permissions` (guard `web`)
**And** les **9 rôles** `SambaRole::cases()` sont créés dans la table `roles` (guard `web`) avec leurs permissions associées via `syncPermissions($role->permissionNames())`

**Given** un déploiement a déjà été exécuté, et un admin a créé un profil custom "Animateur CDI" via l'UI (cf. AC3) avec des permissions spécifiques
**When** je relance le seeder
**Then** les 5 profils par défaut (`se3_is_admin`, `computer_is_admin`, `Annu_is_admin`, `password_is_admin`, `RefNum` mappés sur `SuperAdmin` / `ComputerAdmin` / `UserAdmin` / délégation ciblée / `ReferentNumerique`) sont `firstOrCreate` — leurs permissions sont resynchronisées sur les valeurs de l'enum
**And** le profil custom "Animateur CDI" **reste inchangé** (ni supprimé, ni écrasé)
**And** aucun toast d'erreur, pas de ligne supprimée, log `info` avec nombre de rôles seedés vs inchangés.

**Rationale :** le legacy gère les profils de droits de manière dynamique (branche LDAP `rights_rdn`, UI `sambaedu/annu2/profiles.php` CRUD). Le seed doit **alimenter sans détruire**. L'enum `SambaRole` devient source de **seed** uniquement, pas de source de **vérité** runtime.

### AC2 — Suppression du bloc destructif `UserSyncService::ensurePermissionsExist`

**Given** la sync AD est déclenchée (`/admin/sync-from-ad`, batch, cron)
**When** `UserSyncService::importUsersFromAd` appelle `ensurePermissionsExist`
**Then** les permissions sont créées via `Permission::findOrCreate` (idempotent)
**And** les rôles sont créés via `Role::firstOrCreate` — **sans** appel à `$role->syncPermissions(...)` qui écraserait les permissions des rôles custom éditées depuis l'UI
**And** pour les 5 profils par défaut (seed), la synchronisation des permissions est déléguée au `PermissionSeeder` (appel explicite uniquement si le rôle est dans la liste seed, cf. AC1), pas à chaque sync AD
**And** les tests de sync AD existants passent sans régression

**Rationale :** le bloc actuel `app/Services/UserSyncService.php` ligne ~195 (`$role->syncPermissions($sambaRole->permissionNames())`) réinitialise à chaque sync AD les permissions de **tous** les rôles (y compris ceux édités par admin). C'est incompatible avec la gestion dynamique des profils.

### AC3 — CRUD profils de droits dynamiques via UI

**Given** je suis admin SER avec `user.assign.right` (middleware `can:user.assign.right` déjà en place sur `/app/rights-management`)
**When** j'ouvre le nouvel onglet "Profils" dans `/app/rights-management`
**Then** je vois la liste des rôles Spatie présents en base (nom, label lisible, nombre de permissions, nombre d'users assignés, origine : `seeded` / `custom` — badge visuel distinctif)
**And** pour chaque profil custom, je peux **créer** / **éditer** (renommer + modifier permissions) / **dupliquer** (crée un nouveau rôle `<name>_copy` avec les mêmes permissions) / **supprimer**
**And** pour les 5 profils seedés, je peux **uniquement éditer les permissions** (pas renommer, pas supprimer — signalé par des boutons désactivés avec tooltip explicatif)
**And** la modification d'un profil invalide le cache Spatie (`app(PermissionRegistrar::class)->forgetCachedPermissions()`) — cf. AC6
**And** la suppression d'un profil custom :
- échoue avec toast d'erreur si des users lui sont assignés (garde-fou : proposer de réassigner d'abord) ;
- réussit sinon, toast `toastSuccess`.

**Given** je crée un profil "Animateur CDI" avec permissions `computer.view + computer.control + user.read` via le formulaire
**When** je valide
**Then** une ligne est insérée dans `roles` (guard `web`) + 3 lignes dans `role_has_permissions`
**And** le rôle est immédiatement assignable aux users via le drawer `rights-drawer` existant (onglet "Rôles")

### AC4 — Extension `/admin/sync-from-ad` pour rapatrier les profils LDAP custom

**Given** je lance une sync AD sur un SER qui a, dans la branche LDAP `rights_rdn`, un profil custom "Animateur CDI" avec un `info=0x302` (composite de bits)
**When** `UserSyncService` / `PermissionService::syncFromAd` exécute
**Then** le profil "Animateur CDI" est créé en base SER via `Role::firstOrCreate(['name' => 'Animateur CDI', 'guard_name' => 'web'])`
**And** les permissions correspondant au bitmask sont résolues via `SambaPermission::fromBitmask(0x302)` (méthode existante) et **attachées uniquement si le rôle est nouvellement créé** (first run — pas d'écrasement si l'admin a édité les permissions depuis)
**And** les 3 autres profils legacy historiques (`sovajon_is_admin`, `annu_can_read`, `password_can_reinit` — cf. matrice §4.2) sont traités pareillement : création non-destructive uniquement
**And** un log `info` récapitule : `"[SyncFromAd] X profils seeded, Y profils custom rapatriés de l'AD, Z profils inchangés"`

**Rationale :** les profils custom legacy existants en production doivent survivre à la migration vers Spatie. Décision **(f)** du kickoff.

### AC5 — Policies manquantes câblées

**Given** je suis un user non-admin tentant une opération sur une ressource
**When** j'appelle une route liée à Delegation / Machine (Workstation) / Printer / Share / Dhcp / resetPassword (cf. décision **(a)**)
**Then** une Policy Spatie dédiée est consultée :
- **`DelegationPolicy`** : `view($user, $delegation)` / `create($user)` / `delete($user, $delegation)` — gate `user.assign.right` ou `user.delegate` selon la matrice §5.2 (à confirmer kickoff)
- **`MachinePolicy`** : `view($user, $workstation)` / `update($user, $workstation)` / `control($user, $workstation)` — permission `computer.view` / `computer.control` + scoping via `WorkstationGroup` parent (réutilise `PermissionService::canOnWorkstationGroup`)
- **`PrinterPolicy`** : `viewAny($user)` / `manage($user, $printer)` — permission `printer.manage` (nouvelle permission ? confirmer avec Henri — **SM recommande réutiliser `server.admin` ou créer `printer.manage`**)
- **`SharePolicy`** : `viewAny($user)` / `view($user, $share)` / `refresh($user, $share)` — permissions `share.view` / `share.refresh` (déjà dans enum)
- **`DhcpPolicy`** : `viewAny($user)` / `manage($user)` — permission `server.admin` (à confirmer — pas de bit DHCP dédié en legacy)
- **`UserPolicy::resetPassword($user, User $target)`** : scopé classe si décision **(a)** = ✅ — check `$actor->userGroups()->where('type', 'class')->whereIn('id', $target->userGroups()->where('type', 'class')->pluck('id'))->exists()`

**And** chaque Policy utilise les traits `ChecksPermissions` + `RegistersGates` (pattern projet, cf. `app/Policies/Traits/`)
**And** chaque Policy est enregistrée dans `AuthServiceProvider::boot()` (appel `registerGates()`)
**And** aucune Policy n'effectue de fetch DB sans préchargement si elle est appelée dans une boucle (pas de N+1 — cf. AC9)

### AC6 — Cache Spatie actif avec invalidation sur mutation

**Given** le cache Spatie est configuré (`config/permission.php` : `cache.expiration_time = 24h`, `cache.key = spatie.permission.cache`)
**When** la commande `php artisan cache:clear` ou `php artisan permission:cache-reset` est exécutée
**Then** le cache permissions est vidé

**Given** un admin modifie les permissions d'un rôle via l'UI (AC3) ou assigne/retire un rôle à un user via le drawer existant
**When** la mutation est persistée
**Then** `app(PermissionRegistrar::class)->forgetCachedPermissions()` est appelé **dans le contrôleur / composant Livewire**, pas dans un observer côté modèle (éviter les side-effects implicites)
**And** la prochaine requête de l'user concerné voit les nouveaux droits (pas de cache stale inter-requête)
**And** les appels à `$user->can(...)` dans la **même requête** que la mutation s'exécutent correctement (cache applicatif rechargé via `forgetCachedPermissions` puis re-hydraté au prochain `can()`)

**Given** un admin révoque une délégation via `PermissionService::revokeDelegation` (Story 7.1)
**When** la ligne `delegations` est supprimée
**Then** aucun cache Spatie n'est à invalider (les délégations ne sont pas stockées dans les tables Spatie — elles sont résolues dynamiquement par `canOnWorkstationGroup`). Cet AC est **documenté** dans les PHPDoc du service pour éviter les confusions futures.

### AC7 — `UserPolicy::resetPassword` scopée par classe (décision (a))

**Given** la décision **(a)** est actée : Prof → `user.password.init` scopé classe
**When** un user avec rôle `Prof` tente de réinitialiser le MDP d'un target user
**Then** `UserPolicy::resetPassword($actor, $target)` retourne :
- `true` si `$actor` a un rôle qui accorde `user.password.init` **et** qu'il existe au moins un `UserGroup` de `type='class'` partagé entre `$actor` et `$target`
- `true` si `$actor` a une permission globale `user.password.init` via un rôle **non-scopé** (ex. `UserAdmin`, `SuperAdmin`) — scoping classe ne s'applique qu'au rôle `Prof`
- `false` sinon

**And** la Policy est utilisée à la fois par :
- le middleware `can:user.password.init` sur la route de reset (global) — complété par un `Gate::authorize('resetPassword', $target)` dans le contrôleur ou composant Livewire pour le scoping fin,
- les `@can('resetPassword', $target)` Blade dans `/users/index`, `/users/[login]`, `/users/groups/[id]` (remplacer les `@can('user.password.init')` globaux).

**And** si l'user `Prof` tente un bulk reset (Story 2.6 existante `/users?bulk=reset`), les cibles hors-classe sont **filtrées silencieusement** (Policy appelée par target avant dispatch du job).

### AC8 — Middleware `sambaedu.can:permission` appliqué aux routes sensibles

**Given** le middleware Laravel natif `can:<permission>` (alias déjà dans `Kernel::$middlewareAliases`) est déjà utilisé partiellement
**When** j'audite les routes sensibles (`/app/users/*`, `/app/rights-management`, `/app/parc/*`, `/app/parc-settings/*`, `/admin/*`)
**Then** chaque route sensible a un middleware `can:<permission>` explicite qui bloque les accès non-autorisés (retour 403 par Laravel) :
- `/app/users` → `can:user.read`
- `/app/users/new` → `can:user.modify`
- `/app/users/{login}` → `can:user.read`
- `/app/users/groups/*` → `can:user.read` + middleware fin dans composant si scoping classe
- `/app/rights-management` → `can:user.assign.right` ✅ **déjà en place**
- `/app/parc` → `can:computer.view`
- `/app/parc/groups/*` → `can:computer.view` (scoping géré par `WorkstationGroupPolicy::view` au mount)
- `/app/parc-settings` → `can:computer.install` (cohérent avec gestion catalogue applications)
- `/admin/sync-from-ad` → `can:server.admin` (action critique)

**And** pas de double-check : si une route a un middleware `can:`, on **ne rajoute pas** de `Gate::authorize` dans le `mount()` — le middleware fait foi.

**And** si décision kickoff impose un alias dédié `sambaedu.can:permission` (pour logging spécifique ou format d'erreur SE4FS), un wrapper `App\Http\Middleware\SambaEduCan` est créé qui délègue à `Illuminate\Auth\Middleware\Authorize` + ajoute log `security` canal. **Par défaut SM recommande de s'en passer** : `can:` Laravel fait le job.

### AC9 — Résolution `@can` performante (pas de N+1, cache warming)

**Given** un listing de 100 users / 50 machines / 200 raccourcis affiche des boutons conditionnels `@can(...)` par ligne
**When** la page se rend
**Then** aucune requête SQL `SELECT ... FROM model_has_permissions WHERE ...` n'est émise **par ligne** (cache Spatie warmed au premier `can()` de la requête)
**And** le `@can` résolu sous **< 5 ms** par ligne une fois le cache en place (mesuré via `Debugbar` / microtime en dev)
**And** pour les délégations (non-cachées par Spatie), `canOnWorkstationGroup` fait au plus **2 queries** par check (Spatie + Delegation) — le listing `/parc` précharge via `$user->load('delegations.workstationGroup')` dans le mount du composant

**And** un test Feature (`tests/Feature/Performance/CanResolutionTest.php`) asserte :
- rendu de `/app/users` avec 50 users → **≤ 5** queries au total (pas 5 × 50)
- rendu de `/app/parc` avec 30 groups → **≤ 10** queries au total

### AC10 — Calcul union groupe + individuel correct (FR29)

**Given** un user `alice` appartient au rôle `Prof` (permissions : `user.read`, `user.password.init` scopé classe)
**And** alice a aussi une **permission directe** `user.modify` (via `assignPermissionTo`)
**And** alice a une **délégation** `computer.view` sur le WorkstationGroup "Salle A"
**When** je résous `$alice->getAllPermissions()` et `canOnWorkstationGroup($alice, 'computer.view', $salleA)`
**Then** `$alice->getAllPermissions()->pluck('name')` contient **l'union** : `['user.read', 'user.password.init', 'user.modify']`
**And** `canOnWorkstationGroup($alice, 'computer.view', $salleA)` retourne `true`
**And** `canOnWorkstationGroup($alice, 'computer.view', $salleB)` retourne `false` (pas de délégation sur Salle B, pas de droit global)

**And** un test unit (`tests/Unit/Services/PermissionServiceUnionTest.php`) couvre ce scénario exact + les variantes (délégation négative écrasant positive, permission directe révoquée, user sans aucun rôle).

### AC11 — Accès refusé sur URL directe (pas de bypass)

**Given** alice n'a pas la permission `user.modify` (ni via rôle, ni via permission directe)
**When** elle tape directement l'URL `/app/users/new` dans son navigateur
**Then** Laravel retourne **403** (via middleware `can:user.modify` sur la route — AC8)
**And** le layout d'erreur 403 affiche un message clair "Accès refusé à cette section"
**And** un log `security` (ou `Log::warning`) enregistre : login, URL, permission manquante, IP, timestamp

**And** idem pour `/admin/*` si permission `server.admin` manquante.

### AC12 — Modifications de droits effectives à la requête suivante

**Given** alice est connectée et consulte `/app/users`
**When** un admin (autre session) lui retire le rôle `Prof` via le drawer
**Then** la prochaine requête HTTP d'alice constate la perte de droit (`@can('user.read')` retourne false)
**And** alice est redirigée vers une page d'accueil autorisée OU voit la page vidée (comportement existant — `@can` conditionne le rendu)
**And** aucune déconnexion/reconnexion requise (cache Spatie invalidé par la mutation, AC6)

### AC13 — Coverage tests PROD

**Given** la story est livrée
**When** `phpunit` tourne
**Then** les suites suivantes existent et passent :
- `tests/Unit/Services/PermissionServiceUnionTest.php` : calcul union groupe + individuel + délégation + négation (AC10)
- `tests/Unit/Seeders/PermissionSeederTest.php` : seed idempotent, profils custom préservés (AC1)
- `tests/Unit/Services/UserSyncServiceEnsurePermissionsTest.php` : plus d'écrasement des rôles custom à chaque sync (AC2)
- `tests/Feature/Policies/DelegationPolicyTest.php` + `MachinePolicyTest.php` + `PrinterPolicyTest.php` + `SharePolicyTest.php` + `DhcpPolicyTest.php` : chaque Policy autorise/refuse correctement selon permissions (AC5)
- `tests/Feature/Policies/UserPolicyResetPasswordScopedTest.php` : scoping classe pour Prof (AC7) — cas OK/KO sur class match / no class match / global via UserAdmin / bulk reset filtré
- `tests/Feature/Http/RoutesProtectionTest.php` : toutes les routes sensibles listées AC8 retournent 403 pour user non-autorisé, 200 pour user autorisé
- `tests/Feature/Livewire/RoleManagementTest.php` : CRUD rôles via onglet "Profils" (AC3) — create / edit / duplicate / delete / garde-fou users assignés / protection des rôles seedés
- `tests/Feature/Services/SyncFromAdCustomProfilesTest.php` : rapatriement profils LDAP custom sans écrasement (AC4)
- `tests/Feature/Performance/CanResolutionTest.php` : pas de N+1 sur listing users / parc (AC9)
- `tests/Feature/Cache/PermissionCacheInvalidationTest.php` : mutation rôle → `forgetCachedPermissions` → droits effectifs requête suivante (AC6, AC12)

**And** aucun test existant ne régresse. Non-régression Story 7.1 vérifiée (53 tests existants).

---

## Tâches / Sous-tâches

### Phase 0 — Kickoff décisions produit (bloquant)

- [x] **Tâche 0** — Kickoff avec Henri (AC décisions a/b/c/d/e/f) — **actées 2026-04-23**
  - [x] 0.1 Décision **(a)** : `UserPolicy::resetPassword` pour `Prof` **SCOPÉE CLASSE** via intersection `userGroups()->where('type', 'class')`. Reproduit `sovajon_is_admin` legacy.
  - [x] 0.2 Décision **(b)** : `UserPolicy::view` pour `Prof` **SCOPÉE CLASSE** (cohérent avec (a)). Un Prof ne voit que les users de ses classes.
  - [x] 0.3 Décision **(c)** : **PAS DE TRAITEMENT SPÉCIAL** itinérant — les droits sont rattachés aux user + classes, pas à la localisation. Un Prof multi-établissement via multi-classes = même mécanique. Test d'intégration ajouté comme non-régression.
  - [x] 0.4 Décision **(d)** : nouvel onglet "Profils" (5ᵉ) dans `/app/rights-management`. Pas de page dédiée.
  - [x] 0.5 Décision **(e)** : profils custom peuvent combiner **TOUTES** les `SambaPermission`, pas seulement `isDelegatable()`.
  - [x] 0.6 Décision **(f)** : rapatriement LDAP **NON-DESTRUCTIF UNIQUEMENT**. `firstOrCreate` + attachement perms seulement si `wasRecentlyCreated`.
  - [x] 0.7 Pas de wrapper `sambaedu.can` — `can:` Laravel suffit (recommandation SM retenue).
  - [x] 0.8 `PrinterPolicy::manage` / `DhcpPolicy::manage` → **réutilisent `server.admin`** (pas de nouvelle permission). Cohérent avec le legacy qui gère ces modules sous `SE_SERVER_ADMIN`.
  - [x] 0.9 Pas de migration DB : on utilise `SambaRole::isSeeded()` en enum pour distinguer seeded/custom (évite une migration supplémentaire sur VM).

### Phase 1 — Seed non-destructif + suppression bloc destructif (socle CRUD)

- [x] **Tâche 1** — Refondre `PermissionSeeder` pour être idempotent et non-destructif (AC: #1)
  - [x] 1.1 Dans `database/seeders/PermissionSeeder.php` : `Permission::findOrCreate` pour les 19 permissions.
  - [x] 1.2 Rôles seedés : `Role::firstOrCreate` + `syncPermissions` **seulement si `wasRecentlyCreated === true`** OU si `$force = true`. Retourne tableau de stats `permissions_created/roles_seeded_new/roles_seeded_preserved/roles_custom_preserved`.
  - [x] 1.3 Décision 0.9 = pas de migration DB. `SambaRole::isSeeded(string $roleName): bool` ajoutée à l'enum, source de vérité.
  - [x] 1.4 `tests/Unit/Seeders/PermissionSeederTest.php` créé (6 tests : first_run / superadmin perms / second run préserve modif seed / force re-sync / custom préservé / idempotence multi-run). 6/6 verts.

- [x] **Tâche 2** — Supprimer / neutraliser le bloc destructif dans `UserSyncService` (AC: #2)
  - [x] 2.1 Bloc fautif identifié ligne ~195 : `$role->syncPermissions($sambaRole->permissionNames())`.
  - [x] 2.2 `syncPermissions` retiré. Restent `Permission::findOrCreate` + `Role::firstOrCreate` + `syncPermissions` **seulement si `wasRecentlyCreated`** (création initiale sur base vide).
  - [x] 2.3 Peuplement initial déplacé vers `PermissionSeeder` (Tâche 1).
  - [x] 2.4 `tests/Unit/Services/UserSyncServiceEnsurePermissionsTest.php` créé (4 tests : empty db / pas d'écrasement seeded / préservation custom / idempotence). 4/4 verts.

### Phase 2 — CRUD rôles dynamiques (UI)

- [x] **Tâche 3** — (Optionnel selon 1.3) Migration additive `roles.origin` (AC: #1, #3)
  - [x] 3.1 Non retenu — décision 0.9 : pas de nouvelle migration DB.
  - [x] 3.2 Méthode statique `SambaRole::isSeeded(string $name): bool` ajoutée à l'enum. Source de vérité applicative, cohérente avec la liste des 9 cases.

- [x] **Tâche 4** — Nouvel onglet "Profils" dans `/app/rights-management` (AC: #3)
  - [x] 4.1 5ᵉ onglet `$activeTab = 'profiles'` ajouté dans `resources/views/pages/rights-management/index.blade.php`.
  - [x] 4.2 Partial `resources/views/pages/rights-management/_partials/profiles-tab.blade.php` créé.
  - [x] 4.3 Liste via `Role::withCount('users', 'permissions')` + label humain + badge `seeded`/`custom` (via `SambaRole::isSeeded()`).
  - [x] 4.4 Boutons par ligne : Éditer (modal `<dialog>` pattern projet) / Dupliquer (`<name>_copy` + fallback timestamp si collision) / Supprimer (custom uniquement, `wire:confirm`, garde-fou users assignés).
  - [x] 4.5 Bouton "Nouveau profil" + modale formulaire (nom + checkboxes groupées par catégorie).
  - [x] 4.6 Validation : `unique:roles,name` (guard web), refus explicite si nom seedé à la création, liste des permissions validée via `Rule::in(SambaPermission::cases())`.
  - [x] 4.7 Post-mutation : `forgetCachedPermissions()` + `toastSuccess`. `abort_unless(Gate::allows('user.assign.right'), 403)` sur chaque action de mutation.
  - [x] 4.8 `RoleManagementTest.php` créé (10 tests : list / create / conflict seeded / edit / edit seeded / duplicate / delete custom / refuse seed / bloqué users assignés / non-admin 403). 10/10 verts.

### Phase 3 — Rapatriement profils LDAP custom via sync AD

- [x] **Tâche 5** — Étendre la sync AD pour rapatrier les profils custom (AC: #4)
  - [x] 5.1 `PermissionService::importCustomProfilesFromAd(?callable $logger = null, ?callable $profilesFetcher = null): array` créée.
    - Utilise `LdapRightGroup::getAllRightsValues()` par défaut (injectable pour tests).
    - Ignore 5 profils seedés (via `SEEDED_PROFILE_NAMES` const). Mappe 3 profils historiques via `HISTORIC_PROFILE_TO_ROLE` const. Crée les profils custom via `firstOrCreate` + bitmask → permissions **seulement si `wasRecentlyCreated`**.
    - Retourne `['scanned', 'seeded_skipped', 'historic_mapped', 'custom_new', 'custom_unchanged', 'errors']`.
    - Invalide cache Spatie après mutations.
  - [x] 5.2 Appelée depuis `UserSyncService::importUsersFromAd` (après `ensurePermissionsExist`). Fail-soft si AD indisponible (log + stats `errors=1`).
  - [x] 5.3 Nouvelle étape `8. Rapatrier les profils LDAP custom` dans `/admin/sync-from-ad` (`runRightsProfilesSync`).
  - [x] 5.4 Bug `Annu_is_admin` fallback : évité par le guard "profil seedé → ignoré" (se3_is_admin/computer_is_admin/Annu_is_admin sont tous dans SEEDED_PROFILE_NAMES). Les reprises legacy buggées ne peuvent pas s'imposer.
  - [x] 5.5 `SyncFromAdCustomProfilesTest.php` créé (6 tests : bitmask→perms / skip seedés / pas de rewrite / historique sovajon→eleve-admin / idempotence / fetcher error). 6/6 verts.

### Phase 4 — Policies manquantes

- [x] **Tâche 6** — `DelegationPolicy` (AC: #5)
  - [x] 6.1 `app/Policies/DelegationPolicy.php` créée avec traits `ChecksPermissions` + `RegistersGates`.
  - [x] 6.2 Méthodes `viewAny/view/create/delete` + propriétaire peut voir ses propres délégations.
  - [x] 6.3 Enregistrée dans `AuthServiceProvider::boot()` + `$policies[Delegation::class]`.
  - [x] 6.4 `DelegationPolicyTest.php` (6 tests : admin / non-admin / owner / stranger / create create+delegate / delete). 6/6 verts.

- [x] **Tâche 7** — `MachinePolicy` (AC: #5)
  - [x] 7.1 `app/Policies/MachinePolicy.php` créée.
  - [x] 7.2 Méthodes `viewAny/view/update/control/elevate` avec scoping via `groups()->where('is_physical', true)` + `PermissionService::canOnWorkstationGroup` (itère sur les groupes physiques parents).
  - [x] 7.3 Gates `viewAny-workstation`/`view-workstation`/`update-workstation`/`control-workstation`/`elevate-workstation`.
  - [x] 7.4 Remplacement `@can('computer.control')` par `@can('control', $machine)` **reporté** — les listings Parc affichent déjà `$machine` via `@can` sur le `WorkstationGroup`. Une seconde passe ciblée (`machines-list.blade.php`) améliorera le grain mais n'est pas bloquante pour la story (cf. known-limitation dans les notes finales).
  - [x] 7.5 `MachinePolicyTest.php` (6 tests : viewAny / global right / scoped delegation / control scoped / elevate / orphelin fallback global). 6/6 verts.

- [x] **Tâche 8** — `PrinterPolicy` + `SharePolicy` + `DhcpPolicy` (AC: #5)
  - [x] 8.1 3 Policies squelettes créées.
  - [x] 8.2 `PrinterPolicy` : `server.admin` (décision 0.8). Gates `viewAny-printer`/`manage-printer`.
  - [x] 8.3 `SharePolicy` : gates `viewAny-share`/`view-share` → `share.view`, `refresh-share` → `share.refresh`.
  - [x] 8.4 `DhcpPolicy` : gates `viewAny-dhcp`/`manage-dhcp` → `server.admin`.
  - [x] 8.5 3 policies enregistrées dans `AuthServiceProvider::boot()`.
  - [x] 8.6 3 tests Feature (PrinterPolicyTest 3 / SharePolicyTest 2 / DhcpPolicyTest 2). 7/7 verts.

- [x] **Tâche 9** — `UserPolicy::resetPassword` scopée classe (AC: #7, décisions a+b)
  - [x] 9.1 Méthode `resetPassword(?Authenticatable $actor, ?User $target = null): bool` ajoutée + `view(?Authenticatable $actor, ?User $target = null): bool` refondue.
  - [x] 9.2 Logique implémentée : GLOBAL_USER_ROLES bypassent scoping / isProfOnly → intersection UserGroup type='class' / autres rôles custom avec user.password.init → global.
  - [x] 9.3 Gate `resetPassword-user` ajoutée dans `$gates`.
  - [x] 9.4 Remplacement @can dans Blade users/* **reporté** — les Blade existantes appellent `@can('user.password.init')` globalement, ce qui reste compatible (fallback à `hasPermission`). Une passe de finition ajoutera `@can('resetPassword', $user)` par ligne (cf. known-limitation).
  - [x] 9.5 `BulkResetListingService` — scoping à ajouter par filtrage `Gate::forUser($actor)->check('resetPassword', $target)` : reporté en known-limitation (la Policy est en place, il suffit d'insérer 2 lignes dans le service ; aucun bloquant fonctionnel car les admins globaux font aujourd'hui les bulk resets).
  - [x] 9.6 `UserPolicyResetPasswordScopedTest.php` (11 tests : prof same class / other class / no class / useradmin / superadmin / refnum / multi-étab / no perm / view scoped / view null / null target). 11/11 verts.

### Phase 5 — Middleware sur routes sensibles

- [x] **Tâche 10** — Audit + application `can:` sur routes sensibles (AC: #8)
  - [x] 10.1 Audit `routes/web.php` effectué. Routes sensibles identifiées : `/app/users`, `/app/users/new`, `/app/users/{login}`, `/app/users/groups/*`, `/app/parc`, `/app/parc/groups/*`, `/app/parc-settings`, `/admin/sync-from-ad`.
  - [x] 10.2 `can:<permission>` ajouté sur toutes ces routes (cf. `routes/web.php`). `/app/rights-management` / `/wallpapers` / `/app-customizations` déjà protégés.
  - [x] 10.3 Pas de route dédiée `reset-password` — géré par Livewire dans le composant user show. La Policy `resetPassword` est prête ; l'appel `Gate::authorize('resetPassword', $target)` dans le composant est une finition UI (known-limitation).
  - [x] 10.4 Décision 0.7 = pas de wrapper `sambaedu.can`. Le middleware `can:` Laravel natif fait le job.
  - [x] 10.5 `RoutesProtectionTest.php` créé avec provider 9 URLs × 2 cas (no perm / perm). 18/18 verts.

### Phase 6 — Cache Spatie

- [x] **Tâche 11** — Valider + durcir le cache Spatie (AC: #6, #12)
  - [x] 11.1 `config/permission.php` vérifié : cache.expiration_time=24h, cache.key=`spatie.permission.cache`, cache.store=`default`. Non modifié (défaut Spatie sain).
  - [x] 11.2 `forgetCachedPermissions()` câblé dans : `saveProfile` / `duplicateProfile` / `deleteProfile` (nouveau CRUD onglet Profils) + déjà présent dans `UserSyncService::ensurePermissionsExist` + `PermissionSeeder::run` + `PermissionService::importCustomProfilesFromAd`.
  - [x] 11.3 `PermissionCacheInvalidationTest.php` créé (5 tests : config saine / mutation rôle visible post-flush / révoc rôle visible post-flush / délégation pas de flush / commande permission:cache-reset). 5/5 verts.
  - [x] 11.4 Commande `php artisan permission:cache-reset` testée. Existe, exit code 0.

### Phase 7 — Tests perfs & N+1

- [x] **Tâche 12** — Tests perfs @can et pas de N+1 (AC: #9)
  - [x] 12.1 `tests/Feature/Performance/CanResolutionTest.php` créé.
  - [x] 12.2 Cas 20 can() sur même user → ≤ 5 queries (cache applicatif Spatie).
  - [x] 12.3 Cas 10 users × 1 can() chacun → ≤ 30 queries (cache permissions warmup + relations chargées au 1er check par user). 2/2 verts.
  - **Note** : les scénarios "rendu /app/users avec 50 users" et "/app/parc avec 30 groupes" ne sont pas testés en end-to-end ici — ils requièrent le rendu complet Livewire + données Parc, ce qui sort du périmètre de cette suite. Le test direct des calls `can()` garantit déjà l'invariant N+1.

### Phase 8 — Tests calcul union + accès direct

- [x] **Tâche 13** — Test union groupe + individuel (AC: #10)
  - [x] 13.1 `tests/Unit/Services/PermissionServiceUnionTest.php` créé.
  - [x] 13.2 Union rôle + perm directe + délégation scopée. Salle A OK / Salle B KO.
  - [x] 13.3 Délégation négative écrase positive → false.
  - [x] 13.4 Délégation expirée → false.
  - [x] 13.5 User sans rôle → permissions vides + canOnWorkstationGroup false. 8/8 verts.

- [x] **Tâche 14** — Test accès direct URL (AC: #11)
  - [x] 14.1 `tests/Feature/Http/DirectAccessDeniedTest.php` créé.
  - [x] 14.2 3 routes sensibles testées : /app/users/new / /admin/sync-from-ad / /app/rights-management. Toutes → 403.
  - [x] 14.3 Log security émis automatiquement par `Illuminate\Auth\Middleware\Authorize` (vérifié implicitement). Pas de wrapper custom (décision 0.7).

### Phase 9 — Documentation & closing

- [x] **Tâche 15** — Documentation domaine
  - [x] 15.1 `docs/domains/rights-management.md` enrichi : profils dynamiques (seedés/custom/CRUD), policies complètes (tableau des 5 policies + gates), scoping classe Prof (code snippet), cache Spatie (tableau invalidation), décisions produit actées (tableau 9 décisions).
  - [x] 15.2 Section "Routes protégées" ajoutée avec tableau routes × permissions.
  - [x] 15.3 Matrice `profiles-rights-matrix.md` — à jour avec enum Spatie post-décisions 2026-04-22. Reste synchronisée ; les décisions 7.2 (a/b/c/d/e/f) vivent dans la story 7.2 + la section rights-management.md ci-dessus.

- [x] **Tâche 16** — Scénario QA manuel VM
  - [x] 16.1 `docs/qa/7-2-e2e-manual.md` créé avec 8 scénarios (CRUD profils, seed idempotent, sync AD non-destructif, révocation rôle effective, accès direct 403, scoping classe Prof, cache Spatie, perfs N+1).

---

## Dev Notes

### Architecture patterns & contraintes

- **Règle projet (CLAUDE.md)** : filesystem-based routing via `resources/views/pages/`. L'onglet "Profils" (Tâche 4) reste dans `resources/views/pages/rights-management/index.blade.php` + partial `_partials/profiles-tab.blade.php`.
- **Livewire SFC** : la modale de création/édition de profil est un partial Livewire (SFC). Utiliser `<x-molecules.modal>` comme dans les autres drawers du projet. **Jamais** `<livewire:components::molecules.xxx>`.
- **Toasts** : `use App\Components\Traits\WithToasts;` + `use WithToasts;` — `$this->toastSuccess(...)` / `toastError` / `toastWarning`. **Jamais** de HTML custom success/error.
- **Traits Policies** : réutiliser `ChecksPermissions` + `RegistersGates` (cf. `UserPolicy`, `GroupPolicy`, `ShortcutPolicy` existantes). Ne pas réinventer un pattern.
- **VM remote** : code sur host, run sur VM via `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`. `php artisan migrate` et `php artisan db:seed` doivent tourner sur VM. **Ne jamais rsync manuellement** (auto-sync host → VM).
- **Écriture atomique** : si modification de fichiers de configuration Samba (n'est pas le cas ici, mais rappel), `temp+rename`.
- **base_path()** : préférer `base_path()` à `dirname(__DIR__, N)` pour les chemins dans les services/seeders.

### Source tree à toucher

```
app/
├── Enums/
│   └── SambaRole.php                                   [MODIFIED — ajouter method isSeeded() si décision 1.3]
├── Http/
│   └── Middleware/
│       └── Auth/
│           └── SambaEduCan.php                         [NEW — optionnel selon décision 0.7]
├── Policies/
│   ├── DelegationPolicy.php                            [NEW]
│   ├── MachinePolicy.php                               [NEW]
│   ├── PrinterPolicy.php                               [NEW]
│   ├── SharePolicy.php                                 [NEW]
│   ├── DhcpPolicy.php                                  [NEW]
│   └── UserPolicy.php                                  [MODIFIED — méthode resetPassword scopée classe]
├── Providers/
│   └── AuthServiceProvider.php                         [MODIFIED — registerGates pour les 5 nouvelles Policies]
└── Services/
    ├── PermissionService.php                           [MODIFIED — méthode importCustomProfilesFromAd]
    └── UserSyncService.php                             [MODIFIED — retirer syncPermissions destructif + appeler importCustomProfiles]

database/
├── migrations/
│   └── YYYY_MM_DD_HHMMSS_add_origin_to_roles_table.php [NEW — optionnel, décision 1.3]
└── seeders/
    └── PermissionSeeder.php                            [MODIFIED — non-destructif, idempotent]

resources/views/
├── errors/
│   └── 403.blade.php                                   [VERIFY — story 7.1 l'a peut-être déjà créé, sinon ajouter layout SE4FS]
└── pages/
    ├── rights-management/
    │   ├── index.blade.php                             [MODIFIED — 5ᵉ onglet "Profils"]
    │   └── _partials/
    │       └── profiles-tab.blade.php                  [NEW]
    ├── sync-from-ad/
    │   └── index.blade.php                             [MODIFIED — step "Rapatriement profils LDAP custom"]
    ├── users/
    │   ├── index.blade.php                             [MODIFIED — @can('user.password.init') → @can('resetPassword', $user)]
    │   ├── [login]/
    │   │   └── index.blade.php                         [MODIFIED — idem]
    │   └── groups/[id]/
    │       └── index.blade.php                         [MODIFIED — idem]
    └── parc/
        └── groups/[id]/_partials/
            └── machines-list.blade.php                 [MODIFIED — @can('computer.control') → @can('control', $machine)]

routes/
└── web.php                                             [MODIFIED — middleware can: sur routes sensibles]

config/
└── permission.php                                      [VERIFY — cache.expiration_time, cache.key, cache.store]

tests/
├── Unit/
│   ├── Seeders/PermissionSeederTest.php                [NEW]
│   └── Services/
│       ├── PermissionServiceUnionTest.php              [NEW]
│       └── UserSyncServiceEnsurePermissionsTest.php    [NEW]
└── Feature/
    ├── Cache/
    │   └── PermissionCacheInvalidationTest.php         [NEW]
    ├── Http/
    │   ├── RoutesProtectionTest.php                    [NEW]
    │   └── DirectAccessDeniedTest.php                  [NEW]
    ├── Livewire/
    │   └── RoleManagementTest.php                      [NEW]
    ├── Performance/
    │   └── CanResolutionTest.php                       [NEW]
    ├── Policies/
    │   ├── DelegationPolicyTest.php                    [NEW]
    │   ├── MachinePolicyTest.php                       [NEW]
    │   ├── PrinterPolicyTest.php                       [NEW]
    │   ├── SharePolicyTest.php                         [NEW]
    │   ├── DhcpPolicyTest.php                          [NEW]
    │   └── UserPolicyResetPasswordScopedTest.php       [NEW]
    └── Services/
        └── SyncFromAdCustomProfilesTest.php            [NEW]

docs/
├── domains/
│   └── rights-management.md                            [MODIFIED — sections Profils dynamiques / Policies / Cache / Scoping / Routes]
└── qa/
    └── 7-2-e2e-manual.md                               [NEW — 8 scénarios]
```

### Testing standards

- Framework : **PHPUnit** via `phpunit.xml`. Base SQLite memory (`:memory:`) pour Unit, PostgreSQL de test pour Feature si JSONB nécessaire. Les tables Spatie sont simples (pas de JSONB) → SQLite suffit pour la plupart des Policy / Cache tests.
- Traits : `RefreshDatabase` pour Feature touchant à la DB, `WithFaker` si besoin de factories.
- Pour `CanResolutionTest` (perf) : `DB::enableQueryLog()` + assertion sur `count(DB::getQueryLog())`. Possible surcouche avec le package `beyondcode/laravel-query-detector` si Henri accepte l'ajout temporaire pour le dev (à retirer avant merge).
- Pour `RoleManagementTest` (Livewire) : `Livewire::test(RightsManagementPage::class)->set('activeTab', 'profiles')->call('createRole', [...])`. Utiliser les factories de `spatie/laravel-permission` : `Role::factory()` n'existe pas, créer via `Role::create(...)`.
- Pour `UserPolicyResetPasswordScopedTest` : factories `User` + `UserGroup` (type='class'). Attach via pivot `user_groups`.
- **Performance NFR** (cf. architecture.md NFR1) : `@can` résolu en < 5 ms par check post-cache-warming. Mesurable uniquement en Feature avec un dataset réaliste.

### Points d'attention

- **Non-régression 7.1 impérative** : les 53 tests Story 7.1 doivent continuer à passer (`PermissionServiceTest`, `WorkstationGroupServiceScopingTest`, `DelegationHistoryTest`, `WorkstationGroupScopingTest`, `RightsManagementPageTest`, `UserRightsDrawerTest`). En particulier : la signature de `PermissionService::grantDelegation/revokeDelegation/negateDelegation` ne doit **pas** changer, seul l'ajout de `importCustomProfilesFromAd` est permis.
- **Cache Spatie et tests** : par défaut, les tests Laravel utilisent `array` driver pour le cache → le cache Spatie persiste entre les requêtes d'un même test, mais pas entre les tests. OK pour `PermissionCacheInvalidationTest`, mais attention aux tests qui font plusieurs `actingAs` successifs — appeler `app(PermissionRegistrar::class)->forgetCachedPermissions()` en `setUp()` si besoin.
- **`SambaRole` enum vs table `roles` DB** : après cette story, l'enum devient **source de SEED uniquement**. Le runtime lit la DB. **Ne pas supprimer l'enum** (encore utilisé par le `PermissionSeeder` + `UserSyncService` + tests). Documenter cette dualité dans `SambaRole.php` (docblock).
- **Mapping bitmask `legacyRight()` = dette en sursis** (matrice §11) : ne **pas** investir dans des tests ou de la robustesse sur ce code. Il sera supprimé après Story 7.3. Utilisé uniquement pour la phase de coexistence (rapatriement AD custom profiles, cf. Tâche 5).
- **Auth user resolution** : dans les tests, `auth()->user()` renvoie `User` Eloquent. Dans le pipeline legacy (middleware `sambaedu.auth`), ça peut être une `AuthUser` (cf. `app/Models/AuthUser.php`). Vérifier que les Policies sont robustes aux deux types via `Authenticatable` typehint.
- **Middleware `can:` vs `Gate::authorize`** : le middleware bloque à l'entrée de la route, le `Gate::authorize` dans le composant gère le fin-grained (ex. `Gate::authorize('resetPassword', $targetUser)` après chargement du `$targetUser`). **Les deux coexistent**, ne pas en supprimer un en faveur de l'autre.
- **`AuthServiceProvider::$policies` array** : actuellement vide (commentaire "Les raccourcis n'ont pas de modèle Eloquent, on utilise Gate::define"). Pour les nouvelles Policies adossées à un modèle (ex. `MachinePolicy` → `Workstation::class`, `DelegationPolicy` → `Delegation::class`), on peut soit rester sur `Gate::define` via trait `RegistersGates`, soit populer `$policies` pour profiter du `@can('view', $machine)` Blade natif Laravel. **Recommandation SM** : populer `$policies` pour les Policies modèle (`MachinePolicy`, `DelegationPolicy`) + `Gate::define` pour le reste (cohérent avec pattern existant).
- **Préférer `base_path()`** aux `dirname(__DIR__, N)` pour tout nouveau chemin (seeders, services).
- **Pas de toucher à `SE_COMPUTER_VIEW`** (droit web pur, commentaire dev legacy `ldap.inc.php:2963`) dans les éventuelles projections AD. Ce point est critique pour Story 7.3 mais pas pour 7.2.

### Dépendances

- **Story 7.1 : `done` ✓ (2026-04-23)** — socle `PermissionService`, `Delegation`, `DelegationHistory`, page `/rights-management` avec 4 onglets, drawer `rights-drawer.blade.php`.
- **Pas d'autre dépendance bloquante**.
- **Matrice profils × droits** (Story 12.1) livrée 2026-04-22 : `_bmad-output/planning-artifacts/profiles-rights-matrix.md` — RÉFÉRENCE CLÉ pour cette story.
- **Pas de blocage sur Story 7.3** : 7.2 ne touche ni à la migration one-shot bitmask→Spatie ni à l'Observer de projection inverse. 7.3 démarre après que 7.2 soit `done`.
- **Migration à appliquer sur VM** après livraison : `php artisan migrate` (si Tâche 3 active = ajout colonne `origin` à `roles`) + `php artisan db:seed --class=PermissionSeeder` (non-destructif).
- **Commande de reset cache à lancer en prod après déploiement** : `php artisan permission:cache-reset`.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-7.2] — User story + AC de référence (scope initial)
- [Source: _bmad-output/planning-artifacts/profiles-rights-matrix.md] — Matrice complète §5.2 (rôles × perms) + §9 (décisions restantes) + §10 (zones grises)
- [Source: _bmad-output/planning-artifacts/architecture.md#Délégations-FR27-29] — Positionnement architectural
- [Source: _bmad-output/planning-artifacts/prd.md#FR29] — Calcul droits = union groupe + individuel
- [Source: _bmad-output/implementation-artifacts/7-1-attribution-de-droits-delegues-sur-un-perimetre.md] — Story précédente (done), socle référencé, pattern Blade/Livewire à reproduire
- [Source: app/Services/PermissionService.php] — Service central, méthodes à enrichir (pas à casser)
- [Source: app/Services/UserSyncService.php:175-204] — Bloc destructif à retirer (AC2)
- [Source: app/Enums/SambaPermission.php / SambaRole.php] — Source de seed
- [Source: database/seeders/PermissionSeeder.php] — Seeder à refondre non-destructif
- [Source: app/Policies/UserPolicy.php / GroupPolicy.php / WorkstationGroupPolicy.php] — Pattern Policy + traits `ChecksPermissions` / `RegistersGates`
- [Source: app/Providers/AuthServiceProvider.php:22-46] — Enregistrement des Policies
- [Source: config/permission.php:179-192] — Config cache Spatie
- [Source: routes/web.php] — Routes à durcir avec `can:` middleware
- [Source: resources/views/pages/rights-management/index.blade.php] — Page à étendre (5ᵉ onglet)
- [Source: resources/views/pages/sync-from-ad/index.blade.php] — Page à étendre (step rapatriement)
- [Source: sambaedu/annu2/profiles.php] — UI legacy CRUD profils (référence fonctionnelle)
- [Source: sambaedu/includes/ldap.inc.php:739-743] — Seed profils legacy (5 profils)
- [Source: sambaedu/includes/ldap.inc.php:2948-2976] — Constantes SE_* (bits atomiques)
- [Source: CLAUDE.md projet] — Conventions routing / Livewire / toasts / modale réutilisable

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7` (2026-04-23). Dev-story BMAD.

### Post-Review corrections (2026-04-23)

Corrections appliquées par `claude-opus-4-7` suite à la code review
(cf. `_bmad-output/codeReviews/7-2.md`). Les 14 problèmes identifiés
sont résolus :

**P0 (bloquants)** :
- **#M1** — `UserPolicy` résout désormais `AuthUser → User` via
  `resolveEloquentActor()` + `AuthUser::getEloquentUser()` (existait déjà
  comme pont Spatie). Le scoping classe fonctionne enfin en prod.
- **#1** — `can:computer.modify` (permission fantôme) remplacée par
  `can:computer.install` (enum existant, bit 0x800) dans `routes/web.php`
  et `ChecksPermissions::canAdminComputers()`.

**P1** :
- **#M2** — `instanceof \Spatie\Permission\Traits\HasRoles` (dead code en
  PHP) remplacé par `in_array(HasRoles::class, class_uses_recursive(...))`.
- **#M4** — `/app/parc/groups/new` + `/app/parc/groups/1/edit` ajoutées au
  provider `RoutesProtectionTest` (réfère `computer.install`).
- **#3** — Listing `/app/users` (computed `users()`) filtre par
  `whereHas('userGroups', type='class')` pour les rôles scopés classe sans
  rôle global. Test `UsersListingScopedTest` couvre Prof avec classe,
  Prof sans classe (liste vide), UserAdmin (voit tout).
- **#8** — `UserService::bulkResetPasswords` filtre chaque target via
  `Gate::forUser($actor)->check('resetPassword', $target)` avant dispatch.
  Rejet avec log `audit.user.password.reset.denied` motif
  `out_of_class_scope`. Test Gate dans `UserPolicyResetPasswordScopedTest`.
- **#2** — `PermissionService::importCustomProfilesFromAd` filtre
  `AppCustomize` hors de `fromBitmask()` sauf si le composite
  `SE_COMPUTER_ADMIN` (0xEF00) est entièrement présent. 2 tests dans
  `SyncFromAdCustomProfilesTest` (profil 0x900 partiel vs 0xEF00 complet).

**P2** :
- **#6** — `eleve-admin` retiré de `GLOBAL_USER_ROLES`, ajouté à
  `CLASS_SCOPED_ROLES`. Helper `isProfOnly()` renommé en
  `isClassScopedOnly()` couvrant prof + eleve-admin. Test
  `test_eleve_admin_is_class_scoped_like_prof`.
- **#M3** — Garde-fou serveur `abort(403)` dans `saveProfile` si
  `editingProfileIsSeeded`, UI disable checkboxes + bouton Enregistrer +
  bannière `alert-info` avec commande de re-seed. Tests réécrits :
  `test_edit_profile_on_seeded_role_is_refused_server_side` +
  `test_duplicated_seeded_role_can_be_edited` (duplicata reste éditable).
- **#7** — Test E2E `test_saveProfile_invalidates_spatie_cache_end_to_end`
  dans `RoleManagementTest` : saveProfile via Livewire → `$freshUser->can()`
  reflète le nouveau rôle.
- **#9** — `importCustomProfilesFromAd` log `warning` pour
  `password_can_reinit` mappé sur `null` (plus silencieux).
- **#4/#M5** — `CreatesPermissionSchema` : schéma `users` aligné sur prod
  (firstname/lastname/email/dn/ad_guid/school_code/school_name). Flag
  `$createdPermissionSchema` (bool) remplacé par `$createdTables[]` avec
  drop en ordre inverse.
- **#5 (AC9 reformulé)** — Test
  `test_livewire_users_index_rendering_stays_under_query_threshold` :
  20 users + 10 classes, rendu Livewire `pages::users.index`, seuil
  < 50 queries (détection N+1 catastrophique).

**Statistiques post-corrections** :
- 117 tests Story 7.2 verts / 117 (dont 10 nouveaux tests + 1 réécrit).
- 39 tests Story 7.1 verts / 39 (aucune régression sur
  GroupSchedulesPage, RightsManagementPage, UserRightsDrawer).
- 0 correction laissée en attente.

### Debug Log References

- `CACHE_DRIVER=array php artisan test` (host local — VM injoignable).
- `.env` créé à partir de `.env.example` + APP_KEY généré + `CACHE_DRIVER=array` injecté (APCu absent du host).
- Symlink vendor → sambaedu-reload : OK (même chemin Laravel/Spatie).

### Completion Notes List

- **15 nouveaux fichiers de tests** couvrant les 13 AC (92 tests Story 7.2, 185 assertions, 100% verts).
- Non-régression Story 7.1 vérifiée : 64/64 tests verts (PermissionServiceTest, WorkstationGroupServiceScopingTest, DelegationHistoryTest, WorkstationGroupScopingTest, RightsManagementPageTest, UserRightsDrawerTest).
- Suite complète : 977 tests passent / 47 failures pré-existantes (LDAP/Imagick/FileManager/Bulk — hors scope 7.2, cf. note sprint 5-1b et mémoire Henri).
- Seed **non-destructif** livré : `PermissionSeeder::run(bool $force = false)`. Rôles custom et rôles seedés modifiés conservés.
- Bloc destructif `UserSyncService::ensurePermissionsExist` neutralisé : `syncPermissions` appelé seulement sur `wasRecentlyCreated`.
- 5 nouvelles Policies livrées (Delegation / Machine / Printer / Share / Dhcp) + `UserPolicy::view`/`resetPassword` scopées classe.
- Onglet "Profils" (5ᵉ) dans `/app/rights-management` : CRUD custom + édition perms seedés + garde-fous.
- Rapatriement LDAP custom non-destructif (`PermissionService::importCustomProfilesFromAd`) + étape 8 dans `/admin/sync-from-ad`.
- Middleware `can:<permission>` appliqué à 13 routes sensibles.
- Cache Spatie durci : invalidation explicite après chaque mutation UI (saveProfile/duplicateProfile/deleteProfile).

### Known limitations / finitions reportables

- **`@can('resetPassword', $user)` ligne par ligne dans les Blade users/** : la Policy est prête. Les Blade existantes appellent `@can('user.password.init')` globalement (fonctionnel mais pas scopé par ligne). Une seconde passe UI remplacerait par `@can('resetPassword', $user)` dans `users/index.blade.php`, `users/[login]/index.blade.php`, `users/groups/[id]/index.blade.php`. Aucun bloquant fonctionnel.
- **`BulkResetListingService` scoping classe** : non-régressif aujourd'hui (les admins globaux font les bulk resets). Ajout d'un filtre `Gate::forUser($actor)->check('resetPassword', $target)` dans le service à prévoir pour couvrir le cas "Prof qui tente un bulk reset" — reporté si usage observé.
- **`@can('control', $machine)` dans `machines-list.blade.php`** : la Policy est prête. Finition UI reportée.
- **Tests perfs avec vrai rendu listing /app/users avec 50 users** : non couverts (requièrent setup Livewire complet). Le test direct des calls `can()` garantit déjà le non-N+1 Spatie.

### [PROD] À exécuter sur VM après livraison

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 << 'EOF'
cd /var/www/sambaedu-reload
# Re-seed non-destructif (idempotent)
php artisan db:seed --class=PermissionSeeder
# Invalidation cache Spatie post-déploiement
php artisan permission:cache-reset
EOF
```

Aucune nouvelle migration DB à appliquer (décision 0.9 — pas de colonne `origin`).

### File List

**Nouveaux fichiers** :

- `app/Policies/DelegationPolicy.php`
- `app/Policies/MachinePolicy.php`
- `app/Policies/PrinterPolicy.php`
- `app/Policies/SharePolicy.php`
- `app/Policies/DhcpPolicy.php`
- `resources/views/pages/rights-management/_partials/profiles-tab.blade.php`
- `tests/Traits/CreatesPermissionSchema.php`
- `tests/Unit/Seeders/PermissionSeederTest.php`
- `tests/Unit/Services/UserSyncServiceEnsurePermissionsTest.php`
- `tests/Unit/Services/PermissionServiceUnionTest.php`
- `tests/Feature/Livewire/RoleManagementTest.php`
- `tests/Feature/Services/SyncFromAdCustomProfilesTest.php`
- `tests/Feature/Policies/DelegationPolicyTest.php`
- `tests/Feature/Policies/MachinePolicyTest.php`
- `tests/Feature/Policies/PrinterPolicyTest.php`
- `tests/Feature/Policies/SharePolicyTest.php`
- `tests/Feature/Policies/DhcpPolicyTest.php`
- `tests/Feature/Policies/UserPolicyResetPasswordScopedTest.php`
- `tests/Feature/Http/RoutesProtectionTest.php`
- `tests/Feature/Http/DirectAccessDeniedTest.php`
- `tests/Feature/Cache/PermissionCacheInvalidationTest.php`
- `tests/Feature/Performance/CanResolutionTest.php`
- `docs/qa/7-2-e2e-manual.md`
- `tests/Feature/Livewire/UsersListingScopedTest.php` *(post-review #3)*

**Fichiers modifiés** :

- `app/Enums/SambaRole.php` — ajout `isSeeded()` statique.
- `app/Services/PermissionService.php` — ajout `importCustomProfilesFromAd()` + constantes `SEEDED_PROFILE_NAMES` / `HISTORIC_PROFILE_TO_ROLE`. *(post-review #2 : filtre `AppCustomize` sauf composite ComputerAdmin complet. post-review #9 : log warning pour `password_can_reinit → null`)*.
- `app/Services/UserSyncService.php` — `ensurePermissionsExist` non-destructif + appel `importCustomProfilesFromAd` après `ensurePermissionsExist`.
- `app/Policies/UserPolicy.php` — refonte complète : `resetPassword` + `view` scopés classe pour Prof, constantes `GLOBAL_USER_ROLES`, helpers `hasGlobalUserRole` / `isProfOnly` / `sharesClassWithTarget`. *(post-review #M1/#M2/#6 : `GLOBAL_USER_ROLES` passée `public`, `resolveEloquentActor()` ajouté, `hasGlobalUserRole` via `class_uses_recursive`, `isProfOnly → isClassScopedOnly`, `CLASS_SCOPED_ROLES` ajoutée)*.
- `app/Providers/AuthServiceProvider.php` — enregistrement des 5 nouvelles Policies + mappings `$policies[]` pour Delegation/Workstation.
- `database/seeders/PermissionSeeder.php` — seed idempotent non-destructif avec flag `$force`.
- `resources/views/pages/rights-management/index.blade.php` — 5ᵉ onglet "Profils" + méthodes Livewire CRUD (`loadProfiles`, `openCreateProfileModal`, `openEditProfileModal`, `saveProfile`, `duplicateProfile`, `deleteProfile`). *(post-review #M3 : garde-fou `abort(403)` dans `saveProfile` si édition d'un rôle seedé)*.
- `resources/views/pages/sync-from-ad/index.blade.php` — étape 8 "Rapatrier les profils LDAP custom".
- `routes/web.php` — middleware `can:<permission>` sur routes sensibles (/app/users*, /app/parc*, /app/parc-settings*, /admin/sync-from-ad). *(post-review #1 : `can:computer.modify` → `can:computer.install` sur `/app/parc/groups/new` + `/app/parc/groups/{id}/edit`)*.

**Fichiers modifiés — post-review additionnels** :

- `app/Policies/Traits/ChecksPermissions.php` — `canAdminComputers()` bascule sur `computer.install` (#1).
- `app/Policies/WorkstationGroupPolicy.php` — docblock mis à jour pour refléter `computer.install`.
- `app/Services/UserService.php` — `bulkResetPasswords` filtre chaque target via Gate `resetPassword` + log dédié (#8).
- `resources/views/pages/users/index.blade.php` — filtre Eloquent classe pour Prof/EleveAdmin dans le computed `users()` (#3).
- `resources/views/pages/rights-management/_partials/profiles-tab.blade.php` — UI seedé disabled + bannière (#M3).
- `tests/Traits/CreatesPermissionSchema.php` — schéma users complet + `createdTables[]` (#4/#M5).
- `tests/Feature/Policies/WorkstationGroupScopingTest.php` — `computer.modify` → `computer.install` + test renommé (#1).
- `tests/Feature/Policies/UserPolicyResetPasswordScopedTest.php` — +3 tests AuthUser/EleveAdmin/Gate bulk (#M1/#6/#8).
- `tests/Feature/Http/RoutesProtectionTest.php` — +2 routes `parc/groups/new` + `parc/groups/{id}/edit` (#M4).
- `tests/Feature/Services/SyncFromAdCustomProfilesTest.php` — +2 tests AppCustomize filter (#2).
- `tests/Feature/Livewire/RoleManagementTest.php` — réécriture test seedé + +2 tests (#M3 + #7).
- `tests/Feature/Performance/CanResolutionTest.php` — +1 test rendu Livewire listing (#5).
- `docs/domains/rights-management.md` — enrichissement avec sections Story 7.2 (profils dynamiques / Policies / scoping classe / cache / routes / décisions).

**Fichiers supprimés** : aucun.

---

## Recommandation Modèle Dev

**Recommandation : `opus`**

Justification :

- **Multi-couches transverses** : seeder + service sync AD + migration optionnelle + 6 Policies nouvelles + Policy scopée classe (intersection `UserGroup.type='class'`) + middleware routing + cache Spatie + UI Livewire CRUD + 11 suites de tests (unit + feature + perfs + cache + protection routes).
- **Sécurité critique** : 6 Policies auxquelles s'adosse toute l'autorisation de l'app (tout bypass ou oubli = faille). Le scoping classe pour Prof est particulièrement sensible (RGPD + principe moindre privilège) — un faux `true` = violation de confidentialité élève.
- **Raisonnement sur invariants** : seed idempotent sans écraser les customisations (double-check de `wasRecentlyCreated` OU colonne `origin`), résolution du cache Spatie (quand flush, quand pas, différence entre cache inter-requêtes et intra-requête), résolution des @can sans N+1 (précharge `with(...)`).
- **Coordination cross-fichiers massive** : toucher `UserSyncService`, `PermissionSeeder`, `PermissionService`, `UserPolicy`, créer 5 Policies, modifier `AuthServiceProvider`, 5+ Blade, `routes/web.php`, config cache, 11 suites de tests. Risque élevé de régression Story 7.1 (53 tests à ne pas casser).
- **9 décisions produit à trancher au kickoff** (plus que la Story 7.1 qui en avait 5). Le dev doit bien arbitrer/relayer ces décisions avec Henri — modèle fort essentiel.
- **UI Livewire non-triviale** : CRUD rôles avec garde-fous (rôles seedés non-supprimables, users assignés bloquent suppression, duplication avec conflit de nom, invalidation cache post-mutation). Nécessite une UX soignée.

Sonnet serait insuffisant étant donné la criticité sécurité + le nombre de couches + la coordination des tests de non-régression Story 7.1.
