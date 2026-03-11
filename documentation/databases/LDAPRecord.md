# LdapRecord - Guide d'utilisation

## Qu'est-ce que LdapRecord ?

LdapRecord est un ORM (Object-Relational Mapping) pour LDAP dans l'écosystème Laravel. Il fournit une interface élégante et expressive pour interagir avec les serveurs LDAP (Active Directory, OpenLDAP, etc.) de manière orientée objet.

### Concepts clés

- **Modèles** : Représentent les objets LDAP (utilisateurs, groupes, ordinateurs...)
- **Repositories** : Couche d'abstraction pour les requêtes complexes ce qui permet de donner des noms clairs à des reqêtes compliquées
et de pouvoir les réutiliser facilement.
- **Relations** : Gestion des liens entre objets LDAP (memberof, member...)
- **Query Builder** : Construction de requêtes LDAP avec une syntaxe fluide

## Architecture dans SE4FS

```
App/LdapModels/          # Modèles LDAP (LdapUser, SambaEduGroup...)
App/Repositories/        # Repositories (UserRepository, GroupRepository...)
App/Services/           # Services métier (UserService, GroupService...)
```

## Exemples d'utilisation classiques

### 1. Recherche simple d'utilisateurs
Documentation officielle: https://ldaprecord.com/docs/core/v3/searching

```php
use App\LdapModels\LdapUser;

// Rechercher un utilisateur par login
$user = LdapUser::findByLogin('dupont.j');

// Rechercher par email
$users = LdapUser::where('mail', 'contains', '@etab.fr')->get();

// Rechercher avec plusieurs conditions
$users = LdapUser::where('sn', '=', 'Dupont')
    ->where('givenname', '=', 'Jean')
    ->limit(10)
    ->get();
```

### 2. Recherche structurée multi-objets

Pour récupérer par exemple des élèves avec les informations de leur établissement :

```php
use App\Repositories\UserRepository;
use App\LdapModels\OrganizationalUnitModel;

// Approche 1 : Via le repository (recommandé)
$userRepository = new UserRepository();
$eleves = $userRepository->findByEstablishment('0751234a')
    ->filter(function($user) {
        return $user->isEleve();
    });

// Enrichir avec les infos de l'établissement
$resultat = $eleves->map(function($eleve) use ($uai) {
    // Récupérer les infos de l'établissement
    $etablissement = OrganizationalUnitModel::findByUai($uai);
    
    return [
        'eleve' => [
            'login' => $eleve->getLogin(),
            'nom' => $eleve->getLastName(),
            'prenom' => $eleve->getFirstName(),
            'classe' => $this->extractClasseFromDn($eleve->getDn()),
        ],
        'etablissement' => [
            'uai' => $uai,
            'nom' => $etablissement?->getName(),
            'adresse' => $etablissement?->getAddress(),
            'ville' => $etablissement?->getCity(),
        ]
    ];
});
```

### 3. Approche multi-requêtes optimisée

```php
// Étape 1 : Récupérer les élèves
$eleves = LdapUser::in("OU=0751234a,OU=People,DC=etab,DC=fr")
    ->where('memberof', 'contains', 'CN=Eleves')
    ->limit(100)
    ->get();

// Étape 2 : Récupérer l'établissement une seule fois
$etablissement = OrganizationalUnitModel::findByUai('0751234a');

// Étape 3 : Construire la structure finale
$resultat = [
    'etablissement' => [
        'uai' => '0751234a',
        'nom' => $etablissement->getName(),
        'adresse' => $etablissement->getAddress(),
    ],
    'eleves' => $eleves->map(function($eleve) {
        return [
            'login' => $eleve->getLogin(),
            'nom' => $eleve->getLastName(),
            'prenom' => $eleve->getFirstName(),
            'classe' => $this->extractClasseFromDn($eleve->getDn()),
        ];
    })->toArray()
];
```

### 4. Gestion des relations

```php
// Les groupes d'un utilisateur
$groups = $user->getGroups(); // ['Eleves', '6eme_A', 'Maths']

// Les membres d'un groupe
$group = SambaEduGroup::find('CN=6eme_A,OU=Groups,DC=etab,DC=fr');
$members = $group->members()->get();

// Vérifier l'appartenance à un groupe
if ($user->isMemberOf('CN=Eleves,OU=Groups,DC=etab,DC=fr')) {
    // L'utilisateur est un élève
}
```

### 5. Recherche avancée avec filtres complexes

```php
// Rechercher les élèves actifs d'une classe spécifique
$eleves = LdapUser::query()
    ->where('memberof', 'contains', 'CN=Eleves')
    ->where('memberof', 'contains', 'CN=6eme_A')
    ->where('useraccountcontrol', '=', 512) // Compte actif
    ->where('mail', '!=', '') // Avec email
    ->orderBy('sn')
    ->orderBy('givenname')
    ->get();

// Recherche par attributs multi-valeurs
$users = LdapUser::where('memberof', 'contains', [
    'CN=Eleves,OU=Groups,DC=etab,DC=fr',
    'CN=6eme_A,OU=Groups,DC=etab,DC=fr'
])->get();
```

### 6. Pagination et performance

```php
// Pagination pour grands volumes
$page = 1;
$perPage = 50;

$users = LdapUser::where('memberof', 'contains', 'CN=Eleves')
    ->paginate($perPage, ['cn', 'sn', 'givenname', 'mail']);

// Chunking pour traiter lots par lots
LdapUser::where('memberof', 'contains', 'CN=Eleves')
    ->chunk(100, function($users) {
        foreach ($users as $user) {
            // Traiter 100 utilisateurs à la fois
        }
    });
```

## Bonnes pratiques

### 1. Utiliser les repositories pour la logique métier
```php
// ✅ Bon : Via repository
$eleves = $userRepository->findByType('eleve');

// ❌ Éviter : Logique directe dans le contrôleur
$eleves = LdapUser::where('memberof', 'contains', 'CN=Eleves')->get();
```

### 2. Optimiser les requêtes LDAP
```php
// ✅ Sélectionner uniquement les attributs nécessaires
$users = LdapUser::select(['cn', 'sn', 'givenname', 'mail'])->get();

// ✅ Limiter les résultats
$users = LdapUser::limit(100)->get();

// ✅ Utiliser des index LDAP si disponibles
```

### 3. Gérer les erreurs LDAP
```php
try {
    $users = LdapUser::where('cn', '=', $login)->first();
} catch (\LdapRecord\LdapRecordException $e) {
    Log::error('Erreur LDAP : ' . $e->getMessage());
    return null;
}
```

### 4. Cacher les résultats fréquents
```php
// Cache de 5 minutes pour la liste des établissements
$etablissements = Cache::remember('etablissements', 300, function() {
    return $this->getEtablissements();
});
```

## Migration depuis PostgreSQL/ORM

| PostgreSQL/ORM | LdapRecord |
|----------------|------------|
| `User::where('email', 'like', '%@etab.fr')` | `LdapUser::where('mail', 'contains', '@etab.fr')` |
| `User::join('schools', ...)` | `LdapUser::where('memberof', 'contains', $schoolDn)` |
| `User::find($id)` | `LdapUser::findByLogin($login)` |
| `$user->school->name` | `$this->extractSchoolFromDn($user->getDn())` |

LdapRecord adapte les patterns ORM au contexte hiérarchique de LDAP tout en conservant une syntaxe familière pour les développeurs Laravel.