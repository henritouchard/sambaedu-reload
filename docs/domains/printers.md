# Domaine Imprimantes — gestion CUPS, rattachement parc et pilotes Windows

_Dernière mise à jour : 2026-05-20 (story 6.2 — gestion des pilotes Windows)._

Ce document décrit l'architecture du domaine **Imprimantes** côté SER : encapsulation des appels CUPS, modélisation Eloquent SER (`Printer`, pivot `printer_workstation_group`), commande de réconciliation `printers:sync`, sudoers et cross-références au legacy.

---

## Vue d'ensemble : deux couches complémentaires

Le SER **ne remplace pas CUPS** : CUPS reste source de vérité runtime pour le nom, l'URI, l'état (idle/printing/disabled), la file d'attente et le PPD. La table SER `printers` porte uniquement ce que CUPS ne peut pas exposer : audit (`created_at`/`updated_at`/`created_by_user_id`), métadonnée métier (`description_ser`, distincte de la description CUPS), drift (`orphan`), et **rattachement N:N à un ou plusieurs `WorkstationGroup`** via la pivot `printer_workstation_group`.

```
┌──────────────────────────────────┐
│  CUPS (runtime)                  │
│  /etc/cups/printers.conf         │
│  - lpstat -s, lpstat -l -p       │
│  - lpadmin -p / -x / -m          │
│  - cupsenable / cupsdisable      │
└────────────┬─────────────────────┘
             │ enrichissement runtime
             │ via CupsPrinterService
             ▼
┌──────────────────────────────────┐
│  SER (DB)                        │
│  Printer (cups_name, audit,      │
│  description_ser, orphan)        │
│  ⤳ pivot printer_workstation_group│
│      (cups_name × wg_id, attached_at, attached_by_user_id)│
└──────────────────────────────────┘
             │
             │ scope forUser → délégation Epic 7
             ▼
       UI /parc?tab=printers
```

---

## Architecture Service `App\Services\Print\CupsPrinterService`

Wrappe les binaires CUPS derrière une API typée. Hérite du pattern `App\Services\Filesystem\XfsQuotaService` (story 5.1a) :

- **`escapeshellarg()` systématique** sur tous les arguments user-controlled (`name`, `uri`, `description`, `location`, `ppd`).
- **Re-validation regex côté Service** (defense in depth, en plus de la validation Livewire) :
  - `NAME_REGEX = /^[a-zA-Z0-9_-]{1,15}$/` — cohérent legacy `config_printer.php:132`.
  - `URI_REGEX = #^(socket|ipp|ipps|lpd|http|https)://[^\s\'"`$;|&<>\\]+$#` — formats CUPS standard, rejet des metacaractères shell.
- **Capture séparée stdout / stderr / returnCode** via `App\Services\Print\Contracts\CommandRunner` (interface) et `RealCommandRunner` (impl basée sur `proc_open`).
- **`LC_ALL=C`** sur tous les `lpstat` pour rendre le parsing stable indépendamment de la locale (la VM dev est en français — sans `LC_ALL=C` les chaînes `is now printing` deviennent `imprime maintenant` etc.).
- **Préfixe logs `CupsPrinterService:`** strictement (préserve les grep opérateurs).
- **Fail-soft sur `listPrinters`** : si `lpstat -s` échoue (CUPS down), retourne `[]` + log warning, n'interrompt pas le rendu UI.
- **Reload Samba best-effort** post-mutation : `sudo smbcontrol smbd reload-printers` après chaque ajout / modif / suppression. Échec → log warning, pas de toast erreur (l'imprimante est créée côté CUPS, le reload n'impacte que les clients Windows — story 6.2).

### Méthodes publiques

| Méthode | Commande shell | Notes |
|---|---|---|
| `listPrinters(): array` | `lpstat -s` + `lpstat -l -p` + `lpstat -o <name>` | Un seul appel groupé, pas N appels. Fail-soft. |
| `getPrinter(string $name): ?array` | (variation single de listPrinters) | Re-valide nom. |
| `getJobsCount(string $name): int` | `lpstat -o <name>` | Compte les jobs en attente. |
| `listAvailableDrivers(): array` | `lpinfo -m` | Liste des PPD pour le select modèle. |
| `addPrinter(name, uri, ?desc, ?loc, ?ppd): bool` | `sudo lpadmin -p <name> -E -v <uri> [-D] [-L] [-m]` | + reload Samba. |
| `updatePrinter(name, $changes): bool` | `sudo lpadmin -p <name> [-v] [-D] [-L] [-m]` | Diff-based, une seule commande. |
| `deletePrinter(name): bool` | `sudo lpadmin -x <name>` | + reload Samba. |
| `enablePrinter(name): bool` | `sudo /usr/sbin/cupsenable <name>` | |
| `disablePrinter(name): bool` | `sudo /usr/sbin/cupsdisable <name>` | |

### Erreurs structurées

Toute commande mutante qui retourne `returnCode != 0` lève `App\Services\Print\Exceptions\CupsCommandException` exposant `getCommand()`, `getStderr()`, `getReturnCode()`, et `firstStderrLine()` (raccourci pour les toasts). Aucune StackTrace ne fuite vers l'UI ; le SFC catche `CupsCommandException` et appelle `toastError()` avec un message court ("Erreur CUPS : <première ligne stderr>").

---

## Modèle Eloquent `App\Models\Printer`

```php
protected $primaryKey = 'cups_name';
public $incrementing = false;
protected $keyType = 'string';
protected $fillable = ['cups_name', 'created_by_user_id', 'orphan', 'description_ser'];
protected $casts = ['orphan' => 'boolean'];
```

### Relations

| Méthode | Type | Notes |
|---|---|---|
| `workstationGroups(): BelongsToMany` | N:N via `printer_workstation_group` | `withPivot('attached_at', 'attached_by_user_id', 'is_default')`. **Pas** de `withTimestamps()` — le pivot porte uniquement `attached_at` (timestamp explicite), pas `created_at`/`updated_at`. La colonne `is_default` (Story 27.2) règle l'**imprimante par défaut** poussée par l'agent (cf. « Canal agent desired-state » ci-dessous). |
| `createdBy(): BelongsTo` | vers `User` | nullable — créateur SER (null si row créée par `printers:sync`). |

### Scopes

| Scope | Comportement |
|---|---|
| `nonOrphan()` | `where('orphan', false)` |
| `orphans()` | `where('orphan', true)` |
| `forUser(?User $user)` | Filtre selon délégation Epic 7. Si user `null` → no rows. Si `server.admin` global → toutes. Sinon → uniquement les imprimantes ayant ≥ 1 rattachement à un `WorkstationGroup` autorisé par `PermissionService::getAuthorizedWorkstationGroups($user, 'server.admin')`. |

### Migrations

- `database/migrations/2026_04_27_120000_create_printers_table.php` — `printers` (PK string `cups_name(15)`, audit, `orphan`, `description_ser`).
- `database/migrations/2026_04_27_120100_create_printer_workstation_group_table.php` — pivot N:N PK composite (`cups_name`, `workstation_group_id`), **FK `cups_name` ON DELETE CASCADE**, FK `workstation_group_id` ON DELETE CASCADE. La cascade DELETE garantit que la suppression d'une imprimante (ou d'un parc) nettoie automatiquement les rattachements.

### Factory

`Database\Factories\PrinterFactory` — `cups_name` aléatoire `imp + 6 digits`, `orphan = false`, `created_by_user_id = null`. State `orphan()` pour les tests drift.

---

## Policy `App\Policies\PrinterPolicy`

Posée par story 7.2 (Epic 7) puis étendue par story 6.1 pour le scope délégué.

### Gates

| Gate | Cible | Logique |
|---|---|---|
| `viewAny-printer` | listing global admin (back-office) | `server.admin` global. |
| `manage-printer` | CRUD + toggle + sync rattachements | hiérarchie : admin global → tous (y compris orphan) ; sinon refus si `orphan=true` ; sinon true ssi ≥ 1 `workstationGroup` rattaché autorise `server.admin` scopé via `PermissionService::canOnWorkstationGroup($user, 'server.admin', $group)`. |

Sémantique clé : **les imprimantes orphan ne sont gérables que par les admins globaux** (les délégués ne voient même pas les orphans côté listing — `Printer::forUser()->nonOrphan()`).

---

## Commande Artisan `printers:sync`

Réconcilie la table SER `printers` avec l'état réel de CUPS. Idempotente, planifiée quotidienne 03:30 (`app/Console/Kernel.php`), déclenchable manuellement, mode `--dry-run`.

### Algorithme

1. `cupsByName = collect(CupsPrinterService::listPrinters())->keyBy('name')`
2. `serByName = Printer::all()->keyBy('cups_name')`
3. Calcul des trois deltas :
   - **toAdd** = `cupsByName.keys() \ serByName.keys()` — INSERT (`orphan=false`, `created_by_user_id=null`).
   - **toMarkOrphan** = rows non-orphan en SER absents de CUPS — UPDATE `orphan=true`. **Pas de delete** (préserve les rattachements pour réintroduction).
   - **toRestore** = rows orphan en SER présents dans CUPS — UPDATE `orphan=false` (réintroduction).
4. Logs structurés `[printers:sync]` + sortie console récap.

### Exemple

```
$ php artisan printers:sync --dry-run
printers:sync [dry-run] — ajoutées : 2, marquées orphan : 1, restaurées : 0.
```

### Idempotence

Sur un état aligné (sortie de la commande à `t+1` après un run à `t`) : zéro INSERT, zéro UPDATE, sortie `ajoutées : 0, marquées orphan : 0, restaurées : 0`. Validé par `tests/Feature/Console/PrintersSyncCommandTest::command_is_idempotent_on_aligned_state`.

---

## Pivot `printer_workstation_group`

Rattachement N:N entre une imprimante CUPS et un ou plusieurs `WorkstationGroup`. Source unique de vérité pour le scope utilisateur (les utilisateurs lambdas voient uniquement les imprimantes rattachées à leurs parcs).

| Colonne | Type | Notes |
|---|---|---|
| `cups_name` | string(15) | FK `printers.cups_name`, **ON DELETE CASCADE**. |
| `workstation_group_id` | unsignedBigInteger | FK `workstation_groups.id`, **ON DELETE CASCADE**. |
| `attached_at` | timestamp | Date de rattachement (audit). |
| `attached_by_user_id` | unsignedBigInteger nullable | FK `users.id`, ON DELETE SET NULL — auteur du rattachement. |

PK composite : `(cups_name, workstation_group_id)`. Pas de `created_at`/`updated_at` standard (la sémantique d'audit est portée par `attached_at` explicite).

---

## Sudoers

Le service exécute des commandes `lpadmin`, `cupsenable`, `cupsdisable`, `smbcontrol` qui requièrent root. La conf sudoers requise est documentée dans `/etc/sudoers.d/sambaedu-cups` (cible : `www-admin` sur la VM dev, à packager dans `scripts/update.sh` côté prod) :

```
www-admin ALL=(root) NOPASSWD: /usr/sbin/lpadmin
www-admin ALL=(root) NOPASSWD: /usr/sbin/cupsenable
www-admin ALL=(root) NOPASSWD: /usr/sbin/cupsdisable
www-admin ALL=(root) NOPASSWD: /usr/bin/smbcontrol smbd reload-printers
www-admin ALL=(root) NOPASSWD: /usr/bin/cancel
```

> **Sécurité** : pas de wildcard. Whitelist explicite des binaires CUPS. Toute évolution doit passer par revue (impact : RCE root via injection si combinée avec un défaut d'`escapeshellarg`).

---

## UI : onglet `/parc?tab=printers`

Composant Livewire SFC `resources/views/pages/parc/_partials/printers-tab.blade.php`. Pattern identique aux onglets `Groupes` et `Postes` existants.

### Vue admin

- **Filtres** : Toutes / Rattachées / Non rattachées / Orphelines (les 2 derniers admin-only).
- **Tableau** : Nom, URI, État, File, Lieu, Modèle, **Parcs rattachés** (chips), Actions.
- **Badges** : `non rattachée` (warning) si pas de pivot, `orphan` (error) si row orphan.
- **Modale ajout** : section Configuration CUPS + section Métadonnées SER + section Rattachement aux parcs (multi-select checkboxes).
- **Modale édition** : pré-remplie avec config CUPS courante + `description_ser` + rattachements actuels. Le **nom CUPS est readonly** (légende "Pour renommer, supprimer puis recréer").

### Vue lambda (délégué Epic 7)

- Voit uniquement les imprimantes rattachées à un de ses parcs accessibles (`Printer::forUser($user)->nonOrphan()`).
- Pas de filtres (admin only).
- Boutons d'action présents seulement sur les imprimantes pour lesquelles `manage-printer` retourne true.

### Banner d'info (transition)

Au sommet de l'onglet : « Cette interface remplace l'ancienne page de gestion des imprimantes. » Le shim legacy `legacy/modules/printers/` a été supprimé le 2026-04-29 (refonte native 6.1 livrée).

---

## Cross-références legacy

| Fichier legacy | Description | Statut 6.1 |
|---|---|---|
| `sambaedu/printers/list_printers.php` | Liste avec cache APCu 5min | **non porté** — pas de cache 6.1 (cohérent décision 5.1a) |
| `sambaedu/printers/view_printers.php` | Fiche détaillée + toggle enable/disable | **porté** dans la modale édition + bouton toggle action |
| `sambaedu/printers/config_printer.php` | Formulaire config | **porté** dans la modale ajout / édition |
| `sambaedu/printers/delete_printer.php` | Suppression + nettoyage GPO | **porté** (suppression) ; nettoyage GPO différé Epic 9 |
| `sambaedu/printers/add_printer.php` | Rattachement à un parc via LDAP+AD | **non porté tel quel** — rattachement 6.1 passe par la table pivot SER, pas LDAP/AD |
| `sambaedu/printers/cups_driver.php` | Liste drivers `lpinfo -m` | **porté** dans `listAvailableDrivers()` |
| `sambaedu/printers/printer_jobs.php` | File d'attente + annulation jobs | **partiel** — affichage compteur seulement, annulation différée (story 6.3 future) |
| `sambaedu/includes/printers.inc.php` | Cœur métier (~900 LOC) | **réécrit** dans `CupsPrinterService` |

### À venir (hors 6.1)

- **Story 6.2** : pilotes Windows (`cupsaddsmb`, fichiers `.inf`, `rpcclient enumdrivers/getdriver/setdriver`) → `App\Services\Print\PrintDriverService`.
- **Story 6.3 (potentielle)** : annulation jobs (`/usr/bin/cancel`) côté UI.
- ~~**Epic 9** : suppression du shim legacy~~ — fait le 2026-04-29 (`legacy/modules/printers/` + `legacy/stubs/printers.inc.php` + `tests/Feature/LegacyModulePrintersTest.php` retirés).

---

## Tests

| Suite | Localisation | Coverage |
|---|---|---|
| Unit Service CUPS | `tests/Unit/Services/Print/CupsPrinterServiceTest.php` | 40 tests, 65 assertions — parsing, validation regex, escapeshellarg, exceptions structurées, fail-soft. |
| Unit Modèle Printer | `tests/Unit/Models/PrinterTest.php` | 7 tests — keyType, scopes nonOrphan/orphans/forUser, relation pivot. |
| Feature commande sync | `tests/Feature/Console/PrintersSyncCommandTest.php` | 6 tests — dry-run, ajout, marquage orphan, restauration, idempotence, rapport diff. |
| Feature délégation policy | `tests/Feature/Policies/PrinterPolicyDelegationTest.php` | 5 tests — admin global, délégué scopé, autre parc, refus orphan, refus lambda. |
| Feature Livewire SFC | `tests/Feature/Livewire/Parc/PrintersTabTest.php` | 13 tests — listing, fail-soft CUPS, scope lambda, ajout + pivot, rollback, validation regex, edit pre-fill, delete, toggle, gate forgé. |

### Helpers de test

- `tests/Support/FakeCommandRunner.php` — Test double pour `CommandRunner` interface, programmable (whenContains, whenContainsFromFixture, setDefault). Réutilisable Epic 6.2 et Epic 8.
- `tests/Traits/CreatesPrintersSchema.php` — Crée `printers` + pivot en SQLite mémoire pour Unit/Feature tests.
- `tests/fixtures/cups/*.txt` — Sorties réelles capturées sur VM (`lpstat -s`, `lpstat -l -p`, `lpinfo -m`, `lpstat -o`).

---

## Références

- [Quotas XFS](filesystem.md) — même patron d'appel système sous `sudo`
- [Checklist de pré-production](../qa/domains/printers.md)

---

## Pilotes Windows (Story 6.2)

### Architecture deux couches : Samba (runtime) vs SER (DB)

```
┌───────────────────────────────────────────┐
│  Samba (runtime)                          │
│  /etc/samba/smb.conf [print$]             │
│  /var/lib/samba/printers/x64/*.dll/.ppd   │
│  - rpcclient enumdrivers                  │
│  - rpcclient getdriver "<name>"           │
│  - rpcclient adddriver / deldriver        │
│  - rpcclient setdriver "<printer>" ""     │
│  - smbclient //pivot/print$ -c get …      │
└──────────────────┬────────────────────────┘
                   │ enrichissement runtime
                   │ via PrintDriverService
                   ▼
┌───────────────────────────────────────────┐
│  SER (DB) — table printer_drivers         │
│  PK composite (printer_cups_name,         │
│                architecture)              │
│  + audit (created_at/_by_user_id)         │
│  + source (upload-w10|synced|manual-cli)  │
│  + notes + orphan                         │
│  + FK CASCADE depuis printers.cups_name   │
└──────────────────┬────────────────────────┘
                   │ scope manage-printer
                   ▼
   UI /parc?tab=printers (modale édit)
        + /parc?tab=drivers (Option A)
```

**Principe** : Samba reste source de vérité runtime de la liste publiée
(`rpcclient enumdrivers` est appelé à chaque ouverture de modale et par la
commande sync). La table SER `printer_drivers` complète Samba avec ce
qu'il ne sait pas exposer : qui a uploadé, quand, depuis quelle source,
notes métier, rattachement métier driver↔imprimante.

### Workflow upload depuis poste pivot W10 (D2 6.2)

Le workflow décalqué iso-legacy (pas d'upload binaire HTTP) :

1. L'admin installe le driver Windows localement sur un poste W10 dédié
   (« pivot »), partage une imprimante locale qui utilise ce driver.
2. UI SE5 → modale édit imprimante CUPS → section « Drivers Windows » →
   bouton « Téléverser un driver » → modale upload :
   - saisit hostname du pivot W10,
   - clique « Lister les drivers » → `rpcclient enumprinters <pivot>`,
   - sélectionne le driver (radio button),
   - clique « Téléverser et associer ».
3. Backend :
   1. `getDriverDefinition($pivot, $driverName)` → lit la définition driver
      via `rpcclient getdriver "<name>" <pivot>` (parse legacy l. 47-58).
   2. Pour chaque fichier listé : `copyDriverFile($pivot, $file)` =
      `smbclient //pivot/print$ -c 'cd x64\3;get <file> /var/lib/samba/printers/x64/<file>'`
      puis `sudo chown www-admin:www-admin <dest>`.
   3. `registerDriver($driverDef)` =
      `rpcclient adddriver "Windows x64" "<DriverName>:<Path>:<Datafile>:<Configfile>:<Helpfile>:NULL:NULL:<deps>" "3"`.
      Format strict legacy l. 110-112. Les fields vides sont passés en
      string littéral `"NULL"`, pas en `null` PHP.
   4. INSERT SER ligne `printer_drivers` (source=`upload-w10`,
      `created_by_user_id=auth()->id()`).
   5. `attachDriverToPrinter($cupsName, $driverName)` =
      `rpcclient setdriver "<printer>" "<driver>"`.

**Rollback best-effort (D9)** : si étape K échoue, on tente de rembobiner
les étapes 1..K-1 sans interrompre le toast d'erreur — le driver Samba
ajouté mais non attaché reste visible dans l'onglet `/parc?tab=drivers`,
récupérable manuellement.

### Distinction PPD CUPS (Linux) vs driver SMB (Windows)

| Aspect | PPD CUPS (Story 6.1) | Driver SMB (Story 6.2) |
|---|---|---|
| Cible | Imprimante côté Linux | Postes Windows clients |
| Commande | `lpadmin -p <name> -m <ppd>` | `rpcclient setdriver "<printer>" "<driver>"` |
| Stockage | `/etc/cups/ppd/<name>.ppd` | `/var/lib/samba/printers/x64/*` |
| UI 6.1 | Champ « Modèle » dans modale ajout/édit | — |
| UI 6.2 | — | Section « Drivers Windows » + onglet `/parc?tab=drivers` |
| Sync | `printers:sync` 03:30 | `printer-drivers:sync` 03:35 |

Les deux couches sont **indépendantes** : une imprimante peut avoir un
PPD CUPS sans driver SMB (les postes Windows demanderont alors le
driver manuellement) ou inversement (rare, mais possible pour des
imprimantes Windows-only proxysées par Samba).

### Architecture Service `App\Services\Print\PrintDriverService`

Wrappe les binaires Samba derrière une API typée. Décalque le pattern
6.1 `CupsPrinterService` :

- **`escapeshellarg()` systématique** sur tous les arguments
  user-controlled (`printer_name`, `server_pivot`, `driver_name`,
  `architecture`, file names).
- **Re-validation regex côté Service** :
  - `DRIVER_NAME_REGEX = /^[a-zA-Z0-9 ._\-()\/]{1,255}$/`
  - `HOSTNAME_REGEX = /^[a-zA-Z0-9][a-zA-Z0-9-]{0,14}$/` (NetBIOS)
  - `FILE_NAME_REGEX = /^[a-zA-Z0-9._\-]{1,255}$/` (anti path-traversal,
    + `basename()` PHP forcé)
  - `ARCHITECTURE_ALLOWED = ['x64']` (D5 — `x86` reporté Story 6.2bis)
- **Centralisation Kerberos** : tous les `rpcclient` / `smbclient`
  passent `--use-kerberos=required` (centralisé `buildRpcclientCommand`
  / `buildSmbclientCommand`). Pas de fallback NTLM.
- **`LC_ALL=C` centralisé** dans `RealCommandRunner::run()` (héritage 6.1
  fix #14).
- **Préfixe logs `PrintDriverService:`** strictement.

#### Méthodes publiques

| Méthode | Rôle | Légende erreur |
|---|---|---|
| `isSambaHealthy()` | Pré-flight `rpcclient srvinfo` | retourne bool, ne lève pas |
| `isPivotReachable(string)` | `smbclient -L //pivot` | retourne bool |
| `validateCupsName(string)` | Defense in depth — regex CUPS (avec underscore) | `InvalidArgumentException` |
| `listAllDrivers()` | `rpcclient enumdrivers se4fs` | `SambaUnavailableException`/`KerberosTicketException`/`PrintDriverException` |
| `getDriverDefinition(string, string)` | `rpcclient getdriver "<name>" <pivot>` | + `WindowsPivotUnreachableException` |
| `getDriverDefinitionFromSe4fs(string)` | Sucre : `getDriverDefinition(SE4FS, $name)` (évite d'exposer la config) | idem |
| `listPrintersOnPivot(string)` | `rpcclient enumprinters <pivot>` | idem ci-dessus |
| `listPrintersOnSe4fs()` | Sucre : `listPrintersOnPivot(SE4FS)` — utilisé par `printer-drivers:sync` pour le rattachement auto AC4 (Q1A) | idem |
| `getDriverForPrinter(string)` | `rpcclient getprinter "<name>"` (retourne null si absent) | `SambaUnavailableException` |
| `listDriversForPrinter(string)` | Combine Samba + SER | idem |
| `copyDriverFile(string, string, string)` | `smbclient //pivot/print$ -c 'cd x64\3;get <file>'` + `chown` post-copy | `InvalidArgumentException`/`WindowsPivotUnreachableException`/`PrintDriverException` |
| `registerDriver(array)` | `rpcclient adddriver "Windows x64" "<payload>" "3"` | idem (sans pivot exception) |
| `attachDriverToPrinter(string, string)` | `rpcclient setdriver "<printer>" "<driver>"` | idem |
| `detachDriverFromPrinter(string)` | `rpcclient setdriver "<printer>" ""` | idem |
| `deleteDriver(string, string, string[])` | `rpcclient deldriver "<name>"` puis `rm` sudo path-restricted | idem |
| `unlinkDriverFiles(string[])` | `sudo rm` sudo path-restricted sans deldriver — rollback fichiers orphelins (D9) | retourne `['removed' => [], 'failed' => []]`, best-effort |

**Distinction `validatePivotHostname` vs `validateCupsName`** : les
deux regex divergent volontairement. `HOSTNAME_REGEX` est strict NetBIOS
(15 chars, alphanum + tiret, pas d'underscore) — utilisé pour les
hostnames de pivot W10 et le serveur SE4FS. `CUPS_NAME_REGEX` autorise
l'underscore (cohérent {@see CupsPrinterService::NAME_REGEX} 6.1, qui
laisse créer `imp_salle_a`). Le mélange des deux dans la 6.2 initiale
était un bug bloquant pour les imprimantes nommées avec underscore (cf.
review finding #1, fixé 2026-05-20).

#### Exceptions structurées

- **`PrintDriverException`** (extends `RuntimeException`) — erreur métier
  de commande individuelle. Méthodes `getCommand`, `getStderr`,
  `getReturnCode`, `firstStderrLine` (pour toast court). Décalque
  `CupsCommandException` 6.1.
- **`SambaUnavailableException`** — daemon Samba HS. Utilisé par
  `PrinterDriversSyncCommand` pour skip orphan-marking (fix #12
  décalqué).
- **`WindowsPivotUnreachableException`** (extends
  `PrintDriverException`) — sous-type pour les pannes côté pivot
  (poste éteint, partage non publié). Toast dédié.
- **`KerberosTicketException`** (extends `RuntimeException`) — ticket
  expiré / KRB5_KT_NOTFOUND. Le message d'exception est lisible
  utilisateur (« Authentification Samba expirée — contacter l'admin
  système »).

### Modèle Eloquent `App\Models\PrinterDriver`

**PK composite** `(printer_cups_name, architecture)`. Eloquent ne gère
pas nativement les PK composites : `$primaryKey = null` désactive les
helpers qui supposent une clé scalaire (`find()`, route model binding,
`save()` sur instance fraîche). Helper statique `findByKey()` exposé
+ Query Builder pour les mises à jour ciblées.

| Champ | Type | Rôle |
|---|---|---|
| `printer_cups_name` | string(15) | FK CASCADE vers `printers.cups_name` |
| `architecture` | string(16) | `x64` (D5) |
| `driver_name` | string(255) | Nom Samba canonique |
| `source` | string(32) | `upload-w10` / `synced` / `manual-cli` |
| `orphan` | boolean | True si présent SER mais absent Samba |
| `notes` | text nullable | Nom interne / mémo admin |
| `created_by_user_id` | bigint FK | NULL si créé par sync |

**Scopes** :
- `nonOrphan()` / `orphans()` — symétriques 6.1 `Printer`.
- `forArchitecture(string)` — filtre x64/x86.
- `bySource(string)` — filtre par provenance.

**Relations** :
- `printer(): BelongsTo` → `Printer::class, 'printer_cups_name', 'cups_name'`.
- `createdBy(): BelongsTo` → `User::class`.

Et côté `Printer` (6.1, modifié 6.2) : `drivers(): HasMany` (1:N).

### Commande Artisan `printer-drivers:sync`

Signature : `php artisan printer-drivers:sync [--dry-run]`.

Planifiée quotidienne à **03:35** (5 min après `printers:sync` 03:30 —
monitoring séparé, D7 6.2).

**Algorithme symétrique 6.1** (idempotent) :
1. Pré-flight `isSambaHealthy()` ; si false → log error +
   `Command::FAILURE` + AUCUN row marqué orphan (fix #12 6.1 décalqué).
2. `listAllDrivers()` → index Samba `(driver_name|architecture)`.
3. `PrinterDriver::all()` → index SER `(driver_name|architecture)`.
4. `listPrintersOnSe4fs()` → associations effectives
   `(cups_name → driver_name)` côté Samba (cf. Q1A — décision Henri
   2026-05-20, alignement AC4 strict).
5. Diff :
   - SER non-orphan absent Samba → UPDATE `orphan=true` (audit
     préservé). Le compteur affiché vient de la valeur retournée par
     `UPDATE` (multi-rows si un même driver_name est rattaché à plusieurs
     imprimantes — fix #5 review 2026-05-20).
   - SER orphan présent Samba → UPDATE `orphan=false` (restauration).
   - Association Samba (`cups_name`, `driver_name`) sans ligne SER ET
     `Printer` existe en SER → INSERT auto (`source=synced`,
     `created_by_user_id=null`).
   - Association Samba (`cups_name`, `driver_name`) sans ligne SER ET
     `Printer` absent → log warning « cups_name absent SER, rattachement
     manuel requis ».
6. Logs préfixés `[printer-drivers:sync]`.

### Sudoers v2 (Story 6.2)

À ajouter dans `/etc/sudoers.d/sambaedu-cups` (déploiement effectif =
follow-up `[PROD]` via `scripts/update.sh`) :

```
www-admin ALL=(root) NOPASSWD: /usr/bin/rpcclient
www-admin ALL=(root) NOPASSWD: /usr/bin/smbclient
www-admin ALL=(root) NOPASSWD: /bin/chown www-admin\:www-admin /var/lib/samba/printers/x64/*
www-admin ALL=(root) NOPASSWD: /bin/rm /var/lib/samba/printers/x64/*
```

**Restrictions strictes** :
- Whitelist binaire (`/usr/bin/rpcclient`, `/usr/bin/smbclient`) sans
  wildcard de chemin — évite le RCE via `rpcclient -c "system <cmd>"`.
- `chown` cible **hardcodée** `www-admin:www-admin` + path préfixé
  `/var/lib/samba/printers/x64/` — empêche un `chown www-admin
  /etc/passwd`.
- `rm` strictement scopé `/var/lib/samba/printers/x64/` — empêche un
  `rm /etc/sudoers`.

Test sudoers (cf. runbook QA scénario 6.2-14) : `sudo -l -U www-admin`
doit lister exactement ces 4 entrées 6.2 en plus des 6.1.

**Note sur `chown` post-copy** : le `chown www-admin:www-admin` après
`smbclient get` est best-effort et logué en warning sans bloquer. En
pratique le risque opérationnel est faible : `sudo /bin/rm
/var/lib/samba/printers/x64/<file>` outrepasse l'ownership (l'effective
UID est root via sudo), donc même si le fichier reste root-owned, le
nettoyage ultérieur fonctionne. Si la sudoers drift et que le chown
échoue systématiquement, c'est néanmoins un signal opérateur à investiguer
(scénario QA 6.2-14).

### Path `/var/lib/samba/printers/x64/` + ACL POSIX

Le dossier doit avoir des ACLs POSIX permissives pour permettre la
lecture des fichiers par `smbd` (qui sert le partage `[print$]`) et
l'écriture par `www-admin` (process PHP-FPM). Le legacy
`list_printers.php:45-60` les restaure idempotemment à chaque page —
6.2 ne porte PAS cette logique en PHP (anti-pattern : c'est de la
config système). On délègue au script bash legacy déjà maintenu par
l'équipe systèmes :

```bash
sudo /usr/share/sambaedu/sbin/rest_rights.sh -p
```

**Quand exécuter** : à chaque déploiement, ou en cas de drift visible
(scénario QA `6.2-Sudoers` détecte les chown errors). Une commande
opérateur, pas une commande SE5.

### Sécurité — Non-vérification de signature driver (D12)

**WARNING** : Story 6.2 **ne vérifie pas la signature Authenticode** des
drivers Windows téléversés. Un driver malicieux (rootkit, keylogger,
ransomware) peut techniquement être distribué via SE5 aux postes
Windows.

**Justification du choix** :
1. Surface d'attaque limitée — le driver vient d'un poste W10 « pivot »
   où l'admin l'a installé volontairement (chaîne de confiance manuelle).
2. Windows refuse en mode UAC strict les drivers non signés (défense
   côté client).
3. Le parsing Authenticode côté serveur PHP serait monstrueusement
   complexe (PKCS#7, certificats X.509, CRL, OCSP) — surface d'attaque
   PHP > sécurité gagnée.

**Recommandations opérationnelles** :
- Documenter dans le guide opérateur les sources fiables de drivers
  Windows (sites constructeurs, Windows Update Catalog).
- N'installer un driver sur le pivot W10 qu'après vérification de
  l'origine (signature visible via Explorer > Propriétés > Signatures
  numériques).
- En cas de doute, utiliser le driver universel « Generic / Generic
  PostScript Printer » (Microsoft, signé Microsoft).

### Tests

| Suite | Localisation | Coverage |
|---|---|---|
| Unit Service PrintDriverService | `tests/Unit/Services/Print/PrintDriverServiceTest.php` | 28 tests — parsing fixtures, escapeshellarg, format adddriver strict, mapping erreurs (Kerberos/pivot/Samba), 3 data-providers sécurité ≥ 8 payloads chacun. |
| Unit Modèle PrinterDriver | `tests/Unit/Models/PrinterDriverTest.php` | 8 tests — PK composite, findByKey, scopes (nonOrphan/orphans/bySource), relations (printer, createdBy), HasMany inverse depuis Printer. |
| Feature commande sync | `tests/Feature/Console/PrinterDriversSyncCommandTest.php` | 7 tests — dry-run, marquage orphan + audit préservé, restauration, idempotence, skip si Samba down, warning Samba-sans-SER, rapport combiné. |
| Feature Livewire | `tests/Feature/Livewire/Parc/PrintersTabDriversTest.php` | 9 tests — section visible admin / masquée lambda, banner Samba down, upload happy-path + insertion SER, pivot unreachable, validation forge, detach, delete protection rattachement, gate forgé. |

#### Helpers de test

- `tests/Traits/CreatesPrinterDriversSchema.php` — Crée `printer_drivers`
  en SQLite mémoire pour Unit / Feature tests.
- `tests/fixtures/samba/*.txt` — Fixtures synthétiques annotées
  `SYNTHETIC.md` (D13 fallback : VM injoignable au dev 2026-05-20,
  formats basés sur le legacy `printers.inc.php`).

### Cross-références legacy 6.2

| Fichier legacy | Description | Statut 6.2 |
|---|---|---|
| `sambaedu/printers/add_driver.php` | UI workflow upload pivot W10 | **porté** dans modale `upload-driver-modal.blade.php` |
| `sambaedu/includes/printers.inc.php:45-86` | `get_printer_driver` + `copy_driver_file` | **porté** dans `PrintDriverService::getDriverDefinition` + `copyDriverFile` |
| `sambaedu/includes/printers.inc.php:99-131` | `upload_printer_driver` (5 étapes) | **porté** orchestré côté SFC `uploadDriver` |
| `sambaedu/includes/printers.inc.php:432-443` | `set_smb_driver` | **porté** dans `attachDriverToPrinter` |
| `sambaedu/includes/printers.inc.php:469-487` | `list_smb_drivers` | **porté** dans `listAllDrivers` |
| `sambaedu/includes/printers.inc.php:530-552` | `get_smb_printer` | **porté** dans `getDriverForPrinter` |
| `sambaedu/includes/printers.inc.php:560-585` | `enum_smb_printers` | **porté** dans `listPrintersOnPivot` |
| `sambaedu/printers/list_printers.php:45-60` | Restauration ACL POSIX `/var/lib/samba/printers/` | **NON porté** — délégué au script bash `rest_rights.sh -p` |
| Legacy `escapeshellarg` faille (`get_printer_driver` n'échappe pas `$printer` ni `$server`) | Faille pré-existante | **corrigée** systématiquement dans `PrintDriverService` |

## Canal agent desired-state (Story 27.2)

Depuis l'Epic 27, l'**installation côté POSTE** des imprimantes passe par l'agent
desired-state (type `printers`, contrat §7) — un item d'état comme les autres
(Vérité #9 « l'imprimante de la salle »). Deux étages **distincts, jamais
fusionnés** :

- **Étage SERVEUR (ce domaine)** : `CupsPrinterService` pilote CUPS/Samba
  (créer/supprimer une imprimante côté serveur), la table SER porte audit +
  rattachement N:N. **Inchangé** par 27.2.
- **Étage POSTE (canal agent)** : `App\Services\Agent\Providers\PrintersStateProvider`
  **lit** le pivot `printer_workstation_group` (restreint aux mailles POSTE du
  `TargetContext`) et émet un payload `{cups_name, connection, description,
  location, is_default}`. L'agent **installe la connexion** côté poste. Le
  provider ne **fusionne pas** avec CUPS : il **lit** `CupsPrinterService::getPrinter()`
  uniquement pour la métadonnée (`description`/`location`), **jamais** l'URI
  back-end (`socket://…`). La `connection` émise est une **connexion logique**
  Samba `\\<se4fs>\<cups_name>`, pas l'URI live (couplage runtime écarté).

**Imprimante par défaut (`is_default`)** : colonne ajoutée au pivot
`printer_workstation_group` (migration
`2026_06_15_120000_add_is_default_to_printer_workstation_group.php`). Réglage
explicite par l'admin (toggle « Par défaut » dans
`pages/parc/groups/[id]/_partials/printers-list.blade.php`), valable pour un WG
**physique comme logique**. L'unicité (un seul défaut par poste) est résolue
**côté serveur** par le provider : WG **physique > logique**, départage
`cups_name` asc — **PAS** de contrainte SQL (un poste peut appartenir à plusieurs
WG porteurs d'un défaut). L'agent applique `SetDefaultPrinter` sur l'unique item
marqué.

> Détail du payload, de la convergence level-triggered et de l'isolation des
> erreurs : `docs/agent/state-providers.md` (section `printers`) et
> `agent/README.md` (handler `printers`).

- [Domaine Parc (cross-ref onglet imprimantes)](parc.md)
