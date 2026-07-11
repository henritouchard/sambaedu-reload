# Audit — Dépendances système de SE5 et route vers un Debian vierge

> **But du document.** Aujourd'hui SE5 (`sambaedu-reload`, Laravel) ne s'installe pas *from scratch* :
> il se pose **par-dessus un SE4 déjà packagé** (10 paquets Debian `sambaedu-*` en 4.17.36).
> De nombreux fichiers `/etc/sambaedu/…`, scripts `/usr/share/sambaedu/…` et daemons sont
> supposés présents et provisionnés par ce socle legacy.
>
> Ce document **cartographie** toutes ces dépendances (croisement code SE5 ↔ état réel de la VM),
> les **classe par nature**, et propose un **plan de découplage progressif** pour pouvoir un jour
> partir d'un Debian 13 nu.
>
> Snapshot VM de référence : `se4fs` (192.168.122.50), Debian 12/13, paquets `sambaedu-* 4.17.36`,
> Samba 4.21.5. Date de l'audit : 2026-07-01.

---

## 0. Distinction fondamentale : deux natures de dépendances

Toutes les dépendances ci-dessous ne se valent pas. Deux buckets, deux stratégies :

| Bucket | Nature | Stratégie cible |
|---|---|---|
| **A — Couplage legacy SE4** | Fichiers/scripts/crons/PHP fournis par les paquets `sambaedu-*` et par l'installeur `se4install`. Spécifiques à l'ancien produit. | **Éliminer** : internaliser dans SE5 ou porter en natif. |
| **B — Dépendance plateforme** | Briques OS légitimes qu'un contrôleur de parc *doit* utiliser quel que soit le produit : Samba/AD, Kerberos, ACL POSIX, quotas XFS, CUPS, DHCP, Apache/PHP-FPM, PostgreSQL. | **Posséder & provisionner** : SE5 fournit lui-même l'installeur qui les configure sur un Debian nu. |

La cible « Debian vierge » = **bucket A éliminé** + **bucket B provisionné par un installeur SE5** (et non plus par les paquets `sambaedu-*`).

---

## 1. Le socle actuel — paquets Debian `sambaedu-*` (4.17.36)

Ce que SE5 trouve « déjà là » aujourd'hui, et ce que chaque paquet dépose :

| Paquet | Dépose (extrait) | Rôle pour SE5 |
|---|---|---|
| `sambaedu-config` | `/etc/sambaedu/` (dir), `sambaedu.conf.d/`, `/usr/share/sambaedu/includes/{config,utils}.inc.sh`, `scripts/set_config.sh` | Structure de la config centrale + parseur shell |
| `sambaedu-web-common` | `/etc/sambaedu/applications/*` (templates firefox/thunderbird/veyon/wallpaper), `/usr/share/sambaedu/applications/*`, `sudoers.d/sudoers-sambaedu`, apparmor, crons | Templates d'apps + **sudoers `www-admin ALL NOPASSWD`** + tickets Kerberos |
| `sambaedu-shares` | `/etc/skel/user.windows/*`, `/usr/share/sambaedu/sbin/{logon,update-share,rsync_se3}.sh`, `shares.avail/mkhome.sh`, cron cloud | Squelette home Windows + scripts de partages |
| `sambaedu-wpkg` | `/var/sambaedu/unattended/install/wpkg/*` (autoit, 7za, wpkg-client.vbs, wpkg.cmd), `/var/www/sambaedu/wpkg/*.php`, cron import | Chaîne de déploiement WPKG |
| `sambaedu-boot-server` | `/usr/share/sambaedu/sbin/make_dhcpd_conf.sh`, cron `*/5` | Régénération `dhcpd.conf` |
| `sambaedu-ad-dc` / `sambaedu-ad-client` / `sambaedu-winbind` | conf Samba/AD/winbind | Intégration domaine |
| `sambaedu-proxy-config` | conf proxy | Proxy sortie |
| `sambaedu-php-libs` | `/var/www/sambaedu/{composer.json,vendor}` | Libs PHP legacy |

> **⚠️ Point le plus critique.** La config centrale **`/etc/sambaedu/sambaedu.conf`** (IP, LDAP, secrets
> SQL/AD, realm…), la **`www-sambaedu.keytab`**, **`id_rsa`**, **`reservations.inc`**, **`/etc/sambaedu/hashes`**
> et **`applications/gpos.json`** **n'appartiennent à AUCUN paquet** (`dpkg -S` → « aucun chemin ne correspond »).
> Ils sont **générés au runtime** par `set_config.sh` / `se4install` / SE5 lui-même. C'est la vraie racine
> du couplage : un Debian vierge n'a pas ce fichier, or SE5 le lit dès le boot (`LdapRecordServiceProvider`).

---

## 2. Cartographie des dépendances par domaine

Légende bucket : **[A]** couplage legacy · **[B]** plateforme.

### 2.1 Config centrale `/etc/sambaedu/*` — **[A]**

| Chemin | Consommé par (code) | Usage |
|---|---|---|
| `/etc/sambaedu/sambaedu.conf` | `app/Config/SambaEduConfig.php:41` (`MAIN_CONFIG_FILE`), `LdapRecordServiceProvider.php:41-143`, `GroupRepository.php:443`, `AgentBootstrapPublisher.php:466-512` | Lecture **+ écriture** (`parse_ini`/`write_param`) : LDAP, réseau, secrets |
| `/etc/sambaedu/sambaedu.conf.d/` | `SambaEduConfig.php:42`, `RemoteAccessService.php:62` (guacamole.conf), `update.sh` (dhcp.conf) | Surcharges `.conf` |
| `/etc/sambaedu/credentials.conf` | `SambaEduConfig.php:407` | Credentials LDAP/SE4 |
| `/etc/sambaedu/hashes` | `ServiceCredentials.php:303`, `ServiceCredentialTotpManager.php:39-89`, `config/sambaedu.php:283` | Tokens TOTP legacy |
| `/etc/sambaedu/reservations.inc` | `DhcpService.php:276-540`, `DhcpReservation.php`, `config/sambaedu.php:441` | Réservations DHCP (dérivé de la table SQL) |
| `/etc/sambaedu/id_rsa` (+`.pub`) | `MachinePowerService.php:199,297` | Clé SSH pour reboot/poweroff postes |
| `/etc/sambaedu/applications/` | `AppCustomizationService.php:188-231`, `ShortcutsService.php`, `config/wallpapers.php`, `config/app-customizations.php:29` | Surcharges admin (firefox, thunderbird, raccourcis, wallpaper) |
| `/etc/sambaedu/applications/gpos.json` | `Doctor/Checks/Gpo/GpoTemplateVersionPinCheck.php:19` | Versions GPO publiées |
| `/etc/sambaedu/applications/winget/{add,remove}.json` | `WingetPackagesResolver.php:122,150` | Surcharge catalogue winget |

### 2.2 Ressources paquet `/usr/share/sambaedu/*` (défauts read-only) — **[A]**

| Chemin | Consommé par | Usage |
|---|---|---|
| `/usr/share/sambaedu/gpo/` | `config/sambaedu.php:555`, `AgentBootstrapPublisher.php:292` | Templates GPO livrés |
| `/usr/share/sambaedu/sbin/make_dhcpd_conf.sh` | `DhcpService.php:335`, `config/sambaedu.php:443` | Régénère `dhcpd.conf` (exécuté via sudo) |
| `/usr/share/sambaedu/scripts/make_wine_image.sh` | `GenerateWineImageJob.php:20-49` | Génère image Wine (`Process::run`) |
| `/usr/share/sambaedu/scripts/install` | `SystemStatus/Distro.php`, `RunDistroInstallScriptJob.php` | Scripts install distro |
| `/usr/share/sambaedu/applications/{associations,firefox,thunderbird,winget,wallpaper}/*` | seeders + `config/app-customizations.php` | Templates défaut d'apps |

#### 2.2bis Suivi de versionnement — scripts exécutés au runtime par SE5

Ces scripts sont **invoqués par le code SE5** mais ne vivent que dans les paquets `sambaedu-*`
(figés en 4.17.36) : un déploiement sans le socle SE4 les perdrait silencieusement
(`update.sh:738` se contente déjà d'un warning si `make_dhcpd_conf.sh` est introuvable).

**Convention cible** : tout script exécuté au runtime par SE5 est versionné dans **`scripts/system/`**
du repo et déployé de façon idempotente par `update.sh` (fonction `ensure_*`), le chemin canonique
`/usr/share/sambaedu/…` restant le contrat d'exécution (sudoers, crons, `config/sambaedu.php`).
L'écrasement de fichiers possédés par dpkg est assumé — les paquets 4.17.36 ne bougeront plus.

| Script | Paquet source | Consommé par | Versionné SE5 ? |
|---|---|---|---|
| `sbin/make_dhcpd_conf.sh` | `sambaedu-boot-server` | `DhcpService.php:335`, `update.sh:727`, cron `*/5` | ❌ → **story sous-réseaux DHCP** (copie `scripts/system/` + `ensure_dhcp_scripts()`) |
| `sbin/dhcp-dyndns.sh` | `sambaedu-web-common` | hooks `on commit/release/expiry` du `dhcpd.conf` généré | ❌ → même story (compagnon indissociable du précédent) |
| `includes/config.inc.sh` (+ `utils.inc.sh`) | `sambaedu-config` | sourcé par `make_dhcpd_conf.sh` et les autres scripts sbin | ❌ — reste [A], traité en Vague 1 (config auto-portée) |
| `scripts/make_wine_image.sh` | `sambaedu-web-common` | `GenerateWineImageJob.php:20-49` | ❌ — Vague 3 |
| `scripts/install-{debian,ubuntu,primtux,nird}-*-iso.sh` | **aucun — absents de la VM** | `SystemStatus/Distro.php`, `RunDistroInstallScriptJob.php` | ❌ référencés mais **inexistants** (gap connu du pipeline ISO) |
| `sbin/renew_ticket.sh`, `sbin/smbstatus.sh`, `sbin/check_config.sh` | `sambaedu-web-common` | crons legacy uniquement (pas d'appel direct SE5) | ✅ Story 38.5 — déclenchement re-possédé : `renew_ticket`/`smbstatus` → `sambaedu-system.cron` (scripts inchangés dans `/usr/share/sambaedu`, internalisation = Vague 3) ; `check_config.sh` ABANDONNÉ (canal config→web legacy mort) |

### 2.3 Samba / AD / Kerberos / SYSVOL — **[B]** (binaires) + **[A]** (chemins legacy)

| Élément | Consommé par | Usage |
|---|---|---|
| `samba-tool` (`/usr/bin/samba-tool`) | `SambaToolRunner.php:136` (wrapper central), `GpoService.php` (gpo listall/show/create/fetch/setlink…), `AdUserManager.php` (user create/setpassword), `AdMachineManager.php` (computer create/delete) | Pilotage AD/GPO |
| `smbclient` | `AgentBootstrapPublisher.php` (écriture SYSVOL ss ticket krb), `PrintDriverService.php:204`, `PowerShellRemoteService.php:195` | Accès SMB/SYSVOL |
| `rpcclient` | `PrintDriverService.php:189` | Drivers imprimantes |
| `winexe` | `PowerShellRemoteService.php:102,146` | Exécution PowerShell distante sur postes |
| `kinit`/`klist`/`kdestroy` | `AgentBootstrapPublisher.php:357-763`, `KerberosTicketCheck.php` | Tickets Kerberos (ccache dédié via `KRB5CCNAME`) |
| `/var/lib/samba/sysvol` | `config/sambaedu.php:545`, `GpoServiceProvider.php:107` | Policies GPO (.pol/.ini) |
| `/var/lib/samba/private/{sam.ldb,passdb.tdb,secrets.keytab}` | `SambaToolRunner.php:155`, Doctor checks | Détection DC + ACL |
| `/etc/krb5.keytab`, `www-sambaedu.keytab` | `KerberosTicketCheck.php:106`, crons | Keytabs |
| `/etc/samba/smb.conf` + `smb.conf.d/partages.conf` | `PartagesShareCheck.php:56`, `update.sh:589` | Export SMB `[partages]` |

### 2.4 ACL / Filesystem / Partages / Quotas — **[B]** (outils) + **[A]** (arbo)

| Élément | Consommé par | Usage |
|---|---|---|
| `setfacl`/`getfacl` (sudo) | `AclService.php:124-312`, `NetworkShareService.php:275-667`, `ShareService.php`, `Acl/*` | ACL POSIX des partages |
| `xfs_quota` (sudo) | `XfsQuotaService.php:297-500`, `QuotaSnapshotCommand.php:178` | Quotas XFS |
| `du`/`rsync`/`tar` | `RoamingProfileService.php:496`, `StatsService.php:87`, `FileManagerService.php:478` | Usage disque / backup / extraction |
| `chown/chmod/mkdir/cp/mv/rm` (sudo, cible `www-admin:www-admin`, groupe `domain admins`) | `HomeDirService.php:44-204`, `NetworkShareService.php:498`, `ShareService.php:860` | Provisioning répertoires |
| `/var/sambaedu/Partages` | `config/filesystem.php:35`, `NetworkShareService.php:22` | Racine partages réseau |
| `/var/sambaedu/Classes`, `/Progs`, `/Docs` | `ShareService.php`, `SharesSeedEtablissementCommand.php:13` | Partages classes/étab |
| `/home/{login}`, `/home/trash`, `/home/profiles`, `/home/admin/_Trash_users` | `HomeDirService.php`, `RoamingProfileService.php` | Homes + profils itinérants + corbeilles |
| `/etc/skel/user.windows` | `HomeDirService.php:52` | Squelette home Windows |

### 2.5 WPKG / AppStore / déploiement logiciel — **[A]**

| Chemin | Consommé par | Usage |
|---|---|---|
| `/var/sambaedu/unattended/install` | `config/sambaedu.php:449,477`, `PackageInstallerService.php:29`, `LegacyWpkgImporter.php:51` | Racine binaires WPKG |
| `…/wpkg` (+`/ini`,`/rapports`,`packages.xml`) | `config/sambaedu.php:458-487`, `WpkgProcessReportsCommand.php` | Déploiement + rapports |
| `…/wine` | `config/sambaedu.php:602`, `WinePrefixScanner.php` | Conteneurs Wine |
| `/var/se4fs/wpkg`, `/var/se4/wpkg/local` | `AppStoreService.php:38`, `DepotSeeder.php:40` | Dépôts WPKG |

### 2.6 iPXE / ISO Windows / WinPE — **[A]** (arbo) + **[B]** (outils)

| Élément | Consommé par | Usage |
|---|---|---|
| `/var/sambaedu/unattended/install/os` (+`/nird`,`/winpe`,`/winpe-drivers`) | `config/ipxe.php:227-524`, `WindowsIsoExtractor.php`, `DownloadWindowsIsoJob.php` | OS déployés + montage ISO |
| `mount`/`umount`/`cp` (sudo -n) | `WindowsIsoExtractor.php:71-132` | Montage ISO en boucle |
| `wimlib-imagex` | `WinpeDriverInjector.php:54-245` | Injection drivers WinPE |
| `curl` | `DownloadWindowsIsoJob.php:191` | Téléchargement ISO |
| `/bin/gparted/*`, `/bin/pxelinux.0` | `config/ipxe.php:651` | Boot GParted/HDT |

### 2.7 DHCP / Réseau / Parc — **[B]** (daemon) + **[A]** (script legacy)

| Élément | Consommé par | Usage |
|---|---|---|
| `/var/lib/dhcp/dhcpd.leases` | `DhcpService.php:28-424`, `WorkstationAddressResolver.php` | Lecture des baux |
| `make_dhcpd_conf.sh` (sudo) + `isc-dhcp-server.service` | `DhcpService.php:335,372` | Régénération + reload DHCP |
| `wakeonlan`, `fping`, `ping`, `net`, `ssh` | `MachinePowerService.php:49-297`, `DcReachableCheck.php:33` | WOL / ping / reboot postes |

### 2.8 Impression (CUPS) — **[B]**

| Élément | Consommé par | Usage |
|---|---|---|
| `lpstat`/`lpinfo`/`lpadmin`/`cupsenable`/`cupsdisable` (sudo, `LC_ALL=C`) | `CupsPrinterService.php:93-350` | Gestion files CUPS |
| `/var/lib/samba/printers/x64/` | `PrintDriverService.php:35-872` | Dépôt drivers Windows |
| `smbcontrol` | `CupsPrinterService.php:537` | Reload Samba |

### 2.9 Legacy PHP `/var/www/sambaedu` + crons — **[A]** (à supprimer en priorité)

| Élément | Consommé par | Usage |
|---|---|---|
| `/var/www/sambaedu` | `config/sambaedu.php:50` (`LEGACY_PATH`), `LegacyConfigBridge.php:29`, `LegacyEmbedService.php:31` | Include `config.inc.php`, `ldap.inc.php`, `functions.inc.php` |
| `/var/www/sambaedu/blank.php` | `LegacyCatchallController.php:29` | Fallback routes non migrées |
| `/var/www/sambaedu/temp/policies` | `config/sambaedu.php:559` | Répertoire travail `samba-tool gpo fetch` |
| **Crons legacy** (`/etc/cron.d/sambaedu-*`) | via `action_cron_php.sh` | `wpkg_depot_import`, `wpkg_rapport`, `wpkg_ldap_update`, `sync_cron` (annuaire), `repquota`, `stats`, `clean_connexions`, `clean_profiles`, `renew_ticket.sh`, `smbstatus.sh`, `check_config.sh`, `make_dhcpd_conf.sh` |
| Pont TOTP/tokens APCu | `LegacyBootstrapTokenValidator.php:92-204` (`apcu_fetch('apps.<token>')`) | Partage de session avec le legacy |

> Le seul cron « propre » SE5 est `sambaedu-scheduler` (`* * * * * www-admin … artisan schedule:run`).
> Tout le reste appartient au legacy et devra être porté ou supprimé.

**Story 38.5 — volet crons SOLDÉ (décision PAR FONCTION).** Retrait idempotent des 3
fichiers cron legacy (`ensure_legacy_crons_retired` dans `update.sh` — liste EXPLICITE
`sambaedu-{web-common,shares,wpkg}`, jamais un glob ; `mv` vers
`/var/backups/sambaedu-legacy-crons/`, réversible). Décisions ligne à ligne :

| Ligne cron legacy | Décision 38.5 | Successeur / trace |
|---|---|---|
| `renew_ticket.sh` (×2, dont `@reboot`) | **RE-POSSESSION SE5** | `scripts/config/sambaedu-system.cron` (ticket Kerberos www-sambaedu VITAL SYSVOL, `project_sysvol_write_needs_wwwadmin_kinit`) |
| `smbstatus.sh` | **RE-POSSESSION SE5** | `sambaedu-system.cron` (`/tmp/smbstatus` lu par `UserSessionsService`) |
| `check_config.sh` | ABANDON | canal config→web legacy mort ; successeur = capacités 27.3 (`project_config_capabilities_model`) |
| `parcs/action_cron.php` | portage acté | `parc:execute-group-schedules` (`Kernel.php:35`) |
| `parcs/clean_connexions.php` | ABANDON | présence agent (check-in + shutdown, 4 états) |
| `infos/repquota.php` | ABANDON | `quota:snapshot` (Story 5.1b, `Kernel.php:97`) |
| `stats.php` / `stats/update_stats.php` | ABANDON | backlog « chantier fréquentation » (signal présence agent) |
| `annu/sync_cron.php` / `mfa_ent.php` / `test_ent.php` | **ABANDON acté (Q2)** | réouvrable en epic dédié ENT sur besoin réel |
| `annu/delete_temp_users.php` | ABANDON | notion « users temporaires » legacy sans équivalent ; cycle de vie natif + `trash:purge` |
| `clean_profiles.sh` | ABANDON (Q3) | `profiles:snapshot` (`Kernel.php:186`) + purge `RoamingProfileService:741` ; caches navigateur `/home` non reconduits |
| `wpkg/wpkg_depot_import.php` | portage acté | import dépôt AppStore (série 8.2.x, `ensure_appstore_catalog_sync`) |
| `wpkg/wpkg_rapport.php` | portage acté | rapports WPKG natifs (9.4/9.5, canal SMB) |
| `wpkg/wpkg_ldap_update.php` | portage acté | `users:sync-from-ad` / `user-groups:sync-from-ad` / MachineObserver |
| `partages/rep_cloud_cron.php` | ABANDON | chantier Nextcloud cadré (`project_nextcloud_file_plane_direction`) |
| `systemctl restart php8.2-fpm` (cron `-shares`) | ABANDON | hygiène anti-fuites legacy non reconduite (décision ops, pas héritage silencieux) |
| `make_dhcpd_conf.sh` (`sambaedu-boot-server`) | **NON TOUCHÉ — gating Story 8.3** | fonction vivante (dhcpd.conf/DNS) ; remplacement = 8.3 (versionne le script + retire l'appel `script_make_reservations.php`) |

### 2.10 Services système supposés actifs — **[B]**

Observés tournants sur la VM : `apache2`, `php8.2-fpm`, `smbd`, `nmbd`, `winbind`, `isc-dhcp-server`,
`rpcbind`, `cron`. (`samba-ad-dc` **inactif** ici → l'AD-DC est distant/legacy.)
Attendus par le code : + PostgreSQL, CUPS, workers systemd `laravel-queue-{general,sync}`
(`WorkerMonitoringService.php:14`).

### 2.11 Utilisateurs / groupes / privilèges — **[A]** (sudoers) + **[B]** (comptes AD)

| Élément | Consommé par | Note |
|---|---|---|
| **`www-admin` (uid 599)** propriétaire fichiers servis | `config/auth_v1.php:119`, `CaInitializer.php:605`, chown généralisés | Pool PHP-FPM dédié |
| **`/etc/sudoers.d/sudoers-sambaedu` → `www-admin ALL NOPASSWD: ALL`** | tous les `sudo …` (setfacl, xfs_quota, mount, lpadmin, systemctl, chown) | **Sudo total — à réduire à un allowlist** |
| `www-data` (mode `0660 root:www-data` sur sambaedu.conf) | `SambaEduConfig.php:245` | Écriture config |
| `www-sambaedu`, `se4install` | `MainGroups.php:39-44`, `config/sambaedu.php:280` | Comptes AD de service |

### 2.12 Extensions PHP & packages wrappant du système — **[B]**

- `ext-apcu` (obligatoire, + pont token legacy), `ext-imagick`, `ext-ldap`, `pdo_pgsql`/`pdo_mysql`, PHP `^8.2`.
- Binaire **git** via `czproject/git-php` ; **sendmail** (`config/mail.php:70`) ; TOTP (`robthree/twofactorauth`).
- HTTP externes : ControlHub, InfluxDB, Proxmox VE, BigBlueButton, CAS, GLPI, Nominatim, WebDAV.

### 2.13 PKI / Auth V1 — **[B]** (mais bien internalisé)

Clés par défaut sous `storage_path('keys/...')` (dans le projet), surchargeables vers l'hôte.
`server.key/crt` lus par le vhost Apache HTTPS (`CaInitializer.php:455`). Génération 100 % `openssl` en `Process::run`.

---

## 3. Ce qui manque à un Debian vierge (checklist de provisioning)

Pour qu'un `apt install se5` sur Debian nu suffise, l'installeur SE5 devra fournir/poser :

1. **Paquets APT** : `samba samba-ad-dc winbind smbclient krb5-user ldap-utils acl xfsprogs
   isc-dhcp-server cups apache2 php8.2-fpm postgresql wimlib-tools wakeonlan fping rsync curl
   php8.2-{ldap,pgsql,apcu,imagick} openssl git msmtp/sendmail`.
2. **Arborescence** : `/var/sambaedu/{Partages,Classes,Progs,Docs,unattended/install/{wpkg,os,wine}}`,
   `/home/{profiles,trash}`, `/var/lib/samba/{sysvol,private,printers/x64}`, `/etc/skel/user.windows`.
3. **Config centrale** : générer `/etc/sambaedu/sambaedu.conf` (ou son remplaçant SE5-natif) + keytab + `id_rsa`.
4. **Sudoers** scopé pour `www-admin` (setfacl/getfacl/xfs_quota/mount/umount/lpadmin/systemctl/chown ciblés).
5. **Services** : units systemd `laravel-queue-{general,sync}`, scheduler cron, activation samba/cups/dhcp.
6. **Templates** aujourd'hui dans `/usr/share/sambaedu/*` (GPO, firefox/thunderbird/winget defaults, skel) :
   à **internaliser dans le repo SE5** (`resources/`) et déployer depuis là.
7. **Vhost Apache** + PKI (déjà scriptés : `setupApache.sh`, `CaInitializer.php`).

> **Bonne nouvelle : la couverture Doctor est déjà large.** `app/Doctor/Checks/` vérifie déjà sysvol,
> samba-tool, keytabs, ticket krb, sudoers, partages, iPXE, APCu, Apache, PostgreSQL, DC joignable…
> C'est le socle idéal d'un « installeur idempotent qui répare ce qui manque ».

### 3bis. Dépendance dev/test (hors périmètre provisioning prod)

`php8.2-sqlite3` (fournit `pdo_sqlite`) **n'est pas** un paquet de prod (absent de la checklist §3) :
la suite PHPUnit tourne par défaut en sqlite `:memory:` (`phpunit.xml`), et la VM n'a normalement
que `mysql,pgsql` (cf. §2.12). Installé manuellement en dev-dependency le 2026-07-11 sur `se4fs`
(192.168.122.50) pour permettre `php artisan test` directement sur cette VM. Non versionné dans
l'installeur SE5 — à réinstaller (`apt-get install -y php8.2-sqlite3`) sur toute VM de dev/test qui
en aurait besoin. L'hôte reste l'environnement de référence pour lancer la suite (cf.
`docs/qa/domains/controlhub-contract.md`).

---

## 4. Plan de découplage progressif

Ordonné par dépendance et par risque (du moins au plus intrusif).

### Vague 0 — Instrumenter (préalable, faible risque)
- Compléter les Doctor checks pour couvrir **100 %** des dépendances des §2 (chaque chemin/binaire/daemon
  = un check avec remède). Objectif : `artisan sambaedu:doctor` sur un Debian nu liste exactement ce qui manque.
- Auditer et **externaliser en `config()`/`env()` tous les chemins « en dur »** (repérés « en dur » au §2 :
  `gpo.bin_path`, `gpo.sysvol_path`, `policies_temp_path`, `wine.*`, `wpkg.{deploy,ini,reports}_path`).

### Vague 1 — Rendre la config auto-portée
- SE5 **possède** sa config : `.env` + `config/*.php` comme source de vérité ; `sambaedu.conf` devient
  une **projection écrite par SE5**, plus une source lue. Retirer la lecture au boot dans
  `LdapRecordServiceProvider` au profit de `.env`.
- Couper définitivement le canal legacy : chaque route client encore appelée reçoit une réponse
  native **terminale, typée et inerte** (tombstones, story 38.2) — le kill-switch
  `LEGACY_CONFIG_CHANNEL_ENABLED` (sémantique 410) a été RETIRÉ, remplacé par ces tombstones.
- Supprimer le pont TOTP `/etc/sambaedu/hashes` et le pont token APCu `apps.<token>` (autonomie session).

### Vague 2 — Internaliser les templates `/usr/share/sambaedu/*`
- Rapatrier dans `resources/` du repo SE5 : templates GPO, defaults firefox/thunderbird/winget,
  associations, `user.windows` skel, wallpapers défaut. SE5 les déploie lui-même.
- Effet : plus besoin de `sambaedu-config` / `sambaedu-web-common` / `sambaedu-shares` pour les défauts.

### Vague 3 — Porter les scripts shell legacy en natif SE5
- Remplacer `make_dhcpd_conf.sh` (génération dhcpd.conf → job PHP + template), `make_wine_image.sh`,
  `logon.sh`/`mkhome.sh` (déjà partiellement couverts par `HomeDirService`), `renew_ticket.sh`
  (→ job scheduler + keytab), `clean_profiles.sh`.
- Effet : plus de dépendance à `/usr/share/sambaedu/{sbin,scripts}` ni aux paquets `sambaedu-boot-server`/`-shares`.

### Vague 4 — Retirer le legacy PHP `/var/www/sambaedu`
- ✅ **Volet crons FAIT (Story 38.5)** : `/etc/cron.d/sambaedu-{web-common,wpkg,shares}` retirés
  (`ensure_legacy_crons_retired`, décision par fonction ci-dessus §2.9) ; lignes vitales
  re-possédées (`sambaedu-system.cron`). `sambaedu-boot-server` (`make_dhcpd_conf.sh`)
  **renvoyé à Story 8.3** (dernier `curl *.php` serveur résiduel — disparaîtra avec 8.3,
  AVANT le GO 38.6). Aucun portage de cron n'a été fait dans 38.5 (les portages étaient
  déjà livrés — quota:snapshot, appstore sync, sync-from-ad — ou abandonnés/renvoyés backlog).
- Supprimer le catch-all `LegacyCatchallController` une fois toutes les routes migrées.
- Effet : plus de `sambaedu-php-libs`, plus de `LEGACY_PATH`.

### Vague 5 — Installeur greenfield SE5 + durcissement
- Un **paquet/installeur SE5** (ou `se5-*.deb`) qui, sur Debian nu : installe les paquets APT du §3.1,
  crée l'arborescence, pose sudoers scopé, units systemd, vhost, PKI, et lance `doctor --fix`.
- Réduire `www-admin ALL NOPASSWD: ALL` à un **allowlist** précis.
- Retirer les paquets `sambaedu-*` de la cible d'install.

### Récapitulatif d'ordonnancement

| Vague | Débloque | Paquets legacy retirables |
|---|---|---|
| 0 | Visibilité + chemins configurables | — |
| 1 | Config autonome | (prépare `sambaedu-config`) |
| 2 | Templates internalisés | `sambaedu-web-common`, une partie `-shares` |
| 3 | Scripts natifs | `sambaedu-boot-server`, reste `-shares` |
| 4 | Legacy PHP éteint | `sambaedu-php-libs`, `-wpkg` (crons) |
| 5 | Install from scratch | `sambaedu-config`, `-ad-*`, `-proxy-config` |

---

## 5. Ce qu'on NE cherche PAS à éliminer

Ces dépendances sont **plateforme** (bucket B) : elles restent, mais provisionnées par SE5 et non par SE4.
Samba/AD-DC, Kerberos, `setfacl`/ACL POSIX, quotas XFS, CUPS + drivers, DHCP (isc), Apache + PHP-FPM,
PostgreSQL, `wimlib`/`mount` (ISO), `wakeonlan`/`fping`, `openssl` (PKI), extensions PHP.
L'enjeu n'est pas de les supprimer mais de **maîtriser leur installation et leur configuration** depuis SE5.
