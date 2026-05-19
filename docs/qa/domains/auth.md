# QA manuel — Domaine Auth (HTTPS + JWT v1)

> Runbook E2E pour les stories du domaine Auth (Phase 2). Append-only :
> chaque story ajoute une section avec ses scénarios numérotés stables.

**Pré-requis** :

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Composer à jour (lib `firebase/php-jwt` installée) : `composer install --no-dev`
- APCu CLI activé : `php -r 'var_dump(apcu_enabled());'` → `bool(true)`
- OpenSSL CLI présent : `which openssl && openssl version`
- Apache (ou nginx) actif : `systemctl is-active apache2` ou `nginx`

---

## Story 16.10 — Sécurisation HTTPS + JWT endpoints poste↔serveur local

**Date livraison** : 2026-05-16
**Migrations à appliquer** : `2026_05_16_120000_create_workstation_refresh_tokens_table.php` + `2026_05_16_120100_create_workstation_jwt_revocations_table.php`
**Permissions requises** : `root` (pour `auth:ca:init` qui écrit dans `storage/keys/`)

### Section 1 — PKI locale

#### Scénario 16.10-1 — `php artisan auth:ca:init` génère les 6 fichiers

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu-reload
php artisan auth:ca:init
```

**Attendu** :

- Sortie console : `auth:ca:init → initialized` + liste « Managed files »
  avec 6 fichiers en statut `OK` :
  - `storage/keys/pki/ca-root.key` (0600)
  - `storage/keys/pki/ca-root.crt` (0644)
  - `storage/keys/pki/server.key` (0600)
  - `storage/keys/pki/server.crt` (0644)
  - `storage/keys/jwt/private.pem` (0600)
  - `storage/keys/jwt/public.pem` (0644)
- Bloc Apache et bloc nginx affichés en sortie console.
- Exit code 0.

**Vérification permissions** :

```bash
ls -la storage/keys/pki/ storage/keys/jwt/
# Attendu : -rw------- pour les *.key et private.pem
#           -rw-r--r-- pour les *.crt et public.pem
```

#### Scénario 16.10-2 — Idempotence (re-exécution = no-op)

```bash
php artisan auth:ca:init  # second appel
```

**Attendu** :

- Sortie : `auth:ca:init → already_initialized`
- Aucun fichier ré-écrit (mtime inchangé)
- Exit code 0

#### Scénario 16.10-3 — `--force` régénère tout avec backup

```bash
php artisan auth:ca:init --force --no-interaction
```

**Attendu** :

- Sortie : `auth:ca:init → force_regenerated`
- Fichiers backups présents : `storage/keys/pki/ca-root.crt.bak-<stamp>` etc.
- Nouveau contenu pour les 6 fichiers principaux (mtime mis à jour).
- ⚠️ Tous les JWT précédemment émis deviennent invalides (`jwt.signature_invalid`).

#### Scénario 16.10-4 — Configuration Apache vhost HTTPS

1. Récupérer le bloc Apache affiché par `auth:ca:init` (sortie console).
2. L'intégrer dans `/etc/apache2/sites-available/sambaedu-ssl.conf`
   (ou dans le vhost existant).
3. Vérifier `apachectl configtest` → `Syntax OK`.
4. Reload Apache : `systemctl reload apache2`.
5. Smoke local (auto-signé, donc `-k`) :

```bash
curl -kv https://se4fs-<UAI>.<domaine>/api/v1/agent/ping
```

**Attendu** : HTTP 401 + JSON `{ "error": "unauthorized", "code": "jwt.missing", ... }`.

#### Scénario 16.10-5 — Régénération cert serveur seul

```bash
php artisan auth:ca:init --regenerate-server-only --no-interaction
```

**Attendu** :

- `storage/keys/pki/server.{key,crt}` régénérés (mtime mis à jour)
- `storage/keys/pki/ca-root.{key,crt}` inchangés (mtime identique)
- `storage/keys/jwt/{private,public}.pem` inchangés
- Backup uniquement de `server.*`

#### Scénario 16.10-6 — Sauvegarde et restoration PKI

**Sauvegarde** :

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
  "cd /var/www/sambaedu-reload && tar -czf /tmp/pki-$(date +%F).tgz storage/keys/"
scp -i ~/.ssh/id_se4fs_vm root@192.168.122.50:/tmp/pki-*.tgz ~/backups/
```

**Restoration** :

```bash
scp -i ~/.ssh/id_se4fs_vm ~/backups/pki-2026-05-16.tgz root@192.168.122.50:/tmp/
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu-reload
tar -xzf /tmp/pki-2026-05-16.tgz
chmod 0600 storage/keys/**/*.key storage/keys/**/*.pem
systemctl reload apache2
```

#### Scénario 16.10-7 — Compromission CA root (procédure de catastrophe)

⚠️ **Procédure documentée mais non implémentée Phase 2** — déclenche la perte
de toutes les sessions. À utiliser uniquement si le CA root est confirmé
compromis (vol fichier `ca-root.key`).

1. Régénération complète :

```bash
php artisan auth:ca:init --force
```

2. Pour chaque workstation enrôlée, révoquer :

```bash
# Liste des workstation_uuid actifs
psql -d sambaedu -c "SELECT DISTINCT workstation_uuid FROM workstation_refresh_tokens WHERE revoked_at IS NULL;"
# Pour chacun :
php artisan workstation:revoke <uuid> --reason=ca_compromise --by=admin:incident-2026-05-XX
```

3. Redistribuer le nouveau CA root aux postes : forcer un re-enroll via 16.11
   (auto-bootstrap → détection du fingerprint différent → re-enroll).

---

### Section 2 — Enrollment poste

#### Scénario 16.10-8 — Enroll happy path (avec bootstrap token APCu legacy)

**Pré-requis** : un script poste a déjà tapé `gpo/applications.php` legacy
dans les 30 dernières minutes, qui a posé `apcu_store('apps.<md5>', ...)`.
Récupérer ce md5 (cf. log access ou via tinker `apcu_fetch`).

```bash
TOKEN=$(php artisan tinker --execute='echo apcu_iterator()->current()["key"];' | head -1 | sed 's/^apps\.//')
echo "Bootstrap token: $TOKEN"

curl -k -i -X POST https://se4fs-<UAI>.<domaine>/api/v1/agent/enroll \
  -H "Content-Type: application/json" \
  -H "X-Bootstrap-Token: $TOKEN" \
  -d '{
    "uuid": "11111111-1111-1111-1111-111111111111",
    "mac": "AA:BB:CC:DD:EE:FF",
    "hostname": "pc-test-01",
    "os": "linux"
  }'
```

**Attendu** : HTTP 200 + payload :

```json
{
  "success": true,
  "message": "Workstation enrolled",
  "access_token": "<JWT 3 segments base64>",
  "refresh_token": "<64 hex chars>",
  "token_type": "Bearer",
  "expires_in": 86400,
  "refresh_expires_in": 2592000,
  "ca_cert_pem": "-----BEGIN CERTIFICATE-----\n...",
  "server_base_url": "https://se4fs-<UAI>.<domaine>"
}
```

**Vérification DB** :

```sql
SELECT id, workstation_uuid, expires_at, revoked_at, client_meta
FROM workstation_refresh_tokens
WHERE workstation_uuid = '11111111-1111-1111-1111-111111111111';
```

→ 1 ligne, `revoked_at = NULL`, `expires_at = now + 30j`,
   `client_meta = {"mac":"AA:BB:...","hostname":"pc-test-01","os":"linux","enroll_ip":"..."}`.

#### Scénario 16.10-9 — Enroll sans bootstrap token

```bash
curl -k -i -X POST https://se4fs-<UAI>.<domaine>/api/v1/agent/enroll \
  -H "Content-Type: application/json" \
  -d '{"uuid":"...","mac":"...","hostname":"...","os":"linux"}'
```

**Attendu** : HTTP 401 + `{"error":"unauthorized","code":"bootstrap_token.missing", ...}`.

#### Scénario 16.10-10 — Enroll avec bootstrap token invalide

```bash
curl -k -i -X POST https://se4fs-<UAI>.<domaine>/api/v1/agent/enroll \
  -H "Content-Type: application/json" \
  -H "X-Bootstrap-Token: $(echo -n bogus | md5sum | cut -d' ' -f1)" \
  -d '{"uuid":"...","mac":"...","hostname":"...","os":"linux"}'
```

**Attendu** : HTTP 401 + `{"code":"bootstrap_token.invalid"}`.

#### Scénario 16.10-11 — Enroll avec body invalide (UUID malformé)

**Attendu** : HTTP 422 (validation Laravel standard).

#### Scénario 16.10-12 — Rate limit dépassé sur enroll

```bash
for i in $(seq 1 15); do
  curl -ks -o /dev/null -w "%{http_code}\n" \
    -X POST https://se4fs-<UAI>.<domaine>/api/v1/agent/enroll \
    -H "X-Bootstrap-Token: invalid"
done
```

**Attendu** : les 10 premiers `401`, les suivants `429 Too Many Requests`.

---

### Section 3 — Refresh + détection replay

#### Scénario 16.10-13 — Refresh happy path

```bash
# Récupérer un refresh_token valide depuis le scénario 16.10-8 (REFRESH=...)
curl -k -i -X POST https://se4fs-<UAI>.<domaine>/api/v1/agent/refresh \
  -H "Content-Type: application/json" \
  -d "{\"refresh_token\": \"$REFRESH\"}"
```

**Attendu** : HTTP 200 + payload :

```json
{
  "success": true,
  "message": "Refresh rotated",
  "access_token": "<nouveau JWT>",
  "refresh_token": "<nouveau 64 hex>",
  "token_type": "Bearer",
  "expires_in": 86400,
  "refresh_expires_in": 2592000
}
```

**Vérification DB** :

```sql
SELECT id, refresh_token_hash, revoked_at, revocation_reason
FROM workstation_refresh_tokens
ORDER BY issued_at DESC LIMIT 2;
```

→ Le plus récent : `revoked_at = NULL`. Le précédent : `revoked_at = now`,
  `revocation_reason = 'refresh_rotation'`.

#### Scénario 16.10-14 — Refresh replay détecté

```bash
# Re-utiliser le $REFRESH déjà rotaté au 16.10-13
curl -k -i -X POST https://se4fs-<UAI>.<domaine>/api/v1/agent/refresh \
  -H "Content-Type: application/json" \
  -d "{\"refresh_token\": \"$REFRESH\"}"
```

**Attendu** : HTTP 401 + `{"code":"refresh.replay_detected"}`.

**Vérification DB cascade** : tous les refresh actifs du `workstation_uuid` sont
maintenant `revoked_at != NULL`, `revocation_reason = 'cascade_revoke'`.

**Vérification logs** :

```bash
tail -f storage/logs/auth-v1/auth-v1-*.log | grep replay_detected
```

→ Une entrée `warning` `auth.token.replay_detected` avec le `workstation_uuid`
  et `cascade_revoked_count`.

#### Scénario 16.10-15 — Refresh inconnu

```bash
curl -k -i -X POST https://se4fs-<UAI>.<domaine>/api/v1/agent/refresh \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"0000000000000000000000000000000000000000000000000000000000000000"}'
```

**Attendu** : HTTP 401 + `{"code":"refresh.invalid"}`.

---

### Section 4 — Endpoint scripts (lecture seule, 404, 403)

> **À VENIR** — les endpoints scripts métier (`GET /api/v1/scripts/...`) ne
> sont pas livrés en 16.10. Cette section sera enrichie par 17.X (portage
> métier).
>
> 16.10 livre uniquement `GET /api/v1/agent/ping` comme endpoint de test
> JWT (cf. Section 5).

---

### Section 5 — Endpoint test `GET /api/v1/agent/ping`

#### Scénario 16.10-16 — Ping happy path (avec JWT valide)

```bash
ACCESS=...  # depuis 16.10-8 ou 16.10-13
curl -k -i -X GET https://se4fs-<UAI>.<domaine>/api/v1/agent/ping \
  -H "Authorization: Bearer $ACCESS"
```

**Attendu** : HTTP 200 + payload :

```json
{
  "success": true,
  "message": "pong",
  "workstation_uuid": "11111111-1111-1111-1111-111111111111",
  "server_time": "2026-05-16T19:32:45+00:00",
  "api_version": "v1",
  "se4fs_name": "se4fs-<UAI>"
}
```

#### Scénario 16.10-17 — Ping sans Authorization

```bash
curl -k -i https://se4fs-<UAI>.<domaine>/api/v1/agent/ping
```

**Attendu** : HTTP 401 + `{"code":"jwt.missing"}`.

#### Scénario 16.10-18 — Ping avec JWT expiré

Forcer une émission avec exp dans le passé via tinker :

```bash
php artisan tinker --execute='
use Firebase\JWT\JWT;
$priv = file_get_contents(storage_path("keys/jwt/private.pem"));
echo JWT::encode([
  "iss" => config("sambaedu.se4fs_name"),
  "sub" => "11111111-1111-1111-1111-111111111111",
  "iat" => time() - 7200,
  "exp" => time() - 3600,
  "jti" => "test-expired-jti",
  "tier" => "workstation",
  "kid" => config("auth_v1.jwt.active_kid"),
], $priv, "RS256", config("auth_v1.jwt.active_kid"));
'
# → utiliser ce JWT dans curl
```

**Attendu** : HTTP 401 + `{"code":"jwt.expired"}`.

#### Scénario 16.10-19 — Ping avec tier=controlhub (mocké)

Forcer une émission avec `tier=controlhub` (idem 16.10-18 mais `tier`
différent).

**Attendu** : HTTP 401 + `{"code":"jwt.wrong_tier"}`.

---

### Section 6 — Révocation par UUID

> ✅ **`workstation:revoke` révoque effectivement TOUS les tokens du poste** (Phase 2, révision Q3 16.10)
>
> La commande `php artisan workstation:revoke {uuid}` invalide :
>
> 1. **Tous les refresh tokens actifs** du poste (révocation DB immédiate).
> 2. **Tous les access JWT en cours** émis avant la commande (effet ≤ 60s — TTL cache
>    APCu workstation-wide). Le middleware `EnsureWorkstationJwt` rejette désormais en
>    `jwt.revoked` tout JWT dont l'`iat <= revoked_at` du marker workstation-wide.
>
> **Mécanisme** : la commande insère une row marker `workstation_jwt_revocations
> (workstation_uuid, revoked_at = now())` + push le cache APCu `jwt:revoked_ws:<uuid>`
> (TTL 3600s par défaut). Le `WorkstationJwtRevocationChecker::isRevoked($jti, $sub, $iat)`
> compare l'`iat` du JWT au `revoked_at` cutoff workstation.
>
> **TTL access token** : défaut 10h (révision review — couvre journée scolaire avec
> marge, réduit la fenêtre d'exposition vs 24h précédent).
>
> **Fenêtre résiduelle ≤ 60s** : c'est le délai max entre `workstation:revoke` et
> propagation effective sur tous les workers PHP-FPM (cache APCu négatif TTL 60s).
> Pour une révocation **strictement immédiate** :
>
> - `php artisan cache:clear` après `workstation:revoke` (flush APCu — propagation < 1s).
> - **Ou** `php artisan auth:ca:init --force` (régénère la paire JWT, invalide TOUS les
>   tokens de TOUS les postes d'un coup — option catastrophe, postes doivent re-bootstrap).
> - **Ou** couper l'accès réseau du poste (DHCP lease revoke, firewall rule).

#### Scénario 16.10-20 — `php artisan workstation:revoke <uuid>`

```bash
php artisan workstation:revoke 11111111-1111-1111-1111-111111111111 \
  --reason=lost_device --by=admin:henri
```

**Attendu** :

- Sortie : `Workstation ... : N active refresh token(s)` + `Revoked N refresh
  token(s)` + warning sur la fenêtre 24h.
- Exit code 0.

**Vérification DB** :

```sql
SELECT count(*) FROM workstation_refresh_tokens
  WHERE workstation_uuid = '11111111-1111-1111-1111-111111111111'
    AND revoked_at IS NOT NULL
    AND revocation_reason = 'lost_device';

SELECT count(*) FROM workstation_jwt_revocations
  WHERE workstation_uuid = '11111111-1111-1111-1111-111111111111'
    AND reason = 'lost_device';
```

→ Toutes les refresh actives sont marquées + 1 marker entry est inséré
  dans `workstation_jwt_revocations`.

**Vérification cache APCu** :

```bash
php artisan tinker --execute='
echo Cache::store("apc")->get("jwt:revoked:<marker_jti>") ? "REVOKED\n" : "NOT_CACHED\n";
'
```

#### Scénario 16.10-21 — Révocation `--dry-run`

```bash
php artisan workstation:revoke 22222222-2222-2222-2222-222222222222 --dry-run
```

**Attendu** : affichage du count mais aucune modification DB.

---

### Section 7 — Dual-mode legacy (vérification non-régression)

#### Scénario 16.10-22 — Endpoint legacy `/gpo/firefox_out.php` reste actif

```bash
# Poste qui pingue le legacy en HTTP md5/APCu (pas HTTPS)
curl -i -X POST http://se4fs-<UAI>.<domaine>/gpo/firefox_out.php \
  -F "os=linux" -F "user=admin" -F "machine=pc-test-01" \
  -F "id=$(echo test | md5sum | cut -d' ' -f1)"
```

**Attendu** : HTTP 200 (ou 4xx selon contexte — pas 410 Gone) — la route
existe toujours, le comportement legacy fonctionne.

> Tests Feature dédiés couvrent déjà ce cas :
> - `tests/Feature/Gpo/ApplicationsScriptsEndpointTest.php` (Story 16.7)
> - `tests/Feature/Gpo/AssociationsOutEndpointTest.php` (Story 16.3c)
> - etc.
>
> Et notre test architectural
> `tests/Architecture/AuthV1NamespaceTest::legacy_out_routes_are_preserved`
> vérifie statiquement que les routes restent enregistrées dans
> `routes/web.php`.

#### Scénario 16.10-23 — Cohabitation `/api/v1/snapshot` (controlHub)

```bash
# Avec une clé controlHub valide
curl -i https://se4fs-<UAI>.<domaine>/api/v1/snapshot \
  -H "Authorization: Bearer <controlhub_key>"
```

**Attendu** : HTTP 200 (controlhub.auth toujours fonctionnel) — pas de
collision avec le nouveau namespace `/api/v1/agent/*`.

---

### Section 8 — Logs `auth-v1`

#### Scénario 16.10-24 — Vérifier le channel `auth-v1`

Après quelques scénarios précédents :

```bash
ls -la storage/logs/auth-v1/
tail -f storage/logs/auth-v1/auth-v1-$(date +%F).log
```

**Attendu** : présence du fichier rotaté par date, et entrées JSON-style
avec les `action_type` documentés :

- `auth.ca.init.start`, `auth.ca.init.success`, `auth.ca.init.skipped`
- `auth.bootstrap.attempted` (info en succès, warning en échec)
- `auth.enroll.success`
- `auth.token.issued` (debug)
- `auth.token.refreshed`
- `auth.token.replay_detected` (warning)
- `auth.workstation.revoked` (info)
- `auth.jwt.rejected` (warning)

**Vérification : aucun secret loggé** :

```bash
# Aucun de ces motifs ne doit jamais apparaître
grep -E 'BEGIN PRIVATE KEY|BEGIN RSA' storage/logs/auth-v1/*.log
# → vide

# Le clear refresh ne doit pas être loggé en clair
# (Seul un hash partiel sha256 est acceptable.)
```

---

## Checklist rapide

- [ ] `php artisan auth:ca:init` → 6 fichiers générés, perms 0600/0644
- [ ] Re-exec = no-op (idempotence)
- [ ] `--force` régénère + backup
- [ ] `--regenerate-server-only` ne touche pas le CA
- [ ] Apache/nginx vhost HTTPS reload OK + smoke `curl -kv …/ping` → 401
- [ ] Enroll happy path avec bootstrap token md5 valide → 200 + ca_cert_pem
- [ ] Enroll sans bootstrap → 401 `bootstrap_token.missing`
- [ ] Refresh rotation + ancien revoked DB
- [ ] Refresh replay → 401 + cascade revocation
- [ ] Ping happy path → 200 avec `success:true`, `workstation_uuid` = sub claim
- [ ] Ping JWT expiré → 401 `jwt.expired`
- [ ] Ping JWT révoqué (DB+cache) → 401 `jwt.revoked`
- [ ] Ping JWT `tier=controlhub` → 401 `jwt.wrong_tier`
- [ ] `workstation:revoke <uuid>` → marker DB + cache APCu peuplé
- [ ] Legacy `/gpo/*_out.php` réponse toujours fonctionnelle (non-régression)
- [ ] `/api/v1/snapshot` controlHub toujours OK (cohabitation)
- [ ] `tail storage/logs/auth-v1/*.log` montre les events sans aucun secret

---

## Post-correctifs & non-régressions

### 2026-05-18 — Fix `CaInitializer` ownership runtime web (`auth_v1.pki.web_owner`)

**Finding bloquant identifié pendant la QA §2** : `auth:ca:init` lancé en root
(via `update.sh` ou SSH) produisait des fichiers PKI `root:root 0600`, illisibles
par le runtime PHP-FPM Sambaedu (`www-admin`, pas le défaut Debian `www-data`).
Toute la chaîne auth v1 retombait en HTTP 500 *"JWT private key not found or
not readable"* sur les endpoints `/api/v1/agent/*`.

**Patch** : ajout config `auth_v1.pki.web_owner` (env `AUTH_V1_PKI_WEB_OWNER`,
défaut `www-admin` pour Sambaedu, peut être overridé en `www-data` pour install
standard Debian) + helper `CaInitializer::applyWebOwnership($path)` appelé après
chaque écriture web-readable :

- `storage/keys/pki/ca-root.crt` (servi via réponse enroll `ca_cert_pem`)
- `storage/keys/pki/server.crt` + `server.key` (Apache HTTPS vhost)
- `storage/keys/jwt/private.pem` + `public.pem` (JWT signing/verification)
- `storage/keys/pki/` + `storage/keys/jwt/` (traversée)

`storage/keys/pki/ca-root.key` reste root-only — jamais lu par le web (utilisé
uniquement par `CaInitializer` lui-même pour signer de nouveaux certs serveur).

**Pourquoi pas ACL setfacl ?** Le pattern chown est plus simple et survit aux
`chmod` ultérieurs (les ACL avec mask `---` deviennent ineffectives après
`chmod 0600` — piège connu). Le runtime web obtient l'ownership direct, root
conserve l'accès via CAP_DAC_OVERRIDE.

**Action prod** : après merge, vérifier que `AUTH_V1_PKI_WEB_OWNER` est défini
(ou laissé à `www-admin` par défaut) dans le `.env`, puis lancer
`php artisan auth:ca:init --force` pour ré-appliquer la propriété aux fichiers
existants (l'idempotente `auth:ca:init` ne touchera pas les fichiers).

---

## Smoke test à exécuter quand VM up — bloc prêt à coller

> Bloc d'instructions à dérouler **après** que la VM remonte et que Henri
> aura validé le merge. Capture l'état d'init nécessaire pour mettre en
> production la Story 16.10.

```bash
# 1. SSH + sync git
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
cd /var/www/sambaedu-reload
git log -1 --oneline  # vérifier branche 16-10 mergée

# 2. composer install (lock à jour avec firebase/php-jwt:^6.10 || ^7.0)
composer install --no-dev --optimize-autoloader

# 3. Migrations
php artisan migrate

# 4. PKI init
php artisan auth:ca:init

# 5. Web server config (intégrer le bloc Apache/nginx affiché par auth:ca:init)
$EDITOR /etc/apache2/sites-available/sambaedu-ssl.conf
apachectl configtest && systemctl reload apache2

# 6. Smoke /api/v1/agent/ping → 401
curl -kv https://se4fs-<UAI>/api/v1/agent/ping

# 7. Smoke enroll (récupérer un md5 APCu valide depuis logs legacy
#    `gpo/applications.php` ou tinker)
TOKEN=$(php artisan tinker --execute='
  foreach (apcu_iterator() as $k) {
    if (str_starts_with($k["key"], "apps.")) { echo substr($k["key"], 5); break; }
  }
')
[ -n "$TOKEN" ] && curl -k -i -X POST https://se4fs-<UAI>/api/v1/agent/enroll \
  -H "Content-Type: application/json" \
  -H "X-Bootstrap-Token: $TOKEN" \
  -d '{"uuid":"11111111-1111-1111-1111-111111111111","mac":"AA:BB:CC:DD:EE:FF","hostname":"smoke-pc","os":"linux"}'

# 8. Tests
./scripts/run-tests.sh
# → cible Auth\V1 : ~50+ tests Unit + Feature + Archi, tous verts attendus.

# 9. Sprint status
grep -A1 "16-10-securisation" _bmad-output/implementation-artifacts/sprint-status.yaml
# → doit montrer `review` (ou `done` si Henri a fait la review)
```

---

## Story 16.11 — Auto-bootstrap migration postes existants

> **Scope** : bascule transparente du parc Windows/Linux des endpoints legacy `/gpo/*_out.php` HTTP md5/APCu vers `/api/v1/*` HTTPS+JWT, sans intervention admin. Implémente l'injection d'un fragment de bootstrap idempotent dans les réponses legacy + 2 endpoints publics `bootstrap.{cmd,sh}` + durcissement du couple token↔UUID + IP whitelist LAN + commande artisan `migration:health-check`.

### Pré-requis communs

- SE4FS up + accessible LAN (parité 16.10 smoke).
- Migrations `php artisan migrate` exécutées (16.10 + 16.11 ⇒ tables `workstations_migration_status` + `workstation_migration_attempts`).
- PKI initialisée via `php artisan auth:ca:init` (16.10 prérequis).
- Channel logs `auth-v1` actif dans `config/logging.php`.

> **Note infra HTTPS (16.10 prérequis effectif)** — les scénarios smoke ci-dessous utilisent `https://$(hostname -f)/...`. Si le module Apache `ssl` n'est pas activé sur la VM (cas observé sur certaines VM de dev où la config HTTPS de 16.10 n'a pas été appliquée), tous les smoke 16.11 restent valides en `http://` (port 80). Vérifier : `a2query -m ssl`. Le LAN-only et l'injection fragment fonctionnent indifféremment HTTP/HTTPS — seule la fenêtre MITM bootstrap (cf. §"Limitation Phase 2") suppose HTTPS en prod.

> **⚠️ Limite smoke APCu CLI/FPM (impacte 16.11-1, 16.11-2, 16.11-3)** — `php artisan tinker` (CLI) et FPM utilisent par défaut **deux segments APCu séparés**. Une clé `apcu_store("apps.$TOKEN", ...)` posée via tinker n'est **pas visible** côté FPM. Conséquence : un enroll lancé via curl après seed tinker renvoie `401 bootstrap_token.invalid` (token absent côté FPM), **pas** `uuid_mismatch`. Trois options :
>
> 1. **Méthode canonique (recommandée)** — exécuter les tests équivalents en process unique :
>    ```bash
>    php artisan test \
>      --filter='EnrollControllerTest::(it_rejects_enroll_when_uuid_does_not_match_bootstrap_context|it_rejects_enroll_from_non_lan_ip)|LegacyBootstrapTokenValidatorTest::isvalid_with_payload_missing_uuid_returns_false_fail_closed'
>    ```
>    Couvre 16.11-2 (uuid mismatch) + 16.11-3 (context sans clé uuid, fail-closed) + 16.11-5 (enroll non-LAN).
> 2. **Seed APCu côté FPM** — déclencher un appel HTTP qui passe par `ApcuAppContextWriter` (typiquement `gpo/applications.php` avec un `uuid` + `user`/`machine` AD valides), puis curl `/enroll`. Pratique uniquement avec un AD configuré sur la VM.
> 3. **Smoke iso-runbook** — possible si la VM a `apc.enable_cli=1` ET un segment APCu partagé CLI↔FPM (configuration non-standard).

### Section 9 — Durcissement bootstrap token + couple UUID (16.11 D1)

#### Scénario 16.11-1 — Enroll happy path avec uuid matching context APCu

```bash
# 1. Poser manuellement un contexte APCu avec uuid (simule applications.php legacy)
TOKEN=$(php -r 'echo md5("smoke-bootstrap-16-11-1");')
UUID="11111111-1111-4111-8111-111111111111"
php artisan tinker --execute='
  apcu_store("apps.'"$TOKEN"'", [
    "uuid" => "'"$UUID"'",
    "user" => "jdoe",
    "machine" => "pc-smoke-1",
  ], 1800);
'

# 2. Enroll avec le bon uuid (depuis IP LAN)
curl -k -i -X POST https://$(hostname -f)/api/v1/agent/enroll \
  -H "Content-Type: application/json" \
  -H "X-Bootstrap-Token: $TOKEN" \
  -d "{\"uuid\":\"$UUID\",\"mac\":\"AA:BB:CC:DD:EE:FF\",\"hostname\":\"pc-smoke-1\",\"os\":\"linux\"}"
# Attendu : HTTP 200 + access_token + refresh_token + ca_cert_pem + server_base_url
```

#### Scénario 16.11-2 — Enroll avec uuid mismatch (attaque fixation simulée)

```bash
TOKEN=$(php -r 'echo md5("smoke-bootstrap-16-11-2");')
LEGIT_UUID="22222222-2222-4222-8222-222222222222"
ATTACKER_UUID="99999999-9999-4999-8999-999999999999"

php artisan tinker --execute='apcu_store("apps.'"$TOKEN"'", ["uuid" => "'"$LEGIT_UUID"'"], 1800);'

curl -k -i -X POST https://$(hostname -f)/api/v1/agent/enroll \
  -H "Content-Type: application/json" \
  -H "X-Bootstrap-Token: $TOKEN" \
  -d "{\"uuid\":\"$ATTACKER_UUID\",\"mac\":\"FF:FF:FF:FF:FF:FF\",\"hostname\":\"pc-attacker\",\"os\":\"linux\"}"
# Attendu : HTTP 401 + JSON {"code":"bootstrap_token.uuid_mismatch", "message":"Bootstrap token does not match declared workstation_uuid"}
# Log : auth.bootstrap.uuid_mismatch warning dans storage/logs/auth-v1/auth-v1-$(date +%F).log
```

#### Scénario 16.11-3 — Enroll avec context APCu sans clé uuid → 401 fail-closed

```bash
TOKEN=$(php -r 'echo md5("smoke-bootstrap-16-11-3");')
php artisan tinker --execute='apcu_store("apps.'"$TOKEN"'", ["user" => "jdoe", "machine" => "pc-x"], 1800);'  # PAS de clé uuid

curl -k -i -X POST https://$(hostname -f)/api/v1/agent/enroll \
  -H "Content-Type: application/json" \
  -H "X-Bootstrap-Token: $TOKEN" \
  -d '{"uuid":"11111111-1111-4111-8111-111111111111","mac":"AA:BB:CC:DD:EE:FF","hostname":"pc-x","os":"linux"}'
# Attendu : HTTP 401 — fail-closed (le validator ne fait pas confiance à un contexte sans uuid)
# Log : auth.bootstrap.context_missing_uuid warning
```

### Section 10 — IP whitelist LAN (16.11 D1 partie B)

#### Scénario 16.11-4 — Enroll depuis IP LAN (192.168.X.Y) → 200

```bash
# Le smoke 16.11-1 vérifie déjà ce point depuis une IP LAN.
# Vérifier que les subnets default RFC1918 sont chargés :
php artisan tinker --execute='echo config("auth_v1.bootstrap.allowed_subnets");'
# Attendu : "192.168.0.0/16,10.0.0.0/8,172.16.0.0/12,127.0.0.0/8,::1/128"
```

#### Scénario 16.11-5 — Enroll depuis IP publique simulée → 403

```bash
# Override config pour bloquer 127.0.0.1
php artisan tinker --execute='
  config(["auth_v1.bootstrap.allowed_subnets" => "8.8.8.8/32"]);
'
curl -k -i -X POST https://$(hostname -f)/api/v1/agent/enroll \
  -H "Content-Type: application/json" \
  -H "X-Bootstrap-Token: $(php -r 'echo md5("any-token");')" \
  -d '{"uuid":"11111111-1111-4111-8111-111111111111","mac":"AA:BB:CC:DD:EE:FF","hostname":"pc-x","os":"linux"}'
# Attendu : HTTP 403 + JSON {"success":false,"error":"forbidden","code":"bootstrap.not_lan"}
# Log : auth.bootstrap.lan_blocked warning

# Restaurer config par défaut
php artisan config:clear
```

#### Scénario 16.11-6 — Refresh depuis IP publique simulée → 200/401 (pas de LAN block, cf. D1)

```bash
# /refresh n'est PAS protégé par auth.v1.lan-only — un poste en VPN admin peut refresh.
curl -k -i -X POST https://$(hostname -f)/api/v1/agent/refresh -H "Content-Type: application/json" -d '{}'
# Attendu : HTTP 401 refresh.missing (pas 403 bootstrap.not_lan) → preuve que le LAN-only n'est pas appliqué à /refresh
```

### Section 11 — Auto-bootstrap fragment injection (16.11 D2 + D5 + D6)

> **⚠️ Validation E2E par test Feature (méthode canonique)** — l'injection du fragment ne peut pas être validée par un simple curl sur un endpoint legacy `/gpo/*_out.php`, pour deux raisons cumulatives :
>
> 1. Les endpoints text/plain (`network_out.php`, `firefox_out.php`, `applications.php`, …) exigent un contexte APCu posé **côté FPM** par un appel amont. Le seed via `php artisan tinker` n'est pas visible côté FPM (cf. limite APCu CLI/FPM en intro Section 9). Sans contexte valide, ces endpoints renvoient body vide (parité legacy stricte) ou 4xx → le middleware skip.
> 2. Les endpoints non text/plain sont volontairement skippés (D6) : `wallpaper_out.php` renvoie un BLOB image (JPEG/PNG), `associations_out.php` du JSON. Le précédent runbook ciblait `wallpaper_out.php` par erreur — le middleware skip cet endpoint en permanence (Content-Type ≠ text/plain), ce qui rend les scénarios non discriminants.
>
> Le test Feature `InjectBootstrapFragmentIntegrationTest` couvre les **7 cas** du périmètre en process unique (middleware exécuté contre un controller de test text/plain) :
>
> ```bash
> php artisan test tests/Feature/Auth/V1/InjectBootstrapFragmentIntegrationTest.php
> ```
>
> Cas couverts :
> - `fragment_inject_for_non_migrated_windows_workstation` (= ex 16.11-7)
> - `fragment_inject_for_non_migrated_linux_workstation` (= ex 16.11-8)
> - `fragment_skip_for_migrated_workstation` (= ex 16.11-9)
> - `fragment_skip_for_json_content_type` (= ex 16.11-10, JSON)
> - `fragment_skip_for_4xx_response`
> - `fragment_skip_when_no_uuid_in_request`
> - `fragment_substitutes_server_base_url`
>
> Pour un smoke en conditions réelles, voir Section 13 — Smoke poste réel (action Henri post-VM up).

#### Scénario 16.11-7 — Poste Windows non-migré → fragment cmd en préfixe (PHPUnit Feature)

```bash
php artisan test --filter='InjectBootstrapFragmentIntegrationTest::fragment_inject_for_non_migrated_windows_workstation'
# Attendu : PASS. Le test instancie un controller text/plain, simule un poste non-migré
# (UUID absent de workstations_migration_status), capture la response et vérifie la présence
# du préfixe "@echo off" + "SambaEdu auto-bootstrap" + curl pipe.
```

#### Scénario 16.11-8 — Poste Linux non-migré → fragment sh en préfixe (PHPUnit Feature)

```bash
php artisan test --filter='InjectBootstrapFragmentIntegrationTest::fragment_inject_for_non_migrated_linux_workstation'
# Attendu : PASS. Vérifie le préfixe "# === SambaEdu auto-bootstrap" + curl pipe Linux.
```

#### Scénario 16.11-9 — Poste déjà migré → pas de fragment (PHPUnit Feature)

```bash
php artisan test --filter='InjectBootstrapFragmentIntegrationTest::fragment_skip_for_migrated_workstation'
# Attendu : PASS. Le test seed une row `workstations_migration_status` pour l'UUID,
# appelle l'endpoint, vérifie que la response sort intacte (pas de préfixe).
```

#### Scénario 16.11-10 — Content-Type JSON → pas de fragment (D6, PHPUnit Feature)

```bash
php artisan test --filter='InjectBootstrapFragmentIntegrationTest::fragment_skip_for_json_content_type'
# Attendu : PASS. Le middleware skip toute response dont le Content-Type ne commence pas
# par `text/plain` (associations_out.php JSON, wallpaper_out.php image/jpeg, etc.).
```

### Section 12 — Endpoints publics bootstrap.{cmd,sh}

#### Scénario 16.11-11 — `GET /api/v1/agent/bootstrap.cmd` depuis LAN → 200 + body cmd

```bash
curl -k -i "https://$(hostname -f)/api/v1/agent/bootstrap.cmd" | head -20
# Attendu : HTTP 200 + Content-Type "text/plain; charset=utf-8" + Cache-Control "no-store, no-cache, ..."
# Body contient : "@echo off", "Invoke-RestMethod", "ProtectedData::Protect", "schtasks /create"
# Inserts une row dans workstation_migration_attempts (status='started', os='windows')
```

#### Scénario 16.11-12 — `GET /api/v1/agent/bootstrap.sh` depuis LAN → 200 + body sh

```bash
curl -k -i "https://$(hostname -f)/api/v1/agent/bootstrap.sh" | head -20
# Attendu : HTTP 200 + Content-Type "text/plain"
# Body contient : "#!/bin/bash", "set -e", "update-ca-certificates", "chmod 0600", "systemctl enable sambaedu-refresh.timer"
```

#### Scénario 16.11-13 — bootstrap depuis IP publique → 403

```bash
# Override config pour bloquer 127.0.0.1
php artisan tinker --execute='config(["auth_v1.bootstrap.allowed_subnets" => "8.8.8.8/32"]);'
curl -k -i "https://$(hostname -f)/api/v1/agent/bootstrap.cmd"
# Attendu : HTTP 403 + JSON {"code":"bootstrap.not_lan"}
php artisan config:clear
```

### Section 13 — Smoke poste réel (action Henri post-VM up)

> Ces scénarios doivent être exécutés sur de vrais postes Windows et Linux non migrés. Ils valident DPAPI, certutil, update-ca-certificates, systemd timer — choses qui ne sont pas testables côté serveur Laravel.

#### Scénario 16.11-14 — Poste Windows réel jamais migré

1. Sur un poste Windows propre (jamais migré, registry HKLM\SOFTWARE\SambaEdu absente), provoquer un logon utilisateur.
2. La GPO Windows déclenche `gpo/applications.php` → réponse = fragment cmd + script habituel.
3. Le fragment exécute `curl -kfsS https://se4fs-XXX/api/v1/agent/bootstrap.cmd | cmd`.
4. Vérifier sur la VM Sambaedu :
   ```bash
   php artisan tinker --execute='
     echo "Status: ", json_encode(\App\Auth\V1\Models\WorkstationMigrationStatus::orderByDesc("id")->first()), PHP_EOL;
     echo "Attempts: ", \App\Auth\V1\Models\WorkstationMigrationAttempt::recent(1)->count(), PHP_EOL;
   '
   ```
   Attendu : 1 status (uuid+os=windows), N attempts (started + enrolled).
5. Sur le poste Windows, vérifier registry :
   ```cmd
   reg query HKLM\SOFTWARE\SambaEdu\AuthV1 /v Migrated
   reg query HKLM\SOFTWARE\SambaEdu\AuthV1 /v AccessTokenProtected
   ```
   Attendu : `Migrated = 0x1`, `AccessTokenProtected` REG_BINARY.
6. Vérifier tâche planifiée :
   ```cmd
   schtasks /query /tn SambaEdu-RefreshTokens
   ```

#### Scénario 16.11-15 — Poste Linux réel jamais migré

1. Sur un poste Linux propre (jamais migré, `/var/lib/sambaedu/auth.json` absent), provoquer un boot/logon.
2. Le fragment sh `update-ca-certificates` + `systemctl enable sambaedu-refresh.timer`.
3. Vérifier :
   ```bash
   ls -la /var/lib/sambaedu/   # auth.json mode 0600 root:root + migrated touch
   ls /usr/local/share/ca-certificates/sambaedu-ca.crt
   systemctl status sambaedu-refresh.timer
   cat /var/lib/sambaedu/auth.json | jq .
   ```

#### Scénario 16.11-16 — Idempotence : 2ème reboot

1. Reboot du poste migré (Win ou Linux).
2. Le fragment se relance mais détecte la signature locale (`Migrated=1` registry / `migrated` touch file).
3. Vérifier dans la VM :
   ```bash
   php artisan tinker --execute='
     echo \App\Auth\V1\Models\WorkstationMigrationAttempt::recent(1)->count();
   '
   ```
   Attendu : le compteur n'a pas augmenté significativement (juste les `started` du fragment serveur, mais aucun nouvel `enrolled`).

### Section 14 — Health check daily

#### Scénario 16.11-17 — `php artisan migration:health-check --days=7` sur table vide → OK

```bash
php artisan tinker --execute='\App\Auth\V1\Models\WorkstationMigrationAttempt::truncate();'
php artisan migration:health-check --days=7
# Attendu : output "[OK] No attempts in last 7 days" + exit 0
```

#### Scénario 16.11-18 — Seuil dépassé → log critical

```bash
php artisan tinker --execute='
  for ($i = 0; $i < 100; $i++) {
    \App\Auth\V1\Models\WorkstationMigrationAttempt::factory()->failed()->create();
  }
  \App\Auth\V1\Models\WorkstationMigrationAttempt::factory()->succeeded()->count(10)->create();
'

php artisan migration:health-check --threshold=0.05
# Attendu : output "[CRITICAL] Failure ratio 90.91% exceeds threshold 5.00%" + exit 0
tail -100 storage/logs/auth-v1/auth-v1-$(date +%F).log | grep "auth.migration.health.alert"
# Attendu : 1 entry CRITICAL avec context total/failed/ratio/threshold/days/top_errors
```

#### Scénario 16.11-19 — Schedule list mentionne migration:health-check daily

```bash
php artisan schedule:list | grep migration
# Attendu : "0 0 * * * migration:health-check ........... Next Due: ..."
```

### Section 15 — Non-régression

#### Scénario 16.11-20 — Re-jouer les scénarios 16.10-8 à 16.10-22

Après attachement du middleware `inject.bootstrap-fragment` aux 8 routes legacy, vérifier que :
- les tests Feature 16.3-16.7 restent verts (`./scripts/run-tests.sh --phase1-only`)
- les scénarios 16.10-8 à 16.10-22 (Section 2-7 ci-dessus) restent verts

Les requêtes qui n'incluent pas `uuid` dans la query → middleware no-op silencieux. Les requêtes avec uuid valide mais content-type non text/plain (associations_out.php JSON) → middleware no-op silencieux.

### Limitation Phase 2 — fenêtre MITM courte au bootstrap

**Statut** : limitation acceptée 2026-05-18 par Henri (Q3 post-review 16.11).
**Sortie prévue** : Phase 3 (pré-déploiement CA root via WPKG/GPO machine — cf. TD-16.11-MITM dans `docs/tech-debt-auth.md`).

#### Modèle de menace

Le fragment de migration injecté par `InjectBootstrapFragment` (Story 16.11) télécharge le script complet de bootstrap via `curl -k --insecure` (cf. `resources/views/auth/v1/bootstrap-fragment-{cmd,sh}.blade.php`). Le `-k` est nécessaire **par construction** car le CA root du serveur Sambaedu n'est **pas encore installé** côté poste au moment de cette première requête (chicken-and-egg : le bootstrap installe le CA, donc avant le bootstrap il n'est pas pinned).

Conséquences attaque LAN :

1. Un attaquant LAN (insider — élève sur Wi-Fi école, technicien malveillant, poste compromis) capable d'effectuer un **ARP spoof** ou de tenir un proxy transparent peut :
   - Intercepter la requête `curl -k https://se4fs-XXX/api/v1/agent/bootstrap.cmd` du poste.
   - Servir un script `bootstrap.cmd` malicieux qui installe **son propre CA root** dans le `Trusted Root` store machine du poste.
   - À partir de là, le poste fait confiance au CA de l'attaquant → MITM permanent sur toutes les communications HTTPS suivantes (`/enroll`, `/refresh`, `/ping`, futur `/api/v1/*`).

2. La fenêtre d'attaque est **courte** (durée du premier boot/logon post-déploiement Phase 2 d'un poste donné) mais **persistante** une fois exploitée (le CA root malicieux reste installé jusqu'à intervention manuelle).

#### Mitigations Phase 2

- **EnsureLanIp /24** : les endpoints `/bootstrap.{cmd,sh}` + `/enroll` sont restreints au LAN scolaire (RFC1918 par défaut, configurable via `AUTH_V1_BOOTSTRAP_ALLOWED_SUBNETS`). L'attaquant doit déjà être sur le LAN — pas d'attaque depuis Internet.
- **Observabilité** : tous les rejets bootstrap sont tracés dans `workstation_migration_attempts` (Q2 post-review). Un attaquant qui spam le LAN crée des rows traçables — `migration:health-check` daily alerte sur ratio failed > 5%.
- **Audit fingerprint post-migration** : voir scénario ci-dessous.

#### Scénario de détection post-migration — fingerprint CA root check

Pour détecter si un poste a installé un CA root **différent** du CA officiel du serveur (signe d'un MITM exploité au bootstrap), comparer le fingerprint :

**Côté serveur** — fingerprint du CA officiel :

```bash
# Sur la VM Sambaedu (/vm)
openssl x509 -in storage/keys/ca/ca.crt -noout -fingerprint -sha256
# Attendu : SHA256 Fingerprint=XX:XX:XX:...
```

**Côté poste Windows migré** — fingerprint du CA pinné dans le `Trusted Root` machine :

```powershell
# Sur le poste Windows
Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Subject -like '*SambaEdu*' } | Select-Object Subject, Thumbprint
# Le Thumbprint doit matcher la version SHA1/SHA256 du CA serveur.
```

**Côté poste Linux migré** — fingerprint du CA pinné :

```bash
# Sur le poste Linux
openssl x509 -in /usr/local/share/ca-certificates/sambaedu-ca.crt -noout -fingerprint -sha256
# Doit matcher EXACTEMENT la valeur côté serveur.
```

**Procédure d'audit en cas de doute** (campagne périodique, ou suite à alerte `migration:health-check`) :

1. Récupérer le fingerprint serveur (commande ci-dessus).
2. Pour chaque poste migré sensible (postes admin, postes salle examen), comparer son fingerprint via PowerShell remoting (Win) ou SSH (Linux).
3. Tout mismatch déclenche une procédure d'incident :
   - Re-déploiement complet du poste (réinstallation OS) — un CA root malicieux installé n'est pas révocable sans intervention système complète.
   - Forensique réseau LAN (ARP table, switch logs) pour identifier l'attaquant ayant servi le mauvais CA.

#### Pointeur Phase 3+

La sortie définitive de cette limitation est documentée dans `docs/tech-debt-auth.md` → `TD-16.11-MITM`. Elle consiste à pré-déployer le CA root **avant** le premier bootstrap (via WPKG machine ou GPO Computer Configuration), supprimant ainsi le besoin de `curl -k`.

## Checklist rapide 16.11

- [ ] Migrations `2026_05_18_120000` + `2026_05_18_120100` jouées sur la VM (`migrate:status`).
- [ ] `php artisan migration:health-check` retourne `[OK]` sur table fraîche.
- [ ] `php artisan schedule:list` mentionne `migration:health-check` daily.
- [ ] Endpoints `GET /api/v1/agent/bootstrap.{cmd,sh}` répondent 200 depuis LAN (HTTP ou HTTPS selon config Apache) — body conforme (`@echo off` / `#!/bin/bash`), 1 row `started` inséré par appel dans `workstation_migration_attempts`.
- [ ] `GET /api/v1/agent/bootstrap.cmd` hors LAN (override `AUTH_V1_BOOTSTRAP_ALLOWED_SUBNETS=8.8.8.8/32` + `config:clear` + reload php-fpm) → 403 + `code=bootstrap.not_lan`.
- [ ] PHPUnit `EnrollControllerTest::it_rejects_enroll_when_uuid_does_not_match_bootstrap_context` PASS (uuid mismatch → 401 `bootstrap_token.uuid_mismatch`).
- [ ] PHPUnit `EnrollControllerTest::it_rejects_enroll_from_non_lan_ip` PASS (403 `bootstrap.not_lan`).
- [ ] PHPUnit `InjectBootstrapFragmentIntegrationTest` → 7/7 PASS (cover non-migré Win + non-migré Linux + déjà-migré skip + JSON skip + 4xx skip + no-uuid skip + server_base_url).
- [ ] Smoke poste Windows réel (Section 13) : `Migrated=1` après reboot + tokens DPAPI HKLM stockés.
- [ ] Smoke poste Linux réel (Section 13) : `/var/lib/sambaedu/auth.json` 0600 + systemd timer actif.
- [ ] Logs `auth-v1` du jour contiennent au moins `auth.bootstrap.script.served` (info, depuis smoke `bootstrap.{cmd,sh}`) + `auth.bootstrap.lan_blocked` (warning, depuis smoke override LAN). Les events `auth.bootstrap.fragment.injected` + `auth.migration.success` apparaissent en prod sur les flots E2E réels (Section 13).

---

## Story 16.12 — Logs exécution centralisés

**Date livraison** : 2026-05-18 (implémentation)
**Migrations à appliquer** : `2026_05_19_120000_create_script_execution_logs_table.php`
**Permissions requises** : `server.admin` (pour l'UI Livewire `/admin/settings/scripts-logs/`)

### Section 16 — Endpoint POST /api/v1/script-execution-logs (happy path + auth)

#### Scénario 16.12-1 — POST avec JWT workstation valide → 201 + row + log info

```bash
TOKEN="<jwt workstation valide>"
CORR=$(uuidgen)
curl -k -i -X POST "https://$(hostname -f)/api/v1/script-execution-logs" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "script_source": "managed_script",
    "action": "logon",
    "os": "windows",
    "status": "success",
    "exit_code": 0,
    "stdout": "hello",
    "started_at": "'$(date -u +%Y-%m-%dT%H:%M:%SZ)'",
    "duration_ms": 1250,
    "correlation_id": "'$CORR'"
  }'
```

Attendu : HTTP 201 sans body, 1 row créée en DB, log info `scriptsos.ingest.success` dans `storage/logs/scriptsos/scriptsos-YYYY-MM-DD.log`.

#### Scénario 16.12-2 — POST sans header `Authorization` → 401 `jwt.missing`

```bash
curl -k -i -X POST "https://$(hostname -f)/api/v1/script-execution-logs" \
  -H "Content-Type: application/json" -d '{}'
```

Attendu : HTTP 401 + JSON `{error: "unauthorized", code: "jwt.missing", ...}`.

#### Scénario 16.12-3 — POST avec JWT `tier=controlhub` (mauvais tier) → 401 `jwt.wrong_tier`

```bash
TOKEN="<jwt avec claim tier=controlhub>"
curl -k -i -X POST "https://$(hostname -f)/api/v1/script-execution-logs" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{}'
```

Attendu : HTTP 401 + code `jwt.wrong_tier`.

#### Scénario 16.12-4 — POST avec JWT expiré → 401 `jwt.expired`

Émettre un JWT avec `exp < now`. Attendu : 401 code `jwt.expired`.

### Section 17 — Validation FormRequest

#### Scénario 16.12-5 — POST sans champ `action` → 422 + erreur `action: required`

Payload sans `action`. Attendu : HTTP 422 + JSON `{message, errors: {action: ["..."]}}`.

#### Scénario 16.12-6 — POST avec `status=foobar` (enum invalide) → 422

Payload `status=foobar`. Attendu : HTTP 422 + erreur sur `status`.

#### Scénario 16.12-7 — POST avec `started_at` futur > 5 min → 422 + code `started_at.future`

```bash
FUTURE=$(date -u -d '+1 hour' +%Y-%m-%dT%H:%M:%SZ)
curl -k -i ... -d "{..., \"started_at\": \"$FUTURE\", ...}"
```

Attendu : HTTP 422 + `errors.started_at` contient `started_at.future`.

#### Scénario 16.12-8 — POST avec `started_at` < 7 jours → 422 + code `started_at.too_old`

```bash
OLD=$(date -u -d '-10 days' +%Y-%m-%dT%H:%M:%SZ)
curl -k -i ... -d "{..., \"started_at\": \"$OLD\", ...}"
```

Attendu : HTTP 422 + `errors.started_at` contient `started_at.too_old`.

#### Scénario 16.12-9 — POST avec stdout 12 KB → 201 mais row.stdout_excerpt tronqué ≤ 8 KB

Payload `stdout = repeat('x', 12000)`. Attendu : 201, row.stdout_excerpt ≤ 8192 bytes, contient `[...truncated]`.

### Section 18 — Idempotence correlation_id

#### Scénario 16.12-10 — 2 POST consécutifs avec même correlation_id → 1 seule row + 201/201

```bash
CORR=$(uuidgen)
# 1er POST
curl -k -i -X POST ... -d "{..., \"correlation_id\": \"$CORR\", ...}"
# 2ème POST identique
curl -k -i -X POST ... -d "{..., \"correlation_id\": \"$CORR\", ...}"

# Vérification
psql -U sambaedu -d sambaedu -c "SELECT COUNT(*) FROM script_execution_logs WHERE correlation_id = '$CORR'"
```

Attendu : 2× HTTP 201, exactement 1 row en DB, log `scriptsos.ingest.idempotent_skip` au 2ème.

### Section 19 — UI Livewire /admin/settings/scripts-logs

#### Scénario 16.12-11 — GET `/admin/settings/scripts-logs` admin → 200 + bandeau + tableau

Connexion admin Henri → navigation. Attendu : 200, bandeau d'indicateurs visible (taux échec, top 5 postes/scripts), tableau paginé 50/page.

#### Scénario 16.12-12 — GET en non-admin → 403

Connexion user lambda (sans `server.admin`). Attendu : 403.

#### Scénario 16.12-13 — Filtrage `?filterStatus=failure` → seules les rows failure

```
https://$(hostname -f)/admin/settings/scripts-logs?filterStatus=failure
```

Attendu : seuls les logs `status=failure` affichés.

#### Scénario 16.12-14 — Filtrage `?filterWorkstationUuid=<uuid>` → seules les rows du poste

Attendu : seuls les logs du poste cible.

#### Scénario 16.12-15 — GET `/admin/settings/scripts-logs/{id}` valide → 200 + détail

Attendu : 200, métadonnées affichées + stdout/stderr `<pre>` + boutons "Copier".

#### Scénario 16.12-16 — GET `/admin/settings/scripts-logs/<inexistant>` → 404

Attendu : 404.

### Section 20 — Wrapper script renderer (consommable par 17.3)

#### Scénario 16.12-17 — Render wrapper Windows depuis tinker

```bash
php artisan tinker --execute='
  $renderer = app(\App\ScriptsOs\Services\WrapperScriptRenderer::class);
  echo $renderer->wrap(
    "echo hello",
    \App\ScriptsOs\Enums\ScriptExecutionAction::LOGON,
    \App\ScriptsOs\Enums\ScriptExecutionOs::WINDOWS
  );
'
```

Attendu : contient `Invoke-RestMethod`, un correlation_id UUID, l'URL absolue `/api/v1/script-execution-logs`, `certutil -decode`, `Bearer `, retry 3×.

#### Scénario 16.12-18 — Render wrapper Linux

Idem avec `ScriptExecutionOs::LINUX`. Attendu : contient `curl -fsS -X POST` (post review Q5 — sans `-k`, TLS strict Phase 2), `jq`, `base64 -d`, `/var/lib/sambaedu/auth.json`, retry `for i in 1 2 3`.

### Section 21 — Job artisan archivage

#### Scénario 16.12-19 — Archivage : 50 rows > 90j + 10 récentes → archive créée + 50 supprimées

```bash
# Seed
php artisan tinker --execute='
  App\ScriptsOs\Models\ScriptExecutionLog::factory()->state(["started_at" => now()->subDays(95)])->count(50)->create();
  App\ScriptsOs\Models\ScriptExecutionLog::factory()->recent(1)->count(10)->create();
'

php artisan script-logs:archive:rotate

ls -la storage/archives/
psql -U sambaedu -d sambaedu -c "SELECT COUNT(*) FROM script_execution_logs"
```

Attendu : 1 fichier `script-execution-logs-YYYY-MM.jsonl.gz`, 10 rows DB restantes, log info `scriptsos.archive.rotated`.

#### Scénario 16.12-20 — `php artisan schedule:list | grep script-logs`

Attendu : ligne mentionnant `script-logs:archive:rotate` à `04:00` (post review F1 Q1 — décalé de 03:30 pour éviter collision avec `printers:sync` 03:30 et `wpkg:reports:archive:rotate` 03:45).

### Section 22 — Smoke poste réel (post-VM up, action Henri)

#### Scénario 16.12-21 — Poste Windows migré exécute un wrapper → ligne dans `script_execution_logs`

Sur un poste Windows réel migré (16.11 OK), déclencher l'exécution d'un script user wrappé (Story 17.3 fournira). Attendu : 1 row insérée dans `script_execution_logs` avec status `success` et duration_ms cohérent.

#### Scénario 16.12-22 — Poste Linux migré idem

Sur un poste Linux réel migré, idem. Attendu : 1 row insérée.

### Section 23 — Non-régression

#### Scénario 16.12-23 — Re-jouer 16.11-1 à 16.11-20 → tous verts

Vérifier que l'ajout de `/api/v1/script-execution-logs` n'a pas impacté les routes 16.11 (auto-bootstrap, migration:health-check, fragment injection).

#### Scénario 16.12-24 — Re-jouer 16.10-1 à 16.10-24 → tous verts

Vérifier que l'ajout du nouveau bloc Route group `Route::prefix('v1')` n'a pas impacté les routes 16.10 (`/agent/enroll`, `/agent/refresh`, `/agent/ping`).

### Section 24 — Vérification TLS stricte Phase 2 (post-review Q5)

> Note post-review 2026-05-18 — décision Henri Q5 : retrait `-k` curl (Linux) + `-SkipCertificateCheck` PowerShell (Windows) dans le wrapper.

Le wrapper Windows (`Invoke-RestMethod`) **et** Linux (`curl --max-time 10 ...` sans `-k`) exigent désormais la **validation stricte de la chaîne CA SambaEdu**. Le CA root est installé sur chaque poste lors du bootstrap 16.11 :

- **Windows** : `certutil -addstore Root <ca.crt>` exécuté par le fragment bootstrap (Story 16.11 D4 + D11).
- **Linux** : copie dans `/usr/local/share/ca-certificates/sambaedu-ca.crt` puis `update-ca-certificates` (idem 16.11 D4 Linux).

#### Comportement attendu

Si un poste n'a pas le CA root SambaEdu :

- Windows : `Invoke-RestMethod` lève `WebException: Could not establish trust relationship` → le `catch` du retry-3x consomme l'erreur, après 3 tentatives le wrapper logue `[sambaedu-wrapper] POST failed after 3 attempts` dans `%TEMP%\sambaedu-wrapper-retry.log` et exit avec l'exit_code du script user (pas de bruit côté GPO).
- Linux : `curl --max-time 10` retourne exit code 60 (`SSL certificate problem`) → idem retry 3x puis log `/tmp/sambaedu-wrapper-retry.log`.

#### Pourquoi fail-closed plutôt qu'opportunistic TLS

Décision **fail-closed volontaire** pour empêcher tout MitM L2 sur le LAN scolaire (réseau partagé élèves + admin). Mieux vaut une panne silencieuse de la remontée logs qu'une compromission silencieuse via un attaquant interne qui injecte son CA dans la chaîne.

Si terrain remonte des postes hors-rotation sans CA root (postes oubliés du bootstrap, postes nouvellement rattachés avant 16.11) :

1. `tail -F storage/logs/scriptsos-*.log` côté serveur — on **ne verra rien** pour ce poste (POST ne passe pas).
2. Diagnostiquer le poste via `Get-ChildItem Cert:\LocalMachine\Root | Where-Object Subject -Match SambaEdu` (Windows) ou `awk -v cmd='openssl x509 -noout -subject' '/-----BEGIN/,/-----END/' /etc/ssl/certs/ca-certificates.crt | grep -i sambaedu` (Linux).
3. Re-trigger le bootstrap manuellement (cf. runbook 16.11 Section 14).

#### Mitigation Phase 3+

Cert pinning explicite (épingler le SHA256 du CA SambaEdu dans le wrapper) — différé Phase 3. La validation stricte CA est déjà supérieure à l'ancien comportement `-k` / `-SkipCertificateCheck`.

## Checklist rapide 16.12

- [ ] Migration `2026_05_19_120000` jouée sur la VM.
- [ ] Table `script_execution_logs` existe + indexes `sel_ws_started_idx`, `sel_status_started_idx`, `sel_ws_corr_unique`.
- [ ] `php artisan schedule:list` mentionne `script-logs:archive:rotate` à 04:00 (post review F1 Q1 — décalé de 03:30 pour éviter collision printers:sync).
- [ ] POST `/api/v1/script-execution-logs` avec JWT valide → 201 + row insérée.
- [ ] POST sans JWT → 401 `jwt.missing`.
- [ ] POST avec `status=invalid` → 422 standard Laravel `{message, errors}`.
- [ ] 2 POST avec même correlation_id → 1 seule row + log idempotent_skip.
- [ ] UI `/admin/settings/scripts-logs` accessible admin, 403 non-admin.
- [ ] Détail `/admin/settings/scripts-logs/{id}` → stdout/stderr en `<pre>` escape XSS strict.
- [ ] Job `script-logs:archive:rotate` archive en JSONL gzip mensuel + purge DB.
- [ ] Channel logs `scriptsos` reçoit `ingest.success`, `archive.rotated`, `wrapper.rendered`.
- [ ] Wrapper Windows : `Invoke-RestMethod` (sans `-SkipCertificateCheck` post Q5) + DPAPI lecture token + retry 3×.
- [ ] Wrapper Linux : `curl -fsS` (sans `-k` post Q5) + `jq` fallback `python3` + retry `for i in 1 2 3`.
- [ ] Vérification TLS stricte Phase 2 : `Invoke-RestMethod`/`curl` fail-closed si CA root SambaEdu absent du poste (cf. Section 24 post-review Q5).

## Story 16.13 — Exposition endpoints natifs `/api/v1/workstation-config/*`

> Append-only — 8 endpoints natifs réutilisant les controllers 4.7 / 4.8 / 16.3a/b/c / 16.7. Authentification JWT `auth.v1.workstation` (16.10), `workstation_uuid` extrait du claim `sub` (pattern iso 16.12 strict — jamais depuis query/body user-controlled). Les endpoints legacy `*_out.php` restent inchangés (transformés en `MigrationController` en 16.13bis).
>
> **Note post-review 2026-05-19 (Henri Q4)** : préfixe des routes mis à jour de `/api/v1/{...}` plat vers `/api/v1/workstation-config/{...}` pour désambiguïser avec ControlHub et matérialiser la nature « configuration poste » des endpoints. Les URLs ci-dessous reflètent le préfixe final.

### Pré-requis communs 16.13

- VM up + composer install à jour + `php artisan migrate` (les tables `workstations`, `workstation_groups`, `users` existent et sont seedées).
- JWT de test émis via tinker :
  ```bash
  php artisan tinker
  $issuer = app(\App\Auth\V1\Jwt\WorkstationJwtIssuer::class);
  $jwt = $issuer->issue('<uuid-poste-valide>', 'workstation');
  echo $jwt['access_token'];
  ```
- Au moins une `Workstation` en base avec `uuid` matchant le claim JWT `sub`.
- Pour les scénarios « parité legacy » : avoir un `id` md5 APCu encore valide (poste en cours de boot legacy — la session APCu expire en 1800s).

### Section 24 — Endpoints `/api/v1/workstation-config/*` happy path + auth

#### Scénario 16.13-1 — Happy path wallpaper

```bash
JWT=<token>
UUID=<uuid-poste>
curl -s -o /tmp/wp.png -w "%{http_code} %{content_type}\n" \
  -H "Authorization: Bearer $JWT" \
  "https://localhost/api/v1/workstation-config/wallpaper?action=wallpaper&os=linux&format=png"
file /tmp/wp.png
```

Attendu :
- HTTP `200`, Content-Type `image/png`
- `file /tmp/wp.png` → `PNG image data`
- Header `Cache-Control: no-store, no-cache, must-revalidate, private` (posé par middleware `auth.v1.secure-headers` 16.10)

#### Scénario 16.13-2 — Endpoint sans Authorization (jwt.missing)

```bash
curl -s -o /tmp/resp.json -w "%{http_code}\n" \
  "https://localhost/api/v1/workstation-config/firefox"
cat /tmp/resp.json
```

Attendu :
- HTTP `401`
- Body JSON `{"error":"unauthorized","message":"Missing Authorization header","code":"jwt.missing"}`

#### Scénario 16.13-3 — JWT expiré (jwt.expired)

Émettre un JWT avec `exp` passé (`-2 days`) via tinker :
```bash
php artisan tinker
$payload = [
    'iss' => 'sambaedu-test',
    'sub' => '<uuid>',
    'iat' => now()->subDays(2)->timestamp,
    'exp' => now()->subDay()->timestamp,
    'jti' => \Illuminate\Support\Str::uuid()->toString(),
    'tier' => 'workstation',
    'kid' => config('auth_v1.jwt.active_kid'),
];
$priv = file_get_contents(config('auth_v1.jwt.keys.'.config('auth_v1.jwt.active_kid').'.private'));
echo \Firebase\JWT\JWT::encode($payload, $priv, 'RS256', $payload['kid']);
```

Puis :
```bash
curl -s -H "Authorization: Bearer $JWT_EXPIRED" \
  "https://localhost/api/v1/workstation-config/network?action=startup&os=linux"
```

Attendu : HTTP `401` + `code: jwt.expired`.

#### Scénario 16.13-4 — JWT tier=controlhub (jwt.wrong_tier)

Émettre un JWT avec `tier=controlhub` puis :
```bash
curl -s -H "Authorization: Bearer $JWT_CTRLHUB" \
  "https://localhost/api/v1/workstation-config/veyon"
```

Attendu : HTTP `401` + `code: jwt.wrong_tier`.

#### Scénario 16.13-4bis — JWT révoqué (jwt.revoked) — AC2.5 (post-review F1 + Q1)

Émettre un JWT valide puis enregistrer son `jti` en
`workstation_jwt_revocations` (via tinker `\App\Auth\V1\Models\WorkstationJwtRevocation::create(...)`).

```bash
curl -s -H "Authorization: Bearer $JWT_REVOKED" \
  "https://localhost/api/v1/workstation-config/wallpaper"
```

Attendu : HTTP `401` + body JSON `{"code":"jwt.revoked", ...}` (le middleware
`auth.v1.workstation` 16.10 lit DB + cache APCu).

### Section 25 — Résolution serveur du contexte (workstation_uuid JWT)

#### Scénario 16.13-5 — `workstation_uuid` query string ignoré (preuve binaire forte)

> Post-review F5 + Opus-21 : preuve binaire forte. Le JWT signe UUID_A
> (poste seedé). On passe UUID_B **non seedé** en query. Si la query
> était lue → 404. Si le JWT prime → 200 image.

```bash
# JWT signe sub=UUID_A (poste seedé en DB)
JWT=<token-pour-UUID_A>
# UUID_B inexistant en DB
UUID_B=99999999-9999-4999-9999-999999999999
curl -s -H "Authorization: Bearer $JWT" \
  "https://localhost/api/v1/workstation-config/wallpaper?workstation_uuid=$UUID_B&action=wallpaper&os=linux&format=jpg" \
  -o /tmp/wallpaper-jwt-vs-query.jpg -w "%{http_code} %{content_type}\n"
file /tmp/wallpaper-jwt-vs-query.jpg
```

Attendu :
- HTTP `200`, Content-Type `image/jpeg`
- `file` → `JPEG image data` (preuve forte : UUID_A résolu via JWT, UUID_B query ignoré)

#### Scénario 16.13-6 — UUID JWT inconnu en DB → 404 + log warning

```bash
JWT_UNKNOWN=<token-pour-uuid-inexistant>
curl -s -H "Authorization: Bearer $JWT_UNKNOWN" \
  -o /tmp/notfound.json -w "%{http_code} %{content_type}\n" \
  "https://localhost/api/v1/workstation-config/wallpaper?action=wallpaper&os=linux"
cat /tmp/notfound.json
```

Attendu :
- HTTP `404`, Content-Type `application/json`
- Body JSON `{"error":"workstation_not_found"}` (format unifié post-review Henri Q2)
- `tail storage/logs/auth-v1/*.log` contient `agent.v1.config.workstation_not_found` + `workstation_uuid_prefix=<8-chars>`

### Section 26 — Parité iso-fonctionnelle legacy vs natif

#### Scénario 16.13-7 — `/api/v1/workstation-config/wallpaper` vs `/sambaedu/gpo/wallpaper_out.php`

Pour un poste en cours de boot legacy (id md5 APCu encore valide) :
```bash
ID_MD5=<md5-valide>
curl -s "https://localhost/sambaedu/gpo/wallpaper_out.php?action=wallpaper&id=$ID_MD5&format=png" -o /tmp/wp-legacy.png
curl -s -H "Authorization: Bearer $JWT" \
  "https://localhost/api/v1/workstation-config/wallpaper?action=wallpaper&os=linux&format=png" -o /tmp/wp-native.png

file /tmp/wp-legacy.png /tmp/wp-native.png
ls -l /tmp/wp-legacy.png /tmp/wp-native.png
```

Attendu :
- Les 2 fichiers sont des `PNG image data`
- Tailles **équivalentes** (variations admises si user/salle diffèrent — sinon strictement égales)
- Les 2 endpoints retournent Content-Type `image/png` + Cache-Control `no-store`

#### Scénario 16.13-8 — `/api/v1/workstation-config/network` vs `/sambaedu/gpo/network_out.php`

```bash
curl -s "https://localhost/sambaedu/gpo/network_out.php?action=startup&id=$ID_MD5&os=linux" -o /tmp/net-legacy.sh
curl -s -H "Authorization: Bearer $JWT" \
  "https://localhost/api/v1/workstation-config/network?action=startup&os=linux&user=jdoe" -o /tmp/net-native.sh
diff /tmp/net-legacy.sh /tmp/net-native.sh
```

Attendu :
- Les 2 scripts sont des `text/plain` bash
- Diff vide ou différences cosmétiques uniquement (timestamps de generation)

### Section 27 — Non-régression legacy et 16.10/16.11/16.12

#### Scénario 16.13-9 — Fragment injection sur `wallpaper_out` (non-régression 16.11-3/4)

Re-jouer scénarios 16.11-7 (Windows) et 16.11-8 (Linux) :
- Poste non-migré hit `gpo/wallpaper_out.php` → fragment cmd/sh injecté en préfixe
- Poste déjà migré hit `gpo/wallpaper_out.php` → pas de fragment

Attendu : comportement inchangé par la story 16.13.

#### Scénario 16.13-10 — `/api/v1/script-execution-logs` (non-régression 16.12-1)

```bash
curl -s -H "Authorization: Bearer $JWT" -H "Content-Type: application/json" \
  -X POST -d '{"script_name":"test","status":"success","started_at":"2026-05-19T10:00:00Z","exit_code":0,"workstation_uuid":"<uuid>"}' \
  "https://localhost/api/v1/script-execution-logs"
```

Attendu : HTTP `201` + idempotence via `correlation_id` (relance = `201` quand-même iso 16.12).

#### Scénario 16.13-11 — `/api/v1/agent/ping` (non-régression 16.10-5/6)

```bash
curl -s -H "Authorization: Bearer $JWT" "https://localhost/api/v1/agent/ping"
```

Attendu : HTTP `200` + body JSON `{success: true, workstation_uuid, api_version: "v1", ...}`.

### Section 28 — Smoke route registry

#### Scénario 16.13-12 — `php artisan route:list | grep "workstation-config"`

```bash
php artisan route:list | grep "workstation-config"
```

Attendu (les 8 nouvelles routes 16.13 sous le nouveau préfixe Q4) :
```
GET      api/v1/workstation-config/wallpaper                ............... agent.v1.config.wallpaper            › WallpaperController@apiV1
GET      api/v1/workstation-config/firefox                  ............... agent.v1.config.firefox              › AppPolicyController@apiV1Firefox
GET      api/v1/workstation-config/thunderbird              ............... agent.v1.config.thunderbird          › AppPolicyController@apiV1Thunderbird
GET      api/v1/workstation-config/shortcuts                ............... agent.v1.config.shortcuts            › Api\v1\ShortcutExportController@apiV1
GET      api/v1/workstation-config/network                  ............... agent.v1.config.network              › Gpo\NetworkOutController@apiV1
GET      api/v1/workstation-config/veyon                    ............... agent.v1.config.veyon                › Gpo\VeyonOutController@apiV1
GET|POST api/v1/workstation-config/associations             ............... agent.v1.config.associations         › Gpo\AssociationsOutController@apiV1
GET      api/v1/workstation-config/applications-scripts     ............... agent.v1.config.applications-scripts › Gpo\ApplicationsScriptsController@apiV1
```

+ 4 routes 16.10/16.11 sous `agent.v1.*` (enroll, refresh, ping, bootstrap.cmd, bootstrap.sh) + 1 route 16.12 (`scriptsos.logs.ingest`).

Vérification anti-régression Q4 : aucune route plate ne doit subsister :
```bash
php artisan route:list | grep -E "api/v1/(wallpaper|firefox|thunderbird|shortcuts|network|veyon|associations|applications-scripts)$"
```
Attendu : `0` résultat.

## Checklist rapide 16.13

- [ ] `php artisan route:list | grep "workstation-config"` affiche les 8 routes nommées `agent.v1.config.*` sous `/api/v1/workstation-config/`.
- [ ] `curl https://localhost/api/v1/workstation-config/wallpaper` (sans Auth) → `401` + `code: jwt.missing`.
- [ ] `curl -H "Authorization: Bearer <JWT_EXPIRED>" /api/v1/workstation-config/network` → `401` + `code: jwt.expired`.
- [ ] `curl -H "Authorization: Bearer <JWT_tier=controlhub>" /api/v1/workstation-config/veyon` → `401` + `code: jwt.wrong_tier`.
- [ ] `curl -H "Authorization: Bearer <JWT_REVOKED>" /api/v1/workstation-config/wallpaper` → `401` + `code: jwt.revoked` (post-review F1/Q1).
- [ ] `curl -H "Authorization: Bearer <JWT_UNKNOWN_UUID>" /api/v1/workstation-config/wallpaper?action=wallpaper` → `404` + body JSON `{"error":"workstation_not_found"}` (post-review Q2).
- [ ] Happy path JWT valide + Workstation seedée → `200` + Content-Type attendu (jpeg/png/json/text/plain selon endpoint).
- [ ] `workstation_uuid` query string ignoré (post-review F5 : UUID_A seedé via JWT + UUID_B inexistant en query → 200 image, preuve binaire forte).
- [ ] Legacy `*_out.php` répondent toujours (non-régression 16.10/16.11 — re-jouer 16.10-22 et 16.11-7/8).
- [ ] `/api/v1/agent/ping` répond `200` (non-régression 16.10).
- [ ] `/api/v1/script-execution-logs` répond `201` (non-régression 16.12).
- [ ] `tail storage/logs/auth-v1/*.log` mentionne `agent.v1.config.workstation_not_found` aux 404 (observabilité D5).
- [ ] `grep -rn "input('workstation_uuid'\|query('workstation_uuid'" app/Http/Controllers app/Gpo` → 0 résultat (AC2.6).

## Post-correctifs 2026-05-19 (review code-review 16.13)

| Item | Description | Statut |
|---|---|---|
| F1 + Q1 | Test JWT révoqué AC2.5 explicite | Corrigé — `ApiV1ConfigSecurityTest::revoked_jwt_returns_401_revoked` |
| F2 | Double lookup `AppPolicyController::resolveNative` | Corrigé — unique appel `resolver->resolve()` puis `resolveAppPolicyScope` |
| F3 | Commentaire trompeur `composeWallpaperResponse` | Corrigé — PHPDoc clarifié |
| F4 | `$resolver` inutilisé `ShortcutExportController::apiV1` | Corrigé — lookup via resolver, import `Workstation` supprimé |
| F5 + Opus-21 | Test `workstation_uuid_query_is_ignored` preuve faible | Corrigé — refactor wallpaper UUID_A seedé vs UUID_B inexistant |
| F6 | Couverture repository fail-fast 2/6 endpoints | Corrigé — 6 endpoints + 3 mocks fail-fast |
| F7 + Q2 | Format 404 incohérent (text/plain vs JSON) | Corrigé — JSON unifié `{"error":"workstation_not_found"}` sur 7 controllers |
| F8 | Tests Wallpaper skip global Imagick | Corrigé — check déporté dans 3 tests |
| Q4 | Préfixe routes `/api/v1/workstation-config/*` | Corrigé — 8 routes + 9 tests + archi + runbook QA |
| Opus-11 | Cache-Control redondant controllers | Corrigé — header délégué au middleware `auth.v1.secure-headers` |
| Opus-14 | Garde-fou SQLite resolver test | Corrigé — `markTestSkipped` si non-sqlite |
| Q3 | Validation regex inputs query | **Différé** — story hardening dédiée post-16.13 |

