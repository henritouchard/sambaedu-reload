# Story 38.2: Tombstones natifs du canal client legacy

Status: review

<!-- Créée le 2026-07-10 (create-story, Epic 38 — epics-extinction-se4.md).
     Périmètre Q4 TRANCHÉ sur mesure lab1 du 2026-07-10 (38-questions.md § Q4). -->

## Story

En tant que responsable du parc,
je veux que chaque route encore appelée par un poste SE4 reçoive une réponse native
terminale correcte,
afin qu'aucun poste ne casse ni n'exécute du HTML d'erreur pendant et après l'extinction.

**Périmètre.** Quatre volets :
1. **Tombstones natifs** (200/204 typés inertes, routes déclarées AVANT le catchall
   `{path}`) pour toutes les routes du canal client legacy encore appelées par les
   postes — inventaire exact et formats en Dev Notes § Table des tombstones.
2. **Exception bornée canal Linux** (Q4 tranchée sur donnée lab1, cf. Dev Notes) :
   `gpo/applications.php` avec `os=linux` → PASSTHROUGH vers le proxy catchall
   (comportement actuel préservé) ; `gpo/network_out.php` et `printers/out_printers.php`
   ne sont PAS tombstonés (restent au catchall). Critère de sortie documenté.
3. **Observabilité d'extinction (D3)** : chaque hit tombstone journalisé (route +
   machine/user + IP + horodatage), agrégeable avec `legacy_catchall_logs` — c'est le
   critère GO de la 38.6.
4. **Retrait du kill-switch** `LEGACY_CONFIG_CHANNEL_ENABLED` + middleware
   `EnsureLegacyConfigChannelEnabled` (la sémantique 410 est remplacée par les
   tombstones) et retrait de l'entrée `noop:` `gpo/shortcuts_out.php` de
   `blocked_legacy_routes` (supersédée par le tombstone natif).

**Doctrine (D1/D2, epics-extinction-se4.md).** Tombstone ≠ canal maintenu : réponse
**terminale, typée et inerte** — jamais une réimplémentation, jamais du HTML d'erreur
sur un endpoint dont la réponse est **exécutée** (le crochet client fait
`curl > x.cmd && call x.cmd` ; le démon Linux fait `eval` du corps). Les tombstones
n'installent rien et n'exécutent rien d'actif (servir du code exécutable sur un canal
non authentifié = structurellement un C2). Le nettoyage des crochets côté poste est
la story 38.3 (agent), pas celle-ci.

## Acceptance Criteria

1. **Routes tombstones natives, typées, inertes.** Chaque route de la Table des
   tombstones (Dev Notes) est déclarée dans `routes/web.php` **AVANT le catchall
   `{path}`**, répond en `Route::match(['GET', 'POST'], …)` (les postes appellent en
   GET et en POST multipart `curl -F`, sans session), avec exactement le statut, le
   corps inerte et le Content-Type spécifiés dans la table :
   - script no-op syntaxiquement valide selon l'OS/interpréteur : commentaire `REM …`
     terminé `\r\n` pour cmd (Windows), `# …` terminé `\n` pour bash (Linux) ;
   - XML vide valide (déclaration XML + élément racine conforme au format legacy :
     `<wpkg/>`, `<profiles/>`, `<packages/>`, `<unattend/>`) pour les `*_xml_out` et
     les XML d'install ;
   - `204 No Content` pour les images (`wallpaper_out`, `shortcuts_out`
     `action=file|icon`) ;
   - JSON vide valide `{}` pour firefox/thunderbird/veyon/associations (Content-Type
     `application/json`, iso legacy).
   Sécurité iso 17.6 (garde-fous d'epic) : `withoutMiddleware(['web'])` (appels machine
   sans session/CSRF), middleware `local.request` + `throttle:300,1`, AUCUN middleware
   d'auth (`auth.v1.workstation`/sanctum/lan-only interdits — postes non enrôlés).

2. **`gpo/applications.php` répond inerte à TOUT (os=windows).** Le moteur legacy se
   ré-appelle lui-même en phase `system` et en acquittement `ret=0` : le tombstone
   répond 200 + script no-op à TOUTE combinaison de paramètres (`action`
   logon/logoff/startup/shutdown, `user`, `machine`, `ret`, `context`, os absent…) —
   aucun 4xx/5xx, aucune branche non couverte. Format du commentaire selon
   `os` : `REM` (défaut, CRLF) / `#` si `os=linux`… sauf que :

3. **Exception bornée — canal Linux vivant (Q4, donnée lab1).**
   `gpo/applications.php` avec `os=linux` (paramètre explicite, GET ou POST) n'est PAS
   tombstoné : le contrôleur délègue au catchall
   (`LegacyCatchallController::handle`) qui proxifie vers le vhost legacy —
   comportement fonctionnel actuel STRICTEMENT préservé, hit loggé par le catchall
   (mesure de l'extinction du canal Linux). `gpo/network_out.php` et
   `printers/out_printers.php` n'ont AUCUNE route native (elles continuent d'atteindre
   le catchall). Le critère de sortie de l'exception est documenté dans le bloc de
   commentaire `routes/web.php` : **extinction mesurée du canal (zéro hit `os=linux` /
   `network_out` / `out_printers` sur la période d'observation 38.6) OU livraison de
   l'agent Linux (post-MVP, `project_linux_no_gpo_http_scripts`)**.

4. **`gpo/del_roam.php` / `gpo/no_roam.php` inchangés.** Ils conservent leurs
   early-returns natifs existants du catchall (redirections 1bis.18f) — aucune route
   tombstone pour eux, aucun diff sur ces chemins (test existant vert).

5. **Observabilité — chaque hit tombstone journalisé (critère GO 38.6).**
   - Migration **additive** sur `legacy_catchall_logs` : `source` (varchar 16,
     default `'catchall'`, indexée), `machine` (varchar 255 nullable), `user_login`
     (varchar 255 nullable). Le catchall existant est INCHANGÉ (le default DB remplit
     `catchall`).
   - Chaque hit tombstone crée une ligne `source='tombstone'` avec `path`, `method`,
     `ip`, `query_string`, `machine` (extrait de `machine` ou `poste`), `user_login`
     (extrait de `user`), `created_at` — valeurs TRONQUÉES à la largeur colonne avant
     insert (entrées non authentifiées ; SQLite ne borne pas les varchar,
     `project_sqlite_tests_no_varchar_enforcement`) — plus une ligne channel
     `legacylog` (`legacy.tombstone.hit`, route + ip + machine/user).
   - La page `admin/legacy-monitor` (`resources/views/pages/admin/legacy-monitor/index.blade.php`)
     affiche la colonne `source` et permet de filtrer dessus (le critère GO 38.6 se lit :
     zéro hit `source='catchall'` sur les routes clients, hits `tombstone` en décroissance).

6. **Kill-switch supprimé.** `LEGACY_CONFIG_CHANNEL_ENABLED` et le middleware
   `EnsureLegacyConfigChannelEnabled` sont RETIRÉS du code :
   - suppression de `app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php` et de
     l'alias `legacy.config.channel` (`app/Http/Kernel.php:87`) ;
   - retrait de `'legacy.config.channel'` des middlewares des 2 routes
     `wpkg/{linux,winget}_out.php` (`routes/web.php:698-712`) — ces 2 routes restent
     natives, protégées `local.request` + `throttle:300,1`, **fonctionnellement
     inchangées** (`WpkgOutRoutesTest` vert tel quel) ;
   - suppression de la clé `legacy_config_channel_enabled` + son bloc de commentaire
     (`config/sambaedu.php:29-41`) et de la ligne `LEGACY_CONFIG_CHANNEL_ENABLED=true`
     de `.env.example` (:39) ;
   - mise à jour du commentaire `routes/api.php:427-435` (il affirme que le middleware
     est « CONSERVÉ ») — commentaire SEULEMENT, aucun bloc de routes API touché
     (`project_api_routes_arch_test_window_trap`) ;
   - nettoyage des gardes d'isolation dans les 4 tests
     `tests/Feature/Wpkg/Deployment/Http/{WingetOutEndpoint,WingetOutSettings,LinuxOutEndpoint,EnsureLocalRequestSettings}Test.php`
     (`Config::set('sambaedu.legacy_config_channel_enabled', true)`) + grep global
     `legacy_config_channel|LEGACY_CONFIG_CHANNEL|X-Legacy-Config-Channel` : zéro
     occurrence code/tests restante ; mentions docs
     (`docs/audit-dependances-systeme.md`, `docs/qa/domains/{filesystem,gpo}.md`,
     `docs/agent/{README,metier}.md`) mises à jour (`feedback_doc_follows_code`).

7. **Entrée `noop:` retirée, convention conservée.** L'entrée
   `'gpo/shortcuts_out\.php' => 'noop:…'` est retirée de
   `config/sambaedu.php` `blocked_legacy_routes` (:70-77) — supersédée par le
   tombstone natif. La convention `noop:` de `LegacyCatchallController` (:90-105)
   RESTE comme mécanisme générique ; les 3 tests `noop` de `LegacyCatchallTest`
   (:105-150) sont RE-POINTÉS sur un path synthétique (ex. `gpo/fake_noop_route.php`
   posé par `Config::set`) car `gpo/shortcuts_out.php` matche désormais la route
   tombstone avant le catchall.

8. **Test d'ordre de routes + garde-fous (patron `WpkgOutRoutesTest`).** Nouveau
   `tests/Architecture/LegacyTombstoneRoutesTest.php` (lecture textuelle de
   `routes/web.php` + offsets) : chaque route tombstone est déclarée, AVANT le
   catchall `{path}`, porte `local.request` + `throttle`, ne porte AUCUN middleware
   d'auth, porte `withoutMiddleware(['web'])`. Tests Feature par famille de format
   (statut + Content-Type exact + corps inerte + ligne `source='tombstone'` en DB +
   POST sans CSRF accepté + passthrough `os=linux`). Non-régression :
   `WpkgOutRoutesTest`, `LegacyCatchallTest` (noop re-pointés), `IpxeNamespaceTest`,
   `IpxeLegacyRoutingNonRegressionTest` verts.

9. **Opérations VM consignées (manuel, avec Henri — hors CI).**
   - `php artisan migrate` (migration additive — jamais auto-jouée,
     `project_vm_migrations_not_auto_applied`) ;
   - retrait de `LEGACY_CONFIG_CHANNEL_ENABLED=false` du `.env` VM (il est ACTIF sur
     la VM — les 2 endpoints wpkg y répondent 410 aujourd'hui et redeviennent
     vivants), puis `config:cache` + `route:cache` + chown www-admin
     (`project_vm_config_cache_not_synced`, `project_route_cache_vm_ephemeral_test_routes`) ;
   - **retrait de la surcharge diagnostique
     `/etc/sambaedu/applications/firefox/logon.windows`** (artefact de l'incident
     Firefox 2026-07-03, « ffdiag v2 ») — opération VM pure, exception assumée au
     garde-fou « ne pas toucher /etc/sambaedu » (c'est NOTRE artefact d'incident, pas
     le paquet) ; **inotify ne sync pas les deletes** : suppression à la main côté VM
     (`trash`, jamais `rm -rf`) ;
   - e2e curls : chaque tombstone répond son 200/204 typé, `applications.php`
     `os=linux` proxifie encore, lignes `source='tombstone'` visibles dans
     `legacy-monitor`.

## Tasks / Subtasks

- [x] Task 1 — Migration observabilité (AC: 5)
  - [x] Migration additive `legacy_catchall_logs` : `source` varchar(16) default `'catchall'` + index, `machine` varchar(255) nullable, `user_login` varchar(255) nullable
  - [x] Modèle `LegacyCatchallLog` : `$fillable` complété
  - [x] Vérifier/adapter le provisioning SQLite de la table dans les tests qui la créent à la main (`IpxeLegacyRoutingNonRegressionTest` + `LegacyCatchallTest`)
- [x] Task 2 — Contrôleur tombstone (AC: 1, 2, 3, 5)
  - [x] `app/Http/Controllers/LegacyTombstoneController.php` : `script` (REM/CRLF ou #/LF selon `os`), `bashScript` (# strict pour le démon autorun Linux), `xml` (racine via `defaults('element', …)`), `noContent` (204), `json` (`{}`), `emptyBody` (200 corps vide text/plain), `shortcuts` (204 sur action file|icon, sinon script) — chaque action journalise le hit AVANT de répondre (méthode privée : ligne DB `source='tombstone'` tronquée + channel `legacylog`)
  - [x] Action `applications` : si `input('os') === 'linux'` → passthrough `app(LegacyCatchallController::class)->handle()` (PAS de log tombstone) ; sinon script no-op (répond 200 à tout : `ret`, `action`, `context`…)
  - [x] Aucun contenu dynamique dans les corps servis (message FIXE uniquement — testé)
- [x] Task 3 — Routes (AC: 1, 3, 4)
  - [x] Bloc « Story 38.2 — Tombstones canal client legacy » dans `routes/web.php`, juste AVANT le catchall final : 18 routes, `Route::match(['GET','POST'], …)`, `['local.request', 'throttle:300,1']`, `->withoutMiddleware(['web'])`, noms `legacy.tombstone.*`
  - [x] `/ipxe/linux/action.php` (bash) déclarée AVANT `/ipxe/{version}/action.php` (`where('version', '[A-Za-z0-9_.-]+')`)
  - [x] Commentaire du bloc : doctrine D1/D2, ORDRE STRICT, exception bornée Linux + critère de sortie, routes exclues
- [x] Task 4 — Retrait kill-switch (AC: 6)
  - [x] Middleware supprimé (gio trash) + alias Kernel + clé/bloc config + ligne `.env.example` ; `'legacy.config.channel'` retiré des 2 routes wpkg ; commentaire `routes/api.php` mis à jour ; 4 tests nettoyés ; grep code/tests ZÉRO ; mentions docs (audit-dependances, agent/{README,metier}, qa/{filesystem,gpo})
- [x] Task 5 — Retrait entrée `noop:` (AC: 7)
  - [x] Entrée `gpo/shortcuts_out\.php` retirée de `blocked_legacy_routes` ; 3 tests noop de `LegacyCatchallTest` re-pointés sur `gpo/fake_noop_route.php`
- [x] Task 6 — Monitor (AC: 5)
  - [x] `legacy-monitor/index.blade.php` : colonne + filtre `source` (groupBy intègre `source`) — page hors travail utilisateur (re-vérifié `git status`)
- [x] Task 7 — Tests (AC: 8)
  - [x] `tests/Architecture/LegacyTombstoneRoutesTest.php` (6 tests : présence, ordre < catchall, local.request+throttle, pas d'auth, withoutMiddleware web, ordre linux<version)
  - [x] `tests/Feature/Legacy/LegacyTombstoneEndpointsTest.php` (27 tests : formats par famille, POST sans CSRF, log DB source+machine/user, troncature, passthrough os=linux, matrice applications, pas d'écho de params)
  - [x] Lancé sur l'HÔTE par filtres ciblés : nouveaux (33✓) + `WpkgOutRoutesTest` + `LegacyCatchallTest` + `IpxeNamespaceTest` + `IpxeLegacyRoutingNonRegressionTest` + 4 tests wpkg (110✓)
- [ ] Task 8 — Application VM + e2e (AC: 9 — manuel avec Henri, hors CI, JAMAIS depuis un worktree) — **NON exécuté (ops VM manuelles, cf. Dev Agent Record)**
  - [ ] Vérifier la boucle inotify + `migrate:status` ; `php artisan migrate`
  - [ ] `.env` VM : retirer `LEGACY_CONFIG_CHANNEL_ENABLED=false` ; `config:cache` + `route:cache` + chown www-admin
  - [ ] Retrait surcharge `/etc/sambaedu/applications/firefox/logon.windows` (VM pure, `trash`)
  - [ ] Curls e2e (tombstones + passthrough linux) + contrôle `legacy-monitor`

## Dev Notes

### Table des tombstones (source : inventaire epic + Content-Types relevés dans le repo legacy `../sambaedu/`)

Toutes : `Route::match(['GET','POST'])`, middleware `local.request` + `throttle:300,1`,
`withoutMiddleware(['web'])`, hit loggé `source='tombstone'`.

| Route | Réponse | Content-Type (iso legacy) |
|---|---|---|
| `/gpo/applications.php` | `os=linux` → **PASSTHROUGH catchall** ; sinon 200 script no-op `REM …\r\n` (inerte à TOUT : action/user/machine/ret/context) | `text/plain` |
| `/gpo/shortcuts_out.php` | `action=file` ou `action=icon` → 204 ; sinon 200 script no-op REM/# selon `os` | `text/plain` / — |
| `/gpo/wallpaper_out.php` | 204 (legacy servait `image/*`) | — |
| `/gpo/veyon_out.php` | 200 `{}` (legacy : JSON, `veyon_out.php:139`) | `application/json` |
| `/gpo/no_internet_out.php` | 200 script no-op (legacy : text/plain, `:47`) | `text/plain` |
| `/gpo/associations_out.php` | 200 `{}` (legacy : `text/json`, `:170`) | `application/json` |
| `/gpo/firefox_out.php` | 200 `{}` (JSON policies, `:14`) | `application/json` |
| `/gpo/thunderbird_out.php` | 200 `{}` (`:12`) | `application/json` |
| `/partages/cloud_out.php` | 200 script no-op (legacy : text/plain, `:17`) | `text/plain` |
| `/wpkg/hosts_xml_out.php` | 200 `<?xml version="1.0" encoding="UTF-8"?>` + `<wpkg/>` (racine legacy = `wpkg`, `hosts_xml_out.php:17`) | `text/xml` |
| `/wpkg/profiles_xml_out.php` | 200 idem, racine `<profiles/>` (`:18`) | `text/xml` |
| `/wpkg/packages_xml_out.php` | 200 idem, racine `<packages/>` (`:28`) | `text/xml` |
| `/wpkg/wpkg_log.php` | 200 corps vide (endpoint puits de logs) | `text/plain` |
| `/wpkg/download_prefix.php` | 200 texte no-op (0 hit lab1 — miroir préfixes Wine) | `text/plain` |
| `/ipxe/linux/action.php` | 200 `# …\n` (le démon `autorun` fait `eval` du corps en boucle — commentaire bash STRICT, jamais `exit`) | `text/plain` |
| `/ipxe/{version}/action.php` | 200 `REM …\r\n` (machine à états install Windows — legacy text/plain `action.php:417`) — déclarer APRÈS la variante linux | `text/plain` |
| `/ipxe/Win10/sysprep.xml.php` | 200 `<?xml…?><unattend/>` | `text/xml` |
| `/ipxe/Win10/unattend.xml.php` | 200 `<?xml…?><unattend xmlns="urn:schemas-microsoft-com:unattend"/>` | `text/xml` |

**Explicitement HORS tombstone** (ne pas créer de route) :
- `gpo/network_out.php`, `printers/out_printers.php` — canal Linux vivant (Q4),
  restent au catchall (proxy + mesure) ;
- `gpo/del_roam.php`, `gpo/no_roam.php`, `gpo/user_profile_stats.php` — early-returns
  natifs existants du catchall (`LegacyCatchallController:44-50`) ;
- `ipxe/Win10/repair.bat.php` et `ipxe/Win10/diskpart.php` — encore consommés par le
  flow WinPE NATIF (`resources/views/ipxe/actions/winpe.blade.php:11`,
  `IpxeWindowsDiskpartController`) — les tombstoner CASSERAIT la réparation Windows ;
- `wpkg/{linux,winget}_out.php` — natifs 17.6, inchangés (AC 6) ;
- `ipxe/sysrescuecd/*`, `ipxe/clonezilla/*` — plus atteignables depuis un boot neuf
  (dhcpd chaîne le natif, port 909 mort) ; un poste « en vol » est acceptable
  (doctrine zéro-prod) — pas de route, catchall/404 suffit.

### Décision de périmètre Q4 (TRANCHÉE le 2026-07-10 — ne PAS re-questionner)

Mesure lab1 étab (~2 semaines de logs Apache, se4fs-0991229y) :
`applications.php` **250 hits**, `shortcuts_out` 32, `firefox_out` 17, `wallpaper_out`
14, `hosts/profiles_xml_out` 5+5 → tombstones nécessaires. `download_prefix.php` **0
hit**, `cloud_out.php` **0 hit** → tombstonés. `out_printers.php` 2 hits +
`network_out.php` 2 hits = **poste Linux actif réel** (172.20.1.101, curl, dernier
2026-06-26) → exception bornée : canal Linux proxifié via catchall, critère de sortie =
extinction mesurée (38.6) ou agent Linux post-MVP.
[Source: _bmad-output/ultradev/38-questions.md#Q4]

### Architecture / existant à réutiliser — NE PAS réinventer

| Brique | Fichier | Rôle pour 38.2 |
|---|---|---|
| Catchall + proxy + log | `app/Http/Controllers/LegacyCatchallController.php` — `handle()` (early-returns :44-50, conventions `gone:`/`noop:` :64-108, proxy :207), `logLegacyAccess()` (:643) | Passthrough `os=linux` = déléguer à `handle()`. Patron de réponse typée = branches `noop:` (:98 : `os === 'linux' ? '#' : 'REM'`). NE PAS modifier le catchall lui-même (38.1 l'a déjà traité). |
| Patron routes machine | `routes/web.php:673-712` (bloc 17.6 `linux_out`/`winget_out`) | Copier le patron exact : chemins littéraux `.php`, `Route::match`, `local.request`+`throttle:300,1`, `withoutMiddleware(['web'])`, commentaire ORDRE STRICT. |
| Catchall final | `routes/web.php:1060-1061` (`{path}` where `.*`) | Les tombstones se placent juste avant. |
| Kill-switch à supprimer | `app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php` ; alias `app/Http/Kernel.php:87` ; config `config/sambaedu.php:29-41` ; `.env.example:39` ; commentaire `routes/api.php:427-435` | AC 6. |
| Entrée noop | `config/sambaedu.php:70-77` | AC 7. |
| Log DB + channel | `app/Models/LegacyCatchallLog.php`, migration `2026_03_20_100000_create_legacy_catchall_logs_table.php`, channel `legacylog` (`config/logging.php:123`) | Migration additive (AC 5) ; le channel existe déjà. |
| Monitor | `resources/views/pages/admin/legacy-monitor/index.blade.php` (Livewire SFC, groupBy method+path) | Ajouter colonne/filtre `source`. |
| CSRF | `app/Http/Middleware/VerifyCsrfToken.php` (`gpo*`, `wpkg*`, `ipxe*`, `partages*` déjà exemptés) | Rien à ajouter — et `withoutMiddleware(['web'])` court-circuite de toute façon. |
| Tests patrons | `tests/Architecture/WpkgOutRoutesTest.php` (textuel + offsets + assertions négatives d'auth) ; `tests/Feature/LegacyCatchallTest.php` (setUp `legacyTmpDir`, tests noop :105-150) | AC 8. |

### Pièges connus (mémoire projet + garde-fous d'epic)

- **Corps exécutés = messages FIXES.** Jamais d'écho d'un paramètre de requête dans un
  corps servi (réflexion dans un script CALLé/eval'é = injection). Le catchall `noop:`
  n'échoit que son message de config — faire pareil (constantes).
- **`applications.php` : l'ack `ret=0` et la phase `system` doivent recevoir 200
  inerte** — le crochet poste enchaîne plusieurs appels par logon (constaté lab1 : 250
  hits) ; toute réponse non-200 ou non-script réintroduit l'incident Firefox
  (`project_firefox_profile_forced_no_dir_trap` : un corps HTML CALLé avorte le batch
  logon ENTIER).
- **Le tombstone shortcuts_out shadow le noop actuel** : les 3 tests noop de
  `LegacyCatchallTest` utilisent `gpo/shortcuts_out.php` posé par `Config::set` — une
  fois la route native déclarée, ces requêtes n'atteignent PLUS le catchall →
  re-pointer sur un path synthétique (AC 7), sinon faux rouges.
- **`/ipxe/{version}/action.php`** : contraindre `{version}` (`[A-Za-z0-9_.-]+`) et
  déclarer APRÈS `/ipxe/linux/action.php` (le littéral doit gagner). Les routes natives
  `/ipxe/*` existantes sont déclarées PLUS HAUT dans le fichier → aucune collision
  (Laravel matche dans l'ordre), mais `IpxeNamespaceTest` doit rester vert.
- **`LEGACY_CONFIG_CHANNEL_ENABLED=false` est ACTIF sur le .env de la VM** (les tests
  wpkg s'en isolent explicitement — cf. leurs commentaires) : après retrait du
  middleware, `linux_out`/`winget_out` redeviennent vivants sur la VM. C'est le
  comportement CIBLE de l'epic (les tombstones remplacent la sémantique 410), à
  annoncer dans les notes de livraison + nettoyer la variable du `.env` VM (Task 8).
- **Routes réelles ajoutées ⇒ `route:cache` + chown sur la VM**
  (`project_route_cache_vm_ephemeral_test_routes`) ; config modifiée ⇒ `config:cache`
  (`project_vm_config_cache_not_synced`).
- **Migrations jamais auto-jouées sur la VM** (`project_vm_migrations_not_auto_applied`) :
  `migrate:status` avant tout e2e ; la migration 38.2 est additive (pas de down risqué).
- **Provisioning SQLite de `legacy_catchall_logs` dans les tests** :
  `IpxeLegacyRoutingNonRegressionTest` (et consorts) créent la table à la main en
  SQLite — ajouter les 3 colonnes à ce provisioning, sinon les inserts du code neuf
  échouent en test. Et SQLite n'applique PAS les varchar
  (`project_sqlite_tests_no_varchar_enforcement`) : tronquer en PHP (`Str::limit`/
  `substr`) avant insert, et le tester.
- **`routes/api.php` : commentaire seulement** — tout nouveau bloc API doit aller
  après le groupe 16.12 (`project_api_routes_arch_test_window_trap`) ; cette story ne
  touche AUCUNE route API.
- **Ne PAS toucher** : `agent/**` (pas de bump `agent/shared/version.go` — le
  nettoyage poste = 38.3), `/etc/sambaedu` hors la surcharge firefox (exception actée
  AC 9), `gestion_gpo.php`/GPO SYSVOL, le catchall 38.1 (`abort(404)` D4),
  `scripts/{update,setupApache}.sh`.
- **Working tree utilisateur** : ne pas toucher aux fichiers non committés listés au
  lancement (resources/views/pages/** modifiés, `tabs.blade.php`, `WithReturnBack.php`,
  `backlog.data.js`, `app.css`) — la page `legacy-monitor` n'en fait pas partie
  (vérifié), re-vérifier `git status` avant édition.
- **Tests = HÔTE php8.4 + sqlite** (`project_phpunit_test_env_host_vs_vm`), par
  filtres ciblés (`project_vm_phpunit_bulk_run_false_failures`). VM lecture seule
  pendant le dev ; jamais d'interaction VM depuis un worktree.

### Project Structure Notes

- Nouveau : `app/Http/Controllers/LegacyTombstoneController.php` (voisin de
  `LegacyCatchallController` — même famille), 1 migration additive,
  `tests/Architecture/LegacyTombstoneRoutesTest.php`,
  `tests/Feature/Legacy/LegacyTombstoneEndpointsTest.php` (dossier
  `tests/Feature/Legacy/` existant).
- Modifiés : `routes/web.php` (bloc tombstones avant catchall + middlewares wpkg),
  `app/Http/Kernel.php`, `config/sambaedu.php`, `.env.example`,
  `app/Models/LegacyCatchallLog.php`, page `legacy-monitor`, commentaire
  `routes/api.php`, 4 tests wpkg, `LegacyCatchallTest`, docs (mentions kill-switch).
- Supprimé : `app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php`.
- AUCUN changement : `agent/**`, `legacy/modules/*`, scripts shell, `routes/api.php`
  (hors commentaire).

### References

- [Source: _bmad-output/planning-artifacts/epics-extinction-se4.md#Story-38.2] — ACs d'origine, D1-D4, Overview (inventaire routes), garde-fous d'epic
- [Source: _bmad-output/ultradev/38-questions.md#Q4] — décision de périmètre (mesure lab1 2026-07-10) ; #Q1 (tombstones purs, tranchée)
- [Source: app/Http/Controllers/LegacyCatchallController.php:44-108,207,643] — early-returns, conventions gone:/noop:, proxy, logLegacyAccess
- [Source: routes/web.php:673-712,1049-1061] — patron 17.6 + catchall final
- [Source: config/sambaedu.php:29-41,70-77] — kill-switch + entrée noop à retirer
- [Source: app/Http/Kernel.php:83,87] — alias local.request / legacy.config.channel
- [Source: app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php] — à supprimer
- [Source: database/migrations/2026_03_20_100000_create_legacy_catchall_logs_table.php] — schéma à étendre
- [Source: tests/Architecture/WpkgOutRoutesTest.php] — patron test d'ordre/garde-fous
- [Source: tests/Feature/LegacyCatchallTest.php:105-150] — tests noop à re-pointer
- [Source: ../sambaedu/gpo/*.php, ../sambaedu/wpkg/*_xml_out.php, ../sambaedu/ipxe/Win10/action.php] — Content-Types et racines XML legacy (repo legacy, lecture seule)
- [Source: _bmad-output/implementation-artifacts/38-1-relocalisation-statiques-ipxe-catchall-404.md] — story précédente (catchall 404 D4, patrons de tests, ops VM)

## Dépendances

- **Dépend de 38.1 (mergée sur main — vague 1)** : le catchall dégrade en 404 loggé
  quand `legacy_path` est absent (D4) et les statiques `/ipxe` sont déjà hors legacy.
  Les tests passthrough s'appuient sur ce comportement.
- **Indépendante de 38.3 et 38.4** (séquencement d'epic : 38.1→38.4 indépendantes
  entre elles) — développable en parallèle. L'e2e « zéro hit » final de 38.3 suppose
  les tombstones en place, pas l'inverse.
- **Aval** : bloquante pour 38.6 (le critère GO se mesure sur les logs tombstones +
  catchall) ; l'exception Linux est ré-examinée en 38.6 (critère de sortie AC 3).

## Recommandation Modèle Dev

**opus** — imposé par l'epic (« Reco dev : … opus pour 38.1, 38.2, 38.5 »,
epics-extinction-se4.md, Garde-fous d'epic). Profil : PHP/Laravel pur (routes,
contrôleur simple, migration additive, suppression de middleware) sans Go ni
Kerberos ; le risque est la précision des formats inertes et l'exhaustivité du
nettoyage kill-switch — couverts par la table et les greps prescrits ci-dessus.
Review par le modèle opposé (fable) selon le cycle ultradev.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story, imposé par l'epic).

### Debug Log References

Tests HÔTE (php 8.4.5 + SQLite :memory:) :
- `tests/Architecture/LegacyTombstoneRoutesTest.php` — 6 passed.
- `tests/Feature/Legacy/LegacyTombstoneEndpointsTest.php` — 27 passed (294 assertions).
- Non-régression `tests/Feature/LegacyCatchallTest.php` + `tests/Architecture/WpkgOutRoutesTest.php` + `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` + `tests/Architecture/IpxeNamespaceTest.php` + `tests/Feature/Wpkg/Deployment/Http/` — 110 passed.
- Grep `legacy_config_channel|LEGACY_CONFIG_CHANNEL|X-Legacy-Config-Channel|legacy.config.channel` sur `app/ config/ routes/ tests/ .env.example` → ZÉRO occurrence.

**Échecs pré-existants hors scope 38.2 (fichiers non touchés par la story)** :
`tests/Architecture/Story1614RoutesTest.php` (×3 — cherche des routes `/sections`, `/by-ou`, `/{guid}` absentes de `routes/web.php`) et `tests/Architecture/FederatedRouteTest.php::federated_namespace_is_domain_neutral` (littéral `controlhub` dans `app/Auth/Federated`). La cible « federated callback déclaré avant catchall » reste VERTE — l'insertion du bloc tombstones entre `auth.federated.callback` et le catchall n'a rien cassé.

### Completion Notes List

- 18 routes tombstones déclarées juste AVANT le catchall `{path}` ; corps INERTES et FIXES (aucun écho de paramètre de requête — testé `tombstone_body_never_reflects_request_parameters`).
- `/ipxe/linux/action.php` (bash `#`, action `bashScript` — toujours bash car le démon `autorun` fait `eval` du corps) déclarée AVANT `/ipxe/{version}/action.php` (cmd `REM`, `where('version','[A-Za-z0-9_.-]+')`).
- Exception bornée Q4 : `gpo/applications.php?os=linux` → passthrough `LegacyCatchallController::handle()` (loggé par le catchall, source ≠ tombstone) ; `network_out`/`out_printers` sans route native. Critère de sortie documenté dans le commentaire du bloc `routes/web.php`.
- Observabilité : migration additive `2026_07_10_120000_add_source_to_legacy_catchall_logs_table.php` (`source` default `catchall` +index, `machine`/`user_login` nullable) ; hits tombstone en `source='tombstone'` + channel `legacylog` (`legacy.tombstone.hit`) ; valeurs tronquées en PHP (`mb_substr`) avant insert (SQLite ne borne pas les varchar). `machine` extrait de `machine` puis fallback `poste` ; `user_login` de `user`.
- Kill-switch RETIRÉ intégralement (middleware supprimé via `gio trash`, alias Kernel, clé+bloc config, ligne `.env.example`, commentaire `routes/api.php`, 2 routes wpkg dé-gatées mais fonctionnellement inchangées, 4 tests wpkg nettoyés). Commentaires historiques 27.5/27.14 dans `routes/web.php` reformulés pour ne plus référencer la variable.
- Entrée `noop: gpo/shortcuts_out` retirée de `blocked_legacy_routes` ; convention `noop:` du contrôleur CONSERVÉE ; 3 tests noop de `LegacyCatchallTest` re-pointés sur `gpo/fake_noop_route.php` (sinon le tombstone shadow le catchall → faux vert).
- `legacy-monitor` : colonne « Origine » + filtre `source` (badge success/error), groupBy `source, method, path`.

### Ops VM MANUELLES restantes (Task 8 — hors CI, avec Henri, JAMAIS depuis un worktree)

1. Vérifier la boucle inotify + `migrate:status`, puis `php artisan migrate` (migration additive, jamais auto-jouée sur la VM).
2. `.env` VM : retirer la ligne `LEGACY_CONFIG_CHANNEL_ENABLED=false` (ACTIVE sur la VM → `linux_out`/`winget_out` y répondent 410 aujourd'hui et redeviennent vivants/natifs), puis `php artisan config:cache` + `php artisan route:cache` + `chown www-admin` sur `bootstrap/cache/*`.
3. Retrait de la surcharge diagnostique `/etc/sambaedu/applications/firefox/logon.windows` (artefact incident « ffdiag v2 » — exception assumée au garde-fou /etc/sambaedu). Suppression MANUELLE côté VM via `trash`/`gio trash` (JAMAIS `rm -rf`) — inotify ne sync pas les deletes.
4. Curls e2e : chaque tombstone répond son 200/204 typé ; `applications.php?os=linux` proxifie encore ; lignes `source='tombstone'` visibles dans `legacy-monitor`.

### File List

**Nouveaux**
- `app/Http/Controllers/LegacyTombstoneController.php`
- `database/migrations/2026_07_10_120000_add_source_to_legacy_catchall_logs_table.php`
- `tests/Architecture/LegacyTombstoneRoutesTest.php`
- `tests/Feature/Legacy/LegacyTombstoneEndpointsTest.php`
- `docs/qa/domains/legacy-shims.md`

**Modifiés**
- `routes/web.php` (bloc tombstones avant catchall + retrait `legacy.config.channel` des 2 routes wpkg + reformulation 2 commentaires historiques)
- `routes/api.php` (commentaire seulement)
- `app/Http/Kernel.php` (retrait alias `legacy.config.channel`)
- `config/sambaedu.php` (retrait clé+bloc `legacy_config_channel_enabled` + entrée noop `shortcuts_out`)
- `.env.example` (retrait `LEGACY_CONFIG_CHANNEL_ENABLED`)
- `app/Models/LegacyCatchallLog.php` (`$fillable` : source, machine, user_login)
- `resources/views/pages/admin/legacy-monitor/index.blade.php` (colonne + filtre `source`)
- `tests/Feature/LegacyCatchallTest.php` (3 tests noop re-pointés + colonnes provisioning SQLite)
- `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` (colonnes provisioning SQLite)
- `tests/Feature/Wpkg/Deployment/Http/{LinuxOutEndpoint,WingetOutEndpoint,WingetOutSettings,EnsureLocalRequestSettings}Test.php` (retrait garde kill-switch)
- `docs/audit-dependances-systeme.md`, `docs/agent/README.md`, `docs/agent/metier.md`, `docs/qa/domains/filesystem.md`, `docs/qa/domains/gpo.md` (mentions kill-switch → tombstones)

**Supprimés**
- `app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php`

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-07-10 | 0.1 | Création de la story (create-story, périmètre Q4 acté sur mesure lab1). Status → ready-for-dev. | SM (claude-fable-5) |
| 2026-07-10 | 1.0 | Implémentation intégrale (18 tombstones + migration additive + retrait kill-switch + retrait noop + monitor source). 33 tests neufs + 110 non-régression verts (HÔTE). Status → review. Ops VM consignées. | Dev (claude-opus-4-8) |
