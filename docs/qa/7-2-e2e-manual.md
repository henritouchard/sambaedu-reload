# QA manuel — Story 7.2 — Calcul et Application des Droits Spatie

**Contexte** : scénarios E2E à exécuter sur la VM SER après livraison pour
valider le comportement production.

**Pré-requis** :

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations appliquées (aucune nouvelle migration en 7.2 — seulement du
  re-seed non-destructif)
- `php artisan db:seed --class=PermissionSeeder`
- `php artisan permission:cache-reset`

## Scénario 1 — CRUD profil custom

1. Se connecter en SuperAdmin (`admin`).
2. Naviguer vers `/app/rights-management`, onglet "Profils".
3. Menu **Actions** (haut-droite) → **Nouveau profil** → la page
   `/app/rights-management/profiles/new` s'ouvre.
4. Nom = "Animateur CDI", cocher : `computer.view`, `computer.control`,
   `user.read`. Cliquer **Créer le profil**.
5. Redirigé vers `/app/rights-management/profiles/Animateur CDI`,
   confirmation "Profil créé". Retour onglet Profils → ligne apparue avec
   badge `custom` + "3 permissions".
6. Assigner le profil à un user test :
   - Aller sur `/app/users`, cocher la ligne du user test.
   - Cliquer **Actions** → **Gérer les droits** → drawer s'ouvre, onglet
     **Rôles**.
   - Vérifier que "Animateur CDI" apparaît dans la liste avec le badge
     `custom` (sinon → bug, le drawer ne lit plus tous les rôles Spatie).
   - Sélectionner "Animateur CDI", cliquer **Assigner le rôle**.
7. Vérifier : l'user peut consulter `/app/parc` (si scoping via délégation
   autorise).
8. Retourner sur `/app/rights-management`, onglet Profils, cocher la ligne
   "Animateur CDI" et menu **Actions** → **Supprimer la sélection** →
   toast error "1 profil(s) ignoré(s) car portés par des utilisateurs."
9. Retirer le rôle du user (drawer côté `/app/users` → **Retirer le rôle**)
   → revenir onglet Profils, re-cocher et re-cliquer **Supprimer la
   sélection** → succès.

## Scénario 2 — Seed idempotent

> **Note** : les profils seedés sont volontairement non-modifiables via l'UI
> (rights-management → Profils). Ce scénario se vérifie donc en base directe
> via `tinker`, et les stats du seeder sont émises via `Log::info` (et
> retournées par `run()`) — il n'y a pas de sortie console formattée par
> défaut. On lit `storage/logs/laravel.log` après chaque run.
>
> Pour forcer la resynchro des rôles seedés existants, le flag CLI `--force`
> de `db:seed` n'est PAS propagé à `PermissionSeeder::run(bool $force)` ; il
> faut appeler le seeder directement via tinker (cf. étape 5).

1. Lancer le seed et lire le log :

   ```bash
   ssh root@192.168.122.50 'cd /var/www/sambaedu-reload \
     && php artisan db:seed --class=PermissionSeeder \
     && tail -n 20 storage/logs/laravel.log | grep PermissionSeeder'
   ```

   Première fois sur une base vierge : la ligne de log contient
   `permissions_created: 19, roles_seeded_new: 9, roles_seeded_preserved: 0,
   roles_custom_preserved: 0`.

2. Simuler une dérive (impossible via UI, donc via tinker) : retirer
   `computer.control` du rôle `computer-admin`.

   ```bash
   ssh root@192.168.122.50 'cd /var/www/sambaedu-reload && php artisan tinker --execute="
     \$r = Spatie\Permission\Models\Role::where(\"name\", \"computer-admin\")->firstOrFail();
     \$r->revokePermissionTo(\"computer.control\");
     echo \$r->permissions->pluck(\"name\")->implode(\",\");
   "'
   ```

3. Relancer le seed et lire le log → `permissions_created: 0,
   roles_seeded_new: 0, roles_seeded_preserved: 9` (la dérive est préservée).

4. Vérifier en base que `computer-admin` n'a toujours pas `computer.control`.

5. Relancer en mode forcé (resync des seedés selon enum) :

   ```bash
   ssh root@192.168.122.50 'cd /var/www/sambaedu-reload && php artisan tinker --execute="
     print_r(app(Database\Seeders\PermissionSeeder::class)->run(true));
   "'
   ```

   Le retour doit afficher `roles_seeded_synced_forced: 9` et
   `computer-admin` retrouve `computer.control`.

## Scénario 3 — Sync AD non-destructif

1. `/admin/sync-from-ad` → étape 8 "Rapatrier les profils LDAP custom".
2. Si un profil LDAP custom "Animateur CDI" existe côté AD avec
   `info=0x302`, il doit être rapatrié côté SER avec 3 permissions.
3. Modifier ensuite les permissions côté SER (UI onglet Profils).
4. Relancer la sync → stats `custom_unchanged` s'incrémente, les
   permissions SER ne sont PAS écrasées.

## Scénario 4 — Révocation de rôle effective à la requête suivante

1. Ouvrir 2 sessions (Firefox + Chromium par ex.) avec 2 users :
   - Admin qui va modifier.
   - `alice` avec rôle `prof`.
2. Côté alice : visiter `/app/users` → voir la liste.
3. Côté admin : drawer → retirer le rôle `prof` à alice.
4. Côté alice : refresh `/app/users` → 403 (ou layout masqué).
5. Pas de déconnexion requise.

## Scénario 5 — Accès direct URL → 403

En tant qu'élève (sans droit `user.modify`), taper `/app/users/new` dans
la barre d'adresse → 403. Le log Laravel contient une entrée warning.

Idem pour `/admin/sync-from-ad` sans `server.admin`.

## Scénario 6 — Scoping classe Prof

1. Créer 2 classes via l'UI : `3A` et `3B`.
2. User `profA` avec rôle `prof`, rattaché à classe `3A`.
3. User `eleveA` dans `3A`, user `eleveB` dans `3B`.
4. Se connecter profA → `/app/users/eleveA` → OK.
5. `/app/users/eleveB` → 403 (scoping classe).
6. Tester le bouton "Réinitialiser le mot de passe" → visible pour
   `eleveA`, masqué pour `eleveB`.

## Scénario 7 — Cache Spatie effectif

1. Admin édite les permissions du rôle `technicien` via onglet Profils
   (ajouter `user.read`).
2. Un technicien reload une page → il voit désormais ses permissions à
   jour immédiatement (pas de délai 24h).
3. Si doute, `ssh root@VM 'cd /var/www/sambaedu-reload && php artisan permission:cache-reset'`.

## Scénario 8 — Performance (pas de N+1)

Avec debugbar activé, charger `/app/users` avec ≥ 50 users.

- La barre doit afficher un count de queries stable (ne scale pas
  linéairement avec le nombre de users).
- Si N+1 détecté sur `@can(...)` ligne par ligne, revoir le
  préchargement avec `->with('roles.permissions')` dans le composant.
