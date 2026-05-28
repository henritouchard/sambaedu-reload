# Story 1bis.1 : Error Logger & Module Dashboard

Status: done

## Story

En tant que **développeur**,
je veux un handler global qui capture toutes les erreurs (legacy PHP + exceptions Laravel), les log en DB, et les affiche dans un module du dashboard admin,
afin que l'équipe dispose d'un outil de diagnostic unifié dès le début de l'epic 1bis pour surveiller les erreurs pendant l'intégration legacy.

## Acceptance Criteria

1. **Capture erreurs legacy** — Given une erreur PHP survient dans un module legacy (warning, error, exception), when le handler global l'intercepte, then l'erreur est loggée en DB avec datetime et message (sans stack trace), and l'erreur est identifiée avec source: `legacy`.

2. **Capture exceptions Laravel** — Given une exception Laravel survient, when le handler global l'intercepte, then l'erreur est loggée en DB avec datetime et message (sans stack trace), and l'erreur est identifiée avec source: `laravel`.

3. **Dashboard liste erreurs** — Given des erreurs ont été loggées, when je consulte le module error logger dans `/admin/error-logger`, then je vois la liste des erreurs avec datetime, source (legacy/laravel) et message, and la liste est paginée.

4. **Filtre par source** — Given je suis sur le dashboard error logger, when je filtre par source (legacy/laravel/toutes), then seules les erreurs correspondantes sont affichées.

5. **Accès admin uniquement** — Given je ne suis pas admin, when je tente d'accéder à `/admin/error-logger`, then l'accès est refusé (gate Spatie via middleware `sambaedu.admin`).

## Tasks / Subtasks

- [x] **Tâche 1 : Migration & Modèle** (AC: 1, 2)
  - [x] Créer la migration `create_error_logs_table` avec colonnes : `id`, `source` (string, 10 — "legacy" ou "laravel"), `message` (text), `created_at` (timestamp). Index sur `source` et `created_at`.
  - [x] Créer le modèle `App\Models\ErrorLog` avec `$fillable`, `$casts`, `public $timestamps = false`

- [x] **Tâche 2 : Service ErrorLoggerService** (AC: 1, 2)
  - [x] Créer `App\Services\ErrorLoggerService` avec méthode `log(string $source, string $message): void` qui insère en DB via le modèle `ErrorLog`
  - [x] La méthode est silencieuse en cas d'échec (try/catch + `Log::error`) — le logger ne doit jamais crasher l'app

- [x] **Tâche 3 : Intégration dans le Handler Laravel** (AC: 2)
  - [x] Modifier `App\Exceptions\Handler.php` — dans la méthode `register()`, ajouter un callback `reportable()` qui appelle `ErrorLoggerService::log('laravel', $e->getMessage())`
  - [x] Ne pas interférer avec le reporting existant (GlitchTip à terme)

- [x] **Tâche 4 : Handler PHP legacy** (AC: 1)
  - [x] Créer une fonction `legacy_error_handler(int $errno, string $errstr, string $errfile, int $errline): bool` dans un fichier dédié (ex: `App\Services\LegacyErrorHandler.php` avec méthode statique)
  - [x] Cette fonction appelle `ErrorLoggerService::log('legacy', $errstr)`
  - [x] Créer aussi un handler pour les exceptions non catchées via `set_exception_handler` dans le contexte legacy
  - [x] **Note :** ces handlers seront branchés dans `legacy/bootstrap.php` (story 1bis.2). Pour l'instant, ne créer que le code — le branchement viendra plus tard.

- [x] **Tâche 5 : Page Livewire SFC `/admin/error-logger`** (AC: 3, 4, 5)
  - [x] Créer `resources/views/pages/admin/error-logger/index.blade.php` en Livewire SFC
  - [x] Classe PHP inline : pagination (`WithPagination`), propriété `$sourceFilter` (string, bindée à l'URL via `#[Url]`), méthode `getErrors()` qui query `ErrorLog` avec filtre source optionnel, tri par `created_at desc`
  - [x] Template Blade : `<x-organisms.page>`, tableau avec colonnes datetime/source/message, select de filtre source (Toutes/Legacy/Laravel), `wire:model.live` sur le select, pagination `{{ $errors->links() }}`
  - [x] Optionnel : `wire:poll.10s` pour rafraîchissement auto
  - [x] Suivre le pattern exact de `pages/admin/legacy-monitor/index.blade.php`

- [x] **Tâche 6 : Route admin** (AC: 5)
  - [x] Ajouter dans `routes/web.php` dans le groupe admin : `Route::livewire('/error-logger', 'pages::admin.error-logger.index')->name('error-logger');`
  - [x] Le middleware `sambaedu.admin` du groupe protège automatiquement l'accès

- [x] **Tâche 7 : Tests** (AC: 1, 2, 3, 4, 5)
  - [x] Test unitaire `ErrorLoggerService` : vérifie l'insertion en DB avec source et message corrects
  - [x] Test unitaire : le service ne throw pas si la DB est inaccessible (silencieux)
  - [x] Test Feature : le Handler Laravel logge les exceptions via `ErrorLoggerService`
  - [x] Test Feature : la page `/admin/error-logger` retourne 200 pour un admin
  - [x] Test Feature : le filtre par source retourne les bonnes entrées

## Dev Notes

### Contexte Projet

Projet **`sambaedu-reload/`**. Laravel 12, PHP 8.1+, Livewire v3 SFC, PostgreSQL. Style `app/Http/Kernel.php` classique (pas bootstrap Laravel 11).

### Patterns à Suivre

- **Modèle :** même structure que `LegacyCatchallLog` — `$timestamps = false`, `$fillable`, `$casts` datetime. Table snake_case pluriel : `error_logs`.
- **Service :** classe dans `App\Services\`, injection constructeur, méthodes publiques documentées, `Log::error()` en fallback.
- **Livewire SFC :** inline PHP class + Blade template dans un seul `.blade.php`. Utiliser `#[Title('Error Logger')]`, `WithPagination`, `#[Url]` pour les filtres. Layout `<x-organisms.page>`.
- **Route :** `Route::livewire()` dans le groupe admin existant (middleware `sambaedu.admin` déjà appliqué).
- **Tests Feature :** `$this->withoutVite()` dans `setUp()` pour éviter l'erreur Vite manifest.

### Modèle LegacyCatchallLog comme Référence

```php
class LegacyCatchallLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['method', 'path', 'ip', 'query_string', 'referer', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
```

### Page Legacy Monitor comme Référence SFC

Le pattern exact est dans `resources/views/pages/admin/legacy-monitor/index.blade.php` :
- PHP inline avec `WithPagination`, `#[Url]` pour les filtres
- `wire:poll.5s` pour le rafraîchissement
- `<x-organisms.page>` pour le layout
- Tableau avec pagination `{{ $logs->links() }}`

### Handler — Coexistence avec GlitchTip

Le `Handler.php` actuel est minimal. Le callback `reportable()` s'ajoute sans interférence. GlitchTip (via `sentry/sentry-laravel`) sera ajouté plus tard — les deux systèmes coexisteront sans conflit car `reportable()` ne stoppe pas la chaîne de reporting.

### Handler Legacy — Usage Futur

Les handlers PHP legacy (`set_error_handler`, `set_exception_handler`) sont créés ici mais **branchés dans story 1bis.2** via `legacy/bootstrap.php`. Ce découplage permet de tester le service en isolation.

### Précautions / Risques

| Risque | Mitigation |
|--------|-----------|
| Le logger crashe et fait tomber l'app | try/catch silencieux dans `ErrorLoggerService::log()` |
| Table `error_logs` grossit indéfiniment | Hors scope — nettoyage à implémenter plus tard si nécessaire. Table droppable. |
| Conflits avec GlitchTip futur | `reportable()` est additive — pas de conflit |

### Project Structure Notes

```
sambaedu-reload/
├── app/
│   ├── Exceptions/Handler.php                    (modifié)
│   ├── Models/ErrorLog.php                       (nouveau)
│   └── Services/
│       ├── ErrorLoggerService.php                (nouveau)
│       └── LegacyErrorHandler.php                (nouveau)
├── database/migrations/
│   └── xxxx_xx_xx_create_error_logs_table.php    (nouveau)
├── resources/views/pages/admin/
│   └── error-logger/index.blade.php              (nouveau)
├── routes/web.php                                (modifié)
└── tests/
    └── Feature/ErrorLoggerTest.php               (nouveau)
```

### References

- Architecture — Error Logger Unifié : [_bmad-output/planning-artifacts/architecture.md#Error-Logger-Unifié]
- Architecture — Cloisonnement Legacy : [_bmad-output/planning-artifacts/architecture.md#Cloisonnement-Legacy]
- Modèle référence : [sambaedu-reload/app/Models/LegacyCatchallLog.php]
- Page référence : [sambaedu-reload/resources/views/pages/admin/legacy-monitor/index.blade.php]
- Handler actuel : [sambaedu-reload/app/Exceptions/Handler.php]
- Routes admin : [sambaedu-reload/routes/web.php]
- Source épics : [_bmad-output/planning-artifacts/epics.md#Story-1bis-1]

## Dev Agent Record

### Agent Model Used

Claude Opus 4.6 (1M context)

### Debug Log References

Aucun problème rencontré.

### Completion Notes List

- Migration `create_error_logs_table` créée avec index sur `source` et `created_at`
- Modèle `ErrorLog` suit le pattern de `LegacyCatchallLog` (`$timestamps = false`, `$fillable`, `$casts`)
- `ErrorLoggerService::log()` encapsulé dans try/catch — ne crashe jamais l'app, fallback vers `Log::error()`
- `Handler.php` modifié : `reportable()` appelle `ErrorLoggerService::log('laravel', ...)` — additif, pas d'interférence avec le reporting existant
- `LegacyErrorHandler` créé avec `handleError()` et `handleException()` — branchement prévu en story 1bis.2
- Page Livewire SFC `/admin/error-logger` : tableau paginé, filtre par source (legacy/laravel/toutes), `wire:poll.10s`, pattern identique à legacy-monitor
- Route ajoutée dans le groupe admin (middleware `sambaedu.admin` appliqué automatiquement)
- 7 tests passent (12 assertions), aucune régression sur les tests existants

### Change Log

- 2026-03-25 : Implémentation complète de la story 1bis.1 — Error Logger & Module Dashboard

### File List

- `sambaedu-reload/database/migrations/2026_03_25_100000_create_error_logs_table.php` (nouveau)
- `sambaedu-reload/app/Models/ErrorLog.php` (nouveau)
- `sambaedu-reload/app/Services/ErrorLoggerService.php` (nouveau)
- `sambaedu-reload/app/Services/LegacyErrorHandler.php` (nouveau)
- `sambaedu-reload/app/Exceptions/Handler.php` (modifié)
- `sambaedu-reload/resources/views/pages/admin/error-logger/index.blade.php` (nouveau)
- `sambaedu-reload/routes/web.php` (modifié)
- `sambaedu-reload/tests/Feature/ErrorLoggerTest.php` (nouveau)
