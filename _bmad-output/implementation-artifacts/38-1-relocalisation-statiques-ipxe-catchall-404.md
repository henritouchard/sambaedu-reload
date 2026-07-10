# Story 38.1: Relocalisation des statiques iPXE (+ racine TFTP) + catchall 404 si legacy absent

Status: review

<!-- Créée le 2026-07-10 (create-story, Epic 38 — epics-extinction-se4.md).
     Inspection VM (lecture seule) du 2026-07-10 consignée en Dev Notes. -->

## Story

En tant qu'exploitant,
je veux que plus rien de ce que sert Apache ne vive dans `/var/www/sambaedu`,
afin que la suppression du repo legacy ne touche ni le netboot ni le routing web.

**Périmètre.** Deux volets indépendants entre eux mais liés au même objectif (rendre
`/var/www/sambaedu` supprimable) :
1. **Relocalisation des statiques iPXE** servis par l'alias Apache `/ipxe` (qui pointe
   aujourd'hui sur `/var/www/sambaedu/ipxe`) : versionnement dans le repo SE5 +
   provisioning `update.sh` (patron `ensure_*`) + repointage de l'alias dans
   `scripts/setupApache.sh`. Vérification de la racine TFTP (constat VM : **déjà saine**,
   cf. Dev Notes — à re-vérifier et couvrir par l'e2e, pas à repointer).
2. **Catchall dégradant proprement (D4)** : `LegacyCatchallController::handle` ne doit
   plus jamais répondre `abort(500)` quand `legacy_path` est absent du disque — réponse
   404, toujours loggée (`LEGACY_LOG_404`), pour que le monitoring d'extinction
   (`legacy_catchall_logs`) reste fonctionnel sans le FS legacy.

## Acceptance Criteria

1. **Statiques versionnés dans le repo SE5.** Les fichiers de `/var/www/sambaedu/ipxe`
   (inventaire VM exact, cf. Dev Notes § Inventaire) sont versionnés sous
   `resources/ipxe/static/` :
   - `boot.ipxe` (2 876 o — menu iPXE d'installation des *serveurs* SE4, texte)
   - `diconf/authorized_keys` (564 o), `diconf/install_se4_from0.sh` (19 217 o)
   - `png/ipxe-se4.png` (93 815 o)
   - `undionly.kpxe` (74 994 o, md5 `49e53c73677941fd8d4f5e634fc4220f`) et
     `snponly_x64.efi` (216 064 o, md5 `3c745bf0c61d72f5e7326a271e34cae4`) — **copies
     réelles** récupérées depuis `/var/lib/tftpboot/` de la VM (sur la VM, les entrées
     sous `/var/www/sambaedu/ipxe/` ne sont que des symlinks vers ce dossier).
   Le versionnement de binaires sous `resources/` suit le précédent existant
   (`resources/ipxe/winpe/wimboot`, `resources/agent/tools/*.zip`,
   `resources/assets/wallpaper-icons/*.png` — exception client-facing de la convention
   storage, `project_storage_convention_non_versioned`).

2. **Provisioning par `update.sh` (patron `ensure_*`).** Nouvelle fonction
   `ensure_ipxe_statics()` dans `scripts/update.sh`, appelée dans le bloc principal
   (à côté de `ensure_ipxe_bootstrap_native`), idempotente :
   - copie `resources/ipxe/static/` → `"$PROJECT_DIR"/storage/ipxe/static/` (emplacement
     servi, hors legacy), `chown -R www-admin` + lisible Apache (`o+rX` —
     `project_php_fpm_user_www_admin`) ;
   - **greenfield TFTP** : dépose `undionly.kpxe` et `snponly_x64.efi` dans
     `/var/lib/tftpboot/` **s'ils y sont absents ou différents** (`cmp -s || install`) —
     sur la VM actuelle c'est un no-op (fichiers identiques, propriété du paquet
     `sambaedu-boot-server`), sur un hôte vierge sans paquets SE4 cela rend le TFTP
     autonome. Ne PAS toucher à la config atftpd elle-même.

3. **Alias Apache repointé, fallback conservé.** Dans `scripts/setupApache.sh`, le bloc
   `Alias /ipxe /var/www/sambaedu/ipxe` + `<Directory /var/www/sambaedu/ipxe>` pointe
   désormais sur `$SER_ROOT/storage/ipxe/static` ; `FallbackResource /index.php`,
   `Options -Indexes +FollowSymLinks`, `AllowOverride None`, `Require all granted`
   sont conservés à l'identique. Les routes Laravel `/ipxe/boot`, `/ipxe/admin`,
   `/ipxe/enrollment/*`… continuent de primer sur toute URL **sans fichier physique**
   (comportement FallbackResource inchangé). Le commentaire du bloc est mis à jour
   (plus de référence au legacy).

4. **Racine TFTP vérifiée + e2e VM.** Constat consigné (Dev Notes) : la racine TFTP est
   `/var/lib/tftpboot` (atftpd, socket-activé port 69, `OPTIONS` dans
   `/etc/default/atftpd`) — elle ne dépend PAS de `/var/www/sambaedu`, aucun repointage
   requis. **Preuve e2e VM** (manuelle, avec Henri, hors tests auto) : avec
   `/var/www/sambaedu` temporairement renommé (`mv /var/www/sambaedu{,.test-38-1}` puis
   restauration — opération VM pure, réversible), un poste BIOS **et** un poste UEFI
   rebootent en PXE : TFTP délivre `undionly.kpxe`/`snponly_x64.efi`, puis le chain HTTP
   `http://…/ipxe/boot` répond (route Laravel), et `GET /ipxe/png/ipxe-se4.png` +
   `GET /ipxe/boot.ipxe` sont servis depuis le nouvel emplacement (HTTP 200, octets
   identiques aux originaux).

5. **`dhcpd.conf` inchangé.** Mêmes filenames TFTP (`undionly.kpxe`, `snponly_x32.efi`,
   `snponly_x64.efi`), même URL de chain (`http://<ip>/ipxe/boot`). Aucune modification
   de `/etc/dhcp/dhcpd.conf` ni de `make_dhcpd_conf.sh` (hors périmètre — Story 8.3).
   Le gap pré-existant `snponly_x32.efi` (référencé par dhcpd.conf, absent de
   `/var/lib/tftpboot` ET du legacy) est documenté, PAS corrigé (aucun client arch
   00:06 au parc ; en corriger un absent serait un choix sur-conçu).

6. **Catchall : legacy absent → 404 loggé, plus jamais 500.** Dans
   `LegacyCatchallController::handle` (bloc actuel lignes 140-145) : quand
   `config('sambaedu.legacy_path')` est vide OU n'est pas un dossier, ne plus
   `abort(500)` — sauter la résolution legacy, logger selon
   `config('sambaedu.log_404')` (env `LEGACY_LOG_404`, via `logLegacyAccess()` existant
   → table `legacy_catchall_logs` + channel `legacylog`), puis `abort(404)`. Le
   comportement quand `legacy_path` existe est strictement inchangé (blocked routes,
   `noop:`/`gone:`, proxy, modules in-repo `legacy/modules/*`).

7. **Tests.**
   - `tests/Feature/LegacyCatchallTest.php` : les 2 tests AC4 historiques
     (`test_invalid_legacy_path_returns_500`, `test_missing_legacy_path_returns_500`)
     sont **réécrits** : `legacy_path` invalide/null → `assertStatus(404)` + une ligne
     `legacy_catchall_logs` créée ; + 1 cas `LEGACY_LOG_404=false` → 404 sans ligne DB.
   - Les early-returns du catchall (redirections `no_roam`/`del_roam`, `gone:`,
     `noop:`) restent verts avec `legacy_path` absent (ils précèdent la résolution
     FS — l'affirmer par au moins un test avec `legacy_path` null).
   - Nouveau test d'architecture textuel `tests/Architecture/IpxeStaticAliasTest.php`
     (patron lecture-fichier de `WpkgOutRoutesTest`/`ScriptsOsNamespaceTest`) :
     `scripts/setupApache.sh` ne contient plus `/var/www/sambaedu/ipxe`, contient
     `Alias /ipxe $SER_ROOT/storage/ipxe/static` et `FallbackResource /index.php`
     dans le bloc `/ipxe` ; `scripts/update.sh` contient `ensure_ipxe_statics` déclarée
     ET appelée ; les 6 fichiers de l'AC 1 existent sous `resources/ipxe/static/`
     (tailles > 0 ; md5 des 2 binaires vérifiés).
   - Non-régression : `IpxeNamespaceTest` (ordre routes `/ipxe/*` avant catchall —
     aucune route ajoutée/déplacée par cette story), `IpxeLegacyRoutingNonRegressionTest`,
     `IpxeBootEndpointTest` restent verts.

## Tasks / Subtasks

- [x] Task 1 — Rapatrier les statiques dans le repo (AC: 1)
  - [x] `scp` (lecture seule) depuis la VM : `/var/www/sambaedu/ipxe/{boot.ipxe,diconf/*,png/*}` et `/var/lib/tftpboot/{undionly.kpxe,snponly_x64.efi}` → `resources/ipxe/static/` (arborescence : `boot.ipxe`, `diconf/`, `png/`, binaires à la racine — miroir de l'alias actuel)
  - [x] Vérifier les md5 des 2 binaires contre l'AC 1 après copie
- [x] Task 2 — `ensure_ipxe_statics()` dans `scripts/update.sh` (AC: 2)
  - [x] Écrire la fonction (patron `ensure_wpkg_bundle`/`ensure_agent_required_tools` : `log`/`log_success`/`log_warning`, idempotence, tolérance hôte sans TFTP — si `/var/lib/tftpboot` absent, `log_warning` + continuer)
  - [x] Copie `resources/ipxe/static/` → `storage/ipxe/static/` + `chown -R www-admin` + `chmod` lisible Apache
  - [x] Dépôt conditionnel des 2 binaires dans `/var/lib/tftpboot/` (`cmp -s || install -m 644`)
  - [x] Appel dans le bloc principal, juste après `ensure_ipxe_bootstrap_native`
  - [x] Ajouter `storage/ipxe/` au `.gitignore` de storage si nécessaire (contenu provisionné, non versionné — convention storage)
- [x] Task 3 — Repointer l'alias dans `scripts/setupApache.sh` (AC: 3)
  - [x] Modifier le bloc `Alias /ipxe` + `<Directory>` → `$SER_ROOT/storage/ipxe/static`, directives conservées, commentaire réécrit (mentionner story 38.1 + le fait que les routes Laravel priment sans fichier physique)
- [x] Task 4 — Catchall 404 (AC: 6)
  - [x] `LegacyCatchallController::handle` : remplacer le `abort(500)` (lignes 143-145) par : log conditionnel (`log_404`) via `logLegacyAccess()` + `abort(404, 'Page non trouvée')` — même message que le 404 nominal existant (ligne 166)
  - [x] Vérifier qu'aucun autre chemin ne 500 sur legacy absent (grep `abort(500` dans le contrôleur — le seul autre est dans `executeViaBootstrap`, chemin modules in-repo, hors périmètre)
- [x] Task 5 — Tests (AC: 7)
  - [x] Réécrire les 2 tests AC4 de `LegacyCatchallTest` (404 + ligne DB), ajouter le cas `log_404=false` et le cas early-return avec `legacy_path` null
  - [x] Créer `tests/Architecture/IpxeStaticAliasTest.php` (assertions textuelles sur `setupApache.sh` + `update.sh` + existence/md5 des fichiers `resources/ipxe/static/`)
  - [x] Lancer sur l'HÔTE par filtres ciblés : `php artisan test --filter=LegacyCatchallTest`, `--filter=IpxeStaticAliasTest`, `--filter=IpxeNamespaceTest`, `--filter=IpxeLegacyRoutingNonRegressionTest`, `--filter=IpxeBootEndpointTest`
- [ ] Task 6 — Application VM + e2e (AC: 2, 3, 4, 5 — avec Henri, hors CI) — **RESTE À FAIRE (manuel, hors worktree)**
  - [ ] Attendre le sync inotify (fichiers créés — vérifier la boucle `inotifywait` active, `project_inotify_sync_loop_can_be_down`) ; les binaires copiés localement arrivent par le sync (CRUD fichiers) — vérifier leurs md5 côté VM avant de continuer
  - [ ] Sur la VM : `bash scripts/update.sh` (ou a minima la fonction) → `storage/ipxe/static` peuplé + chown ; puis `bash scripts/setupApache.sh` (le vhost n'est PAS régénéré par update.sh — re-run manuel requis) + vérifier `apache2ctl configtest`
  - [ ] Vérifier `dhcpd.conf` inchangé (`md5sum` avant/après)
  - [ ] e2e AC 4 : renommage temporaire du legacy, boot BIOS + UEFI, curls de contrôle, restauration

## Dev Notes

### Inventaire VM (constaté le 2026-07-10, lecture seule — /vm 192.168.122.50)

`/var/www/sambaedu/ipxe/` (tout appartient à `www-admin:root`) :

| Entrée | Type | Taille | Note |
|---|---|---|---|
| `boot.ipxe` | fichier | 2 876 o | Menu iPXE d'install des **serveurs** SE4 (Se4AD, Debian) ; référence `png/ipxe-se4.png`, `diconf/se4ad.preseed` + `diconf/se4fs.preseed` (**absents** du dossier — entrées de menu déjà mortes) et `/os/debian-installer/*` (alias `/os` → `/var/sambaedu/unattended/install/os`, hors legacy, non concerné) |
| `diconf/authorized_keys` | fichier | 564 o | clés publiques SSH (install serveur SE4) |
| `diconf/install_se4_from0.sh` | fichier | 19 217 o | script d'install serveur SE4 |
| `png/ipxe-se4.png` | fichier | 93 815 o | fond d'écran console iPXE |
| `undionly.kpxe` | **symlink** | → `/var/lib/tftpboot/undionly.kpxe` (74 994 o réels) | |
| `snponly_x64.efi` | **symlink** | → `/var/lib/tftpboot/snponly_x64.efi` (216 064 o réels) | |

**Racine TFTP** : `atftpd` (socket-activé, `atftpd.socket` port 69 ;
`/etc/default/atftpd` → `OPTIONS="… /var/lib/tftpboot"`). Les binaires réels y sont la
propriété du paquet Debian **`sambaedu-boot-server`** (`dpkg -S` confirmé), md5 :
`undionly.kpxe=49e53c73677941fd8d4f5e634fc4220f`,
`snponly_x64.efi=3c745bf0c61d72f5e7326a271e34cae4`. **Conclusion AC 4 : le TFTP ne sert
RIEN depuis `/var/www/sambaedu`** — le lien de dépendance est inverse (le legacy
symlinke vers la racine TFTP). Supprimer le legacy ne casse pas le TFTP ; le volet
« greenfield » de l'AC 2 anticipe seulement le futur retrait du paquet SE4.

**`/etc/dhcp/dhcpd.conf`** (extrait BOOT OPTIONS, à laisser tel quel) :
- `next-server 192.168.122.50;`
- user-class `sambaedu` → `filename "http://192.168.122.50/ipxe/boot";` (route Laravel native)
- sinon arch `00:00` → `undionly.kpxe`, `00:06` → `snponly_x32.efi` (**fichier
  inexistant partout** — gap pré-existant, documenté AC 5, pas d'action), `00:07` →
  `snponly_x64.efi`.
Généré par `/usr/share/sambaedu/sbin/make_dhcpd_conf.sh` (cron legacy — remplacement =
Story 8.3, PAS ici).

**Vhost VM** : `/etc/apache2/sites-enabled/sambaedu.conf` contient l'`Alias /ipxe
/var/www/sambaedu/ipxe` généré par `scripts/setupApache.sh` — modifier le script ne
change RIEN sur la VM tant qu'il n'est pas re-exécuté (Task 6).

### Architecture / existant à réutiliser — NE PAS réinventer

| Brique | Fichier | Rôle pour 38.1 |
|---|---|---|
| Catchall + log | `app/Http/Controllers/LegacyCatchallController.php` — `handle()` (abort(500) : lignes 143-145), `logLegacyAccess()` (DB `legacy_catchall_logs` + channel `legacylog`) | Seul point à modifier pour le volet 404. Réutiliser `logLegacyAccess()` tel quel. |
| Config | `config/sambaedu.php` : `legacy_path` (`LEGACY_PATH`, défaut `/var/www/sambaedu`), `log_404` (`LEGACY_LOG_404`, défaut `true`) | Aucune clé nouvelle. |
| Vhost | `scripts/setupApache.sh` lignes ~106-116 (bloc `/ipxe`) ; précédent d'alias storage : `/install/iso` → `$SER_ROOT/storage/install/iso`, `/assets/shortcut-icons` → `$SER_ROOT/storage/app/shortcut-icons` | Patron exact du repointage. |
| update.sh | patron `ensure_*` (déclaration + appel dans le bloc principal, lignes ~1291-1348) ; voisin logique : `ensure_ipxe_bootstrap_native` (ligne 679) | `ensure_ipxe_statics` s'insère juste après. |
| Assets versionnés | `resources/ipxe/{linux/*.cfg,windows/unattend.xml,winpe/wimboot}` | Précédent binaire versionné (`wimboot`) → `resources/ipxe/static/` est cohérent. |
| Tests patrons | `tests/Architecture/WpkgOutRoutesTest.php` (lecture textuelle + offsets), `tests/Feature/LegacyCatchallTest.php` (setUp `legacyTmpDir`), `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` (provisioning table `legacy_catchall_logs` en SQLite) | |

### Pièges connus (mémoire projet + garde-fous d'epic)

- **Routes avant catchall** : cette story n'ajoute NI ne déplace aucune route — ne pas
  toucher `routes/web.php`. Les gardes existants (`IpxeNamespaceTest`,
  `WpkgOutRoutesTest`) doivent rester verts tels quels.
- **`dhcpd.conf` inchangé** (garde-fou d'epic) : ni le fichier VM, ni
  `make_dhcpd_conf.sh` (`/usr/share/sambaedu` = interdit dans cet epic).
- **inotify ne sync pas les deletes** (`project_inotify_no_delete_sync`) : la story ne
  supprime rien côté VM ; le renommage e2e de l'AC 4 est une opération VM pure,
  manuelle et réversible. Jamais `rm -rf` (utiliser `mv`, et `trash` si suppression).
- **Migrations jamais auto-jouées sur VM** (`project_vm_migrations_not_auto_applied`) :
  sans objet ici (AUCUNE migration dans cette story — la table `legacy_catchall_logs`
  existe), mais `migrate:status` avant tout e2e si d'autres stories sont passées.
- **Boucle sync** : vérifier `ps aux | grep inotifywait` et comparer les md5 des
  binaires hôte/VM avant de croire un état VM (`project_inotify_sync_loop_can_be_down`).
- **Tests = HÔTE php8.4 + sqlite** (`project_phpunit_test_env_host_vs_vm`), par filtres
  ciblés (`project_vm_phpunit_bulk_run_false_failures`).
- **`boot.ipxe` physique shadow la route Laravel** `GET /ipxe/boot.ipxe`
  (`ipxe.boot.alias`, `routes/web.php:743`) : avec un fichier physique présent, Apache
  le sert directement et la route native n'est jamais atteinte pour cette URL. C'est le
  comportement ACTUEL (iso-fonctionnel) — le reconduire tel quel en déposant
  `boot.ipxe` dans le nouvel emplacement. Ne PAS « profiter » de la story pour basculer
  cette URL sur la route native (changement de comportement = hors périmètre ; le menu
  legacy serveur reste accessible à l'identique).
- **`diconf/` servi en HTTP** : `authorized_keys` (clés publiques) +
  `install_se4_from0.sh` sont déjà publics aujourd'hui via l'alias — parité stricte,
  pas de nouveau risque introduit. Ne rien y ajouter.
- **PHP-FPM/Apache user** : fichiers provisionnés `chown www-admin` + `o+rX`
  (`project_php_fpm_user_www_admin` ; précédent : commentaire du bloc shortcut-icons
  de setupApache.sh).
- **`.env`/config VM** : si un `.env` change (aucun prévu ici), `config:cache` + chown
  (`project_vm_config_cache_not_synced`). Pas de `route:cache` nécessaire (routes
  intouchées).
- **`setupApache.sh` n'est PAS rejoué par `update.sh`** : l'application du nouveau
  vhost sur la VM est une étape manuelle explicite de la Task 6.
- **`FilesMatch \.php$` → FPM** est global au vhost : ne déposer AUCUN `.php` sous
  `storage/ipxe/static/` (il n'y en a aucun dans l'inventaire).

### Project Structure Notes

- Source versionnée : `resources/ipxe/static/{boot.ipxe, undionly.kpxe,
  snponly_x64.efi, diconf/{authorized_keys,install_se4_from0.sh}, png/ipxe-se4.png}` —
  l'arborescence sous `static/` est le miroir exact de ce que l'alias `/ipxe` doit
  exposer (URL `/ipxe/<chemin relatif>` inchangées).
- Cible provisionnée (non versionnée) : `storage/ipxe/static/` — convention storage
  (`project_storage_convention_non_versioned`) : `storage/*` provisionné par script,
  exceptions client-facing versionnées côté `resources/`.
- Aucun changement : `routes/web.php`, `routes/api.php`, `config/*.php`, migrations,
  `agent/**` (pas de bump `agent/shared/version.go`), `legacy/modules/*`.

### References

- [Source: _bmad-output/planning-artifacts/epics-extinction-se4.md#Story-38.1] — ACs d'origine, D3/D4, garde-fous d'epic, Overview pt 1 et 6
- [Source: _bmad-output/planning-artifacts/architecture.md#Coexistence-Legacy-—-Stratégie-Catchall] — catchall, `legacy_catchall_logs`, migration route par route
- [Source: app/Http/Controllers/LegacyCatchallController.php:140-166] — bloc à modifier + `logLegacyAccess()`
- [Source: config/sambaedu.php:50-63] — `legacy_path`, `log_404`
- [Source: scripts/setupApache.sh:106-129] — bloc `/ipxe` + précédent shortcut-icons
- [Source: scripts/update.sh:679-745,1291-1348] — `ensure_ipxe_bootstrap_native` + bloc d'appels `ensure_*`
- [Source: tests/Feature/LegacyCatchallTest.php:162-183] — tests AC4 à réécrire (500 → 404)
- [Source: tests/Architecture/WpkgOutRoutesTest.php] — patron de test textuel
- [Source: docs/audit-dependances-systeme.md] — Vague 4 (recoupement epic)

## Dépendances

- **Indépendante des autres stories 38.x** (séquencement d'epic : 38.1→38.4
  indépendantes entre elles). Peut se développer et se livrer seule.
- **Amont** : aucune. La route native `/ipxe/boot` (Story 3.1) et la bascule dhcpd
  (`ensure_ipxe_bootstrap_native`) sont livrées et en place sur la VM.
- **Aval** : **bloquante pour 38.6** (l'extinction à blanc suppose que l'alias `/ipxe`
  et le catchall survivent au renommage de `/var/www/sambaedu`). Le volet 404 (D4)
  conditionne aussi la qualité d'observation de 38.6 (`legacy_catchall_logs` sans FS
  legacy).
- **Hors périmètre assumé** : génération DHCP/DNS (Story 8.3), tombstones des routes
  clients (38.2), `require` FS legacy (38.4).

## Recommandation Modèle Dev

**opus** — imposé par l'epic (« Reco dev : … opus pour 38.1, 38.2, 38.5 »,
epics-extinction-se4.md, Garde-fous d'epic). Justification : story transverse
code PHP léger (un `abort` remplacé) + shell ops (update.sh/setupApache.sh) + tests,
sans algorithmique lourde ni Go/Kerberos — le profil opus suffit et le risque est
surtout opérationnel (VM, Apache), couvert par les garde-fous ci-dessus.

## Dev Agent Record

### Agent Model Used

opus (imposé par l'epic — cf. Recommandation Modèle Dev).

### Debug Log References

- `php artisan test --filter=IpxeStaticAliasTest` → 4 passed (30 assertions).
- `php artisan test --filter=LegacyCatchallTest` → 13 passed (29 assertions).
- `php artisan test --filter='IpxeNamespaceTest|IpxeLegacyRoutingNonRegressionTest|IpxeBootEndpointTest'` → 73 passed (325 assertions).
- `bash -n scripts/update.sh` + `bash -n scripts/setupApache.sh` → OK.
- md5 post-scp : `undionly.kpxe=49e53c73677941fd8d4f5e634fc4220f`, `snponly_x64.efi=3c745bf0c61d72f5e7326a271e34cae4` (conformes AC 1).

### Completion Notes List

- **Task 1** — 6 statiques rapatriés par `scp` (lecture seule VM→worktree) sous `resources/ipxe/static/` (arbo miroir de l'alias : `boot.ipxe`, `diconf/`, `png/`, binaires racine). Tailles conformes à l'inventaire (2 876 / 564 / 19 217 / 93 815 / 74 994 / 216 064 o), md5 des 2 binaires vérifiés.
- **Task 2** — `ensure_ipxe_statics()` ajoutée dans `scripts/update.sh` juste après `ensure_ipxe_bootstrap_native` (déclaration + appel). Publie `resources/ipxe/static/` → `storage/ipxe/static/` (`cp -a src/.`, idempotent), `chown www-admin`, `chmod u+rwX,go+rX` (lisible Apache « other »). Volet greenfield TFTP : `cmp -s || install -m 644` des 2 binaires dans `/var/lib/tftpboot/` (no-op sur la VM, autonome sur hôte vierge). Fail-soft : source ou TFTP absent → `log_warning` + continuer. `/storage/ipxe/` ajouté à `.gitignore` racine (cible provisionnée non versionnée, patron `/storage/install/`).
- **Task 3** — Alias `/ipxe` de `scripts/setupApache.sh` repointé `Alias /ipxe $SER_ROOT/storage/ipxe/static` + `<Directory>` correspondant ; directives conservées à l'identique (`Options -Indexes +FollowSymLinks`, `AllowOverride None`, `Require all granted`, `FallbackResource /index.php`) ; commentaire réécrit (référence 38.1, plus de mention legacy).
- **Task 4** — `LegacyCatchallController::handle` : `abort(500)` sur `legacy_path` absent/invalide remplacé par log conditionnel (`log_404` via `logLegacyAccess()`) + `abort(404, 'Page non trouvée')` (même message que le 404 nominal). Grep confirmé : le seul autre `abort(500)` est dans `executeViaBootstrap` (chemin modules in-repo `legacy/modules/*`, hors périmètre).
- **Task 5** — Les 2 tests AC4 de `LegacyCatchallTest` réécrits (500 → 404 + ligne DB) ; ajout des cas `log_404=false` (404 sans ligne) et early-return (`gpo/no_roam.php` redirige toujours avec `legacy_path` null). Nouveau `tests/Architecture/IpxeStaticAliasTest` (alias repointé + absence de `/var/www/sambaedu/ipxe` + `FallbackResource` conservé ; `ensure_ipxe_statics` déclarée ET appelée ; existence + md5 des 6 statiques). Non-régression iPXE verte.
- **Task 6 (RESTE À FAIRE, manuel avec Henri, hors worktree)** — Application VM (`bash scripts/update.sh` puis `bash scripts/setupApache.sh` + `apache2ctl configtest`), vérification `dhcpd.conf` inchangé (md5 avant/après), e2e netboot BIOS+UEFI avec `/var/www/sambaedu` temporairement renommé (opération VM pure réversible), curls de contrôle `/ipxe/png/ipxe-se4.png` + `/ipxe/boot.ipxe`. Interdit depuis un worktree (garde-fou projet) — à dérouler sur la VM par Henri. Runbook détaillé : `docs/qa/domains/ipxe.md` § Story 38.1.

### File List

- `app/Http/Controllers/LegacyCatchallController.php` (modifié — catchall 404 D4)
- `scripts/update.sh` (modifié — `ensure_ipxe_statics` + appel)
- `scripts/setupApache.sh` (modifié — alias `/ipxe` repointé)
- `.gitignore` (modifié — `/storage/ipxe/` non versionné)
- `resources/ipxe/static/boot.ipxe` (nouveau — versionné)
- `resources/ipxe/static/diconf/authorized_keys` (nouveau — versionné)
- `resources/ipxe/static/diconf/install_se4_from0.sh` (nouveau — versionné)
- `resources/ipxe/static/png/ipxe-se4.png` (nouveau — versionné)
- `resources/ipxe/static/undionly.kpxe` (nouveau — binaire versionné)
- `resources/ipxe/static/snponly_x64.efi` (nouveau — binaire versionné)
- `tests/Feature/LegacyCatchallTest.php` (modifié — tests 404)
- `tests/Architecture/IpxeStaticAliasTest.php` (nouveau)
- `docs/qa/domains/ipxe.md` (modifié — runbook QA § Story 38.1, append-only)
- `_bmad-output/implementation-artifacts/38-1-relocalisation-statiques-ipxe-catchall-404.md` (story)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut → review)

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-07-10 | 0.1 | Implémentation 38.1 (Tasks 1-5) : statiques iPXE versionnés + `ensure_ipxe_statics` + alias repointé + catchall 404 (D4) + tests. Task 6 (e2e VM) reste manuelle. Status → review. | Dev (opus) |
