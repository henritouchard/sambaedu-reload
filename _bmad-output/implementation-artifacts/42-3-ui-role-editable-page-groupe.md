# Story 42.3 : UI — rôle visible et éditable sur la page du groupe

Status: review

> **Type** : UI Livewire SFC pure — colonne « Rôle » sur la liste des membres de la fiche groupe (`/app/users/groups/[id]`), édition unitaire par select, rôle proposé/surchargeable au rattachement. **AUCUNE modification de `app/Services`, `app/Observers`, `app/Models`** : la story CONSOMME le socle 42.1 (vocabulaire, `defaultRoleForGlobalRole`, `assertValidRole`) et le canal 42.2 (`resyncGroupAdProjection` public + observer pivot `updated()`), elle n'en crée aucun. Aucune migration, aucune route nouvelle.
>
> **Origine** : Epic 42 — Socle rôle sur l'arête user↔groupe (`_bmad-output/planning-artifacts/epics-socle-role-groupes.md`, décision Henri 2026-07-07). **3ᵉ des 4 stories** ; amont **42.1 + 42.2 (review approuvées, code sur main)** ; **indépendante de 42.4** (read-back import) — développables en parallèle.
>
> **Direction** : l'admin voit et modifie le rôle de chaque membre depuis la page du groupe (FR-S4/UX-S1) : libellés FR « Élève » (`member`) / « Prof » (`manager`) / « Prof principal » (`owner`), valeurs techniques masquées ; édition → écriture pivot → resync AD (canal 42.2) ; le rattachement propose le défaut dérivé du rôle global et permet de le surcharger. Propriété par-groupe → pages groupes, pas de settings (`feedback_per_group_property_belongs_on_group_pages`).
>
> **CONTRAT HÉRITÉ (review 42.2 #4 — non négociable)** : toute édition de rôles **EN MASSE** encadre ses écritures pivot par `UserGroupUserPivotObserver::disableAdResync()` / `enableAdResync()` (try/finally) et déclenche **UN SEUL** `UserGroupService::resyncGroupAdProjection($group)` explicite — sinon K events `updated` = K reprojections LDAP du même groupe. L'édition **UNITAIRE** s'appuie sur l'observer `updated()` tel quel (changement de rôle → reprojection auto, fail-soft).
>
> **Mémoire projet liée** : `project_livewire_reserved_upload_method.md` (JAMAIS d'action `upload`), `project_daisyui5_form_control_removed_label_inline.md` (piège `.label` inline-flex), `feedback_form_label_above_input_tooltip_hints.md` (labels au-dessus, pas de hints décoratifs), `feedback_per_group_property_belongs_on_group_pages.md`, `project_isprof_iseleve_ldap_first_cost.md` (dérivation = colonne SQL, zéro LDAP), `project_sync_from_ad_transitional.md` (AD-first transitoire — limite D7).

---

## Story

En tant que **responsable d'établissement**,
je veux **voir le rôle de chaque membre (Élève / Prof / Prof principal) dans une colonne de la liste des membres du groupe, le modifier d'un geste, et choisir le rôle dès le rattachement d'un nouveau membre**,
afin que le rôle porté par l'arête (source de vérité depuis 42.1/42.2) soit pilotable là où il se vit — la page du groupe — et que l'AD (`Classe_X`/`Equipe_X`/`PP_X`) reflète immédiatement chaque changement.

---

## Périmètre STRICT

**Dans le scope** : colonne « Rôle » (lecture pour tous les lecteurs, select pour les porteurs d'`update-group`) dans `members-table` ; action d'édition unitaire sur la page (`updateMemberRole`) écrivant le pivot via Eloquent (observer 42.2 = resync auto) ; select de rôle par membre NOUVELLEMENT coché dans l'edit-form (défaut dérivé, surcharge Élève/Prof) appliqué post-`updateGroup` sous le contrat masse (disable/enable + 1 resync) ; toasts `WithToasts` ; tests hôte ; doc QA append-only.

**HORS SCOPE** :
- **42.4** : read-back des rôles au `sync-from-ad` (`projectFoldedGroup`/`syncFromAd`) — la dérivation heuristique de l'import reste EN L'ÉTAT ; la limite transitoire D7 est DOCUMENTÉE ici, pas levée.
- Toute modification de `app/Services/UserGroupService.php`, `app/Observers/UserGroupUserPivotObserver.php`, `app/Models/**` (pivot compris), `ShareService`/`AclService`/`GroupPolicy` — le socle est consommé tel quel (et c'est ce qui garantit **zéro chevauchement de fichiers avec 42.4**).
- Remplacement du canal `head_teacher_ids`/modale PP (4.15) : la modale RESTE le canal batch de désignation PP ; la colonne s'y AJOUTE (cf. D2). Son éventuel retrait = décision ultérieure, pas cette story.
- Rôle d'arête sur d'autres pages (fiche user, drawer groupes) : le drawer garde le défaut dérivé 42.1 (review #1), sans UI de rôle.
- Généricité profils/zones/matrice, role-groups `grp__`.

---

## Décisions de cadrage (ACTÉES — ne pas rouvrir sans signal contraire)

- **D1 — La colonne écrit l'ARÊTE, une seule source de vérité.** Le view-model `members()` expose le rôle d'arête sous une clé NOUVELLE `edge_role` (+ `edge_role_label`) — la clé existante `role` = rôle GLOBAL (prof/eleve/autre), qui continue de piloter le split d'onglets Élèves/Profs et le badge PP : **collision de nom interdite** (piège 42.1 #5). Arête vide/inconnue (`''`, valeur sale) → affichée comme `member` (« Élève »), cohérent avec la résolution D2 de la projection (arête absente → défaut dérivé).
- **D2 — Cohérence colonne ↔ head-teacher-section : convergence par le pivot, ZÉRO nouvelle voie PP parallèle.** Les deux surfaces lisent/écrivent LA MÊME arête : la colonne écrit `role` directement (pivot Eloquent → observer → resync) ; la modale PP garde son canal 4.15 (`head_teacher_ids` → `updateGroup` AD-first → read-back qui converge le pivot). Pas de divergence possible à l'écran : la modale relit le pivot à chaque ouverture (`refreshState()` dans `open()` — existant), et la liste se rafraîchit après un save PP via l'event `head-teachers-updated` existant (listener `refreshMembers`). Après une édition colonne, il suffit d'`unset` les computed locaux (la modale, fermée, relira à l'open). **`head-teacher-section.blade.php` n'est PAS modifiée.**
- **D3 — Option « Prof principal » (owner) offerte UNIQUEMENT si `type === 'classe'`.** Alignement avec la désignation PP 4.15 (geste de classe) : pour les autres types, le select propose Élève/Prof seulement ; une tentative `owner` hors classe (payload forgé) est REFUSÉE serveur (toast erreur, aucune écriture). Une arête `owner` PRÉEXISTANTE sur un groupe non-classe (donnée historique) s'affiche « Prof principal » en lecture et peut être rétrogradée via le select. La colonne est visible et éditable sur TOUS les types de groupes (fondation généricité — le rôle ne route l'AD que pour les classe-like, mais l'arête reste la donnée).
- **D4 — Édition unitaire = write Eloquent + observer, PAS d'appel service.** `updateMemberRole` fait `$group->users()->updateExistingPivot($userId, ['role' => $role])` : le pivot custom émet `updated` (vérifié vendor en 42.2 — uniquement si dirty : re-sélectionner la même valeur = no-op, aucun event), l'observer reprojette le groupe (fail-soft, gate classe-like) — AUCUN `updateGroup`, AUCUN `resyncGroupAdProjection` explicite (contrat 42.2 #4, volet unitaire). Bénéfice décisif : pas de read-back `syncFromAd` → le rôle édité PERSISTE en SQL (le read-back est ce qui écrase — cf. D6).
- **D5 — Rattachement : défaut dérivé proposé, surcharge Élève/Prof, JAMAIS owner.** Dans l'edit-form, chaque user nouvellement coché expose un select initialisé à `UserGroupUserPivot::defaultRoleForGlobalRole($user->role)` (colonne SQL déjà chargée — zéro LDAP). `owner` n'est pas proposé au rattachement (« jamais owner par défaut », 42.1 AC5 — la désignation PP est un geste explicite postérieur : colonne ou modale). Mécanique de `save()` : (1) `updateGroup(user_ids)` INCHANGÉ (AD-first ; le read-back crée les nouvelles arêtes au défaut dérivé — IDENTIQUE au défaut proposé) ; (2) pour les seuls ids RÉELLEMENT nouveaux dont le rôle choisi ≠ arête créée : écritures pivot en MASSE sous `disableAdResync()`/`enableAdResync()` (try/finally) + **UN** `resyncGroupAdProjection($group)` (contrat 42.2 #4). Zéro surcharge → zéro write supplémentaire, zéro resync explicite. Les arêtes EXISTANTES ne sont JAMAIS réécrites par ce chemin (piège 42.1 n°2, transposé).
- **D6 — Limite transitoire ASSUMÉE et DOCUMENTÉE (D7 42.2 / AC7 42.1, levée en 42.4)** : tout read-back (`syncFromAd`) redérive les rôles depuis l'heuristique (`users.role` + CN `PP_`) — et `updateGroup` en déclenche un scopé à CHAQUE save/retrait de membre/save PP, comme l'import global et le bouton « Synchroniser avec AD ». Conséquence : une édition NON conforme à l'heuristique (ex. élève promu « Prof », prof rétrogradé « Élève ») est RÉÉCRASÉE au prochain read-back du groupe ; `owner` survit (relu depuis l'appartenance AD `PP_`), SAUF si le groupe `PP_<base>` n'existe pas en AD (fail-soft 42.2 AC6 → retombe `manager`). L'UI ne promet RIEN de faux : toasts factuels (« Rôle mis à jour »), aucun texte « définitif » ; les tests n'assertent PAS la survie post-read-back. 42.4 lèvera la limite (read-back dérivé de l'appartenance `Equipe_`/`PP_`/`Classe_` — que la projection 42.2 écrit déjà depuis l'arête éditée : le cycle devient stable).
- **D7 — Gardes = celles de l'édition de groupe, pattern local.** `Gate::authorize('update-group')` (→ `GroupPolicy::update` → `user.modify`) sur `updateMemberRole` — le MÊME double guard UI `@can('update-group')` + serveur que `removeMember`/head-teacher-section. Validation du rôle reçu (valeur NON constante — cas exact prévu par la review 42.1 #2) via `UserGroupUserPivot::assertValidRole()` : `InvalidArgumentException` interceptée → toast erreur, AUCUNE écriture, pas de 500.

---

## Critères d'acceptation

1. **Colonne « Rôle » en lecture** : `members-table` (onglets Élèves ET Profs) affiche une colonne « Rôle » avec les libellés FR — `member`→« Élève », `manager`→« Prof », `owner`→« Prof principal » — depuis le rôle d'ARÊTE (`$user->pivot->role`, withPivot 42.1), exposé par `members()` sous les clés NOUVELLES `edge_role`/`edge_role_label` (la clé `role` existante = rôle GLOBAL, INCHANGÉE — piège 42.1 #5 : aucune collision). Aucune valeur technique (`member|manager|owner`) rendue comme texte visible (les `value` d'options HTML restent techniques — c'est le libellé qui compte). Arête `''`/hors vocabulaire → affichée « Élève » (D1). Split d'onglets, badge PP (`title="Professeur principal"`), compteurs : INCHANGÉS.
2. **Édition unitaire (porteur `update-group`)** : la cellule Rôle devient un select ; `wire:change` → action `updateMemberRole(int $userId, string $role)` qui (a) `Gate::authorize('update-group')`, (b) valide le rôle via `assertValidRole` (catch → toast erreur, zéro écriture — D7), (c) refuse `owner` si `type !== 'classe'` (D3, y compris payload forgé), (d) refuse un `$userId` non membre du groupe (toast erreur), (e) écrit `$group->users()->updateExistingPivot($userId, ['role' => $role])` — AUCUN appel `updateGroup`/`resyncGroupAdProjection` (l'observer 42.2 reprojette, D4), (f) invalide les computed (`members`/`students`/`teachers`) et toaste succès via `WithToasts`. Re-sélection de la valeur courante = no-op silencieux côté AD (pivot non dirty → pas d'event).
3. **Lecteur seul (`user.read` sans `user.modify`)** : la colonne affiche le libellé SANS select (guard UI `@can('update-group')`), et l'appel direct `updateMemberRole` est rejeté serveur (`assertForbidden`, pivot inchangé) — pattern du test `it_forbids_remove_member_without_modify_permission`.
4. **Cohérence colonne ↔ section PP (D2)** : promouvoir un membre en « Prof principal » via la colonne (classe) pose l'arête `owner` → le badge PP apparaît dans la liste ET la modale PP le montre coché à l'ouverture suivante (via son `refreshState()` existant — head-teacher-section NON modifiée) ; rétrograder un PP via la colonne le retire de la sélection PP ; un save de la modale PP rafraîchit la colonne via l'event `head-teachers-updated` existant. Aucune écriture PP nouvelle hors pivot : la modale garde son canal 4.15.
5. **Rattachement avec rôle proposé/surchargeable (D5)** : dans l'edit-form, chaque user NOUVELLEMENT coché expose un select initialisé au défaut dérivé (`defaultRoleForGlobalRole` sur la colonne SQL `users.role` — zéro appel LDAP), options « Élève »/« Prof » uniquement (jamais owner) ; décocher le user retire son entrée. `save()` : `updateGroup` INCHANGÉ, puis les surcharges (ids réellement nouveaux, rôle choisi ≠ arête créée par le read-back) sont écrites en MASSE sous `UserGroupUserPivotObserver::disableAdResync()`/`enableAdResync()` (try/finally) suivies d'**UN SEUL** `resyncGroupAdProjection($group)` — contrat review 42.2 #4. Zéro surcharge → zéro write pivot supplémentaire ni resync explicite. Une arête préexistante n'est JAMAIS réécrite par `save()`.
6. **Aucune action Livewire nommée `upload`** (méthode réservée Livewire — `project_livewire_reserved_upload_method`), et conventions formulaires maison respectées : libellés/en-tête de colonne au-dessus, AUCUN hint décoratif, pas de NOUVEAU `.form-control` (DaisyUI 5 l'a supprimé — `.label` est inline-flex, corriger par `flex flex-col` + `w-full` si un wrapper label/champ est ajouté ; les `.form-control` existants de l'edit-form ne sont pas le sujet de cette story).
7. **Limite transitoire documentée (D6)** : la story et la doc QA consignent qu'un read-back (`syncFromAd`, y compris celui déclenché par tout `updateGroup` du groupe) peut réécraser un rôle édité non conforme à l'heuristique (owner survit via `PP_`, sauf `PP_` absent en AD) — levée en 42.4. AUCUN texte UI ne promet la persistance ; aucun test n'asserte la survie post-read-back.
8. **Périmètre fichiers = zéro chevauchement 42.4** : `git diff` ne touche NI `app/Services/UserGroupService.php`, NI `app/Observers/UserGroupUserPivotObserver.php`, NI `app/Models/**`, NI `head-teacher-section.blade.php` — uniquement les 3 blades de la page groupe (`index`, `members-table`, `edit-form`), les tests et la doc QA.
9. **Tests hôte verts** (php8.4 + SQLite, HÔTE uniquement — la VM n'a pas pdo_sqlite) : nouveaux tests AC1-AC5 + non-régression par FILTRES : `vendor/bin/phpunit --filter "GroupShowMembersTabs|GroupMemberRole|HeadTeacherSection|UserGroupUserPivotObserver|UserGroupServiceLegacyCompatibility|UserDerivedRolePayload"`. Pré-existant connu hors scope, NE PAS « corriger » : `BulkPasswordResetGroupsTest` (env LDAP absent).

---

## Tasks / Subtasks

- [x] **T1 — View-model + colonne en lecture** (AC1) — `resources/views/pages/users/groups/[id]/index.blade.php` (computed `members()` l.56-85), `_partials/members-table.blade.php`
  - [x] T1.1 `members()` : ajouter `'edge_role'` (rôle d'arête normalisé : hors `UserGroupUserPivot::ROLES` ou vide → `ROLE_MEMBER`, D1) et `'edge_role_label'` (map FR privée du composant — helper statique du SFC, ex. `roleLabel(string $role): string` ; PAS d'édition du pivot : zéro fichier partagé avec 42.4). Clés `role`/`is_head_teacher` existantes INTACTES.
  - [x] T1.2 `members-table.blade.php` : colonne « Rôle » (th + td) dans les DEUX onglets ; en lecture (hors `@can('update-group')`), rendre `edge_role_label` (badge ou texte sobre). Ajouté `wire:key` par ligne (piège n°7).
- [x] **T2 — Édition unitaire** (AC2, AC3, AC4) — `index.blade.php` + `members-table.blade.php`
  - [x] T2.1 `index.blade.php` : `use WithToasts;` (les nouvelles actions toastent via le trait ; les `session()->flash('toast')` existants de `removeMember`/`save` ne sont pas touchés).
  - [x] T2.2 Action `updateMemberRole(int $userId, string $role)` : séquence D7/D4 exacte implémentée telle que spécifiée.
  - [x] T2.3 `members-table.blade.php` : select DaisyUI sous `@can('update-group')` ; option `owner` affichée si `$type === 'classe'` OU si l'arête courante est déjà `owner` (D3 — état visible même hors classe, refus serveur si forgé).
- [x] **T3 — Rattachement : défaut proposé + surcharge** (AC5) — `index.blade.php` (`availableUsers()`, `toggleUser`, `save`, `cancelEditing`) + `_partials/edit-form.blade.php`
  - [x] T3.1 `availableUsers()` : exposer `'default_role'`. **Écart constaté vs Dev Notes** : `UserGroupService::getAssignableUsers()` (app/**, non modifiable — AC8) ne sélectionne PAS la colonne `role` (`select(['id','login','fullname','lastname','firstname'])`) — donc pas « déjà chargé » comme supposé par la story. Résolu par une requête SQL complémentaire dans le SFC (`User::query()->whereIn('id', ...)->pluck('role','id')`), toujours zéro LDAP, toujours confinée au blade (aucun diff `app/**`).
  - [x] T3.2 Propriété `public array $pendingRoles = []`. `toggleUser` pose le défaut dérivé (lu depuis `availableUsers()`) au check, `unset` au décochage. Action `setPendingRole(int $userId, string $role)` créée (validation `assertValidRole` + refus owner, ignore un id inconnu/forgé). `cancelEditing` réinitialise `$pendingRoles = []`.
  - [x] T3.3 `edit-form.blade.php` : select Élève/Prof affiché sous le libellé du user NOUVELLEMENT coché (`wire:key`, label au-dessus, wrapper `flex flex-col w-full`, aucun nouveau `.form-control` — piège DaisyUI 5).
  - [x] T3.4 `save()` : snapshot des membres AVANT `updateGroup`, diff = ids réellement nouveaux, surcharges calculées sur l'état FRAIS post-`updateGroup` (piège n°3), écritures pivot en masse sous `disableAdResync()`/`enableAdResync()` (try/finally) + **UN SEUL** `resyncGroupAdProjection()` si au moins une surcharge. Défense en profondeur : revalidation `assertValidRole` + refus owner au point d'écriture (jamais confiance dans l'état client).
- [x] **T4 — Tests hôte** (AC1-AC5, AC9) — extension `tests/Feature/Livewire/Users/GroupShowMembersTabsTest.php` (4 tests ajoutés) + nouveau `tests/Feature/Livewire/Users/GroupMemberRoleEditTest.php` (12 tests)
  - [x] T4.1 Rendu : colonne « Rôle » + libellés FR, collision de clés vérifiée (`edge_role` ≠ `role`), arête sale → « Élève », lecteur → pas de select.
  - [x] T4.2 Édition unitaire : member→manager persiste, valeur hors vocabulaire → toast + zéro écriture + zéro 500, owner refusé hors classe, owner accepté sur classe + badge PP synchronisé, lecteur → `assertForbidden`, non-membre refusé.
  - [x] T4.3 Resync unitaire : `resyncGroupAdProjection` appelé EXACTEMENT une fois sur changement de rôle réel ; JAMAIS appelé si re-sélection de la valeur courante (pivot non dirty).
  - [x] T4.4 Rattachement : `default_role` correct (prof→manager, eleve→member) ; `toggleUser` pose/purge `pendingRoles` ; `save()` applique la surcharge au SEUL id concerné, arêtes existantes intactes, `resyncGroupAdProjection` appelé UNE SEULE fois malgré la surcharge, zéro appel si zéro surcharge.
  - [x] T4.5 Non-régression : filtre AC9 complet exécuté (100 tests, 0 échec) — voir Debug Log References pour le défaut pré-existant rencontré et son contournement en test.
- [x] **T5 — Doc QA append-only** (AC7) — `docs/qa/domains/rights-management.md` : **Section 17** ajoutée (append-only, sections 1-16 non renumérotées), avec runbook e2e /vm différé, limite transitoire D6, ET un encart documentant le défaut Livewire pré-existant découvert (hors périmètre 42.3) et son contournement de test.

---

## Dépendances

- **Amont (satisfaites, code sur main)** : **42.1** (`review` approuvée) — colonne `role` + `withPivot('role')` ×3 + `ROLES`/`assertValidRole`/`defaultRoleForGlobalRole` + badge PP lu sur `role==='owner'` ; **42.2** (`review` approuvée) — projection routée par arêtes, **`UserGroupService::resyncGroupAdProjection(UserGroup)` public** (créé POUR cette story), observer `updated()` (`wasChanged('role')`, classe-like, fail-soft) + `disableAdResync()`/`enableAdResync()`, contrat masse (review #4). **4.15** (`done`) — modale PP + event `head-teachers-updated` + listener `refreshMembers` (réutilisés tels quels).
- **Parallèle : 42.4** (read-back rôles au sync-from-ad) — indépendante. **Chevauchement de fichiers : AUCUN sur le code** (42.4 travaille dans `UserGroupService::projectFoldedGroup`/`syncFromAd` + `UserGroupServiceLegacyCompatibilityTest` ; 42.3 n'édite AUCUN fichier `app/**` — AC8 le verrouille). **UN chevauchement bénin identifié** : `docs/qa/domains/rights-management.md` (les deux stories y appendent une section — merge trivial, cf. T5) ; plus les fichiers de suivi communs (`sprint-status.yaml`, backlog) gérés par l'orchestrateur.
- **Aval** : 42.4 lève la limite D6 (le read-back dérivera des groupes AD que la projection écrit depuis l'arête → les éditions UI deviennent stables au read-back).

---

## Dev Notes

### Ancrage code (chemins:lignes vérifiés 2026-07-14)

| Élément | Fichier:ligne | Action 42.3 |
|---|---|---|
| Page groupe SFC — `members()` (view-model : `role` global + `is_head_teacher`), `removeMember` (pattern double guard l.138-161), `toggleUser` l.163-170, `save` l.192-216, `cancelEditing` l.177-190, listener `head-teachers-updated` l.91-95 | `resources/views/pages/users/groups/[id]/index.blade.php` | T1.1, T2.1/T2.2, T3 |
| Table membres (2 onglets, badge PP l.23, bouton retrait l.34-40) | `resources/views/pages/users/groups/[id]/_partials/members-table.blade.php` | T1.2, T2.3 (colonne + select) |
| Bloc « Ajouter des membres » (checkbox list Alpine, l.37-68) — contient des `.form-control` LEGACY (ne pas en ajouter) | `resources/views/pages/users/groups/[id]/_partials/edit-form.blade.php` | T3.3 |
| Onglets Élèves/Profs (include members-table, split par rôle GLOBAL) | `resources/views/pages/users/groups/[id]/_partials/members-list.blade.php` | Aucun diff attendu |
| Modale PP (refreshState à l'open l.94-106/116-131, save → `head_teacher_ids` l.177-245, event `head-teachers-updated` l.227) | `resources/views/pages/users/groups/[id]/_partials/head-teacher-section.blade.php` | **AUCUNE modification** (D2, AC8) |
| Vocabulaire + gardes (ROLES l.63-67, `assertValidRole` l.101-110, `defaultRoleForGlobalRole` l.121-132) | `app/Models/Pivot/UserGroupUserPivot.php` | Consommé (pas d'édition — labels FR côté SFC, D1) |
| Point d'entrée resync public (créé pour 42.3) | `app/Services/UserGroupService.php:1134-1153` (`resyncGroupAdProjection`) | Consommé (T3.4) |
| Observer pivot — `updated()` l.105-140 (resync unitaire auto, fail-soft), `disableAdResync`/`enableAdResync` l.69-77, `disableSync` commun l.59-67 | `app/Observers/UserGroupUserPivotObserver.php` | Consommé (D4, T3.4) |
| `updateGroup` (AD-first + read-back scopé l.230 — source de la limite D6) ; `getAssignableUsers` l.43 | `app/Services/UserGroupService.php:124-242` | Consommé tel quel |
| Gates groupes (`update-group` → `update` → user.modify) | `app/Policies/GroupPolicy.php:22-32` | Consommé (D7) |
| Toasts Livewire (`toastSuccess`/`toastError`/`toastAccessDenied`) | `app/Components/Traits/WithToasts.php` | T2.1 |
| Relation pivot (withPivot `role`, `->using(UserGroupUserPivot)`) — `updateExistingPivot` émet `updated` si dirty | `app/Models/UserGroup.php:65-77` + constat vendor 42.2 (`InteractsWithPivotTable::updateExistingPivotUsingCustomClass`) | Fondation T2.2/T3.4 |
| Tests patrons : fixtures arêtes (`makeClasse` sync associatif role), `primeNoLdap`, `makeAdmin`/`makeReader`, observers disabled setUp, forbidden pattern | `tests/Feature/Livewire/Users/GroupShowMembersTabsTest.php` (231 l.) | T4 |
| Patron fake service (bind conteneur, `updateGroup` andReturnUsing → écrit le pivot) | `tests/Feature/Livewire/Users/HeadTeacherSectionTest.php:81-105` | T4.3/T4.4 |
| Doc QA (Sections 15/16 = 42.1/42.2) | `docs/qa/domains/rights-management.md:1057,1111` | T5 (Section 17) |

### Pièges & points d'attention

- **Piège n°1 — collision de clés view-model (42.1 #5)** : `members()` expose DÉJÀ `'role'` = rôle GLOBAL (pilote les onglets et le badge PP). Le rôle d'arête arrive sous `edge_role`/`edge_role_label`, JAMAIS sous `role`. Ne pas « unifier » : un élève promu `manager` reste dans l'onglet Élèves (population) tout en affichant « Prof » en colonne (rôle dans le groupe) — c'est voulu et honnête.
- **Piège n°2 — rafale d'updates = tempête LDAP (contrat 42.2 #4)** : chaque `updateExistingPivot` dirty émet un event → une reprojection AD complète du groupe. UNITAIRE (colonne) : OK, c'est le canal prévu. EN MASSE (surcharges de `save()`) : OBLIGATOIREMENT `disableAdResync()`/try-finally-`enableAdResync()` + UN `resyncGroupAdProjection`. Le test T4.4 (mock partagé, count total d'appels = 1) prouve la suspension.
- **Piège n°3 — `save()` est AD-first** : à l'issue d'`updateGroup`, le read-back a DÉJÀ créé les arêtes des nouveaux membres au défaut dérivé (résolution D2.3 de la projection + `projectFoldedGroup`). Les surcharges se calculent sur l'état FRAIS du pivot (relire après `updateGroup`, pas depuis un état pré-save), et uniquement pour le diff d'ids réellement nouveaux. Ne PAS écrire le pivot AVANT `updateGroup` (l'arête n'existe pas encore ; et le read-back l'écraserait).
- **Piège n°4 — read-back = écraseur transitoire (D6)** : ne pas « corriger » en supprimant le `syncFromAd` d'`updateGroup` ni en réécrivant le rôle après coup dans `removeMember` — c'est le flux 4.15/42.2 assumé, levé par 42.4. Documenter, ne pas contourner.
- **Piège n°5 — action `upload` INTERDITE** (Livewire réserve la méthode — `move()` casse `TempUploadedFile`). Nommer `updateMemberRole`/`setPendingRole`.
- **Piège n°6 — DaisyUI 5** : `.form-control` n'existe plus, `.label` est inline-flex. L'edit-form contient des `.form-control` hérités (hors scope) — ne pas en AJOUTER ; pour tout wrapper label+champ nouveau : `flex flex-col` + `w-full`, label au-dessus (`feedback_form_label_above_input_tooltip_hints`).
- **Piège n°7 — `wire:key` sur les lignes/selects** : la liste des membres est re-rendue après édition (unset computed) et l'edit-form filtre en Alpine ; sans `wire:key` stable par user, Livewire peut recycler un select sur le mauvais membre (état visuel faux).
- **Piège n°8 — validation d'une valeur NON constante** : `updateMemberRole`/`setPendingRole` reçoivent une string du client. `assertValidRole` LÈVE — toujours la wrapper en try/catch → toast, jamais laisser remonter (500). C'est exactement le cas anticipé par la review 42.1 #2 ; côté projection, la valeur sale resterait fail-soft (42.2 D2), mais elle ne doit JAMAIS atteindre le pivot.
- **Piège n°9 — dérivation SQL only** : le défaut proposé au rattachement lit `users.role` (colonne du modèle déjà chargé par `getAssignableUsers()`), JAMAIS `isProf()` (round-trip LDAP par user — `project_isprof_iseleve_ldap_first_cost`). Tests : `primeNoLdap()` obligatoire sur toute fixture.
- **Piège n°10 — pas de sur-conception** : pas de modale de confirmation pour un changement de rôle (geste réversible), pas de colonne triable, pas d'édition en lot dans la table, pas d'events nouveaux (la couture D2 réutilise `head-teachers-updated` et `refreshState()` existants). Règle dérivable → énoncer, avancer (`feedback_no_overengineered_choices`).
- **Worktree** : `cp -al` du vendor, jamais de symlink (`project_ultradev_worktree_vendor_trap`). Ne JAMAIS interagir avec la VM depuis un worktree — e2e réel différé post-merge (runbook T5).

### Testing standards

- Tests sur l'**HÔTE** uniquement (php8.4 + sqlite ; la VM n'a pas pdo_sqlite). Filtres ciblés (AC9), jamais de run massif.
- Patrons : `GroupShowMembersTabsTest` (schéma `CreatesPermissionSchema` + `PermissionSeeder`, `withoutVite`, mock `ShareService` + table `quota_rules` minimale pour le full-render, observers disabled en setUp/ré-enable en tearDown, purge `User::$ldapCache`, `primeNoLdap`) et `HeadTeacherSectionTest` (`bindFakeUserGroupService` — le composant résout le service par le conteneur en `boot()`, donc un bind Mockery couvre `updateGroup`/`resyncGroupAdProjection`/`getById`).
- `Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])` ; assertions HTML sur libellés (`assertSee('Prof principal')`), pas sur les valeurs techniques ; `assertForbidden` pour les gardes.

### Project Structure Notes

- **AUCUN fichier `app/**` modifié** (AC8 — c'est la garantie anti-chevauchement 42.4). 3 blades édités (page groupe + 2 partials), 1 fichier de test créé + 1 étendu, doc QA Section 17.
- Racine projet = Laravel (`app/`, pas `laravel/app`). Aucun fichier `agent/**` (pas de bump version agent). Aucune route nouvelle (filesystem routing : la page existe).
- Livewire SFC anonymes (`new class extends Component`) — conventions du dossier `pages/` respectées ; partials non réactifs restent de simples blade.

### References

- [Source: _bmad-output/planning-artifacts/epics-socle-role-groupes.md#Story 42.3] — intention + AC-skeleton figé ici ; FR-S4, UX-S1
- [Source: _bmad-output/implementation-artifacts/42-1-colonne-role-arete-backfill.md] — vocabulaire/gardes/défaut dérivé (AC4/AC5), piège collision view-model (#5), piège réécriture d'arêtes existantes (#2)
- [Source: _bmad-output/implementation-artifacts/42-2-projection-ad-depuis-aretes.md] — `resyncGroupAdProjection` (T3.2), observer `updated()` (D4), D7 (limite transitoire), flag `$adResyncEnabled`
- [Source: _bmad-output/codeReviews/42-2.md#4] — CONTRAT masse : disableAdResync + 1 resync explicite (repris AC5)
- [Source: _bmad-output/codeReviews/42-1.md#1/#2] — écrivains UI au rôle dérivé ; `assertValidRole` sur valeur non constante (repris D7)
- [Source: _bmad-output/implementation-artifacts/4-15-ecriture-pp-ad-ui-professeur-principal.md] — modale PP, event `head-teachers-updated`, double guard, toast honnête
- [Source: memory/project_livewire_reserved_upload_method.md ; project_daisyui5_form_control_removed_label_inline.md ; feedback_form_label_above_input_tooltip_hints.md ; feedback_per_group_property_belongs_on_group_pages.md ; project_isprof_iseleve_ldap_first_cost.md ; feedback_no_overengineered_choices.md]

## Dev Agent Record

### Agent Model Used

claude-sonnet-5 (worktree `ultradev/42-3`, dev-story)

### Debug Log References

- **Défaut Livewire pré-existant découvert (HORS périmètre 42.3)** : le ré-rendu Livewire « subséquent » (après un `->call()` qui aboutit sans exception) de la page groupe casse pour un groupe `type='classe'` dès que ses 3 enfants Livewire conditionnels (`class-share-section`, `head-teacher-section`, `group-quota-section`) sont simultanément présents. Erreur : `Illuminate\View\ViewException: Invalid Livewire child tag name...` levée par `Livewire\Features\SupportNestingComponents\SupportNestingComponents::getPreviouslyRenderedChild()`.
  - **Reproduction isolée** : confirmé reproductible avec l'action `removeMember` INCHANGÉE (aucun lien avec le diff 42.3) — root cause tracée par instrumentation temporaire (revertée) de `vendor/livewire/livewire` : `class-share-section.blade.php` et `head-teacher-section.blade.php` structurent tout leur template autour d'un `@if (!$isClasse) <div></div> @else … @endif` de PREMIER NIVEAU (pas de tag racine stable) ; le marqueur HTML `<!--[if BLOCK]><![endif]-->` injecté par `Livewire\Features\SupportMorphAwareBladeCompilation` avant ce `@if` fait capturer un tag racine VIDE par `SupportNestingComponents`, qui casse au moment où ce enfant est « spoofé » (skip de re-render) — seulement observable avec les 3 enfants simultanés (seuil exact non investigué plus avant, hors scope).
  - **Contournement appliqué (tests uniquement, aucun fichier hors scope modifié)** : dans `GroupMemberRoleEditTest.php`, les scénarios combinant une fixture `type='classe'` ET une mutation qui aboutit (`updateMemberRole` réussi ou avec retour anticipé) appellent l'action directement sur `Livewire::test(...)->instance()->updateMemberRole(...)` plutôt que `->call('updateMemberRole', ...)` — exerce la MÊME logique PHP réelle (Gate, écriture pivot, observer) sans déclencher le ré-rendu buggé. Les scénarios non-classe (`type='custom'/'projet'`) et le scénario `forbidden` (exception avant re-render) utilisent `->call()` normalement.
  - **Documenté** : encart dédié en doc QA Section 17 + ce Debug Log. Correction éventuelle (envelopper le contenu conditionnel d'un tag racine stable dans `class-share-section.blade.php`/`head-teacher-section.blade.php`) laissée à une story ultérieure — ces 2 fichiers sont explicitement HORS scope de 42.3 (AC8, File List).
- Environnement worktree : `bootstrap/cache/` et les sous-dossiers `storage/framework/{sessions,views,cache,testing}` et `storage/logs/` étaient absents du worktree fraîchement créé (répertoires vides, non versionnés — `project_storage_convention_non_versioned`) ; créés localement (`mkdir -p` + permissions) pour permettre l'exécution des tests hôte. Aucun impact git (ignorés).
- Bug réel corrigé en cours de dev : `use InvalidArgumentException;` (import PHP) en tête de `index.blade.php` levait `ErrorException: The use statement with non-compound name 'InvalidArgumentException' has no effect` — un blade Livewire SFC compile en namespace GLOBAL (pas de `namespace` déclarée), donc un `use` d'une classe globale non qualifiée est un no-op invalide. Retiré ; les `catch (InvalidArgumentException)` fonctionnent nativement (classe déjà résolue dans le namespace global).

### Completion Notes List

- Toutes les tâches T1-T5 livrées selon les AC1-AC9 de la story.
- **Zéro fichier `app/**` modifié** (AC8) — confirmé par `git diff --stat -- app/` vide. `head-teacher-section.blade.php` et `class-share-section.blade.php` non touchés.
- Écart mineur vs Dev Notes documenté dans T3.1 : `getAssignableUsers()` ne sélectionne pas `role` (contrairement à l'hypothèse de la story) — contourné par une requête SQL complémentaire dans le SFC, zéro LDAP, zéro fichier `app/**`.
- Décision D3 (option `owner` visible hors classe si arête historique) implémentée sans `disabled` HTML : l'option est simplement affichée si `$type === 'classe'` OU si l'arête courante vaut déjà `owner`, la garde serveur (`updateMemberRole`) refusant tout payload forgé qui tenterait de la (ré)affecter hors classe — plus simple que l'alternative `disabled-selected`, comportement final identique (état visible + refus serveur).
- Tests : 100/100 verts sur le filtre AC9 complet (`GroupShowMembersTabs|GroupMemberRole|HeadTeacherSection|UserGroupUserPivotObserver|UserGroupServiceLegacyCompatibility|UserDerivedRolePayload`), 0 échec, 0 test risky après ajout d'une assertion sur le scénario no-op. `BulkPasswordResetGroupsTest` (env LDAP absent) volontairement NON exécuté (pré-existant, hors scope — AC9).
- Aucune question bloquante.

### File List

- `resources/views/pages/users/groups/[id]/index.blade.php` (modifié)
- `resources/views/pages/users/groups/[id]/_partials/members-table.blade.php` (modifié)
- `resources/views/pages/users/groups/[id]/_partials/edit-form.blade.php` (modifié)
- `tests/Feature/Livewire/Users/GroupShowMembersTabsTest.php` (modifié — 4 tests ajoutés)
- `tests/Feature/Livewire/Users/GroupMemberRoleEditTest.php` (créé — 12 tests)
- `docs/qa/domains/rights-management.md` (modifié — Section 17 ajoutée, append-only)
- `_bmad-output/implementation-artifacts/42-3-ui-role-editable-page-groupe.md` (ce fichier)

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-07-14 | 0.1 | Story CRÉÉE (SM/create-story, Fable 5, worktree ultradev/42-3). AC-skeleton de l'epic figé en 9 AC. Décisions actées : D1 clés view-model `edge_role`/`edge_role_label` (collision avec `role` global interdite, arête sale affichée member) ; D2 cohérence colonne↔modale PP par convergence pivot (une seule source = l'arête, modale = canal 4.15 conservé, head-teacher-section NON modifiée, refresh mutuel via mécanismes existants) ; D3 option owner UNIQUEMENT type classe (refus serveur du forgé, arête owner historique hors classe visible/rétrogradable) ; D4 édition unitaire = updateExistingPivot + observer 42.2 (pas d'updateGroup → pas de read-back → le rôle édité persiste) ; D5 rattachement = défaut dérivé proposé + surcharge Élève/Prof (jamais owner), appliquée post-updateGroup en masse sous disableAdResync + 1 resyncGroupAdProjection (contrat review 42.2 #4) ; D6 limite transitoire read-back documentée (levée 42.4, UI sans fausse promesse) ; D7 gardes = update-group + assertValidRole sur entrée UI (review 42.1 #2). AC8 verrouille ZÉRO fichier app/** modifié → zéro chevauchement code avec 42.4 (seul chevauchement bénin : doc QA rights-management.md append-only). 5 tâches. | SM (Fable 5) |
| 2026-07-14 | 1.0 | Implémentation (dev-story, claude-sonnet-5, worktree ultradev/42-3). Colonne « Rôle » (lecture + select unitaire), rattachement défaut/surcharge + contrat masse, doc QA Section 17. Zéro fichier `app/**` modifié (AC8 vérifié). Écart mineur vs Dev Notes : `getAssignableUsers()` ne sélectionne pas `role`, contourné par requête SQL complémentaire confinée au SFC. Défaut Livewire pré-existant découvert (hors scope, documenté) : ré-rendu successif de la page groupe `classe` cassé par une interaction `SupportMorphAwareBladeCompilation`/`SupportNestingComponents` sur `class-share-section.blade.php`/`head-teacher-section.blade.php` (root non stable sous `@if`) — contourné en test (appel direct sur l'instance), non corrigé (fichiers hors scope 42.3). 16 tests (4 étendus + 12 créés), filtre AC9 complet : 100/100 verts. Status → review. | Dev (claude-sonnet-5) |

---

## Recommandation Modèle Dev

**sonnet** (confirme le pré-cadrage de l'epic).

Justification : UI Livewire cadrée de bout en bout — le socle dangereux est DERRIÈRE (42.1/42.2 livrées) et la story ne touche AUCUN fichier `app/**` : le canal de resync est un point d'entrée public existant, l'édition unitaire repose sur l'observer déjà testé, et les deux vrais risques (contrat masse disableAdResync+1 resync, collision de clés view-model) sont prescrits pas à pas avec tests dédiés (T4.3/T4.4 comptent les appels au mock). Pas de décision d'architecture ouverte, pas d'écriture AD nouvelle, patrons de tests existants à recopier (`GroupShowMembersTabsTest`/`HeadTeacherSectionTest`). Review par le modèle opposé (opus) pour traquer les gardes serveur manquantes et le respect du contrat masse.

## Code Review Record (2026-07-14)

Review adversariale **opus** (dev sonnet, review = référence) : **approuvé** après corrections — 1 critique (pré-existant, gate de la story), 1 important, 3 mineurs. Doc : `_bmad-output/codeReviews/42-3.md`.

1. (#1 🔴) Défaut Livewire pré-existant CORRIGÉ (extension de périmètre arbitrée par l'orchestrateur, blades hors app/**) : `head-teacher-section.blade.php` + `class-share-section.blade.php` enveloppés dans une balise racine `<div>` stable — l'édition de rôle ET `removeMember` fonctionnent sur le vrai canal ; AC2 « jamais de 500 » tenue sur classe (#2).
2. (#3) Garde owner-hors-classe déplacée sur `$group->type` (DB) — `$this->type` est client-mutable.
3. (#4) 6 tests classe rebasculés du contournement (appel direct d'instance) vers le vrai canal `->call()->assertOk()`.
4. (#5) Surcharge au rattachement sur groupe non-classe réel : à valider au runbook e2e /vm (doc QA §17).

Tests post-corrections : 110/307 verts.
