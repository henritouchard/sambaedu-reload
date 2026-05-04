# Pipeline déploiement WPKG — Architecture

> **Périmètre** : Epic 15 — fondations Story 15.1.
> Ce document est la note technique du socle ; les générateurs XML / `.ini`,
> la sync AD, l'UI assignation, le dashboard et l'ingestion native sont
> couverts par les Stories 15.2 → 15.7.

## Vue d'ensemble

Le pipeline orchestre la distribution effective WPKG sur les postes Windows :

1. Un déploiement (`wpkg_deployments`) est déclenché manuellement ou par
   schedule, ciblant des postes / groupes / profils.
2. Pour chaque cible, des fichiers partage Samba sont régénérés en atomic
   write : `hosts.xml`, `profiles.xml`, `<host>.ini`.
3. Les postes Windows lisent ces fichiers et appliquent WPKG localement.
4. Les rapports clients (`<host>.txt`) sont déposés dans `reports_inbox/`,
   ingérés via `wpkg:process-reports` (Phase 1) ou directement via API
   (Phase 2 — Story 15.5).
5. Le dashboard Story 15.5 lit `wpkg_deployment_workstation_status` corrélé
   par `deployment_id`.

## Garde-fous transversaux Epic 15

- **Eloquent first** : aucune lecture AD/LDAP en hot path — la sync AD →
  Eloquent est un job périodique (`WpkgAdReconciliationJob`, Story 15.3).
  Le namespace `App\Wpkg\Deployment` est verrouillé par
  `tests/Architecture/WpkgDeploymentNamespaceTest.php` qui interdit l'import
  de `LdapRecord\*` ou `App\Services\Ad\*`. Whitelist explicite : la classe
  `Jobs\WpkgAdReconciliationJob` (Story 15.3).
- **Atomic write** : tout fichier consommé par un client externe transite par
  `App\Support\AtomicFileWriter` (`temp + fsync + rename`, suffixe `pid`,
  même filesystem que la cible).
- **Channel logs `wpkg-deploy`** : tout log du pipeline → `Log::channel('wpkg-deploy')`
  avec contexte minimum `deployment_id`, complété quand applicable de
  `workstation_id` / `app_profile_id`.

## Structure du namespace

```
app/Wpkg/Deployment/
├── Services/    Orchestration (déploiement, ingestion, dashboard)
├── Generators/  HostsXmlGenerator, ProfilesXmlGenerator, WorkstationIniGenerator (Story 15.2)
├── Jobs/        Jobs queue : déploiement asynchrone, sync AD périodique
├── Models/      Eloquent du pipeline (Deployment, DeploymentWorkstationStatus — Story 15.2/15.5)
├── Events/      Events Laravel
└── Support/     Utilitaires propres au pipeline
                 (PAS de classe AtomicFileWriter ici — utiliser App\Support\AtomicFileWriter)
```

### Convention `@legacy-port`

Tout fichier porté du legacy `sambaedu/wpkg/*.php` porte un docblock de tête :

```php
/**
 * @legacy-port path="sambaedu/wpkg/<file>.php"
 * @todo Refactor : <axe d'amélioration>
 * @see _bmad-output/planning-artifacts/epics.md § Story 15.x
 */
```

But : tracer la dette restante et faciliter le tri lors du retrait du shim
legacy (Story 15.7).

### Note conflit namespace existant

Plusieurs classes hors namespace `App\Wpkg\Deployment` consomment des concepts
WPKG et **ne sont pas déplacées dans Story 15.1** :

- `App\Services\Windows\WpkgReportIngestionService`
- `App\Services\Windows\WorkstationLogReader`
- `App\Console\Commands\WpkgProcessReportsCommand`

L'audit de leur destin (déplacement, fusion, statu quo) est tracé en
**Story 15.3 volet 1**.

## Channel logs `wpkg-deploy`

Configuré dans `config/logging.php` :

| Clé | Valeur |
|-----|--------|
| `driver` | `daily` |
| `path` | `storage/logs/wpkg-deploy/deploy-{date}.log` |
| `days` | 30 (env `WPKG_DEPLOY_LOG_DAYS`) |
| `level` | env `WPKG_DEPLOY_LOG_LEVEL`, défaut `info` |
| `replace_placeholders` | `true` |

Usage type :

```php
Log::channel('wpkg-deploy')->withContext([
    'deployment_id' => $deploymentId,
    'workstation_id' => $ws->id,
    'app_profile_id' => $profile?->id,
])->info('Génération hosts.xml', ['target' => $path]);
```

## Tables tracking

### `wpkg_deployments`

| Colonne | Type | Notes |
|---------|------|-------|
| `id` | UUID PK | exposé partout comme `deployment_id` |
| `triggered_by` | FK users nullable | NULL si déclenché par schedule |
| `triggered_at` | timestamp | |
| `target_scope` | json | `{workstation_ids, group_ids, profile_ids}` |
| `status` | string(20) | `pending` / `running` / `completed` / `failed` / `partial` |
| `summary` | json | counts par statut |

Index sur `status`, `triggered_at`.

### `wpkg_deployment_workstation_status`

| Colonne | Type | Notes |
|---------|------|-------|
| `id` | UUID PK | |
| `deployment_id` | UUID FK → `wpkg_deployments.id` | cascade delete |
| `workstation_id` | FK → `workstations.id` | cascade delete |
| `app_profile_id` | FK nullable → `app_profiles.id` | nullOnDelete |
| `client_reported_at` | timestamp nullable | |
| `client_status` | string(20) | `pending` / `success` / `partial` / `failed` / `skipped` |
| `details` | json | |
| `error_message` | text nullable | |

Index : `(deployment_id, workstation_id)`, `(workstation_id, client_reported_at)`.

> **Choix string + cast plutôt qu'enum natif** : portabilité Postgres ↔
> SQLite (tests), cohérence avec le pattern Epic 4 / 7. Le cast modèle vers
> Enum PHP est posé dans Story 15.2 lors de la création des modèles.

## Chemins partage WPKG

Définis **en dur** dans `config/sambaedu.php` (décision 2026-05-03 — pas de
variable d'env dédiée, les ops modifient ce fichier de config si besoin) :

| Clé config | Valeur par défaut |
|------------|-------------------|
| `sambaedu.wpkg.deploy_path` | `/var/sambaedu/unattended/install/wpkg` |
| `sambaedu.wpkg.ini_path` | `/var/sambaedu/unattended/install/wpkg/ini` |
| `sambaedu.wpkg.reports_inbox` | `/var/sambaedu/unattended/install/wpkg/rapports` |
| `sambaedu.wpkg.reports_archive` | `/var/sambaedu/unattended/install/wpkg/archive` |

Les fichiers `hosts.xml` et `profiles.xml` se trouvent **dans** `deploy_path`
(parité legacy — pas de clé par fichier).

### Migration `.env` (note ops)

- Les anciennes clés `sambaedu.wpkg.reports_path` et
  `sambaedu.wpkg.reports_archive_path` ont été **renommées** (pas alias)
  vers `reports_inbox` / `reports_archive`.
- Les variables d'env `WPKG_REPORTS_PATH` et `WPKG_REPORTS_ARCHIVE_PATH`
  **ne sont plus consommées** : à retirer du `.env` de production avant
  déploiement.
- Toute customisation des chemins se fait désormais en éditant
  `config/sambaedu.php` directement (pas de fallback `env(...)`).

### Check de démarrage

Le `App\Providers\WpkgDeploymentServiceProvider::ensurePaths()` itère ces
4 chemins au boot (hors environnement de test) et :

1. **Crée** le dossier manquant via `mkdir -p` (mode `0755`) — évite que
   les admins SER aient à provisionner manuellement l'arborescence.
2. **Vérifie** R/W par le user PHP-FPM.
3. **Log** un warning sur `wpkg-deploy` si la création a échoué ou si les
   permissions restent insuffisantes (`create_attempted`, `create_succeeded`,
   `exists`, `readable`, `writable` propagés dans le contexte Monolog).

Le check est **non-bloquant** : le boot Laravel réussit toujours, même si
un partage Samba est inaccessible.

## Atomic write

`App\Support\AtomicFileWriter::write($path, $content, $mode = 0644)` fournit
les garanties suivantes :

1. Création automatique du dossier parent (`mkdir -p`).
2. Écriture dans `<dir>/.<basename>.tmp.<pid>.<random_hex>` (même filesystem
   que la cible — sinon `rename(2)` cross-FS n'est plus atomique).
3. `fwrite` complet (vérifié contre `strlen($contents)`), `fsync()` (PHP ≥ 8.1
   sur ressource `fopen`) puis `rename` atomique.
4. Cleanup explicite du tmp si exception ou écriture échouée.

> **Pourquoi le suffixe PID ?** Sur PHP-FPM, plusieurs workers peuvent écrire
> sur la même cible en parallèle. Le `random_bytes(6)` couvre la concurrence
> intra-process ; le PID couvre la concurrence multi-process.

> **Pourquoi `fsync` ?** Sans `fsync`, un crash kernel post-`rename` mais
> pré-flush peut exposer un fichier vide aux lecteurs. `fsync` force la
> persistance disque avant que `rename(2)` rende le fichier visible.

## Tests

| Test | Cible |
|------|-------|
| `tests/Unit/Support/AtomicFileWriterTest.php` | API publique, cleanup tmp, mode chmod |
| `tests/Feature/Support/AtomicFileWriterConcurrencyTest.php` | Producer/reader fork, invariant : aucune lecture partielle |
| `tests/Architecture/WpkgDeploymentNamespaceTest.php` | Garde-fou Eloquent first (pas de LdapRecord ni App\Services\Ad) |
| `tests/Feature/Migrations/WpkgDeploymentMigrationsTest.php` | Création + rollback + ré-application des deux tables |
| `tests/Feature/Logging/WpkgDeployLogChannelTest.php` | Channel `wpkg-deploy` vivant, contexte `deployment_id` propagé |

> Migration future : le test architecture ad-hoc sera porté vers ArchTest /
> PHPStan rule lorsqu'un de ces outils sera installé (ticket tooling séparé,
> hors scope 15.1).
