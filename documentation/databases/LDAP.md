# Documentation LDAP / Active Directory

## Qu'est-ce qu'Active Directory ?

Active Directory (AD) est un service d'annuaire développé par Microsoft qui permet de centraliser la gestion des utilisateurs, des ordinateurs, des groupes et des ressources réseau dans un environnement Windows. Il utilise le protocole LDAP (Lightweight Directory Access Protocol) pour stocker et organiser les informations dans une structure hiérarchique en arborescence. Dans SambaEdu, nous utilisons Samba4 qui implémente Active Directory de manière compatible, permettant de gérer un domaine Windows complet avec authentification centralisée, gestion des permissions et politiques de sécurité.

## Structures d'objets LDAP

### DN (Distinguished Name)

Le **DN** (Distinguished Name) est l'identifiant unique et complet d'un objet dans l'arborescence LDAP. Il représente le chemin complet depuis la racine jusqu'à l'objet, en lisant de gauche à droite (de l'objet vers la racine).

**Format** : `CN=nom,OU=conteneur,DC=domaine,DC=com`

**Exemple** :
```
CN=dupont.j,OU=6eme,OU=Eleves,OU=1234567A,OU=People,DC=example,DC=com
```

### RDN (Relative Distinguished Name)

C'est un identifiant unique qui distingue une entrée de ses frères au sein d'un même conteneur

**Dans l'exemple récédent** : `CN=dupond.j,OU=eleves,DC=domaine,DC=com`
`dupond.j` sera le RDN sachant qu'il est unique. Si jue prends le OU "élèves", je récupèrerai plusieurs élèves, tandis que si je prends CN il est unique.


### Composants du DN

#### DC (Domain Component)

Le **DC** représente un composant du nom de domaine DNS. Il apparaît généralement à la fin du DN et constitue la racine de l'arborescence.

**Exemple** : `DC=example,DC=com` représente le domaine `example.com`

#### OU (Organizational Unit)

L'**OU** (Unité Organisationnelle) est un conteneur qui permet d'organiser les objets de manière hiérarchique. Les OUs sont utilisées pour structurer l'annuaire et faciliter la gestion des permissions et politiques.

**Exemple** : `OU=Eleves,OU=People,DC=example,DC=com`

**Types d'OUs dans SambaEdu** :
- `OU=People` : Conteneur principal des utilisateurs
- `OU=Groups` : Conteneur des groupes
- `OU=Eleves` : Conteneur des élèves
- `OU=Profs` : Conteneur des professeurs
- `OU=Administratifs` : Conteneur du personnel administratif
- `OU={UAI}` : Conteneur spécifique à un établissement (format 7 chiffres + 1 lettre, ex: `0751234a`)

On peut avoir plusieurs OUs dans le DN, ce qui décrit une hiérarchie. Voici un exemple de structure :

```
DC=example,DC=com                    (base DN)
└── OU=People                        (conteneur principal)
    └── OU=1234567A                  (UAI de l'établissement - si multi-établissement)
        └── OU=Eleves                (catégorie : Eleves, Profs, Administratifs)
            └── OU=6eme              (fonction/classe : optionnel)
                └── CN=dupont.j      (l'utilisateur final)
```

Le DN complet correspondant serait :
```
CN=dupont.j,OU=6eme,OU=Eleves,OU=1234567A,OU=People,DC=example,DC=com
```

#### CN (Common Name)

Le **CN** (Common Name) est le nom commun d'un objet. Pour les utilisateurs, c'est généralement le login. Pour les groupes, c'est le nom du groupe.

**Exemple** : `CN=dupont.j` pour un utilisateur, `CN=Eleves` pour un groupe

### ObjectClass

L'**objectClass** définit le type d'objet LDAP et détermine quels attributs peuvent être utilisés. Un objet peut avoir plusieurs objectClasses (héritage).

**ObjectClasses courants dans SambaEdu** :
- `user` : Utilisateur Active Directory
- `group` : Groupe Active Directory
- `organizationalUnit` : Unité organisationnelle
- `top` : Classe de base (tous les objets héritent de `top`)
- `computer` : Ordinateur (les ordinateurs ont aussi `objectclass=user`)

### Attributs LDAP courants

#### Attributs utilisateurs

| Attribut | Description | Exemple |
|----------|-------------|---------|
| `cn` | Common Name (login) | `dupont.j` |
| `samaccountname` | Nom de compte SAM (identique au cn) | `dupont.j` |
| `sn` | Surname (nom de famille) | `Dupont` |
| `givenname` | Prénom | `Jean` |
| `displayname` | Nom d'affichage | `Jean Dupont` |
| `mail` | Adresse email | `dupont.j@example.com` |
| `userprincipalname` | UPN (format email) | `dupont.j@example.com` |
| `useraccountcontrol` | État du compte (512=actif, 514=désactivé) | `512` |
| `memberof` | Liste des groupes dont l'utilisateur est membre | `CN=Eleves,OU=Groups,...` |
| `pwdlastset` | Date du dernier changement de mot de passe (0=doit changer) | `0` |
| `employeenumber` | Identifiants externes (SIECLE, GPEI, etc.) | `123456,789012` |
| `title` | Titre/fonction ou ID externe | `Professeur` |
| `physicaldeliveryofficename` | Date de naissance encodée (hash) | `hash_naissance` |
| `objectguid` | Identifiant unique global (GUID) | `{guid-hex}` |

#### Attributs groupes

| Attribut | Description | Exemple |
|----------|-------------|---------|
| `cn` | Nom du groupe | `Eleves` |
| `samaccountname` | Nom de compte SAM du groupe | `Eleves` |
| `description` | Description du groupe | `Groupe des élèves` |
| `member` | Liste des membres (DNs) | `CN=dupont.j,...` |
| `memberof` | Groupes dont ce groupe est membre | `CN=Domain Users,...` |

#### Attributs OUs

| Attribut | Description | Exemple |
|----------|-------------|---------|
| `ou` | Nom de l'OU | `Eleves` |
| `description` | Description de l'OU | `OU des élèves` |
| `name` | Nom alternatif | `Eleves` |

## Modèles dans SambaEdu

### Modèle Utilisateur (LdapUser)

Le modèle `LdapUser` représente un utilisateur dans Active Directory. Il étend le modèle `User` de LdapRecord.

#### Structure du DN utilisateur

Le DN d'un utilisateur suit cette structure :

```
CN={login},OU={fonction},OU={categorie},OU={UAI},OU=People,DC={domaine},DC={com}
```

**Exemple concret** :
```
CN=dupont.j,OU=6eme,OU=Eleves,OU=1234567A,OU=People,DC=example,DC=com
```

#### Catégories d'utilisateurs

Les utilisateurs sont organisés en trois catégories principales :

1. **Eleves** : Les élèves
   - Structure : `OU=Eleves,OU={UAI},OU=People,...`
   - Peut avoir une fonction/classe : `OU={classe},OU=Eleves,...`
   - Exemple : `OU=6eme,OU=Eleves,...`

2. **Profs** : Les professeurs
   - Structure : `OU=Profs,OU={UAI},OU=People,...`
   - Peut avoir une fonction : `OU={fonction},OU=Profs,...`

3. **Administratifs** : Le personnel administratif
   - Structure : `OU=Administratifs,OU={UAI},OU=People,...`
   - Doit avoir une fonction : `OU={fonction},OU=Administratifs,...`

#### Création d'un utilisateur

Lors de la création d'un utilisateur, le système :

1. **Crée les OUs génériques** si elles n'existent pas :
   ```php
   $this->ensureOUsExist($categorie, $fonction, $etab, $config);
   ```
   Cette fonction garantit que toute la hiérarchie d'OUs existe avant de créer l'utilisateur.

2. **Construit le DN** selon la catégorie, fonction et établissement :
   ```php
   $userDn = $this->buildUserDn($login, $categorie, $fonction, $etab, $config);
   ```

3. **Crée l'objet utilisateur** avec ses attributs :
   - `cn`, `samaccountname` : le login
   - `sn`, `givenname`, `displayname` : nom et prénom
   - `mail`, `userprincipalname` : adresses email
   - `useraccountcontrol` : état du compte (512 = actif)
   - `pwdlastset` : gestion du changement de mot de passe
   - `employeenumber` : identifiants externes
   - `objectclass` : `['top', 'user']`

#### ObjectClasses utilisateur

- `top` : Classe de base (obligatoire)
- `user` : Classe Active Directory pour les utilisateurs
- **Note** : Les ordinateurs ont aussi `objectclass=user`, mais avec `objectclass=computer` en plus

### Modèle OU (OrganizationalUnitModel)

Le modèle `OrganizationalUnitModel` représente une Unité Organisationnelle dans Active Directory.

#### Structure du DN OU

```
OU={nom},OU={parent},...,DC={domaine},DC={com}
```

**Exemple** :
```
OU=Eleves,OU=1234567A,OU=People,DC=example,DC=com
```

#### ObjectClasses OU

- `top` : Classe de base
- `organizationalUnit` : Classe spécifique aux OUs

#### Création hiérarchique

Les OUs sont créées de manière hiérarchique : si une OU parente n'existe pas, elle est créée automatiquement avant la création de l'OU enfant.

**Exemple** : Pour créer `OU=6eme,OU=Eleves,OU=People,...`, le système vérifie et crée dans l'ordre :
1. `OU=People,...` (si n'existe pas)
2. `OU=Eleves,OU=People,...` (si n'existe pas)
3. `OU=6eme,OU=Eleves,OU=People,...` (si n'existe pas)

#### OUs génériques

Les **OUs génériques** sont des conteneurs créés automatiquement pour organiser les utilisateurs selon leur profil. Elles permettent :

- **Organisation hiérarchique** : Structurer les utilisateurs dans l'arborescence LDAP
- **Gestion des permissions** : Appliquer des politiques par groupe d'utilisateurs
- **Séparation logique** : Isoler les élèves, professeurs, administratifs, etc.

**Hiérarchie typique** :
```
DC=example,DC=com
└── OU=People
    └── OU=1234567A          (UAI de l'établissement)
        └── OU=Eleves        (catégorie)
            └── OU=6eme      (fonction/classe)
                └── CN=dupont.j  (utilisateur)
```

### Modèle Groupe (SambaEduGroup)

Les groupes permettent de regrouper des utilisateurs pour la gestion des permissions et l'application de politiques.

#### Structure du DN groupe

```
CN={nom},OU={conteneur},DC={domaine},DC={com}
```

**Exemple** :
```
CN=Eleves,OU=Groups,DC=example,DC=com
```

#### Types de groupes dans SambaEdu

1. **Groupes de catégorie** : `Eleves`, `Profs`, `Administratifs`
2. **Groupes de classe** : `Classe_6eme_A`, `Classe_5eme_B`
3. **Groupes d'équipe** : `Equipe_Maths`, `Equipe_Francais`
4. **Groupes de cours** : `Cours_Maths_6A`
5. **Groupes de projet** : `Projet_...`
6. **Groupes de délégués** : `PP_6eme_A` (Professeur Principal)

#### ObjectClasses groupe

- `top` : Classe de base
- `group` : Classe Active Directory pour les groupes

#### Attributs importants

- `member` : Liste des DNs des membres du groupe
- `memberof` : Groupes dont ce groupe est membre (groupes imbriqués)

## Variables de configuration AD

### Variables principales

| Variable | Description | Exemple |
|----------|-------------|---------|
| `ldap_base_dn` | DN de base du domaine | `DC=example,DC=com` |
| `people_rdn` | RDN du conteneur des utilisateurs | `OU=People` |
| `groups_rdn` | RDN du conteneur des groupes | `OU=Groups` |
| `etab_ou` | UAI de l'établissement (si mono-établissement) | `1234567A` |
| `domain` | Nom de domaine DNS | `example.com` |

### DNs complets (construits)

| Variable | Description | Exemple |
|----------|-------------|---------|
| `base_dn_people` | DN complet du conteneur People | `OU=People,DC=example,DC=com` |
| `dn['people']` | DN complet des utilisateurs (avec UAI si multi-établissement) | `OU=1234567A,OU=People,DC=example,DC=com` |
| `dn['groups']` | DN complet des groupes | `OU=Groups,DC=example,DC=com` |
| `dn['classes']` | DN complet des classes | `OU=Classes,OU=Groups,DC=example,DC=com` |

## Exemples pratiques

### Exemple 1 : Création d'un élève

**Données** :
- Login : `dupont.j`
- Catégorie : `Eleves`
- Classe : `6eme`
- Établissement : `1234567A`

**Processus** :

1. Création des OUs génériques :
   ```
   OU=People,DC=example,DC=com
   OU=1234567A,OU=People,DC=example,DC=com
   OU=Eleves,OU=1234567A,OU=People,DC=example,DC=com
   OU=6eme,OU=Eleves,OU=1234567A,OU=People,DC=example,DC=com
   ```

2. Construction du DN utilisateur :
   ```
   CN=dupont.j,OU=6eme,OU=Eleves,OU=1234567A,OU=People,DC=example,DC=com
   ```

3. Création de l'objet utilisateur avec attributs

4. Ajout au groupe `Eleves`

### Exemple 2 : Structure multi-établissement

Pour un environnement multi-établissement, chaque établissement a son propre conteneur UAI :

```
DC=example,DC=com
└── OU=People
    ├── OU=1234567A          (Établissement A)
    │   ├── OU=Eleves
    │   └── OU=Profs
    └── OU=7654321B          (Établissement B)
        ├── OU=Eleves
        └── OU=Profs
```

### Exemple 3 : Recherche d'utilisateurs

**Recherche par login** :
```php
$user = LdapUser::findByLogin('dupont.j');
```

**Recherche par DN** :
```php
$user = LdapUser::findByDn('CN=dupont.j,OU=6eme,OU=Eleves,...');
```

**Filtre LDAP** :
```
(&(objectclass=user)(!(objectclass=computer))(cn=dupont.j))
```

## Notes importantes

1. **Ordre de création** : Les OUs doivent être créées **avant** les objets qu'elles contiennent
2. **Idempotence** : Les fonctions de création vérifient l'existence avant de créer (pas de doublons)
3. **Hiérarchie** : La création d'une OU crée automatiquement ses parents si nécessaire
4. **Distinction ordinateurs/utilisateurs** : Les ordinateurs ont `objectclass=user` ET `objectclass=computer`, il faut filtrer avec `(!(objectclass=computer))`
5. **UAI** : Format 7 chiffres + 1 lettre (ex: `0751234a`), utilisée pour séparer les établissements en multi-établissement

