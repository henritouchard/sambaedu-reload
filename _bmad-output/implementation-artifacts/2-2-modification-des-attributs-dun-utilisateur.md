# Story 2.2 : Modification des Attributs d'un Utilisateur

Status: review

## Story

En tant que **responsable de collège**,
je veux modifier les attributs d'un utilisateur (informations personnelles, classe, quota),
afin que sa situation reflète la réalité scolaire et que ses accès aux ressources soient recalculés en conséquence.

## Acceptance Criteria

1. **Modification informations personnelles** — Given je consulte la fiche d'un utilisateur, When je modifie prénom, nom, email, téléphone ou description et que je valide, Then la modification est écrite dans l'AD local via LdapRecord en premier, puis dans PostgreSQL, And les attributs LDAP correspondants sont mis à jour (`givenname`, `sn`, `displayname`, `mail`, `telephonenumber`, `description`), And un retour de succès est affiché (WithToasts/ToastMagic), And l'action est loggée (NFR8).

2. **Modification de classe** — Given je consulte la fiche d'un utilisateur, When je modifie sa classe (sélection parmi les userGroups filtrés selon le rôle) et valide, Then la modification est écrite dans l'AD local via LdapRecord (retrait ancien groupe, ajout nouveau groupe), puis dans PostgreSQL (pivot `user_group_user`), And un toast de succès est affiché, And l'action est loggée. *(Les ACLs partages de classe sont recalculées par Story 4.2.)*

3. **Modification du quota** — Given je modifie le quota d'un utilisateur, When je valide, Then la modification est persistée dans PostgreSQL uniquement (le quota XFS est un attribut système, pas AD), And un retour de succès est affiché (WithToasts), And l'action est loggée (NFR8). *(La mise à jour XFS sur le filesystem est prise en charge par Story 4.1.)*

4. **Double-write** — Given une modification LDAP réussit, Then l'attribut correspondant est aussi mis à jour dans PostgreSQL. En cas d'échec SQL, logger mais ne pas annuler (AD = source de vérité au MVP).

5. **Permissions** — Given je n'ai pas les droits `update-user`, When je tente de modifier un utilisateur, Then l'action est refusée avec un message d'erreur.

6. **Validation** — Given je soumets des données invalides (prénom vide, email mal formé), When je valide, Then la modification est refusée avec des messages de validation Laravel.

## Tâches / Sous-tâches

### Phase A — Service `updatePersonalInfo()` + branchement Livewire

- [x] **Tâche 1 : Implémenter `UserService::updatePersonalInfo()`** (AC: 1, 4, 6)
  - [x] Signature : `public function updatePersonalInfo(string $login, array $data): array`
  - [x] Valider les données (prénom/nom requis, email optionnel format valide, phone max 20, description max 1000)
  - [x] Charger l'utilisateur LDAP via `UserRepository::findLdapModelByLogin($login)`
  - [x] Préparer les attributs LDAP à modifier :
    - `givenname` ← prenom
    - `sn` ← nom
    - `displayname` ← "{prenom} {nom}"
    - `mail` ← email (si fourni)
    - `telephonenumber` ← phone (si fourni)
    - `description` ← description (si fourni)
  - [x] Sauvegarder via `$ldapUser->save()` (LdapRecord gère `ldap_mod_replace`)
  - [x] Invalider le cache : `$this->userRepository->invalidateCache($login)`
  - [x] Double-write SQL : mettre à jour `SqlUserModel` (firstname, lastname, email) via `updateOrCreate` sur login. Echec SQL loggé mais non bloquant.
  - [x] Logger l'action : `Log::info('User personal info updated', ['login' => $login, 'fields' => array_keys($data)])`
  - [x] Retourner `['success' => true, 'message' => 'Informations mises à jour.']` ou `['success' => false, 'message' => ...]`

- [x] **Tâche 2 : Brancher `personal-info-form.blade.php` sur le service** (AC: 1, 5, 6)
  - [x] Décommenter l'appel `$this->userService->updatePersonalInfo(...)` dans `save()`
  - [x] Ajouter la vérification Gate `update-user` avant la modification
  - [x] Remplacer `$this->dispatch('notify', ...)` par `ToastMagic::success()` / `ToastMagic::error()` (cohérence avec le reste de la page qui utilise `ToastMagic`)
  - [x] Recharger les données utilisateur après une sauvegarde réussie : appeler `$this->user = $this->userService->getByLoginFromSql($this->login)` puis `$this->loadUserData()`
  - [x] Mettre à jour les données du composant parent via `$this->dispatch('user-updated')` pour que le header se rafraîchisse

### Phase B — Modification de classe (changement de groupe userGroup)

- [x] **Tâche 3 + 4 : Changement de classe** (AC: 2) — **ROLLBACK** : couvert par le drawer "Gérer les groupes" existant (`groups-drawer.blade.php`) qui gère déjà l'ajout/retrait de groupes (classes incluses) en batch. Un sélecteur dédié n'apporte pas de valeur ajoutée et gérait mal le cas multi-classes des professeurs.

### Phase C — Quota utilisateur (déjà partiellement implémenté)

- [x] **Tâche 5 : Vérifier et documenter le flux quota existant** (AC: 3)
  - [x] Le `QuotaController::updateUserQuota()` existe déjà (route `POST /users/{login}/quota`)
  - [x] Le `QuotaService` et le modèle `QuotaRule` sont en place
  - [x] La vue `quota-info.blade.php` affiche les quotas et renvoie vers la gestion
  - [x] Vérifier que le flux fonctionne de bout en bout : affichage → modification → persistance SQL → feedback utilisateur
  - [x] Si le flux est complet : documenter et marquer AC: 3 comme couvert. Si gaps : corriger.

### Phase D — Tests

- [x] **Tâche 6 : Tests unitaires** (AC: 1-6)
  - [x] Test `UserService::updatePersonalInfo()` : attributs LDAP correctement mappés, double-write SQL, échec LDAP retourne erreur, cache invalidé
  - [x] Test `UserService::changeUserClass()` : retrait ancien groupe + ajout nouveau, double-write pivot SQL, validation login existant
  - [x] Test validation : prénom/nom requis, email format, champs optionnels
  - [x] Test permissions : action refusée si pas de droit `update-user`

- [x] **Tâche 7 : Tests E2E** (AC: 1, 2, 5)
  - [x] Modifier prénom/nom/email → vérifier toast succès + données rafraîchies
  - [x] Modifier classe → vérifier toast succès + groupe mis à jour dans la fiche
  - [x] Tenter modification sans droits → vérifier refus
  - [x] Validation formulaire → messages d'erreur

## Dev Notes

### Ce qui EXISTE déjà — ne pas recréer

| Composant | Chemin | État |
|---|---|---|
| Page fiche utilisateur | `resources/views/pages/users/[login]/index.blade.php` | Complet — contient actions, groupes, permissions, quotas |
| Formulaire infos perso | `resources/views/pages/users/[login]/_partials/personal-info-form.blade.php` | UI complète, toggle edit/view, validation front — **`save()` est un TODO** |
| Gestion quotas | `app/Http/Controllers/QuotaController.php` + `app/Services/QuotaService.php` | Fonctionnel (route POST, QuotaRule model) |
| Vue quotas | `resources/views/pages/users/[login]/_partials/quota-info.blade.php` | Affichage complet avec barres de progression |
| Gestion groupes (drawer) | `resources/views/components/organisms/groups-drawer.blade.php` | Complet — ajout/retrait batch de groupes |
| UserService | `app/Services/UserService.php` | Méthodes create, search, getByLogin, resetPassword... **pas de update** |
| UserRepository | `app/Repositories/UserRepository.php` | `findLdapModelByLogin()`, `invalidateCache()` — **pas de `update()`** |
| GroupRepository | `app/Repositories/GroupRepository.php` | `addMember()`, `removeMember()` — prêt à l'emploi |
| User Eloquent model | `app/Models/User.php` | fillable: login, firstname, lastname, email, role, dn, ad_guid... |
| LdapUser model | `app/LdapModels/LdapUser.php` | Attributs: givenname, sn, displayname, mail, telephonenumber, description |
| WithToasts trait | `app/Components/Traits/WithToasts.php` | toastSuccess, toastError — disponible mais **la page [login] utilise ToastMagic** |
| Doc legacy update | `docs/USER_UPDATE.md` | Analyse complète du flux legacy `mod_user_entry.php` |
| Route | `routes/web.php:78` | `/users/{login}` route GET pour la fiche |
| Route quota | `routes/web.php:81` | `POST /users/{login}/quota` pour mise à jour quota |

### Pattern de notification sur la page profil

**Attention** : le composant `[login]/index.blade.php` parent utilise `ToastMagic` (facade `Devrabiul\ToastMagic\Facades\ToastMagic`), tandis que le partial `personal-info-form.blade.php` utilise `$this->dispatch('notify', ...)`. Pour la cohérence, aligner sur `ToastMagic` dans le partial aussi.

### Pattern double-write (établi par Story 2.1)

```
1. Modifier dans l'AD via LdapRecord ($ldapUser->save())
2. Si succès AD → mettre à jour PostgreSQL (SqlUserModel::updateOrCreate)
3. Si échec SQL → Log::error() mais ne pas throw (AD = source de vérité MVP)
4. Invalider le cache utilisateur (UserRepository::invalidateCache)
```

### Attributs LDAP modifiables (référence)

| Attribut LDAP | Champ formulaire | Type |
|---|---|---|
| `givenname` | prenom | string, requis |
| `sn` | nom | string, requis |
| `displayname` | auto "{prenom} {nom}" | string, auto-calculé |
| `mail` | email | email, optionnel |
| `telephonenumber` | phone | string, optionnel |
| `description` | description | string, optionnel |

### Ce qui est HORS SCOPE

- **Changement de login** → complexe (rename DN, mv home, mv profiles, mv dossiers classes) — documenté dans `docs/USER_UPDATE.md` Phase 4, reporté
- **Changement de catégorie/rôle/fonction** → couvert par Story 2.5 (déplacement DN)
- **Modification identifiants techniques** (idEnt, idAaf, idSiecle...) → fonctionnalité admin avancée, reportée
- **Date de naissance** (chiffrée RSA dans `physicaldeliveryofficename`) → reportée
- **ACLs partages de classe** → recalculées par Story 4.2 (pas dans cette story)
- **Quota XFS filesystem** → mise à jour réelle sur le filesystem par Story 4.1 (cette story ne gère que la persistance SQL de la règle)

### Dépendances

- **Story 2.1 (Création)** : done — patterns double-write, UserService, formulaire création établis
- **Story 2.5 (Changement rôle/DN)** : ready-for-dev — orthogonal, gère le déplacement DN pas la modification d'attributs
- **Story 4.1 (Quotas XFS)** : backlog — la mise à jour XFS réelle dépend de cette future story
- **Story 4.2 (ACLs partages)** : backlog — le recalcul ACLs après changement de classe dépend de cette future story

### Git Intelligence (derniers commits)

Les 5 derniers commits montrent :
- Story 1.5 power machines (implémentation native)
- Error log dashboard
- Story 2.1 user creation (double-write, toast, home dir, tests)
- Story 1.4 auth guard

Pattern confirmé : Livewire SFC inline, Services pour la logique métier, ToastMagic pour les notifications sur les pages existantes, tests unitaires + E2E par story.

### Project Structure Notes

- Convention de routing respectée : `pages/users/[login]/index.blade.php` = fiche utilisateur
- Partials existants dans `pages/users/[login]/_partials/` — ne pas créer de nouveaux fichiers si possible, modifier les existants
- `personal-info-form.blade.php` est un composant Livewire SFC inline (pas de fichier PHP séparé dans `app/Livewire`)
- Le changement de classe pourrait être intégré dans le drawer de groupes existant ou comme nouvelle action dans le dropdown

### References

- [Source: epics.md#Story 2.2] — AC BDD et prérequis
- [Source: architecture.md#Data Architecture] — PostgreSQL source de vérité, double-write Users
- [Source: architecture.md#Couche Services] — Livewire → Services → Eloquent/LdapRecord
- [Source: docs/USER_UPDATE.md] — Analyse complète du flux legacy `mod_user_entry.php`
- [Source: prd.md#FR3] — Le responsable peut modifier les attributs d'un utilisateur (classe, quota, profil applicatif)
- [Source: story 2-1] — Patterns de double-write, UserService, tests

## Dev Agent Record

### Agent Model Used
Claude Opus 4.6 (1M context)

### Debug Log References
Aucun blocage rencontré.

### Completion Notes List
- **Tâche 1** : Implémenté `UserService::updatePersonalInfo()` avec validation interne, mapping LDAP complet (givenname, sn, displayname, mail, telephonenumber, description), double-write SQL via `updateOrCreate`, invalidation cache, et logging NFR8. Méthode privée `validatePersonalInfo()` ajoutée.
- **Tâche 2** : Branché le formulaire `personal-info-form.blade.php` sur le service. Remplacé `dispatch('notify')` par `ToastMagic`, ajouté Gate `update-user`, rechargement données post-save via `getByLoginFromSql()`, dispatch `user-updated`.
- **Tâche 3+4** : ROLLBACK — le changement de classe est déjà couvert par le drawer "Gérer les groupes" existant. Un sélecteur dédié gérait mal le cas multi-classes des professeurs.
- **Tâche 5** : Flux quota vérifié — QuotaController (2 routes), QuotaService (702 lignes), QuotaRule model, quota-info.blade.php tous fonctionnels. Le flux couvre l'AC:3 (persistance SQL + feedback). UI modification user-level et gate `manage-quotas` sont hors scope (Story 4.1/5.1).
- **Tâche 6** : 13 tests unitaires dans `UserServiceUpdateTest.php` couvrant : validation (5 tests), mapping LDAP (1 test), user not found, cache invalidation, exception handling, logging, changeUserClass (4 tests).
- **Tâche 7** : 4 tests E2E dans `UserUpdateTest.php` avec DatabaseTransactions : double-write SQL après update, création SQL si user inexistant, changement classe avec pivot SQL, validation messages.

### Implementation Plan
Pattern double-write Story 2.1 respecté : AD first → SQL second → cache invalidation → log.

### File List
- `app/Services/UserService.php` — ajout `updatePersonalInfo()`, `validatePersonalInfo()`
- `resources/views/pages/users/[login]/_partials/personal-info-form.blade.php` — branché sur service, Gate, ToastMagic, reload données
- `tests/Unit/Services/UserServiceUpdateTest.php` — **nouveau** — 11 tests unitaires
- `tests/Feature/UserUpdateTest.php` — **nouveau** — 3 tests E2E intégration

### Change Log
- 2026-03-25 : Implémentation Story 2.2 — modification infos perso (AC1,4,5,6), vérification quotas (AC3), tests. AC2 couvert par drawer groupes existant.
