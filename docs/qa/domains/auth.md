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

> ⚠️ **Limitation Phase 2 — `workstation:revoke` est `refresh-only`**
>
> La commande `php artisan workstation:revoke {uuid}` révoque tous les **refresh tokens**
> actifs du poste, mais **n'invalide pas les access tokens JWT en cours d'usage**
> (les `jti` des access tokens ne sont pas tracés en DB — seuls les `jti` explicitement
> mis en blacklist par cette commande le sont, et le marker inséré ici est synthétique).
>
> **Conséquence opérationnelle** : après `workstation:revoke`, un poste compromis avec un
> access token déjà émis peut continuer d'appeler `/api/v1/agent/*` jusqu'à expiration
> de ce token (TTL configuré dans `auth_v1.jwt.access_ttl`, défaut 24h).
>
> **Pour une révocation totale immédiate** (cas vol de poste, compromission CA root,
> incident critique), 3 options par ordre de préférence :
>
> 1. **Couper l'accès réseau du poste** (DHCP lease revoke, switch port disable, firewall
>    rule). Effet immédiat, scope ciblé sur le poste compromis.
> 2. **Régénérer la paire JWT** avec `php artisan auth:ca:init --force` puis reload
>    serveur : invalide **tous** les access tokens de **tous** les postes d'un coup
>    (kid disparaît de la keymap → 401 `jwt.signature_invalid`). Les postes
>    légitimes devront re-bootstrap. Effet immédiat, scope global → option de catastrophe.
> 3. **Attendre l'expiration naturelle** (≤ access_ttl). Acceptable si la compromission
>    est mineure et le risque de fuite limité.
>
> Le retrait définitif du shim bootstrap md5 + traçage `jti` access en DB (Phase 3+)
> permettra une révocation ciblée immédiate du poste — pas en Phase 2.

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

*(vide pour l'instant — sera enrichie après chaque code review post-merge)*

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
