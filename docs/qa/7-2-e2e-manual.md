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
3. Cliquer "Nouveau profil".
4. Nom = "Animateur CDI", cocher : `computer.view`, `computer.control`,
   `user.read`. Valider.
5. Vérifier : ligne apparue avec badge `custom` + "3 permissions".
6. Assigner le profil à un user test via le drawer (onglet
   "Droits d'un utilisateur").
7. Vérifier : l'user peut consulter `/app/parc` (si scoping via délégation
   autorise).
8. Retourner onglet Profils, bouton **Supprimer** sur "Animateur CDI" →
   garde-fou toast warning "Retirer d'abord ce rôle des 1 utilisateur(s)".
9. Retirer le rôle du user → retourner et re-cliquer Supprimer → succès.

## Scénario 2 — Seed idempotent

```bash
ssh root@192.168.122.50 'cd /var/www/sambaedu-reload && php artisan db:seed --class=PermissionSeeder'
```

- Première fois : "X permissions créées, Y rôles seedés".
- Modifier à l'UI les permissions du rôle `computer-admin` (retirer
  `computer.control`).
- Relancer le seed → "0 nouveaux, N préservés".
- Vérifier : `computer-admin` n'a plus `computer.control` (préservé).
- Relancer avec `--force` (à adapter) → permissions restaurées selon enum.

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
