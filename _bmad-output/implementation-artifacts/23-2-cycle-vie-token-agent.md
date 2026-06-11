# Story 23.2: Cycle de vie du token agent — auth, rotation, révocation, anti-clonage

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **que chaque poste s'authentifie sur le canal agent avec son propre token à portée minimale**,
afin que **le canal soit sûr sans créer de nouvelle dépendance AD**.

## Contexte & intention

Deuxième story de l'Epic 23 (« successeur GPO — agent desired-state »), séquence d'implémentation n° 2 de l'architecture : *token & enrôlement = prérequis de tout appel agent*. La 23.1 (done) a figé le contrat JSON ; cette story pose la **couche d'authentification du canal neuf** : colonnes `agent_*` sur `workstations`, service de cycle de vie du token (émission, rotation glissante D5, révocation, quarantaine), middleware `AuthenticateAgentToken`, channel de log `agent`, et le bouton UI de révocation.

Ce qui vient APRÈS et qui consomme cette story :

- la **naissance** du token à l'install iPXE (`POST /enroll` porte 1) → **Story 23.3** (hors-scope ici — 23.2 livre `issueFor()`, 23.3 l'appelle)
- `GET /api/v1/agent/state` derrière ce middleware → **Story 23.5**
- `POST /api/v1/agent/report` → **Story 24.1**
- l'agent côté poste qui présente le bearer et gère le 401/403 → **Epic 24**
- porte 2 (postes migrés, approbation un-clic, 403 « non approuvé ») → **Story 25.3**

Comme aucun endpoint métier du canal n'existe encore, les feature tests exercent le middleware via une **route éphémère définie dans le test** (pattern standard) — on ne crée AUCUNE route applicative ici.

## ⚠️ Trois pièges découverts à l'analyse (lire avant de coder)

1. **Le préfixe `/api/v1/agent/*` et les noms `agent.v1.*` sont DÉJÀ occupés** par le canal JWT legacy-migration (`routes/api.php:178-200` : `agent.v1.enroll`, `agent.v1.refresh`, `agent.v1.ping` + `agent.v1.config.*` sur `/api/v1/workstation-config/*`). 23.2 ne crée pas de route donc pas de collision immédiate, mais : l'alias middleware doit être **`agent.token`** (pas `auth.v1.*`), et les stories 23.3/23.5 devront résoudre la collision de nom/URL (`agent.v1.enroll` est pris). Ne touchez PAS à ces routes existantes — canal legacy intouché pendant la transition (frontière architecture).
2. **« Sanctum » est un abus de langage des artefacts de planning.** Sanctum est installé (`composer.json`) mais **inutilisé** dans tout le codebase (zéro `HasApiTokens`, zéro `PersonalAccessToken`, `auth:sanctum` jamais appliqué). L'AC de l'epic exige explicitement des **colonnes `agent_*` sur `workstations`** — incompatible avec la table `personal_access_tokens` de Sanctum. Implémentation retenue : **bearer token custom** (64 hex, SHA-256 haché en colonne), iso-pattern `WorkstationRefreshToken` existant (`app/Auth/V1/Models/WorkstationRefreshToken.php` : `refresh_token_hash` sha256, lookup par hash, `revoked_at`). N'introduisez NI `HasApiTokens`, NI `personal_access_tokens`.
3. **`config/agent.php` est annoncé « nouveau » en 23.5, mais l'AC de 23.2 référence `agent.token_rotation_days`.** Décision de séquencement : 23.2 crée `config/agent.php` **minimal** (la seule clé `token_rotation_days`), 23.5 le complétera (`ttl_seconds`, `report_history`, rétentions). Tout changement sous `config/` exige `php artisan config:cache` + chown www-admin sur la VM (cf. Project Structure Notes).

## Acceptance Criteria

### AC1 — Authentification & portée minimale (FR12)

**Given** un poste enrôlé (token haché en DB : colonnes `agent_token_hash`, `agent_token_rotated_at`, `agent_last_checkin_at` sur `workstations`)
**When** il appelle une route protégée par le middleware avec son bearer
**Then** `AuthenticateAgentToken` l'authentifie et résout **SON** workstation, injecté dans la requête (`$request->attributes->set('agent.workstation', $ws)`)
**And** la portée se limite à lire SON état et écrire SES rapports : les futurs controllers du canal n'acceptent **jamais** d'identifiant de poste en entrée — le poste résolu par le token est la seule identité (règle d'enforcement, documentée)
**And** `agent_last_checkin_at` est mis à jour à chaque requête authentifiée.

### AC2 — Token invalide ou révoqué → 401

**Given** un bearer absent, malformé, inconnu ou révoqué
**Then** 401, réponse JSON format SE5 (`{error, message, code}`), sans distinction observable entre « inconnu » et « révoqué » (pas d'oracle).

### AC3 — Quarantaine → 403

**Given** un poste en quarantaine (`agent_quarantined_at` non null)
**Then** 403 (`code: AGENT_QUARANTINED`)
**And** `agent_last_checkin_at` est tout de même mis à jour (le poste « poursuit des check-ins légers » — il reste visible, FR15).
*(Le 403 « non approuvé » relève de la porte 2 → 25.3, pas ici.)*

### AC4 — Rotation à échéance + fenêtre de grâce (D5, FR13)

**Given** un token passé l'échéance (`agent_token_rotated_at` plus vieux que `config('agent.token_rotation_days')`)
**When** le poste fait son check-in (toute requête authentifiée)
**Then** le serveur génère un nouveau token et le renvoie dans le header de réponse `X-Agent-New-Token` ; l'ancien hash glisse en `agent_previous_token_hash` et **reste valide jusqu'au premier usage du nouveau**
**And** si la réponse est perdue (le poste re-check-in avec l'ancien token), un nouveau token est ré-émis — l'ancien reste valide tant qu'aucun nouveau n'a servi
**And** au premier usage du nouveau token, la fenêtre de grâce se ferme (`agent_previous_token_hash` effacé, log `agent.token.rotation_confirmed`)
**And** aucune expiration calendaire sèche n'existe : un token rotaté il y a 6 mois s'authentifie et déclenche une rotation — le poste vivant après les vacances se rotate, ne meurt pas (testé).

### AC5 — Anti-clonage (FR15)

**Given** un même token présenté avec une identité divergente (headers `X-Agent-Mac` / `X-Agent-Hostname` comparés à la fiche `workstations`)
**Then** MAC divergente → alerte (log **error** `agent.token.clone_detected`) + mise en quarantaine immédiate + 403
**And** hostname seul divergent → log warning `agent.token.hostname_mismatch` **sans** quarantaine (tolère le délai de renommage légitime UI/AD vs hostname local)
**And** headers absents → pas de détection (l'agent Epic 24 les enverra systématiquement) ; MAC comparée en forme canonique lowercase (iso mutateur `Workstation::setMacAttribute`).

### AC6 — Révocation par événement (FR14)

**Given** le bouton « révoquer le token agent » sur la page détail machine
**When** je clique (avec confirmation)
**Then** le token est révoqué immédiatement (hash courant + previous effacés, quarantaine levée) et le prochain appel du poste reçoit 401 ; toast de confirmation
**And** la suppression du poste révoque par construction (les colonnes vivent sur la ligne — verrouillé par un feature test : workstation supprimé → 401)
**And** la réinstallation (23.3) revoquera via le même service (`TokenRotationService::revokeFor()` — API prête, branchement iPXE hors-scope ici).

### AC7 — Transversal : zéro AD + logging

**Then** aucun appel AD/LdapRecord/Kerberos dans tout le flux (critère Keycloak NFR7, vérifiable en review : `grep -ri 'ldap\|kerberos\|samba-tool' app/Services/Agent app/Http/Middleware/AuthenticateAgentToken.php` → vide)
**And** toutes les transitions sont loggées channel `agent` (nouveau, `config/logging.php`) avec actions namespacées : `agent.token.issued`, `agent.token.rotated`, `agent.token.rotation_confirmed`, `agent.token.revoked`, `agent.token.clone_detected`, `agent.token.hostname_mismatch`, contexte `workstation_id` — et **jamais le token en clair** (iso-convention auth-v1).

## Tasks / Subtasks

- [x] **T1 — Migration colonnes `agent_*` sur `workstations`** (AC1, AC3, AC4)
  - [x] `agent_token_hash` string(64) nullable + index unique ; `agent_previous_token_hash` string(64) nullable + index ; `agent_token_rotated_at`, `agent_last_checkin_at`, `agent_quarantined_at` timestamps nullables.
  - [x] Convention : `YYYY_MM_DD_HHMMSS_add_agent_token_columns_to_workstations.php`, idempotence `Schema::hasColumn()` (iso `2026_05_22_120000_add_progress_and_programmed_action_to_workstations.php`). Types simples → compatible SQLite tests sans branche dédiée.
- [x] **T2 — Modèle `Workstation`** (AC1)
  - [x] Casts datetime sur les 3 timestamps. **NE PAS** ajouter les colonnes `agent_*` à `$fillable` (anti mass-assignment : seules les écritures explicites du service les touchent).
  - [x] Helpers de lecture : `isAgentEnrolled(): bool`, `isAgentQuarantined(): bool` (consommés par l'UI T6).
- [x] **T3 — `App\Services\Agent\Enrollment\TokenRotationService`** (AC4, AC6)
  - [x] `issueFor(Workstation): string` — `bin2hex(random_bytes(32))` (64 hex), stocke `hash('sha256', $token)`, set `agent_token_rotated_at = now()`, efface previous + quarantaine, log `agent.token.issued`, retourne le clair (jamais persisté, jamais loggé). Consommé par 23.3 (enrôlement/réinstall) et les tests.
  - [x] `rotateFor(Workstation): string` — previous ← courant (s'il n'y a pas déjà une grâce ouverte : si grâce ouverte, previous reste l'ancien token que le poste détient), courant ← nouveau, `agent_token_rotated_at = now()`, log `agent.token.rotated`.
  - [x] `confirmRotation(Workstation): void` — efface previous, log `agent.token.rotation_confirmed`.
  - [x] `revokeFor(Workstation, string $reason): void` — efface les 2 hash + `agent_token_rotated_at` + quarantaine, log `agent.token.revoked` avec `reason`.
  - [x] `quarantine(Workstation, string $reason): void` — set `agent_quarantined_at`, log error `agent.token.clone_detected` (ou raison passée).
  - [x] `declare(strict_types=1)`, classe pure injectable, pas de façade.
- [x] **T4 — Middleware `App\Http\Middleware\AuthenticateAgentToken` + provider** (AC1-AC5)
  - [x] Ordre des vérifications : bearer absent/malformé → 401 `AGENT_TOKEN_MISSING` ; `hash('sha256', $bearer)` → lookup `agent_token_hash` OU `agent_previous_token_hash` ; introuvable → 401 `AGENT_TOKEN_INVALID` ; anti-clone (AC5, avant tout traitement) ; quarantaine → maj check-in puis 403 `AGENT_QUARANTINED` ; match sur courant avec grâce ouverte → `confirmRotation()` ; rotation due (`agent_token_rotated_at` + `config('agent.token_rotation_days')` jours < now, y compris si auth via previous) → `rotateFor()` + header `X-Agent-New-Token` ; maj `agent_last_checkin_at` ; injection `agent.workstation` ; next.
  - [x] Réponses d'erreur JSON iso `EnsureWorkstationJwt` (`{error, message, code}`).
  - [x] Nouveau `App\Providers\AgentServiceProvider` (enregistré dans `config/app.php`, iso `AuthV1ServiceProvider:176`) : `$router->aliasMiddleware('agent.token', AuthenticateAgentToken::class)` dans `boot()`. (Futur foyer du registry de StateProviders en 23.4.)
- [x] **T5 — Config : channel `agent` + `config/agent.php` minimal** (AC7, piège n° 3)
  - [x] `config/logging.php` : channel `agent` iso channel `gpo` (lignes 158-164) — `daily`, `storage_path('logs/agent/agent.log')`, `env('AGENT_LOG_LEVEL', 'debug')`, `env('AGENT_LOG_DAYS', 30)`.
  - [x] `config/agent.php` : `['token_rotation_days' => (int) env('AGENT_TOKEN_ROTATION_DAYS', 30)]` (défaut D5), avec commentaire « complété en 23.5 : ttl_seconds, report_history, rétentions ».
- [x] **T6 — UI : bloc « Agent » sur la page détail machine** (AC6)
  - [x] `resources/views/pages/parc/machines/[id]/index.blade.php` (composant Livewire inline existant, trait `WithToasts` déjà présent) : petit bloc affichant l'état (enrôlé / jamais enrôlé / quarantaine + dates rotation & dernier check-in) + bouton « Révoquer le token agent » visible si enrôlé, `wire:confirm`, appelle `TokenRotationService::revokeFor()`, `toastSuccess`/`toastError` (iso `executeMachinePowerAction`).
- [x] **T7 — Documentation transport** (AC4, AC5)
  - [x] `docs/agent/token-lifecycle.md` (nouveau — **ne pas toucher `contract-v1.md`**, figé) : cycle de vie complet, headers `X-Agent-New-Token` / `X-Agent-Mac` / `X-Agent-Hostname`, codes 401/403 + codes métier, sémantique grâce/réponse-perdue, règle MAC=quarantaine vs hostname=warning, renvoi vers 23.3 (naissance) et 25.3 (porte 2).
- [x] **T8 — Tests** (tous AC)
  - [x] `tests/Unit/Services/Agent/TokenRotationServiceTest.php` : format token (64 hex), hash stocké ≠ clair, issue efface quarantaine/previous, rotate préserve la grâce existante, revoke nettoie tout.
  - [x] `tests/Feature/Api/V1/Agent/AuthenticateAgentTokenTest.php` : `RefreshDatabase` + `Workstation::factory()` (précédent : `tests/Feature/Auth/V1/Migration/*`) + route éphémère `Route::middleware('agent.token')->get(...)` déclarée dans le test, renvoyant l'id du workstation injecté. Scénarios : 401 sans bearer / token inconnu ; 200 + bon workstation résolu + check-in horodaté ; 403 quarantaine (check-in quand même horodaté) ; rotation due → header présent + ancien encore valide + nouveau valide ; réponse perdue → ré-émission, ancien toujours valide ; premier usage du nouveau → previous effacé puis ancien → 401 ; token rotaté il y a 6 mois → 200 + rotation (pas de mort calendaire) ; MAC divergente → 403 + quarantine posée ; hostname seul divergent → 200 + pas de quarantaine ; workstation supprimé → 401 ; revoke → 401.
- [x] **T9 — Vérifications finales**
  - [x] `php -l` sur tous les fichiers créés/modifiés (0 erreur).
  - [x] `php artisan test --filter Agent` sur `/vm` (référence) — les 19 tests 23.1 restent verts + les nouveaux : 42 passed (160 assertions).
  - [x] Critère Keycloak : grep AC7 → vide.
  - [x] Sur la VM : `php artisan config:cache` + `chown www-admin` (logging.php + agent.php modifiés/créés — cf. Project Structure Notes), puis `php artisan migrate`.
  - [x] Suite complète de non-régression sur `/vm` : **3980 passed (21997 assertions), 2 failed pré-existants hors scope** (`WpkgReportApiTest::test_post_from_non_local_ip_returns_403` — table `system_settings` absente du setup manuel du test, cassé par story 15.6 ; `GpoIndexExportTest` — assertion HTML page GPO). Zéro régression imputable à la 23.2.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (23.2) | Hors-scope (story) |
|---|---|
| Colonnes `agent_*` workstations + migration | `POST /enroll` porte 1, templates iPXE/WinPE, branchement réinstall → **23.3** |
| `TokenRotationService` (issue/rotate/confirm/revoke/quarantine) | `GET /state`, ETag, complétion `config/agent.php` → **23.5** |
| Middleware `AuthenticateAgentToken` + alias `agent.token` + `AgentServiceProvider` | `POST /report`, tables `agent_*` → **24.1** |
| Channel log `agent` + `config/agent.php` minimal | Agent côté poste (gestion 401/403, présentation headers) → **Epic 24** |
| Bouton UI révocation (page machine) + doc `token-lifecycle.md` | 403 « non approuvé », approbation, levée de quarantaine outillée → **25.3** / runbook |

### Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Grâce D5 ⇒ 4ᵉ colonne** : `agent_previous_token_hash` n'est pas dans l'AC de l'epic (qui n'en nomme que 3) mais est mécaniquement requise par « l'ancien reste valide jusqu'au premier usage du nouveau ». Idem `agent_quarantined_at` pour le 403.
2. **Wire de rotation = header `X-Agent-New-Token`** : le corps de `GET /state` est le JSON v1 figé (23.1) — y injecter un token violerait le contrat ; un header reste orthogonal au schéma. Réponse perdue ⇒ ré-émission d'un NOUVEAU token à chaque check-in sur l'ancien (on ne stocke que des hash, impossible de renvoyer le même) ; le previous reste l'unique token que le poste détient ⇒ jamais de lock-out.
3. **Anti-clone : MAC = quarantaine, hostname seul = warning.** FR15 dit « MAC/hostname divergents » ; la MAC est l'ancre fiable (l'uuid SMBIOS s'est montré vide/peu fiable côté iPXE — mémoire projet ; un hostname peut légitimement diverger le temps d'un renommage UI→AD vs hostname local). Interprétation raisonnée, signalée à Henri.
4. **401 indistinct** entre token inconnu et révoqué (révoqué = hash effacé = introuvable) : pas d'oracle, comportement émergent assumé.
5. **Pas de Sanctum effectif** (piège n° 2) — le mot reste dans les artefacts de planning, l'implémentation est custom-colonne, conforme à l'AC.

### Patterns existants à imiter (NE PAS réinventer)

- **Hash de token** : `WorkstationRefreshToken` (`app/Auth/V1/Models/`) — sha256 hex en colonne unique, lookup par hash, jamais de clair persisté. [Source: migration `2026_05_16_120000_create_workstation_refresh_tokens_table.php`]
- **Réponses d'erreur** : `EnsureWorkstationJwt` (`app/Auth/V1/Http/Middleware/`) — `{error: 'unauthorized', message, code}` 401. [Source: app/Auth/V1/Http/Middleware/EnsureWorkstationJwt.php]
- **Logging sans secret** : `WorkstationJwtIssuer.php:69-76` — `Log::channel(...)->debug('[Classe] action.type', ['action_type' => ..., contexte])`, token jamais loggé.
- **Alias middleware via provider** : `AuthV1ServiceProvider::boot():90-96`.
- **Channel log** : `config/logging.php:158-164` (channel `gpo`).
- **MAC canonique lowercase** : `Workstation::setMacAttribute()` (`app/Models/Workstation.php:90-93`) — normaliser le header pareil avant comparaison.
- **UI action machine + toast + confirm** : `resources/views/pages/parc/machines/[id]/index.blade.php`, méthode `executeMachinePowerAction()`.
- **Factory** : `database/factories/WorkstationFactory.php` (name, uuid, mac lowercase, status).

### Architecture — conventions figées applicables (NON négociables)

[Source: architecture-agent-desired-state.md#Naming Patterns / #Authentication & Security / #Enforcement Guidelines]

- Middleware : `app/Http/Middleware/AuthenticateAgentToken.php` (PAS sous `app/Auth/V1/` — canal neuf, frontière nette ancien/nouveau).
- Services : `App\Services\Agent\Enrollment\TokenRotationService` (l'arbre architecture place `Enrollment/{EnrollmentService,TokenRotationService}` — `EnrollmentService` relève de 23.3).
- Colonnes/config/channel : préfixe `agent_` / `config/agent.php` / channel `agent` — figés.
- Le canal agent n'écrit QUE dans les colonnes `agent_*` (+ futures tables `agent_*`). Aucune écriture AD, aucun import LdapRecord.
- Anti-patterns bloquants : endpoint agent hors `/api/v1/agent/*`, logique métier dans un controller/middleware au-delà de l'auth (déléguer au service), dépendance AD.

### Suppression / archivage / observer — état des lieux

- La **suppression complète** d'un poste n'existe pas encore dans l'UI (seulement `archived_at` + `WorkstationObserver::deleting` pour la sync AD). La révocation à la suppression est **par construction** (colonnes sur la ligne) — verrouiller par test, ne PAS ajouter de hook AD-sync dans l'observer pour ça.
- Si le dev constate qu'un chemin d'**archivage** UI existe, y brancher `revokeFor()` est dans l'esprit de FR14 (événement « le poste sort du parc ») — sinon, le noter en Completion Notes pour 25.x.
- `WorkstationObserver::$syncEnabled` / `withoutSync()` : sans objet ici (aucune écriture AD) — ne pas y toucher.

### Project Structure Notes

- **Racine = projet Laravel** (`app/`, `config/`, `tests/` à la racine). Code édité sur l'hôte, exécuté sur la VM `/vm` (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, projet `/var/www/sambaedu-reload`) ; sync inotify automatique — ne jamais sync manuellement ; si non-synchro → notifier Henri et attendre.
- ⚠️ **`config/logging.php` modifié + `config/agent.php` créé** → sur la VM : `php artisan config:cache` puis `chown www-admin` sur `bootstrap/cache/config.php`, sinon les nouvelles clés valent `null` en HTTP (tests verts mais navigateur/agent KO) [memory `vm-config-cache-not-synced`]. Et `php artisan migrate` sur la VM pour les colonnes.
- inotify ne propage pas les suppressions de fichiers — pas de fichier supprimé prévu ici.

### Testing standards

- PHPUnit, exécution de référence sur `/vm` ; SQLite `:memory:` en test (`phpunit.xml`) — colonnes simples, pas de jsonb, pas de piège varchar connu ici.
- Feature : `RefreshDatabase` + factory (précédent fonctionnel : `tests/Feature/Auth/V1/Migration/MigrationControllerTest.php`). Les traits `tests/Concerns/SeedsWorkstationConfig.php` créent une table `workstations` **manuelle sans colonnes `agent_*`** — ne PAS utiliser ce trait ici ; si un test existant le partage, le laisser intact.
- Viser : `TokenRotationServiceTest` (≥5 cas), `AuthenticateAgentTokenTest` (≥11 scénarios listés en T8). `--filter Agent` doit rester intégralement vert (19 tests 23.1 inclus).

### Intelligence story précédente (23.1, done — review 3 layers passée, 0 bloquant)

- Livré : enums (`AgentResourceStatus` notamment — réutilisable plus tard, pas requis ici), `StateContract`, `StateHasher`, golden files, `docs/agent/contract-v1.md`. **Rien de 23.1 ne se modifie dans 23.2** (le contrat est figé ; le token est du transport, d'où le doc séparé T7).
- Patterns confirmés en review 23.1 : `declare(strict_types=1)` partout, classes pures injectables, doc des décisions dans `docs/agent/`, hashes/secrets jamais en clair dans les logs.
- Environnement : la VM était injoignable pendant le dev 23.1 (tests passés sur l'hôte, PHP 8.4.5 + vendor présents) puis revenue (review exécutée sur `/vm`). Si `/vm` retombe : les tests Feature d'ici utilisent la DB SQLite de test (pas la VM), donc exécutables sur l'hôte — mais la référence reste `/vm`, et migrate/config:cache n'ont de sens QUE sur la VM.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 23.2] — ACs source, FR12-FR15.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Authentication & Security] — D5 rotation/recouvrement, anti-clonage, révocation par événement.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Naming Patterns / #Project Structure & Boundaries] — emplacements, alias, frontières.
- [Source: docs/agent/contract-v1.md §6] — identité du poste dans le rapport (`hostname`, `uuid`) : cohérence du choix headers hostname+MAC (uuid écarté car peu fiable).
- [Source: routes/api.php:178-200] — routes `agent.v1.*` existantes (collision à connaître).
- [Source: app/Auth/V1/Models/WorkstationRefreshToken.php ; app/Auth/V1/Http/Middleware/EnsureWorkstationJwt.php] — patterns token hash + middleware.
- [Source: _bmad-output/implementation-artifacts/23-1-contrat-v1-schemas-state-report.md] — story précédente (périmètre, patterns, review).

## Dev Agent Record

### Agent Model Used

claude-fable-5 (Fable 5 — décision Henri pour tout l'Epic 23)

### Debug Log References

- **Cache de routes VM → 404 sur la route éphémère** : premier run Feature = 16 échecs 404. Cause : `bootstrap/cache/routes-v7.php` présent sur la VM → le matcher compilé fait gagner le catch-all legacy `{path}` (`routes/web.php:1069`, route normale, PAS un `Route::fallback()`) sur toute route déclarée à l'exécution — le fallback dynamique de `CompiledRouteCollection::match()` ne joue que pour les routes `isFallback`. Fix : `$this->app['router']->setRoutes(new RouteCollection())` dans `setUp()` avant la déclaration de la route éphémère — le test est déterministe avec ou sans cache. Précédent à connaître pour 23.5/24.1 (leurs routes seront réelles, donc non concernées).
- Grep AC7 : reformulé un docblock du middleware qui contenait littéralement « LdapRecord/Kerberos » (faux positif du critère).

### Completion Notes List

- **T1-T5 conformes au plan**, aucun écart. Migration idempotente types simples (testée SQLite via RefreshDatabase + appliquée Postgres VM 54 ms).
- **Sémantique rotation précisée en implémentation** (cohérente AC4 + décision design n° 2) : auth via `previous` → ré-émission **systématique** (pas seulement à échéance) — sinon, après une réponse perdue, `rotated_at` vient d'être rafraîchi et le poste ne recevrait plus jamais de nouveau token (grâce éternelle). Auth via courant + grâce ouverte → `confirmRotation()`. Échéance → `rotateFor()`. Header posé sur la réponse après `$next()`.
- **Anti-clone** : MAC comparée `strtolower(trim())` (iso mutateur) ; hostname comparé insensible à la casse (sémantique hostname, évite les faux warnings) ; header absent OU fiche sans mac/name → pas de détection. Le 403 clone répond `AGENT_QUARANTINED` (le poste EST mis en quarantaine) ; check-in non horodaté sur la requête de détection elle-même (le requérant est suspect), horodaté sur tous les passages quarantaine suivants (FR15).
- **Pas de chemin d'archivage branché** : l'UI machine n'expose pas d'action « archiver » (seul `archived_at` existe en colonne + scope) — renvoi 25.x conforme Dev Notes : brancher `revokeFor()` à l'événement d'archivage quand il existera dans l'UI.
- **UI** : card « Agent » sur l'onglet Général (badge Enrôlé/Quarantaine/Jamais enrôlé + dates rotation/check-in/quarantaine + bouton révocation `wire:confirm` → `revokeAgentToken()` method-injection `TokenRotationService`).
- **Tests** : 7 unit + 16 feature (les 11 scénarios T8 + format erreur SE5, 401 révoqué ≡ 401 inconnu byte-identique, MAC uppercase canonisée, headers absents, pas de header rotation sur token frais). `--filter Agent` sur /vm : **42 passed (160 assertions)** — les 19 tests 23.1 inclus, verts.
- **VM** : `php artisan migrate` (colonnes posées), `php artisan config:cache` + `chown www-admin` faits (logging.php + agent.php pris en compte).
- Collision routes `agent.v1.*` : non touchée (aucune route applicative créée ici), à résoudre en 23.3/23.5 comme prévu.

### Post-review (2026-06-11, /bmad-code-review 3 layers — claude-opus-4-8, fixes claude-fable-5)

Verdict layers : **7/7 AC conformes, 0 violation bloquante** (les 2 points signalés — ré-émission systématique via previous, grâce préservée — confirmés corrects). 6 correctifs de durcissement appliqués au middleware :

- **P1 (Majeur)** — comparaison MAC anti-clone via `MacAddressNormalizer::normalize()` des **deux** côtés (forme canonique `aa:bb:cc:dd:ee:ff`) au lieu de `strtolower(trim())` : l'agent Windows émettra `AA-BB-CC-DD-EE-FF` (ipconfig) → l'ancien code aurait quarantainé tout poste légitime au premier check-in. Format non reconnu → pas de détection (jamais de fausse quarantaine).
- **D1 (Majeur)** — bloc rotation/confirmation/check-in sérialisé sous `DB::transaction` + re-fetch `lockForUpdate` (état ré-évalué sous verrou) : deux check-ins simultanés ne peuvent plus s'écraser la rotation (lock-out possible sinon, contraire à l'invariant AC4). No-op SQLite tests, `FOR UPDATE` réel Postgres.
- **P2** — `rotationDue` : `rotated_at` futur (snapshot DB restauré) = incohérent → rotation immédiate ; `->copy()->addDays()`.
- **P3** — plancher `max(1, …)` sur `token_rotation_days` (0/négatif aurait déclenché une rotation par check-in du parc).
- **P4** — lookup OR groupé en closure (défensif vs futur global scope sur Workstation).
- **P5** — `Str::limit(255)` sur le hostname client avant log (anti log-injection).

+4 feature tests (MAC tirets acceptée, format MAC non parseable = pas de détection, `rotated_at` futur → rotation, `rotation_days=0` → pas de tempête). Doc `token-lifecycle.md` mise à jour (§4 plancher, §5 normalisation, §7 verrou). **Defer notés** : throttle/rate-limit à câbler à la création des vraies routes (→ **23.5/24.1**) ; threat-model « clone qui omet les headers » = design AC5 assumé (→ Epic 24).

Tests post-fix `/vm` : `--filter Agent` **46 passed (169 assertions)** ; suite complète **3984 passed (22006 assertions), 2 failed pré-existants hors scope** (les mêmes : WpkgReportApiTest 15.6, GpoIndexExportTest). Zéro régression.

### File List

**Créés :**
- `database/migrations/2026_06_11_120000_add_agent_token_columns_to_workstations.php`
- `app/Services/Agent/Enrollment/TokenRotationService.php`
- `app/Http/Middleware/AuthenticateAgentToken.php`
- `app/Providers/AgentServiceProvider.php`
- `config/agent.php`
- `docs/agent/token-lifecycle.md`
- `tests/Unit/Services/Agent/TokenRotationServiceTest.php`
- `tests/Feature/Api/V1/Agent/AuthenticateAgentTokenTest.php`

**Modifiés :**
- `app/Models/Workstation.php` (casts + helpers `isAgentEnrolled`/`isAgentQuarantined` + docblock @property)
- `config/app.php` (enregistrement `AgentServiceProvider`)
- `config/logging.php` (channel `agent`)
- `resources/views/pages/parc/machines/[id]/index.blade.php` (méthode `revokeAgentToken()` + card « Agent »)

### Change Log

- 2026-06-11 — Story 23.2 implémentée (claude-fable-5) : colonnes `agent_*`, `TokenRotationService`, middleware `AuthenticateAgentToken` (alias `agent.token`), channel log `agent`, `config/agent.php` minimal, bouton UI révocation, doc `token-lifecycle.md`, 23 tests. Tests `--filter Agent` /vm : 42 passed. Suite complète /vm : 3980 passed, 2 failed pré-existants hors scope (WpkgReportApiTest 15.6, GpoIndexExportTest). Status → review.
- 2026-06-11 — Code review 3 layers (Blind Hunter / Edge Case Hunter / Acceptance Auditor, claude-opus-4-8) : 7/7 AC conformes, 0 bloquant. 6 fixes appliqués (claude-fable-5) : normalisation MAC anti-clone via `MacAddressNormalizer` (P1), rotation sous `lockForUpdate` (D1), `rotated_at` futur = rotation (P2), plancher rotation_days (P3), OR groupé (P4), borne log hostname (P5). +4 tests. `--filter Agent` /vm : 46 passed. Suite complète : 3984 passed, mêmes 2 failed pré-existants. Defer → throttle en 23.5/24.1.
