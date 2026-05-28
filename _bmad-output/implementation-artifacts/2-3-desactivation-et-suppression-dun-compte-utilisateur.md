# Story 2.3 : Désactivation et Suppression d'un Compte Utilisateur

Status: review

## Story

En tant que **responsable de collège**,
je veux désactiver ou supprimer un compte utilisateur avec archivage sécurisé de son home directory,
afin que les données de l'utilisateur soient préservées dans un premier temps puis définitivement supprimables, conformément aux obligations RGPD.

## Acceptance Criteria

1. **Désactivation d'un compte** — Given je désactive un compte utilisateur, When je confirme l'action, Then le compte est désactivé dans l'AD local en premier (`useraccountcontrol` → 514), puis dans PostgreSQL (`is_active` → false), And le home directory est déplacé dans `/home/trash/` (archivage — étape 1), And l'utilisateur ne peut plus se connecter, And l'action est loggée (NFR8), And un toast de succès est affiché (ToastMagic).

2. **Réactivation d'un compte** — Given je réactive un compte désactivé, When je confirme l'action, Then le compte est réactivé dans l'AD local (`useraccountcontrol` → 512), puis dans PostgreSQL (`is_active` → true), And si le home directory existe dans `/home/trash/`, il est restauré vers `/home/{login}`, And l'action est loggée (NFR8), And un toast de succès est affiché.

3. **Suppression permanente** — Given un home directory est dans `/home/trash/`, When je déclenche la suppression permanente, Then le home directory est supprimé définitivement du système de fichiers, And le compte est supprimé de l'AD local en premier, puis de PostgreSQL, And l'action est loggée avec horodatage (NFR8 — conformité RGPD), And un toast de succès est affiché.

4. **Suppression en deux temps obligatoire** — Given je tente de supprimer définitivement sans passer par la désactivation (archivage), When je valide, Then l'action est refusée — la suppression en deux temps est obligatoire.

5. **Permissions** — Given je n'ai pas les droits `delete-user`, When je tente de désactiver ou supprimer un utilisateur, Then l'action est refusée avec un message d'erreur.

6. **Double-write** — Given une modification LDAP réussit (disable/enable/delete), Then l'état correspondant est synchronisé dans PostgreSQL. En cas d'échec SQL, logger mais ne pas annuler (AD = source de vérité au MVP).

## Tâches / Sous-tâches

### Phase A — Service `disableUser()` / `enableUser()`

- [x] **Tâche 1 : Implémenter `UserService::disableUser(string $login): array`** (AC: 1, 5, 6)
  - [x] Vérifier Gate `delete-user` avant exécution
  - [x] Charger l'utilisateur LDAP via `UserRepository::findLdapModelByLogin($login)`
  - [x] Modifier `useraccountcontrol` → 514 via `$ldapUser->save()`
  - [x] Invalider le cache : `$this->userRepository->invalidateCache($login)`
  - [x] Double-write SQL : `SqlUserModel::where('login', $login)->update(['is_active' => false])`
  - [x] Déplacer le home directory : `/home/{login}` → `/home/trash/{login}` via commande sudo (voir Tâche 3)
  - [x] Logger : `Log::info('User disabled', ['login' => $login])`
  - [x] Retourner `['success' => true, 'message' => 'Compte désactivé.']` ou erreur

- [x] **Tâche 2 : Implémenter `UserService::enableUser(string $login): array`** (AC: 2, 5, 6)
  - [x] Vérifier Gate `delete-user` avant exécution
  - [x] Charger l'utilisateur LDAP
  - [x] Modifier `useraccountcontrol` → 512 via `$ldapUser->save()`
  - [x] Invalider le cache
  - [x] Double-write SQL : `SqlUserModel::where('login', $login)->update(['is_active' => true])`
  - [x] Si `/home/trash/{login}` existe → restaurer vers `/home/{login}` via commande sudo (voir Tâche 3)
  - [x] Logger : `Log::info('User enabled', ['login' => $login])`
  - [x] Retourner résultat

### Phase B — Gestion du home directory (archivage / restauration / suppression)

- [x] **Tâche 3 : Méthodes filesystem dans `UserService`** (AC: 1, 2, 3)
  - [x] `archiveHomeDirectory(string $login): bool` — `sudo mv /home/{login} /home/trash/{login}`
    - Vérifier que `/home/{login}` existe avant de déplacer
    - Vérifier/créer `/home/trash/` si inexistant
    - Utiliser `exec()` avec sudo (même pattern que `createHomeDirectory()`)
  - [x] `restoreHomeDirectory(string $login): bool` — `sudo mv /home/trash/{login} /home/{login}`
    - Vérifier que `/home/trash/{login}` existe avant restauration
  - [x] `deleteHomeDirectoryPermanently(string $login): bool` — `sudo rm -rf /home/trash/{login}`
    - UNIQUEMENT depuis `/home/trash/` — ne jamais supprimer `/home/{login}` directement
  - [x] `hasArchivedHome(string $login): bool` — vérifier si `/home/trash/{login}` existe

### Phase C — Service `deleteUserPermanently()`

- [x] **Tâche 4 : Implémenter `UserService::deleteUserPermanently(string $login): array`** (AC: 3, 4, 5, 6)
  - [x] Vérifier Gate `delete-user`
  - [x] Vérifier que le compte est désactivé (AC:4 — suppression en deux temps obligatoire) :
    - Charger l'utilisateur LDAP, vérifier `useraccountcontrol == 514`
    - Si actif → refuser avec message "Vous devez d'abord désactiver le compte"
  - [x] Supprimer le home archivé : `deleteHomeDirectoryPermanently($login)`
  - [x] Supprimer de l'AD : `$ldapUser->delete()` (LdapRecord gère `ldap_delete`)
  - [x] Supprimer de PostgreSQL : `SqlUserModel::where('login', $login)->delete()`
  - [x] Supprimer du cache
  - [x] Logger : `Log::info('User permanently deleted', ['login' => $login, 'timestamp' => now()])`

### Phase D — Branchement UI (fiche utilisateur)

- [x] **Tâche 5 : Remplacer les liens legacy par des actions Livewire** (AC: 1, 2, 5)
  - [x] Dans `resources/views/pages/users/[login]/index.blade.php` :
    - Remplacer le lien legacy `<a href="/annu/desac_user_entry.php?cn=...">` par un `wire:click="disableUser"` avec `wire:confirm`
    - Remplacer le lien legacy réactivation par `wire:click="enableUser"` avec `wire:confirm`
    - Ajouter un bouton "Supprimer définitivement" (visible seulement si `$accountDisabled`) avec `wire:click="deleteUserPermanently"` et double confirmation
  - [x] Implémenter les méthodes Livewire dans le composant `[login]/index.blade.php` :
    - `disableUser()` → appelle `$this->userService->disableUser($this->login)` → ToastMagic → refresh
    - `enableUser()` → appelle `$this->userService->enableUser($this->login)` → ToastMagic → refresh
    - `deleteUserPermanently()` → appelle `$this->userService->deleteUserPermanently($this->login)` → ToastMagic → redirect vers `/users`
  - [x] Protéger les actions avec `@can('delete-user')` dans le template Blade

- [ ] **Tâche 6 : Actions batch dans la liste utilisateurs (optionnel — hors scope)** (AC: 1, 2)
  - [ ] Dans `resources/views/pages/users/_partials/dropdownUserActions.blade.php` :
    - Brancher `disableUsers()` et `enableUsers()` qui appellent le service en boucle sur la sélection
    - Brancher `deleteSelected()` avec vérification que tous sont déjà désactivés

### Phase E — Tests

- [x] **Tâche 7 : Tests unitaires** (AC: 1-6)
  - [x] Test `UserService::disableUser()` : UAC → 514, permissions, user not found, log
  - [x] Test `UserService::enableUser()` : UAC → 512, permissions, user not found, log
  - [x] Test `UserService::deleteUserPermanently()` : refus si actif, succès si désactivé, log RGPD avec timestamp
  - [x] Test permissions : refus si pas de Gate `delete-user` (3 tests)
  - [x] Test suppression en deux temps : deleteUserPermanently refuse si useraccountcontrol ≠ 514

- [ ] **Tâche 8 : Tests E2E** (AC: 1, 2, 3, 4, 5) — reporté : nécessite infrastructure E2E
  - [ ] Désactiver un utilisateur → vérifier toast succès + compte désactivé (is_active false)
  - [ ] Réactiver un utilisateur → vérifier toast succès + compte réactivé
  - [ ] Tenter suppression permanente d'un utilisateur actif → vérifier refus
  - [ ] Supprimer un utilisateur désactivé → vérifier suppression SQL
  - [ ] Tenter action sans droits → vérifier refus

## Dev Notes

### Ce qui EXISTE déjà — ne pas recréer

| Composant | Chemin | État |
|---|---|---|
| Page fiche utilisateur | `resources/views/pages/users/[login]/index.blade.php` | Complet — dropdown actions avec liens legacy à remplacer |
| Dropdown actions batch | `resources/views/pages/users/_partials/dropdownUserActions.blade.php` | UI présente, fonctions JS non implémentées |
| UserService | `app/Services/UserService.php` | Méthodes create, search, getByLogin, updatePersonalInfo, resetPassword, **createHomeDirectory()** — pas de delete/disable |
| UserRepository | `app/Repositories/UserRepository.php` | `findLdapModelByLogin()`, `invalidateCache()`, **`applyStatusFilters()`** avec filtres active/inactive/trash |
| LdapUser model | `app/LdapModels/LdapUser.php` | `checkIsInTrash()`, `toBusinessObject()` avec `isActive`/`isTrash`/`isActiveUser` |
| User DTO | `app/Types/User.php` | Constantes `UAC_ACTIVE = 512`, `UAC_DISABLED = 514`, méthodes `isActiveAccount()`, `isDisabled()` |
| SqlUserModel | `app/Models/User.php` | Champ `is_active` (boolean), scope `active()` |
| LdapDnHelper | `app/Config/LdapDnHelper.php` | `trashDn()` — DN de la corbeille LDAP |
| LdapConfig | `app/Config/LdapConfig.php` | `trashRdn` configuré (ex: `ou=Trash`) |
| Routes commentées | `routes/web.php:62-68` | `bulk-enable`, `bulk-disable`, `bulk-reset-password` en commentaire — prêtes à décommenter |
| Liste utilisateurs | `resources/views/pages/users/index.blade.php` | Filtres status active/inactive/trash déjà en place (lignes 232+) |
| Doc legacy update | `docs/USER_UPDATE.md` | Analyse du flux legacy pour référence |

### Mécanisme de désactivation dans l'AD

L'AD Windows utilise l'attribut `useraccountcontrol` :
- **512** = compte actif (normal)
- **514** = compte désactivé

LdapRecord permet de modifier cet attribut directement : `$ldapUser->useraccountcontrol = 514; $ldapUser->save();`

### Corbeille LDAP vs corbeille filesystem

**Deux corbeilles distinctes :**
1. **Corbeille LDAP** (`ou=Trash`) — déplacement du DN de l'utilisateur dans l'OU Trash de l'AD. Utilisée par le legacy. `LdapDnHelper::trashDn()` retourne le DN.
2. **Corbeille filesystem** (`/home/trash/`) — archivage du home directory. Mentionnée dans le PRD (FR4, FR16).

Pour cette story, la désactivation utilise `useraccountcontrol=514` (pas de déplacement DN). Le déplacement DN dans la corbeille LDAP est un pattern legacy qu'on peut simplifier. Le home directory est archivé dans `/home/trash/`.

### Pattern double-write (établi par Stories 2.1 et 2.2)

```
1. Modifier dans l'AD via LdapRecord ($ldapUser->save() ou $ldapUser->delete())
2. Si succès AD → mettre à jour PostgreSQL
3. Si échec SQL → Log::error() mais ne pas throw (AD = source de vérité MVP)
4. Invalider le cache utilisateur (UserRepository::invalidateCache)
```

### Pattern filesystem (établi par Story 2.1)

`UserService::createHomeDirectory()` utilise `Process::run()` avec `sudo` pour les opérations système. Réutiliser ce même pattern pour `mv` et `rm -rf` dans `/home/trash/`.

### Pattern de notification

La page fiche utilisateur utilise **ToastMagic** (pas WithToasts) — cohérence établie par Story 2.2.

### UI — ce qu'il faut modifier

Le dropdown actions dans `[login]/index.blade.php` (lignes 256-286) contient des liens `<a href="/annu/desac_user_entry.php?...">` vers le legacy. Remplacer par des `wire:click` Livewire qui appellent les méthodes du service. Le bouton "Supprimer définitivement" n'existe pas encore — l'ajouter, visible seulement quand le compte est désactivé (`$accountDisabled`).

### Ce qui est HORS SCOPE

- **Actions batch** — les boutons de la liste utilisateurs (`dropdownUserActions.blade.php`) sont un bonus optionnel, pas une obligation de cette story. Le focus est la fiche individuelle.
- **Déplacement DN dans la corbeille LDAP** — le legacy déplaçait le DN dans `ou=Trash`. On simplifie : on désactive via `useraccountcontrol=514` sans déplacer le DN. Le déplacement DN est un pattern legacy qui complique la réactivation (il faut recalculer le DN d'origine).
- **Nettoyage des groupes AD** — le retrait des groupes AD à la désactivation pourrait être ajouté dans une story suivante si nécessaire.
- **Quota XFS** — pas de modification du quota lors de la désactivation/suppression.
- **Purge automatique /home/trash/** — pas de cron de nettoyage automatique dans cette story.

### Dépendances

- **Story 2.1 (Création)** : done — patterns double-write, UserService, `createHomeDirectory()` (pattern sudo)
- **Story 2.2 (Modification)** : review — patterns `updatePersonalInfo()`, ToastMagic, reload données post-action
- **Story 2.5 (Changement rôle/DN)** : ready-for-dev — orthogonal, gère le déplacement DN pas la désactivation/suppression

### Git Intelligence (derniers commits)

Les 10 derniers commits montrent :
- Story 2.2 double-write AD→SQL (personal info update)
- Fix GroupRepository DN construction (LdapDnHelper)
- Fix update.sh et Apache vhost
- Story shim LDAP (bootstrap legacy)

Pattern confirmé : Livewire SFC inline, Services pour la logique métier, ToastMagic pour les notifications, double-write AD first → SQL second, `Process::run()` pour les commandes sudo.

### Project Structure Notes

- Convention routing : `pages/users/[login]/index.blade.php` = fiche utilisateur — c'est là qu'il faut ajouter les actions
- Les actions Livewire sont inline dans le composant SFC — pas de fichier PHP séparé
- Les partials `_partials/` sont pour les sous-composants (personal-info-form, quota-info, groups-drawer)
- La page liste (`pages/users/index.blade.php`) a déjà les filtres active/inactive/trash

### References

- [Source: epics.md#Story 2.3] — AC BDD et prérequis
- [Source: prd.md#FR4] — Désactiver et supprimer un compte utilisateur avec archivage home en 2 temps
- [Source: prd.md#FR16] — Suppression home directories en deux étapes (archivage → suppression permanente)
- [Source: prd.md#RGPD] — Conformité suppression effective des données personnelles
- [Source: prd.md#NFR8] — Logs d'actions sensibles horodatés
- [Source: architecture.md#Data Architecture] — PostgreSQL source de vérité, double-write Users
- [Source: architecture.md#Couche Services] — Livewire → Services → Eloquent/LdapRecord
- [Source: story 2-1] — Patterns double-write, createHomeDirectory(), Process::run() avec sudo
- [Source: story 2-2] — Patterns updatePersonalInfo(), ToastMagic, reload post-action

## Dev Agent Record

### Agent Model Used
Claude Opus 4.6 (1M context)

### Debug Log References
- Tests unitaires : 12 passed (23 assertions) en 0.31s

### Completion Notes List
- Implémenté `disableUser()`, `enableUser()`, `deleteUserPermanently()` dans UserService avec pattern double-write AD→SQL
- Implémenté 4 méthodes filesystem : `archiveHomeDirectory`, `restoreHomeDirectory`, `deleteHomeDirectoryPermanently`, `hasArchivedHome`
- Remplacé les liens legacy `<a href="/annu/desac_user_entry.php">` par des `wire:click` Livewire avec `wire:confirm`
- Ajouté bouton "Supprimer définitivement" (visible uniquement si compte désactivé) avec confirmation renforcée
- Protégé les actions UI avec `@can('delete-user')`
- 12 tests unitaires couvrant : permissions (3), UAC disable/enable (2), user not found (2), logs (3), suppression en deux temps (1), suppression réussie (1)
- Tâche 6 (batch actions) laissée hors scope conformément aux Dev Notes
- Tâche 8 (E2E) reportée : nécessite infrastructure E2E non disponible

### Change Log
- 2026-03-26 : Implémentation complète Story 2.3 — disable/enable/delete user avec double-write, filesystem archival, UI Livewire, 12 tests unitaires

### File List
- `app/Services/UserService.php` — ajouté disableUser(), enableUser(), deleteUserPermanently(), archiveHomeDirectory(), restoreHomeDirectory(), deleteHomeDirectoryPermanently(), hasArchivedHome()
- `resources/views/pages/users/[login]/index.blade.php` — remplacé liens legacy par wire:click, ajouté méthodes Livewire, ajouté bouton suppression permanente
- `tests/Unit/Services/UserServiceDisableDeleteTest.php` — nouveau fichier, 12 tests unitaires
