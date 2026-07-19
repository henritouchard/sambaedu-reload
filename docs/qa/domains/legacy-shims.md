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
5. **Échec partiel (review 38.5 #1)** : le cron système + le retrait sont joués EN
   TÊTE de `main()` (avant composer). Si `update.sh` sort néanmoins en erreur
   pendant une install/finalisation, rejouer manuellement `bash scripts/update.sh`
   AVANT de considérer l'install terminée — vérifier ensuite le point 1.

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

## Story 38.6 — Extinction à blanc, observation, suppression définitive (commandes `se4:*`) (2026-07-18)

### Contexte

L'extinction du legacy est pilotée par 4 commandes artisan versionnées, rejouables à
l'identique sur chaque instance (dev /vm, lab1, étabs) — jamais de procédure SSH
manuelle. Toutes se lancent en **CLI root** sur l'instance (`a2dissite`/`systemctl`/
`mv` exigent root ; `se4:status` seule est en lecture et s'en passe).

| Commande | Rôle |
|---|---|
| `php artisan se4:status [--days=7]` | État bascule (vhost `sambaedu-legacy`, dossiers legacy/`.off`) + agrégation `legacy_catchall_logs` sur la fenêtre + verdict **GO/NO-GO**. Exit 0 = GO. |
| `php artisan se4:unplug [--days=7] [--force]` | Extinction à blanc : préflight (NO-GO → refus sauf `--force`), puis `a2dissite sambaedu-legacy` → `systemctl reload apache2` → `mv /var/www/sambaedu → .off`. Idempotente. |
| `php artisan se4:replug` | Rollback en une commande (mv inverse + `a2ensite` + reload). Idempotente. |
| `php artisan se4:purge --confirm` | Post-GO uniquement : `trash`/`gio trash` de `.off` (JAMAIS `rm -rf` ; abort si aucun utilitaire). Refuse si l'extinction n'est pas en place. |

Effets attendus de l'extinction à blanc : le catchall répond **404 loggé**
(`LEGACY_LOG_404`, D4 story 38.1) pour tout path legacy ; les **tombstones sont
inchangés** (routes natives avant le catchall) ; l'observabilité
`legacy_catchall_logs` + `/admin/settings/migration?tab=legacy-monitor` reste
fonctionnelle sans le FS legacy.

Critère GO = zéro hit legacy non-tombstone sur la fenêtre : seuls comptent les hits
`source` non-tombstone sur un endpoint `.php` **sous un répertoire racine du canal
legacy** (allowlist `LEGACY_CHANNEL_DIRS` du concern : `gpo/`, `wpkg/`, `partages/`,
`annu/`, `parcs/`, etc.). Les hits `source='tombstone'`, le bruit de navigation SE5
(404 sans `.php`) et les sondes de scanners (`/wp-login.php`, `phpmyadmin/…`) ne
bloquent pas — ils restent listés dans le rapport pour contrôle humain.

Notes de robustesse (review 38.6) : le `systemctl reload apache2` est
**inconditionnel** — relancer `se4:unplug`/`se4:replug` après un échec mi-séquence
converge toujours (y compris la branche « rien à faire », qui recharge par sûreté) ;
`a2query` absent (exit 127) → abort explicite, jamais un « vhost inactif » silencieux ;
`sambaedu.legacy_path` vide ou non absolu → abort.

### Séquence type par instance

1. `se4:status` — vérifier le NO-GO résiduel ; traiter chaque hit legacy (fix/story).
   Le rapport inclut les **checks pré-GO** : migrations en attente, scorie `.env`
   `LEGACY_CONFIG_CHANNEL_ENABLED`, et neutralisation de la GPO de domaine
   « applications » pour ce collège (`LegacyGpoNeutralizationInspector`, lecture
   seule AD).
2. GPO « applications » : elle est hébergée AU-DESSUS de l'ensemble des collèges —
   ne JAMAIS la vider/délier/supprimer (les collèges encore en SE4 la consomment).
   Neutralisation = **blocage d'héritage côté collège** (`gPOptions=1` sur l'OU des
   postes, cf. dev `OU=computers` et lab1 `OU=0991229y,OU=computers`). Si le check
   la dit encore appliquée : poser le blocage sur l'OU des postes, rien d'autre.
3. `se4:unplug` — extinction à blanc (le préflight refuse si hits récents OU si la
   GPO « applications » s'applique encore, sauf `--force` ; retire lui-même la
   scorie `.env` + config recache).
4. E2e parc : boot PXE, install Windows native, logon avec agent, WPKG natif,
   Guacamole, GPO bootstrap (runbooks des domaines concernés, `docs/qa/README.md`).
5. Observation **N=7 jours** : `se4:status --days=7` en fin de fenêtre ; tout hit
   inattendu → traité, fenêtre relancée.
6. GO → `se4:purge --confirm`.

### Scénario 38.6-1 — Aller-retour extinction/rollback

1. Sur l'instance (root) : `php artisan se4:unplug` → vhost désactivé, FS déplacé
   en `.off`, sortie mentionnant `se4:replug`.
2. `curl -I http://<instance>/gpo/wallpaper_out.php` → réponse tombstone inchangée
   (204) ; `curl -I http://<instance>/annu/sync_cron.php` → 404 (loggé).
3. `php artisan se4:replug` → FS restauré, vhost réactivé, Apache rechargé.
4. Relancer `se4:unplug` pour laisser l'extinction en place.
5. Rejouer `se4:unplug` une seconde fois → « rien à faire », exit 0 (idempotence).

### Scénario 38.6-2 — Préflight garde-fou (lab1 / exception Linux Q4)

Sur une instance dont le canal Linux est encore vivant (lab1 : `applications.php
os=linux`, `gpo/network_out.php`, `printers/out_printers.php` — poste réel
172.20.1.101) :

1. `php artisan se4:unplug` → **refus NO-GO** avec le rapport des hits.
2. Ne **PAS** utiliser `--force` sur lab1 tant que l'exception Q4 n'est pas soldée
   (extinction mesurée du poste Linux ou agent Linux post-MVP).

### Scénario 38.6-3 — Purge refusée hors conditions

1. `se4:purge` sans `--confirm` → refus.
2. `se4:purge --confirm` avec le legacy encore branché → refus (« extinction à
   blanc n'est pas en place »).
3. Conditions réunies (unplugged + GO) → `.off` part à la corbeille, jamais rm -rf.

### Non-régression automatisée (HÔTE)

```
vendor/bin/phpunit tests/Feature/Console/Se4ExtinctionCommandsTest.php \
  tests/Feature/LegacyMonitorDashboardTest.php \
  tests/Feature/LegacyCatchallTest.php \
  tests/Feature/Legacy/LegacyTombstoneEndpointsTest.php
```

Attendu : verts (21 tests commandes + 42 non-régression au 2026-07-18).
