---
date: 2026-04-22
project: codebase
stepsCompleted:
  - step-01-document-discovery
  - step-02-prd-analysis
  - step-03-epic-coverage-validation
  - step-04-ux-alignment
  - step-05-epic-quality-review
  - step-06-final-assessment
documentsUsed:
  prd: _bmad-output/planning-artifacts/prd.md
  architecture: _bmad-output/planning-artifacts/architecture.md
  epics: _bmad-output/planning-artifacts/epics.md
  ux: null
  supplementary:
    - _bmad-output/planning-artifacts/idempotency.md
---

# Implementation Readiness Assessment Report

**Date:** 2026-04-22
**Project:** codebase

---

## Step 1 — Document Discovery

### Inventaire des documents

| Type | Fichier | Taille | Modifié |
|------|---------|--------|---------|
| PRD | prd.md | 26 KB | 2026-04-16 |
| Architecture | architecture.md | 30 KB | 2026-04-16 |
| Epics & Stories | epics.md | 126 KB | 2026-04-22 |
| UX Design | — | — | NON TROUVÉ |

### Documents supplémentaires
- `idempotency.md` (30 KB, 2026-04-16) — référence technique idempotence
- Rapports readiness précédents : 2026-03-18, 2026-03-19
- Sprint change proposals : 2026-03-25, 2026-04-15, 2026-04-16, 2026-04-17

### Issues identifiées
- ⚠️ Aucun document UX — évaluation de cohérence visuelle non disponible

---

## Step 2 — PRD Analysis

### Functional Requirements

**Gestion des Utilisateurs (SER)**
- **FR1 :** Le responsable de collège peut créer un compte utilisateur (élève ou enseignant) sans exposer de concepts AD ou LDAP
- **FR2 :** Le système provisionne automatiquement le home directory et les droits applicatifs à la création d'un utilisateur ou à sa première connexion si le home directory est manquant
- **FR3 :** Le responsable peut modifier les attributs d'un utilisateur (classe, quota, profil applicatif)
- **FR4 :** Le responsable peut désactiver et supprimer un compte utilisateur avec archivage du home directory en deux temps (corbeille → suppression permanente)
- **FR5 :** Le système affiche le statut itinérant d'un utilisateur et applique automatiquement les droits différenciés associés
- **FR6 :** Le responsable peut importer des utilisateurs depuis un fichier externe (GPEI standalone)

**Gestion des Machines & Parcs (SER)**
- **FR7 :** Le responsable peut consulter l'inventaire des machines par salle et par parc
- **FR8 :** Le responsable peut effectuer des actions unitaires sur une machine (WOL, extinction, reboot)
- **FR9 :** Le responsable peut effectuer des actions batch sur un parc entier
- **FR10 :** Le responsable peut programmer des actions cron sur un parc
- **FR11 :** Le responsable peut importer des machines et des sites depuis un fichier CSV
- **FR12 :** Le système associe un profil applicatif (AppProfile) à des postes individuels et à des groupes de salles indépendamment de la hiérarchie OU

**Système de Fichiers (SER)**
- **FR13 :** Le système crée et gère les home directories individuels avec quotas XFS
- **FR14 :** Le responsable peut créer et configurer des répertoires de partage par classe avec ACLs POSIX héritées
- **FR15 :** Le responsable peut gérer les droits d'accès sur les partages de classe (lecture, écriture, dossier échange)
- **FR16 :** Le système supprime les home directories en deux étapes (/home/trash/ puis suppression permanente optionnelle)

**Impression (SER)**
- **FR17 :** Le responsable peut consulter la liste des imprimantes et leurs détails
- **FR18 :** Le responsable peut ajouter, configurer et supprimer des imprimantes via CUPS
- **FR19 :** Le responsable peut gérer les pilotes Windows associés aux imprimantes

**Réseau (SER)**
- **FR20 :** Le responsable peut consulter et gérer les réservations DHCP et les baux actifs
- **FR21 :** Le responsable peut configurer les entrées DNS
- **FR22 :** Le responsable peut importer des réservations DHCP en masse

**Déploiement Windows (SER)**
- **FR23 :** Le responsable peut consulter et gérer les GPOs via l'interface SER (Services/Legacy/)
- **FR24 :** Le responsable peut gérer les packages WPKG (définition, association aux profils, déclenchement au démarrage)
- **FR25 :** Le responsable peut consulter les logs WPKG et les rapports d'installation
- **FR26 :** Le responsable peut gérer les scripts de démarrage Windows

**Délégations & Permissions (SER)**
- **FR27 :** L'administrateur peut attribuer des droits délégués à un utilisateur sur un périmètre limité (salle, parc)
- **FR28 :** Un utilisateur délégué ne peut voir et agir que sur son périmètre de délégation
- **FR29 :** Le système calcule les droits applicatifs comme l'union des droits de groupe et des droits individuels (Spatie)

**Gestion des Établissements & Itinérants (irundoo)**
- **FR33 :** irundoo maintient les liens utilisateur↔établissement (UAI) pour chaque instance SER
- **FR34 :** irundoo gère les utilisateurs itinérants avec des attributs spécifiques (quota réduit, droits limités) par lien user↔UAI
- **FR35 :** irundoo filtre et transmet à chaque instance SER uniquement les utilisateurs relevant de son UAI

**Imports & Intégrations Académiques (irundoo + SER)**
- **FR36 :** SER peut recevoir et traiter un fichier GPEI en mode standalone
- **FR37 :** irundoo peut parser un fichier GPEI et dispatcher les mises à jour vers les instances SER concernées selon leur UAI
- **FR38 :** SER synchronise les données utilisateurs et machines avec l'AD local via LdapRecord
- **FR39 :** SER gère les applications autorisées à l'installation en mode standalone ; quand irundoo est présent, irundoo définit les apps autorisées et SER s'y conforme

**Total FRs : 35** (numérotation PRD avec trous FR30–FR32, non utilisés)

### Non-Functional Requirements

**Performance**
- **NFR1 :** Opérations courantes < 2s sur réseau local
- **NFR2 :** Feedback immédiat des actions longues sans bloquer l'interface
- **NFR3 :** Aucune requête LDAP non indexée ou scan AD complet

**Sécurité**
- **NFR4 :** Mots de passe jamais en clair (app/sessions)
- **NFR5 :** CAS/SSO via SSL/TLS
- **NFR6 :** Aucun bypass admin non authentifié
- **NFR7 :** Accès aux données personnelles selon moindre privilège (Spatie)
- **NFR8 :** Logs d'actions sensibles conservés et horodatés

**Fiabilité & Résilience**
- **NFR9 :** SER fonctionne sans internet (local-first)
- **NFR10 :** Rollback complet instance en < 5 min via snapshot Proxmox
- **NFR11 :** Perte de connexion irundoo sans impact sur SER
- **NFR12 :** Migrations de données idempotentes

**Maintenabilité**
- **NFR13 :** Tout le code typé (PHP typed properties, return types, DTOs)
- **NFR14 :** Chaque méthode `Services/Legacy/` commentée (fichier legacy source + raison refactoring futur)
- **NFR15 :** Tests automatisés avant passage en bêta
- **NFR16 :** Environnement dev installable via docs uniquement

**Intégration**
- **NFR17 :** Synchro LDAP/AD repose sur structure OU standardisée — déviations détectées et signalées
- **NFR18 :** Intégrations système (CUPS, DHCP, sudo) encapsulées dans Services — pas d'appel système direct depuis SFC Livewire

**Total NFRs : 18**

### Additional Requirements

**Contraintes / Conformité**
- **RGPD** : gestion durées conservation, suppression effective (corbeille → permanent)
- **Mineurs** : précautions sur logs et données d'accès
- **Local-first** : pas de dépendance internet pour MVP
- **Infrastructure hétérogène** : pas de standardisation matérielle inter-établissements
- **Mises à jour MVP** : gérées manuellement

**Intégrations requises (tableau PRD)**
- AD/LDAP local (MVP core, LdapRecord)
- CUPS (Sprint 1)
- DHCP/DNS (Sprint 2)
- GPEI (SER standalone + irundoo orchestration)
- AAF/SIECLE (post-MVP)

**Architecture imposée**
- MPA Livewire 4 (pas de SPA)
- 3 couches : SFC Livewire → Services → Models (SQL+AD)
- Aucune notion de "central" dans SER
- Legacy cloisonné dans `legacy/` avec shims LDAP→Eloquent et MySQL→Eloquent
- ControlHubTasks pour actions longues
- Permissions Spatie (additives : groupe ∪ individuel)

**Zone à investiguer (flagged ⚠️)**
- **Sessions cloud Windows** : mécanisme legacy non analysé — impact potentiel sur home dirs et profils — à investiguer avant finalisation Sprint 3

### PRD Completeness Assessment

✅ **Points forts :**
- Executive summary clair, parcours utilisateurs détaillés (5 personas)
- FRs organisés par domaine fonctionnel
- NFRs couvrent performance / sécurité / résilience / maintenabilité / intégration
- Scoping MVP sprint-by-sprint explicite
- Stratégie de risques formalisée avec mitigations
- Dual product (SER open-source + irundoo SaaS) assumé comme principe architectural

⚠️ **Points d'attention :**
- Numérotation FR avec trous (FR30–FR32 absents) — à confirmer comme voulu ou vestige de refactor PRD
- **Aucune FR explicite pour les permissions granulaires Epic 7** — les FR27/28/29 couvrent la délégation et le calcul additif (Spatie), mais les exigences détaillées (granularité par action, UI de configuration, migration des anciens délégués…) sont implicites
- UX absent — impacte évaluation de complétude des exigences d'interface
- Sessions cloud Windows : zone à investiguer flagged mais non résolue
- Modèle d'abonnement irundoo hors scope MVP — à confirmer OK pour Epic 7

---

## Step 3 — Epic Coverage Validation

### Coverage Matrix

| FR | PRD (résumé) | Epic / Story | Statut |
|----|---|---|---|
| FR1  | Création user sans jargon AD | Epic 2 / Story 2.1 ✅ | ✓ Couvert |
| FR2  | Provisioning home dir + droits | Epic 2 / Story 2.1 ⏳ | ✓ Couvert |
| FR3  | Modifier attributs user | Epic 2 / Story 2.2 ⏳ | ✓ Couvert (partiellement implémenté : classe) |
| FR3b | **[ajouté dans epics]** Droits délégation partages + Spatie | Epic 7 | ✓ Couvert |
| FR4  | Désactiver / supprimer user | Epic 2 / Story 2.3 ⏳ | ✓ Couvert |
| FR5  | Statut itinérant | Epic 2 / Story 2.4 | ✓ Couvert |
| FR6  | Import GPEI standalone | Epic 11 / Story 11.3 | ✓ Couvert (repositionné dans Epic 11) |
| FR7  | Inventaire machines par groupe physique / logique | Epic 4 / Story 4.1 ✅ | ✓ Couvert |
| FR8  | Actions unitaires machine (WOL) | Epic 4 / Story 4.2 ✅ | ✓ Couvert |
| FR8b | **[ajouté dans epics]** Feedback readiness WOL | Epic 4 / Story 4.2 ✅ | ✓ Couvert |
| FR9  | Actions batch workstationGroup | Epic 4 / Story 4.3 ✅ | ✓ Couvert |
| FR10 | Crons workstationGroup | Epic 4 / Story 4.4 ✅ | ✓ Couvert |
| FR11 | Import CSV machines + salles | Epic 4 / Story 4.5 | ✓ Couvert |
| FR12 | AppProfile postes/groupes | Epic 4 / Story 4.6 ✅ | ✓ Couvert |
| FR13 | Home dirs + quotas XFS | Epic 5 / Story 5.1 | ✓ Couvert |
| FR14 | Partages classe + ACLs POSIX | Epic 5 / Story 5.2 | ✓ Couvert |
| FR15 | Droits d'accès partages | Epic 5 / Story 5.2 | ✓ Couvert |
| FR16 | Suppression home dir 2 temps | Epic 5 / Story 5.1 | ✓ Couvert |
| FR17 | Liste imprimantes | Epic 6 / Story 6.1 | ✓ Couvert |
| FR18 | CRUD imprimantes CUPS | Epic 6 / Story 6.1 | ✓ Couvert |
| FR19 | Pilotes Windows imprimantes | Epic 6 / Story 6.2 | ✓ Couvert |
| FR20 | DHCP réservations + baux | Epic 8 / Story 8.1 | ✓ Couvert |
| FR21 | DNS | Epic 8 (⚠️ investigation requise) | ⚠️ Couvert sous condition |
| FR22 | Import DHCP masse | Epic 8 / Story 8.1 | ✓ Couvert |
| FR23 | GPOs (Services/Legacy/) | Epic 9 / Story 9.1 | ✓ Couvert |
| FR24 | WPKG packages + profils | Epic 9 / Story 9.2 | ✓ Couvert |
| FR25 | Logs + rapports WPKG | Epic 9 / Stories 9.4 + 9.5 | ✓ Couvert |
| FR26 | Scripts démarrage Windows | Epic 9 / Story 9.3 | ✓ Couvert |
| FR27 | Droits délégués périmètre | **Epic 7 / Story 7.1** | ✓ Couvert |
| FR28 | Vue filtrée délégation | **Epic 7 / Story 7.1** | ✓ Couvert |
| FR29 | Calcul Spatie union groupe+individuel | **Epic 7 / Story 7.2 ⏳** | ✓ Couvert (prérequis : Epic 12) |
| FR33 | Liens user↔UAI | Epic 11 / Story 11.1 | ✓ Couvert |
| FR34 | Itinérants attributs | Epic 11 / Story 11.2 | ✓ Couvert (Phase 2) |
| FR35 | Filtrage UAI irundoo→SER | Epic 11 / Story 11.5 | ✓ Couvert (Phase 2) |
| FR36 | SER reçoit GPEI standalone | Epic 11 / Story 11.3 | ✓ Couvert |
| FR37 | irundoo parse+dispatch GPEI | Epic 11 / Story 11.3 | ✓ Couvert |
| FR38 | ⚠️ **Divergence** — PRD: "SER sync AD via LdapRecord" ; epics: "Infrastructure réception users depuis controlHub Phase 2" | Epic 11 / Story 11.4 | ⚠️ Désaligné |
| FR39 | Apps autorisées standalone/controlHub | Epic 10 / Story 10.1 | ✓ Couvert |

### Coverage Statistics

- **Total PRD FRs :** 35 (numérotation FR1–FR29 + FR33–FR39 ; trou FR30–FR32 intentionnel ou vestige)
- **FRs couverts par les epics :** 35/35 (incluant 2 FRs additionnelles FR3b + FR8b ajoutées dans le doc epics)
- **FR avec divergence sémantique :** 1 (FR38)
- **FR avec investigation préalable :** 1 (FR21 DNS)
- **Coverage : 100 %** (avec 1 FR désaligné et 1 à investiguer)

### Missing / Partiellement Couvertes

**Aucun FR non couvert**, mais points d'attention :

**FR38 — ⚠️ Divergence PRD ↔ Epics (CRITIQUE pour alignement)**
- **PRD dit :** "SER synchronise les données utilisateurs et machines avec l'AD local via LdapRecord"
- **Epics dit :** "Préparation infrastructure pour réception users depuis controlHub (Phase 2 Keycloak) — en MVP l'AD central se synchronise lui-même"
- **Impact :** Ce sont deux exigences différentes. La première décrit la sync LdapRecord déjà implicite dans toutes les stories SER ; la seconde prépare la phase Keycloak. Soit le PRD doit être mis à jour, soit une FR dédiée "infrastructure réception controlHub" doit être ajoutée au PRD.
- **Recommandation :** Aligner en phase de correction avant Epic 7 (pour ne pas bloquer l'implémentation en cours).

**FR21 — DNS (WARNING)**
- Les epics flaggent explicitement une investigation préalable (DNS intégré Samba AD DC vs serveur séparé ?).
- Pas un blocage Epic 7, mais à traiter avant attaque Epic 8.

### Observation spécifique Epic 7 (contexte requis)

L'Epic 7 a une **dépendance bloquante explicite sur Epic 12** (Matrice Profils × Droits) pour sa Story 7.2 :
- Story 7.2 note : "Avant d'implémenter, définir avec Henri la matrice complète des droits applicatifs SER… **Mini-brainstorm obligatoire.**"
- Epic 12 / Story 12.1 est un epic de **spécification pure** (output : `profiles-rights-matrix.md`).
- **Aucune Story 7.2 ne peut être démarrée tant que la matrice Epic 12 n'est pas figée.**

### Observations supplémentaires

- **FRs ajoutées dans les epics (non présentes dans PRD) :** FR3b, FR8b — devraient être rétro-intégrées dans le PRD pour maintenir la traçabilité.
- **Numérotation trouée FR30–FR32 :** probable vestige d'une itération PRD précédente (les anciennes FRs "Karim supervision" ont été déplacées côté controlHub hors scope SER). Le gap mérite une note dans le PRD, ou renumérotation.

---

## Step 4 — UX Alignment Assessment

### UX Document Status

**NOT FOUND** — aucun document UX explicite dans `_bmad-output/planning-artifacts/`.

### UX est-il impliqué par le projet ?

✅ **OUI** — SER et irundoo sont des applications web user-facing avec des contraintes UX structurantes explicitées ailleurs :

**Dans le PRD :**
- Objectif produit central : *"qu'un responsable de collège comprenne ce qu'il fait sans documentation, que tout soit fluide et rapide"* (Executive Summary)
- Parcours utilisateurs détaillés (Marie P1/P2, Thomas P3, Karim P4, Alex P5) — ces parcours décrivent des flux UI
- Exigences UI implicites : *"formulaires de création utilisateur épurés"*, *"abstraction complète de l'AD"*, *"feedback de succès immédiat"*, *"interface volontairement réduite au périmètre de délégation"*
- Navigateurs cibles : Chrome/Firefox/Edge modernes ; **pas de responsive prioritaire** (postes fixes)

**Dans l'architecture :**
- Stack UI imposée : Livewire 4 SFC + DaisyUI + Tailwind
- Convention vues : `resources/views/pages/` filesystem-based router + atomic design (atoms/molecules/organisms)
- Composant modale réutilisable + trait `WithToasts` (déjà codifiés dans CLAUDE.md projet)

**Dans les epics :**
- Plusieurs stories contiennent des acceptance criteria UX (ex : Story 7.1 *"l'utilisateur délégué ne voit que les groupes… toute tentative d'accès hors périmètre est refusée silencieusement"*)
- Les epics référencent explicitement *"Aucun document UX Design trouvé — cette section ne s'applique pas au projet."* (ligne 166 epics.md) → **décision consciente d'absence de doc UX**

### Alignment Issues

⚠️ **Écart documentaire, pas fonctionnel**

L'absence de document UX formel n'est **pas un blocage** pour l'Epic 7 car :
1. Les parcours utilisateurs du PRD tiennent lieu de spec UX de haut niveau
2. Les conventions UI sont codifiées dans CLAUDE.md (modale, toasts, atomic design, filesystem router)
3. Les acceptance criteria des stories contiennent les détails UI nécessaires

⚠️ **Risques liés à l'absence de UX pour Epic 7 spécifiquement :**
- **Story 7.1** : L'UI de configuration des délégations (qui peut déléguer quoi à qui, sur quel périmètre) n'a pas de mockup — risque d'interprétations divergentes entre dev back et dev front
- **Story 7.2** : L'affichage des sections `@can` dans l'UI (menu, boutons, panels) et le comportement des accès refusés (redirect vs 403) bénéficierait d'une trame UX, même courte

### Warnings

- ⚠️ **Avant démarrage Epic 7 / Story 7.1 :** convenir de l'emplacement UI de l'écran "Délégations" (dans `/app/users/{id}/permissions/` ? section admin dédiée ? onglet de fiche user ?)
- ⚠️ **Avant démarrage Epic 7 / Story 7.2 :** convenir de la stratégie de masquage UI (caché vs grisé-désactivé) pour les sections non autorisées, et du format des pages d'erreur 403

### Recommandation

Dans le cadre de l'Epic 7 spécifiquement, pas besoin de produire un document UX exhaustif. En revanche, **un mini UX blueprint (1-2 pages) décrivant les 2-3 écrans Epic 7** serait utile au moment d'écrire les stories détaillées — intégrable dans l'Epic 12 (matrice) ou en annexe Epic 7.

---

## Step 5 — Epic Quality Review

### Epic Structure Validation

#### A. User Value Focus Check

| Epic | User Value | Verdict |
|------|---|---|
| Epic 1 — Fondations & Observabilité | ❌ "aucune FR produit — prérequis technique" | 🟠 **Technical epic** — justifié pour projet brownfield (migration DB, catchall legacy) mais viole la règle "user value" stricte. Acceptable ici car prérequis bloquant explicite. |
| Epic 1bis — Cloisonnement Legacy | ❌ "aucune FR produit — infrastructure de transition" | 🟠 **Technical epic** — justifié par la stratégie de livraison (les utilisateurs accèdent aux modules legacy via l'UI Laravel). Indirect user value. |
| Epic 2 — Gestion Utilisateurs | ✅ Direct (Marie P1/P2) | ✅ OK |
| Epic 3 — iPXE | ✅ Indirect (admin déploiement OS) | ✅ OK (statut 🔴 not-ready flaggé) |
| Epic 4 — Machines/WorkstationGroups | ✅ Direct (Thomas P3) | ✅ OK (largement livré) |
| Epic 5 — Système de Fichiers | ✅ Direct (partages classe) | ✅ OK |
| Epic 6 — Impression | ✅ Direct | ✅ OK |
| **Epic 7 — Délégations & Permissions** | ✅ Direct (Thomas P3 — périmètre délégué) | ✅ OK |
| Epic 8 — Réseau DHCP/DNS | ✅ Direct | ✅ OK |
| Epic 9 — Déploiement Windows | ✅ Indirect (GPOs, WPKG) | ✅ OK |
| Epic 10 — Intégrations Académiques | ✅ Indirect (apps autorisées) | ✅ OK |
| Epic 11 — Établissements/Itinérants irundoo | ✅ Direct (Marie P2 / Karim P4) | ✅ OK |
| **Epic 12 — Matrice Profils × Droits** | ❌ "epic de conception/spécification — pas d'implémentation code" | 🔴 **Violation** — ce n'est pas un epic au sens strict, c'est un livrable de spec. Devrait être une story de l'Epic 7, ou un "Epic 0 / Design" documenté à part. |
| Epic 13 — Refonte BBB | ✅ Post-prod deferred | ✅ OK (périmètre clair) |

#### B. Epic Independence Validation

**Forward Dependencies détectées :**

🔴 **CRITIQUE — Epic 7 → Epic 12 (forward dependency)**
- Story 7.2 prérequis : *"Avant d'implémenter, définir avec Henri la matrice complète des droits applicatifs SER… Mini-brainstorm obligatoire."*
- Epic 12 / Story 12.1 produit précisément cette matrice.
- **Problème :** Epic 12 a un numéro supérieur à Epic 7 → ordre de lecture impose Epic 7 avant Epic 12, mais l'ordre d'exécution est l'inverse.
- **Remédiation :** Soit renuméroter (Epic 12 → Epic 6bis ou Epic 0), soit fusionner Story 12.1 dans Epic 7 comme "Story 7.0 — Matrice".

🟠 **Majeur — Story 2.6 → Epic 7 (soft forward dependency, déjà résolue)**
- Sprint-status.yaml 2026-04-17 confirme : la dépendance est levée car Spatie est opérationnel. Story 2.6 utilise directement `@can('user.password.init')`.

🟡 **Mineur — Epic 3 → Epic 1 + Epic 4 (backward, OK)**
- Prérequis Epic 1 (AuthGuard, catchall) ✅ et Epic 4 (modèles Workstation) ✅ — pas une forward dep puisque Epic 1 et 4 sont avant.

### Story Quality Assessment

#### A. Story Sizing Validation — Epic 7

**Périmètre réel (confirmé par `backlog.html` + `sprint-status.yaml`) : 3 stories.**

| Source | Stories Epic 7 |
|---|---|
| epics.md | 7.1, 7.2 |
| sprint-status.yaml | 7-1, 7-2, **7-3** |
| backlog.html | 7-1, 7-2, **7-3** |
| Sprint change proposal 2026-04-17 | 7-1, 7-2, 7-3 |

🔴 **Désynchronisation limitée à epics.md :** Story 7.3 "Migration bitmask → Spatie prod" existe dans `sprint-status.yaml` ET `backlog.html` (donc deux sources d'autorité alignées) mais **n'est pas écrite dans `epics.md`**. C'est `epics.md` qui est en retard, pas le backlog.

**Tasks détaillées (déjà formalisées dans backlog.html, PAS des stories supplémentaires) :**

- **Story 7.1** (in-progress) — tasks backlog : améliorations UI `/rights-management`, audit trail explicite délégations, 3 scénarios PROD (délégué test, héritage/révoc, délégations négatives)
- **Story 7.2** (in-progress) — tasks backlog : Policies manquantes (Delegation/Machine/Printer/Share/Dhcp), middleware `sambaedu.can:permission`, cache permissions Spatie + invalidation, tests perfs PROD
- **Story 7.3** (backlog) — tasks : script migration one-shot bitmask→Spatie, backup, dry-run, rollback, Observer Role/Permission écrit bitmask AD, tests parité, 4 checkpoints PROD

**Contenu réel d'Epic 7 (constaté code + sprint-status + backlog) :**
- Socle Spatie déjà livré : `spatie/laravel-permission` v6.24, `SambaPermission` (19 permissions), `SambaRole` (9 rôles), `PermissionService` complet, `Delegation` model, 4 Policies (User/Group/WorkstationGroup/Shortcut), `@can` dans 13+ vues Blade, page `/rights-management` (513 L)
- Reste à livrer (dans le cadre des 3 stories existantes, pas des stories en plus) : Policies manquantes, middleware scope, migration bitmask→Spatie, tests prod, audit trail, améliorations UI

**Seule action de remédiation :** rapatrier Story 7.3 dans `epics.md` avec ACs Given/When/Then. Pas besoin d'inventer 7.4/7.5/7.6.

#### B. Acceptance Criteria Review — Epic 7

**Story 7.1 :** ACs en Given/When/Then — 3 scénarios (attribution, vue filtrée, révocation)
- 🟡 **Léger** : manque les cas limites (attribution conflictuelle, user déjà délégué, périmètre retiré alors que l'user est actif)
- 🟡 **Léger** : pas d'AC sur l'audit trail (qui a délégué quoi à qui, quand)

**Story 7.2 :** ACs en Given/When/Then — 3 scénarios (union droits, accès direct URL, modification live)
- 🟠 **Majeur** : le "prérequis mini-brainstorm obligatoire" n'est pas un AC — c'est un blocage externe non exploitable par un dev
- 🟡 **Léger** : pas d'AC sur les messages d'erreur 403 (formatage, rediretion destination), ni sur les performances (@can() en vue — cache ?)

### Dependency Analysis

#### Forward Dependencies Summary

| Dépendance | Type | Sévérité | Statut |
|---|---|---|---|
| Epic 7 / Story 7.2 → Epic 12 / Story 12.1 | Forward | 🔴 Critique | Non résolu (blocage implémentation) |
| Story 10.2 controlHub → Epic 7 socle | Soft forward | 🟠 Majeur | Non résolu (Story 10.2 mentionnée dans sprint-status mais pas dans epics.md) |
| Story 2.6 → Epic 7 | Soft forward | 🟡 Résolu | OK (Spatie opérationnel) |

#### Database/Entity Creation Timing

✅ **Conforme** : chaque story Epic 7 utilise les tables Spatie standards (`roles`, `permissions`, `model_has_roles`…) créées par la migration `create_permission_tables` (déjà exécutée 2026-02-06).

### Best Practices Compliance Checklist — Epic 7

- [x] Epic délivre de la valeur utilisateur (délégation = Thomas P3 direct)
- [ ] Epic peut fonctionner indépendamment — ❌ dépend Epic 12
- [~] Stories correctement dimensionnées — 3 stories (7.1/7.2/7.3) dans backlog ; `epics.md` en retard (manque 7.3)
- [ ] Pas de forward dependencies — ❌ Epic 12
- [x] Tables DB créées au bon moment (Spatie natif) ✅
- [~] Acceptance criteria clairs — partiellement (cas limites manquants)
- [ ] Traçabilité FR maintenue — ❌ FR3b présent dans epics mais absent PRD ; Story 7.3 absente epics mais présente sprint-status

### Quality Findings — Global

#### 🔴 Critical Violations

1. **Epic 12 est un epic de spec pure, pas un epic de valeur utilisateur** — devrait être absorbé dans Epic 7 (tâche de 7.1 ou 7.2) ou traité comme livrable de spec léger. **Note validée avec Henri 2026-04-22 :** la matrice est une simple table d'associations profils ↔ droits, reconstructible en grande partie depuis les bitmasks legacy (`sambaedu/includes/*`, `SambaPermission::legacyRight()`). Pas besoin d'un epic dédié ni d'un brainstorm de 2h — un spike d'audit legacy + validation rapide suffit.
2. **Désynchronisation epics.md ↔ backlog/sprint-status** : Story 7.3 présente dans `backlog.html` + `sprint-status.yaml` (alignés entre eux) mais absente de `epics.md`. C'est `epics.md` qui est en retard.

#### 🟠 Major Issues

3. **FR38 divergence PRD ↔ epics** (déjà noté step 3) — traçabilité perdue.
4. **FR3b et FR8b présentes dans epics mais absentes PRD** — rétro-intégration nécessaire.
5. **Le prérequis "mini-brainstorm obligatoire" de Story 7.2** était sur-dimensionné — reformulable en "audit legacy + validation matrice avec Henri (1-2h)".

#### 🟡 Minor Concerns

8. **Story 2.5 absente** de epics.md (gap 2.4 → 2.6) — le fichier `implementation-artifacts/2-5-changement-role-fonction-deplacement-dn.md` existe mais n'a pas son équivalent dans epics.md.
9. **Numérotation FR30–FR32 manquante** dans PRD (gap non commenté).
10. **Acceptance criteria Epic 7** manquent systématiquement les cas d'audit trail et les cas limites d'erreurs.
11. **UX blueprint Epic 7** absent (noté step 4) — risque d'interprétation divergente front/back.

### Recommandations Remédiation — Spécial Epic 7

**Avant de reprendre le dev Epic 7 :**

1. **(CRITIQUE)** Spike audit legacy → brouillon de matrice Profils × Droits reconstruit depuis les bitmasks `rights` et `SambaPermission::legacyRight()`. Validation rapide avec Henri (1-2h au lieu d'un brainstorm complet).
2. **(CRITIQUE)** Rapatrier Story 7.3 "Migration bitmask → Spatie" dans `epics.md` avec ACs Given/When/Then (les tasks sont déjà formalisées dans `backlog.html`).
3. **(MAJEUR)** Aligner PRD : ajouter FR3b, FR8b ; clarifier FR38.
4. **(MINEUR)** Produire mini UX blueprint des 2-3 écrans clés d'Epic 7 (écran admin délégations, vue filtrée délégué, page 403).
5. **(MINEUR)** Compléter ACs Story 7.1 et 7.2 avec cas limites et audit trail.

---

## Summary and Recommendations

### Overall Readiness Status

🟡 **NEARLY READY** — spécifiquement pour **reprendre l'Epic 7**

Le projet global est mûr (socle Spatie largement livré, 100 % couverture FR, 3 épics déjà quasi-done). La reprise d'Epic 7 n'est pas réellement bloquée — le socle technique est là et les tasks restantes sont explicites dans `backlog.html`. Il faut juste :
- Un spike de reconstruction de la matrice depuis le legacy (~1-2h)
- Une synchro `epics.md` (rapatrier Story 7.3)
- Un mini ajustement PRD (FR3b, FR8b, FR38)

Pas de blocage dur : le dev peut reprendre rapidement.

### Etat des lieux Epic 7 (constat fondé sur sprint-status.yaml, code, sprint change proposals)

**✅ Déjà livré :**
- `spatie/laravel-permission` v6.24 installé, migrations exécutées
- 19 permissions (`SambaPermission` enum) + 9 rôles (`SambaRole` enum)
- Mapping bidirectionnel `SambaPermission ↔ LegacyRight` (bitmask)
- `PermissionService` complet (syncFromAd, syncToAd, grantDelegation, revokeDelegation, canOnWorkstationGroup)
- Model `Delegation` + relations
- 4 Policies câblées : User, Group, WorkstationGroup, Shortcut
- `@can` dans 13+ vues Blade
- Page `/rights-management` Livewire (513 L)

**❌ Reste à faire (estimation raisonnable) :**
- **Matrice Profils × Droits Applicatifs** (Story 12.1 / Story 7.0) — SPEC, prérequis brainstorm Henri/PM
- Policies manquantes : `Delegation`, `Machine`, `Printer`, `Share`, `Dhcp`
- Middleware de scope par périmètre (filtrage vues / actions)
- **Story 7.3 — Migration bitmask → Spatie (prod)** : migration one-shot + Observer qui réécrit le bitmask AD à chaque changement Spatie (stratégie A actée 2026-04-17)
- Audit trail des changements de délégation (NFR8 RGPD)
- Tests prod + amélioration UI `/rights-management`
- Mini UX blueprint 2-3 écrans
- (éventuellement) Story 10.2 — Profils par Défaut + Rôles imposés controlHub

### Critical Issues Requiring Immediate Action

🟠 **Point #1 — Matrice Profils × Droits à reconstruire (approche allégée validée Henri 2026-04-22)**
- Position initiale : brainstorm complet obligatoire. **Revu :** la matrice est une simple table d'associations reconstructible depuis le legacy.
- Action : spike d'1-2h pour :
  - Parser les bitmasks `rights` dans `sambaedu/includes/*` + profils PHP legacy
  - Exploiter le mapping déjà existant `SambaPermission::legacyRight()`
  - Produire un brouillon `profiles-rights-matrix.md` versionnable
  - Validation rapide avec Henri (cas modernes : délégations scopées, itinérants)
- La matrice peut ensuite **évoluer en parallèle du dev** via simple édition du seeder + réassignations Spatie.

🟠 **Point #2 — epics.md en retard sur le backlog**
- `backlog.html` et `sprint-status.yaml` sont alignés (3 stories : 7-1, 7-2, 7-3) mais `epics.md` n'a que 7.1 et 7.2.
- Action : rapatrier Story 7.3 dans `epics.md` avec ACs Given/When/Then. Pas besoin de stories supplémentaires — les tasks restantes (Policies manquantes, middleware, audit trail, cache) sont déjà formalisées dans `backlog.html` à l'intérieur des 3 stories existantes.

🟠 **Point #3 — PRD désaligné (mineur)**
- FR38 divergence sémantique entre PRD et epics.
- FR3b et FR8b ajoutées en epics, non rétro-intégrées dans PRD.
- Action : passer par `bmad-edit-prd` quand pratique. Pas bloquant pour le dev.

### Recommended Next Steps

**Ordre recommandé (pour reprendre Epic 7) :**

1. **[AUJOURD'HUI — spike 1-2h]** Audit legacy + mapping `SambaPermission::legacyRight()` → brouillon `profiles-rights-matrix.md`. Validation rapide avec Henri (pas un brainstorm complet).
2. **[+30 min]** Rapatrier Story 7.3 dans `epics.md` (copier depuis `backlog.html` + formaliser ACs Given/When/Then).
3. **[ensuite]** Reprendre le dev selon l'ordre des stories in-progress : 7.1 (UI `/rights-management` + audit trail + tests PROD) puis 7.2 (Policies manquantes + middleware + cache).
4. **[avant prod]** Story 7.3 (migration bitmask → Spatie) avec dry-run + snapshot Proxmox.
5. **[parallèle, pas bloquant]** Aligner PRD (FR3b, FR8b, FR38) via `bmad-edit-prd`.
6. **[parallèle, pas bloquant]** Mini UX blueprint 2-3 écrans Epic 7 (admin délégations, vue délégué, 403).

### Final Note

Cette évaluation a identifié **8 issues résiduelles** (après correction de l'analyse initiale suite retour Henri 2026-04-22) :
- **2 critiques revues en majeures** (matrice reconstructible depuis legacy, désync epics.md à rattraper)
- **3 majeures** (PRD désaligné FR3b/FR8b/FR38, divergences traçabilité)
- **3 mineures** (story 2.5 absente doc, gap numérotation FR30-32, ACs incomplets / UX blueprint)

Le socle technique d'Epic 7 est **solide** (Spatie livré, 4 Policies câblées, 19 permissions, 9 rôles, UI `/rights-management` fonctionnelle). Aucun blocage dur. Un spike de 1-2h pour la matrice + 30 min pour synchroniser `epics.md` suffisent à débloquer la reprise complète.

---

**Assessment produit par :** John (PM) — bmad-check-implementation-readiness
**Date :** 2026-04-22
**Sources consultées :** prd.md, architecture.md, epics.md, idempotency.md, sprint-change-proposal-2026-04-17.md, implementation-artifacts/sprint-status.yaml
