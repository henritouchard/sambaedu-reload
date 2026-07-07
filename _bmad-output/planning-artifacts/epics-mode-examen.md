---
stepsCompleted: [scoping]
inputDocuments:
  - planning-artifacts/study-mode-examen-session-restreinte.md
  - planning-artifacts/epics-capacites-v2.md
---
# Mode examen — session élève restreinte - Epic Breakdown (Epic 41)

## Overview

Doter SE5 d'un **mode examen** : un administrateur bascule une **salle** en examen ; à la prochaine
session, tout utilisateur qui s'y connecte hérite d'un environnement **« presque rien »** — **pas
d'internet** et **seules quelques applications autorisées**, définies par un **profil d'examen
prédéfini**.

Décisions structurantes (Henri, 2026-07-07) — détail et justifications dans
`study-mode-examen-session-restreinte.md` §7 :
- **Granularité = salle (parc physique)** : la coupure internet (`internet_access`, mécanisme
  firewall) est *machine-scoped*, un flag par élève ne couperait pas le web. La salle couvre 100 %
  du besoin réel.
- **Temporalité = flag manuel persistant** : aucun ordonnanceur ni créneau.
- **Modèle = liste positive d'apps autorisées** (constructif) + `internet_access=off` +
  durcissements. Cette liste est **agnostique du mécanisme d'enforcement**.
- **Apps V1 = `RestrictRun`** (whitelist Explorer, réutilise 100 % du mécanisme `registry_list`) —
  niveau **« environnement épuré »**, contournable par un élève déterminé, **assumé**. L'intégrité
  anti-triche (**AppLocker/WDAC**) est une V2 sur la **même donnée**.
- **Pas de kiosk visuel en V1** (bureau standard).

**Périmètre = SE5 (sambaedu-reload) uniquement**, réutilisant le modèle de capacités v2
(capabilities/projections/assignments) et l'agent desired-state. Aucun nouveau mécanisme agent en
V1 : tout se compile vers les mécanismes existants `registry` + `registry_list` + `firewall`.

## Requirements Inventory

### Functional Requirements
- **FR-E1** — Un admin définit des **profils d'examen** nommés = liste positive d'apps autorisées
  (+ internet coupé + durcissements), prédéfinis et réutilisables.
- **FR-E2** — Un admin **bascule une salle en mode examen** en lui appliquant un profil (flag
  manuel persistant) ; le retrait rétablit l'état normal.
- **FR-E3** — En mode examen, la session de tout utilisateur se connectant sur un poste de la salle
  est restreinte **au logon suivant** : seules les apps autorisées se lancent (RestrictRun),
  internet coupé (firewall), durcissements appliqués.
- **FR-E4** — Le **poste enseignant** conserve internet via son groupe logique portant
  explicitement `internet_access=on` (override de précédence logique>physique).
- **FR-E5** — Le control-hub **signale les salles en examen** et permet le **déflag en un clic**
  (garde-fou anti-oubli, cohérent avec le flag persistant).

### NonFunctional Requirements
- **NFR-E1** — V1 = niveau **« environnement épuré »** (RestrictRun) : bloque les lancements
  Explorer courants (menu Démarrer, Exécuter, double-clic), **assumé contournable** (script,
  processus enfant, UNC, UWP). L'intégrité anti-triche est une V2.
- **NFR-E2** — **Cycle de vie propre** : RestrictRun en **HKCU** (hive user, nettoyage auto au
  logoff) ; retrait de `internet_access` via `on`/absent, **jamais `unmanaged`** (sinon la règle
  pare-feu survit).
- **NFR-E3** — La donnée « apps autorisées » est **agnostique du mécanisme** : recompilable en
  AppLocker/WDAC en V2 sans changer l'UX admin ni le catalogue.
- **NFR-E4** — **Pas de kiosk visuel** en V1 ; bureau standard.
- **NFR-E5** — Aucun nouveau mécanisme agent en V1 ; aucun bump de version agent (mécanismes
  `registry`/`registry_list`/`firewall` déjà livrés).

### FR Coverage Map
- FR-E1 : Epic 41 — Story 41.3 (modèle profil) + 41.4 (UI définition)
- FR-E2 : Epic 41 — Story 41.3 (assignation salle + flag) + 41.4 (UI bascule)
- FR-E3 : Epic 41 — Story 41.1 (catalogue exe) + 41.2 (capacité RestrictRun) + 41.3 (composition)
- FR-E4 : Epic 41 — Story 41.3 (documenté) + 41.4 (contrôle/avertissement UI)
- FR-E5 : Epic 41 — Story 41.4
- NFR-E1..E5 : transverses, ancrées 41.1→41.4

## Epic 41: Mode examen (session élève restreinte)

Rendre opérant le basculement d'une salle en examen, sans nouveau mécanisme agent : la liste
positive d'apps autorisées se compile en `RestrictRun`, l'internet se coupe via `internet_access`,
le tout assigné à la salle et piloté par un flag manuel depuis le control-hub. Ordre recommandé :
**41.1 → 41.2 → 41.3 → 41.4**.
**FRs covered:** FR-E1..FR-E5, NFR-E1..NFR-E5

---

### Story 41.1: Catalogue — correspondance application → exécutable(s)

**Intention.** Enrichir le catalogue d'une correspondance **app → nom(s) d'image `.exe`**, la
donnée manquante sans laquelle aucune whitelist ne se construit (RestrictRun aujourd'hui, AppLocker
demain). Pièce commune V1+V2, indépendante de l'enforcement.

**AC-skeleton (à figer au create-story) :**
- Ajout au catalogue d'une liste d'exécutables (`.exe`) par application, pour les apps WPKG
  (`Application`) et natives curées (`NativeApplication`) — table/colonne additive + modèle + seed
  de départ pour les apps scolaires courantes (LibreOffice, navigateurs, etc.).
- Une app peut mapper **plusieurs** exécutables ; une whitelist les inclut tous.
- Rétro-compatible : les apps sans exe mappé sont signalées (elles ne peuvent pas entrer dans une
  whitelist tant que non renseignées).
- **Tâche** : décider si la saisie exe est manuelle (curation admin) ou pré-remplie depuis
  l'inventaire poste (`AgentApplicationInventory`) ; trancher au create-story.

**Dépendances.** Aucune amont. Bloquant pour 41.2/41.3. **Reco dev** : sonnet (schéma catalogue +
seed) — à confirmer au create-story.

---

### Story 41.2: Capacité `RestrictRun` — whitelist d'exécution Explorer

**Intention.** Seeder la capacité `restrict_run` (jumeau whitelist de `blocked_executables`),
bi-projection **registry** (flag `HKCU\...\Policies\Explorer` name `RestrictRun`=1) + **registry_list**
(`RestrictRun\1..N` = liste des `.exe` autorisés). Réutilise **100 %** du mécanisme existant
`registry_list` autoritatif — zéro code agent, zéro bump version.

**AC-skeleton (à figer au create-story) :**
- Migration seed calquée sur `2026_07_03_110000_seed_capabilities_registry_list_lot.php`
  (`blocked_executables`) : options `unmanaged`/`on`/`off`, `off`/absent = purge du flag + de la
  liste.
- La liste de valeurs est **paramétrable par assignment** (les `.exe` autorisés proviennent du
  profil examen, pas d'un seed figé) — vérifier que le `spec` registry_list accepte une liste
  portée par l'assignment/projection et non codée en dur.
- Écriture **HKCU** via le compagnon de session (per-user, effet au **logon suivant**).
- **Documenter** dans la capacité la limite RestrictRun (Explorer-only, contournable) — NFR-E1.
- **Tâche** : Windows exige que l'app d'ouverture de session minimale reste lançable ; définir le
  socle d'exe toujours inclus (explorer.exe, l'agent/compagnon, LogonUI) pour ne pas verrouiller la
  session. À figer au create-story.

**Dépendances.** Amont : 41.1 (noms d'exe). Bloquant pour 41.3. **Reco dev** : sonnet (seed capacité
+ garde socle) — à confirmer au create-story.

---

### Story 41.3: Profil d'examen + assignation salle + flag manuel

**Intention.** Objet **profil d'examen** prédéfini = liste positive d'apps autorisées +
`internet_access=off` + durcissements (`llmnr_disabled`, `offline_files_disabled`). Le flag « salle
en examen » = application du profil comme **assignments** sur le parc physique (salle) ; le retrait
rétablit l'état normal.

**AC-skeleton (à figer au create-story) :**
- Modèle profil examen (nommé, réutilisable) composant des valeurs de capacités existantes ; la
  liste d'apps autorisées se **compile** en valeur `restrict_run` (les exe des apps cochées).
- Flag = créer/retirer les assignments du profil sur le **parc physique** de la salle ; persistant
  jusqu'au retrait.
- Retrait **propre** : `internet_access` repasse sur `on`/absent (jamais `unmanaged`) ;
  `restrict_run` purgé ; durcissements retirés. NFR-E2.
- **Exception enseignant documentée** : le poste enseignant doit être dans un groupe logique portant
  explicitement `internet_access=on` ; sans ce réglage, l'enseignant est coupé aussi (résolution
  par capacité). Fournir un contrôle/avertissement (voir 41.4).
- Vérifier par test la résolution `StateCompiler` : salle `off` (physique) surchargée par groupe
  logique `on` (enseignant) ; élève sans override → `off` appliqué.
- **Tâche** : profil examen = entité première dédiée vs preset d'assignments génériques —
  trancher au create-story en évitant la sur-conception (cf. `feedback_no_overengineered_choices`).

**Dépendances.** Amont : 41.2 (`restrict_run`), `internet_access` (existant), capacités
durcissement (existantes). Bloquant pour 41.4. **Reco dev** : opus (composition capacités +
précédence + résolution testée) — à confirmer au create-story.

---

### Story 41.4: UI admin — définition des profils, bascule salle, badge de suivi

**Intention.** Écran control-hub pour définir les profils d'examen (choisir les apps autorisées),
basculer une salle en examen / la sortir, et **suivre** les salles en examen (badge + déflag en un
clic) — garde-fou anti-oubli du flag persistant.

**AC-skeleton (à figer au create-story) :**
- Page sous `resources/views/pages/` (routing par arborescence) : liste/édition des profils
  d'examen (sélection d'apps depuis le catalogue, signalant les apps sans exe mappé — 41.1).
- Bascule d'une salle en examen (choix du profil) et retrait, avec confirmation.
- **Badge de suivi** « N salle(s) en examen » + liste + **déflag en un clic** (NFR : anti-oubli).
- **Avertissement** si une salle bascule sans poste enseignant correctement exempté
  (groupe logique `internet_access=on` absent) — FR-E4.
- Composants : Livewire SFC pour la réactivité, modale réutilisable pour la bascule, `WithToasts`
  pour le retour utilisateur (conventions projet).
- **Tâche** : où vit l'entrée « mode examen » dans la nav (control-hub vs page salles) — trancher
  au create-story.

**Dépendances.** Amont : 41.3 (profil + flag). **Reco dev** : sonnet (Livewire + UI) — à confirmer
au create-story.

## Notes de coordination / évolutions (hors V1)

- **AppLocker / WDAC (intégrité anti-triche)** : nouveau mécanisme agent (policy AppLocker via GPO
  locale / `Set-AppLockerPolicy` + `AppIDSvc`, ou SRP registre) consommant la **même** liste d'apps
  autorisées (via la donnée app→éditeur/hash, extension de 41.1). Epic dédié.
- **Durcissement intégrité de la coupure internet** : poser le `off` examen sur un **groupe logique
  examen** (rang 3) + exception enseignant en **override poste** (rang 2), pour que le `off` ne cède
  qu'au niveau poste/user (cf. étude §3.2).
- **Kiosk visuel** (masquer bureau, bloquer alt-tab) : Assigned Access ou shell custom — à créer.
- **Créneau planifié / bascule automatique** : ordonnanceur début/fin, en remplacement du flag
  manuel persistant, si le besoin émerge.
- **Per-user firewall** : « élève bridé sur un poste quelconque sans préparer la salle » — nécessite
  un mécanisme firewall user-scoped **et** un moteur transitoire logon/logoff robuste (cf. étude §3).
