---
stepsCompleted: [step-01-validate-prerequisites, step-02-design-epics, step-03-create-stories, step-04-final-validation]
inputDocuments: [_bmad-output/planning-artifacts/prd.md, _bmad-output/planning-artifacts/architecture.md]
---

# codebase - Epic Breakdown

## Overview

Ce document fournit le découpage complet en épics et stories pour SambaEdu-Reload (SER) + irundoo, décomposant les exigences du PRD et de l'Architecture en stories implémentables.

> **Dernière révision majeure 2026-05-01** : ajout des Epics **15** (Pipeline de Déploiement WPKG natif, 5 stories actives + 15.7 PLANNED), **16** (Gestion native des GPOs, 6 stories cadrées), et **17** (Scripts de Démarrage Windows, 4 stories cadrées). Stories 9.1 et 9.3 PAUSED annulées et remplacées respectivement par Epic 16 et Epic 17. Epic 15 détaillé (ACs complètes), Epics 16/17 en cadrage haut niveau (ACs à figer au moment de chaque story). Conduite par henri (PM session).

## Requirements Inventory

### Functional Requirements

**SER — Gestion des Utilisateurs**

FR1 ✅: Le responsable de collège peut créer un compte utilisateur (élève ou enseignant) sans exposer de concepts AD ou LDAP
FR2: Le système provisionne automatiquement le home directory et les droits ACL à la création d'un utilisateur ou à sa première connexion si le home directory est manquant
FR3⏳: Le responsable peut modifier les attributs d'un utilisateur (classe(✅), quota)
FR3b: Le responsable peut attribuer à un utilisateur des droits de délégation sur des partages de fichiers et des droits Spatie (accès à certaines sections de l'application) — *Note : pas de profil applicatif utilisateur ; les AppProfiles s'appliquent aux machines/salles (FR12), pas aux sessions utilisateur, car les apps sont installées sur la machine au démarrage et non sur la session*
FR4: Le responsable peut désactiver et supprimer un compte utilisateur avec archivage du home directory en deux temps (corbeille → suppression permanente)(⏳déjà dans le front mais à voir si le back est implémenté convenablement)
FR5: Le système affiche le statut itinérant d'un utilisateur et applique automatiquement les droits différenciés associés
FR6: Le responsable peut importer des utilisateurs depuis un fichier externe (GPEI standalone)

**SER — Gestion des Machines & Parcs**

> **Vocabulaire de ce domaine (critique pour développeurs et utilisateurs) :**
> - **Parc** = l'ensemble de toutes les machines du serveur SER (notion globale, pas un regroupement)
> - **Groupe physique** = regroupement physique de postes (une salle, une partie de salle) — reflète la géographie réelle
> - **WorkstationGroup (groupe logique)** = regroupement logique de 1..N postes, pouvant appartenir à des groupes physiques différents — unité de pilotage (AppProfile, actions batch, cron)

FR7 ✅: Le responsable peut consulter l'inventaire des machines par groupe physique (salle) et par workstationGroup (groupe logique)
FR8 ⏳ (le code existe mais n'est pas encore testé): Le responsable peut effectuer des actions unitaires sur une machine (allumage WOL, extinction, reboot)
FR8b: Le responsable peut voir la progression (à minima la readiness) de l'action des machines dont il a demandé l'allumage
FR9: Le responsable peut effectuer des actions batch sur un workstationGroup entier (allumage, extinction, reboot)
FR10: Le responsable peut programmer des actions cron sur un workstationGroup (allumage/extinction planifiés)
FR11: Le responsable peut importer des machines et des groupes physiques (salles) depuis un fichier CSV
FR12 ✅: Le responsable peut associer un profil applicatif (AppProfile) à des postes individuels et à des workstationGroups

**SER — Système de Fichiers**

FR13: Le système crée et gère les home directories individuels des utilisateurs avec quotas XFS
FR14: Le responsable peut créer et configurer des répertoires de partage par classe avec ACLs POSIX héritées
FR15: Le responsable peut gérer les droits d'accès sur les partages de classe (lecture, écriture, dossier échange)
FR16: Le système supprime les home directories en deux étapes (archivage dans /home/trash/ puis suppression permanente optionnelle)

**SER — Impression**

FR17: Le responsable peut consulter la liste des imprimantes et leurs détails
FR18: Le responsable peut ajouter, configurer et supprimer des imprimantes via CUPS
FR19: Le responsable peut gérer les pilotes Windows associés aux imprimantes

**SER — Réseau**

FR20: Le responsable peut consulter et gérer la liste des réservations DHCP et les baux actifs, CRUD des réservations individuellement ou en groupes.
FR21: Le responsable peut configurer les entrées DNS
FR22: Le responsable peut importer des réservations DHCP en masse

**SER — Déploiement Windows**

FR23: Le responsable peut consulter et gérer les GPOs via l'interface SER (Services/Legacy/) (tâche à travailler en amont)
FR24: Le responsable peut gérer les packages WPKG (définition, ✅association aux profils, déclenchement au démarrage(normalement géré direct dans les scripts windows))
FR25: Le responsable peut consulter les logs WPKG et les rapports d'installation
FR26: Le responsable peut gérer les scripts de démarrage Windows

**SER — Délégations & Permissions**

> **⚠️ Distinction CRITIQUE — deux types de droits totalement indépendants :**
> - **Droits applicatifs SER** (Spatie) = droits d'accès aux sections et fonctionnalités de l'application SER. Exemples : "peut gérer les imprimantes", "peut voir les réservations DHCP". Gérés par Spatie, stockés en DB PostgreSQL, sans lien avec l'AD.
> - **Droits Windows / partages fichiers** = droits NTFS, ACLs POSIX, accès aux partages Samba. Exemples : "peut écrire dans le partage de la classe 3eA", "quota XFS limité à 500 Mo". Gérés via LdapRecord/AD et commandes système.
>
> Ces deux dimensions doivent être présentées séparément dans l'UI et documentées séparément dans le code. Un développeur ne doit jamais confondre les deux.

FR27: L'administrateur peut attribuer des droits délégués à un utilisateur sur un périmètre limité (groupe physique, workstationGroup) — ce sont des **droits applicatifs SER** (Spatie) qui restreignent la vue et les actions dans l'application
FR28: Un utilisateur délégué ne peut voir et agir que sur son périmètre de délégation dans l'interface SER
FR29: Le système calcule les droits applicatifs SER comme l'union des droits de groupe et des droits individuels (Spatie)

> **Vocabulaire — controlHub vs irundoo :**
> - **controlHub** = concept générique : logiciel de supervision d'une ou plusieurs instances SER. Côté SER (open-source), toutes les références sont au concept "controlHub" — SER ne doit pas être verrouillé sur une solution privée.
> - **irundoo** = implémentation commerciale de controlHub développée par l'équipe. C'est UN controlHub parmi d'autres possibles.
> - Dans le code SER : `Services/ControlHub/`, `ControlHubTask`, endpoints `/api/v1/controlhub/`
> - Dans irundoo : il se présente comme un controlHub auprès de SER
>
> **Note périmètre :** la supervision de flotte multi-instances (dashboard, navigation inter-instances, MassActions) est du ressort du controlHub lui-même (irundoo) et sort du scope SER. Les hooks nécessaires côté SER (API controlHub, `ControlHubTask`, MassActions) sont déjà présents ou couverts par les épics SER existants.

**irundoo — Gestion des Établissements & Itinérants**

> **⚠️ Dépendance Keycloak — comportement par phase :**
> - **Phase MVP (AD central)** : tous les AD locaux sont des copies synchronisées de l'AD central qui contient l'ensemble des données de tous les collèges. Le filtrage par UAI n'est pas réalisable nativement — les données irundoo (liens user↔UAI) sont disponibles mais ne peuvent pas filtrer ce qui descend vers chaque SER via la sync AD.
> - **Phase 2 (Keycloak)** : une fois Keycloak substitué à l'AD central, irundoo peut pousser uniquement les utilisateurs du bon UAI vers chaque SER. Le code peut être écrit dès maintenant, mais les appels ne seront opérationnels qu'après la migration Keycloak.
> - Démarrer l'intégration Keycloak dans irundoo dès le départ et travailler à l'import de l'AD central dans Keycloak est une approche valide pour prendre de l'avance.

FR33: irundoo maintient les liens utilisateur↔établissement (UAI) pour chaque instance SER
FR34: irundoo gère les utilisateurs itinérants avec des attributs spécifiques (quota réduit, droits limités) par lien user↔UAI — *opérationnel Phase 2 (Keycloak)*
FR35: irundoo filtre et transmet à chaque instance SER uniquement les utilisateurs relevant de son UAI (locaux + itinérants) — *opérationnel Phase 2 (Keycloak) ; en MVP les ADs sont des copies complètes de l'AD central*

**SER + irundoo — Imports & Intégrations Académiques**

> **⚠️ Comportement par phase (même dépendance Keycloak) :**
> - **Phase MVP** : l'import GPEI doit reproduire strictement le comportement legacy — écrire dans l'AD central, qui se synchronise lui-même avec les ADs locaux de chaque SER. irundoo peut parser et dispatcher, mais l'écriture passe par l'AD central.
> - **Phase 2 (Keycloak)** : irundoo dispatche directement par UAI vers chaque SER via l'API controlHub. Le code peut être écrit dès maintenant pour prendre de l'avance.

FR36: SER peut recevoir et traiter un fichier GPEI en mode standalone — *MVP : écriture dans l'AD local (qui sync avec l'AD central)*
FR37: irundoo peut parser un fichier GPEI et dispatcher les mises à jour vers les instances SER concernées selon leur UAI — *MVP : écriture AD central uniquement ; Phase 2 : dispatch direct par UAI via API controlHub*
FR38: Préparation infrastructure pour réception users depuis controlHub (Phase 2 Keycloak) — en MVP l'AD central se synchronise lui-même avec les ADs locaux, pas de sync applicative à implémenter
FR39: SER gère les applications autorisées à l'installation en mode standalone (apps définies localement) ; quand un controlHub (irundoo) est connecté, il définit les apps autorisées et SER s'y conforme

### NonFunctional Requirements

**Performance**

NFR1: Les opérations courantes (chargement liste utilisateurs, liste machines, état parc) s'affichent en moins de 2 secondes sur un réseau local établissement
NFR2: Les actions longues (WOL, scripts, tâches cron) donnent un retour de démarrage immédiat et un feedback d'état sans bloquer l'interface
NFR3: Aucune opération ne nécessite une requête LDAP non indexée ou un scan complet de l'annuaire AD

**Sécurité**

NFR4: Les mots de passe ne transitent jamais en clair dans l'application ni dans les sessions (correction faille legacy connue)
NFR5: L'accès CAS/SSO est sécurisé via SSL/TLS (correction faille legacy connue)
NFR6: L'accès administrateur ne dispose d'aucun bypass non authentifié (correction faille legacy connue)
NFR7: Les données personnelles des utilisateurs (élèves et enseignants) sont accessibles uniquement aux rôles autorisés selon le principe de moindre privilège (Spatie)
NFR8: Les logs d'actions sensibles (création/suppression utilisateur, modification droits, accès home dir) sont conservés et horodatés

**Fiabilité & Résilience**

NFR9: SER fonctionne intégralement sans connectivité internet — toutes les fonctions MVP opèrent en réseau local isolé
NFR10: Un rollback complet de l'instance est possible en moins de 5 minutes via snapshot Proxmox
NFR11: La perte de connexion au controlHub (irundoo ou autre) n'affecte pas le fonctionnement de SER (SER standalone par design)
NFR12: Les migrations de données sont idempotentes — une migration exécutée deux fois ne corrompt pas les données

**Maintenabilité**

NFR13: Tout le code est typé (PHP typed properties, return types, DTOs) — aucun tableau associatif non typé comme structure de données principale
NFR14: Chaque méthode dans `Services/Legacy/` porte un commentaire indiquant le fichier legacy source et la raison du refactoring futur
NFR15: Chaque fonctionnalité livrée est accompagnée de tests automatisés avant passage en bêta
NFR16: Un développeur externe peut installer un environnement de développement fonctionnel en suivant uniquement la documentation du repo

**Intégration**

NFR17: La synchronisation LDAP/AD repose sur la structure OU standardisée définie dans la documentation — toute déviation est détectée et signalée explicitement
NFR18: Les intégrations système (CUPS, DHCP, scripts sudo) sont encapsulées dans des Services dédiés — aucun appel système direct depuis les SFC Livewire

### Additional Requirements

*(Exigences techniques de l'Architecture impactant l'implémentation)*

- **Aucun starter template** — projet brownfield, la base de code SER existe déjà (migration progressive depuis le legacy SambaEdu)
- **PostgreSQL = source de vérité unique** pour toutes les entités. Users/UserGroups : écriture double LdapRecord (AD local) **en premier**, puis PostgreSQL — ordre intentionnel : si l'écriture AD échoue, PostgreSQL n'est pas mis à jour (cohérence garantie). Lecture toujours PostgreSQL. Autres entités : PostgreSQL uniquement. Le controlHub (irundoo) n'accède jamais directement aux AD locaux — toutes les opérations passent par l'API SER.
- **Architecture 3 couches obligatoire** : SFC Livewire (UI + validation) → Services (métier) → Models Eloquent / LdapRecord. Jamais d'Eloquent ou appel système direct dans un composant Livewire.
- **Pattern ControlHub Tasks** pour toutes les actions longues (WOL batch, scripts Windows, cron workstationGroup) : POST API → création DB task → dispatch Job → callback controlHub. Un Controller + Job par type de tâche.
- **Interface AuthGuard** à implémenter dès le MVP (`app/Contracts/Auth/AuthGuardInterface.php` + `SambaEduAuthGuard`) pour permettre le swap Keycloak en Phase 2 sans modifier les routes.
- **Stratégie catchall legacy** : `SAMBAEDU_LEGACY_PATH` en config, table `legacy_catchall_logs`, dashboard `/admin/legacy-monitor` avec composant Livewire temps réel.
- **Redis** pour cache (requêtes LDAP fréquentes) et queues.
- **Format API REST JSON uniforme** : `{ success, message, [clé_métier] }` sans wrapper `data:`, Sanctum tokens pour auth controlHub↔SER, versioning `/api/v1/`.
- **GlitchTip self-hosted** via `sentry/sentry-laravel` pour monitoring erreurs de chaque instance SER.
- **Tests obligatoires** par feature : PHPUnit unitaire (chaque Service) + Playwright E2E (chaque page/feature) livrés dans la même PR.
- **Convention vues** : `resources/views/pages/` arborescente, composants atomic design (atoms/molecules/organisms).
- **Sprint 0** : migration MySQL → PostgreSQL (associations AppProfile ↔ Apps) — prérequis technique avant Sprint 1.
- **Services/Legacy/** : dette documentée et assumée pour GPOs, WPKG, profils NT — chaque méthode commentée avec source legacy.
- **API SER surface minimale** au départ, croît avec les stories d'intégration controlHub (irundoo).
- **Stratégie de cloisonnement legacy** (Epic 1bis) : les modules PHP legacy sont intégrés dans un sous-dossier `legacy/` avec des shims LDAP→Eloquent et MySQL→Eloquent, permettant de livrer les fonctionnalités legacy aux utilisateurs via l'interface Laravel pendant que la réécriture native avance. Un error logger unifié (legacy + Laravel) sert d'outil de dev dès le début.

### UX Design Requirements

*Aucun document UX Design trouvé — cette section ne s'applique pas au projet.*

### FR Coverage Map

FR1: Epic 2 — Création utilisateur sans jargon AD
FR2: Epic 2 — Provisioning home directory + droits ACL automatique
FR3: Epic 2 — Modification attributs utilisateur (classe, quota)
FR3b: Epic 7 — Droits applicatifs SER (délégation partages + Spatie)
FR4: Epic 2 — Désactivation/suppression avec archivage home dir
FR5: Epic 2 — Statut itinérant + droits différenciés automatiques
FR6: Epic 11 — Import utilisateurs GPEI (l'import GPEI est géré par irundoo côté controlHub, pas en standalone SER)
FR7: Epic 4 — Inventaire machines par groupe physique / workstationGroup
FR8: Epic 4 — Actions unitaires machine (WOL, extinction, reboot)
FR8b: Epic 4 — Feedback progression/readiness après allumage
FR9: Epic 4 — Actions batch sur workstationGroup
FR10: Epic 4 — Crons planifiés sur workstationGroup
FR11: Epic 4 — Import machines + groupes physiques depuis CSV
FR12: Epic 4 — Association AppProfile à postes et workstationGroups
FR13: Epic 5 — Home directories avec quotas XFS
FR14: Epic 5 — Partages de classe avec ACLs POSIX héritées
FR15: Epic 5 — Gestion droits d'accès partages (lecture, écriture, échange)
FR16: Epic 5 — Suppression home dirs en deux étapes (corbeille → permanent)
FR17: Epic 6 — Liste imprimantes et détails
FR18: Epic 6 — Ajout/configuration/suppression imprimantes CUPS
FR19: Epic 6 — Gestion pilotes Windows imprimantes
FR20: Epic 8 — Réservations DHCP + baux actifs
FR21: Epic 8 — ⚠️ À INVESTIGUER : nature exacte de la gestion DNS (intégrée AD ou serveur séparé ?) — peut se fondre dans FR20 ou générer une story dédiée selon les trouvailles legacy
FR22: Epic 8 — Import réservations DHCP en masse
FR23: Epic 9 — Gestion GPOs (Services/Legacy/)
FR24: Epic 9 — Gestion packages WPKG + association profils
FR25: Epic 9 — Logs WPKG + rapports installation
FR26: Epic 9 — Scripts de démarrage Windows
FR27: Epic 7 — Attribution droits délégués sur périmètre (groupe physique, workstationGroup)
FR28: Epic 7 — Vue filtrée au périmètre de délégation
FR29: Epic 7 — Calcul droits Spatie (union groupe + individuel)
FR33: Epic 11 — Liens user↔UAI par instance SER
FR34: Epic 11 — Itinérants avec attributs spécifiques par UAI
FR35: Epic 11 — Filtrage transmission par UAI vers chaque SER (dépend Keycloak irundoo)
FR36: Epic 11 — Import GPEI + dispatch par UAI (irundoo side)
FR37: Epic 11 — Dispatch GPEI par UAI depuis irundoo
FR38: Epic 11 — Infrastructure réception users depuis controlHub (Phase 2)
FR39: Epic 10 — Apps autorisées standalone vs controlHub

## Definition of Done (toutes les stories)

Chaque story est considérée terminée uniquement si :
- Les tests PHPUnit unitaires couvrent chaque méthode de Service introduite ou modifiée
- Les tests Playwright E2E couvrent chaque nouvelle page ou interaction utilisateur
- Tests et code sont livrés dans la même PR
- L'architecture 3 couches est respectée (zéro logique métier dans les SFC Livewire, zéro appel système hors Services)
- Le code est typé (typed properties, return types, DTOs — pas de tableaux associatifs non typés)

---

## Epic List

### Epic 1 — Fondations & Observabilité
*L'équipe de développement dispose d'une base PostgreSQL saine et d'outils pour piloter la migration progressive depuis le legacy SambaEdu.*
**FRs couverts :** aucune FR produit — prérequis technique bloquant pour tous les épics suivants
**Contenu :** migration MySQL → PostgreSQL (associations AppProfile ↔ Apps), catchall legacy + dashboard `/admin/legacy-monitor`, GlitchTip error monitoring, AuthGuard interface (`SambaEduAuthGuard`)

---

### Epic 1bis — Cloisonnement Legacy
*Les modules PHP legacy sont intégrés dans un sous-dossier `legacy/` de SER, avec des shims LDAP→Eloquent et MySQL→Eloquent. Un error logger unifié capture toutes les erreurs (legacy + Laravel) pour faciliter le développement. Les utilisateurs accèdent aux modules non encore réécrits via l'interface Laravel sans rupture fonctionnelle.*
**FRs couverts :** aucune FR produit — infrastructure de transition et outil de dev
**Prérequis :** Epic 1 (catchall story 1.2, import données story 1.1)
**Contenu :** error logger & dashboard admin, bootstrap legacy + shim LDAP→Eloquent, shim SQL MySQL→Eloquent, intégration modules Tier 1/2/3

---

### Epic 2 — Gestion des Utilisateurs SER
*Le responsable de collège peut créer, modifier et supprimer des comptes utilisateurs sans jamais manipuler l'AD. Les home directories et droits ACL sont provisionnés automatiquement. Les utilisateurs itinérants sont identifiés et leurs droits appliqués automatiquement.*
**FRs couverts :** FR1, FR2, FR3, FR4, FR5, FR6

---

### Epic 3 — Système iPXE — Boot réseau & Déploiement OS
**Statut :** 🔴 not-ready
*Le système iPXE est entièrement réimplémenté nativement dans Laravel, sans dépendance au proxy legacy catchall. Les postes peuvent booter, s'enregistrer (nommage, parc, salle), et recevoir une installation OS (Linux, Windows) via des endpoints Laravel dédiés, en s'appuyant sur PostgreSQL comme source de vérité à la place de l'AD direct.*
**FRs couverts :** FR8 (boot/WOL context), FR7 (enrollment machines), FR23-26 (déploiement Windows via iPXE)
**Prérequis :** Epic 1 (AuthGuard, catchall), Epic 4 (modèles Workstation/WorkstationGroup)
**Note :** les scripts d'installation OS existants (`actions/*.php`, `Win10/`) sont réécrits en services PHP Laravel — ils appellent les mêmes outils système (wimboot, clonezilla, partitions) mais via des Services typés, sans dépendance au legacy PHP procédural.

---

### Epic 4 — Gestion des Machines, WorkstationGroups & AppProfiles SER
*Le responsable peut consulter l'inventaire des machines par groupe physique et groupe logique, piloter les postes (WOL, extinction, reboot) de manière unitaire et en batch, programmer des crons, importer des machines depuis CSV, et associer des profils applicatifs.*
**FRs couverts :** FR7, FR8, FR8b, FR9, FR10, FR11, FR12

---

### Epic 5 — Système de Fichiers SER

> Story 5.1 splittée en 5.1a/b/c/d le 2026-04-22 (brainstorm Henri) — voir section détaillée.
*Le responsable gère les home directories avec quotas XFS, crée les partages de classe avec ACLs POSIX héritées (lecture, écriture, échange), et supprime les données en deux temps (corbeille → suppression permanente).*
**FRs couverts :** FR13, FR14, FR15, FR16

---

### Epic 6 — Impression SER ✅
*Le responsable peut consulter, ajouter, configurer et supprimer des imprimantes via CUPS, et gérer les pilotes Windows associés.*
**FRs couverts :** FR17, FR18, FR19
**Note tests :** utiliser `cups-pdf` (`printer-driver-cups-pdf`) comme imprimante virtuelle CUPS sur les environnements sans hardware — les tests peuvent interroger et configurer cette imprimante réelle sans mock.

---

### Epic 7 — Délégations & Permissions Applicatives SER
*L'administrateur peut déléguer des droits à un enseignant sur un périmètre limité. L'utilisateur délégué ne voit et n'agit que sur son périmètre dans l'interface SER. La matrice `@can`/Gates/Policies sera définie collaborativement lors de la création des stories.*

> **Rappel :** cet épic couvre exclusivement les **droits applicatifs SER** (Spatie, accès sections de l'app). Les droits sur les partages et ACLs POSIX/Windows sont dans Epic 5.

**FRs couverts :** FR3b, FR27, FR28, FR29

---

### Epic 8 — Réseau (DHCP/DNS) SER
*Le responsable peut consulter et gérer les réservations DHCP, les baux actifs, configurer les entrées DNS, et importer des réservations en masse.*
**FRs couverts :** FR20, FR21, FR22

---

### Epic 9 — Déploiement Windows SER
*Le responsable peut gérer les GPOs (via Services/Legacy/), définir et associer des packages WPKG aux profils, consulter les logs d'installation, et gérer les scripts de démarrage Windows.*
**FRs couverts :** FR23, FR24, FR25, FR26

---

### Epic 10 — Intégrations Académiques SER
*Gestion des applications autorisées à l'installation selon le contexte (standalone vs controlHub connecté). SER adopte la liste locale en standalone, ou la liste poussée par irundoo quand un controlHub est connecté.*
**FRs couverts :** FR39

---

### Epic 11 — Gestion des Établissements, Itinérants & Intégrations irundoo
*irundoo maintient les liens user↔UAI, gère les itinérants, dispatche le GPEI par UAI, et prépare l'infrastructure de réception des users pour la Phase 2 Keycloak. La sync AD locale reste opérationnelle en Phase MVP.*

> **Phase MVP :** comportement legacy reproduit (écriture AD central + sync). **Phase 2 :** dispatch direct par UAI via API controlHub.

**FRs couverts :** FR33, FR34, FR35, FR36, FR37, FR38

---

### Epic 12 — Revue par l'équipe
*Epic de gouvernance « rolling » — point de revue collaborative récurrente avec l'équipe terrain (responsable de collège, RefNum, enseignants…) pour rouvrir et amender la matrice profils × droits applicatifs SER. Chaque story 12.x = une itération de validation déclenchée par un retour terrain, un nouveau profil métier, ou une question matrice soulevée par une story Epic 7 ou métier. Output = patch versionné de `_bmad-output/planning-artifacts/profiles-rights-matrix.md` + éventuels tickets de retombée vers Epic 7 ou un epic métier.*

---

### Epic 13 — Refonte BBB & Compat BBB 3.x
**Statut :** 🕒 post-prod (deferred)
*Réécrire le module de visioconférence BigBlueButton en Laravel natif et le rendre compatible BBB serveur 3.x. Aujourd'hui shimmé en Tier 3 (cf. `legacy/modules/bbb/`) via le fork `sambaedu/bigbluebutton-api-php` 2.0.12 qui cible BBB 2.4–2.6 — passwords de salon (retirés en BBB 3.0), APCu bloquant, LB maison, config serveurs en CSV. Refonte post-MVP pour lever ces dettes simultanément.*
**FRs couverts :** à préciser (visioconférence intra-établissement + invités externes)
**Prérequis :** mise en production MVP SER

---

### Epic 14 — Refactoring & Sortie du Shim Legacy
*Dette technique, refactor d'architecture, et migration des modules legacy aujourd'hui shimmés via `legacy/modules/` vers leur équivalent SER natif. Aucun changement fonctionnel observable côté utilisateur final : extraction de DTOs, suppression de duplications, simplification des couches, et sortie progressive du shim avec parité fonctionnelle stricte. Epic ouvert (rolling) qui agrège des stories techniques découvertes en cours de route, sans coupler le calendrier produit.*
**FRs couverts :** aucune FR produit — dette technique pure (no-op fonctionnel attendu pour l'utilisateur final)
**Critère d'inclusion :** une story 14.x ne doit livrer **aucun changement fonctionnel observable**. Tout écart fonctionnel renvoie la story dans son epic métier d'origine. Pour les stories de sortie du shim, l'exigence de parité fonctionnelle est strictement requise — la suppression du proxy legacy + retrait du catchall ne sont pas considérés comme un changement fonctionnel observable.

---

### Epic 15 — Pipeline de Déploiement WPKG natif
**Statut :** 🟡 ready
*Le serveur génère et publie `hosts.xml`, `profiles.xml` et les options `.ini` par poste à partir d'Eloquent (source de vérité unique). Les rapports clients sont ingérés via une API native, et le responsable visualise l'état de déploiement par poste / parc / profil sur un dashboard dédié. Channel logs `wpkg-deploy` isolé pour le debug.*
**FRs couverts :** FR24 (partie pipeline serveur), FR25 (ingestion native, complète Story 9.4)
**Prérequis :** Epic 4, Story 9.2 ✅
**Frontière Epic 9 :** complète le pipeline manquant ; ne remplace pas l'admin (Story 9.2) ni les logs shimés (9.4/9.5) — Story 15.7 arbitrera leur retrait après stabilisation.

---

### Epic 16 — Gestion native des GPOs
**Statut :** 🔴 not-ready
*Réécriture native du module GPO Samba (legacy `sambaedu/gpo/`, actuellement shimé via 1bis.18). Listing, lecture, édition par sections (Firefox, Thunderbird, Wallpaper, Veyon, Wine, Roaming, Raccourcis), création / duplication / suppression, liaison GPO ↔ OU / WorkstationGroup, hook GPO → invocation `wpkg.js` côté client (jonction Epic 15). Channel logs `gpo-deploy`.*
**FRs couverts :** FR23
**Prérequis :** Epic 4, Story 1bis.18 ✅
**Annule/remplace :** Story 9.1 PAUSED.

---

### Epic 17 — Scripts de Démarrage Windows
**Statut :** 🔴 not-ready
*Gestion native des scripts Windows (logon, startup, shutdown, logoff) déposés sur NETLOGON ou via GPO. Versioning, éditeur Livewire, association à des cibles (user / machine / OU / WorkstationGroup), logs d'exécution et rapports d'erreur via channel `winscripts`.*
**FRs couverts :** FR26
**Prérequis :** Epic 4
**Annule/remplace :** Story 9.3 PAUSED.
**Frontière Epic 16 :** Epic 16 = policies registre Windows ; Epic 17 = scripts exécutables. Une story 16.6 consomme Epic 17 quand elle invoque `wpkg.js`.

---

## Epic 1 : Fondations & Observabilité

*L'équipe de développement dispose d'une base PostgreSQL saine et d'outils pour piloter la migration progressive depuis le legacy SambaEdu.*

### Story 1.1 : Import des données MySQL vers le schéma PostgreSQL ✅ IMPLÉMENTÉE

As a **développeur**,
I want les données existantes de MySQL importées et contraintes dans le schéma PostgreSQL SER déjà défini,
So that toutes les fonctionnalités SER s'appuient sur la base de données cible sans conserver de dette structurelle MySQL.

**Acceptance Criteria :**

**Given** le schéma PostgreSQL SER est en place (migrations Laravel appliquées)
**When** le script d'import est exécuté
**Then** les données MySQL (utilisateurs, machines, AppProfiles, associations AppProfile ↔ Apps, etc.) sont importées dans les tables PostgreSQL existantes
**And** toutes les contraintes du nouveau schéma (clés étrangères, types, nullable) sont respectées — toute violation est signalée explicitement, pas silencieusement ignorée
**And** les relations sont reconstituées selon le modèle de données SER (pas copiées depuis MySQL)
**And** l'import peut être relancé sans effet de bord si une exécution précédente a échoué ou a déjà été effectuée — pas de doublons, pas de corruption
**And** un snapshot Proxmox est pris avant exécution (rollback < 5 min disponible)

---

### Story 1.2 : Catchall Legacy + Blocage des Routes Migrées ✅

As a **développeur**,
I want que le catchall route les appels non-Livewire vers le legacy via `SAMBAEDU_LEGACY_PATH` configurable, et bloque l'accès aux routes dont l'équivalent Livewire existe dans SER,
So that le legacy reste accessible pour les routes non encore migrées, et que les utilisateurs soient redirigés vers les nouvelles pages SER sans passer par le legacy.

**Acceptance Criteria :**

**Given** `SAMBAEDU_LEGACY_PATH` est défini dans `.env`
**When** une route sans correspondance Livewire est appelée (et non dans la liste de blocage)
**Then** la requête est redirigée vers le script PHP legacy correspondant
**And** la requête est loggée dans `legacy_catchall_logs` (timestamp, method, path, IP, query string, referer)

**Given** une route legacy a un équivalent Livewire déclaré dans la liste de blocage
**When** cette route legacy est appelée
**Then** l'accès est bloqué et l'utilisateur est redirigé vers l'équivalent SER
**And** la requête n'est pas loggée dans `legacy_catchall_logs`

**Given** `LEGACY_BLOCK_MIGRATED_ROUTES=false` dans `.env`
**When** une route de la liste de blocage est appelée
**Then** le blocage est désactivé — la requête passe au legacy normalement (mode transition)

**Given** `SAMBAEDU_LEGACY_PATH` est absent ou invalide
**When** le catchall tente de résoudre une route
**Then** une erreur explicite est levée (pas de comportement silencieux)

---

### Story 1.3 : Dashboard Legacy Monitor ✅

As a **développeur**,
I want un dashboard `/admin/legacy-monitor` affichant les appels catchall en temps réel,
So that l'équipe peut identifier les routes legacy encore actives et prioriser leur migration vers Livewire.

**Acceptance Criteria :**

**Given** des appels catchall ont été loggés dans `legacy_catchall_logs`
**When** je consulte `/admin/legacy-monitor`
**Then** je vois la liste des appels non gérés avec path, méthode, date, fréquence
**And** la liste se rafraîchit sans rechargement de page (composant Livewire)
**And** je peux filtrer par path ou par méthode HTTP
**And** la page est accessible uniquement aux admins SER (gate Spatie)

---

### Story 1.4 : Interface AuthGuard ✅

As a **développeur**,
I want une interface `AuthGuardInterface` avec une implémentation `SambaEduAuthGuard`,
So que le middleware d'auth délègue à l'implémentation active et que le swap vers Keycloak (Phase 2) se fasse en changeant une ligne de config, sans toucher aux routes.

**Acceptance Criteria :**

**Given** l'interface `app/Contracts/Auth/AuthGuardInterface.php` est définie
**When** le middleware `sambaedu.auth` s'exécute
**Then** il délègue à `SambaEduAuthGuard` (implémentation active configurée)
**And** `KeycloakAuthGuard` existe en stub commenté dans `app/Auth/` pour Phase 2
**And** le swap d'implémentation se fait via la config, zéro modification de routes
**And** le comportement auth actuel (login LDAP existant) est identique à l'avant

---

## Epic 1bis : Cloisonnement Legacy

*Les modules PHP legacy sont intégrés dans un sous-dossier `legacy/` de SER, avec des shims LDAP→Eloquent et MySQL→Eloquent. Un error logger unifié capture toutes les erreurs (legacy + Laravel) pour faciliter le développement. Les utilisateurs accèdent aux modules non encore réécrits sans rupture fonctionnelle.*

### Story 1bis.1 : Error Logger & Module Dashboard ✅

As a **développeur**,
I want un handler global qui capture toutes les erreurs (legacy PHP + exceptions Laravel), les log en DB, et les affiche dans un module du dashboard admin,
So that l'équipe dispose d'un outil de diagnostic unifié dès le début de l'epic pour surveiller les erreurs pendant l'intégration legacy.

**Acceptance Criteria :**

**Given** une erreur PHP survient dans un module legacy (warning, error, exception)
**When** le handler global l'intercepte
**Then** l'erreur est loggée en DB avec datetime et message (sans stack trace)
**And** l'erreur est identifiée comme provenant du legacy (source: legacy)

**Given** une exception Laravel survient
**When** le handler global l'intercepte
**Then** l'erreur est loggée en DB avec datetime et message (sans stack trace)
**And** l'erreur est identifiée comme provenant de Laravel (source: laravel)

**Given** des erreurs ont été loggées
**When** je consulte le module error logger dans `/admin/`
**Then** je vois la liste des erreurs avec datetime, source (legacy/laravel) et message
**And** je peux filtrer par source
**And** la page est accessible uniquement aux admins SER (gate Spatie)

> **Note :** Complémentaire à GlitchTip (prévu à terme). Cet outil de dev n'est pas nécessairement conservé en production finale.

---

### Story 1bis.2 : Bootstrap & Shim LDAP→Eloquent ✅

As a **développeur**,
I want un `bootstrap.php` qui initialise la session Laravel et l'autoload pour les modules legacy, un `config.inc.php` qui fait le pont vers la config Laravel, et un `ldap.inc.php` shim qui redirige les appels LDAP vers Eloquent,
So that les modules legacy peuvent tourner dans le contexte Laravel sans modification de leur code interne, en lisant les données depuis PostgreSQL via Eloquent au lieu de l'AD.

**Acceptance Criteria :**

**Given** un module legacy est chargé via le bootstrap
**When** le bootstrap s'exécute
**Then** la session Laravel est initialisée (auth, config, autoload disponibles)
**And** `config.inc.php` expose les variables de configuration legacy en les lisant depuis la config Laravel
**And** le module legacy peut fonctionner sans inclure ses propres fichiers d'initialisation

**Given** un module legacy appelle une fonction LDAP shimmée (ex: recherche d'utilisateurs, lecture d'attributs)
**When** la fonction shim est exécutée
**Then** elle retourne les données depuis Eloquent/PostgreSQL dans le format attendu par le code legacy
**And** aucun appel LDAP réel n'est effectué

**Given** chaque fonction shim est implémentée
**When** les tests PHPUnit s'exécutent sur données réelles
**Then** chaque fonction retourne un résultat cohérent avec ce que retournait l'appel LDAP original

**Given** une fonction LDAP non shimmée est appelée par un module legacy
**When** l'appel est intercepté
**Then** une erreur explicite est loggée (via le error logger story 1bis.1) identifiant la fonction manquante

---

### Story 1bis.3 : Shim SQL MySQL→Eloquent ✅

As a **développeur**,
I want remplacer les appels `mysqli_*` dans les modules legacy par des appels Eloquent, en s'appuyant sur les modèles Laravel existants,
So that les modules legacy accèdent à PostgreSQL via Eloquent sans conserver de dépendance MySQL.

**Acceptance Criteria :**

**Given** un module legacy utilise des appels `mysqli_*` (principalement `wpkg_libsql.php`)
**When** le shim SQL est en place
**Then** les appels sont redirigés vers les modèles Eloquent existants (`Application`, `Depot`, `Workstation`…)
**And** les résultats sont retournés dans le format attendu par le code legacy

**Given** les modèles Eloquent couvrent les tables utilisées par le legacy
**When** les tests PHPUnit s'exécutent
**Then** chaque requête shimmée retourne des données cohérentes avec le schéma PostgreSQL

**Given** un appel SQL non couvert par un modèle Eloquent est détecté
**When** l'appel est intercepté
**Then** une erreur explicite est loggée (via le error logger story 1bis.1)

---

### Story 1bis.4 : Module `display` ✅

> **Tier 1** — 4 fichiers PHP + assets CSS/JS/IMG. Aucun appel LDAP, aucun exec, aucun SQL.

As a **développeur**,
I want intégrer le module legacy `display` dans `legacy/modules/`,
So that l'écran d'accueil legacy est accessible via le catchall et valide le mécanisme de cloisonnement de base.

**Acceptance Criteria :**

**Given** le module `display` est copié dans `legacy/modules/display/`
**When** j'accède à l'URL `/display/` via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** l'écran d'accueil s'affiche avec les assets (CSS/JS/images)

**Given** le module utilise `functions.inc.php`, `traitement_data.inc.php`, `ihm.inc.php`, `display.inc.php`
**When** ces includes sont résolus
**Then** ils sont chargés depuis `sambaedu/includes/` via l'include_path (stubs prépendés)

**Given** le module utilise Guzzle et APCU
**When** ces dépendances sont appelées
**Then** Guzzle est résolu via l'autoloader Composer Laravel
**And** APCU est disponible (extension PHP chargée)

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente n'est présente pour `display`

---

### Story 1bis.5 : Module `oauth2` ✅

> **Tier 1** — 2 fichiers PHP (login.php, callback.php). Utilise `search_ad()` via shim LDAP, League\OAuth2\Client.

As a **développeur**,
I want intégrer le module legacy `oauth2` dans `legacy/modules/`,
So that le provider OAuth2 legacy est fonctionnel via le shim LDAP.

**Acceptance Criteria :**

**Given** le module `oauth2` est copié dans `legacy/modules/oauth2/`
**When** j'accède à l'URL `/oauth2/login.php` via le catchall
**Then** le module se charge sans erreur PHP fatale

**Given** le module appelle `search_ad()` (shim LDAP, story 1bis.2)
**When** la recherche est exécutée
**Then** les données sont lues depuis Eloquent/PostgreSQL via le shim
**And** le format de retour est compatible avec le code legacy

**Given** le module utilise League\OAuth2\Client
**When** la librairie est requise
**Then** elle est résolue via l'autoloader Composer Laravel

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente n'est présente pour `oauth2`

---

### Story 1bis.6 : Modules `sso` + `cas` ✅

> **Tier 1** — sso (3 fichiers : cas.php, oauth2.php, openid.php) + cas (2 fichiers : cas.php, ent.php). Même stack phpCAS + mêmes fonctions LDAP shimmées. Regroupés car imbriqués fonctionnellement.

As a **développeur**,
I want intégrer les modules legacy `sso` et `cas` dans `legacy/modules/`,
So that les mécanismes d'authentification SSO/CAS legacy sont opérationnels via le shim LDAP.

**Acceptance Criteria :**

**Given** les modules `sso` et `cas` sont copiés dans `legacy/modules/sso/` et `legacy/modules/cas/`
**When** j'accède aux URLs `/sso/cas.php`, `/sso/oauth2.php`, `/cas/cas.php`, `/cas/ent.php` via le catchall
**Then** chaque fichier se charge sans erreur PHP fatale

**Given** les modules appellent `search_user()` et `have_right()` (shim LDAP, story 1bis.2)
**When** ces fonctions sont exécutées
**Then** les données sont lues depuis Eloquent/PostgreSQL via le shim
**And** les résultats sont compatibles avec la logique d'authentification legacy

**Given** les modules utilisent phpCAS et League\OAuth2\Client
**When** ces librairies sont requises
**Then** elles sont résolues via l'autoloader Composer Laravel

**Given** les modules sont intégrés
**When** le error logger est consulté
**Then** aucune erreur récurrente n'est présente pour `sso` et `cas`

---

### Story 1bis.7 : Module `api` ✅

> **Tier 1** — 1 fichier PHP (ecowatt.php). Dépendance minimale : power.inc.php uniquement. Aucun LDAP, aucun exec, aucun SQL.

As a **développeur**,
I want intégrer le module legacy `api` dans `legacy/modules/`,
So que l'ancien endpoint API (ecowatt) est accessible via le catchall.

**Acceptance Criteria :**

**Given** le module `api` est copié dans `legacy/modules/api/`
**When** j'accède à l'URL `/api/ecowatt.php` via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** retourne un résultat cohérent

**Given** le module inclut `power.inc.php`
**When** l'include est résolu
**Then** il est chargé depuis `sambaedu/includes/` via l'include_path

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente n'est présente pour `api`

---

### Story 1bis.8 : Module `user` ❌ ANNULÉE

> **Tier 1** — 1 fichier PHP (index.php). Le plus riche en utilisation du shim LDAP : `search_user()`, `search_machine()`, `is_eleve()`, `user_valid_passwd()`. Nombreux includes (functions.inc.php, fonc_outils.inc.php, partages.inc.php, samba.inc.php, ihm.inc.php, user.interface.inc.php, cloud.inc.php).

As a **développeur**,
I want intégrer le module legacy `user` dans `legacy/modules/`,
So que la page profil utilisateur legacy est accessible et valide le shim LDAP sur un module riche en appels.

**Acceptance Criteria :**

**Given** le module `user` est copié dans `legacy/modules/user/`
**When** j'accède à l'URL `/user/` via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** la page profil s'affiche avec les données utilisateur

**Given** le module appelle `search_user()`, `search_machine()`, `is_eleve()`, `user_valid_passwd()` (shim LDAP, story 1bis.2)
**When** ces fonctions sont exécutées
**Then** les données sont lues depuis Eloquent/PostgreSQL via le shim
**And** les résultats sont cohérents (infos utilisateur, droits, machines)

**Given** le module inclut de nombreux fichiers legacy (fonc_outils, partages, samba, ihm, user.interface, cloud)
**When** ces includes sont résolus
**Then** ils sont chargés depuis `sambaedu/includes/` sans conflit avec les stubs

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente n'est présente pour `user`

---

### Story 1bis.9 : Module `dossier_echange` ✅

> **Tier 1** — 1 fichier PHP (dossier_echange.php). Pas de LDAP ni SQL, mais contient un appel `system("/usr/bin/sudo /tmp/partages.sh")` — cas isolé d'exec système.

As a **développeur**,
I want intégrer le module legacy `dossier_echange` dans `legacy/modules/`,
So que le dossier d'échange est accessible, avec l'exec système encadré.

**Acceptance Criteria :**

**Given** le module `dossier_echange` est copié dans `legacy/modules/dossier_echange/`
**When** j'accède à l'URL `/dossier_echange/` via le catchall
**Then** le module se charge sans erreur PHP fatale

**Given** le module contient un appel `system("/usr/bin/sudo /tmp/partages.sh")`
**When** l'exec est appelé
**Then** si le script et les droits sudo sont configurés, la commande s'exécute
**And** si le script n'existe pas ou les droits manquent, l'erreur est capturée par le error logger (pas de crash fatal)

**Given** le module inclut functions.inc.php, traitement_data.inc.php, admin_ui.inc.php, ihm.inc.php, samba.inc.php, partages.inc.php, fonc_outils.inc.php
**When** ces includes sont résolus
**Then** ils sont chargés correctement (stubs pour admin_ui, legacy includes pour le reste)

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente bloquante n'est présente pour `dossier_echange`

---

### Story 1bis.10 : Module `ipxe` ✅

> **Tier 2** — 72 fichiers (dont 39 .cfg de configuration réseau). LDAP limité à `ldap_dn2oudn()` + `move_ad()` via wrappers → shim suffisant. 4 exec dans `Win10/win_iso.php` (montage ISO) et `boot.php` (check paquet). Surtout du templating (preseed, unattend.xml, scripts iPXE).

As a **développeur**,
I want intégrer le module legacy `ipxe` dans `legacy/modules/`,
So que le boot réseau PXE est opérationnel via le cloisonnement, prérequis pour tester wpkg manuellement.

**Acceptance Criteria :**

**Given** le module `ipxe` est copié dans `legacy/modules/ipxe/`
**When** j'accède aux URLs principales du module via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** les pages de configuration iPXE s'affichent correctement

**Given** le module utilise les fonctions LDAP wrapper `ldap_dn2oudn()` et `move_ad()` (shim LDAP, story 1bis.2)
**When** ces fonctions sont appelées
**Then** les données sont lues depuis Eloquent/PostgreSQL via le shim

**Given** le module contient 4 exec système dans `Win10/win_iso.php` (montage ISO) et `boot.php` (check paquet)
**When** un exec est appelé
**Then** la commande s'exécute correctement dans le contexte Laravel
**And** toute erreur est capturée par le error logger

**Given** les fichiers .cfg (preseed, unattend.xml, scripts iPXE) sont présents
**When** le module génère une configuration de boot
**Then** le templating produit un résultat cohérent

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente bloquante n'est présente pour `ipxe`

---

### Story 1bis.11 : Module `wpkg`

> **Tier 2 — module CRITIQUE** — 49 fichiers PHP, 0 LDAP direct, 0 exec actif (le « 1 exec » initial est un `curl_exec` commenté), 0 SQL direct (tout passe par `wpkg_libsql.php` shim, story 1bis.3). Modèles Laravel `Application`, `Depot`, `Workstation`, `WorkstationGroup`, `AppProfile`, `AppStore` déjà existants côté reload (couche relationnelle).
>
> **Surface réelle bien plus large que la couche SQL** — le module héberge aussi : (1) le moteur de génération XML servi au client `wpkg-client.vbs` (`hosts_xml_out`, `profiles_xml_out`, `packages_xml_out`), (2) le CRUD du catalogue `packages.xml` (fichier disque + binaires), (3) le synchronizer de dépôts distants (Guzzle), (4) MEF (templates d'agencement), (5) la sync DB↔AD (`wpkg_ldap_update.php`), (6) le garbage collector FK, (7) la lecture des rapports d'exécution sur partage Samba, (8) la gestion des ACLs Samba, (9) les options .ini par poste, (10) sorties alternatives Linux/Winget, (11) helpers métier `wpkg_lib.php` / `wpkg_lib_admin.php`.
>
> **Décision d'archi** : la story 1bis.11 reste un **cloisonnement legacy pur** (pas de réécriture native). Les générateurs XML sont servis tels quels via le catchall en mode raw (Content-Type detection). La réécriture native du moteur, la migration de `packages.xml` en base, l'object store binaires, et la refonte du flux rapports sont **renvoyés à l'epic 9**.
>
> **Méthode imposée : TDD intégral à 3 niveaux** — (N1) contrat du shim `wpkg_libsql.php` fonction par fonction, (N2) test HTTP par page (49 fichiers), (N3) workflows métier de bout en bout (import dépôt, parc + applis, profil + XML, enregistrement poste + maintenance, MEF, suppression cascade). Référence comportementale = `sambaedu/includes/wpkg_libsql.php` (1977 l, original mysqli) et le code legacy original. Aucun fichier modifié sans test rouge préalable.
>
> Dépend d'ipxe (story 1bis.10, ✅ done) pour les tests manuels du parcours complet boot → install.

As a **développeur**,
I want intégrer le module legacy `wpkg` dans `legacy/modules/`,
So que la gestion des packages WPKG est opérationnelle via les shims SQL et LDAP.

**Acceptance Criteria :**

**Given** le module `wpkg` est copié dans `legacy/modules/wpkg/`
**When** j'accède aux URLs principales du module via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** la liste des applications, dépôts et profils s'affiche

**Given** le module utilise intensivement `wpkg_libsql.php` (shim SQL, story 1bis.3)
**When** les fonctions SQL shimmées sont appelées
**Then** les données sont lues depuis PostgreSQL via Eloquent
**And** les résultats sont compatibles avec le format attendu par le code legacy wpkg

**Given** le module contient 1 exec système
**When** l'exec est appelé
**Then** la commande s'exécute correctement
**And** toute erreur est capturée par le error logger

**Given** le module utilise les modèles `Application`, `Depot` qui existent déjà côté reload
**When** les données sont accédées
**Then** il n'y a pas de conflit de schéma entre le shim et les modèles natifs

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente bloquante n'est présente pour `wpkg`

---

### Story 1bis.12 : Module `annu2` ❌ ANNULÉE

> **🚫 CANCELLED 2026-04-17 — Deferred vers Epic 2** *(cf. sprint-change-proposal-2026-04-17 + idempotency.md § 8)*
>
> Audit : 20 / 22 fichiers sont des wrappers HTML < 1 KB, seule la logique métier réelle est dans `profiles.php` (5.2 KB, gestion bitmask droits) et `pass_user_init.php` (réinit mdp bulk). La base Laravel est déjà présente à ≥ 70% (`/users/*`, `/users/new`, `/users/{login}`, `/users/groups/{id}`, `/rights-management`, `UserService`, `UserGroupService`, `PermissionService`, Spatie Permissions). Shimmer = code jeté immédiatement.
>
> **Nouvelles stories à créer dans Epic 2** via `bmad-create-story` :
> - Epic 2 — Profils de droits Spatie (migration du bitmask legacy → `spatie/laravel-permission`)
> - Epic 2 — Réinit mdp bulk depuis `/users` (sélection multiple)
> - Epic 2 — Completion `/users/new` pour les cas résiduels (imports masse)
>
> *Les ACs ci-dessous sont **conservés à titre historique** — ne pas implémenter.*

> **Tier 2** — 22 fichiers PHP. Refonte UI de l'annuaire, partiellement migré dans reload (`/app/users`, `/app/users/groups/*`, `/app/rights-management`). `profiles.php` (gestion profils de droits/bitmask) reste à migrer en Laravel avec Spatie Permissions. Pas de LDAP direct ni SQL direct.

As a **développeur**,
I want intégrer le module legacy `annu2` dans `legacy/modules/`,
So que les fonctionnalités annuaire non encore migrées (profils de droits, réinit mdp en masse, imports) restent accessibles.

**Acceptance Criteria :**

**Given** le module `annu2` est copié dans `legacy/modules/annu2/`
**When** j'accède aux URLs du module via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** les pages non migrées (profils, imports) s'affichent correctement

**Given** les fonctionnalités déjà migrées dans reload (/app/users, /app/users/groups/*, /app/rights-management) coexistent
**When** un utilisateur accède aux URLs migrées
**Then** les routes Laravel prennent la priorité sur le catchall (pas de conflit)

**Given** le module utilise des fonctions LDAP wrapper (via includes)
**When** ces fonctions sont appelées
**Then** les données sont lues via le shim LDAP depuis Eloquent/PostgreSQL

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente bloquante n'est présente pour `annu2`

---

### Story 1bis.13 : Modules `parcs2` + `acls` — **SPLITTÉE le 2026-04-17** ❌ ANNULÉE

> **🔀 SPLIT 2026-04-17** *(cf. sprint-change-proposal-2026-04-17 + idempotency.md § 8)*
>
> La story unique est scindée en deux :
>
> - **1bis.13a `parcs2`** → **🚫 CANCELLED, deferred vers Epic 4** (BUILD direct). Audit : `WorkstationService` (WOL/stop/reboot/`getMachinesByParc`/`importFromAd`), `WorkstationGroupService`, `MachinePowerService`, `RemoteAccessService`, `ParcController` (mass-action, import/export CSV, shortcuts, applications, API hiérarchie) et les pages `/parc`, `/parc/groups/{id}`, `/parc/machines/{id}` couvrent déjà ≥ 70% du module. Reste à compléter dans Epic 4 : délégations salle (`delegate_parc.php` 18 KB), historique actions (`show_histo`), UI `/parc/groups/new` (route pas encore implémentée — le JS legacy de `wolstop_station.php` 27 KB est largement remplacé par `WorkstationService`). **Nouvelles stories à créer dans Epic 4** via `bmad-create-story`.
>
> - **1bis.13b `acls`** → **🚫 CANCELLED 2026-04-17 — module obsolète (confirmé par Henri).** Defer vers **Epic 5 (FR13-16)** : la refonte ACLs POSIX/Windows native absorbera le besoin lors du sprint Epic 5 — pas de shim intermédiaire à livrer.
>
> **Les ACs ci-dessous sont obsolètes** (ni 1bis.13a parcs2 ni 1bis.13b acls ne seront implémentés dans Epic 1bis). Conservés à titre historique uniquement.

> **Tier 2** — parcs2 (6 fichiers), acls (6 fichiers, 3 exec `samba-tool`). Regroupés car acls est un sous-module de la gestion des parcs. parcs2 est partiellement migré (modèles `Workstation`, `WorkstationGroup` existants côté reload). acls nécessite l'accès au binaire `samba-tool`.

As a **développeur**,
I want intégrer les modules legacy `parcs2` et `acls` dans `legacy/modules/`,
So que la gestion des parcs v2 et les ACLs Samba sont accessibles via le cloisonnement.

**Acceptance Criteria :**

**Given** les modules `parcs2` et `acls` sont copiés dans `legacy/modules/parcs2/` et `legacy/modules/acls/`
**When** j'accède aux URLs des modules via le catchall
**Then** les modules se chargent sans erreur PHP fatale

**Given** `parcs2` utilise des fonctions LDAP wrapper
**When** ces fonctions sont appelées
**Then** les données sont lues via le shim LDAP depuis Eloquent/PostgreSQL

**Given** `acls` contient 3 exec `samba-tool` pour la gestion des ACLs Samba
**When** un exec est appelé
**Then** la commande s'exécute si `samba-tool` est accessible dans le contexte Laravel
**And** toute erreur est capturée par le error logger

**Given** les modèles `Workstation`, `WorkstationGroup` existent déjà côté reload
**When** les données des parcs sont accédées via le shim
**Then** il n'y a pas de conflit de schéma

**Given** les modules sont intégrés
**When** le error logger est consulté
**Then** aucune erreur récurrente bloquante n'est présente pour `parcs2` et `acls`

---

### Story 1bis.14 : Module `partages` ⏸️ PAUSED

> **⚡ SHIM EXPRESS ~1h — révision 2026-04-17** *(cf. sprint-change-proposal-2026-04-17 + idempotency.md § 8)*
>
> Audit : `have_right` (2 occ) déjà shimmé, **0 exec système**, 0 SQL direct. Scope révisé = `cp -r sambaedu/partages sambaedu-reload/legacy/modules/partages/` + smoke test via catchall. Refonte native ensuite dans **Epic 5 (FR13-16)**.

> **Tier 2** — 4 fichiers PHP. Gestion des partages réseau. Appels Samba via includes. Pas de LDAP direct ni SQL direct, mais interaction avec le système de fichiers et les partages Samba.

As a **développeur**,
I want intégrer le module legacy `partages` dans `legacy/modules/`,
So que la gestion des partages réseau legacy est accessible via le cloisonnement.

**Acceptance Criteria :**

**Given** le module `partages` est copié dans `legacy/modules/partages/`
**When** j'accède aux URLs du module via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** la liste des partages s'affiche

**Given** le module interagit avec Samba via les includes legacy (samba.inc.php, partages.inc.php)
**When** les fonctions de gestion de partages sont appelées
**Then** elles fonctionnent correctement dans le contexte Laravel

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente bloquante n'est présente pour `partages`

---

### Story 1bis.15 : Module `printers` ✅

> **⚡ SHIM EXPRESS ~3h — révision 2026-04-17** *(cf. sprint-change-proposal-2026-04-17 + idempotency.md § 8)*
>
> Audit : `have_right` (14 occ) déjà shimmé, 0 SQL direct, **4 exec CUPS** (`lpadmin` + cmds CUPS). Scope révisé = `cp + smoke test` + validation exec en VM (nécessite `cups-pdf` installé, déjà prévu comme imprimante virtuelle par Epic 6). Refonte native ensuite dans **Epic 6 (FR17-19)**, qui supprimera ce shim.

> **Tier 3** — 11 fichiers PHP, 0 LDAP direct, 4 exec (`lpadmin`, commandes CUPS). Gestion des imprimantes via CUPS.

As a **développeur**,
I want intégrer le module legacy `printers` dans `legacy/modules/`,
So que la gestion des imprimantes CUPS est accessible via le cloisonnement.

**Acceptance Criteria :**

**Given** le module `printers` est copié dans `legacy/modules/printers/`
**When** j'accède aux URLs du module via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** la liste des imprimantes s'affiche

**Given** le module contient 4 exec système (`lpadmin` et commandes CUPS)
**When** un exec est appelé
**Then** la commande s'exécute si les binaires CUPS sont accessibles et les droits sudo configurés
**And** toute erreur est capturée par le error logger

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente bloquante n'est présente pour `printers`

---

### Story 1bis.16 : Module `dhcp` ✅

> **⚡ SHIM EXPRESS ~2h — révision 2026-04-17** *(cf. sprint-change-proposal-2026-04-17 + idempotency.md § 8)*
>
> Audit : `have_right` + `search_machine` (4 occ au total) déjà shimmés, 0 SQL direct, **2 exec sur fichiers de config DHCP**. Scope révisé = `cp + smoke test` + validation exec. Refonte native ensuite dans **Epic 8 (FR20-22)**, qui supprimera ce shim.

> **Tier 3** — 6 fichiers PHP, 0 LDAP direct, 2 exec sur fichiers de configuration. Gestion des réservations DHCP et baux.

As a **développeur**,
I want intégrer le module legacy `dhcp` dans `legacy/modules/`,
So que la gestion DHCP legacy est accessible via le cloisonnement.

**Acceptance Criteria :**

**Given** le module `dhcp` est copié dans `legacy/modules/dhcp/`
**When** j'accède aux URLs du module via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** la page de gestion DHCP s'affiche

**Given** le module contient 2 exec sur fichiers de configuration DHCP
**When** un exec est appelé
**Then** la commande s'exécute si les fichiers conf et les droits sont en place
**And** toute erreur est capturée par le error logger

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente bloquante n'est présente pour `dhcp`

---

### Story 1bis.17 : Module `bbb` ✅

> **⚡ SHIM EXPRESS ~2h — révision 2026-04-17** *(cf. sprint-change-proposal-2026-04-17 + idempotency.md § 8)*
>
> Audit : `have_right` + `search_user` + `is_eleve` (7 occ) déjà shimmés, 0 SQL direct, 0 exec système (seule l'API externe BigBlueButton). Décision Henri : shim confirmé (vs BUILD 2j initialement envisagé) — le shim est quasi-gratuit grâce à la couverture LDAP existante. Pas d'Epic dédié à BBB pour l'instant ; la refonte native sera un micro-epic ou une story transverse à programmer ultérieurement selon l'usage.

> **Tier 3** — 6 fichiers PHP, 3 fichiers avec appels LDAP pour récupérer users/groupes, 0 exec. Intégration BigBlueButton.

As a **développeur**,
I want intégrer le module legacy `bbb` dans `legacy/modules/`,
So que l'intégration BigBlueButton legacy est accessible via le cloisonnement.

**Acceptance Criteria :**

**Given** le module `bbb` est copié dans `legacy/modules/bbb/`
**When** j'accède aux URLs du module via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** la page BBB s'affiche

**Given** le module contient 3 fichiers avec des appels LDAP (récupération users/groupes)
**When** ces fonctions LDAP sont appelées
**Then** toutes les fonctions nécessaires sont couvertes par le shim LDAP (story 1bis.2)
**And** les données retournées sont cohérentes (listes d'utilisateurs et de groupes)

**Given** le module interagit avec l'API BigBlueButton
**When** l'API est appelée
**Then** la connexion fonctionne si le serveur BBB est configuré
**And** toute erreur de connexion est capturée par le error logger

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente bloquante n'est présente pour `bbb`

---

### Story 1bis.18 : Module `gpo` (décomposée en 6 sous-stories)

> **Tier 3** — 16 fichiers PHP (hors shortcuts, déjà migrés en Laravel), 3 fichiers LDAP, 2 exec `samba-tool`. Gestion des GPO Windows. Les fichiers shortcuts (`shortcuts.php`, `shortcuts_out.php`, `shortcuts.inc.php`) ne sont **pas** cloisonnés : Laravel gère déjà les raccourcis via `ShortcutExportController` (route `gpo/shortcuts_out.php` interceptée) et `ShortcutsService` (stockage DB PostgreSQL). Le `GpoSyncService` existant appelle les fonctions legacy `add_delegation_salle`/`remove_delegation_salle` via `function_exists()` — une fois les includes chargés (story 18a), ce chemin sera débloqué.

---

#### Story 1bis.18a : Includes GPO core (fondation) ✅

> Prérequis pour toutes les sous-stories suivantes. Rendre disponibles les bibliothèques de fonctions GPO dans le contexte legacy.

**Fichiers :** `includes/gpo.inc.php` (1423 lignes), `includes/samba-tool.inc.php` (1396 lignes), `includes/delegations.inc.php` (373 lignes), `includes/gpo_ui.inc.php` (76 lignes)

As a **développeur**,
I want rendre les includes GPO core accessibles dans le contexte legacy cloisonné,
So que toutes les fonctions GPO (manipulation SYSVOL, samba-tool, délégations) sont disponibles pour les pages du module et pour le `GpoSyncService` Laravel.

**Acceptance Criteria :**

**Given** les fichiers includes sont chargés dans le contexte legacy
**When** j'appelle `function_exists('read_pol')`
**Then** la fonction est disponible (gpo.inc.php chargé)

**Given** les fichiers includes sont chargés dans le contexte legacy
**When** j'appelle `function_exists('gpocreate')`
**Then** la fonction est disponible (samba-tool.inc.php chargé)

**Given** les fichiers includes sont chargés dans le contexte legacy
**When** j'appelle `function_exists('add_delegation_salle')`
**Then** la fonction est disponible (delegations.inc.php chargé)
**And** le `GpoSyncService` Laravel utilise le chemin legacy au lieu du fallback samba-tool vide

**Given** les fonctions LDAP sont appelées par delegations.inc.php (`search_ad`, `ldap_add`, `ldap_delete`, `modify_ad`)
**When** ces fonctions sont exécutées
**Then** elles sont couvertes par le shim LDAP existant

**Given** les includes sont chargés
**When** le error logger est consulté
**Then** aucune erreur fatale au chargement

---

#### Story 1bis.18b : Interface gestion GPO (import/export) ✅

> Page principale de gestion des GPO : consultation de la liste, import de templates, export.

**Fichiers :** `gpo/gestion_gpo.php` (69 lignes), `gpo/gpo-maj.php` (193 lignes), `gpo/gpo-export.php` (88 lignes)

**Dépendances :** Story 1bis.18a

As a **développeur**,
I want intégrer les pages de gestion GPO (consultation, import, export) dans le cloisonnement,
So que les administrateurs peuvent gérer les GPO Windows via l'interface legacy.

**Acceptance Criteria :**

**Given** le module GPO est accessible via le catchall
**When** j'accède à `gestion_gpo.php`
**Then** la page menu s'affiche sans erreur fatale
**And** les liens vers import et export sont fonctionnels

**Given** la page `gpo-maj.php` est chargée
**When** la liste des templates est affichée
**Then** `list_gpo_templates()` retourne les templates disponibles depuis `/usr/share/sambaedu/gpo/`
**And** le statut d'import de chaque GPO est cohérent

**Given** un template GPO est sélectionné pour import
**When** `import_gpo()` est exécuté
**Then** la GPO est créée dans l'AD via `gpocreate()` (samba-tool exec)
**And** les fichiers sont spécialisés et poussés dans le SYSVOL via SMB

**Given** une GPO existante est sélectionnée pour export
**When** `export_gpo()` est exécuté
**Then** une archive ZIP est générée et proposée en téléchargement
**And** les fichiers sont généralisés (placeholders) avant export

---

#### Story 1bis.18c : Configuration applications (Firefox, Thunderbird) ❌ ANNULÉE

> Pages de configuration des navigateurs et applications déployées via GPO.

**Fichiers :** `gpo/gestion_apps.php` (48 lignes), `gpo/firefox.php` (107 lignes), `gpo/firefox_out.php` (16 lignes), `gpo/thunderbird_out.php` (14 lignes), `includes/firefox.inc.php` (292 lignes)

**Dépendances :** Story 1bis.18a

As a **développeur**,
I want intégrer les pages de configuration Firefox/Thunderbird dans le cloisonnement,
So que les administrateurs peuvent gérer la configuration des navigateurs via GPO.

**Acceptance Criteria :**

**Given** la page `gestion_apps.php` est accessible via le catchall
**When** j'y accède
**Then** la page menu applications s'affiche sans erreur fatale

**Given** la page `firefox.php` est chargée
**When** la configuration Firefox actuelle est lue
**Then** la page d'accueil, les marque-pages et les extensions s'affichent
**And** la modification de la configuration se propage dans les GPO

**Given** un poste appelle `firefox_out.php` avec un OS et un ID
**When** l'endpoint retourne la réponse
**Then** le contenu est du JSON valide (policies Firefox)

**Given** un poste appelle `thunderbird_out.php`
**When** l'endpoint retourne la réponse
**Then** le contenu est du JSON valide (policies Thunderbird)

---

#### Story 1bis.18d : Fond d'écran et personnalisation visuelle ❌ ANNULÉE

> Gestion des wallpapers, lock screens et éléments visuels déployés sur les postes.

**Fichiers :** `gpo/wallpaper.php` (46 lignes), `gpo/wallpaper_out.php` (48 lignes), `includes/wallpaper.inc.php` (364 lignes)

**Dépendances :** Story 1bis.18a

As a **développeur**,
I want intégrer les pages de gestion wallpaper dans le cloisonnement,
So que les administrateurs peuvent gérer les fonds d'écran et lock screens via l'interface legacy.

**Acceptance Criteria :**

**Given** la page `wallpaper.php` est accessible via le catchall
**When** j'y accède
**Then** les images actuelles (logo, wallpaper, lockscreen) s'affichent

**Given** un nouveau wallpaper est uploadé
**When** le fichier est traité
**Then** l'image est sauvegardée au bon emplacement

**Given** un poste appelle `wallpaper_out.php` avec une action (lockscreen, wallpaper, icone)
**When** l'endpoint retourne la réponse
**Then** l'image est servie au format correct (JPEG ou PNG)

---

#### Story 1bis.18e : Scripts réseau, Veyon, Wine, associations

> Modules "output" qui génèrent des scripts et configurations pour les postes clients.

**Fichiers :** `gpo/network_out.php` (54 lignes), `gpo/veyon_out.php` (141 lignes), `gpo/wine.php` (79 lignes), `gpo/applications.php` (51 lignes), `gpo/associations_out.php` (173 lignes), `includes/network.inc.php` (172 lignes)

**Dépendances :** Story 1bis.18a ; `associations_out.php` dépend aussi du module `wpkg` (story 1bis.11)

As a **développeur**,
I want intégrer les endpoints de scripts réseau, Veyon, Wine et associations dans le cloisonnement,
So que les postes clients reçoivent leurs configurations réseau, Veyon et associations de fichiers.

**Acceptance Criteria :**

**Given** un poste appelle `network_out.php` avec une action (startup, logon) et un OS
**When** l'endpoint retourne la réponse
**Then** le script généré est valide (bash pour Linux, .cmd pour Windows)

**Given** un poste appelle `veyon_out.php`
**When** l'endpoint retourne la réponse
**Then** la configuration Veyon est du JSON valide
**And** les salles et niveaux d'accès sont cohérents

**Given** la page `wine.php` est accessible via le catchall
**When** j'y accède
**Then** la gestion des préfixes Wine s'affiche sans erreur fatale

**Given** un poste appelle `associations_out.php`
**When** les associations de fichiers sont calculées
**Then** les définitions WPKG sont correctement fusionnées (défaut + distribution + custom)
**And** la hiérarchie user/group/machine/parc est respectée

**Given** la page `applications.php` est accessible
**When** j'y accède avec un contexte (user, group, machine, parc)
**Then** le script d'application est généré pour le bon OS

---

#### Story 1bis.18f : Profils itinérants (roaming)

> Gestion des exclusions de profils itinérants et nettoyage des profils volumineux.

**Fichiers :** `gpo/no_roam.php` (65 lignes), `gpo/del_roam.php` (27 lignes), `gpo/user_profile_stats.php` (32 lignes)

**Dépendances :** Story 1bis.18a (gpo.inc.php, gpo_ui.inc.php) ; nécessite aussi `partages.inc.php`

As a **développeur**,
I want intégrer les pages de gestion des profils itinérants dans le cloisonnement,
So que les administrateurs peuvent gérer les exclusions de roaming et visualiser les stats de profils.

**Acceptance Criteria :**

**Given** la page `no_roam.php` est accessible via le catchall
**When** j'y accède
**Then** les exclusions actuelles de profils itinérants s'affichent (ExcludeProfileDirs)
**And** les statistiques de taille de profils sont visibles

**Given** une modification des exclusions est soumise
**When** la GPO ExcludeProfileDirs est mise à jour
**Then** la modification est écrite dans le SYSVOL via `update_gpo_sysvol()`
**And** la version GPO est incrémentée

**Given** la page `user_profile_stats.php` est accessible
**When** j'y accède
**Then** le détail par utilisateur des tailles de profils s'affiche

**Given** la page `del_roam.php` est appelée
**When** le script de nettoyage est généré
**Then** le script bash est valide et cible les bons répertoires de profils

---

### Story 1bis.19 : Module `infos`

> **⚡ SHIM EXPRESS ~3h + split secondaire — révision 2026-04-17** *(cf. sprint-change-proposal-2026-04-17 + idempotency.md § 8)*
>
> Audit : `have_right` + `search_ad` + `search_user` + `search_group` (28 occ) déjà shimmés, 0 SQL direct, **7 exec système basiques** (`df`, `du`, `uname`, `uptime`…) à faible risque. Scope révisé = `cp + smoke test` + validation exec.
>
> **Split secondaire post-shim** (à faire une fois le SHIM EXPRESS livré, au fil des epics ciblés) :
> - `df.php`, `du.php`, `uname`/`uptime` → absorbés par `/app/dashboard`
> - `quota_fixer.php` (19.6 KB), `quota_visu.php`, `infomdp.php` → Epic 2 / Epic 5 (cohérence avec `QuotaService`, `QuotaAuditLog`, `PasswordService` existants)
> - `test_ldap.php` → outil admin `/admin/`
> - `fix_se4.php` → à analyser au moment du split (probable outil admin durable)

> **Tier 3** — 9 fichiers PHP, 1 fichier LDAP, 7 exec (`df`, `uname`, `uptime`, etc.). Module d'informations système. Le plus intensif en exec système.

As a **développeur**,
I want intégrer le module legacy `infos` dans `legacy/modules/`,
So que la page d'informations système est accessible via le cloisonnement.

**Acceptance Criteria :**

**Given** le module `infos` est copié dans `legacy/modules/infos/`
**When** j'accède aux URLs du module via le catchall
**Then** le module se charge sans erreur PHP fatale
**And** les informations système s'affichent

**Given** le module contient 7 exec système (`df`, `uname`, `uptime`, commandes d'info serveur)
**When** les exec sont appelés
**Then** les commandes s'exécutent correctement dans le contexte Laravel
**And** les résultats sont affichés de manière cohérente
**And** toute erreur est capturée par le error logger

**Given** le module contient 1 fichier avec des appels LDAP
**When** les fonctions LDAP sont appelées
**Then** elles sont couvertes par le shim LDAP
**And** les données retournées sont cohérentes

**Given** le module est intégré
**When** le error logger est consulté
**Then** aucune erreur récurrente bloquante n'est présente pour `infos`

> **Note :** Le module `central` (ex-Tier 3) est **retiré** du périmètre de cloisonnement — ses fonctionnalités (dashboard multi-sites, monitoring, provisioning) sont hors scope SER et traitées côté controlHub (irundoo).

---

## Epic 2 : Gestion des Utilisateurs SER

*Le responsable de collège peut créer, modifier et supprimer des comptes utilisateurs sans jamais manipuler l'AD. Les home directories et droits ACL sont provisionnés automatiquement. Les utilisateurs itinérants sont identifiés depuis l'AD et leurs droits appliqués automatiquement.*

### Story 2.1 : Création et Provisioning d'un Compte Utilisateur ✅

> **Prérequis — Investigation Legacy :** Analyser dans le legacy SambaEdu tout ce que déclenche la création d'un utilisateur (création home dir, structure de répertoires, ACLs initiales, groupes AD, quotas XFS, appartenance OU, scripts post-création…). Lister ce qui doit être réimplémenté, ce qui peut être simplifié, ce qui est obsolète. Mini-brainstorm avec Henri avant d'implémenter FR2.

As a **responsable de collège**,
I want créer un compte utilisateur (élève ou enseignant) et que le système provisionne automatiquement son home directory et ses droits ACL,
So that l'utilisateur peut se connecter et accéder à ses ressources immédiatement, sans manipulation AD ni intervention technique.

**Acceptance Criteria :**

**Given** je suis sur la page de création utilisateur
**When** je saisis prénom, nom, rôle (élève ou enseignant/équipe) et sélectionne une classe parmi les userGroups filtrés selon le rôle (groupes "élèves" pour un élève, groupes "équipe" pour un enseignant), puis je valide
**Then** le compte est créé dans l'AD local via LdapRecord en premier, puis persisté dans PostgreSQL
**And** le home directory est créé avec la structure attendue et les ACLs initiales correctes
**And** le quota XFS est appliqué selon le profil de l'utilisateur
**And** aucun concept AD (OU, DN, attribut LDAP) n'est exposé dans le formulaire
**And** un retour de succès explicite est affiché (WithToasts)
**And** l'action est loggée (NFR8)

**Given** l'utilisateur existe déjà dans l'AD mais son home directory est manquant
**When** il se connecte pour la première fois
**Then** le home directory est provisionné automatiquement à la connexion

---

### Story 2.2 : Modification des Attributs d'un Utilisateur ✅

> **Prérequis — Investigation Legacy :** Identifier quels changements d'attributs (classe, quota) déclenchent des effets de bord dans le legacy (recalcul ACLs partages de classe, mise à jour quota XFS, changement OU AD…). Décider ce qu'on réimplémente. Note : les impacts filesystem dépendent des Services développés en Epic 5 (Fichiers) — certains ACs peuvent être conditionnels à Epic 5.

As a **responsable de collège**,
I want modifier les attributs d'un utilisateur (classe, quota),
So que sa situation reflète la réalité scolaire et que ses accès aux ressources soient recalculés en conséquence.

**Acceptance Criteria :**

**Given** je consulte la fiche d'un utilisateur
**When** je modifie sa classe (sélection parmi les userGroups filtrés selon le rôle) et valide
**Then** la modification est écrite dans l'AD local via LdapRecord en premier, puis dans PostgreSQL
*(les ACLs partages de classe sont recalculées par Story 5.2)*

**Given** je modifie le quota d'un utilisateur
**When** je valide
**Then** la modification est persistée dans PostgreSQL uniquement (le quota XFS est un attribut système, pas AD)
**And** un retour de succès est affiché (WithToasts)
**And** l'action est loggée (NFR8)
*(la mise à jour XFS sur le filesystem est prise en charge par Story 5.1)*

---

### Story 2.3 : Désactivation et Suppression d'un Compte Utilisateur ✅

> **Prérequis — Investigation Legacy :** Analyser ce que le legacy fait lors de la désactivation et suppression (archivage home dir, retrait des groupes AD, nettoyage des ACLs, scripts associés…). Mini-brainstorm avec Henri avant d'implémenter.

As a **responsable de collège**,
I want désactiver ou supprimer un compte utilisateur avec archivage sécurisé de son home directory,
So que les données de l'utilisateur sont préservées dans un premier temps puis définitivement supprimables, conformément aux obligations RGPD.

**Acceptance Criteria :**

**Given** je désactive un compte utilisateur
**When** je confirme l'action
**Then** le compte est désactivé dans l'AD local en premier, puis dans PostgreSQL
**And** le home directory est déplacé dans `/home/trash/` (archivage — étape 1)
**And** l'utilisateur ne peut plus se connecter
**And** l'action est loggée (NFR8)

**Given** un home directory est dans `/home/trash/`
**When** je déclenche la suppression permanente
**Then** le home directory est supprimé définitivement du système de fichiers
**And** le compte est supprimé de l'AD local en premier, puis de PostgreSQL
**And** l'action est loggée avec horodatage (NFR8 — conformité RGPD)

**Given** je tente de supprimer définitivement sans passer par l'archivage
**When** je valide
**Then** l'action est refusée — la suppression en deux temps est obligatoire

---

### Story 2.4 : Affichage et Gestion du Statut Itinérant ✅

> **Prérequis — Vérification AD :** Vérifier la règle de détection itinérant dans l'AD : utilisateur dans l'OU de l'établissement = local, ou utilisateur avec `memberOf=etabUAI` = itinérant (ou l'inverse — à confirmer). Un bug possible existe dans `/sync-from-ad` sur cette logique — corriger si nécessaire avant d'implémenter.

As a **responsable de collège**,
I want que le statut itinérant d'un utilisateur soit clairement affiché et que ses droits différenciés soient appliqués automatiquement,
So que je comprenne immédiatement la situation de l'utilisateur sans configuration manuelle.

**Acceptance Criteria :**

**Given** la synchronisation AD est à jour
**When** SER détermine le statut d'un utilisateur
**Then** un utilisateur dont le compte est dans l'OU de l'établissement est considéré **local**
**And** un utilisateur rattaché via l'attribut AD `memberOf=etabUAI` (règle à confirmer lors du prérequis) est considéré **itinérant**
**And** le statut est dérivé de l'AD à la sync — pas de saisie manuelle

**Given** un utilisateur est détecté comme itinérant
**When** je consulte sa fiche
**Then** son statut itinérant est affiché de façon visible et explicite
**And** ses droits différenciés (quota réduit, profil restreint) sont affichés clairement
**And** aucune action manuelle n'est requise pour appliquer ces droits

**Given** l'établissement fonctionne sans controlHub connecté (SER standalone)
**When** je consulte la liste des utilisateurs
**Then** aucun utilisateur n'est marqué comme itinérant — le statut itinérant ne peut être attribué que via l'AD (géré par le controlHub en amont)

---

### Story 2.6 : Réinitialisation des Mots de Passe en Masse ✅

> **Origine :** migration depuis `annu2/pass_user_init.php` (legacy). Feature critique pour les enseignants en début d'année (reset classe) et pour les incidents de sécurité.
> **Dépendance douce :** permission Spatie `passwords.reset.bulk` attribuée via Epic 7 (selon matrice Epic 12). En attendant Epic 7 livré, un gate temporaire basé sur le bitmask legacy `SE_USER_ADMIN` peut être utilisé.

As a **responsable de collège (ou enseignant avec délégation)**,
I want sélectionner plusieurs utilisateurs dans `/users` et réinitialiser leur mot de passe en une opération,
So que je puisse gérer la rentrée scolaire (reset toutes les classes entrantes) et les incidents de sécurité sans action individuelle par utilisateur.

**Acceptance Criteria :**

**Given** je suis sur la page `/users` avec droit `passwords.reset.bulk` (ou droit délégué scopé à mon périmètre)
**When** je sélectionne N utilisateurs (checkbox multi-sélection) et déclenche "Réinitialiser les mots de passe"
**Then** un nouveau mot de passe est généré pour chacun selon la politique configurée (longueur, classes de caractères)
**And** les mots de passe sont appliqués dans l'AD local via LdapRecord en premier, puis reflétés dans PostgreSQL
**And** un export téléchargeable (PDF et/ou CSV) est proposé immédiatement avec la liste login ↔ nouveau mot de passe
**And** l'export est généré une seule fois (non rejouable côté serveur) et effaçable en local après distribution

**Given** je tente de réinitialiser des utilisateurs hors de mon périmètre de délégation
**When** la requête est traitée
**Then** l'action est refusée avec un message explicite (403)
**And** aucun utilisateur n'est modifié (transaction atomique — tout ou rien)

**Given** une réinitialisation échoue pour un utilisateur (AD indisponible, LDAP verrouillé)
**When** l'erreur est détectée
**Then** la transaction entière est annulée — aucun utilisateur partiellement réinitialisé
**And** l'erreur est affichée via WithToasts avec identification des utilisateurs concernés
**And** l'administrateur peut relancer l'action après correction

**Given** une réinitialisation bulk a été exécutée avec succès
**When** l'audit trail est consulté
**Then** chaque réinitialisation est loggée individuellement (NFR8 — conformité RGPD) avec timestamp, utilisateur cible, auteur de l'action, et périmètre
**And** les nouveaux mots de passe NE SONT PAS stockés en clair dans les logs (seul le fait de la réinitialisation est tracé)

**Given** les utilisateurs doivent changer leur mot de passe à la première connexion
**When** je configure l'option "forcer changement à la prochaine connexion" sur le bulk
**Then** le flag `pwdLastSet` est positionné à 0 dans l'AD pour chaque utilisateur

---

## Epic 3 : Système iPXE — Boot réseau & Déploiement OS

**Statut :** 🔴 not-ready

*Le système iPXE est entièrement réimplémenté nativement dans Laravel, sans dépendance au proxy legacy catchall. Les postes peuvent booter, s'enregistrer (nommage, parc, salle), et recevoir une installation OS (Linux, Windows) via des endpoints Laravel dédiés, en s'appuyant sur PostgreSQL comme source de vérité à la place de l'AD direct.*

**FRs couverts :** FR8 (boot/WOL context), FR7 (enrollment machines), FR23-26 (déploiement Windows via iPXE — partagés avec Epic 9)

> **Prérequis :** Epic 1 (AuthGuard, catchall), Epic 4 (modèles Workstation/WorkstationGroup).
>
> **Note implémentation :** les scripts d'installation OS existants (`actions/*.php`, `Win10/`) sont réécrits en services PHP Laravel — ils appellent les mêmes outils système (wimboot, clonezilla, partitions) mais via des Services typés, sans dépendance au legacy PHP procédural.
>
> **Stories à détailler** (acceptance criteria à produire lors de la création de chaque story via `bmad-create-story`) : voir liste ci-dessous — seuls les titres sont fixés ici pour l'instant.

### Story 3.1 : iPXE Service Core

*Socle Services Laravel `IpxeService` — génération menus iPXE dynamiques, routage par MAC/UUID, lecture PostgreSQL (Workstation/WorkstationGroup/AppProfile) comme source de vérité.*

### Story 3.2 : Boot et Menu Admin iPXE

*Endpoints boot & menu admin iPXE (maintenance, rescue, factory reset) — remplacement du proxy legacy catchall par des routes Laravel dédiées.*

### Story 3.3 : Enrollment Machine — Parcs, Salles, Nommage ✅ IMPLÉMENTÉE

*Flux d'enrollment d'un poste inconnu : saisie nom/parc/salle, persist PostgreSQL + création AD local via LdapRecord.*

### Story 3.4 : Installation Linux (Debian/Ubuntu)

*Pilotage install réseau Linux via iPXE — preseed dynamique, partitionnement, post-install.*

### Story 3.5 : Installation Windows (Sysprep/Wimboot)

*Pilotage install Windows via wimboot/Sysprep — intégration domain join, scripts post-install.*

### Story 3.6 : Gestion ISO Windows

*Upload / stockage / association d'ISOs Windows aux profils de déploiement.*

### Story 3.7 : Clonage et Maintenance

*Opérations clonezilla (clone, restore), mode maintenance/rescue via iPXE.*

---

## Epic 4 : Gestion des Machines, WorkstationGroups & AppProfiles SER

*Le responsable peut consulter l'inventaire des machines, piloter les postes individuellement et en batch, programmer des crons, importer des machines depuis CSV, et associer des profils applicatifs.*

### Story 4.1 : Inventaire des Machines par Groupe Physique et WorkstationGroup ✅

As a **responsable de collège**,
I want consulter l'inventaire des machines organisé par groupe physique (salle) et par workstationGroup (groupe logique),
So que j'ai une vue claire du parc et de l'organisation des postes.

*Fonctionnalité existante — story documentée pour complétude.*

---

### Story 4.2 : Actions Unitaires sur une Machine + Feedback Readiness ✅

> **Prérequis — Lecture Legacy :** Consulter l'implémentation legacy du WOL, extinction et reboot pour comprendre les mécanismes réseau utilisés (broadcast WOL, commandes SSH/WMI, scripts…) avant de valider ou corriger l'implémentation existante.

As a **responsable de collège**,
I want déclencher des actions unitaires sur une machine (allumage WOL, extinction, reboot) et voir la progression de l'opération,
So que je sache si la machine a bien répondu sans avoir à aller vérifier physiquement.

**Acceptance Criteria :**

**Given** je consulte la fiche ou la liste d'une machine
**When** je déclenche une action (WOL, extinction, reboot)
**Then** un retour de démarrage immédiat est affiché (l'action est lancée — NFR2)
**And** la tâche est créée via le pattern ControlHubTask (status: received → running → completed/failed)
**And** le statut de la machine se met à jour en temps réel (readiness après WOL, état après extinction)

**Given** une action WOL est déclenchée
**When** la machine répond sur le réseau
**Then** son état passe à "disponible/allumée" dans l'interface
**And** si la machine ne répond pas dans le délai attendu, un état "timeout" est affiché explicitement

**Given** une action échoue
**When** la tâche passe en status failed
**Then** un message d'erreur explicite est affiché (WithToasts)
**And** l'état de la machine n'est pas modifié de façon incohérente

---

### Story 4.3 : Actions Batch sur un WorkstationGroup ✅

*Dépend de Story 4.2 — les actions unitaires doivent être validées et testées avant.*

As a **responsable de collège**,
I want déclencher une action (allumage, extinction, reboot) sur toutes les machines d'un workstationGroup en une seule opération,
So que je puisse gérer un groupe de salles rapidement sans agir poste par poste.

**Acceptance Criteria :**

**Given** je suis sur la vue d'un workstationGroup
**When** je déclenche une action batch (WOL, extinction, reboot)
**Then** l'action est déclenchée sur chaque machine du groupe via le pattern ControlHubTask
**And** un retour de démarrage immédiat est affiché (NFR2)
**And** la progression par machine est visible (chaque poste indique son état individuellement)

**Given** certaines machines du groupe ne répondent pas
**When** l'action batch est terminée
**Then** un résumé indique le nombre de succès et d'échecs
**And** les machines en échec sont identifiées explicitement

---

### Story 4.4 : Crons Planifiés sur un WorkstationGroup ✅

As a **responsable de collège**,
I want programmer des actions planifiées (allumage, extinction) sur un workstationGroup selon un horaire récurrent,
So que les salles s'allument et s'éteignent automatiquement sans intervention manuelle quotidienne.

**Acceptance Criteria :**

**Given** je configure un cron sur un workstationGroup
**When** je définis l'action (allumage ou extinction), les jours de la semaine et l'heure
**Then** le cron est persisté et s'exécute automatiquement aux horaires définis
**And** l'exécution crée une ControlHubTask par machine du groupe (traçabilité)

**Given** un cron est actif
**When** je consulte le workstationGroup
**Then** les crons actifs sont affichés avec leur horaire et leur action

**Given** je désactive ou supprime un cron
**When** je confirme
**Then** les prochaines exécutions planifiées sont annulées

---

### Story 4.5 : Import de Machines et Groupes Physiques depuis CSV ✅

As a **responsable de collège**,
I want importer des machines et des groupes physiques (salles) depuis un fichier CSV,
So que je puisse peupler ou mettre à jour l'inventaire rapidement sans saisie manuelle poste par poste.

**Acceptance Criteria :**

**Given** j'importe un fichier CSV valide
**When** l'import est traité
**Then** les machines sont créées ou mises à jour dans PostgreSQL
**And** les groupes physiques (salles) sont créés ou rattachés selon le CSV
**And** un rapport d'import est affiché (lignes importées, lignes en erreur avec raison)

**Given** le CSV contient des lignes invalides (champs manquants, format incorrect)
**When** l'import est traité
**Then** les lignes valides sont importées, les lignes invalides sont rejetées
**And** chaque erreur est décrite explicitement dans le rapport

**Given** une machine du CSV existe déjà
**When** l'import est traité
**Then** la machine existante est mise à jour (pas de doublon)

---

### Story 4.6 : Association AppProfile à des Postes et WorkstationGroups ✅

As a **responsable de collège**,
I want associer un profil applicatif (AppProfile) à des postes individuels ou à des workstationGroups,
So que les applications correctes soient installées au démarrage selon le contexte d'utilisation de chaque poste ou groupe.

*Fonctionnalité existante — story documentée pour complétude.*

---

## Epic 5 : Système de Fichiers SER ✅

*Le responsable gère les home directories avec quotas XFS, les partages de classe avec ACLs POSIX héritées, et la suppression des données en deux temps.*

### Story 5.1 : Gestion des Home Directories et Quotas XFS — **SPLITTÉE le 2026-04-22**

> **Investigation Legacy réalisée le 2026-04-22** — Brainstorm avec Henri. Constat :
> - Le code `createHomeDirectory()` + `archive/restore/deletePermanently` existe déjà dans `app/Services/UserService.php` (l. 1568-2205).
> - `QuotaService.php` (737 lignes) est complet : héritage user > group > default_profil, cache 5min, jobs async, audit.
> - Le VRAI legacy PHP (`sambaedu/includes/quotas.inc.php`) utilise : `xfs_quota -x -c 'limit -u bsoft=Xm bhard=Ym $user'` avec héritage table MySQL `quotas` (max des groupes memberof). Pas de notion d'itinérant, pas de project quotas — user quotas uniquement.
>
> **Décisions produit (Henri, 2026-04-22) :**
> - Pas de page centralisée `/filesystem/` — les quotas suivent le modèle WPKG/AppProfiles : affichage/édition dans les pages user et groupe existantes.
> - Snapshot quota 1×/jour (03h00) stocké en BDD (`users.quota_snapshot` JSONB), au lieu du cache 5min actuel. Affichage instantané (lecture BDD, pas de shellout par ligne).
> - `default_itinerant` : override des autres defaults si `User::isExternal()`. Valeur par défaut réglable dans `/admin/settings`.
> - Purge trash : TTL configurable dans `/admin/settings` + toggle auto/manuelle.
> - Flash/toast over-quota au login utilisateur.
> - Scaffold `/admin/settings` créé dans la 5.1c (onglet Quotas & FS uniquement pour cette story — d'autres onglets viendront via autres Epics).
>
> **Story splittée en 4 sous-stories** : 5.1a (refactor), 5.1b (snapshot + UI user), 5.1c (UI groupes + settings + over-quota toast), 5.1d (gaps produits).

#### Story 5.1a : Refactor Services Filesystem (extraction HomeDirService + XfsQuotaService) ✅

> **Scope :** Pure refactoring. Aucun changement fonctionnel utilisateur. Sert de fondation propre pour 5.1b/c/d.
>
> **Note d'implémentation :**
> - Créer `app/Services/Filesystem/HomeDirService.php` → extraire les méthodes `createHomeDirectory`, `archiveHomeDirectory`, `restoreHomeDirectory`, `deleteHomeDirectoryPermanently`, `hasArchivedHome` depuis `UserService` (l. 1568-2205).
> - Créer `app/Services/Filesystem/XfsQuotaService.php` → déplacer le contenu de `app/Services/QuotaService.php` (737 lignes). Garder `QuotaService` racine en alias/facade si trop d'appelants, sinon renommer les usages.
> - Supprimer `RefreshQuotaCacheCommand` et le cache 5min (remplacé par snapshot BDD en 5.1b).
> - Tests de non-régression : tous les tests existants de `QuotaServiceTest`, `UserServiceTest` (section home) doivent passer sans modification fonctionnelle.

As a **développeur SER**,
I want que les responsabilités filesystem soient isolées dans des services dédiés `Filesystem/HomeDirService` et `Filesystem/XfsQuotaService`,
So que `UserService` (2513 lignes) soit allégé et que le domaine FS soit extensible indépendamment.

**Acceptance Criteria :**

**Given** le code actuel avec `UserService::createHomeDirectory()` et `QuotaService`
**When** le refactoring 5.1a est appliqué
**Then** `app/Services/Filesystem/HomeDirService.php` existe avec toutes les méthodes home extraites
**And** `app/Services/Filesystem/XfsQuotaService.php` existe avec le contenu de `QuotaService`
**And** tous les appelants sont mis à jour (injection DI)
**And** tous les tests existants passent sans modification fonctionnelle

**Given** le cache Laravel 5min pour les quotas
**When** le refactoring est appliqué
**Then** `RefreshQuotaCacheCommand` est supprimée
**And** la planification `quota:refresh-cache` est retirée de `Console/Kernel.php`
**And** le cache 5min n'est plus utilisé (remplacé par snapshot BDD en 5.1b)

**Given** une méthode du `HomeDirService` ou `XfsQuotaService` échoue (quota déjà existant, répertoire manquant, commande sudo refusée…)
**When** l'erreur survient
**Then** elle est loggée avec contexte (user, partition, commande)
**And** remontée explicitement — pas de comportement silencieux

**Dépendances :** aucune.
**Estimation :** 1-2 jours.

---

#### Story 5.1b : Snapshot quotas quotidien + UI utilisateur ✅

> **Scope :** Remplacer le cache 5min par un snapshot quotidien en BDD, afficher utilisation/quota dans listing + fiche user, permettre l'override user depuis la fiche.
>
> **Note d'implémentation :**
> - Migration : ajouter `users.quota_snapshot JSONB NULL` (structure : `{partition: {used_mb, soft_mb, hard_mb, percent, is_over, computed_at}}`).
> - Commande `quota:snapshot` : parse `sudo xfs_quota -x -c 'report -a -N' {partition}` en une passe, met à jour tous les users en batch. Planifiée 03h00 via `Console/Kernel.php`.
> - Listing `/app/users` : colonne "Utilisation %" (vert < 70%, orange 70-90%, rouge > 90% / over-quota). Lecture pure BDD.
> - Fiche `/app/users/[login]` : section "Quota" avec utilisation actuelle, quota effectif, **breakdown héritage** (user explicite / groupe le plus grand / default profil), bouton "Actualiser" (refresh snapshot on-demand, appel `XfsQuotaService::getDiskUsage` direct).
> - Override user : formulaire d'édition (`type='user'`, soft/hard en Mo) avec validation. Applique immédiatement via `ApplyQuotaJob`.

As a **responsable de collège**,
I want voir en un coup d'œil l'utilisation disque de chaque utilisateur dans le listing et ajuster un quota individuel depuis sa fiche,
So que je repère rapidement les utilisateurs en dépassement et corrige sans manipuler la ligne de commande.

**Acceptance Criteria :**

**Given** la commande `quota:snapshot` est planifiée à 03h00
**When** elle s'exécute
**Then** `users.quota_snapshot` est mis à jour pour tous les users actifs en une passe (parse `xfs_quota report -a -N`)
**And** la valeur contient `used_mb, soft_mb, hard_mb, percent, is_over, computed_at` par partition

**Given** je consulte `/app/users`
**When** la page se charge
**Then** une colonne "Utilisation %" est affichée avec code couleur (vert/orange/rouge selon seuils)
**And** la valeur provient du snapshot BDD (aucun shellout par ligne)

**Given** je consulte la fiche `/app/users/[login]`
**When** la page se charge
**Then** je vois la section "Quota" avec utilisation, quota effectif, et le breakdown d'héritage (source : user explicite / groupe X / default profil)
**And** un bouton "Actualiser" permet de recalculer le snapshot à la demande

**Given** je modifie le quota d'un utilisateur depuis sa fiche (override user)
**When** je valide
**Then** une règle `type='user'` est créée/mise à jour dans `quota_rules`
**And** `ApplyQuotaJob` est dispatché immédiatement
**And** le snapshot de l'utilisateur est rafraîchi après application
**And** une notification `WithToasts` confirme l'application (ou signale l'échec)

**Dépendances :** 5.1a.
**Estimation :** 2-3 jours.

---

#### Story 5.1c : Quotas groupes + `/admin/settings` scaffold + flash over-quota au login ✅

> **Scope :** Édition quota par groupe, page de réglages serveur (onglet Quotas & FS uniquement dans cette story), toast over-quota à la connexion.
>
> **Note d'implémentation :**
> - Listing `/app/users/groups` : colonne "Quota" (affiche la règle `type='group'` si existe, sinon "—").
> - Fiche `/app/users/groups/[id]` : section "Quota du groupe" avec formulaire d'édition. Dispatch `ApplyQuotaJob` pour recalculer les effectifs des membres.
> - Scaffold `/admin/settings` : page avec layout à onglets (atomic design — `organisms/settings-tabs` ou équivalent). Onglet "Quotas & FS" dans cette story. Les autres onglets (DHCP, CUPS, etc.) viendront dans leurs Epics respectives.
> - Contenu onglet Quotas & FS : defaults par profil (élève, prof, admin, **itinérant**), grace period par partition, TTL trash (jours), toggle "Purge auto/manuelle" pour `/home/trash/`.
> - Flash over-quota : event Laravel `Login` → middleware ou listener qui lit `users.quota_snapshot` de l'utilisateur qui se connecte. Si `is_over == true` sur une partition, ajout d'un toast via `WithToasts` trait ("Votre espace K: est dépassé : X Mo / Y Mo. Libérez de l'espace.").

As a **responsable de collège**,
I want ajuster le quota d'un groupe entier d'un coup, régler les valeurs par défaut par profil depuis une page de réglages, et voir un avertissement clair quand un utilisateur dépasse son quota,
So que j'administre les quotas de façon cohérente sans toucher à chaque utilisateur individuellement, et que les utilisateurs en dépassement soient alertés.

**Acceptance Criteria :**

**Given** je consulte `/app/users/groups/[id]`
**When** la page se charge
**Then** je vois la section "Quota du groupe" avec la règle actuelle (`type='group'`) ou "—" si inexistante

**Given** je modifie le quota d'un groupe
**When** je valide
**Then** une règle `type='group'` est créée/mise à jour
**And** le job de recalcul des effectifs des membres est dispatché (`dispatchRecalculateGroupJob`)
**And** `WithToasts` confirme l'application

**Given** la page `/admin/settings` n'existe pas
**When** 5.1c est implémentée
**Then** la route `/admin/settings` est créée avec un layout à onglets extensible
**And** l'onglet "Quotas & FS" contient les champs : defaults profils (élève/prof/admin/itinérant en Mo soft + hard), grace period par partition (jours), TTL trash (jours), toggle purge auto/manuelle
**And** les autres onglets sont absents (placeholders interdits — on ajoute uniquement ce qui est implémenté)
**And** les modifications persistent (`quota_settings` ou équivalent, table `settings` existante)

**Given** un utilisateur en dépassement de quota (`is_over == true` dans son snapshot)
**When** il se connecte (événement Login)
**Then** un toast d'alerte s'affiche via `WithToasts` ("Votre espace X est dépassé : Y Mo utilisés / Z Mo autorisés. Libérez de l'espace.")
**And** le toast reste visible jusqu'à dismissal manuel

**Dépendances :** 5.1a, 5.1b (snapshot requis pour le toast).
**Estimation :** 3-4 jours.

---

#### Story 5.1d : Gaps produits (default_itinerant, purge trash, seed legacy) 🔄 EN COURS

> **Scope :** Finaliser les décisions produit : règle `default_itinerant`, purge automatique/manuelle du trash, seed des valeurs depuis le legacy.
>
> **Note d'implémentation :**
> - Nouveau type dans `quota_rules` : `default_itinerant`. Migration pour l'ajouter à l'enum.
> - `XfsQuotaService::getEffectiveQuota()` : si `User::isExternal()` ET aucune règle explicite user/group → applique `default_itinerant` (prioritaire sur `default_eleve/prof/admin`).
> - Purge trash : commande `trash:purge` (Artisan) qui supprime les dossiers `/home/trash/*` plus vieux que le TTL configuré dans `/admin/settings`. Si toggle "auto" → planifiée quotidienne (02h00, avant snapshot). Si "manuelle" → commande disponible mais non planifiée + bouton "Purger maintenant" dans onglet Quotas & FS.
> - Seed legacy : commande `quota:seed-from-legacy` (one-shot) qui lit les règles existantes dans le legacy (table MySQL `quotas` du legacy ou config actuelle sur la VM) et crée les entrées correspondantes dans `quota_rules` + valeurs defaults dans `/admin/settings`. Exécutée à la migration initiale.

As a **responsable de collège**,
I want que les utilisateurs itinérants aient automatiquement un quota réduit, que la corbeille `/home/trash/` se purge selon mes règles, et que les valeurs historiques du legacy soient conservées à la migration,
So que je n'aie pas à configurer chaque utilisateur manuellement et que la transition depuis l'ancien système soit transparente.

**Acceptance Criteria :**

**Given** un utilisateur tel que `User::isExternal() == true` et sans règle `type='user'` ni `type='group'` correspondante
**When** `XfsQuotaService::getEffectiveQuota()` est appelé
**Then** la règle `default_itinerant` est appliquée (prioritaire sur `default_eleve/prof/admin`)
**And** si `default_itinerant` est absente, fallback sur le default de profil correspondant

**Given** le toggle "Purge auto" est activé dans `/admin/settings` avec TTL = N jours
**When** la commande `trash:purge` s'exécute quotidiennement (02h00)
**Then** les dossiers `/home/trash/*` dont le mtime > N jours sont supprimés définitivement via `HomeDirService::deleteHomeDirectoryPermanently()`
**And** chaque suppression est loggée (audit_logs)

**Given** le toggle "Purge manuelle" est activé
**When** je clique sur "Purger maintenant" depuis l'onglet Quotas & FS
**Then** la commande `trash:purge` est exécutée en mode manuel
**And** le résultat (N dossiers supprimés) est affiché via `WithToasts`
**And** aucune planification automatique n'est active

**Given** une installation SER fraîche sur une VM avec legacy existant
**When** la commande `quota:seed-from-legacy` est exécutée
**Then** les règles `type='user'` et `type='group'` sont créées dans `quota_rules` depuis les données legacy
**And** les defaults profils (élève/prof/admin/itinérant) sont initialisés dans `/admin/settings`
**And** un rapport indique combien de règles ont été importées

**Given** une opération `trash:purge` ou `quota:seed-from-legacy` échoue
**When** l'erreur survient
**Then** elle est loggée et remontée explicitement — pas de comportement silencieux

**Dépendances :** 5.1a, 5.1c (onglet settings requis pour TTL + toggle).
**Estimation :** 2 jours.

---

### Story 5.2 : Partages de Classe et Gestion des ACLs POSIX ✅

> **Prérequis — Investigation Legacy :** Analyser comment le legacy crée les partages de classe (structure Samba, ACLs POSIX héritées, dossier échange, droits lecture/écriture par groupe…). Identifier les règles d'héritage ACL et les cas limites (changement de classe d'un élève, suppression de classe…). Mini-brainstorm avec Henri avant d'implémenter.

As a **responsable de collège**,
I want créer des répertoires de partage par classe avec ACLs POSIX héritées et gérer les droits d'accès (lecture, écriture, dossier échange),
So que les élèves et enseignants d'une classe accèdent à leurs ressources partagées avec les bons droits.

**Acceptance Criteria :**

**Given** je crée un partage pour une classe
**When** je valide
**Then** le répertoire de partage est créé avec les ACLs POSIX héritées correctes via `AclService`
**And** les membres de la classe (userGroup) ont les droits d'accès appropriés (lecture, écriture selon le rôle)
**And** un dossier échange est créé avec les droits configurés (déposable par tous, lisible par l'enseignant)

**Given** un élève change de classe (Story 2.2)
**When** la modification est appliquée
**Then** ses droits sur l'ancien partage de classe sont révoqués
**And** ses droits sur le nouveau partage de classe sont accordés

**Given** je modifie les droits d'accès sur un partage existant
**When** je valide
**Then** les ACLs sont mises à jour via `AclService`
**And** l'héritage POSIX est préservé sur les sous-répertoires existants

**Given** une opération ACL échoue
**When** l'erreur survient
**Then** l'erreur est loggée et remontée explicitement — les ACLs précédentes restent inchangées

---

## Epic 6 : Impression SER ✅

*Le responsable peut consulter, ajouter, configurer et supprimer des imprimantes CUPS, et gérer les pilotes Windows associés.*

### Story 6.1 : Consultation, Gestion et Rattachement Parc des Imprimantes CUPS ✅

> **Prérequis — Lecture Legacy :** Consulter l'implémentation legacy de la gestion des imprimantes pour comprendre les commandes CUPS utilisées (`lpadmin`, `lpstat`, `cupsctl`…) et la structure des données imprimante.
>
> **Note d'implémentation :** Cette page s'intègre dans `/parc` sous forme d'onglet, en suivant le même pattern que l'onglet workstations existant. Appels CUPS encapsulés dans `CupsPrinterService`. Tests sur `cups-pdf` (`printer-driver-cups-pdf`) comme imprimante virtuelle.
>
> **Note d'architecture (D1+D2 fusionnées 2026-04-27 — option B actée par Henri) :** Une table Eloquent `printers` (PK `cups_name`, audit `created_at`/`updated_at`/`created_by_user_id`/`orphan`) **complète** CUPS sans le remplacer. Une table pivot `printer_workstation_group` rattache chaque imprimante à un ou plusieurs `WorkstationGroup`. CUPS reste source de vérité pour nom/URI/état/PPD/file ; la table SER porte uniquement la couche métier (audit + rattachement parc + filtrage utilisateur). Une commande `php artisan printers:sync` (planifiée quotidienne + manuelle) réconcilie la table SER avec CUPS : ajoute les imprimantes CUPS détectées hors SER, marque `orphan: true` celles présentes en SER mais absentes de CUPS (sans les supprimer, pour préserver les rattachements en cas de réintroduction). L'AD/Samba ne sont **pas** sollicités pour le rattachement parc — la publication SMB des imprimantes aux postes Windows reste assurée par `[printers]` dans `smb.conf` (mécanisme inchangé).

As a **responsable de collège ou administrateur SER**,
I want consulter, ajouter, configurer, supprimer les imprimantes CUPS et les rattacher à un ou plusieurs parcs (`WorkstationGroup`) depuis l'interface SER,
So que je gère le parc d'impression sans manipuler CUPS en ligne de commande, **et** que chaque utilisateur ne voie dans `/parc?tab=printers` que les imprimantes pertinentes pour son périmètre (cohérent avec les délégations Epic 7).

**Acceptance Criteria :**

*Couche CUPS (consultation + CRUD)*

**Given** je consulte l'onglet imprimantes dans `/parc`
**When** la page se charge
**Then** je vois la liste des imprimantes avec leur nom, état (active/inactive) et file d'attente
**And** un badge `non rattachée` apparaît sur les imprimantes sans rattachement parc

**Given** j'ajoute une imprimante
**When** je saisis le nom, l'URI, la configuration et le(s) parc(s) de rattachement, puis je valide
**Then** l'imprimante est créée dans CUPS via `CupsPrinterService`
**And** une ligne est créée dans la table `printers` avec `created_by_user_id` renseigné
**And** les rattachements sont insérés dans `printer_workstation_group`
**And** elle apparaît dans la liste avec son état actuel

**Given** je modifie la configuration d'une imprimante (technique CUPS ou rattachement parc)
**When** je valide
**Then** la configuration CUPS est mise à jour si modifiée
**And** les rattachements parc sont mis à jour si modifiés (sync de la pivot)

**Given** je supprime une imprimante via l'UI SER
**When** je confirme
**Then** l'imprimante est retirée de CUPS
**And** la ligne et ses rattachements sont supprimés de la table SER (cascade)
**And** elle disparaît de la liste

**Given** une opération CUPS échoue
**When** l'erreur est retournée par `CupsPrinterService`
**Then** un message d'erreur explicite est affiché (WithToasts) — pas de comportement silencieux
**And** aucune écriture partielle n'est laissée en SER (transaction Eloquent + pas de commit si CUPS a échoué)

*Couche métier SER (rattachement parc + filtrage)*

**Given** je suis administrateur SER (`server.admin`)
**When** je consulte la liste
**Then** je vois toutes les imprimantes (rattachées et non rattachées)
**And** un filtre permet d'isoler les imprimantes non rattachées pour faciliter le ménage

**Given** je suis utilisateur lambda (sans `server.admin`)
**When** je consulte `/parc?tab=printers`
**Then** je vois uniquement les imprimantes rattachées à au moins un de mes parcs (groupe physique ou `WorkstationGroup`)
**And** les imprimantes non rattachées et celles rattachées hors de mon périmètre ne me sont pas visibles

**Given** je suis responsable délégué (Epic 7) sur le parc `salle-info-1`
**When** je tente de modifier une imprimante
**Then** la modification est autorisée uniquement si l'imprimante est rattachée à `salle-info-1`
**And** `PrinterPolicy::manage` vérifie le rattachement scopé via `PermissionService::canOnWorkstationGroup`

*Synchronisation CUPS ↔ SER*

**Given** une imprimante CUPS est créée hors SER (ex : `lpadmin` en SSH)
**When** `php artisan printers:sync` s'exécute (planifié quotidien + déclenchable manuellement)
**Then** une ligne est ajoutée dans `printers` avec `cups_name` correspondant et aucun rattachement (badge `non rattachée`, visible admin uniquement)
**And** un log informe l'admin de l'imprimante détectée

**Given** une imprimante SER est supprimée hors SER (ex : `lpadmin -x` en SSH)
**When** `printers:sync` s'exécute
**Then** la ligne est marquée `orphan: true` (pas supprimée — préserve les rattachements pour réintroduction éventuelle)
**And** l'imprimante n'apparaît plus dans la liste sauf en mode `Voir orphelines` (admin)

**Given** je restaure une sauvegarde de la base SER après une perte
**When** la table `printers` est restaurée puis je relance `printers:sync`
**Then** les rattachements parc préservés sont réappliqués
**And** les `cups_name` absents de CUPS sont marqués `orphan`
**And** les nouvelles imprimantes CUPS détectées sont ajoutées sans rattachement

---

### Story 6.2 : Gestion des Pilotes Windows ✅

> **Prérequis — Lecture Legacy :** Analyser comment le legacy gère les pilotes Windows associés aux imprimantes CUPS (`cupsaddsmb`, fichiers PPD, `.inf`…) pour comprendre le mécanisme à réimplémenter dans `PrintDriverService`.

As a **responsable de collège**,
I want associer et gérer les pilotes Windows aux imprimantes,
So que les postes Windows puissent installer automatiquement les pilotes corrects lors de la connexion à une imprimante réseau.

**Acceptance Criteria :**

**Given** je consulte la fiche d'une imprimante
**When** j'accède à la section pilotes
**Then** je vois les pilotes Windows associés (nom, architecture 32/64 bits)

**Given** j'ajoute un pilote Windows à une imprimante
**When** je valide
**Then** le pilote est associé à l'imprimante via `PrintDriverService`
**And** il est disponible pour les postes Windows du parc

**Given** je supprime un pilote
**When** je confirme
**Then** le pilote est retiré de l'association sans affecter l'imprimante elle-même

**Given** une opération pilote échoue
**When** l'erreur survient
**Then** un message explicite est affiché — l'état précédent est préservé

---

## Epic 7 : Délégations & Permissions Applicatives SER

*L'administrateur peut déléguer des droits applicatifs SER à un utilisateur sur un périmètre limité. Les droits Spatie sont calculés comme l'union des droits de groupe et individuels.*

> **Rappel :** cet épic couvre exclusivement les **droits applicatifs SER** (accès aux sections et fonctionnalités de l'application). Les droits Windows/POSIX sur les partages et home dirs sont dans Epic 5.

### Story 7.1 : Attribution de Droits Délégués sur un Périmètre ✅

As a **administrateur SER**,
I want attribuer des droits délégués à un utilisateur sur un périmètre limité (groupe physique ou workstationGroup),
So qu'un enseignant ou responsable délégué puisse gérer son périmètre dans SER sans accéder au reste.

**Acceptance Criteria :**

**Given** je suis administrateur SER
**When** j'attribue une délégation à un utilisateur en sélectionnant un périmètre (groupe physique ou workstationGroup) et les droits associés
**Then** la délégation est persistée via Spatie (permission individuelle ou de groupe)
**And** l'utilisateur délégué ne voit dans SER que les ressources de son périmètre

**Given** un utilisateur délégué est connecté
**When** il navigue dans SER
**Then** il ne voit que les groupes physiques ou workstationGroups sur lesquels il a une délégation
**And** toute tentative d'accès hors périmètre est refusée silencieusement (redirection ou 403)

**Given** je révoque une délégation
**When** je confirme
**Then** l'utilisateur perd immédiatement l'accès au périmètre concerné

---

### Story 7.2 : Calcul et Application des Droits Spatie ✅ LIVRÉE (2026-04-24)

> **Prérequis — ✅ levé (2026-04-22).** La matrice Profils × Droits a été produite par la Story 12.1 : `_bmad-output/planning-artifacts/profiles-rights-matrix.md`. Elle fixe les 9 rôles Spatie, les 18 permissions et les mappings legacy ↔ Spatie. Les 3 décisions restantes à prendre au démarrage de cette story sont listées en matrice §9 (Story 7.2) et §10 (zones grises) : **(a)** Policy `UserPolicy::resetPassword` scopée par classe pour le rôle `Prof` (reproduit `sovajon_is_admin` legacy) via `$user->userGroups()->where('type', 'class')` ; **(b)** trancher si `UserRead` du `Prof` est aussi scopé par classe ou global ; **(c)** définir ce qu'on entend par "cas itinérant" pour valider la portée du `canOnWorkstationGroup`.

As a **utilisateur SER**,
I want que mes droits d'accès aux sections de l'application soient calculés correctement selon mon rôle et mes droits individuels,
So que je vois et peux faire uniquement ce qui m'est autorisé, sans configuration manuelle à chaque connexion.

**Acceptance Criteria :**

**Given** un utilisateur appartient à un groupe Spatie et a des droits individuels supplémentaires
**When** il se connecte
**Then** ses droits effectifs sont l'union de ses droits de groupe et de ses droits individuels
**And** aucune section non autorisée n'est visible dans l'interface (`@can` appliqués dans les vues)

**Given** un utilisateur n'a aucun droit sur une section
**When** il tente d'y accéder directement via l'URL
**Then** l'accès est refusé (403 ou redirection) — pas de bypass possible par URL directe

**Given** les droits d'un utilisateur sont modifiés par l'administrateur
**When** l'utilisateur effectue sa prochaine action
**Then** ses nouveaux droits sont appliqués sans nécessiter de déconnexion/reconnexion

---

### Story 7.3 : Migration Bitmask → Spatie + Observer de Projection Legacy

> **Stratégie A actée 2026-04-17** : Spatie devient la **source de vérité** pour les droits applicatifs SER. Le bitmask legacy dans l'attribut `info` des groupes LDAP (branche `rights_rdn`) n'est plus qu'une **projection** écrite en aval par un Observer à chaque changement de rôle/permission Spatie. Cette story implémente la bascule en 2 volets : **(1)** migration one-shot des assignations existantes legacy → Spatie ; **(2)** Observer de projection inverse Spatie → bitmask AD, activé dès la migration terminée.
>
> **Contrat post-story :** après livraison, toute lecture de droits dans l'UI SER passe exclusivement par Spatie ; toute écriture de rôle/permission déclenche automatiquement la mise à jour du bitmask AD pour la compatibilité des consommateurs externes (GPO, scripts legacy qui ne sont pas encore réécrits).
>
> **Sunset du mapping bitmask côté Spatie** : une fois cette story livrée et stabilisée, une PR dédiée supprime `LegacyRight`, `SambaPermission::legacyRight()`, `bitmask()`, `fromBitmask()`, `bitmaskToPermissions`, `permissionsToBitmask` du code Spatie (cf. matrice §11). Seule la projection AD en écriture subsiste, portée par l'Observer.

As a **administrateur SER / développeur**,
I want migrer les assignations de droits historiques (bitmask legacy dans l'AD) vers le modèle Spatie et maintenir la projection inverse automatique,
So que Spatie devienne la seule source de vérité pour les droits applicatifs, tout en préservant la compatibilité des consommateurs legacy qui lisent encore l'attribut `info`.

**Acceptance Criteria :**

*Volet 1 — Migration one-shot*

**Given** une commande `php artisan sambaedu:migrate-rights-to-spatie` est disponible
**When** je l'exécute sur un SER avec des groupes de droits legacy peuplés dans la branche `rights_rdn`
**Then** chaque user rattaché à un groupe legacy reçoit le ou les rôles Spatie correspondants selon la matrice §5.3 (profiles-rights-matrix.md)
**And** les délégations scopées legacy (bitmask attaché à un parc) sont migrées en `Delegation` Spatie via `PermissionService::grantDelegation(user, permission, workstationGroup)`
**And** les profils négatifs legacy (groupes dont le `cn` commence par `no_`) sont migrés en `Delegation` avec le flag `negative` via `negateDelegation`
**And** la commande est **idempotente** : la relancer n'ajoute pas de doublons d'assignations

**Given** j'exécute la migration en mode `--dry-run`
**When** la commande s'achève
**Then** aucune écriture n'est effectuée
**And** un rapport affiche pour chaque user le(s) rôle(s) et délégation(s) qui seraient assignés + les cas non mappables (bitmask orphelin, user introuvable, etc.)

**Given** un groupe legacy `Annu_is_admin` existe **sans attribut `info`** (cas du bug fallback `annu/profiles.php:58` qui le remapperait à tort sur `SE_COMPUTER_ADMIN`)
**When** la migration traite ce groupe
**Then** le groupe est migré comme `SambaRole::UserAdmin` (valeur du seed legacy `SE_USER_ADMIN` = 0xFF), **pas** `ComputerAdmin`
**And** un avertissement est loggé : "Fallback buggé ignoré, assignation alignée sur le seed d'origine"

**Given** la migration s'achève avec succès
**When** je consulte la base
**Then** tous les users migrés ont leurs rôles/permissions Spatie persistés dans les tables Spatie (`model_has_roles`, `model_has_permissions`)
**And** un rapport de synthèse liste : nombre d'users migrés, nombre de rôles assignés, nombre de délégations créées, nombre de cas non mappables

*Volet 2 — Observer de projection Spatie → bitmask AD*

**Given** un Observer `SpatieToBitmaskObserver` est activé sur les assignations/retraits de rôles et permissions Spatie
**When** un administrateur assigne ou retire un rôle à un user via l'UI `/rights-management`
**Then** l'Observer recalcule le bitmask équivalent (via `SambaPermission::toBitmask` sur l'ensemble des permissions effectives) et met à jour l'attribut `info` du groupe AD de rattachement de l'user
**And** le changement est visible aux consommateurs legacy externes (scripts GPO, `have_right()` legacy pendant la coexistence) à la lecture suivante

**Given** une délégation Spatie est créée ou révoquée (`grantDelegation`/`revokeDelegation`)
**When** l'Observer est notifié
**Then** le bitmask du user correspondant est recalculé en tenant compte des délégations (union des permissions de rôle + permissions directes + délégations scopées)
**And** la projection AD est cohérente avec la sémantique `list_rights()` legacy (OR des positifs, AND-NOT des négatifs)

**Given** la projection AD échoue (AD indisponible, permission LDAP, etc.)
**When** l'erreur est capturée
**Then** l'opération Spatie **réussit** (Spatie est source de vérité) mais un log d'erreur + une notification admin sont émis — la projection est retryée en arrière-plan (queue Laravel)

**Given** la migration one-shot a été exécutée avec succès
**When** l'Observer est activé en production
**Then** il reste **désactivable** via un flag de config (`config('sambaedu.permissions.project_to_ad')`) pour debug ou rollback

*Volet 3 — Tests & garanties de non-régression*

**Given** un test d'intégration couvre l'aller-retour legacy → Spatie → bitmask AD
**When** un user a un bitmask legacy arbitraire (ex. `SE_USER_ADMIN | SE_COMPUTER_INSTALL` = 0x8FF)
**Then** après migration, l'user a les rôles Spatie attendus (§5.3) et le bitmask projeté est identique au bitmask d'entrée (round-trip stable)

**Given** la matrice §5.2 liste 9 rôles Spatie et 18 permissions
**When** je couvre tous les profils legacy seedés + cas de délégation scopée + cas négatif `no_*`
**Then** les tests passent pour tous les cas canoniques documentés

---

### Story 7.4 : Bloquer les routes legacy obsolètes + shim minimal admin local Windows ⏳

> **Constat 2026-04-28 (audit shim post-7.3)** : Story 7.3 livre la migration one-shot legacy → Spatie + le shim `have_right` / `list_rights`. Les helpers de délégation legacy (`have_delegation`, `list_delegations`, `list_delegations_right`, `type_delegation`, `search_delegations`, `get_local_admin_right`) restent définis dans `sambaedu/includes/ldap.inc.php` (chargé par le bootstrap legacy) et continuent d'interroger l'AD via `search_ad`. Risque initial : toute délégation posée via `/rights-management` Livewire (table SER `delegations`) est invisible pour le legacy embarqué encore exposé.
>
> **Audit des call sites (2026-04-28)** : 35+ call sites de helpers de délégation dans `sambaedu/`, **mais** :
> - **iPXE n'utilise aucun helper de délégation** (uniquement `have_right` déjà shimé) → Epic 3 peut rester en pause sans risque
> - **`display_remote_list` et `form_choix_poste`** (qui consomment `list_delegations*`) ne sont appelées que depuis `parcs/`, `parcs2/`, et `includes/user.interface.inc.php` — tous remplacés Laravel
> - **`RemoteAccessService` Laravel** require `remote.inc.php` mais n'utilise QUE `search_machine`, `create_remote_token`, `have_right` (pas `find_remote_list`)
> - **Modules legacy déjà remplacés Laravel** (à merger ou déjà mergés) : `printers/` (branche `e6-cups-pilotes`, Story 6.1), `dossier_echange/` (Story 5.2 imminente), `annu/`, `annu2/`, `parcs/`, `parcs2/`, `index.php` flow
> - **Modules legacy non remplacés mais sans `have_delegation`** : `dhcp/`, `gpo/` UI, `display/`, `bbb/` — utilisent uniquement `have_right` (déjà shimé ✅)
>
> **Conclusion** : si on bloque en routing les modules déjà remplacés Laravel, **tous les call sites de délégation deviennent dead code SAUF ceux dans `includes/applications.inc.php`** (admin local Windows, inclus depuis les scripts "out" GPO consommés par les postes Windows joints au domaine).
>
> **Scope final Story 7.4** : (1) bloquer les routes legacy obsolètes, (2) shimer `have_delegation` + `get_local_admin_right` uniquement pour `applications.inc.php`. Plus de "shim complet sur 7 fonctions" — focus sur le périmètre minimal qui sert vraiment.

As a **administrateur SER / développeur**,
I want bloquer en routing les modules legacy déjà remplacés et shimer les 2 helpers de délégation encore consommés par les scripts admin local Windows,
So que les délégations Spatie soient effectives partout où elles servent, sans coût de réimplémentation sur du code mort.

**Acceptance Criteria :**

*Volet 1 — Blocage routing des modules legacy obsolètes*

**Given** une whitelist de routes legacy autorisées (côté `LegacyConfigBridge` ou middleware Laravel)
**When** un user tente d'accéder à `/sambaedu/annu/*`, `/sambaedu/annu2/*` (sauf `add_group.php` si encore référencé en fallback), `/sambaedu/parcs/*`, `/sambaedu/parcs2/*`, `/sambaedu/index.php`, `/sambaedu/index_old.php`, `/sambaedu/action_serv.php`, `/sambaedu/logs.php`, `/sambaedu/parcs/rdp.php`, `/sambaedu/parcs/lance_action.php`, `/sambaedu/parcs/action_parc.php`, `/sambaedu/parcs/action_machine.php`
**Then** la requête est redirigée vers la page Laravel équivalente (`/app/users`, `/app/parc`, `/app/dashboard`, etc.) ou retourne 410 Gone

**Given** la branche `e6-cups-pilotes` (Story 6.1) est mergée
**When** le routing est mis à jour
**Then** `/sambaedu/printers/*` est aussi bloqué et redirigé vers `/app/printers`

**Given** Story 5.2 est livrée
**When** le routing est mis à jour
**Then** `/sambaedu/dossier_echange/*` est bloqué et redirigé vers la page partages Laravel

**Given** le bouton fallback `/app/users/groups/legacy-new` (qui embarque `annu2/add_group.php`)
**When** la page Livewire `/app/users/groups/new` est validée stable
**Then** le bouton + la route fallback sont retirés (ou conservés conditionnellement derrière un flag debug)

*Volet 2 — Shim minimal pour `applications.inc.php` (admin local Windows)*

**Given** les scripts "out" GPO `gpo/applications.php`, `gpo/wallpaper_out.php`, `gpo/associations_out.php` qui incluent `applications.inc.php`
**When** ces scripts sont consommés par les postes Windows joints au domaine au login
**Then** les 5 sites suivants doivent retourner des résultats cohérents avec l'état Spatie/SER :
- `applications.inc.php:742` — `get_local_admin_right($config, $info['user']['cn'])`
- `applications.inc.php:747` — `have_delegation($config, $info['machine']['cn'], SE_COMPUTER_ADMIN, $info['user']['cn'])`
- `applications.inc.php:755` — `get_local_admin_right($config, $info['user']['cn'])`
- `applications.inc.php:758` — `have_delegation($config, $info['machine']['cn'], SE_COMPUTER_ADMIN, $info['user']['cn'])`
- `applications.inc.php:936` — `get_local_admin_right($config, $userl['cn'])` + `have_delegation($config, $machinel['cn'], SE_COMPUTER_ADMIN, $userl['cn'])`

**Given** un shim `have_delegation($config, $parc_or_machine, $right, $name)` enregistré avant le bootstrap legacy
**When** la fonction est appelée
**Then** elle résout `$parc_or_machine` (qui peut être un nom de machine ou de parc) vers le ou les `WorkstationGroup` Eloquent correspondants, puis interroge la table `delegations` SER via `PermissionService::canOnWorkstationGroup` ou équivalent, et retourne un booléen cohérent avec la sémantique legacy (positive OR + négative AND-NOT prioritaires)

**Given** un shim `get_local_admin_right($config, $user)`
**When** la fonction est appelée
**Then** soit elle conserve la sémantique legacy (lecture du param store `$config['local_admin_*']` qui implémente l'élévation temporaire admin local — pas un bitmask Spatie) sans changement, soit elle est shimée pour retourner `0` si la feature n'est plus exposée — décision à trancher selon l'analyse de la fonction `set_local_admin_right` (l'écriture).
**And** la décision est documentée dans une note technique avec le retour fonctionnel attendu côté postes Windows

**Given** l'ordre de chargement
**When** le bootstrap PHP s'exécute (Laravel → LegacyConfigBridge → require legacy)
**Then** les versions shimées de `have_delegation` (et éventuellement `get_local_admin_right`) sont actives au moment où `applications.inc.php` les appelle. Si le legacy est chargé en premier (ordre actuel), patcher `sambaedu/includes/ldap.inc.php` pour entourer ces 2 fonctions par `if (!function_exists(...))` afin que le shim externe puisse gagner.

*Volet 3 — Tests & non-régressions*

**Given** un test feature qui :
1. Pose une délégation positive `bob × computer.elevate × salle-A` via `PermissionService::grantDelegation`
2. Charge le bootstrap legacy + `applications.inc.php`
3. Appelle `have_delegation($config, 'machineX', SE_COMPUTER_ADMIN, 'bob')` avec `machineX` membre de `salle-A`
**Then** le résultat est cohérent avec la délégation Spatie posée

**Given** un test cas négatif (exclusion `no_manage_<parc>`)
**When** une délégation négative existe en table SER
**Then** `have_delegation` retourne `false` même si l'user a la permission via rôle Spatie global

**Given** une revue manuelle du comportement admin local Windows
**When** un user `bob` avec délégation `computer.elevate × salle-A` se logue sur une machine de salle-A
**Then** il devient admin local de la session Windows (comportement iso-legacy)
**And** un user sans cette délégation ne devient pas admin local

**Given** un test smoke sur les routes legacy bloquées
**When** des requêtes synthétiques tapent les URLs bloquées
**Then** elles retournent toutes la redirection ou le 410 attendu — aucune ne sert encore du HTML legacy

*Volet 4 — Sunset progressif*

**Given** la story est livrée et stabilisée 2 semaines en prod
**When** aucun log d'appel aux helpers de délégation legacy non-shimés (les fonctions encore définies en AD-direct dans `sambaedu/includes/ldap.inc.php`) n'apparaît
**Then** une PR de cleanup peut retirer définitivement les définitions `have_delegation` / `list_delegations*` / `type_delegation` / `search_delegations` du legacy embarqué (le shim minimal de `have_delegation` reste en place pour `applications.inc.php`)

**Décisions à trancher en amont :**

- **Format de retour de `have_delegation` shimée** : booléen suffit pour les 5 sites identifiés (l'API est binaire). Pas besoin de reconstituer la structure complète des entrées AD.
- **Sémantique de `get_local_admin_right`** : analyse de la fonction `set_local_admin_right` et des call sites pour décider si la feature "élévation temporaire admin local" reste vivante (alors conserver le legacy tel quel) ou si elle dégrade en `0` constant (perte fonctionnelle minimale).
- **Mécanisme de blocage routing** : middleware Laravel sur prefix `/sambaedu/*` (whitelist), ou réécriture côté Apache/Nginx ? Préférer Laravel pour la maintenabilité.

---

## Epic 8 : Réseau (DHCP/DNS) SER

*Le responsable peut gérer les réservations DHCP, consulter les baux actifs, et importer des réservations en masse.*

> **FR21 (DNS) — Investigation préalable requise :** Analyser dans le legacy comment le DNS est géré (intégré au Samba AD DC avec enregistrements automatiques, ou serveur DNS séparé avec CRUD manuel ?). Selon les trouvailles, FR21 générera une story dédiée ou se fondera dans Story 8.1.

### Story 8.1 : Gestion des Réservations DHCP et Baux Actifs

> **Prérequis — Lecture Legacy :** Consulter l'implémentation legacy pour comprendre le format de configuration DHCP utilisé, les commandes de rechargement du service, et comment les réservations sont liées aux machines de l'inventaire.

As a **responsable de collège**,
I want consulter et gérer les réservations DHCP et les baux actifs, et importer des réservations en masse,
So que chaque machine du parc dispose d'une adresse IP fixe sans configuration manuelle poste par poste.

**Acceptance Criteria :**

**Given** je consulte la page réseau
**When** la page se charge
**Then** je vois la liste des réservations DHCP (nom, MAC, IP réservée) et les baux actifs actuels

**Given** j'ajoute une réservation DHCP
**When** je saisis le nom, l'adresse MAC et l'IP souhaitée et valide
**Then** la réservation est créée via `DhcpService` et le service DHCP est rechargé
**And** la réservation apparaît dans la liste

**Given** je modifie ou supprime une réservation
**When** je confirme
**Then** la modification est appliquée et le service DHCP est rechargé

**Given** j'importe un fichier de réservations en masse
**When** l'import est traité
**Then** les réservations valides sont créées ou mises à jour
**And** un rapport indique les lignes importées et les erreurs avec leur raison

**Given** une opération DHCP échoue
**When** l'erreur est retournée par `DhcpService`
**Then** un message explicite est affiché — la configuration précédente reste active

---

## Epic 9 : Déploiement Windows SER

*Le responsable peut gérer les GPOs, les packages WPKG, les scripts de démarrage Windows, et consulter les logs d'installation.*

> **Note transversale :** Toutes les stories de cet épic nécessitent une investigation legacy approfondie (zone 🔴🔴 critique dans l'Architecture). Chaque méthode implémentée dans `Services/Legacy/` doit porter un commentaire indiquant le fichier legacy source et la raison du refactoring futur (NFR14).

### Story 9.1 : Gestion des GPOs ❌ ANNULÉE — remplacée par Epic 16

> **Annulation 2026-05-01** : la story PAUSED est remplacée par l'**Epic 16 — Gestion native des GPOs** (6 stories cadrées). Le shim 1bis.18 reste opérationnel en transition. La Story 9.1 a été conservée dans l'historique du document à titre de référence — toute nouvelle implémentation doit s'inscrire dans Epic 16.

---

### Story 9.2 : Gestion des Packages WPKG et Association aux Profils ✅

> **Harnais de non-régression existant** — la story 1bis.11 (cloisonnement legacy `wpkg` en TDD à 3 niveaux) a produit un harnais complet de tests workflow (import dépôt, parc + applis, profil → `profiles_xml_out` → parse XML, enregistrement poste + maintenance, MEF, suppression cascade). Ces tests **doivent continuer à passer** sur l'implémentation native — c'est la garantie de parité comportementale avec le legacy.
>
> **Décisions d'architecture tranchées :**
> 1. `packages.xml` — **base = source de vérité, fichier disque = artefact généré.** Le modèle `Application.xml` suffit.
> 2. **Binaires** — **partage Samba (statu quo).** Pas de migration HTTP — le client WPKG côté poste devrait être modifié.
> 3. **Rapports** — **statu quo côté client (.txt Samba) + import/parse côté Laravel.** Scope Story 9.4.
> 4. **MEF** — **supprimée, pas réimplémentée.** Feature morte.
> 5. **`wpkg_ldap_update.php`** — **obsolète.** Couvert par `AdSyncService` + `Workstation`/`WorkstationGroup`.
> 6. **Cluster inter-SE** — **exclu du scope 9.2.** À terme le dépôt sera centralisé via controlHub — responsabilité du controlHub (irundoo), hors scope SER.
>
> **Architecture des services** (détail dans `applications-backend.md`) :
> - `DepotSyncService` — sync catalogue distant → `depot_applications`
> - `PackageInstallerService` — téléchargement multi-fichiers, hash, post-traitement
> - `PackagesXmlService` — génération/maintenance du `packages.xml` local
> - `AppStoreService` — orchestrateur/façade (seul service injecté dans Livewire)
> - `FileManagerService` — enrichi avec `downloadWithHash()`, `extractTarGz()`, `extractZip()`, `hashFile()`

As a **responsable de collège**,
I want gérer les packages WPKG et les associer aux profils applicatifs,
So que les logiciels corrects soient installés automatiquement sur les postes au démarrage selon leur profil.

#### Story 9.2.1 : Extraction des services depuis `AppStoreService` ✅

Refactoring sans changement de comportement. Découper le god-service en services spécialisés.

**Acceptance Criteria :**

**Given** le code est refactoré en 4 services
**When** je sync un dépôt, installe une app, ou consulte le catalogue
**Then** le comportement est strictement identique à avant le refactoring
**And** aucune régression sur les tabs depot/applications

#### Story 9.2.2 : Purge des apps disparues du dépôt + fix packages.xml ✅

**Acceptance Criteria :**

**Given** le dépôt distant a retiré une application
**When** je synchronise le dépôt
**Then** l'application disparaît de `depot_applications`

**Given** des applications sont installées
**When** le `packages.xml` est régénéré
**Then** les packages sont triés alphabétiquement par ID
**And** les noeuds SambaEdu (`<download>`, `<delete>`, `<untar>`, `<unzip>`) sont absents du XML généré

#### Story 9.2.3 : Téléchargement XML recipe + vérification SHA-512 ✅

**Acceptance Criteria :**

**Given** j'installe une application depuis le dépôt
**When** le XML recipe est téléchargé
**Then** le hash SHA-512 est vérifié contre `depot_applications.xml_sha`
**And** les directives (`<download>`, `<delete>`, `<untar>`, `<unzip>`) sont correctement extraites

**Given** le hash SHA-512 du XML recipe ne correspond pas
**When** la vérification échoue
**Then** le fichier est supprimé et l'installation est annulée avec un message d'erreur explicite

#### Story 9.2.4 : Téléchargement multi-fichiers avec hash et skip ✅

**Acceptance Criteria :**

**Given** un package a N noeuds `<download>` dans son XML recipe
**When** l'installation s'exécute
**Then** les N fichiers sont téléchargés aux chemins définis par `saveto` (relatifs à `config('sambaedu.wpkg.storage_path')`)
**And** chaque fichier est vérifié (SHA-256 prioritaire, fallback MD5)
**And** la progression est mise à jour dans `InstallationLog` (colonnes `progress`, `downloaded_bytes`, `message`)

**Given** un fichier existe déjà au bon chemin avec le bon hash
**When** le téléchargement de ce fichier est tenté
**Then** le téléchargement est sauté (skip)

**Given** un hash mismatch sur un fichier
**When** la vérification échoue
**Then** l'installation complète est annulée (atomique) — aucun fichier n'est déplacé vers son chemin final

#### Story 9.2.5 : Post-traitement (delete, untar, unzip) ✅

**Acceptance Criteria :**

**Given** tous les téléchargements sont terminés avec succès
**When** le post-traitement s'exécute
**Then** les fichiers listés dans `<delete>` sont supprimés
**And** les archives `.tar.gz` listées dans `<untar>` sont extraites au bon chemin
**And** les archives `.zip` listées dans `<unzip>` sont extraites au bon chemin
**And** le statut `PostProcessing` apparaît dans le log d'installation

#### Story 9.2.6 : Orchestration complète + nettoyage ✅

**Acceptance Criteria :**

**Given** je sélectionne des applications dans le tab Dépôt et clique "Ajouter au catalogue"
**When** le flow s'exécute
**Then** pour chaque app : download XML recipe → vérif hash → multi-download → post-traitement → mise à jour base → régénération `packages.xml`
**And** les fichiers sont aux bons chemins dans `/var/sambaedu/unattended/install/`
**And** le `packages.xml` local est correct et servi aux clients WPKG

**Given** une erreur survient pendant l'installation
**When** l'erreur est catchée
**Then** le statut et le message d'erreur sont clairs dans le toast
**And** l'application est marquée en erreur dans la base

> **Dépendances** : 8.2.2 et 8.2.3 sont parallélisables après 8.2.1. Chemin critique : 8.2.1 → 8.2.3 → 8.2.4 → 8.2.5 → 8.2.6 (+ 8.2.2)

---

### Story 9.3 : Gestion des Scripts de Démarrage Windows ❌ ANNULÉE — remplacée par Epic 17

> **Annulation 2026-05-01** : la story PAUSED est remplacée par l'**Epic 17 — Scripts de Démarrage Windows** (4 stories cadrées). Frontière avec Epic 16 documentée dans l'introduction d'Epic 17. La Story 9.3 a été conservée dans l'historique du document à titre de référence.

---

### Story 9.4 : Logs WPKG et Rapports d'Installation ✅

As a **responsable de collège**,
I want consulter les logs WPKG et les rapports d'installation des packages sur les postes,
So que je sache quels logiciels ont été installés, lesquels ont échoué, et sur quelles machines.

**Acceptance Criteria :**

**Given** des postes ont exécuté WPKG au démarrage
**When** je consulte la section logs WPKG
**Then** je vois les rapports par poste avec la liste des packages traités, leur statut (installé, échoué, déjà présent) et l'horodatage

**Given** je filtre les logs par machine ou par package
**When** j'applique le filtre
**Then** seuls les logs correspondants sont affichés

**Given** un package a échoué sur plusieurs postes
**When** je consulte le rapport
**Then** les machines en échec sont clairement identifiées avec le message d'erreur associé

---

### Story 9.5 : Parsing Logs WPKG et Affichage Détaillé des Erreurs ✅

*Extension de la story 9.4 — parse fin des logs WPKG (messages d'erreur MSI/exec, codes retour, trace stack) et affichage structuré dans l'interface des rapports.*

> **Note :** spec complète à produire lors de la création de la story via `bmad-create-story`. Implémentation en cours (statut `review` dans sprint-status).

---

## Epic 10 : Intégrations Académiques SER

*Gestion des apps autorisées à l'installation selon le contexte (standalone ou avec controlHub connecté).*

### Story 10.1 : Gestion des Apps Autorisées (Standalone vs controlHub)

As a **responsable de collège / administrateur irundoo**,
I want que SER gère les applications autorisées à l'installation selon le contexte (standalone ou avec controlHub connecté),
So que le catalogue d'apps installables reflète toujours la politique définie au bon niveau (local ou central).

**Acceptance Criteria :**

**Given** SER fonctionne en mode standalone (sans controlHub)
**When** le catalogue d'apps autorisées est consulté
**Then** les apps autorisées sont celles définies localement dans SER

**Given** un controlHub (irundoo) est connecté à SER
**When** le controlHub pousse une liste d'apps autorisées
**Then** SER adopte cette liste comme référence et ignore la liste locale
**And** les apps non autorisées par le controlHub ne peuvent plus être associées aux AppProfiles

**Given** le controlHub se déconnecte
**When** SER repasse en mode standalone
**Then** SER revient à la liste locale d'apps autorisées — pas d'état bloqué

---

## Epic 11 : Gestion des Établissements, Itinérants & Intégrations irundoo

*irundoo maintient les liens user↔UAI, gère les itinérants, dispatche le GPEI, et prépare l'infrastructure de réception users pour la Phase 2 Keycloak.*

> **Note Keycloak :** Keycloak est côté irundoo — il peut être intégré à n'importe quel moment sans contraindre le planning SER. Les stories dépendant de Keycloak sont codées en avance et activées une fois Keycloak en place.

### Story 11.1 : Gestion des Liens Utilisateur↔UAI

As a **administrateur irundoo**,
I want maintenir les liens entre chaque utilisateur et ses établissements (UAI),
So que irundoo sache à quelles instances SER chaque utilisateur est rattaché.

**Acceptance Criteria :**

**Given** je consulte la fiche d'un utilisateur dans irundoo
**When** j'accède à ses rattachements
**Then** je vois la liste des UAI auxquels il est lié avec son rôle dans chaque établissement

**Given** j'ajoute un lien user↔UAI
**When** je valide
**Then** le lien est persisté et l'utilisateur est considéré comme membre de cet établissement

**Given** je supprime un lien user↔UAI
**When** je confirme
**Then** l'utilisateur n'est plus rattaché à l'établissement et ses droits dans ce SER sont révoqués

---

### Story 11.2 : Gestion des Attributs Itinérants par Lien user↔UAI

As a **administrateur irundoo**,
I want définir des attributs spécifiques pour un utilisateur itinérant sur chaque UAI où il est présent,
So que ses droits dans chaque établissement visité reflètent son statut d'itinérant (quota réduit, profil restreint).

**Acceptance Criteria :**

**Given** un utilisateur est lié à un UAI en tant qu'itinérant
**When** je configure ses attributs pour cet UAI
**Then** je peux définir un quota réduit et un profil restreint spécifiques à ce lien

**Given** un utilisateur itinérant se connecte sur une instance SER
**When** SER lit son statut depuis l'AD
**Then** ses droits différenciés sont appliqués automatiquement (définis par irundoo en amont)

---

### Story 11.3 : Import GPEI et Dispatch par UAI

> **Prérequis — Lecture Legacy :** Analyser le format des fichiers GPEI et le comportement legacy lors de l'import (champs, entités mises à jour, règles de fusion…). Mini-brainstorm avec Henri avant d'implémenter.

As a **administrateur irundoo**,
I want importer un fichier GPEI et que irundoo dispatche les mises à jour vers les instances SER concernées selon leur UAI,
So que les données académiques (élèves, enseignants, classes) sont mises à jour dans chaque établissement sans import manuel.

**Acceptance Criteria :**

**Given** irundoo reçoit un fichier GPEI
**When** le parsing est effectué
**Then** les entités (utilisateurs, classes, groupes) sont extraites et associées aux UAI correspondants

**Given** le dispatch est déclenché en Phase MVP (sans Keycloak)
**When** les mises à jour sont appliquées
**Then** les données sont écrites dans l'AD central qui se synchronise automatiquement avec les ADs locaux de chaque SER

**Given** le dispatch est déclenché en Phase 2 (Keycloak actif)
**When** les mises à jour sont appliquées
**Then** irundoo dispatche directement par UAI vers chaque SER via l'API controlHub

**Given** le fichier GPEI contient des données invalides
**When** le parsing échoue sur certaines lignes
**Then** les lignes valides sont traitées et un rapport d'erreur explicite est produit

---

### Story 11.4 : Infrastructure de Réception Users depuis controlHub (Phase 2)

> **Note :** Code préparé en avance. Activé uniquement une fois Keycloak substitué à l'AD central. En MVP, l'AD central gère la synchronisation automatiquement.

As a **développeur**,
I want que SER dispose d'un endpoint API prêt à recevoir des users poussés par le controlHub,
So que la migration vers Keycloak ne nécessite pas de développement d'urgence côté SER.

**Acceptance Criteria :**

**Given** l'endpoint `POST /api/v1/users/sync` est implémenté
**When** le controlHub pousse des users avec leurs attributs UAI
**Then** SER reçoit et valide la payload
**And** les users sont créés ou mis à jour dans PostgreSQL selon le schéma SER
**And** l'endpoint retourne le format API uniforme `{ success, message, ... }`

**Given** Keycloak n'est pas encore actif
**When** l'endpoint est appelé
**Then** il est désactivé via feature flag et retourne une erreur explicite

---

### Story 11.5 : Filtrage et Transmission par UAI vers chaque SER (Phase 2 Keycloak)

> **Note :** Opérationnel une fois Keycloak substitué à l'AD central. Keycloak peut être intégré côté irundoo à tout moment sans contraindre le planning SER.

As a **administrateur irundoo**,
I want que irundoo transmette à chaque instance SER uniquement les utilisateurs relevant de son UAI,
So que chaque SER ne gère que ses propres utilisateurs (locaux + itinérants) sans avoir accès aux données des autres établissements.

**Acceptance Criteria :**

**Given** Keycloak est actif et remplace l'AD central
**When** irundoo dispatche des users vers une instance SER
**Then** seuls les users rattachés à l'UAI de cette instance sont transmis (locaux + itinérants de cet UAI)
**And** aucun user d'un autre établissement n'est accessible dans ce SER

**Given** Keycloak n'est pas encore actif
**When** cette fonctionnalité est appelée
**Then** elle est désactivée via feature flag — la synchronisation AD classique reste active

---

## Epic 12 : Revue par l'équipe

*Epic de gouvernance « rolling ». Point de revue collaborative récurrente avec l'équipe terrain pour rouvrir et amender la matrice profils × droits applicatifs SER. Chaque story 12.x est une itération de validation déclenchée par un retour terrain, un nouveau profil métier, ou une question matrice soulevée par une story Epic 7 ou métier. La Story 12.1 a posé la première version de la matrice ; les itérations suivantes patchent ce livrable.*

> **Portée :** revue, validation, amendement de la matrice — pas d'implémentation code. L'output canonique est `_bmad-output/planning-artifacts/profiles-rights-matrix.md` (référence vivante). Les tickets de retombée fonctionnels sont créés dans Epic 7 ou l'epic métier concerné.

### Story 12.1 : Matrice Profils × Droits Applicatifs ✅ LIVRÉE (2026-04-22)

> **Livrable** : `_bmad-output/planning-artifacts/profiles-rights-matrix.md` — validé avec Henri le 2026-04-22. Décisions actées : `SambaRole::ReferentNumerique` aligné sur legacy `RefNum`, `WallpaperManage` retiré de `ComputerAdmin`, `AppCustomize::legacyRight()` remappé sur `ComputerInstall` (convention bit représentant du composite `SE_COMPUTER_ADMIN`), `Prof` reçoit `UserPasswordInit` **scopé par classe** (Policy à écrire en Story 7.2), labels `ComputerElevate`/`ComputerInstall` clarifiés. Zones grises non bloquantes documentées §10 : scoping `UserRead` pour `Prof` + définition "cas itinérant" — à trancher au démarrage Story 7.2.

As a **product manager**,
I want une matrice exhaustive recensant chaque profil utilisateur et les droits applicatifs qui lui sont attribués dans SER,
So que l'implémentation des Gates/Policies Spatie (Epic 7) repose sur une spécification explicite, revue et validée — pas sur une interprétation implicite du legacy.

**Acceptance Criteria :**

**Given** l'audit des profils legacy est effectué (bitmask `rights`, appartenances AD, délégations, profils PHP legacy)
**When** la matrice est rédigée
**Then** tous les profils existants sont listés (admin, responsable de collège, enseignant, équipe, élève, délégué, itinérant, administrateur irundoo…)
**And** chaque profil est associé à son périmètre d'action (portée globale, établissement, classe, parc, user…)

**Given** l'inventaire des droits applicatifs SER est effectué (par domaine : users, machines, fichiers, impression, réseau, GPO, délégations, apps autorisées…)
**When** la matrice est rédigée
**Then** chaque droit est documenté avec son nom de Permission Spatie cible, sa description, et les profils qui en disposent par défaut

**Given** la matrice est produite
**When** elle est revue avec Henri
**Then** les cas limites sont explicités (droits délégables, héritages, conflits profils multiples, cas itinérants inter-UAI)
**And** la matrice est figée comme spec d'entrée pour Epic 7

---

## Epic 13 : Refonte BBB & Compat BBB 3.x

**Statut :** 🕒 post-prod — à planifier après mise en production MVP
**Contexte étudié :** 2026-04-20 — analyse du module legacy `legacy/modules/bbb/` + lib `sambaedu/bigbluebutton-api-php` 2.0.12 (cf. conversation PM Henri/John).

*Refondre le module de visioconférence BBB en Laravel natif et le rendre compatible BBB serveur 3.x. Lève deux dettes simultanées : migration Laravel (cohérence SER, suppression APCu du chemin critique, élimination du shim legacy Tier 3) et compatibilité serveur BBB moderne (les passwords de salon sont supprimés en BBB 3.0).*

> **Esprit :** epic de cadrage léger, pas d'engagement de détail à ce stade. Rédiger le PRD et les stories détaillées **au moment de démarrer**, pas maintenant. Ce bloc sert de mémoire pour les découvertes déjà faites.

### Mémoire de l'étude 2026-04-20

**État actuel (module legacy shimmé, Tier 3)**
- 6 fichiers PHP dans `legacy/modules/bbb/` : `config.php` (admin — déclare les serveurs BBB), `create.php` (form prof), `launch.php` (cœur — create & join meetings via API BBB), `join.php` (liste salons rejoignables), `records.php` (enregistrements), `refresh.php` (endpoint script APCu, servi raw).
- Lib cliente PHP : fork `sambaedu/bigbluebutton-api-php` **2.0.12** — ciblant BBB serveur **2.4–2.6** (2021-2022). Raison du fork : 3ᵉ argument cURL options passé au constructeur `BigBlueButton(url, secret, curlopts)` pour le proxy d'établissement (non standard dans l'upstream `littleredbutton/bigbluebutton-api-php`).
- Auth/SSO : **pas de SSO natif côté serveur BBB** — le serveur ne fait confiance qu'au secret partagé + checksum. Le module **est** le SSO : il authentifie via l'AD (shim LDAP Eloquent `legacy/ldap.inc.php`), puis signe les URLs de join. Même rôle que Greenlight ou bbb-lti pour leurs écosystèmes respectifs.
- Cache runtime : **APCu** (`meeting_info`, `visio_ext`) — dépendance bloquante, fatal si APCu absent (cf. `apcu_risk.md` en mémoire utilisateur). À remplacer par des tables Eloquent.
- Config serveurs : stockée en **3 colonnes CSV** (`bbb_server_base_url`, `bbb_secret`, `bbb_server_scalelite`) dans la table legacy `config`. Crasse manifeste — à remplacer par une table `bbb_servers`.
- Load balancing maison (appel `getMeetings` sur chaque serveur à chaque création pour choisir le moins chargé) — redondant avec Scalelite.

**Ce qui change entre BBB 2.x et BBB 3.x (impacts refonte)**
- ⚠️ **Passwords de salon supprimés** en BBB 3.0 (dépréciés 2.6) : `setAttendeePassword`/`setModeratorPassword` retirés. Remplacés par `role=MODERATOR|VIEWER` dans l'URL de join signée. **Simplifie énormément** le module (plus besoin de stocker les PW par meeting dans APCu).
- **Lib cliente à migrer** : fork 2.0.12 → `littleredbutton/bigbluebutton-api-php` **5.x** upstream. Quelques setters renommés, namespaces proches. Le fork n'a plus lieu d'être si on gère le proxy cURL via une impl `TransportInterface` (Guzzle) propre.
- **`disabledFeatures`** (string CSV) remplace les ~10 appels `setLockSettings*` éparpillés.
- **Plugin manager** 3.x : hors scope refonte.

**Dépendances / intégrations à préserver**
- Le `meetingId` encode la visibilité (`etab|world|classe|private`) + hash LDAP base DN + hash login + hash classes → permet d'énumérer les salons rejoignables par un user. **À préserver** ou à repenser via une table `bbb_meetings` avec relations explicites.
- Invités externes via `/visio/?salon=<hash>` (cache APCu `visio_ext`) → à migrer en **tokens signés Laravel** (table `bbb_guest_tokens`).
- Proxy cURL d'établissement (`curl_proxy_options`) → à réinjecter au niveau Guzzle dans la nouvelle lib.
- UI admin, form prof, liste salons, enregistrements → conversion filesystem-based router SER (cf. CLAUDE.md projet).

### Points de décision à trancher (au démarrage, pas maintenant)

1. **Scalelite only, ou on garde le LB maison ?** Scalelite simplifie le `BbbService` de moitié. Le code legacy supporte déjà `bbb_server_scalelite` → chemin tracé.
2. **Version serveur BBB cible côté ops** : 2.7 (transition) ou 3.x (cible définitive) ? Recommandation : abstraire derrière une interface `BbbClient` (impl v2 + v3) pour livrer la refonte Laravel **sans attendre** la migration serveur BBB.
3. **Modélisation Eloquent** : `BbbServer`, `BbbMeeting`, `BbbGuestToken`. À valider lors du PRD de l'epic.
4. **Intégration UX SER** : routes filesystem `pages/bbb/index.blade.php` (liste/join), `pages/bbb/new/` (create), `pages/bbb/config/` (admin), `pages/bbb/records/`. Livewire SFC pour les forms. Modale réutilisable, trait `WithToasts`.
5. **Périmètre étude vs refonte** : en profite-t-on pour ajouter des features (enregistrement différé, compte rendu IA, etc.) ? A priori **hors scope** — à documenter en "idées futures".

### Stories pressenties (esquisse, non validée)

- **Story 13.1** — Modèles Eloquent + migration config legacy CSV → tables `bbb_servers`, `bbb_meetings`, `bbb_guest_tokens`.
- **Story 13.2** — Interface `BbbClient` + impl `BbbClientV3` (lib `littleredbutton/bigbluebutton-api-php` 5.x) : compat BBB 3.x, suppression passwords, `disabledFeatures`, proxy cURL propre.
- **Story 13.3** — Routes filesystem Laravel + pages Livewire (config admin, form prof, liste/join, records).
- **Story 13.4** — Invités externes : tokens signés Laravel remplaçant `visio_ext` APCu.
- **Story 13.5** — Suppression du module legacy `legacy/modules/bbb/` + nettoyage shim `legacy/stubs/bbb.inc.php`.

### Prérequis & références

- **Prérequis bloquant** : mise en production MVP SER.
- **Prérequis techniques** : Epic 1 (AuthGuard, catchall) ✅, Epic 2 (`User::is_eleve`) ✅.
- **Décision ops préalable** : version serveur BBB cible + Scalelite on/off.
- **Code actuel** : `legacy/modules/bbb/*.php`, `legacy/stubs/bbb.inc.php`, `tests/Feature/LegacyModuleBbbTest.php`.
- **Lib fork actuel** : `sambaedu/bigbluebutton-api-php` 2.0.12 dans `composer.json`.
- **Lib upstream cible** : `littleredbutton/bigbluebutton-api-php` 5.x.
- **Doc BBB API** : https://docs.bigbluebutton.org/development/api/

---

## Epic 14 : Refactoring & Sortie du Shim Legacy

*Epic technique « rolling » — agrège deux familles de chantiers : (1) refactor / dette technique découverts au fil des stories métier, (2) sortie progressive du shim legacy (`legacy/modules/`) vers une implémentation SER native avec parité fonctionnelle stricte. Aucune story 14.x ne livre de changement fonctionnel observable pour l'utilisateur final. Les stories sont décorrélées et peuvent être priorisées indépendamment.*

> **Garde-fou :** si une story 14.x se met à embarquer un livrable fonctionnel (nouveau comportement, nouvel écran, nouvelle règle métier), elle est requalifiée et déplacée dans son epic métier d'origine. L'epic 14 reste un coffre à dette pure. Pour les stories de sortie du shim : la suppression du proxy legacy + retrait du catchall ne comptent pas comme un changement fonctionnel observable tant que la parité est respectée.

### Story 14.1 : Isoler le DTO `App\Types\User` au pipeline LDAP→SQL

As a **développeur SER**,
I want **le DTO `App\Types\User` renommé `App\LdapModels\AdUser`, déplacé à proximité de `LdapUser`, et retiré des pages Livewire utilisateur au profit de l'Eloquent `App\Models\User`**,
So que **plus aucun composant Livewire ne wrappe inutilement l'Eloquent dans un DTO de transit LDAP, et que l'usage du DTO soit physiquement contraint au pipeline `LdapUser→AdUser→SQL` (sa raison d'être historique)**.

**Contexte :** audit story 7.2 — `UserService::getByLoginFromSql()` reconstruit le DTO à partir des colonnes SQL pour ne pas passer un Eloquent à Blade. Maintenant que `App\Models\User` est wireable nativement en Livewire 3 (avec `#[Locked]`) et que les Gates/Policies Spatie ont besoin de l'Eloquent (`HasRoles`, relations `userGroups`), ce wrap est stérile et a provoqué l'apparition d'une propriété `$sqlUserModel` parallèle pendant la 7.2. 5 propriétés du DTO (`idEnt`, `idAaf`, `idSiecle`, `idGpei`, `idNc`) sont du dead code (jamais populées).

**Acceptance Criteria :**

**Given** `app/Types/` existe encore en début de story
**When** la story est livrée
**Then** le dossier `app/Types/` est supprimé
**And** `App\LdapModels\AdUser`, `App\LdapModels\UserSearchCriteria`, `App\LdapModels\UserSearchResult` existent avec le contenu des anciennes classes (mêmes propriétés et méthodes publiques)
**And** `LdapUser::toBusinessObject()` retourne `AdUser`

**Given** la page profil utilisateur `pages/users/[login]/index.blade.php`
**When** on consulte le composant Livewire
**Then** la propriété typée est `public ?App\Models\User $user = null` avec `#[Locked]`
**And** la propriété temporaire `$sqlUserModel` (béquille 7.2) est supprimée
**And** plus aucun appel à `UserService::getByLoginFromSql()` ; remplacé par `User::where('login', $login)->first()`
**And** `loadSpatieState()` lit rôles/permissions directement sur `$this->user` sans re-query

**Given** `App\Models\User`
**When** une vue consomme l'API attendue par l'ancien DTO
**Then** 4 accessors d'alias existent : `etabCode` (= `school_code`), `etabName` (= `school_name`), `objectGuidDisplay` (= `ad_guid`), `isDisabled(): bool` (= `!$this->is_active`)
**And** un test unitaire couvre les 4 accessors

**Given** `_partials/technical-identifiers.blade.php`
**When** la fiche utilisateur est rendue
**Then** les 5 blocs `idEnt / idAaf / idSiecle / idGpei / idNc` (dead code) sont retirés
**And** `objectGuidDisplay` est conservé

**Given** la suite de tests existante
**When** la story est livrée
**Then** `UserPolicyResetPasswordScopedTest`, `UserServiceBulkResetTest`, `PermissionServiceUnionTest` et la suite Feature globale passent sans régression
**And** `grep -rn "App\\Types\\User" app/ resources/` ne retourne aucun résultat (hors PHPDoc / commentaires migratoires éventuels)

**Hors scope (futures stories de l'epic 14) :** audit des autres pages Livewire qui consomment encore le DTO en lecture, suppression des champs morts du DTO côté pipeline LDAP (idEnt/Aaf/Siecle/Gpei/Nc) après confirmation, refactor du `UserResource` API.

---

### Story 14.2 : Module `display` natif Laravel ⏸️ PAUSED

> **Statut :** ⏸️ PAUSED — non prioritaire (le shim Story 1bis.4 ✅ couvre le besoin). À reprendre quand un déclencheur produit ou une dette d'asset le justifie.

As a **développeur SER**,
I want **réimplémenter nativement dans Laravel le module legacy `display` (page publique de slideshow Reveal.js + admin CRUD des flux RSS / diaporama public)**,
So que **le proxy legacy `legacy/modules/display/` puisse être retiré du catchall et que la page d'affichage dynamique soit maintenable dans le standard SER (routes filesystem-based, Livewire SFC, Cache facade au lieu d'APCu, persistance via DB ou JSON Laravel).**

**Contexte :**
- Le module legacy fait ~550 lignes PHP au total : `display/index.php` (rendu public Reveal.js, ~118 lignes), `display/config.php` (admin flux + diaporama, ~40 lignes), `display/screen.php` (stub mort « en cours de développement »), `includes/display.inc.php` (CRUD config, ~173 lignes), `includes/inc.php` (helpers RSS Guzzle + scan images, ~189 lignes).
- Aujourd'hui shimmé par Story 1bis.4 ✅ (Tier 1, copie dans `legacy/modules/display/`, includes via stubs prépendés, Guzzle/APCu disponibles).
- Le besoin n'est pas pressant : le shim fonctionne et couvre la page publique + l'admin.
- Dépendances déjà présentes côté SER : Guzzle (HTTP), `Cache` facade (remplace APCu), Reveal.js / CSS / JS à recopier tels quels dans `public/`.

**Acceptance Criteria :**

**Given** la route SER native `/display/` (filesystem-based, sans auth)
**When** un client (mini-PC kiosque, navigateur public) y accède
**Then** la page sert le slideshow Reveal.js avec parité fonctionnelle vs legacy : sections d'images plein écran (depuis `/var/sambaedu/Docs/images/` ou volume équivalent) + sections de flux RSS (titre, description, image enclosure ou fallback)
**And** la redirection conditionnelle vers un diaporama public est conservée (match `REMOTE_ADDR` côté reverse proxy : tenir compte de `X-Forwarded-For` / `trustProxies`)
**And** le cache des flux RSS et de la liste d'images est géré via `Cache::remember()` avec TTL équivalent (300 s legacy)

**Given** la page d'admin SER native (sous `/app/display/` ou équivalent route SER, accès `SE_ADMIN`)
**When** un administrateur SER consulte la page
**Then** il peut **lister, ajouter, supprimer** les flux RSS (nom, URL, durée, nombre d'items) avec parité du formulaire legacy
**And** il peut configurer l'**URL d'un diaporama public** (avec liaison à une IP de poste/kiosque) avec parité legacy
**And** la persistance utilise un mécanisme SER standard (table Eloquent dédiée OU fichier JSON via `Storage::` — choix à arbitrer en début de story)
**And** les données de config legacy existantes (`read_config_display()`) sont migrées en one-shot vers le nouveau stockage (script de migration ou seeder)

**Given** la page legacy `display/screen.php` (stub mort « en cours de développement »)
**When** la story est livrée
**Then** elle n'est **pas** réimplémentée — explicitement supprimée du périmètre

**Given** les assets statiques Reveal.js + CSS + JS (`reveal.js`, `clock.js`, `app.js`, `moment-*.min.js`, etc.)
**When** la page native s'affiche
**Then** ils sont servis depuis `public/display/` ou via Vite, sans dépendre de `legacy/modules/display/`

**Given** le module legacy `legacy/modules/display/`
**When** la story est livrée
**Then** le dossier est supprimé
**And** l'entrée correspondante dans le catchall (Story 1.2) est retirée
**And** un test feature couvre au moins : (1) GET `/display/` répond 200 et contient le markup Reveal, (2) la redirection IP-based fonctionne quand un diaporama est configuré, (3) l'admin refuse l'accès non-admin

**Given** la migration achevée
**When** on consulte le dashboard `/admin/legacy-monitor`
**Then** aucune erreur récurrente liée à `display` n'apparaît
**And** Story 1bis.4 est marquée comme **superseded** par 15.1

**Hors scope :**
- Refonte UX du slideshow ou du formulaire admin (iso-fonctionnel uniquement)
- Internationalisation
- Multi-tenant des flux par établissement
- Ajout du contrôle d'allumage/extinction des écrans (feu follet de `screen.php`)

**Notes / dette technique observée à reprendre :**
- Le legacy ne valide pas les URL RSS soumises (vecteur SSRF potentiel) — durcir au passage.
- Le legacy fait `simplexml_load_string()` sur la réponse Guzzle sans timeout XML — borner la taille de payload.
- Le legacy expose `IMG/IMG/` via lien symbolique vers `/var/sambaedu/Docs/Display` — clarifier le contrat de chemin natif.

---

### Story 14.3 : Module `oauth2` natif Laravel ⏸️ PAUSED

> **Statut :** ⏸️ PAUSED — non prioritaire (le shim Story 1bis.5 ✅ couvre le besoin via `legacy/modules/oauth2/`). À reprendre quand l'authentification ENT/OpenENT devient un cap produit (ex: bascule Phase 2 Keycloak / dispatch UAI Epic 11) ou quand les écritures `$_SESSION` legacy deviennent un blocage.

As a **développeur SER**,
I want **réimplémenter nativement dans Laravel le flux OAuth2 OpenENT (`/oauth2/login.php` + `/oauth2/callback.php`) en passant par un AuthGuard SER + driver Socialite-like**,
So que **le proxy legacy `legacy/modules/oauth2/` puisse être retiré du catchall, que le state OAuth ne fuite plus dans `$_SESSION` legacy, et que la connexion ENT s'inscrive dans le pipeline d'auth Laravel standard (Auth::guard / session Laravel / event AuthenticatedEvent).**

**Contexte :**
- Le module legacy fait ~157 lignes : `login.php` (29 lignes — init `League\OAuth2\Client\GenericProvider` + redirect authorize) + `callback.php` (128 lignes — échange code, fetch resource owner, requête AD via `search_ad()`, écriture `$_SESSION['login']` / `$_SESSION['auth']='ent'` / `$_SESSION['accesstoken']`).
- Aujourd'hui shimmé par Story 1bis.5 ✅ (Tier 1, `League\OAuth2\Client\GenericProvider` résolu via Composer Laravel, `search_ad()` via shim LDAP→Eloquent story 1bis.2).
- Provider OpenENT générique paramétré via `$config['openent_*']` (clientId, secret, redirect_uri, urls authorize/token/userinfo).
- Cache APCu utilisé pour `apcu_store($state, $accessToken)` et `apcu_store('ent_status', false)` en cas de quota_overflow.
- Le callback résout l'utilisateur AD via filtre LDAP combiné `title` (externalId) / `cn` (login) / `userprincipalname` car les userinfo ENT et l'AD ne renvoient pas toujours la même valeur de login.

**Acceptance Criteria :**

**Given** la route SER native `/oauth2/login` (Laravel, sans auth préalable)
**When** un utilisateur clique sur le bouton « Connexion ENT » depuis la page d'index
**Then** Laravel construit l'URL d'autorisation via un service `OpenEntOAuthService` (basé sur `League\OAuth2\Client\GenericProvider` ou Socialite custom provider)
**And** le `state` OAuth est stocké dans la **session Laravel** (`session()->put('openent.oauth2state', ...)`), plus dans `$_SESSION` legacy
**And** la redirection HTTP 302 vers l'URL authorize est émise

**Given** la route SER native `/oauth2/callback` recevant `code` + `state`
**When** le code est validé contre le state stocké (CSRF)
**Then** l'AccessToken est échangé via le provider
**And** le ResourceOwner (userinfo ENT) est lu
**And** un User Eloquent SER est résolu via `App\Models\User` (par `cn`, `title`/externalId, ou `userPrincipalName` selon la même logique de fallback que le legacy)
**And** `Auth::login($user)` est appelé sur le guard SER
**And** la `$_SESSION` legacy est synchronisée pour parité (via le bridge legacy déjà en place — pas d'écriture directe dans `$_SESSION`)
**And** la redirection finale pointe sur l'URL post-login SER (`/app` ou équivalent), plus sur `../index.html`

**Given** un échec OAuth (`quota_overflow`, `Invalid response received from Authorization Server`, IdentityProviderException, state invalide)
**When** le callback échoue
**Then** l'erreur est loggée via le système d'erreurs SER unifié (`/admin/legacy-monitor` cf. Epic 1bis), plus dans `/tmp/profil.log`
**And** un message utilisateur friendly est affiché
**And** le statut `ent_status=false` est mémorisé via `Cache::put('openent.status', false, ...)` au lieu de `apcu_store`

**Given** la persistance du token d'accès
**When** l'utilisateur est connecté
**Then** l'AccessToken est stocké via `Cache::` (clé namespacée par session/user, pas par state OAuth) avec gestion `refresh_token`
**And** le refresh est tenté automatiquement quand `$accessToken->hasExpired()` retourne true

**Given** le module legacy `legacy/modules/oauth2/`
**When** la story est livrée
**Then** le dossier est supprimé
**And** l'entrée correspondante dans le catchall (Story 1.2) est retirée
**And** Story 1bis.5 est marquée comme **superseded** par 15.2

**Given** la suite de tests SER
**When** la story est livrée
**Then** un test feature couvre : (1) `/oauth2/login` redirige bien vers l'URL authorize avec state stocké en session Laravel, (2) `/oauth2/callback` avec state valide connecte l'utilisateur résolu via le pattern de fallback `title|cn|userPrincipalName`, (3) state invalide → 403 / message d'erreur, (4) `quota_overflow` → cache `openent.status=false` + redirection
**And** un mock du provider OAuth2 et de l'AD/User Eloquent est utilisé
**And** un test E2E manuel décrit dans la story documente le cycle complet contre un OpenENT de qualif

**Hors scope :**
- Refonte UX de la page d'entrée (bouton ENT) — iso-fonctionnel
- Migration vers Keycloak (Phase 2 Epic 11)
- Multi-providers OAuth2 (Google, Microsoft, etc.) — Pronote/CAS/Google sont d'autres stories
- Provisioning automatique d'un compte SER si l'utilisateur ENT n'existe pas dans l'AD (le legacy `die()` dans ce cas — comportement conservé)

**Notes / dette technique observée à reprendre :**
- Le legacy écrit le state OAuth dans `$_SESSION['oauth2state']` puis vérifie `$_SESSION` côté callback : risque de collision avec d'autres flux qui touchent `$_SESSION`. Migrer en session Laravel résout proprement.
- `apcu_store($_GET['state'], $accessToken)` utilise le **state OAuth** comme clé de cache du token : clé non scopée à l'utilisateur, durée de vie indéterminée → revoir le keying.
- Le `error_log(..., 3, "/tmp/profil.log")` est en dur — basculer sur Laravel logging channel.
- Le filtre LDAP `(&(objectclass=user)(|(title=...)(cn=...)(userprincipalname=...@domain)))` mérite une couverture de tests dédiée car il porte la résolution multi-format ENT↔AD.
- Aucun rate-limiting sur `/oauth2/callback` côté legacy — ajouter au passage.

---

### Story 14.4 : Filtres « quota dépassé » et « mot de passe par défaut » sur `/users`

> **Note garde-fou epic 14 :** cette story livre des éléments d'UI visibles (deux filtres supplémentaires dans la modale de filtres `/users`). Elle est conservée dans l'epic 14 par souhait produit (agrégat de petites stories indépendantes liées à la sortie du module legacy `infos`, story 1bis-19). Couvre la décision D5 du `sprint-change-proposal-2026-04-17` qui défère `quota_visu` / `repquota` et `infomdp` vers Epic 2/5 — ici c'est l'absorption la plus directe : pas de page dédiée, juste deux filtres.

As a **administrateur SER**,
I want **filtrer la liste des utilisateurs `/users` pour n'afficher que les comptes en dépassement de quota OU les comptes encore au mot de passe par défaut**,
So que **je puisse remplacer les pages legacy `quota_visu.php` / `repquota.php` / `infomdp.php` par les filtres déjà présents sur la page liste des utilisateurs, sans créer d'écran séparé**.

**Contexte :**
- La liste `/users` (Livewire SFC `pages/users/index.blade.php`) consomme déjà `quota_snapshot` sur `App\Models\User` (alimenté quotidiennement par `QuotaSnapshotCommand`, story 5.1b). La structure `quota_snapshot.home.is_overfill` est déjà disponible.
- Le legacy `infomdp.php` détecte les comptes au mot de passe par défaut en faisant `system("smbclient -L 127.0.0.1 -U $cn%$dateNaissance")` — une commande shell par utilisateur, mot de passe en argument visible dans `ps`. Stratégie reload : ne pas refaire de probe shell, déduire l'info via `pwd_reset_at IS NULL` côté Eloquent + `pwdLastSet` côté AD.
- `pwd_reset_at` existe déjà sur `users` (cf. `User::$fillable`). `AuthenticationService:275-304` lit déjà `pwdlastset` au login mais ne le persiste pas. Décision : ajouter une colonne `password_changed_at` synchronisée au login et lors de `UserService::syncFromAd`.

**Acceptance Criteria :**

**Given** la migration `add_password_changed_at_to_users_table`
**When** elle est exécutée
**Then** la table `users` a une colonne `password_changed_at TIMESTAMP NULL`
**And** `User::$fillable` inclut `password_changed_at`
**And** le cast `password_changed_at => 'datetime'` est ajouté

**Given** un utilisateur s'authentifie via `AuthenticationService`
**When** son `pwdLastSet` AD est lu (logique existante `AuthenticationService:275-304`)
**Then** `password_changed_at` est mis à jour sur le User Eloquent : timestamp converti depuis `pwdLastSet` AD si > 0, NULL si == 0
**And** la même synchro est répliquée dans `UserService::syncFromAd`

**Given** la modale de filtres de `/users`
**When** un administrateur l'ouvre
**Then** un filtre **« Quota dépassé »** (toggle ou case à cocher) est disponible
**And** un filtre **« Mot de passe par défaut »** (toggle) est disponible
**And** les deux filtres sont combinables avec les filtres existants (rôle, classe, école, etc.)

**Given** le filtre « Quota dépassé » est activé
**When** la requête est exécutée
**Then** la query Eloquent applique `where('quota_snapshot->home->is_overfill', true)` (et `var_sambaedu` si applicable selon la structure du snapshot)
**And** la liste affiche uniquement les comptes concernés
**And** le compteur de résultats reflète le sous-ensemble

**Given** le filtre « Mot de passe par défaut » est activé
**When** la requête est exécutée
**Then** la query Eloquent applique `whereNull('password_changed_at')`
**And** la liste affiche uniquement les comptes n'ayant jamais réinitialisé leur mot de passe

**Given** la story est livrée
**When** un test feature couvre le scénario
**Then** un User avec `quota_snapshot->home->is_overfill = true` est retrouvé via le filtre quota
**And** un User avec `password_changed_at = null` est retrouvé via le filtre mdp
**And** la combinaison des deux filtres retourne uniquement l'intersection

**Hors scope :**
- Action bulk « forcer le changement au prochain login » (story future, dépend de la policy reset password déjà existante)
- Page d'audit séparée (`/admin/passwords-audit`) — explicitement remplacée par les filtres
- Probe via `smbclient` à la legacy (rejeté pour raisons de sécurité)

**Notes :**
- Si `password_changed_at` ne suffit pas (cas migration : compte déjà existant avant la story), prévoir une seed one-shot qui pré-remplit la colonne en lisant `pwdLastSet` pour tous les comptes via `UserService::syncFromAd`.
- Supprime les fichiers `legacy/modules/infos/{quota_visu,repquota,infomdp}.php` du périmètre 1bis-19 — le shim devient inutile pour ces 3 fichiers.

---

### Story 14.5 : Page `/admin/system` avec onglets monitoring système

> **Note garde-fou epic 14 :** cette story livre un nouvel écran SER (`/admin/system`). Elle est conservée dans l'epic 14 par souhait produit (agrégat de stories indépendantes de sortie du module legacy `infos`, story 1bis-19). Couvre la décision D5 du `sprint-change-proposal-2026-04-17` (sous-domaines `df` / `du` / `uname` / `uptime` + `test_ldap` absorbés par la refonte SER).

As a **administrateur SER**,
I want **une page `/admin/system` avec quatre onglets (Système, Stockage, Occupation, Outils) regroupant les infos système, l'espace disque, l'occupation des partages principaux et les outils de diagnostic**,
So que **les pages legacy `infose.php`, `df.php`, `du.php` et `test_ldap.php` du module `infos` soient remplacées par une page SER native, sans shim 1bis-19 pour ces fichiers**.

**Contexte :**
- Module legacy `sambaedu/infos/` : 9 fichiers, 7 exec système (`df`, `du`, `uname`, `uptime`, `top`, `free -h`). Voir `idempotency.md § 8` et le sprint-change-proposal-2026-04-17 D5.
- Le dashboard SER existant (`pages/dashboard/index.blade.php`) affiche déjà des cards d'aperçu (espace disque, machines, queue workers). La page `/admin/system` complète avec une vue détaillée.
- Toutes les infos « système » et « stockage » sont obtenables sans `exec()` via `/proc/*` (loadavg, meminfo, uptime), `php_uname()` et `disk_total_space()` / `disk_free_space()`. Seul l'onglet « Occupation » nécessite `du -sh` (donc job + lock fichier comme legacy `du.php:38-55`).
- `test_ldap.php` est un bind LDAP test trivial : 1 click, 1 réponse OK/KO via LdapRecord.

**Acceptance Criteria :**

**Given** la route SER native `/admin/system` (filesystem-based, Livewire SFC, `resources/views/pages/admin/system/index.blade.php`)
**When** un administrateur (policy `admin.system.view`, équivalent `SE_ADMIN`) y accède
**Then** la page affiche une UI à 4 onglets DaisyUI : **Système**, **Stockage**, **Occupation**, **Outils**
**And** un utilisateur sans la policy reçoit un 403

**Given** l'onglet **Système**
**When** il est affiché
**Then** il montre les informations remontées par un service `App\Services\SystemHealthService` (zéro exec) :
- kernel : `php_uname()`
- uptime : `/proc/uptime`
- load : `/proc/loadavg`
- mémoire : `/proc/meminfo` (total, free, available, swap)
- nombre de comptes / professeurs / élèves / administratifs / corbeille (déjà disponible via `User::query()` + relations Spatie)

**Given** l'onglet **Stockage**
**When** il est affiché
**Then** il montre l'espace total / utilisé / libre / pourcentage pour `/`, `/home`, `/var/sambaedu`
**And** chaque ligne utilise `disk_total_space()` / `disk_free_space()` (pas de `df`)
**And** un seuil visuel (warning > 75%, error > 90%) est appliqué

**Given** l'onglet **Occupation**
**When** un administrateur clique sur « Analyser » pour `/var/sambaedu/Progs`, `/var/sambaedu/Docs` ou `/var/sambaedu/Classes`
**Then** un job `App\Jobs\AnalyzeDirectoryUsageJob` est dispatché
**And** un lock fichier (`/var/lock/admin_system_du_<dir>.lock`) empêche les analyses concurrentes (TTL 15 min, comme legacy)
**And** le résultat (sous-dossiers + tailles via `du -sh`) est polled via Livewire et affiché dans un tableau dès dispo
**And** seuls ces 3 répertoires sont autorisés (pas de saisie libre, contrairement au legacy `du.php`)

**Given** l'onglet **Outils**
**When** un administrateur clique sur « Tester la connexion AD »
**Then** un bind LDAP est tenté via LdapRecord avec les credentials de service
**And** le résultat (OK / KO + détail erreur si KO) est affiché en toast et dans la card de l'onglet
**And** aucun mot de passe n'est jamais affiché ou loggé

**Given** la story est livrée
**When** la suite de tests SER tourne
**Then** un test feature couvre : (1) accès admin OK / non-admin 403, (2) onglet Système renvoie les valeurs `/proc/*` parsées correctement (mock du filesystem), (3) onglet Stockage retourne 3 lignes pour les 3 mountpoints, (4) onglet Occupation dispatche le job correctement et respecte le lock, (5) onglet Outils retourne OK quand le bind AD réussit

**Given** la page `/admin/system` est livrée
**When** le module legacy `legacy/modules/infos/` est consulté
**Then** les fichiers `infose.php`, `df.php`, `du.php`, `test_ldap.php` ne sont plus accessibles via le catchall (ajoutés à la liste de blocage Story 1.2)
**And** le scope du shim 1bis-19 est réduit aux fichiers restants (`fix_se4.php` cf. story 14.6, `quota_fixer.php` cf. Epic 5)

**Hors scope :**
- Card « santé système » sur le dashboard `/app/dashboard` (rejetée par Henri — préférence pour la page à onglets)
- Affichage des quotas utilisateurs (couvert par Story 14.4 via filtres `/users`)
- Saisie libre de chemin pour l'analyse `du` (rejeté pour réduction de surface d'attaque)
- `top -b -n1` et liste des processus (intentionnellement retiré du périmètre — vue système-only, pas process)
- Refonte `fix_se4.php` (Story 14.6)
- Refonte `quota_fixer.php` (Epic 5, story dédiée)

**Notes / dette technique observée :**
- Le legacy `du.php` accepte une saisie libre de chemin via `$_POST['wrep']` filtrée par regex `(\.\.|\$|`|~|\|)` — vecteur path traversal résiduel. La version SER doit être strictement scopée (whitelist 3 chemins).
- Le legacy lance `du -sh` avec `sudo` — vérifier que l'utilisateur Laravel a bien la permission sudoers sur `du` uniquement (pas de wildcard).
- L'onglet Système peut servir de base à un futur monitoring temps réel (Pulse, Telescope) — design extensible souhaitable.

---

### Story 14.6 : Audit + refonte `fix_se4.php`

> **Note garde-fou epic 14 :** cette story est en deux temps. Le **temps 1** (audit) est un livrable purement documentaire, no-op fonctionnel — pleinement compatible avec le garde-fou. Le **temps 2** (exécution) sera scopé à l'issue de l'audit : selon les conclusions, il pourra rester dans l'epic 14 (parité fonctionnelle stricte) ou être déplacé vers Epic 5 (ACLs / partages) si une refonte ouvre du périmètre fonctionnel.

As a **développeur SER**,
I want **auditer le contenu de `sambaedu/infos/fix_se4.php` pour identifier opération par opération ce que le script fait, puis décider de son devenir**,
So que **je puisse soit le retirer du shim 1bis-19, soit le refondre en outils SER ciblés (ACL, structure dossiers), soit le conserver en shim durable comme outil admin résiduel**.

**Contexte :**
- `fix_se4.php` (8.6 KB, 9 fichiers du module `infos`) est l'outil de **maintenance manuelle** du legacy SambaEdu. Sans audit, on ne sait pas exactement ce qu'il répare ni à quelle fréquence il est utilisé sur le terrain.
- C'est le seul fichier de `infos/` qui n'est ni un info système (couvert par 14.5), ni un quota (couvert par 14.4 + Epic 5), ni un audit mdp (couvert par 14.4), ni un test LDAP (couvert par 14.5).
- Le shim 1bis-19 n'est pas encore livré : si l'audit conclut que le script est obsolète ou trivialement remplaçable, on peut éviter de le shimer du tout.

**Acceptance Criteria — Temps 1 (audit, livrable doc) :**

**Given** le fichier `sambaedu/infos/fix_se4.php`
**When** la story est livrée (temps 1)
**Then** un document `_bmad-output/planning-artifacts/audit-fix-se4.md` existe
**And** il liste opération par opération ce que le script fait (lecture du source ligne par ligne, regroupement par fonction métier)
**And** pour chaque opération : périmètre de modification (filesystem ? AD ? DB ?), risque (idempotent ? destructif ?), équivalent SER si existant, fréquence d'utilisation côté terrain (à confirmer avec Henri)

**Given** l'audit est livré
**When** Henri le valide
**Then** une décision de routage est consignée :
- (A) Refonte SER native dans Epic 5 (ACLs / partages) → story créée
- (B) Conservation comme shim durable dans `legacy/modules/infos/fix_se4.php` uniquement (le reste de `infos/` étant supprimé) → bornes documentées
- (C) Suppression pure (script obsolète ou remplacé par une autre commande SER) → suppression sèche

**Acceptance Criteria — Temps 2 (exécution, scope défini par le temps 1) :**

**Given** la décision A / B / C est prise
**When** la story est livrée (temps 2)
**Then** l'action correspondante est exécutée, et la story 1bis-19 est mise à jour pour refléter le périmètre final du shim (potentiellement vide si A ou C, réduit à `fix_se4.php` si B)
**And** une note est ajoutée dans `idempotency.md § 8` pour tracer la décision

**Hors scope :**
- Refonte des autres fichiers du module `infos/` (couverts par 14.4 et 14.5)
- Refonte des outils ACL généraux (Epic 5)

**Notes :**
- Si le temps 2 ouvre du périmètre fonctionnel observable (nouvel écran admin SER, nouveau comportement), la story est requalifiée et déplacée vers Epic 5.
- Pas de prerequis bloquant côté 14.4 / 14.5 ; cette story peut être priorisée à part (dette pure tant que l'audit n'a pas conclu).

---

## Epic 15 : Pipeline de Déploiement WPKG natif

*Le serveur génère et publie les configurations WPKG (`hosts.xml`, `profiles.xml`, options `.ini` par poste) à partir de l'état Eloquent — source de vérité unique. Les rapports d'exécution remontés par les clients Windows sont ingérés via une API native Laravel. Le responsable visualise l'état de déploiement par poste / parc / profil applicatif sur un dashboard dédié. La chaîne complète repose sur un channel logs `wpkg-deploy` isolé pour le debug et un identifiant `deployment_id` pour la traçabilité.*

> **Pourquoi cet épic existe** : l'Epic 9 couvre l'admin (téléchargement depuis dépôts via Story 9.2) et les logs (shim via 9.4/9.5). Le **pipeline de distribution effective** sur les postes — génération XML, sync Eloquent suffisant, UI assignation, ingestion rapports natifs, dashboard état — n'a aucune story. Cet épic le couvre, en réécriture native, avec une stratégie « port legacy + adaptation Eloquent » pour les composants concrets et figés (XML, .ini), et réécriture pure pour les UI / API / dashboard.

> **Garde-fous transversaux :**
> - **Eloquent first** : aucune lecture AD en hot path. Toute donnée nécessaire au déploiement vit dans Eloquent ; la sync AD → Eloquent est un job périodique, pas une dépendance synchrone (cf. Story 15.3).
> - **Atomic write systématique** : tout fichier consommé par un client Windows (xml, .ini) est écrit en `temp + rename` (cf. mémoire `feedback_atomic_write`).
> - **Channel logs dédié** : `wpkg-deploy` (Laravel logging channel, fichier séparé) avec `deployment_id` corrélé, niveau de verbosité configurable.
> - **Stratégie port legacy** : chaque fichier porté du legacy `sambaedu/wpkg/*.php` porte un header `@legacy-port` + référence au fichier source + `@todo` de refactoring.

**FRs couverts :** FR24 (déploiement WPKG sur les postes — partie pipeline serveur), FR25 (logs WPKG / rapports — partie ingestion native, complète Story 9.4)
**Prérequis :** Epic 4 (Workstation, WorkstationGroup, AppProfile) ✅, Story 9.2 (gestion packages WPKG admin) ✅
**Frontière avec Epic 9 :** cet épic complète ce qui manque côté pipeline, pas l'admin. La Story 15.7 (cleanup) arbitrera la fusion / le retrait des stories 9.4 / 9.5 si elles deviennent redondantes.

---

### Story 15.1 : Fondations Pipeline Déploiement WPKG

> **Story foundation** — pré-requis bloquant pour toutes les autres stories de l'épic. À livrer en premier.

As a **développeur SER**,
I want disposer d'une infrastructure isolée pour le pipeline de déploiement WPKG (logs, namespace, tables de tracking, paramètres, helpers atomic write),
So que les stories suivantes puissent s'appuyer sur des fondations solides et que le debug de cette chaîne critique ne pollue ni soit pollué par le reste de l'application.

**Acceptance Criteria :**

*Volet 1 — Channel logs dédié*

**Given** la configuration logging Laravel
**When** un service du namespace `App\Wpkg\Deployment` émet un log
**Then** ce log est routé vers le channel `wpkg-deploy` (fichier `storage/logs/wpkg-deploy/deploy-{date}.log`, rotation quotidienne, rétention 30 jours par défaut, paramétrable)
**And** le contexte inclut systématiquement `deployment_id` quand applicable, et `workstation_id` / `app_profile_id` quand disponibles
**And** un niveau de verbosité (`debug` / `info` / `warning` / `error`) est paramétrable via `config/logging.php` sans redeploy

*Volet 2 — Namespace et structure code*

**Given** le code du pipeline déploiement
**When** un développeur crée un nouveau service, generator, ou job
**Then** il est placé sous `app/Wpkg/Deployment/` (sous-dossiers : `Services`, `Generators`, `Jobs`, `Models`, `Events`, `Support`)
**And** chaque fichier porté du legacy porte un header de docblock `@legacy-port path="sambaedu/wpkg/<file>.php"` + un `@todo` de refactoring + un lien vers une note de dette
**And** un test PHPUnit custom (sous `tests/Architecture/WpkgDeploymentNamespaceTest.php`, scan des `use` statements via `Symfony\Finder` + reflection) vérifie que ce namespace n'importe pas `LdapRecord\*` ni `App\Services\Ad\*` (sauf exception explicite : `WpkgAdReconciliationJob` de Story 15.3)
**And** ce test est conçu pour être migré vers ArchTest / PHPStan rule lorsqu'un de ces outils sera installé dans le projet (ticket tooling séparé hors scope 15.1)

*Volet 3 — Tables de tracking*

**Given** une migration Laravel
**When** elle est appliquée
**Then** la table `wpkg_deployments` existe avec : `id` (**UUID, primary key** — `$table->uuid('id')->primary()` ; consommé comme `deployment_id` dans tout le pipeline et exposé dans les logs `wpkg-deploy`, cf. Story 15.5), `triggered_by` (user_id nullable), `triggered_at`, `target_scope` (json : `workstation_ids`, `group_ids`, `profile_ids`), `status` (enum : `pending`, `running`, `completed`, `failed`, `partial`), `summary` (json : counts par statut), `created_at`, `updated_at`
**And** la table `wpkg_deployment_workstation_status` existe avec : `id` (UUID), `deployment_id` (FK UUID vers `wpkg_deployments.id`), `workstation_id` (FK), `app_profile_id` (FK nullable), `client_reported_at` (nullable), `client_status` (enum : `pending`, `success`, `partial`, `failed`, `skipped`), `details` (json), `error_message` (text nullable), `created_at`, `updated_at`
**And** les indexes sont posés sur `(deployment_id, workstation_id)` et `(workstation_id, client_reported_at)`

*Volet 4 — Paramétrage chemins partage (rename des clés legacy)*

> **Décision (2026-05-03)** : stratégie **rename**, pas alias. Les anciennes clés `sambaedu.wpkg.reports_path` / `sambaedu.wpkg.reports_archive_path` sont supprimées et tous les consommateurs migrent vers les nouvelles dans le scope de cette story. Pas de fallback, pas de dette résiduelle.

**Given** le fichier `config/sambaedu.php`
**When** un service consomme un chemin
**Then** les chemins sont lus depuis `config('sambaedu.wpkg.deploy_path')` (XML hosts/profiles), `config('sambaedu.wpkg.ini_path')` (fichiers .ini par poste), `config('sambaedu.wpkg.reports_inbox')` (rapports clients), `config('sambaedu.wpkg.reports_archive')` (archivage rapports bruts)
**And** les valeurs par défaut pointent vers `/var/sambaedu/unattended/install/wpkg/{hosts.xml, profiles.xml, ini/, rapports/, archive/}` (parité legacy)
**And** un check de démarrage applicatif vérifie que ces chemins sont accessibles en lecture/écriture par le user PHP-FPM, sinon log warning explicite

*Volet 4bis — Migration des consommateurs des anciennes clés*

**Given** les consommateurs actuels des clés legacy `sambaedu.wpkg.reports_path` et `sambaedu.wpkg.reports_archive_path`
**When** la story est livrée
**Then** les fichiers suivants sont migrés vers `sambaedu.wpkg.reports_inbox` / `sambaedu.wpkg.reports_archive` :
- `app/Services/Windows/WorkstationLogReader.php` (ligne ~48)
- `app/Console/Commands/WpkgProcessReportsCommand.php` (lignes ~39, ~42, ~51 + tout commentaire de docblock)
- Toute autre occurrence détectée par `grep -r "reports_path\|reports_archive_path" app/ config/ tests/`
**And** les anciennes clés sont **supprimées** du `config/sambaedu.php` et des fichiers `.env` / `.env.example` (les variables d'environnement `WPKG_REPORTS_PATH` et `WPKG_REPORTS_ARCHIVE_PATH` sont renommées en `WPKG_REPORTS_INBOX` et `WPKG_REPORTS_ARCHIVE`)
**And** les tests existants (notamment ceux couvrant `WpkgProcessReportsCommand` et `WorkstationLogReader`) continuent de passer après le rename
**And** une note de migration `.env` est ajoutée dans la note technique `docs/wpkg-deploy/architecture.md` pour les ops (rappel : renommer la variable avant déploiement)

*Volet 5 — Helpers atomic write (consolidation)*

> **Décision (2026-05-03)** : un `AtomicFileWriter` existe déjà dans `App\Services\AppCustomization\Support\` mais sans suffixe PID dans le tmp. Cette story **consolide** les deux usages dans une classe unique `App\Support\AtomicFileWriter` plutôt que d'introduire une seconde implémentation.

**Given** un service qui doit écrire un fichier consommé par un client externe
**When** il appelle `App\Support\AtomicFileWriter::write($path, $content)`
**Then** le fichier est écrit en `<path>.tmp.<pid>.<random>`, fsync forcé, puis renommé sur `<path>` (rename atomique sur même filesystem)
**And** un test feature démontre qu'aucun lecteur concurrent ne peut observer un état partiel (test concurrent : producer écrit en boucle, reader lit en boucle, on vérifie qu'aucune lecture ne capture un fichier vide ou tronqué)
**And** la classe préexistante `App\Services\AppCustomization\Support\AtomicFileWriter` est supprimée ; tous ses appelants importent désormais `App\Support\AtomicFileWriter`
**And** les tests préexistants couvrant l'usage `AppCustomization` continuent de passer (la garantie atomique reste équivalente, le suffixe PID est un renforcement contre les collisions multi-process — comportement compatible)
**And** le namespace `App\Wpkg\Deployment\Support\` n'expose **pas** de classe `AtomicFileWriter` propre (consommation directe de `App\Support\AtomicFileWriter`)

*Volet 6 — Tests*

**Given** la couche fondation
**When** la suite de tests s'exécute
**Then** tests unitaires sur `App\Support\AtomicFileWriter` (incluant non-régression vs ancien usage `AppCustomization`), tests feature sur la migration des deux tables (création + rollback), tests de configuration sur le channel logs, test PHPUnit custom d'architecture sur le namespace `App\Wpkg\Deployment`, test de non-régression sur `WpkgProcessReportsCommand` et `WorkstationLogReader` après le rename des clés config

**Hors scope :** aucune logique métier de déploiement — pure infrastructure.

**Notes :**
- Cette story produit également une **note technique** dans `docs/wpkg-deploy/architecture.md` qui résume l'architecture du namespace (à compléter au fil des stories suivantes), incluant la note de migration `.env` pour les ops (cf. Volet 4bis).
- Conflit namespace `App\Wpkg\*` à surveiller : la note couvre **non seulement** les services qui pourraient être créés sous `App\Wpkg\Deployment\`, mais aussi les classes existantes hors namespace qui consomment des concepts WPKG : `App\Services\Windows\WpkgReportIngestionService`, `App\Services\Windows\WorkstationLogReader`, `App\Console\Commands\WpkgProcessReportsCommand`. L'audit de Story 15.3 volet 1 tracera leur destin (déplacement, fusion, ou statu quo).
- **Mise à jour `epics.md`** : à la clôture de la story, mettre à jour le tableau de couverture FR en début de fichier pour ajouter Epics 15/16/17 (action de doc, non bloquante pour la livraison code).

---

### Story 15.2 : Generators XML + .ini par poste

As a **administrateur SER**,
I want que les fichiers `hosts.xml`, `profiles.xml` et `<workstation_id>.ini` consommés par le client WPKG soient générés automatiquement à partir de l'état Eloquent,
So que tout changement (assignation app à un parc, modification d'options poste) soit propagé sans intervention manuelle, et que les clients Windows lisent toujours un état cohérent.

> **Stratégie : port legacy + adaptation sources données.** Les XML legacy ont un format figé que les clients Windows connaissent. Le port reproduit la structure à l'identique mais lit Eloquent à la place du SGBD legacy.

**Acceptance Criteria :**

*Volet 1 — `HostsXmlGenerator`*

**Given** des entrées `Workstation` en DB avec un `AppProfile` rattaché (via `WorkstationGroup` ou directement)
**When** `HostsXmlGenerator::generate()` est invoqué
**Then** le fichier `<deploy_path>/hosts.xml` est régénéré en atomic write avec une entrée `<host name="..." profile-id="..."/>` par poste actif (postes archivés exclus)
**And** la mapping `profile-id` reflète la priorité Eloquent : si un `AppProfile` est rattaché directement à un `Workstation`, il prime ; sinon `profile-id = workstation_group.appProfile.code`
**And** l'XML inclut un commentaire `<!-- Généré par SER le {timestamp ISO 8601} - deployment_id={uuid} -->`
**And** le fichier valide `XML 1.0 UTF-8` (test : `XMLReader` ouvre le fichier sans erreur)

*Volet 2 — `ProfilesXmlGenerator`*

**Given** des `AppProfile` rattachés à des `Application`
**When** `ProfilesXmlGenerator::generate()` est invoqué
**Then** le fichier `<deploy_path>/profiles.xml` contient un `<profile id="...">` par AppProfile + un `<package package-id="..."/>` par application liée
**And** les profils orphelins (sans application) sont émis avec une balise `<profile>` vide (cohérence parsing client)
**And** l'ordre des profils et des packages est déterministe (tri alphabétique par id) pour faciliter le diff git en cas d'export historique

*Volet 3 — `WorkstationIniGenerator`*

**Given** un `Workstation` avec ses options WPKG (table `wpkg_workstation_options` à créer dans cette story : `workstation_id` FK, `option_key`, `option_value`, `created_at`, `updated_at` ; unique `(workstation_id, option_key)`)
**When** `WorkstationIniGenerator::generate(Workstation $w)` est invoqué
**Then** le fichier `<ini_path>/<workstation_id>.ini` est généré au format clé=valeur, en respectant les commentaires inline présents dans le legacy (parité — exemple : `debug=false ' Permet d'avoir des logs plus détaillés.`)
**And** les valeurs par défaut sont cohérentes avec le legacy (`debug=false`, `logdebug=false`, `force=false`, `forceinstall=false`, `dryrun=false`)
**And** les options non surchargées en DB ne sont pas émises (le client WPKG applique son propre défaut)

*Volet 4 — Régénération ciblée sur events*

**Given** des événements Laravel `AppProfileWorkstationGroupChanged`, `AppProfileApplicationChanged`, `WorkstationProfileChanged`, `WorkstationOptionsChanged`, `WorkstationActivated`, `WorkstationArchived`
**When** un event est dispatché (par les services responsables des modifications dans Story 15.4)
**Then** le listener correspondant déclenche un job queued `RegenerateHostsXmlJob`, `RegenerateProfilesXmlJob`, ou `RegenerateWorkstationIniJob`
**And** le job est idempotent (deux exécutions consécutives produisent un fichier identique au byte près) et débouncé (max 1 régénération en vol par fichier, autres requêtes coalescées via lock Redis ou DB advisory lock)
**And** la file est dimensionnée pour absorber des bursts (ex : changement bulk de 200 postes en une opération)

*Volet 5 — Régénération manuelle (admin)*

**Given** un admin sur la page diagnostic WPKG
**When** il clique "Régénérer tous les XML / .ini"
**Then** une commande Artisan `php artisan wpkg:regenerate-all` est invocable
**And** elle régénère hosts.xml, profiles.xml, et tous les .ini sans casser les lecteurs concurrents (atomic write garanti par Story 15.1)

*Volet 6 — Tests*

**Given** une fixture (parcs + apps + profils) reproductible et committée
**When** chaque generator est invoqué
**Then** un test snapshot compare le XML/ini généré à un fichier attendu (committé dans `tests/Fixtures/wpkg/expected/`)
**And** un test démontre que la génération est idempotente (2 appels successifs produisent un fichier identique)
**And** un test démontre que le harnais Story 1bis.11 / 9.2 (parité legacy) continue de passer pour les composants déjà en place (`packages.xml`)

**Hors scope :**
- Sync AD → Eloquent (Story 15.3)
- UI d'édition des options `.ini` (Story 15.4)
- Pipeline de rapports (Story 15.5)

**Notes :**
- Les generators sont stateless ; ils prennent en input l'état Eloquent et produisent un fichier. Aucune logique métier en dehors du formatage.
- Pour le format `.ini`, conserver les commentaires inline du legacy si possible (parité). À défaut, les omettre — pas un blocker.

---

### Story 15.3 : Modèle Eloquent suffisant pour le déploiement WPKG

> **Reformulation suite échange avec Henri (2026-05-01)** : la sync `wpkg_ldap_update.php` legacy interroge l'AD pour structurer les parcs/postes. Côté SER, `Workstation` et `WorkstationGroup` Eloquent existent déjà. L'objectif de cette story est de **garantir que la chaîne de déploiement n'a plus besoin d'aller à l'AD en hot path** — toute donnée vit en Eloquent, et la sync AD → Eloquent reste un mécanisme de réconciliation périodique, pas une dépendance synchrone.

As a **développeur SER**,
I want que le modèle Eloquent contienne tous les attributs nécessaires au pipeline de déploiement WPKG, et que la sync AD → Eloquent soit limitée aux attributs sans équivalent DB (job périodique),
So que les Generators (Story 15.2) et l'UI (Story 15.4) consomment uniquement Eloquent, et que le pipeline reste rapide, testable, et résilient à une indisponibilité AD ponctuelle.

**Acceptance Criteria :**

*Volet 1 — Audit du schéma actuel (livrable doc)*

**Given** les entités impliquées : `Workstation`, `WorkstationGroup`, `Application`, `AppProfile`, `WpkgWorkstationOptions` (créée en Story 15.2)
**When** la story commence
**Then** un document `_bmad-output/planning-artifacts/audit-wpkg-eloquent-schema.md` est produit, listant pour chaque entité :
- Les colonnes existantes (avec leur usage côté pipeline déploiement)
- Les colonnes nécessaires au pipeline mais manquantes (à ajouter via migrations dans le volet 2)
- Les attributs uniquement disponibles côté AD et leur stratégie : (a) sync périodique vers Eloquent — colonne à créer, (b) lecture AD ponctuelle hors hot path autorisée — justification documentée
**And** ce document est validé par Henri avant toute migration
**And** l'audit identifie également l'état de `SyncWorkstationGroupJob` existant (couvre-t-il déjà le besoin ? doit-il être étendu ou remplacé par `WpkgAdReconciliationJob` ?)

*Volet 2 — Migrations Eloquent (livrable code)*

**Given** la liste des colonnes manquantes identifiées par l'audit
**When** les migrations sont appliquées
**Then** les colonnes nécessaires sont ajoutées (typiquement attendues : `objectGUID` sur `Workstation` et `WorkstationGroup` si absent, `last_seen_at`, `archived_at`, `ad_dn` ; à préciser au volet 1)
**And** le seeding/backfill initial pose les valeurs depuis l'AD pour les entrées existantes (script Artisan one-shot `php artisan wpkg:backfill-ad-attrs`)
**And** une rollback migration est testée

*Volet 3 — Job de réconciliation AD → Eloquent*

**Given** un job Laravel `WpkgAdReconciliationJob` (ou extension de `SyncWorkstationGroupJob` selon décision audit)
**When** il s'exécute (cron périodique, défaut 15 min, paramétrable via `config/sambaedu.php`)
**Then** il interroge l'AD une seule fois pour récupérer la liste des `Workstation` et `WorkstationGroup` (par objectGUID), compare à l'état Eloquent, et applique les écarts :
- Création Eloquent si machine/groupe AD non présent en DB
- Mise à jour si renommage (objectGUID identique, name différent)
- Marquage `archived_at` si présent en DB mais absent AD (pas de suppression sèche par défaut — la suppression est manuelle ou via une rétention paramétrable)
**And** chaque opération est journalisée dans le channel `wpkg-deploy` avec `objectGUID`
**And** le job est idempotent (2 exécutions consécutives sans changement AD = no-op silencieux)
**And** un lock Redis ou advisory lock empêche l'exécution concurrente du job

*Volet 4 — Aucune lecture AD en hot path*

**Given** les Generators (Story 15.2) et l'UI (Story 15.4)
**When** un test feature mock l'AD en throwing à chaque appel
**Then** la chaîne de déploiement complète (génération XML + .ini, affichage UI assignation) fonctionne sans erreur (Eloquent suffit)
**And** un test architecture vérifie qu'aucun service du namespace `App\Wpkg\Deployment` n'importe `LdapRecord\*` ni `App\Services\Ad\*` (exception explicite : le job de réconciliation lui-même)

*Volet 5 — Tests*

**Given** une simulation AD (LDAP test container ou mock structuré)
**When** le job de réconciliation s'exécute sur des cas représentatifs (création, renommage, suppression, no-op, conflit objectGUID, AD indisponible)
**Then** chaque scénario produit le résultat attendu en DB et émet les logs correspondants
**And** un test démontre qu'une indisponibilité AD ponctuelle ne casse pas la chaîne déploiement (le job log warning et retry, mais le pipeline continue à servir l'état Eloquent existant)

**Hors scope :**
- Sync AD pour des entités hors WPKG (utilisateurs, GPO) — autre story dans Epic 11 / Epic 16
- Refonte profonde de `LdapRecord` ou de l'abstraction AD existante

**Notes :**
- Cette story complète l'`AdSyncService` mentionné dans Story 9.2 (architecture wpkg) — elle ne le remplace pas si déjà existant, mais garantit qu'il couvre le besoin déploiement.
- Mémoire `gpo_real_ad_not_eloquent.md` rappelle qu'il y a des cas où l'AD reste source unique (GPO) — cette story trace explicitement la frontière pour le périmètre WPKG : **rien d'AD en hot path**.

---

### Story 15.4 : UI admin assignation apps WPKG

As a **responsable de collège / administrateur SER**,
I want une interface unique pour assigner/désassigner des applications aux parcs et postes, cloner la configuration d'un parc vers un autre, et gérer les options `.ini` par poste,
So que je puisse piloter le déploiement WPKG sans manipuler les XML / .ini directement, depuis une UI cohérente avec le reste de SER.

> **Confirmation exploration (2026-05-01)** : le legacy SambaEdu utilise un workflow à **un seul niveau** (pas de séparation autorisation / activation). Une UI unique suffit, alignée sur le modèle Eloquent existant `AppProfile` ↔ `WorkstationGroup` / `Workstation`.

**Acceptance Criteria :**

*Volet 1 — Vue parc / WorkstationGroup*

**Given** je suis sur la page d'un `WorkstationGroup` (parc) — route `/app/parc/{group}/wpkg`
**When** je consulte la section "Applications WPKG"
**Then** je vois la liste des `AppProfile` rattachés à ce groupe + la liste des applications de chaque profil
**And** je peux ajouter/retirer un `AppProfile` à ce groupe (Livewire SFC)
**And** les changements déclenchent l'event `AppProfileWorkstationGroupChanged` (consommé par les Generators de Story 15.2)
**And** un toast `WithToasts` confirme chaque action

*Volet 2 — Vue poste / Workstation*

**Given** je suis sur la page d'un `Workstation` — route `/app/workstations/{workstation}/wpkg`
**When** je consulte la section "Applications WPKG"
**Then** je vois les `AppProfile` hérités du `WorkstationGroup` parent + ceux rattachés directement au poste
**And** je peux ajouter/retirer un `AppProfile` directement sur le poste (override)
**And** les héritages et overrides sont visuellement distingués (badge "hérité" vs "direct")
**And** les changements déclenchent l'event `WorkstationProfileChanged`

*Volet 3 — Assignation par catégorie d'apps (bulk)*

**Given** une catégorie d'apps existe (ex : "Logiciels Bureautique")
**When** je clique "Assigner toute la catégorie au parc X"
**Then** un dialogue de confirmation (modale réutilisable) liste les N apps qui seront ajoutées
**And** après confirmation, les N apps sont ajoutées dans un `AppProfile` cible (existant ou nouvellement créé selon le choix utilisateur)
**And** un seul event de regen est émis (pas N events) — coalescing au niveau du listener

*Volet 4 — Clone de configuration parc → parc*

**Given** un parc source A avec sa configuration WPKG, et un parc destination B
**When** je déclenche "Cloner la configuration de A vers B"
**Then** un diff prévisualisable est affiché (apps qui seront ajoutées/retirées de B)
**And** après confirmation, la configuration de B reflète exactement celle de A
**And** un event de regen est émis pour B
**And** une trace audit `wpkg_deployments` (Story 15.1) est créée avec `target_scope` pointant sur B

*Volet 5 — UI options `.ini` par poste*

**Given** un poste sélectionné
**When** j'accède à l'onglet "Options WPKG poste"
**Then** je vois les options actuelles (`debug`, `logdebug`, `force`, `forceinstall`, `dryrun`, etc.) avec leur valeur par défaut héritée
**And** je peux les surcharger pour ce poste
**And** la sauvegarde déclenche l'event `WorkstationOptionsChanged` (regen `<workstation_id>.ini`)

*Volet 6 — Tests*

**Given** la suite de tests Livewire
**When** elle s'exécute
**Then** chaque flux (assigner profile, cloner, modifier options, bulk catégorie) est testé feature
**And** le harnais existant Story 9.2 / 1bis.11 (parité legacy) continue de passer
**And** les permissions Spatie sont vérifiées : un user sans `wpkg.manage` reçoit 403 sur les actions de modification

**Hors scope :**
- Édition du contenu d'un `AppProfile` lui-même (apps qu'il contient) → couvert par Story 9.2 admin
- Téléchargement de nouvelles apps depuis dépôts → Story 9.2 ✅
- Notion de droit délégué par parc (un prof = telle salle uniquement) → Epic 7 si besoin futur

**Notes :**
- Réutiliser le composant modale réutilisable + `WithToasts` (cf. CLAUDE.md).
- Filesystem-based router : pages sous `resources/views/pages/parc/[group]/wpkg/index.blade.php` + `resources/views/pages/workstations/[workstation]/wpkg/index.blade.php`.
- Permissions Spatie suggérées : `wpkg.view`, `wpkg.manage`. Définition exacte à faire en synchro avec Epic 7.

---

### Story 15.5 : Pipeline rapports clients + Dashboard état déploiement

As a **responsable de collège**,
I want que les rapports d'exécution remontés par les clients WPKG (au login / startup) soient ingérés automatiquement, et que je puisse visualiser l'état de déploiement par poste / parc / profil,
So que je sache en quelques secondes si le déploiement WPKG fonctionne sur l'ensemble des postes et identifier les machines en échec.

**Acceptance Criteria :**

*Volet 1 — Endpoint API d'ingestion*

**Given** un client WPKG sur une machine joinée au domaine
**When** il poste un rapport sur `POST /api/v1/wpkg/reports` (body = format texte legacy ou JSON, content-type négocié)
**Then** la requête est authentifiée par identité machine (mécanisme à figer dans cette story : ticket Kerberos via `gss-spnego`, ou cert client TLS, ou secret partagé par machine — décision à prendre en début de story avec note `_bmad-output/planning-artifacts/audit-wpkg-report-auth.md`)
**And** le payload est parsé (parser = port `wpkg_rapport.php`) et persisté dans `wpkg_deployment_workstation_status` (Story 15.1) et `WorkstationApplicationStatus` (existant Story 9.4) pour la vue par app
**And** le rapport brut est conservé sur disque (config `sambaedu.wpkg.reports_archive`) pour audit forensic, avec rotation paramétrable (défaut 90 jours)
**And** un log est émis dans `wpkg-deploy` avec `deployment_id` et `workstation_id`

*Volet 2 — Parser de rapports*

**Given** le format texte legacy WPKG (lignes type `[date] [level] [message]` + structure `<package>...</package>`)
**When** le parser s'exécute
**Then** chaque ligne est convertie en entrée structurée : `package_id`, `status` (success/failed/skipped/installed/uninstalled), `duration_ms`, `error_code`, `error_message`
**And** les cas du harnais 1bis.11 / Story 9.4 sont reproduits sans régression
**And** les nouveaux formats détectés (extension ou évolution client) génèrent un warning dans le channel `wpkg-deploy` sans bloquer l'ingestion (rapport stocké brut + entry partielle)

*Volet 3 — Dashboard état déploiement*

**Given** je suis sur la page `/app/wpkg/deployments`
**When** elle se charge
**Then** je vois :
- KPIs globaux : N postes au total, X% sain, Y% partiel, Z% en échec, dernière sync (timestamp du rapport le plus récent)
- Vue agrégée par parc : graphique status par WorkstationGroup
- Vue par profile : nombre de postes / apps OK/WARN/ERROR
- Tableau des incidents récents (dernières 24h, triable par sévérité, filtrable par parc/profile/app)
**And** un drill-down navigue vers la vue détaillée du poste concerné (volet 4)
**And** la page se charge en moins de 2 secondes sur un parc de 500 postes (NFR1)

*Volet 4 — Vue détaillée par poste*

**Given** je clique sur un poste depuis le dashboard
**When** la page poste s'affiche — route `/app/workstations/{workstation}/wpkg/reports`
**Then** je vois la timeline des derniers rapports WPKG (10 derniers par défaut, paginable)
**And** par rapport, le détail des packages traités, leur statut, durée, erreurs
**And** un bouton "Forcer une re-évaluation" qui régénère hosts/profiles/ini pour ce poste (event `WorkstationProfileChanged` émis manuellement)

*Volet 5 — Tests*

**Given** des fixtures de rapports legacy (committées dans `tests/Fixtures/wpkg/reports/`) + des cas synthétiques (rapport partiel, rapport mal formé, rapport très volumineux)
**When** la suite de tests s'exécute
**Then** chaque format de rapport est ingéré sans erreur, le dashboard affiche les bonnes valeurs, et les calculs d'agrégation sont testés unitairement
**And** un test charge stress (1000 rapports postés en parallèle) vérifie que l'ingestion ne perd aucun rapport

**Hors scope :**
- Notifications proactives (email / push si déploiement en échec) → backlog, à inscrire dans une story future Epic 15.x ou Epic 11
- Export CSV / PDF des rapports → backlog

**Notes :**
- Stories 9.4 / 9.5 (logs WPKG shimés) restent vivantes en parallèle pendant la transition. Story 15.7 arbitrera leur retrait si redondance complète.
- Décision auth machine : à figer en début de story. Default proposé si pas tranché : **secret partagé par machine** (table `workstation_api_secrets`, rotation possible), avec bascule future vers Kerberos une fois SPNEGO testé. Cette décision est non-bloquante pour le reste de l'épic.

---

### Story 15.7 : Bascule production + retrait shim WPKG legacy ⏳ PLANNED

> **Story de cleanup**, à programmer après stabilisation 2 semaines en prod des stories 15.1-15.5. Pas d'AC détaillées maintenant — elles seront figées au moment où la story sera activée.

**Brouillon de scope :**
- Flip switch : routes legacy `wpkg/*` (paths : `parc_maintenance_*.php`, `poste_maintenance_*.php`, `wpkg_log.php`, `wpkg_rapport.php`, `wpkg_ldap_update.php`, `transfert/*`) → 410 Gone ou redirection Laravel (cohérence avec mécanisme Story 7.4 volet 1)
- Retrait du shim WPKG legacy (1bis.11) de la matrice de routing
- Archivage du code legacy `sambaedu/wpkg/` dans `legacy/archived/wpkg-{date}/` pour audit historique
- Mise à jour de `idempotency.md` avec post-mortem de la bascule
- Arbitrage : retrait des stories 9.4 / 9.5 si pleinement couvertes par Story 15.5 (à confirmer après stabilisation)
- Communication équipe terrain : note de version + redirection des anciens favoris navigateur
- **Fixtures legacy byte-à-byte (dette 15.2)** : `tests/Fixtures/wpkg/expected/{hosts,profiles}-PCEXEMPLE.xml` ont été générées en 15.2 par dump local du contrôleur Reload (LDAP HS au moment du dev), pas par `curl` legacy. Au moment de 15.7, regénérer ces fixtures via `curl http://localhost/wpkg/{hosts,profiles}_xml_out.php?poste=PCEXEMPLE` côté VM legacy AVANT retrait du shim, et ajouter dans `tests/Feature/Wpkg/Deployment/Http/{Hosts,Profiles}XmlControllerTest.php` un test `assertXmlStringEqualsXmlString(file_get_contents($fixturePath), $response->getContent())` pour figer la parité byte-équivalente. Sinon les fixtures committées restent décoratives et la bascule legacy → reload n'est pas garantie byte-à-byte.

**Critère de déclenchement :** zéro log d'erreur sur le pipeline natif sur 14 jours roulants + zéro régression remontée par l'équipe support + ingestion rapports natifs >= 95% des postes actifs.

---

## Epic 16 : Gestion native des GPOs

**Statut :** 🟡 **Phase 1 livrée** (stories 16.1-16.7 développées + reviewées, **0 testée** au 2026-05-15) — **Phase 2 cadrée** dans `tech-spec-epic-16-17-phase2.md` (2026-05-15). 7 nouvelles stories 16.8-16.14 à développer.

*Réécriture native Laravel du module `sambaedu/gpo/` (legacy actuellement shimé via Story 1bis.18). Le responsable consulte, crée, édite, lie, et duplique des GPOs depuis l'interface SER, avec des sections spécialisées (Firefox, Thunderbird, Wallpaper, Veyon, Wine, Roaming, Raccourcis). L'invocation de `samba-tool gpo` est encapsulée dans un service dédié, avec un channel logs `gpo-deploy` séparé.*

> **Note transversale :** cet épic remplace la Story 9.1 PAUSED. Le shim 1bis.18 reste valide en transition (le code legacy continue de fonctionner via shim) — son retrait définitif est planifié en **Story 16.13bis** (Phase 2, créée 2026-05-19 via Sprint Change Proposal). Modèle "cleanup post-stabilisation cluster unique" (iso Story 15.7) abandonné car incompatible avec le déploiement par collège — voir `sprint-change-proposal-2026-05-19.md`.

> **Frontière avec Epic 17 :** Epic 16 = configuration de policies GPO (registre Windows). Epic 17 = scripts exécutables (.bat, .vbs, .ps1, .cmd) déclenchés par GPO ou direct NETLOGON. Une story d'Epic 16 (16.6) consomme Epic 17 quand elle invoque un script. **Fusion logique 16+17 en Phase 2** : les deux epics partagent l'auth JWT (16.10), l'auto-bootstrap migration (16.11) et les logs centralisés (16.12).

> **Phase 2 — décisions clés (2026-05-15) :** auth HTTPS+JWT (D3), auto-bootstrap migration postes (D4), centralisation logs DB+UI (D5), conservation UI Livewire sous `/admin/settings` (D2), CA root indépendant par établissement (D9). **Hors-scope explicite Phase 2** : pas d'image immuable, pas d'agent Go binaire (mais design "agent-ready" anticipé pour Phase 3 et controlHub). Réf complète : `tech-spec-epic-16-17-phase2.md`.

**FRs couverts :** FR23
**Prérequis :** Epic 4 (Workstation, WorkstationGroup) ✅, Story 1bis.18 (shim gpo) ✅
**Annule/remplace :** Story 9.1 PAUSED → marqué « ❌ ANNULÉE — remplacée par Epic 16 »

---

### Story 16.1 : Fondations GPO natives + audit legacy

*Cadrage haut niveau (à détailler avant implémentation) : channel logs `gpo-deploy`, namespace `App\Gpo`, abstraction `samba-tool gpo` via `GpoService`, tables de tracking si pertinentes. Audit du shim 1bis.18 et du legacy `sambaedu/gpo/*.php` pour identifier les commandes encapsulables et les sections spécialisées (Firefox, Thunderbird, etc.). Production d'un document `audit-gpo-legacy.md` listant fichier par fichier le rôle, les inputs/outputs, et la stratégie de port (réécriture / port + adaptation / abandon).*

### Story 16.2 : Listing & lecture GPO (UI native)

*Cadrage haut niveau : page Livewire native `/app/gpo` qui remplace progressivement le shim 1bis.18 — listing avec filtres (nom, OU rattachée, statut), lecture détail (sections actives, valeurs des policies), affichage des liens (OU / WorkstationGroup), badges status. Pas encore d'édition.*

### Story 16.3 : Édition de sections GPO — **splittée en 16.3a/b/c (2026-05-11, post-audit 16.1)**

> **Décision Henri 2026-05-11** : suite à l'audit `audit-gpo-legacy.md` section 6.G qui a identifié 9 sections réelles (vs 7 dans le cadrage initial) et estimé la story monolithique à ~9j dev (hors taille raisonnable), la story 16.3 est **splittée en trois sous-stories**. Sections Firefox/Thunderbird/Wallpaper retirées du scope édition GPO car déjà refondues en natif (Stories 4.7, 4.8) — elles sont exposées en navigation depuis `/app/gpo` (16.3a).
>
> **Discrepances tranchées en parallèle :** `associations_out.php` reste dans Epic 16 (placée dans 16.3c) ; templates GPO Git (`sambaedu-gpo-templates`) conservent le pattern apt existant (impact sur 16.4 import/export, pas sur 16.3) ; DNS/réplication AD sortent dans Epic 18.

#### Story 16.3a : Liens profonds vers sections déjà-natives (basse complexité, ~1j)

*Liens profonds depuis `/app/gpo` vers les UIs natives existantes : Firefox/Thunderbird via `AppCustomization`, Wallpapers via Story 4.7, Shortcuts via `ShortcutsService`, Roaming via `RoamingProfileService`. Navigation + breadcrumbs, pas de nouveau code métier. Charge estimée : 1 jour.*

#### Story 16.3b : Section Network proxy + Veyon (complexité moyenne, 2-3j)

*Réécriture native de `/gpo/network_out.php` (proxy) et `/gpo/veyon_out.php` en Controllers Laravel + services dédiés consommant les stubs écriture de `GpoService` (Story 16.1). Catchall override des URLs legacy correspondantes. Tests d'intégration : un poste Veyon réel consomme bien la config générée. Charge estimée : 2-3 jours.*

#### Story 16.3c : Wine + Associations apps + Applications scripts (haute complexité, 3-4j)

*Wine (`/gpo/wine.php`) : génération d'image via Job queue (pas en sync depuis le web). Associations apps (`/gpo/associations_out.php`) : couplage fort avec WPKG (Epic 15) — décision Henri 2026-05-11 : reste dans Epic 16, à câbler proprement pour éviter une dépendance circulaire avec le modèle WPKG. Applications scripts (`/gpo/applications.php`, scripts startup/logon) : endpoint stateless. Charge estimée : 3-4 jours.*

### Story 16.4 : Création / duplication / suppression de GPO

*Cadrage haut niveau : CRUD complet — invocation `samba-tool gpo create/del`, duplication par copie de l'arbre policy, validation pré-suppression (warning si la GPO est liée à des OUs / postes actifs), corbeille avec restauration possible (durée paramétrable).*

### Story 16.5 : Liaison GPO ↔ OU / parc + propagation

*Cadrage haut niveau : UI de liaison GPO à un OU AD ou à un WorkstationGroup, avec gestion de l'ordre de précédence et de la propagation. Port de `gestion_gpo.php` / `gpo-maj.php`. Affichage d'un graphe de liaison (GPO → OU / Group → postes affectés) pour comprendre l'impact d'un changement.*

### Story 16.6 : Hook GPO → invocation `wpkg.js` côté client (jonction Epic 15)

*Cadrage haut niveau : génération d'une GPO de logon/startup spéciale qui invoque `wpkg.js` côté Windows et pointe vers les XML générés par Story 15.2. Point d'intégration explicite avec Epic 15 — à coordonner avec Story 15.2 et 15.5 pour garantir que l'URL/chemin pointé par la GPO soit cohérent avec ce que les Generators produisent. Cette story déclenche probablement Story 17.x si l'invocation de `wpkg.js` est elle-même un script Windows packagé.*

---

### **Phase 2 — Stabilisation, sécurisation, migration (cadrée 2026-05-15)**

> **Phase 2** étend l'Epic 16 avec 8 nouvelles stories qui ciblent les sujets non couverts par la Phase 1 : stabilisation des tests existants, sécurisation des comms poste↔serveur (HTTPS + JWT), auto-bootstrap des postes existants vers le nouveau mode, centralisation DB+UI des logs d'exécution, exposition des endpoints natifs sous `/api/v1/*`, module migration SE4 → SE5 simplifié, et améliorations UX de l'UI admin GPO.
>
> Réf complète et détail des décisions architecturales : `_bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md` et `_bmad-output/planning-artifacts/sprint-change-proposal-2026-05-19.md`.
>
> Séquencement et dépendances :
> ```
> 16.8 ───┬─── 16.9 ────┬── 17.2 ─── 17.3 ─── 17.4 ──────┐
>         │             │                                │
>         │             └── 16.14 (UX UI admin)          │
>         │                                              │
>         └─── 16.10 ──┬─── 16.11                        │
>                     │                                  │
>                     └─── 16.12 (incl. UI logs) ────────┤
>                                                        │
>                                                        ├─── 16.13 ─── 16.13bis
>                                                        │   (api/v1)   (migration
>                                                        │              + cleanup)
> ```
>
> Charge totale Phase 2 Epic 16 : ~29-39j de dev (parallélisable). Story 16.14 déprogrammable sans bloquer le reste.

### Story 16.8 : Stabilisation Phase 1 — exécution tests + audit iso-legacy

*Cadrage haut niveau : exécuter les 76 tests Pest écrits en Phase 1 (Stories 16.1-16.7) en CI, corriger les régressions identifiées, et compléter l'audit de parité iso-legacy. **Critique de production** : aucune story Phase 2 ne démarre tant que les tests Phase 1 ne sont pas verts. Inclut aussi un mini-audit (cf. Tech Spec §5.0) des GPO/scripts qui pointeraient encore sur `SE4FS` nu (= central historique) — risque de cassure à la migration HTTPS+JWT. Audit complet des shims résiduels (1bis.18 etc.) qui devront être retirés en 16.13. Charge : 3-5j.*

### Story 16.9 : Exposition UI admin GPO sous `/admin/settings`

*Cadrage haut niveau : déplacer/réexposer les 6 pages Livewire GPO livrées en Phase 1 (index, détail GPO, links, wine, wpkg-deployment, deep links 16.3a) sous le panneau `/admin/settings/gpo/*`. Les anciens chemins `/app/gpo/*` redirigent. Ajout d'une entrée dans le menu admin settings. Pas de refonte UI, juste exposition correcte (cf. décision D2 du Tech Spec). Charge : 1-2j. Prérequis : 16.8.*

### Story 16.10 : Sécurisation comms — HTTPS + JWT (endpoints v1, middleware, modèles tokens)

*Cadrage haut niveau : introduit l'API v1 et l'auth JWT pour le trafic poste↔serveur local. Endpoints v1 (`/api/v1/agent/enroll`, `/api/v1/scripts/*`, `/api/v1/script-execution-logs`) protégés par middleware `EnsureWorkstationJwt` (RS256, claims `sub=workstation_uuid` + `tier=workstation` pour cohabitation controlHub). Génère/installe un CA root interne par serveur local (D9 : CA indépendant par étab), émet les certs `se4fs-<UAI>.<domaine>`. Stockage tokens en DB (`workstation_refresh_tokens`, `workstation_jwt_revocations`). Le HTTP md5/APCu legacy reste fonctionnel en parallèle (D8 dual-mode). Design "agent-ready" cohabitation controlHub. Charge : 4-6j. Prérequis : 16.8.*

### Story 16.11 : Auto-bootstrap migration postes existants

*Cadrage haut niveau : middleware `InjectBootstrapFragment` sur les endpoints legacy `*_out.php` qui détecte un poste non migré et préfixe la réponse d'un fragment de migration **idempotent**. Le fragment télécharge le CA root local, s'enrôle sur `/api/v1/agent/enroll` (échange du token md5 APCu actuel contre JWT), stocke les tokens localement (registre Win DPAPI machine / fichier Linux 0600), puis no-op aux exécutions suivantes. Table `workstation_migration_attempts` pour traçabilité + alerte si taux d'échec > 5%. **Aucune intervention admin requise** — bascule transparente du parc. Charge : 3-5j. Prérequis : 16.10.*

### Story 16.12 : Logs exécution centralisés (modèle DB + endpoint d'ingestion + wrapper côté poste + UI Livewire de consultation) ✅

*Cadrage haut niveau : table `script_execution_logs` (workstation_id, script_id, source, action, os, status, exit_code, stdout/stderr excerpts max 8KB, durée, correlation_id) + endpoint `POST /api/v1/script-execution-logs` auth JWT. Wrapper généré côté serveur enveloppe les scripts logon/startup/shutdown/logoff et POST le résultat. **UI Livewire de consultation** sous `/admin/settings/scripts-logs` : filtres (poste / script / action / statut / dates), tableau paginé, page détail stdout/stderr complets, bandeau d'indicateurs (taux échec 24h, top 5 postes/scripts en échec). Job d'archivage périodique (>90j) vers `storage/archives/`. Charge : 5-7j. Prérequis : 16.10. **Story partagée avec Epic 17 (17.4)** pour la couche infra logs.*

### Story 16.13 : Exposition endpoints natifs `/api/v1/*` ✅

> **Re-scopée 2026-05-19 via Sprint Change Proposal** (anciennement "Cleanup shims définitif + archivage code legacy"). Cleanup déplacé en 16.13bis. Modèle de bascule progressif intra-cluster abandonné car incompatible avec déploiement-par-collège.

*Cadrage haut niveau : exposition des 8 endpoints natifs sous `/api/v1/*` (wallpaper, firefox, thunderbird, shortcuts, network, veyon, associations, applications-scripts). Réutilisation directe des controllers existants livrés par 4.7, 4.8, 16.3a/b/c, 16.7. Auth via middleware JWT `auth.v1.workstation` (livré par 16.10), extraction `workstation_uuid` depuis JWT (pattern iso 16.12). Tests feature + architecture sur invariance des 8 routes. Les endpoints legacy `*_out.php` restent inchangés durant cette story — le refactor du modèle migration est dans 16.13bis. Charge : 2-3j. Prérequis : 4.7, 4.8, 16.3a/b/c, 16.7, 16.10. Source : `sprint-change-proposal-2026-05-19.md`.*

### Story 16.13bis : Module migration simplifié (SE4 → SE5) + cleanup shim 1bis.18 + UI tracking

> **Créée 2026-05-19 via Sprint Change Proposal**. Absorbe le refactor architectural du modèle migration.

*Cadrage haut niveau : refactor du modèle de migration SE4 → SE5 vers fragment+reboot, suppression du shim 1bis.18, isolation dans un module dédié.*

*Module migration `App\Auth\V1\Migration` : (1) refactor des 8 endpoints `/sambaedu/gpo/*_out.php` en `MigrationController::serveFragment(endpoint, os)` qui renvoie text/plain avec script cmd|sh (download CA, enroll, tokens DPAPI/0600, update registre vers `/api/v1/*`, puis `shutdown /r /t 30` avec message user-friendly uniforme Win+Linux) ; (2) suppression du middleware `InjectBootstrapFragment` (logique absorbée par MigrationController) ; (3) suppression du shim 1bis.18 (`legacy/sambaedu/gpo/*.php` embarqué) ; (4) archive `sambaedu/gpo/` → `legacy/archived/gpo-YYYY-MM-DD/` ; (5) commentaire en tête de module : "Module de migration SE4 → SE5. Ce code pourra être supprimé lorsqu'il n'existera plus de nécessité de migrer un déploiement SE4 vers SE5."*

*Eloquent tracking : relation `App\Models\Workstation::migrationStatus()` (hasOne `WorkstationMigrationStatus` via `uuid`↔`workstation_uuid`) + accessor `$workstation->migrated` (bool) + scopes `Workstation::migrated()` et `Workstation::notMigrated()`.*

*UI admin minimaliste : colonne "Migration" sur l'index admin des workstations (badge ✅/⏳/❌) + filtre par statut migration.*

*Décision proof-of-possession à trancher en SM : préserver `gpo/applications.php` (émetteur md5 APCu) **OU** abandonner md5 au profit de `EnsureLanIp` + UUID self-declared (SE4FS strictement LAN).*

*Tests E2E (poste appelle legacy URL → fragment → migre → reboot → next boot /api/v1/* OK) + runbook QA `docs/qa/domains/auth.md` enrichi. Charge : 4-5j. Prérequis : 16.13 done (impératif). Source : `sprint-change-proposal-2026-05-19.md`.*

### Story 16.14 : Améliorations UX UI admin GPO (onboarding, filtres, vue inverse OU↔GPOs, découvrabilité, dashboard jobs)

*Cadrage haut niveau : 5 améliorations UX ciblées suite à l'audit produit 2026-05-15 (cf. Tech Spec §5.7) — (A) card hero d'onboarding sur index GPO, (B) filtres avancés par type/OU/version/santé + export CSV/JSON, (C) nouvelle page "Vue par OU" (inverse de la vue actuelle "1 GPO → ses OUs"), (D) section "Sections gérables nativement" pour exposer les pages Firefox/Wallpaper/Shortcuts/Veyon (sortir 16.3a de l'ombre), (E) mini-dashboard "Jobs récents" pour Wine generation et WPKG republish. **Pas de refonte structurelle**. Audit trail (F) reporté Phase 3. **Déprogrammable** si charge dépassée — pas de dépendance bloquante en aval. Charge : 7-9j. Prérequis : 16.8 + 16.9.*

---

## Epic 17 : Scripts de Démarrage Windows et Linux

**Statut :** ✅ **DONE 2026-05-28 — 6/6 stories done (17-1..17-6)**. Clôturé par Henri. Compatibilité runtime des ~80 scripts versionnés par le package Debian `sambaedu` garantie avec Epic 15 (WPKG natif) + Epic 16 (GPO natives). Reste à Henri : smoke VM (audit template réel + parité bytes .cmd, DO-3 newline linux_out).

*Gestion des scripts Windows ET Linux (logon, startup, shutdown, logoff) déposés sur NETLOGON ou via GPO/`autorun`. Le responsable peut créer, éditer, versionner et associer des scripts à des cibles (utilisateur, machine, OU, WorkstationGroup). Logs d'exécution et rapports d'erreur côté serveur via channel `winscripts` (et `linscripts` pour Linux), centralisés en DB (table `script_execution_logs` partagée avec Epic 16 Phase 2).*

> **Note transversale :** cet épic remplace la Story 9.3 PAUSED. La frontière avec Epic 16 est : Epic 16 = configuration de policies GPO (registre Windows) ; Epic 17 = scripts exécutables (.bat, .vbs, .ps1, .cmd, .sh) déclenchés par GPO ou direct NETLOGON/`autorun`. Une story d'Epic 16 (16.6) consomme Epic 17 quand elle invoque un script (`wpkg.js`).

> **Fusion logique Epic 16+17 en Phase 2 (2026-05-15)** : les stories 17.2-17.4 partagent l'infrastructure avec Epic 16 Phase 2 — auth JWT (16.10), résolution polymorphique de cibles (réutilise les `script_assignments` de 17.3), et **logs centralisés DB+UI fusionnés avec 16.12**. La story 17.4 se concentre sur ce qui est *spécifique scripts* (alerting échecs récurrents, désactivation script depuis l'UI) plutôt que sur l'infra logs déjà couverte par 16.12.

> **Extension Linux** : la Phase 2 ajoute la prise en charge des scripts Linux (modèle parallèle `LinuxScript`/`LinuxScriptVersion`) qui n'étaient pas dans le scope initial Epic 17 mais sont nécessaires pour atteindre l'iso-legacy (cf. `gpo/applications.php?os=linux` du legacy).

**FRs couverts :** FR26
**Prérequis :** Epic 4 ✅, Story 17.1 ✅, Stories 15.2 ✅ + 16.5 ✅ + 16.6 ✅ + 16.7 ✅ + 16.12 ✅ + 16.13 ✅
**Annule/remplace :** Story 9.3 PAUSED → marqué « ❌ ANNULÉE — remplacée par Epic 17 »

> **⚠ Recadrage 2026-05-21 (post-audit 17.1 validé)** : le découpage initial
> (« Fondations scripts Windows / Éditeur Monaco / Association polymorphique / Rapports d'erreur »)
> reposait sur une mauvaise compréhension du domaine (cf. RESET Epic 17 du 2026-05-14
> dans le story file 17.1). Les scripts ne sont **pas du contenu utilisateur éditable**
> mais du **contenu versionné par le package Debian `sambaedu`**. Le scope d'Epic 17 est
> donc **compatibilité runtime** : garantir que les ~80 scripts livrés par le package
> continuent de fonctionner sur les postes après le remplacement du legacy par Epic 15
> (WPKG natif) et Epic 16 (GPO natives). Pas de nouvelle fonctionnalité éditeur, pas d'UI
> de versioning, pas de modèles Eloquent `WindowsScript`/`LinuxScript`.

---

### Story 17.1 : Audit des scripts packagés Windows & Linux ✅ done 2026-05-21

*Livrable : `_bmad-output/planning-artifacts/audit-applications-scripts.md` (1700+ lignes, ~80 scripts cartographiés en 7 sections A-G + 1 section bonus I logs). Validation Henri 2026-05-21 (patch post-Epic-16-done : 16.4 cancelled, 16.12/16.13 done → recadrage Section G). Aucun code applicatif. Pas de modèle Eloquent. Pas de migration. Pas d'endpoint.*

### Story 17.2 : Portage natif moteur `applications.php` + élargissement whitelist + intégration wrapper logs

*Cadrage post-audit : étend Story 16.7 (squelette `ApplicationScriptsGenerator`/`Assembler`/`Scanner`/`Controller` + whitelist 8 clés). Périmètre : (1) élargir `config/sambaedu.gpo.applications.substitutions.php` de 8 → 14 clés (ajout `ADMINSE_NAME`, `DHCP_MASQUE`, `DHCP_RESEAU`, `GLPI_URL`, `NO_INTERNET`, `SE4AD_IP`, `SE4FS_IP`, `SE4INSTALL_NAME`) en mappant chaque clé vers sa source Eloquent/config/env ; (2) audit de parité bytes legacy vs natif ; (3) intégrer `WrapperScriptRenderer` (16.12) dans `ApplicationScriptsAssembler` pour wrapper chaque fragment (couplé avec 17.5 opt-in). Charge : 2-3j. Prérequis : 17.1 ✅ + 16.7 ✅ + 16.12 ✅.*

### Story 17.3 : Compat GPO orchestratrice `se4_applications` (Stratégie A) ✅ done 2026-05-28

*Cadrage post-audit : Stratégie A confirmée (Henri 2026-05-21) — modèle « download GPO repo + set direct à l'install serveur ». Template `se4_applications` fourni par le package Debian (cf. `WpkgGpoSynchronizer` 16.6 pour le pattern). 17.3 vérifie que les `.cmd` orchestrateurs contenus dans le template (Machine\Scripts\Startup, Machine\Scripts\Logon) pointent vers les endpoints natifs `/api/v1/workstation-config/applications-scripts` (16.13) et non vers `gpo/applications.php` legacy. Patcher le template upstream ou ajouter substitution post-extraction si écart. Aucune création de GPO ni de synchronizer dédié (Story 16.4 cancelled). Charge : ~1j. Prérequis : 17.1 ✅ + 16.5 ✅ + 16.13 ✅.*

### Story 17.4 : Tests d'intégration runtime VM (5 scripts critiques)

*Cadrage post-audit : tests Feature end-to-end sur la VM `/vm` pour 5 scripts critiques (`wpkg/startup.windows`, `wallpaper/logon.windows`, `shortcuts/logon.windows`, `firefox/logon.windows`, `firefox/logon.linux`) + test de parité bytes legacy vs natif pour `applications.php` (legacy vs `/api/v1/workstation-config/applications-scripts`). Tests additionnels : surcharges admin `/etc/sambaedu/applications/<app>/` priorisées sur `/usr/share/`, placeholders étendus de 17.2 correctement substitués. ≥ 1 cas Linux testé (à confirmer avec Henri lors du dev). Charge : 2j. Prérequis : 17.2 + 17.3.*

### Story 17.5 : Intégration WrapperScriptRenderer dans pipeline 17.2 (opt-in)

*Cadrage post-audit : l'infrastructure logs (table `script_execution_logs`, endpoint `POST /api/v1/script-execution-logs`, `WrapperScriptRenderer`, templates `wrapper-cmd.blade.php`/`wrapper-sh.blade.php`, enums) est livrée par 16.12. 17.5 = brancher `WrapperScriptRenderer` dans `ApplicationScriptsAssembler` (17.2) pour wrapper chaque fragment (prefix setup + suffix POST log). Config opt-in (Henri 2026-05-21) `config('sambaedu.scripts.logging.enabled', false)` + commandes artisan `winscript-logs:enable` / `winscript-logs:disable`. Tests Feature : scripts wrappés vs non-wrappés selon flag. Charge : ~1j. Prérequis : 17.2 + 16.12 ✅.*

### Story 17.6 : Portage 2 endpoints orphelins `wpkg/{linux_out,winget_out}.php`

*Cadrage post-audit : Story 16.13 a porté 8 des 10 endpoints initialement listés sous `/api/v1/workstation-config/*`. Restent 2 endpoints orphelins (uniquement servis par shim 1bis-11 PHP-FPM legacy) : (1) `wpkg/linux_out.php` consommé par `applications/wpkg/startup.linux` (resolve packages APT via `WorkstationPackagesResolver` 15.2, retour plain-text `pkg1 pkg2 pkg3`), (2) `wpkg/winget_out.php` consommé par `install/os/SambaEdu/install.ps1` (mapping `packages.xml` + `add.json`/`remove.json` → JSON `{install,upgrade,uninstall}`). Consigne Henri 2026-05-21 : réutiliser code legacy comme base de portage, convertir requêtes LDAP en requêtes Eloquent sur modèles natifs. Pas d'auth (postes pas encore JWT-migrés au moment de l'appel install). Tests parité bytes/JSON vs legacy. Charge : ~2.5-3j. Prérequis : 17.1 ✅ + 15.2 ✅ + 16.13 ✅.*

---

## Epic 18 : Gestion DNS & réplication Active Directory

**Statut :** ⛔ cancelled (2026-05-13) — après examen du legacy : la **réplication AD** (`replicate_ad`, `check_ad_replication_errors` dans `includes/samba-tool.inc.php`) est central-only (callée uniquement depuis `central/php/includes/tdb_ui.inc.php`) et ne sera jamais portée dans sambaedu-reload local. Le **DNS local** (`dns_add`, `dns_delete`, `dns_*_ptr`, `dns_wpad` dans `includes/samba-tool.inc.php`) est déjà géré automatiquement en cascade depuis DHCP/PXE/parcs ; le legacy n'expose aucune page DNS dédiée et le besoin d'une UI de consultation/CRUD ne remonte pas du terrain. À rouvrir uniquement si un besoin concret émerge.

*Le responsable consulte et administre les entrées DNS du domaine AD (zones, enregistrements A / CNAME / PTR / SRV) et pilote la réplication Active Directory entre contrôleurs de domaine (état des liens, déclenchement manuel d'une synchro, monitoring des conflits). Sépare ces deux domaines fonctionnels du module GPO (Epic 16) qui cohabitaient historiquement dans le legacy `sambaedu/gpo/`.*

> **Origine de l'epic** (2026-05-11) : l'audit Story 16.1 (`audit-gpo-legacy.md`) a identifié que le legacy `sambaedu/gpo/` et `sambaedu/includes/gpo*.inc.php` portent — en plus des vraies GPOs — des fonctions DNS (`dns_add`, gestion zones) et de réplication AD (`replicate_ad`). Décision Henri : extraire ces deux périmètres de l'Epic 16 (qui reste centré sur les policies de registre Windows) et les traiter dans un epic dédié dont la priorité sera à arbitrer face aux autres modules legacy non encore migrés.

> **Frontière avec Epic 16** : Epic 16 = configuration des stratégies de groupe (GPO) qui s'appliquent aux postes Windows. Epic 18 = infrastructure DNS du domaine AD et synchro entre DCs (Domain Controllers). Aucune dépendance directe — les deux peuvent être menés en parallèle.

**FRs couverts :** FR21 (DNS) + nouveau FR à créer pour la réplication (à arbitrer dans le PRD).
**Prérequis :** aucun (peut démarrer après Story 16.1 qui aura cartographié le scope exact).
**Annule/remplace :** rien (les fonctionnalités existent en legacy via shim 1bis.18 ou via le legacy direct).

---

### Story 18.1 : Audit & cadrage périmètre DNS + réplication

*Cadrage haut niveau (à détailler) : inventaire des fonctions legacy concernées (croiser avec sections 6.A et 6.E de `audit-gpo-legacy.md`), définition des cas d'usage utilisateur (qui ajoute un DNS ? qui surveille la réplication ? fréquence ?), arbitrage in/out scope (zones publiques vs internes, DNS dynamique, etc.). Production d'un mini-cadrage `epic-18-scope.md`.*

### Story 18.2 : UI consultation DNS (lecture seule)

*Cadrage haut niveau : page Livewire native qui liste les zones DNS du domaine AD, affiche les enregistrements (A, CNAME, PTR, SRV), filtres simples. Pas encore de mutation — juste l'observation. Permet de retirer la page legacy équivalente du shim.*

### Story 18.3 : Édition DNS (CRUD enregistrements)

*Cadrage haut niveau : ajout, modification, suppression d'enregistrements DNS via `samba-tool dns add/update/delete`. Service dédié `App\Dns\Services\DnsService` (parallèle à `App\Gpo\Services\GpoService` — même pattern d'abstraction). Validation des inputs (regex IP, FQDN), confirmation utilisateur avant suppression, log channel dédié `dns`.*

### Story 18.4 : Monitoring réplication AD

*Cadrage haut niveau : tableau de bord état réplication entre DCs (liens, latence, conflits), invocation `samba-tool drs` (drs = directory replication service). Read-only dans un premier temps.*

### Story 18.5 : Déclenchement manuel de réplication

*Cadrage haut niveau : action utilisateur qui force une synchro entre DCs (utile post-modification massive). Confirmation préalable, journalisation, traçabilité.*

---

## Backlog — Update sambaedu legacy

*Notes des évolutions remontées depuis `sambaedu/` upstream qui n'ont pas encore d'équivalent reload, à re-évaluer lors des migrations futures. Rempli à partir du pull `830a2ce..5969d89` (versions XP 4.17.580 → 4.17.593) synchronisé sur la VM le 2026-04-17.*

### USL-1 : VNC comme fallback de connexion distante
- **Source legacy** : commits `8b2ef1a9e` + `f235394fa` — `includes/remote.inc.php` + `includes/parcs.inc.php`
- **Comportement upstream** : `create_remote_json_connection()` bascule `type=vnc` (au lieu de `rdp`) quand `!empty($config['vnc_password']) && !$machine['open'] && !empty($machine['user'][0]['name']) && $machine['user'][0]['name'] != 'wpkg'` — i.e. session étrangère en cours. `form_machines_parc()` affiche alors l'icône `vnc.png`.
- **Impact reload** : `app/Services/Parc/RemoteAccessService.php` expose `generateRemoteToken($machine, $type='rdp', ...)` sans logique de bascule. Aucun écran reload ne consomme encore VNC — à répliquer **le jour où** un écran reload offre "prendre la main sur une machine occupée".
- **Action** : à traiter dans une story quand le besoin remonte côté front reload. Pour l'instant, le legacy gère lui-même via `user_interface.php`.

### USL-2 : Refonte de l'onglet "reinit" pour le rôle refnum
- **Source legacy** : commits `c58b9764e` (tableau de bord refnum), `2f8de08a1` (pages users/machines refnum), `ae9f280fd` (injection `action_parc` dans onglet applis), `3e9e9714c` / `fe3b6dd29` / `8af14e582` / `93ff1b223` (peaufinage du wrapper)
- **Comportement upstream** : `html_user_interface()` case `"reinit"` structure désormais une page à plusieurs blocs : `search_html` + `annu` (restreint à `people_html|group_html|peoples_list_html|groups_list_html`) + injection conditionnelle de `show_histo_html` (quand `annu=people_html&cn=...`), puis `quotas`, `reinit_pwd` et `create_temp_users`. La case `"applis"` charge `action_parc_html` + `assign_parcs` + `assign_applis` avec `parcs_url_rewrite()` pour rester dans le wrapper.
- **Impact reload** : le rôle refnum reload voit aujourd'hui la page legacy via `homelegacy`. Pas de page reload dédiée.
- **Action** : quand Epic 7 / Epic 11 aborderont explicitement le profil refnum, s'inspirer de la structure de la page legacy plutôt que de la concevoir from scratch. Ne pas reporter maintenant — le legacy couvre le besoin.

### USL-3 : Menu legacy `parcs/` → `parcs2/`
- **Source legacy** : commit `cbc1e07eb` — `includes/menu.d/60parcs.inc`
- **Nature** : correction interne legacy. Le sidebar reload (`components/organisms/sidebar.blade.php`) ne consomme pas ce fichier.
- **Action** : aucune (poussé sur la VM pour parité legacy, c'est tout).

### Sanity check show_histo depuis reload
- `resources/views/pages/users/[login]/_partials/user-activity.blade.php:146,157` construit des URLs `/parcs/show_histo.php?selectionne=3&user={login}`. Le refactor de `show_histo_html()` dans `2f8de08a1` **préserve** la lecture des paramètres `user` et `mpenc` : les hidden-inputs sont émis quand `selectionne != 0`. **Lien reload non cassé** — à vérifier manuellement quand quelqu'un ouvrira la fiche d'un user sur la VM.
