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

