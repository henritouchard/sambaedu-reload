---
stepsCompleted: [step-01-init, step-02-discovery, step-02b-vision, step-02c-executive-summary, step-03-success, step-04-journeys, step-05-domain, step-06-innovation, step-07-project-type, step-08-scoping, step-09-functional, step-10-nonfunctional, step-11-polish, step-12-complete, step-e-01-discovery, step-e-02-review, step-e-03-edit]
lastEdited: '2026-03-25'
editHistory:
  - date: '2026-03-25'
    changes: 'Intégration Epic 1bis (cloisonnement legacy) : Executive Summary, tableau MVP, stratégie MVP, risques'
inputDocuments: [_bmad-output/brainstorming/brainstorming-session-2026-03-16-0900.md]
workflowType: 'prd'
brainstormingCount: 1
briefCount: 0
researchCount: 0
projectDocsCount: 0
classification:
  projectType: web_app + saas_b2b
  domain: edtech/govtech
  complexity: high
  projectContext: brownfield
---

# Product Requirements Document - codebase

**Author:** henri
**Date:** 2026-03-16

## Executive Summary

SambaEdu-Reload (SER) et irundoo remplacent le legacy SambaEdu — un système de gestion d'infrastructure IT pour établissements scolaires publics français, techniquement épuisé après plus d'une décennie sans refonte architecturale. Le projet cible les **responsables de collège** qui gèrent au quotidien utilisateurs, machines, partages de fichiers, impression, réseau et déploiement Windows, ainsi que les **techniciens DSI académiques** qui supervisent la flotte d'établissements.

Le legacy souffre de défauts structurels cumulés : Active Directory utilisé comme base de données universelle (performances dégradées, requêtes non optimisées), absence de séparation des responsabilités (gestion locale et gestion centrale mélangées dans un même codebase), code non typé et mal décomposé, UI obsolète avec doublons de fonctionnalités, mauvaises pratiques de versioning, et absence totale de documentation technique — rendant l'onboarding de nouveaux développeurs impossible en pratique.

L'objectif : qu'un responsable de collège comprenne ce qu'il fait sans documentation, que tout soit fluide et rapide, et qu'il n'y ait plus de bugs absurdes.

### Ce qui rend ce projet spécial

La décision centrale est une **séparation de domaines assumée comme principe de conception** : SER gère un unique serveur d'établissement (open-source), irundoo gère une flotte de serveurs multi-établissements (commercial). Ce ne sont pas deux modules d'un même logiciel — ce sont deux produits distincts parce que leurs domaines sont ontologiquement différents. Aucune notion de "central" ne doit exister dans SER.

Cette dualité rend possible :
- Un SER standalone, déployable indépendamment, contribuable par la communauté
- Un irundoo qui gère la complexité multi-UAI (itinérants, filtrage par établissement, MassActions) sans polluer la logique locale
- Une architecture 3 couches (SFC Livewire → Services → Models) qui découple développement front et back — multiplicateur d'équipe direct
- Un code typé, structuré et documenté qui permet le renouvellement des développeurs

L'autre décision structurante est le **cloisonnement legacy** : le volume de code PHP hérité (wpkg 50 fichiers, iPXE 72 fichiers, imprimantes 11 fichiers…) rend la réécriture séquentielle trop lente pour atteindre la parité. Les modules legacy sont encapsulés dans un sous-dossier `legacy/` avec des shims qui redirigent leurs accès données (LDAP, MySQL) vers la couche Laravel (Eloquent/PostgreSQL). Les utilisateurs accèdent aux modules non encore réécrits via l'interface Laravel sans rupture fonctionnelle — la réécriture native avance en parallèle sans bloquer la livraison.

## Success Criteria

### Succès Utilisateur

- Un responsable de collège sans formation préalable prend en main le logiciel en quelques minutes sur les opérations courantes (gestion utilisateurs, machines, salles)
- Chaque action est compréhensible sans documentation : les libellés, les flux et les retours d'état sont auto-explicites
- Les opérations courantes s'exécutent sans latence perceptible (fini les lenteurs AD du legacy)
- Zéro bug absurde visible en production (régressions inattendues, comportements incohérents)

### Succès Technique

- Chaque fonctionnalité livrée est couverte par des tests automatisés avant passage en bêta
- Suite de tests de parité legacy opérationnelle comme filet de régression permanent
- Snapshots Proxmox systématiques avant toute migration → rollback disponible en < 5 min
- Migration MySQL → PostgreSQL complète et validée avant Sprint 1
- Architecture 3 couches respectée : zéro logique métier dans les SFC Livewire, zéro notion de "central" dans SER

### Succès Bêta

- Battle test manuel sur VM locale (casser sans risque) + sandbox prod avant chaque déploiement collège
- Validation collège par collège sur 3 établissements bêta — passage en prod selon jugement de Henri
- Rollback disponible et testé avant chaque migration bêta

### Succès Business

- irundoo : objectifs chiffrés à définir ultérieurement
- SER open-source : codebase contributable (structure lisible, documentation technique suffisante pour onboarding externe)

## Parcours Utilisateurs

### Parcours 1 — Marie, Responsable de Collège (chemin principal)

Marie gère le collège Pasteur à Bordeaux. Lundi matin, 8h05 : un nouvel élève arrive en urgence, transféré d'un autre établissement. Dans l'ancien SambaEdu, ça prend 20 minutes, implique de connaître la structure OU de l'AD et oblige à appeler le technicien en cas d'erreur. Avec SER, Marie ouvre l'interface, clique sur "Nouvel utilisateur", saisit prénom / nom / classe. Aucune notion d'AD, aucun jargon technique. Le compte est créé, le home directory provisionné, les droits applicatifs calculés automatiquement. À 8h12, l'élève se connecte sur un poste Windows de la salle. Marie n'a jamais su qu'un AD existait.

*Révèle les besoins : formulaires de création utilisateur épurés, abstraction complète de l'AD, feedback de succès immédiat, gestion automatique des droits à la création.*

---

### Parcours 2 — Marie, Responsable de Collège (cas limite : élève itinérant)

Même scénario, mais l'élève vient du collège Rimbaud et sera présent 2 jours par semaine à Pasteur. SER affiche clairement "cet utilisateur est itinérant — ses droits sont limités (quota réduit, profil restreint)". Marie n'a rien à configurer manuellement : irundoo a déjà défini le lien user↔UAI et transmis les attributs adaptés à SER. Elle voit le statut, comprend la situation, valide. Aucune action technique requise.

*Révèle les besoins : affichage du statut itinérant, droits différenciés automatiques, communication irundoo → SER transparente pour l'utilisateur final.*

---

### Parcours 3 — Thomas, Enseignant Délégué (gestion de salle)

Thomas enseigne la technologie en salle B12. Il a des droits délégués sur cette salle uniquement. Avant un cours, il ouvre SER, voit sa salle, allume les postes via WOL, vérifie que le profil applicatif "cours-techno" est actif. En fin de cours, il éteint le parc. Il ne voit jamais les autres salles, jamais les autres utilisateurs, jamais les paramètres réseau. Son interface est volontairement réduite à son périmètre de délégation.

*Révèle les besoins : système de délégation granulaire, vues filtrées par périmètre, actions batch sur parc délégué (WOL, extinction, profil), isolation stricte des données hors périmètre.*

---

### Parcours 4 — Karim, Technicien DSI Académique (supervision et intervention)

Karim supervise 12 collèges depuis irundoo. Il voit sur son tableau de bord que le collège Hugo a une anomalie DHCP. Il clique sur l'instance SER du collège Hugo — et se retrouve directement dans l'interface SER, déjà authentifié, sans re-saisir ses identifiants (SSO via Keycloak). Il diagnostique : une réservation DHCP est en conflit. Il la corrige depuis SER, revient sur irundoo, marque l'incident résolu. Le tout sans manipulation de credentials répétée.

*Révèle les besoins : SSO irundoo → SER (Keycloak, architecture préparée dès MVP), vue flotte multi-instances dans irundoo, détection d'anomalies par instance, navigation fluide inter-instances.*

---

### Parcours 5 — Contributeur Open-Source SER

Alex est développeur, intéressé par SER après l'avoir vu sur GitHub. Il clone le repo, ouvre `documentation/architecture/` et comprend la structure en 30 minutes : architecture 3 couches, pattern Services/Legacy/ documenté avec source legacy + raison du refactoring futur, tests d'intégration contre AD Docker en local. Il ouvre un ticket, propose une amélioration sur le HomeDirectoryService, sa PR respecte les conventions existantes. Le maintainer peut la reviewer sans tout expliquer depuis zéro.

*Révèle les besoins : documentation technique de contribution, tests locaux reproductibles (AD Docker), conventions de code explicites, pattern Services/Legacy/ commenté systématiquement.*

---

### ⚠️ Zone à Investiguer — Sessions Cloud Windows

Le legacy SambaEdu inclut une gestion de sessions Windows cloud dont le fonctionnement précis n'a pas encore été étudié. Ce périmètre doit être analysé avant la finalisation du MVP pour déterminer : quel composant (SER, irundoo, ou les deux) le gère, comment il s'intègre à l'architecture AD/Keycloak, et quelles exigences fonctionnelles spécifiques il génère. Un parcours utilisateur dédié sera ajouté une fois ce mécanisme compris.

---

### Résumé des Capacités Révélées par les Parcours

| Capacité | Parcours source |
|---|---|
| Création utilisateur sans jargon AD | Marie P1 |
| Gestion automatique home dirs + droits à la création | Marie P1 |
| Statut et droits itinérants (lien irundoo → SER) | Marie P2 |
| Système de délégation granulaire (enseignants) | Thomas P3 |
| Vues filtrées par périmètre de délégation | Thomas P3 |
| Actions batch parc (WOL, extinction) | Thomas P3 |
| SSO irundoo → SER (Keycloak) | Karim P4 |
| Tableau de bord flotte multi-instances | Karim P4 |
| Navigation inter-instances sans re-login | Karim P4 |
| Documentation technique contributeur | Alex P5 |
| Tests locaux reproductibles (AD Docker) | Alex P5 |
| Sessions cloud Windows | ⚠️ À investiguer |

## Exigences Domaine

### Conformité & Réglementaire

- **RGPD** — les données traitées (noms, identifiants, home directories d'élèves et enseignants) sont des données personnelles dont le responsable de traitement est l'établissement scolaire. SER doit permettre la gestion des durées de conservation et la suppression effective des données (home dirs en 2 temps : /home/trash/ → suppression permanente)
- **Mineurs** — les élèves sont des mineurs ; les logs et données d'accès doivent être traités avec les précautions appropriées
- **Éducation Nationale** — contraintes et standards SI académiques à préciser (SIECLE, formats d'import rentrée) — post-MVP

### Contraintes Techniques

- **Local-first** — SER doit fonctionner sur un réseau pédagogique isolé (pas d'accès internet garanti depuis l'infrastructure locale) ; toutes les fonctions MVP opèrent sans dépendance réseau externe
- **Infrastructure hétérogène** — chaque collège dispose de sa propre infrastructure Proxmox/AD ; SER ne peut pas supposer de standardisation matérielle inter-établissements
- **Mises à jour** — gérées manuellement par l'équipe pour la période MVP ; pas de contrainte d'auto-update à ce stade

### Intégrations Requises

| Système | Composant | Sprint | Notes |
|---|---|---|---|
| AD/LDAP local | SER | MVP core | Via LdapRecord |
| CUPS | SER | Sprint 1 | Gestion imprimantes |
| DHCP/DNS | SER | Sprint 2 | Réservations + baux |
| GPEI | SER (standalone) + irundoo (orchestration optionnelle) | À planifier | SER peut recevoir et traiter directement un fichier GPEI (mode standalone). Quand irundoo est présent, il parse le fichier et dispatche les mises à jour vers les instances SER concernées selon leur UAI. Fichiers d'exemple disponibles. |
| AAF / SIECLE | irundoo → SER | Post-MVP | Import rentrée scolaire — se baser sur l'implémentation legacy comme référence |

### Pattern d'Intégration Académique (irundoo)

irundoo joue le rôle d'**orchestrateur des fichiers académiques** : il reçoit les fichiers de source académique (GPEI, et à terme AAF/SIECLE), les parse, et pousse les mises à jour différentielles vers les instances SER concernées selon leur UAI. Ce pattern évite que chaque établissement gère manuellement ses imports et centralise la logique de transformation des formats académiques.

### Risques Domaine

- **Sessions cloud Windows** — mécanisme existant dans le legacy, non encore analysé ; à investiguer avant finalisation MVP (impact potentiel sur la gestion des profils et home dirs)

## Exigences Spécifiques au Type de Projet

### Vue d'ensemble

Dual product : **SER** (web_app, open-source, local par établissement) + **irundoo** (SaaS B2B, commercial, central multi-UAI). Les deux partagent la stack Laravel + Livewire 4 + DaisyUI + PostgreSQL mais opèrent sur des domaines distincts et n'ont aucune dépendance de livraison.

---

### SER — Web App

#### Architecture de Rendu

- **MPA avec composants réactifs** (Livewire 4) — pas de SPA ; le rendu côté serveur est la norme, la réactivité est apportée par Livewire au niveau composant
- **Architecture 3 couches** : SFC Livewire (UI + validation) → Services (métier, réutilisable par API future) → Models (SQL + AD)

#### Temps Réel & Actions Longues

- Pas d'exigences de temps réel strict — pas de WebSocket ou streaming continu
- Les actions longues (WOL, scripts machine, tâches cron) s'appuient sur **ControlHubTasks** avec feedback asynchrone (polling ou push Livewire)
- Extensible : d'autres points temps-réel peuvent être ajoutés sans refonte

#### Support Navigateurs & Compatibilité

- **Navigateurs modernes** (Chrome, Firefox, Edge récents) — pas de support IE ou navigateurs legacy
- Outil interne : SEO non pertinent, pas de contrainte de référencement
- Responsive design non prioritaire (usage sur postes fixes en établissement)

#### Performance

- Cible : nettement supérieure au legacy (élimination des lenteurs AD comme base de données universelle)
- Pas d'exigences de latence instantanée au sens strict — fluidité perçue suffisante
- Opérations critiques (login, liste utilisateurs, état machines) doivent être perceptiblement rapides

---

### irundoo — SaaS B2B

#### Modèle Multi-Tenant

- Tenant = établissement scolaire identifié par **UAI**
- Chaque instance SER est rattachée à un UAI ; irundoo maintient les liens user↔UAI
- Les itinérants (users présents sur plusieurs UAI) sont gérés dans irundoo, pas dans SER

#### Modèle de Permissions (Spatie)

- **Droits additifs** : droits de groupe + droits individuels (union, pas intersection)
- L'administrateur irundoo attribue des responsabilités à des **groupes** et à des **individus**
- Tout membre d'un groupe hérite des droits du groupe + ses droits individuels propres
- Modèle à construire progressivement — base Spatie déjà prévue, niveaux DSI/technicien/admin à affiner

#### Modèle d'Abonnement

- À définir — direction probable : facturation par établissement ou par académie
- Hors scope MVP

#### Intégrations Clés

- **SSO Keycloak** → navigation irundoo → SER sans re-login (Phase 2, architecture préparée)
- **GPEI** → orchestration dispatch vers instances SER (standalone SER aussi supporté)
- **AAF/SIECLE** → post-MVP

## Scoping & Développement par Phases

### Stratégie MVP

**Approche :** MVP de remplacement (parité legacy) — le produit n'est utile qu'à partir du moment où il peut remplacer intégralement le legacy SambaEdu sur un établissement. Pas de valeur partielle : une bascule à 80% n'est pas une bascule. La parité est atteinte via un mix de modules réécrits nativement en Laravel et de modules legacy cloisonnés (Epic 1bis) — les modules PHP legacy sont encapsulés avec des shims LDAP→Eloquent et MySQL→Eloquent, permettant de les servir via l'interface Laravel sans attendre leur réécriture complète.

**Philosophie :** chaque sprint doit livrer un périmètre testable en isolation (VM locale puis sandbox prod), mais la mise en production réelle ne se fait qu'une fois la parité atteinte.

**Équipe MVP :** développement bi-développeur (Henri côté back/archi + développeur secondaire côté front/Livewire), développés en parallèle sur SER et irundoo.

### Fonctionnalités MVP (Phase 1 — Parité Legacy)

**Parcours utilisateurs couverts :** Marie P1 (création user), Marie P2 (itinérant), Thomas P3 (délégation salle), Karim P4 (supervision — SSO partiel), Alex P5 (contribution)

**Capacités indispensables :**

| Sprint | SER | irundoo |
|---|---|---|
| Sprint 0 | Migration MySQL → PostgreSQL (associations AppProfile ↔ Apps) | — |
| Epic 1bis | Cloisonnement legacy : error logger unifié, bootstrap legacy + shim LDAP→Eloquent, shim MySQL→Eloquent, intégration modules par tiers (Tier 1/2/3). Les modules PHP legacy non encore réécrits sont accessibles via l'interface Laravel sans rupture fonctionnelle. | — |
| Sprint 1 | FS individuel (home dirs + quotas XFS), partages classes + ACLs, imprimantes (Services/Legacy/), délégations, parcs batch + cron | Liens user↔UAI, itinérants |
| Sprint 2 | DHCP réservations + baux + DNS, import CSV machines/sites | — |
| Sprint 3 | GPOs (Services/Legacy/), WPKG MVP (packages + logs + rapports), scripts démarrage | GPEI standalone (SER) + orchestration (irundoo) |

**⚠️ Périmètre à valider avant finalisation MVP :** sessions cloud Windows (mécanisme legacy non encore analysé)

### Post-MVP (Phase 2 — Growth)

- SSO Keycloak irundoo → SER (architecture préparée dès MVP)
- irundoo MassActions migration (rolling release requis)
- AAF/SIECLE import (rentrée scolaire)
- Modèle d'abonnement irundoo (à définir)
- Permissions irundoo avancées (DSI/technicien/admin — base Spatie posée en MVP)

### Vision (Phase 3 — Expansion)

- Migration progressive automatisée via irundoo
- Modèle contributeur SER open-source mature (documentation complète, CI publique)
- iOS/Android (très long terme, hors scope)

### Stratégie de Risques

| Risque | Mitigation |
|---|---|
| Régressions silencieuses | Snapshots Proxmox avant chaque migration + suite de tests parité |
| Sessions cloud Windows inconnues | Investigation avant finalisation Sprint 3 — peut impacter home dirs et profils |
| Bus factor (équipe réduite) | Documentation SER/documentation/ complétée au fil du dev + code auto-documenté via types et Services/Legacy/ commentés |
| Volume legacy trop important pour réécriture séquentielle | Cloisonnement Epic 1bis — modules encapsulés dans `legacy/` avec shims LDAP→Eloquent et MySQL→Eloquent, livraison immédiate via interface Laravel pendant que la réécriture native avance |
| Couplage SER/irundoo | Aucune dépendance de livraison — SER standalone par design |

## Exigences Fonctionnelles

> ⚠️ **Note générale :** Toutes les fonctionnalités listées ci-dessous nécessitent une analyse approfondie du comportement du legacy avant implémentation (cas limites, données manipulées, règles métier implicites).

### Gestion des Utilisateurs (SER)

- **FR1 :** Le responsable de collège peut créer un compte utilisateur (élève ou enseignant) sans exposer de concepts AD ou LDAP
- **FR2 :** Le système provisionne automatiquement le home directory et les droits applicatifs à la création d'un utilisateur ou à sa première connexion si le home directory est manquant (cas des utilisateurs existants créés directement dans l'AD depuis le central)
- **FR3 :** Le responsable peut modifier les attributs d'un utilisateur (classe, quota, profil applicatif)
- **FR4 :** Le responsable peut désactiver et supprimer un compte utilisateur avec archivage du home directory en deux temps (corbeille → suppression permanente)
- **FR5 :** Le système affiche le statut itinérant d'un utilisateur et applique automatiquement les droits différenciés associés
- **FR6 :** Le responsable peut importer des utilisateurs depuis un fichier externe (GPEI standalone)

### Gestion des Machines & Parcs (SER)

- **FR7 :** Le responsable peut consulter l'inventaire des machines par salle et par parc
- **FR8 :** Le responsable peut effectuer des actions unitaires sur une machine (allumage WOL, extinction, reboot)
- **FR9 :** Le responsable peut effectuer des actions batch sur un parc entier (allumage, extinction, reboot)
- **FR10 :** Le responsable peut programmer des actions cron sur un parc (allumage/extinction planifiés)
- **FR11 :** Le responsable peut importer des machines et des sites depuis un fichier CSV
- **FR12 :** Le système associe un profil applicatif (AppProfile) à des postes individuels et à des groupes de salles indépendamment de la hiérarchie OU

### Système de Fichiers (SER)

- **FR13 :** Le système crée et gère les home directories individuels des utilisateurs avec quotas XFS
- **FR14 :** Le responsable peut créer et configurer des répertoires de partage par classe avec ACLs POSIX héritées
- **FR15 :** Le responsable peut gérer les droits d'accès sur les partages de classe (lecture, écriture, dossier échange)
- **FR16 :** Le système supprime les home directories en deux étapes (archivage dans /home/trash/ puis suppression permanente optionnelle)

### Impression (SER)

- **FR17 :** Le responsable peut consulter la liste des imprimantes et leurs détails
- **FR18 :** Le responsable peut ajouter, configurer et supprimer des imprimantes via CUPS
- **FR19 :** Le responsable peut gérer les pilotes Windows associés aux imprimantes

### Réseau (SER)

- **FR20 :** Le responsable peut consulter et gérer les réservations DHCP et les baux actifs
- **FR21 :** Le responsable peut configurer les entrées DNS
- **FR22 :** Le responsable peut importer des réservations DHCP en masse

### Déploiement Windows (SER)

- **FR23 :** Le responsable peut consulter et gérer les GPOs via l'interface SER (Services/Legacy/)
- **FR24 :** Le responsable peut gérer les packages WPKG (définition, association aux profils, déclenchement au démarrage)
- **FR25 :** Le responsable peut consulter les logs WPKG et les rapports d'installation
- **FR26 :** Le responsable peut gérer les scripts de démarrage Windows

### Délégations & Permissions (SER)

- **FR27 :** L'administrateur peut attribuer des droits délégués à un utilisateur sur un périmètre limité (salle, parc)
- **FR28 :** Un utilisateur délégué ne peut voir et agir que sur son périmètre de délégation
- **FR29 :** Le système calcule les droits applicatifs comme l'union des droits de groupe et des droits individuels (Spatie)

### Gestion des Établissements & Itinérants (irundoo)

- **FR33 :** irundoo maintient les liens utilisateur↔établissement (UAI) pour chaque instance SER
- **FR34 :** irundoo gère les utilisateurs itinérants avec des attributs spécifiques (quota réduit, droits limités) par lien user↔UAI
- **FR35 :** irundoo filtre et transmet à chaque instance SER uniquement les utilisateurs relevant de son UAI (locaux + itinérants)

### Imports & Intégrations Académiques (irundoo + SER)

- **FR36 :** SER peut recevoir et traiter un fichier GPEI en mode standalone
- **FR37 :** irundoo peut parser un fichier GPEI et dispatcher les mises à jour vers les instances SER concernées selon leur UAI
- **FR38 :** SER synchronise les données utilisateurs et machines avec l'Active Directory local via LdapRecord
- **FR39 :** SER gère les applications autorisées à l'installation en mode standalone (apps définies localement) ; quand irundoo est présent, irundoo définit les apps autorisées et SER s'y conforme

## Exigences Non-Fonctionnelles

### Performance

- **NFR1 :** Les opérations courantes (chargement liste utilisateurs, liste machines, état parc) s'affichent en moins de 2 secondes sur un réseau local établissement
- **NFR2 :** Les actions longues (WOL, scripts, tâches cron) donnent un retour de démarrage immédiat et un feedback d'état sans bloquer l'interface
- **NFR3 :** Aucune opération ne nécessite une requête LDAP non indexée ou un scan complet de l'annuaire AD

### Sécurité

- **NFR4 :** Les mots de passe ne transitent jamais en clair dans l'application ni dans les sessions (correction d'une faille connue du legacy)
- **NFR5 :** L'accès CAS/SSO est sécurisé via SSL/TLS (correction d'une faille connue du legacy)
- **NFR6 :** L'accès administrateur ne dispose d'aucun bypass non authentifié (correction d'une faille connue du legacy)
- **NFR7 :** Les données personnelles des utilisateurs (élèves et enseignants) sont accessibles uniquement aux rôles autorisés selon le principe de moindre privilège (Spatie)
- **NFR8 :** Les logs d'actions sensibles (création/suppression utilisateur, modification droits, accès home dir) sont conservés et horodatés

### Fiabilité & Résilience

- **NFR9 :** SER fonctionne intégralement sans connectivité internet — toutes les fonctions MVP opèrent en réseau local isolé
- **NFR10 :** Un rollback complet de l'instance est possible en moins de 5 minutes via snapshot Proxmox
- **NFR11 :** La perte de connexion à irundoo n'affecte pas le fonctionnement de SER (SER standalone par design)
- **NFR12 :** Les migrations de données sont idempotentes — une migration exécutée deux fois ne corrompt pas les données

### Maintenabilité

- **NFR13 :** Tout le code est typé (PHP typed properties, return types, DTOs) — aucun tableau associatif non typé comme structure de données principale
- **NFR14 :** Chaque méthode dans `Services/Legacy/` porte un commentaire indiquant le fichier legacy source et la raison du refactoring futur
- **NFR15 :** Chaque fonctionnalité livrée est accompagnée de tests automatisés avant passage en bêta
- **NFR16 :** Un développeur externe peut installer un environnement de développement fonctionnel en suivant uniquement la documentation du repo

### Intégration

- **NFR17 :** La synchronisation LDAP/AD repose sur la structure OU standardisée définie dans la documentation — toute déviation par rapport à cette structure est détectée et signalée explicitement plutôt que silencieusement ignorée
- **NFR18 :** Les intégrations système (CUPS, DHCP, scripts sudo) sont encapsulées dans des Services dédiés — aucun appel système direct depuis les SFC Livewire

