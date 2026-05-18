# `App\Auth\V1` — Plateforme d'authentification HTTPS + JWT (Epic 16 Phase 2, Story 16.10)

Namespace racine du module d'authentification v1 pour le trafic
**poste ↔ serveur local Sambaedu**. Pose les fondations (PKI locale par étab,
JWT RS256 stateless, tables de tracking, middlewares, endpoints `/api/v1/agent/*`)
qui seront consommées par les stories suivantes de la Phase 2 (16.11 auto-bootstrap,
16.12 logs centralisés, 16.13 cleanup shims).

> **Scope strict 16.10** : (a) PKI locale via `openssl_*` natif PHP + Artisan
> `auth:ca:init`, (b) JWT RS256 (lib `firebase/php-jwt`), (c) tables
> `workstation_refresh_tokens` + `workstation_jwt_revocations`, (d) middlewares
> `EnsureWorkstationJwt` + `RequireBootstrapToken` + `EnsureRefreshToken`,
> (e) endpoints `POST /api/v1/agent/enroll` + `POST /api/v1/agent/refresh`,
> (f) endpoint test `GET /api/v1/agent/ping`, (g) channel log dédié `auth-v1`,
> (h) tests + runbook QA `docs/qa/domains/auth.md`.

---

## Topologie cible (rappel Tech Spec §5.0)

Chaque serveur local Sambaedu (`se4fs-<UAI>.<domaine>`, ex.
`se4fs-0991229y.localdev.fr`) :

1. génère son **propre CA root** (RSA 4096, validité 5 ans) à l'installation
   Phase 2 via `php artisan auth:ca:init` ;
2. émet son **cert serveur HTTPS** (RSA 2048, validité 1 an) signé par ce CA root,
   CN = `se4fs-<UAI>.<domaine>` + SAN hostname court ;
3. émet **une paire de clés RS256** dédiée à la signature JWT (séparée du
   `APP_KEY` Laravel — règle absolue, jamais réutiliser `APP_KEY` pour signer
   un JWT).

Les postes Windows/Linux du LAN scolaire ciblent **uniquement** ce serveur
local — jamais le central. Le central conserve sa Let's Encrypt actuelle pour
l'UI admin.

---

## Garde-fous transversaux Epic 16 (Phase 2)

- **Encapsulation crypto** : aucun fichier sous `app/Auth/V1/*` n'importe
  `Firebase\JWT\JWT` **sauf** `Jwt/WorkstationJwtIssuer.php` et
  `WorkstationJwtVerifier.php` (cf. test archi
  `tests/Architecture/AuthV1NamespaceTest.php`).
- **Encapsulation shell** : aucun fichier sous `app/Auth/V1/*` n'appelle
  `exec()`, `shell_exec()`, `passthru()`, `proc_open()` directement. La PKI
  utilise `openssl_*` natif PHP en priorité ; si une extension v3 nécessite
  un fallback `Process::run([...])` mode array, il est **whitelisté
  exclusivement** dans `Pki/CaInitializer.php`.
- **Pas d'inclusion `legacy/*`** depuis `app/Auth/V1/*` — frontière nette.
- **Émission JWT centralisée** : seul `Http/Controllers/EnrollController.php`
  et `Http/Controllers/RefreshController.php` invoquent `WorkstationJwtIssuer::issue*`.
  Le reste du codebase ne signe **jamais** un JWT.

---

## Catalogue claim `tier`

Le claim `tier` discrimine les clients du namespace `/api/v1/*` :

| `tier`        | Usage                                  | Middleware Laravel       | Status    |
|---------------|----------------------------------------|--------------------------|-----------|
| `workstation` | Poste Windows/Linux du parc            | `auth.v1.workstation`    | ✅ 16.10 |
| `controlhub`  | controlHub (existant)                  | `controlhub.auth`        | Inchangé  |
| `agent`       | Futur agent Go binaire (Phase 3+)      | (réservé)                | Réservé   |

Le `WorkstationJwtVerifier` rejette tout JWT dont le claim `tier` ne correspond
pas à l'`expected_tier` du middleware (`jwt.wrong_tier`).

---

## Catalogue codes d'erreur (cf. `Support/JwtErrorCodes.php`)

Format réponse erreur (décision D8 — pas RFC 7807, JSON simple) :

```json
{
  "error": "unauthorized",
  "message": "JWT signature invalid",
  "code": "jwt.signature_invalid"
}
```

| Code                       | HTTP | Source           |
|----------------------------|------|------------------|
| `jwt.missing`              | 401  | EnsureWorkstationJwt |
| `jwt.malformed`            | 401  | WorkstationJwtVerifier |
| `jwt.signature_invalid`    | 401  | WorkstationJwtVerifier |
| `jwt.expired`              | 401  | WorkstationJwtVerifier |
| `jwt.revoked`              | 401  | WorkstationJwtRevocationChecker |
| `jwt.wrong_tier`           | 401  | WorkstationJwtVerifier |
| `jwt.unknown_workstation`  | 401  | (réservé)         |
| `bootstrap_token.missing`  | 401  | RequireBootstrapToken |
| `bootstrap_token.invalid`  | 401  | LegacyBootstrapTokenValidator |
| `refresh.missing`          | 400  | EnsureRefreshToken |
| `refresh.invalid`          | 401  | EnsureRefreshToken |
| `refresh.expired`          | 401  | EnsureRefreshToken |
| `refresh.revoked`          | 401  | EnsureRefreshToken |
| `refresh.replay_detected`  | 401  | EnsureRefreshToken + cascade revocation |

---

## Convention de logging — channel `auth-v1`

Tout log émis depuis `App\Auth\V1\*` doit aller sur le channel `auth-v1`
(`config/logging.php`). Verbosité paramétrable via `AUTH_V1_LOG_LEVEL`
(default `debug` Phase 2, à bumper `info` en Phase 3).

### `action_type` documentés

| `action_type`                | Émetteur                         | Niveau    |
|------------------------------|----------------------------------|-----------|
| `auth.ca.init.start`         | CaInitializer / AuthCaInit       | info      |
| `auth.ca.init.success`       | CaInitializer / AuthCaInit       | info      |
| `auth.ca.init.skipped`       | CaInitializer (idempotence)      | info      |
| `auth.ca.server.regenerated` | CaInitializer (--regenerate-server-only) | info |
| `auth.bootstrap.attempted`   | RequireBootstrapToken            | info/warn |
| `auth.enroll.success`        | EnrollController                 | info      |
| `auth.token.issued`          | WorkstationJwtIssuer             | debug     |
| `auth.token.refreshed`       | WorkstationJwtRefreshService     | info      |
| `auth.token.replay_detected` | WorkstationJwtRefreshService     | warning   |
| `auth.token.revoked`         | WorkstationRevoke (artisan)      | info      |
| `auth.jwt.rejected`          | WorkstationJwtVerifier           | warning   |

**Aucun secret n'est jamais loggé** (clé privée, refresh_token clear, JWT
complet). Seuls `workstation_uuid`, `jti`, `kid`, `exp`, `ip`, `mac`, `hostname`,
`os` sont autorisés.

---

## Stockage des clés et certificats (cf. `config/auth_v1.php`)

| Fichier                                    | Mode  | Rôle                            |
|--------------------------------------------|-------|---------------------------------|
| `storage/keys/pki/ca-root.key`             | 0600  | Clé privée du CA root (validité 5 ans) |
| `storage/keys/pki/ca-root.crt`             | 0644  | Cert auto-signé du CA root      |
| `storage/keys/pki/server.key`              | 0600  | Clé privée du cert serveur HTTPS |
| `storage/keys/pki/server.crt`              | 0644  | Cert serveur HTTPS signé par le CA |
| `storage/keys/jwt/private.pem`             | 0600  | Clé privée RS256 (signature JWT) |
| `storage/keys/jwt/public.pem`              | 0644  | Clé publique RS256 (vérification JWT) |

> ⚠️ Le dossier `storage/keys/` est **gitignored** (cf. `.gitignore` racine).
> Aucun de ces fichiers ne doit transiter par VCS, logs, ou la réponse HTTP.

### Fixtures tests

`tests/fixtures/auth-v1/{private,public}.pem` sont des paires RS256 **TEST ONLY**
commitées dans le repo pour permettre des tests sign+verify déterministes
(iso-pattern Laravel — un `tests/fixtures/secret.txt` factice). **NEVER use
these keys in production.** Le runtime `WorkstationJwtIssuer` interdit
explicitement leur usage si `APP_ENV !== 'testing'`.

---

## Structure des sous-dossiers

```
app/Auth/V1/
├── Http/
│   ├── Controllers/
│   │   ├── EnrollController.php
│   │   ├── RefreshController.php
│   │   └── PingController.php
│   ├── Middleware/
│   │   ├── EnsureWorkstationJwt.php
│   │   ├── RequireBootstrapToken.php
│   │   └── EnsureRefreshToken.php
│   └── Requests/
│       ├── EnrollAgentRequest.php
│       └── RefreshTokenRequest.php
├── Jwt/
│   ├── Exceptions/InvalidJwtException.php
│   ├── WorkstationJwtClaims.php          (DTO readonly)
│   ├── WorkstationJwtIssuer.php          (sign — whitelist Firebase\JWT)
│   ├── WorkstationJwtVerifier.php        (verify — whitelist Firebase\JWT)
│   ├── WorkstationJwtRefreshService.php  (rotation + replay cascade)
│   └── WorkstationJwtRevocationChecker.php (cache APC + DB lookup)
├── Models/
│   ├── WorkstationRefreshToken.php
│   └── WorkstationJwtRevocation.php
├── Pki/
│   └── CaInitializer.php                 (openssl_* natif + Process whitelist)
├── Services/
│   └── LegacyBootstrapTokenValidator.php (APCu legacy `apps.<md5>`)
└── Support/
    └── JwtErrorCodes.php                 (constantes du catalogue D8)
```

---

## Cohabitation `/api/v1/*`

| Préfixe                                       | Middleware            | Owner      |
|-----------------------------------------------|-----------------------|------------|
| `/api/v1/snapshot`, `/api/v1/workstation-groups`, … | `controlhub.auth` | controlHub |
| `/api/v1/agent/enroll`                        | `auth.v1.bootstrap`   | 16.10      |
| `/api/v1/agent/refresh`                       | `auth.v1.refresh`     | 16.10      |
| `/api/v1/agent/ping`                          | `auth.v1.workstation` | 16.10      |
| `/api/v1/script-execution-logs` (futur)       | `auth.v1.workstation` | 16.12      |
| `/api/v1/scripts/*` (futur)                   | `auth.v1.workstation` | Phase 3+   |

Aucune collision : le sous-namespace `/api/v1/agent/*` est dédié aux postes,
et la discrimination par `tier` empêche un JWT controlHub de passer le
middleware `auth.v1.workstation` (et inversement).

---

## Références

- `_bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md` §5.0, §5.2, §5.6, §6.1, §7
- `_bmad-output/implementation-artifacts/16-10-securisation-https-jwt-endpoints.md`
- `app/Gpo/README.md` — pattern catalogue `action_type` et organisation namespace
- `app/Http/Middleware/ControlHubAuth.php` — pattern Bearer middleware référence
- `docs/qa/domains/auth.md` — runbook QA append-only Phase 2 auth
