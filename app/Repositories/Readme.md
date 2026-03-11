# Repositories SambaEdu

## Qu'est-ce qu'un Repository ?

Un repository (ou "dépôt" en français) est un pattern de conception logicielle qui agit comme une couche d'abstraction entre la logique métier (domain) et la couche de persistance des données. Il présente une interface uniforme pour accéder aux données, comme si elles étaient une collection d'objets en mémoire.

### Structure typique :
- **Interface** : Définit les méthodes (`find()`, `save()`, `delete()`)
- **Implémentation concrète** : Utilise un ORM (Eloquent) ou des requêtes SQL ou des requêtes LDAP
- **Responsabilités** : Encapsule la logique d'accès aux données, requêtes complexes, relations

### Avantages de cette architecture :
- **Séparation des préoccupations** : Logique métier indépendante de la persistance
- **Testabilité** : Facilite les tests unitaires via injection de dépendances
- **Abstraction** : Change facilement de source de données (LDAP, base de données)
- **Réutilisabilité** : Centralise les requêtes communes
- **Performance** : Optimisations centralisées (cache, eager loading)
- **Lisibilité** : Code plus lisible et plus facile à maintenir. On sait où chercher quoi. Si on a une erreur dans une requête, on sait où la chercher, si c'est plutot une erreur de logique métier, ça évite de lire toutes les requêtes en même temps que le code métier.

## Implémentation

On utilisera généralement un repository pour un modèle de donnée (que ce soit LDAP ou SQL).