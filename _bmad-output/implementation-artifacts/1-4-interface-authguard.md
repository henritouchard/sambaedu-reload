# Story 1.4 : Interface AuthGuard

Status: review

## Story

En tant que **développeur**,
je veux une interface `AuthGuardInterface` avec une implémentation `SambaEduAuthGuard`,
afin que le middleware `sambaedu.auth` délègue à l'implémentation active et que le swap vers Keycloak (Phase 2) se fasse en changeant une ligne de config, sans toucher aux routes.

## Acceptance Criteria

1. **Interface définie** — L'interface `app/Http/Middleware/Auth/AuthGuardInterface.php` existe et définit le contrat de la garde d'authentification.

2. **Délégation par le middleware** — Quand le middleware `sambaedu.auth` (`SambaEduAuth`) s'exécute, il délègue au guard actif (`SambaEduAuthGuard`) résolu via le conteneur IoC.

3. **Implémentation MVP** — `app/Http/Middleware/Auth/SambaEduAuthGuard.php` implémente `AuthGuardInterface` et reproduit exactement le comportement actuel du middleware (session, LDAP, auto-provisioning Eloquent, `Auth::login`).

4. **Stub Phase 2** — `app/Http/Middleware/Auth/KeycloakAuthGuard.php` existe avec l'implémentation commentée (stub vide ou throw NotImplemented).

5. **Swap via config** — Le swap d'implémentation se fait via un binding dans un ServiceProvider, zéro modification de routes ou de `Kernel.php`.

6. **Comportement identique** — Après le refactoring, le comportement auth (login LDAP, session, redirect, auto-provisioning) est strictement identique à l'avant.

## Tasks / Subtasks

- [x] **Tâche 1 : Créer l'interface** (AC: 1)
  - [x] Créer le dossier `sambaedu-reload/app/Http/Middleware/Auth/`
  - [x] Créer `app/Http/Middleware/Auth/AuthGuardInterface.php` avec la méthode `handle(Request $request, Closure $next): Response`

- [x] **Tâche 2 : Créer `SambaEduAuthGuard`** (AC: 3, 6)
  - [x] Créer `app/Http/Middleware/Auth/SambaEduAuthGuard.php` implémentant `AuthGuardInterface`
  - [x] Déplacer dans `SambaEduAuthGuard` **toute la logique** actuellement dans `SambaEduAuth::handle()` et `SambaEduAuth::ensureEloquentUser()`
  - [x] Injecter les mêmes dépendances : `AuthenticationService`, `UserRepository`
  - [x] Conserver les méthodes privées `ensureEloquentUser()` et `unauthorized()` dans la guard

- [x] **Tâche 3 : Déplacer et refactorer le middleware** (AC: 2, 5)
  - [x] Déplacer `app/Http/Middleware/SambaEduAuth.php` → `app/Http/Middleware/Auth/SambaEduAuth.php`
  - [x] Mettre à jour le namespace : `App\Http\Middleware` → `App\Http\Middleware\Auth`
  - [x] `SambaEduAuth` ne fait plus que : injecter `AuthGuardInterface` → appeler `handle($request, $next)`
  - [x] Mettre à jour `Kernel.php` ligne ~69 : `\App\Http\Middleware\Auth\SambaEduAuth::class`

- [x] **Tâche 4 : Créer le stub `KeycloakAuthGuard`** (AC: 4)
  - [x] Créer `app/Http/Middleware/Auth/KeycloakAuthGuard.php` implémentant `AuthGuardInterface`
  - [x] Corps de `handle()` : `throw new \RuntimeException('KeycloakAuthGuard not implemented — Phase 2')`

- [x] **Tâche 5 : Binding dans AppServiceProvider** (AC: 5)
  - [x] Dans `app/Providers/AppServiceProvider.php` méthode `register()`, ajouter :
    ```php
    $this->app->bind(
        \App\Http\Middleware\Auth\AuthGuardInterface::class,
        \App\Http\Middleware\Auth\SambaEduAuthGuard::class
    );
    ```

- [x] **Tâche 6 : Tests** (AC: 1, 2, 3, 6)
  - [x] Créer `tests/Feature/AuthGuardInterfaceTest.php`
  - [x] Test : `SambaEduAuthGuard` implémente `AuthGuardInterface`
  - [x] Test : `KeycloakAuthGuard` implémente `AuthGuardInterface`
  - [x] Test : middleware non authentifié → redirect vers `route('auth.login')` (comportement inchangé)
  - [x] Test : middleware authentifié avec utilisateur valide → `$next($request)` appelé (comportement inchangé)
  - [x] Test : le binding `AuthGuardInterface` → `SambaEduAuthGuard` est résolu par le conteneur

## Dev Notes

### Contexte Projet

Projet **`sambaedu-reload/`**. Laravel 12, PHP 8.1+, Livewire v3 SFC, PostgreSQL. Style `app/Http/Kernel.php` classique (pas le bootstrap Laravel 11).

### Comportement Actuel du Middleware à Préserver

`SambaEduAuth::handle()` fait exactement ceci — **ne rien changer à la logique** :

1. `$this->authService->isAlreadyAuthenticated()` — lit `$_SESSION['login']` via `AuthenticationService`
2. `$this->authService->getCurrentUser()` — retourne `$_SESSION['login']`
3. `$this->userRepository->findByLogin($login)` — vérif LDAP avec cache 60s
4. Check `$user->isActive` — redirect si désactivé
5. `$request->attributes->set('sambaedu_user', $user)` et `'sambaedu_login'`
6. `$this->ensureEloquentUser($login, $user)` — auto-provisioning User Eloquent + droits admin si login='admin'
7. `Auth::check()` → `AuthUser::findByLogin($login)` → `Auth::login($laravelUser)`
8. `unauthorized()` → JSON si API, `redirect(route('auth.login'))` sinon avec `session()->put('url.intended', ...)`

**Rien de tout cela ne doit changer fonctionnellement.**

### Architecture des Fichiers

```
sambaedu-reload/app/Http/Middleware/
├── Auth/                              ← dossier à créer
│   ├── AuthGuardInterface.php         ← interface
│   ├── SambaEduAuth.php               ← middleware (déplacé depuis Middleware/)
│   ├── SambaEduAuthGuard.php          ← implémentation MVP (logique extraite)
│   └── KeycloakAuthGuard.php          ← stub Phase 2
├── ControlHubAuth.php                 ← inchangé
├── RequireAdminRights.php             ← inchangé
├── PasswordChangeMiddleware.php       ← inchangé
└── [autres middleware inchangés]
```

### Interface à Créer

```php
<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface AuthGuardInterface
{
    public function handle(Request $request, Closure $next): Response;
}
```

### Middleware Simplifié Après Refactoring

```php
<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SambaEduAuth
{
    public function __construct(private AuthGuardInterface $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->guard->handle($request, $next);
    }
}
```

### Mise à Jour Kernel.php

```php
// avant (ligne ~69)
'sambaedu.auth' => \App\Http\Middleware\SambaEduAuth::class,

// après
'sambaedu.auth' => \App\Http\Middleware\Auth\SambaEduAuth::class,
```

### Binding dans AppServiceProvider

```php
// app/Providers/AppServiceProvider.php — méthode register()
$this->app->bind(
    \App\Http\Middleware\Auth\AuthGuardInterface::class,
    \App\Http\Middleware\Auth\SambaEduAuthGuard::class
);
```

Pour swapper en Phase 2 : changer `SambaEduAuthGuard::class` en `KeycloakAuthGuard::class` — **zéro autre modification**.

### Stub KeycloakAuthGuard

```php
<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 2 — Implémentation Keycloak SSO
 * TODO: Implémenter quand Keycloak est disponible
 */
class KeycloakAuthGuard implements AuthGuardInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        // TODO: Phase 2 — Authentification Keycloak
        throw new \RuntimeException('KeycloakAuthGuard not implemented — Phase 2');
    }
}
```

### Injection dans `SambaEduAuthGuard`

Mêmes dépendances que l'actuel `SambaEduAuth` — le conteneur les résout automatiquement :

```php
public function __construct(
    private AuthenticationService $authService,
    private UserRepository $userRepository
) {}
```

### Précautions / Risques

| Risque | Mitigation |
|--------|-----------|
| `ensureEloquentUser()` appelle `UserSyncService` via `app()` | Conserver ce pattern dans `SambaEduAuthGuard` — lazy pour éviter les dépendances circulaires |
| Régression sur le comportement auth | Tests Feature avant/après (non authentifié → login, authentifié → passage) |
| Oublier de mettre à jour Kernel.php | Tâche 3 explicite le changement de namespace |

### Project Structure Notes

- **Interface** : `sambaedu-reload/app/Http/Middleware/Auth/AuthGuardInterface.php`
- **Guard MVP** : `sambaedu-reload/app/Http/Middleware/Auth/SambaEduAuthGuard.php`
- **Stub Phase 2** : `sambaedu-reload/app/Http/Middleware/Auth/KeycloakAuthGuard.php`
- **Middleware déplacé** : `sambaedu-reload/app/Http/Middleware/Auth/SambaEduAuth.php`
- **Kernel mis à jour** : `sambaedu-reload/app/Http/Kernel.php` (ligne ~69)
- **Binding** : `sambaedu-reload/app/Providers/AppServiceProvider.php`
- **Tests** : `sambaedu-reload/tests/Feature/AuthGuardInterfaceTest.php`

### Learnings des Stories Précédentes

- **Tests Feature** : Utiliser `$this->withoutVite()` dans `setUp()` pour éviter l'erreur Vite manifest.
- **Anonymous class** dans SFC : non testable via `Livewire::test()` — non applicable ici (tests standard Feature Laravel).
- **Routes admin** : Cette story ne crée pas de route — uniquement infrastructure auth.

### References

- Middleware actuel : [sambaedu-reload/app/Http/Middleware/SambaEduAuth.php](sambaedu-reload/app/Http/Middleware/SambaEduAuth.php)
- Service d'auth : [sambaedu-reload/app/Services/AuthenticationService.php](sambaedu-reload/app/Services/AuthenticationService.php)
- Kernel (alias middleware) : [sambaedu-reload/app/Http/Kernel.php#L69](sambaedu-reload/app/Http/Kernel.php#L69)
- Architecture — Décision AuthGuard : [_bmad-output/planning-artifacts/architecture.md#Décision-ajoutée-Interface-AuthGuard]
- Source épics : [_bmad-output/planning-artifacts/epics.md#Story-1-4]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

Aucun blocage rencontré. Implémentation directe suivant les spécifications de la story.

### Completion Notes List

- ✅ `AuthGuardInterface` créée avec contrat `handle(Request, Closure): Response`
- ✅ `SambaEduAuthGuard` extraite de `SambaEduAuth` — logique complète (LDAP, session, auto-provisioning, Auth::login) préservée à l'identique
- ✅ `SambaEduAuth` simplifiée : délègue uniquement à `AuthGuardInterface` via injection IoC
- ✅ `Kernel.php` mis à jour : namespace `Auth\SambaEduAuth`
- ✅ `KeycloakAuthGuard` stub créé (throw RuntimeException Phase 2)
- ✅ Binding IoC `AuthGuardInterface → SambaEduAuthGuard` dans `AppServiceProvider::register()`
- ✅ `LegacyMonitorDashboardTest.php` mis à jour : import namespace `Auth\SambaEduAuth`
- ✅ 6 tests Feature passent (9 assertions), aucune régression introduite
- ✅ Les échecs pré-existants dans d'autres tests (LDAP, colonnes manquantes) confirmés comme antérieurs à cette story

### File List

- `sambaedu-reload/app/Http/Middleware/Auth/AuthGuardInterface.php` (nouveau)
- `sambaedu-reload/app/Http/Middleware/Auth/SambaEduAuthGuard.php` (nouveau)
- `sambaedu-reload/app/Http/Middleware/Auth/SambaEduAuth.php` (nouveau)
- `sambaedu-reload/app/Http/Middleware/Auth/KeycloakAuthGuard.php` (nouveau)
- `sambaedu-reload/app/Http/Kernel.php` (modifié — namespace sambaedu.auth)
- `sambaedu-reload/app/Providers/AppServiceProvider.php` (modifié — binding IoC)
- `sambaedu-reload/tests/Feature/AuthGuardInterfaceTest.php` (nouveau)
- `sambaedu-reload/tests/Feature/LegacyMonitorDashboardTest.php` (modifié — import namespace)

## Change Log

- 2026-03-23 : Implémentation complète de l'interface AuthGuard — extraction de `SambaEduAuthGuard` depuis `SambaEduAuth`, création du stub `KeycloakAuthGuard`, binding IoC dans `AppServiceProvider`, 6 tests Feature (9 assertions) verts.

## Status

done
