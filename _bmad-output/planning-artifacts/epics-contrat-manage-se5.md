---
stepsCompleted: [step-01-validate-prerequisites, step-02-design-epics, step-03-create-stories]
epicNumbering: "28-33 (suite du backlog projet, max existant = Epic 27)"
inputDocuments:
  - _bmad-output/planning-artifacts/prd-contrat-manage-se5.md
  - _bmad-output/planning-artifacts/architecture-agent-desired-state.md
  - _bmad-output/planning-artifacts/handoff-controlhub-contrat-manage.md
docType: feature-epics
feature: contrat-manage-se5
scope: SE5 (sambaedu-reload) — côté local uniquement
note: "Fichier DÉDIÉ — ne pas confondre avec epics.md (epics projet)."
---

# Contrat Amont (SE5) - Epic Breakdown

## Overview

Décomposition en epics/stories de la capacité **Contrat Amont** côté SE5 (sambaedu-reload), à partir du mini-PRD `prd-contrat-manage-se5.md`. Cadrage neutre : SE5 ne modélise jamais « central », il consomme et enforce un **contrat amont** générique.

## Requirements Inventory

### Functional Requirements

FR1: SE5 ingère un contrat amont (items imposés par type + valeur + état verrouillé/permissif/absent, catalogue applicatif, état du lien).
FR2: `StateCompiler` résout contrat-amont > local ; le local empile sans masquer un item verrouillé (réutilise `specificity()`).
FR3: Toute tentative locale de modifier un item verrouillé est refusée (UI + service + gate), pas seulement masquée.
FR4: Un item permissif peut être surchargé par le refnum au niveau d'un `workstationGroup`.
FR5: Le canal d'install refnum est conservé mais filtré au catalogue amont.
FR6: SE5 reprend les ordres d'install ciblés, en désir d'état repris par le check-in agent existant.
FR7: À réception du signal de rupture, SE5 lève tous les verrous, conserve les ajouts locaux, rend la main au refnum (tracé).
FR8: Le refnum voit ce qui est imposé/verrouillé/permissif (transparence UI).
FR9: SE5 ingère les labels amont (nom + mode libre/réservé) et les groupes imposés (nom + label).
FR10: Pour un label libre, le refnum l'assigne à des groupes existants ou crée des groupes le portant (1 label max/groupe). Label réservé non attribuable.
FR11: SE5 garantit l'existence des `workstationGroups` exigés par l'amont (création/réconciliation, label réservé).
FR12: Un item ciblant un label s'applique à tous les groupes portant ce label ; conflit poste tranché par règle verrou/permissif (amont permissif → local, verrouillé → amont).
FR13: Validation prédictive à l'assignation : SE5 détecte et signale une collision insoluble (deux verrous amont contradictoires) avant application.

### NonFunctional Requirements

NFR1: L'enforcement passe par des Gates scopés (par entité/`workstationGroup`). **Bloquant** : `wpkg.*` est gaté par un Gate global non scopé → à corriger sinon verrou apps inopérant.
NFR2: Un item verrouillé est soumis à la politique de drift STRICT existante.
NFR3: Sans contrat amont, comportement SE5 strictement inchangé ; le code ne suppose jamais une autorité amont.
NFR4: Idempotence — réception répétée du même contrat = no-op ; reprise après indisponibilité sans effet de bord.
NFR5: Auditabilité — pose de verrou, override permissif, rupture de lien tracés.

### Additional Requirements

- Réutiliser `StateCompiler::specificity()` (moteur de résolution existant) — ne pas réinventer.
- Réutiliser le canal release/check-in agent existant pour les déclenchements d'install (désir d'état, idempotence/reprise).
- Schéma d'échange controlHub↔SE5 = contrat de données partagé versionné (dépendance, R2) — story d'intégration dédiée.
- Réutiliser le patron de validation prédictive existant (native/WPKG).
- Prérequis bloquant : scoper le Gate `wpkg.*` (NFR1) avant FR5.
- Garde-fou de vocabulaire : aucun « central » dans code/UI SE5 (R3).

### UX Design Requirements

Aucune spécification UX dédiée. FR8 (lisibilité refnum : badges imposé/verrouillé/permissif, refus explicite d'édition) et FR10/FR13 (UI de mapping de labels + avertissement de collision) impliquent du travail UI à concevoir au fil des stories concernées.

### FR Coverage Map

FR1: Epic 28 — Ingestion & persistance du contrat amont
FR2: Epic 28 — Tier de précédence amont > local (StateCompiler)
FR3: Epic 29 — Refus de modification d'un item verrouillé
FR4: Epic 29 — Override d'un item permissif par workstationGroup
FR5: Epic 31 — Bornage de l'install au catalogue amont
FR6: Epic 31 — Déclenchement d'install en désir d'état (check-in agent)
FR7: Epic 32 — Release des verrous à la rupture du lien
FR8: Epic 29 — Lisibilité refnum (statuts imposé/verrouillé/permissif)
FR9: Epic 30 — Réception des labels + groupes imposés
FR10: Epic 30 — Mapping refnum (1 label max ; réservé non attribuable)
FR11: Epic 30 — Garantie d'existence des groupes imposés
FR12: Epic 30 — Résolution par label (règle verrou/permissif)
FR13: Epic 30 — Validation prédictive à l'assignation
NFR1: Epic 29 — Gates scopés (correctif wpkg.*), prérequis d'Epic 31
NFR2: Epic 29 — Drift STRICT sur item verrouillé
NFR3: Epic 28 — Standalone préservé (sans contrat, inchangé)
NFR4: Epic 28 — Idempotence de la réception
NFR5: Epic 29 + Epic 32 — Auditabilité (overrides, rupture)
Add. (schéma versionné, R2): Epic 33 — Contrat de données partagé controlHub↔SE5

## Epic List

### Epic 28: Recevoir et résoudre un contrat amont
L'instance SE5 ingère un contrat amont, le persiste, et calcule l'état effectif (amont > local) via `StateCompiler`. Sans contrat, le comportement reste strictement inchangé. Définit unilatéralement le format d'ingestion attendu (formalisé plus tard en Epic 33).
**FRs covered:** FR1, FR2, NFR3, NFR4

### Epic 29: Faire respecter le contrat (verrou & permissif)
Le refnum ne peut plus défaire un item verrouillé, peut surcharger un item permissif au niveau d'un `workstationGroup`, et voit clairement les statuts. Enforcement via Gates scopés (incl. correctif `wpkg.*`), drift STRICT, audit des overrides. C'est ici que la divergence entre établissements est stoppée.
**FRs covered:** FR3, FR4, FR8, NFR1, NFR2, NFR5

### Epic 30: Cibler par labels (types de parc)
L'amont impose par label ; le refnum mappe les labels sur ses groupes (1 max), les groupes imposés sont garantis, et les collisions insolubles sont prévenues à l'assignation. Ajoute le ciblage sous-instance par-dessus l'enforcement d'Epic 29.
**FRs covered:** FR9, FR10, FR11, FR12, FR13

### Epic 31: Dépôt applicatif borné & install pilotée
L'install locale (refnum) est bornée au catalogue amont ; l'amont peut déclencher des installs en désir d'état via le canal check-in agent. Dépend du correctif `wpkg.*` (NFR1, Epic 29).
**FRs covered:** FR5, FR6

### Epic 32: Cycle de vie du lien & release
À réception du signal de rupture du lien, SE5 libère proprement tous les verrous : le refnum reprend la main, ses ajouts locaux sont conservés, le tout tracé.
**FRs covered:** FR7, NFR5

### Epic 33: Contrat de données d'intégration controlHub↔SE5
Formaliser et versionner le schéma d'échange partagé entre controlHub et SE5 (coordination cross-équipe), durcissant le format d'ingestion introduit en Epic 28.
**FRs covered:** Additional requirements (schéma versionné, risque R2)

---

## Epic 28: Recevoir et résoudre un contrat amont

L'instance SE5 ingère un contrat amont, le persiste, et calcule l'état effectif (amont > local) via `StateCompiler`. Sans contrat, le comportement reste strictement inchangé.

### Story 28.1: Modèle et persistance du contrat amont

As a SE5 (le système),
I want un modèle de données qui représente un contrat amont reçu (items imposés {type, clé, valeur, état verrouillé/permissif}, catalogue applicatif, labels, groupes imposés, état du lien),
So that l'instance dispose d'une structure stable et requêtable pour enforcer le contrat.

**Acceptance Criteria:**

**Given** aucune table de contrat amont n'existe
**When** la migration de la story est jouée
**Then** les tables/colonnes nécessaires au contrat amont (items + cible `instance|label`, catalogue, labels+mode, groupes imposés, état du lien) sont créées
**And** le modèle Eloquent expose les relations et ne porte aucun champ nommé « central » (garde-fou R3).

### Story 28.2: Réception idempotente d'un contrat amont

As a SE5 (le système),
I want un point d'ingestion qui reçoit un contrat amont et le persiste de façon idempotente,
So that une diffusion répétée ou une reprise après indisponibilité n'a aucun effet de bord (NFR4).

**Acceptance Criteria:**

**Given** un contrat amont valide est reçu
**When** l'ingestion s'exécute
**Then** le contrat est persisté (upsert) et l'état du lien passe à « actif »

**Given** le même contrat (inchangé) est reçu une seconde fois
**When** l'ingestion s'exécute
**Then** l'opération est un no-op (aucune écriture fonctionnelle, aucun événement de changement émis).

### Story 28.3: Résolution amont > local dans StateCompiler

As a refnum,
I want que l'état effectif d'un poste/groupe combine contrat amont et configuration locale avec l'amont prioritaire,
So that ce que l'autorité amont impose prime, tout en laissant le local empiler (FR2).

**Acceptance Criteria:**

**Given** un item est imposé par le contrat amont et un réglage local existe pour la même clé
**When** `StateCompiler` calcule l'état effectif
**Then** la valeur amont prime sur la valeur locale (tier amont au-dessus du local via `specificity()`)
**And** les réglages locaux sans équivalent amont restent appliqués (empilement)

**Given** aucun contrat amont n'est présent sur l'instance
**When** `StateCompiler` calcule l'état effectif
**Then** le résultat est strictement identique au comportement actuel sans contrat (NFR3).

---

## Epic 29: Faire respecter le contrat (verrou & permissif)

Le refnum ne peut plus défaire un item verrouillé, peut surcharger un item permissif, et voit clairement les statuts. C'est ici que la divergence entre établissements est stoppée.

### Story 29.1: Scoper le Gate `wpkg.*` par périmètre

As a SE5 (le système),
I want que les permissions `wpkg.*` soient évaluées par périmètre (`workstationGroup`/entité) et non par un Gate global,
So that un verrou amont sur les apps soit réellement opposable et non du théâtre (NFR1, prérequis d'Epic 31).

**Acceptance Criteria:**

**Given** une autorisation `wpkg.*` accordée sur un périmètre donné
**When** une action `wpkg` est tentée hors de ce périmètre
**Then** l'autorisation est refusée par le Gate scopé
**And** les autorisations existantes restent fonctionnelles dans leur périmètre (pas de régression).

### Story 29.2: Refuser la modification d'un item verrouillé

As a refnum,
I want que toute tentative de modifier un item verrouillé par l'amont soit refusée explicitement,
So that je ne puisse pas défaire ce que l'autorité amont impose (FR3).

**Acceptance Criteria:**

**Given** un item est imposé en état « verrouillé »
**When** je tente de le modifier (UI ou service)
**Then** l'opération est refusée au niveau service ET Gate (pas seulement masquée)
**And** un message explicite indique que l'item est verrouillé par un contrat amont.

### Story 29.3: Surcharger un item permissif par workstationGroup

As a refnum,
I want pouvoir surcharger un item marqué « permissif » sur un `workstationGroup` précis,
So that je garde une marge d'adaptation locale là où l'amont l'autorise (FR4).

**Acceptance Criteria:**

**Given** un item est imposé en état « permissif »
**When** je définis un override sur un `workstationGroup`
**Then** l'override s'applique à ce groupe uniquement et l'état effectif y reflète ma valeur

**Given** un item est en état « verrouillé »
**When** je tente le même override
**Then** l'override est refusé (réservé aux items permissifs).

### Story 29.4: Lisibilité refnum des statuts imposés

As a refnum,
I want voir clairement, dans l'UI, quels réglages sont imposés/verrouillés/permissifs,
So that je comprenne pourquoi certains réglages ne sont pas modifiables (FR8).

**Acceptance Criteria:**

**Given** des items imposés par le contrat amont
**When** j'ouvre l'écran de configuration concerné
**Then** chaque item affiche un statut visible (imposé-verrouillé / imposé-permissif / local)
**And** aucun réglage ne « ne s'enregistre pas » sans explication visible.

### Story 29.5: Drift STRICT et audit des overrides

As a SE5 (le système),
I want que les items verrouillés soient soumis au drift STRICT et que les overrides permissifs soient tracés,
So that un item imposé soit réappliqué en cas de dérive et que les écarts soient auditables (NFR2, NFR5).

**Acceptance Criteria:**

**Given** un item verrouillé dérive de sa valeur imposée
**When** le cycle de réconciliation s'exécute
**Then** la valeur imposée est réappliquée (politique drift STRICT, sans tolérance)

**Given** un refnum pose un override permissif
**When** l'override est enregistré
**Then** un événement d'audit horodaté est consigné (acteur, item, périmètre).

---

## Epic 30: Cibler par labels (types de parc)

L'amont impose par label ; le refnum mappe les labels sur ses groupes (1 max), les groupes imposés sont garantis, et les collisions insolubles sont prévenues à l'assignation.

### Story 30.1: Réception des labels et des groupes imposés

As a SE5 (le système),
I want recevoir et persister les labels amont (nom + mode libre/réservé) et les groupes imposés (nom + label),
So that l'instance connaisse le vocabulaire de ciblage défini en amont (FR9).

**Acceptance Criteria:**

**Given** un contrat amont contient des labels et des groupes imposés
**When** l'ingestion s'exécute
**Then** les labels sont persistés avec leur mode (`libre`/`réservé`) et les groupes imposés sont enregistrés avec leur label associé.

### Story 30.2: Mapping d'un label par le refnum

As a refnum,
I want assigner un label libre à un `workstationGroup` existant ou créer un groupe portant ce label,
So that je rattache le ciblage amont à mes parcs concrets (FR10).

**Acceptance Criteria:**

**Given** un label en mode « libre »
**When** je l'assigne à un groupe ou crée un groupe le portant
**Then** le groupe porte ce label (1 label max par groupe : un second label est refusé)

**Given** un label en mode « réservé »
**When** je tente de l'assigner à un groupe
**Then** l'opération est refusée (non attribuable par le refnum).

### Story 30.3: Garantie d'existence des groupes imposés

As a SE5 (le système),
I want garantir l'existence des `workstationGroups` exigés par l'amont,
So that les groupes imposés (ex. `bureau_direction`, `compta_x`) existent toujours avec leur label réservé (FR11).

**Acceptance Criteria:**

**Given** le contrat impose un groupe nommé avec un label réservé
**When** la réconciliation s'exécute et que le groupe est absent
**Then** le groupe est créé avec son label réservé

**Given** un groupe imposé existe déjà
**When** la réconciliation s'exécute
**Then** son existence et son label sont confirmés sans duplication, et le refnum ne peut pas le supprimer tant que le contrat l'exige.

### Story 30.4: Résolution d'un item ciblant un label

As a refnum,
I want qu'un item amont ciblant un label s'applique à tous les groupes portant ce label,
So that l'imposition se propage automatiquement au bon périmètre (FR12).

**Acceptance Criteria:**

**Given** un item est ciblé sur `label:<nom>`
**When** `StateCompiler` résout l'état d'un poste appartenant à un groupe portant ce label
**Then** l'item s'applique à ce poste

**Given** un poste cumule deux valeurs imposées sur une même propriété (via plusieurs parcs)
**When** la résolution s'exécute
**Then** la règle verrou/permissif tranche (amont permissif → local l'emporte ; amont verrouillé → amont l'emporte), sans ordre de spécificité inter-parcs.

### Story 30.5: Validation prédictive à l'assignation

As a refnum,
I want être prévenu d'une collision insoluble au moment où j'assigne un label ou lie un parc,
So that une contradiction de verrous amont soit interceptée avant d'atteindre le poste (FR13).

**Acceptance Criteria:**

**Given** une assignation aboutirait à deux propriétés amont verrouillées contradictoires sur un même poste
**When** je tente l'assignation
**Then** SE5 détecte la collision et m'avertit explicitement (item, périmètre, valeurs en conflit)
**And** aucune résolution silencieuse n'est appliquée.

---

## Epic 31: Dépôt applicatif borné & install pilotée

L'install locale est bornée au catalogue amont ; l'amont peut déclencher des installs en désir d'état via le canal check-in agent.

### Story 31.1: Borner l'install refnum au catalogue amont

As a refnum,
I want que mon canal d'install reste utilisable mais filtré au catalogue amont,
So that je puisse ajouter des apps librement mais seulement depuis le catalogue faisant autorité (FR5).

**Acceptance Criteria:**

**Given** un contrat amont définit un catalogue applicatif faisant autorité
**When** je consulte/installe une app via le canal refnum
**Then** seules les apps du catalogue sont proposées/installables

**Given** une app hors catalogue
**When** je tente de l'installer
**Then** l'opération est refusée avec un message explicite (hors catalogue amont).

### Story 31.2: Déclenchement d'install en désir d'état

As a SE5 (le système),
I want reprendre les ordres d'install amont sous forme de désir d'état repris par le check-in agent,
So that une install pilotée par l'amont soit idempotente et reprenable (FR6).

**Acceptance Criteria:**

**Given** le contrat amont contient un ordre d'install (cible + app)
**When** l'agent fait son check-in
**Then** l'app cible figure dans l'état désiré renvoyé à l'agent

**Given** l'app est déjà installée sur la cible
**When** l'agent réconcilie
**Then** aucune réinstallation n'est déclenchée (idempotence).

---

## Epic 32: Cycle de vie du lien & release

À réception du signal de rupture du lien, SE5 libère proprement tous les verrous : le refnum reprend la main, ses ajouts locaux sont conservés.

### Story 32.1: Release des verrous à la rupture du lien

As a refnum,
I want qu'à la rupture du lien de management tous les verrous tombent en conservant les valeurs courantes,
So that je reprenne la main sans rien casser sur les postes (FR7).

**Acceptance Criteria:**

**Given** un contrat amont actif avec des items verrouillés et des ajouts locaux
**When** SE5 reçoit le signal de rupture du lien
**Then** tous les items quittent l'état imposé et deviennent local-libre en conservant leur valeur courante effective
**And** le bornage catalogue tombe et le refnum retrouve un droit de modification plein
**And** les ajouts locaux antérieurs sont conservés.

### Story 32.2: Indisponibilité amont et trace du lien

As a SE5 (le système),
I want distinguer « amont indisponible » de « lien rompu » et tracer les transitions,
So that une simple panne ne libère pas les verrous et que les changements d'état soient auditables (NFR5).

**Acceptance Criteria:**

**Given** l'autorité amont est indisponible (pas de MAJ reçue)
**When** le temps passe sans signal de rupture
**Then** le dernier contrat reste en vigueur (verrous maintenus)

**Given** une transition d'état du lien (actif → rompu)
**When** elle survient
**Then** un événement d'audit horodaté est consigné.

---

## Epic 33: Contrat de données d'intégration controlHub↔SE5

Formaliser et versionner le schéma d'échange partagé entre controlHub et SE5.

### Story 33.1: Schéma d'échange versionné

As a SE5 (le système),
I want un schéma d'échange formalisé et versionné pour le contrat amont,
So that les deux côtés (controlHub et SE5) partagent une source unique vérifiable (R2).

**Acceptance Criteria:**

**Given** un payload de contrat amont reçu
**When** l'ingestion valide le payload contre le schéma versionné
**Then** un payload conforme est accepté et sa version de schéma est enregistrée
**And** le schéma est documenté en un artefact partagé référencé par les deux BMAD.

### Story 33.2: Négociation et rejet gracieux d'une version incompatible

As a SE5 (le système),
I want rejeter proprement un contrat dont la version de schéma est incompatible,
So that une évolution non coordonnée côté amont ne corrompe pas l'état local (R2).

**Acceptance Criteria:**

**Given** un payload portant une version de schéma non supportée
**When** l'ingestion s'exécute
**Then** le payload est rejeté sans modifier l'état existant
**And** l'incompatibilité est tracée (version reçue vs versions supportées).
