# Plan de Gestion des Droits SE4

## Vue d'ensemble

Ce document décrit l'architecture unifiée pour gérer les **permissions globales** et les **délégations scopées** dans SE4, compatible avec les Policies Laravel et la base de données SQL.

---

## 1. Concepts Clés

### 1.1 Permissions Globales
Droits système qui s'appliquent **partout**, indépendamment d'une ressource spécifique.

| Exemple | Description |
|---------|-------------|
| `admin.access` | Accès à l'interface d'administration |
| `users.view_all` | Voir tous les utilisateurs |
| `machines.manage_all` | Gérer toutes les machines |

### 1.2 Délégations (Droits Scopés)
Droits attribués sur une **ressource spécifique** (parc, salle, groupe).

| Exemple | Description |
|---------|-------------|
| `view` sur `Salle-Info-101` | Voir les machines de cette salle |
| `control` sur `Parc-Portables` | Contrôler les machines de ce parc |
| `wpkg_assign` sur `CDI` | Assigner des apps WPKG au CDI |

### 1.3 Hiérarchie des droits

```
┌─────────────────────────────────────────────────────────────┐
│                    SUPER ADMIN                               │
│              (tous les droits, partout)                      │
└─────────────────────────────────────────────────────────────┘
                              │
┌─────────────────────────────────────────────────────────────┐
│                 PERMISSIONS GLOBALES                         │
│   Ex: "machines.manage_all" → toutes les machines           │
└─────────────────────────────────────────────────────────────┘
                              │
┌─────────────────────────────────────────────────────────────┐
│                    DÉLÉGATIONS                               │
│   Ex: "control" sur "Salle-Info-101" → machines de la salle │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Modèle de Données

### 2.1 Schéma des tables

```
┌─────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   users     │────<│  model_has_     │>────│   permissions   │
│             │     │  permissions    │     │                 │
└─────────────┘     └─────────────────┘     └─────────────────┘
       │                                            │
       │            ┌─────────────────┐             │
       └───────────<│ model_has_roles │>────────────┤
                    └─────────────────┘             │
                            │                       │
                    ┌───────┴───────┐               │
                    │     roles     │───────────────┘
                    └───────────────┘
                            │
                    ┌───────┴───────────┐
                    │ role_has_         │
                    │ permissions       │
                    └───────────────────┘

                    ┌───────────────────┐
                    │   delegations     │  ← Droits scopés par ressource
                    └───────────────────┘
                            │
                    ┌───────┴───────────┐
                    │delegation_profiles│  ← Profils de délégation
                    └───────────────────┘
```

### 2.2 Table `permissions`

```sql
CREATE TABLE permissions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(125) NOT NULL UNIQUE,      -- Ex: 'users.view', 'machines.power_on'
    guard_name VARCHAR(125) DEFAULT 'web',
    description TEXT,
    category VARCHAR(50),                    -- Ex: 'users', 'machines', 'apps'
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### 2.3 Table `roles`

```sql
CREATE TABLE roles (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(125) NOT NULL UNIQUE,       -- Ex: 'admin', 'teacher', 'technician'
    guard_name VARCHAR(125) DEFAULT 'web',
    description TEXT,
    is_system BOOLEAN DEFAULT FALSE,         -- Rôles système non supprimables
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### 2.4 Table `delegations` (existante, à enrichir)

```sql
CREATE TABLE delegations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    grantee_type VARCHAR(50) NOT NULL,       -- 'user' ou 'group'
    grantee_id VARCHAR(255) NOT NULL,        -- sam_account_name ou DN du groupe
    resource_type VARCHAR(50) NOT NULL,      -- 'parc', 'salle', 'ou'
    resource_id VARCHAR(255) NOT NULL,       -- CN ou DN de la ressource
    profile_id BIGINT NOT NULL,              -- FK vers delegation_profiles
    is_negative BOOLEAN DEFAULT FALSE,       -- Exclusion explicite
    granted_by VARCHAR(255),                 -- Qui a accordé la délégation
    expires_at TIMESTAMP NULL,               -- Expiration optionnelle
    ad_sync_pending BOOLEAN DEFAULT FALSE,   -- Sync GPO nécessaire
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (profile_id) REFERENCES delegation_profiles(id),
    UNIQUE KEY unique_delegation (grantee_type, grantee_id, resource_type, resource_id, profile_id)
);
```

### 2.5 Table `delegation_profiles`

```sql
CREATE TABLE delegation_profiles (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL UNIQUE,        -- Ex: 'view', 'control', 'full_admin'
    display_name VARCHAR(100),               -- Ex: 'Visualisation', 'Contrôle'
    description TEXT,
    permissions JSON,                        -- Permissions incluses dans ce profil
    requires_ad_sync BOOLEAN DEFAULT FALSE,  -- Nécessite sync GPO (ex: elevate)
    legacy_constant INT,                     -- Compatibilité SE_COMPUTER_* legacy
    level INT DEFAULT 0,                     -- Niveau hiérarchique (0=view, 100=full)
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## 3. Permissions Prédéfinies

### 3.1 Catégorie `users`

| Permission | Description |
|------------|-------------|
| `users.view` | Voir la liste des utilisateurs |
| `users.view_details` | Voir les détails d'un utilisateur |
| `users.create` | Créer un utilisateur |
| `users.edit` | Modifier un utilisateur |
| `users.delete` | Supprimer un utilisateur |
| `users.manage_all` | Gestion complète de tous les utilisateurs |

### 3.2 Catégorie `machines`

| Permission | Description |
|------------|-------------|
| `machines.view` | Voir la liste des machines |
| `machines.view_details` | Voir les détails d'une machine |
| `machines.power_on` | Allumer une machine (WoL) |
| `machines.power_off` | Éteindre une machine |
| `machines.reboot` | Redémarrer une machine |
| `machines.create` | Ajouter une machine |
| `machines.edit` | Modifier une machine |
| `machines.delete` | Supprimer une machine |
| `machines.manage_all` | Gestion complète de toutes les machines |

### 3.3 Catégorie `parcs`

| Permission | Description |
|------------|-------------|
| `parcs.view` | Voir la liste des parcs/salles |
| `parcs.create` | Créer un parc/salle |
| `parcs.edit` | Modifier un parc/salle |
| `parcs.delete` | Supprimer un parc/salle |
| `parcs.assign_machines` | Assigner des machines à un parc |
| `parcs.manage_all` | Gestion complète de tous les parcs |

### 3.4 Catégorie `apps`

| Permission | Description |
|------------|-------------|
| `apps.view` | Voir la liste des applications |
| `apps.create` | Ajouter une application |
| `apps.edit` | Modifier une application |
| `apps.delete` | Supprimer une application |
| `apps.install` | Installer une application |
| `apps.assign` | Assigner une app à un parc/machine |
| `apps.manage_all` | Gestion complète de toutes les apps |

### 3.5 Catégorie `groups`

| Permission | Description |
|------------|-------------|
| `groups.view` | Voir la liste des groupes |
| `groups.create` | Créer un groupe |
| `groups.edit` | Modifier un groupe |
| `groups.delete` | Supprimer un groupe |
| `groups.manage_members` | Gérer les membres d'un groupe |
| `groups.manage_all` | Gestion complète de tous les groupes |

### 3.6 Catégorie `admin`

| Permission | Description |
|------------|-------------|
| `admin.access` | Accès à l'interface d'administration |
| `admin.settings` | Modifier les paramètres système |
| `admin.logs` | Voir les logs système |
| `admin.delegations` | Gérer les délégations |
| `admin.roles` | Gérer les rôles et permissions |

---

## 4. Profils de Délégation

### 4.1 Profils prédéfinis (compatibles legacy)

| Profil | Level | Permissions incluses | Legacy |
|--------|-------|---------------------|--------|
| `view` | 10 | Voir les machines du scope | SE_COMPUTER_VIEW |
| `control` | 20 | view + allumer/éteindre/redémarrer | SE_COMPUTER_CONTROL |
| `elevate` | 30 | control + admin local (GPO) | SE_COMPUTER_ELEVATE |
| `install` | 40 | elevate + installer des apps | SE_COMPUTER_INSTALL |
| `wpkg_assign` | 50 | Assigner des apps WPKG | SE_WPKG_ASSIGN |
| `wpkg_full` | 60 | wpkg_assign + gérer les apps | SE_WPKG_FULL |
| `full_admin` | 100 | Tous les droits sur le scope | SE_COMPUTER_ADMIN |

### 4.2 Détail des profils

```php
// delegation_profiles seeder
[
    'name' => 'view',
    'display_name' => 'Visualisation',
    'description' => 'Peut voir les machines et leur statut',
    'permissions' => ['machines.view', 'machines.view_details'],
    'requires_ad_sync' => false,
    'legacy_constant' => 1, // SE_COMPUTER_VIEW
    'level' => 10,
],
[
    'name' => 'control',
    'display_name' => 'Contrôle',
    'description' => 'Peut allumer, éteindre et redémarrer les machines',
    'permissions' => ['machines.view', 'machines.view_details', 'machines.power_on', 'machines.power_off', 'machines.reboot'],
    'requires_ad_sync' => false,
    'legacy_constant' => 2, // SE_COMPUTER_CONTROL
    'level' => 20,
],
[
    'name' => 'elevate',
    'display_name' => 'Élévation',
    'description' => 'Droits administrateur local sur les machines (via GPO)',
    'permissions' => ['machines.view', 'machines.view_details', 'machines.power_on', 'machines.power_off', 'machines.reboot', 'machines.elevate'],
    'requires_ad_sync' => true, // Nécessite création GPO
    'legacy_constant' => 4, // SE_COMPUTER_ELEVATE
    'level' => 30,
],
[
    'name' => 'install',
    'display_name' => 'Installation',
    'description' => 'Peut installer des applications sur les machines',
    'permissions' => ['machines.view', 'machines.view_details', 'machines.power_on', 'machines.power_off', 'machines.reboot', 'machines.elevate', 'apps.install'],
    'requires_ad_sync' => true,
    'legacy_constant' => 8, // SE_COMPUTER_INSTALL
    'level' => 40,
],
[
    'name' => 'wpkg_assign',
    'display_name' => 'Assignation WPKG',
    'description' => 'Peut assigner des applications WPKG aux machines',
    'permissions' => ['apps.view', 'apps.assign'],
    'requires_ad_sync' => false,
    'legacy_constant' => 16, // SE_WPKG_ASSIGN
    'level' => 50,
],
[
    'name' => 'wpkg_full',
    'display_name' => 'Gestion WPKG complète',
    'description' => 'Peut gérer et assigner les applications WPKG',
    'permissions' => ['apps.view', 'apps.create', 'apps.edit', 'apps.delete', 'apps.assign'],
    'requires_ad_sync' => false,
    'legacy_constant' => 32, // SE_WPKG_FULL
    'level' => 60,
],
[
    'name' => 'full_admin',
    'display_name' => 'Administration complète',
    'description' => 'Tous les droits sur les ressources du scope',
    'permissions' => ['*'], // Toutes les permissions du scope
    'requires_ad_sync' => true,
    'legacy_constant' => 64, // SE_COMPUTER_ADMIN
    'level' => 100,
],
```

---

## 5. Rôles Prédéfinis

### 5.1 Rôles système

| Rôle | Description | Permissions globales |
|------|-------------|---------------------|
| `super_admin` | Accès total | Toutes |
| `admin` | Administrateur | `admin.*`, `users.manage_all`, `machines.manage_all`, `parcs.manage_all`, `apps.manage_all`, `groups.manage_all` |
| `technician` | Technicien | `machines.*`, `parcs.*`, `apps.*` |
| `teacher` | Enseignant | `users.view`, `machines.view`, `parcs.view`, `apps.view` |
| `student` | Élève | `users.view` (soi-même) |

### 5.2 Rôles personnalisables

Les administrateurs peuvent créer des rôles personnalisés en combinant les permissions disponibles.

---

## 6. Intégration Laravel

### 6.1 Package recommandé

**Spatie/laravel-permission** pour les permissions globales et rôles.

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

### 6.2 Trait sur le modèle User

```php
// app/Models/User.php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
    
    /**
     * Vérifie si l'utilisateur a une délégation sur une ressource
     */
    public function hasDelegation(string $profile, string $resourceType, string $resourceId): bool
    {
        return app(DelegationService::class)->userHasDelegation(
            $this,
            $profile,
            $resourceType,
            $resourceId
        );
    }
    
    /**
     * Vérifie une permission globale OU une délégation scopée
     */
    public function canOnResource(string $permission, string $resourceType, string $resourceId): bool
    {
        // 1. Permission globale "manage_all" ?
        $globalPermission = str_replace('.', '.manage_all_', $permission) . 's';
        if ($this->hasPermissionTo($globalPermission)) {
            return true;
        }
        
        // 2. Délégation sur la ressource ?
        return $this->hasDelegationForPermission($permission, $resourceType, $resourceId);
    }
}
```

### 6.3 Service de Délégation

```php
// app/Services/Rights/DelegationService.php
namespace App\Services\Rights;

use App\Models\User;
use App\Models\Delegation;
use App\Models\DelegationProfile;
use Illuminate\Support\Facades\Cache;

class DelegationService
{
    /**
     * Vérifie si un utilisateur a une délégation
     */
    public function userHasDelegation(
        User $user,
        string $profileName,
        string $resourceType,
        string $resourceId
    ): bool {
        $cacheKey = "delegation:{$user->sam_account_name}:{$profileName}:{$resourceType}:{$resourceId}";
        
        return Cache::remember($cacheKey, 300, function () use ($user, $profileName, $resourceType, $resourceId) {
            $profile = DelegationProfile::where('name', $profileName)->first();
            if (!$profile) {
                return false;
            }
            
            // Délégation directe sur l'utilisateur
            $directDelegation = Delegation::where('grantee_type', 'user')
                ->where('grantee_id', $user->sam_account_name)
                ->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)
                ->where('profile_id', '>=', $profile->id)
                ->where('is_negative', false)
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now())
                ->exists();
            
            if ($directDelegation) {
                return true;
            }
            
            // Délégation via groupe AD
            $userGroups = $user->member_of ?? [];
            
            return Delegation::where('grantee_type', 'group')
                ->whereIn('grantee_id', $userGroups)
                ->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)
                ->where('profile_id', '>=', $profile->id)
                ->where('is_negative', false)
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now())
                ->exists();
        });
    }
    
    /**
     * Récupère toutes les ressources sur lesquelles l'utilisateur a une délégation
     */
    public function getUserDelegatedResources(User $user, string $resourceType = null): Collection
    {
        $query = Delegation::where(function ($q) use ($user) {
            $q->where(function ($q2) use ($user) {
                $q2->where('grantee_type', 'user')
                   ->where('grantee_id', $user->sam_account_name);
            })->orWhere(function ($q2) use ($user) {
                $q2->where('grantee_type', 'group')
                   ->whereIn('grantee_id', $user->member_of ?? []);
            });
        })
        ->where('is_negative', false)
        ->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
        
        if ($resourceType) {
            $query->where('resource_type', $resourceType);
        }
        
        return $query->with('profile')->get();
    }
}
```

### 6.4 Policies avec délégations

```php
// app/Policies/MachinePolicy.php
namespace App\Policies;

use App\Models\User;
use App\Models\Machine;
use App\Services\Rights\DelegationService;

class MachinePolicy
{
    public function __construct(
        private DelegationService $delegationService
    ) {}
    
    /**
     * Voir une machine
     */
    public function view(User $user, Machine $machine): bool
    {
        // Permission globale
        if ($user->hasPermissionTo('machines.view_all')) {
            return true;
        }
        
        // Délégation "view" sur le parc/salle de la machine
        return $this->hasDelegationOnMachine($user, $machine, 'view');
    }
    
    /**
     * Allumer une machine
     */
    public function powerOn(User $user, Machine $machine): bool
    {
        if ($user->hasPermissionTo('machines.manage_all')) {
            return true;
        }
        
        return $this->hasDelegationOnMachine($user, $machine, 'control');
    }
    
    /**
     * Éteindre une machine
     */
    public function powerOff(User $user, Machine $machine): bool
    {
        return $this->powerOn($user, $machine);
    }
    
    /**
     * Installer une application sur une machine
     */
    public function install(User $user, Machine $machine): bool
    {
        if ($user->hasPermissionTo('machines.manage_all')) {
            return true;
        }
        
        return $this->hasDelegationOnMachine($user, $machine, 'install');
    }
    
    /**
     * Vérifie la délégation sur tous les parcs/salles de la machine
     */
    private function hasDelegationOnMachine(User $user, Machine $machine, string $profile): bool
    {
        // Vérifier la salle (OU) de la machine
        if ($machine->ou_dn) {
            if ($this->delegationService->userHasDelegation($user, $profile, 'salle', $machine->ou_dn)) {
                return true;
            }
        }
        
        // Vérifier les parcs (groupes) dont la machine est membre
        foreach ($machine->parcs as $parc) {
            if ($this->delegationService->userHasDelegation($user, $profile, 'parc', $parc->cn)) {
                return true;
            }
        }
        
        return false;
    }
}
```

### 6.5 Middleware pour les routes

```php
// app/Http/Middleware/CheckPermissionOrDelegation.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermissionOrDelegation
{
    public function handle(Request $request, Closure $next, string $permission, string $resourceParam = null)
    {
        $user = $request->user();
        
        if (!$user) {
            abort(403);
        }
        
        // Permission globale ?
        if ($user->hasPermissionTo($permission)) {
            return $next($request);
        }
        
        // Si un paramètre de ressource est spécifié, vérifier la délégation
        if ($resourceParam && $request->route($resourceParam)) {
            $resource = $request->route($resourceParam);
            // Logique de vérification de délégation...
        }
        
        abort(403, 'Accès non autorisé');
    }
}
```

---

## 7. Interface d'Administration

### 7.1 Pages à créer

| Route | Description |
|-------|-------------|
| `/admin/rights` | Dashboard des droits |
| `/admin/rights/roles` | Gestion des rôles |
| `/admin/rights/permissions` | Liste des permissions |
| `/admin/rights/delegations` | Gestion des délégations |
| `/admin/rights/users/{id}` | Droits d'un utilisateur |

### 7.2 Fonctionnalités

- **Créer/modifier des rôles** avec sélection des permissions
- **Attribuer des rôles** aux utilisateurs
- **Créer des délégations** : utilisateur/groupe → profil → ressource
- **Visualiser les droits effectifs** d'un utilisateur (permissions + délégations)
- **Audit** : historique des modifications de droits

---

## 8. Compatibilité Legacy

### 8.1 Wrapper pour le code legacy

```php
// includes/rights_compat.inc.php

/**
 * Wrapper compatible avec have_right() legacy
 */
function have_delegation(array $config, int $rights, $user, string $parc): bool
{
    // Charger Laravel si nécessaire
    if (!app()->bound('delegation.service')) {
        return false;
    }
    
    $delegationService = app('delegation.service');
    $login = is_array($user) ? ($user['cn'] ?? $user['samaccountname'] ?? '') : $user;
    
    // Mapper les constantes legacy vers les profils
    $profileMap = [
        SE_COMPUTER_VIEW => 'view',
        SE_COMPUTER_CONTROL => 'control',
        SE_COMPUTER_ELEVATE => 'elevate',
        SE_COMPUTER_INSTALL => 'install',
        SE_WPKG_ASSIGN => 'wpkg_assign',
        SE_WPKG_FULL => 'wpkg_full',
        SE_COMPUTER_ADMIN => 'full_admin',
    ];
    
    foreach ($profileMap as $constant => $profile) {
        if ($rights & $constant) {
            if ($delegationService->userHasDelegationByLogin($login, $profile, 'parc', $parc)) {
                return true;
            }
        }
    }
    
    return false;
}
```

### 8.2 Migration des données legacy

```php
// Migration des délégations AD existantes vers la table SQL
// À exécuter une fois lors de la migration

$legacyDelegations = $ldapService->getAllDelegations();

foreach ($legacyDelegations as $delegation) {
    Delegation::updateOrCreate(
        [
            'grantee_type' => $delegation['type'],
            'grantee_id' => $delegation['grantee'],
            'resource_type' => 'parc',
            'resource_id' => $delegation['parc'],
        ],
        [
            'profile_id' => DelegationProfile::where('legacy_constant', $delegation['rights'])->first()->id,
            'granted_by' => 'migration',
        ]
    );
}
```

---

## 9. Plan d'Implémentation

### Phase 1 : Fondations (1-2 jours)
- [ ] Installer Spatie/laravel-permission
- [ ] Créer les migrations pour `delegation_profiles` et `delegations`
- [ ] Créer les seeders pour permissions et profils prédéfinis
- [ ] Créer les modèles `Delegation` et `DelegationProfile`

### Phase 2 : Services (2-3 jours)
- [ ] Créer `DelegationService`
- [ ] Créer `RightsService` (orchestrateur)
- [ ] Ajouter les méthodes sur le modèle `User`
- [ ] Créer les Policies principales (Machine, Parc, App, User)

### Phase 3 : Interface Admin (3-4 jours)
- [ ] Page de gestion des rôles
- [ ] Page de gestion des délégations
- [ ] Page de visualisation des droits d'un utilisateur
- [ ] Composants Livewire pour l'attribution de droits

### Phase 4 : Compatibilité Legacy (1-2 jours)
- [ ] Créer le wrapper `have_delegation()`
- [ ] Migrer les délégations AD existantes
- [ ] Tests de non-régression

### Phase 5 : Sync AD (optionnel, 2-3 jours)
- [ ] Service de création GPO pour `elevate`
- [ ] Job asynchrone de synchronisation
- [ ] Interface de monitoring de la sync

---

## 10. Exemple d'Utilisation

### Dans un contrôleur

```php
public function powerOn(Machine $machine)
{
    $this->authorize('powerOn', $machine);
    
    // Action...
}
```

### Dans une vue Blade

```blade
@can('powerOn', $machine)
    <button wire:click="powerOn">Allumer</button>
@endcan

@if(auth()->user()->hasDelegation('control', 'parc', $parc->cn))
    <span class="badge badge-success">Contrôle délégué</span>
@endif
```

### Dans un composant Livewire

```php
public function powerOn(int $machineId)
{
    $machine = Machine::findOrFail($machineId);
    
    if (!auth()->user()->canOnResource('machines.power_on', 'parc', $machine->parc->cn)) {
        throw new AuthorizationException('Accès non autorisé');
    }
    
    // Action...
}
```

---

## 11. Résumé

| Aspect | Solution |
|--------|----------|
| **Permissions globales** | Spatie/laravel-permission |
| **Délégations scopées** | Table `delegations` custom |
| **Autorisation** | Policies Laravel combinant les deux |
| **Héritage groupes** | Calcul dynamique via `member_of` |
| **Compatibilité legacy** | Wrapper `have_delegation()` |
| **Sync AD** | Jobs asynchrones pour GPO |
| **Cache** | Laravel Cache (5 min TTL) |
| **Audit** | Table `delegation_audit_logs` |

Cette architecture permet une gestion fine des droits tout en maintenant la compatibilité avec le système legacy SE4.
