# Observers

Les observers se déclenchent automatiquement lors des événements du cycle de vie des modèles Eloquent (requête SQL) (création, mise à jour, suppression).

## Rôle

Dans SambaEdu, les observers sont utilisés pour **synchroniser automatiquement les données SQL vers l'Active Directory** :

- Quand un modèle est créé/modifié/supprimé en SQL, l'observer dispatch un job de synchronisation AD
- Cela garantit que l'AD reste cohérent avec la base de données

## Observers disponibles

> **Story 38.7 — asymétrie `OU=Parcs` / `OU=Computers`.** `OU=Parcs` est un
> vestige SE4 en LECTURE SEULE : lu à l'import de migration (`sync-from-ad`),
> jamais écrit. Seule l'`OU` d'une salle physique sous `OU=Computers` reste
> synchronisée (rangement des machines + liens GPO). Les groupes LOGIQUES et les
> profils applicatifs (`AppProfile`) sont purement SQL.

### WorkstationGroupObserver

Synchronise les **salles physiques** (`is_physical = true`) vers leur `OU` sous
`OU=Computers`. Les groupes logiques ne produisent AUCUN job.

- **`created()`** : groupe physique → dispatch du job de création de l'OU
- **`updated()`** : groupe physique → dispatch des jobs (rename, move) selon les changements
- **`deleting()`** : groupe physique → dispatch du job de suppression de l'OU

La création automatique d'un `AppProfile` (profil WPKG) a été **retirée** en 38.7 :
un profil se crée dans `/parc-settings/profiles` et s'attache explicitement.

### AppProfileObserver

Ne dispatche plus aucun job AD (38.7) : un `AppProfile` n'a plus de représentation
écrite dans `OU=Parcs`. L'observer ne conserve que le drapeau `disableSync()` utilisé
par l'importeur de migration.

### WorkstationObserver

Synchronise les machines (Workstation) vers l'AD :

- Gère l'ajout/retrait des machines dans les groupes AD
- Gère le déplacement des machines entre salles

## Architecture

```
WorkstationGroup (SQL)
    │
    └── WorkstationGroupObserver
            └── [is_physical == true seulement] Dispatch WorkstationGroupAdSyncJob
                    └── OU sous OU=Computers (rangement + GPO)

AppProfile (SQL)  ── purement SQL, aucune écriture AD (OU=Parcs en lecture seule)
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