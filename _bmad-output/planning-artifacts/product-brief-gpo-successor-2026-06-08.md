---
stepsCompleted: [1, 2, 3, 4]
inputDocuments:
  - docs/tech-debt-gpo.md
  - _bmad-output/planning-artifacts/audit-gpo-legacy.md
  - docs/qa/domains/gpo.md
  - docs/explications_wpkg.md
date: 2026-06-08
author: henri
---

# Product Brief: Successeur du système GPO (SambaEdu SE5)

## Executive Summary

Le module GPO de SambaEdu SE5 est un héritage Windows/Active Directory que l'équipe ne maîtrise pas : opaque, à moitié porté en natif (Story 16.4 abandonnée), couplant l'AD à Windows et imposant un fonctionnement différent entre Linux et Windows. Il coexiste avec d'autres scripts, sans ordre d'exécution clair, et impose une UI trop technique pour un refnum.

Ce projet vise à **remplacer le système GPO par un canal unique, natif et maîtrisé** : des politiques de configuration (registre, scripts, imprimantes, déploiement logiciel…) **versionnées, pilotées par API et tracées par des logs unifiés**, appliquées de façon identique sur Linux et Windows, en réduisant l'Active Directory à un simple point d'ancrage. La fondation existe déjà partiellement (canal `/api/v1/workstation-config/*`).

---

## Core Vision

### Problem Statement

Configurer les postes dans SE5 passe aujourd'hui par des « GPO » — un concept Windows/AD que personne dans l'équipe ne maîtrise pleinement :

- **système opaque, sans prise** : on subit le comportement plus qu'on ne le pilote ;
- **impossible à réimplémenter proprement en natif** : le portage Laravel est gelé (16.4 abandonnée), la dette s'accumule sur des shims legacy ;
- **barrière à l'onboarding** : un nouveau dev doit d'abord comprendre ce qu'est une GPO — coût d'entrée élevé ;
- **ordre d'exécution flou** : GPO *et* autres scripts tournent en parallèle, sans séquence claire ;
- **dichotomie imposée physique/logique** sur les workstationGroups (liaison GPO ↔ OU), même si la dichotomie reste pertinente par ailleurs ;
- **couplage AD ↔ Windows** structurel ;
- **divergence de fonctionnement Linux / Windows**.

### Problem Impact

La douleur est partagée par **tous les acteurs** : le refnum face à une UI technique, le dev face à un système non maîtrisable et non portable, l'équipe face à une dette qui ne se résorbe pas. Tant que ça dure, chaque évolution de configuration de poste reste coûteuse, risquée et dépendante d'un savoir Windows/AD rare.

### Why Existing Solutions Fall Short

Les GPO sont un standard Windows pensé pour un monde 100 % AD/Windows. Dans un produit qui se veut **multi-OS et multi-vertical** (AD = simple projection), elles imposent leur paradigme à tout le reste : couplage, divergence d'OS, opacité. Le portage natif l'a confirmé en échouant. À l'inverse, les scripts maison déjà servis par API montrent qu'une autre voie existe — mais ils cohabitent avec les GPO au lieu de les remplacer.

### Point de départ : le modèle cible existe déjà (côté Linux)

Découverte structurante (exploration legacy SE4 + natif SE5) : **un poste Linux n'utilise PAS les GPO**. Il ne lit jamais SYSVOL ni `Registry.pol`. Au boot/logon, un déclencheur local (cron `@reboot`, systemd, hook PAM) appelle des endpoints HTTP (`/gpo/applications.php`, `/gpo/network_out.php`, `/wpkg/linux_out.php`…) et le serveur lui renvoie du **bash généré à la volée** (`nmcli`, `gsettings`, `sed /etc/...`, `apt-get install`) qu'il exécute.

Autrement dit, **le paradigme visé — config-as-code servie par API, sans binaire ni SYSVOL — tourne déjà en production pour Linux.** Le projet n'est donc pas « inventer un successeur aux GPO » mais :

> **Aligner Windows sur le modèle Linux existant** — supprimer le chemin GPO binaire (SYSVOL/`Registry.pol`/WPKG client) au profit de scripts/politiques servis par API, identiques pour les deux OS.

Le modèle Linux apporte deux preuves de faisabilité : (1) le **déclenchement sans GPO existe** (cron/systemd/PAM) — précédent direct pour le « point d'ancrage » Windows à arbitrer ; (2) l'**abstraction « politique → actions OS-spécifiques » fonctionne** (une même intention se compile en `gsettings` sous Linux et en clé registre sous Windows, côté serveur).

**Vraie complexité restante :** réimplémenter côté serveur la génération des actions natives Windows (registre, déploiement logiciel avec dépendances) sans plus passer par le binaire GPO ; et réconcilier les imprimantes (Windows-only en SE4, nouveau système CUPS SE5 non branché côté Linux).

### Proposed Solution

Un **canal de configuration unique**, sous contrôle total de l'équipe (généralisation à Windows du modèle Linux déjà en place) :

- **Politiques versionnées** (git) plutôt que des objets binaires dans SYSVOL ;
- **Pilotées par API**, servies dynamiquement par SE5 (le canal `/workstation-config/*` est la fondation) ;
- **Logs unifiés** : un seul endroit pour comprendre ce qui s'applique, dans quel ordre ;
- **Identiques Linux / Windows** : même mécanisme, mêmes politiques, les différences d'OS deviennent un détail d'implémentation côté serveur ;
- **AD réduit à un point d'ancrage minimal** (forme à arbitrer avec l'architecte) ;
- **Déclenchement libre** : au boot, au logon, ou poussé à la demande — non contraint par le modèle GPO.

### Key Differentiators

- **Maîtrise** : du code natif Laravel que l'équipe comprend et fait évoluer, vs un héritage AD subi.
- **Versionnement & traçabilité** : tout changement de config est dans git et dans les logs.
- **Convergence OS** : un seul modèle mental au lieu de deux.
- **Découplage** : aligne le produit avec sa vision multi-vertical (AD = projection, pas socle).

---

## Target Users

### Primary Users

**1. Le refnum (référent numérique d'établissement) — celui qui *configure***
Profil non-dev, compétent mais pas ingénieur AD. Il veut appliquer des choses simples à un parc : « ces imprimantes dans cette salle », « ce logiciel sur ces postes », « ce fond d'écran ». Aujourd'hui il se heurte à une UI qui parle GPO, GUID, liaisons OU, héritage — un vocabulaire Windows/AD qui n'est pas le sien. **Succès pour lui :** raisonner en « politiques appliquées à des groupes de postes », jamais en GPO.

**2. Le dev SambaEdu (équipe SE5) — celui qui *maintient et fait évoluer***
Doit comprendre, débugger, étendre le système. Aujourd'hui : système opaque, à moitié porté, shims legacy, ordre d'exécution flou, savoir Windows/AD rare. **Succès pour lui :** du code Laravel natif lisible, versionné, traçable par logs, qu'un nouveau dev comprend sans cours sur les GPO.

**3. Le poste (machine Linux/Windows) — celui qui *consomme et applique***
Acteur non-humain mais central : il interroge le serveur et applique ce qu'on lui renvoie. C'est le client de l'API. Sa contrainte forte : **l'auth reste iso-legacy** (AD + SMB), parce qu'au déploiement tous les postes ne sont pas à jour — pas de secret par poste. **Succès pour lui :** récupérer et appliquer ses politiques de façon fiable, identique quel que soit l'OS.

### Secondary Users

- **Le nouveau dev qui arrive** : mesure directe du succès — temps de compréhension du système (aujourd'hui : long, à cause des GPO).
- **L'administrateur central (controlHub) — futur, via API** : pourra pousser des politiques cross-établissements. **Hors scope initial**, branché dans un second temps comme simple client de l'API. Sa seule exigence sur ce projet : que l'API soit conçue dès le départ pour qu'on puisse l'y raccorder sans refonte.

### User Journey

- **Refnum** : ouvre l'admin → choisit un groupe de postes → applique/retire des politiques → voit l'état appliqué. Plus jamais le mot « GPO ».
- **Dev** : modifie une politique = un commit git → déploie → lit un log unifié pour vérifier l'application sur les postes.
- **Poste** : au boot / logon / ou sur push → s'authentifie iso-legacy → récupère ses politiques via API → applique → remonte un log.
- **controlHub (futur)** : appelle l'API SE5 pour appliquer des politiques à un ou plusieurs établissements — même contrat que le client local.

---

## Success Metrics

Le succès se mesure à la disparition des douleurs identifiées, pas à des métriques d'usage. Trois familles :

### Pour le dev / l'équipe (maîtrise & dette)

- **Couverture native** : 100 % du comportement de configuration servi par du code Laravel natif — **zéro dépendance à un shim legacy** (`import_gpo`, `specialise_gpo`, `applications.php`) pour livrer une politique.
- **Canaux unifiés** : nombre de mécanismes d'application en parallèle ramené de **2 → 1** (plus de « GPO + autres scripts »).
- **Ordre d'exécution** : l'ordre d'application des politiques est **déterministe, documenté et visible dans un log unifié**.
- **Onboarding** : un nouveau dev comprend le système **sans avoir à apprendre ce qu'est une GPO** ni l'AD.

### Pour le refnum (simplicité)

- Le mot **« GPO » n'apparaît plus** dans l'interface refnum.
- Un refnum applique/retire une politique à un groupe de postes **sans vocabulaire AD** (GUID, liaison OU, héritage).

### Pour le poste & le découplage (convergence)

- **Convergence OS** : une seule et même mécanique pour Linux et Windows — **zéro branche de code spécifiquement divergente** au niveau du modèle de politique (les écarts restent un détail d'implémentation serveur).
- **Découplage AD** : les points de contact AD pour la configuration de poste réduits au **seul point d'ancrage** retenu.
- **Traçabilité** : 100 % des changements de politique sont **un commit git** et **apparaissent dans les logs unifiés**.

### Business Objectives

N/A — projet interne. L'objectif se résume à : **réduction de dette technique** et **capacité d'évolution** (système redevenu maîtrisable et extensible, notamment pour brancher le controlHub plus tard).

### Key Performance Indicators

- shims legacy de config restants : **→ 0**
- mécanismes d'application parallèles : **2 → 1**
- occurrences « GPO » dans l'UI refnum : **→ 0**
- branches de code OS-divergentes (modèle de politique) : **→ 0**
- couverture des politiques traçables (git + logs) : **→ 100 %**

> **Hypothèse à valider :** aucun seuil de performance/latence d'application retenu comme critère de succès (« le quand, on s'en moque »). À reconfirmer si un seuil terrain émerge.
