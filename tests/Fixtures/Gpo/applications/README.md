# Fixtures GPO Applications — Parité Bytes Legacy vs Natif

## Versioning paquet legacy

> Si les fixtures cassent suite à mise à jour paquet sambaedu, regénérer via la procédure ci-dessous et bumper le checksum/version dans ce fichier.

| Champ | Valeur |
|---|---|
| **Version paquet** | `4.17.285` (capturé 2026-05-21) |
| **SHA256 `/usr/share/sambaedu/applications/`** | `8e0b5be2498b000762af4de89141023e62d9cf5e75713e982169d50a0f8c280e` |

Pour recalculer le checksum après une mise à jour :
```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
  "find /usr/share/sambaedu/applications/ -type f | sort | xargs sha256sum | sha256sum"
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

- **2026-05-21**
- **Version paquet sambaedu** : `4.17.285`
- **VM** : `root@192.168.122.50`
- **Path legacy** : `/var/www/sambaedu/gpo/applications.php`

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

| Pattern à remplacer | Remplacement | Raison |
|---|---|---|
| `id=[0-9a-f]{32}` | `id=__ID__` | L'ID est un `md5()` calculé à la volée — doit être fixé via `$info['id']` fixe dans le test |
| `SET id=[0-9a-f]{32}` | `SET id=__ID__` | Idem dans les headers cmd |
| `SET DOMAINSID=S-1-5-21-[0-9-]+` | `SET DOMAINSID=__SID__` | SID domaine lu via `net getdomainsid` — varie selon instance |

**Note** : dans les tests de parité, l'`$info['id']` est fixé à la même
valeur que celle utilisée lors de la capture (valeur md5 statique ci-dessus)
pour éviter tout écart d'ID. Le DOMAINSID est normalisé car il dépend du SID
Samba de l'instance VM (non portable).

## Régénération des fixtures

Si un script upstream change (mise à jour paquet `sambaedu`), régénérer les
fixtures en relançant les commandes ci-dessus sur la VM après `apt upgrade sambaedu`.

**Ne jamais modifier manuellement les fixtures** — elles doivent refléter
exactement la sortie du legacy PHP.
