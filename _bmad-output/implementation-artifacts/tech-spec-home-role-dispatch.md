---
title: "Page d'accueil /home avec dispatch par rôle Spatie"
slug: 'home-role-dispatch'
created: '2026-04-30'
status: 'ready-for-dev'
stepsCompleted: [1, 2, 3, 4]
tech_stack:
  - 'Laravel'
  - 'Livewire v3 (SFC)'
  - 'Spatie Permission'
  - 'Blade'
  - 'DaisyUI / Tailwind'
  - 'LdapRecord (AD operations)'
  - 'PostgreSQL'
files_to_modify:
  - 'routes/web.php'
  - 'app/Http/Controllers/AuthController.php'
  - 'app/Http/Controllers/ChangePasswordController.php'
  - 'app/Http/Middleware/RedirectIfAuthenticated.php'
  - 'resources/views/components/organisms/sidebar.blade.php'
  - 'resources/views/errors/layout.blade.php'
  - 'resources/views/pages/workers/index.blade.php'
  - 'resources/views/pages/home/index.blade.php (CREATE)'
  - 'resources/views/pages/home/_partials/tech.blade.php (CREATE)'
  - 'resources/views/pages/home/_partials/standard.blade.php (CREATE)'
  - 'resources/views/pages/home/_partials/eleve.blade.php (CREATE)'
  - 'resources/views/pages/home/activity/index.blade.php (MOVE from pages/dashboard/activity/index.blade.php)'
  - 'resources/views/pages/dashboard/index.blade.php (DELETE après migration vers tech.blade.php)'
  - 'resources/views/pages/dashboard/activity/index.blade.php (DELETE après déplacement)'
  - 'tests/Feature/Livewire/HomeDispatcherTest.php (CREATE)'
code_patterns:
  - 'Livewire SFC : `<?php new #[Title(...)] class extends Component { use WithToasts; ... }; ?>` + template Blade dans le même fichier'
  - "Filesystem-based routing via Route::livewire('/path', 'pages::folder.index')"
  - 'Partials sous `_partials/` (Blade purs sans Livewire si pas de réactivité)'
  - 'Dispatcher : @include de partial Blade depuis le composant Livewire racine'
  - 'Wrapper `<x-organisms.page title="..." description="...">` pour le layout'
  - 'Trait `WithToasts` pour les notifications'
  - "Spatie : `\$user->hasAnyRole([...])` / `\$user->hasRole('...')` (guard_name = 'web')"
test_patterns:
  - "Livewire::test('pages::folder.index') avec chemin filesystem-based"
  - 'Trait DatabaseTransactions ; createTablesIfNeeded() pour les tables Spatie + users'
  - "actingAs(\$user) ; \$user->assignRole(SambaRole::X->value)"
  - 'Pattern hérité de tests/Feature/Livewire/RoleManagementTest.php et RightsManagementPageTest.php'
---

# Tech-Spec: Page d'accueil /home avec dispatch par rôle Spatie

**Created:** 2026-04-30

## Overview

### Problem Statement

La route actuelle `/app/dashboard` expose l'état système (disk usage, statut MariaDB, queue workers, restart) à **tous** les utilisateurs authentifiés, y compris les élèves qui n'ont rien à y faire. On veut une page d'accueil contextuelle au rôle de l'utilisateur.

### Solution

Remplacer `/app/dashboard` par une nouvelle route `/app/home` dont le rendu est dispatché par rôle Spatie en **3 buckets** :

- **`tech`** (super-admin, technicien, referent-numerique) : vue état système — reprend le contenu actuel de `pages/dashboard/index.blade.php`.
- **`standard`** (prof, user-admin, computer-admin, share-admin, eleve-admin) : version allégée du dashboard sans les éléments techniques.
- **`eleve`** (eleve + fallback restrictif si aucun rôle reconnu) : formulaire de changement de mot de passe self-service.

Le dispatcher est un Livewire SFC (`pages::home.index`) qui résout le bucket via `Spatie\Permission\Traits\HasRoles::hasAnyRole()` et inclut le partial correspondant. Le contenu du dashboard actuel est **migré** dans `pages/home/_partials/tech.blade.php`, la route et le fichier `pages/dashboard/index.blade.php` sont supprimés. La sous-page `/app/dashboard/activity` est déplacée sous `/app/home/activity`. Toutes les références `route('app.dashboard')` du codebase (8) et `route('app.dashboard.activity')` (1) sont migrées.

### Scope

**In Scope:**

- Création route `/app/home` (Livewire dispatcher) → `pages::home.index`.
- Création des 3 partials : `pages/home/_partials/{tech,standard,eleve}.blade.php`.
- Migration du contenu de `pages/dashboard/index.blade.php` → `_partials/tech.blade.php` (avec mise à jour des liens internes : `app.dashboard.activity` → `app.home.activity`).
- Construction du partial `standard` = copie de `tech` SANS : carte MariaDB, carte AD, carte Espace Disque, carte Queue Workers et bouton restart, action "Voir les workers et logs". Conserve : carte Utilisateurs Actifs, carte Machines en Ligne, card "Activité Récente" (pointant vers `app.home.activity`), card "Actions Rapides".
- Construction du partial `eleve` (Livewire SFC) = formulaire self-service de changement de mot de passe utilisant `AuthenticationService::authenticate()` + `UserService::changePasswordInAd($login, $new, mustChangeAtNextLogin: false)`.
- Déplacement de `/app/dashboard/activity` → `/app/home/activity` (route + fichier `pages/dashboard/activity/index.blade.php` → `pages/home/activity/index.blade.php`, internal back link `app.dashboard` → `app.home`).
- Suppression des routes `/app/dashboard` et `/app/dashboard/activity` (dans `routes/web.php`).
- Suppression des fichiers `pages/dashboard/index.blade.php` et `pages/dashboard/activity/index.blade.php` (et du dossier `pages/dashboard/` qui devient vide).
- Mise à jour des 9 références (8 × `app.dashboard` + 1 × `app.dashboard.activity`) :
  - `app/Http/Controllers/AuthController.php` : 5 redirections (lignes 44, 124, 137, 290, 336)
  - `app/Http/Controllers/ChangePasswordController.php` : 1 redirection (ligne 106)
  - `app/Http/Middleware/RedirectIfAuthenticated.php` : 1 redirection (ligne 24)
  - `resources/views/errors/layout.blade.php` : 1 lien (ligne 48)
  - `resources/views/pages/workers/index.blade.php` : 1 lien + libellé (ligne 33 — "Retour dashboard" → "Retour accueil")
- Sidebar (`resources/views/components/organisms/sidebar.blade.php` lignes 16-20) :
  - `route('app.dashboard')` → `route('app.home')`
  - `request()->is('app/dashboard*')` → `request()->is('app/home*')`
  - Libellé "Tableau de bord" → "Accueil"
- Tests (`tests/Feature/Livewire/HomeDispatcherTest.php`) : 5 cas — `tech` / `standard` / `eleve` / `multi-roles` / `fallback sans rôle`.

**Out of Scope:**

- Implémentation des stories 14.4 / 14.5 / 14.6 (contenu admin réel).
- Affichage d'infos de profil pour l'élève (uniquement le form MDP).
- Ajout de `administratif` à l'enum `SambaRole`.
- Création de helpers `User::isAdministratif()`, `isRefnum()`, etc. — on s'appuie exclusivement sur Spatie.
- Refonte UX/visuelle des partials (style identique au dashboard actuel ; aucun nouveau composant DaisyUI).
- Décommissionnement de l'icône `<x-icons.dashboard>` (réutilisée dans la sidebar avec le nouveau libellé "Accueil").

## Context for Development

### Codebase Patterns

**Routing filesystem-based** — Routes déclarées dans `routes/web.php` via `Route::livewire('/path', 'pages::folder.index')->name('app.path')`. Le composant correspond à `resources/views/pages/folder/index.blade.php`.

**Livewire SFC** — Un `.blade.php` contient à la fois la classe anonyme PHP (`new #[Title('...')] class extends Component { ... };`) et le template Blade. Wrapper standard de page : `<x-organisms.page title="..." description="...">`.

**Partials Blade purs** — Les morceaux non réactifs vivent sous `_partials/` comme `*.blade.php` simples. Inclus depuis le parent via `@include('pages.folder._partials.name', [...])`.

**Spatie Permission** — `User` model utilise `HasRoles` (déclaré ligne 49 de `app/Models/User.php`) avec `protected $guard_name = 'web';`. Les noms de rôles sont définis dans `App\Enums\SambaRole` (cas : `Eleve`, `Prof`, `EleveAdmin`, `ShareAdmin`, `UserAdmin`, `Technicien`, `ReferentNumerique`, `ComputerAdmin`, `SuperAdmin`). API utilisée : `$user->hasRole(string|SambaRole)` et `$user->hasAnyRole(array)`.

**Toasts** — Trait `App\Components\Traits\WithToasts` (méthodes `toastSuccess()`, `toastError()`).

**`auth()->user()` retourne directement l'Eloquent `User`** depuis 2026-04-24 (LdapUserProvider). Plus de wrapper AuthUser. ([memory: auth_uses_eloquent_user.md])

**Tests Livewire** — Pattern : `Livewire::test('pages::folder.index')` ; `DatabaseTransactions` ; `createTablesIfNeeded()` pour provisionner les tables Spatie + users à chaud (cf. `RightsManagementPageTest::createTablesIfNeeded`) ; `actingAs($user)` ; `$user->assignRole(SambaRole::X->value)`.

### Files to Reference

| File | Purpose |
| ---- | ------- |
| `routes/web.php` (ligne 53-54) | Ajouter `/app/home` + `/app/home/activity` (`Route::livewire`), supprimer `/app/dashboard` + `/app/dashboard/activity`. |
| `resources/views/pages/dashboard/index.blade.php` | Source à migrer vers `_partials/tech.blade.php` (logique PHP + template). Supprimer après migration. Lien interne ligne 298 (`app.dashboard.activity`) à mettre à jour. |
| `resources/views/pages/dashboard/activity/index.blade.php` | À déplacer vers `pages/home/activity/index.blade.php`. Mettre à jour le `route('app.dashboard')` ligne 125 (back link). |
| `app/Models/User.php` (ligne 49, 52) | `HasRoles` trait + `$guard_name = 'web'` confirmés. Pas de modification requise. |
| `app/Enums/SambaRole.php` | Source des valeurs string utilisées dans le dispatcher (`->value`). Pas de modification. |
| `app/Services/UserService.php` (ligne 585-619) | Fournit `changePasswordInAd($login, $newPassword, $mustChangeAtNextLogin)` utilisé par le partial `eleve`. Appel : `mustChangeAtNextLogin: false`. |
| `app/Services/AuthenticationService.php` | Fournit `authenticate($login, $password, $ip)` pour valider le mot de passe actuel dans le partial `eleve` (cohérent avec le pattern `ChangePasswordController` ligne 83). |
| `app/Http/Controllers/AuthController.php` (lignes 44, 124, 137, 290, 336) | 5 redirections `route('app.dashboard')` → `route('app.home')`. |
| `app/Http/Controllers/ChangePasswordController.php` (ligne 106) | 1 redirection à mettre à jour. |
| `app/Http/Middleware/RedirectIfAuthenticated.php` (ligne 24) | 1 redirection à mettre à jour. |
| `resources/views/components/organisms/sidebar.blade.php` (lignes 16-20) | Lien + check active path + libellé. |
| `resources/views/errors/layout.blade.php` (ligne 48) | Lien fallback page d'erreur. |
| `resources/views/pages/workers/index.blade.php` (ligne 33) | Lien "Retour dashboard" → "Retour accueil". |
| `tests/Feature/Livewire/RoleManagementTest.php` (lignes 60-65, 82-107) | Modèle de référence pour les tests : `actingAs`, `assignRole`, `Livewire::test('pages::xxx')`. |
| `tests/Feature/Livewire/RightsManagementPageTest.php` (lignes 31-90) | Modèle de référence pour `createTablesIfNeeded()` (Spatie + users en SQLite mémoire). |

### Technical Decisions

**TD-01 — Dispatcher logic (Spatie-only, sans helpers custom).**

Dans `pages/home/index.blade.php` (Livewire SFC) :

```php
public string $bucket = 'eleve';

public function mount(): void {
    $user = auth()->user();
    $this->bucket = match (true) {
        $user?->hasAnyRole(['super-admin', 'technicien', 'referent-numerique']) => 'tech',
        $user?->hasRole('eleve')                                                => 'eleve',
        $user?->hasAnyRole(['prof', 'user-admin', 'computer-admin', 'share-admin', 'eleve-admin']) => 'standard',
        default                                                                 => 'eleve', // fallback restrictif
    };
}
```

Template :

```blade
<x-organisms.page title="Accueil" description="...">
    @include('pages.home._partials.' . $bucket)
</x-organisms.page>
```

**TD-02 — Migration plutôt que duplication.** Le contenu de `pages/dashboard/index.blade.php` est **déplacé** dans `_partials/tech.blade.php` (option (a) validée par Henri 2026-04-30). Le fichier source est supprimé.

**TD-03 — Décomposition en partial Blade pur (pas Livewire) pour `tech` et `standard`.** La logique de chargement (`getDashboardStats`, `getRecentActivity`, `getMariaDbStatus`, `restartQueueWorkers`) est portée par le composant Livewire **dispatcher** (pages/home/index.blade.php). Les partials sont des templates Blade purs qui consomment `$stats`, `$recentActivity`, `$mariaDbStatus`. Le `wire:click="restartQueueWorkers"` reste résolu côté dispatcher (uniquement appelable depuis `tech`).

**TD-04 — Périmètre du partial `standard`.** Conserve : carte "Utilisateurs Actifs", carte "Machines en Ligne", card "Activité Récente" (lien vers `app.home.activity`), card "Actions Rapides". Retire : carte MariaDB, carte AD, carte Espace Disque, carte Queue Workers (+ bouton restart + lien workers). Le bouton "Actualiser" reste car les stats conservées peuvent encore évoluer.

**TD-05 — Partial `eleve` = Livewire SFC dédié, embedded dans le dispatcher via `@livewire('pages::home._partials.eleve')`** plutôt qu'un `@include` Blade pur, car il porte un état de formulaire (current_password, new_password, new_password_confirmation) et des actions wire:click. Logique :
- `auth()->user()` (Eloquent User) pour récupérer le login
- Validation : current_password requis, new_password ≥ 8 + `confirmed`
- Vérification du current via `app(AuthenticationService::class)->authenticate($login, $current, request()->ip())`
- Changement via `app(UserService::class)->changePasswordInAd($login, $new, mustChangeAtNextLogin: false)`
- Toast success/error via `WithToasts`

**TD-06 — Activity sub-page : déplacement, pas suppression.** `pages/dashboard/activity/index.blade.php` est déplacé sous `pages/home/activity/index.blade.php` ; la route `app.dashboard.activity` devient `app.home.activity`. Justification : les cards "Activité Récente" des partials `tech` et `standard` ont besoin d'un lien "Voir tout".

**TD-07 — Sidebar : lien visible pour tous les rôles, libellé "Accueil"**. Pas de filtrage par rôle dans la sidebar (chaque utilisateur a une vue `/home` adaptée à son rôle, donc le lien reste universel). Icône `<x-icons.dashboard>` conservée.

**TD-08 — `fallback restrictif`** : un user authentifié sans aucun rôle Spatie reconnu tombe dans le bucket `eleve`. Sécurité par défaut (rien d'opérationnel exposé). À documenter dans le test `test_user_without_any_known_role_falls_back_to_eleve`.

## Implementation Plan

### Tasks

**Stratégie d'ordre :** ajout du nouveau (additif, sans casser l'existant) → migration des références → suppression de l'ancien. Permet à tout moment de rollback la suppression sans perdre l'ajout.

- [ ] **Task 1 : Ajouter les nouvelles routes `/app/home` et `/app/home/activity` (sans toucher /dashboard)**
  - File : `routes/web.php`
  - Action : Dans le groupe `Route::prefix('app')->middleware('sambaedu.auth')->name('app.')`, ajouter immédiatement avant les routes `/dashboard` :
    ```php
    Route::livewire('/home', 'pages::home.index')->name('home');
    Route::livewire('/home/activity', 'pages::home.activity.index')->name('home.activity');
    ```
  - Notes : Les routes `/dashboard` et `/dashboard/activity` restent en place jusqu'à la Task 9 — coexistence temporaire.

- [ ] **Task 2 : Créer le dispatcher Livewire `pages/home/index.blade.php`**
  - File : `resources/views/pages/home/index.blade.php` (nouveau)
  - Action : Livewire SFC qui (a) charge dans `mount()` les données système (`$stats`, `$recentActivity`, `$mariaDbStatus`) UNIQUEMENT si bucket = `tech` ou `standard`, (b) résout `$bucket` via `auth()->user()->hasAnyRole(...)` selon TD-01, (c) expose `refreshStats()` et `restartQueueWorkers()` (méthodes héritées du dashboard, restartQueueWorkers gardée seulement utilisable depuis tech), (d) template = `<x-organisms.page title="Accueil" description="...">` qui `@include('pages.home._partials.' . $bucket, [...])` pour `tech`/`standard` ET `@livewire('pages::home._partials.eleve')` pour `eleve` (cf. TD-05).
  - Notes : reprendre intégralement les méthodes privées `getDashboardStats()`, `getRecentActivity()`, `getMariaDbStatus()`, `getQueueWorkerCount()` depuis `pages/dashboard/index.blade.php` lignes 53-134.

- [ ] **Task 3 : Créer le partial `tech.blade.php` (Blade pur)**
  - File : `resources/views/pages/home/_partials/tech.blade.php` (nouveau)
  - Action : Copier intégralement le **template** de `pages/dashboard/index.blade.php` lignes 138-360 (entre `<x-organisms.page>` et `</x-organisms.page>` exclus, donc juste le contenu interne). Remplacer `route('app.dashboard.activity')` par `route('app.home.activity')` ligne 298.
  - Notes : Le partial est un fichier Blade pur (pas de `<?php new class extends Component>`). Il consomme `$stats`, `$recentActivity`, `$mariaDbStatus` injectés par le dispatcher.

- [ ] **Task 4 : Créer le partial `standard.blade.php` (Blade pur, version allégée)**
  - File : `resources/views/pages/home/_partials/standard.blade.php` (nouveau)
  - Action : Copier `tech.blade.php` puis SUPPRIMER : (a) tout le bloc "Statut des services" (cartes MariaDB + AD, lignes 148-193 du dashboard original), (b) la carte "Espace disque" (lignes 228-244), (c) la carte "Queue Workers" complète y compris dropdown restart (lignes 246-293). CONSERVER : carte "Utilisateurs Actifs", carte "Machines en Ligne", grille "Activité Récente" + "Actions Rapides" (lignes 296-358). Le `<x-slot:actions>` avec bouton "Actualiser" reste.
  - Notes : Ajuster les classes grid si nécessaire (la grille `md:grid-cols-2 lg:grid-cols-3` passe à `md:grid-cols-2` car il ne reste que 2 cartes).

- [ ] **Task 5 : Créer le partial `eleve.blade.php` (Livewire SFC)**
  - File : `resources/views/pages/home/_partials/eleve.blade.php` (nouveau)
  - Action : Livewire SFC autonome avec :
    - Propriétés : `public string $current_password = '';`, `public string $new_password = '';`, `public string $new_password_confirmation = '';`
    - Méthode `changePassword()` qui :
      1. `$this->validate(['current_password' => 'required', 'new_password' => 'required|min:8|confirmed', 'new_password_confirmation' => 'required'])`
      2. `$user = auth()->user(); $login = $user->login;`
      3. `$result = app(\App\Services\AuthenticationService::class)->authenticate($login, $this->current_password, request()->ip());`
      4. Si `!$result['success']` : `$this->toastError('Le mot de passe actuel est incorrect'); return;`
      5. `$ok = app(\App\Services\UserService::class)->changePasswordInAd($login, $this->new_password, mustChangeAtNextLogin: false);`
      6. Si `$ok` : reset des champs + `$this->toastSuccess('Votre mot de passe a été modifié.');` sinon `$this->toastError('Erreur lors de la modification du mot de passe.');`
    - Template : `<x-organisms.page>` ? **Non** — le partial est embedded DANS le `<x-organisms.page>` du dispatcher. Donc le template du partial est juste un `<div>` contenant le formulaire (titre interne "Changer mon mot de passe", 3 inputs `wire:model`, bouton submit `wire:click="changePassword"` + `wire:loading.attr="disabled"`).
    - Use trait : `use App\Components\Traits\WithToasts;`
  - Notes : memory `feedback_blade_component_syntax.md` — ne pas utiliser `<livewire:components::...>` ; embed via `@livewire('pages::home._partials.eleve')` côté dispatcher (Task 2).

- [ ] **Task 6 : Déplacer la sous-page activity et mettre à jour son lien interne**
  - File : `resources/views/pages/dashboard/activity/index.blade.php` → `resources/views/pages/home/activity/index.blade.php`
  - Action : (a) `mv resources/views/pages/dashboard/activity/index.blade.php resources/views/pages/home/activity/index.blade.php`, (b) dans le fichier déplacé ligne 125, remplacer `route('app.dashboard')` par `route('app.home')` et le libellé "Retour dashboard" par "Retour accueil".
  - Notes : à ce stade il y a un dossier vide `pages/dashboard/activity/` qui sera supprimé en Task 10.

- [ ] **Task 7 : Mettre à jour les 9 références code → `route('app.home')` / `route('app.home.activity')`**
  - Files & lines :
    - `app/Http/Controllers/AuthController.php` lignes 44, 124, 137, 290, 336 : `route('app.dashboard')` → `route('app.home')`
    - `app/Http/Controllers/ChangePasswordController.php` ligne 106 : idem
    - `app/Http/Middleware/RedirectIfAuthenticated.php` ligne 24 : idem
    - `resources/views/errors/layout.blade.php` ligne 48 : idem
    - `resources/views/pages/workers/index.blade.php` ligne 33 : idem + libellé "Retour dashboard" → "Retour accueil"
  - Notes : `route('app.dashboard.activity')` côté `pages/dashboard/index.blade.php` ligne 298 sera traité par Task 3 (migration vers `tech.blade.php`).

- [ ] **Task 8 : Mettre à jour la sidebar**
  - File : `resources/views/components/organisms/sidebar.blade.php` lignes 16-20
  - Action : `route('app.dashboard')` → `route('app.home')` ; `request()->is('app/dashboard*')` → `request()->is('app/home*')` ; libellé "Tableau de bord" → "Accueil".
  - Notes : icône `<x-icons.dashboard>` conservée.

- [ ] **Task 9 : Supprimer les anciennes routes `/app/dashboard` et `/app/dashboard/activity`**
  - File : `routes/web.php` lignes 53-54
  - Action : supprimer les 2 lignes :
    ```php
    Route::livewire('/dashboard', 'pages::dashboard.index')->name('dashboard');
    Route::livewire('/dashboard/activity', 'pages::dashboard.activity.index')->name('dashboard.activity');
    ```
  - Notes : à faire APRÈS Task 7 et 8 (sinon liens cassés en cours de dev).

- [ ] **Task 10 : Supprimer les fichiers dashboard orphelins**
  - Files : `resources/views/pages/dashboard/index.blade.php` + `resources/views/pages/dashboard/activity/index.blade.php` (déjà déplacé en Task 6) + dossier `resources/views/pages/dashboard/` (devenu vide)
  - Action : `trash resources/views/pages/dashboard/` (memory `Sécurité` : utiliser `trash`, pas `rm -rf`).
  - Notes : la sous-page activity a déjà été MOVED en Task 6, donc le dossier ne contient plus que `index.blade.php` à ce stade.

- [ ] **Task 11 : Tests Feature Livewire pour le dispatcher et le form MDP éleve**
  - File : `tests/Feature/Livewire/HomeDispatcherTest.php` (nouveau)
  - Action : Reprendre le squelette de `tests/Feature/Livewire/RightsManagementPageTest.php` (`createTablesIfNeeded()` + `DatabaseTransactions`). Implémenter les 9 tests listés en Testing Strategy ci-dessous, attribut `#[Test]` (memory `feedback_phpunit_attributes.md`).
  - Notes : pour les tests du form éleve, mocker `UserService::changePasswordInAd` et `AuthenticationService::authenticate` via `$this->app->bind(...)` pour éviter les appels LDAP réels.

### Acceptance Criteria

- [ ] **AC1 — Tech bucket (rôles techniques).** Given un user authentifié avec le rôle Spatie `super-admin` (ou `technicien`, ou `referent-numerique`), when il visite `GET /app/home`, then la page rendue contient les cartes "MariaDB", "Active Directory", "Espace Disque" et "Queue Workers" (avec bouton "Redémarrer les workers").

- [ ] **AC2 — Standard bucket (autres rôles).** Given un user authentifié avec un rôle parmi `prof`, `user-admin`, `computer-admin`, `share-admin`, `eleve-admin` (et aucun rôle technique), when il visite `GET /app/home`, then la page contient "Utilisateurs Actifs" et "Machines en Ligne" mais NE contient PAS "MariaDB", ni "Espace Disque", ni "Queue Workers", ni le bouton "Redémarrer les workers".

- [ ] **AC3 — Eleve bucket.** Given un user authentifié avec uniquement le rôle `eleve`, when il visite `GET /app/home`, then la page rendue contient un formulaire de changement de mot de passe avec exactement 3 champs (`current_password`, `new_password`, `new_password_confirmation`) et NE contient AUCUNE des cartes système.

- [ ] **AC4 — Priorité multi-rôles.** Given un user cumulant `prof` + `super-admin`, when il visite `GET /app/home`, then le bucket résolu est `tech` (priorité descendante : tech > eleve > standard > fallback).

- [ ] **AC5 — Fallback restrictif.** Given un user authentifié sans aucun rôle Spatie reconnu (par ex. uniquement `administratif`, ou aucun rôle), when il visite `GET /app/home`, then le partial `eleve` est rendu (form MDP uniquement, pas de cartes système).

- [ ] **AC6 — Changement MDP éleve happy path.** Given un user éleve sur `/app/home`, when il soumet `current_password=correct`, `new_password=NewSecret123`, `new_password_confirmation=NewSecret123`, then `UserService::changePasswordInAd($login, 'NewSecret123', mustChangeAtNextLogin: false)` est appelé et un toast success "Votre mot de passe a été modifié." est émis.

- [ ] **AC7 — MDP actuel invalide.** Given un user éleve, when il soumet un `current_password` qui échoue à `AuthenticationService::authenticate()`, then `UserService::changePasswordInAd` n'est PAS appelé et un toast error "Le mot de passe actuel est incorrect" est émis.

- [ ] **AC8 — Validation new_password trop court.** Given un user éleve, when il soumet `new_password=abc` (< 8 caractères), then la validation Livewire échoue avec une erreur sur `new_password` et `changePasswordInAd` n'est PAS appelé.

- [ ] **AC9 — Confirmation non concordante.** Given un user éleve, when il soumet `new_password=NewSecret123` et `new_password_confirmation=Different123`, then la validation échoue avec une erreur `confirmed` sur `new_password`.

- [ ] **AC10 — Anciennes routes 404.** Given un user authentifié, when il visite `GET /app/dashboard`, then la réponse est 404 (route supprimée).

- [ ] **AC11 — Nouvelle route activity opérationnelle.** Given un user authentifié, when il visite `GET /app/home/activity`, then la page d'activité est rendue avec sa table paginée et son back-link "Retour accueil" pointant vers `/app/home`.

- [ ] **AC12 — Sidebar mise à jour.** Given un user authentifié sur `/app/home`, when la sidebar est rendue, then le lien affiche "Accueil" (pas "Tableau de bord"), pointe vers `/app/home`, et est marqué actif (classe `active bg-primary/20 text-primary`).

- [ ] **AC13 — Redirections post-login.** Given un login réussi via `AuthController::authenticate` (ou les flux CAS, ENT, change-password), when la redirection finale est calculée, then l'URL cible est `route('app.home')` et plus jamais `route('app.dashboard')`.

## Additional Context

### Dependencies

- **Spatie Laravel Permission** (déjà installé) — fournit `HasRoles` et `hasAnyRole()`.
- **LdapRecord** (déjà installé) — utilisé par `UserService::changePasswordInAd`.
- **Aucune nouvelle dépendance**.
- **Pas de migration DB** requise.
- **Pas de seeder à modifier** (les rôles Spatie utilisés existent déjà via `PermissionSeeder` — issu de `SambaRole` enum).

### Testing Strategy

**Tests Feature Livewire** dans `tests/Feature/Livewire/HomeDispatcherTest.php` :

1. `#[Test] super_admin_renders_tech_partial` — assigne `super-admin`, charge `pages::home.index`, vérifie `assertSee('MariaDB')`, `assertSee('Espace Disque')`, `assertSee('Queue Workers')`.
2. `#[Test] technicien_renders_tech_partial` — idem avec rôle `technicien`.
3. `#[Test] referent_numerique_renders_tech_partial` — idem avec `referent-numerique`.
4. `#[Test] prof_renders_standard_partial` — assigne `prof`, vérifie `assertSee('Utilisateurs Actifs')`, `assertDontSee('MariaDB')`, `assertDontSee('Queue Workers')`, `assertDontSee('Espace Disque')`.
5. `#[Test] eleve_admin_renders_standard_partial` — idem avec `eleve-admin`.
6. `#[Test] eleve_renders_password_form_only` — assigne `eleve`, vérifie présence des inputs `current_password`/`new_password`/`new_password_confirmation` ET absence de "Utilisateurs Actifs"/"MariaDB".
7. `#[Test] multi_role_uses_priority_tech_over_standard` — assigne `prof` + `super-admin`, vérifie tech rendu.
8. `#[Test] no_role_falls_back_to_eleve_partial` — user sans rôle, vérifie partial éleve rendu.
9. `#[Test] eleve_change_password_calls_user_service_with_must_change_false` — bind mock `UserService` + mock `AuthenticationService` (success), `Livewire::test('pages::home._partials.eleve')->set('current_password', 'ok')->set('new_password', 'NewSecret123')->set('new_password_confirmation', 'NewSecret123')->call('changePassword')`, asserter sur l'appel mock + `assertHasNoErrors()` + `assertSet('new_password', '')`.
10. `#[Test] eleve_change_password_rejects_wrong_current` — mock auth qui retourne `['success' => false]`, asserter pas d'appel à `changePasswordInAd`.
11. `#[Test] eleve_change_password_validates_min_length_and_confirmation` — validation pure sans mock LDAP.

**Tests d'intégration des redirections** : à vérifier en Step 4 — si `tests/Feature/Auth/AuthControllerTest.php` existe déjà avec assertion `redirected->route('app.dashboard')`, mettre à jour vers `app.home` (sinon hors scope ici).

**Manual smoke test sur la VM (`/vm`)** :
1. Login avec un compte super-admin → atterrir sur `/app/home`, voir les cartes système.
2. Login avec un compte prof → `/app/home`, voir version allégée.
3. Login avec un compte éleve → `/app/home`, voir le form MDP, soumettre, vérifier le toast.
4. Visiter `/app/dashboard` → 404.
5. Cliquer "Accueil" dans la sidebar → atterrissage `/app/home`, lien marqué actif.

### Notes

**Memories appliquées :**
- `auth_uses_eloquent_user.md` : `auth()->user()` retourne directement un `User` Eloquent depuis 2026-04-24 → safe d'appeler `->hasRole()` directement sans wrapper.
- `feedback_blade_component_syntax.md` : utiliser `<x-organisms.page>`, jamais `<livewire:components::...>`.
- `feedback_phpunit_attributes.md` : tests avec `#[Test]`, jamais `@test`.
- `Sécurité` (CLAUDE.md global) : utiliser `trash` (pas `rm -rf`) en Task 10.
- `feedback_no_rsync.md` : aucune commande rsync ; les modifs sont auto-syncées vers la VM `/vm`.

**Risques pré-mortem :**
- **R1 — Dispatcher Livewire qui charge des stats lourdes (disk, queue, MariaDB) inutilement pour les buckets `standard`/`eleve`.** Mitigation : dans `mount()`, n'appeler `getDashboardStats()` / `getMariaDbStatus()` QUE si `$bucket === 'tech'` ou `$bucket === 'standard'` (et pour `standard`, ne pas appeler `getMariaDbStatus()` ni `getQueueWorkerCount()` non plus).
- **R2 — `restartQueueWorkers()` exposé côté serveur même en bucket `standard`/`eleve`.** Mitigation : dans la méthode, vérifier `if ($this->bucket !== 'tech') { abort(403); }` en début de fonction.
- **R3 — Activity sub-page accessible à tous les rôles** (la route n'a pas de `middleware can:`). Pas une régression (le legacy était identique), mais à signaler comme tech-debt potentiel hors scope.
- **R4 — Tests qui touchent l'AD réel via `UserService::changePasswordInAd`.** Mitigation : binding via `$this->app->instance(UserService::class, $mock)` dans le setUp de chaque test concerné.
- **R5 — Si la session `sambaedu_user` est consommée par les partials migrés** (cf. `dashboard/index.blade.php` ligne 28 `session('sambaedu_user', [])`), elle doit toujours être disponible côté `home/index.blade.php` (même middleware `sambaedu.auth`). À vérifier en smoke test.

**Limitations connues :**
- Le rôle `administratif` n'est PAS reconnu par le dispatcher → tombe dans le fallback `eleve`. Henri à arbitrer en Step 4 si problématique pour les comptes administratifs réels.
- Stories 14.4 / 14.5 / 14.6 (contenu admin avancé) sortent du scope. Cette spec produit la **structure** ; les contenus enrichis viendront ensuite via une nouvelle story qui modifiera `tech.blade.php`.

**Future considerations (out of scope mais à noter) :**
- Si `administratif` doit avoir sa propre vue : ajouter un 4ème bucket entre `standard` et `eleve` dans le `match`, créer `_partials/administratif.blade.php`. Coût ~1h.
- Si la sidebar doit afficher des liens différents par bucket : factoriser le calcul du bucket dans un Service `HomeRoleResolver` réutilisable depuis la sidebar.
- Le composant `pages/dashboard/index.blade.php` étant supprimé, sa réutilisation initialement prévue "pour le refnum" est de fait réalisée via le bucket `tech`.
