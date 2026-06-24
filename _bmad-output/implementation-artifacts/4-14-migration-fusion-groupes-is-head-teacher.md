# Story 4.14 : Migration data (fusion lignes héritées) + colonne `is_head_teacher` sur l'arête

Status: review

> **Type** : migration de schéma (1 colonne pivot) + migration de **données** (fusion des lignes `user_groups` héritées) + alimentation du flag PP à l'import. Pas d'écriture SQL→AD, pas d'UI.
>
> **Origine** : Epic 4 — gestion des groupes utilisateurs. **2ᵉ** des 3 stories de la refonte « groupes au nom nu » (4.13 fold import *livrée/review* / **4.14 migration data + `is_head_teacher`** / 4.15 écriture SQL→AD `PP_` + UI). Suite directe de 4.13 (fold du moteur `syncFromAd`) — voir la section **HORS SCOPE** de 4.13 qui renvoie explicitement migration data + colonne ici.
>
> **Direction validée (Henri, 2026-06-24)** : le professeur principal est modélisé par un **attribut d'arête** `is_head_teacher` (bool) sur le pivot `user_group_user`, PAS par un groupe séparé. Import `PP_<base>` → rattache le membre à la ligne nue `<base>` **avec** `is_head_teacher=true`. Plusieurs PP par classe autorisés. Flag pertinent uniquement pour les groupes de type `classe`/`equipe`.
>
> **Mémoire projet liée** : `memory/project_usergroup_sql_fold_bare_name.md` (direction + blast radius), `memory/project_pivot_global_memberships.md` (modèle pivot global, invariants), `memory/project_equipe_group_never_populated_se5.md` (4.12), `memory/project_group_model_multivertical_direction.md` (SE6 — rôle sur l'arête, orientation parquée), `memory/project_sync_from_ad_transitional.md` (AD-first transitoire).

---

## Story

En tant que **responsable d'établissement et développeur SE5**,
je veux **(1) fusionner les éventuelles lignes `user_groups` héritées `Classe_X` / `Equipe_X` / `PP_X` d'une base déjà importée AVANT 4.13 en UNE seule ligne au nom nu `X`** (report des pivots, choix d'`ad_guid`/`ad_dn` canonique, suppression des redondantes), **et (2) tracer le rôle « professeur principal » par un flag `is_head_teacher` sur l'arête `user_group_user`** alimenté à l'import depuis le CN `PP_<X>`,
afin que les bases existantes convergent vers le modèle « 1 ligne SQL = 1 classe » introduit par 4.13 (sans perte de membres) et que la story aval 4.15 (écriture SQL→AD `PP_` + UI) dispose d'une source de vérité du PP portée par le pivot.

---

## Contexte & cause racine (lecture code 2026-06-24)

### Ce que 4.13 a livré (point d'appui — NE PAS refaire)

4.13 a réécrit **la projection à venir** : `UserGroupService::syncFromAd` itère désormais sur `buildFoldedGroups()` qui regroupe les CN AD `Classe_X`/`Equipe_X`/`PP_X` d'une même base **AVANT** toute écriture, produit UNE entrée au nom nu `X` (`ad_guid`/`ad_dn` canoniques D2 `Classe_ > Equipe_ > PP_`, `type='classe'` D3), et fait **un seul** `users()->sync(union)` par ligne (`app/Services/UserGroupService.php:312,335-459`). Helpers `buildFoldedGroups`/`foldPrefixOf`/`shouldFold`/`resolveSqlLookupName` + const `FOLD_PREFIXES` (l.551-758). Lookup post-sync au nom nu branché dans `createGroup`/`updateGroup`.

**Limite connue D5 de 4.13** (citée verbatim dans 4.13) : « les lignes SQL `Classe_X`/`Equipe_X`/`PP_X` **déjà présentes** sur une base existante ne sont PAS fusionnées par 4.13 (pas de migration de données). » → C'est le **scope #1/#2 de 4.14**.

### Ce que 4.13 NE fait PAS (les trois trous que 4.14 comble)

1. **Lignes héritées non fusionnées.** Sur une base migrée avant 4.13 (ou greenfield jamais re-syncé complet), `user_groups` peut contenir 3 lignes physiques `Classe_3A`/`Equipe_3A`/`PP_3A` (chacune avec ses pivots, son `ad_guid`, son `ad_dn`). Le fold de 4.13 ne s'applique qu'au **flux d'import** ; il ne réécrit pas l'existant déjà persisté. → **Migration data** (scope #2).
2. **Pas de colonne `is_head_teacher`.** Le pivot `user_group_user` n'a que `(user_group_id, user_id)` (PK composite, `$incrementing=false`, `$timestamps=false` — voir `app/Models/Pivot/UserGroupUserPivot.php` + migration `2026_02_06_115500_create_rights_management_tables.php:70-81`). Le membre venu de `PP_3A` est aujourd'hui simplement unioné dans la ligne nue, **indistinct** des autres profs. → **Colonne pivot** (scope #1).
3. **L'union de 4.13 perd l'origine PP.** `resolveMemberUserIdsFromAdGroup(cn)` (`UserGroupService.php:734-758`) retourne des IDs **sans** tracer de quel CN ils viennent ; la boucle de fold concatène les IDs des 3 CN puis `sync(array_unique(...))` (l.430-437). L'information « ce membre vient de `PP_` » est donc dissoute. → **Alimentation du flag à l'import** (scope #3) = capturer l'ensemble des membres du CN `PP_` du groupe foldé et porter `is_head_teacher=true` sur leur arête.

### AD-first conservé

L'import AD→SQL reste **l'outil de migration transitoire** (`memory/project_sync_from_ad_transitional.md`). 4.14 ne change PAS la direction : AD reste la source ; SQL le cache. La migration data ne fait que **converger l'existant SQL** vers la forme que produit déjà le fold ; l'alimentation du flag enrichit la projection lecture (read-back) sans toucher l'écriture SQL→AD (4.15).

---

## Scope précis (UNIQUEMENT « migration data + colonne `is_head_teacher` »)

### 1. Migration de schéma — colonne `is_head_teacher` sur `user_group_user`
- Ajouter `is_head_teacher` (boolean, **défaut false**, non nullable) au pivot `user_group_user`.
- Le pivot a une **PK composite** `(user_group_id, user_id)` et pas de timestamps — la migration ajoute uniquement une colonne, sans toucher la PK ni introduire de timestamps.
- `down()` : `dropColumn('is_head_teacher')` (idempotent via `Schema::hasColumn`).
- Cible Postgres (prod SE5) ; compatible SQLite (tests hôte). **Piège SQLite** : `ALTER TABLE ADD COLUMN ... DEFAULT false` est supporté ; vérifier que le défaut s'applique aux lignes pivot existantes (Laravel/SQLite : un `boolean()->default(false)` rétro-remplit). Tester explicitement (AC5).

### 2. Migration de données — fusion des lignes `user_groups` héritées
- Détecter les **bases** ayant ≥ 2 lignes physiques parmi `{Classe_<X>, Equipe_<X>, PP_<X>}` (matching **strict casse** comme 4.13 — préfixes `Classe_`/`Equipe_`/`PP_`, réutiliser la même logique de base nue que `stripClasseLikePrefix`). Une base = la portion nue après strip du préfixe, **insensible à la casse** pour le regroupement (cf. `mb_strtolower(base)` dans `buildFoldedGroups`).
- Pour chaque base à fusionner :
  - **Choisir la ligne survivante (canonique)** : ligne `Classe_<X>` prioritaire ; fallback déterministe `Equipe_<X>` puis `PP_<X>` (ordre D2 identique à 4.13). Si une ligne **nom nu `X`** existe déjà (cas mixte : base partiellement foldée par un import 4.13 + reliquats préfixés), elle est la survivante prioritaire sur tout préfixe.
  - **Renommer** la survivante en **nom nu** `X` (`name = X`), `type='classe'` (D3), `ad_guid`/`ad_dn` = ceux du CN canonique survivant (si la survivante n'est pas déjà `Classe_<X>`, conserver ses propres `ad_guid`/`ad_dn` — ils correspondent au CN le plus prioritaire disponible).
  - **Reporter les pivots** des lignes redondantes vers la survivante : **union** des `user_id` (idempotent : `insertOrIgnore`/`ON CONFLICT DO NOTHING` sur la PK composite). Aucun membre perdu, aucun doublon (PK compo garantit l'unicité).
  - **Marquer `is_head_teacher=true`** sur les arêtes de la survivante pour les `user_id` qui étaient membres de la ligne `PP_<X>` (avant suppression de celle-ci).
  - **Supprimer** les lignes redondantes `Classe_/Equipe_/PP_` (leurs pivots cascade `onDelete('cascade')` — mais on a déjà reporté avant suppression). Sécuriser l'ordre : reporter pivots → marquer PP → supprimer redondantes.
- **Conflit d'unicité `name`** : `user_groups.name` est `unique` (migration l.56). Renommer une survivante en `X` alors qu'une ligne `X` préexiste = collision. → la détection « ligne nue préexistante » (ci-dessus) doit être faite **en amont** ; si `X` existe, c'est ELLE la survivante et les préfixées y sont fusionnées (pas l'inverse). Garde anti-collision explicite.
- **Idempotente et rejouable** : une 2ᵉ exécution ne doit rien faire (plus de lignes préfixées à fusionner). Utiliser des opérations `insertOrIgnore`/`updateOrInsert` et des suppressions conditionnées par l'existence des préfixées.
- **`Equipe_<X>` orphelin (règle D1 de 4.13)** : une ligne `Equipe_<Y>` SANS `Classe_<Y>`/`PP_<Y>` héritées **et** sans ligne nue `Y` de type classe/équipe **ne fusionne PAS** (c'est l'équipe d'un cours, sémantique distincte). Elle est renommée en nom nu `Y` (type `equipe`) **si** elle est encore préfixée — cohérent avec le comportement standalone de `buildFoldedGroups` (l.617-626) — OU laissée telle quelle si déjà nue. À trancher : voir D3 ci-dessous.

### 3. Alimentation du flag `is_head_teacher` à l'import (read-back)
- Dans le moteur de fold de 4.13 (`syncFromAd` boucle l.430-437), porter `is_head_teacher=true` sur l'arête des membres issus du **CN `PP_<base>`** du groupe foldé.
- Mécanisme : pour chaque entrée foldée, calculer l'ensemble des `user_id` venant du/des CN `PP_*` (le groupe foldé connaît ses `cns` — `$folded['cns']`), puis passer au `users()->sync()` un tableau **associatif** `[$userId => ['is_head_teacher' => $isPP]]` au lieu du tableau de scalaires actuel. Les membres non-PP portent `is_head_teacher=false`.
- **Requiert `->withPivot('is_head_teacher')`** sur la relation `UserGroup::users()` (`app/Models/UserGroup.php:55-63`) pour que `sync()` persiste l'attribut d'arête. Vérifier l'impact sur les **3 relations** pointant `user_group_user` : `UserGroup::users()`, `User::userGroups()`, `User::groups()` (toutes via `->using(UserGroupUserPivot::class)`). Ajouter `withPivot` là où le flag doit être lu/écrit (a minima `UserGroup::users()` pour l'écriture du fold ; `User::userGroups()`/`groups()` si une lecture aval en a besoin — sinon laisser inchangé pour limiter le blast radius).
- **Préserver les invariants 4.13** : l'union des membres et l'idempotence ne doivent PAS régresser. Un `sync()` associatif avec attribut d'arête doit toujours dédupliquer par `user_id` (un membre présent dans `Classe_` ET `PP_` → une seule arête, `is_head_teacher=true`). Le `sync()` détache toujours les membres absents de l'union (comportement 4.13 conservé).
- **Plusieurs PP par classe** autorisés (plusieurs `user_id` à `is_head_teacher=true`).
- **`is_head_teacher` n'est posé QUE pour les groupes foldés de type classe/équipe** (la notion n'a de sens que là). Un CN non foldé (`Cours_`, `Matiere_@`, etc.) ne porte jamais le flag (reste `false`).

---

## HORS SCOPE 4.14 (renvoyé explicitement à 4.15)

- **Écriture SQL→AD vers `PP_<X>`** : ajout d'une 3ᵉ cible `PP_<X>` dans `syncRoleAwareAdGroupMembers` (`UserGroupService.php:785+`) pilotée par le flag `is_head_teacher`. 4.14 alimente le flag **en lecture** ; 4.15 le **projette** vers AD. NE PAS toucher `syncRoleAwareAdGroupMembers`/`syncAdGroupMembersByUserIds`.
- **UI « Professeur principal »** : affichage/édition du flag dans l'edit-form du groupe (`resources/views/pages/users/groups/`) ou ailleurs. Aucune UI dans 4.14.
- Refonte du modèle multi-vertical (rôle générique sur l'arête, ProvisioningProfile) — orientation **parquée** (`memory/project_group_model_multivertical_direction.md`).

---

## Décisions de cadrage (à acter / actées)

- **D1 — Survivante canonique = même ordre que le fold (D2 de 4.13).** Ligne nue `X` préexistante > `Classe_X` > `Equipe_X` > `PP_X`. Garantit la cohérence avec ce que produit `buildFoldedGroups` sur le flux d'import (pas de divergence entre l'existant fusionné et le futur importé).
- **D2 — Report des pivots = UNION idempotente.** `insertOrIgnore` sur la PK composite `(user_group_id, user_id)`. Pas de perte, pas de doublon. Le flag `is_head_teacher` est posé `true` pour les membres issus de la ligne `PP_` ; les autres restent `false` (défaut colonne).
- **D3 — `Equipe_` orphelin en migration data.** Aligné sur le standalone de `buildFoldedGroups` (4.13, l.617-626) : une ligne `Equipe_<Y>` orpheline (pas de `Classe_`/`PP_` héritées de même base, pas de ligne nue classe/équipe) est **renommée au nom nu `Y` type `equipe`** si encore préfixée, **sans fusion**. Décision retenue par cohérence read-back↔migration. (Risque collision `name` `Y` à gérer comme pour les fusionnées.)
- **D4 — `withPivot('is_head_teacher')` minimal.** Ajouter sur `UserGroup::users()` (écriture du fold) impérativement. Sur `User::userGroups()`/`User::groups()` : seulement si une lecture aval consomme le flag dans 4.14 (a priori non — 4.15 le fera). **Documenter le choix retenu** ; ne pas élargir sans besoin (le custom pivot + observer 5.2 ne doit pas régresser : `UserGroupUserPivot` n'a pas de timestamps, ajouter `withPivot` ne réactive pas les timestamps).
- **D5 — La migration data tourne sur la VM Postgres en différé.** Les migrations VM ne sont **pas auto-jouées** par le dev-cycle (`memory/project_vm_migrations_not_auto_applied.md` — SQLite migré only, VM reste `Pending`). La migration de fusion est **rejouable** : son exécution réelle sur `/vm` est un geste **post-merge** explicite (`migrate` + vérif `migrate:status`), différée comme l'E2E. Documenter dans la story + runbook QA.

---

## Critères d'acceptation

1. **Colonne pivot** — Après migration `up()`, la table `user_group_user` possède une colonne `is_head_teacher` boolean non nullable défaut `false`. Les arêtes préexistantes (le cas échéant) valent `false`. `down()` la supprime proprement (idempotent). Aucune modification de la PK composite, aucun timestamp ajouté.
2. **Fusion — 3 lignes héritées → 1 nue** — Données de départ : `user_groups` contient `Classe_3A` (membres {alice}), `Equipe_3A` (membres {bob}), `PP_3A` (membres {bob}). Après migration data : il existe **UNE seule** ligne `name='3A'`, `type='classe'`, dont les membres sont l'**union** `{alice, bob}` (dédupliquée). Les lignes `Classe_3A`/`Equipe_3A`/`PP_3A` ont disparu. Aucun membre perdu.
3. **`ad_guid`/`ad_dn` canoniques après fusion** — La ligne survivante `3A` porte l'`ad_guid`/`ad_dn` de la ligne `Classe_3A` (canonique D1). Si `Classe_3A` est absente mais `Equipe_3A` présente, elle porte ceux d'`Equipe_3A` (fallback déterministe). Pas de conflit d'unicité `name`.
4. **`is_head_teacher` posé en migration data** — Après fusion de l'AC2, l'arête `(3A, bob)` a `is_head_teacher=true` (bob était membre de `PP_3A`) ; l'arête `(3A, alice)` a `is_head_teacher=false`. Plusieurs PP : si `PP_3A={bob, carol}`, les deux arêtes valent `true`.
5. **Idempotence migration data** — Rejouer la migration data une 2ᵉ fois (ou la lancer sur une base déjà foldée par 4.13, sans lignes préfixées) est un **no-op** : aucune ligne créée/supprimée, aucun flag modifié, aucune exception. Compatible SQLite (tests) **et** Postgres (cible).
6. **Collision nom nu préexistant** — Données : ligne nue `3A` (type classe, membres {alice}) **+** reliquat `PP_3A` (membres {bob}) hérité d'un import partiel. Après migration : UNE ligne `3A`, membres `{alice, bob}`, `(3A, bob).is_head_teacher=true`, `(3A, alice).is_head_teacher=false`. La ligne nue préexistante est la survivante (D1) ; `PP_3A` est fusionnée puis supprimée. Aucune `UNIQUE constraint violation` sur `name`.
7. **`Equipe_` orphelin non fusionné (D3)** — Données : `Cours_Maths5A` (type cours) + `Equipe_Maths5A` (type equipe, pas de `Classe_`/`PP_` Maths5A, pas de ligne nue Maths5A). Après migration : `Cours_Maths5A` inchangée (CN, type cours) ; `Equipe_Maths5A` renommée `Maths5A` (type equipe) **sans fusion** avec le cours. Aucun flag PP.
8. **Alimentation du flag à l'import (read-back)** — Sur AD `Classe_3A={alice}`, `Equipe_3A={bob}`, `PP_3A={bob}` : après `syncFromAd`, la ligne nue `3A` a pour membres `{alice, bob}` (invariant 4.13 conservé) **et** `(3A, bob).is_head_teacher=true`, `(3A, alice).is_head_teacher=false`. Un 2ᵉ `syncFromAd` ne change ni les membres ni les flags (idempotence préservée).
9. **Plusieurs PP à l'import** — Sur AD `PP_3A={bob, carol}` : après `syncFromAd`, `(3A, bob)` et `(3A, carol)` valent `is_head_teacher=true`.
10. **Retrait du rôle PP à l'import** — Un membre `bob` qui était dans `PP_3A` puis n'y est plus (toujours dans `Classe_3A`) : après `syncFromAd`, `bob` reste membre de `3A` mais `(3A, bob).is_head_teacher=false`. Le flag suit l'état AD (pas de rémanence).
11. **CN non foldés sans flag** — Un `Cours_Histoire4A` (membre {prof}) après `syncFromAd` : la ligne `Cours_Histoire4A` existe (type cours) et `(Cours_Histoire4A, prof).is_head_teacher=false`. Le flag n'est jamais `true` hors classe/équipe foldée.
12. **Non-régression 4.13/4.12** — Tous les tests existants de `UserGroupServiceLegacyCompatibilityTest` (16, dont `it_unions_members_across_folded_variants`, `it_is_idempotent_across_repeated_imports`, `it_keeps_orphan_equipe_as_its_own_bare_group`, partition 4.12) restent verts. L'ajout de `withPivot`/`sync()` associatif ne régresse ni l'union, ni l'idempotence, ni la déduplication. Aval (`ShareService`/`AclService`/UI/`UserPolicy`/listing blade) intact.
13. **Tests hôte verts** — Nouveaux tests couvrant : colonne + défaut (AC1), fusion 3→1 (AC2), GUID canonique + fallback (AC3), flag migration (AC4), idempotence (AC5), collision nom nu (AC6), orphelin (AC7), flag import + idempotence (AC8), multi-PP (AC9), retrait PP (AC10), CN non folded (AC11). 0 régression sur la suite ciblée `UserGroupService*`.

---

## Tasks / Subtasks

- [x] **T1 — Migration de schéma : colonne `is_head_teacher`** (AC1) — nouveau fichier `database/migrations/2026_06_25_120000_add_is_head_teacher_to_user_group_user.php`
  - [x] T1.1 `up()` : `Schema::table('user_group_user', fn($t) => $t->boolean('is_head_teacher')->default(false))` gardé par `Schema::hasColumn`. Ne PAS toucher la PK composite ni ajouter de timestamps.
  - [x] T1.2 `down()` : `dropColumn('is_head_teacher')` gardé par `Schema::hasColumn`.
  - [x] T1.3 Vérifier le rétro-remplissage du défaut sur lignes existantes (SQLite + PG). Validé : `migrate` full SQLite OK ; défaut `false` posé par le `boolean()->default(false)`.
- [x] **T2 — `withPivot('is_head_teacher')` sur la relation d'écriture** (AC4, AC8, D4) — `app/Models/UserGroup.php`
  - [x] T2.1 Ajouter `->withPivot('is_head_teacher')` sur `UserGroup::users()`, en conservant `->using(UserGroupUserPivot::class)`.
  - [x] T2.2 D4 retenu : `User::userGroups()`/`User::groups()` NON modifiées (aucune lecture aval du flag en 4.14 — 4.15 lira). Cast `is_head_teacher => boolean` ajouté sur `UserGroupUserPivot` pour une lecture fiable cross-driver. `$timestamps=false` inchangé (withPivot n'ajoute pas de timestamps).
- [x] **T3 — Migration de données : fusion des lignes héritées** (AC2, AC3, AC4, AC5, AC6, AC7) — logique extraite dans `app/Actions/Groups/MergeLegacyUserGroups.php` (action invocable testable, pure SQL cross-driver), appelée par la migration T1 après la création de colonne (ordre garanti).
  - [x] T3.1 Recenser les bases candidates : grouper les lignes `user_groups` préfixées (casse stricte `Classe_`/`Equipe_`/`PP_`) par `mb_strtolower(base)`, en incluant les lignes nues `<base>` de type classe/équipe préexistantes.
  - [x] T3.2 Pour chaque base : choisir la survivante (D1 : nue > Classe_ > Equipe_ > PP_) via `chooseSurvivor()`.
  - [x] T3.3 Reporter les pivots : `insertOrIgnore` des `(survivante_id, user_id)` depuis les lignes redondantes (union idempotente, PK compo).
  - [x] T3.4 Marquer `is_head_teacher=true` sur les arêtes de la survivante pour les membres de `PP_<base>` (AVANT suppression de PP_).
  - [x] T3.5 Renommer/promouvoir la survivante : `name=<base nu>`, `type='classe'`. Garde anti-collision (si la cible nue existe = c'est la survivante, pas un rename).
  - [x] T3.6 Supprimer les lignes redondantes (pivots cascade ; déjà reportés).
  - [x] T3.7 **`Equipe_` orphelin (D3)** : ligne préfixée isolée → `renameLonelyPrefixedRow()` (`Equipe_<Y>`→`Y` type `equipe`, pas de fusion ; `Classe_`/`PP_` isolé → type `classe`). Regex de strip dupliqué localement dans l'action (pas de dépendance au service LDAP).
  - [x] T3.8 Idempotence : pas de ligne préfixée → no-op ; `insertOrIgnore`/conditions d'existence. Validé AC5.
  - [x] T3.9 `down()` : drop colonne (T1.2) ; partie data NON ré-éclatée (convergence assumée, réimportable depuis l'AD) — no-op data documenté en commentaire dans la migration.
- [x] **T4 — Alimentation du flag à l'import (read-back)** (AC8, AC9, AC10, AC11, AC12) — `app/Services/UserGroupService.php` (boucle de fold)
  - [x] T4.1 Pour chaque entrée foldée, `$ppUserIds` = membres des `$cn` dont `foldPrefixOf($cn) === 'PP_'`.
  - [x] T4.2 Payload `sync()` associatif `[$userId => ['is_head_teacher' => isset($ppUserIds[$userId])]]` pour tous les `$userId` de l'union dédupliquée. Remplace le `sync()` scalaire.
  - [x] T4.3 Invariant union+dédup+idempotence conservé (clé = `user_id`) ; stats `linked_users`/`detached_users` via `attached`/`detached` inchangées.
  - [x] T4.4 CN standalone non-classe : `$ppUserIds` vide (pas de CN `PP_` dans `$folded['cns']`) → `is_head_teacher=false`, jamais `true`.
- [x] **T5 — Tests hôte** (AC13) — `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (read-back/flag import) + nouveau `tests/Feature/Migrations/MergeLegacyUserGroupsMigrationTest.php` (migration data)
  - [x] T5.1 Read-back flag : `it_marks_head_teacher_from_pp_cn_on_import` (AC8), `it_marks_multiple_head_teachers` (AC9), `it_clears_head_teacher_when_removed_from_pp` (AC10), `it_never_marks_head_teacher_on_non_class_cn` (AC11).
  - [x] T5.2 Idempotence read-back : `it_marks_head_teacher_idempotently_across_repeated_imports` (flags stables sur 2 runs).
  - [x] T5.3 Migration data : `MergeLegacyUserGroupsMigrationTest` (10 tests) monte les lignes héritées + pivots, invoque `MergeLegacyUserGroups`, vérifie fusion (AC2), GUID canonique + fallback (AC3), flag + multi-PP (AC4), idempotence + base déjà foldée (AC5), collision nue (AC6), orphelin equipe + idempotence orphelin (AC7). Logique extraite en action invocable (testabilité, cf. Dev Notes).
  - [x] T5.4 Non-régression : les 16 tests 4.13/4.12 restent verts (AC12). Le `is_head_teacher` du test ajouté à `createTestTables` (parité migration).
- [x] **T6 — Doc QA append-only** (AC13) — `docs/qa/domains/rights-management.md`
  - [x] T6.1 Nouvelle **Section 8** « Migration data (fusion lignes héritées) + `is_head_teacher` (Story 4.14) » : scénarios 8.1–8.8 + checklist pré-prod. Append-only (insérée après §7, avant Post-correctifs ; §1–7 non renumérotées).
  - [x] T6.2 Runbook migration VM différé post-merge (D5) : scénario 8.7 (`migrate:status` + `migrate` /vm + vérif SQL/`samba-tool`).
- [x] **T7 — Non-régression aval** (AC12) — aucune modification de `ShareService`/`AclService`/`UserPolicy`/listing blade/`syncRoleAwareAdGroupMembers`. `withPivot` n'introduit pas de timestamps (pivot custom 5.2 inchangé fonctionnellement). `UserPolicyResetPasswordScopedTest` + `UsersListingScopedTest` verts (17/17).

> **Limite connue (HORS SCOPE)** : l'écriture SQL→AD vers `PP_<X>` pilotée par le flag et l'UI « Professeur principal » sont le scope de **4.15**. 4.14 fournit la colonne + la migration data + l'alimentation read-back ; le flag est en place mais n'est encore **consommé** par aucune projection AD ni aucune UI.

---

## Dev Notes

### Ancrage code (chemins:lignes vérifiés 2026-06-25)

| Élément | Fichier:ligne | Rôle |
|---|---|---|
| Schéma pivot `user_group_user` (PK compo, pas de timestamps) | `database/migrations/2026_02_06_115500_create_rights_management_tables.php:70-81` | **Cible de la colonne** (T1) |
| Modèle pivot custom (incrementing=false, timestamps=false) | `app/Models/Pivot/UserGroupUserPivot.php:28-35` | Ajouter cast bool si lecture (T2.2) |
| Relation d'écriture du fold | `app/Models/UserGroup.php:55-63` (`users()`) | `->withPivot('is_head_teacher')` (T2.1) |
| Relations User vers pivot | `app/Models/User.php:112-120` (`userGroups`), `182-190` (`groups`) | D4 : ne pas élargir sans besoin |
| Boucle de fold (union + sync) | `app/Services/UserGroupService.php:430-439` | **Cœur read-back flag** (T4) — `sync()` associatif |
| `resolveMemberUserIdsFromAdGroup` | `app/Services/UserGroupService.php:734-758` | Réutilisé par CN PP_ pour `$ppUserIds` (T4.1) |
| `foldPrefixOf` (préfixe de fold ou null) | `app/Services/UserGroupService.php:691-` | Détecter `PP_` parmi `$folded['cns']` (T4.1) |
| `buildFoldedGroups` (entrées foldées + `cns[]`) | `app/Services/UserGroupService.php:575-` | `$folded['cns']` porte les CN à distinguer (T4) |
| `stripClasseLikePrefix` (4.12, privé) | `app/Services/UserGroupService.php:863-` | Base nue — dupliquer le regex dans la migration (T3) |
| `FOLD_PREFIXES` (ordre canonique D2) | `app/Services/UserGroupService.php:551` | Ordre survivante (T3.2) |
| Exemple migration fusion de pivot (4.11) | `database/migrations/2026_06_04_120000_unify_workstation_membership_pivot.php` | **Patron** : backfill `insertOrIgnore` cross-driver, JOIN, idempotence, `down()` (T3) |
| `syncRoleAwareAdGroupMembers` (écriture SQL→AD, 4.12) | `app/Services/UserGroupService.php:785-` | **NE PAS TOUCHER** (c'est 4.15) |
| Helper partagé scope classe (4.13) | `app/Models/User.php:138-172` (`classGroupNames`/`sharesClassGroupWith`) | Lit `type='classe'` — non impacté par le flag |
| Test compat (16 tests, fixtures AD) | `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` | Patron `makeService`/`adGroupRow`/`primeNoLdap` (T5) |
| Fixture union membres (réutiliser) | `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php:194-252` | Modèle pour T5.1/T5.2 |

### Pièges & points d'attention

- **PK composite + `sync()` associatif** : le pivot a `PK (user_group_id, user_id)`, `$incrementing=false`. Laravel `sync([$id => ['is_head_teacher' => true]])` fonctionne sur PK composite via la relation `BelongsToMany`. **Mais** `withPivot('is_head_teacher')` est **obligatoire** sur la relation, sinon Laravel ignore l'attribut au `sync()` (il ne le persiste pas). C'est le piège n°1.
- **`sync()` associatif vs scalaire et l'union 4.13** : 4.13 fait `sync(array_values(array_unique($memberIds)))`. En passant à `[$id => ['is_head_teacher' => …]]`, la **clé** doit être l'`user_id` (pas une valeur indexée). Construire le map après avoir dédupliqué l'union, sinon un `user_id` présent dans `Classe_` et `PP_` apparaîtrait deux fois — la version associative l'unifie naturellement (dernière clé gagne), mais s'assurer que la valeur retenue est `is_head_teacher=true` (un membre PP reste PP même s'il est aussi dans Classe_).
- **Cast booléen** : SQLite stocke les bool en 0/1, PG en `true/false`. Pour des assertions fiables (`assertTrue($pivot->is_head_teacher)`), ajouter `protected $casts = ['is_head_teacher' => 'boolean']` sur `UserGroupUserPivot` (T2.2) ou lire via `(bool)`. Sans cast, SQLite renvoie `"0"`/`"1"` (string) — piège classique des tests pivot.
- **Tester une migration** : les tests hôte utilisent `DatabaseTransactions` + migrations jouées au boot. Pour tester **la migration data** (fusion), deux approches : (a) un test qui insère les lignes héritées **après** que la migration colonne+fusion a tourné (donc rien à fusionner — inutile), ou (b) un test qui exécute la **logique de fusion** isolée. **Recommandé** : extraire la logique de fusion dans une **classe/action invocable testable** (ex. `app/Actions/...` ou méthode statique) appelée par la migration, et tester cette action sur un état monté à la main. Sinon, jouer `php artisan migrate:rollback`/`migrate` dans un test de migration dédié (lourd, sensible SQLite). **À trancher** par le dev — privilégier la testabilité (action invocable).
- **`stripClasseLikePrefix` est privé dans `UserGroupService`** : la migration ne doit PAS instancier le service (dépendances LDAP/repository). Dupliquer le regex de strip (`^(Classe_|Equipe_|PP_)` casse stricte) localement dans la migration/action, ou exposer un helper statique pur. Garder la **même casse** que 4.13 (préfixes `Classe_`/`Equipe_`/`PP_`, pas `classe_`).
- **Collision `name` unique** : `user_groups.name` est `UNIQUE`. Renommer une survivante `Classe_3A`→`3A` alors qu'une ligne `3A` existe lève une contrainte. La détection « ligne nue préexistante = survivante » (D1) évite ça : on ne renomme JAMAIS vers un `name` déjà pris ; on fusionne plutôt dans la ligne existante. Tester explicitement (AC6).
- **Ordre des opérations migration data** : (1) recenser, (2) choisir survivante, (3) reporter pivots `insertOrIgnore`, (4) marquer PP `is_head_teacher`, (5) renommer survivante, (6) supprimer redondantes. Marquer PP **avant** suppression de `PP_` (sinon on perd l'info de qui était PP). Le cascade `onDelete('cascade')` supprime les pivots des redondantes — pas grave, ils sont déjà reportés.
- **VM migrations pas auto-jouées** (`memory/project_vm_migrations_not_auto_applied.md`) : le dev-cycle ne migre que SQLite. La migration restera `Pending` sur `/vm`. L'exécution réelle est **post-merge, manuelle** (D5). Ne PAS lancer `migrate` sur la VM depuis le worktree (CLAUDE.md).
- **Idempotence read-back vs détache** : `sync()` détache les membres absents de l'union (comportement 4.13). En passant aux attributs d'arête, vérifier qu'un re-run sans changement renvoie `attached=[]`, `detached=[]`, `updated=[]` (ou `updated` ne contenant que les vraies bascules de flag). AC8 (2ᵉ run stable).

### Environnement de test (worktree — comme 4.13)

- **Tests sur l'HÔTE** (php 8.4 + sqlite + vendor), PAS sur la VM. Le worktree n'a pas de `vendor/` propre : suivre la procédure 4.12/4.13 (vendor reconstruit localement, `bootstrap/cache/` créé — tout gitignored). Lancer `vendor/bin/phpunit --filter UserGroupService` + le test de migration.
- **SQLite ne contraint pas `varchar`** (`memory/project_sqlite_tests_no_varchar_enforcement.md`) ; le défaut `boolean` est rétro-rempli par Laravel — tester explicitement (AC1/AC5). JSONB peut différer de PG mais non concerné ici.
- **Ne jamais sync/tester sur la VM depuis ce worktree** (CLAUDE.md). E2E `syncFromAd` réel + **exécution de la migration data sur PG** différés **post-merge** sur `main`/`/vm` ; runbook dans `docs/qa/domains/rights-management.md` Section 8 (append-only).
- Mocker `GroupRepository` (`getGroupsWithMemberCount`/`getGroupMembers`) comme `UserGroupServiceLegacyCompatibilityTest::makeService` ; `primeNoLdap()` pour court-circuiter LDAP (fallback `User.role`). Purger `User::$ldapCache` en `tearDown` si les tests touchent `isProf()`.

### Project Structure Notes

- **1 nouveau fichier de migration** : `database/migrations/2026_06_25_*_add_is_head_teacher_to_user_group_user.php` (colonne + fusion data ; ou colonne + action de fusion appelée). Préférer une migration unique (colonne d'abord, fusion ensuite — l'ordre est garanti dans `up()`).
- Si la logique de fusion est extraite pour la testabilité : `app/Actions/Groups/MergeLegacyUserGroups.php` (ou similaire) — classe pure invocable, sans dépendance LDAP. À trancher par le dev (cf. piège « tester une migration »).
- Changement service interne à `UserGroupService::syncFromAd` (boucle de fold) — pas de nouveau fichier de prod côté service.
- `withPivot` + cast sur `UserGroupUserPivot` — édition minimale.
- Doc QA append-only : `docs/qa/domains/rights-management.md` Section 8.
- **Pas de migration agent** (aucun fichier `agent/**` touché — pas de bump version).

### Dépendances (avec statut)

- **4.13 — `review` (worktree `user-groups`, branche `worktree-user-groups`), NON mergée.** 4.14 **dépend directement** du moteur de fold de 4.13 (`buildFoldedGroups`/`foldPrefixOf`/`FOLD_PREFIXES`/boucle `sync()` l.430-437). 4.14 doit être développée **sur la même base que 4.13** (continuer dans le worktree `user-groups` après merge de 4.13, ou rebaser). **Bloquant** : si 4.13 n'est pas présent, le code que 4.14 modifie n'existe pas. → Développer 4.14 **après** que 4.13 soit mergée/disponible dans la branche de travail. Statut à revérifier au lancement du dev.
- **4.12 — `review`.** Livré `syncRoleAwareAdGroupMembers`/`stripClasseLikePrefix` (réutilisés en lecture). Non bloquant pour 4.14 (4.14 ne touche pas l'écriture SQL→AD).
- **4.11 — `done`.** Patron de migration de fusion de pivot (`2026_06_04_120000_unify_workstation_membership_pivot.php`) réutilisé comme modèle (`insertOrIgnore` cross-driver, idempotence, `down()`).
- **4.15 (aval) — non créée.** Consommera le flag `is_head_teacher` (écriture SQL→AD `PP_` + UI). HORS SCOPE 4.14.

### Risques

- **R1 — Migration data destructive non rejouable.** Une fusion mal conditionnée pourrait supprimer une ligne sans avoir reporté ses pivots (perte de membres). Mitigation : ordre strict (report AVANT suppression), `insertOrIgnore`, tests d'idempotence + AC2 (aucun membre perdu). **Sévérité haute.**
- **R2 — Collision `name` UNIQUE** au rename de survivante. Mitigation : D1 (survivante = nue préexistante prioritaire), garde anti-collision, AC6. **Sévérité moyenne.**
- **R3 — `withPivot` régresse le pivot custom 5.2** (observer/timestamps). Mitigation : `withPivot` n'ajoute pas de timestamps ; cast bool ciblé ; D4 minimal ; AC12 non-régression. **Sévérité faible.**
- **R4 — `sync()` associatif casse l'union/idempotence 4.13.** Mitigation : clé = `user_id` dédupliqué, valeur PP-priorité ; AC8 idempotence ; anti-régression vérifié (T5.4). **Sévérité moyenne.**
- **R5 — Testabilité de la migration data** (jouer une migration dans un test SQLite est fragile). Mitigation : extraire en action invocable testable (piège documenté). **Sévérité moyenne.**
- **R6 — Exécution VM différée oubliée** : la colonne+fusion restent `Pending` sur PG → flag absent en prod, 4.15 sans données. Mitigation : runbook D5 explicite (T6.2), checklist pré-prod. **Sévérité moyenne (process).**

### Scénarios de validation (résumé exécutable)

| # | Scénario | Vérif | AC |
|---|---|---|---|
| V1 | `up()` ajoute colonne défaut false | `Schema::hasColumn` + arête existante = false | AC1 |
| V2 | 3 lignes `Classe_/Equipe_/PP_3A` → fusion | 1 ligne `3A` type classe, membres {alice,bob} | AC2 |
| V3 | GUID canonique `Classe_` + fallback `Equipe_` | `ad_guid` survivante = celui du CN prioritaire | AC3 |
| V4 | Flag PP en migration data | `(3A,bob).is_head_teacher=true`, alice=false | AC4 |
| V5 | Re-run migration data = no-op | aucun delta, pas d'exception | AC5 |
| V6 | Collision nue `3A` + reliquat `PP_3A` | 1 ligne `3A`, bob fusionné PP=true, pas de violation | AC6 |
| V7 | `Equipe_Maths5A` orphelin (cours) | renommé `Maths5A` type equipe, pas de fusion | AC7 |
| V8 | Flag PP à l'import + idempotence | bob PP=true, alice=false, stable sur 2 runs | AC8 |
| V9 | Multi-PP import | bob + carol PP=true | AC9 |
| V10 | Retrait PP import | bob membre, PP=false (suit AD) | AC10 |
| V11 | CN non folded sans flag | `Cours_*` PP=false toujours | AC11 |
| V12 | Non-régression 16 tests 4.13/4.12 | suite verte | AC12 |

### References

- [Source: app/Services/UserGroupService.php:430-439] — boucle de fold (union + `sync()`), point d'alimentation du flag (T4)
- [Source: app/Services/UserGroupService.php:575-758] — `buildFoldedGroups`/`foldPrefixOf`/`resolveMemberUserIdsFromAdGroup`/`stripClasseLikePrefix` (réutilisés)
- [Source: app/Models/UserGroup.php:55-63] — `users()` (ajout `withPivot`)
- [Source: app/Models/Pivot/UserGroupUserPivot.php] — pivot custom (cast bool éventuel)
- [Source: database/migrations/2026_02_06_115500_create_rights_management_tables.php:70-81] — schéma pivot (PK compo)
- [Source: database/migrations/2026_06_04_120000_unify_workstation_membership_pivot.php] — patron migration fusion de pivot (4.11)
- [Source: tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php:194-252] — fixtures fold (patron tests)
- [Source: _bmad-output/implementation-artifacts/4-13-fold-import-groupes-classe-nom-nu.md] — story amont (fold), section HORS SCOPE = handoff 4.14
- [Source: memory/project_usergroup_sql_fold_bare_name.md] — direction (flag d'arête, plusieurs PP/classe)
- [Source: memory/project_pivot_global_memberships.md] — modèle pivot global (invariants)
- [Source: memory/project_vm_migrations_not_auto_applied.md] — migrations VM non auto-jouées (D5)
- [Source: memory/project_sync_from_ad_transitional.md] — AD-first transitoire

### Previous Story Intelligence (4.13, en review)

- 4.13 a introduit le **grouper-avant-sync** (`buildFoldedGroups`) ; 4.14 enrichit l'arête lors du **seul `sync()` par ligne foldée** — ne pas réintroduire un sync par-CN (régresserait l'union 4.13).
- 4.13 a découvert que le filtre `onlyGroupNames` + la passe `deleted` doivent raisonner en **nom nu persisté** ; 4.14 n'y touche pas mais doit respecter cet invariant.
- 4.13 a recâblé `UserPolicy`/listing blade sur `User::classGroupNames()` (helper partagé). 4.14 n'altère pas ce helper (il lit `type='classe'`, indépendant du flag).
- Process 4.13 : tests hôte ciblés (`--filter`), e2e VM différé post-merge, doc QA append-only, anti-régression vérifié en réinjectant la logique cassée.

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (DEV).

### Debug Log References

- `vendor/bin/phpunit --filter UserGroupService tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` → 23 tests / 75 assertions OK (16 existants 4.13/4.12 + 7 nouveaux read-back/idempotence).
- `vendor/bin/phpunit tests/Feature/Migrations/MergeLegacyUserGroupsMigrationTest.php` → 10 tests / 32 assertions OK (fusion data).
- `vendor/bin/phpunit --filter "UserGroup|MergeLegacy"` → 41 tests / 123 assertions OK.
- `vendor/bin/phpunit --filter "UserPolicyResetPasswordScoped|UsersListingScoped"` → 17 tests / 30 assertions OK (non-régression aval scope prof).
- `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan migrate` → migration `2026_06_25_120000_…` jouée (DONE), colonne créée + fusion invoquée sans erreur (valide AC1 rétro-remplissage + boot de l'action sur DB propre).
- PRÉ-EXISTANT (hors scope, NON corrigé) : `BulkPasswordResetGroupsTest` → 4 errors. Cause : `GroupRepository::addGroup()` `$baseDn` null via `UserGroupAdSyncJob.php:56,83` (env LDAP absent en test). Identique au signalement 4.13 (vérifié git stash en 4.13) ; aucun fichier de ce chemin touché par 4.14.

### Completion Notes List

- **Schéma (T1)** : migration unique `2026_06_25_120000_add_is_head_teacher_to_user_group_user.php`. `up()` ajoute la colonne `boolean('is_head_teacher')->default(false)` (gardé `Schema::hasColumn`, PK composite et absence de timestamps préservées) PUIS invoque la fusion data — l'ordre garantit la colonne avant le report du flag PP. `down()` drop la colonne (idempotent) ; partie data non ré-éclatée (convergence assumée, réimportable depuis l'AD).
- **Migration data (T3)** : logique extraite dans `app/Actions/Groups/MergeLegacyUserGroups.php` (action invocable, pure SQL via façade `DB`, sans dépendance LDAP/service → testable sur état monté à la main, cf. piège « tester une migration » des Dev Notes). Ordre strict : repérer membres PP → reporter pivots (`insertOrIgnore`, union idempotente PK compo) → marquer PP `is_head_teacher=true` → promouvoir survivante (nom nu + type) → supprimer redondantes. Survivante D1 (nue préexistante > `Classe_` > `Equipe_` > `PP_`). Anti-collision `name` UNIQUE : la ligne nue préexistante est TOUJOURS la survivante (jamais de rename vers un nom pris). `Equipe_` orphelin (D3) : ligne préfixée isolée renommée au nom nu type `equipe` sans fusion (`renameLonelyPrefixedRow`, garde anti-collision incluse).
- **Read-back flag (T4)** : la boucle de fold de `syncFromAd` calcule `$ppUserIds` (membres des CN dont `foldPrefixOf===PP_`) en parallèle de l'union des membres, puis passe un `users()->sync()` ASSOCIATIF `[$userId => ['is_head_teacher' => isset($ppUserIds[$userId])]]`. La clé `user_id` garantit la dédup (membre dans Classe_ ET PP_ → une seule arête, PP-priorité). Les CN standalone non-classe n'ont jamais de CN `PP_` dans `$folded['cns']` → flag toujours `false`. Invariant union/dédup/idempotence 4.13 préservé (sync détache toujours les absents ; stats `attached`/`detached` inchangées).
- **withPivot (T2)** : `->withPivot('is_head_teacher')` ajouté UNIQUEMENT sur `UserGroup::users()` (relation d'écriture du fold) — sans lui Laravel ignore l'attribut au `sync()`. D4 : `User::userGroups()`/`groups()` non touchées (aucune lecture aval en 4.14 ; blast radius limité). Cast `is_head_teacher => boolean` sur `UserGroupUserPivot` (lecture fiable SQLite 0/1 vs PG true/false).
- **HORS SCOPE respecté** : `syncRoleAwareAdGroupMembers` (écriture SQL→AD) NON touché ; aucune UI « Professeur principal ». Le flag est posé en LECTURE, consommé par personne (→ 4.15).
- **VM (D5)** : la migration restera `Pending` sur /vm (migrations non auto-jouées par le dev-cycle). Exécution réelle = geste post-merge MANUEL (`migrate` + `migrate:status` /vm) — runbook QA §8.7. E2E `syncFromAd` réel (`samba-tool`) également différé post-merge (§8.8).

### File List

- `database/migrations/2026_06_25_120000_add_is_head_teacher_to_user_group_user.php` (créé) — colonne pivot + appel fusion data.
- `app/Actions/Groups/MergeLegacyUserGroups.php` (créé) — action invocable de fusion (pure SQL, testable).
- `app/Models/UserGroup.php` (modifié) — `->withPivot('is_head_teacher')` sur `users()`.
- `app/Models/Pivot/UserGroupUserPivot.php` (modifié) — cast `is_head_teacher => boolean`.
- `app/Services/UserGroupService.php` (modifié) — boucle de fold : `sync()` associatif read-back du flag PP.
- `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (modifié) — colonne `is_head_teacher` dans `createTestTables` + 7 tests read-back/idempotence + helper `isHeadTeacher()`.
- `tests/Feature/Migrations/MergeLegacyUserGroupsMigrationTest.php` (créé) — 10 tests de la fusion data.
- `docs/qa/domains/rights-management.md` (modifié) — Section 8 append-only (scénarios 8.1–8.8 + checklist).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modifié) — clé 4-14 → review.
- `_bmad-output/implementation-artifacts/4-14-migration-fusion-groupes-is-head-teacher.md` (modifié) — tasks cochées, Dev Agent Record, status review.

### Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-06-25 | 0.2 | Story IMPLÉMENTÉE (DEV opus). (1) Migration `2026_06_25_120000` : colonne `is_head_teacher` (bool défaut false) sur `user_group_user` + appel fusion data après création colonne. (2) Action invocable `MergeLegacyUserGroups` (pure SQL cross-driver, testable) : fusion lignes héritées `Classe_/Equipe_/PP_X`→1 ligne nue `X` — report pivots `insertOrIgnore` AVANT suppression (zéro perte), survivante D1, GUID canonique, flag PP, idempotente, anti-collision `name`, `Equipe_` orphelin renommé sans fusion (D3). (3) `syncFromAd` boucle de fold : `users()->sync()` associatif posant `is_head_teacher=true` sur les membres des CN `PP_`, union/dédup/idempotence 4.13 préservées. (4) `withPivot('is_head_teacher')` sur `UserGroup::users()` + cast bool pivot (D4 minimal). Tests hôte (php8.4+sqlite) : LegacyCompatibility 23/23 (16 existants + 7 read-back), MergeLegacyMigration 10/10, scope aval 17/17, migrate full SQLite OK. PRÉ-EXISTANT signalé : BulkPasswordResetGroupsTest 4 errors (LDAP `$baseDn` null via UserGroupAdSyncJob, hors scope). HORS SCOPE respecté (écriture SQL→AD PP_ + UI → 4.15). Migration VM + E2E syncFromAd réel différés post-merge (runbook QA §8.7/§8.8). | DEV (Opus 4.8 1M) |
| 2026-06-25 | 0.1 | Story CRÉÉE (SM). Scope strict « migration data + `is_head_teacher` » (direction Henri 2026-06-24, `memory/project_usergroup_sql_fold_bare_name.md`, handoff section HORS SCOPE de 4.13). 3 volets : (1) colonne pivot `is_head_teacher` bool défaut false ; (2) migration data fusion lignes héritées `Classe_/Equipe_/PP_X`→1 ligne nue `X` (report pivots `insertOrIgnore`, GUID canonique D1, flag PP, idempotente, collision nue gérée, orphelin equipe D3) ; (3) alimentation read-back du flag à l'import via `sync()` associatif sur la boucle de fold 4.13 + `withPivot`. HORS SCOPE : écriture SQL→AD `PP_` + UI (→4.15). Dépend de 4.13 (review, worktree user-groups) — bloquant. 13 AC, 7 tâches. Modèle recommandé : opus. | SM (Opus 4.8) |

---

## Recommandation Modèle Dev

**opus.**

Pourquoi : 4.14 combine deux sources de difficulté qui sont précisément les angles morts de sonnet. (1) Une **migration de données destructive** avec invariants subtils — report des pivots AVANT suppression sous peine de perte de membres (R1), choix déterministe de survivante + garde anti-collision sur un `name` UNIQUE (R2), idempotence rejouable cross-driver SQLite/PG, et la règle `Equipe_` orphelin (D3) reprise de 4.13. (2) Le passage du `sync()` scalaire de 4.13 à un `sync()` **associatif avec attribut d'arête** sur un pivot à PK composite + custom pivot 5.2, qui doit préserver exactement l'union/dédup/idempotence de 4.13 (R4) sans réintroduire un sync par-CN. Les pièges `withPivot` obligatoire, cast booléen SQLite, et la testabilité d'une migration data (R5) demandent de la rigueur sur des comportements Laravel non triviaux. opus pour la sûreté des invariants de fusion de pivots et la non-régression du moteur de fold livré en 4.13.
