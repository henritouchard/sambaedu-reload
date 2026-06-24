# Story 4.12 : Peuplement des groupes AD `Equipe_X` par rôle (parité SE4 — ACL prof effectives)

Status: review

> **Type** : quick spec mono-changement (parité ISO SE4). Pas de refonte de modèle.
>
> **Origine** : Epic 4 — gestion des droits/groupes. Suite logique de 4.11 (pivot global memberships livré). Corrige un **bug latent de parité** : le `rwx` prof posé par `ShareService` ne mord sur aucun dossier de classe sur une install greenfield SE5.
>
> **Mémoire projet liée** : `project_equipe_group_never_populated_se5` (Equipe_X jamais peuplé → ACL prof inopérantes) ; `project_socle_commun_and_unified_resolution` ; orientation parquée `docs/group-model-multivertical-orientation.md`.

---

## Story

En tant que **responsable d'établissement**,
je veux que l'appartenance d'un prof à une classe le place dans le groupe AD `Equipe_<classe>` (et non `Classe_<classe>`),
afin que les ACLs `rwx` déjà posées par `ShareService` sur `group:equipe_<x>` deviennent effectives, en restant strictement ISO SE4.

---

## Contexte & cause racine (investigation confirmée — lecture code 2026-06-24)

### Le modèle réel (à respecter tel quel)

- **1 ligne SQL = 1 groupe logique.** Table `user_groups` (migration `2026_02_06_115500_create_rights_management_tables.php:54-64`) : colonnes `name`, `display_name`, `type`, `ad_dn`… Le nom est **nu** (`3eme3`), le `type` (`classe`, `equipe`, `cours`…) stratifie la sémantique. L'UI (`resources/views/pages/users/groups/new` + `[id]/_partials/edit-form.blade.php`) n'expose **qu'un seul** nom de groupe + un type + une liste plate de membres. **Pas** de groupe SQL `equipe_3eme3` distinct.
- **Le serveur expanse les noms AD.** `GroupRepository::createGroup` (`app/Repositories/GroupRepository.php:457-470`) : pour `type='classe'` **ou** `type='equipe'`, crée **3 groupes AD** — `Classe_X`, `Equipe_X`, `PP_X` — dans leurs OU respectives.
- **Le rôle est une donnée de 1ʳᵉ classe.** Colonne `users.role` (`…115500…:32`, valeurs `eleve|prof|admin|autre`), lue par `User::isProf()` / `User::isEleve()` (`app/Models/User.php`). **Non** dérivée de l'appartenance groupe. → le serveur a tout pour router par rôle.
- **Les ACLs sont déjà correctes.** `app/Services/Filesystem/ShareService.php` pose `group:equipe_<x>:rwx` sur la racine de classe, `_travail`, `_profs` et chaque dossier élève (l. 162, 199, 225, 249, 273). Elles attendent juste que `Equipe_X` soit peuplé. **Ne pas y toucher.**

### Le bug

`syncAdGroupMembersByUserIds()` (`app/Services/UserGroupService.php:501-539`) écrit **tous** les membres dans **un seul** groupe cible, déterminé par `resolvePrimaryGroupName()` (l. 554-566) — qui replie `'class','classe','equipe'` → **toujours `Classe_{name}`** (l. 559).

Conséquence : profs **et** élèves atterrissent dans `Classe_X`. `Equipe_X` (et `PP_X`) restent **vides** → `group:equipe_<x>:rwx` ne s'applique à personne → le prof n'a aucun droit sur les dossiers de classe.

`detectTypeFromAdGroupName()` (l. 599-611) classe déjà `Equipe_*` **et** `PP_*` → `type='equipe'` : le read-back AD→SQL reste cohérent une fois les groupes peuplés.

### Incohérence aggravante à corriger au passage

Les **deux** points d'appel divergent :
- `createGroup` (l. 72) sync contre le nom **résolu** (`$primaryGroupName` = `Classe_3eme3`).
- `updateGroup` (l. 119) sync contre le nom **brut** (`$newName` = `3eme3`).

→ Le fix doit **centraliser** la résolution + la partition par rôle dans un helper unique appliqué aux **deux** chemins.

---

## Objectif (ISO SE4, pas de refonte)

Pour un groupe de `type` `classe`/`equipe`, **partitionner les membres par rôle** au moment de la sync AD, puis réutiliser la sync idempotente existante **une fois par cible** :

| Prédicat membre        | Groupe AD cible |
|------------------------|-----------------|
| `User::isProf()` → vrai | `Equipe_{name}` |
| sinon (`eleve`/`admin`/`autre`) | `Classe_{name}` |

Partition **binaire sur `isProf()`** : aucun membre orphelin, et on préserve le comportement « tout non-prof reste dans `Classe_X` » (élèves, et incidemment admin/autre comme aujourd'hui).

Les autres types (`cours`, `matiere`, `projet`, `custom`…) **conservent le comportement actuel** (une seule cible via `resolvePrimaryGroupName`).

---

## Décisions de cadrage (actées)

- **D1 — `PP_X` DIFFÉRÉ.** Pas de peuplement de `PP_X` dans cette story. Désigner un prof principal *d'une classe précise* est un rôle **par-arête** (par pivot user×classe), que SE5 ne capture pas (`users.role` est global). Le faire = modèle role-on-edge **explicitement parqué** (`docs/group-model-multivertical-orientation.md`). `PP_X` reste vide → **limite connue documentée**. **Sans effet sur l'objectif** : `ShareService` ne pose aucune ACL sur `PP_X`, uniquement sur `equipe_<x>`.
- **D2 — Partition binaire `isProf()`.** Profs → `Equipe_X` ; tout le reste → `Classe_X`. Pas de 3ᵉ bucket.
- **D3 — Hors-scope strict** : aucune colonne `role` sur le pivot, pas de profil/zones/matrice, pas de projection générique role-groups. On rétablit uniquement la parité de peuplement `Equipe_X`.

---

## Changements attendus

1. **Helper de projection centralisé** (dans `UserGroupService`) qui, pour un nom de groupe nu + son type + la liste des `user_ids` sélectionnés :
   - si `type ∈ {classe, equipe}` : partitionne les `user_ids` par `isProf()`, puis appelle `syncAdGroupMembersByUserIds('Equipe_'.$name, $profIds)` **et** `syncAdGroupMembersByUserIds('Classe_'.$name, $nonProfIds)` ;
   - sinon : un seul appel sur le nom résolu (comportement actuel inchangé).
   - Respecter le bypass CN legacy préfixé (`resolvePrefixedGroupOu`/`resolvePrimaryGroupName` matiere_classe) : si le nom est déjà un CN préfixé, ne pas ré-expanser.
2. **Brancher ce helper dans `createGroup` (l. 69-73) ET `updateGroup` (l. 117-120)** — supprime au passage l'incohérence nom-résolu vs nom-brut.
3. **Idempotence** : réutiliser tel quel le diff add/remove fail-soft de `syncAdGroupMembersByUserIds` (l. 524-538). Le retrait d'un prof de la classe doit le retirer d'`Equipe_X` ; le passage d'un membre prof→élève (ou inverse) doit le déplacer entre les deux groupes au prochain sync (la suppression ne portant que sur les DN connus SQL, vérifier que le membre déplacé est bien retiré de l'ancien groupe).
4. **Ne pas toucher** `ShareService` / `AclService` / les ACLs.

---

## Critères d'acceptation

1. Sur un groupe `type='classe'` nommé `3A` contenant 1 prof + 2 élèves, après `createGroup`/`updateGroup` : le prof est membre de l'AD `Equipe_3A`, les 2 élèves de `Classe_3A`. `PP_3A` reste vide.
2. Retirer le prof du groupe le retire d'`Equipe_3A` (idempotent, pas de doublon, fail-soft si DN absent).
3. Déplacer un membre `prof`↔`eleve` le bascule entre `Equipe_3A` et `Classe_3A` au sync suivant (pas de présence dans les deux).
4. Après `ShareService::createClassShare` + assignation, `getfacl` sur `/var/sambaedu/Classes/Classe_3A/_travail` et sur un dossier élève montre le prof effectivement `rwx` (le groupe `equipe_3a` n'est plus vide).
5. `UserPolicy` (scoping prof via membership SQL `type='equipe'`/suffixe X, `app/Policies/UserPolicy.php:233-271`) reste inchangé/fonctionnel — il lit le pivot SQL, indépendant de cette projection AD.
6. Read-back `syncFromAd` cohérent : un membre d'`Equipe_3A` reste classé `type='equipe'` ; aucune ligne SQL dupliquée/conflictuelle ne casse l'UI groupe.

---

## Contraintes

- **AD partagé/fédéré (75 étab)** : écritures de membership strictement scopées au groupe visé, idempotentes et **fail-soft** (ne pas casser si l'entrée existe déjà / DN absent). Réutiliser le diff existant, ne pas réécrire la couche LDAP.
- **Tests sur l'HÔTE** (php 8.4 + sqlite + vendor). Étendre/ajuster : `UserGroupServiceLegacyCompatibilityTest`, et tout test couvrant `resolvePrimaryGroupName` / `syncAdGroupMembersByUserIds`. Couvrir la partition par rôle (profs→Equipe, autres→Classe), l'idempotence, le bascule de rôle. Mocker la couche `GroupRepository` (add/remove/getGroupMembers).
- **Validation e2e sur /vm** : créer une classe + assigner 1 prof + élèves, lancer le partage, vérifier `getfacl` + appartenance AD réelle (`samba-tool group listmembers Equipe_<x>` / `getent group`). Penser aux **migrations VM non auto-jouées** (`migrate:status`) et au **chown www-admin** si fichiers config touchés.

---

## Hors-scope (NE PAS faire)

- **Pas de peuplement `PP_X`** (D1 — différé, limite connue).
- Pas de colonne `role` sur le pivot, pas de profil/zones/matrice, pas de projection générique role-groups — orientation future parquée (`docs/group-model-multivertical-orientation.md`).
- Pas de modification des ACLs ni de `ShareService`/`AclService`.
- Pas de changement de l'UI de gestion des groupes (l'UI mono-nom reste la cible ISO SE4).

---

## Tasks / Subtasks

- [x] **T1 — Helper de projection centralisé** `syncRoleAwareAdGroupMembers(rawName, type, userIds)` dans `UserGroupService` (AC1, AC2, AC3) :
  - [x] type ∈ {classe, equipe} : partition binaire par `User::isProf()` → profs vers `Equipe_<base>`, reste vers `Classe_<base>` ;
  - [x] dérivation de la base nue via `stripClasseLikePrefix()` (gère le nom NU de `createGroup` ET le CN primaire `Classe_X`/`Equipe_X` renvoyé par l'edit-form de `updateGroup`) ;
  - [x] types non-classe : cible unique via `resolvePrimaryGroupName()` (bypass des CN legacy `Matiere_*@*`, `Cours_*`, `Projet_*`, `Matiere_*` — pas de ré-expansion) ;
  - [x] sync systématique des DEUX cibles (même partition vide) pour garantir le retrait/bascule.
- [x] **T2 — Branchement** dans `createGroup` (remplace l'appel `syncAdGroupMembersByUserIds($primaryGroupName, …)`) ET `updateGroup` (remplace `syncAdGroupMembersByUserIds($newName, …)`), supprimant l'incohérence nom-résolu vs nom-brut (AC1).
- [x] **T3 — Idempotence / bascule de rôle** : réutilisation telle quelle du diff add/remove fail-soft de `syncAdGroupMembersByUserIds` (AC2, AC3). Aucune réécriture de la couche LDAP.
- [x] **T4 — Non-régression ShareService/AclService/UI** : aucune modification (AC4, AC5).
- [x] **T5 — Tests hôte** : extension de `UserGroupServiceLegacyCompatibilityTest` (partition par rôle, idempotence, retrait prof, bascule prof↔élève, type non-classe, bypass CN `matiere_classe`). Mocks `GroupRepository` add/remove/getGroupMembers.

> **Limite connue (D1)** : `PP_X` reste non peuplé — rôle prof-principal par-arête non capturé par `users.role`. Sans effet sur l'objectif (aucune ACL `ShareService` sur `PP_X`).

---

## Dev Agent Record

### Context

- Modèle réel respecté : 1 ligne SQL (nom NU ou CN primaire) → le serveur expanse les CN AD. `createGroup` stocke le **CN primaire résolu** (`Classe_X`) comme `name` SQL ; l'edit-form de `updateGroup` renvoie donc ce CN. Le helper dérive la base nue (`stripClasseLikePrefix`) pour réconcilier les deux chemins — c'est la dé-duplication exigée par la story.
- `User::isProf()` retombe sur `users.role` quand aucune résolution LDAP n'est disponible.

### Completion Notes

- **Partition par rôle** : profs → `Equipe_<base>`, tout le reste (élèves/admin/autre) → `Classe_<base>`. `PP_<base>` jamais touché.
- **Idempotence & bascule** : le diff existant (add = `desired \ current`, remove = `current ∩ sqlKnown \ desired`) suffit ; en synchronisant systématiquement les deux cibles, un membre prof→élève est retiré d'`Equipe_X` et ajouté à `Classe_X` au sync suivant (jamais présent dans les deux). Vérifié par test mutable-membership.
- **Bypass** : `matiere_classe` (`Matiere_X@Y`), `cours`, `projet`, `matiere` → cible unique inchangée (pas de ré-expansion en Classe_/Equipe_).
- **Aucune modification** de `ShareService`/`AclService`/ACL/UI.

#### Détail d'environnement de test (worktree)

Le worktree n'a pas de `vendor/` ni `bootstrap/cache` propres. Pour exécuter les tests sur l'hôte **sans toucher la VM**, `vendor/` a été reconstruit localement (packages symlinkés depuis le repo principal, mais `vendor/autoload.php`, `vendor/composer/` et `vendor/bin/` recopiés en réel pour que l'autoloader PSR-4/classmap résolve vers le `app/` du **worktree** et non du repo principal). `bootstrap/cache/` créé. Ces répertoires sont gitignored (aucun impact sur le commit).

### File List

- `app/Services/UserGroupService.php` (modifié) — `createGroup`/`updateGroup` branchés sur le nouveau helper ; ajout de `syncRoleAwareAdGroupMembers()` et `stripClasseLikePrefix()`.
- `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (modifié) — 6 nouveaux scénarios + helpers de capture des appels add/remove et `primeNoLdap()` (court-circuit LDAP → fallback `role`).
- `docs/qa/domains/rights-management.md` (modifié) — section append-only scénarios e2e peuplement `Equipe_X` / getfacl prof / bascule de rôle.

### Change Log

- 2026-06-24 — Implémentation 4.12 : peuplement `Equipe_X` par rôle (partition `isProf()` centralisée), branchée sur create/update, idempotente fail-soft. Status → review.

---

## Senior Developer Review (AI)

_(à compléter en phase de code-review)_
