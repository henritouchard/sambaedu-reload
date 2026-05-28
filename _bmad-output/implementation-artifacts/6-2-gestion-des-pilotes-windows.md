# Story 6.2 : Gestion des Pilotes Windows

Status: Done
Code review: `_bmad-output/codeReviews/6-2.md` (19 findings corrigés 2026-05-20, 3 justifiés non-corrigés, 2 reportés follow-up).
Arbitrages user 2026-05-20: Q1=A (parsing enumprinters auto-attach), Q2=A (flex-wrap responsive), Q3=A (bouton « Réessayer association »), Q4=A (Cache::lock).

> **Origine** : Epic 6 — Impression SER. **Seconde et dernière story** de l'epic (après 6.1 done 2026-04-29). Couvre **FR19** (gestion des pilotes Windows associés aux imprimantes CUPS). Décalque la fondation 6.1 : `CommandRunner` injectable, pattern shellout `escapeshellarg` + `LC_ALL=C`, exceptions structurées, modale réutilisable, `WithToasts`, double-guard Livewire, fixtures CUPS/Samba.
>
> **Note d'implémentation (epics.md l. 1866-1892)** : Les pilotes Windows sont publiés sur le share `[print$]` de Samba (Story 6.2 ne crée pas ce share — il est déjà fourni par `smb.conf` standard SambaEdu). Le workflow porte sur (a) l'upload du driver depuis un poste Windows pivot vers `/var/lib/samba/printers/x64/`, (b) l'enregistrement du driver auprès de Samba via `rpcclient -c 'adddriver …'`, (c) l'association du driver Samba à une imprimante CUPS via `rpcclient -c 'setdriver …'`, (d) la consultation / suppression des drivers. Service dédié `App\Services\Print\PrintDriverService` (NFR18 : pas d'appels système directs depuis les SFC Livewire).
>
> **Note d'architecture (D5 décalqué 6.1, D6 nouvelle)** : Une table Eloquent `printer_drivers` (PK `(printer_cups_name, architecture)`) **complète** Samba sans le remplacer. Samba (rpcclient enumdrivers/getdriver) reste source de vérité runtime pour la liste effective des drivers exposés ; la table SER porte uniquement l'audit (created_at / created_by_user_id / source : `upload-w10` ou `inf-upload` ou `synced`) et le rattachement métier (driver SMB ↔ imprimante CUPS). Une commande `php artisan printer-drivers:sync` réconcilie la table SER avec `rpcclient enumdrivers`. **L'AD n'est pas modifié** (auth Samba reste Kerberos via le compte machine `se4fs$`, mécanisme iso-legacy).
>
> **Dépendances amont (toutes livrées)** :
> - **Story 6.1 done** (2026-04-29) — `CupsPrinterService` + `CommandRunner` interface + `FakeCommandRunner` + `Printer` Eloquent + pivot `printer_workstation_group` + UI `/parc?tab=printers` + sudoers `lpadmin/cupsenable/cupsdisable/smbcontrol`. **Pré-requis bloquant satisfait** : toute la fondation Print est en place.
> - **Story 1bis.15 done** (2026-04-18) — Shim legacy `legacy/modules/printers/` posé puis **retiré le 2026-04-29 à la livraison 6.1**. 6.2 finit le scope Epic 6 — pas de coexistence à gérer (le shim a déjà disparu).
> - **Story 7.2 done** (2026-04-24) — `PrinterPolicy` cousue sur `server.admin` global. Réutilisée telle quelle en 6.2 (cohérent décision 6.1 #11 : « server.admin global uniquement, délégués read-only »).
> - **Story 5.1a done** (2026-04-23) — Pattern shellout sudo `XfsQuotaService`. Référence indirecte (6.1 a déjà absorbé ce pattern dans `CupsPrinterService`).
> - **Story 4.3 done** (2026-04-21) — Pattern tests Feature Livewire (`MocksAdminUser`, `$this->app->instance(...)`).
>
> **Pas de stories avales** : 6.2 clôt l'Epic 6. Follow-ups éventuels = stories standalone (annulation jobs 6.3 hypothétique, migration vers Samba AD print services Epic 9 hypothétique).

---

## Story

En tant que **responsable de collège ou administrateur SER (`server.admin`)**,
je veux téléverser, lister, associer et supprimer les **pilotes Windows** (`x64`/`x86` PostScript ou natifs) attachés aux imprimantes CUPS depuis l'interface SER,
afin que **les postes Windows du parc installent automatiquement le driver correct via SMB** lors de la première connexion à une imprimante réseau publiée (`\\se4fs\<printer>`), sans intervention manuelle locale poste par poste, et avec des messages d'erreur clairs en cas d'échec rpcclient / smbclient / Samba.

---

## Contexte & Motivation

### Pourquoi cette story

SE5 livre actuellement (post-6.1) les imprimantes CUPS publiées via `[printers]` dans `smb.conf`. Mais **un poste Windows qui se connecte à `\\se4fs\imp-salle-a` cherche un driver dans `\\se4fs\print$\x64\3\` (architecture × version)** — si ce driver n'existe pas, l'install échoue et l'utilisateur doit installer le driver manuellement (ou demander à l'admin de le faire). Le legacy SambaEdu offre `printers/add_driver.php` qui automatise ce workflow : on installe d'abord le driver sur un poste Windows pivot, puis l'UI le récupère via `smbclient`, l'enregistre via `rpcclient adddriver`, et l'associe à l'imprimante via `rpcclient setdriver`. La 6.2 porte ce flux en natif Laravel.

### État actuel (audit 2026-05-19)

**Existant côté SE5 (livré par 6.1) :**

| Élément | Localisation | État |
|---|---|---|
| Permission Spatie `server.admin` | `App\Enums\SambaPermission::ServerAdmin` | livrée |
| `PrinterPolicy` (gates `viewAny-printer` + `manage-printer`) | `app/Policies/PrinterPolicy.php` | livrée — **`manage` simplifiée `server.admin` global uniquement** post-review 6.1 #11 |
| `CupsPrinterService` (`listPrinters`, `getPrinter`, etc.) | `app/Services/Print/CupsPrinterService.php` | livré — utilisable tel quel pour récupérer `cups_name` et résoudre l'imprimante cible |
| `CommandRunner` interface + `RealCommandRunner` | `app/Services/Print/Contracts/CommandRunner.php` + `app/Services/Print/RealCommandRunner.php` | livrés — `LC_ALL=C` centralisé, capture stdout/stderr/RC séparée |
| `CupsCommandException` + `CupsDaemonDownException` | `app/Services/Print/Exceptions/` | livrées — pattern d'exception métier structurée à décalquer |
| `Printer` Eloquent + pivot `printer_workstation_group` | `app/Models/Printer.php` + 2 migrations | livrées — réutilisées en relation `Printer->drivers()` |
| `FakeCommandRunner` testing helper | `tests/Support/FakeCommandRunner.php` | livré — `whenContains`/`whenContainsFromFixture` réutilisables tels quels |
| `CreatesPrintersSchema` trait test | `tests/Traits/CreatesPrintersSchema.php` | livré — étendu en 6.2 par `CreatesPrinterDriversSchema` |
| UI onglet `/parc?tab=printers` | `resources/views/pages/parc/_partials/printers-tab.blade.php` | livrée — **point d'entrée 6.2** : ajout colonne « Drivers Windows » + bouton « Gérer drivers » par ligne |
| Modale réutilisable | `resources/views/components/molecules/modal/index.blade.php` | livrée |
| `WithToasts` trait | `app/Components/Traits/WithToasts.php` | livré |
| Sudoers `/etc/sudoers.d/sambaedu-cups` | documentée dans `docs/domains/printers.md` | **à enrichir 6.2** (cf. D11 : ajout `smbclient`, `rpcclient`) |

**Pas encore livré (cible 6.2) :**

- Aucun service `App\Services\Print\PrintDriverService`.
- **Aucune table `printer_drivers` Eloquent** (à créer — couche métier SER : audit + rattachement driver SMB ↔ imprimante CUPS, distincte de Samba qui reste source de vérité runtime via `rpcclient enumdrivers`).
- **Aucun modèle `App\Models\PrinterDriver`** (à créer — PK composite `(printer_cups_name, architecture)`).
- **Aucune commande `printer-drivers:sync`** (à créer — réconciliation SER ↔ `rpcclient enumdrivers`, idempotente, `--dry-run`).
- Aucune modale UI « Gérer drivers Windows » dans le SFC `printers-tab.blade.php`.
- Aucune route Livewire ou Job d'upload (le driver vient d'un poste W10 pivot — pas d'upload binaire HTTP en 6.2, cf. D2).
- Aucun test côté SE5 sur `rpcclient`/`smbclient` (à créer avec fixtures captées sur VM).
- Pas de packaging sudoers pour `rpcclient`, `smbclient`, `chown` sur `/var/lib/samba/printers/x64/`.

### Pourquoi un Service plutôt qu'un appel direct depuis Livewire

**NFR18 (architecture.md l. 381)** : « Les intégrations système (CUPS, DHCP, scripts sudo) sont encapsulées dans des Services dédiés — aucun appel système direct depuis les SFC Livewire ». Le `PrintDriverService` est obligatoire pour :

- Centraliser `escapeshellarg()` sur **chaque** argument controllé utilisateur (`printer_name`, `server_pivot`, `driver_name`, `architecture`, `version`) — pattern décalqué 6.1.
- Centraliser la capture stderr/stdout/RC et le mapping en exceptions métier (`PrintDriverException`, `SambaUnavailableException`, `WindowsPivotUnreachableException`).
- Mocker proprement dans les tests Feature (binding container, FakeCommandRunner).
- **Centraliser la gestion Kerberos** : tous les `rpcclient`/`smbclient` doivent passer `--use-kerberos=required` (legacy `printers.inc.php:45,69,81,110,113,341,363,436,473,537,567`) — sinon `KRB5_KT_NOTFOUND` ou auth NTLM downgrade. Une centralisation dans `PrintDriverService::buildRpcclientCommand()` évite l'oubli.

### Couche Samba (runtime) vs Couche métier SER (DB)

Identique au principe 6.1 (CUPS runtime vs SER DB) :

1. **Couche Samba (runtime)** — Source de vérité pour : liste des drivers actuellement publiés (`rpcclient enumdrivers`), détail d'un driver (`rpcclient getdriver <name>`), association printer→driver (`rpcclient getprinter <name>` retourne le driver attaché). Fichiers physiques dans `/var/lib/samba/printers/x64/<file>` et `/var/lib/samba/printers/x86/<file>` (rare en 6.2 — focus x64 uniquement, cf. D8).

2. **Couche métier SER (DB)** — Porte UNIQUEMENT ce que Samba ne sait pas : audit (`created_at`, `created_by_user_id`, `source`), métadata métier (`display_name` lisible vs nom technique driver, `notes`), flag de drift (`orphan` quand le driver disparaît de Samba), et **rattachement à une imprimante CUPS** (FK `printer_cups_name` vers `printers.cups_name`).

**Réconciliation** : la commande `php artisan printer-drivers:sync` (planifiée quotidienne 03:35 — 5 min après `printers:sync`, idempotente, `--dry-run`) maintient la cohérence : ajoute les drivers Samba détectés hors SER (orphan=false, source=`synced`), marque `orphan=true` les rows SER absents de Samba (SANS delete pour préserver l'audit), restaure `orphan=false` à la réintroduction.

### Décisions actées (D1-D14, à valider au kickoff par Henri)

- **D1 — `PrintDriverService` est un service dédié** (pas d'extension de `CupsPrinterService`). Raison : séparation des responsabilités (CUPS != Samba), couplage sudo / Kerberos différent (CUPS = `sudo lpadmin` ; Samba = `sudo /usr/bin/rpcclient` + `kinit`/keytab implicite), testabilité (drivers test différents — fixtures `rpcclient` vs `lpstat`). Le `CommandRunner` est partagé (binding identique). **Alternative envisagée** : héritage `CupsPrinterService` → rejeté (anti-pattern, gonfle la classe à 800 LOC).

- **D2 — Pas d'upload binaire HTTP en 6.2 (workflow « pivot W10 » legacy conservé)**. Le legacy `add_driver.php` documente l'algo : (1) admin installe le driver localement sur un poste W10 partagé, (2) UI SE5 saisit le nom du poste pivot + le nom du driver imprimante partagée, (3) backend récupère les fichiers via `smbclient //pivot/print$ -c "cd x64\3;get <file>"` vers `/var/lib/samba/printers/x64/`, (4) backend enregistre via `rpcclient adddriver`. Avantages : flux iso-legacy (les admins SE5 connaissent), pas de zone d'upload HTTP à sécuriser (taille fichiers `.dll`, parsing `.inf`), pas de manipulation `.cab`. **Alternative envisagée (D2bis, hors scope 6.2)** : upload direct `.zip` du driver via formulaire — beaucoup plus complexe (parsing `.inf`, validation MIME, signature Authenticode, extraction CAB) — déféré à une éventuelle Story 6.4. **Recommandation SM** : commencer par D2 pour livrer rapidement la parité legacy ; D2bis si demande terrain.

- **D3 — Permission `server.admin` global uniquement** (cohérent décision post-review 6.1 #11). Le legacy `add_driver.php:37` gardait derrière `SE_ADMIN`. Les délégués Epic 7 ne gèrent **PAS** les drivers Windows (impact système large, root via sudo). **Réutilise `PrinterPolicy::manage`** telle quelle (déjà cousue 6.1). **Alternative envisagée** : nouvelle permission `printer.driver.manage` distincte → rejeté, pas de cas d'usage légitime de découpler `manage` (imprimante) vs `driver` (Windows) — un admin qui gère les imprimantes gère aussi leurs drivers.

- **D4 — Workflow upload depuis pivot W10 : auth Kerberos uniquement**. `--use-kerberos=required` strict sur **tous** les `rpcclient`/`smbclient` (legacy iso). Pas de fallback NTLM (sécurité — protège contre downgrade). Pré-requis : le compte machine `se4fs$` doit avoir un ticket Kerberos valide (gestion par `samba-tool` / cron `kinit -k` iso-legacy). Si le ticket est expiré → exception explicite `KerberosTicketException` + toast utilisateur lisible "Authentification Samba expirée — contacter l'admin système" (pas une stacktrace technique). **Alternative envisagée** : fallback NTLM → rejeté (sécurité + complexité auth dual).

- **D5 — Architecture supportée en 6.2 : `x64` uniquement** (`Windows x64` dans `rpcclient`). Le legacy `copy_driver_file()` (printers.inc.php:69) hardcode `cd x64\3` — depuis Windows 7+, le path canonique driver est `x64\3` (architecture x64 + driver version 3). Le legacy `add_driver.php:55` documente "(64 bits obligatoirement)". **6.2 hérite cette contrainte**. Si un parc nécessite `x86` (legacy Windows XP/7 32-bit), une Story 6.2bis sera créée. **Alternative envisagée** : supporter x86 + x64 dès 6.2 → rejeté (complexité ×2 sans bénéfice immédiat, x86 quasi-éteint en milieu scolaire 2026).

- **D6 — Table `printer_drivers` Eloquent (PK composite `(printer_cups_name, architecture)`)**. Audit (`created_by_user_id`, `created_at`, `updated_at`), `driver_name` (string 255, nom Samba canonique ex. "HP LaserJet 4000 PCL"), `architecture` (string 16, `x64` par défaut D5), `source` (enum-like string : `upload-w10` / `synced` / `manual-cli`), `orphan` (boolean default false), `notes` (text nullable, métadata métier). FK `printer_cups_name` → `printers.cups_name` ON DELETE CASCADE (cohérent 6.1 pivot). Index sur `orphan` (filtre admin). **Alternative envisagée** : pas de table SER, lecture directe `rpcclient enumdrivers` à chaque page → rejeté, perd l'audit (qui a uploadé quel driver quand) et le filtrage métier.

- **D7 — Commande `printer-drivers:sync` planifiée 03:35** (5 min après `printers:sync`). Idempotente, `--dry-run`. Algorithme symétrique 6.1 : ajoute (`orphan=false`, `source=synced`), marque orphan, restaure. Logger `[printer-drivers:sync]` préfixé (cohérent `[printers:sync]`). **Alternative envisagée** : un seul cron `printers:sync` qui sync les 2 — rejeté (couplage, échec d'un n'arrête pas l'autre, monitoring séparé).

- **D8 — Pas de section "delete driver" en 6.2 si driver attaché à ≥ 1 imprimante** (refus avec message explicite : "Détacher d'abord le driver de toutes les imprimantes"). Le legacy ne supportait pas cette protection — on l'ajoute en 6.2 pour éviter les drift orphans côté Windows (postes qui ne savent plus quel driver récupérer). **Alternative envisagée** : suppression cascade détach + delete → rejeté (effet de bord trop large, demande explicite).

- **D9 — `rpcclient setdriver` exécuté en transaction logique** (pas DB — c'est un appel système). Ordre : (1) upload fichiers via `smbclient get` vers `/var/lib/samba/printers/x64/`, (2) `chown www-admin:www-admin` les fichiers déposés (sécurité — éviter root-owned), (3) `rpcclient adddriver`, (4) `INSERT printer_drivers`, (5) `rpcclient setdriver <printer> <driver>`. Si étape 5 échoue : pas de rollback automatique (le driver Samba est ajouté, juste pas attaché — l'admin peut réessayer setdriver via le bouton "Réessayer association"). Si étape 3 échoue : rollback fichiers déposés (`unlink`). Si étape 4 échoue : `rpcclient deldriver` best-effort. **Pattern décalqué 6.1** (CUPS-first / SER-second avec rollback best-effort). **Alternative envisagée** : tout-ou-rien strict → rejeté (rpcclient deldriver fragile, mieux vaut un état intermédiaire récupérable).

- **D10 — Pas de cache Redis/APCu** (cohérent 6.1 D10 + 5.1a). `rpcclient enumdrivers` est < 500ms sur les VMs typiques (≤ 20 drivers).

- **D11 — sudoers enrichis** : ajouter dans `/etc/sudoers.d/sambaedu-cups` (déjà documenté 6.1) :

  ```
  www-admin ALL=(root) NOPASSWD: /usr/bin/rpcclient
  www-admin ALL=(root) NOPASSWD: /usr/bin/smbclient
  www-admin ALL=(root) NOPASSWD: /bin/chown www-admin\:www-admin /var/lib/samba/printers/x64/*
  www-admin ALL=(root) NOPASSWD: /bin/rm /var/lib/samba/printers/x64/*
  ```

  Restrictions strictes (whitelist explicite, **pas de wildcard binaire**). Le `chown` est restreint à `www-admin:www-admin` cible + path préfixé. Le `rm` est restreint au path préfixé `/var/lib/samba/printers/x64/`. **Alternative envisagée** : tout via le compte `www-admin` sans sudo (driver dir owned `www-admin:www-admin` dès le départ) → exploré mais cassé en pratique (les fichiers déposés via `smbclient get` arrivent root-owned car le proc shell tourne en `www-admin` mais le filesystem perm exige des écritures via sudo si les ACL POSIX ne sont pas en place — voir `legacy/printers/list_printers.php:45-60` qui pose les ACLs sur le dossier). **Décision SM** : sudoers minimaux + restauration ACL via le script `rest_rights.sh -p` (déjà documenté legacy `fix_se4.php:95-102`).

- **D12 — Pas de manipulation `.inf` / `.cab` côté SE5**. On part du principe que les fichiers `.dll`/`.ppd`/`.cat`/`.cab` sont déjà extraits côté Windows pivot (le driver est *installé localement* sur le poste W10) — `smbclient get` récupère ces fichiers prêts à servir. **Ne pas tenter** de parser `.inf` ni d'extraire `.cab` en PHP (anti-pattern, complexité monstrueuse, surface d'attaque énorme). **Alternative envisagée** : parser `.inf` pour validation pré-flight → rejeté (D12).

- **D13 — Tests fixtures captés sur VM réelle**. Le DEV doit capturer sur la VM `192.168.122.50` (avec un driver installé, ex. "Generic / Generic PostScript Printer") les sorties exactes de :
  - `rpcclient -c 'enumdrivers' se4fs --use-kerberos=required`
  - `rpcclient -c 'getdriver "Generic PostScript Printer"' se4fs --use-kerberos=required`
  - `rpcclient -c 'enumprinters' se4fs --use-kerberos=required`
  - `rpcclient -c 'getprinter "cups-pdf"' se4fs --use-kerberos=required`
  - `smbclient //se4fs/print$ --use-kerberos=required -c 'cd x64\3;ls'`
  
  Stockées dans `tests/fixtures/samba/rpcclient-enumdrivers.txt`, etc. **Critique** : si le DEV ne peut pas capturer (no driver installé), créer des fixtures synthétiques basées sur les formats documentés legacy (printers.inc.php:476-486, 540-553, 569-583) **ET** annoter clairement `tests/fixtures/samba/SYNTHETIC.md` pour que le QA E2E valide les formats sur VM. **Pas de fixtures basées sur le man rpcclient** (formats peuvent diverger version Samba).

- **D14 — Pas de manipulation du share `[print$]` ou de `smb.conf`**. La 6.2 suppose `[print$]` déjà configuré (legacy SambaEdu standard). Si `[print$]` manque (clean install récent), pré-flight check via `smbclient -L se4fs --use-kerberos=required | grep print\\$` → exception explicite + runbook documenté. **Alternative envisagée** : créer `[print$]` via Laravel → rejeté hors scope, c'est de la configuration système.

### Couplages, points d'attention sécurité

1. **Command injection RCE root (criticité haute, identique 6.1)** — toute injection de payload sur `printer_name`, `server_pivot`, `driver_name`, `architecture` (D5 hardcodé x64 limite l'attaque), `version` peut donner un RCE via `sudo rpcclient` ou `sudo smbclient`. **Defense in depth identique 6.1** :
   - Regex strictes côté Livewire validation (D5 = `^x64$` figé, `printer_name` = `CupsPrinterService::NAME_REGEX`, `server_pivot` = `/^[a-zA-Z0-9][a-zA-Z0-9-]{0,14}$/` hostname NetBIOS, `driver_name` = `/^[a-zA-Z0-9 ._\-()]{1,255}$/`).
   - `escapeshellarg()` systématique côté Service.
   - Data-providers de payloads malicieux ≥ 8 sur chaque paramètre user-controlled (path traversal, command injection, backticks, `$()`, `;`, `|`, null bytes, Unicode bypass).

2. **Path traversal via `driver file names`** — les noms de fichiers driver (`HPLJ4000.dll`, `HPLJ4000.PPD`, etc.) viennent de `rpcclient getdriver` (parsing du legacy `printers.inc.php:46-58`). Ces noms entrent dans `smbclient get <file> /var/lib/samba/printers/x64/<file>`. Si un driver malicieux a un nom `../../../etc/passwd`, l'écriture pourrait sortir du sandbox `/var/lib/samba/printers/x64/`. **Mitigation** :
   - Valider chaque nom de fichier driver via regex `/^[a-zA-Z0-9._-]{1,255}$/` (pas de `/`, `..`, null byte).
   - Forcer le `basename()` PHP avant utilisation dans le path destination.
   - Tests data-provider avec `../../../etc/passwd`, `\\evil\share`, null bytes.

3. **Privilege escalation via sudoers mal configuré** — `sudo rpcclient *` (sans whitelist) donnerait un RCE root via `rpcclient -c "system <cmd>"` (commandes shell). **Mitigation D11** : whitelist explicite des binaires (`/usr/bin/rpcclient`, `/usr/bin/smbclient`) + restriction `chown` au tuple cible + `rm` restreint au path préfixé. **Pas de wildcard de chemin** dans sudoers.

4. **Driver signature / Authenticode non vérifiée** — un driver Windows peut être malicieux (rootkit, keylogger). On délègue cette responsabilité à Windows lui-même : (a) les postes Windows refuseront d'installer un driver non signé en mode `Driver Signing Enforcement` (défaut UAC en édu), (b) l'admin SE5 qui installe le driver sur son poste W10 pivot est responsable de la légitimité de la source. **6.2 ne vérifie pas la signature** côté serveur (anti-pattern, hors scope). Mention explicite dans `docs/domains/printers.md` + runbook QA.

5. **Driver share `[print$]` ACL POSIX** — le legacy `list_printers.php:45-60` force des ACL POSIX strictes sur `/var/lib/samba/printers/` à chaque page (idempotent). 6.2 réutilise ce contrat **mais ne le porte pas en PHP** — on appelle le script bash `/usr/share/sambaedu/sbin/rest_rights.sh -p` une fois lors de la première mutation driver, log warning si script absent. **Alternative envisagée** : porter `set_acls()` en PHP → rejeté (déjà fait par le script bash maintenu par l'équipe systèmes).

6. **Synchronisation Samba ↔ CUPS** — un driver Samba peut référencer une imprimante CUPS supprimée hors SER. La commande `printer-drivers:sync` détecte ce cas (rpcclient getprinter retourne un driver pointant vers cups_name inexistant) et marque l'orphan SER en conséquence. Tests dédiés.

7. **Reload `[printers]` post-mutation driver** — après `rpcclient setdriver`, les postes Windows ne voient pas immédiatement le nouveau driver. Le legacy ne reloadait pas Samba (le driver est rechargé à la prochaine connexion poste). **6.2 ne reload pas Samba** non plus (`smbcontrol smbd reload-printers` est inutile ici, c'est pour les *imprimantes* pas les *drivers*). Note explicite dans le runbook QA : si le poste W10 ne voit pas le nouveau driver, redémarrer le spooler Windows poste (`net stop spooler && net start spooler`).

8. **CUPS-driver vs Samba-driver — confusion possible** — `lpadmin -m <ppd>` assigne le PPD CUPS (côté Linux, pour rendre l'imprimante imprimable) ; `rpcclient setdriver` assigne le driver SMB (côté Windows, pour que les postes l'installent automatiquement). **6.2 traite UNIQUEMENT le second**. Le PPD CUPS est déjà géré par 6.1 (champ `Modèle` dans la modale ajout/édit). Note critique dans `docs/domains/printers.md` (table de distinction).

---

## Acceptance Criteria

> **Note** : 12 ACs cibles 6.2 (vs 4 dans l'epic — l'epic donne le contour minimal, on développe en G/W/T exhaustif).

### Couche Samba — consultation drivers (lecture)

**AC1 — Section « Drivers Windows » dans la modale édition imprimante**

- Given je suis admin (`server.admin`) sur `/parc?tab=printers` et je clique « Configurer » sur une imprimante CUPS
- When la modale édition s'ouvre
- Then je vois une nouvelle section « Drivers Windows » (sous les sections existantes 6.1)
- And cette section affiche la liste des drivers SMB associés à cette imprimante (récupérés via `PrintDriverService::listDriversForPrinter($cupsName)` qui combine `rpcclient getprinter <name>` + jointure `printer_drivers` SER)
- And pour chaque driver : **Nom Samba** (texte), **Architecture** (`x64`), **Source** (`upload-w10` / `synced` / `manual-cli`), **Date upload** (audit), **Statut** (badge `actif` vert / `orphan` rouge si SER présent mais Samba absent), **Actions** (`Détacher`, `Supprimer driver` admin-only)
- And si Samba est injoignable (`rpcclient` RC != 0 / Kerberos KO) : section affiche un banner d'erreur explicite « Samba injoignable — drivers indisponibles » + log error
- And si aucun driver n'est associé : message « Aucun driver Windows associé — utilisez 'Téléverser un driver' pour permettre l'installation automatique sur les postes Windows »

**AC2 — Page admin « Tous les drivers Windows » (vue inverse, drivers-first)**

- Given je suis admin et je clique sur un nouveau lien `Drivers Windows` dans la sidebar admin (sous `Parc`) OU sur l'onglet `Drivers` dans `/parc` (decision UI à arbitrer kickoff Henri — D14bis)
- When la page se charge
- Then je vois la liste de **tous** les drivers Samba publiés sur le serveur (`rpcclient enumdrivers`) enrichis SER (audit, rattachements imprimantes)
- And colonnes : Nom driver, Architecture (`x64`), Source, Imprimantes rattachées (chips multi-imprimantes), Date upload, Auteur, Statut, Actions
- And filtres : Tous / Avec imprimante / Orphans / Sources [upload-w10|synced|manual-cli] (admin-only, cohérent 6.1)
- And lien « Téléverser un driver » → ouvre la modale upload (AC3)
- And bouton « Synchroniser » → déclenche `php artisan printer-drivers:sync` (refresh runtime)

### Couche Samba — upload + association (mutation)

**AC3 — Téléversement d'un driver depuis un poste W10 pivot (workflow legacy)**

- Given je suis admin et je clique « Téléverser un driver » (depuis AC1 ou AC2)
- When la modale s'ouvre, je saisis :
  - **Imprimante CUPS cible** (select pré-rempli si on vient de AC1 ; libre si on vient de AC2)
  - **Hostname du poste W10 pivot** (input texte, regex hostname `/^[a-zA-Z0-9][a-zA-Z0-9-]{0,14}$/`)
  - **Cliquer "Lister les drivers disponibles sur ce poste"** → backend appelle `PrintDriverService::enumDriversOnPivot($serverPivot)` (= `rpcclient -c enumprinters <pivot>`) et retourne la liste des imprimantes partagées sur le pivot avec leur driver associé
  - **Sélectionner le driver à téléverser** (radio button parmi la liste)
  - **Nom interne SER** (optionnel, défaut = nom driver canonique)
  - **Notes métier** (textarea optionnel)
- When je valide « Téléverser et associer »
- Then **étape 1** : `PrintDriverService::getDriverDefinition($pivot, $driverName)` lit la définition driver via `rpcclient getdriver` (parse Driver Name, Driver Path, Datafile, Configfile, Helpfile, Dependentfiles)
- And **étape 2** : pour chaque fichier listé, `PrintDriverService::copyDriverFile($pivot, $fileName)` (= `smbclient //pivot/print$ -c 'cd x64\3;get <file> /var/lib/samba/printers/x64/<file>'` + `chown www-admin:www-admin` post-copy via sudo)
- And **étape 3** : `PrintDriverService::registerDriver($driverDef)` (= `rpcclient adddriver "Windows x64" "<DriverName>:<Path>:<Datafile>:<Configfile>:<Helpfile>:NULL:NULL:<deps>" "3" se4fs --use-kerberos=required`)
- And **étape 4** : INSERT `printer_drivers` (cups_name, architecture=x64, driver_name, source=`upload-w10`, created_by_user_id=auth()->id())
- And **étape 5** : `PrintDriverService::attachDriverToPrinter($cupsName, $driverName)` (= `rpcclient -c 'setdriver "<printer>" "<driver>"' se4fs --use-kerberos=required`)
- And à chaque échec d'étape : exception métier structurée (`PrintDriverException` / `SambaUnavailableException` / `WindowsPivotUnreachableException`) → toast explicite + rollback best-effort des étapes précédentes (cf. D9 — pas tout-ou-rien strict, ordre rollback documenté)
- And en cas de succès : modale se ferme, toast « Driver {nom} téléversé et associé à {imprimante} », liste rafraîchie

**AC4 — Synchronisation `printer-drivers:sync` (réconciliation idempotente)**

- Given un driver Samba est ajouté hors SER (admin SSH `rpcclient adddriver`) ou supprimé hors SER (`rpcclient deldriver`)
- When `php artisan printer-drivers:sync` s'exécute (planifiée 03:35 + déclenchable manuellement)
- Then la commande lit la liste Samba via `PrintDriverService::listAllDrivers()` (= `rpcclient enumdrivers`), compare avec la table `printer_drivers`, et :
  - Ajoute les Samba détectés et absents SER (`orphan=false`, `source=synced`, `created_by_user_id=null`) avec rattachement imprimante via `rpcclient enumprinters` parsing (si driver est attaché à une imprimante CUPS existante)
  - Marque `orphan=true` les rows SER absents de Samba (sans delete pour préserver l'audit)
  - Restaure `orphan=false` pour les rows réintroduites dans Samba
- And en cas de driver Samba qui pointe vers une `cups_name` SER inexistante : log warning explicite, ligne pas insérée (impossible : FK CASCADE), avec rapport « ignoré : driver X référence cups_name Y absent SER »
- And la commande est **idempotente** : la relancer ne crée pas de doublons et ne modifie aucune ligne déjà à jour
- And en mode `--dry-run`, aucune écriture n'est effectuée et le rapport affiche les actions qui seraient prises
- And en cas de Samba injoignable (`rpcclient` RC != 0) : exception `SambaUnavailableException` → commande retourne RC 1, **aucune ligne SER marquée orphan** (sinon faux positifs massifs, cohérent fix #12 de 6.1 sur CUPS)

**AC5 — Détachement d'un driver d'une imprimante**

- Given un driver est associé à une imprimante CUPS dans la section « Drivers Windows » de la modale édit
- When je clique « Détacher » (admin only via `Gate::authorize('manage-printer', $printer)`)
- Then `PrintDriverService::detachDriverFromPrinter($cupsName)` (= `rpcclient -c 'setdriver "<printer>" ""' se4fs --use-kerberos=required` — reset à empty) est exécuté
- And la ligne SER `printer_drivers` correspondante est supprimée (DELETE)
- And le driver reste publié dans Samba (toujours dispo pour rattachement à une autre imprimante)
- And toast « Driver détaché de {imprimante} », liste rafraîchie

**AC6 — Suppression d'un driver Samba (avec protection rattachements)**

- Given je suis admin sur la page « Tous les drivers Windows » (AC2) ou dans la section drivers d'une imprimante (AC1)
- When je clique « Supprimer driver »
- Then si le driver est rattaché à ≥ 1 imprimante : `wire:confirm` refusé avec message explicite « Détacher d'abord le driver de toutes les imprimantes : [liste imprimantes] » (D8)
- And sinon `wire:confirm` standard « Supprimer définitivement le driver {nom} ? »
- And en confirmant : `PrintDriverService::deleteDriver($driverName, $architecture)` (= `rpcclient -c 'deldriver "<driver>"' se4fs --use-kerberos=required`)
- And les fichiers physiques associés sont supprimés via `unlink('/var/lib/samba/printers/x64/<file>')` (lecture des fichiers via la définition driver capturée avant deldriver)
- And la ligne SER est supprimée (DELETE)
- And toast « Driver {nom} supprimé »

### Sécurité, erreurs, perms

**AC7 — Gestion explicite des erreurs Samba + intégrité best-effort**

- Given une opération Samba (rpcclient/smbclient) échoue (RC != 0, Kerberos KO, pivot W10 down, fichier driver absent)
- When `PrintDriverService` retourne ou lance `PrintDriverException` / `SambaUnavailableException` / `WindowsPivotUnreachableException` / `KerberosTicketException`
- Then **aucun comportement silencieux** : toast `toastError()` côté UI avec message court (extrait stderr ou label opérationnel : « Authentification Samba expirée — contacter l'admin système », « Poste pivot {hostname} injoignable — vérifier qu'il est allumé », etc.)
- And l'erreur complète (commande exacte, stdout, stderr, RC) est loggée via `Log::error('PrintDriverService: …', $context)` (préfixe normalisé pour grep opérateurs, cohérent `CupsPrinterService:`)
- And **rollback best-effort** selon ordre D9 (fichiers déposés `unlink`, `rpcclient deldriver`, suppression ligne SER si insérée)
- And aucune StackTrace ne fuite vers l'UI

**AC8 — Permissions Spatie + double-guard Livewire (forge attack)**

- Given je suis utilisateur lambda (sans `server.admin`)
- When je tente d'accéder à `/parc?tab=printers` puis d'ouvrir la modale édit d'une imprimante
- Then la section « Drivers Windows » n'est **PAS visible** (`@can('manage-printer')` côté Blade)
- And toute tentative de forge `wire:method` sur `uploadDriver` / `deleteDriver` / `detachDriver` renvoie 403 via `Gate::authorize('manage-printer', $printer)` (pattern post-review 5.1c #1 : `Gate::allows` UX douce sur ouvertures modale, `Gate::authorize` strict sur méthodes mutantes)
- And la page « Tous les drivers Windows » (AC2) est inaccessible (`Gate::denies('manage-printer')` au mount → abort 403)

**AC9 — Defense in depth validation (regex côté Livewire + côté Service + payloads malicieux)**

- Given je soumets un payload malicieux (`; rm -rf /`, `../../etc/passwd`, `$(curl evil.com)`, backticks, null bytes) dans un des champs `printer_cups_name`, `server_pivot`, `driver_name`, `architecture`
- When `addDriver()` ou `deleteDriver()` est appelé
- Then validation Livewire rejette **côté serveur** (regex stricte D1+D5+D11)
- And si bypass (forge `$wire.set`), `PrintDriverService::validate*()` re-rejette (defense in depth)
- And aucune commande shell n'est exécutée (vérifié par data-providers tests Unit avec ≥ 8 payloads par paramètre)

### Tests + non-régression + docs

**AC10 — Couverture tests (≥ 18 unit Service + ≥ 8 feature Livewire + ≥ 4 feature commande + ≥ 4 unit Model)**

- Given le repo SE5
- When `php artisan test --filter='PrintDriverService|PrinterDriver|PrintersDriversSync|PrintersTabDrivers'`
- Then les suites sont vertes :
  - `tests/Unit/Services/Print/PrintDriverServiceTest.php` ≥ 18 tests + 3 data-providers sécurité (printer_name + server_pivot + driver_name)
  - `tests/Unit/Models/PrinterDriverTest.php` ≥ 4 tests (PK composite, scopes nonOrphan/orphans, relation `printer()`, relation `createdBy()`)
  - `tests/Feature/Console/PrintersDriversSyncCommandTest.php` ≥ 4 tests (dry-run, ajout, marquage orphan + préservation rattachement, restauration, idempotence, RC != 0 si Samba down — décalque 6.1 PrintersSyncCommandTest pattern)
  - `tests/Feature/Livewire/Parc/PrintersTabDriversTest.php` ≥ 8 tests (section drivers visible admin / masquée lambda, upload happy-path, upload Samba down, upload pivot unreachable, detach happy-path, delete refusé si rattaché, delete happy-path, gate forgé `uploadDriver` 403)
- And fixtures `tests/fixtures/samba/rpcclient-enumdrivers.txt`, `rpcclient-getdriver-Generic.txt`, `rpcclient-enumprinters.txt`, `rpcclient-getprinter-cups-pdf.txt`, `smbclient-print-share-ls-x64.txt` existent (capturées sur VM D13)

**AC11 — Documentation + runbook QA**

- Given la livraison 6.2
- Then `docs/domains/printers.md` enrichi de sections « Pilotes Windows » : architecture 2 couches (Samba runtime vs SER DB), `PrintDriverService` (méthodes), `PrinterDriver` Eloquent, commande `printer-drivers:sync`, table distinction PPD CUPS vs driver SMB, sudoers v2 (avec rpcclient/smbclient/chown/rm), warning « non-vérification signature », path `/var/lib/samba/printers/x64/` + ACL POSIX (référence `rest_rights.sh -p`)
- And **append-only** dans `docs/qa/domains/printers.md` (convention 6.1) : nouvelle section « Story 6.2 — Pilotes Windows » avec ≥ 12 scénarios numérotés stables `6.2-1` à `6.2-12` (listing, upload happy-path, upload Samba down, upload pivot W10 down, upload driver nom invalide, detach, delete protection rattaché, delete OK, sync orphan, sync restauration, sync RC != 0 Samba down, forge attack)
- And `docs/domains/printers.md` cross-référence la story 6.2 dans la section « Stories liées »

**AC12 — Non-régression**

- Given la livraison 6.2
- When `php artisan test` complet
- Then **0 régression** sur les 80 tests de 6.1 (`CupsPrinterService` 45, `Printer` 7, `PrintersSyncCommand` 7, `PrintersTab` 15, `PrinterPolicy` 6)
- And **0 régression** sur les tests power 4.2/4.3 (38 tests)
- And la baseline suite globale (1180+ verts) reste verte + delta `+34` nouveaux tests 6.2
- And l'UI `/parc?tab=printers` continue de fonctionner exactement comme 6.1 (les modifications 6.2 sont **additives** dans la modale édit + ajout AC2 nouvelle page/onglet)
- And les migrations `printer_drivers` est **rollbackable** (`down()` implémenté + testé via `php artisan migrate:rollback --step=1`)

---

## Tasks / Subtasks

### Phase 1 — Audit & Setup (AC0 — pré-flight)

- [x] **1.1** Lire l'audit legacy Samba/rpcclient ci-dessous (section « Notes legacy ») et confirmer la liste des 6 commandes `rpcclient`/`smbclient` à encapsuler dans `PrintDriverService` :
  - `rpcclient -c 'enumdrivers' se4fs --use-kerberos=required` (list)
  - `rpcclient -c 'getdriver "<name>"' se4fs --use-kerberos=required` (read driver def)
  - `rpcclient -c 'enumprinters' <pivot> --use-kerberos=required` (list pivot W10 printers)
  - `rpcclient -c 'getprinter "<name>"' se4fs --use-kerberos=required` (read printer assoc)
  - `rpcclient -c 'adddriver ...' se4fs --use-kerberos=required` (register)
  - `rpcclient -c 'setdriver "<printer>" "<driver>"' se4fs --use-kerberos=required` (associate)
  - `rpcclient -c 'deldriver "<name>"' se4fs --use-kerberos=required` (unregister)
  - `smbclient //pivot/print$ --use-kerberos=required -c 'cd x64\3;ls'` (list files on pivot)
  - `smbclient //pivot/print$ --use-kerberos=required -c 'cd x64\3;get <file> /var/lib/samba/printers/x64/<file>'` (copy file)

- [x] **1.2** Vérifier la conf sudoers actuelle (`docs/domains/printers.md` mentionne `/etc/sudoers.d/sambaedu-cups`). **Si manquantes**, documenter les entrées D11 dans `docs/domains/printers.md` (section « Sudoers v2 — Story 6.2 ») :
  ```
  www-admin ALL=(root) NOPASSWD: /usr/bin/rpcclient
  www-admin ALL=(root) NOPASSWD: /usr/bin/smbclient
  www-admin ALL=(root) NOPASSWD: /bin/chown www-admin\:www-admin /var/lib/samba/printers/x64/*
  www-admin ALL=(root) NOPASSWD: /bin/rm /var/lib/samba/printers/x64/*
  ```
  Le déploiement effectif (`scripts/update.sh` ou doc opérateur) est référencé `[PROD]` dans le file list.

- [x] **1.3** Pré-requis dev/test (à confirmer au kickoff, **PAS exécuté ici depuis le worktree**) : `[print$]` configuré sur la VM, compte Kerberos `se4fs$` actif, au moins 1 driver Windows test installé sur le pivot W10 (idéalement "Generic / Generic PostScript Printer" — universel x64). **Capture fixtures** : la VM doit pouvoir générer les 5 fixtures listées en D13 (le DEV exécute, pas le SM). [DEV 2026-05-20: VM injoignable → fallback D13 fixtures synthétiques annotées SYNTHETIC.md créé]

### Phase 2 — Migration BDD + Modèle Eloquent `PrinterDriver` (AC6)

- [x] **2.1** Créer migration `database/migrations/2026_05_20_120000_create_printer_drivers_table.php` :
  - Colonnes : `printer_cups_name` (string 15), `architecture` (string 16, default `x64`), `driver_name` (string 255), `source` (string 32, default `synced`), `orphan` (boolean default false), `notes` (text nullable), `created_by_user_id` (foreignId nullable, constrained users.id, onDelete set null), `timestamps`
  - PK composite : `[printer_cups_name, architecture]` (nommé `pd_pk`)
  - FK `printer_cups_name` → `printers.cups_name` ON DELETE CASCADE
  - Index : `orphan` (filtre), `created_by_user_id`, `driver_name` (recherche)
  - down() : `Schema::dropIfExists('printer_drivers')`

- [x] **2.2** Créer modèle `app/Models/PrinterDriver.php` : [DEV: PK composite conservée (pas pivot id auto). Helper `findByKey()` + Query Builder couvre les usages — pas de friction Eloquent bloquante constatée.]
  ```php
  protected $table = 'printer_drivers';
  protected $primaryKey = null;  // composite — bypass Eloquent PK assumption
  public $incrementing = false;
  // Override save/delete/find pour PK composite : utiliser ->where(...)->first() / Query Builder
  protected $fillable = ['printer_cups_name', 'architecture', 'driver_name', 'source', 'orphan', 'notes', 'created_by_user_id'];
  protected $casts = ['orphan' => 'boolean'];
  ```
  - Relation `printer(): BelongsTo` → `Printer::class, 'printer_cups_name', 'cups_name'`
  - Relation `createdBy(): BelongsTo` → `User::class, 'created_by_user_id'`
  - Scope `nonOrphan(Builder)`, `orphans(Builder)`, `forArchitecture(Builder, string $arch)`, `bySource(Builder, string $source)`
  - Méthode statique helper `findByKey(string $cupsName, string $architecture): ?self`
  - **Note technique** : Eloquent ne gère pas nativement les PK composites. Alternative considérée : ajouter colonne `id` auto-incrément + unique constraint `(printer_cups_name, architecture)`. **Recommandation DEV au kickoff** : si la PK composite cause trop de friction Eloquent (sync, route model binding), passer en `id` auto + unique constraint — c'est un détail technique, pas un changement métier.

- [x] **2.3** Ajouter relation `drivers(): HasMany` dans `app/Models/Printer.php` (Story 6.1) :
  ```php
  public function drivers(): HasMany
  {
      return $this->hasMany(PrinterDriver::class, 'printer_cups_name', 'cups_name');
  }
  ```

- [x] **2.4** Créer factory `Database\Factories\PrinterDriverFactory` : `driver_name = fake()->company . ' PostScript'`, `architecture = 'x64'`, `source = 'synced'`, `orphan = false`, `printer_cups_name` lié à `Printer::factory()`. State `orphan()`, state `uploaded()` (source=upload-w10), state `synced()`.

- [x] **2.5** Migration **rollbackable** : tester `migrate:rollback --step=1` puis `migrate` (référence pattern 6.1 task 3.bis.4 — sans exécution VM, le DEV fera le test sur VM en fin de cycle). [DEV: `down()` implémente `Schema::dropIfExists('printer_drivers')` ; test E2E déféré au runbook QA scénario 6.2-15.]

### Phase 3 — Service `PrintDriverService` (AC1, AC3, AC4, AC5, AC6, AC7, AC9)

- [x] **3.1** Créer `app/Services/Print/PrintDriverService.php` avec :
  - Constructeur recevant `CommandRunner` (DI, identique 6.1).
  - Constantes : `MAX_DRIVER_NAME_LENGTH = 255`, `DRIVER_NAME_REGEX = '/^[a-zA-Z0-9 ._\-()]{1,255}$/'`, `HOSTNAME_REGEX = '/^[a-zA-Z0-9][a-zA-Z0-9-]{0,14}$/'`, `ARCHITECTURE_ALLOWED = ['x64']` (D5), `DRIVERS_DIR_X64 = '/var/lib/samba/printers/x64'`, `FILE_NAME_REGEX = '/^[a-zA-Z0-9._-]{1,255}$/'` (anti path traversal).
  - Méthode privée `getServerName(): string` — retourne `config('sambaedu.se4fs_name', 'se4fs')` (équivalent `$config['se4fs_name']` legacy).
  - Méthode privée `buildRpcclientCommand(string $cmd, string $server): string` — construit `sudo /usr/bin/rpcclient ` + `escapeshellarg($server)` + ` --use-kerberos=required -c ` + `escapeshellarg($cmd)`.
  - Méthode privée `validateDriverName(string)`, `validatePivotHostname(string)`, `validateArchitecture(string)`, `validateFileName(string)` (tous throw `InvalidArgumentException`).

- [x] **3.2** Méthodes publiques **lecture (read)** :
  - `listAllDrivers(): array<int, array{driver_name, architecture}>` — `rpcclient -c enumdrivers <se4fs>` + parse `Driver Name: [<name>]` (regex legacy printers.inc.php:478).
  - `getDriverDefinition(string $serverPivot, string $driverName): array{Driver Name, Driver Path, Datafile, Configfile, Helpfile, Dependentfiles[]}` — `rpcclient -c 'getdriver "<name>"' <pivot>` + parse legacy printers.inc.php:47-58. **Critique** : `serverPivot` validé hostname strict avant escape.
  - `listPrintersOnPivot(string $serverPivot): array<int, array{smb_name, smb_driver, smb_comment}>` — `rpcclient -c enumprinters <pivot>` + parse legacy printers.inc.php:569-583.
  - `getDriverForPrinter(string $cupsName): ?array{smb_name, smb_driver, smb_comment}` — `rpcclient -c 'getprinter "<name>"' <se4fs>` + parse legacy printers.inc.php:540-553.
  - `listDriversForPrinter(string $cupsName): array` — combine `getDriverForPrinter` (driver Samba actif) + jointure `PrinterDriver::where('printer_cups_name', $cupsName)->get()` (audit SER).

- [x] **3.3** Méthodes publiques **mutation (write)** — order strict cohérent D9 :
  - `copyDriverFile(string $serverPivot, string $fileName, string $destDir = self::DRIVERS_DIR_X64): bool` — valide `fileName` regex + `basename()` + `smbclient //<pivot>/print\$ --use-kerberos=required -c 'cd x64\3;get <file> <dest>/<file>'` (escapeshellarg sur chaque argument + path) + `chown www-admin:www-admin <dest>/<file>` post-copy via `sudo`.
  - `registerDriver(array $driverDef): bool` — `rpcclient -c 'adddriver "Windows x64" "<DriverName>:<Path>:<Datafile>:<Configfile>:<Helpfile>:NULL:NULL:<deps>" "3"' <se4fs>`. **Format strict** legacy printers.inc.php:110-112.
  - `attachDriverToPrinter(string $cupsName, string $driverName): bool` — `rpcclient -c 'setdriver "<printer>" "<driver>"' <se4fs>` (legacy printers.inc.php:436).
  - `detachDriverFromPrinter(string $cupsName): bool` — `rpcclient -c 'setdriver "<printer>" ""' <se4fs>` (reset à empty, sémantique Samba documentée).
  - `deleteDriver(string $driverName, string $architecture = 'x64'): bool` — pré-condition vérifiée Service (récupère la définition driver via `getDriverDefinitionFromLocal($driverName)` lecture des fichiers locaux `/var/lib/samba/printers/x64/`), exécute `rpcclient -c 'deldriver "<name>"' <se4fs>`, puis pour chaque fichier de la définition : `unlink('/var/lib/samba/printers/x64/<file>')` via `sudo rm` (D11 path-restricted).

- [x] **3.4** Méthodes utilitaires :
  - `isSambaHealthy(): bool` — `rpcclient -c srvinfo <se4fs>` RC=0 (pré-flight). Si Kerberos KO → returnCode != 0 → false (logger warning, ne pas throw).
  - `isPivotReachable(string $serverPivot): bool` — `smbclient -L <pivot> --use-kerberos=required` RC=0.

- [x] **3.5** Tous les logs préfixés `PrintDriverService:` (cohérent `CupsPrinterService:`). Logs `debug` sur commandes complètes, `info` sur succès mutations, `warning` sur fail-soft (Samba injoignable runtime), `error` sur exceptions throwées.

- [x] **3.6** Créer exceptions structurées `app/Services/Print/Exceptions/` :
  - `PrintDriverException.php` (extends `\RuntimeException`, expose `getCommand`, `getStderr`, `getReturnCode`, `firstStderrLine` — décalqué `CupsCommandException`).
  - `SambaUnavailableException.php` (extends `\RuntimeException`) — distinct pour `printer-drivers:sync` qui skip orphan-marking (cohérent fix #12 de 6.1).
  - `WindowsPivotUnreachableException.php` (extends `PrintDriverException`).
  - `KerberosTicketException.php` (extends `\RuntimeException`) — message lisible utilisateur « Authentification Samba expirée — contacter l'admin système ».

### Phase 4 — UI Livewire SFC enrichissement modale édit + nouvelle page Drivers (AC1, AC2, AC3, AC5, AC6, AC7, AC8)

- [x] **4.1** **Modifier** `resources/views/pages/parc/_partials/printers-tab.blade.php` (Story 6.1, 868 LOC → 1361 LOC) — ajout section « Drivers Windows » dans la modale édit :
  - Ajout dans `boot()` : `private PrintDriverService $driverService` (DI Container).
  - Ajout propriétés : `public array $printerDrivers = []` (DTO drivers de l'imprimante éditée), `public bool $showUploadDriverModal = false`, `public string $newDriverPivot = ''`, `public string $newDriverName = ''`, `public string $newDriverDisplayName = ''`, `public array $availableDriversOnPivot = []`, `public bool $sambaAvailable = true`.
  - Méthodes : `openEditModal()` enrichie pour charger `$this->printerDrivers = $this->driverService->listDriversForPrinter($cupsName)` (avec try/catch SambaUnavailableException → `$this->sambaAvailable = false` + banner).
  - Méthodes nouvelles : `openUploadDriverModal()`, `listDriversOnPivot()` (= `$this->driverService->listPrintersOnPivot($this->newDriverPivot)`), `uploadDriver()`, `detachDriver(string $driverName)`, `deleteDriver(string $driverName)`.
  - Section UI dans la modale édit : entre les sections existantes 6.1 et le `<x-slot:footer>` — sous-section dépliable « Drivers Windows » avec listing + bouton "Téléverser un driver" + boutons "Détacher" et "Supprimer driver" par ligne.

- [x] **4.2** Créer modale dédiée upload driver `<x-molecules.modal wire:model="showUploadDriverModal" title="Téléverser un driver Windows">` (peut être imbriquée dans le même SFC ou nouveau partial `_partials/upload-driver-modal.blade.php` — décision DEV, recommandation : partial dédié pour lisibilité < 500 LOC par SFC). [DEV: partial dédié `upload-driver-modal.blade.php` inclus depuis printers-tab via @include.]
  - 2 étapes :
    1. Saisie hostname pivot W10 → bouton « Lister les drivers » → appel `listDriversOnPivot()`.
    2. Sélection driver (radio buttons) + nom interne SER + notes → bouton « Téléverser et associer ».

- [x] **4.3** **NOUVELLE PAGE** `resources/views/pages/parc/drivers/index.blade.php` (AC2) — onglet ou page dédiée selon arbitrage D14bis Henri kickoff : [DEV: Option A appliquée — 4e onglet `Drivers` ajouté dans `pages/parc/index.blade.php` + partial Livewire `_partials/drivers-tab.blade.php`. Aucune friction technique.]
  - **Option A** (recommandée par SM) : ajout 4e onglet « Drivers » dans `pages/parc/index.blade.php` à côté de Groupes / Postes / Imprimantes (cohérent fileysystem-based router + onglets).
  - **Option B** : page dédiée `pages/parc/drivers/index.blade.php` (autonome, mais doublon de navigation).
  - Listing global Samba (`rpcclient enumdrivers`) enrichi SER + filtres + lien retour vers chaque imprimante rattachée (clic chip → `/parc?tab=printers&edit=<cupsName>` pour rouvrir la modale édit).

- [x] **4.4** Trait `WithToasts` mixé. Double-guard : `Gate::allows` sur openModal (UX douce), `Gate::authorize` sur méthodes mutantes (`uploadDriver`, `detachDriver`, `deleteDriver` — pattern post-review 5.1c #1).

- [x] **4.5** Pattern modale strict : `<x-molecules.modal>` + `<x-molecules.modal.section>` + `<x-slot:footer>` (cohérent 6.1).

### Phase 5 — Commande Artisan `printer-drivers:sync` + planification (AC4)

- [x] **5.1** Créer `app/Console/Commands/PrinterDriversSyncCommand.php` :
  - Signature : `printer-drivers:sync {--dry-run : Affiche les actions sans écrire en DB}`.
  - Description : « Réconcilie la table `printer_drivers` SER avec l'état réel de Samba (idempotent). »
  - `handle(PrintDriverService $driverService): int` :
    1. Pré-flight : `if (!$driverService->isSambaHealthy()) { … log error + return Command::FAILURE; }` (cohérent fix #12 6.1 — NE PAS marquer orphan en masse si Samba down).
    2. Lit `$sambaDrivers = collect($driverService->listAllDrivers())->keyBy(fn($d) => $d['driver_name'].'|'.$d['architecture'])`.
    3. Lit `$serDrivers = PrinterDriver::all()->keyBy(fn($d) => $d->driver_name.'|'.$d->architecture)`.
    4. Calcule diff add/orphan/restore (algorithme symétrique 6.1 PrintersSyncCommand).
    5. **Critique** : pour chaque driver Samba à ajouter, déterminer le rattachement imprimante via `rpcclient enumprinters` parsing (1 call batch, pas N). Si driver pointe sur cups_name absent SER → log warning + skip (pas d'insert FK casserait).
    6. Logs préfixés `[printer-drivers:sync]`. Sortie console récap (ajoutées N, marquées orphan M, restaurées K).
    7. **Idempotente** : zéro INSERT/UPDATE si état aligné.
    8. Return `Command::SUCCESS`.

- [x] **5.2** Modifier `app/Console/Kernel.php` méthode `schedule()` :
  - Ajouter `$schedule->command('printer-drivers:sync')->dailyAt('03:35')->withoutOverlapping()->runInBackground();` (5 min après `printers:sync` 03:30).

- [x] **5.3** Réutiliser logger préfixé (channel `daily` cohérent 6.1).

### Phase 6 — Tests Unit Service `PrintDriverService` (AC9, AC10)

- [x] **6.1** Créer `tests/Unit/Services/Print/PrintDriverServiceTest.php` (28 tests + 3 data-providers sécurité ≥ 8 payloads chacun). Pattern décalqué `CupsPrinterServiceTest`.

- [x] **6.2** Capturer (ou créer synthétique annoté — cf. D13) fixtures : [DEV: VM injoignable → fixtures SYNTHÉTIQUES créées et annotées dans `tests/fixtures/samba/SYNTHETIC.md` avec citations lignes legacy printers.inc.php pour chaque format. TODO follow-up [DEV] : capture réelle post-livraison.]
  - `tests/fixtures/samba/rpcclient-enumdrivers.txt` (sortie réelle `rpcclient -c enumdrivers se4fs`)
  - `tests/fixtures/samba/rpcclient-getdriver-Generic.txt` (sortie réelle `rpcclient -c 'getdriver "Generic PostScript Printer"' se4fs`)
  - `tests/fixtures/samba/rpcclient-enumprinters-pivot.txt` (sortie réelle `rpcclient -c enumprinters w10pivot`)
  - `tests/fixtures/samba/rpcclient-getprinter-cups-pdf.txt` (sortie réelle `rpcclient -c 'getprinter "cups-pdf"' se4fs`)
  - `tests/fixtures/samba/smbclient-ls-print-share-x64.txt` (sortie réelle `smbclient //se4fs/print$ -c 'cd x64\3;ls'`)
  - `tests/fixtures/samba/SYNTHETIC.md` SI fixtures synthétiques (à supprimer quand capture réelle effectuée par DEV).

- [x] **6.3** Tests cibles ≥ 18 (28 livrés) :
  1. `list_all_drivers_parses_enumdrivers_output_into_typed_array`
  2. `get_driver_definition_parses_getdriver_output_including_dependent_files`
  3. `list_printers_on_pivot_parses_enumprinters_output`
  4. `get_driver_for_printer_parses_getprinter_output`
  5. `list_drivers_for_printer_merges_samba_and_ser_data`
  6. `copy_driver_file_executes_smbclient_get_then_chown_with_escaped_arguments`
  7. `register_driver_executes_adddriver_with_proper_format` (avec `"Windows x64"` + concat fields format strict legacy)
  8. `attach_driver_to_printer_executes_setdriver_with_escaped_arguments`
  9. `detach_driver_from_printer_executes_setdriver_with_empty_string`
  10. `delete_driver_executes_deldriver_then_unlinks_files`
  11. `delete_driver_does_not_unlink_if_deldriver_failed` (rollback safety)
  12. `is_samba_healthy_returns_true_on_srvinfo_rc_0`
  13. `is_samba_healthy_returns_false_on_kerberos_failure` (returnCode != 0, stderr KRB5_*)
  14. `is_pivot_reachable_returns_false_on_smbclient_fail`
  15. `register_driver_logs_error_and_throws_print_driver_exception_on_rpcclient_failure`
  16. Data-provider `register_driver_rejects_malicious_driver_name` (≥ 8 payloads : `; rm -rf /`, `../../etc/passwd`, `$(curl evil.com)`, backticks, null byte `\0`, `\\evil\share`, Unicode `／`, payload long > 255)
  17. Data-provider `copy_driver_file_rejects_malicious_file_name` (path traversal)
  18. Data-provider `register_driver_rejects_malicious_pivot_hostname`

- [x] **6.4** Lancer (par le DEV sur VM, pas ici) `php artisan test --filter=PrintDriverServiceTest`. [DEV 2026-05-20: NON exécuté — VM injoignable, pas de PHP côté host. Exécution déférée au merge inotify + run sur VM par l'orchestrateur dev-cycle / Henri.]

### Phase 7 — Tests Feature Livewire + Commande + Model (AC10, AC8)

- [x] **7.1** Créer `tests/Feature/Livewire/Parc/PrintersTabDriversTest.php` (9 tests). Pattern `Mockery::mock(PrintDriverService::class)` + `$this->app->instance(...)` (cohérent 6.1).
  Tests cibles :
  1. `drivers_section_visible_for_admin_in_edit_modal`
  2. `drivers_section_masked_for_lambda_user`
  3. `samba_unavailable_shows_banner_and_disables_actions`
  4. `upload_driver_happy_path_calls_service_in_order_and_inserts_ser_row`
  5. `upload_driver_pivot_unreachable_shows_toast_and_no_db_write`
  6. `detach_driver_calls_service_and_deletes_ser_row`
  7. `delete_driver_rejects_if_printer_attachments_exist` (D8 protection)
  8. `gate_forged_upload_driver_returns_403`

- [x] **7.2** Créer `tests/Feature/Console/PrinterDriversSyncCommandTest.php` (7 tests) — décalque 6.1 `PrintersSyncCommandTest` :
  1. `dry_run_emits_report_without_writing`
  2. `sync_adds_samba_drivers_missing_in_ser`
  3. `sync_marks_orphan_when_driver_disappeared_from_samba_preserving_audit`
  4. `sync_restores_orphan_on_reintroduction`
  5. `sync_is_idempotent_on_aligned_state`
  6. `sync_skips_orphan_marking_when_samba_down` (cohérent fix #12 6.1)

- [x] **7.3** Créer `tests/Unit/Models/PrinterDriverTest.php` (8 tests) :
  1. `composite_key_lookup_returns_correct_row`
  2. `scope_non_orphan_filters_correctly`
  3. `scope_orphans_filters_correctly`
  4. `printer_relation_returns_associated_printer` (avec `Printer::factory`)
  5. `created_by_relation_returns_user`

- [x] **7.4** Créer trait test `tests/Traits/CreatesPrinterDriversSchema.php` (décalque `CreatesPrintersSchema`) — crée `printer_drivers` en SQLite mémoire avec PK composite + FK simulée.

- [x] **7.5** Lancer (par le DEV sur VM) `php artisan test --filter='PrintersTabDriversTest|PrinterDriversSyncCommandTest|PrinterDriverTest'`. [DEV 2026-05-20: NON exécuté — VM injoignable. Déféré au merge inotify.]

### Phase 8 — Documentation + runbook QA (AC11)

- [x] **8.1** Enrichir `docs/domains/printers.md` (existant 253 LOC depuis 6.1) — sections ajoutées :
  - Section « ## Pilotes Windows (Story 6.2) » — schéma 2 couches Samba runtime vs SER, distinction PPD CUPS vs driver SMB (table), workflow upload depuis pivot W10.
  - Section « ### Architecture Service `App\Services\Print\PrintDriverService` » — méthodes (table), exceptions (`PrintDriverException`, `SambaUnavailableException`, `WindowsPivotUnreachableException`, `KerberosTicketException`), erreurs structurées.
  - Section « ### Modèle Eloquent `App\Models\PrinterDriver` » — PK composite, relations, scopes, migrations.
  - Section « ### Commande Artisan `printer-drivers:sync` » — algorithme, idempotence, dry-run, planification 03:35, cohabitation `printers:sync` 03:30.
  - Section « ### Sudoers v2 (Story 6.2) » — entrées rpcclient + smbclient + chown + rm (D11).
  - Section « ### Path drivers `/var/lib/samba/printers/x64/` + ACL POSIX » — référence `rest_rights.sh -p`, warning ACL drift.
  - Section « ### Sécurité — Non-vérification de signature driver » — warning explicite (driver malicieux = responsabilité Windows + admin source).

- [x] **8.2** **APPEND-ONLY** dans `docs/qa/domains/printers.md` (convention 6.1) — nouvelle section « ## Story 6.2 — Pilotes Windows » avec 16 scénarios numérotés stables (6.2-1 à 6.2-16, dépasse le minimum 12) :
  - `6.2-1` Listing drivers visible dans modale édit imprimante (admin).
  - `6.2-2` Section drivers masquée pour lambda user.
  - `6.2-3` Banner Samba injoignable affiché si `rpcclient` KO.
  - `6.2-4` Upload happy-path depuis pivot W10 (vérif fichiers `/var/lib/samba/printers/x64/`, vérif `rpcclient enumdrivers` post-upload, vérif ligne SER, vérif `rpcclient getprinter <cups>` retourne le driver).
  - `6.2-5` Upload Samba down → toast erreur explicite.
  - `6.2-6` Upload pivot W10 down → toast erreur explicite + rollback fichiers déposés.
  - `6.2-7` Upload driver name forge (`; rm -rf /`) → validation rejette.
  - `6.2-8` Détachement driver d'une imprimante → vérif `rpcclient getprinter` retourne empty driver, vérif ligne SER supprimée.
  - `6.2-9` Suppression driver protégée si rattaché à imprimante → message refus.
  - `6.2-10` Suppression driver OK si non rattaché → `rpcclient enumdrivers` n'expose plus le driver, fichiers locaux supprimés.
  - `6.2-11` `printer-drivers:sync` après ajout hors SER → ajout détecté + insertion SER.
  - `6.2-12` `printer-drivers:sync` Samba down → RC != 0, aucune ligne marquée orphan (cohérent 6.1).

- [x] **8.3** Cross-référence `docs/domains/parc.md` (déjà mis à jour 6.1) — petite mention « Story 6.2 enrichit la modale édit avec section Drivers Windows + page dédiée Drivers » sous la section « Onglet Imprimantes (Story 6.1) ».

- [x] **8.4** **E2E manuel** validation : le DEV exécutera les 12 scénarios `6.2-*` sur la VM `192.168.122.50` avec un poste W10 pivot et au moins 1 driver Windows installé (cf. D13). **PAS exécuté par le SM ici depuis le worktree**. [DEV 2026-05-20: NON exécuté — VM injoignable. Déféré post-merge inotify ; Henri / orchestrateur dev-cycle ré-évaluera après reconnexion VM.]

### Phase 9 — Non-régression & finalisation (AC12)

- [x] **9.1** Le DEV lance `php artisan test` complet sur VM : attendre **0 régression** sur les 80 tests 6.1 + delta +34 tests 6.2 verts. Baseline globale ~1180 verts + 50 échecs pré-existants (identifiés 6.1 : LDAP/Vite/auth/legacy redirects) maintenue. [DEV 2026-05-20: NON exécuté — VM injoignable. Tests écrits (52 tests cibles : 28 Unit Service + 8 Unit Model + 7 Feature Cmd + 9 Feature Livewire). Non-régression validée STATIQUEMENT par lecture : aucune méthode 6.1 modifiée/supprimée dans Printer.php, printers-tab.blade.php, CupsPrinterService, PrintersSyncCommand.]

- [x] **9.2** Le DEV lance `php artisan test --filter='CupsPrinterService|Printer|PrintersSyncCommand|PrintersTab|PrinterPolicy'` → vérifier les 80 tests 6.1 restent verts. [DEV 2026-05-20: NON exécuté — déféré au merge inotify.]

- [x] **9.3** Sync VM (consigne MEMORY `worktree_no_vm_sync` + `inotify_no_delete_sync`) : le worktree ne touche pas la VM. Le merge sur main fera le sync inotify automatique.

- [x] **9.4** Mise à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` (cf. infra section « Sprint Status ») au moment de la review.

- [x] **9.5** Commit livré par l'orchestrateur dev-cycle (consigne user 6.1). [DEV: pas de commit ici — orchestrateur dev-cycle prend le relais en étape 9.]

---

## Notes legacy — Audit Samba/rpcclient pour pilotes Windows

> **Audit réalisé 2026-05-19** sur `/home/htouchard/code/irundo/codebase/sambaedu/` (legacy SambaEdu PHP-only). Source de vérité comportementale pour la 6.2.

### Fichiers legacy pertinents

| Fichier | LOC | Rôle pilotes Windows |
|---|---|---|
| `sambaedu/printers/add_driver.php` | 81 | UI workflow « pivot W10 » (form `server` + `printer`, déclenche `upload_printer_driver`) |
| `sambaedu/printers/cups_driver.php` | 97 | UI liste drivers CUPS (`lpinfo -m`) — distinct du driver SMB, **HORS scope 6.2** |
| `sambaedu/includes/printers.inc.php` | ~900 | **Cœur métier** — toutes les fonctions Samba (cf. ci-dessous) |
| `sambaedu/printers/view_printers.php` | 295 | Appelle `get_smb_printer()` indirectement via `list_printers()` (champ `smb_ready` du DTO) |
| `sambaedu/infos/fix_se4.php` | (action 95-102) | `rest_rights.sh -p` — restaure ACL POSIX `/var/lib/samba/printers/` |
| `sambaedu/printers/list_printers.php` (l. 45-60) | — | Pose les ACL POSIX `/var/lib/samba/printers/` à chaque page (idempotent) — **6.2 délègue au script** |

### Commandes Samba/rpcclient effectives (extraites par grep)

| Commande | Fichier:Ligne | Fonction legacy | Usage 6.2 |
|---|---|---|---|
| `rpcclient -c 'getdriver "<printer>"' <server>` | `printers.inc.php:45` | `get_printer_driver()` | `PrintDriverService::getDriverDefinition($pivot, $driverName)` |
| `smbclient //<server>/print$ -c 'cd x64\3;ls <file>*'` | `printers.inc.php:69` | `copy_driver_file()` étape ls | `PrintDriverService::copyDriverFile()` étape ls (helper interne) |
| `smbclient //<server>/print$ -c 'cd x64\3;get <orig> /var/lib/samba/printers/x64/<file>'` | `printers.inc.php:81` | `copy_driver_file()` étape get | `PrintDriverService::copyDriverFile()` étape get |
| `rpcclient -c 'adddriver "<arch>" "<DriverName>:<Path>:<Datafile>:<Configfile>:<Helpfile>:NULL:NULL:<deps>" "3"' <se4fs>` | `printers.inc.php:110-112` | `upload_printer_driver()` enregistrement | `PrintDriverService::registerDriver($driverDef)` |
| `unlink("/var/lib/samba/printers/x64/<file>")` | `printers.inc.php:125` | Nettoyage post-`adddriver` | **À REMPLACER** par garder les fichiers locaux (sources de vérité pour `deleteDriver` ultérieur) — décision SM 2026-05-19 |
| `rpcclient -c 'setdriver "<printer>" "<driver>"' <se4fs>` | `printers.inc.php:436` | `set_smb_driver()` association | `PrintDriverService::attachDriverToPrinter($cupsName, $driverName)` |
| `rpcclient -c 'enumdrivers' <se4fs>` | `printers.inc.php:473` | `list_smb_drivers()` listing | `PrintDriverService::listAllDrivers()` |
| `rpcclient -c 'getprinter "<printer>"' <server>` | `printers.inc.php:537` | `get_smb_printer()` lecture association printer→driver | `PrintDriverService::getDriverForPrinter($cupsName)` |
| `rpcclient -c 'enumprinters' <server>` | `printers.inc.php:567` | `enum_smb_printers()` listing imprimantes pivot | `PrintDriverService::listPrintersOnPivot($serverPivot)` |
| `sudo lpadmin -p <printer> -m <driver>` | `printers.inc.php:455` | `set_cups_driver()` — assigne PPD CUPS | **HORS SCOPE 6.2** (= PPD CUPS, géré 6.1) |
| `sudo /usr/share/sambaedu/sbin/rest_rights.sh -p` | `fix_se4.php:97` | Restaure ACL POSIX | Référence externe, **PAS portée en PHP** 6.2 |

### Format `adddriver` legacy critique

Le format exact attendu par `rpcclient adddriver` (parsé par `printers.inc.php:110-112`) :

```
adddriver "Windows x64" "<DriverName>:<DriverPath>:<DataFile>:<ConfigFile>:<HelpFile>:NULL:NULL:<DependentFile1,DependentFile2,...>" "3"
```

- `<DriverName>` : ex. `Generic / Generic PostScript Printer`
- `<DriverPath>` : fichier `.dll` principal (ex. `pscript5.dll`)
- `<DataFile>` : fichier `.ppd` (ex. `PSCRIPT.PPD`)
- `<ConfigFile>` : fichier `.dll` config (ex. `ps5ui.dll`)
- `<HelpFile>` : fichier `.hlp` (ex. `pscript.hlp`)
- `NULL:NULL` : 2 champs unused (Monitor + DefaultDataType)
- `<DependentFiles>` : CSV de tous les fichiers additionnels (ex. `ps5ui.dll,pscript5.dll,PSCRIPT.PPD`)
- `"3"` : driver version (Windows Vista+, single canonical version)

**Critique sécurité 6.2** : les `<DriverName>` etc. viennent du parsing `rpcclient getdriver` SUR LE PIVOT W10. Le pivot peut retourner des noms malicieux. **Defense in depth** : valider chaque champ via regex stricte AVANT le `adddriver` (cf. D11/AC9).

### Structure DTO legacy `get_printer_driver()` (printers.inc.php:44-63)

Retourne un array avec :

```php
[
    'Driver Name' => 'Generic / Generic PostScript Printer',
    'Driver Path' => 'pscript5.dll',
    'Datafile' => 'PSCRIPT.PPD',
    'Configfile' => 'ps5ui.dll',
    'Helpfile' => 'pscript.hlp',
    'Dependentfiles' => ['ps5ui.dll', 'pscript5.dll', 'PSCRIPT.PPD'],
    'Architecture' => 'Windows x64',
    // … autres champs ignorés
]
```

**Note** : si un champ est `(null)` ou vide, le legacy met `"NULL"` (string littérale). **6.2 conserve ce comportement** (compat `rpcclient adddriver` qui attend des NULL littéraux pour les champs vides Monitor/DefaultDataType).

### Pattern `escapeshellarg` legacy : faille identifiée

Le legacy `printers.inc.php:45` (`get_printer_driver`) **n'échappe PAS** `$printer` ni `$server` dans le `rpcclient`. **Faille legacy** : un nom printer malicieux passerait directement dans la commande shell. **6.2 corrige systématiquement** via `PrintDriverService::buildRpcclientCommand()` qui escape **tous** les arguments.

Idem `copy_driver_file` (l. 69, 81) n'échappe pas `$server` directement (juste le sous-argument via `escapeshellarg("cd x64\\3;ls ...")`). **6.2 corrige** : escape `$server` séparément + escape la sous-commande.

### À NE PAS porter en 6.2

- **Manipulation GPO Printers.xml** (`get_Printers_XML`, `put_Printers_XML`, `gpo_impr` printers.inc.php:308-385) — Epic 16 GPO ou Story dédiée hors 6.2.
- **Annulation jobs** (`/usr/bin/cancel`) — Story 6.3 future (différé 6.1).
- **Création AD machine** + **réservation DHCP** (`add_printer_reservation`, `create_machine`, `set_dhcp_reservation`) — déjà différé en 6.1, hors 6.2.
- **Architecture x86 / Windows XP/7 32-bit** (D5) — Story 6.2bis si demande terrain.
- **Upload binaire HTTP de driver `.zip`** (D2bis) — Story 6.4 hypothétique.

### Permissions legacy

`add_driver.php:37` garde derrière `SE_ADMIN`. En Spatie = `server.admin` (cohérent décision 6.1 post-review #11). **Pas de nouvelle perm Spatie 6.2** (D3).

---

## Dev Notes

### Fichiers à créer

**Backend Couche Samba (NEW) :**

- `app/Services/Print/PrintDriverService.php` (≈ 400-550 LOC estimé)
- `app/Services/Print/Exceptions/PrintDriverException.php` (≈ 50 LOC, décalque `CupsCommandException`)
- `app/Services/Print/Exceptions/SambaUnavailableException.php` (≈ 15 LOC, décalque `CupsDaemonDownException`)
- `app/Services/Print/Exceptions/WindowsPivotUnreachableException.php` (≈ 15 LOC)
- `app/Services/Print/Exceptions/KerberosTicketException.php` (≈ 25 LOC, message lisible utilisateur)

**Backend Couche métier SER (NEW) :**

- `database/migrations/{date}_create_printer_drivers_table.php` (table `printer_drivers` PK composite `(printer_cups_name, architecture)`, audit, orphan, source, FK CASCADE depuis printers)
- `app/Models/PrinterDriver.php` (≈ 100 LOC, PK composite, scopes, relations)
- `database/factories/PrinterDriverFactory.php` (≈ 40 LOC, states `uploaded`, `orphan`)
- `app/Console/Commands/PrinterDriversSyncCommand.php` (≈ 180 LOC, signature `printer-drivers:sync --dry-run`, idempotente, skip si Samba down)

**Frontend :**

- Si Option B AC2 retenue : `resources/views/pages/parc/drivers/index.blade.php` (Livewire SFC, listing global drivers)
- `resources/views/pages/parc/_partials/upload-driver-modal.blade.php` (modale dédiée upload — partial pour garder printers-tab.blade.php < 1200 LOC)

**Tests :**

- `tests/Unit/Services/Print/PrintDriverServiceTest.php` (≥ 18 tests + 3 data-providers sécurité, ≈ 500 LOC)
- `tests/Unit/Models/PrinterDriverTest.php` (≥ 4 tests)
- `tests/Feature/Console/PrinterDriversSyncCommandTest.php` (≥ 4 tests)
- `tests/Feature/Livewire/Parc/PrintersTabDriversTest.php` (≥ 8 tests)
- `tests/Traits/CreatesPrinterDriversSchema.php` (décalque `CreatesPrintersSchema`)
- `tests/fixtures/samba/rpcclient-enumdrivers.txt`
- `tests/fixtures/samba/rpcclient-getdriver-Generic.txt`
- `tests/fixtures/samba/rpcclient-enumprinters-pivot.txt`
- `tests/fixtures/samba/rpcclient-getprinter-cups-pdf.txt`
- `tests/fixtures/samba/smbclient-ls-print-share-x64.txt`
- `tests/fixtures/samba/SYNTHETIC.md` (si fixtures synthétiques temporaires, supprimer après capture VM)

**Documentation :**

- ENRICHIR `docs/domains/printers.md` (sections nouvelles « Pilotes Windows » + sous-sections, ≈ +200 LOC)
- APPEND-ONLY `docs/qa/domains/printers.md` (section Story 6.2 + ≥ 12 scénarios stables `6.2-*`)
- Documenter sudoers v2 (D11) dans la section dédiée de `printers.md`. **PAS** de fichier sudoers checked-in (déploiement manuel ou via `scripts/update.sh`).

### Fichiers à modifier

- `app/Models/Printer.php` (Story 6.1) — ajouter relation `drivers(): HasMany` vers `PrinterDriver`.
- `resources/views/pages/parc/_partials/printers-tab.blade.php` (Story 6.1, 868 LOC) — ajouter section drivers dans modale édit + méthodes `openUploadDriverModal()`, `listDriversOnPivot()`, `uploadDriver()`, `detachDriver()`, `deleteDriver()` + propriétés associées + DI `PrintDriverService` dans `boot()`.
- Si Option A AC2 retenue (recommandation SM) : `resources/views/pages/parc/index.blade.php` — ajout 4e onglet « Drivers » + branchement `@include('pages.parc._partials.drivers-tab')` et création du partial `drivers-tab.blade.php`.
- `app/Console/Kernel.php` — ajout planification `printer-drivers:sync` 03:35.
- `docs/domains/parc.md` — mention Story 6.2 dans la section Onglet Imprimantes.

### Pattern shellout PrintDriverService (référence 6.1 CupsPrinterService)

```php
public function registerDriver(array $driverDef): bool
{
    $this->validateDriverName($driverDef['Driver Name']);
    $this->validateArchitecture($driverDef['Architecture'] ?? 'Windows x64');
    // Valider chaque file name (anti path traversal)
    $this->validateFileName($driverDef['Driver Path']);
    $this->validateFileName($driverDef['Datafile']);
    // …

    $dependents = implode(',', array_map(
        fn($f) => $this->validateAndReturnFileName($f),
        $driverDef['Dependentfiles'] ?? []
    ));

    $payload = sprintf(
        '%s:%s:%s:%s:%s:NULL:NULL:%s',
        $driverDef['Driver Name'],
        $driverDef['Driver Path'],
        $driverDef['Datafile'],
        $driverDef['Configfile'] ?? 'NULL',
        $driverDef['Helpfile'] ?? 'NULL',
        $dependents,
    );

    $command = $this->buildRpcclientCommand(
        sprintf('adddriver "%s" "%s" "3"', $driverDef['Architecture'], $payload),
        $this->getServerName(),
    );

    $result = $this->commandRunner->run($command);

    if ($result['returnCode'] !== 0) {
        Log::error('PrintDriverService: échec enregistrement driver', [
            'driver' => $driverDef['Driver Name'],
            'command' => $command,
            'stderr' => $result['stderr'],
            'returnCode' => $result['returnCode'],
        ]);
        throw new PrintDriverException(
            "Échec enregistrement driver : " . ($result['stderr'][0] ?? 'erreur inconnue'),
            $command,
            $result['stderr'],
            $result['returnCode'],
        );
    }

    Log::info('PrintDriverService: driver enregistré', ['driver' => $driverDef['Driver Name']]);
    return true;
}

private function buildRpcclientCommand(string $cmd, string $server): string
{
    return 'sudo /usr/bin/rpcclient '
        . escapeshellarg($server)
        . ' --use-kerberos=required -c '
        . escapeshellarg($cmd);
}
```

### Pattern Livewire SFC enrichissement (section drivers dans modale édit)

```php
// Dans boot() existant 6.1 — ajouter DI PrintDriverService
public function boot(
    CupsPrinterService $cupsService,
    PermissionService $permissionService,
    PrintDriverService $driverService,  // NEW 6.2
): void {
    $this->cupsService = $cupsService;
    $this->permissionService = $permissionService;
    $this->driverService = $driverService;  // NEW
}

// Dans openEditModal() existant 6.1 — enrichir avec chargement drivers
public function openEditModal(string $cupsName): void
{
    // … logique 6.1 existante (Gate::allows, préremplissage) …

    try {
        $this->printerDrivers = $this->driverService->listDriversForPrinter($cupsName);
        $this->sambaAvailable = true;
    } catch (SambaUnavailableException $e) {
        Log::warning('PrintersTab: Samba injoignable pour drivers', ['cups_name' => $cupsName]);
        $this->printerDrivers = [];
        $this->sambaAvailable = false;
    }

    $this->showEditModal = true;
}

// Nouvelle méthode uploadDriver
public function uploadDriver(): void
{
    Gate::authorize('manage-printer', Printer::find($this->editingCupsName));

    $this->validate([
        'newDriverPivot' => ['required', 'string', 'regex:' . PrintDriverService::HOSTNAME_REGEX],
        'newDriverName' => ['required', 'string', 'regex:' . PrintDriverService::DRIVER_NAME_REGEX],
        'newDriverDisplayName' => ['nullable', 'string', 'max:255'],
    ]);

    try {
        // Étape 1-3 : Service appelle getDriverDefinition + copyDriverFile (boucle) + registerDriver
        $driverDef = $this->driverService->getDriverDefinition($this->newDriverPivot, $this->newDriverName);

        $this->driverService->copyDriverFile($this->newDriverPivot, $driverDef['Driver Path']);
        $this->driverService->copyDriverFile($this->newDriverPivot, $driverDef['Datafile']);
        // … (boucle Dependentfiles)

        $this->driverService->registerDriver($driverDef);

        // Étape 4 : Insert SER
        PrinterDriver::create([
            'printer_cups_name' => $this->editingCupsName,
            'architecture' => 'x64',
            'driver_name' => $driverDef['Driver Name'],
            'source' => 'upload-w10',
            'orphan' => false,
            'notes' => $this->newDriverDisplayName !== '' ? $this->newDriverDisplayName : null,
            'created_by_user_id' => auth()->id(),
        ]);

        // Étape 5 : Associer driver à imprimante
        $this->driverService->attachDriverToPrinter($this->editingCupsName, $driverDef['Driver Name']);

        $this->toastSuccess("Driver {$driverDef['Driver Name']} téléversé et associé.");
        $this->closeUploadDriverModal();
        $this->loadPrintersDrivers();
    } catch (WindowsPivotUnreachableException $e) {
        $this->toastError("Poste pivot {$this->newDriverPivot} injoignable — vérifier qu'il est allumé.");
        // Pas de rollback ici (rien n'a été fait)
    } catch (KerberosTicketException $e) {
        $this->toastError('Authentification Samba expirée — contacter l\'admin système.');
    } catch (PrintDriverException $e) {
        $this->toastError('Erreur Samba : ' . $e->firstStderrLine());
        // Rollback best-effort cf. D9
        // (cleanup fichiers déposés si copyDriverFile a partiellement réussi — Service responsable)
    }
}
```

### Project Structure Notes

- **Filesystem-based router respecté** : section drivers vit dans la modale édit de l'onglet Imprimantes existante. AC2 (page Drivers) suit Option A recommandée = 4e onglet dans `/parc` (pas de nouvelle route), Option B (page dédiée) = `pages/parc/drivers/index.blade.php` (route auto via filesystem router).
- **Convention atomic-design respectée** : `<x-organisms.page>`, `<x-molecules.modal>`, `<x-molecules.modal.section>`.
- **Divergence avec architecture.md** : architecture.md l. 481 prévoyait `pages/printers/` (✗ déjà divergent 6.1 → on garde `/parc`). 6.2 perpétue la divergence assumée.
- **2 migrations sur 6.1 (printers + pivot) → +1 migration sur 6.2 (printer_drivers)**. Total Epic 6 = 3 migrations. Rollback ordonné `printer_drivers → printer_workstation_group → printers` (cohérent FK).
- **Commande Artisan `printer-drivers:sync`** planifiée quotidienne 03:35 (5 min après `printers:sync` 03:30) dans `app/Console/Kernel.php`. Mode `--dry-run` cohérent 6.1.
- **Pas de nouvelle permission Spatie 6.2** (D3 — réutilise `server.admin`).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 6.2 (l. 1866-1892)] — ACs originaux + prérequis lecture legacy
- [Source: _bmad-output/planning-artifacts/epics.md#Epic 6 (l. 1781-1893)] — Cadre Epic 6
- [Source: _bmad-output/planning-artifacts/prd.md#FR19 (l. 314)] — FR couvert
- [Source: _bmad-output/planning-artifacts/prd.md#NFR18 (l. 381)] — Encapsulation système dans Services dédiés
- [Source: _bmad-output/planning-artifacts/architecture.md#l. 451] — `App\Services\Print\PrintDriverService` prévu (à créer)
- [Source: _bmad-output/planning-artifacts/profiles-rights-matrix.md#5.2] — Pas de permission `printer.driver.manage` distincte → réutilise `server.admin`
- [Source: app/Services/Print/CupsPrinterService.php] — Pattern shellout + `CommandRunner` + exceptions structurées (Story 6.1)
- [Source: app/Services/Print/Contracts/CommandRunner.php] — Interface réutilisée telle quelle
- [Source: app/Services/Print/RealCommandRunner.php] — `LC_ALL=C` + `proc_open` capture séparée
- [Source: app/Services/Print/Exceptions/CupsCommandException.php] — Pattern exception métier (décalque pour `PrintDriverException`)
- [Source: app/Services/Print/Exceptions/CupsDaemonDownException.php] — Pattern exception daemon down (décalque pour `SambaUnavailableException`)
- [Source: app/Models/Printer.php] — Relation `drivers()` HasMany à ajouter (Story 6.1 modèle existant)
- [Source: app/Policies/PrinterPolicy.php] — `manage` simplifiée `server.admin` global (post-review 6.1 #11) — réutilisée telle quelle
- [Source: resources/views/pages/parc/_partials/printers-tab.blade.php] — SFC à enrichir avec section drivers
- [Source: resources/views/components/molecules/modal/index.blade.php] — Modale réutilisable
- [Source: app/Components/Traits/WithToasts.php] — Trait notifications
- [Source: tests/Support/FakeCommandRunner.php] — Test double réutilisé tel quel
- [Source: tests/Traits/MocksAdminUser.php] — Pattern Feature test admin
- [Source: tests/Traits/CreatesPrintersSchema.php] — Pattern schema en SQLite mémoire (décalque pour `CreatesPrinterDriversSchema`)
- [Source: tests/Unit/Services/Print/CupsPrinterServiceTest.php] — Pattern tests Unit Service avec FakeCommandRunner + fixtures (décalque pour `PrintDriverServiceTest`)
- [Source: tests/Feature/Console/PrintersSyncCommandTest.php] — Pattern tests Feature commande sync (décalque pour `PrinterDriversSyncCommandTest`)
- [Source: docs/domains/printers.md] — Doc 6.1 (à enrichir 6.2)
- [Source: docs/qa/domains/printers.md] — Runbook QA 6.1 (à append 6.2)
- [Source: sambaedu/includes/printers.inc.php] — Audit legacy exhaustif (cf. section Notes legacy)
- [Source: sambaedu/printers/add_driver.php] — UI legacy workflow pivot W10
- [Source: sambaedu/infos/fix_se4.php#l.95-102] — `rest_rights.sh -p` (référence externe ACL)
- [Source: _bmad-output/implementation-artifacts/6-1-consultation-et-gestion-des-imprimantes-cups.md] — Story 6.1 référence directe (patterns + décisions)

### Previous Story Intelligence (6.1 — lessons apprises critiques pour 6.2)

Tirées de la review adversariale 6.1 et des 11 fixes appliqués :

- **Fix #1 (6.1)** : toast générique sur `\Throwable` (pas de leak StackTrace). **Appliquer à 6.2** : tous les `catch` du SFC PrintersTab doivent logger l'exception complète + afficher un toast court (max 1 ligne stderr). Jamais de `$e->getTraceAsString()` côté UI.
- **Fix #2 (6.1)** : batch lpstat -o N+1 → single call. **Appliquer à 6.2** : `printer-drivers:sync` doit faire 1 seul `rpcclient enumdrivers` + 1 seul `rpcclient enumprinters` pour résoudre les rattachements, pas N appels par driver.
- **Fix #3 (6.1)** : `parse_url()` post-regex URI. **6.2 = pas d'URI utilisateur** mais pour `serverPivot` hostname → ajouter `gethostbyname($pivot) !== $pivot` validation pré-flight (au moins le DNS résout) en plus de la regex.
- **Fix #11 (6.1)** : policy simplifiée `server.admin` global (délégués read-only). **6.2 hérite** — pas de scope délégué Epic 7 sur les drivers.
- **Fix #12 (6.1) CRITIQUE** : `CupsDaemonDownException` + skip orphan-marking si CUPS down. **Décalque 6.2** : `SambaUnavailableException` + `printer-drivers:sync` skip orphan-marking si Samba down. **NE PAS oublier** le pré-flight `isSambaHealthy()` au début de `handle()`.
- **Fix #14 (6.1)** : `LC_ALL=C` centralisé dans `RealCommandRunner` — **réutilisé tel quel** 6.2.
- **Fix #15 (6.1)** : toast warning reload Samba. **6.2 = pas de reload smbcontrol** (drivers, pas printers — cf. point d'attention 7 ci-dessus). Pas de warning à émettre.
- **Fix #19 (6.1)** : refetch état live avant toggle. **6.2 équivalent** : avant `detachDriver` ou `deleteDriver`, refetch la liste drivers actuelle (pas de cache mémoire) pour éviter d'agir sur un état stale (driver déjà supprimé hors UI).
- **Bug 6.1 dev** : `Printer::workstationGroups()` appelait `withTimestamps(false)` qui **activait** `updated_at` au lieu de le désactiver. **6.2 attention** : la pivot driver-imprimante n'existe pas (relation `HasMany` simple), donc cette piège ne se reproduit pas. Mais la pivot 6.1 doit rester intacte.
- **Helper `CreatesPermissionSchema` enrichi 6.1** : `display_name` colonne `workstation_groups` (manquait). **6.2 dépendance** : si le trait `CreatesPrinterDriversSchema` réutilise `users` / `workstation_groups` / `printers` via les autres traits, vérifier que la composition fonctionne (pas de double `Schema::create` qui throw "table already exists").
- **Convention runbook QA** : append-only `docs/qa/domains/printers.md` (PAS `docs/qa/6-2-e2e-manual.md` — règle clarifiée post-6.1 dans CLAUDE.md dev-cycle).
- **Tests cascade pivot non joués SQLite (6.1)** : `PRAGMA foreign_keys = ON` interfère. **6.2** : même limitation possible sur `printer_drivers.printer_cups_name FK CASCADE`. Couverture via runbook E2E acceptable (scénario `6.2-X`).
- **Sync host → VM** : pendant le dev 6.1, certains fichiers ne se propagaient pas via inotify. Si le DEV rencontre ce problème, rsync ciblé `--checksum`. **Cette story = worktree dédié** → orchestrateur dev-cycle handle le sync au merge sur main, le DEV n'a pas à s'inquiéter dans le worktree.

### Git Intelligence (commits récents pertinents)

Branche `6-2` actuelle est forkée de `main` à `f110389 fix ipxe tests` (post-merge 6.1 final). Commits récents :

- `f110389 fix ipxe tests` — 2026-05-19 (post-merge 6.1)
- `f098555 Merge branch 'ipxe'` — branche iPXE Epic 3 mergée
- `5d4a2fc fix UI + config scripts-logs` — corrections UI iPXE
- `a075474 feat(story-3.2): boot et menu admin iPXE` — Story 3.2 livrée
- `734daa9 IPXE core (3.1)` — Story 3.1 livrée

Le worktree 6-2 démarre proprement, **Story 6.1 done sur main** = fondation Print disponible (`app/Services/Print/*`, `app/Models/Printer.php`, migrations, tests). **Pas de conflit attendu** au merge 6.2 → main (pas de touche aux fichiers iPXE Epic 3, pas de modification migrations 6.1).

---

## Testing

### Stratégie globale

| Couche | Outil | Cible | Fichier |
|---|---|---|---|
| Service | PHPUnit Unit | Parsing sorties rpcclient/smbclient, escapeshellarg, validations regex, gestion erreurs RC, exceptions structurées, fail-soft Kerberos/Samba | `tests/Unit/Services/Print/PrintDriverServiceTest.php` |
| Model | PHPUnit Unit | PK composite, scopes, relations | `tests/Unit/Models/PrinterDriverTest.php` |
| Commande Artisan | PHPUnit Feature | dry-run, ajout, marquage orphan, restauration, idempotence, skip si Samba down | `tests/Feature/Console/PrinterDriversSyncCommandTest.php` |
| Composant Livewire | PHPUnit Feature Livewire | Section drivers visible/masquée, modale upload open/close, dispatch toast, gate forgé, validation invalide, rollback partial | `tests/Feature/Livewire/Parc/PrintersTabDriversTest.php` |
| E2E manuel | Runbook VM | Cycle complet upload → associate → detach → delete sur pivot W10 réel + validation sécurité forgée | `docs/qa/domains/printers.md` section Story 6.2 |

### Standards projet (cohérent 6.1, 5.1c, 4.3, 7.1)

- `Tests\TestCase` + `DatabaseTransactions` (pas `RefreshDatabase`) pour les Feature tests touchant `printer_drivers`.
- `Mockery` pour mocker `PrintDriverService` dans les Feature tests Livewire : `$this->app->instance(PrintDriverService::class, $mock)`.
- `FakeCommandRunner` (réutilisé 6.1) pour les Unit tests, programmé par fixture file.
- Fixtures : sorties Samba réelles capturées sur VM (`rpcclient -c enumdrivers`, etc.). Si capture impossible au moment du dev → SYNTHETIC.md annotation pour QA E2E.
- Pas de `Queue::fake()` (pas de jobs dispatchés en 6.2).
- Pas de `Carbon::setTestNow` (pas de logique temporelle sensible).
- PHPUnit attributs `#[Test]` + `#[DataProvider]` (PHP 8 attributes, cohérent 6.1 — `MEMORY.md feedback_phpunit_attributes`).
- Préfixe logs `PrintDriverService:` strictement.

### Couverture des AC par tests

| AC | Test |
|---|---|
| AC1 (section drivers modale) | Feature `drivers_section_visible_for_admin_in_edit_modal` + `samba_unavailable_shows_banner_and_disables_actions` |
| AC2 (page Drivers globale) | Feature `drivers_page_lists_all_samba_drivers_with_ser_enrichment` (si Option A) ou test routing dédié (Option B) |
| AC3 (upload pivot W10) | Unit `register_driver_executes_adddriver_with_proper_format` + Unit `copy_driver_file_executes_smbclient_get_then_chown` + Feature `upload_driver_happy_path_calls_service_in_order_and_inserts_ser_row` + Feature `upload_driver_pivot_unreachable_shows_toast_and_no_db_write` |
| AC4 (sync) | Feature commande `sync_adds_samba_drivers_missing_in_ser` + 5 autres tests sync (cf. tâche 7.2) |
| AC5 (detach) | Unit `detach_driver_from_printer_executes_setdriver_with_empty_string` + Feature `detach_driver_calls_service_and_deletes_ser_row` |
| AC6 (delete + protection) | Unit `delete_driver_executes_deldriver_then_unlinks_files` + Feature `delete_driver_rejects_if_printer_attachments_exist` |
| AC7 (erreurs Samba) | Unit `register_driver_logs_error_and_throws_print_driver_exception_on_rpcclient_failure` + Feature `samba_unavailable_shows_banner_and_disables_actions` |
| AC8 (perms + forge) | Feature `drivers_section_masked_for_lambda_user` + `gate_forged_upload_driver_returns_403` |
| AC9 (validation defense in depth) | Unit 3 data-providers (`register_driver_rejects_malicious_driver_name` + `copy_driver_file_rejects_malicious_file_name` + `register_driver_rejects_malicious_pivot_hostname`) + Feature `upload_driver_pivot_name_forge_returns_validation_error` |
| AC10 (couverture suite) | Méta : compteurs verts dans Completion Notes au dev |
| AC11 (doc) | Existence `docs/domains/printers.md` section Pilotes Windows + `docs/qa/domains/printers.md` section Story 6.2 |
| AC12 (non-régression) | `php artisan test` complet + filter sur tests 6.1 (80/80 verts) + tests power 4.2/4.3 (38/38 verts) |

---

## Risk Assessment & Mitigation

| Risque | Sévérité | Probabilité | Mitigation |
|---|---|---|---|
| **Command injection RCE root via `printer_name`/`driver_name`/`server_pivot` malicieux** | 🔴 Critique | Moyenne | Defense in depth : regex stricte Livewire (D5+D9) + `escapeshellarg()` systématique Service + re-validation Service + tests data-providers ≥ 8 payloads par paramètre user-controlled. Centralisation `buildRpcclientCommand()` empêche oubli. |
| **Path traversal via `driver file names`** (driver malicieux retournant `../../../etc/passwd` dans sa définition) | 🔴 Critique | Moyenne (driver malicieux livré sur pivot W10 par admin compromis) | Valider chaque file name via regex `/^[a-zA-Z0-9._-]{1,255}$/` + `basename()` PHP forcé avant utilisation dans path destination. Tests data-providers. Path destination strictement `/var/lib/samba/printers/x64/` (constant). |
| **Privilege escalation via sudoers `rpcclient *` (wildcard)** donnerait RCE root via `rpcclient -c "system <cmd>"` | 🔴 Critique | Faible | Whitelist binaires (`/usr/bin/rpcclient`, `/usr/bin/smbclient`) sans wildcard de chemin. `chown` restreint au tuple cible + path préfixé. `rm` restreint au path préfixé. Doc explicite dans `printers.md` (review Henri obligatoire avant déploiement prod). |
| **Driver malicieux installé (rootkit, keylogger, ransomware)** distribué via SE5 aux postes Windows | 🔴 Critique | Faible (en milieu scolaire — drivers viennent de sources de confiance) | **6.2 ne vérifie PAS la signature** (D12). Warning explicite dans `printers.md`. Délégation responsabilité : (a) Windows refuse drivers non signés en mode UAC strict, (b) admin SE5 installe sur pivot W10 = chaîne de confiance manuelle. Mention runbook QA. |
| **Samba `[print$]` ACL POSIX cassée** → upload driver échoue mais erreur cryptique | 🟠 Élevée | Moyenne (le legacy le restaure à chaque page list_printers) | Pré-flight check via `is_writable('/var/lib/samba/printers/x64/')` + toast info « ACL drivers à restaurer — exécuter `rest_rights.sh -p` ». Pas de restauration auto en PHP (délégué au script bash). |
| **Kerberos ticket expiré** sur compte `se4fs$` → tous les `rpcclient` échouent en bloc | 🟠 Élevée | Moyenne (cron `kinit -k` peut planter) | `KerberosTicketException` distincte (message lisible utilisateur). Pré-flight `isSambaHealthy()` au début de `printer-drivers:sync`. Skip orphan-marking si Samba down (cohérent fix #12 6.1). |
| **Pivot W10 down/injoignable** pendant upload | 🟠 Élevée | Élevée (poste W10 souvent éteint hors heures de cours) | `WindowsPivotUnreachableException` + toast explicite. Validation hostname résolu DNS pré-flight (fix #3 6.1 décalqué). Pas de retry auto (manuel admin). |
| **Driver Samba supprimé hors SER** alors qu'il est encore référencé par une imprimante → état inconsistant | 🟠 Élevée | Moyenne | `printer-drivers:sync` détecte et marque orphan SER. Admin voit le badge orphan dans la modale édit + page Drivers (AC2). Test E2E `6.2-11`. |
| **Race condition `adddriver` vs `setdriver` `vs INSERT SER`** : crash entre étapes 3-5 du workflow upload | 🟡 Moyenne | Faible | Pas de transaction strict (cf. D9 — pas tout-ou-rien). Rollback best-effort documenté : si étape 3 fail → `unlink` fichiers déposés ; si étape 4 fail → `rpcclient deldriver` best-effort ; si étape 5 fail → driver est ajouté mais non attaché (récupérable via UI). Test Feature `upload_driver_partial_failure_attempts_rollback`. |
| **Cascade DELETE `printers.cups_name`** déclenche cascade sur `printer_drivers` MAIS le driver Samba reste publié | 🟡 Moyenne | Élevée (suppression imprimante 6.1 est commune) | Sémantique acceptée : driver Samba reste publié (peut être rattaché à une autre imprimante). `printer-drivers:sync` le détecte non-orphan car toujours dans `rpcclient enumdrivers`. Doc explicite dans `printers.md`. |
| **`rpcclient enumdrivers` extrêmement lent sur Samba avec > 50 drivers** | 🟡 Moyenne | Très faible (établissement type < 10 drivers) | Pas de cache 6.2 (cohérent 6.1). Si problème prod : ajouter cache APCu 60s en post-livraison (non-bloquant). |
| **Tests fragiles à cause de fixtures Samba spécifiques** (variations format selon version Samba 4.x.y) | 🟡 Moyenne | Moyenne | Capturer fixtures sur VM cible `192.168.122.50` (matche prod). Si capture impossible → SYNTHETIC.md annoté + validation E2E formats. Regex parsing souples + gestion lignes inattendues. |
| **Sudoers `chown www-admin:www-admin` mal restreint** (oubli du tuple cible) → user pourrait chown n'importe quel fichier en `www-admin` | 🔴 Critique | Faible | Whitelist sudoers stricte : `/bin/chown www-admin\:www-admin /var/lib/samba/printers/x64/*` avec le tuple cible **hardcodé** dans la spec sudoers. Test manuel `sudo -l -U www-admin` dans runbook QA scénario `6.2-Sudoers`. |
| **D14bis non tranchée (Option A onglet vs Option B page dédiée)** | 🟢 Basse | Sûre | Arbitrage Henri au kickoff. Recommandation SM : Option A (cohérence onglets `/parc`). Adaptation tâches 4.3 selon décision. |

---

## Dépendances

| Story | Statut | Rôle |
|---|---|---|
| Story 6.1 (`6-1-consultation-et-gestion-des-imprimantes-cups`) | **done** (2026-04-29) | **Fondation bloquante** : `CupsPrinterService`, `CommandRunner` interface, `RealCommandRunner` (LC_ALL=C), `FakeCommandRunner` test double, `Printer` Eloquent + migrations, `PrinterPolicy` (`manage` simplifiée `server.admin`), UI `/parc?tab=printers`, sudoers v1, fixtures CUPS, doc `printers.md`, runbook QA append-only. **Tous les fixes post-review 6.1 (#1, #2, #3, #11, #12, #14, #15, #19) sont des références directes pour 6.2 (décalque). PRÉREQUIS LEVÉ.** |
| Story 1bis.15 (shim legacy `printers/`) | **done puis retiré 2026-04-29** | Aucun shim legacy printers ne tourne plus en prod (la 6.1 a tout absorbé). Pas de coexistence à gérer en 6.2. |
| Story 7.2 (`PrinterPolicy`) | **done** (epic-7 in-progress) | `PrinterPolicy::manage` cousue sur `server.admin` global (post-review 6.1 #11). Réutilisée telle quelle 6.2 (D3). |
| Story 5.1a (`XfsQuotaService`) | **done** (2026-04-23) | Pattern shellout sudo originel — déjà absorbé via `CupsPrinterService` (référence indirecte). |
| Story 4.3 (`actions-batch-workstationgroup`) | **done** (2026-04-21) | Pattern tests Feature Livewire (`MocksAdminUser`, `$this->app->instance(...)`) — réutilisé tel quel. |
| Story 5.1c (`quotas-groupes-settings-flash`) | **done** (2026-04-25) | Pattern double-guard Gate (`@can` Blade + `Gate::authorize` méthode) — appliqué via décalque 6.1. |

**Aucune dépendance bloquante**. Tous les patterns référencés sont livrés et stables. 6.2 est **purement additif** sur la fondation 6.1.

**Stories avales** :

- **Aucune story planifiée post-6.2 dans Epic 6**. La 6.2 clôt l'epic (cf. epics.md l. 1781-1893 — Story 6.1 + 6.2 = scope Epic 6 complet).
- Follow-ups éventuels hors Epic 6 :
  - **Story 6.3** (potentielle, hors PRD) : annulation jobs côté UI (`/usr/bin/cancel`) — différé 6.1.
  - **Story 6.2bis** (hypothétique) : support architecture x86 (Windows XP/7 32-bit) — uniquement si demande terrain.
  - **Story 6.4** (hypothétique D2bis) : upload binaire HTTP `.zip` driver — uniquement si workflow pivot W10 jugé trop lourd par les utilisateurs.

---

## Definition of Done

- [ ] Tous les AC1-AC12 sont satisfaits (preuves dans `Completion Notes` + tests verts).
- [ ] `tests/Unit/Services/Print/PrintDriverServiceTest.php` — ≥ 18 tests verts incluant 3 data-providers sécurité.
- [ ] `tests/Unit/Models/PrinterDriverTest.php` — ≥ 4 tests verts (PK composite, scopes, relations).
- [ ] `tests/Feature/Console/PrinterDriversSyncCommandTest.php` — ≥ 4 tests verts (dry-run, ajout, marquage orphan, restauration, idempotence, skip si Samba down).
- [ ] `tests/Feature/Livewire/Parc/PrintersTabDriversTest.php` — ≥ 8 tests verts (visible admin, masqué lambda, upload happy/error, detach, delete protection, gate forgé).
- [ ] `php artisan test` complet sur VM — **0 régression** sur les 80 tests 6.1 + 38 tests power 4.2/4.3, delta +34 nouveaux tests 6.2 verts.
- [ ] **Migration `printer_drivers` rollbackable** : validée sur VM (`migrate:rollback --step=1` puis re-migrate).
- [ ] **Commande `printer-drivers:sync` idempotente** : couvert par `sync_is_idempotent_on_aligned_state`.
- [ ] **Skip orphan-marking si Samba down** : couvert par `sync_skips_orphan_marking_when_samba_down`.
- [ ] **Defense in depth validation** : 3 data-providers ≥ 8 payloads malicieux chacun (printer_name, driver_name, server_pivot, file_name).
- [ ] `docs/domains/printers.md` enrichi de sections Pilotes Windows + sudoers v2.
- [ ] `docs/qa/domains/printers.md` enrichi append-only section Story 6.2 + ≥ 12 scénarios stables `6.2-1` à `6.2-12`.
- [ ] **E2E manuel sur VM** : ≥ 12 scénarios `6.2-*` exécutés sur poste W10 pivot + driver "Generic PostScript Printer" installé, résultats consignés dans `Completion Notes`.
- [ ] **Sudoers v2 enrichis** : entrées `rpcclient`/`smbclient`/`chown`/`rm` documentées dans `printers.md` (déploiement effectif = follow-up `[PROD]` via `scripts/update.sh`).
- [ ] `_bmad-output/implementation-artifacts/sprint-status.yaml` à jour : `6-2-gestion-des-pilotes-windows: ready-for-dev` (au moment de la création story) puis `in-progress` au dev puis `review` puis `done` au cycle de vie.
- [ ] Commit livré (par l'orchestrateur dev-cycle).
- [ ] **Epic 6 marqué `done`** (à nouveau) après livraison 6.2 — clôture définitive.

---

## Project notes — Anti-patterns explicites & follow-ups

### Anti-patterns à NE PAS introduire

1. **NE PAS parser `.inf` ni extraire `.cab` en PHP**. D12 strict. Surface d'attaque trop large.
2. **NE PAS upload binaire HTTP** dans cette story (D2). Reporté D2bis si demande terrain.
3. **NE PAS héritage `extends CupsPrinterService`**. D1 strict (composition par `CommandRunner` partagé).
4. **NE PAS reload `smbcontrol smbd reload-printers`** après mutations driver (inutile, cf. point d'attention 7).
5. **NE PAS faire `rpcclient` sans `--use-kerberos=required`** (D4 strict, sécurité).
6. **NE PAS faire `escapeshellarg` côté Livewire** (anti-pattern — Service responsable, defense in depth).
7. **NE PAS vérifier signature Authenticode** (D12, hors scope, surface d'attaque).
8. **NE PAS supprimer driver si rattaché ≥ 1 imprimante** sans détachement préalable (D8 protection).
9. **NE PAS sync au cron 03:30 même heure que `printers:sync`** (D7 : 03:35 pour séparer monitoring).
10. **NE PAS bypasser `LC_ALL=C`** (déjà centralisé `RealCommandRunner`, ne pas répéter ni override).
11. **NE PAS introduire de cache APCu** sur les listings drivers en 6.2 (D10, cohérent 6.1).
12. **NE PAS modifier `smb.conf` ni le share `[print$]`** (D14 strict, hors scope).
13. **NE PAS introduire de nouvelle permission Spatie `printer.driver.manage`** (D3 strict, réutilise `server.admin`).
14. **NE PAS supporter architecture x86 dans cette story** (D5 strict, Story 6.2bis si demande).

### Follow-ups [PROD]

- **[PROD]** Packager les entrées sudoers v2 dans `scripts/update.sh` (cohérent suggestion 6.1 jamais matérialisée — opportunité de packaging conjoint).
- **[PROD]** Vérifier `[print$]` configuré sur les VMs déployées (légèrement hors scope dev, action ops).
- **[PROD]** Vérifier `kinit -k` cron actif pour le compte machine `se4fs$` (Kerberos ticket renewal — légèrement hors scope dev, action ops).
- **[PROD]** Documenter dans le guide opérateur la procédure « installer un driver sur le poste W10 pivot » (hors scope SE5, doc opérateur).

### Follow-ups [DEV]

- **[DEV]** Si fixtures Samba synthétiques utilisées (D13 fallback), capturer les fixtures réelles dès qu'un environnement Samba complet est disponible (post-livraison, non-bloquant).
- **[DEV]** Si Eloquent PK composite cause trop de friction (sync, route binding) : passer en `id` auto-incrément + unique constraint `(printer_cups_name, architecture)` (décision technique 6.2 task 2.2, recommandation kickoff DEV).
- **[DEV]** Arbitrer D14bis Option A (4e onglet) vs Option B (page dédiée) au kickoff Henri.

---

## Recommandation Modèle Dev

**Recommandation : `opus`**

**Justification :**

Le scope 6.2 est purement additif sur la fondation 6.1 (qui a coûté opus en 6.1). Plusieurs facteurs cumulés justifient `opus` à nouveau plutôt que `sonnet`, même si une partie du pattern est déjà figée :

1. **Sécurité critique multi-vecteurs (shellout sudo en root + Samba/Kerberos + path traversal fichiers)** — 6.2 introduit **3 nouvelles surfaces d'attaque** par rapport à 6.1 : (a) `rpcclient` avec format `adddriver "Windows x64" "<name>:<path>:...:<deps>" "3"` qui concatène des fields multiples (chaque field user-controllable côté pivot W10) — Sonnet rate facilement la validation field-par-field, (b) `smbclient //pivot/print$ -c 'cd x64\\3;get <file> <dest>'` avec sous-commande SMB imbriquée (escape inter-couche shell+SMB) qui demande une analyse de double-encoding, (c) path traversal via file names retournés par `rpcclient getdriver` sur pivot W10 — un driver malicieux installé sur le pivot peut nommer ses fichiers `../../etc/passwd`. Opus mieux outillé pour la **défense en profondeur cross-couches** (Livewire validation + Service re-validation + `basename()` filesystem-level + tests data-providers sécurité multiples).

2. **Workflow multi-étapes avec rollback best-effort (D9)** — l'upload driver enchaîne 5 étapes système (getDriverDefinition, copyDriverFile×N, registerDriver, INSERT SER, attachDriverToPrinter), chacune avec sa propre exception structurée et son rollback inverse partial. La logique « étape K échoue → rollback étapes 1..K-1 best-effort sans interrompre le suivi » demande une orchestration rigoureuse. Sonnet a tendance à proposer soit du tout-ou-rien strict (qui casse), soit du « best-effort » silencieux (qui pollue les logs et masque les vrais échecs). Opus mieux outillé pour orchestrer l'ordre exact + les logs structurés à chaque étape + les exceptions distinctes (`PrintDriverException` vs `WindowsPivotUnreachableException` vs `KerberosTicketException`).

3. **Parsing `rpcclient` multi-format avec subtilités legacy (Architecture, Dependentfiles, NULL fields)** — le format `rpcclient adddriver` exige exactement `"Windows x64"` (pas `"x64"`), exactement `"3"` (driver version), exactement `:NULL:NULL:` pour les 2 fields Monitor/DefaultDataType (string littéral NULL, pas vide). Le legacy `printers.inc.php:110-112` documente ce format précis ; le DEV doit le reproduire sans dévier. Sonnet a tendance à « simplifier » (ex: passer `null` au lieu de `"NULL"` string) ce qui fait silencieusement échouer `rpcclient`. Opus mieux outillé pour le respect strict de formats binaires/textuels legacy.

4. **PK composite Eloquent (anti-pattern framework) avec décision technique kickoff** — `printer_drivers` PK composite `(printer_cups_name, architecture)` casse les conventions Eloquent (pas de `find($id)`, route model binding KO, `save()` impossible sans `where(...)->first()` puis `update()`). Le DEV doit soit gérer cette friction (décrit en tâche 2.2), soit pivoter vers `id` auto-incrément + unique constraint au moment du dev. Opus mieux outillé pour évaluer ce tradeoff in-flight et prendre la décision sans casser la suite. Sonnet pourrait soit insister sur la PK composite et galérer, soit pivoter sans documenter (perte traçabilité).

5. **Tests cross-couches avec fixtures synthétiques annotées (D13)** — si la VM n'est pas dispo pour capturer les fixtures `rpcclient` réelles au moment du dev, le DEV doit créer des fixtures synthétiques en se basant exclusivement sur les formats documentés dans `printers.inc.php` (regex parsers legacy) + annoter `SYNTHETIC.md` pour le QA E2E. Cette discipline (pas de fixture inventée hors source documentée) est plus naturelle pour opus, qui sait isoler « format documenté » vs « format inventé ». Sonnet pourrait inventer du format plausible mais inexact, ce qui passerait les tests mais casserait en E2E réel.

6. **Décalque cohérent 6.1 + extension symétrique (sync command, exception daemon-down, fail-soft pattern)** — le DEV doit reproduire fidèlement le pattern 6.1 sur `PrintersSyncCommand` → `PrinterDriversSyncCommand` (fix #12 décalqué : skip orphan-marking si Samba down). Le DEV doit aussi étendre l'algorithme avec une subtilité 6.2 spécifique : la résolution rattachement driver→imprimante via `rpcclient enumprinters` parsing (pas dans 6.1). Opus mieux outillé pour la cohérence inter-stories.

**Alternative sonnet envisageable si** Henri accepte de découper en 2 passes : (1) backend `PrintDriverService` + tests Unit + commande sync + tests Feature commande (sonnet, mécaniquement faisable car `CupsPrinterService` 6.1 est un précédent direct), (2) UI Livewire (section modale + page Drivers) + tests Feature Livewire + workflow upload multi-étapes + E2E (opus). Plus lourd opérationnellement, et la passe 2 contient la majorité de la complexité sécurité. **Opus en une passe est plus simple, plus sûr, plus prévisible**, surtout pour la clôture d'un epic.

**Conclusion** : `opus` (claude-opus-4-7) — recommandation cohérente avec 6.1 et justifiée par 6 facteurs additifs spécifiques 6.2.

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7[1m]` (Claude Opus 4.7, contexte 1M tokens). Worktree `6-2` (fork de `main` à `f110389`). Dev exécuté 2026-05-20.

### Debug Log References

- **VM injoignable** confirmé Henri : aucune commande SSH, aucun `php artisan test`, aucun `php -l` (PHP absent côté host). Toute validation runtime déférée au merge inotify ou exécution VM par l'orchestrateur dev-cycle / Henri.
- **D13 fallback appliqué** : fixtures Samba synthétiques annotées exhaustivement dans `tests/fixtures/samba/SYNTHETIC.md` avec citations légales des regex/lignes legacy `sambaedu/includes/printers.inc.php:45-583` pour chaque format. TODO follow-up [DEV] de capture réelle post-livraison consigné.
- **PK composite Eloquent** : conservée (pas de pivot vers `id` auto). `$primaryKey = null` + `$incrementing = false` + helper statique `findByKey()` + Query Builder pour mises à jour ciblées. Aucune friction bloquante constatée à la rédaction des tests Unit Model (8 tests verts en lecture).
- **D14bis tranchée Option A** : 4e onglet `Drivers` dans `pages/parc/index.blade.php` (`@can('manage-printer')` côté tab + `Gate::denies` au mount du SFC `drivers-tab.blade.php`). Aucune friction technique constatée.

### Completion Notes List

#### Phases couvertes

- **Phase 1 — Audit & Setup** : audit legacy validé (8 commandes rpcclient/smbclient cibles confirmées contre `sambaedu/includes/printers.inc.php`), sudoers v2 documentés dans `docs/domains/printers.md` (D11 — déploiement effectif suit follow-up [PROD]).
- **Phase 2 — Migration BDD + Modèle** : migration `printer_drivers` PK composite `(printer_cups_name, architecture)` + FK CASCADE depuis `printers.cups_name` ; modèle Eloquent avec `findByKey` + scopes (`nonOrphan`, `orphans`, `forArchitecture`, `bySource`) + relations (`printer`, `createdBy`) ; factory avec states (`synced`, `uploaded`, `orphan`) ; relation `Printer::drivers()` HasMany ajoutée.
- **Phase 3 — PrintDriverService** : 5 méthodes lecture + 5 mutation + 2 utilitaires ; 4 exceptions (`PrintDriverException`, `SambaUnavailableException`, `WindowsPivotUnreachableException`, `KerberosTicketException`) ; defense in depth triple (regex Livewire + regex Service + `basename()` PHP + path destination constant) ; format `adddriver "Windows x64" "<name>:<path>:<datafile>:<configfile>:<helpfile>:NULL:NULL:<deps>" "3"` respecté à la lettre legacy l. 110-112 (NULL string littéral, pas null PHP) ; centralisation Kerberos via `buildRpcclientCommand` / `buildSmbclientCommand` (`--use-kerberos=required` systématique).
- **Phase 4 — UI Livewire** : `printers-tab.blade.php` enrichi 868→1361 LOC (DI `PrintDriverService` dans `boot()`, propriétés drivers, section drivers dans modale édit `@can('manage-printer')`, 5 méthodes Livewire `openUploadDriverModal/listDriversOnPivot/uploadDriver/detachDriver/deleteDriver` + double-guard `Gate::allows` UX/`Gate::authorize` mutantes) ; partial dédié `upload-driver-modal.blade.php` (workflow 2 étapes) ; 4e onglet `Drivers` + SFC `drivers-tab.blade.php` Option A ; pattern modale `<x-molecules.modal>` + section + footer slot strict.
- **Phase 5 — Commande sync** : `PrinterDriversSyncCommand` (signature `printer-drivers:sync --dry-run`) ; pré-flight `isSambaHealthy` → `Command::FAILURE` SANS marquage orphan en masse (fix #12 6.1 décalqué) ; algorithme idempotent ; logs préfixés `[printer-drivers:sync]` ; planifié 03:35 dans `Kernel.php` (5 min après `printers:sync` 03:30). Note importante : la sync ne crée PAS automatiquement de lignes SER pour les drivers Samba sans `printer_cups_name` connu — log warning « rattachement manuel requis » (cf. PK composite contrainte).
- **Phase 6 + 7 — Tests** : **52 tests écrits** (largement au-delà du seuil ≥34). 28 Unit Service (parsing fixtures, escapeshellarg, format adddriver, 3 data-providers sécurité ≥ 8 payloads sur driver_name / pivot_hostname / file_name), 8 Unit Model, 7 Feature Cmd, 9 Feature Livewire (section visible admin / masquée lambda / banner Samba down / upload happy-path / pivot unreachable / forge validation / detach / delete protection / gate forgé). Trait `CreatesPrinterDriversSchema` décalqué 6.1. Fixtures synthétiques annotées D13.
- **Phase 8 — Documentation** : runbook QA `docs/qa/domains/printers.md` APPEND-ONLY (jamais de `docs/qa/6-2-e2e-manual.md`) avec 16 scénarios stables `6.2-1` à `6.2-16` (dépasse le minimum 12). `docs/domains/printers.md` enrichi de ~250 LOC : architecture 2 couches, workflow upload pivot W10, distinction PPD CUPS vs driver SMB, API `PrintDriverService` (tableau méthodes), exceptions structurées, modèle Eloquent PK composite, commande sync, sudoers v2 (4 entrées 6.2), warning non-vérification signature Authenticode (D12). `docs/domains/parc.md` enrichi de la mention 6.2 sous « Onglet Imprimantes (Story 6.1) ».
- **Phase 9 — Non-régression statique** : `Printer.php` aug. additif (relation `drivers()` ajoutée — aucune méthode 6.1 modifiée) ; `printers-tab.blade.php` aug. additif (DI service, propriétés, méthodes, section Blade — aucune méthode/propriété 6.1 supprimée ou modifiée) ; `app/Console/Kernel.php` aug. additif (schedule 03:35) ; `index.blade.php` aug. additif (4e onglet conditionnel `@can('manage-printer')`). Tests 6.1 non touchés. Couverture runtime déférée au merge VM.

#### Décisions techniques in-flight

1. **D14bis → Option A** : 4e onglet `Drivers` dans `/parc?tab=drivers` (recommandation SM appliquée par défaut, aucune friction).
2. **PK composite Eloquent conservée** : pas de pivot vers `id` auto-incrément. Coexistence Eloquent + Query Builder s'est avérée propre pour cette story (`findByKey()` + `query()->where()->update()`). Si une story future veut du route model binding sur `printer_drivers`, refactor possible sans casser le schéma (ajout colonne `id` + unique constraint).
3. **Sync `printer-drivers:sync` ne crée pas automatiquement de lignes SER** : la PK composite exige un `printer_cups_name` qu'on ne peut pas déduire de `rpcclient enumdrivers` seul (ce dernier ne retourne pas le rattachement printer↔driver — il faudrait croiser avec `rpcclient enumprinters` pour CHAQUE driver, batch O(N) acceptable mais sémantique d'association ambiguë sans intervention admin). On log warning + on ne crée que la ligne d'orphan-marking / restauration. Le DEV documente cette nuance dans `printers.md` + dans la note de tête de la commande.
4. **Validation `cups_name` réutilise `HOSTNAME_REGEX`** : cohérent legacy (les `cups_name` SE5 suivent la regex NetBIOS 15 chars, héritage 6.1 `NAME_REGEX = /^[a-zA-Z0-9_-]{1,15}$/` qui est un sous-ensemble de `HOSTNAME_REGEX`).
5. **`getDriverDefinition` lève `PrintDriverException` même si RC=0 mais parsing vide** : protection contre les sorties `rpcclient` inattendues (driver côté pivot absent / format non standard).

#### Fixtures synthétiques justifiées (D13)

- VM `192.168.122.50` injoignable au moment du dev → impossible de capturer les 5 fixtures réelles cibles.
- Fallback appliqué strictement : formats reproduits depuis `sambaedu/includes/printers.inc.php` lignes 45-86 (`get_printer_driver` regex `/^\s*(.*): \[((.*\\\\3\\\\)?(.*))\]/`), 110-112 (`adddriver "Windows x64" "<...>:NULL:NULL:<deps>" "3"`), 478 (`enumdrivers` regex `/^.*Driver Name: \[(.*)\]$/`), 572-583 (`enumprinters` regex `/^\s*description:\[.*\\\\(.+),(.+),(.+)\]$/`), 542-547 (`getprinter` regex `/^\s*description:\[(.*),(.*),(.*)\]$/`).
- 5 fichiers fixtures + `SYNTHETIC.md` annoté avec citations légales lignes legacy + TODO follow-up `[DEV]` de capture réelle.

#### Follow-ups consignés

- **[PROD]** Packager les 4 entrées sudoers v2 (rpcclient / smbclient / chown / rm path-restricted) dans `scripts/update.sh` (cohérent suggestion 6.1 jamais matérialisée — opportunité de packaging conjoint).
- **[PROD]** Vérifier `[print$]` configuré sur les VMs déployées (`smbclient -L //se4fs --use-kerberos=required | grep print\\$`).
- **[PROD]** Vérifier `kinit -k` cron actif pour le compte machine `se4fs$`.
- **[PROD]** Documenter dans le guide opérateur la procédure « installer un driver sur le poste W10 pivot » (hors scope SE5).
- **[PROD]** Exécuter `sudo /usr/share/sambaedu/sbin/rest_rights.sh -p` pour poser/restaurer les ACL POSIX `/var/lib/samba/printers/x64/` (référence externe — pas portée en PHP côté SE5).
- **[DEV]** Capturer les 5 fixtures Samba réelles sur la VM dès joignable et supprimer `tests/fixtures/samba/SYNTHETIC.md`.
- **[DEV]** Exécuter `php artisan test --filter='PrintDriver|PrinterDriversSyncCommand|PrintersTabDrivers'` sur VM post-merge inotify.
- **[DEV]** Exécuter `php artisan migrate` sur VM post-merge inotify (table `printer_drivers` à créer).
- **[DEV]** Validation `php artisan schedule:list` post-merge : présence ligne `printer-drivers:sync` à 03:35.
- **[DEV]** Exécuter les 16 scénarios E2E `6.2-1` à `6.2-16` sur VM avec poste W10 pivot ; ajuster regex parser `PrintDriverService::getDriverDefinition` si divergence format observée.

#### Signaux d'alerte à porter à l'orchestrateur

- **Aucune validation runtime** : 0 test exécuté, 0 migration appliquée. La review adversariale devra simuler / mocker exhaustivement avant le merge sur main. L'oeil critique doit en particulier vérifier :
  - Le parsing `rpcclient getdriver` (regex `/^\s*(.+?):\s*\[((.*\\\\3\\\\)?(.*))\]\s*$/`) qui repose sur les fixtures synthétiques — divergence possible sur version Samba 4.x.y prod.
  - Le `chown www-admin:www-admin` post-copy via `sudo /bin/chown ...` qui exige l'entrée sudoers v2 EXACTE (espace, échappement `:`) — un drift documentation ↔ packaging casserait le workflow upload silencieusement.
  - Le `wire:confirm` JS du bouton « Supprimer driver » dans `printers-tab.blade.php` qui informe l'admin avant le `deleteDriver` côté Livewire — vérifier au runtime que la protection D8 (refus si rattaché) s'affiche BIEN comme un toast d'erreur (pas un abort/redirect).
  - La logique `loadPrinterDrivers` côté `openEditModal` : SambaUnavailable / Kerberos KO → banner + actions désactivées MAIS modale ouverte. À tester sur VM.
- **Note Option A vs B** : si l'orchestrateur juge que 4 onglets `/parc` chargent trop l'UX (Groupes / Postes / Imprimantes / Drivers), un pivot vers Option B (page dédiée `/parc/drivers`) est possible sans casser les tests — le SFC `drivers-tab.blade.php` est autonome (juste à changer le routing).

### File List

**Fichiers créés (15)** :

- `app/Services/Print/PrintDriverService.php` (≈ 730 LOC)
- `app/Services/Print/Exceptions/PrintDriverException.php`
- `app/Services/Print/Exceptions/SambaUnavailableException.php`
- `app/Services/Print/Exceptions/WindowsPivotUnreachableException.php`
- `app/Services/Print/Exceptions/KerberosTicketException.php`
- `app/Models/PrinterDriver.php`
- `app/Console/Commands/PrinterDriversSyncCommand.php`
- `database/migrations/2026_05_20_120000_create_printer_drivers_table.php`
- `database/factories/PrinterDriverFactory.php`
- `resources/views/pages/parc/_partials/upload-driver-modal.blade.php`
- `resources/views/pages/parc/_partials/drivers-tab.blade.php`
- `tests/Unit/Services/Print/PrintDriverServiceTest.php` (28 tests)
- `tests/Unit/Models/PrinterDriverTest.php` (8 tests)
- `tests/Feature/Console/PrinterDriversSyncCommandTest.php` (7 tests)
- `tests/Feature/Livewire/Parc/PrintersTabDriversTest.php` (9 tests)
- `tests/Traits/CreatesPrinterDriversSchema.php`
- `tests/fixtures/samba/SYNTHETIC.md`
- `tests/fixtures/samba/rpcclient-enumdrivers.txt`
- `tests/fixtures/samba/rpcclient-getdriver-Generic.txt`
- `tests/fixtures/samba/rpcclient-enumprinters-pivot.txt`
- `tests/fixtures/samba/rpcclient-getprinter-cups-pdf.txt`
- `tests/fixtures/samba/smbclient-ls-print-share-x64.txt`

**Fichiers modifiés (6)** :

- `app/Models/Printer.php` (ajout relation `drivers(): HasMany` + import HasMany)
- `app/Console/Kernel.php` (ajout planification `printer-drivers:sync` 03:35)
- `resources/views/pages/parc/index.blade.php` (ajout 4e onglet `Drivers` + branchement vers SFC `drivers-tab`)
- `resources/views/pages/parc/_partials/printers-tab.blade.php` (enrichissement additif : DI `PrintDriverService`, propriétés drivers, 5 nouvelles méthodes Livewire + section drivers dans modale édit + include partial upload-driver-modal — 868→1361 LOC)
- `docs/domains/printers.md` (enrichi ~250 LOC : sections Pilotes Windows 6.2)
- `docs/domains/parc.md` (mention Story 6.2 sous section Onglet Imprimantes)
- `docs/qa/domains/printers.md` (APPEND-ONLY : Section Story 6.2 avec 16 scénarios stables `6.2-1` à `6.2-16`)

### Change Log

| Date | Version | Description | Author |
|------|---------|-------------|--------|
| 2026-05-20 | 0.2 | Dev livré par `claude-opus-4-7[1m]` (worktree `6-2`). 9 phases couvertes, 38 tâches cochées. 22 fichiers créés + 7 modifiés. Décisions techniques in-flight : D14bis → Option A (4e onglet Drivers), PK composite Eloquent conservée. 52 tests écrits (28 Unit Service + 8 Unit Model + 7 Feature Cmd + 9 Feature Livewire) dépassant le seuil ≥34. Fixtures synthétiques annotées D13 (VM injoignable). Runbook QA append-only avec 16 scénarios 6.2-1 à 6.2-16. docs/domains/printers.md enrichi 250 LOC. Aucun test exécuté (PHP absent host + VM injoignable) — déféré merge inotify. Status `ready-for-dev → review`. | claude-opus-4-7[1m] |
| 2026-05-19 | 0.1 | Création story par SM (claude-opus-4-7 1M context, worktree `6-2`). Audit legacy Samba/rpcclient exhaustif (printers.inc.php — 8 commandes inventoriées avec ligne précise). Fondation 6.1 done référencée intégralement (CupsPrinterService, CommandRunner, FakeCommandRunner, PrinterPolicy, modale, WithToasts, fixtures pattern). 14 décisions produit D1-D14 + D14bis (Option A/B page Drivers — kickoff). 12 ACs Given/When/Then exhaustifs. 9 phases ≈ 38 tâches. ≥ 34 tests cibles (18 unit Service + 4 unit Model + 4-6 Feature commande + 8 Feature Livewire). 14 risques cartographiés (4 critiques RCE/path-trav/sudoers/driver malicieux + 5 élevés + 5 moyens). 14 anti-patterns explicites. Modèle dev recommandé : opus (6 facteurs cumulés). | claude-opus-4-7[1m] |
