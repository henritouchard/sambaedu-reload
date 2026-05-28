# Story 1.1: Import des données MySQL vers le schéma PostgreSQL

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'administrateur système,
je veux disposer d'un script de migration fiable pour transférer toutes les données SambaEdu4 depuis MySQL vers le schéma PostgreSQL existant,
afin d'avoir une base de données PostgreSQL propre et cohérente sur laquelle bâtir toutes les fonctionnalités SER.

## Acceptance Criteria

1. **Schéma PostgreSQL en place** — Étant donné que les migrations Laravel sont déjà appliquées, quand le script est lancé, alors il vérifie que les tables cibles existent avant de commencer l'import.

2. **Import des entités legacy** — Quand le script d'import est exécuté, alors les données MySQL (utilisateurs, groupes, postes, groupes de postes, profils applicatifs, applications, associations) sont importées dans les tables PostgreSQL correspondantes.

3. **Respect des contraintes du nouveau schéma** — Les contraintes PostgreSQL (clés étrangères, types, nullable) sont toutes respectées ; toute violation est rapportée explicitement dans le rapport final, jamais ignorée silencieusement.

4. **Reconstruction des relations** — Les relations sont reconstituées selon le modèle de données SER (pas copiées à l'identique depuis MySQL) ; ex. la relation `app_profiles ↔ applications` est reconstruite via la table pivot `app_profile_application`.

5. **Idempotence** — L'import peut être relancé N fois sans créer de doublons ni corrompre les données ; les enregistrements déjà présents sont détectés et sautés (ou mis à jour selon option).

6. **Snapshot Proxmox** — Un snapshot Proxmox est pris avant toute exécution sur la VM de production ; le rollback est réalisable en moins de 5 minutes.

7. **Rapport d'exécution** — Le script produit un rapport structuré indiquant pour chaque entité : nombre importé, nombre sauté (doublon), erreurs avec cause explicite.

8. **Mode dry-run** — L'option `--dry-run` permet d'exécuter le script en lecture seule et de valider les données sans rien écrire en base.

## Tasks / Subtasks

- [ ] **Tâche 1 : Audit du schéma MySQL legacy** (AC: 2, 4)
  - [ ] Connecter la commande artisan à la connexion `mysql` (legacy)
  - [ ] Lister et documenter les tables MySQL pertinentes (users, parcs, postes, applications, etc.)
  - [ ] Identifier les anomalies : valeurs NULL inattendues, orphelins, types incohérents
  - [ ] Produire un mapping MySQL → PostgreSQL pour chaque entité

- [ ] **Tâche 2 : Créer l'Artisan Command** (AC: 7, 8)
  - [ ] Créer `app/Console/Commands/ImportFromMysqlCommand.php`
  - [ ] Signature : `sambaedu:import-from-mysql {--dry-run} {--entity=} {--verbose}`
  - [ ] Respecter le pattern de `MigrateDelegationsCommand.php` (option dry-run, logging, rapport)

- [ ] **Tâche 3 : Créer le service d'import** (AC: 2, 3, 4, 5)
  - [ ] Créer `app/Services/Legacy/MysqlImportService.php`
  - [ ] Implémenter `importUsers()` — mapping legacy users → `users` table
  - [ ] Implémenter `importUserGroups()` — mapping legacy groups → `user_groups` table
  - [ ] Implémenter `importWorkstations()` — mapping postes → `workstations` table
  - [ ] Implémenter `importWorkstationGroups()` — mapping parcs → `workstation_groups` table
  - [ ] Implémenter `importAppProfiles()` — mapping profils WPKG → `app_profiles` table
  - [ ] Implémenter `importApplications()` — mapping apps → `applications` table
  - [ ] Implémenter `importAssociations()` — reconstruire pivots (app_profile_application, etc.)
  - [ ] Implémenter la logique d'idempotence (updateOrCreate / firstOrCreate selon le cas)
  - [ ] Toute violation de contrainte PostgreSQL = exception levée + ajout au rapport d'erreurs

- [ ] **Tâche 4 : Tests unitaires** (AC: 3, 5)
  - [ ] Créer `tests/Unit/Services/Legacy/MysqlImportServiceTest.php`
  - [ ] Test idempotence : double exécution → même nombre d'enregistrements
  - [ ] Test violations explicites : données invalides → erreur dans le rapport, pas d'exception silencieuse
  - [ ] Test dry-run : aucune écriture en base après exécution

- [ ] **Tâche 5 : Validation intégrité post-import** (AC: 3)
  - [ ] Ajouter une méthode `validateIntegrity()` dans le service
  - [ ] Vérifier : aucun user sans user_group valide si la FK est NOT NULL
  - [ ] Vérifier : aucune workstation orpheline (workstation_group_id invalide)
  - [ ] Vérifier : totaux cohérents entre MySQL source et PostgreSQL cible

- [ ] **Tâche 6 : Exécution sécurisée** (AC: 6)
  - [ ] Documenter la procédure de snapshot Proxmox dans le fichier `documentation/`
  - [ ] Tester le dry-run sur la VM de dev
  - [ ] Exécuter l'import complet sur VM de test avec snapshot préalable

## Dev Notes

### Contexte Projet Critique

Le projet est **`sambaedu-reload/`** (PAS `irundoo/`). Tout le code va dans `/home/htouchard/code/irundo/codebase/sambaedu-reload/`.

**Laravel 12** — PHP 8.1+, PostgreSQL comme BD cible, `spatie/laravel-permission ^6.24`, `livewire/livewire ^4.0`.

### Connexions Base de Données

La connexion MySQL legacy existe déjà dans `config/database.php` sous le nom `mysql`. Les variables `.env` à ajouter :

```
DB_MYSQL_LEGACY_HOST=127.0.0.1
DB_MYSQL_LEGACY_PORT=3306
DB_MYSQL_LEGACY_DATABASE=sambaedu
DB_MYSQL_LEGACY_USERNAME=sambaedu
DB_MYSQL_LEGACY_PASSWORD=secret
```

Ajouter une entrée dédiée dans `config/database.php` :

```php
'mysql_legacy' => [
    'driver' => 'mysql',
    'host' => env('DB_MYSQL_LEGACY_HOST', '127.0.0.1'),
    'port' => env('DB_MYSQL_LEGACY_PORT', '3306'),
    'database' => env('DB_MYSQL_LEGACY_DATABASE', 'sambaedu'),
    'username' => env('DB_MYSQL_LEGACY_USERNAME', 'root'),
    'password' => env('DB_MYSQL_LEGACY_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false, // legacy data may be non-strict
],
```

Utiliser `DB::connection('mysql_legacy')->table('...')` pour lire le legacy.

### Patterns à Suivre OBLIGATOIREMENT

**Pattern Artisan Command** — voir `app/Console/Commands/MigrateDelegationsCommand.php` :
- Signature avec préfixe `sambaedu:` → `sambaedu:import-from-mysql`
- Option `--dry-run` obligatoire
- `$this->info()` / `$this->error()` / `$this->warn()` pour les logs
- Retourner `self::SUCCESS` ou `self::FAILURE`

**Pattern Service Legacy** — voir `app/Services/Legacy/LegacyParcBridgeService.php` :
- Namespace `App\Services\Legacy`
- Placer dans `app/Services/Legacy/MysqlImportService.php`
- Utiliser `Illuminate\Support\Facades\DB` pour les requêtes raw MySQL
- Utiliser les Eloquent Models pour les écritures PostgreSQL

**Pattern idempotence** — utiliser `firstOrCreate()` ou `updateOrCreate()` d'Eloquent sur des clés naturelles (ex: `login` pour les users, `nom_poste` pour les workstations).

### Modèles PostgreSQL Existants

Tous les modèles sont dans `app/Models/`. **NE PAS les recréer** :

| Modèle | Table PostgreSQL | Clé naturelle à utiliser |
|--------|----------------|--------------------------|
| `User` | `users` | `login` |
| `UserGroup` | `user_groups` | `name` |
| `Workstation` | `workstations` | `nom_poste` |
| `WorkstationGroup` | `workstation_groups` | `name` |
| `AppProfile` | `app_profiles` | `name` |
| `Application` | `applications` | (à déterminer selon schéma) |

Vérifier les champs exacts via les migrations : `database/migrations/2026_01_30_000000_create_unified_schema.php`.

### Ordre d'Import Obligatoire (dépendances FK)

```
1. user_groups          (pas de dépendances)
2. users                (→ user_groups)
3. workstation_groups   (→ peut avoir parent_id self-reference)
4. workstations         (→ workstation_groups)
5. app_profiles         (→ pas de dépendances directes)
6. applications         (→ pas de dépendances directes)
7. app_profile_application (pivot → app_profiles + applications)
8. Autres pivots et associations
```

### Format du Rapport de Migration

Respecter le format API SER (Architecture doc) :

```php
return [
    'success' => true,
    'message' => 'Import terminé',
    'entities' => [
        'users' => ['imported' => 150, 'skipped' => 5, 'errors' => []],
        'workstations' => ['imported' => 200, 'skipped' => 0, 'errors' => [...]],
    ],
    'violations' => [],
    'dry_run' => false,
];
```

### Commandes Utiles Existantes à NE PAS Dupliquer

- `sambaedu:migrate-delegations` — migre les délégations AD → SQL (pattern de référence)
- `compare:legacy-laravel` — compare comportements legacy vs Laravel (outil de test)
- `ImportExportService.php` — contient déjà de la logique d'import/export, vérifier ce qui peut être réutilisé

### Tests

**Framework** : PHPUnit ^11.0
**Localisation** : `tests/Unit/Services/Legacy/MysqlImportServiceTest.php`

Pour les tests, mocker la connexion `mysql_legacy` avec un faux jeu de données in-memory ou utiliser un fixture SQLite. Ne pas tester contre la vraie base MySQL en CI.

```php
// Exemple pattern de test
public function test_import_is_idempotent(): void
{
    // Premier import
    $report1 = $this->service->importUsers(dryRun: false);
    $count1 = User::count();

    // Deuxième import
    $report2 = $this->service->importUsers(dryRun: false);
    $count2 = User::count();

    $this->assertEquals($count1, $count2);
    $this->assertGreaterThan(0, $report2['entities']['users']['skipped']);
}
```

### Risques et Points d'Attention

| Risque | Mitigation |
|--------|-----------|
| Données MySQL corrompues/invalides | Audit en AC1 + dry-run obligatoire avant production |
| Timeouts sur gros volumes | Utiliser `chunk(500)` pour itérer sur les données |
| Clés naturelles non-uniques en legacy | Détecter en audit, documenter les cas limites |
| Snapshots Proxmox oubliés | Documenter procédure dans `documentation/` |

### Project Structure Notes

- **Commande** : `app/Console/Commands/ImportFromMysqlCommand.php`
- **Service** : `app/Services/Legacy/MysqlImportService.php`
- **Tests** : `tests/Unit/Services/Legacy/MysqlImportServiceTest.php`
- **Documentation** : `documentation/migration-mysql-postgresql.md`
- **Config DB** : `config/database.php` (ajouter entrée `mysql_legacy`)
- **Env** : `.env.example` (ajouter les nouvelles variables `DB_MYSQL_LEGACY_*`)

### References

- Pattern commande artisan : [app/Console/Commands/MigrateDelegationsCommand.php](sambaedu-reload/app/Console/Commands/MigrateDelegationsCommand.php)
- Pattern service legacy : [app/Services/Legacy/LegacyParcBridgeService.php](sambaedu-reload/app/Services/Legacy/LegacyParcBridgeService.php)
- Schéma PostgreSQL cible : [database/migrations/2026_01_30_000000_create_unified_schema.php](sambaedu-reload/database/migrations/2026_01_30_000000_create_unified_schema.php)
- Config base de données : [config/database.php](sambaedu-reload/config/database.php)
- ImportExportService (réutilisable?) : [app/Services/ImportExportService.php](sambaedu-reload/app/Services/ImportExportService.php)
- Source: _bmad-output/planning-artifacts/epics.md#Epic-1-Story-1-1
- Source: _bmad-output/planning-artifacts/architecture.md#Stack-Technique

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
