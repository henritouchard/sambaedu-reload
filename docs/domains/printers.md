# Domaine Imprimantes — gestion CUPS et rattachement parc

_Dernière mise à jour : 2026-04-27 (story 6.1 — consultation, gestion et rattachement parc des imprimantes CUPS)._

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
| `workstationGroups(): BelongsToMany` | N:N via `printer_workstation_group` | `withPivot('attached_at', 'attached_by_user_id')`. **Pas** de `withTimestamps()` — le pivot porte uniquement `attached_at` (timestamp explicite), pas `created_at`/`updated_at`. |
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

- [Story 6.1](../../_bmad-output/implementation-artifacts/6-1-consultation-et-gestion-des-imprimantes-cups.md)
- [Epic 6 — Impression SER](../../_bmad-output/planning-artifacts/epics.md#epic-6)
- [PRD FR17-19](../../_bmad-output/planning-artifacts/prd.md#fr17-19)
- [Architecture — `App\Services\Print\`](../../_bmad-output/planning-artifacts/architecture.md)
- [Pattern shellout sudo — `XfsQuotaService` (5.1a)](filesystem.md)
- [Domaine Parc (cross-ref onglet imprimantes)](parc.md)
