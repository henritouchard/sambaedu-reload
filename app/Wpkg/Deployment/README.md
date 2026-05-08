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
Client Windows (script wpkg.js / GPO startup)
        │
        │ POST /api/v1/wpkg/reports/{hostname}
        │ Authorization: Bearer <secret>
        │ Content-Type: text/plain
        ▼
WorkstationBearerAuth (middleware Phase 2)
  ├── Bearer présent : verify() vs `workstation_api_secrets.secret_hash`
  │                    + couvre rotation 7j (`previous_secret_hash`)
  └── Bearer absent  : fallback Phase 1 IP allowlist (jusqu'à 15.7)
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
  event=wpkg_report_ingested | wpkg_auth_failed | wpkg_report_parser_warning
        │
        ▼
Dashboard `/app/wpkg/deployments` lit l'agrégat via WpkgDashboardQueryService
  (DISTINCT ON / ROW_NUMBER OVER PARTITION pour portabilité PG/SQLite).
```

### Commandes Artisan

| Commande                                             | Rôle                                                                 |
|------------------------------------------------------|----------------------------------------------------------------------|
| `wpkg:provision-secrets [--force]`                   | Provisionne un secret Bearer par poste actif (CSV stdout `hostname,secret`). Refuse hors TTY sauf `--unsafe-output-secrets`. |
| `wpkg:rotate-secret {hostname\|id}`                  | Rote le secret d'un poste avec fenêtre de chevauchement 7j.          |
| `wpkg:revoke-secret {hostname\|id}`                  | Révoque définitivement le secret. Toute requête future → 401.        |
| `wpkg:reports:archive:rotate [--days=N] [--dry-run]` | Supprime les archives plus anciennes que N jours (90 par défaut). Schedulée daily 03:00. |

### Mapping legacy → reload (Story 15.5 / AC7.1)

| Legacy                                                  | Reload                                                                                              |
|---------------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| `sambaedu/wpkg/wpkg_rapport.php`                        | `App\Http\Controllers\Api\WpkgReportController` (étendu 15.5) + `WpkgReportIngestionService`        |
| (cron systemd / SMB lecture)                            | Endpoint HTTP direct depuis client Windows (Phase 2 Bearer) + worker `wpkg:process-reports` (9.4 transition) |
| `sambaedu/wpkg/log.php`                                 | route `windows-deploy.reports.log` (9.5) — non modifié 15.5                                        |
| (aucun dashboard global)                                | `pages/wpkg/deployments/index.blade.php` (Story 15.5)                                              |
| (aucune corrélation deployment → rapports)              | `ActiveDeploymentForWorkstationQuery` + `wpkg_deployment_workstation_status` (15.1 alimentée)      |
| (auth IP allowlist seule)                               | Auth Bearer machine + table `workstation_api_secrets` (Phase 2, fallback Phase 1 jusqu'à 15.7)     |
| (pas d'archive brute)                                   | `WpkgReportArchiver` + atomic write `Y/m/d/{host}_{ts}_{sha8}.txt` + rotation 90j                  |

### Composants 15.5 ajoutés au namespace

| Catégorie       | Classe                                                            |
|-----------------|-------------------------------------------------------------------|
| Models          | `WorkstationApiSecret`, `WpkgDeployment`, `WpkgDeploymentWorkstationStatus` |
| Services        | `WpkgReportArchiver`, `WpkgDashboardQueryService`                |
| Queries         | `ActiveDeploymentForWorkstationQuery`                            |
| Events          | `WorkstationManualReevaluationRequested`                         |
| Listeners       | `RegenerateWorkstationIniOnManualReevaluation` (+ extension `InvalidateWorkstationPackagesCache`) |
| Commands        | `ProvisionWorkstationSecretsCommand`, `RotateWorkstationSecretCommand`, `RevokeWorkstationSecretCommand`, `RotateWpkgReportArchivesCommand` |

### Décisions dev (Story 15.5)

- **Rotation secrets** : option « colonne » (`previous_secret_hash` + `previous_valid_until`)
  plutôt que table historique. Simple + suffit au cas d'usage (un seul ancien secret valide
  à la fois).
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
