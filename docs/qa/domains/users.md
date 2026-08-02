# QA manuel — Domaine Users (Gestion des Utilisateurs)

> Runbook E2E pour les stories du domaine Users. Append-only : chaque
> story ajoute une section avec ses scénarios numérotés stables.

**Pré-requis** :

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Cache Spatie reset si évolutions permission : `php artisan permission:cache-reset`
- Compte `admin` (server.admin) disponible pour les actions critiques.
- Cron `users:sync-from-ad` fonctionnel (toutes les 5 min) pour le backfill
  `password_changed_at`.

---

## Story 14.4 — Filtres Audit « Quota dépassé » et « Mot de passe par défaut »

**Date livraison** : 2026-05-21
**Migrations à appliquer** : `2026_05_21_100000_add_password_changed_at_to_users_table`

**Contexte** : Deux filtres natifs ajoutés dans la modale de filtres `/app/users`
(section « Audit »), remplaçant les pages legacy `quota_visu.php` /
`repquota.php` / `infomdp.php`. Aucun probe shell (ni `smbclient`, ni
`xfs_quota`) — tout passe par la colonne SQL `quota_snapshot` (story 5.1b) et
la nouvelle colonne `password_changed_at` (synchronisée depuis AD).

**Note fenêtre transitoire** (D6 / R3) : Après le premier déploiement, les
comptes existants auront `password_changed_at = NULL` jusqu'au prochain run du
cron `users:sync-from-ad` (~5 min). Pendant cette fenêtre, le filtre « Mot de
passe par défaut » affiche tous les utilisateurs (faux positifs transitoires).
Le compteur baisse au fil des syncs AD. Comportement attendu et documenté.

### Scénario 14.4-1 — Filtre « Quota dépassé » : user avec quota home dépassé visible

**Pré-requis** : La commande `quota:snapshot` a tourné au moins une fois depuis
le dernier déploiement. Un utilisateur (ex : `jdupont`) a `quota_snapshot.home.is_over_soft = true`.

1. Se connecter en `admin` (server.admin).
2. Naviguer vers `/app/users`.
3. Cliquer sur le bouton « Filtres » → la modale s'ouvre.
4. Faire défiler jusqu'à la section « Audit » (icône bouclier).
5. Activer le toggle « Quota dépassé ».
6. La modale se ferme ou cliquer « Appliquer ».
7. Vérifier que `jdupont` apparaît dans la liste.
8. Vérifier qu'un chip « audit: quota dépassé » est affiché sous la barre de
   recherche.
9. Vérifier que les utilisateurs dont `quota_snapshot IS NULL` N'apparaissent
   PAS (D2 — exclus).
10. Vérifier qu'aucun appel `xfs_quota` / `smbclient` n'est déclenché
    (journaux `/var/log/sambaedu/*.log` restent silencieux).

### Scénario 14.4-2 — Filtre « Mot de passe par défaut » : user avec password_changed_at NULL visible

**Pré-requis** : Un utilisateur (ex : `tdurand`) a `password_changed_at = NULL`
(jamais changé ou sync AD pas encore passé).

1. Se connecter en `admin` (server.admin).
2. Naviguer vers `/app/users`.
3. Cliquer « Filtres » → section « Audit » → activer « Mot de passe par défaut ».
4. Appliquer.
5. Vérifier que `tdurand` apparaît dans la liste.
6. Vérifier qu'un utilisateur avec `password_changed_at = '2026-01-01 00:00:00'`
   N'apparaît PAS.
7. Vérifier le chip « audit: mdp par défaut » dans la barre de chips.
8. Cliquer sur le chip → le filtre est retiré, la liste revient en mode non
   filtré.

### Scénario 14.4-3 — Combinaison des deux filtres (AND strict, D8)

**Pré-requis** :
- `u1` : quota dépassé + `password_changed_at = NULL`
- `u2` : quota dépassé + `password_changed_at` défini
- `u3` : quota OK + `password_changed_at = NULL`

1. Activer les deux filtres simultanément (« Quota dépassé » ET « Mot de passe
   par défaut »).
2. Appliquer.
3. Vérifier que seul `u1` apparaît.
4. Vérifier que `u2` et `u3` sont absents.
5. Vérifier que le compteur de résultats correspond.
6. Vérifier que les deux chips sont affichés simultanément.
7. Cliquer « Tout effacer » → les deux filtres sont réinitialisés, la liste
   revient en affichage complet.

### Scénario 14.4-4 — Synchro password_changed_at au login

**Pré-requis** : Un compte test avec `pwdLastSet > 0` dans l'AD (non nul).

1. Vérifier en BDD avant login : `SELECT password_changed_at FROM users WHERE login='testuser'`
   → valeur quelconque (peut être NULL si premier login).
2. Se connecter avec `testuser` sur `/app/login`.
3. Vérifier en BDD après login :
   `SELECT password_changed_at FROM users WHERE login='testuser'` → date non
   nulle correspondant à la conversion AD-FILETIME du `pwdLastSet` de cet
   utilisateur.
4. Répéter avec un compte dont `pwdLastSet = 0` (mdp doit être changé) →
   `password_changed_at` doit rester NULL après le login forcé (retour -1).

### Scénario 14.4-5 — Backfill via cron users:sync-from-ad

**Pré-requis** : Plusieurs comptes avec `password_changed_at = NULL` en BDD.

1. Vérifier le count avant sync :
   `SELECT COUNT(*) FROM users WHERE password_changed_at IS NULL`.
2. Lancer manuellement : `php artisan users:sync-from-ad`.
3. Vérifier après sync : le count de NULL a diminué (correspondant aux users
   dont `pwdLastSet > 0` en AD).
4. Les users avec `pwdLastSet = 0` en AD gardent `password_changed_at = NULL`
   (cohérent D7).

### Scénario 14.4-6 — Non-régression scoping Prof (AC7)

**Pré-requis** : Un compte `prof.test` scopé sur la classe `Classe_3emeA` via
le groupe `Equipe_3emeA`.

1. Se connecter en `prof.test`.
2. Naviguer vers `/app/users`.
3. Activer le filtre « Quota dépassé ».
4. Vérifier que seuls les élèves de `Classe_3emeA` en dépassement de quota
   sont affichés (scoping classe toujours appliqué — AC7).
5. Vérifier qu'aucun utilisateur hors-classe ne s'affiche même avec le filtre.

### Scénario 14.4-7 — Filtre quota dépassé + reset selection bulk (post-review #3)

**Pré-requis** : Plusieurs utilisateurs en dépassement de quota.

1. Naviguer vers `/app/users` (sans filtre actif).
2. Cocher manuellement 3 utilisateurs dans la colonne « Sélection »
   (compteur de sélection à 3).
3. Ouvrir les filtres → activer « Quota dépassé ».
4. **Vérifier** : la sélection passe immédiatement à 0 (les 3 cases sont
   décochées, le panneau d'actions bulk se ferme s'il était ouvert).
5. Sélectionner 2 nouveaux users dans la liste filtrée.
6. Cliquer sur le chip « audit: quota dépassé » pour le retirer.
7. **Vérifier** : la sélection repasse à 0 (reset au retrait du chip).
8. Idem en activant/désactivant « Mot de passe par défaut ».
9. Idem en cliquant « Tout effacer » sur la barre de chips → reset complet
   (filtres + sélection).

> **But** : éviter qu'une bulk action (reset password, désactivation) soit
> exécutée sur des logins qui ne sont plus visibles dans la liste filtrée.

### Scénario 14.4-8 — Fenêtre transitoire post-deploy : mdp par défaut (post-review #8 / R3 affinée)

**Contexte** : Immédiatement après le déploiement de 14.4 (premier `php artisan migrate`), la colonne
`password_changed_at` est NULL pour TOUS les comptes existants. Le filtre
« Mot de passe par défaut » remontera donc l'intégralité de l'annuaire pendant
une fenêtre transitoire de ~5 min à 2h selon le rythme de sync AD.

**Pré-requis** : Déploiement frais ou environnement reseté avec
`UPDATE users SET password_changed_at = NULL;`.

1. Immédiatement après deploy, naviguer vers `/app/users` (`admin`).
2. Activer le filtre « Mot de passe par défaut ».
3. **Vérifier** : la liste affiche TOUS les utilisateurs (compteur ≈ taille
   annuaire).
4. **Communication équipe** : avertir oralement / sur Mattermost les admins
   d'attendre **1 cycle complet de cron `users:sync-from-ad`** (~5 min) avant
   d'utiliser le filtre pour des actions bulk.
5. Attendre que `php artisan schedule:list` ait fait passer le job
   `users:sync-from-ad` au moins 1 fois.
6. Optionnel : forcer manuellement `php artisan users:sync-from-ad` pour
   accélérer.
7. **Vérifier post-sync** : le compteur baisse à un niveau réaliste
   (typiquement < 5% du total — uniquement les comptes avec `pwdLastSet == 0`
   en AD ou n'ayant jamais été synchronisés via 14.4).
8. Si après 2h le compteur reste anormalement haut → vérifier que le cron AD
   tourne effectivement (logs `/var/log/sambaedu/users-sync-from-ad.log`).

> **Décision utilisateur 2026-05-21** : pas de commande `users:backfill-pwdlastset`
> dédiée. La fenêtre transitoire est acceptée et documentée. Évolution UI
> ultérieure possible si gênant : toast d'avertissement contextuel.

### Scénario 14.4-9 — Mesure perf filtres audit (post-review #10)

**Pré-requis** : Environnement Postgres staging avec 50k+ users représentatifs.

1. Activer les deux filtres simultanément (`quotaOverflow=true` ET
   `passwordDefault=true`).
2. Capturer la requête SQL générée (Laravel Debugbar ou `DB::enableQueryLog`).
3. Exécuter manuellement sur la console Postgres :
   ```sql
   EXPLAIN ANALYZE
   SELECT users.* FROM users
   WHERE (
     quota_snapshot->'home'->>'is_over_soft' = 'true'
     OR quota_snapshot->'home'->>'is_over_hard' = 'true'
     OR quota_snapshot->'sambaedu'->>'is_over_soft' = 'true'
     OR quota_snapshot->'sambaedu'->>'is_over_hard' = 'true'
   )
   AND password_changed_at IS NULL;
   ```
4. **Vérifier** : `Execution Time` < 500ms.
5. **Si > 500ms** : ouvrir une story de suivi `users-pwd-index-partial` pour
   ajouter `CREATE INDEX users_pwd_null_idx ON users (id) WHERE password_changed_at IS NULL;`
   et éventuellement un index GIN sur `quota_snapshot`.

---

## Checklist rapide — Story 14.4

- [ ] Scénario 14.4-1 : filtre quota OK
- [ ] Scénario 14.4-2 : filtre mdp par défaut OK
- [ ] Scénario 14.4-3 : combinaison AND strict OK
- [ ] Scénario 14.4-4 : synchro au login OK
- [ ] Scénario 14.4-5 : backfill cron OK
- [ ] Scénario 14.4-6 : scoping Prof non régressé
- [ ] Scénario 14.4-7 : reset selection bulk (post-review #3)
- [ ] Scénario 14.4-8 : fenêtre transitoire post-deploy documentée et communiquée
- [ ] Scénario 14.4-9 : perf filtres audit < 500ms (sinon story de suivi)
- [ ] Aucun shellout journalisé (nginx/php-fpm logs propres)
- [ ] Migration rollback propre :
      `php artisan migrate:rollback --step=1` → colonne `password_changed_at`
      supprimée sans erreur

---

## Post-correctifs & non-régressions — Story 14.4 (2026-05-21)

Après la review Opus du 2026-05-21, 9 correctifs ont été appliqués. Tableau
de suivi des bugs détectés en review et des scénarios de non-régression
dédiés à exécuter à chaque évolution touchant le pipeline `pwdLastSet`
ou la query des filtres audit.

| Bug review | Sévérité | Symptôme | Correction | Non-régression |
|------------|----------|----------|------------|----------------|
| #1 | 🔴 Critique | Carbon (LdapRecord auto-cast) → `password_changed_at` NULL silencieux (D7 cas 4 cassé) → user remonte à tort dans « mdp par défaut » | `ResolvesPwdLastSet::resolvePwdLastSetRaw(Carbon)` → -1 (pipeline `-1 → now()` D7 cas 3, perte de précision assumée) + `Log::warning` sur garde-fou FILETIME hors plage | Tests unit `ResolvesPwdLastSetTest::it_returns_minus_one_for_carbon_*` + e2e `AuthenticationServicePasswordChangedAtTest::it_sets_approx_now_when_pwdLastSet_is_a_carbon_instance` + `UserSyncServicePasswordChangedAtTest::ldap_user_to_ad_data_handles_carbon_pwdlastset_as_now`. Scénario QA recommandé : capturer un compte AD dont le pwdLastSet est auto-casté Carbon par LdapRecord (rare mais documenté), se reloguer, vérifier `password_changed_at` non-NULL en BDD |
| #2 | 🔴 Critique | `UserSyncService::upsertUser` branche `update` écrasait `password_changed_at` avec NULL si l'AD ne renvoyait pas l'attribut (ACL, replica filtré) → avalait la vraie date du login persistée par `AuthenticationService::persistPasswordChangedAt` | `'password_changed_at' => $adUser->passwordChangedAt ?? $user->password_changed_at` sur branche `update` (branche `create` inchangée) | Tests Feature `UserSyncServicePasswordChangedAtTest::upsert_user_keeps_existing_password_changed_at_when_ad_returns_null` + `::upsert_user_overwrites_password_changed_at_when_ad_returns_value`. Scénario QA : 1) user se logue → `password_changed_at` SQL renseigné, 2) forcer `users:sync-from-ad` immédiatement, 3) vérifier que la date SQL n'est pas devenue NULL même si l'AD renvoie une valeur partielle |
| #3 | 🟠 Important | Changement de filtre audit ne reset pas `selectedUsers` → bulk action exécutable sur des logins absents de la liste filtrée | Reset `$this->selectedUsers = []` dans `updatedQuotaOverflow`, `updatedPasswordDefault`, `removeQuotaOverflowFilter`, `removePasswordDefaultFilter`, `resetFilters` | Scénario QA 14.4-7 ci-dessus + 3 tests Livewire dans `UsersIndexPageAuditFiltersTest::test_it_resets_selected_users_when_*` |
| #11 | 🟡 Mineur | `App\Types\User::toArray()` / `fromLivewire()` non aware de `passwordChangedAt` → round-trip Livewire perdrait silencieusement la valeur | Sérialisation ISO8601 `passwordChangedAt?->toIso8601String()` + parsing `Carbon::parse(...)` miroir | Tests unit `tests/Unit/Types/UserTest.php::it_round_trips_password_changed_at_through_livewire` + tests robustesse null/missing key |
| #12 | 🟡 Mineur | Garde-fou FILETIME > 2100 silencieux → user mis « mdp par défaut » sans trace | Commentaire seuil + `Log::warning('ResolvesPwdLastSet: FILETIME hors plage', [...])` | Tests unit `ResolvesPwdLastSetTest::it_logs_warning_when_filetime_*`. Scénario QA : grep `journalctl -u php-fpm` pour les entrées `FILETIME hors plage` après 1 cycle complet de sync AD ; aucune entrée attendue en prod nominale |

---

## Story 26.3 — Pastille « profil itinérant volumineux » (2026-06-15)

Le tableau `/app/users` affiche une pastille pour les comptes dont le profil
itinérant (`/home/profiles/<login>.V<N>`) dépasse le seuil
`RoamingProfileService::LARGE_PROFILE_THRESHOLD_MB` (200 Mo). La valeur provient
**exclusivement** du cache `users.profile_snapshot` (alimenté par le job nocturne
`profiles:snapshot`, cf. domaine **filesystem** Story 26.3) — **zéro shellout/`du`
au render** (invariant perf). Le volet purge des orphelins est documenté dans
`filesystem.md` (onglet admin Profils itinérants).

**Pré-requis** : avoir lancé `php artisan profiles:snapshot` au moins une fois
après avoir peuplé `/home/profiles` (cf. Scénario filesystem 26.3-1).

### Scénario 26.3-U1 — Pastille affichée au-delà du seuil

**Étapes** :

1. Garantir un compte `qa263` avec `profile_snapshot.size_mb` ≥ 200 (via snapshot).
2. Ouvrir `/app/users`, rechercher `qa263`.
3. **Attendu** : colonne « Utilisation » → badge `badge-warning` « <taille> Mo » avec tooltip « Profil itinérant volumineux (… Mo, seuil 200 Mo) ». Test : `UsersIndexPageQuotaColumnTest::test_it_shows_large_profile_badge_above_threshold`.

### Scénario 26.3-U2 — Pas de pastille sous le seuil / sans snapshot

**Étapes** :

1. Compte avec `size_mb < 200`, ou compte sans `profile_snapshot`.
2. Ouvrir `/app/users`.
3. **Attendu** : **aucune** pastille « profil volumineux » (pas d'erreur, pas de badge). Tests : `test_it_hides_large_profile_badge_below_threshold` + non-régression colonne quota inchangée.

### Checklist rapide — Story 26.3 (users)

- [ ] Scénario 26.3-U1 : pastille volumineux affichée (cache uniquement)
- [ ] Scénario 26.3-U2 : pas de pastille sous seuil / sans snapshot

---

## Story 49.2 — Affichages du rôle : Postgres, plus l'annuaire (2026-08-01)

Le cut-over runtime de l'Epic 49 touche deux affichages de ce domaine. Les
parcours de session, de droits d'administration et de blocage des élèves sont
documentés dans **`auth.md` § Story 49.2** (jamais de fichier QA par story).

**Ce qui change** : les badges « Élève » / « Professeur » de la fiche utilisateur
et la liste des profs éligibles au rôle de professeur principal dérivaient de
prédicats qui interrogeaient l'annuaire « d'abord ». Ils lisent désormais
`users.role`, colonne déjà chargée en mémoire. Aucun changement d'affichage
attendu pour un compte synchronisé — le bénéfice est en coût.

### Scénario 49.2-U1 — Badges de la fiche utilisateur, iso-affichage

**Étapes** :

1. Ouvrir `/app/users/<login>` pour un élève, puis pour un professeur.
2. Comparer avec la tuile « Rôle » de la même page.

**Attendu** :

- Élève → badge bleu « Élève » ; professeur → badge vert « Professeur ».
- Le badge et la tuile « Rôle » sont **cohérents** (ils dérivent maintenant de la
  même valeur normalisée : `prof` et `profs` donnent le même résultat).
- Un compte `administratif` ou `autre` n'affiche **ni** badge Élève **ni** badge
  Professeur — comme avant.

### Scénario 49.2-U2 — Professeur principal : liste instantanée

**Étapes** :

1. Ouvrir une classe `/app/users/groups/<id>`, action « Nommer un professeur
   principal ».
2. Observer le temps d'ouverture de la modale sur une classe de 25-30 élèves.

**Attendu** :

- Seuls les **professeurs** de la classe sont proposés (aucun élève).
- La modale s'ouvre **immédiatement**. Auparavant, ce rendu déclenchait un
  aller-retour annuaire **par membre de la classe** — sur une classe complète,
  l'attente était perceptible.
- Cocher / décocher un PP fonctionne comme avant (projection AD `PP_<classe>`
  inchangée).

### Scénario 49.2-U3 — Modales de droits : plus de compte fabriqué

**Étapes** :

1. `/app/users` → sélection → « Gérer les droits » (ou « Déléguer »).
2. Cibler un login qui n'existe pas en base.

**Attendu** : toast « introuvable — attendez la synchronisation (≤ 5 min) »,
**aucune** ligne `users` créée. Détail et contre-épreuve : `auth.md` § 49.2-13.

### Checklist rapide — Story 49.2 (users)

- [ ] 49.2-U1 : badges Élève/Professeur inchangés et cohérents avec la tuile Rôle
- [ ] 49.2-U2 : liste des profs d'une classe correcte et instantanée
- [ ] 49.2-U3 : aucun compte fabriqué depuis les modales de droits
