# Observers

Les observers se déclenchent automatiquement lors des événements du cycle de vie des modèles Eloquent (requête SQL) (création, mise à jour, suppression).

## Rôle

Dans SambaEdu, les observers sont utilisés pour **synchroniser automatiquement les données SQL vers l'Active Directory** :

- Quand un modèle est créé/modifié/supprimé en SQL, l'observer dispatch un job de synchronisation AD
- Cela garantit que l'AD reste cohérent avec la base de données

## Observers disponibles

### WorkstationGroupObserver

Synchronise les groupes de machines (WorkstationGroup) vers l'AD :

- **`created()`** : Crée l'AppProfile correspondant en SQL (pour les salles physiques), puis dispatch le job de sync AD
- **`updated()`** : Détecte les changements (nom, parent), renomme le AppProfile associé si même nom, et dispatch les jobs appropriés (rename, move)
- **`deleting()`** : Supprime le AppProfile associé si même nom, puis dispatch le job de suppression AD

### AppProfileObserver

Synchronise les profils applicatifs (AppProfile) vers l'AD :

- **`created()`** : Dispatch le job de création du groupe CN dans OU=Parcs
- **`updated()`** : Détecte les changements de nom et dispatch le job de renommage AD
- **`deleting()`** : Dispatch le job de suppression du groupe CN de l'AD

### WorkstationObserver

Synchronise les machines (Workstation) vers l'AD :

- Gère l'ajout/retrait des machines dans les groupes AD
- Gère le déplacement des machines entre salles

## Architecture

```
WorkstationGroup (SQL)
    │
    ├── WorkstationGroupObserver
    │       ├── Crée/Renomme/Supprime AppProfile associé
    │       └── Dispatch WorkstationGroupAdSyncJob
    │
    └── AppProfile (SQL)
            │
            └── AppProfileObserver
                    └── Dispatch AppProfileAdSyncJob
```

## Désactivation temporaire

Pour les imports en masse, on peut désactiver temporairement la sync AD :

```php
WorkstationGroupObserver::disableSync();
AppProfileObserver::disableSync();
// ... import en masse ...
WorkstationGroupObserver::enableSync();
AppProfileObserver::enableSync();
```

Ou avec le helper `withoutSync()` :

```php
WorkstationGroupObserver::withoutSync(function () {
    // ... import en masse ...
});
```

## Enregistrement

Les observers sont enregistrés dans `AppServiceProvider::boot()` :

```php
WorkstationGroup::observe(WorkstationGroupObserver::class);
AppProfile::observe(AppProfileObserver::class);
```