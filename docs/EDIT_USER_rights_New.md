# Refactorisation du système de droits avec Laravel et LDAP Record

## Architecture moderne proposée

### 1. Modélisation des droits avec Eloquent et LDAP Record

#### Modèle Right (Eloquent + LDAP)
```php
// app/Models/Right.php
class Right extends Model implements LdapRecordable
{
    use LdapRecordTrait;
    
    protected $fillable = ['name', 'description', 'bit_value', 'is_negative'];
    
    // Mapping LDAP Record
    protected static $objectClasses = ['group'];
    protected $ldap_dn = 'ou=rights,dc=sambaedu,dc=local';
    
    // Relations
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_rights');
    }
    
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_rights');
    }
}
```

#### Modèle User (étendu)
```php
// app/Models/User.php
class User extends Authenticatable implements LdapRecordable
{
    use LdapRecordTrait, HasRoles;
    
    // Relations avec les droits
    public function rights()
    {
        return $this->belongsToMany(Right::class, 'user_rights');
    }
    
    public function delegations()
    {
        return $this->hasMany(Delegation::class);
    }
    
    // Calcul des droits effectifs
    public function getEffectiveRights(): int
    {
        return Cache::remember(
            "user_rights_{$this->login}",
            300,
            fn() => $this->calculateEffectiveRights()
        );
    }
    
    public function hasRight(int $right): bool
    {
        return ($this->getEffectiveRights() & $right) === $right;
    }
}
```

#### Modèle Delegation
```php
// app/Models/Delegation.php
class Delegation extends Model
{
    protected $fillable = ['user_id', 'parc_id', 'rights_level'];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function parc()
    {
        return $this->belongsTo(Parc::class);
    }
}
```

### 2. Service de gestion des droits

#### RightsService
```php
// app/Services/RightsService.php
class RightsService
{
    public function calculateUserRights(User $user): int
    {
        $rights = 0;
        
        // 1. Droits directs des groupes
        foreach ($user->rights as $right) {
            if ($right->is_negative) {
                $rights &= ~$right->bit_value;
            } else {
                $rights |= $right->bit_value;
            }
        }
        
        // 2. Droits hérités des groupes LDAP
        $inheritedRights = $this->getInheritedGroupRights($user);
        $rights |= $inheritedRights;
        
        // 3. Délégations de parc
        foreach ($user->delegations as $delegation) {
            $rights |= $delegation->rights_level;
        }
        
        return $rights;
    }
    
    public function assignRight(User $user, Right $right): bool
    {
        $user->rights()->attach($right);
        $this->invalidateUserCache($user);
        return true;
    }
    
    public function removeRight(User $user, Right $right): bool
    {
        $user->rights()->detach($right);
        $this->invalidateUserCache($user);
        return true;
    }
    
    public function createDelegation(User $user, Parc $parc, int $rightsLevel): Delegation
    {
        $delegation = Delegation::create([
            'user_id' => $user->id,
            'parc_id' => $parc->id,
            'rights_level' => $rightsLevel
        ]);
        
        $this->invalidateUserCache($user);
        return $delegation;
    }
}
```

### 3. Constantes de droits modernisées

#### RightsConstants
```php
// app/Constants/RightsConstants.php
class RightsConstants
{
    // User Rights (0x01 - 0xFF)
    public const USER_PASSWORD_INIT = 0x01;
    public const USER_READ = 0x02;
    public const USER_MODIFY = 0x04;
    public const USER_CREATE_TEMP = 0x08;
    public const USER_ASSIGN_RIGHT = 0x10;
    public const USER_DELEGATE = 0x20;
    public const SHARE_VIEW = 0x40;
    public const SHARE_REFRESH = 0x80;
    public const USER_ADMIN = 0xFF;
    
    // Computer Rights (0x100 - 0xEF00)
    public const COMPUTER_VIEW = 0x100;
    public const COMPUTER_CONTROL = 0x200;
    public const COMPUTER_ELEVATE = 0x400;
    public const COMPUTER_INSTALL = 0x800;
    public const WPKG_ASSIGN = 0x1000;
    public const WPKG_ADD = 0x2000;
    public const COMPUTER_ADMIN = 0xEF00;
    
    // Super Admin
    public const ADMIN = 0xFFFF;
    
    public static function getAllRights(): array
    {
        return [
            'user' => [
                self::USER_PASSWORD_INIT => 'Réinitialiser mots de passe',
                self::USER_READ => 'Lire annuaire',
                self::USER_MODIFY => 'Modifier utilisateurs',
                self::USER_CREATE_TEMP => 'Créer comptes temporaires',
                self::USER_ASSIGN_RIGHT => 'Assigner droits',
                self::USER_DELEGATE => 'Déléguer parcs',
                self::SHARE_VIEW => 'Voir partages',
                self::SHARE_REFRESH => 'Actualiser partages',
                self::USER_ADMIN => 'Admin complet utilisateurs',
            ],
            'computer' => [
                self::COMPUTER_VIEW => 'Voir parcs',
                self::COMPUTER_CONTROL => 'Contrôle distant',
                self::COMPUTER_ELEVATE => 'Admin local',
                self::COMPUTER_INSTALL => 'Installer postes',
                self::WPKG_ASSIGN => 'Déployer applications',
                self::WPKG_ADD => 'Ajouter applications',
                self::COMPUTER_ADMIN => 'Admin complet parcs',
            ],
            'admin' => [
                self::ADMIN => 'Super administrateur',
            ]
        ];
    }
}
```

### 4. Middleware Laravel pour les droits

#### HasRight Middleware
```php
// app/Http/Middleware/HasRight.php
class HasRight
{
    public function handle($request, Closure $next, int $right)
    {
        $user = $request->user();
        
        if (!$user || !$user->hasRight($right)) {
            abort(403, 'Droits insuffisants');
        }
        
        return $next($request);
    }
}
```

#### HasRightOrDelegation Middleware
```php
// app/Http/Middleware/HasRightOrDelegation.php
class HasRightOrDelegation
{
    public function handle($request, Closure $next, int $right)
    {
        $user = $request->user();
        
        if (!$user || (!$user->hasRight($right) && !$this->hasDelegation($user, $right))) {
            abort(403, 'Droits insuffisants');
        }
        
        return $next($request);
    }
    
    private function hasDelegation(User $user, int $right): bool
    {
        return $user->delegations()
            ->whereRaw('(rights_level & ?) > 0', [$right])
            ->exists();
    }
}
```

### 5. Contrôleurs de gestion des droits

#### RightsController
```php
// app/Http/Controllers/Admin/RightsController.php
class RightsController extends Controller
{
    public function __construct(private RightsService $rightsService) {}
    
    public function index()
    {
        $this->authorize('manage', Right::class);
        
        $users = User::with('rights')->paginate(25);
        return view('admin.rights.index', compact('users'));
    }
    
    public function edit(User $user)
    {
        $this->authorize('manage', Right::class);
        
        $user->load('rights');
        $availableRights = Right::orderBy('name')->get();
        $currentRights = $user->rights->pluck('id')->toArray();
        
        return view('admin.rights.edit', compact(
            'user', 'availableRights', 'currentRights'
        ));
    }
    
    public function update(Request $request, User $user)
    {
        $this->authorize('manage', Right::class);
        
        $validated = $request->validate([
            'rights' => 'array',
            'rights.*' => 'exists:rights,id'
        ]);
        
        // Synchronisation des droits
        $user->rights()->sync($validated['rights'] ?? []);
        $this->rightsService->invalidateUserCache($user);
        
        return redirect()
            ->route('admin.rights.index')
            ->with('success', 'Droits mis à jour avec succès');
    }
    
    public function delegations(User $user)
    {
        $this->authorize('delegate', Right::class);
        
        $delegations = $user->delegations()->with('parc')->get();
        $parcs = Parc::orderBy('name')->get();
        
        return view('admin.rights.delegations', compact(
            'user', 'delegations', 'parcs'
        ));
    }
    
    public function storeDelegation(Request $request, User $user)
    {
        $this->authorize('delegate', Right::class);
        
        $validated = $request->validate([
            'parc_id' => 'required|exists:parcs,id',
            'rights_level' => 'required|integer|min:1'
        ]);
        
        $this->rightsService->createDelegation(
            $user,
            Parc::find($validated['parc_id']),
            $validated['rights_level']
        );
        
        return redirect()
            ->route('admin.rights.delegations', $user)
            ->with('success', 'Délégation créée avec succès');
    }
}
```

### 6. Routes Laravel

#### Définition des routes
```php
// routes/web.php
Route::middleware(['auth', 'right:' . RightsConstants::USER_ADMIN])
    ->prefix('admin/rights')
    ->name('admin.rights.')
    ->group(function () {
        Route::get('/', [RightsController::class, 'index'])->name('index');
        Route::get('/{user}/edit', [RightsController::class, 'edit'])->name('edit');
        Route::put('/{user}', [RightsController::class, 'update'])->name('update');
        Route::get('/{user}/delegations', [RightsController::class, 'delegations'])->name('delegations');
        Route::post('/{user}/delegations', [RightsController::class, 'storeDelegation'])->name('storeDelegation');
        Route::delete('/delegations/{delegation}', [RightsController::class, 'destroyDelegation'])->name('destroyDelegation');
    });
```

### 7. Vues Blade modernes

#### Vue d'édition des droits
```blade
<!-- resources/views/admin/rights/edit.blade.php -->
@extends('layouts.admin')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3>Gestion des droits : {{ $user->fullname }}</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.rights.update', $user) }}">
                        @csrf
                        @method('PUT')
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Droits actuels</h5>
                                <div class="form-group">
                                    <select name="rights[]" class="form-control" size="15" multiple>
                                        @foreach($user->rights as $right)
                                            <option value="{{ $right->id }}" selected>
                                                {{ $right->name }} - {{ $right->description }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5>Droits disponibles</h5>
                                <div class="form-group">
                                    <select name="rights[]" class="form-control" size="15" multiple>
                                        @foreach($availableRights as $right)
                                            <option value="{{ $right->id }}" 
                                                @if(in_array($right->id, $currentRights)) disabled @endif>
                                                {{ $right->name }} - {{ $right->description }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer les modifications
                            </button>
                            <a href="{{ route('admin.rights.index') }}" class="btn btn-secondary">
                                Annuler
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
```

### 8. Policies Laravel pour les droits

#### RightPolicy
```php
// app/Policies/RightPolicy.php
class RightPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasRight(RightsConstants::USER_ADMIN);
    }
    
    public function delegate(User $user): bool
    {
        return $user->hasRight(RightsConstants::USER_DELEGATE);
    }
    
    public function viewAny(User $user): bool
    {
        return $user->hasRight(RightsConstants::USER_READ);
    }
}
```

### 9. Commandes Artisan pour la migration

#### Migration des droits existants
```php
// app/Console/Commands/MigrateRights.php
class MigrateRights extends Command
{
    protected $signature = 'sambaedu:migrate-rights';
    protected $description = 'Migrate legacy rights to modern system';
    
    public function handle(RightsService $rightsService)
    {
        $this->info('Migration des droits depuis LDAP...');
        
        // Création des droits par défaut
        $rights = RightsConstants::getAllRights();
        foreach ($rights as $category => $categoryRights) {
            foreach ($categoryRights as $bitValue => $description) {
                Right::updateOrCreate(
                    ['bit_value' => $bitValue],
                    [
                        'name' => $this->generateRightName($bitValue),
                        'description' => $description,
                        'is_negative' => false
                    ]
                );
            }
        }
        
        $this->info('Migration terminée avec succès');
    }
}
```

### 10. Étapes de la refacto

#### Phase 1 : Création des modèles et services
1. Créer les modèles `Right`, `Delegation`
2. Étendre le modèle `User` avec les relations
3. Implémenter `RightsService`
4. Créer les constantes `RightsConstants`

#### Phase 2 : Middleware et policies
1. Créer les middleware `HasRight`, `HasRightOrDelegation`
2. Implémenter `RightPolicy`
3. Configurer les routes avec les middleware

#### Phase 3 : Contrôleurs et vues
1. Créer `RightsController`
2. Développer les vues Blade
3. Intégrer avec le layout admin existant

#### Phase 4 : Migration des données
1. Créer la commande de migration
2. Exporter les droits depuis LDAP legacy
3. Importer dans le nouveau système
4. Valider la cohérence des données

#### Phase 5 : Tests et déploiement
1. Tests unitaires des services
2. Tests d'intégration des middleware
3. Validation UI/UX
4. Déploiement progressif

### 11. Avantages de la nouvelle architecture

#### Performance
- Cache Laravel plus efficace qu'APCu
- Requêtes optimisées avec Eloquent
- Relations chargées en eager loading

#### Maintenabilité
- Code typé avec les propriétés de PHP 8
- Architecture SOLID
- Tests automatisés possibles

#### Sécurité
- Policies Laravel centralisées
- Validation des requêtes
- Protection CSRF native

#### Extensibilité
- Système de plugins pour les droits
- API REST pour la gestion
- Support multi-tenant facilité
