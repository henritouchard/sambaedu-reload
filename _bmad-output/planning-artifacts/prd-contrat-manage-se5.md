---
docType: feature-prd
feature: contrat-manage-se5
scope: SE5 (sambaedu-reload) — côté local uniquement
author: henri
date: '2026-06-25'
status: draft
relatedDocs:
  - _bmad-output/planning-artifacts/handoff-controlhub-contrat-manage.md  # côté central (autre BMAD)
  - _bmad-output/planning-artifacts/architecture-agent-desired-state.md
  - _bmad-output/planning-artifacts/prd.md  # PRD projet — NE PAS écraser
principleGuard: "Aucune notion de 'central' ne doit exister dans SER → ce PRD modélise un CONTRAT AMONT générique, pas controlHub."
---

# Mini-PRD — Contrat Amont (côté SE5)

> ⚠️ Ceci **n'est pas** le PRD projet (`prd.md`). C'est un PRD de *capacité*, focalisé sur le côté **local SE5**. Le côté central (controlHub) est cadré dans un autre projet BMAD via `handoff-controlhub-contrat-manage.md`.

---

## 1. Contexte & problème

SE5 est un serveur d'établissement **standalone**. Un **refnum** (admin local) y configure le parc : applications, wallpapers, capacités, raccourcis, outils agent, et leur application jusqu'aux postes via le modèle *desired-state* / agent.

Aujourd'hui, une instance SE5 peut recevoir des entités d'une autorité amont, mais **de façon non-liante** : rien n'empêche le refnum local de les modifier ou de les défaire. Résultat observé au niveau de la flotte : **les établissements divergent** alors que certaines règles doivent être **identiques partout**.

**Le problème côté SE5** : il manque à l'instance la capacité de **recevoir et faire respecter (enforcer) un contrat amont liant** — un socle de réglages qu'elle applique sans que le refnum puisse les défaire, tout en le laissant **empiler** ses propres réglages par-dessus.

---

## 2. Principe de cadrage (garde-fou de séparation de domaines)

Le PRD projet pose : *« Aucune notion de "central" ne doit exister dans SER. »* Ce mini-PRD le respecte **strictement** :

- SE5 **n'a pas connaissance de controlHub**. Il modélise une abstraction neutre : **un contrat amont** (une autorité externe peut épingler certains réglages).
- C'est le **même patron** que le *login fédéré* (SE5 consomme un « contrat = rôle » sans modéliser l'IdP) et que le modèle *desired-state* (SE5 applique un état désiré sans connaître son émetteur).
- Toute la logique d'**authoring** (qui impose quoi, à qui) reste **hors de SE5**. SE5 ne fait que **consommer** un contrat reçu et **l'enforcer localement**.
- Vocabulaire imposé : on parle de **« contrat amont »** / **« autorité externe »**, **jamais** de « central » dans le code, l'UI ou le modèle SE5.

> Conséquence positive : la capacité **renforce** l'architecture existante (desired-state, fédération) au lieu de la contredire. Une instance SE5 reste déployable seule ; sans contrat amont, son comportement est inchangé.

---

## 3. Objectifs / Non-objectifs

**Objectifs**
- SE5 sait **recevoir** un contrat amont (items imposés + état de verrou + catalogue applicatif + état du lien).
- SE5 reçoit des **labels** (types de parc) et les **mappe** sur ses `workstationGroups` ; l'amont impose au choix par **label** ou sur l'**instance entière**.
- SE5 **enforce** le contrat : le local empile, ne défait jamais un item verrouillé ; il peut override un item marqué **permissif** sur un `workstationGroup`.
- L'install locale (refnum) est **bornée au catalogue** porté par le contrat.
- SE5 reprend les **déclenchements d'install** amont (de préférence en désir d'état, via le check-in agent existant).
- Les verrous sont **réellement enforced** (gates de permission scopés).
- À la **rupture du lien**, tous les verrous sautent proprement et le refnum reprend la main, **sans perte** de ses ajouts.

**Non-objectifs (hors périmètre SE5)**
- L'authoring/diffusion du contrat (controlHub) — *cf. handoff*.
- Toute modélisation de « central » dans SE5.
- Le format d'échange est une **dépendance** à co-spécifier (cf. §9), pas à inventer unilatéralement ici.

---

## 4. Acteurs

| Acteur | Intérêt vis-à-vis de la capacité |
|---|---|
| **Refnum (admin local SE5)** | Configure le parc ; subit le verrou ; garde la main sur ce qui est permissif/non imposé. |
| **Autorité amont** (abstraite) | Émet le contrat ; SE5 ne la modélise pas, il consomme. |
| **Agent / poste** | Reçoit l'état désiré résolu (contrat + local) et l'applique. |

---

## 5. Le modèle

### 5.1 Tier de résolution « contrat amont »

Le moteur de résolution SE5 (`StateCompiler`) gagne un **tier au-dessus du local**. Le local **ne peut qu'empiler** ; il ne masque ni ne modifie un item verrouillé.

### 5.2 État à 3 positions, par item

| État du contrat amont | Ce que le refnum peut faire |
|---|---|
| **Imposé – verrouillé** | empiler à côté ; **jamais** modifier l'item |
| **Imposé – permissif** *(flag)* | **override** l'item sur un `workstationGroup` |
| **Non imposé** | gestion locale libre (comportement actuel) |

**Exemple** : contrat amont = « Windows Store désactivé » + **permissif** → SE5 peut le réactiver sur un `workstationGroup` ciblé. Sans permissiveness, c'est verrouillé pour tous.

### 5.3 Cycle de vie du lien (côté SE5)
- **Lien actif** : SE5 reçoit les MAJ d'état ; verrous appliqués ; refnum borné.
- **Amont indisponible** : pas de MAJ ; le **dernier contrat reste en vigueur**.
- **Rupture du lien** (signal reçu) : **release** — le local conserve tous ses ajouts, **tous les verrous sautent**, le refnum reprend la main.

### 5.4 Labels de parc — surface de liaison amont↔local

**Problème** : les `workstationGroups` sont **locaux et libres** — un collège a une salle « techno », un autre en a trois (`techno101`, `techno2`, `technox`). L'autorité amont **ne peut pas** cibler un groupe qu'elle ne connaît pas.

**Solution** : un niveau d'**indirection par label**. L'autorité amont définit des **labels** (sa vision abstraite des *types* de parc). SE5 les reçoit ; le refnum les **mappe** sur ses groupes concrets. L'amont associe un item de contrat à un **label** → **tout** `workstationGroup` portant ce label hérite de la contrainte imposée. Le label cible **tous les types d'items** (apps, wallpapers, capacités, raccourcis, outils agent), de façon symétrique au ciblage instance-globale.

**Règles de cardinalité & résolution**
- **1 label max par `workstationGroup`** → aucun conflit inter-labels *sur un même groupe*.
- La flexibilité vient de l'**appartenance multiple** : un poste peut appartenir à **plusieurs parcs logiques** (seule la salle est en 1-max), chacun mono-label → il cumule plusieurs labels.
- **Conflit au niveau poste** (deux valeurs imposées sur une même propriété) : tranché par la règle verrou/permissif (§5.2) — amont permissif → local l'emporte, amont verrouillé → amont l'emporte. **Pas d'ordre de spécificité inter-parcs à inventer.** Le cas insoluble (deux verrous amont contradictoires) est intercepté par **validation prédictive à l'assignation** (FR13).
- SE5 **ne crée pas** de labels (vocabulaire amont) ; il les **mappe** sur ses groupes.

**Trois modes de label** (portés par le contrat)

| Mode | Sémantique côté SE5 |
|---|---|
| **Libre** | le refnum assigne le label à des groupes existants **ou** crée des groupes le portant |
| **Réservé** *(non-attribuable)* | le label existe en base mais le refnum **ne peut pas** l'attribuer ; seule l'autorité amont décide quels groupes le portent (ex. label `compta`) |
| **Groupe imposé** | l'amont **exige la création** d'un `workstationGroup` nommé portant un label (ex. `bureau_direction`[direction], `compta_x`[compta]) ; SE5 **garantit** son existence (création/réconciliation) |

---

## 6. Exigences fonctionnelles (FR)

- **FR1 — Réception** : SE5 ingère un contrat amont : liste d'items imposés par type d'entité (applications, wallpapers, capacités, raccourcis, outils agent), avec pour chacun valeur + état (verrouillé/permissif/absent), + le catalogue applicatif faisant autorité, + l'état du lien.
- **FR2 — Tier de précédence** : `StateCompiler` résout contrat-amont > local ; le local empile sans masquer un item verrouillé (réutilise `specificity()`).
- **FR3 — Enforcement du verrou** : toute tentative locale de modifier un item verrouillé est **refusée** (UI + service + gate), pas seulement masquée à l'affichage.
- **FR4 — Override permissif** : un item permissif peut être surchargé par le refnum **au niveau d'un `workstationGroup`**.
- **FR5 — Bornage catalogue** : le canal d'install refnum est **conservé mais filtré** au catalogue amont (apps hors catalogue non installables).
- **FR6 — Déclenchement d'install amont** : SE5 reprend les ordres d'install ciblés, idéalement en **désir d'état** repris par le check-in agent existant (idempotence/reprise).
- **FR7 — Release à la rupture** : à réception du signal de rupture, SE5 lève tous les verrous, conserve les ajouts locaux, rend la main au refnum ; opération **tracée/auditée**.
- **FR8 — Lisibilité refnum** : le refnum **voit** ce qui est imposé/verrouillé/permissif (transparence : pas de réglage qui « ne s'enregistre pas » sans explication).
- **FR9 — Réception des labels** : SE5 ingère les labels amont (nom + mode `libre`/`réservé`) et les **groupes imposés** (nom + label) émis par le contrat.
- **FR10 — Mapping refnum** : pour un label **libre**, le refnum l'assigne à des groupes existants ou crée des groupes le portant (**1 label max par groupe**). Un label **réservé** n'est **pas attribuable** par le refnum.
- **FR11 — Groupes imposés** : SE5 **garantit l'existence** des `workstationGroups` exigés par l'amont (création/réconciliation, label réservé associé) ; il ne les supprime pas tant que le contrat les exige.
- **FR12 — Résolution par label** : un item ciblant un label s'applique à **tous** les groupes portant ce label ; au niveau **poste**, le conflit entre deux valeurs imposées sur une même propriété est tranché par la règle verrou/permissif (§5.2) : amont permissif → local l'emporte, amont verrouillé → amont l'emporte. Aucun ordre de spécificité inter-parcs requis.
- **FR13 — Validation prédictive à l'assignation** : quand le refnum assigne un label ou lie un parc, SE5 **détecte et signale** une collision insoluble (deux propriétés amont verrouillées contradictoires sur un même poste) **avant** application — pas de résolution silencieuse.

---

## 7. Exigences non-fonctionnelles (NFR)

- **NFR1 — Gates scopés** : l'enforcement passe par des Gates **scopés** (par entité/`workstationGroup`). ⚠️ Trou connu : `wpkg.*` est gaté par un Gate global non scopé → un verrou sur les apps serait du théâtre tant que ce n'est pas corrigé. **À couvrir dans le périmètre.**
- **NFR2 — Drift STRICT** : un item verrouillé est soumis à la politique de drift STRICT existante (réapplication, pas de dérive tolérée).
- **NFR3 — Standalone préservé** : sans contrat amont, comportement SE5 strictement inchangé ; le code ne suppose jamais l'existence d'une autorité amont.
- **NFR4 — Idempotence** : réception répétée du même contrat = no-op ; reprise après indisponibilité sans effet de bord.
- **NFR5 — Auditabilité** : pose de verrou, override permissif, rupture de lien → tracés.

---

## 8. Les 2 décisions à verrouiller (priorité)

### Décision A — Granularité de la précédence verrou/permissif
**Proposition** : l'état (verrouillé / permissif / absent) est porté **par item** (clé d'entité), pas par parc ni par type en bloc. La résolution réutilise `StateCompiler::specificity()` : le tier contrat-amont a une *specificity* supérieure au local pour les items verrouillés ; pour les items permissifs, un override `workstationGroup` local reprend la priorité **sur ce groupe uniquement**.
**À confirmer** : un item permissif overridé localement — l'override survit-il à une MAJ du contrat qui re-pousse la valeur par défaut ? (proposition : oui tant que permissif ; la MAJ ne ré-écrase pas un override permissif existant).

### Décision B — Sémantique exacte de la rupture du lien
**Proposition** : la rupture est un **signal reçu** (déclenché côté autorité amont), pas une action refnum. À réception : (1) tous les items quittent l'état imposé → deviennent local-libre en **conservant leur valeur courante** ; (2) le bornage catalogue tombe ; (3) le refnum retrouve un droit de modification plein ; (4) événement d'audit horodaté.
**À confirmer** : valeur conservée à la valeur **courante effective** (contrat) ou repli sur un défaut local ? (proposition : valeur courante effective, pour ne rien casser sur les postes).

### Décision C — Adressage par label *(RÉSOLU)*
Le tier amont cible soit l'**instance entière**, soit un **label**. **1 label max par `workstationGroup`** ; pas de résolution inter-labels au niveau groupe. La superposition vient du fait qu'un **poste appartient à plusieurs parcs logiques** (appartenance multiple ; seule la salle est en 1-max), chacun mono-label. Trois modes de label : `libre`, `réservé` (non-attribuable refnum), `groupe imposé` (création garantie). Le label porte **tous** les types d'items.

**Résolution de conflit au niveau poste** (deux parcs/labels imposant des valeurs différentes sur une même propriété) — **pas d'ordre de spécificité à inventer**, c'est la règle verrou/permissif (§5.2) appliquée par propriété :
- propriété amont **permissive** → le **parc/réglage local** l'emporte (override) ;
- propriété amont **verrouillée** → le **réglage amont** l'emporte.

**Cas résiduel** (deux propriétés amont **toutes deux verrouillées** et contradictoires sur un même poste) : pas résolu silencieusement → **validation prédictive à l'assignation** (cf. NFR1 / patron native-WPKG). Au moment où le refnum assigne un label ou lie un parc, SE5 **détecte et prévient** la collision avant qu'elle n'atteigne le poste.

---

## 9. Couture avec controlHub (dépendance)

SE5 **attend de recevoir** (format à co-spécifier avec le BMAD controlHub — voir §7 du handoff) :
- **labels** définis en amont (nom + mode `libre`/`réservé`) ;
- **groupes imposés** (nom + label réservé associé) dont SE5 doit garantir l'existence ;
- items imposés par type + valeur + état (verrouillé/permissif/absent) + **cible** (`instance` | `label:<nom>`) ;
- catalogue applicatif faisant autorité ;
- ordres de déclenchement d'install (cible + app) ;
- état du lien (actif/rompu).

> 🔗 Le **schéma d'échange** est un contrat de données partagé : toute évolution doit être répercutée des deux côtés. À traiter comme une story de **dépendance/intégration**, pas comme une invention locale.
>
> 📐 **Source unique du schéma** (versionné, R2) : [`schema-echange-controlhub-se5.md`](schema-echange-controlhub-se5.md) — format de payload, version courante (`schema_version`) et politique de compatibilité. Référencé symétriquement par le handoff controlHub §7 (Story 33.1).

---

## 10. Risques & dépendances connus

- **R1** — `wpkg.*` non scopé (NFR1) : sans correctif, verrou apps inopérant. Bloquant pour FR5.
- **R2** — Le format d'échange n'existe pas encore : risque de désync entre les deux BMAD. Mitigation : schéma versionné partagé, story d'intégration dédiée.
- **R3** — Glissement de vocabulaire : tout « central » qui fuit dans le code/UI SE5 viole le principe fondateur. Garde-fou en revue.

---

## 11. Prochaine étape

Ce mini-PRD alimente **CE (Create Epics & Stories)** côté SE5. Découpage pressenti (à challenger) :
- **Epic 1** — Réception & modèle du contrat amont (FR1, FR2).
- **Epic 2** — Labels & mapping : réception labels, modes libre/réservé, mapping refnum, groupes imposés, résolution par label (FR9–FR12).
- **Epic 3** — Enforcement verrou/permissif + gates scopés (FR3, FR4, NFR1, NFR2).
- **Epic 4** — Catalogue borné & déclenchement d'install (FR5, FR6).
- **Epic 5** — Cycle de vie du lien & release (FR7, NFR5).
- **Epic 6** — Intégration/contrat de données avec controlHub (§9, R2).
