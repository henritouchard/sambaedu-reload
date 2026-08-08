# Plan de Refactoring - Gestion du Parc Informatique

## Vision Globale

Développer une gestion moderne du parc informatique en distinguant deux sections :

### Section 1 : Gestion du Parc
- **Machines** : Postes Windows/Linux du domaine
- **Groupes de machines** : Salles (OU) et Parcs (groupes de sécurité AD) — conteneurs pour l'assignation

### Section 2 : Gestion des Configurations (nom à définir)
> Suggestions : "Configurations", "Déploiements", "Politiques", "Profils système"

- **Bibliothèque WPKG** : Catalogue d'applications disponibles
- **Profils applicatifs** : Assignation d'applications aux parcs/machines
- **GPO** : Gestion des Group Policy Objects

---

## Architecture Technique

### Principe de base
- **MySQL = Source de vérité** pour toutes les lectures
- **AD = Backend** synchronisé depuis MySQL pour les besoins Windows (GPO, Kerberos)
- **Sync MySQL → AD** à chaque écriture dans MySQL via une Queue Laravel
- **Sync AD → MySQL** On créera aussi un script qui permettra, au clic, d'importer l'AD dans mysql (synchronisation inverse)

### Gestion des types AD (OU vs CN)
- **Dans MySQL** : `MachineGroup` stocke uniquement le nom du groupe (ex: "Salle-101")
- **Dans AD** : Le type est déterminé par l'usage :
  - Si le groupe a des GPO liées → créé comme `OU=Salle-101` (Organizational Unit)
  - Sinon → créé comme `CN=Salle-101,CN=Parcs` (Groupe de sécurité)
- **Compatibilité legacy** : Le `AdSyncService` gère automatiquement la création OU/CN selon les besoins

### Gestion des UUID (MySQL vs AD)
- **Contrainte AD** : L'`objectGUID` est généré par AD à la création (lecture seule)
- **Solution double UUID** :
  - `uuid` : UUID Laravel pour usage interne (relations, cache)
  - `ad_guid` : `objectGUID` de l'AD (NULL si pas encore sync, rempli après sync)
- **Workflow création** : MySQL génère `uuid` → Sync AD → Récupère `objectGUID` → Stocke dans `ad_guid`
- **Workflow import AD** : Lit `objectGUID` → Cherche par `ad_guid` → Update ou Create

### Modèles Laravel

```
App\Models\Parc\
├── Machine.php              # Poste Windows/Linux
└── MachineGroup.php         # Groupe de machines (nom simple en MySQL, type OU/CN géré en AD)

App\Models\Wpkg\
├── Application.php          # Application WPKG (catalogue)
├── ApplicationProfile.php   # Profil applicatif (ancien "parc" de type groupe de sécurité). Correspond à un groupe de wpkg
├── Dependency.php           # Dépendances entre apps
├── Depot.php                # Dépôt d'applications
└── Report.php               # Rapports d'installation

App\Models\Gpo\
├── Gpo.php                  # Group Policy Object
├── GpoLink.php              # Liaison GPO → OU/groupe
├── GpoPreference.php        # Préférences (imprimantes, raccourcis, etc.)
└── GpoTemplate.php          # Templates importables
```

### Relations entre modèles

```
Machine
├── belongsToMany: MachineGroup       # Groupes auxquels appartient la machine (N:N)
├── belongsToMany: ApplicationProfile # Profils applicatifs assignés directement
└── reports(): hasMany Report

MachineGroup
├── belongsToMany: Machine            # Machines dans ce groupe (N:N)
├── belongsTo: MachineGroup (parent)  # Groupe parent (hiérarchie)
├── hasMany: MachineGroup (children)  # Sous-groupes
└── belongsToMany: ApplicationProfile # Profils applicatifs assignés au groupe

ApplicationProfile (ancien "Parc")
├── belongsToMany: Application       # Applications de ce profil
├── belongsToMany: Machine           # Machines ayant ce profil
├── belongsToMany: MachineGroup      # Groupes ayant ce profil
└── belongsToMany: Gpo               # GPO liées à ce profil

Application
├── belongsToMany: ApplicationProfile # Profils contenant cette app
├── hasMany: Dependency (as parent)
└── hasMany: Dependency (as child)
```

### Tables pivot
```
machine_group_machine            # Machine ↔ MachineGroup (N:N)
application_profile_machine      # Machine ↔ ApplicationProfile
application_profile_group        # MachineGroup ↔ ApplicationProfile
application_profile_application  # ApplicationProfile ↔ Application
application_profile_gpo          # ApplicationProfile ↔ Gpo
```

### Services

```
App\Services\
<!-- pour les parcs -->
├── ParcService.php          # CRUD machines et groupes
├── AdSyncService.php        # Sync MySQL ↔ AD (bidirectionnel)

<!-- pour les wpkg -->
├── WpkgService.php          # CRUD applications et profils
├── DepotService.php         # Import depuis dépôts
├── ReportService.php        # Traitement des rapports
├── XmlGeneratorService.php  # Génération packages.xml/profiles.xml

<!-- pour les gpo -->
├── GpoService.php           # CRUD GPO et préférences
└── GpoImportService.php     # Import templates
```

### Providers (Infrastructure)

```
App\Providers\
├── SysvolProvider.php       # Lecture/écriture fichiers SYSVOL via smbclient
└── XmlProvider.php          # Parse et génération XML (GPO, WPKG, GPP)
```

### Repositories

```
App\Repositories\
├── ParcRepository.php       # Machines et groupes
├── WpkgRepository.php       # Applications, profils, dépendances, rapports
└── GpoRepository.php        # GPO et préférences
```

---

## Plan de Développement

> Chaque section livre un système **opérationnel et testable** avant de passer à la suivante.

---

### Section 1 : Gestion du Parc (Machines & Groupes)

**Objectif** : Interface complète pour gérer les machines et groupes de machines, synchronisée avec AD.

#### 1.1 Modèles et Repository
- [x] `Workstation.php` : Modèle Eloquent (table `postes`) - renommé de Machine
- [x] `WorkstationGroup.php` : Modèle Eloquent (table `parc` enrichie)
  - Champs : `id_parc`, `nom_parc`, `nom_parc_wpkg`, `description`, `parent_id`, `ad_guid_cn`, `ad_guid_ou`, `flag_parc`, `created_at`, `updated_at`
  - `ad_guid_cn` : objectGUID du CN dans AD (pour WPKG)
  - `ad_guid_ou` : objectGUID de l'OU dans AD (pour GPO)
- [x] `ParcRepository.php` : Requêtes machines et groupes
- [x] Relations Eloquent : Workstation ↔ WorkstationGroup (N:N via `parc_profile`), WorkstationGroup ↔ WorkstationGroup (parent/children)
- [x] Table pivot `parc_profile` (legacy, réutilisée)
- [x] Migration : enrichissement table `parc` (2026_01_19_120000_enhance_parc_table.php)

#### 1.2 Service et Sync AD
- [x] `ParcService.php` : CRUD machines et groupes (App\Services\Parc\ParcService)
- [ ] `AdSyncService.php` : Sync MySQL → AD (création/modification)
- [x] Job Laravel : `SyncWorkstationGroupsFromAd` (import AD → MySQL)
- [x] Commande artisan : `sync:workstation-groups` (import AD → MySQL au clic)

#### 1.3 Vues Livewire
- [x] Onglet "Postes" (filtres, recherche, pagination)
    - [x] Liste des postes (filtres OS, groupe, recherche, pagination)
    - [x] Détail poste (infos, groupes, actions)
- [x] Onglet "Groupes"
    - [x] Liste des groupes (tableau avec pagination)
- [x] Création/modification/suppression groupe
- [x] Ajout/retrait poste dans un groupe
- [x] Action "Importer depuis l'AD" (dropdown actions)

#### 1.4 Tests et validation
- [ ] Tests unitaires ParcService
- [ ] Tests feature vues Livewire
- [ ] Test sync AD (environnement de test)

**Livrable** : Gestion complète du parc fonctionnelle et synchronisée avec AD.

---

### Section 2 : Gestion WPKG & Profils Applicatifs

**Objectif** : Catalogue d'applications et assignation via profils.

#### 2.1 Modèles et Migrations ✅
- [x] `Application.php` : Modèle Eloquent (table `depot_applications`)
  - Fichier : `app/Models/Application.php`
  - Renommé de `DepotApplication` → `Application` (20/01/2026)
  - La table reste `depot_applications` (289 applications)
  - Relations : `appProfiles()`, `depot()`
  - Accesseurs : `id` (alias pour `id_depot_applications`), `name` (alias pour `nom_app`)
- [x] `AppProfile.php` : Modèle Eloquent (nouvelle table `app_profiles`)
  - Fichier : `app/Models/AppProfile.php`
  - Champs : `id`, `name`, `display_name`, `description`, `ad_guid`, `is_active`, timestamps
  - Relations : `applications()` (N:N), `workstationGroups()` (N:N)
- [x] Migration : `2026_01_20_140000_create_app_profiles_tables.php`
  - Table `app_profiles` : Profils applicatifs
  - Table `app_profile_workstation_group` : Pivot AppProfile ↔ WorkstationGroup
  - Table `app_profile_application` : Pivot AppProfile ↔ Application
- [x] Migration : `2026_01_20_150000_add_app_profile_workstation_table.php`
  - Table `app_profile_workstation` : Pivot AppProfile ↔ Workstation (postes individuels)
- [x] Migration : `2026_01_20_160000_refactor_app_profile_to_depot_applications.php`
  - Refactoring pivot `app_profile_application` pour pointer vers `depot_applications` au lieu de `applications`
- [x] Relation inverse dans `WorkstationGroup.php` : `appProfiles()`
- [x] Relation dans `AppProfile.php` : `workstations()` (postes individuels)
- [x] `Depot.php` : Modèle Eloquent (table `depot`)
  - Fichier : `app/Models/Depot.php`
  - Relations : `depotApplications()`
  - Scopes : `active()`, `principal()`
- [x] ~~`DepotApplication.php`~~ : Supprimé, renommé en `Application.php` (20/01/2026)
- [x] `Report.php` : Modèle Eloquent (table `poste_app`)
  - Fichier : `app/Models/Report.php`
  - Relations : `workstation()`, `application()`
  - Scopes : `installed()`, `notInstalled()`, `needsReboot()`
- [ ] `Dependency.php` : Modèle Eloquent (table `dependance`) - relation déjà dans Application

#### 2.2 Services ✅
- [x] `AppProfileService.php` : CRUD profils et applications
  - Fichier : `app/Services/AppProfile/AppProfileService.php`
  - Méthodes : `listProfiles()`, `createProfile()`, `updateProfile()`, `deleteProfile()`
  - Méthodes : `addApplications()`, `removeApplications()`, `addWorkstationGroups()`, `removeWorkstationGroups()`
  - Méthodes : `listApplications()`, `getCategories()`, `getStats()`
- [ ] `DepotService.php` : Import applications depuis dépôts externes
- [ ] `ReportService.php` : Traitement des rapports clients WPKG

#### 2.3 Providers
- [ ] `XmlProvider.php` : Parse et génération XML

#### 2.4 Vues Livewire ✅
- [x] Page principale `/app/parc-settings` avec 2 onglets
  - Fichier : `resources/views/pages/parc-settings/index.blade.php`
  - Onglet "Profils Applicatifs" : Liste, création, activation/désactivation, suppression
  - Onglet "Catalogue d'Applications" : Liste avec filtres (recherche, catégorie)
- [x] Partials :
  - `_partials/profiles-tab.blade.php` : Tableau des profils
  - `_partials/applications-tab.blade.php` : Tableau des applications
- [x] Détail profil `/app/parc-settings/profiles/{id}`
  - Fichier : `resources/views/pages/parc-settings/profiles/index.blade.php`
  - Édition nom/description, onglets Applications, Groupes et Postes
  - Modals : ajout applications, ajout groupes, ajout postes
  - Partials : `_partials/applications-tab.blade.php`, `_partials/groups-tab.blade.php`, `_partials/workstations-tab.blade.php`, `_partials/add-apps-modal.blade.php`, `_partials/add-groups-modal.blade.php`, `_partials/add-workstations-modal.blade.php`
  - Filtrage des applications disponibles (exclut celles déjà dans le profil)
- [x] Détail application `/app/parc-settings/applications/{id}`
  - Fichier : `resources/views/pages/parc-settings/applications/index.blade.php`
  - Infos techniques (version, catégorie, dépôt, branche, compatibilité)
  - Profils utilisant l'application
- [ ] Rapports d'installation par machine

#### 2.5 Routes ✅
- [x] `app.parc-settings.index` : Page principale
- [x] `app.parc-settings.profiles.show` : Détail profil
- [x] `app.parc-settings.applications.show` : Détail application
- [x] Lien sidebar mis à jour

#### 2.5.2 Synchronisation SQL → AD (Jobs Laravel)

**Objectif** : Maintenir l'AD cohérent avec la base SQL pour que WPKG et les délégations fonctionnent.

##### Structure AD à maintenir

```
OU=Parcs                              ← Conteneur des groupes de sécurité
 ├── CN=Salle-Info-101                ← Groupe (membres = machines)
 ├── CN=CDI                           
 └── CN=Parc-Portables                

OU=Computers                          ← Conteneur des machines
 ├── OU=Salle-Info-101                ← Salle (OU pour GPO)
 │    └── CN=PC-101-01$               ← Machine
 └── OU=CDI                           
```

##### Jobs de synchronisation à créer

**1. Gestion des Parcs/Salles**

| Action SQL | Job Laravel | Opérations AD |
|------------|-------------|---------------|
| Création WorkstationGroup (type=salle) | `SyncWorkstationGroupToAd` | `groupadd()` dans `OU=Parcs` + `ouadd()` dans `OU=Computers` |
| Création WorkstationGroup (type=parc) | `SyncWorkstationGroupToAd` | `groupadd()` dans `OU=Parcs` uniquement |
| Suppression WorkstationGroup | `DeleteWorkstationGroupFromAd` | `delete_ad()` groupe + OU si salle + délégations associées |
| Renommage WorkstationGroup | `RenameWorkstationGroupInAd` | `move_ad()` groupe + OU si salle |
| Déplacement salle (changement parent) | `MoveWorkstationGroupInAd` | `rename_salle()` + mise à jour `memberof` des machines |

**2. Gestion des Machines dans les Parcs**

| Action SQL | Job Laravel | Opérations AD |
|------------|-------------|---------------|
| Ajout machine à un parc | `AddWorkstationToGroupInAd` | `groupaddmemberbydn()` sur le groupe CN |
| Retrait machine d'un parc | `RemoveWorkstationFromGroupInAd` | `groupdelmemberbydn()` sur le groupe CN |
| Déplacement machine vers salle | `MoveWorkstationToSalleInAd` | `move_ad()` machine + mise à jour `memberof` (retrait anciens parcs, ajout nouveaux) |

**3. Gestion des Délégations** (optionnel, si on garde ce système)

| Action SQL | Job Laravel | Opérations AD |
|------------|-------------|---------------|
| Création délégation | `CreateDelegationInAd` | `groupadd()` dans `OU=Delegations` avec membres (user + parc) |
| Suppression délégation | `DeleteDelegationFromAd` | `groupdel()` |

##### Tâches d'implémentation

- [x] **Service `AdSyncService`** : Encapsule les opérations LDAP (via LdapRecord)
  - [x] `createWorkstationGroup(WorkstationGroup $group)` : Crée CN dans OU=Parcs + OU si salle
  - [x] `deleteWorkstationGroup(WorkstationGroup $group)` : Supprime CN + OU + délégations
  - [x] `renameWorkstationGroup(WorkstationGroup $group, string $newName)`
  - [x] `moveWorkstationGroup(WorkstationGroup $group, ?WorkstationGroup $newParent)` : Déplace salle vers nouveau parent
  - [x] `addMemberToGroup(Workstation $machine, WorkstationGroup $group)`
  - [x] `removeMemberFromGroup(Workstation $machine, WorkstationGroup $group)`
  - [x] `moveMachineToSalle(Workstation $machine, WorkstationGroup $salle)`

- [x] **Jobs Laravel** (dispatchés après les actions CRUD)
  - [x] `SyncWorkstationGroupToAd` : Création/mise à jour groupe
  - [x] `DeleteWorkstationGroupFromAd` : Suppression groupe
  - [x] `RenameWorkstationGroupInAd` : Renommage groupe
  - [x] `MoveWorkstationGroupInAd` : Déplacement salle (changement parent)
  - [x] `SyncWorkstationMembershipToAd` : Ajout/retrait membre

- [x] **Observers Eloquent** (déclenchent les jobs automatiquement)
  - [x] `WorkstationGroupObserver` : `created()`, `updated()` (nom + parent), `deleting()`
  - [x] `WorkstationMembershipObserver` : Helper statique pour les relations pivot

- [x] **Commande de synchronisation complète**
  - [x] `php artisan ad:sync-workstation-groups` : Synchronise tous les groupes SQL → AD
  - [x] Option `--dry-run` pour prévisualiser les changements
  - [x] Option `--force` pour forcer la resynchronisation

- [x] **Gestion des erreurs**
  - [x] Retry automatique (3 tentatives)
  - [x] Logging des échecs
  - [ ] Notification admin en cas d'échec persistant (TODO)

##### Dépendances

- `LdapService` existant (connexion AD)
- Configuration `parcs_rdn`, `computers_rdn` depuis `SambaEduConfig`

#### 2.6 Endpoints XML (compatibilité WPKG clients)
- [ ] Route `GET /wpkg/packages.xml` → liste des packages actifs
- [ ] Route `GET /wpkg/profiles.xml?poste=XXX` → profil de la machine
- [ ] Route `POST /wpkg/report` → réception rapports clients
- [ ] `XmlGeneratorService.php` : Génération XML

#### 2.6.1 Réécriture du calcul des applications à installer (CRITIQUE)

**Contexte** : Le script `wpkg/profiles_xml_out.php` génère le XML des applications à installer pour chaque machine. Il appelle `info_poste_applications()` qui utilise l'ancien modèle de données.

**Problème** : La fonction legacy `info_poste_applications()` dans `includes/wpkg_libsql.php` calcule les applications via :
- Table `parc` (ancienne, mélange WorkstationGroup + AppProfile)
- Table `parc_profile` (association machine ↔ parc)
- Table `applications_profile` (association application ↔ entité polymorphique)

**Nouveau modèle Laravel** :
- `WorkstationGroup` = Salle physique (OU dans AD)
- `AppProfile` = Profil applicatif (CN dans AD, contient les applications)
- Relation `WorkstationGroup` ↔ `AppProfile` (N:N)
- Relation `Workstation` ↔ `AppProfile` (N:N pour assignation directe)

**Calcul à réécrire** :

```
Applications pour une machine = 
  UNION de :
    1. Applications des AppProfiles assignés directement à la machine
       (via app_profile_workstation)
    2. Applications des AppProfiles assignés aux WorkstationGroups de la machine
       (via app_profile_workstation_group + parc_profile)
    3. Dépendances des applications ci-dessus
       (via dependance)
```

**Tâches** :
- [ ] Créer `App\Services\Wpkg\ApplicationCalculatorService.php`
  - [ ] `getApplicationsForWorkstation(Workstation $workstation): Collection`
  - [ ] `getApplicationsForWorkstationByName(string $hostname): Collection`
  - [ ] Gestion du cache APCu (comme legacy)
  - [ ] Résolution des dépendances
- [ ] Créer route Laravel `GET /wpkg/profiles/{hostname}.xml`
  - [ ] Utilise `ApplicationCalculatorService`
  - [ ] Génère XML compatible WPKG
- [ ] Tester avec client WPKG réel
- [ ] Migrer les données `applications_profile` → `app_profile_application`

**Requête SQL legacy à remplacer** (référence) :
```sql
-- Fichier: includes/wpkg_libsql.php ligne 217-222
SELECT ap.type_entite, a.id_app, a.id_nom_app, ...
FROM (`postes` po, `parc` p, `parc_profile` pp)
LEFT JOIN (`applications_profile` ap, `applications` a) 
  ON a.id_app=ap.id_appli 
  AND ((po.id_poste=ap.id_entite AND ap.type_entite='poste') 
       OR (pp.id_parc=ap.id_entite AND ap.type_entite='parc'))
WHERE po.nom_poste=? AND po.id_poste=pp.id_poste AND p.id_parc=pp.id_parc
```

**Nouvelle requête Eloquent** (à implémenter) :
```php
// Via AppProfile directement assigné au poste
$directApps = $workstation->appProfiles()
    ->with('applications')
    ->get()
    ->pluck('applications')
    ->flatten();

// Via AppProfile des WorkstationGroups du poste
$groupApps = $workstation->workstationGroups()
    ->with('appProfiles.applications')
    ->get()
    ->pluck('appProfiles')
    ->flatten()
    ->pluck('applications')
    ->flatten();

// Union + dépendances
$allApps = $directApps->merge($groupApps)->unique('id');
$withDeps = $this->resolveDependencies($allApps);
```

#### 2.7 Tests et validation
- [ ] Tests unitaires AppProfileService
- [ ] Tests endpoints XML (format, contenu)
- [ ] Test avec client WPKG réel

**Livrable** : Gestion des applications et profils fonctionnelle ✅, endpoints XML compatibles (à faire).

---

### Décisions prises (Session 20/01/2026)

1. **Profils Applicatifs (AppProfiles)**
   - Nouvelle table SQL `app_profiles` créée (pas de réutilisation de l'ancien système polymorphique)
   - Relations ManyToMany avec `parc` (WorkstationGroup), `postes` (Workstation) et `depot_applications` (Application)
   - Champ `ad_guid` prévu pour synchronisation AD future
   - Synchronisation AD reportée à plus tard

2. **Refactoring Application (20/01/2026)**
   - La table `applications` était vide → utilisation de `depot_applications` (289 apps)
   - `DepotApplication` renommé en `Application` (classe uniquement, table inchangée)
   - Pivot `app_profile_application` pointe maintenant vers `depot_applications.id_depot_applications`
   - Ancien modèle `Application.php` (table `applications`) supprimé

3. **GPOs**
   - Reporté - nécessite étude approfondie

4. **Catalogue WPKG**
   - Lecture seule pour le moment (pas d'import/création/modification)
   - Itérations futures prévues

5. **Structure des routes**
   - `/app/parc-settings` au lieu de `/app/wpkg`
   - Structure fichiers : `index.blade.php` + `_partials/` pour chaque niveau


#### controles et corrections
- [] tester les autres fonctionnalités d'association.
- [] exporter une portion de l'ad pour étude.
- [] Lors du renommage d'un workStationGroup, si un appProfile est lié et porte le nom d'origine du groupe, il doit être renommé et renommé également dans l'AD
 - [] Lors de la suppression d'un workStationGroup, si un appProfile est lié et porte le nom d'origine du groupe, il doit être supprimé et supprimé également dans l'AD
 - [] Renommer les classes Machine en workstation (à voir car machine c'est pour l'AD alors que workstation c'est sql.)
 - [] Renommer les classes DeviceGroupModel en WorkstationGroupModel (à voir car devicegroupmodel c'est pour l'AD alors que workstationgroupmodel c'est sql.)
 - [] Documenter le fonctionnement de la gestion du parc (dans un worktree) (Documentation technique, flux des infos, ce que chaque élément représente, etc...)
 - [] Documenter le fonctionnement de la gestion du groupe applicatif (dans un worktree) (Documentation technique, flux des infos, ce que chaque élément représente, etc...)
 - [][testing de monstre](./testingPlan.md)


---

### Section 3 : Gestion GPO

**Objectif** : Administration des GPO (import, préférences, liaisons).

#### 3.1 Modèles et Repository
- [ ] `Gpo.php` : Modèle Eloquent (données GPO depuis AD ou cache)
- [ ] `GpoPreference.php` : Préférences (imprimantes, raccourcis, registre)
- [ ] `GpoTemplate.php` : Templates importables
- [ ] `GpoRepository.php` : Requêtes GPO
- [ ] Relation : ApplicationProfile ↔ Gpo (N:N)

#### 3.2 Providers
- [ ] `SysvolProvider.php` : Lecture/écriture fichiers SYSVOL via smbclient

#### 3.3 Services
- [ ] `GpoService.php` : CRUD GPO, versioning, préférences
- [ ] `GpoImportService.php` : Import templates depuis Git/zip

#### 3.4 Vues Livewire
- [ ] Liste des GPO (statut, version, liaisons)
- [ ] Détail GPO (préférences, OU liées)
- [ ] Import de templates GPO
- [ ] Édition préférences (imprimantes, raccourcis, registre, proxy)

#### 3.5 Tests et validation
- [ ] Tests unitaires GpoService
- [ ] Tests SysvolProvider (mock smbclient)
- [ ] Test import template réel

**Livrable** : Administration GPO complète depuis l'interface web.

---

### Section 4 : Scripts d'Applications & Intégration

**Objectif** : Génération des scripts GPO et intégration finale.

#### 4.1 Scripts d'applications
- [ ] Service de génération des scripts (startup, logon, logoff, shutdown)
- [ ] Gestion des redirections de profils
- [ ] Endpoint `/gpo/applications.php` modernisé (ou route Laravel)

#### 4.2 Intégration
- [ ] Remplacement progressif des appels legacy
- [ ] Documentation API interne
- [ ] Tests d'intégration end-to-end

#### 4.3 Migration données
- [ ] Script de migration des données legacy si changement de schéma
- [ ] Validation cohérence données

**Livrable** : Système complet intégré, legacy remplacé.

---

## Questions Ouvertes

### Nommage
- [x] Nom pour la section "Gestion des Configurations" ? => "Profils applicatifs"

### Architecture
- [x] Faut-il conserver la distinction salle/parc ou unifier en un seul concept "groupe" ? 
=> Certainement pas l'unifier: ces concepts sont différents. Nous avons répondu à cette question à l'aide des models de données ci-dessous.
- [x] Les GPO doivent-elles être gérées dans la même section que WPKG ou séparément ? => possiblement différemment je vais voir.

### Migration
- [x] Stratégie de migration des données existantes ? => Il y aura une migration des données existantes.
- [x] Période de cohabitation legacy/moderne ? => Il y en aura une c'est pourquoi il faut respecter les conventions de nommage établies par le legacy, à minima dans l'AD. 

---

## Notes Techniques

### Tables MySQL existantes (à mapper)
- `postes` → `Workstation` ✅
- `parc` → `WorkstationGroup` ✅ (table enrichie avec description, parent_id, ad_guid_ou, timestamps)
- `parc_profile` → pivot Workstation ↔ WorkstationGroup ✅
- `applications` → ~~non utilisée~~ (table vide, remplacée par `depot_applications`)
- `depot_applications` → `Application` ✅ (289 applications, renommé de DepotApplication)
- `app_profiles` → `AppProfile` ✅ (nouvelle table, remplace le système polymorphique legacy)
- `app_profile_workstation_group` → pivot AppProfile ↔ WorkstationGroup ✅
- `app_profile_application` → pivot AppProfile ↔ Application (depot_applications) ✅
- `app_profile_workstation` → pivot AppProfile ↔ Workstation ✅ (nouvelle table)
- `applications_profile` → (legacy, polymorphique - non utilisé par le nouveau système)
- `dependance` → relation dans `Application` ✅
- `poste_app` → `Report` ✅
- `depot` → `Depot` ✅ (2 dépôts configurés)

### GPO - Niveau d'administration actuel
L'app legacy gère les GPO à plusieurs niveaux :
1. **Import/Export** : Templates depuis Git ou archives zip
2. **Modification de contenu** : Fichiers `.pol`, XML, INI
3. **Préférences** : Imprimantes, raccourcis, registre, proxy
4. **Synchronisation SYSVOL** : Via smbclient
5. **Versioning** : Incrémentation automatique

### Fichiers legacy clés
- `includes/wpkg_lib.php` : Fonctions utilitaires WPKG
- `includes/wpkg_libsql.php` : Requêtes SQL WPKG
- `includes/gpo.inc.php` : Manipulation GPO (1356 lignes)
- `includes/applications.inc.php` : Génération scripts
- `wpkg/wpkg_ldap_update.php` : Sync AD → MySQL (à inverser)
- `gpo/gpo-maj.php` : Interface import GPO
