# `App\Wpkg\Deployment` — Pipeline déploiement WPKG

Namespace dédié au pipeline de distribution effective WPKG (Epic 15) :
génération `hosts.xml` / `profiles.xml` / `.ini`, ingestion des rapports clients,
dashboard de l'état de déploiement.

## Garde-fous transversaux Epic 15

- **Eloquent first** : aucune lecture AD/LDAP en hot path. La synchro AD →
  Eloquent est un job périodique (`WpkgAdReconciliationJob`, Story 15.3).
  Toute classe de ce namespace qui importerait `LdapRecord\*` ou
  `App\Services\Ad\*` casse le test architectural
  `tests/Architecture/WpkgDeploymentNamespaceTest.php` — exception
  whitelistée explicitement pour `Jobs\WpkgAdReconciliationJob`.
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
- `Jobs/`             — jobs queue (sync AD périodique — Story 15.3, ingestion — Story 15.5).
- `Models/`           — modèles Eloquent dédiés au pipeline (`WpkgWorkstationOption` — Story 15.2 ; tracking deployments — Story 15.5).
- `Events/`           — events Laravel déclenchés par le pipeline (assignations, options, membership — Story 15.2).
- `Listeners/`        — listeners (invalidation cache, regen `.ini` — Story 15.2).
- `Http/Controllers/` — endpoints HTTP servant des artefacts WPKG (`HostsXmlController`, `ProfilesXmlController` — Story 15.2).
- `Support/`          — utilitaires propres au pipeline. **Ne pas y introduire de classe `AtomicFileWriter`** : utiliser `App\Support\AtomicFileWriter`.

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

**Invariant Eloquent first** : `WorkstationPackagesResolver` n'importe ni `LdapRecord\*` ni `App\Services\Ad\*`. La résolution métier est 100% Eloquent ; la sync AD reste un job périodique (`WpkgAdReconciliationJob`, Story 15.3).

**Routing** : `routes/web.php` expose `/wpkg/hosts.xml` et `/wpkg/profiles.xml` sans middleware `web`/`auth` (parité legacy stricte — décision user 2026-05-04 #3).

**Émetteurs des events** : la story 15.2 livre uniquement les **classes events + listeners + tests qui dispatchent à la main**. Les vrais émetteurs (services métier, observers Eloquent sur les pivots, UI admin) sont reportés à **Story 15.4**.

## Commandes Artisan utilitaires (Story 15.2)

| Commande                            | Description                                                           |
|-------------------------------------|-----------------------------------------------------------------------|
| `wpkg:cache:warmup [--all\|--workstation=H]` | Pré-remplit le cache `wpkg:packages:*` pour un poste ou tous.         |
| `wpkg:cache:flush [--workstation=H]`         | Vide le cache pour un poste ou tous.                                 |
| `wpkg:ini:regenerate [--all\|--workstation=H]` | Régénère le `.ini` per-poste en atomic write.                       |
