# TestingPlan

L'idée est de comparer les résultats dans l'AD de différentes actions effectuées en premier sur legacy puis une seconde action similaire faite en laravel.

# processus d'élaboration du plan:

1. Lister les actions à tester.
2. pour chaque action indiquer toutes les actions d'écriture effectuées dans l'AD par le legacy.
3. rédiger le script de test legacy.
4. faire fonctionner le script de A à Z.
5. rédiger le script de test laravel par catégories.
7. Comparer les résultats.
Il faudra prendre en compte le fait que laravel utilise des jobs pour écrire dans l'AD. ça devrait être rapide mais pas instantané.

# features

## parc

### 1. Actions à tester

| # | Action | Écrit vers AD | Priorité |
|---|--------|---------------|----------|
| P1 | Créer un parc (type=parc) | OUI (CN dans OU=Parcs + OU dans OU=Computers) | Haute |
| P2 | Créer une salle (type=salle) | OUI (CN + OU) | Haute |
| P3 | Renommer un parc/salle | OUI (Renomme CN + OU) | Haute |
| P4 | Supprimer un parc/salle | OUI (Supprime OU + CN) | Haute |
| P5 | Déplacer une salle (changer parent) | OUI (Déplace OU + met à jour groupes) | Moyenne |
| P6 | Ajouter une machine à un parc | OUI (Ajoute membre au CN) | Haute |
| P7 | Retirer une machine d'un parc | OUI (Retire membre du CN) | Haute |
| P8 | Déplacer une machine vers une salle | OUI (Déplace machine + maj groupes CN) | Moyenne |

### 2. Écritures AD par action (à compléter avec legacy)

#### P1 - Créer un parc
**Laravel:**
- Crée CN `{nom}` dans `OU=Parcs,OU={etab},DC=...`
- Stocke `ad_guid_cn` en SQL
- Note: Un parc (type=parc) n'a PAS d'OU, seulement un groupe CN

**Legacy:** (documenté - voir `/includes/ldap.inc.php:create_parc()`)

1. **Création du groupe CN** via `groupadd()` (ldap.inc.php:3646)
   ```
   ldap_add($bind, "CN={nom},OU=Parcs,OU={etab},DC=...", [
       "cn" => "{nom}",
       "objectclass" => ["top", "group"],
       "samaccountname" => "{nom}{suffix}",
       "grouptype" => 0x80000002,  // = -2147483646 signé (Domain Local Security Group)
       "description" => "{description}",  // optionnel
   ])
   ```

2. **Si type=salle** : Création de l'OU via `ouadd()` (ldap.inc.php:3647-3659)
   - Détermine le DN parent selon `$parentou`
   - Crée `OU={nom}` dans le parent approprié
   - **Note**: Pour un parc simple (type=parc), cette étape est IGNORÉE

**Différences Legacy/Laravel:**
- ✓ Pour type=parc : identique (seulement CN, pas d'OU)
- ✓ grouptype: identique (0x80000002 = -2147483646)
- ✓ Attributs CN: identiques (cn, samaccountname, objectclass, description)
- ⚠ Laravel stocke le GUID en SQL (ad_guid_cn), legacy non

#### P2 - Créer une salle
**Laravel:**
- Crée CN `{nom}` dans `OU=Parcs,OU={etab},DC=...`
- Crée OU `{nom}` dans `OU={parent},OU=Computers,OU={etab},DC=...`
- Stocke `ad_guid_cn` et `ad_guid_ou` en SQL

**Legacy:** (documenté - voir `/includes/ldap.inc.php:create_parc()` et `/includes/samba-tool.inc.php`)

1. **Création du groupe CN** via `groupadd()` (samba-tool.inc.php:562-611)
   ```
   ldap_add($bind, "CN={nom},OU=Parcs,OU={etab},DC=...", [
       "cn" => "{nom}",
       "objectclass" => ["top", "group"],
       "samaccountname" => "{nom}{suffix}",
       "grouptype" => 0x80000002,  // = -2147483646 signé (Domain Local Security Group)
       "description" => "{description}",  // optionnel
   ])
   ```

2. **Création de l'OU** via `ouadd()` (samba-tool.inc.php:387-431)
   ```
   ldap_add($bind, "OU={nom},OU=Computers,OU={etab},DC=...", [
       "ou" => "{nom}",
       "objectClass" => "organizationalUnit",
   ])
   ```
   Si parent spécifié: `OU={nom},OU={parent},OU=Computers,OU={etab},DC=...`

**Différences Legacy/Laravel:**
- ✓ grouptype: identique (0x80000002 = -2147483646)
- ✓ Attributs CN: identiques (cn, samaccountname, objectclass, description)
- ✓ Attributs OU: identiques (ou, objectClass)
- ⚠ Laravel stocke les GUIDs en SQL (ad_guid_cn, ad_guid_ou), legacy non

#### P3 - Renommer un parc/salle
**Laravel:**
- Renomme CN dans OU=Parcs
- Renomme OU dans OU=Computers

**Legacy:**
- [ ] À documenter

#### P4 - Supprimer un parc/salle
**Laravel:**
- Supprime OU de OU=Computers (avec vérif si vide)
- Supprime CN de OU=Parcs

**Legacy:** (documenté - voir `/includes/ldap.inc.php:delete_parc()`)

1. **Si type=salle** : Appel à `rename_salle()` avec `new_dn=""` (ldap.inc.php:3674-3741)
   - Déplace temporairement les machines vers des OUs temporaires
   - Supprime l'OU de la salle via `delete_ad()`
   - Remet les machines en place dans l'OU parent
   - Nettoie les OUs temporaires

2. **Suppression du groupe CN** via `delete_ad()` (ldap.inc.php:1706-1735)
   ```
   ldap_delete($bind, "CN={nom},OU=Parcs,OU={etab},DC=...")
   apcu_store("ldap_cache_invalid", true, 60)
   ```

3. **Suppression des délégations** si existantes (ldap.inc.php:3762-3767)
   - Récupère les délégations via `list_delegations()`
   - Pour chaque délégation : `groupdel()` (samba-tool.inc.php:667-688)
   ```
   ldap_delete($bind, $delegation_dn)
   apcu_store("ldap_delegation_cache_invalid", true, 60)
   ```

**Ordre des opérations Legacy:**
1. Si salle : déplacer machines vers parent (rename_salle avec new_dn="")
2. Supprimer le groupe CN dans OU=Parcs
3. Supprimer les groupes de délégation associés

**Différences Legacy/Laravel:**
- ⚠ Legacy gère les salles avec enfants (déplacement temporaire des machines)
- ⚠ Legacy supprime les délégations associées
- ✓ Ordre de suppression : OU d'abord (si salle), puis CN, puis délégations

#### P5 - Déplacer une salle
**Laravel:**
- Déplace OU vers nouveau parent
- Récupère machines dans l'OU
- Pour chaque machine: retire des anciens groupes CN parents, ajoute aux nouveaux

**Legacy:**
- [ ] À documenter

#### P6 - Ajouter machine à un parc
**Laravel:**
- Modifie attribut `member` du CN groupe (ajoute DN machine)

**Legacy:** (documenté - voir `/includes/ldap.inc.php:add_member_parc()`)

1. **Recherche du parc** via `search_parcs()` (ldap.inc.php:3999)
   - Récupère le DN du groupe CN (`gdn`)

2. **Recherche de la machine** via `search_machine()` (ldap.inc.php:4000)
   - Récupère le DN de la machine

3. **Ajout au groupe** via `groupaddmemberbydn()` (samba-tool.inc.php:888-912)
   ```
   $info["member"] = $machine_dn
   ldap_mod_add($bind, $group_dn, $info)
   ```
   - Si erreur "Already exists" → retourne true (idempotent)
   - Invalide le cache APCu selon le type de groupe:
     - `/rights/` → `ldap_rights_cache_invalid`
     - `/delegations/` → `ldap_delegation_cache_invalid`
     - Autre → `ldap_cache_invalid`

**Différences Legacy/Laravel:**
- ✓ Opération identique : `ldap_mod_add` sur attribut `member`
- ✓ Idempotence : les deux gèrent le cas "déjà membre"
- ⚠ Legacy invalide différents caches APCu selon le type de groupe

#### P7 - Retirer machine d'un parc
**Laravel:**
- Modifie attribut `member` du CN groupe (retire DN machine)

**Legacy:** (documenté - voir `/includes/ldap.inc.php:remove_member_parc()`)

1. **Recherche du parc** via `search_parcs()` (ldap.inc.php:4014)
   - Récupère le DN du groupe CN (`gdn`)

2. **Recherche de la machine** via `search_machine()` (ldap.inc.php:4015)
   - Récupère le DN de la machine

3. **Cas spécial salle** : Si le parc est une salle ET la machine est dans l'OU de cette salle (ldap.inc.php:4017-4018)
   - Appelle `move_member_parc()` pour déplacer la machine vers le parent
   - Ne retire pas simplement du groupe, mais déplace physiquement

4. **Cas normal** : Retrait du groupe via `groupdelmemberbydn()` (samba-tool.inc.php:852-877)
   ```
   $info["member"] = $machine_dn
   ldap_mod_del($bind, $group_dn, $info)
   ```
   - Si erreur "unwilling" → retourne true (idempotent)
   - Invalide le cache APCu selon le type de groupe:
     - `/rights/` → `ldap_rights_cache_invalid`
     - `/delegations/` → `ldap_delegation_cache_invalid`
     - Autre → `ldap_cache_invalid`

**Différences Legacy/Laravel:**
- ✓ Opération identique : `ldap_mod_del` sur attribut `member`
- ✓ Idempotence : les deux gèrent le cas "déjà retiré"
- ⚠ Legacy a une logique spéciale pour les salles (déplacement physique)
- ⚠ Legacy invalide différents caches APCu selon le type de groupe

#### P8 - Déplacer machine vers salle
**Laravel:**
- Déplace objet machine vers OU cible
- Retire machine des groupes CN de l'ancienne hiérarchie
- Ajoute machine aux groupes CN de la nouvelle hiérarchie

**Legacy:**
- [ ] À documenter

### 3. Commande Artisan pour lancer les tests

Une commande Artisan a été créée pour faciliter l'exécution des tests de comparaison Legacy/Laravel :

```bash
# Afficher le menu des tests disponibles
php artisan compare:legacy-laravel

# Lancer un test spécifique (comparaison complète)
php artisan compare:legacy-laravel P4
php artisan compare:legacy-laravel P6

# Lancer uniquement le test legacy
php artisan compare:legacy-laravel P4 --legacy

# Lancer uniquement le test Laravel
php artisan compare:legacy-laravel P4 --laravel

# Lancer uniquement le script de comparaison
php artisan compare:legacy-laravel P4 --compare

# Lancer tous les tests disponibles
php artisan compare:legacy-laravel all
```

**Avantages de la commande:**
- Menu interactif avec statut des tests
- Exécution simplifiée (pas besoin de mémoriser les chemins)
- Support des options pour tests ciblés
- Résumé automatique des résultats
- Nom explicite qui reflète qu'il s'agit de comparaisons Legacy/Laravel

### 4. Scripts de test legacy

**P2 - Créer une salle:**
- [x] `tests/Integration/LegacyLaravelComparison/legacy_create_salle_test.php` - Crée une salle et affiche les attributs AD
- [x] `tests/Integration/LegacyLaravelComparison/legacy_delete_salle_test.php` - Supprime une salle de test

**P4 - Supprimer un parc/salle:**
- [x] `tests/Integration/LegacyLaravelComparison/legacy_delete_parc_test.php` - Supprime un parc/salle et vérifie la suppression

**P6 - Ajouter une machine à un parc:**
- [x] `tests/Integration/LegacyLaravelComparison/legacy_add_machine_to_parc_test.php` - Ajoute une machine à un parc et vérifie l'ajout

**P7 - Retirer une machine d'un parc:**
- [x] `tests/Integration/LegacyLaravelComparison/legacy_remove_machine_from_parc_test.php` - Retire une machine d'un parc et vérifie le retrait

Usage:
```bash
cd /root/se4/sources/var/www/sambaedu/laravel
# P2
php tests/Integration/LegacyLaravelComparison/legacy_create_salle_test.php [nom] [description] [parent]
php tests/Integration/LegacyLaravelComparison/legacy_delete_salle_test.php [nom] [parent]
# P4
php tests/Integration/LegacyLaravelComparison/legacy_delete_parc_test.php [nom] [parent]
# P6
php tests/Integration/LegacyLaravelComparison/legacy_add_machine_to_parc_test.php [parc] [machine]
# P7
php tests/Integration/LegacyLaravelComparison/legacy_remove_machine_from_parc_test.php [parc] [machine]
```

### 5. Scripts de test Laravel

**P2 - Créer une salle:**
- [x] `tests/Integration/LegacyLaravelComparison/CreateSalleComparisonTest.php` - Test PHPUnit qui:
  - Crée une salle via AdSyncService
  - Vérifie les attributs CN dans OU=Parcs
  - Vérifie les attributs OU dans OU=Computers
  - Compare avec les attributs attendus (legacy)

**P4 - Supprimer un parc/salle:**
- [x] `tests/Integration/LegacyLaravelComparison/DeleteParcComparisonTest.php` - Test PHPUnit qui:
  - Crée puis supprime un parc via AdSyncService
  - Crée puis supprime une salle via AdSyncService
  - Vérifie la suppression du CN et de l'OU dans l'AD
  - Compare avec le comportement legacy

**P6 - Ajouter une machine à un parc:**
- [x] `tests/Integration/LegacyLaravelComparison/AddMachineToParcComparisonTest.php` - Test PHPUnit qui:
  - Crée un parc via AdSyncService
  - Ajoute une machine existante au parc
  - Vérifie l'attribut 'member' du groupe CN dans l'AD
  - Compare avec le comportement legacy

**P7 - Retirer une machine d'un parc:**
- [x] `tests/Integration/LegacyLaravelComparison/RemoveMachineFromParcComparisonTest.php` - Test PHPUnit qui:
  - Crée un parc via AdSyncService
  - Ajoute puis retire une machine du parc
  - Vérifie l'absence dans l'attribut 'member' du groupe CN
  - Compare avec le comportement legacy

Usage:
```bash
cd /root/se4/sources/var/www/sambaedu/laravel
# P2
php artisan test tests/Integration/LegacyLaravelComparison/CreateSalleComparisonTest.php
# P4
php artisan test tests/Integration/LegacyLaravelComparison/DeleteParcComparisonTest.php
# P6
php artisan test tests/Integration/LegacyLaravelComparison/AddMachineToParcComparisonTest.php
# P7
php artisan test tests/Integration/LegacyLaravelComparison/RemoveMachineFromParcComparisonTest.php
```

### 5. Comparaison des résultats

**P2 - Créer une salle:**
- [x] `tests/Integration/LegacyLaravelComparison/compare_create_salle.php` - Script de comparaison automatique

**P4 - Supprimer un parc/salle:**
- [x] `tests/Integration/LegacyLaravelComparison/compare_delete_parc.php` - Script de comparaison automatique

**P6 - Ajouter une machine à un parc:**
- [x] `tests/Integration/LegacyLaravelComparison/compare_add_machine_to_parc.php` - Script de comparaison automatique

**P7 - Retirer une machine d'un parc:**
- [x] `tests/Integration/LegacyLaravelComparison/compare_remove_machine_from_parc.php` - Script de comparaison automatique

Usage:
```bash
cd /root/se4/sources/var/www/sambaedu/laravel
# P2
php tests/Integration/LegacyLaravelComparison/compare_create_salle.php
# P4
php tests/Integration/LegacyLaravelComparison/compare_delete_parc.php
# P6
php tests/Integration/LegacyLaravelComparison/compare_add_machine_to_parc.php
# P7
php tests/Integration/LegacyLaravelComparison/compare_remove_machine_from_parc.php
```

**P2** - Ce script:
1. Crée une salle avec Legacy
2. Lit les attributs AD créés
3. Crée une salle avec Laravel
4. Lit les attributs AD créés
5. Compare les attributs côte à côte
6. Nettoie les salles de test

**P4** - Ce script:
1. Crée et supprime un parc avec Legacy
2. Vérifie la suppression dans l'AD
3. Crée et supprime un parc avec Laravel
4. Vérifie la suppression dans l'AD
5. Crée et supprime une salle avec Legacy
6. Crée et supprime une salle avec Laravel
7. Compare les résultats des suppressions

**P6** - Ce script:
1. Trouve une machine existante dans l'AD
2. Crée un parc et ajoute la machine avec Legacy
3. Vérifie l'attribut 'member' du groupe CN
4. Crée un parc et ajoute la machine avec Laravel
5. Vérifie l'attribut 'member' du groupe CN
6. Compare les résultats des ajouts
7. Nettoie les parcs de test

**P7** - Ce script:
1. Trouve une machine existante dans l'AD
2. Crée un parc, ajoute puis retire la machine avec Legacy
3. Vérifie l'absence dans l'attribut 'member' du groupe CN
4. Crée un parc, ajoute puis retire la machine avec Laravel
5. Vérifie l'absence dans l'attribut 'member' du groupe CN
6. Compare les résultats des retraits
7. Nettoie les parcs de test

---

## appProfiles

### 1. Actions à tester

| # | Action | Écrit vers AD | Priorité |
|---|--------|---------------|----------|
| A1 | Créer un profil applicatif | Non (SQL uniquement) | Haute |
| A2 | Modifier un profil applicatif | Non (SQL uniquement) | Haute |
| A3 | Supprimer un profil applicatif | Non (SQL uniquement) | Haute |
| A4 | Ajouter des applications au profil | Non (SQL uniquement) | Haute |
| A5 | Retirer des applications du profil | Non (SQL uniquement) | Moyenne |
| A6 | Lier un groupe de postes au profil | Non (SQL uniquement) | Haute |
| A7 | Délier un groupe de postes du profil | Non (SQL uniquement) | Moyenne |
| A8 | Lier un poste individuel au profil | Non (SQL uniquement) | Moyenne |
| A9 | Synchroniser profils depuis AD | LIT depuis AD | Haute |

### 2. Écritures AD par action (à compléter avec legacy)

#### A1 - Créer un profil applicatif
**Laravel:**
- INSERT dans table `app_profiles` (name, description)
- Note: Le profil est aussi créé automatiquement via Observer quand on crée un WorkstationGroup

**Legacy:**
- [ ] À documenter (utilise probablement directement les groupes AD comme profils)

#### A2 - Modifier un profil applicatif
**Laravel:**
- UPDATE table `app_profiles`
- Sync des relations (applications, workstation_groups)

**Legacy:**
- [ ] À documenter

#### A3 - Supprimer un profil applicatif
**Laravel:**
- DELETE de `app_profiles` (cascade sur pivots)

**Legacy:**
- [ ] À documenter

#### A4-A8 - Gestion des relations
**Laravel:**
- Opérations sur tables pivot:
  - `app_profile_application`
  - `app_profile_workstation_group`
  - `app_profile_workstation`

**Legacy:**
- [ ] À documenter

#### A9 - Synchroniser profils depuis AD
**Laravel:**
- Job `SyncAppProfilesFromAd` lit les parcs depuis AD via fonction legacy `search_parcs()`
- Crée/Met à jour AppProfile en SQL pour chaque parc trouvé
- Établit relation avec WorkstationGroup correspondant

**Legacy:**
- [ ] À documenter (comportement de référence)

### 3. Scripts de test legacy
- [ ] À rédiger

### 4. Scripts de test Laravel
- [ ] À rédiger

### 5. Comparaison des résultats
- [ ] À effectuer

---

## Notes techniques

### Jobs Laravel (asynchrone)
Les écritures AD dans Laravel passent par des Jobs en queue:
- `SyncWorkstationGroupToAd` - Création groupe
- `DeleteWorkstationGroupFromAd` - Suppression groupe
- `RenameWorkstationGroupInAd` - Renommage groupe
- `MoveWorkstationGroupInAd` - Déplacement salle
- `SyncWorkstationMembershipToAd` - Ajout/retrait membre

**Important pour les tests:** Attendre que les jobs soient traités avant de comparer les résultats AD. Utiliser `Queue::fake()` ou `$this->artisan('queue:work --once')` selon le besoin.

### Fichiers clés
- **Contrôleur:** `app/Http/Controllers/Admin/ParcController.php`
- **Modèles:** `app/Models/WorkstationGroup.php`, `app/Models/AppProfile.php`
- **Services:** `app/Services/AdSync/AdSyncService.php`, `app/Services/AppProfile/AppProfileService.php`
- **Observer:** `app/Observers/WorkstationGroupObserver.php`
- **Jobs:** `app/Jobs/AdSync/`



















