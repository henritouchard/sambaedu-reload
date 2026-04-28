# QA manuel E2E — Story 7.1 (Attribution de droits délégués)

**Contexte** : à jouer sur la VM une fois la migration `delegation_history` appliquée. Toutes les actions visibles dans `/rights-management` et `/app/users` (drawer).

**Prérequis** :
- VM Laravel accessible (http ou lab1.sambaedu.net).
- Migration exécutée : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "cd /var/www/sambaedu-reload && php artisan migrate"`.
- Connexion admin (rôle `admin`).
- Au moins 2 WorkstationGroups physiques distincts (salles A et B) avec 2+ machines chacune.
- Un utilisateur cible `enseignant.test` de rôle `Prof` sans délégation initiale.

---

## Scénario 1 — Grant auto sur état vide (UX état→action)

1. Se connecter en admin.
2. Aller sur `/app/users`, cocher `enseignant.test`, cliquer "Gérer les droits".
3. Dans le drawer → onglet "Délégations".
4. **Choisir salle A** dans le premier select.
5. **Choisir `computer.view`** dans le second select.
6. Observer le **tableau d'état** : colonne "Utilisateur" = `enseignant.test`, "Origine" = "Aucun droit", "État" = badge gris "Aucun", "Action Auto" = badge bleu "Accorder".
7. Cliquer sur le bouton "Appliquer l'action suggérée".

**Attendu** :
- Toast vert "Sur 'salle-A' (computer.view) : 1 accordée(s).".
- Le drawer **ne se ferme pas** (AC7).
- Recharger/ouvrir le drawer → l'état passe à "Délégation sur cette salle / Autorisé / Révoquer".
- `/rights-management` → onglet "Délégations actives" : 1 ligne avec badge vert "Accordée".
- Onglet "Historique" : 1 entrée `grant` récente (acteur = admin, cible = enseignant.test).

## Scénario 2 — Batch multi-user hétérogène

**Préparation** :
- `user.A` = pas de droit sur salle A / `computer.view`.
- `user.B` = déjà une délégation positive sur salle A / `computer.view`.
- `user.C` = permission globale `computer.view` (rôle ou perm directe).
- `user.D` = exclusion active sur salle A / `computer.view`.

1. Sur `/app/users`, sélectionner les 4 users, ouvrir le drawer, onglet Délégations.
2. Choisir salle A + permission `computer.view`.
3. Observer le tableau d'état :
   - `user.A` → "Aucun droit" / "Accorder"
   - `user.B` → "Délégation sur cette salle" / "Révoquer"
   - `user.C` → "Permission globale directe" ou "Permission via rôle" / "Exclure"
   - `user.D` → "Exclusion scopée" / "Lever l'exclusion"
4. Cliquer "Appliquer l'action suggérée".

**Attendu** :
- Toast vert avec synthèse du style "Sur 'salle-A' (computer.view) : 1 accordée(s), 1 révoquée(s), 1 exclusion(s) créée(s), 1 exclusion(s) levée(s).".
- Historique : 4 entrées distinctes, chacune avec la bonne `action` et `is_negative`.
- Rouvrir le drawer sur les mêmes 4 users → l'état reflète la nouvelle situation.

## Scénario 3 — Exclusion forcée sur user ayant droit global

1. Donner à `enseignant.test` la permission globale `computer.view` (rôle prof ou perm directe).
2. Ouvrir le drawer pour `enseignant.test`, onglet Délégations.
3. Choisir salle B + `computer.view`.
4. Vérifier dans le tableau : "Origine" = "Permission globale directe" (ou "via rôle"), "Action Auto" = "Exclure".
5. Cliquer "Appliquer l'action suggérée".

**Attendu** :
- Toast vert "Sur 'salle-B' (computer.view) : 1 exclusion(s) créée(s).".
- `/rights-management` → nouvelle ligne avec badge rouge "Exclusion" sur salle-B.
- Historique : entrée `negate` avec `is_negative=true`.
- Test fonctionnel : se connecter en `enseignant.test`, aller sur `/parc` → ne voit pas salle B. `/parc/groups/{idB}` → redirection `/parc` + toast "Vous n'avez pas accès à cette ressource.".

## Scénario 4 — Lever une exclusion (restauration du droit global)

1. Utiliser `enseignant.test` avec la config posée au scénario 3 (exclusion active sur salle B).
2. Ouvrir le drawer, choisir salle B + `computer.view`.
3. Vérifier dans le tableau : "Origine" = "Exclusion scopée", "État" = "Bloqué" (rouge), "Action Auto" = "Lever l'exclusion".
4. Cliquer "Appliquer l'action suggérée".

**Attendu** :
- Toast vert "Sur 'salle-B' (computer.view) : 1 exclusion(s) levée(s).".
- `/rights-management` : la ligne d'exclusion a disparu.
- Historique : entrée `revoke` avec `is_negative=true`.
- Test fonctionnel : `enseignant.test` voit de nouveau salle B dans `/parc` (le droit global reprend).

## Scénario 4.bis — Option avancée "Forcer une action"

1. Sélectionner `enseignant.test` (état : positive active sur salle A / `computer.view`).
2. Ouvrir le drawer, choisir salle A + `computer.view`. "Action Auto" = "Révoquer".
3. **Déplier "Options avancées"** → sélectionner "Exclure (toujours)".
4. Observer que le libellé du bouton devient "Appliquer : Exclure".
5. Cliquer.

**Attendu** :
- Toast vert "Sur 'salle-A' (computer.view) : 1 exclusion(s) créée(s).".
- En base : la positive existe **toujours** + une nouvelle négative est créée (l'exclusion scopée prévaut sur la positive scopée → user bloqué en effet).
- Historique : entrée `negate` (pas de revoke parallèle).

## Scénario 4.ter — Révocation unitaire depuis `/rights-management`

1. Sur `/rights-management` → onglet "Délégations actives".
2. Sur une ligne `enseignant.test` / salle-A / `computer.view`, cliquer corbeille.
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
