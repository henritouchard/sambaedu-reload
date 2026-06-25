# Handoff PM — Contrat Managé (côté controlHub / central)

> **Destinataire** : PM du projet BMAD **controlHub** (`irundoo`).
> **Auteur** : PM du projet SE5 (`sambaedu-reload`).
> **But** : t'apporter, de façon autoportante, les requirements du **côté central** d'une nouvelle capacité — le *Contrat Managé* — dont le **côté local (SE5)** est cadré en parallèle dans le BMAD sambaedu-reload.
> **À faire avec ce doc** : il sert d'entrée à ton *Create PRD* puis *Create Epics & Stories* pour la part controlHub. Les sections « Décisions ouvertes » et « Contrat d'interface » sont les points à figer avec ton équipe et à garder synchronisés avec SE5.

---

## 1. Contexte (pour un lecteur sans historique)

- **controlHub (central)** : plan de gestion centralisé. Aujourd'hui il **pousse sélectivement** des entités vers des instances SE5 (applications, configs, etc.).
- **SE5 (local)** : instance d'établissement (collège). Elle gère ce qui se passe *jusqu'aux postes* (Windows/agent). Un **refnum** (administrateur local) y configure le parc.
- **Relation actuelle** : push **sélectif et non-liant**. Central propose/pousse des entités, mais n'impose pas leur **liaison jusqu'aux postes**, et le **refnum local peut override** les configs.

### Le problème à résoudre

Parce que le push est sélectif et que le local peut override, **les collèges divergent** alors que le besoin métier est qu'ils appliquent **tous les mêmes règles**. On veut passer d'un modèle « proposition » à un modèle **« contrat liant »** : central impose, le local empile mais ne défait pas.

---

## 2. Vision : le Contrat Managé

À compter d'un lien de management actif entre controlHub et une instance SE5, central peut **figer un contrat** qui :

1. fait de **central le dépôt applicatif unique** ;
2. **borne** les applications installables sur le serveur SE5 **au catalogue central** ;
3. **impose une config par défaut du parc** : applications, wallpapers, capacités, raccourcis, outils agent ;
4. peut **mettre à disposition** des apps à certains `workstationGroups` **hors du process habituel d'install par les refnums** ;
5. impose une **config partielle** avec **verrou par item** : le refnum peut **ajouter** des entités mais **pas modifier** ce que central a imposé ;
6. peut **déclencher l'install d'une app** à distance.

> ⚠️ **L'enrôlement ne change pas.** Le Contrat Managé se superpose au lien existant ; ce n'est pas un nouveau cycle d'inscription.

---

## 3. Modèle de précédence (le cœur)

Le contrat introduit un **tier central au-dessus du local**. Le local **ne peut qu'empiler** : il continue de gérer ce qui se trouve sur les postes, mais ne modifie jamais un item imposé.

Chaque item imposé porte un **état à 3 positions** :

| État central | Ce que le refnum SE5 peut faire |
|---|---|
| **Imposé – verrouillé** | empiler à côté, **jamais** modifier l'item imposé |
| **Imposé – permissif** *(flag de permissiveness)* | **override** l'item sur un `workstationGroup` donné |
| **Non imposé** | gestion locale libre (comportement actuel) |

**Exemple canonique** : central coche « Windows Store désactivé » et **active la permissiveness** dessus → SE5 peut réactiver le Store sur un `workstationGroup` précis. Sans la permissiveness, le réglage est verrouillé pour tout le monde.

> Le **flag de permissiveness** est donc une propriété portée par chaque item du contrat (et non un mode global).

### 3.1 Labels de parc — la surface de ciblage central↔local

Les `workstationGroups` sont **locaux et hétérogènes** : un collège a une salle « techno », un autre en a trois (`techno101`, `techno2`, `technox`). **Central ne connaît pas** les groupes locaux et ne peut donc pas les cibler directement.

→ Central raisonne en **labels** (types de parc), une couche d'**indirection**. Le flux :

1. **L'admin central saisit des labels** dans un formulaire (sa vision abstraite des types de parc : `techno`, `direction`, `compta`, `cdi`…).
2. Les labels sont **communiqués au SE5**.
3. **Le refnum SE5 mappe** : il assigne un label à des groupes existants **ou** crée des groupes le portant.
4. Central **associe un item de contrat à un label** (une app, un wallpaper, une capacité… — *tous* les types d'items sont ciblables par label) → **chaque** `workstationGroup` portant ce label hérite de la contrainte.

**Contrainte de cardinalité (côté SE5)** : **1 label max par `workstationGroup`**. La flexibilité vient de la combinaison locale groupe physique (salle) + groupe logique (parc) ; le conflit éventuel se résout côté SE5 par sa précédence `logique > physique`. *Central n'a pas à gérer la résolution de conflit.*

**Trois modes de label** que central peut émettre :

| Mode | Effet attendu côté SE5 |
|---|---|
| **Libre** | le refnum peut assigner/créer librement des groupes portant le label |
| **Réservé** *(non-attribuable)* | le label existe en base SE5 mais le refnum **ne peut pas** l'attribuer ; seul central décide quels groupes le portent (ex. `compta`) |
| **Groupe imposé** | central **exige la création** d'un `workstationGroup` nommé + label (ex. `bureau_direction`[direction], `compta_x`[compta]) ; SE5 garantit son existence |

> Cas d'usage du réservé + groupe imposé : « je veux forcer le logiciel de comptabilité sur un poste comptable » → central impose le groupe `compta_x` avec le label réservé `compta`, et associe l'app comptabilité au label `compta`.

---

## 4. Cycle de vie du lien de management

- **Lien actif** : controlHub pousse l'état ; les verrous s'appliquent ; le refnum est borné.
- **controlHub indisponible (panne)** : **pas de mise à jour d'état** sur SE5 ; le dernier contrat reste en vigueur (rien ne saute).
- **Rupture du lien de management** (action délibérée, côté central) : **release propre** —
  - SE5 **conserve toutes ses modifications** ;
  - **tous les verrous sautent** ;
  - le refnum SE5 **reprend la main** sur tous les paramètres.

> Implication produit forte : **le verrou n'a de valeur que tant que le lien vit.** La rupture est l'unique porte de sortie, et c'est une libération, pas une remise à zéro.

---

## 5. Responsabilités côté controlHub (périmètre de TON BMAD)

C'est ici que se concentre le travail central. À cadrer en epics/stories :

1. **Authoring du contrat** : interface pour composer un Contrat Managé — choisir les entités imposées (applications, wallpapers, capacités, raccourcis, outils agent), poser le **verrou** et le **flag de permissiveness** par item.
2. **Authoring des labels** (cf. §3.1) : formulaire de saisie des labels (types de parc) ; choix du mode par label (`libre` / `réservé`) ; déclaration de **groupes imposés** (nom + label). C'est la **surface de ciblage** : central ne cible jamais un `workstationGroup` local directement.
3. **Ciblage des items** : chaque item de contrat vise soit l'**instance entière**, soit un **label** (→ tous les groupes locaux portant ce label). Mise à dispo sélective d'apps hors process refnum via label.
4. **Dépôt applicatif unique + catalogue** : central est l'autorité du catalogue ; définir ce qui est « installable » et **borner** le local à ce catalogue.
5. **Déclenchement d'install à distance** : central peut initier l'install d'une app sur des cibles.
6. **Diffusion de l'état** : mécanisme de push/MAJ de l'état du contrat vers SE5 (à aligner avec le canal d'état existant — voir §7).
7. **Gestion du lien** : poser / rompre le lien de management, avec la sémantique de release du §4 ; trace/audit de qui rompt et quand.

---

## 6. Décisions ouvertes à figer (avec ton équipe + en synchro SE5)

1. **Granularité du verrou / permissif** : par item, par type d'entité, ou par parc ? (SE5 réutilise un moteur de précédence à *specificity* — l'aligner.)
2. **« Déclencher une install »** : push impératif unique, ou **désir d'état** repris par le mécanisme de check-in de l'agent SE5 existant ? (Recommandation : désir d'état, pour idempotence et reprise.)
3. **Catalogue borné** : le canal d'install local du refnum est-il **coupé**, ou **conservé mais filtré** au catalogue central ? (Réponse SE5 actuelle : *borné au catalogue*, canal conservé mais filtré.)
4. **Sémantique de rupture** : qui est autorisé à rompre (central seul ?), confirmation, audit, notification au refnum.
5. **Versionnement du contrat** : un contrat évolue — gère-t-on des versions / un diff appliqué, ou un état désiré remplacé en bloc ?
6. **Conflit multi-niveaux** : si plusieurs contrats/portées ciblent une même instance, ordre de résolution ?

---

## 7. Contrat d'interface controlHub ↔ SE5 (à garder synchronisé)

C'est le **point de couture** entre les deux BMAD. SE5 (local) attend de recevoir, pour pouvoir *enforcer* :

- la **liste des labels** (nom + mode `libre` / `réservé`) et les **groupes imposés** (nom + label réservé) ;
- la **liste des items imposés** par type d'entité (applications, wallpapers, capacités, raccourcis, outils agent) ;
- pour chaque item : **valeur imposée** + **état** (`verrouillé` | `permissif` | absent) + **cible** (`instance` | `label:<nom>`) ;
- le **catalogue applicatif** faisant autorité (ce qui borne le local) ;
- les **ordres de déclenchement d'install** (cible + app) ;
- l'**état du lien** (actif / rompu) — car la rupture déclenche la levée des verrous **côté SE5**.

> 🔗 **Action de coordination** : ce schéma d'échange doit être validé des **deux côtés**. Toute évolution du format côté controlHub doit être répercutée dans le cadrage SE5, et inversement. Idéalement, un contrat de données partagé (schéma versionné) sert de source unique.

---

## 8. Hors périmètre (pour éviter le scope creep)

- L'**enforcement jusqu'aux postes** (application réelle des verrous, drift, gates de permissions, résolution locale) est **traité côté SE5**, pas ici.
- Le **modèle d'identité / login fédéré** controlHub→SE5 existe déjà ; le Contrat Managé s'appuie dessus, ne le refait pas.

---

## 9. Suggestion de découpage central (à challenger dans ton CE)

- **Epic A — Authoring du contrat** : composer / éditer / versionner un Contrat Managé (items, verrou, permissiveness).
- **Epic B — Labels & groupes imposés** : formulaire labels (mode libre/réservé), déclaration de groupes imposés, association item↔label.
- **Epic C — Ciblage & diffusion** : item → instance ou label + push de l'état vers SE5.
- **Epic D — Dépôt applicatif & catalogue** : autorité catalogue, bornage du local, déclenchement d'install.
- **Epic E — Cycle de vie du lien** : poser/rompre, sémantique de release, audit.

---

*Fin du handoff. Pour toute ambiguïté sur le comportement attendu côté local, se référer au cadrage SE5 en cours dans le BMAD sambaedu-reload.*
