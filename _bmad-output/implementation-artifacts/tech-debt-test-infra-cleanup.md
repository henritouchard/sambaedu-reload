# Tech Debt : Nettoyage infrastructure tests Feature

Status: backlog
Type: technique (pas de story number BMAD, à intégrer dans le sprint à discrétion)
Créée : 2026-04-14
Origine : révélée par le fix `CasAuthenticationTest` commit `ce3e8c5` (story 9.4)

## Contexte

Jusqu'au commit `ce3e8c5`, `php artisan test` semblait exécuter ~350 tests avec succès. En réalité, le runner PHPUnit mourait silencieusement au 3ème test de `CasAuthenticationTest.php` parce que les attributs `#[RunInSeparateProcess]` étaient présents sans les `use` statements correspondants. Le test `test_init_cas_client_port_zero_is_not_overridden_to_443` appelait `phpCAS::client()` dans le process principal, déclenchant un `exit()` silencieux.

**Conséquence** : tous les tests alphabétiquement après `CasAuthenticationTest` (Console, ControlHub, Shortcuts, Windows, UserCreationTest, UserUpdateTest, LegacyModule*, LegacyMonitor*, etc.) n'étaient jamais exécutés. Les échecs de ces tests étaient masqués depuis au moins le 10 avril 2026.

Après le fix : `php artisan test` exécute bien les 593 tests découverts, mais **116+ échecs pré-existants** sont désormais visibles.

## Catégories d'échecs observés

Échantillon mesuré sur `tests/Feature/Console + ControlHub + Shortcuts` (120 tests, 116 échecs) :

### 1. Schema de la base `sambaedu_test` non réinitialisé entre suites

```
QueryException  SQLSTATE[2BP01]: Dependent objects still exist
ERROR: cannot drop table shortcuts because other objects depend on it
```

**Cause probable** : certains tests utilisent `RefreshDatabase` qui tente de DROP toutes les tables, mais les FK d'autres tables bloquent. Ordonnancement + CASCADE à revoir.

### 2. Factory manquante sur le modèle `User`

```
BadMethodCallException : Call to undefined method App\Models\User::factory()
```

**Cause** : `App\Models\User` n'utilise pas le trait `HasFactory`, ou la classe `UserFactory` n'existe pas. À vérifier dans `database/factories/`.

### 3. phpCAS exit() dans subprocess

```
AssertionFailedError : Test was run in child process and ended unexpectedly
```

**Cause** : même avec `#[RunInSeparateProcess]`, `phpCAS::client()` appelle `exit()` → le subprocess termine avant d'avoir exécuté les assertions. Il faut soit mocker `phpCAS`, soit restructurer les tests pour ne pas l'invoquer directement.

### 4. Bug code pré-existant `controlhub_id`

```
ErrorException : Undefined array key "controlhub_id"
```

**Cause** : bug fonctionnel dans le code production (service ou controller) qui attend cette clé. À localiser via la stack trace et corriger.

### 5. Pool PostgreSQL saturé

```
PDOException : SQLSTATE[08006] FATAL: sorry, too many clients already
```

**Cause** : `max_connections=100` trop bas par rapport au nombre de subprocess qui ouvrent chacun leur connexion PDO. **Fix temporaire appliqué sur la VM** : `ALTER SYSTEM SET max_connections = 500` (persisté via `postgresql.auto.conf`). À pérenniser dans `docker-compose.yml` via commande `postgres -c max_connections=500`.

## Acceptance Criteria (à détailler lors du pickup)

1. `php artisan test` exécute les 593 tests sans crash silencieux (✅ déjà fait par le fix `ce3e8c5`)
2. Factory `UserFactory` disponible (AC pour test #2)
3. Tests CAS mockent phpCAS sans appel réel (AC pour test #3)
4. Bug `controlhub_id` corrigé à la source (AC pour test #4)
5. Base `sambaedu_test` peut être recréée proprement entre tests (AC pour test #1)
6. `max_connections` bumpé dans `docker-compose.yml` pour pérenniser (AC pour test #5)
7. Suite `php artisan test` verte bout-en-bout (objectif final)

## Notes

- Cette story n'a pas de numéro BMAD officiel — elle peut être intégrée comme « 9-5 tech-debt test infra » ou traitée hors sprint selon priorité.
- Le correctif `CasAuthenticationTest` (commit `ce3e8c5`) est un prérequis minimal pour que `php artisan test` soit honnête. Les autres corrections peuvent se faire incrémentalement.
- Aucun impact sur le code de prod : seuls les tests + `docker-compose.yml` sont touchés.

## Références

- Commit fix runner : `ce3e8c5` (main)
- Story déclencheuse : `9-4-logs-wpkg-et-rapports-dinstallation.md`
- Fichier de review ayant identifié le problème : `_bmad-output/codeReviews/9-4.md`
