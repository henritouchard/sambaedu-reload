# Analyse du système de droits SambaEdu Legacy

## Architecture des droits dans SambaEdu

### 1. Stockage des droits

Les droits sont stockés dans **LDAP** sous forme de **groupes de droits** dans la branche `rights` :

```
OU=rights,DC=sambaedu,DC=local
├── CN=se3_is_admin (droit: 0xFF = SE_USER_ADMIN)
├── CN=computer_is_admin (droit: 0xEF00 = SE_COMPUTER_ADMIN)
├── CN=Annu_is_admin (droit: 0xFF = SE_USER_ADMIN)
├── CN=password_is_admin (droit: 0x01 = SE_USER_PASSWORD_INIT)
├── CN=RefNum (droit: 0x1F07 = combinaison de droits)
└── CN=no_[droit] (groupes négatifs pour annuler des droits)
```

### 2. Constantes de droits (système de bits)

Le système utilise des **constantes entières** avec des opérations bit à bit :

```php
// Droits utilisateurs (0x01 à 0xFF)
define('SE_USER_PASSWORD_INIT', 0x01);     // Réinitialiser mots de passe
define('SE_USER_READ', 0x02);               // Lire annuaire
define('SE_USER_MODIFY', 0x04);             // Modifier utilisateurs
define('SE_USER_CREATE_TEMP', 0x08);        // Créer comptes temporaires
define('SE_USER_ASSIGN_RIGHT', 0x10);       // Assigner droits
define('SE_USER_DELEGATE', 0x20);           // Déléguer parcs
define('SE_SHARE_VIEW', 0x40);              // Voir partages
define('SE_SHARE_REFRESH', 0x80);           // Actualiser partages
define('SE_USER_ADMIN', 0xFF);              // Admin complet utilisateurs

// Droits parcs (0x100 à 0xEF00)
define('SE_COMPUTER_VIEW', 0x100);          // Voir parcs
define('SE_COMPUTER_CONTROL', 0x200);       // Contrôle distant (Guacamole)
define('SE_COMPUTER_ELEVATE', 0x400);       // Admin local
define('SE_COMPUTER_INSTALL', 0x800);       // Installer postes
define('SE_WPKG_ASSIGN', 0x1000);           // Déployer applications
define('SE_WPKG_ADD', 0x2000);              // Ajouter applications
define('SE_COMPUTER_ADMIN', 0xEF00);        // Admin complet parcs

// Super admin
define('SE_ADMIN', 0xFFFF);                 // Tous les droits
```

### 3. Fonctions principales de gestion des droits

#### `list_rights($config, $name, $deleg = false, $refresh = false)`
- **Rôle** : Calculer les droits effectifs d'un utilisateur/groupe
- **Logique** :
  1. Recherche les groupes de droits de l'utilisateur (`list_right_profiles`)
  2. Additionne les droits des groupes positifs (`$right |= $group['info']`)
  3. Soustrait les droits des groupes négatifs (`$right &= ~$group['info']`)
  4. Ajoute les délégations si `$deleg = true`
- **Cache** : APCu avec clé `rights_{login}` (300 secondes)

#### `have_right($config, $test_right, $user = "login", $or = false)`
- **Rôle** : Vérifier si un utilisateur a un/des droits spécifiques
- **Logique** : Opérations bit à bit pour tester les droits
- **Retour** : `true` si droits présents, `false` sinon

#### `add_right_profile($config, $cn, $right)` / `remove_right_profile($config, $cn, $right)`
- **Rôle** : Ajouter/supprimer un utilisateur d'un groupe de droits
- **Implémentation** : Utilise `groupaddmember()` / `groupdelmember()`

### 4. Système de délégations

Les délégations permettent de donner des droits sur des parcs spécifiques :

```php
// Structure d'une délégation
array(
    'user' => 'nom_utilisateur',
    'parc' => 'nom_parc', 
    'level' => 0xEF00  // niveau de droits délégués
)
```

#### Fonctions de délégation
- `list_delegations($config, $login)` : Lister les délégations d'un utilisateur
- `create_delegation($config, $parc, $name, $level)` : Créer une délégation
- `delete_delegation($config, $delegation, $name)` : Supprimer une délégation
- `have_right_or_delegation($config, $right, $name)` : Vérifier droits + délégations

### 5. Gestion des droits individuels vs groupes

#### Droits individuels
- Ajout direct de l'utilisateur dans les groupes de droits LDAP
- Modification via `manage_rights.php`
- Interface : formulaire avec listes déroulantes

#### Droits par groupe
- Les groupes peuvent être membres de groupes de droits
- Héritage récursif des droits
- Gestion via les mêmes fonctions (`list_rights`, `have_right`)

### 6. Cache et performance

- **Cache APCu** : `rights_{login}` (300s) pour les droits calculés
- **Invalidation** : `ldap_rights_cache_invalid` pour forcer le rechargement
- **Cache délégations** : `delegation_{login}` pour les délégations

### 7. Interface d'administration (`manage_rights.php`)

#### Actions possibles
- `AddRights` : Ajouter des profils de droits
- `DelRights` : Supprimer des profils de droits  
- `AddDelegation` : Ajouter une délégation de parc
- `DelDelegation` : Supprimer une délégation

#### Contrôle d'accès
- Nécessite le droit `SE_USER_ADMIN` pour gérer les droits
- Affichage des droits actuels vs disponibles
- Distinction entre droits directs et hérités

### 8. Cas spéciaux

#### Utilisateur "admin"
- Retourne automatiquement `SE_ADMIN` (tous les droits)
- Hardcodé dans `list_rights()` et `have_right()`

#### Groupes négatifs (`no_*`)
- Permettent d'annuler des droits spécifiques
- Préfixe `no_` dans le nom du groupe
- Logique de soustraction bit à bit

#### Droits hérités
- Affichés comme "disabled" dans l'interface
- Non supprimables directement (vient des groupes parent)
