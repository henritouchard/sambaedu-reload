---
stepsCompleted: [step-01-validate-prerequisites, step-02-design-epics, step-03-create-stories, step-04-final-validation]
workflow_completed: true
completedAt: 2026-06-11
inputDocuments:
  - '_bmad-output/planning-artifacts/product-brief-agent-desired-state-2026-06-11.md'
  - '_bmad-output/planning-artifacts/architecture-agent-desired-state.md'
  - '_bmad-output/brainstorming/brainstorming-session-2026-06-10-1848.md'
  - '_bmad-output/planning-artifacts/epics.md (section Epic 22, absorbée)'
scope: 'Agent desired-state — successeur GPO (SE5)'
parentEpics: '_bmad-output/planning-artifacts/epics.md'
date: 2026-06-11
---

# Agent desired-state (successeur GPO) - Epic Breakdown

## Overview

Ce document fournit le découpage en epics et stories du sous-projet « Agent desired-state — successeur GPO », décomposant le product brief (qui tient lieu de PRD), l'architecture dédiée (`architecture-agent-desired-state.md`) et la session de brainstorming 2026-06-10/11 en stories implémentables.

> **Articulation avec le backlog maître (`epics.md`)** : ce découpage **absorbe l'Epic 22** existant (stories 22.1-22.3 — enum `WorkstationEnvironment`, sync nomade, tags domain-first), créé le 2026-06-09 sur la base de l'ancien brief « dispatcher statique ». L'Epic 22 du backlog maître sera marqué comme remplacé par ce document.

## Requirements Inventory

### Functional Requirements

**Contrat serveur & compilation d'état**

FR1: Le serveur expose `GET /api/v1/agent/state` qui renvoie l'état cible compilé en JSON 3 portées (`machine`, `session`, `machine_user`), fonction de (poste, user).
FR2: L'état cible est une projection pure de la DB SE5 existante via des `StateProvider` par type de ressource (D1) — aucune table générique de règles, aucune nouvelle saisie pour l'admin.
FR3: La fonction de compilation d'état (`StateCompiler`) résout transitivement les mailles de ciblage — workstationGroup(s), poste individuel, groupes de l'utilisateur, user individuel, broadcast — au moment de l'appel (iso-modèle du ciblage overlay livré).
FR4: La composition respecte la sémantique par type (D2) : **aggregate** = union des mailles applicables ; **exclusive** = la maille la plus spécifique gagne (poste > WG physique > WG logique > broadcast) ; conflit intra-maille = règle la plus récente + warning visible dans l'UI.
FR5: Le contrat JSON est versionné `se5.desired-state/v1` : enveloppe (`schema`, `generated_at`, `ttl_seconds`), items `{type, semantics, mode, payload, hash}` ; le booléen `mode ∈ strict|default` est présent dès la v1 (rétrofit impossible) ; champ ajouté = mineur, retrait/renommage = majeur (l'agent refuse un major inconnu).
FR6: `GET /state` supporte la réponse conditionnelle `ETag`/`If-None-Match` → 304 sans corps si l'état est inchangé.
FR7: Le hash d'état est calculé par un composant unique (`StateHasher`, SHA-256 sur JSON canonicalisé, exclusion des champs volatils) partagé entre ETag et rapports ; l'agent compare des hashes opaques sans jamais les recalculer.

**Rapports & conformité**

FR8: Le serveur expose `POST /api/v1/agent/report` recevant l'état réel en delta/hash (« conforme » = 1 ligne) ; le schéma du payload de rapport (statuts par item, erreurs, version agent) est à drafter dans la story « contrat v1 » avec son golden file (gap 2).
FR9: Stockage des rapports (D3) : table d'état courant upsertée par (workstation, type) avec statut `compliant|drift|drifted_allowed|error` + hash + horodatage ; journal des seuls événements de changement (rétention courte) ; historique complet append-only derrière flag `AGENT_REPORT_HISTORY` (défaut off, purge automatique).
FR10: L'UI expose la conformité **intégrée aux pages parc existantes** : penser en règles, le poste n'apparaît qu'en reporting de conformité/exception ; écarts visibles et datés sans déplacement physique.
FR11: Un bouton « forcer la synchro » dans l'UI couvre les urgences rares.

**Token & enrôlement**

FR12: Auth agent = bearer token per-host Sanctum sur canal neuf : né à l'enrôlement, haché en DB (colonnes `agent_*` sur `workstations`), portée minimale (lire SON état, écrire SES rapports), aucune dépendance AD.
FR13: Rotation du token à intervalle + recouvrement (D5) : à échéance, le serveur renvoie le nouveau token au check-in ; l'ancien reste valide jusqu'au premier usage du nouveau ; jamais d'expiration calendaire sèche.
FR14: Révocation par événement : suppression/réinstallation du poste, bouton UI.
FR15: Anti-clonage : détection serveur d'un même token avec MAC/hostname divergents → alerte + quarantaine (403 : l'agent cesse de converger mais poursuit des check-ins légers).
FR16: Deux portes d'enrôlement (`POST /api/v1/agent/enroll`) : **porte 1** poste neuf via iPXE (token déposé à l'install WinPE — seule porte du MVP) ; **porte 2** poste migré via bootstrap GPO + **approbation un-clic dans l'UI** (preuves présentées par le poste à spécifier — gap 3, l'uuid SMBIOS ne peut être la preuve unique).

**Agent côté poste**

FR17: Agent Windows = service SYSTEM (check-in au boot + timer, portée machine) + processus compagnon de session (logon, portée user) ; cache local du dernier état connu ; convergence asynchrone jamais bloquante.
FR18: Boucle générique de convergence : « pour chaque ressource : si !test → apply ; rapporter » ; handlers `test/apply/report` idempotents (level-triggered), isolés (un handler en échec ne bloque ni les autres ni le rapport), exécutés séquentiellement dans l'ordre du payload serveur.
FR19: Mode `default` par item : si l'état réel a divergé par action humaine, le handler ne réapplique PAS et rapporte `drifted_allowed` — exige la persistance du dernier état APPLIQUÉ par item côté agent (gap 1, prioritaire).
FR20: Handlers MVP : **wallpaper** (depuis la bibliothèque d'assets) et **overlay** (Rainmeter — l'agent devient le fetch du POC, écrit `overlay.json` local, Rainmeter/Conky inchangés).
FR21: Handlers palier 2, bascule du simple au dur : raccourcis → lecteurs/imprimantes → registre/associations → config d'app déclarative (policies.json) → applications (WPKG déclenché par l'agent — moteur déclaratif conservé, un tuyau deux outils).
FR22: Résilience canal : serveur injoignable → dernier état en cache, backoff exponentiel plafonné au timer ; 401 → tentative ancien token si rotation en cours, sinon arrêt + log (jamais de re-enrôlement automatique silencieux).
FR23: Cadence par défaut (D7) : timer 60 min + jitter ±10 %, configurable ; points de synchro = boot, login, timer, bouton forcer.

**Distribution & mise à jour de l'agent**

FR24: Distribution (D6) : binaires signés servis par SE5 en HTTP (`storage/agent/releases/`), manifest JSON `{version, hash, url}` en DB (`agent_releases`), version cible **par ring** = WorkstationGroup (1 poste lab → 1 salle → parc), piloté dans l'UI.
FR25: Le bootstrap GPO-dispatcher figé installe/répare l'agent sur les postes migrés et reste le filet éternel (réinstalle un agent briqué) — dernier artefact AD, jamais ré-édité.
FR26: UI `parc-settings/agent/` : rings de déploiement, enrôlements en attente, releases, toggle strict/défaut par type.

**Observabilité serveur**

FR27: Logging channel `agent` avec actions namespacées (`agent.state.compiled`, `agent.report.drift`, `agent.enroll.approved`, `agent.token.clone_detected`, `agent.release.promoted`…), contexte `workstation_id` + `type`.

**Absorbé de l'Epic 22 (environnement poste & tags domain-first)**

FR28: Enum `WorkstationEnvironment` (`shared_local` / `personal_local` / `nomade`) porté par `WorkstationGroup` (colonne Postgres), résolution par machine avec précédence `nomade` > `personal_local` > `shared_local` ; consommé par le chemin du bureau, les exclusions de redirection des profils navigateur, le gating du `clean_profiles` ; UI de sélection dans parc-settings. (ex-22.1)
FR29: Stratégie offline pour les dossiers user en mode `nomade` : accès offline + resynchronisation au retour (Folder Redirection + Offline Files recommandé), désactivation du `clean_profiles` pour ces postes. (ex-22.2)
FR30: Extinction en bloc du canal legacy au décommissionnement : générateurs/assembleurs de scripts, matching par tags `list_*`, shims `/gpo/*` et `/api/v1/workstation-config/*`. Le mécanisme de tags n'est PAS refactoré avant (ex-22.3 annulée — décision 2026-06-11 : pas d'état transitoire de déploiement, casse temporaire du canal legacy acceptée pendant le dev).

### NonFunctional Requirements

NFR1: **Login jamais bloquant** — zéro dépendance réseau à l'ouverture de session ; convergence session asynchrone après ouverture (sabotage #26).
NFR2: **Autonomie locale** — LAN seul = fonctionnel ; serveur injoignable = dernier état connu ; élimine tout cloud-first (Vérité #8).
NFR3: **Fraîcheur laxe** — pull HTTP aux points de synchro naturels, pas de push temps réel ni de convergence mid-session exigée (Vérité #5).
NFR4: **Volumétrie** — le serveur tient ~600 postes × ~96 check-ins/jour (~0,7 req/s) ; reporting delta/hash, historiser peu, agréger vite (sabotage #32).
NFR5: **Frontière de confiance** — état tiré exclusivement du serveur authentifié (TLS + bearer) ; binaire, config et cache agent sous ACL SYSTEM, non modifiables par l'élève (#12).
NFR6: **Signature de code** — pipeline de build qui signe dès le premier prototype (Authenticode) ; CA interne (racine déployée par l'install) pour lab/premiers déploiements, certificat OV public à budgéter pour la diffusion large (#31).
NFR7: **Critère Keycloak (non négociable, vérifiable en review)** — le canal agent ne crée AUCUNE nouvelle dépendance AD ; l'AD sert au bootstrap puis plus jamais ; aucune écriture AD.
NFR8: **Canal d'update = partie la plus testée** — déploiement canari systématique ; un agent briqué se réinstalle par le bootstrap GPO (#27).
NFR9: **Anti-couteau-suisse** — périmètre agent : « converger l'état, rapporter l'état » — rien d'autre ; inventaire, remote control, métrologie = un AUTRE logiciel (#30).
NFR10: **Transition** — bascule par type de ressource « du simple au dur » comme ordre de développement, SANS obligation de maintenir le canal legacy fonctionnel pendant le dev (décision 2026-06-11 : pas d'état transitoire de déploiement, casse temporaire acceptée en lab). La mise en production reste conditionnée à la **parité de compétences** : à terminaison, le mode agent couvre tout ce que le legacy savait faire (wallpapers, apps, règles ciblées selon les critères définis) ; remplacement sec, pas de cohabitation.
NFR11: **Stack héritée SE5** — Laravel, Livewire 4 SFC (convention pages/), DaisyUI, PostgreSQL, Sanctum, Spatie ; format de réponse API standard SE5 ; logique en couche Services, jamais dans les controllers.
NFR12: **Identifiants de types de ressource figés** — snake_case, jamais renommés une fois publiés (vivent dans rapports, tables, agents déployés) ; en cas d'erreur : déprécier et ajouter.
NFR13: **Testabilité du contrat** — golden files `tests/Fixtures/Agent/` consommés par les tests serveur ET agent (tests croisés) ; toute évolution du schéma = mise à jour des golden files + bump de version explicite.

### Additional Requirements

**Issues de l'architecture (structure, séquence, intégrations) :**

- Brownfield SE5, pas de starter : la fondation serveur existe (POC overlay f9b3ad9 : endpoint JSON, facade OverlayService, ciblage 4 mailles, biblio d'assets wallpaper) — le premier handler étend cet existant.
- Techno agent (Go, .NET, PowerShell…) = décision d'implémentation **reportée**, gate = PoC P2 ; cahier des charges en 7 contraintes (service SYSTEM + compagnon, artefact signable, ACL SYSTEM, auto-update fiable, cœur partageable, zéro dépendance runtime exotique, empreinte discrète) ; le PoC peut démarrer en PowerShell jetable, choix définitif requis avant tout déploiement hors lab.
- Conventions de nommage figées : namespace `App\Services\Agent\`, controllers `Api\V1\Agent\`, config `config/agent.php`, channel log `agent`, tables `agent_*`, colonnes workstations `agent_*`, routes `agent.v1.*`, fixtures `tests/Fixtures/Agent/`.
- Structure delta : dossier top-level `agent/` (shared/, windows/, linux/, build/) — jamais mélangé à `app/` ; enums `ResourceSemantics`, `StateScope`, `AgentResourceStatus` ; middleware `AuthenticateAgentToken` (rotation D5 + anti-clone) ; routes overlay existantes (`agent.v1.*`) migrent sous `/api/v1/agent/*` à la bascule wallpaper.
- Frontières : StateProviders en lecture seule sur les tables métier ; le canal agent n'écrit QUE dans `agent_*` ; canal legacy (`/gpo/*`, `/api/v1/workstation-config/*`) intouché pendant la transition.
- Séquence d'implémentation induite : 1) contrat v1 (fige les irréversibles, résout gaps 1-2) → 2) token & enrôlement → 3) StateCompiler + premiers StateProviders → 4) rapports + UI conformité → 5) distribution canari (requise avant tout hors-lab).
- Gaps identifiés à résoudre en story : (1) sémantique fine du mode `default` (persistance dernier-appliqué) — prioritaire ; (2) schéma du POST /report ; (3) preuves d'enrôlement porte 2 ; (4) politiques de rétention (valeurs dans `config/agent.php`).
- Intégrations : templates iPXE/WinPE (dépôt du token), GPO-dispatcher figée (bootstrap), POC overlay (l'agent devient le fetch), WPKG (déclenché par l'agent à terme).
- Workflow dev : `config/agent.php` nouveau → `php artisan config:cache` + chown www-admin sur la VM après chaque modification (cache non synchronisé par inotify) ; démos lab fréquentes = outil d'évangélisation (#29) ; PHPUnit sur VM.
- Anti-patterns interdits : provider qui applique la précédence, table générique de règles, handler synchrone bloquant au logon, endpoint agent hors `/api/v1/agent/*`, renommage d'identifiant publié, fonctionnalité non-convergence dans l'agent.

**Paliers métier (gates successifs, pas de dates) :**

1. Adhésion du mainteneur (brief + démo live) → 2. **Palier 1 / MVP** : boucle complète en lab (UI → état cible → agent → rapport → UI) sur wallpaper + overlay → 3. **Palier 2** : parité legacy par types de ressource sur parc réaliste (postes migrés + neufs) → 4. **Palier 3** : collège béta à parité complète → 5. Généralisation, extinction du transport GPO.

**Hors scope MVP (rappel)** : autres handlers, applications, porte 2 d'enrôlement, exposition UI du strict/défaut, push temps réel, agent Linux, certificat OV public.

### UX Design Requirements

N/A — pas de document UX dédié. Les surfaces UI (conformité dans les pages parc, `parc-settings/agent/`, approbation d'enrôlement, warnings de conflit) héritent des conventions front SE5 (Livewire 4 SFC, DaisyUI, WithToasts, modale réutilisable) définies dans l'architecture.

### FR Coverage Map

FR1: Epic 23 - GET /api/v1/agent/state, état compilé 3 portées
FR2: Epic 23 - Projection pure via StateProviders (D1)
FR3: Epic 23 - StateCompiler, résolution transitive des mailles
FR4: Epic 23 - Sémantique de merge aggregate/exclusive + précédence (D2)
FR5: Epic 23 - Schéma versionné se5.desired-state/v1, strict/défaut dès v1
FR6: Epic 23 - Réponse conditionnelle ETag/304
FR7: Epic 23 - StateHasher unique (SHA-256 canonicalisé)
FR8: Epic 24 - POST /api/v1/agent/report delta/hash (schéma drafté en Epic 23, story contrat v1 — gap 2)
FR9: Epic 24 - Stockage D3 (état courant + journal + flag history)
FR10: Epic 24 - UI conformité intégrée aux pages parc
FR11: Epic 24 - Bouton « forcer la synchro »
FR12: Epic 23 - Bearer token per-host Sanctum, portée minimale
FR13: Epic 23 - Rotation intervalle + recouvrement (D5)
FR14: Epic 23 - Révocation par événement
FR15: Epic 23 - Anti-clonage → alerte + quarantaine
FR16: Epic 23 (porte 1 iPXE) + Epic 25 (porte 2 migrés + approbation un-clic)
FR17: Epic 24 - Service SYSTEM + compagnon de session, cache local → 24.5 (SYSTEM/Go) + 24.6 (compagnon/Go)
FR18: Epic 24 - Boucle test/apply/report idempotente, isolée, séquentielle → 24.5 (moteur Go)
FR19: Epic 24 - Mode default → drifted_allowed (persistance dernier-appliqué — gap 1, spécifié au contrat en Epic 23) → 24.6 (Go)
FR20: Epic 24 - Handlers MVP wallpaper + overlay → 24.6 (Go)
FR21: Epic 27 - Handlers palier 2, bascule du simple au dur
FR22: Epic 24 - Résilience canal (backoff, 401/403) → 24.5 (Go)
FR23: Epic 24 - Cadence timer 60 min + jitter (D7) → 24.5 (Go)
FR24: Epic 25 - Distribution binaires signés + manifest + rings (D6)
FR25: Epic 25 - Bootstrap GPO figé = filet éternel
FR26: Epic 25 - UI parc-settings/agent (+ toggle strict/défaut exposé au fil de l'eau en Epic 27)
FR27: Epic 23 - Logging channel agent namespacé (transversal, étendu par chaque epic)
FR28: Epic 26 - Enum WorkstationEnvironment (ex-22.1, rescopée : consommateurs = handlers Epic 27, pas de retrofit legacy)
FR29: Epic 26 - Sync offline des nomades (ex-22.2)
FR30: Epic 27 - Extinction en bloc du canal legacy (ex-22.3 annulée)

## Epic List

### Epic 23 : État cible servi — contrat v1, compilation d'état, token & enrôlement iPXE

Le serveur SE5 sait répondre à la question que le legacy n'a jamais matérialisée : « quel est l'état cible exact de CE poste pour CE user ? ». Contrat v1 figé (golden files, strict/défaut, hash canonique), StateCompiler + premiers StateProviders (wallpaper/overlay, projection de l'existant), token Sanctum per-host (rotation, révocation, anti-clonage), porte d'enrôlement iPXE. Valeur autonome : l'état compilé est consultable et diagnosticable (curl/jq, iso-POC overlay) avant même qu'un agent existe ; les irréversibles sont figés ; les gaps 1, 2 et 4 de l'architecture sont résolus ici.
**FRs covered:** FR1-FR7, FR12-FR15, FR16 (porte 1), FR27

### Epic 24 : La boucle fermée en lab — agent de convergence MVP (gate palier 1)

L'admin change un wallpaper dans l'UI, le poste de lab converge, le rapport remonte, l'écart se voit. Agent Windows squelette (service SYSTEM + compagnon de session, cache local, login jamais bloquant), boucle test/apply/report idempotente, handlers wallpaper + overlay, POST /report + stockage D3, vue de conformité minimale dans les pages parc, bouton « forcer la synchro ». Critère de complétude = la démo live répétable (UI → état → agent → rapport → UI) — le MVP du brief et l'outil d'évangélisation du mainteneur.
**FRs covered:** FR8-FR11, FR17-FR20, FR22-FR23

### Epic 25 : Gestion de flotte — distribution canari, bootstrap GPO, porte des postes migrés

On passe d'UN poste de lab à UN PARC : binaires signés servis par SE5, manifest {version, hash, url}, rings = WorkstationGroups pilotés dans l'UI (1 poste → 1 salle → parc), GPO-dispatcher figée comme bootstrap/filet éternel, enrôlement des postes migrés avec approbation un-clic (preuves — gap 3 résolu ici), UI parc-settings/agent (rings, enrôlements en attente, releases). Prérequis de tout déploiement hors lab (NFR8).
**FRs covered:** FR16 (porte 2), FR24-FR26

### Epic 26 : Environnement de poste — donnée du domaine pour les handlers (ex-Epic 22 absorbé, rescopé)

La nature du poste (partagé / personnel / nomade) devient une donnée du domaine Postgres, pilotable par parc : enum WorkstationEnvironment + service de résolution, stratégie offline/resync des nomades. Rescopé 2026-06-11 : plus aucun retrofit dans le canal legacy (le pansement Bug C n'est pas rafistolé — le fix définitif arrive avec le handler raccourcis de l'Epic 27) ; l'ex-22.3 (tags domain-first) est annulée, le mécanisme de tags s'éteint en bloc avec le canal legacy. Parallélisable, prérequis du handler raccourcis de l'Epic 27.
**FRs covered:** FR28-FR29

### Epic 27 : Parité de compétences — la bascule des ressources, du simple au dur (gate paliers 2-3)

Chaque type de ressource passe au canal agent, dans l'ordre de développement « du simple au dur » : raccourcis → lecteurs/imprimantes → registre/associations → config d'app déclarative (policies.json) → applications (WPKG déclenché par l'agent — moteur conservé). Exposition UI du strict/défaut au fil des handlers concernés. Le canal legacy n'est PAS maintenu pendant le dev ; à parité de compétences atteinte, il est éteint en bloc (générateurs, tags, shims). Condition de la mise en production et du collège béta.
**FRs covered:** FR21, FR26 (toggle strict/défaut au fil de l'eau), FR30 (extinction)

**Dépendances :** 23 → 24 → 25 → 27 ; 26 parallélisable (doit précéder le handler raccourcis de 27).

## Epic 23 : État cible servi — contrat v1, compilation d'état, token & enrôlement iPXE

Le serveur SE5 sait répondre à la question que le legacy n'a jamais matérialisée : « quel est l'état cible exact de CE poste pour CE user ? ». Les irréversibles (schéma v1, strict/défaut, hash) sont figés ici ; les gaps 1, 2 et 4 de l'architecture y sont résolus.

### Story 23.1 : Contrat v1 figé — schémas state & report, StateHasher, golden files

En tant que mainteneur SambaEdu,
je veux un contrat JSON `se5.desired-state/v1` versionné, hashé canoniquement et figé par golden files,
afin que serveur et agents (présents et futurs) évoluent sans jamais se désynchroniser.

**Acceptance Criteria:**

**Given** le schéma `se5.desired-state/v1`
**When** le serveur sérialise un état cible
**Then** l'enveloppe contient `schema`, `generated_at` (ISO 8601 + timezone), `ttl_seconds` et les trois portées `machine`, `session`, `machine_user`
**And** chaque item porte `{type, semantics, mode, payload, hash}` avec `mode ∈ strict|default` et `semantics ∈ aggregate|exclusive` ; tableau vide (« rien à faire ») et type absent (« type non géré ») sont distingués et documentés.

**Given** deux compilations du même état à des instants différents
**When** `StateHasher` calcule le hash (SHA-256 sur JSON canonicalisé : clés triées, UTF-8, sans espaces, champs volatils exclus)
**Then** les hashes sont identiques
**And** l'algorithme n'existe qu'en un seul endroit (`App\Services\Agent\StateHasher`), réutilisé par l'ETag et les rapports.

**Given** le gap 2 (schéma du rapport)
**When** le contrat v1 est rédigé
**Then** le payload de `POST /report` est spécifié : statut par item (`compliant|drift|drifted_allowed|error`), hash, détail d'erreur, version de l'agent — avec son golden file.

**Given** le gap 1 (sémantique fine du mode `default`)
**When** le contrat handler est spécifié
**Then** la règle est écrite au contrat : l'agent persiste le dernier état APPLIQUÉ par item ; si réel ≠ cible ∧ dernier-appliqué = cible → dérive humaine → ne pas réappliquer, rapporter `drifted_allowed`.

**Given** les tests
**Then** les golden files `tests/Fixtures/Agent/` (state + report) existent et sont consommés par PHPUnit
**And** la règle d'évolution est documentée : champ ajouté = mineur, retrait/renommage = majeur + bump explicite des golden files.

### Story 23.2 : Cycle de vie du token agent — auth, rotation, révocation, anti-clonage

En tant qu'admin d'établissement,
je veux que chaque poste s'authentifie sur le canal agent avec son propre token à portée minimale,
afin que le canal soit sûr sans créer de nouvelle dépendance AD.

**Acceptance Criteria:**

**Given** un poste enrôlé (token Sanctum haché en DB, colonnes `agent_token_hash`, `agent_token_rotated_at`, `agent_last_checkin_at` sur `workstations`)
**When** il appelle `/api/v1/agent/*` avec son bearer
**Then** le middleware `AuthenticateAgentToken` l'authentifie et résout SON workstation
**And** la portée se limite à lire SON état et écrire SES rapports.

**Given** un token invalide ou révoqué
**Then** 401.

**Given** un poste en quarantaine ou non approuvé
**Then** 403.

**Given** un token passé l'échéance de rotation (`agent.token_rotation_days`)
**When** le poste fait son check-in
**Then** le serveur renvoie le nouveau token dans la réponse, l'ancien restant valide jusqu'au premier usage du nouveau (fenêtre de grâce, résiste à la réponse perdue)
**And** aucune expiration calendaire sèche n'existe (un poste vivant après les vacances se rotate, ne meurt pas).

**Given** un même token présenté avec MAC/hostname divergents
**Then** alerte + mise en quarantaine du poste + log `agent.token.clone_detected`.

**Given** la suppression ou réinstallation d'un poste, ou le bouton de révocation UI
**Then** le token est révoqué immédiatement (event-driven).

**And** aucun appel AD dans tout le flux (critère Keycloak, vérifiable en review) ; toutes les transitions loggées channel `agent`.

### Story 23.3 : Enrôlement porte 1 — le token naît à l'install iPXE

En tant qu'admin d'établissement,
je veux que les postes installés par la chaîne iPXE soient enrôlés automatiquement,
afin qu'aucune action manuelle ne soit nécessaire pour les postes neufs.

**Acceptance Criteria:**

**Given** un poste créé ou réinstallé via la chaîne iPXE (admin déjà authentifié au menu)
**When** l'installation se déroule
**Then** un token naît à l'enrôlement côté serveur (haché en DB) et est déposé sur le poste à l'install WinPE, sous emplacement à ACL SYSTEM (consommé par l'agent en Epic 24).

**Given** la réinstallation d'un poste déjà enrôlé
**Then** l'ancien token est révoqué et remplacé (la réinstall = événement de révocation de 23.2).

**Given** une demande d'enrôlement en conflit (poste déjà enrôlé et actif)
**Then** réponse 409, rien n'est écrasé silencieusement.

**And** les templates iPXE/WinPE modifiés émettent des URLs absolues (piège connu des URLs relatives), ne persistent aucun credential en clair, et les événements sont loggés `agent.enroll.*`.

### Story 23.4 : StateCompiler — résolution des mailles, précédence, premiers StateProviders

En tant qu'admin d'établissement,
je veux que l'état cible d'un (poste, user) soit calculé depuis mes règles existantes (salles, parcs, bibliothèque wallpaper, signaux overlay),
afin de ne rien ressaisir : mes écrans d'administration actuels SONT la source.

**Acceptance Criteria:**

**Given** l'interface `StateProvider` (`type()`, `semantics()`, `scope()`, `itemsFor(TargetContext)`) et son registry
**When** un nouveau provider s'enregistre
**Then** un nouveau type de ressource est servi sans AUCUNE modification du compilateur ni du contrat.

**Given** un poste avec ses appartenances (WG logiques/physiques), ses règles propres, et un user avec ses groupes
**When** `StateCompiler` compile
**Then** les mailles sont résolues transitivement (workstationGroups, poste, groupes user, user, broadcast)
**And** type `aggregate` = union des mailles ; type `exclusive` = la maille la plus spécifique gagne (poste > WG physique > WG logique > broadcast).

**Given** deux règles en conflit dans la même maille sur un type exclusif
**Then** la plus récente gagne et un warning est émis (loggé, exposable UI).

**Given** les providers `WallpaperStateProvider` et `OverlayStateProvider`
**Then** ils lisent en **lecture seule** les tables métier existantes (biblio + liens parc, `overlay_signals`)
**And** aucun provider n'applique lui-même la précédence (anti-pattern bloquant en review — D2 = compilateur seul).

**And** tests unitaires `tests/Unit/Services/Agent/` couvrant union, spécificité, conflit, et le cas « aucune règle » (tableau vide ≠ type absent).

### Story 23.5 : GET /api/v1/agent/state — l'état cible servi, ETag/304, config agent

En tant que mainteneur (et bientôt l'agent),
je veux tirer l'état cible compilé d'un poste authentifié, avec réponse conditionnelle,
afin de diagnostiquer l'état attendu de n'importe quel poste et permettre des check-ins légers.

**Acceptance Criteria:**

**Given** un poste authentifié (23.2)
**When** `GET /api/v1/agent/state`
**Then** 200 avec le JSON v1 complet (3 portées, conforme au golden file de 23.1) et un header `ETag` = hash `StateHasher`.

**Given** un `If-None-Match` égal à l'ETag courant
**Then** 304 sans corps, log `agent.state.not_modified`.

**Given** un check-in machine sans session ouverte (service SYSTEM au boot)
**When** l'appel ne porte pas de user
**Then** la portée `machine` est servie, `session`/`machine_user` vides — sans erreur.

**Given** `config/agent.php` (nouveau)
**Then** il porte `ttl_seconds`, `token_rotation_days`, `report_history` et les rétentions (gap 4 résolu : valeurs fixées)
**And** la doc de story rappelle le `php artisan config:cache` + chown www-admin sur la VM.

**And** feature tests `tests/Feature/Api/V1/Agent/` : 200/304/401/403, contrat validé contre le golden file.

## Epic 24 : La boucle fermée en lab — agent de convergence MVP (gate palier 1)

L'admin change un wallpaper dans l'UI, le poste de lab converge, le rapport remonte, l'écart se voit. **Critère de complétude de l'epic = la démo live répétable** (UI → état → agent → rapport → UI) — le MVP du brief.

> **Note bascule Go (course-correction 2026-06-12)** : le prototype PowerShell
> (24.2/24.3/24.4, superseded) a validé la boucle et le modèle 2-process.
> L'agent de production est en **Go** : core+service SYSTEM (24.5), compagnon+
> handlers (24.6). La démo palier 1 (24.7) est jouée sur le binaire Go signé —
> frontière de confiance et signature réelles. Aucune double implémentation
> conservée (pas d'état transitoire).

### Story 24.1 : POST /api/v1/agent/report — ingestion et stockage des rapports

En tant qu'admin d'établissement,
je veux que les rapports d'état des postes soient ingérés et stockés en volume borné,
afin que la conformité du parc soit connue du serveur sans le noyer.

**Acceptance Criteria:**

**Given** un poste authentifié envoyant un rapport conforme au golden file de 23.1
**When** `POST /api/v1/agent/report`
**Then** `ReportIngestService` upserte `agent_resource_states` par (workstation, type) : statut `compliant|drift|drifted_allowed|error` + hash + horodatage — « conforme » = 1 ligne, le volume reste borné (postes × types)
**And** `agent_last_checkin_at` est mis à jour.

**Given** un changement d'état (dérive détectée/corrigée, apply échoué)
**Then** un événement est journalisé dans `agent_report_events` (rétention courte) ; un rapport identique au précédent ne crée AUCUN événement.

**Given** le flag `AGENT_REPORT_HISTORY` activé (défaut off)
**Then** l'historique complet append-only est conservé avec purge automatique selon la rétention de `config/agent.php`.

**Given** un rapport malformé
**Then** 422 avec détail, rien n'est écrit
**And** le hash rapporté est comparé via le même `StateHasher` que l'ETag (jamais de recalcul ad hoc) ; logs `agent.report.received` / `agent.report.drift`.

### Story 24.2 : Agent squelette Windows — service SYSTEM, check-in, cache, build signé

En tant que mainteneur SambaEdu,
je veux un agent Windows minimal qui check-in (boot + timer), met en cache l'état cible et rapporte,
afin de valider la boucle réseau complète avant tout handler.

**Acceptance Criteria:**

**Given** un poste de lab enrôlé (token de 23.3)
**When** le service SYSTEM démarre (boot) puis à chaque timer (60 min + jitter ±10 %, configurable)
**Then** il appelle `GET /state` avec `If-None-Match`, met en cache local le dernier état connu (fichiers sous ACL SYSTEM), et envoie son rapport.

**Given** le serveur injoignable
**Then** l'agent fonctionne sur son dernier état en cache, retry en backoff exponentiel plafonné au timer — jamais de retry agressif.

**Given** un 401 pendant une rotation
**Then** tentative avec l'ancien token ; sinon arrêt + log local (jamais de re-enrôlement automatique silencieux).

**Given** un 403 quarantaine
**Then** l'agent cesse de converger mais poursuit des check-ins légers.

**Given** la structure repo
**Then** le code vit sous `agent/` top-level (`shared/` = boucle + parsing contrat, `windows/`, `build/`) — jamais dans `app/`
**And** `agent/build/` produit un artefact **signé dès ce premier prototype** (CA interne, racine déployée par l'install).

**Given** la décision techno (gate PoC P2)
**Then** [Story 24.2 requalifiée — spike de dérisquage PowerShell, superseded par 24.5
(Go). Le démarrage PowerShell a validé la boucle réseau ; le choix définitif
est acté : **Go** (mémoire project_agent_runtime_go, 2026-06-11), implémenté
en 24.5/24.6. Le `.ps1` est retiré à la bascule Go.]

### Story 24.3 : Compagnon de session — portée user, login jamais bloquant

En tant que prof ou élève,
je veux que ma session s'ouvre instantanément, réseau ou pas,
afin que la convergence ne se fasse jamais sentir.

**Acceptance Criteria:**

**Given** un logon utilisateur
**When** le compagnon de session démarre
**Then** l'ouverture de session n'attend RIEN du réseau : la convergence des portées `session`/`machine_user` démarre de façon asynchrone après ouverture.

**Given** le serveur injoignable au logon
**Then** la session s'ouvre normalement sur le dernier état en cache — temps d'ouverture identique avec et sans serveur joignable (mesuré, critère KPI du brief).

**Given** le check-in du compagnon
**Then** il appelle `GET /state` avec le user de la session, reçoit les 3 portées et ne traite que `session` + `machine_user` ; la portée `machine` reste au service SYSTEM.

**And** le compagnon tourne avec les droits de la session (pas SYSTEM) ; il ne peut pas modifier les fichiers de l'agent (frontière de confiance).

### Story 24.4 : Handlers wallpaper + overlay — la convergence devient réelle

En tant qu'admin d'établissement,
je veux que le fond d'écran et l'overlay d'identité convergent sur le poste depuis mes règles UI,
afin de voir le modèle fonctionner sur les premières ressources réelles.

**Acceptance Criteria:**

**Given** l'état cible contenant un item `wallpaper` (biblio d'assets, maille résolue)
**When** la boucle exécute `test` puis `apply` si écart
**Then** le fond d'écran du poste correspond à l'asset cible, `apply` est idempotent (rejouable sans effet cumulatif), et le statut est rapporté.

**Given** l'item `overlay`
**When** le handler s'exécute
**Then** l'agent écrit `overlay.json` local (il DEVIENT le fetch du POC) — Rainmeter/Conky inchangés, l'overlay affiche identité user + parc.

**Given** un item en mode `default` dont l'état réel a été modifié par un humain (réel ≠ cible ∧ dernier-appliqué = cible)
**Then** le handler ne réapplique PAS et rapporte `drifted_allowed` (persistance du dernier état appliqué par item, contrat 23.1).

**Given** un handler qui échoue
**Then** statut `error` + détail rapportés, les autres handlers et le rapport continuent (isolation)
**And** l'exécution est séquentielle dans l'ordre du payload serveur.

### Story 24.5 : Agent Go — core de convergence, service SYSTEM, build signé

En tant que mainteneur SambaEdu,
je veux le cœur de l'agent réécrit en Go (boucle, hash, cache, résilience, build signé) tournant en service SYSTEM,
afin de disposer de l'artefact de production réel, signable et déployable, en lieu et place du prototype PowerShell.

**Acceptance Criteria:**

**Given** le repo `agent/`
**Then** le code Go vit sous `agent/` (`shared/` = boucle test/apply/report + parsing du contrat + `StateHasher` Go ; `windows/` = service SYSTEM), jamais dans `app/`
**And** les `.ps1` de 24.2 (squelette, compagnon) sont **retirés** une fois leur équivalent Go vert — aucune double implémentation au repo (l'historique git garde la trace).

**Given** un poste de lab enrôlé (token de 23.3)
**When** le service SYSTEM Go démarre (boot) puis à chaque timer (60 min + jitter ±10 %, configurable)
**Then** il appelle `GET /state` avec `If-None-Match`, met en cache local le dernier état connu (fichiers sous ACL SYSTEM), envoie son rapport (portée machine).

**Given** `StateHasher`
**Then** l'agent Go reproduit l'algorithme de 23.1 **à l'identique** (ksort SORT_STRING récursif, NFC, zéro float, `generated_at` exclu) et le valide contre les golden files `tests/Fixtures/Agent/` — tests croisés serveur/agent ; l'agent compare des hashes opaques, il ne recalcule jamais depuis sa propre sérialisation.

**Given** le serveur injoignable
**Then** l'agent fonctionne sur son dernier état en cache, backoff exponentiel plafonné au timer.

**Given** un 401 pendant une rotation
**Then** tentative avec l'ancien token ; sinon arrêt + log local (jamais de re-enrôlement silencieux).
**Given** un 403 quarantaine
**Then** l'agent cesse de converger mais poursuit des check-ins légers.

**Given** le build
**Then** `agent/build/` produit un **binaire Go statique unique**, cross-compilé Windows, **signé Authenticode** (CA interne, racine déployée par l'install), zéro dépendance runtime exotique (NFR6)
**And** `agent/README.md` documente la décision **Go** contre le cahier des **7 contraintes** (service SYSTEM + compagnon, artefact signable, ACL SYSTEM, auto-update fiable, cœur partageable, zéro dépendance exotique, empreinte discrète) — gate techno (ex-PoC P2) **résolu**.

### Story 24.6 : Agent Go — compagnon de session, handlers wallpaper/overlay, parité démo

En tant qu'admin d'établissement (et prof/élève côté session),
je veux le compagnon de session et les handlers wallpaper/overlay en Go, convergents et idempotents,
afin que la boucle complète tourne sur le binaire de production et que la démo palier 1 soit jouée pour de vrai.

**Acceptance Criteria:**

**Given** un logon utilisateur
**When** le compagnon de session Go démarre
**Then** l'ouverture de session n'attend RIEN du réseau (convergence `session`/`machine_user` asynchrone après ouverture) ; le compagnon tourne aux droits de la session (pas SYSTEM) et ne peut pas modifier les fichiers de l'agent (frontière de confiance) ; le `.ps1` du compagnon de 24.3 est retiré une fois ce handler vert.

**Given** l'état cible contient un item `wallpaper` (biblio d'assets, maille résolue)
**When** la boucle exécute `test` puis `apply` si écart
**Then** le fond d'écran correspond à l'asset cible (`SystemParametersInfo` via FFI Win32, ou shell-out PowerShell documenté si un cas le justifie), `apply` idempotent, statut rapporté.

**Given** l'item `overlay`
**When** le handler s'exécute
**Then** l'agent Go écrit `overlay.json` local (il DEVIENT le fetch du POC) — Rainmeter/Conky inchangés, l'overlay affiche identité user + parc.

**Given** un item en mode `default` dont l'état réel a été modifié par un humain (réel ≠ cible ∧ dernier-appliqué = cible)
**Then** le handler ne réapplique PAS et rapporte `drifted_allowed` (persistance du dernier état appliqué par item, contrat 23.1).

**Given** un handler qui échoue
**Then** statut `error` + détail rapportés, les autres handlers et le rapport continuent (isolation), exécution séquentielle dans l'ordre du payload serveur.

**Given** l'agent Go complet (24.5 + 24.6)
**Then** la convergence est démontrable de bout en bout (changer le wallpaper d'un parc dans l'UI → le poste de lab converge → le rapport remonte, vérifiable curl/jq iso-Epic 23) — le bouclage visuel UI complet est scellé par 24.7 (gate palier 1).

### Story 24.7 : Conformité visible — l'état rapporté dans les pages parc + forcer la synchro

En tant qu'admin d'établissement,
je veux voir l'état rapporté de mes postes là où je gère déjà mon parc, et pouvoir forcer une synchro,
afin de détecter un écart sans me déplacer — et fermer la boucle de la démo.

**Acceptance Criteria:**

**Given** des rapports ingérés (24.1)
**When** je consulte une page parc existante
**Then** la conformité est visible par règle (penser en règles : le poste n'apparaît qu'en exception), les écarts sont datés, le détail d'un poste montre son état rapporté par type — pas de page « postes » à part.

**Given** un écart `drift` corrigé au passage suivant de l'agent
**Then** la vue reflète le retour à `compliant` sans action de ma part.

**Given** le bouton « forcer la synchro » (parc ou poste)
**When** je clique
**Then** le poste converge avant son prochain cycle naturel — le mécanisme reste du pull (pas de push temps réel, NFR3), il est choisi et documenté dans la story
**And** UI en Livewire SFC, notifications via WithToasts.

**Given** l'epic complet
**Then** la démo live est répétable : changer le wallpaper d'un parc dans l'UI → le poste de lab converge → le rapport remonte → l'écart se voit puis se résorbe (gate palier 1).

## Epic 25 : Gestion de flotte — distribution canari, bootstrap GPO, porte des postes migrés

On passe d'UN poste de lab à UN PARC : releases par rings, auto-update, « la dernière GPO de l'histoire » comme bootstrap/filet, enrôlement des postes migrés. Prérequis de tout déploiement hors lab (NFR8). Gap 3 résolu ici.

### Story 25.1 : Releases serveur — binaires signés, manifest, rings = WorkstationGroups

> **Dépendance amont** : le binaire signé déposé dans storage/agent/releases/
> est produit par les stories 24.5 (build signé Authenticode) + 24.6 (binaire
> complet). 25.1 le distribue, ne le crée pas.

En tant que mainteneur SambaEdu,
je veux publier des versions de l'agent ciblées par ring,
afin qu'une release atteigne 1 poste de lab avant 1 salle avant le parc.

**Acceptance Criteria:**

**Given** un binaire signé déposé dans `storage/agent/releases/` (non versionné, convention storage)
**When** une release est créée (`agent_releases` : version, hash, url)
**Then** `GET` du manifest (ReleaseController, authentifié agent) renvoie `{version, hash, url}` **résolu selon le ring du poste** — un ring = un WorkstationGroup, version cible par ring.

**Given** un poste n'appartenant à aucun ring
**Then** il reçoit la version stable par défaut (jamais une canari par accident).

**Given** un binaire dont le hash ne correspond pas au manifest
**Then** la release est refusée à la création — impossible de publier un artefact incohérent
**And** logs `agent.release.*` ; aucune écriture hors `agent_*`.

### Story 25.2 : Auto-update de l'agent — le canal le plus testé

En tant que mainteneur SambaEdu,
je veux que l'agent se mette à jour seul depuis le manifest, sans jamais briquer le parc,
afin que les évolutions se déploient sans tournée des salles.

**Acceptance Criteria:**

**Given** un manifest annonçant une version plus récente que celle de l'agent
**When** le check-in la détecte
**Then** l'agent télécharge le binaire, **vérifie hash + signature avant toute exécution**, se remplace et redémarre proprement ; un téléchargement corrompu est jeté sans installation.

**Given** un update qui échoue à mi-chemin
**Then** l'agent en place continue de fonctionner (jamais d'état « ni ancien ni nouveau ») et l'échec est rapporté au serveur.

**Given** la version de l'agent
**Then** elle figure dans chaque rapport (contrat 23.1) — le serveur voit la progression du déploiement par ring
**And** ce chemin d'update est couvert par les tests les plus complets de l'agent (NFR8 : c'est LA partie la plus testée).

### Story 25.3 : Porte 2 — enrôlement des postes migrés avec approbation un-clic

En tant qu'admin d'établissement,
je veux approuver d'un clic les postes migrés qui demandent à rejoindre le système,
afin d'enrôler l'existant sans réinstallation et sans usurpation possible.

**Acceptance Criteria:**

**Given** un agent posé sur un poste migré, sans token
**When** `POST /enroll` (mode demande d'approbation) avec ses preuves d'identité — hostname + MAC + uuid SMBIOS, **aucune preuve n'étant suffisante seule** (gap 3 : l'uuid SMBIOS s'est déjà montré peu fiable) ; le faisceau retenu est documenté
**Then** une demande en attente est créée, visible dans l'UI ; le poste reste 403 (non approuvé) et check-in légèrement en attendant.

**Given** la demande visible dans l'UI (preuves affichées, rapprochement avec le poste connu en DB le cas échéant)
**When** j'approuve d'un clic
**Then** le token naît (cycle 23.2), le poste le reçoit à son prochain check-in et commence à converger.

**Given** une campagne de passage à SE5 (mode « approbation automatique » activé par l'admin, borné dans le temps ou désactivable)
**When** une demande d'enrôlement arrive dont les preuves **concordent avec un poste déjà connu en DB** (importé de l'AD/legacy : hostname + MAC cohérents)
**Then** elle est approuvée automatiquement, le token naît sans clic, et l'approbation auto est loggée distinctement (`agent.enroll.auto_approved`)
**And** toute demande dont les preuves divergent du poste connu (ou poste inconnu en DB) reste en approbation **manuelle**, même en mode campagne — l'anti-usurpation ne se débraye jamais.

**Given** une demande douteuse (preuves incohérentes avec le poste connu)
**Then** je peux la rejeter ; le rejet est loggé et le poste reste hors système
**And** logs `agent.enroll.requested` / `agent.enroll.approved` ; UI Livewire SFC + WithToasts + modale réutilisable.

### Story 25.4 : Les deux chemins d'installation — GPO-dispatcher figée (bootstrap + filet) et dépôt iPXE

En tant que mainteneur SambaEdu,
je veux que « la dernière GPO de l'histoire » installe et répare l'agent sur les postes migrés, et que la chaîne iPXE le dépose sur les postes neufs,
afin que tout poste du parc, quel que soit son passé, finisse avec un agent vivant.

**Acceptance Criteria:**

**Given** un poste migré joint au domaine, sans agent
**When** la GPO-dispatcher figée s'exécute (boot/refresh)
**Then** elle installe l'agent depuis SE5 (binaire signé de 25.1) puis se tait — c'est le **dernier artefact AD, jamais ré-édité ensuite** (toute évolution passe par l'auto-update 25.2).

**Given** un agent briqué ou supprimé sur n'importe quel poste
**Then** la même GPO le réinstalle au passage suivant — le filet éternel (#27).

**Given** un poste neuf installé par la chaîne iPXE/WinPE
**Then** l'install dépose le binaire de l'agent (dernière version stable servie par SE5) en plus du token de 23.3 — un poste neuf n'a jamais besoin de la GPO.

**And** la racine de la CA interne est déployée par les deux chemins (prérequis signature) ; la GPO ne contient aucune logique métier (dispatcher générique : event → install/réparation, rien d'autre).

### Story 25.5 : UI parc-settings/agent — rings, enrôlements en attente, releases

En tant qu'admin d'établissement (et mainteneur en lab),
je veux piloter les rings de déploiement, les demandes d'enrôlement et les releases depuis une page dédiée,
afin de gérer la plomberie de la flotte sans toucher ni CLI ni GPO.

**Acceptance Criteria:**

**Given** la page `parc-settings/agent/` (convention pages/, Livewire SFC)
**When** je la consulte
**Then** je vois : les releases et leur version par ring, les enrôlements en attente (lien vers l'approbation 25.3), et l'état de progression du déploiement (versions rapportées par les agents).

**Given** une release canari validée sur son ring
**When** je la promeus au ring suivant (1 poste → 1 salle → parc)
**Then** le manifest la sert au nouveau ring au prochain check-in ; la promotion est loggée `agent.release.promoted`.

**Given** un déploiement qui se passe mal
**Then** je peux re-cibler un ring sur la version stable précédente (le manifest fait foi, les agents reconvergent)
**And** WithToasts pour les retours d'action ; les critères de promotion restent du jugement humain (architecture : gap « souhaitable » n° 5).

## Epic 26 : Environnement de poste — donnée du domaine pour les handlers (ex-Epic 22 absorbé, rescopé)

La nature du poste devient une donnée du domaine Postgres, consommée par les handlers de l'Epic 27. Rescopé 2026-06-11 : aucun retrofit dans le canal legacy ; ex-22.3 annulée (les tags `list_*` s'éteignent en bloc avec le canal legacy, FR30 → Epic 27).

### Story 26.1 : Enum `WorkstationEnvironment` — la nature du poste devient une donnée du domaine

En tant qu'admin d'établissement,
je veux déclarer par parc si les postes sont partagés, personnels ou nomades,
afin que les handlers (bureau, profils navigateur, clean_profiles) adaptent leur comportement à la nature du poste.

**Acceptance Criteria:**

**Given** l'enum `App\Enums\WorkstationEnvironment` (`shared_local` / `personal_local` / `nomade`) portée par une colonne Postgres sur `WorkstationGroup` (applicable groupe logique OU physique — résolu côté serveur)
**When** un service de domaine résout l'environnement d'une machine appartenant à plusieurs groupes
**Then** la précédence est `nomade` > `personal_local` > `shared_local`, défaut `shared_local`
**And** la résolution lit Postgres, jamais l'AD ; le service est consommable par les StateProviders de l'Epic 27.

**Given** la sémantique des trois valeurs
**Then** elle est documentée : `shared_local` = poste partagé (bureau réseau, profils redirigés) ; `personal_local` = modèle perdir (bureau local, données sur le home réseau) ; `nomade` = tout local avec sync (26.2).

**Given** la page parc-settings
**Then** l'admin sélectionne l'environnement du parc dans l'UI (Livewire SFC, WithToasts)
**And** tests couvrant la précédence multi-groupes et le défaut.

**Note de transition :** AUCUN retrofit dans le canal legacy (`ApplicationScriptsGenerator`, `ShortcutCompilerService`, pansement `4e5a152` intouchés — ils meurent avec le canal). Le Bug C est corrigé définitivement par le handler raccourcis (Epic 27), qui consommera ce service.

### Story 26.2 : Mode nomade — données accessibles offline et resynchronisées au retour

En tant qu'utilisateur d'un portable nomade,
je veux accéder à mes fichiers hors établissement et les retrouver synchronisés au retour,
afin que mes données ne soient jamais prisonnières du poste.

**Acceptance Criteria:**

**Given** un poste en environnement `nomade`
**When** la stratégie offline est appliquée aux dossiers user
**Then** la source de vérité reste le serveur (home SambaEdu) avec cache local et resynchronisation au retour — **Folder Redirection + Offline Files (CSC)** recommandé ; alternative rclone/robocopy documentée si CSC disqualifié à l'implémentation.

**Given** un poste nomade hors établissement (serveur injoignable)
**Then** l'utilisateur accède à ses dossiers en local sans erreur.

**Given** le retour sur le LAN
**Then** les modifications locales remontent au serveur ; les conflits suivent le comportement documenté de la stratégie retenue.

**Given** un poste `nomade`
**Then** le `clean_profiles` est désactivé pour ces postes (sinon perte des données en attente de sync).

## Epic 27 : Parité de compétences — la bascule des ressources, du simple au dur (gate paliers 2-3)

Chaque type de ressource passe au canal agent dans l'ordre « du simple au dur ». Pattern par story : 1 StateProvider + 1 handler + identifiant de type figé + golden file. Le canal legacy n'est pas maintenu pendant le dev ; il meurt en bloc en 27.6.

### Story 27.1 : Handler raccourcis — le bureau converge selon la nature du poste

En tant qu'admin d'établissement,
je veux que les raccourcis du bureau convergent selon mes règles et la nature du poste,
afin que le Bug C soit corrigé définitivement, par le bon modèle.

**Acceptance Criteria:**

**Given** le type `shortcuts` (identifiant figé, `semantics=aggregate`, portée session) avec son `ShortcutsStateProvider` (lecture seule des règles existantes) et son golden file
**When** l'agent converge
**Then** les raccourcis cibles sont présents au chemin dicté par `WorkstationEnvironment` (26.1) : bureau réseau si `shared_local`, bureau local si `personal_local`/`nomade` — fix définitif du Bug C, le pansement legacy meurt avec son canal.

**Given** l'union des mailles (parc + groupes user + poste)
**Then** le poste reçoit l'union des raccourcis applicables, sans doublon ; `apply` idempotent ; un raccourci retiré des règles disparaît du poste (convergence, pas accumulation).

**Given** le mode `strict|default` par règle
**Then** l'UI des raccourcis expose le toggle (première exposition UI du mode — il couvre aussi wallpaper/overlay rétroactivement), et un raccourci supprimé par un prof en mode `default` est rapporté `drifted_allowed`, pas recréé.

### Story 27.2 : Handlers lecteurs & imprimantes

En tant qu'admin d'établissement,
je veux que les lecteurs réseau et les imprimantes d'un poste suivent ses règles,
afin que « l'imprimante de la salle » soit un item d'état comme les autres (Vérité #9).

**Acceptance Criteria:**

**Given** les types `drives` et `printers` (figés, `aggregate`) avec providers + golden files
**When** l'agent converge en portée session
**Then** lecteurs mappés et imprimantes installées correspondent à l'union des mailles ; l'imprimante par défaut suit la règle la plus spécifique (sous-item `exclusive` documenté au schéma).

**Given** un serveur d'impression injoignable à l'apply
**Then** statut `error` + détail rapportés, les autres items continuent (isolation), retry au prochain cycle.

**Given** un mapping retiré des règles
**Then** il est démonté au passage suivant (level-triggered).

### Story 27.3 : Handlers registre & associations de fichiers

En tant que mainteneur SambaEdu,
je veux les vices de Windows (UserChoice…) confinés chacun dans un handler testable,
afin de ne plus jamais écrire de chorégraphie dispersée pour ces réglages.

**Acceptance Criteria:**

**Given** le type `registry` (figé) — premier type SANS table métier existante
**Then** sa table dédiée est créée avec son UI de règles (jamais de table polymorphe générique — D1), provider + golden file
**And** l'agent applique les valeurs de registre par portée (machine via SYSTEM, user via compagnon), idempotent.

**Given** le type `associations`
**When** l'agent applique une association de fichiers
**Then** le mécanisme UserChoice (hash) est entièrement confiné dans le handler (~50 lignes testables, lucidité #13) ; échec rapporté `error` avec détail exploitable.

**Given** un poste migré portant d'anciennes associations legacy
**Then** la convergence l'amène à l'état cible sans étape manuelle (l'histoire du poste ne compte plus).

### Story 27.4 : Handler config d'app déclarative — policies.json & profils navigateur

En tant qu'admin d'établissement,
je veux que la configuration des navigateurs et apps configurables suive l'état cible et la nature du poste,
afin que profils et politiques d'app soient gérés sans scripts.

**Acceptance Criteria:**

**Given** le type `app_config` (figé) avec provider + golden file
**When** l'agent converge
**Then** les mécanismes enterprise natifs sont utilisés : `policies.json` (Firefox), registre/politiques (Chrome/Edge) — idée #14 ; profil sur le home réseau quand l'app le supporte.

**Given** la nature du poste (26.1)
**Then** les profils navigateur (Chrome/Edge/OpenBoard) sont redirigés ou laissés locaux selon `WorkstationEnvironment` (remplace les exclusions legacy), et `clean_profiles` est gaté en conséquence.

**Given** une app butée qui écrit localement sans option
**Then** le comportement est documenté comme limite connue (« match nul » assumé du brainstorming) — pas de contournement bricolé dans l'agent.

### Story 27.5 : Applications — l'agent déclenche WPKG (un tuyau, deux outils)

En tant qu'admin d'établissement,
je veux que les installations d'applications passent par le canal agent,
afin que la dernière ressource quitte le transport GPO sans réécrire le moteur de paquets.

**Acceptance Criteria:**

**Given** le type `applications` (figé) avec provider lisant les affectations existantes
**When** l'état cible contient des applications
**Then** l'agent **déclenche** le moteur WPKG local (qui reste le moteur déclaratif — il n'est PAS absorbé) et rapporte le résultat par application ; le déclencheur GPO de WPKG est supprimé.

**Given** un poste hors ligne pendant l'installation programmée
**Then** il converge à son prochain cycle (level-triggered — fini les postes joints qui n'installent rien).

**Given** le rapport
**Then** les sièges/licences restent comptabilisables (le reporting porte l'inventaire par poste — fondation des licences à pool, sans en implémenter l'UI).

### Story 27.6 : Extinction du canal legacy — parité de compétences validée, la dette part en bloc

En tant que mainteneur SambaEdu,
je veux valider la parité de compétences puis supprimer le canal legacy d'un coup,
afin que SE5 ne porte plus qu'UN système de configuration des postes.

**Acceptance Criteria:**

**Given** la checklist de parité de compétences (référence : capacités legacy — wallpapers, raccourcis, lecteurs, imprimantes, associations, registre, config d'app, applications, ciblage par critères)
**When** chaque capacité est démontrée sur le canal agent en environnement réaliste (postes migrés + neufs, salles multiples)
**Then** la checklist est validée et documentée — c'est le gate de la mise en production (palier 3).

**Given** la parité validée
**When** l'extinction s'exécute
**Then** sont supprimés en bloc : `ApplicationScriptsGenerator`/`Assembler` et le matching par tags `list_*`, les shims `/gpo/*`, le canal `/api/v1/workstation-config/*` (les routes overlay ayant déjà migré), et les templates GPO serveur hors bootstrap
**And** seule la GPO-dispatcher bootstrap (25.4) survit — figée, jamais ré-éditée.

**Given** l'audit post-extinction
**Then** 0 GPO créée ou modifiée hors bootstrap (KPI du brief, audit SYSVOL) ; aucune route legacy ne répond ; les tests du canal supprimé sont retirés avec lui.
