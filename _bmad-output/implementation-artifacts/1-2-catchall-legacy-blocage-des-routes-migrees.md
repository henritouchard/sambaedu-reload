# Story 1.2: Catchall Legacy + Blocage des Routes Migrées

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant que **développeur**,
je veux que le catchall route les appels non-Livewire vers le legacy via `SAMBAEDU_LEGACY_PATH` configurable, et bloque l'accès aux routes dont l'équivalent Livewire existe dans SER,
afin que le legacy reste accessible pour les routes non encore migrées, et que les utilisateurs soient redirigés vers les nouvelles pages SER sans passer par le legacy.

## Acceptance Criteria

1. **Config `SAMBAEDU_LEGACY_PATH`** — Étant donné que `SAMBAEDU_LEGACY_PATH` est défini dans `.env`, quand une route sans correspondance Livewire est appelée (et non dans la liste de blocage), alors la requête est redirigée vers le script PHP legacy correspondant, et la requête est loggée dans `legacy_catchall_logs` (timestamp, method, path, IP, query string, referer).

2. **Blocage des routes migrées** — Étant donné qu'une route legacy a un équivalent Livewire déclaré dans la liste de blocage, quand cette route legacy est appelée, alors l'accès est bloqué et l'utilisateur est redirigé vers l'équivalent SER, et la requête n'est PAS loggée dans `legacy_catchall_logs`. (tu ne sauras pas forcément quelles pages sont gérées et lesquelles ne le sont pas, il faut juste mettre en place le système pour que je puisse ajouter les url que je veux). Il 

3. **Mode transition** — Étant donné que `LEGACY_BLOCK_MIGRATED_ROUTES=false` dans `.env`, quand une route de la liste de blocage est appelée, alors le blocage est désactivé — la requête passe au legacy normalement (mode transition).

4. **Config absente/invalide** — Étant donné que `SAMBAEDU_LEGACY_PATH` est absent ou invalide, quand le catchall tente de résoudre une route, alors une erreur explicite est levée (pas de comportement silencieux).

## Tasks / Subtasks

- [x] **Tâche 1 : Configuration `SAMBAEDU_LEGACY_PATH`** (AC: 1, 4)
  - [x] Ajouter `SAMBAEDU_LEGACY_PATH=/var/www/sambaedu` dans `.env.example`
  - [x] Ajouter `LEGACY_BLOCK_MIGRATED_ROUTES=true` dans `.env.example`
  - [x] Créer ou compléter `config/sambaedu.php` avec les clés `legacy_path` et `block_migrated_routes`
  - [x] Remplacer `base_path('../' . $path)` dans le catchall par `config('sambaedu.legacy_path') . '/' . $path`
  - [x] Ajouter une validation : si `legacy_path` est null ou le dossier n'existe pas → `abort(500)` avec message explicite

- [x] **Tâche 2 : Migration `legacy_catchall_logs`** (AC: 1)
  - [x] Créer la migration pour la table `legacy_catchall_logs`
  - [x] Colonnes : `id`, `method` (varchar 10), `path` (varchar 2048), `ip` (varchar 45), `query_string` (text nullable), `referer` (text nullable), `created_at` (timestamp)
  - [x] Pas de `updated_at` (logs immutables)
  - [x] Index sur `path` et `created_at` pour le dashboard

- [x] **Tâche 3 : Modèle `LegacyCatchallLog`** (AC: 1)
  - [x] Créer `app/Models/LegacyCatchallLog.php`
  - [x] `$timestamps = false` + colonne `created_at` gérée manuellement via `$casts`
  - [x] `$fillable` = les colonnes de logging
  - [x] Pas de relations — table autonome droppable

- [x] **Tâche 4 : Refactorer le catchall dans `web.php`** (AC: 1, 2, 3, 4)
  - [x] Extraire la logique catchall dans un Service ou un Controller dédié (le catchall actuel dans `web.php` fait ~120 lignes inline — à refactorer en controller)
  - [x] Créer `app/Http/Controllers/LegacyCatchallController.php` avec une méthode `handle(Request $request, string $path)`
  - [x] Implémenter une liste d'autorisées explicites (qui acceptera des regex)
  - [x] Implémenter la liste de blocage : tableau de mapping `[legacy_path => ser_route]` dans la config `sambaedu.blocked_legacy_routes`. Ce mapping doit accepter les regex afin de pouvoir autoriser certaines routes.
  - [x] Si le path matche une route bloquée ET qu'il ne matche pas une route explicitement autorisée ET `block_migrated_routes` est true → `redirect()` vers la route SER
  - [x] Si le path ne matche pas ou le blocage est désactivé → résolution legacy existante
  - [x] Logger chaque appel legacy (pas les redirections vers SER) dans `legacy_catchall_logs`
  - [x] Remplacer dans `web.php` le closure inline par un renvoi vers le controller

- [x] **Tâche 5 : Channel de logging `legacylog`** (AC: 1)
  - [x] Ajouter le channel `legacylog` dans `config/logging.php` (daily, storage path `logs/legacy-catchall.log`)
  - [x] Logger aussi dans le channel file pour debugging (en plus de la table DB)

- [x] **Tâche 6 : Tests** (AC: 1, 2, 3, 4)
  - [x] Créer `tests/Feature/LegacyCatchallTest.php`
  - [x] Test : route legacy existante → contenu legacy servi + log en DB
  - [x] Test : route bloquée + `LEGACY_BLOCK_MIGRATED_ROUTES=true` → redirect vers SER + pas de log
  - [x] Test : route bloquée + `LEGACY_BLOCK_MIGRATED_ROUTES=false` → contenu legacy servi
  - [x] Test : `SAMBAEDU_LEGACY_PATH` invalide → erreur 500 explicite
  - [x] Test : route inexistante → 404

## Dev Notes

### Contexte Projet Critique

Le projet est **`sambaedu-reload/`**. Tout le code va dans `/home/htouchard/code/irundo/codebase/sambaedu-reload/`.

**Laravel 12** — PHP 8.1+, PostgreSQL comme BD cible.

### État Actuel du Catchall — Ce Qui Existe Déjà

Le catchall est **déjà implémenté** dans `routes/web.php` lignes 231-348. C'est une closure inline de ~120 lignes qui :
- Bloque les dossiers sensibles (laravel, vendor, node_modules, .git, .env)
- Résout les fichiers PHP legacy via `base_path('../' . $path)` (chemin **hardcodé** — c'est ça le problème)
- Gère les dossiers avec `index.php` / `index.html`
- Sert les assets statiques (CSS, JS, images, fonts)
- Corrige les chemins relatifs dans le HTML legacy

**Ce qui manque (= cette story) :**
- `SAMBAEDU_LEGACY_PATH` configurable (actuellement hardcodé `base_path('../')`)
- Le logging dans `legacy_catchall_logs` (table inexistante)
- Le blocage des routes migrées
- Le channel `legacylog` (inexistant dans `config/logging.php`)

### Chemin Legacy Actuel

Dans `app/Config/LegacyConfigBridge.php` ligne 29, le chemin legacy est déjà hardcodé :
```php
private const LEGACY_PATH = '/var/www/sambaedu';
```
La config `config/sambaedu.php` **n'existe pas encore** — il faudra la créer. Réutiliser la même valeur par défaut `/var/www/sambaedu` pour cohérence avec `LegacyConfigBridge`.

### Routes Livewire Déjà Migrées

23 routes sont déjà déclarées dans `web.php` (lignes 52-143) sous le préfixe `/app` avec middleware `sambaedu.auth` :
- `/app/dashboard`, `/app/workers`, `/app/users`, `/app/users/new`
- `/app/users/groups/*`, `/app/users/{login}`
- `/app/rights-management`, `/app/shortcuts/*`
- `/app/parc-settings/*`, `/app/parc/*`
- `/app/sync-from-ad`, `/app/admin/control-hub`

**Point critique :** les routes SER sont sous `/app/*`, tandis que les routes legacy sont à la racine (ex: `gpo/shortcuts_out.php`). La liste de blocage doit mapper les paths legacy (racine) vers les routes SER (`/app/*`).

### Liste Initiale de Blocage Suggérée

Construire le mapping à partir des routes legacy connues. Exemples probables :
```php
// config/sambaedu.php → 'blocked_legacy_routes'
// Format : 'path/legacy' => '/app/equivalent-ser'
```

Le mapping exact devra être déterminé en analysant quels scripts PHP legacy dans `/var/www/sambaedu/` correspondent aux routes Livewire existantes. Pour cette story, inclure au minimum la route `gpo/shortcuts_out.php` qui a déjà un intercepteur dédié (ligne 220 de `web.php`).

### Patterns à Suivre OBLIGATOIREMENT

**Architecture 3 couches** — ne pas laisser de logique métier dans `web.php`. Le catchall actuel (closure de 120 lignes) doit être extrait dans un controller.

**Convention nommage DB** — `legacy_catchall_logs` : snake_case, pluriel (conforme).

**Gestion d'erreurs** — `WithToasts` pour le front, mais cette story n'a pas de composant Livewire front (le dashboard est story 1.3). Le logging DB + channel file suffisent.

**Format API** — pas applicable ici (pas de réponse API).

**Services** — le controller catchall peut appeler directement le modèle `LegacyCatchallLog::create()` sans service intermédiaire car c'est du logging simple. Un service serait over-engineering.

### Points d'Attention / Risques

| Risque | Mitigation |
|--------|-----------|
| Régression catchall | Le refactoring du catchall doit préserver exactement le comportement existant (résolution PHP, dossiers index, assets statiques, correction chemins relatifs) |
| La route `gpo/shortcuts_out.php` (ligne 220) intercepte déjà un path legacy | Ne pas la bloquer — elle a son propre controller (`ShortcutExportController::legacyDispatch`) |
| Performance du logging | Logging en DB sur chaque requête catchall — acceptable car volume faible (uniquement les routes non-SER) |
| `LegacyConfigBridge::LEGACY_PATH` hardcodé | Ne PAS modifier `LegacyConfigBridge` dans cette story — il continuera à utiliser sa constante. `config/sambaedu.php` est la nouvelle source de vérité pour le catchall uniquement |

### Project Structure Notes

- **Controller** : `app/Http/Controllers/LegacyCatchallController.php`
- **Modèle** : `app/Models/LegacyCatchallLog.php`
- **Migration** : `database/migrations/YYYY_MM_DD_HHMMSS_create_legacy_catchall_logs_table.php`
- **Config** : `config/sambaedu.php` (nouveau fichier)
- **Logging** : ajout du channel `legacylog` dans `config/logging.php`
- **Tests** : `tests/Feature/LegacyCatchallTest.php`
- **Env** : `.env.example` (ajouter `SAMBAEDU_LEGACY_PATH`, `LEGACY_BLOCK_MIGRATED_ROUTES`)

### References

- Catchall actuel : [routes/web.php:231-348](sambaedu-reload/routes/web.php#L231-L348)
- Routes Livewire migrées : [routes/web.php:52-143](sambaedu-reload/routes/web.php#L52-L143)
- LegacyConfigBridge (chemin hardcodé) : [app/Config/LegacyConfigBridge.php:29](sambaedu-reload/app/Config/LegacyConfigBridge.php#L29)
- LegacyParcBridgeService (pattern Service Legacy) : [app/Services/Legacy/LegacyParcBridgeService.php](sambaedu-reload/app/Services/Legacy/LegacyParcBridgeService.php)
- Logging config : [config/logging.php](sambaedu-reload/config/logging.php)
- Intercepteur shortcuts existant : [routes/web.php:220](sambaedu-reload/routes/web.php#L220)
- Source: _bmad-output/planning-artifacts/epics.md#Story-1-2
- Source: _bmad-output/planning-artifacts/architecture.md#Coexistence-Legacy-Stratégie-Catchall

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Test `test_allowed_route_overrides_blocking` : 1er essai 400 car `/gpo/shortcuts_out.php` est intercepté par une route dédiée (`ShortcutExportController::legacyDispatch`) avant le catchall. Corrigé en utilisant un path différent (`/legacy-section/exception.php`).
- Erreurs pre-existantes dans la suite globale (LDAP injoignable, `ShortcutExportComparisonTest` nécessite `../includes/shortcuts.inc.php` absent hors serveur) — non liées à cette story.

### Completion Notes List

- Tâche 1 : `config/sambaedu.php` complété (clés `legacy_path`, `legacy_base_url`, `block_migrated_routes`, `log_404`, `blocked_legacy_routes`, `allowed_legacy_routes`). `.env.example` mis à jour avec section LEGACY.
- Tâche 2 : Migration `2026_03_20_100000_create_legacy_catchall_logs_table.php` créée. Pas d'`updated_at`, index sur `path` et `created_at`.
- Tâche 3 : Modèle `LegacyCatchallLog` créé. `$timestamps = false`, `created_at` casté en datetime, `$fillable` complet.
- Tâche 4 : `LegacyCatchallController::handle()` extrait de la closure de ~120 lignes de `web.php`. Logique complète : dossiers sensibles → blocage regex (avec allowed override) → proxy HTTP vers vhost legacy → assets statiques servis directement. Chemin legacy via `config('sambaedu.legacy_path')` + validation abort(500).
- Tâche 5 : Channel `legacylog` ajouté (`daily`, 30 jours, `storage/logs/legacy-catchall.log`).
- Tâche 6 : 9 tests Feature écrits et passants à 100%.

### Code Review Record (bmad-code-review)

- **Date** : 2026-03-20
- **Reviewer** : claude-opus-4-6
- **Layers** : Blind Hunter + Edge Case Hunter + Acceptance Auditor
- **Résultat** : 2 intent gaps, 1 patch, 8 defer (pré-existants), 8 rejetés

**Corrections post-review :**
- Délimiteur regex `#` → `~` dans `findBlockedRouteRedirect()` (patch #3)
- Logging 404 configurable ajouté (`sambaedu.log_404`, `LEGACY_LOG_404` dans .env) (intent gap #2)
- Scope logging clarifié : PHP/index uniquement, pas les assets statiques (intent gap #1)
- **Refactoring majeur** : remplacement de `include` par proxy HTTP vers vhost legacy (port 8082, localhost only) pour isolation complète des process PHP — résout la collision de fonctions (`encrypt()` dans `ldap.inc.php` vs Laravel helpers)
- `tearDown` des tests : `LegacyCatchallLog::query()->delete()` au lieu de `Schema::dropIfExists` (évite de dropper la table de prod)
- Script `setupApache.sh` créé pour la bascule Apache (SER port 80, legacy port 8082)
- Migration Apache intégrée dans `install.sh` (fonction `configure_apache`)
- Pré-remplissage `APP_URL` avec l'UAI dans `install.sh`

### File List

- `sambaedu-reload/.env.example` (modifié)
- `sambaedu-reload/config/sambaedu.php` (modifié)
- `sambaedu-reload/config/logging.php` (modifié)
- `sambaedu-reload/database/migrations/2026_03_20_100000_create_legacy_catchall_logs_table.php` (créé)
- `sambaedu-reload/app/Models/LegacyCatchallLog.php` (créé)
- `sambaedu-reload/app/Http/Controllers/LegacyCatchallController.php` (créé)
- `sambaedu-reload/routes/web.php` (modifié)
- `sambaedu-reload/tests/Feature/LegacyCatchallTest.php` (créé)
- `sambaedu-reload/scripts/setupApache.sh` (créé)
- `sambaedu-reload/scripts/install.sh` (modifié)

## Change Log

- 2026-03-20 : Story 1-2 implémentée — catchall refactoré en `LegacyCatchallController`, `SAMBAEDU_LEGACY_PATH` configurable, blocage des routes migrées via regex, logging DB + fichier, 8 tests Feature.
- 2026-03-20 : Code review (bmad-code-review) — corrections post-review : proxy HTTP (isolation PHP), logging 404 configurable, délimiteur regex, script setupApache, intégration install.sh. 9 tests passants.
