---
date: 2026-03-19
project: codebase
stepsCompleted:
  - step-01-document-discovery
  - step-02-prd-analysis
  - step-03-epic-coverage-validation
  - step-04-ux-alignment
  - step-05-epic-quality-review
  - step-06-final-assessment
documentsSelected:
  prd: _bmad-output/planning-artifacts/prd.md
  architecture: _bmad-output/planning-artifacts/architecture.md
  epics: _bmad-output/planning-artifacts/epics.md
  ux: null
---

# Implementation Readiness Assessment Report

**Date:** 2026-03-19
**Project:** codebase

## Document Inventory

| Type | Fichier | Taille | Date |
|------|---------|--------|------|
| PRD | prd.md | 25 534 octets | 17 mars 2026 |
| Architecture | architecture.md | 23 950 octets | 18 mars 2026 |
| Epics & Stories | epics.md | 65 541 octets | 19 mars 2026 |
| UX Design | _(non trouvé)_ | — | — |

**Remarques :** Aucun doublon détecté. Document UX absent (évaluation partielle sur ce point).

---

## Analyse PRD

### Exigences Fonctionnelles (39 total)

**Gestion des Utilisateurs (SER)**
- FR1 : Le responsable peut créer un compte utilisateur (élève/enseignant) sans exposer de concepts AD/LDAP
- FR2 : Provisionnement automatique du home directory et droits applicatifs à la création (ou première connexion si home manquant)
- FR3 : Modification des attributs d'un utilisateur (classe, quota, profil applicatif)
- FR4 : Désactivation et suppression avec archivage home directory en deux temps (corbeille → suppression permanente)
- FR5 : Affichage du statut itinérant et application automatique des droits différenciés
- FR6 : Import d'utilisateurs depuis fichier externe (GPEI standalone)

**Gestion des Machines & Parcs (SER)**
- FR7 : Consultation de l'inventaire des machines par salle et par parc
- FR8 : Actions unitaires sur machine (WOL, extinction, reboot)
- FR9 : Actions batch sur parc entier (allumage, extinction, reboot)
- FR10 : Programmation d'actions cron sur parc (allumage/extinction planifiés)
- FR11 : Import de machines et sites depuis CSV
- FR12 : Association d'un AppProfile à des postes individuels et groupes de salles, indépendamment de la hiérarchie OU

**Système de Fichiers (SER)**
- FR13 : Création et gestion des home directories avec quotas XFS
- FR14 : Création et configuration de répertoires de partage par classe avec ACLs POSIX héritées
- FR15 : Gestion des droits d'accès sur partages de classe (lecture, écriture, dossier échange)
- FR16 : Suppression des home directories en deux étapes (archivage /home/trash/ puis suppression permanente optionnelle)

**Impression (SER)**
- FR17 : Consultation de la liste des imprimantes et leurs détails
- FR18 : Ajout, configuration et suppression d'imprimantes via CUPS
- FR19 : Gestion des pilotes Windows associés aux imprimantes

**Réseau (SER)**
- FR20 : Consultation et gestion des réservations DHCP et baux actifs
- FR21 : Configuration des entrées DNS
- FR22 : Import en masse de réservations DHCP

**Déploiement Windows (SER)**
- FR23 : Consultation et gestion des GPOs via interface SER (Services/Legacy/)
- FR24 : Gestion des packages WPKG (définition, association aux profils, déclenchement au démarrage)
- FR25 : Consultation des logs WPKG et rapports d'installation
- FR26 : Gestion des scripts de démarrage Windows

**Délégations & Permissions (SER)**
- FR27 : Attribution de droits délégués à un utilisateur sur un périmètre limité (salle, parc)
- FR28 : Un utilisateur délégué ne voit et agit que sur son périmètre
- FR29 : Droits applicatifs calculés comme union droits de groupe + droits individuels (Spatie)

**Supervision Multi-Instances (irundoo)**
- FR30 : Consultation de l'état de toutes les instances SER de la flotte
- FR31 : Navigation vers instance SER sans re-saisie identifiants (SSO — architecture préparée)
- FR32 : Actions en masse sur plusieurs instances SER (MassActions — post-MVP)

**Gestion des Établissements & Itinérants (irundoo)**
- FR33 : Maintien des liens utilisateur↔établissement (UAI) par instance SER
- FR34 : Gestion des itinérants avec attributs spécifiques (quota réduit, droits limités) par lien user↔UAI
- FR35 : Filtrage et transmission à chaque instance SER uniquement des utilisateurs relevant de son UAI

**Imports & Intégrations Académiques (irundoo + SER)**
- FR36 : SER traite un fichier GPEI en mode standalone
- FR37 : irundoo parse un fichier GPEI et dispatche vers instances SER concernées selon UAI
- FR38 : SER synchronise données utilisateurs et machines avec AD local via LdapRecord
- FR39 : SER gère les apps autorisées en standalone ; quand irundoo est présent, irundoo définit les apps

---

### Exigences Non-Fonctionnelles (18 total)

**Performance**
- NFR1 : Opérations courantes (liste users, machines, état parc) < 2 secondes sur réseau local
- NFR2 : Actions longues (WOL, scripts, cron) donnent retour immédiat + feedback sans bloquer l'interface
- NFR3 : Aucune requête LDAP non indexée ou scan complet de l'annuaire AD

**Sécurité**
- NFR4 : Mots de passe jamais en clair dans l'application ni dans les sessions
- NFR5 : Accès CAS/SSO sécurisé via SSL/TLS
- NFR6 : Accès administrateur sans bypass non authentifié
- NFR7 : Données personnelles accessibles uniquement aux rôles autorisés (principe moindre privilège, Spatie)
- NFR8 : Logs d'actions sensibles conservés et horodatés (création/suppression user, modification droits, accès home dir)

**Fiabilité & Résilience**
- NFR9 : SER fonctionne intégralement sans connectivité internet
- NFR10 : Rollback complet en < 5 minutes via snapshot Proxmox
- NFR11 : Perte de connexion irundoo n'affecte pas SER (standalone by design)
- NFR12 : Migrations de données idempotentes

**Maintenabilité**
- NFR13 : Code entièrement typé (PHP typed properties, return types, DTOs)
- NFR14 : Chaque méthode Services/Legacy/ porte un commentaire indiquant source legacy + raison du refactoring
- NFR15 : Chaque fonctionnalité livrée accompagnée de tests automatisés avant bêta
- NFR16 : Développeur externe peut installer l'environnement en suivant uniquement la documentation du repo

**Intégration**
- NFR17 : Synchronisation LDAP/AD sur structure OU standardisée — déviation détectée et signalée explicitement
- NFR18 : Intégrations système (CUPS, DHCP, scripts sudo) encapsulées dans Services dédiés — aucun appel direct depuis SFC Livewire

---

### Exigences Additionnelles & Contraintes

- **RGPD :** gestion durées de conservation + suppression effective des données (élèves/enseignants)
- **Mineurs :** logs et données d'accès traités avec précautions appropriées
- **Local-first :** toutes les fonctions MVP opèrent sans dépendance réseau externe
- **Infrastructure hétérogène :** SER ne peut pas supposer de standardisation matérielle inter-établissements
- **Mises à jour :** manuelles par l'équipe pendant MVP
- **Navigateurs :** modernes uniquement (Chrome, Firefox, Edge récents) — pas de support IE
- **⚠️ Zone non résolue :** Sessions cloud Windows — mécanisme legacy non encore analysé, à investiguer avant finalisation MVP

---

### Évaluation de Complétude du PRD

Le PRD est **bien structuré et complet** pour un document de phase MVP. Les exigences sont numérotées, les parcours utilisateurs sont documentés, les phases sont claires. Un seul point reste ouvert : les **sessions cloud Windows** (périmètre non encore analysé — risque identifié et tracé). Le périmètre post-MVP est clairement délimité.

---

## Validation de la Couverture Épics

### Matrice de Couverture FR

| FR | Exigence PRD (résumé) | Épic (couverture map) | Story | Statut |
|----|----------------------|-----------------------|-------|--------|
| FR1 | Création utilisateur sans jargon AD | Epic 2 | Story 2.1 | ✅ Couvert |
| FR2 | Provisioning home dir + droits ACL automatique | Epic 2 | Story 2.1 | ✅ Couvert |
| FR3 | Modification attributs utilisateur (classe, quota) | Epic 2 | Story 2.2 | ✅ Couvert |
| FR4 | Désactivation/suppression + archivage home dir | Epic 2 | Story 2.3 | ✅ Couvert |
| FR5 | Statut itinérant + droits différenciés automatiques | Epic 2 | Story 2.4 | ✅ Couvert |
| FR6 | Import utilisateurs GPEI standalone SER | Epic 10 | **AUCUNE STORY** | ⚠️ Décision à confirmer |
| FR7 | Inventaire machines par groupe physique / workstationGroup | Epic 3 | Story 3.1 | ✅ Couvert |
| FR8 | Actions unitaires machine (WOL, extinction, reboot) | Epic 3 | Story 3.2 | ✅ Couvert |
| FR9 | Actions batch sur workstationGroup | Epic 3 | Story 3.3 | ✅ Couvert |
| FR10 | Crons planifiés sur workstationGroup | Epic 3 | Story 3.4 | ✅ Couvert |
| FR11 | Import machines + groupes physiques depuis CSV | Epic 3 | Story 3.5 | ✅ Couvert |
| FR12 | Association AppProfile à postes et workstationGroups | Epic 3 | Story 3.6 | ✅ Couvert |
| FR13 | Home directories avec quotas XFS | Epic 4 | Story 4.1 | ✅ Couvert |
| FR14 | Partages de classe avec ACLs POSIX héritées | Epic 4 | Story 4.2 | ✅ Couvert |
| FR15 | Droits d'accès partages (lecture, écriture, échange) | Epic 4 | Story 4.2 | ✅ Couvert |
| FR16 | Suppression home dirs en deux étapes | Epic 4 | Story 4.1 | ✅ Couvert |
| FR17 | Liste imprimantes et détails | Epic 5 | Story 5.1 | ✅ Couvert |
| FR18 | Ajout/configuration/suppression imprimantes CUPS | Epic 5 | Story 5.1 | ✅ Couvert |
| FR19 | Gestion pilotes Windows imprimantes | Epic 5 | Story 5.2 | ✅ Couvert |
| FR20 | Réservations DHCP + baux actifs | Epic 7 | Story 7.1 | ✅ Couvert |
| FR21 | Configuration entrées DNS | Epic 7 | **STORY CONDITIONNELLE** | ⚠️ Investigation requise |
| FR22 | Import réservations DHCP en masse | Epic 7 | Story 7.1 | ✅ Couvert |
| FR23 | Gestion GPOs (Services/Legacy/) | Epic 8 | Story 8.1 | ✅ Couvert |
| FR24 | Gestion packages WPKG + association profils | Epic 8 | Story 8.2 | ✅ Couvert |
| FR25 | Logs WPKG + rapports installation | Epic 8 | Story 8.4 | ✅ Couvert |
| FR26 | Scripts de démarrage Windows | Epic 8 | Story 8.3 | ✅ Couvert |
| FR27 | Attribution droits délégués sur périmètre | Epic 6 | Story 6.1 | ✅ Couvert |
| FR28 | Vue filtrée au périmètre de délégation | Epic 6 | Story 6.1 | ✅ Couvert |
| FR29 | Calcul droits Spatie (union groupe + individuel) | Epic 6 | Story 6.2 | ✅ Couvert |
| FR30 | Vue état flotte instances SER | Epic 9 | Story 9.1 | ✅ Couvert |
| FR31 | Navigation inter-instances sans re-login (SSO préparé) | Epic 9 | Story 9.2 | ✅ Couvert |
| FR32 | Actions en masse sur instances SER (MassActions) | Epic 9 | Story 9.3 | ✅ Couvert |
| FR33 | Liens user↔UAI par instance SER | Epic 11 | Story 11.1 | ✅ Couvert |
| FR34 | Itinérants avec attributs spécifiques par UAI | Epic 11 | Story 11.2 | ✅ Couvert |
| FR35 | Filtrage transmission par UAI vers chaque SER | Epic 11 | Story 11.5 | ✅ Couvert (Phase 2) |
| FR36 | SER reçoit et traite un fichier GPEI standalone | Epic 11 (map) | **AUCUNE STORY SER** | ❌ GAP |
| FR37 | irundoo parse GPEI et dispatche par UAI | Epic 11 | Story 11.3 | ✅ Couvert |
| FR38 | Infrastructure réception users depuis controlHub | Epic 11 | Story 11.4 | ✅ Couvert |
| FR39 | Apps autorisées standalone vs controlHub | Epic 10 | Story 10.1 | ✅ Couvert |

---

### Exigences Manquantes

#### ❌ GAP CRITIQUE — FR36 : SER standalone GPEI (côté SER)

**Exigence PRD :** "SER peut recevoir et traiter un fichier GPEI en mode standalone — MVP : écriture dans l'AD local (qui sync avec l'AD central)"

**Situation dans les épics :** La Coverage Map place FR36 en Epic 11, mais Story 11.3 couvre uniquement **irundoo** (parsing + dispatch). Il n'existe **aucune story côté SER** pour recevoir et traiter un fichier GPEI localement.

**Impact :** SER ne peut pas fonctionner en mode standalone pour l'import GPEI — contradictoire avec le principe "SER standalone by design".

**Recommandation :** Ajouter une Story 10.2 ou Story 11.x dédiée à "SER reçoit et traite un fichier GPEI en standalone (upload direct, écriture AD local)" OU confirmer explicitement que ce cas d'usage est abandonné au profit du passage systématique par irundoo.

---

#### ⚠️ RISQUE — FR6 : Import utilisateurs GPEI (redirection non résolue)

**Exigence PRD :** "Le responsable peut importer des utilisateurs depuis un fichier externe (GPEI standalone)"

**Situation dans les épics :** La Coverage Map indique "déplacé vers intégrations académiques — l'import GPEI sera géré par irundoo, pas en standalone SER". Aucune story n'existe en Epic 10 pour cette exigence. Cette décision modifie le scope du PRD sans qu'il y ait de story correspondante.

**Impact :** FR6 (SER standalone) et FR36 adressent en substance le même besoin — le traitement GPEI côté SER. Si la décision est de tout passer par irundoo, **FR6 doit être explicitement retiré du scope SER** dans le PRD et les épics, sinon c'est un gap non couvert.

**Recommandation :** Trancher : (a) supprimer FR6 du scope SER et documenter la décision, ou (b) créer une story pour le cas standalone.

---

#### ⚠️ EN ATTENTE — FR21 : Configuration DNS

**Exigence PRD :** "Le responsable peut configurer les entrées DNS"

**Situation dans les épics :** Flaggé "⚠️ À INVESTIGUER : nature exacte de la gestion DNS (intégrée AD ou serveur séparé ?)". Aucune story dédiée à ce stade — conditionnelle aux trouvailles legacy.

**Impact :** FR21 peut soit se fondre dans Story 7.1 (DHCP), soit générer une story dédiée. Le risque est de commencer Epic 7 sans avoir tranché, créant une dette de planification.

**Recommandation :** Investiguer le DNS legacy avant de démarrer Epic 7 et créer la story si nécessaire.

---

### Incohérence Détectée — Attribution FR36/FR37/FR38

| Source | Epic 10 couvre | Epic 11 couvre |
|--------|---------------|---------------|
| **En-têtes des épics** | FR36, FR37, FR38, FR39 | FR33, FR34, FR35 |
| **Coverage Map** | FR39 uniquement | FR33, FR34, FR35, FR36, FR37, FR38 |
| **Stories** | Story 10.1 (FR39) | Stories 11.1-11.5 (FR33-FR38) |

**Les stories et la Coverage Map sont cohérentes entre elles** — seuls les en-têtes d'Epic 10 et Epic 11 sont incorrects. Les en-têtes doivent être mis à jour : Epic 10 → FRs couverts : FR39 / Epic 11 → FRs couverts : FR33, FR34, FR35, FR36, FR37, FR38.

---

### Nouvelles Exigences Ajoutées dans les Épics (hors PRD)

| FR | Description | Épic | Story |
|----|-------------|------|-------|
| FR3b | Droits applicatifs SER + délégation partages (Spatie) | Epic 6 | Story 6.x (non créée) |
| FR8b | Feedback progression/readiness après allumage machine | Epic 3 | Story 3.2 |
| FR32b | Organisation fichiers échanges SER↔controlHub | Epic 9 | Différé |

FR3b n'a pas de story dédiée en Epic 6 — la Story 6.1 couvre les droits délégués sur périmètre (FR27-28), la Story 6.2 couvre Spatie global (FR29), mais les droits de délégation sur partages de fichiers (FR3b) ne semblent pas explicitement traités.

---

### Statistiques de Couverture

- **Total FRs PRD :** 39
- **FRs avec story clairement couverte :** 36
- **FRs avec gap ou investigation en attente :** 3 (FR6, FR21, FR36)
- **Taux de couverture :** 92 % (36/39)
- **Nouvelles FRs ajoutées dans épics (hors PRD) :** 3 (FR3b, FR8b, FR32b)

---

## Évaluation UX Alignment

### Statut du Document UX

**Non trouvé** — aucun document UX dans `_bmad-output/planning-artifacts/`.

### UX est-il implicite dans ce projet ?

**Oui, fortement.** SER est une application web (MPA Livewire) avec des utilisateurs non-techniques en cœur de cible : responsables de collège sans formation technique. Le PRD cite explicitement :
- Prise en main sans formation en quelques minutes (critère de succès)
- Actions compréhensibles sans documentation (libellés, flux, retours d'état auto-explicites)
- Abstraction complète de l'AD (aucun jargon technique exposé)
- Vues filtrées par périmètre de délégation (Thomas P3)
- Feedback immédiat sur toutes les actions longues (NFR2)
- Formulaires épurés, statuts explicites, toasts de confirmation (WithToasts référencés dans les stories)

L'architecture définit des conventions UI : atomic design (atoms/molecules/organisms), `resources/views/pages/` arborescente, DaisyUI comme framework CSS.

### Avertissements

**ℹ️ INFO :** L'absence de document UX dédié est **acceptable dans ce contexte** pour les raisons suivantes :
- Projet brownfield avec référentiel visuel existant (legacy SambaEdu)
- Application interne (pas de contraintes SEO ou marketing)
- Conventions UI déjà définies dans l'Architecture (atomic design, DaisyUI, patterns Livewire)
- Les stories contiennent des ACs précis sur le comportement UI (WithToasts, feedback, filtrage)
- Équipe réduite (bi-développeur) — un doc UX formel représenterait une charge disproportionnée

**⚠️ AVERTISSEMENT :** Les points UX suivants du PRD ne sont pas couverts dans les stories épics :
1. **Convention de libellés et terminologie** — le PRD insiste sur l'absence de jargon AD ; aucune story ne formalise le glossaire ou les conventions de nommage UI
2. **Accessibilité / responsive** — le PRD dit "responsive non prioritaire" mais aucune contrainte minimale n'est définie
3. **Design system** — DaisyUI est cité mais le niveau de standardisation des composants n'est pas documenté (risque d'incohérence visuelle entre epics développés en parallèle)

---

## Revue de Qualité des Épics

### Validation : Valeur Utilisateur par Épic

| Épic | Valeur Utilisateur | Verdict |
|------|-------------------|---------|
| Epic 1 — Fondations & Observabilité | Aucune valeur directe (prérequis technique) | ⚠️ Épic technique |
| Epic 2 — Gestion des Utilisateurs SER | ✅ Marie P1/P2 — création et gestion comptes | ✅ |
| Epic 3 — Gestion Machines & Parcs | ✅ Thomas P3 — pilotage postes et salles | ✅ |
| Epic 4 — Système de Fichiers | ✅ Home dirs, partages, quotas | ✅ |
| Epic 5 — Impression | ✅ Gestion imprimantes et pilotes | ✅ |
| Epic 6 — Délégations & Permissions | ✅ Thomas P3 — délégation périmètre | ✅ |
| Epic 7 — Réseau DHCP/DNS | ✅ Gestion réservations et baux | ✅ |
| Epic 8 — Déploiement Windows | ✅ GPOs, WPKG, scripts démarrage | ✅ |
| Epic 9 — Supervision Flotte | ✅ Karim P4 — supervision multi-instances | ✅ |
| Epic 10 — Intégrations Académiques | ⚠️ Intitulé trompeur (voir ci-dessous) | ⚠️ |
| Epic 11 — Établissements & Itinérants | ✅ Gestion UAI, itinérants, GPEI | ✅ |

---

### 🔴 Violations Critiques

#### 🔴 C1 — Epic 1 est un épic purement technique

**Constat :** Epic 1 (Fondations & Observabilité) ne délivre aucune valeur utilisateur directe. Ses 4 stories sont des prérequis techniques (migration BDD, catchall, monitoring, interface auth). Il est explicitement présenté comme "prérequis technique bloquant — aucune FR produit".

**Justification brownfield :** Dans un projet brownfield comme SER, un épic de fondations est souvent inévitable avant de pouvoir livrer de la valeur. L'Architecture exige la migration MySQL→PostgreSQL avant tout Sprint 1. Ce cas est **acceptable contextuellement** mais reste une violation des best practices formelles.

**Recommandation :** Documenter explicitement dans l'Epic 1 qu'il s'agit d'un "Sprint 0" brownfield — ce qui est déjà partiellement fait. S'assurer qu'il soit livré en isolation complète avant de démarrer Epic 2. Risque faible.

---

#### 🔴 C2 — Dépendances croisées en avant dans Epic 2 → Epic 4

**Constat :** Story 2.2 (Modification attributs utilisateur) contient deux références explicites à Epic 4 :
- `"la mise à jour XFS sur le filesystem est prise en charge par Story 4.1"`
- `"les ACLs partages de classe sont recalculées par Story 4.2"`

Ces ACs sont donc **incomplètes sans Epic 4**. Story 2.2 ne peut pas être considérée comme "done" sans les stories de l'Epic 4.

**Impact :** Si Epic 2 est livré avant Epic 4 (ce qui peut arriver en développement parallèle), le responsable de collège verra un comportement incomplet : modification de classe sans recalcul ACL, modification quota sans mise à jour XFS. L'utilisateur ne peut pas détecter que quelque chose manque.

**Recommandation :** Deux options :
1. Retirer ces ACs de Story 2.2 et les inclure uniquement dans Stories 4.1 et 4.2 (les effets de bord filesystem restent dans Epic 4)
2. Ajouter des notes explicites dans l'interface ("quota mis à jour — synchronisation système en cours") pour que le comportement partiel soit visible
L'option 1 est préférable pour la propreté des stories.

---

### 🟠 Problèmes Majeurs

#### 🟠 M1 — Epic 10 : incohérence titre / FRs couverts

**Constat :** L'en-tête d'Epic 10 annonce "FRs couverts : FR36, FR37, FR38, FR39" mais seule Story 10.1 existe (couvrant FR39). Les FRs FR36, FR37, FR38 sont en réalité dans Epic 11 (Stories 11.3, 11.4). Le titre "Intégrations Académiques SER" est également trompeur si l'épic ne couvre plus que FR39.

**Impact :** Confusion dans le suivi de couverture, risque de considérer FR36/37/38 comme couverts par Epic 10 sans le vérifier.

**Recommandation :** Mettre à jour l'en-tête d'Epic 10 → "FRs couverts : FR39" et renommer éventuellement l'épic en "Gestion des Apps Autorisées". Corriger aussi l'en-tête d'Epic 11 → ajouter FR36, FR37, FR38 à la liste.

---

#### 🟠 M2 — FR3b sans story dans Epic 6

**Constat :** FR3b est listé dans la Coverage Map ("Epic 6 — Droits applicatifs SER (délégation partages + Spatie)") mais aucune story en Epic 6 ne le couvre explicitement. Story 6.1 couvre les droits délégués sur périmètre applicatif (FR27-28), Story 6.2 couvre Spatie global (FR29). La délégation sur partages de fichiers (côté Windows/POSIX) n'a pas de story.

**Impact :** FR3b (droits de délégation sur partages Samba, accès sections app) est à cheval entre Epic 4 et Epic 6 — risque de tomber entre les deux.

**Recommandation :** Créer une Story 6.3 couvrant la délégation sur partages de fichiers OU clarifier que ce cas est géré dans Story 4.2 et le documenter.

---

#### 🟠 M3 — FR21 DNS sans story ni investigation planifiée

**Constat :** L'épic 7 note "FR21 — Investigation préalable requise" mais il n'y a ni story de spike/investigation, ni story conditionnelle. Si l'investigation révèle que le DNS nécessite une story dédiée, elle sera ajoutée "in flight" pendant le sprint — ce qui perturbe la planification.

**Recommandation :** Créer une Story 7.0 ou un spike "Investigation DNS legacy" à inclure dans l'Epic 7 avant la Story 7.1. Le résultat du spike valide ou invalide l'ajout d'une Story 7.2 DNS.

---

### 🟡 Préoccupations Mineures

#### 🟡 m1 — Story 3.3 dépend explicitement de Story 3.2

La dépendance intra-épic est documentée : "Dépend de Story 3.2 — les actions unitaires doivent être validées et testées avant." C'est une dépendance séquentielle acceptable et bien communiquée. Aucun risque d'implémentation en parallèle non intentionnel si l'ordre est respecté. ✅ Acceptable.

---

#### 🟡 m2 — Stories 11.4 et 11.5 sont Phase 2 dans les épics MVP

Stories 11.4 et 11.5 sont "codées en avance, activées une fois Keycloak en place". Risque : ces stories pourraient ne jamais passer en "done" formellement pendant le MVP, créant des états ambigus dans le backlog.

**Recommandation :** Marquer ces stories explicitement "Phase 2 — à valider lors du passage Keycloak" dans leur titre ou statut.

---

#### 🟡 m3 — Story 9.2 (SSO) semi-vide côté MVP

Story 9.2 livre essentiellement "une redirection vers la page de login SER" en MVP. La valeur utilisateur est minimale. Si l'effort d'implémentation est faible, c'est acceptable. Si c'est significatif, il faut se poser la question de la priorisation.

---

### Checklist de Conformité par Épic

| Épic | Valeur utilisateur | Indépendant | Stories bien dimensionnées | Pas de dépendances en avant | ACs testables | Traçabilité FR |
|------|:-:|:-:|:-:|:-:|:-:|:-:|
| Epic 1 | ⚠️ | ✅ | ✅ | ✅ | ✅ | N/A |
| Epic 2 | ✅ | ✅ | ✅ | ⚠️ (Story 2.2) | ✅ | ✅ |
| Epic 3 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 4 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 5 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 6 | ✅ | ✅ | ⚠️ (FR3b sans story) | ✅ | ✅ | ⚠️ |
| Epic 7 | ✅ | ✅ | ⚠️ (FR21 sans story) | ✅ | ✅ | ⚠️ |
| Epic 8 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 9 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 10 | ⚠️ | ✅ | ✅ | ✅ | ✅ | ❌ (incohérence) |
| Epic 11 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

### Vérifications Brownfield

| Critère | Statut |
|---------|--------|
| Story de migration / compatibilité legacy | ✅ Epic 1 Stories 1.1, 1.2 |
| Pattern Services/Legacy/ documenté | ✅ NFR14 + notes dans stories Epic 8 |
| Investigation legacy avant chaque feature critique | ✅ Prérequis explicites dans chaque story concernée |
| Pas de starter template (projet existant) | ✅ Confirmé Architecture |
| Rollback / migration idempotente | ✅ NFR10, NFR12, Story 1.1 |

---

## Synthèse et Recommandations

### Statut de Lisibilité Global

**🟡 PRÊT AVEC RÉSERVES**

Le projet SER/irundoo est **bien planifié dans l'ensemble** — PRD complet, architecture cohérente, stories bien structurées avec ACs BDD testables, investigation legacy anticipée, conventions NFR claires. Les gaps identifiés sont circonscrits et actionnables. Aucun problème structurel majeur ne bloque le démarrage de l'implémentation.

---

### Problèmes Critiques à Traiter Avant Implémentation

#### 1. Trancher FR6 / FR36 (SER standalone GPEI)

**Action :** Décider si SER doit ou non traiter un fichier GPEI en mode standalone (sans passer par irundoo). La décision actuelle dans les épics ("géré par irundoo uniquement") contredit le PRD. Deux options :
- **Option A :** Supprimer FR6 et FR36 du scope SER, documenter la décision dans le PRD
- **Option B :** Créer une Story 11.x "SER reçoit et traite un fichier GPEI en standalone" dans Epic 11

**Urgence :** Avant de démarrer Epic 11 ou tout sprint impliquant GPEI.

---

#### 2. Créer un spike DNS avant Epic 7

**Action :** Ajouter une Story 7.0 "Spike — Investigation DNS legacy" dans Epic 7 pour déterminer si FR21 génère une story dédiée ou se fond dans Story 7.1.

**Urgence :** Avant de planifier le sprint incluant Epic 7.

---

#### 3. Corriger les en-têtes d'Epic 10 et Epic 11

**Action :** Mettre à jour les listes "FRs couverts" :
- Epic 10 → FRs couverts : FR39 uniquement
- Epic 11 → FRs couverts : FR33, FR34, FR35, FR36, FR37, FR38

**Urgence :** Correction de traçabilité — à faire dès que possible, effort minimal.

---

### Actions Recommandées (Non Bloquantes)

4. **Résoudre la dépendance croisée Story 2.2 → Epic 4** : Retirer les ACs cross-épics de Story 2.2 et les confier aux Stories 4.1 et 4.2. Clarifier que la modification de quota en Story 2.2 = persistance PostgreSQL uniquement, l'effet XFS étant géré par Epic 4.

5. **Créer une Story 6.3 pour FR3b** : Couvrir la délégation sur partages de fichiers (Samba/POSIX) dans Epic 6, ou documenter explicitement que ce cas est intégré dans Story 4.2.

6. **Marquer Stories 11.4 et 11.5 comme "Phase 2"** dans leur titre pour éviter l'ambiguïté dans le suivi backlog.

---

### Tableau de Synthèse des Constats

| # | Sévérité | Catégorie | Description | Action |
|---|----------|-----------|-------------|--------|
| C1 | 🔴 | Épic technique | Epic 1 sans valeur utilisateur | Acceptable brownfield — documenter clairement |
| C2 | 🔴 | Dépendance en avant | Story 2.2 référence Epic 4 | Restructurer les ACs |
| M1 | 🟠 | Traçabilité | Epic 10 en-tête incorrect (FR36/37/38) | Corriger les en-têtes |
| M2 | 🟠 | Story manquante | FR3b sans story dans Epic 6 | Créer Story 6.3 |
| M3 | 🟠 | Story manquante | FR21 DNS sans story | Ajouter spike investigation |
| G1 | 🔴 | Gap couverture | FR36 SER standalone sans story | Décision FR6/FR36 à trancher |
| G2 | ⚠️ | Décision scope | FR6 redirigé irundoo sans résolution PRD | Aligner PRD et épics |
| m1 | 🟡 | Mineur | Story 3.3 dépend Story 3.2 | Documenté — acceptable |
| m2 | 🟡 | Mineur | Stories 11.4/11.5 Phase 2 non marquées | Renommer avec tag [Phase 2] |
| m3 | 🟡 | Mineur | Story 9.2 valeur MVP minimale | Évaluer effort vs valeur |

**Total : 2 critiques, 4 majeurs, 3 mineurs**

---

### Note Finale

Ce rapport a identifié **10 constats** répartis en 3 niveaux de sévérité. Les gaps FR6/FR36 et la dépendance croisée Story 2.2 → Epic 4 sont les deux points les plus importants à résoudre avant de considérer le backlog comme implémentation-ready. Les corrections d'en-têtes et la story manquante pour FR3b/FR21 représentent un effort faible pour une gain de clarté significatif.

La qualité globale des épics et stories est **bonne à très bonne** : ACs BDD bien formées, prérequis legacy anticipés, architecture 3 couches rigoureusement appliquée, Definition of Done claire. Le projet peut démarrer l'implémentation dès résolution des 3 actions prioritaires.

**Rapport généré le :** 2026-03-19
**Évaluateur :** Claude Code (bmad-check-implementation-readiness)
**Documents évalués :** prd.md (2026-03-17), architecture.md (2026-03-18), epics.md (2026-03-19)
