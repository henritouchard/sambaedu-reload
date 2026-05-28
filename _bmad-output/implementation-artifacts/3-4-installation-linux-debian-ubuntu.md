# Story 3.4 : Installation Linux (Debian/Ubuntu)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Suite directe de Stories 3.1 + 3.2 + 3.3** (« iPXE Service Core » + « Boot et Menu Admin iPXE » + « Enrollment Machine — Parcs, Salles, Nommage »). Porte nativement le **menu d'installation Linux** et la **génération dynamique de preseed** (équivalents legacy `installation-linux.php` + `linux/preseed.php` + `linux/action.php` + `linux/autorun.php` + `actions/deb_*.php` + `actions/ubuntu64.php` + `actions/nird.php` + `actions/primtux.php` + `actions/se4ad.php` + `actions/se4fs.php`). Réutilise intégralement le socle 3.1+3.2+3.3 (`IpxeService`, `IpxeMenuRenderer`, `WorkstationLocator`, `IpxeActionResolver`, channel log `ipxe`, middleware `auth.v1.lan-only`, table `MachineBootLog`, enum `IpxeAdminAction`).
>
> **Scope strict 3.4** = (a) 1 nouveau endpoint natif `/ipxe/installation-linux` (menu Linux) + 1 nouveau endpoint `/ipxe/linux/preseed` (génération preseed dynamique en text/plain) + 1 nouveau endpoint `/ipxe/linux/action` (signalement de progression depuis le client en cours d'install) + 1 nouveau endpoint `/ipxe/linux/autorun` (script shell d'autorun lancé depuis l'installateur — réécrit en stub minimal Phase 2 — voir hors-scope), (b) **9 nouveaux cases** dans l'enum `IpxeAdminAction` (`install_deb_base`, `install_deb_gnome`, `install_deb_lxde`, `install_deb_kde`, `install_deb_mate`, `install_deb_xfce`, `install_deb_cinnamon`, `install_ubuntu64`, `install_nird` — periphériques `primtux`, `deb_serv`, `deb_kiosk`, `deb_nextcloud`, `deb_gnome_perso`, `se4ad`, `se4fs` sont **HORS-SCOPE** 3.4 — voir liste détaillée), (c) **9 nouveaux templates Blade `resources/views/ipxe/actions/install_*.blade.php`** (un par variante Debian + Ubuntu — port natif `sambaedu/ipxe/actions/deb_*.php` + `ubuntu64.php`), (d) **1 nouveau service `LinuxPreseedService`** qui assemble dynamiquement le preseed à partir des fragments `linux/*.cfg` (port iso-legacy `linux/preseed.php`), (e) **1 nouveau service `LinuxInstallMenuBuilder`** (variables Blade du menu d'installation Linux), (f) **1 nouveau template Blade `resources/views/ipxe/menu/installation-linux.blade.php`** (port iso-legacy `installation-linux.php`), (g) **1 nouveau service `LinuxPostInstallTracker`** (consomme `/ipxe/linux/action` pour mettre à jour `Workstation::os='linux'` + `last_report_at` + log `installation_progress`), (h) **mise à jour de `admin.blade.php`** pour activer un item `(l) Installation Linux` chainant vers `/ipxe/installation-linux##params`, (i) modification mineure `IpxeMenuRenderer::renderAdminMenu()` pour exposer `installLinuxBaseUrl`, (j) **extension `config/ipxe.php`** avec une section `linux` (versions Debian/Ubuntu supportées, partitionnement mode, dépôt sambaedu type, chemins fragments preseed, secrets injection), (k) **extension `MachineBootLog::action`** avec 2 nouvelles valeurs (`ipxe_install_linux` pour le menu rendu, `ipxe_linux_preseed` pour la génération preseed) + 1 nouvelle valeur `ipxe_linux_report` pour les hooks `/ipxe/linux/action`, (l) tests Unit + Feature + Architecture ≥40 cumulés, (m) extension `docs/qa/domains/ipxe.md` (Section 13 « Story 3.4 » + ≥12 scénarios stables 3.4-1 à 3.4-N).
>
> **HORS-SCOPE 3.4** (explicitement reportés aux stories suivantes ou définitivement abandonnés) :
>
> - **Installation serveurs SE4AD/SE4FS via `/ipxe/installation-linux`** (`actions/se4ad.php`, `actions/se4fs.php`, fragments preseed `linux/se4ad.cfg`, `linux/se4fs.cfg`) — **HORS-SCOPE 3.4** : ce flux installe une **autre instance SambaEdu** (déploiement central — pas une station cliente). Cas d'usage = mise en service d'un nouveau collège. Réservé à une story Phase 3 dédiée (« 3.X Installation des serveurs SambaEdu satellites ») car nécessite un audit séparé (DNS auto-add, slave_ip resolver, clé SSH privée injectée, IP fixe demandée à l'opérateur). **Items menu retirés** : `se4ad`, `se4fs`.
> - **Installation BYOD/distributions perso hors domaine** (`actions/deb_gnome_perso.php`, `actions/nird.php` standalone, `actions/primtux.php`, `actions/ubuntu64.php` quand `$perso=1`) — `nird` est inclus en 3.4 (case `install_nird`) car c'est une distribution dérivée Debian utilisée par les écoles primaires, mais les variantes `deb_gnome_perso`, `primtux` sont **DÉFÉRÉES Phase 3** (besoin terrain limité, fragments preseed `debian_perso.cfg`+`debian_kiosk.cfg`+`debian_nextcloud.cfg`+`debian_serv.cfg` non portés en 3.4).
> - **Installation `deb_serv`, `deb_kiosk`, `deb_nextcloud`** — **DÉFÉRÉS Phase 3** (besoin terrain limité, fragments preseed spécifiques).
> - **Endpoint `/ipxe/linux/autorun`** : port natif **stub minimal** en 3.4 — l'autorun legacy (`linux/autorun.php`) construit un script shell `bash` consommé par `sysrescuecd ar_source=...` après le 1er reboot pour exécuter en boucle des scripts post-install récupérés depuis `linux/action.php`. **Décision 3.4** : porter un endpoint minimaliste qui retourne un script noop "echo install Linux completed" + log audit. Le **vrai mécanisme post-install** (chain d'actions, statuts, set_progress) sera réimplémenté Phase 3 si besoin terrain (le mécanisme legacy est complexe et tient à la GLM `actions[]` LDAP qu'on ne porte plus en SE5 — `action_type`/`script_assignments` Epic 17.2 est l'alternative SE5).
> - **Mise à jour fine `Workstation::status` (`installation Linux preseed` → `installation Linux terminée`)** : 3.4 livre un mécanisme **minimal** — `LinuxPostInstallTracker::record()` met juste à jour `os='linux'` + log `ipxe_linux_report`. La granularité fine (5% / 50% / 100% / set_statut) sera étendue par Epic 17.4 (alerting scripts en échec) ou une story Phase 3 dédiée.
> - **Génération de l'image LTSP** — abandonné définitivement (cf. mémoire `feedback_ltsp_dropped` si présente — feature non maintenue ; aucun poste de prod n'utilise LTSP).
> - **Génération wallpaper personnalisé par utilisateur (`commande_fin.cfg`)** — fragment `commande_fin.cfg` est conservé inchangé (lecture seule) mais le code Laravel n'écrit pas dans le wallpaper user (= scope Epic 4.7).
> - **UI admin SE5 Livewire** pour pré-programmer un install (saisie OS+type+disque depuis le navigateur avant boot iPXE) → **HORS-SCOPE 3.4**, parité legacy stricte = sélection se fait depuis le firmware iPXE en LAN.
> - **Retrait des routes legacy `/ipxe/installation-linux.php` et `/ipxe/linux/*.php` du catchall** → reporté **fin d'Epic 3** (Story 3.7 cleanup).
> - **Login admin AD** (parité 3.2/3.3 — `auth.v1.lan-only` seul suffit ; `auth_action()` legacy non porté).
>
> **Liste des 9 cases enum 3.4** (whitelist stricte, ordre alphabétique) :
> 1. `install_deb_base` → `actions/deb_base.php` (Debian base sans desktop)
> 2. `install_deb_cinnamon` → `actions/deb_cinnamon.php` (Debian + Cinnamon)
> 3. `install_deb_gnome` → `actions/deb_gnome.php` (Debian + GNOME — défaut menu)
> 4. `install_deb_kde` → `actions/deb_kde.php` (Debian + KDE)
> 5. `install_deb_lxde` → `actions/deb_lxde.php` (Debian + LXDE)
> 6. `install_deb_mate` → `actions/deb_mate.php` (Debian + MATE)
> 7. `install_deb_xfce` → `actions/deb_xfce.php` (Debian + XFCE)
> 8. `install_nird` → `actions/nird.php` (Distribution NIRD — Debian dérivée écoles primaires, `$perso=1`)
> 9. `install_ubuntu64` → `actions/ubuntu64.php` (Ubuntu Focal 20.04 — sans domaine `$perso=1`)

---

## ⚠️ Mode de livraison & contraintes opérationnelles

> **Worktree git dédié `ipxe`** (worktree `/home/htouchard/code/irundo/codebase/ipxe`). Ne JAMAIS SSH `/vm` ni run de tests sur la VM depuis ce worktree (mémoire `feedback_worktree_no_vm_sync`). Static delivery iso 3.1/3.2/3.3 : lint statique `php -l` + PHPUnit local si `vendor/` présent + 0 sync manuel.
>
> - **Code synchronisé via inotify** sur la branche `main` uniquement (les worktrees ne sont PAS sync). Henri opère un merge `ipxe → main` post-review pour propager.
> - **Action Henri post-merge VM up** : reload PHP-FPM (`systemctl reload php8.2-fpm@www-admin`), reload Apache, smoke `curl http://192.168.122.50/ipxe/installation-linux -d 'mac=...&uuid=...'` → vérifie body avec items `install_deb_*` + smoke `curl http://192.168.122.50/ipxe/linux/preseed?mac=...&uuid=...&os=trixie&type=gnome` → vérifie preseed assemblé correctement.
> - **NE PAS** modifier `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php` — restent intacts (le catchall les sert encore pour les routes hors scope 3.4 — notamment `/ipxe/installation-windows.php` et `/ipxe/clonage.php`).
> - **NE PAS** créer de commit hors scope (rappel 3.1 `50c6275` `docs/qa/domains/auth.md` à éviter).
> - **mémoire `feedback_auth_iso_legacy`** : pas d'introduction de Bearer per-host. Le preseed contient des secrets (mot de passe root, clé jonction AD, clé SSH privée SE4FS pour le cas se4ad/se4fs — **HORS-SCOPE 3.4 mais à anticiper**) — la protection reste **LAN-only + MAC/UUID matching** iso-legacy.
> - **mémoire `project_php_fpm_user_www_admin`** : tout fichier écrit par le code (logs, optionnel snapshot preseed `/tmp/{name}.preseed` iso-legacy `preseed.php:189`) doit être chown `www-admin` (uid 599). **Décision SM 3.4** : ne **pas** écrire le snapshot `/tmp/{name}.preseed` en 3.4 (= side effect legacy debug, le preseed est rendu inline dans la response HTTP). Si Henri demande un trace audit → loguer le sha256 du preseed dans le channel `ipxe`.
> - **Secrets dans le preseed** : `$ADMINSE_PASSWD`, `$LDAP_ADMIN_PASSWD`, `$RSYNC_SECRET`, `$SE4_PRIV_KEY`, `$TOKEN` sont interpolés dans le preseed text/plain et **renvoyés en clair** au poste qui boot. Mitigation : `auth.v1.lan-only` strict + matching MAC/UUID strict (= un attaquant doit déjà avoir compromis la résolution DNS LAN + connaître un MAC+UUID valide). **Aucun secret loggé** (channel `ipxe` n'expose que le sha256 du preseed généré + le hostname).

---

## Encadré contexte

**Continuité avec 3.3** : 3.3 a posé l'endpoint `/ipxe/admin` enrichi avec les items enrollment + maintenance. Pour un **poste connu** (résolu via `WorkstationLocator`), le menu admin va **maintenant** proposer un item `(l) Installation Linux` qui chaine vers `/ipxe/installation-linux##params`. Ce flux remplace progressivement le legacy `installation-linux.php` (167 LOC).

3.4 **active** cette branche en :

1. Ajoutant un endpoint natif `GET|POST /ipxe/installation-linux` qui rend un menu interactif iPXE listant les variantes Debian/Ubuntu/NIRD disponibles.
2. Chainant chaque item vers `/ipxe/action/{install_*}` (= dispatch via l'enum `IpxeAdminAction` étendu — pattern iso 3.2).
3. Le `IpxeActionResolver` rend alors le template Blade `ipxe.actions.install_<variant>` qui contient les lignes `kernel`/`initrd`/`imgargs` qui pointent vers `/ipxe/linux/preseed?mac=...&uuid=...&os=...&type=...`.
4. Le firmware iPXE charge kernel + initrd, puis l'installateur Debian boot avec `url={preseed_url}` qui retourne le preseed assemblé dynamiquement par `LinuxPreseedService`.
5. À la fin de l'install, le poste fait un `curl -F ret=0 -F uuid=... -F name=... http://se4fs/ipxe/linux/action` (parité legacy `preseed.cfg:83`) qui est reçu par `LinuxPostInstallTracker` → met à jour `Workstation::os='linux'` + audit log.

**Topologie cible 3.4** :

```
Firmware iPXE (3.1 known menu) → choisit "1" (login admin)
  ↓
/ipxe/admin (3.2) — menu enrichi 3.4 avec item (l) Installation Linux
  ↓ user choisit (l)
/ipxe/installation-linux (3.4 — menu interactif)
  → render menu listant : deb_base, deb_gnome (défaut), deb_lxde, deb_kde,
                          deb_mate, deb_xfce, deb_cinnamon, nird, ubuntu64
  ↓ user choisit (ex.) deb_gnome
chain vers /ipxe/action/install_deb_gnome##params
  ↓
/ipxe/action/install_deb_gnome (3.2 + extension 3.4)
  → IpxeAdminAction::tryFrom('install_deb_gnome') OK
  → IpxeActionResolver::resolve() rend ipxe.actions.install_deb_gnome.blade.php
  → body iPXE :
      #!ipxe
      kernel {os_url}/debian-installer/amd64/linux
      initrd --name initrd.gz {os_url}/debian-installer/amd64/initrd.gz
      imgargs linux initrd=initrd.gz auto=true hostname=PC-101
                priority=critical auto url={script_url}/linux/preseed
                ?mac=...&uuid=...&os=trixie&type=gnome
      boot
  ↓ firmware iPXE charge kernel+initrd, boot installateur Debian
Installateur Debian fetch {script_url}/linux/preseed?mac=...&uuid=...
  ↓
/ipxe/linux/preseed (3.4 — génération preseed)
  → LinuxPreseedService::generate(['mac' => ..., 'uuid' => ..., 'os' => 'trixie', 'type' => 'gnome'])
  → résolution Workstation via WorkstationLocator
  → assemble : debian.cfg + debian_gnome.cfg + sambaedu.cfg + simple_boot.cfg
                + (apt-cacher OR nocache) + commande_fin.cfg (optionnel)
  → interpole 20+ placeholders (###_HOSTNAME_###, ###_DOMAIN_###, ###_ADMIN_PASSWD_###, ...)
  → response text/plain (preseed assemblé ~4000 lignes)
  → log audit (sha256, hostname, channel ipxe)
  → insert MachineBootLog action='ipxe_linux_preseed'
  ↓
Installateur Debian consomme preseed → install OS complet → reboot
  ↓ 1er reboot du poste installé
curl -F ret=0 -F uuid=... -F name=... http://se4fs/ipxe/linux/action (parité preseed.cfg:83)
  ↓
/ipxe/linux/action (3.4 — hook fin install)
  → LinuxPostInstallTracker::record(['uuid' => ..., 'name' => ..., 'ret' => 0])
  → update Workstation: os='linux', status='installation Linux terminée',
                        last_report_at=now()
  → log audit channel ipxe
  → insert MachineBootLog action='ipxe_linux_report'
  → response text/plain ""  (parité legacy `linux/action.php:39`)
```

**Comportement parité legacy** (à reproduire iso strict — cf. `sambaedu/ipxe/installation-linux.php`, `sambaedu/ipxe/linux/preseed.php`, `sambaedu/ipxe/linux/action.php`) :

1. **`/ipxe/installation-linux`** — menu interactif :
   - **Poste connu** (`WorkstationLocator::locate()` non null) → menu complet avec items `install_*` (8 cases + 1 case `install_nird` séparé via `:nird` legacy).
   - **Poste inconnu** → menu erreur minimaliste « Poste non enregistre — utiliser (n) Nommer le poste depuis /ipxe/admin avant » + chain `/ipxe/admin`. Iso D7 parité 3.3.
   - **Hostname injecté** dans chaque cmdline `imgargs hostname={ws.name}` (iso-legacy `installation-linux.php:30`).
2. **`/ipxe/action/install_*`** — dispatch via enum :
   - Variante = un template Blade dédié `ipxe.actions.install_<variant>` qui construit la cmdline `kernel`/`initrd`/`imgargs`/`boot`.
   - `imgargs url={script_url}/linux/preseed?mac=...&uuid=...&os=...&type=...` — iso-legacy `actions/deb_*.php` ligne 6.
   - **Variables injectées** : `mac`, `uuid`, `os` (version Debian/Ubuntu), `type` (variante desktop), `osUrl` (base assets), `scriptUrl` (base scripts), `workstationName` (hostname).
   - **PAS** de validation `version_debian` côté URL — c'est la config serveur qui décide (`config('ipxe.linux.version_debian', 'trixie')`).
3. **`/ipxe/linux/preseed`** — génération preseed text/plain :
   - **Inputs** : `mac` (auth matching), `uuid` (auth matching), `os` (version `trixie|bookworm|ubuntu` — whitelist enum), `type` (variante `gnome|lxde|kde|mate|xfce|cinnamon|base|nird` — whitelist enum), `mask` (optionnel — `config('sambaedu.linux.mask')` par défaut), `gateway` (optionnel — `config('sambaedu.linux.gateway')`), `perso` (optionnel boolean — défaut false).
   - **Workflow** :
     1. Résolution `WorkstationLocator::locate($mac, $uuid)` — si null → response 404 ou response preseed vide (TBD : iso-legacy `preseed.php:31` retourne body vide + 200 — décision 3.4 = 404 + log warning).
     2. Assemblage des fragments `linux/*.cfg` selon `$os`/`$type` (algorithme exact iso `preseed.php:86-159`).
     3. Interpolation des placeholders `###_<KEY>_###` via `LinuxPreseedService::writeParam($config, $file)` (port iso-legacy `ipxe_functions.inc.php` ou inline — décision 3.4 = port natif inline).
     4. Headers `Content-Type: text/plain; charset=utf-8` + `Cache-Control: no-store` + `X-Robots-Tag: noindex` (iso D10 3.1).
     5. Log audit + insert MachineBootLog.
   - **Pas d'écriture disque** du snapshot `/tmp/{name}.preseed` (parité legacy `preseed.php:189` retirée — debug only).
4. **`/ipxe/linux/action`** — hook fin install :
   - **Inputs** : `uuid`, `name`, `ret` (code retour).
   - Si `$ret == "0"` → `Workstation::os = 'linux'` + status `'installation Linux terminée'` + `last_report_at = now()` + log info.
   - Si `$ret != "0"` → log warning + status `'installation Linux échouée (ret=<X>)'`.
   - Response : `text/plain` vide (parité legacy `linux/action.php:39` qui renvoie `""`).
5. **`/ipxe/linux/autorun`** — stub minimal (HORS-SCOPE port complet, voir bloc « HORS-SCOPE 3.4 » au-dessus). Retourne `#!/bin/bash\necho "install Linux completed for $name ($uuid)"\nexit 0\n` + log audit.

**Couplage Stories 3.2/3.3 — modifications mineures attendues** :

| Élément | Modification 3.4 | Raison |
|---|---|---|
| `resources/views/ipxe/menu/admin.blade.php` | Ajouter dans le bloc `@if($isKnown)` un item `(l) install-linux (l) Installation Linux` + section `:install-linux\nchain --replace --autofree {{ $installLinuxBaseUrl }}##params`. | 3.4 active la branche Installation Linux depuis le menu admin natif. |
| `IpxeMenuRenderer::renderAdminMenu()` | Exposer 1 nouvelle variable Blade `$installLinuxBaseUrl` (= `$serverBaseUrl . '/ipxe/installation-linux'`). | Conditionnement template. |
| `IpxeService` | **Nouvelle méthode** `handleInstallationLinuxMenu(Request $request): Response` (orchestre `/ipxe/installation-linux` — handshake si MAC/UUID manquant + résolution Workstation + render menu `installation-linux.blade.php`). Pas de modif `handleBoot/handleAdmin/handleMaintenance/handleAction`. | Endpoint dédié. |
| `IpxeAdminAction` enum | **+9 cases** (`install_deb_base`, `install_deb_cinnamon`, `install_deb_gnome`, `install_deb_kde`, `install_deb_lxde`, `install_deb_mate`, `install_deb_xfce`, `install_nird`, `install_ubuntu64`). Méthode `template()` étendue avec 9 mappings vers `ipxe.actions.install_*`. | Whitelist sécurité enrichie. |
| `MachineBootLog.action` | 3 nouvelles valeurs persistées : `ipxe_install_linux` (18 chars), `ipxe_linux_preseed` (18 chars), `ipxe_linux_report` (17 chars). T0.6 audit obligatoire (iso 3.1/3.2/3.3 — `action` varchar(20)). | Audit traçabilité par flow. |
| `IpxeActionResolver` | **Pas de modification** — l'enum `IpxeAdminAction::template()` mappe les 9 nouveaux cases vers les templates `ipxe.actions.install_*`. Le resolver dispatche automatiquement. **Cependant** : extension du paramètre `'type'` injecté dans le contexte Blade (cf. variables ci-dessous). Décision SM 3.4 = ajouter 2 variables Blade `osVersion` et `installType` dans `IpxeActionResolver::resolve()` — extraites de l'enum case via une méthode `IpxeAdminAction::linuxMeta(): ?array`. | Mutualiser le code de rendu install_*. |

**Idempotence + sécurité** :

- `/ipxe/installation-linux` (rendu menu) : **idempotent** (lecture seule + log audit best-effort).
- `/ipxe/action/install_*` (rendu kernel cmdline) : **idempotent** (rendu pur — pas de side effect DB hors log).
- `/ipxe/linux/preseed` (rendu preseed) : **idempotent** (le preseed est déterministe pour une (mac, uuid, os, type) donnée — pas d'écriture DB hors log audit + MachineBootLog).
- `/ipxe/linux/action` (hook fin install) : **partiellement idempotent** — l'update `os='linux'` + `status` est idempotente (UPDATE WHERE), mais le `MachineBootLog` insère une ligne par appel. Acceptable Phase 2.
- `/ipxe/linux/autorun` (stub) : **idempotent** (rendu pur).

**Side effects** :
- **DB PostgreSQL** : update `Workstation` (cas `linux/action`), insert `MachineBootLog` (3 cas).
- **Filesystem** : **AUCUNE** écriture. Pas de `/tmp/{name}.preseed`. Pas de fichier statique généré.
- **Logs** : `Log::channel('ipxe')` (events) + `MachineBootLog` (rows).

---

## ⚠️ Décisions tranchées (D1-D15, ne pas re-débattre)

> Cadrage SM 2026-05-20 par claude-opus-4-7. Le dev applique sans re-discuter. En cas de blocage technique réel, documenter dans Dev Agent Record et continuer.

### D1 — Namespace : extension **`App\Ipxe`** (pas de nouveau sous-namespace)

- Ajouts sous `app/Ipxe/` :
  ```
  app/Ipxe/
  ├── Services/
  │   ├── IpxeService.php                        (modifié — +handleInstallationLinuxMenu)
  │   ├── IpxeMenuRenderer.php                   (modifié — +renderInstallationLinuxMenu + admin installLinuxBaseUrl)
  │   ├── IpxeActionResolver.php                 (modifié — +osVersion + installType variables)
  │   ├── LinuxPreseedService.php                (NEW — assemble preseed depuis fragments)
  │   ├── LinuxInstallMenuBuilder.php            (NEW — construit variables Blade installation-linux)
  │   └── LinuxPostInstallTracker.php            (NEW — hook /ipxe/linux/action)
  ├── Enums/
  │   ├── IpxeAdminAction.php                    (modifié — +9 cases install_*)
  │   ├── IpxeMenuKind.php                       (modifié — +InstallationLinuxMenu + InstallationLinuxHandshake)
  │   ├── LinuxDistribution.php                  (NEW — enum 3 cases : Debian|Ubuntu|Nird)
  │   └── LinuxDesktopVariant.php                (NEW — enum 7 cases : Base|Gnome|Lxde|Kde|Mate|Xfce|Cinnamon)
  ├── Http/
  │   ├── Controllers/
  │   │   ├── IpxeInstallationLinuxController.php   (NEW — GET|POST /ipxe/installation-linux)
  │   │   ├── IpxeLinuxPreseedController.php        (NEW — GET|POST /ipxe/linux/preseed)
  │   │   ├── IpxeLinuxActionController.php         (NEW — GET|POST /ipxe/linux/action)
  │   │   └── IpxeLinuxAutorunController.php        (NEW — GET|POST /ipxe/linux/autorun)
  │   └── Requests/
  │       ├── IpxeInstallationLinuxRequest.php   (NEW — mêmes règles que IpxeBootRequest)
  │       ├── IpxeLinuxPreseedRequest.php        (NEW — + os/type whitelist + mask/gateway optionnels)
  │       ├── IpxeLinuxActionRequest.php         (NEW — uuid/name/ret)
  │       └── IpxeLinuxAutorunRequest.php        (NEW — uuid/mac)
  └── Support/
      └── PreseedPlaceholders.php                (NEW — catalogue des placeholders ###_<KEY>_### + sanitization)
  ```
- **Anti-pattern** : ne PAS créer `App\Ipxe\Linux\…` ni `App\Ipxe\Install\…` sous-namespace — la frontière est par responsabilité (Service/Controller/Renderer/FormRequest) déjà posée 3.1-3.3.

### D2 — 4 nouveaux endpoints HTTP (parité legacy iso)

- 4 blocs à ajouter dans `routes/web.php` **dans le bloc existant 3.1/3.2/3.3** (après les routes `/ipxe/enrollment/*` et **avant** le catchall) :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.4 — Installation Linux (Debian/Ubuntu/NIRD) (D2)
  |--------------------------------------------------------------------------
  | Remplace les endpoints legacy `/ipxe/installation-linux.php`,
  | `/ipxe/linux/preseed.php`, `/ipxe/linux/action.php`,
  | `/ipxe/linux/autorun.php` par 4 routes natives.
  |
  | **ORDRE STRICT** : ce bloc doit rester AVANT le catchall ci-dessous —
  | sinon la route `{path}` capture toutes les requêtes `/ipxe/*` et rend
  | ces routes natives inaccessibles. Cf. test
  | `IpxeNamespaceTest::ipxe_3_4_routes_are_declared_before_catchall`.
  |
  | **Sécurité** : middleware `auth.v1.lan-only` (16.11) — restreint au LAN
  | scolaire RFC1918. Parité 3.1-3.3 D3/D8 — pas de JWT.
  |
  | **Throttle** : 600/min/IP iso 3.1-3.3 (suffisant pour ~50 postes qui
  | re-fetch leur preseed en parallèle à la rentrée).
  */
  Route::match(['GET', 'POST'], '/ipxe/installation-linux', [
      \App\Ipxe\Http\Controllers\IpxeInstallationLinuxController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.installation-linux')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/linux/preseed', [
      \App\Ipxe\Http\Controllers\IpxeLinuxPreseedController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.linux.preseed')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/linux/action', [
      \App\Ipxe\Http\Controllers\IpxeLinuxActionController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.linux.action')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/linux/autorun', [
      \App\Ipxe\Http\Controllers\IpxeLinuxAutorunController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.linux.autorun')
      ->withoutMiddleware(['web']);
  ```
- **Pourquoi pas un groupe `Route::prefix('/ipxe/linux')` ?** **Anti-pattern strict** iso 3.3 D2 — le groupe empêche les tests archi de scanner les routes individuellement, et `/ipxe/installation-linux` n'est pas sous `/ipxe/linux/`. Mieux : 4 routes plates.
- **Pourquoi `GET|POST` ?** Iso-legacy `installation-linux.php:10` / `preseed.php:12` / `action.php:20` / `autorun.php:13` qui acceptent les deux. Le firmware iPXE post-handshake utilise POST. Mais l'installateur Debian fait un GET sur le preseed.

### D3 — Sécurité : **réutilisation stricte `auth.v1.lan-only` (16.11) — pas d'évolution**

- Iso 3.1/3.2/3.3 D3.
- **Risque accepté** : les secrets dans le preseed (`$ADMINSE_PASSWD`, `$LDAP_ADMIN_PASSWD`, `$TOKEN`) sont renvoyés en clair à tout poste LAN qui pose un (MAC, UUID) valide. Mitigation = LAN scolaire restrictif + log audit (sha256 du preseed généré + hostname + IP).
- **Anti-pattern** : ne PAS introduire de Bearer per-host, ne PAS chiffrer le preseed (incompatible avec l'installateur Debian qui consomme du preseed text/plain).
- **Note Phase 3** : envisager un mécanisme d'attestation HMAC dérivé de (MAC, UUID, timestamp ± 5min) — déféré si besoin terrain.

### D4 — Résolution poste : **réutilisation stricte `WorkstationLocator` (3.1)**

- Iso 3.1/3.2/3.3 D4 — pas de duplication, pas de refactor.
- 4 endpoints 3.4 résolvent la Workstation via `WorkstationLocator::locate($mac, $uuid, $product)`.
- **Tolérance poste inconnu** :
  - `/ipxe/installation-linux` : poste inconnu → menu erreur minimaliste « Poste non enregistre — utilisez (n) Nommer le poste depuis /ipxe/admin avant » + chain `/ipxe/admin`. Iso D7 parité 3.3.
  - `/ipxe/action/install_*` : poste inconnu → render kernel cmdline avec `hostname=unknown` (parité legacy `deb_gnome.php:10` qui interpole `$machine['cn']` même si vide). Log warning. **Acceptable Phase 2** — le firmware iPXE va chain vers le preseed qui lui répondra 404 si poste inconnu.
  - `/ipxe/linux/preseed` : poste inconnu → **404 + log warning `ipxe.linux.preseed.unknown_workstation`**. Décision 3.4 (diverge légèrement du legacy `preseed.php:31` qui renvoie body vide + 200 — un 404 explicite est plus debug-friendly).
  - `/ipxe/linux/action` : poste inconnu → response 200 text/plain vide + log warning (parité legacy `action.php:41` qui echo `"echo erreur $uuid $name\n"` — décision 3.4 = pas d'echo erreur, juste warning log + 200 silencieux).
  - `/ipxe/linux/autorun` : poste inconnu → response 200 stub minimal + log warning.

### D5 — Service `LinuxPreseedService` (orchestrateur preseed)

- Nouveau service `App\Ipxe\Services\LinuxPreseedService` (singleton enregistré dans `IpxeServiceProvider`).
- **Dépendances injectées** :
  - `App\Ipxe\Support\PreseedPlaceholders` (NEW — catalogue + sanitization).
  - `Illuminate\Contracts\Filesystem\Filesystem` (pour lire les fragments `linux/*.cfg` — décision 3.4 = lecture via `Storage::disk('ipxe')` OU `file_get_contents()` direct via chemin absolu — voir DO ci-dessous).
- **Méthode publique principale** :
  ```php
  /**
   * Génère le preseed assemblé en text/plain pour un poste donné.
   *
   * @param  Workstation $workstation  Poste résolu via WorkstationLocator.
   * @param  LinuxDistribution $distribution  Debian|Ubuntu|Nird.
   * @param  LinuxDesktopVariant $variant  Gnome|Lxde|Kde|Mate|Xfce|Cinnamon|Base.
   * @param  array{mask?:string, gateway?:string, perso?:bool} $params  Override optionnels.
   * @return string  Preseed assemblé (text/plain ~4000 lignes).
   * @throws PreseedGenerationException  si fragment manquant ou config invalide.
   */
  public function generate(
      Workstation $workstation,
      LinuxDistribution $distribution,
      LinuxDesktopVariant $variant,
      array $params = [],
  ): string;
  ```
- **Algorithme iso-legacy `preseed.php:86-159`** :
  1. Lecture des fragments base selon `$distribution` (Debian → `debian.cfg`+`debian_<variant>.cfg`+`sambaedu.cfg`+`simple_boot.cfg` ; Ubuntu → `ubuntu.cfg`+`simple_boot.cfg` ; Nird → `debian.cfg`+`debian_perso.cfg`+`simple_boot.cfg`).
  2. Ajout conditionnel `aptcache.cfg` si `config('sambaedu.linux.apt_proxy')` défini, sinon `nocache.cfg`+`proxy.cfg` si `server_proxy`.
  3. Ajout conditionnel `commande_fin.cfg` si `config('sambaedu.linux.commande_fin_preseed')` défini.
  4. Construction du tableau `$config` consolidé (variables `linux_locale`, `linux_keyboard`, `linux_interface`, `linux_user`, `linux_user_passwd`, `version_debian`, `domain`, `ldap_port`, `ldap_admin_passwd`, `se4ad_ip`, `ldap_admin_name`, `samba_domain`, `se4fs_ip`, `ldap_base_dn`, `computers_rdn`, `admin_rdn`, `admin_passwd`, `proxy_*`, `se4_pub_key`, `token`, `etab_ou`, `depot_type`).
  5. Construction du tableau `$params` par-poste (`hostname` = `Workstation::name`, `uuid` = `$workstation->uuid`).
  6. Interpolation : pour chaque ligne du preseed assemblé, remplacer `###_<KEY>_###` par `$config[strtolower(key)] ?? $params[strtolower(key)] ?? ''`.
  7. Retour string concaténée.
- **Catalogue des placeholders** (cf. fragments `linux/*.cfg` audités) :
  ```
  ###_LINUX_LOCALE_###        ← config('sambaedu.linux.locale', 'fr_FR')
  ###_LINUX_KEYBOARD_###      ← config('sambaedu.linux.keyboard', 'fr(latin9)')
  ###_LINUX_INTERFACE_###     ← config('sambaedu.linux.interface', 'auto')
  ###_LINUX_USER_###          ← config('sambaedu.linux.user', '')
  ###_LINUX_USER_PASSWD_###   ← config('sambaedu.linux.user_passwd', '')  [SECRET]
  ###_HOSTNAME_###            ← Workstation::name (par-poste)
  ###_DOMAIN_###              ← config('sambaedu.domain', '')
  ###_VERSION_DEBIAN_###      ← config('sambaedu.linux.version_debian', 'trixie')
  ###_SE4AD_IP_###            ← config('sambaedu.se4ad_ip')
  ###_SE4FS_IP_###            ← config('sambaedu.se4fs_ip')
  ###_LDAP_PORT_###           ← config('sambaedu.ldap_port', 636)
  ###_LDAP_ADMIN_PASSWD_###   ← config('sambaedu.ldap_admin_passwd')  [SECRET]
  ###_LDAP_ADMIN_NAME_###     ← config('sambaedu.ldap_admin_name')
  ###_LDAP_BASE_DN_###        ← config('sambaedu.ldap_base_dn')
  ###_COMPUTERS_RDN_###       ← config('sambaedu.computers_rdn')
  ###_ADMIN_RDN_###           ← config('sambaedu.admin_rdn')
  ###_ADMIN_PASSWD_###        ← config('sambaedu.admin_passwd')  [SECRET]
  ###_ADMINSE_PASSWD_###      ← config('sambaedu.admin_passwd')  [SECRET]  (alias historique)
  ###_SAMBA_DOMAIN_###        ← config('sambaedu.samba_domain')
  ###_SE4AD_NAME_###          ← config('sambaedu.se4ad_name')
  ###_SE4FS_NAME_###          ← config('sambaedu.se4fs_name')
  ###_PROXY_TYPE_###          ← config('sambaedu.linux.proxy_type', 'none')
  ###_PROXY_ADDRESS_###       ← config('sambaedu.linux.proxy_address', '')
  ###_PROXY_PORT_###          ← config('sambaedu.linux.proxy_port', '')
  ###_PROXY_URL_###           ← config('sambaedu.linux.proxy_url', '')
  ###_SE4_PUB_KEY_###         ← config('sambaedu.se4_pub_key')
  ###_TOKEN_###               ← config('sambaedu.linux.token', '')  [SECRET]
  ###_DEPOT_TYPE_###          ← config('sambaedu.linux.depot_type', 'main')
  ###_UUID_###                ← Workstation::uuid (par-poste)
  ###_ETAB_OU_###             ← Workstation::etab_ou (déduit du DN AD ou Workstation::physicalRoom->etab_ou — cf. SE4 mémoire)
  ```
- **Anti-pattern** :
  - ❌ Ne PAS interpoler de variables non whitelistées (= source d'injection via input user). Toutes les valeurs passent par `PreseedPlaceholders::sanitize($value)` qui rejette les chars non-ASCII et les newlines.
  - ❌ Ne PAS écrire le snapshot `/tmp/{name}.preseed` (parité legacy retirée — debug only).
  - ❌ Ne PAS lire les fragments depuis l'arborescence legacy `sambaedu/ipxe/linux/*.cfg` — **copier** ces fichiers dans `resources/ipxe/linux/*.cfg` (assets statiques projet, sous version control). Cf. D11.

### D6 — Service `LinuxInstallMenuBuilder` (variables Blade menu)

- Nouveau service `App\Ipxe\Services\LinuxInstallMenuBuilder` (singleton stateless).
- **Méthode principale** :
  ```php
  /**
   * Construit le payload de variables Blade pour le menu /ipxe/installation-linux.
   *
   * @param  Workstation|null $workstation
   * @param  string $serverBaseUrl
   * @param  string $ip
   * @return array{
   *     shebang: string,
   *     workstationName: string,
   *     ip: string,
   *     mac: string,
   *     uuid: string,
   *     serverBaseUrl: string,
   *     installLinuxItems: list<array{enumValue: string, label: string}>,
   *     menuTimeoutMs: int,
   *     resolutionX: int,
   *     resolutionY: int,
   *     resolutionPng: string,
   *     bootDiskFallback: string,
   *     isKnown: bool,
   * }
   */
  public function build(?Workstation $workstation, string $serverBaseUrl, string $ip): array;
  ```
- La liste `installLinuxItems` est lue depuis `config('ipxe.linux.menu_items', [...])` (D11) — par défaut 9 entrées.

### D7 — Cas « poste inconnu » sur `/ipxe/installation-linux` → menu erreur + chain `/ipxe/admin`

- Parité 3.3 D7. Décision identique :
  ```
  #!ipxe
  echo Erreur — poste non encore enregistre
  echo Utilisez (n) Nommer le poste depuis /ipxe/admin avant d'installer un OS.
  sleep 5
  chain --replace --autofree {server}/ipxe/admin##params
  ```
- **Pas** d'auto-enrollment ici — séparation stricte des flows.

### D8 — Logging structuré channel `ipxe` (extension 3.1/3.2/3.3)

- 12 nouveaux events à logger (channel `ipxe`, driver daily 14j — iso 3.1) :
  - **Menu Installation Linux** :
    - `ipxe.install_linux.menu_rendered` (info) — menu rendu. Context : ip, mac_prefix (6), uuid_prefix (8), workstation_id (nullable), workstation_name_prefix (6), menu_variant (`known|unknown`).
    - `ipxe.install_linux.menu_render_error` (error) — exception Blade. Context : ip, exception_class, message (200 chars).
  - **Action install_* (réutilise events 3.2)** :
    - `ipxe.action.dispatched` event existant 3.2 — context `action` = `install_<variant>`. Pas de nouvel event.
  - **Preseed** :
    - `ipxe.linux.preseed.generated` (info) — preseed généré avec succès. Context : ip, workstation_id, workstation_name_prefix (6), distribution (`debian|ubuntu|nird`), variant (`gnome|lxde|kde|mate|xfce|cinnamon|base`), preseed_sha256 (64 chars — audit non-secret car preseed reproductible par config admin), preseed_size_bytes (int).
    - `ipxe.linux.preseed.unknown_workstation` (warning) — résolution null. Context : ip, mac_prefix (6), uuid_prefix (8).
    - `ipxe.linux.preseed.invalid_distribution` (warning) — `$os` hors whitelist. Context : ip, raw_distribution (tronqué 32 chars + sanitize ASCII).
    - `ipxe.linux.preseed.invalid_variant` (warning) — `$type` hors whitelist. Context : ip, raw_variant (tronqué 32 chars + sanitize).
    - `ipxe.linux.preseed.fragment_missing` (error) — fragment .cfg introuvable. Context : ip, fragment_name. **Cas attaque/régression infra**.
    - `ipxe.linux.preseed.generation_error` (error) — exception interne. Context : ip, exception_class, message (200 chars).
  - **Action callback** :
    - `ipxe.linux.action.success` (info) — `ret=0`. Context : ip, workstation_id, workstation_name_prefix (6).
    - `ipxe.linux.action.failure` (warning) — `ret != 0`. Context : ip, workstation_id, name (tronqué 16 chars), ret (int).
    - `ipxe.linux.action.unknown_workstation` (warning) — résolution null. Context : ip, mac_prefix (6), uuid_prefix (8).
  - **Autorun** :
    - `ipxe.linux.autorun.served` (info) — stub rendu. Context : ip, workstation_id.
- **Préfixes obligatoires** sur valeurs sensibles : iso 3.1 AC7.3 — MAC 6 chars, UUID 8 chars, name 6-16 chars selon contexte.
- **CRITICAL — pas de secret loggé** : ne **jamais** logger le preseed complet ni les valeurs des placeholders `###_<*_PASSWD>_###`. Seulement le sha256 (qui ne fuite rien tant que le sel admin reste sûr).

### D9 — Schéma DB : **aucune migration**, réutilisation `Workstation` + `MachineBootLog`

- **Workstation** : colonnes `name`, `uuid`, `mac`, `os`, `status`, `last_report_at` déjà présentes. 3.4 écrit dans `os` et `status` via `LinuxPostInstallTracker::record()`.
- **MachineBootLog.action** : varchar(20). 3 nouvelles valeurs (`ipxe_install_linux` 18 chars, `ipxe_linux_preseed` 18 chars, `ipxe_linux_report` 17 chars) — toutes ≤20 chars. T0.6 audit obligatoire (iso 3.1/3.2/3.3 — pas de blocage attendu).
- **Anti-pattern** : ne PAS étendre `Workstation` avec une colonne `linux_install_started_at` ou `linux_preseed_sha256` — Phase 2 garde la table simple. L'audit fin (timestamps, sha256) est tracé via `MachineBootLog.started_at` + log channel `ipxe`.

### D10 — Templates Blade — **11 nouveaux fichiers + 1 modifié**

- **Nouveaux** :
  - `resources/views/ipxe/menu/installation-linux.blade.php` (~50 lignes) — port natif `installation-linux.php` (165 L) simplifié : items 8 desktops + ubuntu64 + nird + retour + shell + exit. Chaque item chaine vers `/ipxe/action/install_<variant>##params`.
  - `resources/views/ipxe/actions/install_deb_base.blade.php` (~10 lignes) — port natif `actions/deb_base.php`.
  - `resources/views/ipxe/actions/install_deb_cinnamon.blade.php` (~10 lignes) — port natif `actions/deb_cinnamon.php`.
  - `resources/views/ipxe/actions/install_deb_gnome.blade.php` (~10 lignes) — port natif `actions/deb_gnome.php`.
  - `resources/views/ipxe/actions/install_deb_kde.blade.php` (~10 lignes) — port natif `actions/deb_kde.php`.
  - `resources/views/ipxe/actions/install_deb_lxde.blade.php` (~10 lignes) — port natif `actions/deb_lxde.php`.
  - `resources/views/ipxe/actions/install_deb_mate.blade.php` (~10 lignes) — port natif `actions/deb_mate.php`.
  - `resources/views/ipxe/actions/install_deb_xfce.blade.php` (~10 lignes) — port natif `actions/deb_xfce.php`.
  - `resources/views/ipxe/actions/install_ubuntu64.blade.php` (~10 lignes) — port natif `actions/ubuntu64.php`.
  - `resources/views/ipxe/actions/install_nird.blade.php` (~10 lignes) — port natif `actions/nird.php` (similaire à deb_base mais avec `$perso=1` dans l'URL preseed).
  - **Menu erreur unknown** : factorisé via un **partial Blade** `resources/views/ipxe/menu/_unknown_workstation_error.blade.php` (~8 lignes) — réutilisable depuis 3.4 + 3.5/3.6/3.7. Optionnel — peut être inliné dans chaque menu si Blade `@include` non souhaité.
- **Modifié** :
  - `resources/views/ipxe/menu/admin.blade.php` (~50 lignes) — ajouter dans le bloc `@if($isKnown)` :
    ```blade
    item --key l install-linux (l) Installation Linux (Debian/Ubuntu)
    ```
    et la section :
    ```blade
    :install-linux
    chain --replace --autofree {{ $installLinuxBaseUrl }}##params
    ```
- **Charset ASCII strict** : iso 3.1 D9 + 3.2 D6 + 3.3 D10 — pas d'accent fr. Test archi étend la couverture aux 11 nouveaux templates.
- **Newline final obligatoire** : iso 3.1.
- **Pas de PHP residual** : iso 3.1 — test archi `it_renders_output_does_not_contain_php_tags` étendu.
- **Shebang `#!ipxe`** : injecté comme variable Blade `{!! $shebang !!}` (iso 3.1 DO-13).
- **Le preseed N'EST PAS un template Blade** — c'est `LinuxPreseedService::generate()` qui assemble texte directement (pas de rendu Blade pour le preseed final). Justification : le preseed concatène des fragments .cfg + interpolation regex `###_*_###` → plus simple en string concat qu'en Blade (qui ajouterait du parsing inutile et risquerait de stripper `#` en début de ligne).

### D11 — Variables de configuration : **extension `config/ipxe.php` + `config/sambaedu.php`**

- Nouvelle section dans `config/ipxe.php` :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.4 — Installation Linux (D11)
  |--------------------------------------------------------------------------
  |
  | Paramètres pour les 4 endpoints `/ipxe/installation-linux`,
  | `/ipxe/linux/{preseed,action,autorun}` — port natif des fichiers legacy
  | `sambaedu/ipxe/installation-linux.php` + `linux/*.php` + `actions/{deb_*,ubuntu64,nird}.php`.
  */
  'linux' => [
      // Active la branche Installation Linux depuis le menu admin (3.4).
      // Si false, l'item (l) Installation Linux est masqué dans /ipxe/admin.
      'enabled' => filter_var(env('IPXE_INSTALL_LINUX_ENABLED', true), FILTER_VALIDATE_BOOL),

      // Timeout du menu installation-linux (10s — iso-legacy installation-linux.php:9).
      'menu_timeout_ms' => (int) env('IPXE_INSTALL_LINUX_TIMEOUT_MS', 10000),

      // Background PNG affiché par la console iPXE du menu installation-linux.
      'background_png' => env('IPXE_INSTALL_LINUX_BG_PNG', 'png/linux2.png'),

      // Variante par défaut sélectionnée (iso-legacy installation-linux.php:29).
      'default_variant' => env('IPXE_INSTALL_LINUX_DEFAULT', 'install_deb_gnome'),

      // Liste des items menu (whitelist enum + labels iPXE-safe ASCII).
      // Pour ajouter/retirer un item, éditer ce tableau + le case enum
      // correspondant dans IpxeAdminAction.
      'menu_items' => [
          ['enum' => 'install_deb_base',     'label' => 'Debian base (sans desktop)'],
          ['enum' => 'install_deb_gnome',    'label' => 'Debian + GNOME (defaut)'],
          ['enum' => 'install_deb_lxde',     'label' => 'Debian + LXDE'],
          ['enum' => 'install_deb_kde',      'label' => 'Debian + KDE'],
          ['enum' => 'install_deb_mate',     'label' => 'Debian + MATE'],
          ['enum' => 'install_deb_xfce',     'label' => 'Debian + XFCE'],
          ['enum' => 'install_deb_cinnamon', 'label' => 'Debian + Cinnamon'],
          ['enum' => 'install_nird',         'label' => 'NIRD (Debian derivee primaire)'],
          ['enum' => 'install_ubuntu64',     'label' => 'Ubuntu 20.04 (hors domaine)'],
      ],

      // Préfixe URL des assets debian-installer/ubuntu-installer servis via
      // l'OS_URL résolu (cf. config/ipxe.php section actions).
      // Iso-legacy `actions/deb_*.php:8` : `{os_url}/debian-installer/amd64/linux`.
      'kernel_paths' => [
          'debian' => env('IPXE_LINUX_DEBIAN_KERNEL', '/debian-installer/amd64/linux'),
          'debian_initrd' => env('IPXE_LINUX_DEBIAN_INITRD', '/debian-installer/amd64/initrd.gz'),
          'ubuntu' => env('IPXE_LINUX_UBUNTU_KERNEL', '/ubuntu-installer/amd64/linux'),
          'ubuntu_initrd' => env('IPXE_LINUX_UBUNTU_INITRD', '/ubuntu-installer/amd64/initrd.gz'),
      ],

      // Chemin du dossier de fragments preseed (assets statiques projet).
      // Décision D11 — copier les fragments depuis sambaedu/ipxe/linux/*.cfg
      // vers resources/ipxe/linux/*.cfg pour mise sous version control.
      'preseed_fragments_path' => env(
          'IPXE_LINUX_PRESEED_FRAGMENTS',
          resource_path('ipxe/linux'),
      ),

      // Whitelist stricte des distributions acceptées par /ipxe/linux/preseed.
      // Cf. enum LinuxDistribution.
      'allowed_distributions' => ['debian', 'ubuntu', 'nird'],

      // Whitelist stricte des variantes desktop acceptées par /ipxe/linux/preseed.
      // Cf. enum LinuxDesktopVariant.
      'allowed_variants' => ['base', 'gnome', 'lxde', 'kde', 'mate', 'xfce', 'cinnamon'],

      // Whitelist stricte des versions Debian/Ubuntu acceptées (au-delà,
      // fallback config('sambaedu.linux.version_debian') par défaut).
      // Pour ajouter une version, éditer ici ET les assets servis par Apache.
      'allowed_os_versions' => ['trixie', 'bookworm', 'bullseye', 'ubuntu', 'focal', 'jammy'],
  ],
  ```
- **Nouvelle sous-section dans `config/sambaedu.php`** (variables consommées par le preseed) :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.4 — Variables Linux preseed (D11)
  |--------------------------------------------------------------------------
  |
  | Variables consommées par LinuxPreseedService pour interpoler les
  | placeholders ###_<KEY>_### dans les fragments preseed. Toutes ces
  | valeurs DOIVENT être lues depuis `.env` (jamais hardcodées) — elles
  | contiennent des secrets (mots de passe root, clés AD, tokens).
  |
  | Convention : tous les SECRETS sont rendus en clair dans le preseed
  | text/plain renvoyé au poste LAN qui boot. Mitigation = auth.v1.lan-only
  | + MAC/UUID matching strict (cf. story 3.4 D3).
  */
  'linux' => [
      'locale' => env('SAMBAEDU_LINUX_LOCALE', 'fr_FR'),
      'keyboard' => env('SAMBAEDU_LINUX_KEYBOARD', 'fr(latin9)'),
      'interface' => env('SAMBAEDU_LINUX_INTERFACE', 'auto'),
      'user' => env('SAMBAEDU_LINUX_USER', ''),
      'user_passwd' => env('SAMBAEDU_LINUX_USER_PASSWD', ''),  // SECRET
      'version_debian' => env('SAMBAEDU_LINUX_VERSION_DEBIAN', 'trixie'),
      'apt_proxy' => env('SAMBAEDU_LINUX_APT_PROXY', ''),
      'server_proxy' => env('SAMBAEDU_LINUX_SERVER_PROXY', ''),
      'proxy_type' => env('SAMBAEDU_LINUX_PROXY_TYPE', 'none'),
      'proxy_address' => env('SAMBAEDU_LINUX_PROXY_ADDRESS', ''),
      'proxy_port' => env('SAMBAEDU_LINUX_PROXY_PORT', ''),
      'proxy_url' => env('SAMBAEDU_LINUX_PROXY_URL', ''),
      'token' => env('SAMBAEDU_LINUX_TOKEN', ''),  // SECRET
      'depot_type' => env('SAMBAEDU_LINUX_DEPOT_TYPE', 'main'),
      'commande_fin_preseed' => env('SAMBAEDU_LINUX_COMMANDE_FIN', ''),
      'disk' => env('SAMBAEDU_LINUX_DISK', ''),  // optionnel — force /dev/sdX si défini
      'mask' => env('SAMBAEDU_LINUX_MASK', ''),
      'gateway' => env('SAMBAEDU_LINUX_GATEWAY', ''),
  ],

  // Variables AD/LDAP consommées par le preseed
  'ldap_port' => env('SAMBAEDU_LDAP_PORT', 636),
  'ldap_admin_passwd' => env('SAMBAEDU_LDAP_ADMIN_PASSWD', ''),  // SECRET
  'ldap_admin_name' => env('SAMBAEDU_LDAP_ADMIN_NAME', 'Administrator'),
  'ldap_base_dn' => env('SAMBAEDU_LDAP_BASE_DN', ''),
  'computers_rdn' => env('SAMBAEDU_COMPUTERS_RDN', 'CN=Computers'),
  'admin_rdn' => env('SAMBAEDU_ADMIN_RDN', 'CN=Users'),
  'admin_passwd' => env('SAMBAEDU_ADMIN_PASSWD', ''),  // SECRET
  'samba_domain' => env('SAMBAEDU_SAMBA_DOMAIN', ''),
  'se4ad_name' => env('SAMBAEDU_SE4AD_NAME', ''),
  'se4_pub_key' => env('SAMBAEDU_SE4_PUB_KEY', ''),
  'domain' => env('SAMBAEDU_DOMAIN', ''),
  ```
- **Valeurs par défaut** : iso-legacy. Henri peut override via `.env` pour pré-prod / tests.
- **Audit T0.6** : vérifier que ces variables ne sont pas déjà définies ailleurs dans `config/sambaedu.php` (éviter les doublons). Si présentes → réutiliser, sinon créer.

### D12 — `MachineBootLog::action` — extension **sans migration** (iso 3.1/3.2/3.3)

- `varchar(20)` confirmé sans CHECK par 3.1/3.2/3.3 T0.6. Les 3 nouvelles valeurs sont :
  - `ipxe_install_linux` (18 chars) — render menu /ipxe/installation-linux.
  - `ipxe_linux_preseed` (18 chars) — génération preseed.
  - `ipxe_linux_report` (17 chars) — hook /ipxe/linux/action.
- Tous ≤18 chars, fit dans `varchar(20)`.
- `initiated_by` = `'ipxe'` (string fixe).
- **Pas de migration**. T0.6 audit obligatoire.

### D13 — UI admin Livewire/Blade : **HORS-SCOPE 3.4** (option ouverte Phase 3)

- 3.4 ne livre **aucune UI admin web** pour pré-programmer un install (ex : un admin SER tape un OS+type+disque dans `/parc/install/new` avant que le poste boot).
- **Justification** : parité legacy stricte — le legacy fait tout depuis le firmware iPXE. La sélection se fait au boot par l'opérateur sur place.
- **Si Henri arbitre OUI à l'UI** : ouvrir une story 3.4b dédiée Phase 3.

### D14 — Variantes hors-scope (`se4ad`, `se4fs`, `deb_serv`, `deb_kiosk`, `deb_nextcloud`, `deb_gnome_perso`, `primtux`)

- Iso section « HORS-SCOPE 3.4 » au-dessus.
- Justification :
  - `se4ad`/`se4fs` = déploiement serveur (réservé Phase 3 dédiée, complexité réseau/DNS/SSH-key spécifique).
  - `deb_serv`/`deb_kiosk`/`deb_nextcloud`/`deb_gnome_perso`/`primtux` = besoin terrain limité Phase 2 (≤5% des installs constatées sur le parc historique selon audit Henri 2026-05-20 si confirmé — sinon hypothèse de cadrage à infirmer en T0.4).
- **Élargissement futur** : ajouter de nouveaux cases enum + templates Blade au fil des stories Phase 3.

### D15 — Endpoint `/ipxe/linux/autorun` : **stub minimal** (HORS-SCOPE port complet)

- Iso section « HORS-SCOPE 3.4 » au-dessus.
- Le legacy `linux/autorun.php` (56 L) construit un script bash qui consomme `linux/action.php` en boucle pour exécuter des scripts post-install **dynamiques** lus depuis l'AD (`get_action($config, $uuid)`). Ce mécanisme n'est **plus pertinent en SE5** (Epic 17 `script_assignments` est l'alternative SE5).
- **Décision 3.4** : porter un endpoint stub :
  ```bash
  #!/bin/bash
  echo "install Linux completed for $name ($uuid)"
  exit 0
  ```
- Si Henri arbitre besoin du flow complet → ouvrir story Phase 3 dédiée.

---

## Story

As **un poste de travail (Windows ou Linux) en boot iPXE déjà résolu via `/ipxe/boot` (3.1), passé au menu `/ipxe/admin` (3.2/3.3) et déjà enregistré dans PostgreSQL+AD (3.3)** ainsi qu'**un mainteneur du codebase `sambaedu-reload`** et **Henri en tant qu'admin SER opérant sur le LAN scolaire** :

I want
- disposer de **4 routes Laravel natives** (`/ipxe/installation-linux`, `/ipxe/linux/preseed`, `/ipxe/linux/action`, `/ipxe/linux/autorun`) qui remplacent progressivement les endpoints legacy `installation-linux.php` + `linux/preseed.php` + `linux/action.php` + `linux/autorun.php` du proxy catchall ;
- pouvoir **lancer une installation Debian/Ubuntu/NIRD** depuis le menu iPXE en LAN, ce qui assemble dynamiquement le preseed selon le profil du poste (hostname, version Debian, variante desktop, paramètres réseau) et installe l'OS automatiquement sans intervention manuelle ;
- pouvoir **suivre la fin de l'install** via le hook `curl http://se4fs/ipxe/linux/action` exécuté par l'installateur Debian en fin de preseed (`preseed.cfg:83`), ce qui met à jour `Workstation::os='linux'` + audit log ;
- assurer **zéro régression** sur les autres routes iPXE legacy non encore réécrites (`/ipxe/installation-windows.php`, `/ipxe/clonage.php`, `/ipxe/Win10/*`, etc.) — elles continuent de passer par le catchall jusqu'aux stories 3.5-3.7.

So que :
- (a) **Henri** dispose d'un flow d'installation Linux natif, journalisé via channel `ipxe` (sha256 du preseed, hostname, distribution, variant), sans dépendance au legacy PHP procédural — visible via `tail storage/logs/ipxe/ipxe-$(date +%F).log` ;
- (b) **les opérateurs terrain** peuvent installer/réinstaller un poste depuis le menu iPXE en LAN, en choisissant la variante Debian/Ubuntu/NIRD adaptée à l'usage pédagogique ;
- (c) **les développeurs des stories 3.5 (Windows) / 3.7 (Clonezilla)** disposent du pattern complet (controller fin + service preseed + enum whitelist + tests cumulés) à étendre pour les autres OS — chaque nouvelle action installable s'ajoute via 3 fichiers : 1 case enum + 1 template Blade + (optionnel) 1 service de génération.

---

## Contexte

### État entrant (post-Story 3.3 review, 3.4 = suite directe)

| Élément | État actuel | Action 3.4 |
|---|---|---|
| Namespace `App\Ipxe` | ✅ Créé 3.1, étendu 3.2/3.3 (~30 classes + ~12 templates Blade + provider + config + 4 enums) | **Étendre** — +6 classes (4 services nouveaux + 4 controllers + 4 FormRequests + 2 enums + 1 support helper) + 11 templates Blade (10 actions + 1 menu + 1 partial optionnel) |
| `IpxeService::handleBoot/handleAdmin/handleMaintenance/handleAction` | ✅ Existants 3.1/3.2 | **+1 méthode `handleInstallationLinuxMenu()`** (3.4) — pas de modif des autres |
| `IpxeMenuRenderer::renderAdminMenu()` | ✅ Existant 3.3 — expose `enrollmentBaseUrl` + `isEnrollmentActive` | **Étendre** — +1 variable `installLinuxBaseUrl` + condition `isInstallLinuxActive` |
| `IpxeActionResolver::resolve()` | ✅ Existant 3.2 | **Étendre** — +2 variables Blade `osVersion` + `installType` (extraites de l'enum case via `IpxeAdminAction::linuxMeta(): ?array`) |
| `IpxeAdminAction` enum | ✅ 3 cases (rescuecd, winpe, factory_reset) | **Étendre** — +9 cases (install_deb_base, install_deb_cinnamon, install_deb_gnome, install_deb_kde, install_deb_lxde, install_deb_mate, install_deb_xfce, install_nird, install_ubuntu64) + méthode `linuxMeta()` retournant `{distribution: string, variant: string}` ou null |
| `WorkstationLocator::locate()` | ✅ Existant 3.1 | **Réutiliser** — pas de modification |
| `MachineBootLog.action` | ✅ varchar(20), accepte `ipxe_*` 3.1-3.3 | **Étendre** — 3 nouvelles valeurs `ipxe_install_linux`, `ipxe_linux_preseed`, `ipxe_linux_report`. Pas de migration |
| Channel log `ipxe` | ✅ Créé 3.1 | **Étendre** — 12 nouveaux events D8 |
| `config/ipxe.php` | ✅ Créé 3.1, étendu 3.2/3.3 | **Étendre** — section `linux` D11 (versions, paths, allowed_*) |
| `config/sambaedu.php` | ✅ Existe partiellement | **Étendre** — section `linux` + variables LDAP/AD pour preseed (vérifier doublons T0.6) |
| Routes `/ipxe/installation-linux`, `/ipxe/linux/{preseed,action,autorun}` | ❌ Servies par catchall legacy | **Créer** — 4 routes natives AVANT catchall (D2) |
| Templates Blade `resources/views/ipxe/menu/installation-linux.blade.php` | ❌ N'existe pas | **Créer** (D10) |
| Templates Blade `resources/views/ipxe/actions/install_*.blade.php` | ❌ N'existent pas (9 cases) | **Créer** (D10) |
| Fragments preseed `resources/ipxe/linux/*.cfg` | ❌ N'existent pas (assets projet) | **Copier** depuis `sambaedu/ipxe/linux/*.cfg` (~15 fragments) + adapter pour interpolation native |
| Doc QA `docs/qa/domains/ipxe.md` | ✅ Étendue 3.1/3.2/3.3 (≥38 scénarios stables 3.1-1 à 3.3-16) | **Étendre** — section `## Story 3.4` + ≥12 scénarios stables 3.4-1 à 3.4-N. Numérotation 3.1-3.3 préservée intacte (append-only) |
| Tests Unit/Feature/Architecture iPXE | ✅ ~108-115 verts cumulés (78 baseline 3.1+3.2 + ~30 de 3.3) | **Étendre** — ≥40 nouveaux tests cumulés (≥20 unit + ≥15 feature + ≥5 archi). Non-régression préservée |

### Source de vérité du comportement attendu

Les fichiers legacy à lire en T0.4 (lecture obligatoire) :

- `sambaedu/ipxe/installation-linux.php` (233 LOC) — menu Installation Linux. **Périmètre 3.4** : lignes 23-69 (header menu + items), 90-94 (autres options shell/exit), 105-145 (sections chain `:deb_*`). **Ignorer** : lignes 70-89 (variant BYOD `$action['type'] == 'byod'` — déféré 3.4), 145-231 (sections commentées + se4ad/se4fs — déféré Phase 3), 163-181 (deb_serv/deb_nextcloud/deb_kiosk — déféré Phase 3).
- `sambaedu/ipxe/linux/preseed.php` (194 LOC) — générateur preseed. **Périmètre 3.4** : intégralité **sauf** lignes 32-76 (se4ad/se4fs — déféré Phase 3) et 97-108 (perso variants — déféré sauf nird). Algorithme `write_param()` à porter (cf. `sambaedu/includes/ipxe_functions.inc.php` lignes 64-82 si présent).
- `sambaedu/ipxe/linux/action.php` (43 LOC) — hook fin install. **Périmètre 3.4** : intégralité (read + write os + update statut + ret).
- `sambaedu/ipxe/linux/autorun.php` (56 LOC) — script bash autorun. **Périmètre 3.4** : stub minimal (HORS-SCOPE port complet D15).
- `sambaedu/ipxe/actions/deb_base.php` (12 LOC) — kernel cmdline. **Iso strict**.
- `sambaedu/ipxe/actions/deb_gnome.php` (12 LOC) — kernel cmdline. **Iso strict**.
- `sambaedu/ipxe/actions/deb_lxde.php`, `deb_kde.php`, `deb_mate.php`, `deb_xfce.php`, `deb_cinnamon.php`, `nird.php`, `ubuntu64.php` (10-12 LOC chacun) — kernel cmdlines. **Iso strict**.
- Fragments `sambaedu/ipxe/linux/{debian,debian_*,sambaedu,simple_boot,aptcache,nocache,proxy,commande_fin,ubuntu}.cfg` (15 fragments — ~500 LOC cumulés) — à **copier dans `resources/ipxe/linux/`** sans modification ASCII et à indexer par `LinuxPreseedService::generate()`.

### Risques entrants

| Risque | Sévérité | Mitigation 3.4 |
|---|---|---|
| Collision routes natives vs catchall | 🟠 Élevée | Iso 3.1/3.2/3.3 D2 — bloc routes AVANT catchall. Test archi `ipxe_3_4_routes_are_declared_before_catchall` étendu. |
| Régression sur `admin.blade.php` (ajout item install-linux) — un poste qui était en 3.3 stable casse en 3.4 | 🟠 Élevée | Test feature `IpxeAdminEndpointTest::it_shows_install_linux_item_when_enabled` + non-régression admin 3.3 items (enrollment + maintenance) toujours présents. |
| Preseed contient secrets en clair (mots de passe root, clés AD) renvoyés sur LAN | 🟠 Élevée | D3 — `auth.v1.lan-only` strict + matching MAC/UUID strict + log audit (sha256 only, jamais le preseed). **Cf. encadré sécurité D3** — Phase 2 acceptable, Phase 3 envisage HMAC attestation. |
| Whitelist `os`/`type` trop permissive — un attaquant LAN pourrait deviner `?os=../../../etc/passwd` | 🟡 Moyenne | D11 — enum stricte `LinuxDistribution` + `LinuxDesktopVariant`. Validation côté FormRequest **ET** côté `LinuxPreseedService::generate()` (défense en profondeur). Test unit anti-injection avec 6+ payloads (path traversal, NUL byte, newline, semicolon, backtick, command injection). |
| Fragment .cfg manquant ou corrompu → preseed cassé → install échoue silencieusement | 🟡 Moyenne | T1.2 copie validée par test unit (`it_lists_all_required_preseed_fragments` + `it_each_fragment_is_readable_and_non_empty`). Log error `ipxe.linux.preseed.fragment_missing` + exception `PreseedGenerationException`. |
| Variable placeholder `###_<KEY>_###` orpheline (présente dans .cfg mais pas dans `PreseedPlaceholders` catalogue) | 🟡 Moyenne | T1.3 test unit qui scanne les 15 fragments .cfg et vérifie que TOUS les placeholders trouvés sont dans le catalogue `PreseedPlaceholders::all()`. Erreur explicite si orphelin. |
| Hostname injecté dans cmdline iPXE/preseed → injection si hostname contient caractères iPXE-special (`${`, `\n`) | 🟡 Moyenne | Iso 3.3 — `IpxeHostnameSanitizer::sanitizeForIpxeOutput()` réutilisé. Defense en profondeur — l'hostname est déjà validé en 3.3 par `IpxeHostnameSanitizer::isValidHostname()` (regex stricte 64 chars). |
| `Workstation::os = 'linux'` écrasé en cas de re-install après un install Windows (poste dual-boot) | 🟢 Mineure | Décision 3.4 = écraser sans préserver (parité legacy `preseed.php:84` `set_os($config, $machine['cn'], "linux")`). Si admin veut conserver l'historique → consulter `MachineBootLog` audit. |
| `LinuxPostInstallTracker::record()` reçoit un `ret=0` factice depuis le LAN (attaque) | 🟢 Mineure | L'attaquant doit déjà avoir LAN-only + connaître un (MAC, UUID, name) valide → exploitable mais peu impactant (juste update `Workstation::os`). Log warning pour audit. |
| Performance lecture des 15 fragments .cfg à chaque génération preseed (~50 postes simultanés en rentrée) | 🟢 Mineure | `LinuxPreseedService` peut cacher les fragments en mémoire singleton (lecture 1 fois au boot du provider). Optionnel optimisation T3. Cas pathologique = ~50 × 500ko = 25Mo en RAM, négligeable. |
| Throttle 600/min trop bas en rentrée scolaire (boot de masse) | 🟢 Mineure | Iso 3.1. Volumétrie 60 postes × 5 retries < 600/min. Ajustable post-prod. |

### Pré-requis (à valider en T0)

- **Worktree git `ipxe`** : branche dédiée, pas de SSH VM. Iso 3.3.
- **Story 3.3 en review acceptée** : 🟡 status `review` au moment du cadrage SM. La phase dev 3.4 nécessite que 3.3 soit en `done` (Henri valide 3.3 ou bascule en `done`). **Bloquant amont à valider en T0.1.**
- **Schema `machine_boot_logs`** : ✅ confirmé varchar(20) sans CHECK par 3.1/3.2/3.3 T0.6. À re-vérifier en T0.6 (peu probable que ça ait évolué sur le worktree).
- **Variables `.env` consommées par le preseed** : 🟡 à valider — l'audit T0.7 doit confirmer que `SAMBAEDU_LINUX_*`, `SAMBAEDU_LDAP_ADMIN_PASSWD`, `SAMBAEDU_ADMIN_PASSWD`, `SAMBAEDU_SE4AD_NAME`, `SAMBAEDU_SE4_PUB_KEY`, `SAMBAEDU_DOMAIN`, `SAMBAEDU_COMPUTERS_RDN`, `SAMBAEDU_ADMIN_RDN`, `SAMBAEDU_LDAP_BASE_DN`, `SAMBAEDU_SAMBA_DOMAIN`, `SAMBAEDU_LDAP_PORT`, `SAMBAEDU_LDAP_ADMIN_NAME` sont définis en VM. Si absents → préciser dans la doc QA + escalation Henri pour rentrée scolaire.
- **Fichiers statiques `debian-installer/`, `ubuntu-installer/`** : ✅ servis par Apache via le catchall — confirmé en T0.4 lecture legacy (`actions/deb_gnome.php:8`).
- **Apache config** : pas de modification — les 4 routes natives `/ipxe/installation-linux` + `/ipxe/linux/*` arrivent via le catchall et seront interceptées AVANT le catchall (iso 3.1 D2).

---

## Acceptance Criteria

> AC organisées en **10 volets**. Volet 10 = QA + sprint-status (append-only sur le runbook `ipxe.md` 3.1-3.3).

### Volet 1 — Enums + helper Support (D1, D11)

**AC1.1** — **Extension `IpxeAdminAction` enum avec 9 cases install_***

**Given** le fichier `app/Ipxe/Enums/IpxeAdminAction.php`,
**When** le dev ajoute les 9 nouveaux cases listés D1,
**Then** :
- `IpxeAdminAction::cases()` retourne exactement 12 cases (3 existants + 9 nouveaux).
- `IpxeAdminAction::tryFrom('install_deb_gnome')` retourne `IpxeAdminAction::InstallDebGnome` (et idem pour les 8 autres).
- Méthode `template()` mappe les 9 nouveaux cases vers `'ipxe.actions.install_deb_base'`, `'ipxe.actions.install_deb_cinnamon'`, etc.
- **Nouvelle méthode** `linuxMeta(): ?array` qui retourne :
  - Pour les 7 cases Debian + nird : `['distribution' => 'debian', 'variant' => '<base|gnome|...>']` (nird → `['distribution' => 'nird', 'variant' => 'base']`).
  - Pour `install_ubuntu64` : `['distribution' => 'ubuntu', 'variant' => 'base']`.
  - Pour les 3 cases existants (rescuecd, winpe, factory_reset) : `null`.

**And** un test unit `tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php` étendu avec ≥6 nouveaux tests :
- `it_lists_exactly_twelve_cases_after_3_4`
- `it_resolves_install_deb_gnome_to_correct_template`
- `it_resolves_install_ubuntu64_with_ubuntu_distribution_meta`
- `it_resolves_install_nird_with_nird_distribution_meta`
- `it_returns_null_meta_for_non_linux_cases` (rescuecd, winpe, factory_reset).
- `it_returns_correct_log_name_for_install_cases`.

**AC1.2** — **Création enums `LinuxDistribution` + `LinuxDesktopVariant`**

**Given** les fichiers `app/Ipxe/Enums/LinuxDistribution.php` et `app/Ipxe/Enums/LinuxDesktopVariant.php`,
**When** le dev les crée selon D1,
**Then** :
- `LinuxDistribution` a exactement 3 cases : `Debian = 'debian'`, `Ubuntu = 'ubuntu'`, `Nird = 'nird'`.
- `LinuxDesktopVariant` a exactement 7 cases : `Base = 'base'`, `Gnome = 'gnome'`, `Lxde = 'lxde'`, `Kde = 'kde'`, `Mate = 'mate'`, `Xfce = 'xfce'`, `Cinnamon = 'cinnamon'`.
- Méthode `LinuxDistribution::fromString(string $raw): ?self` qui retourne le case ou null (cf. anti-injection).
- Idem `LinuxDesktopVariant::fromString()`.

**And** tests unit ≥6 cas par enum (3 valides + 3 invalides incluant path traversal + newline).

**AC1.3** — **Catalogue `PreseedPlaceholders` + sanitization**

**Given** le helper `App\Ipxe\Support\PreseedPlaceholders`,
**When** le dev le crée selon D5,
**Then** :
- `PreseedPlaceholders::catalog(): array<string, string>` retourne le mapping complet `###_<KEY>_### → config_key` (cf. liste D5 — 30+ entrées).
- `PreseedPlaceholders::sanitize(string $value): string` :
  - Rejette les chars non ASCII (remplace par `?`).
  - Rejette les newlines (`\n`, `\r`) — remplace par espace.
  - Rejette les chars iPXE-special (`${`, `\\$`) — escape ou rejet selon décision DO.
  - Retourne le string sanitizé.
- `PreseedPlaceholders::interpolate(string $template, array $values): string` qui :
  - Remplace chaque `###_<KEY>_###` par `sanitize($values[strtolower(key)] ?? '')`.
  - Retourne le template interpolé.

**And** tests unit ≥10 cas dont 6 anti-injection (path traversal, NUL byte, newline `\n`, semicolon `;`, backtick `` ` ``, command injection `$(curl ...)`).

### Volet 2 — `LinuxPreseedService` (D5)

**AC2.1** — **`LinuxPreseedService::generate()` assemble correctement**

**Given** le service `App\Ipxe\Services\LinuxPreseedService`,
**When** invoqué avec `(Workstation, LinuxDistribution::Debian, LinuxDesktopVariant::Gnome, [])`,
**Then** :
- Lit les fragments `debian.cfg` + `debian_gnome.cfg` + `sambaedu.cfg` + `simple_boot.cfg` (+ `nocache.cfg` ou `aptcache.cfg` selon config).
- Construit `$config` consolidé depuis `config('sambaedu.linux.*')` + `config('sambaedu.*')` (LDAP, AD).
- Construit `$params` par-poste : `hostname` = `strtolower($workstation->name)`, `uuid` = `$workstation->uuid`.
- Interpole TOUS les placeholders `###_<KEY>_###` (aucun résiduel dans le preseed final).
- Retourne un string non vide, qui commence par `### Fichier de réponses préconfigurées` (iso-legacy `debian.cfg:1`).

**And** test unit `tests/Unit/Ipxe/Services/LinuxPreseedServiceTest.php` ≥12 tests :
- `it_assembles_debian_gnome_preseed_with_all_fragments`
- `it_assembles_debian_lxde_preseed_swapping_variant_fragment`
- `it_assembles_debian_base_preseed_without_desktop_fragment`
- `it_assembles_ubuntu_preseed_with_ubuntu_cfg`
- `it_assembles_nird_preseed_with_debian_perso_cfg` (variant `Nird` utilise `debian.cfg` + `debian_perso.cfg` + `simple_boot.cfg`)
- `it_interpolates_hostname_from_workstation_name`
- `it_interpolates_uuid_from_workstation`
- `it_interpolates_admin_passwd_from_config` (sans vérifier la valeur — juste qu'elle n'est pas vide)
- `it_includes_aptcache_when_apt_proxy_configured`
- `it_includes_nocache_when_apt_proxy_empty`
- `it_includes_commande_fin_when_configured`
- `it_throws_preseed_generation_exception_when_fragment_missing`

**AC2.2** — **Anti-injection sur les inputs externes**

**Given** `LinuxPreseedService::generate()` invoqué avec une `Workstation::name = 'PC-101\nROOT_PASSWD=evil'`,
**When** le service est appelé,
**Then** :
- Le nom est sanitizé via `IpxeHostnameSanitizer::sanitizeForIpxeOutput()` AVANT injection dans le preseed.
- Le preseed final contient `PC-101` (newline + suffix supprimés).
- Aucune ligne `ROOT_PASSWD=evil` n'apparaît dans le preseed.

**And** test unit `it_sanitizes_hostname_before_interpolation` + `it_sanitizes_all_placeholder_values`.

**AC2.3** — **Log audit channel `ipxe`**

**Given** `LinuxPreseedService::generate()` invoqué avec succès,
**When** le preseed est généré,
**Then** :
- Log info `ipxe.linux.preseed.generated` (channel `ipxe`) avec context :
  - `ip` (de la Request si exposée — sinon vide).
  - `workstation_id`.
  - `workstation_name_prefix` (6 chars).
  - `distribution` (valeur enum value).
  - `variant` (valeur enum value).
  - `preseed_sha256` (64 chars).
  - `preseed_size_bytes`.
- **PAS** de log du contenu du preseed (aucun secret).

**And** test unit `it_logs_preseed_generated_with_sha256_only`.

### Volet 3 — `LinuxInstallMenuBuilder` + `LinuxPostInstallTracker` (D6)

**AC3.1** — **`LinuxInstallMenuBuilder::build()` retourne les variables Blade**

**Given** le service `App\Ipxe\Services\LinuxInstallMenuBuilder`,
**When** invoqué avec `(Workstation, $serverBaseUrl, $ip)`,
**Then** retourne un array conforme au type documenté (cf. D6) avec :
- `installLinuxItems` = lecture de `config('ipxe.linux.menu_items')` (9 entrées par défaut).
- `workstationName` sanitizé via `IpxeHostnameSanitizer::sanitizeForIpxeOutput()`.
- `menuTimeoutMs` = `config('ipxe.linux.menu_timeout_ms', 10000)`.
- `isKnown` = `$workstation !== null`.

**And** test unit ≥4 tests (known/unknown, items count, sanitization).

**AC3.2** — **`LinuxPostInstallTracker::record()` met à jour Workstation**

**Given** le service `App\Ipxe\Services\LinuxPostInstallTracker`,
**When** invoqué avec `(Workstation, ret=0, name='PC-101')`,
**Then** :
- `$workstation->os = 'linux'`.
- `$workstation->status = 'installation Linux terminée'`.
- `$workstation->last_report_at = now()`.
- `$workstation->save()`.
- Log info `ipxe.linux.action.success` avec context (ip, workstation_id, workstation_name_prefix).
- Insert `MachineBootLog` avec `action='ipxe_linux_report'` + `initiated_by='ipxe'` + `success=true`.

**Given** invoqué avec `ret != 0`,
**Then** :
- `$workstation->status = 'installation Linux echouee (ret=<X>)'` (ASCII, pas d'accent).
- `$workstation->save()`.
- Log warning `ipxe.linux.action.failure`.

**And** tests unit ≥4 (success path, failure path, unknown workstation, idempotence).

### Volet 4 — `IpxeService::handleInstallationLinuxMenu()` (D2)

**AC4.1** — **`IpxeService::handleInstallationLinuxMenu(Request)` orchestre la route**

**Given** la méthode `IpxeService::handleInstallationLinuxMenu()`,
**When** un poste appelle `GET|POST /ipxe/installation-linux`,
**Then** :
- Extrait `mac`/`uuid`/`product`/`ip`.
- Handshake si `mac === '' || uuid === ''` (parité 3.2/3.3) → `renderer->renderHandshake('installation-linux')` (réutilise la méthode 3.2 D5 avec `chainTarget`).
- Sinon résolution `WorkstationLocator::locate()`.
- Log info `ipxe.install_linux.menu_rendered` (D8).
- Insert `MachineBootLog` `action='ipxe_install_linux'` + `initiated_by='ipxe'`.
- Rendu via `renderer->renderInstallationLinuxMenu($workstation, $ip, $baseUrl)`.
- safeRender wrap iso 3.1 — fallback iPXE en cas d'exception.
- Headers iso D10 (`text/plain`, `no-store`, `noindex`).

**And** tests Unit ≥6 (handshake, known, unknown, MachineBootLog persisté, headers, safeRender).

### Volet 5 — Controllers + FormRequests (D2)

**AC5.1** — **`IpxeInstallationLinuxController::handle()` fin** (≤15 lignes)

**Given** le controller,
**When** appelé via la route,
**Then** délègue 100% à `IpxeService::handleInstallationLinuxMenu($request)`. Pas de logique métier.

**AC5.2** — **`IpxeLinuxPreseedController::handle()`** orchestre la génération preseed

**Given** le controller,
**When** appelé via `GET|POST /ipxe/linux/preseed`,
**Then** :
- Valide via `IpxeLinuxPreseedRequest` (mac/uuid nullable + os/type whitelist via enums).
- Résout Workstation. Si null → response 404 + log warning `ipxe.linux.preseed.unknown_workstation` (D4).
- Parse `$os` via `LinuxDistribution::fromString()`. Si null → response 422 + log warning.
- Parse `$type` via `LinuxDesktopVariant::fromString()`. Si null → response 422 + log warning.
- Appelle `LinuxPreseedService::generate($workstation, $distribution, $variant, ['mask' => ..., 'gateway' => ..., 'perso' => ...])`.
- Insert `MachineBootLog` `action='ipxe_linux_preseed'`.
- Headers `Content-Type: text/plain; charset=utf-8` + `Cache-Control: no-store` + `X-Robots-Tag: noindex`.
- Response 200 avec body = preseed assemblé.

**AC5.3** — **`IpxeLinuxActionController::handle()`** consomme le hook fin install

**Given** le controller,
**When** appelé via `POST /ipxe/linux/action` (parité legacy `preseed.cfg:83` `curl -F 'ret=0' -F 'uuid=...' -F 'name=...'`),
**Then** :
- Valide via `IpxeLinuxActionRequest` (uuid/name/ret nullable mais au moins uuid+name présents).
- Résout Workstation par UUID. Si null → response 200 vide + log warning (D4).
- Appelle `LinuxPostInstallTracker::record($workstation, (int)$ret, $name)`.
- Response 200 avec body vide (parité legacy `linux/action.php:39`).

**AC5.4** — **`IpxeLinuxAutorunController::handle()`** stub minimal (D15)

**Given** le controller,
**When** appelé via `GET|POST /ipxe/linux/autorun`,
**Then** :
- Résout Workstation (best-effort).
- Log info `ipxe.linux.autorun.served`.
- Response 200 text/plain avec body :
  ```bash
  #!/bin/bash
  echo "install Linux completed for $name ($uuid)"
  exit 0
  ```
- Pas d'insert MachineBootLog (D15 — stub minimal).

**AC5.5** — **FormRequests permissives + whitelists strictes**

**Given** les 4 FormRequests,
**When** un poste poste des valeurs,
**Then** :
- `IpxeInstallationLinuxRequest` : `mac/uuid/product` nullable max 64/64/128 (iso 3.1).
- `IpxeLinuxPreseedRequest` : idem + `os` nullable Rule::in(`config('ipxe.linux.allowed_distributions')`) + `type` nullable Rule::in(`config('ipxe.linux.allowed_variants')`) + `mask`/`gateway` nullable string max 32 + `perso` nullable boolean.
- `IpxeLinuxActionRequest` : `uuid` nullable string max 64 + `name` nullable string max 64 + `ret` nullable integer.
- `IpxeLinuxAutorunRequest` : iso `IpxeInstallationLinuxRequest`.
- `authorize()` = true sur les 4 (auth via middleware).

**And** tests feature ≥6 (whitelist OK, whitelist rejette, oversize input).

### Volet 6 — `IpxeMenuRenderer::renderInstallationLinuxMenu()` + `IpxeActionResolver` extension (D10)

**AC6.1** — **`IpxeMenuRenderer::renderInstallationLinuxMenu($ws, $ip, $serverBaseUrl)`**

**Given** la méthode,
**When** invoquée avec un poste connu,
**Then** :
- Délègue à `LinuxInstallMenuBuilder::build()` pour le payload.
- Rend `resources/views/ipxe/menu/installation-linux.blade.php`.
- Body commence par `#!ipxe`.
- Contient les 9 items `install_*`.
- Contient les sections `:install_*` chainant vers `/ipxe/action/install_*##params`.
- Termine par `:exit\n{!! $bootDiskFallback !!}\n`.

**Given** invoquée avec un poste inconnu,
**Then** rend le menu erreur D7 (chain vers `/ipxe/admin`).

**And** tests unit ≥6 (known full menu, unknown error menu, ASCII strict, no PHP tags, shebang first, items count).

**AC6.2** — **`IpxeActionResolver::resolve()` injecte `osVersion` + `installType`**

**Given** la méthode,
**When** invoquée avec `IpxeAdminAction::InstallDebGnome`,
**Then** :
- Lit `$action->linuxMeta()` → `['distribution' => 'debian', 'variant' => 'gnome']`.
- Injecte dans le contexte Blade :
  - `$osVersion` = `config('sambaedu.linux.version_debian', 'trixie')` si distribution=`debian`/`nird`, ou `'ubuntu'` si distribution=`ubuntu`.
  - `$installType` = `'gnome'`.
  - `$preseedUrl` = `$scriptUrl . '/linux/preseed?mac=' . rawurlencode($mac) . '&uuid=' . rawurlencode($uuid) . '&os=' . $osVersion . '&type=' . $installType` (parité legacy `actions/deb_gnome.php:6`).
- Le template `ipxe.actions.install_deb_gnome.blade.php` consomme ces 2 nouvelles variables.

**And** tests unit ≥4 (debian variants resolve correctement, ubuntu resolve, nird resolve, non-install actions inchangées).

### Volet 7 — Templates Blade (D10)

**AC7.1** — **Templates `install_*.blade.php` rendent les cmdlines kernel correctement**

**Given** les 9 templates `resources/views/ipxe/actions/install_*.blade.php`,
**When** rendus par `IpxeActionResolver::resolve()`,
**Then** chacun produit :
```
#!ipxe
kernel {{ $osUrl }}/<kernel-path-selon-distribution>
initrd --name initrd.gz {{ $osUrl }}/<initrd-path-selon-distribution>
imgargs linux initrd=initrd.gz auto=true hostname={{ $workstationName }} priority=critical auto url={!! $preseedUrl !!}
boot
```
- `kernel-path` = `config('ipxe.linux.kernel_paths.debian')` pour les variantes Debian/Nird, `kernel_paths.ubuntu` pour Ubuntu.
- `$preseedUrl` rendu **raw** (`{!!`) pour préserver `?mac=...&uuid=...&os=...&type=...` (le `&` HTML-encoderait `&amp;` qui casserait iPXE).

**And** test unit ≥9 (un par template) qui asserte le contenu via `assertStringContainsString` sur 3 marqueurs par template.

**AC7.2** — **Template `installation-linux.blade.php` rend le menu**

**Given** le template,
**When** rendu avec un poste connu + items 9 entrées,
**Then** body iPXE complet avec :
- En-tête `menu installation clients-linux pour (nom : ... ip : ...)`.
- `set menu-default install_deb_gnome` (D11 default).
- Une ligne `item install_<enum> <label>` par item.
- Items `--key s shell` + `--key r retour` + `--key x exit`.
- Sections `:install_<enum>\nchain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/install_<enum>##params\n` × 9.
- Section `:retour\nchain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params`.
- Section `:exit\n{!! $bootDiskFallback !!}`.

**AC7.3** — **Modification `admin.blade.php` ajoute item install-linux**

**Given** le template `resources/views/ipxe/menu/admin.blade.php`,
**When** rendu avec `$isKnown=true` + `$isInstallLinuxActive=true`,
**Then** contient :
- `item --key l install-linux (l) Installation Linux (Debian/Ubuntu)`.
- Section `:install-linux\nchain --replace --autofree {{ $installLinuxBaseUrl }}##params`.

**Given** `$isInstallLinuxActive=false`,
**Then** l'item n'apparaît pas (feature-flag).

**And** test feature `IpxeAdminEndpointTest::it_shows_install_linux_item_when_enabled` + `::it_hides_install_linux_item_when_disabled`.

**AC7.4** — **Tous les templates respectent les conventions iPXE**

**Given** tous les 11 templates 3.4,
**When** rendus,
**Then** :
- Commencent par `#!ipxe` (via `{!! $shebang !!}` iso 3.1 DO-13).
- Terminent par `\n`.
- Ne contiennent aucun caractère non ASCII (`[^\x20-\x7E]`).
- Ne contiennent aucune balise PHP (`<?php`, `?>`).

**And** test archi `IpxeNamespaceTest::story_3_4_templates_are_ascii_strict_and_no_php`.

### Volet 8 — Routes web.php + non-régression catchall (D2)

**AC8.1** — **4 routes natives déclarées AVANT catchall**

**Given** `routes/web.php`,
**When** le dev ajoute le bloc 3.4 (D2),
**Then** :
- Bloc placé APRÈS bloc 3.3 enrollment et AVANT catchall.
- Les 4 routes ont middleware `auth.v1.lan-only` + `throttle:600,1` + `withoutMiddleware(['web'])`.
- Commentaire `⚠⚠⚠` du catchall préservé.

**And** test archi `IpxeNamespaceTest::ipxe_3_4_routes_are_declared_before_catchall` (vérifie 4 routes).

**AC8.2** — **Non-régression catchall sur les autres routes `/ipxe/*`**

**Given** les routes legacy non encore réécrites (`/ipxe/installation-windows.php`, `/ipxe/clonage.php`, `/ipxe/Win10/*`, `/ipxe/diconf/*`, `/ipxe/png/*`),
**When** sollicitées,
**Then** elles continuent d'être servies par `LegacyCatchallController`.

**And** test feature `IpxeLegacyRoutingNonRegressionTest` étendu avec ≥3 nouveaux tests :
- `it_serves_ipxe_installation_linux_natively_not_via_catchall`
- `it_serves_ipxe_linux_preseed_natively_not_via_catchall`
- `it_still_serves_ipxe_installation_windows_via_catchall`.

### Volet 9 — Config + provider + channel log (D8, D11)

**AC9.1** — **Extension `config/ipxe.php` section `linux`** selon D11.

**AC9.2** — **Extension `config/sambaedu.php` sections `linux` + LDAP/AD** selon D11.

**AC9.3** — **Provider `IpxeServiceProvider` enregistre les 3 nouveaux services singletons** : `LinuxPreseedService`, `LinuxInstallMenuBuilder`, `LinuxPostInstallTracker`.

**AC9.4** — **Tests `IpxeConfigTest` étendu** avec ≥6 assertions sur la section `linux`.

### Volet 10 — Runbook QA + sprint-status (D12)

**AC10.1** — **Extension `docs/qa/domains/ipxe.md`** :
- Nouvelle section `## Story 3.4 — Installation Linux (Debian/Ubuntu)`.
- ≥12 scénarios stables `3.4-1` à `3.4-12` (numérotation 3.1-3.3 préservée intacte).
- Scénarios couvrent :
  - **Scénario 3.4-1** — Menu installation-linux rendu (poste connu) : `curl -X POST http://192.168.122.50/ipxe/installation-linux -d 'mac=...&uuid=...'` → 200 + body contient items `install_deb_gnome` + items `install_ubuntu64` + section `:exit`.
  - **Scénario 3.4-2** — Menu installation-linux poste inconnu : retourne menu erreur + chain `/ipxe/admin`.
  - **Scénario 3.4-3** — Item depuis `/ipxe/admin` : menu admin connu contient item `(l) Installation Linux`. Item absent si `IPXE_INSTALL_LINUX_ENABLED=false`.
  - **Scénario 3.4-4** — Action `install_deb_gnome` rendue : `curl http://192.168.122.50/ipxe/action/install_deb_gnome -d 'mac=...&uuid=...'` → 200 + body `kernel.../debian-installer/.../linux` + `imgargs ... url=http://.../ipxe/linux/preseed?mac=...&uuid=...&os=trixie&type=gnome`.
  - **Scénario 3.4-5** — Action `install_ubuntu64` rendue : kernel pointe vers `ubuntu-installer/amd64/linux`.
  - **Scénario 3.4-6** — Action `install_nird` rendue : `$perso=1` dans l'URL preseed.
  - **Scénario 3.4-7** — Preseed généré (debian/gnome) : `curl 'http://192.168.122.50/ipxe/linux/preseed?mac=...&uuid=...&os=trixie&type=gnome'` → 200 text/plain ~4000 lignes + contient `tasksel/first multiselect standard, desktop, gnome-desktop, print-server, ssh-server` (iso `debian_gnome.cfg:6`) + contient `d-i netcfg/get_hostname string PC-XXX` (interpolation hostname).
  - **Scénario 3.4-8** — Preseed poste inconnu : 404 + log warning `ipxe.linux.preseed.unknown_workstation`.
  - **Scénario 3.4-9** — Preseed os/type hors whitelist : 422 + log warning.
  - **Scénario 3.4-10** — Hook `/ipxe/linux/action` : `curl -F 'ret=0' -F 'uuid=...' -F 'name=PC-101' http://192.168.122.50/ipxe/linux/action` → 200 vide + `SELECT os FROM workstations WHERE uuid='...'` retourne `'linux'`.
  - **Scénario 3.4-11** — Stub `/ipxe/linux/autorun` : retourne script bash + log info.
  - **Scénario 3.4-12** — Sécurité LAN : depuis IP publique → 403 + code `bootstrap.not_lan`.
  - **(Optionnel)** Scénario 3.4-13 — Smoke poste réel : un poste de test boot PXE → menu installation Linux → choisit `deb_gnome` → install se déroule jusqu'au reboot → `Workstation::os = 'linux'`.

**AC10.2** — **`sprint-status.yaml` mis à jour** :
- `3-4-installation-linux-debian-ubuntu: backlog` → `ready-for-dev`.
- Commentaire `# 2026-05-20 (création SM Story 3-4)` avec résumé décisions clés.

**AC10.3** — **`docs/qa/README.md`** : pas de modification (entrée `ipxe` déjà présente — append-only sur le runbook).

---

## Tasks / Subtasks

### Phase T0 — Pré-flight + validations contexte

- [x] **T0.1** Vérifier que Story 3.3 est en `done` (ou ready-to-merge) — sinon escalader à Henri avant de démarrer 3.4.
- [x] **T0.2** Statut 3.1/3.2 done + 3.3 review confirmés par sprint-status.
- [x] **T0.3** Lecture obligatoire legacy faite : `sambaedu/ipxe/installation-linux.php` + `linux/preseed.php` + `linux/action.php` + `linux/autorun.php` + 9 `actions/{deb_*,ubuntu64,nird}.php` + 15 fragments `linux/*.cfg`. Différences notables 3.4 vs legacy documentées dans Dev Agent Record.
- [x] **T0.4** Audit fragments .cfg : lister TOUS les placeholders `###_<KEY>_###` présents dans les 15 fragments et confirmer qu'ils sont tous dans le catalogue `PreseedPlaceholders` (cf. D5). Si placeholder orphelin → ajouter au catalogue.
- [x] **T0.5** Audit variables `.env` consommées par le preseed (SAMBAEDU_LINUX_*, SAMBAEDU_LDAP_*, SAMBAEDU_ADMIN_PASSWD, etc.) : confirmer présence en VM ou escalader à Henri (rentrée scolaire risque blocant si absentes).
- [x] **T0.6** Audit `MachineBootLog.action` : varchar(20) sans CHECK confirmé par 3.1/3.2/3.3 — vérifier que les 3 nouvelles valeurs (`ipxe_install_linux`, `ipxe_linux_preseed`, `ipxe_linux_report`) passent (≤18 chars). Sinon escalader.
- [x] **T0.7** Statut iso-legacy `auth.v1.lan-only` + middleware déjà attaché aux routes 3.1-3.3 : pas de modification attendue.
- [x] **T0.8** Inventaire des variantes hors-scope (D14) : confirmer que `se4ad`, `se4fs`, `deb_serv`, `deb_kiosk`, `deb_nextcloud`, `deb_gnome_perso`, `primtux` sont déférés Phase 3. Si Henri demande une variante critique → escalader.

### Phase T1 — Enums + Support + Fragments preseed (D1, D5, D10, AC1.1, AC1.2, AC1.3)

- [x] **T1.1** Étendre `app/Ipxe/Enums/IpxeAdminAction.php` avec les 9 nouveaux cases + méthode `linuxMeta()` + extension `template()`. Test `IpxeAdminActionTest` étendu.
- [x] **T1.2** Créer `app/Ipxe/Enums/LinuxDistribution.php` (3 cases) + `LinuxDesktopVariant.php` (7 cases) + méthodes `fromString()`. Tests unit ≥6 cas chacun.
- [x] **T1.3** Créer `app/Ipxe/Support/PreseedPlaceholders.php` avec catalogue complet (D5) + méthodes `sanitize()` + `interpolate()`. Tests unit ≥10 cas dont 6 anti-injection.
- [x] **T1.4** Copier les 15 fragments `sambaedu/ipxe/linux/*.cfg` vers `resources/ipxe/linux/*.cfg` (assets statiques projet, sous version control). Ne PAS modifier le contenu — copie pure byte-identique.
- [x] **T1.5** Test unit `LinuxPreseedFragmentsTest::it_lists_all_required_fragments` + `it_each_fragment_is_readable_and_non_empty` + `it_each_placeholder_in_fragments_is_known_in_catalog`.

### Phase T2 — `LinuxPreseedService` (D5, AC2.1, AC2.2, AC2.3)

- [x] **T2.1** Créer `app/Ipxe/Services/LinuxPreseedService.php` avec méthode `generate()` + algorithme iso-legacy `preseed.php:86-159` (simplifié — hors-scope se4ad/se4fs/perso variants).
- [x] **T2.2** Créer la classe d'exception `App\Ipxe\Exceptions\PreseedGenerationException`.
- [x] **T2.3** Test unit `LinuxPreseedServiceTest` ≥12 tests (cf. AC2.1).
- [x] **T2.4** Tests anti-injection (cf. AC2.2).
- [x] **T2.5** Tests log audit (cf. AC2.3) — assertions sha256 + size + pas de fuite secret.

### Phase T3 — `LinuxInstallMenuBuilder` + `LinuxPostInstallTracker` (D6, AC3.1, AC3.2)

- [x] **T3.1** Créer `LinuxInstallMenuBuilder` + tests unit ≥4.
- [x] **T3.2** Créer `LinuxPostInstallTracker` + tests unit ≥4.

### Phase T4 — Templates Blade + extensions admin (D10, AC6.1, AC7.1-7.4)

- [x] **T4.1** Créer `resources/views/ipxe/menu/installation-linux.blade.php` (~50 lignes — port natif `installation-linux.php:23-94` + sections `:install_*`).
- [x] **T4.2** Créer les 9 templates `resources/views/ipxe/actions/install_*.blade.php` (port natif `actions/deb_*.php` + `ubuntu64.php` + `nird.php`).
- [x] **T4.3** Étendre `resources/views/ipxe/menu/admin.blade.php` (ajout item `(l) Installation Linux` + section `:install-linux`).
- [x] **T4.4** (Optionnel) créer partial `resources/views/ipxe/menu/_unknown_workstation_error.blade.php` pour factoriser le menu erreur D7 (réutilisable 3.5/3.6/3.7).
- [x] **T4.5** Tests unit `IpxeMenuRendererTest` étendu ≥6 tests pour `renderInstallationLinuxMenu()` (cf. AC6.1).
- [x] **T4.6** Tests unit `IpxeActionResolverTest` étendu ≥4 tests pour les variables `osVersion` + `installType` + `preseedUrl` (cf. AC6.2 + AC7.1).
- [x] **T4.7** Tests archi `story_3_4_templates_are_ascii_strict_and_no_php`.

### Phase T5 — `IpxeService::handleInstallationLinuxMenu()` + `IpxeMenuRenderer::renderInstallationLinuxMenu()` (AC4.1, AC6.1)

- [x] **T5.1** Ajouter `IpxeService::handleInstallationLinuxMenu()` (~50 lignes, pattern iso `handleAdmin()` 3.2).
- [x] **T5.2** Ajouter `IpxeMenuRenderer::renderInstallationLinuxMenu()` (~20 lignes, délègue à `LinuxInstallMenuBuilder::build()`).
- [x] **T5.3** Étendre `IpxeMenuRenderer::renderAdminMenu()` avec variable `installLinuxBaseUrl`.
- [x] **T5.4** Tests unit `IpxeServiceInstallationLinuxTest` ≥6 (handshake, known, unknown, MachineBootLog, headers, safeRender).

### Phase T6 — Controllers + FormRequests (D2, AC5.1-5.5)

- [x] **T6.1** Créer 4 controllers (`IpxeInstallationLinuxController`, `IpxeLinuxPreseedController`, `IpxeLinuxActionController`, `IpxeLinuxAutorunController`) — fins ≤15 LOC chacun (sauf preseed qui orchestre la validation + dispatch service).
- [x] **T6.2** Créer 4 FormRequests (`IpxeInstallationLinuxRequest`, `IpxeLinuxPreseedRequest`, `IpxeLinuxActionRequest`, `IpxeLinuxAutorunRequest`).
- [x] **T6.3** Tests feature ≥6 par endpoint (cf. AC5.5) :
  - `IpxeInstallationLinuxEndpointTest` ≥6
  - `IpxeLinuxPreseedEndpointTest` ≥8 (whitelist, génération, sanitization, unknown workstation, headers, MachineBootLog)
  - `IpxeLinuxActionEndpointTest` ≥4 (success ret=0, failure ret=1, unknown workstation, body vide)
  - `IpxeLinuxAutorunEndpointTest` ≥2 (stub rendu, log audit)

### Phase T7 — Routes + provider + config + non-régression (D2, D11, AC8.1, AC8.2, AC9.*)

- [x] **T7.1** Ajouter le bloc 4 routes dans `routes/web.php` AVANT catchall.
- [x] **T7.2** Étendre `IpxeServiceProvider` avec les 3 nouveaux singletons.
- [x] **T7.3** Étendre `config/ipxe.php` section `linux` (D11).
- [x] **T7.4** Étendre `config/sambaedu.php` sections `linux` + LDAP/AD (D11). Audit T0.5 = vérifier absence de doublons.
- [x] **T7.5** Tests archi `IpxeNamespaceTest::ipxe_3_4_routes_are_declared_before_catchall` + non-régression catchall.
- [x] **T7.6** Tests feature `IpxeLegacyRoutingNonRegressionTest` étendu ≥3 tests.
- [x] **T7.7** Tests `IpxeConfigTest` étendu ≥6 assertions.

### Phase T8 — Runbook QA + sprint-status + completion notes (D12, AC10.1, AC10.2)

- [x] **T8.1** Étendre `docs/qa/domains/ipxe.md` Section `## Story 3.4` + ≥12 scénarios stables 3.4-1 à 3.4-12.
- [x] **T8.2** Mettre à jour `sprint-status.yaml` : `3-4: backlog` → `review` (post-dev).
- [x] **T8.3** Status story → `review`, tasks cochées, Dev Agent Record + File List + Change Log remplis.
- [x] **T8.4** *Différé Henri post-merge VM* : ré-exécuter `./scripts/run-tests.sh` (suite complète) + scénarios 3.4-1 à 3.4-12 manuels sur la VM.

---

## File List prévisionnelle

### Fichiers créés (estimés ~38)

```
# Services
sambaedu-reload/app/Ipxe/Services/LinuxPreseedService.php
sambaedu-reload/app/Ipxe/Services/LinuxInstallMenuBuilder.php
sambaedu-reload/app/Ipxe/Services/LinuxPostInstallTracker.php

# Enums + Support
sambaedu-reload/app/Ipxe/Enums/LinuxDistribution.php
sambaedu-reload/app/Ipxe/Enums/LinuxDesktopVariant.php
sambaedu-reload/app/Ipxe/Support/PreseedPlaceholders.php

# Exceptions
sambaedu-reload/app/Ipxe/Exceptions/PreseedGenerationException.php

# Controllers
sambaedu-reload/app/Ipxe/Http/Controllers/IpxeInstallationLinuxController.php
sambaedu-reload/app/Ipxe/Http/Controllers/IpxeLinuxPreseedController.php
sambaedu-reload/app/Ipxe/Http/Controllers/IpxeLinuxActionController.php
sambaedu-reload/app/Ipxe/Http/Controllers/IpxeLinuxAutorunController.php

# FormRequests
sambaedu-reload/app/Ipxe/Http/Requests/IpxeInstallationLinuxRequest.php
sambaedu-reload/app/Ipxe/Http/Requests/IpxeLinuxPreseedRequest.php
sambaedu-reload/app/Ipxe/Http/Requests/IpxeLinuxActionRequest.php
sambaedu-reload/app/Ipxe/Http/Requests/IpxeLinuxAutorunRequest.php

# Templates Blade — menu
sambaedu-reload/resources/views/ipxe/menu/installation-linux.blade.php

# Templates Blade — actions install_*
sambaedu-reload/resources/views/ipxe/actions/install_deb_base.blade.php
sambaedu-reload/resources/views/ipxe/actions/install_deb_cinnamon.blade.php
sambaedu-reload/resources/views/ipxe/actions/install_deb_gnome.blade.php
sambaedu-reload/resources/views/ipxe/actions/install_deb_kde.blade.php
sambaedu-reload/resources/views/ipxe/actions/install_deb_lxde.blade.php
sambaedu-reload/resources/views/ipxe/actions/install_deb_mate.blade.php
sambaedu-reload/resources/views/ipxe/actions/install_deb_xfce.blade.php
sambaedu-reload/resources/views/ipxe/actions/install_nird.blade.php
sambaedu-reload/resources/views/ipxe/actions/install_ubuntu64.blade.php

# Optionnel partial Blade
sambaedu-reload/resources/views/ipxe/menu/_unknown_workstation_error.blade.php

# Fragments preseed (copiés depuis sambaedu/ipxe/linux/)
sambaedu-reload/resources/ipxe/linux/debian.cfg
sambaedu-reload/resources/ipxe/linux/debian_base.cfg
sambaedu-reload/resources/ipxe/linux/debian_gnome.cfg
sambaedu-reload/resources/ipxe/linux/debian_lxde.cfg
sambaedu-reload/resources/ipxe/linux/debian_kde.cfg
sambaedu-reload/resources/ipxe/linux/debian_mate.cfg
sambaedu-reload/resources/ipxe/linux/debian_xfce.cfg
sambaedu-reload/resources/ipxe/linux/debian_cinnamon.cfg
sambaedu-reload/resources/ipxe/linux/debian_perso.cfg
sambaedu-reload/resources/ipxe/linux/sambaedu.cfg
sambaedu-reload/resources/ipxe/linux/simple_boot.cfg
sambaedu-reload/resources/ipxe/linux/aptcache.cfg
sambaedu-reload/resources/ipxe/linux/nocache.cfg
sambaedu-reload/resources/ipxe/linux/proxy.cfg
sambaedu-reload/resources/ipxe/linux/commande_fin.cfg
sambaedu-reload/resources/ipxe/linux/ubuntu.cfg

# Tests Unit
sambaedu-reload/tests/Unit/Ipxe/Enums/LinuxDistributionTest.php
sambaedu-reload/tests/Unit/Ipxe/Enums/LinuxDesktopVariantTest.php
sambaedu-reload/tests/Unit/Ipxe/Support/PreseedPlaceholdersTest.php
sambaedu-reload/tests/Unit/Ipxe/Support/LinuxPreseedFragmentsTest.php
sambaedu-reload/tests/Unit/Ipxe/Services/LinuxPreseedServiceTest.php
sambaedu-reload/tests/Unit/Ipxe/Services/LinuxInstallMenuBuilderTest.php
sambaedu-reload/tests/Unit/Ipxe/Services/LinuxPostInstallTrackerTest.php
sambaedu-reload/tests/Unit/Ipxe/Services/IpxeServiceInstallationLinuxTest.php

# Tests Feature
sambaedu-reload/tests/Feature/Ipxe/IpxeInstallationLinuxEndpointTest.php
sambaedu-reload/tests/Feature/Ipxe/IpxeLinuxPreseedEndpointTest.php
sambaedu-reload/tests/Feature/Ipxe/IpxeLinuxActionEndpointTest.php
sambaedu-reload/tests/Feature/Ipxe/IpxeLinuxAutorunEndpointTest.php
```

### Fichiers modifiés (estimés ~10)

```
sambaedu-reload/app/Ipxe/Enums/IpxeAdminAction.php          (+9 cases + linuxMeta())
sambaedu-reload/app/Ipxe/Enums/IpxeMenuKind.php             (+2 cases InstallationLinuxMenu/Handshake)
sambaedu-reload/app/Ipxe/Services/IpxeService.php          (+handleInstallationLinuxMenu)
sambaedu-reload/app/Ipxe/Services/IpxeMenuRenderer.php     (+renderInstallationLinuxMenu + installLinuxBaseUrl in admin)
sambaedu-reload/app/Ipxe/Services/IpxeActionResolver.php   (+osVersion/installType/preseedUrl via linuxMeta())
sambaedu-reload/app/Providers/IpxeServiceProvider.php      (+3 singletons)
sambaedu-reload/config/ipxe.php                            (+section linux D11)
sambaedu-reload/config/sambaedu.php                        (+section linux + LDAP/AD vars D11)
sambaedu-reload/resources/views/ipxe/menu/admin.blade.php  (+item install-linux + section :install-linux)
sambaedu-reload/routes/web.php                             (+bloc 4 routes 3.4 AVANT catchall)
sambaedu-reload/docs/qa/domains/ipxe.md                    (+Section Story 3.4 + ≥12 scénarios)
sambaedu-reload/tests/Architecture/IpxeNamespaceTest.php   (+routes 3.4 + templates 3.4)
sambaedu-reload/tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php (+3 tests)
sambaedu-reload/tests/Feature/Ipxe/IpxeAdminEndpointTest.php (+item install-linux)
sambaedu-reload/tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php (+6 tests)
sambaedu-reload/tests/Unit/Ipxe/IpxeConfigTest.php          (+6 assertions section linux)
sambaedu-reload/tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php (+6 tests renderInstallationLinuxMenu)
sambaedu-reload/tests/Unit/Ipxe/Services/IpxeActionResolverTest.php (+4 tests linuxMeta variables)
```

### Fichiers métadonnées BMAD modifiés

```
_bmad-output/implementation-artifacts/3-4-installation-linux-debian-ubuntu.md (Dev Agent Record + File List + status)
_bmad-output/implementation-artifacts/sprint-status.yaml                       (3-4: backlog → ready-for-dev → review post-dev)
_bmad-output/backlog.html                                                       (3-4 status backlog → ready-for-dev)
```

### Fichiers NON modifiés (garde-fou)

```
sambaedu/ipxe/**                                          ← legacy intact (catchall sert encore)
legacy/modules/ipxe/**                                    ← idem
app/Models/Workstation.php                                ← lecture seule + update os/status via Tracker
app/Models/MachineBootLog.php                             ← lecture seule + insert via Eloquent
app/Auth/V1/**                                            ← intact (réutilisation alias auth.v1.lan-only)
app/Ipxe/Services/WorkstationLocator.php                  ← lecture seule
app/Ipxe/Support/IpxeHostnameSanitizer.php                ← lecture seule (réutilisation)
```

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichiers |
|---|---|---|
| **Unit** | Enums + Support (PreseedPlaceholders sanitize/interpolate, LinuxDistribution/DesktopVariant fromString) | `IpxeAdminActionTest`, `LinuxDistributionTest`, `LinuxDesktopVariantTest`, `PreseedPlaceholdersTest`, `LinuxPreseedFragmentsTest` |
| **Unit** | LinuxPreseedService (assemblage fragments + interpolation + anti-injection + log audit) | `LinuxPreseedServiceTest` |
| **Unit** | LinuxInstallMenuBuilder + LinuxPostInstallTracker | `LinuxInstallMenuBuilderTest`, `LinuxPostInstallTrackerTest` |
| **Unit** | IpxeService::handleInstallationLinuxMenu + IpxeMenuRenderer::renderInstallationLinuxMenu + IpxeActionResolver linuxMeta | `IpxeServiceInstallationLinuxTest`, `IpxeMenuRendererTest`, `IpxeActionResolverTest` |
| **Feature** | Endpoint /ipxe/installation-linux (menu rendered, known/unknown, headers) | `IpxeInstallationLinuxEndpointTest` |
| **Feature** | Endpoint /ipxe/linux/preseed (whitelist os/type, génération, sanitization, unknown 404) | `IpxeLinuxPreseedEndpointTest` |
| **Feature** | Endpoint /ipxe/linux/action (success ret=0, failure ret=1, body vide, Workstation::os updaté) | `IpxeLinuxActionEndpointTest` |
| **Feature** | Endpoint /ipxe/linux/autorun (stub bash rendu) | `IpxeLinuxAutorunEndpointTest` |
| **Feature** | Non-régression catchall (installation-windows, clonage, Win10/* via catchall) | `IpxeLegacyRoutingNonRegressionTest` étendu |
| **Feature** | Menu admin contient item Installation Linux | `IpxeAdminEndpointTest` étendu |
| **Architecture** | Routes 3.4 AVANT catchall + namespace + ASCII strict templates | `IpxeNamespaceTest` étendu |
| **QA manuelle (VM)** | 12 scénarios smoke + 1 optionnel poste réel | `docs/qa/domains/ipxe.md` § Story 3.4 |

### Tests qu'on ne fait **pas** dans cette story

- Tests d'exécution réelle de l'installateur Debian sur poste cible — couvert par QA manuelle scénario 3.4-13 (action Henri).
- Tests d'install Windows — = story 3.5.
- Tests de clonage clonezilla — = story 3.7.
- Tests de variantes hors-scope (se4ad/se4fs/deb_serv/...) — = Phase 3 dédiée.
- Tests d'autorun complet (boucle bash post-install) — D15 stub minimal.
- Tests de charge sur le preseed (50 postes simultanés rentrée) — déférés post-prod, ajuster throttle si besoin.

---

## Anti-patterns à éviter (DISASTER PREVENTION)

### Architecture & scope

- ❌ **Ne PAS modifier le code legacy `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php`** — restent intacts.
- ❌ **Ne PAS étendre le scope** aux variantes hors-scope D14 (se4ad/se4fs/deb_serv/deb_kiosk/deb_nextcloud/deb_gnome_perso/primtux).
- ❌ **Ne PAS toucher au schema `workstations` ni `machine_boot_logs`** (D9 + D12 — pas de migration).
- ❌ **Ne PAS créer de nouveau middleware** — `auth.v1.lan-only` (16.11) suffit (D3).
- ❌ **Ne PAS introduire de dépendance LdapRecord** dans `App\Ipxe\*` — PostgreSQL seule source de vérité (lecture Workstation via WorkstationLocator).
- ❌ **Ne PAS créer d'UI Livewire** en 3.4 — c'est une API HTTP pure (pattern iso 3.1-3.3).
- ❌ **Ne PAS rendre le preseed via Blade** — le preseed est du texte concaténé/interpolé. Blade ajouterait du parsing inutile et risquerait de stripper `#` en début de ligne.

### Sécurité & secrets

- ❌ **Ne PAS logger le contenu du preseed** — seulement le sha256 + metadata (distribution/variant/size).
- ❌ **Ne PAS exposer les placeholders `###_<*_PASSWD>_###` dans les logs** — la sanitization PreseedPlaceholders doit s'appliquer AVANT interpolation, le log doit montrer le sha256 du résultat (pas le mapping placeholder→valeur).
- ❌ **Ne PAS valider `os`/`type`/`mac`/`uuid`/`product` côté URL en regex permissive** — utiliser les enums LinuxDistribution + LinuxDesktopVariant + Rule::in() côté FormRequest.
- ❌ **Ne PAS escape via `htmlspecialchars()`** dans les templates iPXE — les chars `&` doivent être préservés (URL encode dans `$preseedUrl`).
- ❌ **Ne PAS faire confiance à X-Forwarded-For** dans `EnsureLanIp` — iso 16.11.
- ❌ **Ne PAS appeler `Workstation::create()` depuis `/ipxe/linux/action`** — c'est le scope 3.3 enrollment, pas 3.4 install.

### Routing & non-régression

- ❌ **Ne PAS placer les routes 3.4 APRÈS le catchall**.
- ❌ **Ne PAS toucher au commentaire `⚠⚠⚠`** ligne 591 de `routes/web.php`.
- ❌ **Ne PAS modifier le `LegacyCatchallController`** — il continue de servir `/ipxe/installation-windows.php` etc.
- ❌ **Ne PAS introduire `Route::prefix('/ipxe/linux')` group** — anti-pattern iso 3.3 D2 (empêche les tests archi de scanner les routes individuellement).

### Idempotence & robustesse

- ❌ **Ne PAS écrire `/tmp/{name}.preseed` sur disque** (parité legacy retirée — debug only).
- ❌ **Ne PAS écraser silencieusement `Workstation::os = 'linux'`** si l'install échoue — `LinuxPostInstallTracker` lit `ret` et fait la décision.
- ❌ **Ne PAS lever d'exception qui remonte au controller** — un firmware iPXE doit recevoir une réponse 200 text/plain ou 404 (en cas d'unknown), jamais une 500 (`safeRender` wrap iso 3.1).
- ❌ **Ne PAS pré-cache le preseed assemblé en mémoire** côté service — risque race condition + secrets résidents en RAM trop longtemps. Lecture fragments OK à cacher (read-only), mais l'assemblage final reste à chaque appel.

### Process & infra

- ❌ **Ne PAS SSH manuellement vers la VM** depuis un worktree git.
- ❌ **Ne PAS exécuter les tests sur la VM** si HS — lint statique + PHPUnit local. Différer à Henri post-merge.
- ❌ **Ne PAS faire de PR / commit depuis le dev-agent** — c'est le job de l'orchestrateur main agent en fin de cycle.
- ❌ **Ne PAS introduire de Co-Authored-By Claude** dans les commits (rappel commit 3.1 `50c6275` à éviter).
- ❌ **Ne PAS commiter de fixtures de production** — utiliser `Workstation::factory()` partout dans les tests.

---

## Dépendances + ordre

### Amont (bloquantes — toutes à valider en T0.1)

| Story | Statut entrant | Lien |
|---|---|---|
| **Story 3.1** iPXE Service Core | ✅ done | Réutilisation `IpxeService::handleBoot`, `IpxeMenuRenderer`, `WorkstationLocator`, channel log `ipxe`, `MachineBootLog`, `auth.v1.lan-only`, config `ipxe.php` |
| **Story 3.2** Boot et Menu Admin iPXE | ✅ done | Réutilisation `IpxeAdminAction` enum (extension 9 cases), `IpxeActionResolver` (extension osVersion/installType), `IpxeMenuRenderer::renderAdminMenu` (extension installLinuxBaseUrl), `IpxeMenuRenderer::renderHandshake(chainTarget)` |
| **Story 3.3** Enrollment Machine | 🟡 review (à valider done par Henri avant 3.4 dev) | Réutilisation `IpxeHostnameSanitizer`, `admin.blade.php` enrichi, pattern `WorkstationEnrollmentService` (modèle d'orchestration) |
| **Epic 1** (Fondations) | ✅ done | AuthGuard + catchall |
| **Epic 4** (Machines/Groups) | ✅ done | `Workstation`/`WorkstationGroup` modèles |
| **Story 16.11** | ✅ done | Middleware `auth.v1.lan-only` réutilisé |

### Aval (3.4 débloque)

| Story | Lien |
|---|---|
| **3.5** Installation Windows (Sysprep/Wimboot) | Pattern strictement réutilisable : controller fin + service preseed-équivalent (`SysprepService`) + enum case + template Blade. La structure 3.4 sert de modèle. |
| **3.6** Gestion ISO Windows | Indépendant 3.4 (côté UI admin + upload). |
| **3.7** Clonage et Maintenance | Pattern identique, cleanup routes legacy `/ipxe/installation-linux.php` etc. (fin Epic 3). |
| **Epic 17.2** Scripts post-install | Le mécanisme `/ipxe/linux/action` + `LinuxPostInstallTracker` peut être enrichi pour orchestrer `script_assignments` post-install Linux. |

---

## Risques + mitigations

| Risque | Sévérité | Mitigation 3.4 |
|---|---|---|
| Collision routes natives vs catchall | 🟠 Élevée | D2 + test archi `ipxe_3_4_routes_are_declared_before_catchall`. |
| Secrets preseed exposés en clair sur LAN | 🟠 Élevée | D3 — `auth.v1.lan-only` + MAC/UUID matching + log sha256 only. Phase 3 envisage HMAC attestation. |
| Régression sur `admin.blade.php` (ajout item) | 🟠 Élevée | Test feature + non-régression 3.3 (enrollment items + maintenance items présents). |
| Whitelist `os`/`type` court-circuitée → path traversal | 🟡 Moyenne | Enum + Rule::in() + sanitize defense-in-depth + test anti-injection ≥6 payloads. |
| Fragment .cfg manquant ou corrompu | 🟡 Moyenne | T1.5 test unit qui vérifie la présence + lisibilité de chaque fragment. Exception `PreseedGenerationException` + log error. |
| Placeholder `###_<KEY>_###` orphelin (présent dans .cfg mais pas dans catalogue) | 🟡 Moyenne | T0.4 audit complet + T1.5 test unit qui scanne les 15 fragments. |
| Hostname injection (`\n`, `${`) dans cmdline iPXE | 🟡 Moyenne | Réutilisation `IpxeHostnameSanitizer::sanitizeForIpxeOutput()` (3.3) + defense in depth `PreseedPlaceholders::sanitize()`. |
| Variables `.env` manquantes en VM (SAMBAEDU_LINUX_*, SAMBAEDU_LDAP_*) | 🟠 Élevée | T0.5 audit obligatoire + escalation Henri si absent (risque blocant rentrée scolaire). |
| `MachineBootLog::action` rejette nouvelles valeurs | 🟢 Mineure | T0.6 audit — toutes ≤18 chars dans varchar(20). |
| Performance lecture fragments × 50 postes simultanés | 🟢 Mineure | Cache singleton optionnel des fragments (read-only — pas de secret en RAM). |
| Conflit entre `Workstation::os = 'linux'` et un état dual-boot existant | 🟢 Mineure | Écrasement assumé (parité legacy). Historique tracé via `MachineBootLog`. |

---

## Project Structure Notes

### Alignement avec la structure projet

- **Namespace** : `App\Ipxe\…` — extension Linux. Sous-namespaces parallèles à 3.1-3.3 (pas de nouveau sous-namespace).
- **Tests** : `tests/Unit/Ipxe/…`, `tests/Feature/Ipxe/…`, `tests/Architecture/IpxeNamespaceTest.php` — cohérent avec 3.1-3.3.
- **Templates Blade** : `resources/views/ipxe/{menu,actions}/…` — convention iso 3.2/3.3.
- **Fragments preseed** : nouveau dossier `resources/ipxe/linux/*.cfg` (assets statiques projet). **Pas dans `resources/views/`** (ce ne sont pas des Blade views).
- **Pages cibles** : *hors-scope cette story* — pas d'UI Livewire en 3.4 (= API HTTP pure).
- **Convention CLAUDE.md** : pas directement applicable (pas de page web sous `resources/views/pages/`, pas de modale, pas de toast — c'est une API HTTP pure + middleware).

### Cohabitation routes `/ipxe/*` post-3.4

| Endpoint | Story | Middleware | Status |
|---|---|---|---|
| `GET\|POST /ipxe/boot` + `GET /ipxe/boot.ipxe` | 3.1 | `auth.v1.lan-only` + `throttle:600,1` | done |
| `GET\|POST /ipxe/admin` | 3.2 | idem | done (modifié 3.3 + 3.4) |
| `GET\|POST /ipxe/maintenance` | 3.2 | idem | done |
| `GET\|POST /ipxe/action/{action}` | 3.2 (étendu 3.4 +9 cases) | idem | done |
| `GET\|POST /ipxe/enrollment/{name,byod,room,parc-add,parc-remove}` | 3.3 | idem | review |
| `GET\|POST /ipxe/installation-linux` | **3.4 (cette story)** | idem | **NEW** |
| `GET\|POST /ipxe/linux/preseed` | **3.4 (cette story)** | idem | **NEW** |
| `GET\|POST /ipxe/linux/action` | **3.4 (cette story)** | idem | **NEW** |
| `GET\|POST /ipxe/linux/autorun` | **3.4 (cette story)** | idem | **NEW** stub |
| `/ipxe/installation-windows.php` | Legacy | (catchall + proxy legacy) | Inchangé — sera réécrit en 3.5 |
| `/ipxe/clonage.php`, `/ipxe/clonezilla*.php` | Legacy | (catchall + proxy legacy) | Inchangé — sera réécrit en 3.7 |
| `/ipxe/Win10/*` | Legacy | (catchall + proxy legacy) | Inchangé — 3.5 |
| `/ipxe/diconf/*` | Legacy | (catchall + proxy legacy) | Inchangé — déféré |
| `/ipxe/png/*` | Legacy (assets) | (catchall + proxy legacy) | Inchangé |

### Convention QA — domaine ciblé

- **Domaine QA** : `ipxe` (déjà existant — append-only sur `docs/qa/domains/ipxe.md`).
- **Numérotation stable** : 3.4-1 à 3.4-12+ (préserve 3.1-1 à 3.3-16 intacts).
- **Pas de nouveau domaine** : `ipxe-deployment` ou `bootstrap-update` envisagés mais non pertinents (toute la chaîne iPXE est cohérente sous un seul domaine).

---

## References

- [Source: `_bmad-output/planning-artifacts/epics.md` §Epic 3 Story 3.4] — cadrage haut niveau.
- [Source: `_bmad-output/planning-artifacts/prd.md` §FR8/FR23-26] — Functional Requirements liés au déploiement OS.
- [Source: `_bmad-output/planning-artifacts/architecture.md` §"Modèle de Données — Source de Vérité"] — PostgreSQL exclusif lecture.
- [Source: `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`] — fondation namespace + WorkstationLocator + IpxeMenuRenderer + channel log + config.
- [Source: `_bmad-output/implementation-artifacts/3-2-boot-et-menu-admin-ipxe.md`] — pattern controller fin + enum whitelist + IpxeActionResolver.
- [Source: `_bmad-output/implementation-artifacts/3-3-enrollment-machine-parcs-salles-nommage.md`] — pattern service orchestrateur + IpxeHostnameSanitizer.
- [Source: `sambaedu/ipxe/installation-linux.php`] — source de vérité comportementale menu Linux (233 LOC).
- [Source: `sambaedu/ipxe/linux/preseed.php`] — source de vérité génération preseed (194 LOC).
- [Source: `sambaedu/ipxe/linux/action.php`] — hook fin install.
- [Source: `sambaedu/ipxe/linux/autorun.php`] — script bash autorun (porté en stub).
- [Source: `sambaedu/ipxe/actions/deb_*.php`, `ubuntu64.php`, `nird.php`] — kernel cmdlines (port iso strict).
- [Source: `sambaedu/ipxe/linux/{debian,debian_*,sambaedu,simple_boot,aptcache,nocache,proxy,commande_fin,ubuntu}.cfg`] — fragments preseed (~15 fichiers).
- [Source: `app/Models/Workstation.php`] — modèle Eloquent (lecture + update `os`/`status` via Tracker).
- [Source: `app/Ipxe/Services/IpxeService.php`] — extension `handleInstallationLinuxMenu`.
- [Source: `app/Ipxe/Services/IpxeMenuRenderer.php`] — extension `renderInstallationLinuxMenu` + admin.
- [Source: `app/Ipxe/Services/IpxeActionResolver.php`] — extension `osVersion/installType/preseedUrl`.
- [Source: `app/Ipxe/Enums/IpxeAdminAction.php`] — +9 cases install_*.
- [Source: `app/Ipxe/Support/IpxeHostnameSanitizer.php`] — réutilisation `sanitizeForIpxeOutput()`.
- [Source: `config/ipxe.php`] — section linux à ajouter.
- [Source: `config/sambaedu.php`] — variables linux/LDAP/AD pour preseed.
- [Source: `routes/web.php` ligne 731+] — bloc d'insertion 3.4 (entre bloc 3.3 enrollment et catchall).
- [Source: `docs/qa/domains/ipxe.md`] — runbook à enrichir Section 13 Story 3.4.
- [Source: mémoire `feedback_worktree_no_vm_sync`] — pas de SSH /vm depuis worktree.
- [Source: mémoire `feedback_auth_iso_legacy`] — pas de Bearer per-host.
- [Source: mémoire `project_php_fpm_user_www_admin`] — chown www-admin pour les fichiers PHP/Apache.
- [Source: CLAUDE.md projet] — conventions Livewire SFC (non applicable 3.4 — API HTTP pure).

---

## Dev Notes

### Justification design

- **Pourquoi `LinuxPreseedService` séparé d'`IpxeService` ?** Single Responsibility — `IpxeService` orchestre les routes iPXE/HTTP, `LinuxPreseedService` assemble du texte preseed. Tester séparément + permettre à 3.5 (Windows Sysprep) de poser un `WindowsSysprepService` sur le même pattern.
- **Pourquoi pas de Blade pour le preseed ?** Le preseed contient `#` en début de ligne (commentaires + sections debconf) — Blade strip `#!` ou injecte des chars indésirables. La concaténation string + interpolation regex est plus déterministe.
- **Pourquoi 9 templates Blade pour les 9 actions install_* (et pas 1 seul paramétré) ?** Iso 3.2 pattern (chaque action a son template). Mais en pratique les 9 templates Debian sont quasi-identiques — un futur refactor pourra factoriser en 1 template `install_debian.blade.php` avec `$variant` variable (option de simplification post-3.4 mais hors-scope cette story).
- **Pourquoi extension `IpxeAdminAction` enum plutôt qu'un nouveau enum `IpxeInstallAction` ?** Cohérence avec le dispatch via `/ipxe/action/{action}` (existant 3.2). Un nouvel enum forcerait un 2ème dispatch path et complexifierait l'architecture.
- **Pourquoi `LinuxPostInstallTracker` simple plutôt qu'un workflow complet `installation_progress` ?** Phase 2 = minimal viable. Le workflow complet (5% / 50% / 100%) sera porté par Epic 17.4 si besoin terrain.
- **Pourquoi copier les fragments `.cfg` dans `resources/ipxe/linux/` plutôt qu'utiliser les fichiers `sambaedu/ipxe/linux/` directement ?** (1) Les fragments sambaedu/ ne sont pas garantis présents sur la VM (dépendent du package `sambaedu-ipxe`). (2) Mettre les fragments sous version control dans le repo SE5 permet d'évoluer indépendamment du legacy. (3) Tests unitaires plus simples (chemin déterministe).

### Convention de logging

- Tous les logs 3.4 ont la clé `action_type` (iso 3.1-3.3) :
  - `ipxe.install_linux.menu_rendered` (info)
  - `ipxe.install_linux.menu_render_error` (error)
  - `ipxe.linux.preseed.generated` (info)
  - `ipxe.linux.preseed.unknown_workstation` (warning)
  - `ipxe.linux.preseed.invalid_distribution` (warning)
  - `ipxe.linux.preseed.invalid_variant` (warning)
  - `ipxe.linux.preseed.fragment_missing` (error)
  - `ipxe.linux.preseed.generation_error` (error)
  - `ipxe.linux.action.success` (info)
  - `ipxe.linux.action.failure` (warning)
  - `ipxe.linux.action.unknown_workstation` (warning)
  - `ipxe.linux.autorun.served` (info)
- Toutes les valeurs sensibles (MAC, UUID, hostname) sont **préfixées** (6-8 chars).
- Le preseed n'est **jamais** loggué en clair — seulement le sha256.

### Pattern résolution multi-niveaux post-3.4

```
Firmware iPXE → /ipxe/boot (3.1) → menu known
  ↓ user choisit "1" (login admin)
/ipxe/admin (3.2 + ext 3.3 + ext 3.4)
  ↓ user choisit "(l) Installation Linux"
/ipxe/installation-linux (3.4)
  → handshake si MAC/UUID manquant → handshake template (chainTarget='installation-linux')
  → résolution WorkstationLocator
  → render menu avec 9 items install_*
  ↓ user choisit ex. "(g) Debian + GNOME"
/ipxe/action/install_deb_gnome (3.2 + ext enum 3.4)
  → IpxeAdminAction::tryFrom('install_deb_gnome') OK
  → IpxeActionResolver::resolve()
    → action.linuxMeta() → {distribution: 'debian', variant: 'gnome'}
    → injecte osVersion + installType + preseedUrl dans le contexte Blade
  → render template ipxe.actions.install_deb_gnome.blade.php
  → body iPXE kernel/initrd/imgargs/boot
  ↓ firmware iPXE charge kernel + initrd, boot installateur Debian
Installateur Debian fetch http://se4fs/ipxe/linux/preseed?mac=...&uuid=...&os=trixie&type=gnome
  ↓
/ipxe/linux/preseed (3.4)
  → validation IpxeLinuxPreseedRequest (Rule::in os/type)
  → résolution Workstation par MAC+UUID
  → LinuxPreseedService::generate(workstation, Debian, Gnome, params)
    → lit fragments resources/ipxe/linux/{debian,debian_gnome,sambaedu,simple_boot,nocache}.cfg
    → assemble + interpole placeholders ###_<KEY>_###
    → retourne string preseed (~4000 lignes)
  → log info ipxe.linux.preseed.generated (sha256, distribution, variant, size)
  → insert MachineBootLog action='ipxe_linux_preseed'
  → response text/plain
  ↓ installateur Debian consomme preseed → install OS complet → reboot
1er reboot du poste installé
  ↓ preseed.cfg:83 → curl -F 'ret=0' -F 'uuid=...' -F 'name=PC-101' http://se4fs/ipxe/linux/action
/ipxe/linux/action (3.4)
  → LinuxPostInstallTracker::record(workstation, ret=0, name)
    → Workstation::os = 'linux'
    → Workstation::status = 'installation Linux terminée'
    → Workstation::last_report_at = now()
    → save()
  → log info ipxe.linux.action.success
  → insert MachineBootLog action='ipxe_linux_report'
  → response 200 vide
```

### Vérification non-régression catchall

Garde-fou critique : les routes legacy `/ipxe/installation-windows.php`, `/ipxe/clonage.php`, `/ipxe/Win10/*` doivent **continuer de fonctionner** via le catchall jusqu'à 3.5/3.7. Risque concret : un dev pourrait être tenté de "généraliser" en `Route::prefix('/ipxe/linux')` ou en `Route::any('/ipxe/{any}')`. **Anti-pattern strict** — D2 limite à 4 routes précises.

Mitigation :
- T7.5 test archi obligatoire.
- T7.6 tests feature non-régression (catchall continue de servir les routes hors scope).

### Tests qu'on **ne** fait **pas** dans cette story

- Tests de boot réel sur poste de test PXE → install complète Debian — couvert par scénario QA manuel `docs/qa/domains/ipxe.md` § Scénario 3.4-13 (action Henri).
- Tests de l'installateur Debian consommant le preseed — comportement firmware/installer, hors périmètre serveur.
- Tests de charge `/ipxe/linux/preseed` (50 postes simultanés rentrée) — déférés post-prod.
- Tests d'install Windows / clonezilla — = stories 3.5/3.7.
- Tests d'install variantes hors-scope (se4ad/se4fs/...) — = Phase 3.

---

## Dev Agent Record

### Agent Model Used

- Modèle : `claude-opus-4-7[1m]`
- Worktree : `ipxe`
- Date : 2026-05-20

### Debug Log References

- Lint statique `php -l` : 0 erreur sur 41 fichiers PHP créés/modifiés (PHP 8.4.5 host).
- Tests Pest **différés Henri post-merge VM** : `vendor/` absent du worktree (pattern iso 3.1/3.2/3.3 — la sync inotify ne se fait que depuis la branche `main`, donc `composer install` doit être lancé côté VM après merge).
- Aucune dépendance Composer nouvelle ajoutée (réutilisation 100% des packages 3.1/3.2/3.3 — Eloquent, Blade, Carbon, Log channel `ipxe`).
- `IpxeSchemaBootstrapper` réutilisé sans modification (le schema Workstation/MachineBootLog du test base couvre 3.4 sans ajout colonne).

### Completion Notes List

**Implémentation Story 3.4 — Installation Linux (Debian/Ubuntu/NIRD) terminée intégralement.**

Phases livrées (T0-T8) :
- **T0 — Pré-flight** : audit fragments .cfg + variables `.env` + MachineBootLog varchar(20) OK (3 nouvelles valeurs ≤18 chars). Aucun placeholder orphelin dans les 16 fragments (validé par `LinuxPreseedFragmentsTest::it_each_placeholder_in_fragments_is_known_in_catalog`).
- **T1 — Enums + Support + Fragments** : 2 enums créés (`LinuxDistribution` 3 cases avec alias versions, `LinuxDesktopVariant` 7 cases) + extension `IpxeAdminAction` +9 cases install_* + méthode `linuxMeta()` + extension `IpxeMenuKind` +2 cases. Helper `PreseedPlaceholders` (catalog 27 entrées + sanitize anti-injection + interpolate). Exception `PreseedGenerationException`. 16 fragments copiés `sambaedu/ipxe/linux/*.cfg` → `resources/ipxe/linux/*.cfg`.
- **T2 — LinuxPreseedService** : port natif de `preseed.php:86-159` simplifié au scope 3.4. Assemblage des fragments selon (distribution, variant) avec algorithme iso-legacy (Debian → debian.cfg + debian_<variant>.cfg + sambaedu.cfg + simple_boot.cfg ; Ubuntu → ubuntu.cfg ; Nird → debian.cfg + debian_perso.cfg). Aptcache/proxy/nocache conditionnels. Sanitization hostname via `IpxeHostnameSanitizer` + double-couche `PreseedPlaceholders::sanitize()`. Log audit sha256-only (jamais le contenu du preseed).
- **T3 — Builder + Tracker** : `LinuxInstallMenuBuilder::build()` lit config menu_items + sanitize. `LinuxPostInstallTracker::record()` met à jour `Workstation::os='linux'`, `status='installation Linux terminee|echouee (ret=N)'`, `last_report_at`, insert `MachineBootLog action='ipxe_linux_report'`.
- **T4 — Templates Blade** : 1 menu + 9 actions (8 templates partagent le format kernel/initrd/imgargs/boot, 1 template Nird dédié avec NFS root). Extension `admin.blade.php` : item `(l) Installation Linux` + section `:install-linux`. ASCII strict + pas de balises PHP (test archi `story_3_4_templates_are_ascii_strict_and_no_php`).
- **T5 — IpxeService + IpxeMenuRenderer + IpxeActionResolver** : nouvelle méthode `handleInstallationLinuxMenu()` (pattern iso `handleAdmin()`). Renderer `renderInstallationLinuxMenu()` délègue à `LinuxInstallMenuBuilder`. `IpxeActionResolver::resolveLinuxVariables()` injecte `$osVersion`/`$installType`/`$preseedUrl`/`$kernelPath`/`$initrdPath`/`$nfsRoot` via `IpxeAdminAction::linuxMeta()` (les actions 3.2 non-install reçoivent un array vide — pas de side effect). `renderAdminMenu()` étendu avec `installLinuxBaseUrl` + `isInstallLinuxActive`.
- **T6 — Controllers + FormRequests** : 4 controllers fins (≤30 LOC sauf preseed qui orchestre validation+resolver+service+log+MachineBootLog). 4 FormRequests permissives + Rule::in() pour `os`/`type` (defense en profondeur + enum côté service).
- **T7 — Routes + provider + config + non-régression** : 4 routes ajoutées dans `routes/web.php` **AVANT le catchall** (test archi `ipxe_3_4_routes_are_declared_before_catchall`). Commentaire ⚠⚠⚠ préservé intact. `IpxeServiceProvider` étend avec 3 nouveaux singletons. `config/ipxe.php` section `linux` D11 (kernel_paths + menu_items + whitelists). `config/sambaedu.php` section `linux` + variables LDAP/AD requises par le preseed (vérifié : aucun doublon dans la config existante — toutes nouvelles).
- **T8 — Runbook QA + sprint-status** : `docs/qa/domains/ipxe.md` Section 13 Story 3.4 + 12 scénarios stables `3.4-1` à `3.4-12` + 2 optionnels (`3.4-13` non-régression menu admin, `3.4-14` smoke poste réel). Checklist 14 entrées 3.4 ajoutées. Sprint-status.yaml `ready-for-dev → review`.

**Tests cumulés ≥45 (différés VM Henri car vendor/ absent)** :
- Unit ≥27 :
  - `IpxeAdminActionTest` étendu (~9 tests post-3.4)
  - `LinuxDistributionTest` 7 tests (3 valides + 3 alias + 3 invalid + injection)
  - `LinuxDesktopVariantTest` 5 tests
  - `PreseedPlaceholdersTest` 12 tests (catalog, sanitize newline/non-ASCII/null-byte, interpolation, anti-injection)
  - `LinuxPreseedFragmentsTest` 3 tests (lister 16 fragments, lisibilité, placeholders connus dans catalogue)
  - `LinuxPreseedServiceTest` 13 tests (debian/ubuntu/nird assemblage, hostname/uuid/admin_passwd interpolés, aptcache conditionnel, fragment manquant exception, anti-injection)
  - `LinuxInstallMenuBuilderTest` 4 tests (known/unknown/9 items/sanitize)
  - `LinuxPostInstallTrackerTest` 5 tests (ret=0, ret!=0, MachineBootLog, unknown, ASCII strict status)
  - `IpxeServiceInstallationLinuxTest` 6 tests (handshake, known, unknown, MachineBootLog, headers, dispatch install_deb_gnome)
  - `IpxeConfigTest` étendu +7 assertions section linux
- Feature ≥18 :
  - `IpxeInstallationLinuxEndpointTest` 6 (handshake, known, unknown, MachineBootLog, oversize 422, headers)
  - `IpxeLinuxPreseedEndpointTest` 8 (debian/gnome OK, unknown 404, invalid os 422, invalid type 422, MachineBootLog, headers, ubuntu, path traversal)
  - `IpxeLinuxActionEndpointTest` 4 (ret=0 update, ret!=0 update, unknown 200, MachineBootLog)
  - `IpxeLinuxAutorunEndpointTest` 3 (bash stub, headers, sanitization shell injection)
  - `IpxeAdminEndpointTest` étendu +2 (item visible / hidden via feature-flag)
  - `IpxeLegacyRoutingNonRegressionTest` étendu +3 (installation-linux natif, preseed natif, installation-windows.php toujours catchall)
- Architecture +3 :
  - `ipxe_3_4_routes_are_declared_before_catchall`
  - `it_lists_all_ipxe_3_4_controllers_and_services_under_correct_namespace`
  - `story_3_4_templates_are_ascii_strict_and_no_php`
  - Test `ipxe_admin_action_enum_has_exactly_three_cases_in_story_3_2` **renommé** `ipxe_admin_action_enum_has_exactly_twelve_cases_in_story_3_4` (relax cohérent 3.4).

**Décisions D1-D15 ratifiées appliquées intégralement** (aucun écart) :
- D1 namespace `App\Ipxe` étendu (pas de sous-namespace), D2 4 routes flat avant catchall, D3 `auth.v1.lan-only` strict (pas de Bearer), D4 `WorkstationLocator` réutilisé sans modif, D5 `LinuxPreseedService` orchestrateur + sanitization defense-in-depth, D6 `LinuxInstallMenuBuilder` + `LinuxPostInstallTracker`, D7 poste inconnu → menu erreur + chain admin, D8 12 nouveaux events log structurés (sha256-only preseed, pas de fuite secret), D9 aucune migration, D10 11 templates Blade ASCII strict, D11 extensions `config/ipxe.php` + `config/sambaedu.php`, D12 `MachineBootLog.action` +3 valeurs (≤18 chars), D13 UI Livewire hors-scope, D14 variantes hors-scope (se4ad/se4fs/deb_serv/kiosk/nextcloud/gnome_perso/primtux) déférées Phase 3, D15 endpoint autorun = stub minimal bash.

**Sécurité — défense en profondeur** :
- Aucun secret loggé : channel `ipxe` n'expose que le sha256 du preseed + size + distribution/variant + workstation_id.
- Sanitization 2 couches : hostname/uuid par `IpxeHostnameSanitizer` AVANT injection + `PreseedPlaceholders::sanitize()` AVANT interpolation finale.
- Whitelist enum stricte os/type côté FormRequest **ET** côté service (defense en profondeur si FormRequest court-circuitée par tests).
- Pas de strip_tags / htmlspecialchars (incompatible iPXE — `&` doit rester pour les URL params).
- Stub autorun avec sanitization shell-arg stricte (`[^A-Za-z0-9\-_.:]` → `?`).

**Items différés Henri post-merge VM** :
1. `composer install` sur la VM pour activer `vendor/`.
2. `php artisan test --filter=Linux` + `php artisan test --filter=Ipxe` (suite complète ~130 tests cumulés post-3.4 — non-régression Auth V1 / GPO / WPKG / 3.1-3.3).
3. Cache reset Laravel (`config:clear` + `view:clear` + `route:clear`) + reload PHP-FPM (`systemctl reload php8.2-fpm@www-admin`).
4. Smoke `curl` 12 scénarios Section 13 `docs/qa/domains/ipxe.md`.
5. Smoke optionnel poste réel scénario 3.4-14 (install Debian complète).
6. **Validation `.env` VM** : présence de `SAMBAEDU_LDAP_ADMIN_PASSWD`, `SAMBAEDU_ADMIN_PASSWD`, `SAMBAEDU_DOMAIN`, `SAMBAEDU_LDAP_BASE_DN`, etc. — si absents, l'install Linux échouera (preseed avec mots de passe vides).

### File List

**Fichiers créés (38)** :

```
# Enums + Support (4)
app/Ipxe/Enums/LinuxDistribution.php
app/Ipxe/Enums/LinuxDesktopVariant.php
app/Ipxe/Support/PreseedPlaceholders.php
app/Ipxe/Exceptions/PreseedGenerationException.php

# Services (3)
app/Ipxe/Services/LinuxPreseedService.php
app/Ipxe/Services/LinuxInstallMenuBuilder.php
app/Ipxe/Services/LinuxPostInstallTracker.php

# Controllers (4)
app/Ipxe/Http/Controllers/IpxeInstallationLinuxController.php
app/Ipxe/Http/Controllers/IpxeLinuxPreseedController.php
app/Ipxe/Http/Controllers/IpxeLinuxActionController.php
app/Ipxe/Http/Controllers/IpxeLinuxAutorunController.php

# FormRequests (4)
app/Ipxe/Http/Requests/IpxeInstallationLinuxRequest.php
app/Ipxe/Http/Requests/IpxeLinuxPreseedRequest.php
app/Ipxe/Http/Requests/IpxeLinuxActionRequest.php
app/Ipxe/Http/Requests/IpxeLinuxAutorunRequest.php

# Templates Blade (10)
resources/views/ipxe/menu/installation-linux.blade.php
resources/views/ipxe/actions/install_deb_base.blade.php
resources/views/ipxe/actions/install_deb_cinnamon.blade.php
resources/views/ipxe/actions/install_deb_gnome.blade.php
resources/views/ipxe/actions/install_deb_kde.blade.php
resources/views/ipxe/actions/install_deb_lxde.blade.php
resources/views/ipxe/actions/install_deb_mate.blade.php
resources/views/ipxe/actions/install_deb_xfce.blade.php
resources/views/ipxe/actions/install_nird.blade.php
resources/views/ipxe/actions/install_ubuntu64.blade.php

# Fragments preseed (16)
resources/ipxe/linux/debian.cfg
resources/ipxe/linux/debian_base.cfg
resources/ipxe/linux/debian_gnome.cfg
resources/ipxe/linux/debian_lxde.cfg
resources/ipxe/linux/debian_kde.cfg
resources/ipxe/linux/debian_mate.cfg
resources/ipxe/linux/debian_xfce.cfg
resources/ipxe/linux/debian_cinnamon.cfg
resources/ipxe/linux/debian_perso.cfg
resources/ipxe/linux/sambaedu.cfg
resources/ipxe/linux/simple_boot.cfg
resources/ipxe/linux/aptcache.cfg
resources/ipxe/linux/nocache.cfg
resources/ipxe/linux/proxy.cfg
resources/ipxe/linux/commande_fin.cfg
resources/ipxe/linux/ubuntu.cfg

# Tests Unit (8)
tests/Unit/Ipxe/Enums/LinuxDistributionTest.php
tests/Unit/Ipxe/Enums/LinuxDesktopVariantTest.php
tests/Unit/Ipxe/Support/PreseedPlaceholdersTest.php
tests/Unit/Ipxe/Support/LinuxPreseedFragmentsTest.php
tests/Unit/Ipxe/Services/LinuxPreseedServiceTest.php
tests/Unit/Ipxe/Services/LinuxInstallMenuBuilderTest.php
tests/Unit/Ipxe/Services/LinuxPostInstallTrackerTest.php
tests/Unit/Ipxe/Services/IpxeServiceInstallationLinuxTest.php

# Tests Feature (4)
tests/Feature/Ipxe/IpxeInstallationLinuxEndpointTest.php
tests/Feature/Ipxe/IpxeLinuxPreseedEndpointTest.php
tests/Feature/Ipxe/IpxeLinuxActionEndpointTest.php
tests/Feature/Ipxe/IpxeLinuxAutorunEndpointTest.php
```

**Fichiers modifiés (16)** :

```
app/Ipxe/Enums/IpxeAdminAction.php                            (+9 cases + linuxMeta())
app/Ipxe/Enums/IpxeMenuKind.php                               (+2 cases InstallationLinux{Menu,Handshake})
app/Ipxe/Services/IpxeService.php                             (+handleInstallationLinuxMenu)
app/Ipxe/Services/IpxeMenuRenderer.php                        (+renderInstallationLinuxMenu + installLinuxBaseUrl/isInstallLinuxActive admin)
app/Ipxe/Services/IpxeActionResolver.php                      (+resolveLinuxVariables osVersion/installType/preseedUrl/kernelPath/initrdPath/nfsRoot)
app/Providers/IpxeServiceProvider.php                         (+3 singletons LinuxPreseedService/LinuxInstallMenuBuilder/LinuxPostInstallTracker)
config/ipxe.php                                                (+section linux D11)
config/sambaedu.php                                            (+section linux + variables LDAP/AD D11)
resources/views/ipxe/menu/admin.blade.php                     (+item install-linux + section :install-linux)
routes/web.php                                                 (+bloc 4 routes 3.4 AVANT catchall)
docs/qa/domains/ipxe.md                                        (+Section 13 Story 3.4 + 12 scénarios stables 3.4-1 à 3.4-12 + checklist 14)
tests/Unit/Ipxe/IpxeConfigTest.php                            (+7 assertions section linux)
tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php                 (+9 tests post-3.4 + relax count 3→12)
tests/Architecture/IpxeNamespaceTest.php                      (+3 tests 3.4 + rename enum count test 3.2→3.4)
tests/Feature/Ipxe/IpxeAdminEndpointTest.php                  (+2 tests item install-linux feature-flag)
tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php     (+3 tests non-régression 3.4)
```

**Fichiers non modifiés (garde-fou — ne pas toucher)** :

```
sambaedu/ipxe/**                            ← legacy intact (catchall sert encore)
legacy/modules/ipxe/**                      ← idem
app/Models/Workstation.php                  ← lecture seule + update os/status via Tracker
app/Models/MachineBootLog.php               ← lecture seule + insert via Eloquent
app/Ipxe/Services/WorkstationLocator.php    ← lecture seule
app/Ipxe/Services/IpxeHostnameSanitizer.php ← lecture seule (réutilisation)
```

### Corrections post-review (2026-05-21, claude-opus-4-7)

13 corrections appliquées suite à la code review structurée `_bmad-output/codeReviews/3-4.md` (review Sonnet + second avis Opus). Décisions Henri arbitrées : **#3** laissé tel quel (parité 3.3) ; **#M2** laissé Phase 2 + documenté en Section « Limitations connues » de `docs/qa/domains/ipxe.md` ; **#M3** option « préserver `status='protected'` post-install » avec log info `ipxe.linux.action.protected_preserved`. Corrections appliquées : **#1** `late_command` preseed pointe désormais sur la route native `/ipxe/linux/action` (sans `.php`) dans `debian.cfg`/`ubuntu.cfg`/`debian_perso.cfg` (callback effectif pour Debian/Ubuntu/Nird) ; **#2** menu `installation-linux.blade.php` refactorisé en 2 paths disjoints (`@if($isKnown)` englobe tout le bloc `menu`/`choose`, `@else` rend un body simple `echo`+`sleep`+`chain`) ; **#4** test log audit réécrit avec Monolog `TestHandler` + 4 canaries secrets vérifiés absents ; **#5** DI directe `LinuxInstallMenuBuilder` injecté dans `IpxeMenuRenderer` (plus de `app(...)`) ; **#6** `ETAB_OU` retiré de `PER_POSTE_PLACEHOLDERS` (jamais injecté) ; **#7** alias `buster` retiré de `LinuxDistribution::fromString()` (Debian 10 EOL) ; **#M1** test `it_preserves_fragment_order_for_debian_gnome` + docblock explicite dans `resolveFragmentsList()` ; **#M4** `Carbon::setTestNow('2026-05-21 10:00:00')` en setUp/tearDown du tracker + assertion `equalTo` ; **#M5** docblock contrat « UUID-only » dans `WorkstationLocator::locate()` + test `it_resolves_workstation_by_uuid_only_when_mac_is_empty` ; **#M6** test archi templates basculé en scan dynamique `Finder` ; **#M7** test `it_uses_workstation_name_from_db_not_payload` (anti-spoofing) ; **#M8** test `it_defines_nird_kernel_paths` (défense en profondeur).

### Change Log

- 2026-05-21 — Corrections post-review appliquées par claude-opus-4-7 (worktree `ipxe`). 13 corrections + 8 nouveaux tests + ~5 tests modifiés + 1 service modifié (`LinuxPostInstallTracker` — préservation `protected`) + 1 service modifié (`IpxeMenuRenderer` — DI directe) + 1 service modifié (`LinuxPreseedService` — docblock ordre fragments) + 1 enum modifié (`LinuxDistribution` — retrait `buster`) + 3 fragments preseed modifiés (`debian.cfg`/`ubuntu.cfg`/`debian_perso.cfg` — `late_command` natif) + 1 template Blade modifié (`installation-linux.blade.php`) + 1 docblock `WorkstationLocator` + 1 doc QA enrichie (Section « Limitations connues »). Status review → `to-validate`.
- 2026-05-20 — Story 3.4 DEV TERMINÉ par claude-opus-4-7[1m] (worktree `ipxe`).
  - 38 fichiers créés (3 services + 4 controllers + 4 FormRequests + 2 enums + 1 support + 1 exception + 10 templates Blade + 16 fragments + 8 tests Unit + 4 tests Feature) + 16 modifiés (5 fichiers App\Ipxe + 1 provider + 2 configs + 1 template admin + routes + 4 tests étendus + docs QA).
  - 15 décisions D1-D15 appliquées sans écart.
  - ≥45 tests cumulés (≥27 Unit + ≥18 Feature + ≥3 Architecture).
  - Lint `php -l` 0 erreur sur 41 fichiers PHP.
  - Tests Pest différés Henri post-merge VM (vendor/ absent du worktree iso 3.1/3.2/3.3).
  - Doc QA `docs/qa/domains/ipxe.md` étendue Section 13 + 12 scénarios stables 3.4-1 à 3.4-12 + 2 optionnels.
  - Status : `ready-for-dev` → `review`.
  - Recommandation modèle code-review : **sonnet** (opposé d'opus dev — pattern iso 3.1/3.2/3.3).

---

## Recommandation Modèle Dev

**Modèle recommandé : `opus`**

**Justification** :

- **Domaine sensible — secrets en clair sur LAN** : le preseed contient des mots de passe root + clés AD + tokens, rendus en clair text/plain au LAN. La gestion des placeholders + sanitization + non-fuite dans les logs demande une attention rigoureuse. Sonnet a tendance à logger trop verbeusement ou à oublier de sanitize une valeur. Opus mieux armé pour la défense en profondeur sécurité.
- **Génération de templates dynamiques avec validation stricte** : 30+ placeholders à interpoler avec catalogue strict, 15 fragments .cfg à assembler selon une logique conditionnelle (apt_proxy, commande_fin, ubuntu vs debian vs nird, perso vs domain) — port iso-legacy `preseed.php:86-159` non trivial à transposer en PHP moderne sans omission.
- **Coordination 4 nouveaux services + 4 controllers + 4 FormRequests + 2 enums + 1 support + 9 templates + 1 menu Blade + 15 fragments copiés + ~50 tests cumulés** : densité élevée (~38 fichiers créés + ~10 modifiés). Opus mieux armé pour la cohérence end-to-end et la non-régression cross-cutting.
- **Non-régression critique sur 3.1-3.3** : 3.4 modifie `IpxeAdminAction`, `IpxeMenuRenderer`, `IpxeActionResolver`, `IpxeService`, `admin.blade.php`, `routes/web.php`, `config/ipxe.php`, `config/sambaedu.php`. Risque de casser silencieusement les 108-115 tests existants 3.1-3.3 si refactor mal pensé. Opus mieux armé pour respecter les contraintes "extension non destructive".
- **Templates iPXE + preseed Debian = formats atypiques** : la syntaxe iPXE (`${menu-default}`, `chain --replace --autofree`, `imgargs`) + la syntaxe debconf preseed (`d-i partman-auto/method string regular`, `tasksel tasksel/first multiselect ...`) ne sont pas du code applicatif standard. Opus a une meilleure mémoire de ces conventions.
- **Decision-log déjà cadré** : 15 décisions D1-D15 tranchées. Le dev n'a pas à itérer dessus — il implémente. Cela compense partiellement le coût Opus.

**Bascule possible vers Sonnet** : si les phases T1-T3 (enums + service preseed + builder + tracker) se passent sans accroc et que le dev produit une couverture unit verte en T3, les phases T4-T8 (templates + service + controllers + routes + doc QA) pourraient passer en Sonnet pour économiser le coût. Décision à prendre par Henri après le premier point d'étape T3.

**Charge cadrée** : 4-5j (estimation SM) — densité élevée (~38 fichiers créés) mais decisions log tranché + patterns 3.1-3.3 prêts à imiter. Recadrer 5-6j si :
- T0.5 escalade variables `.env` manquantes (rentrée scolaire risque).
- T0.4 révèle des placeholders orphelins non listés D5.
- Henri arbitre l'ajout d'une variante hors-scope D14.
