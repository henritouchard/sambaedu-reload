# Debrief : Refactoring du Système de Gestion des Applications Windows

## Vue d'ensemble du système legacy

Le système actuel gère le déploiement automatique d'applications sur les machines Windows via trois mécanismes interconnectés :

1. **WPKG** - Gestionnaire de paquets Windows (installation/désinstallation d'applications)
2. **GPO** - Group Policy Objects pour la configuration système et l'exécution de scripts
3. **Scripts d'applications** - Scripts dynamiques exécutés au startup/logon/logoff/shutdown

---

## Architecture proposée pour Laravel

> **Important** : Cette architecture s'intègre avec l'existant Laravel qui utilise déjà :
> - **LdapRecord** pour l'accès à Active Directory (LdapModels avec accesseurs sémantiques)
> - **Pattern Repository** pour l'abstraction des données
> - **Pattern Service** pour la logique métier
> - **Types** pour les objets métier (`App\Types\User`, `App\Types\Parc`)

### 1. Domaine : Applications (WPKG)

#### 1.1 Models Eloquent (MySQL) - À créer dans `App\Models`

> Ces models représentent les données WPKG stockées en MySQL (pas dans l'AD).

##### `WpkgApplication`
- **Table** : `applications`
- **Responsabilité** : Représente une application déployable (paquet WPKG)
- **Attributs** : `id_nom_app`, `nom_app`, `version_app`, `compatibilite_app`, `categorie_app`, `priorite_app`, `reboot_app`, `sha_app`, `active_app`
- **Relations** : 
  - `belongsToMany(WpkgParc, 'applications_profile')` avec pivot `type_entite='parc'`
  - `belongsToMany(WpkgPoste, 'applications_profile')` avec pivot `type_entite='poste'`
  - `belongsToMany(WpkgApplication, 'dependance', 'id_app', 'id_app_requise')` - dépendances
  - `hasMany(WpkgDepotApplication)`

##### `WpkgPoste`
- **Table** : `postes`
- **Responsabilité** : Représente une machine Windows dans le parc WPKG (miroir de l'AD)
- **Attributs** : `nom_poste`, `uuid_poste`, `OS_poste`, `ip_poste`, `mac_address_poste`, `flag_poste`, `sha_rapport_poste`
- **Relations** :
  - `belongsToMany(WpkgParc, 'parc_profile')`
  - `belongsToMany(WpkgApplication, 'applications_profile')` avec pivot `type_entite='poste'`
  - `hasMany(WpkgPosteApp, 'poste_app')` - rapports d'installation
- **Lien AD** : `uuid_poste` correspond à `objectguid` dans `MachineModel`

##### `WpkgParc`
- **Table** : `parc`
- **Responsabilité** : Représente un parc WPKG (miroir de l'AD)
- **Attributs** : `nom_parc`, `nom_parc_wpkg`, `uuid`
- **Relations** :
  - `belongsToMany(WpkgPoste, 'parc_profile')`
  - `belongsToMany(WpkgApplication, 'applications_profile')` avec pivot `type_entite='parc'`
- **Lien AD** : `uuid` correspond à `objectguid` dans `DeviceGroupTagModel`

##### `WpkgDepot`
- **Table** : `depot`
- **Responsabilité** : Source externe d'applications (dépôt de recettes)
- **Attributs** : `nom_depot`, `url_depot`, `hash_xml`, `depot_principal`, `depot_actif`
- **Relations** : `hasMany(WpkgDepotApplication)`

##### `WpkgDepotApplication`
- **Table** : `depot_applications`
- **Responsabilité** : Application disponible sur un dépôt externe
- **Attributs** : `id_nom_app`, `nom_app`, `url_xml`, `sha_xml`, `version`, `categorie`, `compatibilite`
- **Relations** : `belongsTo(WpkgDepot)`

##### `WpkgPosteApp`
- **Table** : `poste_app`
- **Responsabilité** : Rapport d'installation d'une application sur un poste
- **Attributs** : `id_poste`, `id_app`, `revision_poste_app`, `statut_poste_app`, `reboot_poste_app`
- **Relations** : `belongsTo(WpkgPoste)`, `belongsTo(WpkgApplication)`

---

#### 1.2 Repositories WPKG - À créer dans `App\Repositories`

##### `WpkgApplicationRepository`
- **Responsabilité** : Accès aux données des applications WPKG (MySQL)
- **Méthodes clés** :
  - `findByIdNom(string $idNom): ?WpkgApplication`
  - `findActive(): Collection`
  - `findWithDependencies(int $id): WpkgApplication`
  - `getApplicationsForPoste(WpkgPoste $poste): Collection` - Résout via parcs + direct + dépendances

##### `WpkgPosteRepository`
- **Responsabilité** : Accès aux données des postes WPKG (MySQL)
- **Méthodes clés** :
  - `findByNom(string $nom): ?WpkgPoste`
  - `findByUuid(string $uuid): ?WpkgPoste`
  - `findWithParcs(string $nom): ?WpkgPoste`
  - `findWithApplications(string $nom): ?WpkgPoste`

##### `WpkgParcRepository`
- **Responsabilité** : Accès aux données des parcs WPKG (MySQL)
- **Méthodes clés** :
  - `findByNom(string $nom): ?WpkgParc`
  - `findByUuid(string $uuid): ?WpkgParc`
  - `findWithApplications(string $nom): ?WpkgParc`

##### `WpkgDepotRepository`
- **Responsabilité** : Accès aux données des dépôts (MySQL)
- **Méthodes clés** :
  - `findPrincipal(): ?WpkgDepot`
  - `findActive(): Collection`
  - `getApplicationsForDepot(int $depotId): Collection`

---

#### 1.3 Services WPKG - À créer dans `App\Services\Wpkg`

##### `WpkgApplicationService`
- **Responsabilité** : Logique métier des applications WPKG
- **Dépendances** : `WpkgApplicationRepository`, `WpkgPosteRepository`, `WpkgParcRepository`
- **Méthodes clés** :
  - `assignToParc(WpkgApplication $app, WpkgParc $parc): void`
  - `assignToPoste(WpkgApplication $app, WpkgPoste $poste): void`
  - `getApplicationsForPoste(string $nomPoste): Collection` - Résout applications via parcs + direct + dépendances
  - `syncEntiteApplications(string $type, int $id, array $appIds): void`

##### `WpkgSyncService`
- **Responsabilité** : Synchronisation entre Active Directory et MySQL WPKG
- **Dépendances** : `MachineRepository` (LDAP), `ParcRepository` (LDAP), `WpkgPosteRepository`, `WpkgParcRepository`
- **Méthodes clés** :
  - `syncParcsFromAD(): SyncResult` - Compare AD ↔ MySQL, insert/update/delete
  - `syncPostesFromAD(): SyncResult` - Compare AD ↔ MySQL avec gestion UUID
  - `syncParcMembership(): SyncResult` - Met à jour `parc_profile`
  - `handleUuidConflict(string $uuid, string $newName): void` - Gestion des renommages

##### `WpkgDepotImportService`
- **Responsabilité** : Import d'applications depuis les dépôts externes
- **Dépendances** : `WpkgDepotRepository`, `WpkgApplicationRepository`, `GuzzleHttp\Client`
- **Méthodes clés** :
  - `fetchDepotApplicationList(WpkgDepot $depot): Collection`
  - `importApplication(WpkgDepotApplication $depotApp, bool $downloadFiles = true): ImportResult`
  - `downloadInstallationFiles(array $downloads): DownloadResult`
  - `verifyFileIntegrity(string $path, string $expectedHash, string $algo = 'sha256'): bool`

##### `WpkgPackagesXmlService`
- **Responsabilité** : Génération du fichier `packages.xml` pour les clients WPKG
- **Dépendances** : `WpkgApplicationRepository`, `SEConfig` (facade existante)
- **Méthodes clés** :
  - `generate(): string` - XML complet de toutes les applications actives
  - `generateForPoste(string $nomPoste): string` - XML filtré pour une machine
  - `substituteVariables(DOMDocument $xml): void` - Substitue `###_PARAM_###`

##### `WpkgProfilesXmlService`
- **Responsabilité** : Génération du fichier `profiles.xml` pour les clients WPKG
- **Dépendances** : `WpkgApplicationService`
- **Méthodes clés** :
  - `generateForPoste(string $nomPoste): string` - Liste des applications assignées

---

### 2. Domaine : Scripts d'Applications

#### 2.1 Types/DTOs - À créer dans `App\Types`

> Suivre le pattern existant des Types (`App\Types\User`, `App\Types\Parc`)

##### `ApplicationScript` (Value Object)
- **Fichier** : `App\Types\ApplicationScript.php`
- **Responsabilité** : Représente un script à exécuter sur les machines
- **Propriétés** (readonly) :
  - `type` : `ScriptType` enum (package | local)
  - `app` : string - nom du dossier application
  - `action` : `ScriptAction` enum (startup | logon | logoff | shutdown | wpkg)
  - `context` : `ScriptContext` enum ("" | once | server | system)
  - `os` : `OperatingSystem` enum (windows | linux)
  - `interpreter` : `Interpreter` enum (cmd | bash | powershell | apt | redirects)
  - `includes` / `excludes` : array - filtres par groupe/parc
  - `includesApps` / `excludesApps` : array - filtres par applications installées
  - `script` : array - lignes du script
  - `path` : string - chemin du fichier source

##### `RedirectConfig` (Value Object)
- **Fichier** : `App\Types\RedirectConfig.php`
- **Responsabilité** : Configuration de redirection de dossiers utilisateur
- **Propriétés** (readonly) : `link`, `dest`, `server`, `includes`, `excludes`, `includesApps`, `excludesApps`

##### `ScriptExecutionContext` (DTO)
- **Fichier** : `App\Types\ScriptExecutionContext.php`
- **Responsabilité** : Contexte d'exécution d'un script (informations de la requête)
- **Propriétés** (readonly) :
  - `id` : string - identifiant unique de la session (md5)
  - `action` : `ScriptAction` enum
  - `context` : `ScriptContext` enum
  - `machine` : `MachineModel` - depuis LdapModels existant
  - `user` : `LdapUser|null` - depuis LdapModels existant
  - `salle` : string - nom de la salle
  - `parcs` : array - liste des noms de parcs
  - `groups` : array - liste des groupes utilisateur
  - `installedApplications` : array - applications installées (depuis WPKG)
  - `os` : `OperatingSystem` enum
  - `interpreter` : `Interpreter` enum
  - `userprofile` : string|null - chemin du profil utilisateur (Windows)
  - `speed` : int - vitesse réseau
  - `uuid` : string|null - UUID de la machine

##### Enums - À créer dans `App\Constants\Scripts`
- `ScriptType` : package, local
- `ScriptAction` : startup, logon, logoff, shutdown, wpkg
- `ScriptContext` : default, once, server, system
- `OperatingSystem` : windows, linux
- `Interpreter` : cmd, bash, powershell, apt, redirects

---

#### 2.2 Services Scripts - À créer dans `App\Services\Scripts`

##### `ScriptReaderService`
- **Responsabilité** : Lecture et parsing des scripts depuis le filesystem
- **Configuration** : Chemins configurables via `config('sambaedu.scripts.paths')`
- **Méthodes clés** :
  - `readAll(): Collection` - Retourne `Collection<ApplicationScript>`
  - `parseScriptsJson(string $appPath): array`
  - `parseRedirectsJson(string $appPath): array`
  - `parseLegacyFilename(string $filename): ?ApplicationScript` - Support ancien format `startup@parc.windows`

##### `ScriptFilterService`
- **Responsabilité** : Filtrage des scripts applicables selon le contexte
- **Méthodes clés** :
  - `filter(Collection $scripts, ScriptExecutionContext $ctx): Collection`
  - `matchesIncludesExcludes(ApplicationScript $script, ScriptExecutionContext $ctx): bool`
  - `matchesApplicationFilter(ApplicationScript $script, array $installedApps): bool`

##### `ScriptGeneratorService`
- **Responsabilité** : Orchestration de la génération du script final
- **Dépendances** : `ScriptReaderService`, `ScriptFilterService`, `VariableSubstitutionService`, `CacheService` (existant)
- **Méthodes clés** :
  - `generate(ScriptExecutionContext $ctx): ScriptOutput` - Retourne scripts par interpréteur
  - `generateHeader(ScriptExecutionContext $ctx, Interpreter $interpreter): array`
  - `generateFooter(ScriptExecutionContext $ctx, Interpreter $interpreter): array`

##### `ScriptOutput` (DTO résultat)
- **Propriétés** : `cmd: string`, `bash: string`, `powershell: string`, `server: string`

##### Générateurs spécialisés - Dans `App\Services\Scripts\Generators`

| Classe | Responsabilité |
|--------|----------------|
| `RedirectScriptGenerator` | Commandes MKLINK pour redirections de profils (Local→Roaming→Server) |
| `LocalAdminScriptGenerator` | Gestion des droits admin locaux Windows/Linux |
| `SudoScriptGenerator` | Appel des scripts system Linux via sudo |
| `OnceScriptGenerator` | Scripts exécutés une seule fois (tracking MD5) |
| `AptScriptGenerator` | Installation de paquets Linux (apt) |
| `PowershellScriptGenerator` | Téléchargement et exécution de scripts PowerShell |

##### `VariableSubstitutionService`
- **Responsabilité** : Substitution des variables `###_PARAMETRE_###` dans les scripts
- **Dépendances** : `SEConfig` (facade existante)
- **Méthodes clés** :
  - `substitute(array $lines): array`
  - `getSubstitutionMap(): array` - Utilise `SEConfig::all()`

---

#### 2.3 Factory - À créer dans `App\Services\Scripts`

##### `ScriptExecutionContextFactory`
- **Responsabilité** : Construction du contexte d'exécution depuis une requête HTTP
- **Dépendances** : `MachineRepository`, `UserRepository`, `ParcRepository` (existants), `WpkgApplicationService`
- **Méthodes clés** :
  - `fromRequest(Request $request): ScriptExecutionContext`
  - `resolveMachine(string $machineName): ?MachineModel` - Via `MachineRepository`
  - `resolveUser(string $login, ?MachineModel $machine): ?LdapUser` - Via `UserRepository`
  - `resolveInstalledApplications(string $machineName): array` - Via `WpkgApplicationService`
- **Validation** : Utilise `HTMLPurifier` ou validation Laravel pour sanitizer les inputs

---

### 3. Domaine : Active Directory / LDAP (Architecture existante)

> **Note** : L'architecture LDAP est déjà en place avec LdapRecord. Il faut réutiliser les composants existants.

#### 3.1 LdapModels existants (à réutiliser)

##### `MachineModel` (existe dans `App\LdapModels`)
- Hérite de `LdapRecord\Models\ActiveDirectory\Computer`
- **Accesseurs sémantiques** : `$machine->name`, `$machine->hostname`, `$machine->ip_address`, `$machine->parcs`, `$machine->status`
- **Méthodes** : `findByName()`, `findByHostname()`, `findByIp()`, `salle()`, `parcs()`

##### `DeviceGroupTagModel` (existe - représente les parcs/groupes)
- Hérite de `LdapRecord\Models\ActiveDirectory\Group`
- **Accesseurs** : `$parc->name`, `$parc->description`, `$parc->machine_names`, `$parc->machine_count`
- **Méthodes** : `machines()`, `associatedGroup()`, `toBusinessObject()`

##### `DeviceGroupModel` (existe - représente les salles/OUs)
- Hérite de `LdapRecord\Models\ActiveDirectory\OrganizationalUnit`
- Représente les OUs dans la branche Computers

##### `LdapUser` (existe)
- **Accesseurs** : `$user->login`, `$user->fullname`, `$user->groups`, `$user->account_status`
- **Méthodes** : `isAdmin()`, `isEleve()`, `isProf()`, `toBusinessObject()`

#### 3.2 Repositories existants (à réutiliser/étendre)

##### `MachineRepository` (existe dans `App\Repositories`)
- **Méthodes existantes** : `findByName()`, `findByHostname()`, `findByIp()`, `findByMac()`, `search()`, `findActive()`, `findByParc()`
- **À ajouter pour WPKG** :
  - `findWithParcsAndGroups(string $name): ?MachineModel` - Retourne machine avec ses parcs chargés
  - `listAllWithUuid(): Collection` - Pour la synchronisation WPKG

##### `ParcRepository` (existe dans `App\Repositories`)
- **Méthodes existantes** : `findByName()`, `findBySamAccountName()`, `search()`, `all()`, `findWithMachines()`
- **À ajouter pour WPKG** :
  - `findByUuid(string $uuid): ?DeviceGroupTagModel`
  - `getHierarchy(string $parcName): array` - Hiérarchie parent/enfant

##### `UserRepository` (existe dans `App\Repositories`)
- **Méthodes existantes** : `findByLogin()`, `findByEmail()`, `search()`, `findByType()`
- **À ajouter pour scripts** :
  - `findWithGroups(string $login): ?LdapUser` - Avec groupes pré-chargés

#### 3.3 Services existants (à réutiliser/étendre)

##### `ParcService` (existe dans `App\Services`)
- Gestion complète des parcs avec `getGroupsWithTags()`
- **À étendre** pour la synchronisation WPKG

##### `MachineService` (existe dans `App\Services`)
- **À étendre** pour les opérations WPKG

##### `RightsService` (existe dans `App\Services`)
- Gestion des droits utilisateurs
- **Méthodes utiles** : vérification des droits admin, délégations

#### 3.4 Types/DTOs existants (à réutiliser)

##### `App\Types\Parc`
- Objet métier représentant un parc
- Retourné par `DeviceGroupTagModel::toBusinessObject()`

##### `App\Types\User`
- Objet métier représentant un utilisateur
- Retourné par `LdapUser::toBusinessObject()`

---

### 4. Domaine : GPO

#### 4.1 Services

##### `GpoService`
- **Responsabilité** : Gestion des GPO dans Active Directory
- **Méthodes clés** :
  - `listGpos(): Collection`
  - `importGpo(string $gpoName, string $displayName, bool $force = false): bool`
  - `updateGpoVersion(array $gpo, int $version)`
  - `linkGpoToOu(string $gpoGuid, string $ouDn): bool`

##### `GpoTemplateService`
- **Responsabilité** : Gestion des templates de GPO (extraction, personnalisation)
- **Méthodes clés** :
  - `extractTemplate(string $archivePath, string $displayName): string` - Retourne le chemin temporaire
  - `specializeGpo(string $tmpPath, array $config)` - Substitue les variables
  - `readPolFile(string $path): array`
  - `writePolFile(string $path, array $entries)`

##### `SysvolService`
- **Responsabilité** : Opérations sur le partage SYSVOL (via smbclient)
- **Méthodes clés** :
  - `putGpo(array $gpo, string $sourcePath): bool`
  - `getGpoContent(string $gpoGuid): array`

---

### 5. Controllers / Endpoints

#### 5.1 API WPKG (clients Windows) - À créer dans `App\Http\Controllers\Wpkg`

##### `PackagesXmlController`
- **Route** : `GET /wpkg/packages.xml` (ou `/wpkg/packages_xml_out.php` pour compatibilité)
- **Responsabilité** : Génère le XML des paquets disponibles pour un client WPKG
- **Dépendances** : `WpkgPackagesXmlService`
- **Flow** :
  1. Récupère les applications actives via le service
  2. Génère le XML avec substitution des variables
  3. Retourne `Response` avec `Content-Type: text/xml`

##### `ProfilesXmlController`
- **Route** : `GET /wpkg/profiles.xml?poste={nom}` (ou `/wpkg/profiles_xml_out.php`)
- **Responsabilité** : Génère le profil WPKG d'une machine
- **Dépendances** : `WpkgProfilesXmlService`
- **Flow** :
  1. Valide le paramètre `poste`
  2. Génère le XML via `WpkgProfilesXmlService::generateForPoste()`
  3. Retourne `Response` avec `Content-Type: text/xml`

#### 5.2 API Scripts d'Applications - À créer dans `App\Http\Controllers\Gpo`

##### `ApplicationScriptsController`
- **Route** : `POST /gpo/applications` (ou `/gpo/applications.php` pour compatibilité)
- **Responsabilité** : Génère les scripts d'applications pour une machine/utilisateur
- **Dépendances** : `ScriptExecutionContextFactory`, `ScriptGeneratorService`, `StatsService` (existant)
- **Flow** :
  1. Valide les inputs (machine, user, action, os, interpreter)
  2. Construit le contexte via `ScriptExecutionContextFactory::fromRequest()`
  3. Si `ret=0` : log fin d'exécution, invalide cache, retourne vide
  4. Sinon : génère via `ScriptGeneratorService::generate()`
  5. Log la connexion via `StatsService`
  6. Retourne le script pour l'interpréteur demandé (text/plain)

#### 5.3 API Administration - À créer dans `App\Http\Controllers\Admin\Wpkg`

> Suivre le pattern existant de `App\Http\Controllers\Admin\ParcController`

##### `WpkgApplicationsController`
- **Routes** : Resource CRUD sur `/admin/wpkg/applications`
- **Responsabilité** : Gestion des applications WPKG
- **Méthodes** : `index`, `show`, `store`, `update`, `destroy`, `assignToParc`, `assignToPoste`

##### `WpkgParcsController`
- **Routes** : Resource sur `/admin/wpkg/parcs`
- **Responsabilité** : Gestion des parcs WPKG et leurs applications

##### `WpkgDepotsController`
- **Routes** : `/admin/wpkg/depots/*`
- **Responsabilité** : Gestion des dépôts et import d'applications
- **Méthodes** : `index`, `show`, `listApplications`, `importApplications`

##### `WpkgSyncController`
- **Route** : `POST /admin/wpkg/sync`
- **Responsabilité** : Déclenche la synchronisation AD ↔ MySQL
- **Dépendances** : `WpkgSyncService`

---

### 6. Jobs / Commands

#### `SyncWpkgFromAdJob`
- **Responsabilité** : Synchronisation périodique AD ↔ MySQL (cron)
- **Utilise** : `WpkgSyncService`

#### `ImportDepotApplicationsCommand`
- **Responsabilité** : Import batch d'applications depuis un dépôt
- **Utilise** : `DepotImportService`

---

## Flows principaux à implémenter

### Flow 1 : Requête d'un client WPKG pour packages.xml

```
Client WPKG → GET /wpkg/packages.xml
    ↓
PackagesXmlController::__invoke()
    ↓
WpkgPackagesXmlService::generate()
    ├── WpkgApplicationRepository::findActive()
    ├── Chargement packages.xml maître
    ├── Filtrage par applications actives
    └── VariableSubstitutionService::substitute()
    ↓
Response (Content-Type: text/xml)
```

### Flow 2 : Génération de scripts au startup/logon

```
GPO Windows → POST /gpo/applications (machine, user, action, os)
    ↓
ApplicationScriptsController::__invoke()
    ↓
ScriptExecutionContextFactory::fromRequest()
    ├── MachineRepository::findByName()        [LdapModels existant]
    ├── UserRepository::findByLogin()          [LdapModels existant]
    ├── $machine->parcs                        [Accesseur sémantique]
    └── WpkgApplicationService::getApplicationsForPoste()
    ↓
ScriptGeneratorService::generate(context)
    ├── ScriptReaderService::readAll()
    ├── ScriptFilterService::filter(scripts, context)
    ├── Generators\LocalAdminScriptGenerator
    ├── Generators\RedirectScriptGenerator
    ├── Generators\OnceScriptGenerator
    └── VariableSubstitutionService::substitute()
    ↓
CacheService::put("scripts.{$id}", $output)   [Service existant]
    ↓
Response (text/plain) → script cmd/bash/powershell
```

### Flow 3 : Synchronisation WPKG ↔ AD

```
Cron ou Admin → SyncWpkgFromAdJob / WpkgSyncController
    ↓
WpkgSyncService::sync()
    │
    ├── syncParcs()
    │   ├── ParcRepository::all()              [LdapModels existant]
    │   ├── WpkgParcRepository::all()          [Eloquent]
    │   └── Comparaison par UUID → Insert/Update/Delete
    │
    ├── syncPostes()
    │   ├── MachineRepository::findActive()    [LdapModels existant]
    │   ├── WpkgPosteRepository::all()         [Eloquent]
    │   └── Comparaison par UUID → Insert/Update/Delete (gestion conflits)
    │
    └── syncMembership()
        └── Mise à jour table parc_profile
    ↓
SyncResult (created, updated, deleted, errors)
```

### Flow 4 : Import d'application depuis un dépôt

```
Admin → POST /admin/wpkg/depots/{id}/import (app_ids[])
    ↓
WpkgDepotsController::importApplications()
    ↓
WpkgDepotImportService::importApplication()
    ├── Téléchargement XML recette (GuzzleHttp)
    ├── verifyFileIntegrity(sha512)
    ├── Parsing XML (DOMDocument)
    ├── downloadInstallationFiles()
    │   └── verifyFileIntegrity(md5/sha256)
    ├── WpkgApplicationRepository::delete(oldVersion)
    └── WpkgApplicationRepository::create(newVersion)
    ↓
ImportResult (success, errors, downloaded_files)
```

---

## Points d'attention pour la migration

### Intégration avec l'existant Laravel
- **LdapRecord** : Réutiliser `MachineModel`, `DeviceGroupTagModel`, `LdapUser` avec leurs accesseurs sémantiques
- **Repositories** : Étendre `MachineRepository`, `ParcRepository`, `UserRepository` existants si nécessaire
- **Services** : Réutiliser `CacheService`, `RightsService`, `StatsService` existants
- **Facades** : Utiliser `SEConfig` pour la configuration SambaEdu
- **Types** : Suivre le pattern de `App\Types\User` et `App\Types\Parc` pour les nouveaux DTOs

### Compatibilité legacy
- Les endpoints `/wpkg/packages_xml_out.php` et `/wpkg/profiles_xml_out.php` doivent rester fonctionnels pendant la migration
- Ajouter des routes Laravel qui redirigent ou servent les mêmes URLs
- Le format XML généré doit être strictement identique (clients WPKG existants)
- Les scripts générés doivent produire le même output (syntaxe CMD/Bash/PowerShell)

### Cache
- Le système legacy utilise APCu pour le cache des scripts et profils WPKG
- **Recommandation** : Utiliser `CacheService` existant qui abstrait le driver de cache Laravel
- Clés de cache : `scripts.{id}`, `wpkg_poste_{nom}`, `wpkg_statut_{hash}`

### Filesystem
- Les scripts sont lus depuis `/usr/share/sambaedu/applications/` et `/etc/sambaedu/applications/`
- **Recommandation** : Configurer via `config/sambaedu.php` :
  ```php
  'scripts' => [
      'paths' => [
          'package' => '/usr/share/sambaedu/applications/',
          'local' => '/etc/sambaedu/applications/',
      ],
  ],
  ```

### LDAP
- ✅ LdapRecord est déjà configuré et utilisé
- Les accesseurs sémantiques masquent la complexité LDAP
- Les requêtes LDAP sont critiques pour les performances → utiliser le cache

### Sécurité
- HTMLPurifier est utilisé dans le legacy pour sanitizer les inputs
- **Recommandation** : Utiliser les Form Requests Laravel avec validation stricte
- Exemple : `ApplicationScriptsRequest` avec rules pour `machine`, `user`, `action`, `os`

---

## Estimation de complexité

| Composant | Complexité | Priorité | Dépendances existantes |
|-----------|------------|----------|------------------------|
| Models Eloquent WPKG | Moyenne | Haute | - |
| Repositories WPKG | Moyenne | Haute | - |
| WpkgApplicationService | Moyenne | Haute | WpkgRepositories |
| WpkgSyncService | Haute | Haute | MachineRepository, ParcRepository (existants) |
| WpkgPackagesXmlService | Moyenne | Haute | WpkgApplicationRepository |
| ScriptGeneratorService | Très haute | Haute | Tous les Generators |
| ScriptReaderService | Moyenne | Haute | - |
| ScriptExecutionContextFactory | Haute | Haute | MachineRepository, UserRepository (existants) |
| Generators (Redirect, Admin...) | Haute | Moyenne | RightsService (existant) |
| WpkgDepotImportService | Moyenne | Moyenne | GuzzleHttp |
| GpoService | Haute | Basse | - |
| Controllers API | Basse | Haute | Services |
| Controllers Admin | Moyenne | Moyenne | Services, Livewire ? |

---

## Structure de fichiers proposée

```
app/
├── Models/
│   └── Wpkg/
│       ├── WpkgApplication.php
│       ├── WpkgPoste.php
│       ├── WpkgParc.php
│       ├── WpkgDepot.php
│       ├── WpkgDepotApplication.php
│       └── WpkgPosteApp.php
├── Repositories/
│   └── Wpkg/
│       ├── WpkgApplicationRepository.php
│       ├── WpkgPosteRepository.php
│       ├── WpkgParcRepository.php
│       └── WpkgDepotRepository.php
├── Services/
│   ├── Wpkg/
│   │   ├── WpkgApplicationService.php
│   │   ├── WpkgSyncService.php
│   │   ├── WpkgPackagesXmlService.php
│   │   ├── WpkgProfilesXmlService.php
│   │   └── WpkgDepotImportService.php
│   └── Scripts/
│       ├── ScriptGeneratorService.php
│       ├── ScriptReaderService.php
│       ├── ScriptFilterService.php
│       ├── ScriptExecutionContextFactory.php
│       ├── VariableSubstitutionService.php
│       └── Generators/
│           ├── RedirectScriptGenerator.php
│           ├── LocalAdminScriptGenerator.php
│           ├── OnceScriptGenerator.php
│           └── ...
├── Types/
│   ├── ApplicationScript.php
│   ├── RedirectConfig.php
│   ├── ScriptExecutionContext.php
│   └── ScriptOutput.php
├── Constants/
│   └── Scripts/
│       ├── ScriptAction.php (enum)
│       ├── ScriptContext.php (enum)
│       ├── OperatingSystem.php (enum)
│       └── Interpreter.php (enum)
└── Http/Controllers/
    ├── Wpkg/
    │   ├── PackagesXmlController.php
    │   └── ProfilesXmlController.php
    ├── Gpo/
    │   └── ApplicationScriptsController.php
    └── Admin/Wpkg/
        ├── WpkgApplicationsController.php
        ├── WpkgParcsController.php
        ├── WpkgDepotsController.php
        └── WpkgSyncController.php
```

---

## Prochaines étapes recommandées

1. **Phase 1** : Créer les Models Eloquent WPKG et leurs Migrations (tables existantes)
2. **Phase 2** : Créer les Repositories WPKG (`WpkgApplicationRepository`, etc.)
3. **Phase 3** : Implémenter `WpkgApplicationService` et `WpkgSyncService`
4. **Phase 4** : Implémenter les endpoints WPKG (`PackagesXmlController`, `ProfilesXmlController`)
5. **Phase 5** : Créer les Types/DTOs pour les scripts (`ApplicationScript`, `ScriptExecutionContext`)
6. **Phase 6** : Implémenter `ScriptReaderService` et `ScriptFilterService`
7. **Phase 7** : Implémenter `ScriptGeneratorService` avec les Generators spécialisés
8. **Phase 8** : Implémenter `ApplicationScriptsController` (endpoint `/gpo/applications`)
9. **Phase 9** : Migrer l'interface d'administration (Livewire ?)
10. **Phase 10** : Implémenter `WpkgDepotImportService`


