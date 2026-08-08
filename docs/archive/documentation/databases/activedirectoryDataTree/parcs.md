# Structure Active Directory - Parcs et Machines

Ce document décrit la structure de l'arborescence Active Directory pour la gestion des parcs informatiques, salles et machines dans SambaÉdu.

## Concepts clés : Salle vs Parc

> **Important** : Dans SambaÉdu, "Salle" et "Parc" ont des rôles très différents ! La nomenclature
étant très étrangte, on prendra le parti d'obfusquer celle-ci en ne mélangeant pas les concepts dans l'interface utilisateur. Pour faire court, on parlera de groupe pour désitgner les types "salle" et tag ou profil (à déterminer) pour désigner les types "parc".


| Concept | Objet AD | Rôle fonctionnel |
|---------|----------|------------------|
| **Salle** | Organizational Unit (OU) | Conteneur physique + **GPO** + **Imprimantes** + Héritage hiérarchique |
| **Parc** | Security Group | Regroupement pour **WPKG** (déploiement logiciel) uniquement |

### Différences fondamentales

| Caractéristique | Salle (OU) | Parc (Group) |
|-----------------|------------|--------------|
| **GPO** | ✅ Oui | ❌ Non |
| **Imprimantes** | ✅ Oui | ❌ Non |
| **Héritage parent** | ✅ Oui (GPO héritées) | ❌ Non |
| **WPKG (logiciels)** | ❌ Non | ✅ Oui |
| **Appartenance machine** | 1 seule salle | Plusieurs parcs possibles |
| **Hiérarchie** | ✅ Oui (salles imbriquées) | ❌ Non |

### Citation du code legacy

> *"Un parc de type salle permet, en plus des logiciels installés par WPKG, d'associer des **GPO et des imprimantes**. Une machine ne peut appartenir qu'à **une seule salle**, mais en revanche les **salles peuvent avoir une autre salle pour parent**. Dans ce cas les machines **héritent des GPO et des applications**."*
> — `parcs/create_parc.php`

---

## Vue d'ensemble

```
dc=localdev,dc=fr (Base DN)
├── ou=Utilisateurs
│   └── OU=<UAI>                    # Établissement (ex: 0950000x)
│       └── ...utilisateurs...
│
├── ou=Groups
│   └── OU=<UAI>                    # Établissement
│       ├── ou=Parcs                # ← DeviceGroupTagModel (Parcs/Tags)
│       │   ├── CN=salle-info-101   # Parc (groupe LDAP)
│       │   ├── CN=salle-info-102
│       │   └── CN=batiment-A
│       ├── ou=Classes
│       ├── ou=Equipes
│       └── ...autres groupes...
│
└── ou=computers                    # ← DeviceGroupModel (Salles/OU)
    └── OU=<UAI>                    # Établissement (ex: 0950000x)
        ├── OU=salle-info-101       # Salle (OU contenant des machines)
        │   ├── CN=PC-INFO-01$      # Machine
        │   ├── CN=PC-INFO-02$
        │   └── CN=PC-INFO-03$
        ├── OU=salle-info-102
        │   └── CN=PC-102-01$
        ├── OU=batiment-A
        │   ├── OU=salle-A1         # Sous-salle
        │   │   └── CN=PC-A1-01$
        │   └── OU=salle-A2
        └── CN=PC-ADMIN-01$         # Machine directement dans l'établissement
```

## Types d'objets

### 1. DeviceGroupModel (Salle/OU)

**Classe LDAP** : `organizationalUnit`  
**Emplacement** : `ou=computers,dc=...`  
**Modèle Laravel** : `App\LdapModels\DeviceGroupModel`

C'est une **Organizational Unit (OU)** qui représente une salle physique ou un regroupement logique de machines.

#### Rôle fonctionnel

- **Conteneur physique** : Les machines sont placées dans cette OU
- **GPO** : Les stratégies de groupe s'appliquent aux machines de cette OU
- **Imprimantes** : Les imprimantes peuvent être associées à la salle
- **Héritage** : Les salles peuvent être imbriquées, les GPO sont héritées du parent
- **Exclusivité** : Une machine ne peut appartenir qu'à **une seule** salle

#### Attributs

| Attribut | Description | Exemple |
|----------|-------------|---------|
| `ou` | Nom de l'OU (identifiant) | `salle-info-101` |
| `cn` | Nom commun (souvent = ou) | `salle-info-101` |
| `description` | Description de la salle | `Salle informatique 101` |
| `objectGUID` | Identifiant unique AD | `{guid}` |

#### DN Exemple
```
OU=salle-info-101,OU=0950000x,ou=computers,dc=localdev,dc=fr
```

#### Hiérarchie
Les OU peuvent être imbriquées pour représenter une hiérarchie :
- Bâtiment → Étage → Salle
- Établissement → Salle

---

### 2. DeviceGroupTagModel (Parc/Tag)

**Classe LDAP** : `group`  
**Emplacement** : `ou=Parcs,OU=<UAI>,ou=Groups,dc=...`  
**Modèle Laravel** : `App\LdapModels\DeviceGroupTagModel`

C'est un **Groupe de sécurité AD** utilisé pour le déploiement logiciel via WPKG.

#### Rôle fonctionnel

- **WPKG** : Définit les logiciels à installer sur les machines membres
- **Regroupement logique** : Les machines sont membres du groupe (pas de hiérarchie physique)
- **Multi-appartenance** : Une machine peut appartenir à **plusieurs** parcs
- **Pas de GPO** : Les parcs ne portent pas de stratégies de groupe
- **Pas d'imprimantes** : Les imprimantes ne sont pas associées aux parcs

#### Attributs

| Attribut | Description | Exemple |
|----------|-------------|---------|
| `cn` | Nom du parc | `salle-info-101` |
| `samAccountName` | Nom SAM (avec $) | `salle-info-101$` |
| `description` | Description du parc | `Parc de la salle info 101` |
| `member` | Liste des DN des machines membres | `[CN=PC-01$,OU=..., ...]` |
| `objectGUID` | Identifiant unique AD | `{guid}` |

#### DN Exemple
```
CN=salle-info-101,ou=Parcs,OU=0950000x,ou=Groups,dc=localdev,dc=fr
```

#### Relation avec les Salles
Un parc (tag) peut être **associé** à une salle (OU) si :
- Le `samAccountName` du parc (sans le `$`) correspond au `ou` de la salle

Exemple :
- Parc : `samAccountName=salle-info-101$`
- Salle : `ou=salle-info-101`
→ Ces deux objets sont associés et représentent la même entité logique.

---

### 3. MachineModel (Machine/Computer)

**Classe LDAP** : `computer`  
**Emplacement** : Dans une OU sous `ou=computers,dc=...`  
**Modèle Laravel** : `App\LdapModels\MachineModel`

C'est un **objet Computer AD** représentant une machine physique.

#### Attributs

| Attribut | Description | Exemple |
|----------|-------------|---------|
| `cn` | Nom de la machine | `PC-INFO-01` |
| `samAccountName` | Nom NetBIOS (avec $) | `PC-INFO-01$` |
| `dnsHostname` | FQDN | `pc-info-01.localdev.fr` |
| `description` | Description | `Poste élève` |
| `ipHostNumber` | Adresse IP réservée | `192.168.1.101` |
| `networkAddress` | Adresse MAC | `00:11:22:33:44:55` |
| `memberOf` | Groupes dont la machine est membre | `[CN=salle-info-101,ou=Parcs,...]` |
| `operatingSystem` | Système d'exploitation | `Windows 10 Pro` |
| `operatingSystemVersion` | Version | `10.0 (19045)` |
| `lastLogon` | Dernière connexion | `timestamp` |
| `location` | Emplacement (prise murale) | `Salle 101, poste 5` |
| `netbootGUID` | Token PXE/UUID | `{guid}` |
| `objectGUID` | Identifiant unique AD | `{guid}` |

#### DN Exemple
```
CN=PC-INFO-01$,OU=salle-info-101,OU=0950000x,ou=computers,dc=localdev,dc=fr
```

---

## Relations entre objets

```
┌─────────────────────────────────────────────────────────────────┐
│                        ACTIVE DIRECTORY                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ou=Groups                           ou=computers                │
│  └── ou=Parcs                        └── OU=<UAI>                │
│      │                                   │                       │
│      │  ┌──────────────┐                 │  ┌──────────────┐    │
│      └──│ DeviceGroup  │ ◄─── associé ───┼──│ DeviceGroup  │    │
│         │ TagModel     │     (samAccount │  │ Model (OU)   │    │
│         │ (Parc/Tag)   │      Name = ou) │  │ (Salle)      │    │
│         └──────┬───────┘                 │  └──────┬───────┘    │
│                │                         │         │             │
│                │ member                  │         │ contient    │
│                │ (attribut)              │         │ (hiérarchie)│
│                ▼                         │         ▼             │
│         ┌──────────────┐                 │  ┌──────────────┐    │
│         │ MachineModel │ ◄───────────────┼──│ MachineModel │    │
│         │ (référence)  │    même objet   │  │ (objet réel) │    │
│         └──────────────┘                 │  └──────────────┘    │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Deux façons de lier machines et parcs

1. **Par hiérarchie OU** (DeviceGroupModel)
   - Les machines sont **physiquement dans** une OU
   - Relation parent-enfant dans l'arborescence LDAP
   - Requête : `MachineModel::in($ouDn)->get()`

2. **Par appartenance groupe** (DeviceGroupTagModel)
   - Les machines sont **membres** d'un groupe
   - Attribut `member` du groupe contient les DN des machines
   - Attribut `memberOf` de la machine contient les DN des groupes
   - Requête : `$tag->machines()` ou `$machine->parcs()`

---

## Construction des DN

### Configuration (LdapConfig)

```php
$baseDn = 'dc=localdev,dc=fr';
$computersRdn = 'ou=computers';
$groupsRdn = 'ou=Groups';
$parcsRdn = 'ou=Parcs';
```

### DN avec préfixe établissement (LdapDnHelper)

```php
// Pour l'établissement 0950000x :

// Computers (salles)
$dnHelper->computers();
// → "OU=0950000x,ou=computers,dc=localdev,dc=fr"

// Parcs (tags)
LdapDnHelper::parcsDn();
// → "ou=Parcs,OU=0950000x,ou=Groups,dc=localdev,dc=fr"

// Mode global (sans filtre établissement)
$dnHelper->computers(global: true);
// → "ou=computers,dc=localdev,dc=fr"
```

---

## Cas d'usage dans le code

### Récupérer toutes les salles d'un établissement

```php
$salles = DeviceGroupModel::in($dnHelper->computers())->get();
```

### Récupérer tous les parcs (tags)

```php
$parcs = DeviceGroupTagModel::in(LdapDnHelper::parcsDn())->get();
```

### Récupérer les machines d'une salle (par OU)

```php
$machines = MachineModel::in($salle->getDn())->get();
```

### Récupérer les machines d'un parc (par groupe)

```php
$machines = $parc->machines(limit: 100);
```

### Trouver le parc associé à une salle

```php
$parc = $salle->associatedTag()->first();
```

### Trouver la salle associée à un parc

```php
$salle = $parc->associatedGroup();
```

---

## Types de parcs (déterminés par le code)

Le type est déterminé dynamiquement depuis le nom ou la description :

| Type | Mots-clés détectés | Badge |
|------|-------------------|-------|
| `building` | bâtiment, batiment, building, bat- | `badge-warning` |
| `lab` | laboratoire, labo | `badge-info` |
| `room` | salle, room (ou par défaut) | `badge-primary` |

---

## Modèle métier : Parc (DTO)

Le DTO `App\Types\Parc` unifie les deux types d'objets LDAP :

```php
class Parc implements JsonSerializable, Wireable
{
    public readonly string $cn;           // Nom technique
    public readonly string $name;         // Nom d'affichage
    public readonly ?string $description;
    public readonly string $type;         // building, room, lab
    public readonly ?string $parentDn;
    public readonly ?string $dn;
    public readonly ?string $etab;        // Code UAI
    public readonly ?string $samAccountName;
    public readonly int $machineCount;
    
    // Méthodes
    public function getDisplayName(): string;
    public function getIcon(): string;
    public function getTypeLabel(): string;
    public function getBreadcrumb(): array;
    public function getHierarchyLevel(): int;
}
```

### Conversion depuis les modèles LDAP

```php
// Depuis une salle (OU)
$parc = $deviceGroupModel->toBusinessObject();

// Depuis un parc (tag)
$parc = $deviceGroupTagModel->toBusinessObject();
```

---

## Notes importantes

1. **Salle ≠ Parc** : Ce sont deux concepts distincts avec des rôles différents :
   - **Salle** = Conteneur + GPO + Imprimantes (1 machine = 1 salle)
   - **Parc** = Déploiement logiciel WPKG (1 machine = N parcs)

2. **Association Salle/Parc** : Une salle et un parc peuvent être associés si le `samAccountName` du parc correspond au `ou` de la salle. Cela permet d'avoir à la fois les GPO (via la salle) et les logiciels WPKG (via le parc).

3. **Héritage GPO** : Les salles peuvent être imbriquées (bâtiment > étage > salle). Les machines héritent des GPO de toute la hiérarchie parente.

4. **Performance** : Le comptage des machines (`getMachineCount()`) est coûteux car il nécessite une requête LDAP. Il est désactivé par défaut dans `toBusinessObject()` et chargé en lazy loading.

5. **Préfixe établissement** : Toutes les requêtes sont automatiquement filtrées par l'établissement courant via `LdapDnHelper`.

---

## Schéma fonctionnel

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           MACHINE (PC-INFO-01)                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  Appartient à UNE salle (OU)          Membre de PLUSIEURS parcs (Group) │
│  ┌─────────────────────────┐          ┌─────────────────────────┐       │
│  │ OU=salle-info-101       │          │ CN=parc-bureautique     │       │
│  │                         │          │ CN=parc-dev             │       │
│  │ → GPO appliquées        │          │ CN=parc-multimedia      │       │
│  │ → Imprimantes           │          │                         │       │
│  │ → Héritage parent       │          │ → Logiciels WPKG        │       │
│  └─────────────────────────┘          └─────────────────────────┘       │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```
