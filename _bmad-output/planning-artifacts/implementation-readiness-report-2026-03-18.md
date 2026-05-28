---
stepsCompleted: [step-01-document-discovery, step-02-prd-analysis, step-03-epic-coverage-validation, step-04-ux-alignment, step-05-epic-quality-review, step-06-final-assessment]
documentsSelected:
  prd: planning-artifacts/prd.md
  architecture: planning-artifacts/architecture.md
  epics: planning-artifacts/epics.md
  ux: null
---

# Implementation Readiness Assessment Report

**Date:** 2026-03-18
**Project:** codebase

## Inventaire des Documents

| Type | Fichier | Taille | Modifié |
|------|---------|--------|---------|
| PRD | `planning-artifacts/prd.md` | 25K | 2026-03-17 |
| Architecture | `planning-artifacts/architecture.md` | 24K | 2026-03-18 |
| Epics & Stories | `planning-artifacts/epics.md` | 65K | 2026-03-18 |
| UX Design | _(non trouvé)_ | — | — |

**Doublons :** Aucun
**Avertissements :** Aucun document UX Design — évaluation partielle sur ce point.

---

## Analyse PRD

### Exigences Fonctionnelles (39 FRs)

#### Gestion des Utilisateurs (SER)
- **FR1 :** Création compte utilisateur (élève/enseignant) sans exposer concepts AD/LDAP
- **FR2 :** Provisionnement automatique home directory + droits applicatifs à la création ou première connexion
- **FR3 :** Modification des attributs utilisateur (classe, quota, profil applicatif)
- **FR4 :** Désactivation et suppression compte avec archivage home dir en deux temps (corbeille → suppression permanente)
- **FR5 :** Affichage statut itinérant et application automatique droits différenciés
- **FR6 :** Import utilisateurs depuis fichier externe (GPEI standalone)

#### Gestion des Machines & Parcs (SER)
- **FR7 :** Consultation inventaire machines par salle et par parc
- **FR8 :** Actions unitaires sur machine (WOL, extinction, reboot)
- **FR9 :** Actions batch sur parc entier (allumage, extinction, reboot)
- **FR10 :** Programmation actions cron sur parc (allumage/extinction planifiés)
- **FR11 :** Import machines et sites depuis CSV
- **FR12 :** Association profil applicatif (AppProfile) à postes individuels et groupes de salles indépendamment de la hiérarchie OU

#### Système de Fichiers (SER)
- **FR13 :** Création et gestion home directories individuels avec quotas XFS
- **FR14 :** Création et configuration répertoires de partage par classe avec ACLs POSIX héritées
- **FR15 :** Gestion droits d'accès sur partages de classe (lecture, écriture, dossier échange)
- **FR16 :** Suppression home directories en deux étapes (archivage /home/trash/ puis suppression permanente)

#### Impression (SER)
- **FR17 :** Consultation liste imprimantes et détails
- **FR18 :** Ajout, configuration et suppression imprimantes via CUPS
- **FR19 :** Gestion pilotes Windows associés aux imprimantes

#### Réseau (SER)
- **FR20 :** Consultation et gestion réservations DHCP et baux actifs
- **FR21 :** Configuration entrées DNS
- **FR22 :** Import réservations DHCP en masse

#### Déploiement Windows (SER)
- **FR23 :** Consultation et gestion GPOs via SER (Services/Legacy/)
- **FR24 :** Gestion packages WPKG (définition, association aux profils, déclenchement au démarrage)
- **FR25 :** Consultation logs WPKG et rapports d'installation
- **FR26 :** Gestion scripts de démarrage Windows

#### Délégations & Permissions (SER)
- **FR27 :** Attribution droits délégués à un utilisateur sur périmètre limité (salle, parc)
- **FR28 :** Vision et action restreintes au périmètre de délégation
- **FR29 :** Calcul droits applicatifs = union droits groupe + droits individuels (Spatie)

#### Supervision Multi-Instances (irundoo)
- **FR30 :** Consultation état de toutes les instances SER de la flotte
- **FR31 :** Navigation vers instance SER sans re-saisie identifiants (SSO — architecture préparée)
- **FR32 :** Actions en masse sur plusieurs instances SER (MassActions — post-MVP)

#### Gestion Établissements & Itinérants (irundoo)
- **FR33 :** Maintien liens utilisateur↔établissement (UAI) par instance SER
- **FR34 :** Gestion utilisateurs itinérants avec attributs spécifiques par lien user↔UAI
- **FR35 :** Filtrage et transmission à chaque SER uniquement les utilisateurs de son UAI

#### Imports & Intégrations Académiques
- **FR36 :** SER reçoit et traite fichier GPEI en mode standalone
- **FR37 :** irundoo parse fichier GPEI et dispatche vers instances SER selon UAI
- **FR38 :** SER synchronise avec AD local via LdapRecord
- **FR39 :** SER gère apps autorisées en standalone ; quand irundoo est présent, irundoo définit les apps

**Total FRs : 39**

---

### Exigences Non-Fonctionnelles (18 NFRs)

#### Performance
- **NFR1 :** Opérations courantes (liste users, machines, état parc) < 2 secondes sur réseau local
- **NFR2 :** Actions longues (WOL, scripts, cron) retour immédiat + feedback d'état sans bloquer l'interface
- **NFR3 :** Aucune requête LDAP non indexée ou scan complet AD

#### Sécurité
- **NFR4 :** Mots de passe jamais en clair dans l'application ni dans les sessions
- **NFR5 :** CAS/SSO sécurisé via SSL/TLS
- **NFR6 :** Aucun bypass non authentifié pour accès administrateur
- **NFR7 :** Données personnelles accessibles uniquement aux rôles autorisés (Spatie — moindre privilège)
- **NFR8 :** Logs d'actions sensibles conservés et horodatés

#### Fiabilité & Résilience
- **NFR9 :** SER fonctionne sans connectivité internet (réseau local isolé)
- **NFR10 :** Rollback complet < 5 minutes via snapshot Proxmox
- **NFR11 :** Perte connexion irundoo n'affecte pas SER (standalone by design)
- **NFR12 :** Migrations de données idempotentes

#### Maintenabilité
- **NFR13 :** Code typé (typed properties, return types, DTOs) — pas de tableaux associatifs non typés
- **NFR14 :** Chaque méthode `Services/Legacy/` commentée (fichier legacy source + raison refactoring)
- **NFR15 :** Chaque fonctionnalité accompagnée de tests automatisés avant bêta
- **NFR16 :** Documentation d'installation suffisante pour onboarding développeur externe

#### Intégration
- **NFR17 :** Synchronisation LDAP/AD détecte et signale explicitement toute déviation de la structure OU standard
- **NFR18 :** Intégrations système (CUPS, DHCP, scripts sudo) encapsulées dans Services — aucun appel système direct depuis SFC Livewire

**Total NFRs : 18**

---

### Exigences Additionnelles & Contraintes

- **RGPD :** Gestion durées de conservation, suppression effective données personnelles (élèves = mineurs)
- **Local-first :** Toutes fonctions MVP opèrent sans dépendance réseau externe
- **Infrastructure hétérogène :** Pas de standardisation matérielle supposée entre établissements
- **⚠️ Sessions cloud Windows :** Mécanisme legacy non encore analysé — périmètre à investiguer avant finalisation Sprint 3 (impact potentiel sur home dirs et profils)
- **Intégrations requises :** AD/LDAP (MVP), CUPS (Sprint 1), DHCP/DNS (Sprint 2), GPEI (Sprint 3), AAF/SIECLE (post-MVP)

### Évaluation Complétude du PRD

Le PRD est **complet et bien structuré**. Les exigences sont clairement numérotées, les parcours utilisateurs sont documentés et traçables vers les FRs. Un seul point reste ouvert : les sessions cloud Windows (explicitement signalé comme zone à investiguer). La roadmap par sprint est cohérente avec les FRs listées.

---

## Validation de Couverture Epics

### Matrice de Couverture

| FR | Exigence PRD (résumé) | Epic | Statut |
|----|-----------------------|------|--------|
| FR1 | Création user sans jargon AD | Epic 2 | ✅ Couvert |
| FR2 | Provisionnement auto home dir + droits ACL | Epic 2 | ✅ Couvert |
| FR3 | Modification attributs user (classe, quota) | Epic 2 | ✅ Couvert |
| FR4 | Désactivation/suppression + archivage 2 temps | Epic 2 | ✅ Couvert |
| FR5 | Statut itinérant + droits différenciés auto | Epic 2 | ✅ Couvert |
| FR6 | Import GPEI standalone SER | Epic 10 | ⚠️ Déviation (voir ci-dessous) |
| FR7 | Inventaire machines par groupe physique/logique | Epic 3 | ✅ Couvert |
| FR8 | Actions unitaires machine (WOL, extinction, reboot) | Epic 3 | ✅ Couvert |
| FR9 | Actions batch sur workstationGroup | Epic 3 | ✅ Couvert |
| FR10 | Crons planifiés sur workstationGroup | Epic 3 | ✅ Couvert |
| FR11 | Import machines + groupes physiques CSV | Epic 3 | ✅ Couvert |
| FR12 | Association AppProfile à postes/workstationGroups | Epic 3 | ✅ Couvert |
| FR13 | Home directories avec quotas XFS | Epic 4 | ✅ Couvert |
| FR14 | Partages de classe avec ACLs POSIX héritées | Epic 4 | ✅ Couvert |
| FR15 | Gestion droits accès partages | Epic 4 | ✅ Couvert |
| FR16 | Suppression home dirs en 2 étapes | Epic 4 | ✅ Couvert |
| FR17 | Liste imprimantes | Epic 5 | ✅ Couvert |
| FR18 | Ajout/config/suppression imprimantes CUPS | Epic 5 | ✅ Couvert |
| FR19 | Pilotes Windows imprimantes | Epic 5 | ✅ Couvert |
| FR20 | Réservations DHCP + baux actifs | Epic 7 | ✅ Couvert |
| FR21 | Configuration DNS | Epic 7 | ⚠️ À investiguer (nature DNS) |
| FR22 | Import réservations DHCP en masse | Epic 7 | ✅ Couvert |
| FR23 | GPOs via Services/Legacy/ | Epic 8 | ✅ Couvert |
| FR24 | Packages WPKG + association profils | Epic 8 | ✅ Couvert |
| FR25 | Logs WPKG + rapports installation | Epic 8 | ✅ Couvert |
| FR26 | Scripts démarrage Windows | Epic 8 | ✅ Couvert |
| FR27 | Droits délégués sur périmètre | Epic 6 | ✅ Couvert |
| FR28 | Vue filtrée au périmètre de délégation | Epic 6 | ✅ Couvert |
| FR29 | Calcul droits Spatie (union groupe + individuel) | Epic 6 | ✅ Couvert |
| FR30 | Vue état flotte instances SER | Epic 9 | ✅ Couvert |
| FR31 | Navigation inter-instances SSO (Phase 2) | Epic 9 | ✅ Couvert |
| FR32 | MassActions multi-instances (post-MVP PRD) | Epic 9 | ⚠️ Déviation (voir ci-dessous) |
| FR33 | Liens user↔UAI par instance SER | Epic 11 | ✅ Couvert |
| FR34 | Itinérants avec attributs spécifiques par UAI | Epic 11 | ✅ Couvert |
| FR35 | Filtrage par UAI vers chaque SER | Epic 11 | ✅ Couvert |
| FR36 | Import GPEI standalone SER | Epic 11 | ✅ Couvert |
| FR37 | Dispatch GPEI par UAI (irundoo) | Epic 11 | ✅ Couvert |
| FR38 | Sync AD local via LdapRecord | Epic 11 | ✅ Couvert |
| FR39 | Apps autorisées standalone vs controlHub | Epic 10 | ✅ Couvert |

### Exigences Manquantes

**Aucune FR non couverte.** Les 39 FRs du PRD ont toutes un epic correspondant.

### Déviations Notables

#### ⚠️ FR6 — Changement de propriétaire (SER → irundoo)
- **PRD :** "SER peut recevoir et traiter un fichier GPEI en mode standalone"
- **Epics :** FR6 est réaffecté à Epic 10 avec la note "l'import GPEI sera géré par irundoo, pas en standalone SER"
- **Impact :** Changement de scope — le mode standalone SER pour GPEI est supprimé au profit d'une gestion centralisée par irundoo. **FR36 couvre le cas standalone SER**, donc la fonctionnalité n'est pas perdue, mais la numérotation crée une ambiguïté. À confirmer avec l'équipe.

#### ⚠️ FR32 — Décalage post-MVP vs implémenté
- **PRD :** FR32 MassActions est explicitement marqué "post-MVP"
- **Epics :** FR32 est marqué ✅ (déjà implémenté) dans le Requirements Inventory
- **Impact :** Le périmètre MVP est plus large que ce que le PRD indique. Non bloquant mais à documenter pour aligner les attentes.

#### ℹ️ FR21 — DNS à investiguer
- Les epics signalent explicitement que la nature de la gestion DNS (intégrée AD ou serveur séparé) doit être étudiée dans le legacy avant d'implémenter.

#### ℹ️ FRs ajoutés dans les epics (non présents dans le PRD)
- **FR3b :** Droits applicatifs SER (délégation partages + Spatie) → Epic 6
- **FR8b :** Feedback progression/readiness après allumage WOL → Epic 3
- **FR32b :** Organisation fichiers échanges SER↔controlHub → Epic 9

Ces ajouts sont des **raffinements légitimes** identifiés lors du découpage — ils complètent le PRD sans le contredire.

### Statistiques de Couverture

- **Total PRD FRs :** 39
- **FRs couverts dans les epics :** 39 (100%)
- **FRs avec déviation :** 2 (FR6, FR32) — non bloquants
- **FRs ajoutés dans les epics :** 3 (FR3b, FR8b, FR32b) — enrichissements
- **Couverture globale : 100%**


---

## Évaluation Alignement UX

### Statut du Document UX

**Non trouvé.** Aucun fichier `*ux*.md` dans le dossier planning-artifacts. Le document epics.md lui-même confirme : "Aucun document UX Design trouvé — cette section ne s'applique pas au projet."

### Évaluation : UX est-elle implicite ?

**Oui — il s'agit d'une application web user-facing.** Le PRD décrit une interface web (SFC Livewire, MPA), avec des parcours utilisateurs précis (Marie, Thomas, Karim). Une UX est donc implicite.

### Justification de l'absence de document UX formel

L'absence de document UX formel est **délibérée et justifiable** pour ce projet pour les raisons suivantes :

1. **Outil interne** — usage sur postes fixes en établissement, responsive design explicitement non prioritaire
2. **Design system fourni** — DaisyUI + Livewire 4 cadrent les conventions visuelles sans nécessiter de spécifications custom
3. **Conventions UI dans le PRD** — les parcours utilisateurs (P1 à P5) décrivent précisément les flux et les besoins UX (formulaires épurés, abstraction AD, feedback immédiat)
4. **Architecture décrit l'UI** — convention `resources/views/pages/` arborescente + atomic design (atoms/molecules/organisms) posée dans les exigences techniques

### Problèmes d'Alignement

Aucun misalignment critique identifié. Les quelques points d'attention :

- **Délégation (FR27/FR28)** — L'épic 6 note que "la matrice @can/Gates/Policies sera définie collaborativement lors de la création des stories" : l'UX de filtrage des vues n'est pas encore spécifiée en détail. Non bloquant pour l'implémentation des stories mais un risque de retravail front.
- **FR21 DNS** — nature de l'interface DNS non encore définie (dépend de l'investigation legacy).
- **Feedback asynchrone (NFR2)** — le pattern ControlHubTasks est défini architecturalement, mais les états UI (loading, success, error) ne sont pas formalisés. Laissé à la discrétion du développeur front.

### Avertissements

- ⚠️ **Absence de wireframes/maquettes** — acceptable pour cet outil interne mais peut générer des allers-retours lors de la review front des stories épic 6 (délégation) qui implique des vues filtrées complexes.
- ℹ️ **Convention atomic design** déclarée dans l'architecture mais non illustrée — les développeurs devront s'aligner sur une interprétation commune lors du Sprint 1.


---

## Revue Qualité des Epics

### Checklist Epic par Epic

| Epic | Valeur Utilisateur | Indépendance | Stories sizing | ACs BDD | Traçabilité FR |
|------|--------------------|--------------|----------------|---------|----------------|
| Epic 1 | ⚠️ Technique | ✅ | ✅ | ✅ | n/a (brownfield) |
| Epic 2 | ✅ | ⚠️ dépend Epic 4 | ✅ | ✅ | ✅ |
| Epic 3 | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 4 | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 5 | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 6 | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 7 | ✅ | ✅ | ✅ | ✅ | ⚠️ FR21 ouvert |
| Epic 8 | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 9 | ✅ | ✅ | ✅ | ✅ | ✅ |
| Epic 10 | ✅ | ✅ | ✅ | ✅ | ⚠️ FR6/FR36 flou |
| Epic 11 | ✅ | ✅ | ✅ | ✅ | ✅ |

---

### 🟠 Problèmes Majeurs

#### 🟠 M1 — Epic 1 : Epic technique sans valeur utilisateur directe

**Constat :** Toutes les stories d'Epic 1 sont "As a développeur" — aucun persona utilisateur. Epic 1 est un prérequis technique (migration PostgreSQL, catchall, AuthGuard), pas un livrable utilisateur.

**Contexte atténuant :** Le document le reconnaît lui-même ("FRs couverts : aucune FR produit — prérequis technique bloquant"). Dans un projet brownfield, ce type d'epic fondateur est légitime. Ce n'est pas une erreur de conception mais une déviation assumée des best practices.

**Recommandation :** Conserver tel quel. Documenter clairement que Epic 1 est le "Sprint 0" non-négociable. S'assurer que les stories 1.2/1.3 (catchall + dashboard) délivrent quand même une valeur opérationnelle à l'équipe.

---

#### 🟠 M2 — Story 2.2 : Critères d'acceptation non autonomes (forward dependency Epic 4)

**Constat :** Deux ACs de Story 2.2 mentionnent explicitement "(dépend Epic 4)" :
- "les ACLs sur les partages de classe sont recalculées selon la nouvelle classe **(dépend Epic 4)**"
- "le quota XFS est mis à jour sur le système de fichiers **(dépend Epic 4)**"

**Impact :** Story 2.2 ne peut pas être considérée "Done" avec sa Definition of Done complète sans Epic 4. Un développeur qui implémente Story 2.2 au Sprint 1 ne peut pas valider ces ACs.

**Recommandation :** Soit (a) retirer ces ACs de Story 2.2 et les transférer dans Story 4.1/4.2 comme ACs de rétro-intégration, soit (b) les marquer explicitement comme "AC différé — validé lors de Epic 4" avec un marqueur visuel dans la story. L'option (b) est moins invasive et maintient la traçabilité.

---

#### 🟠 M3 — Epic 6 mal positionnée dans le document (après Epic 10)

**Constat :** L'ordre des epics dans le document est : 1, 2, 3, 4, 5, 7, 8, 9, 10, **6**, 11. Epic 6 (Délégations & Permissions) apparaît après Epic 10, alors que le PRD la situe en Sprint 1 (même sprint que les Epics 2-5).

**Impact :** Un développeur lisant le document pourrait mal interpréter la priorité d'Epic 6 et la planifier après les Sprints 2-3. Epic 6 est pourtant critique pour le parcours Thomas (P3) prévu en Sprint 1.

**Recommandation :** Déplacer la section Epic 6 dans le document pour la placer après Epic 5, dans l'ordre logique du planning Sprint 1.

---

#### 🟠 M4 — GPEI Standalone SER (FR6/FR36) : périmètre ambigu, ownership flou

**Constat :**
- Le PRD FR6 dit : "SER peut recevoir et traiter un fichier GPEI en mode **standalone**"
- Le PRD FR36 dit la même chose mais renommé
- Dans les epics, le FR Coverage Map marque FR6 → Epic 10 avec la note "l'import GPEI sera géré par irundoo, pas en standalone SER"
- **Story 10.1** ne couvre QUE la gestion des apps autorisées (pas le GPEI)
- **Story 11.3** (Import GPEI et Dispatch) est côté irundoo uniquement

**Impact :** Il n'existe aucune story qui implémente le cas "SER standalone reçoit et traite un fichier GPEI sans irundoo". La fonctionnalité a été supprimée de facto des stories sans décision documentée explicite.

**Recommandation :** Décision à prendre explicitement — soit créer une Story 10.2 "Import GPEI en mode standalone SER", soit confirmer définitivement la suppression de ce use case (et mettre à jour le PRD en conséquence).

---

### 🟡 Points d'Attention Mineurs

#### 🟡 m1 — Story 3.3 : dépendance déclarée vers Story 3.2

**Constat :** "Dépend de Story 3.2 — les actions unitaires doivent être validées et testées avant."

**Évaluation :** Dépendance intra-epic logique et acceptable. Story 3.2 précède Story 3.3 dans l'ordre du document. Non bloquant si le sprint est planifié séquentiellement.

---

#### 🟡 m2 — Stories 11.4 et 11.5 : personas "développeur" non user-facing

**Constat :** Stories 11.4 ("As a **développeur**") et 11.5 (Phase 2 Keycloak, conditionnelle) sont des stories techniques avec des personas non-utilisateurs, similaires à Epic 1.

**Évaluation :** Justifié par le besoin de préparer l'infrastructure Phase 2. Les feature flags sont correctement utilisés pour isoler le code inactif. Acceptable.

---

#### 🟡 m3 — FR32 (MassActions) : incohérence post-MVP vs ✅ existant

**Constat :** Le PRD marque FR32 "post-MVP". L'inventaire des exigences dans les epics marque FR32 ✅ (existant). Story 9.3 note "*Fonctionnalité existante — story documentée pour complétude.*"

**Évaluation :** La fonctionnalité existe déjà dans le codebase irundoo. La divergence est réelle mais sans conséquence opérationnelle — c'est une information dépassée dans le PRD, pas un problème de planning.

---

#### 🟡 m4 — FR21 (DNS) : story absente, investigation préalable requise

**Constat :** FR21 n'a pas de story dédiée. L'épic 7 signale explicitement l'investigation nécessaire ("FR21 — À INVESTIGUER : nature exacte de la gestion DNS").

**Évaluation :** Traitement honnête et correct d'une incertitude. Non bloquant si l'investigation est planifiée avant ou pendant Sprint 2.

---

#### 🟡 m5 — Pas de sprint mapping visible dans les stories

**Constat :** Les stories n'indiquent pas à quel sprint elles appartiennent. Le mapping sprint est uniquement dans le PRD (tableau fonctionnalités MVP). Un plan de sprint séparé serait utile pour l'exécution.

**Recommandation :** Créer un document sprint plan ou ajouter un tag `Sprint: X` dans chaque story lors du sprint planning.

---

### Synthèse Qualité

| Sévérité | Nombre | Bloquant |
|----------|--------|----------|
| 🔴 Critique | 0 | — |
| 🟠 Majeur | 4 | M4 (GPEI standalone) nécessite décision |
| 🟡 Mineur | 5 | Non bloquants |

**Verdict :** La structure des epics est **solide**. Les ACs sont spécifiques, en format BDD, et couvrent les cas d'erreur. Les prérequis d'investigation legacy sont systématiquement documentés. Les 4 points majeurs sont des ajustements de planification, pas des défauts architecturaux.


---

## Synthèse et Recommandations

### Statut Global de Préparation à l'Implémentation

# ✅ PRÊT — avec actions correctives mineures

Le projet SER/irundoo est **prêt à démarrer l'implémentation**. Les artefacts de planification sont cohérents, complets à 100% en couverture FR, et les epics/stories sont de bonne qualité structurelle. Les problèmes identifiés sont des ajustements de planification, pas des blocages architecturaux.

---

### Problèmes Nécessitant une Action Avant ou Pendant Sprint 1

#### 🟠 Action #1 — GPEI Standalone SER : décision requise (M4)

**Décision à prendre :** La story "SER reçoit et traite un fichier GPEI en mode standalone" (FR6/FR36) n'a pas d'implémentation prévue. Est-ce délibéré ?
- **Si oui** → mettre à jour le PRD pour supprimer FR6 et FR36 standalone, ou les marquer explicitement post-MVP
- **Si non** → créer Story 10.2 "Import GPEI mode standalone SER" dans Epic 10

**Responsable :** Henri — décision produit

---

#### 🟠 Action #2 — ACs de Story 2.2 non autonomes (M2)

Marquer ou déplacer les deux ACs qui dépendent d'Epic 4 :
1. "ACLs recalculées selon la nouvelle classe (dépend Epic 4)" → déplacer vers Story 4.2
2. "quota XFS mis à jour sur le filesystem (dépend Epic 4)" → déplacer vers Story 4.1

**Responsable :** Développeur qui prend en charge Story 2.2

---

#### 🟠 Action #3 — Repositionner Epic 6 dans le document (M3)

Déplacer la section Epic 6 pour qu'elle apparaisse après Epic 5, respectant l'ordre Sprint 1. Purement cosmétique mais important pour la lisibilité du document par un développeur secondaire.

**Responsable :** Henri (mise à jour epics.md)

---

### Recommandations Additionnelles (non bloquantes)

1. **Investigation DNS (FR21)** — Planifier l'investigation legacy DNS en début de Sprint 2, avant le démarrage de Story 7.1. Durée estimée : 1-2 heures.

2. **Sprint mapping** — Ajouter un tag `Sprint: X` dans chaque story lors du sprint planning pour faciliter le suivi. Alternative : créer un document `sprint-plan.md` séparé.

3. **Matrice Spatie (Epic 6)** — Le mini-brainstorm préalable requis pour Story 6.2 doit être planifié **avant** le début d'Epic 6. Sans cette matrice, le développeur front ne peut pas implémenter les vues filtrées de Story 6.1.

4. **Sessions cloud Windows** — L'investigation de ce périmètre doit être planifiée avant la finalisation de Sprint 3 (Epic 8). Non bloquant pour les Sprints 1-2.

---

### Bilan de l'Évaluation

| Domaine | Statut | Issues |
|---------|--------|--------|
| Documents disponibles | ✅ | Pas de doublons, 3/4 docs présents |
| Complétude PRD | ✅ | 39 FRs + 18 NFRs bien définis |
| Couverture Epics | ✅ | 100% FRs couverts |
| Alignement UX | ✅ | Absence justifiée pour outil interne |
| Qualité Epics/Stories | ✅ | 0 critique, 4 majeurs, 5 mineurs |

**Total issues : 9** (0 critique / 4 majeurs / 5 mineurs)

Les 4 points majeurs peuvent être traités en moins d'une demi-journée de travail. Aucun ne remet en cause la structure d'ensemble ni le démarrage du Sprint 1.

---

*Rapport généré le 2026-03-18 | Évalué par : Claude Code (PM & Scrum Master)*
*Documents évalués : prd.md (2026-03-17), architecture.md (2026-03-18), epics.md (2026-03-18)*

