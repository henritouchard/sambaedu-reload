# `App\Wpkg\Deployment` — Pipeline déploiement WPKG

Namespace dédié au pipeline de distribution effective WPKG (Epic 15) :
génération `hosts.xml` / `profiles.xml` / `.ini`, ingestion des rapports clients,
dashboard de l'état de déploiement.

## Garde-fous transversaux Epic 15

- **Eloquent first (chemin critique)** : aucune lecture AD/LDAP en chemin
  critique. La direction d'écriture canonique est **Eloquent → AD via
  observers** (`WorkstationGroupObserver`, `AppProfileObserver`,
  `WorkstationObserver` qui dispatchent des `*AdSyncJob` sortants).
  Toute classe de ce namespace qui importerait `LdapRecord\*`,
  `App\LdapModels\*` ou `App\Services\Ad\*` casse le test architectural
  `tests/Architecture/WpkgDeploymentNamespaceTest.php` (Story 15.3 / AC4.1
  — durci sans whitelist).
- **Atomic write** : tout fichier consommé par un client Windows (XML, `.ini`,
  rapports) doit transiter par `App\Support\AtomicFileWriter` (`temp + rename`,
  même filesystem que la cible, suffixe PID anti-collision multi-process).
- **Channel logs `wpkg-deploy`** : tout log émis par ce namespace doit utiliser
  `Log::channel('wpkg-deploy')->withContext([...])` avec au minimum
  `deployment_id` et, quand applicable, `workstation_id` / `app_profile_id`.

## Convention header `@legacy-port`

Tout fichier porté du legacy `sambaedu/wpkg/*.php` porte un docblock de tête :

```php
/**
 * @legacy-port path="sambaedu/wpkg/<file>.php"
 * @todo Refactor : <axe d'amélioration>
 * @see _bmad-output/planning-artifacts/epics.md § Story 15.x
 */
```

Le but : tracer la dette restante et faciliter le tri lors du retrait du shim
legacy (Story 15.7).

## Sous-dossiers

- `Services/`         — services applicatifs (orchestration, resolver, ingestion, dashboard).
- `Generators/`       — générateurs de fichiers (`WorkstationIniGenerator` — Story 15.2).
- `Jobs/`             — jobs queue chemin critique (ingestion — Story 15.5).
- `Models/`           — modèles Eloquent dédiés au pipeline (`WpkgWorkstationOption` — Story 15.2 ; tracking deployments — Story 15.5).
- `Events/`           — events Laravel déclenchés par le pipeline (assignations, options, membership — Story 15.2).
- `Listeners/`        — listeners (invalidation cache, regen `.ini` — Story 15.2).
- `Http/Controllers/` — endpoints HTTP servant des artefacts WPKG (`HostsXmlController`, `ProfilesXmlController` — Story 15.2).
- `Support/`          — utilitaires propres au pipeline. **Ne pas y introduire de classe `AtomicFileWriter`** : utiliser `App\Support\AtomicFileWriter`.

## Sync AD ↔ Eloquent

> **Rappel direction d'écriture** : Eloquent → AD via observers
> `*AdSyncJob` sortants. Postgres reste l'unique source de vérité.

- **Cron entrant : aucun.** Race silencieuse possible avec les jobs
  sortants en queue (un cron qui lit AD pendant qu'une mutation sortante
  n'a pas encore été appliquée écraserait l'état SQL → perte d'écritures
  silencieuse).
- **Sync entrante** : limitée aux **imports manuels** depuis
  `/admin/sync-from-ad` (workstation_groups, app_profiles, users, etc.)
  pour le bootstrap initial post-migration legacy.
- **Drift après bootstrap** : si une divergence AD ↔ Eloquent apparaît
  malgré la direction canonique, la réconciliation se fait
  **manuellement par entité** (UI flash cards sur les pages
  `/app/parc/*`, `/app/app-profiles/*`, etc.) — pas via un sync global.

## Mapping legacy → reload (Story 15.2 / AC8.1)

| Legacy                                              | Reload                                                                                          |
|-----------------------------------------------------|-------------------------------------------------------------------------------------------------|
| `sambaedu/wpkg/hosts_xml_out.php`                   | `App\Wpkg\Deployment\Http\Controllers\HostsXmlController`                                       |
| `sambaedu/wpkg/profiles_xml_out.php`                | `App\Wpkg\Deployment\Http\Controllers\ProfilesXmlController`                                    |
| `info_poste_applications()` (`wpkg_libsql.php:212`) | `App\Wpkg\Deployment\Services\WorkstationPackagesResolver::resolve()`                           |
| `apcu_fetch/store("wpkg_poste_*", 1000)`            | `Cache::store()->remember("wpkg:packages:{lower(hostname)}", 1000, ...)` (cache-aside)          |
| `apcu_delete("wpkg_poste_$h")` (mutations métier)   | Events Laravel (`App\Wpkg\Deployment\Events\*`) + listener `InvalidateWorkstationPackagesCache` |
| `create_ini_poste()` / `update_ini_poste()`         | `App\Wpkg\Deployment\Generators\WorkstationIniGenerator::generate()` (atomic write)             |
| `delete_ini_poste()`                                | (intentionnellement non porté — stratégie 15.2 = régénérer, pas supprimer)                      |
| Constante 8 options legacy + descriptions           | Constante PHP `WorkstationIniGenerator::LEGACY_OPTIONS` (pas de stockage des descriptions en BDD) |

**Invariant Eloquent first** : `WorkstationPackagesResolver` n'importe ni `LdapRecord\*` ni `App\LdapModels\*` ni `App\Services\Ad\*`. La résolution métier est 100% Eloquent.

**Routing** : `routes/web.php` expose `/wpkg/hosts.xml` et `/wpkg/profiles.xml` sans middleware `web`/`auth` (parité legacy stricte — décision user 2026-05-04 #3).

**Émetteurs des events** : la story 15.2 livre uniquement les **classes events + listeners + tests qui dispatchent à la main**. Les vrais émetteurs (services métier, observers Eloquent sur les pivots, UI admin) sont reportés à **Story 15.4**.

## Commandes Artisan utilitaires (Story 15.2)

| Commande                            | Description                                                           |
|-------------------------------------|-----------------------------------------------------------------------|
| `wpkg:cache:warmup [--all\|--workstation=H]` | Pré-remplit le cache `wpkg:packages:*` pour un poste ou tous.         |
| `wpkg:cache:flush [--workstation=H]`         | Vide le cache pour un poste ou tous.                                 |
| `wpkg:ini:regenerate [--all\|--workstation=H]` | Régénère le `.ini` per-poste en atomic write.                       |

## UI admin (Story 15.4)

L'UI admin pilote les mutations WPKG depuis 2 surfaces existantes (Décision A
2026-05-07 — pas de routes Livewire dédiées) :

- Onglet « Applications WPKG » sur la fiche parc :
  `pages/parc/groups/[id]/index.blade.php?tab=wpkg` →
  `pages/parc/groups/[id]/_partials/wpkg-assignment-tab.blade.php`,
  `wpkg-bulk-category-modal.blade.php`, `wpkg-clone-modal.blade.php`.
- Onglet « Applications WPKG » sur la fiche poste :
  `pages/parc/machines/[id]/index.blade.php?tab=wpkg` → `_partials/wpkg-assignment-tab.blade.php`
  + sous-onglet `_partials/wpkg-options-tab.blade.php`.

Les 3 modales d'attach (apps / groupes / postes) sont mutualisées sous
`resources/views/components/organisms/wpkg/attach-{apps,groups,workstations}-modal.blade.php`
(Décision B). Elles sont **réutilisées** par la fiche profil
`pages/parc-settings/profiles/index.blade.php` (test de non-régression
`tests/Feature/AppProfile/ProfileAttachModalsRegressionTest.php`).

Permission : `wpkg.assign` (existant `SambaPermission::WpkgAssign`). Lecture
libre via `viewAny-workstationGroup` (Gate route-level), Gate method-level
`Gate::authorize('wpkg.assign')` sur les mutations.

## Events émis par les services métier (Story 15.4 / AC6)

| Méthode service                                                        | Event émis                                                  |
|------------------------------------------------------------------------|-------------------------------------------------------------|
| `AppProfileService::addApplications($pid, $appIds)`                    | `AppProfileApplicationsChanged($pid, $appIds, 'attached')`  |
| `AppProfileService::removeApplications($pid, $appIds)`                 | `AppProfileApplicationsChanged($pid, $appIds, 'detached')`  |
| `AppProfileService::addWorkstationGroups($pid, $gIds)`                 | N × `AppProfileWorkstationGroupChanged($pid, $gId, 'attached')` |
| `AppProfileService::removeWorkstationGroups($pid, $gIds)`              | N × `AppProfileWorkstationGroupChanged($pid, $gId, 'detached')` |
| `AppProfileService::addWorkstations($pid, $wIds)`                      | N × `AppProfileWorkstationChanged($pid, $wId, 'attached')`  |
| `AppProfileService::removeWorkstations($pid, $wIds)`                   | N × `AppProfileWorkstationChanged($pid, $wId, 'detached')`  |
| `AppProfileService::addApplicationsToWorkstationGroup($gId, $appIds)`  | `WorkstationGroupApplicationsChanged($gId, $attached, 'attached')` (si non vide) |
| `AppProfileService::removeApplicationsFromWorkstationGroup($gId, $aId)` | `WorkstationGroupApplicationsChanged($gId, $aIds, 'detached')` |
| `AppProfileService::addApplicationsToWorkstation($wId, $appIds)`       | `WorkstationApplicationsChanged($wId, $attached, 'attached')` |
| `AppProfileService::removeApplicationsFromWorkstation($wId, $appIds)`  | `WorkstationApplicationsChanged($wId, $appIds, 'detached')` |
| `AppProfileService::cloneConfiguration($srcId, $tgtId)`                | N × `AppProfileWorkstationGroupChanged` (par profil ajouté/retiré) + jusqu'à 2 `WorkstationGroupApplicationsChanged` (1 attached + 1 detached) + insertion `wpkg_deployments` UUID |
| `WorkstationOptionsService::update($wId, $changes)`                    | `WorkstationOptionsChanged($wId, $changedKeys)` (si keys non vides) |
| `WorkstationOptionsService::resetToDefaults($wId)`                     | `WorkstationOptionsChanged($wId, $allLegacyKeys)` (si lignes supprimées) |

**Pattern dispatch post-commit** : tous les events sont dispatchés **après**
la fermeture de `DB::transaction(...)` — invariant AC6.3 (aucun event sur
échec DB). Aucun dispatch depuis observers Eloquent (cohérence décision SM
15.2 + helper `WpkgSchemaBootstrapper` qui flush les observers métier en
testing).

## Mapping legacy → reload (Story 15.4 / AC8.1)

| Legacy                                                | Reload                                                                                                |
|-------------------------------------------------------|-------------------------------------------------------------------------------------------------------|
| `sambaedu/wpkg/parc_maintenance_apps.php`             | Onglet `?tab=wpkg` sur `pages/parc/groups/[id]/index.blade.php`                                       |
| `sambaedu/wpkg/poste_maintenance_apps.php`            | Onglet `?tab=wpkg` sur `pages/parc/machines/[id]/index.blade.php`                                     |
| `sambaedu/wpkg/poste_maintenance_options.php` (UI)    | Sous-onglet « Options .ini » de l'onglet WPKG du poste (`_partials/wpkg-options-tab.blade.php`)       |
| `set_entite_apps()` (`wpkg_libsql.php:1379`)          | `AppProfileService::add*` / `remove*` + dispatch event + `Log::channel('wpkg-deploy')`                |
| `apcu_delete("wpkg_poste_*")` post-mutation           | Listener `InvalidateWorkstationPackagesCache` (câblé sur 9 events Laravel)                            |
| Bulk catégorie legacy (manuel SQL)                    | `bulkCategory*` + Décision C (1 event pluriel `AppProfileApplicationsChanged`)                        |
| Clone parc → parc (manuel SQL)                        | `AppProfileService::cloneConfiguration` synchrone + ligne `wpkg_deployments` UUID + diff retourné     |

## Pipeline d'ingestion (Story 15.5)

### Vue d'ensemble du flux

```
Postes Windows déposent leur rapport sur le partage SMB
`/var/sambaedu/unattended/install/wpkg/rapports/{HOSTNAME}.txt`
(auth machine = jointure AD + ACL Samba)
        │
        ▼
Worker local `php artisan wpkg:process-reports` (Story 9.4)
        │
        │ POST /api/wpkg/reports/{hostname}
        │ Content-Type: text/plain
        ▼
EnsureLocalRequest (middleware Phase 1 — IP allowlist)
        │
        ▼
WpkgReportController::store()
        │
        ▼
WpkgReportIngestionService::ingest()
  ├─ SHA256 (idempotence — skip si identique)
  ├─ WpkgReportArchiver::archive() — atomic write Y/m/d/{host}_{ts}_{sha8}.txt
  ├─ parseReport() — graceful unknown (warning + best-effort)
  ├─ updateWorkstationReport() — workstation_application_status (9.4)
  │                              + workstations.last_report_at + report_sha
  ├─ ActiveDeploymentForWorkstationQuery::find() — 3 axes :
  │     workstation_ids / group_ids / profile_ids (héritage groupe + direct)
  ├─ upsert wpkg_deployment_workstation_status (15.1, agrégat)
  └─ recalcule wpkg_deployments.summary + transition status
        │
        ▼
Log structured `wpkg-deploy` channel :
  event=wpkg_report_ingested | wpkg_report_parser_warning
        │
        ▼
Dashboard `/app/wpkg/deployments` lit l'agrégat via WpkgDashboardQueryService
  (DISTINCT ON / ROW_NUMBER OVER PARTITION pour portabilité PG/SQLite).
```

### Commandes Artisan

| Commande                                             | Rôle                                                                 |
|------------------------------------------------------|----------------------------------------------------------------------|
| `wpkg:process-reports`                               | Worker local (Story 9.4) : lit les fichiers du partage SMB et POST vers `/api/wpkg/reports/{hostname}`. |
| `wpkg:reports:archive:rotate [--days=N] [--dry-run]` | Supprime les archives plus anciennes que N jours (90 par défaut). Schedulée daily 03:00. |

### Mapping legacy → reload (Story 15.5 / AC7.1)

| Legacy                                                  | Reload                                                                                              |
|---------------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| `sambaedu/wpkg/wpkg_rapport.php`                        | `App\Http\Controllers\Api\WpkgReportController` (étendu 15.5) + `WpkgReportIngestionService`        |
| cron systemd / SMB lecture                              | Worker local `wpkg:process-reports` (Story 9.4) — scan du partage SMB, POST vers l'endpoint local   |
| `sambaedu/wpkg/log.php`                                 | route `windows-deploy.reports.log` (9.5) — non modifié 15.5                                        |
| (aucun dashboard global)                                | `pages/wpkg/deployments/index.blade.php` (Story 15.5)                                              |
| (aucune corrélation deployment → rapports)              | `ActiveDeploymentForWorkstationQuery` + `wpkg_deployment_workstation_status` (15.1 alimentée)      |
| auth IP allowlist (`local.request`)                     | Conservée. L'identité machine reste portée par la jointure AD + ACL Samba côté partage `rapports/`. |
| (pas d'archive brute)                                   | `WpkgReportArchiver` + atomic write `Y/m/d/{host}_{ts}_{sha8}.txt` + rotation 90j                  |

### Composants 15.5 ajoutés au namespace

| Catégorie       | Classe                                                            |
|-----------------|-------------------------------------------------------------------|
| Models          | `WpkgDeployment`, `WpkgDeploymentWorkstationStatus`              |
| Services        | `WpkgReportArchiver`, `WpkgDashboardQueryService`                |
| Queries         | `ActiveDeploymentForWorkstationQuery`                            |
| Events          | `WorkstationManualReevaluationRequested`                         |
| Listeners       | `RegenerateWorkstationIniOnManualReevaluation` (+ extension `InvalidateWorkstationPackagesCache`) |
| Commands        | `RotateWpkgReportArchivesCommand`                                |

### Décisions dev (Story 15.5)

- **Auth machine** : retour iso-legacy 2026-05-11. La Phase 2 Bearer initialement
  prévue (table `workstation_api_secrets` + 3 commandes secrets + middleware
  `WorkstationBearerAuth`) a été retirée. L'ingestion reste pilotée par le
  worker `wpkg:process-reports` (Story 9.4) qui POST en local après scan du
  partage SMB ; l'identité machine est portée par la jointure AD + ACL Samba.
  Motif : tous les postes ne sont pas à jour au moment du déploiement et l'AD
  reste l'autorité d'identité machine pertinente.
- **Listener manuel re-évaluation** : nouveau listener dédié
  `RegenerateWorkstationIniOnManualReevaluation` (sémantique distincte des events
  15.2/15.4 — origine manuelle traçable via `triggeredByUserId`).
- **AC2.2 format `<package>`** : descopé. L'audit du code legacy local
  (`legacy/wpkg_libsql.php`) ne montre aucune trace de ce format. Le parser reste
  graceful (AC2.3) — un rapport au format inattendu sera archivé brut + warning,
  pas bloqué.
- **NFR1 < 2s sur 500 postes** : indices DB + SQL agrégé suffisent. Pas de Redis
  cache layer. Portabilité PG/SQLite via `DB::getDriverName()` dans
  `WpkgDashboardQueryService` (PG `DISTINCT ON` ↔ SQLite `ROW_NUMBER OVER PARTITION`).

## Réglages runtime (Story 15.6)

### Vue d'ensemble

Les deux bascules opérationnelles du canal de livraison Windows (`WPKG_WINGET_ENABLED`,
`WPKG_ALLOWED_IPS`) ont été sorties du fichier `.env` (figé au runtime) vers la table
`system_settings` (DB), pilotée depuis l'UI admin `/admin/settings/gpo/wpkg-deployment`.

### Précédence

```
DB (SystemSetting) > env (config()) > défaut codé fail-closed
```

Un système fraîchement provisionné reste fermé (`winget_enabled=false`, `allowed_ips=[]`)
jusqu'à ouverture explicite via l'UI ou `SystemSetting::set(...)`.

### Clés SystemSetting

| Clé                    | Type JSON     | Défaut fallback                                            |
|------------------------|---------------|------------------------------------------------------------|
| `wpkg.winget_enabled`  | `bool`        | `config('sambaedu.wpkg.winget_enabled')` (env `WPKG_WINGET_ENABLED`, défaut `false`) |
| `wpkg.allowed_ips`     | `array<string>` (IP/CIDR) | `config('sambaedu.wpkg.report_ingestion_allowed_ips')` (env `WPKG_ALLOWED_IPS`, défaut `['127.0.0.1','::1']`) |

> **`127.0.0.1`/`::1` restent TOUJOURS autorisés en dur** dans
> `EnsureLocalRequest::ALWAYS_ALLOWED` — l'allowlist DB/env ne fait qu'**ajouter**
> (jamais enlever le localhost).

### Points de lecture

1. **`WingetOutController::handle()`** — lit `wingetEnabled()` via le résolveur.
   Si `false` → **400** (parité legacy, D1/D2 story 15.6).

2. **`EnsureLocalRequest::isAllowed()`** — lit `allowedIps()` via le résolveur.
   Protège `/wpkg/winget_out.php` ET `/wpkg/linux_out.php` ET `/api/wpkg/reports/{hostname}`
   (alias `local.request`).

### Résolveur centralisé

`App\Wpkg\Deployment\Services\WpkgDeploymentSettings` centralise la précédence.
Les deux points de lecture appellent ce résolveur, pas `config()` directement.
**Pas de cache en v1** (effet immédiat garanti, cf. D6 story 15.6).

### Note de migration stopgap → DB

Suite à la livraison de Story 15.6, le stopgap appliqué manuellement le 2026-06-10
(`.env` VM : `WPKG_WINGET_ENABLED=true` + `WPKG_ALLOWED_IPS=...,192.168.122.0/24` +
`config:cache` + `chown www-admin`) peut être **rebasculé via l'UI** :

1. Connecté admin SER sur `/admin/settings/gpo/wpkg-deployment`.
2. Activer le toggle « Canal winget ».
3. Ajouter `192.168.122.0/24` dans l'allowlist + confirmer la modale.
4. Revenir `.env` aux défauts fail-closed : `WPKG_WINGET_ENABLED=false` (ou retirer la ligne).
5. Relancer `php artisan config:cache` + `chown www-admin:www-admin bootstrap/cache/config.php`
   (nécessaire pour purger l'env override au profit des défauts fall-through vers DB).

Vérifier : `POST /wpkg/winget_out.php?machine=windaube action=list list=[]` → **200**.
