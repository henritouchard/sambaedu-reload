# Constantes LDAP SambaEdu

Ce dossier contient les constantes LDAP utilisées dans l'application pour standardiser les filtres, attributs et scopes LDAP.

## Fichiers

- **LdapFilter.php** : Constantes pour les filtres LDAP (objectclass, cn, etc.)
- **LdapAttributes.php** : Constantes pour les attributs LDAP à récupérer
- **LdapScope.php** : Constantes pour les scopes de recherche LDAP

## Utilisation

### LdapFilter

Les filtres sont définis avec des placeholders `%s` pour les valeurs dynamiques :

```php
use App\Constants\Ldap\LdapFilter;

// Filtre simple
$filter = LdapFilter::USER_ALL; // '(&(objectclass=user)(!(objectclass=computer)))'

// Filtre avec paramètres
$filter = LdapFilter::build(LdapFilter::USER_BY_CN, 'jdupont');
// Résultat: '(&(objectclass=user)(!(objectclass=computer))(cn=jdupont))'
```

### LdapAttributes

Les attributs sont définis comme des tableaux :

```php
use App\Constants\Ldap\LdapAttributes;

// Utiliser directement dans une requête LdapRecord
$user = LdapUser::select(LdapAttributes::USER)->first();

// Ou pour un groupe
$group = DeviceGroupTagModel::select(LdapAttributes::PARC)->first();
```

### LdapScope

Les scopes définissent la profondeur de recherche :

```php
use App\Constants\Ldap\LdapScope;

// Recherche récursive (par défaut)
$scope = LdapScope::SUBTREE;

// Recherche au niveau de base uniquement
$scope = LdapScope::BASE;

// Recherche un seul niveau
$scope = LdapScope::ONELEVEL;
```

## Correspondance avec le code legacy

Ces constantes correspondent aux valeurs utilisées dans `includes/ldap.inc.php` dans la fonction `search_ad()` :

- **Types de recherche** : `user`, `group`, `machine`, `salle` (legacy, utiliser `deviceGroup`), `parc`, `classe`, `equipe`, etc.
- **Attributs** : Définis dans chaque `case` de `search_ad()`
- **Scopes** : `subtree` (par défaut), `base`, `onelevel`

## Migration

Lors de la migration vers LdapRecord, ces constantes remplaceront progressivement les valeurs codées en dur dans le code legacy.

