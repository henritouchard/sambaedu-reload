---
project: codebase
date: 2026-05-05
scope: Epic 16 — Gestion native des GPOs
stepsCompleted: [01-document-discovery, 02-prd-analysis, 03-epic-coverage-validation, 04-ux-alignment, 05-epic-quality-review, 06-final-assessment]
status: complete
filesIncluded:
  prd: _bmad-output/planning-artifacts/prd.md
  architecture: _bmad-output/planning-artifacts/architecture.md
  epics: _bmad-output/planning-artifacts/epics.md
  ux: null
  stories: []
---

# Implementation Readiness Assessment Report

**Date:** 2026-05-05
**Project:** codebase
**Scope:** Epic 16 — Gestion native des GPOs

---

## Step 1 — Document Inventory

| Type | Statut | Fichier |
|---|---|---|
| PRD | ✅ Whole | `_bmad-output/planning-artifacts/prd.md` (26 ko, 2026-04-16) |
| Architecture | ✅ Whole | `_bmad-output/planning-artifacts/architecture.md` (30 ko, 2026-04-16) |
| Epics | ✅ Whole | `_bmad-output/planning-artifacts/epics.md` (227 ko, 2026-05-05) — Epic 16 : lignes 3294-3332 |
| UX Design | ⚠️ Manquant | aucun fichier `*ux*.md` |
| Stories Epic 16 | ⚠️ Aucune | Epic 16 cadré haut niveau seulement (statut 🔴 not-ready), 6 stories listées (16.1-16.6) sans fichier shardé |

**Doublons :** aucun.

**Décision utilisateur :** poursuivre l'évaluation à l'état actuel (epic cadré, stories non shardées, pas d'UX doc).

---

## Step 2 — PRD Analysis

### Functional Requirements (extraits exhaustifs)

**Gestion des Utilisateurs (SER)**
- **FR1** : Le responsable de collège peut créer un compte utilisateur (élève ou enseignant) sans exposer de concepts AD ou LDAP
- **FR2** : Le système provisionne automatiquement le home directory et les droits applicatifs à la création d'un utilisateur ou à sa première connexion
- **FR3** : Le responsable peut modifier les attributs d'un utilisateur (classe, quota, profil applicatif)
- **FR4** : Le responsable peut désactiver et supprimer un compte utilisateur avec archivage du home directory en deux temps (corbeille → suppression permanente)
- **FR5** : Le système affiche le statut itinérant d'un utilisateur et applique automatiquement les droits différenciés associés
- **FR6** : Le responsable peut importer des utilisateurs depuis un fichier externe (GPEI standalone)

**Gestion des Machines & Parcs (SER)**
- **FR7** : Consultation inventaire machines par salle / parc
- **FR8** : Actions unitaires sur machine (WOL, extinction, reboot)
- **FR9** : Actions batch sur parc entier
- **FR10** : Programmation d'actions cron sur parc
- **FR11** : Import machines/sites via CSV
- **FR12** : Association profil applicatif (AppProfile) à postes individuels et groupes de salles indépendamment de l'OU

**Système de Fichiers (SER)**
- **FR13** : Création/gestion home directories individuels avec quotas XFS
- **FR14** : Création/configuration partages classe avec ACLs POSIX héritées
- **FR15** : Gestion droits d'accès partages (lecture, écriture, dossier échange)
- **FR16** : Suppression home dirs en deux étapes (archivage `/home/trash/` puis purge)

**Impression (SER)**
- **FR17** : Consultation liste imprimantes
- **FR18** : Ajout/configuration/suppression imprimantes via CUPS
- **FR19** : Gestion pilotes Windows associés

**Réseau (SER)**
- **FR20** : Consultation/gestion réservations DHCP et baux
- **FR21** : Configuration entrées DNS
- **FR22** : Import réservations DHCP en masse

**Déploiement Windows (SER) — *Epic 16 + 15 + 17***
- **FR23** : Le responsable peut consulter et gérer les GPOs via l'interface SER (Services/Legacy/) → **🎯 cible Epic 16**
- **FR24** : Le responsable peut gérer les packages WPKG (définition, association aux profils, déclenchement au démarrage)
- **FR25** : Le responsable peut consulter les logs WPKG et rapports d'installation
- **FR26** : Le responsable peut gérer les scripts de démarrage Windows

**Délégations & Permissions (SER)**
- **FR27** : Attribution droits délégués sur périmètre limité (salle, parc)
- **FR28** : Utilisateur délégué ne voit/agit que sur son périmètre
- **FR29** : Calcul droits applicatifs union groupe + individuel (Spatie)

**Établissements & Itinérants (irundoo)**
- **FR33** : irundoo maintient liens user↔UAI par instance SER
- **FR34** : irundoo gère itinérants avec attributs spécifiques par lien user↔UAI
- **FR35** : irundoo filtre/transmet à chaque SER uniquement les users de son UAI

**Imports & Intégrations Académiques**
- **FR36** : SER peut traiter fichier GPEI en standalone
- **FR37** : irundoo parse GPEI et dispatch vers SER selon UAI
- **FR38** : SER synchronise users/machines avec AD via LdapRecord
- **FR39** : SER gère apps autorisées en standalone ; sinon irundoo définit, SER s'y conforme

**Total FRs : 35** (FR1-29, FR33-39 ; FR30-32 absents — confirmer si gap volontaire ou erreur de numérotation)

### Non-Functional Requirements

**Performance**
- **NFR1** : Opérations courantes < 2s sur réseau local
- **NFR2** : Actions longues : feedback démarrage immédiat, état sans bloquer UI
- **NFR3** : Aucune requête LDAP non indexée / scan AD complet

**Sécurité**
- **NFR4** : Mots de passe jamais en clair (correction faille legacy)
- **NFR5** : CAS/SSO via SSL/TLS
- **NFR6** : Pas de bypass admin non authentifié
- **NFR7** : Données personnelles accessibles uniquement aux rôles autorisés (Spatie)
- **NFR8** : Logs d'actions sensibles conservés et horodatés

**Fiabilité & Résilience**
- **NFR9** : Fonctionnement complet sans internet (réseau local isolé)
- **NFR10** : Rollback instance < 5 min via snapshot Proxmox
- **NFR11** : Perte connexion irundoo n'affecte pas SER
- **NFR12** : Migrations idempotentes

**Maintenabilité**
- **NFR13** : Tout le code typé (PHP typed properties, return types, DTOs)
- **NFR14** : Méthodes `Services/Legacy/` commentées avec source legacy + raison refactoring
- **NFR15** : Tests automatisés livrés avec chaque fonctionnalité
- **NFR16** : Setup dev externe via documentation repo seulement

**Intégration**
- **NFR17** : Sync LDAP/AD sur OU standardisée — déviations détectées et signalées
- **NFR18** : Intégrations système (CUPS, DHCP, sudo) encapsulées dans Services dédiés ; pas d'appel direct depuis SFC

**Total NFRs : 18**

### Additional Requirements / Constraints

- **Conformité RGPD** : suppression effective des données personnelles (home dirs deux temps)
- **Mineurs** : précautions particulières sur logs et accès données élèves
- **Local-first** : toutes fonctions MVP opèrent sans dépendance externe
- **Infrastructure hétérogène** : pas de standardisation matérielle inter-établissements
- **Architecture 3 couches** : SFC Livewire → Services → Models (zéro logique métier dans SFC)
- **Cloisonnement legacy (Epic 1bis)** : modules legacy encapsulés avec shims LDAP→Eloquent et MySQL→Eloquent
- **Risque domaine identifié** : sessions cloud Windows, mécanisme legacy non analysé — peut impacter home dirs/profils ; à investiguer avant finalisation MVP
- **⚠️ Zone à investiguer** explicite dans PRD : sessions cloud Windows (Parcours utilisateur dédié à ajouter)

### PRD Completeness Assessment (vis-à-vis Epic 16)

**Forces** :
- FR23 cible Epic 16 nominativement (« consulter et gérer les GPOs via l'interface SER »).
- Mention `Services/Legacy/` impose le pattern d'encapsulation côté Epic 16.
- Le sprint planning MVP place GPOs en Sprint 3 cohérent avec dépendance Epic 4 / Story 1bis.18.

**Faiblesses / gaps potentiels concernant Epic 16** :
1. **FR23 très générique** — un seul FR pour toute la gestion native GPO alors qu'Epic 16 dérive 6 stories (listing, sections spécialisées Firefox/Thunderbird/Wallpaper/Veyon/Wine/Roaming/Raccourcis, CRUD, liaison OU↔WorkstationGroup, hook wpkg.js). Aucun FR ne mentionne explicitement la duplication de GPO, la corbeille, le graphe d'impact, l'ordre de précédence des liaisons, ni la jonction GPO↔WPKG (16.6). À valider en Step 3.
2. **Sections spécialisées non listées** : le PRD ne nomme jamais Firefox / Thunderbird / Wallpaper / Veyon / Wine / Roaming / Raccourcis. Story 16.3 est tirée du legacy `sambaedu/gpo/*.php` mais sans ancrage PRD direct → risque de scope creep ou d'omission.
3. **Pas de FR sur l'audit legacy** : Story 16.1 prévoit un audit `audit-gpo-legacy.md`, sans correspondance dans les FRs (acceptable — c'est de la recherche prérequise, pas un livrable utilisateur).
4. **Frontière Epic 16 ↔ Epic 17 absente du PRD** : seuls FR23 (GPO) et FR26 (scripts Windows) co-existent, sans préciser la jonction (16.6 invoque `wpkg.js`, hook scripts→GPO). Risque d'incohérence Epic 17.
5. **Numérotation FRs trompeuse** : FR30-32 absents (saut FR29→FR33). À clarifier — gap volontaire ou perte de numérotation lors d'un edit.
6. **NFRs muets sur Epic 16** : aucun NFR ne cible spécifiquement le déploiement GPO (idempotence des `samba-tool gpo`, audit log channel `gpo-deploy` mentionné dans epics.md mais sans support PRD).

**Couverture FR23 → Epic 16 stories** :
| Story | Couvert par FR23 ? | Gap PRD |
|---|---|---|
| 16.1 Fondations + audit legacy | Indirect (prérequis non-fonctionnel) | Acceptable |
| 16.2 Listing & lecture GPO | ✅ « consulter » | OK |
| 16.3 Édition sections spécialisées | ✅ « gérer » (générique) | ⚠️ Sections (Firefox, Thunderbird…) non énumérées dans PRD |
| 16.4 CRUD + duplication + corbeille | ⚠️ « gérer » couvre CRUD ; duplication et corbeille non mentionnées | À expliciter |
| 16.5 Liaison GPO↔OU/WorkstationGroup + précédence + graphe | ⚠️ Implicite | À expliciter |
| 16.6 Hook GPO→wpkg.js (jonction Epic 15) | ❌ Non mentionné dans FR23 ni FR24 | **Gap PRD** — la jonction GPO↔WPKG transverse n'est ancrée nulle part |

---

## Step 3 — Epic Coverage Validation

### FR Coverage Map extrait de `epics.md` (section dédiée lignes 170-209)

```
FR23: Epic 9 — Gestion GPOs (Services/Legacy/)    ← entrée historique non mise à jour
```

Et plus loin :
- Epic 9 (ligne 292) : « FRs couverts : FR23, FR24, FR25, FR26 »
- Epic 16 (ligne 343) : « FRs couverts : FR23 »
- Epic 17 (ligne 352) : « FRs couverts : FR26 »
- Epic 3 (ligne 248) : « FRs couverts : FR8 (boot/WOL), FR7 (enrollment), **FR23-26 (déploiement Windows via iPXE)** »

### 🚨 Incohérences traceabilité (critiques)

1. **FR Coverage Map obsolète** — lignes 170-209 listent encore FR23 → Epic 9, alors que la section Epic 16 (ligne 340-345) annonce explicitement « Annule/remplace : Story 9.1 PAUSED » et revendique FR23. La FR Coverage Map n'a pas été synchronisée lors de l'introduction d'Epic 16/17 le 2026-05-01.
2. **Double / triple revendication FR23** :
   - Epic 9 (statut hérité, contient stories 9.1 ❌ ANNULÉE, 9.2, 9.4, 9.5)
   - Epic 16 (annonce remplacer 9.1)
   - Epic 3 « FR23-26 via iPXE » (formulation suspecte — iPXE ne gère pas les GPOs ; vraisemblablement copier-coller d'erreur)
   → Sans nettoyage, l'implémenteur ne sait pas qui possède FR23.
3. **FR26 doublon Epic 9 / Epic 17** : Epic 9 ligne 292 revendique FR26 alors qu'Epic 17 (annonce remplacer Story 9.3) reprend FR26. Même pathologie qu'avec FR23.

### Coverage Matrix — focus Epic 16

| FR | Texte PRD | Couverture Epic 16 (stories) | Statut |
|---|---|---|---|
| FR23 | « Le responsable peut consulter et gérer les GPOs via l'interface SER (Services/Legacy/) » | Stories 16.1 (fondations), 16.2 (listing/lecture), 16.3 (sections spécialisées), 16.4 (CRUD), 16.5 (liaison OU/WG) | ✅ Couvert mais **map obsolète** (FR Coverage Map pointe encore Epic 9) |
| FR23 (suite) | — | Story 16.6 : hook GPO → `wpkg.js` | ⚠️ Va au-delà de FR23 — touche FR24 (WPKG) et FR26 (scripts) implicitement |

### Aspects Epic 16 sans ancrage FR explicite

Story 16.4 — **duplication** de GPO et **corbeille avec restauration** :
→ ni FR23 ni autre FR ne mentionne ces capacités. Risque scope creep ou dépendance non justifiée par le PRD.

Story 16.5 — **ordre de précédence des liaisons** GPO et **graphe d'impact** :
→ implicite dans « gérer les GPOs » mais non explicite. Capacité différentiante / livrable visuel non priorisé par le PRD.

Story 16.6 — **jonction GPO ↔ WPKG** (génération d'une GPO logon/startup invoquant `wpkg.js`) :
→ aucune trace dans les FRs. Pourtant essentielle pour Epic 15 (sans cette story, le pipeline WPKG natif n'est jamais déclenché côté Windows). **Gap PRD critique** — soit le PRD ajoute un FR dédié (ex. « FR40 : Le système publie une GPO de démarrage qui invoque le client WPKG »), soit Epic 16 référence Epic 15 comme requérant. Aujourd'hui ni l'un ni l'autre.

### Cohérence inter-Epic (Epic 16 ↔ 9 / 15 / 17)

- **Epic 9 vs Epic 16** : Epic 9 reste dans `epics.md` avec FR23 dans son ligne « FRs couverts » alors que sa Story 9.1 (la seule à porter les GPOs) est annulée. → Epic 9 devrait soit perdre FR23 dans sa ligne « FRs couverts », soit être marqué partiellement déprécié.
- **Epic 15 vs Story 16.6** : Story 16.6 indique « jonction Epic 15 — à coordonner avec Story 15.2 et 15.5 ». Aucune story Epic 15 (15.1 à 15.5 ✅ implémentées, 15.7 PLANNED) ne mentionne en retour la dépendance à 16.6. → Risque que WPKG soit livré sans déclencheur GPO (point d'entrée Windows).
- **Epic 17 vs Story 16.6** : Le cadrage 16.6 dit « probablement Story 17.x si l'invocation `wpkg.js` est elle-même un script Windows packagé ». Cette dépendance reste conditionnelle non tranchée.

### Coverage Statistics (scope Epic 16)

- FRs revendiqués par Epic 16 : **1** (FR23)
- FRs PRD effectivement traçables aux stories Epic 16 : **1** (FR23, partiellement)
- Stories Epic 16 sans FR PRD propre : **2** (16.4 duplication/corbeille, 16.6 hook WPKG)
- FRs PRD apparentés non revendiqués par Epic 16 mais touchés indirectement : **FR24** (partie WPKG → Story 16.6), **FR26** (scripts → Story 16.6 si wpkg.js packagé)
- **Couverture Epic 16 → FR23 : 100% au sens textuel, mais avec map obsolète et ambiguïtés inter-Epic non résolues.**

---

## Step 4 — UX Alignment Assessment

### UX Document Status

❌ **Aucun document UX trouvé** dans `_bmad-output/planning-artifacts/`.

### Évaluation : UX implicite ?

Oui, fortement implicite. Le PRD précise :
- Architecture de rendu **MPA + composants Livewire 4** (« pas de SPA », « rendu côté serveur la norme »)
- 5 parcours utilisateurs nommés (Marie, Thomas, Karim, Alex, sessions cloud)
- Navigateurs cibles, performance perçue, responsive non prioritaire

L'architecture définit la **convention d'organisation des vues** (`resources/views/pages/[route]/index.blade.php`, `_partials/`, sous-routes) et un mapping FRs → Pages.

→ **L'UX globale est cadrée par convention**, pas par doc. Acceptable pour un projet brownfield où l'UI réplique le legacy. Mais reste un risque sur les nouveautés Epic 16.

### Alignement spécifique Epic 16

#### A. UX ↔ PRD (parcours utilisateurs)

🚨 **Aucun des 5 parcours PRD ne traite des GPOs**. Marie (P1, P2) gère utilisateurs ; Thomas (P3) salle déléguée ; Karim (P4) DHCP ; Alex (P5) contributeur. **Le persona "responsable qui pilote les GPOs natives" n'est pas explicité.**

→ Sans persona dédié, les questions de design Epic 16 (qui édite quoi ? quel niveau de granularité dans les sections Firefox/Thunderbird/etc. ? graphe ou tableau pour les liaisons ?) seront tranchées au moment des stories — risque d'inversions tardives.

#### B. UX ↔ Architecture

| Élément Epic 16 | Architecture | Statut |
|---|---|---|
| Page racine `/app/gpo` (Story 16.2) | `pages/windows-deploy/` mentionnée (line 486) pour FR23-26 — **pas de `pages/gpo/` distincte** | ⚠️ Incohérence : Story 16.2 cadre dit « page Livewire native `/app/gpo` ». Architecture suggère `windows-deploy/`. À trancher : page séparée ou sous-page de `windows-deploy/` ? |
| Namespace `App\Gpo` (Story 16.1) | Architecture dit `app/Services/Windows/GpoService` (line 453) | ⚠️ Discrepance namespace : `App\Gpo` (story) vs `App\Services\Windows\Gpo*` (archi) |
| Channel logs `gpo-deploy` | Pas mentionné — archi ne décrit que `legacylog` et GlitchTip | ⚠️ À déclarer dans `config/logging.php` lors de Story 16.1 |
| Livewire SFC édition GPO | Convention « parties réactives » prévue, conforme | ✅ |
| Composants atomic design (atoms/molecules/organisms) | Disponibles | ✅ |
| Notifications via `WithToasts` | Standard du projet | ✅ |
| Modale réutilisable (CLAUDE.md) | Existe | ✅ |

#### C. Spécificités UI Epic 16 non couvertes

1. **Story 16.3 — sections spécialisées** : 7 sections (Firefox, Thunderbird, Wallpaper, Veyon, Wine, Roaming, Raccourcis). Le cadrage « probablement à splitter en sous-stories par section au moment du développement » → décision UX différée. **Aucune maquette ni gabarit de section** dans les artefacts. Pour 7 sections, l'absence de patron commun est un risque d'incohérence visuelle.
2. **Story 16.5 — graphe de liaison GPO → OU/Group → postes** : composant graphe non listé dans `components/organisms/`. Choix lib (vis.js / mermaid / d3 / table simple) à décider. Aucune décision archi.
3. **Story 16.4 — corbeille avec restauration paramétrable** : le pattern « corbeille » existe pour home dirs (FR16, deux temps) mais aucune convention UI commune n'est documentée pour réutilisation côté GPO.
4. **Story 16.6 — UI pour générer/éditer la GPO logon WPKG** : c'est une UI très spécifique, sans précédent dans le projet. Décisions UX (formulaire dédié ? wizard ? bouton magique « publier WPKG GPO » ?) entièrement ouvertes.

### Warnings UX

- ⚠️ **Pas de spec UX dédiée** Epic 16 — acceptable mais doit être compensé au moment de la rédaction de chaque story (création par `bmad-create-story` avec passage UX explicite).
- ⚠️ **Discrepance namespace** `App\Gpo` (cadrage story) vs `App\Services\Windows\Gpo*` (architecture). À trancher avant Story 16.1 — sinon l'audit legacy + bootstrap pourraient partir dans deux directions.
- ⚠️ **Discrepance route** `/app/gpo` (cadrage story 16.2) vs `pages/windows-deploy/` (architecture FR23-26). À trancher.
- ⚠️ **Sections 16.3 sans gabarit commun** — risque d'incohérence visuelle entre les 7 sections spécialisées si chaque sous-story décide indépendamment.
- ⚠️ **Composant graphe non choisi** (Story 16.5) — décision technique avec impact bundle/perf à anticiper.
- ⚠️ **Aucun parcours utilisateur PRD** ne traite des GPOs → persona implicite (« responsable qui maîtrise déjà les GPOs Samba »). Risque d'UX trop technique pour un responsable de collège tel que Marie.

---

## Step 5 — Epic Quality Review

> Rappel scope : Epic 16 contient 6 stories cadrées **haut niveau uniquement** (statut 🔴 not-ready), sans fichiers de story shardés. Les évaluations ci-dessous portent sur les cadrages d'`epics.md` lignes 3294-3332.

### A. Epic Structure Validation

#### Titre & valeur utilisateur

| Item | Verdict |
|---|---|
| Titre « Epic 16 — Gestion native des GPOs » | ✅ User-centric (« gestion » est une capacité utilisateur) |
| Goal | ⚠️ Partiellement orienté outcome : « réécriture native Laravel ». La phrase commence par un objectif technique (« réécriture native ») avant d'évoquer l'utilisateur (« le responsable consulte, crée, édite, lie, et duplique »). Reformulation plus user-first souhaitable. |
| Value proposition | ✅ Implicite : remplacer le shim 1bis.18 (UI legacy) par UI native cohérente. Acceptable en brownfield. |

**Risque borderline** : Story 16.1 « Fondations GPO natives + audit legacy » est typiquement le genre de story qui dérive en **technical milestone sans valeur utilisateur**. Elle livre `audit-gpo-legacy.md`, infra (channel logs, namespace, abstraction `GpoService`), tables de tracking — **aucune capacité utilisateur visible**. C'est habituel pour une story 0/foundational mais reste un écart aux best practices BMAD.

#### Indépendance epic

- Epic 16 prérequis déclarés : Epic 4 ✅, Story 1bis.18 ✅ — stables, pas de forward dependency.
- Epic 16 **annule/remplace** Story 9.1 PAUSED → cohérent.
- Story 16.6 référence **Epic 15** (« jonction Epic 15 — Story 15.2 et 15.5 ») et **Epic 17** (« Probablement Story 17.x »).
  - Epic 15 : 15.1-15.5 ✅ implémentées → pas de forward dependency réelle. ✅
  - Epic 17 : 🔴 not-ready, en parallèle d'Epic 16. **Forward dependency possible** si la conditionnelle « si l'invocation `wpkg.js` est elle-même un script Windows packagé » se vérifie. À trancher avant de figer Story 16.6.

### B. Story Quality Assessment (par story Epic 16)

#### Story 16.1 — Fondations GPO natives + audit legacy

| Critère | Verdict |
|---|---|
| User value | 🔴 **Aucune** — purement infrastructure + audit |
| Indépendance | ✅ Premier maillon |
| Sizing | ⚠️ Mixte : « channel logs + namespace + abstraction `GpoService` + tables tracking + audit fichier par fichier de `sambaedu/gpo/*.php` ». Charge de travail importante. À splitter probablement (16.1a fondations / 16.1b audit) |
| AC formelles | ❌ Absentes — cadrage haut niveau seulement |

#### Story 16.2 — Listing & lecture GPO (UI native)

| Critère | Verdict |
|---|---|
| User value | ✅ Le responsable peut consulter |
| Indépendance | ⚠️ Dépend de 16.1 (GpoService) — acceptable car 16.1 est dans le même epic |
| Sizing | ✅ Périmètre raisonnable (page Livewire, filtres, lecture détail, badges) |
| AC formelles | ❌ Absentes |
| Risque | 🟠 « remplace progressivement le shim 1bis.18 » — la double-route legacy/native pendant la transition demande une stratégie de routage explicite (catchall override). Non précisé. |

#### Story 16.3 — Édition de sections GPO

| Critère | Verdict |
|---|---|
| User value | ✅ Élevée |
| Indépendance | ✅ Après 16.2 |
| Sizing | 🔴 **Beaucoup trop large** — 7 sections (Firefox, Thunderbird, Wallpaper, Raccourcis, Veyon, Wine, Roaming) × validation × preview. Le cadrage le reconnaît : « probablement à splitter en sous-stories par section ». |
| AC formelles | ❌ Absentes |
| Recommandation | Splitter en 16.3.a → 16.3.g (une story par section) ou regrouper par affinité (e.g. Firefox+Thunderbird, Wallpaper+Raccourcis, Veyon+Wine, Roaming). |

#### Story 16.4 — Création / duplication / suppression

| Critère | Verdict |
|---|---|
| User value | ✅ Élevée |
| Indépendance | ✅ Après 16.2/16.3 |
| Sizing | 🟠 Combine CRUD + duplication (copie d'arbre policy) + corbeille avec restauration durée paramétrable. À borner : la corbeille à délai est un sous-thème mineur — peut être différé ou splitté. |
| AC formelles | ❌ Absentes |
| Gap PRD | Duplication et corbeille **non couvertes par FR23** (cf. Step 3). |

#### Story 16.5 — Liaison GPO ↔ OU / parc + propagation

| Critère | Verdict |
|---|---|
| User value | ✅ Très élevée (sans liaison, une GPO ne s'applique à personne) |
| Indépendance | ✅ Après 16.4 |
| Sizing | 🟠 Liaisons CRUD + précédence + graphe d'impact en une story. Le **graphe d'impact** est un livrable visualisation à part entière (choix de lib, perf sur grands parcs) — candidat au split. |
| AC formelles | ❌ Absentes |
| Risque | Précédence GPO (LSDOU/héritage) est une logique métier non triviale et non explicitée. À détailler dans les AC. |

#### Story 16.6 — Hook GPO → wpkg.js (jonction Epic 15)

| Critère | Verdict |
|---|---|
| User value | ✅ Indirecte (déclenche WPKG côté Windows) |
| Indépendance | 🟠 Dépend d'Epic 15 stories 15.2/15.5 ✅ implémentées — OK. Mais conditionnelle Epic 17 non tranchée. |
| Sizing | ⚠️ Pas clair sans AC. Génération d'une GPO logon spéciale est une opération unitaire ; mais la coordination (chemin pointé doit être cohérent avec ce que 15.2 produit) demande un alignement transversal. |
| AC formelles | ❌ Absentes |
| Gap PRD | 🚨 **Critique** : aucune FR ne couvre cette jonction (cf. Step 3) |
| Risque | **Story load-bearing pour Epic 15** — sans 16.6, le pipeline WPKG livré n'a pas de déclencheur Windows. L'absence d'AC bloque la stabilisation d'Epic 15. |

### C. Dependency Analysis (Epic 16)

| Source | → Cible | Type | Statut |
|---|---|---|---|
| 16.1 | — | (premier) | ✅ |
| 16.2 | 16.1 (GpoService) | Intra-epic forward OK | ✅ |
| 16.3 | 16.2 (UI base) | Intra-epic forward OK | ✅ |
| 16.4 | 16.2 | Intra-epic forward OK | ✅ |
| 16.5 | 16.4 | Intra-epic forward OK | ✅ |
| 16.6 | 16.4 + Story 15.2 ✅ + Story 15.5 ✅ | Cross-epic vers passé | ✅ |
| 16.6 | Story 17.x (conditionnelle) | Cross-epic forward conditionnel | 🟠 À trancher |

**Aucune circularité détectée.** Indépendance globale Epic 16 OK.

### D. Database / Entity Creation Timing

Story 16.1 mentionne « tables de tracking si pertinentes ». **Indéterminé** — non décidé. À cadrer dans la story formelle. Conformité best practice (« Each story creates tables it needs ») = à enforcer story par story.

### E. Brownfield Indicators

✅ Story 16.1 inclut explicitement un **audit legacy** + production d'un document `audit-gpo-legacy.md`. Cohérent avec le caractère brownfield et la stratégie « shim 1bis.18 reste valide en transition ».

### F. Acceptance Criteria — bilan global

🔴 **0 / 6 stories** ont des AC en format Given/When/Then.

C'est le défaut le plus structurant : Epic 16 est cadré au niveau « phrase descriptive » uniquement. Avant l'implémentation, **chaque story doit passer par `bmad-create-story`** pour produire AC formelles, slices techniques, edge cases.

### G. Findings consolidés par sévérité

#### 🔴 Critical Violations

1. **6/6 stories sans AC formelles** — implémentation impossible en l'état. Bloquant total.
2. **Story 16.6 sans FR PRD** ni mention dans Epic 15 (pourtant load-bearing pour la chaîne WPKG end-to-end). Gap traceabilité produit.
3. **Story 16.3 overscoped** (7 sections en une story) — découpage indispensable avant implémentation.

#### 🟠 Major Issues

4. **Story 16.1 sans valeur utilisateur** — typique foundational story. Acceptable mais à découper en 16.1a (fondations infra) + 16.1b (audit legacy) car charge mixte.
5. **Story 16.4 inclut corbeille à délai paramétrable non couverte par FR23** — scope à border, capacité différentiable.
6. **Story 16.5 mêle liaisons + précédence + graphe** — graphe candidat au split.
7. **Discrepance namespace** `App\Gpo` (story 16.1) vs `App\Services\Windows\Gpo*` (architecture line 453).
8. **Discrepance route** `/app/gpo` (story 16.2) vs `pages/windows-deploy/` (architecture line 486).
9. **FR Coverage Map d'`epics.md`** (lignes 170-209) non mise à jour : pointe FR23 → Epic 9 alors qu'Epic 16 le revendique.
10. **Stratégie de transition shim 1bis.18 → UI native** non précisée (catchall override, double routing). Risque silencieux pour Story 16.2.

#### 🟡 Minor Concerns

11. **Goal Epic 16 commence par tech (« réécriture native »)** avant le résultat utilisateur. Reformulation user-first conseillée.
12. **Story 16.6 condition Epic 17** non tranchée — à trancher avant de figer 16.6.
13. **Channel logs `gpo-deploy`** non déclaré dans architecture.
14. **Tables de tracking 16.1** : « si pertinentes » — décision à prendre.
15. **Aucun parcours utilisateur PRD** ne traite des GPOs (Step 4) — UX persona implicite, à reverify.

---

## Summary and Recommendations

### Overall Readiness Status

🔴 **NOT READY pour implémentation directe.**

Epic 16 est cohérent dans sa vision et son périmètre est compris, mais il reste au stade de **cadrage haut niveau**. Le passage à l'implémentation requiert plusieurs actions structurantes en amont. Ce verdict n'est pas une surprise : Epic 16 est explicitement marqué **Statut 🔴 not-ready** dans `epics.md`.

### Critical Issues Requiring Immediate Action

| # | Issue | Sévérité | Action |
|---|---|---|---|
| C1 | Aucune AC formelle sur les 6 stories Epic 16 | 🔴 Bloquant | Pour chaque story, lancer `/bmad-create-story` avant développement |
| C2 | Story 16.6 (jonction GPO↔WPKG) sans FR PRD ni mention dans Epic 15 | 🔴 Bloquant | Ajouter un FR dédié au PRD (proposition : « FR40 : Le système publie une GPO de démarrage qui invoque le client WPKG sur les postes Windows ») et croiser la dépendance dans Epic 15 |
| C3 | Story 16.3 over-scopée (7 sections en une story) | 🔴 Bloquant | Décider du découpage avant story formelle : par section, par affinité, ou en MVP « 2-3 sections critiques » + backlog |
| C4 | FR Coverage Map d'`epics.md` obsolète (FR23 toujours rattaché à Epic 9) | 🟠 Majeur | Mettre à jour la Map : FR23 → Epic 16, FR26 → Epic 17 ; corriger la formulation Epic 3 « FR23-26 via iPXE » (formulation suspecte/erronée) |
| C5 | Discrepance namespace `App\Gpo` (story) vs `App\Services\Windows\Gpo*` (architecture) | 🟠 Majeur | Trancher dans Story 16.1 fondations |
| C6 | Discrepance route `/app/gpo` vs `pages/windows-deploy/` | 🟠 Majeur | Trancher avant Story 16.2 |

### Recommended Next Steps (séquencé)

1. **Mettre à jour le PRD** :
   - Ajouter FR pour la jonction GPO↔WPKG (Story 16.6).
   - Corriger la numérotation FRs (gap FR30-32) ou documenter le saut volontaire.
   - (Optionnel) Renforcer FR23 : énumérer les sections spécialisées attendues (Firefox, Thunderbird, Wallpaper, Veyon, Wine, Roaming, Raccourcis).
2. **Mettre à jour `epics.md`** :
   - Synchroniser la FR Coverage Map (lignes 170-209) sur la nouvelle propriété FR23→Epic 16, FR26→Epic 17.
   - Retirer ou amender la mention Epic 3 « FR23-26 via iPXE » (Epic 3 ne déploie pas de GPOs).
   - Retirer FR23 de la ligne « FRs couverts » d'Epic 9 ; idem FR26.
   - Reformuler le Goal Epic 16 user-first.
3. **Trancher les ambiguïtés architecturales** :
   - Namespace `App\Gpo` vs `App\Services\Windows\Gpo*` (recommandation : aligner sur l'architecture existante = `Services\Windows\Gpo*` pour cohérence avec WPKG).
   - Route `/app/gpo` vs `pages/windows-deploy/gpo/` (recommandation : sous-page de `windows-deploy/` pour homogénéité Epic 15/16/17).
   - Déclarer le channel `gpo-deploy` dans `config/logging.php`.
4. **Décider du découpage de Story 16.3** (sections spécialisées) avant rédaction formelle.
5. **Décider la conditionnelle 16.6 ↔ Epic 17** (si `wpkg.js` est lui-même un script Windows packagé Epic 17).
6. **Préciser la stratégie de transition shim 1bis.18 → UI native** (override catchall, double routing, drapeau de feature). Impacte Story 16.2.
7. **Lancer `/bmad-create-story 16.1`** une fois les points 1-6 stabilisés. Découper si nécessaire (16.1a fondations / 16.1b audit).
8. **Itérer story par story** (`/bmad-create-story` pour 16.2 → 16.6) — chaque story produit AC formelles, slices techniques, edge cases.

### Recommandations transversales (utiles à l'audit Story 16.1)

- Pendant l'audit `audit-gpo-legacy.md`, **ne pas oublier l'AD central** : les commits récents du legacy peuvent contenir des évolutions dans `gpo/` non encore reportées (cf. backlog USL dans `epics.md` lignes 3368+).
- Identifier les capacités legacy **non revendiquées** par le cadrage Epic 16 (potentiellement des fonctionnalités utilisées en prod). Risque qu'Epic 16 livre une parité fonctionnelle incomplète sans s'en apercevoir.

### Statistiques

| Indicateur | Valeur |
|---|---|
| Documents évalués | 3 (PRD, Architecture, Epics) — UX absent |
| FRs PRD totaux | 35 (FR1-29 + FR33-39 ; FR30-32 manquants) |
| FRs revendiqués par Epic 16 | 1 (FR23) |
| Stories Epic 16 cadrées | 6 |
| Stories Epic 16 avec AC formelles | 0 |
| Stories Epic 16 avec story file shardé | 0 |
| Issues critiques (🔴) | 3 |
| Issues majeures (🟠) | 7 |
| Issues mineures (🟡) | 5 |
| **Total findings** | **15** |

### Final Note

Epic 16 a une vision claire et un périmètre cohérent, ancré dans la nécessité de sortir du shim 1bis.18. **Le cadrage haut niveau est correct ; ce qui manque, c'est la phase de mise en story**. Aucune des 6 stories n'est prête à être implémentée en l'état (zéro AC, scope flou par endroits, dépendances cross-epic incomplètement tranchées).

Trois actions sont prioritaires avant tout dev :
1. Combler le gap PRD sur Story 16.6 (jonction WPKG).
2. Découper Story 16.3 (7 sections).
3. Lancer `/bmad-create-story 16.1` après les arbitrages d'archi (namespace, route, channel logs).

Une fois ces points levés, Epic 16 peut entrer en phase d'implémentation avec un risque contrôlé. Le statut `🔴 not-ready` actuel d'Epic 16 reflète fidèlement la situation — ce rapport en explicite les leviers de levée.

---

**Date de l'évaluation :** 2026-05-05
**Évaluateur :** Implementation Readiness Skill (BMAD)
**Rapport :** `_bmad-output/planning-artifacts/implementation-readiness-report-2026-05-05-epic16.md`




