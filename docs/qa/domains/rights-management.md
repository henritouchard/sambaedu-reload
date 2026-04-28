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

**Non-régressions**
- [ ] Drawer Rôles + Permissions
- [ ] `/parc` admin visibilité totale
- [ ] Actions power / wallpaper / schedules
- [ ] ~30 `@can` Blade spot-check
