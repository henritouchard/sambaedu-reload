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

Attendu : tous verts (29 tests 38.2 — 6 architecture + 23 endpoints — + 110 non-régression au moment de la livraison ; DualModeCoexistenceTest adapté : URIs tombstone présentes mais nommées legacy.tombstone.*).

---

## Story 38.5 — Débranchement des crons et embed legacy (2026-07-10)

### Contexte

Les crons legacy (`/etc/cron.d/sambaedu-{web-common,shares,wpkg}`) étaient la
MAJORITÉ du trafic vers le web legacy PHP (`action_cron_php.sh` = `curl -F se4_key…
http://<name>/<page>.php`). Story 38.5 les débranche à sec :

- Retrait idempotent par `ensure_legacy_crons_retired()` dans `update.sh` (liste
  EXPLICITE des 3 fichiers, `mv` vers `/var/backups/sambaedu-legacy-crons/`,
  JAMAIS un glob `sambaedu-*`, JAMAIS `rm -rf`). Rejoué à chaque update (couvre une
  réapparition par conffile `apt reinstall`).
- Lignes vitales RE-POSSÉDÉES AVANT le retrait : `sambaedu-system.cron`
  (`renew_ticket.sh` ×2 dont `@reboot` + `smbstatus.sh`) provisionné par
  `install.sh`/`update.sh` — zéro fenêtre sans ticket Kerberos www-sambaedu.
- `sambaedu-boot-server` (`make_dhcpd_conf.sh`) **NON TOUCHÉ** (gating Story 8.3) ;
  `sambaedu-scheduler` intouchable (c'est SE5).
- Embed legacy débranché sec : route `users.groups.legacy-new` +
  `LegacyEmbedController` + `LegacyEmbedService` SUPPRIMÉS (création de groupe
  native livrée). Vue `legacy-embed.blade.php` CONSERVÉE (catchall `:280,:442`).

### Pré-requis VM (ops manuelles, avec Henri — JAMAIS depuis un worktree)

1. **Fantômes PHP** (`project_inotify_no_delete_sync`) : `LegacyEmbedController.php`
   et `LegacyEmbedService.php` survivent sur la VM (inotify ne sync pas les
   deletes). Les retirer À LA MAIN (`gio trash` / `mv`), puis
   `composer dump-autoload` — sinon l'autoload peut encore les résoudre.
2. `bash scripts/update.sh` sur /vm (LE vecteur du retrait des crons).
3. Route retirée ⇒ `php artisan route:cache && php artisan config:cache` + chown
   www-admin des caches (`project_route_cache_vm_ephemeral_test_routes`,
   `project_vm_config_cache_not_synced`).

### Scénario 38.5-1 — Crons débranchés, lignes vitales survivantes

1. `ls /etc/cron.d/` → il reste EXACTEMENT `sambaedu-{scheduler,system,boot-server}`
   (plus de `-web-common`, `-shares`, `-wpkg`).
2. `ls /var/backups/sambaedu-legacy-crons/` → contient les 3 fichiers retirés
   (réversibles).
3. `cat /etc/cron.d/sambaedu-system` → 3 lignes actives (`renew_ticket` ×2 dont
   `@reboot`, `smbstatus`).
4. Rejouer `bash scripts/update.sh` → idempotent (« Aucun cron legacy présent » +
   « Cron système déjà à jour »).

### Scénario 38.5-2 — Fonctions vitales toujours en vie (H+1)

1. `sudo -u www-admin klist` → ticket `www-sambaedu` frais (renew_ticket vit).
2. `stat -c %Y /tmp/smbstatus` → mtime < 1h (smbstatus vit).
3. Page sessions/wallpapers SE5 OK (consomme `/tmp/smbstatus`).

### Scénario 38.5-3 — Embed débranché

1. `php artisan route:list | grep legacy-new` → ZÉRO.
2. `GET /users/groups/legacy-new` → retombe dans le catchall (proxy legacy tant que
   le legacy vit, 404 loggé après extinction 38.6). Acceptable : personne n'y est lié.
3. Création de groupe native (modale `group-form-modal` sur `/users`) fonctionnelle.

### Scénario 38.5-4 — Vérif AC1 finale (critère GO 38.6)

Observer les access-logs Apache (vhost SE5 + legacy) sur ≥ 1h : plus AUCUN
`POST *.php` provenant des crons. Ne doit rester que `make_dhcpd_conf.sh` →
`script_make_reservations.php`/`dnsupdate.php` toutes les 5 min (résiduel gated 8.3)
et le trafic postes → tombstones 38.2. La ligne de base `legacy_catchall_logs` chute
massivement — c'est ATTENDU (instrument de mesure du GO 38.6, D3), le DOCUMENTER.

### Non-régression automatisée (HÔTE)

```
php artisan test \
  tests/Architecture/LegacyCronRetirementTest.php \
  tests/Feature/LegacyEmbedRouteRemovedTest.php \
  tests/Architecture/GpoLegacyIsolationTest.php \
  tests/Feature/LegacyCatchallTest.php \
  tests/Architecture/LegacyTombstoneRoutesTest.php
```

Attendu : verts. `LegacyModuleBbbTest` est skippé (pré-existant, hors 38.5).
