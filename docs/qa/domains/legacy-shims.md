# QA Manuel — Shims & tombstones du canal legacy

Runbook stable (append-only). Couvre les réponses natives servies à la place du
code legacy PHP : catchall (proxy/404/410), conventions `gone:`/`noop:`, et les
**tombstones natifs du canal client legacy** (story 38.2).

---

## Story 38.2 — Tombstones natifs du canal client legacy (2026-07-10)

### Contexte

Chaque route encore appelée par un poste SE4 (crochet logon cmd/bash, démon iPXE,
machine à états d'install) reçoit une réponse **native, terminale, typée et
inerte** — jamais du HTML d'erreur sur un endpoint dont le corps est EXÉCUTÉ côté
poste. Les 18 routes tombstones sont déclarées AVANT le catchall `{path}` dans
`routes/web.php` (`LegacyTombstoneController`), sous `local.request` +
`throttle:300,1` + `withoutMiddleware(['web'])`, sans aucun middleware d'auth.

Le kill-switch `LEGACY_CONFIG_CHANNEL_ENABLED` / middleware
`EnsureLegacyConfigChannelEnabled` a été RETIRÉ par cette story (la sémantique 410
est remplacée par les tombstones). Les endpoints WPKG `linux_out`/`winget_out`
restent natifs, protégés `local.request` + `throttle`, **fonctionnellement
inchangés**.

### Pré-requis VM (ops manuelles, avec Henri — JAMAIS depuis un worktree)

1. Boucle inotify active + `php artisan migrate:status` ; `php artisan migrate`
   (migration additive `legacy_catchall_logs` : `source`/`machine`/`user_login`).
2. Retrait de `LEGACY_CONFIG_CHANNEL_ENABLED=false` du `.env` VM (il y est ACTIF —
   les 2 endpoints wpkg y répondent 410 aujourd'hui). Puis `php artisan config:cache`
   + `php artisan route:cache` + `chown www-admin bootstrap/cache/*`.
3. Retrait de la surcharge diagnostique
   `/etc/sambaedu/applications/firefox/logon.windows` (artefact incident « ffdiag
   v2 ») — suppression à la main via `trash`/`gio trash`, **jamais `rm -rf`**
   (inotify ne sync pas les deletes).

### Scénario 38.2-1 — Tombstones typés par famille (curls e2e depuis le LAN)

Depuis une IP LAN allowlistée (sinon `local.request` → 403) :

| Requête | Attendu |
|---|---|
| `GET /gpo/applications.php` | 200, `text/plain`, corps `REM …\r\n` |
| `POST /gpo/applications.php` (multipart, sans `_token`) | 200 `REM …` (CSRF court-circuité) |
| `GET /gpo/applications.php?ret=0&action=logon&context=system` | 200 `REM …` (inerte à TOUT) |
| `GET /gpo/shortcuts_out.php?action=file` | **204** No Content |
| `GET /gpo/shortcuts_out.php?action=icon` | **204** |
| `GET /gpo/shortcuts_out.php?action=logon` | 200 `REM …` |
| `GET /gpo/wallpaper_out.php` | **204** |
| `GET /gpo/veyon_out.php` | 200 `application/json`, corps `{}` |
| `GET /gpo/{associations,firefox,thunderbird}_out.php` | 200 `{}` `application/json` |
| `GET /gpo/no_internet_out.php` | 200 `REM …` `text/plain` |
| `GET /partages/cloud_out.php` | 200 `REM …` |
| `GET /wpkg/hosts_xml_out.php` | 200 `text/xml`, `<?xml …?>` + `<wpkg/>` |
| `GET /wpkg/profiles_xml_out.php` | 200, racine `<profiles/>` |
| `GET /wpkg/packages_xml_out.php` | 200, racine `<packages/>` |
| `GET /wpkg/wpkg_log.php` | 200, corps VIDE, `text/plain` |
| `GET /wpkg/download_prefix.php` | 200 `REM …` |
| `GET /ipxe/linux/action.php` | 200 `# …\n` (bash STRICT, jamais `exit`) |
| `GET /ipxe/Win10/action.php` | 200 `REM …\r\n` (cmd) |
| `GET /ipxe/Win10/sysprep.xml.php` | 200 `<?xml …?><unattend/>` |
| `GET /ipxe/Win10/unattend.xml.php` | 200 `<unattend xmlns="urn:schemas-microsoft-com:unattend"/>` |

**Sécurité** : aucun corps ne doit réfléchir un paramètre de la requête (corps
CALLé/eval'é = injection). Vérifier qu'un `?action=<sentinelle>` n'apparaît PAS
dans la réponse.

### Scénario 38.2-2 — Exception bornée canal Linux (passthrough)

1. `GET /gpo/applications.php?os=linux` → **PASSTHROUGH** vers le catchall (proxy
   vhost legacy) — comportement fonctionnel préservé. Le hit est loggé par le
   catchall (`source != 'tombstone'`), PAS comme tombstone.
2. `gpo/network_out.php` et `printers/out_printers.php` n'ont AUCUNE route native
   — elles atteignent le catchall (proxy + mesure).
3. **Critère de sortie de l'exception** : extinction mesurée (zéro hit `os=linux` /
   `network_out` / `out_printers` sur la période d'observation 38.6) OU livraison
   de l'agent Linux (post-MVP).

### Scénario 38.2-3 — Observabilité (critère GO 38.6)

1. Après quelques curls, ouvrir `/admin/legacy-monitor` : la colonne « Origine »
   distingue `tombstone` (badge vert) de `catchall` (badge rouge). Le filtre
   « origines » restreint sur `source`.
2. Le critère GO 38.6 se lit : **zéro hit `source='catchall'` sur les routes
   clients**, hits `tombstone` en décroissance.
3. Les colonnes `machine`/`user_login` de `legacy_catchall_logs` sont renseignées
   pour les hits tombstone (extraites de `machine`/`poste` et `user`).

### Scénario 38.2-4 — Kill-switch retiré, wpkg natifs vivants

1. `grep -rn 'LEGACY_CONFIG_CHANNEL' app config routes tests .env.example` → ZÉRO.
2. `GET /wpkg/linux_out.php?id=<md5>` (IP LAN) → 200 (liste APT) sans aucun gate
   410. `GET /wpkg/winget_out.php` → décision JSON (si `winget_enabled`).
3. Les routes tombstones et wpkg restent AVANT le catchall (`php artisan route:list
   | grep -E 'legacy.tombstone|wpkg.(linux|winget)-out'`).

### Non-régression automatisée (HÔTE)

```
php artisan test \
  tests/Architecture/LegacyTombstoneRoutesTest.php \
  tests/Feature/Legacy/LegacyTombstoneEndpointsTest.php \
  tests/Feature/LegacyCatchallTest.php \
  tests/Architecture/WpkgOutRoutesTest.php \
  tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php \
  tests/Architecture/IpxeNamespaceTest.php \
  tests/Feature/Wpkg/Deployment/Http/
```

Attendu : tous verts (33 tests 38.2 + 110 non-régression au moment de la livraison).
