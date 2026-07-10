# Story 38.5: Débranchement des crons et fonctions serveur résiduelles

Status: review

## Story

En tant qu'exploitant,
je veux que plus aucun cron ni écran SE5 n'invoque le web legacy,
afin que le serveur soit fonctionnellement autonome avant l'extinction à blanc.

## Acceptance Criteria

(Repris de `_bmad-output/planning-artifacts/epics-extinction-se4.md` — Story 38.5,
précisés par les décisions Q2/Q3 tranchées par Henri le 2026-07-10.)

1. **Given** les décisions Q2/Q3 tranchées (ENT, quotas, cloud, stats, clean_profiles —
   voir section « Décisions » ci-dessous)
   **When** les crons `/etc/cron.d/sambaedu-{web-common,wpkg,shares}` sont retirés
   (`update.sh` porte la logique de retrait idempotente ; **`sambaedu-boot-server` est
   EXPLICITEMENT EXCLU** — gating Story 8.3, voir Dépendances)
   **Then** chaque fonction est soit portée au scheduler Laravel (story ou epic dédié
   référencé), soit abandonnée avec trace écrite (doc + backlog), et plus AUCUN POST
   `*.php` legacy n'apparaît dans les logs Apache serveur (les crons étaient la
   majorité du trafic : `sync_cron`, `update_stats`, `rep_cloud_cron`, `wpkg_*`…)
   **And** les lignes cron qui NE servent PAS le web legacy (`renew_ticket.sh`,
   `smbstatus.sh`) sont **conservées sous propriété SE5** (fichier cron versionné dans
   le repo, provisionné par install.sh/update.sh) — elles ne doivent JAMAIS disparaître,
   même transitoirement.

2. **Given** l'embed legacy (`LegacyEmbedService`) et sa dernière route
   (`/users/groups/legacy-new` → `annu2/add_group.php`, + titre `annu/import_gpei.php`)
   **When** la décision « débranchement sec » est appliquée (tranchée en création de
   story — voir « Décision embed » ci-dessous : la création de groupe native existe,
   la route legacy est orpheline dans l'UI)
   **Then** `LegacyEmbedService`/`LegacyEmbedController` sont supprimés du repo, la
   route `users.groups.legacy-new` est retirée de `routes/web.php`, et la whitelist de
   `GpoLegacyIsolationTest` ne contient plus `LegacyEmbedService.php` (le garde-fou
   architectural se referme d'un cran)
   **And** la vue `resources/views/legacy-embed.blade.php` est **CONSERVÉE** (consommée
   par `LegacyCatchallController:280,442` — l'embed HTML du catchall survit jusqu'à
   l'Epic 14, D6).

## Décisions Q2/Q3 — TRANCHÉES par Henri (2026-07-10)

- **Q2 — ENT (`sync_cron`, `mfa_ent`, `test_ent`) : ABANDON ACTÉ.** Crons retirés,
  abandon documenté (doc + backlog). Réouvrable en **epic dédié** si un besoin réel
  émerge sur une instance cible. AUCUN portage dans cette story.
- **Q3 — minimum livrable CONFIRMÉ** : 38.5 = retrait des crons + décision documentée
  PAR FONCTION. Les portages sont HORS story :
  - **Quotas (`repquota`)** : déjà portés — `quota:snapshot` (Story 5.1b,
    `app/Console/Kernel.php:97`). Abandon du cron `repquota` **acté**.
  - **Stats de fréquentation (`update_stats`, `stats.php`)** : entrée backlog
    « chantier produit dédié » (SE5 a le signal présence agent,
    `project_workstation_presence_shutdown_signal`). Abandon des crons.
  - **`rep_cloud_cron`** : chantier Nextcloud déjà cadré
    (`project_nextcloud_file_plane_direction`). Abandon du cron, renvoi documenté.
  - **`clean_profiles.sh`** : évalué en création de story — VERDICT : abandon,
    couverture native existante. Le script (a) `rm -rf` des caches navigateur dans
    `/home/*`, (b) `curl gpo/del_roam.php` puis **exécute la réponse** (pattern « code
    servi en HTTP » banni par D2), (c) produit le `du` des profils. SE5 couvre déjà :
    `profiles:snapshot` (`Kernel.php:186`) pour le scan nocturne, et la purge native
    des profils orphelins (`RoamingProfileService.php:741`, réimplémentation documentée
    de `clean_profiles('*')`). Le nettoyage des caches navigateur `/home` n'est PAS
    reconduit (décision à documenter comme abandon assumé — si le besoin revient,
    c'est un job scheduler natif trivial, `feedback_no_overengineered_choices`).

## Décision embed — DÉBRANCHEMENT SEC (analyse en création de story, 2026-07-10)

Analyse du POURQUOI métier (`feedback_understand_business_before_design`) :

1. **La création de groupe native EXISTE et est LIVRÉE** : modale `group-form-modal`
   (`resources/views/pages/users/groups/_partials/group-form-modal.blade.php`), event
   `open-user-group-modal` sur la page `/users`, gardée `Gate::allows('user.modify')`
   à l'ouverture ET au save, création via `UserGroupService::createGroup()` (name = CN
   AD + display_name + type). Commit `2994a79` « Refactor user group creation to use
   modal instead of dedicated page ». Le commentaire de `routes/web.php:108-111` le
   confirme : « La création passe désormais par la modale group-form-modal ».
2. **La route legacy est ORPHELINE** : `grep legacy-new` dans `resources/` et `lang/`
   = ZÉRO occurrence. Aucun lien UI ne pointe vers `/users/groups/legacy-new`.
3. **`annu/import_gpei.php` n'a JAMAIS eu de route** : c'est une simple entrée du
   tableau de titres `LegacyEmbedController::titleFromModule()` (:46). Rien à porter —
   l'import d'utilisateurs SE5 passe par `users:sync-from-ad` et la gestion native.
   Si un import GPEI natif est demandé un jour, c'est une feature produit (backlog).

→ **Aucun portage à faire : suppression sèche** de la route, du contrôleur et du
service. Effet de bord assumé : l'URL `/users/groups/legacy-new` retombe dans le
catchall (proxy legacy tant que le legacy vit, 404 loggé après extinction 38.6) —
acceptable, personne n'y est lié.

## Inventaire RÉEL des crons (constaté sur /vm le 2026-07-10) et triage ligne à ligne

Règle de triage (garde-fou epic) : la story ne retire QUE ce qui invoque le web legacy
(`action_cron_php.sh` = `curl -F se4_key… http://<name>/<page>.php` ; `check_config.sh`
et `clean_profiles.sh` curl-ent aussi) — les lignes système vitales sont RE-POSSÉDÉES.

### `/etc/cron.d/sambaedu-web-common` → RETIRÉ (après re-possession des 3 lignes vitales)

| Ligne cron | Invoque le web legacy ? | Décision |
|---|---|---|
| `50 * * * * www-admin renew_ticket.sh` + `@reboot www-admin renew_ticket.sh` | NON (`kinit -k -t /etc/sambaedu/www-sambaedu.keytab www-sambaedu`) | **CONSERVER — re-possession SE5** (ticket Kerberos www-admin VITAL pour SYSVOL, `project_sysvol_write_needs_wwwadmin_kinit` ; le ccache ambiant est encore consommé par les writes SYSVOL et vérifié par `KerberosTicketCheck`) |
| `1 * * * * root smbstatus.sh` | NON (dump `smbstatus -b` → `/tmp/smbstatus`) | **CONSERVER — re-possession SE5** : `/tmp/smbstatus` est LU par `app/Services/UserSessionsService.php:40` (sessions utilisateur, wallpapers) |
| `parcs/action_cron.php` (4×/h) | OUI | RETIRER — natif : `parc:execute-group-schedules` (`Kernel.php:35`) |
| `check_config.sh` (1/min → curl `config/config_action.php` sur changement de `/etc/sambaedu/*.conf`) | OUI | RETIRER — le canal « config → web legacy » meurt ; successeur = modèle capacités 27.3 (`project_config_capabilities_model`). Les fichiers `/etc/sambaedu` eux-mêmes ne sont PAS touchés (autre vague d'audit) |
| `stats.php` (php CLI 4×/j, sleep aléatoire) | indirect (script legacy) | RETIRER — abandon (télémétrie legacy) ; volet fréquentation → backlog |
| `annu/sync_cron.php` (1/min) + `annu/sync_cron.php openent` (04h01) | OUI | RETIRER — **Q2 abandon acté** |
| `infos/repquota.php` (*/4) | OUI | RETIRER — **abandon acté**, remplacé par `quota:snapshot` (5.1b) |
| `parcs/clean_connexions.php` (*/9) | OUI | RETIRER — natif : présence agent (check-in + shutdown, 4 états) |
| `/tmp/admin_script_{fast,normal,slow}.sh` | générés PAR le web legacy | RETIRER — mécanisme d'actions différées du web legacy (fichiers absents de /vm) |
| `config/test_ent.php` (*/5) | OUI | RETIRER — **Q2 abandon acté** |
| `clean_profiles.sh` (02h00) | OUI (curl + exécute `del_roam`) | RETIRER — **Q3 évalué** : couverture native `profiles:snapshot` + purge `RoamingProfileService:741` (cf. Décisions) |
| `stats/update_stats.php` (1/min) | OUI | RETIRER — backlog « chantier fréquentation » |
| `annu/mfa_ent.php cron` | OUI | RETIRER — **Q2 abandon acté** |
| `annu/delete_temp_users.php` (00h10) | OUI | RETIRER — abandon documenté (notion « utilisateurs temporaires » legacy sans équivalent SE5 ; gestion du cycle de vie utilisateur native + `trash:purge`) |

### `/etc/cron.d/sambaedu-shares` → RETIRÉ intégralement

| Ligne | Décision |
|---|---|
| `partages/rep_cloud_cron.php` (1/min) + `rep_cloud_cron.php cloud` (05h05) | RETIRER — renvoi chantier Nextcloud cadré (`project_nextcloud_file_plane_direction`) |
| `0 6 * * * root systemctl restart php8.2-fpm.service` | RETIRER — hygiène anti-fuites du legacy, non reconduite (abandon documenté ; si un besoin ops réel apparaît, c'est une décision d'exploitation, pas un héritage silencieux) |

### `/etc/cron.d/sambaedu-wpkg` → RETIRÉ intégralement

| Ligne | Décision |
|---|---|
| `wpkg/wpkg_depot_import.php` (12×/h) | RETIRER — natif : import dépôt AppStore (série 8.2.x, `appstore_catalog_sync` update.sh) |
| `wpkg/wpkg_rapport.php` (1/min) | RETIRER — natif : rapports WPKG collectés/parsés (9.4/9.5, canal SMB) |
| `wpkg/wpkg_ldap_update.php` (*/2) | RETIRER — natif : sync AD (`users:sync-from-ad`, `user-groups:sync-from-ad`, MachineObserver) |

### `/etc/cron.d/sambaedu-boot-server` → **NE PAS TOUCHER** (gating Story 8.3)

`*/5 * * * * root make_dhcpd_conf.sh` : fonction VIVANTE (génération dhcpd.conf +
réservations + DNS). La Story 8.3 (`ready-for-dev`, NON livrée) est son véhicule de
remplacement : son volet 2 versionne `make_dhcpd_conf.sh` dans `scripts/system/`
(adapté : retrait de l'appel `action_cron_php.sh script_make_reservations.php`) +
`ensure_dhcp_scripts()` dans update.sh. **Tant que 8.3 n'est pas livrée, retirer ce
cron casserait DHCP/DNS.** Cette story consigne le gating et n'y touche pas — le
dernier `curl *.php` serveur résiduel (script_make_reservations/dnsupdate) disparaîtra
avec 8.3, AVANT le GO de 38.6.

### `/etc/cron.d/sambaedu-scheduler` → **NE PAS TOUCHER** (c'est SE5)

## Tasks / Subtasks

### T1 — Re-possession SE5 des lignes cron vitales (AC1 And)

- [x] 1.1 Créer `scripts/config/sambaedu-system.cron` (patron exact de
  `scripts/config/sambaedu-scheduler.cron` : en-tête commentaire « source repo, ne pas
  éditer sur la VM », `SHELL`, `PATH`) contenant les 3 lignes conservées iso-legacy :
  - `50 * * * * www-admin /usr/share/sambaedu/sbin/renew_ticket.sh`
  - `@reboot www-admin /usr/share/sambaedu/sbin/renew_ticket.sh`
  - `1 * * * * root /usr/share/sambaedu/sbin/smbstatus.sh`
  Les scripts restent dans `/usr/share/sambaedu` (garde-fou epic : on n'y touche pas —
  leur internalisation est la Vague 3 de l'audit, PAS cette story). On ne re-possède
  ici que le DÉCLENCHEMENT.
- [x] 1.2 `install.sh` : fonction `install_system_cron()` (patron
  `install_scheduler_cron()`, `install.sh:699-727`) → `/etc/cron.d/sambaedu-system`,
  `chown root:root`, `chmod 644` ; appel dans le main à côté de
  `install_scheduler_cron` (`:951`).
- [x] 1.3 `update.sh` : provision idempotente de `/etc/cron.d/sambaedu-system` (patron
  du bloc scheduler, `update.sh:522-531` : rendu + `diff -q` avant écriture).
- [x] 1.4 ORDRE : la provision de `sambaedu-system` s'exécute AVANT le retrait de
  `sambaedu-web-common` (T2) — zéro fenêtre sans `renew_ticket`.

### T2 — Retrait des crons legacy dans update.sh (AC1)

- [x] 2.1 `update.sh` : fonction `ensure_legacy_crons_retired()` :
  - cible EXACTEMENT `/etc/cron.d/sambaedu-web-common`, `sambaedu-shares`,
    `sambaedu-wpkg` — liste EXPLICITE, jamais un glob `sambaedu-*` (qui avalerait
    `scheduler`, `system`, `boot-server`) ;
  - retrait par `mv` vers `/var/backups/sambaedu-legacy-crons/` (mkdir -p ; suffixe
    horodaté si collision) — réversible, JAMAIS `rm -rf` ;
  - idempotente (fichier absent → no-op silencieux) et rejouée à CHAQUE update.sh :
    couvre le cas d'une réapparition par conffile de paquet Debian legacy
    (`apt reinstall sambaedu-web-common` reposerait le fichier) ;
  - log clair par fichier retiré (patron `log`/`log_success` du script).
- [x] 2.2 Appeler `ensure_legacy_crons_retired` dans `main()` APRÈS la provision T1.
- [x] 2.3 `install.sh` (greenfield) : appeler aussi le retrait — une machine neuve avec
  paquets legacy résiduels ne doit pas relancer les crons.

### T3 — Débranchement sec de l'embed legacy (AC2)

- [x] 3.1 Supprimer la route `users.groups.legacy-new` (`routes/web.php:112-114`) et le
  commentaire associé s'il ne documente plus rien d'existant.
- [x] 3.2 Supprimer `app/Http/Controllers/LegacyEmbedController.php` et
  `app/Services/LegacyEmbedService.php` (via `trash`/`git rm`, jamais `rm -rf`).
- [x] 3.3 **CONSERVER** `resources/views/legacy-embed.blade.php` (consommée par
  `LegacyCatchallController:280,442`) et `legacy/stubs/` (consommés par
  `legacy/bootstrap.php`, les modules Tier 3 et leurs tests).
- [x] 3.4 `tests/Architecture/GpoLegacyIsolationTest.php` : retirer
  `'LegacyEmbedService.php'` de `WHITELIST` (:37) + mettre à jour le docblock (:21-25).
  Le test doit rester VERT après suppression (c'est la preuve que la frontière s'est
  refermée).
- [x] 3.5 Grep de clôture : `legacy-new`, `LegacyEmbed`, `add_group.php`,
  `import_gpei` → zéro occurrence dans `app/`, `routes/`, `resources/`, `tests/`
  (hors `LegacyCatchallController`/docs historiques).

### T4 — Documentation des décisions par fonction (AC1 Then)

- [x] 4.1 `docs/audit-dependances-systeme.md` — solder le volet crons de la Vague 4 :
  §2.9 (:163-173, tableau crons) et la ligne `:99`
  (`renew_ticket/smbstatus/check_config`) annotés avec la décision par fonction
  (portée native référencée / abandon acté / re-possession SE5 / gating 8.3) ;
  Vague 4 (:257-259) : marquer le volet crons fait, `make_dhcpd_conf` renvoyé à 8.3.
- [x] 4.2 Runbook QA `docs/qa/domains/legacy-shims.md` : section 38.5 (append-only) —
  vérifs post-retrait (voir T6).
- [x] 4.3 Backlog : ajouter les entrées « Stats de fréquentation — chantier produit
  dédié (signal présence agent) » et « ENT (sync/MFA/test) — abandonné 38.5,
  réouvrable en epic dédié sur besoin réel ».
  ⚠️ `_bmad-output/backlog.data.js` porte du travail utilisateur NON COMMITTÉ
  (`project_backlog_split_multifile` : 4 fichiers à committer ensemble) — si le
  working tree n'est pas propre sur ces fichiers au moment du dev, NE PAS y toucher :
  consigner les 2 entrées dans la story (Dev Agent Record) et le signaler à Henri.

### T5 — Tests (AC1, AC2)

- [x] 5.1 `tests/Architecture/LegacyCronRetirementTest.php` (patron grep-de-script
  `IpxeStaticAliasTest`) :
  - `update.sh` contient `ensure_legacy_crons_retired` et les 3 cibles explicites
    `sambaedu-web-common|sambaedu-shares|sambaedu-wpkg` ;
  - `update.sh`/`install.sh` ne contiennent AUCUN retrait de `sambaedu-scheduler`,
    `sambaedu-system`, `sambaedu-boot-server` (anti-glob : asserter l'absence de
    `sambaedu-*` dans la fonction de retrait) ;
  - `scripts/config/sambaedu-system.cron` existe et contient `renew_ticket.sh`
    (×2 dont `@reboot`) et `smbstatus.sh` ;
  - aucun `rm -rf` dans la fonction de retrait.
- [x] 5.2 Test routes : `users.groups.legacy-new` n'existe plus
  (`Route::has(...)` === false — à loger dans un test Feature routes existant ou
  dédié) ; `GpoLegacyIsolationTest` vert avec la whitelist réduite.
- [x] 5.3 Non-régression HÔTE ciblée (php8.4+sqlite,
  `project_phpunit_test_env_host_vs_vm`, runs ciblés par filtre —
  `project_vm_phpunit_bulk_run_false_failures`) : `GpoLegacyIsolationTest`,
  `LegacyCatchallTest`, `LegacyTombstoneRoutesTest`, `LegacyModuleDhcpTest`,
  `LegacyModuleBbbTest`, nouveau `LegacyCronRetirementTest`.

### T6 — Ops VM consignées (exécution APRÈS merge sur main, jamais depuis un worktree)

- [ ] 6.1 ⚠️ **inotify ne sync pas les deletes** (`project_inotify_no_delete_sync`) :
  la suppression de `LegacyEmbedService.php`/`LegacyEmbedController.php` laisse des
  FANTÔMES sur la VM → les retirer À LA MAIN côté VM (`trash` ou `mv`), sinon
  l'autoload peut encore les résoudre.
- [ ] 6.2 Sur /vm : `bash scripts/update.sh` (c'est LE vecteur du retrait des crons) ;
  vérifier `ls /etc/cron.d/` → restent `sambaedu-{scheduler,system,boot-server}` ;
  `/var/backups/sambaedu-legacy-crons/` contient les 3 fichiers retirés.
- [ ] 6.3 Route retirée ⇒ `php artisan route:cache` + `config:cache` + chown www-admin
  des caches (`project_route_cache_vm_ephemeral_test_routes`,
  `project_vm_config_cache_not_synced`).
- [ ] 6.4 Vérifs fonctionnelles : à H+1, `sudo -u www-admin klist` montre un ticket
  `www-sambaedu` frais (renew_ticket vit) ; `/tmp/smbstatus` a un mtime < 1h
  (smbstatus vit) ; page sessions/wallpapers OK.
- [ ] 6.5 **Vérif AC1 finale** : observer les access-logs Apache (vhost SE5 + legacy)
  sur ≥ 1h — plus AUCUN `POST *.php` provenant des crons (il ne doit rester que
  `make_dhcpd_conf.sh` → `script_make_reservations.php`/`dnsupdate.php` toutes les
  5 min, résiduel documenté gated 8.3, et le trafic postes → tombstones 38.2).
- [ ] 6.6 Consigner le résultat dans le runbook QA (T4.2).

## Dev Notes

- **Périmètre strict** : ne toucher NI `/etc/sambaedu`, NI `/usr/share/sambaedu`, NI
  `/var/sambaedu` (autres vagues d'audit). Seuls les fichiers `/etc/cron.d/sambaedu-*`
  listés + le code embed + install/update.sh + docs/tests.
- **Pourquoi le retrait vit dans update.sh et pas en ops pure** : update.sh est le
  vecteur de provisioning de TOUTES les instances (VM dev, lab1, futures) et se rejoue
  — le retrait devient une propriété du produit, pas un geste manuel unique. L'ops VM
  (T6) n'est que l'exécution + vérification sur la cible dev.
- **Réversibilité** : les fichiers retirés sont conservés dans
  `/var/backups/sambaedu-legacy-crons/`. Pour réactiver ponctuellement une ligne en
  debug : la recopier dans un fichier SANS point dans le nom (cron.d ignore les
  fichiers dont le nom contient `.`).
- **`renew_ticket` : ne PAS « moderniser »** en job scheduler Laravel : `@reboot` n'a
  pas d'équivalent scheduler propre, et le ccache doit exister AVANT toute écriture
  SYSVOL post-boot. Reprise iso-legacy en cron.d = le choix non sur-conçu
  (`feedback_no_overengineered_choices`). NB : 38.4 a introduit
  `AdministratorKerberosContext` (ccache Administrator dédié par opération) mais le
  ccache ambiant www-admin reste requis (autres writes smbclient + doctor
  `KerberosTicketCheck`).
- **`smbstatus.sh` n'est PAS du legacy web** : son unique effet est
  `/tmp/smbstatus`, consommé par `UserSessionsService` (SE5). Sa réécriture native
  éventuelle (appel `smbstatus` direct) est un chantier Vague 3, hors story.
- **Les crons curl-ent le nom `config_se4fs_name`** : ces requêtes atterrissent sur
  le vhost SE5 (catchall → proxy legacy). Après retrait, la ligne de base des logs
  legacy (`legacy_catchall_logs`) doit chuter massivement — c'est l'instrument de
  mesure du GO 38.6 (D3), ne pas s'étonner de la chute, la DOCUMENTER.
- **Embed** : `LegacyCatchallController` garde sa propre logique embed (vue
  `legacy-embed`) — la suppression ne concerne QUE le couple service/contrôleur dédié
  et sa route. Ne pas confondre les deux chemins de code.
- **Pièges connus** : jamais `git stash` ni modification du working tree utilisateur
  (fichiers `resources/views/**`, `app/Components/**`, `backlog.data.js`,
  `epics-*.md`, `app.css` NON COMMITTÉS — ne pas y toucher) ; suppressions repo via
  `git rm`/`trash` ; VM : migrations jamais auto-jouées (aucune migration dans cette
  story — n'en créer AUCUNE).

### Project Structure Notes

- `scripts/config/sambaedu-system.cron` — NOUVEAU (patron `sambaedu-scheduler.cron`).
- `scripts/install.sh` — `install_system_cron()` + appel retrait (T1.2, T2.3).
- `scripts/update.sh` — provision `sambaedu-system` + `ensure_legacy_crons_retired()`
  (patron `ensure_*` existant, cf. `ensure_ipxe_statics:767`).
- SUPPRIMÉS : `app/Http/Controllers/LegacyEmbedController.php`,
  `app/Services/LegacyEmbedService.php`, route `routes/web.php:112-114`.
- MODIFIÉS : `tests/Architecture/GpoLegacyIsolationTest.php` (whitelist),
  `docs/audit-dependances-systeme.md`, `docs/qa/domains/legacy-shims.md`.
- NOUVEAU test : `tests/Architecture/LegacyCronRetirementTest.php`.

### References

- [Source: _bmad-output/planning-artifacts/epics-extinction-se4.md#Story-38.5 + garde-fous + D5]
- [Source: inventaire /vm 2026-07-10 — `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 'cat /etc/cron.d/sambaedu-*'` + lecture des scripts `/usr/share/sambaedu/sbin/{renew_ticket,smbstatus,check_config,action_cron_php,clean_profiles}.sh`]
- [Source: docs/audit-dependances-systeme.md §2.9, Vague 4]
- [Source: app/Console/Kernel.php:97 (quota:snapshot), :186 (profiles:snapshot), :35 (parc:execute-group-schedules)]
- [Source: app/Services/UserSessionsService.php:37-40 (/tmp/smbstatus)]
- [Source: app/Services/RoamingProfileService.php:741 (purge native clean_profiles)]
- [Source: _bmad-output/implementation-artifacts/8-3-sous-reseaux-dhcp-vlans.md — D2/Volet 2 (make_dhcpd_conf conservé, versionné et adapté par 8.3)]
- [Source: mémoires projet — project_sysvol_write_needs_wwwadmin_kinit, project_inotify_no_delete_sync, project_route_cache_vm_ephemeral_test_routes, project_nextcloud_file_plane_direction, project_config_capabilities_model, feedback_no_overengineered_choices]

## Dépendances

- **Gating PARTIEL sur Story 8.3 (make_dhcpd_conf UNIQUEMENT)** :
  `/etc/cron.d/sambaedu-boot-server` est HORS périmètre tant que 8.3 (ready-for-dev)
  n'est pas livrée — son remplacement (script versionné SE5 + retrait de l'appel
  `script_make_reservations.php`) appartient à 8.3. Tout le reste de la story est
  indépendant et peut être développé/livré immédiatement.
- Indépendante de 38.1/38.2/38.3/38.4 pour le dev (38.2 en review : les endpoints
  visés par les crons ne sont pas des tombstones postes — aucun conflit).
- **Bloquante pour 38.6** (extinction à blanc : les crons étaient la majorité du
  trafic legacy ; sans 38.5 le critère GO est innatteignable).

## Recommandation Modèle Dev

**OPUS** (imposé par l'epic : « opus pour 38.1, 38.2, 38.5 »). Review : fable
(modèle opposé).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (OPUS — imposé par l'epic pour 38.5). Review recommandée : fable.

### Debug Log References

- Tests HÔTE ciblés (php8.4 + sqlite) :
  `php artisan test tests/Architecture/LegacyCronRetirementTest.php tests/Feature/LegacyEmbedRouteRemovedTest.php tests/Architecture/GpoLegacyIsolationTest.php`
  → **9 passed (41 assertions)**.
- Non-régression élargie :
  `php artisan test tests/Architecture/GpoLegacyIsolationTest.php tests/Feature/LegacyCatchallTest.php tests/Architecture/LegacyTombstoneRoutesTest.php tests/Feature/LegacyModuleDhcpTest.php tests/Feature/LegacyModuleBbbTest.php tests/Architecture/LegacyCronRetirementTest.php tests/Feature/LegacyEmbedRouteRemovedTest.php`
  → **43 passed, 17 skipped (315 assertions)**. Les 17 skips = `LegacyModuleBbbTest`
  (désactivé PRÉ-EXISTANT, hors 38.5 — « error handler + exit() stub à retravailler »).
- `bash -n scripts/update.sh` et `bash -n scripts/install.sh` → OK (syntaxe valide).
- `composer dump-autoload` rejoué APRÈS suppression du couple embed (sinon le classmap
  optimisé résout encore les classes trashées ; vendor/ est gitignored → non stagé).
- Échecs PRÉ-EXISTANTS connus hors périmètre (fichiers non touchés) : `Story1614RoutesTest`
  ×3, `FederatedRouteTest` ×1 (non exécutés ici, documentés dans 38.2/38.4).

### Completion Notes List

- **T1 — re-possession SE5 (crons vitaux)** : `scripts/config/sambaedu-system.cron`
  (patron `sambaedu-scheduler.cron`) = `renew_ticket.sh` ×2 (dont `@reboot`) +
  `smbstatus.sh`. `install_system_cron()` (install.sh) + `ensure_system_cron()`
  (update.sh, provision idempotente `diff -q`). ORDRE garanti : `ensure_system_cron`
  appelée dans `main()` AVANT `ensure_legacy_crons_retired` (zéro fenêtre sans ticket).
- **T2 — retrait legacy** : `ensure_legacy_crons_retired()` (update.sh) cible la liste
  EXPLICITE `sambaedu-{web-common,shares,wpkg}` (jamais un glob), `mv` vers
  `/var/backups/sambaedu-legacy-crons/` (suffixe horodaté si collision, JAMAIS `rm -rf`),
  idempotente et rejouée à chaque update (anti-conffile). Greenfield (install.sh) : le
  retrait est joué par le replay `bash update.sh` en fin d'install (après
  `install_system_cron` en phase 8) — pas de duplication de la fonction.
- **T3 — débranchement sec embed** : route `users.groups.legacy-new` retirée
  (web.php) ; `LegacyEmbedController.php` + `LegacyEmbedService.php` supprimés
  (`gio trash`) ; vue `legacy-embed.blade.php` + `legacy/stubs/` CONSERVÉS ; whitelist
  `GpoLegacyIsolationTest` réduite (retrait de `LegacyEmbedService.php`) + docblock à
  jour → test VERT. Grep de clôture : plus aucune référence en code effectif (seuls
  restent 3 commentaires documentaires annotant la suppression, acceptés).
- **T4 — doc** : `docs/audit-dependances-systeme.md` §2.9 (tableau décision PAR
  FONCTION) + ligne renew/smbstatus/check_config + Vague 4 (volet crons soldé,
  make_dhcpd_conf renvoyé 8.3). Runbook QA `docs/qa/domains/legacy-shims.md` :
  section 38.5 append-only (4 scénarios + ops VM + non-régression).
- **T5 — tests** : `LegacyCronRetirementTest` (grep script : fonction déclarée+appelée,
  3 cibles explicites, anti-glob, système avant retrait, cron file vital, no rm -rf) +
  `LegacyEmbedRouteRemovedTest` (Route::has false + class_exists false).

**⚠️ Backlog (T4.3) — À REPORTER PAR HENRI dans `_bmad-output/backlog.data.js`**
(fichier utilisateur NON COMMITTÉ, `project_backlog_split_multifile` : 4 fichiers à
committer ensemble — NON touché par le dev). Deux entrées à ajouter :
  1. **« Stats de fréquentation — chantier produit dédié »** : porter la télémétrie de
     fréquentation sur le signal présence agent (`project_workstation_presence_shutdown_signal`).
     Origine : abandon des crons `stats.php` / `update_stats.php` en 38.5.
  2. **« ENT (sync_cron / mfa_ent / test_ent) — abandonné 38.5 »** : réouvrable en epic
     dédié si un besoin réel émerge sur une instance cible (Q2 tranchée Henri 2026-07-10).

**⚠️ Ops VM MANUELLES (T6 — après merge sur main, JAMAIS depuis un worktree)** — non
exécutées par le dev (working tree host), à jouer sur /vm :
  1. **Fantômes** (`project_inotify_no_delete_sync`) : retirer À LA MAIN
     `app/Http/Controllers/LegacyEmbedController.php` + `app/Services/LegacyEmbedService.php`
     côté VM (inotify ne sync pas les deletes), puis `composer dump-autoload`.
  2. `bash scripts/update.sh` sur /vm (vecteur du retrait) ; vérifier
     `ls /etc/cron.d/` → restent `sambaedu-{scheduler,system,boot-server}` ;
     `/var/backups/sambaedu-legacy-crons/` contient les 3 fichiers retirés.
  3. `php artisan route:cache && php artisan config:cache` + chown www-admin des caches.
  4. H+1 : `sudo -u www-admin klist` (ticket www-sambaedu frais), `/tmp/smbstatus`
     mtime < 1h, page sessions/wallpapers OK.
  5. AC1 finale : access-logs Apache ≥ 1h → plus AUCUN `POST *.php` cron (résiduel
     attendu : `make_dhcpd_conf.sh` gated 8.3 + tombstones postes 38.2). Chute de la
     ligne de base `legacy_catchall_logs` = ATTENDUE (instrument GO 38.6, D3).
  6. Consigner le résultat dans le runbook QA.

### File List

**NOUVEAUX :**
- `scripts/config/sambaedu-system.cron`
- `tests/Architecture/LegacyCronRetirementTest.php`
- `tests/Feature/LegacyEmbedRouteRemovedTest.php`

**MODIFIÉS :**
- `scripts/install.sh` (`install_system_cron()` + appel phase 8)
- `scripts/update.sh` (`ensure_system_cron()` + `ensure_legacy_crons_retired()` + appels main)
- `routes/web.php` (route `users.groups.legacy-new` retirée)
- `tests/Architecture/GpoLegacyIsolationTest.php` (whitelist réduite + docblock)
- `docs/audit-dependances-systeme.md` (§2.9 + ligne crons + Vague 4)
- `docs/qa/domains/legacy-shims.md` (section 38.5 append-only)
- `_bmad-output/implementation-artifacts/38-5-debranchement-crons-embed-legacy.md` (checkboxes, Dev Agent Record, File List, Status)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (ligne 38-5 → review)

**SUPPRIMÉS (via `gio trash`) :**
- `app/Http/Controllers/LegacyEmbedController.php`
- `app/Services/LegacyEmbedService.php`

### Change Log

- 2026-07-10 — Story 38.5 implémentée (débranchement crons + embed legacy) : re-possession
  SE5 des crons vitaux (`sambaedu-system.cron`), retrait idempotent des 3 crons legacy
  (`ensure_legacy_crons_retired`, anti-glob, `mv` réversible), débranchement sec de l'embed
  (route + contrôleur + service supprimés, vue conservée), whitelist archi réduite, doc +
  runbook QA + tests. Status → review.
