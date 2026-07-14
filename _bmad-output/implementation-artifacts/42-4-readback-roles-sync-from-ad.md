# Story 42.4 : Read-back des rôles au sync-from-ad (import legacy)

Status: review

> **Type** : bascule de la dérivation du rôle d'arête dans le read-back AD→SQL (`projectFoldedGroup`) — l'HEURISTIQUE `users.role` (posée en 42.1 AC7, conservée « EN L'ÉTAT » par 42.2) est remplacée par la lecture du **TRIO AD réel** (`Classe_X`/`Equipe_X`/`PP_X`). AUCUNE migration de schéma. AUCUNE écriture AD (le read-back LIT l'AD ; la projection SQL→AD reste 42.2, intouchée). Pas d'UI (42.3, parallèle).
>
> **Origine** : Epic 42 — Socle rôle sur l'arête user↔groupe (`_bmad-output/planning-artifacts/epics-socle-role-groupes.md`, FR-S5, décision Henri 2026-07-07). **4ᵉ et dernière story** de l'epic ; amont **42.1 + 42.2 LIVRÉES** (review approuvées, code sur main) ; **parallèle à 42.3** (aucun chevauchement de code — cf. Dépendances).
>
> **Direction** : à l'import (`syncFromAd`, transitoire — `project_sync_from_ad_transitional`), le rôle de chaque arête est **reconstruit depuis l'appartenance aux CN du trio** : membre de `PP_X` → `owner`, membre d'`Equipe_X` → `manager`, membre de `Classe_X` seulement → `member`, précédence `owner > manager > member` (une seule arête par user×groupe). Cette story **LÈVE la limite transitoire 42.1-AC7/42.2-D7** : l'import n'écrase plus un rôle édité en UI par une heuristique — il lit ce que la projection 42.2 a réellement écrit dans l'AD (aller-retour projection→read-back→projection = no-op).
>
> **Mémoire projet liée** : `project_usergroup_sql_fold_bare_name.md` (fold 4.13 : 1 ligne SQL au nom nu — INTACTE), `project_vm_ad_junk_classe_groups.md` (déchets + savepoint 25P02), `project_ad_sync_resolve_by_guid.md` (résolution groupe par GUID), `project_acl_equipe_group_missing_etab_suffix.md` (suffixe étab = sAMAccountName, pas le CN), `project_sync_from_ad_transitional.md` (AD-first transitoire), `project_isprof_iseleve_ldap_first_cost.md` (jamais de round-trip LDAP par user), `project_sqlite_tests_no_varchar_enforcement.md` (vocabulaire borné applicativement).

---

## Story

En tant que **responsable d'établissement migrant depuis SE4 (et développeur SE5)**,
je veux **que l'import AD→SQL reconstruise les arêtes user↔groupe avec leur rôle depuis les groupes legacy réels (`Classe_`/`Equipe_`/`PP_`), avec précédence `owner > manager > member`, tolérance aux données sales du parc réel et préservation des rôles quand l'AD ne porte pas le signal**,
afin que la migration d'un établissement SE4 (58 `classe_`, 606 `equipe_`, 4 `pp_` sur lab1) produise des rôles d'arête FIDÈLES à l'existant, que le cycle projection (42.2) ⇄ read-back soit stable (un rôle édité en UI 42.3 survit à l'import), et que l'epic 42 soit complet : la relation est la source de vérité, l'AD n'est plus qu'une projection convergente.

---

## Périmètre STRICT

**Dans le scope** : la dérivation du rôle dans `projectFoldedGroup` (capture par-CN des tiers du trio, précédence, préservation par signal manquant), les commentaires « limite levée en 42.4 » devenus faux, les tests (nouveaux + adaptations), la doc QA append-only.

**HORS SCOPE** :
- **42.3 (parallèle)** : colonne « Rôle » éditable en UI (pages groupes), resync à l'édition. 42.4 ne touche AUCUN fichier `resources/views/**`.
- **Mécanique de fold 4.13** : `buildFoldedGroups`/`foldPrefixOf`/`shouldFold`/`FOLD_PREFIXES`, union des membres, détachement des absents, cleanup `whereNotIn`, résolution `ad_guid`/name/ad_dn de la ligne — **INTACTS**. Seul le CALCUL DU RÔLE de chaque arête change.
- **Chaîne de projection 42.2** : `syncRoleAwareAdGroupMembers`, `resyncGroupAdProjection`, `syncAdGroupMembersByUserIds`, observer pivot (`$adResyncEnabled`) — zéro diff (on VÉRIFIE que la suspension couvre le nouveau code, on ne la modifie pas).
- **Vestiges D5 (42.2)** : `MergeLegacyUserGroups`, `BackfillUserGroupUserRoles` — actions one-shot legacy, **NON touchées**. La colonne `is_head_teacher` reste stale et non écrite ; la migration destructive `dropColumn` reste post-42.4 (hors story).
- **Couche LDAP** : `GroupRepository` (dont `getGroupMembers` et sa limite « erreur LDAP ⇒ `collect([])` indistinguable d'un groupe vide ») — zéro diff.
- **Nettoyage des déchets AD** (`pp_profs`, `pp_legaco`, `Classe_classe_NNN`…) : on les TOLÈRE (fail-soft, savepoint), on ne les corrige pas.
- `ShareService`/`AclService`/`UserPolicy`, `UserService::persistUserGroupsToSql` (défauts 42.1) — zéro diff.

---

## Décisions de cadrage (ACTÉES — ne pas rouvrir sans signal contraire)

- **D1 — Dérivation par CN du trio, précédence par tier.** Pour chaque membre de l'union d'un groupe foldé, le rôle dérivé est le MAX des tiers de ses CN d'appartenance dans `$folded['cns']` : `PP_` → `owner` (3), `Equipe_` → `manager` (2), `Classe_` → `member` (1). Un user présent dans `Classe_3A` ET `Equipe_3A` → `manager` ; dans `Equipe_3A` ET `PP_3A` → `owner`. Le tier est déterminé par `foldPrefixOf($cn)` (déjà insensible à la casse — `classe_3a`/`Classe_3A` équivalents) ; les espaces dans les bases (`Equipe_301 g1`) transitent sans traitement. Le payload `sync()` reste associatif clé `user_id` → **une seule arête par user×groupe** par construction. **Le rôle global `users.role` ne participe PLUS à la dérivation pour les membres d'un CN du trio** — c'est LE remplacement de l'heuristique.
- **D2 — `Equipe_` orphelin (cours/sous-groupes, ~548 des 606 `equipe_` lab1) : membres → `manager`.** Tranché contre « ignorer » et contre « membership-only (`member`) » :
  - *membership-only serait DESTRUCTIF* : la ligne standalone 4.13 est de type `equipe`, donc classe-like pour la projection 42.2 — des arêtes `member` routeraient vers le bucket `Classe_<base>` (inexistant en AD, no-op) et VIDERAIENT le bucket `Equipe_<base>` : à la première reprojection (édition 42.3, updateGroup), le diff **arracherait les membres d'`equipe_301_esp` dans l'AD fédéré**. Inacceptable.
  - *ignorer casserait le fold 4.13* : ne pas importer ces lignes déclencherait leur suppression par le cleanup `whereNotIn` (lignes déjà importées) — hors de question.
  - *`manager` est AD-fidèle et stable* : reprojection = `Equipe_<base>` ⊇ membres exacts, `Classe_`/`PP_` vides fail-soft → **zéro écriture destructive, aller-retour no-op**. Sémantiquement, membre d'une « équipe » SE4 = membre de l'équipe pédagogique ; le cas exotique d'un élève dans un sous-groupe `Equipe_` reste fidèle à l'AD (aujourd'hui l'heuristique le met `member`, ce qui est précisément la bombe destructive ci-dessus — 42.4 la désamorce). Donc **pas d'arête orpheline** : les arêtes se posent sur la ligne `equipe` standalone existante (4.13-D1), avec `role='manager'`.
- **D3 — Préservation par signal manquant (« rôles existants non écrasés sans changement AD »).** Un rôle SQL existant ne peut être RÉTROGRADÉ que si le CN AD qui l'exprimerait est PRÉSENT dans le fold :
  - fold **sans CN `PP_`** (cas MAJORITAIRE : 54 des 58 classes lab1 n'ont pas de `pp_` ; la projection 42.2 d'un `owner` vers `PP_X` absent est fail-soft `false`) : une arête existante `owner` dont le dérivé D1 vaut `manager` **reste `owner`** ;
  - fold **sans CN `Equipe_`** (rare — createGroup SE5 crée le trio, brownfield en a 606 ; groupes créés à la main) : une arête existante `manager` (ou `owner`) dont le dérivé vaut `member` **remonte à `manager`** (puis `owner` par composition si `PP_` absent aussi) ;
  - si le CN est PRÉSENT, l'AD est **autoritaire** : user absent d'un `PP_X` présent → rétrogradé (`manager` s'il est dans `Equipe_`, sinon `member`) — c'est un vrai changement AD (PP décoché, retrait d'équipe) ;
  - comparaisons **STRICTES aux constantes `ROLE_*`** : une valeur existante hors vocabulaire (SQLite ne borne pas — NFR-S4) n'est JAMAIS préservée (le dérivé D1 s'applique).
  Sans D3, tout `owner` posé en UI (42.3) sur une classe sans `pp_` serait rétrogradé à CHAQUE import — la limite 42.1-AC7 ne serait pas levée, juste déplacée.
- **D4 — L'appartenance reste AD-first, intacte.** Union des membres, détachement des absents de l'union, résolution des lignes par `ad_guid` (fallback `name`/`ad_dn`), résolution des membres par `login` (extraction du CN du DN membre — pour un user SE5, `cn == sAMAccountName == login`, `project_user_login_single_identity`) : **inchangés**. Aucun nouveau matching par CN suffixé ni sAMAccountName global — en fédéré le CN n'est pas suffixé (suffixe = sAMAccountName, OU par UAI dans le DN, vérifié lab1) et le fold opère sur le CN.
- **D5 — Dérivation heuristique CONSERVÉE hors trio.** Les projections standalone NON-trio (`Cours_*`, `Matiere_*`, `Matiere_*@*`, `Projet_*`, rôle/fonction/custom — `foldPrefixOf() === null`) gardent `defaultRoleForGlobalRole(users.role)` résolu en une requête (comportement actuel) : le rôle n'y route aucune projection (cible unique) et rien dans l'AD n'y porte de signal de rôle.
- **D6 — Fail-soft intégral dans le savepoint.** Aucune exception nouvelle pour données sales : pas d'`assertValidRole` en levée dans le chemin d'import (les valeurs écrites sont des constantes ou passent par `defaultRoleForGlobalRole`, déjà gardée). Le savepoint par groupe (25P02) reste l'unique isolation d'erreur ; le nouveau code ne fait AUCUNE requête hors de `projectFoldedGroup`.

---

## Critères d'acceptation

1. **Read-back du trio (D1)** : sur un fold AD `Classe_3A={alice(eleve), paul(prof)}`, `Equipe_3A={bob(prof), alice}`, `PP_3A={bob}` : `(3A, bob)=owner`, `(3A, alice)=manager` (Equipe_ prime sur Classe_ — précédence par tier), `(3A, paul)=member` (**l'AD prime sur `users.role` global : un prof présent SEULEMENT dans `Classe_` devient `member`** — changement assumé vs l'heuristique, c'est le POINT de la story). Une seule arête par user×groupe. Plus AUCUNE lecture de `users.role` pour un membre d'un CN du trio.
2. **Casse et espaces (lab1)** : CN legacy en minuscules (`classe_3a`/`equipe_3a`/`pp_3a`) et bases avec ESPACES (`Equipe_301 g1`) dérivent les mêmes rôles que la forme canonique — tests dédiés (fold insensible à la casse déjà couvert par 4.13, le TIER doit l'être aussi).
3. **`Equipe_` orphelin → `manager` (D2)** : les membres d'un `Equipe_Y` sans `Classe_Y`/`PP_Y` (ligne standalone nue type `equipe`) reçoivent `role='manager'`, élèves inclus. Test « aller-retour non destructif » : après read-back, une reprojection de cette ligne (via `resyncGroupAdProjection`) ne produit **AUCUN `removeMember`** sur `Equipe_Y` (les membres restent, `Classe_Y`/`PP_Y` = buckets vides no-op).
4. **Standalone non-trio inchangé (D5)** : `Cours_Histoire4A` (membre prof) → arête `manager` par dérivation `users.role` (une requête pour l'union, zéro LDAP par membre) ; tests existants verts sans changement de sémantique.
5. **Préservation par signal manquant (D3)** : (a) fold `Classe_3A`+`Equipe_3A` SANS `PP_3A` : arête existante `owner` d'un membre d'`Equipe_3A` → **reste `owner`** ; (b) fold avec `PP_3A` présent mais user retiré de `PP_3A` → rétrogradé `manager` (AD autoritaire) ; (c) fold `Classe_3A` seule (pas d'`Equipe_3A` dans le lot) : arête existante `manager` d'un membre de `Classe_3A` → reste `manager`, et arête `owner` → reste `owner` (composition) ; (d) valeur existante HORS vocabulaire (`'chef'`) → JAMAIS préservée, le dérivé D1 s'applique, aucune exception.
6. **Aller-retour stable projection⇄read-back (lève 42.1-AC7/42.2-D7)** : (a) greenfield trio complet (fixture `makeClassFixture`, membership mutable) : arêtes `owner`/`manager`/`member` posées → projection 42.2 → `syncFromAd` → **mêmes rôles**, et une 2ᵉ projection = zéro add/remove ; (b) brownfield sans `PP_` : `owner` posé sur l'arête (comme le fera l'UI 42.3) → projection (add `PP_3A` échoue fail-soft) → `syncFromAd` → **`owner` intact** (test nommant explicitement la levée de la limite « l'import écrase un rôle édité »).
7. **Idempotence** : deux `syncFromAd` consécutifs sans changement AD = état pivot strictement identique, `sync()['updated']` vide au 2ᵉ run (stat `head_teacher_updated` stable), aucun attach/detach fantôme.
8. **Données sales tolérées (savepoint 25P02)** : un lot contenant des déchets (`pp_profs`, `pp_legaco` — qui foldent en lignes `profs`/`legaco` avec membres `owner`, comportement 4.13/4.14 EXISTANT documenté, pas « corrigé ») et un groupe dont la projection LÈVE (ex. conflit `ad_guid`) : la boucle CONTINUE, les groupes suivants sont projetés avec leurs rôles, `stats['errors']` incrémenté, la transaction d'ensemble survit (pattern savepoint existant — test avec groupe fautif AU MILIEU du lot).
9. **Fédéré : résolution inchangée (D4)** : test avec DN portant l'OU par UAI (`CN=Classe_3CK,OU=classes,OU=0991229y,OU=Groups,…`) : fold vers la ligne nue `3CK`, rôles dérivés du trio, résolution de la ligne par `ad_guid` ; **ZÉRO diff `GroupRepository`**, aucun matching nouveau par CN suffixé/sAMAccountName.
10. **Suspension `adResync` couvre le nouveau code** : un `syncFromAd` qui flippe des rôles en masse ne déclenche AUCUNE écriture membership AD (flag dédié 42.2 posé autour de la transaction — vérifié, pas modifié). ⚠️ Le test existant `it_suspends_ad_resync_observer_during_syncFromAd` doit être ADAPTÉ : sa fixture (bob prof dans `Classe_3A` seule, arête `member`) ne flippera PLUS avec le read-back D1 (dérivé = `member`) — mettre bob dans `Equipe_3A` pour provoquer le flip `member→manager`.
11. **Intouchables — zéro diff** : `MergeLegacyUserGroups`, `BackfillUserGroupUserRoles`, `syncRoleAwareAdGroupMembers`, `resyncGroupAdProjection`, `syncAdGroupMembersByUserIds`, `buildFoldedGroups`/`foldPrefixOf`/`shouldFold`, `GroupRepository`, `ShareService`/`AclService`/`UserPolicy`, `UserService`. `is_head_teacher` toujours NON écrit par le read-back (stale, D5 42.2). Vérifiable par revue de diff : seuls `projectFoldedGroup` (+ docblocks/commentaires devenus faux) changent côté app.
12. **Tests hôte verts** (php8.4 + SQLite, HÔTE uniquement — la VM n'a pas pdo_sqlite) : nouveaux tests AC1-AC10 + non-régression par FILTRES : `vendor/bin/phpunit --filter "UserGroup|MergeLegacy|HeadTeacher|GroupShowMembers|UserServiceClassChange|UserCreation|Backfill|UserGroupUserPivot|UserDerivedRolePayload|SyncFromAdImportCommand|SyncUsersFromAdCommand|ClassShareSection|SharesResyncClassCommand"`. Pré-existant connu hors scope, NE PAS « corriger » : `BulkPasswordResetGroupsTest` (env LDAP absent).

---

## Tasks / Subtasks

- [x] **T1 — Capture par-CN des tiers du trio** (AC1, AC2) — `app/Services/UserGroupService.php:609-666` (`projectFoldedGroup`)
  - [x] T1.1 Étendre la boucle existante sur `$folded['cns']` (l.617-628) : au lieu du seul `$ppUserIds`, capturer le TIER par user — `foldPrefixOf($cn)` → `'PP_'`=owner, `'Equipe_'`=manager, `'Classe_'`=member, `null`=hors-trio (heuristique D5). Conserver un `max()` de tier par `user_id` (précédence D1). Aucune requête LDAP supplémentaire (mêmes appels `resolveMemberUserIdsFromAdGroup` par CN qu'aujourd'hui).
  - [x] T1.2 Restreindre la dérivation `users.role` (requête `pluck('role','id')` l.644-649) aux SEULS membres hors-trio (D5) — pour un fold de classe, elle disparaît entièrement ; pour un standalone `Cours_`, comportement identique.
- [x] **T2 — Préservation par signal manquant** (AC5, AC6) — `app/Services/UserGroupService.php` (même méthode)
  - [x] T2.1 Détecter la présence des CN `PP_`/`Equipe_` dans `$folded['cns']` (via `foldPrefixOf`, une passe).
  - [x] T2.2 Lire les arêtes existantes EN UNE REQUÊTE avant le `sync()` (`$group->users()->pluck('user_group_user.role', 'users.id')` — pivot `role` dispo via `withPivot` 42.1) ; appliquer D3 par composition : dérivé `member` + pas de CN `Equipe_` + existant ∈ {`manager`,`owner`} strict → `manager` ; puis dérivé `manager` + pas de CN `PP_` + existant `owner` strict → `owner`. Groupe nouvellement créé : aucune arête → aucune préservation (chemin naturel).
  - [x] T2.3 Payload `sync()` associatif inchangé dans sa forme (`[$userId => ['role' => …]]`) ; stats `linked_users`/`detached_users`/`head_teacher_updated` inchangées (contrat public).
- [x] **T3 — Commentaires/docblocks devenus faux** (AC11)
  - [x] T3.1 `projectFoldedGroup` : réécrire le bloc l.630-643 (« la dérivation heuristique reste EN L'ÉTAT ici — 42.4 ») → documenter D1/D3/D5 ; docblock de la méthode (l.515-523) complété.
  - [x] T3.2 `app/Models/Pivot/UserGroupUserPivot.php` : NB l.84-91 et docblock ROLES l.38-51 — les mentions « 42.4 lèvera » passent au passé ; la note « drop destructif post-42.4 » reste (le drop n'est PAS cette story).
  - [x] T3.3 Grep `42.4|42\.4` dans `app/` : plus aucune mention prospective trompeuse (syncFromAd l.440-449 : la justification de la suspension reste vraie, mettre à jour « dérivation heuristique » → « read-back du trio »).
- [x] **T4 — Tests hôte** (AC1-AC10, AC12) — `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php`
  - [x] T4.1 Nouveaux tests D1 : trio avec chevauchements (AC1 — alice dans Classe_+Equipe_, paul prof dans Classe_ seule) ; casse legacy minuscule + base avec espaces (AC2, patron `it_folds_lowercase_legacy_cn_variants_case_insensitively`).
  - [x] T4.2 Nouveaux tests D2 : orphan `Equipe_301 g1` (élève + prof membres → tous `manager`) + aller-retour non destructif via `resyncGroupAdProjection` (AC3 — asserter `removedDnsFor('Equipe_301 g1') === []`).
  - [x] T4.3 Nouveaux tests D3 : les 4 branches de l'AC5 (owner préservé sans PP_ ; rétrogradé avec PP_ présent ; manager préservé sans Equipe_ ; valeur sale non préservée).
  - [x] T4.4 Nouveaux tests aller-retour (AC6) : greenfield `makeClassFixture` (mutableMembership) rôles→projection→import→rôles ; brownfield sans PP_ (`failAddMemberCns: ['PP_3A']` + lot AD sans `PP_3A`) : owner survit à l'import — test nommé sur la levée de la limite 42.1-AC7.
  - [x] T4.5 Idempotence (AC7) : adapter/étendre `it_keeps_role_mirror_idempotent_across_two_imports` (2ᵉ run : zéro `updated`).
  - [x] T4.6 Données sales (AC8) : lot `[classe_ok, groupe_fautif(conflit ad_guid), pp_profs, equipe_301_esp]` → erreurs isolées, suivants projetés avec rôles, `errors==1`.
  - [x] T4.7 Fédéré (AC9) : DN `OU=0991229y` (adGroupRow adapté), rôles du trio, résolution `ad_guid`.
  - [x] T4.8 Adaptations : `it_suspends_ad_resync_observer_during_syncFromAd` (fixture bob → `Equipe_3A`, AC10) ; `it_writes_role_on_pivot_read_back`/tests 4.14-4.15 (PP→owner : mêmes attentes, fixtures trio-complètes déjà convergentes — vérifier, adapter les fixtures qui comptaient sur l'heuristique prof-en-`Classe_`-seule) ; balayer les tests read-back existants pour tout prof AD placé uniquement dans `Classe_` (dérivé désormais `member`).
  - [x] T4.9 Non-régression : filtre complet AC12 (run massif interdit — `project_vm_phpunit_bulk_run_false_failures`).
- [x] **T5 — Doc QA append-only** — `docs/qa/domains/rights-management.md` : **Section 18** « Read-back des rôles au sync-from-ad (Story 42.4) » — scénarios (trio+précédence, orphan equipe manager, préservation sans PP_, données sales lab1, aller-retour stable) + runbook e2e /vm différé post-merge (import `php artisan import:sync-from-ad user_groups` puis vérif pivot `role` sur une classe avec/sans `pp_`, `migrate:status` préalable — 42.1 jouée). Sections 1-16 NON renumérotées. ⚠️ 42.3 (parallèle) ajoutera probablement AUSSI une section : collision de numéro possible au merge — bénin, renuméroter à l'intégration (le contenu est append-only).

---

## Dépendances

- **Amont (satisfaites, code sur main)** : **42.1** (`review` approuvée) — colonne `role` + `withPivot` ×3 + `ROLES`/`assertValidRole`/`defaultRoleForGlobalRole` + backfill ; **42.2** (`review` approuvée) — projection par arêtes (D1 buckets), `resyncGroupAdProjection` public, flag `$adResyncEnabled` posé autour de `syncFromAd` (couvre ce read-back), miroir retiré du chemin vivant. 4.13 (fold nom nu) et 4.15 (sémantique PP) : consommées, non modifiées.
- **Parallèle : 42.3 (UI rôle éditable)** — indépendante. **Contrat de non-chevauchement** : 42.4 reste dans `app/Services/UserGroupService.php::projectFoldedGroup` + `UserGroupUserPivot` (commentaires) + `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` ; 42.3 reste dans `resources/views/pages/users/groups/**` + tests Livewire, et CONSOMME `resyncGroupAdProjection`/`disableAdResync` SANS éditer `UserGroupService` (le point d'entrée public 42.2 suffit — contrat review 42.2 #4 : édition en masse = `disableAdResync()` + UN resync explicite, à implémenter dans le composant Livewire). **Chevauchements résiduels identifiés** : (a) si le create-story 42.3 conclut qu'il faut malgré tout une méthode service pour l'édition en masse → à écrire dans le composant ou à séquencer APRÈS le merge de 42.4 (signaler à l'orchestrateur) ; (b) `docs/qa/domains/rights-management.md` : les deux stories appendent une section — collision de numérotation bénigne, renuméroter au merge ; (c) `sprint-status.yaml` : lignes distinctes, merge trivial.
- **Aval (débloqué par 42.4)** : migration destructive `dropColumn('is_head_teacher')` + retrait des vestiges D5 (hors epic, à planifier).

---

## Dev Notes

### Ancrage code (chemins:lignes vérifiés 2026-07-14 sur le worktree)

| Élément | Fichier:ligne | Action 42.4 |
|---|---|---|
| Read-back + savepoints 25P02 + suspension adResync | `app/Services/UserGroupService.php:351-513` (`syncFromAd` ; savepoint l.475-477 ; disable l.449 / finally l.499-502) | Vérifier (AC10), commentaire l.440-449 (T3.3) |
| **Cœur de la story** — dérivation heuristique à remplacer | `app/Services/UserGroupService.php:524-666` (`projectFoldedGroup` ; résolution ad_guid l.526-557 ; boucle CN + `$ppUserIds` l.609-628 ; heuristique `users.role` l.630-660 ; `sync()` l.662-665) | T1/T2/T3.1 |
| Fold 4.13 — NE PAS TOUCHER | `buildFoldedGroups` :769-878 (orphan equipe l.806-821), `foldPrefixOf` :891-900 (insensible casse), `shouldFold` :920-929, `FOLD_PREFIXES` :745 | Consommés (tier par prefix) |
| Résolution membres AD (login=cn) — NE PAS TOUCHER | `app/Services/UserGroupService.php:934-958` (`resolveMemberUserIdsFromAdGroup`) | Appels par CN inchangés |
| Chokepoint projection 42.2 — ZÉRO diff | `syncRoleAwareAdGroupMembers` :1010-1120, `resyncGroupAdProjection` :1134+, `syncAdGroupMembersByUserIds` :1213+ | AC11 |
| Vocabulaire + garde + défaut dérivé | `app/Models/Pivot/UserGroupUserPivot.php:52-67, 101-110, 121-132` (NB stale l.84-91) | T3.2 (commentaires seulement) |
| Flags observer (suspension 42.2) — ZÉRO diff | `app/Observers/UserGroupUserPivotObserver.php:69-75` | AC10 (vérif) |
| Greenfield : createGroup crée le TRIO AD (Classe_+Equipe_+PP_) ; type cours crée Cours_+Equipe_ (orphan par construction) | `app/Repositories/GroupRepository.php:438-520` | Contexte D2/D3 (aucun diff) |
| `getGroupMembers` (erreur LDAP → `collect([])`) | `app/Repositories/GroupRepository.php:716-800` | Limite connue documentée (aucun diff) |
| Commande CLI (délègue à `importFromUsersAdGroups`) | `app/Console/Commands/SyncFromAdImportCommand.php:306` | Non-régression seulement |
| Tests principaux (patrons + adaptations) | `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` : read-back rôles :1456-1509, PP import :976-1205, suspension :2552-2599 (**fixture à adapter**, AC10), orphan equipe :275-309 et :888, fold casse :1008-1109, `makeClassFixture` :1241-1270, `makeService` (+`failAddMemberCns`, `mutableMembership`) :2688-2782, `adGroupRow` :2650, schéma :2812-2861 | T4 |

### Pièges & points d'attention

- **Piège n°1 — le changement de comportement VOULU casse des fixtures** : tout test dont un PROF n'apparaît en AD que dans `Classe_X` dérivait `manager` (heuristique) et dérivera `member` (D1). Cas identifié : `it_suspends_ad_resync_observer_during_syncFromAd` (bob). Balayer les autres avant de coder (grep des fixtures `'role' => 'prof'` + membership `Classe_` seul). NE PAS « corriger » en réintroduisant l'heuristique : adapter la fixture (mettre le prof dans `Equipe_`), c'est la réalité SE4.
- **Piège n°2 — préservation ≠ heuristique par la fenêtre** : D3 lit le rôle EXISTANT de l'arête, jamais `users.role`. Ne pas confondre : un prof SANS arête existante membre de `Classe_` seule → `member` (D1), même si `users.role='prof'`.
- **Piège n°3 — une requête pivot de plus par groupe foldé, pas par membre** : la lecture des arêtes existantes (T2.2) se fait en UNE requête AVANT le `sync()`. Jamais de `isProf()`/LDAP par membre (`project_isprof_iseleve_ldap_first_cost`), jamais de requête par arête. Import lab1 ≈ 700 lignes projetées : +1 requête SQL chacune, acceptable.
- **Piège n°4 — savepoint 25P02** : tout le nouveau code vit DANS `projectFoldedGroup` (donc dans le savepoint). Aucune requête sur l'état d'un groupe APRÈS son échec ; la boucle appelante et le cleanup `whereNotIn` sont intacts. Le test AC8 place le fautif AU MILIEU pour prouver que les suivants passent (piège `project_vm_ad_junk_classe_groups`).
- **Piège n°5 — `pp_profs`/`pp_legaco` foldent déjà** : `foldPrefixOf('pp_profs')='PP_'` → ligne nue `profs` type classe, membres `owner`. C'est le comportement 4.13/4.14 EXISTANT (les membres avaient déjà `is_head_teacher=true` puis `role='owner'`). 42.4 ne l'aggrave ni ne le corrige — le test AC8 le CONSTATE (déchet toléré), il ne le « répare » pas.
- **Piège n°6 — `getGroupMembers` erreur = vide** : une erreur LDAP transitoire sur `Equipe_3A` rendrait `collect([])` → rétrogradation en masse manager→member (comme elle détache déjà les membres — limite 4.13 préexistante de l'import AD-first). ZÉRO diff `GroupRepository` : documenter dans la section QA, ne pas tenter de « fiabiliser » ici.
- **Piège n°7 — stats contrat public** : `head_teacher_updated` compte les `updated` du `sync()` (flips de rôle) — clé et forme du tableau INCHANGÉES (retours, logs, UI sync-from-ad).
- **Piège n°8 — SQLite ne borne pas les varchar** : la préservation D3 compare STRICTEMENT aux constantes ; une valeur sale (`'chef'`, `''`) n'est ni préservée ni écrite — le dérivé D1/D5 (constantes ou `defaultRoleForGlobalRole`, gardée) est la seule source d'écriture. Pas d'`assertValidRole` en LEVÉE dans le chemin d'import (fail-soft, D6).
- **Piège n°9 — ne pas toucher l'ordre AD-avant-read-back** : `createGroup`/`updateGroup` écrivent l'AD PUIS appellent `syncFromAd` (scopé). Le read-back D1 lit donc l'AD fraîchement projeté → convergence par construction. Ne rien « optimiser » dans ce flux (4.15-D2).
- **Piège n°10 — suffixe étab** : jamais de strip `-<uai>` sur les CN (le CN n'est PAS suffixé, c'est le sAMAccountName qui l'est — vérifié lab1, `project_acl_equipe_group_missing_etab_suffix`). Le fold et le tier travaillent sur le CN tel quel.
- **VM** : AUCUNE migration dans cette story ; e2e réel différé post-merge (runbook Section 18) ; `migrate:status` préalable (42.1 jouée sur /vm). Ne JAMAIS interagir avec la VM depuis ce worktree.
- **Worktree** : `cp -al` du vendor, jamais de symlink (`project_ultradev_worktree_vendor_trap`).

### Testing standards

- Tests sur l'**HÔTE** uniquement (php8.4 + sqlite ; la VM n'a pas pdo_sqlite). Filtres ciblés (AC12), jamais de run massif.
- Patron : `UserGroupServiceLegacyCompatibilityTest` — mocks `GroupRepository` (`makeService` : `groupMembersByCn`, `mutableMembership` pour les aller-retours, `failAddMemberCns` pour PP_ absent), `primeNoLdap()` par login, purge `User::$ldapCache` en tearDown, assertions sur les APPELS (`addedDnsFor`/`removedDnsFor`) pas sur un état AD.
- Le schéma de test (`createTestTables` l.2849-2860) porte déjà `role` (42.1) — rien à ajouter.

### Project Structure Notes

- **AUCUN fichier créé côté app** ; 0 migration, 0 route, 0 vue, 0 fichier `agent/**` (pas de bump version agent).
- Éditions : `app/Services/UserGroupService.php` (`projectFoldedGroup` + commentaires), `app/Models/Pivot/UserGroupUserPivot.php` (commentaires), `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php`, `docs/qa/domains/rights-management.md` (Section 18 append-only).
- Racine projet = Laravel (`app/`, pas `laravel/app`).

### References

- [Source: _bmad-output/planning-artifacts/epics-socle-role-groupes.md#Story 42.4] — intention + AC-skeleton figé ici ; FR-S5, NFR-S2/NFR-S4
- [Source: _bmad-output/planning-artifacts/epics-socle-role-groupes.md#Additional Requirements] — nommage lab1 (CN casse préservée, OU par UAI, suffixe = sAMAccountName), volumétrie (58 classe_, 606 equipe_ dont espaces, 4 pp_ dont déchets)
- [Source: _bmad-output/implementation-artifacts/42-1-colonne-role-arete-backfill.md] — AC7 (limite « l'import écrase un rôle édité » LEVÉE ici), vocabulaire/garde/défaut dérivé
- [Source: _bmad-output/implementation-artifacts/42-2-projection-ad-depuis-aretes.md] — D4 (suspension adResync), D5 (vestiges is_head_teacher), D7 (bascule par l'arête), buckets D1 de la projection (cible de convergence)
- [Source: _bmad-output/codeReviews/42-2.md] — #2 (NULL = arête absente), #4 (contrat 42.3 : masse = disableAdResync + 1 resync)
- [Source: memory/project_usergroup_sql_fold_bare_name.md ; project_vm_ad_junk_classe_groups.md ; project_sync_from_ad_transitional.md ; project_ad_sync_resolve_by_guid.md ; project_acl_equipe_group_missing_etab_suffix.md ; project_sqlite_tests_no_varchar_enforcement.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (dev-story, worktree ultradev/42-4)

### Debug Log References

- `bootstrap/cache/` absent du worktree (non versionné) → recréé (`mkdir -p bootstrap/cache`) pour permettre l'exécution des tests hôte ; sinon `The bootstrap/cache directory must be present and writable`.
- Piège n°1 confirmé au 1ᵉʳ run (56 tests) : SEUL `it_suspends_ad_resync_observer_during_syncFromAd` échoue (bob prof dans `Classe_3A` seul dérive désormais `member` au lieu de `manager` — plus de flip). Fixture adaptée (bob → `Equipe_3A`, AC10) : aucune réintroduction de l'heuristique.

### Completion Notes List

- **T1 (D1/D5)** — `projectFoldedGroup` : la boucle sur `$folded['cns']` capture le TIER MAX par user via `foldPrefixOf($cn)` (`PP_`=3/owner > `Equipe_`=2/manager > `Classe_`=1/member ; `null`=hors-trio). Un membre du trio ne lit PLUS `users.role` ; `users.role` (via `defaultRoleForGlobalRole`) n'est résolu, en UNE requête, que pour les membres HORS trio (`$heuristicIds`) — pour un fold de classe cette requête disparaît. Aucun appel LDAP nouveau.
- **T2 (D3)** — arêtes existantes lues en UNE requête (`DB::table('user_group_user')->where('user_group_id', $group->id)->pluck('role','user_id')`) AVANT le `sync()`. Préservation par composition : (a) dérivé `member` + PAS de CN `Equipe_` + existant ∈ {manager,owner} → `manager` ; (b) puis `manager` + PAS de CN `PP_` + existant `owner` → `owner`. Comparaisons STRICTES à `ROLES` (valeur sale jamais préservée). Groupe fraîchement créé (createGroup) = aucune arête → aucune préservation (le groupe n'existe en SQL qu'APRÈS le read-back). Forme du payload `sync()` et clés de stats inchangées.
- **T3 (AC11)** — docblock `projectFoldedGroup` complété (D1/D3/D5/D6), bloc heuristique « EN L'ÉTAT — 42.4 » remplacé par la doc du read-back, commentaire `syncFromAd` (« dérivation heuristique » → « read-back du trio AD — 42.4 »), commentaire pivot `UserGroupUserPivot.php` (mention prospective 42.4 passée au présent ; la note « drop destructif post-42.4 » conservée). Grep `42\.4` : plus aucune mention prospective trompeuse.
- **T4** — 12 nouveaux tests (AC1-AC9) + fixture AC10 adaptée. Preuve AC6 no-op : greenfield (owner/manager/member → `resyncGroupAdProjection` → `syncFromAd` → mêmes rôles + 2ᵉ projection zéro add/remove) ET brownfield sans PP_ (owner UI survit à l'import). AC7 : `stats['head_teacher_updated']/linked_users/detached_users == 0` au 2ᵉ run. AC8 : conflit `ad_guid` AU MILIEU du lot isolé (savepoint), groupes avant/après projetés, `errors==1`, déchet `pp_profs` toléré (owner). AC9 : DN `OU=0991229y`, résolution `ad_guid`, rôles du trio.
- **Périmètre** — côté app, SEULS `projectFoldedGroup` (corps + docblock) + le commentaire de `syncFromAd` et un commentaire de `UserGroupUserPivot.php` ont changé (diff vérifié : hunks `@@ -440`, `@@ -518`, `@@ -606`). Chokepoint 42.2 (`syncRoleAwareAdGroupMembers`/`resyncGroupAdProjection`/`syncAdGroupMembersByUserIds`), fold 4.13, `GroupRepository`, observer, `MergeLegacyUserGroups`/`BackfillUserGroupUserRoles`, `is_head_teacher` : ZÉRO diff. 0 migration, 0 vue (aucun chevauchement 42.3).
- **Tests HÔTE** — `UserGroupServiceLegacyCompatibilityTest` : 68/68 (224 assertions). Filtre AC12 complet : 192/192 (554 assertions), 0 régression. `BulkPasswordResetGroupsTest` (env LDAP absent) laissé pré-existant hors scope.

### File List

- `app/Services/UserGroupService.php` (modifié — `projectFoldedGroup` : read-back du trio D1/D3/D5/D6 + docblock ; commentaire `syncFromAd`)
- `app/Models/Pivot/UserGroupUserPivot.php` (modifié — commentaire de traçabilité 42.4 seulement)
- `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (modifié — 12 nouveaux tests AC1-AC9 + fixture AC10 adaptée)
- `docs/qa/domains/rights-management.md` (modifié — Section 18 append-only)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modifié — ligne 42-4 uniquement)

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-07-14 | 1.0 | Story IMPLÉMENTÉE (dev-story, claude-opus-4-8, worktree ultradev/42-4) → review. Read-back du trio AD dans `projectFoldedGroup` : tier MAX par user (D1), `users.role` conservé HORS trio (D5, 1 requête sur les seuls membres non-trio), préservation par signal manquant (D3, arêtes existantes lues 1 requête avant `sync()`, composition member→manager→owner, comparaisons strictes), fail-soft intégral (D6). Aller-retour projection 42.2 ⇄ read-back no-op prouvé (greenfield + brownfield sans PP_ : owner UI survit, limite 42.1-AC7 LEVÉE). Périmètre STRICT : app = `projectFoldedGroup` + commentaire `syncFromAd` + 1 commentaire pivot (chokepoint/fold/observer/vestiges INTOUCHÉS, diff vérifié) ; 0 migration, 0 vue. 12 nouveaux tests (AC1-AC9) + fixture AC10 adaptée (bob → Equipe_3A). Tests HÔTE : LegacyCompatibility 68/68 ; AC12 192/192 (554 assertions), 0 régression. QA Section 18. | Dev (Opus 4.8) |
| 2026-07-14 | 0.1 | Story CRÉÉE (SM/create-story, Fable 5, worktree ultradev/42-4). AC-skeleton de l'epic figé en 12 AC. Décisions actées : D1 dérivation par CN du trio avec précédence par tier (PP_→owner, Equipe_→manager, Classe_→member ; l'AD prime sur users.role — un prof en Classe_ seule devient member, changement ASSUMÉ) ; D2 Equipe_ orphelin (~548/606 lab1) → arêtes `manager` (membership-only=member serait DESTRUCTIF à la reprojection : vidage d'Equipe_<base> en AD ; ignorer casserait le cleanup 4.13) ; D3 préservation par signal manquant (fold sans PP_ → owner existant conservé si dérivé manager ; fold sans Equipe_ → manager conservé ; CN présent = AD autoritaire ; comparaisons strictes aux constantes) — sans D3 la limite 42.1-AC7 ne serait pas levée sur les 54/58 classes lab1 sans pp_ ; D4 appartenance/résolution AD-first intactes (ad_guid, login=cn, jamais de CN suffixé) ; D5 heuristique conservée hors trio (Cours_/Matiere_/custom) ; D6 fail-soft intégral dans le savepoint 25P02. Aller-retour projection 42.2 ⇄ read-back = no-op (greenfield trio complet ET brownfield sans PP_). Piège identifié : fixture du test de suspension adResync à adapter (bob → Equipe_3A). Périmètre : projectFoldedGroup + tests + doc QA — AUCUN chevauchement avec 42.3 (UI). | SM (Fable 5) |

---

## Recommandation Modèle Dev

**opus** (confirme le pré-cadrage de l'epic).

Justification : la story est chirurgicale (une seule méthode app, `projectFoldedGroup`) mais dense en cas limites — précédence par tier, préservation par signal manquant (D3, la subtilité centrale), données sales lab1, et l'adaptation d'une suite de tests existante SANS en affaiblir les invariants (le piège n°1 : des fixtures qui reposaient sur l'heuristique remplacée). Toutes les décisions structurantes sont TRANCHÉES dans la story (D1-D6, avec le pourquoi) et il n'y a AUCUNE écriture AD ni surface destructive directe — le profil « exécution rigoureuse de règles figées + jugement sur les tests » est exactement opus. Fable n'est pas justifié : pas de bascule atomique, pas de diff AD destructif, pas d'événementiel — la borne haute de l'epic était 42.2, déjà livrée. Review par le modèle opposé (fable ou sonnet selon dispo du cycle).

## Code Review Record (2026-07-14)

Review adversariale **sonnet** (dev opus) + évaluation orchestrateur : **approuvé** — 1 critique corrigé, 2 mineurs corrigés/documentés, 1 ignoré (pertinence 1). Doc : `_bmad-output/codeReviews/42-4.md`.

1. (#1 🔴, pertinence 3) Préservation D3 gardée par `$isTrioFold` (au moins un CN `Classe_/Equipe_/PP_` dans le fold) : hors-trio (Cours_/Matiere_/custom), recalcul frais systématique depuis `users.role` — plus de rôle stale immortel.
2. (#2, pertinence 3) Test de régression `it_recomputes_fresh_role_on_non_trio_group_even_with_stale_pivot_role`.
3. (#3, pertinence 2) Lettre de D3 assumée sur `Equipe_` orpheline (owner préservé, signal PP_ structurellement manquant) — figée par `it_preserves_existing_owner_on_orphan_equipe_fold`.
4. (#4, pertinence 1) Couverture casse : garantie par construction (`strncasecmp`), ignoré.
