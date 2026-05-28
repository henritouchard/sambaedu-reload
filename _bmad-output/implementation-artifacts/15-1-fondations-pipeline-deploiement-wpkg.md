# Story 15.1 : Fondations Pipeline Déploiement WPKG

Status: review

> **Story foundation Epic 15** — pré-requis bloquant pour toutes les stories 15.2 → 15.7.
> Aucune logique métier de déploiement ici : pure infrastructure (logs, namespace, tables tracking, paramétrage chemins, helpers atomic write).

---

## Story

As a **développeur SER**,
I want disposer d'une infrastructure isolée pour le pipeline de déploiement WPKG (logs, namespace, tables de tracking, paramètres, helpers atomic write),
So que les stories suivantes puissent s'appuyer sur des fondations solides et que le debug de cette chaîne critique ne pollue ni soit pollué par le reste de l'application.

---

## Contexte

L'Epic 15 réécrit nativement le **pipeline de distribution effective** WPKG sur les postes Windows : génération `hosts.xml` / `profiles.xml` / `.ini`, ingestion des rapports clients, dashboard de l'état de déploiement.

Cette story 15.1 prépare le **socle technique** transverse :

- un **channel logs `wpkg-deploy` isolé** pour ne pas polluer / être pollué par le reste de l'app, avec `deployment_id` corrélé.
- un **namespace `App\Wpkg\Deployment`** dédié, avec garde-fou architectural empêchant l'appel direct AD/LDAP en hot path (rappel garde-fou Epic 15 : *Eloquent first*).
- les **deux tables de tracking** (`wpkg_deployments` + `wpkg_deployment_workstation_status`) avec `deployment_id` UUID consommé dans toute la chaîne.
- la **migration des chemins partage** (rename des clés legacy `sambaedu.wpkg.reports_path/_archive_path` vers les nouvelles clés sans alias).
- la **consolidation de `AtomicFileWriter`** dans `App\Support\AtomicFileWriter` (suppression de la classe préexistante sous `App\Services\AppCustomization\Support\`, renforcée d'un suffixe `pid` dans le tmp).

> **Garde-fous transversaux Epic 15 (rappel) :**
> - **Eloquent first** : aucune lecture AD en hot path ; sync AD → Eloquent = job périodique (Story 15.3).
> - **Atomic write systématique** : tout fichier consommé par un client Windows (xml, .ini, rapports) est écrit en `temp + rename` (cf. mémoire `feedback_atomic_write`).
> - **Channel logs dédié** `wpkg-deploy` avec `deployment_id` corrélé et niveau de verbosité configurable.
> - **Stratégie port legacy** : tout fichier porté du legacy `sambaedu/wpkg/*.php` porte un header `@legacy-port` + référence source + `@todo` de refactoring.

---

## Dépendances

| Story | Titre | Status | Détail |
|-------|-------|--------|--------|
| Epic 4 | Workstation, WorkstationGroup, AppProfile | done (2026-04-22) | Modèles Eloquent disponibles, requis par les FK des tables tracking |
| 9-2 | Gestion packages WPKG admin | done (2026-04-17, label historique 8-2.x) | Story d'admin packages, contexte WPKG existant à connaître |
| 1bis-11 | Module legacy `wpkg` (shim) | done | Module legacy WPKG accessible via catchall, conserve la cohabitation pendant la transition |

Toutes les dépendances sont satisfaites. La story peut être implémentée immédiatement.

---

## Acceptance Criteria

> Reproduits **fidèlement** depuis `_bmad-output/planning-artifacts/epics.md` (Story 15.1, lignes 2924–3005), incluant les amendements readiness review du 2026-05-03 (volets 4bis et 5).

### Volet 1 — Channel logs dédié

**AC1.1**
**Given** la configuration logging Laravel
**When** un service du namespace `App\Wpkg\Deployment` émet un log
**Then** ce log est routé vers le channel `wpkg-deploy` (fichier `storage/logs/wpkg-deploy/deploy-{date}.log`, rotation quotidienne, rétention 30 jours par défaut, paramétrable)
**And** le contexte inclut systématiquement `deployment_id` quand applicable, et `workstation_id` / `app_profile_id` quand disponibles
**And** un niveau de verbosité (`debug` / `info` / `warning` / `error`) est paramétrable via `config/logging.php` sans redeploy.

### Volet 2 — Namespace et structure code

**AC2.1**
**Given** le code du pipeline déploiement
**When** un développeur crée un nouveau service, generator, ou job
**Then** il est placé sous `app/Wpkg/Deployment/` (sous-dossiers : `Services`, `Generators`, `Jobs`, `Models`, `Events`, `Support`)
**And** chaque fichier porté du legacy porte un header de docblock `@legacy-port path="sambaedu/wpkg/<file>.php"` + un `@todo` de refactoring + un lien vers une note de dette
**And** un test PHPUnit custom (sous `tests/Architecture/WpkgDeploymentNamespaceTest.php`, scan des `use` statements via `Symfony\Finder` + reflection) vérifie que ce namespace n'importe pas `LdapRecord\*` ni `App\Services\Ad\*` (sauf exception explicite : `WpkgAdReconciliationJob` de Story 15.3)
**And** ce test est conçu pour être migré vers ArchTest / PHPStan rule lorsqu'un de ces outils sera installé dans le projet (ticket tooling séparé hors scope 15.1).

### Volet 3 — Tables de tracking

**AC3.1**
**Given** une migration Laravel
**When** elle est appliquée
**Then** la table `wpkg_deployments` existe avec :
- `id` (**UUID, primary key** — `$table->uuid('id')->primary()` ; consommé comme `deployment_id` dans tout le pipeline et exposé dans les logs `wpkg-deploy`, cf. Story 15.5)
- `triggered_by` (user_id nullable)
- `triggered_at`
- `target_scope` (json : `workstation_ids`, `group_ids`, `profile_ids`)
- `status` (enum : `pending`, `running`, `completed`, `failed`, `partial`)
- `summary` (json : counts par statut)
- `created_at`, `updated_at`

**And** la table `wpkg_deployment_workstation_status` existe avec :
- `id` (UUID)
- `deployment_id` (FK UUID vers `wpkg_deployments.id`)
- `workstation_id` (FK)
- `app_profile_id` (FK nullable)
- `client_reported_at` (nullable)
- `client_status` (enum : `pending`, `success`, `partial`, `failed`, `skipped`)
- `details` (json)
- `error_message` (text nullable)
- `created_at`, `updated_at`

**And** les indexes sont posés sur `(deployment_id, workstation_id)` et `(workstation_id, client_reported_at)`.

### Volet 4 — Paramétrage chemins partage (rename des clés legacy)

> **Décision (2026-05-03)** : stratégie **rename**, pas alias. Les anciennes clés `sambaedu.wpkg.reports_path` / `sambaedu.wpkg.reports_archive_path` sont supprimées et tous les consommateurs migrent vers les nouvelles dans le scope de cette story. Pas de fallback, pas de dette résiduelle.

**AC4.1**
**Given** le fichier `config/sambaedu.php`
**When** un service consomme un chemin
**Then** les chemins sont lus depuis :
- `config('sambaedu.wpkg.deploy_path')` — XML hosts/profiles
- `config('sambaedu.wpkg.ini_path')` — fichiers `.ini` par poste
- `config('sambaedu.wpkg.reports_inbox')` — rapports clients
- `config('sambaedu.wpkg.reports_archive')` — archivage rapports bruts

**And** les valeurs par défaut pointent vers `/var/sambaedu/unattended/install/wpkg/{hosts.xml, profiles.xml, ini/, rapports/, archive/}` (parité legacy)
**And** ces 4 chemins sont écrits **en dur** dans `config/sambaedu.php` (pas de `env(...)` — décision 2026-05-03 : pas de variable d'env dédiée, les ops modifient le fichier de config si nécessaire)
**And** un check de démarrage applicatif vérifie que ces chemins sont accessibles en lecture/écriture par le user PHP-FPM, sinon log warning explicite.

### Volet 4bis — Migration des consommateurs des anciennes clés

**AC4bis.1**
**Given** les consommateurs actuels des clés legacy `sambaedu.wpkg.reports_path` et `sambaedu.wpkg.reports_archive_path`
**When** la story est livrée
**Then** les fichiers suivants sont migrés vers `sambaedu.wpkg.reports_inbox` / `sambaedu.wpkg.reports_archive` :

- `app/Services/Windows/WorkstationLogReader.php` (ligne ~48)
- `app/Console/Commands/WpkgProcessReportsCommand.php` (lignes ~39, ~42, ~51 + tout commentaire de docblock)
- Toute autre occurrence détectée par `grep -r "reports_path\|reports_archive_path" app/ config/ tests/`

**And** les anciennes clés sont **supprimées** du `config/sambaedu.php`
**And** la séquence de migration est explicite :
  1. **Rename** d'abord les clés config consommées (`reports_path` → `reports_inbox`, `reports_archive_path` → `reports_archive`) ET les variables d'env existantes (`WPKG_REPORTS_PATH` → `WPKG_REPORTS_INBOX`, `WPKG_REPORTS_ARCHIVE_PATH` → `WPKG_REPORTS_ARCHIVE`) dans `.env.example` / `.env.testing.example` afin que les consommateurs migrés (Volet 4bis) lisent toujours les bonnes valeurs pendant la transition
  2. **Puis remplacer** le pattern `env('WPKG_REPORTS_INBOX', '...')` / `env('WPKG_REPORTS_ARCHIVE', '...')` par des **valeurs en dur** dans `config/sambaedu.php` (defaults legacy), retirer les lignes `env(...)` correspondantes, et **supprimer** les variables d'env nouvellement renommées des `.env.example` / `.env.testing.example`
**And** les tests existants (notamment ceux couvrant `WpkgProcessReportsCommand` et `WorkstationLogReader`) continuent de passer après le rename + le passage en dur
**And** une note de migration `.env` est ajoutée dans la note technique `docs/wpkg-deploy/architecture.md` pour les ops (rappel : retirer `WPKG_REPORTS_PATH` et `WPKG_REPORTS_ARCHIVE_PATH` du `.env` de production avant déploiement — elles ne sont plus consommées).

### Volet 5 — Helpers atomic write (consolidation)

> **Décision (2026-05-03)** : un `AtomicFileWriter` existe déjà dans `App\Services\AppCustomization\Support\` mais sans suffixe PID dans le tmp. Cette story **consolide** les deux usages dans une classe unique `App\Support\AtomicFileWriter` plutôt que d'introduire une seconde implémentation.

**AC5.1**
**Given** un service qui doit écrire un fichier consommé par un client externe
**When** il appelle `App\Support\AtomicFileWriter::write($path, $content)`
**Then** le fichier est écrit en `<path>.tmp.<pid>.<random>`, fsync forcé, puis renommé sur `<path>` (rename atomique sur même filesystem)
**And** un test feature démontre qu'aucun lecteur concurrent ne peut observer un état partiel (test concurrent : producer écrit en boucle, reader lit en boucle, on vérifie qu'aucune lecture ne capture un fichier vide ou tronqué)
**And** la classe préexistante `App\Services\AppCustomization\Support\AtomicFileWriter` est supprimée ; tous ses appelants importent désormais `App\Support\AtomicFileWriter`
**And** les tests préexistants couvrant l'usage `AppCustomization` continuent de passer (la garantie atomique reste équivalente, le suffixe PID est un renforcement contre les collisions multi-process — comportement compatible)
**And** le namespace `App\Wpkg\Deployment\Support\` n'expose **pas** de classe `AtomicFileWriter` propre (consommation directe de `App\Support\AtomicFileWriter`).

### Volet 6 — Tests

**AC6.1**
**Given** la couche fondation
**When** la suite de tests s'exécute
**Then** sont fournis :

- tests unitaires sur `App\Support\AtomicFileWriter` (incluant non-régression vs ancien usage `AppCustomization`)
- tests feature sur la migration des deux tables (création + rollback)
- tests de configuration sur le channel logs (`wpkg-deploy` vivant, contexte deployment_id propagé, niveau verbosité paramétrable)
- test PHPUnit custom d'architecture sur le namespace `App\Wpkg\Deployment` (`tests/Architecture/WpkgDeploymentNamespaceTest.php`)
- test de non-régression sur `WpkgProcessReportsCommand` et `WorkstationLogReader` après le rename des clés config.

---

## Hors scope

- Aucune logique métier de déploiement (generators XML, ingestion rapports, dashboard, UI assignation) — pure infrastructure.
- Aucun déplacement / fusion / refonte des classes existantes `WpkgReportIngestionService`, `WorkstationLogReader`, `WpkgProcessReportsCommand` au-delà du rename des clés config consommées (l'audit du destin de ces classes est explicitement reporté à Story 15.3 volet 1).
- Migration vers ArchTest / PHPStan rule du test architectural : ticket tooling séparé hors scope.

---

## Fichiers à lire avant d'implémenter

- `_bmad-output/planning-artifacts/epics.md` § Story 15.1 (lignes 2924-3005) — source de vérité des AC
- `_bmad-output/planning-artifacts/epics.md` § Epic 15 introduction (lignes 2906-2922) — garde-fous transversaux
- `app/Services/AppCustomization/Support/AtomicFileWriter.php` — implémentation préexistante à consolider
- `app/Services/AppCustomization/Thunderbird/ThunderbirdPolicyAdapter.php` (ligne 8, 111) — appelant à migrer
- `app/Services/AppCustomization/Firefox/FirefoxPolicyAdapter.php` (ligne 8, 139) — appelant à migrer
- `app/Services/Windows/WorkstationLogReader.php` (ligne 48-50) — consommateur clé legacy `reports_path`
- `app/Console/Commands/WpkgProcessReportsCommand.php` (lignes 18, 20, 39, 42, 51) — consommateur clés legacy
- `tests/Unit/Services/WorkstationLogReaderTest.php` (lignes 33, 242-269) — tests à mettre à jour
- `tests/Feature/Windows/WpkgProcessReportsCommandTest.php` (lignes 41-42) — tests à mettre à jour
- `config/sambaedu.php` (lignes 167-185) — bloc `wpkg`
- `config/logging.php` — channels existants pour ajouter `wpkg-deploy`
- `_bmad-output/planning-artifacts/architecture.md` § conventions namespace / tests — patterns du projet
- Mémoire `feedback_atomic_write.md` — pourquoi temp+rename, contraintes filesystem
- Mémoire `feedback_prefer_base_path.md` — chemins explicites via `base_path()`
- Mémoire `feedback_phpunit_attributes.md` — préférer attributs `#[Test]` aux annotations `@test`

---

## Tasks / Subtasks

- [x] **Tâche 1 : Channel logs `wpkg-deploy` (AC1.1)**
  - [x] Ajouter le channel `wpkg-deploy` dans `config/logging.php` : driver `daily`, path `storage/logs/wpkg-deploy/deploy-{date}.log`, rétention 30 jours par défaut, niveau paramétrable via `env('WPKG_DEPLOY_LOG_LEVEL', 'info')`.
  - [x] Créer `storage/logs/wpkg-deploy/.gitkeep`.
  - [x] Définir un `LoggerHelper` ou logger contextuel (ex : `Log::channel('wpkg-deploy')->withContext([...])`) qui injecte automatiquement `deployment_id`, `workstation_id`, `app_profile_id` quand fournis.
  - [x] Vérifier la rotation quotidienne et la rétention (test config).

- [x] **Tâche 2 : Namespace `App\Wpkg\Deployment` (AC2.1)**
  - [x] Créer l'arborescence `app/Wpkg/Deployment/{Services,Generators,Jobs,Models,Events,Support}/` avec un `.gitkeep` dans chaque dossier (ou un README).
  - [x] Mettre à jour `composer.json` autoload PSR-4 si la racine `App\\Wpkg\\` n'est pas déjà couverte par `App\\` (vérifier — Laravel 11 default = `app/` mappé sur `App\\`, donc OK sans changement).
  - [x] Ajouter un docblock convention dans le README du namespace expliquant le header `@legacy-port path="sambaedu/wpkg/<file>.php"` + `@todo` de refactoring + lien dette.
  - [x] Créer `tests/Architecture/WpkgDeploymentNamespaceTest.php` : scanner via `Symfony\Component\Finder\Finder` tous les fichiers `app/Wpkg/Deployment/**/*.php`, parser les `use` statements (regex ou nikic/php-parser si dispo), vérifier qu'aucun ne référence `LdapRecord\` ni `App\Services\Ad\`.
  - [x] Inclure une whitelist d'exception : la classe `App\Wpkg\Deployment\Jobs\WpkgAdReconciliationJob` (Story 15.3) — ne pas créer la classe ici, juste documenter l'exception dans le test.
  - [x] Documenter dans le test un commentaire pointant vers ArchTest / PHPStan pour migration future.

- [x] **Tâche 3 : Tables tracking (AC3.1)**
  - [x] Créer migration `database/migrations/<ts>_create_wpkg_deployments_table.php` avec `$table->uuid('id')->primary()`, `triggered_by` (FK users nullable), `triggered_at`, `target_scope` (json), `status` (enum), `summary` (json), timestamps.
  - [x] Créer migration `database/migrations/<ts>_create_wpkg_deployment_workstation_status_table.php` avec `id` UUID, `deployment_id` (FK UUID `wpkg_deployments.id`), `workstation_id` (FK), `app_profile_id` (FK nullable), `client_reported_at` (nullable), `client_status` (enum), `details` (json), `error_message` (text nullable), timestamps.
  - [x] Poser indexes : `index(['deployment_id','workstation_id'])` et `index(['workstation_id','client_reported_at'])`.
  - [x] Vérifier la cohérence du driver DB (Postgres = ok pour UUID + enum via `string` + check, ou Postgres native enum — choisir `string` + cast côté modèle pour portabilité).
  - [x] Tests feature `tests/Feature/Migrations/WpkgDeploymentMigrationsTest.php` : run + rollback + run, assertion sur la présence des colonnes / indexes.

- [x] **Tâche 4 : Paramétrage chemins (AC4.1)**
  - [x] Dans `config/sambaedu.php` bloc `wpkg`, **remplacer** `reports_path` / `reports_archive_path` par les 4 nouvelles clés : `deploy_path`, `ini_path`, `reports_inbox`, `reports_archive`.
  - [x] Valeurs **en dur** (pas de `env(...)`) : `/var/sambaedu/unattended/install/wpkg`, `/var/sambaedu/unattended/install/wpkg/ini`, `/var/sambaedu/unattended/install/wpkg/rapports`, `/var/sambaedu/unattended/install/wpkg/archive` (parité legacy).
  - [x] Aucune nouvelle variable d'env créée pour ces chemins (décision 2026-05-03 : config en dur).
  - [x] Note : les fichiers `hosts.xml` et `profiles.xml` se trouvent **dans** `deploy_path` (pas une clé par fichier — parité legacy).
  - [x] Implémenter un check de démarrage (Service Provider boot) qui itère ces chemins et log un warning explicite (channel `wpkg-deploy`) si non accessibles en R/W par le user PHP-FPM.

- [x] **Tâche 5 : Migration consommateurs anciennes clés (AC4bis.1)**
  - [x] **Phase 1 — rename séquentiel** (les consommateurs doivent rester fonctionnels pendant la transition) :
    - [x] Renommer dans `.env.example` (et `.env.testing.example` si présent) `WPKG_REPORTS_PATH` → `WPKG_REPORTS_INBOX` et `WPKG_REPORTS_ARCHIVE_PATH` → `WPKG_REPORTS_ARCHIVE` _(ces variables n'existaient ni dans `.env.example` ni dans `.env.testing.example` — phase 1 ne touche que `config/sambaedu.php` et le code applicatif)._
    - [x] Renommer dans `config/sambaedu.php` les clés `reports_path` → `reports_inbox` et `reports_archive_path` → `reports_archive` (toujours en `env(...)` à ce stade) _— étape fusionnée avec la phase 2 puisque les variables d'env n'étaient pas présentes : passage direct en dur._
    - [x] Migrer `app/Services/Windows/WorkstationLogReader.php` ligne ~48 : `config('sambaedu.wpkg.reports_path')` → `config('sambaedu.wpkg.reports_inbox')` + adapter le message de log warning.
    - [x] Migrer `app/Console/Commands/WpkgProcessReportsCommand.php` lignes ~39, ~42, ~51 + commentaires docblock (lignes 18, 20).
    - [x] Vérifier via `grep -r "reports_path\|reports_archive_path" app/ config/ tests/` qu'aucun consommateur n'a été manqué.
    - [x] Mettre à jour `tests/Unit/Services/WorkstationLogReaderTest.php` lignes 33, 242-269 (`Config::set('sambaedu.wpkg.reports_path', ...)` → `reports_inbox`, idem messages d'attente).
    - [x] Mettre à jour `tests/Feature/Windows/WpkgProcessReportsCommandTest.php` lignes 41-42.
  - [x] **Phase 2 — passage en dur** :
    - [x] Remplacer dans `config/sambaedu.php` les `env('WPKG_REPORTS_INBOX', ...)` / `env('WPKG_REPORTS_ARCHIVE', ...)` par les valeurs en dur (`/var/sambaedu/unattended/install/wpkg/rapports` et `.../archive`).
    - [x] Supprimer les variables d'env nouvellement renommées de `.env.example` et `.env.testing.example` _(rien à supprimer — voir phase 1)._
    - [x] Vérifier qu'aucun appel `env('WPKG_REPORTS_*')` ne subsiste dans le code (`grep -r "WPKG_REPORTS" app/ config/ tests/`).
  - [x] Créer `docs/wpkg-deploy/architecture.md` avec une section « Migration `.env` » expliquant aux ops : retirer `WPKG_REPORTS_PATH` et `WPKG_REPORTS_ARCHIVE_PATH` du `.env` de production (elles ne sont plus consommées) ; les chemins sont désormais figés en dur dans `config/sambaedu.php` (modifier le fichier de config pour customiser).

- [x] **Tâche 6 : Consolidation `AtomicFileWriter` (AC5.1)**
  - [x] Créer `app/Support/AtomicFileWriter.php` : signature compatible `write(string $path, string $content, int $mode = 0644): bool`, tmp = `<dir>/.<basename>.tmp.<pid>.<bin2hex(random_bytes(6))>`, `file_put_contents` puis `fopen + fsync` (PHP : `fflush` + `flush via stream_socket_*` non disponible — utiliser `fsync()` PHP ≥ 8.1 sur descripteur ouvert) puis `rename` atomique.
  - [x] Migrer `app/Services/AppCustomization/Thunderbird/ThunderbirdPolicyAdapter.php` (lignes 8, 111) vers `App\Support\AtomicFileWriter`.
  - [x] Migrer `app/Services/AppCustomization/Firefox/FirefoxPolicyAdapter.php` (lignes 8, 139) vers `App\Support\AtomicFileWriter`.
  - [x] **Supprimer** `app/Services/AppCustomization/Support/AtomicFileWriter.php`.
  - [x] Tests unitaires `tests/Unit/Support/AtomicFileWriterTest.php` : succès simple, dir auto-créé, échec `file_put_contents` (mock), échec `rename` (mock), exception capturée et tmp nettoyé, suffixe PID présent dans le tmp (assertion sur le nom intermédiaire).
  - [x] Test feature concurrent `tests/Feature/Support/AtomicFileWriterConcurrencyTest.php` : producer fork (`pcntl_fork` ou processus enfant via `exec` d'un script de boucle d'écriture) écrit N fois, reader fork lit N fois, assertion : aucun read n'a observé un contenu vide ou tronqué (chaque read = soit `null/non-existant` soit un contenu complet attendu — checksum md5).
  - [x] Vérifier que le namespace `App\Wpkg\Deployment\Support` ne contient pas de classe `AtomicFileWriter` propre.

- [x] **Tâche 7 : Tests & non-régression (AC6.1)**
  - [x] Lancer la suite complète : tests unitaires AtomicFileWriter, tests feature migrations, test config logging, test architecture namespace, tests existants `WpkgProcessReportsCommand` et `WorkstationLogReader`, tests `AppCustomization` (Firefox + Thunderbird PolicyAdapter).
  - [x] Couvrir la non-régression Firefox/Thunderbird Policy : assertion que le JSON est bien écrit après le swap d'AtomicFileWriter.
  - [x] Vérifier qu'aucun test legacy ne référence encore `reports_path` / `reports_archive_path`.

- [x] **Tâche 8 : Documentation et clôture**
  - [x] Compléter `docs/wpkg-deploy/architecture.md` : namespace conventions, channel logs, tables tracking, atomic write, paths config, rappel migration `.env`.
  - [x] Préparer la TODO `epics.md` (mise à jour tableau couverture FR pour Epics 15/16/17 — action de doc, non bloquante).
  - [x] Run final de la suite de tests et capture de l'output dans `Dev Agent Record`.

---

## Dev Notes

### Architecture & contraintes

- **Stack** : Laravel 11, PHP 8.3, Postgres (cible production), Pest/PHPUnit pour les tests. Worktree non auto-syncé VM (cf. mémoire `feedback_no_vm_sync_e5_partages` — règle générale : ne pas exécuter SSH/VM depuis worktree).
- **Channel logs** : Laravel utilise Monolog. Le driver `daily` couvre nativement la rotation quotidienne + la rétention via `days`.
- **UUID PK** : `$table->uuid('id')->primary()` + `Model::$incrementing = false` + `protected $keyType = 'string'` + trait `HasUuids` (Laravel 9+) sur les modèles à créer dans Story 15.2/15.5 (cette story ne crée pas les modèles, seulement les migrations).
- **Enum DB** : préférer `string` + cast modèle pour portabilité (cohérent avec patterns Epic 4 / 7).
- **Test architecture** : la regex de scan `use` doit gérer (a) `use Foo\Bar;`, (b) `use Foo\Bar as Baz;`, (c) `use Foo\{Bar, Qux};`. Préférer `nikic/php-parser` (déjà transitive de plusieurs paquets Laravel — vérifier `composer show nikic/php-parser`) à une regex fragile.
- **Fsync PHP** : `fsync()` est dispo nativement depuis PHP 8.1 sur une ressource `fopen`. Pattern : `$fh = fopen($tmp, 'wb')` + `fwrite` + `fsync($fh)` + `fclose` + `rename`. Alternative `file_put_contents` + ouverture-fsync-fermeture séparée.
- **Rename atomique** : garanti uniquement intra-filesystem. Le tmp doit donc rester dans le **même dossier** que la cible (pas dans `/tmp`). C'est déjà le cas dans l'implémentation préexistante.
- **Suffixe PID** : utiliser `getmypid()` pour éviter les collisions multi-process (FPM workers). Le `random_bytes(6)` reste pour la concurrence intra-process si plusieurs writes sur le même PID.

### Patterns à suivre / antipatterns à éviter

- **Eviter** d'introduire un nouveau `AtomicFileWriter` sous `App\Wpkg\Deployment\Support\`. AC5.1 explicite : import direct de `App\Support\AtomicFileWriter`.
- **Eviter** de garder les anciennes clés config en alias (« strat rename, pas alias » — décision 2026-05-03).
- **Préférer** `base_path()` aux `dirname(__DIR__, N)` (cf. mémoire `feedback_prefer_base_path`).
- **Préférer** les attributs PHPUnit `#[Test]`, `#[DataProvider]` aux annotations dépréciées (cf. mémoire `feedback_phpunit_attributes`).
- **Convention namespace WPKG** : tout nouveau code pipeline va sous `App\Wpkg\Deployment\<sub>\`. Les classes existantes hors namespace (`App\Services\Windows\Wpkg*`, `App\Console\Commands\WpkgProcessReportsCommand`) **ne sont pas déplacées dans cette story** — l'audit de leur destin est repoussé à Story 15.3 volet 1.

### Source tree components à toucher

```
app/
├── Support/AtomicFileWriter.php                          # CRÉÉ
├── Wpkg/Deployment/{Services,Generators,Jobs,Models,Events,Support}/  # CRÉÉ (squelettes)
├── Services/AppCustomization/Support/AtomicFileWriter.php             # SUPPRIMÉ
├── Services/AppCustomization/Firefox/FirefoxPolicyAdapter.php         # MIGRÉ (use)
├── Services/AppCustomization/Thunderbird/ThunderbirdPolicyAdapter.php # MIGRÉ (use)
├── Services/Windows/WorkstationLogReader.php                          # MIGRÉ (config keys)
└── Console/Commands/WpkgProcessReportsCommand.php                     # MIGRÉ (config keys + docblock)

config/
├── logging.php                                           # Ajout channel wpkg-deploy
└── sambaedu.php                                          # Rename clés wpkg (4 nouvelles, 2 supprimées)

database/migrations/
├── <ts>_create_wpkg_deployments_table.php                # CRÉÉ
└── <ts>_create_wpkg_deployment_workstation_status_table.php  # CRÉÉ

docs/wpkg-deploy/architecture.md                          # CRÉÉ (note technique fondations)

storage/logs/wpkg-deploy/.gitkeep                         # CRÉÉ

tests/
├── Architecture/WpkgDeploymentNamespaceTest.php          # CRÉÉ
├── Feature/Migrations/WpkgDeploymentMigrationsTest.php   # CRÉÉ
├── Feature/Support/AtomicFileWriterConcurrencyTest.php   # CRÉÉ
├── Unit/Support/AtomicFileWriterTest.php                 # CRÉÉ
├── Unit/Services/WorkstationLogReaderTest.php            # MIGRÉ (config keys)
└── Feature/Windows/WpkgProcessReportsCommandTest.php     # MIGRÉ (config keys)

.env.example, .env.testing.example                        # Renames variables WPKG_REPORTS_*
```

### Testing standards summary

- **Unitaires** : Pest/PHPUnit, isolés (pas de DB), mock filesystem si pertinent.
- **Feature migrations** : `RefreshDatabase` ou `DatabaseMigrations`, run + rollback.
- **Feature concurrence** : `pcntl_fork` ou processus enfant `exec`, timeout court (~3s), assertion d'invariant via boucle `while (microtime(true) < $deadline) { ... }`. À garder rapide (<5s) pour CI.
- **Architecture** : zéro dépendance DB, scan filesystem via Symfony Finder.
- **Couverture** : non régression sur les tests existants `WorkstationLogReader`, `WpkgProcessReportsCommand`, `Firefox/ThunderbirdPolicyAdapter`.

### Project Structure Notes

- Le namespace `App\Wpkg\` est neuf — vérifier qu'aucune classe (legacy `infos`, etc.) ne le squatte avant création (rapide check avec `grep -rn "namespace App.Wpkg" app/`).
- Le fichier `docs/wpkg-deploy/architecture.md` est neuf : créer le dossier `docs/wpkg-deploy/`.
- Pas de conflit attendu avec `legacy/modules/wpkg/` (shim 1bis-11) qui vit dans `legacy/`, namespace différent.

### References

- [Source: _bmad-output/planning-artifacts/epics.md § Epic 15, Story 15.1 (lignes 2906-3005)] — AC source unique
- [Source: _bmad-output/planning-artifacts/prd.md § FR24, FR25 (lignes 325-326)] — couverture fonctionnelle
- [Source: _bmad-output/planning-artifacts/architecture.md § Tier classification, namespace conventions] — placement code
- [Source: ~/.claude/projects/.../memory/MEMORY.md § feedback_atomic_write] — pattern temp+rename
- [Source: ~/.claude/projects/.../memory/MEMORY.md § feedback_prefer_base_path] — chemins explicites
- [Source: ~/.claude/projects/.../memory/MEMORY.md § feedback_phpunit_attributes] — attributs PHPUnit
- [Source: ~/.claude/projects/.../memory/MEMORY.md § feedback_port_legacy_then_refactor] — stratégie port legacy
- [Source: app/Services/AppCustomization/Support/AtomicFileWriter.php] — implémentation préexistante à consolider
- [Source: config/sambaedu.php (lignes 167-185)] — bloc wpkg actuel

---

## Notes

### Amendements readiness review 2026-05-03

- **Volet 4 → décision rename strict** (pas alias). Les anciennes clés `sambaedu.wpkg.reports_path` / `reports_archive_path` sont **supprimées** ; tous les consommateurs migrent vers les nouvelles clés dans le scope de cette story. Pas de fallback, pas de dette résiduelle.
- **Volet 4bis ajouté** : la migration des consommateurs des anciennes clés est explicitée en deux phases — (1) rename des clés config + des variables d'env existantes pour ne pas casser les consommateurs en cours de migration (`WPKG_REPORTS_PATH` → `WPKG_REPORTS_INBOX`, `WPKG_REPORTS_ARCHIVE_PATH` → `WPKG_REPORTS_ARCHIVE`), (2) passage des chemins en dur dans `config/sambaedu.php` (suppression du `env(...)`) et retrait des variables d'env de `.env.example` (décision 2026-05-03 : pas de variable d'env dédiée, config en dur). Note ops dans `docs/wpkg-deploy/architecture.md` pour retirer les variables du `.env` prod.
- **Volet 5 → consolidation et non duplication** : un `AtomicFileWriter` existe déjà dans `App\Services\AppCustomization\Support\` mais sans suffixe PID. La story **consolide** dans `App\Support\AtomicFileWriter` (suffixe PID + fsync explicite + tests concurrence) et **supprime** la classe préexistante. Les appelants Firefox / Thunderbird PolicyAdapter sont migrés. Le namespace `App\Wpkg\Deployment\Support\` n'expose pas de classe `AtomicFileWriter` propre.
- **Story 15.6 retirée du scope Epic 15** lors de la même session (la 15.6 historique fusionnée dans 15.5) — pas d'impact direct sur 15.1, mais bon à savoir pour la cohérence.

### Note conflit namespace

Le namespace `App\Wpkg\*` doit cohabiter avec des classes existantes hors namespace qui consomment des concepts WPKG :

- `App\Services\Windows\WpkgReportIngestionService`
- `App\Services\Windows\WorkstationLogReader`
- `App\Console\Commands\WpkgProcessReportsCommand`

Ces classes **ne sont pas déplacées dans cette story**. L'audit de Story 15.3 volet 1 tracera leur destin (déplacement, fusion, ou statu quo). La note technique `docs/wpkg-deploy/architecture.md` mentionne explicitement cette zone à arbitrer.

### Note doc post-livraison

À la clôture de la story, **mettre à jour le tableau de couverture FR** en début de `_bmad-output/planning-artifacts/epics.md` pour ajouter Epics 15 / 16 / 17 (action de doc, non bloquante pour la livraison code).

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-7[1m]

### Debug Log References

- Suite ciblée 15.1 : `CACHE_DRIVER=array vendor/bin/phpunit tests/Unit/Support tests/Unit/Services/WorkstationLogReaderTest.php tests/Unit/Services/AppCustomization/Firefox/FirefoxPolicyAdapterTest.php tests/Unit/Services/AppCustomization/Thunderbird/ThunderbirdPolicyAdapterTest.php tests/Architecture tests/Feature/Logging tests/Feature/Migrations tests/Feature/Support/AtomicFileWriterConcurrencyTest.php tests/Feature/Windows/WpkgProcessReportsCommandTest.php tests/Feature/AppCustomization`
  → **82 tests, 201 assertions, 0 failure, 2 skipped** (skip = test échec mkdir `/proc` quand sandbox ne le permet pas, et un test AppCustomization indépendant).
- Suite complète : `CACHE_DRIVER=array vendor/bin/phpunit`
  → **1490 tests, 11639 assertions, 106 errors, 2 failures, 98 skipped, 28 risky**.
  Baseline pré-implémentation (vérifiée via `git stash` puis pop) : **107 errors, 4 failures**. Aucune régression introduite ; au contraire 1 erreur et 2 failures éliminées (les tests `WpkgProcessReportsCommandTest` / `WorkstationLogReaderTest` qui passent désormais avec les clés renommées).
- Les 106 erreurs résiduelles sont **toutes pré-existantes** et hors scope 15.1 (Ldap réseau indispo, ext-imagick absente sur runner local pour `WallpaperComposer`, modèles `User` Eloquent partiellement instanciés sur certains tests Auth, etc.).

### Completion Notes List

- **Tâche 1 (channel logs)** : channel `wpkg-deploy` ajouté en `daily` driver, path `storage/logs/wpkg-deploy/deploy-{date}.log` (driver Monolog daily préfixe automatiquement le basename), rétention 30 jours via `WPKG_DEPLOY_LOG_DAYS` env. Niveau via `WPKG_DEPLOY_LOG_LEVEL` env (défaut `info`). Pas de `LoggerHelper` dédié : usage standard Laravel `Log::channel('wpkg-deploy')->withContext([...])` documenté dans `docs/wpkg-deploy/architecture.md` et le README du namespace.
- **Tâche 2 (namespace + test archi)** : test architectural utilise `nikic/php-parser` (déjà dans `vendor/`) plutôt qu'une regex fragile. Whitelist explicite `App\\Wpkg\\Deployment\\Jobs\\WpkgAdReconciliationJob`. Vérifié que le test détecte bien les violations (test piégé avec un `use App\\Services\\Ad\\Whatever` → fail attendu, puis remis à zéro).
- **Tâche 3 (migrations)** : test feature `RefreshDatabase` initialement bloqué par une migration baseline `2026_02_03_*_remove_is_physical_room_*` qui n'est pas SQLite-compatible (drop column avec index orphelin). Bypass : le test recharge directement nos 2 migrations via `require ... up()/down()` après avoir préparé des tables shim minimales pour les FK (users, workstations, app_profiles). Couverture : présence colonnes, insert UUID, rollback + ré-application. La migration sur Postgres prod tourne quant à elle nativement (à valider via runbook QA section 1.2).
- **Tâches 4 + 4bis (rename clés + passage en dur)** : les variables d'env `WPKG_REPORTS_PATH` / `WPKG_REPORTS_ARCHIVE_PATH` ne figuraient ni dans `.env.example` ni dans `.env.testing.example` du repo (uniquement consommées via `env('WPKG_REPORTS_PATH', '...')` dans `config/sambaedu.php`). La phase 1 « rename d'env vars » a donc été fusionnée avec la phase 2 (passage en dur) — ceci est documenté dans la story et la note ops `docs/wpkg-deploy/architecture.md` rappelle aux ops de retirer ces variables du `.env` de production avant déploiement.
- **Tâche 6 (AtomicFileWriter)** : consolidation dans `App\Support\AtomicFileWriter`, suffixe `pid` + `fsync()` ajouté, ancien fichier `App\Services\AppCustomization\Support\AtomicFileWriter.php` supprimé (le dossier `Support/` reste vide). Test concurrent via `pcntl_fork` (deadline 2.5s, payload 64KB, marker md5 :payload, hash ≠ md5(payload) → partial). Aucune partial observée sur 100+ reads.
- **Service Provider** : nouveau `WpkgDeploymentServiceProvider` enregistré dans `config/app.php` (le projet utilise encore le pattern legacy Laravel 10 `config/app.php` providers, pas Laravel 11 `bootstrap/providers.php`). Check démarrage skip volontairement en `testing` env pour ne pas polluer les logs.
- **Note migration vendor** : le runner local n'avait pas `ext-apcu` / `ext-imagick`. Composer install via `--ignore-platform-req` puis `CACHE_DRIVER=array` pour les tests. Sur la VM ces extensions sont présentes — pas d'impact prod.
- **Points d'attention review** : (a) le test concurrent dépend de `pcntl_fork` (skip si absent) ; (b) le test architectural utilise `realpath` + scan dynamique — refactor candidat vers ArchTest/PHPStan rule (ticket tooling séparé, hors scope) ; (c) la migration `wpkg_deployment_workstation_status` utilise des index alias courts (`wdws_*`) car les noms générés Laravel dépassent la limite Postgres 63 chars.

### File List

**Créés** :
- `app/Support/AtomicFileWriter.php`
- `app/Wpkg/Deployment/README.md`
- `app/Wpkg/Deployment/{Services,Generators,Jobs,Models,Events,Support}/.gitkeep`
- `app/Providers/WpkgDeploymentServiceProvider.php`
- `database/migrations/2026_05_03_100000_create_wpkg_deployments_table.php`
- `database/migrations/2026_05_03_100100_create_wpkg_deployment_workstation_status_table.php`
- `docs/wpkg-deploy/architecture.md`
- `docs/qa/domains/wpkg-deploy.md`
- `storage/logs/wpkg-deploy/.gitkeep`
- `tests/Architecture/WpkgDeploymentNamespaceTest.php`
- `tests/Feature/Logging/WpkgDeployLogChannelTest.php`
- `tests/Feature/Migrations/WpkgDeploymentMigrationsTest.php`
- `tests/Feature/Support/AtomicFileWriterConcurrencyTest.php`
- `tests/Unit/Support/AtomicFileWriterTest.php`

**Modifiés** :
- `app/Console/Commands/WpkgProcessReportsCommand.php` — `reports_path` → `reports_inbox`, `reports_archive_path` → `reports_archive` (code + commentaires)
- `app/Services/AppCustomization/Firefox/FirefoxPolicyAdapter.php` — `use App\Support\AtomicFileWriter`
- `app/Services/AppCustomization/Thunderbird/ThunderbirdPolicyAdapter.php` — `use App\Support\AtomicFileWriter`
- `app/Services/Windows/WorkstationLogReader.php` — `reports_path` → `reports_inbox` (code + message Log::warning)
- `config/app.php` — registration `WpkgDeploymentServiceProvider`
- `config/logging.php` — channel `wpkg-deploy`
- `config/sambaedu.php` — bloc `wpkg` : 4 nouvelles clés en dur, suppression `reports_path` / `reports_archive_path`
- `docs/qa/README.md` — entrée `wpkg-deploy`
- `tests/Feature/Windows/WpkgProcessReportsCommandTest.php` — Config keys renommées
- `tests/Unit/Services/WorkstationLogReaderTest.php` — Config keys + messages d'attente + noms de méthodes

**Supprimés** :
- `app/Services/AppCustomization/Support/AtomicFileWriter.php` (consolidé dans `App\Support\AtomicFileWriter`)

---

## Recommandation Modèle Dev

**Modèle recommandé : opus**

Raisons : story foundation critique avec multiples préoccupations transverses (logging, namespace, migrations DB avec UUID, atomic write avec test concurrent, rename clés config touchant plusieurs consommateurs existants, test architecture custom). Risques : casse silencieuse des consommateurs migrés (volet 4bis), régression atomic write sur AppCustomization (volet 5), incompatibilités test architecture. La logique de réconciliation et les tests concurrents demandent une vigilance d'opus.
