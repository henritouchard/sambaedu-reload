# Story 26.3 : Nettoyage natif des profils itinérants — pastille par-user + purge des orphelins

Status: review

<!-- Spin-off de 26.2 (clean_profiles n'est PAS un sujet nomade ; c'est un nettoyage du store /home/profiles per-user). Réimplémentation NATIVE du nettoyage legacy. -->

## Story

En tant qu'**exploitant du parc (server.admin)**,
je veux **voir d'un coup d'œil quels profils itinérants sont volumineux (pastille par utilisateur) et purger en un clic les profils orphelins (dossiers `/home/profiles/<x>` sans compte correspondant)**,
afin de **maîtriser l'occupation disque de `/home/profiles` sans repasser par les pages legacy `ldap_cleaner.php?do=3` (purge orphelins) et `del-roam.sh` (trim per-username), et sans payer le coût d'un `du` à chaque affichage**.

## Contexte (lire en premier)

Aujourd'hui le nettoyage des profils itinérants est **100 % legacy** :

1. **Purge des orphelins** — `clean_profiles('*')` appelé depuis `sambaedu/annu/ldap_cleaner.php?do=3` (« Effacer les homes des comptes orphelins », action admin manuelle, lien legacy). Cible : les dossiers de `/home/profiles/` qui n'ont **plus de compte utilisateur** correspondant.
2. **Trim per-username** — `del-roam.sh` (porté nativement en SE5 via `RoamingProfileService::generatePurgeScript()` + `RoamingProfileController::delRoamScript()`), consommé par le logon-script Windows pour supprimer les sous-dossiers exclus (`ExcludeProfileDirs`) **dans** le profil d'un user à la session. **Ce trim per-session reste tel quel — hors scope 26.3.**

26.3 réimplémente nativement le **volet admin** de ce nettoyage, en UI native :

- **(1) Pastille par-user** dans le tableau `/app/users` = profil itinérant volumineux (au-delà d'un seuil), pour repérer les comptes consommateurs.
- **(2) Bandeau/compteur global** « N profils orphelins » (dossiers `/home/profiles/<x>` **sans** ligne user — donc pas de ligne à badger dans le tableau) **+ bouton « Purger les profils orphelins »** (réimplémentation native de `do=3`).

### CONTRAINTE PERF (Henri, ferme — invariant de conception)

Les valeurs nécessaires (`du` sur `/home/profiles`, scan des sous-dossiers) sont **COÛTEUSES**. Elles **DOIVENT être calculées UNE FOIS PAR JOUR** (job planifié nocturne → cache persistant), et **JAMAIS recalculées à chaque affichage**. L'UI lit **uniquement** le cache. Un affichage du tableau users ou du bandeau orphelins ne déclenche **aucun** `du`, **aucun** scan FS, **aucun** shellout. C'est non négociable.

> Pattern de référence dans le repo : `quota:snapshot` (story 5.1b) qui parse `xfs_quota` une fois la nuit dans `users.quota_snapshot`, et le listing `/users` lit la colonne JSON sans shellout par ligne. **On calque exactement ce modèle.**

## Acceptance Criteria

1. **Job planifié quotidien de calcul des stats profils.** Une commande artisan (ex. `profiles:snapshot`) exécute **une fois par jour** (planifiée dans `App\Console\Kernel::schedule()`, fenêtre nocturne) le scan de `/home/profiles` : taille par dossier (premier niveau) + détection des dossiers sans compte user. Le résultat est **persisté** (cache journalier). **Aucun** `du`/scan FS n'est exécuté hors de ce job.

2. **Pastille par-user volumineux.** Dans le tableau `/app/users` (`resources/views/pages/users/_partials/users-table.blade.php`), chaque ligne user dont le profil itinérant dépasse le seuil affiche une pastille/badge « profil volumineux » (avec la taille). La valeur provient **exclusivement** du cache (AC #1) — résolue par login. Les users sans entrée dans le cache (pas de profil, ou pas encore scanné) n'affichent **rien** (pas d'erreur, pas de badge).

3. **Bandeau global orphelins + compteur.** Un bandeau/section (page users ou onglet admin profils-itinérants) affiche « N profils orphelins » où N = nombre de dossiers de `/home/profiles` **sans** compte user correspondant (résolu contre la table `users` Postgres, NFR7 — **jamais** l'AD). N=0 → bandeau neutre/absent. Les valeurs proviennent du cache (AC #1).

4. **Bouton « Purger les profils orphelins » (réimplémentation native de `do=3`).** Action `server.admin` (double `Gate::allows('server.admin')` à mount ET dans la méthode), confirmation utilisateur (`wire:confirm` ou modale réutilisable). La purge supprime les dossiers orphelins **ré-vérifiés au moment de l'action** (ne pas faire confiance aveuglément à un cache pouvant dater de la veille — re-confirmer l'absence de compte avant tout `rm`). Toast de résultat via `WithToasts` (jamais `$e->getMessage()` exposé). Après purge → rafraîchir le compteur.

5. **Sécurité suppression (anti-désastre).** La purge ne supprime **que** des chemins sous `/home/profiles/` (préfixe vérifié), chaque entrée validée anti path-traversal (réutiliser `RoamingProfileService::isValueSafe()` ou équivalent), `..` rejeté, jamais de glob non borné. Un dossier dont le user **existe** (table `users`) n'est **jamais** supprimé, même s'il figure dans un cache obsolète. Suppression via `trash`/déplacement vers `_Trash_users` privilégié si le legacy l'offre (`do=3&mode_clean=mv`) — sinon `rm -rf` borné au seul dossier orphelin re-vérifié.

6. **Réutilisation, zéro réinvention.** Étendre `RoamingProfileService` (déjà porteur de `getProfileStatsGlobal`, `getExclusions`, `generatePurgeScript`, `isValueSafe`) plutôt que créer un service concurrent. Le calcul `du`/scan vit dans un service + commande ; l'UI n'appelle que des lecteurs de cache. **NE PAS** raviver le canal legacy `ldap_cleaner.php`/`clean_profiles` (kill-switch legacy actif) : on réimplémente, on ne route pas vers le legacy.

7. **Tests (Pest/PHPUnit, SQLite).** Couvrir : (a) la commande snapshot (parsing/scan mocké, persistance), (b) la détection d'orphelins (dossier sans user = orphelin ; dossier avec user = non-orphelin), (c) la garde de sécurité de la purge (refus hors `/home/profiles`, refus `..`, refus si user existe), (d) le gating `server.admin` du composant Livewire (403 sans permission), (e) l'absence de shellout/`du` au rendu (l'UI lit le cache). **Aucun test ne doit dépendre d'un `/home/profiles` réel** — scan/FS mockés (cf. note SQLite : pas d'enforcement varchar, et FS absent en CI).

## Tasks / Subtasks

- [x] **T1 — Service de scan + cache des stats profils** (AC: #1, #5, #6)
  - [x] Étendre `app/Services/RoamingProfileService.php` (décision : extension du service existant, PAS de service concurrent — réutilise `isValueSafe`, logs `[RoamingProfileService]`) avec : scan natif `du --max-depth=1 -b /home/profiles` (`scanProfileSizes()` + `parseDuOutput()` extrait pour testabilité), détection orphelins `detectOrphans()` (résolution `LOWER(login)` Postgres-only NFR7, strip suffixe `.V<N>` via `loginFromProfileDir()`), lecteurs cache `getProfileSizeForLogin()`/`getOrphanProfiles()`/`getOrphanCount()` (aucun FS).
  - [x] Support de cache journalier : **persistance DB** — colonne JSON `users.profile_snapshot` (mirror de `quota_snapshot`, survit au flush cache) pour les tailles par-login ; `SystemSetting` clé `profiles.orphans` pour la liste globale des orphelins (pas de ligne user → stockage séparé). Tranché en Dev Notes.
  - [x] Seuil « volumineux » = constante `RoamingProfileService::LARGE_PROFILE_THRESHOLD_MB` (200 Mo, au-dessus du seuil d'affichage 8 Mo de l'onglet admin). Documenté.

- [x] **T2 — Commande artisan planifiée `profiles:snapshot`** (AC: #1)
  - [x] Créé `app/Console/Commands/ProfilesSnapshotCommand.php` (calque `QuotaSnapshotCommand` : `declare(strict_types=1)`, signature, fail-soft, logs préfixés, exit codes).
  - [x] Persiste stats par-login (colonne) + liste orphelins (SystemSetting) via `RoamingProfileService::persistSnapshot()`.
  - [x] Fail-soft : `scanProfileSizes()` null (`/home/profiles` absent / `du` KO) → `Log::error` + snapshot précédent conservé + exit FAILURE non-fatal.
  - [x] Planifié dans `Console\Kernel::schedule()` à **04:30** (créneau libre après script-logs 04:00), `->withoutOverlapping()->runInBackground()`.

- [x] **T3 — Pastille par-user dans le tableau users** (AC: #2)
  - [x] Page réelle = `resources/views/pages/users/index.blade.php` (le partial `users-table.blade.php` n'est pas le listing actif). Colonne `profile_snapshot` ajoutée au `select()` du computed `users()` (chargée une fois par page, pas de FS).
  - [x] Badge `badge-warning` + tooltip taille dans la cellule « Utilisation », conditionné au seuil. Aucun badge si pas d'entrée cache.

- [x] **T4 — Bandeau orphelins + bouton purge (Livewire)** (AC: #3, #4, #5)
  - [x] Bandeau « N profils orphelins » dans l'onglet admin `profils-itinerants-tab.blade.php` (décision recommandée — page de gestion des profils, déjà gardée `server.admin`). Confirmé en Dev Notes.
  - [x] Compteur `orphanCount` lu depuis le cache (`getOrphanCount()`), aucun FS.
  - [x] Bouton « Purger les profils orphelins » : double `Gate::allows('server.admin')` (mount + `purgeOrphans()`), `wire:confirm`, action re-vérifiant l'absence de compte (service), toast `WithToasts`, refresh compteur après purge.

- [x] **T5 — Action de purge sécurisée** (AC: #4, #5, #6)
  - [x] `purgeOrphanProfiles(): array` : pour chaque orphelin re-confirmé (`detectOrphans` frais), garde `isValueSafe` + refus `/` + `realpath` confiné sous `/home/profiles` → **déplacement** vers `_Trash_users/<dir>.<horodatage>` (réversible, calque `do=3&mode_clean=mv`). Jamais de `rm -rf` ni de fallback silencieux. Logs `[RoamingProfileService]`, jamais de chemin brut dans les toasts.
  - [x] Aucun appel legacy `clean_profiles`/`ldap_cleaner.php` (réimplémentation native).

- [x] **T6 — Tests** (AC: #7)
  - [x] `tests/Unit/Services/RoamingProfileCleanupTest.php` : scan/parse mocké, login extraction, détection orphelins (présent/absent, case-insensitive), persistance, lecteurs cache, gardes purge (`..`, `/`, user existant).
  - [x] `tests/Unit/Console/ProfilesSnapshotCommandTest.php` : persistance + fail-soft scan null.
  - [x] `tests/Feature/Livewire/Admin/ProfilsItinerantsOrphanPurgeTest.php` : bandeau, 403 forgé, purge+refresh, **invariant aucun shellout** (Process::assertNothingRan + stub levant si scan appelé).
  - [x] `tests/Feature/Livewire/Users/UsersIndexPageQuotaColumnTest.php` : pastille volumineux (au-dessus/sous seuil).
  - [x] `tests/Feature/Console/KernelScheduleTest.php` : planification 04:30.

- [x] **T7 — Doc QA par DOMAINE** (AC: tous)
  - [x] `docs/qa/domains/filesystem.md` : section Story 26.3 (scan, orphelins, purge, invariant perf) scénarios 26.3-1→8. `docs/qa/domains/users.md` : pastille par-user 26.3-U1/U2. README « Domaines couverts » mis à jour. Aucun fichier `{epic}-{story}-e2e-manual.md` créé.

## Dev Notes

### Réutilisation impérative (anti-réinvention)

- **`app/Services/RoamingProfileService.php`** porte déjà : `getProfileStatsGlobal()` (parse `/tmp/du.txt` cron → tailles par chemin/user), `parseDuStats()` (privé), `getExclusions()`/`setExclusions()`/`generatePurgeScript()` (GPO `ExcludeProfileDirs` + script `del-roam.sh`), et la **garde sécurité `isValueSafe()` + `VALUE_REGEX`** (anti path-traversal, veto `..`). **Réutiliser `isValueSafe()` pour la purge.** Le decoupling « stats en pur PHP » est déjà fait — ne pas réintroduire d'appel legacy pour les stats.
- **`QuotaSnapshotCommand` (story 5.1b)** = modèle de référence pour T2 : un job nocturne qui calcule une fois et écrit une colonne, l'UI lit la colonne. Calquer fail-soft, logs préfixés, exit codes, planification.
- **`profils-itinerants-tab.blade.php`** = composant Livewire SFC existant `server.admin` (double gate à `mount()` + chaque méthode, toasts `WithToasts`, modale réutilisable `x-molecules.modal`). C'est l'endroit naturel du bandeau orphelins + purge (T4). Il affiche déjà les stats `du` (« mises à jour toutes les nuits par le cron `du.sh` ») → cohérent avec le calcul journalier.
- **`User::findByLogin()` / `LOWER(login)`** pour la résolution login↔dossier (case-insensitive, NFR7 Postgres-only). **Ne JAMAIS** requêter l'AD pour décider qu'un profil est orphelin — la table `users` Postgres est la source domaine.

### Contrainte perf — invariant de conception (Henri)

- `du`/scan = **job nocturne UNIQUEMENT**. L'UI (tableau users, bandeau orphelins, compteur) lit le **cache persistant**. Zéro shellout/FS au render. Un test (AC #7) doit verrouiller cette invariant (pas de `Process`/`du` appelé au render Livewire).
- Le legacy produisait `/tmp/du.txt` via un cron exploitation (`du.sh`). Deux options pour T1/T2, à trancher : (a) **réutiliser `/tmp/du.txt`** si le cron exploitation reste en place (mais SE5-autonome préférable), ou (b) **`du` natif borné** dans `profiles:snapshot` (`du --max-depth=1 -b /home/profiles` + scandir orphelins). **Recommandé (b)** pour l'autonomie SE5 (mémoire « SE5 autonome ») et pour ne pas dépendre d'un cron hors-git.

### Sécurité purge (anti-désastre `rm -rf`)

- Préfixe `/home/profiles/` **obligatoirement vérifié** sur chaque chemin avant suppression. `realpath` + vérification que le résultat commence bien par `/home/profiles/` (anti-symlink/traversal).
- **Re-vérifier l'absence de compte au moment de la purge** (le cache peut dater de la veille ; un compte recréé entre-temps ne doit pas perdre son profil). AC #5.
- Réutiliser `RoamingProfileService::isValueSafe()` ; rejeter `..` ; jamais de glob.
- Privilégier un déplacement vers `_Trash_users` (réversible, calque `do=3&mode_clean=mv` legacy) plutôt que `rm -rf` direct — décision dev à documenter. Respecter la règle globale : `trash` plutôt que `rm -rf` quand possible.

### Hors-scope (NE PAS faire)

- **Trim per-session `del-roam.sh` / `ExcludeProfileDirs`** : déjà porté (`generatePurgeScript`/`delRoamScript`), inchangé.
- **Tout ce qui touche le mode nomade** : les nomades n'ont **pas** de profil réseau (`/home/profiles`) — `clean_profiles` leur est étranger (cf. 26.2). 26.3 agit sur le store des profils itinérants, indépendamment de la nature du poste.
- **Redirection des profils navigateur selon `WorkstationEnvironment`** = Story 27.4.
- **Raviver le canal legacy** `ldap_cleaner.php`/`clean_profiles` (kill-switch actif).
- **Modifier la GPO `redirections`** ou le contrat agent/golden files.

### Dépendances

| Story | Rôle pour 26.3 | Statut | Bloquant ? |
|-------|----------------|--------|------------|
| 26.1 — Enum + resolver `WorkstationEnvironment` | **Aucun.** Le nettoyage de profils itinérants est orthogonal à la nature du poste (le `du` et les orphelins de `/home/profiles` ne dépendent pas de l'environnement résolu). | review | **Non** |
| 26.2 — Mode nomade (modèle local) | **Aucun.** 26.2 a établi que `clean_profiles` est hors sujet *pour les nomades* et a renvoyé la réimplémentation native ici. 26.3 ne dépend d'aucune décision/code de 26.2. | review | **Non** |
| 1bis.18f — `RoamingProfileService` + onglet profils-itinérants | Point d'appui réutilisé (stats, `isValueSafe`, UI admin). | done | Non (déjà livré) |
| 5.1b — `quota:snapshot` | Modèle de pattern (job nocturne → colonne, UI lit le cache). | done | Non (référence) |

> **Confirmation d'indépendance (demandée au cadrage)** : 26.3 **ne dépend PAS** du resolver d'environnement (26.1) **ni** de la décision nomade (26.2) au niveau code. C'est un nettoyage du store des profils itinérants `/home/profiles` (per-user/orphelins), orthogonal à `WorkstationEnvironment`. Les statuts `review` de 26.1/26.2 ne bloquent donc pas 26.3.

### Conventions projet

- **Routing filesystem-based** : pages sous `resources/views/pages/` ; réactif = Livewire SFC ; modale réutilisable `x-molecules.modal` ; toasts via `app/Components/Traits/WithToasts.php`.
- **PHP** : `declare(strict_types=1)`, services dans `app/Services`, commandes dans `app/Console/Commands` (autoloadées par `Kernel::commands()`).
- **Tests** : Pest/PHPUnit, base SQLite ; pas d'enforcement varchar SQLite (mémoire) ; mocker FS/`du`.
- **Migrations** : si table `profile_snapshots` retenue, migration datée + jouée en SQLite pour les tests (rappel : la VM ne joue pas auto les migrations — `migrate:status` avant e2e).
- **Cache driver** : configurable via `.env`/`config/cache.php` (mémoire 16-15) — d'où la **préférence persistance DB** plutôt que `Cache::*` pour la survie au flush.

### Notes post-review (2026-06-15) — hypothèses & dettes documentées

Issues du cycle de review (cf. `_bmad-output/codeReviews/26-3.md`). Points NON corrigés en code, assumés et documentés :

- **Login non-ASCII (review #5)** : `detectOrphans` compare via `strtolower()` PHP (ASCII) tandis que `findByLogin` résout en `LOWER()` SQL (locale-aware). Hypothèse retenue : **les logins sont des sAMAccountName ASCII**. Sur un login accentué, un profil pourrait apparaître orphelin à tort dans le compteur (risque de suppression faible car la purge re-passe par `detectOrphans`, cohérent des deux côtés). À revoir si le naming multi-vertical introduit des logins Unicode.
- **Extraction login `.V<N>` (review S3)** : `loginFromProfileDir` strippe `\.V\d+.*$`. Hypothèse : **aucun login ne contient littéralement `.V<chiffres>`** (sinon troncature → mauvais classement). Resserrer la regex laissé en attente (changerait le comportement pour les dossiers `.bak`).
- **Corbeille `_Trash_users` jamais vidée (review S2)** : la purge déplace (réversible, voulu) mais aucun pendant au `do=6` legacy ne vide `_Trash_users` → accumulation disque, gain différé. **Dette assumée** ; un vidage de corbeille serait une story dédiée.
- **`rename` cross-device (review S1)** : déplacement via `rename()`, qui échoue `EXDEV` si `/home/profiles` et la corbeille sont sur des mounts distincts. Vérifié OK sur la VM (même device `/dev/vda1`). **À confirmer en prod** : si `/home/profiles` est un volume dédié, prévoir un fallback (`mv` shell / copy+unlink). Collision de suffixe horodaté (même seconde) corrigée par un suffixe `-N` incrémental.

### References

- [Source: _bmad-output/implementation-artifacts/sprint-status.yaml#26-3-nettoyage-natif-profils] — cadrage spin-off (source de vérité du scope).
- [Source: _bmad-output/implementation-artifacts/26-2-mode-nomade-offline-resync.md] — story parente ; `clean_profiles` hors sujet nomade → renvoi vers 26.3.
- [Source: app/Services/RoamingProfileService.php] — service réutilisé (stats, `isValueSafe`, `generatePurgeScript`).
- [Source: app/Console/Commands/QuotaSnapshotCommand.php + app/Console/Kernel.php] — modèle job nocturne → colonne ; planification.
- [Source: resources/views/pages/users/_partials/users-table.blade.php] — tableau à badger (T3).
- [Source: resources/views/pages/admin/settings/_partials/profils-itinerants-tab.blade.php] — onglet admin (bandeau orphelins + purge, T4).
- [Source: sambaedu/annu/ldap_cleaner.php (do=3 / do=3&mode_clean=mv) + clean_profiles()] — comportement legacy réimplémenté (purge orphelins / déplacement `_Trash_users`).
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#FR28/FR29/Story 26.x] — situation epic.

## Recommandation Modèle Dev

**opus.**

Justification : story de code **multi-fichiers** (service + commande artisan planifiée + 2 surfaces UI Livewire + tests) touchant à la **suppression de fichiers sur disque** (`rm -rf`/`_Trash_users` sous `/home/profiles`) — la moindre erreur de garde (préfixe, re-vérification du compte, path-traversal) = **perte de données utilisateur**. S'y ajoutent une **contrainte perf non négociable** (invariant « jamais de `du` au render » à concevoir et verrouiller par test) et une **détection d'orphelins FS↔domaine** (résolution login Postgres-only, NFR7) avec fail-soft. Logique critique + sécurité + scheduler → `opus`.

## Dev Agent Record

### Agent Model Used

opus (claude-opus-4-8)

### Debug Log References

- `php artisan test` (host, php 8.4.5, vendor présent) — suite ciblée 26.3 : **53 tests verts (138 assertions)**.
- Migration `profile_snapshot` jouée up/down en SQLite :memory: (smoke OK).
- 6 échecs résiduels dans `tests/Feature/Livewire/Users/{ClassShareSection,GroupShowQuotaSection,UserShowQuotaSection}Test` = **pré-existants** (Vite manifest absent sur l'hôte, ces fichiers n'appellent pas `withoutVite()`), **hors scope 26.3** (aucun fichier touché par la story).

### Completion Notes List

**Décisions de design**

- **Cache = persistance DB** (recommandation story suivie) : colonne JSON `users.profile_snapshot` (mirror exact de `quota_snapshot`, survit au flush cache / restart, mémoire 16-15 driver configurable) pour les tailles par-login ; `SystemSetting` clé `profiles.orphans` pour la liste globale des orphelins (ces dossiers n'ont PAS de ligne user → pas badgeables → stockage séparé). Pas de table dédiée : réutilise les deux mécanismes K/V existants.
- **Scan = `du` natif borné** (recommandation suivie) : `du --max-depth=1 -b /home/profiles` dans le job, pas de dépendance au cron legacy `/tmp/du.txt` (autonomie SE5). `Process::run` (fakeable). Parsing extrait en `parseDuOutput()` pour testabilité (le `is_dir` guard rend `scanProfileSizes()` non testable directement en CI sans `/home/profiles`).
- **Bandeau = onglet admin profils-itinérants** (recommandation suivie) : cohérence métier, déjà gardé `server.admin`.
- **Purge = déplacement `_Trash_users`** (réversible, calque `do=3&mode_clean=mv`) plutôt que `rm -rf`. Suffixe horodaté pour ne pas écraser une purge précédente. Jamais de fallback `rm` silencieux : échec de `rename` = compté en erreur.
- **Seuil volumineux = 200 Mo** (constante `LARGE_PROFILE_THRESHOLD_MB`), au-dessus du seuil d'affichage 8 Mo de l'onglet (qui sert au drill-down par sous-dossier ; ici on cible le profil complet).
- **Résolution login↔dossier** : strip suffixe `.V<N>` (profils Windows versionnés `alice.V6`, cf. regex legacy `clean_profiles`), `LOWER(login)` Postgres-only (NFR7), cumul multi-versions sur le login.

**Invariants verrouillés par test**

- PERF : `ProfilsItinerantsOrphanPurgeTest::it_never_shells_out_at_render_or_purge` (`Process::assertNothingRan` + stub levant si `scanProfileSizes` appelé depuis l'UI).
- Sécurité : refus `..`/`/`/realpath hors `/home/profiles`, et **re-vérification BDD au moment de la purge** (compte recréé jamais supprimé).
- Zéro legacy : aucune référence à `clean_profiles`/`ldap_cleaner.php` ajoutée.

**Points d'attention pour la review**

1. La migration `2026_06_15_110000_add_profile_snapshot_to_users_table` n'est PAS jouée auto sur la VM (mémoire `vm_migrations_not_auto_applied`) — l'orchestrateur devra `php artisan migrate` + `config:cache` (chown www-admin) avant e2e.
2. `scanProfileSizes()` / `purgeOrphanProfiles()` n'ont pas de test FS réel (pas de `/home/profiles` en CI) — couverts par `parseDuOutput()`/gardes ; l'e2e VM (scénarios filesystem 26.3-1/5/6) valide le chemin FS complet.
3. Le partial `resources/views/pages/users/_partials/users-table.blade.php` n'est PAS le listing actif (le listing est inline dans `index.blade.php`) — la pastille a été posée dans `index.blade.php`, pas dans le partial inutilisé.

### File List

**Créés**

- `app/Console/Commands/ProfilesSnapshotCommand.php` — job nocturne `profiles:snapshot`.
- `database/migrations/2026_06_15_110000_add_profile_snapshot_to_users_table.php` — colonne JSON/JSONB `users.profile_snapshot`.
- `tests/Unit/Services/RoamingProfileCleanupTest.php` — scan/parse/orphelins/cache/gardes purge.
- `tests/Unit/Console/ProfilesSnapshotCommandTest.php` — persistance + fail-soft.
- `tests/Feature/Livewire/Admin/ProfilsItinerantsOrphanPurgeTest.php` — bandeau/purge/403/invariant perf.

**Modifiés**

- `app/Services/RoamingProfileService.php` — scan natif (`scanProfileSizes`/`parseDuOutput`), `loginFromProfileDir`, `detectOrphans`, lecteurs cache, `persistSnapshot`, `purgeOrphanProfiles` + constantes (`PROFILES_ROOT`, `TRASH_ROOT`, `ORPHANS_SETTING_KEY`, `LARGE_PROFILE_THRESHOLD_MB`).
- `app/Console/Kernel.php` — planification `profiles:snapshot` à 04:30.
- `app/Models/User.php` — `profile_snapshot` ajouté à `$fillable` + cast `array`.
- `resources/views/pages/users/index.blade.php` — `profile_snapshot` au `select()` + pastille « profil volumineux » (cache uniquement).
- `resources/views/pages/admin/settings/_partials/profils-itinerants-tab.blade.php` — `orphanCount` + `reloadOrphanCount()` + `purgeOrphans()` (double gate) + bandeau orphelins.
- `tests/Traits/CreatesPermissionSchema.php` — colonne `profile_snapshot` au schéma users de test.
- `tests/Feature/Livewire/Users/UsersIndexPageQuotaColumnTest.php` — 2 tests pastille volumineux.
- `tests/Feature/Console/KernelScheduleTest.php` — test planification 04:30.
- `docs/qa/domains/filesystem.md` — section Story 26.3 (scénarios 26.3-1→8).
- `docs/qa/domains/users.md` — section Story 26.3 (pastille 26.3-U1/U2).
- `docs/qa/README.md` — « Domaines couverts » filesystem + users mis à jour.

## Change Log

| Date | Changement |
|------|-----------|
| 2026-06-15 | Création (SM/architecte) — réimplémentation native du nettoyage de profils itinérants (pastille par-user + purge orphelins, calcul journalier). Spin-off de 26.2. Indépendance code vs 26.1/26.2 confirmée. Reco modèle : opus. |
| 2026-06-15 | Implémentation (dev opus) — T1-T7 livrés. Service étendu (scan `du` natif + orphelins + cache + purge `_Trash_users`), commande `profiles:snapshot` planifiée 04:30, colonne `users.profile_snapshot` + `SystemSetting profiles.orphans`, pastille volumineux tableau users, bandeau+purge onglet admin (double gate). 53 tests verts. Doc QA filesystem + users. Status → review. |
