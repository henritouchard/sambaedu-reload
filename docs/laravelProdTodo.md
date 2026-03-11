# Actions à faire pour la mise en production Laravel

Ce document liste les actions nécessaires pour interdire certaines opérations legacy qui entrent en conflit avec l'architecture Laravel refactorisée.

## Contexte

Dans l'architecture refactorisée :
- **WorkstationGroup** (flag_parc=1) = Salle physique avec OU dans AD
- **AppProfile** (flag_parc=0) = Profil applicatif (CN seulement, pas d'OU)

Le legacy utilise la même table `parc` pour les deux types, ce qui crée des conflits avec Laravel qui gère uniquement les WorkstationGroup via `AdSyncService`.

---

## 1. Opérations CRUD Legacy sur la table `parc`

### 1.1 CREATE - Création de parcs/salles

| Fichier | Fonction | Description | Action requise |
|---------|----------|-------------|----------------|
| `parcs/create_parc.php` | Interface web | Crée un parc/salle via `create_parc()` | **BLOQUER** ou rediriger vers Laravel |
| `includes/ldap.inc.php` | `create_parc()` | Fonction legacy de création AD | **MODIFIER** pour appeler Laravel |
| `includes/sites.inc.php` | Appel `create_parc()` | Création lors de l'import de sites | **MODIFIER** pour appeler Laravel |
| `wpkg/wpkg_ldap_update.php` | `insert_parc()` | Sync AD → MySQL (table parc) | **REMPLACER** par job Laravel |
| `wpkg/transfert/wpkg_profile.php` | `insert_parc()` | Import de profils WPKG | **MODIFIER** pour appeler Laravel |
| `includes/wpkg_libsql.php` | `insert_parc()` | Insertion directe MySQL | **BLOQUER** - utiliser Eloquent |

### 1.2 UPDATE - Modification de parcs/salles

| Fichier | Fonction | Description | Action requise |
|---------|----------|-------------|----------------|
| `parcs/rename_parc.php` | Interface web | Renomme via `rename_parc()` | **BLOQUER** ou rediriger vers Laravel |
| `includes/ldap.inc.php` | `rename_parc()` | Fonction legacy de renommage AD | **MODIFIER** pour appeler Laravel |
| `includes/ldap.inc.php` | `rename_salle()` | Déplacement/renommage OU | **MODIFIER** pour appeler Laravel |
| `wpkg/wpkg_ldap_update.php` | `update_parc()` | Sync AD → MySQL | **REMPLACER** par job Laravel |
| `includes/wpkg_libsql.php` | `update_parc()` | Update directe MySQL | **BLOQUER** - utiliser Eloquent |

### 1.3 DELETE - Suppression de parcs/salles

| Fichier | Fonction | Description | Action requise |
|---------|----------|-------------|----------------|
| `parcs/delete_parc.php` | Interface web | Supprime via `delete_parc()` | **BLOQUER** ou rediriger vers Laravel |
| `includes/ldap.inc.php` | `delete_parc()` | Fonction legacy de suppression AD | **MODIFIER** pour appeler Laravel |
| `wpkg/wpkg_ldap_update.php` | `delete_parc_wpkg()` | Sync AD → MySQL | **REMPLACER** par job Laravel |
| `includes/wpkg_libsql.php` | `delete_parc_wpkg()` | Delete directe MySQL | **BLOQUER** - utiliser Eloquent |

### 1.4 Gestion des membres (machines)

| Fichier | Fonction | Description | Action requise |
|---------|----------|-------------|----------------|
| `parcs/create_parc.php` | `add_member_parc()` | Ajout machine à parc | **MODIFIER** pour appeler Laravel |
| `parcs/delete_parc.php` | `remove_member_parc()` | Retrait machine de parc | **MODIFIER** pour appeler Laravel |
| `ipxe/parcs.php` | `add_member_parc()` | Ajout via iPXE | **MODIFIER** pour appeler Laravel |
| `ipxe/enleveparc.php` | `remove_member_parc()` | Retrait via iPXE | **MODIFIER** pour appeler Laravel |
| `ipxe/salles.php` | `move_member_parc()` | Déplacement machine | **MODIFIER** pour appeler Laravel |
| `includes/ldap.inc.php` | `add_member_parc()` | Fonction legacy AD | **MODIFIER** pour appeler Laravel |
| `includes/ldap.inc.php` | `remove_member_parc()` | Fonction legacy AD | **MODIFIER** pour appeler Laravel |
| `includes/ldap.inc.php` | `move_member_parc()` | Fonction legacy AD | **MODIFIER** pour appeler Laravel |

---

## 1bis. Scripts automatisés (CRON) - CRITIQUE

### Script critique : `wpkg/wpkg_ldap_update.php`

**Fréquence** : Toutes les 2 minutes via cron
```
*/2 * * * * root /usr/share/sambaedu/sbin/action_cron_php.sh wpkg/wpkg_ldap_update.php
```

**Opérations effectuées** :
- `insert_parc()` - Crée des parcs dans MySQL depuis AD
- `update_parc()` - Met à jour les noms/UUID des parcs
- `delete_parc_wpkg()` - Supprime les parcs absents de l'AD

**Risque** : Ce script synchronise AD → MySQL toutes les 2 minutes, ce qui peut **écraser les modifications faites par Laravel** si les deux systèmes ne sont pas coordonnés.

### Action requise : Désactiver ce cron

**Fichier** : `/etc/cron.d/sambaedu-wpkg`

```bash
# AVANT (actif)
*/2 * * * * root /usr/share/sambaedu/sbin/action_cron_php.sh wpkg/wpkg_ldap_update.php

# APRÈS (désactivé)
# Désactivé pour migration Laravel - utiliser le job SyncWorkstationGroupsFromAd
# */2 * * * * root /usr/share/sambaedu/sbin/action_cron_php.sh wpkg/wpkg_ldap_update.php
```

### Autres scripts cron (lecture seule - OK)

| Script | Fréquence | Opérations |
|--------|-----------|------------|
| `wpkg/wpkg_rapport.php` | 1 min | Lecture rapports |
| `parcs/action_cron.php` | 15 min | Actions WOL/stop (lecture AD) |
| `wpkg/wpkg_depot_import.php` | 5 min | Import dépôt (pas de table parc) |

---

## 2. Stratégie de migration

### Phase 1 : Wrapper de compatibilité (URGENT)

Modifier les fonctions legacy dans `ldap.inc.php` pour qu'elles appellent les services Laravel :

```php
// Exemple pour create_parc()
function create_parc(array $config, string $parc, string $description = "", string $type = "salle", string $parentou = "")
{
    // Appeler Laravel via API ou service
    $result = call_laravel_service('workstation-group.create', [
        'nom_parc' => $parc,
        'description' => $description,
        'type' => $type,
        'parent' => $parentou
    ]);
    return $result['success'];
}
```

### Phase 2 : Bloquer les interfaces legacy

Ajouter des redirections ou messages d'erreur dans les fichiers d'interface :

- `parcs/create_parc.php` → Rediriger vers `/app/workstation-groups/create`
- `parcs/delete_parc.php` → Rediriger vers `/app/workstation-groups`
- `parcs/rename_parc.php` → Rediriger vers `/app/workstation-groups`

### Phase 3 : Remplacer wpkg_ldap_update.php

Le script `wpkg/wpkg_ldap_update.php` synchronise l'AD vers MySQL. Il doit être remplacé par :

1. **Job Laravel** : `SyncWorkstationGroupsFromAd` (déjà créé)
2. **Scheduler** : Exécuter le job périodiquement
3. **Désactiver** : Commenter l'appel dans `infos/fix_se4.php`

---

## 3. Fichiers à modifier

### Priorité HAUTE (bloquants)

1. **`includes/ldap.inc.php`** - Fonctions CRUD parcs
   - `create_parc()` → Appeler `AdSyncService::createWorkstationGroup()`
   - `delete_parc()` → Appeler `AdSyncService::deleteWorkstationGroup()`
   - `rename_parc()` → Appeler `AdSyncService::renameWorkstationGroup()`
   - `add_member_parc()` → Appeler `AdSyncService::addMemberToGroup()`
   - `remove_member_parc()` → Appeler `AdSyncService::removeMemberFromGroup()`
   - `move_member_parc()` → Appeler `AdSyncService::moveMachineToSalle()`

2. **`includes/wpkg_libsql.php`** - Fonctions SQL directes
   - `insert_parc()` → **BLOQUER** (lever exception ou log warning)
   - `update_parc()` → **BLOQUER**
   - `delete_parc_wpkg()` → **BLOQUER**

### Priorité MOYENNE (interfaces)

3. **`parcs/create_parc.php`** - Interface création
   - Ajouter redirection vers Laravel ou appeler service Laravel

4. **`parcs/delete_parc.php`** - Interface suppression
   - Ajouter redirection vers Laravel ou appeler service Laravel

5. **`parcs/rename_parc.php`** - Interface renommage
   - Ajouter redirection vers Laravel ou appeler service Laravel

### Priorité BASSE (scripts secondaires)

6. **`wpkg/wpkg_ldap_update.php`** - Sync AD → MySQL
   - Remplacer par job Laravel `SyncWorkstationGroupsFromAd`

7. **`ipxe/parcs.php`**, **`ipxe/salles.php`**, **`ipxe/enleveparc.php`**
   - Modifier pour appeler les services Laravel

8. **`infos/fix_se4.php`** - Appel wpkg_ldap_update
   - Remplacer par dispatch du job Laravel

---

## 4. Tests de non-régression

Avant chaque modification, vérifier que les tests de comparaison passent :

```bash
cd /var/www/sambaedu/laravel
php artisan compare:legacy-laravel all
```

Tests actuels (6/6 passent) :
- P2 - Créer une salle (WorkstationGroup)
- P3 - Renommer une salle
- P4 - Supprimer un parc/salle
- P5 - Déplacer une salle
- P6 - Ajouter une machine à un parc
- P7 - Retirer une machine d'un parc

---

## 5. Checklist de mise en production

- [ ] Modifier `ldap.inc.php` pour wrapper les fonctions CRUD
- [ ] Bloquer les fonctions SQL directes dans `wpkg_libsql.php`
- [ ] Rediriger les interfaces legacy vers Laravel
- [ ] Remplacer `wpkg_ldap_update.php` par job Laravel
- [ ] Mettre à jour `fix_se4.php` pour utiliser le job Laravel
- [ ] Tester les scripts iPXE avec les nouveaux services
- [ ] Valider tous les tests de comparaison
- [ ] Déployer en staging et tester manuellement
- [ ] Déployer en production

---

## 6. Notes importantes

### Différence AppProfile vs WorkstationGroup

Dans le legacy, `flag_parc` distingue :
- `flag_parc = 0` : "parc" logique (AppProfile) - CN seulement dans OU=Parcs
- `flag_parc = 1` : "salle" physique (WorkstationGroup) - CN + OU

Dans Laravel refactorisé :
- `WorkstationGroup` = toujours une salle avec OU
- `AppProfile` = entité séparée, pas gérée par `AdSyncService`

**Conséquence** : Les créations de "parcs" (flag_parc=0) via le legacy ne doivent plus passer par `create_parc()` mais par un service dédié aux AppProfile.

### Synchronisation bidirectionnelle

Le legacy synchronise AD → MySQL via `wpkg_ldap_update.php`.
Laravel synchronise MySQL → AD via les Observers et Jobs.

Pour éviter les conflits :
1. **Désactiver** la sync legacy AD → MySQL
2. **Utiliser uniquement** Laravel pour les modifications
3. **Garder** la lecture AD pour les scripts qui en ont besoin
