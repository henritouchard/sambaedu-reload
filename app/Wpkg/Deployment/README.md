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

- `Services/`   — services applicatifs (orchestration, ingestion, dashboard).
- `Generators/` — générateurs de fichiers (HostsXmlGenerator, ProfilesXmlGenerator, WorkstationIniGenerator).
- `Jobs/`       — jobs queue (déploiement asynchrone, sync AD périodique).
- `Models/`     — modèles Eloquent dédiés au pipeline (Deployment, DeploymentWorkstationStatus).
- `Events/`     — events Laravel déclenchés par le pipeline.
- `Support/`    — utilitaires propres au pipeline. **Ne pas y introduire de classe `AtomicFileWriter`** : utiliser `App\Support\AtomicFileWriter`.
