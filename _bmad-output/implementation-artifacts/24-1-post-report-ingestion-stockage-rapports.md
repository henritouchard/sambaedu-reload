# Story 24.1: POST /api/v1/agent/report — ingestion et stockage des rapports

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **que les rapports d'état des postes soient ingérés et stockés en volume borné**,
afin que **la conformité du parc soit connue du serveur sans le noyer**.

## Contexte & intention

Première story de l'**Epic 24** (« La boucle fermée en lab — agent de convergence MVP », gate palier 1). L'Epic 23 est **done 5/5 + validé e2e par Henri** (curl/jq sur /vm + install iPXE réelle) : le canal agent existe de bout en bout côté serveur — contrat v1 figé (23.1), token/rotation/anti-clone (23.2), enrôlement porte 1 (23.3), StateCompiler (23.4), `GET /state` ETag/304 (23.5). Cette story livre **le chemin retour** : `POST /api/v1/agent/report` (FR8) + le stockage D3 (FR9) — état courant upserté + journal d'événements + historique de débogage derrière flag.

Le **schéma du rapport est DÉJÀ figé** (gap 2 résolu en 23.1) : `docs/agent/contract-v1.md` §6 + golden file `tests/Fixtures/Agent/report.v1.json`. Cette story n'invente AUCUN wire format — elle implémente l'ingestion du format existant. Consommé ensuite par : l'agent squelette 24.2 (qui POSTera ses rapports), l'UI conformité 24.5/FR10, le « forcer la synchro » FR11.

Avant tout agent réel, la valeur autonome immédiate : un rapport est **postable en curl** depuis un poste enrôlé (token 23.3) et l'état de conformité devient **interrogeable en SQL/Tinker** — la moitié serveur de la démo « UI → état → agent → rapport → UI ».

## ⚠️ Pièges connus (lire avant de coder)

1. **`contract-v1.md` + golden files + `FROZEN_STATE_HASH` (`6c0e8135…`) sont FIGÉS — intouchables.** Le golden `report.v1.json` est l'artefact normatif de ce qu'on ingère : un feature test DOIT poster le payload du golden tel quel et obtenir 200. Toute doc nouvelle va dans un fichier NEUF (`docs/agent/report-endpoint.md`), jamais dans `contract-v1.md`.
2. **DEFER hérité de la review 23.1 (à résoudre ICI)** : valider l'entrée agent **AVANT** tout appel `StateHasher` — `JsonException` (UTF-8 invalide / NAN / INF) non catchée = 500 possible sur rapport forgé. Design retenu : l'ingestion **ne hashe jamais le payload agent** (comparaisons = égalité de chaînes sur hashes opaques) + validation FormRequest stricte en amont ; test de régression : body JSON malformé / UTF-8 invalide → **422 (ou 4xx), jamais 500**.
3. **Fenêtre 1500 chars de `routes/api.php`** (`ScriptsOsNamespaceTest::route_script_execution_logs_is_protected…` cherche `auth.v1.workstation` dans les 1500 chars précédant la déclaration de route 16.12) : la route POST report s'ajoute **à la FIN du bloc agent 23.3/23.5** (juste après `agent.v1.state`, le commentaire du bloc annonce déjà « Futurs endpoints du canal (24.1 report) ») — **jamais** avant le groupe 16.12. Piège confirmé deux fois (23.3, 23.5).
4. **`X-Agent-New-Token` doit survivre à la réponse du report** (invariant D5) : le middleware pose le header APRÈS `$next($request)` (`AuthenticateAgentToken.php:164-167`) donc ça marche par construction — mais c'est un invariant de sécurité du recouvrement : **test obligatoire** (rotation due + POST report → 200 avec header), pas une confiance.
5. **`agent_last_checkin_at` est DÉJÀ géré par le middleware** (23.2) : le controller/service n'y touche PAS (zéro write sur `workstations` dans cette story). L'AC « màj agent_last_checkin_at » est satisfait par le middleware — le test l'asserte, le code ne le duplique pas.
6. **VM : migration + nouvelle route = `php artisan migrate` + `config:cache` + `route:cache` + chown www-admin OBLIGATOIRES** (`bootstrap/cache/` non synchronisé par inotify — 18/18 tests en 404 au premier run 23.5 pour cette raison). inotify ne propage pas les suppressions de fichiers.
7. **Tests SQLite n'appliquent pas les varchar** (memory projet) : un overflow PG 22001 est invisible en test. `status` = domaine fermé validé par enum AVANT écriture ; `detail` = colonne `text` (pas varchar) ; `type`/`hash` bornés par la validation (`in:` types publiés, regex hex 64).
8. **Réponse du report = wrapper SE5 `{success, …}`, PAS l'enveloppe brute.** Seul `GET /state` sert le contrat brut (l'ETag est calculé dessus). Ici c'est un ack serveur→agent : format SE5 standard, l'agent n'a besoin que du 2xx. Erreurs 401/403 = formats du middleware 23.2, intouchés ; 422 = format ValidationException Laravel (`{message, errors}` — le « détail » exigé par l'AC).
9. **Volume borné = contrainte structurelle, pas une promesse** : UNIQUE `(workstation_id, type)` sur `agent_resource_states` + validation `items.*.type` dans la liste **fermée** des 9 identifiants publiés (§7 contrat) + `distinct` (pas deux items du même type dans un rapport). Sans ça, un agent forgé gonfle la table à l'infini.

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Identité = le token, jamais le payload.** Le bloc `workstation {hostname, uuid}` du rapport est **déclaratif** (debug agent) : il est validé en forme mais **jamais utilisé pour résoudre le poste** (le poste = `$request->attributes->get('agent.workstation')` posé par le middleware). Divergence hostname/uuid déclaré vs poste authentifié → log `warning` (contexte tronqué `Str::limit`), ingestion **poursuit** (l'anti-clonage MAC est le travail du middleware 23.2, pas du report).
2. **Règle de création d'événement** (D3 : « seuls les événements de changement — dérive détectée/corrigée, apply échoué ») : pour chaque item, comparer `(status, hash)` entrant à la ligne `agent_resource_states` existante :
   - ligne absente (premier rapport) : événement **seulement si** `status ≠ compliant` (un premier « tout va bien » n'est pas un changement) ;
   - ligne présente, `(status, hash)` identiques : **AUCUN événement** (rapport identique — AC epic) ;
   - ligne présente, `(status, hash)` différents : événement **sauf** transition `compliant → compliant` (la cible a bougé et l'agent a convergé silencieusement — pas une dérive ; le hash de la ligne d'état est mis à jour, c'est suffisant).
   L'événement porte `previous_status` (null si ligne absente), `status`, `hash`, `detail`.
3. **Trois tables, une migration** (`create_agent_report_tables`) : `agent_resource_states` (état courant, UNIQUE (workstation_id, type)), `agent_report_events` (journal, rétention 14 j), `agent_report_history` (payload brut jsonb, append-only, flag off, rétention 30 j). L'architecture ne nomme que les 2 premiers modèles mais D3 distingue explicitement « journal des événements » et « historique complet append-only derrière flag » → table dédiée, supprimable d'un bloc à la sortie de débogage (retrait prévu par D3).
4. **`reported_at` est rafraîchi à CHAQUE rapport** (même identique) sur la ligne d'état — la fraîcheur du dernier rapport est une donnée UI (24.5) ; « identique » n'inhibe que la création d'ÉVÉNEMENT, pas l'horodatage.
5. **Purge = commande artisan `agent:reports:prune`** (iso-pattern `error-logs:prune` / `PruneErrorLogsCommand`), planifiée daily dans `app/Console/Kernel.php` (`runInBackground`, hors heures chargées, iso conventions du fichier). Elle purge `agent_report_events` (> `report_events_retention_days`) ET `agent_report_history` (> `report_history_retention_days`) — la purge events tourne toujours, la purge history aussi (vide si flag off, et nettoie les résidus d'une phase de debug terminée). Les clés `config/agent.php` **existent déjà** (23.5, planchers `max(1, …)`) : les **consommer, ne pas les recréer**.
6. **Aucun recalcul de hash, nulle part.** Les hashes du rapport sont les hashes opaques `StateHasher` émis par `GET /state` ; l'ingestion les stocke et les compare en **égalité de chaînes**. Si un jour le serveur veut confronter un rapport à la cible courante, il prendra les hashes du `StateCompiler` (qui délègue à `StateHasher`) — jamais un `hash('sha256', …)` ad hoc (anti-pattern bloquant, architecture).
7. **Liste fermée des types acceptés** : constante additive `StateContract::RESOURCE_TYPES` (les 9 identifiants §7 : `wallpaper`, `overlay`, `shortcuts`, `printers`, `drives`, `associations`, `registry`, `app_config`, `applications`) — la classe `StateContract` n'est pas un artefact figé (seuls golden files + `contract-v1.md` + hash le sont), un ajout de constante est licite. La validation `in:` s'y adosse ; un type inconnu → 422 (un nouveau type = bump de contrat de toute façon).
8. **Chaîne middleware iso 23.5** : `['auth.v1.secure-headers', 'throttle:60,1', 'agent.token']` — réponses 401 `AGENT_TOKEN_MISSING`/`AGENT_TOKEN_INVALID`, 403 `AGENT_QUARANTINED` du middleware existant, intouché. Pas de `local.request` (l'auth EST le token).
9. **`items: []` est valide** (200) : agent sans rien à rapporter — màj check-in (middleware), zéro ligne, zéro événement. `schema` ≠ `se5.desired-state/v1` exact → 422 (le serveur refuse un schéma inconnu, miroir de « l'agent refuse un major inconnu »).
10. **Transaction DB autour de l'ingestion complète** (état + événements + history) : un rapport est atomique — un échec à mi-course ne laisse pas un état sans son événement. Pas de `lockForUpdate` (un seul agent par poste, séquentiel par design FR18).

## Acceptance Criteria

### AC1 — Ingestion nominale : upsert borné + check-in (FR8, FR9)

**Given** un poste authentifié (token 23.2/23.3) envoyant un rapport conforme au golden file de 23.1
**When** `POST /api/v1/agent/report`
**Then** `ReportIngestService` upserte `agent_resource_states` par `(workstation, type)` : statut `compliant|drift|drifted_allowed|error` + hash + horodatage — « conforme » = 1 ligne, et N rapports successifs du même poste laissent **au plus 1 ligne par type** (volume borné postes × types, garanti par UNIQUE + validation)
**And** `agent_last_checkin_at` est mis à jour (par le middleware — le code de la story n'écrit pas sur `workstations`, le test l'asserte)
**And** le payload exact du golden `report.v1.json` est accepté (200) — test obligatoire.

### AC2 — Journal d'événements : changements seulement (FR9, D3)

**Given** un changement d'état (dérive détectée, dérive corrigée, apply échoué — règle décision n° 2)
**Then** un événement est journalisé dans `agent_report_events` (workstation, type, previous_status, status, hash, detail)
**And** un rapport **identique au précédent** (mêmes `(status, hash)` par item) ne crée **AUCUN** événement — mais `reported_at` de la ligne d'état est rafraîchi (décision n° 4)
**And** un premier rapport `compliant` ne crée pas d'événement ; un premier rapport `drift`/`error` en crée un.

### AC3 — Flag AGENT_REPORT_HISTORY : historique append-only + purge (FR9, D3)

**Given** le flag `AGENT_REPORT_HISTORY` activé (`config('agent.report_history')` — défaut **off**)
**Then** chaque rapport accepté est conservé intégralement (payload brut) en append-only dans `agent_report_history`
**And** flag off (défaut) → aucune ligne d'historique n'est écrite
**And** la commande `agent:reports:prune` (planifiée daily) purge `agent_report_events` au-delà de `report_events_retention_days` (14 j) et `agent_report_history` au-delà de `report_history_retention_days` (30 j) — clés **existantes** de `config/agent.php` (23.5), consommées, pas recréées.

### AC4 — Rapport malformé : 422, rien n'est écrit (FR8)

**Given** un rapport malformé (schema inconnu, `items` absent/mal typé, `status` hors enum, `hash` non hex-64, `detail` absent ou vide quand `status=error`, type hors liste publiée, types dupliqués)
**Then** 422 avec détail (format ValidationException), **aucune écriture** (ni état, ni événement, ni history — assertions DB)
**And** un body JSON malformé ou contenant de l'UTF-8 invalide → 4xx **jamais 500** (defer review 23.1 résolu : validation avant tout traitement, aucun `StateHasher` sur l'entrée agent)
**And** tout hash manipulé est comparé en égalité de chaînes opaques issues du même `StateHasher` que l'ETag — **zéro** recalcul ad hoc (grep en review : pas de `hash(` ni `md5(` dans le code livré hors tests).

### AC5 — Logs & observabilité (FR27)

**Then** channel `agent` : `agent.report.received` (info, contexte `workstation_id` + compte d'items par statut) sur chaque rapport accepté ; `agent.report.drift` (warning, contexte `workstation_id` + `type`) pour chaque item créant un événement de dérive (`drift`/`error`/`drifted_allowed` entrant) — pas de spam sur rapport identique
**And** divergence identité déclarée vs poste authentifié → log warning, ingestion poursuivie (décision n° 1)
**And** jamais de payload complet ni de token dans les logs (conventions 23.2-23.5).

### AC6 — Sécurité du canal : middleware, rotation, throttle

**Given** la route réelle `POST /api/v1/agent/report` (nom `agent.v1.report`, fin du bloc agent — piège n° 3)
**Then** chaîne `['auth.v1.secure-headers', 'throttle:60,1', 'agent.token']` ; sans bearer → 401 `AGENT_TOKEN_MISSING`, token invalide → 401 `AGENT_TOKEN_INVALID`, quarantaine → 403 `AGENT_QUARANTINED` (formats middleware intouchés)
**And** rotation due → la réponse 200 du report porte `X-Agent-New-Token` (invariant D5, testé — piège n° 4)
**And** poste en quarantaine : **aucune** écriture de rapport (le middleware coupe avant le controller).

### AC7 — Tests

**Then** `tests/Feature/Api/V1/Agent/ReportEndpointTest.php` (RefreshDatabase, conventions `StateEndpointTest`/`AuthenticateAgentTokenTest` : `Workstation::factory()` + `TokenRotationService::issueFor()` + helper privé `report()`) couvre : golden payload → 200 ; upsert borné (2 rapports → 1 ligne/type, `reported_at` rafraîchi) ; matrice événements (premier compliant = 0, premier drift = 1, transitions drift→compliant / compliant→drift / →error, identique = 0, compliant→compliant hash changé = 0) ; history off/on + contenu ; 422 × cas AC4 + assertions « rien écrit » ; JSON malformé / UTF-8 invalide ≠ 500 ; 401 missing/invalid ; 403 quarantaine ; X-Agent-New-Token sur 200 ; check-in stampé par le middleware
**And** `tests/Unit/Services/Agent/ReportIngestServiceTest.php` (logique événements + history, sans HTTP) et un test de la commande de purge (lignes antidatées → purgées, récentes → conservées, les deux rétentions)
**And** `php artisan test --filter Agent` intégralement vert sur `/vm` — baseline **121 passed** (post-23.5) + les nouveaux, golden files et `FROZEN_STATE_HASH` intouchés. **UNIQUEMENT ce filtre** — jamais la suite complète (décision Henri).

### AC8 — Transversal : frontières, doc, VM

**Then** aucun appel AD/LdapRecord/Kerberos/APCu (critère Keycloak, grep en review) ; aucune logique métier dans le controller (couche Services) ; le canal agent n'écrit QUE dans `agent_*` (zéro write `workstations` hors middleware existant) ; StateProviders/StateCompiler/middleware/`contract-v1.md`/golden files **intouchés**
**And** `docs/agent/report-endpoint.md` (NEUF) documente : URL/méthode/middlewares, identité = token (décision n° 1), règle d'événement (décision n° 2), tables et rétentions D3, flag history, codes 200/401/403/422/429, consignes agent (Epic 24 : renvoyer le hash reçu tel quel, `detail` obligatoire sur error)
**And** `docs/qa/domains/agent.md` (NEUF — le domaine n'existe pas encore, vérifié) créé selon la convention `docs/qa/README.md` (scénarios numérotés stables, structure iso `rights-management.md`) + ligne ajoutée à « Domaines couverts » du README ; scénarios curl report sur poste enrôlé VM
**And** opérations VM exécutées et tracées : `php artisan migrate` + `config:cache` + `route:cache` + chown www-admin (`bootstrap/cache/`), smoke curl POST sans token → 401 JSON (route vivante derrière le cache).

## Tasks / Subtasks

- [x] **T1 — Migration `create_agent_report_tables`** (AC1, AC2, AC3)
  - [x] `database/migrations/2026_06_11_…_create_agent_report_tables.php` (datée après `130000`, style guard `Schema::hasTable`, docblock iso migrations 23.2/23.3) :
    - `agent_resource_states` : id ; `workstation_id` FK `constrained()->cascadeOnDelete()` ; `type` string(64) ; `status` string(32) ; `hash` string(64) ; `detail` **text** nullable ; `reported_at` timestamp ; timestamps ; **UNIQUE (`workstation_id`, `type`)** ; index `status`.
    - `agent_report_events` : id ; `workstation_id` FK cascade ; `type` string(64) ; `previous_status` string(32) nullable ; `status` string(32) ; `hash` string(64) ; `detail` text nullable ; `created_at` timestamp (pas d'updated_at — append-only) ; index (`workstation_id`, `type`), index `created_at` (purge).
    - `agent_report_history` : id ; `workstation_id` FK cascade ; `payload` json (jsonb PG) ; `created_at` timestamp ; index `created_at` (purge).
- [x] **T2 — Modèles** (AC1, AC2, AC3)
  - [x] `app/Models/AgentResourceState.php` — `$fillable`, casts `status => AgentResourceStatus::class`, `reported_at => datetime` ; relation `workstation()`.
  - [x] `app/Models/AgentReportEvent.php` — append-only (`UPDATED_AT = null`), casts statuts.
  - [x] `app/Models/AgentReportHistory.php` — `UPDATED_AT = null`, cast `payload => array`.
- [x] **T3 — Constante des types publiés** (AC4, décision n° 7)
  - [x] `StateContract::RESOURCE_TYPES` (list des 9 identifiants §7) + docblock « figés une fois publiés, NFR12 ». Addition pure — aucun fichier figé touché.
- [x] **T4 — FormRequest `ReportRequest`** (AC4)
  - [x] `app/Http/Requests/Api/V1/Agent/ReportRequest.php` (iso `EnrollmentRequest` : `authorize() => true`, l'auth est le middleware). Rules : `schema` required + `in:` `StateContract::SCHEMA` ; `generated_at` required|date ; `agent_version` required|string|max:32 ; `workstation` nullable|array, `workstation.hostname` nullable|string|max:255, `workstation.uuid` nullable|string|max:64 ; `items` present|array ; `items.*.type` required|string + `Rule::in(StateContract::RESOURCE_TYPES)` + `distinct` ; `items.*.status` required + `Rule::enum(AgentResourceStatus::class)` ; `items.*.hash` required + `regex:/^[0-9a-f]{64}$/` ; `items.*.detail` string|max:2000 + `required_if:items.*.status,error`. Champs inconnus **ignorés** (règle d'évolution : champ ajouté = mineur — ne pas rejeter l'inconnu).
- [x] **T5 — `ReportIngestService`** (AC1, AC2, AC3, AC5)
  - [x] `app/Services/Agent/Reporting/ReportIngestService.php` — `ingest(Workstation $workstation, array $report): array` (retourne les comptes par statut pour la réponse/logs). `DB::transaction` : par item → lire la ligne `(workstation_id, type)`, appliquer la règle d'événement (décision n° 2), `updateOrCreate` l'état (`status`, `hash`, `detail`, `reported_at = now()`), créer l'événement si dû ; si `config('agent.report_history')` → 1 ligne history (payload validé complet). Logs AC5. **Aucun hash calculé, aucune écriture hors `agent_*`.**
  - [x] Binding singleton dans `AgentServiceProvider::register()` (iso `EnrollmentService`).
- [x] **T6 — Controller + route** (AC1, AC6)
  - [x] `app/Http/Controllers/Api/V1/Agent/ReportController.php` — `store(ReportRequest $request)` mince : workstation depuis `$request->attributes->get('agent.workstation')` ; log warning si identité déclarée divergente (décision n° 1) ; `$counts = $ingest->ingest(...)` ; `response()->json(['success' => true, 'counts' => $counts])`.
  - [x] `routes/api.php` : import aliasé `AgentReportController` (iso `AgentStateController`) ; route POST **après** `agent.v1.state`, fin du bloc agent (piège n° 3) : middleware `['auth.v1.secure-headers', 'throttle:60,1', 'agent.token']`, nom `agent.v1.report` (libre, vérifié — seuls enroll/refresh/ping/enrollment/state/config.* existent). Mettre à jour le commentaire du bloc (« 24.1 report » livré).
- [x] **T7 — Purge** (AC3)
  - [x] `app/Console/Commands/PruneAgentReportsCommand.php` — signature `agent:reports:prune`, lit les 2 rétentions de `config/agent.php`, delete en masse par `created_at`, output des comptes (iso `PruneErrorLogsCommand`).
  - [x] `app/Console/Kernel.php` : `$schedule->command('agent:reports:prune')->dailyAt('02:30')->runInBackground()` + commentaire iso style du fichier (vérifier qu'aucun job lourd ne tourne à la même heure — trash:purge 02h00, snapshot 03h00).
- [x] **T8 — Documentation** (AC8)
  - [x] `docs/agent/report-endpoint.md` (NEUF) — contenu AC8 ; renvoi 1 ligne depuis `docs/agent/state-endpoint.md` (« le rapport remonte par POST /report, voir report-endpoint.md ») si trivial. `contract-v1.md` FIGÉ — zéro édit.
  - [x] `docs/qa/domains/agent.md` (NEUF) + ligne « Domaines couverts » dans `docs/qa/README.md` : pré-requis (poste enrôlé, token), scénarios numérotés (post golden → 200 + ligne SQL ; rapport identique → pas d'événement ; rapport drift → événement + log ; malformé → 422 ; sans token → 401 ; flag history). Append-only pour les stories suivantes de l'epic.
- [x] **T9 — Tests** (AC7)
  - [x] `tests/Feature/Api/V1/Agent/ReportEndpointTest.php` — cas AC7, conventions `StateEndpointTest` (factories, `TokenRotationService::issueFor()`, rotation due via recul `agent_token_rotated_at`, quarantaine via `quarantine()`, mock channel `agent` étendu à error/critical — correctif P2 23.5). Charger le golden via `base_path('tests/Fixtures/Agent/report.v1.json')`.
  - [x] `tests/Unit/Services/Agent/ReportIngestServiceTest.php` — matrice événements complète (décision n° 2), history on/off, comptes retournés.
  - [x] Test purge : lignes events/history antidatées (`created_at` forcé) vs récentes, les 2 rétentions, flag indifférent à la purge.
- [x] **T10 — Vérifications finales + VM** (AC7, AC8)
  - [x] `php -l` sur tous les fichiers créés/modifiés ; grep critère Keycloak (`ldap|kerberos|apcu|samba-tool`) → vide ; grep `hash(`/`md5(` dans le code livré (hors tests) → vide.
  - [x] `/vm` (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, `/var/www/sambaedu-reload`) : `php artisan migrate` → `config:cache` → `route:cache` → chown www-admin ; `php artisan test --filter Agent` (121 baseline + nouveaux, zéro régression — **jamais la suite complète**) ; smoke `curl -X POST http://localhost/api/v1/agent/report` sans token → 401 JSON.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (24.1) | Hors-scope (story) |
|---|---|
| `POST /api/v1/agent/report` + `ReportController` mince | Agent côté poste qui POSTe (boucle, cache) → **24.2/24.3** |
| `ReportIngestService` + 3 tables `agent_*` + modèles | Handlers wallpaper/overlay (qui produisent les vrais statuts) → **24.4** |
| Journal événements + flag history + purge `agent:reports:prune` | UI conformité pages parc (lecture de ces tables) → **24.5 / FR10** |
| Résolution du defer 23.1 (validation avant hash / pas de hash sur l'entrée) | Bouton « forcer la synchro » → **FR11** |
| `StateContract::RESOURCE_TYPES` (addition) | Confrontation rapport ↔ cible courante recompilée (UI/alerting futur) |
| Doc `report-endpoint.md` + domaine QA `agent.md` | Tout édit de `contract-v1.md`, golden files, StateCompiler, providers, middleware |

### Patterns existants à imiter (NE PAS réinventer)

- **Workstation authentifié** : `$request->attributes->get('agent.workstation')` — posé par `AuthenticateAgentToken` (`app/Http/Middleware/AuthenticateAgentToken.php:162`) ; constantes `CODE_TOKEN_MISSING/INVALID/QUARANTINED` (l.56-58), `HEADER_NEW_TOKEN` (l.61) ; `agent_last_checkin_at` écrit par LUI (l.108/152).
- **Controller mince canal agent** : `app/Http/Controllers/Api/V1/Agent/StateController.php` (23.5) — strict_types, injection constructeur, logs channel `agent` avec `action_type` namespacé, `Str::limit` sur tout contexte user-controlled.
- **FormRequest canal agent** : `app/Http/Requests/Api/V1/Agent/EnrollmentRequest.php` (23.3) — `authorize() => true` + commentaire expliquant pourquoi.
- **Service + binding** : `app/Providers/AgentServiceProvider.php` — singletons `TokenRotationService`/`EnrollmentService`/`StateCompiler` ; ajouter `ReportIngestService` au même endroit.
- **Migrations agent** : `2026_06_11_120000_add_agent_token_columns_to_workstations.php` — guards `Schema::hasColumn/hasTable`, docblock détaillé, down() propre.
- **Commande de purge** : `app/Console/Commands/PruneErrorLogsCommand.php` (signature, output) + `app/Console/Kernel.php` (style des entrées schedule, commentaires datés par story).
- **Feature tests canal** : `tests/Feature/Api/V1/Agent/StateEndpointTest.php` + `AuthenticateAgentTokenTest.php` — helper de requête privé, `Workstation::factory()`, `TokenRotationService::issueFor()`, rotation due par recul `agent_token_rotated_at`, mock channel `agent` couvrant debug/info/warning/error/critical (P2 23.5).
- **Enums** : `App\Enums\AgentResourceStatus` (23.1) — `Compliant|Drift|DriftedAllowed|Error`, backed string ; `Rule::enum()` pour la validation, cast Eloquent pour les modèles.

### Architecture — conventions figées applicables (NON négociables)

[Source: architecture-agent-desired-state.md#D3 ; #Naming Patterns ; #Communication Patterns ; #Enforcement Guidelines]

- D3 : état courant upserté (workstation, type), volume borné « conforme = 1 ligne » ; journal des seuls changements, rétention courte ; historique complet derrière `AGENT_REPORT_HISTORY` (défaut off), purge auto, **retrait prévu** à la sortie de débogage.
- Tables préfixe `agent_` ; modèles `AgentResourceState`/`AgentReportEvent` ; service `App\Services\Agent\Reporting\ReportIngestService` ; controller `App\Http\Controllers\Api\V1\Agent\ReportController` ; route `agent.v1.report` ; tests `tests/{Unit/Services,Feature/Api/V1}/Agent/`.
- Le canal agent n'écrit QUE dans `agent_*` (+ colonnes `agent_*` de workstations — réservées au middleware) ; StateProviders lecture seule ; aucune écriture AD.
- `StateHasher` = source unique de TOUT hash du canal (ETag 23.5 ⇄ rapports 24.1) — ici : zéro hash calculé, comparaisons opaques.
- Logs : channel `agent`, `agent.report.received` / `agent.report.drift`, toujours `workstation_id` + `type` quand applicable.
- Codes canal : 200 / 401 / 403 / 422 (+ 429 throttle hors contrat).
- Anti-patterns bloquants : endpoint agent hors `/api/v1/agent/*`, hash ad hoc, logique métier dans le controller, table générique de règles.

### Contrat v1 — invariants consommés ici (FIGÉS)

[Source: docs/agent/contract-v1.md §6 ; tests/Fixtures/Agent/report.v1.json]

- Payload : `{schema, generated_at, agent_version, workstation: {hostname, uuid}, items: [{type, status, hash, detail?}]}`.
- `items[].detail` : **obligatoire et non vide** quand `status = error` ; optionnel sinon.
- `items[].hash` : hash **opaque** de la cible traitée par l'agent — « échoué tel quel, jamais recalculé ».
- Statuts = `App\Enums\AgentResourceStatus` ; types = identifiants figés §7 (9 valeurs).
- Règle d'évolution §9 : champ ajouté = mineur → le serveur **tolère les champs inconnus** (ne pas mettre de validation « exactement ces clés »).

### Project Structure Notes

- **Racine = projet Laravel** ; code édité sur l'hôte, exécuté sur la VM `/vm` ; sync inotify auto (jamais de sync manuel ; si non-synchro → notifier Henri et attendre) ; inotify ne propage PAS les suppressions. Jamais de VM depuis un worktree git.
- Story avec **migration + route + (pas de clé config nouvelle)** → VM : `php artisan migrate` + `config:cache` + `route:cache` + chown www-admin (`bootstrap/cache/` et tout fichier lu par PHP — PHP-FPM tourne en `www-admin`, uid 599).
- `config/agent.php` : les 4 clés report (flag + rétentions, planchers `max(1,…)`) existent depuis 23.5 — **consommer uniquement**.

### Testing standards

- PHPUnit, exécution de référence sur `/vm` ; SQLite `:memory:` en feature (RefreshDatabase) — attention piège n° 7 (varchar non appliqués).
- **Commande prescrite : `php artisan test --filter Agent` UNIQUEMENT** (décision Henri — machine de dev moins puissante). Baseline : **121 passed** (post-23.5). Les 2 failed connus de la suite complète (`WpkgReportApiTest`, `GpoIndexExportTest`) sont hors scope — ne pas y toucher, ne pas lancer la suite complète.
- Antidater des lignes pour la purge : `Model::query()->update(['created_at' => …])` ou création directe avec timestamp forcé (UPDATED_AT = null sur events/history).

### Intelligence stories précédentes

- **23.1 (done)** : schéma report figé + golden ; **defer « valider l'entrée agent avant hashState » → RÉSOLU ICI** (piège n° 2) ; contraintes §4.1 (pas de floats, NFC) concernent les payloads d'état, pas le rapport.
- **23.2 (done)** : middleware = autorité sur check-in/rotation/quarantaine ; formats d'erreur figés ; conventions logs (jamais de secret, `Str::limit` sur contexte user-controlled).
- **23.3 (done)** : piège fenêtre 1500 chars confirmé ; smoke HTTP réel sur VM = pratique validée ; FormRequest + service + controller mince = le trio du canal.
- **23.4 (done)** : conventions tests par tables métier réelles ; le compilateur reste le SEUL porteur de D2 — l'ingestion ne recompile rien.
- **23.5 (done)** : chaîne middleware réutilisée telle quelle ; piège cache routes VM vécu (18/18 en 404) ; mock logs étendu error/critical (P2) ; X-Agent-New-Token sur réponse non-200 testé — refaire l'équivalent sur le POST.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 24.1] — ACs source, FR8/FR9.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#D3 ; #Naming Patterns ; #Structure (delta) ; #Communication Patterns] — tables/modèles/service/logs, D3 intégral.
- [Source: docs/agent/contract-v1.md §6, §7, §9] — schéma report FIGÉ, types publiés, règle d'évolution.
- [Source: tests/Fixtures/Agent/report.v1.json] — golden normatif (hash `FROZEN_STATE_HASH` du state intouchable).
- [Source: app/Http/Middleware/AuthenticateAgentToken.php:56-61, 108, 152, 162-167] — codes, check-in, attribut, header rotation.
- [Source: app/Http/Controllers/Api/V1/Agent/StateController.php ; app/Http/Requests/Api/V1/Agent/EnrollmentRequest.php] — patterns controller/FormRequest du canal.
- [Source: config/agent.php] — `report_history`, `report_events_retention_days` (14), `report_history_retention_days` (30) — existants.
- [Source: app/Console/Commands/PruneErrorLogsCommand.php ; app/Console/Kernel.php:52-78] — pattern purge planifiée.
- [Source: routes/api.php:228-260 ; tests/Architecture/ScriptsOsNamespaceTest.php:88-92] — emplacement bloc agent, fenêtre 1500 chars.
- [Source: docs/qa/README.md#Convention ; docs/qa/domains/rights-management.md] — structure du domaine QA à créer.

## Dev Agent Record

### Agent Model Used

claude-fable-5 (workflow dev-story, branche main).

### Debug Log References

- VM : `php artisan migrate` (2026_06_11_140000 DONE 140 ms) → `config:cache` → `route:cache` → chown www-admin (`bootstrap/cache/` + fichiers nouveaux).
- `php artisan test --filter Agent` sur /vm : **167 passed (650 assertions)** — baseline 121, +46 nouveaux, zéro régression. Jamais la suite complète (décision Henri).
- Smoke curl `POST http://localhost/api/v1/agent/report` sans token → `401 {"error":"unauthorized","code":"AGENT_TOKEN_MISSING"}` (route vivante derrière le cache routes).
- `php -l` via SSH sur les 15 fichiers PHP créés/modifiés : OK. Greps de revue : `ldap|kerberos|apcu|samba-tool` → vide ; `hash(`/`md5(` dans le code livré hors tests → vide.

### Completion Notes List

- **Defer review 23.1 RÉSOLU par design** : l'ingestion ne hashe JAMAIS le payload agent — validation `ReportRequest` stricte en amont (422 avant toute écriture), hashes stockés/comparés en égalité de chaînes opaques. Tests de régression : JSON malformé et UTF-8 invalide → 4xx jamais 500 + assertions « rien écrit ».
- **Règle d'événement (décision n° 2) implémentée telle quelle** : ligne absente → événement ssi `status ≠ compliant` ; `(status, hash)` identiques → aucun événement (mais `reported_at` rafraîchi) ; différents → événement sauf `compliant → compliant` (matrice complète couverte en unit).
- **Volume borné structurel** : UNIQUE `(workstation_id, type)` + `Rule::in(StateContract::RESOURCE_TYPES)` (constante ADDITIVE, 9 identifiants §7) + `distinct`.
- **Invariant D5 testé deux fois** : `X-Agent-New-Token` survit au 200 du POST (rotation due) ET à un 422 (durcissement au-delà de l'AC — une rotation émise sur rapport forgé ne doit pas être perdue).
- **Zéro write hors `agent_*`** : `agent_last_checkin_at` = middleware (asserté par test, pas dupliqué) ; controller mince (warning identité déclarée divergente → ingestion poursuit, décision n° 1) ; `DB::transaction` autour de l'ingestion complète ; logs `agent.report.received`/`agent.report.drift` émis APRÈS commit (pas de trace d'un rollback).
- **Purge `agent:reports:prune`** daily 02:35 (fenêtre entre trash:purge 02:00 et quota:snapshot 03:00 ; décalée de federated:purge-identities 02:30 suite review #3) — consomme les clés EXISTANTES de `config/agent.php`, purge history inconditionnelle au flag.
- **Fichiers FIGÉS intouchés** : `contract-v1.md`, golden files, `FROZEN_STATE_HASH 6c0e8135…`, StateCompiler/StateProviders/middleware. `StateContract::RESOURCE_TYPES` = addition pure.
- Réponse 200 = wrapper SE5 `{success, counts}` (comptes par statut, zéros inclus — forme stable pour l'agent 24.2) ; `items: []` valide.
- Modèle `AgentReportHistory` : `$table` explicite (nom singulier D3, pas le pluriel Eloquent `histories`).

### File List

**Créés :**
- database/migrations/2026_06_11_140000_create_agent_report_tables.php
- app/Models/AgentResourceState.php
- app/Models/AgentReportEvent.php
- app/Models/AgentReportHistory.php
- app/Http/Requests/Api/V1/Agent/ReportRequest.php
- app/Services/Agent/Reporting/ReportIngestService.php
- app/Http/Controllers/Api/V1/Agent/ReportController.php
- app/Console/Commands/PruneAgentReportsCommand.php
- docs/agent/report-endpoint.md
- docs/qa/domains/agent.md
- tests/Feature/Api/V1/Agent/ReportEndpointTest.php
- tests/Unit/Services/Agent/ReportIngestServiceTest.php
- tests/Feature/Console/PruneAgentReportsCommandTest.php

**Modifiés :**
- app/Services/Agent/StateContract.php (addition constante `RESOURCE_TYPES`)
- app/Providers/AgentServiceProvider.php (binding singleton `ReportIngestService` + docblock)
- routes/api.php (import aliasé `AgentReportController`, route `agent.v1.report` FIN du bloc agent, commentaire du bloc mis à jour)
- app/Console/Kernel.php (schedule `agent:reports:prune` dailyAt 02:35)
- docs/agent/state-endpoint.md (renvoi 2 lignes vers report-endpoint.md)
- docs/qa/README.md (ligne domaine `agent` dans « Domaines couverts »)

### Corrections post-review (2e reviewer claude-fable-5, 2026-06-11)

- **#1 (pertinence 2)** — `ReportRequest` : regex hash passée en `/^[0-9a-f]{64}$/D`. Le vecteur HTTP réel est en fait neutralisé par le middleware global `TrimStrings` (\n traîné trimé avant validation) mais la règle ne doit pas dépendre d'un middleware global → `/D` en défense en profondeur + test `hash_rule_rejects_trailing_newline_independently_of_trim_middleware` (validation des rules hors pipeline HTTP).
- **#3 (pertinence 2)** — `Kernel.php` : `agent:reports:prune` décalé 02:30 → 02:35 (collision `federated:purge-identities` 02:30), commentaire et `docs/agent/report-endpoint.md` mis à jour.
- **#5 (pertinence 3)** — `ReportIngestService` : `lockForUpdate` sur la ligne `workstations` en tête de transaction (pattern middleware 23.2) — deux POST concurrents du même poste ne peuvent plus courser l'`updateOrCreate` (UNIQUE workstation_id+type → 500). Docblock classe corrigé.
- **#7 (pertinence 2)** — `ReportIngestServiceTest` : `assertSame($report, $history->payload)` → `assertEquals` (jsonb PG réordonne les clés).
- **#2, #4, #8** laissés à l'arbitrage Henri (history brut vs validé ; suppression d'événement étendue aux statuts stables ; FQDN vs nom court — à traiter de façon cohérente avec le middleware 23.2 si retenu). **#6** : observation sans action — le detail de l'erreur guérie reste lisible dans l'événement d'erreur précédent (`agent_report_events`).
- Fichiers touchés : ReportRequest.php, ReportIngestService.php, Kernel.php, docs/agent/report-endpoint.md, ReportEndpointTest.php, ReportIngestServiceTest.php.
- Tests post-corrections sur /vm : `--filter Agent` → **168 passed (652 assertions)** (167 baseline + 1 nouveau test).

## Change Log

- 2026-06-11 — Story 24.1 créée (SM/orchestrateur) : ingestion `POST /api/v1/agent/report` + stockage D3 (3 tables, événements, flag history, purge), defer 23.1 résolu par design, schéma report figé 23.1 consommé tel quel. Status backlog → ready-for-dev.
- 2026-06-11 — Story 24.1 DÉVELOPPÉE (DEV claude-fable-5, dev-story) : T1-T10 livrés, 8/8 AC satisfaits, 46 nouveaux tests (--filter Agent : 167 passed / 650 assertions sur /vm, baseline 121, zéro régression), opérations VM faites (migrate + config:cache + route:cache + chown + smoke curl 401). Status ready-for-dev → review.

## Recommandation Modèle Dev

**fable.** Le raisonnement d'Henri pour l'Epic 23 (cohérence du contrat serveur⇄agent) s'étend pleinement à 24.1 : c'est l'autre moitié du même contrat figé (golden `report.v1.json`, hashes opaques `StateHasher`, invariant D5 sur le POST), avec en plus une logique d'événements à cas limites (matrice premier-rapport/transition/identique/compliant→compliant), un defer de sécurité à résoudre (422 jamais 500 sur payload forgé), des frontières d'écriture strictes (`agent_*` only) et ~10 fichiers touchés (migration, 3 modèles, FormRequest, service, controller, route, commande, scheduler, 2 docs, 3 tests). C'est une story d'intégration sécurité/contrat, pas un CRUD — `opus` se justifierait pour 24.5 (UI conformité), pas ici.
