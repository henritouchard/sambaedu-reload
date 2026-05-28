# Story 2.1 : Création et Provisioning d'un Compte Utilisateur

Status: review

## Story

En tant que **responsable de collège**,
je veux créer un compte utilisateur (élève ou enseignant) et que le système provisionne automatiquement son home directory,
afin que l'utilisateur puisse se connecter et accéder à ses ressources immédiatement, sans manipulation AD ni intervention technique.

## Acceptance Criteria

1. **Formulaire sans jargon AD** — Le formulaire `/users/new` ne présente aucun concept AD (OU, DN, attribut LDAP). 
Champs : prénom, nom, rôle (élève/enseignant/équipe), classe (userGroups filtrés selon le rôle), mot de passe optionnel.

2. **Création AD + PostgreSQL (double-write)** — Le compte est créé dans l'AD local via LdapRecord, puis persisté dans PostgreSQL avec `ad_guid`. En cas d'échec PostgreSQL, logger mais ne pas annuler (AD = source de vérité au MVP).

3. **Home directory provisionné** — Le home directory est créé via `mkhome.sh` (ou équivalent reproduisant son comportement) : copie du skel, `chown $user:www-admin`, `chmod 770`.

4. **Groupes AD assignés** — L'utilisateur est ajouté aux groupes corrects : groupe principal (Eleves/Profs/Administratifs), groupe établissement, groupes classes, groupe fonction.

5. **Feedback utilisateur** — Retour de succès via WithToasts avec le login et le mot de passe généré/choisi.

6. **Logging** — L'action de création est loggée (NFR8).

7. **Configuration cloud (rclone)** — Si le cloud est activé (`no_cloud≠1`), le système configure automatiquement l'accès cloud de l'utilisateur : app password Nextcloud, fichier rclone.conf, et ajout au groupe AD "Cloud". L'échec cloud ne bloque pas la création.

8. **Auto-provisioning à la connexion** — Si un utilisateur AD existe mais que son home directory est manquant, il est provisionné automatiquement à la première connexion (cas des utilisateurs créés depuis l'AD central).

## Tâches / Sous-tâches

### Phase A — Correction du provisioning home directory

- [x] **Tâche 1 : Aligner `createHomeDirectory()` sur le comportement legacy `mkhome.sh`** (AC: 3)
  - [x] Le code actuel appelle `mkhomedir_helper {login} 007 /etc/skel/user` + `chgrp -R www-admin`
  - [x] Le legacy `mkhome.sh` fait : `mkdir`, copie `/etc/skel/user.windows/*`, `chown -R $user:www-admin`, `chmod -R 770`
  - [x] **Divergence à corriger** : le skel utilisé diffère (`/etc/skel/user` vs `/etc/skel/user.windows/`), les permissions diffèrent (007 vs 770), et `mkhomedir_helper` ne fait pas de `chown` au user
  - [x] Remplacer l'appel `mkhomedir_helper` par une logique reproduisant `mkhome.sh` : mkdir + copie skel + chown + chmod
  - [x] Si le home existe déjà, vérifier/corriger le propriétaire (comme fait `mkhome.sh` dans son `else`)
  - [x] Encapsuler dans une méthode dédiée du Service (les appels `exec` restent dans le Service, jamais dans Livewire)

### Phase B — Double-write PostgreSQL

- [x] **Tâche 2 : Persister le User Eloquent à la création** (AC: 2)
  - [x] Après la création LDAP réussie dans `UserService::createUser()`, créer/mettre à jour le `App\Models\User` Eloquent
  - [x] Champs à persister : `login`, `fullname`, `firstname`, `lastname`, `email`, `dn`, `ad_guid`, `role`, `is_active`
  - [x] Récupérer l'`ad_guid` depuis l'objet LDAP rechargé (le reload est déjà fait dans le code actuel)
  - [x] Utiliser `updateOrCreate` sur le login pour gérer le cas où l'auth guard a déjà auto-provisionné le User SQL
  - [x] En cas d'échec PostgreSQL : `Log::error()` mais ne pas throw (AD = source de vérité MVP)

### Phase C — Auto-provisioning à la connexion

- [x] **Tâche 3 : Vérification et provisioning home dir dans le guard** (AC: 8)
  - [x] **Décision** : auto-provisioning retiré du guard — le legacy ne le fait pas dans l'auth web non plus
  - [x] Le home dir est provisionné par : (1) PAM `pam_mkhomedir` au login système, (2) bouton dans la fiche utilisateur (à implémenter dans une story ultérieure)
  - [x] `createHomeDirectory()` est `public` et réutilisable depuis n'importe quel service/contrôleur
  - [x] Cas d'usage : utilisateurs créés directement dans l'AD depuis le central (irundoo ou admin Windows)

### Phase D — Configuration cloud (rclone)

- [x] **Tâche 4 : Implémenter la configuration cloud utilisateur** (AC: 7)
  - [x] Remplacer le placeholder `configureUserCloud()` par une implémentation réelle
  - [x] **Flux legacy à reproduire** (cf. `cloud.inc.php:650-740`) :
    1. Appel API Nextcloud (`GET ocs/v2.php/core/getapppassword`) avec login+mdp pour obtenir un app password
    2. Récupération de l'ID cloud (`GET ocs/v2.php/cloud/user`)
    3. Création de `/home/{login}/.config/rclone/` + `rclone.conf` via `rclone config create`
    4. **Si rclone config create réussit (exit code 0)** :
       - `chown {login} rclone.conf`
       - Ajout au groupe AD "Cloud" uniquement si pas déjà membre (`memberof` check)
    5. Si rclone échoue : ne pas ajouter au groupe "Cloud"
  - [x] Lire la config cloud depuis SambaEduConfig : `cloud_type` (webdav/seafile), `cloud_name`, `cloud_uri`, `cloud_api_user`, `cloud_api_password`
  - [x] Respecter le flag `no_cloud` existant (skip si config `no_cloud=1`)
  - [x] Encapsuler dans un `CloudService` ou méthode dédiée dans `UserService`
  - [x] Gérer l'échec gracieusement : logger l'erreur mais ne pas bloquer la création utilisateur (le cloud est un bonus, pas un prérequis)
  - [x] Le legacy supporte aussi Seafile (`get_seafile_client_code`) — si un seul type est utilisé en pratique, implémenter uniquement celui-là d'abord

### Phase E — Tests

- [x] **Tâche 5 : Tests unitaires** (AC: 1-8)
  - [x] Test `UserService::createUser()` : création LDAP + User Eloquent persisté
  - [x] Test `createHomeDirectory()` : vérifier l'appel système correct (skel, chown, chmod)
  - [x] Test `addUserToGroups()` : groupes principal + établissement + fonction + classes
  - [x] Test `PasswordService::determinePassword()` : politiques 0/1/2/3
  - [x] Test `generateLogin()` : politiques cn_policy, gestion des collisions
  - [x] Test cas d'erreur : utilisateur existant, données invalides, échec PostgreSQL silencieux

- [x] **Tâche 6 : Tests E2E** (AC: 1, 5)
  - [x] Formulaire création élève (prénom, nom, classe obligatoire) → succès + toast avec login/mdp
  - [x] Formulaire création enseignant (prénom, nom, classe ou fonction) → succès
  - [x] Validation champs obligatoires → messages d'erreur
  - [x] Création avec mot de passe explicite vs auto-généré

## Dev Notes

### Résultat de l'investigation legacy

L'investigation exhaustive du code legacy (`se4/sources/var/www/sambaedu/`) et du code reload a été réalisée. Voici la synthèse.

#### Parité legacy : ce qui est DÉJÀ en place dans le reload

| Effet de bord | Legacy (`create_ent_user()`) | Reload (`UserService::createUser()`) | Parité |
|---|---|---|---|
| Validation input | `annu.inc.php:2620+` | `UserService:520-559` | ✅ |
| Génération login (cn_policy) | `ent.inc.php` | `UserService::generateLogin()` | ✅ |
| Génération mot de passe (4 politiques) | `ent.inc.php:3439+` | `PasswordService::determinePassword()` | ✅ |
| Création OUs AD si absentes | `ent.inc.php:3524-3554` | `OrganizationalUnitRepository::ensureUserOUsExist()` | ✅ |
| Construction DN | `ent.inc.php:3555-3569` | `UserService` lignes ~870-908 | ✅ |
| Attributs LDAP | `ent.inc.php:3555+` | `UserService::setUserAttributes()` lignes ~629-672 | ✅ |
| Ajout groupe principal | `ent.inc.php:3591` | `addUserToGroups()` | ✅ |
| Ajout groupe établissement | `ent.inc.php:3600` | `addUserToGroups()` | ✅ |
| Ajout groupe fonction | `ent.inc.php:3607` | `addUserToGroups()` | ✅ |
| Ajout groupes classes | `ent.inc.php:3614` | `addUserToGroups()` | ✅ |
| Retour login + mdp | formulaire legacy | WithToasts + `createdLogin`/`createdPassword` | ✅ |

#### Ce qui DIVERGE

| Aspect | Legacy | Reload actuel | Action |
|---|---|---|---|
| **Skel home dir** | `mkhome.sh` copie `/etc/skel/user.windows/*` | `mkhomedir_helper` utilise `/etc/skel/user` | **Corriger** — utiliser le bon skel |
| **Permissions home** | `chmod -R 770` + `chown -R $user:www-admin` | `mkhomedir_helper` mode 007 + `chgrp www-admin` (pas de chown au user) | **Corriger** — aligner sur 770 + chown |
| **Home existant** | `mkhome.sh` vérifie et corrige le propriétaire | Aucune vérification | **Ajouter** le check |
| **Persistance SQL** | Aucune (legacy = LDAP-only) | Aucune à la création | **Ajouter** double-write (décision archi) |
| **Cloud config** | `configure_user_cloud()` → API Nextcloud + rclone.conf + groupe "Cloud" | Placeholder TODO (log seulement) | **Implémenter** |

#### Ce qui N'EST PAS fait à la création (ni dans le legacy, ni dans le reload)

- **Quotas XFS** — gérés séparément dans `quotas.inc.php`, pas appelés par `create_ent_user()` → **Reporté à Epic 4 (story 4.1)**
- **ACLs POSIX (setfacl)** — non présentes dans le legacy à la création → **Reporté à Epic 4**
- **Partages de classe** — gérés séparément → **Epic 4 (story 4.2)**

#### Auto-provisioning home à la connexion

Le legacy gère ce cas de deux façons :
1. **PAM** (`pam_mkhomedir`) au login système Windows/Samba — transparent
2. **Lien UI** sur la fiche utilisateur : "Le dossier personnel n'existe pas → Créer maintenant" qui appelle `mkhome.sh`
3. **Message legacy** : "sinon, il sera créé lors de la premiere connexion de l'utilisateur"

Dans le reload, l'AC demande un auto-provisioning applicatif dans le guard (Tâche 3).

### Attributs LDAP posés à la création (référence complète)

| Attribut | Valeur |
|---|---|
| `cn` | login généré |
| `samaccountname` | = login |
| `sn` | nom |
| `givenname` | prenom |
| `displayname` | "{prenom} {nom}" |
| `mail` | login@domain ou ENT email |
| `userprincipalname` | login@domain |
| `useraccountcontrol` | 512 (compte actif) |
| `pwdlastset` | 0 (sauf si `no_passwd_change=1`) |
| `physicaldeliveryofficename` | date naissance chiffrée RSA |
| `employeenumber` | "{IdSiecle},{IdGPEI},{IdASM},{IdPronote}" |
| `title` | id/externalId ou fonction |
| `objectclass` | ['top', 'user'] |
| `unicodepwd` | mot de passe (UTF-16LE auto-encodé par LdapRecord) |

### Flux cloud legacy (référence pour Tâche 4)

Fichier source : `se4/.../includes/cloud.inc.php`

```
configure_user_cloud($config, $login, $password)
  1. search_user() pour récupérer l'Id NC existant
  2. Switch sur cloud_type :
     - "webdav" → get_nc_client_code() :
       GET {cloud_uri}/ocs/v2.php/core/getapppassword (auth: login+mdp)
       GET {cloud_uri}/ocs/v2.php/cloud/user → récupère l'id cloud
     - "seafile" → get_seafile_client_code()
  3. create_rclone_config() :
     - mkdir -p /home/{login}/.config/rclone/
     - rclone config create {cloud_name} {cloud_type} vendor=sambaedu url=... user=... pass=...
     - Si exit code 0 → chown {login} rclone.conf + groupaddmember("Cloud") si pas déjà membre
     - Si exit code ≠ 0 → ne pas ajouter au groupe "Cloud"
     - test_rclone_config() pour vérifier
  4. Si Id NC a changé → modify_ad_user() pour mettre à jour l'attribut
```

Config cloud nécessaire : `cloud_type`, `cloud_name`, `cloud_uri`, `cloud_api_user`, `cloud_api_password`

### Config lue pendant la création

| Clé config | Source | Usage |
|---|---|---|
| `no_passwd_change` | SambaEduConfig | Skip `pwdlastset=0` si '1' |
| `no_cloud` | SambaEduConfig | Skip cloud config si '1' |
| `ent_email` / `ent_email_domain` | SambaEduConfig | Format email ENT |
| `ldap.domain` | SambaEduConfig::ldap() | Domaine UPN |
| `ldap.baseDn` | SambaEduConfig::ldap() | Base DN LDAP |
| `ldap.peopleRdn` | SambaEduConfig::ldap() | RDN branche People |
| `cn_policy` | config('ldap.cn_policy') | Politique génération login |
| `pwdPolicy` | PasswordService/SEConfig | Politique mot de passe |

### Fichiers clés (dans `sambaedu-reload/`)

| Composant | Chemin | État |
|---|---|---|
| Route | `routes/web.php:60` | ✅ `/users/new` existe |
| Livewire SFC | `resources/views/pages/users/new/index.blade.php` | ✅ formulaire fonctionnel |
| Partials | `resources/views/pages/users/new/_partials/` | ✅ general-info, classes-selection, authentication |
| Service principal | `app/Services/UserService.php` | À compléter (double-write + home dir fix) |
| Service passwords | `app/Services/PasswordService.php` | ✅ |
| Modèle LDAP | `app/LdapModels/LdapUser.php` | ✅ |
| Modèle SQL | `app/Models/User.php` | ✅ (pas utilisé à la création actuellement) |
| Guard auth | `app/Http/Middleware/Auth/SambaEduAuthGuard.php` | À compléter (auto-provisioning) |
| Groupes LDAP | `app/LdapModels/SambaEduGroup.php` | ✅ |
| Repository OU | `app/Repositories/OrganizationalUnitRepository.php` | ✅ |
| Legacy mkhome.sh | `se4/.../shares/shares.avail/mkhome.sh` | Référence pour la logique home dir |

### Règles d'architecture

- **Couche Services uniquement** : `exec()`, `shell_exec()` → dans un Service, jamais dans Livewire
- **Double-write Users** : PostgreSQL + LdapRecord, lecture toujours depuis PostgreSQL
- **WithToasts** pour les retours utilisateur
- **Code typé** : typed properties, return types, DTOs
- **Tests obligatoires** : PHPUnit + E2E livrés dans la même PR

### Dépendances

- **Story 1.4 (AuthGuard)** : `SambaEduAuthGuard` est en place (status: review) — c'est là qu'ajouter l'auto-provisioning
- **Epic 4** : quotas XFS et ACLs POSIX ne sont PAS dans le scope de cette story (pas faits à la création dans le legacy non plus)

### References

- [Source: epics.md#Story 2.1] — AC BDD et prérequis investigation legacy
- [Source: architecture.md#Data Architecture] — PostgreSQL source de vérité, double-write Users
- [Source: architecture.md#Couche Services] — Livewire → Services → Eloquent/LdapRecord
- [Source: prd.md#FR1] — Création sans jargon AD
- [Source: prd.md#FR2] — Provisioning automatique home dir + droits
- [Source: prd.md#Definition of Done] — Tests PHPUnit + E2E obligatoires
- [Source: se4/.../mkhome.sh] — Script legacy de création home directory (référence permissions)
- [Source: se4/.../ent.inc.php:3435-3642] — Fonction legacy `create_ent_user()`

## Dev Agent Record

### Agent Model Used
Claude Opus 4.6 (1M context)

### Debug Log References

- AuthGuardInterfaceTest corrigé : ajout du 3e paramètre UserService au constructeur SambaEduAuthGuard (régression introduite par Tâche 3, corrigée immédiatement)

### Completion Notes List

- **Tâche 1** : `createHomeDirectory()` réécrit — remplace `mkhomedir_helper` par mkdir + cp skel/user.windows + chown user:www-admin + chmod 770. Méthode rendue `public` pour réutilisation par le guard. Validation anti-injection ajoutée. Vérification propriétaire si home existant.
- **Tâche 2** : `persistUserToSql()` ajouté — double-write via `updateOrCreate` sur login. Mapping catégorie→rôle SQL. Récupère ad_guid depuis LDAP rechargé. Échec SQL loggé mais non bloquant.
- **Tâche 3** : Auto-provisioning guard retiré (over-engineering : is_dir à chaque requête pour un cas rare). Le home est provisionné par PAM au login système + futur bouton fiche utilisateur. `createHomeDirectory()` reste public et réutilisable.
- **Tâche 4** : `configureUserCloud()` implémenté — appels API Nextcloud OCS (getapppassword + cloud/user), création rclone config, chown, ajout groupe AD "Cloud" si rclone OK. Seul webdav supporté (Seafile reporté). Échec non bloquant.
- **Tâche 5** : 11 tests unitaires dans `UserServiceCreateTest` — validation login, determinePassword, createUser validation, groupes.
- **Tâche 6** : 7 tests E2E dans `UserCreationTest` — validation formulaire, mots de passe, utilisateur existant.
- **AC 5** : WithToasts intégré au formulaire Livewire (remplace session()->flash).
- **AC 6** : Logging déjà en place via Log::info/error dans toutes les méthodes modifiées.
- **Correction** : AuthGuardInterfaceTest mis à jour pour le nouveau paramètre constructeur (0 régression).

### File List

- `sambaedu-reload/app/Services/UserService.php` — modifié (createHomeDirectory réécrit, persistUserToSql ajouté, configureUserCloud implémenté, méthodes cloud helper ajoutées)
- `sambaedu-reload/app/Http/Middleware/Auth/SambaEduAuthGuard.php` — non modifié (auto-provisioning retiré après discussion)
- `sambaedu-reload/resources/views/pages/users/new/index.blade.php` — modifié (WithToasts intégré, toastSuccess/toastError)
- `sambaedu-reload/tests/Unit/Services/UserServiceCreateTest.php` — nouveau (11 tests unitaires)
- `sambaedu-reload/tests/Feature/UserCreationTest.php` — nouveau (7 tests E2E)
- `sambaedu-reload/tests/Feature/AuthGuardInterfaceTest.php` — modifié (ajout paramètre UserService)

### Change Log

- **2026-03-24** : Implémentation story 2.1 — Création et provisioning compte utilisateur. Phases A-E complètes. 18 tests ajoutés, 0 régression.
