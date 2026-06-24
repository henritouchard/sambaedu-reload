# Story 4.13 : Fold de l'import AD — une classe = une ligne `user_groups` au nom nu

Status: review

> **Type** : refactor ciblé du moteur de sync AD→SQL (parité conservée, AD-first). Pas de migration de données, pas d'UI, pas d'écriture SQL→AD.
>
> **Origine** : Epic 4 — gestion des groupes utilisateurs. 1ère des 3 stories de la refonte « groupes au nom nu » (4.13 fold import / 4.14 migration data + `is_head_teacher` / 4.15 écriture SQL→AD `PP_` + UI). Suite de 4.11 (pivot global livré) et 4.12 (peuplement `Equipe_X` par rôle livré).
>
> **Direction validée (Henri, 2026-06-24)** : une classe = **UNE** ligne SQL au **nom nu** (`3A`), au lieu des 3 lignes actuelles (`Classe_3A` / `Equipe_3A` / `PP_3A`). Les CN AD restent dérivés par convention ; le rôle reste dérivé de `User.role` (4.12). Le professeur principal (`is_head_teacher` sur l'arête) est **hors scope** (4.14/4.15).
>
> **Mémoire projet liée** : `memory/project_usergroup_sql_fold_bare_name.md` (direction + blast radius mesuré), `memory/project_pivot_global_memberships.md`, `memory/project_equipe_group_never_populated_se5.md` (4.12), `memory/project_sync_from_ad_transitional.md` (AD-first transitoire).

---

## Story

En tant que **responsable d'établissement et développeur SE5**,
je veux que l'import AD→SQL replie les variantes `Classe_X` / `Equipe_X` / `PP_X` d'une même classe en **une seule** ligne `user_groups` au **nom nu** (`X`), avec l'union des membres des 3 CN,
afin que l'UI de gestion des groupes présente une classe comme un objet unique (cohérent avec le modèle « 1 ligne SQL = 1 groupe logique ») et que les stories aval (4.14 migration data, 4.15 PP/UI) puissent s'appuyer sur ce nom nu canonique.

---

## Contexte & cause racine (lecture code 2026-06-24)

### Le modèle réel aujourd'hui

- **Le serveur expanse les CN AD.** `GroupRepository::createGroup` (`app/Repositories/GroupRepository.php:457-470`) : pour `type='classe'` **ou** `type='equipe'`, crée **3 groupes AD** — `Classe_X`, `Equipe_X`, `PP_X` (et pour `cours` : `Cours_X` + `Equipe_X`). C'est correct et conservé.
- **Mais l'import AD→SQL crée 1 ligne SQL par CN.** `UserGroupService::syncFromAd` (`app/Services/UserGroupService.php:240-403`) itère chaque CN éligible et fait un `UserGroup::create([...'name' => $groupName...])` (l.324-330). Pour une classe `3A`, AD contient `Classe_3A`, `Equipe_3A`, `PP_3A` → **3 lignes SQL** (`name = 'Classe_3A' | 'Equipe_3A' | 'PP_3A'`). C'est ce que prouve le test `it_creates_three_sql_groups_for_classe_like_legacy` (`tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php:59-114`, assertions l.106-114).
- **Conséquence** : l'UI groupes (`resources/views/pages/users/groups/`) liste 3 entrées par classe (`Classe_3A`, `Equipe_3A`, `PP_3A`) là où elle ne devrait en montrer qu'une (`3A`). Le `name` SQL stocké est le **CN primaire** (`Classe_3A`), pas le nom nu.

### Ce qui est DÉJÀ tolérant au nom nu (blast radius mesuré — ne pas re-préfixer en aval)

- **`ShareService`** : `bareClassName()` (`app/Services/Filesystem/ShareService.php:88-99`) dépouille `Classe_` (case-insensitive, idempotent sur nom nu) puis `resolveClassPath()` (l.134-143) re-préfixe une seule fois (`/var/sambaedu/Classes/Classe_<bare>`). Fonctionne identiquement que `$group->name` soit `Classe_3A` ou `3A`.
- **`GroupRepository::createGroup(name, type)`** re-préfixe par convention (l.457-470) à partir du nom **nu**.
- **`DrivesStateProvider`** : même tolérance (re-préfixe par convention).

→ **Le travail réel est concentré sur le moteur de sync import** (`syncFromAd` / `detectTypeFromAdGroupName`) + le lookup post-sync de `createGroup`/`updateGroup`. La couche aval n'a pas besoin de modification.

### Ce que 4.12 a déjà livré (point d'appui)

`syncRoleAwareAdGroupMembers()` (`UserGroupService.php:529-573`) + `stripClasseLikePrefix()` (l.607-616) + `guardReservedPrefixOnCreate()` (l.586-601) sont en place. **L'écriture** SQL→AD des membres (partition `isProf()` → `Equipe_`/`Classe_`) fonctionne déjà au nom nu. 4.13 aligne **la lecture** (import AD→SQL) sur le même nom nu — c'est le pendant read-back de 4.12.

---

## Scope précis (UNIQUEMENT « fold import + nom nu »)

1. **Fold dans `syncFromAd`** : lorsqu'un CN AD est `Classe_X` / `Equipe_X` / `PP_X`, le projeter sur UNE ligne SQL au nom nu `X` (strip de préfixe) au lieu d'une ligne par CN.
2. **Union des membres** : la ligne nue `X` reçoit l'UNION des membres résolus des 3 CN `Classe_X` + `Equipe_X` + `PP_X`.
3. **`ad_guid` / `ad_dn` canoniques** : la source canonique est le CN **`Classe_X`** (GUID + DN de `Classe_X`). Si `Classe_X` est absent d'AD mais `Equipe_X`/`PP_X` présents, retomber sur le premier CN disponible dans l'ordre `Classe_ → Equipe_ → PP_` (déterministe).
4. **Lookup nom nu dans `createGroup`/`updateGroup`** : le sélecteur SQL post-sync (`createGroup:84` `LOWER(name) = classe_x`) doit chercher le **nom nu** (`LOWER(name) = x`). Idem pour le retour de `updateGroup:131`.
5. **`detectTypeFromAdGroupName`** : un CN folded de classe/équipe résout `type='classe'` (un seul type pour la ligne foldée — cf. D3). Les autres CN (`Cours_`, `Projet_`, `Matiere_`, `Matiere_@`, rôle/fonction/custom) restent inchangés et **non foldés**.
6. **Cleanup `deleted`** : la passe de suppression (`syncFromAd:384-388`, `whereNotIn(LOWER(name), $detectedNames)`) doit comparer contre les **noms nus réellement persistés**, pas les CN bruts — sinon la ligne foldée `3A` serait supprimée à chaque sync (les CN bruts `classe_3a` ne sont plus des `name` SQL).
7. **Vérifier `LegacyParcBridgeService`** : aucune lecture de `user_groups` côté parc (le bridge porte sur `WorkstationGroup`/`parc.nom_parc`, cf. `app/Services/Legacy/LegacyParcBridgeService.php:43-46,360-373,443-447`). Confirmer l'absence d'impact ; **ne rien changer** si confirmé.
8. **Fixtures / tests impactés** : réécrire les assertions qui attendent 3 lignes SQL (`it_creates_three_sql_groups_for_classe_like_legacy`) et 2 lignes pour `cours` (`it_creates_two_sql_groups_for_cours_like_legacy`, qui expanse aussi `Equipe_`).

### AD-first conservé

L'import AD→SQL reste **l'outil de migration transitoire** (`memory/project_sync_from_ad_transitional.md`). 4.13 ne change PAS la direction : AD reste la source ; SQL le cache. On ne fait que **dé-dupliquer la projection** vers SQL.

---

## Décisions de cadrage (actées)

- **D1 — Fold de classe/équipe uniquement.** Seuls les CN `Classe_` / `Equipe_` / `PP_` d'une **même base** foldent en une ligne. `Cours_` reste sa propre ligne nue (`Maths5A`) ; son `Equipe_` co-créé **ne fold pas avec un `Cours_`** (bases identiques mais sémantiques distinctes côté AD : un `Cours_Maths5A` n'a pas de `Classe_Maths5A`). → règle : la base `Equipe_Y` fold avec `Classe_Y`/`PP_Y` **si et seulement si** un `Classe_Y` ou `PP_Y` existe dans le lot AD éligible, OU si la ligne nue `Y` existe déjà en SQL avec `type ∈ {classe, equipe}`. Sinon `Equipe_Y` reste une ligne nue de type `equipe`. **À trancher et tester** (cf. AC6, Dev Notes « ambiguïté Equipe_ orphelin »).
- **D2 — `ad_guid`/`ad_dn` canoniques = CN `Classe_`** (fallback déterministe `Equipe_` puis `PP_`). Garantit la stabilité de la résolution par GUID (`syncFromAd:291`) entre runs.
- **D3 — `type` de la ligne foldée = `classe`.** Une classe foldée porte `type='classe'` (pas `equipe`). Le rôle prof/élève reste dérivé de `User.role` (4.12), pas du type.
- **D4 — Nom nu = strip du préfixe primaire.** Réutiliser `stripClasseLikePrefix()` (déjà présent, 4.12). `Classe_3A` → `3A`, `Equipe_3A` → `3A`, `PP_3A` → `3A`.
- **D5 — Hors scope strict** : pas de colonne `is_head_teacher`, pas de migration de données fusionnant les lignes existantes, pas d'écriture SQL→AD vers `PP_`, pas d'UI « Professeur principal ». Ces points sont **4.14** (migration + colonne) et **4.15** (écriture PP + UI).

---

## Critères d'acceptation

1. **Fold create** — `createGroup(['name' => '3emeA', 'type' => 'classe', 'user_ids' => [alice(eleve)]])` : après l'expansion AD (3 CN) + `syncFromAd`, il existe **UNE seule** ligne `user_groups` de `name = '3emeA'` (nom nu), `type = 'classe'`. **Aucune** ligne `Classe_3emeA` / `Equipe_3emeA` / `PP_3emeA` en SQL. `createGroup` retourne cette ligne nue.
2. **Union des membres** — Sur AD `Classe_3A = {alice}`, `Equipe_3A = {bob}`, `PP_3A = {bob}` : après `syncFromAd`, la ligne nue `3A` a pour membres l'union `{alice, bob}` (dédupliquée). Aucun membre perdu, aucun doublon.
3. **`ad_guid` canonique** — La ligne nue `3A` porte le `ad_guid` et l'`ad_dn` du CN **`Classe_3A`**. Si `Classe_3A` est absent du lot AD mais `Equipe_3A` présent, elle porte le GUID/DN d'`Equipe_3A` (fallback déterministe). Aucun conflit `ad_guid` levé (`syncFromAd:303-317`) entre les 3 CN d'une même base.
4. **Lookup nom nu** — `createGroup` et `updateGroup` résolvent la ligne post-sync via `LOWER(name) = <nom nu>` ; ils ne lèvent plus « introuvable après synchronisation » (la ligne existe au nom nu). `updateGroup` charge bien la ligne foldée avec ses `users`.
5. **Idempotence import** — Deux `syncFromAd` consécutifs sur le même lot AD ne créent pas de doublon, ne suppriment pas la ligne foldée, et laissent les membres stables (la passe `deleted` ne touche pas `3A` ; cf. scope §6).
6. **`Equipe_` orphelin** — Un `Equipe_Y` sans `Classe_Y`/`PP_Y` dans le lot **et** sans ligne nue `Y` préexistante de type classe/équipe reste **sa propre ligne** (type `equipe`, nom nu `Y`) — il ne fold pas avec un `Cours_Y` (`it_creates_two_sql_groups_for_cours_like_legacy` : `Cours_Maths5A` → ligne nue `Maths5A`, `Equipe_Maths5A` → règle D1 à appliquer/tester).
7. **Types non foldés inchangés** — `Cours_`, `Projet_`, `Matiere_`, `Matiere_@` (matiere_classe), groupes de rôle/fonction/custom : `name` SQL et `type` inchangés vs comportement actuel (le `name` peut rester le CN ou le nom nu selon le comportement existant — **ne pas régresser** ; documenter le choix retenu). `Matiere_Math@3emeA` reste `name='Matiere_Math@3emeA'`, `type='matiere_classe'`.
8. **Exclusions préservées** — Les groupes de droits (`OU=Rights`), délégations (`OU=Delegations`) et hors `groups_rdn` restent exclus (`fetchEligibleAdGroups:408-473`) — non régressé.
9. **Aval non touché** — `ShareService::resolveClassPath` produit le bon path FS (`/var/sambaedu/Classes/Classe_3A`) que `$group->name` soit nu (`3A`) ou préfixé : aucune modification de `ShareService`/`AclService`. `LegacyParcBridgeService` confirmé sans dépendance à `user_groups` (aucun changement).
10. **Tests hôte verts** — Les tests de `UserGroupServiceLegacyCompatibilityTest` sont mis à jour (fold) et passent ; `it_partitions_members_by_role_between_equipe_and_classe` (4.12) reste vert ; nouveaux tests couvrant union des membres, GUID canonique, fallback GUID, idempotence, `Equipe_` orphelin. 0 régression sur la suite ciblée `UserGroupService*`.

---

## Tasks / Subtasks

- [x] **T1 — Fold dans `syncFromAd`** (AC1, AC2, AC3, AC5) — `app/Services/UserGroupService.php`
  - [x] T1.1 Avant la boucle, **grouper** les CN éligibles par clé de fold via le nouveau `buildFoldedGroups()` : CN `Classe_/Equipe_/PP_<base>` → clé `mb_strtolower(<base>)` ; autres CN → 1 CN = 1 projection (nom = CN brut). Réutilise `stripClasseLikePrefix()` + nouveau prédicat `foldPrefixOf()`.
  - [x] T1.2 **D1** (règle `Equipe_` orphelin) implémentée dans `shouldFold()` : `Equipe_Y` fold avec `Classe_Y`/`PP_Y` **uniquement si** une ancre `Classe_`/`PP_` est dans le lot (`$foldAnchorBases`) OU si une ligne nue `Y` (type classe/équipe) préexiste en SQL ; sinon `Equipe_Y` = ligne nue `Y` type `equipe` (nom NU, pas le CN).
  - [x] T1.3 CN canonique choisi dans l'ordre D2 (`Classe_` > `Equipe_` > `PP_`) → `name = <base nue>`, `ad_guid`/`ad_dn` du CN canonique, `display_name` = description du CN canonique (fallback nom nu).
  - [x] T1.4 Membres = **union** des `resolveMemberUserIdsFromAdGroup()` des CN du groupe foldé (`$folded['cns']`), dédupliquée, puis un **seul** `users()->sync(...)` par ligne.
  - [x] T1.5 Résolution upsert par GUID→name→DN conservée en visant le **nom nu** ; garde de conflit `ad_guid` conservée — ne se déclenche pas entre les 3 CN d'une même base (un seul GUID canonique écrit, une seule entrée foldée par base et par run).
- [x] **T2 — `detectTypeFromAdGroupName`** (AC1, AC7) : la ligne foldée porte `type='classe'` (D3), forcé dans `buildFoldedGroups()`. CN non foldés continuent d'utiliser `detectTypeFromAdGroupName` (inchangé). `Equipe_` orphelin = type `equipe`.
- [x] **T3 — Cleanup `deleted` au nom nu** (AC5) — `$detectedNames` collecte désormais les **noms nus effectivement persistés** (`$folded['name']`), pas les CN bruts. La ligne foldée `3A` survit à la passe de suppression (prouvé par `it_is_idempotent_across_repeated_imports`).
- [x] **T4 — Lookup nom nu dans create/update** (AC1, AC4) — nouveau helper `resolveSqlLookupName()` : pour classe/équipe → base nue (`stripClasseLikePrefix`) ; autres types → `resolvePrimaryGroupName` (CN, inchangé). Branché dans `createGroup` et `updateGroup`. `syncRoleAwareAdGroupMembers` (écriture membres, 4.12) NON touché.
- [x] **T5 — Vérif `LegacyParcBridgeService`** (AC9) — grep confirmé : **0** référence à `user_groups`/`UserGroup` (porte sur `WorkstationGroup`/`parc`). No-op, aucun changement.
- [x] **T6 — Tests hôte** (AC10) — `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php`
  - [x] T6.1 **Réécrit** `it_creates_three_sql_groups_for_classe_like_legacy` → `it_folds_classe_variants_into_one_bare_name_group` : UNE ligne `3emeA` type classe, aucune ligne préfixée, retour `createGroup` = ligne nue.
  - [x] T6.2 **Remplacé** `it_creates_two_sql_groups_for_cours_like_legacy` par `it_keeps_orphan_equipe_as_its_own_bare_group` (D1) : `Cours_Maths5A` (CN, type cours) + `Maths5A` (nom nu, type equipe) ; l'équipe orpheline ne fold pas avec le cours.
  - [x] T6.3 Nouveaux tests : `it_unions_members_across_folded_variants` (AC2), `it_uses_canonical_classe_guid_for_folded_group` (AC3), `it_falls_back_to_equipe_guid_when_classe_absent` (AC3 fallback), `it_is_idempotent_across_repeated_imports` (AC5).
  - [x] T6.4 `it_partitions_members_by_role_between_equipe_and_classe` (4.12), `it_creates_matiere_classe_group_with_legacy_naming`, `it_imports_ad_groups_with_legacy_type_detection_and_rights_exclusion` restent verts (CN non foldés gardent leur `name=CN`).
- [x] **T7 — Non-régression aval** (AC9) — aucune modification de `ShareService`/`AclService`/UI. `bareClassName`/`resolveClassPath` re-préfixent par convention et tolèrent le nom nu (vérifié par lecture, blast radius mesuré dans la story).

> **Limite connue (D5)** : les lignes SQL `Classe_X`/`Equipe_X`/`PP_X` **déjà présentes** sur une base existante ne sont PAS fusionnées par cette story (pas de migration de données). Sur une base greenfield ou après un `syncFromAd` complet, le fold s'applique naturellement. La **fusion des lignes héritées** + la colonne `is_head_teacher` sont le scope de **4.14**.

---

## Dev Notes

### Ancrage code (chemins:lignes vérifiés 2026-06-24)

| Élément | Fichier:ligne | Rôle |
|---|---|---|
| Moteur sync import (boucle, upsert, cleanup) | `app/Services/UserGroupService.php:240-403` | **Cœur du changement** (T1, T3) |
| `create` ligne SQL par CN | `app/Services/UserGroupService.php:324-330` | À remplacer par upsert au nom nu |
| Garde conflit `ad_guid` | `app/Services/UserGroupService.php:303-317` | Vérifier non-déclenchement intra-base |
| `users()->sync()` membres | `app/Services/UserGroupService.php:372` | Recevra l'union (T1.4) |
| Passe `deleted` | `app/Services/UserGroupService.php:383-388` | Comparer aux noms persistés (T3) |
| `resolveMemberUserIdsFromAdGroup` | `app/Services/UserGroupService.php:478-502` | Réutilisé par CN pour l'union |
| `detectTypeFromAdGroupName` | `app/Services/UserGroupService.php:719-754` | Type folde = classe (T2) |
| `createGroup` sélecteur post-sync | `app/Services/UserGroupService.php:75,84` | Lookup nom nu (T4) |
| `updateGroup` retour post-sync | `app/Services/UserGroupService.php:131` | Lookup nom nu (T4) |
| `stripClasseLikePrefix` (4.12) | `app/Services/UserGroupService.php:607-616` | **Réutiliser** pour la base nue |
| `syncRoleAwareAdGroupMembers` (4.12) | `app/Services/UserGroupService.php:529-573` | **Ne pas toucher** (écriture déjà au nom nu) |
| `guardReservedPrefixOnCreate` (4.12) | `app/Services/UserGroupService.php:586-601` | Inchangé |
| Expansion AD 3 CN | `app/Repositories/GroupRepository.php:457-470` | Inchangé (correct) |
| `ShareService::bareClassName` | `app/Services/Filesystem/ShareService.php:88-99` | Tolérant nom nu — **ne pas toucher** |
| `ShareService::resolveClassPath` | `app/Services/Filesystem/ShareService.php:134-143` | Re-préfixe par convention — **ne pas toucher** |
| LegacyParcBridge (parc, pas user_groups) | `app/Services/Legacy/LegacyParcBridgeService.php:43-46,360-373,443-447` | Confirmer no-op (T5) |
| Test à réécrire (3 lignes) | `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php:59-114` | Fold (T6.1) |
| Test cours (2 lignes) | `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php:118-144` | D1 (T6.2) |
| Test partition 4.12 (garder vert) | `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php:230-290` | Régression (T6.4) |

### Pièges & points d'attention

- **Ordre de traitement vs union de membres** : le code actuel traite chaque CN indépendamment et appelle `users()->sync()` par CN. Si on garde la boucle par CN sans grouper d'abord, un `sync()` sur `Equipe_3A` **écraserait** les membres déjà posés via `Classe_3A`. → **Grouper AVANT** (T1.1) puis un seul `sync()` par groupe folde avec l'union. Ne pas faire un `sync()` par CN sur la même ligne.
- **`detectTypeFromAdGroupName` mappe `Equipe_`/`PP_` → `equipe`** (l.729). Après fold sous le CN canonique `Classe_` → `classe` (l.725), la ligne porte `classe` (D3). S'assurer que c'est le type du CN **canonique** qui prime, pas l'ordre d'itération.
- **Garde conflit `ad_guid`** (l.303-317) : aujourd'hui chaque CN a son GUID → 3 lignes. Après fold, on n'écrit que le GUID canonique. La garde compare « autre ligne avec ce GUID » — elle ne doit jamais voir `Equipe_3A`/`PP_3A` (leurs GUID ne sont plus écrits). Vérifier qu'on ne tente pas d'upsert deux fois la même base dans le même run.
- **Cleanup `deleted`** : `$detectedNames` (l.285) push `mb_strtolower($groupName)` = le CN brut. Si on persiste désormais `3a` mais qu'on a poussé `classe_3a`/`equipe_3a`/`pp_3a` dans `$detectedNames`, la ligne `3a` sera dans `whereNotIn(...)` → **supprimée**. Pousser le nom **persisté** (nom nu) dans `$detectedNames` (T3).
- **`Equipe_` orphelin (D1)** : c'est l'ambiguïté centrale. Un `Equipe_Y` peut venir (a) d'une classe (`Classe_Y` présent) ou (b) d'un cours (`Cours_Y` + `Equipe_Y`, **pas** de `Classe_Y`). Le fold doit distinguer : ne replier `Equipe_Y` que si la base `Y` a un `Classe_`/`PP_` (ou une ligne nue classe/équipe préexistante). Sinon, `Equipe_Y` reste une ligne `equipe` distincte. Trancher en T1.2, tester en T6.2/T6.3.
- **`matiere_classe`** (`Matiere_X@Y`) : ne JAMAIS fold (le `@` le distingue). `detectTypeFromAdGroupName:721` le capte avant tout. `name` reste le CN.
- **AD-first** : ne pas écrire d'OU/CN nouveaux. Le fold est une projection **lecture** AD→SQL. L'écriture membres (4.12) reste séparée et déjà au nom nu.
- **Casse** : `stripClasseLikePrefix` est case-sensitive sur le préfixe (`Classe_`, pas `classe_`). Les CN AD réels sont `Classe_`/`Equipe_`/`PP_` (cf. `GroupRepository:459,464,469`). Le matching de fold doit suivre la même casse que la création.

### Environnement de test (worktree — comme 4.12)

- **Tests sur l'HÔTE** (php 8.4 + sqlite + vendor), pas sur la VM. Le worktree n'a pas de `vendor/` propre : suivre la procédure 4.12 (vendor reconstruit localement, `bootstrap/cache/` créé — tout gitignored). Lancer `vendor/bin/phpunit --filter UserGroupService`.
- **Ne jamais sync/tester sur la VM depuis ce worktree** (CLAUDE.md). E2E `syncFromAd` réel (samba-tool, getfacl) **différé post-merge** sur `main`/`/vm` ; documenter le runbook dans `docs/qa/domains/rights-management.md` (append-only).
- Mocker `GroupRepository` (`getGroupsWithMemberCount`/`getGroupMembers`) comme le fait déjà `UserGroupServiceLegacyCompatibilityTest::makeService` ; `primeNoLdap()` pour court-circuiter LDAP (fallback `User.role`).

### Project Structure Notes

- Aucun nouveau fichier de production attendu : le changement est interne à `UserGroupService`. Si un helper de fold devient volumineux, le garder **privé** dans `UserGroupService` (cohérent avec `stripClasseLikePrefix`/`syncRoleAwareAdGroupMembers`).
- Pas de migration de schéma (D5 — c'est 4.14).
- Doc QA append-only : `docs/qa/domains/rights-management.md` (section « fold import nom nu »).

### Dépendances aval (HORS SCOPE — ne pas implémenter, juste connaître)

- **4.14** : colonne `is_head_teacher` (bool) sur le pivot `user_group_user` + **migration de données** fusionnant les lignes SQL héritées `Classe_X`/`Equipe_X`/`PP_X` en une ligne nue `X` (avec report des pivots + `ad_guid` canonique). C'est elle qui traite l'existant ; 4.13 ne traite que la projection à venir.
- **4.15** : écriture SQL→AD ciblant `PP_` (pilotée par `is_head_teacher`) + UI « Professeur principal » dans l'edit-form du groupe.

### References

- [Source: app/Services/UserGroupService.php:240-403] — `syncFromAd`, moteur à folder
- [Source: app/Services/UserGroupService.php:529-616] — helpers 4.12 réutilisés (`syncRoleAwareAdGroupMembers`, `stripClasseLikePrefix`, `guardReservedPrefixOnCreate`)
- [Source: app/Services/UserGroupService.php:719-754] — `detectTypeFromAdGroupName`
- [Source: app/Repositories/GroupRepository.php:457-470] — expansion AD 3 CN (inchangée)
- [Source: app/Services/Filesystem/ShareService.php:88-143] — `bareClassName`/`resolveClassPath` (tolérance nom nu confirmée)
- [Source: app/Services/Legacy/LegacyParcBridgeService.php:43-46,443-447] — bridge parc (no-op user_groups)
- [Source: tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php:59-144,230-290] — tests à réécrire/garder
- [Source: _bmad-output/implementation-artifacts/4-12-peuplement-equipe-x-par-role.md] — story précédente (écriture nom nu, point d'appui)
- [Source: memory/project_usergroup_sql_fold_bare_name.md] — direction + blast radius mesuré
- [Source: memory/project_sync_from_ad_transitional.md] — AD-first transitoire

### Previous Story Intelligence (4.12, en review)

- 4.12 a livré l'**écriture** au nom nu (`syncRoleAwareAdGroupMembers` + `stripClasseLikePrefix`). 4.13 est le pendant **lecture** : aligner l'import AD→SQL sur ce même nom nu. Réutiliser `stripClasseLikePrefix` plutôt que ré-implémenter.
- 4.12 a découvert le piège « CN AD fantôme » → `guardReservedPrefixOnCreate`. 4.13 ne crée pas d'AD ; pas de risque équivalent, mais garder la cohérence nom-nu↔CN.
- Process 4.12 : tests hôte ciblés (`--filter`), e2e VM différé post-merge, doc QA append-only, purge `User::$ldapCache` en `tearDown` si tests touchent `isProf()`.

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Dev Agent BMAD, worktree `user-groups`, branche `worktree-user-groups`).

### Debug Log References

- Baseline avant dev : `vendor/bin/phpunit --filter UserGroupServiceLegacyCompatibilityTest` → 12 tests / 44 assertions OK.
- Après fold : `vendor/bin/phpunit --filter UserGroupServiceLegacyCompatibilityTest` → **16 tests / 56 assertions OK**.
- `tests/Feature/Console/SyncFromAdImportCommandTest.php` + `SyncUsersFromAdCommandTest.php` → 13 tests / 37 assertions OK (0 régression).
- `tests/Feature/Users/BulkPasswordResetGroupsTest.php` → 4 ERRORS **PRÉ-EXISTANTES** (vérifié via `git stash` sur HEAD propre : mêmes 4 erreurs `GroupRepository::addGroup(): $baseDn null` via `UserGroupAdSyncJob` — environnement LDAP non configuré, sans rapport avec 4.13).

### Completion Notes List

- **Architecture du fold** : introduit `buildFoldedGroups(array $eligibleGroups)` qui regroupe les CN **AVANT** toute écriture (piège #1 de la story : grouper-avant-sync). Chaque entrée foldée porte `name` (nom nu), `cns` (liste des CN à unir), `ad_guid`/`ad_dn` canoniques, `type`, `display_name`. La boucle de `syncFromAd` itère désormais sur les entrées foldées (1 upsert + 1 `users()->sync()` de l'union par entrée).
- **Helpers privés ajoutés** : `buildFoldedGroups()`, `foldPrefixOf()` (préfixe de fold ou null, casse stricte `Classe_`/`Equipe_`/`PP_`), `shouldFold()` (D1), `resolveSqlLookupName()` (lookup post-sync nom nu pour classe/équipe, CN sinon). Constante `FOLD_PREFIXES` (ordre canonique D2).
- **D2 — GUID canonique** : le CN canonique est le premier disponible dans l'ordre `Classe_` > `Equipe_` > `PP_`. Fallback déterministe prouvé par `it_falls_back_to_equipe_guid_when_classe_absent`. Un seul GUID écrit par base → la garde de conflit `ad_guid` ne se déclenche jamais entre les variantes (elles ne sont plus 3 lignes distinctes).
- **D1 — `Equipe_` orphelin** : `shouldFold()` fold `Equipe_Y` seulement si une ancre `Classe_`/`PP_` est présente dans le lot (pré-calcul `$foldAnchorBases`) OU si une ligne nue `Y` classe/équipe préexiste en SQL. **Décision d'implémentation** : un `Equipe_` orphelin devient sa propre ligne **au nom nu** `Y` (type `equipe`), conformément à l'AC6 (« reste sa propre ligne, type equipe, **nom nu Y** ») — pas le CN brut. Il ne fold donc pas avec un `Cours_Y` (qui garde son CN). Couvert par `it_keeps_orphan_equipe_as_its_own_bare_group`.
- **T3 — passe `deleted`** : `$detectedNames` collecte `$folded['name']` (nom nu persisté). La ligne foldée survit au sweep (piège #2 : sinon `classe_3a`/`equipe_3a` pollueraient `whereNotIn` et `3a` serait purgée). Idempotence prouvée par double-import.
- **T4 — lookup** : `resolvePrimaryGroupName` n'est plus utilisé directement comme sélecteur post-sync ; il reste utilisé par `resolveSqlLookupName` pour les types non classe/équipe et par `syncRoleAwareAdGroupMembers` (inchangé). Le lookup classe/équipe vise désormais la base nue.
- **T5 — `LegacyParcBridgeService`** : no-op confirmé (0 référence `user_groups`). Aucun changement.
- **Aval** : `ShareService`/`AclService`/UI/`GroupRepository`/`DrivesStateProvider` intacts (tolérance nom nu mesurée dans la story, re-préfixe par convention).
- **Hors scope respecté** : aucune migration de schéma, pas de colonne `is_head_teacher`, pas d'écriture SQL→AD `PP_`, pas d'UI. `syncRoleAwareAdGroupMembers` (4.12) non modifié.
- **E2E /vm différé** post-merge (runbook `docs/qa/domains/rights-management.md` §7, append-only).

### Corrections post-review (2026-06-24)

**Cadrage tranché par Henri** : **Q1 = tout corriger DANS 4.13** (étendre le blast-radius, réparer les 4 consommateurs cassés par le fold) ; **Q2 = source de vérité partition prof/élève = dérivation par `User.role`** (cohérent 4.12). Post-fold, prof ET élève sont co-membres de la MÊME ligne nue `X` (`type='classe'`) ; la distinction de rôle vient de `User.role`, pas du nom du groupe. La résolution classe↔partage est **factorisée dans UN helper partagé** réutilisé par la policy ET la blade.

**Emplacement du helper partagé** : `app/Models/User.php` — `classGroupNames(): Collection` (noms nus des classes `type='classe'` dont l'utilisateur est membre) et `sharesClassGroupWith(User $other): bool` (intersection). Choisi sur le modèle `User` car la policy ET le listing blade opèrent tous deux sur des instances `User` ; pas de copie divergente.

| # | Sévérité | Statut | Correctif |
|---|---|---|---|
| #1 | 🔴 | Corrigé | `syncFromAd` : le filtre `onlyGroupNames` matche désormais chaque CN AD sur sa **base nue** (via `foldPrefixOf`/`stripClasseLikePrefix`) en plus du CN brut. `syncGroupsWithAd` (bouton « Synchroniser avec AD ») refonctionne. Passe `deleted` non impactée (sync ciblée ne supprime pas). |
| #2 | 🔴 | Corrigé | `UserService::persistUserGroupsToSql` : lookup classe par **NOM NU** (`$classes`) + fallback `Classe_<c>` (lignes héritées) + garde `whereIn('type', ['classe','class'])`. `oldClassIds` inchangé (capte la ligne nue via `type='classe'`). Branche catégorie/fonction intacte. |
| #3 | 🔴 | Corrigé | `UserPolicy::sharesClassWithTarget` recâblé sur `User::sharesClassGroupWith()`. Logique vestige `Equipe_%`/`PP_%` + reconstruction `'Classe_'.X` supprimée (renvoyait un ensemble vide post-fold → déni total). Scope toujours strict (prof voit/reset uniquement SES élèves). |
| #4+#5 | 🔴/🟠 | Corrigé | `shouldFold` : décision sur le **seul lot AD courant** (`$foldAnchorBases`), suppression de la requête `EXISTS` sur l'état SQL. Corrige l'idempotence (un `Equipe_` orphelin ne bascule plus `equipe`→`classe` au 2e run, AC6) ET la perf (plus de N requêtes). |
| #6 | 🟡 | Corrigé | Stats : `total_cn_detected` (CN bruts) distinct de `total_groups_folded` (lignes projetées). `total_groups_detected` conservé en alias compat. Log reformulé « N CN → M groupes après fold ». |
| #11 | 🔴 | Corrigé | `resources/views/pages/users/index.blade.php` : scope listing prof/eleve-admin recâblé sur `User::classGroupNames()` (même helper partagé que la policy). Plus de `whereRaw('1=0')` faux-positif post-fold. |
| #7 | test | Ajouté | `it_targets_folded_bare_names_when_syncing_selected_groups` — détecte le no-op #1 (vérifié : échoue avec le filtre CN-only). |
| #8 | test | Ajouté | `it_attaches_student_to_folded_bare_name_class` — rattachement élève→classe nue (vérifié : échoue avec le lookup `Classe_`-only). |
| #9 | test | Ajouté | `it_keeps_orphan_equipe_stable_type_across_repeated_imports` — type `equipe` stable sur 2 runs (détecte #4). |
| #10 | test | Corrigé | `UserPolicyResetPasswordScopedTest` + `UsersListingScopedTest` : fixtures réécrites au modèle post-fold (prof+élève co-membres d'UNE ligne nue `type=classe`). Vérifié : 6 tests échouent si la logique vestige est réinjectée → le bug #3/#11 est désormais capturé. |

**Validation des tests anti-régression** : pour #7, #8 et #10, j'ai réinjecté temporairement la logique cassée et confirmé que les nouveaux/réécrits tests **échouent** (puis restauré). Ils ne sont donc pas des oracles vides.

**Runbook QA** : scénario manuel **7.6** ajouté (append-only) — « non-régression scope prof post-fold » : un prof rattaché à `3A` voit/reset uniquement ses élèves de `3A` (ni tous, ni zéro), bulk filtré, eleve-admin idem, admin global bypass. C'est exactement le bug manqué par les fixtures pré-fold mais détectable en manuel sur données importées.

### File List

- `app/Services/UserGroupService.php` (modifié) — `syncFromAd` itère sur les groupes foldés ; nouveaux helpers `buildFoldedGroups`/`foldPrefixOf`/`shouldFold`/`resolveSqlLookupName` ; lookup post-sync nom nu dans `createGroup`/`updateGroup` ; constante `FOLD_PREFIXES`. **Post-review** : filtre `onlyGroupNames` matche aussi la base nue (#1) ; `shouldFold` purgé de l'`EXISTS` SQL → décision sur le seul lot AD (#4/#5) ; stats `total_cn_detected`/`total_groups_folded` (#6).
- `app/Models/User.php` (modifié, **post-review**) — **helper PARTAGÉ factorisé** `classGroupNames()` (noms nus des classes `type='classe'` de l'utilisateur) + `sharesClassGroupWith(User)` (intersection). Réutilisés par la policy ET le listing blade (#3/#11).
- `app/Policies/UserPolicy.php` (modifié, **post-review**) — `sharesClassWithTarget` recâblé sur `User::sharesClassGroupWith()`, logique vestige `Equipe_%`/`PP_%` supprimée (#3).
- `resources/views/pages/users/index.blade.php` (modifié, **post-review**) — scope listing prof/eleve-admin recâblé sur `User::classGroupNames()` (#11).
- `app/Services/UserService.php` (modifié, **post-review**) — `persistUserGroupsToSql` : lookup classe par NOM NU + fallback `Classe_<c>` (lignes héritées) + garde `type in (classe,class)` ; rattachement élève→classe foldée rétabli (#2).
- `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (modifié) — réécritures de fold (cf. ci-dessus) + **post-review** : `it_keeps_orphan_equipe_stable_type_across_repeated_imports` (#9), `it_targets_folded_bare_names_when_syncing_selected_groups` (#7).
- `tests/Feature/Policies/UserPolicyResetPasswordScopedTest.php` (modifié, **post-review #10**) — fixtures réécrites au modèle post-fold (prof+élève co-membres d'UNE ligne nue `type=classe`) ; détectent désormais une régression de scope.
- `tests/Feature/Livewire/UsersListingScopedTest.php` (modifié, **post-review #10**) — idem, fixtures nom-nu.
- `tests/Feature/Services/UserServiceClassChangeTest.php` (modifié, **post-review #8**) — ajout `it_attaches_student_to_folded_bare_name_class` (rattachement au nom nu).
- `docs/qa/domains/rights-management.md` (modifié, append-only) — Section 7 « Fold import AD→SQL au nom nu (Story 4.13) » : 5 scénarios initiaux + **scénario 7.6 post-review** (non-régression scope prof post-fold, manuel) + checklist.
- `_bmad-output/implementation-artifacts/4-13-fold-import-groupes-classe-nom-nu.md` (modifié) — checkboxes, Dev Agent Record, status → review.
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modifié) — `4-13-fold-import-groupes-classe-nom-nu` → review.

### Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-06-24 | 0.3 | **Corrections post-review** (DEV opus, worktree). Cadrage Henri : Q1=tout-dans-4.13, Q2=`User.role` + helper partagé. Recâblage scope prof post-fold (#3 policy + #11 blade) via helper factorisé `User::classGroupNames()`/`sharesClassGroupWith()` ; `syncGroupsWithAd` no-op corrigé (#1) ; rattachement élève→classe nue dans `UserService` (#2) ; `shouldFold` idempotent sans `EXISTS` SQL (#4/#5) ; stats `total_cn_detected`/`total_groups_folded` (#6). Tests : fixtures policy/listing réécrites au modèle post-fold (#10), + tests `syncGroupsWithAd` (#7), orphelin 2-runs (#9), persist nom-nu (#8). Runbook §7.6 manuel ajouté. Tests hôte ciblés VERTS (46 + 13 console). Anti-régression #7/#8/#10 vérifiés (échouent sans le fix). | DEV (Opus 4.8) |
| 2026-06-24 | 0.2 | Story IMPLÉMENTÉE (DEV opus, worktree user-groups) — fold import via `buildFoldedGroups` (grouper-avant-sync, union membres, GUID canonique `Classe_`+fallback `Equipe_`/`PP_`, type=classe D3) ; D1 `Equipe_` orphelin → ligne nue type equipe (`shouldFold`) ; passe `deleted` au nom nu persisté ; lookup nom nu create/update (`resolveSqlLookupName`). Aval intact. 16 tests verts (56 assertions). Status → review. |
| 2026-06-24 | 0.1 | Story CRÉÉE (SM, branche worktree-user-groups) — fold import AD→SQL des variantes `Classe_`/`Equipe_`/`PP_` en une ligne `user_groups` au nom nu + union membres + GUID canonique `Classe_` + lookup nom nu create/update. Scope strict « fold import + nom nu » ; migration data (4.14) et écriture PP/UI (4.15) hors scope. Ancrée sur lecture code 2026-06-24. | SM (Opus 4.8) |

---

## Recommandation Modèle Dev

**opus.**

Pourquoi : le cœur du changement est un **refactor du moteur de sync** avec plusieurs invariants subtils qui interagissent — (1) grouper-avant-sync pour ne pas écraser les membres entre CN, (2) GUID canonique + fallback déterministe sans déclencher la garde de conflit, (3) la passe `deleted` qui supprime silencieusement la ligne foldée si `$detectedNames` n'est pas aligné, et surtout (4) l'**ambiguïté `Equipe_` orphelin (D1)** qui distingue une classe d'un cours sur la seule présence d'autres CN dans le lot. Ce sont exactement les cas où sonnet tend à produire un fold naïf par-CN qui régresse l'union des membres ou efface la ligne foldée. La surface de test est large (réécriture d'assertions historiques + 5 nouveaux scénarios cross-cas). opus pour la rigueur sur les invariants et la non-régression de la couche aval (4.11/4.12).
