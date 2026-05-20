# Fixtures Samba — annotation **SYNTHÉTIQUES** (Story 6.2, D13 fallback)

> **Statut** : ces fixtures `rpcclient` / `smbclient` sont **SYNTHÉTIQUES**.
> La VM 192.168.122.50 était injoignable au moment du développement Story 6.2 (Henri kickoff 2026-05-20).
> Le DEV a appliqué le fallback **D13** : reproduire les formats documentés dans le legacy
> `sambaedu/includes/printers.inc.php` plutôt que d'inventer ou utiliser le `man rpcclient`
> (qui peut diverger selon la version Samba).
>
> **Action de capture réelle post-livraison** : un follow-up `[DEV]` est ouvert pour
> capturer ces fixtures en direct sur la VM dès qu'elle est joignable, idéalement avec
> le driver universel `Generic / Generic PostScript Printer` installé sur un poste W10 pivot.
> Toute divergence de format constatée doit être consignée et les regex parsers de
> `App\Services\Print\PrintDriverService` ajustées.

## Pourquoi des fixtures synthétiques

- **VM injoignable** au démarrage de 6.2 (consigne Henri ; aucune `ssh /vm` possible).
- **`man rpcclient`** documente la sémantique des sous-commandes mais pas la sortie textuelle
  exacte (sensible à la locale, à la version `smbclient`, à la présence de Kerberos…).
- Le legacy SambaEdu **a parsé pendant 10+ ans** ces sorties via regex `printers.inc.php`,
  ce qui constitue notre source de vérité comportementale.

## Provenance des formats

Pour chaque fixture, on cite ci-dessous la ligne legacy de référence + la regex
parser implémentée côté SE5 `PrintDriverService`.

### `rpcclient-enumdrivers.txt`

- **Sous-commande** : `rpcclient -c 'enumdrivers' se4fs --use-kerberos=required`
- **Source format legacy** : `sambaedu/includes/printers.inc.php:478`
  ```php
  if (preg_match("/^.*Driver Name: \[(.*)\]$/", $ligne, $m) == 1) {
      $drivers[] = $m[1];
  }
  ```
- **Regex SE5** : `/^\s*Driver Name:\s*\[(.+)\]\s*$/`
  (implémentée dans `PrintDriverService::listAllDrivers`)
- **Comportement attendu** : output multi-blocs, chaque driver listé via une ligne
  `	Driver Name: [<canonical name>]`. Chaque bloc contient aussi `Driver Path`,
  `Datafile`, `Helpfile`, mais `enumdrivers` ne retourne que `Driver Name`
  (résumé) — pour la définition complète, voir `getdriver`.

### `rpcclient-getdriver-Generic.txt`

- **Sous-commande** : `rpcclient -c 'getdriver "Generic PostScript Printer"' se4fs --use-kerberos=required`
- **Source format legacy** : `sambaedu/includes/printers.inc.php:47-58`
  ```php
  if (preg_match("/^\s*(.*): \[((.*\\\\3\\\\)?(.*))\]/", $line, $m) == 1) {
      if ($m[1] == "Dependentfiles") {
          $driver['Dependentfiles'][] = $m[4];
      } else {
          if ($m[4] == "(null)" || empty($m[4])) {
              $driver[$m[1]] = "NULL";
          } else {
              $driver[$m[1]] = $m[4];
          }
      }
  }
  ```
- **Regex SE5** : `/^\s*(.+?):\s*\[((.*\\\\3\\\\)?(.*))\]\s*$/`
  (implémentée dans `PrintDriverService::getDriverDefinition`)
- **Champs attendus** : `Driver Name`, `Driver Path` (`.dll`), `Datafile` (`.ppd`),
  `Configfile` (`.dll`), `Helpfile` (`.hlp`), `Dependentfiles` (multi-lignes),
  `Architecture` (`Windows x64`).
- **Cas `(null)`** : la sortie réelle de `rpcclient getdriver` met `(null)` pour
  les champs absents. Le legacy normalise en string littéral `"NULL"` (compat
  `rpcclient adddriver` qui exige `:NULL:NULL:` pour Monitor/DefaultDataType).

### `rpcclient-enumprinters-pivot.txt`

- **Sous-commande** : `rpcclient -c 'enumprinters' w10pivot --use-kerberos=required`
- **Source format legacy** : `sambaedu/includes/printers.inc.php:572-583`
  ```php
  if (preg_match("/^\s*description:\[.*\\\\(.+),(.+),(.+)\]$/", $ligne, $m) == 1) {
      $printer[$i]['smb_name'] = $m[1];
      $printer[$i]['smb_driver'] = $m[2];
      $printer[$i]['smb_comment'] = $m[3];
  }
  ```
- **Regex SE5** : `/^\s*description:\s*\[.*\\\\(.+),(.+),(.+)\]\s*$/`
  (implémentée dans `PrintDriverService::listPrintersOnPivot`)
- **Champs attendus** par ligne `description:[\\W10PIVOT\Generic PS,Generic / Generic PostScript Printer,Imprimante PostScript locale]`.

### `rpcclient-getprinter-cups-pdf.txt`

- **Sous-commande** : `rpcclient -c 'getprinter "cups-pdf"' se4fs --use-kerberos=required`
- **Source format legacy** : `sambaedu/includes/printers.inc.php:540-553`
  ```php
  if (preg_match("/^\s*description:\[(.*),(.*),(.*)\]$/", $ligne, $m) == 1) {
      $printer['smb_name'] = $m[1];
      $printer['smb_driver'] = $m[2];
      $printer['smb_comment'] = $m[3];
  }
  ```
- **Regex SE5** : `/^\s*description:\s*\[(.+),(.+),(.+)\]\s*$/`
  (implémentée dans `PrintDriverService::getDriverForPrinter`)
- **Différence vs enumprinters** : `getprinter` retourne la description SANS le préfixe
  UNC `\\PIVOT\` (un seul printer ciblé). Le tuple `(smb_name, smb_driver, smb_comment)`
  est le même.

### `smbclient-ls-print-share-x64.txt`

- **Sous-commande** : `smbclient //se4fs/print$ --use-kerberos=required -c 'cd x64\3;ls'`
- **Source format legacy** : `sambaedu/includes/printers.inc.php:70-79`
  ```php
  if (preg_match("/^\s*([a-zA-Z0-9_\-]+\.[a-zA-Z0-9_\-]+).*/", $line, $m) == 1) {
      // compare $m[1] et $file
  }
  ```
- **Comportement attendu** : sortie tabulaire `smbclient` standard (1 entrée par
  fichier, avec taille + date). Le legacy ne parse que le premier champ (nom de
  fichier). 6.2 utilise `smbclient get` directement (pas `ls`) donc cette
  fixture sert surtout au test d'intégration `copyDriverFile`.

## Exemple driver canonique utilisé : « Generic / Generic PostScript Printer »

Le driver universel x64 PostScript fourni nativement par Windows 10. Composants
typiques :

| Champ | Valeur |
|---|---|
| `Driver Name` | `Generic / Generic PostScript Printer` |
| `Driver Path` | `pscript5.dll` |
| `Datafile` | `PSCRIPT.PPD` |
| `Configfile` | `ps5ui.dll` |
| `Helpfile` | `pscript.hlp` |
| `Dependentfiles` | `ps5ui.dll`, `pscript5.dll`, `PSCRIPT.PPD` |
| `Architecture` | `Windows x64` |

## TODO de capture réelle

- [ ] **[DEV]** Capturer `rpcclient enumdrivers se4fs --use-kerberos=required` sur la VM
  192.168.122.50 dès joignable. Comparer la regex SE5 avec la sortie réelle ;
  ajuster si divergence (notamment l'indentation du `Driver Name`).
- [ ] **[DEV]** Capturer `rpcclient getdriver "Generic PostScript Printer" se4fs` ;
  valider le marqueur `\3\` du chemin driver pour la regex SE5 (legacy l. 48).
- [ ] **[DEV]** Capturer `rpcclient enumprinters w10pivot --use-kerberos=required`
  depuis un poste W10 réel avec ≥ 1 imprimante partagée.
- [ ] **[DEV]** Capturer `rpcclient getprinter "<printer>" se4fs --use-kerberos=required`
  pour une imprimante CUPS publiée via `smb.conf` `[printers]`.
- [ ] **[DEV]** Capturer `smbclient //se4fs/print$ -c 'cd x64\3;ls'` pour vérifier
  les fichiers actuellement déposés (post-upload).
- [ ] **[DEV]** Supprimer ce fichier `SYNTHETIC.md` une fois les fixtures réelles
  capturées et validées.
