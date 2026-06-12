# Story 24.7 : Conformité visible — l'état rapporté dans les pages parc + forcer la synchro

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **voir l'état rapporté de mes postes là où je gère déjà mon parc, et pouvoir forcer une synchro**,
afin de **détecter un écart sans me déplacer — et fermer la boucle de la démo**.

## Contexte & intention

**Gate palier 1 de l'Epic 24** (ex-24.5 renumérotée par le sprint-change-proposal-2026-06-12, contenu inchangé). Toute la chaîne existe et tourne : état cible compilé (23.4/23.5), token/quarantaine (23.2/23.3), ingestion des rapports + tables D3 (24.1), **binaire Go complet 2.1.1 signé, validé sur le poste lab ws 49** (24.5 review / 24.6 done — incident T12 soldé). Le serveur SAIT déjà tout (`agent_resource_states`, `agent_report_events`, `agent_last_checkin_at`) ; il ne le **montre** nulle part. Cette story livre le dernier maillon « → UI » : la conformité **intégrée aux pages parc existantes** (penser en règles, le poste n'apparaît qu'en exception, pas de page « postes » à part) + le bouton « **forcer la synchro** » (FR10/FR11). Critère de complétude de l'epic = **démo live répétable** : changer le wallpaper d'un parc dans l'UI → le poste lab converge → le rapport remonte → l'écart se voit puis se résorbe.

**Ce que cette story est :** une story **UI Livewire + lecture serveur**, avec UN ajustement serveur minimal et entièrement spécifié (mécanique pull du « forcer la synchro », décision n° 1).
**Ce que cette story n'est PAS :** une story agent (binaire Go 2.1.1 FIGÉ, zéro modification, zéro rebuild exigé) ; ni distribution/canari (25.x) ; ni nouveaux handlers (Epic 27) ; ni alerting/notifications (futur).

## Dépendances

| Dépendance | Statut | Ce qu'on en consomme |
|---|---|---|
| **24.1** POST /report + stockage D3 | done | `agent_resource_states` (état courant upserté par (workstation, type), `reported_at` rafraîchi à CHAQUE rapport — donnée de fraîcheur UI), `agent_report_events` (journal daté des changements, rétention 14 j), enum `AgentResourceStatus`. Arbitrages review faits POUR cette UI : événement conservé sur `drifted_allowed→drifted_allowed` hash changé (#4) ; `detail` de guérison = null, contexte dans l'événement d'erreur précédent (#6). |
| **24.6** agent Go compagnon + handlers | done (binaire 2.1.1) | Comportement du cycle : GET /state machine → fetchs `?user=` des sessions actives (in-process) → sync assets → collecte drops → POST /report. Compagnon : poll mtime du cache ~60 s + re-test ~5 min. **Un 200 au lieu d'un 304 est un chemin nominal de l'agent** (réécrit cache + etag verbatim). |
| **24.5** agent Go core | review (T11 soldé avec T12) | Boucle/timer 3600 s + jitter, backoff, quarantaine = check-ins légers sans report. **Vérifié dans le code (`agent/shared/loop.go`) : l'agent parse `ttl_seconds` mais ne l'utilise PAS pour planifier** — fonde la décision n° 1. Précédent projet : dev sur dépendance en review accepté. |
| **23.2/23.5** middleware + GET /state | done | `AuthenticateAgentToken` (401/403, check-in, X-Agent-New-Token — INTOUCHÉ), `StateController` (ETag = `hashState`, `isNotModified()`), card Agent UI machine [id] (révocation token, dates) — à ÉTENDRE, pas dupliquer. |
| **16.13bis** module migration UI Parc | done | LE pattern à imiter : colonne + filtre + compteur dans `parc/index` / `machines-tab` (`migrationFilter`, badge, stats). |

## ⚠️ Pièges connus (lire avant de coder)

1. **ZÉRO modification de l'agent Go, du contrat, des golden files.** `agent/`, `docs/agent/contract-v1.md`, `docs/agent/enrollment.md`, `tests/Fixtures/Agent/*`, `FROZEN_STATE_HASH` = intouchés. Le binaire 2.1.1 tourne sur ws 49 : la story doit marcher avec LUI tel quel. Conséquence vérifiée : pas de raccourcissement du poll possible (piège 2).
2. **L'agent ignore `ttl_seconds` pour sa planification** (`agent/shared/loop.go:280-312` : `interval` vient exclusivement de `config.json`/`DefaultIntervalSeconds` ; `state.TtlSeconds` parsé mais sans consommateur). Tout « forcer » par ttl dynamique ou push exigerait une modif agent → ÉCARTÉ (décision n° 1). Ne pas réessayer.
3. **Le 200 forcé reste l'enveloppe contrat BRUTE avec le MÊME ETag.** Le bypass = ne pas honorer `If-None-Match`, RIEN d'autre : `setEtag()` conservé, jamais de wrapper SE5 sur ce corps, aucun changement du calcul de hash. L'agent restocke le même etag verbatim et reprend ses 304 au cycle suivant.
4. **`StateController` est aujourd'hui ZÉRO write** (docblock : tout write = middleware). Le GET ne consomme PAS la demande (elle doit couvrir les fetchs `?user=` du même cycle) ; la consommation vit au POST /report (décision n° 1). Ne pas écrire dans le GET.
5. **`workstations.status` = domaine fermé (`active|inactive|protected`)** — la conformité agent ne s'y écrit JAMAIS (mémoire projet). Elle vit dans `agent_*` + la nouvelle colonne `agent_sync_requested_at`.
6. **Quarantaine = GET légers sans POST /report ni convergence** : une demande de synchro sur un poste en quarantaine ne sera jamais soldée ni suivie d'effet → bouton désactivé + tooltip (idem poste non enrôlé). Le badge quarantaine existant (card Agent 23.2) prime visuellement.
7. **Race acceptée (documentée, pas sur-ingénierée)** : un clic pendant un cycle agent en vol (fenêtre de quelques secondes) peut être soldé par le rapport quasi-simultané sans 200 forcé. Conséquence bénigne (l'admin voit un check-in frais, re-clique au besoin). PAS de deuxième colonne/d'état machine pour ça.
8. **`drifted_allowed` = dérive TOLÉRÉE** (mode `default`, contrat §5) : badge informatif distinct, jamais compté dans « en écart » des compteurs d'alerte. `drift`/`error` = en écart. Distinguer aussi les états dérivés : **jamais rapporté** (enrôlé, zéro ligne d'état) et **muet** (`agent_last_checkin_at` > 2 × `config('agent.ttl_seconds')`).
9. **Hashes opaques partout** : l'UI affiche éventuellement un hash tronqué (`Str::limit`), ne l'interprète ni ne le recalcule JAMAIS (grep `hash(`/`md5(` en review, hors tests).
10. **Pages Livewire volumineuses** (`machines/[id]/index.blade.php` ~1200 l., `groups/[id]/index.blade.php` ~1800 l.) : modifications chirurgicales, le NEUF va dans des `_partials/`, ne pas casser tabs/`#[Url]`/sélections existants. Filets : `tests/Feature/Livewire/Parc/{MachineShowPageTest,GroupShowPageTest}.php`.
11. **Pas de N+1 sur le tableau parc** : badge conformité par ligne = UNE requête agrégée par page (worst-status par `workstation_id` sur les ids paginés), compteurs = requêtes `groupBy` — jamais une relation lazy par ligne.
12. **`wire:poll` borné** : uniquement sur le panneau conformité / la card Agent (pattern `statusRunning` des power actions machine [id]) — jamais sur tout le tableau parc.
13. **Tests SQLite n'appliquent pas les varchar** (mémoire projet) ; throttle `throttle:60,1` sur les routes agent — conventions `StateEndpointTest` (helpers, `TokenRotationService::issueFor()`) pour ne pas le percuter.
14. **VM : migrations pas auto-jouées** (`migrate:status` avant tout e2e) ; nouvelle migration ICI → `php artisan migrate` sur /vm. Aucune nouvelle clé config ni route → ni `config:cache` ni `route:cache` requis ; `chown www-admin` si fichiers `bootstrap/cache/` régénérés. inotify ne propage pas les deletes ; jamais de sync manuelle.
15. **Événements de guérison** : `detail` null par design (24.1 #6) — pour le contexte d'une erreur guérie, l'UI lit l'événement d'ERREUR précédent dans l'historique (append-only). Ne pas « réparer » l'ingestion.

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **« Forcer la synchro » = demande PULL côté serveur, servie au prochain contact, soldée par le rapport** (FR11, NFR3 — mécanisme choisi et documenté, AC epic) :
   - **Colonne** `workstations.agent_sync_requested_at` (timestamp nullable, préfixe `agent_` = convention architecture).
   - **Demande** : bouton UI (poste ou groupe = tous ses membres enrôlés) → `SyncRequestService::request()` → timestamp posé + log `agent.sync.requested` (admin, périmètre).
   - **Service** : tant que la demande est pendante, **`GET /api/v1/agent/state` (tous contextes : machine ET `?user=`) répond 200 corps complet même si `If-None-Match` concorde** (bypass de `isNotModified()`, même ETag — piège 3). Effet poste : cache `state.json` réécrit → mtime change → le compagnon rejoue les handlers ≤ ~60 s après le check-in ; le service reconverge la portée machine au même cycle. Log debug `agent.sync.state_forced`.
   - **Solde** : le premier **POST /report** suivant consomme la demande (`SyncRequestService::fulfill()` → colonne remise à null + log `agent.sync.fulfilled`) — le cycle agent étant GET(s) → … → report, tous les contextes du cycle ont bénéficié du bypass avant le solde.
   - **Latence honnête assumée** : la demande est servie au prochain contact (timer ≤ 60 min + jitter, ou boot/login immédiats) — c'est LE modèle pull (fraîcheur laxe, D7) ; le bouton garantit une **reconvergence complète** (full state re-servi, toutes portées) + une **trace** + un **feedback UI** (« demandée le… / en attente du prochain check-in » → disparaît au solde).
   - **Écartés** : ttl dynamique / raccourcissement du poll (piège 2 — modif agent ; évolution possible quand l'auto-update 25.x portera un agent qui honore `ttl_seconds`, noter dans la doc) ; push WoL/reboot/WinRM (NFR3, anti-couteau-suisse) ; bouton purement décoratif (sans garantie de re-servir l'état complet, indigne du nom).
2. **Écritures cadrées sur la colonne neuve** (la story étend l'invariant « colonnes `agent_*` = middleware » de 24.1) : `agent_sync_requested_at` a exactement DEUX écrivains — l'UI admin via `SyncRequestService::request()` (canal web authentifié, PAS le canal agent) et `ReportController` via `SyncRequestService::fulfill()` (canal agent, à côté de l'ingestion). `StateController` reste zéro write (piège 4). Documenté dans `state-endpoint.md`/`report-endpoint.md`.
3. **Lecture conformité = service dédié partagé** `App\Services\Agent\Reporting\ConformityService` (singleton, binding `AgentServiceProvider`) — JAMAIS de requêtes agrégées dupliquées dans 3 composants Livewire : `summary(?WorkstationGroup)` (compteurs par statut + dérivés sur un périmètre), `worstStatusFor(array $workstationIds)` (badge tableau, 1 requête), `exceptionsFor(?WorkstationGroup)` (états ≠ compliant + jamais-rapportés/muets, datés, groupés par type), `statesFor(Workstation)` + `recentEventsFor(Workstation, int $limit = 10)`. Précédence du worst-status : `error > drift > drifted_allowed > compliant` ; dérivés : jamais rapporté / muet (piège 8). Périmètre = postes **enrôlés** (les non-enrôlés sont hors conformité, affichés neutres).
4. **« Penser en règles » version MVP** : la « règle » à l'écran = **type de ressource × périmètre** (le serveur ne stocke pas quelle maille a produit la cible — `agent_resource_states` est par (poste, type)). Vue groupe : un bloc par type (`wallpaper`, `overlay`, … types effectivement rapportés) → « n/N conformes » + la liste des SEULES exceptions, datées. Aucune liste exhaustive de postes verts.
5. **Emplacements UI** (zéro nouvelle route web — pages existantes) :
   - `pages/parc/index` : compteurs conformité dans `_partials/stats-cards.blade.php` + colonne badge + filtre `conformityFilter` (`''|exceptions|drifted_allowed|compliant|silent`) dans `_partials/machines-tab.blade.php` — calque exact du pattern 16.13bis (`#[Url]`, options select, reset).
   - `pages/parc/machines/[id]` : **extension de la card Agent existante** (23.2) — table « État rapporté par type » (type, badge statut, daté `reported_at`, `detail`, hash tronqué) + sous-section « Derniers événements » (10, datés, `previous_status→status`) + bouton « Forcer la synchro » (wire:confirm iso `revokeAgentToken`, désactivé si non enrôlé/quarantaine — piège 6) + état de la demande pendante + `wire:poll` borné sur ce bloc.
   - `pages/parc/groups/[id]` : nouveau partial `_partials/conformity-panel.blade.php` (onglet `general`) — résumé par type (décision n° 4) + exceptions + bouton « Forcer la synchro » groupe (wire:confirm, compte des postes ciblés ; ignorer silencieusement non-enrôlés/quarantaine, toast récapitulatif).
   - Confirmations = `wire:confirm` (pattern en place sur ces pages) ; si une vraie modale s'avère utile (ex. détail des événements d'une exception), utiliser la modale réutilisable `x-molecules.modal` + bouton déclencheur — jamais une modale ad hoc.
6. **Retour auto à `compliant` sans action admin** : c'est l'upsert 24.1 qui le porte (`reported_at` rafraîchi à chaque rapport) — l'UI n'a qu'à relire. `wire:poll.15s` sur le panneau conformité/card Agent uniquement (piège 12) suffit pour la démo live.
7. **Aucune nouvelle clé config** : seuil « muet » = `2 × config('agent.ttl_seconds')` (clé existante 23.5) — pas de `config:cache` VM à refaire.
8. **Toasts** : trait `WithToasts` (`app/Components/Traits/WithToasts.php`) pour toutes les notifications (demande envoyée, erreurs) — déjà `use` sur les 3 pages.

## Acceptance Criteria

### AC1 — Conformité intégrée à la page parc (FR10 — AC epic : penser en règles, poste = exception)

**Given** des rapports ingérés (24.1)
**When** je consulte `app/parc` (onglet machines)
**Then** des compteurs de conformité apparaissent (stats-cards) : postes en écart (`drift`+`error`), dérive tolérée, jamais rapporté/muets, conformes — calculés sur les postes **enrôlés**, en requêtes agrégées (zéro N+1, piège 11)
**And** chaque ligne machine porte un badge conformité (worst-status, décision n° 3 ; neutre si non enrôlé) et un filtre `conformityFilter` permet d'isoler les exceptions (pattern 16.13bis : `#[Url]`, select, reset des filtres)
**And** aucune page « postes » à part n'est créée — tout vit dans les pages parc existantes.

### AC2 — Détail poste : l'état rapporté par type, daté (FR10 — AC epic)

**Given** la page `app/parc/machines/{id}` d'un poste enrôlé
**Then** la card Agent (23.2, étendue — pas dupliquée) montre l'état rapporté **par type** : badge statut (4 statuts enum + distinction visuelle `drifted_allowed` — piège 8), date `reported_at`, `detail` (tronqué), hash opaque tronqué jamais interprété (piège 9)
**And** une sous-section « Derniers événements » liste les 10 derniers `agent_report_events` datés (`previous_status → status`, detail) — les écarts sont DATÉS (l'événement de dérive donne le début, la ligne d'état donne la fraîcheur)
**And** les états dérivés sont visibles : « jamais rapporté » (aucune ligne) et « muet » (check-in > 2 × ttl — décision n° 7) ; un poste non enrôlé garde l'affichage neutre existant.

### AC3 — Vue groupe : conformité par règle, exceptions seules (FR10 — AC epic)

**Given** la page `app/parc/groups/{id}`
**Then** un panneau conformité (partial dédié, onglet `general`) présente, par **type de ressource** rapporté sur le périmètre du groupe : « n/N conformes » + la liste des seuls postes en exception (statut ≠ compliant, jamais rapporté, muet), chacun daté et cliquable vers son détail
**And** aucun poste conforme n'est listé (le poste n'apparaît qu'en exception).

### AC4 — Retour auto à `compliant` (AC epic)

**Given** un écart `drift` corrigé au passage suivant de l'agent
**Then** la vue (poste et groupe) reflète le retour à `compliant` **sans action de ma part** : `wire:poll` borné au panneau conformité/card Agent (piège 12), données relues depuis `agent_resource_states` (l'upsert 24.1 fait foi)
**And** test : deux ingestions successives via `ReportIngestService` (drift puis compliant) → le rendu Livewire passe de l'exception à l'absence d'exception.

### AC5 — Forcer la synchro : mécanisme pull choisi, documenté, soldé (FR11 — AC epic)

**Given** le bouton « forcer la synchro » (poste ou groupe)
**When** je clique (confirmation `wire:confirm`)
**Then** `agent_sync_requested_at` est posé (poste, ou tous les membres enrôlés non-quarantaine du groupe) + toast + log `agent.sync.requested` (admin + périmètre) ; le bouton est désactivé avec tooltip pour un poste non enrôlé ou en quarantaine (piège 6)
**And** tant que la demande est pendante, `GET /api/v1/agent/state` (contexte machine ET `?user=`) répond **200 corps complet même si `If-None-Match` concorde**, avec le MÊME ETag et l'enveloppe contrat brute inchangée (pièges 3-4 — zéro write dans le GET) → le poste re-télécharge tout l'état et reconverge avant son prochain cycle naturel complet, **le mécanisme reste du pull** (NFR3, décision n° 1 — documentée)
**And** le premier `POST /api/v1/agent/report` suivant **solde** la demande (colonne remise à null, log `agent.sync.fulfilled`) ; l'UI montre l'état pendant/après (« demandée le… / en attente du prochain check-in » → disparaît au solde) ; la race du cycle en vol est acceptée et documentée (piège 7).

### AC6 — Frontières et intouchables

**Then** AUCUNE modification sous `agent/` (binaire 2.1.1 figé), ni de `contract-v1.md`/`enrollment.md`/golden files/`FROZEN_STATE_HASH`/`StateHasher`/middleware `AuthenticateAgentToken`/`StateCompiler`/providers
**And** modifications serveur du canal agent STRICTEMENT bornées à : bypass conditionnel dans `StateController::show()` (+ log) et appel `fulfill()` dans `ReportController` — tout le reste = additions (migration, service, UI) ; `ReportIngestService` intouché
**And** la colonne `agent_sync_requested_at` a exactement 2 écrivains (décision n° 2) ; `workstations.status` intouché (piège 5) ; aucun appel AD/LDAP/Kerberos (critère Keycloak, grep en review) ; aucun hash recalculé (grep `hash(`/`md5(` hors tests).

### AC7 — Tests

**Then** `tests/Feature/Api/V1/Agent/` (conventions `StateEndpointTest` : factories, `issueFor()`) couvre : demande pendante + `If-None-Match` concordant → **200** corps complet, même ETag ; même requête sans demande → **304** (non-régression) ; demande pendante + contexte `?user=` → 200 forcé aussi ; POST /report → demande soldée (colonne null) ; report sans demande → no-op ; quarantaine → 403 middleware AVANT toute logique (demande non soldée)
**And** tests Unit des services (`SyncRequestService` : request/fulfill/périmètre groupe avec exclusions ; `ConformityService` : worst-status, compteurs, dérivés jamais-rapporté/muet, exceptions datées)
**And** tests Livewire : extension `MachineShowPageTest` (table par type, événements, bouton + états désactivés, demande posée) et `GroupShowPageTest` (panneau exceptions, bouton groupe) + nouveau test page parc (compteurs + filtre `conformityFilter`)
**And** sur /vm : `php artisan test --filter Agent` (baseline **206 passed / 839 assertions** + les nouveaux, zéro régression) + les tests Livewire ciblés (`--filter "MachineShowPage|GroupShowPage|Conformity"`) — **jamais la suite complète** (décision Henri).

### AC8 — Démo palier 1, doc, VM

**Then** `docs/qa/domains/agent.md` enrichi **append-only** (nouvelle **Section 7** « Conformité UI + forcer la synchro », sans renuméroter 1-6) : scénario de démo live répétable pas-à-pas (changer le wallpaper d'un parc dans l'UI → ws 49 converge → rapport → l'écart se voit puis se résorbe à l'écran → forcer la synchro → solde visible) + ligne 24.7 dans `docs/qa/README.md`
**And** `docs/agent/state-endpoint.md` + `report-endpoint.md` documentent la sémantique force-sync (bypass 304 pendant demande / solde au report, latence pull assumée, évolution ttl notée) — `contract-v1.md` FIGÉ, zéro édit
**And** opérations VM tracées : `migrate:status` puis `php artisan migrate` (+ chown www-admin si `bootstrap/cache/` régénéré), smoke : poser une demande en Tinker → curl GET /state avec If-None-Match → 200 ; la **démo live elle-même** (ws 49) = action humaine Henri, gate palier 1.

## Tasks / Subtasks

- [x] **T1 — Migration `add_agent_sync_requested_at_to_workstations`** (AC5)
  - [x] `database/migrations/2026_06_12_120000_add_agent_sync_requested_at_to_workstations.php` — timestamp nullable, guard `Schema::hasColumn`, docblock iso 23.2, `down()` propre
- [x] **T2 — Modèles & relations** (AC1, AC2, AC5)
  - [x] `Workstation` : relations `agentResourceStates()` / `agentReportEvents()` (hasMany), helpers `hasAgentSyncPending()`, `isAgentSilent()` (2 × ttl — décision n° 7) ; `$casts['agent_sync_requested_at'] = 'datetime'`
- [x] **T3 — `SyncRequestService`** (AC5, AC6)
  - [x] `app/Services/Agent/SyncRequestService.php` : `request(Workstation|Collection, ?Authenticatable $admin)` (filtre enrôlés non-quarantaine, retourne le compte), `isPending(Workstation)`, `fulfill(Workstation)` ; logs channel `agent` (`agent.sync.requested`/`agent.sync.fulfilled`, contexte workstation_id + admin, jamais de token) ; binding singleton `AgentServiceProvider`. **Note dev** : type `?Authenticatable` (pas `User`) pour rester robuste à `auth()->user()` (mock Authenticatable / null) — id/login lus défensivement.
- [x] **T4 — Bypass GET /state + solde au report** (AC5, AC6) — *chirurgical, zéro write au GET*
  - [x] `StateController::show()` : si `agent_sync_requested_at` non null → court-circuite `isNotModified()` (ETag posé, corps 200 conservé, enveloppe brute) + log debug `agent.sync.state_forced` ; docblock mis à jour
  - [x] `ReportController::store()` : après ingestion, `SyncRequestService::fulfill()` (no-op si pas de demande)
- [x] **T5 — `ConformityService` (lecture agrégée)** (AC1, AC2, AC3)
  - [x] `app/Services/Agent/Reporting/ConformityService.php` : `summary()`, `worstStatusFor(ids)`, `exceptionsFor()`, `statesFor()`, `recentEventsFor()` (décision n° 3) — requêtes agrégées, périmètre = enrôlés, dérivés jamais-rapporté/muet ; binding singleton
- [x] **T6 — UI parc index** (AC1)
  - [x] `pages/parc/index.blade.php` + `_partials/stats-cards.blade.php` : compteurs conformité (2e rangée de cards) ; `_partials/machines-tab.blade.php` : colonne badge (worst-status par page, 1 requête via `$this->machineConformity`) + filtre `conformityFilter` `#[Url]` (pattern 16.13bis, intégré au reset). Filtre threadé `WorkstationGroupService::listMachines` → `WorkstationGroupRepository::applyConformityFilter`.
- [x] **T7 — UI machine [id]** (AC2, AC4, AC5)
  - [x] Card Agent étendue via `_partials/agent-conformity.blade.php` : table état par type + « Derniers événements » (10) + états dérivés + bouton « Forcer la synchro » (`wire:confirm`, désactivations piège 6, toast) + affichage demande pendante + `wire:poll.15s` borné ; action Livewire `forceSyncWorkstation` + computed `agentStates`/`agentEvents`
- [x] **T8 — UI groupe [id]** (AC3, AC4, AC5)
  - [x] `_partials/conformity-panel.blade.php` (onglet `general`) : blocs par type (n/N + exceptions datées, liens détail poste) + bouton groupe `forceSyncGroup` (`wire:confirm`, toast récapitulatif demandés/ignorés) + `wire:poll.15s` borné ; computed `conformityByType`/`conformitySummary`
- [x] **T9 — Tests** (AC4, AC7)
  - [x] Feature API : `tests/Feature/Api/V1/Agent/SyncRequestTest.php` (matrice AC7 : 200 forcé/304 nominal/contexte user/solde/no-op/quarantaine) — 8 tests
  - [x] Unit : `tests/Unit/Services/Agent/{SyncRequestServiceTest,ConformityServiceTest}.php` — 17 tests
  - [x] Livewire : extensions `MachineShowPageTest` (+5) / `GroupShowPageTest` (+2) + `tests/Feature/Livewire/Parc/ParcConformityTest.php` (+5 : compteurs, badge, filtre, reset, retour auto compliant — AC4). Infra : agent tables/colonnes ajoutées aux `createTablesIfNeeded` de Machine/Group/GroupSchedules (cette dernière = régression évitée, la page groupe inclut le panneau).
- [x] **T10 — Docs + VM + validation finale** (AC8)
  - [x] Docs : QA Section 7 append-only (7.1-7.6 + 6 items checklist) + ligne README QA ; addenda `state-endpoint.md` (§ bypass 304) / `report-endpoint.md` (§ solde au report) ; `contract-v1.md` intouché
  - [x] Greps de garde : zéro AD/LDAP/Kerberos en prod, zéro hash recalculé hors tests, `git status` = zéro fichier sous `agent/`/`routes/`/`config/`/contrat. `php -l` OK sur tous les fichiers modifiés. Tests host (SQLite) : 30 nouveaux verts + suite Parc Livewire 83/83 verte.
  - [ ] **/vm RESTE (VM injoignable au dev — `No route to host` 192.168.122.50)** : `migrate:status` → `php artisan migrate` (+ chown www-admin si `bootstrap/cache/`) ; `php artisan test --filter Agent` (baseline 206 + nouveaux) + filtres Livewire ciblés ; smoke Tinker + curl GET /state (AC8). **À exécuter dès retour VM.**
  - [ ] **Démo live palier 1 (ws 49) : ACTION HUMAINE (Henri)** — dérouler QA Section 7 (scénario 7.5), gate de l'epic ; résultat à tracer en Completion Notes

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (24.7) | Hors-scope (story) |
|---|---|
| Conformité dans les 3 pages parc (compteurs, badge+filtre, détail par type, panneau groupe) | Toute modif de l'agent Go / rebuild / redéploiement (binaire 2.1.1 figé) |
| Bouton « forcer la synchro » poste + groupe (pull : colonne + bypass 304 + solde au report) | Raccourcissement du poll via ttl dynamique (évolution notée doc, vecteur naturel = auto-update 25.x) |
| `SyncRequestService` + `ConformityService` + migration colonne | Alerting/notifications/historique long (au-delà des events 14 j) |
| Docs state/report + QA Section 7 (scénario démo) | Page « postes » dédiée (anti-pattern [#18]) ; UI strict/défaut par type (parc-settings/agent, autre story) |
| Extensions chirurgicales StateController/ReportController | `ReportIngestService`, middleware, StateCompiler, providers, contrat (FIGÉS) |

### Données disponibles (livrées 24.1 — la story LIT, n'écrit pas)

- `agent_resource_states` : (workstation_id, type UNIQUE), `status` (cast enum `AgentResourceStatus` : `compliant|drift|drifted_allowed|error`), `hash` (opaque), `detail` (text), `reported_at` (rafraîchi à CHAQUE rapport — fraîcheur). Écrit UNIQUEMENT par `ReportIngestService`.
- `agent_report_events` : append-only (`UPDATED_AT = null`), `previous_status` nullable, `created_at` daté, rétention 14 j (purge 02:35). Inclut `drifted_allowed→drifted_allowed` à hash changé (gardé POUR cette UI).
- `workstations.agent_*` : `agent_last_checkin_at` (middleware), `agent_quarantined_at`, helpers `isAgentEnrolled()`/`isAgentQuarantined()` (utilisés par la card Agent 23.2).
- Types affichables : aujourd'hui seuls `wallpaper`/`overlay` remontent (handlers 24.6) — l'UI itère sur les types PRÉSENTS en base, pas sur `StateContract::RESOURCE_TYPES` (pas de lignes vides pour 7 types sans handler).

### Patterns existants à imiter (NE PAS réinventer)

- **Colonne+filtre+compteur parc** : `migrationFilter` 16.13bis dans `pages/parc/index.blade.php` (#[Url], `availableOs`…) + `machines-tab.blade.php` (select l.35-43, badge l.143-152, reset l.61-67) + `stats-cards.blade.php`.
- **Card Agent + action confirmée** : `pages/parc/machines/[id]/index.blade.php` l.1080-1135 (card 23.2) et `revokeAgentToken()` l.211-233 (wire:confirm + WithToasts + try/catch + Log).
- **Poll conditionnel** : propriétés `statusRunning`/`pollMachineReadiness` même page (le poll n'est rendu que si nécessaire).
- **Onglets groupe** : `setTab()`/partials de `pages/parc/groups/[id]/index.blade.php` (l.1788-1805).
- **Modale réutilisable** (si besoin) : `x-molecules.modal` + `modal.section` (`resources/views/components/molecules/modal/`).
- **Service agent + binding** : `app/Providers/AgentServiceProvider.php` (singletons existants).
- **Tests canal agent** : `tests/Feature/Api/V1/Agent/StateEndpointTest.php` (ETag/304, helpers) ; **tests pages** : `tests/Feature/Livewire/Parc/*`.

### Architecture — conventions applicables (NON négociables)

[Source: architecture-agent-desired-state.md#Naming ; #Frontend ; #Enforcement]
- Logs channel `agent`, actions namespacées `agent.sync.*`, contexte `workstation_id`, jamais de token/payload complet.
- Couche Services (jamais de logique métier dans les controllers/composants au-delà de l'orchestration UI) ; canal agent n'écrit que `agent_*` (+ la colonne cadrée décision n° 2) ; StateProviders/compilation intouchés ; aucune dépendance AD.
- UI : Livewire SFC convention `pages/`, DaisyUI, WithToasts, conformité = « reporting par règle, poste = exception [#18] ».

### Project Structure Notes

- Racine = projet Laravel (host + VM) ; code édité sur l'hôte, exécuté sur la VM (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, `/var/www/sambaedu-reload`), sync inotify auto.
- Fichiers neufs attendus : migration, `app/Services/Agent/SyncRequestService.php`, `app/Services/Agent/Reporting/ConformityService.php`, `resources/views/pages/parc/groups/[id]/_partials/conformity-panel.blade.php` (+ partial machine si utile), 4 fichiers de tests. Modifiés : `Workstation.php`, `StateController.php`, `ReportController.php`, `AgentServiceProvider.php`, 3 pages/partials parc, 2 tests pages, 3 docs.
- AUCUN fichier sous `agent/`, `routes/`, `config/` (vérifiable `git status` en review).

### Testing standards

- PHPUnit, exécution de référence sur /vm ; SQLite `:memory:` (piège 13). Baseline `--filter Agent` = **206 passed (839 assertions)** — cette story MODIFIE du PHP : le run doit monter, jamais régresser. Jamais la suite complète.
- Livewire : `Livewire::test()` sur les pages (conventions des tests Parc existants) ; simuler l'ingestion via `ReportIngestService` réel (pas de seed à la main des tables agent quand le service suffit — il porte la règle d'événements).

### Intelligence stories précédentes

- **24.1 (done)** : `reported_at` rafraîchi même sur rapport identique = LA donnée de fraîcheur prévue pour cette UI ; arbitrages #4/#6 faits en sa review pour cette UI (piège 15).
- **24.5/24.6 (review/done)** : cycle agent = GET machine → fetchs sessions → drops → report (fonde le couple bypass-au-GET/solde-au-report) ; compagnon mtime ~60 s (fonde l'effet du 200 forcé) ; quarantaine = pas de report (fonde piège 6) ; 200 inattendu = chemin nominal agent (fonde piège 3) ; incident T12 : ce que les tests hôte ne voient pas se paie au lab → la démo humaine ws 49 reste le gate.
- **16.13bis** : le trio colonne/filtre/compteur sur l'UI Parc est déjà passé en review — recopier la mécanique, pas l'improviser.
- **20.4 (mémoire projet)** : l'audit HTTP ne voit pas les mutations Livewire — raison de plus pour logger `agent.sync.requested` avec l'admin DANS le service.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 24.7] — ACs source, FR10/FR11, gate palier 1
- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-06-12.md §4.B] — renumérotation ex-24.5 → 24.7, contenu inchangé, démo sur binaire Go signé
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#D3 ; #D7 ; #Frontend Architecture ; #Data Flow] — reporting borné, fraîcheur laxe + bouton forcer (point de synchro), UI conformité règles→exceptions
- [Source: agent/shared/loop.go:280-312 ; agent/shared/contract.go:89-91] — preuve : `ttl_seconds` parsé, jamais utilisé pour planifier (fonde la décision n° 1)
- [Source: app/Http/Controllers/Api/V1/Agent/StateController.php ; ReportController.php] — points d'extension chirurgicaux
- [Source: app/Models/AgentResourceState.php ; AgentReportEvent.php ; app/Enums/AgentResourceStatus.php] — données lues
- [Source: resources/views/pages/parc/… (index, machines/[id], groups/[id], _partials)] — surfaces UI et patterns
- [Source: docs/agent/state-endpoint.md ; report-endpoint.md ; docs/qa/domains/agent.md] — docs à compléter (append-only QA)
- [Source: _bmad-output/codeReviews/24-1.md (#4, #6) ; 24-5.md ; 24-6.md] — findings différés consommés/à ne pas régresser

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context) — DEV BMAD dev-story.

### Debug Log References

- Tests host (SQLite `:memory:`, VM injoignable au dev) :
  - `SyncRequestServiceTest|ConformityServiceTest` → **17 passed**.
  - `SyncRequestTest` (Feature API) → **8 passed / 21 assertions**.
  - `MachineShowPageTest` (étendu) → **16 passed / 69 assertions**.
  - `GroupShowPageTest` (étendu) → **15 passed / 69 assertions**.
  - `ParcConformityTest` (neuf) → **5 passed / 10 assertions**.
  - Suite Parc Livewire complète → **83 passed / 273 assertions** (zéro régression ; `GroupSchedulesPageTest` percutait le panneau conformité au render → table `agent_resource_states` ajoutée à son `createTablesIfNeeded`, corrigé).
- `--filter Agent` sur l'hôte = 231 tests dont 28 ERRORS **toutes** `RuntimeException: ldap_search(): Can't contact LDAP server` — artefact hôte (l'observer `WorkstationGroupObserver` dispatche un AD sync job sur `groups()->attach()` ; ces tests pré-existants — Overlay/Wallpaper/StateCompiler/HandlersE2e/StateEndpoint — passent sur la VM où LDAP est configuré). Vérifié : un test StateEndpoint SANS attach passe sur l'hôte ; `RoutesProtectionTest` a 12 échecs `ViteManifestNotFoundException` IDENTIQUES avec/sans mes changements (manifest non buildé localement). **Aucune régression imputable à 24.7.**

### Completion Notes List

- **T1-T8 livrés.** Mécanique « forcer la synchro » = PULL pur conforme à la décision n° 1 : colonne `agent_sync_requested_at` (2 écrivains stricts), bypass 304 zéro-write au GET, solde au POST /report. UI conformité intégrée aux 3 pages parc (compteurs+badge+filtre iso-16.13bis, détail par type+événements datés, panneau groupe règles→exceptions), badge réutilisable `x-atoms.conformity-badge` (mapping centralisé).
- **Décision dev (à valider en review)** : `SyncRequestService::request()` prend `?Authenticatable` (pas `User`) — `auth()->user()` peut être un mock Authenticatable (tests) ou null ; id/login lus défensivement, jamais bloquant. Conforme à l'esprit du log audit (admin tracé) sans coupler à `App\Models\User`.
- **Filtre conformité repository** : `applyConformityFilter` aligne la sémantique SQL sur le badge worst-status (`exceptions`=drift/error présents ; `drifted_allowed`=présent sans drift/error ; `compliant`=que compliant et non muet ; `silent`=check-in > 2×ttl). Toujours borné aux enrôlés.
- **AC4 prouvé** : test de double ingestion (drift → compliant) via `ReportIngestService` réel, sur la fiche poste ET le filtre parc.
- **Frontières (AC6)** : `git status` = zéro fichier sous `agent/`/`routes/`/`config/` ni contrat/golden/middleware/StateCompiler/ReportIngestService. Greps : zéro AD/LDAP/Kerberos en prod, zéro hash recalculé hors tests.
- **RESTE (T10)** : (1) opérations VM (migrate + run `--filter Agent` officiel + smoke) — VM `No route to host` pendant tout le dev, à exécuter dès retour ; (2) démo live ws 49 (action humaine Henri, gate palier 1 — runbook QA Section 7 scénario 7.5).

### File List

**Créés :**
- `database/migrations/2026_06_12_120000_add_agent_sync_requested_at_to_workstations.php`
- `app/Services/Agent/SyncRequestService.php`
- `app/Services/Agent/Reporting/ConformityService.php`
- `resources/views/components/atoms/conformity-badge.blade.php`
- `resources/views/pages/parc/machines/[id]/_partials/agent-conformity.blade.php`
- `resources/views/pages/parc/groups/[id]/_partials/conformity-panel.blade.php`
- `tests/Feature/Api/V1/Agent/SyncRequestTest.php`
- `tests/Unit/Services/Agent/SyncRequestServiceTest.php`
- `tests/Unit/Services/Agent/ConformityServiceTest.php`
- `tests/Feature/Livewire/Parc/ParcConformityTest.php`

**Modifiés :**
- `app/Models/Workstation.php` (cast + relations + helpers `hasAgentSyncPending`/`isAgentSilent`)
- `app/Http/Controllers/Api/V1/Agent/StateController.php` (bypass 304 + log)
- `app/Http/Controllers/Api/V1/Agent/ReportController.php` (fulfill au report)
- `app/Providers/AgentServiceProvider.php` (bindings singleton SyncRequest/Conformity)
- `app/Repositories/WorkstationGroupRepository.php` (filtre `conformityFilter` sur getMachines/getMachinesScoped)
- `app/Services/Parc/WorkstationGroupService.php` (paramètre `conformityFilter` threadé)
- `resources/views/pages/parc/index.blade.php` (property `conformityFilter`, `conformityStats`, computed `machineConformity`, reset)
- `resources/views/pages/parc/_partials/stats-cards.blade.php` (rangée compteurs conformité)
- `resources/views/pages/parc/_partials/machines-tab.blade.php` (colonne + filtre conformité)
- `resources/views/pages/parc/machines/[id]/index.blade.php` (actions `forceSyncWorkstation` + computed + include partial)
- `resources/views/pages/parc/groups/[id]/index.blade.php` (actions `forceSyncGroup` + computed + include panel)
- `tests/Feature/Livewire/Parc/MachineShowPageTest.php` (agent tables/colonnes + 5 tests conformité)
- `tests/Feature/Livewire/Parc/GroupShowPageTest.php` (agent tables/colonnes + 2 tests conformité)
- `tests/Feature/Livewire/Parc/GroupSchedulesPageTest.php` (agent tables/colonnes — évite régression render panneau)
- `docs/agent/state-endpoint.md` (addendum bypass 304 force-sync)
- `docs/agent/report-endpoint.md` (addendum solde au report)
- `docs/qa/domains/agent.md` (Section 7 append-only + checklist)
- `docs/qa/README.md` (ligne domaine agent → Story 24.7)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (24-7 → review)

## Change Log

- 2026-06-12 — Story 24.7 DÉVELOPPÉE (DEV claude-opus-4-8, 1 session), ready-for-dev → review. T1-T9 + docs livrés ; conformité intégrée aux 3 pages parc (compteurs+badge+filtre iso-16.13bis, détail par type+événements datés, panneau groupe règles→exceptions) + « forcer la synchro » PULL (colonne `agent_sync_requested_at` 2-écrivains, bypass 304 zéro-write GET /state tous contextes, solde au POST /report). Services `SyncRequestService` + `ConformityService` (singletons), badge réutilisable `x-atoms.conformity-badge`. 30 nouveaux tests verts sur l'hôte (SQLite) + suite Parc Livewire 83/83 (régression `GroupSchedulesPageTest` évitée). Agent Go 2.1.x, contrat, middleware, golden, `ReportIngestService`, `StateCompiler` INTOUCHÉS (vérifié `git status` + greps AD/hash). RESTE T10 : opérations VM (VM injoignable au dev — `No route to host`) + démo live ws 49 (action humaine Henri, gate palier 1).

- 2026-06-12 — Story 24.7 créée (SM/create-story) : conformité intégrée aux pages parc (compteurs + badge/filtre iso-16.13bis, détail poste par type + événements datés, panneau groupe règles→exceptions) + « forcer la synchro » en sémantique PULL tranchée (colonne `agent_sync_requested_at`, bypass 304 sur GET /state tous contextes pendant demande, solde au POST /report — l'agent Go 2.1.1 reste intouché, vérifié : il ignore `ttl_seconds` pour sa planification). Ajustement serveur cadré minimal (StateController bypass zéro-write + ReportController fulfill). Gate palier 1 : démo live répétable, action humaine ws 49 en T10. Status backlog → ready-for-dev.

## Recommandation Modèle Dev

**opus.** Jugé sur la complexité réelle de CETTE story (consigne Henri : pas de réflexe « UI = petit modèle », mais pas l'inverse non plus) : le gros du travail est de l'UI Livewire sur des patterns déjà éprouvés et explicitement référencés (trio 16.13bis, card Agent 23.2, partials groupe), plus deux petits services de lecture/écriture et une migration d'une colonne. La seule zone sensible — le bypass 304 dans le canal agent et son solde au report — est entièrement spécifiée dans la story (décisions n° 1-2, pièges 3-4, matrice de tests AC7) : le dev n'a aucune latitude de design dessus, et la consigne est STOP + remonter s'il fallait toucher le canal au-delà des deux points d'extension nommés. C'est le partage prévu de longue date par l'epic lui-même (les recos des stories 24.1 et 24.5 réservaient déjà `opus` à « l'UI Livewire 24.7 » ; `fable` aux stories contrat/hash/système). Point de vigilance pour la review : les deux pages SFC volumineuses (régressions tabs/état) et le zéro-write du GET.
