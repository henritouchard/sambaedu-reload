# Sécurité et gestion des droits

Ce document explique comment implémenter la gestion des droits dans l'application Laravel SE4FS en utilisant les Policies Laravel et le système de droits legacy SambaEdu.

## Bonnes pratiques à vérifier à chauqe fois

- ✅ Toujours protéger le backend, même si le frontend est protégé
- ✅ Utiliser des noms de gates cohérents : `{action}-{resource}`
- ✅ Centraliser la logique de droits dans les Policies
- ✅ Logger les tentatives d'accès non autorisées
- ✅ Utiliser le trait `WithToasts` pour les messages utilisateur
- ❌ Ne jamais faire confiance uniquement au frontend
- ❌ Ne pas dupliquer la logique de droits dans plusieurs endroits

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         FRONTEND                                │
│  @can('action-resource') → Masque/affiche les éléments UI      │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                         BACKEND                                 │
│  Gate::denies('action-resource') → Bloque l'action             │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                         POLICY                                  │
│  Vérifie les droits via le système legacy (have_right)         │
└─────────────────────────────────────────────────────────────────┘
```

## Étape 1 : Créer une Policy

Les Policies sont des classes qui encapsulent la logique d'autorisation pour un modèle ou une ressource.

### Emplacement
```
app/Policies/NomDeLaRessourcePolicy.php
```

### Exemple : ShortcutPolicy

#### Via artisan (recommandé) 

```bash
php artisan make:policy ShortcutPolicy --model=Shortcut
```


```php
<?php

namespace App\Policies;

use App\Models\AuthUser;
use App\Services\SE4\ConfigurationService;
use Illuminate\Support\Facades\Log;

class ShortcutPolicy
{
    private ConfigurationService $configService;

    public function __construct(ConfigurationService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * Détermine si l'utilisateur peut voir les raccourcis
     */
    public function viewAny(?AuthUser $user): bool
    {
        return $this->hasComputerAdminRights($user);
    }

    /**
     * Détermine si l'utilisateur peut créer un raccourci
     */
    public function create(?AuthUser $user): bool
    {
        return $this->hasComputerAdminRights($user);
    }

    /**
     * Détermine si l'utilisateur peut modifier un raccourci
     */
    public function update(?AuthUser $user): bool
    {
        return $this->hasComputerAdminRights($user);
    }

    /**
     * Détermine si l'utilisateur peut supprimer un raccourci
     */
    public function delete(?AuthUser $user): bool
    {
        return $this->hasComputerAdminRights($user);
    }

    /**
     * Vérifie les droits via le système legacy
     */
    private function hasComputerAdminRights(?AuthUser $user): bool
    {
        try {
            // Récupérer le login depuis l'utilisateur Laravel ou la session
            $login = $user?->getLogin() ?? $_SESSION['login'] ?? null;

            if (!$login) {
                return false;
            }

            $config = $this->configService->getConfig();

            if (!isset($config['bind']) || $config['bind'] === null) {
                return false;
            }

            // Utiliser les fonctions legacy pour vérifier les droits
            $userInfo = search_user($config, "(cn=$login)");
            
            if (empty($userInfo)) {
                return false;
            }

            // SE_COMPUTER_ADMIN est la constante de droits pour la gestion des postes
            return have_right($config, SE_COMPUTER_ADMIN);

        } catch (\Exception $e) {
            Log::error('Policy rights check error: ' . $e->getMessage());
            return false;
        }
    }
}
```

### Constantes de droits SambaEdu disponibles

| Constante | Description |
|-----------|-------------|
| `SE_USER_ADMIN` | Administration des utilisateurs |
| `SE_COMPUTER_ADMIN` | Administration des postes/machines |
| `SE_WPKG_ADMIN` | Administration WPKG |
| `SE_PRINTER_ADMIN` | Administration des imprimantes |

## Étape 2 : Enregistrer les Gates dans AuthServiceProvider

Les Gates sont des closures qui déterminent si un utilisateur est autorisé à effectuer une action.

### Fichier : `app/Providers/AuthServiceProvider.php`

```php
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Policies\ShortcutPolicy;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        // Mapping Model => Policy si nécessaire
    ];

    public function boot(): void
    {
        // Enregistrer les gates pour les raccourcis
        Gate::define('viewAny-shortcut', [ShortcutPolicy::class, 'viewAny']);
        Gate::define('create-shortcut', [ShortcutPolicy::class, 'create']);
        Gate::define('update-shortcut', [ShortcutPolicy::class, 'update']);
        Gate::define('delete-shortcut', [ShortcutPolicy::class, 'delete']);
        Gate::define('bulkDelete-shortcut', [ShortcutPolicy::class, 'bulkDelete']);
    }
}
```

### Convention de nommage des Gates

```
{action}-{resource}
```

Exemples :
- `create-shortcut`
- `delete-user`
- `update-parc`
- `viewAny-machine`

## Étape 3 : Protéger le Frontend (Blade/Livewire)

### Directive `@can` dans les vues Blade

```blade
{{-- Masquer un bouton si l'utilisateur n'a pas les droits --}}
@can('create-shortcut')
    <a href="{{ route('app.shortcuts.new') }}" class="btn btn-primary">
        Nouveau raccourci
    </a>
@endcan

{{-- Avec else --}}
@can('delete-shortcut')
    <button wire:click="delete">Supprimer</button>
@else
    <span class="text-gray-400">Suppression non autorisée</span>
@endcan

{{-- Vérifier l'inverse --}}
@cannot('update-shortcut')
    <p class="text-warning">Vous n'avez pas les droits de modification</p>
@endcannot
```

## Étape 4 : Protéger le Backend (Classe Livewire ou Controllers)

### Dans un composant Livewire

```php
<?php

use Illuminate\Support\Facades\Gate;
use App\Components\Traits\WithToasts;

new class extends Component {
    use WithToasts;

    public function delete(string $id)
    {
        // Vérifier les droits AVANT toute action
        if (Gate::denies('delete-shortcut')) {
            $this->toast('error', 'Accès refusé', 'Vous n\'avez pas les droits pour supprimer');
            return;
        }
        
        executeDelete($id);
    }
};
```

### Dans un Controller Laravel classique

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;

class ShortcutController extends Controller
{
    public function store(Request $request)
    {
        // Méthode 1 : Vérification manuelle
        if (Gate::denies('create-shortcut')) {
            abort(403, 'Action non autorisée');
        }

        // Méthode 2 : Avec authorize() - lance une exception automatiquement
        $this->authorize('create-shortcut');

        // Logique de création...
    }

    public function destroy(string $id)
    {
        $this->authorize('delete-shortcut');
        
        // Logique de suppression...
    }
}
```

## Résumé : Checklist pour sécuriser une ressource

1. **Créer la Policy** dans `app/Policies/`
   - Injecter `ConfigurationService` si besoin des droits legacy
   - Implémenter les méthodes : `viewAny`, `create`, `update`, `delete`

2. **Enregistrer les Gates** dans `AuthServiceProvider`
   - `Gate::define('action-resource', [Policy::class, 'method'])`

3. **Protéger le Frontend** avec `@can`
   - Masquer les boutons/liens non autorisés
   - Améliore l'UX mais ne suffit pas !

4. **Protéger le Backend** avec `Gate::denies()`
   - Vérifier AVANT chaque action modifiant des données
   - Afficher un toast d'erreur si refusé
   - C'est la vraie sécurité !

