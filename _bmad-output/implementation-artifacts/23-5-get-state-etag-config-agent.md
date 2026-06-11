# Story 23.5: GET /api/v1/agent/state — l'état cible servi, ETag/304, config agent

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant que **mainteneur (et bientôt l'agent)**,
je veux **tirer l'état cible compilé d'un poste authentifié, avec réponse conditionnelle**,
afin de **diagnostiquer l'état attendu de n'importe quel poste et permettre des check-ins légers**.

## Contexte & intention

Dernière story de l'Epic 23 — elle **branche tout ce qui précède** : le contrat v1 (23.1, done), l'auth du canal (23.2, done), l'enrôlement porte 1 (23.3, done) et le compilateur (23.4, done). Livrable : le premier endpoint authentifié du canal agent neuf, `GET /api/v1/agent/state`, qui sert l'enveloppe `se5.desired-state/v1` compilée pour (poste authentifié, user optionnel), avec `ETag`/`If-None-Match` → 304 sans corps (FR1, FR6) ; plus la complétion de `config/agent.php` qui **résout le gap 4 de l'architecture** (valeurs de rétention fixées) et le defer review 23.4 (lecture `ttl_seconds` robuste).

Valeur autonome immédiate (epic) : l'état compilé devient **consultable et diagnosticable en curl/jq** avant qu'aucun agent n'existe — iso-POC overlay. Consommé ensuite par : l'agent squelette 24.2 (`GET /state` + `If-None-Match` au boot/timer), le compagnon 24.3 (même endpoint avec le user de session), le rapport 24.1 (mêmes hashes via `StateHasher`).

**Prérequis satisfaits : 23.1→23.4 toutes done** (23.4 clôturée 2026-06-11 post-review adversariale : 103 passed, correctifs P1 `LogicException` morph map + P2 récence microsecondes appliqués). Note Henri à la clôture 23.4 : **la validation humaine e2e de l'Epic 23 est différée après 23.5** — cette story est donc celle qui rend l'e2e possible (curl/jq contre la VM). Le code fait foi sur le papier s'ils divergent.

## ⚠️ Huit pièges découverts à l'analyse (lire avant de coder)

1. **La réponse 200 = l'enveloppe contrat BRUTE, jamais le wrapper SE5 `{success, …}`.** L'agent parse le contrat v1, pas une réponse SE5 : le corps est exactement le retour de `StateCompiler::compile()` — `{schema, generated_at, ttl_seconds, machine, session, machine_user}`, rien autour. Un wrapper casserait le golden file ET fausserait l'ETag (`hashState` est calculé sur l'enveloppe). Les erreurs 401/403 gardent le format du middleware `{error, message, code}` (figé 23.2, intouché).
2. **ETag HTTP = valeur QUOTÉE (RFC 7232).** `$response->setEtag($hash)` produit `ETag: "6c0e…"` — l'AC epic « ETag = hash StateHasher » se lit modulo les guillemets. Ne PAS comparer `If-None-Match` à la main : construire la réponse 200, `setEtag()`, puis `$response->isNotModified($request)` (Symfony gère guillemets, `W/`, listes, `*`, et transforme en 304 corps vide en conservant l'ETag). Le hash reste opaque de bout en bout : l'agent stocke le header verbatim et le renvoie verbatim (à documenter pour Epic 24).
3. **Le 304 doit conserver `X-Agent-New-Token`.** Cas réel : rotation due + état inchangé (réponse de rotation perdue puis re-check-in → ré-émission systématique 23.2). Le middleware pose le header APRÈS `$next($request)` (`AuthenticateAgentToken.php:164-167`) donc ça marche par construction — mais c'est un invariant de sécurité du recouvrement D5 : **test obligatoire**, pas une confiance.
4. **`(int) config('agent.ttl_seconds', 3600)` rend `0` si la clé vaut `null`** (defer review 23.4) : le défaut de `config()` ne couvre que l'ABSENCE de clé, pas une clé nulle (`.env` vide → `null`). Double correctif : créer la clé avec plancher dans `config/agent.php` ET durcir la lecture du compilateur (`max(1, (int) (config('agent.ttl_seconds') ?? 3600))`) — seul edit autorisé de `StateCompiler`.
5. **`user=null` ne vide PAS mécaniquement les portées `session`/`machine_user`.** Le scope est déclaré PAR TYPE (wallpaper = `session`), pas par maille : un wallpaper ciblé WG/poste/broadcast sort en portée `session` même sans user. L'AC epic « session/machine_user vides » se lit « vides de toute contribution user » (décision n° 2 ci-dessous) — **ne JAMAIS tronquer les portées dans le controller** (logique de compilation hors controller + D2 = compilateur seul, anti-pattern bloquant).
6. **Fenêtre 1500 chars de `routes/api.php`** (`ScriptsOsNamespaceTest`) : la route GET s'ajoute à la FIN du bloc 23.3 (après la ligne ~250, le commentaire « Futurs endpoints du canal (23.5 GET /state…) » marque l'emplacement) — jamais avant le groupe 16.12.
7. **VM : route réelle + config modifiée = `route:cache` + `config:cache` + chown www-admin OBLIGATOIRES.** Le cache `bootstrap/cache/` n'est pas synchronisé par inotify : sans ces commandes, la route fraîche est absente d'un `routes-v7.php` stale (404 navigateur, piège 23.3) et les nouvelles clés config valent `null` (tests verts mais VM KO).
8. **`contract-v1.md` + golden files restent FIGÉS.** Le contrat ne documente pas la couche HTTP (ETag/304 volontairement absents) : la doc endpoint va dans un fichier NEUF `docs/agent/state-endpoint.md`. Hash figé `6c0e8135…` intouchable ; `state.v1.json` intouché (les feature tests valident la réponse par les INVARIANTS du contrat — iso `ContractV1Test` — jamais par comparaison aux payloads illustratifs).

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Résolution du user = query param `?user=<login>`**, via `User::findByLogin()` (lookup case-insensitive, correctif 4.10). Login inconnu ou compte local (admin local, comptes hors SE5 — cas légitime du compagnon) → compilation machine-only (`user = null`) + log `agent.state.unknown_user` (info, login tronqué `Str::limit(255)` — convention P5 23.2) — **jamais d'erreur** : une session locale doit recevoir un état. Param absent ou vide → machine-only sans log. Pas de cloisonnement par user dans la portée du token : l'état est `f(poste, user)` et le poste (SYSTEM) est l'autorité sur QUI est dans SA session — c'est « lire SON état ».
2. **Portées avec `user = null` : servir le compilé tel quel.** Les mailles user sont vides (23.4 AC2), les portées `session`/`machine_user` peuvent porter des items issus de mailles machine. Divergence douce assumée avec la lettre de l'AC epic (écrit avant le design 23.4) : le service SYSTEM ne traite que `machine` de toute façon (24.2), le compagnon re-demande avec user (24.3). Tronquer côté controller = violation D2.
3. **Un ETag par couple (poste, user).** Deux contextes = deux états = deux hashes ; le service machine et le compagnon de session tiennent chacun LEUR `If-None-Match`. À documenter dans `state-endpoint.md` pour Epic 24 (cache par requête, pas global).
4. **Chaîne middleware : `['auth.v1.secure-headers', 'throttle:60,1', 'agent.token']`** — résout le defer 23.2 (« throttle canal agent à câbler avec les vraies routes »). Throttle AVANT `agent.token` : le lookup DB du middleware est protégé du flood (clé = IP, pas de guard user sur ce canal ; 60/min/IP = large pour un poll horaire + forçages). Pas de `local.request` : l'auth EST le token (iso canal config legacy 16.13 qui n'en a pas) ; `local.request` reste propre à la porte d'enrôlement. `secure-headers` pose `Cache-Control: no-store` : sans incidence sur notre revalidation (l'agent stocke l'ETag délibérément, ce n'est pas un cache HTTP) et bonne hygiène sur un canal à token.
5. **`config/agent.php` — gap 4 résolu, valeurs FIXÉES** (consommées en 24.1, définies ici comme l'exige l'epic) :
   - `ttl_seconds` => `max(1, (int) env('AGENT_STATE_TTL_SECONDS', 3600))` — cadence de poll conseillée, alignée golden file (3600) et D7 (60 min) ;
   - `report_history` => `(bool) env('AGENT_REPORT_HISTORY', false)` — flag D3, défaut off ;
   - `report_events_retention_days` => `max(1, (int) env('AGENT_REPORT_EVENTS_RETENTION_DAYS', 14))` — journal des changements, « rétention courte » D3 ;
   - `report_history_retention_days` => `max(1, (int) env('AGENT_REPORT_HISTORY_RETENTION_DAYS', 30))` — purge auto de l'historique de débogage.
   `token_rotation_days` et `enroll_ticket_ttl_minutes` existants : INTOUCHÉS. Planchers `max(1, …)` dans le fichier config (évalués au `config:cache`, iso-pattern P3 23.2).
6. **`StateController::show()` mince** (l'arbre architecture nomme la classe ; méthode `show` pour un GET) : résoudre le user (décision n° 1) → `TargetContext::for($workstation, $user)` → `compile()` → `response()->json($state)` + `setEtag($compiler->hashState($state))` → `isNotModified()` → 304 + log. Aucune logique de compilation, aucun write : `agent_last_checkin_at` est déjà géré par le middleware (23.2) — le controller n'y touche PAS.
7. **Logs** : `agent.state.not_modified` (debug, contexte `workstation_id` + `user` login-ou-null) sur le 304 ; le 200 est déjà couvert par `agent.state.compiled` émis par le compilateur (ne pas dupliquer) ; `agent.state.unknown_user` (décision n° 1). Channel `agent`, action types namespacés, jamais de payload ni de token loggé.
8. **Recompilation à chaque hit, pas de cache serveur.** Le 304 économise le CORPS, pas le calcul (~0,7 req/s à 600 postes — D1 : cache par maille = optimisation différée, mesurer avant). Toute mise en cache de l'état compilé est HORS scope.

## Acceptance Criteria

### AC1 — 200 : l'enveloppe v1 brute + ETag

**Given** un poste enrôlé (token 23.2/23.3 valide)
**When** `GET /api/v1/agent/state` avec son bearer
**Then** 200 dont le corps est l'enveloppe contrat BRUTE (`schema` = `StateContract::SCHEMA`, `generated_at` ISO 8601+TZ, `ttl_seconds` reflétant `config('agent.ttl_seconds')`, les 3 portées toujours présentes même vides) — aucun wrapper SE5
**And** chaque item porte exactement `{type, semantics, mode, payload, hash}` avec `hash` vérifiable par `StateHasher::hashItem()` (invariants iso `ContractV1Test`, sans comparaison aux payloads illustratifs du golden file)
**And** le header `ETag` porte `StateHasher::hashState()` de ce corps (forme quotée RFC 7232), recalculable depuis le corps décodé par le test.

### AC2 — 304 : réponse conditionnelle

**Given** un `If-None-Match` égal à l'ETag courant (valeur renvoyée verbatim)
**Then** 304 **sans corps**, ETag conservé sur la réponse, log `agent.state.not_modified` (channel `agent`, contexte `workstation_id`)
**And** deux compilations du même état à des instants différents → même ETag (déterminisme 23.4, prouvé à travers HTTP avec `travel()`)
**And** si une règle métier change entre deux appels (ex. nouveau wallpaper sur le WG du poste), le même `If-None-Match` reçoit 200 + nouvel ETag.

### AC3 — Check-in machine sans session : user optionnel

**Given** un appel SANS `?user` (service SYSTEM au boot)
**Then** 200, mailles user vides : aucune règle ciblée `User`/`UserGroup` ne sort, les règles de mailles machine restent servies dans LEUR portée déclarée (décision n° 2) — sans erreur
**And** `?user=<login connu>` → les règles user/groupes user du contexte sortent (et l'ETag diffère de l'appel machine-only si l'état diffère)
**And** `?user=<login inconnu>` → comportement machine-only + log `agent.state.unknown_user`, jamais d'erreur ; lookup case-insensitive (`findByLogin`).

### AC4 — config/agent.php complété (gap 4) + lecture ttl durcie

**Given** `config/agent.php`
**Then** il porte `ttl_seconds`, `report_history`, `report_events_retention_days`, `report_history_retention_days` avec les valeurs et planchers de la décision n° 5 (gap 4 architecture : valeurs fixées), `token_rotation_days`/`enroll_ticket_ttl_minutes` inchangés
**And** la lecture du compilateur est durcie : `max(1, (int) (config('agent.ttl_seconds') ?? 3600))` — une clé `null` ne produit plus `ttl_seconds: 0` (defer review 23.4, testé)
**And** seule cette ligne change dans `StateCompiler` ; le hash figé `FROZEN_STATE_HASH` et la suite 23.4 restent verts.

### AC5 — Sécurité du canal : middleware, throttle, rotation sur 304

**Given** la route réelle `GET /api/v1/agent/state` (nom `agent.v1.state` — libre, vérifié ; collision legacy `agent.v1.enroll/refresh/ping/config.*` évitée)
**Then** chaîne middleware décision n° 4 ; sans bearer → 401 `AGENT_TOKEN_MISSING`, token invalide/révoqué → 401 `AGENT_TOKEN_INVALID`, poste en quarantaine → 403 `AGENT_QUARANTINED` (formats middleware 23.2 inchangés)
**And** rotation due + `If-None-Match` correspondant → la réponse 304 porte `X-Agent-New-Token` (piège n° 3, invariant D5 testé)
**And** `agent_last_checkin_at` est mis à jour par le middleware au passage — le controller ne fait AUCUNE écriture.

### AC6 — Feature tests

**Then** `tests/Feature/Api/V1/Agent/StateEndpointTest.php` (`RefreshDatabase`, conventions `EnrollmentEndpointTest`/`AuthenticateAgentTokenTest` : `Workstation::factory()` + `TokenRotationService::issueFor()` + `withHeaders(['Authorization' => 'Bearer …'])`) couvre : 200 structure complète + items hashés ; ETag = hashState du corps ; 304 sur If-None-Match + corps vide + log ; déterminisme via `travel()` ; invalidation par changement de règle ; user absent/connu/inconnu (AC3) ; 401 missing + invalid ; 403 quarantaine ; X-Agent-New-Token sur 304 ; ttl_seconds non-zéro avec clé nulle (AC4, `config(['agent.ttl_seconds' => null])`)
**And** `php artisan test --filter Agent` intégralement vert sur `/vm` (baseline post-review 23.4 : **103 passed**) ; golden files et hash figé intouchés.

### AC7 — Transversal : zéro AD, frontières, doc, VM

**Then** aucun appel AD/LdapRecord/APCu dans le code livré (critère Keycloak, grep en review) ; aucune logique de compilation dans le controller ; canal agent en lecture seule (zéro write applicatif — le middleware 23.2 garde ses writes `agent_*` à lui)
**And** `docs/agent/state-endpoint.md` (NEUF) documente : URL/méthode/middlewares, `?user` et sa sémantique (décisions n° 1-3), ETag opaque verbatim + un cache If-None-Match PAR contexte, 304 + rotation, codes 200/304/401/403/429, clés `config/agent.php` et leur rationale (gap 4), recompilation à chaque hit ; `contract-v1.md` et golden files INTOUCHÉS (piège n° 8)
**And** opérations VM exécutées et tracées : `php artisan config:cache && php artisan route:cache` + chown www-admin (piège n° 7), smoke HTTP réel (curl sans token → 401 JSON = route vivante, iso smoke 23.3).

## Tasks / Subtasks

- [x] **T1 — config/agent.php + lecture ttl durcie** (AC4)
  - [x] Ajouter les 4 clés (décision n° 5) avec env vars, planchers `max(1, …)` et commentaires homogènes au fichier existant (sections datées par story, style actuel).
  - [x] `StateCompiler` ligne ~69 : `'ttl_seconds' => max(1, (int) (config('agent.ttl_seconds') ?? 3600)),` — adapter/retirer le commentaire « piège n° 7 » de 23.4 devenu obsolète. AUCUN autre edit du compilateur.
- [x] **T2 — Route** (AC5)
  - [x] `routes/api.php`, FIN du bloc 23.3 (~ligne 250, piège n° 6) : `Route::get('/v1/agent/state', [AgentStateController::class, 'show'])->middleware(['auth.v1.secure-headers', 'throttle:60,1', 'agent.token'])->name('agent.v1.state');` + import en tête de fichier (alias `AgentStateController` si conflit de noms, iso `AgentEnrollController` 23.3).
- [x] **T3 — StateController** (AC1, AC2, AC3)
  - [x] `App\Http\Controllers\Api\V1\Agent\StateController` (`declare(strict_types=1)`, injection `StateCompiler` — singleton déjà bindé `AgentServiceProvider`).
  - [x] `show(Request $request)` : workstation = `$request->attributes->get('agent.workstation')` (posé par le middleware) ; user = décision n° 1 (`query('user')` trim, vide → null, `User::findByLogin()`, inconnu → null + log) ; `TargetContext::for()` ; `compile()` ; `response()->json($state)` (flags `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`, iso canonicalisation lisible) ; `setEtag($this->compiler->hashState($state))` ; `if ($response->isNotModified($request))` → log `agent.state.not_modified` ; return.
- [x] **T4 — Documentation** (AC7)
  - [x] `docs/agent/state-endpoint.md` (NEUF) — contenu AC7 ; renvoi 1 ligne depuis `docs/agent/state-providers.md` (« servi par GET /state, voir state-endpoint.md ») si trivial, sinon rien — `contract-v1.md` figé.
- [x] **T5 — Tests** (AC6)
  - [x] `tests/Feature/Api/V1/Agent/StateEndpointTest.php` : cas listés AC6. Fabriquer l'état via les tables métier réelles (factories `Wallpaper`/`WallpaperAsset`/`WorkstationGroup`/`User`/`UserGroup`, `OverlaySignal::create()` — conventions tests 23.4) ; helper privé `state(string $token, array $headers = [])` iso `checkin()` 23.2 ; rotation due via `agent_token_rotated_at` reculé (cf. `AuthenticateAgentTokenTest`).
  - [x] Vérif ETag : `$this->assertSame('"' . app(StateHasher::class)->hashState($response->json()) . '"', $response->headers->get('ETag'))` (ou trim des guillemets — forme quotée, piège n° 2).
- [x] **T6 — Vérifications finales + VM** (AC6, AC7)
  - [x] `php -l` sur tous les fichiers créés/modifiés ; grep Keycloak (`ldap`, `apcu`, `get_apps`, `samba-tool`) sur le code livré → vide.
  - [x] `/vm` : `php artisan config:cache && php artisan route:cache` + chown www-admin sur `bootstrap/cache/` ; `php artisan test --filter Agent` (103 baseline + nouveaux, zéro régression) ; suite complète (2 failed préexistants connus hors scope : `WpkgReportApiTest::post_from_non_local_ip`, `GpoIndexExportTest::it_shows_advanced_filter_controls`) ; smoke curl sans token → 401.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (23.5) | Hors-scope (story) |
|---|---|
| `GET /api/v1/agent/state` + `StateController` mince | `POST /report`, `ReportIngestService`, tables `agent_*` → **24.1** |
| ETag/304 (`setEtag` + `isNotModified`), log `not_modified` | Consommation des clés `report_*` de config → **24.1** |
| Complétion `config/agent.php` (gap 4 : 4 clés, valeurs fixées) | Agent côté poste, cache local, If-None-Match client → **24.2/24.3** |
| Lecture `ttl_seconds` durcie dans `StateCompiler` (defer 23.4) | Bouton « forcer la synchro », UI conformité → **24.5** |
| Throttle du canal câblé (defer 23.2) | Migration des routes overlay legacy sous `/api/v1/agent/*` (bascule wallpaper, Epic 24/27) |
| Doc `docs/agent/state-endpoint.md` | Cache serveur de l'état compilé (mesurer avant — D1) |
| Feature tests 200/304/401/403 | Tout edit de `StateCompiler` au-delà de la ligne ttl, des providers, du middleware |

### Patterns existants à imiter (NE PAS réinventer)

- **Workstation authentifié** : `$request->attributes->get('agent.workstation')` — posé par `AuthenticateAgentToken` (`app/Http/Middleware/AuthenticateAgentToken.php:162`) ; constantes d'erreur `CODE_TOKEN_MISSING/INVALID/QUARANTINED` (lignes 56-58), header `HEADER_NEW_TOKEN` (ligne 61).
- **Compilation** : `StateCompiler::compile(TargetContext $ctx): array` + `StateCompiler::hashState(array $state): string` (délègue à `StateHasher`) — singletons bindés `app/Providers/AgentServiceProvider.php`. Constantes contrat : `StateContract::SCHEMA`, `StateContract::scopes()`.
- **Contexte** : `TargetContext::for(Workstation $workstation, ?User $user): self` (`app/Services/Agent/TargetContext.php:45`) — user nullable prévu POUR cette story (23.4 AC2).
- **User par login** : `User::findByLogin(string $login): ?self` (`app/Models/User.php:206-209`, `LOWER()`, correctif 4.10) — ne pas réécrire de whereRaw.
- **Controller mince canal agent** : `app/Http/Controllers/Api/V1/Agent/EnrollController.php` (23.3) — structure, strict_types, logs, codes d'erreur en constantes.
- **Style feature tests** : `tests/Feature/Api/V1/Agent/EnrollmentEndpointTest.php` + `AuthenticateAgentTokenTest.php` (helper `checkin()`, forge token via `TokenRotationService::issueFor()`, rotation due par recul de `agent_token_rotated_at`, quarantaine via `quarantine()`).
- **Assertions contrat** : `tests/Unit/Services/Agent/ContractV1Test.php` — enveloppe (schema/clés/3 portées listes), items 5 clés exactes, enums valides, `hashItem` cohérent. Réutiliser ces invariants côté HTTP.
- **Logging channel `agent`** : `config/logging.php:173` ; conventions 23.2-23.4 (`action_type` namespacé, contexte `workstation_id`, jamais de secret/payload).

### Architecture — conventions figées applicables (NON négociables)

[Source: architecture-agent-desired-state.md#API & Communication Patterns (D4) ; #Format Patterns ; #Enforcement Guidelines]

- D4 : `GET /api/v1/agent/state`, ETag/If-None-Match → **304 sans corps** ; endpoint DANS `/api/v1/agent/*`, bearer per-host — un endpoint agent hors préfixe ou hors auth canal = anti-pattern bloquant.
- Codes du canal : 200 / 304 / 401 / 403 (+ 429 throttle, hors contrat).
- `StateHasher` pour TOUT hash (jamais de `hash('sha256', …)` ad hoc) ; ETag et rapports 24.1 = même source.
- Aucune logique de compilation dans un controller (couche Services) ; aucun appel AD (critère Keycloak NFR7) ; le canal agent n'écrit que dans `agent_*` (ici : rien côté controller).
- Conventions : controller `App\Http\Controllers\Api\V1\Agent\StateController`, route `agent.v1.state`, config `config/agent.php`, channel `agent`, tests `tests/Feature/Api/V1/Agent/`.
- Logging archi : `agent.state.compiled` (déjà émis par le compilateur) + `agent.state.not_modified` (cette story).

### Contrat v1 — invariants consommés ici

[Source: docs/agent/contract-v1.md §1, §2, §4, §8]

- Enveloppe : `{schema, generated_at, ttl_seconds, machine, session, machine_user}` — 3 portées TOUJOURS présentes, listes ordonnées.
- `hashState()` exclut `generated_at` (le corps change à chaque hit, l'ETag non — c'est le design) ; `hashItem()` exclut `hash`.
- L'agent compare des hashes OPAQUES, ne recalcule jamais — l'ETag HTTP en est la première incarnation.
- §8 : type absent = aucune règle ; ne pas faire porter de sens à `{}` vs `[]` après décodage.
- Golden file `tests/Fixtures/Agent/state.v1.json` : payloads ILLUSTRATIFS (les vrais payloads = `docs/agent/state-providers.md` 23.4) — valider la réponse HTTP par invariants, pas par égalité au golden.

### Project Structure Notes

- **Racine = projet Laravel** ; code édité sur l'hôte, exécuté sur la VM `/vm` (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, `/var/www/sambaedu-reload`) ; sync inotify auto — jamais de sync manuel ; si non-synchro → notifier Henri et attendre. Jamais de VM depuis un worktree git.
- Cette story TOUCHE `config/` et `routes/` → opérations VM obligatoires (piège n° 7) : `config:cache` + `route:cache` + chown www-admin. Aucune migration.
- inotify ne propage pas les suppressions — aucun fichier supprimé prévu.

### Testing standards

- PHPUnit, référence `/vm` ; SQLite `:memory:` en feature (RefreshDatabase) — sans incidence (lecture seule).
- Baseline `--filter Agent` : **103 passed** (post-review 23.4) — doit rester intégralement vert + les nouveaux.
- Suite complète : 2 failed préexistants hors scope (`WpkgReportApiTest`, `GpoIndexExportTest`) — ne pas tenter de les réparer ici.
- Piège route réelle vs cache VM : les tests utilisent la VRAIE route (plus de route éphémère ni de `setRoutes()` nécessaires — c'était le piège 23.2 pour routes de test) ; sur la VM c'est `route:cache` qui rend la route vivante.
- Déterminisme HTTP : GET → ETag → `travel(3h)` → GET avec If-None-Match → 304 (LE test qui valide l'ETag de bout en bout).

### Intelligence stories précédentes

- **23.1 (done)** : contrat + `StateHasher` figés ; defer « valider l'entrée agent avant hashState » → 24.1 (POST), rien ici (GET sans corps).
- **23.2 (done)** : middleware complet (rotation ré-émise systématiquement via previous, anti-clone MAC normalisée, `agent_last_checkin_at` géré DANS le middleware) ; defer throttle → **résolu ici** (décision n° 4) ; formats d'erreur figés.
- **23.3 (done)** : bloc routes 23.3 + commentaire-emplacement pour cette route ; piège fenêtre 1500 chars confirmé (1er emplacement avait cassé `ScriptsOsNamespaceTest`) ; smoke HTTP réel sur VM = bonne pratique validée ; `route:cache` VM requis pour route fraîche.
- **23.4 (done)** : compilateur déterministe prouvé (`travel(3h)` → même hash) ; interface +`mode()` validée en review ; defer ttl null→0 → **résolu ici** (piège n° 4) ; conventions tests par tables métier réelles + factories.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 23.5] — ACs source, FR1/FR6, gap 4.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#D4 ; #Format Patterns (hash, codes) ; #Communication Patterns (logging) ; #Enforcement Guidelines] — endpoint, ETag/304, anti-patterns.
- [Source: docs/agent/contract-v1.md §2, §4, §8] — enveloppe, hashState/generated_at, golden illustratif.
- [Source: docs/agent/token-lifecycle.md §4, §6] — rotation au check-in (header sur TOUTE réponse), codes 401/403.
- [Source: app/Http/Middleware/AuthenticateAgentToken.php:56-65, 162-167] — attribut `agent.workstation`, header rotation posé après `$next()`.
- [Source: app/Services/Agent/StateCompiler.php:69 ; ContractV1Test::FROZEN_STATE_HASH] — ligne ttl à durcir, hash figé à ne pas casser.
- [Source: routes/api.php:182-204, 228-250, 274-287] — noms `agent.v1.*` pris (enroll/refresh/ping/enrollment/config.*), emplacement bloc 23.3, throttles existants (10/30/60/300 par minute).
- [Source: app/Models/User.php:206-209 ; app/Services/Agent/TargetContext.php:45] — findByLogin, user nullable.
- [Source: tests/Feature/Api/V1/Agent/EnrollmentEndpointTest.php ; AuthenticateAgentTokenTest.php] — conventions feature canal agent.

## Dev Agent Record

### Agent Model Used

claude-fable-5 (modèle recommandé : **fable** — décision Henri pour tout l'Epic 23 : cohérence contrat serveur⇄agent + subtilités ETag/rotation/déterminisme.)

### Debug Log References

- Premier run `StateEndpointTest` sur `/vm` : 18/18 en 404 — cache `routes-v7.php` stale (piège n° 7 confirmé, pas un bug de code). Résolu par `config:cache` + `route:cache` + chown www-admin ; run suivant 18/18 verts.
- Smoke HTTPS `localhost:443` KO : Apache de la VM n'écoute qu'en :80 (+ :8082 loopback) — smoke refait en HTTP, concluant.

### Completion Notes List

- **T1** : 4 clés ajoutées à `config/agent.php` (`ttl_seconds` 3600, `report_history` false, `report_events_retention_days` 14, `report_history_retention_days` 30) avec env vars et planchers `max(1, …)` ; `token_rotation_days`/`enroll_ticket_ttl_minutes` intouchés. `StateCompiler` : seule la ligne ttl éditée (`max(1, (int) (config('agent.ttl_seconds') ?? 3600))`), commentaire 23.4 obsolète remplacé par l'explication du `??` (clé null ≠ clé absente). Hash figé `6c0e8135…` et suite 23.4 verts (AC4).
- **T2** : route `GET /v1/agent/state` ajoutée à la FIN du bloc 23.3 de `routes/api.php` (piège n° 6 respecté — `ScriptsOsNamespaceTest` vert), chaîne `['auth.v1.secure-headers', 'throttle:60,1', 'agent.token']` (décision n° 4, defer throttle 23.2 résolu), import aliasé `AgentStateController` iso 23.3, nom `agent.v1.state` sans collision.
- **T3** : `StateController::show()` mince — résolution user (query `user` : non-string/vide → null sans log ; `findByLogin` case-insensitive ; inconnu → machine-only + log info `agent.state.unknown_user` borné `Str::limit(255)`) → `TargetContext::for()` → `compile()` → `response()->json(…, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)` → `setEtag(hashState)` → `isNotModified()` → log debug `agent.state.not_modified` (contexte `workstation_id` + `user` login-ou-null). Zéro write, zéro logique de compilation (D2), enveloppe BRUTE sans wrapper SE5. Garde `is_string` sur `query('user')` (un `?user[]=x` ne crashe pas).
- **T4** : `docs/agent/state-endpoint.md` créé (URL/middlewares, sémantique `?user` décisions n° 1-3, ETag opaque verbatim + cache par contexte, rotation sur 304, codes 200/304/401/403/429, clés config + rationale gap 4, recompilation à chaque hit, consignes Epic 24) ; renvoi 1 ligne ajouté au blockquote d'intro de `state-providers.md`. `contract-v1.md` + golden files INTOUCHÉS.
- **T5** : `StateEndpointTest` — 18 tests / 69 assertions : enveloppe brute (clés exactes, pas de wrapper), items 5 clés + `hashItem` recalculé, ETag quoté = `hashState` du corps décodé, 304 sans corps + ETag conservé + log, déterminisme `travel(3h)` → 304 à travers HTTP, invalidation par nouveau wallpaper WG, machine-only sans contribution user (portées non tronquées), user connu (maille user gagne + ETag différent), lookup case-insensitive, user inconnu = machine-only + log sans erreur, `?user=` vide sans log, ttl clé nulle → 3600, 401 missing/invalid, 403 quarantaine, X-Agent-New-Token sur 304 (invariant D5) + nouveau token utilisable, check-in stampé par le middleware (zéro write controller). Logs capturés au pattern `StateCompilerTest` (mock channel `agent`).
- **T6** : `php -l` 5 fichiers OK ; grep Keycloak (`ldap|apcu|get_apps|samba-tool`) vide sur le code livré ; VM `config:cache` + `route:cache` + chown www-admin faits ; `--filter Agent` **121 passed** (460 assertions — baseline 103 + 18) ; suite complète **4065 passed / 2 failed préexistants hors scope** (`WpkgReportApiTest::post_from_non_local_ip`, `GpoIndexExportTest::it_shows_advanced_filter_controls`) ; smoke HTTP réel : `curl` sans token → `401 {"error":"unauthorized","code":"AGENT_TOKEN_MISSING"}` = route vivante derrière le cache.
- L'Epic 23 est désormais e2e-testable à la main (curl/jq contre la VM) — validation humaine différée par Henri à exécuter sur cette base.

### File List

- `app/Http/Controllers/Api/V1/Agent/StateController.php` (créé)
- `tests/Feature/Api/V1/Agent/StateEndpointTest.php` (créé)
- `docs/agent/state-endpoint.md` (créé)
- `config/agent.php` (modifié — 4 clés ajoutées, gap 4)
- `app/Services/Agent/StateCompiler.php` (modifié — ligne ttl durcie uniquement ; ⚠️ traçabilité : l'édit a été embarqué dans le commit `9557f07` de la 23.4, fait après coup sur l'arbre de travail partagé — il est ABSENT du changeset 23.5 ; un revert de 23.5 ne défait pas le durcissement ttl)
- `routes/api.php` (modifié — import aliasé + route `agent.v1.state` fin du bloc 23.3)
- `docs/agent/state-providers.md` (modifié — renvoi 1 ligne vers state-endpoint.md)

## Change Log

- 2026-06-11 — Story 23.5 implémentée (claude-fable-5, workflow dev-story) : endpoint `GET /api/v1/agent/state` (ETag/304), complétion `config/agent.php` (gap 4), lecture ttl durcie (defer 23.4), throttle canal câblé (defer 23.2), doc `state-endpoint.md`, 18 feature tests. Suite Agent VM 121 passed ; suite complète 4065 passed / 2 failed préexistants hors scope. Status → review.
- 2026-06-11 — **Code review adversariale (3 couches : Blind Hunter / Edge Case Hunter / Acceptance Auditor) + seconde lecture critique de la review** : 7/7 AC satisfaits, fichiers figés intouchés, zéro anti-pattern (D2/AD/writes vérifiés). 2 correctifs appliqués (orchestrateur) : **P1** traçabilité — l'édit ttl de `StateCompiler` (réel, fait par le dev 23.5) a été embarqué dans le commit `9557f07` de la 23.4 (commit fait après coup sur l'arbre partagé) → annoté dans la File List, un revert 23.5 ne le défait pas ; **P2** mock logs des tests étendu à `error`/`critical` (un futur `Log::error()` ne plante plus en BadMethodCallException opaque). **P3 rejeté en seconde lecture** (borner `?user` avant `findByLogin` : iso-convention canal — le middleware ne borne qu'au log, `AuthenticateAgentToken.php:232` — et `Str::limit` suffixerait `…`). **Deferred** : clé du throttle = IP — à revoir si le canal passe un jour derrière NAT/controlHub (60/min partagé → 429 en storm de boot, Epic 24+). ~11 findings rejetés (faux positifs vérifiés : `hashState` canonicalise indépendamment des flags réponse, middleware garantit `agent.workstation` avant le controller, contexte log JSON-échappé).
