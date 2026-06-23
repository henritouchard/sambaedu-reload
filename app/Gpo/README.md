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
| `gpo.link.add`         | Création d'une liaison GPO ↔ OU (`samba-tool gpo setlink`) | 16.5 ✅ implémenté            |
| `gpo.link.remove`      | Suppression de liaison (`samba-tool gpo dellink`)          | 16.5 ✅ implémenté            |
| `gpo.link.order.update` | Réordonnancement des liaisons (`reorderLinks` non atomique) | 16.5 ✅ implémenté           |
| `gpo.link.toggle.disabled` | Toggle flag `disabled` (D4 — dellink + setlink avec flag) | 16.5 ✅ implémenté        |
| `gpo.link.toggle.enforced` | Toggle flag `enforced` (D4 — idem)                     | 16.5 ✅ implémenté            |
| `gpo.inheritance.set`  | Modification de l'héritage OU (`samba-tool gpo setinheritance`) | 16.5 ✅ implémenté       |
| `gpo.sysvol.write`     | Écriture fichier `.pol` / `.xml` / `.ini` SYSVOL           | 16.3, 16.4                   |
| `gpo.sambatool.exec`   | Exécution brute d'une commande `samba-tool` (niveau debug) | tous                         |
| `gpo.audit.legacy`     | Trace de portage d'un fichier legacy                       | 16.1 (audit)                 |
| `gpo.wine.prefixes.list` | Scan FS des conteneurs Wine partagés                     | 16.3c                        |
| `gpo.wine.image.generate` | Dispatch + handle Job `GenerateWineImageJob`            | 16.3c                        |
| `gpo.wine.shortcuts.generate` | Import raccourcis Wine via `ShortcutsService::importWineShortcuts` | 16.3c                |
| `gpo.wpkg.sync.start`  | Démarrage `audit()` ou `publish()` du `WpkgGpoSynchronizer` | 16.6 ✅ implémenté            |
| `gpo.wpkg.sync.end`    | Clôture symétrique avec outcome (success/noop/failure)     | 16.6 ✅ implémenté            |
| `gpo.wpkg.template.spec` | Spécialisation des placeholders du template `se4_wpkg.zip` via shim legacy `specialise_gpo` (loggé en sous-étape par `import_gpo` côté legacy uniquement — plus invoqué séparément côté natif depuis le post-review #3 de la 16.6) | 16.6 ✅ implémenté |
| `gpo.wpkg.publish`     | Import SYSVOL via shim legacy `import_gpo` (write GPO + ré-import idempotent) | 16.6 ✅ implémenté |

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
| `/gpo/associations_out.php` (POST only) | `App\Http\Controllers\Gpo\AssociationsOutController::legacyOut` | `App\Gpo\Services\AssociationsResolver` + `App\Gpo\Services\PackagesXmlAssociationsReader` + `App\Wpkg\Deployment\Services\WorkstationPackagesResolver` (15.2) | aucun (lecture seule)                          | `daily` standard (Story 16.3c D9) |

**Auth** : pas d'auth web (postes clients sans cookie Laravel). Garde effective =
`id` md5 32 hex présent dans APCu (`apps.$id` posée par
`legacy/modules/gpo/applications.php`, TTL 1800s — entropie effective 64 bits).
Throttle `300,1` par IP (parité firefox_out.php).

**Iso-bytes** : sortie strictement comparable byte-à-byte au legacy (modulo
`BindPassword` chiffré OAEP non-déterministe pour Veyon). Pas de `\r\n`, pas
de gzip, pas de cache.

**Spécificité `associations_out.php` (Story 16.3c)** :
- Endpoint **POST only** — le legacy n'accepte pas GET (body `list` obligatoire).
- Réponse 400 (et non 200 body=""), iso-legacy `associations_out.php:25-26`
  `header("HTTP/1.1 400 Bad request"); exit()`. La sémantique est différente
  des autres endpoints : un poste qui reçoit `{}` perd ses associations →
  400 explicite est préférable à un fallback silencieux.
- Iso-bytes : `Content-Type: text/json` (non-standard, conservé pour parité
  legacy ligne 170) + body `json_encode(['result' => $result], JSON_PRETTY_PRINT)`.
- Source apps installées = `WorkstationPackagesResolver::resolve($machineName)`
  (Story 15.2, pendant natif Eloquent de `info_poste_applications`). Pas de
  shim mysqli (Décision Henri 15.2 #5).
- `packages.xml` lu via `config('sambaedu.wpkg.deploy_path').'/packages.xml'`.
  Fichier absent → log warning + retour `{"result": {}}` gracieux.
- Debug `/tmp/assoc_result.json` (parité partielle D5 — les 3 autres writes
  legacy `assoc_local.json`/`assoc_app.json`/`assoc_wpkg.json` sont skippés).
  Skip total en `app()->environment('testing')`.

## UI admin native Wine (Story 16.3c)

Page Livewire SFC `/admin/settings/gpo/wine` (renommée depuis `/app/gpo/wine`
par Story 16.9) qui remplace `legacy/modules/gpo/wine.php` (79 lignes).
Permission `server.admin` (Spatie). Channel logs `gpo` (audit admin
auditable). Pattern iso `/admin/settings/gpo` (Story 16.2 + 16.9).

| Élément                  | Path / nom                                                          |
|--------------------------|---------------------------------------------------------------------|
| URL native               | `/admin/settings/gpo/wine` (16.9)                                   |
| Route                    | `Route::livewire('/wine', 'pages::admin.settings.gpo.wine.index')` (sous-groupe `admin.gpo`, filesystem-router, pas de Controller) |
| Vue Livewire SFC         | `resources/views/pages/admin/settings/gpo/wine/index.blade.php`     |
| Service scan FS          | `App\Gpo\Services\WinePrefixScanner` (`list()` / `exists()`)        |
| Service queuer           | `App\Gpo\Services\WineImageQueuer::dispatch`                        |
| Job queue Laravel        | `App\Gpo\Jobs\GenerateWineImageJob` (tries=1, timeout=1800)         |
| Extension ShortcutsService | `App\Services\ShortcutsService::importWineShortcuts`              |
| Redirect catchall legacy | `/gpo/wine.php` → `/admin/settings/gpo/wine` (config `blocked_legacy_routes`) |

**Sécurité audit §6.F F7 corrigé** :
- Whitelist regex `^[a-zA-Z0-9._\-]*$` sur `$application` (input UI), appliquée
  côté Livewire + côté queuer + côté Job constructeur (défense en profondeur).
- `Process::run(['/usr/share/sambaedu/scripts/make_wine_image.sh', $application])`
  en **mode array** (pas de concaténation shell). Le test architecture
  `GpoNamespaceTest::it_uses_process_in_array_mode_in_generate_wine_image_job`
  garantit cette propriété.
- `GenerateWineImageJob.php` est whitelist dans `GpoNamespaceTest::SHELL_WHITELIST_FILES`
  (seul autre point autorisé à invoquer `Illuminate\Support\Facades\Process`
  hors `SambaToolRunner`).

**Idempotence (discrepance SM (a))** : `Cache::lock('gpo:wine:generate-image:{application}', 1800)`
non-bloquant côté queuer. Si déjà détenu, `WineImageAlreadyQueuedException`
remontée → toast warning UI. Lock libéré par le Job (`handle()` + `failed()`).

**Bug legacy `wine.php:52` NON reproduit** : l'attribut `selected` est posé
sur l'option strictement égale (`==`), pas via assignment.


## Endpoint `applications.php` — Story 16.7

**Position critique dans la chaîne native** : c'est l'endpoint amont qui
**POSE** la session APCu `apps.$id` (TTL 1800s) consommée par les endpoints
runtime déjà portés (`wallpaper_out` 4.7, `firefox_out`/`thunderbird_out` 4.8,
`network_out`/`veyon_out` 16.3b, `associations_out` 16.3c).

| Élément                  | Description                                                       |
|--------------------------|-------------------------------------------------------------------|
| Route                    | `Route::match(['GET', 'POST'], 'gpo/applications.php', ...)` + `throttle:300,1` |
| Controller               | `App\Http\Controllers\Gpo\ApplicationsScriptsController::generate` |
| Orchestrateur résolution | `App\Gpo\Services\ApplicationScriptsGenerator` (port `get_app_scripts_info`) |
| Assembleur scripts       | `App\Gpo\Services\ApplicationScriptsAssembler` (ports `make_*` + 12 fonctions) |
| Scanner FS               | `App\Gpo\Services\ApplicationTemplatesScanner` (port `read_application_scripts`) |
| Logger                   | `App\Gpo\Services\ApplicationLoggerService` (port `log_application_scripts`) |
| Surface AD writeback     | `App\Ldap\AdMachineManager` (check/registerHardware/listRemoteConnexion ; `set_os` non porté — OS = `workstations.os`) |
| Pose cache               | `App\Services\AppCustomization\CacheAppContextWriter` (interface `AppContextWriter`) |
| Enum bitmask erreurs     | `App\Gpo\Enums\ApplicationActionError` (7 cas iso `SAMBAEDU_*_APP_ERROR`) |
| Config substitutions     | `config/sambaedu.php` clé `gpo.applications.substitutions.whitelist` (whitelist statique — étendue Story 17.3 d'1 clé `APPLICATIONS_SCRIPTS_URL`) |
| Commande audit template (17.3) | `php artisan gpo:applications:audit [--json] [--path=<dir|.zip>]` — scan lecture pure du template GPO `se4_applications` Debian (`app/Console/Commands/AuditApplicationsGpoTemplateCommand.php`) ; détecte les `.cmd` orchestrateurs hardcodant `gpo/applications.php` legacy + placeholders hors whitelist. Cf. `docs/qa/domains/gpo.md` § 17.3. |

### Architecture hybride (DO2 option (c))

Le port natif des 13 fonctions legacy a été réalisé selon l'option hybride :

- **Un orchestrateur résolution** (`ApplicationScriptsGenerator`) port pur de
  `get_app_scripts_info` (LDAP + AD writeback + pose APCu).
- **Un assembleur scripts** (`ApplicationScriptsAssembler`) port pur de 11
  fonctions d'assemblage (`make_application_scripts`, `add_scripts`,
  `header_scripts`, `footer_scripts`, `once_scripts`, `redirect_scripts`,
  `sudo_scripts`, `wpkg_scripts`, `apt_scripts`, `local_admin_scripts`,
  `powershell_scripts` + `applySubstitutions`).
- **Un service logger** (`ApplicationLoggerService`) port de `log_application_scripts`.
- **Un service scanner** (`ApplicationTemplatesScanner`) port de `read_application_scripts`.

**Pourquoi pas 13 services individuels (option a)** : trop de fragmentation
pour des fonctions très courtes et inter-dépendantes (les séparateurs
`addScripts` sont consommés par 5+ autres fonctions). L'option hybride
préserve la testabilité (chaque méthode est testable isolément via
`ApplicationScriptsAssembler` injecté) sans démultiplier les classes.

### Sécurité défense en profondeur (Volet 6)

1. **Régex stricte** sur tous inputs HTTP AVANT side effects :
   - `machine` : `^[a-z0-9._\-$]{1,64}$`
   - `user`    : `^[A-Za-z0-9_.\-$]{1,64}$`
   - `uuid`    : `^[a-f0-9\-]{0,36}$`
   - `action`  : `^((remote)-)?([a-z]*)(-(system|server|once))?$`
   - `id`      : `^([a-f0-9]{32})?$`
   - `os`      : enum `{windows, linux}`
   - `interpreter` : enum `{cmd, bash, ps1, powershell, redirects, apt}`
2. **Whitelist substitutions** (`config/sambaedu.php` → `gpo.applications.substitutions.whitelist`) :
   - Seules les clés explicitement listées (`SE4FS_NAME`, `DOMAIN`, `UAI`,
     `NETLOGON_PATH`, `WPKG_URL`, `SAMBA_DOMAIN`, `TMP_DIR`, `CLOUD_PERSO_NAME`)
     sont substituées. Aucun input user (`machine`, `user`, `action`) ne peut
     servir de clé de substitution.
   - Clés hors whitelist restent inchangées dans la sortie (warning log).
3. **Path traversal templates** :
   - Paths scannés hardcodés (`/etc/sambaedu/applications/`, `/usr/share/sambaedu/applications/`).
   - Validation `realpath()` sur chaque sous-dossier app (rejet symlink hors préfixe).
4. **`SambaToolRunner` mode array** dans `AdMachineManager` (jamais de concat shell).
5. **Throttle 300/min/IP** sur la route.
6. **Test architecture `LdapNamespaceTest`** garantit ces propriétés.

### Catalogue `action_type` enrichi (Story 16.1 AC1.3)

| Action type                       | Description                                                  | Channel |
|-----------------------------------|--------------------------------------------------------------|---------|
| `gpo.applications.script.generate` | Génération nominale d'un script Cmd/Bash pour un poste       | `daily` |
| `gpo.applications.context.put`     | Pose APCu `apps.$id` (consommée par 4.7/4.8/16.3b/16.3c)     | `gpo`   |
| `ad.machine.check`                 | Vérification existence machine AD + auto-création startup    | `gpo`   |
| `ad.machine.hardware.register`     | Écriture `netbootGUID` LDAP (UUID BIOS)                      | `gpo`   |
| `ad.machine.os.set`                | Ajout membre groupe parc `linux`/`windows`                   | `gpo`   |
| `ad.machine.remote.list`           | Requête connexion Guacamole (shim fallback en 16.7)          | `gpo`   |

**Channels duals iso pattern Story 16.3b** :
- `gpo` (audit) : actions AD writeback + pose APCu (auditabilité Epic 16).
- `daily` (runtime) : génération scripts, validation inputs, warnings runtime
  (volume élevé ~300 logs/min boot de masse rentrée scolaire).

### Élévation admin local Windows / Linux (Story 16.7 review #4 corrigée)

Le port natif de `local_admin_scripts` legacy s'appuie sur les services
Spatie natifs Epic 7 (`done` 2026-04-29). Mapping legacy → Spatie :

| Legacy                                            | Pendant natif Spatie                                                  |
|---------------------------------------------------|------------------------------------------------------------------------|
| `have_right(SE_COMPUTER_ADMIN, $userCn)`          | `$user->hasPermissionTo('computer.elevate')`                          |
| `have_delegation($machineCn, SE_COMPUTER_ADMIN)`  | `PermissionService::canOnWorkstationGroup($user, 'computer.elevate', $group)` |

**Choix `computer.elevate`** (et non `computer.install`) : c'est la seule
permission qui déclare `requiresGpoSync() === true` dans
`App\Enums\SambaPermission::requiresGpoSync()` — sa raison d'être est
explicitement d'élever un utilisateur en admin local. Le composite legacy
`SE_COMPUTER_ADMIN` (0xEF00) contient `SE_COMPUTER_ELEVATE` (0x400), donc
tout user porteur du composite avait aussi ce bit.

**Comportement par OS** :

- **Windows logon** (`os=windows && userprofile !== '' && action=logon`) :
  si user élevable → `net localgroup administrateurs "<SAMBA_DOMAIN>\<user>"
  /add` + `set admin=1` (parité legacy `:747-749`).
- **Windows logoff** (`action=logoff`) : inconditionnel
  `net localgroup administrateurs "<SAMBA_DOMAIN>\<user>" /delete` (parité
  legacy `:743` — cleanup safety même si droits changés entre logon/logoff).
- **Linux logon** : si user élevable → `/etc/sudoers.d/<user>` créé avec
  `chmod 0440` (parité legacy `:759-760`). Le `.` du login est remplacé par
  `_` dans le nom de fichier.
- **Linux logoff** : inconditionnel `rm -f /etc/sudoers.d/<user>`.

**Pose `$info['admin']`** : `ApplicationScriptsGenerator::resolveAdminFlag()`
positionne `$info['admin'] = 1` ssi la même résolution réussit — parité
legacy `applications.inc.php:936`. Ce flag est consommé par les scripts
applicatifs via la variable `%admin%`.

**Mécanisme legacy non porté** : l'élévation **temporaire** posée par
`set_local_admin_right($user, $duration = 7200)` (paramètre
`local_admin_<user>`). Cf. `docs/tech-debt-gpo.md` section dédiée.

## Story 16.5 — Liaison GPO ↔ OU AD

Première story **write AD** d'Epic 16. Implémente les 3 stubs Story 16.1
`setLink` / `removeLink` / `setInheritance` + nouvelle méthode `reorderLinks`
(non atomique avec rollback best effort — TD-16.5-1).

### Méthodes write GpoService

| Méthode                                                            | Wrapper samba-tool             | `action_type`              | Contrat                                                                                                                                            |
|--------------------------------------------------------------------|--------------------------------|----------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------|
| `setLink(string $dn, string $guid, bool $enforce, bool $disable)` | `gpo setlink [--enforce] [--disable]` | `gpo.link.add`             | Validation regex GUID + DN AVANT exec. Idempotent (already linked → succès silencieux). Lève `InvalidArgumentException` sur input invalide.       |
| `removeLink(string $dn, string $guid)`                             | `gpo dellink`                  | `gpo.link.remove`          | Idempotent (lien absent → succès silencieux). Mêmes garanties d'input.                                                                            |
| `setInheritance(string $dn, bool $enabled)`                        | `gpo setinheritance inherit\|block` | `gpo.inheritance.set`     | **Ne reproduit PAS le bug legacy** `samba-tool.inc.php:1027` (concat `$message` au lieu `$command`). Mode array bypass naturellement.              |
| `reorderLinks(string $dn, array $orderedGuids)`                    | `gpo dellink` puis `gpo setlink` séquentiel | `gpo.link.order.update` | Non atomique. Rollback best effort si étape KO. Lève `RuntimeException` si rollback lui-même KO (état AD potentiellement incohérent — TD-16.5-1). |

Toggle disabled / enforced (boutons UI) = `removeLink` puis `setLink` avec
flag adapté. Loggé sous `gpo.link.toggle.disabled` / `gpo.link.toggle.enforced`
(en plus des logs implicites `gpo.link.add` / `gpo.link.remove` émis par les
helpers sous-jacents).

### UI native

(Routes renommées en `/admin/settings/gpo/{guid}/...` par Story 16.9 ; les anciens
chemins `/app/gpo/{guid}/...` redirigent 301 pour compat bookmarks.)

| URL                                          | Composant Livewire SFC                              | Description                                                                   |
|----------------------------------------------|-----------------------------------------------------|-------------------------------------------------------------------------------|
| `/admin/settings/gpo/{guid}/links` (16.9)   | `pages::admin.settings.gpo.[guid].links.index`     | Page dédiée gestion liaisons (DO3) — flat list OUs (DO4), 4 actions par lien |
| `/admin/settings/gpo/{guid}` (enrichi, 16.9) | `pages::admin.settings.gpo.[guid].index`           | Ajout CTA "Gérer les liaisons" + encart "Impact" comptage postes par OU      |

### Décisions structurantes (D1-D10, voir story 16.5)

- **D1** : OU AD uniquement en première itération — pas de mapping
  `WorkstationGroup` (story 16.5b éventuelle).
- **D2** : Écriture exclusivement via `samba-tool gpo setlink` (pas
  `LdapRecord::update` direct sur `gpLink`).
- **D3** : Précédence via réordonnancement explicite (`reorderLinks`).
- **D4** : Désactivation et suppression exposées séparément (toggle disabled +
  délier).
- **D5** : Graphe d'impact = arbre HTML 2 niveaux + comptage postes (KISS).
- **D6** : Modales `<x-molecules.modal>` obligatoires sur toute action write.
- **D7** : Channel `gpo` exclusif pour les writes AD (volume faible).
- **D8** : Iso-bytes non applicable (UI admin, pas endpoint runtime).
- **D9** : Tests Unit ≥12 + Feature ≥10 + Architecture +1 + smoke VM.
- **D10** : Frontière Epic 17 stricte (16.5 = liaisons, 17 = scripts Windows).

### Comptage postes par OU (DO2)

La table `workstations` n'expose pas de colonne `ou_dn` dédiée — on utilise
un suffix-match SQL sur `ad_dn` (le DN complet du poste se termine par
`,<ou_dn>` si le poste est dans l'OU). Cf. `docs/tech-debt-gpo.md` TD-16.5-2.

```sql
SELECT COUNT(*) FROM workstations
WHERE ad_dn ILIKE '%,<OU_DN>' AND archived_at IS NULL;
```

### Liste OUs domaine (DO4)

`OrganizationalUnitRepository::listAll(int $cacheSeconds = 300)` retourne un
tableau associatif `DN => display_name` trié alphabétiquement. Cache 5 min
pour éviter de bombarder l'AD à chaque rendu (LDAP `query()->get()` peut être
lent sur parc large).

### Limitations (cf. `docs/tech-debt-gpo.md`)

- **TD-16.5-1** : `reorderLinks` non atomique. Rollback best effort
  uniquement — risque résiduel si crash mi-rollback.
- **TD-16.5-2** : Comptage postes via suffix-match `ad_dn` (pas de colonne
  `ou_dn` dédiée — poste non synchronisé AD ne sera pas compté).
- **TD-16.5-3** : Flat list OUs domaine (pas d'arbre hiérarchique) — UX
  dégradée si parc avec >50 OUs.



## Story 16.6 — Hook GPO ↔ invocation `wpkg.js` côté client (jonction Epic 15)

Outil d'audit et de (re-)publication de la GPO `se4_wpkg` qui déclenche
`cscript wpkg.js /server=<SE4FS_NAME>` sur les postes Windows au boot machine.
Garantit la cohérence entre la GPO (côté SYSVOL) et les endpoints serveur
`/wpkg/hosts.xml` + `/wpkg/profiles.xml` (Story 15.2) + l'auth Bearer Phase 2
(Story 15.5 lecture seule).

**Frontière nette** : 16.6 **ne crée pas** la GPO from scratch (= Epic 17.1).
Il consomme le template officiel `/usr/share/sambaedu/gpo/se4_wpkg.zip` et
re-spécialise ses placeholders (`###_SE4FS_NAME_###`, `###_DOMAIN_###`, etc.).

### Classes

| Type     | Path                                                          | Rôle                                                                                  |
|----------|---------------------------------------------------------------|---------------------------------------------------------------------------------------|
| Service  | `app/Gpo/Services/WpkgGpoSynchronizer.php`                    | Cœur métier : `audit(): WpkgGpoSyncReport` (readonly) + `publish(force): WpkgGpoSyncReport` (lock anti-race + shim legacy). |
| Enum     | `app/Gpo/Enums/WpkgGpoSyncSeverity.php`                       | Sévérité `ok`/`info`/`warning`/`error` avec helpers `merge()`/`exitCode()`/`rank()`.  |
| DTO      | `app/Gpo/Dto/WpkgGpoSyncReport.php`                           | Photographie immutable de l'état GPO + URLs attendues + couverture Bearer.            |
| Command  | `app/Wpkg/Deployment/Console/Commands/WpkgGpoSyncCommand.php` | `wpkg:gpo:sync {--audit-only} {--force} {--json}` — cron-friendly + déploiement.      |
| Livewire | `resources/views/pages/admin/settings/gpo/wpkg-deployment/index.blade.php` | Page admin SFC `/admin/settings/gpo/wpkg-deployment` (renommée par 16.9 ; badge sévérité + 4 tableaux + modale publish). |

### URL & route

- `/admin/settings/gpo/wpkg-deployment` (16.9) — name `admin.gpo.wpkg-deployment`,
  middleware `web` + `sambaedu.auth` + `sambaedu.admin` (via groupe `/admin`)
  + `can:server.admin`.
- Déclarée AVANT `/{guid}` paramétré (segment statique `wpkg-deployment` ne
  matche pas la regex GUID, mais ordre explicite pour clarté).
- L'ancien chemin `/app/gpo/wpkg-deployment` redirige 301 (compat Phase 2).

### Architecture

```
[Admin /admin/settings/gpo/wpkg-deployment]    [Cron / Ansible]
            │                                          │
            ▼                                          ▼
 pages::admin.settings.gpo.wpkg-deployment.index   wpkg:gpo:sync (artisan)
            │                                          │
            └───────────► WpkgGpoSynchronizer ◄────────┘
                                  │
              ┌───────────────────┼──────────────────┐
              │                   │                  │
              ▼                   ▼                  ▼
       GpoService          ZipArchive scan      Cache::lock
       (list/getLinks)     placeholders        gpo:wpkg:sync
              │                   │                  │
              ▼                   ▼                  │
     samba-tool gpo *    Whitelist substitutions     │
                         (config 16.7)              ▼
                                              legacy.import_gpo
                                              (binding container +
                                               fallback fn globale ;
                                               enchaîne en interne
                                               unzip_gpo → specialise_gpo
                                               → sysvol_put)
                                                     │
                                                     ▼
                                              SYSVOL Policies/{GUID}
```

### Sécurité (défense en profondeur)

| Niveau              | Mécanisme                                                                                    |
|---------------------|----------------------------------------------------------------------------------------------|
| Permission          | `SambaPermission::ServerAdmin` (`server.admin`) Spatie — middleware route + `abort_unless` dans `mount()`. |
| Path traversal      | `realpath()` du template + préfixe autorisé `/usr/share/sambaedu/gpo/` (skip en `testing`).  |
| Lock anti-race      | `Cache::lock('gpo:wpkg:sync', 60)->block(10)` bloquant — `RuntimeException` après 10s.       |
| Injection shell     | Aucun input user concaténé : le shim `import_gpo` est invoqué via fonction PHP (jamais exec). |
| Frontière legacy    | Binding container `legacy.import_gpo` (orchestre seul `unzip_gpo → specialise_gpo → sysvol_put`) + test architecture qui interdit l'invocation hors `WpkgGpoSynchronizer`. |
| Audit trail         | Channel `gpo` exclusif + 4 nouveaux `action_type` + `operation_id` UUID propagé.             |

### Décisions structurantes (D1-D10, voir story 16.6)

- **D1** : Périmètre = audit + republish (pas de génération from scratch = Epic 17.1).
- **D2** : URL serveur via `URL::route('wpkg.hosts-xml')` / `wpkg.profiles-xml` (single source of truth de 15.2).
- **D3** : Service métier avec `audit()` + `publish(force)` — pattern service métier audit/publish (cf. 16.3b).
- **D4** : UI Livewire SFC + commande artisan double accès.
- **D5** : Modale `<x-molecules.modal>` confirmation obligatoire sur publish.
- **D6** : Fallback shim `@legacy-port` autorisé (`import_gpo`/`specialise_gpo`) — pas de portage natif.
- **D7** : Channel `gpo` exclusif + 4 nouveaux `action_type`.
- **D8** : Permission `server.admin` (iso 16.2/16.5).
- **D9** : Bearer machine lecture seule (diagnostic, pas de provisioning).
- **D10** : Pas de catchall override (encart « Création GPO paused » 16-5 D11 reste pour les autres GPOs).

### Discrepances ouvertes tranchées (T0)

- **DO1 — Placeholders template `se4_wpkg.zip`** : 8 clés natives héritées de
  `specialise_gpo` (legacy `sambaedu/includes/gpo.inc.php:621-630`) :
  `domain`, `samba_domain`, `se4fs_name`, `se4ad_name`, `domain_sid`,
  `se4install_name`, `ldap_base_dn`, `cloud_name`. URL serveur **implicite** via
  `###_SE4FS_NAME_###` — pas de placeholder URL explicite dans le template.
  Henri vérifiera VM-side (T8.1) que `se4_wpkg.zip` n'introduit aucun placeholder
  hors whitelist.
- **DO2 — Bearer Phase 2** : `bearer_required = false` par défaut (mode tolérant
  Phase 1). Bumper à `true` post-15.5 done (TD-16.6-3).
- **DO3 — Atomicité `import_gpo`** : best effort. Lock applicatif + log `critical`
  sur échec mid-publish (TD-16.6-1).
- **DO4 — Auto-link** : pas d'auto-link au publish — séparation responsabilité
  16.6 publication / 16.5 liaisons. UI signale via warning + CTA vers
  `/admin/settings/gpo/{guid}/links` (renommé par 16.9).

### Limitations (cf. `docs/tech-debt-gpo.md`)

- **TD-16.6-1** : `import_gpo` best effort, pas de rollback automatique
  (SYSVOL peut être incohérent en cas d'échec mid-publish). ✅ **Post-review
  fix #3 (2026-05-13)** : la source du risque la plus prégnante (l'appel
  séparé à `specialise_gpo` côté natif qui spécialisait `/tmp/<gpo>/` puis se
  faisait écraser par le tarball brut de `unzip_gpo`) a été supprimée. Le
  shim `import_gpo` reste seul point d'orchestration et enchaîne
  `unzip_gpo → specialise_gpo → sysvol_put` en interne. Le risque résiduel
  d'incohérence SYSVOL en cas de panne sur `sysvol_put` reste valide (lock +
  log critical conservés).
- **TD-16.6-2** : Shim `legacy/bootstrap.php` `import_gpo`/`specialise_gpo`
  pas porté natif (Story 16.4 paused).
- **TD-16.6-3** : Bearer Phase 2 mode tolérant par défaut. Bumper post-15.5
  done. ✅ **Post-review fix (2026-05-13)** : la clé
  `config('sambaedu.gpo.wpkg_sync.bearer_required')` est désormais déclarée
  explicitement dans `config/sambaedu.php` (avec env var
  `GPO_WPKG_BEARER_REQUIRED`) — bascule simple sans patch code.

### Post-review fixes (2026-05-13)

Corrections automatiques post-review (claude-opus-4-7, modèle adverse
sonnet) appliquées :

| # | Problème | Correction |
|---|----------|-----------|
| #3 (critique) | Appel séparé `invokeSpecialise()` redondant et casse-tête (spécialisation `/tmp/<gpo>` écrasée par `unzip_gpo` côté `import_gpo`) | **Supprimé** : `WpkgGpoSynchronizer::publish()` n'invoque plus que `legacy.import_gpo` qui enchaîne en interne `unzip_gpo → specialise_gpo → sysvol_put`. Binding `legacy.specialise_gpo` supprimé. Tests architecture + Unit + Feature adaptés. |
| #1 | Clés `config('sambaedu.gpo.wpkg_sync.*')` lues partout mais jamais déclarées en config dédiée | Ajout sous-section `wpkg_sync` dans `config/sambaedu.php` (4 clés env-overridables : `template_path`, `bearer_required`, `lock_timeout`, `lock_wait`) |
| #2 | Ordre routes `/gpo/wpkg-deployment` après `/gpo/{guid}` (fragile à la regex GUID) | Route déplacée AVANT `/gpo/{guid}` |
| #4 / #10 | `LOCK_TIMEOUT_SECONDS=60` + `LOCK_WAIT_SECONDS=10` trop courts pour absorber un `import_gpo` lent | Bumpés en valeurs par défaut 300 / 30 + rendus configurables (`lock_timeout`/`lock_wait`) |
| #8 | Branche `auditBearerCoverage` `Schema::hasTable=true` jamais couverte par les tests | 3 nouveaux tests Unit : OK 100%, partiel tolérant, error required |
| #C | Pas de cap sur `numFiles` / `getFromIndex` du ZIP (zip bomb) | `MAX_ZIP_FILES=1000` + `MAX_ZIP_ENTRY_BYTES=10MB` + log warning sur skip |
| #D | `mb_convert_encoding` échoué silencieusement | Log warning `gpo.wpkg.template.scan` + fallback brut |
| #F | Cap `limit(200)` workstations par OU sans signalement | Log warning + message DTO « Liste tronquée » |
| #H | Test `publish_runs_initial_when_gpo_absent` swallow Throwable | Test ré-écrit : assert explicite sur shim called + severity Error post-audit |

Non-actions tranchées :
- **#5 (log level `info`)** : conservé iso Epic 16.
- **#6 (mount-only iso 16.5)** : aucun changement requis.
- **#E (test path-traversal hors testing)** : laissé en TD (refacto invasive
  requise pour injecter l'environnement — pattern à généraliser plus tard).
