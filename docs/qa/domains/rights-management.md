# QA Manuel — Rights Management

**Domaine** : droits applicatifs, rôles Spatie, permissions, délégations périmétrées, scoping classe, profils dynamiques.

**Stories couvertes** : 7.1 (délégations périmétrées) + 7.2 (calcul & application, profils dynamiques, scoping classe). _Story 7.3 (migration bitmask → Spatie) à ajouter en section 5 quand livrée._

**Code de référence** :
- `app/Services/PermissionService.php` — grant/revoke/negate, canOnWorkstationGroup, importCustomProfilesFromAd
- `app/Policies/UserPolicy.php` — scoping classe Prof/EleveAdmin + résolution AuthUser→User
- `app/Policies/{Delegation,Machine,Printer,Share,Dhcp,WorkstationGroup,Group,Shortcut,AppCustomization}Policy.php`
- `app/Enums/{SambaPermission,SambaRole,LegacyRight}.php`
- `database/seeders/PermissionSeeder.php`
- `resources/views/pages/rights-management/**` — 5 onglets (Overview / User Lookup / Délégations / Historique / Profils)
- `resources/views/pages/sync-from-ad/**` — étape 8 rapatriement profils LDAP

---

## Pré-requis communs

- VM SER accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- `scripts/update.sh` exécuté (migrations + `db:seed --class=PermissionSeeder --force` + `permission:cache-reset` appliqués automatiquement via `sambaedu:app:update`)
- Au moins 2 `WorkstationGroup` physiques distincts (salle-A et salle-B) avec 2+ machines chacune
- Utilisateurs test :
  - `admin` — rôle `super-admin`
  - `enseignant.test` — rôle `prof`, rattaché classe 3A
  - `eleveAdmin1` — rôle `eleve-admin`, rattaché classe 3A
  - `eleveA` — rôle `eleve`, dans classe 3A
  - `eleveB` — rôle `eleve`, dans classe 3B
- **Important production** : tous les tests scoping classe (section 3) **doivent** être joués avec une authentification LDAP réelle (`AuthUser` en prod), pas avec des users Eloquent créés à la main — c'est le cas critique qui révèle les incidents type `#M1`.

---

## Section 1 — Délégations périmétrées (Story 7.1)

### Scénario 1.1 — Grant simple

1. Se connecter en `admin`, aller sur `/app/users`.
2. Cocher `enseignant.test`, cliquer "Gérer les droits".
3. Dans le drawer → onglet **Délégations**.
4. Sélectionner salle-A, cocher `computer.view` + `computer.control`, cliquer "Accorder 2 délégation(s)".

**Attendu** :
- Toast vert "2 délégation(s) sur 'salle-A' accordée(s) à 1 utilisateur(s)."
- Le drawer **ne se ferme pas** (comportement voulu pour enchaîner les grants).
- `/rights-management` → onglet "Délégations actives" : 2 lignes badge vert "Accordée".
- Onglet "Historique" : 2 entrées `grant` récentes (acteur = admin).

### Scénario 1.2 — Grant multi-users en batch

1. Sur `/app/users`, sélectionner 3 utilisateurs (`enseignant.test` + 2 autres).
2. Drawer → onglet Délégations → salle-A + permission `wpkg.assign` → Accorder.

**Attendu** :
- Toast "1 délégation(s) sur 'salle-A' accordée(s) à 3 utilisateur(s)."
- 3 nouvelles lignes dans "Délégations actives".
- Historique : 3 entrées `grant` distinctes (`target_user_id` différent).

### Scénario 1.3 — Grant négative (exclusion)

1. Préalablement : donner `computer.view` global à `enseignant.test` (via rôle ou seed).
2. Drawer pour `enseignant.test` → onglet Délégations.
3. **Cocher le toggle "Marquer comme exclusion (négative)"** — le toggle "Retirer les délégations" se décoche automatiquement (exclusion mutuelle).
4. Sélectionner salle-B, `computer.view`, "Exclure 1 délégation(s)".

**Attendu** :
- Toast vert "1 délégation(s) sur 'salle-B' marquée(s) en exclusion sur 1 utilisateur(s)."
- `/rights-management` → "Délégations actives" : ligne avec badge rouge "Exclusion" sur salle-B.
- Historique : entrée `negate` avec `is_negative=true`.
- Test fonctionnel : se connecter en `enseignant.test` → `/parc` affiche toutes les salles **sauf** salle-B (ou salle-B non-cliquable). `/parc/groups/{idB}` → redirection `/parc` + toast "Vous n'avez pas accès à cette ressource."

### Scénario 1.4 — Révocation unitaire depuis `/rights-management`

1. `/rights-management` → onglet "Délégations actives" → ligne `enseignant.test` / salle-A / `computer.view` → clic corbeille.
2. Confirmer le `wire:confirm`.

**Attendu** :
- Toast vert "Délégation computer.view révoquée sur salle-A".
- Tableau rafraîchi sans rechargement complet (Livewire).
- Ligne disparue.
- Historique : entrée `revoke`.

### Scénario 1.5 — User délégué voit son périmètre uniquement

1. Se connecter en `enseignant.test` (après grant salle-A propre, sans exclusion active).
2. Aller sur `/parc`.

**Attendu** :
- Liste des groupes = **uniquement salle-A**.
- Liste des machines = uniquement celles de salle-A.
- Aucune référence à salle-B (ni filtres, ni listings).

### Scénario 1.6 — Blocage accès direct URL hors périmètre

1. Toujours en `enseignant.test`.
2. Taper `/parc/groups/{idB}` dans la barre d'URL.

**Attendu** :
- Redirection silencieuse vers `/parc` (pas de 403 explicite — AC3 story 7.1).
- Toast rouge "Vous n'avez pas accès à cette ressource." au chargement de `/parc`.
- Log serveur : `[GroupShow] Accès refusé hors périmètre` avec user, group_id, URL.

### Scénario 1.7 — Filtres onglet Historique

1. Reconnexion `admin` → `/rights-management` → onglet "Historique".
2. Vérifier que les entrées des scénarios 1.1 à 1.4 sont visibles.
3. Filtrer par action `revoke` → ne reste que les lignes revoke.
4. Filtrer par user cible `enseignant.test` → ne reste que ses entrées.
5. Ajouter une plage de dates → filtre appliqué.
6. Bouton "Effacer" → tous les filtres réinitialisés, pagination à page 1.

**Attendu** :
- Filtres combinables.
- Pagination respecte les filtres (badge count en bas).

---

## Section 2 — Profils dynamiques & Seed (Story 7.2 — décisions d/e/f + #M3)

### Scénario 2.1 — CRUD profil custom

1. Se connecter en `admin`.
2. `/app/rights-management` → onglet **Profils** (5ᵉ onglet).
3. **Créer** "Animateur CDI" avec `computer.view` + `computer.control` + `user.read` → badge `custom` apparaît, 3 permissions affichées.
4. **Dupliquer** → `Animateur CDI_copy` créé, éditable.
5. **Renommer** "Animateur CDI" en "Animateur BCD" → OK.
6. **Assigner** le rôle à un user test via le drawer (onglet Droits d'un utilisateur).
7. **Supprimer** sur "Animateur BCD" → garde-fou : toast erreur "Retirer d'abord ce rôle des 1 utilisateur(s)."
8. Retirer le rôle du user → re-cliquer Supprimer → succès, toast vert.

### Scénario 2.2 — Garde-fou rôles seedés (correction #M3)

1. Onglet Profils → sélectionner **`super-admin`** (badge `seeded`).
2. Vérifier visuellement :
   - **Checkboxes permissions** toutes désactivées (`disabled`).
   - **Bouton Enregistrer** désactivé.
   - **Bannière info** "Rôle seedé — permissions gérées par le système, relancer PermissionSeeder pour modifier les défauts".
   - **Bouton Supprimer** désactivé (rôles seedés non-supprimables).
   - **Bouton Dupliquer** actif → crée un rôle custom éditable.
3. Tenter un contournement : ouvrir DevTools, retirer l'attribut `disabled` d'une checkbox, soumettre → **`abort(403)` serveur** via garde-fou `saveProfile`.

**Rationale** : évite qu'un admin vide accidentellement les permissions de `super-admin` et se retire son propre accès (auto-DoS identifié en review opus).

### Scénario 2.3 — Seed idempotent & non-destructif

```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 'cd /var/www/sambaedu-reload && php artisan db:seed --class=PermissionSeeder --force'
```

1. Première fois : log "X permissions créées, 9 rôles seedés, N profils custom préservés".
2. Modifier via UI les permissions du rôle `computer-admin` (si pas gardé par #M3 : modifier un profil **custom** à la place — un rôle seedé est verrouillé).
3. Relancer le seed → sortie "0 nouveau rôle, profils custom préservés".
4. Vérifier : le profil custom modifié n'a **pas été écrasé**.

### Scénario 2.4 — Rapatriement LDAP non-destructif (décision f + correction #2)

1. `/admin/sync-from-ad` → étape 8 "Rapatrier les profils LDAP custom".
2. Si un profil LDAP custom (ex: "Animateur CDI" avec `info=0x302`) existe côté AD → doit être rapatrié côté SER avec les 3 permissions correspondant au bitmask (`fromBitmask(0x302)`).
3. Modifier les permissions du profil côté SER (onglet Profils).
4. Relancer la sync → stats `custom_unchanged++`, les permissions SER ne sont **pas écrasées**.
5. **Edge case #2** : profil custom AD avec bitmask `0x900` (= `ComputerView + ComputerInstall` uniquement, sans le composite complet `ComputerAdmin`) → doit recevoir uniquement `computer.view` + `computer.install`, **pas** `app.customize` (la permission composite est filtrée hors `fromBitmask` si le bitmask source ≠ composite complet).

---

## Section 3 — Scoping classe (Story 7.2 — décisions a/b/c + corrections #M1 + #6 + #8)

> **⚠️ Joué en prod uniquement avec auth LDAP réelle** — tous ces scénarios dépendent de la résolution `AuthUser::getEloquentUser()` (#M1) qui n'est déclenchée qu'en prod.

### Scénario 3.1 — Prof scopé classe (vue + reset MDP)

1. Setup : 2 classes (3A, 3B) + `profA` rôle `prof` dans 3A, `eleveA` dans 3A, `eleveB` dans 3B.
2. Se connecter en `profA` via **login LDAP réel** (pas test).
3. `/app/users` → liste contient **uniquement** les users partageant au moins une classe avec `profA` (= `eleveA`, pas `eleveB`).
4. Fiche `/app/users/eleveA` → **200 OK**.
5. Fiche `/app/users/eleveB` → **403**.
6. Bouton "Réinitialiser MDP" sur `eleveA` → visible et fonctionnel.
7. Bouton "Réinitialiser MDP" sur `eleveB` → masqué ; forcé via URL → 403.

**Rationale** : reproduit `sovajon_is_admin` du legacy via `$user->userGroups()->where('type', 'class')`. Si `profA` voit toute la liste → la résolution `AuthUser → User` ne fonctionne pas (cf. incident #M1).

### Scénario 3.2 — EleveAdmin scopé classe (correction #6)

1. Créer `eleveAdmin1` avec rôle `eleve-admin`, rattaché à classe 3A.
2. Se connecter en `eleveAdmin1` via login LDAP réel.
3. `/app/users/eleveA` → **200 OK** (même classe).
4. `/app/users/eleveB` → **403** (classe 3B, pas partagée).
5. Bouton "Réinitialiser MDP" : visible sur `eleveA`, masqué sur `eleveB`.

**Rationale** : iso-legacy `sovajon_is_admin` (bit 0x07). Avant correction, `eleve-admin` faisait partie de `GLOBAL_USER_ROLES` et bypassait le scoping.

### Scénario 3.3 — Bulk reset filtré (correction #8)

1. Se connecter en `profA` (ou `eleveAdmin1`).
2. Déclencher un bulk reset MDP via `/users?bulk=reset` (si UI accessible à ce rôle).
3. Sélectionner `eleveA` + `eleveB` dans le formulaire → lancer.
4. Vérifier : **seul `eleveA` est reset**. `eleveB` est filtré côté serveur par `Gate::forUser($actor)->check('resetPassword', $target)` dans `UserService::bulkResetPasswords`.

**Rationale** : defense-in-depth. Avant correction, un Prof pouvait contourner le scoping unitaire via le flow bulk.

### Scénario 3.4 — Test AuthUser réel (correction #M1)

**⚠️ Scénario critique** : valide que le scoping classe s'applique bien avec `AuthUser` (auth LDAP prod), pas seulement avec `User` Eloquent (tests unitaires).

1. Purger le cache : `php artisan permission:cache-reset`.
2. Se déconnecter complètement, fermer le navigateur.
3. Se reconnecter **via le formulaire de login LDAP** en `profA`.
4. Vérifier qu'`auth()->user()` est bien un `AuthUser` côté serveur (logs debug si besoin).
5. Répéter scénario 3.1 → tous les attendus doivent rester vrais.

**Rationale** : l'incident #M1 (manqué par review sonnet, rattrapé par opus) faisait que la Policy rejetait `AuthUser` et le scoping était inopérant en prod. Les tests unitaires passaient car ils instancient `User` directement. **Si `profA` voit tout le monde → #M1 pas opérationnel → RGPD cassé.**

### Scénario 3.5 — AuthUser orphelin → fail-closed

1. Créer un `AuthUser` LDAP sans contrepartie `User` Eloquent en base (ex : user créé côté AD après un sync cassé).
2. Se connecter en ce user.
3. Accéder à une route protégée par une Policy scopée (`/app/users/eleveA`).

**Attendu** : **403** systématique (fail-closed). `UserPolicy::resolveEloquentActor()` renvoie `null` → accès refusé.

---

## Section 4 — Middleware, Policies, Cache (Story 7.2 — AC11/AC12/AC6 + corrections #1 + #5)

### Scénario 4.1 — Accès direct URL hors périmètre = 403

1. Se connecter en élève (`eleveA`, rôle `eleve` uniquement, sans droits admin).
2. Taper dans la barre d'URL :
   - `/app/users/new` → **403**
   - `/admin/sync-from-ad` → **403**
   - `/app/parc/groups/new` → **403**
   - `/app/rights-management` → **403**
3. Les logs Laravel contiennent une entrée warning pour chaque refus.

### Scénario 4.2 — Routes parc groups accessibles (correction #1)

1. Se connecter en `admin` (ou user avec `computer.install`).
2. `/app/parc/groups/new` → **page accessible, formulaire s'affiche**.
3. `/app/parc/groups/{id}/edit` → accessible, édition possible.
4. Se connecter en user sans `computer.install` → **403**.

**Rationale** : avant correction, ces 2 routes utilisaient `can:computer.modify` (permission fantôme inexistante dans l'enum) → `abort(403)` systématique pour tous, SuperAdmin compris. Correction : `can:computer.install`.

### Scénario 4.3 — Revocation effective sans logout (AC12 — cache invalidation)

1. Session A : `admin` dans onglet Profils.
2. Session B : `alice` (rôle `prof`) sur `/app/users`, liste chargée.
3. Session A : drawer → retirer le rôle `prof` à `alice`, ou modifier les permissions du rôle `prof` via onglet Profils.
4. Session B : refresh `/app/users` → **nouvelle permission effective** (403 si plus de droit, ou liste filtrée si permission ajustée).
5. **Pas de logout** requis côté alice.

**Rationale** : `app(PermissionRegistrar::class)->forgetCachedPermissions()` est appelé par `saveProfile` / `duplicateProfile` / `deleteProfile` dans le Livewire rights-management. Le cache Spatie (TTL 24h) est donc invalidé à chaque mutation.

### Scénario 4.4 — Performance listing (AC9 — pas de N+1)

1. Activer Laravel Debugbar sur la VM.
2. Charger `/app/users` avec ≥ 50 users en base.
3. Vérifier :
   - Nombre total de queries **stable** (ne scale pas linéairement avec le nombre de users).
   - Pas de requête `select * from role_has_permissions where role_id = ...` répétée 50 fois.
4. Si N+1 détecté sur `@can(...)` ligne par ligne → précharger les rôles dans le composant Livewire (`->with('roles.permissions')`).

---

## Section 5 — Migration bitmask → Spatie + Refactor calculateRights() (Story 7.3, 2026-04-25)

### 5.1 Dry-run de la commande de migration

**Préconditions** :
- Branche LDAP `rights_rdn` peuplée (groupes `<profile>` avec `info`).
- Tables Spatie déjà seedées (run `db:seed --class=PermissionSeeder`).

**Étapes** :
1. SSH sur la VM : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
2. `cd /var/www/sambaedu-reload && php artisan sambaedu:migrate-rights-to-spatie --dry-run`

**Attendu** :
- Mode `DRY-RUN (aucune écriture)` affiché en en-tête.
- Tableau de synthèse : `Users scannés / Rôles attribués / Délégations créées (positives, négatives) / Fallbacks buggés ignorés / Cas non mappables / Warnings`.
- **Aucune ligne ajoutée** dans `model_has_roles` ni `delegations` (vérifier en DB).
- Exit code = 0.

### 5.2 Run effectif + idempotence

**Préconditions** : 5.1 OK.

**Étapes** :
1. `php artisan sambaedu:migrate-rights-to-spatie` (run réel).
2. Vérifier le rapport stdout + le fichier persisté `storage/logs/migrate-rights-to-spatie-<timestamp>.log`.
3. Re-jouer immédiatement : `php artisan sambaedu:migrate-rights-to-spatie`.
4. Comparer : aucun nouvel enregistrement n'a été ajouté (idempotence).

**Attendu** :
- 1er run : `roles_assigned > 0`, `delegations_created` selon présence en `delegations_rdn`.
- 2e run : mêmes compteurs, aucun doublon dans `model_has_roles` ni `delegations`.
- Exit code = 0 dans les deux cas.

### 5.3 Bug `Annu_is_admin` sans `info` → fallback ignoré

**Préconditions** : un groupe LDAP `Annu_is_admin` existe avec `info` absent / null / 0 (cas reproductible en peuplement test).

**Étapes** :
1. Créer dans LDAP un groupe `Annu_is_admin` sans attribut `info` ou `info=0`, avec un user membre.
2. `php artisan sambaedu:migrate-rights-to-spatie`.
3. Inspecter `storage/logs/laravel.log` ou `storage/logs/migrate-rights-to-spatie-*.log`.

**Attendu** :
- Warning loggé : `[MigrateRightsToSpatie] Annu_is_admin sans info — fallback buggé ignoré, assignation alignée sur le seed d'origine SE_USER_ADMIN`.
- Le user reçoit `SambaRole::UserAdmin` (`user-admin` en DB), **PAS** `SambaRole::ComputerAdmin`.
- Compteur `Fallbacks buggés ignorés` ≥ 1 dans le rapport stdout.

### 5.4 Délégations scopées `<level>_<parc>` migrées

> **Mise à jour post-review batch corrections (2026-04-25)** : le format CN
> legacy réel est `(no_)?(manage|view|rdp)_<parc>` (cf. `sambaedu/includes/ldap.inc.php:4396-4426`),
> pas `<spatie-perm-name>_<parc>`. Mappings appliqués par la migration :
> `manage→computer.elevate`, `view→computer.view`, `rdp→computer.remote.rdp` (nouvelle perm Story 7.3).

**Préconditions** :
- Branche `delegations_rdn` (`ou=delegations`) contient au moins :
  - Un groupe `cn=manage_<parc-existant>` avec un user DN dans `member`.
  - Un groupe `cn=no_manage_<parc-existant>` avec un user DN dans `member`.
  - (Optionnel pour valider RDP) Un groupe `cn=rdp_<parc-existant>`.
- Les `WorkstationGroup` correspondant à `<parc-existant>` sont en DB SER (`workstation_groups.name`).

**Étapes** :
1. `php artisan sambaedu:migrate-rights-to-spatie`.
2. Vérifier `delegations` :
   - `SELECT * FROM delegations WHERE user_id = <user> AND workstation_group_id = <wg> AND is_negative = false;`
   - `SELECT * FROM delegations WHERE user_id = <user> AND workstation_group_id = <wg> AND is_negative = true;`

**Attendu** :
- Les lignes positive (issue de `manage_<parc>`) et négative (issue de `no_manage_<parc>`)
  existent. `permission_id` pointe sur `computer.elevate` (les deux pointent sur la
  même permission, le flag `is_negative` distingue le sens).
- Si `rdp_<parc>` était présent : `permission_id` pointe sur `computer.remote.rdp`
  (PAS sur `computer.control` — vérification post-review #10).
- Un parc nommé avec underscores (ex. `manage_salle_info_bat_A`) doit être
  correctement migré vers le `WorkstationGroup` `salle_info_bat_A` (vérifié
  post-review #2).
- L'historique `delegation_history` contient les actions correspondantes
  (`grant` + `negate`) avec `actor_user_id = NULL` mais `context.source =
  'migration-7.3'` et `context.message = 'Migration legacy 7.3 - aucun
  acteur humain'` (post-review #8).

### 5.4.bis Re-run préserve `granted_by` d'une délégation manuelle (post-review #11)

**Préconditions** :
- Migration 5.4 OK.
- Un admin `henri` pose **manuellement** via UI `/rights-management` une
  délégation `bob` × `computer.elevate` × `salle-X` après le 1er run de
  migration. La ligne en DB a `granted_by = <henri.id>`.

**Étapes** :
1. Re-jouer la commande de migration : `php artisan sambaedu:migrate-rights-to-spatie`.
2. Vérifier en DB : `SELECT granted_by FROM delegations WHERE user_id = <bob.id> AND workstation_group_id = <salle-X.id>;`

**Attendu** :
- `granted_by` reste pointé sur `<henri.id>` — la migration n'écrase PAS
  l'acteur humain (passage de `updateOrCreate` → `firstOrCreate`).
- Aucune nouvelle entrée `delegation_history` n'est créée (la ligne existait
  déjà, la migration est idempotente sur cet aspect).

### 5.4.ter `password_is_admin` migré vers permission directe (post-review #1, anti-escalade)

**Préconditions** :
- Branche `rights_rdn` contient un groupe `password_is_admin` avec au moins
  un user membre.
- Tables Spatie seedées (`db:seed --class=PermissionSeeder`).

**Étapes** :
1. `php artisan sambaedu:migrate-rights-to-spatie`.
2. Vérifier en DB pour le user concerné :
   ```sql
   SELECT name FROM permissions p
   JOIN model_has_permissions m ON m.permission_id = p.id
   WHERE m.model_id = <user.id> AND m.model_type = 'App\\Models\\User';
   ```
3. Vérifier qu'aucun rôle Spatie n'a été attribué :
   ```sql
   SELECT name FROM roles r
   JOIN model_has_roles m ON m.role_id = r.id
   WHERE m.model_id = <user.id> AND m.model_type = 'App\\Models\\User';
   ```

**Attendu** :
- L'user a `user.password.init` en **permission directe** (model_has_permissions).
- L'user n'a **AUCUN** rôle Spatie (ni `user-admin`, ni autre — c'est l'objet
  de la correction #1 anti-escalade : un user qui n'avait que `SE_USER_PASSWORD_INIT`
  (0x01) ne doit pas se retrouver avec les 8 droits de `UserAdmin` (0xFF)).
- L'user **n'a pas** `user.assign.right` ni `user.delegate` (vérifiable via
  `php artisan tinker` → `$u->can('user.assign.right')` retourne `false`).

### 5.5 `RightsService::calculateRights()` Spatie-only avec LDAP down

**Préconditions** : un user a au moins un rôle Spatie assigné en DB.

**Étapes** :
1. Sur la VM, simuler une coupure LDAP (par exemple : modifier `LDAP_HOST` dans `.env` vers une IP injoignable, ou couper le réseau LDAP).
2. Tester `php artisan tinker` :
   ```php
   $u = App\Models\User::where('login', '<login_test>')->first();
   $r = app(App\Services\RightsService::class);
   $r->calculateRightsForUser($u); // doit retourner un int bitmask cohérent
   ```
3. Vérifier `storage/logs/laravel.log` : aucune trace `LdapRightGroup::getAllRightsValues` ou `RightRepository::getAllRightsValues`.

**Attendu** :
- Le bitmask retourné est cohérent avec les rôles Spatie de l'user.
- `SE_COMPUTER_VIEW` (0x100) est toujours absent du bitmask retourné.
- Aucune erreur LDAP en logs runtime (la fonction ne consulte plus le LDAP).

### 5.6 Drawer `rights-drawer` Spatie

**Préconditions** : un user existe en DB SER avec rôles assignés.

**Étapes** :
1. Connexion en admin avec permission `manage-rights`.
2. Aller sur `/app/users/<login>` → cliquer "Gérer les permissions".
3. Inspecter le contenu du drawer.

**Attendu** :
- Liste des rôles disponibles (seedés + custom) avec toggle.
- Pour chaque rôle : badge `seed` ou `custom`, label FR, code interne en code, badges des permissions associées avec labels FR (ex. `Voir les utilisateurs`).
- Section "Permissions directes" si l'user a des permissions individuelles.
- **Aucun bitmask hex `0x...`** dans le rendu HTML.
- Toggle d'un rôle + Enregistrer → assignation Spatie modifiée + reload page.

### 5.7 Round-trip identité bitmask LDAP-source vs Spatie-source

**Préconditions** : 5.2 OK (migration effectuée).

**Étapes** :
1. Pour chaque profil seedé représentatif (`se3_is_admin`, `computer_is_admin`, `Annu_is_admin`, `password_is_admin`, `RefNum`) avec un user de test rattaché :
   - Capturer le bitmask attendu depuis l'enum (`SambaRole::<Role>->permissions()` → `SambaPermission::toBitmask()`).
   - Lancer `RightsService::calculateRightsForUser($user)`.
2. Comparer.

**Attendu** :
- Tous les bits attendus (hors `SE_COMPUTER_VIEW` et `SE_SERVER_ADMIN` hors `SuperAdmin`) sont présents dans le bitmask Spatie.
- Le rôle `SuperAdmin` (`se3_is_admin`) couvre 100 % des bits avec filtre `SE_COMPUTER_VIEW`.

---

## Section 6 — Peuplement des groupes AD `Equipe_X` par rôle (Story 4.12, 2026-06-24)

> **Contexte.** Sur une install greenfield SE5, profs ET élèves d'une classe atterrissaient tous dans `Classe_X` ; `Equipe_X` (et `PP_X`) restaient vides. Les ACLs `group:equipe_<x>:rwx` posées par `ShareService` ne mordaient donc sur aucun dossier → le prof n'avait aucun droit. La 4.12 partitionne les membres par rôle au moment de la sync AD : profs → `Equipe_X`, reste → `Classe_X`. `PP_X` reste volontairement non peuplé (limite connue D1). Aucune modification de `ShareService`/ACLs.

> **Pré-requis spécifiques.** AD réel (VM). Au moins 1 user `role=prof` et 2 users `role=eleve` avec un `dn` AD valide. Vérifier les appartenances AD réelles avec `samba-tool group listmembers <CN>` et `getent group <cn>`. Migrations VM non auto-jouées → `php artisan migrate:status` avant. Chown `www-admin` si un fichier de config est touché.

### Scénario 6.1 — Création d'une classe : partition prof/élève

1. UI `/app/users/groups/new` : créer un groupe `type=classe`, nom `3A`, et y assigner 1 prof + 2 élèves.
2. Vérifier l'appartenance AD réelle :
   - `samba-tool group listmembers Equipe_3A` → **uniquement** le prof.
   - `samba-tool group listmembers Classe_3A` → **uniquement** les 2 élèves.
   - `samba-tool group listmembers PP_3A` → **vide** (limite connue D1, attendu).
3. Read-back SQL : `/app/users/groups/[id]` du groupe primaire reste cohérent (pas de ligne dupliquée, l'UI mono-nom s'affiche normalement). Le membre d'`Equipe_3A` est classé `type=equipe` au prochain `syncFromAd`.

### Scénario 6.2 — getfacl : le prof est effectivement `rwx`

1. Après 6.1, lancer le partage de classe (`ShareService::createClassShare` via l'UI de la classe) puis l'assignation.
2. `getfacl /var/sambaedu/Classes/Classe_3A/_travail` → ligne `group:equipe_3a:rwx` **effective** (groupe non vide, le prof l'applique).
3. `getfacl` sur un dossier élève de la classe → idem `group:equipe_3a:rwx` effectif.
4. Se connecter en tant que le prof sur un poste : il peut lire/écrire dans `_travail` et dans les dossiers élèves de la classe.

### Scénario 6.3 — Retrait d'un prof (idempotence fail-soft)

1. Dans l'edit-form du groupe, retirer le prof de la sélection des membres, enregistrer.
2. `samba-tool group listmembers Equipe_3A` → le prof n'y est **plus**. Les élèves restent dans `Classe_3A`.
3. Ré-enregistrer sans changement (re-sync) → aucun doublon, aucune erreur (le retrait ne porte que sur les DN connus SQL ; DN absent = no-op silencieux).

### Scénario 6.4 — Bascule de rôle prof ↔ élève

1. Un membre de la classe, initialement `role=prof` (donc dans `Equipe_3A`), voit son `role` passer à `eleve` (changement AD/SQL).
2. Re-synchroniser le groupe (edit-form enregistré, ou `syncFromAd`).
3. `samba-tool group listmembers Equipe_3A` → le membre n'y est **plus** ; `samba-tool group listmembers Classe_3A` → il y **est**. Jamais présent dans les deux.
4. Cas inverse `eleve` → `prof` : le membre est déplacé `Classe_3A` → `Equipe_3A` au sync suivant.

### Scénario 6.5 — Types non-classe inchangés (cible unique)

1. Créer un groupe `type=cours` (ex. `Maths5A`) avec un prof, OU un groupe `matiere_classe` (CN préfixé `Matiere_Math@3emeA`).
2. Vérifier qu'**aucune** ré-expansion `Equipe_`/`Classe_` n'a lieu : le membre est écrit dans la **seule** cible résolue (`Cours_Maths5A`, resp. `Matiere_Math@3emeA`). `Equipe_*`/`Classe_*` correspondants ne sont pas touchés.

> **Couverture automatisée.** `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (hôte, sqlite) couvre 6.1 (partition), 6.3 (retrait + idempotence), 6.4 (bascule), 6.5 (cours + bypass `matiere_classe`). 6.2 (getfacl prof effectif) et l'appartenance AD réelle = validation manuelle /vm uniquement.

---

## Section 7 — Fold import AD→SQL au nom nu (Story 4.13, 2026-06-24)

> **Contexte.** L'import AD→SQL (`UserGroupService::syncFromAd`) créait auparavant **3 lignes** `user_groups` par classe (`Classe_3A`, `Equipe_3A`, `PP_3A`). La 4.13 replie (« fold ») ces variantes en **UNE** ligne au **nom nu** (`3A`, `type=classe`), avec l'**union** des membres des 3 CN. Le `ad_guid`/`ad_dn` canonique est celui de `Classe_` (fallback déterministe `Equipe_` puis `PP_`). C'est le pendant **lecture** de l'écriture nom-nu livrée en 4.12. AD reste la source ; SQL le cache (import = migration transitoire). La couche aval (`ShareService::resolveClassPath`, `GroupRepository`) re-préfixe par convention et tolère déjà le nom nu → **aucun changement aval**. `LegacyParcBridgeService` ne lit pas `user_groups` (confirmé : porte sur `WorkstationGroup`/`parc`) → no-op.

> **Limite connue (D5).** Les lignes SQL héritées `Classe_X`/`Equipe_X`/`PP_X` **déjà présentes** sur une base existante ne sont PAS fusionnées par 4.13 (pas de migration de données). Le fold s'applique sur greenfield ou après un `syncFromAd` complet. La fusion de l'existant + la colonne `is_head_teacher` sont le scope de **4.14**.

> **Pré-requis spécifiques.** AD réel (VM). Une classe avec ses 3 CN (`Classe_X`/`Equipe_X`/`PP_X`) réellement créés (cf. `GroupRepository::createGroup` expanse les 3). Migrations VM non auto-jouées → `migrate:status` avant. Vérifier les appartenances AD réelles avec `samba-tool group listmembers <CN>`.

### Scénario 7.1 — Une classe importée donne UNE ligne nue

1. Sur AD, s'assurer que `Classe_3A`, `Equipe_3A`, `PP_3A` existent (création d'une classe via l'UI les expanse).
2. Lancer un import : `php artisan` (commande de sync groupes) **ou** créer la classe via `/app/users/groups/new`.
3. SQL : `SELECT name, type FROM user_groups WHERE LOWER(name) IN ('classe_3a','equipe_3a','pp_3a','3a');`
   - Attendu : **une seule** ligne `name='3A'`, `type='classe'`. **Aucune** ligne `Classe_3A`/`Equipe_3A`/`PP_3A`.
4. L'UI `/app/users/groups` liste **une** entrée pour la classe (plus 3).

### Scénario 7.2 — Union des membres des 3 CN

1. Sur AD : `Classe_3A = {alice}`, `Equipe_3A = {bob}`, `PP_3A = {bob}` (bob en double volontaire).
2. Lancer l'import.
3. Membres de la ligne nue `3A` (UI ou `SELECT u.login FROM user_group_user ... WHERE name='3A'`) → **`{alice, bob}`** (union dédupliquée). Aucun membre perdu, aucun doublon.

### Scénario 7.3 — `ad_guid` canonique `Classe_` + fallback

1. Import d'une classe complète → `SELECT ad_guid, ad_dn FROM user_groups WHERE name='3A';` doit porter le **GUID/DN de `Classe_3A`**.
2. Sur AD, supprimer `Classe_3A` en gardant `Equipe_3A`/`PP_3A`, ré-importer → la ligne `3A` porte désormais le **GUID/DN d'`Equipe_3A`** (fallback déterministe ; `PP_` seulement si `Equipe_` absent aussi).
3. Aucun conflit `ad_guid` n'est levé pendant l'import (les 3 CN partagent la même ligne, un seul GUID écrit).

### Scénario 7.4 — Idempotence du re-sync

1. Lancer `syncFromAd` deux fois de suite sur le même lot AD.
2. Attendu : la ligne `3A` **survit** (la passe `deleted` compare aux noms NUS persistés), aucun doublon créé, membres stables (0 attach / 0 detach au 2e run).

### Scénario 7.5 — `Equipe_` orphelin (classe vs cours)

1. Sur AD : un `Cours_Maths5A` + son `Equipe_Maths5A` co-créé, **sans** `Classe_Maths5A`/`PP_Maths5A`.
2. Import → SQL doit contenir : `Cours_Maths5A` (`type=cours`, **CN conservé**, non foldé) **et** une ligne nue `Maths5A` (`type=equipe`) pour l'équipe orpheline. L'équipe du cours **ne fold pas** avec le cours.
3. Cas inverse (sécurité) : si `Classe_Maths5A`/`PP_Maths5A` existaient, alors `Equipe_Maths5A` folderait avec eux en `Maths5A` type classe.

> **Couverture automatisée.** `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (hôte, sqlite) couvre 7.1 (`it_folds_classe_variants_into_one_bare_name_group`), 7.2 (`it_unions_members_across_folded_variants`), 7.3 (`it_uses_canonical_classe_guid_for_folded_group` + `it_falls_back_to_equipe_guid_when_classe_absent`), 7.4 (`it_is_idempotent_across_repeated_imports`), 7.5 (`it_keeps_orphan_equipe_as_its_own_bare_group`). L'import AD réel (`samba-tool`, appartenances effectives) = validation manuelle /vm uniquement, différée post-merge.

### Scénario 7.6 — Non-régression scope prof post-fold (RGPD) — **CRITIQUE, manuel**

> **Contexte.** Le fold (4.13) supprime la distinction SQL `Equipe_X`/`Classe_X` : après import, **prof ET élève sont co-membres de la même ligne nue `X` (`type=classe`)** ; la distinction de rôle vient de `User.role`. Le scope « un prof ne voit/reset QUE ses élèves » repose désormais sur ce partage de classe nue. Les tests unitaires de la review d'origine fabriquaient des fixtures pré-fold (`Equipe_X` + `Classe_X` séparés) qui **masquaient** la régression : en condition réelle post-fold, l'ancienne policy renvoyait un ensemble vide → soit déni total (prof ne voit personne), soit (si recâblé sans scope) accès global non scopé. Ce scénario se vérifie en manuel sur données réellement importées.

**Prérequis** : avoir lancé un `syncFromAd` réel (/vm) qui a foldé au moins 2 classes (`3A`, `3B`) avec un prof rattaché à `3A` uniquement, et des élèves dans `3A` et dans `3B`.

1. Se connecter à `/app/users` en tant que **prof rattaché à `3A` seulement** (rôle `prof`, sans rôle admin global).
2. **Listing** : le prof ne voit QUE les membres de `3A` (lui-même + ses élèves de `3A`). Il ne voit **AUCUN** élève de `3B`. Vérifier qu'il ne voit pas **tous** les utilisateurs (= pas de bascule en accès non-scopé) ni **zéro** utilisateur (= pas de déni total / `whereRaw('1=0')`).
3. **Reset mot de passe** : le bouton « Réinitialiser » est actif sur un élève de `3A` (action réussit) et **refusé** sur un élève de `3B` (403 / action masquée).
4. **Bulk reset** : sélectionner des élèves de `3A` ET `3B`, lancer le bulk → seuls les élèves de `3A` sont effectivement réinitialisés (filtrage Gate `resetPassword`).
5. **EleveAdmin** : répéter 2–4 avec un acteur `eleve-admin` rattaché à `3A` → même scope strict que le prof.
6. **Admin global** (`user-admin`/`super-admin`/`referent-numerique`) : voit et reset tous les élèves de `3A` et `3B` (bypass scope confirmé).

> **Couverture automatisée (modèle post-fold).** `tests/Feature/Policies/UserPolicyResetPasswordScopedTest.php` et `tests/Feature/Livewire/UsersListingScopedTest.php` ont été RÉÉCRITS pour le modèle nom-nu (prof+élève co-membres d'une ligne nue `type=classe`) : ils échouent désormais si le scope régresse (vérifié en injectant la logique vestige). La résolution est factorisée dans `User::classGroupNames()` / `User::sharesClassGroupWith()`, partagée par la policy ET le listing blade. Ce scénario manuel reste requis car il valide le scope sur des appartenances **réellement importées** depuis AD (et non des fixtures).

---

## Post-correctifs & non-régressions

### Post-correctifs Story 7.2 (review 2026-04-23)

| Incident | Couvert par |
|----------|-------------|
| #M1 — AuthUser rejeté par Policy en prod (scoping inopérant, RGPD) | Scénarios 3.1 à 3.5 (tous avec auth LDAP réelle) |
| #1 — `computer.modify` permission fantôme (routes parc groups inaccessibles) | Scénario 4.2 |
| #M3 — Admin peut vider permissions SuperAdmin (auto-DoS) | Scénario 2.2 |
| #6 — EleveAdmin bypass scoping classe | Scénario 3.2 |
| #8 — Bulk reset bypass scoping | Scénario 3.3 |
| #2 — `fromBitmask` sur-élargit les profils custom narrow | Scénario 2.4 edge case |
| #7 — Cache non-invalidé via saveProfile Livewire | Scénario 4.3 |

### Non-régressions à vérifier

- **Drawer "Gérer les droits"** fonctionne sur les onglets Rôles et Permissions (avant Story 7.2).
- **`/parc` admin** : voit toujours toutes les salles et toutes les machines.
- **Actions power** (wake / shutdown) depuis `/parc/groups/{id}` fonctionnent pour un admin (non-régression Epic 4).
- **Actions wallpaper et schedules** accessibles via la gate `manage-workstationGroup` (non-régression 7.1).
- **`@can` dans les ~30 Blade existants** : aucune page ne doit régresser (spot-check : `/app/shortcuts`, `/app/parc-settings/wallpapers`, `/app/parc-settings/app-customizations`, `/app/users/groups/[id]`).

### Follow-ups connus (hors périmètre actuel)

- Page 403 dédiée : uniquement si un chemin non-Livewire (ex: controller API) est exposé hors périmètre. Aujourd'hui tous les accès sensibles passent par Livewire avec toast + redirect silencieux.
- Migration one-shot bitmask → Spatie en prod : **Story 7.3**.
- Observer de projection Spatie → bitmask AD : **Story 7.3**.
- Suppression du mapping bitmask (`LegacyRight`, `SambaPermission::legacyRight()`, etc.) : **post-7.3 sunset**.

---

## Checklist rapide pré-prod

**Section 1 — Délégations (7 scénarios)**
- [ ] 1.1 Grant simple
- [ ] 1.2 Grant multi-users batch
- [ ] 1.3 Grant négative (exclusion)
- [ ] 1.4 Révocation unitaire
- [ ] 1.5 User délégué voit son périmètre uniquement
- [ ] 1.6 Blocage accès direct URL hors périmètre
- [ ] 1.7 Filtres onglet Historique

**Section 2 — Profils & Seed (4 scénarios)**
- [ ] 2.1 CRUD profil custom
- [ ] 2.2 Garde-fou rôles seedés (UI + serveur)
- [ ] 2.3 Seed idempotent & non-destructif
- [ ] 2.4 Rapatriement LDAP non-destructif + edge case bitmask

**Section 3 — Scoping classe (5 scénarios, auth LDAP réelle)**
- [ ] 3.1 Prof scopé classe (vue + reset MDP)
- [ ] 3.2 EleveAdmin scopé classe
- [ ] 3.3 Bulk reset filtré
- [ ] 3.4 AuthUser LDAP réel (incident #M1 validé)
- [ ] 3.5 AuthUser orphelin → fail-closed

**Section 4 — Middleware / Cache (4 scénarios)**
- [ ] 4.1 Accès direct URL = 403
- [ ] 4.2 Routes `/parc/groups/new` + `/edit` accessibles (fix #1)
- [ ] 4.3 Revocation effective sans logout
- [ ] 4.4 Performance listing (pas de N+1)

**Section 5 — Migration bitmask → Spatie (Story 7.3, 9 scénarios)**
- [ ] 5.1 Dry-run de la commande `sambaedu:migrate-rights-to-spatie`
- [ ] 5.2 Run effectif + idempotence (re-run = même rapport)
- [ ] 5.3 Bug `Annu_is_admin` sans `info` → fallback ignoré (UserAdmin pas ComputerAdmin)
- [ ] 5.4 Délégations scopées `<level>_<parc>` migrées (manage/view/rdp avec mapping correct, parc à underscores OK)
- [ ] 5.4.bis Re-run préserve `granted_by` d'une délégation manuelle (`firstOrCreate`)
- [ ] 5.4.ter `password_is_admin` → permission directe `user.password.init`, **pas** rôle UserAdmin (anti-escalade #1)
- [ ] 5.5 `RightsService::calculateRights()` fonctionne avec LDAP down
- [ ] 5.6 Drawer `rights-drawer` affiche rôles + permissions Spatie (zéro hex)
- [ ] 5.7 Round-trip identité bitmask LDAP-source vs Spatie-source

**Section 6 — Peuplement `Equipe_X` par rôle (Story 4.12, 5 scénarios)**
- [ ] 6.1 Création classe : prof → `Equipe_X`, élèves → `Classe_X`, `PP_X` vide
- [ ] 6.2 `getfacl` : `group:equipe_<x>:rwx` effectif (prof rwx réel)
- [ ] 6.3 Retrait d'un prof → enlevé d'`Equipe_X`, idempotent fail-soft
- [ ] 6.4 Bascule prof↔élève → déplacé entre `Equipe_X` et `Classe_X` (jamais les deux)
- [ ] 6.5 Types non-classe (cours / `matiere_classe`) → cible unique, pas de ré-expansion

**Section 7 — Fold import nom nu (Story 4.13, 5 scénarios)**
- [ ] 7.1 Une classe importée → **1** ligne `user_groups` nom nu `3A` type classe (0 ligne `Classe_/Equipe_/PP_`)
- [ ] 7.2 Membres de `3A` = union dédupliquée des 3 CN
- [ ] 7.3 `ad_guid`/`ad_dn` = `Classe_` (fallback `Equipe_` puis `PP_`), aucun conflit GUID
- [ ] 7.4 Double `syncFromAd` idempotent (ligne survit, 0 doublon, membres stables)
- [ ] 7.5 `Equipe_` orphelin (cours sans `Classe_`/`PP_`) → ligne nue type equipe, ne fold pas avec `Cours_`
- [ ] 7.6 **Non-régression scope prof post-fold (CRITIQUE, manuel)** : prof rattaché à `3A` voit/reset uniquement ses élèves de `3A` (ni tous, ni zéro) ; bulk filtré ; eleve-admin idem ; admin global bypass

**Non-régressions**
- [ ] Drawer Rôles + Permissions
- [ ] `/parc` admin visibilité totale
- [ ] Actions power / wallpaper / schedules
- [ ] ~30 `@can` Blade spot-check
