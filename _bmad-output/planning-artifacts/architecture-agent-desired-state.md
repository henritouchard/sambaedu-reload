---
stepsCompleted: [1, 2, 3, 4, 5, 6, 7, 8]
lastStep: 8
status: 'complete'
completedAt: '2026-06-11'
inputDocuments:
  - '_bmad-output/brainstorming/brainstorming-session-2026-06-10-1848.md'
  - '_bmad-output/planning-artifacts/product-brief-gpo-successor-2026-06-08.md'
  - '_bmad-output/planning-artifacts/product-brief-agent-desired-state-2026-06-11.md'
  - '_bmad-output/planning-artifacts/spike-windows-anchor-2026-06-08.md'
  - '_bmad-output/planning-artifacts/spike-wallpaper-overlay-tools-2026-06-09.md'
  - '_bmad-output/planning-artifacts/audit-gpo-legacy.md'
  - 'docs/tech-debt-gpo.md'
  - '_bmad-output/planning-artifacts/architecture.md'
  - '_bmad-output/planning-artifacts/prd.md'
workflowType: 'architecture'
scope: 'Agent desired-state — successeur GPO (SE5)'
parentArchitecture: '_bmad-output/planning-artifacts/architecture.md'
project_name: 'codebase'
user_name: 'henri'
date: '2026-06-11'
---

# Architecture Decision Document — Agent desired-state (successeur GPO)

_Ce document se construit collaborativement étape par étape. Les sections sont ajoutées au fil des décisions architecturales prises ensemble. Il est dédié au sous-projet « agent desired-state » et complète l'architecture globale SE5 (`architecture.md`) sans la remplacer._

---

## Project Context Analysis

### Requirements Overview

**Exigences fonctionnelles (issues du brainstorming 2026-06-10/11 + brief successeur-GPO) :**

Le socle en une phrase (Vérités #1-6) :

> « SE5 détient l'état cible de chaque poste et de chaque session, fonction de
> (poste, user). Un agent de convergence unique sur le poste tire cet état à
> trois occasions (boot, login, timer), exécute la différence, et rapporte
> l'état réel. »

Décomposition architecturale :

1. **Contrat serveur** — `GET /api/state?host=X&user=Y` → JSON 3 portées
   (machine, session, machine×user) + `POST /report` (état réel, delta/hash).
   **`f(poste, user)` est la signature du contrat, pas la maille des règles** :
   les règles (apps, raccourcis, wallpaper…) s'attachent à des **mailles de
   ciblage** — workstationGroup(s) (salle/parc/étab), **poste individuel**,
   groupes de l'utilisateur, user individuel, broadcast — et le serveur les
   **résout transitivement** au moment de l'appel (poste → ses appartenances
   + ses règles propres → merge → état cible). Iso-modèle du ciblage overlay
   déjà livré (broadcast/salle/poste/user). Le principe « penser en règles,
   pas en postes » [#18] est un défaut d'UX, pas une restriction : la maille
   poste sert les cas particuliers, et le poste réapparaît systématiquement
   en reporting de conformité. L'état cible = projection JSON de la DB SE5
   existante (workstationGroups, biblio wallpapers, applications) — pas de
   nouveau modèle de données métier, l'UI d'administration existe déjà (F4
   dissoute). La **fonction de compilation d'état** (résolution + merge +
   précédence entre mailles) est un composant serveur à part entière — c'est
   elle que le legacy n'a jamais matérialisée (Vérité #1).
2. **Agent de convergence** — service Windows SYSTEM au boot (portée machine)
   + processus compagnon par session (portée user) [#11]. Boucle générique :
   « pour chaque ressource : si !test → apply ; rapporter ». Un agent par OS =
   couche de portabilité [#21] ; le config-as-code Linux existant devient le
   premier adaptateur du même contrat.
3. **Handlers test/apply/report** — un par type de ressource (~10 × ~50 lignes
   estimés) : wallpaper/overlay (1er, POC existant), raccourcis, lecteurs,
   imprimantes [#9], associations, registre, config d'app déclarative
   (policies.json) [#14] ; applis = moteur déclaratif WPKG conservé (un tuyau,
   deux outils). Licences à pool = sous-produit du reporting [#15].
4. **Cycle de vie du token** [#22] — né à l'enrôlement (Sanctum, haché en DB,
   colonne sur workstations), déposé par l'install WinPE, ACL SYSTEM,
   rotation glissante au check-in, révocation par événement, JAMAIS
   d'expiration calendaire. Portée minimale : lire SON état, écrire SES rapports.
5. **Deux portes d'enrôlement** [#23] — poste neuf : token auto via iPXE ;
   poste migré : agent posé par la GPO-dispatcher figée (bootstrap) +
   approbation un-clic dans l'UI.
6. **Mode strict/défaut par item** [#25] — décision métier exposée dans l'UI,
   booléen présent dans le schéma JSON v1 (rétrofit impossible).

**Exigences non-fonctionnelles (issues des parades #25-32) :**

- **Login jamais bloquant** [#26] : convergence session asynchrone après
  ouverture + cache local du dernier état ; le login ne dépend JAMAIS du réseau.
- **Autonomie locale** (Vérité #8) : LAN seul = fonctionnel (miroir local) ;
  serveur injoignable = dernier état connu. Élimine le cloud-first.
- **Fraîcheur laxe** (Vérité #5) : pull HTTP aux points de synchro naturels
  (boot, login, timer) + bouton « forcer la synchro » ; pas de push temps réel.
- **Canal d'update = partie la plus testée** [#27] : déploiement canari
  (1 poste → 1 salle → 1 étab) ; le bootstrap GPO reste le filet éternel.
- **Reporting delta/hash** [#32] : « conforme » = 1 ligne ; historiser peu,
  agréger vite (~600 postes × ~96 check-ins/jour).
- **Frontière de confiance** [#12] : état tiré exclusivement du serveur
  authentifié ; fichiers agent non modifiables par l'élève.
- **Anti-clonage** [#28] : détection serveur même token / MAC-hostname
  différents → alerte + quarantaine.
- **Signature de code** [#31] : CA interne (racine déployée par l'install)
  pour lab/premiers déploiements ; certificat OV public à budgéter pour la
  diffusion large ; pipeline de build qui signe dès le premier prototype.

**Scale & Complexity :**

- Domaine primaire : backend API (Laravel/SE5) + agent endpoint-management
  multi-OS (système distribué client-serveur)
- Complexité : haute, mais confinée par design — chaque vice OS vit dans UN
  handler testable [#13] ; le chiffrage honnête compte les handlers
- Composants estimés : côté serveur ~6 (compilation d'état, API state/report,
  gestion token/enrôlement, reporting/conformité, UI strict-défaut,
  distribution agent+canari) ; côté client 1 agent par OS (core + handlers
  + updater + cache)

### Technical Constraints & Dependencies

- **C1 — Parc hétérogène, jamais homogène** : chemin de rattrapage obligatoire
  pour postes migrés/anciens (bootstrap GPO) ; techno agent (binaire Go vs
  PowerShell) = décision d'implémentation, pas de blocage (F5).
- **C2 — Identité AD/Kerberos iso-legacy pour l'existant** ; canal agent =
  NEUF → bearer token per-host accepté (F1 tranché par Henri). Chemin
  Kerberos machine écarté (épaissirait la dépendance AD).
- **C3 — Admins non-devops** : pilotage par UI SE5 exclusivement, pas de
  YAML/git exposé.
- **C4 — Serveur local souverain** : serveur d'étab = source de vérité ;
  cloud futur = sauvegarde fichiers user, jamais la config.
- **Critère Keycloak** (transversal, non négociable) : le successeur GPO ne
  crée AUCUNE nouvelle dépendance à l'AD — l'AD sert au bootstrap puis plus
  jamais.
- **Transition** (F3, #24) : bascule par type de ressource, jamais 2 systèmes
  sur le même type ; du simple au dur (wallpaper/overlay → raccourcis →
  imprimantes → registre/associations → applis) ; pas de prod avant parité
  complète (cohabitation = lab uniquement).
- **Dépendances existantes réutilisées** : endpoint overlay + biblio d'assets
  (POC committé f9b3ad9), canal `/api/v1/workstation-config/*` + JWT
  workstation, chaîne iPXE (enrôlement + réinstall = handler ultime [#17]),
  GPO `se4_applications` déjà quasi-dispatcher (spike windows-anchor),
  stack SE5 (Laravel, Livewire, PostgreSQL, Sanctum, Spatie).
- **Anti-dépendance** : SYSVOL/SMB comme transport de config = habitude à
  remplacer par HTTP (Vérité #7) ; Samba conserve identité, fichiers,
  impression.

### Cross-Cutting Concerns Identified

| Préoccupation | Impact |
|---|---|
| Trois portées d'état (machine / session / machine×user) | Contrat JSON, architecture agent (2 processus), conception de chaque handler |
| Résolution par mailles (workstationGroups / poste / groupes user / user / broadcast) | Fonction de compilation d'état (merge + précédence à trancher en décisions core — intuition : maille la plus spécifique l'emporte), UI règles, reporting — même logique de ciblage que les signaux overlay déjà livrée |
| Réconciliation level-triggered [#16] | Idempotence obligatoire de tous les handlers ; l'histoire du poste ne compte plus |
| Cycle de vie token & enrôlement | API, DB workstations, install WinPE/iPXE, bootstrap GPO, UI approbation |
| Strict vs défaut par item [#25] | Schéma JSON v1, UI admin, logique de convergence de chaque handler |
| Observabilité (état rapporté, conformité) | Serveur (stockage agrégé), agent (delta/hash), UI (reporting par règle, poste = exception [#18]) |
| Transition legacy | GPO figées en dispatcher/bootstrap, PASSTHROUGH_HANDLERS, décommissionnement par ressource |
| Sécurité chaîne agent | Signature de code, frontière de confiance, anti-clonage, canari |
| Anti-couteau-suisse [#30] | Contrat en une phrase : « converger l'état, rapporter l'état » — tout le reste = un AUTRE logiciel |

---

## Starter Template Evaluation

### Primary Technology Domain

Système distribué client-serveur : backend API dans SE5 existant (brownfield)
+ agent endpoint-management multi-OS (composant neuf côté poste).

### Côté serveur — pas de starter (brownfield SE5)

Aucun starter à évaluer : tout le versant serveur s'implémente dans le
codebase SE5 existant, dont la stack est verrouillée par l'architecture
globale (`architecture.md`) :

| Composant | Choix (hérité) |
|---|---|
| Backend | Laravel |
| Frontend réactif | Livewire 4 SFC (convention pages/) |
| UI | DaisyUI |
| Base de données | PostgreSQL |
| Tokens API | Sanctum (déjà utilisé irundoo↔SER) |
| Permissions | Spatie |

**La fondation serveur existe déjà en partie** (POC overlay, commits
41e7a8b/f9b3ad9/375324b) : endpoint JSON authentifié JWT workstation,
facade `OverlayService`, store `overlay_signals` avec ciblage 4 mailles,
biblio d'assets wallpaper. Le premier handler (wallpaper/overlay)
étendra cet existant — pas de projet à initialiser.

### Côté client — techno agent : décision d'implémentation REPORTÉE (iso-F5)

Décision actée en brainstorming (F5) et confirmée ici : le choix de la
technologie de l'agent (binaire autonome type Go, .NET Worker Service,
PowerShell, autre) est une **décision d'implémentation**, prise au moment
du spike/PoC agent — pas un préalable architectural. L'architecture reste
agnostique : le contrat est HTTP/JSON, n'importe quelle techno capable de
poller une API et d'exécuter des actions locales peut l'honorer (preuve :
le config-as-code Linux existant le fait en bash).

**Contraintes que le choix devra satisfaire (cahier des charges du spike) :**

1. **Service Windows SYSTEM** au boot + **processus compagnon par session**
   (portée user) [#11] — modèle natif Windows requis.
2. **Signature de code Authenticode** [#31] : la techno doit produire un
   artefact signable (binaire/exe) ; pipeline de build qui signe dès le
   premier prototype ; CA interne d'abord, OV publique ensuite.
3. **Frontière de confiance** [#12] : artefacts sous ACL SYSTEM, non
   modifiables par l'élève — un script en clair éditable est disqualifié
   pour le cœur (élévation de privilèges sinon).
4. **Auto-update fiable** [#27] : canal d'update = partie la plus testée,
   canari, et le bootstrap GPO sait réinstaller un agent mort.
5. **Portabilité du modèle** [#21] : pas d'exigence d'un binaire unique
   multi-OS — « un agent par OS » est le modèle ; mais le cœur (boucle
   test/apply/report, parsing du contrat) gagne à être partageable.
6. **Parc hétérogène** (C1) : zéro dépendance runtime exotique sur les
   postes (vieux Windows 10 possibles) ; statique/autonome privilégié.
7. **Empreinte discrète** : poll HTTP + actions locales — pas de besoin
   de perfs particulières ; la simplicité d'opération prime.

**Note :** le PoC P2 (premier handler wallpaper en lab) est l'endroit
naturel pour trancher — il peut démarrer en PowerShell jetable pour
valider la boucle, le choix définitif étant requis avant le premier
déploiement hors lab (la signature de code impose l'artefact final).

---

## Core Architectural Decisions

### Decision Priority Analysis

**Critiques (bloquantes pour l'implémentation) — tranchées :**
D1 modèle de règles, D2 précédence/merge, D4 contrat d'endpoints,
D5 rotation token, D6 distribution/canari.

**Importantes (structurent l'architecture) — tranchées :**
D3 stockage des rapports, D7 cadence par défaut.

**Reportées (explicitement) :**
techno agent (step-03, gate = PoC P2) ; cache applicatif par maille
(mesurer avant d'optimiser) ; localisation du code agent dans le repo
(step-06 structure).

### Data Architecture

**D1 — État cible = projection pure (StateProviders), pas de table générique
de règles.**
La fonction de compilation d'état interroge des `StateProvider` (un par type
de ressource) qui lisent les tables métier existantes (wallpapers + liens,
applications, shortcuts, overlay_signals…). Un type sans table métier (ex.
registre) recevra sa table dédiée au moment de son handler — jamais de table
polymorphe `desired_state_rules`.
*Rationale :* iso-F4 (l'UI d'admin existe déjà, zéro migration) ; la charge
est triviale (~0,7 req/s à 600 postes × 96 check-ins/jour) et mitigée par
D7 (jitter) + D4 (réponse conditionnelle 304). Cache par maille = optimisation
différée, sur mesure uniquement.

**D2 — Sémantique de merge par type + précédence par spécificité.**
Chaque type de ressource déclare sa sémantique de composition :
- **agrégeable** (raccourcis, imprimantes, applications…) : UNION des valeurs
  de toutes les mailles applicables ;
- **exclusif** (wallpaper…) : une seule valeur, la maille la plus spécifique
  gagne — **poste > WorkstationGroup physique > WorkstationGroup logique >
  broadcast**. Conflit au sein d'une même maille → la règle la plus récente
  gagne + warning visible dans l'UI.
*Rationale :* zéro configuration, prévisible pour un refnum non-devops (C3).
Terminologie actée : pas de notion de « parc » ni d'« étab » dans la chaîne —
le système entier EST l'établissement.

**D3 — Rapports : état courant permanent + historique de débogage temporaire.**
- Table d'état courant upsertée par (workstation, type de ressource) :
  statut conforme/dérive/erreur + hash + horodatage — volume borné
  (postes × types), « conforme » = 1 ligne [#32].
- Journal des seuls événements de changement (dérive détectée/corrigée,
  apply échoué) — rétention courte.
- **Historique complet append-only derrière flag** (`AGENT_REPORT_HISTORY`,
  défaut off) : activé pendant la phase de débogage du système, purge
  automatique (rétention N jours), retrait prévu à la sortie de débogage.

### Authentication & Security

(Décisions héritées du brainstorming, consignées ici comme référence.)

- **Auth agent = bearer token per-host Sanctum** (canal neuf — l'interdit
  iso-legacy visait les flux existants). Haché en DB, colonne sur
  `workstations`. Portée minimale : lire SON état, écrire SES rapports.
  Aucun usage de Kerberos machine (critère Keycloak).
- **D5 — Rotation : intervalle + recouvrement.** Rotation à échéance (ex.
  30 jours) au premier check-in passé l'échéance : le serveur renvoie le
  nouveau token dans la réponse ; l'ancien reste valide jusqu'au premier
  usage du nouveau (fenêtre de grâce). Résiste à la réponse perdue ;
  jamais d'expiration calendaire sèche (poste vivant après les vacances).
- **Révocation par événement** : suppression/réinstall du poste, bouton UI.
- **Anti-clonage** [#28] : détection serveur même token / MAC-hostname
  divergents → alerte + quarantaine.
- **Enrôlement — deux portes** [#23] : iPXE (token déposé à l'install,
  admin déjà authentifié) ; poste migré (agent posé par bootstrap GPO,
  sans token, **approbation un-clic dans l'UI**).
- **Frontière de confiance** [#12] + **signature de code** [#31] :
  cf. cahier des charges techno agent (step-03).

### API & Communication Patterns

**D4 — Préfixe dédié `/api/v1/agent/*`.**
- `GET  /api/v1/agent/state` — état cible compilé (3 portées), avec
  `ETag`/`If-None-Match` → **304 sans corps** si état inchangé.
- `POST /api/v1/agent/report` — rapport delta/hash.
- `POST /api/v1/agent/enroll` — porte d'enrôlement (selon le mode : iPXE
  ou demande d'approbation).
- Middleware Sanctum bearer per-host, distinct du canal
  `/api/v1/workstation-config/*` existant (JWT workstation). Frontière
  nette ancien/nouveau canal → décommissionnement par ressource lisible.
  Les routes overlay (déjà nommées `agent.v1.*`) migreront sous ce préfixe
  au moment de la bascule wallpaper.
- **Schéma versionné** : `se5.desired-state/v1` (iso-pattern overlay) ;
  l'agent refuse un major inconnu. Le booléen strict/défaut [#25] et la
  sémantique par type (D2) font partie du schéma v1.
- Format de réponse : standard SE5 (`success`, `message`, clés métier à la
  racine — cf. architecture globale).

### Frontend Architecture

Hérité de l'architecture globale (Livewire 4 SFC, convention pages/,
WithToasts, modale réutilisable). Surfaces UI propres au projet (détail en
step-06) : reporting de conformité (penser en règles, poste = exception
[#18]), toggle strict/défaut par item [#25], approbation d'enrôlement,
pilotage des rings de déploiement (D6), warnings de conflit de précédence (D2).

### Infrastructure & Deployment

**D6 — Distribution agent : SE5 HTTP + rings = WorkstationGroups.**
Binaires signés servis par SE5 (cohérent avec les assets `/os`), manifest
JSON `{version, hash, url}` ; **version cible par ring**, un ring =
WorkstationGroup (1 poste lab → 1 salle → parc entier), piloté dans l'UI.
Le bootstrap GPO figé reste le filet éternel (réinstalle un agent mort) [#27].
Le canal paquet OS (winget/apt) reste une option future quand WPKG/winget
sera dégaté — pas le chemin de référence.

**D7 — Cadence par défaut : timer 60 min + jitter ±10 %, configurable.**
Points de synchro : boot, login, timer, bouton « forcer la synchro » (UI).
Fraîcheur laxe assumée (Vérité #5 : « programmé aujourd'hui, effectif
demain ») ; aligné sur l'intervalle refresh du GPO-dispatcher (spike).

### Decision Impact Analysis

**Séquence d'implémentation induite :**
1. Contrat v1 (schéma JSON + endpoints D4) — fige strict/défaut + sémantique
   par type ;
2. Token & enrôlement (D5 + portes) — prérequis de tout appel agent ;
3. Compilateur d'état + premiers StateProviders (D1/D2) — wallpaper/overlay
   d'abord (POC existant) ;
4. Rapports (D3) + UI conformité ;
5. Distribution canari (D6) — requis avant le premier déploiement hors lab.

**Dépendances croisées :**
- D2 (sémantique par type) est déclarée DANS le schéma D4 → toute évolution
  de précédence = version de schéma.
- D1 (projection) couple le compilateur aux modèles métier → chaque bascule
  de ressource (F3) ajoute un StateProvider sans toucher au contrat.
- D6 réutilise les WorkstationGroups (D1 les lit, D6 les cible) — le ciblage
  est LE concept pivot du système.
- D3 (hash rapporté) doit utiliser le même calcul que l'ETag de D4 (un seul
  algorithme de hash d'état, partagé serveur/agent).

---

## Implementation Patterns & Consistency Rules

### Pattern Categories Defined

8 zones identifiées où des agents IA pourraient diverger d'une story à
l'autre : vocabulaire du domaine, identifiants de types de ressource,
conventions du contrat JSON, algorithme de hash, interface StateProvider,
contrat handler côté poste, logging, gestion d'erreurs du canal.

### Naming Patterns

**Vocabulaire du domaine — « Agent » partout (décision Henri) :**

| Élément | Convention | Exemple |
|---|---|---|
| Namespace serveur | `App\Services\Agent\` | `App\Services\Agent\StateCompiler` |
| Providers | `App\Services\Agent\Providers\` | `WallpaperStateProvider` |
| Controllers API | `App\Http\Controllers\Api\V1\Agent\` | `StateController`, `ReportController`, `EnrollController` |
| Config | `config/agent.php` | `agent.ttl_seconds`, `agent.token_rotation_days`, `agent.report_history` |
| Channel log | `agent` | `Log::channel('agent')` |
| Tables | préfixe `agent_` | `agent_resource_states`, `agent_report_events`, `agent_releases` |
| Colonnes workstations | préfixe `agent_` | `agent_token_hash`, `agent_token_rotated_at`, `agent_last_checkin_at` |
| Routes | nom `agent.v1.*` | `agent.v1.state`, `agent.v1.report` |
| Fixtures | `tests/Fixtures/Agent/` | golden files du contrat |

**Identifiants de types de ressource (clé de voûte — partagés serveur,
agent, schéma JSON, DB, UI) :**

- snake_case, singulier ou pluriel selon l'usage métier, **figés une fois
  publiés** : `wallpaper`, `overlay`, `shortcuts`, `printers`, `drives`,
  `associations`, `registry`, `app_config`, `applications`.
- Un identifiant publié ne se renomme JAMAIS (il vit dans les rapports,
  les tables d'état et les agents déployés) — en cas d'erreur, on déprécie
  et on ajoute.

**Code naming :** conventions Laravel/SE5 héritées (PSR-4, services par
domaine, Jobs par action, Enums dans `app/Enums/`). Sémantique de
composition = enum PHP `ResourceSemantics: Aggregate | Exclusive` ;
portées = enum `StateScope: Machine | Session | MachineUser`.

### Structure Patterns

- Compilateur + providers + token + rapports = `app/Services/Agent/`.
- **Jamais** de logique de compilation dans les controllers (couche
  Services — règle globale).
- Tests : PHPUnit `tests/Unit/Services/Agent/` + `tests/Feature/Api/V1/Agent/`
  (iso-pattern overlay existant).
- Code agent côté poste : sous le repo SE5 (emplacement exact tranché en
  step-06), jamais mélangé à `app/`.

### Format Patterns

**Contrat JSON (`se5.desired-state/v1`) — conventions héritées du payload
overlay (prouvées parsables Rainmeter + jq) :**

- Clés snake_case, structure plate, tableaux d'objets — pas de maps à clés
  dynamiques.
- Enveloppe : `schema`, `generated_at` (ISO 8601 avec timezone),
  `ttl_seconds`, puis les 3 portées : `machine`, `session`, `machine_user`.
- Chaque item d'état : `{type, semantics, mode, payload, hash}` avec
  `mode ∈ strict|default` [#25] et `semantics ∈ aggregate|exclusive` (D2).
- Évolution : champ ajouté = mineur (les agents ignorent l'inconnu) ;
  champ retiré/renommé ou sémantique changée = MAJEUR (l'agent refuse).
- Tableau vide = « rien à faire pour ce type », type absent = « type non
  géré par le serveur » — distinction significative, ne pas confondre.

**Hash d'état (un seul algorithme, partagé ETag D4 / rapports D3) :**

- SHA-256 sur le JSON **canonicalisé** : clés triées alphabétiquement,
  pas d'espaces, UTF-8, exclusion des champs volatils (`generated_at`).
- Implémenté UNE fois côté serveur (`App\Services\Agent\StateHasher`) ;
  l'agent compare des hashes opaques, il ne les recalcule jamais à partir
  de sa propre sérialisation (évite tout désaccord de canonicalisation).

**Réponses API :** format SE5 standard (`success`, `message`, clés métier à
la racine). Codes du canal agent : 200 (état), 304 (inchangé, sans corps),
401 (token invalide), 403 (poste en quarantaine/non approuvé), 409 (conflit
d'enrôlement), 422 (rapport malformé).

### Communication Patterns

**Logging (iso-convention Epic 16 `gpo.*`) :** channel `agent`, action types
namespacés : `agent.state.compiled`, `agent.state.not_modified`,
`agent.report.received`, `agent.report.drift`, `agent.enroll.requested`,
`agent.enroll.approved`, `agent.token.rotated`, `agent.token.clone_detected`,
`agent.release.promoted`. Toujours avec contexte `workstation_id` + `type`
quand applicable.

**Interface StateProvider (registry, ajout d'un type = zéro modification
du contrat) :**

```php
interface StateProvider {
    public function type(): string;                    // identifiant figé
    public function semantics(): ResourceSemantics;    // aggregate|exclusive
    public function scope(): StateScope;               // machine|session|machine_user
    public function itemsFor(TargetContext $ctx): Collection; // règles brutes par maille
}
// Le StateCompiler applique D2 (union / spécificité) — JAMAIS le provider.
```

La précédence (D2) est implémentée UNE fois dans le compilateur ; un
provider qui trie ou filtre par maille lui-même est une violation.

**Contrat handler côté agent (quel que soit l'OS/la techno) :**

- Signature conceptuelle : `test(item) → bool`, `apply(item) → Result`,
  `report() → {type, hash, status, detail?}`.
- **Idempotence obligatoire** (level-triggered [#16]) : `apply` rejouable
  sans effet de bord cumulatif.
- **Isolation** : un handler qui échoue ne bloque ni les autres handlers
  ni le rapport (statut `error` + detail, on continue).
- **Mode défaut** [#25] : si `mode=default` et que l'état réel a divergé
  par action humaine, le handler ne réapplique PAS — il rapporte
  `drifted_allowed`.
- Ordonnancement : séquentiel dans l'ordre du payload serveur [#33] —
  l'agent n'invente pas d'ordre.

### Process Patterns

**Résilience agent (parades #26/#28, identiques pour tous les handlers) :**

- Serveur injoignable → dernier état cible mis en cache local, pas de
  retry agressif (backoff expo, plafonné au timer D7).
- Le login ne dépend JAMAIS du réseau : convergence session asynchrone
  après ouverture.
- 401 → tentative avec l'ancien token si rotation en cours (D5), sinon
  arrêt + log (le re-enrôlement est humain ou bootstrap GPO, jamais
  automatique silencieux).
- 403 quarantaine → l'agent cesse de converger, continue les check-ins
  légers (le poste reste visible).

**Erreurs serveur :** exceptions métier catchées dans les Services →
loggées channel `agent` + réponse structurée (règle globale). UI admin :
WithToasts.

### Enforcement Guidelines

**Tout agent IA implémentant une story de ce projet DOIT :**

1. Passer par `StateCompiler`/`StateProvider` pour toute production d'état
   cible — jamais de requête métier directe dans un controller agent.
2. Utiliser `StateHasher` pour tout hash d'état — jamais de `md5`/`sha`
   ad hoc.
3. Déclarer tout nouveau type de ressource : identifiant figé + semantics
   + scope + provider enregistré + fixture de contrat (golden file).
4. Ne jamais introduire de dépendance AD dans le canal agent (critère
   Keycloak — vérifiable en review).
5. Mettre à jour les golden files `tests/Fixtures/Agent/` à chaque
   évolution du schéma, avec bump de version explicite.

**Anti-patterns :**

- ❌ Provider qui applique la précédence lui-même (D2 = compilateur seul)
- ❌ Table générique de règles « pour aller plus vite » (violation D1)
- ❌ Handler qui exécute au logon de façon synchrone bloquante (#26)
- ❌ Nouveau endpoint agent hors `/api/v1/agent/*` ou hors Sanctum
- ❌ Renommage d'un identifiant de type publié
- ❌ Fonctionnalité non-convergence dans l'agent (couteau-suisse #30)

---

## Project Structure & Boundaries

### Structure (delta sur le repo SE5 existant)

```
sambaedu-reload/
├── agent/                                    # NOUVEAU — code agent côté poste (top-level, comme legacy/)
│   ├── README.md                             # contrat handler, build, signature
│   ├── shared/                               # logique portable : boucle test/apply/report, parsing contrat
│   ├── windows/                              # service SYSTEM + compagnon de session [#11]
│   │   └── handlers/                         # un fichier par type de ressource
│   ├── linux/                                # adaptateur Linux (aligne le config-as-code existant) [#21]
│   │   └── handlers/
│   └── build/                                # pipeline build + signature (signe dès le 1er prototype [#31])
├── app/
│   ├── Enums/
│   │   ├── ResourceSemantics.php             # aggregate | exclusive (D2)
│   │   ├── StateScope.php                    # machine | session | machine_user
│   │   └── AgentResourceStatus.php           # compliant | drift | drifted_allowed | error
│   ├── Http/
│   │   ├── Controllers/Api/V1/Agent/
│   │   │   ├── StateController.php           # GET /api/v1/agent/state (ETag/304)
│   │   │   ├── ReportController.php          # POST /api/v1/agent/report
│   │   │   ├── EnrollController.php          # POST /api/v1/agent/enroll (2 portes #23)
│   │   │   └── ReleaseController.php         # GET manifest de mise à jour (D6)
│   │   └── Middleware/
│   │       └── AuthenticateAgentToken.php    # bearer Sanctum per-host + rotation D5 + détection clone #28
│   ├── Models/
│   │   ├── AgentResourceState.php            # état courant (workstation, type) — D3
│   │   ├── AgentReportEvent.php              # journal des changements — D3
│   │   └── AgentRelease.php                  # versions + rings — D6
│   └── Services/Agent/
│       ├── StateCompiler.php                 # résolution mailles + précédence (SEUL porteur de D2)
│       ├── StateHasher.php                   # SHA-256 canonicalisé (unique source ETag + rapports)
│       ├── TargetContext.php                 # (poste, user, appartenances WG) résolu
│       ├── Contracts/StateProvider.php       # interface (step-05)
│       ├── Providers/
│       │   ├── WallpaperStateProvider.php    # 1er provider — lit biblio + liens existants (bascule F3 n°1)
│       │   └── OverlayStateProvider.php      # lit overlay_signals (POC)
│       ├── Enrollment/
│       │   ├── EnrollmentService.php
│       │   └── TokenRotationService.php
│       ├── Reporting/
│       │   └── ReportIngestService.php       # upsert état courant + événements + flag history
│       └── Releases/
│           └── ReleaseManifestService.php    # {version, hash, url} par ring
├── config/agent.php                          # ttl_seconds, token_rotation_days, report_history, schéma
├── database/migrations/
│   └── […]_create_agent_tables.php           # une migration par feature finale (règle globale)
├── resources/views/pages/
│   ├── parc-settings/agent/                  # NOUVEAU — rings de déploiement, enrôlements en attente,
│   │   └── index.blade.php                   #   releases, toggle strict/défaut par type
│   └── parc/…                                # conformité INTÉGRÉE aux pages parc existantes
│                                             #   (détail poste = son état rapporté ; pas de page « postes » à part [#18])
├── storage/agent/releases/                   # binaires signés (non versionnés — convention storage)
└── tests/
    ├── Unit/Services/Agent/
    ├── Feature/Api/V1/Agent/
    └── Fixtures/Agent/                       # golden files contrat v1 — consommés AUSSI par agent/ (tests croisés)
```

### Architectural Boundaries

**Frontière API (la plus importante) :**
- Canal agent = `/api/v1/agent/*`, Sanctum bearer — canal NEUF.
- Canal legacy = `/gpo/*` (shim) + `/api/v1/workstation-config/*` (JWT) —
  intouchés pendant la transition, décommissionnés ressource par ressource (F3).
- Un type de ressource est servi par UN seul canal à la fois — jamais les deux.

**Frontière données :**
- Les StateProviders sont en **lecture seule** sur les tables métier
  (wallpapers, applications, shortcuts, overlay_signals…) — l'écriture
  métier reste la propriété exclusive des UIs/services existants.
- Le canal agent n'écrit QUE dans `agent_*` (+ colonnes `agent_*` de
  workstations). Aucune écriture AD (critère Keycloak).

**Frontière de confiance [#12] :**
- Serveur → agent : état signé par l'auth du canal (TLS + bearer).
- Poste : binaire + config sous ACL SYSTEM ; l'élève ne peut modifier
  ni l'agent ni son cache. Le fichier `overlay.json` local reste le seul
  artefact lisible session (modèle facade client du POC).

**Frontière agent (anti-couteau-suisse [#30]) :**
- `agent/` ne contient QUE convergence + rapport. Inventaire, remote
  control, métrologie = d'autres logiciels, d'autres dossiers.

### Requirements to Structure Mapping

| Thème brainstorming | Emplacement |
|---|---|
| A — Socle (état, compilation) | `app/Services/Agent/` + `tests/Fixtures/Agent/` |
| B — Architecture agent | `agent/` + `Middleware/AuthenticateAgentToken` + `Enrollment/` |
| C — Handlers/ressources | `Providers/` (serveur) + `agent/*/handlers/` (poste) — un PR type = 1 provider + 1 handler/OS + golden file |
| D — Transition | GPO-dispatcher figée (template serveur, hors repo applicatif) + décommissionnement par ressource dans les providers |
| E — Vision multi-OS | `agent/shared/` vs `agent/{windows,linux}/` |
| F — Garde-fous | rings (`Releases/`), anti-clone (middleware), strict/défaut (schéma + UI parc-settings/agent) |

### Integration Points

- **iPXE/WinPE** : dépose le token à l'install (porte 1) — touche les
  templates iPXE existants.
- **Bootstrap GPO** : la GPO-dispatcher figée installe/répare l'agent
  (porte 2 + filet [#27]) — dernier artefact AD, jamais ré-édité.
- **Overlay** : l'agent devient le `fetch` du POC (écrit `overlay.json`
  local) ; Rainmeter/Conky inchangés.
- **WPKG** : à terme, déclenché par l'agent (un tuyau, deux outils) —
  le moteur déclaratif n'est PAS absorbé.
- **UI Livewire** : pages existantes (règles métier) + `parc-settings/agent/`
  (plomberie) + conformité dans les pages parc.

### Data Flow

```
UI admin (écrit tables métier) ──┐
                                 ▼
poll agent ──► StateController ──► StateCompiler ◄── StateProviders (lecture métier)
   ▲                │ ETag? 304 : JSON v1 (3 portées, hash)
   │                ▼
handlers test/apply (idempotents, isolés)
   │
   └─► POST report ──► ReportIngestService ──► agent_resource_states (upsert)
                                            └► agent_report_events (+ history si flag)
                                                  ▼
                                       UI conformité (règles → exceptions)
```

### Development Workflow

- Tests serveur : PHPUnit sur VM (env hôte sans vendor) ; fixtures contrat
  partagées serveur/agent.
- ⚠️ `config/agent.php` est NOUVEAU : après création/modification, relancer
  `php artisan config:cache` (+ chown www-admin) sur la VM — le cache config
  n'est pas synchronisé par inotify.
- Build agent : `agent/build/` signe dès le premier prototype ; les binaires
  produits vont dans `storage/agent/releases/` (non versionnés), le manifest
  vit en DB (`agent_releases`).
- Démos lab fréquentes [#29] : la boucle complète (UI → état → agent →
  rapport → UI) est LA démo d'évangélisation.

---

## Architecture Validation Results

### Coherence Validation ✅

**Compatibilité des décisions :** aucune contradiction détectée.
- D1 (projection) × D4 (ETag) : exige un compilateur déterministe —
  garanti par StateHasher (canonicalisation, exclusion `generated_at`).
- D2 (sémantique par type) portée par le schéma D4 : chaque item embarque
  `semantics` → l'agent n'a pas à connaître le catalogue.
- D5 (rotation) × #28 (anti-clone) : compatibles — la fenêtre de grâce est
  bornée au premier usage du nouveau token, la détection clone reste active.
- D6 (rings WorkstationGroups) × D1 (providers lisent les WG) : le ciblage
  est un concept unique réutilisé, pas deux implémentations.
- Step-03 (techno agent reportée) × step-05 (contrat handler conceptuel) :
  cohérent — aucun pattern ne présuppose la techno.

**Cohérence avec l'architecture globale SE5 :** stack héritée respectée,
format API standard, couche Services, conventions DB Laravel, critère
Keycloak aligné avec la note stratégique long terme. Le canal agent
coexiste proprement avec catchall/shims legacy (frontière par préfixe).

### Requirements Coverage Validation ✅

**Thèmes du brainstorming (A-F) :** tous couverts —
A socle (D1/D2/D4), B agent (token/enrôlement/structure), C handlers
(providers + identifiants figés + golden files ; licences à pool [#15] =
futur type de ressource, rien à prévoir de plus aujourd'hui), D transition
(F3 + frontière par canal + bootstrap GPO), E vision (multi-OS via
agent/shared, zéro dépendance AD), F garde-fous (#25→schéma v1,
#26→résilience, #27→rings+filet, #28→middleware, #29→workflow démos,
#30→frontière agent, #31→build/, #32→D3).

**PRD global :** FR23 (GPO) à terme remplacé par ce système (cohérent avec
l'annulation 16-4) ; FR24-26 inchangés pendant la transition (WPKG conservé
comme moteur, un tuyau deux outils). NFR9 (local-first) ✓, NFR4-7
(sécurité) ✓, NFR13-15 (typage, tests) ✓ via patterns step-05.

### Implementation Readiness Validation ✅

- Décisions D1-D7 documentées avec rationale ; aucune techno nouvelle à
  versionner (tout est dans le lock SE5 existant).
- Patterns : conflits d'agents IA couverts (nommage, hash, providers,
  handlers, logs, erreurs) + enforcement + anti-patterns.
- Structure : arbre delta complet, frontières explicites, mapping
  thèmes → emplacements.

### Gap Analysis Results

**Critiques (bloquants) : aucun.**

**Importants (à traiter en story, avant le composant concerné) :**

1. **Sémantique fine du mode `default`** — distinguer « dérive humaine »
   de « jamais appliqué » exige que l'agent persiste le dernier état
   APPLIQUÉ par item (réel ≠ cible ∧ dernier-appliqué = cible → humain →
   ne pas réappliquer). À spécifier dans le contrat handler avant le
   premier handler. [#25 est LE sabotage le plus dangereux — ce gap est
   prioritaire.]
2. **Schéma du POST /report** — le contrat v1 détaille l'état cible ;
   le payload de rapport (statuts par item, erreurs, version agent) doit
   être drafté dans la story « contrat v1 » avec son golden file.
3. **Flux d'approbation porte 2** — quelles preuves le poste migré
   présente-t-il avant token (hostname + MAC + uuid SMBIOS ?) et que
   voit l'admin dans l'UI ? ⚠️ mémoire projet : l'uuid SMBIOS s'est déjà
   montré peu fiable (champ SQL vide côté iPXE) — ne pas en faire la
   preuve unique.
4. **Politiques de rétention** — N jours du journal d'événements et de
   l'historique de débogage (D3) : valeurs à fixer dans config/agent.php.

**Souhaitables (non bloquants) :**

5. Critères de promotion d'un ring canari (signaux observés, durée de
   trempage) — peut rester du jugement humain au début.
6. Procédure documentée de re-personnalisation d'un poste cloné
   (parade #28, runbook plus qu'architecture).

### Architecture Completeness Checklist

- [x] Contexte projet analysé (corpus brainstorming + brief + spikes + audits)
- [x] Contraintes C1-C4 + critère Keycloak intégrés
- [x] Décisions critiques D1-D7 tranchées et motivées
- [x] Patterns de cohérence inter-agents IA définis avec enforcement
- [x] Structure delta complète et frontières posées
- [x] Transition legacy cadrée (F3, canal unique par ressource, filet GPO)
- [x] Gaps connus listés avec leur point de résolution

### Architecture Readiness Assessment

**Statut global : PRÊT POUR L'IMPLÉMENTATION** (gaps 1-4 à résoudre dans
les premières stories, aucun ne bloque le démarrage).

**Niveau de confiance : élevé** — le modèle est validé par 3 sources
indépendantes : le brainstorming structuré (33 idées, stress-test
adversarial), le spike serveur (endpoints déjà prouvés pour Windows),
le POC overlay livré (le premier handler a déjà sa moitié serveur).

**Forces :** complexité confinée par design (handlers unitaires) ;
réutilisation massive de l'existant (WorkstationGroups, Sanctum, iPXE,
biblio wallpaper) ; transition réversible (filet GPO éternel) ;
deux décisions irréversibles (strict/défaut, versionnement schéma)
identifiées et figées dès la v1.

**Améliorations futures :** cache par maille (si mesure), JWKS-like pour
la distribution de la racine CA, canal paquet OS pour la distribution
agent (post-dégate winget).

### Implementation Handoff

**Consignes aux agents IA :** suivre ce document + l'architecture globale ;
en cas de conflit sur le périmètre agent, CE document prime. Toute
évolution du schéma v1 = golden files + bump explicite.

**Première priorité d'implémentation :** story « Contrat v1 » (schéma
state + report + golden files) — elle fige les irréversibles et résout
les gaps 1 et 2 ; puis token/enrôlement ; puis StateCompiler +
WallpaperStateProvider (la moitié serveur existe déjà).
