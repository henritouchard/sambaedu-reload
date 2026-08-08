# Migration vers LdapRecord

## Vue d'ensemble

Ce document détaille la migration de l'application SambaEdu des fonctions PHP LDAP natives vers **LdapRecord**. Il combine l'analyse de faisabilité et le plan de développement étape par étape.

---

## 📊 État d'avancement global

**Phase actuelle** : Phase 4 - Migration du ParcService  
**Progression globale** : ~35%

| Phase | Statut | Description |
|-------|--------|-------------|
| Phase 0 | ✅ Complétée | Infrastructure et configuration |
| Phase 1 | ✅ Complétée | Création des modèles LdapRecord |
| Phase 2 | ⏸️ Abandonnée | LdapService (remplacé par repositories) |
| Phase 3 | ✅ Partielle | UserService (searchUsers, getUser, createUser) |
| Phase 4 | ✅ Partielle | ParcService (getAllParcs, getParcById, stats) |
| Phase 5 | ⏳ À faire | MachineService |
| Phase 6 | ✅ Partielle | AuthenticationService |
| Phase 7-10 | ⏳ À faire | Middlewares, écritures, optimisation |

**Dernière mise à jour** : 2025-12-12

**Tests** : 36 tests LDAP passent avec succès (66 assertions)

---

## Architecture actuelle

### Avant migration (legacy)

```
┌─────────────────────────────────────────┐
│  Code Legacy                            │
│  ├── ldap.inc.php (~5000 lignes)        │
│  ├── config.inc.php                     │
│  ├── ent.inc.php                        │
│  └── samba-tool.inc.php                 │
└─────────────────────────────────────────┘
```

### Après migration (LdapRecord)

```
┌─────────────────────────────────────────┐
│  Laravel + LdapRecord                   │
│  ├── LdapModels/                        │
│  │   ├── LdapUser.php           ✅      │
│  │   ├── SambaEduGroup.php      ✅      │
│  │   ├── DeviceGroupModel.php   ✅      │
│  │   ├── DeviceGroupTagModel.php ✅     │
│  │   └── MachineModel.php       ✅      │
│  ├── Repositories/                      │
│  │   ├── UserRepository.php     ✅      │
│  │   ├── ParcRepository.php     ✅      │
│  │   └── MachineRepository.php  ✅      │
│  ├── Services/                          │
│  │   ├── UserService.php        ✅      │
│  │   ├── ParcService.php        ✅      │
│  │   └── AuthenticationService  ✅      │
│  └── Types/ (DTOs)                      │
│      ├── User.php               ✅      │
│      ├── Parc.php               ✅      │
│      └── Machine.php            ✅      │
└─────────────────────────────────────────┘
```

## Qu'est-ce que LdapRecord ?

**LdapRecord** est une bibliothèque PHP moderne pour interagir avec Active Directory et LDAP, créée par Steve Bauman.

### Avantages principaux

✅ **Interface orientée objet** - Modèles Eloquent-like pour LDAP  
✅ **Query Builder** - Construction de requêtes fluide et lisible  
✅ **Gestion automatique** - Connexion, pagination, échappement  
✅ **Laravel intégration** - Package dédié `ldaprecord/laravel`  
✅ **Active Directory** - Support natif des spécificités AD  
✅ **Authentification** - Système d'auth intégré pour Laravel  
✅ **Maintenance active** - Bien maintenu et documenté  
✅ **Type hints** - Support complet de PHP 8.1+  

### Fonctionnalités clés

- **Modèles LDAP** : Classes qui représentent des objets LDAP (User, Group, OrganizationalUnit, etc.)
- **Query Builder** : `User::where('cn', 'john')->first()`
- **Relations** : `$user->groups()` pour récupérer les groupes d'un utilisateur
- **Events** : Hooks sur les opérations LDAP
- **Multi-connexions** : Gestion de plusieurs serveurs LDAP
- **Cache** : Support du cache Laravel
- **Validation** : Validation des données avant écriture

---

## PHASE 0 : Infrastructure ✅ COMPLÉTÉE

### 0.1 Installation et configuration
- ✅ Packages installés : `directorytree/ldaprecord` v3.8.5, `directorytree/ldaprecord-laravel` v3.4.2
- ✅ Configuration dans `config/ldap.php`
- ✅ Variables d'environnement depuis `/etc/sambaedu/sambaedu.conf`
- ✅ Service Provider `LdapRecordServiceProvider` créé
- ✅ Connexion testée : `php artisan ldap:test-connection`

### 0.2 Constantes LDAP
- ✅ `LdapFilter.php` : Filtres LDAP (user, group, machine, salle, parc, etc.)
- ✅ `LdapAttributes.php` : Attributs par type d'objet
- ✅ `LdapScope.php` : Scopes (SUBTREE, BASE, ONELEVEL)
- ✅ Documentation dans `app/Constants/Ldap/README.md`

### 0.3 Tests de connexion
- ✅ 15 tests passent (45 assertions)
- ✅ Connexion validée avec le même serveur que le legacy

---

## PHASE 1 : Modèles LdapRecord ✅ COMPLÉTÉE

### 1.1 LdapUser (`app/LdapModels/LdapUser.php`)
- ✅ Étend `LdapRecord\Models\ActiveDirectory\User`
- ✅ Attributs : cn, displayname, sn, givenname, mail, memberof, useraccountcontrol, etc.
- ✅ Méthodes : `getEtablissement()`, `isAdmin()`, `isEleve()`, `isProf()`, `isActive()`
- ✅ Recherche : `findByLogin()`, `findByEmployeeNumber()`
- ✅ Normalisation des valeurs LDAP (tableaux → strings)

### 1.2 SambaEduGroup (`app/LdapModels/SambaEduGroup.php`)
- ✅ Groupes principaux : Eleves, Profs, Administratifs
- ✅ Méthodes : `findMainGroup()`, `findByCn()`, `isMainGroup()`
- ✅ Constantes dans `MainGroups.php`

### 1.3 DeviceGroupModel (Salles - OU)
- ✅ Étend `OrganizationalUnit`
- ✅ Attributs : cn, ou, description, objectguid
- ✅ Relation : `associatedGroup()`

### 1.4 DeviceGroupTagModel (Parcs - Group)
- ✅ Étend `Group`
- ✅ Attributs : cn, description, member, samaccountname
- ✅ Relation : `machines()`

### 1.5 MachineModel (Ordinateurs)
- ✅ Étend `Computer`
- ✅ Attributs : cn, samaccountname, dnsHostname, iphostnumber, etc.
- ✅ Méthodes : `parcs()`, `getMachineName()`, `getIpAddress()`

### 1.6 Tests des modèles
- ✅ 21 tests passent (45 assertions)
- ✅ Total : 36 tests LDAP (90 assertions)

---

## PHASE 2 : LdapService ⏸️ ABANDONNÉE

**Décision** : Le `LdapService` a été identifié comme une couche intermédiaire non nécessaire. Les services métier utilisent directement les modèles LdapRecord et les repositories.

- ✅ Supprimé l'enregistrement dans `AppServiceProvider`
- ✅ Supprimé le helper `se4_ldap()` et la facade `SE4Ldap`
- ✅ Documenté comme obsolète dans `SERVICES.md`

---

## PHASE 3 : UserService ✅ PARTIELLE

### 3.1 searchUsers ✅
- ✅ Utilise `UserRepository::search()` et `UserRepository::all()`
- ✅ Filtrage par rôle via `MainGroups::isMainGroup()`
- ✅ Filtrage par statut (actif, corbeille)
- ✅ Pagination conservée

### 3.2 getUser / getByLogin ✅
- ✅ Utilise `UserRepository::findByLogin()` → `LdapUser::findByLogin()`
- ✅ Conversion vers DTO `User` via `toBusinessObject()`

### 3.3 getUserRole ✅
- ✅ Utilise les méthodes du modèle `LdapUser`

### 3.4-3.6 getEtablissements, getFonctions, getClasses ⏳
- ⏳ À migrer vers LdapRecord

### 3.7 createUser ✅
- ✅ Création avec `LdapUser::save()`
- ✅ Mot de passe avec `unicodepwd` (UTF-16LE)
- ✅ Création des OUs avec connexions LDAP natives
- ✅ Ajout aux groupes avec `SambaEduGroup::findMainGroup()`
- ✅ Helpers : `generateLogin()`, `buildUserDn()`, `encodeBirthdate()`

### 3.8 updateUser ⏳ À FAIRE
- ⏳ Voir `docs/USER_UPDATE.md` pour le plan détaillé
- ⏳ Méthodes à créer : `updatePersonalInfo()`, `updateIdentifiers()`, `changeLogin()`

---

## PHASE 4 : ParcService ✅ PARTIELLE

### 4.1 getAllParcs ✅
- ✅ Utilise `ParcRepository::all()`
- ✅ Hiérarchie via `ParcCollection::buildHierarchy()`
- ✅ Filtrage par établissement (UAI)

### 4.2 getParcById ✅
- ✅ Utilise `ParcRepository::findByName()`
- ✅ Recherche récursive dans la hiérarchie

### 4.3-4.4 createParc, deleteParc ⏳
- ⏳ Stubs actuellement, à migrer

### 4.5 Méthodes de recherche ✅
- ✅ `searchParcs()`, `getParcsByType()`, `getParcsByEtab()`
- ✅ `getGroupsWithTags()`, `getDeviceGroupDetails()`
- ✅ `getParcHierarchy()`, `getRootParcs()`, `getLeafParcs()`

### 4.6 Statistiques ✅
- ✅ `getGlobalStats()`, `getParcStats()`
- ✅ `getDetailedStatsByType()`, `getStatsByEtab()`

---

## PHASE 5 : MachineService ⏳ À FAIRE

- ⏳ `getMachinesByParc` → `MachineModel`
- ⏳ `getMachineStatus`
- ⏳ `machineExists`
- ⏳ Actions : `start_machine`, `wakeOnLan`, `shutdown`, `restart`

---

## PHASE 6 : AuthenticationService ✅ PARTIELLE

- ✅ `searchUser` → `LdapUser::findByLogin()`
- ✅ `isEleve` → `LdapUser::isEleve()`
- ✅ `validatePassword` → Bind LDAP direct
- ⏳ `searchMachine`, `getMachineStatus`

---

## PHASES 7-10 : À FAIRE

### Phase 7 : Middlewares et Controllers
- ⏳ Migration du middleware `SambaEduAuth`
- ⏳ Migration des Controllers API et Admin

### Phase 8 : Opérations d'écriture
- ⏳ Créations : `create_parc`, `create_user`
- ⏳ Mises à jour : utilisateurs, parcs
- ⏳ Suppressions
- ⏳ Gestion des membres de groupes

### Phase 9 : Optimisation
- ⏳ Optimisation des requêtes N+1
- ⏳ Cache Laravel pour LdapRecord
- ⏳ Suppression du code legacy

### Phase 10 : Tests et déploiement
- ⏳ Tests de régression complets
- ⏳ Tests de performance
- ⏳ Déploiement progressif

---

## Checklist de migration par service

### UserService
- [x] `searchUsers` ✅
- [x] `getUser` / `getByLogin` ✅
- [x] `getUserRole` ✅
- [ ] `getEtablissements`
- [ ] `getFonctions`
- [ ] `getClasses`
- [x] `createUser` ✅
- [ ] `updateUser` (voir `docs/USER_UPDATE.md`)

### ParcService
- [x] `getAllParcs` ✅
- [x] `getParcById` ✅
- [ ] `createParc`
- [ ] `deleteParc`
- [x] Méthodes de recherche ✅
- [x] Statistiques ✅

### MachineService
- [ ] `getMachinesByParc`
- [ ] `getMachineStatus`
- [ ] `machineExists`
- [ ] Actions sur machines

### AuthenticationService
- [x] `searchUser` ✅
- [x] `isEleve` ✅
- [x] `validatePassword` ✅
- [ ] `searchMachine`
- [ ] `getMachineStatus`

---

## Notes techniques

### Connexions LDAP natives
- Les opérations nécessitant `ldap_add`, `ldap_mod_replace` utilisent des connexions natives
- Connexions fermées immédiatement après utilisation
- Timeout de 10 secondes pour éviter les blocages

### Gestion des OUs
- Création dans l'ordre hiérarchique (parents avant enfants)
- Vérification d'existence avant création
- Gestion des erreurs "Already exists"

### Gestion des comptes système
- Inclusion basée sur les groupes principaux (Eleves, Profs, Administratifs)
- Filtrage via `MainGroups::isSystemAccount()`
- Liste dans `MainGroups::SYSTEM_ACCOUNTS`

### Configuration
- Chargement depuis `/etc/sambaedu/sambaedu.conf`
- DN construits automatiquement dans le Service Provider
- Accès via `config('ldap.connections.default.*')`

---

## Ressources

- 📚 [Documentation LdapRecord](https://ldaprecord.com/docs/core/v3)
- 📚 [LdapRecord Laravel](https://ldaprecord.com/docs/laravel/v3)
- 💻 [GitHub LdapRecord](https://github.com/DirectoryTree/LdapRecord)

---

## Estimation globale

| Phase | Durée estimée | Statut |
|-------|---------------|--------|
| Phase 0 : Infrastructure | 1 semaine | ✅ Complétée |
| Phase 1 : Modèles | 1 semaine | ✅ Complétée |
| Phase 2 : LdapService | - | ⏸️ Abandonnée |
| Phase 3 : UserService | 1 semaine | ✅ Partielle |
| Phase 4 : ParcService | 1 semaine | ✅ Partielle |
| Phase 5 : MachineService | 1 semaine | ⏳ À faire |
| Phase 6 : AuthenticationService | 1 semaine | ✅ Partielle |
| Phase 7 : Middlewares/Controllers | 1 semaine | ⏳ À faire |
| Phase 8 : Écritures | 2 semaines | ⏳ À faire |
| Phase 9 : Optimisation | 1 semaine | ⏳ À faire |
| Phase 10 : Tests/Déploiement | 1 semaine | ⏳ À faire |

**Total estimé : 12 semaines (3 mois)**  
**Progression actuelle : ~35%**
