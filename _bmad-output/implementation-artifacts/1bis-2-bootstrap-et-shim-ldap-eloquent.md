# Story 1bis.2 : Bootstrap & Shim LDAP→Eloquent

Status: review

## Dépendance Critique — Story 1bis.1

Le error logger (`ErrorLoggerService` + `LegacyErrorHandler`) doit être implémenté avant cette story. Les fonctions LDAP non shimmées loggent via ce service. Si la story 1bis.1 n'est pas terminée, le mécanisme de détection des fonctions manquantes ne fonctionnera pas.

## Story

En tant que **développeur**,
je veux un `bootstrap.php` qui initialise la session Laravel et l'autoload pour les modules legacy, un `config.inc.php` qui fait le pont vers la config Laravel, et un `ldap.inc.php` shim qui redirige les appels LDAP vers Eloquent,
afin que les modules legacy puissent tourner dans le contexte Laravel sans modification de leur code interne, en lisant les données depuis PostgreSQL via Eloquent au lieu de l'AD.

## Acceptance Criteria

1. **Bootstrap initialise le contexte Laravel** — Given un module legacy est chargé via le bootstrap, when le bootstrap s'exécute, then la session Laravel est initialisée (`app()`, `config()`, `auth()` disponibles), and l'autoload est configuré pour que les modules legacy trouvent leurs dépendances, and le error handler global (story 1bis.1) est branché.

2. **Config bridge fonctionne** — Given un module legacy lit une variable de configuration (via `$_SESSION`, constante PHP ou variable globale), when `config.inc.php` est chargé, then les variables sont alimentées depuis `config('sambaedu.*')` Laravel.

3. **Shim LDAP redirige vers Eloquent** — Given un module legacy appelle une fonction LDAP shimmée (ex: recherche d'utilisateurs, lecture d'attributs), when la fonction shim est exécutée, then elle retourne les données depuis Eloquent/PostgreSQL dans le format attendu par le code legacy, and aucun appel LDAP réel n'est effectué.

4. **Tests PHPUnit sur données réelles** — Given chaque fonction shim est implémentée, when les tests PHPUnit s'exécutent, then chaque fonction retourne un résultat cohérent avec ce que retournait l'appel LDAP original.

5. **Fonction non shimmée → erreur explicite** — Given une fonction LDAP non shimmée est appelée par un module legacy, when l'appel est intercepté, then une erreur explicite est loggée (via le error logger story 1bis.1) identifiant la fonction manquante, and il n'y a pas de crash silencieux.

6. **Catchall intègre le bootstrap** — Given une requête arrive sur une route non Livewire, when le `LegacyCatchallController` la traite, then il charge `legacy/bootstrap.php` avant d'exécuter le module legacy.

## Tasks / Subtasks

- [x] **Tâche 1 : Créer la structure `legacy/`** (AC: 1)
  - [x] Créer le dossier `sambaedu-reload/legacy/` avec les fichiers vides : `bootstrap.php`, `config.inc.php`, `ldap.inc.php`
  - [x] Créer le dossier `legacy/modules/` (vide pour l'instant — rempli dans stories 1bis.4 à 1bis.6)

- [x] **Tâche 2 : Implémenter `bootstrap.php`** (AC: 1)
  - [x] Charger l'autoloader Composer de Laravel (`require __DIR__ . '/../vendor/autoload.php'`)
  - [x] Bootstrapper l'application Laravel (`$app = require_once __DIR__ . '/../bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();`)
  - [x] Démarrer/reprendre la session Laravel (`session_start()` ou `app('session')->start()` si non déjà active)
  - [x] Brancher le error handler legacy : `set_error_handler([LegacyErrorHandler::class, 'handleError'])` et `set_exception_handler([LegacyErrorHandler::class, 'handleException'])`
  - [x] Inclure `config.inc.php` et `ldap.inc.php`
  - [x] **Attention :** le bootstrap doit être idempotent — un double appel ne doit pas crasher

- [x] **Tâche 3 : Implémenter `config.inc.php`** (AC: 2)
  - [x] Analyser le code legacy SambaEdu pour identifier les variables de config attendues (constantes, `$_SESSION`, globales)
  - [x] Pour chaque variable identifiée, créer le pont vers `config('sambaedu.xxx')` Laravel
  - [x] Documenter dans le fichier la correspondance legacy → Laravel pour chaque variable
  - [x] **Approche incrémentale :** commencer par les variables les plus courantes, compléter au fil des intégrations de modules

- [x] **Tâche 4 : Implémenter le shim LDAP `ldap.inc.php`** (AC: 3, 5)
  - [x] Analyser le code legacy SambaEdu pour identifier les fonctions LDAP utilisées par les modules
  - [x] Implémenter les fonctions shim prioritaires (celles utilisées par les modules Tier 1) — chaque fonction traduit l'appel LDAP en requête Eloquent/PostgreSQL
  - [x] Pour chaque fonction, retourner les données dans le format exact attendu par le code legacy (même structure de tableau/objet)
  - [x] Implémenter un mécanisme fallback pour les fonctions non encore shimmées : wrapper `_shim_log_unimplemented` qui logge via `ErrorLoggerService::log('legacy', "Fonction LDAP non shimmée : {$functionName}")` et retourne `false`
  - [x] Documenter dans le fichier chaque fonction shimmée avec sa correspondance LDAP → Eloquent

- [x] **Tâche 5 : Intégration avec le catchall** (AC: 6)
  - [x] Modifier `LegacyCatchallController::handle()` pour inclure `legacy/bootstrap.php` avant le proxy legacy, quand le path cible un module dans `legacy/modules/`
  - [x] Ajouter une condition : si le path correspond à un module dans `legacy/modules/`, charger via bootstrap. Sinon, conserver le proxy HTTP actuel (routes legacy externes).
  - [x] S'assurer que le `config('sambaedu.legacy_path')` reste utilisé pour les modules non encore dans `legacy/`

- [x] **Tâche 6 : Tests** (AC: 1, 2, 3, 4, 5)
  - [x] Test unitaire : `bootstrap.php` rend `app()`, `config()`, `auth()` disponibles
  - [x] Test unitaire : `config.inc.php` expose les variables attendues depuis la config Laravel
  - [x] Test unitaire : chaque fonction LDAP shimmée retourne un résultat cohérent (format et données)
  - [x] Test unitaire : appel d'une fonction LDAP non shimmée logge une erreur explicite via `ErrorLoggerService`
  - [x] Test Feature : le catchall charge le bootstrap pour un module dans `legacy/modules/`

## Dev Notes

### Contexte Projet

Projet **`sambaedu-reload/`**. Laravel 12, PHP 8.1+, Livewire v3 SFC, PostgreSQL. Le code legacy SambaEdu est actuellement externe (`/var/www/sambaedu` configurable via `SAMBAEDU_LEGACY_PATH`).

### Architecture du Cloisonnement

```
Requête HTTP (route non Livewire)
    ↓ catchall (LegacyCatchallController)
    ↓ legacy/bootstrap.php (init session Laravel + autoload + error handler)
    ↓ legacy/config.inc.php (pont config legacy → config('sambaedu.*'))
    ↓ legacy/ldap.inc.php (shim ~20 fonctions LDAP → Eloquent)
    ↓ legacy/modules/[module]/index.php
    ↓ appels LDAP → shim → Eloquent models (PostgreSQL)
    ↓ réponse HTML
```

### Structure Cible

```
sambaedu-reload/legacy/
├── bootstrap.php          # Init session Laravel, autoload, error handler
├── config.inc.php         # Pont config legacy → config('sambaedu.*')
├── ldap.inc.php           # Shim fonctions LDAP → Eloquent
└── modules/               # Vide — rempli dans stories 1bis.4 à 1bis.6
```

### Catchall Existant

Le `LegacyCatchallController` (dans `app/Http/Controllers/`) fait actuellement :
1. Vérifie si la route est bloquée (routes migrées vers Livewire)
2. Logge l'appel dans `legacy_catchall_logs`
3. Proxy HTTP vers `config('sambaedu.legacy_base_url')` + path

Pour story 1bis.2, ajouter un branchement : si le path cible un module dans `legacy/modules/`, exécuter via `bootstrap.php` au lieu du proxy HTTP. Le proxy reste pour les routes legacy externes non encore copiées.

### Shim LDAP — Approche

Les modules legacy appellent des fonctions globales PHP (pas des méthodes de classe). Le shim `ldap.inc.php` déclare ces fonctions dans le namespace global. Chaque fonction :
1. Reçoit les mêmes paramètres que la version LDAP originale
2. Traduit en requête Eloquent via les modèles existants (`User`, `Workstation`, etc.)
3. Retourne le résultat dans le format exact attendu par le code legacy

**Fonctions typiques à shimmer :**
- `ldap_search()` → `Model::where(...)->get()`
- `ldap_get_entries()` → format tableau LDAP → tableau PHP associatif
- `ldap_first_entry()`, `ldap_get_attributes()`, `ldap_get_values()`
- etc.

**Règle :** le shim ne simule pas un serveur LDAP — il traduit les appels en requêtes Eloquent.

### Config Bridge — Approche

Le code legacy utilise diverses sources de config :
- Constantes PHP (`SE3IP`, `SE3_DOMAIN`, etc.)
- Variables globales (`$_SESSION`, `$GLOBALS`)
- Fichiers de config legacy (PHP)

`config.inc.php` initialise ces variables depuis `config('sambaedu.*')`. Le fichier `config/sambaedu.php` contient déjà des sections pertinentes (`legacy_path`, `legacy_ldap`, etc.).

### Error Handler — Branchement

`bootstrap.php` branche les handlers créés dans story 1bis.1 :
```php
set_error_handler([LegacyErrorHandler::class, 'handleError']);
set_exception_handler([LegacyErrorHandler::class, 'handleException']);
```

Toute erreur PHP dans un module legacy sera capturée et loggée en DB avec source `legacy`.

### Modèles Eloquent Disponibles

Les modèles existants dans `app/Models/` couvrent déjà une partie des entités legacy :
- `User` (avec attributs AD sync)
- `Workstation`, `WorkstationGroup`
- `Application`, `AppProfile`
- `QuotaRule`, `InstallationLog`

Le shim LDAP s'appuie sur ces modèles. Si un modèle manque, le créer dans le cadre de cette story.

### Précautions / Risques

| Risque | Mitigation |
|--------|-----------|
| Le bootstrap crashe et fait tomber tout le site | Le bootstrap doit être dans un try/catch global. En cas d'échec, retourner une 500 propre. |
| Les fonctions LDAP legacy sont trop nombreuses pour être toutes shimmées | Approche incrémentale : shimmer les fonctions Tier 1 d'abord, le fallback logge les manquantes. |
| Le format de retour shim ne correspond pas au format legacy | Tests PHPUnit avec données réelles — comparer format attendu vs format retourné. |
| Le catchall modifié casse les routes legacy existantes | La condition `modules/` dans legacy est un branchement, pas un remplacement du proxy. |
| Double bootstrapping Laravel (catchall déjà dans un contexte Laravel) | Le bootstrap doit détecter si Laravel est déjà bootstrappé et ne pas re-bootstrapper. |

### Learnings des Stories Précédentes

- **Tests Feature :** `$this->withoutVite()` dans `setUp()` pour éviter l'erreur Vite manifest.
- **LegacyCatchallController :** attention au path stripping (UAI prefix) — le controller strip déjà le prefix.
- **Config sambaedu :** sections existantes à réutiliser (`legacy_path`, `legacy_ldap`, `blocked_legacy_routes`).

### References

- Architecture — Cloisonnement Legacy : [_bmad-output/planning-artifacts/architecture.md#Cloisonnement-Legacy]
- Architecture — Bootstrap & Ponts : [_bmad-output/planning-artifacts/architecture.md#Bootstrap-Ponts-de-Configuration]
- Architecture — Shims : [_bmad-output/planning-artifacts/architecture.md#Shims]
- Catchall controller : [sambaedu-reload/app/Http/Controllers/LegacyCatchallController.php]
- Config sambaedu : [sambaedu-reload/config/sambaedu.php]
- Modèles existants : [sambaedu-reload/app/Models/]
- Error handler (story 1bis.1) : [sambaedu-reload/app/Services/LegacyErrorHandler.php]
- Source épics : [_bmad-output/planning-artifacts/epics.md#Story-1bis-2]

## Dev Agent Record

### Agent Model Used

Claude Opus 4.6 (1M context)

### Debug Log References

- Config bridge : `$config` devait être déclaré `global` dans config.inc.php et ldap.inc.php pour être accessible aux modules legacy
- ErrorLoggerService : `app()->bound()` ne détecte pas les services auto-résolus — remplacé par `app()->make()` dans un try/catch
- ErrorLoggerService enregistré comme singleton dans AppServiceProvider pour garantir la disponibilité dans le shim LDAP

### Completion Notes List

- **bootstrap.php** : initialise Laravel (autoload, app, session), branche les error handlers (story 1bis.1), charge config bridge + shim LDAP. Idempotent via `LEGACY_BOOTSTRAP_LOADED` guard. Try/catch global pour ne jamais faire tomber le site.
- **config.inc.php** : pont vers `config('sambaedu.*')` Laravel. Expose les constantes legacy (WOL, shutdown, WPKG), construit le `$config` array avec DNs, OUs, timeouts, domaine déduit du base_dn, préfixe UAI. Documenté en tête de fichier.
- **ldap.inc.php** : shim ~30 fonctions LDAP de haut niveau vers Eloquent. Fonctions shimmées : `search_ad` (user/group/machine/member/filter), `search_user`, `search_group`, `search_machine`, `list_members_group`, `list_groups`, `list_classes`, `list_profs`, `list_eleves`, `filter_user`, `filter_group`, `filter_group_*`, `get_config`, `get_config_file`, utilitaires DN (`ldap_dn2cn`, etc.), fonctions de cache, lock, encrypt/decrypt. Fonctions non shimmées (modify_ad, delete_ad, move_ad, create_*) loggent via ErrorLoggerService et retournent false.
- **LegacyCatchallController** : ajouté branchement `executeViaBootstrap()` pour les modules dans `legacy/modules/`. Le proxy HTTP reste actif pour les routes legacy externes.
- **AppServiceProvider** : ajouté `ErrorLoggerService` comme singleton.
- **Tests** : 35 nouveaux tests (5 bootstrap, 7 config bridge, 20 shim LDAP, 3 feature catchall). 51 tests au total avec les tests existants, tous passent (128 assertions).

### Change Log

- 2026-03-25 : Implémentation complète de la story 1bis-2. Structure legacy/ créée, bootstrap/config/shim LDAP implémentés, catchall intégré, 35 tests ajoutés.

### File List

- `legacy/bootstrap.php` (new)
- `legacy/config.inc.php` (new)
- `legacy/ldap.inc.php` (new)
- `legacy/modules/.gitkeep` (new)
- `app/Http/Controllers/LegacyCatchallController.php` (modified)
- `app/Providers/AppServiceProvider.php` (modified)
- `tests/Unit/LegacyBootstrapTest.php` (new)
- `tests/Unit/LegacyConfigBridgeTest.php` (new)
- `tests/Unit/LdapShimTest.php` (new)
- `tests/Feature/LegacyBootstrapCatchallTest.php` (new)
