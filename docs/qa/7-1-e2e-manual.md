# QA manuel E2E — Story 7.1 (Attribution de droits délégués)

**Contexte** : à jouer sur la VM une fois la migration `delegation_history` appliquée. Toutes les actions visibles dans `/rights-management` et `/app/users` (drawer).

**Prérequis** :
- VM Laravel accessible (http ou lab1.sambaedu.net).
- Migration exécutée : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "cd /var/www/sambaedu-reload && php artisan migrate"`.
- Connexion admin (rôle `admin`).
- Au moins 2 WorkstationGroups physiques distincts (salles A et B) avec 2+ machines chacune.
- Un utilisateur cible `enseignant.test` de rôle `Prof` sans délégation initiale.

---

## Scénario 1 — Grant simple

1. Se connecter en admin.
2. Aller sur `/app/users`.
3. Cocher `enseignant.test` dans la liste, cliquer sur "Gérer les droits".
4. Dans le drawer → onglet "Délégations".
5. Sélectionner la salle A dans le select.
6. Cocher `computer.view` et `computer.control`.
7. Cliquer "Accorder 2 délégation(s)".

**Attendu** :
- Toast vert "2 délégation(s) sur 'salle-A' accordée(s) à 1 utilisateur(s).".
- Le drawer **ne se ferme pas** (AC7).
- Sur `/rights-management` → onglet "Délégations actives" : 2 lignes avec badges verts "Accordée".
- Onglet "Historique" : 2 entrées `grant` récentes (acteur = admin, cible = enseignant.test).

## Scénario 2 — Grant multi-users en batch

1. Sur `/app/users`, sélectionner 3 utilisateurs (enseignant.test + 2 autres).
2. Ouvrir le drawer, onglet Délégations.
3. Salle A, permission `wpkg.assign`. Cliquer Accorder.

**Attendu** :
- Toast "1 délégation(s) sur 'salle-A' accordée(s) à 3 utilisateur(s).".
- `/rights-management` → "Délégations actives" : 3 nouvelles lignes.
- Historique : 3 entrées `grant` distinctes (target_user_id différent).

## Scénario 3 — Grant négative (exclusion)

1. Avant ce scénario, donner via seed ou directement `computer.view` global à `enseignant.test` (pour avoir un cas où il a accès global mais on veut l'exclure d'une salle précise).
2. Ouvrir le drawer pour `enseignant.test`, onglet Délégations.
3. **Cocher le toggle "Marquer comme exclusion (négative)"**.
4. Vérifier que le toggle "Retirer les délégations" se décoche automatiquement.
5. Sélectionner salle B, permission `computer.view`.
6. Cliquer "Exclure 1 délégation(s)".

**Attendu** :
- Toast vert "1 délégation(s) sur 'salle-B' marquée(s) en exclusion sur 1 utilisateur(s).".
- `/rights-management` → onglet "Délégations actives" : nouvelle ligne avec badge rouge "Exclusion" sur salle-B.
- Historique : entrée `negate` avec `is_negative=true`.
- Test fonctionnel : se connecter en `enseignant.test`, aller sur `/parc` → voit toutes les salles SAUF B (ou B non-cliquable selon droit global restant). Essayer `/parc/groups/{idB}` → redirection `/parc` + toast "Vous n'avez pas accès à cette ressource.".

## Scénario 4 — Révocation unitaire depuis `/rights-management`

1. Sur `/rights-management` → onglet "Délégations actives".
2. Sur la ligne `enseignant.test` / salle-A / `computer.view`, cliquer corbeille.
3. Confirmer le `wire:confirm`.

**Attendu** :
- Toast vert "Délégation computer.view révoquée sur salle-A".
- Le tableau se rafraîchit sans rechargement complet (Livewire).
- La ligne a disparu.
- Historique : nouvelle entrée `revoke` (acteur = admin).

## Scénario 5 — User délégué voit son périmètre uniquement

1. Se connecter en `enseignant.test` (après grant salle-A + retirer éventuelles négatives).
2. Aller sur `/parc`.

**Attendu** :
- La liste des groupes **ne montre QUE** la salle-A (et aucune autre).
- La liste des machines ne montre que les machines appartenant à la salle-A.
- Aucune référence à la salle-B visible (ni dans les filtres, ni dans les listings).

## Scénario 6 — Blocage d'accès direct URL hors périmètre

1. Toujours connecté en `enseignant.test`.
2. Taper directement `/parc/groups/{idB}` dans la barre d'URL.

**Attendu** :
- Redirection silencieuse vers `/parc` (pas de 403 explicite, AC3).
- Toast rouge "Vous n'avez pas accès à cette ressource." au chargement de la page d'atterrissage.
- Log applicatif côté serveur : `[GroupShow] Accès refusé hors périmètre` avec user, group_id, URL.

## Scénario 7 — Filtres de l'onglet Historique

1. Reconnexion admin, aller sur `/rights-management` → onglet "Historique".
2. Vérifier que toutes les entrées récentes des scénarios 1-4 sont visibles.
3. Filtrer par action `revoke` → ne reste que les lignes revoke.
4. Filtrer par user cible "enseignant.test" → ne reste que les lignes concernant ce user.
5. Ajouter une plage de dates → filtre appliqué.
6. Cliquer "Effacer" → tous les filtres se réinitialisent, pagination revient à la page 1.

**Attendu** :
- Les filtres sont combinables.
- La pagination respecte les filtres (badge count en bas).

---

## Non-régressions à vérifier

- Le drawer "Gérer les droits" fonctionne toujours sur les onglets Rôles et Permissions (avant ces changements).
- `/parc` admin : voit toujours toutes les salles et machines.
- Les actions power (wake/shutdown) depuis `/parc/groups/{id}` fonctionnent pour un admin.
- Les actions wallpaper et schedules restent accessibles (via la nouvelle gate `manage-workstationGroup`).

## Follow-ups connus (hors Story 7.1)

- Page 403 dédiée : implémentée uniquement si un chemin non-Livewire (ex: controller API) est exposé hors périmètre. Aujourd'hui tous les accès ressources sensibles passent par Livewire.
- Middleware `sambaedu.can:permission` : Story 7.2.
- Cache Spatie : Story 7.2.
- Migration bitmask → Spatie en prod : Story 7.3.
