---
stepsCompleted: [1, 2, 3, 4, 5, 6]
workflow_completed: true
inputDocuments:
  - '_bmad-output/brainstorming/brainstorming-session-2026-06-10-1848.md'
date: 2026-06-11
author: henri
---

# Product Brief: Agent desired-state — successeur GPO (SE5)

> Ce brief remplace `product-brief-gpo-successor-2026-06-08.md` : la direction « dispatcher statique » y est absorbée comme bootstrap de l'agent. Il sert aussi de pitch au mainteneur du projet.

## Executive Summary

SambaEdu gère la configuration des postes Windows par un héritage de GPO et de scripts déclenchés par événements (boot, login, GPO refresh) — un modèle que SE5 a jusqu'ici fidèlement reproduit. Ce modèle est structurellement fragile : un événement raté produit une dérive silencieuse et éternelle, et personne ne sait ce qui est réellement appliqué sur un poste.

Ce brief propose le successeur : **SE5 détient l'état cible de chaque poste et de chaque session, fonction de (poste, user). Un agent de convergence unique sur le poste tire cet état à trois occasions (boot, login, timer), exécute la différence, et rapporte l'état réel.**

Ce n'est pas une invention : WPKG (déjà dans le parc), Intune, Kubernetes, Nix et ChromeOS ont tous convergé vers ce même modèle — état cible central, agent de convergence, rapport. SambaEdu l'applique à sa façon : **un ChromeOS souverain à axe machine lourd**, dont le « cloud » est le serveur d'établissement et le /home Samba. Le pilotage reste dans l'UI SE5 existante (pas de YAML, pas de git), aucune nouvelle dépendance AD n'est créée, et la transition recycle l'existant : **la dernière GPO de l'histoire installera l'agent — plus personne ne touchera jamais à une GPO.**

La cible de succès : la boucle complète démontrée en lab (UI → état cible → agent → rapport → UI) sur les premiers handlers — fond d'écran + overlay affichant identité du user et du parc — puis bascule par type de ressource, du simple au dur, sans mise en production avant parité complète avec le legacy.

---

## Core Vision

### Problem Statement

La configuration des postes Windows (raccourcis, fonds d'écran, applications, registre, imprimantes) est gérée par une chorégraphie impérative étalée dans le temps : GPO au boot, scripts au login, tâches planifiées, moments du cycle de vie. Ce modèle est **edge-triggered** : chaque réglage n'est appliqué qu'à l'instant d'un événement. Si l'événement est raté — GPO vide, helper absent, script en échec — le poste dérive, silencieusement, pour toujours. Le besoin métier, lui, est entièrement déclaratif : « voilà l'état attendu d'un poste de cette salle pour cet utilisateur ». Le décalage entre ce besoin (un état) et le mécanisme (une séquence d'événements) est la source de toute la complexité ressentie.

### Problem Impact

- **Dérives invisibles et irrattrapables** : les cas réels du lab le prouvent — GPO SYSVOL vides (postes joints qui n'installent rien), helpers clients manquants sur postes migrés (wallpaper/raccourcis KO côté poste malgré un serveur sain). Aucun mécanisme ne détecte ni ne corrige ces états.
- **Exécution en aveugle** : le serveur pousse et espère. Aucun reporting d'état réel, donc aucun diagnostic possible sans aller physiquement sur le poste.
- **Coût de maintenance** : chaque besoin nouveau exige d'écrire des scripts et des GPO à la main, dispersés entre SYSVOL, partages SMB et tâches planifiées — illisible pour les admins, pénible pour les mainteneurs (qui n'aiment pas non plus les GPO).
- **Verrou stratégique** : le transport de config par SYSVOL/GPO épaissit la dépendance à l'AD, alors que la trajectoire long terme du projet vise à l'alléger (option Keycloak).

### Why Existing Solutions Fall Short

- **Le legacy reproduit (GPO + scripts)** : fragile par construction (edge-triggered), aveugle (pas de rapport), et chaque portage natif dans SE5 reconduit la dette au lieu de la résorber — l'annulation du portage de création GPO native (story 16-4) l'a déjà acté.
- **Les outils desired-state du marché (Ansible, Puppet, DSC, Salt)** : le bon modèle, mais pilotés par YAML/git et pensés pour des équipes devops. Les admins d'établissement pilotent par l'UI SE5 — cette contrainte est certaine et non négociable.
- **Les MDM cloud (Intune, Jamf)** : le bon modèle aussi, mais cloud-first. Le serveur d'établissement est souverain : Internet coupé, le parc doit fonctionner sur le LAN seul. Éliminatoire.
- **WPKG** : preuve locale que le modèle agent + déclaratif fonctionne dans le parc — mais limité aux paquets, moteur figé, et déclenché... par une GPO.

Aucune solution existante ne combine : modèle de convergence moderne + pilotage UI non-devops + souveraineté du serveur local + compatibilité avec un parc hétérogène jamais à jour.

### Proposed Solution

Le socle en quatre lignes :

> SE5 détient l'état cible de chaque poste et de chaque session, fonction de (poste, user). Un agent de convergence unique sur le poste tire cet état à trois occasions (boot, login, timer), exécute la différence, et rapporte l'état réel.

Concrètement :

- **L'état cible est une projection JSON de la DB SE5 existante** (workstationGroups, bibliothèque de wallpapers, applications) servie par `GET /api/state?host=X&user=Y` — l'UI d'administration existe déjà, rien de nouveau à apprendre pour les admins.
- **L'agent** : service Windows SYSTEM au boot (portée machine) + compagnon de session au logon (portée user) — le modèle natif d'Intune/SCCM/Jamf, déjà présent dans le parc via WPKG. Le login ne dépend jamais du réseau (convergence asynchrone, cache du dernier état connu).
- **Des handlers test/apply/report par type de ressource** (~10 handlers de ~50 lignes) : fond d'écran, raccourcis, lecteurs, imprimantes, associations, registre, applications. Le moteur générique dérive l'impératif du déclaratif — plus personne n'écrit de chorégraphie.
- **Le rapport ferme la boucle** : état réel remonté en delta/hash, conformité et exceptions visibles dans l'UI. L'histoire du poste ne compte plus ; seul l'état présent compte (level-triggered).
- **Auth par bearer token per-host** : né à l'enrôlement, déposé par l'install, rotation glissante au check-in, révocation par événement. Canal neuf, zéro dépendance AD nouvelle.
- **Transition sans rupture** : la GPO-dispatcher figée devient le bootstrap de l'agent sur les postes migrés (avec approbation un-clic dans l'UI) ; bascule par type de ressource, du simple au dur (wallpaper/overlay → raccourcis → imprimantes → registre/associations → applis) ; cohabitation en lab uniquement, prod à parité complète.

### Key Differentiators

1. **Level-triggered là où le legacy est edge-triggered** — la réconciliation rattrape tout, y compris les postes qui ont raté des événements. La catégorie entière des « dérives silencieuses » disparaît.
2. **« Un ChromeOS souverain à axe machine lourd »** — l'expérience de gestion par règles de ChromeOS (penser en règles, jamais en postes), sans cloud : le serveur d'établissement et le /home Samba sont le cloud.
3. **Pilotage par l'UI SE5, pas par YAML** — le desired-state pour des admins non-devops ; aucun outil du marché n'offre ça.
4. **La transition recycle l'existant au lieu de le combattre** — la GPO figée devient le filet de bootstrap éternel, le POC overlay devient le premier handler, la chaîne iPXE fait de la réinstallation le handler ultime.
5. **Aucune nouvelle dépendance AD** — bearer token sur canal neuf ; chaque ressource basculée allège l'AD et rapproche de l'option Keycloak. L'architecture vieillit dans le bon sens : chaque appli qui devient web fait maigrir l'axe machine sans changer le moteur.
6. **Un agent par OS = couche de portabilité** — le contrat JSON est agnostique de l'OS ; le config-as-code Linux déjà en prod est le futur premier adaptateur du même contrat.

---

## Target Users

### Primary Users

**L'admin d'établissement — connaissance de surface, pilotage 100 % UI**

Profil type : la personne référente du parc dans un collège ou lycée. Elle sait utiliser SE5 — créer une salle, affecter un poste à un parc, choisir un fond d'écran, associer des applications à un groupe — sans compréhension technique de ce qui se passe en dessous. GPO, SYSVOL, scripts PowerShell : hors de portée, et ça doit le rester.

- **Son problème aujourd'hui** : quand un poste « ne fait pas ce qu'il devrait » (fond d'écran absent, appli manquante, raccourcis disparus), elle n'a aucun moyen de savoir pourquoi ni même de le détecter — le serveur affiche une configuration correcte, le poste fait autre chose. Seul recours : se déplacer physiquement, ou appeler à l'aide.
- **Ce que le système change** : elle continue d'utiliser exactement la même UI (l'état cible est une projection de la DB SE5 existante — rien de nouveau à apprendre). En plus, elle gagne ce qu'elle n'a jamais eu : un tableau de conformité. Chaque poste rapporte son état réel ; les écarts sont visibles, datés, et se corrigent seuls au prochain passage de l'agent. Le bouton « forcer la synchro » couvre les urgences rares.
- **Son moment « c'est exactement ce qu'il me fallait »** : changer un fond d'écran dans l'UI et constater que toute la salle a convergé — y compris le poste qui était éteint ce jour-là et qui rattrape tout seul au prochain boot.
- **Décision métier exposée** : pour chaque réglage, elle choisit dans l'UI entre « géré strictement » (l'agent ré-impose) et « valeur par défaut, déviation permise » (l'agent respecte les changements faits sur place par un prof). C'est elle qui arbitre, pas le code.

### Secondary Users

**Le mainteneur du projet SambaEdu**

Il écrit et maintient aujourd'hui les GPO et scripts du legacy — et il ne les aime pas. C'est l'audience de ce brief. Ce que le modèle lui offre : chaque vice de Windows (hash UserChoice des associations, etc.) confiné dans **un** handler test/apply testable d'environ 50 lignes, écrit une fois — au lieu d'une chorégraphie dispersée entre SYSVOL, partages et tâches planifiées. L'argument décisif : « la dernière GPO de l'histoire installera l'agent, plus personne ne touchera jamais à une GPO ». Le chiffrage est honnête : on compte les handlers, on ne promet pas la magie.

**Le technicien externe (via controlHub)**

Intervient à distance par Veyon/Guacamole, et pourra potentiellement agir sur la configuration — mais toujours par le canal controlHub ⇔ SE5, côté serveur. Il ne touche jamais aux postes ni à l'agent directement : pour le système de convergence, ses actions sont indistinguables de celles d'un admin local. Aucun mécanisme spécifique à prévoir côté agent.

### Bénéficiaires indirects

**Profs et élèves** — bénéficiaires passifs : des postes cohérents dans toute la salle, un login qui ne dépend jamais du réseau (convergence asynchrone, jamais bloquante), et un overlay discret affichant l'identité de la session et du parc. Le prof qui personnalise un réglage en salle est respecté ou repris selon le mode strict/défaut choisi par l'admin — il n'interagit pas avec le système, c'est le système qui est conçu pour ne pas se battre contre lui.

### User Journey

**Parcours de l'admin d'établissement :**

1. **Découverte** : la mise à jour SE5 active le module ; ses salles, parcs et bibliothèques existants deviennent automatiquement l'état cible — rien à migrer, rien à ressaisir.
2. **Enrôlement** : postes neufs enrôlés automatiquement par la chaîne iPXE (token déposé à l'install) ; postes migrés : l'agent posé par le bootstrap GPO apparaît dans l'UI, elle approuve d'un clic.
3. **Usage quotidien** : identique à aujourd'hui — elle administre des règles (salle, parc, groupe), jamais des postes individuels. Le poste n'apparaît qu'en reporting de conformité ou d'exception.
4. **Moment de bascule** : premier écart détecté et auto-corrigé sans qu'elle se déplace — le réflexe « j'appelle quelqu'un » devient « je regarde le tableau de conformité ».
5. **Long terme** : la réinstallation iPXE devient son handler ultime (> 15 min de debug → réinstalle) ; la confiance dans le tableau remplace la tournée des salles.

**Parcours du mainteneur :**

1. **Découverte** : ce brief + démo lab en live (UI → état cible → agent → rapport → UI) sur le handler wallpaper/overlay.
2. **Adoption** : il écrit son premier handler contre le contrat test/apply/report — et constate qu'il tient en ~50 lignes testables.
3. **Long terme** : plus aucune GPO à écrire, jamais. Le legacy se réduit à un bootstrap figé qu'on ne touche plus.

---

## Success Metrics

La philosophie de mesure découle du modèle lui-même : le système rapporte l'état réel du parc, donc **le produit fabrique ses propres métriques**. Le taux de conformité n'est pas un indicateur qu'on instrumente après coup — c'est la fonctionnalité.

**Succès utilisateur (admin d'établissement) :**

- Un changement de configuration dans l'UI converge sur toute la salle sans intervention sur les postes — y compris les postes éteints au moment du changement (rattrapage au boot suivant).
- Les écarts entre état cible et état réel sont visibles dans l'UI sans déplacement physique ; ils se résorbent seuls ou s'expliquent (exception rapportée).
- Zéro compétence nouvelle requise : l'admin n'a jamais à toucher autre chose que l'UI SE5 existante.

**Succès mainteneur :**

- Un besoin de configuration nouveau = un handler test/apply/report (~50 lignes testables), jamais une GPO ni un script dispersé.
- Après bascule : zéro GPO modifiée, le bootstrap figé excepté — « la dernière GPO de l'histoire ».

### Business Objectives

Progression par paliers, **le plus vite possible**, sans date calendaire — chaque palier est un gate du suivant :

1. **Adhésion** — le mainteneur du projet valide le modèle (ce brief en est l'outil), démo live à l'appui.
2. **Palier 1 : lab de test** — boucle complète sur un poste enrôlé : UI → état cible → agent → convergence → rapport → UI, sur les premiers handlers (fond d'écran + overlay identité user/parc).
3. **Palier 2 : environnement réaliste** — parité legacy par types de ressources sur un parc représentatif (postes migrés + neufs, salles multiples) : bascule du simple au dur (wallpaper/overlay → raccourcis → imprimantes → registre/associations → applis).
4. **Palier 3 : collège béta** — un établissement réel en conditions de production, à parité complète. Aucune mise en production avant parité (décision actée) ; la cohabitation des deux systèmes sur un même type de ressource n'existe qu'en lab.
5. **Généralisation** — extinction du transport GPO sur le parc ; objectif stratégique atteint quand chaque composante AD abandonnée rapproche de l'option Keycloak (zéro nouvelle dépendance AD introduite en chemin).

### Key Performance Indicators

| Indicateur | Cible | Mesure |
|---|---|---|
| Taux de conformité du parc | Visible par salle/parc dans l'UI ; écarts datés | Rapports d'état des agents (le produit se mesure lui-même) |
| Temps de convergence d'un changement | ≤ 1 cycle naturel (boot, login ou timer) ; bouton « forcer la synchro » pour les urgences | Horodatage changement UI → rapport de conformité |
| Impact login | Zéro dépendance réseau au login ; convergence asynchrone, jamais bloquante | Mesure du temps d'ouverture de session avec/sans serveur joignable |
| Poids du reporting | « Conforme » = 1 ligne (delta/hash) ; le serveur tient 600 postes × 96 check-ins/jour | Volumétrie des rapports en palier 2 |
| Sûreté des updates agent | Déploiement canari systématique (1 poste → 1 salle → 1 étab) ; un agent briqué se réinstalle par le bootstrap GPO | Procédure vérifiée à chaque release agent |
| Dérive GPO post-bascule | 0 GPO créée ou modifiée (hors bootstrap figé) | Audit SYSVOL |
| Périmètre de l'agent | Converger l'état, rapporter l'état — rien d'autre (anti couteau-suisse) | Revue de scope à chaque ajout de fonctionnalité |

---

## MVP Scope

### Core Features

Le MVP = le palier 1 : **la boucle complète qui tourne sur un poste de lab**, démontrable en live. Six briques :

1. **Endpoint état cible** — `GET /api/state?host=X&user=Y` renvoie le JSON {machine, session} projeté depuis la DB SE5 existante (workstationGroups, bibliothèque wallpapers). Réutilise et étend l'endpoint overlay du POC.
2. **Agent squelette Windows** — service SYSTEM (check-in boot + timer) + compagnon de session (logon), cache local du dernier état connu, convergence asynchrone jamais bloquante au login. Signé dès le premier prototype (CA interne, racine déployée par l'install).
3. **Deux premiers handlers** test/apply/report : **wallpaper** (depuis la bibliothèque d'assets) et **overlay** (Rainmeter affichant identité user + parc — recycle le POC existant).
4. **Reporting** — `POST /api/report` en delta/hash (« conforme » = 1 ligne) ; vue minimale dans l'UI : état rapporté par poste, écarts datés.
5. **Enrôlement par la porte iPXE** — token Sanctum per-host né à l'enrôlement, déposé à l'install, rotation glissante au check-in, révocation par événement. Une seule porte au MVP : le poste neuf (la chaîne iPXE existe déjà).
6. **Schéma JSON v1 complet** — incluant dès le départ le booléen strict/défaut par item (rétrofit impossible sans casser les handlers), même si la valeur est câblée en dur côté serveur au début.

**Critère de complétude du MVP** : la démo live — changer le wallpaper d'un parc dans l'UI, voir le poste converger, voir le rapport remonter dans l'UI. La boucle UI → état → agent → rapport → UI fermée de bout en bout.

### Out of Scope for MVP

- **Les autres handlers** (raccourcis, lecteurs, imprimantes, registre, associations) — arrivent un par un au palier 2, du simple au dur.
- **Les applications** — dernier type de ressource basculé ; `applications` (impératif) et wpkg (déclaratif) continuent tels quels d'ici là.
- **La porte d'enrôlement des postes migrés** (bootstrap GPO-dispatcher + approbation un-clic) — palier 2, quand on attaque le parc réaliste.
- **L'exposition UI du mode strict/défaut** — le schéma le porte dès v1, l'UI l'exposera quand les handlers concernés existeront.
- **Tout ce qui n'est pas « converger l'état, rapporter l'état »** — remote control, inventaire, métrologie : un AUTRE logiciel (anti couteau-suisse, sabotage #30).
- **Push temps réel et convergence mid-session** — la fraîcheur laxe est actée (boot/login/timer + bouton forcer) ; mid-session = bonus éventuel, jamais une exigence.
- **Agent Linux** — le config-as-code Linux en prod reste tel quel ; il deviendra le premier adaptateur du contrat plus tard.
- **Certificat de signature public (OV)** — la CA interne suffit pour lab et premiers déploiements ; le certificat public (~300-500 €/an) se budgète pour la diffusion large.

### MVP Success Criteria

- La démo live fonctionne de manière répétable sur le lab de test (gate du palier 2).
- Le login d'un poste enrôlé n'est jamais ralenti, serveur joignable ou non.
- Le mainteneur du projet a vu la démo et adhère au modèle (gate de l'évangélisation).
- L'agent n'a aucune dépendance AD nouvelle (le token suffit à tout).

### Future Vision

La trajectoire au-delà du MVP suit les paliers : environnement réaliste (tous les handlers, postes migrés, parité legacy), collège béta, puis généralisation et extinction du transport GPO. Au-delà :

- **Licences à pool** — sièges inventoriés dans SE5, affectation f(poste), libération à la réinstall : sous-produit gratuit du reporting.
- **Un agent par OS** — le contrat JSON est la couche de portabilité ; Linux (adaptateur du config-as-code existant), et tout OS futur = écrire son agent.
- **L'architecture vieillit dans le bon sens** — chaque appli qui devient web fait maigrir l'axe machine sans changer le moteur ; convergence spontanée vers le modèle ChromeOS complet.
- **Option Keycloak** — chaque composante AD éteinte (le transport GPO en premier) rapproche de la sortie AD pour l'identité. Le successeur GPO est conçu pour ne jamais l'éloigner.
