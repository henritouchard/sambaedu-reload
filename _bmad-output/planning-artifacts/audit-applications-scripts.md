# Audit des scripts applications packagés — Epic 17 (Story 17.1)

> Livrable structurant Story 17.1 (Epic 17 post-RESET 2026-05-14). Cartographie
> exhaustive des fichiers scripts livrés par le package Debian `sambaedu` sous
> `usr/share/sambaedu/applications/`, `var/sambaedu/unattended/install/wpkg/`
> et `var/sambaedu/unattended/install/os/SambaEdu/` (≈ 109 fichiers au total,
> dont 54 fragments `.windows`/`.linux`, 11 `scripts.json`, 5 `redirects.json`,
> 3 scripts WPKG critiques et ≈ 29 scripts `install/os/SambaEdu/`). Guide le
> découpage technique des stories 17.2 → 17.4 et la stratégie de compatibilité
> runtime des scripts packagés avec Epic 15 (WPKG natif) + Epic 16 (GPO natives).

**Date initiale** : 2026-05-14
**Patch post-Epic-16-done** : 2026-05-21 (orchestrateur dev-cycle, claude-opus-4-7[1m])
**Auteur initial** : claude-opus-4-7 (Story 17.1, Phase T4 dev)
**Statut** : ✅ **validé par Henri 2026-05-21** (AC1.4 ; parité 16.1).
**Version analysée** : repo upstream `/home/htouchard/code/irundo/se4/sources/`
(inventaire de référence au 2026-05-14 — pas de commit ID exploitable côté
upstream depuis le repo de travail ; le snapshot lu est celui présent sur disque
le jour de l'audit, post-RESET Epic 17).

> **⚠ Patch 2026-05-21 — Mise à jour post-Epic-16-done** : entre la rédaction
> de l'audit (2026-05-14) et sa validation (2026-05-21), l'Epic 16 a été
> complétée (stories 16.10-16.15 + 16.13bis done, story 16.4 **cancelled**
> 2026-05-18). Les recommandations Section G ont été révisées en conséquence :
> - **Story 16.13** a porté 8 endpoints natifs sous `/api/v1/workstation-config/*`
>   (firefox, thunderbird, veyon, wallpaper, shortcuts, network, associations,
>   applications-scripts) → 17.6 réduite à 2 endpoints orphelins
>   (`wpkg/linux_out.php`, `wpkg/winget_out.php`).
> - **Story 16.12** a livré l'infrastructure logs (table `script_execution_logs`,
>   endpoint `POST /api/v1/script-execution-logs`, `WrapperScriptRenderer`,
>   templates wrapper Blade) → 17.5 réduite à brancher le wrapper dans le
>   pipeline d'assembly.
> - **Story 16.4 cancelled** (création/duplication native GPO) → 17.3 bascule
>   sur Stratégie A (template `se4_applications` géré par le repo de templates
>   GPO téléchargé à l'install serveur ; 17.3 vérifie que les `.cmd` orchestrateurs
>   du template pointent vers les endpoints `/api/v1/workstation-config/*` natifs
>   et non vers `gpo/applications.php` legacy).
> Voir la section « Réponses Henri 2026-05-21 » en fin de document pour le détail
> Q1-Q8 résolu.

---

## Synthèse exécutive

- **Volumétrie effective < prévision SM (~80)** : **54 fragments `.windows`/`.linux`**
  exactement (et non ~80), répartis sur **29 dossiers d'apps** (`associations`,
  `chrome`, `conda`, `edge`, `Filius`, `firefox`, `firewall`, `folders`, `gdm`,
  `glpi`, `gnome`, `logs`, `ltsp`, `OnlyOffice`, `OpenBoard`, `printers`,
  `rclone`, `rdp`, `reseau`, `shortcuts`, `thunderbird`, `veyon`, `vscode`,
  `wakeonlan`, `wallpaper`, `wine`, `winget`, `wpkg`). Le décompte initial
  surestimait — la story 17.4 (5 scripts critiques) garde son sens, mais la
  cartographie totale Section A reste sous la centaine.
- **2 mécanismes co-existent dans le scanner serveur legacy** : (1) le pattern
  ancien `<action>[-context][@group].{windows,linux}` (≈ 54 fragments) ; (2) le
  pattern récent `scripts.json` (6 apps déclarent en JSON → 15 entrées
  supplémentaires : `folders`, `logs`, `ltsp`, `rdp`, `reseau`, `ltsp`). À
  noter : `redirects.json` (5 apps : `chrome`, `edge`, `Filius`, `firefox`,
  `thunderbird`, `OpenBoard`, `OnlyOffice`) est un **3e mécanisme** distinct
  géré par `redirect_scripts()` côté serveur (pas un simple JSON descripteur).
- **Découverte critique #1 — Whitelist 16.7 incomplète** : la whitelist 16.7
  comporte **8 clés** (`SE4FS_NAME`, `DOMAIN`, `UAI`, `NETLOGON_PATH`,
  `WPKG_URL`, `SAMBA_DOMAIN`, `TMP_DIR`, `CLOUD_PERSO_NAME`). Or les scripts
  upstream utilisent **11 placeholders distincts** dont **6 absents** de la
  whitelist : `ADMINSE_NAME`, `DHCP_MASQUE`, `DHCP_RESEAU`, `GLPI_URL`,
  `NO_INTERNET`, `SE4AD_IP`, `SE4FS_IP`, `SE4INSTALL_NAME`. **Conséquence
  parc-wide bloquante** : `firewall/startup.windows`, `firewall/logon-system.windows`,
  `folders/clean_profiles`, `glpi/startup.linux`, `wallpaper/logon.windows`,
  `wine/startup.linux` produiraient des scripts avec placeholders non substitués
  → segments `IF NOT [###_NO_INTERNET_###]==[]` triviaux à exécuter mais
  segments `netsh firewall add rule … remoteip=###_SE4FS_IP_###` **casseraient
  la règle netsh** (string littérale au lieu d'IP). **Story 17.2 doit étendre
  la whitelist ; la liste est en Section B.**
- **Découverte critique #2 — `wpkg.js` du package ≠ `wpkg.js` de la story 16.6** :
  le fichier que 16.6 appelle « wpkg.js » côté client est en réalité
  `var/sambaedu/unattended/install/wpkg/wpkg-se4.js` (11 281 lignes — fork
  upstream wpkg.js patché pour SE4). Il est invoqué via `cscript.exe wpkg-client.vbs`
  (lui-même 1 038 lignes). **Frontière confirmée** : `wpkg-se4.js` est **hors
  scope Epic 17** (déjà couvert par chaîne `wpkg/startup.windows` → `wpkg.cmd`
  → `wpkg-client.vbs` → `wpkg-se4.js` → `/wpkg/hosts.xml` + `/wpkg/profiles.xml`
  servis par Story 15.2 + Story 16.6 GPO). L'audit ne re-cartographie pas
  ce fichier en profondeur (Section A le mentionne, statut « Hors scope Epic 17 »).
- **Découverte critique #3 — `install/os/SambaEdu/*` est en partie in-scope
  runtime** : la décision SM par défaut « out-of-scope iPXE » est **partiellement
  fausse**. Ces ≈ 29 fichiers sont déployés une fois par
  `wpkg/startup.windows:ROBOCOPY %WinDir%\install\os\SambaEdu %ProgramFiles%\SambaEdu`,
  puis appelés au runtime via `powershellTask.ps1` (lui-même livré là) qui
  programme une `ScheduledTask` pour exécuter `applications-<action>.ps1`
  (téléchargé par curl depuis `applications.php`). **Conséquence** : les fichiers
  `install/os/SambaEdu/{install.ps1, powershellTask.ps1, SetWallpaper.ps1,
  winget-install.ps1, PingSE4.ps1, no_internet.ps1, ...}` sont **infrastructure
  d'exécution runtime** des scripts assemblés. **Verdict** : in-scope **observabilité
  Epic 17** (l'audit les liste) mais **out-of-scope portage 17.2** (le moteur
  natif n'a pas à les regénérer — ils sont juste recopiés depuis
  `%WinDir%\install`). Détail en Section H point 3.
- **Endpoints HTTP serveur invoqués depuis les scripts (Section C)** : **9
  endpoints distincts** consommés par `curl` côté poste : `gpo/applications.php`,
  `gpo/firefox_out.php`, `gpo/thunderbird_out.php`, `gpo/veyon_out.php`,
  `gpo/wallpaper_out.php`, `gpo/shortcuts_out.php`, `gpo/network_out.php`,
  `partages/cloud_out.php`, `wpkg/linux_out.php`, `wpkg/winget_out.php`,
  `logs.php`. **5 d'entre eux sont des `*_out.php` non encore portés** par
  Story 16.7 (qui ne porte que `applications.php`). **Risque parc-wide** : ces
  endpoints doivent rester accessibles iso-comportement pendant Epic 17 (ils
  sont consommés par 24 des 54 fragments scripts) — d'où la nécessité d'une
  story dédiée (proposition 17.6 — cf. Section G).
- **Recommandations de découpage final 17.x (Section G)** :
  - **17.2** = portage natif `applications.php` (déjà fait en review par 16.7) +
    **élargissement whitelist placeholders** (6 clés à ajouter) + **enveloppe
    logging** (cf. Section I) → confirmer si 17.2 absorbe le DELTA 16.7 ou si
    une story 17.2b est créée.
  - **17.3** = compat GPO orchestratrice `se4_applications` (création/liaison
    native via Story 16.5 déjà DONE — vérification du contenu SYSVOL des `.cmd`
    orchestrateurs `Nettoyage applications-*.cmd` est in-scope).
  - **17.4** = tests d'intégration runtime VM (5 scripts critiques confirmés :
    `wpkg/startup.windows`, `wallpaper/logon.windows`, `shortcuts/logon.windows`,
    `firefox/logon.windows`, `firefox/logon.linux`).
  - **NOUVEAU — 17.5** = centralisation logs scripts en BDD (cf. Section I —
    cadrage cette section).
  - **NOUVEAU — 17.6** = portage natif des **5 endpoints `*_out.php`** non
    encore traités par 16.7 (`firefox_out.php`, `thunderbird_out.php`,
    `veyon_out.php`, `wallpaper_out.php`, `shortcuts_out.php`, `network_out.php`,
    `cloud_out.php`, `wpkg/linux_out.php`, `wpkg/winget_out.php`). À discuter
    avec Henri — alternativement à splitter en 17.6a/b/c.

**Recommandation portée par cette synthèse** : Epic 17 passe de **4 stories
(17.1-17.4) à 5-6 stories (ajout 17.5 logs + 17.6 endpoints out.php)**.
La décision finale revient à Henri.

---

## Section A — Cartographie fichier par fichier

> Pour chaque script : **11 champs** (AC2.A) — (1) Fichier source (chemin
> absolu + lignes), (2) Type d'action, (3) OS cible, (4) App rattachée, (5)
> Interpréteur, (6) Déclencheur côté poste, (7) Placeholders consommés, (8)
> Endpoints HTTP appelés, (9) Statut runtime côté Epic 15/16, (10) Surcharges
> admin observées, (11) Risques/pièges + mode de logging (annoté pour Section I).
>
> Présentation : pour limiter le bruit, les fragments « banals » (registre
> Windows pur, sans curl ni placeholder hors whitelist) sont groupés
> proprement avec une fiche synthétique. Les fragments à risque ou consommant
> des endpoints HTTP/placeholders hors whitelist ont chacun leur fiche.
> **54 fragments `.windows`/`.linux` couverts** + **2 scripts critiques WPKG**
> + **11 fragments référencés par `scripts.json`** + **5 `redirects.json`**
> + **échantillon 5/29 scripts `install/os/SambaEdu/`** (justification du périmètre
> in/out scope).

### A.1 — Fragments invoqués via la GPO orchestratrice `se4_applications`

Cette sous-section couvre les fragments téléchargés par les `.cmd` orchestrateurs
déposés par la GPO `se4_applications` côté poste Windows / par les unités
systemd Linux équivalentes.

#### `applications/associations/logon.windows` (1 ligne)

| Champ                  | Valeur                                                                                                                                              |
|------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `/home/htouchard/code/irundo/se4/sources/usr/share/sambaedu/applications/associations/logon.windows` — 1 ligne                                       |
| **Type d'action**      | `logon`                                                                                                                                              |
| **OS cible**           | windows                                                                                                                                              |
| **App rattachée**      | associations (associations de fichiers Windows)                                                                                                      |
| **Interpréteur**       | cmd (legacy : déduit ext → `cmd`)                                                                                                                    |
| **Déclencheur poste**  | GPO `se4_applications` → orchestrateur `applications-logon.cmd` → curl `applications.php?action=logon&os=windows` → fragment inséré dans le `.cmd`   |
| **Placeholders**       | aucun                                                                                                                                                |
| **Endpoints HTTP**     | aucun (le script appelle juste `%programfiles%\SambaEdu\associations.ps1`, lui-même déployé par `wpkg/startup.windows`)                              |
| **Statut runtime**     | `OK iso-legacy` côté assembly (pas de risque whitelist). Dépend de la présence de `associations.ps1` côté poste (déployé par WPKG, hors scope 17.2). |
| **Surcharges admin**   | non rencontrée à l'audit                                                                                                                             |
| **Risques / logging**  | Logging : silent (le `.ps1` peut logger localement, pas remonté serveur). Risque : si `associations.ps1` absent du poste, le `logon` ne fait rien.  |

#### `applications/associations/startup.windows` (15 lignes)

| Champ                  | Valeur                                                                                                                                              |
|------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `/home/htouchard/code/irundo/se4/sources/usr/share/sambaedu/applications/associations/startup.windows` — 15 lignes                                  |
| **Type d'action**      | `startup`                                                                                                                                            |
| **OS cible**           | windows                                                                                                                                              |
| **App rattachée**      | associations                                                                                                                                         |
| **Interpréteur**       | cmd                                                                                                                                                  |
| **Déclencheur poste**  | GPO `se4_applications` startup                                                                                                                       |
| **Placeholders**       | aucun                                                                                                                                                |
| **Endpoints HTTP**     | aucun                                                                                                                                                |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                      |
| **Surcharges admin**   | non rencontrée                                                                                                                                       |
| **Risques / logging**  | Logging : silent. Risque mineur : `sc config UCPD start=disabled​` — caractère zéro-width invisible en fin de ligne dans le script upstream (encoding warning, à signaler à upstream). |

#### `applications/chrome/logon.windows` (2 lignes) + `chrome/startup.windows` (2 lignes)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/chrome/logon.windows` — 2 lignes ; `…/applications/chrome/startup.windows` — 2 lignes                                            |
| **Type d'action**      | `logon` / `startup`                                                                                                                              |
| **OS cible**           | windows                                                                                                                                          |
| **App rattachée**      | chrome                                                                                                                                           |
| **Interpréteur**       | cmd                                                                                                                                              |
| **Déclencheur poste**  | GPO `se4_applications` logon/startup                                                                                                             |
| **Placeholders**       | aucun                                                                                                                                            |
| **Endpoints HTTP**     | aucun                                                                                                                                            |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                  |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : silent. Pure manipulation de registre HKCU/HKLM `DiskCacheDir`.                                                                        |

#### `applications/chrome/redirects.json` (12 lignes) — type `redirects`

| Champ                  | Valeur                                                                                                                                                                                |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/chrome/redirects.json` — 12 lignes                                                                                                                                    |
| **Type d'action**      | `logon` (et variante `logon-system` injectée automatiquement par `read_application_scripts()`)                                                                                          |
| **OS cible**           | windows (hardcodé côté legacy ligne 88)                                                                                                                                                |
| **App rattachée**      | chrome (key `GoogleChrome`)                                                                                                                                                            |
| **Interpréteur**       | redirects (généré par `redirect_scripts()` côté serveur — produit du `cmd` MKLINK)                                                                                                     |
| **Déclencheur poste**  | GPO `se4_applications` logon                                                                                                                                                           |
| **Placeholders**       | aucun (substitutions faites par PHP serveur, pas par `###_*_###`)                                                                                                                       |
| **Endpoints HTTP**     | aucun                                                                                                                                                                                  |
| **Statut runtime**     | `À adapter 17.2` — le moteur natif 16.7 a porté `redirect_scripts()` (vérifier `ApplicationScriptsAssembler` côté natif gère le cas `interpreter=redirects` avec `link`/`dest`/`server`) |
| **Surcharges admin**   | possible (cf. exclude `port_perdir`, `portables`)                                                                                                                                       |
| **Risques / logging**  | Logging : silent. Risque : si la machine fait partie d'un `excludes` (groupe), pas de redirect — l'élève voit son cache Chrome rester en `Local\Google\Chrome` (gros).               |

#### `applications/conda/logon-system.windows` (4 lignes)

| Champ                  | Valeur                                                                                                                                                                                   |
|------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/conda/logon-system.windows` — 4 lignes                                                                                                                                   |
| **Type d'action**      | `logon-system`                                                                                                                                                                            |
| **OS cible**           | windows                                                                                                                                                                                   |
| **App rattachée**      | conda (Anaconda3 Python)                                                                                                                                                                  |
| **Interpréteur**       | cmd                                                                                                                                                                                       |
| **Déclencheur poste**  | GPO `se4_applications` logon-system (context = system, élévation)                                                                                                                          |
| **Placeholders**       | aucun (utilise `%SE4FS%` directement — env var posée par `wpkg.cmd:SETX SE4FS …`)                                                                                                          |
| **Endpoints HTTP**     | aucun                                                                                                                                                                                     |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                                                            |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                             |
| **Risques / logging**  | Logging : silent. Crée un MKLINK depuis `%HOMEPATH%\.conda` vers `\\%SE4FS%\users\%USERLOGIN%\.conda` (roaming partiel).                                                                  |

#### `applications/edge/logon.windows` (4 lignes) + `edge/startup.windows` (~2 lignes)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/edge/logon.windows` — 4 lignes ; `…/applications/edge/startup.windows` — 2 lignes                                                |
| **Type d'action**      | `logon` / `startup`                                                                                                                              |
| **OS cible**           | windows                                                                                                                                          |
| **App rattachée**      | edge (Microsoft Edge)                                                                                                                            |
| **Interpréteur**       | cmd                                                                                                                                              |
| **Déclencheur poste**  | GPO `se4_applications` logon/startup                                                                                                             |
| **Placeholders**       | aucun                                                                                                                                            |
| **Endpoints HTTP**     | aucun                                                                                                                                            |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                  |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : silent. Manipulation registre (DiskCacheDir, désactivation Copilot/GenAI).                                                            |

#### `applications/edge/redirects.json`, `applications/Filius/redirects.json`, `applications/OnlyOffice/redirects.json`, `applications/OpenBoard/redirects.json` — type `redirects`

Fiche groupée — même pattern que `chrome/redirects.json` (cf. ci-dessus) :
roaming Local→Server par MKLINK. Aucun placeholder, aucun endpoint, statut
`À adapter 17.2` (vérifier portage `redirect_scripts()` natif).

#### `applications/firefox/logon.windows` (26 lignes) — **CRITIQUE Story 17.4**

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/firefox/logon.windows` — 26 lignes                                                                                                                                                |
| **Type d'action**      | `logon`                                                                                                                                                                                            |
| **OS cible**           | windows                                                                                                                                                                                            |
| **App rattachée**      | firefox                                                                                                                                                                                            |
| **Interpréteur**       | cmd                                                                                                                                                                                                |
| **Déclencheur poste**  | GPO `se4_applications` logon                                                                                                                                                                       |
| **Placeholders**       | aucun                                                                                                                                                                                              |
| **Endpoints HTTP**     | aucun                                                                                                                                                                                              |
| **Statut runtime**     | `OK iso-legacy` — mais à valider dans 17.4 (initialisation `profiles.ini` + `installs.ini`).                                                                                                       |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Logging : silent (heredoc `>profiles.ini`). Risque : valeur `Install308046B0AF4A39CB` hardcodée (Firefox install hash) — peut changer après MAJ majeure Firefox. À surveiller.                    |

#### `applications/firefox/logon.linux` (34 lignes) — **CRITIQUE Story 17.4**

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/firefox/logon.linux` — 34 lignes                                                                                                                                                  |
| **Type d'action**      | `logon`                                                                                                                                                                                            |
| **OS cible**           | linux                                                                                                                                                                                              |
| **App rattachée**      | firefox                                                                                                                                                                                            |
| **Interpréteur**       | bash                                                                                                                                                                                               |
| **Déclencheur poste**  | systemd user-session → orchestrateur Linux (équivalent linux des `.cmd` Windows, déclenché par PAM ou gnome-session)                                                                                |
| **Placeholders**       | aucun                                                                                                                                                                                              |
| **Endpoints HTTP**     | aucun                                                                                                                                                                                              |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                                                                    |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Logging : `echo` vers stdout uniquement (capté par systemd journal). Heredoc `profiles.ini` ; hardcoded `build="3B6073811A6ABF12"` — à surveiller.                                               |

#### `applications/firefox/startup.windows` (12 lignes)

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/firefox/startup.windows` — 12 lignes                                                                                                                                              |
| **Type d'action**      | `startup`                                                                                                                                                                                          |
| **OS cible**           | windows                                                                                                                                                                                            |
| **App rattachée**      | firefox                                                                                                                                                                                            |
| **Interpréteur**       | cmd                                                                                                                                                                                                |
| **Déclencheur poste**  | GPO `se4_applications` startup                                                                                                                                                                     |
| **Placeholders**       | `###_SE4FS_NAME_###`, `###_DOMAIN_###` (whitelistés)                                                                                                                                                |
| **Endpoints HTTP**     | `POST http://###_SE4FS_NAME_###.###_DOMAIN_###/gpo/firefox_out.php` (2 cibles : `%PROGRAMFILES%` + `%PROGRAMFILES(x86)%`) avec `id=%id%`, `os=windows`                                              |
| **Statut runtime**     | `À adapter 17.6` — endpoint `firefox_out.php` non porté par 16.7. Doit rester accessible iso-comportement.                                                                                          |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Logging : `>NUL` (silent côté poste — réponse 404 du serveur produirait un `policies.json` vide → Firefox sans policies). **Endpoint `firefox_out.php` doit retourner JSON valide ou 404 silent.** |

#### `applications/firefox/startup.linux` (9 lignes)

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/firefox/startup.linux` — 9 lignes                                                                                                                                                 |
| **Type d'action**      | `startup`                                                                                                                                                                                          |
| **OS cible**           | linux                                                                                                                                                                                              |
| **App rattachée**      | firefox                                                                                                                                                                                            |
| **Interpréteur**       | bash                                                                                                                                                                                               |
| **Déclencheur poste**  | systemd boot                                                                                                                                                                                       |
| **Placeholders**       | `###_SE4FS_NAME_###`, `###_DOMAIN_###`                                                                                                                                                              |
| **Endpoints HTTP**     | `POST http://###_SE4FS_NAME_###.###_DOMAIN_###/gpo/firefox_out.php`                                                                                                                                |
| **Statut runtime**     | `À adapter 17.6`                                                                                                                                                                                    |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Logging : `echo` stdout. Idem startup.windows.                                                                                                                                                     |

#### `applications/firefox/wpkg.windows` (12 lignes)

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/firefox/wpkg.windows` — 12 lignes                                                                                                                                                 |
| **Type d'action**      | `wpkg`                                                                                                                                                                                              |
| **OS cible**           | windows                                                                                                                                                                                            |
| **App rattachée**      | firefox                                                                                                                                                                                            |
| **Interpréteur**       | cmd (inséré par `wpkg_scripts()` côté serveur)                                                                                                                                                     |
| **Déclencheur poste**  | post-installation WPKG (déclenché par `wpkg-client.vbs` après `apt-get install firefox`)                                                                                                            |
| **Placeholders**       | `###_SE4FS_NAME_###`, `###_DOMAIN_###`                                                                                                                                                              |
| **Endpoints HTTP**     | `POST gpo/firefox_out.php`                                                                                                                                                                          |
| **Statut runtime**     | `À adapter 17.6`                                                                                                                                                                                    |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Idem firefox/startup.windows. Action `wpkg` = case spécial dans `make_application_scripts()` (cf. legacy `applications.inc.php:275-278`).                                                          |

#### `applications/firefox/logoff.linux` (3 lignes)

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/firefox/logoff.linux` — 3 lignes                                                                                                                                                  |
| **Type d'action**      | `logoff`                                                                                                                                                                                            |
| **OS cible**           | linux                                                                                                                                                                                              |
| **App rattachée**      | firefox                                                                                                                                                                                            |
| **Interpréteur**       | bash                                                                                                                                                                                               |
| **Déclencheur poste**  | systemd user-session logout / PAM logoff                                                                                                                                                            |
| **Placeholders**       | aucun                                                                                                                                                                                              |
| **Endpoints HTTP**     | aucun                                                                                                                                                                                              |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                                                                    |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Logging : silent. Pure `rm -rf /tmp/cacheFirefox/$USER/`.                                                                                                                                          |

#### `applications/firefox/{default,redirects}.json` — config files

`default.json` (~50 lignes) : policies Firefox par défaut (DisplayBookmarksToolbar,
AppAutoUpdate=false, etc.) — non assemblé dans le `.cmd`, retourné par
`firefox_out.php` (endpoint serveur). `redirects.json` : 2 entrées (`Firefox`,
`FirefoxBackup`) pattern roaming MKLINK identique à chrome. Statut natif : à
inclure dans le portage `firefox_out.php` (Story 17.6).

#### `applications/firewall/startup.windows` (~45 lignes) — **RISQUE BLOQUANT**

| Champ                  | Valeur                                                                                                                                                                                                                                                                          |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/firewall/startup.windows` — 45 lignes                                                                                                                                                                                                                            |
| **Type d'action**      | `startup`                                                                                                                                                                                                                                                                          |
| **OS cible**           | windows                                                                                                                                                                                                                                                                            |
| **App rattachée**      | firewall                                                                                                                                                                                                                                                                            |
| **Interpréteur**       | cmd                                                                                                                                                                                                                                                                                 |
| **Déclencheur poste**  | GPO `se4_applications` startup                                                                                                                                                                                                                                                      |
| **Placeholders**       | **`###_NO_INTERNET_###`, `###_DHCP_RESEAU_###`, `###_DHCP_MASQUE_###`, `###_SE4FS_IP_###`, `###_SE4AD_IP_###`** — **5 placeholders HORS WHITELIST 16.7** ⚠️                                                                                                                            |
| **Endpoints HTTP**     | aucun                                                                                                                                                                                                                                                                                |
| **Statut runtime**     | `À adapter 17.2` — **BLOQUANT** : sans extension whitelist, la commande `netsh advfirewall firewall add rule … remoteip=###_SE4FS_IP_###` serait insérée littéralement → règle netsh rejetée → **pare-feu de tous les postes mis en mode "on" mais sans whitelist SE4FS/SE4AD**.   |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                                                                                                       |
| **Risques / logging**  | Logging : silent. **Risque parc-wide majeur** : si la story 17.2 ne complète pas la whitelist, l'application des policies firewall casse la connectivité serveur de tous les postes Windows. ⚠️                                                                                |

#### `applications/firewall/logon-system.windows` (5 lignes)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/firewall/logon-system.windows` — 5 lignes                                                                                       |
| **Type d'action**      | `logon-system`                                                                                                                                   |
| **OS cible**           | windows                                                                                                                                          |
| **App rattachée**      | firewall                                                                                                                                         |
| **Interpréteur**       | cmd                                                                                                                                              |
| **Déclencheur poste**  | GPO `se4_applications` logon-system                                                                                                              |
| **Placeholders**       | **`###_NO_INTERNET_###`** — HORS WHITELIST 16.7 ⚠️                                                                                              |
| **Endpoints HTTP**     | aucun                                                                                                                                            |
| **Statut runtime**     | `À adapter 17.2` — moins critique : la condition `IF NOT [###_NO_INTERNET_###]==[]` produirait `IF NOT [###_NO_INTERNET_###]==[]` → toujours vrai (string non vide). |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : silent. Bug fonctionnel : appel à `no_internet.ps1` se produit toujours, même si admin a désactivé `no_internet`.                    |

#### `applications/folders/scripts.json` — 8 entrées

Cf. table ci-dessous. `scripts.json` déclare 8 entrées qui pointent vers les
fichiers du dossier `folders/` (sans extension `.windows`/`.linux` — pattern
récent JSON). Le scanner legacy + natif 16.7 lit `scripts.json` et charge
les fichiers référencés.

| Entrée (`name`) | `file`            | OS      | action  | Risques / placeholders                                                |
|-----------------|-------------------|---------|---------|-----------------------------------------------------------------------|
| #0              | `clean_explorer`  | windows | logon   | Aucun placeholder. Registre HKCU pure.                                |
| #1              | `clean_desktop`   | linux   | logon   | Aucun placeholder. `find ~/Bureau`.                                   |
| #2              | `bureau_samba`    | windows | logon   | Utilise `%SE4FS%` env (posé par `wpkg.cmd`). Pas de placeholder.      |
| #3              | `bureau_samba.ps1`| windows | logon   | Interpréteur explicite `powershell` (cf. `scripts.json`).             |
| #4              | `bureau_local`    | windows | logon   | Aucun placeholder.                                                    |
| #5              | `docs_local`      | windows | logon   | Aucun placeholder.                                                    |
| #6              | `docs_samba`      | windows | logon   | Aucun placeholder.                                                    |
| #7              | `clean_profiles`  | windows | startup | **`###_ADMINSE_NAME_###`** — HORS WHITELIST 16.7 ⚠️ + `!DOMAINSID!` (env). |

**Risque #7** : `clean_profiles` supprime tous les dossiers `C:\Users\*` sauf
`Default`, `Public`, `%USERPROFILE%` et `C:\Users\###_ADMINSE_NAME_###`. Si
le placeholder reste non substitué, le dossier `C:\Users\###_ADMINSE_NAME_###`
n'existe pas → le dossier admin local **est supprimé**. ⚠️ **Bloquant
parc-wide** — perte de l'admin local du poste.

#### `applications/gdm/logon.linux` (5 lignes)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/gdm/logon.linux` — 5 lignes                                                                                                     |
| **Type d'action**      | `logon`                                                                                                                                          |
| **OS cible**           | linux                                                                                                                                            |
| **App rattachée**      | gdm                                                                                                                                              |
| **Interpréteur**       | bash                                                                                                                                             |
| **Déclencheur poste**  | systemd user-session (cas spécial `$USER == "Debian-gdm"` au prelogin)                                                                            |
| **Placeholders**       | aucun                                                                                                                                            |
| **Endpoints HTTP**     | aucun                                                                                                                                            |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                  |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : silent (echo stdout). `dbus-launch gsettings` numlock.                                                                                |

#### `applications/glpi/startup.linux` (3 lignes) — **RISQUE BLOQUANT**

| Champ                  | Valeur                                                                                                                                                |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/glpi/startup.linux` — 3 lignes                                                                                                        |
| **Type d'action**      | `startup`                                                                                                                                              |
| **OS cible**           | linux                                                                                                                                                  |
| **App rattachée**      | glpi (GLPI Agent)                                                                                                                                      |
| **Interpréteur**       | bash                                                                                                                                                   |
| **Déclencheur poste**  | systemd boot                                                                                                                                           |
| **Placeholders**       | **`###_GLPI_URL_###`** (hors whitelist ⚠️), `###_UAI_###` (whitelisté)                                                                                  |
| **Endpoints HTTP**     | aucun                                                                                                                                                  |
| **Statut runtime**     | `À adapter 17.2` — bloquant : `server = ###_GLPI_URL_###` → config GLPI Agent invalide → l'agent ne peut pas remonter à GLPI → inventaire perdu.       |
| **Surcharges admin**   | non rencontrée                                                                                                                                         |
| **Risques / logging**  | Logging : silent (juste un echo redirigé vers `/etc/glpi-agent/conf.d/local.cfg`). ⚠️ ajouter `GLPI_URL` à la whitelist.                              |

#### `applications/glpi/startup.windows` (1 ligne)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/glpi/startup.windows` — 1 ligne                                                                                                  |
| **Type d'action**      | `startup`                                                                                                                                        |
| **OS cible**           | windows                                                                                                                                          |
| **App rattachée**      | glpi                                                                                                                                             |
| **Interpréteur**       | cmd                                                                                                                                              |
| **Déclencheur poste**  | GPO `se4_applications` startup                                                                                                                   |
| **Placeholders**       | aucun (utilise `%TAG%` env posé par le `header_scripts()` côté serveur — cf. legacy `applications.inc.php:368 SET TAG=...`)                       |
| **Endpoints HTTP**     | aucun                                                                                                                                            |
| **Statut runtime**     | `À adapter 17.2` — dépend de la génération correcte du `SET TAG=<salle>,<UAI>` dans le header par le moteur natif.                              |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : silent.                                                                                                                                |

#### `applications/gnome/logon.linux` (~30 lignes)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/gnome/logon.linux` — ~30 lignes                                                                                                 |
| **Type d'action**      | `logon`                                                                                                                                          |
| **OS cible**           | linux                                                                                                                                            |
| **App rattachée**      | gnome                                                                                                                                            |
| **Interpréteur**       | bash                                                                                                                                             |
| **Déclencheur poste**  | systemd user-session                                                                                                                             |
| **Placeholders**       | aucun                                                                                                                                            |
| **Endpoints HTTP**     | aucun                                                                                                                                            |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                  |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : silent. Pure `gsettings` (theme Yaru-dark, extensions ding/dash-to-dock).                                                             |

#### `applications/logs/scripts.json` — entrée `ping`

| Entrée   | `file` | OS      | action | Cible/risque                                                              |
|----------|--------|---------|--------|---------------------------------------------------------------------------|
| #0       | `ping` | windows | logon  | 1 ligne. Lance `PingSE4.ps1` depuis `%PROGRAMFILES%\SambaEdu\`. Pas de placeholder. |

#### `applications/ltsp/scripts.json` — 2 entrées + `ltsp` + `packages.list`

| Entrée | `file`           | OS    | action  | `interpreter` | Risques                                                            |
|--------|------------------|-------|---------|---------------|--------------------------------------------------------------------|
| #0     | `packages.list`  | linux | startup | apt           | Liste de paquets apt (interpréteur `apt` côté legacy → fusion `apt_scripts()`). Pas de placeholder. |
| #1     | `ltsp`           | linux | startup | bash          | Restreint au groupe `serveurs_ltsp`. À lire pour confirmer.        |

#### `applications/printers/startup.windows` (1 ligne)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/printers/startup.windows` — 1 ligne                                                                                              |
| **Type d'action**      | `startup`                                                                                                                                        |
| **OS cible**           | windows                                                                                                                                          |
| **App rattachée**      | printers                                                                                                                                         |
| **Interpréteur**       | cmd                                                                                                                                              |
| **Déclencheur poste**  | GPO `se4_applications` startup                                                                                                                   |
| **Placeholders**       | aucun                                                                                                                                            |
| **Endpoints HTTP**     | aucun                                                                                                                                            |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                  |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : silent. `reg add … RpcUseNamedPipeProtocol`.                                                                                          |

#### `applications/rclone/logon.windows` (8 lignes)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/rclone/logon.windows` — 8 lignes                                                                                                |
| **Type d'action**      | `logon`                                                                                                                                          |
| **OS cible**           | windows                                                                                                                                          |
| **App rattachée**      | rclone (montage automatique cloud user)                                                                                                          |
| **Interpréteur**       | cmd                                                                                                                                              |
| **Déclencheur poste**  | GPO `se4_applications` logon                                                                                                                     |
| **Placeholders**       | `###_SE4FS_NAME_###` (whitelisté)                                                                                                                |
| **Endpoints HTTP**     | `POST http://###_SE4FS_NAME_###/partages/cloud_out.php` avec `os=windows`, `id=%id%`                                                              |
| **Statut runtime**     | `À adapter 17.6` — endpoint `cloud_out.php` non porté par 16.7.                                                                                  |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : `>NUL` (silent). Si l'endpoint retourne du HTML d'erreur, le `.cmd` reçoit du HTML → `START /B` charge du HTML comme script → erreur. |

#### `applications/rclone/logon.linux` (~15 lignes)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/rclone/logon.linux` — ~15 lignes                                                                                                |
| **Type d'action**      | `logon`                                                                                                                                          |
| **OS cible**           | linux                                                                                                                                            |
| **App rattachée**      | rclone                                                                                                                                           |
| **Interpréteur**       | bash                                                                                                                                             |
| **Déclencheur poste**  | systemd user-session                                                                                                                             |
| **Placeholders**       | `###_SE4FS_NAME_###`, `###_DOMAIN_###`                                                                                                            |
| **Endpoints HTTP**     | `POST http://###_SE4FS_NAME_###.###_DOMAIN_###/partages/cloud_out.php`                                                                          |
| **Statut runtime**     | `À adapter 17.6`                                                                                                                                  |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : `echo` stdout. Si endpoint en panne → pas de montage cloud user → utilisateur perd accès au cloud.                                   |

#### `applications/rdp/scripts.json` — 3 entrées (`desactivate.cmd`, `activate.cmd`)

| Entrée | `file`           | OS      | action  | context  | Risques                                                                            |
|--------|------------------|---------|---------|----------|------------------------------------------------------------------------------------|
| #0     | `desactivate.cmd`| windows | logon   | system   | Restreint au groupe `remote_user`. Désactive RDP (`reg add … fDenyTSConnections=1`). |
| #1     | `activate.cmd`   | windows | logoff  | system   | Active RDP à la fin de session.                                                    |
| #2     | `activate.cmd`   | windows | startup | (none)   | Active RDP au boot.                                                                |

Pas de placeholder. Pas d'endpoint. Statut `OK iso-legacy`.

#### `applications/reseau/scripts.json` — 4 entrées (PowerShell + Bash)

| Entrée | `file`             | OS      | action  | `interpreter` | Risques                                                                      |
|--------|--------------------|---------|---------|---------------|------------------------------------------------------------------------------|
| #0     | `networkInfo.ps1`  | windows | startup | powershell    | Utilise `${env:SE4FS}` (env var posée). Endpoint `POST /logs.php` ⚠️.        |
| #1     | `networkManager.bash`| linux | startup | bash          | `###_SE4FS_NAME_###`, `###_DOMAIN_###`. Endpoint `POST /gpo/network_out.php`. |
| #2     | `networkInfo.bash` | linux   | startup | bash          | Détail à lire — supposé similaire à `networkInfo.ps1`.                       |
| #3     | `gnome.bash`       | linux   | logon   | bash          | `###_SE4FS_NAME_###`, `###_DOMAIN_###`. Endpoint `POST /gpo/network_out.php`. |

Endpoint **`gpo/network_out.php`** : non porté par 16.7 → **Story 17.6**.

#### `applications/shortcuts/*` — 6 fragments + `*.inc.json` (`Scripts admin.inc.json`, etc.)

5 fragments cmd Windows + 2 bash Linux, tous consomment l'endpoint
`gpo/shortcuts_out.php` (sauf `logon-system.windows` qui est registre pur).

| Fichier                                          | action       | OS      | Placeholders             | Endpoint                          | Statut         |
|--------------------------------------------------|--------------|---------|--------------------------|-----------------------------------|----------------|
| `shortcuts/logon.windows` (8 lignes) **CRIT 17.4** | logon       | windows | `###_SE4FS_NAME_###`     | `POST gpo/shortcuts_out.php`      | `À adapter 17.6` |
| `shortcuts/startup.windows` (7 lignes)            | startup     | windows | `###_SE4FS_NAME_###`     | `POST gpo/shortcuts_out.php`      | `À adapter 17.6` |
| `shortcuts/logoff.windows` (7 lignes)             | logoff      | windows | `###_SE4FS_NAME_###`     | `POST gpo/shortcuts_out.php`      | `À adapter 17.6` |
| `shortcuts/shutdown.windows` (7 lignes)           | shutdown    | windows | `###_SE4FS_NAME_###`     | `POST gpo/shortcuts_out.php`      | `À adapter 17.6` |
| `shortcuts/logon-system.windows` (3 lignes)       | logon-system| windows | aucun                    | aucun                             | `OK iso-legacy` |
| `shortcuts/logon.linux` (3 lignes)                | logon       | linux   | `###_SE4FS_NAME_###`, `DOMAIN` | `POST gpo/shortcuts_out.php` | `À adapter 17.6` |
| `shortcuts/logoff.linux` (3 lignes)               | logoff      | linux   | idem                     | idem                              | `À adapter 17.6` |

Les fichiers `*.inc.json` (`Mes Documents en ligne.inc.json`, `Scripts admin.inc.json`,
`Se deconnecter.inc.json`) sont **lus côté serveur** par `shortcuts_out.php`
pour générer le `.cmd` retourné (catalogues de raccourcis admin/user). **Hors
scope assembly direct** côté Story 17.2 (concerne Story 17.6).

#### `applications/thunderbird/*` — 4 fragments

Pattern strictement identique à Firefox (logon.windows, logon.linux,
startup.windows, startup.linux, wpkg.windows) — endpoints `thunderbird_out.php`,
même placeholders, même statut. **Risque cumulé Firefox+Thunderbird** : 2
endpoints `*_out.php` retournant `policies.json`.

#### `applications/veyon/*` — 4 fragments + `veyon.json`

| Fichier                                  | action        | OS      | Placeholders         | Endpoint               | Statut         |
|------------------------------------------|---------------|---------|----------------------|------------------------|----------------|
| `veyon/startup.windows` (~50 lignes)     | startup       | windows | `###_SE4FS_NAME_###` | `POST gpo/veyon_out.php` | `À adapter 17.6` |
| `veyon/startup.linux` (~15 lignes)       | startup       | linux   | `###_SE4FS_NAME_###`, `DOMAIN` | `POST gpo/veyon_out.php` | `À adapter 17.6` |
| `veyon/logon-system.linux` (5 lignes)    | logon-system  | linux   | aucun                | aucun                  | `OK iso-legacy` |
| `veyon/wpkg.windows` (~12 lignes)        | wpkg          | windows | `###_SE4FS_NAME_###` | `POST gpo/veyon_out.php` | `À adapter 17.6` |

`veyon.json` (~50 lignes) : config par défaut Veyon retournée par `veyon_out.php`.

#### `applications/vscode/logon-system.windows` (4 lignes)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/vscode/logon-system.windows` — 4 lignes                                                                                         |
| **Type d'action**      | `logon-system`                                                                                                                                   |
| **OS cible**           | windows                                                                                                                                          |
| **App rattachée**      | vscode (Visual Studio Code)                                                                                                                      |
| **Interpréteur**       | cmd                                                                                                                                              |
| **Déclencheur poste**  | GPO `se4_applications` logon-system                                                                                                              |
| **Placeholders**       | aucun (`%SE4FS%` env)                                                                                                                            |
| **Endpoints HTTP**     | aucun                                                                                                                                            |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                  |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : silent. MKLINK `.vscode` roaming, similaire à conda.                                                                                  |

#### `applications/wakeonlan/startup.windows` (1 ligne)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/wakeonlan/startup.windows` — 1 ligne                                                                                            |
| **Type d'action**      | `startup`                                                                                                                                        |
| **OS cible**           | windows                                                                                                                                          |
| **App rattachée**      | wakeonlan                                                                                                                                        |
| **Interpréteur**       | cmd (lance ps1 externe)                                                                                                                          |
| **Déclencheur poste**  | GPO `se4_applications` startup                                                                                                                   |
| **Placeholders**       | aucun                                                                                                                                            |
| **Endpoints HTTP**     | aucun directement (le `.ps1` lui-même peut appeler endpoints, à vérifier)                                                                        |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                  |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : silent.                                                                                                                                |

#### `applications/wallpaper/logon.windows` (10 lignes) — **CRITIQUE Story 17.4**

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/wallpaper/logon.windows` — 10 lignes                                                                                                                                              |
| **Type d'action**      | `logon`                                                                                                                                                                                            |
| **OS cible**           | windows                                                                                                                                                                                            |
| **App rattachée**      | wallpaper                                                                                                                                                                                          |
| **Interpréteur**       | cmd                                                                                                                                                                                                |
| **Déclencheur poste**  | GPO `se4_applications` logon                                                                                                                                                                       |
| **Placeholders**       | `###_SE4FS_NAME_###`, **`###_SE4INSTALL_NAME_###`** (hors whitelist ⚠️)                                                                                                                              |
| **Endpoints HTTP**     | `POST gpo/wallpaper_out.php` (×2 : `action=wallpaper` puis `action=veyon`)                                                                                                                          |
| **Statut runtime**     | `À adapter 17.6` (endpoint) + `À adapter 17.2` (placeholder `SE4INSTALL_NAME`).                                                                                                                    |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Logging : `>NUL`. `taskkill /F /IM explorer.exe /FI "USERNAME ne ###_SE4INSTALL_NAME_###"` — sans substitution, le filtre Windows est interprété comme nom littéral → explorer.exe est tué pour TOUS les users y compris la session admin. ⚠️ |

#### `applications/wallpaper/{logoff,startup,startup.linux,logon.linux}.windows/linux`

Voir tableau récapitulatif :

| Fichier                              | action  | OS      | Placeholders                  | Endpoint                            |
|--------------------------------------|---------|---------|-------------------------------|-------------------------------------|
| `wallpaper/logoff.windows` (1 ligne) | logoff  | windows | `###_SE4FS_NAME_###` (REM)    | (commenté) `POST wallpaper_out.php` |
| `wallpaper/startup.windows` (~15 l.) | startup | windows | `###_SE4FS_NAME_###`          | `POST wallpaper_out.php` (×2)       |
| `wallpaper/startup.linux` (~15 l.)   | startup | linux   | `###_SE4FS_NAME_###`, `DOMAIN`| `POST wallpaper_out.php`            |
| `wallpaper/logon.linux` (~25 l.)     | logon   | linux   | `###_SE4FS_NAME_###`, `DOMAIN`| `POST wallpaper_out.php` (×2)       |

Tous statut `À adapter 17.6`. Logging : silent (echo Linux, `>NUL` Windows).

#### `applications/wine/startup.linux` (~12 lignes)

| Champ                  | Valeur                                                                                                                                                |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/wine/startup.linux` — ~12 lignes                                                                                                      |
| **Type d'action**      | `startup`                                                                                                                                              |
| **OS cible**           | linux                                                                                                                                                  |
| **App rattachée**      | wine                                                                                                                                                   |
| **Interpréteur**       | bash                                                                                                                                                   |
| **Déclencheur poste**  | systemd boot                                                                                                                                            |
| **Placeholders**       | **`###_SE4INSTALL_NAME_###`** (hors whitelist ⚠️)                                                                                                       |
| **Endpoints HTTP**     | aucun                                                                                                                                                  |
| **Statut runtime**     | `À adapter 17.2` — `set_config sambaedu se4install_name ###_SE4INSTALL_NAME_###` enregistre une string non substituée comme nom de serveur install.    |
| **Surcharges admin**   | non rencontrée                                                                                                                                         |
| **Risques / logging**  | Logging : echo stdout. `dpkg --add-architecture i386` (effet permanent).                                                                              |

#### `applications/winget/startup.windows` (~5 lignes)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/winget/startup.windows` — ~5 lignes                                                                                             |
| **Type d'action**      | `startup`                                                                                                                                        |
| **OS cible**           | windows                                                                                                                                          |
| **App rattachée**      | winget                                                                                                                                           |
| **Interpréteur**       | cmd                                                                                                                                              |
| **Déclencheur poste**  | GPO `se4_applications` startup                                                                                                                   |
| **Placeholders**       | aucun                                                                                                                                            |
| **Endpoints HTTP**     | aucun direct (lance les `.ps1` package)                                                                                                          |
| **Statut runtime**     | `OK iso-legacy`                                                                                                                                  |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : silent. icacls reset sur `%ProgramFiles%\WinGet\Packages\*`.                                                                          |

Les fichiers `winget/add.json` et `winget/remove.json` sont **catalogues de
paquets winget** lus côté serveur (`/wpkg/winget_out.php`), pas assemblés
dans le `.cmd` orchestrateur.

#### `applications/wpkg/startup.windows` (3 lignes) — **CRITIQUE Story 17.4** + déclencheur infra

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/wpkg/startup.windows` — 3 lignes                                                                                                                                                  |
| **Type d'action**      | `startup`                                                                                                                                                                                          |
| **OS cible**           | windows                                                                                                                                                                                            |
| **App rattachée**      | wpkg (WPKG bootstrap côté poste)                                                                                                                                                                   |
| **Interpréteur**       | cmd                                                                                                                                                                                                |
| **Déclencheur poste**  | GPO `se4_applications` startup                                                                                                                                                                     |
| **Placeholders**       | aucun                                                                                                                                                                                              |
| **Endpoints HTTP**     | aucun direct                                                                                                                                                                                       |
| **Statut runtime**     | `Déjà couvert 16.6` (sa chaîne d'invocation aval est gérée par 16.6).                                                                                                                              |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Logging : silent. Ce script `ROBOCOPY %WinDir%\install\os\SambaEdu → %ProgramFiles%\SambaEdu` — c'est le **mécanisme de déploiement** de tous les scripts `install/os/SambaEdu/*.ps1` côté poste. ⚠️ |

#### `applications/wpkg/startup.linux` (~15 lignes)

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/applications/wpkg/startup.linux` — ~15 lignes                                                                                                                                                  |
| **Type d'action**      | `startup`                                                                                                                                                                                          |
| **OS cible**           | linux                                                                                                                                                                                              |
| **App rattachée**      | wpkg                                                                                                                                                                                               |
| **Interpréteur**       | bash                                                                                                                                                                                               |
| **Déclencheur poste**  | systemd boot                                                                                                                                                                                       |
| **Placeholders**       | `###_SE4FS_NAME_###`, `###_DOMAIN_###`                                                                                                                                                              |
| **Endpoints HTTP**     | `POST http://###_SE4FS_NAME_###.###_DOMAIN_###/wpkg/linux_out.php` (récupère liste paquets apt)                                                                                                    |
| **Statut runtime**     | `À adapter 17.6` — endpoint `wpkg/linux_out.php` non porté par 16.7 ni Epic 15.                                                                                                                    |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Logging : echo stdout. `DEBIAN_FRONTEND=noninteractive apt-get install -y -q $p` boucle (effet : installation de tous les paquets renvoyés par l'endpoint).                                       |

### A.2 — Scripts critiques WPKG (chaîne d'invocation Story 16.6)

#### `var/sambaedu/unattended/install/wpkg/wpkg.cmd` (21 lignes)

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `/home/htouchard/code/irundo/se4/sources/var/sambaedu/unattended/install/wpkg/wpkg.cmd` — 21 lignes                                                                                                |
| **Type d'action**      | `startup` (déposé via GPO `se4_wpkg` legacy + Story 16.6 native)                                                                                                                                   |
| **OS cible**           | windows                                                                                                                                                                                            |
| **App rattachée**      | infra WPKG (pas un fragment `applications/`)                                                                                                                                                       |
| **Interpréteur**       | cmd                                                                                                                                                                                                |
| **Déclencheur poste**  | GPO `se4_wpkg` startup (gérée par Story 16.6)                                                                                                                                                       |
| **Placeholders**       | `###_SE4FS_NAME_###` (whitelisté + 16.6 a documenté)                                                                                                                                                |
| **Endpoints HTTP**     | aucun direct — l'appel HTTP se produit dans `wpkg-client.vbs` → `wpkg-se4.js`                                                                                                                       |
| **Statut runtime**     | `Déjà couvert 16.6` ✅                                                                                                                                                                              |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Logging : silent (`>NUL 2>&1` sur le cscript). Crée le MKLINK `%Windir%\install` → `\\%SE4FS%\install` et `%Windir%\rapports` → `\\%SE4FS%\rapports` (chemins SMB). Si SMB indisponible : tout casse. |

#### `var/sambaedu/unattended/install/wpkg/wpkg-client.vbs` (1 038 lignes)

| Champ                  | Valeur                                                                                                                                                                                            |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/wpkg-client.vbs` — 1 038 lignes (VBScript)                                                                                                                                                     |
| **Type d'action**      | infra — exécution chaînée par `wpkg.cmd`                                                                                                                                                            |
| **OS cible**           | windows                                                                                                                                                                                            |
| **App rattachée**      | infra WPKG                                                                                                                                                                                         |
| **Interpréteur**       | cscript.exe (VBScript)                                                                                                                                                                              |
| **Déclencheur poste**  | `wpkg.cmd` ligne 20 : `cscript.exe //B //NoLogo %WinDir%\wpkg-client.vbs /NOTempo`                                                                                                                  |
| **Placeholders**       | aucun (VBScript autonome ; consomme env `%SE4FS%`)                                                                                                                                                  |
| **Endpoints HTTP**     | aucun direct — invoque `wpkg-se4.js` (`Z:\wpkg\wpkg-se4.js`) qui fait les requêtes HTTP                                                                                                              |
| **Statut runtime**     | `Hors scope Epic 17` — fork upstream wpkg.js, non modifié par SambaEdu.                                                                                                                            |
| **Surcharges admin**   | non rencontrée                                                                                                                                                                                     |
| **Risques / logging**  | Logging : `c:\windows\rapports\<computer>.log` (fichier local + recopie SMB vers `\\SE4FS\rapports\`). **Granularité ligne-par-ligne** (cf. `print` interne, append à `fLocal`). Possède un **`watchDog` de 18 000 secondes** (5 heures !) — long-running. |

#### `var/sambaedu/unattended/install/wpkg/wpkg-se4.js` (11 281 lignes)

| Champ                  | Valeur                                                                                                                                          |
|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**     | `…/wpkg-se4.js` — 11 281 lignes (fork upstream wpkg.js)                                                                                          |
| **Type d'action**      | infra — synchronisation WPKG côté client                                                                                                         |
| **OS cible**           | windows                                                                                                                                          |
| **App rattachée**      | infra WPKG (le `wpkg.js` mentionné dans 16.6)                                                                                                    |
| **Interpréteur**       | cscript.exe (JScript)                                                                                                                            |
| **Déclencheur poste**  | `wpkg-client.vbs` ligne 296                                                                                                                       |
| **Placeholders**       | aucun (config dans le code JS via `wpkg_base = "http://" + se4fs + "/wpkg"`)                                                                     |
| **Endpoints HTTP**     | `GET /wpkg/hosts.xml`, `GET /wpkg/profiles.xml`, `GET /wpkg/packages.xml` — couverts par Story 15.2 et 16.6                                       |
| **Statut runtime**     | `Hors scope Epic 17` ✅ (frontière 16.6).                                                                                                          |
| **Surcharges admin**   | non rencontrée                                                                                                                                   |
| **Risques / logging**  | Logging : sortie stdout cscript (capturée par `wpkg-client.vbs:fLocal`). Voir 16.6 pour l'analyse détaillée.                                    |

### A.3 — Scripts `var/sambaedu/unattended/install/os/SambaEdu/*` (échantillon 5/29)

Ces ≈ 29 fichiers sont **déployés** par `wpkg/startup.windows` via
`ROBOCOPY %WinDir%\install\os\SambaEdu %ProgramFiles%\SambaEdu` puis exécutés
au runtime par `powershellTask.ps1` (programme ScheduledTask) ou directement
par les fragments `applications/`. **Décision in/out scope** : in-scope
**observabilité Epic 17**, **out-of-scope assembly Story 17.2** (ils ne sont pas
concaténés dans le `.cmd` orchestrateur — ils sont des binaires/scripts livrés
en runtime). Cf. Section H point 3.

| Fichier                                          | Type                              | Statut Epic 17                                                                            |
|--------------------------------------------------|-----------------------------------|-------------------------------------------------------------------------------------------|
| `install.ps1` (~lignes inconnues — pas lu)       | bootstrap au premier boot         | `Hors scope assembly` — exécuté par `wpkg.cmd` ligne 15.                                  |
| `powershellTask.ps1` (37 lignes)                 | infra ScheduledTask wrapper       | `Hors scope assembly` — invoqué depuis `applications.inc.php:458` (`powershell_scripts()`).|
| `SetWallpaper.ps1` (~30 lignes)                  | runtime wallpaper poste           | `Hors scope assembly` — invoqué par `wallpaper/logon.windows`.                              |
| `winget-install.ps1` (~hundreds lignes)          | runtime install winget            | `Hors scope assembly` mais **endpoint serveur `wpkg/winget_out.php`** ⚠️ (non porté 16.7). |
| `sysprep.ps1` (5 lignes)                         | bootstrap clonage (iPXE)          | `Hors scope runtime` — exécuté en iPXE pré-déploiement.                                    |

**Autres fichiers du dossier `install/os/SambaEdu/`** (référence rapide, non
détaillée individuellement) : `associations.ps1`, `driversAuto.ps1`, `no_internet.ps1`,
`PingSE4.ps1`, `pin.ps1`, `rdpvideo.ps1`, `SFTA.ps1`, `veyon-master.ps1`,
`wakeonlan.ps1`, `winget.ps1`, plus les `.cmd` admin (`Nettoyage *.cmd`,
`Diagnostic GPOs - GPResult.cmd`, `Installation outils RSAT.cmd`,
`Configurer les associations aux applications par defaut.cmd`,
`wintail.exe/.lnk`). **Pour Epic 17**, ces fichiers ne sont pas portés ; ils
sont simplement livrés à l'identique par le package Debian.

**Cas particulier `Nettoyage applications-*.cmd`** (3 fichiers `.cmd`) :
contiennent **directement** les requêtes `curl … applications.php` orchestratrices —
ils sont les outils admin équivalents au `.cmd` que la GPO `se4_applications`
dépose. Important : ils confirment le **contrat HTTP** de l'endpoint (méthode
POST avec form-fields `os`, `action`, `user`, `machine`).

---

## Section B — Catalogue des placeholders `###_PARAMETRE_###`

> Extraction par `grep -rohE '###_[A-Z0-9_]+_###'` sur l'arborescence
> `usr/share/sambaedu/applications/` + `var/sambaedu/unattended/`.
> **11 placeholders distincts** identifiés au total.

| Placeholder              | Whitelist 16.7 ? | Scripts consommateurs (Section A)                                                                                  | Source legacy config                          | Source équivalente native                                | Risque d'oubli 17.2  |
|--------------------------|------------------|---------------------------------------------------------------------------------------------------------------------|-----------------------------------------------|----------------------------------------------------------|----------------------|
| `###_SE4FS_NAME_###`     | ✅ OUI            | 24 scripts (firefox, thunderbird, veyon, wallpaper, shortcuts, rclone, wpkg, reseau, …)                              | `$config['se4fs_name']`                       | `config('sambaedu.se4fs_name')` (whitelist clé)          | **NON**              |
| `###_DOMAIN_###`         | ✅ OUI            | 11 scripts Linux principalement (firefox.linux, thunderbird.linux, rclone.linux, wpkg.linux, network.bash, …)        | `$config['domain']`                           | `config('sambaedu.domain')`                              | **NON**              |
| `###_UAI_###`            | ✅ OUI            | `glpi/startup.linux` (1 script)                                                                                      | `$config['UAI']`                              | `config('sambaedu.uai')`                                 | **NON**              |
| `###_ADMINSE_NAME_###`   | ❌ NON ⚠️         | `folders/clean_profiles` (1 script, action startup)                                                                  | `$config['adminse_name']` legacy              | **à ajouter** — clé `ADMINSE_NAME` → `config('sambaedu.adminse_name')` | **OUI — BLOQUANT** |
| `###_DHCP_MASQUE_###`    | ❌ NON ⚠️         | `firewall/startup.windows` (1 script)                                                                                | `$config['dhcp_masque']` legacy               | **à ajouter** — `config('sambaedu.dhcp_masque')` ou `config('sambaedu.network.dhcp_netmask')` | **OUI — BLOQUANT** |
| `###_DHCP_RESEAU_###`    | ❌ NON ⚠️         | `firewall/startup.windows` (1 script)                                                                                | `$config['dhcp_reseau']` legacy               | **à ajouter**                                            | **OUI — BLOQUANT**   |
| `###_GLPI_URL_###`       | ❌ NON ⚠️         | `glpi/startup.linux` (1 script)                                                                                      | `$config['glpi_url']` legacy                  | **à ajouter** — `config('sambaedu.glpi_url')`            | **OUI — BLOQUANT**   |
| `###_NO_INTERNET_###`    | ❌ NON ⚠️         | `firewall/startup.windows`, `firewall/logon-system.windows` (2 scripts)                                              | `$config['no_internet']` legacy (boolean ?)   | **à ajouter** — `config('sambaedu.no_internet', '')`     | **OUI — BLOQUANT**   |
| `###_SE4AD_IP_###`       | ❌ NON ⚠️         | `firewall/startup.windows` (1 script)                                                                                | `$config['se4ad_ip']` legacy                  | **à ajouter** — `config('sambaedu.se4ad.ip')` ou similaire | **OUI — BLOQUANT** |
| `###_SE4FS_IP_###`       | ❌ NON ⚠️         | `firewall/startup.windows` (1 script)                                                                                | `$config['se4fs_ip']` legacy                  | **à ajouter** — `config('sambaedu.se4fs.ip')`            | **OUI — BLOQUANT**   |
| `###_SE4INSTALL_NAME_###`| ❌ NON ⚠️         | `wallpaper/logon.windows`, `wine/startup.linux` (2 scripts)                                                          | `$config['se4install_name']` legacy           | **à ajouter** — `config('sambaedu.se4install_name')`     | **OUI — BLOQUANT**   |

### Placeholders whitelist 16.7 **NON utilisés** par les scripts upstream

| Placeholder whitelist 16.7 | Utilisé dans les scripts upstream ? | Décision recommandée                                                                          |
|-----------------------------|-------------------------------------|-----------------------------------------------------------------------------------------------|
| `###_NETLOGON_PATH_###`     | ❌ NON                              | Conserver dans la whitelist — utilisé par `header_scripts()` ou autres (à vérifier 16.7).      |
| `###_WPKG_URL_###`          | ❌ NON                              | Conserver — peut être consommé par scripts admin non-upstream.                                |
| `###_SAMBA_DOMAIN_###`      | ❌ NON                              | Conserver — utilisé par `local_admin_scripts()`.                                              |
| `###_TMP_DIR_###`           | ❌ NON                              | Conserver — utilisé serveur-side.                                                              |
| `###_CLOUD_PERSO_NAME_###`  | ❌ NON dans `applications/` upstream | Conserver — peut être substitué par le serveur via `config['cloud_perso_name']` directement (cf. `make_application_scripts:290`). |

### Synthèse Section B

- **Whitelist 16.7 doit être étendue** de **6 nouvelles clés** : `ADMINSE_NAME`,
  `DHCP_MASQUE`, `DHCP_RESEAU`, `GLPI_URL`, `NO_INTERNET`, `SE4AD_IP`,
  `SE4FS_IP`, `SE4INSTALL_NAME` (8 clés au total nouvelles ; comptage : il y a 8
  placeholders hors whitelist, qui correspondent à 8 clés à ajouter — soit 16
  clés au total après extension).
- Action immédiate **17.2** : compléter `config/sambaedu.gpo.applications.substitutions.php`
  + mapper `config/sambaedu.php` (ou `env`) pour chaque nouvelle clé.
- Tests : story 17.4 doit valider que pour chaque placeholder, la substitution
  produit la valeur attendue iso-legacy (parité bytes du `.cmd` final).

---

## Section C — Catalogue des endpoints HTTP serveur invoqués

> Extraction par `grep -rE '(curl|wget|Invoke-WebRequest|cscript|http://)'`
> sur les scripts upstream. **11 endpoints HTTP distincts** identifiés.

| Endpoint                                                | Méthode | Form-data envoyées                          | Côté legacy                          | Côté natif Epic 15/16/17                                | Statut compat   | Scripts appelants (Section A)                                              |
|---------------------------------------------------------|---------|---------------------------------------------|--------------------------------------|---------------------------------------------------------|-----------------|-----------------------------------------------------------------------------|
| `POST gpo/applications.php`                             | POST    | `os`, `action`, `user`, `machine`, `id`, `ret`, `uuid`, `speed`, `interpreter`, `userprofile` | `sambaedu/gpo/applications.php` (51 lignes)         | **Story 16.7** native — `gpo.applications.legacy` (throttle 300/min) ✅ | `OK natif`      | **Tous les `.cmd` orchestrateurs** déposés par GPO `se4_applications` (cf. `Nettoyage applications-*.cmd` ; et les footers de scripts assemblés cf. `applications.inc.php:423`). |
| `POST gpo/firefox_out.php`                              | POST    | `id`, `os`                                  | `sambaedu/gpo/firefox_out.php`        | **Non porté** (16.7 ne couvre qu'`applications.php`)   | `À adapter 17.6`| `firefox/startup.windows`, `firefox/startup.linux`, `firefox/wpkg.windows`. |
| `POST gpo/thunderbird_out.php`                          | POST    | `id`                                        | `sambaedu/gpo/thunderbird_out.php`    | **Non porté**                                          | `À adapter 17.6`| `thunderbird/startup.windows`, `thunderbird/startup.linux`, `thunderbird/wpkg.windows`. |
| `POST gpo/veyon_out.php`                                | POST    | `id`, `licence`                             | `sambaedu/gpo/veyon_out.php`          | **Non porté**                                          | `À adapter 17.6`| `veyon/startup.windows`, `veyon/startup.linux`, `veyon/wpkg.windows`.       |
| `POST gpo/wallpaper_out.php`                            | POST    | `action`, `id`, `format`                    | `sambaedu/gpo/wallpaper_out.php`      | **Non porté**                                          | `À adapter 17.6`| `wallpaper/{logon,startup,logoff}.windows`, `wallpaper/{logon,startup}.linux`. |
| `POST gpo/shortcuts_out.php`                            | POST    | `os`, `id`, `action`                        | `sambaedu/gpo/shortcuts_out.php`      | **Non porté** ⚠️ mais `ShortcutsService` natif existe (cf. memory 1bis.18) | `À adapter 17.6`| `shortcuts/{logon,logoff,startup,shutdown}.windows`, `shortcuts/{logon,logoff}.linux`. |
| `POST gpo/network_out.php`                              | POST    | `id`, `action`, `os`                        | `sambaedu/gpo/network_out.php`        | **Non porté**                                          | `À adapter 17.6`| `reseau/networkManager.bash`, `reseau/gnome.bash`.                          |
| `POST partages/cloud_out.php`                           | POST    | `id`, `os`                                  | `sambaedu/partages/cloud_out.php`     | **Non porté**                                          | `À adapter 17.6`| `rclone/logon.windows`, `rclone/logon.linux`.                               |
| `POST wpkg/linux_out.php`                               | POST    | `id`                                        | `sambaedu/wpkg/linux_out.php`         | **Non porté** ⚠️                                       | `À adapter 17.6`| `wpkg/startup.linux`.                                                       |
| `POST wpkg/winget_out.php`                              | POST    | `machine`, `list`, `action`                 | `sambaedu/wpkg/winget_out.php`        | **Non porté** ⚠️                                       | `À adapter 17.6`| `winget-install.ps1` (déployé via `install/os/SambaEdu/`).                  |
| `POST /logs.php`                                        | POST    | (form-data variées)                         | `sambaedu/logs.php`                   | **Non porté** ⚠️ (cf. Section I)                       | `À adapter 17.5`| `reseau/networkInfo.ps1`.                                                   |

### Endpoints couverts par Story 16.6 (chaîne WPKG)

Pour mémoire (cf. fiche `wpkg-se4.js` en Section A) :

| Endpoint                            | Couverture                                     |
|-------------------------------------|------------------------------------------------|
| `GET /wpkg/hosts.xml?poste=X`       | Story 15.2 ✅                                  |
| `GET /wpkg/profiles.xml?poste=X`    | Story 15.2 ✅                                  |
| `GET /wpkg/packages.xml`            | Servi par legacy (à vérifier Epic 15)          |

### Synthèse Section C

- **1 endpoint déjà porté** (Story 16.7) : `applications.php`.
- **10 endpoints à porter** (Story 17.6 proposée). 6 d'entre eux sont des
  `*_out.php` retournant un `.cmd`/`.json`/`.vlf` consommé par les fragments
  `applications/*`.
- **Tous les endpoints sont en HTTP (port 80)** — pas de HTTPS. Risque sécurité
  hérité, à signaler (man-in-the-middle dans le LAN école est plausible). Mais
  iso-legacy : ne pas changer dans Epic 17.

---

## Section D — Flux d'invocation côté poste

### D.1 — Cas Windows (cycle boot + user session)

```
[Poste Windows boot]
       │
       ▼
GPO `se4_applications` (créée par Story 16.5 — DONE)
       │  dépose : Machine\Scripts\Startup\applications-startup.cmd
       ▼  dépose : User\Scripts\Logon\applications-logon.cmd
       │  dépose : User\Scripts\Logoff\applications-logoff.cmd
       │  dépose : Machine\Scripts\Shutdown\applications-shutdown.cmd
       │
       │   (Ces `.cmd` orchestrateurs sont identiques à
       │    /home/htouchard/code/irundo/se4/sources/var/sambaedu/unattended/
       │    install/os/SambaEdu/Nettoyage applications-startup.cmd)
       │
       ▼
applications-startup.cmd (à exécution startup machine)
   │
   ├─► curl.exe -F "os=windows" -F "action=startup"
   │       -F "user=%username%" -F "machine=%computername%"
   │       -F "id=<calc>" -F "ret=1"
   │       "http://se4fs/gpo/applications.php"
   │       → écrit la réponse dans %temp%\applications-startup.cmd
   │
   │   ┌────────────────── SERVEUR (legacy ou Story 16.7 natif) ──────────────────┐
   │   │ gpo/applications.php (51 lignes legacy / route Laravel native 16.7)      │
   │   │   ├─► get_app_scripts_info() : parse $_POST, calc $id = md5(user+machine+action) │
   │   │   ├─► log_application_scripts() : INSERT/UPDATE `machines`+`connexions`  │
   │   │   ├─► read_application_scripts() : scan FS `usr/share/sambaedu/applications/` │
   │   │   │   et `etc/sambaedu/applications/` (priorité local), parse scripts.json │
   │   │   │   redirects.json, fragments `.windows`/`.linux`, packages.list        │
   │   │   ├─► make_application_scripts() : filtre par groupe (includes/excludes/  │
   │   │   │   includes_apps/excludes_apps), assemble le `.cmd` dans l'ordre :     │
   │   │   │     1. header (SET DOMAINSID, SET TAG=salle,UAI, SET id, SET SE4FS)   │
   │   │   │     2. local_admin / sudo / once selon context                        │
   │   │   │     3. fragments matching action+os+groupe                            │
   │   │   │     4. apt_scripts (Linux uniquement)                                 │
   │   │   │     5. powershell_scripts (DL + exec ps1 séparé)                      │
   │   │   │     6. footer (curl `ret=0` callback à `applications.php`)            │
   │   │   ├─► write_param() : substitution `###_KEY_###` → valeur config         │
   │   │   └─► echo $out['cmd' ou 'bash'] → renvoyé au poste                      │
   │   └──────────────────────────────────────────────────────────────────────────┘
   │
   ├─► call %temp%\applications-startup.cmd
   │     (exécute tous les fragments concaténés ; chacun fait éventuellement
   │      ses propres curl vers les *_out.php endpoints)
   │
   └─► curl `ret=0` callback (envoyé en footer du `.cmd` assemblé)
         → applications.php enregistre la fin d'exécution → log_connexion()
            INSERT into `connexions` (logintime) / UPDATE `machines` (starttime, error)

[Poste Windows logon utilisateur]
   │
   └─► applications-logon.cmd (idem, action=logon, user=<USERNAME>)
         + applications-logon-system.cmd (élévation system pour MKLINK, etc.)

[Poste Windows shutdown]
   └─► applications-shutdown.cmd

[Chaîne WPKG indépendante — Story 16.6]
   │
   ▼
GPO `se4_wpkg` (créée par Story 16.6 native)
   └─► applications/wpkg/startup.windows
         → ROBOCOPY %WinDir%\install\os\SambaEdu %ProgramFiles%\SambaEdu
         → wpkg.cmd (var/sambaedu/unattended/install/wpkg/)
              → SETX SE4FS …
              → MKLINK \\SE4FS\install
              → install.ps1 (pwsh)
              → cscript wpkg-client.vbs
                  → wpkg-se4.js (synchronisation packages)
                       → GET /wpkg/hosts.xml  (Story 15.2)
                       → GET /wpkg/profiles.xml (Story 15.2)
                       → GET /wpkg/packages.xml (legacy)
```

### D.2 — Cas Linux (boot + user session)

```
[Linux boot]
       │
       ▼
systemd → service `sambaedu-startup.service` (ou équivalent)
   │  (déposé par package `sambaedu` upstream, exécution iso-legacy)
   │
   ▼
applications-startup.sh (équivalent du .cmd Windows)
   │
   ├─► curl -F "os=linux" -F "action=startup"
   │       -F "user=root" -F "machine=$(hostname)"
   │       -F "id=<calc>" -F "ret=1"
   │       http://se4fs.domain/gpo/applications.php
   │       → écrit la réponse dans /tmp/applications-startup.sh
   │
   └─► bash /tmp/applications-startup.sh
         → header bash (SE4FS=…, id=…, URL=…)
         → fragments .linux assemblés
         → footer curl ret=0

[Linux user session (gdm/lightdm)]
   │
   └─► PAM → applications-logon.sh (action=logon, user=$USER)
```

### D.3 — Note diagrammatique sur les surcharges admin

Cf. Section E. Le scanner serveur (legacy comme natif 16.7) lit d'abord
`/usr/share/sambaedu/applications/` (package) puis `/etc/sambaedu/applications/`
(local). En cas de collision sur même `(os, action, app, name, context, remote,
interpreter, file)`, le local **surcharge** le package (merge `includes/excludes`
+ remplace `script`).

---

## Section E — Couche surcharges admin `/etc/sambaedu/applications/`

### Mécanisme

Le scanner legacy (`applications.inc.php:39-189`) et son portage natif 16.7
(`app/Gpo/Services/ApplicationTemplatesScanner.php:55-323`) implémentent
**rigoureusement** le même mécanisme :

1. Scan **`/usr/share/sambaedu/applications/`** (chemin package, lu en premier).
2. Scan **`/etc/sambaedu/applications/`** (chemin local, lu en second).
3. Pour chaque dossier `<app>/` :
   - Lecture `scripts.json` si présent → entrées JSON.
   - Lecture `redirects.json` si présent → entrées redirects.
   - Scan des fichiers `<action>[-<context>][@<group>].{windows,linux}`
     et `packages[@<group>].list`.
4. **Merge** par hash sha256 sur `(os, action, app, name, context, remote,
   interpreter, file)` : le local gagne (remplace `script`, fusionne `includes`
   et `excludes`).
5. Application des filtres `includes`/`excludes` par groupe AD + filtres
   `includes_apps`/`excludes_apps` par app installée.

### Confirmation : Story 16.7 préserve déjà ce mécanisme

✅ Vérifié dans `app/Gpo/Services/ApplicationTemplatesScanner.php` ligne 55-69 :

```php
$paths = [
    'package' => $packagePath ?? self::DEFAULT_PACKAGE_PATH,  // /usr/share/sambaedu/applications/
    'local'   => $localPath ?? self::DEFAULT_LOCAL_PATH,      // /etc/sambaedu/applications/
];
```

Le merge incremental par hash (ligne 289-323) **réplique fidèlement** la
fonction legacy `merge_applications()`. Les valeurs locales surchargent bien
les valeurs package.

### Fréquence d'usage chez les clients

**L'audit ne peut pas trancher en autonomie** — c'est une information terrain
que seul Henri (ou un opérateur SER) connaît. Hypothèses raisonnables :

- **Surcharges très fréquentes** chez les écoles qui ont des besoins
  spécifiques de stratégie locale (ex. désactivation Veyon dans une salle
  particulière, redirections de profil custom pour un parc spécifique).
- **Surcharges rares** chez les écoles standards qui se contentent du package.

→ **Question pour Henri** (cf. liste finale) : « Quelle est la fréquence
d'usage réelle de la couche `/etc/sambaedu/applications/` chez les clients ?
Faut-il prévoir un mécanisme d'UI native pour cette couche dans une story
future ? »

### Recommandation pour Story 17.2

**Conserver cette priorité dans le moteur natif** (déjà fait par 16.7).
Aucun changement requis. **Couvrir un test d'intégration** dans 17.4 qui crée
un fichier dans `/etc/sambaedu/applications/<app>/<action>.windows` et vérifie
qu'il surcharge bien la version package.

---

## Section F — Risques de rupture au croisement Epic 15 + Epic 16 + Epic 17

| #  | Risque                                                                                                          | Cause racine                                                       | Épopée concernée    | Story de mitigation | Sévérité    |
|----|-----------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------|---------------------|---------------------|-------------|
| F1 | **Firewall casse parc-wide** : `netsh advfirewall firewall add rule … remoteip=###_SE4FS_IP_###` rejeté            | Whitelist 16.7 ne contient pas `SE4FS_IP`, `SE4AD_IP`              | Epic 17             | **17.2 (étendue)**  | **BLOQUANTE**   |
| F2 | **Admin local supprimé** : `clean_profiles` supprime `C:\Users\###_ADMINSE_NAME_###` car string non substituée  | Whitelist 16.7 ne contient pas `ADMINSE_NAME`                       | Epic 17             | **17.2 (étendue)**  | **BLOQUANTE**   |
| F3 | **explorer.exe killé en boucle** : `taskkill /F /IM explorer.exe /FI "USERNAME ne ###_SE4INSTALL_NAME_###"`     | Whitelist 16.7 ne contient pas `SE4INSTALL_NAME`                    | Epic 17             | **17.2 (étendue)**  | **BLOQUANTE**   |
| F4 | **GLPI Agent désorienté** : `server = ###_GLPI_URL_###` → config invalide → inventaire perdu                    | Whitelist 16.7 ne contient pas `GLPI_URL`                           | Epic 17             | **17.2 (étendue)**  | **DÉGRADÉE**    |
| F5 | **`no_internet` toujours actif** : `IF NOT [###_NO_INTERNET_###]==[]` toujours vrai                            | Whitelist 16.7 ne contient pas `NO_INTERNET`                        | Epic 17             | **17.2 (étendue)**  | **DÉGRADÉE**    |
| F6 | **Wine install pollue serveur "install"** : `set_config sambaedu se4install_name ###_SE4INSTALL_NAME_###`       | Whitelist 16.7 ne contient pas `SE4INSTALL_NAME` (déjà signalé F3) | Epic 17             | **17.2 (étendue)**  | **DÉGRADÉE**    |
| F7 | **10 endpoints `*_out.php` orphelins** : si legacy désinstallé, les `curl` reçoivent 404 → comportement dégradé | 16.7 ne couvre qu'`applications.php` — pas les 10 endpoints aval    | Epic 17             | **17.6 (nouvelle)** | **DÉGRADÉE**    |
| F8 | **Encodage CP1252 vs UTF-8** : Story 16.7 documente déjà ce risque (cf. ligne 21 du config). À surveiller en 17.4 | Cmd Windows = CP1252 ; bash Linux = UTF-8                          | Epic 17             | **17.4 (tests)**    | **MINEURE**     |
| F9 | **GPO `se4_applications` non gérée nativement** : on crée la GPO `se4_wpkg` (Story 16.6) mais pas `se4_applications` | Epic 16 a couvert `se4_wpkg` (16.6) mais pas l'orchestratrice apps  | Epic 16/17          | **17.3 + 16.5**     | **BLOQUANTE**   |
| F10| **Couche surcharges admin perdue si migration mal faite** : `/etc/sambaedu/applications/` peut être oublié lors du déploiement natif | Mauvais setup déploiement                            | Epic 17             | **17.4 (test E2E)** | **DÉGRADÉE**    |
| F11| **APCu stub non disponible** : `apcu_fetch/apcu_store` utilisé par `make_application_scripts()` (cache 'apps.', 'scripts.') | Memory todo `apcu-stub-logs` (mode dégradé)         | Epic 17 + shim 1bis | **17.2** (option: désactiver le cache APCu côté natif Laravel) | **MINEURE** |
| F12| **`wpkg-client.vbs` watchDog 5 heures** : si l'endpoint serveur traîne, le poste est bloqué 5h sans feedback   | Hardcoded `watchDog = 18000`                                       | Hors Epic 17        | (signaler à upstream) | **MINEURE** |
| F13| **`policies.json` Firefox vide en cas d'endpoint en panne** : `curl -o … >NUL` écrase le fichier existant     | Pas de `--fail` sur le curl côté script                            | Epic 17             | **17.6 (mitigation côté endpoint)** | **MINEURE** |
| F14| **`shortcuts/logoff.windows` ligne 2 — bug latent** : `"-F" "http://… "` (sans `=` → curl le traite comme URL) | Bug upstream                                                       | Hors Epic 17        | (signaler à upstream) | **MINEURE** |
| F15| **`Filius/redirects.json` + `OnlyOffice/redirects.json` non audités en détail** — risque résiduel              | Pas de lecture exhaustive (échantillonnage)                        | Epic 17             | **17.1 follow-up**  | **MINEURE**     |

### Synthèse Section F

- **3 risques BLOQUANTS** (F1, F2, F3, F9) — tous attribuables soit à la
  whitelist incomplète (17.2 étendue), soit à l'absence de gestion native de
  la GPO `se4_applications` (17.3 + 16.5).
- **5 risques DÉGRADÉS** (F4, F5, F6, F7, F10).
- **5 risques MINEURS** (F8, F11, F12, F13, F14, F15).

---

## Section G — Recommandations de découpage pour 17.2, 17.3, 17.4 (+ 17.5, 17.6)

> **⚠ Patch 2026-05-21** : cette section a été révisée après l'achèvement complet
> de l'Epic 16. Les estimations initiales étaient conservatives (Epic 17 cadré
> à ~10-14j) ; le périmètre réel post-Epic-16 est de **~7-9j** grâce aux livrables
> 16.12 (infra logs) et 16.13 (API v1 endpoints). Les sous-sections G.4-G.5
> intègrent ces économies. Le récapitulatif G.6 a été refait.

### G.1 — Story 17.2 (portage natif moteur `applications.php`)

**Périmètre** :

- Story 16.7 a livré le **portage du squelette** (`ApplicationScriptsGenerator`,
  `ApplicationScriptsAssembler`, `ApplicationTemplatesScanner`,
  `ApplicationsScriptsController`, whitelist initiale 8 clés).
- Story 17.2 doit **compléter** ce travail avec :
  1. **Élargissement whitelist** : ajouter 8 clés `ADMINSE_NAME`, `DHCP_MASQUE`,
     `DHCP_RESEAU`, `GLPI_URL`, `NO_INTERNET`, `SE4AD_IP`, `SE4FS_IP`,
     `SE4INSTALL_NAME` à `config/sambaedu.gpo.applications.substitutions.php`.
  2. **Mapper les sources Eloquent / config / env** pour chaque nouvelle clé
     (à arbitrer avec Henri : `config('sambaedu.network.dhcp_netmask')` vs
     query LDAP/AD vs config statique).
  3. **Enveloppe logging** (cf. Section I — peut être 17.5 séparée).
  4. **Audit de parité bytes** : exécuter le moteur natif vs legacy avec un
     même contexte (machine, user, action, os) et comparer byte-à-byte le
     `.cmd` retourné. Toute divergence = bug.

**Frontière** : 17.2 ne touche pas aux endpoints `*_out.php` (→ 17.6) ni à la
GPO `se4_applications` (→ 17.3).

### G.2 — Story 17.3 (compat GPO orchestratrice `se4_applications`)

> **⚠ Patch 2026-05-21** : Stratégie B initialement recommandée invalidée par
> l'annulation de Story 16.4 (création/duplication native GPO) le 2026-05-18.
> Fallback **Stratégie A confirmée par Henri** : modèle « download GPO repo +
> set direct à l'install serveur » déjà opérationnel pour `se4_wpkg`
> (cf. `WpkgGpoSynchronizer` 16.6 + template `/usr/share/sambaedu/gpo/se4_wpkg.zip`).
> Le même pattern s'applique pour `se4_applications`.

**Périmètre (Stratégie A confirmée)** :

- Le template `se4_applications` est fourni par le package Debian sambaedu
  (`/usr/share/sambaedu/gpo/se4_applications.zip` upstream) et déployé à
  l'installation serveur en cascade avec `se4_wpkg`. Aucune création/modification
  runtime native.
- 17.3 doit **vérifier que les `.cmd` orchestrateurs contenus dans le template
  pointent vers les endpoints natifs `/api/v1/workstation-config/applications-scripts`**
  (16.13) et **non vers l'endpoint legacy `gpo/applications.php`**. Si écart,
  patcher le template upstream OU ajouter une couche de substitution
  post-extraction (pattern `WpkgGpoSynchronizer::substituteTokens`).
- Vérification de **liaison GPO ↔ OU/parc** déjà couverte par Story 16.5 done.
- Pas de création de GPO ni de synchronizer dédié.

**Frontière** : 17.3 ne touche pas au contenu des fragments scripts (→ 17.2)
ni au portage des endpoints (→ 17.6).

**Estimation révisée** : **~1 jour** (vérification + patch éventuel template).

### G.3 — Story 17.4 (tests d'intégration runtime VM)

**Périmètre** : tests d'intégration end-to-end sur la VM dev (`/vm`) qui
valident la chaîne complète pour les **5 scripts critiques** :

1. **`wpkg/startup.windows`** : vérifier `ROBOCOPY` + `wpkg.cmd` + chaîne complète.
2. **`wallpaper/logon.windows`** : vérifier substitution `SE4FS_NAME` + endpoint
   `wallpaper_out.php` joignable + image `wallpaper.jpg` téléchargée.
3. **`shortcuts/logon.windows`** : vérifier endpoint `shortcuts_out.php` +
   exécution `%TEMP%\shortcuts.cmd`.
4. **`firefox/logon.windows`** : vérifier création `profiles.ini` correct
   (utilisateur non-roaming + utilisateur roaming).
5. **`firefox/logon.linux`** : version Linux équivalente.

**Tests additionnels recommandés** :

- **Test parité bytes** : pour chaque (machine, user, action, os), comparer
  le `.cmd` retourné par legacy vs natif → doit être strictement identique
  (modulo timestamps de log et numéros de séquence).
- **Test surcharges admin** : créer `/etc/sambaedu/applications/wallpaper/logon.windows`
  avec contenu différent et vérifier qu'il prend la priorité.
- **Test placeholders étendus (Section B)** : valider que chaque nouvelle clé
  whitelist 17.2 est correctement substituée dans un fragment qui la consomme.

**Frontière** : tests d'intégration, **pas** des tests unitaires des services
(qui sont du ressort de Story 17.2 elle-même).

### G.4 — Story 17.5 (centralisation logs scripts BDD)

> **⚠ Patch 2026-05-21** : la Story 16.12 « Logs d'exécution centralisés » a
> été livrée done depuis. Elle pose l'infrastructure : table migration +
> modèle `App\ScriptsOs\Models\ScriptExecutionLog`, endpoint
> `POST /api/v1/script-execution-logs` via `ScriptExecutionLogIngestionController`,
> service `WrapperScriptRenderer`, templates `wrapper-cmd.blade.php` +
> `wrapper-sh.blade.php`. Les enums `ScriptExecutionAction/Status/Os/Source`
> sont définis. **17.5 ne refait donc pas l'infra** ; elle se concentre sur
> l'intégration du wrapper dans la pipeline 17.2.

**Périmètre révisé** (post-16.12) :
- Brancher `WrapperScriptRenderer` dans `ApplicationScriptsAssembler` (Story 17.2)
  pour wrapper chaque fragment assemblé (prefix setup + suffix POST log).
- Ajouter une config opt-in `config('sambaedu.scripts.logging.enabled', false)`
  + commande artisan `winscript-logs:enable` / `winscript-logs:disable`.
- Tests Feature : génération scripts wrappés vs non-wrappés selon flag.
- Pas de nouvelle UI (l'UI de consultation est déjà livrée par 16.12 sous
  `/admin/settings/scripts-logs` à confirmer).

**Décision Henri 2026-05-21** : **opt-in par défaut** (risque latence sur les
54 fragments × timeout potentiel).

**Estimation révisée** : **~1 jour** (au lieu de 1-2j initialement).

### G.5 — Story 17.6 (portage endpoints `wpkg/*_out.php` orphelins)

> **⚠ Patch 2026-05-21** : Story 16.13 « Exposition endpoints API v1 » a
> porté nativement 8 des 10 endpoints initialement listés sous
> `/api/v1/workstation-config/*` : wallpaper, firefox, thunderbird, shortcuts,
> network, veyon, associations, applications-scripts. Restent **2 endpoints
> orphelins** (consommés mais non portés ni couverts par Epic 15) :
> `wpkg/linux_out.php` (consommé par `wpkg/startup.linux`) et
> `wpkg/winget_out.php` (consommé par `install/os/SambaEdu/install.ps1`).
> L'endpoint `partages/cloud_out.php` mentionné initialement n'a pas été
> retrouvé dans le repo upstream — déféré jusqu'à confirmation.

**Périmètre révisé** (post-16.13) :

- **`wpkg/linux_out.php`** : 1 controller natif Laravel (`LinuxOutController`)
  qui résout les packages APT applicables au poste via
  `WorkstationPackagesResolver` (Story 15.2 déjà disponible) et retourne le
  format plain-text legacy `pkg1 pkg2 pkg3`. Pas d'auth.
- **`wpkg/winget_out.php`** : 1 controller natif (`WingetOutController`) qui
  reproduit la logique de mapping `packages.xml` + `add.json`/`remove.json` →
  décision install/upgrade/uninstall en JSON. Pas d'auth (le poste n'est pas
  encore JWT-migré quand il appelle cet endpoint pendant l'install).

**Consigne Henri 2026-05-21** : *« Tu as le droit de ne pas tout recoder mais de
réutiliser une large part du code existant de sambaedu (sauf si certaines
requêtes en AD peuvent être portées en base et en adaptant les requêtes en
base à notre nouveau modèle). »* → réutiliser le code legacy `linux_out.php` /
`winget_out.php` comme **base de portage**, mais convertir toute requête LDAP
en requête Eloquent sur les modèles natifs (`Workstation`, `Application`,
`WorkstationGroup`, `AppProfile`).

**Tests Feature** : parité bytes vs legacy pour `linux_out.php` (output
plain-text), parité JSON pour `winget_out.php` sur ≥ 3 scénarios
(machine vierge / machine avec apps installées / machine avec apps à upgrader).

**Estimation révisée** : **~2.5-3 jours** (1j linux_out + 1.5-2j winget_out +
tests + runbook QA).

### G.6 — Récapitulatif découpage final (validé Henri 2026-05-21)

| Story | Titre                                                       | Estimation initiale | Estimation révisée post-Epic-16-done | Statut          | Bloquant par           |
|-------|-------------------------------------------------------------|---------------------|--------------------------------------|-----------------|------------------------|
| 17.1  | Audit (cette story)                                         | 1-2 jours           | 1-2j ✅ livré 2026-05-14              | ✅ done 2026-05-21 | —                   |
| 17.2  | Portage moteur `applications.php` + whitelist étendue (6→14 clés) + intégration wrapper logs (avec 17.5) | 2-3 jours | 2-3j inchangé                  | à créer (backlog) | 17.1 ✅           |
| 17.3  | Compat GPO orchestratrice `se4_applications` (Stratégie A : vérif template + patch endpoints v1) | 2 jours | **~1j** réduit (Stratégie A)  | à créer (backlog) | 17.1 ✅ + 16.5 ✅  |
| 17.4  | Tests d'intégration runtime VM (5 scripts critiques + parité bytes legacy vs natif) | 2 jours | 2j inchangé                  | à créer (backlog) | 17.2 + 17.3       |
| 17.5  | Intégration `WrapperScriptRenderer` (16.12) dans pipeline 17.2 + config opt-in | 1-2 jours | **~1j** réduit (16.12 a livré infra) | à créer (backlog) | 17.2 + 16.12 ✅ |
| 17.6  | Portage 2 endpoints orphelins `wpkg/linux_out.php` + `wpkg/winget_out.php` (réutilisant code legacy, adapté Eloquent) | 3-5 jours | **~2.5-3j** réduit (8/10 endpoints déjà portés par 16.13) | à créer (backlog) | 17.1 ✅ + 15.2 ✅ + 16.13 ✅ |

**Epic 17 cadrage final** : **6 stories**, **~7-9 jours** au total (au lieu de
10-14j estimés à l'audit initial — économie ~30-40 % grâce aux livrables Epic 16
post-2026-05-14).

---

## Section H — Vérifications transverses obligatoires

### H.1 — Frontière `wpkg.js` / Story 16.6

**Réponse** : ✅ **Confirmé hors scope Epic 17**.

- Le `wpkg.js` mentionné dans 16.6 est en réalité **`wpkg-se4.js`** situé dans
  `/home/htouchard/code/irundo/se4/sources/var/sambaedu/unattended/install/wpkg/wpkg-se4.js`
  (11 281 lignes — fork upstream wpkg.js patché SambaEdu).
- Sa chaîne d'invocation côté poste est :
  `wpkg/startup.windows` → `wpkg.cmd` → `wpkg-client.vbs` → `wpkg-se4.js`
  → `GET /wpkg/hosts.xml` (Story 15.2) + `GET /wpkg/profiles.xml` (Story 15.2)
  + `GET /wpkg/packages.xml` (legacy).
- La GPO qui pilote cette chaîne est **`se4_wpkg`** (couverte par Story 16.6,
  status `review`) — distincte de **`se4_applications`** (à traiter par Story
  17.3).
- L'audit n'a pas re-cartographié `wpkg-se4.js` en profondeur (sa cartographie
  est du ressort de Story 16.6 ou d'une éventuelle story upstream). Section A
  fiche `wpkg-se4.js` se contente d'un marqueur `Hors scope Epic 17`.

**Référence dossier `wpkg.js`** : `var/sambaedu/unattended/install/wpkg/` du
package upstream `sambaedu` Debian.

### H.2 — Statut couche surcharges `/etc/sambaedu/applications/`

**Réponse** : ✅ **Mécanisme préservé par Story 16.7** (vérifié dans
`ApplicationTemplatesScanner.php:55-69`). ⚠️ **Fréquence d'usage chez les
clients inconnue** — question pour Henri (cf. liste finale).

Recommandation 17.4 : test E2E qui crée une surcharge admin et vérifie sa
priorité.

### H.3 — Statut `var/sambaedu/unattended/install/os/SambaEdu/*.{ps1,cmd}`

**Réponse** : ⚠️ **Décision nuancée** — **out-of-scope assembly Story 17.2 mais
in-scope observabilité Epic 17**.

Justification (échantillon T1.4 lu) :

1. **`install.ps1`** : exécuté par `wpkg.cmd` ligne 15 au boot (si pwsh 7
   présent). C'est du bootstrap **runtime**, pas iPXE. ✅ in-scope runtime.
2. **`powershellTask.ps1`** : invoqué par `make_application_scripts()` côté
   serveur via `applications.inc.php:458` — c'est l'infra qui transforme un
   fragment `.ps1` en ScheduledTask déclenchée au runtime. ✅ in-scope runtime
   (mais hors scope assembly — il n'est pas concaténé dans un `.cmd`).
3. **`winget-install.ps1`** : envoie `POST /wpkg/winget_out.php` au runtime —
   in-scope endpoint (cf. Section C). ⚠️ endpoint non porté par 16.7.
4. **`sysprep.ps1`** : exécuté **iPXE pré-déploiement** (pour Sysprep avant
   clonage d'image). ❌ out-of-scope runtime.
5. **`SetWallpaper.ps1`** : exécuté par `wallpaper/logon.windows` au runtime
   utilisateur (force le wallpaper côté `Win32::Wallpaper.SetWallpaper`). ✅
   in-scope runtime mais pas assemblé dans le `.cmd` — c'est un binaire
   exécuté.

**Conclusion** : ces ~29 fichiers sont **livrés en l'état** par le package
Debian, **déployés côté poste** par `wpkg/startup.windows` (ROBOCOPY) et
**invoqués au runtime** par d'autres scripts (les fragments `applications/*`
ou directement par WPKG). **Story 17.2 ne les porte pas** (pas concaténés
dans le `.cmd` orchestrateur). **Story 17.6** doit en revanche couvrir
l'endpoint `wpkg/winget_out.php` (appelé par `winget-install.ps1`). **Story
17.4** doit inclure un test qui vérifie que `ROBOCOPY` fonctionne et que les
`.ps1` sont bien déployés.

---

## Section I — État des lieux logging scripts + recommandation centralisation BDD (cadrage Story 17.5)

> Ajout 2026-05-14 (Henri, option A) : élargissement de l'audit pour
> cartographier l'observabilité actuelle des scripts et préparer une future
> story 17.5 « centralisation logs scripts en BDD ». Pas de design technique
> exhaustif — juste l'état des lieux + une recommandation cadrante.

### I.1 — État des lieux logging legacy

**Endpoints legacy de log identifiés** :

| Endpoint legacy                  | Rôle                                                                                                        | Source                                                                                                          |
|----------------------------------|-------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------|
| `gpo/applications.php` (footer)  | Callback final du `.cmd` orchestrateur (`ret=0`) → `log_connexion()` UPDATE `machines`/`connexions`         | `sambaedu/gpo/applications.php` + `sambaedu/includes/logs.inc.php:57+` (`log_connexion`)                        |
| `logs.php`                       | Endpoint de log généraliste utilisé par `networkInfo.ps1` (`http://${env:SE4FS}/logs.php`)                  | `sambaedu/logs.php` (39 lignes) → `log_connexion($config, $user, $machine, $os, $action)`                       |
| `logs.inc.php` (interne PHP)     | Fonctions PHP `log_connexion()`, `log_application_scripts()` — appelées depuis `applications.php` côté serveur | `sambaedu/includes/logs.inc.php:57-300+` ; `applications.inc.php:775-825` (`log_application_scripts()`)         |
| `apcu_store('computer_lock', …)` | Lock anti-race pour assignation RDP (utilisé par `computer_lock()`/`computer_unlock()`)                    | `sambaedu/includes/logs.inc.php:9-46` — ⚠️ dépendance APCu (cf. memory todo `apcu-stub-logs`)                   |
| `c:\windows\rapports\<machine>.log` | Log local Windows ligne-par-ligne (recopié sur SMB partage `\\SE4FS\rapports\`)                          | `wpkg-client.vbs:dump()` + `fLocal`                                                                              |
| `c:\windows\wpkg.log` + `wpkg.txt`  | Log local WPKG synthétique                                                                                  | `wpkg-client.vbs` lignes 170-175                                                                                 |

**Granularité observée** :

- **Côté `applications.php`** : **log de début + log de fin** uniquement (pas
  ligne-par-ligne). Le `.cmd` orchestrateur fait un `ret=0` callback en footer,
  signalant que tout s'est exécuté — mais sans détail par fragment. Un fragment
  qui crash silencieusement (sans break) ne sera pas remonté. ⚠️ **Granularité
  insuffisante pour le debug parc-wide**.
- **Côté `wpkg-client.vbs`** : **ligne-par-ligne** via `dump()` (équivalent
  d'un `print`) — chaque exécution de package est tracée, le log final est
  recopié sur le SMB partage `\\SE4FS\rapports\<computer>.log`. Granularité
  bien meilleure mais **silo** : pas de ré-injection en BDD côté serveur.
- **Côté fragments `applications/*`** : **silent à 95%** (`>NUL` sur Windows,
  `echo` Linux capté par systemd journal sans persistence). Aucune trace
  serveur d'un fragment individuel qui aurait échoué.

**Mode de logging annoté par script** (annotation rapportée en Section A,
colonne « Risques / logging »):

- **`silent`** (43 scripts / 54 fragments) : pas de log explicite.
- **`POST-legacy-applications.php-footer`** (54 scripts indirectement) :
  via le footer du `.cmd` assemblé — couvre tous les fragments mais sans
  granularité.
- **`local-eventlog`** : aucun script upstream observé.
- **`local-file`** (2 scripts : `wpkg/startup.windows`, `reseau/networkInfo.ps1`) :
  log local ou recopie SMB.
- **`POST-legacy-logs.php`** (1 script : `reseau/networkInfo.ps1`) : `POST
  http://${env:SE4FS}/logs.php`.
- **`mixte`** : `wpkg-client.vbs` (local + SMB).

### I.2 — Recommandation pour une future Story 17.5 (centralisation BDD)

#### Mécanisme d'injection privilégié

**Option recommandée : enveloppe de logging ajoutée par le moteur natif 17.2
au moment de l'assemblage.**

- Le moteur `ApplicationScriptsAssembler` (Story 16.7 / 17.2) **wrappe**
  chaque fragment dans un `try-catch` cmd/bash équivalent qui capture
  `%ERRORLEVEL%` / `$?` + un excerpt stdout/stderr et envoie un POST au
  serveur Laravel.
- **Pseudocode CMD côté assembleur** :
  ```cmd
  REM ###_FRAGMENT_START_### {app=wallpaper, action=logon}
  (
    REM <contenu du fragment wallpaper/logon.windows>
  ) > "%TEMP%\winscript-wallpaper-logon.out" 2>&1
  SET _RC=%ERRORLEVEL%
  curl.exe -F "machine=%computername%" -F "user=%username%" -F "action=logon" ^
           -F "script_path=wallpaper/logon.windows" -F "exit_code=%_RC%" ^
           -F "stdout_excerpt=@%TEMP%\winscript-wallpaper-logon.out" ^
           "http://%SE4FS%/api/v1/winscripts/log" >NUL
  REM ###_FRAGMENT_END_###
  ```
- **Avantage** : ne touche pas aux scripts upstream (préservé iso-byte) — le
  log est injecté **à l'assembly** côté serveur Laravel. La release `sambaedu`
  du package upstream peut continuer à itérer indépendamment.
- **Inconvénient** : alourdit le `.cmd` orchestrateur final (×3 pour chaque
  fragment). Latence ajoutée (curl par fragment). À mesurer en charge.

**Alternative — modification des scripts upstream** : ajouter un `try/catch`
+ curl dans chaque fragment `.windows`/`.linux`. Trop invasif, casse l'iso-byte
parité, à éviter.

#### Contrat HTTP cible

| Aspect              | Recommandation                                                                                                |
|---------------------|---------------------------------------------------------------------------------------------------------------|
| **Méthode**         | `POST`                                                                                                        |
| **Route Laravel**   | `/api/v1/winscripts/log` (route name `winscripts.log`)                                                        |
| **Auth**            | Bearer machine (cf. Story 15.5 phase 2) ou route ouverte avec rate-limit par IP — à arbitrer avec Henri        |
| **Payload** (form-data) | `machine`, `user`, `action` (logon/startup/…), `script_path` (ex. `wallpaper/logon.windows`), `app`, `exit_code` (int), `stdout_excerpt` (string limité à 4KB), `stderr_excerpt` (string limité à 4KB), `timestamp` (ISO 8601 RFC 3339), `duration_ms` (optional) |
| **Réponse**         | `204 No Content` en succès ; `429` si rate-limit ; `503` si BDD indispo (le script ne doit pas bloquer)         |
| **Rate-limit**      | Throttle 60/min par machine (suffisant : 1 log par fragment × ~5 fragments par cycle × ~10 cycles/jour ≈ 50) |

#### Modèle Eloquent cible

**Modèle suggéré : `WinscriptLog`** (peut s'appeler `ScriptLog` si on étend
au Linux).

```
Table : `winscript_logs`
Colonnes :
- id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
- machine VARCHAR(63) NOT NULL                — netbios name (lowercase)
- user VARCHAR(128) NULL                       — username (nullable si action=startup)
- os ENUM('windows','linux') NOT NULL
- action ENUM('startup','logon','logoff','logon-system','logoff-system','shutdown','wpkg') NOT NULL
- app VARCHAR(63) NOT NULL                     — wallpaper, firefox, etc.
- script_path VARCHAR(255) NOT NULL            — relatif à usr/share/sambaedu/applications/
- exit_code SMALLINT NOT NULL                  — 0 = OK, autre = échec
- stdout_excerpt MEDIUMTEXT NULL               — limité 4KB serveur-side
- stderr_excerpt MEDIUMTEXT NULL               — idem
- duration_ms INT UNSIGNED NULL                — optional, si l'enveloppe le calcule
- ip_address VARCHAR(45) NULL                  — captée par Laravel (`$request->ip()`)
- created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
- INDEX (machine, created_at)
- INDEX (action, exit_code, created_at)        — pour dashboard "actions ayant échoué"
- INDEX (app, exit_code, created_at)           — pour dashboard "apps fragiles"
```

**Rétention** : 30 jours par défaut (purge cron quotidien), configurable
via `config('sambaedu.winscript_logs.retention_days', 30)`. Pour les
établissements voulant garder plus, augmenter ; pour ceux limités en disque,
diminuer à 7.

**Estimation volumétrie** :
- ~54 fragments × ~50 machines (école moyenne) × ~10 cycles/jour ≈ **27 000
  lignes/jour/établissement**. À 1KB par ligne (avec stdout_excerpt court) ≈
  **27 MB/jour** ; sur 30 jours ≈ **810 MB**. Acceptable mais à monitorer.
- Pour une école très large (500 postes) : ≈ 270 000 lignes/jour ≈ 270 MB/jour
  → **8 GB sur 30 jours** ⚠️. Rétention à raccourcir.

#### Couplage avec Story 17.2

**Recommandation** : **découpler 17.5 de 17.2**.

- Story 17.2 livre l'élargissement whitelist + parité bytes du moteur 16.7.
  C'est déjà non-trivial.
- Story 17.5 ajoute l'enveloppe de logging dans un 2nd temps, en réutilisant
  l'`ApplicationScriptsAssembler` livré par 17.2.
- Avantage du découplage : les écoles qui ne veulent **pas** d'observabilité
  centralisée peuvent désactiver Story 17.5 via feature flag
  (`config('sambaedu.winscript_logs.enabled', true)`).

#### Risques identifiés

| #   | Risque                                                                                          | Sévérité    |
|-----|-------------------------------------------------------------------------------------------------|-------------|
| I-R1| Volumétrie BDD : 8 GB / 30 jours pour grandes écoles → table volumineuse, requêtes lentes      | Modérée     |
| I-R2| Bruit (logs non-actionnables) : chaque cycle remonte 54 logs, dont 95% sont des `exit_code=0`. Difficile de repérer les échecs. | Modérée |
| I-R3| Confidentialité : `stdout_excerpt` peut contenir des données sensibles (user, IP, parfois password en clair si le script log débile) — à filtrer côté Laravel (regex purifier) | Élevée |
| I-R4| Couplage curl-callback : si l'endpoint serveur est en panne, le `.cmd` orchestrateur prend plusieurs minutes (timeout curl par défaut = 60s × 54 fragments) | Élevée |
| I-R5| Performance applicative : 54 INSERT par cycle × 50 machines × 10 cycles/jour ≈ 27 000 INSERTS/jour. À mesurer en charge. | Modérée |

### I.3 — Synthèse Section I (cadrage 17.5)

- **Mécanisme** : enveloppe de logging injectée par `ApplicationScriptsAssembler`
  natif (pas modification scripts upstream).
- **Endpoint cible** : `POST /api/v1/winscripts/log` avec rate-limit 60/min
  machine.
- **Modèle Eloquent** : `WinscriptLog` (table `winscript_logs`) avec rétention
  30 jours par défaut.
- **Couplage** : **découpler** Story 17.5 de Story 17.2 — feature flag opt-in.
- **Story 17.5 NE DOIT PAS** : modifier les scripts upstream ; bloquer
  l'exécution si BDD en panne (logging best-effort, jamais bloquant).

Cette section ne livre **ni code, ni story 17.5 elle-même**. Elle pose les
fondations pour que le SM crée la story 17.5 sans réinvestigation.

---

## Réponses Henri (2026-05-21) — Q1 à Q8

(Parité Story 16.1 — section « Discrepances ouvertes » → résolutions.)

### Q1 — Fréquence d'usage couche `/etc/sambaedu/applications/`

**Résolution** : ✅ **couche active**, exploitée nativement par plusieurs services
sambaedu-reload :
- `app/Gpo/Services/ApplicationTemplatesScanner.php:34-38` — `DEFAULT_PACKAGE_PATH`
  `/usr/share/sambaedu/applications/` puis surcharge `DEFAULT_LOCAL_PATH`
  `/etc/sambaedu/applications/`.
- `app/Services/ShortcutsService.php` — écrit/lit `/etc/sambaedu/applications/shortcuts/shortcuts.json`.
- `app/Services/AppCustomization/AppCustomizationService.php` — export FS via
  `config('app-customizations.fs_base_path', '/etc/sambaedu/applications')`.
- `app/Services/WallpaperController.php` — consomme `/etc/sambaedu/applications/wallpaper/`.
- `app/Console/Commands/AppsImportCustomizationsFromLegacyCommand.php` — import
  depuis `/etc/sambaedu/applications/{firefox,thunderbird}/*.json`.
- Routes Livewire `/app/shortcuts`, `/app/wallpapers` — persistance dans `/etc/sambaedu/applications/`.

**Conséquence Section G** : conserver priorité `/etc/` > `/usr/share/` dans 17.2.
Pas d'UI rollback/audit dédiée nécessaire en Epic 17 (déférer à un Epic ultérieur
si besoin terrain remonte).

### Q2 — Sources des nouvelles clés whitelist (déférée à Story 17.2)

**Résolution Henri** : déférée au dev de **Story 17.2** lors de l'élargissement de
`config/sambaedu.gpo.applications.substitutions.php`. Pattern par défaut :
`config('sambaedu.<key>')` + fallback `env('<KEY>')` (pattern 16.7). Le dev 17.2
arbitrera pour chaque clé entre source statique config, `.env`, requête Eloquent
ou query LDAP selon la nature de la donnée (ex. `DHCP_RESEAU` likely Eloquent
sur table `Establishment`/`Dhcp`, `GLPI_URL` likely config statique).

### Q3 — Périmètre `*.linux` et postes Linux nomades (déférée à Story 17.4)

**Résolution Henri** : déférée au dev de **Story 17.4** au moment du choix des cas
de test. Couverture minimale : ≥ 1 cas Linux testé (par défaut `firefox/logon.linux`
selon cadrage epics.md). Le dev 17.4 confirmera avec Henri la liste finale de
fragments `.linux` réellement actifs en parc (LTSP, postes nomades).

### Q4 — Endpoints `*_out.php` déjà partiellement portés ? (RÉSOLU)

**Résolution 2026-05-21** : **Story 16.13 a porté 8 des 10 endpoints initiaux**
sous `/api/v1/workstation-config/*` :
- ✅ `firefox_out.php` → `/api/v1/workstation-config/firefox`
- ✅ `thunderbird_out.php` → `/api/v1/workstation-config/thunderbird`
- ✅ `veyon_out.php` → `/api/v1/workstation-config/veyon`
- ✅ `wallpaper_out.php` → `/api/v1/workstation-config/wallpaper`
- ✅ `shortcuts_out.php` → `/api/v1/workstation-config/shortcuts`
- ✅ `network_out.php` → `/api/v1/workstation-config/network`
- ✅ `applications.php` → `/api/v1/workstation-config/applications-scripts`
- ✅ `associations` → `/api/v1/workstation-config/associations`
- ⚠️ `partages/cloud_out.php` — introuvable upstream (à déférer si confirmé)
- ❌ `wpkg/linux_out.php` — **orphelin**, à porter dans 17.6
- ❌ `wpkg/winget_out.php` — **orphelin**, à porter dans 17.6
- ⚠️ `logs.php` — partiellement couvert par 16.12 (`POST /api/v1/script-execution-logs`)

**Décision Henri 2026-05-21** : porter les 2 endpoints orphelins dans **Story 17.6**
en réutilisant le code legacy comme base, mais en remplaçant les requêtes AD par
des requêtes Eloquent sur le modèle natif.

### Q5 — Stratégie pour la GPO `se4_applications` (RÉSOLU)

**Résolution 2026-05-21** : Stratégie B initialement recommandée **invalidée par
l'annulation de Story 16.4** le 2026-05-18 (cf. memory `project_no_native_gpo_creation`).
**Stratégie A confirmée par Henri** :

> *« On ne crée plus de GPO. À l'installation du serveur on télécharge des GPO
> depuis un repo et on les set directement. »*

Le mécanisme est déjà opérationnel pour `se4_wpkg` (cf. `WpkgGpoSynchronizer`
Story 16.6 + template `/usr/share/sambaedu/gpo/se4_wpkg.zip` livré par le package
Debian). Pour `se4_applications`, le même pattern s'applique. 17.3 doit vérifier
que les `.cmd` orchestrateurs contenus dans le template pointent vers les
endpoints natifs `/api/v1/workstation-config/applications-scripts` (16.13) et
non vers `gpo/applications.php` legacy.

### Q6 — Story 17.5 (logs BDD) : opt-in (RÉSOLU)

**Résolution Henri 2026-05-21** : **opt-in par défaut**
(`config('sambaedu.scripts.logging.enabled', false)`), avec commandes artisan
`winscript-logs:enable` / `winscript-logs:disable` documentées. Justification :
risque I-R4 audit (54 fragments × timeout potentiel 60s) → ne pas activer
automatiquement parc-wide.

L'infrastructure logs (table, modèle, endpoint, wrapper service, templates) est
**déjà livrée par Story 16.12 done**. 17.5 ne refait pas l'infra ; elle se
concentre sur l'intégration de `WrapperScriptRenderer` dans la pipeline
`ApplicationScriptsAssembler` (Story 17.2).

### Q7 — Découpage 17.x proposé (RÉSOLU)

**Résolution Henri 2026-05-21** : **6 stories conservées** (17.1 → 17.6), avec
estimations révisées post-Epic-16-done. Cf. Section G.6 révisée pour le détail.
Pas de fusion/suppression de story. Total ~7-9j (au lieu de 10-14j estimés
initialement).

### Q8 — Endpoint orphelin `wpkg/winget_out.php` (RÉSOLU)

**Résolution 2026-05-21** : subsumé par la décision Q4 — porté dans **Story 17.6**
avec `wpkg/linux_out.php` (les deux orphelins ensemble, ~2.5-3j).

---

## Annexes

### Annexe A — Inventaire exhaustif des fichiers upstream analysés

```
usr/share/sambaedu/applications/  (29 apps × N fichiers)
├── 54 fragments  *.{windows,linux}
├── 11 scripts.json     (6 apps : folders, logs, ltsp, rdp, reseau, ltsp)
├──  5 redirects.json   (chrome, edge, Filius, firefox, OnlyOffice, OpenBoard, thunderbird)
├──  2 default.json     (firefox, thunderbird)
├──  1 veyon.json       (veyon)
├──  2 winget add.json + remove.json
├──  3 shortcuts/*.inc.json  (catalogues raccourcis)
├──  3 shortcuts/*.{png,ico} (icônes)
├──  1 wallpaper assets : default.jpg + lockscreen@nird.jpg + sambaedu.png + wallpaper@nird.jpg
├──  1 firefox/policies.json.exemple
├──  1 veyon/default-pubkey.pem
├──  1 firefox/default.json
└── ~10 fragments sans extension (folders/bureau_*, clean_*, docs_*) listés par scripts.json

var/sambaedu/unattended/install/wpkg/
├── wpkg.cmd                    (21 lignes)
├── wpkg-client.vbs             (1038 lignes)
├── wpkg-client.vbs-original    (backup upstream)
├── wpkg-se4.js                 (11281 lignes — fork wpkg.js)
├── tools/  (autoit, wget, etc.)
└── autoit-auto.au3 + autoit-auto.exe

var/sambaedu/unattended/install/os/SambaEdu/  (~29 fichiers)
├── install.ps1
├── powershellTask.ps1
├── SetWallpaper.ps1
├── winget-install.ps1
├── winget.ps1
├── PingSE4.ps1
├── associations.ps1
├── no_internet.ps1
├── driversAuto.ps1
├── pin.ps1
├── rdpvideo.ps1
├── SFTA.ps1
├── sysprep.ps1                  (out-of-scope iPXE)
├── veyon-master.ps1
├── wakeonlan.ps1
├── wpkg.cmd                     (différent du wpkg.cmd dans install/wpkg/ — 2 lignes admin)
├── Nettoyage applications-startup.cmd
├── Nettoyage applications-logon.cmd
├── Nettoyage GPOs.cmd
├── Nettoyage Windows DISM.cmd
├── Nettoyage Windows SFC.cmd
├── Nettoyage Windows Update.cmd
├── Nettoyage WPKG.cmd
├── Diagnostic GPOs - GPResult.cmd
├── Installation outils RSAT.cmd
├── Configurer les associations aux applications par defaut.cmd
├── wintail.exe + wintail.lnk
└── associations.ps1
```

### Annexe B — Mapping fragments → endpoints HTTP (résumé)

Voir Section C tableau principal. Pour rappel :
- **24 fragments** appellent un endpoint HTTP (sur 54 total) — soit 44 %.
- **30 fragments** sont purement locaux (registre Windows, gsettings Linux,
  heredoc fichier de conf) — 56 %.

### Annexe C — Liens vers la story et les artefacts de référence

- Story 17.1 : `_bmad-output/implementation-artifacts/17-1-audit-scripts-windows-linux.md`
- Template structurel : `_bmad-output/planning-artifacts/audit-gpo-legacy.md`
- Story 16.7 (référence portage) : `_bmad-output/implementation-artifacts/16-7-portage-natif-applications-php.md`
- Story 16.6 (frontière `wpkg-se4.js`) : `_bmad-output/implementation-artifacts/16-6-hook-gpo-invocation-wpkgjs-cote-client.md`
- Whitelist 16.7 : `config/sambaedu.gpo.applications.substitutions.php`
- Scanner natif 16.7 : `app/Gpo/Services/ApplicationTemplatesScanner.php`
- Assembler natif 16.7 : `app/Gpo/Services/ApplicationScriptsAssembler.php`
- Generator natif 16.7 : `app/Gpo/Services/ApplicationScriptsGenerator.php`

---

**FIN — audit livré 2026-05-14, validé par Henri 2026-05-21 (patch post-Epic-16-done).**
