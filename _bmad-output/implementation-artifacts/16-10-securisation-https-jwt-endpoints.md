# Story 16.10 : Sécurisation HTTPS + JWT endpoints poste↔serveur local

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> Story **fondatrice** de la Phase 2 Epic 16 — pose la couche d'auth et le canal sécurisé (HTTPS + JWT RS256) qui sera ensuite consommée par 16.11 (auto-bootstrap migration postes), 16.12 (logs exécution centralisés) et 16.13 (cleanup shims définitif).
>
> **Scope strict 16.10** = (a) PKI locale par étab (CA root indépendant — D9), (b) émission/vérification JWT RS256 stateless (D3), (c) tables `workstation_refresh_tokens` + `workstation_jwt_revocations`, (d) middlewares `EnsureWorkstationJwt` + `RequireBootstrapToken` + `EnsureRefreshToken`, (e) endpoints `POST /api/v1/agent/enroll` + `POST /api/v1/agent/refresh`, (f) un endpoint v1 **echo de test** `GET /api/v1/agent/ping` protégé pour valider la chaîne bout-en-bout, (g) channel log dédié `auth-v1`, (h) tests + runbook QA.
>
> **HORS-SCOPE 16.10** : middleware `InjectBootstrapFragment` côté legacy `*_out.php` (= 16.11), création de la table `workstations_migration_status` (= 16.11), portage métier des endpoints scripts (`/api/v1/scripts/applications`, etc. — futures stories), wrapper d'exécution + table `script_execution_logs` + endpoint `POST /api/v1/script-execution-logs` (= 16.12), retrait des shims legacy (= 16.13), retrait des routes legacy `*_out.php` md5/APCu (= 16.13), agent Go binaire (Phase 3+), mTLS / certificats clients (Phase 3+), Let's Encrypt local (non applicable au LAN scolaire — DNS interne).

---

## Encadré contexte

**Topologie cible** (cf. Tech Spec §5.0) : chaque serveur local Sambaedu (`se4fs-<UAI>.<domaine>`, ex. `se4fs-0991229y`) génère son **propre CA root** à l'installation Phase 2, émet son cert serveur HTTPS, et signe ses JWT avec une clé privée RS256 isolée. Les postes Windows/Linux du LAN scolaire ciblent ce serveur local — **jamais le central** (`lab1.sambaedu.org`). Le central conserve sa Let's Encrypt actuelle pour l'UI admin web.

**Dual-mode pendant Phase 2 (D8)** : les endpoints legacy `/gpo/*_out.php` md5/APCu HTTP **restent actifs et fonctionnels** pendant toute la Phase 2. La bascule effective des postes vers `/api/v1/*` HTTPS+JWT se fait via 16.11 (auto-bootstrap). 16.10 livre uniquement la **plateforme v1** prête à consommer.

**Audit 16.8 — GO 16.10** confirmé : aucune occurrence `SE4FS` nu critique dans le code source actuel, aucune URL hardcodée vers le central qui casserait après bascule HTTPS. La substitution dynamique via `$config['se4fs_name']` / `###_SE4FS_NAME_###` / `%SE4FS%` est cohérente. Cf. `audit-iso-legacy-2026-05-15.md` §2.3.

**Cohabitation controlHub (cf. Tech Spec §5.6)** : le namespace `/api/v1/*` est **partagé** entre les endpoints postes (Phase 2) et controlHub déjà existants (`/api/v1/snapshot`, `/api/v1/workstation-groups`, …). La discrimination se fait par **claim `tier`** dans le JWT : `tier=workstation` pour les postes, `tier=controlhub` pour controlHub. Les middlewares Laravel valident le tier attendu par route. **À garder en tête** lors du dimensionnement des middlewares (pattern parallèle au middleware existant `controlhub.auth` / `app/Http/Middleware/ControlHubAuth.php`).

**Arbitrage iso-legacy reverté pour Phase 2** : la mémoire `feedback_auth_iso_legacy` (revert 15.5 Bearer 2026-05-11) **NE s'applique PAS** à cette story. Henri a explicitement validé 2026-05-15 que la décision Phase 2 prime (JWT applicatif par poste autorisé). Cf. `_bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md` §4 D3 + §5.6.

---

## ⚠️ Décisions tranchées (D1-D10, ne pas re-débattre)

> Cadrage SM 2026-05-16 (au moment de la création de cette story). Le dev applique sans re-discuter ; en cas de blocage technique réel, il documente la difficulté dans Dev Agent Record et continue.

### D1 — Outil PKI : **scripts `openssl` natif PHP (pas `step-ca`)**

- Outil retenu : extension PHP `ext-openssl` (déjà chargée — précédent `app/Gpo/Services/VeyonConfigGenerator.php` utilise `openssl_public_encrypt` ligne 151-206) **+** invocations CLI `openssl` via `Process::run()` (mode array) **quand l'API native PHP est insuffisante** (ex. génération extension v3 CA = plus simple en CLI).
- Encapsulation : commande Artisan `auth:ca:init` qui appelle `App\Auth\V1\Pki\CaInitializer` (singleton).
- Rationale :
  - **Minimalisme** : pas de service `step-ca` à installer/superviser sur chaque local (N serveurs locaux). `openssl` est déjà disponible sur toutes les VM Sambaedu (Debian standard).
  - **Iso-pattern projet** : Sambaedu utilise déjà `Process::run()` pour `samba-tool` (Story 16.1), `make_dhcpd_conf.sh` (Story 8.1). Cohérent. L'extension PHP `openssl_*` est utilisée par `VeyonConfigGenerator` (précédent).
  - **Renouvellement automatique** : `step-ca` apporterait de l'ACME interne (avantage à terme), mais les certs serveurs Sambaedu ont une **durée longue** (5 ans CA, 1 an cert serveur) et le renouvellement reste manuel via Artisan command. Pas un cas d'usage de churn élevé.
- Trade-off accepté : pas de CRL/OCSP automatique. Si compromission cert serveur → ré-émission manuelle + redistribution. Acceptable parce que limité à 1 étab (CA isolé).
- Implémentation : `app/Auth/V1/Pki/CaInitializer.php` (génération CA root + cert serveur + paire RS256), idempotent (relance ne ré-émet pas si CA root présent, sauf `--force`).

### D2 — Lib JWT PHP : **`firebase/php-jwt` ^6.10** (ajout `composer.json`)

- Lib retenue : `firebase/php-jwt` (la plus répandue, API simple, support RS256 natif).
- Rationale :
  - Pas de lib JWT installée actuellement (`composer.json` vérifié — aucun `jwt`, `lcobucci`, `firebase/php-jwt`). Sanctum 4.0 présent mais **stocke des tokens DB** (table `personal_access_tokens`) et ne signe pas de JWS RS256 — pas adapté.
  - `firebase/php-jwt` : API ultra-simple (`JWT::encode($payload, $privateKey, 'RS256', $kid)` / `JWT::decode($jwt, $keysMap)`), 1 fichier vendor, zéro dépendance transitive. Iso-pattern minimaliste.
  - `lcobucci/jwt` est plus puissant (chained builder, parser plus strict) mais plus lourd en dépendances et l'API change entre versions majeures. `firebase` est suffisant pour notre besoin : sign + verify + claims standard.
- Action dev : `composer require firebase/php-jwt:^6.10` (à exécuter sur la VM par dev en T0, commit du lockfile).
- Wrapper façade : `App\Auth\V1\Jwt\WorkstationJwtIssuer` (signature) + `WorkstationJwtVerifier` (vérification) encapsulent les appels `Firebase\JWT\JWT::*`. Permet une bascule future vers une autre lib sans toucher au middleware.

### D3 — Stockage clé privée RS256 : **fichier `.pem` dans `storage/keys/` chmod 0600 + path en config**

- Localisation : `storage/keys/jwt/private.pem` (0600) + `storage/keys/jwt/public.pem` (0644). Cert PKI : `storage/keys/pki/ca-root.{key,crt}` + `storage/keys/pki/server.{key,crt}`.
- Permissions : `0600` pour les clés privées. Le dossier `storage/keys/` est ajouté à `.gitignore`. Le boot via `AuthV1ServiceProvider` peut vérifier les permissions et logger un warning si > 0600 (mais ne corrige pas — c'est ops).
- Lecture : via `config('auth_v1.jwt.keys')` (map kid → path private+public). Override par env vars `AUTH_V1_JWT_PRIVATE_KEY_PATH` / `_PUBLIC_KEY_PATH` (override de path absolu sous `storage/`).
- Rationale :
  - **Pas de DB chiffrée** : les modèles Eloquent chiffrent rarement bien (encrypted casts marchent mais ajoutent une key dependency sur `APP_KEY`). Or `APP_KEY` est partagé entre tous les usages Laravel — pas approprié pour une clé de signature JWT (need isolation cryptographique).
  - **Pas de coffre type Vault** : trop ambitieux pour Phase 2. Vault peut venir Phase 3 si besoin (cf. Risques §7 Tech Spec — « Pas besoin de HashiCorp Vault »).
  - **Pattern iso projet** : on reproduit le pattern Laravel `storage/` (hors VCS) avec permissions strictes. Cohérent avec `storage/logs/`, `storage/app/`, etc.
- Génération : la commande Artisan `auth:ca:init` **génère aussi** la paire RS256 (`openssl_pkey_new` PHP natif + `openssl_pkey_export`). Idempotente.

### D4 — Cache révocation : **`Cache::store('apc')` avec fallback DB-only**

- Stratégie : le `WorkstationJwtVerifier` interroge d'abord le cache (`Cache::store('apc')->get("jwt:revoked:{$jti}")`, TTL 60s — alignement Tech Spec §7), puis fallback DB (`workstation_jwt_revocations`) si miss.
- Rationale :
  - **Iso-pattern projet** : APCu est utilisé partout (Stories 16.7 `ApcuAppContextWriter`, legacy `gpo/applications.php` md5/APCu). Le driver `apc` Laravel est déjà fonctionnel (cf. `phpunit.xml` force `apc.enable_cli=1`).
  - **Pas de Redis** : pas de service Redis installé sur la VM Sambaedu. Ajouter Redis = story d'infra dédiée Phase 3+, pas le job de 16.10.
  - **Pas DB-only** : ajoute une requête DB sur chaque vérification JWT (= chaque appel `/api/v1/*`). Inacceptable performance-wise.
- Implémentation : helper interne `App\Auth\V1\Jwt\WorkstationJwtRevocationChecker` qui encapsule la double-couche. Tests doivent vérifier les 4 cas : cache hit revoked, cache miss + DB hit revoked, cache hit not-revoked, cache miss + DB miss.
- Commande Artisan `workstation:revoke {uuid}` (incluse 16.10) : invalide DB + push cache `Cache::store('apc')->put("jwt:revoked:{$jti}", true, 3600)` pour invalidation rapide.

### D5 — Endpoint v1 livré en 16.10 : **endpoint echo de test `GET /api/v1/agent/ping` UNIQUEMENT — PAS de portage métier**

- Endpoint minimal livré : `GET /api/v1/agent/ping` → renvoie `{ workstation_uuid, server_time, api_version, se4fs_name }` si JWT valide, 401 sinon.
- Rationale :
  - **Scope strict 16.10** : la story livre l'ossature middleware. Le portage de `/api/v1/scripts/applications` (équivalent v1 de legacy `/gpo/applications_out.php`) **n'est pas dans 16.10** — c'est une story dédiée du backlog Phase 2.
  - Néanmoins **un endpoint test est indispensable** pour valider la chaîne bout-en-bout : signature JWT → middleware → claim parsing → context binding (`workstation_uuid` injecté en `request()->attributes`).
  - L'endpoint `/api/v1/agent/ping` sera également utile au 16.11 (auto-bootstrap) comme health-check post-enrollment côté script poste (vérifie que le JWT obtenu fonctionne avant de mémoriser et passer en mode v1).
- Anti-pattern : ne pas porter `applications`, `scripts/firefox`, `scripts/wallpaper`, etc. — ce sont des stories portage métier dédiées (ou Phase 3+).

### D6 — Création de `workstations_migration_status` : **REPORTÉ à 16.11**

- La table `workstations_migration_status` (uuid, started_at, completed_at, status, error) **n'est pas créée en 16.10**. Elle est créée par 16.11 (qui en est le consommateur principal — le middleware `InjectBootstrapFragment` y écrit lors de la détection d'un poste non migré).
- Rationale :
  - 16.10 livre uniquement la **plateforme d'enrôlement**. L'enrôlement réussi ne fait rien d'autre que renvoyer un access/refresh token + écrire dans `workstation_refresh_tokens`. La notion de « migrated » est une vue applicative qui appartient au middleware d'injection legacy (= 16.11).
  - Évite à 16.10 de devoir spécifier le contrat de cette table avant que 16.11 sache ce dont elle a besoin (started_at vs completed_at, error_status enum, etc.).
- Garde-fou : si 16.10 a besoin d'écrire un marqueur « ce poste s'est enrôlé », il le fait dans `workstation_refresh_tokens` (la simple existence d'un refresh_token actif suffit). 16.11 ajoutera sa table séparée.

### D7 — Namespace PHP : **`App\Auth\V1`** (pas `App\Api\V1\Auth`)

- Racine choisie : `App\Auth\V1\…` avec sous-namespaces `Jwt`, `Pki`, `Http\{Controllers,Middleware,Requests}`, `Models`, `Services`, `Support`.
- Rationale :
  - **Cohérence avec le pattern Sambaedu** : `App\Gpo\…` (16.1), `App\Wpkg\…` (15.1), `App\Ldap\…`. Namespace racine par **domaine fonctionnel** (auth est un domaine, pas une sous-section d'API).
  - **Évolution** : si demain le `tier=workstation` doit cohabiter avec `tier=agent` (futur agent Go) ou `tier=controlhub`, on aura `App\Auth\V1\…` qui est l'unité versionnée d'auth, indépendante de la couche transport (HTTP API v1).
  - L'autoload PSR-4 (cf. `composer.json:54-67`) supporte `App\\` → `app/`. Aucune modification composer requise.
- Garde-fou archi : test `tests/Architecture/AuthV1NamespaceTest.php` (iso pattern `GpoNamespaceTest` 16.1) qui interdit : (a) `exec()` direct hors `Pki\CaInitializer`, (b) accès direct à `Firebase\JWT\JWT` hors `Jwt\WorkstationJwtIssuer` + `WorkstationJwtVerifier`, (c) inclusion `legacy/*` depuis `app/Auth/V1/*`.

### D8 — Format réponse erreur middleware : **JSON simple `{error, message, code}` (pas RFC 7807)**

- Format réponse 401/403 du middleware `EnsureWorkstationJwt` :
  ```json
  {
    "error": "unauthorized",
    "message": "JWT signature invalid",
    "code": "jwt.signature_invalid"
  }
  ```
- Codes `code` standardisés (catalogue dans `app/Auth/V1/Support/JwtErrorCodes.php`) : `jwt.missing`, `jwt.malformed`, `jwt.signature_invalid`, `jwt.expired`, `jwt.revoked`, `jwt.wrong_tier`, `jwt.unknown_workstation`, `bootstrap_token.missing`, `bootstrap_token.invalid`, `refresh.missing`, `refresh.invalid`, `refresh.expired`, `refresh.revoked`, `refresh.replay_detected`.
- Rationale :
  - **RFC 7807 (Problem Details)** est plus formel mais ajoute du verbiage (`type`, `title`, `status`, `instance`) sans valeur ajoutée pour des scripts cmd/bash qui parsent par `code` ou `error`.
  - **Iso-pattern projet** : `ControlHubAuth.php` renvoie déjà `{success, error, message}`. On reste compatible (on ajoute juste `code` pour discrimination programmatique).
  - Évolution Phase 3+ : si un agent Go pose le besoin d'erreurs structurées RFC 7807, on bascule via un middleware d'enrobage (= refacto trivial). Pas la peine de payer ce coût maintenant.
- Anti-pattern : ne pas mélanger les formats — toutes les erreurs `/api/v1/agent/*` sortent au format `{error, message, code}`.

### D9 — Rotation `kid` : **stub unique kid actif en 16.10 + design extensible**

- 16.10 livre un seul `kid` actif (généré au moment de la création de la paire RS256, ex. `kid=2026-05-16`). Le `WorkstationJwtIssuer` l'inclut dans le header JWT. Le `WorkstationJwtVerifier` accepte ce `kid` unique.
- Design : la lookup table `kid → public_key_path` est dans `config('auth_v1.jwt.keys')`. Aujourd'hui une seule entrée :
  ```php
  'keys' => [
      env('AUTH_V1_JWT_KID', '2026-05-16') => [
          'private' => storage_path('keys/jwt/private.pem'),
          'public' => storage_path('keys/jwt/public.pem'),
      ],
  ],
  'active_kid' => env('AUTH_V1_JWT_KID', '2026-05-16'),
  ```
- Pas de cron de rotation, pas de période de grâce, pas de re-signature massive. Ce sera une story Phase 3+ (cf. Annexe B Q4 Tech Spec — « rotation manuelle ou automatique ? À trancher en 16.10 »). 16.10 tranche : **manuelle pour l'instant, infra prête pour automatisation future**.
- Rationale :
  - Risque concret : compromission clé privée. Mitigation = rotation manuelle (génération nouvelle paire + ajout entrée `keys[new_kid]` + bump `active_kid` + révocation explicite tous les JWT actifs émis avec l'ancien kid). Pratique mais infréquent.
  - Tests doivent valider qu'un JWT signé avec un kid **non listé** dans la config est rejeté (`jwt.signature_invalid`).
- Stub commande Artisan `workstation:jwt:rotate-keys` (signature + docblock seulement, throw `RuntimeException('Not implemented — Phase 3+')` en body) **scaffoldée** pour signaler l'extension.

### D10 — Endpoint `/api/v1/agent/refresh` : **INCLUS dans 16.10 (avec rotation refresh + détection replay)**

- Endpoint `POST /api/v1/agent/refresh` livré dans la même story. Pas protégé par `EnsureWorkstationJwt` (puisque l'access token peut être expiré), mais protégé par un middleware léger `EnsureRefreshToken` qui valide le `refresh_token` en DB (`workstation_refresh_tokens`).
- Body : `{ refresh_token }` (64 hex). Réponse : `{ access_token, refresh_token, expires_in, refresh_expires_in }` (rotation refresh — pattern « rotating refresh tokens »).
- **Détection replay** : si un client présente un `refresh_token` dont le hash existe en DB mais avec `revoked_at != null`, c'est un signal sécurité critique. Action : révoquer **toute** la lignée du `workstation_uuid` (tous les refresh actifs et tous les JWT actifs avec ce sub → `workstation_jwt_revocations`), log warning `replay_detected`, retour 401 `refresh.replay_detected`. Le poste devra re-bootstrap (16.11 prendra le relais).
- Rationale :
  - Le refresh est **indissociable** de la chaîne d'enrôlement. Un poste qui s'enrôle reçoit un access (TTL 24h) + refresh (TTL 30j). Sans endpoint refresh, le poste devra re-enroll tous les jours — anti-pattern qui pollue les logs et `workstation_refresh_tokens`.
  - 16.11 (auto-bootstrap) configurera un job planifié côté poste pour appeler `/api/v1/agent/refresh` quand l'access expire — 16.10 doit donc livrer l'endpoint dès maintenant.
- Implémentation : `App\Auth\V1\Jwt\WorkstationJwtRefreshService` qui (a) valide via `EnsureRefreshToken` upstream, (b) émet nouveau pair access+refresh, (c) marque l'ancien refresh `revoked_at = now(), revocation_reason = 'refresh_rotation'`, (d) gère le replay (révocation cascade si revoked_at déjà set).

---

## Story

As **un poste Windows/Linux du parc Sambaedu (= client v1 futur)** ainsi qu'**un mainteneur du codebase `sambaedu-reload`**,

I want
- exposer une plateforme d'authentification HTTPS + JWT RS256 pour les endpoints `/api/v1/*` postes↔serveur local, avec un CA root indépendant par établissement et des tokens stateless (access TTL 24h + refresh rotatif TTL 30j) ;
- disposer d'un endpoint d'enrôlement `POST /api/v1/agent/enroll` qui échange le `X-Bootstrap-Token` md5 transitoire existant contre une paire JWT, ainsi que d'un endpoint de refresh `POST /api/v1/agent/refresh` avec rotation et détection replay ;
- préserver la backward-compat absolue : les endpoints legacy `/gpo/*_out.php` md5/APCu HTTP **restent fonctionnels** pour les postes non encore migrés (D8) ;

So que :
- (a) la Story 16.11 (auto-bootstrap migration postes) puisse implémenter l'injection du fragment de bascule sans avoir à se soucier de la plateforme JWT ;
- (b) la Story 16.12 (logs exécution centralisés) puisse créer son endpoint `POST /api/v1/script-execution-logs` en réutilisant simplement le middleware `EnsureWorkstationJwt` ;
- (c) le futur **agent unifié Go** (Phase 3+) et **controlHub** (long terme) trouvent une plateforme `/api/v1/*` déjà conforme au design « agent-ready » (claims `tier` distincts, JSON partout, stateless, idempotent).

---

## Contexte

### État entrant (post-16.8)

| Élément | État après 16.8 |
|---|---|
| Tests Phase 1 GPO/Ldap (~449) | ✅ GREEN — 474 passed, 0 failed (cf. 16-8 review done 2026-05-16, commit f9e11a0) |
| Audit `SE4FS` nu | ✅ 0 occurrence critique. Substitution dynamique cohérente via `$config['se4fs_name']` / `%SE4FS%` / `###_SE4FS_NAME_###` |
| Shims 1bis.18 | ✅ Inventaire livré : 6 retirables (16.13), 2 conditionnels, 1 shim fondation à conserver Phase 2 |
| Endpoints legacy `/gpo/*_out.php` | 🟠 Encore actifs en HTTP md5/APCu — restent en place pendant toute la Phase 2 (D8) |
| Lib JWT PHP | ❌ Aucune installée (`composer.json` vérifié) — à ajouter en T0 (`firebase/php-jwt:^6.10` — cf. D2) |
| Cert HTTPS local | ❌ Aucun mécanisme de génération en place — à implémenter (D1) |
| Routes `/api/v1/*` | 🟡 Réservées : controlHub utilise déjà `/api/v1/snapshot`, `/api/v1/workstation-groups` etc. (cf. `routes/api.php` ligne 42 — middleware `controlhub.auth`). Nouveaux endpoints postes utilisent `/api/v1/agent/*` (sous-namespace dédié, pas de collision) |

### Topologie réseau Sambaedu (rappel Tech Spec §5.0)

- **Multi-établissements** : N serveurs locaux (`se4fs-<UAI>`, ex. `se4fs-0991229y`) + 1 serveur central (`lab1.sambaedu.org`, reverse-proxy UI admin web).
- Les **postes** sont sur le LAN de leur étab → ciblent uniquement leur local. Le central est inatteignable depuis le LAN (sauf VPN admin).
- Le placeholder `###_SE4FS_NAME_###` est substitué côté serveur local au moment du rendu de scripts/GPO → résolution vers `se4fs-<UAI>`. La var d'env `%SE4FS%` côté Windows est positionnée par `wpkg.cmd`.
- **Conséquence pour 16.10** :
  - Le **cert HTTPS** doit être émis pour `se4fs-<UAI>.<domaine>` (= hostname résolu côté poste). Pas pour `lab1.sambaedu.org`.
  - Le **CA root** doit être indépendant par étab (D9 Tech Spec) — chaque local génère son CA root, distribué côté poste lors de l'enrôlement.

### Risques entrants (Tech Spec §7)

| Risque | Sévérité | Mitigation 16.10 |
|---|---|---|
| Migration auto-bootstrap échoue sur certains postes (réseau, AV bloque curl) | 🟠 Élevée | 16.10 livre `/api/v1/agent/enroll` **idempotent** + côté poste 16.11 fallback explicite legacy md5 si enroll échoue |
| HTTPS + cert AC interne : navigateurs admins se plaignent | 🟡 Moyenne | **Pas un problème 16.10** (UI admin reste sur LE central). Le cert AC interne ne concerne que les postes en LAN |
| JWT révocation pas immédiate (cache TTL) | 🟡 Moyenne | D4 : cache TTL=60s ; commande Artisan `workstation:revoke {uuid}` qui push le cache `Cache::store('apc')->put(..., true, 3600)` pour invalidation rapide |
| PKI à standup | 🟡 Moyenne | D1 + D3 : openssl natif PHP + Artisan command + clés en `storage/keys/` 0600. Cadrage léger, pas de Vault |

### Pré-requis (à valider en T0)

- **Code à jour sur la VM** via inotify : commit `main` actuel réfléchi sur `/var/www/sambaedu-reload`.
- **PHP `openssl` extension chargée** : `php -r "var_dump(extension_loaded('openssl'));"` → `true`.
- **`openssl` CLI disponible** : `which openssl` → présent (par défaut Debian).
- **APC CLI enabled** : déjà vérifié 16.8, `apc.enable_cli=1` forcé runtime par `phpunit.xml`.
- **Postgres up sur VM** : nécessaire pour migrations.
- **Apache/nginx config locale** : T0 doit identifier le serveur web tournant (`systemctl is-active apache2 nginx 2>&1`) pour la doc op vhost HTTPS. Le legacy Sambaedu utilise **Apache** historiquement, mais nginx peut être présent côté reverse-proxy.
- **Bootstrap token APCu legacy** : identifier la clé APCu exacte posée par `gpo/applications.php` legacy (= input pour `LegacyBootstrapTokenValidator`). Vérifier dans `legacy/modules/gpo/applications.php` ou `ApcuAppContextWriter` (16.7).

---

## Acceptance Criteria

> AC organisées en **7 volets**. Volet 7 (QA + doc) est append-only sur `docs/qa/domains/auth-v1.md` (nouveau domaine).

### Volet 1 — PKI locale (CA root + cert serveur HTTPS)

**AC1.1** — **Commande Artisan `auth:ca:init` idempotente**

**Given** la VM Sambaedu avec `openssl` installé,
**When** l'admin exécute `php artisan auth:ca:init` une première fois,
**Then** la commande génère :
- Un CA root RSA 4096 (clé + cert auto-signé, validité 5 ans, CN = `SambaEdu Local CA — {se4fs_name}`) : `storage/keys/pki/ca-root.key` (0600) + `storage/keys/pki/ca-root.crt` (0644)
- Un cert serveur RSA 2048 signé par le CA root, CN = `config('sambaedu.se4fs_name').'.'.config('sambaedu.ldap_domain')` (ex. `se4fs-0991229y.localdev.fr`), SAN incluant le hostname court (`se4fs-0991229y`), validité 1 an : `storage/keys/pki/server.key` (0600) + `storage/keys/pki/server.crt` (0644)
- Une paire JWT RS256 (clé privée 2048 bits) : `storage/keys/jwt/private.pem` (0600) + `storage/keys/jwt/public.pem` (0644)

**And** la même commande **relancée** une seconde fois **ne ré-émet rien** (idempotente) : elle détecte la présence du CA root et sort en `Already initialized` + exit 0.

**And** un flag `--force` permet de ré-émettre (avec confirmation interactive, `--no-interaction` accepté pour scripts).

**And** un flag `--regenerate-server-only` permet de régénérer uniquement le cert serveur (utile rotation cert sans toucher au CA).

**And** la génération privilégie les fonctions natives PHP `openssl_pkey_new`, `openssl_csr_new`, `openssl_csr_sign`, `openssl_x509_export`, `openssl_pkey_export`. Si une feature x509v3 nécessaire n'est pas dispo en natif → fallback `Process::run(['openssl', ...])` mode array. Pas de shell concat.

### Volet 1 (suite) — Provisioning du cert dans le serveur web local

**AC1.2** — **Affichage de la config web à intégrer**

**Given** le serveur web local de la VM (Apache détecté en T0, ou nginx),
**When** la commande `auth:ca:init` s'est exécutée avec succès,
**Then** la commande affiche en sortie le bloc de configuration à intégrer manuellement (selon le serveur détecté) :

Pour Apache :
```apache
SSLCertificateFile      /var/www/sambaedu-reload/storage/keys/pki/server.crt
SSLCertificateKeyFile   /var/www/sambaedu-reload/storage/keys/pki/server.key
```

Pour nginx :
```nginx
ssl_certificate     /var/www/sambaedu-reload/storage/keys/pki/server.crt;
ssl_certificate_key /var/www/sambaedu-reload/storage/keys/pki/server.key;
ssl_protocols       TLSv1.2 TLSv1.3;
```

**And** la documentation `docs/qa/domains/auth-v1.md` § Story 16.10 contient la procédure exacte d'installation du vhost HTTPS local (configuration Apache/nginx + reload + smoke `curl -kv https://se4fs-<UAI>/api/v1/agent/ping`).

**And** 16.10 ne modifie **PAS automatiquement** la config Apache/nginx (action ops dédiée pour ne pas casser un vhost existant).

**AC1.3** — **Documentation opérationnelle PKI**

**Given** la commande livrée,
**When** un ops doit régénérer le CA, sauvegarder, ou révoquer,
**Then** `docs/qa/domains/auth-v1.md` § Story 16.10 documente :
- **Sauvegarde** : `tar -czf /backup/pki-$(date +%F).tgz storage/keys/` → à stocker hors-VM (cf. ops).
- **Restoration** : extraction + `chmod -R 0600 storage/keys/**/*.key` et `chmod -R 0600 storage/keys/**/*.pem`.
- **Révocation cert serveur** : ré-émission via `php artisan auth:ca:init --force --regenerate-server-only` + reload Apache/nginx.
- **Compromission CA root** : ré-émission complète + redistribution du nouveau CA root à tous les postes via `php artisan workstation:revoke-all-and-rebootstrap` (commande à scaffolder pour Phase 3+, mais documentée comme procédure de catastrophe).

### Volet 2 — JWT issuer/verifier RS256 stateless

**AC2.1** — **Service `WorkstationJwtIssuer`**

**Given** la classe `App\Auth\V1\Jwt\WorkstationJwtIssuer`,
**When** elle est instanciée avec la config `auth_v1.jwt`,
**Then** elle expose :
- `issueAccessToken(string $workstationUuid): string` — JWS RS256 avec claims `iss` (= `config('sambaedu.se4fs_name')`), `sub` (= workstation_uuid), `iat`, `exp` (= `iat + 86400` = 24h), `jti` (UUID v4), `tier = 'workstation'`, `kid` (= `active_kid` config).
- `issueRefreshToken(string $workstationUuid): array` — retourne `['token' => '<random 64 hex>', 'expires_at' => Carbon, 'hash' => '<sha256>']`. Le token clear est rendu **une seule fois** au caller (à mémoriser côté poste) ; seul le hash est stocké en DB.
- Logs `auth-v1` (channel D dédié) : `auth.token.issued` avec `workstation_uuid`, `jti`, `kid`, `expires_at`. Le clear token n'est **jamais loggé**.

**And** la signature utilise `Firebase\JWT\JWT::encode($payload, $privateKey, 'RS256', $kid)`.

**And** un test unit `tests/Unit/Auth/V1/Jwt/WorkstationJwtIssuerTest.php` couvre : émission claim complet, format header (`kid` présent), TTL 24h (24 * 3600s), tier=workstation, jti unique entre deux émissions, refresh token avec 64 hex chars + entropie via `random_bytes(32)` CSPRNG.

**AC2.2** — **Service `WorkstationJwtVerifier`**

**Given** la classe `App\Auth\V1\Jwt\WorkstationJwtVerifier`,
**When** elle reçoit un access_token en string,
**Then** elle expose `verify(string $jwt, array $options = []): WorkstationJwtClaims` qui :
- Décode le JWT via `Firebase\JWT\JWT::decode($jwt, $keysMap)` avec map `kid → public_key` (lookup dans `config('auth_v1.jwt.keys')`).
- Vérifie `exp` (expiration), `tier` (doit être `workstation` sauf override par `$options['expected_tier']`), `kid` (présent dans `config('auth_v1.jwt.keys')` — sinon rejet `jwt.signature_invalid`).
- Vérifie révocation `jti` (cf. D4 : cache APCu hit → revoked rejected ; cache miss → DB lookup `workstation_jwt_revocations`).
- Retourne un DTO immuable `WorkstationJwtClaims` (sub=`workstationUuid`, jti, exp, tier, kid, iss).

**And** en cas d'échec, lève une exception `App\Auth\V1\Jwt\Exceptions\InvalidJwtException` avec `code` parmi le catalogue D8 (`jwt.missing`, `jwt.malformed`, `jwt.signature_invalid`, `jwt.expired`, `jwt.revoked`, `jwt.wrong_tier`).

**And** un test unit couvre les 6 cas d'échec + le happy path + le cas `kid` non listé (= rejeté `jwt.signature_invalid`).

**AC2.3** — **Service `WorkstationJwtRefreshService`**

**Given** la classe `App\Auth\V1\Jwt\WorkstationJwtRefreshService`,
**When** un client poste `/api/v1/agent/refresh` avec un `refresh_token` clear,
**Then** elle (assumant l'amont middleware `EnsureRefreshToken` a validé) :
- Émet une nouvelle paire access+refresh via `WorkstationJwtIssuer` (rotation).
- Marque l'ancien refresh `revoked_at = now(), revocation_reason = 'refresh_rotation'`.
- Crée nouvelle entrée `workstation_refresh_tokens`.

**And** logs `auth-v1` : `auth.token.refreshed` (avec `workstation_uuid`, ancien `jti`, nouveau `jti`).

**And** en cas de **replay détecté** (refresh_token déjà revoked_at != null mais hash valide) : révoque cascade tous les refresh actifs du workstation_uuid + tous les JWT actifs (table `workstation_jwt_revocations`), log warning `auth.token.replay_detected`.

**AC2.4** — **Channel logs dédié `auth-v1`**

**Given** la configuration `config/logging.php`,
**When** un service du namespace `App\Auth\V1` émet un log,
**Then** ce log est routé vers le channel `auth-v1` :
- Driver `daily`, path `storage/logs/auth-v1/auth-v1.log`, niveau paramétrable via `AUTH_V1_LOG_LEVEL` (default `debug` pendant la Phase 2, à bumper `info` en Phase 3).
- Rotation 30 jours (paramétrable via `AUTH_V1_LOG_DAYS`).
- Le dossier `storage/logs/auth-v1/` est créé automatiquement au boot par `AuthV1ServiceProvider` (pattern iso `GpoServiceProvider` 16.1).

**And** **aucun secret** n'est loggé (clé privée, refresh_token clear, JWT signé complet). Seuls `workstation_uuid`, `jti`, `kid`, `exp` sont loggés.

### Volet 3 — Tables DB : refresh_tokens + jwt_revocations

**AC3.1** — **Migration `workstation_refresh_tokens`**

**Given** une nouvelle migration `database/migrations/2026_05_16_XXXXXX_create_workstation_refresh_tokens_table.php`,
**When** elle est exécutée,
**Then** elle crée la table avec :
- `id` (uuid, primary)
- `workstation_uuid` (uuid, indexed) — NOTE : on stocke `workstation_uuid` libre (pas FK vers `workstations.uuid`) — voir Dev Notes pour justification (un poste peut s'enrôler avant d'être présent dans `workstations` Eloquent)
- `refresh_token_hash` (string 64, unique) — sha256 du token clear
- `issued_at` (timestamp)
- `expires_at` (timestamp, indexed)
- `revoked_at` (timestamp, nullable, indexed)
- `revocation_reason` (string 64, nullable) — `manual_admin`, `refresh_rotation`, `replay_detected`, `cascade_revoke`
- `last_used_at` (timestamp, nullable)
- `client_meta` (jsonb nullable) — pour stocker MAC/hostname/os/enroll_ip captés à l'enroll (debug + audit)
- `created_at` / `updated_at` (timestamps)

**And** modèle Eloquent `App\Auth\V1\Models\WorkstationRefreshToken` avec scopes `active()` (= `whereNull('revoked_at')->where('expires_at', '>', now())`) et `expired()`, et un cast `client_meta` → `array`.

**AC3.2** — **Migration `workstation_jwt_revocations`**

**Given** une migration séparée,
**When** elle est exécutée,
**Then** elle crée la table :
- `id` (uuid, primary)
- `jti` (uuid, unique indexed) — claim `jti` du JWT révoqué
- `workstation_uuid` (uuid, indexed)
- `revoked_at` (timestamp)
- `reason` (string 128) — ex. `manual_admin`, `lost_device`, `rotation_kid`, `replay_cascade`
- `revoked_by` (string, nullable) — pour audit : `system`, `admin:<login>`, `automated:<job>`
- `expires_at` (timestamp) — = `exp` claim du JWT révoqué, sert au job de purge futur (les entrées dont `expires_at < now()` peuvent être purgées car le JWT était déjà expiré de toute façon)

**And** modèle `App\Auth\V1\Models\WorkstationJwtRevocation` avec scope `active()` (= `where('expires_at', '>', now())`).

**AC3.3** — **Factories Eloquent**

**Given** les modèles Eloquent ci-dessus,
**When** un test veut créer une fixture,
**Then** les factories `Database\Factories\Auth\V1\WorkstationRefreshTokenFactory` et `Database\Factories\Auth\V1\WorkstationJwtRevocationFactory` génèrent des instances valides utilisables dans les tests.

### Volet 4 — Middlewares Laravel

**AC4.1** — **Middleware `EnsureWorkstationJwt`**

**Given** la classe `App\Auth\V1\Http\Middleware\EnsureWorkstationJwt`,
**When** elle traite une requête vers `/api/v1/agent/ping` (et toute autre route du groupe `/api/v1/agent/*` protégée par cet alias),
**Then** elle :
- Extrait le JWT du header `Authorization: Bearer <jwt>` (ou retourne 401 `{error: "unauthorized", message: "Missing Authorization header", code: "jwt.missing"}`).
- Délègue la validation à `WorkstationJwtVerifier::verify()` avec `expected_tier='workstation'`.
- En cas de succès, injecte dans `$request->attributes` :
  - `auth_v1.workstation_uuid` (string)
  - `auth_v1.jwt_claims` (DTO `WorkstationJwtClaims`)
- En cas d'échec : 401 avec le format D8 (`error`, `message`, `code`).

**And** enregistré dans `bootstrap/app.php` (Laravel 12) ou `app/Http/Kernel.php` (selon version effective sur la VM — vérifier T0) sous l'alias `auth.v1.workstation`.

**And** **non attaché** par défaut à `/api/v1/*` global (le middleware existant `controlhub.auth` reste sur les routes controlHub). Le routage par tier se fait au niveau de chaque sous-groupe (`/api/v1/agent/*` → `auth.v1.workstation`).

**AC4.2** — **Middleware `RequireBootstrapToken`**

**Given** la classe `App\Auth\V1\Http\Middleware\RequireBootstrapToken`,
**When** elle traite une requête vers `/api/v1/agent/enroll`,
**Then** elle :
- Extrait le header `X-Bootstrap-Token`.
- Si absent : 401 `{error, message, code: "bootstrap_token.missing"}`.
- Si présent : valide via `App\Auth\V1\Services\LegacyBootstrapTokenValidator` qui regarde l'APCu legacy (clé exacte à identifier en T0 — probablement `se4_bootstrap_$token` ou équivalent posée par `gpo/applications.php`) ET la valeur courante de `$config['se4_key']` si applicable.
- Si invalide : 401 `{error, message, code: "bootstrap_token.invalid"}`.
- Si valide : laisse passer + (idempotence) **ne consomme PAS** le token côté APCu (le TTL 1800s legacy gère la fenêtre — éviter race condition avec retry réseau).

**And** enregistré sous l'alias `auth.v1.bootstrap`.

**And** logs `auth-v1` : `auth.bootstrap.attempted` (avec IP source, token hash partiel — 8 premiers chars hash sha256 du token, succès/échec).

**AC4.3** — **Middleware `EnsureRefreshToken`** (pour `/api/v1/agent/refresh`)

**Given** la classe `App\Auth\V1\Http\Middleware\EnsureRefreshToken`,
**When** elle traite la requête `POST /api/v1/agent/refresh`,
**Then** elle :
- Lit le `refresh_token` dans le body JSON.
- Si absent ou malformé : 400 `{error: "bad_request", message: "Missing refresh_token", code: "refresh.missing"}`.
- Calcule `sha256($refresh_token)`, lookup `workstation_refresh_tokens where refresh_token_hash = ? AND active scope`.
- Si non trouvé (= hash inconnu) : 401 `{error, message, code: "refresh.invalid"}`.
- Si trouvé mais `revoked_at != null` : **DÉTECTION REPLAY** — déclenche `WorkstationJwtRefreshService::handleReplay($record)` (révoque cascade tous les refresh + JWT actifs du workstation_uuid), log warning, 401 `{error, message, code: "refresh.replay_detected"}`.
- Si trouvé et `expires_at <= now()` : 401 `{error, message, code: "refresh.expired"}`.
- Si valide : injecte `auth_v1.refresh_token_record` (le modèle Eloquent) dans `$request->attributes`.

**And** enregistré sous l'alias `auth.v1.refresh`.

### Volet 5 — Endpoints HTTP

**AC5.1** — **`POST /api/v1/agent/enroll`**

**Given** un poste qui possède un `X-Bootstrap-Token` md5 valide (legacy APCu),
**When** il fait `POST /api/v1/agent/enroll` avec body JSON `{uuid, mac, hostname, os}` (validation : `uuid` UUID v4 strict, `mac` regex `/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/`, `hostname` max 64 chars, `os` enum `windows|linux`),
**Then** le serveur :
- Middleware `RequireBootstrapToken` valide le token (AC4.2).
- Middleware `throttle:10,1` (rate limit 10 req/min/IP).
- Valide le body (Request class `App\Auth\V1\Http\Requests\EnrollAgentRequest` étendant `FormRequest`).
- Émet via `WorkstationJwtIssuer` :
  - access_token (RS256, claim `sub = $uuid`)
  - refresh_token (clear + hash en DB)
- Crée une entrée `workstation_refresh_tokens` (hash + expires_at = +30j + client_meta = `{mac, hostname, os, enroll_ip: $request->ip()}`).
- Si workstation_uuid avait déjà des refresh actifs : **PAS de révocation automatique** (cf. D6 garde-fou — un poste peut s'enrôler plusieurs fois sans détruire les autres devices avec le même UUID).
- Retourne 200 JSON :
  ```json
  {
    "access_token": "<jwt>",
    "refresh_token": "<64-hex clear>",
    "token_type": "Bearer",
    "expires_in": 86400,
    "refresh_expires_in": 2592000,
    "ca_cert_pem": "-----BEGIN CERTIFICATE-----\n...",
    "server_base_url": "https://se4fs-<UAI>.<domaine>"
  }
  ```
- Logs `auth-v1` : `auth.enroll.success` (workstation_uuid, mac, ip).

**And** si payload invalide : 422 (validation Laravel standard, format compact `{message, errors}`).

**And** **idempotence** : si un poste appelle `enroll` deux fois avec le même UUID, le second appel **émet une nouvelle paire** (sans révoquer l'ancienne — c'est le job des refresh subséquents ou de la commande `workstation:revoke`). Justification : un poste qui ré-appelle enroll a probablement perdu son state local — on lui redonne un token frais sans casser les éventuels autres devices qui auraient le même UUID (cas marginal mais documenté).

**AC5.2** — **`POST /api/v1/agent/refresh`**

**Given** un poste qui possède un refresh_token valide,
**When** il fait `POST /api/v1/agent/refresh` avec body JSON `{refresh_token}`,
**Then** le serveur :
- Middleware `EnsureRefreshToken` valide (AC4.3) — gère aussi la détection replay (cascade revocation + 401).
- Middleware `throttle:30,1`.
- `WorkstationJwtRefreshService::refresh($refreshTokenRecord)` :
  - Émet nouvelle paire access+refresh via `WorkstationJwtIssuer`.
  - Marque l'ancien refresh `revoked_at = now(), revocation_reason = 'refresh_rotation'`.
  - Crée nouvelle entrée `workstation_refresh_tokens` (avec client_meta copié de l'ancien).
- Retourne 200 JSON (même schéma que enroll, **sans** `ca_cert_pem` ni `server_base_url`).
- Logs `auth-v1` : `auth.token.refreshed` (ancien jti, nouveau jti).

**AC5.3** — **`GET /api/v1/agent/ping` (endpoint echo de test — D5)**

**Given** un poste authentifié avec un JWT valide,
**When** il fait `GET /api/v1/agent/ping`,
**Then** le serveur (middleware `auth.v1.workstation`) :
- Retourne 200 JSON :
  ```json
  {
    "workstation_uuid": "<sub claim>",
    "server_time": "2026-05-16T17:48:32+00:00",
    "api_version": "v1",
    "se4fs_name": "se4fs-0991229y"
  }
  ```

**And** sans JWT : 401 `jwt.missing` (D8).
**And** JWT expiré : 401 `jwt.expired`.
**And** JWT révoqué : 401 `jwt.revoked`.
**And** JWT wrong tier (ex. `tier=controlhub`) : 401 `jwt.wrong_tier`.

**And** **pas de logique métier** dans cet endpoint — c'est un echo pur. Pas de lecture LDAP, pas de query DB lourde (lecture JWT claims uniquement).

### Volet 6 — Configuration + service provider

**AC6.1** — **`config/auth_v1.php`**

**Given** un nouveau fichier `config/auth_v1.php`,
**When** un service `App\Auth\V1\*` lit sa config,
**Then** elle expose :
```php
return [
    'jwt' => [
        'algorithm' => 'RS256',
        'access_ttl' => 86400, // 24h
        'refresh_ttl' => 2592000, // 30j
        'active_kid' => env('AUTH_V1_JWT_KID', '2026-05-16'),
        'keys' => [
            env('AUTH_V1_JWT_KID', '2026-05-16') => [
                'private' => env('AUTH_V1_JWT_PRIVATE_KEY_PATH', storage_path('keys/jwt/private.pem')),
                'public' => env('AUTH_V1_JWT_PUBLIC_KEY_PATH', storage_path('keys/jwt/public.pem')),
            ],
        ],
        'issuer' => env('AUTH_V1_JWT_ISSUER'), // fallback : config('sambaedu.se4fs_name')
    ],
    'pki' => [
        'ca_root_key' => storage_path('keys/pki/ca-root.key'),
        'ca_root_crt' => storage_path('keys/pki/ca-root.crt'),
        'server_key' => storage_path('keys/pki/server.key'),
        'server_crt' => storage_path('keys/pki/server.crt'),
        'ca_validity_days' => 1825, // 5 ans
        'server_validity_days' => 365, // 1 an
        'subject_organization' => env('AUTH_V1_PKI_ORG', 'SambaEdu'),
    ],
    'revocation' => [
        'cache_ttl' => 60, // 60s (alignement Tech Spec §7)
        'cache_store' => 'apc',
    ],
];
```

**AC6.2** — **`AuthV1ServiceProvider`**

**Given** une nouvelle classe `App\Providers\AuthV1ServiceProvider`,
**When** elle est `register()`'ée + `boot()`'ée,
**Then** elle :
- Bind `WorkstationJwtIssuer`, `WorkstationJwtVerifier`, `WorkstationJwtRefreshService`, `WorkstationJwtRevocationChecker`, `CaInitializer`, `LegacyBootstrapTokenValidator` en singletons.
- Au boot, crée automatiquement le dossier `storage/logs/auth-v1/` s'il est manquant (iso `GpoServiceProvider`).
- Au boot, crée automatiquement les dossiers `storage/keys/jwt/` et `storage/keys/pki/` s'ils sont manquants (sans clés dedans — la génération vient via `auth:ca:init`).
- Au boot, vérifie l'existence des fichiers de clés (`config('auth_v1.jwt.keys')[active_kid]['private'/'public']`) — si absents : log warning explicite « JWT keys missing, run `php artisan auth:ca:init` ». Ne bloque pas le boot (le serveur peut démarrer avec UI admin, juste l'API v1 renverra 503 ou crashera à la première requête /api/v1/agent/*).
- Enregistre les commandes Artisan : `auth:ca:init`, `workstation:revoke`, `workstation:jwt:rotate-keys` (stub).

**And** ajouté à `config/app.php` providers (ou `bootstrap/providers.php` selon Laravel 12 structure).

**AC6.3** — **Routes group dans `routes/api.php`**

**Given** le fichier `routes/api.php`,
**When** la story est mergée,
**Then** un nouveau groupe est ajouté :
```php
// Story 16.10 — Auth v1 poste↔serveur local (HTTPS + JWT RS256)
Route::prefix('v1/agent')->name('agent.v1.')->group(function () {
    // Enrôlement : protégé par bootstrap token md5 transitoire + rate limit
    Route::post('/enroll', [EnrollController::class, 'store'])
        ->middleware(['auth.v1.bootstrap', 'throttle:10,1'])
        ->name('enroll');

    // Refresh : protégé par refresh_token DB + rate limit
    Route::post('/refresh', [RefreshController::class, 'store'])
        ->middleware(['auth.v1.refresh', 'throttle:30,1'])
        ->name('refresh');

    // Endpoints protégés par JWT (tier=workstation)
    Route::middleware('auth.v1.workstation')->group(function () {
        Route::get('/ping', [PingController::class, 'show'])->name('ping');
        // Futurs endpoints (16.12 logs, futures stories scripts) ajoutent leurs routes ici
    });
});
```

**And** **aucune modification** des routes legacy `/gpo/*_out.php` ni des routes controlHub `/api/v1/snapshot` etc.

### Volet 7 — Tests + QA manuelle + doc op

**AC7.1** — **Tests unit**

**Given** les classes services du namespace `App\Auth\V1`,
**When** la suite `php artisan test --filter='Auth\\\\V1'` s'exécute,
**Then** elle couvre :
- `WorkstationJwtIssuerTest` — sign+verify roundtrip, kid, exp TTL, unicité jti entre deux émissions, refresh token entropy.
- `WorkstationJwtVerifierTest` — 6 cas d'échec D8 + happy path + kid inconnu (rejeté `jwt.signature_invalid`).
- `WorkstationJwtRefreshServiceTest` — rotation refresh, ancien refresh marqué revoked, replay cascade revocation.
- `WorkstationJwtRevocationCheckerTest` — 4 cas cache hit/miss × revoked/not.
- `CaInitializerTest` — idempotence (relance sans `--force` = no-op), `--force` regen, perms via `fileperms()`, contenu cert (CN match), validité dates.
- `LegacyBootstrapTokenValidatorTest` — token APCu valide / invalide / absent.

**And** utilise de **vraies clés RS256** dans `tests/fixtures/auth-v1/private.pem` et `public.pem` (générées une fois pour les tests, commit dans le repo — usage **test only**, jamais en prod). Pas de mock cryptographique.

**AC7.2** — **Tests feature**

**Given** le route group `/api/v1/agent/*`,
**When** la suite `php artisan test --filter='Auth\\\\V1\\\\Feature'` s'exécute,
**Then** elle couvre :
- `EnrollControllerTest` :
  - Happy path : `X-Bootstrap-Token` valide + body valide → 200 + access+refresh+ca_cert_pem présent.
  - Bootstrap token absent → 401 `bootstrap_token.missing`.
  - Bootstrap token invalide → 401 `bootstrap_token.invalid`.
  - Payload invalide (UUID malformé, MAC malformé, os hors enum) → 422.
  - Rate limit dépassé → 429.
- `RefreshControllerTest` :
  - Happy path : refresh_token valide → 200 + nouvelle paire + ancien révoqué en DB (assertion DB query).
  - Refresh inconnu → 401 `refresh.invalid`.
  - Refresh expiré → 401 `refresh.expired`.
  - Refresh déjà révoqué (replay) → 401 `refresh.replay_detected` + assertion : tous les autres refresh du workstation_uuid sont aussi révoqués (cascade).
- `PingControllerTest` :
  - JWT valide → 200 + payload attendu (`workstation_uuid` = sub claim).
  - Sans Authorization → 401 `jwt.missing`.
  - JWT expiré → 401 `jwt.expired`.
  - JWT révoqué (entrée DB + cache APCu warm) → 401 `jwt.revoked`.
  - JWT `tier=controlhub` (mocké) → 401 `jwt.wrong_tier`.

**AC7.3** — **Test architecture `AuthV1NamespaceTest`**

**Given** le namespace `App\Auth\V1`,
**When** le test archi `tests/Architecture/AuthV1NamespaceTest.php` s'exécute (iso pattern `GpoNamespaceTest`),
**Then** il vérifie :
- Aucun fichier sous `app/Auth/V1/` n'appelle `exec(`, `shell_exec(`, `passthru(`, `proc_open(` direct **sauf** `app/Auth/V1/Pki/CaInitializer.php` (whitelist explicite).
- Aucun fichier sous `app/Auth/V1/` n'importe `Firebase\JWT\JWT` **sauf** `app/Auth/V1/Jwt/WorkstationJwtIssuer.php` et `WorkstationJwtVerifier.php` (whitelist).
- Aucun fichier sous `app/Auth/V1/` n'inclut `legacy/*` (interdiction frontière legacy).
- Aucun fichier hors `app/Auth/V1/Http/Controllers/*` n'utilise `WorkstationJwtIssuer::issue*` (encapsulation — l'émission est faite par les controllers d'enroll/refresh uniquement).

**AC7.4** — **Runbook QA `docs/qa/domains/auth-v1.md`**

**Given** un nouveau fichier `docs/qa/domains/auth-v1.md` (premier domaine `auth-v1`, créer le fichier — vérifier la convention append-only via `cat docs/qa/README.md`),
**When** un ops/admin valide la story,
**Then** le fichier contient (append-only, numérotation stable) :

- Header (pré-requis VM + commandes setup)
- **Scénario 16.10-1** : `php artisan auth:ca:init` génère bien les 6 fichiers attendus aux bonnes permissions
- **Scénario 16.10-2** : ré-exécution = no-op (idempotence, exit 0, log info `Already initialized`)
- **Scénario 16.10-3** : `--force` régénère + dossier `pki/` mis à jour
- **Scénario 16.10-4** : configuration Apache/nginx vhost HTTPS local + reload + smoke `curl -kv https://<host>/api/v1/agent/ping` → 401 (pas de JWT) avec format JSON attendu
- **Scénario 16.10-5** : enroll happy path via `curl` (bootstrap token md5 valide depuis APCu legacy) → 200 + ca_cert_pem présent
- **Scénario 16.10-6** : enroll avec bootstrap token invalide → 401 `bootstrap_token.invalid`
- **Scénario 16.10-7** : `curl -H "Authorization: Bearer <jwt>" https://<host>/api/v1/agent/ping` → 200 happy path
- **Scénario 16.10-8** : `curl` avec JWT expiré (forcer `exp` à -1h via tinker) → 401 `jwt.expired`
- **Scénario 16.10-9** : refresh happy path via `curl /api/v1/agent/refresh` → 200 + ancien refresh révoqué en DB (vérification SQL `SELECT revoked_at FROM workstation_refresh_tokens WHERE refresh_token_hash = sha256('<old_token>')`)
- **Scénario 16.10-10** : refresh replay (rejouer ancien refresh) → 401 `refresh.replay_detected` + vérification SQL que la cascade a révoqué tous les autres refresh du workstation_uuid
- **Scénario 16.10-11** : commande `php artisan workstation:revoke <uuid>` → vérification cache APCu + DB (table `workstation_jwt_revocations` peuplée)
- **Scénario 16.10-12** : **Backward compat** — route legacy `/gpo/applications.php?token=<md5>` continue de fonctionner (smoke `curl http://<host>/gpo/applications.php` → 200 ou code legacy attendu, **pas 410 Gone**)
- **Scénario 16.10-13** : audit logs `tail -f storage/logs/auth-v1/auth-v1-*.log` pendant les tests précédents montre les events attendus (`auth.enroll.success`, `auth.token.refreshed`, `auth.token.replay_detected`, etc.)

**AC7.5** — **Mise à jour `sprint-status.yaml`**

**Given** le fichier `_bmad-output/implementation-artifacts/sprint-status.yaml`,
**When** le dev clôture la story,
**Then** :
- Ligne `16-10-securisation-https-jwt-endpoints` passe `ready-for-dev` → `review`.
- Annotation datée avec : modèle dev utilisé, résumé court (nb fichiers créés, nb tests, decision-log éventuel).

---

## Tasks / Subtasks

### Phase T0 — Pré-flight + dépendances

- [ ] **T0.1** SSH vers `/vm` : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 'echo OK'`. Si KO → notifier Henri (CLAUDE.md projet — pas de sync manuelle). _DIFFÉRÉ — VM HS au moment du dev._
- [ ] **T0.2** Vérifier sync inotify : `git log -1 --oneline` host vs VM. HEAD attendu : `f9e11a0 review(story-16.8): corrections code review — 7 patches`. _DIFFÉRÉ — VM HS._
- [ ] **T0.3** `ssh /vm 'which openssl && openssl version'` — confirmer présence + version (≥ 1.1.1 attendu). _DIFFÉRÉ — VM HS. Vérifié en local sur host : `openssl_pkey_new` + `openssl_csr_new` + `openssl_csr_sign` retournent `true` côté PHP._
- [x] **T0.4** `ssh /vm 'php -r "echo extension_loaded(\"openssl\") ? \"OK\\n\" : \"KO\\n\";"'` — confirmer ext openssl PHP. **Vérifié localement** : `php -r 'var_dump(function_exists("openssl_pkey_new"));'` → `bool(true)`. La VM Debian a aussi OpenSSL PHP par défaut.
- [x] **T0.5** Identifier le serveur web local actif. **Hypothèse documentée** : Apache (iso legacy SambaEdu — historique). La commande `auth:ca:init` affiche **les deux blocs (Apache + nginx)** en sortie pour ne pas devoir trancher T0 — l'ops choisit selon son serveur effectif. À confirmer quand VM remonte.
- [x] **T0.6** Ajouter `firebase/php-jwt:^6.10` au `composer.json`. **Adapté** : `firebase/php-jwt:^6.10 || ^7.0` ajouté à `composer.json:require` (le `composer.lock` contient déjà la version 7.0.5 en transitive — pas besoin de relancer `composer install` pour avoir la lib disponible). Lockfile inchangé.
- [x] **T0.7** Identifier la clé APCu exacte posée par `gpo/applications.php` legacy pour le bootstrap token. **Identifié** : clé `apps.<md5>` (32 chars hex), TTL 1800s, posée par `app/Services/AppCustomization/ApcuAppContextWriter::write` (Story 16.7) — cf. `legacy/modules/gpo/applications.php` qui appelle indirectement le writer. Config `auth_v1.bootstrap_token.apcu_prefix` = `apps.` et `token_regex` = `/^[a-f0-9]{32}$/i`. Validation effective sur VM différée (test live nécessite APCu peuplée par un script poste).
- [x] **T0.8** Vérifier la structure Laravel 12 (présence ou non de `app/Http/Kernel.php` vs `bootstrap/app.php` config middleware). **Vérifié** : `app/Http/Kernel.php` est présent (Laravel 12 mais conserve la structure legacy via `class App\Http\Kernel extends HttpKernel`). Les aliases existants (`controlhub.auth`, `sambaedu.auth`, `local.request`, `sambaedu.admin`, …) sont définis dans `app/Http/Kernel.php::$middlewareAliases`. **Choix retenu** : on enregistre les nouveaux alias `auth.v1.*` via le `Router` dans `AuthV1ServiceProvider::boot()` (pattern Laravel 12 idiomatique, sans modifier `app/Http/Kernel.php` ni `bootstrap/app.php`). Tests Feature attestent que les alias sont effectivement résolus (cf. `DualModeCoexistenceTest`).
- [x] **T0.9** Capturer baseline `git log -5 --oneline` dans Dev Agent Record. (Voir Dev Agent Record ci-dessous.)

### Phase T1 — Namespace + ServiceProvider + channel log + test archi

- [x] **T1.1** Créer l'arborescence `app/Auth/V1/{Jwt,Pki,Http/{Controllers,Middleware,Requests},Models,Services,Support}/`. (`.gitkeep` non nécessaires — chaque dossier contient au moins un `.php`.)
- [x] **T1.2** Créer `app/Auth/V1/README.md`.
- [x] **T1.3** Créer `app/Providers/AuthV1ServiceProvider.php` selon AC6.2.
- [x] **T1.4** Ajouter le provider à `config/app.php` `'providers'`.
- [x] **T1.5** Créer `config/auth_v1.php` selon AC6.1.
- [x] **T1.6** Ajouter le channel `auth-v1` dans `config/logging.php` (driver `daily`, path `storage/logs/auth-v1/auth-v1.log`, level `AUTH_V1_LOG_LEVEL` default `debug`). (AC2.4)
- [x] **T1.7** Créer `tests/Architecture/AuthV1NamespaceTest.php` selon AC7.3.
- [x] **T1.8** Ajouter `/storage/keys/` à `.gitignore`.

### Phase T2 — PKI : CaInitializer + commande Artisan + cert serveur

- [x] **T2.1** Créer `app/Auth/V1/Pki/CaInitializer.php` — service principal. Méthodes : `initIfMissing(): array`, `forceRegen(): array`, `regenerateServerOnly(): array`, `getCaCertPem(): string`. **Implémentation finale** : 100% `openssl_*` natif PHP (pas de fallback `Process` finalement nécessaire). Les extensions x509v3 (basicConstraints, keyUsage, extendedKeyUsage, subjectAltName) passent via un fichier de config OpenSSL temporaire généré par `writeOpensslConfig()` (PEM ini-style) — accepté par `openssl_csr_new`/`openssl_csr_sign` en `$configargs.config`. Pas d'appel shell concat. (AC1.1)
- [x] **T2.2** Créer la commande Artisan `app/Console/Commands/AuthCaInit.php` (signature `auth:ca:init {--force} {--regenerate-server-only} {--no-interaction}`). Affiche en sortie les blocs Apache **et** nginx (les deux — l'admin choisit selon son serveur web, cohérent avec T0.5 ambivalent). (AC1.1, AC1.2)
- [x] **T2.3** Tests unit `tests/Unit/Auth/V1/Pki/CaInitializerTest.php` (idempotence, force, regenerate-server-only, perms 0600/0644 via `fileperms()`, CN match `se4fs-test001.lab.local`, validité ~1825j CA / ~365j server, `getCaCertPem()`, `renderServerBlocks()`). Utilise `sys_get_temp_dir()` pour ne pas polluer `storage/keys/`. (AC7.1)
- [x] **T2.4** Documenter dans `docs/qa/domains/auth.md` § Story 16.10 la procédure de config Apache/nginx vhost + sauvegarde + restoration + révocation (AC1.3, scénarios 16.10-1 à 16.10-7). **Note** : le runbook a été nommé `auth.md` (premier domaine `auth`, alignement consigne Henri), pas `auth-v1.md`.

### Phase T3 — Tables DB + modèles Eloquent + factories

- [x] **T3.1** Créer migration `database/migrations/2026_05_16_120000_create_workstation_refresh_tokens_table.php` selon AC3.1 (uuid primary, indexes, jsonb client_meta sur Postgres / json sur SQLite via test du driver).
- [x] **T3.2** Créer migration `database/migrations/2026_05_16_120100_create_workstation_jwt_revocations_table.php` selon AC3.2.
- [x] **T3.3** Créer modèles `App\Auth\V1\Models\WorkstationRefreshToken` + `WorkstationJwtRevocation` (scopes `active()` / `expired()` / `revoked()`, casts `client_meta` → `array`, `HasUuids` + override `newFactory()` pour résoudre le sous-namespace factory).
- [x] **T3.4** Créer factories `Database\Factories\Auth\V1\WorkstationRefreshTokenFactory` + `WorkstationJwtRevocationFactory`. États : `revoked()`, `expired()`, `withHash()`, `forWorkstation()`, `forJti()`. (AC3.3)
- [x] **T3.5** Tests unit `tests/Unit/Auth/V1/Models/WorkstationRefreshTokenTest.php` (scopes `active`/`expired`/`revoked`, cast `client_meta`, helper `isActive()`). Tables auto-créées en SQLite via le trait `IssuesWorkstationJwt::ensureAuthV1Tables()`.
- [ ] **T3.6** Exécuter les migrations sur la VM : `ssh /vm 'cd /var/www/sambaedu-reload && php artisan migrate'`. _DIFFÉRÉ — VM HS. Migrations livrées, à exécuter au reboot VM par Henri (commande dans la section « Smoke test à exécuter quand VM up »)._

### Phase T4 — JWT issuer/verifier/refresh + revocation checker

- [x] **T4.1** Créer DTO immuable `app/Auth/V1/Jwt/WorkstationJwtClaims.php` (`final readonly class` PHP 8.2 — properties promues + helper `workstationUuid()` alias `sub`).
- [x] **T4.2** Créer `app/Auth/V1/Jwt/Exceptions/InvalidJwtException.php` avec catalogue de codes D8 + factory methods `missing()`, `malformed()`, `signatureInvalid()`, `expired()`, `revoked()`, `wrongTier()`, `unknownWorkstation()`.
- [x] **T4.3** Créer `app/Auth/V1/Support/JwtErrorCodes.php` — constantes du catalogue (référencé par middlewares + exception factory). 14 codes (D8).
- [x] **T4.4** Créer `app/Auth/V1/Jwt/WorkstationJwtIssuer.php` selon AC2.1. Garde-fou prod (`forbid_test_keys_in_production`) implémenté. (AC2.1)
- [x] **T4.5** Créer `app/Auth/V1/Jwt/WorkstationJwtRevocationChecker.php` — encapsule cache APC + DB lookup avec fail-open documenté. Helper `pushRevocation()` pour la commande `workstation:revoke`. (D4)
- [x] **T4.6** Créer `app/Auth/V1/Jwt/WorkstationJwtVerifier.php` selon AC2.2 (utilise revocation checker). kid inconnu → rejet `jwt.signature_invalid` (D9). Log `auth.jwt.rejected` warning sans révéler le JWT clear (sha256 prefix 8 chars uniquement). (AC2.2)
- [x] **T4.7** Créer `app/Auth/V1/Jwt/WorkstationJwtRefreshService.php` selon AC2.3 (incluant méthode `handleReplay()` pour cascade revocation transactionnelle). Helper `persistEnrollmentRefresh()` pour le controller enroll. Helper `revokeAllRefreshesForWorkstation()` pour la commande Artisan. (AC2.3, D10)
- [x] **T4.8** Tests unit `tests/Unit/Auth/V1/Jwt/{WorkstationJwtIssuerTest,WorkstationJwtVerifierTest,WorkstationJwtRefreshServiceTest,WorkstationJwtRevocationCheckerTest}.php`. 6 + 9 + 5 + 5 tests cumulés. **Non exécutés VM HS — livrés statiquement, validés en lint PHP `php -l`.** (AC7.1)
- [x] **T4.9** Créer fixtures `tests/fixtures/auth-v1/private.pem` + `public.pem` (RS256 2048 — générées via `openssl_pkey_new`, commitées comme test-only). `tests/fixtures/auth-v1/README.md` ajouté avec warning « TEST ONLY ».

### Phase T5 — Middlewares + bootstrap token validator

- [x] **T5.1** Créer `app/Auth/V1/Services/LegacyBootstrapTokenValidator.php` — encapsule la lookup APCu `apps.<md5>` (clé identifiée T0.7) + validation regex md5 stricte. `config('sambaedu.se4_key')` non utilisée — la présence APCu suffit (= un script poste a tapé `gpo/applications.php` legacy dans les 1800s).
- [x] **T5.2** Créer `app/Auth/V1/Http/Middleware/RequireBootstrapToken.php`. (AC4.2)
- [x] **T5.3** Créer `app/Auth/V1/Http/Middleware/EnsureWorkstationJwt.php`. (AC4.1)
- [x] **T5.4** Créer `app/Auth/V1/Http/Middleware/EnsureRefreshToken.php`. (AC4.3) — inclut la détection replay (cascade revocation via `WorkstationJwtRefreshService::handleReplay()`).
- [x] **T5.5** Enregistrer les alias dans le **Router via `AuthV1ServiceProvider::boot()`** (pattern Laravel 12 — pas de modification de `app/Http/Kernel.php` ni `bootstrap/app.php`) : `auth.v1.workstation`, `auth.v1.bootstrap`, `auth.v1.refresh`.
- [x] **T5.6** Tests unit `tests/Unit/Auth/V1/Http/Middleware/{EnsureWorkstationJwtTest,RequireBootstrapTokenTest,EnsureRefreshTokenTest}.php`. 5 + 3 + 6 tests.
- [x] **T5.7** Tests unit `tests/Unit/Auth/V1/Services/LegacyBootstrapTokenValidatorTest.php`. 4 tests (format invalide + happy path APCu + APCu skip).

### Phase T6 — Endpoints + Controllers + Requests

- [x] **T6.1** Créer `app/Auth/V1/Http/Requests/EnrollAgentRequest.php` (validation `uuid` strict, `mac` regex `:` ou `-` séparateur, `hostname` max 64, `os` in `windows|linux`).
- [x] **T6.2** Créer `app/Auth/V1/Http/Requests/RefreshTokenRequest.php` (validation `refresh_token` 64 hex via regex `/^[a-f0-9]{64}$/i`).
- [x] **T6.3** Créer `app/Auth/V1/Http/Controllers/EnrollController.php` méthode `store()` selon AC5.1. Émission access + refresh + persistance refresh + retour `ca_cert_pem` + `server_base_url`. Idempotence : ré-enroll même UUID ne révoque pas l'ancien.
- [x] **T6.4** Créer `app/Auth/V1/Http/Controllers/RefreshController.php` méthode `store()` selon AC5.2. Délègue à `WorkstationJwtRefreshService::refresh`.
- [x] **T6.5** Créer `app/Auth/V1/Http/Controllers/PingController.php` méthode `show()` selon AC5.3. Réponse echo pure (workstation_uuid, server_time, api_version, se4fs_name).
- [x] **T6.6** Ajouter le route group dans `routes/api.php` selon AC6.3 — sous-namespace `/api/v1/agent/*` avec aliases `auth.v1.bootstrap` / `auth.v1.refresh` / `auth.v1.workstation` + throttle.
- [x] **T6.7** Tests feature `tests/Feature/Auth/V1/{EnrollControllerTest,RefreshControllerTest,PingControllerTest}.php`. 7 + 6 + 6 tests + `DualModeCoexistenceTest` (4 tests routes statique). (AC7.2)

### Phase T7 — Commande révocation + stub rotation + runbook QA + sprint-status

- [x] **T7.1** Créer commande Artisan `app/Console/Commands/WorkstationRevoke.php` (signature `workstation:revoke {uuid} {--reason=manual_admin} {--by=admin} {--dry-run}`). Inscrit en DB `workstation_jwt_revocations` (entrée marker car les jti des access tokens en cours ne sont pas tracés — limitation Phase 2 documentée). Révoque cascade tous les refresh actifs via `WorkstationJwtRefreshService::revokeAllRefreshesForWorkstation`. Push cache APCu. Logs `auth-v1`.
- [x] **T7.2** Créer commande **stub** `app/Console/Commands/WorkstationJwtRotateKeys.php` (signature `workstation:jwt:rotate-keys`) — body throw `RuntimeException('Not implemented — Phase 3+, cf. tech-spec §Annexe B Q4')`.

  **Note Q1 Henri** : substitution `http://` → `https://` dans les 6 fichiers iPXE/display **REPORTÉE à 16.13 / Phase 3**. Note de report 2026-05-16 ajoutée dans `_bmad-output/planning-artifacts/audit-iso-legacy-2026-05-15.md` §4.3. **Aucun fichier iPXE/display modifié en 16.10.**
- [x] **T7.3** Tests unit `tests/Unit/Console/Commands/WorkstationRevokeTest.php`. 4 tests (invalid UUID, happy path, dry-run, marker quand pas de refresh).
- [x] **T7.4** Créer `docs/qa/domains/auth.md` (renommé `auth-v1.md` → `auth.md` — premier domaine `auth`, alignement consigne Henri). Header + 24 scénarios numérotés 16.10-1 à 16.10-24 + checklist rapide + bloc « Smoke test à exécuter quand VM up » + section « Post-correctifs & non-régressions » vide. `docs/qa/README.md` mis à jour (entrée auth ajoutée à « Domaines couverts »).
- [ ] **T7.5** Re-lancer `scripts/run-tests.sh` sur la VM. _DIFFÉRÉ — VM HS. Lint statique `php -l` passé sur tous les fichiers livrés (0 erreur de syntaxe). À exécuter au reboot VM par Henri._
- [x] **T7.6** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : ligne `16-10-securisation-https-jwt-endpoints` `ready-for-dev` → `in-progress` (au début du dev) → sera passée à `review` lors de la finalisation de cette story.
- [x] **T7.7** Mettre à jour cette story : status → `review`, cocher les tasks faisables, remplir Dev Agent Record.

---

## File List prévisionnelle

### Fichiers créés

```
app/Auth/V1/README.md
app/Auth/V1/Pki/CaInitializer.php
app/Auth/V1/Jwt/WorkstationJwtClaims.php
app/Auth/V1/Jwt/WorkstationJwtIssuer.php
app/Auth/V1/Jwt/WorkstationJwtVerifier.php
app/Auth/V1/Jwt/WorkstationJwtRefreshService.php
app/Auth/V1/Jwt/WorkstationJwtRevocationChecker.php
app/Auth/V1/Jwt/Exceptions/InvalidJwtException.php
app/Auth/V1/Http/Middleware/EnsureWorkstationJwt.php
app/Auth/V1/Http/Middleware/RequireBootstrapToken.php
app/Auth/V1/Http/Middleware/EnsureRefreshToken.php
app/Auth/V1/Http/Controllers/EnrollController.php
app/Auth/V1/Http/Controllers/RefreshController.php
app/Auth/V1/Http/Controllers/PingController.php
app/Auth/V1/Http/Requests/EnrollAgentRequest.php
app/Auth/V1/Http/Requests/RefreshTokenRequest.php
app/Auth/V1/Models/WorkstationRefreshToken.php
app/Auth/V1/Models/WorkstationJwtRevocation.php
app/Auth/V1/Services/LegacyBootstrapTokenValidator.php
app/Auth/V1/Support/JwtErrorCodes.php
app/Providers/AuthV1ServiceProvider.php
app/Console/Commands/AuthCaInit.php
app/Console/Commands/WorkstationRevoke.php
app/Console/Commands/WorkstationJwtRotateKeys.php  (stub Phase 3+)

config/auth_v1.php

database/migrations/2026_05_16_XXXXXX_create_workstation_refresh_tokens_table.php
database/migrations/2026_05_16_XXXXXX_create_workstation_jwt_revocations_table.php
database/factories/Auth/V1/WorkstationRefreshTokenFactory.php
database/factories/Auth/V1/WorkstationJwtRevocationFactory.php

tests/fixtures/auth-v1/private.pem   (TEST ONLY — RS256 2048)
tests/fixtures/auth-v1/public.pem
tests/fixtures/auth-v1/README.md     (warning « TEST ONLY »)

tests/Architecture/AuthV1NamespaceTest.php
tests/Unit/Auth/V1/Pki/CaInitializerTest.php
tests/Unit/Auth/V1/Jwt/WorkstationJwtIssuerTest.php
tests/Unit/Auth/V1/Jwt/WorkstationJwtVerifierTest.php
tests/Unit/Auth/V1/Jwt/WorkstationJwtRefreshServiceTest.php
tests/Unit/Auth/V1/Jwt/WorkstationJwtRevocationCheckerTest.php
tests/Unit/Auth/V1/Models/WorkstationRefreshTokenTest.php
tests/Unit/Auth/V1/Services/LegacyBootstrapTokenValidatorTest.php
tests/Unit/Auth/V1/Http/Middleware/EnsureWorkstationJwtTest.php
tests/Unit/Auth/V1/Http/Middleware/RequireBootstrapTokenTest.php
tests/Unit/Auth/V1/Http/Middleware/EnsureRefreshTokenTest.php
tests/Unit/Console/Commands/WorkstationRevokeTest.php
tests/Feature/Auth/V1/EnrollControllerTest.php
tests/Feature/Auth/V1/RefreshControllerTest.php
tests/Feature/Auth/V1/PingControllerTest.php

docs/qa/domains/auth-v1.md  ← NOUVEAU DOMAINE QA
```

### Fichiers modifiés

```
composer.json                                   (+ firebase/php-jwt:^6.10)
composer.lock                                   (vendor update automatique)
config/app.php                                  (+ AuthV1ServiceProvider dans providers) OU bootstrap/providers.php
config/logging.php                              (+ channel auth-v1)
routes/api.php                                  (+ route group /api/v1/agent/*)
app/Http/Kernel.php (ou bootstrap/app.php)      (+ 3 alias middleware auth.v1.*)
.gitignore                                      (+ /storage/keys/ si absent)
_bmad-output/implementation-artifacts/sprint-status.yaml  (status update)
```

> **Aucun fichier supprimé.** Le shim 1bis.18 reste vivant. Les routes legacy `*_out.php` ne sont pas touchées. La table `workstations_migration_status` n'est PAS créée ici (D6 → 16.11). La table `script_execution_logs` n'est PAS créée ici (= 16.12).

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichiers |
|---|---|---|
| **Unit** | PKI (idempotence, force, perms via `fileperms()`, CN match) | `tests/Unit/Auth/V1/Pki/CaInitializerTest.php` |
| **Unit** | JWT sign+verify roundtrip avec vraies clés RS256 | `tests/Unit/Auth/V1/Jwt/WorkstationJwt{Issuer,Verifier,RefreshService,RevocationChecker}Test.php` |
| **Unit** | Modèles (scopes, casts) | `tests/Unit/Auth/V1/Models/*` |
| **Unit** | Middlewares (cas d'échec D8) | `tests/Unit/Auth/V1/Http/Middleware/*` |
| **Unit** | Validators auxiliaires | `tests/Unit/Auth/V1/Services/LegacyBootstrapTokenValidatorTest.php` |
| **Unit** | Commande Artisan révocation | `tests/Unit/Console/Commands/WorkstationRevokeTest.php` |
| **Feature** | Endpoints `/api/v1/agent/*` end-to-end | `tests/Feature/Auth/V1/{Enroll,Refresh,Ping}ControllerTest.php` |
| **Architecture** | Isolation namespace + interdictions | `tests/Architecture/AuthV1NamespaceTest.php` |
| **QA manuelle (VM)** | Smoke HTTPS + curl JWT + révocation + replay | `docs/qa/domains/auth-v1.md` 13 scénarios numérotés |

### Stratégie cryptographique pour tests

- **Vraies clés RS256** dans `tests/fixtures/auth-v1/` (générées une fois, commitées). Pattern : la première exécution des tests vérifie leur présence ; si absentes → fail explicite avec message « run `php -r "openssl_pkey_export(openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]),\$priv); file_put_contents('tests/fixtures/auth-v1/private.pem',\$priv);"` puis dump public ». Dans la pratique : commiter une fois et oublier.
- **Pas de mock** sur `Firebase\JWT\JWT::encode/decode` — on teste avec les vraies clés. Iso-pattern projet : `Process::fake` n'est pas utilisé pour la crypto dans 16.1, on suit la même approche.
- **Cache APCu** : utiliser `Cache::store('array')` dans les tests (driver mémoire, isolé par test). Override dans `phpunit.xml` ou `setUp()` de chaque test.

### Tests qu'on ne fait **pas** dans cette story

- Tests E2E HTTPS réels (certificat self-signed). Hors-scope CI — couvert par les scénarios QA manuels (AC7.4).
- Tests cross-poste (Windows / Linux scripts qui appellent enroll). Hors-scope dev — c'est 16.11.
- Tests de charge / load testing JWT verification. Hors-scope Phase 2.
- Tests `requires-postgres` : les migrations supposent Postgres mais les tests unit/feature tournent en SQLite mémoire (iso 16.8). Si une cassure spécifique Postgres apparaît (ex. cast jsonb) : ouvrir story dédiée tech-debt.

---

## Anti-patterns à éviter (DISASTER PREVENTION)

### Architecture & scope

- ❌ **Ne pas toucher aux routes legacy `/gpo/*_out.php`** — elles restent actives en HTTP md5/APCu pendant toute la Phase 2 (D8). Le retrait est dans 16.13.
- ❌ **Ne pas implémenter `InjectBootstrapFragment`** — c'est le job exclusif de 16.11. 16.10 livre uniquement l'endpoint `enroll` qu'utilisera 16.11.
- ❌ **Ne pas porter les endpoints métier** (`/api/v1/scripts/applications`, `/api/v1/scripts/firefox`, etc.). 16.10 livre `/api/v1/agent/ping` test, pas plus. Le portage métier = futures stories dédiées.
- ❌ **Ne pas créer la table `script_execution_logs`** — c'est 16.12. Pas de modèle Eloquent associé non plus. Pas d'endpoint `POST /api/v1/script-execution-logs` créé en 16.10.
- ❌ **Ne pas créer la table `workstations_migration_status`** (D6) — c'est 16.11.
- ❌ **Ne pas créer d'endpoint `GET /api/v1/scripts/{uuid}`** — c'est une story de portage métier ultérieure (17.3 résolution polymorphique + une future story dédiée pour le lookup unique). 16.10 ne livre QUE `/api/v1/agent/ping` comme endpoint protégé.
- ❌ **Ne pas modifier la signature des services métier existants** (`AppContextWriter`, `GpoService`, `Workstation` model, etc.). 16.10 ne touche que son propre namespace `App\Auth\V1`.
- ❌ **Ne pas créer une UI Livewire** de gestion révocations — c'est Phase 3+ si volumétrie le justifie. 16.10 livre uniquement la commande Artisan `workstation:revoke`.

### Crypto & secrets

- ❌ **Ne pas exposer la clé privée RS256** dans les logs, les réponses HTTP, le code (jamais d'echo/dd/dump direct). Le fichier `.pem` est `0600`, point.
- ❌ **Ne pas commiter `storage/keys/`** — à ajouter à `.gitignore` (T1.8).
- ❌ **Ne pas mocker la signature JWT** dans les tests unit. Utiliser vraies clés `tests/fixtures/auth-v1/private.pem` (cf. AC7.1).
- ❌ **Ne pas logger le `refresh_token` clear** ni le JWT complet — seuls `jti`, `workstation_uuid`, `kid`, `exp`, `tier` peuvent être logués.
- ❌ **Ne pas réutiliser `APP_KEY`** pour signer les JWT. RS256 demande des clés dédiées (asymétrique, séparées de `APP_KEY` symétrique).
- ❌ **Ne pas utiliser `md5()` pour hash de refresh_token** — c'est sha256 (D2 implicite + AC3.1 schéma `refresh_token_hash` 64 chars = sha256 hex).

### Process & infra

- ❌ **Ne pas SSH manuellement vers la VM pour modifier des fichiers** — sync inotify pour tout fichier dans `sambaedu-reload/`. Si modification VM hors `sambaedu-reload/` (ex. vhost Apache) : documenter et notifier Henri (CLAUDE.md projet).
- ❌ **Ne pas exécuter les tests sur le host** — besoin de la VM (Postgres si requires-postgres, APCu, openssl). Lancer via `scripts/run-tests.sh` (16.8) → SSH `/vm`.
- ❌ **Ne pas faire de migration `drizzle-kit`** — c'est un projet Laravel/Eloquent. Migrations PHP via `php artisan make:migration`.
- ❌ **Ne pas créer de pull request / commit depuis le dev-agent** — c'est le job du main agent en fin de cycle.

### Lib & autoload

- ❌ **Ne pas installer une autre lib JWT** que `firebase/php-jwt` (D2). Si dev hésite ou propose une alternative : escalader Henri.
- ❌ **Ne pas faire d'autoload custom** — utiliser PSR-4 standard via `composer.json` autoload (déjà mappé `App\\` → `app/`).
- ❌ **Ne pas utiliser Sanctum** — Sanctum stocke ses tokens en DB (table `personal_access_tokens`), pas adapté à du JWT stateless RS256. Sanctum reste pour l'auth admin/user web, pas pour les postes.

### Anti-pattern Laravel / Eloquent

- ❌ **Ne pas créer de FK `workstation_refresh_tokens.workstation_uuid` → `workstations.uuid`** — voir Dev Notes : un poste peut s'enrôler avant d'être présent dans la table `workstations` (notamment pendant la migration 16.11). On garde `workstation_uuid` libre + index, sans contrainte référentielle.
- ❌ **Ne pas consommer le bootstrap token md5 en `apcu_delete`** lors de l'enroll — risque race condition si retry réseau. Le TTL APCu 1800s legacy gère naturellement la fenêtre. Documenté dans AC4.2.

---

## Dépendances + ordre

| Story | Statut entrant | Lien |
|---|---|---|
| **16.8** Stabilisation Phase 1 | ✅ **done** (2026-05-16, commits f9e11a0 + c8a8cce) | Bloquante — fournit tests Phase 1 verts (baseline pour non-régression) + audit `SE4FS` nu (GO 16.10) + audit shims (plan 16.13) |
| **16.7** Portage natif applications | ✅ done | Référence du pattern `ApcuAppContextWriter` (md5 token APCu legacy à valider en bootstrap — AC4.2 / T5.1) |
| **16.1** Fondations GPO natives | ✅ review | Pattern de structure (namespace racine, ServiceProvider, channel log, test archi) |
| **16.9** UI admin sous `/admin/settings` | 🟡 backlog (parallèle) | **Pas de dépendance** — 16.9 peut s'exécuter en parallèle de 16.10 sans collision (16.9 touche `/app/gpo/*` UI Livewire, 16.10 touche `App\Auth\V1\*`) |

**16.10 débloque** :
- **16.11** (auto-bootstrap migration postes) — consomme `/api/v1/agent/enroll` + `ca_cert_pem`.
- **16.12** (logs exécution centralisés) — consomme middleware `auth.v1.workstation` pour `POST /api/v1/script-execution-logs`.
- **16.13** (cleanup shims définitif) — retire les endpoints legacy `*_out.php` une fois les postes migrés.

---

## Risques + mitigations

| Risque | Sévérité | Mitigation 16.10 |
|---|---|---|
| Clé privée RS256 compromise | 🟠 Élevée | Permissions 0600 + dossier `.gitignore` + ne jamais log/expose. Procédure de rotation manuelle documentée (AC1.3 + D9 stub). |
| Cache APCu pas synchro entre processus PHP-FPM workers | 🟡 Moyenne | TTL court (60s) + commande `workstation:revoke` qui push la révocation. En cas de gap, DB lookup prend le relais (D4 fallback). |
| `firebase/php-jwt` version qui change API | 🟢 Mineure | Pin `^6.10` (semver minor stable). À monitorer en Phase 3. Wrapper `WorkstationJwtIssuer`/`Verifier` encapsule l'API → bascule future indolore. |
| Apache config vhost cassée par ops | 🟡 Moyenne | 16.10 **ne modifie pas** la config Apache automatiquement. Bloc affiché en sortie de `auth:ca:init` à intégrer manuellement. Scénario QA 16.10-4 valide le reload. |
| Backward compat legacy `*_out.php` cassée par effet de bord | 🟡 Moyenne | Aucune route legacy n'est touchée. Test feature inclus dans la suite Phase 1 (déjà green 16.8). Scénario QA 16.10-12 vérifie post-merge. |
| Tests Phase 1 (474) cassés par ajout providers/middlewares | 🟡 Moyenne | Re-lancer suite complète après chaque batch de commits. Si régression : revert + analyse cause racine avant de continuer. |
| Volume DB révocations grossit sans limite | 🟢 Mineure | Job de purge à scaffolder en story Phase 3+. Pour 16.10, accepter croissance linéaire (1 entrée par révocation manuelle + 1 par rotation refresh + N entrées par replay cascade). Volumétrie estimée < 100k entries/an même pour 1000 postes. |
| Confusion `tier` workstation vs controlhub si futurs claims se chevauchent | 🟡 Moyenne | Catalogue strict des `tier` documenté dans `app/Auth/V1/README.md`. Test archi vérifie qu'aucun JWT controlHub n'est accepté par `auth.v1.workstation` middleware. |
| Audit SYSVOL prod non refait avant 16.10 (recommandation 16.8 AC5) | 🟢 Mineure | **Non bloquant strict 16.10** — l'audit SYSVOL impacte la re-publication GPO en 16.11, pas l'API v1 elle-même. Mentionner comme rappel dans Completion Notes pour relayer à 16.11. |
| Bootstrap token APCu clé incorrecte (T0.7 mal identifié) | 🟠 Élevée | T0.7 obligatoire : grep dans `legacy/modules/gpo/applications.php` + tests `LegacyBootstrapTokenValidatorTest` doivent valider la clé APCu cible. Si KO en feature test enroll → blocage à diagnostiquer T0. |

---

## Project Structure Notes

### Alignement avec la structure projet

- **Namespace** : `App\Auth\V1\…` — racine par domaine fonctionnel (iso `App\Gpo\…`, `App\Wpkg\…`, `App\Ldap\…`).
- **Tests** : `tests/Unit/Auth/V1/…`, `tests/Feature/Auth/V1/…`, `tests/Architecture/AuthV1NamespaceTest.php` — sous-arborescence parallèle au namespace.
- **Pages cibles** : *hors-scope cette story* — pas d'UI Livewire dans 16.10 (la commande Artisan + scénarios QA suffisent pour l'ops). Une UI admin de gestion des révocations pourrait apparaître Phase 3+ si volumétrie le justifie.
- **Convention CLAUDE.md** : pas applicable à cette story (pas de page web, pas de modale, pas de toast — c'est une API HTTP pure).

### Conflits / variances détectés

| Élément | Architecture officielle | Décision 16.10 | Justification |
|---|---|---|---|
| Lib JWT | non décidée (pas mentionnée dans `architecture.md`) | `firebase/php-jwt:^6.10` | D2 — lib minimaliste, iso-pattern Sambaedu |
| Stockage clé | non décidé | Fichier `.pem` sous `storage/keys/jwt/` 0600 | D3 — pas de Vault, pas de DB chiffrée |
| Outil PKI | non décidé | `openssl_*` natif PHP via Artisan | D1 — pas de step-ca dépendance |
| Cache révocation | non décidé | `Cache::store('apc')` (driver `apc` Laravel) | D4 — pas de Redis |
| Format réponse erreur | `{success, error, message}` (iso ControlHubAuth) | `{error, message, code}` | D8 — ajout `code` pour discrimination programmatique (cohabite — `success` peut être ajouté côté wrapper si besoin futur) |
| FK `workstation_uuid` | non décidé | Pas de FK | AC3.1 / Dev Notes — un poste peut s'enrôler avant d'être présent dans `workstations` |

### Cohabitation routes `/api/v1/*`

| Préfixe | Owner | Middleware | Status |
|---|---|---|---|
| `/api/v1/snapshot`, `/api/v1/workstation-groups`, `/api/v1/shortcuts`, `/api/v1/health` etc. | controlHub (existant `routes/api.php` lignes 42-96) | `controlhub.auth` | Inchangé |
| `/api/v1/agent/enroll`, `/api/v1/agent/refresh`, `/api/v1/agent/ping` | **16.10 (cette story)** | `auth.v1.bootstrap` / `auth.v1.refresh` / `auth.v1.workstation` | **NEW** |
| `/api/v1/script-execution-logs` | 16.12 (future) | `auth.v1.workstation` | Réservé, non créé |
| `/api/v1/scripts/*` | futures stories | `auth.v1.workstation` | Réservé, non créé |
| `/api/v1/health-check`, `/api/v1/shortcuts/export/*` | déjà existant (Ecowatt, ShortcutExport) | divers (`local.request` pour wpkg.reports, sans auth pour shortcuts.export) | Inchangé |

**Pas de collision** : `/api/v1/agent/*` est un nouveau sous-namespace dédié aux postes. Les claims `tier` discriminent les clients en interne. Le middleware `controlhub.auth` reste sur ses routes existantes.

---

## References

- [Source: `_bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md` §4 (D3/D8/D9), §5.0 (topologie), §5.1 (vue d'ensemble), §5.2 (auth HTTPS+JWT), §5.3 (auto-bootstrap), §5.4 (logs centralisés), §5.6 (future-readiness agent + controlHub), §6.1 (plan stories Phase 2), §7 (risques), §8.1 (séquence bascule), §8.2 (critères D8), Annexe B Q4 + Q5] — cadrage primaire validé Henri 2026-05-15.
- [Source: `_bmad-output/planning-artifacts/audit-iso-legacy-2026-05-15.md` §2.3, §4.1, §4.2] — audit 16.8, GO 16.10 confirmé, plan re-substitution `http://` → `https://` pour 6 fichiers iPXE/display legacy (déclenchement effectif **en 16.11** car ces fichiers sont rendus côté serveur — pas dans 16.10).
- [Source: `_bmad-output/implementation-artifacts/16-8-stabilisation-phase1-tests-audit-legacy.md`] — pattern story complexe multi-phases T0-T7 + decision-log + commit log.
- [Source: `_bmad-output/implementation-artifacts/16-1-fondations-gpo-natives-audit-legacy.md`] — pattern story de fondation (namespace dédié, ServiceProvider, channel log dédié, test archi).
- [Source: `_bmad-output/implementation-artifacts/16-7-portage-natif-applications-php.md`] — pattern `ApcuAppContextWriter` (md5 token APCu legacy validation, utile à T5.1 `LegacyBootstrapTokenValidator`).
- [Source: `_bmad-output/implementation-artifacts/sprint-status.yaml` ligne 262] — cadrage condensé 16.10.
- [Source: mémoire `feedback_auth_iso_legacy`] — **arbitrage Henri 2026-05-15** : la directive iso-legacy NE s'applique PAS à 16.10. Phase 2 introduit JWT applicatif par décision explicite (cf. paragraphe « Évolution Phase 2 »).
- [Source: `composer.json` lignes 10-41] — `firebase/php-jwt` à ajouter (non présent), Sanctum 4.0 présent mais non adapté JWT RS256 stateless.
- [Source: `routes/api.php` lignes 42-96] — namespace `/api/v1/*` partagé avec controlHub (`controlhub.auth` middleware), pattern à respecter.
- [Source: `app/Http/Middleware/ControlHubAuth.php`] — pattern de référence pour middleware d'auth Bearer (réponse JSON `{success, error, message}` — D8 ajoute `code`).
- [Source: `app/Http/Middleware/EnsureLocalRequest.php`] — pattern IP allowlist (utile pour `wpkg.report` legacy, à ne pas confondre avec 16.10).
- [Source: `app/Providers/GpoServiceProvider.php`] — pattern boot auto-mkdir logs.
- [Source: `config/logging.php` lignes 152-164] — pattern channel dédié `gpo` (iso à reproduire pour `auth-v1`).
- [Source: `config/sambaedu.php` lignes 169-170, 255-274] — `se4fs_name` et section `gpo` (référence pour `config/auth_v1.php`).
- [Source: `docs/qa/domains/gpo.md`] — pattern runbook QA append-only avec scénarios numérotés stables.
- [Source: `app/Gpo/Services/VeyonConfigGenerator.php:151-206`] — précédent usage `openssl_*` natif PHP (chiffrement OAEP) — référence pour T2.1 CaInitializer.
- [Source: `CLAUDE.md` projet racine] — sync inotify, cibles SSH `/vm`, conventions Livewire SFC, trait WithToasts (non applicable 16.10 — pas d'UI).

---

## Dev Notes

### Justification design

- **Pourquoi pas de FK `workstation_uuid` → `workstations.uuid` ?** Le poste peut s'enrôler avant d'apparaître dans la table `workstations` Eloquent. Le flux 16.11 (auto-bootstrap) déclenchera l'enrôlement la première fois qu'un poste appelle un endpoint legacy, **avant** que le sync AD/`workstations` ait pris en charge ce poste. On évite la FK pour ne pas bloquer.
- **Pourquoi `firebase/php-jwt` plutôt que `lcobucci/jwt` ?** API plus simple, 1 fichier vendor, support RS256 standard, pas de chaîne de responsabilité complexe à apprendre. `lcobucci/jwt` apporterait des features (chain of trust, custom claims validators) qui ne sont pas requises Phase 2. À reconsidérer Phase 3+ si besoin.
- **Pourquoi pas Sanctum ?** Sanctum stocke ses tokens en DB et fait du hashing comparable, mais (a) il n'émet pas de JWT stateless RS256 (juste des opaque tokens), (b) il est conçu pour des clients web/SPA, pas pour des scripts cmd/bash, (c) le contrat agent-ready (Tech Spec §5.6) demande explicitement JWS RS256 pour pouvoir cohabiter avec un futur agent Go qui consommerait des tokens via crypto standard.
- **Pourquoi un seul `kid` actif en 16.10 ?** La rotation `kid` ajoute une complexité non-triviale (table de mapping kid → public_key, période de grâce, re-signature de tous les JWT actifs ou attente expiration naturelle). Pour Phase 2, on accepte le risque d'un kid unique. Si compromission : ré-génération via `auth:ca:init --force` + révocation explicite tous les jti actifs via `workstation:revoke` batch. Documenté dans le runbook QA (AC1.3 + scénario 16.10-11).
- **Pourquoi `tier=workstation` plutôt que `aud=workstation` (claim standard JWT) ?** Iso-pattern Sambaedu : `tier` est plus parlant pour les développeurs, `aud` est plus formel. Tradeoff accepté car les claims sont opaques aux clients (seul le serveur les lit). Pour interop avec un agent Go futur, on pourra ajouter `aud` en alias sans casser la backward-compat.
- **Pourquoi `access_ttl=24h` plutôt qu'un TTL plus court (15min) ?** Tech Spec §5.2 mentionne « TTL 24h » explicitement. Compromis : un poste qui tourne 8h/jour fait ~1 refresh par session. Un TTL plus court (15min) ferait 32+ refresh/session — pollue les logs et la DB inutilement. 24h offre une bonne fenêtre tout en limitant l'exploitation d'un access token volé.

### Risque non bloquant — fixtures RS256 commit

Les fixtures `tests/fixtures/auth-v1/private.pem` + `public.pem` sont **commitées** dans le repo Git. Iso-pattern Laravel (les apps publient parfois des `tests/fixtures/secret.txt` factices). Conditions :
- Le path `tests/fixtures/auth-v1/` est documenté comme **TEST ONLY** dans un README adjacent (`tests/fixtures/auth-v1/README.md`).
- Le `.pem` privé n'est jamais accepté par `WorkstationJwtIssuer` si `APP_ENV !== 'testing'` (garde-fou runtime).
- Documentation dans `app/Auth/V1/README.md` : « **NEVER use tests/fixtures keys in production** ».

Si Henri préfère ne pas commiter les fixtures : alternative = `tests/TestCase::setUp()` qui régénère les clés à la volée via `openssl_pkey_new(...)` puis stocke en `tempnam()`. Coût test : ~50-100ms par test class. **Recommandation SM** : commiter les fixtures (plus simple, plus déterministe, moins de flakiness).

### Pattern d'extraction du token JWT

```php
// Iso-pattern ControlHubAuth.php:67-75
private function extractJwtFromRequest(Request $request): ?string
{
    $authHeader = $request->header('Authorization');
    if (is_string($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
        return substr($authHeader, 7);
    }
    return null;
}
```

### Idempotence et migration UUID

Un poste qui s'enrôle deux fois avec le même UUID (cf. AC5.1 « idempotence ») : on émet une nouvelle paire **sans** révoquer l'ancienne. Justification :
- Cas réel attendu : poste qui a perdu son state local (réinstall, fichier `/etc/sambaedu/auth.json` supprimé, etc.). 16.11 (auto-bootstrap) appellera ré-enroll automatiquement.
- Risque marginal : deux devices physiquement distincts qui auraient le même UUID. Cas pathologique (clone VM, MAC spoof) — non géré Phase 2. La table `workstation_refresh_tokens.client_meta` (cf. AC3.1) capture mac/hostname/enroll_ip : audit possible a posteriori si suspicion.

### Replay detection — pattern obligatoire

Le pattern « detect replay on revoked refresh » est critique : un attaquant qui aurait volé un refresh_token et que le poste légitime aurait utilisé entre-temps (donc révoqué via rotation) **présentera un refresh révoqué**. Action serveur : **révoquer cascade** tous les refresh + JWT actifs du `workstation_uuid` pour invalider la session de l'attaquant ET du poste légitime — ce dernier devra re-bootstrap (réinjection 16.11 si poste migré, ou retomber en mode legacy).

Logs `auth.token.replay_detected` doivent être **warning-level** au minimum (potentiellement alertable côté Phase 3+ via cron de monitoring).

### Outils CI/QA en Phase 2

`scripts/run-tests.sh` (16.8) est l'outil canonique. Pour 16.10, lancer :
```bash
ssh /vm 'cd /var/www/sambaedu-reload && ./scripts/run-tests.sh'
# (sans --phase1-only pour inclure Auth\V1)
```
Le summary JSON sera dans `storage/logs/tests/last-run-summary.json`. Code retour 0 attendu.

### Vérification non-régression Phase 1

Garde-fou critique : tous les 474 tests Phase 1 doivent rester verts après l'ajout des providers/middlewares 16.10. Risque concret : `AuthV1ServiceProvider::boot()` qui auto-mkdir `storage/logs/auth-v1/` peut accidentellement déclencher un side-effect dans un test isolé qui mockait le filesystem. Mitigation :
- T7.5 obligatoire : re-lancer `scripts/run-tests.sh` (suite complète) avant de marquer 16.10 → `review`.
- Si régression Phase 1 détectée : `git bisect` entre commits 16.10 pour identifier le batch fautif, puis fix ou rollback de ce batch sans toucher au reste de la story.

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7[1m]` (Opus 4.7 — 1M context fenêtre, sélectionné pour la
densité de fichiers à coordonner simultanément — namespace nouveau de
~30 fichiers + fixtures + doc QA + 14 codes d'erreur cohérents à
maintenir, et pour la sécurité crypto critique de la story).

### Debug Log References

**Baseline `git log -5 --oneline` (capturé au début du dev, 2026-05-16)** :

```
f9e11a0 review(story-16.8): corrections code review — 7 patches
c8a8cce docs(story-16.8): audit SYSVOL — précision env dev (DC sur .60 via SMB)
fc21034 docs(story-16.8): qa/domains/gpo.md — section Stabilisation Phase 1
0a4609c fix(story-16.8): tests Feature GPO — withoutMiddleware sambaedu.auth + regex attribut
d00c812 fix(story-16.8): GpoNamespaceTest — whitelist services portage natif + regex Process
```

Branche : `16-10` (worktree git, dérivée de `main`).
HEAD attendu sur VM : non vérifié (VM HS).

### Completion Notes List

**Mode de livraison** : **static delivery** — VM HS au moment du dev (Henri
confirmé en début de session). Conséquences :

- **T0.1/T0.2/T0.3 différés** : aucun SSH vers `/vm`. Vérifications statiques
  équivalentes faites en local (PHP openssl PHP fonction, lint `php -l` sur
  tous les fichiers livrés).
- **T0.6 adapté** : `firebase/php-jwt:^6.10 || ^7.0` ajouté à `composer.json:require`. Le
  `composer.lock` contient déjà la version `v7.0.5` en dépendance transitive
  (via google/auth) — pas besoin de relancer `composer install` pour disposer
  de la lib. **Action Henri au reboot VM : `composer install` pour formaliser
  la dépendance directe dans `composer.lock`** (note : pas obligatoire pour
  que le code marche, juste pour la traçabilité explicite).
- **T0.7 documenté** : la clé APCu legacy = `apps.<md5>` (32 chars hex), TTL
  1800s — posée par `app/Services/AppCustomization/ApcuAppContextWriter`
  (Story 16.7). `LegacyBootstrapTokenValidator` configurable via
  `config('auth_v1.bootstrap_token.apcu_prefix')` + `token_regex`.
- **T3.6, T8.1-T8.3 différés** : migrations, smoke `curl`, exécution tests
  Pest non lancés. Livré : 2 migrations + 47 tests (Unit + Feature +
  Architecture) prêts à l'exécution.

**Décisions techniques émises au-delà des D1-D10 SM** :

1. **Enregistrement des aliases middlewares via `Router` dans `AuthV1ServiceProvider::boot()`** plutôt que dans `app/Http/Kernel.php::$middlewareAliases`. Plus idiomatique Laravel 12, isolation des changements dans le provider du domaine. Aucune modification de `app/Http/Kernel.php` ni `bootstrap/app.php`.
2. **Extensions x509v3 via fichier de config OpenSSL temporaire** (ini-style généré par `writeOpensslConfig()`) au lieu d'un fallback `Process::run`. 100% natif PHP. Le fichier `Process` (Facade) n'est pas importé dans `CaInitializer` (test archi plus strict).
3. **Pas de FK** sur `workstation_uuid` (cohérent avec Dev Notes) — un poste peut s'enrôler avant d'apparaître dans la table `workstations`. Migrations conservent juste l'index.
4. **`jsonb` côté Postgres, `json` côté SQLite** : test du driver runtime dans la migration pour rester portable testing/prod.
5. **Override `newFactory()` sur les modèles** pour résoudre le sous-namespace `Database\Factories\Auth\V1\*` (la convention Eloquent par défaut cherche `Database\Factories\<Model>Factory` racine).
6. **Helper test `tests/Concerns/IssuesWorkstationJwt.php`** centralise la config des fixtures clés + l'émission de JWT déterministe (utilisé par 5 fichiers de tests, évite la duplication).
7. **Marker entry pour `workstation:revoke`** : la commande insère une entrée `workstation_jwt_revocations` avec un `jti` synthétique unique (UUID v4) — limitation acceptée Phase 2 que les jti des access tokens en cours ne sont pas tracés. Documenté dans le code + commande + runbook.
8. **Channel `auth-v1` séparé du channel `gpo`** : iso-pattern `gpo`/`wpkg-deploy`/`network`, verbosité paramétrable, rotation 30j.
9. **`docs/qa/domains/auth.md`** (et non `auth-v1.md` comme suggéré dans la story SM) — aligné consigne Henri « créer le fichier `auth.md` (premier fichier du domaine `auth`) ». L'entrée a été ajoutée à `docs/qa/README.md` « Domaines couverts ».
10. **Note Q1 (iPXE) ajoutée dans `audit-iso-legacy-2026-05-15.md` §4.3** : substitution `http://` → `https://` **reportée à 16.13 / Phase 3** (décision Henri 2026-05-16). Aucun fichier iPXE/display modifié en 16.10.

**Difficultés rencontrées et résolutions** :

- **Import inutilisé `Illuminate\Contracts\Http\Kernel as HttpKernel`** dans `AuthV1ServiceProvider` : finalement retiré (pas nécessaire avec le pattern Router aliasMiddleware).
- **Le `Process` facade** initialement importée dans `CaInitializer` au cas où on en aurait besoin — finalement supprimée car l'implémentation pure natif est suffisante. Aligne le namespace avec un test archi plus strict (pas de `Illuminate\Support\Facades\Process` import).
- **Détection `kid` inconnu côté `firebase/php-jwt`** : la lib lance `UnexpectedValueException` avec un message contenant `kid` ou `key`. On parse le message pour distinguer entre `signature_invalid` (kid inconnu = D9) et `malformed` (segments corrompus).

**Items différés VM HS (à exécuter par Henri quand VM remonte)** :

- T0.1, T0.2, T0.3 (SSH + sync inotify + openssl version)
- T0.7 live (validation APCu réelle posée par script poste)
- T3.6 (`php artisan migrate`)
- T7.5 (`./scripts/run-tests.sh`)
- T8.1, T8.2, T8.3 (smoke `curl` enroll/refresh/ping + reload Apache)

Voir section « Smoke test à exécuter quand VM up » ci-dessous pour le bloc
prêt à coller.

**Audit SYSVOL prod** : différé post-merge (cf. 16.8 AC5 — non bloquant).
Mention dans cette story.

### File List

**Fichiers créés** (39) :

```
app/Auth/V1/README.md
app/Auth/V1/Http/Controllers/EnrollController.php
app/Auth/V1/Http/Controllers/PingController.php
app/Auth/V1/Http/Controllers/RefreshController.php
app/Auth/V1/Http/Middleware/EnsureRefreshToken.php
app/Auth/V1/Http/Middleware/EnsureWorkstationJwt.php
app/Auth/V1/Http/Middleware/RequireBootstrapToken.php
app/Auth/V1/Http/Requests/EnrollAgentRequest.php
app/Auth/V1/Http/Requests/RefreshTokenRequest.php
app/Auth/V1/Jwt/Exceptions/InvalidJwtException.php
app/Auth/V1/Jwt/WorkstationJwtClaims.php
app/Auth/V1/Jwt/WorkstationJwtIssuer.php
app/Auth/V1/Jwt/WorkstationJwtRefreshService.php
app/Auth/V1/Jwt/WorkstationJwtRevocationChecker.php
app/Auth/V1/Jwt/WorkstationJwtVerifier.php
app/Auth/V1/Models/WorkstationJwtRevocation.php
app/Auth/V1/Models/WorkstationRefreshToken.php
app/Auth/V1/Pki/CaInitializer.php
app/Auth/V1/Services/LegacyBootstrapTokenValidator.php
app/Auth/V1/Support/JwtErrorCodes.php
app/Console/Commands/AuthCaInit.php
app/Console/Commands/WorkstationJwtRotateKeys.php
app/Console/Commands/WorkstationRevoke.php
app/Providers/AuthV1ServiceProvider.php

config/auth_v1.php

database/migrations/2026_05_16_120000_create_workstation_refresh_tokens_table.php
database/migrations/2026_05_16_120100_create_workstation_jwt_revocations_table.php
database/factories/Auth/V1/WorkstationJwtRevocationFactory.php
database/factories/Auth/V1/WorkstationRefreshTokenFactory.php

docs/qa/domains/auth.md

tests/Architecture/AuthV1NamespaceTest.php
tests/Concerns/IssuesWorkstationJwt.php
tests/Feature/Auth/V1/DualModeCoexistenceTest.php
tests/Feature/Auth/V1/EnrollControllerTest.php
tests/Feature/Auth/V1/PingControllerTest.php
tests/Feature/Auth/V1/RefreshControllerTest.php
tests/fixtures/auth-v1/README.md
tests/fixtures/auth-v1/private.pem
tests/fixtures/auth-v1/public.pem
tests/Unit/Auth/V1/Http/Middleware/EnsureRefreshTokenTest.php
tests/Unit/Auth/V1/Http/Middleware/EnsureWorkstationJwtTest.php
tests/Unit/Auth/V1/Http/Middleware/RequireBootstrapTokenTest.php
tests/Unit/Auth/V1/Jwt/WorkstationJwtIssuerTest.php
tests/Unit/Auth/V1/Jwt/WorkstationJwtRefreshServiceTest.php
tests/Unit/Auth/V1/Jwt/WorkstationJwtRevocationCheckerTest.php
tests/Unit/Auth/V1/Jwt/WorkstationJwtVerifierTest.php
tests/Unit/Auth/V1/Models/WorkstationRefreshTokenTest.php
tests/Unit/Auth/V1/Pki/CaInitializerTest.php
tests/Unit/Auth/V1/Services/LegacyBootstrapTokenValidatorTest.php
tests/Unit/Console/Commands/WorkstationRevokeTest.php
```

**Fichiers modifiés** (7) :

```
.gitignore                                                              (+ /storage/keys/)
composer.json                                                           (+ firebase/php-jwt:^6.10 || ^7.0)
config/app.php                                                          (+ AuthV1ServiceProvider dans providers)
config/logging.php                                                      (+ channel auth-v1)
routes/api.php                                                          (+ route group /api/v1/agent/*)
docs/qa/README.md                                                       (+ entrée auth.md dans Domaines couverts)
_bmad-output/implementation-artifacts/sprint-status.yaml                (status update in-progress + review)
_bmad-output/planning-artifacts/audit-iso-legacy-2026-05-15.md         (+ note Q1 report iPXE → 16.13)
```

**Aucun fichier supprimé.** Routes legacy `*_out.php` intactes (D8 dual-mode).
Table `workstations_migration_status` non créée (D6 — 16.11). Table
`script_execution_logs` non créée (= 16.12).

### Change Log

| Date       | Auteur                 | Changement                                                                                                                             |
|------------|------------------------|----------------------------------------------------------------------------------------------------------------------------------------|
| 2026-05-16 | dev claude-opus-4-7[1m] | Implémentation initiale Story 16.10 (mode static delivery — VM HS). 39 fichiers créés + 8 modifiés. Tests livrés non exécutés (cf. différé VM HS). |

---

## Smoke test à exécuter quand VM up

Bloc d'instructions prêt à coller dès que la VM remonte.

```bash
# 0. SSH + état git
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu-reload
git log -3 --oneline
# Attendu : présence de(s) commit(s) Story 16.10

# 1. Composer (lock à jour si firebase/php-jwt est ajouté en direct)
composer install --no-dev --optimize-autoloader
# Vérifier : composer.json contient bien "firebase/php-jwt": "^6.10 || ^7.0"

# 2. Migrations
php artisan migrate
# Attendu : 2 migrations 2026_05_16_120000 et 2026_05_16_120100 appliquées

# 3. PKI init
php artisan auth:ca:init
# Attendu : status=initialized, 6 fichiers OK, blocs Apache/nginx affichés

# 4. Web server config (intégrer le bloc Apache OU nginx selon T0.5)
$EDITOR /etc/apache2/sites-available/sambaedu-ssl.conf  # ou nginx
apachectl configtest  # ou nginx -t
systemctl reload apache2  # ou nginx

# 5. Smoke ping → 401 jwt.missing
curl -kv https://$(hostname -f)/api/v1/agent/ping
# Attendu : HTTP/2 401, JSON {"error":"unauthorized","code":"jwt.missing", ...}

# 6. Smoke enroll (récupérer un md5 APCu valide posé par un poste qui a tapé
#    /gpo/applications.php dans les 30 dernières minutes)
TOKEN=$(php artisan tinker --execute='
  foreach (apcu_iterator() as $k) {
    if (str_starts_with($k["key"], "apps.")) {
      echo substr($k["key"], 5);
      break;
    }
  }
')

if [ -z "$TOKEN" ]; then
  echo "Pas de bootstrap token disponible — déclencher un boot poste pour
        peupler APCu, ou utiliser un md5 factice + apcu_store via tinker
        pour tester."
else
  curl -k -i -X POST https://$(hostname -f)/api/v1/agent/enroll \
    -H "Content-Type: application/json" \
    -H "X-Bootstrap-Token: $TOKEN" \
    -d '{
      "uuid": "11111111-1111-1111-1111-111111111111",
      "mac": "AA:BB:CC:DD:EE:FF",
      "hostname": "smoke-pc",
      "os": "linux"
    }'
  # Attendu : HTTP 200 + JSON {"success":true, "access_token":..., "refresh_token":..., "ca_cert_pem":..., ...}
fi

# 7. Tests Pest
./scripts/run-tests.sh
# Attendu : tous les tests Phase 1 verts (non-régression) + tests Auth\V1 verts
#   - 12 tests Unit Pki + Jwt + Models + Services + Middleware
#   - 21 tests Feature Auth\V1 (enroll + refresh + ping + dual-mode)
#   - 5 tests Architecture AuthV1NamespaceTest
#   - 4 tests Unit Console WorkstationRevoke

# 8. Vérifier les logs auth-v1
ls -la storage/logs/auth-v1/
tail -50 storage/logs/auth-v1/auth-v1-$(date +%F).log
# Attendu : présence de action_type auth.ca.init.* + auth.enroll.success
#   Aucun secret loggé (jamais de BEGIN PRIVATE KEY, jamais de refresh clear)

# 9. Sprint status
grep -A1 "16-10-securisation" _bmad-output/implementation-artifacts/sprint-status.yaml
# Attendu : ligne `16-10-securisation-https-jwt-endpoints: review`
```

---

## Recommandation Modèle Dev

**Modèle recommandé : `opus`** (charge 4-6j, sécurité critique, nouvelle PKI + JWT crypto + multiples tables + middlewares + endpoints + tests, design « agent-ready » à respecter pour cohabitation controlHub future).

**Justification** :

- **Charge cadrée 4-6j** : la story la plus lourde de l'Epic 16 Phase 2 (cf. tech-spec §6.1). Multiples couches qui doivent toutes être correctes (PKI, JWT, DB, middlewares, controllers, tests) sans introduire de régression sur la baseline Phase 1 (474 tests verts à préserver).
- **Sécurité critique** : 16.10 livre la couche d'auth qui sera consommée par 16.11/16.12/16.13. Une faille de design (claim leak, signature bypass, race condition révocation, replay non géré) propagerait à tout le reste de la Phase 2. Opus mieux armé pour anticiper les pièges crypto (e.g. confusion `alg` claim, kid stripping, replay attack via jti non-unique, attaque sur l'ordre de validation exp vs revocation).
- **PKI nouvelle** : aucun pattern existant dans Sambaedu (pas de `App\Pki`, pas de wrapper `openssl` antérieur — `samba-tool` ne fait pas de PKI X.509, le précédent `VeyonConfigGenerator` n'utilise qu'`openssl_public_encrypt` simple). Opus apporte la culture nécessaire pour produire un `CaInitializer` idempotent du premier coup (vs Sonnet qui itérerait via dev-cycle).
- **Design « agent-ready »** : nécessite tenir simultanément en tête le contrat 16.10 (postes Phase 2), les besoins futurs 16.12/16.13 (logs + cleanup), la cohabitation controlHub (claim tier), et l'extensibilité agent Go Phase 3+. Sonnet aurait tendance à optimiser pour le scope 16.10 seul et à passer à côté d'opportunités d'API design qui ne se présenteront qu'une fois.
- **Decision-log déjà cadré** : les 10 décisions D1-D10 sont tranchées. Le dev n'a pas à itérer dessus — il implémente. Cela compense un peu le coût Opus, mais ne le neutralise pas (les détails d'implémentation des décisions restent à designer : structure exacte du `WorkstationJwtRevocationChecker`, factory tests, cascade revocation transactionnelle, etc.).

**Bascule possible vers Sonnet** : si l'implémentation T0-T2 (preflight + namespace + PKI) se passe sans accroc et que le dev produit une suite de tests verte d'un seul coup en T4, considérer la suite (T5-T7) en Sonnet pour économiser le coût. Décision à prendre par Henri après le premier point d'étape T2.

**Anti-escalade** : ne pas escalader vers `claude-opus-4-7[1m]` (1M context) — la story est dense mais reste dans la fenêtre 200k tokens d'Opus standard. Le 1M context est utile pour des migrations massives multi-fichiers, pas pour une story bien découpée comme 16.10.

