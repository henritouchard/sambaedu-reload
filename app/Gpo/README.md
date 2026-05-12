# `App\Gpo` — Module GPO natif Laravel (Epic 16)

Namespace racine du module GPO natif Laravel (Epic 16). Remplace progressivement
le module legacy `sambaedu/gpo/*` et le shim 1bis.18 (a-g). Pose les fondations
techniques (channel logs, abstraction `samba-tool gpo`, garde-fous archi) pour
les stories 16.2 → 16.6.

## Garde-fous transversaux Epic 16

- **AD = source de vérité GPO** : contrairement à Epic 15 (Eloquent first),
  les GPO vivent dans l'AD/SYSVOL. Eloquent ne stocke que des **vues / cache /
  index de tracking** (à décider story par story). **Aucune migration Eloquent
  n'est créée dans Story 16.1** — les tables de tracking (cache liste, corbeille,
  journal) seront créées story par story selon besoin avéré, pas par anticipation.
- **Abstraction `samba-tool gpo`** : pas d'appel `exec()` direct dans le code
  métier. Tout passe par `App\Gpo\Services\GpoService` qui délègue à
  `App\Gpo\Support\SambaToolRunner` (utilise `Illuminate\Support\Facades\Process`
  en mode array — pas de concaténation string).
- **Channel logs `gpo`** corrélé par `operation_id` (UUID). Voir la convention
  de logging ci-dessous (s'applique à TOUT Epic 16, pas qu'à 16.1).
- **Tests architecturaux** (`tests/Architecture/GpoNamespaceTest.php` et
  `GpoLegacyIsolationTest.php`) garantissent l'isolation du namespace vis-à-vis
  du legacy et l'absence de `exec()` direct hors `SambaToolRunner`.

## Écart vs `architecture.md:453`

L'architecture documente `App\Services\Windows\GpoService`. Story 16.1 retient
`App\Gpo\Services\GpoService` pour deux raisons :

1. **Parallélisme avec `App\Wpkg\Deployment`** (Story 15.1, Epic 15) — chaque
   domaine fonctionnel a son namespace racine dédié.
2. **Cadrage Epic 16** (`epics.md:3312`) qui mentionne explicitement `App\Gpo`.

Décision SM D1 (Story 16.1, 2026-05-11). L'écart sera reflété dans
`architecture.md` lors d'une prochaine itération.

## Structure des sous-dossiers

- `Services/` — services métier (entrée principale : `GpoService`).
- `Jobs/` — jobs queue (sync, déploiement asynchrone).
- `Models/` — modèles Eloquent dédiés GPO (vues / cache / tracking — créés story
  par story).
- `Support/` — utilitaires (`SambaToolRunner`, `GpoLogger`, `GpoActionLog`).
- `Events/` — events Laravel déclenchés par le module (création, modification,
  lien, etc.).
- `Dto/` — DTOs typés strict, readonly (`GpoSummary`, `GpoLink`).

## Convention de logging Epic 16 (catalogue `action_type`)

Toute action GPO d'un service `App\Gpo\*` **doit** utiliser `GpoLogger` pour
émettre au moins 3 logs : `start` (action démarrée), `step` (étapes
intermédiaires si pertinent), `end` (success ou failure). Chaque log inclut
les champs systématiques `operation_id` (UUID), `action_type`, `gpo_name` /
`target_dn` quand disponibles.

| `action_type`          | Quand l'émettre                                            | Story où le pattern apparaît |
|------------------------|------------------------------------------------------------|------------------------------|
| `gpo.list`             | Listing GPOs (`samba-tool gpo listall`)                    | 16.1, 16.2                   |
| `gpo.show`             | Lecture détail GPO (`samba-tool gpo show`)                 | 16.1, 16.2                   |
| `gpo.containers.list`  | Containers liés à une GPO (`samba-tool gpo listcontainers`) | 16.1, 16.2                   |
| `gpo.link.get`         | Liens GPO d'un container (`samba-tool gpo getlink`)        | 16.1, 16.2                   |
| `gpo.inheritance.get`  | État d'héritage d'un container (`samba-tool gpo getinheritance`) | 16.1, 16.2             |
| `gpo.fetch`            | Fetch policy depuis SYSVOL                                 | 16.1, 16.3                   |
| `gpo.create`           | Création GPO (`samba-tool gpo create`)                     | 16.4                         |
| `gpo.delete`           | Suppression GPO                                            | 16.4                         |
| `gpo.duplicate`        | Duplication par copie d'arbre policy                       | 16.4                         |
| `gpo.section.read`     | Lecture d'une section (Firefox, Veyon, Wine…)              | 16.3                         |
| `gpo.section.write`    | Édition d'une section (avec diff before/after)             | 16.3                         |
| `gpo.link.set`         | Liaison GPO ↔ OU / WorkstationGroup                        | 16.5                         |
| `gpo.link.remove`      | Suppression de liaison                                     | 16.5                         |
| `gpo.inheritance.set`  | Modification de l'héritage OU                              | 16.5                         |
| `gpo.sysvol.write`     | Écriture fichier `.pol` / `.xml` / `.ini` SYSVOL           | 16.3, 16.4                   |
| `gpo.sambatool.exec`   | Exécution brute d'une commande `samba-tool` (niveau debug) | tous                         |
| `gpo.audit.legacy`     | Trace de portage d'un fichier legacy                       | 16.1 (audit)                 |

### Règles supplémentaires

- Pour une **mutation** (`create`, `delete`, `section.write`, `link.set`,
  `link.remove`, `inheritance.set`, `sysvol.write`), un **diff before/after**
  doit être logué dès qu'il est calculable (via `GpoActionLog::diff()`).
- Chaque appel `samba-tool` produit un log `gpo.sambatool.exec` séparé contenant
  la commande exacte (args en array), la durée, le code retour, stdout/stderr
  (tronqués à 8 Ko avec marker `[truncated]`).
- En échec, le log `end` inclut `outcome=failure`, `error.class`,
  `error.message` et `error.trace` (uniquement si `GPO_LOG_LEVEL=debug`).

### Paramétrage

- Channel : `gpo` (config dans `config/logging.php`).
- Niveau de log : `GPO_LOG_LEVEL` (default `debug` pendant Epic 16, sera bumpé
  à `info` une fois l'epic stabilisé).
- Rétention : `GPO_LOG_DAYS` (default 30 jours).
- Fichier : `storage/logs/gpo/gpo-{date}.log` (rotation quotidienne).
- Création auto du dossier au boot via `GpoServiceProvider`.

## Convention header `@legacy-port`

Tout fichier porté du legacy (`sambaedu/gpo/*.php`, `sambaedu/includes/gpo*.inc.php`,
`samba-tool.inc.php`, `delegations.inc.php`) porte un docblock de tête :

```php
/**
 * @legacy-port path="sambaedu/<file>.php"
 * @todo Refactor : <axe d'amélioration>
 * @see _bmad-output/planning-artifacts/epics.md § Story 16.x
 * @see docs/tech-debt-gpo.md
 */
```

Le but : tracer la dette restante et faciliter le tri lors du retrait du shim
1bis.18 (à terme).

## Cohabitation avec l'existant

- `App\Services\GpoSyncService` (service legacy pour la délégation
  `computer.elevate`) reste vivant et fonctionnel. Il est marqué `@deprecated`
  et sera replié progressivement dans `App\Gpo\Services\GpoService` à partir de
  Story 16.4+. **Ne pas le supprimer dans 16.1.**
- Le shim 1bis.18 (`legacy/modules/gpo/`) reste actif pendant tout Epic 16.
  Chaque story 16.x livrant une page native décide story-par-story de cohabiter
  ou d'override le catchall.

## Endpoints runtime postes clients (Story 16.3b)

Endpoints HTTP iso-contrat consommés par les postes clients Linux au
startup/logon via la GPO `se4_applications`. **Pas d'UI admin** — ces routes
servent uniquement des artefacts (script bash / config JSON) lus par des
clients automates (bash, client Veyon C++).

| URL                       | Controller                                                       | Service métier                       | Side effect AD                                    | Channel logs |
|---------------------------|------------------------------------------------------------------|--------------------------------------|---------------------------------------------------|--------------|
| `/gpo/network_out.php`    | `App\Http\Controllers\Gpo\NetworkOutController::legacyOut`       | `App\Gpo\Services\NetworkScriptGenerator` | aucun                                            | `daily` standard |
| `/gpo/veyon_out.php`      | `App\Http\Controllers\Gpo\VeyonOutController::legacyOut`         | `App\Gpo\Services\VeyonConfigGenerator` + `App\Gpo\Services\ReadUserManager` | création AD `read.user{suffix}` si absent (sous lock) | `daily` standard |
| `/gpo/veyon_out.php?licence=1` | idem (sous-action)                                          | sert `/etc/sambaedu/applications/veyon/licence.vlf` raw | aucun                                            | `daily` standard |

**Auth** : pas d'auth web (postes clients sans cookie Laravel). Garde effective =
`id` md5 32 hex présent dans APCu (`apps.$id` posée par
`legacy/modules/gpo/applications.php`, TTL 1800s — entropie effective 64 bits).
Throttle `300,1` par IP (parité firefox_out.php).

**Iso-bytes** : sortie strictement comparable byte-à-byte au legacy (modulo
`BindPassword` chiffré OAEP non-déterministe pour Veyon). Pas de `\r\n`, pas
de gzip, pas de cache.

**Fallback shim `@legacy-port`** : `ReadUserManager` délègue à
`create_ad_user`, `set_config`, `user_valid_passwd`, `usersetpassword` du
shim 1bis-18g (chargés via `legacy/bootstrap.php`). Story 16.4 portera ces
opérations en service AD natif propre.
