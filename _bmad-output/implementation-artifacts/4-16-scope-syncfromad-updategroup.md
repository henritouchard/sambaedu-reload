# Story 4.16 : Scoper le read-back `syncFromAd()` de `updateGroup()` au groupe concerné

Status: review

> **Type** : **refactor ciblé** d'un service dense (`UserGroupService`). PAS d'UI, PAS de migration de schéma, PAS de fold/migration data nouveaux, PAS de bump agent. On scope un appel de read-back AD→SQL existant.
>
> **Origine** : Code review 4.15 (`_bmad-output/codeReviews/4-15.md`), problème **#3** + **M1** + question ouverte **Q1**. Décision Henri (2026-06-25) : **story dédiée**. Le micro-fix M1 (écriture `updateGroupDescription` conditionnelle) est DÉJÀ livré dans 4.15 ; SEUL le scoping du `syncFromAd()` global de `updateGroup` est reporté ici.
>
> **Dette PRÉ-EXISTANTE** (pas introduite par 4.15) : `updateGroup` appelle `$this->syncFromAd()` SANS `onlyGroupNames` → re-balaye TOUS les groupes AD de l'établissement à chaque édition. 4.15 en fait un déclencheur d'UI **fréquent** (toggle Professeur principal). Pire : le read-back global passe par le cleanup `whereNotIn` qui peut SUPPRIMER une ligne SQL momentanément absente de la réponse AD.
>
> **Mémoire projet liée** : `memory/project_usergroup_sql_fold_bare_name.md` (fold nom nu, stories 4.13/4.14/4.15), `memory/project_sync_from_ad_transitional.md` (AD-first transitoire), `memory/project_vm_ad_junk_classe_groups.md` (fold case-insensitive + savepoints), `memory/project_phpunit_test_env_host_vs_vm.md` (tests sur l'hôte).

---

## Story

En tant que **développeur SE5 (et opérateur d'un parc fédéré 75 établissements)**,
je veux **que `UserGroupService::updateGroup()` scope son read-back `syncFromAd()` au seul groupe édité** (`onlyGroupNames=[base concernée]`), comme le fait déjà `syncGroupsWithAd()`,
afin que chaque édition de groupe (rename, édition de membres, toggle Professeur principal…) ne re-balaye plus TOUS les groupes AD de l'établissement et ne risque plus de supprimer une ligne SQL hors scope via le cleanup `whereNotIn`.

---

## Contexte & cause racine (lecture code 2026-06-25)

### Le canal aujourd'hui

`updateGroup(int $id, array $data)` (`app/Services/UserGroupService.php:109-204`) :

1. résout le groupe, valide le payload, calcule `$oldName`/`$newName` ;
2. rename AD **ou** write description conditionnel (M1 livré 4.15) ;
3. si `user_ids`/`head_teacher_ids` présents → `syncRoleAwareAdGroupMembers(...)` (écriture AD des 3 cibles `Equipe_`/`Classe_`/`PP_`) ;
4. **`$this->syncFromAd();`** (l.187) — read-back AD→SQL **NON scopé** ;
5. lookup post-sync au nom nu (`resolveSqlLookupName`) et retour de la ligne.

L'étape 4 est le problème : `syncFromAd()` sans `onlyGroupNames` (l.308) :

- **re-`fetchEligibleAdGroups()`** = TOUS les groupes AD éligibles de l'établissement (coût LDAP O(N) à chaque édition d'UN groupe) ;
- **re-fold + re-projette** chaque ligne ;
- exécute le **cleanup `whereNotIn` (l.433-442)** : `if (count($onlyGroupNames) === 0)` → supprime toute ligne SQL absente du lot AD courant. Si l'AD renvoie momentanément une réponse incomplète (latence, réplication, filtre RDN transitoire), une ligne SQL **hors rapport avec l'édition** peut être supprimée.

### Le mécanisme `onlyGroupNames` existe déjà et est éprouvé

`syncGroupsWithAd(array $groupIds)` (l.243-266) fait EXACTEMENT ce qu'on veut pour le bouton « Synchroniser avec AD » :

```php
$groupNames = UserGroup::query()->whereIn('id', $ids)->pluck('name')->...->all();
// ...
$this->syncFromAd(onlyGroupNames: $groupNames);
```

Et le filtre `onlyGroupNames` de `syncFromAd` (l.335-368) **matche déjà chaque CN AD soit sur le CN brut, soit sur sa BASE NUE** (via `foldPrefixOf` + `stripClasseLikePrefix`, correction 4.13) :

```php
if (isset($allowed[mb_strtolower($cn)])) { return true; }            // CN brut
if ($this->foldPrefixOf($cn) !== null) {                             // Classe_/Equipe_/PP_
    return isset($allowed[mb_strtolower($this->stripClasseLikePrefix($cn))]); // base nue
}
```

→ passer le **nom nu** d'une base (ex. `3A`) fait remonter les 3 variantes `Classe_3A`/`Equipe_3A`/`PP_3A` ; le fold 4.13 reste cohérent (1 ligne nue projetée). Couvert par le test existant `it_targets_folded_bare_names_when_syncing_selected_groups`.

### La garde `whereNotIn` est déjà scopée

Le cleanup (l.433) est gardé `if (count($onlyGroupNames) === 0)` : **un read-back scopé ne purge RIEN**, c'est le comportement voulu. La ligne du groupe édité reste persistée via la projection (toujours détectée même si une projection échoue, l.408-409). Donc scoper `updateGroup` :

- élimine le balayage global (gain perf/LDAP pour **tous** les appelants, pas que PP) ;
- supprime le risque de suppression accidentelle hors scope (la purge ne tourne plus en mode scopé).

### Cas RENAME (piège central)

`updateGroup` peut renommer (`$oldName !== $newName`, l.121) : à l'étape 4, **l'AD porte déjà le NOUVEAU CN**. Le scope doit donc cibler la **base du `$newName`**, pas de `$oldName` — sinon le read-back ne verrait pas le groupe renommé et la ligne SQL ne convergerait pas (voire, en mode scopé, ne serait pas re-projetée).

---

## Scope précis (UNIQUEMENT `updateGroup`)

Remplacer dans `updateGroup` (`app/Services/UserGroupService.php:187`) :

```php
$this->syncFromAd();
```

par un read-back **scopé sur la base du nouveau nom** :

```php
// 4.16 — scoper le read-back au seul groupe édité (parité syncGroupsWithAd).
// La base nue fait remonter les 3 variantes Classe_/Equipe_/PP_ (filtre l.335-368),
// donc le fold 4.13 reste cohérent. En mode scopé, le cleanup whereNotIn (l.433)
// ne tourne pas : aucune ligne hors scope n'est purgée.
$readBackScope = $this->resolveReadBackScopeName($newName, $payload['type']);
$this->syncFromAd(onlyGroupNames: [$readBackScope]);
```

### Détermination du nom de scope

- **classe/équipe** : base nue de `$newName` (retirer un éventuel préfixe `Classe_`/`Equipe_`/`PP_`) → `stripClasseLikePrefix($newName)`. C'est exactement ce que fait déjà `resolveSqlLookupName` pour ces types. **Réutiliser `resolveSqlLookupName($newName, $payload['type'])`** plutôt qu'introduire un helper redondant — il renvoie la base nue pour classe/équipe et le CN primaire brut pour les autres types, ce qui est précisément la valeur attendue par le filtre `onlyGroupNames` (qui matche CN brut OU base nue).
- **autres types** (`cours`, `matiere`, `matiere_classe`, `projet`, `custom`…) : `resolveSqlLookupName` renvoie le CN brut (`Cours_X`, `Matiere_X@Y`…) = le `name` SQL persisté ET un CN matchable par le filtre (branche CN brut). Pas de préfixe foldable → scope = le CN tel quel. Conforme au piège n°5.

> **Recommandation d'implémentation** : ne PAS créer de nouvelle méthode si `resolveSqlLookupName` suffit. Le `$lookupName` calculé l.192 APRÈS `syncFromAd` est déjà `resolveSqlLookupName($newName, $payload['type'])` : le dev peut **hisser** ce calcul AVANT le read-back et réutiliser la même valeur pour `onlyGroupNames` ET le lookup post-sync (une seule source, pas de divergence). C'est l'approche la plus propre.

---

## HORS SCOPE 4.16

- **`deleteGroup` (l.206-221)** — NE PAS TOUCHER. Il appelle `syncFromAd()` non scopé et **DÉPEND** du cleanup `whereNotIn` pour retirer la ligne SQL du groupe supprimé : le CN n'est plus dans le lot AD → la ligne tombe dans le `whereNotIn` → supprimée. Un read-back scopé sur le groupe supprimé ne purgerait rien et laisserait la ligne fantôme. **Conserver le read-back global de `deleteGroup`.**
- **`createGroup` (l.51-107)** — HORS SCOPE déclaré par Henri (story = `updateGroup` uniquement). Il appelle aussi `syncFromAd()` non scopé. Un create n'a pas le risque `whereNotIn` du même ordre (le nouveau groupe EST dans le lot AD), mais le balayage global y subsiste. **NE PAS le modifier dans cette story** (scope déclaré). Tracer comme follow-up éventuel si Henri le souhaite (note de suivi, voir « Follow-ups »). Toute modification de `createGroup` exigerait une justification explicite.
- **Signature de `syncFromAd`** — déjà OK (paramètre `onlyGroupNames` existant l.308). NE PAS la modifier.
- **Filtre `onlyGroupNames` (l.335-368)** — déjà correct (matche CN brut + base nue). NE PAS le modifier.
- **Moteur de fold 4.13** (`buildFoldedGroups`/`shouldFold`) — intact. ⚠️ **AMENDEMENT post-dev (Q1, review opus)** : `foldPrefixOf`/`stripClasseLikePrefix`/`detectTypeFromAdGroupName` ont dû être rendus **insensibles à la casse** — voir D6. Le reste du moteur de fold est intact.
- **Migration/flag 4.14** (`MergeLegacyUserGroups`, colonne `is_head_teacher`, read-back du flag dans la boucle de fold) — intact.
- **Écriture `PP_`/UI 4.15** (`syncRoleAwareAdGroupMembers` 3ᵉ cible, `head-teacher-section.blade.php`) — intact.
- **Pas de bump agent** (aucun fichier `agent/**`).

---

## Décisions de cadrage (actées)

- **D1 — Scope = `resolveSqlLookupName($newName, $type)`** (réutilisation, pas de nouvel helper). Base nue pour classe/équipe (remonte les 3 variantes), CN brut pour les autres types. Le filtre `onlyGroupNames` matche les deux.
- **D2 — Cibler le NOUVEAU nom (`$newName`), jamais `$oldName`.** Après rename, l'AD porte le nouveau CN ; scoper sur l'ancien manquerait le groupe renommé.
- **D3 — `deleteGroup` reste NON scopé** (dépend de `whereNotIn` pour retirer la ligne supprimée).
- **D4 — `createGroup` non modifié** (hors scope déclaré ; pas de justification de l'inclure).
- **D5 — Hisser le calcul `$lookupName` AVANT le read-back** et le réutiliser pour `onlyGroupNames` ET le lookup post-sync (source unique, anti-divergence). Le comportement du lookup post-sync reste identique.
- **D6 — Fold/détection de type rendus INSENSIBLES À LA CASSE** (acté post-dev, review opus, décision Henri Q1 « accepter dans 4.16 + documenter »). **Cause** : l'AD réel du parc porte des CN legacy en minuscule (`classe_3a`/`equipe_3a`/`pp_3a`, cf. `project_vm_ad_junk_classe_groups`). Le read-back scopé passe le nom nu (`3a`) à `onlyGroupNames` ; avec l'ancien `foldPrefixOf` (`str_starts_with($cn,'Classe_')`), un CN `classe_3a` renvoyait `null` → exclu du filtre → read-back scopé = no-op → `updateGroup` lèverait « introuvable après synchronisation » sur le parc réel. Le fix est donc **indissociable** du scoping. **Trou de spec assumé** : la story d'origine décrivait l'AD en CN canoniques `Classe_3A`, hypothèse contredite par la réalité du parc. Changement : `foldPrefixOf`/`stripClasseLikePrefix`/`detectTypeFromAdGroupName` passent en `strncasecmp` (valeurs de retour inchangées ; impacte le chemin de fold global ET scopé). **Bénéfice collatéral** : le fold/type des CN minuscules était déjà cassé sur le chemin global (3 lignes au lieu d'1, `pp_3a` classé `custom`) — 4.16 le corrige.
- **D7 — Cohérence casse à la création (Q2)** : `guardReservedPrefixOnCreate` (garde-fou préfixe réservé) et la branche `matiere_classe` de `resolvePrimaryGroupName` passent aussi en `strncasecmp`, pour qu'une saisie minuscule (`pp_terminale`, `matiere_x@y`) soit traitée comme sa forme canonique (pas de CN à double préfixe `Classe_classe_x` / `Matiere_matiere_x`).

---

## Critères d'acceptation

1. **Read-back scopé** — `updateGroup($id, ['name'=>'3A','type'=>'classe',...])` appelle `syncFromAd(onlyGroupNames: ['3A'])` (et non `syncFromAd()` global). Vérifiable par espionnage du `fetchEligibleAdGroups`/filtre ou par l'absence de suppression hors scope (AC4).
2. **Le read-back ciblé voit les 3 variantes de la base** — sur AD `Classe_3A={alice}`, `Equipe_3A={bob}`, `PP_3A={bob}`, après `updateGroup` du groupe `3A`, la ligne nue `3A` a bien pour membres `{alice, bob}` (union des 3 CN, fold 4.13 cohérent) et `(3A,bob).is_head_teacher=true` (read-back du flag 4.14). Le scope nu `3A` n'a PAS amputé une variante.
3. **Rename scopé sur le nouveau nom** — `updateGroup` qui renomme une classe `3A` → `3B` (AD porte déjà `Classe_3B`/`Equipe_3B`/`PP_3B`) : le read-back est scopé sur `3B` (base du nouveau nom), la ligne SQL converge sur `3B` avec ses membres. Un scope sur l'ancien nom `3A` (qui ne verrait rien) est exclu.
4. **`updateGroup` ne purge AUCUN groupe hors scope** — un second groupe `5C` (ligne SQL préexistante, présent OU momentanément ABSENT du lot AD renvoyé) n'est PAS supprimé par un `updateGroup` portant sur `3A`. Le cleanup `whereNotIn` ne tourne pas en mode scopé.
5. **Types non classe/équipe** — `updateGroup($id, ['name'=>'Maths','type'=>'cours',...])` scope sur le CN brut (`Cours_Maths` via `resolveSqlLookupName`) : la ligne `Cours_Maths` converge, aucun autre groupe n'est purgé.
6. **Convergence PP préservée (non-régression 4.15 D2)** — l'ordre reste : écriture AD (`syncRoleAwareAdGroupMembers`, `PP_` compris) AVANT le read-back scopé. Le flag `is_head_teacher` est re-posé depuis `PP_<base>` par le read-back **ciblé** ; l'aller-retour est stable (le flag ne clignote pas).
7. **Préservation des PP sur édition de membres (non-régression M6 4.15)** — `updateGroup` avec `user_ids` SANS `head_teacher_ids` (edit-form) préserve les PP existants ET le read-back scopé ne les efface pas (le scope nu remonte `PP_<base>`).
8. **Lookup post-sync inchangé** — le groupe est toujours retrouvé après read-back (`resolveSqlLookupName` au nom nu pour classe/équipe, CN brut sinon) ; aucune `RuntimeException` « introuvable après synchronisation ».
9. **Non-régression épic 4.12/4.13/4.14/4.15** — partition `Equipe_`/`Classe_` (4.12), fold import (4.13), read-back flag + migration data (4.14), écriture `PP_` + UI (4.15) restent verts. `deleteGroup`/`createGroup`/`syncGroupsWithAd`/filtre `onlyGroupNames`/boucle de fold NON modifiés.
10. **Tests hôte verts** — suite ciblée verte (cf. Tâches T3) : `UserGroupServiceLegacyCompatibilityTest`, `HeadTeacherSectionTest`, `MergeLegacyUserGroupsMigrationTest`, `UserPolicyResetPasswordScopedTest`, `UsersListingScopedTest` (s'il existe) ; 0 régression ; nouveaux tests 4.16 (AC1–AC4) verts.

---

## Tasks / Subtasks

- [x] **T1 — Scoper le read-back de `updateGroup`** (AC1, AC2, AC3, AC5, AC6, AC8) — `app/Services/UserGroupService.php`
  - [x] T1.1 Hisser le calcul `$lookupName = $this->resolveSqlLookupName($newName, $payload['type']);` AVANT l'appel `syncFromAd` (actuellement l.187), réutiliser cette valeur pour le lookup post-sync (l.192) ET le scope (D5, anti-divergence).
  - [x] T1.2 Remplacer `$this->syncFromAd();` (l.187) par `$this->syncFromAd(onlyGroupNames: [$lookupName]);`. Cibler `$newName` (D2), jamais `$oldName`.
  - [x] T1.3 Commentaire expliquant : (a) base nue remonte les 3 variantes via le filtre l.335-368, (b) `whereNotIn` ne tourne pas en mode scopé (l.433), (c) ordre AD-avant-read-back préservé (non-régression 4.15 D2).
  - [x] T1.4 Vérifier que la branche rename ET la branche description-conditionnelle (M1) aboutissent au même read-back scopé (un seul appel `syncFromAd` à la fin, comme aujourd'hui).
- [x] **T2 — Garde HORS SCOPE** (AC9) — `app/Services/UserGroupService.php`
  - [x] T2.1 `deleteGroup` (l.206-221) : NE PAS toucher le `syncFromAd()` global (dépend de `whereNotIn` pour retirer la ligne supprimée — D3). Commentaire « read-back global VOULU » ajouté.
  - [x] T2.2 `createGroup` (l.96) : NON modifié (D4, hors scope déclaré).
  - [x] T2.3 Signature `syncFromAd`, filtre `onlyGroupNames`, moteur de fold, migration 4.14, écriture/UI 4.15 : intacts.
- [x] **T3 — Tests hôte** (AC1, AC2, AC3, AC4, AC10) — `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php`
  - [x] T3.1 `it_scopes_read_back_to_edited_group_on_update` (AC1+AC2) : 39 tests verts, nouveaux cas inclus.
  - [x] T3.2 `it_does_not_purge_out_of_scope_groups_on_update` (AC4) : `5C` survit après updateGroup(`3A`).
  - [x] T3.3 `it_scopes_read_back_to_new_name_on_rename` (AC3) : rename `3A`→`3B`, convergence `3B`, `5C` non purgé.
  - [x] T3.4 `it_scopes_read_back_for_non_class_type` (AC5) : type `cours`, scope CN brut, `Cours_Phys` non purgé.
  - [x] T3.5 Non-régression PP verts : `it_keeps_pp_stable_after_syncFromAd_roundtrip`, `it_preserves_head_teachers_when_updateGroup_omits_head_teacher_ids`.
  - [x] T3.6 Suite combinée verte : `UserGroupServiceLegacyCompatibilityTest` (39/39) + `HeadTeacherSectionTest` + `MergeLegacyUserGroupsMigrationTest` + `UserPolicyResetPasswordScopedTest` → 40 tests verts, 0 régression. `renameGroup` mock ajouté dans `makeService`.
- [x] **T4 — Doc QA append-only** (AC10) — `docs/qa/domains/rights-management.md`
  - [x] T4.1 Section 10 ajoutée (scénarios 10.1–10.4, checklist pré-prod).
  - [x] T4.2 Runbook E2E /vm différé post-merge documenté en §10.
- [x] **T5 — Non-régression aval** (AC9) — `deleteGroup`/`createGroup`/`syncGroupsWithAd`/filtre `onlyGroupNames`/boucle de fold/`MergeLegacyUserGroups`/`syncRoleAwareAdGroupMembers`/UI 4.15 : aucune modification.

---

## Dev Notes

### Ancrage code (chemins:lignes vérifiés 2026-06-25)

| Élément | Fichier:ligne | Rôle |
|---|---|---|
| **`updateGroup` — read-back à scoper** | `app/Services/UserGroupService.php:187` (`$this->syncFromAd();`) | **Cœur T1** — remplacer par `syncFromAd(onlyGroupNames: [$lookupName])` |
| `updateGroup` — branche rename | `app/Services/UserGroupService.php:121-126` | `$newName` porte déjà le nouveau CN AD au read-back (D2) |
| `updateGroup` — lookup post-sync (à hisser) | `app/Services/UserGroupService.php:192` (`resolveSqlLookupName`) | Réutiliser comme scope (D5) |
| `resolveSqlLookupName` (helper réutilisé) | `app/Services/UserGroupService.php:1082-1091` | Base nue (classe/équipe) ou CN brut (autres) = valeur de scope |
| **Filtre `onlyGroupNames` (NE PAS toucher)** | `app/Services/UserGroupService.php:335-368` | Matche CN brut **ET** base nue → nom nu remonte les 3 variantes |
| **Garde `whereNotIn` (NE PAS toucher)** | `app/Services/UserGroupService.php:433-442` | `if (count($onlyGroupNames) === 0)` → purge SEULEMENT en mode global |
| Précédent éprouvé : `syncGroupsWithAd` | `app/Services/UserGroupService.php:243-266` | Patron `syncFromAd(onlyGroupNames: ...)` à imiter |
| **`deleteGroup` — read-back global VOULU (NE PAS toucher)** | `app/Services/UserGroupService.php:206-221` | Dépend de `whereNotIn` pour retirer la ligne supprimée (D3) |
| `createGroup` — non modifié (D4) | `app/Services/UserGroupService.php:96` | Hors scope déclaré |
| `stripClasseLikePrefix` (base nue) | `app/Services/UserGroupService.php:1007-1016` | Utilisé par `resolveSqlLookupName` pour classe/équipe |
| `syncRoleAwareAdGroupMembers` (4.15, ordre D2 préservé) | `app/Services/UserGroupService.php:912-973` | Appelé AVANT le read-back ; inchangé |
| Test patron scope ciblé existant | `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php:822-862` (`it_targets_folded_bare_names_when_syncing_selected_groups`) | **Modèle T3** : `makeService`/`adGroupRow`/3 variantes/nom nu |
| Test convergence PP (non-régression) | `…CompatibilityTest.php:1387` (`it_keeps_pp_stable_after_syncFromAd_roundtrip`) | AC6 — doit rester vert |
| Test préservation PP (non-régression M6) | `…CompatibilityTest.php:1422` (`it_preserves_head_teachers_when_updateGroup_omits_head_teacher_ids`) | AC7 — doit rester vert |
| Doc QA append-only | `docs/qa/domains/rights-management.md` (§9 = 4.15, dernier numéroté l.612) | Section 10 (T4) |

### Pièges & points d'attention

- **PIÈGE n°1 — le scope nu DOIT voir les 3 variantes.** Le filtre `onlyGroupNames` (l.355-363) matche chaque CN AD sur le CN brut OU sa base nue. Passer `3A` (nom nu) fait donc remonter `Classe_3A`/`Equipe_3A`/`PP_3A` et le fold 4.13 les replie en UNE ligne nue avec union des membres + flag PP. Si le dev passait `Classe_3A` (CN préfixé) au lieu du nom nu, le filtre ne matcherait QUE `Classe_3A` (la branche CN brut ne déclenche pas la branche base-nue) → `Equipe_3A`/`PP_3A` exclus → membres profs et flag PP perdus à l'édition. **→ passer la base NUE pour classe/équipe** (c'est ce que renvoie `resolveSqlLookupName`).
- **PIÈGE n°2 — RENAME cible le NOUVEAU nom.** Au read-back, l'AD porte déjà `Classe_<newName>`. Scoper sur `$oldName` → le read-back ne voit rien (mode scopé : aucune projection, et la garde `whereNotIn` étant désactivée, la ligne ne converge pas). Toujours `$newName`.
- **PIÈGE n°3 — `whereNotIn` reste correct en mode scopé.** En mode scopé la purge ne tourne PAS (l.433) : c'est exactement ce qu'on veut (ne pas toucher hors scope). La ligne du groupe édité reste persistée par sa projection (détectée même si la projection échoue, l.408-409). Ne PAS « compenser » par une suppression manuelle.
- **PIÈGE n°4 — NE PAS toucher `deleteGroup`.** Sa suppression de ligne SQL repose sur le `whereNotIn` du mode global : le CN supprimé n'est plus dans le lot → ligne purgée. Un scope sur le groupe supprimé ne purgerait rien (D3). Laisser `deleteGroup` en read-back global et le commenter.
- **PIÈGE n°5 — types non classe/équipe.** Pas de préfixe foldable → `resolveSqlLookupName` renvoie le CN brut (`Cours_X`, `Matiere_X@Y`…), qui est à la fois le `name` SQL persisté et un CN matchable par le filtre (branche CN brut). Le scope = ce CN. Correct sans cas particulier.
- **Ordre AD-avant-read-back (non-régression 4.15 D2)** : la 3ᵉ cible `PP_<base>` est écrite par `syncRoleAwareAdGroupMembers` AVANT le `syncFromAd`. Le read-back scopé re-pose le flag depuis ce `PP_<base>`. L'ordre est inchangé par cette story (on ne déplace que le paramètre de `syncFromAd`).
- **Mock `GroupRepository` en test** : asserter via l'état projeté (membres/flags de la ligne nue) et l'absence de purge hors scope. Pour AC4, configurer le mock `getGroupsWithMemberCount`/`fetchEligibleAdGroups` pour NE PAS renvoyer le groupe hors scope (`5C`) lors de l'édition, puis vérifier qu'il survit (ce qui prouve que `whereNotIn` n'a pas tourné). Réutiliser `makeService(collect([...]), [], [...])` et `adGroupRow` (patron l.830-842).
- **`User::$ldapCache`** : purger en `tearDown` si les tests exercent `isProf()` (partition prof/élève — déjà fait par la classe de test).

### Project Structure Notes

- **Édition service minimale** : 1 ligne fonctionnelle modifiée dans `updateGroup` (+ hissage du `$lookupName` + commentaires). Aucun nouveau fichier service, aucun helper nouveau (réutilisation `resolveSqlLookupName`).
- **Pas de migration de schéma**, **pas de fold/migration data nouveaux**, **pas d'UI**, **pas de bump agent**.
- **Tests** : 4 nouveaux cas dans `UserGroupServiceLegacyCompatibilityTest` (T3.1–T3.4) ; non-régression sur la suite ciblée.
- **Doc QA append-only** : `docs/qa/domains/rights-management.md` Section 10 (§1–9 non renumérotées).

### Environnement de test (HÔTE)

- **Tests sur l'HÔTE** (php 8.4 + sqlite + vendor, `vendor/bin/phpunit`), PAS sur la VM (`memory/project_phpunit_test_env_host_vs_vm.md`, CLAUDE.md). SQLite ne contraint pas varchar.
- Mocker `GroupRepository` via `makeService` ; `primeNoLdap()` pour court-circuiter LDAP. Purger `User::$ldapCache` en `tearDown`.
- Lancer ciblé : `vendor/bin/phpunit --filter UserGroupServiceLegacyCompatibilityTest`, puis la suite combinée (cf. T3.6). Run massif VM = faux échecs (`memory/project_vm_phpunit_bulk_run_false_failures.md`) — valider par filtres ciblés.
- E2E /vm (réduction du balayage AD observable dans les logs) différé post-merge, runbook §10.

### Dépendances (avec statut)

- **4.13 — code sur `main` (`c5b99e5`).** Fold import + filtre `onlyGroupNames` matchant la base nue (l.335-368) — LE mécanisme que cette story exploite. Disponible.
- **4.14 — code sur `main` (`a712794`).** Read-back du flag `is_head_teacher` dans la boucle de fold — re-posé par le read-back scopé. Disponible.
- **4.15 — code sur `main` (commits récents : `f9e848e` feat + `d13f04a` review + `4262953` fix).** 3ᵉ cible `PP_`, ordre D2 (AD avant read-back), micro-fix M1 (description conditionnelle), correctif M6 (préservation PP). Disponible. **C'est la review 4.15 (Q1) qui ouvre cette story.**
- **`syncGroupsWithAd` (existant).** Précédent éprouvé du `syncFromAd(onlyGroupNames: ...)`. Disponible.

### Risques

- **R1 — Scope sur CN préfixé au lieu de la base nue** → amputation des variantes `Equipe_`/`PP_` à l'édition (perte profs + flag PP). Mitigation : `resolveSqlLookupName` renvoie la base nue pour classe/équipe (D1), AC2 vérifie les 3 variantes. **Sévérité haute.**
- **R2 — Scope sur l'ancien nom au rename** → ligne non convergente. Mitigation : cibler `$newName` (D2), AC3. **Sévérité moyenne.**
- **R3 — Régression `deleteGroup`** si le dev « uniformise » les read-backs. Mitigation : `deleteGroup` HORS SCOPE + commentaire (D3, T2.1), AC9. **Sévérité moyenne.**
- **R4 — Clignotement du flag PP** si l'ordre AD-avant-read-back est cassé. Mitigation : ordre inchangé (on ne touche que le paramètre), AC6, test `it_keeps_pp_stable_after_syncFromAd_roundtrip`. **Sévérité moyenne.**
- **R5 — `createGroup` toujours global** (balayage subsistant). Assumé HORS SCOPE (D4) ; follow-up éventuel. **Sévérité faible (perf résiduelle).**

### References

- [Source: app/Services/UserGroupService.php:187] — read-back `syncFromAd()` non scopé de `updateGroup` (cible T1)
- [Source: app/Services/UserGroupService.php:243-266] — `syncGroupsWithAd` (patron `syncFromAd(onlyGroupNames: ...)`)
- [Source: app/Services/UserGroupService.php:335-368] — filtre `onlyGroupNames` (CN brut + base nue)
- [Source: app/Services/UserGroupService.php:433-442] — cleanup `whereNotIn` gardé au mode global
- [Source: app/Services/UserGroupService.php:206-221] — `deleteGroup` (read-back global VOULU, HORS SCOPE)
- [Source: app/Services/UserGroupService.php:1082-1091] — `resolveSqlLookupName` (valeur de scope)
- [Source: tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php:822-862] — patron de test scope ciblé
- [Source: _bmad-output/codeReviews/4-15.md] — problème #3 + M1 + Q1 (origine de la story)
- [Source: _bmad-output/implementation-artifacts/4-15-ecriture-pp-ad-ui-professeur-principal.md] — story amont (ordre D2, M6)
- [Source: memory/project_usergroup_sql_fold_bare_name.md] — direction fold nom nu (4.13/4.14/4.15)
- [Source: memory/project_sync_from_ad_transitional.md] — AD-first transitoire

### Previous Story Intelligence (4.13/4.14/4.15)

- 4.13 a introduit `onlyGroupNames` matchant la base nue : `syncGroupsWithAd` passe les noms NUS et le filtre remonte les 3 variantes. 4.16 réutilise exactement ce chemin pour `updateGroup`.
- 4.14 pose le flag `is_head_teacher` au read-back depuis `PP_<base>`. Le read-back scopé doit donc remonter `PP_<base>` (→ scope nu).
- 4.15 (M6) a corrigé un effacement silencieux des PP : `updateGroup` sans `head_teacher_ids` dérive les PP du pivot. 4.16 ne doit PAS ré-introduire de perte de PP — le scope nu remonte `PP_<base>`, la convergence tient (AC7).
- 4.15 (D2) : AD écrit AVANT `syncFromAd`. 4.16 ne déplace pas cet appel, ajoute seulement `onlyGroupNames`.
- Process 4.12→4.15 : tests hôte ciblés, `GroupRepository` mocké (asserter l'état projeté/les appels), E2E /vm différé post-merge, doc QA append-only, purge `User::$ldapCache` en `tearDown`.

### Follow-ups connus (hors périmètre 4.16)

- **`createGroup` toujours en read-back global** — scoping possible sur le nouveau nom, mais non demandé (D4). À tracer si Henri souhaite uniformiser après 4.16.
- **Non-déterminisme cosmétique du `name` foldé en casse mixte** (#8, review opus — décision Henri Q3 : follow-up). `buildFoldedGroups` projette `name = base` du 1er CN rencontré (suffixe casse d'origine) ; clé de fold = `mb_strtolower`. En casse mixte (`Classe_3A` + `equipe_3a`), le `name` SQL peut « clignoter » `3A`/`3a` selon l'ordre AD. Fonctionnellement OK (`LOWER(name)` neutralise le lookup), seul l'affichage UI varie. **Story dédiée à créer** : canonicaliser le `name`/`display_name` depuis le préfixe canonique retenu (`Classe_` prioritaire).

---

## Recommandation Modèle Dev

**`sonnet`.**

Justification :
- **Surface minuscule** : une ligne fonctionnelle à scoper dans `updateGroup`, sur un mécanisme (`syncFromAd(onlyGroupNames: ...)`) DÉJÀ existant, éprouvé et testé via le précédent `syncGroupsWithAd`. Aucun design nouveau, aucun helper nouveau (réutilisation de `resolveSqlLookupName`).
- **Invariants subtils, mais explicités et bornés** : les trois pièges (le scope nu doit voir les 3 variantes ; le rename cible le nouveau nom ; `whereNotIn` ne purge pas en mode scopé) sont entièrement documentés dans cette story avec chemins:lignes et un patron de test copiable (`it_targets_folded_bare_names_when_syncing_selected_groups`). Le dev n'a pas à les redécouvrir.
- **HORS SCOPE net** (`deleteGroup`/`createGroup` à ne pas toucher) explicitement gardé avec justification — faible risque de dérive.
- Le coût d'opus serait dans la *découverte* des invariants ; or cette découverte est déjà faite et consignée. Le reste est une exécution dirigée + 4 tests calqués sur un existant.

> Bascule vers `opus` SEULEMENT si, à la lecture, le dev juge que le couplage fold/`onlyGroupNames`/`whereNotIn` exige une ré-analyse de bout en bout — ce que cette story vise précisément à rendre inutile.

---

## File List

### Modifiés
- `app/Services/UserGroupService.php` —
  - T1 (hissage `$lookupName`, scopage `syncFromAd(onlyGroupNames:)`) + T2.1 (commentaire `deleteGroup`).
  - **D6 (Q1)** : `foldPrefixOf`, `stripClasseLikePrefix`, `detectTypeFromAdGroupName` rendus insensibles à la casse (`str_starts_with` → `strncasecmp`) — nécessaire au read-back scopé sur l'AD réel en CN minuscules.
  - **D7 (Q2)** : `guardReservedPrefixOnCreate` + branche `matiere_classe` de `resolvePrimaryGroupName` rendues insensibles à la casse (cohérence).
- `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` — T3 (4 tests de scoping 4.16 + mock `renameGroup`) + tests casse : `it_folds_lowercase_legacy_cn_variants_case_insensitively`, `it_folds_mixed_case_legacy_cn_variants_into_one_group`, `it_scopes_read_back_to_edited_group_on_update_with_lowercase_ad_cns` (#5), `it_rejects_lowercase_reserved_prefix_name_for_classe_like_create` + `it_does_not_re_expand_lowercase_prefixed_matiere_classe_cn` (Q2).
- `docs/qa/domains/rights-management.md` — T4 (Section 10 append-only) + note « casse des CN AD » + Scénario 10.5 (read-back scopé sur CN minuscules).

### Non modifiés (conformes HORS SCOPE)
- `deleteGroup` (read-back global D3), `createGroup` (D4), signature de `syncFromAd`, filtre `onlyGroupNames`, `buildFoldedGroups`/`shouldFold`, migration 4.14, écriture/UI 4.15 : intacts.

---

## Dev Agent Record

- **Date** : 2026-06-25
- **Modèle dev** : sonnet / claude-sonnet-4-6 ; **review** : opus / claude-opus-4-8[1m] (adversariale) ; **corrections post-review** : opus (orchestrateur)
- **Branche** : main
- **Tests** : `UserGroupServiceLegacyCompatibilityTest` **43/43** (4 tests scoping 4.16 + 3 tests casse Q1/Q2 + 2 tests fold lowercase/mixte) + suite non-régression 40/40 — 0 régression
- **Résumé** : Scopage du read-back `syncFromAd` d'`updateGroup` (T1) + garde `deleteGroup` (T2). **Découverte en review** : le scoping casse l'édition de groupe sur l'AD réel (CN minuscules) → fold/type rendus insensibles à la casse (D6, Q1) + cohérence à la création (D7, Q2). Voir `_bmad-output/codeReviews/4-16.md`.
- **Review** : verdict initial « à revoir » (2 blocages 🔴 : #1 index incohérent test/code, #2 scope creep casse non tracé). #1 corrigé (re-stage version cohérente + 80/80 verts) ; #2 tracé (D6 + File List) ; Q1=accepter+documenter, Q2=harmoniser+test, Q3=follow-up (#8 non-déterminisme name foldé).
