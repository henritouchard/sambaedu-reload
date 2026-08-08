# Group Policy Objects (GPO) dans SambaEdu

## Table des matières

1. [Introduction aux GPO](#introduction-aux-gpo)
2. [Architecture technique](#architecture-technique)
3. [Le partage SYSVOL](#le-partage-sysvol)
4. [Lien entre AD et GPO](#lien-entre-ad-et-gpo)
5. [Gestion legacy dans SE4](#gestion-legacy-dans-se4)
6. [Interactions avec d'autres composants](#interactions-avec-dautres-composants)
7. [Nouveau mode de gestion (à développer)](#nouveau-mode-de-gestion-à-développer)

---

## Introduction aux GPO

### Qu'est-ce qu'une GPO ?

Une **Group Policy Object (GPO)** est un ensemble de paramètres de configuration qui s'appliquent automatiquement aux utilisateurs et/ou ordinateurs d'un domaine Active Directory. Les GPO permettent de :

- **Configurer les postes Windows** : fond d'écran, proxy, lecteurs réseau, etc.
- **Déployer des logiciels** : via WPKG ou autres mécanismes
- **Appliquer des restrictions de sécurité** : désactivation UAC, verrouillage de fonctionnalités
- **Configurer des applications** : Firefox, Thunderbird, etc.
- **Gérer les imprimantes** : déploiement automatique selon les salles

### Types de paramètres GPO

| Type | Cible | Description |
|------|-------|-------------|
| **Machine** | Ordinateur | S'applique au démarrage du poste, avant connexion utilisateur |
| **User** | Utilisateur | S'applique à la connexion de l'utilisateur |

### Formats de fichiers GPO

| Extension | Type | Description |
|-----------|------|-------------|
| `Registry.pol` | Binaire | Paramètres de registre Windows (format PReg) |
| `*.xml` | XML | Group Policy Preferences (GPP) - raccourcis, imprimantes, etc. |
| `GPT.INI` | INI | Métadonnées de la GPO (version, nom) |
| `GptTmpl.inf` | INI | Paramètres de sécurité (SecEdit) |

---

## Architecture technique

### Structure d'une GPO

Une GPO est composée de **deux parties** :

#### 1. Objet AD (Group Policy Container - GPC)
Stocké dans l'annuaire Active Directory :
```
CN={GUID},CN=Policies,CN=System,DC=domain,DC=local
```

Attributs principaux :
- `cn` : GUID unique de la GPO (ex: `{A1B2C3D4-E5F6-...}`)
- `displayName` : Nom lisible (ex: "proxy", "wpkg")
- `versionNumber` : Numéro de version (incrémenté à chaque modification)
- `gPCMachineExtensionNames` : Extensions CSE pour la partie Machine
- `gPCUserExtensionNames` : Extensions CSE pour la partie User
- `gPCFileSysPath` : Chemin vers les fichiers dans SYSVOL

#### 2. Fichiers (Group Policy Template - GPT)
Stockés dans le partage SYSVOL :
```
\\domain\sysvol\domain\Policies\{GUID}\
├── GPT.INI
├── Machine/
│   ├── Registry.pol
│   └── Preferences/
│       ├── Registry/Registry.xml
│       └── Printers/Printers.xml
└── User/
    ├── Registry.pol
    └── Preferences/
        ├── Shortcuts/Shortcuts.xml
        └── Registry/Registry.xml
```

### Numéro de version

Le `versionNumber` est un entier 32 bits encodant deux compteurs :
- **16 bits hauts** : Version User (incrémenté si modification User)
- **16 bits bas** : Version Machine (incrémenté si modification Machine)

```php
// Calcul de la version
$version = $user_version * 0x10000 + $machine_version;

// Extraction des versions
$user_version = floor($version / 0x10000);
$machine_version = $version % 0x10000;
```

### Client-Side Extensions (CSE)

Les CSE sont des GUID identifiant le type de paramètres :
```
[{35378EAC-683F-11D2-A89A-00C04FBBCFA2}{D02B1F72-3407-48AE-BA88-E8213C6761F1}]
```

Exemples de CSE courants :
- `{35378EAC-...}` : Paramètres de registre
- `{42B5FAAE-...}` : Scripts
- `{827D319E-...}` : Sécurité
- `{8A28E2C5-...}` : Imprimantes

---

## Le partage SYSVOL

### Définition

**SYSVOL** (System Volume) est un partage réseau spécial sur les contrôleurs de domaine AD contenant :
- Les fichiers des GPO
- Les scripts de connexion/déconnexion
- Les politiques de sécurité

### Emplacement physique

Sur un serveur Samba AD :
```
/var/lib/samba/sysvol/domain.local/
├── Policies/
│   ├── {GUID1}/
│   ├── {GUID2}/
│   └── ...
└── scripts/
```

### Réplication SYSVOL

**Point critique** : Dans une architecture multi-AD (SE4AD central + SE4AD établissements), le SYSVOL est **répliqué** depuis l'AD central (master) vers les AD locaux.

```
┌─────────────────┐
│   SE4AD Central │  ← MASTER (écriture)
│   (sysvol)      │
└────────┬────────┘
         │ Réplication
    ┌────┴────┐
    ▼         ▼
┌───────┐ ┌───────┐
│SE4AD-1│ │SE4AD-2│  ← REPLICAS (lecture seule)
│(etab) │ │(etab) │
└───────┘ └───────┘
```

**Conséquence importante** : Pour **écrire** des GPO, il faut obligatoirement écrire sur le **master**, sinon les modifications seraient écrasées par la réplication.

### Accès au SYSVOL

Via SMB avec authentification Kerberos :
```bash
smbclient "//se4ad.domain.local/sysvol" --use-kerberos=required
```

### ACL SYSVOL

Les GPO ont des ACL spécifiques définies en SDDL :
```
O:DAG:DAD:P(A;OICI;FA;;;DA)(A;OICI;FA;;;EA)(A;OICIIO;FA;;;CO)
(A;OICI;FA;;;DA)(A;OICI;FA;;;SY)(A;OICI;0x1200a9;;;AU)(A;OICI;0x1200a9;;;ED)
```

Signification :
- `DA` : Domain Admins - Full Access
- `EA` : Enterprise Admins - Full Access
- `SY` : SYSTEM - Full Access
- `AU` : Authenticated Users - Read/Execute
- `ED` : Enterprise Domain Controllers - Read/Execute

---

## Lien entre AD et GPO

### Liaison (Link) des GPO

Une GPO n'a d'effet que si elle est **liée** à un conteneur AD :
- **Domaine** : S'applique à tous les objets du domaine
- **OU (Organizational Unit)** : S'applique aux objets de l'OU
- **Site** : S'applique aux objets du site AD

```
DC=domain,DC=local                    ← GPO "Default Domain Policy"
├── OU=People                         ← GPO "redirections"
│   ├── OU=Eleves                     ← GPO "restrictions élèves"
│   └── OU=Profs
├── OU=Computers
│   └── OU=Salles
│       ├── OU=Salle-Info-1           ← GPO "imprimantes salle 1"
│       └── OU=Salle-Info-2           ← GPO "imprimantes salle 2"
└── OU=Parcs
    └── OU=Parc-Windows               ← GPO "wpkg", "proxy"
```

### Ordre d'application (LSDOU)

Les GPO s'appliquent dans l'ordre :
1. **L**ocal (GPO locale du poste)
2. **S**ite
3. **D**omaine
4. **OU** (de la plus haute à la plus basse)

Les paramètres des GPO appliquées en dernier **écrasent** les précédents.

### Héritage et blocage

- **Héritage** : Par défaut, les GPO des conteneurs parents s'appliquent aux enfants
- **Blocage** : Une OU peut bloquer l'héritage (`gposetinheritance`)
- **Forçage** : Une GPO peut être "enforced" pour ignorer le blocage

### Attribut gpLink

L'attribut `gpLink` d'une OU contient les liens vers les GPO :
```
[LDAP://CN={GUID},CN=Policies,CN=System,DC=domain,DC=local;0]
```

Le chiffre final indique les options :
- `0` : Lien normal
- `1` : Lien désactivé
- `2` : Lien enforced

---

## Gestion legacy dans SE4

### Fichiers principaux

| Fichier | Rôle |
|---------|------|
| `includes/gpo.inc.php` | Fonctions de manipulation des GPO (1356 lignes) |
| `includes/samba-tool.inc.php` | Wrapper pour `samba-tool gpo *` |
| `includes/delegations.inc.php` | Gestion des délégations GPO par salle |
| `gpo/gestion_gpo.php` | Interface d'import/export GPO |
| `gpo/gpo-maj.php` | Mise à jour des GPO depuis templates |

### Fonctions principales (gpo.inc.php)

#### Lecture/Écriture de fichiers .pol

```php
// Lire un fichier Registry.pol
$gpo = read_pol($file);
// Retourne un tableau de clés de registre

// Écrire un fichier Registry.pol
write_pol($file, $gpo);
```

Structure d'une clé :
```php
[
    'key' => 'Software\Policies\Mozilla\Firefox',
    'value' => 'Homepage',
    'type' => REG_SZ,  // 1 = string, 4 = DWORD
    'data' => 'https://example.com'
]
```

#### Import/Export de GPO

```php
// Importer une GPO depuis un template
import_gpo($config, $displayname, $gpo_archive, $update, $force);

// Exporter une GPO vers une archive
export_gpo($config, $displayname, $export);

// Supprimer une GPO
delete_gpo($config, $gpo);
```

#### Lecture/Écriture SYSVOL

```php
// Lire un fichier depuis SYSVOL
$data = read_gpo_sysvol($config, $gpo, MACHINE_GPO);

// Écrire dans SYSVOL
update_gpo_sysvol($config, $gpo, MACHINE_GPO, $data, $commit);

// Pousser une arborescence complète
sysvol_put($config, $gpo, $source_path, $message);
```

#### Généralisation/Spécialisation

Les GPO templates utilisent des placeholders :
```
###_DOMAIN_###           → domain.local
###_SAMBA_DOMAIN_###     → DOMAIN
###_SE4FS_NAME_###       → se4fs
###_SE4AD_NAME_###       → se4ad
###_DOMAIN_SID_###       → S-1-5-21-...
###_LDAP_BASE_DN_###     → DC=domain,DC=local
```

```php
// Remplacer les valeurs par des placeholders (export)
generalise_gpo($config, $source_path);

// Remplacer les placeholders par les valeurs (import)
specialise_gpo($config, $source_path);
```

### Fonctions principales (samba-tool.inc.php)

```php
// Créer une GPO
$uuid = gpocreate($config, $displayname, $msg);

// Supprimer une GPO
gpodel($config, $gpo_uuid);

// Lier une GPO à une OU
gposetlink($config, $container_dn, $gpo_uuid, $enforce, $disable);

// Supprimer un lien
gpodellink($config, $container_dn, $gpo_uuid);

// Lister les GPO d'un utilisateur/machine
$gpos = gpolist($config, $cn);

// Lister les conteneurs liés à une GPO
$ous = gpolistcontainers($config, $gpo_uuid);

// Obtenir les liens d'une OU
$gpos = gpogetlink($config, $container_dn);

// Gérer l'héritage
gposetinheritance($config, $container_dn, $inherit);
$status = gpogetinheritance($config, $container_dn);
```

### Templates GPO

Les templates sont stockés dans `/usr/share/sambaedu/gpo/` :
- `se4_*.zip` : Templates officiels du paquet
- `etab_*.zip` : Templates exportés par l'établissement
- `sambaedu-gpo/` : Dépôt Git des templates

```php
// Lister les templates disponibles
$templates = list_gpo_templates();
$templates = list_gpo_templates_git($config);  // Depuis GitLab
$templates = list_gpo_templates_etab();        // Exports locaux

// Vérifier les mises à jour
check_gpo_templates($config);
```

### Accès au SYSVOL Master

**Important** : Les fonctions qui **écrivent** sur SYSVOL utilisent `$master = true` :

```php
// Lecture depuis sysvol (peut être local ou master)
exec('smbclient "//' . ad_url($config, "dns", true) . '/sysvol" ...');
//                                          ^^^^
//                                          $master = true

// Fonctions concernées :
// - gpo_get_content() : Lecture GPO
// - gpo_get_file() : Récupération fichier
// - sysvol_acl_reset() : Reset ACL
// - sysvol_write_master() : Écriture fichiers
```

La fonction `ad_url($config, "dns", true)` retourne l'URL de l'AD **central** (master) au lieu de l'AD local de l'établissement.

---

## Interactions avec d'autres composants

### 1. WPKG (Déploiement logiciels)

Les GPO configurent WPKG pour le déploiement automatique de logiciels :

```
GPO "wpkg"
├── Machine/Registry.pol
│   └── HKLM\Software\Policies\WPKG_GP
│       ├── WpkgCommand = "\\se4fs\netlogon\wpkg.js"
│       └── WpkgVerbosity = 1
└── Machine/Preferences/Registry/Registry.xml
```

**Fichiers liés** :
- `wpkg/*.php` : Interface de gestion WPKG
- `includes/applications.inc.php` : Fonctions WPKG

### 2. Imprimantes

Déploiement automatique d'imprimantes par GPO :

```php
// Structure GPP Imprimantes
define('USER_PRINTERS', [
    'type' => "gpp",
    'target' => "user",
    'path' => '/User/Preferences/Printers/',
    'file' => "Printers.xml",
    'clsid' => "{1F577D12-3D1B-471e-A1B7-060317597B9C}"
]);
```

**Fichiers liés** :
- `printers/*.php` : Interface de gestion imprimantes
- `includes/printers.inc.php` : Fonctions imprimantes

### 3. Proxy

Configuration automatique du proxy navigateur :

```php
// GPO "proxy" configure :
// - IE8/IE10 : ProxyServer, ProxyEnable, AutoConfigURL
// - Firefox : via policies.json ou Registry.pol
function change_proxy_file($config, $line);
function set_proxy_gpo($config);
```

### 4. Firefox/Thunderbird

Configuration des applications Mozilla :

**Fichiers liés** :
- `gpo/firefox.php` : Configuration Firefox
- `gpo/thunderbird_out.php` : Export config Thunderbird

### 5. Raccourcis Bureau

Création de raccourcis sur le bureau des utilisateurs :

```php
define('USER_SHORTCUTS', [
    'type' => "gpp",
    'target' => "user",
    'path' => '/User/Preferences/Shortcuts/',
    'file' => "Shortcuts.xml",
    'clsid' => "{872ECB34-B2EC-401b-A585-D32574AA90EE}"
]);
```

**Fichiers liés** :
- `gpo/shortcuts.php` : Interface gestion raccourcis

### 6. Délégations (Administrateurs locaux)

Ajout de groupes aux administrateurs locaux des postes :

```php
// delegations.inc.php
add_delegation_policy($config, $delegation, $gpo, $type);
list_delegation_policies($config, $gpo);
```

### 7. Profils itinérants

Gestion des exclusions de profils itinérants :

**Fichiers liés** :
- `gpo/no_roam.php` : Exclusions de dossiers
- `gpo/del_roam.php` : Suppression profils

### 8. Scripts et API

Les GPO peuvent être manipulées via :
- **Interface web** : `gpo/gestion_gpo.php`
- **Cron** : Mise à jour automatique des templates
- **API** : Potentiellement via les contrôleurs Laravel

---

## Nouveau mode de gestion (à développer)

### Objectifs

1. **Interface moderne** : Gestion des GPO via l'interface Laravel/Livewire
2. **Abstraction** : Service Laravel encapsulant la logique GPO
3. **API REST** : Endpoints pour manipulation programmatique
4. **Audit** : Traçabilité des modifications GPO
5. **Templates** : Gestion simplifiée des templates GPO

### Architecture proposée

```
app/
├── Services/
│   └── SE4/
│       └── GpoService.php           # Service principal
├── Models/
│   └── Gpo.php                      # Modèle (cache/métadonnées)
├── Http/
│   └── Controllers/
│       └── Api/
│           └── v1/
│               └── GpoController.php
└── Livewire/
    └── Admin/
        └── Gpo/
            ├── GpoList.php          # Liste des GPO
            ├── GpoEditor.php        # Éditeur de GPO
            └── GpoTemplates.php     # Gestion templates
```

### GpoService - Méthodes proposées

```php
class GpoService
{
    // Lecture
    public function list(): Collection;
    public function get(string $uuid): ?GpoData;
    public function getLinks(string $uuid): array;
    public function getContainerGpos(string $containerDn): array;
    
    // Écriture
    public function create(string $name): string;
    public function delete(string $uuid): bool;
    public function link(string $uuid, string $containerDn, array $options = []): bool;
    public function unlink(string $uuid, string $containerDn): bool;
    
    // Contenu
    public function readRegistryPol(string $uuid, string $target): array;
    public function writeRegistryPol(string $uuid, string $target, array $data): bool;
    public function readPreference(string $uuid, string $type): mixed;
    public function writePreference(string $uuid, string $type, mixed $data): bool;
    
    // Templates
    public function listTemplates(): Collection;
    public function importTemplate(string $name, bool $force = false): bool;
    public function exportToTemplate(string $uuid): string;
    
    // Utilitaires
    public function incrementVersion(string $uuid, string $target): bool;
    public function syncFromMaster(): bool;
}
```

### API REST proposée

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/v1/gpo` | Liste des GPO |
| GET | `/api/v1/gpo/{uuid}` | Détails d'une GPO |
| POST | `/api/v1/gpo` | Créer une GPO |
| DELETE | `/api/v1/gpo/{uuid}` | Supprimer une GPO |
| GET | `/api/v1/gpo/{uuid}/links` | Liens de la GPO |
| POST | `/api/v1/gpo/{uuid}/links` | Ajouter un lien |
| DELETE | `/api/v1/gpo/{uuid}/links/{container}` | Supprimer un lien |
| GET | `/api/v1/gpo/{uuid}/registry/{target}` | Lire Registry.pol |
| PUT | `/api/v1/gpo/{uuid}/registry/{target}` | Écrire Registry.pol |
| GET | `/api/v1/gpo/templates` | Liste des templates |
| POST | `/api/v1/gpo/templates/import` | Importer un template |

### Interface Livewire proposée

#### Liste des GPO
- Tableau avec nom, version, liens, dernière modification
- Filtres par type (machine/user), par OU liée
- Actions : éditer, supprimer, exporter, dupliquer

#### Éditeur de GPO
- Onglets : Général, Machine, User, Liens
- Éditeur visuel des clés de registre
- Prévisualisation des modifications
- Historique des versions

#### Gestion des templates
- Liste des templates disponibles (officiels, établissement)
- Import/export en un clic
- Comparaison template vs GPO actuelle
- Mise à jour groupée

### Considérations techniques

#### Gestion du SYSVOL Master

Pour les environnements multi-établissements, le service doit :
1. Détecter si on est sur l'AD central ou un établissement
2. Router les écritures vers le master si nécessaire
3. Gérer la latence de réplication

```php
class GpoService
{
    private function getSysvolConnection(bool $write = false): SmbClient
    {
        $host = $write ? $this->getMasterAdHost() : $this->getLocalAdHost();
        return new SmbClient($host, 'sysvol');
    }
}
```

#### Cache et performances

- Mettre en cache la liste des GPO (invalidation sur modification)
- Mettre en cache les contenus fréquemment lus
- Utiliser des jobs pour les opérations longues (import massif)

#### Sécurité

- Vérifier les droits admin avant toute modification
- Logger toutes les actions GPO
- Valider les données avant écriture dans SYSVOL

### Prochaines étapes

1. [ ] Créer le `GpoService` avec les méthodes de base
2. [ ] Implémenter les endpoints API REST
3. [ ] Créer les composants Livewire pour l'interface
4. [ ] Ajouter les tests unitaires et d'intégration
5. [ ] Documenter l'API avec OpenAPI/Swagger
6. [ ] Migrer progressivement les pages legacy

---

## Glossaire

| Terme | Définition |
|-------|------------|
| **GPO** | Group Policy Object - Ensemble de paramètres de configuration |
| **GPT** | Group Policy Template - Fichiers de la GPO dans SYSVOL |
| **GPC** | Group Policy Container - Objet AD de la GPO |
| **CSE** | Client-Side Extension - Module Windows traitant un type de paramètre |
| **SYSVOL** | Partage réseau contenant les fichiers GPO et scripts |
| **SDDL** | Security Descriptor Definition Language - Format des ACL |
| **Registry.pol** | Fichier binaire contenant les paramètres de registre |
| **GPP** | Group Policy Preferences - Extensions XML (raccourcis, imprimantes...) |
| **LSDOU** | Local, Site, Domain, OU - Ordre d'application des GPO |

---

## Références

- [Microsoft GPO Documentation](https://docs.microsoft.com/en-us/previous-versions/windows/desktop/policy/group-policy-objects)
- [Samba Wiki - GPO](https://wiki.samba.org/index.php/Group_Policy)
- [Registry.pol Format](https://docs.microsoft.com/en-us/previous-versions/windows/desktop/policy/registry-policy-file-format)
- Code source SambaEdu : `includes/gpo.inc.php`, `includes/samba-tool.inc.php`
