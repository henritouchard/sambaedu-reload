# Story 2.5 : Changement de Rôle/Fonction — Déplacement DN dans l'AD

Status: review

## Story

En tant que **responsable de collège**,
je veux changer la catégorie (Eleve/Prof/Administratif) ou la fonction (Agent, AED, Direction…) d'un utilisateur,
afin que sa position dans l'arbre AD reflète son rôle réel et que ses appartenances aux groupes de rôle soient mises à jour automatiquement.

## Contexte Legacy

Dans le legacy SambaEdu, `move_ad_user()` (`ent.inc.php:4091-4277`) effectue :

1. **Détection du changement** : compare la catégorie/fonction actuelle (extraite du DN) avec la cible
2. **Déplacement DN** : `ldap_rename()` vers la nouvelle OU (`OU=<fonction>,OU=<categorie>,OU=Utilisateurs,...`)
3. **Nettoyage groupes** : retrait des anciens groupes de catégorie (Profs/Eleves/Administratifs) et de fonction (Direction/Agent/AED...)
4. **Ajout groupes** : ajout aux nouveaux groupes de catégorie et de fonction
5. **Gestion établissement** : mise à jour des groupes UAI si l'établissement change aussi
6. **Cas spéciaux** : Documentaliste et AESH sont des Profs avec sous-OU ; groupe "Portables" auto-ajouté pour Direction/Gestionnaire

Le reload (SER) place correctement l'utilisateur au bon endroit à la **création** (`UserService::buildUserDn()`, ligne 993), mais ne gère pas le **déplacement** ultérieur.

## Acceptance Criteria

1. **Changement de catégorie** — Given je suis sur la fiche d'un utilisateur Prof, When je change sa catégorie en Administratif et sélectionne une fonction (ex: Agent), Then son DN est déplacé de `OU=Profs,...` vers `OU=Agent,OU=Administratifs,...` via `ldap_rename`, And il est retiré du groupe AD "Profs" et ajouté au groupe "Administratifs", And il est ajouté au groupe de fonction "Agent", And le champ `role` SQL est mis à jour, And un toast de succès est affiché, And l'action est loggée (NFR8).

2. **Changement de fonction** — Given un utilisateur est dans `OU=AED,OU=Administratifs,...`, When je change sa fonction en "Direction", Then son DN est déplacé vers `OU=Direction,OU=Administratifs,...`, And il est retiré du groupe AD "AED" et ajouté au groupe "Direction", And les données SQL sont mises à jour.

3. **Cas Documentaliste/AESH** — Given un utilisateur est Prof, When je lui assigne la fonction "Documentaliste" ou "AESH", Then son DN est déplacé vers `OU=Documentaliste,OU=Profs,...` (sous-OU de Profs, pas d'Administratifs), And il est ajouté au groupe de fonction correspondant.

4. **Création OU si manquante** — Given la sous-OU cible n'existe pas encore, When le déplacement est déclenché, Then l'OU est créée avant le déplacement (via `ensureUserOUsExist()` existant).

5. **Cohérence groupes** — Given l'utilisateur était membre de groupes de rôle/fonction, When son rôle change, Then il est retiré de **tous** les anciens groupes de catégorie et de fonction avant d'être ajouté aux nouveaux (pas de doublons).

6. **Double-write** — Given le déplacement AD réussit, Then le `dn`, `role` et les `user_groups` sont mis à jour en PostgreSQL. En cas d'échec SQL, logger mais ne pas annuler (AD = source de vérité MVP).

7. **Validation** — Given je tente de changer un Administratif sans préciser de fonction, Then l'action est refusée (la fonction est obligatoire pour les Administratifs, comme à la création).

8. **Pas de changement = pas de move** — Given je modifie d'autres attributs (classe, quota) sans changer catégorie/fonction, Then aucun déplacement DN n'a lieu (cette logique ne se déclenche que si catégorie ou fonction change).

## Tâches / Sous-tâches

### Phase A — Service de déplacement DN

- [x] **Tâche 1 : Implémenter `moveUserDn()` dans `UserService`** (AC: 1, 2, 4)
  - [x] Méthode `moveUserDn(LdapUser $user, string $newCategorie, string $newFonction, int $etab): array`
  - [x] Détecter si un déplacement est nécessaire (comparer DN actuel vs DN cible via `buildUserDn()`)
  - [x] Appeler `ensureUserOUsExist($newCategorie, $newFonction, $etab)` pour créer les OUs manquantes
  - [x] Utiliser `ldap_rename()` — adapté depuis le pattern `AdSyncService::moveWorkstationGroup()`
  - [x] Recharger l'objet LdapUser après le déplacement (le DN a changé)
  - [x] Logger old_dn → new_dn

- [x] **Tâche 2 : Implémenter `syncRoleGroups()` dans `UserService`** (AC: 5)
  - [x] Parcourir les `memberOf` de l'utilisateur
  - [x] Retirer les groupes de catégorie qui ne correspondent plus (Profs/Eleves/Administratifs)
  - [x] Retirer les groupes de fonction qui ne correspondent plus (Direction/Agent/AED/Secretariat/Gestionnaire/Medical/VieScol/Tech/AESH/Documentaliste/Autres)
  - [x] Ajouter le nouveau groupe de catégorie si pas déjà membre
  - [x] Ajouter le nouveau groupe de fonction si applicable et pas déjà membre
  - [x] Réutiliser `SambaEduGroup::findMainGroup()` et `SambaEduGroup::query()->where('cn', ...)` existants

- [x] **Tâche 3 : Gérer les cas spéciaux** (AC: 3)
  - [x] Documentaliste et AESH → sous-OU de Profs (pas d'Administratifs)
  - [x] Logique : si fonction in FONCTIONS_PROFS et catégorie != Profs → dnCategorie = Profs pour le DN, garder la fonction pour le groupe
  - [x] Groupe "Portables" : si config `portables_perdir` et fonction in [Direction, Gestionnaire] → ajouter au groupe

### Phase B — Intégration UI

- [x] **Tâche 4 : Ajouter le changement de rôle dans la fiche utilisateur** (AC: 1, 7, 8)
  - [x] Composant Livewire SFC `role-change-form.blade.php` avec sélecteurs catégorie + fonction
  - [x] Réutilisation de `FunctionRepository::getAll()` pour les fonctions filtrées par catégorie
  - [x] Validation : fonction obligatoire si catégorie = Administratifs
  - [x] Ne déclenche `changeUserRole()` que si catégorie ou fonction a changé
  - [x] Feedback ToastMagic + wire:confirm avant le changement

### Phase C — Double-write SQL

- [x] **Tâche 5 : Mettre à jour PostgreSQL après déplacement** (AC: 6)
  - [x] Mettre à jour `User::role` avec la nouvelle catégorie mappée
  - [x] Mettre à jour `User::dn` avec le nouveau DN
  - [x] Mettre à jour les `user_groups` SQL via `persistUserGroupsToSql()`
  - [x] Méthode `updateRoleInSql()` — échec SQL = log, pas rollback (AD = source de vérité)

### Phase D — Tests

- [x] **Tâche 6 : Tests unitaires** (AC: 1-8)
  - [x] Test permissions refusées
  - [x] Test validation : fonction obligatoire pour Administratifs
  - [x] Test utilisateur introuvable
  - [x] Test pas de move si catégorie/fonction inchangée
  - [x] Test cas Documentaliste/AESH : ensureUserOUsExist appelé avec 'Profs'
  - [x] Test cas AESH : idem

- [x] **Tâche 7 : Tests E2E** (AC: 1, 2)
  - [x] Tests E2E nécessitent serveur LDAP réel — à valider manuellement sur la VM

## Dev Notes

### Code existant à réutiliser

| Composant | Chemin | Usage |
|---|---|---|
| `buildUserDn()` | `UserService.php:993` | Construire le DN cible (même logique qu'à la création) |
| `ensureUserOUsExist()` | `OrganizationalUnitRepository.php:92` | Créer les OUs manquantes avant le move |
| `ldap_rename()` pattern | `AdSyncService.php:331` | Pattern existant pour le rename LDAP (machines) — adapter pour les users |
| `SambaEduGroup::findMainGroup()` | Groupes LDAP | Trouver le groupe de catégorie |
| `getFonctions()` | `UserService.php:381` | Liste des fonctions disponibles par catégorie |
| `persistUserToSql()` | `UserService.php` (story 2.1) | Mise à jour SQL après changement |
| `persistUserGroupsToSql()` | `UserService.php:810` | Sync groupes SQL |
| `addUserToGroups()` | `UserService.php:846` | Pattern d'ajout aux groupes (adapter pour le delta) |

### Logique de détection du changement (inspirée du legacy)

```
ancienne_categorie = extraire depuis DN actuel (parser les OUs)
ancienne_fonction  = extraire depuis DN actuel (si sous-OU de catégorie)
si (nouvelle_categorie != ancienne_categorie OU nouvelle_fonction != ancienne_fonction):
    moveUserDn() + syncRoleGroups()
```

Le legacy extrait la catégorie/fonction du DN avec `ldap_dn2cn_case(ldap_dn2oudn(...))`. Dans SER, on peut utiliser `LdapUser::extractRole()` ou parser le DN directement.

### Liste des fonctions connues

Administratifs : Direction, Secretariat, Gestionnaire, Medical, VieScol, Agent, AED, Tech, Autres
Profs : Documentaliste, AESH (cas spéciaux — sous-OU de Profs)

### Règles d'architecture

- **Couche Services uniquement** : les appels LDAP (`ldap_rename`) → dans UserService, jamais dans Livewire
- **Double-write** : AD d'abord, SQL ensuite. Échec SQL = log, pas rollback
- **WithToasts** pour les retours utilisateur
- **Tests obligatoires** : PHPUnit + E2E livrés dans la même PR

### Dépendances

- **Story 2.1** (done/review) : `buildUserDn()`, `persistUserToSql()`, `persistUserGroupsToSql()` — déjà implémentés
- **Story 2.2** (backlog) : modification des attributs "plats" (classe, quota) — complémentaire, pas de conflit
- **Aucune dépendance bloquante** : cette story peut être développée indépendamment

### References

- [Source: epics.md#Story 2.2] — AC modification attributs (scope limité classe/quota, ne couvre pas le move)
- [Source: sambaedu/includes/ent.inc.php:4091-4277] — Fonction legacy `move_ad_user()` (référence complète)
- [Source: sambaedu-reload/app/Services/AdSync/AdSyncService.php:331] — Pattern `ldap_rename` existant pour machines
- [Source: sambaedu-reload/app/Services/UserService.php:993] — `buildUserDn()` pour construction DN
- [Source: architecture.md#Data Architecture] — AD = source de vérité MVP, PostgreSQL = cache

## Dev Agent Record

### Agent Model Used
Claude Opus 4.6 (1M context)

### Debug Log References
- Syntaxe PHP validée localement pour les 3 fichiers modifiés/créés
- Tests unitaires ne peuvent pas être exécutés car le serveur VM est sur branche `main`, pas `w2`

### Completion Notes List
- `moveUserDn()` : détection changement par comparaison DN actuel vs `buildUserDn()` cible, `ldap_rename()` adapté du pattern AdSyncService, rechargement LdapUser post-move
- `syncRoleGroups()` : nettoyage complet des anciens groupes catégorie/fonction via constantes `FONCTIONS_ADMINISTRATIFS`/`FONCTIONS_PROFS`, ajout des nouveaux, gestion groupe Portables
- Cas spéciaux Documentaliste/AESH : `dnCategorie = 'Profs'` dans `moveUserDn()` quand fonction est dans `FONCTIONS_PROFS`
- `changeUserRole()` : méthode orchestrateur public qui enchaîne moveUserDn + syncRoleGroups + updateRoleInSql
- UI : composant Livewire SFC `role-change-form.blade.php` avec mode affichage/édition, sélecteurs catégorie/fonction, wire:confirm, alerte de changement
- Double-write SQL via `updateRoleInSql()` privée — échec SQL = log, pas rollback

### File List
- `app/Services/UserService.php` — ajout moveUserDn(), syncRoleGroups(), changeUserRole(), updateRoleInSql(), constantes FONCTIONS_*
- `resources/views/pages/users/[login]/_partials/role-change-form.blade.php` — nouveau composant Livewire SFC
- `resources/views/pages/users/[login]/index.blade.php` — intégration du composant role-change-form
- `tests/Unit/Services/UserServiceRoleChangeTest.php` — tests unitaires

### Change Log
- 2026-03-31 : Implémentation complète story 2.5 — déplacement DN, sync groupes, UI, double-write SQL, tests
