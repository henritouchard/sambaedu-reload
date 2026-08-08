# Comprendre WPKG, GPO et le Déploiement d'Applications dans SambaEdu

## Introduction

Ce document explique de manière pédagogique comment fonctionne le système de déploiement d'applications dans SambaEdu. Le système peut sembler complexe car il mélange plusieurs concepts et technologies qui interagissent ensemble. L'objectif est de démystifier chaque composant et de montrer comment ils s'imbriquent.

---

## Vue d'ensemble : Les 4 piliers du système

Le déploiement d'applications dans SambaEdu repose sur **4 piliers** distincts mais interconnectés :

```
┌─────────────────────────────────────────────────────────────────────┐
│                        ACTIVE DIRECTORY (AD)                        │
│  Source de vérité pour : Machines, Parcs (groupes), Utilisateurs   │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    ▼               ▼               ▼
            ┌───────────┐   ┌───────────────┐   ┌─────────────┐
            │    GPO    │   │     WPKG      │   │   Scripts   │
            │ (Policies)│   │ (Packages)    │   │ (Actions)   │
            └───────────┘   └───────────────┘   └─────────────┘
                    │               │               │
                    └───────────────┼───────────────┘
                                    ▼
                    ┌───────────────────────────────┐
                    │      POSTE CLIENT WINDOWS     │
                    └───────────────────────────────┘
```

### Résumé des rôles :

| Pilier | Rôle | Stockage |
|--------|------|----------|
| **Active Directory** | Annuaire central : machines, utilisateurs, groupes, parcs | LDAP (Samba4) |
| **GPO** | Règles de configuration appliquées automatiquement | AD + SYSVOL |
| **WPKG** | Gestionnaire de paquets pour installer/désinstaller des logiciels | MySQL + XML |
| **Scripts** | Actions personnalisées au démarrage/connexion/déconnexion | Fichiers système |

---

## 1. Active Directory (AD) : La Source de Vérité

### Qu'est-ce que l'AD dans SambaEdu ?

L'Active Directory est un **annuaire LDAP** géré par Samba4. Il stocke toutes les informations sur :
- Les **utilisateurs** (élèves, professeurs, administrateurs)
- Les **machines** (ordinateurs du parc informatique)
- Les **groupes** (classes, équipes pédagogiques, etc.)
- Les **parcs** (regroupements logiques de machines)

### Structure de l'AD pour les machines

```
DC=mondomaine,DC=lan
 └── OU=Computers                    ← Racine des machines
      ├── OU=Salle-Info-101          ← Salle (OU pour GPO)
      │    ├── CN=PC-101-01          ← Machine physiquement dans cette salle
      │    ├── CN=PC-101-02          
      │    └── CN=PC-101-03          
      ├── OU=CDI                     
      │    └── CN=PC-CDI-01          
      │
 └── OU=Parcs                        ← Conteneur des groupes de sécurité
      ├── CN=Salle-Info-101          ← CN miroir de l'OU (pour WPKG legacy)
      │    └── members: PC-101-01$, PC-101-02$, PC-101-03$
      ├── CN=CDI                     ← CN miroir de l'OU CDI
      │    └── members: PC-CDI-01$
      ├── CN=Parc-Portables          ← Parc logique (CN seul, pas d'OU)
      │    └── members: PC-101-02$, PC-CDI-01$, LAPTOP-01$
      └── CN=Parc-Video              
           └── members: PC-101-01$, PC-101-03$
```

### Les deux types de "Parcs"

SambaEdu distingue deux types de regroupements de machines :

#### 1. **Salle** (Organizational Unit - OU)
- **Nature** : Conteneur physique dans l'arborescence LDAP
- **Usage** : Représente un lieu physique (salle informatique, CDI, etc.)
- **Caractéristique** : Les machines sont **physiquement dans** l'OU
- **Attribut LDAP** : `organizationalUnit`
- **Exemple** : `OU=Salle-Info-101,OU=Computers,DC=domaine,DC=lan`

#### 2. **Parc** (Groupe de sécurité)
- **Nature** : Groupe dont les machines sont membres
- **Usage** : Regroupement logique (ex: "tous les portables", "machines de TP")
- **Caractéristique** : Les machines sont **membres du groupe**
- **Attribut LDAP** : `group` avec attribut `member`
- **Exemple** : `CN=Parc-Portables,OU=Parcs,OU=Computers,DC=domaine,DC=lan`

### Pourquoi cette distinction est importante ?

Une **même machine** peut appartenir à :
- **1 seule salle** (son emplacement physique dans l'OU)
- **Plusieurs parcs** (groupes logiques)
- **Le parc spécial `_TousLesPostes`** (toutes les machines automatiquement)

Cette flexibilité permet d'assigner des applications :
- À toutes les machines d'une salle physique
- À un ensemble logique de machines (ex: toutes celles qui font de la vidéo)
- À une machine individuelle

---

## 2. WPKG : Le Gestionnaire de Paquets Windows

### Qu'est-ce que WPKG ?

**WPKG** (Windows Package) est un système open-source de déploiement automatique de logiciels sur Windows. Il fonctionne comme un "apt-get pour Windows" :

- **Installe** des logiciels silencieusement
- **Met à jour** les versions
- **Désinstalle** les logiciels obsolètes
- **Gère les dépendances** entre logiciels

### Comment WPKG fonctionne (flux simplifié)

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Admin Web     │────▶│   Base MySQL    │────▶│   Fichier XML   │
│ (Interface SE4) │     │   (sambaedu)    │     │ (packages.xml)  │
└─────────────────┘     └─────────────────┘     └─────────────────┘
                                                        │
                                                        ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Client WPKG    │◀────│   Serveur Web   │◀────│ profiles.xml    │
│  (sur le PC)    │     │   (SE4-FS)      │     │ (par machine)   │
└─────────────────┘     └─────────────────┘     └─────────────────┘
```

### Les composants WPKG

#### A. Les Applications (table `applications`)

Une **application WPKG** est une "recette" d'installation définie par :

| Attribut | Description | Exemple |
|----------|-------------|---------|
| `id_nom_app` | Identifiant unique | `firefox` |
| `nom_app` | Nom affiché | `Mozilla Firefox` |
| `version_app` | Version actuelle | `120.0` |
| `compatibilite_app` | OS supportés | `4` (Windows 10/11) |
| `categorie_app` | Catégorie | `Navigateurs` |
| `priorite_app` | Ordre d'installation | `50` |
| `reboot_app` | Redémarrage requis | `false` |
| `active_app` | Activée dans SE4 | `1` |

#### B. Le fichier `packages.xml`

C'est le **catalogue maître** de toutes les applications. Chaque application y est décrite avec :

```xml
<package id="firefox" name="Mozilla Firefox" revision="120.0" 
         reboot="false" priority="50" category="Navigateurs">
    
    <!-- Comment vérifier si l'app est installée -->
    <check type="registry" path="HKLM\SOFTWARE\Mozilla\Firefox" 
           condition="exists"/>
    
    <!-- Comment installer -->
    <install cmd='"%SOFTWARE%\Firefox\Firefox Setup.exe" /S'/>
    
    <!-- Comment mettre à jour -->
    <upgrade cmd='"%SOFTWARE%\Firefox\Firefox Setup.exe" /S'/>
    
    <!-- Comment désinstaller -->
    <remove cmd='"%PROGRAMFILES%\Mozilla Firefox\uninstall\helper.exe" /S'/>
    
    <!-- Dépendances -->
    <depends package-id="vcredist"/>
</package>
```

#### C. Le fichier `profiles.xml` (généré dynamiquement)

C'est la **liste des applications à installer sur une machine spécifique**. Il est généré à la volée par le serveur quand une machine le demande :

```xml
<profiles>
    <profile id="PC-101-01">
        <package package-id="firefox"/>
        <package package-id="libreoffice"/>
        <package package-id="vlc"/>
    </profile>
</profiles>
```

### Comment une machine sait quoi installer ?

Le client WPKG sur la machine interroge le serveur :

1. **GET `/wpkg/profiles.xml?poste=PC-101-01`**
   - Le serveur calcule les applications pour cette machine
   - En fonction : parcs + applications directes + dépendances

2. **GET `/wpkg/packages.xml`**
   - Le serveur retourne le catalogue filtré (applications actives uniquement)

3. **Le client WPKG compare** :
   - Ce qui est demandé (profiles.xml)
   - Ce qui est installé (rapports locaux)
   - → Installe/met à jour/désinstalle en conséquence

---

## 3. L'Assignation des Applications : La Table Pivot

### Comment lier applications et machines ?

Le cœur du système est la **relation N:N** entre applications et "entités" (parcs ou postes).

#### Table `applications_profile`

| id_entite | type_entite | id_appli |
|-----------|-------------|----------|
| 5         | parc        | 12       |
| 5         | parc        | 15       |
| 42        | poste       | 12       |

Lecture :
- Le parc #5 doit avoir les applications #12 et #15
- Le poste #42 doit avoir l'application #12 (en plus de celles de ses parcs)

#### Table `parc_profile` (appartenance machine → parc)

| id_poste | id_parc |
|----------|---------|
| 42       | 5       |
| 42       | 8       |
| 43       | 5       |

Lecture :
- Le poste #42 appartient aux parcs #5 et #8
- Le poste #43 appartient au parc #5

### Calcul des applications pour une machine

Quand on demande les applications pour `PC-101-01` :

```
Applications pour PC-101-01 =
    Applications assignées directement au poste
  + Applications de chaque parc du poste
  + Dépendances de toutes ces applications
  - Doublons
```

**Exemple concret :**

```
PC-101-01 appartient à :
  - Parc "Salle-101" (Firefox, LibreOffice)
  - Parc "_TousLesPostes" (Antivirus, WPKG-client)

Applications directes sur PC-101-01 :
  - VLC

Dépendances :
  - LibreOffice dépend de Java
  - VLC dépend de VCRedist

Résultat final :
  Firefox, LibreOffice, Antivirus, WPKG-client, VLC, Java, VCRedist
```

---

## 4. La Synchronisation AD ↔ MySQL

### Le problème de la double source de vérité

- **AD** : Contient les machines et parcs (source de vérité pour l'infrastructure)
- **MySQL** : Contient les applications et assignations (source de vérité pour WPKG)



### Le script `wpkg_ldap_update.php`

Ce script tourne périodiquement et :

1. **Récupère les parcs de l'AD** via `search_parcs()`
2. **Compare avec la table `parc` de MySQL**
3. **Synchronise** :
   - Crée les nouveaux parcs
   - Renomme les parcs modifiés (via UUID)
   - Supprime les parcs disparus
    
> [!question]+
> Si les parcs ne peuvent pas être modifiés ailleurs que dans mon instance sambaedu, ne pourrions nous pas gérer les parcs etc dans mysql et se contenter de synchroniser l'ad (si c'est vraiment nécessaire) 

4. **Récupère les machines de l'AD** via `list_machines_parcs()`
5. **Compare avec la table `postes` de MySQL**
6. **Synchronise** :
   - Crée les nouvelles machines
   - Met à jour les UUID (gestion des renommages)
   - Met à jour l'appartenance aux parcs (`parc_profile`)

### L'importance de l'UUID

Chaque machine et parc a un **UUID** (identifiant unique universel) dans l'AD.

**Pourquoi ?** Pour gérer les renommages :
- Si `PC-101-01` est renommé `PC-SALLE1-01`
- L'UUID reste le même
- WPKG sait que c'est la même machine
- L'historique des installations est préservé

> [!warning]+
> Dans l'éventualité d'un passage à sql comme source il faut voir si l'UUID peut être créé par sql.



---

## 5. Les GPO : Group Policy Objects

### Qu'est-ce qu'une GPO ?

Une **GPO** est un ensemble de règles de configuration qui s'appliquent automatiquement aux machines ou utilisateurs. Elles sont stockées dans l'AD et le SYSVOL.

### GPO vs WPKG : Quelle différence ?

| Aspect          | GPO                          | WPKG                    |
| --------------- | ---------------------------- | ----------------------- |
| **Rôle**        | Configurer le système        | Installer des logiciels |
| **Exemple**     | Désactiver USB, fond d'écran | Installer Firefox 120   |
| **Stockage**    | AD + SYSVOL                  | MySQL + XML             |
| **Application** | Automatique au boot/logon    | Via client WPKG         |
| **Granularité** | Par OU ou groupe             | Par parc ou poste       |
> [!question]+
> OU ou group quelle différence ? le "group" est il un CN ? 
> Dans l'arborescence indiquée plus haut, que seraient les gpos ? Comment les relier aux différentes entités ? Comment est formalisée une GPO (.xml ? Donnée AD ? autre?)

### Les GPO SambaEdu principales

SambaEdu fournit des GPO prêtes à l'emploi :

| GPO | Rôle |
|-----|------|
| `se4_applications` | Déclenche les scripts d'applications |
| `se4_wpkg` | Lance le client WPKG au démarrage |
| `se4_wallpaper` | Configure le fond d'écran |
| `se4_imprimantes` | Déploie les imprimantes |
| `se4_profil` | Configure les profils itinérants |

### La GPO `se4_applications` : Le déclencheur

Cette GPO est **cruciale**. Elle contient des scripts qui s'exécutent à différents moments :

```
Démarrage machine (startup)
    └──▶ curl http://se4fs/gpo/applications.php → script.cmd
         └──▶ Exécute le script retourné

Ouverture session (logon)
    └──▶ curl http://se4fs/gpo/applications.php?action=logon&user=XXX
         └──▶ Exécute le script retourné

Fermeture session (logoff)
    └──▶ ...

Arrêt machine (shutdown)
    └──▶ ...
```

---

## 6. Les Scripts d'Applications : La Personnalisation

### Qu'est-ce que c'est ?

Les **scripts d'applications** sont des fichiers qui définissent des actions à exécuter sur les machines. Ils permettent de :

- Configurer des logiciels après installation
- Créer des raccourcis personnalisés
- Rediriger des dossiers vers le serveur
- Exécuter des commandes spécifiques

### Où sont-ils stockés ?

```
/usr/share/sambaedu/applications/    ← Scripts officiels (paquet)
    └── firefox/
         ├── scripts.json            ← Index des scripts
         ├── startup.windows         ← Script au démarrage
         └── logon.windows           ← Script à la connexion

/etc/sambaedu/applications/          ← Scripts locaux (prioritaires)
    └── mon-appli/
         ├── scripts.json
         └── logon@Salle-101.windows ← Script pour un parc spécifique
```

> [!question]+
> J'aime  regrouper les dépendances de mes apps dans des emplacements uniques.
> Selon la manière dont ces scripts sont appelés, serait il envisageable de les stocker dans un serveur de fichier  (S3|ftp) et dans la bdd pour les infos json et de renvoyer le tout à la demande ?  ainsi notre app serait moins dépendante du système sur lequel elle tourne. et on saurait plus facilement quel fichier fait partie du serveur et quel fichier fait partie de notre app. 


### Structure d'un `scripts.json`

```json
{
    "config-firefox": {
        "os": "windows",
        "action": "logon",
        "file": "config.cmd",
        "interpreter": "cmd",
        "includes": ["Profs"],
        "excludes": ["Eleves"],
        "includes_apps": ["firefox"],
        "excludes_apps": []
    }
}
```

| Champ | Signification |
|-------|---------------|
| `os` | Système cible : `windows` ou `linux` |
| `action` | Quand exécuter : `startup`, `logon`, `logoff`, `shutdown` |
| `file` | Fichier script à exécuter |
| `interpreter` | Type : `cmd`, `bash`, `powershell` |
| `includes` | Parcs/groupes où appliquer |
| `excludes` | Parcs/groupes à exclure |
| `includes_apps` | Appliquer si ces apps sont installées |
| `excludes_apps` | Ne pas appliquer si ces apps sont présentes |

### Le flux de génération des scripts

```
Machine démarre
    │
    ▼
GPO déclenche curl vers /gpo/applications.php
    │
    ▼
Serveur identifie la machine (nom, IP)
    │
    ▼
Serveur récupère :
  - Les parcs de la machine
  - Les groupes de l'utilisateur (si logon)
  - Les applications installées
    │
    ▼
Serveur lit tous les scripts.json
    │
    ▼
Serveur filtre les scripts applicables
  (includes/excludes/includes_apps/excludes_apps)
    │
    ▼
Serveur génère un script CMD/Bash personnalisé
    │
    ▼
Machine exécute le script
```

---

## 7. Les Redirections de Profils

### Le problème des profils utilisateur

Sur Windows, les applications stockent leurs préférences dans le profil utilisateur :
- `C:\Users\martin\AppData\Roaming\Firefox\...`
- `C:\Users\martin\AppData\Local\VLC\...`
> [!question]+
>Il me semble avoir entendu quelqu'un dire que ce n'était même pas toujours le cas, que certaines appli les stockaient ailleurs ? Peux-tu me confirmer cette info ?

Avec les profils itinérants, ces données sont copiées au serveur à chaque déconnexion. Problème : certaines applications créent des **gros dossiers** (caches, etc.).

### La solution : Les redirections

SambaEdu permet de **rediriger** certains dossiers :

```json
// fichier redirects.json
{
    "redirect-firefox": {
        "link": "AppData\\Local\\Mozilla\\Firefox",
        "dest": "AppData\\Roaming\\Mozilla\\Firefox",
        "server": ".mozilla\\firefox"
    }
}
```

Cela crée une **jonction NTFS** (lien symbolique) :
```
Local\Mozilla\Firefox → Roaming\Mozilla\Firefox → \\serveur\users\martin\.mozilla\firefox
```

> [!question]+
> Où sont stockées ces fichiers de redirection ? envisageable de le mettre en sql ?
> Peut on préciser pour quelles applications on veut du stockage et pour lesquels on n'en veut pas ?

### Les types de redirection

| Type | Description | Usage |
|------|-------------|-------|
| **Local → Roaming** | Données locales vers profil itinérant | Préférences légères |
| **Local → Serveur** | Données locales directement sur serveur | Gros caches |
| **Roaming → Serveur** | Profil itinérant vers serveur | Tout le profil app |
> [!question]+
> à quoi correspond le Roaming ?

---

## 8. Les Dépôts d'Applications

### Qu'est-ce qu'un dépôt ?

Un **dépôt** est une source externe d'applications WPKG. C'est comme un "store" d'applications pré-packagées.

### Structure d'un dépôt

```
https://depot.example.com/
    └── packages.xml           ← Catalogue des applications disponibles
    └── firefox/
         ├── package.xml       ← Recette d'installation
         └── Firefox-Setup.exe ← Installeur
    └── libreoffice/
         └── ...
```

### Le flux d'import

```
Admin consulte le dépôt
    │
    ▼
Sélectionne "Firefox 120"
    │
    ▼
SE4 télécharge package.xml + installeur
    │
    ▼
Vérifie l'intégrité (hash SHA256)
    │
    ▼
Ajoute l'application dans la base MySQL
    │
    ▼
Met à jour packages.xml local
    │
    ▼
Application disponible pour assignation
```

---

## 9. Les Rapports WPKG

### Comment savoir ce qui est installé ?

Le client WPKG génère des **rapports** après chaque exécution :

```xml
<wpkg>
    <package id="firefox" status="Installed" revision="120.0"/>
    <package id="vlc" status="Installed" revision="3.0.18"/>
    <package id="libreoffice" status="Not Installed" revision=""/>
</wpkg>
```

### Le cycle de vie d'un rapport

```
Client WPKG termine son exécution
    │
    ▼
Génère un fichier XML de rapport
    │
    ▼
Envoie au serveur SE4-FS
    │
    ▼
Script wpkg_rapport.php parse le XML
    │
    ▼
Met à jour la table poste_app
    │
    ▼
Interface admin affiche le statut
```

> [!question]+
> Possible d'envisager qu'il post un Json plutot que du XML ? 
> Peut être pas dans le fond car Windows a besoin d'une réponse en XML donc autant rester cohérent dans le format. 


### Les statuts possibles

| Statut      | Signification                       | Couleur |
| ----------- | ----------------------------------- | ------- |
| **Ok**      | Version installée = version requise | Vert    |
| **MaJ**     | Version installée < version requise | Orange  |
| **Not_Ok-** | Requis mais non installé            | Rouge   |
| **Not_Ok+** | Installé mais non requis            | Rouge   |

---

## 10. Schéma Récapitulatif Global

```
┌────────────────────────────────────────────────────────────────────────────┐
│                              ACTIVE DIRECTORY                               │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐                  │
│  │   Machines   │    │    Parcs     │    │ Utilisateurs │                  │
│  │  (Computers) │    │   (Groupes)  │    │   (People)   │                  │
│  └──────────────┘    └──────────────┘    └──────────────┘                  │
│         │                   │                   │                          │
│         └───────────────────┴───────────────────┘                          │
│                             │                                              │
│         Sync via wpkg_ldap_update.php (UUID-based)                         │
└────────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌────────────────────────────────────────────────────────────────────────────┐
│                              BASE MySQL                                     │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────────────┐          │
│  │    postes    │◀──▶│ parc_profile │◀──▶│        parc          │          │
│  └──────────────┘    └──────────────┘    └──────────────────────┘          │
│         │                                          │                       │
│         │            ┌──────────────────────┐      │                       │
│         └───────────▶│ applications_profile │◀─────┘                       │
│                      └──────────────────────┘                              │
│                                │                                           │
│                                ▼                                           │
│                      ┌──────────────────────┐                              │
│                      │    applications      │                              │
│                      └──────────────────────┘                              │
│                                │                                           │
│                      ┌────────┴────────┐                                   │
│                      ▼                 ▼                                   │
│              ┌─────────────┐   ┌─────────────┐                             │
│              │  poste_app  │   │ dependance  │                             │
│              │ (rapports)  │   │ (liens)     │                             │
│              └─────────────┘   └─────────────┘                             │
└────────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌────────────────────────────────────────────────────────────────────────────┐
│                         FICHIERS XML GÉNÉRÉS                               │
│  ┌──────────────────────┐         ┌──────────────────────┐                 │
│  │    packages.xml      │         │    profiles.xml      │                 │
│  │ (catalogue global)   │         │ (par machine)        │                 │
│  └──────────────────────┘         └──────────────────────┘                 │
└────────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌────────────────────────────────────────────────────────────────────────────┐
│                         POSTE CLIENT WINDOWS                               │
│                                                                            │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐                     │
│  │     GPO     │───▶│   Scripts   │    │    WPKG     │                     │
│  │ se4_applis  │    │ /gpo/appli  │    │   Client    │                     │
│  └─────────────┘    └─────────────┘    └─────────────┘                     │
│        │                  │                   │                            │
│        │                  ▼                   ▼                            │
│        │         ┌─────────────────────────────────────┐                   │
│        └────────▶│     Configuration + Installation    │                   │
│                  │         des applications            │                   │
│                  └─────────────────────────────────────┘                   │
└────────────────────────────────────────────────────────────────────────────┘
```

---

## 11. Concepts Clés à Retenir

### Séparation des responsabilités actuelles

| Composant | Responsabilité | Technologie |
|-----------|----------------|-------------|
| **AD** | Identité (qui/quoi existe) | LDAP/Samba4 |
| **MySQL** | Logique métier (qui a quoi) | Base relationnelle |
| **GPO** | Déclenchement des actions | Windows natif |
| **WPKG** | Installation de logiciels | Client Windows + XML |
| **Scripts PHP** | Génération dynamique | Serveur web |
| **Scripts apps** | Configuration fine | Fichiers système |
> [!question]+
> Dans quelle mesure les identités (à l'exception de l'utilisateur et de la machine) pourraient elles être gérés seulement en base de donnée (je pense aux salles, et aux groupes de sécurité). Ca aurait pour effet de réduire les besoins de synchronisation entre AD et sql.

### Les points de complexité

1. **Double source de vérité** : AD pour l'infra, MySQL pour WPKG → nécessite synchronisation
2. **Calcul des applications** : Via parcs + direct + dépendances → requêtes SQL complexes
3. **Génération dynamique** : Scripts personnalisés par machine/user → logique de filtrage
4. **Plusieurs formats** : LDAP, SQL, XML, JSON, CMD, PowerShell → conversions multiples

### Pour simplifier dans une refonte

Une architecture moderne pourrait :

1. **Unifier la source de vérité** : Tout en base de données, AD comme simple backend d'auth 
   > [!question]+
> Quel serait le degré de complexité ? 
2. **Abstraire les "entités"** : Interface unique pour parcs/salles/machines
   > [!question]+
> autant salles et machines je suis d'accord, autant le parc, indépendamment du fait qu'il porte fort mal son nom,  est une propriété qui s'applique sur les salles et les machines. J'ai tendance à penser que c'est une logique séparée mais reliée.
> Formule une critique de mon point de vue en détaillant pourquoi le parc pourrait avoir la même abstraction que salles et machines.

3. **Simplifier le calcul** : Cache des applications par machine
4. **Moderniser le déploiement** : Remplacer WPKG par Winget/Chocolatey
> [!info]
> L'utilisation de wpkg nous permet de customiser l'installation et notre repo est un repo d'applications dédiées à l'éducation. Pour le moment nous garderons wpkg en architecturant la solution de sorte qu'il soit facile de passer vers un autre Package manager.  

5. **Unifier les scripts** : PowerShell partout, plus de CMD/bash mixés
   > [!question]+
> Ce serait bien mais nous pouvons avoir des machines linux donc il faudra à minima conserver bash. Depuis que MS a installé un utilitaire bash dans ses système, je serai curieux de savoir si il est possible de tout faire en bash (sachant que c'est compatible linux, windows et mac). As-tu cette information ?

---

## 12. Glossaire

| Terme | Définition |
|-------|------------|
| **AD** | Active Directory - Annuaire LDAP de Microsoft/Samba |
| **GPO** | Group Policy Object - Règle de configuration Windows |
| **WPKG** | Windows Package - Gestionnaire de paquets |
| **Parc** | Groupe logique de machines dans SambaEdu |
| **Salle** | OU contenant physiquement des machines |
| **SYSVOL** | Partage réseau contenant les fichiers GPO |
| **UUID** | Identifiant unique universel (pour le tracking) |
| **Profil itinérant** | Données utilisateur synchronisées avec le serveur |
| **Jonction NTFS** | Lien symbolique au niveau système de fichiers |
| **Dépôt** | Source externe d'applications packagées |

---

## 13. Pour Aller Plus Loin

### Fichiers clés à étudier

| Fichier | Rôle |
|---------|------|
| `includes/wpkg_libsql.php` | Toutes les fonctions SQL WPKG |
| `includes/wpkg_lib.php` | Logique métier WPKG |
| `includes/applications.inc.php` | Génération des scripts |
| `wpkg/wpkg_ldap_update.php` | Synchronisation AD ↔ MySQL |
| `wpkg/packages_xml_out.php` | Génération packages.xml |
| `wpkg/profiles_xml_out.php` | Génération profiles.xml |
| `gpo/applications.php` | Point d'entrée des scripts |
| `includes/ldap.inc.php` | Fonctions de recherche AD |

### Questions pour la refonte

1. Faut-il conserver WPKG ou migrer vers Winget/Chocolatey ?
===Non
2. Comment gérer la rétrocompatibilité avec les machines existantes ?
 > [!question]+
> Pourquoi ? les scripts sont déjà sur les machines ? qu'est-ce qui pourrait casser en imaginant une refonte

3. Peut-on simplifier le modèle parc/salle en un seul concept ?
   ====Nous clarifierons ce point ensemble 
4. Comment moderniser la génération de scripts (Ansible, PowerShell DSC) ?
   ====précise ta question
5. Quelle API exposer pour une gestion centralisée multi-établissements ?
   ====A minima le CRUD des parcs, salles, machines 

---

## Architecture Découplée (Implémentée)

### Principe

L'architecture a été refactorisée pour **découpler** les concepts :

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  GROUPES        │     │  RÈGLES         │     │  ASSOCIATIONS   │
│  (machines)     │     │  (applicatives  │     │  (groupe ↔      │
│                 │     │   ou GPO)       │     │   règle)        │
└─────────────────┘     └─────────────────┘     └─────────────────┘
        │                       │                       │
        └───────────────────────┴───────────────────────┘
                                │
                    ┌───────────┴───────────┐
                    │  SYNCHRONISATION AD   │
                    │  (couche technique)   │
                    │  - Crée OU si GPO     │
                    │  - Crée CN si WPKG    │
                    └───────────────────────┘
```

### Modèle `MachineGroup` simplifié

| Champ | Type | Description |
|-------|------|-------------|
| `id` | int | Clé primaire |
| `uuid` | string | UUID Laravel |
| `name` | string | Nom du groupe |
| `description` | string? | Description optionnelle |
| `parent_id` | int? | Groupe parent (arborescence) |
| `ad_guid_ou` | string? | GUID de l'OU dans AD (si règles GPO associées) |
| `ad_guid_cn` | string? | GUID du CN dans AD (si règles applicatives associées) |
| `legacy_parc_id` | int? | Référence vers table `parc` legacy |

### Ce qui a été supprimé

- `is_room` : Plus de distinction salle/parc logique côté utilisateur
- `ad_type` : Déterminé automatiquement selon les règles associées

### Interfaces utilisateur

1. **Groupes de machines** : Interface simple pour créer/gérer des groupes de machines
2. **Règles** (à implémenter) : Interface pour créer des règles applicatives (WPKG) ou GPO
3. **Associations** (à implémenter) : Interface pour lier les règles aux groupes

### Synchronisation AD

La synchronisation AD devient une **couche technique** qui :
- Crée un **OU** si le groupe a des règles GPO associées
- Crée un **CN** si le groupe a des règles applicatives (WPKG) associées
- Gère les membres automatiquement
