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

## Section 8 — Migration data (fusion lignes héritées) + `is_head_teacher` (Story 4.14, 2026-06-25)

> **Contexte.** 4.13 a foldé le **flux d'import** (3 CN AD → 1 ligne nue) mais n'a PAS réécrit l'existant SQL déjà persisté (limite D5 de 4.13). 4.14 comble trois trous : (1) une **colonne d'arête** `is_head_teacher` (bool défaut false) sur le pivot `user_group_user` ; (2) une **migration de données** qui converge les bases héritées (3 lignes `Classe_X`/`Equipe_X`/`PP_X`) vers 1 ligne nue `X` — report des pivots AVANT suppression (zéro perte), survivante canonique (D1 : nue > `Classe_` > `Equipe_` > `PP_`), `ad_guid`/`ad_dn` du CN canonique, idempotente/rejouable, garde anti-collision `name` UNIQUE, `Equipe_` orphelin renommé sans fusion (D3) ; (3) l'**alimentation read-back** du flag à l'import : les membres issus du CN `PP_<base>` portent `is_head_teacher=true` via un `users()->sync()` ASSOCIATIF dans la boucle de fold 4.13. La logique de fusion est extraite dans `App\Actions\Groups\MergeLegacyUserGroups` (action invocable, pure SQL cross-driver) appelée par la migration `2026_06_25_120000_add_is_head_teacher_to_user_group_user`.

> **HORS SCOPE (→ 4.15).** L'écriture SQL→AD vers `PP_<X>` pilotée par le flag (`syncRoleAwareAdGroupMembers` NON touché) et l'UI « Professeur principal ». 4.14 pose la colonne + alimente le flag en LECTURE ; rien ne le consomme encore côté AD/UI.

> **Pré-requis spécifiques.** AD réel (VM). Une base avec lignes SQL héritées (3 lignes préfixées, typiquement une base importée AVANT 4.13). Migrations VM **non auto-jouées** → `migrate:status` puis `migrate` MANUEL (voir runbook 8.7).

### Scénario 8.1 — Colonne `is_head_teacher` ajoutée, défaut false rétro-rempli

1. Après `php artisan migrate`, la table `user_group_user` possède une colonne `is_head_teacher` boolean non nullable défaut `false`.
2. SQL : `SELECT is_head_teacher FROM user_group_user LIMIT 5;` → les arêtes préexistantes (le cas échéant) valent `false`.
3. La PK composite `(user_group_id, user_id)` est inchangée ; aucun timestamp ajouté (`\d user_group_user` en PG).

### Scénario 8.2 — Fusion 3 lignes héritées → 1 ligne nue (aucun membre perdu)

1. État de départ SQL : `Classe_3A` (membres {alice}), `Equipe_3A` (membres {bob}), `PP_3A` (membres {bob}).
2. Lancer `php artisan migrate` (la migration appelle la fusion).
3. `SELECT name, type FROM user_groups WHERE LOWER(name) IN ('classe_3a','equipe_3a','pp_3a','3a');` → **UNE** ligne `name='3A'`, `type='classe'` ; **aucune** ligne préfixée.
4. Membres de `3A` → **union {alice, bob}** dédupliquée. Aucun membre perdu.

### Scénario 8.3 — `ad_guid`/`ad_dn` canoniques après fusion

1. La ligne survivante `3A` porte l'`ad_guid`/`ad_dn` de `Classe_3A` (canonique D1).
2. Si `Classe_3A` est absente mais `Equipe_3A` présente, elle porte ceux d'`Equipe_3A` (fallback déterministe ; `PP_` seulement si `Equipe_` absent aussi).
3. Aucun conflit d'unicité `name` levé.

### Scénario 8.4 — Flag PP posé en migration data

1. Après fusion de 8.2 : l'arête `(3A, bob)` a `is_head_teacher=true` (bob ∈ `PP_3A`) ; `(3A, alice)` a `is_head_teacher=false`.
2. Multi-PP : si `PP_3A={bob, carol}`, les deux arêtes valent `true`.
   `SELECT u.login, ugu.is_head_teacher FROM user_group_user ugu JOIN users u ON u.id=ugu.user_id JOIN user_groups g ON g.id=ugu.user_group_id WHERE g.name='3A';`

### Scénario 8.5 — Idempotence (re-run = no-op)

1. Rejouer la migration data (ou la lancer sur une base déjà foldée par 4.13, sans lignes préfixées) : **aucune** ligne créée/supprimée, **aucun** flag modifié, **aucune** exception.
2. Vérifier le compte-rendu de l'action (`merged_bases=0`, `removed_rows=0` au 2e run) — ou simplement constater l'état SQL identique.

### Scénario 8.6 — Collision nom nu préexistant + `Equipe_` orphelin

1. **Collision** : ligne nue `3A` (type classe, {alice}) + reliquat `PP_3A` ({bob}). Après migration : UNE ligne `3A`, membres {alice, bob}, `(3A,bob)=true`, `(3A,alice)=false`. La ligne nue préexistante est la survivante (D1) ; pas de `UNIQUE constraint violation`.
2. **Orphelin (D3)** : `Cours_Maths5A` (type cours) + `Equipe_Maths5A` (type equipe, sans `Classe_`/`PP_` Maths5A, sans ligne nue Maths5A). Après migration : `Cours_Maths5A` inchangée (CN, type cours) ; `Equipe_Maths5A` renommée `Maths5A` (type equipe) **sans fusion** avec le cours. Aucun flag PP.

### Scénario 8.7 — Runbook migration VM (différé post-merge, MANUEL)

> **Les migrations VM ne sont PAS auto-jouées** (`memory/project_vm_migrations_not_auto_applied.md` : le dev-cycle ne migre que SQLite pour les tests ; la VM reste `Pending`). L'exécution réelle sur PG est un geste **explicite post-merge** sur `/vm`.

1. `php artisan migrate:status` → vérifier que `2026_06_25_120000_add_is_head_teacher_to_user_group_user` est `Pending`.
2. `php artisan migrate --no-interaction` → la colonne est créée puis la fusion s'exécute.
3. Vérifier en SQL (8.2/8.4) la fusion réelle sur les bases concernées et avec `samba-tool group listmembers <CN>` la cohérence AD↔SQL.
4. La migration étant **rejouable**, un second `migrate` (après rollback) ne reproduit pas d'effet de fusion (idempotence 8.5).

### Scénario 8.8 — Read-back du flag à l'import (AD réel)

1. Sur AD : `Classe_3A={alice}`, `Equipe_3A={bob}`, `PP_3A={bob}`. Lancer un `syncFromAd` (UI ou commande).
2. La ligne nue `3A` a pour membres {alice, bob} (invariant 4.13) **et** `(3A,bob).is_head_teacher=true`, `(3A,alice)=false`.
3. **Multi-PP** : `PP_3A={bob, carol}` → bob et carol à `true`.
4. **Retrait PP** : retirer bob de `PP_3A` (toujours dans `Classe_3A`), re-sync → bob reste membre, `(3A,bob)=false` (le flag suit l'état AD, pas de rémanence).
5. **CN non foldé** : un `Cours_Histoire4A={prof}` → la ligne existe (type cours), `(Cours_Histoire4A, prof)=false`. Le flag n'est jamais `true` hors classe/équipe foldée.
6. **Idempotence** : un 2e `syncFromAd` sans changement laisse membres et flags stables.

> **Couverture automatisée.** `tests/Feature/Migrations/MergeLegacyUserGroupsMigrationTest.php` (hôte, sqlite) couvre 8.2–8.6 (fusion, GUID canonique + fallback, flag PP + multi-PP, idempotence + base déjà foldée, collision nue, orphelin equipe + idempotence orphelin). `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` couvre 8.8 (read-back : `it_marks_head_teacher_from_pp_cn_on_import`, `it_marks_head_teacher_idempotently_across_repeated_imports`, `it_marks_multiple_head_teachers`, `it_clears_head_teacher_when_removed_from_pp`, `it_never_marks_head_teacher_on_non_class_cn`). L'exécution de la migration sur PG réel et l'import AD effectif (`samba-tool`) = validation manuelle /vm, différée post-merge (8.7).

> **Checklist rapide pré-prod (4.14)** : `migrate:status` (migration `Pending` confirmée) → `migrate` /vm → fusion vérifiée en SQL (8.2/8.4) → re-run = no-op (8.5) → un `syncFromAd` réel pose `is_head_teacher` depuis `PP_` (8.8). Rappel : le flag n'est encore consommé par aucune projection AD ni UI (→ 4.15).

---

## Section 9 — Écriture SQL→AD `PP_<X>` + UI Professeur principal (Story 4.15, 2026-06-25)

> **But.** Rendre EFFECTIF le flag `is_head_teacher` posé en lecture par 4.14. Deux volets : (1) écriture SQL→AD d'une **3ᵉ cible** `PP_<base>` dans `syncRoleAwareAdGroupMembers`, pilotée par les arêtes `is_head_teacher=true`, **orthogonale** à `Equipe_`/`Classe_` ; (2) UI Livewire SFC « Professeur principal » sur la fiche de groupe pour désigner/visualiser les PP. Pas de migration de schéma (colonne livrée 4.14).

> **Pré-requis communs.** AD réel /vm (DC se4ad) avec une classe foldée `3A` (CN `Classe_3A`, `Equipe_3A`, `PP_3A`). Un compte avec la permission `user.modify` (édition de groupe). La **migration data 4.14 doit avoir été jouée** (`migrate` /vm, §8.7) pour que les classes héritées portent déjà un flag cohérent — mais l'UI 4.15 pose le flag elle-même, donc une classe créée via SE5 ne dépend pas de la migration. `samba-tool group listmembers PP_3A` pour inspecter le groupe AD.

### Scénario 9.1 — 3ᵉ cible `PP_<base>` écrite, orthogonale (CRITIQUE)

1. Sur la fiche de `3A` (membres : prof1, prof2, élève), désigner **prof1** professeur principal (toggle) puis **Enregistrer**.
2. AD : `samba-tool group listmembers PP_3A` → contient **prof1** uniquement.
3. **Orthogonalité (clé)** : `Equipe_3A` contient toujours prof1 **et** prof2 (parité rwx prof 4.12 — `getfacl` `group:equipe_3a:rwx` inchangé) ; prof1 est donc dans `Equipe_3A` **ET** `PP_3A`. `Classe_3A` contient l'élève. Vérifier qu'aucun prof n'a été **retiré** d'`Equipe_3A` (régression R1 = haute).

### Scénario 9.2 — `PP_<base>` vidé quand plus de PP (pas de rémanence)

1. Sur `3A` avec prof1 PP, **décocher** prof1 puis Enregistrer.
2. `samba-tool group listmembers PP_3A` → **vide**. Aucune rémanence.
3. `Equipe_3A`/`Classe_3A` inchangés (prof1 reste membre prof).

### Scénario 9.3 — Plusieurs PP

1. Cocher prof1 **et** prof2, Enregistrer.
2. `PP_3A` contient prof1 et prof2.

### Scénario 9.4 — `PP_` jamais écrit hors classe/équipe

1. Sur un groupe `type='cours'`/`'matiere'`/`'custom'` : la fiche **n'affiche pas** la section « Professeur principal » (gating `type === 'classe'`).
2. Côté écriture, aucun `addMember("PP_…")` n'est jamais émis pour ces types (la branche `$isClasseLike` ne s'exécute pas).

### Scénario 9.5 — Intersection garde-fou (PP hors membres)

1. Cas défensif (forge / désync) : un `head_teacher_id` qui n'est pas membre du groupe est **ignoré** silencieusement à l'écriture AD (pas d'exception). Seuls les PP membres sont écrits dans `PP_3A`.

### Scénario 9.6 — Aller-retour AD↔SQL stable (D2, anti-clignotement)

1. Après l'enregistrement UI (qui écrit AD **puis** `syncFromAd`), l'arête `(3A,prof1).is_head_teacher=true` est persistée en SQL et **correspond** au CN `PP_3A`.
2. Relancer un `syncFromAd` (bouton « Synchroniser avec AD ») : ni les membres ni le flag ne changent (l'AD `PP_3A` ayant été écrit AVANT le read-back, le flag ne « clignote » pas).

### Scénario 9.7 — UI : section gated classe + abort anti-forge

1. La fiche d'un groupe `type='classe'` **affiche** la section « Professeur principal » ; un groupe `type='cours'` ne l'affiche pas.
2. Un payload Livewire forgé avec un `groupId` non-classe (ou inexistant) est **rejeté en `mount`** (`abort 404`), comme `class-share-section` — pas seulement par le `@if` de la vue.

### Scénario 9.8 — UI : toggle limité aux profs (D5)

1. La case « professeur principal » n'est proposée **que** pour les membres `isProf()`. Un élève membre n'a aucun contrôle PP (l'écriture service reste robuste si forcé, cf. 9.5).

### Scénario 9.9 — UI : double guard d'autorisation

1. Un utilisateur sans `user.modify` (ex. `user.read` seul) voit la section en **lecture seule** (badges PP, pas de toggle ni bouton).
2. Une action `save` forcée sans `user.modify` est **rejetée serveur** (`AuthorizationException` / 403) — double guard (`@can` UI + `Gate::authorize('update-group')`).

> **Couverture automatisée.** `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` couvre l'écriture AD : `it_writes_head_teachers_to_pp_group` (9.1, orthogonalité), `it_clears_pp_group_when_no_head_teacher` (9.2), `it_writes_multiple_head_teachers` (9.3), `it_never_writes_pp_for_non_class_type` (9.4), `it_ignores_head_teacher_not_in_members` (9.5), `it_persists_head_teacher_pivot_on_save` (pivot/AC6), `it_is_idempotent_across_repeated_pp_writes` (AC7), `it_keeps_pp_stable_after_syncFromAd_roundtrip` (9.6, D2). `tests/Feature/Livewire/Users/HeadTeacherSectionTest.php` couvre l'UI : rendu gated (9.7), abort non-classe/introuvable, toggle limité profs (9.8), désignation/retrait persistant le pivot + toast (9.1 côté UI), double guard `update-group` (9.9). Le `GroupRepository` est mocké (assertions sur les appels `addMember`/`removeMember` `PP_3A`) ; l'écriture AD réelle (`samba-tool group listmembers PP_3A`) = validation manuelle /vm, **différée post-merge**.

> **Checklist rapide pré-prod (4.15)** : migration data 4.14 jouée (§8.7) → désigner un PP en UI sur `3A` → `samba-tool group listmembers PP_3A` = {prof1} (9.1) → vérifier `Equipe_3A` toujours = {prof1,prof2} (orthogonalité, R1) → décocher → `PP_3A` vide (9.2) → re-`syncFromAd` ne fait pas clignoter le flag (9.6) → section absente sur un groupe non-classe (9.4/9.7) → toggle absent pour un élève (9.8).

### Scénario 9.10 — Post-review : write description AD conditionnel (Q1, 2026-06-25)

Un **toggle PP** (`oldName == newName`, `display_name` inchangé) ne déclenche **aucune** écriture LDAP de description : avant le correctif, `updateGroupDescription` était appelé systématiquement (write + `RuntimeException` possible) même sans changement. Un changement réel de `display_name` déclenche **toujours** l'écriture (comportement nominal préservé). La branche **rename** (`oldName != newName`) est inchangée.

> Couverture : `it_skips_ad_description_write_when_description_unchanged` (aucun write sur toggle PP), `it_writes_ad_description_when_display_name_changes` (write nominal) dans `UserGroupServiceLegacyCompatibilityTest.php`.

### Scénario 9.11 — Post-review : toast honnête sur convergence du flag PP (Q2, 2026-06-25)

Le toast de succès n'est affiché que si l'état **persisté** du groupe courant a réellement convergé vers l'ensemble PP intendu (`$ppIds` envoyé). En cas de read-back AD fail-soft incomplet (le pivot `is_head_teacher` ne reflète pas l'intention), la SFC affiche un **`toastWarning`** « Professeur(s) principal(aux) enregistré(s) en base, mais la synchronisation AD est incomplète — réessayez. ». La vérification cible **uniquement** le groupe courant (requête fraîche, pas le compteur d'erreurs global de `syncFromAd` qui agrégerait d'autres groupes).

> Couverture : chemin nominal (PP convergent → `toastSuccess`) couvert par les tests Livewire existants (`it_designates_a_head_teacher_and_persists_pivot`). Le chemin `toastWarning` exige un read-back AD qui échoue : non simulable simplement avec le mock service actuel (qui pose le pivot directement) → couvert par **inspection**, validation manuelle /vm.

### Scénario 9.12 — Post-review : section invisible/refusée sans `user.read` (Q3, 2026-06-25)

`mount()` est gardé par `Gate::authorize('view-group', $group)` (== `user.read`, `GroupPolicy`) **après** le `abort(404)` anti-forge, **avant** d'exposer quoi que ce soit. Un utilisateur sans `user.read` ne peut pas instancier la section pour lire les membres profs via `wire:call` (le `@can('view-group')` du template ne couvrait que le rendu UI). En prod, tous les rôles seedés avec `user.modify` ont aussi `user.read`, donc `save()` reste fonctionnel.

> Couverture : `it_blocks_mount_without_read_permission` (user `user.modify` seul → `AuthorizationException` au mount) dans `HeadTeacherSectionTest.php` ; non-régression `UserAdmin` (read+modify) monte normalement via les tests de rendu existants.

---

## Section 10 — Scope du read-back `syncFromAd` de `updateGroup` (Story 4.16, 2026-06-25)

> **But.** Chaque appel à `UserGroupService::updateGroup()` (rename, édition de membres, toggle Professeur principal…) déclenchait un `syncFromAd()` **global** : re-balayage de TOUS les groupes AD de l'établissement + risque de suppression hors scope via le cleanup `whereNotIn` (mode global). 4.16 scope ce read-back au seul groupe édité en passant `onlyGroupNames: [$lookupName]` (base nue pour classe/équipe → remonte les 3 variantes `Classe_`/`Equipe_`/`PP_` via le filtre l.335-368 ; CN brut pour les autres types). En mode scopé, le cleanup `whereNotIn` (l.433) **ne tourne pas** : aucune ligne hors scope n'est purgée. `deleteGroup` reste délibérément en read-back global (il dépend du `whereNotIn` pour retirer la ligne supprimée — D3).

> **Pré-requis.** AD réel /vm (DC se4ad) avec des classes foldées (`Classe_3A`, `Equipe_3A`, `PP_3A`). La migration data 4.14 jouée (§8.7). Permission `user.modify`. Logs Laravel accessibles pour observer les compteurs « N CN AD détecté(s) → M groupe(s) projeté(s) ».

> ⚠️ **CASSE DES CN AD (parc réel).** L'AD du parc porte majoritairement des CN legacy en **minuscule** (`classe_3a`/`equipe_3a`/`pp_3a`), pas la forme canonique `Classe_3A` (cf. mémoire `project_vm_ad_junk_classe_groups`). Les scénarios ci-dessous écrivent `Classe_3A` par lisibilité, mais sur `/vm` réel vous observerez `classe_3a` — c'est **normal**, pas une anomalie. 4.16 a rendu le fold (`foldPrefixOf`/`stripClasseLikePrefix`/détection de type) **insensible à la casse** : c'est ce qui permet au read-back **scopé** (qui passe le nom nu `3a` à `onlyGroupNames`) de reconnaître les 3 variantes minuscules. Sans ce durcissement, le read-back scopé serait un no-op sur le parc réel et l'édition lèverait « introuvable après synchronisation ». La détection de préfixe réservé à la création (`guardReservedPrefixOnCreate`) et la résolution `matiere_classe` sont aussi insensibles à la casse (Q2) pour éviter les CN à double préfixe (`Classe_classe_x`).

### Scénario 10.1 — Read-back ciblé voit les 3 variantes (CRITIQUE)

1. Éditer le groupe `3A` (toggle PP ou description) → cliquer « Enregistrer ».
2. Observer les logs : le message `N CN AD détecté(s) → M groupe(s) projeté(s)` doit indiquer **3 CN, 1 groupe** (uniquement `Classe_3A`/`Equipe_3A`/`PP_3A`) — et non l'ensemble des groupes de l'établissement.
3. La ligne nue `3A` conserve l'**union complète** de ses membres (`alice` de `Classe_3A`, `bob` de `Equipe_3A`) et le flag `is_head_teacher` re-posé depuis `PP_3A`.
4. **Aucune autre ligne `user_groups`** n'est modifiée.

### Scénario 10.2 — Aucune purge hors scope (CRITIQUE)

1. Éditer `3A` pendant qu'un autre groupe `5C` existe en SQL.
2. Simuler une **réponse AD incomplète** (momentanée : `5C` absent du lot renvoyé) — en pratique : observer que, même si l'AD était lent à répondre, `5C` n'est jamais supprimé de `user_groups` lors d'un `updateGroup('3A')`.
3. Vérifier après l'édition que la ligne `5C` est toujours présente en SQL (`SELECT id, name FROM user_groups WHERE name='5C'`).
4. Comparer le comportement de `deleteGroup('5C')` : lui passe par le read-back global et **supprime bien** la ligne `5C` (comportement intentionnel D3, à contraster avec 10.2).

### Scénario 10.3 — Rename → scope sur le NOUVEAU nom

1. Renommer la classe `3A` en `3B` depuis l'UI (AD renommé en `Classe_3B`/`Equipe_3B`/`PP_3B` avant le read-back).
2. Observer les logs : le read-back cible **`3B`** (base du nouveau nom), pas `3A`.
3. La ligne SQL converge vers `3B` avec ses membres ; `3A` n'existe plus en SQL.
4. Un autre groupe préexistant (`5C`) **n'est pas purgé**.

### Scénario 10.4 — Non-régression PP convergence (D2, aller-retour stable)

1. Désigner `prof1` PP sur `3A` (UI) → enregistrement.
2. Observer : `PP_3A={prof1}` en AD, `(3A,prof1).is_head_teacher=true` en SQL (read-back scopé a re-posé le flag depuis `PP_3A`).
3. **Aller-retour stable** : relancer un `syncFromAd` (bouton « Synchroniser avec AD ») → flag inchangé, membres inchangés (pas de clignotement).
4. Éditer la liste de membres (retirer `prof2`, sans `head_teacher_ids`) → `PP_3A` préserve `prof1` (M6, préservation des PP existants) ; le read-back scopé ré-affirme le flag.

### Scénario 10.5 — Read-back scopé sur CN AD MINUSCULES (parc réel, CRITIQUE)

> C'est le scénario qui reflète l'AD réel `/vm` (CN legacy minuscules) et qui justifie le durcissement casse-insensible de 4.16. À dérouler en priorité sur le parc.

1. Identifier une classe dont les CN AD sont en minuscule : `samba-tool group list | grep -iE '^classe_'` → typiquement `classe_3a`, `equipe_3a`, `pp_3a`.
2. Vérifier la ligne SQL foldée correspondante : `php artisan tinker --execute="echo App\\Models\\UserGroup::whereRaw('LOWER(name)=?',['3a'])->value('name');"` (le fold a projeté une ligne nue `3a`).
3. Éditer ce groupe depuis l'UI (toggle PP ou description) → « Enregistrer ».
4. **Attendu** : l'édition aboutit (pas d'erreur « introuvable après synchronisation »), la ligne nue conserve l'union de ses membres, et le flag `is_head_teacher` reste posé depuis `pp_3a`. Les logs montrent **3 CN → 1 groupe**, scope sur le nom nu minuscule.
5. **Régression à surveiller** : si l'édition d'une classe à CN minuscule échoue avec « introuvable après synchronisation », c'est le signe que le fold est redevenu sensible à la casse (`str_starts_with` réintroduit) → le read-back scopé ne voit plus les CN minuscules.

> **Couverture automatisée.** `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` couvre : `it_scopes_read_back_to_edited_group_on_update` (10.1 — scope ciblé voit les 3 variantes + flag PP, AC1+AC2) ; `it_does_not_purge_out_of_scope_groups_on_update` (10.2 — `5C` survit, AC4) ; `it_scopes_read_back_to_new_name_on_rename` (10.3 — rename ciblé nouveau nom, AC3) ; `it_scopes_read_back_for_non_class_type` (type `cours`, scope CN brut, AC5) ; `it_scopes_read_back_to_edited_group_on_update_with_lowercase_ad_cns` (10.5 — read-back scopé sur CN minuscules, garde contre régression de casse) ; casse-insensibilité de la création : `it_rejects_lowercase_reserved_prefix_name_for_classe_like_create` + `it_does_not_re_expand_lowercase_prefixed_matiere_classe_cn` (Q2) ; non-régression PP : `it_keeps_pp_stable_after_syncFromAd_roundtrip` (10.4 aller-retour stable, AC6) et `it_preserves_head_teachers_when_updateGroup_omits_head_teacher_ids` (10.4 préservation M6, AC7). La réduction du balayage AD observable dans les logs (`N CN → M groupe(s)`) = **validation manuelle /vm, différée post-merge** (voir runbook ci-dessous).

> **Runbook E2E /vm (différé post-merge).** (1) Ouvrir les logs Laravel : `tail -f /var/www/sambaedu-reload/storage/logs/laravel.log`. (2) Éditer un groupe `3A` (toggle PP ou description) depuis l'UI. (3) Vérifier que le message `[UserGroupService]` indique **3 CN AD détecté(s) → 1 groupe(s) projeté(s)** (vs N_établissement CN avant 4.16). (4) Vérifier qu'un second groupe SQL (`5C`) est toujours présent : `php artisan tinker --execute="echo App\\Models\\UserGroup::where('name','5C')->exists() ? 'OK' : 'PURGÉ';"`. (5) Contrôle `deleteGroup` : supprimer `5C` → ligne disparaît (comportement global D3 intentionnel préservé).

> **Checklist rapide pré-prod (4.16)** : déployer (inotify synctomé) → config:cache + route:cache si nécessaire → éditer un groupe en UI → vérifier log « 3 CN → 1 groupe » → vérifier un groupe hors scope non purgé → `deleteGroup` d'un groupe test : ligne supprimée (read-back global D3 intact).

---

## Section 11 — Scoping du Gate WPKG par périmètre (Story 29.1, 2026-06-26)

> **But.** L'enforcement des assignations WPKG (profils/applications/options `.ini`) passait par un Gate **global** Spatie `wpkg.assign`, aveugle au périmètre : une délégation WPKG accordée sur **une salle** ne faisait rien (bloquée partout faute du droit global), et un technicien à droit global agissait sur **toutes** les salles. 29.1 remplace ce Gate global par un Gate **scopé** `assign-wpkg-workstationGroup` (méthode `WorkstationGroupPolicy::assignWpkg`, calquée sur `manage`/`computer.control`) : sur la page **groupe** le scope est le parc (`$this->group`), sur la page **machine** c'est la **salle physique** du poste (`Workstation::physicalRoom`). La logique de décision (exclusion négative prévalant, expiration, fallback global) est entièrement déléguée à `PermissionService::canOnWorkstationGroup` (réutilisé, pas réimplémenté). Un garde **defense-in-depth** double l'enforcement dans `AppProfileService` (inerte hors contexte HTTP : `Auth::check()===false`).

> **Pré-requis.** Schéma Spatie + délégations 7.x (`delegations`, `PermissionService`). Permission `wpkg.assign` seedée. Deux salles physiques (`salle_a`, `salle_b`), au moins un poste rattaché à `salle_a`, un poste **sans** salle physique (nomade). Trois comptes : un **délégué** WPKG sur `salle_a` (non-admin), un **technicien global** (`wpkg.assign` direct/rôle), un **lambda** sans droit.

### Scénario 11.1 — Délégué WPKG voit/agit sur SA salle, pas les autres (CRITIQUE)

1. Accorder une délégation **positive active** `wpkg.assign` sur `salle_a` au délégué (drawer Délégations).
2. Page **groupe** `salle_a` (onglet Applications WPKG) : les boutons d'ajout/retrait de profils, d'applications, « Bulk catégorie » et « Cloner cette configuration » sont **visibles** ; l'ajout d'un profil/app **aboutit**.
3. Page **groupe** `salle_b` : les mêmes contrôles sont **masqués** (`@can` faux) ; toute action forcée (ex. via DOM) lève `AuthorizationException` (toast « Vous n'avez pas la permission… »).

### Scénario 11.2 — Technicien global agit partout (non-régression, CRITIQUE)

1. Donner le droit **global** `wpkg.assign` à un technicien (permission directe ou via rôle).
2. Vérifier que sur `salle_a`, `salle_b`, **et** un poste **sans** salle physique, tous les contrôles WPKG sont visibles et les actions aboutissent.
3. **Régression à surveiller** : si le technicien global est bloqué sur une salle, le fallback global de `assignWpkg` est cassé (le risque #1 de la story).

### Scénario 11.3 — Exclusion négative active prévaut même sur le droit global

1. Sur un user disposant du droit **global** `wpkg.assign`, poser une **exclusion négative active** `wpkg.assign` sur `salle_a`.
2. Sur la page groupe `salle_a` : les contrôles WPKG sont **masqués** et toute action est **refusée** (l'exclusion prévaut sur le global).
3. Sur `salle_b` (pas d'exclusion) : le droit global continue de couvrir → autorisé.

### Scénario 11.4 — Délégation expirée → refus

1. Accorder au délégué une délégation `wpkg.assign` sur `salle_a` avec `expires_at` **dépassé**.
2. Page groupe `salle_a` : contrôles masqués, action refusée (le scope `->active()` exclut les délégations expirées).

### Scénario 11.5 — Page machine = salle physique du poste

1. Délégué de `salle_a` : ouvrir la fiche d'un **poste rattaché à `salle_a`** (onglet WPKG + sous-onglet Options) → contrôles visibles, action (attacher une app, basculer une option `.ini`, enregistrer) **aboutit**.
2. Ouvrir un **poste rattaché à `salle_b`** → contrôles masqués, action refusée.

### Scénario 11.6 — Poste sans salle physique (nomade) → admin global seul

1. Délégué de `salle_a` : ouvrir un **poste nomade** (aucune salle physique). `Workstation::physicalRoom` vaut `null` → la policy se rabat sur le droit global : le délégué est **refusé** (pas de fausse ouverture).
2. Technicien **global** : sur le même poste nomade, les contrôles sont visibles et les actions aboutissent.

### Scénario 11.7 — Defense-in-depth couche service (appelant non-web inerte)

1. Sous le **délégué de `salle_a`** (contexte web) : un appel à `AppProfileService::addApplicationsToWorkstationGroup(salle_b, …)` lève `AuthorizationException` (aucune écriture pivot).
2. **Hors contexte utilisateur** (commande artisan / seed / agent, `Auth::check()===false`) : la même méthode **ne lève pas** et s'exécute (non-régression des appelants non-web).

### Scénario 11.8 — Page profils : attache d'un profil à un POSTE hors périmètre (review 29.1, faille M1) (CRITIQUE)

> **Pourquoi ce scénario** : la review (2e avis opus) a trouvé que le chemin **profil→poste** (`addWorkstations`/`removeWorkstations`, surface = page `parc-settings/profiles`) contournait entièrement le verrou WPKG — un rôle ayant `computer.install` mais **pas** `wpkg.assign` (ex: `ReferentNumerique`) pouvait attacher un profil d'apps à n'importe quel poste de n'importe quelle salle. La page profils n'ayant **pas** de garde UI `@can`, le seul rempart est le garde service. Test unitaire vert ≠ chemin couvert : angle détectable uniquement en pensant « toutes les surfaces qui matérialisent une assignation par-poste ».

1. Sous un **délégué WPKG de `salle_a`** (ou un `ReferentNumerique` sans `wpkg.assign`) : ouvrir `parc-settings/profiles/{id}`, tenter d'**attacher** le profil à un **poste de `salle_b`** → l'action est **refusée** (`AuthorizationException` → toast d'erreur), **aucune** ligne dans `app_profile_workstation`.
2. Le même délégué attache le profil à un **poste de `salle_a`** → **autorisé**, ligne pivot créée, le poste reçoit les apps au cycle WPKG suivant.
3. Vérifier la symétrie sur le **détachement** (`removeWorkstations`) : refus hors périmètre, succès dans le périmètre.
4. **Note UX (Q1 review)** : sur la page profils les boutons restent visibles même sans droit (pas de `@can`) → l'action échoue par toast générique. Acceptable en zero-prod ; à durcir (masquage `@can`) si l'UX le justifie.

> **Couverture automatisée.** `tests/Unit/Policies/WorkstationGroupPolicyWpkgTest.php` : délégué autorisé sur A / refusé sur B (11.1), admin global partout + scope null (11.2), exclusion négative prévalant sur global (11.3), délégation expirée refusée (11.4), groupe logique → fallback global seul, user sans droit refusé, et résolution via la façade `Gate::allows('assign-wpkg-workstationGroup', …)` (enregistrement `RegistersGates`). `tests/Feature/AppProfile/AppProfileServiceWpkgScopingTest.php` : garde service délégué A OK / B refusé (11.7), appelant non authentifié non bloqué (11.7). La vérification visuelle des `@can`/`@cannot` (boutons masqués) et le scope page machine (11.5/11.6) = **validation manuelle UI**, l'enforcement serveur étant couvert par les tests ci-dessus.

> **Checklist rapide pré-prod (29.1)** : déployer → grep garde-fou (`Gate::authorize('wpkg.assign')` global et `@can('wpkg.assign')` sans modèle = **0** dans `resources/views/pages/parc/`) → délégué WPKG sur une salle agit sur SA salle et pas les autres → technicien global agit partout → exclusion négative refuse même sur global → poste nomade : seul l'admin global passe.

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

### Post-correctifs Story 29.1 (review 2026-06-26 — sonnet + 2e avis opus)

| Incident | Couvert par |
|----------|-------------|
| M1 — chemin profil→poste (`addWorkstations`/`removeWorkstations`) non gardé → verrou WPKG contournable par-poste (page profils sans garde UI ; AC #6 faux pour ce chemin). Manqué par sonnet, rattrapé par opus | Garde service `assertCanAssignWpkgOnGroup($ws->physicalRoom)` ajouté (symétrique `addApplicationsToWorkstation`). Scénario **11.8** + tests `delegate_of_a_can/cannot_attach_profile_to_workstation_in_*` |
| #1 — test `gate_denies_when_user_lacks_wpkg_assign` tautologique (testait l'ancien gate global) | Test supprimé ; scoping réel couvert par `WorkstationGroupPolicyWpkgTest` (7 cas) + `AppProfileServiceWpkgScopingTest` |
| #3 — `app/Wpkg/Deployment/README.md` décrivait encore le gate global | Doc corrigée (gate scopé + fallback + defense-in-depth) |

### Post-correctifs Story 4.14 (review 2026-06-25 — sonnet + 2e avis opus)

| Incident | Couvert par |
|----------|-------------|
| #1/M3 — `resolveBareName` non déterministe (divergence de casse) → nom nu désync du lookup 4.13, risque doublon/violation UNIQUE en PG | Déterminisation : ligne nue prioritaire sinon strip du CN canonique (`Classe_`>`Equipe_`>`PP_`), byte-identique à `stripClasseLikePrefix`. Scénario 8.3 + tests fusion |
| #2 — `PP_<X>` isolé renommé sans poser `is_head_teacher` (PP invisibles pour 4.15 sans resync) | Flag posé dans `renameLonelyPrefixedRow`. Test `it_flags_head_teacher_when_pp_row_is_lonely` |
| #7 — Collision de nom nu silencieuse (ligne préfixée résiduelle indiagnosticable sur 75 étab) | `Log::warning` + compteur `skipped_collisions`. Test `it_skips_and_reports_collision` ; **vérifier les logs migration en prod** (cf. 8.7) |
| M1 — Fusion data hors transaction → base semi-fusionnée en cas d'échec PG à mi-parcours | `DB::transaction` par base (atomicité). Vérifier 8.7 : un `migrate` interrompu ne laisse aucune base à mi-chemin, re-run idempotent |
| AC1 — colonne / rétro-remplissage / `down()` non exercés par la migration réelle | Test `it_real_migration_adds_column_retrofills_false_and_down_drops_it` |

> Angles documentés sans correction (jugés intentionnels/mitigés) : #5 (survivante `equipe`+`Classe_` hérité → type `classe`, voulu) ; M5 (N+1 acceptable pour une migration one-shot) ; M6 (la FK réelle `user_group_user` cascade en prod — pas d'orphelin ; écart de fidélité côté tables de test seulement).

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

**Section 9 — Écriture `PP_<X>` + UI Professeur principal (Story 4.15)**
- [ ] 9.1 **PP désigné → `PP_3A`={prof1} ; orthogonalité (CRITIQUE)** : prof1 reste dans `Equipe_3A` (rwx prof inchangé), aucun prof retiré
- [ ] 9.2 Dernier PP retiré → `PP_3A` vidé (pas de rémanence), `Equipe_`/`Classe_` inchangés
- [ ] 9.3 Plusieurs PP → `PP_3A`={prof1,prof2}
- [ ] 9.4 Type non-classe → section absente + aucun `addMember("PP_…")`
- [ ] 9.5 PP hors membres → ignoré (pas d'exception)
- [ ] 9.6 Aller-retour `syncFromAd` stable (flag ne clignote pas, D2)
- [ ] 9.7 Section gated classe + abort `mount` sur groupId non-classe (anti-forge)
- [ ] 9.8 Toggle PP limité aux membres `isProf()` (pas de contrôle sur un élève)
- [ ] 9.9 Double guard `update-group` (lecture seule sans `user.modify`, `save` rejeté serveur)

**Section 10 — Scope du read-back `syncFromAd` de `updateGroup` (Story 4.16)**
- [ ] 10.1 **Read-back ciblé voit les 3 variantes (CRITIQUE)** : log « 3 CN → 1 groupe » après édition de `3A` ; membres + flag PP intacts
- [ ] 10.2 **Aucune purge hors scope (CRITIQUE)** : groupe `5C` SQL survit à un `updateGroup('3A')` même si AD ne retourne pas `5C`
- [ ] 10.3 Rename → scope sur le NOUVEAU nom ; `5C` non purgé
- [ ] 10.4 Non-régression PP convergence : flag re-posé depuis `PP_3A`, aller-retour stable (pas de clignotement)

**Non-régressions**
- [ ] Drawer Rôles + Permissions
- [ ] `/parc` admin visibilité totale
- [ ] Actions power / wallpaper / schedules
- [ ] ~30 `@can` Blade spot-check

---

## Section 12 — Scoping de `app.customize` (override capacité par parc) (Story 29.6, 2026-06-27)

> **Contexte.** L'onglet « Options / Capacités » d'un parc (`capabilities-tab`) autorisait l'écriture/le retrait d'overrides de capacité via le droit Spatie `app.customize` évalué **GLOBALEMENT**, et `groupId` était une propriété Livewire publique **hydratée côté client** (falsifiable). Un refnum disposant d'`app.customize` pouvait donc écrire/retirer (et faire auditer 29.5) un override sur **un autre parc** en altérant `groupId` (faille M4 tracée à la review 29.5).
>
> **Fix (LES DEUX, indissociables).** (1) `#[Locked] public int $groupId` (Livewire 4.3) → le périmètre est serveur-autoritatif, toute mutation client lève `CannotUpdateLockedPropertyException` ; (2) `WorkstationGroupPolicy::customize()` (jumelle de `assignWpkg` 29.1) + gate `customize-workstationGroup` ; `guardCustomize()` résout le `WorkstationGroup` **côté serveur** depuis `$this->groupId` et appelle le gate scopé (defense-in-depth, ne se fie à aucun flag client). `mount()` réordonné : `groupId` assigné **avant** le garde.
>
> **Interaction connue (hors-scope 29.6).** Le gate `modify-capability` (29.2, appelé par `authorizeUpstream`) exige le droit **global** `app.customize` comme plancher. Un délégué **positif-seul** passe donc le guard d'accès (mount/openAdd) mais ne peut pas **finaliser une écriture** tant que ce plancher reste global. Le scoping par-parc effectif de l'écriture concerne donc aujourd'hui un détenteur du droit global restreint par **exclusions négatives** par parc (et le figement anti-tampering). Le plancher scopé de `modify-capability` relève d'un suivi 29.2/Epic 31.

### Scénario 12.1 — Délégué `app.customize` accède à SA salle, pas aux autres (CRITIQUE)
- **Préparation** : refnum avec une **délégation positive active** `app.customize` sur le parc physique A (aucun droit global), aucune délégation sur B.
- **Attendu** : l'onglet « Options / Capacités » du parc **A** s'ouvre (mount OK) ; celui du parc **B** renvoie **403**. Avant 29.6, le délégué positif-seul aurait été refusé même sur A (contrôle global).

### Scénario 12.2 — Technicien global agit partout (non-régression, CRITIQUE)
- **Préparation** : technicien avec le droit **global** Spatie `app.customize`, aucune exclusion.
- **Attendu** : édition/retrait d'overrides autorisés sur **n'importe quel** parc (fallback global préservé, iso `assignWpkg`/`manage`).

### Scénario 12.3 — Exclusion négative active prévaut même sur le droit global (CRITIQUE)
- **Préparation** : refnum avec droit **global** `app.customize` **ET** une exclusion **négative active** `app.customize` sur le parc B.
- **Attendu** : écriture autorisée sur A ; sur B → **403**, **aucune** ligne `capability_assignments`, **aucune** trace `capability_override_audit_logs`. (Le guard scopé honore la négative ; l'ancien contrôle global l'ignorait — c'est le cœur du fix M4.)

### Scénario 12.4 — Délégation expirée → refus
- **Préparation** : délégation `app.customize` sur A dont `expires_at` est dépassé.
- **Attendu** : accès refusé (le scope `->active()` exclut les délégations expirées).

### Scénario 12.5 — Parc LOGIQUE → décision globale
- **Préparation** : parc `is_physical = false`.
- **Attendu** : la voie déléguée ne s'applique pas (`canCheckDelegation`) ; seul le droit **global** ouvre l'édition (convention identique à `view`/`manage`/`assignWpkg`).

### Scénario 12.6 — Tentative de falsification de `groupId` (anti-tampering, CRITIQUE)
- **Préparation** : composant monté légitimement sur A, puis tentative client de muter `groupId` vers B (`$set('groupId', B)` / payload falsifié).
- **Attendu** : Livewire lève `CannotUpdateLockedPropertyException` ; le périmètre reste figé à la valeur d'hydratation ; aucune écriture sur B.

### Scénario 12.7 — Verrou amont 29.2 intact (non-régression)
- **Préparation** : acteur autorisé par périmètre + capacité **verrouillée** par un item amont (`locked`/`registry`/`instance`).
- **Attendu** : malgré l'autorisation de périmètre, l'écriture est refusée serveur par `authorizeUpstream` (toast « verrouillée par un contrat amont »), **aucune** écriture — le scoping 29.6 et le verrou 29.2 coexistent (refus orthogonaux).

---

## Section 13 — Préservation de `created_at` du pivot lors d'un override édité (Story 29.7, 2026-06-27)

> **Contexte.** `saveOverride()` dans `capabilities-tab` utilisait `updateOrInsert(…, ['value'=>…,'updated_at'=>now(),'created_at'=>now()])` — le 2e argument (les VALEURS) étant appliqué aussi bien à l'INSERT qu'à l'UPDATE. Sur un UPDATE d'override existant, `created_at` du pivot `capability_assignments` était **réécrit à `now()`** à chaque édition, effaçant l'horodatage de création d'origine (défaut pré-existant hérité de 27.12, pas une régression 29.5). Fix chirurgical : remplacer le tableau de valeurs par une **closure** `fn(bool $exists)` qui pose `created_at` uniquement à l'INSERT (signature `array|callable $values` de `Builder::updateOrInsert`, Laravel 12.x).
>
> **Ce qui NE change PAS.** L'audit 29.5 (`CapabilityOverrideAuditLog`) horodate sa **propre** trace (`created_at` via `useCurrent()` / `log()`), indépendamment du pivot — l'historique d'audit reste correct même avec l'ancien bug. Le compilé (`StateCompiler`) lit la `value` du pivot, jamais `created_at`. L'atomicité acte↔trace (29.5, NFR5) est préservée : la closure s'exécute dans le même `updateOrInsert`, lui-même dans la même `DB::transaction`.

### Scénario 13.1 — Premier override d'une capacité : `created_at` posé à la création

- **Préparation** : capacité sans override existant pour le parc cible.
- **Action** : poser un premier override via l'onglet « Options / Capacités » du parc (bouton « Ajouter une capacité », choisir la valeur, enregistrer).
- **Attendu** : la ligne `capability_assignments` est créée avec `created_at` **non nul** (≈ l'heure de la demande) et `updated_at` également posé. La valeur est correcte.

  ```sql
  SELECT value, created_at, updated_at
  FROM capability_assignments
  WHERE capability_id = <id>
    AND assignable_type = 'App\\Models\\WorkstationGroup'
    AND assignable_id   = <parc_id>;
  -- created_at et updated_at : tous deux ≈ maintenant, non nuls.
  ```

### Scénario 13.2 — Ré-édition d'un override existant : `created_at` inchangé, `updated_at` rafraîchi (CRITIQUE)

> C'est le scénario qui prouve la non-réécriture du `created_at` d'origine. Avant 29.7, chaque édition écrasait silencieusement cette colonne — invisible en prod faute de test dédié.

- **Préparation** : un override existe déjà en base (posé via 13.1 ou directement en SQL) avec un `created_at` connu (ex : noté manuellement ou figé avec `UPDATE … SET created_at = '2026-01-01 10:00:00'`).
- **Action** : ré-éditer la valeur de l'override via l'onglet « Options / Capacités » (bouton « Éditer », choisir une valeur différente, enregistrer).
- **Attendu** :
  1. `value` = nouvelle valeur.
  2. `created_at` **strictement inchangé** (identique à la valeur d'avant l'édition).
  3. `updated_at` **postérieur** à `created_at` (avancé à l'heure de l'édition).

  ```sql
  SELECT value, created_at, updated_at
  FROM capability_assignments
  WHERE capability_id = <id>
    AND assignable_type = 'App\\Models\\WorkstationGroup'
    AND assignable_id   = <parc_id>;
  -- created_at : INCHANGÉ (valeur d'avant l'édition).
  -- updated_at : > created_at (avancé).
  ```

- **Régression à surveiller** : si `created_at` vaut l'heure de la dernière édition (et non la date initiale), le bug pré-existant est revenu — la closure a été remplacée par un tableau statique.

### Scénario 13.3 — Retrait d'un override (non concerné par 29.7)

- `removeOverride()` effectue un `DELETE`, sans `updateOrInsert`. Ce scénario confirme que la suppression reste inchangée (pas de `created_at` concerné) et que l'audit de suppression (`action=delete`, `old_value` correct, `new_value=null`) reste intact (non-régression 29.5).

### Scénario 13.4 — Audit 29.5 intact (non-régression)

- Après chaque opération des scénarios 13.1 et 13.2, vérifier que la table `capability_override_audit_logs` contient bien les traces attendues (`action=create` à 13.1, `action=update` à 13.2 avec `old_value`/`new_value` corrects). L'horodatage de la trace d'audit (`created_at` de la ligne audit) ne dépend pas du pivot — il reste correct indépendamment du fix.

> **Couverture automatisée.** `tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php` : `inserting_a_new_override_sets_created_at` (13.1 — INSERT pose `created_at` non nul) et `re_editing_an_override_preserves_original_created_at` (13.2 — `created_at` figé dans le passé, inchangé après ré-édition, `updated_at` avancé). La vérification visuelle SQL en prod (13.2 sur override réel) = validation manuelle post-déploiement recommandée.

---

## Section 14 — Habilitation du délégué `app.customize` positif-seul (retrait du plancher `modify-capability`) (Story 29.8, 2026-06-27)

> **Contexte.** Le gate `modify-capability` (`CapabilityPolicy::modify`, posé en 29.2 et appelé par `authorizeUpstream()` sur les deux surfaces capacité) imposait DEUX conditions cumulées : (1) un **plancher de droit GLOBAL** `app.customize` (`$user->can('app.customize')`, jamais scopé par parc) ET (2) l'**absence de verrou amont**. Depuis 29.6, l'onglet « Options / Capacités » garde déjà l'accès via le gate **scopé** `customize-workstationGroup` (délégation par parc opposable). Mais au write-through, le plancher GLOBAL (1) rebloquait un **délégué positif-seul** (délégation `app.customize` scopée sur son parc, **sans** droit global) que le guard scopé venait d'autoriser → l'habilitation promise par l'AC#1 de 29.6 n'était pas livrée pour ce persona (P1 review 29.6).
>
> **Constat clé — `modify-capability` est DUAL-PURPOSE.** Il sert l'override **par-parc** (`capabilities-tab`, droit **scopé** `customize-workstationGroup`) ET le défaut diffusé **global** d'instance (`registry-tab`, droit **global** `server.admin`). Scoper le plancher *dans* le gate empièterait sur l'une ou l'autre surface.
>
> **Fix.** Retrait du plancher de droit de `CapabilityPolicy::modify` → le gate ne conserve **que** le verrou amont (`return $capability === null ? true : ! isCapabilityLocked($capability)`). Le droit est désormais porté **par chaque surface en amont** : `guardCustomize()` (scopé) sur `capabilities-tab`, `guardAdmin()` (`server.admin` global) sur `registry-tab` — tous deux abortent **403 avant** d'atteindre `authorizeUpstream()`. La **sécurité n'est pas perdue, elle a migré vers les surfaces** (prouvé par les scénarios 14.2/14.3 ci-dessous). La double-branche du message d'erreur (`catch`) est **conservée** en defense-in-depth (la branche « pas le droit » est désormais théoriquement inatteignable par défaut de droit, mais reste un garde-fou contre un futur appelant non gardé).

### Scénario 14.1 — Délégué positif-seul édite/ajoute/retire un override sur SON parc (désormais OK, CRITIQUE)
- **Préparation** : refnum avec une **délégation positive active** `app.customize` sur le parc physique A (**aucun** droit global Spatie), aucune délégation sur B.
- **Action** : sur l'onglet « Options / Capacités » du parc **A**, ajouter un override (choisir une capacité non verrouillée, poser une valeur, enregistrer), puis éditer cet override, puis le retirer.
- **Attendu** : les trois opérations **réussissent** (ligne `capability_assignments` créée puis supprimée ; formulaire d'édition ouvert). **Avant 29.8**, l'écriture échouait (bloquée par le plancher global) bien que l'onglet s'ouvre. C'est l'habilitation AC#1 de 29.6 enfin livrée.

### Scénario 14.2 — Délégué positif-seul reste bloqué sur un AUTRE parc (non-régression sécurité M4, CRITIQUE)
- **Préparation** : même délégué de A, composant ouvert sur le parc **B** (hors périmètre).
- **Attendu** : **403** dès le mount (`guardCustomize()` scopé), **aucune** ligne `capability_assignments`, **aucune** trace `capability_override_audit_logs`. Le retrait du plancher n'ouvre **aucun** chemin d'écriture hors-périmètre.

### Scénario 14.3 — Non-admin bloqué sur le défaut diffusé (registry-tab) (non-régression garde globale, CRITIQUE)
- **Préparation** : acteur porteur de `app.customize` (même délégation par-parc) mais **sans** `server.admin`, sur l'onglet « Registre / capacités » de `/admin/settings/parc-defaults`.
- **Attendu** : **403** (`guardAdmin()`) sur `openEdit` / `saveDefault` / `toggleLock` (et dès le mount) ; `capabilities.default_value` et `overrides_locked` **inchangés**. Le retrait du plancher **n'affaiblit pas** la garde globale `server.admin` du réglage d'instance.

### Scénario 14.4 — Capacité verrouillée amont refusée sur les DEUX surfaces (verrou = seul motif restant, CRITIQUE)
- **Préparation** : un item amont `locked`/`registry`/`instance` matche une clé de la capacité.
- **Attendu** : sur `capabilities-tab` (délégué autorisé sur SON parc) **ET** sur `registry-tab` (admin global), la mutation est refusée serveur par `authorizeUpstream` (toast « verrouillée par un contrat amont »), **aucune** écriture. Le **verrou amont 29.2** devient le **seul** motif de refus restant de `modify-capability`, sur les deux surfaces.

### Scénario 14.5 — Admin global inchangé (non-régression)
- **Préparation** : technicien avec le droit **global** `app.customize` (et `server.admin` pour registry-tab), aucune exclusion.
- **Attendu** : write-through autorisé sur n'importe quel parc (capabilities-tab) et sur le défaut diffusé (registry-tab), strictement comme avant 29.8.

> **Couverture automatisée.** `tests/Feature/Livewire/Parc/CapabilitiesTabCustomizeScopingTest.php` : `positive_delegate_can_complete_write_through_on_a` / `…can_open_edit_on_a` / `…can_remove_override_on_a` (14.1), `positive_delegate_is_forbidden_on_b_without_write_or_audit_trace` (14.2), `positive_delegate_is_still_blocked_by_upstream_lock` (14.4 capabilities-tab), `global_admin_can_save_override_on_a_and_b` (14.5). `tests/Feature/Livewire/Admin/ParcDefaultsUpstreamLockTest.php` : `non_admin_is_blocked_on_registry_tab` (14.3 — la fermeture au mount est aussi couverte par `AdminSettingsParcDefaultsPageTest::registry_tab_gate_blocks_mount_without_server_admin`), `save_default_is_blocked_for_upstream_locked_capability` / `toggle_lock_is_blocked_for_upstream_locked_capability` (14.4 registry-tab). `tests/Unit/Policies/CapabilityPolicyTest.php` : contrat unitaire révisé (`right_is_no_longer_enforced_at_policy_level`, `null_capability_is_always_allowed`, `deny_when_capability_is_upstream_locked`).

## Section 15 — Rôle sur l'arête `user_group_user.role` + backfill (Story 42.1, 2026-07-13)

> **Contexte.** Le rôle d'un utilisateur DANS un groupe devient un attribut d'arête `role` (`member|manager|owner`) sur le pivot `user_group_user`, backfillé depuis l'existant (`is_head_teacher` + `users.role`). `role === 'owner'` **absorbe** l'ancien flag d'arête `is_head_teacher` (professeur principal). **Aucune écriture AD** dans cette story : `is_head_teacher` reste écrit EN MIROIR de `role` (invariant `role === 'owner'` ⇔ `is_head_teacher === true`) tant que la projection AD 4.15 le lit (bascule + suppression = Story 42.2). Le rôle GLOBAL `users.role` est CONSERVÉ (policies, création de home, droits UI). Vocabulaire borné APPLICATIVEMENT (SQLite ne borne pas les varchar).

### Runbook migration VM (geste post-merge MANUEL — `project_vm_migrations_not_auto_applied`)
- **Avant** : `cd /var/www/sambaedu-reload && php artisan migrate:status` — la ligne `2026_07_13_120000_add_role_to_user_group_user` doit être `Pending`.
- **Appliquer** : `php artisan migrate --force` puis, si config cachée, `php artisan config:cache && chown www-admin:www-admin` sur les fichiers concernés.
- **Après** : `migrate:status` → la migration passe `Ran`. Contrôle SQL : `SELECT role, COUNT(*) FROM user_group_user GROUP BY role;` — répartition attendue `owner` (ex-PP) / `manager` (profs membres) / `member` (le reste). PG borne réellement le varchar(20) ; SQLite non.

### Scénario 15.1 — Backfill déterministe (owner > manager > member)
- **Préparation** : une classe `3A` avec un élève, un prof membre non-PP, un prof PP (`is_head_teacher=true`).
- **Attendu** : après `migrate` (ou l'action `BackfillUserGroupUserRoles`), `(3A, prof PP)` = `owner`, `(3A, prof membre)` = `manager`, `(3A, élève)` = `member`. Précédence : un prof PP est `owner` (pas `manager`).

### Scénario 15.2 — Idempotence du backfill
- **Préparation** : rejouer l'action `BackfillUserGroupUserRoles` deux fois de suite.
- **Attendu** : état final identique, aucune exception, même compte-rendu de comptage. Une arête au `role` obsolète est réalignée sur l'état courant (`is_head_teacher` + `users.role`). Aucune requête LDAP (dérivation SQL pure sur la colonne `users.role`).

### Scénario 15.3 — Read-back import pose `role` en miroir
- **Préparation** : AD `Classe_3A={alice(élève)}`, `Equipe_3A={bob(prof), carol(prof)}`, `PP_3A={bob}` ; lancer `syncFromAd` (ou `updateGroup` avec `head_teacher_ids=[bob]`).
- **Attendu** : `(3A, bob)` = `owner` + `is_head_teacher=true` ; `(3A, carol)` = `manager` + `false` ; `(3A, alice)` = `member` + `false`. Un 2ᵉ `syncFromAd` = no-op (aucune bascule de rôle fantôme). L'union/dédup/détache 4.13 reste intacte. NOTE : l'import RESTE autoritaire sur `role` (AD-first transitoire) — un `role` édité à la main serait réécrit ; comportement raffiné en 42.4.

### Scénario 15.4 — Fusion legacy pose `role` en miroir (garde ordre de migrations)
- **Préparation** : base héritée pré-fold avec `Classe_3A`/`Equipe_3A`/`PP_3A` ; exécuter l'action `MergeLegacyUserGroups`.
- **Attendu** : après fusion, les PP portent `owner`, les profs `manager`, les élèves `member` sur la ligne survivante — miroir du flag `is_head_teacher`. **Cas critique** : sur une base **sans** la colonne `role` (pré-42.1), l'action ne lève PAS (garde `Schema::hasColumn`) et conserve le comportement 4.14 (flag seul).

### Scénario 15.5 — Défaut de rôle au rattachement (nouvelles arêtes uniquement)
- **Préparation** : importer un prof (rattaché à une classe + un groupe rôle), puis un élève ; enfin, re-importer un prof déjà PP (`owner`) d'une classe.
- **Attendu** : le prof reçoit `manager` sur ses NOUVELLES arêtes (dérivé de `users.role='prof'`), l'élève `member`. Le re-import du prof PP **ne rétrograde PAS** son arête `owner` (piège `syncWithoutDetaching`-avec-attributs : les attributs ne s'appliquent qu'aux arêtes réellement nouvelles). Le défaut DB `member` reste le filet pour tout attach hors chemins instrumentés.

### Scénario 15.6 — Vocabulaire borné applicativement (garde)
- **Préparation** : appeler `UserGroupUserPivot::assertValidRole()` avec une valeur hors `member|manager|owner` (ex. `superadmin`, `Owner` en casse mixte).
- **Attendu** : `InvalidArgumentException`. Le vocabulaire est en minuscules strictes ; `defaultRoleForGlobalRole()` ne renvoie JAMAIS `owner` (owner = désignation explicite du PP).

### Scénario 15.7 — Lecture UI du badge PP basculée sur `role === 'owner'`
- **Préparation** : ouvrir la fiche groupe `/app/users/groups/[id]` d'une classe avec un PP ; ouvrir la modale « Professeur principal ».
- **Attendu** : le badge PP (icône) et la sélection des PP se lisent désormais sur `role === 'owner'` (miroir de `is_head_teacher`). Un membre non-prof porteur de l'arête `owner` n'est PAS badgé (le badge reste gaté par le rôle GLOBAL `prof`). Le canal de projection (`save()` → `head_teacher_ids` → `updateGroup`) est INCHANGÉ.

### Scénario 15.8 — Aucune écriture AD (non-régression, CRITIQUE)
- **Préparation** : dérouler 15.3 et observer les appels au `GroupRepository` / `syncRoleAwareAdGroupMembers`.
- **Attendu** : aucune nouvelle écriture LDAP, aucun changement des cibles/CN projetés. `syncRoleAwareAdGroupMembers`, la dérivation `$headTeacherUserIds` d'`updateGroup` et le payload `head_teacher_ids` sont INTACTS (bascule = 42.2). `UserPolicy` inchangée (elle ne lit pas `is_head_teacher` — elle lit `User.role` global + co-membership de classe).

> **Couverture automatisée.** `tests/Unit/Models/UserGroupUserPivotTest.php` (vocabulaire + helpers), `tests/Feature/Migrations/BackfillUserGroupUserRolesTest.php` (backfill déterministe/idempotent + migration réelle up/down), `tests/Feature/Migrations/MergeLegacyUserGroupsMigrationTest.php` (miroir fusion + garde hasColumn), `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` (miroir read-back + idempotence + piège withPivot), `tests/Feature/Services/UserServiceClassChangeTest.php` (défaut au rattachement + non-rétrogradation owner), `tests/Feature/Livewire/Users/HeadTeacherSectionTest.php` & `GroupShowMembersTabsTest.php` (lectures UI sur `role === 'owner'`).

### Checklist rapide Section 15
- [ ] `migrate:status` : `add_role_to_user_group_user` `Pending` → `Ran` après `migrate --force` sur /vm
- [ ] Répartition `SELECT role, COUNT(*) FROM user_group_user GROUP BY role;` cohérente (owner/manager/member)
- [ ] Backfill idempotent (2 runs = même état), zéro LDAP
- [ ] Read-back pose `role` en miroir de `is_head_teacher` (PP→owner, prof→manager, élève→member)
- [ ] Fusion legacy pose `role` miroir ; sans colonne `role` = comportement 4.14 intact
- [ ] Défaut au rattachement : prof→manager, élève→member ; owner jamais rétrogradé au re-import
- [ ] `assertValidRole` rejette hors vocabulaire ; `defaultRoleForGlobalRole` jamais owner
- [ ] Badge PP UI lu sur `role === 'owner'` ; canal projection `head_teacher_ids` inchangé
- [ ] Aucune écriture AD ; `UserPolicy` inchangée

## Section 16 — Projection AD des memberships depuis les arêtes (Story 42.2, 2026-07-14)

> **Contexte.** Le routage des membres vers le trio legacy `Classe_X`/`Equipe_X`/`PP_X` passe des deux heuristiques historiques (partition `User::isProf()` — 4.12 ; flag d'arête `is_head_teacher` → `PP_` — 4.15) au **rôle d'arête** `user_group_user.role` (42.1) : `member` → `Classe_<base>`, `manager` → `Equipe_<base>`, `owner` → `Equipe_<base>` **ET** `PP_<base>` (orthogonalité 4.15 conservée). Bascule ATOMIQUE : plus AUCUNE lecture de `is_head_teacher` dans la chaîne de projection, et le miroir n'est plus écrit par le read-back (`projectFoldedGroup` ne pose que `role` — la colonne devient STALE, drop destructif différé post-42.4). Résolution du rôle effectif (D2) : override `owner` autoritaire depuis `head_teacher_ids ∩ membres` → rôle de l'arête (avec rétrogradation de projection `owner`→`manager` pour un ex-PP décoché) → défaut dérivé de `users.role` en UNE requête SQL (jamais de LDAP par user). Fail-soft partout : rôle hors vocabulaire = fallback + warning ; `PP_X` absent en AD = no-op. NOUVEAU : un **changement de rôle sur l'arête** (UPDATE pivot) reprojette automatiquement le groupe vers l'AD (observer `updated`, suspendu pendant `syncFromAd` par un flag dédié — la synchro FS ShareService, elle, continue de tourner au read-back). La mécanique de noms (fold 4.13, `stripClasseLikePrefix`, CN scopés OU étab, matching `ad_guid`) est INTACTE ; `ShareService`/`AclService`/`UserPolicy` : zéro diff.

### Runbook e2e /vm (différé post-merge)
- **Préalable** : `cd /var/www/sambaedu-reload && php artisan migrate:status` — la migration 42.1 `2026_07_13_120000_add_role_to_user_group_user` doit être `Ran` (les arêtes doivent porter un rôle backfillé AVANT toute projection par arêtes). Aucune migration nouvelle en 42.2.
- **Contrôle projection** : depuis la fiche groupe d'une classe `<x>`, modifier la liste des membres (ou les PP), puis : `samba-tool group listmembers Equipe_<x>` → les profs (`manager`+`owner`) ; `samba-tool group listmembers Classe_<x>` → les élèves (`member`) ; `samba-tool group listmembers PP_<x>` → les seuls PP (`owner`).
- **Contrôle brownfield (CRITIQUE)** : sur un groupe pré-peuplé SE4, comparer `listmembers Equipe_<x>` AVANT/APRÈS une édition sans changement de membres — AUCUN retrait ne doit apparaître.
- **Contrôle resync sur changement de rôle** : `UPDATE` du rôle d'une arête via l'app (dès 42.3 : édition UI ; en attendant : tinker `$group->users()->updateExistingPivot($userId, ['role' => 'manager'])`) → le membre change de groupe AD sans passer par l'edit-form.

### Scénario 16.1 — Routage 3 buckets par le rôle d'arête (CRITIQUE)
- **Préparation** : classe `3A` avec arêtes `alice=member`, `bob=manager`, `carl=owner` (état backfillé 42.1 ou posé main).
- **Action** : sauvegarder la liste des membres depuis l'edit-form (updateGroup SANS `head_teacher_ids`).
- **Attendu** : `Equipe_3A` = {bob, carl} (owner ⊂ équipe, jamais exclusif), `Classe_3A` = {alice}, `PP_3A` = {carl} (dérivé du pivot `role='owner'` — plus aucune lecture `is_head_teacher`). Les 3 cibles sont TOUJOURS synchronisées (bucket vide → vidage, pas de rémanence). Aucune requête LDAP par user (dérivation `users.role` en une requête pour les seuls membres sans arête).

### Scénario 16.2 — Changement de rôle d'arête → resync AD automatique
- **Préparation** : `bob` membre de `3A` avec arête `member` (présent dans `Classe_3A` en AD).
- **Action** : UPDATE du rôle de l'arête à `manager` (canal Eloquent : `updateExistingPivot`/`sync()` associatif — l'UI 42.3 empruntera ce canal).
- **Attendu** : reprojection AUTOMATIQUE du seul groupe concerné — bob ajouté à `Equipe_3A`, retiré de `Classe_3A`. Filtres : groupes `classe`/`equipe` uniquement ; AUCUN resync si l'update ne touche pas `role` ; AUCUN resync pendant un `syncFromAd` (flag dédié `$adResyncEnabled`, jamais `$syncEnabled` qui gouverne la synchro FS) ni quand `$syncEnabled=false` (imports users). Fail-soft : un échec LDAP est loggé, l'écriture pivot reste valide. Les writes SQL bruts (backfill, MergeLegacy) ne déclenchent RIEN (voulu).

### Scénario 16.3 — Ex-PP décoché : rétrogradation de projection (piège n°4)
- **Préparation** : `carl` arête `owner`, présent dans `Equipe_3A` ET `PP_3A`.
- **Action** : section « Professeur principal » → décocher carl → save (`head_teacher_ids=[]` explicite).
- **Attendu** : carl SORT de `PP_3A` (pas de rémanence) mais RESTE dans `Equipe_3A` (rétrogradation de projection `owner`→`manager` : pas de perte rwx). Son arête est réalignée `manager` par le read-back. La préservation « clé absente = PP conservés / `[]` explicite = effacement volontaire » (4.15 M6) est INTACTE.

### Scénario 16.4 — `PP_X` absent en AD : fail-soft (volumétrie lab1 : 4 pp_ seulement)
- **Préparation** : classe dont l'AD ne porte PAS de CN `PP_<base>` ; désigner un PP côté SE5.
- **Attendu** : AUCUNE exception — la lecture `PP_` renvoie vide, l'`addMember` échoue en silence (false), les cibles `Equipe_`/`Classe_` sont projetées normalement et le save aboutit. AD-first assumé : sans `PP_` lisible, le read-back ne peut pas poser `owner` (l'arête retombe sur le dérivé `manager` ; le toast UI signale la convergence incomplète — 4.15 Q2).

### Scénario 16.5 — Parité brownfield : aucun retrait de membre légitime (CRITIQUE, piège n°2)
- **Préparation** : AD fédéré pré-peuplé SE4 (`Equipe_3A={prof1,prof2}`, `Classe_3A={eleve}`), arêtes backfillées 42.1 (`manager` ⇔ prof SQL, `member` sinon).
- **Action** : sauvegarder la liste des membres sans changement.
- **Attendu** : ZÉRO `removeMember`, ZÉRO `addMember` — cibles STRICTEMENT identiques à l'ancienne partition `isProf()` (le diff idempotent `syncAdGroupMembersByUserIds` est réutilisé tel quel, retraits bornés aux DN connus SQL). Une résolution de rôle buggée arracherait des profs d'`Equipe_X` sur les 75 établissements → perte rwx immédiate : ce scénario est NON NÉGOCIABLE avant tout déploiement.

### Scénario 16.6 — L'arête PRIME sur le rôle global (D7, changement assumé)
- **Préparation** : `bob` a `users.role='prof'` mais une arête `member` sur `3A`.
- **Action** : projection (save de l'edit-form).
- **Attendu** : bob est projeté dans `Classe_3A` (l'ARÊTE est la source de vérité — plus l'heuristique globale). Un changement prof↔élève sur la fiche user ne rebascule PLUS le membre tant que son arête n'a pas été réalignée (read-back — qui dérive encore de `users.role` jusqu'à 42.4 — ou édition 42.3). Rôle d'arête HORS vocabulaire (donnée sale) : fallback dérivé + `Log::warning`, jamais d'exception.

> **Couverture automatisée.** `tests/Unit/Services/UserGroupServiceLegacyCompatibilityTest.php` : `it_routes_members_to_buckets_by_edge_role` (16.1), `it_reprojects_group_to_ad_when_edge_role_changes` + `it_suspends_ad_resync_observer_during_syncFromAd` (16.2), `it_demotes_unchecked_owner_to_manager_at_projection` (16.3), `it_tolerates_missing_pp_group_in_ad` (16.4), `it_does_not_remove_legitimate_se4_members_on_brownfield_projection` (16.5), `it_projects_by_edge_role_even_when_global_role_disagrees` + `it_falls_back_to_derived_role_on_invalid_edge_role` (16.6), `it_derives_default_role_for_payload_member_without_edge` (D2.3), tests 4.12/4.15 adaptés (fixtures arêtes, D7), miroir 42.1 réécrit rôle-seul (`it_writes_role_on_pivot_read_back`). `tests/Feature/Observers/UserGroupUserPivotObserverTest.php` : ancrage `updated` (resync classe/equipe, filtre wasChanged('role'), suspension double flag, non-classe ignoré, fail-soft). Non-régression : filtre AC12 complet (180 tests).

### Checklist rapide Section 16
- [ ] `migrate:status` /vm : migration 42.1 `Ran` AVANT tout e2e (aucune migration 42.2)
- [ ] Routage : manager∪owner → `Equipe_`, member → `Classe_`, owner → `PP_` (3 cibles toujours synchronisées)
- [ ] `grep is_head_teacher app/` : plus AUCUN lecteur vivant (vestiges D5 : pivot, UserGroup withPivot, MergeLegacy, Backfill, migrations)
- [ ] Changement de rôle d'arête → reprojection AD automatique du seul groupe concerné
- [ ] AUCUNE reprojection pendant `syncFromAd` (flag dédié) ; synchro FS ShareService toujours active au read-back
- [ ] Ex-PP décoché : sort de `PP_`, reste dans `Equipe_`
- [ ] `PP_X` absent AD : save OK, pas d'exception
- [ ] Brownfield : AUCUN retrait de membre légitime d'`Equipe_X` pré-peuplé SE4
- [ ] Stats `head_teacher_updated` (clé publique) inchangée dans les retours `syncFromAd`
