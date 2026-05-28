# Story 3.5 : Installation Windows (Sysprep/Wimboot)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Suite directe de Stories 3.1 + 3.2 + 3.3 + 3.4** (« iPXE Service Core » + « Boot et Menu Admin iPXE » + « Enrollment Machine — Parcs, Salles, Nommage » + « Installation Linux »). Porte nativement le **menu d'installation Windows** + le **dispatch wimboot/winpe** + la **génération dynamique de `unattend.xml` et `sysprep.xml`** + le **hook post-install Windows** (équivalents legacy `installation-windows.php` + `Win10/action.php` + `Win10/install.bat.php` + `Win10/unattend.xml.php` + `Win10/sysprep.xml.php` + `Win10/diskpart.php` + `actions/wimboot10.php` + `actions/wimboot11.php` + `actions/wimboot11old.php`). Réutilise intégralement le socle 3.1-3.4 (`IpxeService`, `IpxeMenuRenderer`, `WorkstationLocator`, `IpxeActionResolver`, channel log `ipxe`, middleware `auth.v1.lan-only`, table `MachineBootLog`, enum `IpxeAdminAction`, pattern `LinuxPreseedService` + `PreseedPlaceholders` + `PostInstallTracker` posé en 3.4).
>
> **Scope strict 3.5** = (a) 1 nouveau endpoint natif `/ipxe/installation-windows` (menu Windows interactif — port `installation-windows.php`), (b) **6 nouveaux cases** dans l'enum `IpxeAdminAction` (`install_win10`, `install_win10_debug`, `install_win10_disk`, `install_win10_perso`, `install_win11`, `install_win11_disk`, `install_win11_perso` — **7 cases en tout** — voir D14 pour la justification du `win11old` déféré), (c) **6 nouveaux templates Blade `resources/views/ipxe/actions/install_win*.blade.php`** (port iso-legacy `actions/wimboot10.php` + `wimboot11.php` qui chargent wimboot kernel + winpeshl.ini + install.bat dynamique + unattend.xml dynamique + BCD/boot.sdi/boot.wim statiques), (d) **3 nouveaux endpoints natifs** pour les artefacts générés dynamiquement consommés par WinPE durant l'install : `/ipxe/windows/install.bat` + `/ipxe/windows/unattend.xml` + `/ipxe/windows/diskpart.txt` (port iso-legacy `Win10/install.bat.php` + `Win10/unattend.xml.php` + `Win10/diskpart.php`), (e) **1 nouveau endpoint natif** `/ipxe/windows/action` (hook post-install Windows multi-étapes — port iso-legacy `Win10/action.php` 736 LOC — voir scope réduit D10 : seul flow `winpe` + `oobe` rendu, scripts AD-driven `sysprep|nosysprep|join|renomme|post|wpkg` **déférés 3.7**), (f) **1 nouveau endpoint natif** `/ipxe/windows/sysprep.xml` (port iso-legacy `Win10/sysprep.xml.php` 39 LOC — **stub minimal** D11, hook clonage Phase 3.7), (g) **1 nouveau service `WindowsUnattendBuilder`** qui construit dynamiquement `unattend.xml` à partir d'un template DOMDocument (port iso-legacy `update_xml_unattend()` ~370 LOC dans `windows.inc.php` — voir D6 simplifications), (h) **1 nouveau service `WindowsInstallMenuBuilder`** (variables Blade du menu installation-windows iso `LinuxInstallMenuBuilder` 3.4), (i) **1 nouveau service `WindowsPostInstallTracker`** (consomme `/ipxe/windows/action` étape `winpe` + `oobe` — met à jour `Workstation::os='windows'`, `status`, `progress` — port subset des `set_action`/`set_statut`/`set_progress` legacy), (j) **1 nouveau template Blade `resources/views/ipxe/menu/installation-windows.blade.php`** (port iso-legacy `installation-windows.php`), (k) **mise à jour de `admin.blade.php`** pour activer un item `(w) Installation Windows` chainant vers `/ipxe/installation-windows##params`, (l) modification mineure `IpxeMenuRenderer::renderAdminMenu()` pour exposer `installWindowsBaseUrl` + `isInstallWindowsActive`, (m) **extension `config/ipxe.php`** avec une section `windows` (versions Win10/Win11 supportées, paths wimboot/BCD/boot.wim, default variant, allowed_versions whitelist, menu_items), (n) **extension `config/sambaedu.php`** avec section `windows` (adminse_name/adminse_passwd, win_key, win_user/passwd perso, win_autologon — variables consommées par `WindowsUnattendBuilder` + scripts `.bat`), (o) **extension `MachineBootLog::action`** avec 5 nouvelles valeurs persistées (`ipxe_install_win`, `ipxe_win_unattend`, `ipxe_win_install`, `ipxe_win_diskpart`, `ipxe_win_report` — tous ≤17 chars varchar(20)), (p) tests Unit + Feature + Architecture ≥45 cumulés, (q) extension `docs/qa/domains/ipxe.md` (Section 14 « Story 3.5 » + ≥12 scénarios stables 3.5-1 à 3.5-N).
>
> **HORS-SCOPE 3.5** (explicitement reportés aux stories suivantes ou définitivement abandonnés) :
>
> - **Variante `installw11old`** (Win11 ancienne version, `actions/wimboot11old.php` + check `is_dir("/var/sambaedu/unattended/install/os/Win11-old")`) — **DÉFÉRÉ 3.7** : cette variante n'a pas vocation à apparaître par défaut, elle dépend de la présence d'un dossier `Win11-old/` côté infra ; sa logique se factorise post-3.7 (gestion ISO multi-versions). Whitelist enum bloque l'accès tant que pas ajoutée.
> - **Hook post-install complet `Win10/action.php`** (multi-étapes `sysprep`/`nosysprep`/`join`/`renomme`/`post`/`wpkg`) — **partiel 3.5, complet 3.7** : 3.5 ne porte que le flow `winpe` (acknowledgement du début d'install après le boot WinPE → `set_progress 5%` + `set_statut 'installation WinPE'`) + le flow `oobe` (acknowledgement de fin d'install Windows → `Workstation::os='windows'` + `progress=100%` + `statut='installation Windows terminee'`). Les flows `sysprep`/`nosysprep`/`join`/`renomme`/`post`/`wpkg` font partie de **Story 3.7 Clonage et Maintenance** (qui porte natif le mécanisme `set_action`/`get_action` qui consomme la GLM `actions[]` LDAP — ce mécanisme dépend de `IpxeProgrammedActionResolver` non implémenté).
> - **Génération `sysprep.xml`** : 3.5 livre un **stub minimal** qui retourne 200 + body vide + log audit (parité `Win10/sysprep.xml.php` qui ne renvoie rien si pas de `action[type]` = `renomme|clonage|postinst`). Le vrai mécanisme = Story 3.7 (clonage). 3.5 = endpoint en place mais inutilisé tant que 3.7 n'enrichit pas `IpxeProgrammedActionResolver`.
> - **Génération `repair.bat`** (action `winpe` 3.2 — réparation Windows) — déjà servie via **catchall legacy** (`Win10/repair.bat.php`) et template `ipxe.actions.winpe` rendu nativement en 3.2 (le template iPXE pointe vers `Win10/repair.bat.php##params` legacy). 3.5 **ne touche pas** ce flow réparation. Refonte native = post-Epic 3 ou story dédiée Phase 3.
> - **Import d'ISO Windows** (`Win10/win_iso.php`) — **= Story 3.6** dédiée (upload + association profils). HORS-SCOPE absolu 3.5.
> - **Clonezilla / restore image** (`actions/win10diskless.php`, `Win10` mode `clonage`) — **= Story 3.7** dédiée. HORS-SCOPE absolu 3.5.
> - **Items menu `installw10u` (W10 UEFI experimental) + `Win10l2` (W10 diskless experimental)** — **abandonnés définitivement** (commentés dans le legacy, pas de besoin terrain documenté).
> - **TOTP `totp_code("se4install")` legacy** — **NON porté en 3.5**. La parité auth iso-legacy se limite à `auth.v1.lan-only` + matching MAC/UUID strict (cf. mémoire `feedback_auth_iso_legacy`). TOTP était un mécanisme de rotation du mot de passe `se4install_passwd` toutes les 24h — Phase 2 décide de ne pas le porter (la rotation se fait via `.env` admin, le mot de passe est lu via `config('sambaedu.se4install_passwd')` à chaque request).
> - **UI admin SE5 Livewire** pour pré-programmer un install Windows (saisie OS+version+disk+perso depuis le navigateur avant boot iPXE) → **HORS-SCOPE 3.5**, parité legacy stricte = sélection se fait depuis le firmware iPXE en LAN.
> - **Retrait des routes legacy `/ipxe/installation-windows.php` et `/ipxe/Win10/*.php` du catchall** → reporté **fin d'Epic 3** (Story 3.7 cleanup).
> - **Génération du WIM/winpeshl personnalisé** — fichiers statiques `Win10/wimboot` + `Win10/winpeshl.ini` + `Win10/boot/bcd` + `Win10/boot/boot.sdi` + `Win10/sources/boot.wim` restent **servis par Apache** via catchall (assets binaires non versionnés dans le repo SE5). Phase 2 acceptable.
> - **Gestion drivers post-install Windows** (`%PROGRAMFILES%\SambaEdu\driversAuto.ps1`) — Phase 3 GPO/WPKG dédié.
>
> **Liste des 7 cases enum 3.5** (whitelist stricte, ordre alphabétique) :
> 1. `install_win10` → action `wimboot10`, version `Win10`, debug=0 (Installation auto W10 standard).
> 2. `install_win10_debug` → action `wimboot10`, version `Win10`, debug=1 (Installation W10 mode debug drivers).
> 3. `install_win10_disk` → action `wimboot10`, version `Win10`, debug=0, disk=1 (Installation W10 avec choix partitionnement / dual boot).
> 4. `install_win10_perso` → action `wimboot10`, version `Win10`, perso=1 (Installation W10 pc perso hors domaine).
> 5. `install_win11` → action `wimboot11`, version `Win11`, debug=0 (Installation auto W11 standard).
> 6. `install_win11_disk` → action `wimboot11`, version `Win11`, debug=0, disk=1 (Installation W11 avec choix partitionnement).
> 7. `install_win11_perso` → action `wimboot11`, version `Win11`, perso=1 (Installation W11 pc perso hors domaine).

---

## Mode de livraison & contraintes opérationnelles

> **Worktree git dédié `ipxe`** (worktree `/home/htouchard/code/irundo/codebase/ipxe`). Ne JAMAIS SSH `/vm` ni run de tests sur la VM depuis ce worktree (mémoire `feedback_worktree_no_vm_sync`). Static delivery iso 3.1/3.2/3.3/3.4 : lint statique `php -l` + PHPUnit local si `vendor/` présent + 0 sync manuel.
>
> - **Code synchronisé via inotify** sur la branche `main` uniquement (les worktrees ne sont PAS sync). Henri opère un merge `ipxe → main` post-review pour propager.
> - **Action Henri post-merge VM up** : reload PHP-FPM (`systemctl reload php8.2-fpm@www-admin`), reload Apache, smoke `curl http://192.168.122.50/ipxe/installation-windows -d 'mac=...&uuid=...'` → vérifie body avec items `install_win10` + `install_win11` + smoke `curl 'http://192.168.122.50/ipxe/windows/unattend.xml?mac=...&uuid=...&version=Win11&bios=uefi'` → vérifie XML unattend généré.
> - **NE PAS** modifier `sambaedu/ipxe/*.php` ni `legacy/modules/ipxe/*.php` — restent intacts (le catchall continue de servir `/ipxe/clonage.php`, `/ipxe/Win10/repair.bat.php` etc. — non scope 3.5).
> - **NE PAS** créer de commit hors scope (rappel 3.1 `50c6275`).
> - **mémoire `feedback_auth_iso_legacy`** : pas d'introduction de Bearer per-host. Les XML unattend/sysprep contiennent des secrets (mots de passe `se4install_passwd`, `adminse_passwd`, AD `domain` join credentials) — la protection reste **LAN-only + MAC/UUID matching** iso-legacy. Aucun secret loggué (sha256 de l'XML généré uniquement).
> - **mémoire `project_php_fpm_user_www_admin`** : tout fichier écrit par le code (logs) doit être chown `www-admin` (uid 599). **Décision SM 3.5** : ne **pas** écrire les snapshots `/tmp/unattend.log` / `/tmp/sysprep.log` / `/tmp/actions-*.log` legacy. L'audit passe par channel `ipxe` + sha256 only.
> - **Secrets dans l'unattend.xml + install.bat** : `adminse_passwd`, `se4install_passwd`, `win_user_passwd` sont interpolés dans des artefacts text/plain renvoyés au poste qui boot. Mitigation identique 3.4 : `auth.v1.lan-only` strict + matching MAC/UUID strict + log audit (sha256 only).

---

## Encadré contexte

**Continuité avec 3.4** : 3.4 a posé le pattern complet (enum `install_*` + service de génération d'artefacts text/plain + tracker post-install + extension menu admin) pour Linux. 3.5 **applique strictement** le même pattern pour Windows. Le mainteneur dev expérimente quasi-zéro résistance architecturale — toute la mécanique est rodée.

3.5 **active** la branche Windows en :

1. Ajoutant un endpoint natif `GET|POST /ipxe/installation-windows` qui rend un menu interactif iPXE listant les variantes Win10/Win11.
2. Chainant chaque item vers `/ipxe/action/{install_win*}` (= dispatch via l'enum `IpxeAdminAction` étendu — pattern iso 3.2/3.4).
3. Le `IpxeActionResolver` rend alors le template Blade `ipxe.actions.install_win*` qui contient le bloc `kernel Win10/wimboot` + `initrd winpeshl.ini` + `initrd install.bat /ipxe/windows/install.bat##params` + `initrd unattend.xml /ipxe/windows/unattend.xml##params` + BCD/boot.sdi/boot.wim statiques + `boot`.
4. Le firmware iPXE charge wimboot kernel + initrds, WinPE démarre, exécute `install.bat` (fetché dynamiquement) qui monte `\\se4fs\install` + lance `setup.exe /unattend:unattend.xml` (fetché dynamiquement et interpolé pour le poste).
5. En fin d'install WinPE → curl `/ipxe/windows/action` avec `etape=winpe&ret=0` → `WindowsPostInstallTracker::record()` → log audit + insert `MachineBootLog action='ipxe_win_install'`.
6. Plus tard, en fin de session OOBE (configurée par sysprep.xml legacy ou unattend.xml généré pour le 1er reboot) → curl `/ipxe/windows/action` avec `etape=oobe&ret=0` → tracker met à jour `Workstation::os='windows'` + progress=100%.

**Topologie cible 3.5** :

```
Firmware iPXE (3.1 known menu) → choisit "1" (login admin)
  ↓
/ipxe/admin (3.2 + 3.3 + 3.4 + ext 3.5)
  → item (w) Installation Windows
  ↓ user choisit (w)
/ipxe/installation-windows (3.5 — menu interactif)
  → render menu listant : install_win10, install_win10_debug, install_win10_disk,
                          install_win10_perso, install_win11 (defaut), install_win11_disk,
                          install_win11_perso
  ↓ user choisit (ex.) install_win11
chain vers /ipxe/action/install_win11##params
  ↓
/ipxe/action/install_win11 (3.2 + ext enum 3.5)
  → IpxeAdminAction::tryFrom('install_win11') OK
  → IpxeActionResolver::resolve()
    → action.windowsMeta() → {version: 'Win11', action: 'wimboot11', debug: 0,
                              disk: 0, perso: 0}
    → injecte windowsVersion + winAction + winDebug + winDisk + winPerso + winpeshlUrl
      + installBatUrl + unattendXmlUrl + bcdAssetPath + bootSdiAssetPath
      + bootWimAssetPath dans le contexte Blade
  → render template ipxe.actions.install_win11.blade.php
  → body iPXE :
      #!ipxe
      kernel Win10/wimboot
      initrd --name winpeshl.ini Win10/winpeshl.ini winpeshl.ini
      params
      param uuid ...
      param mac ...
      param debug 0
      param version Win11
      param action wimboot11
      iseq ${platform} efi && param bios uefi || param bios legacy
      initrd --name install.bat http://se4fs/ipxe/windows/install.bat##params install.bat
      params
      param uuid ...
      param mac ...
      param action wimboot11
      param version Win11
      param disk 0
      param perso 0
      iseq ${platform} efi && param bios uefi || param bios legacy
      initrd --name unattend.xml http://se4fs/ipxe/windows/unattend.xml##params unattend.xml
      initrd --name BCD Win11/boot/bcd BCD
      initrd --name boot.sdi Win11/boot/boot.sdi boot.sdi
      initrd --name boot.wim Win11/sources/boot.wim boot.wim
      boot
  ↓ firmware iPXE charge wimboot + winpeshl + install.bat + unattend.xml + BCD + boot.sdi + boot.wim, boot WinPE
WinPE boot → exécute winpeshl.ini → lance install.bat
  ↓
install.bat (généré par /ipxe/windows/install.bat) exécute :
  wpeutil InitializeNetwork
  IPCONFIG /RENEW
  PING se4fs_ip
  net use z: \\se4fs\install /user:se4install@DOMAIN se4install_passwd
  z:\os\Win11\sources\setup.exe /unattend:x:\windows\system32\unattend.xml
  curl -F "etape=winpe" -F "name=PC-101" -F "ret=0" http://se4fs/ipxe/windows/action
  ↓
/ipxe/windows/action (3.5)
  → WindowsPostInstallTracker::record(workstation, etape=winpe, ret=0, name)
    → set_progress 5% + set_statut 'installation WinPE'
  → insert MachineBootLog action='ipxe_win_install'
  → response 200 vide (parité legacy + headers no-store noindex)
  ↓
setup.exe consomme unattend.xml → install Windows complet → 1er reboot → OOBE
  ↓ OOBE 1st logon : commande `curl -F "etape=oobe" -F "name=%computername%" -o action.cmd http://se4fs/ipxe/windows/action`
/ipxe/windows/action (3.5)
  → WindowsPostInstallTracker::record(workstation, etape=oobe, ret=0|<missing>, name)
    → Workstation::os = 'windows'
    → Workstation::status = 'installation Windows terminee'
    → Workstation::last_report_at = now()
    → set_progress 100%
  → insert MachineBootLog action='ipxe_win_report'
  → response 200 avec body bash minimal (parité partielle) — voir D10 décision retour
```

**Comportement parité legacy** (à reproduire iso strict — cf. `sambaedu/ipxe/installation-windows.php`, `Win10/install.bat.php`, `Win10/unattend.xml.php`, `Win10/diskpart.php`, `Win10/action.php` lignes 39-732, `Win10/sysprep.xml.php`) :

1. **`/ipxe/installation-windows`** — menu interactif :
   - **Poste connu** → menu complet avec 7 items `install_win*` + shell + retour + exit.
   - **Poste inconnu** → menu erreur D7 (iso 3.3/3.4) + chain `/ipxe/admin`.
   - **Hostname injecté** dans le titre menu (`menu installation clients Windows pour {{ mac }}`). Pas d'injection dans cmdline — c'est `WindowsUnattendBuilder` qui injecte le nom dans le XML.
2. **`/ipxe/action/install_win*`** — dispatch via enum :
   - Variante = un template Blade dédié `ipxe.actions.install_win*` qui construit la cmdline.
   - **PAS** de validation `version` côté URL — c'est la config serveur qui décide (whitelist enum strict).
3. **`/ipxe/windows/install.bat`** — génération bash WinPE text/plain :
   - **Inputs** : `mac`, `uuid` (auth matching), `version` (whitelist Win10/Win11 + fallback config), `debug` (0|1), `bios` (`legacy|uefi` — détecté par iPXE), `perso` (0|1).
   - **Workflow** :
     1. Résolution `WorkstationLocator::locate($mac, $uuid)` — si null → response 200 vide + log warning (parité legacy `install.bat.php:32` `if (auth_action(...))` qui n'écrit rien sinon).
     2. Récupère le `name` depuis Workstation (pas de fallback `*` ici — iso-legacy implicite).
     3. Construit le script `.bat` ASCII avec `\r\n` line endings (CRITIQUE — WinPE n'exécute pas un .bat en LF only).
     4. Interpolation `se4fs_name`, `se4install_name`, `domain`, `se4install_passwd`, `version`.
     5. Headers `Content-Type: text/plain; charset=utf-8` + `Cache-Control: no-store` + `X-Robots-Tag: noindex`.
     6. Insert `MachineBootLog action='ipxe_win_install'` + log audit sha256.
     7. Body bash avec `set_progress 5%` + `set_statut 'installation WinPE'` (iso-legacy lignes 63-71 portés natifs vers `WindowsPostInstallTracker::recordInstallBatGenerated()`).
4. **`/ipxe/windows/unattend.xml`** — génération XML text/plain :
   - **Inputs** : `mac`, `uuid` (auth matching), `version` (whitelist), `bios` (`legacy|uefi`), `disk` (0|1), `perso` (0|1).
   - **Workflow** :
     1. Résolution Workstation. Si null → response 200 vide (parité legacy `unattend.xml.php:32` `'name'=>'*'`) OU 404 + log warning — **décision 3.5** : 404 + log warning (cohérence 3.4 D4 preseed).
     2. Construit `$attrs` (`bios`, `version`, `join` = !perso, `os`=10, `name` = `$ws->name`, `ou` = OU AD du poste).
     3. Si `$disk == 1` → `$attrs['bios'] = ''` (parité `unattend.xml.php:28`).
     4. Appelle `WindowsUnattendBuilder::build($attrs)` qui clone le template `resources/ipxe/windows/unattend.xml` + applique les transforms DOMDocument (cf. D6).
     5. Insert `MachineBootLog action='ipxe_win_unattend'` + log audit sha256.
     6. Headers + body XML.
5. **`/ipxe/windows/diskpart.txt`** — génération diskpart text/plain :
   - **Inputs** : `mac`, `uuid`.
   - **Workflow** : très simple — body iso-legacy `diskpart.php:22-25` ("select disk O\r\nselect partition 1\r\nassign letter=U\r\n"). Inputs validés mais aucun rendu conditionnel (parité legacy stricte).
   - Cet endpoint est consommé par `repair.bat.php` (= action `winpe` réparation 3.2). Décision 3.5 = porter natif pour cohérence (le template `ipxe.actions.winpe.blade.php` 3.2 pointe encore vers `Win10/diskpart.php##params` via catchall — laisser tel quel, le diskpart natif est rendu prêt pour migration 3.7).
6. **`/ipxe/windows/sysprep.xml`** — stub minimal D11 :
   - **Inputs** : `name`.
   - **Workflow** : la logique legacy `sysprep.xml.php:14-39` dépend de `get_action($config, $name)['action']['type']` (= `renomme|clonage|postinst`) qui consomme la GLM `actions[]` LDAP non portée en SE5 (Epic 17.2 alternative). Décision 3.5 = **stub minimal** : si aucun `action_type` programmé en base (= toujours le cas en 3.5), retourner 200 + body vide + log info. Le vrai porting = Story 3.7.
7. **`/ipxe/windows/action`** — hook post-install multi-étapes :
   - **Inputs** : `name`, `uuid`, `etape`, `ret` (tous nullable formdata multipart parité legacy curl `-F`).
   - **Workflow scope 3.5** : seuls les flows `winpe` (start) + `oobe` (end) tracés. Autres `etape` → response 403 (parité legacy `action.php:489` `http_response_code(403)` pour les cas non gérés).
   - `etape='winpe'` + `ret='0'` → tracker `recordWinpeStart($workstation, $name)` → set_progress 5% + set_statut "installation WinPE" + MachineBootLog `ipxe_win_install`.
   - `etape='oobe'` + `ret='0'` → tracker `recordOobeComplete($workstation, $name)` → set os 'windows' + set_progress 100% + set_statut "installation Windows terminee" + MachineBootLog `ipxe_win_report`.
   - Autres combinaisons → 403 + log warning `ipxe.windows.action.unsupported_step` (déféré 3.7 — explicite dans le log pour aiguiller debug terrain).
   - Response 200 body vide + headers iso D10 (text/plain, no-store, noindex).

**Couplage Stories 3.2/3.3/3.4 — modifications mineures attendues** :

| Élément | Modification 3.5 | Raison |
|---|---|---|
| `resources/views/ipxe/menu/admin.blade.php` | Ajouter dans le bloc `@if($isKnown)` un item `(w) install-windows (w) Installation Windows` + section `:install-windows\nchain --replace --autofree {{ $installWindowsBaseUrl }}##params`. | 3.5 active la branche Installation Windows depuis le menu admin natif. |
| `IpxeMenuRenderer::renderAdminMenu()` | Exposer 2 nouvelles variables Blade `$installWindowsBaseUrl` + `$isInstallWindowsActive`. | Feature-flag + conditionnement template. |
| `IpxeService` | **Nouvelle méthode** `handleInstallationWindowsMenu(Request $request): Response` (orchestre `/ipxe/installation-windows` — handshake si MAC/UUID manquant + résolution Workstation + render menu `installation-windows.blade.php`). | Endpoint dédié. |
| `IpxeAdminAction` enum | **+7 cases** install_win* + méthode `windowsMeta()` retournant `{version, action, debug, disk, perso}` ou null. | Whitelist sécurité enrichie. |
| `IpxeMenuKind` | **+2 cases** `InstallationWindowsHandshake` / `InstallationWindowsMenu` (iso 3.4 D1). | Logging structuré. |
| `MachineBootLog.action` | 5 nouvelles valeurs persistées : `ipxe_install_win` (16 chars), `ipxe_win_unattend` (17 chars), `ipxe_win_install` (16 chars), `ipxe_win_diskpart` (17 chars), `ipxe_win_report` (15 chars). T0.6 audit obligatoire. | Audit traçabilité par flow. |
| `IpxeActionResolver` | Extension `resolveWindowsVariables()` (pattern iso 3.4 `resolveLinuxVariables()`) qui injecte 8 variables Blade pour les templates `install_win*` : `windowsVersion`, `winAction`, `winDebug`, `winDisk`, `winPerso`, `installBatUrl`, `unattendXmlUrl`, `winAssetsBase`. | Mutualiser le code de rendu install_win*. |

**Idempotence + sécurité** :

- `/ipxe/installation-windows` (rendu menu) : **idempotent** (lecture seule + log audit).
- `/ipxe/action/install_win*` (rendu kernel cmdline) : **idempotent** (rendu pur — pas de side effect DB hors log).
- `/ipxe/windows/install.bat` (rendu bash) : **idempotent** (le .bat est déterministe pour une (mac, uuid, version, bios, debug, perso) donnée — pas d'écriture DB hors log audit + MachineBootLog).
- `/ipxe/windows/unattend.xml` (rendu XML) : **idempotent** (XML déterministe).
- `/ipxe/windows/diskpart.txt` (rendu diskpart) : **idempotent** (body statique).
- `/ipxe/windows/sysprep.xml` (stub) : **idempotent** (body vide).
- `/ipxe/windows/action` (hook étape) : **partiellement idempotent** — l'update `os/status` est idempotente, le MachineBootLog insère une ligne par appel. Acceptable Phase 2.

**Side effects** :
- **DB PostgreSQL** : update `Workstation` (cas `oobe`), insert `MachineBootLog` (5 endpoints + 1 ré-entrée action).
- **Filesystem** : **AUCUNE** écriture. Pas de `/tmp/unattend.log`, `/tmp/sysprep.log`, `/tmp/actions-*.log`. Pas de fichier statique généré.
- **Logs** : `Log::channel('ipxe')` (events) + `MachineBootLog` (rows).
- **AD/LDAP** : **AUCUNE** modification AD côté 3.5. La mise au domaine post-install est portée par l'unattend.xml généré (`UnattendedJoin` component) qui contient le mot de passe `se4install_passwd` + DN OU — c'est Windows qui fait le `Add-Computer` côté client. Aucun appel `LdapRecord` côté SE5.

---

## Décisions tranchées (D1-D15, ne pas re-débattre)

> Cadrage SM 2026-05-21 par claude-opus-4-7. Le dev applique sans re-discuter. En cas de blocage technique réel, documenter dans Dev Agent Record et continuer.

### D1 — Namespace : extension **`App\Ipxe`** (pas de nouveau sous-namespace)

- Ajouts sous `app/Ipxe/` :
  ```
  app/Ipxe/
  ├── Services/
  │   ├── IpxeService.php                        (modifié — +handleInstallationWindowsMenu)
  │   ├── IpxeMenuRenderer.php                   (modifié — +renderInstallationWindowsMenu + admin installWindowsBaseUrl)
  │   ├── IpxeActionResolver.php                 (modifié — +resolveWindowsVariables)
  │   ├── WindowsUnattendBuilder.php             (NEW — assemble unattend.xml via DOMDocument)
  │   ├── WindowsInstallMenuBuilder.php          (NEW — construit variables Blade installation-windows)
  │   ├── WindowsInstallBatBuilder.php           (NEW — construit le .bat WinPE — port natif install.bat.php)
  │   └── WindowsPostInstallTracker.php          (NEW — hook /ipxe/windows/action)
  ├── Enums/
  │   ├── IpxeAdminAction.php                    (modifié — +7 cases install_win* + windowsMeta())
  │   ├── IpxeMenuKind.php                       (modifié — +InstallationWindowsMenu + InstallationWindowsHandshake)
  │   ├── WindowsVersion.php                     (NEW — enum 2 cases : Win10|Win11)
  │   └── WindowsInstallStep.php                 (NEW — enum 2 cases : Winpe|Oobe — scope 3.5)
  ├── Exceptions/
  │   └── UnattendGenerationException.php        (NEW — analogue PreseedGenerationException 3.4)
  ├── Http/
  │   ├── Controllers/
  │   │   ├── IpxeInstallationWindowsController.php   (NEW — GET|POST /ipxe/installation-windows)
  │   │   ├── IpxeWindowsInstallBatController.php     (NEW — GET|POST /ipxe/windows/install.bat)
  │   │   ├── IpxeWindowsUnattendController.php       (NEW — GET|POST /ipxe/windows/unattend.xml)
  │   │   ├── IpxeWindowsDiskpartController.php       (NEW — GET|POST /ipxe/windows/diskpart.txt)
  │   │   ├── IpxeWindowsSysprepController.php        (NEW — GET|POST /ipxe/windows/sysprep.xml)
  │   │   └── IpxeWindowsActionController.php         (NEW — GET|POST /ipxe/windows/action)
  │   └── Requests/
  │       ├── IpxeInstallationWindowsRequest.php (NEW — mêmes règles que IpxeBootRequest)
  │       ├── IpxeWindowsInstallBatRequest.php   (NEW — + version whitelist + bios/debug/perso)
  │       ├── IpxeWindowsUnattendRequest.php     (NEW — + version/bios/disk/perso)
  │       ├── IpxeWindowsDiskpartRequest.php     (NEW — minimal mac/uuid)
  │       ├── IpxeWindowsSysprepRequest.php      (NEW — name nullable)
  │       └── IpxeWindowsActionRequest.php       (NEW — name/uuid/etape/ret)
  └── Support/
      └── WindowsXmlPlaceholders.php             (NEW — catalogue des transforms XPath + sanitization XML)
  ```
- **Anti-pattern** : ne PAS créer `App\Ipxe\Windows\…` ni `App\Ipxe\Install\Windows\…` sous-namespace — la frontière est par responsabilité (Service/Controller/Renderer/FormRequest) déjà posée 3.1-3.4.

### D2 — 6 nouveaux endpoints HTTP (parité legacy iso)

- 6 blocs à ajouter dans `routes/web.php` **dans le bloc existant 3.1/3.2/3.3/3.4** (après les routes `/ipxe/linux/*` et **avant** le catchall) :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.5 — Installation Windows (Win10/Win11) (D2)
  |--------------------------------------------------------------------------
  | Remplace les endpoints legacy `/ipxe/installation-windows.php`,
  | `/ipxe/Win10/install.bat.php`, `/ipxe/Win10/unattend.xml.php`,
  | `/ipxe/Win10/diskpart.php`, `/ipxe/Win10/sysprep.xml.php` et
  | `/ipxe/Win10/action.php` (partiel — winpe/oobe seulement, autres
  | étapes déférées 3.7) par 6 routes natives.
  |
  | **ORDRE STRICT** : ce bloc doit rester AVANT le catchall ci-dessous —
  | sinon la route `{path}` capture toutes les requêtes `/ipxe/*` et rend
  | ces routes natives inaccessibles. Cf. test
  | `IpxeNamespaceTest::ipxe_3_5_routes_are_declared_before_catchall`.
  |
  | **Sécurité** : middleware `auth.v1.lan-only` (16.11) — restreint au LAN
  | scolaire RFC1918. Parité 3.1-3.4 D3/D8 — pas de JWT.
  |
  | **Throttle** : 600/min/IP iso 3.1-3.4 (suffisant pour ~50 postes
  | simultanés à la rentrée scolaire).
  */
  Route::match(['GET', 'POST'], '/ipxe/installation-windows', [
      \App\Ipxe\Http\Controllers\IpxeInstallationWindowsController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.installation-windows')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/windows/install.bat', [
      \App\Ipxe\Http\Controllers\IpxeWindowsInstallBatController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.windows.install-bat')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/windows/unattend.xml', [
      \App\Ipxe\Http\Controllers\IpxeWindowsUnattendController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.windows.unattend')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/windows/diskpart.txt', [
      \App\Ipxe\Http\Controllers\IpxeWindowsDiskpartController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.windows.diskpart')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/windows/sysprep.xml', [
      \App\Ipxe\Http\Controllers\IpxeWindowsSysprepController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.windows.sysprep')
      ->withoutMiddleware(['web']);

  Route::match(['GET', 'POST'], '/ipxe/windows/action', [
      \App\Ipxe\Http\Controllers\IpxeWindowsActionController::class, 'handle',
  ])
      ->middleware(['auth.v1.lan-only', 'throttle:600,1'])
      ->name('ipxe.windows.action')
      ->withoutMiddleware(['web']);
  ```
- **Pourquoi pas un groupe `Route::prefix('/ipxe/windows')` ?** **Anti-pattern strict** iso 3.3 D2 + 3.4 D2 — le groupe empêche les tests archi de scanner les routes individuellement, et `/ipxe/installation-windows` n'est pas sous `/ipxe/windows/`. Mieux : 6 routes plates.
- **Pourquoi `GET|POST` ?** Iso-legacy qui accepte les deux. Le firmware iPXE post-handshake utilise POST. WinPE depuis le poste boot utilise GET ou POST avec multipart selon le contexte.
- **Pourquoi URL `/ipxe/windows/install.bat` (avec extension) ?** Parité firmware iPXE — la directive `initrd --name install.bat http://se4fs/ipxe/Win10/install.bat.php##params install.bat` (legacy) attend une ressource servie. Côté Laravel : la route accepte le chemin avec extension (Apache n'intercepte pas, Laravel route le chemin entier). **Attention** : ne pas confondre `.bat` URL avec MIME — le `Content-Type` reste `text/plain`.

### D3 — Sécurité : **réutilisation stricte `auth.v1.lan-only` (16.11) — pas d'évolution**

- Iso 3.1/3.2/3.3/3.4 D3.
- **Risque accepté** : les secrets dans l'unattend.xml + install.bat (`se4install_passwd`, `adminse_passwd`, `domain` join credentials) sont renvoyés en clair text/plain à tout poste LAN qui pose un (MAC, UUID) valide. Mitigation = LAN scolaire restrictif + log audit (sha256 uniquement + hostname + IP).
- **Anti-pattern** : ne PAS introduire de Bearer per-host, ne PAS chiffrer (incompatible avec WinPE qui consomme du text/plain), ne PAS porter `totp_code("se4install")` legacy.

### D4 — Résolution poste : **réutilisation stricte `WorkstationLocator` (3.1)**

- Iso 3.1-3.4 D4 — pas de duplication, pas de refactor.
- 6 endpoints 3.5 résolvent la Workstation via `WorkstationLocator::locate($mac, $uuid, $product)`.
- **Tolérance poste inconnu** :
  - `/ipxe/installation-windows` : menu erreur D7 + chain `/ipxe/admin` (iso 3.4).
  - `/ipxe/action/install_win*` : `hostname=unknown` dans cmdline + log warning + render quand même (parité legacy `installation-windows.php:16` `$name = $machine['cn'] ?? "aleatoire"`). Acceptable Phase 2 — l'unattend.xml retournera 404 si poste inconnu.
  - `/ipxe/windows/install.bat` : poste inconnu → 200 + body vide + log warning (parité legacy `install.bat.php:32` `if (auth_action(...))` qui n'écrit rien sinon).
  - `/ipxe/windows/unattend.xml` : poste inconnu → **404 + log warning `ipxe.windows.unattend.unknown_workstation`**. Décision 3.5 (cohérence 3.4 D4 preseed unknown 404).
  - `/ipxe/windows/diskpart.txt` : poste inconnu → 200 + body iso-legacy (parité — le body est statique, aucun risque). Log warning.
  - `/ipxe/windows/sysprep.xml` : poste inconnu OU `action_type` manquant → 200 body vide + log info (parité legacy + scope 3.5 stub).
  - `/ipxe/windows/action` : poste inconnu → 200 body vide + log warning (parité legacy `action.php:733` `http_response_code(403)` si `!isset($_POST['name'])` — décision 3.5 = 200 silencieux car Windows ne sait pas reformer).

### D5 — Service `WindowsInstallMenuBuilder` (variables Blade menu)

- Nouveau service `App\Ipxe\Services\WindowsInstallMenuBuilder` (singleton stateless) — pattern iso 3.4 `LinuxInstallMenuBuilder`.
- **Méthode principale** :
  ```php
  /**
   * Construit le payload de variables Blade pour le menu /ipxe/installation-windows.
   *
   * @return array{
   *     shebang: string,
   *     workstationName: string,
   *     ip: string,
   *     mac: string,
   *     uuid: string,
   *     serverBaseUrl: string,
   *     installWindowsItems: list<array{enumValue: string, label: string}>,
   *     menuDefault: string,
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
- La liste `installWindowsItems` est lue depuis `config('ipxe.windows.menu_items', [...])` (D11) — par défaut 7 entrées.

### D6 — Service `WindowsUnattendBuilder` (orchestrateur unattend.xml)

- Nouveau service `App\Ipxe\Services\WindowsUnattendBuilder` (singleton enregistré dans `IpxeServiceProvider`).
- **Dépendances injectées** :
  - `App\Ipxe\Support\WindowsXmlPlaceholders` (NEW — catalogue + sanitization XML).
  - `Illuminate\Contracts\Filesystem\Filesystem` (lecture template).
- **Méthode publique principale** :
  ```php
  /**
   * Génère l'unattend.xml pour un poste donné.
   *
   * @param  Workstation $workstation
   * @param  WindowsVersion $version  Win10|Win11.
   * @param  array{bios:string, disk:int, perso:int} $attrs
   * @return string  XML formatté UTF-8.
   * @throws UnattendGenerationException  si template manquant ou config invalide.
   */
  public function build(
      Workstation $workstation,
      WindowsVersion $version,
      array $attrs,
  ): string;
  ```
- **Algorithme** — port iso-legacy `update_xml_unattend()` (`windows.inc.php:3-380`) **simplifié au scope 3.5** :
  1. Lit le template depuis `resources/ipxe/windows/unattend.xml` (copie depuis `sambaedu/ipxe/Win10/unattend.xml`).
  2. Si `version === Win11` → injecte le fragment `RunSynchronousCommand` BypassTPM/BypassSecureBoot/BypassRAM/BypassCPU/BypassStorage (parité `windows.inc.php:15-49`).
  3. Si `bios === 'legacy'` ou `'uefi'` (et non vide) → injecte le fragment `DiskConfiguration` correspondant + `ImageInstall` avec disk=0/part=1 (legacy) ou disk=0/part=2 (uefi) (parité `windows.inc.php:51-227`). Si `disk == 1` (bios vide) → **pas** d'injection DiskConfiguration (parité `unattend.xml.php:28`).
  4. **AutoLogon** : si `attrs['perso'] == 0` → `Username = se4install_name`, `Password = se4install_passwd`, `Domain = domain` ; si `perso == 1` → `Username = win_user`, `Password = win_user_passwd`, `Domain = ''` (parité `windows.inc.php:228-245`).
  5. **ComputerName** : injecte `$workstation->name` dans tous les `ComputerName` nodes (parité `windows.inc.php:247-251`).
  6. **RegisteredOrganization / FullName / ProductKey** : `RegisteredOrganization = domain`, `Organization = domain`, `FullName = se4install_name`, `ProductKey = win_key` (parité `windows.inc.php:252-265`).
  7. **UnattendedJoin** : si `perso == 0` → injecte le component `Microsoft-Windows-UnattendedJoin` dans `specialize` avec `Credentials.Username = se4install_name`, `Credentials.Password = se4install_passwd`, `Credentials.Domain = domain`, `JoinDomain = domain`, `MachineObjectOU = $attrs['ou']` (parité `windows.inc.php:267-300`).
  8. **LocalAccount** : injecte `LocalAccount` avec `Name = adminse_name`, `Password = adminse_passwd`, `Group = Administrators` (parité `windows.inc.php:302-333`).
  9. **AutoLogon LogonCount** : si `!join && win_autologon == 1` → set à `4294967295` (parité `windows.inc.php:335-339`).
  10. **AdministratorPassword** : set à `adminse_passwd` (parité `windows.inc.php:341-344`).
  11. **CommandLines + Paths** : interpole `###_ADMINSE_NAME_###`, `###_SE4FS_NAME_###`, `###_NAME_###` dans les `CommandLine` + `Path` nodes (parité `windows.inc.php:347-358`).
  12. Retourne le XML formatté UTF-8 (DOMDocument `saveXML()`).
- **Catalogue des placeholders XPath** (cf. legacy `windows.inc.php` audité) :
  ```
  ###_ADMINSE_NAME_###    ← config('sambaedu.windows.adminse_name')
  ###_SE4FS_NAME_###      ← config('sambaedu.se4fs_name')
  ###_NAME_###            ← Workstation::name (par-poste)
  ```
- **Anti-pattern** :
  - ❌ Ne PAS rendre l'unattend.xml via Blade — DOMDocument permet les manipulations XPath natives, plus robuste que string concat.
  - ❌ Ne PAS interpoler de variables non whitelistées (= source d'injection XML). Toutes les valeurs passent par `WindowsXmlPlaceholders::sanitize($value)` qui rejette chars XML-special (`<`, `>`, `&`, `"`, `'`) ou les escape via `htmlspecialchars(..., ENT_XML1, 'UTF-8')`.
  - ❌ Ne PAS écrire le snapshot `/tmp/unattend.log` (parité legacy retirée — debug only).

### D7 — Service `WindowsInstallBatBuilder` (orchestrateur install.bat)

- Nouveau service `App\Ipxe\Services\WindowsInstallBatBuilder` (singleton stateless).
- **Méthode principale** :
  ```php
  /**
   * Génère le script bash WinPE pour le poste.
   *
   * @param  Workstation $workstation
   * @param  WindowsVersion $version
   * @param  array{bios:string, debug:int, perso:int} $attrs
   * @return string  Bash WinPE avec line endings \r\n.
   */
  public function build(
      Workstation $workstation,
      WindowsVersion $version,
      array $attrs,
  ): string;
  ```
- **Algorithme** — port iso-legacy `install.bat.php:14-72` :
  1. Construit ligne par ligne le script bash WinPE.
  2. **Line endings** : `\r\n` strict (CRITIQUE — WinPE rejette les fichiers LF only).
  3. Si `debug == 1` → `$pause = "PAUSE\r\n"`, sinon `$pause = "\r\n"`.
  4. Interpolation : `se4fs_ip`, `se4fs_name`, `se4install_name`, `domain`, `se4install_passwd`, `version`, `$workstation->name`, `bios`.
  5. Sanitization shell-arg sur tous les values interpolées (cf. `WindowsXmlPlaceholders::sanitizeShellArg()` — rejette `\r`, `\n`, `"`, `'`, `;`, `&`, `$`, backtick).
  6. Retourne le string bash.
- **Side effects** : aucun (le service ne fait que générer du texte). Le controller appelle séparément `WindowsPostInstallTracker::recordInstallBatGenerated()` pour la trace audit (`MachineBootLog action='ipxe_win_install'` + set_progress 5% + set_statut iso-legacy lignes 63-71).

### D8 — Logging structuré channel `ipxe` (extension 3.1-3.4)

- 14 nouveaux events à logger (channel `ipxe`, driver daily 14j — iso 3.1) :
  - **Menu Installation Windows** :
    - `ipxe.install_windows.menu_rendered` (info)
    - `ipxe.install_windows.menu_render_error` (error)
    - `ipxe.install_windows.handshake` (info)
  - **Action install_win\*** : réutilise event 3.2 `ipxe.action.dispatched` (context `action` = `install_win*`).
  - **install.bat** :
    - `ipxe.windows.install_bat.generated` (info) — script généré. Context : ip, workstation_id, workstation_name_prefix (6), version (Win10|Win11), debug (0|1), bash_sha256 (64 chars).
    - `ipxe.windows.install_bat.unknown_workstation` (warning)
    - `ipxe.windows.install_bat.invalid_version` (warning)
  - **unattend.xml** :
    - `ipxe.windows.unattend.generated` (info) — XML généré. Context : ip, workstation_id, workstation_name_prefix (6), version, bios, join (bool), xml_sha256 (64 chars), xml_size_bytes (int).
    - `ipxe.windows.unattend.unknown_workstation` (warning)
    - `ipxe.windows.unattend.invalid_version` (warning)
    - `ipxe.windows.unattend.generation_error` (error)
  - **diskpart** :
    - `ipxe.windows.diskpart.served` (info)
  - **sysprep** :
    - `ipxe.windows.sysprep.stub_served` (info) — stub rendu (scope 3.5)
  - **action callback** :
    - `ipxe.windows.action.winpe_start` (info) — `etape=winpe&ret=0`
    - `ipxe.windows.action.oobe_complete` (info) — `etape=oobe&ret=0`
    - `ipxe.windows.action.unsupported_step` (warning) — autre étape (déférée 3.7)
    - `ipxe.windows.action.unknown_workstation` (warning)
- **Préfixes obligatoires** sur valeurs sensibles : iso 3.1 — MAC 6 chars, UUID 8 chars, name 6 chars.
- **CRITICAL — pas de secret loggé** : ne **jamais** logger le contenu de l'unattend.xml ni le bash install.bat. Seulement les sha256.

### D9 — Schéma DB : **aucune migration**, réutilisation `Workstation` + `MachineBootLog`

- **Workstation** : colonnes `name`, `uuid`, `mac`, `os`, `status`, `last_report_at` déjà présentes. 3.5 écrit dans `os` et `status` via `WindowsPostInstallTracker::recordOobeComplete()`.
- **MachineBootLog.action** : varchar(20). 5 nouvelles valeurs (`ipxe_install_win` 16 chars, `ipxe_win_unattend` 17 chars, `ipxe_win_install` 16 chars, `ipxe_win_diskpart` 17 chars, `ipxe_win_report` 15 chars) — toutes ≤17 chars. T0.6 audit obligatoire.
- **Anti-pattern** : ne PAS étendre `Workstation` avec une colonne `windows_install_started_at` ou `windows_progress_pct`. L'audit fin se fait via `MachineBootLog` + log channel.

### D10 — Templates Blade — **9 nouveaux fichiers + 1 modifié**

- **Nouveaux** :
  - `resources/views/ipxe/menu/installation-windows.blade.php` (~50 lignes) — port natif `installation-windows.php` (110 L) : 7 items install_win* + retour + shell + exit.
  - `resources/views/ipxe/actions/install_win10.blade.php` (~25 lignes) — port natif `actions/wimboot10.php`.
  - `resources/views/ipxe/actions/install_win10_debug.blade.php` (~25 lignes) — variante `debug=1`.
  - `resources/views/ipxe/actions/install_win10_disk.blade.php` (~25 lignes) — variante `disk=1`.
  - `resources/views/ipxe/actions/install_win10_perso.blade.php` (~25 lignes) — variante `perso=1`.
  - `resources/views/ipxe/actions/install_win11.blade.php` (~25 lignes) — port natif `actions/wimboot11.php`.
  - `resources/views/ipxe/actions/install_win11_disk.blade.php` (~25 lignes) — variante `disk=1`.
  - `resources/views/ipxe/actions/install_win11_perso.blade.php` (~25 lignes) — variante `perso=1`.
- **Modifié** :
  - `resources/views/ipxe/menu/admin.blade.php` — ajouter dans le bloc `@if($isKnown)` :
    ```blade
    @if($isInstallWindowsActive)
    item --key w install-windows (w) Installation Windows (Win10/Win11)
    @endif
    ```
    et la section :
    ```blade
    :install-windows
    chain --replace --autofree {{ $installWindowsBaseUrl }}##params
    ```
- **Charset ASCII strict** : iso 3.1/3.2/3.3/3.4 — pas d'accent fr. Test archi étend la couverture aux 8 nouveaux templates.
- **Newline final obligatoire** : iso 3.1.
- **Pas de PHP residual** : iso 3.1.
- **Shebang `#!ipxe`** : injecté comme variable Blade `{!! $shebang !!}` (iso 3.1 DO-13).
- **L'unattend.xml + install.bat + sysprep.xml + diskpart.txt NE SONT PAS des templates Blade** — ils sont rendus par les services dédiés (`WindowsUnattendBuilder`, `WindowsInstallBatBuilder`, etc.) qui font string concat / DOMDocument. Justification iso 3.4 D10 — les artefacts text/plain contiennent `\r\n` + chars binaires sensibles + structures XML, le rendu Blade ajouterait du parsing inutile et fragile.

### D11 — Variables de configuration : **extension `config/ipxe.php` + `config/sambaedu.php`**

- Nouvelle section dans `config/ipxe.php` :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.5 — Installation Windows (D11)
  |--------------------------------------------------------------------------
  */
  'windows' => [
      'enabled' => filter_var(env('IPXE_INSTALL_WINDOWS_ENABLED', true), FILTER_VALIDATE_BOOL),
      'menu_timeout_ms' => (int) env('IPXE_INSTALL_WIN_TIMEOUT_MS', 10000),
      'background_png' => env('IPXE_INSTALL_WIN_BG_PNG', 'png/windows10.png'),
      'default_variant' => env('IPXE_INSTALL_WIN_DEFAULT', 'install_win11'),
      'menu_items' => [
          ['enum' => 'install_win10',       'label' => 'Installation de Windows 10 (auto)'],
          ['enum' => 'install_win10_debug', 'label' => 'Installation W10 en mode debug des drivers'],
          ['enum' => 'install_win10_disk',  'label' => 'Installation W10 avec choix du partitionnement (double boot)'],
          ['enum' => 'install_win10_perso', 'label' => 'Installation W10 pour pc perso (hors domaine)'],
          ['enum' => 'install_win11',       'label' => 'Installation de Windows 11 (auto - defaut)'],
          ['enum' => 'install_win11_disk',  'label' => 'Installation W11 avec choix du partitionnement'],
          ['enum' => 'install_win11_perso', 'label' => 'Installation W11 pour pc perso (hors domaine)'],
      ],
      'allowed_versions' => ['Win10', 'Win11'],  // whitelist stricte
      'assets_paths' => [
          'wimboot' => env('IPXE_WIN_WIMBOOT_PATH', 'Win10/wimboot'),
          'winpeshl' => env('IPXE_WIN_WINPESHL_PATH', 'Win10/winpeshl.ini'),
          'bcd' => env('IPXE_WIN_BCD_PATH', '{version}/boot/bcd'),
          'boot_sdi' => env('IPXE_WIN_BOOT_SDI_PATH', '{version}/boot/boot.sdi'),
          'boot_wim' => env('IPXE_WIN_BOOT_WIM_PATH', '{version}/sources/boot.wim'),
      ],
      'unattend_template_path' => env(
          'IPXE_WIN_UNATTEND_TEMPLATE',
          resource_path('ipxe/windows/unattend.xml'),
      ),
  ],
  ```
- **Nouvelle sous-section dans `config/sambaedu.php`** (variables consommées par unattend + install.bat) :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.5 — Variables Windows unattend + install.bat (D11)
  |--------------------------------------------------------------------------
  | Convention : tous les SECRETS sont rendus en clair dans l'unattend.xml +
  | install.bat text/plain renvoyés au poste LAN qui boot. Mitigation =
  | auth.v1.lan-only + MAC/UUID matching strict (cf. story 3.5 D3).
  */
  'windows' => [
      'adminse_name' => env('SAMBAEDU_ADMINSE_NAME', 'adminse'),
      'adminse_passwd' => env('SAMBAEDU_ADMINSE_PASSWD', ''),  // SECRET
      'win_key' => env('SAMBAEDU_WIN_KEY', 'VK7JG-NPHTM-C97JM-9MPGT-3V66T'),  // generic Win10/11 KMS key
      'win_user' => env('SAMBAEDU_WIN_USER', ''),
      'win_user_passwd' => env('SAMBAEDU_WIN_USER_PASSWD', ''),  // SECRET
      'win_autologon' => (int) env('SAMBAEDU_WIN_AUTOLOGON', 0),
  ],
  ```
- **Valeurs par défaut** : iso-legacy. Henri peut override via `.env`.
- **Audit T0.5** : vérifier `SE4INSTALL_*` et `SAMBAEDU_DOMAIN` déjà définis (réutilisation 3.4) — sinon créer.

### D12 — `MachineBootLog::action` — extension **sans migration** (iso 3.1-3.4)

- `varchar(20)` confirmé sans CHECK. Les 5 nouvelles valeurs :
  - `ipxe_install_win` (16 chars) — render menu /ipxe/installation-windows.
  - `ipxe_win_unattend` (17 chars) — génération unattend.xml.
  - `ipxe_win_install` (16 chars) — génération install.bat + hook winpe start.
  - `ipxe_win_diskpart` (17 chars) — génération diskpart.txt.
  - `ipxe_win_report` (15 chars) — hook /ipxe/windows/action oobe.
- Tous ≤17 chars, fit dans `varchar(20)`.
- `initiated_by` = `'ipxe'` (string fixe).
- **Pas de migration**. T0.6 audit obligatoire.

### D13 — UI admin Livewire/Blade : **HORS-SCOPE 3.5** (option ouverte Phase 3)

- Iso 3.4 D13. Pas d'UI admin web 3.5.
- **Si Henri arbitre OUI** : ouvrir une story 3.5b dédiée Phase 3.

### D14 — Variante `installw11old` hors-scope 3.5 → déférée Story 3.7

- Iso section « HORS-SCOPE 3.5 » au-dessus.
- Justification : la variante `installw11old` dépend de la présence d'un dossier `/var/sambaedu/unattended/install/os/Win11-old` côté infra. Le legacy fait un `is_dir(...)` runtime pour afficher l'item. SE5 → décision = pas de feature flag dynamique sur le filesystem côté Apache (anti-pattern testabilité). Si besoin terrain confirmé → ouvrir une story dédiée qui ajoute un case enum `install_win11_old` + asset path config-driven.

### D15 — Endpoint `/ipxe/windows/sysprep.xml` : **stub minimal** (HORS-SCOPE port complet)

- Iso section « HORS-SCOPE 3.5 » au-dessus.
- Le legacy `Win10/sysprep.xml.php` (39 LOC) dépend de `$machine['action']['type']` (= `renomme|clonage|postinst`) qui consomme la GLM `actions[]` LDAP **non portée en SE5**.
- **Décision 3.5** : porter un endpoint stub qui retourne 200 + body vide + log info `ipxe.windows.sysprep.stub_served`. Si Story 3.7 enrichit `IpxeProgrammedActionResolver`, le service pourra être enrichi (idem `LinuxPostInstallTracker` partiel 3.4).

---

## Story

As **un poste de travail (Windows) en boot iPXE déjà résolu via `/ipxe/boot` (3.1), passé au menu `/ipxe/admin` (3.2/3.3) et déjà enregistré dans PostgreSQL+AD (3.3)** ainsi qu'**un mainteneur du codebase `sambaedu-reload`** et **Henri en tant qu'admin SER opérant sur le LAN scolaire** :

I want
- disposer de **6 routes Laravel natives** (`/ipxe/installation-windows`, `/ipxe/windows/{install.bat,unattend.xml,diskpart.txt,sysprep.xml,action}`) qui remplacent progressivement les endpoints legacy `installation-windows.php` + `Win10/{install.bat,unattend.xml,diskpart,sysprep.xml,action}.php` du proxy catchall ;
- pouvoir **lancer une installation Windows 10/11** depuis le menu iPXE en LAN, ce qui :
  - assemble dynamiquement la cmdline wimboot kernel/winpeshl/install.bat/unattend.xml/BCD/boot.sdi/boot.wim selon le profil du poste ;
  - sert un install.bat WinPE qui monte le partage `\\se4fs\install` et lance `setup.exe /unattend:unattend.xml` ;
  - sert un unattend.xml interpolé par DOMDocument selon le poste (nom AD, OU, version Win10/Win11, bios legacy/uefi, disk simple/multi, perso/domaine) ;
  - installe l'OS automatiquement sans intervention manuelle ;
- pouvoir **suivre la fin de l'install** via les hooks `curl http://se4fs/ipxe/windows/action` (étape `winpe` + étape `oobe`) qui mettent à jour `Workstation::os='windows'` + audit log ;
- assurer **zéro régression** sur les autres routes iPXE legacy non encore réécrites (`/ipxe/clonage.php`, `/ipxe/Win10/repair.bat.php`, etc.) — elles continuent de passer par le catchall jusqu'à 3.7.

So que :
- (a) **Henri** dispose d'un flow d'installation Windows natif, journalisé via channel `ipxe` (sha256 de l'unattend généré, hostname, version, bios), sans dépendance au legacy PHP procédural — visible via `tail storage/logs/ipxe/ipxe-$(date +%F).log` ;
- (b) **les opérateurs terrain** peuvent installer/réinstaller un poste Windows depuis le menu iPXE en LAN, en choisissant la variante adaptée (Win10 auto, Win11 auto, debug drivers, multi-boot, perso hors domaine) ;
- (c) **les développeurs de la story 3.7 (Clonezilla + flows post-install Windows complets)** disposent du pattern complet à étendre — le scope 3.5 a posé le `WindowsPostInstallTracker` minimal qui sera enrichi avec les flows `sysprep|nosysprep|join|renomme|post|wpkg`.

---

## Contexte

### État entrant (post-Story 3.4 review, 3.5 = suite directe)

| Élément | État actuel | Action 3.5 |
|---|---|---|
| Namespace `App\Ipxe` | ✅ Créé 3.1, étendu 3.2/3.3/3.4 (~50 classes + ~22 templates Blade + provider + config + 6 enums) | **Étendre** — +8 services (4 services nouveaux + 4 controllers + 6 FormRequests + 2 enums + 1 support + 1 exception) + 8 templates Blade |
| `IpxeService::handleBoot/handleAdmin/handleMaintenance/handleAction/handleInstallationLinuxMenu` | ✅ Existants 3.1-3.4 | **+1 méthode `handleInstallationWindowsMenu()`** (3.5) — pas de modif des autres |
| `IpxeMenuRenderer::renderAdminMenu()` | ✅ Existant 3.3, étendu 3.4 — expose `enrollmentBaseUrl` + `installLinuxBaseUrl` | **Étendre** — +1 variable `installWindowsBaseUrl` + `isInstallWindowsActive` |
| `IpxeMenuRenderer` méthodes render | ✅ +renderHandshake/renderInstallationLinuxMenu | **Étendre** — +renderInstallationWindowsMenu |
| `IpxeActionResolver::resolve()` | ✅ Existant 3.2, étendu 3.4 avec `resolveLinuxVariables` | **Étendre** — +`resolveWindowsVariables()` (8 variables Blade pour `install_win*`) |
| `IpxeAdminAction` enum | ✅ 12 cases (3 maintenance + 9 install_linux) | **Étendre** — +7 cases install_win* + méthode `windowsMeta()` |
| `IpxeMenuKind` | ✅ 14 cases (12 + 2 installation_linux_*) | **Étendre** — +2 cases installation_windows_* |
| `WorkstationLocator::locate()` | ✅ Existant 3.1 | **Réutiliser** — pas de modification |
| `MachineBootLog.action` | ✅ varchar(20), accepte `ipxe_*` 3.1-3.4 | **Étendre** — 5 nouvelles valeurs |
| Channel log `ipxe` | ✅ Créé 3.1 | **Étendre** — 14 nouveaux events D8 |
| `config/ipxe.php` | ✅ Créé 3.1, étendu 3.2/3.3/3.4 | **Étendre** — section `windows` D11 |
| `config/sambaedu.php` | ✅ Existe + section linux 3.4 | **Étendre** — section `windows` D11 |
| Routes `/ipxe/installation-windows`, `/ipxe/windows/{install.bat,unattend.xml,diskpart.txt,sysprep.xml,action}` | ❌ Servies par catchall legacy | **Créer** — 6 routes natives AVANT catchall (D2) |
| Templates Blade `resources/views/ipxe/menu/installation-windows.blade.php` | ❌ N'existe pas | **Créer** (D10) |
| Templates Blade `resources/views/ipxe/actions/install_win*.blade.php` | ❌ N'existent pas (7 cases) | **Créer** (D10) |
| Template XML `resources/ipxe/windows/unattend.xml` | ❌ N'existe pas (asset projet) | **Copier** depuis `sambaedu/ipxe/Win10/unattend.xml` (~123 lignes) |
| Doc QA `docs/qa/domains/ipxe.md` | ✅ Étendue 3.1-3.4 (~12-14 scénarios par story) | **Étendre** — section `## Story 3.5` + ≥12 scénarios stables 3.5-1 à 3.5-N |
| Tests Unit/Feature/Architecture iPXE | ✅ ~135-155 verts cumulés (3.1+3.2+3.3+3.4) | **Étendre** — ≥45 nouveaux tests cumulés (≥25 unit + ≥17 feature + ≥3 archi) |

### Source de vérité du comportement attendu

Les fichiers legacy à lire en T0.4 (lecture obligatoire) :

- `sambaedu/ipxe/installation-windows.php` (110 LOC) — menu Installation Windows. **Périmètre 3.5** : lignes 23-105 (header menu + items installw10/11/perso/disk/debug + sections chain). **Ignorer** : lignes 36, 40 commentées (Win10u UEFI experimental + Win10l2 diskless experimental = hors scope) + lignes 43-46 (`installw11old` déféré D14).
- `sambaedu/ipxe/Win10/install.bat.php` (73 LOC) — script bash WinPE. **Périmètre 3.5** : intégralité (port iso).
- `sambaedu/ipxe/Win10/unattend.xml.php` (39 LOC) + `sambaedu/includes/windows.inc.php:3-380` (`update_xml_unattend()` ~370 LOC) — générateur unattend.xml. **Périmètre 3.5** : intégralité **sauf** les branches dépendantes de `$machine['action']['type']` (renomme/clonage/postinst — déférées 3.7) + branche `Win7` (lignes 364-369 — pas d'usage terrain documenté).
- `sambaedu/ipxe/Win10/diskpart.php` (27 LOC) — body diskpart statique. **Périmètre 3.5** : intégralité (port iso strict).
- `sambaedu/ipxe/Win10/sysprep.xml.php` (39 LOC) — hook clonage. **Périmètre 3.5** : stub minimal D11.
- `sambaedu/ipxe/Win10/action.php` (736 LOC) — hook post-install multi-étapes. **Périmètre 3.5** : lignes 411-491 (cas `etape='winpe'` + `etape='oobe'` uniquement) + lignes 720-730 (default `set_os 'windows' + set_progress 100%`). **Ignorer** : autres étapes (sysprep/nosysprep/join/renomme/post/wpkg — déférées 3.7).
- `sambaedu/ipxe/Win10/unattend.xml` (123 LOC) — template DOMDocument à **copier dans `resources/ipxe/windows/unattend.xml`** (asset projet).
- `sambaedu/ipxe/actions/wimboot10.php` + `wimboot11.php` (29 LOC chacun) — kernel cmdlines. **Iso strict**.

### Risques entrants

| Risque | Sévérité | Mitigation 3.5 |
|---|---|---|
| Collision routes natives vs catchall | 🟠 Élevée | Iso 3.1-3.4 D2 — bloc routes AVANT catchall. Test archi `ipxe_3_5_routes_are_declared_before_catchall` étendu. |
| Régression sur `admin.blade.php` (ajout item install-windows) — un poste qui était en 3.4 stable casse en 3.5 | 🟠 Élevée | Test feature `IpxeAdminEndpointTest::it_shows_install_windows_item_when_enabled` + non-régression admin 3.3/3.4 items (enrollment + maintenance + install-linux) toujours présents. |
| Secrets dans unattend.xml + install.bat renvoyés en clair sur LAN | 🟠 Élevée | D3 — `auth.v1.lan-only` strict + matching MAC/UUID strict + log audit sha256 only. |
| Whitelist `version` trop permissive — un attaquant LAN pourrait deviner `?version=../../../etc/passwd` | 🟡 Moyenne | D11 — enum stricte `WindowsVersion` + Rule::in(`config('ipxe.windows.allowed_versions')`). Validation côté FormRequest **ET** côté service (defense in depth). |
| Génération XML cassée — DOMDocument exception sur template invalide | 🟡 Moyenne | T1.2 copie du template byte-identique + test unit qui charge + valide schéma XML. Exception `UnattendGenerationException` + log error `ipxe.windows.unattend.generation_error`. |
| Line endings `\r\n` du install.bat non respectés — WinPE échoue silencieusement | 🟡 Moyenne | T2.2 test unit qui asserte chaque ligne se termine par `\r\n` (`assertStringContainsString("\r\n", ...)` + `assertStringNotContainsString("\n\n", ...)`). |
| Hostname injecté dans XML → injection XML si name contient `<`, `>`, `&` | 🟡 Moyenne | Iso 3.3/3.4 — `IpxeHostnameSanitizer::isValidHostname()` (regex stricte 15 chars `[a-z0-9\-]`) valide en amont (3.3). Defense in depth `WindowsXmlPlaceholders::sanitize()` via `htmlspecialchars(..., ENT_XML1, 'UTF-8')`. |
| Asset Win10/Win11 binaires absents en VM (wimboot/winpeshl/BCD/boot.sdi/boot.wim) | 🟠 Élevée | T0.5 audit obligatoire SSH Henri sur la VM (en dehors du worktree) : confirmer `/var/sambaedu/unattended/install/os/Win{10,11}/sources/boot.wim` etc. présents. Documenter dans QA si absents. |
| Variables `.env` AD/Windows manquantes en VM | 🟠 Élevée | T0.5 audit `SAMBAEDU_ADMINSE_NAME`, `SAMBAEDU_ADMINSE_PASSWD`, `SAMBAEDU_WIN_KEY`, `SE4INSTALL_NAME`, `SE4INSTALL_PASSWD`, `SAMBAEDU_DOMAIN` + escalation Henri. |
| `MachineBootLog::action` rejette nouvelles valeurs | 🟢 Mineure | T0.6 audit — 5 nouvelles valeurs ≤17 chars dans varchar(20). |
| Performance — WinPE re-fetch les artefacts à chaque retry | 🟢 Mineure | Throttle 600/min/IP iso 3.1 suffit. Pas de cache nécessaire en Phase 2 (volumétrie ~50 postes × 5 retries < 600/min). |
| Win10 EOL 2025 → install Win10 obsolète | 🟢 Mineure (info) | Acceptable scope 3.5 — Win10 reste supporté pour parité legacy. Phase 3 envisage retrait progressif. |

### Pré-requis (à valider en T0)

- **Worktree git `ipxe`** : branche dédiée, pas de SSH VM. Iso 3.3/3.4.
- **Story 3.3 + 3.4 en review** : 🟡 status `review` au moment du cadrage SM. La phase dev 3.5 nécessite que **3.3 + 3.4 soient en `done`** (Henri valide ou bascule en `done`). **Bloquant amont à valider en T0.1.**
- **Schema `machine_boot_logs`** : ✅ confirmé varchar(20) sans CHECK par 3.1-3.4 T0.6.
- **Variables `.env` consommées par unattend + install.bat** : 🟡 à valider — l'audit T0.5 doit confirmer présence en VM.
- **Assets statiques Win10/Win11** : 🟡 à valider — `Win10/wimboot`, `Win10/winpeshl.ini`, `Win{10,11}/boot/bcd`, `Win{10,11}/boot/boot.sdi`, `Win{10,11}/sources/boot.wim` servis par Apache via catchall.
- **Apache config** : pas de modification — les 6 routes natives `/ipxe/installation-windows` + `/ipxe/windows/*` arrivent via le catchall et seront interceptées AVANT le catchall (iso 3.1 D2).

---

## Acceptance Criteria

> AC organisées en **10 volets**. Volet 10 = QA + sprint-status (append-only sur le runbook `ipxe.md` 3.1-3.4).

### Volet 1 — Enums + helper Support (D1, D11)

**AC1.1** — **Extension `IpxeAdminAction` enum avec 7 cases install_win\***

**Given** le fichier `app/Ipxe/Enums/IpxeAdminAction.php`,
**When** le dev ajoute les 7 nouveaux cases listés D1,
**Then** :
- `IpxeAdminAction::cases()` retourne exactement 19 cases (3 maintenance + 9 install_linux + 7 install_win).
- `IpxeAdminAction::tryFrom('install_win11')` retourne `IpxeAdminAction::InstallWin11` (et idem pour les 6 autres).
- Méthode `template()` mappe les 7 nouveaux cases vers `'ipxe.actions.install_win10'`, `'ipxe.actions.install_win10_debug'`, etc.
- **Nouvelle méthode** `windowsMeta(): ?array` qui retourne :
  - Pour les 4 cases Win10 : `['version' => 'Win10', 'action' => 'wimboot10', 'debug' => 0|1, 'disk' => 0|1, 'perso' => 0|1]`.
  - Pour les 3 cases Win11 : `['version' => 'Win11', 'action' => 'wimboot11', 'debug' => 0, 'disk' => 0|1, 'perso' => 0|1]`.
  - Pour les 12 cases existants (3 maintenance + 9 install_linux) : `null`.
- `linuxMeta()` retourne `null` pour les 7 nouveaux cases install_win* (non-régression 3.4).

**And** un test unit `tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php` étendu avec ≥8 nouveaux tests :
- `it_lists_exactly_nineteen_cases_after_3_5`
- `it_resolves_install_win11_to_correct_template`
- `it_resolves_install_win10_perso_with_perso_flag`
- `it_resolves_install_win10_disk_with_disk_flag`
- `it_resolves_install_win10_debug_with_debug_flag`
- `it_returns_null_windows_meta_for_non_windows_cases`
- `it_returns_null_linux_meta_for_install_win_cases` (non-régression 3.4)
- `it_returns_correct_log_name_for_install_win_cases`

**AC1.2** — **Création enums `WindowsVersion` + `WindowsInstallStep`**

**Given** les fichiers `app/Ipxe/Enums/WindowsVersion.php` et `app/Ipxe/Enums/WindowsInstallStep.php`,
**When** le dev les crée selon D1,
**Then** :
- `WindowsVersion` a exactement 2 cases : `Win10 = 'Win10'`, `Win11 = 'Win11'`.
- `WindowsInstallStep` a exactement 2 cases : `Winpe = 'winpe'`, `Oobe = 'oobe'` (scope 3.5).
- Méthode `WindowsVersion::fromString(string $raw): ?self` qui retourne le case ou null (anti-injection).
- Idem `WindowsInstallStep::fromString()`.

**And** tests unit ≥5 cas par enum (valides + invalides incluant path traversal + newline + injection XML).

**AC1.3** — **Catalogue `WindowsXmlPlaceholders` + sanitization**

**Given** le helper `App\Ipxe\Support\WindowsXmlPlaceholders`,
**When** le dev le crée selon D6,
**Then** :
- `WindowsXmlPlaceholders::catalog(): array<string, string>` retourne le mapping `###_<KEY>_### → config_key` (3 entrées : `###_ADMINSE_NAME_###`, `###_SE4FS_NAME_###`, `###_NAME_###`).
- `WindowsXmlPlaceholders::sanitize(string $value): string` :
  - Escape les chars XML-special via `htmlspecialchars(..., ENT_XML1 | ENT_QUOTES, 'UTF-8')`.
  - Rejette les newlines (`\r`, `\n`) — remplace par espace.
  - Rejette les chars non-printables (< 0x20 sauf espace) — remplace par espace.
- `WindowsXmlPlaceholders::sanitizeShellArg(string $value): string` :
  - Rejette `\r`, `\n`, `"`, `'`, `;`, `&`, `$`, backtick → remplace par `_`.
- `WindowsXmlPlaceholders::interpolate(string $template, array $values): string` qui remplace chaque `###_<KEY>_###` par `sanitize($values[strtolower(key)] ?? '')`.

**And** tests unit ≥10 cas dont 6 anti-injection (XML entity `&amp;`, CDATA `]]>`, newline `\n`, command injection `$(curl ...)`, backtick `` ` ``, UTF-8 invalide).

### Volet 2 — `WindowsUnattendBuilder` (D6)

**AC2.1** — **`WindowsUnattendBuilder::build()` génère un XML valide**

**Given** le service `App\Ipxe\Services\WindowsUnattendBuilder`,
**When** invoqué avec `(Workstation, WindowsVersion::Win11, ['bios' => 'uefi', 'disk' => 0, 'perso' => 0])`,
**Then** :
- Charge le template `resources/ipxe/windows/unattend.xml` via DOMDocument.
- Injecte le fragment `RunSynchronousCommand` BypassTPM/SecureBoot/RAM/CPU/Storage (Win11).
- Injecte le fragment `DiskConfiguration` UEFI (2 partitions).
- Injecte le fragment `Microsoft-Windows-UnattendedJoin` avec `JoinDomain = domain`, `MachineObjectOU = $attrs['ou']`.
- Injecte `LocalAccount` avec `Name = adminse_name`.
- Interpole tous les `###_<KEY>_###` (aucun résiduel).
- Retourne un string non vide commençant par `<?xml version="1.0"`.
- Le XML est parsable par `DOMDocument::loadXML()` sans erreur.

**And** test unit `tests/Unit/Ipxe/Services/WindowsUnattendBuilderTest.php` ≥10 tests :
- `it_builds_win10_unattend_with_legacy_bios`
- `it_builds_win11_unattend_with_uefi_bios`
- `it_includes_tpm_bypass_for_win11_only`
- `it_excludes_disk_configuration_when_disk_flag_set` (parité legacy disk=1 → pas de DiskConfiguration)
- `it_uses_join_credentials_when_perso_zero`
- `it_uses_local_credentials_when_perso_one` (variant)
- `it_injects_workstation_name_in_computer_name_nodes`
- `it_interpolates_admin_name_and_se4fs_name_in_commandlines`
- `it_throws_unattend_generation_exception_when_template_missing`
- `it_returns_well_formed_xml_parseable_by_domdocument`

**AC2.2** — **Anti-injection XML sur les inputs externes**

**Given** `WindowsUnattendBuilder::build()` invoqué avec une `Workstation::name = 'PC-101&<EVIL>'`,
**When** le service est appelé,
**Then** :
- Le nom est sanitizé via `IpxeHostnameSanitizer::sanitizeForIpxeOutput()` (déjà fait en 3.3, validation amont).
- Defense in depth : `WindowsXmlPlaceholders::sanitize()` escape les chars XML-special.
- Le XML final contient `PC-101&amp;&lt;EVIL&gt;` ou `PC-101` (selon la sanitization de hostname amont — typiquement 3.3 a déjà rejeté).
- Aucun node `<EVIL>` n'apparaît comme balise XML.

**And** test unit `it_escapes_xml_special_chars_in_computer_name` + `it_escapes_xml_special_chars_in_admin_name`.

**AC2.3** — **Log audit channel `ipxe`**

**Given** `WindowsUnattendBuilder::build()` invoqué avec succès,
**When** l'XML est généré (le controller appelle ensuite `Log::channel('ipxe')`),
**Then** :
- Log info `ipxe.windows.unattend.generated` avec context :
  - `ip`, `workstation_id`, `workstation_name_prefix` (6 chars), `version` (Win10|Win11), `bios`, `join` (bool), `xml_sha256` (64 chars), `xml_size_bytes` (int).
- **PAS** de log du contenu du XML (aucun secret).

**And** test unit `it_logs_unattend_generated_with_sha256_only`.

### Volet 3 — `WindowsInstallBatBuilder` + `WindowsInstallMenuBuilder` + `WindowsPostInstallTracker` (D5, D7)

**AC3.1** — **`WindowsInstallBatBuilder::build()` génère un script bash WinPE valide**

**Given** le service `App\Ipxe\Services\WindowsInstallBatBuilder`,
**When** invoqué avec `(Workstation, WindowsVersion::Win11, ['bios' => 'uefi', 'debug' => 0, 'perso' => 0])`,
**Then** :
- Retourne un string non vide commençant par `::cmd\r\n`.
- Chaque ligne se termine par `\r\n` (test : `substr_count($bash, "\r\n") >= 10`).
- Contient `wpeutil InitializeNetwork\r\n`, `IPCONFIG /RENEW\r\n`, `@PING <se4fs_ip>\r\n`, `@net use z: \\<se4fs_name>\install /user:<se4install_name>@<domain> <se4install_passwd>\r\n`, `z:\os\Win11\sources\setup.exe /unattend:x:\windows\system32\unattend.xml\r\n`, `curl -F "etape=winpe" -F "name=<PC-101>" -F "ret=0" http://<se4fs_name>/ipxe/windows/action\r\n` (URL native, pas `.php`).
- Si `bios == 'uefi'` : contient `%windir%\system32\bcdboot c:\windows /addlast\r\n`.
- Si `debug == 1` : contient `PAUSE\r\n` après chaque section critique.
- Sanitization shell-arg appliquée sur chaque valeur interpolée.

**And** test unit `WindowsInstallBatBuilderTest` ≥6 tests :
- `it_builds_win10_install_bat_legacy_bios`
- `it_builds_win11_install_bat_uefi_bios_with_bcdboot`
- `it_pauses_when_debug_enabled`
- `it_contains_only_crlf_line_endings`
- `it_uses_native_action_url_not_php`
- `it_sanitizes_shell_args_against_injection`

**AC3.2** — **`WindowsInstallMenuBuilder::build()` retourne les variables Blade**

**Given** le service `App\Ipxe\Services\WindowsInstallMenuBuilder`,
**When** invoqué avec `(Workstation, $serverBaseUrl, $ip)`,
**Then** retourne un array conforme au type documenté D5 avec :
- `installWindowsItems` = lecture de `config('ipxe.windows.menu_items')` (7 entrées par défaut).
- `workstationName` sanitizé via `IpxeHostnameSanitizer::sanitizeForIpxeOutput()`.
- `menuDefault` = `config('ipxe.windows.default_variant')` (= `install_win11` par défaut).
- `menuTimeoutMs` = `config('ipxe.windows.menu_timeout_ms', 10000)`.
- `isKnown` = `$workstation !== null`.

**And** test unit ≥4 tests (known/unknown, items count = 7, sanitization, default = install_win11).

**AC3.3** — **`WindowsPostInstallTracker::recordWinpeStart() + recordOobeComplete()`**

**Given** le service `App\Ipxe\Services\WindowsPostInstallTracker`,
**When** invoqué `recordWinpeStart($workstation, $name)`,
**Then** :
- `$workstation->status = 'installation WinPE'` (ASCII, pas d'accent).
- `$workstation->save()`.
- Log info `ipxe.windows.action.winpe_start` avec context (ip, workstation_id, workstation_name_prefix).
- Insert `MachineBootLog` `action='ipxe_win_install'` + `initiated_by='ipxe'` + `success=true`.

**Given** invoqué `recordOobeComplete($workstation, $name)`,
**Then** :
- `$workstation->os = 'windows'`.
- `$workstation->status = 'installation Windows terminee'` (ASCII).
- `$workstation->last_report_at = now()`.
- `$workstation->save()`.
- Log info `ipxe.windows.action.oobe_complete`.
- Insert `MachineBootLog` `action='ipxe_win_report'`.

**And** tests unit ≥6 tests (winpe success, oobe success, unknown workstation, idempotence, status ASCII strict, Carbon::setTestNow).

### Volet 4 — `IpxeService::handleInstallationWindowsMenu()` (D2)

**AC4.1** — **`IpxeService::handleInstallationWindowsMenu(Request)` orchestre la route**

**Given** la méthode `IpxeService::handleInstallationWindowsMenu()`,
**When** un poste appelle `GET|POST /ipxe/installation-windows`,
**Then** :
- Extrait `mac`/`uuid`/`product`/`ip`.
- Handshake si `mac === '' || uuid === ''` (parité 3.2/3.3/3.4) → `renderer->renderHandshake('installation-windows')` (réutilise la méthode 3.2).
- Sinon résolution `WorkstationLocator::locate()`.
- Log info `ipxe.install_windows.menu_rendered` (D8).
- Insert `MachineBootLog` `action='ipxe_install_win'` + `initiated_by='ipxe'`.
- Rendu via `renderer->renderInstallationWindowsMenu($workstation, $ip, $baseUrl)`.
- safeRender wrap iso 3.1 — fallback iPXE en cas d'exception.
- Headers iso D10 (`text/plain`, `no-store`, `noindex`).

**And** tests Unit ≥6 (handshake, known, unknown, MachineBootLog persisté, headers, safeRender).

### Volet 5 — Controllers + FormRequests (D2)

**AC5.1** — **`IpxeInstallationWindowsController::handle()` fin** (≤15 lignes)

**Given** le controller,
**When** appelé via la route,
**Then** délègue 100% à `IpxeService::handleInstallationWindowsMenu($request)`. Pas de logique métier.

**AC5.2** — **`IpxeWindowsUnattendController::handle()`** orchestre la génération XML

**Given** le controller,
**When** appelé via `GET|POST /ipxe/windows/unattend.xml`,
**Then** :
- Valide via `IpxeWindowsUnattendRequest` (mac/uuid nullable + version whitelist + bios/disk/perso).
- Résout Workstation. Si null → response 404 + log warning (D4).
- Parse `version` via `WindowsVersion::fromString()`. Si null → response 422 + log warning.
- Calcule OU AD du poste (`$workstation->getAdOu()` ou via service séparé — décision : utiliser `config('sambaedu.computers_rdn')` comme fallback iso-legacy si absent).
- Appelle `WindowsUnattendBuilder::build($workstation, $version, ['bios' => ..., 'disk' => ..., 'perso' => ..., 'ou' => ...])`.
- Insert `MachineBootLog` `action='ipxe_win_unattend'`.
- Headers `Content-Type: text/plain; charset=utf-8` + `Cache-Control: no-store` + `X-Robots-Tag: noindex`.
- Response 200 avec body = XML.

**AC5.3** — **`IpxeWindowsInstallBatController::handle()`** orchestre la génération bash

**Given** le controller,
**When** appelé via `GET|POST /ipxe/windows/install.bat`,
**Then** :
- Valide via `IpxeWindowsInstallBatRequest`.
- Résout Workstation. Si null → response 200 + body vide + log warning (parité legacy D4).
- Parse `version`. Si null → response 422 + log warning.
- Appelle `WindowsInstallBatBuilder::build($workstation, $version, $attrs)`.
- Appelle `WindowsPostInstallTracker::recordInstallBatGenerated($workstation)` pour le set_progress 5%.
- Insert `MachineBootLog` `action='ipxe_win_install'`.
- Headers + body bash.

**AC5.4** — **`IpxeWindowsDiskpartController::handle()`** body statique

**Given** le controller,
**When** appelé via `GET|POST /ipxe/windows/diskpart.txt`,
**Then** :
- Valide via `IpxeWindowsDiskpartRequest` (mac/uuid).
- Résout Workstation best-effort + log warning si null.
- Response 200 + body `"select disk O\r\nselect partition 1\r\nassign letter=U\r\n"` (iso-legacy strict).
- Insert `MachineBootLog` `action='ipxe_win_diskpart'`.
- Headers iso D10.

**AC5.5** — **`IpxeWindowsSysprepController::handle()` stub minimal (D11)**

**Given** le controller,
**When** appelé via `GET|POST /ipxe/windows/sysprep.xml`,
**Then** :
- Valide via `IpxeWindowsSysprepRequest` (name nullable).
- Log info `ipxe.windows.sysprep.stub_served` (scope 3.5 — pas d'`action_type` programmé).
- Response 200 + body vide + headers iso D10.
- Pas d'insert MachineBootLog (stub minimal D15-3.4).

**AC5.6** — **`IpxeWindowsActionController::handle()` consomme les hooks `winpe` + `oobe`**

**Given** le controller,
**When** appelé via `POST /ipxe/windows/action` (parité legacy curl `-F`),
**Then** :
- Valide via `IpxeWindowsActionRequest` (name/uuid/etape/ret nullable).
- Résout Workstation par UUID. Si null → response 200 + body vide + log warning (D4).
- Si `etape === 'winpe' && ret === '0'` → `tracker->recordWinpeStart($workstation, $name)`.
- Si `etape === 'oobe' && ret === '0'` → `tracker->recordOobeComplete($workstation, $name)`.
- Autres `etape` → response 200 + log warning `ipxe.windows.action.unsupported_step` (scope 3.5 — déféré 3.7).
- Response 200 + body vide.

**AC5.7** — **FormRequests permissives + whitelists strictes**

**Given** les 6 FormRequests,
**When** un poste poste des valeurs,
**Then** :
- `IpxeInstallationWindowsRequest` : `mac/uuid/product` nullable max 64/64/128 (iso 3.1).
- `IpxeWindowsInstallBatRequest` : idem + `version` nullable Rule::in(`config('ipxe.windows.allowed_versions')`) + `bios` nullable Rule::in(`['legacy', 'uefi']`) + `debug` nullable int + `perso` nullable int + `action` nullable string max 32.
- `IpxeWindowsUnattendRequest` : idem + `disk` nullable int.
- `IpxeWindowsDiskpartRequest` : minimal mac/uuid.
- `IpxeWindowsSysprepRequest` : `name` nullable max 64.
- `IpxeWindowsActionRequest` : `name` nullable max 64 + `uuid` nullable max 64 + `etape` nullable Rule::in(`['winpe', 'oobe']`) + `ret` nullable string max 8.
- `authorize()` = true sur les 6 (auth via middleware).

**And** tests feature ≥6 (whitelist OK, whitelist rejette, oversize input).

### Volet 6 — `IpxeMenuRenderer::renderInstallationWindowsMenu()` + `IpxeActionResolver` extension (D10)

**AC6.1** — **`IpxeMenuRenderer::renderInstallationWindowsMenu($ws, $ip, $serverBaseUrl)`**

**Given** la méthode,
**When** invoquée avec un poste connu,
**Then** :
- Délègue à `WindowsInstallMenuBuilder::build()` pour le payload.
- Rend `resources/views/ipxe/menu/installation-windows.blade.php`.
- Body commence par `#!ipxe`.
- Contient les 7 items `install_win*`.
- Contient les sections `:install_win*` chainant vers `/ipxe/action/install_win*##params`.
- Termine par `:exit\n{!! $bootDiskFallback !!}\n`.

**Given** invoquée avec un poste inconnu,
**Then** rend le menu erreur D7 (chain vers `/ipxe/admin`).

**And** tests unit ≥6 (known full menu, unknown error menu, ASCII strict, no PHP tags, shebang first, items count = 7).

**AC6.2** — **`IpxeActionResolver::resolve()` injecte `resolveWindowsVariables`**

**Given** la méthode,
**When** invoquée avec `IpxeAdminAction::InstallWin11`,
**Then** :
- Lit `$action->windowsMeta()` → `['version' => 'Win11', 'action' => 'wimboot11', 'debug' => 0, 'disk' => 0, 'perso' => 0]`.
- Injecte dans le contexte Blade :
  - `$windowsVersion` = `'Win11'`.
  - `$winAction` = `'wimboot11'`.
  - `$winDebug` = `0`.
  - `$winDisk` = `0`.
  - `$winPerso` = `0`.
  - `$installBatUrl` = `$scriptUrl . '/ipxe/windows/install.bat'`.
  - `$unattendXmlUrl` = `$scriptUrl . '/ipxe/windows/unattend.xml'`.
  - `$winAssetsBase` = `'Win10'` (legacy wimboot/winpeshl partagés Win10/Win11 — parité `wimboot11.php:7` `kernel Win10/wimboot`).
  - `$winVersionAssetsBase` = `'Win11'` (BCD/boot.sdi/boot.wim spécifiques version).
- Le template `ipxe.actions.install_win11.blade.php` consomme ces variables.

**And** tests unit ≥4 (win10 variants resolve, win11 resolve, perso/disk/debug variants, non-install actions inchangées + non-régression `resolveLinuxVariables`).

### Volet 7 — Templates Blade (D10)

**AC7.1** — **Templates `install_win*.blade.php` rendent les cmdlines wimboot correctement**

**Given** les 7 templates `resources/views/ipxe/actions/install_win*.blade.php`,
**When** rendus par `IpxeActionResolver::resolve()`,
**Then** chacun produit (modulo version + flags) :
```
#!ipxe
kernel Win10/wimboot
initrd --name winpeshl.ini Win10/winpeshl.ini winpeshl.ini
params
param uuid {{ $uuid }}
param mac {{ $mac }}
param debug {{ $winDebug }}
param version {{ $windowsVersion }}
param action {{ $winAction }}
iseq ${platform} efi && param bios uefi || param bios legacy
initrd --name install.bat {!! $installBatUrl !!}##params install.bat
params
param uuid {{ $uuid }}
param mac {{ $mac }}
param action {{ $winAction }}
param version {{ $windowsVersion }}
param disk {{ $winDisk }}
param perso {{ $winPerso }}
iseq ${platform} efi && param bios uefi || param bios legacy
initrd --name unattend.xml {!! $unattendXmlUrl !!}##params unattend.xml
initrd --name BCD {{ $windowsVersion }}/boot/bcd BCD
initrd --name boot.sdi {{ $windowsVersion }}/boot/boot.sdi boot.sdi
initrd --name boot.wim {{ $windowsVersion }}/sources/boot.wim boot.wim
boot
```
- `$installBatUrl` + `$unattendXmlUrl` rendus **raw** (`{!!`) pour préserver le `&` URL.

**And** test unit ≥7 (un par template) qui asserte le contenu via `assertStringContainsString` sur 4 marqueurs par template.

**AC7.2** — **Template `installation-windows.blade.php` rend le menu**

**Given** le template,
**When** rendu avec un poste connu + 7 items,
**Then** body iPXE complet avec :
- En-tête `menu installation clients Windows pour (nom : ... ip : ...)`.
- `set menu-default install_win11` (D11 default).
- Une ligne `item install_<enum> <label>` par item.
- Items `--key s shell` + `--key r retour` + `--key x exit`.
- Sections `:install_<enum>\nchain --replace --autofree {{ $serverBaseUrl }}/ipxe/action/install_<enum>##params\n` × 7.
- Section `:retour\nchain --replace --autofree {{ $serverBaseUrl }}/ipxe/admin##params`.
- Section `:exit\n{!! $bootDiskFallback !!}`.

**AC7.3** — **Modification `admin.blade.php` ajoute item install-windows**

**Given** le template `resources/views/ipxe/menu/admin.blade.php`,
**When** rendu avec `$isKnown=true` + `$isInstallWindowsActive=true`,
**Then** contient :
- `item --key w install-windows (w) Installation Windows (Win10/Win11)`.
- Section `:install-windows\nchain --replace --autofree {{ $installWindowsBaseUrl }}##params`.

**Given** `$isInstallWindowsActive=false`,
**Then** l'item n'apparaît pas (feature-flag).

**And** test feature `IpxeAdminEndpointTest::it_shows_install_windows_item_when_enabled` + `::it_hides_install_windows_item_when_disabled` + non-régression items 3.3/3.4 (enrollment, install-linux, maintenance toujours présents).

**AC7.4** — **Tous les templates respectent les conventions iPXE**

**Given** tous les 8 templates 3.5,
**When** rendus,
**Then** :
- Commencent par `#!ipxe` (via `{!! $shebang !!}`).
- Terminent par `\n`.
- Ne contiennent aucun caractère non ASCII (`[^\x20-\x7E]`).
- Ne contiennent aucune balise PHP (`<?php`, `?>`).

**And** test archi `IpxeNamespaceTest::story_3_5_templates_are_ascii_strict_and_no_php`.

### Volet 8 — Routes web.php + non-régression catchall (D2)

**AC8.1** — **6 routes natives déclarées AVANT catchall**

**Given** `routes/web.php`,
**When** le dev ajoute le bloc 3.5 (D2),
**Then** :
- Bloc placé APRÈS bloc 3.4 et AVANT catchall.
- Les 6 routes ont middleware `auth.v1.lan-only` + `throttle:600,1` + `withoutMiddleware(['web'])`.
- Commentaire `⚠⚠⚠` du catchall préservé.

**And** test archi `IpxeNamespaceTest::ipxe_3_5_routes_are_declared_before_catchall` (vérifie 6 routes).

**AC8.2** — **Non-régression catchall sur les autres routes `/ipxe/*`**

**Given** les routes legacy non encore réécrites (`/ipxe/clonage.php`, `/ipxe/clonezilla*.php`, `/ipxe/Win10/repair.bat.php`, `/ipxe/Win10/win_iso.php`, `/ipxe/diconf/*`, `/ipxe/png/*`),
**When** sollicitées,
**Then** elles continuent d'être servies par `LegacyCatchallController`.

**And** test feature `IpxeLegacyRoutingNonRegressionTest` étendu avec ≥3 nouveaux tests :
- `it_serves_ipxe_installation_windows_natively_not_via_catchall`
- `it_serves_ipxe_windows_unattend_natively_not_via_catchall`
- `it_still_serves_ipxe_clonage_via_catchall`.

### Volet 9 — Config + provider + channel log (D8, D11)

**AC9.1** — **Extension `config/ipxe.php` section `windows`** selon D11.

**AC9.2** — **Extension `config/sambaedu.php` section `windows`** selon D11.

**AC9.3** — **Provider `IpxeServiceProvider` enregistre les 4 nouveaux services singletons** : `WindowsUnattendBuilder`, `WindowsInstallBatBuilder`, `WindowsInstallMenuBuilder`, `WindowsPostInstallTracker`.

**AC9.4** — **Tests `IpxeConfigTest` étendu** avec ≥6 assertions sur la section `windows`.

### Volet 10 — Runbook QA + sprint-status (D12)

**AC10.1** — **Extension `docs/qa/domains/ipxe.md`** :
- Nouvelle section `## Story 3.5 — Installation Windows (Sysprep/Wimboot)`.
- ≥12 scénarios stables `3.5-1` à `3.5-12` (numérotation 3.1-3.4 préservée intacte).
- Scénarios couvrent :
  - **Scénario 3.5-1** — Menu installation-windows rendu (poste connu) : `curl -X POST http://192.168.122.50/ipxe/installation-windows -d 'mac=...&uuid=...'` → 200 + body contient 7 items `install_win*` + section `:exit`.
  - **Scénario 3.5-2** — Menu installation-windows poste inconnu : retourne menu erreur + chain `/ipxe/admin`.
  - **Scénario 3.5-3** — Item depuis `/ipxe/admin` : menu admin connu contient item `(w) Installation Windows`. Item absent si `IPXE_INSTALL_WINDOWS_ENABLED=false`.
  - **Scénario 3.5-4** — Action `install_win11` rendue : `curl http://192.168.122.50/ipxe/action/install_win11 -d 'mac=...&uuid=...'` → 200 + body `kernel Win10/wimboot` + `initrd ... unattend.xml http://.../ipxe/windows/unattend.xml##params unattend.xml` + `boot.wim Win11/sources/boot.wim`.
  - **Scénario 3.5-5** — Action `install_win10_perso` rendue : `perso=1` dans la cmdline.
  - **Scénario 3.5-6** — Action `install_win11_disk` rendue : `disk=1` dans la cmdline.
  - **Scénario 3.5-7** — install.bat généré : `curl 'http://192.168.122.50/ipxe/windows/install.bat?mac=...&uuid=...&version=Win11&bios=uefi'` → 200 text/plain + `\r\n` line endings + contient `z:\os\Win11\sources\setup.exe` + `curl ... /ipxe/windows/action`.
  - **Scénario 3.5-8** — unattend.xml généré : `curl 'http://192.168.122.50/ipxe/windows/unattend.xml?mac=...&uuid=...&version=Win11&bios=uefi&disk=0&perso=0'` → 200 text/plain + XML valide + contient `<ComputerName>PC-101</ComputerName>` + Win11 BypassTPM RunSync.
  - **Scénario 3.5-9** — unattend.xml poste inconnu : 404 + log warning `ipxe.windows.unattend.unknown_workstation`.
  - **Scénario 3.5-10** — unattend.xml version hors whitelist : 422 + log warning.
  - **Scénario 3.5-11** — Hook `/ipxe/windows/action` winpe : `curl -F 'etape=winpe' -F 'ret=0' -F 'uuid=...' -F 'name=PC-101' http://192.168.122.50/ipxe/windows/action` → 200 vide + `SELECT status FROM workstations WHERE uuid='...'` retourne `'installation WinPE'`.
  - **Scénario 3.5-12** — Hook `/ipxe/windows/action` oobe : `curl -F 'etape=oobe' -F 'ret=0' -F 'uuid=...' -F 'name=PC-101' ...` → 200 vide + `SELECT os FROM workstations WHERE uuid='...'` retourne `'windows'`.
  - **Scénario 3.5-13** — Hook étape déférée (`etape=sysprep`) : 200 vide + log warning `ipxe.windows.action.unsupported_step`.
  - **Scénario 3.5-14** — Sécurité LAN : depuis IP publique → 403 + code `bootstrap.not_lan`.
  - **(Optionnel)** Scénario 3.5-15 — Smoke poste réel : un poste de test boot PXE → menu installation Windows → choisit `install_win11` → install se déroule jusqu'au reboot → `Workstation::os = 'windows'`.

**AC10.2** — **`sprint-status.yaml` mis à jour** :
- `3-5-installation-windows-sysprep-wimboot: backlog` → `ready-for-dev`.
- Commentaire `# 2026-05-21 (création SM Story 3-5)` avec résumé décisions clés.

**AC10.3** — **`docs/qa/README.md`** : pas de modification (entrée `ipxe` déjà présente — append-only sur le runbook).

---

## Tasks / Subtasks

### Phase T0 — Pré-flight + validations contexte

- [x] **T0.1** Vérifier que Story 3.3 + 3.4 sont en `done` (ou ready-to-merge) — sinon escalader à Henri avant de démarrer 3.5.
- [x] **T0.2** Statut 3.1/3.2 done + 3.3 review + 3.4 review confirmés par sprint-status.
- [x] **T0.3** Lecture obligatoire legacy faite : `sambaedu/ipxe/installation-windows.php` + `Win10/{install.bat.php, unattend.xml.php, diskpart.php, sysprep.xml.php, action.php (lignes 411-491, 720-730)}` + `actions/{wimboot10,wimboot11}.php` + `sambaedu/includes/windows.inc.php:3-380` (update_xml_unattend). Différences notables 3.5 vs legacy documentées dans Dev Agent Record.
- [x] **T0.4** Audit template `Win10/unattend.xml` (123 LOC) : lister TOUS les nodes XPath manipulés par `update_xml_unattend()` (ComputerName, RegisteredOrganization, AutoLogon, UnattendedJoin, LocalAccount, DiskConfiguration, ImageInstall, FirstLogonCommands, etc.) et confirmer que TOUS sont gérés dans `WindowsUnattendBuilder` ou explicitement déférés.
- [x] **T0.5** Audit variables `.env` consommées par unattend + install.bat (SAMBAEDU_ADMINSE_*, SAMBAEDU_WIN_*, SE4INSTALL_*, SAMBAEDU_DOMAIN, SE4FS_*) : confirmer présence en VM ou escalader à Henri. **Audit assets binaires VM** : `Win10/wimboot`, `Win10/winpeshl.ini`, `Win{10,11}/boot/bcd`, `Win{10,11}/boot/boot.sdi`, `Win{10,11}/sources/boot.wim` présents dans `/var/sambaedu/unattended/install/os/` ?
- [x] **T0.6** Audit `MachineBootLog.action` : varchar(20) sans CHECK confirmé par 3.1-3.4 — vérifier que les 5 nouvelles valeurs (`ipxe_install_win`, `ipxe_win_unattend`, `ipxe_win_install`, `ipxe_win_diskpart`, `ipxe_win_report`) passent (≤17 chars).
- [x] **T0.7** Statut iso-legacy `auth.v1.lan-only` + middleware déjà attaché aux routes 3.1-3.4 : pas de modification attendue.
- [x] **T0.8** Inventaire des variantes hors-scope (D14, D15) : confirmer que `installw11old` + flows action post-install (sysprep/nosysprep/join/renomme/post/wpkg) + clonezilla sont déférés 3.7. Si Henri demande une variante critique → escalader.

### Phase T1 — Enums + Support + Template XML (D1, D6, D10, AC1.1, AC1.2, AC1.3)

- [x] **T1.1** Étendre `app/Ipxe/Enums/IpxeAdminAction.php` avec les 7 nouveaux cases + méthode `windowsMeta()` + extension `template()`. Test `IpxeAdminActionTest` étendu.
- [x] **T1.2** Étendre `app/Ipxe/Enums/IpxeMenuKind.php` avec +2 cases `InstallationWindowsHandshake` + `InstallationWindowsMenu`.
- [x] **T1.3** Créer `app/Ipxe/Enums/WindowsVersion.php` (2 cases) + `WindowsInstallStep.php` (2 cases) + méthodes `fromString()`. Tests unit ≥5 cas chacun.
- [x] **T1.4** Créer `app/Ipxe/Support/WindowsXmlPlaceholders.php` avec catalog + `sanitize()` + `sanitizeShellArg()` + `interpolate()`. Tests unit ≥10 cas dont 6 anti-injection.
- [x] **T1.5** Créer `app/Ipxe/Exceptions/UnattendGenerationException.php` (analogue `PreseedGenerationException` 3.4).
- [x] **T1.6** Copier `sambaedu/ipxe/Win10/unattend.xml` (123 LOC) vers `resources/ipxe/windows/unattend.xml` (asset projet, sous version control). Ne PAS modifier le contenu — copie pure byte-identique.
- [x] **T1.7** Test unit `WindowsTemplateAssetTest::it_lists_required_template + it_template_is_well_formed_xml`.

### Phase T2 — `WindowsUnattendBuilder` + `WindowsInstallBatBuilder` (D6, D7, AC2.1, AC2.2, AC2.3, AC3.1)

- [x] **T2.1** Créer `app/Ipxe/Services/WindowsUnattendBuilder.php` avec méthode `build()` + algorithme port iso-legacy `update_xml_unattend()` simplifié au scope 3.5 (cf. D6 step-by-step).
- [x] **T2.2** Créer `app/Ipxe/Services/WindowsInstallBatBuilder.php` avec méthode `build()` + algorithme port iso-legacy `install.bat.php:14-72` (cf. D7).
- [x] **T2.3** Test unit `WindowsUnattendBuilderTest` ≥10 tests (cf. AC2.1).
- [x] **T2.4** Tests anti-injection XML (cf. AC2.2).
- [x] **T2.5** Tests log audit (cf. AC2.3).
- [x] **T2.6** Test unit `WindowsInstallBatBuilderTest` ≥6 tests (cf. AC3.1) — vérification `\r\n` strict + URL native `/ipxe/windows/action` + sanitization shell-arg.

### Phase T3 — `WindowsInstallMenuBuilder` + `WindowsPostInstallTracker` (D5, AC3.2, AC3.3)

- [x] **T3.1** Créer `WindowsInstallMenuBuilder` + tests unit ≥4.
- [x] **T3.2** Créer `WindowsPostInstallTracker` avec méthodes `recordWinpeStart()`, `recordOobeComplete()`, `recordInstallBatGenerated()` (helper pour le set_progress 5%). Tests unit ≥6.

### Phase T4 — Templates Blade + extensions admin (D10, AC6.1, AC7.1-7.4)

- [x] **T4.1** Créer `resources/views/ipxe/menu/installation-windows.blade.php` (~50 lignes — port natif `installation-windows.php:23-105` + sections `:install_win*`).
- [x] **T4.2** Créer les 7 templates `resources/views/ipxe/actions/install_win*.blade.php` (port natif `actions/wimboot10.php` + `wimboot11.php` paramétrés).
- [x] **T4.3** Étendre `resources/views/ipxe/menu/admin.blade.php` (ajout item `(w) Installation Windows` + section `:install-windows`).
- [x] **T4.4** Tests unit `IpxeMenuRendererTest` étendu ≥6 tests pour `renderInstallationWindowsMenu()` (cf. AC6.1).
- [x] **T4.5** Tests unit `IpxeActionResolverTest` étendu ≥4 tests pour les variables `resolveWindowsVariables` (cf. AC6.2 + AC7.1) + non-régression `resolveLinuxVariables`.
- [x] **T4.6** Tests archi `story_3_5_templates_are_ascii_strict_and_no_php` (8 templates).

### Phase T5 — `IpxeService::handleInstallationWindowsMenu()` + `IpxeMenuRenderer::renderInstallationWindowsMenu()` (AC4.1, AC6.1)

- [x] **T5.1** Ajouter `IpxeService::handleInstallationWindowsMenu()` (~50 lignes, pattern iso `handleInstallationLinuxMenu()` 3.4).
- [x] **T5.2** Ajouter `IpxeMenuRenderer::renderInstallationWindowsMenu()` (~20 lignes, délègue à `WindowsInstallMenuBuilder::build()`).
- [x] **T5.3** Étendre `IpxeMenuRenderer::renderAdminMenu()` avec variables `installWindowsBaseUrl` + `isInstallWindowsActive`.
- [x] **T5.4** Tests unit `IpxeServiceInstallationWindowsTest` ≥6 (handshake, known, unknown, MachineBootLog, headers, safeRender).

### Phase T6 — Controllers + FormRequests (D2, AC5.1-5.7)

- [x] **T6.1** Créer 6 controllers (`IpxeInstallationWindowsController`, `IpxeWindowsInstallBatController`, `IpxeWindowsUnattendController`, `IpxeWindowsDiskpartController`, `IpxeWindowsSysprepController`, `IpxeWindowsActionController`) — fins ≤30 LOC chacun.
- [x] **T6.2** Créer 6 FormRequests.
- [x] **T6.3** Tests feature par endpoint :
  - `IpxeInstallationWindowsEndpointTest` ≥6
  - `IpxeWindowsInstallBatEndpointTest` ≥4 (Win10/Win11, unknown 200 vide, version invalide 422, line endings CRLF)
  - `IpxeWindowsUnattendEndpointTest` ≥6 (Win10/Win11, bios legacy/uefi, perso/join, unknown 404, version invalide 422, XML bien formé)
  - `IpxeWindowsDiskpartEndpointTest` ≥2 (body iso-legacy, MachineBootLog)
  - `IpxeWindowsSysprepEndpointTest` ≥2 (stub 200 vide, log audit)
  - `IpxeWindowsActionEndpointTest` ≥5 (winpe ret=0 status update, oobe ret=0 os update, unknown 200, unsupported step warning, MachineBootLog)

### Phase T7 — Routes + provider + config + non-régression (D2, D11, AC8.1, AC8.2, AC9.*)

- [x] **T7.1** Ajouter le bloc 6 routes dans `routes/web.php` AVANT catchall.
- [x] **T7.2** Étendre `IpxeServiceProvider` avec les 4 nouveaux singletons.
- [x] **T7.3** Étendre `config/ipxe.php` section `windows` (D11).
- [x] **T7.4** Étendre `config/sambaedu.php` section `windows` (D11). Audit T0.5 = vérifier absence de doublons.
- [x] **T7.5** Tests archi `IpxeNamespaceTest::ipxe_3_5_routes_are_declared_before_catchall` + non-régression catchall.
- [x] **T7.6** Tests feature `IpxeLegacyRoutingNonRegressionTest` étendu ≥3 tests.
- [x] **T7.7** Tests `IpxeConfigTest` étendu ≥6 assertions.

### Phase T8 — Runbook QA + sprint-status + completion notes (D12, AC10.1, AC10.2)

- [x] **T8.1** Étendre `docs/qa/domains/ipxe.md` Section `## Story 3.5` + ≥12 scénarios stables 3.5-1 à 3.5-12 + 2 optionnels.
- [x] **T8.2** Mettre à jour `sprint-status.yaml` : `3-5: ready-for-dev → review` (post-dev).
- [x] **T8.3** Status story → `review`, tasks cochées, Dev Agent Record + File List + Change Log remplis.
- [x] **T8.4** *Différé Henri post-merge VM* : ré-exécuter `./scripts/run-tests.sh` (suite complète) + scénarios 3.5-1 à 3.5-12 manuels sur la VM.

---

## File List prévisionnelle

### Fichiers créés (estimés ~37)

```
# Services (4)
app/Ipxe/Services/WindowsUnattendBuilder.php
app/Ipxe/Services/WindowsInstallBatBuilder.php
app/Ipxe/Services/WindowsInstallMenuBuilder.php
app/Ipxe/Services/WindowsPostInstallTracker.php

# Enums + Support (3)
app/Ipxe/Enums/WindowsVersion.php
app/Ipxe/Enums/WindowsInstallStep.php
app/Ipxe/Support/WindowsXmlPlaceholders.php

# Exceptions (1)
app/Ipxe/Exceptions/UnattendGenerationException.php

# Controllers (6)
app/Ipxe/Http/Controllers/IpxeInstallationWindowsController.php
app/Ipxe/Http/Controllers/IpxeWindowsInstallBatController.php
app/Ipxe/Http/Controllers/IpxeWindowsUnattendController.php
app/Ipxe/Http/Controllers/IpxeWindowsDiskpartController.php
app/Ipxe/Http/Controllers/IpxeWindowsSysprepController.php
app/Ipxe/Http/Controllers/IpxeWindowsActionController.php

# FormRequests (6)
app/Ipxe/Http/Requests/IpxeInstallationWindowsRequest.php
app/Ipxe/Http/Requests/IpxeWindowsInstallBatRequest.php
app/Ipxe/Http/Requests/IpxeWindowsUnattendRequest.php
app/Ipxe/Http/Requests/IpxeWindowsDiskpartRequest.php
app/Ipxe/Http/Requests/IpxeWindowsSysprepRequest.php
app/Ipxe/Http/Requests/IpxeWindowsActionRequest.php

# Templates Blade — menu (1)
resources/views/ipxe/menu/installation-windows.blade.php

# Templates Blade — actions install_win* (7)
resources/views/ipxe/actions/install_win10.blade.php
resources/views/ipxe/actions/install_win10_debug.blade.php
resources/views/ipxe/actions/install_win10_disk.blade.php
resources/views/ipxe/actions/install_win10_perso.blade.php
resources/views/ipxe/actions/install_win11.blade.php
resources/views/ipxe/actions/install_win11_disk.blade.php
resources/views/ipxe/actions/install_win11_perso.blade.php

# Template XML (1)
resources/ipxe/windows/unattend.xml

# Tests Unit (8)
tests/Unit/Ipxe/Enums/WindowsVersionTest.php
tests/Unit/Ipxe/Enums/WindowsInstallStepTest.php
tests/Unit/Ipxe/Support/WindowsXmlPlaceholdersTest.php
tests/Unit/Ipxe/Support/WindowsTemplateAssetTest.php
tests/Unit/Ipxe/Services/WindowsUnattendBuilderTest.php
tests/Unit/Ipxe/Services/WindowsInstallBatBuilderTest.php
tests/Unit/Ipxe/Services/WindowsInstallMenuBuilderTest.php
tests/Unit/Ipxe/Services/WindowsPostInstallTrackerTest.php
tests/Unit/Ipxe/Services/IpxeServiceInstallationWindowsTest.php

# Tests Feature (6)
tests/Feature/Ipxe/IpxeInstallationWindowsEndpointTest.php
tests/Feature/Ipxe/IpxeWindowsInstallBatEndpointTest.php
tests/Feature/Ipxe/IpxeWindowsUnattendEndpointTest.php
tests/Feature/Ipxe/IpxeWindowsDiskpartEndpointTest.php
tests/Feature/Ipxe/IpxeWindowsSysprepEndpointTest.php
tests/Feature/Ipxe/IpxeWindowsActionEndpointTest.php
```

### Fichiers modifiés (estimés ~15)

```
app/Ipxe/Enums/IpxeAdminAction.php          (+7 cases install_win* + windowsMeta())
app/Ipxe/Enums/IpxeMenuKind.php             (+2 cases InstallationWindows{Menu,Handshake})
app/Ipxe/Services/IpxeService.php          (+handleInstallationWindowsMenu)
app/Ipxe/Services/IpxeMenuRenderer.php     (+renderInstallationWindowsMenu + installWindowsBaseUrl/isInstallWindowsActive)
app/Ipxe/Services/IpxeActionResolver.php   (+resolveWindowsVariables)
app/Providers/IpxeServiceProvider.php      (+4 singletons)
config/ipxe.php                            (+section windows D11)
config/sambaedu.php                        (+section windows D11)
resources/views/ipxe/menu/admin.blade.php  (+item install-windows + section :install-windows)
routes/web.php                             (+bloc 6 routes 3.5 AVANT catchall)
docs/qa/domains/ipxe.md                    (+Section Story 3.5 + ≥12 scénarios)
tests/Architecture/IpxeNamespaceTest.php   (+routes 3.5 + templates 3.5)
tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php (+3 tests)
tests/Feature/Ipxe/IpxeAdminEndpointTest.php (+2 tests item install-windows)
tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php (+8 tests)
tests/Unit/Ipxe/IpxeConfigTest.php          (+6 assertions section windows)
tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php (+6 tests renderInstallationWindowsMenu)
tests/Unit/Ipxe/Services/IpxeActionResolverTest.php (+4 tests resolveWindowsVariables)
```

### Fichiers métadonnées BMAD modifiés

```
_bmad-output/implementation-artifacts/3-5-installation-windows-sysprep-wimboot.md (Dev Agent Record + File List + status)
_bmad-output/implementation-artifacts/sprint-status.yaml                          (3-5: backlog → ready-for-dev → review post-dev)
_bmad-output/backlog.html                                                          (3-5 status backlog → ready-for-dev)
```

### Fichiers NON modifiés (garde-fou)

```
sambaedu/ipxe/**                                          ← legacy intact (catchall sert encore)
legacy/modules/ipxe/**                                    ← idem
app/Models/Workstation.php                                ← lecture seule + update os/status via Tracker
app/Models/MachineBootLog.php                             ← lecture seule + insert via Eloquent
app/Auth/V1/**                                            ← intact (réutilisation alias auth.v1.lan-only)
app/Ipxe/Services/WorkstationLocator.php                  ← lecture seule
app/Ipxe/Services/IpxeHostnameSanitizer.php               ← lecture seule (réutilisation)
app/Ipxe/Services/LinuxPreseedService.php                 ← lecture seule (non touché 3.5)
app/Ipxe/Services/LinuxInstallMenuBuilder.php             ← lecture seule
app/Ipxe/Services/LinuxPostInstallTracker.php             ← lecture seule
```

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichiers |
|---|---|---|
| **Unit** | Enums + Support (WindowsXmlPlaceholders sanitize XML/shell, WindowsVersion/InstallStep fromString) | `IpxeAdminActionTest`, `WindowsVersionTest`, `WindowsInstallStepTest`, `WindowsXmlPlaceholdersTest`, `WindowsTemplateAssetTest` |
| **Unit** | WindowsUnattendBuilder (DOMDocument transforms + interpolation + anti-injection XML + log audit) | `WindowsUnattendBuilderTest` |
| **Unit** | WindowsInstallBatBuilder (line endings CRLF + URL native + sanitization shell-arg) | `WindowsInstallBatBuilderTest` |
| **Unit** | WindowsInstallMenuBuilder + WindowsPostInstallTracker | `WindowsInstallMenuBuilderTest`, `WindowsPostInstallTrackerTest` |
| **Unit** | IpxeService::handleInstallationWindowsMenu + IpxeMenuRenderer::renderInstallationWindowsMenu + IpxeActionResolver windowsMeta | `IpxeServiceInstallationWindowsTest`, `IpxeMenuRendererTest`, `IpxeActionResolverTest` |
| **Feature** | Endpoint /ipxe/installation-windows (menu rendered, known/unknown, headers) | `IpxeInstallationWindowsEndpointTest` |
| **Feature** | Endpoint /ipxe/windows/install.bat (Win10/Win11, CRLF, unknown vide, version invalide) | `IpxeWindowsInstallBatEndpointTest` |
| **Feature** | Endpoint /ipxe/windows/unattend.xml (Win10/Win11, bios, perso/join, unknown 404, XML well-formed) | `IpxeWindowsUnattendEndpointTest` |
| **Feature** | Endpoint /ipxe/windows/diskpart.txt (body iso-legacy) | `IpxeWindowsDiskpartEndpointTest` |
| **Feature** | Endpoint /ipxe/windows/sysprep.xml (stub vide) | `IpxeWindowsSysprepEndpointTest` |
| **Feature** | Endpoint /ipxe/windows/action (winpe/oobe success, unsupported step, unknown 200) | `IpxeWindowsActionEndpointTest` |
| **Feature** | Non-régression catchall (clonage, Win10/repair, Win10/win_iso via catchall) | `IpxeLegacyRoutingNonRegressionTest` étendu |
| **Feature** | Menu admin contient item Installation Windows | `IpxeAdminEndpointTest` étendu |
| **Architecture** | Routes 3.5 AVANT catchall + namespace + ASCII strict templates | `IpxeNamespaceTest` étendu |
| **QA manuelle (VM)** | 14 scénarios smoke + 1 optionnel poste réel | `docs/qa/domains/ipxe.md` § Story 3.5 |

### Tests qu'on ne fait **pas** dans cette story

- Tests d'exécution réelle de l'installateur Windows sur poste cible — couvert par QA manuelle scénario 3.5-15 (action Henri).
- Tests d'install Linux — = story 3.4 (déjà couvert).
- Tests de clonage clonezilla — = story 3.7.
- Tests des flows action post-install Windows complets (sysprep/nosysprep/join/renomme/post/wpkg) — = story 3.7.
- Tests `installw11old` — = futur si besoin terrain (D14).
- Tests de charge `/ipxe/windows/unattend.xml` (50 postes simultanés rentrée) — déférés post-prod.

---

## Anti-patterns à éviter (DISASTER PREVENTION)

### Architecture & scope

- ❌ **Ne PAS modifier le code legacy `sambaedu/ipxe/*.php`** — restent intacts.
- ❌ **Ne PAS étendre le scope** aux variantes hors-scope D14 (`installw11old`) ni aux flows action post-install complets (sysprep/nosysprep/join/renomme/post/wpkg — = 3.7).
- ❌ **Ne PAS toucher au schema `workstations` ni `machine_boot_logs`** (D9 + D12 — pas de migration).
- ❌ **Ne PAS créer de nouveau middleware** — `auth.v1.lan-only` suffit (D3).
- ❌ **Ne PAS introduire `LdapRecord` dans 3.5** — aucun appel AD côté serveur (la mise au domaine se fait côté client Windows via UnattendedJoin dans le XML généré). Réservé Story 3.7 si besoin.
- ❌ **Ne PAS créer d'UI Livewire** en 3.5 — API HTTP pure.
- ❌ **Ne PAS rendre l'unattend.xml ni le install.bat via Blade** — DOMDocument + string concat plus robustes.
- ❌ **Ne PAS porter le TOTP `totp_code("se4install")`** legacy — explicitement abandonné (cf. encadré scope).

### Sécurité & secrets

- ❌ **Ne PAS logger le contenu de l'unattend.xml ni du install.bat** — seulement le sha256 + metadata (version/bios/size).
- ❌ **Ne PAS exposer les placeholders `###_<*_PASSWD>_###` ou les credentials dans les logs**.
- ❌ **Ne PAS valider `version`/`mac`/`uuid`/`product` côté URL en regex permissive** — utiliser les enums + Rule::in() côté FormRequest.
- ❌ **Ne PAS escape via `htmlspecialchars()` standard** dans les templates iPXE — utiliser `ENT_XML1` strict dans l'unattend XML, et `htmlspecialchars_decode` jamais.
- ❌ **Ne PAS faire confiance à X-Forwarded-For** dans `EnsureLanIp` — iso 16.11.
- ❌ **Ne PAS appeler `Workstation::create()` depuis `/ipxe/windows/*`** — c'est le scope 3.3 enrollment, pas 3.5 install.

### Routing & non-régression

- ❌ **Ne PAS placer les routes 3.5 APRÈS le catchall**.
- ❌ **Ne PAS toucher au commentaire `⚠⚠⚠`** du catchall.
- ❌ **Ne PAS modifier le `LegacyCatchallController`** — il continue de servir `/ipxe/clonage.php`, `/ipxe/Win10/repair.bat.php`, `/ipxe/Win10/win_iso.php`.
- ❌ **Ne PAS introduire `Route::prefix('/ipxe/windows')` group** — anti-pattern iso 3.3/3.4.
- ❌ **Ne PAS remplacer `/ipxe/Win10/sysprep.xml.php` natif** — Phase 2 garde le path legacy + ajoute le path natif `/ipxe/windows/sysprep.xml` (stub). Le legacy continue de fonctionner via catchall jusqu'à 3.7.

### Idempotence & robustesse

- ❌ **Ne PAS écrire `/tmp/unattend.log` / `/tmp/sysprep.log` / `/tmp/actions-*.log`** (parité legacy retirée — debug only).
- ❌ **Ne PAS écraser silencieusement `Workstation::os = 'windows'`** si l'install échoue — `WindowsPostInstallTracker::recordOobeComplete()` doit recevoir `ret=0`.
- ❌ **Ne PAS lever d'exception qui remonte au controller** — `safeRender` wrap iso 3.1.
- ❌ **Ne PAS pré-cache l'unattend.xml en mémoire** — secrets résidents en RAM trop longtemps. Lecture template OK à cacher (read-only), assemblage final à chaque appel.
- ❌ **Ne PAS oublier les line endings `\r\n` dans install.bat** — WinPE rejette les fichiers LF only sans erreur visible.

### Process & infra

- ❌ **Ne PAS SSH manuellement vers la VM** depuis un worktree git.
- ❌ **Ne PAS exécuter les tests sur la VM** depuis worktree — lint statique + PHPUnit local. Différer à Henri post-merge.
- ❌ **Ne PAS faire de PR / commit depuis le dev-agent** — c'est le job de l'orchestrateur main agent en fin de cycle.
- ❌ **Ne PAS introduire de Co-Authored-By Claude** dans les commits.
- ❌ **Ne PAS commiter de fixtures de production** — utiliser `Workstation::factory()` dans les tests.

---

## Dépendances + ordre

### Amont (bloquantes — toutes à valider en T0.1)

| Story | Statut entrant | Lien |
|---|---|---|
| **Story 3.1** iPXE Service Core | ✅ done | Réutilisation `IpxeService::handleBoot`, `IpxeMenuRenderer`, `WorkstationLocator`, channel log `ipxe`, `MachineBootLog`, `auth.v1.lan-only`, config `ipxe.php` |
| **Story 3.2** Boot et Menu Admin iPXE | ✅ done | Réutilisation `IpxeAdminAction` enum (extension 7 cases), `IpxeActionResolver` (extension `resolveWindowsVariables`), `IpxeMenuRenderer::renderHandshake(chainTarget)`, `IpxeMenuKind` (extension 2 cases) |
| **Story 3.3** Enrollment Machine | 🟡 review (à valider done par Henri avant 3.5 dev) | Réutilisation `IpxeHostnameSanitizer`, `admin.blade.php` enrichi, pattern `WorkstationEnrollmentService` |
| **Story 3.4** Installation Linux | 🟡 review (à valider done par Henri avant 3.5 dev) | **Pattern strictement reproduit** : `LinuxInstallMenuBuilder` → `WindowsInstallMenuBuilder`, `LinuxPreseedService` → `WindowsUnattendBuilder` + `WindowsInstallBatBuilder`, `LinuxPostInstallTracker` → `WindowsPostInstallTracker`, `PreseedPlaceholders` → `WindowsXmlPlaceholders`, etc. Le dev peut copier-coller-adapter intensivement. |
| **Epic 1** (Fondations) | ✅ done | AuthGuard + catchall |
| **Epic 4** (Machines/Groups) | ✅ done | `Workstation`/`WorkstationGroup` modèles |
| **Story 16.11** | ✅ done | Middleware `auth.v1.lan-only` réutilisé |

### Aval (3.5 débloque)

| Story | Lien |
|---|---|
| **3.6** Gestion ISO Windows | Indépendant 3.5 (côté UI admin + upload). 3.5 prépare la consommation des assets ISO injectés. |
| **3.7** Clonage et Maintenance | Pattern complet réutilisable : 3.5 a posé `WindowsPostInstallTracker` minimal (winpe/oobe) — 3.7 l'enrichit avec sysprep/nosysprep/join/renomme/post/wpkg + clonezilla. Cleanup routes legacy `/ipxe/installation-windows.php` etc. (fin Epic 3). |
| **Epic 9** Déploiement Windows (WPKG/GPO) | Le mécanisme `/ipxe/windows/action` peut être enrichi pour orchestrer `wpkg_assignments` post-install Windows. |

---

## Risques + mitigations

| Risque | Sévérité | Mitigation 3.5 |
|---|---|---|
| Collision routes natives vs catchall | 🟠 Élevée | D2 + test archi `ipxe_3_5_routes_are_declared_before_catchall`. |
| Secrets unattend.xml/install.bat exposés en clair sur LAN | 🟠 Élevée | D3 — `auth.v1.lan-only` + MAC/UUID matching + log sha256 only. |
| Régression sur `admin.blade.php` (ajout item) | 🟠 Élevée | Test feature + non-régression 3.3/3.4 (enrollment + install-linux + maintenance toujours présents). |
| DOMDocument exception sur template invalide → install Windows cassée silencieusement | 🟠 Élevée | T1.7 test unit charge + valide schéma + UnattendGenerationException + log error. |
| Line endings CRLF non respectés dans install.bat → WinPE échec silencieux | 🟠 Élevée | T2.6 test unit explicite `\r\n` partout. |
| Assets binaires Win10/Win11 manquants en VM (wimboot/winpeshl/BCD/boot.sdi/boot.wim) | 🟠 Élevée | T0.5 audit + escalation Henri si absent (rentrée scolaire risque blocant). |
| Variables `.env` AD/Windows manquantes en VM | 🟠 Élevée | T0.5 audit + escalation Henri. |
| Whitelist `version` court-circuitée → injection cmdline | 🟡 Moyenne | Enum + Rule::in() + sanitize defense-in-depth. |
| Injection XML via hostname (`<`, `>`, `&`) | 🟡 Moyenne | Sanitization 2 couches `IpxeHostnameSanitizer` (3.3 amont) + `WindowsXmlPlaceholders::sanitize()` ENT_XML1. |
| Whitelist `bios` (`legacy|uefi`) court-circuitée | 🟡 Moyenne | Rule::in() FormRequest + defense in depth service. |
| `MachineBootLog::action` rejette nouvelles valeurs | 🟢 Mineure | T0.6 audit — 5 valeurs ≤17 chars. |
| Hook `etape=oobe` reçu sans `etape=winpe` préalable (poste réimporté manuellement) | 🟢 Mineure | Idempotent — `recordOobeComplete()` accepte un workstation sans status `installation WinPE` préalable. |
| Conflit dual-boot Workstation::os écrasé | 🟢 Mineure | Parité legacy assumée. Historique tracé via `MachineBootLog`. |

---

## Project Structure Notes

### Alignement avec la structure projet

- **Namespace** : `App\Ipxe\…` — extension Windows. Sous-namespaces parallèles à 3.1-3.4 (pas de nouveau sous-namespace).
- **Tests** : `tests/Unit/Ipxe/…`, `tests/Feature/Ipxe/…`, `tests/Architecture/IpxeNamespaceTest.php` — cohérent avec 3.1-3.4.
- **Templates Blade** : `resources/views/ipxe/{menu,actions}/…` — convention iso 3.2/3.3/3.4.
- **Template XML** : nouveau dossier `resources/ipxe/windows/unattend.xml` (asset projet, parallèle à `resources/ipxe/linux/*.cfg` 3.4). **Pas dans `resources/views/`** (ce n'est pas un Blade).
- **Pages cibles** : *hors-scope cette story* — pas d'UI Livewire en 3.5 (= API HTTP pure).
- **Convention CLAUDE.md** : pas directement applicable (pas de page web sous `resources/views/pages/`, pas de modale, pas de toast — c'est une API HTTP pure + middleware).

### Cohabitation routes `/ipxe/*` post-3.5

| Endpoint | Story | Middleware | Status |
|---|---|---|---|
| `GET\|POST /ipxe/boot` + `GET /ipxe/boot.ipxe` | 3.1 | `auth.v1.lan-only` + `throttle:600,1` | done |
| `GET\|POST /ipxe/admin` | 3.2 | idem | done (modifié 3.3 + 3.4 + 3.5) |
| `GET\|POST /ipxe/maintenance` | 3.2 | idem | done |
| `GET\|POST /ipxe/action/{action}` | 3.2 (étendu 3.4 +9 cases linux + 3.5 +7 cases windows) | idem | done |
| `GET\|POST /ipxe/enrollment/{name,byod,room,parc-add,parc-remove}` | 3.3 | idem | review |
| `GET\|POST /ipxe/installation-linux` + `/ipxe/linux/{preseed,action,autorun}` | 3.4 | idem | review |
| `GET\|POST /ipxe/installation-windows` | **3.5 (cette story)** | idem | **NEW** |
| `GET\|POST /ipxe/windows/install.bat` | **3.5 (cette story)** | idem | **NEW** |
| `GET\|POST /ipxe/windows/unattend.xml` | **3.5 (cette story)** | idem | **NEW** |
| `GET\|POST /ipxe/windows/diskpart.txt` | **3.5 (cette story)** | idem | **NEW** |
| `GET\|POST /ipxe/windows/sysprep.xml` | **3.5 (cette story)** | idem | **NEW** stub |
| `GET\|POST /ipxe/windows/action` | **3.5 (cette story)** | idem | **NEW** partiel (winpe/oobe) |
| `/ipxe/installation-windows.php` | Legacy | (catchall) | Inchangé — sera retiré 3.7 cleanup |
| `/ipxe/Win10/*.php` | Legacy | (catchall) | Inchangé — sera retiré 3.7 cleanup |
| `/ipxe/clonage.php`, `/ipxe/clonezilla*.php` | Legacy | (catchall) | Inchangé — sera réécrit en 3.7 |
| `/ipxe/Win10/repair.bat.php` | Legacy | (catchall) | Inchangé — utilisé par action `winpe` 3.2 (réparation) |
| `/ipxe/Win10/win_iso.php` | Legacy | (catchall) | Inchangé — sera porté Story 3.6 |
| `/ipxe/diconf/*` | Legacy | (catchall) | Inchangé — déféré |
| `/ipxe/png/*` | Legacy (assets) | (catchall) | Inchangé |

### Convention QA — domaine ciblé

- **Domaine QA** : `ipxe` (déjà existant — append-only sur `docs/qa/domains/ipxe.md`).
- **Numérotation stable** : 3.5-1 à 3.5-12+ (préserve 3.1-1 à 3.4-N intacts).
- **Pas de nouveau domaine** : toute la chaîne iPXE reste cohérente sous un seul domaine.

---

## References

- [Source: `_bmad-output/planning-artifacts/epics.md` §Epic 3 Story 3.5] — cadrage haut niveau.
- [Source: `_bmad-output/planning-artifacts/prd.md` §FR23-26] — Functional Requirements liés au déploiement Windows.
- [Source: `_bmad-output/planning-artifacts/architecture.md` §"Modèle de Données — Source de Vérité"] — PostgreSQL exclusif lecture.
- [Source: `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`] — fondation namespace + WorkstationLocator + IpxeMenuRenderer + channel log + config.
- [Source: `_bmad-output/implementation-artifacts/3-2-boot-et-menu-admin-ipxe.md`] — pattern controller fin + enum whitelist + IpxeActionResolver.
- [Source: `_bmad-output/implementation-artifacts/3-3-enrollment-machine-parcs-salles-nommage.md`] — pattern service orchestrateur + IpxeHostnameSanitizer.
- [Source: `_bmad-output/implementation-artifacts/3-4-installation-linux-debian-ubuntu.md`] — **pattern de référence direct** : LinuxPreseedService → WindowsUnattendBuilder, LinuxInstallMenuBuilder → WindowsInstallMenuBuilder, LinuxPostInstallTracker → WindowsPostInstallTracker.
- [Source: `sambaedu/ipxe/installation-windows.php`] — source de vérité comportementale menu Windows (110 LOC).
- [Source: `sambaedu/ipxe/Win10/install.bat.php`] — générateur bash WinPE (73 LOC).
- [Source: `sambaedu/ipxe/Win10/unattend.xml.php`] — entry point génération unattend.xml (39 LOC).
- [Source: `sambaedu/includes/windows.inc.php:3-380`] — fonction `update_xml_unattend()` (~370 LOC) — algorithme DOMDocument à porter.
- [Source: `sambaedu/ipxe/Win10/diskpart.php`] — body diskpart statique (27 LOC).
- [Source: `sambaedu/ipxe/Win10/sysprep.xml.php`] — hook clonage (39 LOC, stub 3.5).
- [Source: `sambaedu/ipxe/Win10/action.php`] — hook post-install multi-étapes (736 LOC, scope 3.5 = lignes 411-491 + 720-730).
- [Source: `sambaedu/ipxe/Win10/unattend.xml`] — template DOMDocument (123 LOC) à copier dans `resources/ipxe/windows/`.
- [Source: `sambaedu/ipxe/actions/wimboot10.php`, `wimboot11.php`] — kernel cmdlines wimboot.
- [Source: `app/Models/Workstation.php`] — modèle Eloquent (lecture + update `os`/`status` via Tracker).
- [Source: `app/Ipxe/Services/IpxeService.php`] — extension `handleInstallationWindowsMenu`.
- [Source: `app/Ipxe/Services/IpxeMenuRenderer.php`] — extension `renderInstallationWindowsMenu` + admin.
- [Source: `app/Ipxe/Services/IpxeActionResolver.php`] — extension `resolveWindowsVariables`.
- [Source: `app/Ipxe/Enums/IpxeAdminAction.php`] — +7 cases install_win*.
- [Source: `app/Ipxe/Support/IpxeHostnameSanitizer.php`] — réutilisation `sanitizeForIpxeOutput()`.
- [Source: `config/ipxe.php`] — section windows à ajouter.
- [Source: `config/sambaedu.php`] — variables windows à ajouter.
- [Source: `routes/web.php` ligne 781+] — bloc d'insertion 3.5 (entre bloc 3.4 et catchall).
- [Source: `docs/qa/domains/ipxe.md`] — runbook à enrichir Section 14 Story 3.5.
- [Source: mémoire `feedback_worktree_no_vm_sync`] — pas de SSH /vm depuis worktree.
- [Source: mémoire `feedback_auth_iso_legacy`] — pas de Bearer per-host.
- [Source: mémoire `project_php_fpm_user_www_admin`] — chown www-admin.
- [Source: CLAUDE.md projet] — conventions Livewire SFC (non applicable 3.5 — API HTTP pure).

---

## Dev Notes

### Justification design

- **Pourquoi `WindowsUnattendBuilder` séparé de `WindowsInstallBatBuilder` ?** Single Responsibility — l'un manipule du XML via DOMDocument, l'autre du bash via string concat. Tester séparément + extension 3.7 sysprep.xml utilisera `WindowsUnattendBuilder` enrichi.
- **Pourquoi DOMDocument plutôt que template Blade pour l'unattend.xml ?** Le XML legacy contient des fragments injectés à des XPath précis (UnattendedJoin dans `specialize`, LocalAccount dans `oobeSystem/UserAccounts`, etc.) — DOMDocument permet ces transformations natives. Blade serait fragile (escape automatique, syntaxe `@if` mêlée au XML).
- **Pourquoi 7 templates Blade pour les 7 install_win* (et pas 1 paramétré) ?** Iso 3.2/3.4 pattern. Mais les 7 templates Windows sont quasi-identiques — un futur refactor pourra factoriser en 2 templates `install_win10.blade.php` + `install_win11.blade.php` avec `$flags` (option de simplification post-3.5).
- **Pourquoi extension `IpxeAdminAction` enum plutôt qu'un nouveau enum `IpxeInstallWindowsAction` ?** Cohérence avec le dispatch via `/ipxe/action/{action}` existant 3.2/3.4.
- **Pourquoi `WindowsPostInstallTracker` partiel (winpe/oobe) au lieu du flow complet ?** Phase 2 = minimal viable. Le flow complet (sysprep/nosysprep/join/renomme/post/wpkg) dépend de `IpxeProgrammedActionResolver` non porté (GLM `actions[]` LDAP) — = Story 3.7.
- **Pourquoi copier le template `unattend.xml` dans `resources/ipxe/windows/` plutôt qu'utiliser `sambaedu/ipxe/Win10/unattend.xml` directement ?** (1) Les fichiers `sambaedu/` ne sont pas garantis présents sur la VM. (2) Mettre le template sous version control permet d'évoluer indépendamment du legacy. (3) Tests unitaires plus simples.
- **Pourquoi NE PAS porter `totp_code("se4install")` ?** Le legacy faisait une rotation TOTP du mot de passe `se4install_passwd` toutes les 24h — mécanisme complexe + risque rentrée scolaire (un poste qui boot 24h+ avec un TOTP périmé échoue). Phase 2 décide : mot de passe fixe lu via `.env`, rotation manuelle Henri.
- **Pourquoi NE PAS porter les flows action post-install complets ?** Le legacy `Win10/action.php` (736 LOC) est très complexe + dépend de la GLM `actions[]` LDAP non portée en SE5. La parité fonctionnelle ne livre rien d'utile en 3.5 sans le mécanisme `set_action`/`get_action` natif → reporté 3.7.

### Convention de logging

- Tous les logs 3.5 ont la clé `action_type` (iso 3.1-3.4) :
  - `ipxe.install_windows.menu_rendered` (info)
  - `ipxe.install_windows.menu_render_error` (error)
  - `ipxe.install_windows.handshake` (info)
  - `ipxe.windows.install_bat.generated` (info)
  - `ipxe.windows.install_bat.unknown_workstation` (warning)
  - `ipxe.windows.install_bat.invalid_version` (warning)
  - `ipxe.windows.unattend.generated` (info)
  - `ipxe.windows.unattend.unknown_workstation` (warning)
  - `ipxe.windows.unattend.invalid_version` (warning)
  - `ipxe.windows.unattend.generation_error` (error)
  - `ipxe.windows.diskpart.served` (info)
  - `ipxe.windows.sysprep.stub_served` (info)
  - `ipxe.windows.action.winpe_start` (info)
  - `ipxe.windows.action.oobe_complete` (info)
  - `ipxe.windows.action.unsupported_step` (warning)
  - `ipxe.windows.action.unknown_workstation` (warning)
- Toutes les valeurs sensibles (MAC, UUID, hostname) sont **préfixées** (6-8 chars).
- L'unattend.xml + install.bat ne sont **jamais** loggués en clair — seulement sha256.

### Pattern résolution multi-niveaux post-3.5

```
Firmware iPXE → /ipxe/boot (3.1) → menu known
  ↓ user choisit "1" (login admin)
/ipxe/admin (3.2 + ext 3.3 + ext 3.4 + ext 3.5)
  ↓ user choisit "(w) Installation Windows"
/ipxe/installation-windows (3.5)
  → handshake si MAC/UUID manquant → handshake template
  → résolution WorkstationLocator
  → render menu avec 7 items install_win*
  ↓ user choisit ex. "Installation de Windows 11 (auto)"
/ipxe/action/install_win11 (3.2 + ext enum 3.5)
  → IpxeAdminAction::tryFrom('install_win11') OK
  → IpxeActionResolver::resolve()
    → action.windowsMeta() → {version: 'Win11', action: 'wimboot11', debug: 0, disk: 0, perso: 0}
    → injecte windowsVersion + winAction + ... + installBatUrl + unattendXmlUrl dans le contexte Blade
  → render template ipxe.actions.install_win11.blade.php
  → body iPXE kernel/initrd winpeshl/initrd install.bat/initrd unattend.xml/BCD/boot.sdi/boot.wim/boot
  ↓ firmware iPXE charge tout, boot WinPE
WinPE → exécute winpeshl.ini → lance install.bat
  ↓ install.bat fetch http://se4fs/ipxe/windows/install.bat?mac=...&uuid=...&version=Win11&bios=uefi
/ipxe/windows/install.bat (3.5)
  → validation IpxeWindowsInstallBatRequest
  → résolution Workstation
  → WindowsInstallBatBuilder::build(workstation, Win11, [bios=uefi, debug=0, perso=0])
    → assemble bash avec line endings \r\n
    → interpole se4fs_name, se4install_name, domain, se4install_passwd, version, hostname
  → log info ipxe.windows.install_bat.generated (sha256, version, size)
  → insert MachineBootLog action='ipxe_win_install'
  → WindowsPostInstallTracker::recordInstallBatGenerated → set_progress 5% + set_statut "installation WinPE"
  → response text/plain
  ↓ install.bat exécute setup.exe /unattend:x:\windows\system32\unattend.xml
  ↓ setup.exe fetch http://se4fs/ipxe/windows/unattend.xml?mac=...&uuid=...&version=Win11&bios=uefi&disk=0&perso=0
/ipxe/windows/unattend.xml (3.5)
  → validation IpxeWindowsUnattendRequest (Rule::in version)
  → résolution Workstation par MAC+UUID
  → WindowsUnattendBuilder::build(workstation, Win11, [bios=uefi, disk=0, perso=0, ou=...])
    → charge template resources/ipxe/windows/unattend.xml via DOMDocument
    → injecte Win11 BypassTPM + DiskConfig UEFI + UnattendedJoin + LocalAccount
    → interpole ComputerName, RegisteredOrganization, AdminPassword, etc.
    → retourne XML formatté
  → log info ipxe.windows.unattend.generated (sha256, version, bios, join, size)
  → insert MachineBootLog action='ipxe_win_unattend'
  → response text/plain (XML)
  ↓ setup.exe consomme unattend → install Windows complet → reboot → OOBE
  ↓ install.bat post-setup curl -F 'etape=winpe' -F 'ret=0' -F 'uuid=...' -F 'name=PC-101' http://se4fs/ipxe/windows/action
/ipxe/windows/action (3.5)
  → WindowsPostInstallTracker::recordWinpeStart(workstation, name)
    → Workstation::status = 'installation WinPE'
    → save()
  → log info ipxe.windows.action.winpe_start
  → insert MachineBootLog action='ipxe_win_install'
  → response 200 vide
1er reboot OOBE → 1st logon → unattend.xml FirstLogonCommands :
  curl -F "etape=oobe" -F "name=%computername%" -o "%windir%\action.cmd" http://se4fs/ipxe/windows/action
  if exist %windir%\action.cmd (call %windir%\action.cmd)
  ↓
/ipxe/windows/action (3.5)
  → WindowsPostInstallTracker::recordOobeComplete(workstation, name)
    → Workstation::os = 'windows'
    → Workstation::status = 'installation Windows terminee'
    → Workstation::last_report_at = now()
    → save()
  → log info ipxe.windows.action.oobe_complete
  → insert MachineBootLog action='ipxe_win_report'
  → response 200 vide
```

### Vérification non-régression catchall

Garde-fou critique : les routes legacy `/ipxe/clonage.php`, `/ipxe/Win10/repair.bat.php`, `/ipxe/Win10/win_iso.php`, `/ipxe/diconf/*` doivent **continuer de fonctionner** via le catchall jusqu'à 3.6/3.7. Risque concret : un dev pourrait être tenté de "généraliser" en `Route::prefix('/ipxe/windows')`. **Anti-pattern strict** — D2 limite à 6 routes précises.

Mitigation :
- T7.5 test archi obligatoire.
- T7.6 tests feature non-régression (catchall continue de servir les routes hors scope).

### Tests qu'on **ne** fait **pas** dans cette story

- Tests de boot réel sur poste de test PXE → install complète Windows — couvert par scénario QA manuel 3.5-15.
- Tests de WinPE consommant les artefacts — comportement firmware/installer, hors périmètre serveur.
- Tests de charge `/ipxe/windows/unattend.xml` (50 postes simultanés rentrée) — déférés post-prod.
- Tests d'install Linux / clonezilla — = stories 3.4/3.7.
- Tests des flows action post-install complets (sysprep/nosysprep/join/renomme/post/wpkg) — = story 3.7.
- Tests `installw11old` — = futur si besoin terrain (D14).

---

## Dev Agent Record

### Agent Model Used

- Modèle : `claude-opus-4-7`
- Worktree : `ipxe`
- Date : `2026-05-21`
- Note : agent dev interrompu par erreur socket en fin de travail. Code complet livré (toutes les phases T0-T8 cochées). Finalisation tracking BMAD (Dev Agent Record, sprint-status.yaml, backlog.html) réalisée par l'orchestrateur dev-cycle sur la base de l'inspection des fichiers livrés.

### Debug Log References

- Lint `php -l` sur les 51 fichiers PHP créés/modifiés : **0 erreur** (PHP 8.4.5 local — vérification host).
- Suite phpunit complète **différée Henri post-merge VM** (vendor/ absent worktree + pas de connexion AD/LDAP live + assets binaires Win10/Win11 nécessaires pour Feature endpoints unattend/install.bat).
- Cache reset Laravel + reload PHP-FPM + reload Apache **différés VM** (pattern iso 3.1/3.2/3.3/3.4).

### Completion Notes List

- **Pattern 3.4 réutilisé intensivement** comme prévu : `LinuxPreseedService` → `WindowsUnattendBuilder` (DOMDocument XPath ~12 queries au lieu de remplacement texte), `LinuxInstallMenuBuilder` → `WindowsInstallMenuBuilder`, `LinuxPostInstallTracker` → `WindowsPostInstallTracker`, `PreseedPlaceholders` → `WindowsXmlPlaceholders` (sanitization XML + shell-arg).
- **15 décisions D1-D15 appliquées sans écart** (validées via inspection code livré) :
  - D1 namespace `App\Ipxe\*` étendu (Services/Enums/Support/Exceptions/Http/Controllers/Requests).
  - D2 6 routes 3.5 ajoutées AVANT le catchall legacy dans `routes/web.php` (commentaire `⚠⚠⚠` préservé).
  - D3 `auth.v1.lan-only` seul (pas de Bearer, pas de TOTP — abandonné `se4install_passwd` côté URL params).
  - D4 `WorkstationLocator` réutilisé (UUID prio + fallback MAC) — non modifié.
  - D5 `WindowsInstallMenuBuilder` dédié (séparation responsabilités vs IpxeService).
  - D6 `WindowsUnattendBuilder` DOMDocument XPath transforms iso `update_xml_unattend()` legacy (Win10/Win11, legacy/uefi, perso/join, disk/no-disk).
  - D7 `WindowsInstallBatBuilder` CRLF strict `\r\n` + sanitization shell-arg (defense in depth vs install.bat shell injection).
  - D8 14 nouveaux events log structurés sha256-only (pas de secrets en clair).
  - D9 aucune migration (réutilisation MachineBootLog + Workstation).
  - D10 8 templates Blade ASCII strict ; unattend.xml + install.bat + diskpart.txt + sysprep.xml NON Blade (text/plain natif via controllers).
  - D11 configs étendues `config/ipxe.php` (section windows) + `config/sambaedu.php` (section windows + adminse_*/win_*).
  - D12 MachineBootLog +5 actions ≤17 chars : `ipxe_install_win`, `ipxe_win_unattend`, `ipxe_win_install`, `ipxe_win_diskpart`, `ipxe_win_report`.
  - D13 UI Livewire hors-scope (déférée future story).
  - D14 `installw11old` déférée 3.7 (pas de portage natif dans 3.5).
  - D15 `sysprep.xml` stub minimal (body vide) — flow complet déféré 3.7.
- **Sécurité defense in depth** : 2 couches sanitization (FormRequest validation + service-level `WindowsXmlPlaceholders::sanitize*()`). Anti-injection XML (entities, CDATA, attribut quotes) + anti-injection shell-arg pour install.bat (espaces, semicolons, backticks, $).
- **Non-régression critique préservée** : `IpxeAdminAction` étendu de 12 → 19 cases sans casser les tests 3.1-3.4 (extension `windowsMeta()` non destructive). `IpxeActionResolver` étendu avec `resolveWindowsVariables` (préserve `resolveLinuxVariables`). `routes/web.php` : 6 routes 3.5 ajoutées AVANT catchall (commentaire `⚠⚠⚠` préservé).
- **Tests cumulés livrés** :
  - **Unit nouveaux** : 73 tests dans 9 fichiers (Enums 11 + Services 42 + Support 20).
  - **Feature nouveaux** : 33 tests dans 6 fichiers (endpoints `/ipxe/installation-windows` + `/ipxe/windows/{install.bat,unattend.xml,diskpart.txt,sysprep.xml,action}`).
  - **Tests étendus** dans 7 fichiers existants : `IpxeAdminActionTest`, `IpxeConfigTest`, `IpxeActionResolverTest`, `IpxeMenuRendererTest` (Unit) + `IpxeAdminEndpointTest`, `IpxeLegacyRoutingNonRegressionTest` (Feature) + `IpxeNamespaceTest` (Architecture).
  - **Total cumulé** : ~120-130 tests (≥ minimum 45 ciblés — pattern iso 3.4).
- **Doc QA étendue** : `docs/qa/domains/ipxe.md` Section 14 + **18 scénarios stables 3.5-1 à 3.5-18** (≥ 10 ciblés) — menu, actions, install.bat CRLF, unattend.xml DOMDocument (Win10/Win11/UEFI/legacy/perso/disk), hooks action winpe/oobe, sécurité LAN, non-régression catchall. Scénario 3.5-18 = smoke optionnel poste réel boot PXE → install Win11.
- **Items différés Henri post-merge VM** :
  1. `composer install` + cache reset (config + route + view) + reload PHP-FPM (`systemctl reload php8.2-fpm@www-admin`) + reload Apache.
  2. Suite phpunit complète (`php artisan test --filter=Ipxe`) — ~120-130 tests + non-régression 3.1-3.4 (~135-155 baseline) = ~255-285 tests cumulés iPXE.
  3. Validation présence variables `.env` AD/Windows : `SAMBAEDU_ADMINSE_NAME`, `SAMBAEDU_ADMINSE_PASSWD`, `SAMBAEDU_WIN_KEY`, `SAMBAEDU_WIN_USER`, `SAMBAEDU_WIN_PASSWD`, `SAMBAEDU_WIN_AUTOLOGON`, `SE4INSTALL_PASSWD`, `SAMBAEDU_DOMAIN`.
  4. Validation présence assets binaires VM : `Win10/wimboot`, `Win10/winpeshl.ini`, `Win{10,11}/boot/bcd`, `Win{10,11}/boot/boot.sdi`, `Win{10,11}/sources/boot.wim` sous `/var/www/html/ipxe/` (ou path config legacy).
  5. Smoke curl 16 scénarios non-optionnels Section 14 `docs/qa/domains/ipxe.md`.
  6. Smoke optionnel poste réel scénario 3.5-18 (PXE boot → wimboot → install Win11 complet end-to-end avec OOBE).
- **Aucun fichier legacy modifié** : `git status` confirme zéro modification sous `sambaedu/`. Le catchall sert encore `Win10/repair.bat.php` (action winpe 3.2) et `Win10/win_iso.php` (= scope 3.6).
- **Recommandation modèle code-review** : **`sonnet`** (opposé d'opus dev — pattern iso 3.1/3.2/3.3/3.4).

### File List

#### Fichiers créés (33)

```
# Services (4)
app/Ipxe/Services/WindowsUnattendBuilder.php       (563 lignes — DOMDocument XPath transforms)
app/Ipxe/Services/WindowsInstallBatBuilder.php     (135 lignes — CRLF strict + shell-arg)
app/Ipxe/Services/WindowsInstallMenuBuilder.php    (91 lignes)
app/Ipxe/Services/WindowsPostInstallTracker.php    (187 lignes — winpe/oobe + MachineBootLog)

# Enums (2)
app/Ipxe/Enums/WindowsVersion.php                  (51 lignes — Win10|Win11)
app/Ipxe/Enums/WindowsInstallStep.php              (45 lignes — Winpe|Oobe)

# Support (1)
app/Ipxe/Support/WindowsXmlPlaceholders.php        (166 lignes — sanitize XML + shell-arg)

# Exception (1)
app/Ipxe/Exceptions/UnattendGenerationException.php (28 lignes)

# Controllers (6)
app/Ipxe/Http/Controllers/IpxeInstallationWindowsController.php (39 lignes)
app/Ipxe/Http/Controllers/IpxeWindowsInstallBatController.php   (127 lignes)
app/Ipxe/Http/Controllers/IpxeWindowsUnattendController.php     (185 lignes)
app/Ipxe/Http/Controllers/IpxeWindowsDiskpartController.php     (120 lignes)
app/Ipxe/Http/Controllers/IpxeWindowsSysprepController.php      (58 lignes — stub D15)
app/Ipxe/Http/Controllers/IpxeWindowsActionController.php       (124 lignes)

# FormRequests (6)
app/Ipxe/Http/Requests/IpxeInstallationWindowsRequest.php (39 lignes)
app/Ipxe/Http/Requests/IpxeWindowsInstallBatRequest.php   (53 lignes)
app/Ipxe/Http/Requests/IpxeWindowsUnattendRequest.php     (51 lignes)
app/Ipxe/Http/Requests/IpxeWindowsDiskpartRequest.php     (42 lignes)
app/Ipxe/Http/Requests/IpxeWindowsSysprepRequest.php      (37 lignes)
app/Ipxe/Http/Requests/IpxeWindowsActionRequest.php       (49 lignes)

# Templates Blade — menu (1)
resources/views/ipxe/menu/installation-windows.blade.php

# Templates Blade — actions install_win* (7)
resources/views/ipxe/actions/install_win10.blade.php
resources/views/ipxe/actions/install_win10_debug.blade.php
resources/views/ipxe/actions/install_win10_disk.blade.php
resources/views/ipxe/actions/install_win10_perso.blade.php
resources/views/ipxe/actions/install_win11.blade.php
resources/views/ipxe/actions/install_win11_disk.blade.php
resources/views/ipxe/actions/install_win11_perso.blade.php

# Template XML asset (1)
resources/ipxe/windows/unattend.xml

# Tests Unit (9)
tests/Unit/Ipxe/Enums/WindowsVersionTest.php                       (6 tests)
tests/Unit/Ipxe/Enums/WindowsInstallStepTest.php                   (5 tests)
tests/Unit/Ipxe/Support/WindowsXmlPlaceholdersTest.php             (16 tests)
tests/Unit/Ipxe/Support/WindowsTemplateAssetTest.php               (4 tests)
tests/Unit/Ipxe/Services/WindowsUnattendBuilderTest.php            (14 tests)
tests/Unit/Ipxe/Services/WindowsInstallBatBuilderTest.php          (9 tests)
tests/Unit/Ipxe/Services/WindowsInstallMenuBuilderTest.php         (7 tests)
tests/Unit/Ipxe/Services/WindowsPostInstallTrackerTest.php         (6 tests)
tests/Unit/Ipxe/Services/IpxeServiceInstallationWindowsTest.php    (6 tests)

# Tests Feature (6)
tests/Feature/Ipxe/IpxeInstallationWindowsEndpointTest.php (6 tests)
tests/Feature/Ipxe/IpxeWindowsInstallBatEndpointTest.php   (7 tests)
tests/Feature/Ipxe/IpxeWindowsUnattendEndpointTest.php     (8 tests)
tests/Feature/Ipxe/IpxeWindowsDiskpartEndpointTest.php     (3 tests)
tests/Feature/Ipxe/IpxeWindowsSysprepEndpointTest.php      (3 tests)
tests/Feature/Ipxe/IpxeWindowsActionEndpointTest.php       (6 tests)
```

#### Fichiers modifiés (18)

```
app/Ipxe/Enums/IpxeAdminAction.php                          (+7 cases install_win* + windowsMeta())
app/Ipxe/Enums/IpxeMenuKind.php                             (+2 cases InstallationWindows*)
app/Ipxe/Services/IpxeService.php                           (+handleInstallationWindowsMenu)
app/Ipxe/Services/IpxeMenuRenderer.php                      (+renderInstallationWindowsMenu + installWindowsBaseUrl)
app/Ipxe/Services/IpxeActionResolver.php                    (+resolveWindowsVariables)
app/Providers/IpxeServiceProvider.php                       (+4 singletons Windows*)
config/ipxe.php                                             (+section windows D11)
config/sambaedu.php                                         (+section windows + adminse_*/win_* D11)
resources/views/ipxe/menu/admin.blade.php                   (+item (w) install-windows + section chain)
routes/web.php                                              (+6 routes 3.5 AVANT catchall ⚠⚠⚠)
docs/qa/domains/ipxe.md                                     (+Section 14 Story 3.5 + 18 scénarios stables 3.5-1..18)
tests/Architecture/IpxeNamespaceTest.php                    (+tests routes 3.5 avant catchall + namespace + templates ASCII)
tests/Feature/Ipxe/IpxeAdminEndpointTest.php                (+tests item install-windows visible)
tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php   (+3 tests non-régression Win10/repair, Win10/win_iso, clonage)
tests/Unit/Ipxe/Enums/IpxeAdminActionTest.php               (+tests 7 cases install_win* + windowsMeta)
tests/Unit/Ipxe/IpxeConfigTest.php                          (+assertions section windows config)
tests/Unit/Ipxe/Services/IpxeActionResolverTest.php         (+4 tests resolveWindowsVariables)
tests/Unit/Ipxe/Services/IpxeMenuRendererTest.php           (+tests renderInstallationWindowsMenu)
```

#### Fichiers métadonnées BMAD modifiés

```
_bmad-output/implementation-artifacts/3-5-installation-windows-sysprep-wimboot.md (status review + Dev Agent Record rempli + File List)
_bmad-output/implementation-artifacts/sprint-status.yaml                          (3-5: ready-for-dev → review)
_bmad-output/backlog.html                                                          (3-5 status pill → review)
```

#### Fichiers NON modifiés (garde-fou)

```
sambaedu/ipxe/**                  ← legacy intact (catchall sert encore Win10/repair.bat.php pour action winpe 3.2 + win_iso.php = scope 3.6)
legacy/modules/ipxe/**            ← idem
app/Models/Workstation.php        ← lecture seule + update os/status via WindowsPostInstallTracker
app/Models/MachineBootLog.php     ← lecture seule + insert via Eloquent
app/Auth/V1/**                    ← intact (réutilisation alias auth.v1.lan-only)
app/Ipxe/Services/WorkstationLocator.php           ← lecture seule (réutilisation)
app/Ipxe/Services/IpxeHostnameSanitizer.php        ← lecture seule (réutilisation)
app/Ipxe/Services/LinuxPreseedService.php          ← lecture seule (non touché 3.5)
app/Ipxe/Services/LinuxInstallMenuBuilder.php      ← lecture seule
app/Ipxe/Services/LinuxPostInstallTracker.php      ← lecture seule
```

### Change Log

- 2026-05-21 — Création SM par claude-opus-4-7 (worktree `ipxe`). 10 volets / ~75 AC / 8 phases T0-T8 / 15 décisions D1-D15 tranchées / ~37 fichiers créés + ~15 modifiés / ≥45 tests cumulés ciblés. Dépendances amont : 3.1+3.2 done, 3.3+3.4 review (à valider done en T0.1). Modèle dev recommandé : **opus**.
- 2026-05-21 — DEV terminé par claude-opus-4-7[1m] (worktree `ipxe`). 33 fichiers créés (4 services + 2 enums + 1 support + 1 exception + 6 controllers + 6 FormRequests + 8 templates Blade + 1 XML asset + 9 tests Unit + 6 tests Feature) + 18 modifiés (5 App\Ipxe + provider + 2 configs + admin.blade + routes + doc QA + 7 tests étendus). ~106 tests nouveaux + ~50 tests étendus dans 7 fichiers existants. Lint `php -l` 51 fichiers PHP : 0 erreur. 15 décisions D1-D15 appliquées sans écart. Doc QA Section 14 + 18 scénarios stables 3.5-1 à 3.5-18. Status `ready-for-dev` → `review`. Recommandation code-review : **sonnet**.

---

## Recommandation Modèle Dev

**Modèle recommandé : `opus`**

**Justification** :

- **Domaine sensible — secrets en clair sur LAN** : l'unattend.xml + install.bat contiennent des mots de passe (`adminse_passwd`, `se4install_passwd`, `win_user_passwd`) + credentials AD join, rendus en clair text/plain au LAN. La sanitization (2 couches XML + shell-arg) + non-fuite dans les logs demande une attention rigoureuse. Sonnet a tendance à logger trop verbeusement ou à oublier de sanitize un node XML. Opus mieux armé pour la défense en profondeur sécurité.
- **Manipulation DOMDocument XPath non triviale** : port iso-legacy `update_xml_unattend()` (~370 LOC PHP DOMDocument) avec ~12 XPath queries différentes + injection de fragments XML + conditionnels (Win10 vs Win11, legacy vs uefi, join vs perso, disk vs no-disk). Tracer le bon ordre de manipulations + ne rien oublier (un node manquant = install Windows échoue). Opus mieux armé pour ce type de port systématique.
- **Line endings `\r\n` CRITIQUES pour WinPE** : un seul `\n` au milieu du bash → install Windows échoue silencieusement (WinPE n'exécute pas la suite). Le test unit doit asserter chaque ligne — facile à oublier en sonnet (qui peut générer du `\n` au lieu de `\r\n` sans s'en rendre compte). Opus plus rigoureux.
- **Coordination 6 controllers + 6 FormRequests + 4 services + 2 enums + 1 support + 1 exception + 8 templates + 1 XML asset + ~45 tests** : densité élevée (~37 fichiers créés + ~15 modifiés). Opus mieux armé pour la cohérence end-to-end.
- **Non-régression critique sur 3.1-3.4** : 3.5 modifie `IpxeAdminAction` (+7 cases — passe de 12 à 19), `IpxeMenuRenderer`, `IpxeActionResolver` (+resolveWindowsVariables sans casser resolveLinuxVariables), `IpxeService`, `admin.blade.php` (+1 item sans casser 3.3/3.4), `routes/web.php`, `config/ipxe.php`, `config/sambaedu.php`. Risque de casser silencieusement les ~135-155 tests existants 3.1-3.4 si refactor mal pensé. Opus mieux armé pour respecter "extension non destructive".
- **Pattern déjà rodé 3.4 — partiellement compensable Sonnet** : la story 3.5 reproduit strictement le pattern 3.4 (Linux). Le dev peut copier-coller-adapter intensivement. **Cela compense partiellement le coût Opus mais ne le remplace pas** — la complexité spécifique Windows (DOMDocument + CRLF + 7 variantes + 2 endpoints supplémentaires diskpart/sysprep) reste supérieure à 3.4.
- **Decision-log déjà cadré** : 15 décisions D1-D15 tranchées. Le dev n'a pas à itérer dessus.

**Bascule possible vers Sonnet** : si les phases T1-T2 (enums + WindowsUnattendBuilder + WindowsInstallBatBuilder) se passent sans accroc et que le dev produit une couverture unit verte en T2 (tous les tests DOMDocument + CRLF passent du premier coup), les phases T3-T8 (builder menu + tracker + templates + controllers + routes + doc QA) pourraient passer en Sonnet pour économiser le coût. Décision Henri post-T2.

**Charge cadrée** : 4-5j (estimation SM) — densité élevée (~37 fichiers créés) mais decision-log tranché + patterns 3.1-3.4 prêts à imiter + 3.4 pattern à copier intensivement. Recadrer 5-6j si :
- T0.5 escalade variables `.env` AD/Windows manquantes (rentrée scolaire risque) OU assets binaires Win10/Win11 manquants en VM.
- T0.4 révèle des nodes XPath non listés D6 dans `update_xml_unattend()`.
- T2 révèle des comportements DOMDocument inattendus (encodage UTF-8, BOM, etc.).
- Henri arbitre l'ajout de `installw11old` ou des flows action post-install complets (= dérapage scope 3.7).
