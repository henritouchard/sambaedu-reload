---
stepsCompleted: [scoping]
inputDocuments:
  - planning-artifacts/study-meshcentral-technicien-controlhub.md
  - planning-artifacts/handoff-guacamole-controlhub.md
---
# Canal technicien MeshCentral - Epic Breakdown (Epic 40)

## Overview

Doter SE5 d'un **canal de dépannage système par les techniciens** (connectés à controlHub) :
prise en main **en arrière-plan, sans login/mot de passe, pendant l'usage** du poste (shadow
de la session active), terminal SYSTEM, transfert de fichiers — via **MeshCentral**.

Ce canal est **distinct et complémentaire de Guacamole** (Epic 19), qui reste l'accès *usager*
(élève/prof) à un poste. Voir le positionnement dans
`study-meshcentral-technicien-controlhub.md` §1.

Modèle retenu (décisions Henri 2026-07-06) :
- **Broker controlHub** : controlHub forge un *device-sharing link* MeshCentral temporaire par
  poste ; aucun compte MeshCentral technicien ; authz + audit dans controlHub. Symétrique de la
  couture token Guacamole (`handoff-guacamole-controlhub.md` C10-4).
- **Déploiement du MeshAgent par l'agent Go SE5** (desired-state, handler `meshagent`), réutilisant
  le pattern de staging content-addressed `agent/provision/` (module WPKG tools).
- **Consentement RGPD** : notification systématique + consentement pour shadow de session
  utilisateur ; terminal/fichiers SYSTEM hors-session sans prompt ; audit controlHub.

**Périmètre de cet epic = SE5 (sambaedu-reload) uniquement.** L'infra serveur MeshCentral et le
broker `meshctrl` vivent côté controlHub (`../irundoo`, dataset `central`) — voir §Notes de
coordination. La forge du lien est hors-scope SE5, comme la forge des JWT techniciens (story 20.1).

## Requirements Inventory

### Functional Requirements
- **FR-M1** — L'agent SE5 déploie et maintient le MeshAgent sur les postes via l'état cible (install, config serveur, service, self-heal).
- **FR-M2** — Le déploiement cloisonne chaque poste dans le **device-group de son établissement** (mesh install string résolu par étab côté serveur).
- **FR-M3** — SE5 remonte et **persiste le mapping node MeshCentral ↔ poste** (`workstations.meshcentral_node_id`) puis l'**expose à controlHub** (canal API clé d'instance) pour que le broker forge le lien.
- **FR-M4** — SE5 configure la **politique de consentement/notification** de prise en main par device-group et **journalise** les sessions techniciens (audit RGPD).

### NonFunctional Requirements
- **NFR-M1** — Aucun flux entrant à ouvrir côté établissement : MeshAgent en connexion **sortante** WebSocket 443 (NAT traversal).
- **NFR-M2** — Binaire MeshAgent **signé** ; vérification d'intégrité **SHA-256 au staging** (porte identique à l'auto-update agent).
- **NFR-M3** — **Aucun compte MeshCentral technicien** : toute session passe par un partage pré-autorisé forgé par controlHub (broker).
- **NFR-M4** — Journalisation/rétention RGPD des sessions (qui / quel poste / quand / durée) ; postes potentiellement élèves mineurs.
- **NFR-M5** — Déploiement **par l'agent uniquement** (pas GPO/legacy — cf. Epic 38, kill-switch config).

### FR Coverage Map
- FR-M1 : Epic 40 — Story 40.2
- FR-M2 : Epic 40 — Story 40.1 (résolution serveur) + 40.2 (application agent)
- FR-M3 : Epic 40 — Story 40.3 (remontée/persistance) + 40.4 (exposition controlHub)
- FR-M4 : Epic 40 — Story 40.5
- NFR-M1..M5 : transverses, ancrées 40.1→40.5

## Epic 40: Canal technicien MeshCentral (dépannage système via controlHub)

Rendre opérant le dépannage système des postes SE5 par les techniciens controlHub, sans jamais
ouvrir de flux entrant ni distribuer de compte MeshCentral. Chaque story reste autonome et
déployable ; ordre recommandé : **40.1 → 40.2 → 40.3 → 40.4**, puis **40.5** (transverse, peut
démarrer en parallèle dès 40.2).
**FRs covered:** FR-M1..FR-M4, NFR-M1..NFR-M5

---

### Story 40.1: Type de contrat `meshagent` + résolution serveur du device-group par établissement

**Intention.** Ajouter l'item desired-state `meshagent` au contrat v1 (portée MACHINE) : version
cible, `server_url`, `mesh_install_string`. Résoudre côté serveur le `mesh_install_string` du
device-group de l'**établissement** du poste et l'injecter dans l'état cible machine.

**AC-skeleton (à figer au create-story) :**
- Nouveau `type: meshagent` documenté dans `docs/agent/contract-v1.md` + golden `tests/Fixtures/Agent/*.v1.json` (hashes jumeaux PHP↔Go).
- Le serveur résout le device-group depuis l'établissement du poste (`se4fs_etab_rattachement`, OU par étab) et n'émet l'item que si un mapping étab→mesh existe.
- Le mesh install string par étab est **configurable** (table/seed ou config), pas en dur.
- **Tâche** : décider si l'item est émis pour tous les postes d'un étab couvert, ou opt-in par groupe/parc.

**Dépendances.** Amont : contrat v1 (Epic 24/27). Bloquant pour 40.2. **Reco dev** : sonnet (mapping HTTP/contrat) — à confirmer au create-story.

---

### Story 40.2: Handler agent `meshagent` — staging signé, install & self-heal

**Intention.** Nouveau handler desired-state `meshagent` : stage le MeshAgent **signé** (pattern
`agent/provision/`), installe/configure le service pointant sur `server_url` + device-group,
converge (Test/Apply), self-heal si le service dérive. Bump `agent/shared/version.go`.

**AC-skeleton (à figer au create-story) :**
- Handler `meshagent` enregistré dans `MachineEngine` ; logique pure `shared/handler_meshagent.go` + ops Windows `windows/handler_meshagent_windows.go`.
- Staging content-addressed avec **porte SHA-256** avant exécution de l'installeur (comme `update.go`).
- Test = service présent + configuré sur le bon serveur/mesh ; Apply = install/reconfigure idempotent ; verdict par type.
- Rien réimplémenté du MeshAgent (binaire C) : l'agent installe/configure/supervise.
- Bump de version agent ; contrat golden mis à jour ; publication release **AVANT** migrate (cf. gate Epic 35).

**Dépendances.** Amont : 40.1 (émission de l'item), module `provision/`. Bloquant pour 40.3. **Reco dev** : opus (handler agent Win32 + service + intégrité) — à confirmer au create-story.

---

### Story 40.3: Remontée & persistance du nodeid (mapping node ↔ poste)

**Intention.** Le nodeid MeshCentral n'est pas déterministe (dérive du cert de l'agent) → l'agent
le **remonte** dans son rapport ; le serveur le **persiste** sur le poste + santé du service.

**AC-skeleton (à figer au create-story) :**
- Le rapport agent (`/api/v1/agent/report`) porte `meshcentral_node_id` + état du service.
- Migration additive `workstations.meshcentral_node_id` (+ éventuel `meshagent_last_seen_at`).
- Ingestion serveur idempotente (re-report = no-op au même nodeid) ; nodeid vidé/renouvelé géré.
- **Tâche** : définir le comportement si le nodeid change (réinstall MeshAgent) — écrasement vs historique.

**Dépendances.** Amont : 40.2 (l'agent a un MeshAgent installé). Bloquant pour 40.4. **Reco dev** : sonnet (migration + ingestion report) — à confirmer au create-story.

---

### Story 40.4: Exposer le mapping node ↔ poste à controlHub (broker-ready)

**Intention.** Donner à controlHub, via le canal API existant (clé d'instance, `ControlHubAuth`),
le node MeshCentral d'un poste pour que le broker forge le device-sharing link.

**AC-skeleton (à figer au create-story) :**
- Endpoint sous `controlhub.auth` (Bearer clé instance / token handshake, cf. couture 39.5) exposant `{ workstation, meshcentral_node_id, agentPresence, etab }` — lecture seule.
- Scoping : ne renvoie que les postes du périmètre de la connexion controlHub appelante.
- Réponse 403 (jamais 401), route APRÈS le groupe 16.12 (fenêtre 1500 chars — cf. `api_routes_arch_test_window_trap`).
- **Tâche** : batch (liste par étab) vs unitaire (par poste) — trancher selon le besoin du broker.

**Dépendances.** Amont : 40.3 (mapping persisté), canal controlHub (Epic 39). Coordination : broker côté `../irundoo`. **Reco dev** : sonnet (endpoint API scopé) — à confirmer au create-story.

---

### Story 40.5: Politique de consentement/notification + audit RGPD des sessions

**Intention.** Configurer la politique de prise en main par device-group (notification
systématique + consentement pour shadow de session utilisateur ; terminal/fichiers SYSTEM
hors-session sans prompt) et journaliser les sessions techniciens (audit RGPD).

**AC-skeleton (à figer au create-story) :**
- Politique de consentement/notification portée par la config du device-group MeshCentral (provisionnée côté infra, référencée par SE5 ; part configurable côté SE5 à préciser).
- Les sessions techniciens sont **journalisées** (qui / quel poste / quand / durée) et consultables ; rétention RGPD définie.
- Le cas « poste potentiellement élève mineur » impose notification + consentement pour tout shadow de session utilisateur.
- **Tâche** : trancher où vit l'audit faisant foi (controlHub vs SE5 vs double) et la durée de rétention.

**Dépendances.** Amont : 40.2 (MeshAgent déployé). Coordination : infra MeshCentral + broker (`../irundoo`). **Reco dev** : sonnet (config + journalisation) — à confirmer au create-story.

## Notes de coordination

**Hors-scope SE5 — à porter côté controlHub (`../irundoo`, dataset `central`, epic Cx dédié) :**
- Serveur MeshCentral central (NGINX/TLS offload, config.json, LDAP optionnel), un **device-group par établissement**.
- **Broker** : forge du device-sharing link temporaire via `meshctrl` / API à partir d'un login-token admin MeshCentral, scoping par rôle technicien, embed/redirect, audit — analogue au handoff Guacamole C10-x.
- Émission du `mesh_install_string` par étab consommée par la story 40.1 (contrat de coordination à figer).

**Faits MeshCentral de référence** : voir `study-meshcentral-technicien-controlhub.md` §8
(MeshAgent sortant 443, device-groups, login tokens & device sharing, consentement/notification).
