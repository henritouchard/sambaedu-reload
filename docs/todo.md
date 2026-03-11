# User CRUD
- [ ] Création d'un utilisateur → `docs/CREATE_USER.md`
- [ ] Suppression d'un utilisateur
- [ ] Édition d'un utilisateur → `docs/USER_UPDATE.md`
  - [ ] `UserService::updatePersonalInfo()` (prenom, nom, email, phone, description)
  - [ ] `UserService::updateIdentifiers()` (idEnt, idAaf, idSiecle, idGpei, idNc)
  - [ ] `UserService::updateBirthdate()`
  - [ ] `UserService::changeLogin()` (+ déplacement home/profiles)
  - [ ] Composant Livewire `personal-info-form.blade.php` ✅ créé (save() à implémenter)

# Assignation de groupes
- [ ] Ajout à un groupe
- [ ] Retrait d'un groupe

# Assignation de droits
- [ ] Ajout d'un droit
- [ ] Retrait d'un droit

---

# Migration LdapRecord → `docs/LDAPRECORD_MIGRATION.md`

## Phase 3 : UserService (partielle)
- [ ] `getEtablissements()` → LdapRecord
- [ ] `getFonctions()` → LdapRecord
- [ ] `getClasses()` → LdapRecord

## Phase 4 : ParcService (partielle)
- [ ] `createParc()` → LdapRecord
- [ ] `deleteParc()` → LdapRecord

## Phase 5 : MachineService
- [ ] `getMachinesByParc()` → `MachineModel`
- [ ] `getMachineStatus()`
- [ ] `machineExists()`
- [ ] Actions : `wakeOnLan`, `shutdown`, `restart`

## Phase 6 : AuthenticationService (partielle)
- [ ] `searchMachine()`
- [ ] `getMachineStatus()`

## Phase 7 : Middlewares et Controllers
- [ ] Migration du middleware `SambaEduAuth`
- [ ] Migration des Controllers API
- [ ] Migration des Controllers Admin

## Phase 8 : Opérations d'écriture
- [ ] Créations : parcs, machines
- [ ] Mises à jour : utilisateurs, parcs, machines
- [ ] Suppressions
- [ ] Gestion des membres de groupes (`attach`/`detach`)

## Phase 9 : Optimisation
- [ ] Optimisation des requêtes N+1
- [ ] Cache Laravel pour LdapRecord
- [ ] Suppression du code legacy inutilisé

## Phase 10 : Tests et déploiement
- [ ] Tests de régression complets
- [ ] Tests de performance
- [ ] Déploiement progressif