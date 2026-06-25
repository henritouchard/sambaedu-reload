# Story 4.15 : Écriture SQL→AD `PP_<X>` pilotée par `is_head_teacher` + UI « Professeur principal »

Status: review

> **Type** : (1) **écriture SQL→AD** — 3ᵉ cible `PP_<base>` dans `syncRoleAwareAdGroupMembers`, pilotée par le flag d'arête `is_head_teacher` posé par 4.14 ; (2) **UI** — section/contrôle « Professeur principal » dans la fiche de groupe (désigner/voir les PP d'une classe). Pas de migration de schéma (la colonne existe — 4.14), pas de fold (livré 4.13).
>
> **Origine** : Epic 4 — gestion des groupes utilisateurs. **3ᵉ et DERNIÈRE** des 3 stories de la refonte « groupes au nom nu » (4.13 fold import *review* / 4.14 migration data + `is_head_teacher` *review* / **4.15 écriture SQL→AD `PP_` + UI**). Suite **directe** de 4.14 : la section **HORS SCOPE 4.14** renvoie explicitement l'écriture `PP_` et l'UI ici. 4.14 a posé le flag en **LECTURE** (read-back à l'import) ; il n'est **consommé par personne**. 4.15 le rend **effectif** : projection AD + saisie utilisateur.
>
> **Direction validée (Henri, 2026-06-24)** : le professeur principal est un **attribut d'arête** `is_head_teacher` (bool) sur le pivot `user_group_user` (PAS un groupe séparé côté modèle SE5). Côté AD, le CN `PP_<base>` reste la projection legacy : les membres à `is_head_teacher=true` d'une classe foldée sont écrits dans le groupe AD `PP_<base>`. Plusieurs PP par classe autorisés. Flag pertinent uniquement pour les groupes `classe`/`equipe`.
>
> **Mémoire projet liée** : `memory/project_usergroup_sql_fold_bare_name.md` (direction + blast radius, stories 4.13/4.14/4.15), `memory/project_pivot_global_memberships.md` (modèle pivot global, invariants), `memory/project_equipe_group_never_populated_se5.md` (4.12 — partition ACL prof), `memory/project_sync_from_ad_transitional.md` (AD-first transitoire), `feedback_per_group_property_belongs_on_group_pages.md` (propriété par-groupe = edit-form du groupe, pas onglet global).

---

## Story

En tant que **responsable d'établissement et développeur SE5**,
je veux **(1) que l'écriture SQL→AD des membres d'une classe foldée projette les professeurs principaux (arêtes `is_head_teacher=true`) vers le groupe AD `PP_<base>`** — 3ᵉ cible à côté de `Equipe_<base>` et `Classe_<base>` — **et (2) une UI dans la fiche du groupe pour désigner et visualiser le(s) professeur(s) principal(aux) d'une classe**,
afin que la parité legacy `PP_<X>` soit rétablie au nom nu (le rôle PP redevient effectif côté AD/legacy) et que l'opérateur puisse gérer le PP sans passer par l'AD directement — clôturant la refonte « groupes au nom nu ».

---

## Contexte & cause racine (lecture code 2026-06-25)

### Ce que 4.14 a livré (point d'appui — NE PAS refaire)

- **Colonne pivot** `user_group_user.is_head_teacher` (bool, défaut `false`, non nullable) — migration `2026_06_25_120000_add_is_head_teacher_to_user_group_user.php`. PK composite `(user_group_id, user_id)`, pas de timestamps.
- **Cast bool** sur `App\Models\Pivot\UserGroupUserPivot` (`protected $casts = ['is_head_teacher' => 'boolean']`) — lecture fiable SQLite 0/1 vs PG true/false.
- **`->withPivot('is_head_teacher')`** sur `UserGroup::users()` (`app/Models/UserGroup.php:64-74`) — relation d'**écriture** du fold. **PAS** sur `User::userGroups()`/`User::groups()` (D4 minimal de 4.14). → si 4.15 a besoin de lire le flag côté `User`, ajouter `withPivot` sur la relation lue (cf. D3 ci-dessous), sinon lire via `UserGroup::users()`.
- **Read-back à l'import** : `UserGroupService::syncFromAd` boucle de fold (`app/Services/UserGroupService.php:435-460`) pose `is_head_teacher=true` sur les arêtes des membres venus du CN `PP_<base>` via un `users()->sync()` ASSOCIATIF `[$id => ['is_head_teacher' => $isPP]]`. Idempotent, union/dédup 4.13 préservée.
- **Migration data** : `app/Actions/Groups/MergeLegacyUserGroups.php` fusionne les lignes héritées et pose `is_head_teacher` (y compris pour un `PP_<X>` isolé).

**Limite connue D5 de 4.14** (verbatim) : « le flag `is_head_teacher` est en place mais n'est encore **consommé** par aucune projection AD ni aucune UI. » → C'est **exactement** le scope 4.15.

### Le canal d'écriture SQL→AD aujourd'hui (2 cibles, pas 3)

`syncRoleAwareAdGroupMembers(string $rawName, string $type, array $selectedUserIds)` (`app/Services/UserGroupService.php:817-861`), appelé par `createGroup` (l.78) et `updateGroup` (l.125) :

```php
// classe/équipe : base nue + partition isProf()
$baseName = $this->stripClasseLikePrefix($rawName);
// … partition $profIds / $nonProfIds via User::isProf() …
$this->syncAdGroupMembersByUserIds("Equipe_{$baseName}", $profIds);     // profs
$this->syncAdGroupMembersByUserIds("Classe_{$baseName}", $nonProfIds);  // reste
```

→ Il écrit **2** groupes AD (`Equipe_`/`Classe_`). **Il n'écrit JAMAIS `PP_<base>`** (D1 explicite de 4.12 : « PP_X non peuplé »). Et surtout : **il ne reçoit que `$selectedUserIds` scalaires** — il ne connaît PAS qui est PP. L'info PP vit sur le **pivot** (`is_head_teacher=true`), pas dans `$selectedUserIds`.

`syncAdGroupMembersByUserIds(string $groupName, array $selectedUserIds)` (l.909-947) : diff idempotent fail-soft (add les manquants, remove les connus-SQL en trop). **Réutilisable tel quel** pour la 3ᵉ cible `PP_<base>`.

### Les deux trous que 4.15 comble

1. **Écriture `PP_<base>` absente.** `syncRoleAwareAdGroupMembers` doit ajouter une 3ᵉ cible `PP_{$baseName}` peuplée par l'ensemble des `user_id` à `is_head_teacher=true` de **la ligne SQL persistée du groupe** (classe foldée). Comme la méthode ne reçoit que des IDs scalaires, elle doit soit (a) recevoir un 4ᵉ paramètre `$headTeacherUserIds` calculé par l'appelant, soit (b) lire le flag depuis le pivot de la ligne courante. → cf. **D1** (param explicite, retenu).
2. **Aucune UI pour saisir le PP.** La fiche de groupe (`resources/views/pages/users/groups/[id]/index.blade.php`, Volt SFC) n'expose aucun contrôle « professeur principal ». L'opérateur ne peut désigner un PP que via l'AD directement. → 4.15 ajoute une **section Livewire SFC** dans la fiche, gated `type === 'classe'`, sur le **patron exact de `class-share-section` (Story 5.2)**.

### AD-first conservé

L'import AD→SQL reste l'outil de migration transitoire (`memory/project_sync_from_ad_transitional.md`). 4.15 ne change PAS la direction : l'**écriture SQL→AD** (`syncRoleAwareAdGroupMembers`) est le **canal de propagation** déjà en place (4.12) ; on lui ajoute une cible. La saisie UI met à jour le **pivot SQL** (source côté SE5) puis la même écriture SQL→AD projette vers `PP_<base>`. Symétrie parfaite avec le read-back 4.14 (AD `PP_` → flag) : 4.15 = flag → AD `PP_`.

---

## Scope précis (UNIQUEMENT « écriture `PP_` + UI Professeur principal »)

### 1. Écriture SQL→AD de la 3ᵉ cible `PP_<base>` (pilotée par `is_head_teacher`)

- Dans `syncRoleAwareAdGroupMembers` (classe/équipe uniquement), ajouter **une 3ᵉ cible** : `syncAdGroupMembersByUserIds("PP_{$baseName}", $headTeacherIds)`, où `$headTeacherIds` = les `user_id` du groupe courant à `is_head_teacher=true`.
- **`PP_<base>` est un SOUS-ENSEMBLE des membres** de la classe — il n'est PAS exclusif de `Equipe_`/`Classe_`. Un prof PP est **à la fois** dans `Equipe_<base>` (parité ACL 4.12, car `isProf()`) **et** dans `PP_<base>` (rôle PP). Un élève marqué PP (cas dégénéré, à interdire/ignorer en UI mais robuste en écriture) serait dans `Classe_<base>` et `PP_<base>`. → l'écriture `PP_` est **orthogonale** à la partition prof/élève : ne PAS retirer le PP de `Equipe_`/`Classe_`.
- **Toujours synchroniser `PP_<base>`** même avec un ensemble PP **vide** (comme `Equipe_`/`Classe_` aujourd'hui : l.857-860 « toujours synchroniser les DEUX cibles ») — pour qu'un retrait du dernier PP vide bien le groupe AD `PP_<base>` (pas de rémanence).
- **D1 — Source des `$headTeacherIds`** : passés par l'appelant (`createGroup`/`updateGroup`) en **4ᵉ paramètre** `array $headTeacherUserIds = []`. L'appelant les lit depuis le **payload UI** (`data['head_teacher_ids']`) APRÈS avoir persisté le pivot. Évite un re-query du pivot dans le service et garde la cohérence avec `$selectedUserIds` (déjà passé en scalaire). **Intersection garde-fou** : `$headTeacherUserIds ∩ $selectedUserIds` (un PP doit être membre ; un id PP hors membres est ignoré — défensif).
- **Garde** : `PP_<base>` n'est écrit QUE pour `type ∈ {classe, equipe}` (même condition `$isClasseLike` que `Equipe_`/`Classe_`). Les types non classe/équipe ne passent jamais par l'expansion `PP_`.
- **Réutiliser `syncAdGroupMembersByUserIds`** (diff idempotent fail-soft) tel quel — NE PAS réimplémenter le diff.

### 2. Persistance du flag `is_head_teacher` au save (pivot SQL)

- Le pivot porte déjà la colonne (4.14). 4.15 doit l'**écrire** depuis l'UI : lors du `save()`/`updateGroup`, persister `is_head_teacher=true` pour les `head_teacher_ids` sélectionnés et `false` pour les autres membres.
- **Mécanisme** : réutiliser le `users()->sync()` ASSOCIATIF (déjà branché par 4.14 sur `UserGroup::users()` via `withPivot`). L'écriture du pivot via l'UI doit produire le même payload `[$userId => ['is_head_teacher' => isset($ppSet[$userId])]]` pour tous les membres. **Attention** : `updateGroup` appelle `syncFromAd()` (read-back depuis AD) APRÈS l'écriture membres — l'ordre doit garantir que l'AD `PP_<base>` est écrit AVANT le read-back, sinon le `syncFromAd` ré-écraserait le pivot avec l'état AD (qui n'a `PP_` peuplé que si on l'a écrit avant). → **D2** : écrire AD (`syncRoleAwareAdGroupMembers` incluant `PP_`) **avant** `syncFromAd()`. Le pivot SQL convergera alors avec l'AD au read-back (cohérence). Tester explicitement (AC8 : aller-retour stable).
- **Nouveau point d'API service** : exposer une méthode (ex. `updateGroup` enrichi du payload `head_teacher_ids`, ou un `setHeadTeachers(int $groupId, array $userIds)` dédié). → **D3** à trancher par le dev (cf. Décisions de cadrage). Privilégier l'enrichissement du payload `updateGroup` pour un seul aller-retour transactionnel.

### 3. UI « Professeur principal » dans la fiche de groupe

- **Emplacement** : fiche de groupe `resources/views/pages/users/groups/[id]/index.blade.php` (Volt SFC). Section visible **UNIQUEMENT si `type === 'classe'`** (les profs principaux n'ont de sens que pour une classe ; `equipe` peut être inclus si le dev le juge cohérent — **D4**, défaut `classe` seul).
- **Patron OBLIGATOIRE** = `_partials/class-share-section.blade.php` (Story 5.2) : SFC anonyme `new class extends Component`, trait `WithToasts`, `#[Locked] int $groupId`, `mount(int $groupId)` qui **abort si le groupe n'est pas une classe**, inclusion via `@livewire('pages::users.groups.[id]._partials.<nom>', ['groupId' => $groupId], key('...'))` gated `@if ($type === 'classe')` dans `index.blade.php` (cf. l.275-277 pour `class-share-section`). **Routing filesystem-based** respecté (`resources/views/pages/`).
- **Fonction** : lister les membres de la classe (réutiliser le pattern `members`/`availableUsers` de l'index), avec un **toggle par membre** « professeur principal » (case à cocher / switch). Visualisation : badge/indicateur PP sur les membres concernés (peut enrichir `_partials/members-list.blade.php` en lecture seule). Plusieurs PP autorisés.
- **Restriction** : ne proposer le toggle PP que pour les membres **profs** (`isProf()`) — un élève PP n'a pas de sens métier (mais l'écriture reste robuste, cf. §1). **D5** : gating UI sur `isProf()` (défaut), écriture défensive côté service.
- **Notifications** : `WithToasts` (jamais `$e->getMessage()` brut — leçon 5.1b/5.2). **Modale réutilisable** si une confirmation est nécessaire (composant modale du projet) — a priori un simple toggle inline suffit, pas de modale obligatoire.
- **Autorisation** : double guard (UI `@can` + serveur `Gate::authorize`) cohérent avec la gestion de membres existante (réutiliser la permission qui régit l'édition du groupe — vérifier la policy de `updateGroup`/édition de groupe ; **ne pas inventer** une nouvelle permission sans besoin). **D6** à confirmer par le dev.

---

## HORS SCOPE 4.15

- **Refonte du modèle multi-vertical** (rôle générique sur l'arête, ProvisioningProfile, zones/matrice) — orientation **parquée** (`memory/project_group_model_multivertical_direction.md`).
- **Re-fold / migration data** : livré 4.13/4.14. NE PAS retoucher `buildFoldedGroups`/`MergeLegacyUserGroups`.
- **Modification du read-back 4.14** (`syncFromAd` boucle de fold posant le flag depuis `PP_<base>`) — déjà correct, NE PAS toucher (4.15 lit/écrit, ne refait pas le read-back).
- **Création UI du PP à la création de groupe** (`new/index.blade.php`) — **différé** : la création passe par le formulaire générique ; la désignation PP se fait sur la fiche existante (édition). Parité création = HORS SCOPE (note de suivi). **D7**.
- **ACL FS spécifiques au PP** : le PP n'ouvre pas de droits FS nouveaux (il reste dans `Equipe_`, qui porte déjà rwx prof — 4.12). NE PAS toucher `ShareService`/`AclService`.

---

## Décisions de cadrage (à acter / actées)

- **D1 — `$headTeacherUserIds` en 4ᵉ paramètre de `syncRoleAwareAdGroupMembers`** (défaut `[]`, rétrocompatible). Calculé par l'appelant depuis le payload UI, **intersecté** avec `$selectedUserIds` (un PP doit être membre). `PP_<base>` écrit en plus de `Equipe_`/`Classe_` (orthogonal, pas exclusif).
- **D2 — Ordre : écrire AD (`PP_` compris) AVANT `syncFromAd()`.** Sinon le read-back ré-écraserait le pivot avec un AD `PP_` encore vide. Garantit la convergence flag SQL ↔ groupe AD `PP_`. Aller-retour stable (AC8).
- **D3 — Persistance pivot via payload `updateGroup`** (un seul aller-retour). Le payload `updateGroup` accepte `head_teacher_ids` ; le service écrit le pivot (sync associatif) PUIS l'AD. *Alternative* `setHeadTeachers()` dédié : possible mais multiplie les allers-retours AD (`syncFromAd` rejoué). **Retenu : payload enrichi.** À confirmer par le dev si un endpoint Livewire dédié (toggle réactif sans full save) est préférable UX — dans ce cas, encapsuler la même logique service.
- **D4 — UI gated `type === 'classe'`** (défaut). `equipe` peut être inclus si cohérent ; documenter le choix. Le SFC `mount()` abort si type non éligible (anti-forge payload, cf. 5.2).
- **D5 — Toggle PP proposé uniquement pour les membres `isProf()`** côté UI ; écriture service défensive (intersection `$selectedUserIds`, pas de crash si élève PP forgé).
- **D6 — Autorisation = permission d'édition de groupe existante** (réutiliser, double guard). Ne PAS créer de nouvelle permission Spatie sans besoin. Vérifier la policy/`@can` qui régit `startEditing`/`save` aujourd'hui.
- **D7 — Création (`new/index.blade.php`) HORS SCOPE.** PP désigné sur la fiche existante. Note de suivi.
- **D8 — `withPivot` côté lecture si nécessaire.** Si l'UI lit `is_head_teacher` via `UserGroup::users()` (qui a déjà `withPivot`, 4.14), aucun ajout. Si elle lit via `User::userGroups()`/`groups()`, ajouter `withPivot('is_head_teacher')` sur la relation lue (D4 de 4.14 laissait ce point ouvert). Limiter le blast radius : lire de préférence via `UserGroup::users()`.

---

## Critères d'acceptation

1. **3ᵉ cible `PP_<base>` écrite** — `updateGroup($id, ['name'=>'3A','type'=>'classe','user_ids'=>[prof1,prof2,eleve],'head_teacher_ids'=>[prof1]])` : après l'écriture SQL→AD, le groupe AD `PP_3A` contient **prof1** (et seulement les PP). `Equipe_3A` contient prof1+prof2 (profs), `Classe_3A` contient l'élève (partition 4.12 inchangée). prof1 est donc dans `Equipe_3A` **ET** `PP_3A` (orthogonalité).
2. **`PP_<base>` vidé quand plus de PP** — repasser `head_teacher_ids=[]` (ou retirer le dernier PP) : après écriture, `PP_3A` est **vide** (les anciens membres PP en sont retirés via le diff idempotent). Aucune rémanence. `Equipe_`/`Classe_` inchangés.
3. **Plusieurs PP** — `head_teacher_ids=[prof1,prof2]` : `PP_3A` contient prof1 et prof2.
4. **`PP_` jamais écrit hors classe/équipe** — un groupe `type='cours'`/`'matiere'`/`'custom'` : `syncRoleAwareAdGroupMembers` n'écrit aucun `PP_<base>` (la branche `$isClasseLike` ne s'exécute pas). Aucun appel `addMember("PP_…")`.
5. **Intersection garde-fou** — `head_teacher_ids=[prof1, ghost]` où `ghost ∉ user_ids` : seul prof1 est écrit dans `PP_3A` (ghost ignoré, pas d'exception).
6. **Pivot persisté** — après `save`/`updateGroup` avec `head_teacher_ids=[prof1]`, l'arête `(3A, prof1).is_head_teacher=true` et `(3A, prof2).is_head_teacher=false`, `(3A, eleve).is_head_teacher=false` en SQL.
7. **Idempotence écriture** — deux `updateGroup` consécutifs avec le même `head_teacher_ids` : aucun `addMember`/`removeMember` superflu sur `PP_3A` au 2ᵉ run (diff idempotent), pivot stable.
8. **Aller-retour AD↔SQL stable (D2)** — après `updateGroup` (qui appelle `syncFromAd` en read-back), l'arête PP persistée correspond au CN `PP_3A` projeté : un `syncFromAd` ultérieur ne change ni les membres ni les flags. Le flag SQL ne « clignote » pas (l'AD `PP_` ayant été écrit AVANT le read-back).
9. **UI — section PP visible sur une classe** — la fiche d'un groupe `type='classe'` affiche la section « Professeur principal » ; un groupe `type='cours'` ne l'affiche pas. Un payload Livewire forgé avec un `groupId` non-classe est rejeté en `mount` (abort), comme `class-share-section`.
10. **UI — désigner un PP** — cocher « professeur principal » pour prof1 puis enregistrer : l'arête `(classe, prof1).is_head_teacher=true` est persistée, un toast de succès (`WithToasts`) s'affiche, et la visualisation reflète prof1 comme PP. Décocher le retire.
11. **UI — toggle limité aux profs (D5)** — la case PP n'est proposée que pour les membres `isProf()` ; les élèves n'ont pas de contrôle PP. (Écriture service robuste même si forcé.)
12. **UI — routing/convention** — la section est un Livewire SFC dans `resources/views/pages/users/groups/[id]/_partials/`, incluse via `@livewire(...)` gated `@if ($type === 'classe')` (patron 5.2), utilise `WithToasts`, double guard d'autorisation. Aucune route ad hoc.
13. **Non-régression 4.12/4.13/4.14** — `Equipe_`/`Classe_` partition prof/élève (4.12), fold import (4.13), read-back flag + migration data (4.14) restent verts. `syncFromAd` boucle de fold (4.14) NON modifiée. `ShareService`/`AclService`/`UserPolicy`/listing blade intacts.
14. **Tests hôte verts** — couverture : 3ᵉ cible écrite (AC1), vidage (AC2), multi-PP (AC3), gating type (AC4), intersection (AC5), pivot persisté (AC6), idempotence (AC7), aller-retour stable (AC8), + tests Livewire de la section UI (AC9–AC11 : visibilité gated, désignation, abort non-classe). 0 régression sur la suite ciblée `UserGroupService*` + `UserPolicyResetPasswordScoped`/`UsersListingScoped`.

---

## Tasks / Subtasks

- [x] **T1 — 3ᵉ cible `PP_<base>` dans l'écriture SQL→AD** (AC1, AC2, AC3, AC4, AC5) — `app/Services/UserGroupService.php`
  - [x] T1.1 Signature : `syncRoleAwareAdGroupMembers(string $rawName, string $type, array $selectedUserIds, array $headTeacherUserIds = [])`. Rétrocompatible (défaut `[]`).
  - [x] T1.2 Dans la branche `$isClasseLike` : `$ppIds` = `$headTeacherUserIds` intersecté avec `$selectedUserIds` (garde-fou D1, `array_flip` + `isset`) ; `$this->syncAdGroupMembersByUserIds("PP_{$baseName}", $ppIds)` **en plus** de `Equipe_`/`Classe_` (orthogonal — PP non retiré des deux autres cibles).
  - [x] T1.3 `PP_<base>` toujours synchronisé (même `$ppIds` vide → vidage via le diff idempotent, pas de rémanence — AC2).
  - [x] T1.4 Branche non classe/équipe inchangée (aucun `PP_`, AC4).
- [x] **T2 — Appelants : passer les PP + persister le pivot** (AC6, AC8) — `app/Services/UserGroupService.php` (`createGroup`/`updateGroup`)
  - [x] T2.1 `updateGroup`/`createGroup` acceptent `data['head_teacher_ids']` (array d'IDs, défaut `[]`), normalisé (`array_map intval` + `array_unique`).
  - [x] T2.2 Persistance pivot = option (b) de la story (dérivée du read-back) : l'AD `PP_<base>` est écrit AVANT `syncFromAd()` qui re-pose le flag depuis ce CN (boucle de fold 4.14 inchangée). Pas de double `sync()` qui détacherait les membres ; convergence garantie (AC6/AC8 verts).
  - [x] T2.3 **D2** — Ordre : `syncRoleAwareAdGroupMembers` (incluant `PP_`) appelé AVANT `syncFromAd()` dans createGroup ET updateGroup. Aller-retour stable (AC8, `it_keeps_pp_stable_after_syncFromAd_roundtrip`).
  - [x] T2.4 Intersection `head_teacher_ids ∩ selectedUserIds` côté service (défensif, AC5). Dans `updateGroup`, si `head_teacher_ids` est passé sans `user_ids`, les membres courants sont dérivés du pivot.
- [x] **T3 — UI « Professeur principal » (Livewire SFC)** (AC9, AC10, AC11, AC12) — `resources/views/pages/users/groups/[id]/_partials/head-teacher-section.blade.php` (nouveau) + inclusion dans `index.blade.php`
  - [x] T3.1 SFC anonyme `new class extends Component` sur le patron `class-share-section` (5.2) : `use WithToasts`, `#[Locked] int $groupId`, `mount(int $groupId)` qui **abort 404 si introuvable** + flag `isClasse` (rendu vide si non-classe), `loadClasseOrFail()` qui abort sur `save`, double guard `update-group` (D6 = `user.modify`).
  - [x] T3.2 Lister les membres profs (`isProf()`) via `profMembers()` avec un toggle « professeur principal » (état lu via `UserGroup::users()->pivot->is_head_teacher`, D8). Plusieurs PP autorisés.
  - [x] T3.3 Action `save()` : `Gate::authorize('update-group')` puis `updateGroup($id, [... 'head_teacher_ids'])` → projette AD `PP_` + pivot. Toast `WithToasts` générique (pas `$e->getMessage()`).
  - [x] T3.4 Inclusion dans `index.blade.php` via `@livewire('pages::users.groups.[id]._partials.head-teacher-section', ['groupId' => $groupId], key('head-teacher-' . $groupId))` gated `@if ($type === 'classe')` (à côté de `class-share-section`).
  - [x] T3.5 Indicateur PP (badge) rendu en lecture seule dans la section quand l'utilisateur n'a pas `update-group` (pas de modif de `members-list.blade.php` — la section porte sa propre visualisation).
- [x] **T4 — Tests hôte** (AC14) — `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (écriture AD) + `tests/Feature/Livewire/Users/HeadTeacherSectionTest.php` (UI)
  - [x] T4.1 Écriture AD : `it_writes_head_teachers_to_pp_group` (AC1), `it_clears_pp_group_when_no_head_teacher` (AC2), `it_writes_multiple_head_teachers` (AC3), `it_never_writes_pp_for_non_class_type` (AC4), `it_ignores_head_teacher_not_in_members` (AC5).
  - [x] T4.2 Pivot + idempotence : `it_persists_head_teacher_pivot_on_save` (AC6), `it_is_idempotent_across_repeated_pp_writes` (AC7), `it_keeps_pp_stable_after_syncFromAd_roundtrip` (AC8, D2).
  - [x] T4.3 UI Livewire : `it_renders_section_for_classe_type` / `it_does_not_render_section_for_non_classe_type` / `it_aborts_404_when_group_id_not_found` (AC9), `it_only_lists_prof_members_as_toggleable` (AC11), `it_designates_a_head_teacher_and_persists_pivot` / `it_removes_a_head_teacher_when_untoggled` (AC10), `it_blocks_save_without_modify_permission` (AC12).
  - [x] T4.4 Non-régression (AC13) : partition 4.12, fold 4.13, read-back/migration 4.14 verts ; `UserPolicyResetPasswordScoped`/`UsersListingScoped` verts.
- [x] **T5 — Doc QA append-only** (AC14) — `docs/qa/domains/rights-management.md`
  - [x] T5.1 Nouvelle **Section 9** « Écriture SQL→AD `PP_<X>` + UI Professeur principal (Story 4.15) » : scénarios 9.1–9.9 + pré-requis communs + checklist pré-prod, insérée après §8 avant « Post-correctifs ». **§1–8 NON renumérotées.**
  - [x] T5.2 Runbook E2E /vm différé post-merge (`samba-tool group listmembers PP_<x>` après désignation UI) — documenté dans la couverture 9.x, différé comme 4.13/4.14.
- [x] **T6 — Non-régression aval** (AC13) — aucune modification de `ShareService`/`AclService`/`UserPolicy`/`MergeLegacyUserGroups`/boucle de fold `syncFromAd` (read-back 4.14). `syncAdGroupMembersByUserIds` réutilisé tel quel.

> **Clôture epic** : 4.15 ferme la refonte « groupes au nom nu » (4.13 fold / 4.14 migration+flag / 4.15 écriture PP+UI). Le flag `is_head_teacher` est désormais **produit** (read-back AD→SQL, 4.14), **saisi** (UI, 4.15) et **consommé** (écriture SQL→AD `PP_`, 4.15).

---

## Dev Notes

### Ancrage code (chemins:lignes vérifiés 2026-06-25)

| Élément | Fichier:ligne | Rôle |
|---|---|---|
| Écriture SQL→AD membres (2 cibles, **à étendre**) | `app/Services/UserGroupService.php:817-861` (`syncRoleAwareAdGroupMembers`) | **Cœur T1** — ajouter 3ᵉ cible `PP_<base>` |
| Diff idempotent fail-soft (réutiliser) | `app/Services/UserGroupService.php:909-947` (`syncAdGroupMembersByUserIds`) | Cible `PP_` (T1.2) — NE PAS réécrire |
| Appelant create (l.78) | `app/Services/UserGroupService.php:51-92` (`createGroup`) | Passer `head_teacher_ids` + ordre AD-avant-syncFromAd (T2) |
| Appelant update (l.125) | `app/Services/UserGroupService.php:94-145` (`updateGroup`) | idem (T2) — appelle `syncFromAd()` après (D2) |
| `stripClasseLikePrefix` (base nue) | `app/Services/UserGroupService.php:895-904` | `$baseName` pour `PP_{$baseName}` |
| Read-back flag (4.14) — **NE PAS toucher** | `app/Services/UserGroupService.php:435-460` (boucle fold) | Pose `is_head_teacher` depuis CN `PP_` ; déjà correct |
| `users()` + `withPivot('is_head_teacher')` (4.14) | `app/Models/UserGroup.php:64-74` | Relation d'écriture/lecture du pivot (T2.2, T3.2) |
| Cast bool pivot (4.14) | `app/Models/Pivot/UserGroupUserPivot.php:47-49` | Lecture fiable `is_head_teacher` cross-driver |
| `User::userGroups()`/`groups()` (sans withPivot, D4 4.14) | `app/Models/User.php:112-120, 182-190` | D8 : ajouter `withPivot` SEULEMENT si lecture via User |
| `isProf()` (partition + gating UI) | `app/Models/User.php` | T1 (partition Equipe_/Classe_ inchangée), T3.2 (toggle profs) |
| **Fiche de groupe (Volt SFC)** | `resources/views/pages/users/groups/[id]/index.blade.php:12-202` (component) `:264-301` (vue) | Point d'inclusion T3.4 (cf. `@if($type==='classe')` l.275-277) |
| **Patron UI section gated classe (5.2)** | `resources/views/pages/users/groups/[id]/_partials/class-share-section.blade.php:1-70` | **PATRON OBLIGATOIRE** T3.1 (SFC, WithToasts, mount abort, #[Locked]) |
| Edit-form (toggleUser/save/selectedUserIds) | `resources/views/pages/users/groups/[id]/_partials/edit-form.blade.php` | Pattern multiselect membres (réutilisable T3.2) |
| Members-list (lecture) | `resources/views/pages/users/groups/[id]/_partials/members-list.blade.php` | Badge PP optionnel (T3.5) |
| Trait toasts | `app/Components/Traits/WithToasts.php` | Notifs UI (T3.3) |
| Action migration data (4.14) — **NE PAS toucher** | `app/Actions/Groups/MergeLegacyUserGroups.php` | Pose déjà le flag pour `PP_` isolé |
| Test compat (patron `makeService`/`primeNoLdap`) | `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` | T4 — fixtures AD + helper `isHeadTeacher()` (ajouté 4.14) |
| Doc QA append-only | `docs/qa/domains/rights-management.md` (§8 = 4.14, dernier numéroté) | Section 9 (T5) |

### Pièges & points d'attention

- **`PP_` est orthogonal, pas exclusif** : le piège n°1 est de partitionner en 3 (prof non-PP → Equipe_, PP → PP_, élève → Classe_). FAUX. Un prof PP est dans `Equipe_` **ET** `PP_`. La partition prof/élève (4.12) est intacte ; `PP_` est un **sur-ensemble marqueur** écrit en plus. Ne PAS retirer le PP de `Equipe_`/`Classe_`.
- **Ordre AD-avant-read-back (D2)** : `updateGroup` appelle `syncFromAd()` (read-back 4.14) APRÈS `syncRoleAwareAdGroupMembers`. Si on écrit le pivot mais PAS l'AD `PP_` avant `syncFromAd`, le read-back lit un `PP_<base>` AD encore vide → ré-écrase le flag à `false`. Donc l'écriture AD `PP_` doit précéder le `syncFromAd()` existant (elle le fait déjà via `syncRoleAwareAdGroupMembers` appelé avant `syncFromAd` en l.78/125 — vérifier que la 3ᵉ cible est bien dans ce même appel, pas après).
- **Persistance pivot vs `sync()` 4.14** : 4.14 pose le flag UNIQUEMENT au read-back (`syncFromAd`). Pour la saisie UI, il faut écrire le pivot **directement** (avant l'écriture AD), sinon le flag dépend d'un round-trip AD. Deux options : (a) écrire le pivot via `users()->sync()` associatif puis écrire AD puis `syncFromAd` (convergence) ; (b) écrire AD `PP_` depuis `head_teacher_ids` puis laisser `syncFromAd` re-poser le flag (le pivot est alors dérivé de l'AD). **(b) est plus simple et symétrique** (single source = AD après écriture), MAIS le flag SQL n'existe qu'après `syncFromAd` — acceptable car `updateGroup` l'appelle toujours. **À trancher (D3)** ; tester l'aller-retour (AC8) quel que soit le choix.
- **`withPivot` côté lecture** : l'UI lit `is_head_teacher` pour cocher les toggles. `UserGroup::users()` a déjà `withPivot` (4.14) → lire via `$group->users` et `$user->pivot->is_head_teacher`. NE PAS lire via `User::userGroups()`/`groups()` sans ajouter `withPivot` (D8). Le cast bool (4.14) garantit `assertTrue` fiable.
- **Volt SFC + chemins `[id]`** : les crochets `[id]` cassent le parsing de la tag-syntax `<livewire:...>` → utiliser la directive `@livewire('pages::users.groups.[id]._partials.…', […], key(…))` (cf. commentaire l.283-284 de l'index pour `group-quota-section`).
- **Gating `mount` abort** : comme `class-share-section`, le SFC PP doit `abort(403/404)` si le groupe n'est pas une classe (anti-forge de payload Livewire avec un `groupId` non-classe). NE PAS se fier seulement au `@if` de la vue.
- **Garde `guardReservedPrefixOnCreate`** (l.874-889) : rejette un `name` nu à préfixe `Classe_/Equipe_/PP_`. Le nom nu d'une classe ne porte jamais ces préfixes — non impacté, mais cohérence à préserver.
- **fail-soft AD** : `syncAdGroupMembersByUserIds` est fail-soft (add/remove best-effort). En test, `GroupRepository` est mocké (`getGroupMembers`/`addMember`/`removeMember`) — asserter les **appels** `addMember("PP_3A", dn)` plutôt qu'un état AD réel (pattern 4.12).
- **VM migrations / E2E différés** : aucune migration de schéma en 4.15 (la colonne existe — 4.14). Mais l'E2E `samba-tool group listmembers PP_<x>` après désignation UI est différé post-merge (`memory/project_vm_migrations_not_auto_applied.md` pour 4.14, même logique de différé). Ne PAS sync/tester sur la VM depuis un worktree (CLAUDE.md).

### Environnement de test (HÔTE)

- **Tests sur l'HÔTE** (php 8.4 + sqlite + vendor, `vendor/bin/phpunit`), PAS sur la VM. Procédure 4.12/4.13/4.14 (vendor reconstruit localement si worktree, `bootstrap/cache/` créé — gitignored).
- **SQLite ne contraint ni varchar ni les bool** comme PG (`memory/project_sqlite_tests_no_varchar_enforcement.md`) ; le cast bool (4.14) sur le pivot garantit des assertions fiables (`assertTrue($pivot->is_head_teacher)`).
- Mocker `GroupRepository` (`makeService` de `UserGroupServiceLegacyCompatibilityTest`) ; `primeNoLdap()` pour court-circuiter LDAP (fallback `User.role` → `isProf()`). Purger `User::$ldapCache` en `tearDown` si les tests touchent `isProf()`.
- E2E `syncRoleAwareAdGroupMembers` réel (`samba-tool group listmembers PP_<x>`) + UI manuelle différés post-merge sur `main`/`/vm` ; runbook `docs/qa/domains/rights-management.md` Section 9 (append-only).

### Project Structure Notes

- **1 nouveau fichier UI** : `resources/views/pages/users/groups/[id]/_partials/head-teacher-section.blade.php` (Volt SFC, patron 5.2). Inclusion dans `index.blade.php` (édition minimale, à côté de `class-share-section`).
- **Édition service** : `syncRoleAwareAdGroupMembers` (4ᵉ param + 3ᵉ cible), `createGroup`/`updateGroup` (payload `head_teacher_ids` + persistance pivot + ordre D2). Pas de nouveau fichier service (sauf si un `setHeadTeachers()` dédié est retenu — D3).
- **Pas de migration de schéma** (colonne livrée 4.14). **Pas de migration agent** (aucun fichier `agent/**` touché — pas de bump version).
- **Doc QA append-only** : `docs/qa/domains/rights-management.md` Section 9 (§1–8 non renumérotées).

### Dépendances (avec statut)

- **4.14 — `review` (branche main, commit a712794), code DISPONIBLE.** 4.15 dépend de la colonne `is_head_teacher`, du cast pivot, du `withPivot` sur `UserGroup::users()` et du read-back `syncFromAd`. **La dépendance est satisfaite par disponibilité sur `main`** (commits 4.13 `c5b99e5` + 4.14 `a712794` mergés). NE PAS bloquer sur la clôture formelle de la review 4.14 : l'utilisateur testera **4.13 + 4.14 + 4.15 ensemble**. Développer 4.15 directement sur `main`.
- **4.13 — `review`, code sur main (`c5b99e5`).** Fold import + helpers (`buildFoldedGroups`/`stripClasseLikePrefix`) réutilisés en lecture. Disponible.
- **4.12 — `review`.** `syncRoleAwareAdGroupMembers` (la méthode étendue) + partition `isProf()`. Disponible.
- **5.2 — (pivot custom + `class-share-section`).** Patron UI de la section PP + pivot `->using(UserGroupUserPivot::class)`. Disponible.
- **Piège 4.14 connu** : la **migration data 4.14 reste `Pending` sur `/vm`** (non auto-jouée). En prod, le flag `is_head_teacher` ne sera peuplé qu'après exécution manuelle de la migration (runbook §8.7). 4.15 ne ré-exécute pas cette migration ; l'UI/écriture fonctionne sur des arêtes dont le flag est posé (UI saisie OU migration data OU read-back). Documenter dans la checklist QA §9.

### Risques

- **R1 — Partition prof/élève cassée par `PP_`.** Si le dev rend `PP_` exclusif de `Equipe_`/`Classe_`, un prof PP disparaît de `Equipe_` → perte rwx prof (régression 4.12). Mitigation : `PP_` orthogonal (T1.2), AC1 vérifie prof1 dans `Equipe_` ET `PP_`. **Sévérité haute.**
- **R2 — Flag clignotant (ordre D2).** Écrire le pivot sans écrire l'AD `PP_` avant `syncFromAd` → read-back l'efface. Mitigation : ordre AD-avant-read-back (T2.3), AC8 aller-retour stable. **Sévérité moyenne.**
- **R3 — UI exposée hors classe / payload forgé.** Section PP rendue/atteignable sur un non-classe. Mitigation : `@if($type==='classe')` + `mount` abort (patron 5.2), AC9. **Sévérité moyenne (sécurité).**
- **R4 — Rémanence `PP_<base>`.** Le dernier PP retiré mais `PP_<base>` AD non vidé. Mitigation : toujours synchroniser `PP_` même vide (T1.3), AC2. **Sévérité moyenne.**
- **R5 — Autorisation manquante.** Toggle PP accessible sans la permission d'édition de groupe. Mitigation : double guard (T3.1, D6), réutiliser la permission existante. **Sévérité moyenne.**
- **R6 — E2E PP réel non vérifié (différé).** Comme 4.13/4.14, l'écriture `samba-tool` réelle est post-merge. Mitigation : runbook §9, tests hôte sur les appels mockés. **Sévérité faible (process).**

### Scénarios de validation (résumé exécutable)

| # | Scénario | Vérif | AC |
|---|---|---|---|
| V1 | PP désigné → `PP_<base>` écrit | `addMember("PP_3A", prof1.dn)` ; prof1 ∈ Equipe_3A aussi | AC1 |
| V2 | Plus de PP → `PP_<base>` vidé | `removeMember("PP_3A", …)` ; PP_3A vide | AC2 |
| V3 | Multi-PP | prof1+prof2 ∈ PP_3A | AC3 |
| V4 | Type non classe | aucun `PP_` écrit | AC4 |
| V5 | PP hors membres | ghost ignoré, prof1 seul | AC5 |
| V6 | Pivot persisté | `(3A,prof1).is_head_teacher=true` | AC6 |
| V7 | Idempotence | 2ᵉ run = 0 add/remove superflu | AC7 |
| V8 | Aller-retour syncFromAd | flag stable après read-back | AC8 |
| V9 | UI section gated classe | visible classe, absente cours, abort forgé | AC9 |
| V10 | UI désigner PP | pivot persisté + toast | AC10 |
| V11 | UI toggle profs seuls | pas de contrôle PP sur élève | AC11 |
| V12 | Non-régression 4.12/4.13/4.14 | suite verte | AC13 |

### References

- [Source: app/Services/UserGroupService.php:817-861] — `syncRoleAwareAdGroupMembers` (2 cibles, à étendre en 3)
- [Source: app/Services/UserGroupService.php:909-947] — `syncAdGroupMembersByUserIds` (diff idempotent, réutilisé pour `PP_`)
- [Source: app/Services/UserGroupService.php:51-145] — `createGroup`/`updateGroup` (appelants, ordre AD-avant-syncFromAd)
- [Source: app/Services/UserGroupService.php:435-460] — read-back flag 4.14 (NE PAS toucher)
- [Source: app/Models/UserGroup.php:64-74] — `users()` + `withPivot('is_head_teacher')` (4.14)
- [Source: app/Models/Pivot/UserGroupUserPivot.php:47-49] — cast bool (4.14)
- [Source: resources/views/pages/users/groups/[id]/index.blade.php:264-301] — fiche groupe (point d'inclusion UI, l.275-277 pattern gating classe)
- [Source: resources/views/pages/users/groups/[id]/_partials/class-share-section.blade.php] — PATRON UI section gated classe (5.2)
- [Source: resources/views/pages/users/groups/[id]/_partials/edit-form.blade.php] — pattern multiselect membres / toggleUser / save
- [Source: app/Components/Traits/WithToasts.php] — notifications UI
- [Source: _bmad-output/implementation-artifacts/4-14-migration-fusion-groupes-is-head-teacher.md] — story amont (colonne + flag), section HORS SCOPE = handoff 4.15
- [Source: _bmad-output/implementation-artifacts/4-13-fold-import-groupes-classe-nom-nu.md] — fold import (nom nu canonique)
- [Source: memory/project_usergroup_sql_fold_bare_name.md] — direction (flag d'arête, PP, stories 4.13/4.14/4.15)
- [Source: memory/project_equipe_group_never_populated_se5.md] — 4.12 partition ACL prof (Equipe_/Classe_)
- [Source: feedback_per_group_property_belongs_on_group_pages.md] — propriété par-groupe dans l'edit-form, pas onglet global
- [Source: memory/project_sync_from_ad_transitional.md] — AD-first transitoire (écriture = canal de propagation)

### Previous Story Intelligence (4.14, en review — code sur main)

- 4.14 a posé le flag en LECTURE (read-back `syncFromAd` + migration data) ; **personne ne le consomme**. 4.15 le consomme (écriture `PP_`) et le saisit (UI). NE PAS retoucher le read-back ni la migration data.
- 4.14 a ajouté `withPivot('is_head_teacher')` SEULEMENT sur `UserGroup::users()` (D4) + cast bool sur le pivot. Lire via `UserGroup::users()` (pivot dispo) ; n'élargir à `User::*` que si nécessaire (D8).
- 4.14 a découvert le piège du `sync()` associatif (clé = `user_id`, dédup, PP-priorité). Réutiliser ce mécanisme pour la persistance UI sans réintroduire un double `sync()` qui détacherait les membres.
- Process 4.12/4.13/4.14 : tests hôte ciblés (`--filter UserGroupService`), GroupRepository mocké (asserter les appels `addMember`/`removeMember`), E2E /vm différé post-merge, doc QA append-only, purge `User::$ldapCache` en `tearDown`.
- La migration data 4.14 reste **Pending sur /vm** : en prod le flag n'est peuplé qu'après `migrate` manuel (§8.7). 4.15 n'en dépend pas pour la saisie UI (l'UI pose le flag elle-même), mais le read-back en dépend.

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context) — DEV.

### Debug Log References

- Tests service : 2 itérations. (1) Les 5 tests d'écriture AD (AC1–AC5) verts d'emblée ; les 3 tests pivot (AC6/AC7/AC8) échouaient car le mock `getGroupMembers` ne renvoyait pas de `cn` (login) pour les membres ajoutés via `addMember` (qui ne stocke que le `dn`), donc le read-back `syncFromAd` (qui résout par `cn`) ne reposait pas le flag. (2) Fix : dériver le `cn` du `dn` **à la lecture** dans le callback `getGroupMembers` (sans muter l'état stocké, pour préserver les assertions `adMembersByCn` des tests 4.12). → 31/31 verts.
- Tests Livewire : la table de test `user_group_user` du trait partagé `CreatesPermissionSchema` n'avait pas la colonne `is_head_teacher` (antérieure à 4.14) → ajout de la colonne au trait. Les tests d'abort (`404`/`403`) rendaient la page d'erreur (Vite manifest absent sur l'hôte) → bascule en `withoutExceptionHandling()` + `expectException` (`HttpException`/`AuthorizationException`), même limite environnementale que les 3 tests d'abort PRÉ-EXISTANTS de `ClassShareSectionTest`.

### Completion Notes List

- **Écriture SQL→AD — 3 cibles.** `syncRoleAwareAdGroupMembers` reçoit un 4ᵉ param `array $headTeacherUserIds = []`. Dans la branche classe/équipe, après les cibles `Equipe_<base>` (profs) / `Classe_<base>` (reste) **inchangées** (partition 4.12 intacte), une 3ᵉ cible `PP_<base>` est synchronisée via `syncAdGroupMembersByUserIds` (diff idempotent réutilisé tel quel). `$ppIds` = `$headTeacherUserIds` ∩ `$selectedUserIds` (garde-fou : un PP doit être membre). **Orthogonalité** : un prof PP est écrit dans `Equipe_` **et** `PP_` (jamais retiré d'`Equipe_`). Cible **toujours** synchronisée → vidage si plus de PP, pas de rémanence.
- **Persistance pivot (D2/D3).** Choix de l'**option (b)** documentée par la story (« plus simple et symétrique ») : l'AD `PP_<base>` est écrit AVANT `syncFromAd()` (dans `createGroup` ET `updateGroup`), et le read-back 4.14 (boucle de fold, NON modifiée) re-pose `is_head_teacher` depuis le CN `PP_<base>` qu'on vient d'écrire. Pas de `users()->sync()` manuel additionnel (qui risquerait de détacher des membres). Convergence flag SQL ↔ AD `PP_` garantie (AC6/AC8 verts). Dans `updateGroup`, si `head_teacher_ids` est passé sans `user_ids`, les membres courants sont dérivés du pivot SQL pour conserver la partition.
- **UI Livewire SFC.** `_partials/head-teacher-section.blade.php` sur le patron exact de `class-share-section` (5.2) : `new class extends Component`, `WithToasts`, `#[Locked] int $groupId`, `mount()` abort 404 si introuvable + flag `isClasse` (rendu vide sinon, anti-forge), `loadClasseOrFail()` qui abort sur action. Liste **uniquement les membres `isProf()`** (`profMembers()`), toggle PP par membre, état lu via `UserGroup::users()->pivot->is_head_teacher` (withPivot 4.14, D8). `save()` → `Gate::authorize('update-group')` (= permission `user.modify`, D6) puis `updateGroup($id, [... 'head_teacher_ids'])`, toast générique. Inclusion dans `index.blade.php` via `@livewire(...)` gated `@if ($type === 'classe')` (directive, pas tag-syntax, à cause des `[id]`).
- **Décisions tranchées.** D1 = 4ᵉ param + intersection (retenu). D2 = AD-avant-`syncFromAd` (retenu). D3 = pivot dérivé du read-back, option (b) (retenu — pas de `setHeadTeachers()` dédié). D4 = gating `type === 'classe'` seul. D5 = toggle limité `isProf()` UI + écriture service défensive. D6 = `update-group` (`user.modify`, permission d'édition existante, pas de nouvelle permission). D7 = création (`new/`) hors scope. D8 = lecture via `UserGroup::users()` (withPivot 4.14 déjà présent, aucun ajout sur `User::*`).
- **Non-régression.** `ShareService`/`AclService`/`UserPolicy`/`MergeLegacyUserGroups`/boucle de fold `syncFromAd` (read-back 4.14) NON touchés. Pas de migration de schéma (colonne 4.14). Pas de fichier `agent/**` (pas de bump version).
- **Tests hôte (php 8.4 + sqlite + vendor).** `UserGroupServiceLegacyCompatibilityTest` **31/31** (95 assertions ; 8 nouveaux 4.15 + 23 existants 4.12/4.13/4.14). `HeadTeacherSectionTest` **7/7** (17 assertions). Non-régression scope prof : `UserPolicyResetPasswordScoped` + `UsersListingScoped` + `MergeLegacyUserGroupsMigrationTest` verts (suite combinée requise **69/69**, 188 assertions). PRÉ-EXISTANT hors scope, NON corrigé : les 3 tests d'abort de `ClassShareSectionTest` échouent sur l'hôte (Vite manifest non buildé) — identique pour les 7 autres tests de cette classe qui passent ; aucun rapport avec 4.15 (vérifié : échec reproduit avant toute modif). `BulkPasswordResetGroupsTest` 4 erreurs PRÉ-EXISTANTES (env LDAP, `$baseDn` null) — hors scope, signalé en 4.13/4.14.
- **E2E /vm différé post-merge** : `samba-tool group listmembers PP_<x>` après désignation UI (runbook QA §9), comme 4.13/4.14.
- **Correction M6 (post-review).** `updateGroup` distingue désormais `head_teacher_ids` **ABSENT** vs `[]` **EXPLICITE**. Quand la clé est absente du payload (edit-form / removeMember qui ne touchent pas aux PP), les PP existants sont **préservés** en les dérivant du pivot (`$group->users()->wherePivot('is_head_teacher', true)->pluck('users.id')`) au lieu de `[]` (qui vidait silencieusement `PP_<base>` en AD puis effaçait le pivot au read-back). Un `[]` explicite reste un effacement volontaire. Couverture post-review : 2 tests ajoutés (cf. File List).
- **Correctifs post-review 2 (Q1/Q2/Q3, v1.2).** Trois micro-fix sûrs sur le code déjà commité :
  - **Q1/M1 — write AD de description conditionnel.** Dans la branche `oldName == newName` d'`updateGroup`, `updateGroupDescription` (write LDAP + `RuntimeException` possible) n'est plus appelé **que si** la description désirée (`$payload['display_name'] ?? $newName`) diffère de la courante (`$group->display_name ?? $oldName`). Un simple toggle PP (description inchangée) ne déclenche plus aucun write LDAP inutile. La branche **rename** est inchangée.
  - **Q2 (option b) — toast honnête sur convergence du flag PP** (auto-contenu côté SFC, signature `updateGroup` **inchangée**). Après `updateGroup`, `save()` recharge l'état **persisté** des PP du groupe courant (requête fraîche `UserGroup::whereKey(...)->users()->wherePivot('is_head_teacher', true)`, pas le modèle mémoïsé) et le compare à l'ensemble **intendu** (`$ppIds` envoyé, ensembles d'IDs triés). Convergent → `toastSuccess` ; sinon → `toastWarning` (« …enregistré(s) en base, mais la synchronisation AD est incomplète — réessayez. »). Cible le SEUL groupe courant (pas le compteur d'erreurs global de `syncFromAd`).
  - **Q3 — gating `mount()` par `view-group`.** Ajout de `Gate::authorize('view-group', $group)` dans `mount()`, **après** le `abort(404)` anti-forge, **avant** d'exposer quoi que ce soit. `view-group` == `user.read` (`GroupPolicy`). Un utilisateur sans `user.read` ne peut plus instancier la section pour lire les membres profs via `wire:call`. Tous les rôles seedés avec `user.modify` ont `user.read` → `save()` reste fonctionnel.
  - **Tests (chiffres réels hôte php 8.4 + sqlite).** `UserGroupServiceLegacyCompatibilityTest` **34/34** (113 assertions ; +2 Q1 : `it_skips_ad_description_write_when_description_unchanged`, `it_writes_ad_description_when_display_name_changes` — via journal d'appels `updateGroupDescription` ajouté au mock). `HeadTeacherSectionTest` **9/9** (24 assertions ; +1 Q3 : `it_blocks_mount_without_read_permission`). Non-régression combinée `UserPolicyResetPasswordScoped|UsersListingScoped|MergeLegacyUserGroupsMigrationTest` **31/31** (76 assertions). Q2 chemin nominal (toastSuccess) couvert ; chemin `toastWarning` non simulable avec le mock service actuel (pose le pivot directement) → couvert par inspection.

### File List

- `app/Services/UserGroupService.php` (modifié) — 4ᵉ param + 3ᵉ cible `PP_<base>` dans `syncRoleAwareAdGroupMembers` ; `head_teacher_ids` câblé dans `createGroup`/`updateGroup` (ordre AD-avant-`syncFromAd`, D2).
- `resources/views/pages/users/groups/[id]/_partials/head-teacher-section.blade.php` (nouveau) — Livewire SFC section « Professeur principal ».
- `resources/views/pages/users/groups/[id]/index.blade.php` (modifié) — inclusion `@livewire(...)` gated classe.
- `app/Services/UserGroupService.php` (modifié post-review — M6) — `updateGroup` préserve les PP existants (dérivés du pivot) quand `head_teacher_ids` est ABSENT ; `[]` explicite = effacement volontaire.
- `app/Services/UserGroupService.php` (modifié post-review 2 — Q1) — branche `oldName == newName` : `updateGroupDescription` n'est appelé que si la description désirée diffère de la courante (toggle PP n'écrit plus l'AD inutilement).
- `resources/views/pages/users/groups/[id]/_partials/head-teacher-section.blade.php` (modifié post-review 2 — Q2/Q3) — `mount()` gardé par `Gate::authorize('view-group', $group)` (Q3) ; `save()` vérifie la convergence du flag PP (requête fraîche) et émet `toastWarning` si AD incomplet, `toastSuccess` sinon (Q2).
- `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (modifié post-review 2 — Q1) — journal `descriptionUpdateCalls` ajouté au mock + 2 tests (`it_skips_ad_description_write_when_description_unchanged`, `it_writes_ad_description_when_display_name_changes`). Suite → 34/34.
- `tests/Feature/Livewire/Users/HeadTeacherSectionTest.php` (modifié post-review 2 — Q3) — +1 test `it_blocks_mount_without_read_permission` (user `user.modify` seul → AuthorizationException au mount). Suite → 9/9.
- `docs/qa/domains/rights-management.md` (modifié post-review 2) — scénarios 9.10 (Q1), 9.11 (Q2), 9.12 (Q3) append-only (§1–9.9 non renumérotées).
- `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (modifié) — 8 tests 4.15 + dérivation `cn` à la lecture dans le mock `getGroupMembers` ; **+1 test post-review** `it_preserves_head_teachers_when_updateGroup_omits_head_teacher_ids` (régression M6 : clé absente préserve PP, `[]` explicite vide, retrait membre PP par intersection préserve les autres). Suite → 32/32.
- `tests/Feature/Livewire/Users/HeadTeacherSectionTest.php` (nouveau) — 7 tests Livewire de la section UI ; **+1 test post-review** `it_renders_readonly_for_viewer_without_modify_permission` (viewer `user.read` seul : section + badge PP visibles, toggle/bouton Enregistrer absents). Suite → 8/8.
- `tests/Traits/CreatesPermissionSchema.php` (modifié) — colonne `is_head_teacher` sur la table de test `user_group_user` (parité 4.14).
- `docs/qa/domains/rights-management.md` (modifié) — Section 9 (9.1–9.9 + checklist) append-only.
- `_bmad-output/implementation-artifacts/4-15-ecriture-pp-ad-ui-professeur-principal.md` (modifié) — tasks cochées, Dev Agent Record, Status → review.
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modifié) — ligne 4-15 → review.

### Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-06-25 | 1.2 | CORRECTIONS POST-REVIEW 2 (Q1/Q2/Q3) + 3 tests. **Q1/M1** : `updateGroup` n'écrit la description AD (`updateGroupDescription`) que si elle diffère réellement (toggle PP ne déclenche plus de write LDAP inutile) ; branche rename inchangée. **Q2** (option b, signature inchangée) : `save()` du SFC vérifie la convergence persistée des PP du groupe courant (requête fraîche vs `$ppIds` intendu) → `toastSuccess` si convergent, `toastWarning` sinon (« …synchronisation AD incomplète — réessayez »). **Q3** : `mount()` gardé par `Gate::authorize('view-group', $group)` (== `user.read`) après l'abort 404, avant exposition. Tests HÔTE : `UserGroupServiceLegacyCompatibilityTest` 34/34 (+2 Q1), `HeadTeacherSectionTest` 9/9 (+1 Q3), non-régression `UserPolicyResetPasswordScoped|UsersListingScoped|MergeLegacyUserGroupsMigrationTest` 31/31. Doc QA §9.10–9.12 append-only. Q2 chemin warning : couvert par inspection (non simulable avec le mock actuel). | DEV (Opus 4.8 1M) |
| 2026-06-25 | 1.1 | CORRECTION REVIEW (M6) + 2 tests. `updateGroup` distingue `head_teacher_ids` ABSENT (PP préservés, dérivés du pivot) vs `[]` EXPLICITE (effacement). 2 tests ajoutés : `it_preserves_head_teachers_when_updateGroup_omits_head_teacher_ids` (régression M6, suite 32/32) + `it_renders_readonly_for_viewer_without_modify_permission` (rendu readonly viewer, suite 8/8). Non-régression `UserPolicyResetPasswordScoped`/`UsersListingScoped`/`MergeLegacyUserGroupsMigrationTest` 31/31. | DEV (Opus 4.8 1M) |
| 2026-06-25 | 1.0 | Story IMPLÉMENTÉE (DEV opus claude-opus-4-8[1m]), ready-for-dev → review. (1) ÉCRITURE SQL→AD : 4ᵉ param `headTeacherUserIds` + 3ᵉ cible `PP_<base>` orthogonale dans `syncRoleAwareAdGroupMembers` (intersection garde-fou, toujours synchronisée, partition 4.12 intacte, diff idempotent réutilisé) ; `head_teacher_ids` câblé dans `createGroup`/`updateGroup`, AD écrit AVANT `syncFromAd` (D2, pivot dérivé du read-back = option (b) de la story). (2) UI : Livewire SFC `_partials/head-teacher-section` sur patron `class-share-section` (mount abort, `isProf()` toggle, double guard `update-group`), inclusion gated classe. Tests HÔTE : `UserGroupServiceLegacyCompatibilityTest` 31/31 (8 nouveaux 4.15) + `HeadTeacherSectionTest` 7/7 ; non-régression `UserPolicyResetPasswordScoped`/`UsersListingScoped`/`MergeLegacyUserGroupsMigrationTest` verts (69/69 combiné). Colonne `is_head_teacher` ajoutée au trait de test `CreatesPermissionSchema` (parité 4.14). Doc QA Section 9 append-only. Pas de migration schéma, pas de bump agent. Pré-existant non corrigé : 3 abort tests `ClassShareSectionTest` (Vite manifest hôte) + `BulkPasswordResetGroupsTest` (env LDAP). | DEV (Opus 4.8 1M) |
| 2026-06-25 | 0.1 | Story CRÉÉE (SM). 3ᵉ et DERNIÈRE des « groupes au nom nu » (handoff section HORS SCOPE de 4.14). Scope strict : (1) ÉCRITURE SQL→AD — 3ᵉ cible `PP_<base>` dans `syncRoleAwareAdGroupMembers` (817-861), pilotée par `is_head_teacher`, orthogonale à `Equipe_`/`Classe_` (un prof PP est dans les deux), toujours synchronisée (vidage si plus de PP), réutilise `syncAdGroupMembersByUserIds` ; `head_teacher_ids` en 4ᵉ param + payload `updateGroup`/`createGroup`, ordre AD-avant-`syncFromAd` (D2 anti-clignotement). (2) UI « Professeur principal » — Livewire SFC `_partials/head-teacher-section` sur patron `class-share-section` (5.2 : `new class`, `WithToasts`, `#[Locked]`, `mount` abort non-classe), gated `@if($type==='classe')`, toggle PP sur les membres profs, persiste le pivot. HORS SCOPE : modèle multi-vertical (parqué), re-fold/migration (4.13/4.14), read-back (4.14), création (`new/`, différé), ACL FS PP. Dépendance 4.13/4.14 SATISFAITE par disponibilité sur main (c5b99e5/a712794) — tester 4.13+4.14+4.15 ensemble, ne pas bloquer sur review. Pièges : `PP_` orthogonal (pas exclusif → ne pas casser partition 4.12), ordre D2, lire flag via `UserGroup::users()` (withPivot 4.14), Volt `[id]` → `@livewire(...)`, `mount` abort, migration data 4.14 Pending /vm. Tests HÔTE (php8.4+sqlite). Doc QA append-only Section 9 (§1–8 non renumérotées). 14 AC, 6 tâches. Modèle recommandé : opus. | SM (Opus 4.8) |

---

## Recommandation Modèle Dev

**opus.**

Pourquoi : 4.15 est moins « UI/CRUD » qu'il n'y paraît — son cœur est une **écriture SQL→AD avec invariants subtils** qui sont précisément les angles morts de sonnet. (1) L'orthogonalité de `PP_` : un prof principal doit rester dans `Equipe_<base>` (rwx prof, parité 4.12) **tout en** étant ajouté à `PP_<base>` ; un fold naïf qui partitionne en 3 casserait silencieusement les ACL prof (R1, haute). (2) L'**ordre AD-avant-read-back** (D2) : `updateGroup` rappelle `syncFromAd` (read-back 4.14) qui ré-écraserait le flag si l'AD `PP_` n'est pas écrit avant — bug de cohérence non évident (R2). (3) La symétrie flag↔AD (lecture 4.14 vs écriture 4.15) et le choix « pivot direct vs dérivé du read-back » (D3) demandent de raisonner sur un aller-retour AD↔SQL. (4) La sécurité de la section UI (gating `type` + `mount` abort anti-forge, R3/R5) et la non-régression de tout l'épic (4.12/4.13/4.14) sur un service déjà dense. La part UI suit un patron établi (5.2), mais l'écriture AD et la cohérence du flag exigent la rigueur d'opus pour ne pas régresser la partition ACL ni faire clignoter le flag.
