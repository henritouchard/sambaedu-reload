# Plan de refonte du système de droits SambaEdu 4.6

## Sommaire

1. [Analyse du système legacy](#1-analyse-du-système-legacy)
2. [Analyse de l'existant Laravel](#2-analyse-de-lexistant-laravel)
3. [Pourquoi spatie/laravel-permission](#3-pourquoi-spatielaravel-permission)
4. [Architecture cible](#4-architecture-cible)
5. [Mapping legacy → Spatie](#5-mapping-legacy--spatie)
6. [Délégations scopées par WorkstationGroup : un concept distinct mais intégrable](#6-délégations-scopées-par-workstationgroup--un-concept-distinct-mais-intégrable)
7. [GPO et délégations : explication du cas d'usage](#7-gpo-et-délégations--explication-du-cas-dusage)
8. [Modèle de données](#8-modèle-de-données)
9. [Plan d'implémentation](#9-plan-dimplémentation)
10. [Migration des données legacy](#10-migration-des-données-legacy)

---

## 1. Analyse du système legacy

### 1.1 Droits globaux (bitmask dans AD)

Le legacy utilise un système de **bitmask sur 16 bits** stocké dans l'attribut `info` de groupes AD situés dans la branche `OU=Rights`.

```
Bit   Hex      Constante               Description
----- -------- ----------------------- ------------------------------------------
0     0x01     SE_USER_PASSWORD_INIT   Réinitialiser les mots de passe
1     0x02     SE_USER_READ            Consulter l'annuaire utilisateurs
2     0x04     SE_USER_MODIFY          Modifier les utilisateurs
3     0x08     SE_USER_CREATE_TEMP     Créer des comptes temporaires
4     0x10     SE_USER_ASSIGN_RIGHT    Assigner des droits aux utilisateurs
5     0x20     SE_USER_DELEGATE        Déléguer la gestion de parcs
6     0x40     SE_SHARE_VIEW           Voir les partages et quotas
7     0x80     SE_SHARE_REFRESH        Actualiser les partages
8     0x100    SE_COMPUTER_VIEW        Voir les machines et parcs
9     0x200    SE_COMPUTER_CONTROL     Contrôle à distance (VNC/RDP)
10    0x400    SE_COMPUTER_ELEVATE     Admin local temporaire (GPO)
11    0x800    SE_COMPUTER_INSTALL     Installation automatisée
12    0x1000   SE_WPKG_ASSIGN          Affecter des apps aux machines
13    0x2000   SE_WPKG_ADD             Ajouter des apps du référentiel
14    0x4000   SE_WPKG_CREATE          Créer des recettes d'installation
15    0x8000   SE_SERVER_ADMIN         Administrer les serveurs
```

**Raccourcis composites :**
- `SE_ELEVE_ADMIN` = 0x07 (PASSWORD_INIT | USER_READ | USER_MODIFY)
- `SE_USER_ADMIN` = 0xFF (tous les droits utilisateurs)
- `SE_SHARE_ADMIN` = 0xC0 (SHARE_VIEW | SHARE_REFRESH)
- `SE_COMPUTER_ADMIN` = 0xEF00 (tous les droits machines + WPKG)
- `SE_ADMIN` = 0xFFFF (super admin)

**Fonctionnement :**
- Un utilisateur est membre de groupes AD dans `OU=Rights`
- Chaque groupe a un attribut `info` contenant un bitmask
- `list_rights()` parcourt récursivement les groupes et fait un OR des bitmasks
- Les groupes préfixés `no_` annulent des droits (AND NOT)
- Le résultat est caché dans APCu pendant 300s

### 1.2 Délégations (groupes AD dans OU=Delegations)

Les délégations sont un **mécanisme séparé** qui permet d'accorder des droits **limités à un WorkstationGroup physique spécifique** (anciennement "parc").

**Principe :** Un admin peut dire "l'utilisateur X a le droit `SE_COMPUTER_VIEW` **uniquement sur le WorkstationGroup physique Salle-Info-1**".

**Stockage AD :**
- Branche `OU=Delegations` dans l'AD
- Chaque délégation est un **groupe AD** nommé `{profil}_{parc}` (ex: `parc_can_view_SalleInfo1`)
- Le groupe contient comme membres : l'utilisateur bénéficiaire ET le parc concerné
- L'attribut `info` pointe vers un profil de droits (dans `OU=Rights`)
- Les délégations négatives sont préfixées `no_` (ex: `no_parc_can_view_SalleInfo1`)

**Fonctions legacy :**
- `create_delegation($config, $parc, $user, $level)` : crée le groupe AD
- `delete_delegation($config, $delegation, $user)` : supprime
- `list_delegations($config, $login)` : liste récursive (hérite des groupes parents)
- `type_delegation($config, $parc, $user)` : droits effectifs sur un WorkstationGroup
- `have_right_or_delegation($config, $right, $user)` : vérifie droit global OU délégation

**Héritage :** Les délégations sont héritées via les groupes AD (`memberOf`). Si un groupe "Profs-Maths" a une délégation sur un parc, tous les membres du groupe en héritent.

### 1.3 GPO et délégations : le fichier `delegations.inc.php`

Le fichier `delegations.inc.php` (à ne pas confondre avec les délégations de parcs de la section 1.2) gère un **troisième aspect** : l'assignation de **GPO (Group Policy Objects)** à des OU (salles ou utilisateurs). C'est un mécanisme **distinct** des délégations de parcs.

**Ce que ça fait concrètement :**
- Quand un utilisateur reçoit le droit `SE_COMPUTER_ELEVATE` (admin local) sur un parc via une délégation, il faut que Windows le sache
- Pour cela, on crée une **GPO** qui ajoute l'utilisateur au groupe `Administrateurs locaux` (SID S-1-5-32-544) de chaque machine du parc
- La GPO est liée à l'OU du parc dans l'AD, et Windows l'applique automatiquement

**Fonctions :**
- `add_delegation_salle()` : crée la GPO + la lie à l'OU du parc
- `remove_delegation_salle()` : supprime la GPO ou le lien
- `add_delegation_policy()` : écrit dans le fichier `GptTmpl.inf` de la GPO (section `[Group Membership]`)
- `list_delegation_policies()` : lit les policies existantes

**En résumé :** `delegations.inc.php` est le **mécanisme d'exécution côté Windows** des délégations de type "admin local". Ce n'est pas un système de droits en soi, c'est la **synchronisation AD/GPO** qui rend effective la délégation `SE_COMPUTER_ELEVATE`.

---

## 2. Analyse de l'existant Laravel

### 2.1 Ce qui existe déjà

| Composant | Fichier | Rôle |
|-----------|---------|------|
| `RightsService` | `app/Services/RightsService.php` | Constantes SE_*, calcul bitmask, `hasRight()` |
| `RightRepository` | `app/Repositories/RightRepository.php` | Lecture des groupes de droits depuis LDAP |
| `AuthUser` | `app/Models/AuthUser.php` | Wrapper LDAP, implémente Authenticatable, expose `hasRight()`, `getRightsMask()` |
| `GroupPolicy` | `app/Policies/GroupPolicy.php` | Policy basée sur `SE_USER_ADMIN` |
| `UserPolicy` | `app/Policies/UserPolicy.php` | Policy basée sur `SE_USER_ADMIN` / `SE_USER_ASSIGN_RIGHT` |
| `ShortcutPolicy` | `app/Policies/ShortcutPolicy.php` | Policy basée sur `SE_COMPUTER_ADMIN` |
| `RegistersGates` | `app/Policies/Traits/RegistersGates.php` | Trait pour enregistrer des Gates automatiquement |

### 2.2 Limites actuelles

- **Pas de granularité** : les policies vérifient `SE_USER_ADMIN` (0xFF = tous les droits users) au lieu de droits fins
- **Pas de délégations** : aucun support pour "droit X sur parc Y"
- **Pas de persistance SQL** : tout vient du LDAP à chaque requête (cache APCu 300s)
- **Pas de gestion d'attribution** : impossible d'attribuer/retirer des droits depuis Laravel
- **Couplage bitmask** : le système de bits est fragile et peu extensible (16 bits max)

---

## 3. Pourquoi spatie/laravel-permission

### 3.1 Ce que Spatie apporte

| Fonctionnalité | Bitmask legacy | Spatie |
|----------------|:-:|:-:|
| Permissions nommées | Non (bits opaques) | Oui (`user.read`, `computer.view`) |
| Rôles | Non (raccourcis composites) | Oui (`admin`, `prof`, `technicien`) |
| Assignation via UI | Non (manipulation AD) | Oui (`$user->givePermissionTo(...)`) |
| Héritage role->permissions | Non (groupes AD) | Oui (natif) |
| Permissions par scope | Non | Oui (via teams/guards) |
| Middleware Laravel | Non | Oui (`role:admin`, `permission:user.read`) |
| Blade directives | Non | Oui (`@can`, `@role`, `@hasanypermission`) |
| Policies intégrées | Partiel | Oui (natif) |
| Extensible | Non (16 bits max) | Oui (illimité) |
| Audit trail | Non | Oui (avec spatie/activitylog) |

### 3.2 Compatibilité avec le legacy

Spatie peut coexister avec le système legacy pendant la migration :
- Les permissions Spatie sont nommées pour correspondre aux constantes SE_*
- Un service de synchronisation lit les droits AD et les traduit en permissions Spatie
- Les Policies existantes sont adaptées pour utiliser `$user->can()` au lieu de `hasRight()`
- Le bitmask legacy reste disponible via `RightsService` pour la compatibilité

### 3.3 Contrainte : AuthUser n'est pas un modèle Eloquent

Spatie attend un modèle Eloquent avec le trait `HasRoles`. Or `AuthUser` est un wrapper LDAP qui implémente `Authenticatable` sans Eloquent.

**Solution :** Créer un modèle `User` Eloquent qui sert de **cache SQL** des utilisateurs AD. Ce modèle :
- Est synchronisé depuis l'AD (login, fullname, groupes)
- Porte le trait `HasRoles` de Spatie
- Est utilisé par le système d'auth Laravel (`Auth::user()`)
- Remplace progressivement `AuthUser`

---

## 4. Architecture cible

```
+-------------------------------------------------------------+
|                    SOURCES DE VERITE                          |
+----------------------+--------------------------------------+
|   Active Directory   |         Base SQL (MySQL)              |
|                      |                                       |
|  - Authentification  |  - Permissions (spatie)               |
|  - Groupes AD        |  - Roles (spatie)                     |
|  - Profils legacy    |  - Delegations (table custom)         |
|                      |  - Cache utilisateurs                 |
|                      |  - Audit log                          |
+----------+-----------+--------------+-----------------------+
           |                          |
           v                          v
+-------------------------------------------------------------+
|                   COUCHE SERVICE                             |
|                                                              |
|  PermissionService                                           |
|  +-- syncFromAd($login)     : sync AD -> SQL                |
|  +-- grantPermission($user, $perm, ?$wkGroup)               |
|  +-- revokePermission($user, $perm, ?$wkGroup)              |
|  +-- hasPermission($user, $perm, ?$wkGroup)                 |
|  +-- getDelegations($user)                                   |
|  +-- syncToGpo($user, $wkGroup) : GPO si SE_COMPUTER_ELEVATE|
|                                                              |
|  RightsService (existant, compatibilite bitmask)             |
|  RightRepository (existant, lecture LDAP)                    |
+-------------------------------------------------------------+
           |
           v
+-------------------------------------------------------------+
|                   COUCHE AUTORISATION                        |
|                                                              |
|  Policies Laravel (UserPolicy, WorkstationGroupPolicy, etc.)|
|  +-- Utilisent $user->can('permission-name')                |
|  +-- Supportent les delegations via PermissionService       |
|  +-- Blade : @can('computer.view', $workstationGroup)       |
|                                                              |
|  Middleware                                                   |
|  +-- role:admin,technicien                                   |
|  +-- permission:user.read                                    |
|  +-- sambaedu.auth (existant)                                |
+-------------------------------------------------------------+
```

---

## 5. Mapping legacy -> Spatie

### 5.1 Permissions

Chaque bit legacy devient une **permission nommée** Spatie :

| Constante legacy | Permission Spatie | Guard |
|---|---|---|
| `SE_USER_PASSWORD_INIT` | `user.password.init` | web |
| `SE_USER_READ` | `user.read` | web |
| `SE_USER_MODIFY` | `user.modify` | web |
| `SE_USER_CREATE_TEMP` | `user.create.temp` | web |
| `SE_USER_ASSIGN_RIGHT` | `user.assign.right` | web |
| `SE_USER_DELEGATE` | `user.delegate` | web |
| `SE_SHARE_VIEW` | `share.view` | web |
| `SE_SHARE_REFRESH` | `share.refresh` | web |
| `SE_COMPUTER_VIEW` | `computer.view` | web |
| `SE_COMPUTER_CONTROL` | `computer.control` | web |
| `SE_COMPUTER_ELEVATE` | `computer.elevate` | web |
| `SE_COMPUTER_INSTALL` | `computer.install` | web |
| `SE_WPKG_ASSIGN` | `wpkg.assign` | web |
| `SE_WPKG_ADD` | `wpkg.add` | web |
| `SE_WPKG_CREATE` | `wpkg.create` | web |
| `SE_SERVER_ADMIN` | `server.admin` | web |

### 5.2 Roles

Les raccourcis composites legacy deviennent des **rôles** Spatie :

| Raccourci legacy | Role Spatie | Permissions incluses |
|---|---|---|
| `SE_ELEVE_ADMIN` | `eleve-admin` | user.password.init, user.read, user.modify |
| `SE_USER_ADMIN` | `user-admin` | toutes les permissions user.* + share.* |
| `SE_SHARE_ADMIN` | `share-admin` | share.view, share.refresh |
| `SE_COMPUTER_ADMIN` | `computer-admin` | toutes les permissions computer.* + wpkg.* |
| `SE_ADMIN` | `super-admin` | TOUTES les permissions |

**Roles metier supplementaires** (non-legacy, a creer) :

| Role | Permissions |
|---|---|
| `eleve` | (aucune permission admin) |
| `prof` | user.read (voir ses eleves) |
| `technicien` | computer.view, computer.control, wpkg.assign |
| `referent-numerique` | computer.view, wpkg.assign, wpkg.add |

### 5.3 Correspondance bitmask <-> permission (compatibilite)

Le `RightsService` existant est conserve et enrichi d'un mapping :

```php
// Dans PermissionService
const BITMASK_TO_PERMISSION = [
    RightsService::SE_USER_PASSWORD_INIT => 'user.password.init',
    RightsService::SE_USER_READ          => 'user.read',
    RightsService::SE_USER_MODIFY        => 'user.modify',
    // ...
];
```

Cela permet de convertir dans les deux sens pendant la periode de cohabitation.

---

## 6. Delegations scopees par WorkstationGroup : un concept distinct mais integrable

### 6.1 Le probleme

Spatie gere des permissions **globales** : "l'utilisateur X a la permission `computer.view`" = il peut voir **toutes** les machines.

Le legacy a des **delegations scopees** : "l'utilisateur X a `computer.view` **sur le WorkstationGroup physique SalleInfo1 uniquement**".

**Nomenclature :** Dans le legacy, ces scopes s'appelaient "parcs". Dans Laravel, le modele `WorkstationGroup` (table `workstation_groups`) existe deja avec le champ `is_physical` :
- **WorkstationGroup physique** (`is_physical=true`) : correspond aux anciennes OU dans `OU=Computers`. C'est sur ces groupes qu'on applique les GPO et les WPKG.
- **WorkstationGroup logique** (`is_physical=false`) : regroupe des workstations de n'importe quel groupe physique pour leur appliquer des AppProfiles (WPKG).

Les delegations s'appliquent aux **WorkstationGroups physiques** (ceux qui correspondent a des salles/parcs reels).

### 6.2 La solution : table `delegations` custom

Spatie ne gere pas nativement le scoping par ressource. On cree une table `delegations` qui etend le systeme. Elle reference directement la table `workstation_groups` existante :

```sql
CREATE TABLE delegations (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               BIGINT UNSIGNED NOT NULL,        -- FK vers users
    workstation_group_id  BIGINT UNSIGNED NOT NULL,        -- FK vers workstation_groups (physiques)
    permission_id         BIGINT UNSIGNED NOT NULL,        -- FK vers spatie permissions
    is_negative           BOOLEAN DEFAULT FALSE,           -- delegation d'exclusion (no_)
    granted_by            BIGINT UNSIGNED NULL,            -- qui a accorde
    expires_at            TIMESTAMP NULL,                  -- expiration optionnelle
    created_at            TIMESTAMP,
    updated_at            TIMESTAMP,

    UNIQUE KEY unique_delegation (user_id, workstation_group_id, permission_id, is_negative),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (workstation_group_id) REFERENCES workstation_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
);
```

### 6.3 Logique de verification

```php
// Dans PermissionService
public function canOnWorkstationGroup(User $user, string $permission, WorkstationGroup $group): bool
{
    // 1. Droit global ? -> acces a tout
    if ($user->can($permission)) {
        return true;
    }

    // 2. Delegation specifique sur ce WorkstationGroup ?
    $delegation = Delegation::where('user_id', $user->id)
        ->where('workstation_group_id', $group->id)
        ->whereHas('permission', fn($q) => $q->where('name', $permission))
        ->where('is_negative', false)
        ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
        ->exists();

    if (!$delegation) {
        return false;
    }

    // 3. Verifier qu'il n'y a pas de delegation negative
    $negated = Delegation::where('user_id', $user->id)
        ->where('workstation_group_id', $group->id)
        ->whereHas('permission', fn($q) => $q->where('name', $permission))
        ->where('is_negative', true)
        ->exists();

    return !$negated;
}
```

### 6.4 Heritage via groupes

L'heritage des delegations via les groupes AD est gere par le `PermissionService` :
- A la synchronisation, on resout les groupes AD de l'utilisateur
- Si un groupe a une delegation, elle est copiee pour l'utilisateur (avec flag `inherited`)
- Cela evite les requetes LDAP recursives a chaque verification

---

## 7. GPO et delegations : explication du cas d'usage

### 7.1 A quoi ca repond ?

Le fichier `delegations.inc.php` gere un cas tres specifique :

**Scenario :** Un professeur a besoin d'installer un logiciel sur les machines de sa salle. L'admin lui donne le droit `SE_COMPUTER_ELEVATE` (admin local) sur le parc "SalleInfo1".

**Probleme :** Ce droit doit etre applique **cote Windows**. Les machines Windows doivent savoir que ce professeur est administrateur local.

**Solution legacy :** Creer une **GPO** (Group Policy Object) dans l'AD qui :
1. Ajoute le SID de l'utilisateur au groupe `Administrateurs` (S-1-5-32-544) des machines
2. Est liee a l'OU du parc dans l'AD
3. Est appliquee automatiquement par Windows au prochain `gpupdate`

### 7.2 Ce qui change avec la refonte

La **logique metier** (qui a le droit sur quel parc) migre vers SQL + Spatie.
La **synchronisation GPO** reste necessaire pour `SE_COMPUTER_ELEVATE` uniquement.

```
Avant :  AD (delegation) ------------------------------------> GPO Windows
Apres :  SQL (delegation) -> GpoSyncService -> AD -> GPO Windows
```

Le `GpoSyncService` est un service Laravel qui :
- Ecoute les evenements de creation/suppression de delegations `computer.elevate`
- Cree/supprime les GPO correspondantes dans l'AD via les fonctions legacy
- Est declenche par un Job asynchrone (pas bloquant pour l'UI)

### 7.3 Quand la GPO est necessaire

| Permission | GPO necessaire ? | Raison |
|---|:-:|---|
| `computer.view` | Non | Verification cote web uniquement |
| `computer.control` | Non | Guacamole verifie cote web |
| `computer.elevate` | **Oui** | Windows doit ajouter l'user aux admins locaux |
| `computer.install` | Non | WPKG gere cote serveur |
| `wpkg.*` | Non | Verification cote web uniquement |

---

## 8. Modele de donnees

### 8.1 Tables Spatie (creees par la migration du package)

```
permissions          roles              model_has_permissions
+-- id               +-- id             +-- permission_id
+-- name             +-- name           +-- model_type
+-- guard_name       +-- guard_name     +-- model_id
+-- created_at       +-- created_at
+-- updated_at       +-- updated_at
                                        model_has_roles
role_has_permissions                    +-- role_id
+-- permission_id                       +-- model_type
+-- role_id                             +-- model_id
```

### 8.2 Table `users`

**Remarque transition :** Cette table est concue des le depart comme future source de verite. Les colonnes prefixees `ad_` sont des colonnes de **synchronisation/compatibilite** qui deviendront obsoletes quand PostgreSQL sera la source de verite. Ne jamais construire de logique metier sur ces colonnes — utiliser uniquement les permissions Spatie.

```sql
CREATE TABLE users (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login               VARCHAR(255) NOT NULL UNIQUE,    -- samAccountName AD
    password            VARCHAR(255) NULL,               -- hash bcrypt (NULL = auth via AD)
    fullname            VARCHAR(255),
    firstname           VARCHAR(255),
    lastname            VARCHAR(255),
    email               VARCHAR(255),
    dn                  TEXT NULL,                        -- DN dans l'AD (sync proxy)
    role                VARCHAR(50) DEFAULT 'autre',     -- eleve, prof, admin, autre
    is_active           BOOLEAN DEFAULT TRUE,
    -- Colonnes de sync AD (transitoires, a supprimer quand SQL = source de verite)
    ad_groups           JSON NULL,                       -- groupes AD (memberOf)
    ad_right_profiles   JSON NULL,                       -- profils de droits AD
    ad_rights_bitmask   INT UNSIGNED DEFAULT 0,          -- bitmask legacy calcule
    ad_synced_at        TIMESTAMP NULL,                  -- derniere sync depuis AD
    remember_token      VARCHAR(100) NULL,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);
```

### 8.3 Table `user_groups`

**Remarque :** On utilise `user_groups` (et non `groups`) pour distinguer clairement les groupes d'utilisateurs des `workstation_groups` (groupes de machines). Meme si les groupes sont encore dans l'AD aujourd'hui, cette table anticipe la transition vers PostgreSQL comme source de verite.

```sql
CREATE TABLE user_groups (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL UNIQUE,    -- nom du groupe
    display_name    VARCHAR(255),
    type            VARCHAR(50) NOT NULL,            -- class, team, admin, custom
    ad_dn           TEXT NULL,                       -- DN dans l'AD (sync proxy)
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);

CREATE TABLE user_group_user (
    user_group_id   BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_group_id, user_id),
    FOREIGN KEY (user_group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 8.4 Table `workstation_groups` (existante)

**Cette table existe deja** dans le projet Laravel. Elle n'est pas a creer, mais les delegations y font reference via FK.

```
workstation_groups (existante)
+-- id
+-- name                    -- identifiant unique (slug)
+-- is_physical             -- true = salle physique (OU dans Computers), false = groupe logique
+-- display_name
+-- description
+-- app_profile_name        -- nom du AppProfile a creer
+-- parent_id               -- hierarchie GPO (groupes physiques)
+-- ad_dn                   -- DN dans l'AD
+-- ad_guid                 -- objectGUID dans l'AD
+-- is_active
+-- locked
+-- managed_by_control_hub
```

Les delegations s'appliquent aux **WorkstationGroups physiques** (`is_physical=true`).

### 8.5 Table `delegations`

Reference directe vers `workstation_groups` existante. Voir section 6.2 pour le SQL complet.

### 8.6 Table `delegation_audit_logs` (optionnel)
**Remarque: On fera plus tard un mécanisme de logs global qui enregistrera l'historique des actions sur l'ensemble du domaine et qui prendra les délégations en compte. Pour le moment on ne l'implémente pas.**

```sql
CREATE TABLE delegation_audit_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    action          ENUM('grant', 'revoke') NOT NULL,
    permission_name VARCHAR(255) NOT NULL,
    workstation_group_id BIGINT UNSIGNED NULL,   -- FK vers workstation_groups
    performed_by    BIGINT UNSIGNED NOT NULL,
    metadata        JSON NULL,
    created_at      TIMESTAMP
);
```

---

## 9. Plan d'implementation

### Phase 1 : Fondations (priorite haute)

**1.1 Installer spatie/laravel-permission**
```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

**1.2 Creer le modele `User` Eloquent**
- Nouveau modele `App\Models\User` avec trait `HasRoles`
- Migration pour la table `users`
- Implemente `Authenticatable`
- Conserve la compatibilite avec `AuthUser` (periode de transition)

**1.3 Seeder des permissions et roles**
- Creer `PermissionSeeder` avec les 16 permissions mappees depuis les constantes SE_*
- Creer `RoleSeeder` avec les roles correspondant aux raccourcis composites
- Associer les permissions aux roles

**1.4 Creer le `PermissionService`**
- Methode `syncFromAd(string $login): User` : synchronise un utilisateur AD -> SQL + Spatie
- Methode `syncToAd(User $user): void` : synchronise SQL -> AD (anticipe la transition source de verite)
- Methode `bitmaskToPermissions(int $bitmask): array` : convertit bitmask -> noms de permissions
- Methode `permissionsToBitmask(User $user): int` : convertit permissions -> bitmask (compatibilite)

**Regle de conception :** Toute logique metier (Policies, Blade, middleware) doit utiliser **uniquement** les permissions Spatie (`$user->can(...)`) et jamais les colonnes `ad_*` ni le bitmask. Cela garantit que le passage SQL = source de verite ne casse rien.

### Phase 2 : Delegations (priorite haute)

**2.1 Migration `delegations`**
- Creer la table `delegations` (voir section 6.2)
- Creer le modele `App\Models\Delegation`

**2.2 Enrichir le `PermissionService`**
- `grantDelegation(User $user, string $permission, WorkstationGroup $group, ?User $grantedBy)`
- `revokeDelegation(User $user, string $permission, WorkstationGroup $group)`
- `canOnWorkstationGroup(User $user, string $permission, WorkstationGroup $group): bool`
- `getUserDelegations(User $user): Collection`
- `getWorkstationGroupDelegations(WorkstationGroup $group): Collection`

**2.3 Creer le `GpoSyncService`**
- Ecoute les evenements `DelegationGranted` / `DelegationRevoked`
- Pour `computer.elevate` uniquement : appelle les fonctions legacy GPO
- Job asynchrone `SyncGpoJob`
- **Conception unidirectionnelle SQL -> AD** : ce service ecrit dans l'AD, jamais l'inverse. Il restera identique apres la transition source de verite.

### Phase 3 : Integration Policies + UI (priorite moyenne)

**3.1 Adapter les Policies existantes**
- `UserPolicy` : utiliser `$user->can('user.read')` au lieu de `hasUserAdminRights()`
- `GroupPolicy` : idem
- `ShortcutPolicy` : idem
- Nouvelles policies : `WorkstationGroupPolicy`, `WorkstationPolicy`, `ApplicationPolicy`

**3.2 Creer les pages de gestion (Livewire SFC)**
- `resources/views/pages/admin/rights/index.blade.php` : gestion des droits d'un utilisateur
- `resources/views/pages/admin/delegations/index.blade.php` : gestion des delegations
- `resources/views/pages/admin/roles/index.blade.php` : gestion des roles

**3.3 Middleware**
- Ajouter le middleware Spatie aux routes : `role:super-admin`, `permission:user.read`
- Conserver `sambaedu.auth` pour la compatibilite session

### Phase 4 : Synchronisation et migration (priorite moyenne)

**4.1 Commande de synchronisation**
- `php artisan sambaedu:sync-rights` : synchronise tous les utilisateurs AD -> SQL
- Planifiable dans le scheduler Laravel
- Mode incremental (ne sync que les changements)

**4.2 Ecriture bidirectionnelle**
- Quand on modifie un droit dans Laravel, on l'ecrit aussi dans l'AD via `syncToAd()`
- Via `add_right_profile()` / `remove_right_profile()` legacy
- Permet la cohabitation pendant la transition
- **Anticipe la transition :** quand SQL devient source de verite, `syncFromAd()` est desactive et `syncToAd()` devient le seul sens de sync (SQL -> AD proxy)

**4.3 Migration des delegations existantes**
- Script `php artisan sambaedu:migrate-delegations` : lit les delegations AD -> table `delegations`

### Phase 5 : Nettoyage (priorite basse)

**5.1 Supprimer les dependances legacy**
- Remplacer `AuthUser` par `User` Eloquent partout
- Supprimer `RightsService` (ou le garder comme helper de compatibilite)
- Supprimer les appels directs a `have_right()` / `list_rights()`

**5.2 Supprimer le trait `RegistersGates`**
- Les Gates sont geres nativement par Spatie via les Policies

---

## 10. Migration des donnees legacy

### 10.1 Synchronisation AD -> SQL

```php
// PermissionService::syncFromAd()
public function syncFromAd(string $login): User
{
    // 1. Chercher ou creer l'utilisateur SQL
    $user = User::firstOrCreate(['login' => $login]);

    // 2. Charger les donnees AD
    $ldapUser = LdapUser::findByLogin($login);
    $user->update([
        'fullname' => $ldapUser->fullname,
        'dn' => $ldapUser->dn,
        'ad_groups' => $ldapUser->groups,
        'ad_right_profiles' => $ldapUser->rightProfiles,
        'last_synced_at' => now(),
    ]);

    // 3. Calculer le bitmask legacy
    $bitmask = $this->rightsService->calculateRights($ldapUser->rightProfiles, $login);
    $user->ad_rights_bitmask = $bitmask;
    $user->save();

    // 4. Convertir en permissions Spatie
    $permissions = $this->bitmaskToPermissions($bitmask);
    $user->syncPermissions($permissions);

    // 5. Assigner le role approprie
    $this->assignRoleFromBitmask($user, $bitmask);

    return $user;
}
```

### 10.2 Migration des delegations

```php
// Commande artisan sambaedu:migrate-delegations
public function handle(): void
{
    $config = get_config();
    $allDelegations = list_delegations($config, '*');

    foreach ($allDelegations as $delegation) {
        $user = User::where('login', $delegation['user'])->first();
        if (!$user) continue;

        $permission = $this->profileToPermission($delegation['profile']);
        if (!$permission) continue;

        // Trouver le WorkstationGroup physique correspondant au parc legacy
        $parcName = ldap_dn2cn($delegation['parcdn']);
        $wkGroup = WorkstationGroup::where('name', $parcName)
            ->where('is_physical', true)
            ->first();
        if (!$wkGroup) continue;

        Delegation::updateOrCreate([
            'user_id' => $user->id,
            'workstation_group_id' => $wkGroup->id,
            'permission_id' => $permission->id,
            'is_negative' => $delegation['negate'],
        ], [
            'granted_by' => null, // migration
        ]);
    }
}
```

---

## 11. Anticipation de la transition PostgreSQL = source de verite

Le plan est concu pour que la transition AD -> PostgreSQL soit **progressive et non-cassante** :

### 11.1 Principes de conception appliques des maintenant

| Principe | Implication |
|---|---|
| **Logique metier = Spatie uniquement** | Policies, middleware, Blade utilisent `$user->can()`, jamais `ad_rights_bitmask` |
| **Colonnes `ad_*` = sync transitoire** | Jamais utilisees dans le code applicatif, uniquement par le `PermissionService` |
| **`GpoSyncService` unidirectionnel** | Ecrit SQL -> AD, jamais l'inverse. Reste identique apres transition |
| **Table `user_groups` en SQL** | Groupes d'utilisateurs distincts des `workstation_groups` (machines) |
| **Champ `password` nullable** | NULL = auth AD, rempli = auth locale. Permet la bascule progressive |
| **FK `workstation_group_id`** | Reference directe vers la table `workstation_groups` existante |

### 11.2 Etapes de la transition (future)

1. **Phase A** (actuelle) : AD = source de verite, SQL = cache
   - `syncFromAd()` actif, `syncToAd()` actif (bidirectionnel)
   - Auth via AD (`password` = NULL)

2. **Phase B** : Double-ecriture, SQL prend le relais
   - Toute modification passe par Laravel (SQL d'abord, puis sync vers AD)
   - `syncFromAd()` desactive progressivement
   - Auth migre vers bcrypt local (`password` rempli)

3. **Phase C** : SQL = source de verite, AD = proxy Windows
   - `syncFromAd()` supprime
   - `syncToAd()` seul sens de sync (pour GPO, Kerberos, login Windows)
   - Colonnes `ad_*` supprimees
   - AD ne sert plus qu'a : login Windows, GPO, Kerberos
   - `workstation_groups` et `user_groups` sont les sources de verite pour les groupes

---

## Resume des reponses aux questions

### "Est-ce que ce systeme pourrait me permettre de gerer la delegation de droits telle qu'elle existe dans le legacy ?"

**Oui, mais pas avec Spatie seul.** Spatie gere les permissions globales (roles + permissions). Les delegations scopees par WorkstationGroup necessitent une **table custom `delegations`** qui etend Spatie. Le `PermissionService` orchestre les deux :
- Permission globale -> Spatie natif
- Permission scopee par WorkstationGroup -> table `delegations` (FK vers `workstation_groups`) + logique custom

### "La delegation de droits concerne aussi les GPOs, a quel cas ca repond ?"

Les GPO ne concernent qu'un seul cas : **`SE_COMPUTER_ELEVATE` (admin local temporaire)**. Quand un utilisateur recoit ce droit sur un parc, Windows doit le savoir pour l'ajouter au groupe Administrateurs locaux des machines. Le `GpoSyncService` gere cette synchronisation AD/GPO de maniere transparente. Toutes les autres permissions sont verifiees cote web uniquement.

### "Je pensais utiliser spatie/laravel-permission"

C'est le bon choix. Spatie fournit la fondation (permissions nommees, roles, middleware, Blade directives). La table `delegations` custom comble le manque de scoping par ressource. L'ensemble est plus maintenable, extensible et auditable que le systeme bitmask legacy.
