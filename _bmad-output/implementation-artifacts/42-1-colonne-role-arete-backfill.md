# Story 42.1 : Colonne `role` sur l'arête + backfill (absorption `is_head_teacher`)

Status: review

> **Type** : migration de schéma (1 colonne pivot) + data-migration backfill + `withPivot` + bascule des consommateurs de lecture. **AUCUNE écriture AD.** Pas d'UI nouvelle (l'UI d'édition du rôle = 42.3).
>
> **Origine** : Epic 42 — Socle rôle sur l'arête user↔groupe (`_bmad-output/planning-artifacts/epics-socle-role-groupes.md`, décision Henri 2026-07-07, `docs/group-model-multivertical-orientation.md` statut DÉCIDÉ). **1ʳᵉ des 4 stories** de l'epic ; **BLOQUANTE pour 42.2/42.3/42.4**.
>
> **Direction** : le rôle d'un user DANS un groupe devient un attribut d'arête `role` (string) sur le pivot `user_group_user`, vocabulaire borné applicativement `member|manager|owner` (élève/prof/prof principal — pas d'enum SQL). `role` **absorbe** l'attribut d'arête `is_head_teacher` (4.14) : `owner` ⇔ ex-`is_head_teacher=true`. `User.role` **global est CONSERVÉ** (NFR-S3 : comportements type OU — création de home, droits UI, policies).
>
> **Mémoire projet liée** : `project_group_model_multivertical_direction.md` (direction), `project_pivot_global_memberships.md` (invariants pivot), `project_isprof_iseleve_ldap_first_cost.md` (JAMAIS de round-trip LDAP par user sur listes/backfill), `project_sqlite_tests_no_varchar_enforcement.md` (validation applicative), `project_vm_migrations_not_auto_applied.md` (migrate manuel /vm), `project_equipe_group_never_populated_se5.md` (contexte 4.12).

---

## Story

En tant que **développeur SE5 (et, en aval, responsable d'établissement)**,
je veux **que chaque arête `user_group_user` porte un rôle `member|manager|owner`, backfillé depuis l'existant (`is_head_teacher` + `users.role`), déclaré `withPivot` partout où l'arête est synchronisée, avec un défaut au rattachement dérivé du rôle global**,
afin que la source de vérité du rôle passe de l'heuristique globale (`User::isProf()`) et du flag mono-usage (`is_head_teacher`) à **la relation elle-même** — fondation de la projection AD par arêtes (42.2), de l'UI rôle (42.3), du read-back import (42.4) et, à terme, de la généricité profils/zones/matrice.

---

## Périmètre STRICT — et l'EXCEPTION CRITIQUE

**Dans le scope** : colonne + index, backfill idempotent, `withPivot('role')`, validation applicative du vocabulaire, défaut au rattachement, écriture **miroir** de `role` sur les points qui posent `is_head_teacher`, bascule des consommateurs de **lecture** sur `role === 'owner'`.

**EXCEPTION CRITIQUE (à respecter à la lettre)** : la projection AD 4.15 (`syncRoleAwareAdGroupMembers` + toute sa chaîne d'alimentation `$headTeacherUserIds` / `head_teacher_ids`) **ne bascule PAS** dans cette story — c'est 42.2 (bascule atomique). En conséquence :

- la colonne `is_head_teacher` reste **ÉCRITE EN MIROIR** de `role` sur tous les points d'écriture (invariant : `role === 'owner'` ⇔ `is_head_teacher === true`, à tout instant, sur toute arête) ;
- sa **suppression** (colonne + miroir + chaîne `head_teacher_ids`) est une **tâche de 42.2** ;
- **jamais de fenêtre où la projection lit une colonne morte** : tant que `syncRoleAwareAdGroupMembers` lit `is_head_teacher` (via `updateGroup` l.179-185), cette colonne reste alimentée et cohérente.

**HORS SCOPE** (stories aval) : routage AD par rôle d'arête (42.2), colonne « Rôle » éditable en UI (42.3), read-back des rôles depuis les groupes AD legacy au sync-from-ad avec précédence/données sales (42.4), suppression de `is_head_teacher` (42.2), tables profils/zones/matrice, role-groups `grp__`, toute modification de `ShareService`/`AclService` (FR-S6).

---

## Critères d'acceptation

1. **Migration schéma — colonne `role`** : après `up()`, `user_group_user.role` existe — `string` (longueur 20), non nullable, **défaut `'member'`**, **index**. Garde `Schema::hasColumn` sur `up()` ET `down()`. `down()` supprime la colonne proprement (l'index part avec). PK composite `(user_group_id, user_id)` et absence de timestamps **inchangées**. `php artisan migrate` full SQLite passe ; les arêtes préexistantes valent `'member'` avant backfill (rétro-remplissage du défaut).
2. **Backfill déterministe et idempotent** : à l'issue de la migration (via une action invocable, cf. T1), chaque arête vaut : `'owner'` si `is_head_teacher=true` ; sinon `'manager'` si le user a `users.role='prof'` (**lecture SQL pure — zéro appel LDAP/`isProf()`**, `project_isprof_iseleve_ldap_first_cost`) ; sinon `'member'`. Précédence `owner > manager > member`. Rejouer l'action = **même état final**, aucune exception, cross-driver SQLite (tests) / PG (prod — booléens via query builder, pas de SQL brut `= true`).
3. **`withPivot('role')` partout où l'arête est synchronisée** : déclaré sur **les 3 relations** — `UserGroup::users()` (app/Models/UserGroup.php:65-75), `User::groups()` (app/Models/User.php:183-191), `User::userGroups()` (app/Models/User.php:113-121). Test explicite du piège 4.14 : un `sync([$id => ['role' => 'manager']])` persiste bien l'attribut (sans `withPivot`, Laravel l'ignore SILENCIEUSEMENT). `->withPivot('is_head_teacher')` **conservé** sur `UserGroup::users()` (miroir jusqu'à 42.2).
4. **Vocabulaire borné applicativement** (SQLite ne borne pas les varchar — NFR-S4) : constantes `ROLE_MEMBER='member'`, `ROLE_MANAGER='manager'`, `ROLE_OWNER='owner'` + liste `ROLES` sur `UserGroupUserPivot`, et garde `assertValidRole(string $role)` qui lève `InvalidArgumentException` hors vocabulaire. La garde est appelée par tout chemin applicatif qui reçoit une valeur de rôle non constante (backfill, helper de dérivation) ; test prouvant le rejet d'une valeur arbitraire.
5. **Défaut au rattachement dérivé de `User.role` global** : helper pur (ex. `UserGroupUserPivot::defaultRoleForGlobalRole(?string $globalRole): string` — `'prof'`→`ROLE_MANAGER`, sinon `ROLE_MEMBER` ; jamais `owner` par défaut). `UserService::persistUserGroupsToSql` (app/Services/UserService.php:1405-1508) crée ses **nouvelles** arêtes avec ce rôle dérivé (lecture de `$sqlUser->role` — colonne SQL déjà en mémoire, pas `isProf()`), sur les DEUX chemins d'attach (non-classes l.1428, classes l.1486). **Une arête existante n'est JAMAIS réécrite par ce chemin** (piège : `syncWithoutDetaching([$id => attrs])` UPDATE les arêtes existantes — ne passer les attributs que pour les ids réellement nouveaux). Test : un re-import d'un user dont l'arête vaut `owner` ne la rétrograde pas. Le défaut DB `'member'` reste le filet pour tout attach hors chemins instrumentés.
6. **Miroir `is_head_teacher` ⇔ `role`** : en sortie de TOUT point d'écriture touché, l'invariant `(role === 'owner') ⇔ (is_head_teacher === true)` tient : (a) read-back import `projectFoldedGroup` (AC7) ; (b) `MergeLegacyUserGroups` — les 3 écritures pivot (insertOrIgnore l.171-183, flag fusion l.190-193, flag `PP_` isolé l.354-357) posent aussi `role`, **gardées par `Schema::hasColumn('user_group_user', 'role')`** (l'action est appelée par la migration 2026_06_25, ANTÉRIEURE à la colonne — sans garde, un `migrate` fresh casserait) ; (c) backfill (AC2). Test d'invariant après chacun de ces flux.
7. **Read-back import écrit `role` en miroir** : la boucle de fold (`projectFoldedGroup`, payload `sync()` associatif l.604-609) pose désormais `['is_head_teacher' => $isPP, 'role' => $isPP ? 'owner' : dérivé]` où le dérivé vient de `users.role` SQL résolu **en une seule requête** pour l'union des membres (pas de LDAP par membre). Sur AD `Classe_3A={alice(eleve)}`, `Equipe_3A={bob(prof)}`, `PP_3A={bob}` : `(3A,bob)` = `owner` + `is_head_teacher=true` ; `(3A,alice)` = `member` + `false`. Un prof membre non-PP = `manager`. 2ᵉ `syncFromAd` = no-op (idempotence). Union/dédup/détache 4.13 **intactes**. NOTE : ce n'est PAS le read-back 42.4 (qui dérivera `manager` de l'appartenance AD `Equipe_` avec précédence et données sales) — ici la dérivation reste heuristique (`users.role` + CN `PP_`), et l'import RESTE autoritaire sur `role` (AD-first transitoire, `project_sync_from_ad_transitional`) ; limite documentée, levée en 42.4.
8. **Consommateurs de LECTURE basculés sur `role === 'owner'`** : (a) fiche groupe `members()` (resources/views/pages/users/groups/[id]/index.blade.php:76-79) — la clé de view-model `'is_head_teacher'` est **conservée** (ne PAS la renommer : `$member['role']` dans ce view-model = rôle GLOBAL `User.role`, collision de nom à ne pas introduire — l'UI rôle d'arête = 42.3) ; (b) `head-teacher-section.blade.php` — `refreshState()` l.122-126 et la vérification de convergence post-save l.207-216 (`wherePivot('is_head_teacher', true)` → `wherePivot('role', 'owner')`). **NON basculés** (exception critique) : `syncRoleAwareAdGroupMembers` (app/Services/UserGroupService.php:941-1002), la dérivation `$headTeacherUserIds` de `updateGroup` (l.179-185) et tout le payload `head_teacher_ids` — intacts jusqu'à 42.2. `UserPolicy` : **AUCUN changement** (vérifié code : elle ne lit PAS `is_head_teacher` ; elle lit `User.role` global + co-membership `classGroupNames()` — NFR-S3).
9. **AUCUNE écriture AD** : `GroupRepository`, `syncAdGroupMembersByUserIds`, `syncRoleAwareAdGroupMembers` non modifiés ; aucun nouveau write LDAP, aucun changement des cibles/CN projetés. Les tests de capture d'appels AD existants passent sans modification de leurs attentes.
10. **Tests hôte verts** (php8.4 + SQLite, la VM n'a pas pdo_sqlite) : nouveaux tests (AC1-AC8) + non-régression complète de la suite ciblée : `UserGroupServiceLegacyCompatibilityTest` (~23), `MergeLegacyUserGroupsMigrationTest` (10), `HeadTeacherSectionTest`, `GroupShowMembersTabsTest`, `UserServiceClassChangeTest`, `UserCreationTest`. Les **schémas de test** portent la colonne : `UserGroupServiceLegacyCompatibilityTest::createTestTables` (l.2188-2193) et `tests/Traits/CreatesPermissionSchema.php` (l.85-93) ajoutent `role` string défaut `'member'`. Pré-existant connu hors scope : `BulkPasswordResetGroupsTest` (env LDAP absent, signalé depuis 4.13).

---

## Tasks / Subtasks

- [x] **T1 — Migration + action backfill** (AC1, AC2) — nouveaux fichiers `database/migrations/2026_07_13_120000_add_role_to_user_group_user.php` + `app/Actions/Groups/BackfillUserGroupUserRoles.php`
  - [x] T1.1 `up()` : `Schema::hasColumn` puis `$table->string('role', 20)->default('member')->index()`. Ni PK ni timestamps touchés. Patron : `2026_06_25_120000_add_is_head_teacher_to_user_group_user.php`.
  - [x] T1.2 Action invocable `BackfillUserGroupUserRoles` (pure `DB`, sans dépendance LDAP/service — patron `MergeLegacyUserGroups`) : 3 passes déterministes — (1) `role='member'` partout (base), (2) `role='manager'` pour les arêtes dont le user a `users.role='prof'` (sous-requête SQL, zéro LDAP), (3) `role='owner'` où `is_head_teacher` vrai (query builder `where('is_head_teacher', true)` — cross-driver). Retourne un rapport de comptage. Appelée par `up()` APRÈS la création de colonne.
  - [x] T1.3 `down()` : `dropColumn('role')` gardé `hasColumn` ; partie data no-op documentée (les rôles se reconstruisent par backfill/re-import).
  - [x] T1.4 Doc migration : exécution PG réelle = geste post-merge MANUEL sur /vm (`php artisan migrate` + `migrate:status`) — migrations VM non auto-jouées.
- [x] **T2 — Vocabulaire + helpers sur le pivot** (AC4, AC5) — `app/Models/Pivot/UserGroupUserPivot.php`
  - [x] T2.1 Constantes `ROLE_MEMBER`/`ROLE_MANAGER`/`ROLE_OWNER` + `public const ROLES = [...]` + `assertValidRole(string $role): void` (InvalidArgumentException hors vocabulaire).
  - [x] T2.2 Helper pur `defaultRoleForGlobalRole(?string $globalRole): string` (`'prof'`→manager, sinon member). PAS de cast à ajouter pour `role` (string natif) ; cast `is_head_teacher => boolean` conservé.
- [x] **T3 — `withPivot('role')` sur les 3 relations** (AC3) — `app/Models/UserGroup.php:65-75`, `app/Models/User.php:113-121` et `183-191`
  - [x] T3.1 Ajouter `'role'` au `withPivot` existant de `UserGroup::users()` (garder `is_head_teacher`) ; ajouter `->withPivot('role')` sur `User::groups()` et `User::userGroups()` (le D4 « minimal » de 4.14 est levé : 42.1 écrit l'arête depuis `User::groups()` dans `UserService`, et 42.2/42.3 liront des deux côtés). `->using(UserGroupUserPivot::class)` conservé partout ; `withPivot` n'introduit pas de timestamps.
- [x] **T4 — Défaut au rattachement (import users)** (AC5) — `app/Services/UserService.php:1405-1508` (`persistUserGroupsToSql`)
  - [x] T4.1 Calculer le rôle dérivé une fois : `UserGroupUserPivot::defaultRoleForGlobalRole($sqlUser->role)`.
  - [x] T4.2 Chemin non-classes (l.1421-1429) : distinguer arêtes nouvelles vs existantes (diff avec les ids déjà attachés) ; `attach`/`syncWithoutDetaching` associatif `['role' => $derived]` UNIQUEMENT pour les nouvelles ; ne pas toucher les existantes (piège : `syncWithoutDetaching` avec attributs UPDATE les arêtes déjà présentes).
  - [x] T4.3 Chemin classes (l.1477-1490) : idem sur `$newClassIdsArr` (seules les arêtes réellement nouvelles reçoivent l'attribut). L'Observer pivot reste désactivé/réactivé comme aujourd'hui (aucun changement du flux ShareService).
- [x] **T5 — Miroir sur les écritures existantes** (AC6, AC7)
  - [x] T5.1 `UserGroupService::projectFoldedGroup` (l.586-614) : résoudre `users.role` de l'union des membres EN UNE REQUÊTE (`User::query()->whereIn('id', …)->pluck('role', 'id')` — pas de LDAP) ; payload `sync()` associatif enrichi `['is_head_teacher' => $isPP, 'role' => $isPP ? ROLE_OWNER : defaultRoleForGlobalRole(...)]`. Union/dédup/PP-priorité/idempotence 4.13-4.14 inchangées ; stats `attached`/`detached`/`updated` inchangées.
  - [x] T5.2 `app/Actions/Groups/MergeLegacyUserGroups.php` : sous garde `Schema::hasColumn('user_group_user','role')` — (a) insertOrIgnore l.171-183 : ajouter `role` dérivé (sous-requête `users.role='prof'`→manager sinon member, ou 2ᵉ passe UPDATE post-insert) ; (b) update flag fusion l.190-193 : ajouter `role='owner'` ; (c) update `PP_` isolé l.354-357 : ajouter `role='owner'`. Sans la colonne (base pré-42.1) : comportement 4.14 intact.
- [x] **T6 — Bascule des consommateurs de lecture** (AC8)
  - [x] T6.1 `resources/views/pages/users/groups/[id]/index.blade.php` `members()` l.76-79 : `'is_head_teacher' => (($user->pivot->role ?? null) === UserGroupUserPivot::ROLE_OWNER)`. Clé de view-model inchangée ; blades `members-table.blade.php` (badge PP l.23) inchangées.
  - [x] T6.2 `_partials/head-teacher-section.blade.php` : `refreshState()` l.122-126 filtre sur `pivot->role === 'owner'` ; vérification post-save l.207-216 `wherePivot('role', UserGroupUserPivot::ROLE_OWNER)`. Le `save()` (payload `head_teacher_ids` → `updateGroup`) reste INCHANGÉ (canal projection 4.15).
  - [x] T6.3 VÉRIFIER (ne pas toucher) : `updateGroup` l.179-185 (`wherePivot('is_head_teacher', true)` — alimentation projection, bascule 42.2), `syncRoleAwareAdGroupMembers` l.941-1002, `UserPolicy` (aucune lecture du flag — rien à faire).
- [x] **T7 — Tests hôte** (AC10) — schémas + nouveaux + non-régression
  - [x] T7.1 Schémas de test : colonne `role` string défaut `'member'` dans `UserGroupServiceLegacyCompatibilityTest::createTestTables` (l.2188-2193) et `tests/Traits/CreatesPermissionSchema.php` (l.85-93).
  - [x] T7.2 Nouveau `tests/Feature/Migrations/BackfillUserGroupUserRolesTest.php` (patron `MergeLegacyUserGroupsMigrationTest`) : dérivation owner/manager/member, précédence owner>manager, idempotence (2 runs = même état), zéro LDAP.
  - [x] T7.3 Extension `UserGroupServiceLegacyCompatibilityTest` : read-back pose `role` en miroir (PP→owner, prof→manager, élève→member ; AC7), invariant miroir, idempotence 2 imports, piège withPivot (AC3).
  - [x] T7.4 Extension `MergeLegacyUserGroupsMigrationTest` : fusion pose `role` miroir ; action sur schéma SANS colonne `role` = comportement 4.14 (garde hasColumn).
  - [x] T7.5 Défaut au rattachement (`UserServiceClassChangeTest`/`UserCreationTest` ou nouveau test) : prof importé → arête manager ; élève → member ; re-import ne rétrograde pas une arête owner (AC5).
  - [x] T7.6 Vocabulaire : `assertValidRole` rejette une valeur arbitraire (AC4).
  - [x] T7.7 Non-régression : `vendor/bin/phpunit --filter "UserGroup|MergeLegacy|HeadTeacher|GroupShowMembers|UserServiceClassChange|UserCreation|Backfill"` — piloter par filtres (le run massif VM produit de faux échecs, `project_vm_phpunit_bulk_run_false_failures`).
- [x] **T8 — Doc QA append-only** — `docs/qa/domains/rights-management.md` : nouvelle section « Rôle sur l'arête (Story 42.1) » — scénarios backfill + miroir + runbook migration VM post-merge (`migrate:status` avant/après). Sections existantes non renumérotées.

---

## Dépendances

- **Amont (satisfaites)** : **4.11** (`done`) — pivot global memberships ; **4.14** (`done`) — attribut d'arête `is_head_teacher` + `MergeLegacyUserGroups` + read-back associatif (précédent direct de cette story) ; 4.12/4.13/4.15 (`done`) — heuristiques et projection en place, NON touchées ici.
- **Aval (bloquées par 42.1)** : **42.2** (projection AD depuis les arêtes — bascule `syncRoleAwareAdGroupMembers` + suppression `is_head_teacher`), **42.3** (UI rôle éditable), **42.4** (read-back rôles au sync-from-ad).
- Non bloquant : 4.16 (`review`) — scope `syncFromAd`/`updateGroup`, orthogonal (le paramètre `onlyGroupNames` est déjà dans le code de `main`).

---

## Dev Notes

### Ancrage code (chemins:lignes vérifiés 2026-07-13)

| Élément | Fichier:ligne | Action 42.1 |
|---|---|---|
| Schéma pivot (PK compo, pas de timestamps) | `database/migrations/2026_02_06_115500_create_rights_management_tables.php:70-81` | Cible de la colonne (T1) |
| `users.role` (`eleve|prof|admin|autre`, indexée) | même migration `:32,45` | Source de dérivation (SQL only) |
| Patron migration colonne pivot + garde hasColumn | `database/migrations/2026_06_25_120000_add_is_head_teacher_to_user_group_user.php` | Copier la forme (T1) |
| Pivot custom (casts, `$timestamps=false`) | `app/Models/Pivot/UserGroupUserPivot.php` | Constantes + garde + helper (T2) |
| `UserGroup::users()` — withPivot is_head_teacher | `app/Models/UserGroup.php:65-75` | + `'role'` (T3) |
| `User::userGroups()` / `User::groups()` | `app/Models/User.php:113-121, 183-191` | + `withPivot('role')` (T3) |
| Read-back fold — payload sync associatif | `app/Services/UserGroupService.php:586-614` (`projectFoldedGroup`) | + `role` miroir (T5.1) |
| Dérivation PP pour la projection (NE PAS TOUCHER) | `app/Services/UserGroupService.php:179-185` (`updateGroup`) | Bascule = 42.2 |
| Projection AD (NE PAS TOUCHER) | `app/Services/UserGroupService.php:941-1002` (`syncRoleAwareAdGroupMembers`), `:1062+` (`syncAdGroupMembersByUserIds`) | Bascule = 42.2 |
| Attach import users | `app/Services/UserService.php:1405-1508` (`persistUserGroupsToSql` ; writes l.1428, 1483, 1486) | Défaut dérivé, nouvelles arêtes only (T4) |
| Fusion legacy (3 écritures pivot) | `app/Actions/Groups/MergeLegacyUserGroups.php:171-183, 190-193, 354-357` | Miroir sous hasColumn (T5.2) |
| Observer pivot (ShareService, type classe) | `app/Observers/UserGroupUserPivotObserver.php` | AUCUN changement (events created/deleted, insensibles à l'attribut) |
| Badge PP fiche groupe | `resources/views/pages/users/groups/[id]/index.blade.php:76-79` | Lire `pivot->role === 'owner'` (T6.1) |
| Section PP (état + convergence post-save) | `resources/views/pages/users/groups/[id]/_partials/head-teacher-section.blade.php:122-126, 207-216` | Lire role ; `save()` INCHANGÉ (T6.2) |
| Badge (blade pur, clé view-model) | `resources/views/pages/users/groups/[id]/_partials/members-table.blade.php:23` | Inchangé (clé conservée) |
| Schémas de test à enrichir | `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php:2188-2193`, `tests/Traits/CreatesPermissionSchema.php:85-93` | + colonne `role` (T7.1) |
| Patron test action data | `tests/Feature/Migrations/MergeLegacyUserGroupsMigrationTest.php` | Patron T7.2 |
| UserPolicy (lit User.role global, PAS le flag) | `app/Policies/UserPolicy.php:222-254` | RIEN à changer (constat vérifié) |

### Pièges & points d'attention

- **Piège n°1 (précédent 4.14)** : sans `withPivot('role')` sur la relation utilisée, `sync([$id => ['role' => …]])` **ignore silencieusement** l'attribut. Test dédié obligatoire (AC3).
- **Piège n°2 — `syncWithoutDetaching` avec attributs UPDATE l'existant** : dans `persistUserGroupsToSql`, passer `['role' => $derived]` pour un id déjà attaché écraserait un rôle promu (`owner`). N'appliquer l'attribut qu'aux arêtes NOUVELLES (diff explicite avant l'appel). AC5 le teste.
- **Piège n°3 — ordre des migrations** : la migration 2026_06_25 (qui documente l'action `MergeLegacyUserGroups`) précède la colonne `role`. Toute écriture de `role` dans `MergeLegacyUserGroups` DOIT être gardée `Schema::hasColumn` — sinon un `migrate` fresh ou une invocation sur base pré-42.1 casse. AC6/T7.4.
- **Piège n°4 — LDAP-first** : `User::isProf()` fait un round-trip LDAP par user avant le fallback SQL. Sur le backfill et l'union du read-back : lire **la colonne `users.role`** (sous-requête SQL ou `pluck('role','id')`), jamais `isProf()` en boucle.
- **Piège n°5 — collision de noms dans le view-model** : `members()` de la fiche groupe expose déjà `'role'` = rôle GLOBAL (`prof|eleve|autre`). NE PAS y injecter le rôle d'arête sous la même clé en 42.1 (42.3 introduira la colonne UI proprement). Conserver la clé `'is_head_teacher'` calculée depuis `pivot->role`.
- **Piège n°6 — SQLite ne borne pas les varchar** : `string('role', 20)` n'est PAS appliqué en test → la garde applicative `assertValidRole` est la seule frontière ; PG 22001 n'apparaîtrait qu'en prod (`project_sqlite_tests_no_varchar_enforcement`).
- **Booleans cross-driver** : comparer `is_head_teacher` via query builder (`where('is_head_teacher', true)`), jamais en SQL brut `= true` (SQLite stocke 0/1).
- **Idempotence read-back** : `sync()` associatif — un 2ᵉ run sans changement doit rendre `attached=[] detached=[]` et `updated` vide (aucune bascule de rôle fantôme). La dérivation étant déterministe, l'import écrase un `role` édité à la main : comportement transitoire ASSUMÉ (AD-first), documenté, raffiné en 42.4 (« rôles non écrasés sans changement AD »).
- **Semantics `owner`** : un `owner` est un cas particulier de prof — il ne sort PAS du bucket manager côté projection (la sémantique orthogonale `Equipe_`+`PP_` de 4.15 est préservée par 42.2, pas par nous). En 42.1 personne ne consomme `manager` : seule la paire lecture `owner`/miroir est branchée.
- **VM** : migrations non auto-jouées (`migrate:status` avant tout e2e) ; exécution PG réelle post-merge manuelle. Ne JAMAIS interagir avec la VM depuis un worktree.
- **Worktree** : si dev en worktree — `cp -al` du vendor, jamais de symlink (`project_ultradev_worktree_vendor_trap`).

### Testing standards

- Tests sur l'**HÔTE** uniquement (php8.4 + sqlite ; la VM n'a pas pdo_sqlite). Lancer par **filtres** ciblés, pas de run massif. Gardes suite existantes (`withoutVite`/reguard) à respecter dans les tests Livewire.
- Patrons : `UserGroupServiceLegacyCompatibilityTest` (mocks `GroupRepository`, `primeNoLdap()`, purge `User::$ldapCache` en tearDown), `MergeLegacyUserGroupsMigrationTest` (action sur état monté à la main).
- Pré-existant connu, hors scope, NE PAS « corriger » : `BulkPasswordResetGroupsTest` (env LDAP absent).

### Project Structure Notes

- 2 fichiers créés : `database/migrations/2026_07_13_120000_add_role_to_user_group_user.php`, `app/Actions/Groups/BackfillUserGroupUserRoles.php` (+ 1 fichier de test).
- Éditions ciblées : `UserGroupUserPivot`, `UserGroup`, `User` (relations), `UserGroupService::projectFoldedGroup`, `UserService::persistUserGroupsToSql`, `MergeLegacyUserGroups`, 2 SFC blade `[id]/` (lectures seulement), 2 schémas de test, doc QA.
- Racine projet = Laravel (`app/`, pas `laravel/app`). Aucun fichier `agent/**` (pas de bump version agent). Aucune route/vue nouvelle.
- Convention « une migration par feature finale » (architecture.md §Data Architecture) : colonne + backfill dans UNE migration (backfill extrait en action pour la testabilité — précédent 4.14).

### References

- [Source: _bmad-output/planning-artifacts/epics-socle-role-groupes.md#Story 42.1] — intention + AC-skeleton figé ici ; FR-S1/FR-S2, NFR-S3/NFR-S4
- [Source: docs/group-model-multivertical-orientation.md#Direction proposée / #Séquençage retenu] — rôle sur l'arête, phase « socle »
- [Source: _bmad-output/planning-artifacts/architecture.md#Modèle de Données / #Data Architecture] — PG source applicative, AD sync transitoire, migrations squashées
- [Source: _bmad-output/implementation-artifacts/4-14-migration-fusion-groupes-is-head-teacher.md] — précédent direct : colonne pivot + action data invocable + sync associatif + piège withPivot
- [Source: _bmad-output/implementation-artifacts/4-15-ecriture-pp-ad-ui-professeur-principal.md] — canal projection `PP_` (NON basculé ici) ; D2 ordre AD-avant-read-back
- [Source: _bmad-output/implementation-artifacts/4-12-peuplement-equipe-x-par-role.md] — partition `isProf()` (remplacée en 42.2, pas ici)
- [Source: memory/project_isprof_iseleve_ldap_first_cost.md ; project_sqlite_tests_no_varchar_enforcement.md ; project_vm_migrations_not_auto_applied.md ; project_sync_from_ad_transitional.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (dev-story, main)

### Debug Log References

- `vendor/bin/phpunit --filter "UserGroup|MergeLegacy|HeadTeacher|GroupShowMembers|UserServiceClassChange|UserCreation|Backfill|UserGroupUserPivot"` → **129 tests / 376 assertions, OK**.
- Review #3 — le filtre ci-dessus ne couvrait pas les commandes d'import qui exercent `projectFoldedGroup` : ajouter `|SyncFromAdImportCommand|SyncUsersFromAdCommand` au filet de non-régression. Run post-corrections (filtre complet + `UserDerivedRolePayload`) → **142 tests / 415 assertions, OK**.
- `vendor/bin/phpunit --filter "UserPolicy|UserGroupUserPivotObserver"` → **18 tests, OK** (non-régression policy + observer, non touchés).
- `DB_CONNECTION=sqlite DB_DATABASE=<scratch> php artisan migrate --force` → migration `2026_07_13_120000_add_role_to_user_group_user` **DONE** (full migrate SQLite passe, AC1). Ordre des migrations validé : 4.14 (2026_06_25) tourne AVANT la colonne `role`, la garde `Schema::hasColumn` dans `MergeLegacyUserGroups` empêche toute casse.

### Completion Notes List

- **AC1** — Migration `add_role_to_user_group_user` : `string('role', 20)->default('member')->index()` sous garde `hasColumn` ; `down()` retire l'index PUIS la colonne (SQLite laisse un index orphelin sinon → `dropIndex(['role'])` avant `dropColumn`). PK composite + absence de timestamps inchangées.
- **AC2** — Action `BackfillUserGroupUserRoles` : 3 passes déterministes (base `member` → `manager` via sous-requête `users.role='prof'` ZÉRO LDAP → `owner` via `where('is_head_teacher', true)` cross-driver). Idempotente (réaligne un `role` obsolète). Appelée par `up()`.
- **AC3** — `withPivot('role')` ajouté sur les 3 relations (`UserGroup::users()` conserve `is_head_teacher`, `User::groups()`, `User::userGroups()`). Piège 4.14 testé.
- **AC4/AC5** — Constantes `ROLE_*` + `ROLES` + `assertValidRole()` (InvalidArgumentException) + `defaultRoleForGlobalRole()` (prof→manager, sinon member, JAMAIS owner) sur `UserGroupUserPivot`.
- **AC5** — `persistUserGroupsToSql` : rôle dérivé de `$sqlUser->role` appliqué UNIQUEMENT aux arêtes nouvelles (diff explicite sur les 2 chemins non-classes/classes) ; owner d'un PP jamais rétrogradé au re-import.
- **AC6/AC7** — Miroir `role` ⇔ `is_head_teacher` posé en même temps sur `projectFoldedGroup` (dérivation `users.role` en 1 requête pour l'union) et `MergeLegacyUserGroups` (3 écritures, gardées `hasColumn`).
- **AC8** — Lectures UI basculées sur `role === 'owner'` : badge fiche groupe (clé view-model `is_head_teacher` CONSERVÉE, pas de collision avec le rôle global), `head-teacher-section` (refreshState + convergence post-save `wherePivot('role', 'owner')`). Canal projection `head_teacher_ids`/`syncRoleAwareAdGroupMembers`/`updateGroup` INTACT. `UserPolicy` non touchée (constat vérifié : ne lit pas `is_head_teacher`).
- **AC9** — Aucune écriture AD ajoutée ; `GroupRepository`/`syncAdGroupMembersByUserIds`/`syncRoleAwareAdGroupMembers` non modifiés.
- **AC10** — Schémas de test enrichis (`CreatesPermissionSchema`, `UserGroupServiceLegacyCompatibilityTest`, + schémas inline `UserCreationTest`, `MergeLegacyUserGroupsMigrationTest`, `BackfillUserGroupUserRolesTest`). Fixtures existantes qui posaient `is_head_teacher` directement mises à jour pour poser aussi le miroir `role` (invariant). Suite ciblée verte.
- **Décision notable** : `UserCreationTest` avait un schéma pivot inline SANS la colonne (ni `is_head_teacher` ni `role`) — l'attach instrumenté écrivait `role` → colonne ajoutée à ce schéma de test (hors des 2 cités par la story mais nécessaire à la non-régression AC10).

### File List

**Créés**
- `database/migrations/2026_07_13_120000_add_role_to_user_group_user.php`
- `app/Actions/Groups/BackfillUserGroupUserRoles.php`
- `tests/Feature/Migrations/BackfillUserGroupUserRolesTest.php`
- `tests/Unit/Models/UserGroupUserPivotTest.php`

**Modifiés (code)**
- `app/Models/Pivot/UserGroupUserPivot.php` (constantes + `assertValidRole` + `defaultRoleForGlobalRole`)
- `app/Models/UserGroup.php` (`withPivot('is_head_teacher','role')`)
- `app/Models/User.php` (`withPivot('role')` sur `groups()` et `userGroups()`)
- `app/Services/UserService.php` (`persistUserGroupsToSql` — défaut dérivé, nouvelles arêtes only)
- `app/Services/UserGroupService.php` (`projectFoldedGroup` — miroir `role`, union résolue en 1 requête)
- `app/Actions/Groups/MergeLegacyUserGroups.php` (miroir `role` sur les 3 écritures, garde `hasColumn`)
- `resources/views/pages/users/groups/[id]/index.blade.php` (badge PP lu sur `role === 'owner'`)
- `resources/views/pages/users/groups/[id]/_partials/head-teacher-section.blade.php` (refreshState + convergence post-save)

**Modifiés (tests)**
- `tests/Traits/CreatesPermissionSchema.php` (colonne `role`)
- `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (schéma + tests miroir/idempotence/withPivot-trap)
- `tests/Feature/Migrations/MergeLegacyUserGroupsMigrationTest.php` (schéma + tests miroir + garde hasColumn)
- `tests/Feature/UserCreationTest.php` (colonne `role` au schéma inline)
- `tests/Feature/Services/UserServiceClassChangeTest.php` (tests défaut au rattachement)
- `tests/Feature/Livewire/Users/HeadTeacherSectionTest.php` (fixtures miroir `role`)
- `tests/Feature/Livewire/Users/GroupShowMembersTabsTest.php` (fixtures miroir `role`)

**Modifiés (doc)**
- `docs/qa/domains/rights-management.md` (Section 15 append-only)

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-07-13 | 1.0 | IMPLÉMENTÉE (dev-story, claude-opus-4-8, main). Colonne `role` (string 20, défaut member, index) + `BackfillUserGroupUserRoles` idempotent + `withPivot('role')` ×3 + vocabulaire borné (`assertValidRole`/`defaultRoleForGlobalRole`) + défaut au rattachement (nouvelles arêtes only) + miroir `role`⇔`is_head_teacher` sur projectFoldedGroup/MergeLegacyUserGroups/backfill + lectures UI sur `role==='owner'`. AUCUNE écriture AD ; canal projection 4.15 intact. Fix SQLite `dropIndex` avant `dropColumn` en down(). Suite ciblée 129 tests verts. Section 15 QA (rights-management). Status → review. | Dev (claude-opus-4-8) |
| 2026-07-13 | 0.1 | Story CRÉÉE (SM/create-story, Fable 5). Périmètre strict figé depuis l'AC-skeleton de l'epic 42 : colonne `role` (string 20, défaut member, index, gardes hasColumn) + backfill idempotent en action invocable (owner>manager>member, SQL only) + `withPivot('role')` sur les 3 relations + vocabulaire borné applicatif + défaut au rattachement dérivé de `User.role` (nouvelles arêtes only) + miroir `is_head_teacher`⇔`role` sur projectFoldedGroup/MergeLegacyUserGroups/backfill + bascule des lectures UI sur `role==='owner'`. EXCEPTION CRITIQUE : canal projection 4.15 (`syncRoleAwareAdGroupMembers` + `head_teacher_ids`) NON basculé, suppression `is_head_teacher` = tâche 42.2. AUCUNE écriture AD. Constat vérifié code : UserPolicy ne consomme PAS `is_head_teacher` (rien à basculer côté policy). 10 AC, 8 tâches. | SM (Fable 5) |

---

## Recommandation Modèle Dev

**opus.**

L'epic pré-cadrait sonnet (« migration + backfill cadrés »), révisé à la création : le vrai risque n'est pas la migration (triviale) mais l'**invariant miroir** `is_head_teacher`⇔`role` à maintenir simultanément sur 3 flux d'écriture hétérogènes (read-back `sync()` associatif, action SQL pure `MergeLegacyUserGroups` avec garde d'ordre de migrations, import users), le piège `syncWithoutDetaching`-avec-attributs qui écrase silencieusement les arêtes existantes, et la frontière chirurgicale avec le canal projection 4.15 qui ne doit PAS bouger (toute fuite de bascule = fenêtre où la projection lit une colonne morte — exactement ce que 42.2 doit éviter). Profil identique à 4.14 (même famille de pièges pivot/PK composite), qui avait été confiée à opus. Multi-fichiers (~12), logique critique, zéro UI créative → opus ; review par le modèle opposé.

## Code Review Record (2026-07-13)

Review adversariale **sonnet** (dev opus) + évaluation orchestrateur (éval des findings, lecture code) : **approuvé avec réserves** — 0 critique, 2 importants, 2 mineurs, **tous corrigés**. Doc : `_bmad-output/codeReviews/42-1.md`.

Corrections appliquées :
1. (#1 🟠, pertinence 3) Écrivains pivot UI hors import instrumentés : `User::userGroupSyncPayloadWithDerivedRole()` (rôle dérivé sur arêtes NOUVELLES uniquement, existantes jamais réécrites) câblé sur `syncGroupsFromAd` (fiche user) et les 2 écritures du drawer « Gestion des groupes ». Tests : `tests/Feature/Models/UserDerivedRolePayloadTest.php` (3 tests).
2. (#2 🟠, pertinence 2) `assertValidRole()` câblée dans `defaultRoleForGlobalRole()` (défense en profondeur, plus une garde morte).
3. (#3 🟡, pertinence 2) Filet de non-régression documenté complété (Sync*CommandTest).
4. (#4 🟡, pertinence 2) Commentaire `MergeLegacyUserGroups` corrigé : la garde `hasColumn` couvre l'invocation manuelle/ops sur base pré-42.1, pas un `migrate fresh` (la migration 4.14 n'invoque pas l'action).
