# Dette technique GPO — registre Epic 16

Document vivant qui trace les éléments de dette technique du module GPO
réécrit en natif Laravel (Epic 16). Chaque fichier porté depuis le legacy
(`sambaedu/gpo/*.php`, `sambaedu/includes/gpo*.inc.php`, `samba-tool.inc.php`,
`delegations.inc.php`) ajoute une entrée ici via son header `@legacy-port`.

> Voir aussi : `app/Gpo/README.md` (convention `@legacy-port`),
> `_bmad-output/planning-artifacts/audit-gpo-legacy.md` (audit exhaustif Story 16.1).

## Registre (ouvert)

| Date       | Story | Fichier natif                                    | Source legacy                                                  | Type dette                                        | Sortie prévue |
|------------|-------|--------------------------------------------------|----------------------------------------------------------------|---------------------------------------------------|---------------|
| 2026-05-11 | 16.1  | `app/Services/GpoSyncService.php` (legacy)       | (existant) `add_delegation_salle`/`remove_delegation_salle`    | `@deprecated` — sera replié dans `App\Gpo`        | Story 16.4+   |
| 2026-05-11 | 16.1  | `app/Gpo/Services/GpoService.php` (6 stubs)      | `samba-tool.inc.php:gpocreate,gpodel,gposetlink,gpodellink,…`  | Stubs écriture — signatures stables, body manquant | Stories 16.4 / 16.5 |
| 2026-05-11 | 16.1  | `app/Gpo/Support/SambaToolRunner.php`            | (review code 16.1 #C)                                          | Pas de log dédié sur `ProcessTimedOutException` — log `gpo.sambatool.exec` non émis sur timeout | Story 16.4 (au plus tard quand écriture lance commandes plus longues) |
| 2026-05-11 | 16.1  | `app/Gpo/Support/SambaToolRunner.php::run()`     | (review code 16.1 #D)                                          | Pas de garde-fou runtime que `$args` est `list<non-empty-string>` — input user-controlled non validé en amont | Story 16.2 (premiers controllers exposant samba-tool à requêtes HTTP) |

## Catégories de dette à anticiper

### Encodage UTF-16 dans `.pol`

`sambaedu/includes/gpo.inc.php:247,342` (`read_pol`, `write_pol`) gèrent
l'encodage policy registry Windows. Toute lecture/écriture de `.pol` dans
`App\Gpo` devra réutiliser cette logique (potentiellement via un helper porté
`@legacy-port`).

Sortie prévue : Story 16.3 (édition sections) ou Story 16.4 (CRUD).

### Vecteurs d'injection `samba-tool`

`sambaedu/includes/samba-tool.inc.php:54` (`sambatool()`) utilise
`exec("/usr/bin/samba-tool " . $command . …)` — le `$command` est construit
par chaque appelant sans échappement centralisé.

Mitigé dès Story 16.1 par `SambaToolRunner` qui impose le mode array
(`Process::run([...])`). Toute écriture de section ou de policy SYSVOL
doit passer par ce runner.

### Constantes legacy (`gpo.inc.php`)

`sambaedu/includes/gpo.inc.php` définit 30+ constantes top-level
(`REG_NONE`, `REG_SZ`, `GPO_SDDL`, `USER_GPO`, `MACHINE_GPO`, etc.).
Le module natif doit, si nécessaire, les redéfinir comme constantes de classe
ou enums PHP 8.x — pas réutiliser les `define()` globaux.

### Fonctions wbinfo non échappées

`sambaedu/includes/gpo.inc.php:177,183,196` (`get_domain_sid`,
`get_sid_from_name`, `get_name_from_sid`) appellent `exec("wbinfo …")` sans
`escapeshellarg()`. Si on doit porter ces fonctions, le port natif devra
utiliser un `WbinfoRunner` sur le même modèle que `SambaToolRunner`.

Sortie prévue : Story 16.3 / 16.4 selon qui consomme la résolution SID.

### `smbclient` / `smbcacls` pour le SYSVOL

`sambaedu/includes/gpo.inc.php` (9 occurrences, lignes 1094-1314) utilise
`smbclient` et `smbcacls` pour interagir avec SYSVOL via Samba. Story 16.4
décidera : (a) implémenter un `SmbClientRunner` sibling de `SambaToolRunner`,
ou (b) accéder au SYSVOL local (mount Samba) en filesystem direct.

## Discipline

- Toute nouvelle entrée doit pointer vers une story Epic 16 qui la sortira.
- Pas de dette « ouverte sans date » : si on ne sait pas quand on la sort, on
  le dit explicitement dans une cellule « Sortie prévue : non décidé » avec un
  TODO à clarifier.
- Avant de marquer Epic 16 terminé, ce registre doit être vide (ou tous les
  items doivent porter une note explicite « conservée volontairement »).
