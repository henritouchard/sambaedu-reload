# Story 42.2 : Projection AD des memberships depuis les arêtes (remplace la partition 4.12)

Status: review

> **Type** : bascule du canal d'écriture SQL→AD — le routage des membres vers le trio legacy `Classe_X`/`Equipe_X`/`PP_X` passe des DEUX heuristiques actuelles (partition `User::isProf()` — 4.12 ; flag d'arête `is_head_teacher` → `PP_` — 4.15) au **rôle d'arête** `user_group_user.role` (42.1). AUCUNE migration de schéma. AUCUNE modification de `ShareService`/`AclService` (FR-S6). Pas d'UI nouvelle (colonne rôle éditable = 42.3).
>
> **Origine** : Epic 42 — Socle rôle sur l'arête user↔groupe (`_bmad-output/planning-artifacts/epics-socle-role-groupes.md`, décision Henri 2026-07-07, `docs/group-model-multivertical-orientation.md` DÉCIDÉ). **2ᵉ des 4 stories** ; amont **42.1 (review approuvée, code sur main)** ; **BLOQUANTE pour 42.3 et 42.4**.
>
> **Direction** : `member`→`Classe_<base>`, `manager`→`Equipe_<base>`, `owner`→`Equipe_<base>` **ET** `PP_<base>` (nommage legacy strict — le générique `grp__` attend la phase profils, il n'existe nulle part dans l'AD). La mécanique de noms (SQL nu ↔ CN préfixé via `resolvePrimaryGroupName`/`stripClasseLikePrefix`) est **INTACTE** — cette story ne remplace QUE le routage. Corrige définitivement « `Equipe_X` vide » et rend `PP_X` peuplable depuis les arêtes.
>
> **Mémoire projet liée** : `project_group_model_multivertical_direction.md`, `project_ad_federated_root_gpos.md` (jamais la racine), `project_ad_sync_resolve_by_guid.md` (matching GUID), `project_acl_equipe_group_missing_etab_suffix.md` (suffixe étab = sAMAccountName, pas le CN), `project_state_precedence_logical_over_physical.md` (le logique prime — ici : l'arête prime sur l'heuristique globale), `project_isprof_iseleve_ldap_first_cost.md` (JAMAIS de round-trip LDAP par user), `project_equipe_group_never_populated_se5.md`, `project_sync_from_ad_transitional.md` (AD-first transitoire).

---

## Story

En tant que **responsable d'établissement (et développeur SE5)**,
je veux **que la projection AD des membres d'un groupe classe/équipe soit routée par le rôle porté sur chaque arête (`member`/`manager`/`owner`) et resynchronisée à chaque changement d'arête**,
afin que la source de vérité du rôle soit la relation elle-même (plus l'heuristique globale `isProf()` ni le flag mono-usage `is_head_teacher`), que le `rwx` prof reste effectif à parité SE4 (greenfield ET brownfield), et que 42.3 (édition du rôle en UI) et 42.4 (read-back des rôles) puissent s'appuyer sur un canal de projection unique.

---

## Périmètre STRICT — bascule ATOMIQUE

**Dans le scope** : routage par arêtes dans `syncRoleAwareAdGroupMembers` (suppression de la partition `isProf()` ET de toute lecture `is_head_teacher`), bascule de la dérivation `$headTeacherUserIds` d'`updateGroup` sur `role='owner'`, ancrage du resync sur changement de rôle (observer pivot `updated`), **suppression de l'écriture miroir `is_head_teacher`** sur le chemin vivant (`projectFoldedGroup`), tests de parité FR-S6/NFR-S2, adaptation des tests 42.1 d'invariant miroir.

**Bascule ATOMIQUE** : à aucun moment il n'existe d'état où NI les heuristiques NI les arêtes ne peuplent le trio. Concrètement : une seule story, un seul merge — la partition `isProf()` et la lecture `is_head_teacher` sont retirées DANS LE MÊME diff qui branche le routage par arêtes, et le backfill 42.1 (déjà joué en migration) garantit que toute arête existante porte un rôle cohérent avec l'heuristique qu'elle remplace.

**HORS SCOPE** (stories aval / hors story) :
- **42.3** : colonne « Rôle » éditable en UI, resync à l'édition depuis les pages groupes.
- **42.4** : read-back des RÔLES depuis le trio AD (dérivation `Equipe_`-consciente, précédence `owner`>`manager`>`member`, données sales lab1). La dérivation heuristique de `projectFoldedGroup` (owner depuis CN `PP_`, sinon `defaultRoleForGlobalRole(users.role)`) reste EN L'ÉTAT ici — limite transitoire documentée en 42.1 AC7, levée en 42.4.
- **Migration destructive `dropColumn('is_head_teacher')`** : HORS story (décision D5 ci-dessous) — la colonne, son cast et le `withPivot` restent ; seule l'écriture miroir du chemin vivant est retirée.
- Toute modification de `ShareService`/`AclService`/`UserPolicy`, de la couche LDAP (`GroupRepository`), du fold 4.13 (`buildFoldedGroups`/`foldPrefixOf`), de `resolvePrimaryGroupName`/`stripClasseLikePrefix`/`resolveSqlLookupName`/`guardReservedPrefixOnCreate`.

---

## Décisions de cadrage (ACTÉES — ne pas rouvrir sans signal contraire)

- **D1 — Routage par rôle d'arête** : pour `type ∈ {classe, equipe}` : `Equipe_<base>` = arêtes `manager` ∪ `owner` (un `owner` est un cas particulier de prof — il ne sort JAMAIS du bucket équipe, sémantique orthogonale 4.15 conservée) ; `Classe_<base>` = arêtes `member` ; `PP_<base>` = arêtes `owner`. Les 3 cibles sont TOUJOURS synchronisées (même bucket vide → vidage par le diff idempotent, pas de rémanence). Types non classe-like : cible unique via `resolvePrimaryGroupName`, comportement STRICTEMENT inchangé (le rôle n'y route rien).
- **D2 — Résolution du rôle effectif à la projection** (algorithme, dans cet ordre de précédence, pour chaque `$userId ∈ $selectedUserIds`) :
  1. `owner` si `$userId ∈ ($headTeacherUserIds ∩ $selectedUserIds)` — le paramètre PP reste **autoritaire** (canal de désignation 4.15) ;
  2. sinon rôle de l'**arête** (`$edgeRolesByUserId[$userId]`) si elle existe — avec **rétrogradation de projection** `owner`→`manager` pour un ex-PP décoché (son arête dit encore `owner` jusqu'au read-back : il doit sortir de `PP_` mais rester dans `Equipe_`) ;
  3. sinon (membre du payload SANS arête — nouvel ajout, l'arête ne sera créée que par le read-back) : `UserGroupUserPivot::defaultRoleForGlobalRole(users.role)` — `users.role` résolu **EN UNE SEULE requête** pour tous les manquants (jamais `isProf()` : round-trip LDAP interdit).
  Valeur d'arête HORS vocabulaire (`ROLES`) : fallback rôle dérivé + `Log::warning` — la projection est fail-soft, **pas d'exception** (ne pas câbler `assertValidRole` en levée ici).
- **D3 — Chaîne `head_teacher_ids` CONSERVÉE, lecture `is_head_teacher` SUPPRIMÉE.** 42.1 annonçait la suppression de « colonne + miroir + chaîne `head_teacher_ids` » ; précision actée ici : le **payload** `head_teacher_ids` (UI `head-teacher-section` → `createGroup`/`updateGroup` → override `owner`) reste le canal de désignation PP jusqu'à 42.3 (qui décidera de son remplacement par l'édition de rôle générique). Ce qui disparaît DANS CETTE STORY : toute **lecture** de `is_head_teacher` (dérivation `updateGroup` l.179-185 → `wherePivot('role', ROLE_OWNER)`) et toute **écriture miroir** sur le chemin vivant.
- **D4 — Ancrage resync = observer pivot `updated()`**, PAS les événements created/deleted. Justification : chaque canal d'attach/detach existant a DÉJÀ sa projection AD (`createGroup`/`updateGroup` = appel explicite ; drawer groupes = écrit l'AD lui-même ; imports = AD-first, une projection y ré-écrirait l'AD depuis l'AD). Le **changement de rôle** est la dimension NOUVELLE : c'est un UPDATE de pivot, invisible des handlers actuels. Implémentation : handler `updated(UserGroupUserPivot $pivot)` filtré `$pivot->wasChanged('role')`, groupe de type classe-like uniquement, **fail-soft** (pattern `dispatch()` existant : catch Throwable + Log::error, jamais propagé), qui reprojette LE groupe concerné. **Suspension obligatoire pendant `syncFromAd`** (flag statique DÉDIÉ, ex. `$adResyncEnabled` + `disableAdResync()`/`enableAdResync()`, posé autour de la transaction de `syncFromAd` l.418-470, dans le même try/finally que `UserGroupObserver::disableSync()`) : le read-back met à jour des rôles en masse (heuristique) — sans suspension, chaque flip déclencherait une reprojection LDAP par arête (import ~600 groupes lab1 = tempête d'I/O, et écrire l'AD PENDANT qu'on le lit est conceptuellement faux). NE PAS réutiliser `$syncEnabled` (il gouverne la synchro FS `ShareService`, qui DOIT continuer à tourner au read-back). Le flag `$syncEnabled=false` (imports users) suspend AUSSI le resync AD (guard commun en tête de handler).
- **D5 — Sort du miroir `is_head_teacher`** : l'écriture miroir est retirée du SEUL chemin vivant, `projectFoldedGroup` (payload sync l.618-629 → `['role' => …]` seul). `MergeLegacyUserGroups` et `BackfillUserGroupUserRoles` sont **CONSERVÉS TELS QUELS** : actions one-shot legacy/migration, qui doivent rester correctes sur une base pré-42.1 (gardes `hasColumn` en place) — leurs écritures/lectures `is_head_teacher` sont des vestiges inoffensifs documentés. La colonne, le cast `boolean` et le `withPivot('is_head_teacher')` de `UserGroup::users()` RESTENT (fixtures de tests, bases brownfield) ; la migration destructive `dropColumn` est proposée **après 42.4** (hors story). Conséquence assumée : `is_head_teacher` devient STALE après tout read-back — d'où l'audit lecteurs (AC5) qui prouve que plus personne ne la lit.
- **D6 — Sort du helper 4.12 : SUPPRESSION de la partition, pas délégation.** Le bloc `isProf()` de `syncRoleAwareAdGroupMembers` (l.985-1002) est supprimé et remplacé par la résolution D2. La méthode garde son nom (elle devient enfin littéralement « role-aware ») et sa responsabilité de chokepoint unique (créé par 4.12, à ne pas dupliquer). `stripClasseLikePrefix`, le bypass CN legacy préfixé et la dérivation base nue sont INTACTS. `User::isProf()` lui-même N'EST PAS touché (autres consommateurs légitimes : gating UI `profMembers`, policies).
- **D7 — Changement de comportement ASSUMÉ (source de vérité = l'arête)** : un changement du rôle GLOBAL `users.role` (prof↔élève sur la fiche user) ne rebascule PLUS le membre entre `Equipe_`/`Classe_` à la projection suivante tant que son ARÊTE n'a pas été réalignée (par le read-back `syncFromAd` — qui dérive encore du rôle global jusqu'à 42.4 — ou par l'édition 42.3). C'est le POINT de l'epic (« la source de vérité passe de l'heuristique globale à la relation »). Le test 4.12 `it_moves_member_between_equipe_and_classe_on_role_switch` (et son jumeau eleve→prof) est ADAPTÉ en conséquence, pas contourné : il doit prouver la bascule via l'arête réalignée.

---

## Critères d'acceptation

1. **Routage par arêtes** : `syncRoleAwareAdGroupMembers` ne contient PLUS AUCUN appel `isProf()` ni AUCUNE lecture `is_head_teacher` ; pour un groupe classe-like, les cibles sont peuplées selon D1 (`Equipe_` = manager∪owner, `Classe_` = member, `PP_` = owner), TOUJOURS synchronisées toutes les trois (bucket vide → vidage, pas de rémanence — parité 4.15 AC2). Le diff idempotent `syncAdGroupMembersByUserIds` (l.1082-1120) est réutilisé **TEL QUEL** (aucune réécriture de la couche LDAP). Types non classe-like : cible unique `resolvePrimaryGroupName`, zéro diff de comportement.
2. **Résolution du rôle effectif = D2 exactement** : override `owner` autoritaire depuis `$headTeacherUserIds ∩ $selectedUserIds` (garde-fou ghost 4.15 conservé — un id hors membres est ignoré sans exception) ; arête existante sinon (avec rétrogradation de projection `owner`→`manager` hors set PP) ; défaut dérivé `defaultRoleForGlobalRole` en UNE requête `users.role` pour les membres sans arête ; valeur d'arête hors vocabulaire → fallback dérivé + warning, pas d'exception. Tests dédiés pour chaque branche.
3. **Bascule atomique — plus aucune lecture `is_head_teacher` dans la chaîne de projection** : la dérivation des PP courants d'`updateGroup` (l.179-185, clé `head_teacher_ids` ABSENTE) passe à `wherePivot('role', UserGroupUserPivot::ROLE_OWNER)` ; la préservation « clé absente = PP conservés / `[]` explicite = effacement volontaire » (4.15) est INTACTE (test `it_preserves_head_teachers_when_updateGroup_omits_head_teacher_ids` vert). `grep -rn "is_head_teacher" app/` ne matche plus que : le pivot (cast/commentaires), la migration 4.14, `MergeLegacyUserGroups`, `BackfillUserGroupUserRoles` (vestiges D5 documentés) — AUCUN service/vue vivant.
4. **Resync sur changement de rôle (ancrage observer)** : `UserGroupUserPivotObserver::updated()` reprojette le groupe concerné quand `wasChanged('role')`, pour un groupe `type ∈ {classe, equipe}` uniquement, fail-soft (Throwable → Log::error, jamais propagé), inactif quand `$syncEnabled === false` OU pendant `syncFromAd` (flag dédié D4, posé/retiré dans le try/finally l.418-470). Tests : (a) update de rôle via `updateExistingPivot`/`sync` associatif → reprojection captée (appels `GroupRepository` mockés) ; (b) AUCUNE reprojection pendant un `syncFromAd` qui flippe des rôles ; (c) AUCUNE reprojection pour un groupe non classe-like ; (d) un échec de projection ne casse pas l'écriture pivot.
5. **Miroir supprimé du chemin vivant** : le payload sync de `projectFoldedGroup` (l.618-629) n'écrit plus que `['role' => …]` ; le compteur `head_teacher_updated` des stats est conservé (il compte les `updated` du sync — renommage cosmétique interdit, contrat de retour public). Les tests 42.1 d'invariant miroir (`it_mirrors_role_on_pivot_read_back`, `it_keeps_role_mirror_idempotent_across_two_imports`) sont RÉÉCRITS en tests de rôle seul (le read-back pose `role`, ne touche plus `is_head_teacher`). Audit lecteurs exhaustif documenté dans la story (Dev Agent Record) prouvant qu'aucun code vivant ne LIT plus la colonne (cf. AC3).
6. **`PP_X` absent en AD toléré (fail-soft)** : sur un AD sans groupe `PP_<base>` (volumétrie réelle : 4 `pp_` sur lab1, PP marginal), la projection ne lève RIEN — `getGroupMembers` renvoie `collect([])`, les `addMember` renvoient `false`, le groupe/les autres cibles sont projetés normalement. Test explicite (mock `getGroupMembers('PP_…')` → collection vide + `addMember` → false).
7. **AD fédéré — aucune nouvelle résolution par CN global** : les opérations membership passent EXCLUSIVEMENT par le canal existant (`GroupRepository` scopé OU étab via `dnHelper` — jamais la racine, `project_ad_federated_root_gpos`), les membres par DN SQL, le matching des lignes SQL par `ad_guid` (inchangé). Le suffixe étab vit dans le sAMAccountName (vérifié lab1) : NE PAS introduire de matching par CN suffixé ni de recherche LDAP globale. AC vérifié par revue de diff : ZÉRO changement dans `GroupRepository`.
8. **Parité FR-S6/NFR-S2** : sur un état backfillé 42.1 (arêtes `manager` ⇔ prof SQL, `owner` ⇔ ex-PP, `member` sinon), les cibles AD projetées sont **IDENTIQUES** à celles de la partition `isProf()` remplacée — greenfield (AD vierge peuplé par `createGroup`) ET brownfield (AD pré-peuplé SE4 : la projection n'arrache PERSONNE d'`Equipe_X` peuplé par SE4 dès lors que les arêtes managers correspondent). `ShareService`/`AclService`/`UserPolicy` : **ZÉRO diff**. Piège nom résolu vs nom brut (corrigé 4.12) non réintroduit : les tests existants passant le CN primaire (`Classe_3A`) à `updateGroup` restent verts.
9. **Sémantique PP 4.15 intégralement conservée** : orthogonalité (owner ∈ `Equipe_` ET `PP_`), vidage sans rémanence, multi-PP, garde-fou intersection, gating type (jamais de `PP_` hors classe-like), idempotence (2ᵉ run = 0 add/remove superflu), aller-retour `syncFromAd` stable. Les tests 4.15 (`it_writes_head_teachers_to_pp_group` … `it_keeps_pp_stable_after_syncFromAd_roundtrip`) passent avec des adaptations de fixtures MINIMALES (poser les arêtes `role` au lieu de compter sur `isProf`).
10. **Convergence UI PP inchangée** : `head-teacher-section` `save()` → `updateGroup(head_teacher_ids)` → `PP_` écrit depuis l'override owner → read-back converge → vérification post-save `wherePivot('role','owner')` (déjà basculée en 42.1) → toast succès. `HeadTeacherSectionTest` vert sans changement de sémantique.
11. **Bascule prof↔élève via l'arête (D7)** : les tests 4.12 de bascule (`it_moves_member_between_equipe_and_classe_on_role_switch`, `it_moves_member_from_classe_to_equipe_on_eleve_to_prof_switch`) sont adaptés : le déplacement AD est prouvé APRÈS réalignement de l'arête (read-back exécuté par le flux `updateGroup`, ou arête posée à la nouvelle valeur), et un test documente explicitement que l'arête PRIME sur `users.role` à la projection (prof SQL avec arête `member` → `Classe_`).
12. **Tests hôte verts** (php8.4 + SQLite, HÔTE uniquement — la VM n'a pas pdo_sqlite) : nouveaux tests AC1-AC11 + non-régression par FILTRES : `vendor/bin/phpunit --filter "UserGroup|MergeLegacy|HeadTeacher|GroupShowMembers|UserServiceClassChange|UserCreation|Backfill|UserGroupUserPivot|UserDerivedRolePayload|SyncFromAdImportCommand|SyncUsersFromAdCommand|ClassShareSection|SharesResyncClassCommand"`. Pré-existant connu hors scope, NE PAS « corriger » : `BulkPasswordResetGroupsTest` (env LDAP absent).

---

## Tasks / Subtasks

- [x] **T1 — Routage par arêtes dans `syncRoleAwareAdGroupMembers`** (AC1, AC2, AC9) — `app/Services/UserGroupService.php:961-1022`
  - [x] T1.1 Signature : ajouter un 5ᵉ paramètre `array $edgeRolesByUserId = []` (map `user_id => role` fournie par l'appelant ; `[]` depuis `createGroup` — le groupe n'existe pas encore en SQL). Docblock réécrit (D1/D2, plus de mention isProf).
  - [x] T1.2 SUPPRIMER la partition `isProf()` (l.985-1002) — sort du helper 4.12 acté D6 : suppression pure, méthode/chokepoint conservés, `stripClasseLikePrefix` + bypass non classe-like (l.972-979) INTACTS.
  - [x] T1.3 Implémenter la résolution D2 (owner autoritaire → arête avec rétrogradation owner→manager hors set → défaut dérivé en UNE requête `users.role` pour les ids sans arête ; hors vocabulaire → fallback dérivé + `Log::warning`).
  - [x] T1.4 Buckets D1 : `Equipe_{base}` = manager∪owner ; `Classe_{base}` = member ; `PP_{base}` = owner. Les 3 appels `syncAdGroupMembersByUserIds` SYSTÉMATIQUES (l.1005-1021 : garder le « toujours synchroniser », étendu à 3). Garde-fou intersection PP conservé (l.1016-1020).
- [x] **T2 — Appelants : fournir les arêtes + basculer la dérivation PP** (AC2, AC3) — `app/Services/UserGroupService.php`
  - [x] T2.1 `updateGroup` : construire `$edgeRolesByUserId` depuis `$group->users()` (UNE requête, pivot `role` dispo via `withPivot` 42.1) et le passer à la projection (l.188-193). L'état lu est le pivot PRÉ-update (voulu : les nouveaux membres du payload passent par le défaut dérivé, le read-back crée leurs arêtes ensuite).
  - [x] T2.2 `updateGroup` l.179-185 : dérivation des PP courants (clé `head_teacher_ids` absente) → `wherePivot('role', UserGroupUserPivot::ROLE_OWNER)`. Commentaire 4.15 mis à jour (préservation clé-absente INCHANGÉE sur le fond).
  - [x] T2.3 `createGroup` (l.96-103) : appel inchangé hors 5ᵉ param `[]` — l'override owner (`head_teacher_ids` payload) + défaut dérivé couvrent tous les membres. Vérifier qu'AUCUN autre call site n'existe (grep `syncRoleAwareAdGroupMembers`).
- [x] **T3 — Ancrage observer : resync sur changement de rôle** (AC4) — `app/Observers/UserGroupUserPivotObserver.php` + `app/Services/UserGroupService.php:418-470`
  - [x] T3.1 Flag statique dédié `$adResyncEnabled` (+ `disableAdResync()`/`enableAdResync()`, pattern `$syncEnabled` existant l.33-43).
  - [x] T3.2 Handler `updated(UserGroupUserPivot $pivot)` : guards (`$syncEnabled`, `$adResyncEnabled`, `wasChanged('role')`), résolution du groupe, filtre `type ∈ {classe, equipe}`, reprojection fail-soft du groupe concerné (membres courants du pivot + arêtes → même chokepoint T1 ; PP dérivés `role='owner'`). Injection via `app(UserGroupService::class)` (pattern `dispatch()` l.79-101) — ATTENTION : `syncRoleAwareAdGroupMembers` est `private` → exposer un point d'entrée public dédié minimal (ex. `resyncGroupAdProjection(UserGroup $group): void` qui dérive membres/arêtes/owners du pivot et délègue), réutilisé tel quel par 42.3.
  - [x] T3.3 `syncFromAd` : `UserGroupUserPivotObserver::disableAdResync()` AVANT la transaction, `enableAdResync()` dans le `finally` (l.468-470, à côté d'`UserGroupObserver::enableSync()`). NE PAS toucher `$syncEnabled` (la synchro FS ShareService du read-back doit vivre).
- [x] **T4 — Retrait du miroir + audit lecteurs** (AC3, AC5) — `app/Services/UserGroupService.php:577-635`, `app/Models/Pivot/UserGroupUserPivot.php`
  - [x] T4.1 `projectFoldedGroup` : payload sync → `['role' => …]` seul (retirer `'is_head_teacher' => $isPP` l.622) ; commentaires 4.14/42.1 (l.577-610) réécrits (la dérivation heuristique du rôle RESTE — 42.4 la remplacera). Stats inchangées.
  - [x] T4.2 Audit lecteurs : `grep -rn "is_head_teacher" app/ resources/ database/ routes/` — vérifier qu'il ne reste QUE les vestiges D5 (pivot cast/commentaires, migration 4.14, `MergeLegacyUserGroups`, `BackfillUserGroupUserRoles`) ; consigner la liste dans le Dev Agent Record. `MergeLegacyUserGroups`/`Backfill` NON modifiés (D5).
  - [x] T4.3 `UserGroupUserPivot` : NB l.84-88 (« miroir maintenu jusqu'à 42.2 ») mis à jour — le miroir n'est plus écrit sur le chemin vivant, colonne conservée jusqu'à la migration destructive post-42.4 ; cast conservé.
- [x] **T5 — Tests hôte** (AC1-AC12) — `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php`, `tests/Feature/Observers/UserGroupUserPivotObserverTest.php`, `tests/Feature/Livewire/Users/HeadTeacherSectionTest.php`
  - [x] T5.1 Nouveaux tests routage (AC1/AC2) : arêtes manager/member/owner → 3 buckets ; membre sans arête → défaut dérivé (1 requête) ; owner décoché → retiré de `PP_`, conservé dans `Equipe_` ; rôle d'arête invalide → fallback + pas d'exception ; arête prime sur `users.role` (AC11).
  - [x] T5.2 Adaptation tests 4.12 : `it_partitions_members_by_role_between_equipe_and_classe` (l.395), `it_is_idempotent_when_resyncing_same_partition` (l.459), `it_removes_prof_from_equipe_when_detached` (l.516) → fixtures posant les arêtes `role` (le backfill 42.1 garantit cet état en réel) ; bascules l.574/636 adaptées D7 (AC11).
  - [x] T5.3 Tests 4.15 (l.1241-1587) : verts avec fixtures minimales (arêtes `role` posées) ; miroir 42.1 (l.1424, 1452) réécrits rôle-seul (AC5).
  - [x] T5.4 Observer (AC4) : 4 scénarios (update rôle → reprojection ; suspension pendant syncFromAd ; non classe-like ignoré ; fail-soft). Patron : `UserGroupUserPivotObserverTest` existant (mock ShareService → ici mock `GroupRepository`/service).
  - [x] T5.5 Parité brownfield (AC8) : AD pré-peuplé (mock `getGroupMembers` retournant les membres SE4) + arêtes backfillées → AUCUN `removeMember` sur les membres légitimes, cibles identiques à l'ancienne partition. Fail-soft `PP_` absent (AC6).
  - [x] T5.6 Non-régression : filtre complet AC12 ; purge `User::$ldapCache` en tearDown, `primeNoLdap()` (patrons existants du fichier). Piloter par filtres — run massif = faux échecs (`project_vm_phpunit_bulk_run_false_failures`).
- [x] **T6 — Doc QA append-only** — `docs/qa/domains/rights-management.md` : **Section 16** « Projection AD depuis les arêtes (Story 42.2) » — scénarios (routage 3 buckets, changement de rôle → resync, PP absent fail-soft, parité brownfield lab1) + runbook e2e /vm différé post-merge (`samba-tool group listmembers Equipe_<x>` / `PP_<x>` après édition, `migrate:status` préalable). Sections 1-15 NON renumérotées.

---

## Dépendances

- **Amont (satisfaites)** : **42.1** (`review` **approuvée** — 0 critique, 4 findings tous corrigés, code sur main) : colonne `role` + backfill joué en migration + `withPivot('role')` ×3 + miroir tenu partout + `defaultRoleForGlobalRole`/`assertValidRole` + `User::userGroupSyncPayloadWithDerivedRole` sur les écrivains UI. **4.12/4.15 (`done`)** : `syncRoleAwareAdGroupMembers` (chokepoint étendu ici) + `syncAdGroupMembersByUserIds` (diff réutilisé) + sémantique PP orthogonale. Le « statuer 4.12 » de l'epic est réglé : 4.12 est passée `done`, son heuristique est REMPLACÉE ici, son chokepoint et ses invariants (noms, idempotence) sont conservés.
- **Aval (bloquées par 42.2)** : **42.3** (UI rôle éditable — consommera le point d'entrée public de resync T3.2) ; **42.4** (read-back des rôles — remplacera la dérivation heuristique de `projectFoldedGroup`, lèvera la limite transitoire D7/42.1-AC7 « l'import écrase un rôle édité »).
- **Post-42.4 (hors epic si préféré)** : migration destructive `dropColumn('is_head_teacher')` + retrait cast/withPivot/vestiges (D5).

---

## Dev Notes

### Ancrage code (chemins:lignes vérifiés 2026-07-13)

| Élément | Fichier:ligne | Action 42.2 |
|---|---|---|
| Chokepoint projection (partition isProf à REMPLACER) | `app/Services/UserGroupService.php:961-1022` (`syncRoleAwareAdGroupMembers` ; partition l.985-1002 ; PP l.1010-1021) | T1 |
| Diff idempotent fail-soft (NE PAS réécrire) | `app/Services/UserGroupService.php:1082-1120` (`syncAdGroupMembersByUserIds`) | Réutilisé tel quel |
| Appelant create (payload PP l.76-79, projection l.96-103) | `app/Services/UserGroupService.php:51-116` (`createGroup`) | T2.3 (5ᵉ param `[]`) |
| Appelant update (dérivation PP l.179-185 — DERNIÈRE lecture `is_head_teacher` vivante ; projection l.188-193) | `app/Services/UserGroupService.php:118-222` (`updateGroup`) | T2.1/T2.2 |
| Read-back + savepoints (suspension resync T3.3 : l.418 disable / l.468-470 finally) | `app/Services/UserGroupService.php:331-481` (`syncFromAd`) | T3.3 |
| Miroir à retirer (payload l.618-629) — dérivation heuristique du rôle CONSERVÉE (42.4) | `app/Services/UserGroupService.php:492-635` (`projectFoldedGroup`) | T4.1 |
| Mécanique de noms — NE PAS TOUCHER | `stripClasseLikePrefix` :1068-1077, `resolvePrimaryGroupName` :1154-1168, `resolveSqlLookupName` :1143-1152, `guardReservedPrefixOnCreate` :1041-1056 | Aucun diff |
| Observer pivot (created/deleted FS — flag `$syncEnabled` l.33-43, dispatch fail-soft l.61-102) | `app/Observers/UserGroupUserPivotObserver.php` | T3.1/T3.2 (`updated` + flag dédié) |
| Enregistrement observer | `app/Providers/AppServiceProvider.php:218` | Aucun diff (events pivot déjà routés) |
| Pivot (ROLES l.63-67, `assertValidRole` l.98-107, `defaultRoleForGlobalRole` l.118-129, NB miroir l.84-88) | `app/Models/Pivot/UserGroupUserPivot.php` | T4.3 (commentaire) |
| Écritures AD par CN scopé OU étab (fail-soft : groupe absent → `false`/`collect([])`) | `app/Repositories/GroupRepository.php:636-707` (`addMember`/`removeMember`), `:716-746` (`getGroupMembers` — scope `dnHelper->groups()`) | AUCUN diff (AC7) |
| UI PP (save → `head_teacher_ids` l.192-198 ; convergence `role='owner'` l.211-222 déjà basculée 42.1) | `resources/views/pages/users/groups/[id]/_partials/head-teacher-section.blade.php` | Aucun diff attendu (AC10) |
| Écrivains pivot UI (rôle dérivé, nouvelles arêtes only — review 42.1 #1) | `app/Models/User.php:144-162`, `resources/views/pages/users/[login]/index.blade.php:238-243`, `resources/views/components/organisms/groups-drawer.blade.php:354-366` | Aucun diff (contexte) |
| Import users (observer pivot désactivé l.1504/1531) | `app/Services/UserService.php` (`persistUserGroupsToSql`) | Aucun diff (guard `$syncEnabled` couvre T3.2) |
| Événements pivot custom (update → `fill()->save()` → event `updated`) | `vendor/laravel/framework/.../InteractsWithPivotTable.php:312-327` (`updateExistingPivotUsingCustomClass`) | Constat vérifié : l'ancrage `updated` FONCTIONNE avec `sync()` associatif/`updateExistingPivot` (pas avec les writes `DB::` bruts — backfill/merge, voulu) |
| Tests principaux | `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (partition :395-698, PP :1241-1587, miroir :1424/1452), `tests/Feature/Observers/UserGroupUserPivotObserverTest.php`, `tests/Feature/Livewire/Users/HeadTeacherSectionTest.php` | T5 |

### Pièges & points d'attention

- **Piège n°1 — récursion/tempête observer↔syncFromAd** : `projectFoldedGroup` fait un `sync()` associatif qui UPDATE des rôles (heuristique) → sans la suspension T3.3, chaque flip déclenche une reprojection LDAP pendant le read-back (import lab1 ~600 groupes). Le flag DÉDIÉ est obligatoire : réutiliser `$syncEnabled` casserait la synchro FS ShareService au read-back (création des dossiers élèves à l'attach — Story 5.2). Test AC4(b) le verrouille.
- **Piège n°2 — brownfield : le diff peut RETIRER des membres** : `syncAdGroupMembersByUserIds` remove = `current ∩ sqlKnown \ desired`. Si la résolution D2 est buggée (ex. prof résolu `member`), un prof SE4 est ARRACHÉ d'`Equipe_X` en prod fédérée → perte rwx immédiate. C'est LE risque de la story ; les tests de parité AC8 (brownfield, cibles identiques à l'ancienne partition) sont non négociables.
- **Piège n°3 — état du pivot à la projection dans `updateGroup`** : le pivot n'est PAS écrit par `updateGroup` avant la projection (il est réaligné par le read-back APRÈS — AD-first transitoire). Les arêtes lues (T2.1) sont donc PRÉ-update : nouveaux membres du payload = pas d'arête → défaut dérivé ; membres retirés = absents de `$selectedUserIds` → sortis des 3 buckets (le diff les retire). Ne PAS « corriger » en écrivant le pivot avant l'AD — c'est le flux 4.15/D2 (AD avant read-back), sa refonte n'est PAS cette story.
- **Piège n°4 — ex-PP décoché** : son arête dit encore `owner` au moment de la projection (le read-back ne l'a pas encore rétrogradée). Sans la rétrogradation de projection D2.2 (`owner` hors set PP → `manager`), il resterait dans `PP_` (rémanence) ou sortirait d'`Equipe_` (perte rwx). Test dédié T5.1.
- **Piège n°5 — LDAP-first** : `isProf()` fait un round-trip LDAP par user. La résolution D2 lit `users.role` (colonne SQL) en UNE requête pour les manquants — JAMAIS `isProf()` en boucle (`project_isprof_iseleve_ldap_first_cost`). Les tests utilisent `primeNoLdap()` : l'équivalence isProf/users.role y est exacte (fallback SQL).
- **Piège n°6 — nom résolu vs nom brut** : `updateGroup` peut recevoir le CN primaire (`Classe_3A`) depuis l'edit-form ; `stripClasseLikePrefix` réconcilie (corrigé 4.12). N'introduire AUCUNE dérivation de base qui contourne ce strip. Tests existants au CN primaire = filet.
- **Piège n°7 — `head_teacher_updated` est un contrat de stats public** (retour `syncFromAd`, logs, appels UI). Le retrait du miroir ne doit PAS changer la forme du tableau de stats.
- **Piège n°8 — events pivot** : `sync()` associatif sur pivot custom passe par `updateExistingPivotUsingCustomClass` → event `updated` UNIQUEMENT si `isDirty()` (un sync sans changement ne déclenche rien — bon pour l'idempotence). Les writes `DB::table('user_group_user')` (backfill, merge) NE déclenchent PAS l'observer — comportement voulu (actions de migration).
- **Piège n°9 — fail-soft partout** : la projection ne doit JAMAIS faire échouer un save de groupe pour un souci AD (`PP_` absent, groupe déchet lab1, DN manquant). Pas d'`assertValidRole` en levée dans le chemin de projection (D2) — la garde en levée reste pour les écrivains (42.1 #2).
- **Piège n°10 — AD fédéré partagé (75 étab)** : jamais d'écriture à la racine, scoping OU étab par `dnHelper` (existant), matching SQL par `ad_guid`. Ne pas « améliorer » la résolution de groupe au passage — ZÉRO diff `GroupRepository`.
- **VM** : aucune migration dans cette story, mais `migrate:status` avant tout e2e (42.1 doit être jouée sur /vm) ; e2e réel différé post-merge (runbook Section 16). Ne JAMAIS interagir avec la VM depuis un worktree.
- **Worktree** : `cp -al` du vendor, jamais de symlink (`project_ultradev_worktree_vendor_trap`).

### Testing standards

- Tests sur l'**HÔTE** uniquement (php8.4 + sqlite ; la VM n'a pas pdo_sqlite). Filtres ciblés (AC12), jamais de run massif.
- Patrons : `UserGroupServiceLegacyCompatibilityTest` (`makeService` avec mocks `GroupRepository` — asserter les APPELS `addMember`/`removeMember`/`getGroupMembers`, pas un état AD ; `primeNoLdap()` ; purge `User::$ldapCache` en tearDown ; `createTestTables` porte déjà la colonne `role` — 42.1), `UserGroupUserPivotObserverTest` (events pivot réels sur schéma SQLite), `MergeLegacyUserGroupsMigrationTest` (non touché sur le fond).
- Gardes suite (`withoutVite`/reguard) dans les tests Livewire.

### Project Structure Notes

- **AUCUN fichier créé côté app** (tout est édition ciblée) ; 0 route, 0 vue, 0 migration, 0 fichier `agent/**` (pas de bump version agent).
- Éditions : `UserGroupService` (chokepoint + 2 appelants + syncFromAd suspension + projectFoldedGroup), `UserGroupUserPivotObserver` (+`updated`, +flag), `UserGroupUserPivot` (commentaires), tests (~4 fichiers), doc QA (Section 16 append-only).
- Racine projet = Laravel (`app/`, pas `laravel/app`).

### References

- [Source: _bmad-output/planning-artifacts/epics-socle-role-groupes.md#Story 42.2] — intention + AC-skeleton figé ici ; FR-S3/FR-S6, NFR-S1/NFR-S2
- [Source: _bmad-output/planning-artifacts/epics-socle-role-groupes.md#Additional Requirements] — nommage lab1 (suffixe étab = sAMAccountName), volumétrie (606 equipe_, 4 pp_), transition 4.12/4.15 atomique
- [Source: docs/group-model-multivertical-orientation.md#Direction proposée / #Séquençage retenu] — phase « socle » : projection depuis les arêtes, nommage legacy conservé
- [Source: _bmad-output/implementation-artifacts/42-1-colonne-role-arete-backfill.md] — amont direct : EXCEPTION CRITIQUE (canal 4.15 non basculé → basculé ICI), miroir « jusqu'à 42.2 », semantics owner ⊂ manager
- [Source: _bmad-output/codeReviews/42-1.md] — findings #1 (écrivains UI dérivés — prérequis de cette bascule) et #2 (assertValidRole)
- [Source: _bmad-output/implementation-artifacts/4-12-peuplement-equipe-x-par-role.md] — partition isProf remplacée ; chokepoint + incohérence nom résolu/brut (à ne pas réintroduire)
- [Source: _bmad-output/implementation-artifacts/4-15-ecriture-pp-ad-ui-professeur-principal.md] — sémantique PP orthogonale, D1 intersection, D2 AD-avant-read-back, préservation clé-absente
- [Source: _bmad-output/planning-artifacts/architecture.md#Modèle de Données / #Data Architecture] — PG source applicative, AD = projection transitoire
- [Source: memory/project_ad_federated_root_gpos.md ; project_ad_sync_resolve_by_guid.md ; project_acl_equipe_group_missing_etab_suffix.md ; project_state_precedence_logical_over_physical.md ; project_isprof_iseleve_ldap_first_cost.md ; project_vm_phpunit_bulk_run_false_failures.md]

## Dev Agent Record

### Agent Model Used

claude-fable-5 (dev-story, main)

### Debug Log References

- `vendor/bin/phpunit --filter "UserGroupServiceLegacyCompatibilityTest"` → **56 tests / 186 assertions, OK** (14 échecs intermédiaires attendus après la bascule code — les assertions de convergence lisaient encore le flag 4.14 ; réécrites rôle-seul).
- `vendor/bin/phpunit --filter "UserGroupUserPivotObserverTest"` → **10 tests, OK** (4 existants 5.2 + 6 nouveaux AC4).
- Filtre complet AC12 : `vendor/bin/phpunit --filter "UserGroup|MergeLegacy|HeadTeacher|GroupShowMembers|UserServiceClassChange|UserCreation|Backfill|UserGroupUserPivot|UserDerivedRolePayload|SyncFromAdImportCommand|SyncUsersFromAdCommand|ClassShareSection|SharesResyncClassCommand"` → **180 tests / 516 assertions, OK**.
- Non-régression adjacente : `--filter "UserPolicy|ShareServiceTest"` → **68 tests, OK** (zéro diff sur ces couches — AC7/AC8).
- Vérification AC1 : `grep -E '\$[a-zA-Z]+->isProf\(\)' app/Services/UserGroupService.php` → **zéro appel** ; `grep "wherePivot('is_head_teacher'" app/` → **zéro lecture**.

### Completion Notes List

- **AC1/AC2 (T1)** — `syncRoleAwareAdGroupMembers` : partition `isProf()` SUPPRIMÉE (D6 — chokepoint/nom/`stripClasseLikePrefix`/bypass non classe-like conservés), 5ᵉ param `array $edgeRolesByUserId = []`, résolution D2 exacte (owner autoritaire depuis `head_teacher_ids ∩ selected` → arête avec rétrogradation `owner`→`manager` hors set PP → défaut dérivé `users.role` en UNE requête pour les seuls ids sans arête valide ; hors vocabulaire → `Log::warning` + fallback, pas d'exception). Buckets D1 : `Equipe_`=manager∪owner, `Classe_`=member, `PP_`=owner ; 3 appels `syncAdGroupMembersByUserIds` SYSTÉMATIQUES (diff idempotent réutilisé TEL QUEL). Garde-fou intersection PP conservé (le set PP EST le bucket `PP_`).
- **AC2/AC3 (T2)** — `updateGroup` : map d'arêtes PRÉ-update lue en UNE requête (`pluck('user_group_user.role','users.id')`) et passée à la projection ; `$selectedUserIds` dérivé de cette même map quand `user_ids` absent (une requête pivot au lieu de deux) ; dérivation des PP courants (clé absente) basculée `wherePivot('role', ROLE_OWNER)` — DERNIÈRE lecture vivante de `is_head_teacher` supprimée ; préservation « clé absente / `[]` explicite » 4.15 INTACTE (test M6 vert). `createGroup` : 5ᵉ param `[]` explicite. Grep : aucun autre call site.
- **AC4 (T3)** — `UserGroupUserPivotObserver` : flag statique DÉDIÉ `$adResyncEnabled` + `disableAdResync()`/`enableAdResync()` (JAMAIS `$syncEnabled`, qui gouverne la synchro FS ShareService) ; handler `updated()` gardé (`$syncEnabled` commun, `$adResyncEnabled`, `wasChanged('role')`, type ∈ {classe, equipe}), fail-soft (Throwable → `Log::error`, `app()` résolu DANS le try). Point d'entrée public minimal `UserGroupService::resyncGroupAdProjection(UserGroup)` (dérive membres/arêtes/owners du pivot en une requête, délègue au chokepoint — réutilisable tel quel par 42.3). `syncFromAd` : `disableAdResync()` posé à côté d'`UserGroupObserver::disableSync()`, `enableAdResync()` dans le même `finally`.
- **AC5 (T4)** — `projectFoldedGroup` : payload sync → `['role' => …]` SEUL (miroir retiré du chemin vivant) ; dérivation heuristique du rôle CONSERVÉE (42.4) ; stats inchangées (clé `head_teacher_updated` = contrat public, seul le libellé du log d'issue reformulé « rôle(s) d'arête mis à jour »). `UserGroupUserPivot` : NB miroir réécrit (colonne stale, conservée jusqu'au drop post-42.4). Test dédié : le read-back ne touche plus le flag (reste à son défaut false y compris pour un PP).
- **AC6** — fail-soft `PP_` absent testé (mock `getGroupMembers('PP_…')` → collection vide + `addMember('PP_3A')` → false via nouveau param `failAddMemberCns` de `makeService`) : aucun crash, autres cibles projetées, arête retombée `manager` (AD-first documenté).
- **AC7** — ZÉRO diff `GroupRepository`/`ShareService`/`AclService`/`UserPolicy` (vérifié git status + tests policy/share verts).
- **AC8** — parité greenfield (test partition 4.12 conservé : createGroup sans arêtes = 100 % défaut dérivé, cibles identiques) ET brownfield (nouveau test : AD pré-peuplé SE4 + arêtes backfillées → ZÉRO add/remove). Piège nom résolu/brut non réintroduit (tests au CN primaire `Classe_3A` verts).
- **AC9/AC10** — tests 4.15 verts avec fixtures minimales (arêtes posées via `attach(['role' => …])`) ; `HeadTeacherSectionTest` vert SANS modification (le fake service du test mirrorne encore les deux colonnes — fixture, pas un chemin vivant).
- **AC11 (D7)** — bascules 4.12 adaptées (arête RÉALIGNÉE à la nouvelle valeur avant projection) + test explicite « l'arête PRIME » (prof SQL avec arête `member` → `Classe_`).
- **Écart assumé (mineure, justifiée)** : commentaires de `app/Models/UserGroup.php` (docblock `withPivot`) mis à jour — fichier hors liste anticipée de la story, nécessaire pour que l'audit AC3 ne laisse aucune mention trompeuse d'un miroir encore « vivant ». Blades 4.15 NON touchées (leurs mentions `is_head_teacher` sont des clés de view-model calculées depuis `role` — 42.1 — ou des commentaires historiques ; hors périmètre AC3 qui scope `app/`).

### Audit lecteurs `is_head_teacher` (AC3/T4.2 — `grep -rn "is_head_teacher" app/ resources/ database/ routes/`)

**app/ — AUCUN lecteur vivant restant** :
- `app/Models/Pivot/UserGroupUserPivot.php` : cast `boolean` + commentaires (vestige D5, colonne conservée).
- `app/Models/UserGroup.php` : `->withPivot('is_head_teacher', 'role')` + docblocks (D5 : withPivot CONSERVÉ pour fixtures/brownfield, documenté stale).
- `app/Actions/Groups/MergeLegacyUserGroups.php` : 3 ÉCRITURES one-shot legacy (l.199, 224, 394) + commentaires — NON modifié (D5, gardes `hasColumn` en place, correct sur base pré-42.1).
- `app/Actions/Groups/BackfillUserGroupUserRoles.php` : 1 LECTURE one-shot de migration (l.67, `where('is_head_teacher', true)`) + commentaires — NON modifié (D5).
- `app/Services/UserGroupService.php` : ZÉRO occurrence du token (commentaires reformulés « flag 4.14 »/« miroir booléen 4.14 ») ; zéro lecture, zéro écriture.

**resources/** : uniquement des CLÉS DE VIEW-MODEL homonymes calculées depuis `role === 'owner'` (42.1 — `groups/[id]/index.blade.php:82`, `head-teacher-section.blade.php:158` + `members-table.blade.php:23` qui consomme la clé) et des commentaires historiques 4.14/4.15. Aucune lecture de la COLONNE pivot.

**database/** : migrations `2026_06_25` (4.14, création de la colonne) et `2026_07_13` (42.1, docblock backfill). **routes/** : zéro occurrence.

Conclusion : plus AUCUN code vivant ne lit la colonne — elle peut devenir stale sans effet (D5) ; le drop destructif post-42.4 ne cassera que les vestiges documentés.

### File List

**Modifiés (code)**
- `app/Services/UserGroupService.php` (chokepoint T1 routage par arêtes + `resyncGroupAdProjection` public T3.2 + appelants T2 + suspension resync syncFromAd T3.3 + retrait miroir projectFoldedGroup T4.1 + commentaires)
- `app/Observers/UserGroupUserPivotObserver.php` (flag dédié `$adResyncEnabled` T3.1 + handler `updated()` T3.2 + docblock)
- `app/Models/Pivot/UserGroupUserPivot.php` (commentaires T4.3 — colonne stale documentée)
- `app/Models/UserGroup.php` (commentaires withPivot — vestige D5 documenté)

**Modifiés (tests)**
- `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (9 nouveaux tests 42.2 ; adaptation 4.12 D7 ; miroir 42.1 réécrit rôle-seul ; helper `isPivotOwner`/`legacyHeadTeacherFlag` ; `makeService` + param `failAddMemberCns`)
- `tests/Feature/Observers/UserGroupUserPivotObserverTest.php` (6 nouveaux tests AC4 ; enable/disable `adResync` en setUp/tearDown)

**Modifiés (doc)**
- `docs/qa/domains/rights-management.md` (Section 16 append-only)

**Modifiés (suivi)**
- `_bmad-output/implementation-artifacts/42-2-projection-ad-depuis-aretes.md` (cette story)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (ligne 42-2 → review)

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-07-14 | 1.0 | IMPLÉMENTÉE (dev-story, claude-fable-5, main). Bascule ATOMIQUE : `syncRoleAwareAdGroupMembers` routé par le rôle d'arête (D1 : Equipe_=manager∪owner, Classe_=member, PP_=owner ; 3 cibles toujours synchronisées ; diff idempotent réutilisé tel quel) avec résolution D2 (owner autoritaire → arête + rétrogradation owner→manager hors set PP → défaut dérivé 1 requête ; hors vocabulaire = warning+fallback). Dérivation PP d'updateGroup sur `wherePivot('role','owner')` — dernière lecture `is_head_teacher` supprimée ; miroir retiré de `projectFoldedGroup` (rôle seul, stats/clé `head_teacher_updated` intactes). Ancrage observer `updated()` (wasChanged('role'), classe/equipe, fail-soft) → nouveau point d'entrée public `resyncGroupAdProjection` (réutilisable 42.3) ; flag DÉDIÉ `$adResyncEnabled` suspendu pendant `syncFromAd` (synchro FS `$syncEnabled` intouchée). Parité AC8 greenfield+brownfield prouvée (zéro retrait de membre SE4 légitime). Audit lecteurs : plus aucun code vivant ne lit la colonne (vestiges D5 documentés). 15 nouveaux tests + adaptations D7/rôle-seul ; filtre AC12 : 180 tests / 516 assertions OK. Section 16 QA (rights-management). Status → review. | Dev (claude-fable-5) |
| 2026-07-13 | 0.1 | Story CRÉÉE (SM/create-story, Fable 5). AC-skeleton de l'epic figé en 12 AC. Décisions actées : D1 buckets (Equipe_=manager∪owner, Classe_=member, PP_=owner), D2 résolution du rôle effectif (owner autoritaire → arête avec rétrogradation owner→manager hors set PP → défaut dérivé 1 requête ; hors vocabulaire = fallback+warning fail-soft), D3 chaîne `head_teacher_ids` CONSERVÉE (canal désignation PP jusqu'à 42.3) mais ZÉRO lecture `is_head_teacher` restante, D4 ancrage observer `updated()` + flag dédié suspendu pendant syncFromAd (jamais `$syncEnabled`, qui gouverne la synchro FS), D5 miroir retiré du seul chemin vivant (projectFoldedGroup) — colonne/cast/withPivot conservés, MergeLegacy/Backfill vestiges intacts, drop destructif post-42.4 hors story, D6 sort helper 4.12 = suppression de la partition (chokepoint conservé), D7 changement assumé : la bascule prof↔élève passe par l'arête (source de vérité = la relation). Constat vendor vérifié : events `updated` du pivot custom fonctionnent via sync()/updateExistingPivot. | SM (Fable 5) |

---

## Recommandation Modèle Dev

**fable** (confirme le pré-cadrage fable/opus de l'epic, borne haute).

Justification : c'est la story pivot de l'epic et la plus dangereuse — elle écrit dans l'**AD fédéré partagé de 75 établissements** avec un diff qui sait RETIRER des membres (piège n°2 : une résolution de rôle buggée arrache des profs d'`Equipe_X` en brownfield → perte rwx immédiate en prod) ; elle exécute une **bascule atomique** entre deux heuristiques et une source d'arêtes avec quatre coutures inter-stories à ne pas déchirer (canal PP 4.15 conservé, read-back heuristique conservé pour 42.4, miroir retiré sans lecteur restant, point d'entrée resync pour 42.3) ; et l'ancrage événementiel (observer `updated` + suspension pendant `syncFromAd`) porte des risques de récursion/tempête LDAP non triviaux, vérifiés jusque dans le vendor. La story est très prescriptive, mais la surface d'échecs silencieux (fail-soft partout, diff destructif, events pivot) justifie le modèle le plus fort ; review par le modèle opposé (opus).
