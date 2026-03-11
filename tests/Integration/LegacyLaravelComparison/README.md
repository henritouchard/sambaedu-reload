# Tests d'intégration - WorkstationGroup, Workstation, AppProfile

Ce dossier contient les tests d'intégration pour la gestion des groupes de postes (WorkstationGroup), 
des postes de travail (Workstation) et des profils applicatifs (AppProfile).

## Structure des fichiers

```
LegacyLaravelComparison/
├── README.md                                    # Ce fichier
│
│   # WorkstationGroup
├── WorkstationGroupCreateTest.php               # Création de WorkstationGroup
├── WorkstationGroupDeleteTest.php               # Suppression de WorkstationGroup
├── WorkstationGroupAppProfileTest.php           # Gestion automatique des AppProfiles
│
│   # Workstation membership
├── WorkstationMembershipAddTest.php             # Ajout Workstation à un groupe
├── WorkstationMembershipRemoveTest.php          # Retrait Workstation d'un groupe
│
│   # AppProfile (TODO)
├── AppProfileCrudTest.php                       # TODO: CRUD des AppProfiles
├── AppProfileWorkstationGroupAssociationTest.php # TODO: Association AppProfile <=> WorkstationGroup
│
└── Legacy/                                      # Scripts legacy (à supprimer à terme)
    ├── legacy_*.php                             # Tests du code legacy PHP
    └── compare_*.php                            # Scripts de comparaison legacy/Laravel
```

## Tests Laravel (PHPUnit)

### WorkstationGroupCreateTest
Teste la création d'un WorkstationGroup dans l'AD :
- Vérification des attributs AD du CN (cn, samaccountname, grouptype, description)
- Création de groupes avec hiérarchie parent/enfant

### WorkstationGroupDeleteTest  
Teste la suppression d'un WorkstationGroup de l'AD :
- Suppression simple d'un groupe
- Suppression de groupes avec hiérarchie parent/enfant

### WorkstationGroupAppProfileTest
Teste le comportement automatique des AppProfiles :
- Création automatique si `app_profile_name` est renseigné
- Pas de création si `app_profile_name` est null
- Renommage automatique lors du changement de `app_profile_name`
- Suppression automatique lors de la suppression du groupe

### WorkstationMembershipAddTest
Teste l'ajout d'une Workstation à un WorkstationGroup (membership AD).

### WorkstationMembershipRemoveTest
Teste le retrait d'une Workstation d'un WorkstationGroup.

### AppProfileCrudTest (TODO)
À créer : teste le CRUD complet des AppProfiles :
- Création d'un AppProfile
- Lecture/récupération d'un AppProfile
- Mise à jour d'un AppProfile (nom, description, etc.)
- Suppression d'un AppProfile

### AppProfileWorkstationGroupAssociationTest (TODO)
À créer : teste l'association manuelle entre AppProfile et WorkstationGroup :
- Associer un AppProfile existant à un WorkstationGroup
- Dissocier un AppProfile d'un WorkstationGroup
- Vérifier les relations many-to-many

## Scripts Legacy (dossier Legacy/)

Ces scripts sont conservés pour référence et comparaison avec le code legacy.
Ils seront supprimés une fois la migration complète vers Laravel terminée.

### Prérequis pour les scripts legacy

Le code legacy utilise APCu pour le cache. En mode CLI, APCu n'est pas activé par défaut :

```bash
php -d apc.enable_cli=1 <script.php>
```

### Configuration

Les tests utilisent la configuration de `/etc/sambaedu/sambaedu.conf` et les fonctions legacy de `/var/www/sambaedu/includes/`.

## Exécution des tests Laravel

```bash
# Exécuter tous les tests de ce dossier
php artisan test tests/Integration/LegacyLaravelComparison/

# Exécuter un test spécifique
php artisan test tests/Integration/LegacyLaravelComparison/WorkstationGroupCreateTest.php

# Mode rapide (via la commande interactive)
npm run test:quick
```

## Structure AD

### WorkstationGroup
Chaque WorkstationGroup crée un groupe CN dans `OU=Parcs` :
```
OU=Parcs
├── CN=groupe-1
├── CN=groupe-2
└── CN=groupe-3
```

### AppProfile (optionnel)
Si `app_profile_name` est renseigné lors de la création d'un WorkstationGroup,
un AppProfile est automatiquement créé en base de données avec ce nom.

### Valeur de grouptype
Le code utilise `grouptype = -2147483646` (0x80000002) = **Domain Local Security Group**.

---

## Dépannage

### Erreur de connexion LDAP
```
Erreur: Bind LDAP échoué: Invalid credentials
```
**Solution :** Vérifier les credentials dans `/etc/sambaedu/sambaedu.conf`.
