# Services SE4 - Documentation

## Vue d'ensemble

Cette refactorisation transforme les fichiers legacy critiques de SambaEdu en services Laravel modernes :

- `includes/config.inc.php` → `App\Config\SambaeduConfig`
- `includes/ldap.inc.php` → `App\Config\LdapService`
- `includes/functions.inc.php` → `App\Service\UtilityService`

## Services disponibles

### ConfigurationService
Gère la configuration SE4, les connexions LDAP, et les paramètres système.

```php
// Via façade
use App\Facades\SEConfig;
$config = SEConfig::getConfig();

// Via injection de dépendance
public function __construct(ConfigurationService $configService) {}
```

### LdapService (OBSOLÈTE)
⚠️ **Ce service est obsolète et ne doit plus être utilisé.**

Utilisez plutôt les **Repositories** pour les interactions LDAP :
- `App\Repositories\UserRepository` pour les utilisateurs
- `App\Repositories\MachineRepository` pour les machines
- `App\Repositories\ParcRepository` pour les parcs

```php
// ✅ Nouvelle approche (recommandée)
use App\Repositories\UserRepository;

class MyController {
    public function __construct(private UserRepository $userRepository) {}
    
    public function index() {
        $users = $this->userRepository->search('john', 50);
        // ...
    }
}
```

### UtilityService
Fonctions utilitaires : sessions, sécurité, menus, validation.

```php
// Via façade
use App\Facades\SE4Utility;
SE4Utility::openSession($config, $login, $password);

// Via helper
se4_utility()->openSession($config, $login, $password);
```

### CacheService
Wrapper pour APCu avec fallback gracieux si l'extension n'est pas disponible.

```php
// Via helper
se4_cache()->store('key', $data, 60);
```


## Compatibilité

- ✅ Maintient la compatibilité avec le code legacy existant
- ✅ Même comportement que les fonctions originales
- ✅ Sessions partagées entre Laravel et legacy
- ⚠️ Nécessite l'extension APCu pour le cache optimal

## Migration progressive

Les services peuvent être utilisés progressivement :
1. Remplacer les appels directs aux fonctions legacy
2. Utiliser les façades ou helpers dans le nouveau code
3. Migrer module par module vers les nouveaux services

## Notes techniques

- Les services sont des singletons pour maintenir l'état (il n'y a qu'une seule instance de chaque service qui sera utilisée partout)
- Le cache APCu est optionnel (fallback silencieux)
- Les fonctions legacy restent disponibles pendant la transition
