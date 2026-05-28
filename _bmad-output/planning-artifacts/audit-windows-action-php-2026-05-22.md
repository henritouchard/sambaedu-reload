# Audit `Win10/action.php` legacy — Story 3.8 « Installation Windows post-OOBE flows »

> Livrable T0 (pre-flight) de la Story 3.8 (story de comblement de dette créée
> post-3.7). Cartographie exhaustive de `sambaedu/ipxe/Win10/action.php`
> (733 LOC) — orchestrateur central des étapes post-OOBE Windows (sysprep,
> nosysprep, join, renomme, post, wpkg) hors-scope 3.5 et non-portées en 3.7.

**Date d'exécution** : 2026-05-22
**Auteur** : claude-opus-4-7[1m] (Story 3.8, Phase T0 — création SM)
**HEAD host** : `726e1ff Feat - 3.6 handle iso windows` + branche `ipxe`
**Worktree** : `ipxe` (`/home/htouchard/code/irundo/codebase/ipxe`)
**Source legacy** : `legacy/modules/ipxe/Win10/action.php` (733 LOC)
**Statut** : livrable — input direct dev 3.8.

---

## 1. Contexte du trou fonctionnel

La Story 3.5 (« Installation Windows Sysprep/Wimboot ») a porté natif :

- `installation-windows.php` (menu iPXE)
- `Win10/install.bat.php` (script WinPE)
- `Win10/unattend.xml.php` (XML setup.exe)
- `Win10/diskpart.php` (partitionnement)
- `Win10/sysprep.xml.php` (stub minimal — D15 différé)
- `Win10/action.php` **partiel** — uniquement `etape=winpe` (début install) et
  `etape=oobe` (1er logon) — voir `app/Ipxe/Enums/WindowsInstallStep.php:18-26` :

  > « **Hors-scope 3.5** (déférée 3.7 quand `IpxeProgrammedActionResolver` sera
  > porté) : sysprep, nosysprep, join, renomme, post, wpkg. »

La Story 3.7 a livré clonezilla/diagnostic + cleanup catchall — **n'a PAS
porté `action.php` post-OOBE flows** (recadrage scope).

**Conséquence terrain** (constat 2026-05-22) :

- Postes existants pré-3.5 : leur install.bat hérité hardcode `http://<se4fs>/
  ipxe/Win10/action.php` → fallback `direct_legacy_routes ^/ipxe/` continue de
  servir → **legacy fonctionne**.
- Postes installés via natif 3.5 : leur install.bat **généré par
  `WindowsInstallBatBuilder` SE5** pointe vers `/ipxe/windows/action` (cf.
  `app/Ipxe/Services/WindowsInstallBatBuilder.php:115`). Cet endpoint répond
  200 + body vide sur `etape=sysprep|nosysprep|join|renomme|post|wpkg` (log
  warning `ipxe.windows.action.unsupported_step`, cf.
  `IpxeWindowsActionController.php:69-79`). → **install incomplet** : Windows
  reçoit `action.cmd` vide post-OOBE → ne fait aucune des étapes post-install
  (mise au domaine, sysprep, renommage, wpkg).

**Findings critique** : `resources/ipxe/windows/unattend.xml:112` contient :

```xml
<CommandLine>%comspec% /c curl -sL --retry 20 --retry-max-time 300 --fail
 -F "etape=oobe" -F "name=###_NAME_###" -o "%windir%\action.cmd"
 "http://###_SE4FS_NAME_###/ipxe/windows/action"</CommandLine>
```

L'OOBE Windows attend de **télécharger** un body `action.cmd` (script BAT) à
exécuter — pas un body vide. Le tracker SE5 actuel update la DB mais ne sert
PAS le body BAT, ce qui rompt la chaîne post-OOBE.

---

## 2. Cartographie LOC par étape (action.php legacy 733 LOC)

### 2.1 Bootstrap (lignes 1-66)

| LOC | Contenu | Mapping SE5 |
|---|---|---|
| 1-37 | Commentaires + require `config/ldap/actions/ipxe_functions/windows.inc.php` | N/A — config Laravel + Eloquent |
| 39-58 | Bloc principal : `if (isset($_POST['name']))` + `get_action($config,$name)` (lookup AD + apcu actions[]) + extract `id,uuid,script,type,ret,role,etape,ou,clone_name` | `WorkstationLocator::locate($mac, $uuid)` + `Workstation` Eloquent attrs (id, name, ad_dn, status, progress) — pas d'apcu (= `WindowsProgrammedAction` table dédiée OU JSON column sur `workstations`) |
| 60-66 | `$cmd_header = "REM cmd\r\nREM script de demarrage genere ...\r\n"` | Header constant SE5 (cf. `WindowsActionCmdBuilder::HEADER`) |

### 2.2 Bloc cmd_sysprep (lignes 73-144 — ~71 LOC)

**Étape** : `etape=sysprep` reçu via curl depuis GPO startup script.

| LOC | Sous-bloc | Description |
|---|---|---|
| 74-77 | `:uuid` | Extraction UUID via `powershell Get-CimInstance Win32_ComputerSystemProduct` + goto `autologon|gpo` selon `%username%==se4install_name` |
| 78-95 | `:gpo` | 1er passage (user normal) : registry autoLogon `se4install` + autorun.cmd + curl POST `etape=sysprep&ret=0&uuid=%UUID%&name=...` + copy action.cmd → autorun.cmd + reboot |
| 98-120 | `:autologon` | 2e passage (user=se4install) : delete registry autoLogon + DL sysprep.xml + run `sysprep.exe /generalize /oobe /quiet /unattend:sysprep.xml` + on success curl `ret=1` + reboot pour clonage (msg "le poste est pret pour le clonage") |
| 122-143 | `:nosysprep` | Fallback si sysprep KO : registry autoLogon `adminse` + powershell `Remove-Computer -UnjoinDomain` + curl `ret=2` + reboot |
| 142-143 | `:fin` | Cleanup `%windir%\gpo.txt` + `c:\netinst\nowpkg.txt` |

**Params reçus du legacy** : `etape, ret (0/1/2), name, uuid`
**Variables interpolées** : `$config['se4install_name|domain|se4install_passwd|
adminse_name|adminse_passwd|se4fs_name]`, `$name`, `$clone_name` (= 6 premiers
chars de `$name` + `-` + random 0-9999).
**Side effects DB legacy** : `set_action(uuid, [type='clonage|clonage2', role=
'modele', script='windows|rescuecd', ret=N, etape=...])`, `set_statut(id,
'preparation image|sysprep generalisation|clonage sans sysprep')`,
`set_progress(id, '50%|100%')`.

### 2.3 Bloc cmd_nosysprep (lignes 151-192 — ~41 LOC)

**Étape** : `etape=nosysprep` (déclinaison sans sysprep — clonage rapide).

| LOC | Sous-bloc | Description |
|---|---|---|
| 152-172 | `:gpo` | Registry autoLogon se4install + autorun + curl `etape=sysprep&ret=0&uuid&name` + copy action.cmd → autorun + reboot 5s |
| 176-189 | `:autologon` | Registry autoLogon adminse (count=2) + powershell `Remove-Computer -UnjoinDomain -WorkGroupName clone` + curl `etape=sysprep&ret=2` + reboot (msg "pret pour clonage") |
| 190-192 | `:fin` | Cleanup |

Note legacy : ce bloc est servi en réponse à `etape=nosysprep` MAIS il poste lui-même
`etape=sysprep` en retour — ambiguïté legacy à clarifier (cf. décision D-A4).

### 2.4 Bloc cmd_post (lignes 198-231 — ~33 LOC)

**Étape** : `etape=post` — post-intégration manuelle (mise au domaine ratée,
ré-injection des fichiers SambaEdu).

| LOC | Sous-bloc | Description |
|---|---|---|
| 199-220 | `:gpo` | Registry autoLogon se4install + autorun + `md %WINDIR%\Web\SE4\` + `md %PROGRAMFILES%\SambaEdu\` + `robocopy c:\netinst → %PROGRAMFILES%\SambaEdu /MOVE` + curl `etape=post&ret=0&uuid&name` + copy action.cmd → autorun + reboot |
| 221-228 | `:autologon` | Cleanup registry + DL nouveau script via curl `--fail -F etape=post -F name=%computername% -o %windir%\action.cmd` + run action.cmd (second tour) |
| 229-231 | `:fin` | Cleanup |

**Comportement remarquable** : le bloc `:autologon` **recharge action.php** une
2e fois (curl avec -o = télécharge le body). Donc legacy renvoie 2 scripts
distincts selon le passage (state machine).

### 2.5 Bloc cmd_oobe (lignes 236-263 — ~27 LOC)

**Étape** : `etape=oobe` — déclenché par `FirstLogonCommands` dans unattend.xml
post-install Windows.

| LOC | Sous-bloc | Description |
|---|---|---|
| 236-238 | `:uuid` | Extract UUID + goto |
| 239-260 | `:admin` | Si user==se4install : delete registry autoLogon + setx SE4FS + gpupdate /force + powershell `driversAuto.ps1` + powershell `winget-install.ps1` + `bcdboot c:\windows /addlast` + curl `etape=oobe&ret=0&uuid&name` + `echo install finie > c:\netinst\install.log` + reboot |
| 261-263 | `:fin` | Cleanup |

**Variables interpolées** : `$config['se4install_name', 'se4fs_name']`, `$name`.
**Side effects** : delete autologon registry, run drivers ps1, run wpkg4
scheduled task, log install finished.
**Side effects DB** : `set_action(uuid, ['role'=>'windows', 'etape'=>'oobe'])`,
`set_statut(id, 'mise au domaine v1')`, `set_progress(id, '90%')`.

### 2.6 Bloc cmd_wpkg (lignes 268-311 — ~43 LOC)

**Étape** : `etape=wpkg` — lancement wpkg en mode interactif.

| LOC | Sous-bloc | Description |
|---|---|---|
| 269-287 | `:gpo` | Registry autoLogon se4install + autorun + curl `etape=wpkg&ret=0&uuid&name` + reboot forcé `-f` |
| 289-309 | `:autologon` | Delete autologon registry + `mklink` install et rapports → SMB share + copy wpkg-client.vbs + run driversAuto.ps1 + run winget-install.ps1 + run "Nettoyage WPKG.cmd" + curl `etape=wpkg&ret=1` + shutdown.exe /l (logoff) |
| 311 | `:fin` | Cleanup |

### 2.7 Bloc cmd_renomme (lignes 317-351 — ~34 LOC)

**Étape** : `etape=renomme` — renommage poste au domaine.

| LOC | Sous-bloc | Description |
|---|---|---|
| 318-337 | `:gpo` | Registry autoLogon se4install + autorun + curl `etape=renomme&ret=0&uuid&name` + reboot (msg "redemarrer en se4install pour etre renomme $role") |
| 340-348 | `:autologon` | Delete autologon registry + `powershell Rename-Computer -NewName $role` + curl `etape=renomme&ret=1` + reboot final (msg "le poste est renomme $role") |
| 349-351 | `:fin` | Cleanup |

**Variable critique** : `$role` (= nouveau nom du poste, vient de `actions[uuid][role]`).

### 2.8 Bloc cmd_join (lignes 358-406 — ~48 LOC)

**Étape** : `etape=join` — mise au domaine après un clonage.

| LOC | Sous-bloc | Description |
|---|---|---|
| 359-371 | `:uuid` + `:join` | Si user==adminse : `Rename-Computer -NewName $name` + curl `etape=join&ret=0` + reboot |
| 373-389 | `:domaine` | Registry autoLogon se4install + autorun + powershell `Add-Computer -Credential -DomainName -OUPath '$ou' -Force` + cleanup GroupPolicy + curl `etape=join&ret=1` + reboot |
| 391-404 | `:autologon` | Delete autologon registry + gpupdate /force + `md %WINDIR%\Web\SE4\` + copy SetWallpaper.ps1 + cleanup nowpkg + `schtasks /run /tn wpkg4` + curl `etape=join&ret=2` + reboot |
| 405-406 | `:fin` | Cleanup |

**Variables interpolées critiques** : `$name`, `$role`, `$ou` (= LDAP OU
extrait de `$machine['dn']` via `ldap_dn2oudn`), `$config['domain']`,
`$config['se4install_*']`.

### 2.9 Dispatcher (lignes 408-516 — ~108 LOC, le coeur)

#### Branche A — `!isset($_POST['ret'])` ou `$ret < 0` (premier appel d'étape)

```
switch ($etape) {
    case "sysprep":
        if ($type == "clonage" || $type == "clonage2") {
            $out .= $cmd_nosysprep; set_action(type, role='modele', etape);
            set_statut("préparation 1er boot"); set_progress("0%"); }
        else: set_progress("0%");
        break;
    case "nosysprep": set_progress("50%"); break;
    case "join":      $out .= $cmd_join; set_action(role='windows'); set_statut("mise au domaine v2"); set_progress("0%"); break;
    case "renomme":   $out .= $cmd_renomme; set_action(etape); set_statut("renommage au domaine"); set_progress("20%"); break;
    case "post":      $out .= $cmd_post; set_action(etape); set_statut("post-mise au domaine manuelle"); set_progress("20%"); break;
    case "wpkg":      $out .= $cmd_wpkg; set_action(etape); set_statut("lancement de wpkg interactif"); set_progress("10%"); break;
    case "oobe":      $out .= $cmd_oobe; set_action(role='windows', etape='oobe'); set_statut("mise au domaine v1"); set_progress("90%"); break;
    default:          http_response_code(403);
}
```

#### Branche B — `ret == 0` (réponse curl post-étape)

```
switch ($etape) {
    case "post": $out .= $cmd_post; break;
    case "join": $out .= $cmd_join; set_statut("mise au domaine v2"); set_progress("0%"); break;
    default:     http_response_code(403);
}
```

#### Branche C — `ret > 0` (validation finale étape — lignes 504-515)

```
switch ($etape) {
    case "join": $out .= $cmd_join; set_statut("mise au domaine v2"); set_progress("0%"); break;
    default:     http_response_code(403);
}
```

#### Branche D — `isset($_POST['ret'])` ELSE (validation et avancement state machine — lignes 518-727)

Le legacy `else` (validation d'étape) implémente une machine à états distincte
de la simple émission de cmd_*. Pour chaque (etape, ret) tuple, le state des
actions du poste évolue (set_action), le statut et la progression DB sont mis
à jour, **mais aucun body cmd_* n'est renvoyé** (la machine continue son
exécution avec son script local). Cas couverts :

- `sysprep ret=0`: type=clonage2, role=modele, script=windows, status=preparation image, progress=50%.
- `sysprep ret=1`: role=modele, script=rescuecd, etape=init-modele, status=sysprep generalisation, progress=50%.
- `sysprep ret=2`: type=clonage2, role=modele, script=rescuecd, etape=init-modele, status=clonage sans sysprep, progress=100%.
- `join ret=0`: type=clonage2, role=windows, script=default, status=renommage sans sysprep OK, progress=30%.
- `join ret=1`: type=clonage2, role=windows, script=default, status=mise au domaine sans sysprep OK, progress=60%.
- `join ret=2`: type=clonage2, role=windows, script=default, etape=default, ret=-1, status=clonage terminé, progress=100%.
- `oobe ret=0`: role=windows, script=default, ret=-1, etape=default, status=script de demarrage post-install OK, progress=100%.
- `post ret=0`: role=windows, script=default, status=script de demarrage post-install OK, progress=50%.
- `post ret=1`: role=windows, script=default, etape=default, ret=-1, status=...OK, progress=100%.
- `wpkg ret=0`: role=windows, script=default, etape=wpkg, ret=0, status=lancement wpkg interactif, progress=50%.
- `wpkg ret=1`: role=windows, script=default, etape=default, ret=-1, status=d'exec de wpkg fini, progress=100%.
- `renomme ret=0`: rename AD via `move_ad($config, $machine['cn'], $new_dn, "machine")` + `dns_delete` + `dns_add` (effets de bord forts !) + set_progress("60%") + set_statut("renommage AD OK"). Si rename AD échoue → progress=40% + statut=ERREUR.
- `renomme ret=1`: type=default, script=default, etape=default, ret=-1, status=Renommage terminé, progress=100%.
- `default ret=any` (= étape `oobe` finished pour install Windows native): type=default, etape=default, ret=-1, `set_os($config, $cn, "windows")`, set_progress("100%"), set_statut("terminé").

### 2.10 Footer (lignes 728-733)

```
} else {
    file_put_contents("/tmp/actions_err.log", "erreur ..."); http_response_code(403);
}
?>
```

---

## 3. Effets de bord legacy → mapping SE5

| Helper legacy | Comportement | Mapping SE5 natif 3.8 |
|---|---|---|
| `set_action($config, $uuid, [...])` | Persiste un dict d'actions programmées dans apcu_cache (clé = uuid) + ldap (attribut custom ?) | Nouvelle table `windows_programmed_actions` OU colonne JSON `programmed_action` sur `workstations`. **Décision D-A1** = colonne JSON. |
| `set_statut($id, "...")` | Update `workstation.status` (text fr) | `$workstation->status = "..."` + `save()`. Préserver `protected` D11 3.4. |
| `set_progress($id, "0%-100%")` | Update `workstation.progress` (text) | `$workstation->progress = "..."` + `save()`. À ajouter via colonne si absente. |
| `set_os($config, $cn, "windows")` | Update `workstation.os = 'windows'` | `$workstation->os = 'windows'` + `save()` (déjà fait par `WindowsPostInstallTracker::recordOobeComplete`). |
| `move_ad($config, $cn, $new_dn, "machine")` | LDAP modify_dn — déplace l'objet ordinateur dans une nouvelle OU/cn | `AdMachineManager::renameComputer($oldCn, $newCn, $newOu)` (existe déjà 3.3 D14 plan B = delete+recreate). |
| `dns_delete + dns_add` | Met à jour la zone DNS samba/bind | `AdDnsManager::deleteRecord/addRecord` (à vérifier — si pas dispo → laisser legacy via shim, hors-scope strict). Décision D-A6. |
| `get_action($config, $name)` | Lookup AD computer + apcu actions[] | `WorkstationLocator::locate(mac, uuid, name)` + lecture colonne `programmed_action` JSON. |
| `apcu_store("ldap_cache_invalid", true, 60)` | Invalide cache LDAP côté legacy | Pas nécessaire SE5 — pas de cache LDAP intermédiaire. |
| `ldap_dn2oudn($machine['dn'])` | Extrait `OU=...,DC=...` du DN | Helper réutilisable `App\Ldap\DnHelper::extractOuPath()` (à vérifier — sinon implémenter). |

---

## 4. Helpers/scripts shell invoqués par les .bat générés

Le legacy `action.php` génère des cmd batch Windows qui invoquent côté poste
Windows :

| Script invoqué | Origine | À porter SE5 ? |
|---|---|---|
| `c:\windows\system32\sysprep\sysprep.exe` | Windows OS natif | NON (binaire OS) |
| `c:\netinst\sysprep.ps1` | Côté Windows poste (copié pendant install via robocopy `c:\netinst` ← SMB) | NON — fichier déjà déployé pendant l'install. **À auditer** si toujours présent post-3.5 native install. **Décision D-A7** = scope SE5 ne modifie pas ces scripts shell — restent côté windows poste / share SMB legacy. |
| `%PROGRAMFILES%\SambaEdu\driversAuto.ps1` | Share SMB legacy `\\se4fs\install\os\netinst\driversAuto.ps1` (copié sur poste par OOBE FirstLogon) | NON — script PowerShell côté poste, hors-scope SE5 strict (Phase 3 audit shell). |
| `%PROGRAMFILES%\SambaEdu\winget-install.ps1` | Idem | NON |
| `%PROGRAMFILES%\SambaEdu\SetWallpaper.ps1` | Idem | NON |
| `%PROGRAMFILES%\SambaEdu\Nettoyage WPKG.cmd` | Idem | NON |
| `wpkg4` scheduled task | Schedtasks Windows installée par WPKG (Epic 15) | NON — déjà géré par Epic 15. |
| `bcdboot c:\windows /addlast` | Windows natif | NON |
| `gpupdate /force /target:computer` | Windows natif | NON |
| `powershell Add-Computer -Credential -DomainName -OUPath` | Windows natif | NON |
| `powershell Rename-Computer -NewName` | Windows natif | NON |
| `powershell Remove-Computer -UnjoinDomain -WorkGroupName` | Windows natif | NON |

**Décision D-A7** : 3.8 ne porte AUCUN script shell côté Windows poste — uniquement
le **générateur de cmd batch** côté serveur SE5 (= le rôle de `action.php`).
Les scripts shell invoqués restent dans le partage SMB legacy `\\<se4fs>\install\`
et seront audités/portés sur des stories Phase 3 dédiées si besoin (cf.
`audit-applications-scripts.md` Epic 17 — story 17-1 déjà done).

---

## 5. Décisions D-naming-clés à pré-trancher (proposées SM, applique par dev sans débat)

### D-A1 — Persistance des actions programmées : **colonne JSON** sur `workstations` (PAS de nouvelle table)

- Ajout colonne `programmed_action JSONB DEFAULT '{}'` à `workstations` (1
  migration trivial).
- Schema : `{"type":"clonage|clonage2|renomme|postinst|default", "role":"...",
  "script":"...", "etape":"...", "ret":-1|0|1|2}` (parité legacy `apcu`).
- **Justification** : pas d'historique multi-actions à conserver (1 seule
  action programmée active par poste), colonne JSON simple + lookup par `$workstation->programmed_action['etape']` lisible.
- **Anti-pattern** : NE PAS créer `windows_programmed_actions` (overkill — 1
  ligne par workstation, 1-to-1).

### D-A2 — Whitelist enum `WindowsInstallStep` : étendre à **8 cases** (Winpe + Oobe + Sysprep + Nosysprep + Join + Renomme + Post + Wpkg)

- Ajout dans `app/Ipxe/Enums/WindowsInstallStep.php` :
  ```php
  case Sysprep   = 'sysprep';
  case Nosysprep = 'nosysprep';
  case Join      = 'join';
  case Renomme   = 'renomme';
  case Post      = 'post';
  case Wpkg      = 'wpkg';
  ```
- **Sécurité critique** : whitelist enum reste l'autorité finale (defense in
  depth contre payload `etape=arbitrary`).

### D-A3 — Naming `MachineBootLog.action` (≤20 chars varchar) : **6 nouveaux labels**

| Étape | Label boot_log | LOC |
|---|---|---|
| Sysprep   | `ipxe_win_sysprep`   | 16 ✓ |
| Nosysprep | `ipxe_win_nosysprep` | 18 ✓ |
| Join      | `ipxe_win_join`      | 13 ✓ |
| Renomme   | `ipxe_win_renomme`   | 16 ✓ |
| Post      | `ipxe_win_post`      | 13 ✓ |
| Wpkg      | `ipxe_win_wpkg`      | 13 ✓ |

Tous ≤ 20 chars. Pas de migration DB (varchar(20) suffit).

### D-A4 — Variantes `ret` par étape : **mappées vers méthodes Tracker distinctes**

| Étape | ret=0 | ret=1 | ret=2 |
|---|---|---|---|
| sysprep   | `recordSysprepGpoStart()` → status="préparation image", progress=50% | `recordSysprepGeneralized()` → status="sysprep generalisation", progress=50% | `recordSysprepNoneClone()` → status="clonage sans sysprep", progress=100% |
| nosysprep | (pas de ret legacy) | — | — |
| join      | `recordJoinAdminseStarted()` → status="renommage sans sysprep OK", progress=30% | `recordJoinDomained()` → status="mise au domaine sans sysprep OK", progress=60% | `recordJoinComplete()` → status="clonage terminé", progress=100% |
| oobe      | `recordOobeComplete()` (existe 3.5) | — | — |
| post      | `recordPostAutologon()` → status="post-install OK", progress=50% | `recordPostFinished()` → status="post-install OK", progress=100% | — |
| wpkg      | `recordWpkgAutologon()` → status="lancement wpkg interactif", progress=50% | `recordWpkgFinished()` → status="exec wpkg fini", progress=100% | — |
| renomme   | `recordRenommeAdRenamed()` → AD rename via AdMachineManager + DNS update + progress=60% | `recordRenommeFinished()` → status="Renommage terminé", progress=100% | — |
| default   | `recordDefault()` → set os='windows', progress=100%, status="terminé" | — | — |

### D-A5 — Séparation step vs sub-step : **PAS de sous-states explicites**

- Le legacy mélange `etape` (string) + `ret` (int) + `type/role/script` (dict)
  → la séparation step/sub-step y est implicite.
- Décision SE5 = 3.8 utilise (step, ret) comme tuple unique, sans extraire
  sous-states séparés. Le `programmed_action` JSON reflète le state directement.
- **Anti-pattern** : ne PAS introduire un enum `WindowsInstallSubStep` (overkill).

### D-A6 — DNS update lors du renomme (legacy `dns_delete/add`) : **HORS-SCOPE strict 3.8** — laisser via shim si AD a son propre mécanisme

- Le legacy `move_ad` + `dns_delete/dns_add` mette à jour DNS samba/bind.
- SE5 : `AdMachineManager` ne gère probablement pas DNS directement (à
  confirmer T0.4 dev). Si pas dispo → 3.8 ne fait QUE le rename AD via
  `renameComputer`, et le DNS est mis à jour par Samba 4 lui-même (intégration
  AD + DNS native depuis Samba 4.0).
- **Si terrain remonte un bug DNS post-rename** → story dédiée Phase 3.

### D-A7 — Sanitization placeholders dans les .cmd batch : **WindowsXmlPlaceholders étendu (PAS de nouveau service)**

- Réutiliser `WindowsXmlPlaceholders::sanitizeShellArg()` (existe 3.5) pour
  tous les `$name`, `$role`, `$ou`, `$clone_name` interpolés dans les .cmd
  batch (sécurité critique — ces .cmd s'exécutent en SYSTEM côté Windows poste).
- Ajout 1 méthode `WindowsXmlPlaceholders::sanitizeBatPlaceholder(string $raw)`
  (alias plus précis pour les contextes cmd vs XML).
- **Sécurité 0-trust** : même si l'enum + FormRequest valident `etape/ret`,
  les variables `name/uuid/role/clone_name` viennent de l'AD ou du curl POST
  → sanitize systématique avant interpolation.

### D-A8 — Génération des 6 templates .cmd : **Blade dédié OU concatenation PHP ?**

- **Décision SM = Blade strict** (parité 3.5 pattern : `WindowsInstallBatBuilder`
  est PHP pur car install.bat est dynamique avec `CRLF strict` ; pour les 6
  .cmd post-OOBE, la complexité textuelle est élevée → Blade `@if/@php` plus
  lisible MAIS la contrainte CRLF strict revient).
- **Sous-décision** : 6 templates Blade `resources/views/ipxe/windows/cmd/{sysprep,nosysprep,join,renomme,post,wpkg}.blade.php` rendus via `WindowsActionCmdBuilder::buildSysprep(Workstation)`, post-traités via `str_replace("\n", "\r\n")` (pattern 3.4 `LinuxPreseedService`).
- **Alternative non retenue** : tout en PHP concaténé (comme `WindowsInstallBatBuilder`) — trop lourd pour 6 cmd × 30-70 LOC.

### D-A9 — Endpoint POST `/ipxe/windows/action` : **réutilisation route existante 3.5, extension controller**

- **Pas** de nouvelle route. La route `/ipxe/windows/action` existe (3.5 D2)
  + middleware `auth.v1.lan-only + throttle:600,1`.
- Extension : `IpxeWindowsActionController` étend son match sur step.
- **Response Content-Type** : `text/plain` (parité legacy header `text/plain`),
  body = le cmd batch généré (PAS body vide comme actuellement sur unsupported_step).
- Critère AC4.7 : un curl `-F etape=sysprep -F ret=0 -F uuid -F name -o action.cmd` doit recevoir un body cmd valide (non-vide).

### D-A10 — Audit des dirty bits (état legacy avant 3.8) : **5 colonnes Workstation à scruter**

- `os` (`linux`/`windows`/null) — déjà géré 3.4/3.5.
- `status` (text fr) — étendu par 3.8 avec 10+ statuts FR distincts.
- `progress` (text `0%-100%`) — à ajouter si colonne absente. **Migration trivial**.
- `programmed_action` (JSONB) — nouveau D-A1.
- `ad_dn` (text) — utilisé pour extraction OU lors du renomme.

**Migration 3.8** : 2 colonnes ajoutées (`progress` + `programmed_action`) si absentes.

### D-A11 — Garde-fous parité legacy bit-équivalence : **2 tests dédiés**

- `it_generates_cmd_sysprep_byte_equivalent_to_legacy_fixture`
- `it_generates_cmd_join_byte_equivalent_to_legacy_fixture`
- Fixtures : capture du body legacy `action.php` (curl direct vers VM avec
  poste connu) puis assertion `assertSame($fixtureBody, $natifBody)` à l'octet
  près (modulo le `cmd_header` commenté qui contient `$id`, `$uuid`, `$type`
  variables → masquer ces lignes du diff).

### D-A12 — Cleanup `direct_legacy_routes ^/ipxe/` : **NE PAS RETIRER** (hors-scope 3.8 strict)

- Le fallback `direct_legacy_routes ^/ipxe/` reste en place — postes existants
  pré-3.5 continuent de fonctionner via legacy `Win10/action.php`.
- **3.8 ne touche PAS à ce fallback** — Q-5 Henri 3.7 décision : laisser actif
  pour les assets statiques + les postes legacy.
- Le pattern `^ipxe/Win10/action\.php$` n'est PAS ajouté à `blocked_legacy_routes`
  en 3.8 (laisse le legacy servir les postes legacy).

---

## 6. Synthèse : code à porter natif en 3.8

### 6.1 Inventaire fichiers SE5 à créer (~6)

- `app/Ipxe/Services/WindowsActionCmdBuilder.php` (NEW — ~250 LOC, génère les
  6 cmd batch via templates Blade + CRLF strict + sanitization).
- `database/migrations/2026_05_22_XXXXXX_add_progress_and_programmed_action_to_workstations.php` (NEW — D-A10).
- `resources/views/ipxe/windows/cmd/sysprep.blade.php` (NEW — ~70 LOC port iso `cmd_sysprep`).
- `resources/views/ipxe/windows/cmd/nosysprep.blade.php` (NEW — ~40 LOC).
- `resources/views/ipxe/windows/cmd/join.blade.php` (NEW — ~50 LOC).
- `resources/views/ipxe/windows/cmd/renomme.blade.php` (NEW — ~35 LOC).
- `resources/views/ipxe/windows/cmd/post.blade.php` (NEW — ~35 LOC).
- `resources/views/ipxe/windows/cmd/wpkg.blade.php` (NEW — ~45 LOC).
- `tests/Unit/Ipxe/Services/WindowsActionCmdBuilderTest.php` (NEW — ~30 tests).
- `tests/Feature/Ipxe/IpxeWindowsActionEndpointPostOobeTest.php` (NEW — ~15 tests Feature).
- `tests/Feature/Ipxe/ParityLegacyWindowsActionTest.php` (NEW — ~6 fixtures parité bit-équivalence).

### 6.2 Inventaire fichiers SE5 à modifier (~10)

- `app/Ipxe/Enums/WindowsInstallStep.php` (+6 cases).
- `app/Ipxe/Http/Controllers/IpxeWindowsActionController.php` (extension dispatch).
- `app/Ipxe/Http/Requests/IpxeWindowsActionRequest.php` (étendre `Rule::in` etape).
- `app/Ipxe/Services/WindowsPostInstallTracker.php` (+~14 méthodes record*).
- `app/Ipxe/Support/WindowsXmlPlaceholders.php` (+méthode `sanitizeBatPlaceholder`).
- `app/Models/Workstation.php` (+casts `programmed_action` JSON).
- `app/Providers/IpxeServiceProvider.php` (+singleton WindowsActionCmdBuilder).
- `config/ipxe.php` (+section `windows.post_install` avec flags enabled toggle par étape).
- `tests/Unit/Ipxe/Enums/WindowsInstallStepTest.php` (assertions +6 cases).
- `docs/qa/domains/ipxe.md` (+Section 17 « Story 3.8 » ≥ 10 scénarios).

---

## 7. Risques inventoriés

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Body cmd batch malformé (CRLF manquant, char invisible) → Windows poste reçoit action.cmd silencieusement KO | Moyenne | install incomplète + silencieuse | CRLF strict (iso 3.5 D7) + test unit `it_contains_only_crlf_line_endings` pour chaque cmd template |
| Injection via `$name`/`$role`/`$ou` (poste compromis qui POST `name="; calc.exe; rem`) | Moyenne | RCE côté poste Windows en SYSTEM | `WindowsXmlPlaceholders::sanitizeBatPlaceholder` (defense in depth D-A7) + whitelist enum + log warning si rejet |
| Régression installs Windows déjà en cours (poste WinPE → POST `etape=sysprep` mais le SE5 partiel répondait 200 silent et poste passait à autre étape) | Faible (postes en train d'installer = rare) | install bloquée | Decision : **Activer 3.8 progressivement** via `config('ipxe.windows.post_install.enabled', true)` toggle. Si KO → flip à false → revert au comportement 3.5 silent. |
| Non-régression sur `factory_reset` (cmdline iPXE identique à `ClonezillaRestoreSda2Sda1` 3.7) | Très faible | / | Tests Architecture 3.7 doivent rester verts. |
| DNS samba non synchronisé après rename AD | Moyenne | poste ne résout pas son nouveau nom | D-A6 — laisser samba 4 gérer DNS auto. Si KO terrain → story Phase 3 dédiée. |
| Concurrent requests sur même poste (2 POST simultanés `etape=sysprep`) | Faible | double set_action + double cmd renvoyé | `DB::transaction` + `lockForUpdate()` sur le `Workstation::programmed_action` (D-A1). |
| `programmed_action` JSON colonne incompatible PostgreSQL ancien | Faible (PostgreSQL 9.4+ supporte JSONB) | migration KO | Schema `JSONB DEFAULT '{}'::jsonb` standard. |

---

## 8. Critères de réussite 3.8

1. Un poste installé via 3.5 natif et qui appelle `etape=sysprep|nosysprep|join|renomme|post|wpkg` reçoit un body cmd batch valide (non vide) bit-équivalent au legacy.
2. Tracker SE5 met à jour status/progress/programmed_action en conformité parité legacy (state machine).
3. Le `MachineBootLog.action` reçoit 6 nouveaux labels distincts.
4. Le rename AD via `renomme ret=0` met à jour le DN du poste (test e2e avec AdMachineManager fake).
5. Le fallback legacy `direct_legacy_routes ^/ipxe/` reste fonctionnel pour les postes pré-3.5.
6. Tests Unit + Feature + Architecture ≥ 35 cumulés. ≥ 2 tests parité legacy bit-équivalence.
7. Doc QA Section 17 ≥ 10 scénarios stables 3.8-1 à 3.8-10.

---

## 9. Hors-scope strict 3.8 (à ne PAS toucher)

- Port shell des scripts SE5 sous-jacents (`driversAuto.ps1`, `winget-install.ps1`,
  `SetWallpaper.ps1`, `Nettoyage WPKG.cmd`, `sysprep.ps1`) — Phase 3 dédiée.
- Retrait du fallback `direct_legacy_routes ^/ipxe/` (D-A12) — postes existants
  doivent continuer à fonctionner via legacy.
- Refonte UX/UI Livewire (pas d'UI native pour gérer ces flows).
- Drivers DISM post-install (déféré Phase 3).
- Multi-établissements (hors-scope iPXE).
- Workflow stateful clonage UDP-multicast (3.7 D3 + Phase 3 dédiée).
- Variante Win7 (legacy `sysprep.xml.php:16` — abandonnée 3.5 D3).
- Migration de la colonne `programmed_action` legacy apcu → SE5 (postes en
  cours d'install pré-3.8 finiront via legacy ; ceux post-3.8 partent
  directement sur SE5).

---

## 10. Charge estimée

**3 jours** dev opus :

- T1 enum + migration + JSON casts : 0.3j
- T2 WindowsActionCmdBuilder + 6 templates Blade + CRLF strict + sanitization : 1.0j
- T3 WindowsPostInstallTracker extension (+~14 méthodes record* + AD rename intégration) : 0.7j
- T4 Controller dispatch + FormRequest extension + tests Feature endpoint : 0.5j
- T5 Tests Architecture + tests parité bit-équivalence (2 fixtures) : 0.3j
- T6 Doc QA Section 17 + sprint-status : 0.2j

Recadrage 4j si T0.4 `AdMachineManager::renameComputer` ne supporte pas le
flow nécessaire OU si T0.5 colonne `progress` absente + migrations à coordonner.

---

**Fin audit Story 3.8.**
