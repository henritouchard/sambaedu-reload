# Fixtures GPO Applications — Parité Bytes Legacy vs Natif

## Versioning paquet legacy

> Si les fixtures cassent suite à mise à jour paquet sambaedu, regénérer via la procédure ci-dessous et bumper le checksum/version dans ce fichier.

| Champ | Valeur |
|---|---|
| **Version paquet** | `4.17.695` (recapturé 2026-06-04 — capture initiale `4.17.285` du 2026-05-21) |
| **SHA256 `/usr/share/sambaedu/applications/`** | `688824a804221fe86763c2812c11dfc4952593c73f4c8f69ab61dbe108886d22` |

Pour recalculer le checksum après une mise à jour (`-print0` requis : le paquet
contient des noms de fichiers avec espaces depuis 4.17.695) :
```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
  "cd /usr/share/sambaedu/applications && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum"
```

Pour vérifier la version installée :
```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "dpkg-query -W -f='\${Package} \${Version}' sambaedu"
```

---

> **Tests parité en CI** : Les tests `ApplicationsScriptsByteParityTest` skipent silencieusement
> quand `/usr/share/sambaedu/applications/` est absent (CI sans VM legacy). Pour valider la parité,
> exécuter sur VM via :
> ```bash
> ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
> ./vendor/bin/phpunit --testsuite Feature --filter ApplicationsScriptsByteParityTest
> ```

---

## Contexte

Ces fixtures sont des sorties de référence du legacy PHP `gpo/applications.php`
(paquet Debian `sambaedu`), capturées sur la VM de développement
(`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`).

Elles servent de référence byte-à-byte pour le test de parité
`tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` (Story 17.2 — AC2.2).

## Date de capture

- **2026-06-04** (recapture — paquet `4.17.695` ; capture initiale 2026-05-21 sur `4.17.285`)
- **Version paquet sambaedu** : `4.17.695`
- **VM** : `root@192.168.122.50`
- **Path legacy** : `/var/www/sambaedu/gpo/applications.php`
- **Script de capture** : `/tmp/capture_fixtures_4_17_695.php` (les 5 fixtures
  17.2 + 17.4 en un seul passage, mêmes `$info`/`$config` que ci-dessous)

## Procédure de capture

Les fixtures ont été générées en appelant directement les fonctions PHP legacy
avec un contexte `$info` synthétique (la VM de test n'a pas de postes clients
réels dans l'AD — workaround validé Story 17.2 T3.2).

### Commande générique

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "php -r \"
require '/var/www/sambaedu/includes/config.inc.php';
\$config = get_config();
require_once '/var/www/sambaedu/includes/traitement_data.inc.php';
require '/var/www/sambaedu/includes/applications.inc.php';

// Valeurs de test injectées dans \$config pour couvrir les 8 nouvelles clés (Story 17.2)
\$config['adminse_name'] = 'adminse';
\$config['se4install_name'] = 'se4install';
\$config['no_internet'] = 'pasInternet';
\$config['glpi_url'] = 'http://glpi.test.fr';
\$config['dhcp_reseau'] = '192.168.1.0';
\$config['dhcp_masque'] = '255.255.255.0';
\$config['cloud_perso_name'] = 'Mes Documents';

// ... \$info selon le scénario ...

apcu_clear_cache();
\$scripts = read_application_scripts(\$config);
\$out = make_application_scripts(\$config, \$info, \$scripts);
file_put_contents('/tmp/fixture-<scenario>.<ext>', \$out['<interpreter>']);
\"\""
```

Puis `scp` pour récupérer :
```bash
scp -i ~/.ssh/id_se4fs_vm root@192.168.122.50:/tmp/fixture-<scenario>.<ext> \
    tests/Fixtures/Gpo/applications/<scenario>/expected.<ext>
```

## Scénarios

### 1. `windows_logon_user` — Windows logon utilisateur standard

**Fichier** : `windows_logon_user/expected.cmd`

**Contexte `$info`** :
```php
[
    'os'              => 'windows',
    'action'          => 'logon',
    'interpreter'     => 'cmd',
    'context'         => '',          // user (pas system)
    'remote'          => false,
    'machine'         => ['cn' => 'pc-test', 'dn' => 'cn=pc-test,ou=salle01,...', 'memberof' => [...]],
    'user'            => ['cn' => 'testuser', 'memberof' => []],
    'userprofile'     => 'C:\Users\testuser',
    'salle'           => 'salle01',
    'parcs'           => ['salle01'],
    'list'            => ['testuser', 'salle01', 'pc-test'],
    'liste_applications' => [],
    'admin'           => 0,
    'id'              => md5('testuserpc-testlogon'),   // e32b20d400dd5651c7544bffa04bb48d
    'speed'           => 0,
]
```

**Clés couvertes** : `SE4FS_NAME`, `DOMAIN`, `UAI`, `SE4INSTALL_NAME`
(via script `wallpaper/logon.windows` — `se4install`).

### 2. `windows_startup_firewall` — Windows startup machine (context=system)

**Fichier** : `windows_startup_firewall/expected.cmd`

**Contexte `$info`** :
```php
[
    'os'              => 'windows',
    'action'          => 'startup',
    'interpreter'     => 'cmd',
    'context'         => 'system',    // context SYSTEM (avant logon)
    'remote'          => false,
    'machine'         => ['cn' => 'pc-test', 'dn' => 'cn=pc-test,ou=salle01,...', 'memberof' => [...]],
    'user'            => ['cn' => 'pc-test', 'memberof' => []],
    'userprofile'     => 'C:\Users\Default',
    'salle'           => 'salle01',
    'parcs'           => ['salle01'],
    'list'            => ['pc-test', 'salle01'],
    'liste_applications' => [],
    'admin'           => 0,
    'id'              => md5('pc-testpc-teststartup'),   // bb28a6b4b3480d159d803c740968ea0c
    'speed'           => 0,
]
```

**Clés couvertes** :
- `NO_INTERNET` → `pasInternet` (script `firewall/startup.windows`)
- `DHCP_RESEAU` → `192.168.1.0` (script `firewall/startup.windows`)
- `DHCP_MASQUE` → `255.255.255.0` (script `firewall/startup.windows`)
- `SE4FS_IP` → `192.168.122.50` (script `firewall/startup.windows`)
- `SE4AD_IP` → `192.168.122.60` (script `firewall/startup.windows`)
- `ADMINSE_NAME` → `adminse` (script `folders/clean_profiles`)
- `UAI`, `SE4FS_NAME`, `DOMAIN` (header)

### 3. `linux_startup_glpi` — Linux startup machine (GLPI Agent)

**Fichier** : `linux_startup_glpi/expected.sh`

**Contexte `$info`** :
```php
[
    'os'              => 'linux',
    'action'          => 'startup',
    'interpreter'     => 'bash',
    'context'         => '',          // context '' (pour éviter local_admin_scripts legacy qui requiert $config LDAP complet)
    'remote'          => false,
    'machine'         => ['cn' => 'pc-test', 'dn' => 'cn=pc-test,ou=salle01,...', 'memberof' => []],
    'user'            => ['cn' => 'pc-test', 'memberof' => []],
    'userprofile'     => '',
    'salle'           => 'salle01',
    'parcs'           => [],
    'list'            => ['pc-test', 'salle01'],
    'liste_applications' => [],
    'admin'           => 0,
    'id'              => md5('pc-testpc-teststartuplinux'),
    'speed'           => 0,
]
```

**Clés couvertes** :
- `GLPI_URL` → `http://glpi.test.fr` (script `glpi/startup.linux`)
- `UAI` → `0000000x` (script `glpi/startup.linux`)
- `SE4FS_NAME`, `DOMAIN` (header bash)

## Normalisation avant comparaison

Les tests appliquent les normalisations suivantes **avant** `assertSame` :

Le trait partagé `tests/Concerns/AssertsScriptParity::assertScriptParity()`
applique ces normalisations **avant** `assertSame` (post-review 17.4 P7 — regex
`id` **restreintes aux contextes session connus**, pour ne PAS masquer un hash
non-session) :

| Pattern à remplacer | Remplacement | Raison |
|---|---|---|
| `SET DOMAINSID=…` | `SET DOMAINSID=__SID__` | SID domaine lu via `net getdomainsid` — varie selon instance |
| `SET id=[a-f0-9]{32}` | `SET id=__ID__` | id md5 session — header cmd Windows startup |
| `^id=[a-f0-9]{32}` (début de ligne) | `id=__ID__` | id md5 session — header bash Linux |
| `-F "id=[a-f0-9]{32}"` | `-F "id=__ID__"` | id md5 session — footer / DL powershell (cmd + bash) |

**Note P7 — innocuité de la normalisation `id`** : l'audit des 5 fragments
critiques + headers/footers montre qu'aucun hash 32-hex *non-session* n'apparaît
dans ces 3 contextes ancrés :
- le hash Firefox hardcodé est `308046B0AF4A39CB` (16 chars **MAJUSCULES**) → hors
  champ de `[a-f0-9]{32}` (lowercase + longueur 32) ;
- les `md5_file()` du mécanisme `once` produisent un md5 mais ne sont jamais
  préfixés par `id=` / `SET id=` / `-F "id="` (ils sont en nom de fichier `.md5`
  ou en comparaison `local_md5`).
La regex large `\bid=([a-f0-9]{32})\b` de la version 17.4 pré-review est donc
remplacée par trois ancres de contexte explicites (aucune divergence réelle
masquée).

**Note `$info['id']`** : dans les tests de parité, l'`$info['id']` est fixé à la
même valeur que celle utilisée lors de la capture (valeur md5 statique) pour
éviter tout écart d'ID. Le DOMAINSID est normalisé car il dépend du SID Samba de
l'instance VM (non portable).

## Régénération des fixtures

Si un script upstream change (mise à jour paquet `sambaedu`), régénérer les
fixtures en relançant les commandes ci-dessus sur la VM après `apt upgrade sambaedu`.

**Ne jamais modifier manuellement les fixtures** — elles doivent refléter
exactement la sortie du legacy PHP.

---

## Snapshot portable du package (Story 17.4 P3) — `_package_snapshot/`

> **Post-review 17.4 P3** — pour rendre les tests de parité **portables CI**
> (exécutables sans dépendre du chemin système `/usr/share/sambaedu/applications/`),
> un **snapshot byte-identique** du package est committé sous
> `tests/Fixtures/Gpo/applications/_package_snapshot/`.

| Champ | Valeur |
|---|---|
| **Provenance** | `/usr/share/sambaedu/applications/` (VM `root@192.168.122.50`) |
| **Version paquet** | `sambaedu 4.17.695` (recapturé 2026-06-04, identique à 17.2) |
| **SHA256 agrégé** | `688824a804221fe86763c2812c11dfc4952593c73f4c8f69ab61dbe108886d22` (identique à la table 17.2) |
| **Périmètre** | arborescence **complète** (100 fichiers, ~1.4 Mo, 28 apps) |

**Pourquoi l'arborescence complète et pas un sous-ensemble ?**
`ApplicationScriptsAssembler::assemble()` concatène **tous** les fragments
applicables d'un contexte (`logon/windows`, `startup/windows`, `logon/linux`) :
un snapshot partiel produirait un blob différent → casserait la parité byte.
Le snapshot complet est donc requis pour reproduire le blob exact des fixtures.
Taille maîtrisée (les 3 plus gros fichiers sont des images wallpaper ~262 Ko,
ressources légitimes de distribution ; le seul `.pem` est une **clé publique**
Veyon — aucun secret).

**Le test pointe sur le snapshot, pas sur le système** : le trait
`AssertsScriptParity::applicationsScriptsSource()` retourne le snapshot **en
priorité** (fallback `/usr/share/…` seulement s'il est absent). Vérifié : les
tests de parité 17.4 passent **sur un host sans `/usr/share/sambaedu/applications/`**.

**Régénérer le snapshot** (après bump paquet) :
```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
  "cd /usr/share/sambaedu/applications && tar -cf /tmp/apps_snapshot.tar ."
scp -i ~/.ssh/id_se4fs_vm root@192.168.122.50:/tmp/apps_snapshot.tar /tmp/
rm -rf tests/Fixtures/Gpo/applications/_package_snapshot/* \
  && tar -xf /tmp/apps_snapshot.tar -C tests/Fixtures/Gpo/applications/_package_snapshot/
# Puis recapturer les fixtures expected.* sur le MÊME paquet (cohérence byte).
```

---

## Scénarios Story 17.4 — parité par CONTEXTE + assertions ciblées

> Capture réalisée le **2026-05-25** sur la VM, paquet `sambaedu 4.17.285` (SHA256 identique à 17.2).

**Post-review P1/P2 — granularité de la couverture** : `assemble()` ne sait pas
isoler un script (il concatène tous les fragments d'un contexte). Les 5 scripts
critiques se répartissent sur seulement **3 contextes distincts** :

| Contexte | Fixture de parité byte | Fragments critiques couverts |
|---|---|---|
| `startup/windows` | *(aucune — byte-identique à `windows_startup_firewall` 17.2)* | `wpkg/startup.windows` |
| `logon/windows` | `windows_logon_wallpaper/expected.cmd` | `wallpaper`, `shortcuts`, `firefox` (logon.windows) |
| `logon/linux` | `linux_logon_firefox/expected.sh` | `firefox/logon.linux` |

**Fixtures supprimées (P1/P2 — byte-identiques après normalisation, redondantes)** :
- `windows_logon_shortcuts/` et `windows_logon_firefox/` → md5 normalisé identique à
  `windows_logon_wallpaper` (`23eac4c1…`). Une seule fixture conservée pour le
  blob `logon/windows` ; l'isolation par script est assurée par des **assertions
  ciblées de fragment** dans `ApplicationsScriptsCriticalParityTest`.
- `windows_startup_wpkg/` → md5 normalisé identique à `windows_startup_firewall`
  17.2 (`240afc13…`). Parité `startup/windows` déjà couverte par 17.2 ; ici seule
  l'**assertion ROBOCOPY ligne complète** (P4) est conservée.

### Fixture conservée — `windows_logon_wallpaper` (référence blob `logon/windows`)

**Fichier** : `windows_logon_wallpaper/expected.cmd`
**Couvre le blob** : `wallpaper` + `shortcuts` + `firefox` (logon.windows).

**Contexte `$info`** :
```php
[
    'os'          => 'windows',
    'action'      => 'logon',
    'interpreter' => 'cmd',
    'context'     => '',
    'remote'      => false,
    'machine'     => ['cn' => 'pc-test', 'dn' => 'cn=pc-test,ou=salle01,...', 'memberof' => [...]],
    'user'        => ['cn' => 'testuser', 'memberof' => []],
    'userprofile' => 'C:\\Users\\testuser',
    'salle'       => 'salle01',
    'parcs'       => ['salle01'],
    'list'        => ['testuser', 'salle01', 'pc-test'],
    'admin'       => 0,
    'id'          => md5('testuserpc-testlogonwallpaper'),
    'speed'       => 0,
]
```

**Assertions ciblées de fragment (isolation par script)** :
- **wallpaper** (P8) : ligne **complète** `taskkill /F /IM explorer.exe /FI "USERNAME ne se4install"`
  (`SE4INSTALL_NAME` substitué ; risque audit Section A ligne 645).
- **firefox** : heredoc `profiles.ini` (marqueur `[Install308046B0AF4A39CB]` + `)>…\Firefox\profiles.ini`).
- **shortcuts** : appel curl `http://se4fs/gpo/shortcuts_out.php` (`SE4FS_NAME` substitué).
  > Note : le fragment `shortcuts/logon.windows` réel **n'utilise pas** `mklink`/`.lnk`
  > (l'audit P1 le supposait) mais télécharge le `.cmd` de raccourcis via
  > `shortcuts_out.php` — marqueur vérifié sur le contenu réel de la fixture.

### Fragment `wpkg/startup.windows` — assertion ROBOCOPY (P4)

Pas de fixture de parité dédiée (P2). Assertion **ligne ROBOCOPY complète** via
`assertMatchesRegularExpression` :
```
ROBOCOPY "%WinDir%\install\os\SambaEdu" "%ProgramFiles%\SambaEdu"
```
**Historique source** : en `4.17.285` la source était `install\os\netinst`
(VM = référence légitime, validé Henri P4 — l'audit H.3 mentionnait déjà
`install\os\SambaEdu`). Le paquet `4.17.695` est passé à `install\os\SambaEdu` :
l'assertion ligne-entière a détecté le changement (recapture 2026-06-04) et a
été alignée. Elle continue de verrouiller la ligne entière (source + destination)
pour détecter tout changement de l'un ou l'autre.

### Fixture conservée — `linux_logon_firefox` (parité byte isolée nouvelle)

**Fichier** : `linux_logon_firefox/expected.sh`
**Script couvert** : `firefox/logon.linux` (seul contexte avec une parité byte
isolée nouvelle, distinct de tout contexte 17.2).

**Fichier** : `linux_logon_firefox/expected.sh`

**Script couvert** : `firefox/logon.linux`

**Contexte `$info`** :
```php
[
    'os'          => 'linux',
    'action'      => 'logon',
    'interpreter' => 'bash',
    'context'     => '',
    'remote'      => false,
    'machine'     => ['cn' => 'pc-test', 'dn' => 'cn=pc-test,ou=salle01,...', 'memberof' => []],
    'user'        => ['cn' => 'testuser', 'memberof' => []],
    'userprofile' => '',
    'salle'       => 'salle01',
    'parcs'       => [],
    'list'        => ['testuser', 'salle01', 'pc-test'],
    'admin'       => 0,
    'id'          => md5('testuserpc-testlogonlinuxfirefox'),
    'speed'       => 0,
]
```

**Clés couvertes** : aucun placeholder (script heredoc `profiles.ini` bash statique).

**Charset** : UTF-8, pas de CRLF Windows (LF uniquement).

---

### Commande de capture Story 17.4 (fixtures conservées)

```bash
# Copier le script de capture sur la VM
scp /tmp/capture_fixtures_17_4.php root@192.168.122.50:/tmp/
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "php /tmp/capture_fixtures_17_4.php"
# Rapatrier en base64 (préserve CRLF/charset) — seules 2 fixtures conservées :
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "base64 /tmp/fixture-windows_logon_wallpaper.cmd" \
  | base64 -d > tests/Fixtures/Gpo/applications/windows_logon_wallpaper/expected.cmd
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "base64 /tmp/fixture-linux_logon_firefox.sh" \
  | base64 -d > tests/Fixtures/Gpo/applications/linux_logon_firefox/expected.sh
```

### Notes de capture 17.4 (post-review)

- Paquet `sambaedu 4.17.285` — identique à 17.2, pas de bump version/SHA256.
- Capture réalisée le 2026-05-25 sur VM `root@192.168.122.50`.
- **Recapture 2026-06-04 (paquet `4.17.695`)** : les 5 fixtures (17.2 + 17.4) et
  le snapshot P3 ont été régénérés ensemble sur le même paquet (script
  `/tmp/capture_fixtures_4_17_695.php`, mêmes `$info`). Changements upstream
  notables : `firewall/startup.windows` → `firewall/logon-system.windows`,
  `ltsp/startup@serveurs_ltsp.linux` → `ltsp/{ltsp,packages.list,scripts.json}`,
  source ROBOCOPY wpkg `netinst` → `SambaEdu`, suppression des zero-width
  spaces (U+200B) des lignes UCPD de `firewall`, lignes roaming profile
  chrome/edge, retrait de `redirect-GoogleChrome`.
- **Snapshot P3** : `_package_snapshot/` byte-identique au package (SHA256 `8e0b5be2…`),
  permet les tests de parité portables CI (cf. section dédiée ci-dessus).
- **Fixtures supprimées (P1/P2)** : `windows_logon_shortcuts/`, `windows_logon_firefox/`,
  `windows_startup_wpkg/` — byte-identiques après normalisation, remplacées par des
  assertions ciblées de fragment (cf. section « Scénarios 17.4 »).
- `/etc/sambaedu/applications/` : 6 sous-dossiers (firefox, once, shortcuts, thunderbird,
  veyon, wallpaper) avec **uniquement des ressources** (images .jpg, default.json) — aucun
  fichier script reconnu. Pas de surcharges scripts déployées sur ce serveur (H.2).
- Template GPO `se4_applications` : `/usr/share/sambaedu/gpo/sambaedu-gpo/se4_applications/`
  absent sur cette VM (test AC2.3 skippé — sous-cas VM-dépendant légitime).
- Divergence audit H.3 (P4) : `wpkg/startup.windows` utilise `install\os\netinst`
  (non `install\os\SambaEdu`) — **VM = référence validée Henri**.
