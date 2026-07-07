# Étude — Intégration MeshCentral : canal technicien de dépannage SE5 via controlHub

> Statut : étude d'intégration (pré-epic). Décisions structurantes tranchées avec le user (§7).
> Voisins : `handoff-guacamole-controlhub.md`, `quickspec-guacamole-sambaedu-reload.md`.

## 1. Positionnement — deux canaux d'accès distant complémentaires

Guacamole et MeshCentral ne se recouvrent pas et ne doivent pas être fusionnés.

| | **Guacamole** (existant) | **MeshCentral** (à intégrer) |
|---|---|---|
| Public | Élève / prof | **Technicien** (via controlHub) |
| Nature | Accès *usager* à un poste (RDP/VNC/SSH), session interactive, clientless | Dépannage *système* en arrière-plan, **sans login/mdp**, **pendant** l'usage du poste (shadow session active), terminal SYSTEM, transfert de fichiers |
| Réseau | `guacd` sur le LAN de l'étab, RDP activé sur le poste | **MeshAgent sortant** (WebSocket 443) → traverse le NAT, **aucun flux entrant** côté étab |
| Déploiement | Infra par établissement | Agent poussé par l'agent SE5 ; serveur central unique |

MeshCentral occupe la case vide : prise système en tâche de fond, sans credentials, à travers Internet, multi-établissement. L'agent Go SE5 n'a **aucun** code de télé-assistance aujourd'hui (handler `privilege` ne fait que *refuser* le RDP `SeDenyRemoteInteractiveLogonRight`) → terrain vierge.

## 2. Topologie cible

```
Technicien ── (session controlHub, déjà authentifié)
   │
   ▼
controlHub (../irundoo)  ──── login-token admin MeshCentral (secret serveur)
   │  broker : forge un lien de partage temporaire pour le node du poste X
   ▼
MeshCentral Server (central, à côté de controlHub, derrière NGINX/TLS offload)
   ▲   ▲   ▲   ... WebSocket 443 sortant, un device-group par établissement
MeshAgent  MeshAgent  MeshAgent   (postes SE5, service SYSTEM)
   ▲
   │ installé & configuré par l'agent Go SE5 (desired-state), nodeid remonté
```

Serveur MeshCentral **central et unique** (côté controlHub) : joignable de tous les étabs, postes en connexion sortante. Un **device-group par établissement** = frontière de cloisonnement.

## 3. Couture d'authentification — modèle broker

Calqué sur la couture Guacamole admin (`handoff C10-4`) et le principe « controlHub = autorité, contrat = rôle » :

1. Technicien déjà authentifié dans controlHub.
2. Choisit « dépanner le poste X de l'étab Y ».
3. controlHub — seul détenteur d'un login-token admin MeshCentral — appelle l'API `meshctrl` pour **forger un lien de partage (device sharing) temporaire** ciblant le *node* du poste X (bureau + terminal + fichiers, borné dans le temps).
4. controlHub redirige/embarque le technicien. **Aucun compte MeshCentral pour le technicien.**

La décision d'autorisation reste **dans controlHub** (modèle de rôles) ; MeshCentral exécute un partage pré-autorisé. Zéro compte à synchroniser, audit centralisé. La forge du lien est **hors-scope sambaedu-reload** (comme la forge des JWT techniciens, story 20.1) → vit dans `../irundoo`.

## 4. Périmètre sambaedu-reload

**a) Déploiement du MeshAgent via l'agent Go** — nouveau handler desired-state `meshagent`, réutilisant le pattern de staging content-addressed (`agent/provision/`, module WPKG tools) :
- état cible machine : `{ meshagent: version, server_url, mesh_install_string (par étab) }` ;
- l'agent télécharge le MSI/exe MeshAgent **signé** (porte SHA-256 comme l'auto-update), installe le service, configure le device-group ;
- remonte le `nodeid` + santé du service dans `/api/v1/agent/report`.
- MeshAgent = binaire C mature : **rien à réimplémenter en Go**, l'agent installe/configure/supervise. Bump version agent + type ajouté au contrat v1 (golden + hashes jumeaux PHP↔Go).

**b) Mapping node ↔ poste** — colonne `workstations.meshcentral_node_id`, alimentée par le rapport agent (nodeid dérive du cert de l'agent → non déterministe → doit être remonté), exposée à controlHub via le canal API existant (clé d'instance symétrique, middleware `ControlHubAuth`).

**c) Résolution par étab** — le serveur SE5 résout le `mesh_install_string` du bon device-group depuis l'établissement du poste (`se4fs_etab_rattachement`, OU par étab, scoping délégation).

## 5. Points sensibles

- **RGPD / mineurs** : notification + consentement en **politique par device-group** (cf. §7).
- **Cloisonnement** : device-group par étab + rôle controlHub scopé = technicien limité à son périmètre. Défense en profondeur.
- **Extinction legacy** : déploiement par l'**agent** (canal GPO/legacy en extinction, Epic 38, kill-switch).
- **Greenfield** : cas « poste frais sans MeshAgent » amorcé par le handler `meshagent` lui-même (cf. trous provisioning `wpkg-client.vbs`, overlay).

## 6. Découpage indicatif (futur epic)

1. **Infra centrale** (`../irundoo`) : serveur MeshCentral, NGINX/TLS offload, device-groups par étab, config broker — logique du handoff Guacamole C10-x.
2. **Agent SE5** : handler `meshagent` (stage+install+configure+report nodeid), bump version, contrat v1.
3. **Serveur SE5** : colonne `meshcentral_node_id`, ingestion depuis rapport agent, exposition canal controlHub, résolution mesh-string par étab.
4. **controlHub broker** (hors-scope repo) : forge lien via `meshctrl`, scoping par rôle, embed/redirect, audit.
5. **Politique consentement/RGPD + rétention + journalisation**.

## 7. Décisions structurantes (tranchées)

- **Auth technicien** : **Broker controlHub**. controlHub forge un lien de partage temporaire par poste via l'API MeshCentral ; aucun compte MeshCentral technicien ; authz + audit dans controlHub.
- **Déploiement MeshAgent** : **Handler agent SE5 desired-state** (`meshagent`), réutilise le pattern `provision/`.
- **Consentement RGPD** : **Notification systématique + consentement obligatoire pour shadow de session utilisateur ; terminal/fichiers SYSTEM hors-session sans prompt ; audit controlHub**.

## 8. Faits MeshCentral de référence

- MeshAgent : connexion **sortante** WebSocket 443 → NAT traversal, aucun flux entrant à l'étab.
- Accès **système en arrière-plan sans credentials**, y compris pendant l'usage (shadow session active).
- **Device-groups** (mesh) + **domains** (multi-tenant) ; LDAP/SSPI possible.
- Reverse proxy NGINX/HAProxy avec `TlsOffload`.
- **Login tokens** + **device sharing links** temporaires via `meshctrl` / API → primitive du broker (invité sans compte).
- Consentement + barre de notification pour la prise de main (levier RGPD).

Sources : docs.meshcentral.com, github.com/Ylianst/MeshCentral, blog meshcentral2 (login tokens & guest sharing).
