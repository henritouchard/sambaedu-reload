# Modèles LdapRecord avec Accesseurs Sémantiques

## Vue d'ensemble

Les modèles LdapRecord ont été enrichis avec des **accesseurs sémantiques** qui masquent complètement la complexité LDAP. Vous pouvez maintenant utiliser des noms de propriétés explicites (`login`, `fullname`, `firstname`) au lieu des noms techniques LDAP (`cn`, `displayname`, `givenname`).

## Utilisation directe des modèles

### Utilisateur (LdapUser)

```php
use App\LdapModels\LdapUser;

// Recherche par login
$ldapUser = LdapUser::findByLogin('jdupont');

// Accès aux propriétés sémantiques (plus besoin de connaître LDAP !)
$login = $ldapUser->login;              // Au lieu de $ldapUser->getAttribute('cn')
$fullname = $ldapUser->fullname;         // Au lieu de $ldapUser->getAttribute('displayname')
$firstname = $ldapUser->firstname;       // Au lieu de $ldapUser->getAttribute('givenname')
$lastname = $ldapUser->lastname;         // Au lieu de $ldapUser->getAttribute('sn')
$email = $ldapUser->email;               // Au lieu de $ldapUser->getAttribute('mail')
$phonenumber = $ldapUser->phonenumber;   // Au lieu de $ldapUser->getAttribute('telephonenumber')
$groups = $ldapUser->groups;             // Tableau de noms de groupes (pas de DN !)
$accountStatus = $ldapUser->account_status; // 'active' ou 'disabled'
$establishmentCode = $ldapUser->establishment_code; // Code UAI
$uniqueId = $ldapUser->unique_id;        // GUID formaté

// Méthodes métier
if ($ldapUser->isAdmin()) { ... }
if ($ldapUser->isEleve()) { ... }
if ($ldapUser->isProf()) { ... }
if ($ldapUser->isActive()) { ... }

// Conversion vers objet métier
$user = $ldapUser->toBusinessObject(); // Retourne App\Types\User
```

### Machine (MachineModel)

```php
use App\LdapModels\MachineModel;

// Recherche par nom
$machine = MachineModel::findByName('SALLE-INFO-101');

// Accès aux propriétés sémantiques
$name = $machine->name;                  // Nom de la machine
$hostname = $machine->hostname;          // Hostname (sans $)
$ipAddress = $machine->ip_address;       // Adresse IP
$macAddress = $machine->mac_address;     // Adresse MAC
$operatingSystem = $machine->operating_system; // OS
$operatingSystemVersion = $machine->operating_system_version; // Version OS
$location = $machine->location;          // Emplacement
$description = $machine->description;    // Description
$status = $machine->status;              // 'active' ou 'disabled'
$parcs = $machine->parcs;                // Tableau de noms de parcs

// Méthodes métier
if ($machine->isActive()) { ... }
```

### Parc (DeviceGroupTagModel)

```php
use App\LdapModels\DeviceGroupTagModel;

// Recherche par nom
$parc = DeviceGroupTagModel::where('cn', '=', 'SALLE-INFO-101')->first();

// Accès aux propriétés sémantiques
$name = $parc->name;                     // Nom du parc
$description = $parc->description;      // Description
$samAccountName = $parc->sam_account_name; // SamAccountName
$machineNames = $parc->machine_names;    // Tableau de noms de machines
$machineCount = $parc->machine_count;    // Nombre de machines

// Récupérer les machines membres
$machines = $parc->machines(); // Collection de MachineModel
```

## Utilisation via les Repositories (Recommandé)

Les repositories masquent complètement LDAP et retournent uniquement des objets métier :

### UserRepository

```php
use App\Repositories\UserRepository;

$userRepo = app(UserRepository::class);

// Recherche par login
$user = $userRepo->findByLogin('jdupont');
// $user est un App\Types\User, pas un modèle LDAP !

// Recherche par email
$user = $userRepo->findByEmail('jean.dupont@example.com');

// Recherche
$users = $userRepo->search('dupont', limit: 20);

// Utilisateurs d'un établissement
$users = $userRepo->findByEstablishment('0751234a');

// Utilisateurs actifs
$users = $userRepo->findActive();

// Utilisateurs par type
$eleves = $userRepo->findByType('eleve');
$profs = $userRepo->findByType('prof');
$administratifs = $userRepo->findByType('administratif');
```

### MachineRepository

```php
use App\Repositories\MachineRepository;

$machineRepo = app(MachineRepository::class);

// Recherche par nom
$machine = $machineRepo->findByName('SALLE-INFO-101');

// Recherche par hostname
$machine = $machineRepo->findByHostname('salle-info-101');

// Recherche par IP
$machine = $machineRepo->findByIp('192.168.1.100');

// Recherche
$machines = $machineRepo->search('salle-info');

// Machines actives
$machines = $machineRepo->findActive();

// Machines d'un parc
$machines = $machineRepo->findByParc('SALLE-INFO-101');
```

### ParcRepository

```php
use App\Repositories\ParcRepository;

$parcRepo = app(ParcRepository::class);

// Recherche par nom
$parc = $parcRepo->findByName('SALLE-INFO-101');

// Recherche
$parcs = $parcRepo->search('salle');

// Tous les parcs
$parcs = $parcRepo->all();

// Parcs avec machines
$parcs = $parcRepo->findWithMachines();
```

## Mapping des propriétés

### Utilisateur

| Propriété sémantique | Attribut LDAP | Description |
|---------------------|---------------|-------------|
| `login` | `cn` | Login utilisateur |
| `fullname` | `displayname` | Nom complet |
| `firstname` | `givenname` | Prénom |
| `lastname` | `sn` | Nom de famille |
| `email` | `mail` | Email |
| `phonenumber` | `telephonenumber` | Téléphone |
| `description` | `description` | Description |
| `establishment_code` | (extrait du DN) | Code UAI |
| `groups` | `memberof` | Noms de groupes (pas DN) |
| `account_status` | `useraccountcontrol` | 'active' ou 'disabled' |
| `unique_id` | `objectguid` | GUID formaté |

### Machine

| Propriété sémantique | Attribut LDAP | Description |
|---------------------|---------------|-------------|
| `name` | `cn` | Nom de la machine |
| `hostname` | `samaccountname` | Hostname (sans $) |
| `ip_address` | `iphostnumber` | Adresse IP |
| `mac_address` | `networkaddress` | Adresse MAC |
| `operating_system` | `operatingsystem` | Système d'exploitation |
| `operating_system_version` | `operatingsystemversion` | Version OS |
| `location` | `location` | Emplacement |
| `description` | `description` | Description |
| `status` | `useraccountcontrol` | 'active' ou 'disabled' |
| `parcs` | `memberof` | Noms de parcs (pas DN) |

### Parc

| Propriété sémantique | Attribut LDAP | Description |
|---------------------|---------------|-------------|
| `name` | `cn` | Nom du parc |
| `description` | `description` | Description |
| `sam_account_name` | `samaccountname` | SamAccountName |
| `machine_names` | `member` | Noms de machines (pas DN) |
| `machine_count` | (calculé) | Nombre de machines |

## Avantages

1. **Code plus lisible** : `$user->login` est plus clair que `$user->getAttribute('cn')`
2. **Pas besoin de connaître LDAP** : Les développeurs peuvent travailler sans comprendre les attributs LDAP
3. **Type-safety** : Les accesseurs retournent des types explicites
4. **Abstraction complète** : Les repositories masquent complètement LDAP
5. **Maintenabilité** : Si les attributs LDAP changent, seul le modèle est à modifier

## Migration depuis le code legacy

### Avant (code legacy)

```php
$user = search_user($config, $login);
$userLogin = $user['cn'];
$userFullname = $user['displayname'];
$userGroups = array_map(function($dn) {
    preg_match('/^CN=([^,]+),/', $dn, $m);
    return $m[1];
}, $user['memberof'] ?? []);
```

### Après (avec LdapRecord + accesseurs sémantiques)

```php
// Option 1 : Modèle direct
$ldapUser = LdapUser::findByLogin($login);
$userLogin = $ldapUser->login;
$userFullname = $ldapUser->fullname;
$userGroups = $ldapUser->groups; // Déjà extraits !

// Option 2 : Repository (recommandé)
$user = $userRepo->findByLogin($login);
$userLogin = $user->login;
$userFullname = $user->fullname;
$userGroups = $user->groups;
```

## Notes importantes

- Les accesseurs magiques (`$user->login`) fonctionnent grâce aux méthodes `getLoginAttribute()`
- Les groupes retournés par `$user->groups` sont des **noms simples**, pas des DN
- Pour obtenir les DN si nécessaire, utilisez `$user->getGroupDns()`
- La méthode `toBusinessObject()` convertit le modèle LDAP en `DataObject` métier
- Les repositories retournent toujours des objets métier, jamais des modèles LDAP directement

