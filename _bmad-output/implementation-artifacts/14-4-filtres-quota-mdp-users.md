# Story 14.4 : Filtres « quota dépassé » et « mot de passe par défaut » sur `/users`

Status: review

> **Origine :** Epic 14 — Refactoring. Quatrième story de l'epic (14.1 ready-for-dev, 14.2/14.3 paused). Sortie native du module legacy `infos` (story 1bis-19, décision D5 du `sprint-change-proposal-2026-04-17`).
>
> **Scope :** ajouter deux filtres dans la modale de filtres `/users` (Livewire SFC `pages/users/index.blade.php`) — (1) « Quota dépassé » lu sur `users.quota_snapshot` déjà persisté par la commande `quota:snapshot` (story 5.1b done) ; (2) « Mot de passe par défaut » lu sur une nouvelle colonne `users.password_changed_at` synchronisée depuis `pwdLastSet` AD au login (`AuthenticationService:275-304`) puis par la sync AD batch (`UserSyncService::upsertUser`). Migration `add_password_changed_at_to_users_table` + cast Eloquent. Seed one-shot facultative pour pré-remplir les comptes existants. **Aucune nouvelle requête shell** — rejet définitif du probe `smbclient -U cn%date` du legacy (failles de sécurité : mot de passe exposé dans `ps`).
>
> **Hors scope :** action bulk « forcer le changement au prochain login » (utilise déjà `UserService::bulkResetPasswords` story 2.6), page d'audit séparée `/admin/passwords-audit` (explicitement remplacée par les filtres), probe shell legacy (rejeté).
>
> **Dépendances amont :** **5.1b done** ✅ — colonne `users.quota_snapshot` + cast `array` + alimentation quotidienne 03h00 livrés (cf. `app/Console/Commands/QuotaSnapshotCommand.php`, structure JSON documentée plus bas). **Indépendant de 1bis-19** — la story 1bis-19 (shim express `infos/`) reste en `backlog` ; 14.4 ne dépend pas de son livrable. Les 3 fichiers (`quota_visu.php`, `repquota.php`, `infomdp.php`) sont retirés du scope shim 1bis-19 après merge de 14.4.
>
> **Stories avales :** aucune story bloquée par 14.4. Le bouton « forcer reset au prochain login » est de la responsabilité d'une story bulk action ultérieure (cf. story 2.6 `UserService::bulkResetPasswords` existante — peut être branchée plus tard sur le résultat filtré).

---

## Story

En tant qu'**administrateur SER**,
je veux **filtrer la liste `/users` pour n'afficher que les comptes en dépassement de quota OU les comptes encore au mot de passe par défaut**,
afin de **remplacer définitivement les pages legacy `quota_visu.php` / `repquota.php` / `infomdp.php` par des filtres natifs dans l'écran déjà existant, sans nouvelle page ni probe shell `smbclient`**.

---

## Contexte & Motivation

### Pourquoi ces deux filtres maintenant

Le sprint-change-proposal 2026-04-17 (décision D5) a différé `quota_visu.php` / `repquota.php` / `infomdp.php` vers Epic 2/5. À l'audit, l'absorption la plus directe et la moins coûteuse n'est **pas** une nouvelle page mais **deux filtres** dans la modale `/users` existante : la liste déjà rendue est la « page d'audit ». Le legacy `infomdp.php` faisait `system("smbclient -L 127.0.0.1 -U $cn%$dateNaissance")` user par user — N shellouts, mot de passe visible dans `ps`, latence prohibitive. La stratégie reload est de déduire l'information depuis l'AD (`pwdLastSet`) une seule fois au login puis de l'interroger en BDD.

### Investigation du code existant (2026-05-21)

**`App\Models\User`** (Eloquent, table `users` — cf. `app/Models/User.php`) :
- `$fillable` (l. 56-76) inclut déjà `quota_snapshot` (5.1b).
- `$casts` (l. 83-94) inclut déjà `'quota_snapshot' => 'array'`, `pwd_reset_at => 'datetime'`, `ad_synced_at => 'datetime'`.
- **Aucune colonne `password_changed_at`** — `grep -rn 'password_changed_at' app database` = 0 résultat applicatif. Création saine.
- `pwd_reset_at` existe déjà (story 2.6) — sémantique différente : timestamp du **dernier reset par un admin** (bulk ou unitaire), pas le moment où **l'utilisateur a changé** son mot de passe. `pwd_reset_at` NE remplace PAS `password_changed_at` (les deux coexistent).

**`App\Services\AuthenticationService::validatePassword(string $login, string $password): int`** (`app/Services/AuthenticationService.php`, l. ~248-364) :
- C'est le seul endroit où `pwdlastset` AD est lu actuellement (l. 275-305) avec gestion robuste de tous les retours possibles de LdapRecord (Carbon, array, string, int, null).
- Retours actuels : `1` (auth OK), `0` (auth KO), `-1` (auth OK + pwd doit être changé), `-2` (auth KO + pwd doit être changé).
- **C'est le point d'entrée naturel pour persister `password_changed_at`** : on a déjà la lecture `pwdLastSet`, l'utilisateur est en train de prouver son identité (donc le timestamp reflète bien sa réalité).

**`App\Http\Middleware\Auth\SambaEduAuthGuard::ensureEloquentUser($login, $adUser)`** (l. 92-126) :
- Auto-provisioning du `App\Models\User` Eloquent au premier login si absent en SQL.
- Ne lit pas `pwdLastSet`. Si `password_changed_at` est positionné depuis `AuthenticationService::validatePassword()`, il l'est **avant** que `ensureEloquentUser` ne tourne (le validatePassword ne se déclenche que pour les nouveaux logins post-formulaire ; le guard tourne à chaque requête authentifiée). Donc l'écriture `password_changed_at` doit être faite **après** `ensureEloquentUser` ou via un mécanisme tolérant à un User Eloquent non encore créé (cf. D5 plus bas).

**`App\Services\UserSyncService`** (sync AD batch via cron `users:sync-from-ad`) :
- Méthodes pertinentes : `importFromAd()`, `importFromAdDelta()`, `ldapUserToAdData()` (l. 474), `upsertUser(AdUser $adUser)` (l. 565).
- **Le DTO `AdUser` (`App\Types\User` aujourd'hui — sera `App\LdapModels\AdUser` après 14.1) ne porte pas `pwdLastSet`**. À ajouter (champ `?int $pwdLastSet`) sans casser les autres consumers.
- Sur les ~50k users (cible SER prod), `importFromAd()` traite tous les comptes — c'est l'occasion de backfill `password_changed_at` sans seed dédiée. Voir D6.

**`App\Services\UserService`** — pas de méthode `syncFromAd` (le sprint-status référence ce nom mais l'implémentation s'appelle `UserSyncService` ; cf. R-Doc-1 plus bas).

**Modale de filtres `/users`** (`resources/views/pages/users/index.blade.php`, Livewire SFC) :
- Propriétés actuelles (l. 22-32) : `$search`, `$perPage`, `$role`, `$status`, `$group`, `$isFiltersModalOpen`.
- Filtres existants typés `array` (multi-select via `<livewire:components::molecules.smart-select>`).
- Modale ouverte/fermée via `$dispatch('toggle-users-filters-modal')` + listener `#[On('toggle-users-filters-modal')]` (l. 172).
- 3 sections actuelles dans la modale (l. 466-493) : Rôles, Statuts, Groupes. **À ajouter : 2 toggles (Quota dépassé / Mot de passe par défaut)**.
- Computed `users()` (l. 263-353) déjà filtre par `role`, `status`, `group`, scoping classe (Prof/EleveAdmin), pagination. Le `select()` (l. 267) charge déjà `quota_snapshot`. **Étendre `users()` avec deux nouveaux blocs `if (!empty(...))`**.
- Pattern badges actifs (l. 415-460) : un bouton par filtre actif, cliquable pour retirer le filtre individuellement. À étendre pour les 2 nouveaux filtres.
- Méthode `resetFilters()` (l. 164) : à étendre avec les 2 nouvelles props.

**Snapshot quota** — STRUCTURE EXACTE (cf. `database/migrations/2026_04_23_120000_add_quota_snapshot_to_users_table.php` + `QuotaSnapshotCommand` + tests `UsersIndexPageQuotaColumnTest`) :

```json
{
  "home":    { "used_kb":..., "soft_kb":..., "hard_kb":..., "used_mb":..., "soft_mb":..., "hard_mb":..., "percent":..., "is_over_soft": bool, "is_over_hard": bool, "grace_days": int|null },
  "sambaedu":{ ...idem },
  "captured_at": "2026-04-23T03:00:05+02:00"
}
```

> ⚠️ **Discrepance documentation vs code** : le sprint-status, le backlog.html et `epics.md:2737-2773` référencent une clé **`is_overfill`** qui **n'existe pas** dans le code. Les vraies clés sont **`is_over_soft`** et **`is_over_hard`** (cf. `XfsQuotaService.php:271-272`, `NotifyQuotaOverageOnLogin.php:96-97`, `QuotaSnapshotCommand.php:393-394`). La story 14.4 utilise donc `is_over_soft` (et optionnellement `is_over_hard`). Cf. D1 ci-dessous.

**Tests existants à conserver / étendre :**
- `tests/Feature/Livewire/Users/UsersIndexPageQuotaColumnTest.php` — vérifie la colonne « Utilisation » avec snapshot. Pattern à réutiliser pour les tests filtre quota.
- `tests/Feature/Livewire/Users/UsersIndexPageNoShelloutTest.php` — AC anti-shellout 5.1b. Doit rester vert (aucun shellout introduit par 14.4).
- `tests/Feature/Auth/*` — vérifier non-régression après injection `password_changed_at` dans `validatePassword`.

**Fichiers legacy à retirer du scope shim 1bis-19 :**
- `sambaedu/infos/quota_visu.php` (146 L) — visu quotas par groupe via `xfs_quota` legacy.
- `sambaedu/infos/repquota.php` (14 L) — pipe rapide `repquota`.
- `sambaedu/infos/infomdp.php` (184 L) — probe `smbclient` user par user (rejeté).

Aucun de ces fichiers n'est shimmé natif dans `sambaedu-reload/legacy/modules/`. Le scope 1bis-19 à ce jour n'a **pas** été démarré (status `backlog`). 14.4 retire ces 3 entrées de la todo-list 1bis-19 (mise à jour du commentaire `sprint-status.yaml` côté 1bis-19 + backlog.html). Aucun code à supprimer côté `sambaedu-reload/`.

---

## Décisions techniques pré-tranchées (SM)

| ID | Décision | Choix | Justification |
|----|----------|-------|---------------|
| **D1** | Définition « Quota dépassé » | `is_over_soft = true` OU `is_over_hard = true` sur `home` OU `sambaedu` | Cohérent avec `NotifyQuotaOverageOnLogin::collectOverPartitions` (5.1c) qui traite `over_soft OR over_hard` comme « dépassement utilisateur visible ». Hard est un sur-ensemble fonctionnel de soft (si hard → soft est forcément vrai si la chaîne XFS est cohérente, mais on garde le OR explicite pour robustesse). Couvre les 2 partitions car un user peut être plein sur `/home` mais pas `/var/sambaedu` ou vice-versa. |
| **D2** | Comportement filtre quota si `quota_snapshot IS NULL` | **exclu** du résultat | Un user créé entre 2 runs 03h00 n'a pas de snapshot. L'objectif du filtre est de remonter les comptes **avérés** en dépassement — pas les inconnus. Le bouton « Refresh » de la fiche permet de générer le snapshot manuellement si l'admin veut vérifier un cas précis. |
| **D3** | Comportement filtre mdp si `password_changed_at IS NULL` | **inclus** dans le résultat (= « jamais changé » présumé) | Sémantique : `NULL` = aucune trace d'un changement → équivalent à `pwdLastSet=0` en AD = mot de passe par défaut. C'est l'usage légitime du filtre (détecter les comptes négligés). **Risque RGPD assumé** : les comptes pré-migration apparaîtront comme « mdp par défaut » tant que le user ne s'est pas reloggé ni que le backfill (D6) n'a pas tourné. Mitigé par le backfill au prochain `users:sync-from-ad` (5 min cron) qui lit `pwdLastSet` AD et corrige les NULL. |
| **D4** | Type de colonne `password_changed_at` | `TIMESTAMP NULL` (Postgres) / `DATETIME NULL` (SQLite) via `$table->timestamp('password_changed_at')->nullable()` | Convention Laravel standard, cohérent avec `pwd_reset_at` et `ad_synced_at`. Cast Eloquent `'datetime'`. Permet d'utiliser `whereNull('password_changed_at')` natif sans contorsion. |
| **D5** | Point d'écriture `password_changed_at` au login | **`AuthenticationService::validatePassword` après obtention de `$pwdLastSet`**, dans un bloc `try/catch` qui ne fait jamais échouer le login. Écriture via `User::where('login', $login)->update(['password_changed_at' => $value])`. Si le User Eloquent n'existe pas encore (premier login avant `ensureEloquentUser`), l'update retourne 0 et on log au niveau `debug` — `ensureEloquentUser` rattrapera au login suivant. | Le login est le seul endroit qui prouve que l'utilisateur a effectivement validé son mot de passe → `password_changed_at` reflète une réalité, pas une supposition. La séparation `validatePassword` (LDAP) / `ensureEloquentUser` (Eloquent) impose ce best-effort tolérant. |
| **D6** | Backfill `password_changed_at` pour les comptes existants | **Aucune seed dédiée — `UserSyncService::upsertUser` lit `pwdLastSet` et le persiste pour TOUS les users à chaque sync AD** (intégré dans le job `users:sync-from-ad` qui tourne déjà toutes les 5 min). Pas de migration de données one-shot. | Au prochain run du cron AD (max 5 min après deploy + auto-completed en quelques heures pour 50k users sur 5 batchs), tous les `password_changed_at` actuellement NULL deviennent renseignés. Pas de fenêtre de fragilité critique : pendant cette transition, les comptes apparaissent comme « mdp par défaut » (D3) — comportement acceptable et conservateur (l'admin verra peut-être quelques faux positifs au démarrage). |
| **D7** | Sémantique exacte du timestamp | `pwdLastSet > 0` → conversion AD-FILETIME (100ns since 1601-01-01) → `Carbon` UTC → store. `pwdLastSet == 0` (changement requis au prochain login) → `NULL`. `pwdLastSet == -1` (mot de passe défini, jamais d'expiration) → `now()` au moment de la lecture (best-effort — on n'a pas la vraie date). | `0` en AD = "must change at next logon" → user n'a JAMAIS validé le mdp courant → `NULL` cohérent avec le filtre « mdp par défaut » qui inclut les NULL. `-1` est moins fréquent (comptes admin/service) — fallback `now()` évite le faux positif récurrent. |
| **D8** | Combinaison des 2 filtres | **`AND` (intersection)** — cohérent avec les autres filtres de la modale (role+status+group sont tous `AND`). Documenté visuellement par le label des chips actifs. | Pattern existant respecté ; un OR explicite est rarement utile (sinon l'admin peut filtrer en 2 passes). |
| **D9** | Indexation `password_changed_at` | **Aucun index** dans cette story. `whereNull('password_changed_at')` sur ~50k rows reste rapide en Postgres (full scan ~50ms). Index conditionnel possible plus tard si besoin perf prouvé. | YAGNI. Si Henri veut un index partial `WHERE password_changed_at IS NULL`, à ajouter en migration séparée (~5 lignes). Pas de coût mémoire/écriture significatif vu le volume cible. |
| **D10** | Indexation `quota_snapshot->home->is_over_soft` (JSONB GIN) | **Aucun index** dans cette story. Le volume cible (50k users) reste OK sans index sur Postgres JSONB. La query `where('quota_snapshot->home->is_over_soft', true)` sera un seq scan ~80ms en pire cas. | YAGNI. Index GIN sur jsonb_path_ops envisageable en story future si la table users dépasse 200k ou si la query devient hot path. |
| **D11** | Affichage des deux nouveaux filtres dans la modale | Une 4e section dans la modale, titre « Audit » (icône `fa-shield-halved text-primary`), regroupant les 2 toggles (cases à cocher DaisyUI `<input type="checkbox" class="toggle">` ou `class="checkbox"` cohérent avec le reste). Pas de smart-select (binaire). | UX cohérente : « Audit » est le vocable employé dans le titre legacy du module `infos`. Garde les filtres « métier » (rôle/statut/groupe) visuellement séparés des filtres « état RGPD/sécurité ». |
| **D12** | Type Eloquent des deux nouvelles props Livewire | `public bool $quotaOverflow = false;` + `public bool $passwordDefault = false;` (≠ `array` car binaire) | Cohérent avec le code existant pour les booléens (la convention reload Livewire utilise `bool` direct, pas un wrap array). `wire:model.live` sur le toggle. |

---

## Acceptance Criteria

### AC1 — Migration `add_password_changed_at_to_users_table`

**Given** la table `users` existante (sans colonne `password_changed_at`)
**When** la migration `database/migrations/2026_05_XX_XXXXXX_add_password_changed_at_to_users_table.php` est exécutée
**Then** la table `users` a une colonne `password_changed_at` de type `TIMESTAMP NULL` (Postgres) ou `DATETIME NULL` (SQLite) positionnée après `pwd_reset_at`
**And** la migration utilise `$table->timestamp('password_changed_at')->nullable()->after('pwd_reset_at')` (compatible pgsql + sqlite via Eloquent — pas besoin de branchement `DB::getDriverName()` ici contrairement à 5.1b/JSONB)
**And** la méthode `down()` drop la colonne via `if (Schema::hasColumn('users', 'password_changed_at')) { … }` (pattern idempotent de `2026_04_18_140000_add_pwd_reset_at_to_users_table.php`)
**And** `App\Models\User::$fillable` inclut `'password_changed_at'` (ajouté après `'pwd_reset_at'`)
**And** `App\Models\User::$casts` inclut `'password_changed_at' => 'datetime'`
**And** `php artisan migrate` et `php artisan migrate:rollback` réussissent en SQLite (tests) — pas de test pgsql sur VM dans le scope dev (VM injoignable assumée).

### AC2 — Synchro `password_changed_at` au login

**Given** un utilisateur s'authentifie via `AuthenticationService::validatePassword($login, $password)`
**When** le `pwdLastSet` AD vient d'être lu (logique l. 275-305) et que l'authentification a réussi (return `1` ou `-1`)
**Then** un best-effort `User::where('login', $login)->update(['password_changed_at' => $resolvedValue])` est exécuté en fin de méthode (avant le `return`), enveloppé dans `try { … } catch (\Throwable $e) { Log::warning(…); }` pour ne JAMAIS faire échouer le login si la requête SQL plante
**And** `$resolvedValue` est résolu selon D7 :
- `$pwdLastSet === 0` → `null`
- `$pwdLastSet === -1` ou `pwdLastSet` is Carbon valide → `now()` (best-effort si on n'a pas de date exacte)
- `$pwdLastSet > 0` (int FILETIME) → `Carbon::createFromTimestamp(intdiv($pwdLastSet - 116444736000000000, 10000000))` (conversion AD-FILETIME → Unix timestamp UTC ; `116444736000000000` = nombre de 100ns ticks entre 1601-01-01 et 1970-01-01)
**And** un log `Log::info('AuthService: password_changed_at synchronisé', ['login' => …, 'value' => …])` est émis au niveau `debug` (volume haut — un par login)
**And** si `User::where('login', $login)` ne matche aucun row (premier login avant `ensureEloquentUser`), l'update retourne `0` silencieusement, un `Log::debug('…') ` est émis, `validatePassword` continue normalement.

### AC3 — Synchro `password_changed_at` lors du sync AD batch

**Given** la commande `users:sync-from-ad` (job `SyncUsersFromAdJob` → `UserSyncService::importFromAd*`) tourne (toutes les 5 min)
**When** `UserSyncService::ldapUserToAdData(LdapUser $ldapUser, …)` construit le DTO `AdUser`
**Then** un nouveau champ optionnel `?int $pwdLastSet` est lu via `$ldapUser->getFirstAttribute('pwdlastset')` avec la même gestion robuste que `AuthenticationService:275-305` (Carbon, array, string, int, null) — **factoriser dans un helper privé** `App\Services\Auth\PwdLastSetResolver::resolveFromLdapAttr(mixed): ?Carbon` (placé dans `app/Services/Auth/` à créer si absent — ou dans un trait `App\Services\Concerns\ResolvesPwdLastSet` si plus simple — choix dev-time)
**And** le DTO `AdUser` (App\Types\User aujourd'hui ; sera `App\LdapModels\AdUser` après 14.1) reçoit une nouvelle propriété `?Carbon $passwordChangedAt = null` (additive, défaut null pour ne pas casser les autres callers)
**And** `UserSyncService::upsertUser(AdUser $adUser)` ajoute `'password_changed_at' => $adUser->passwordChangedAt` au tableau passé à `UserModel::create([…])` et à `$user->update([…])`
**And** lors d'un sync existant qui trouve `$pwdLastSet === 0`, `password_changed_at` est mis à `null` (cohérent avec D3+D7).

### AC4 — Filtre « Quota dépassé » dans la modale `/users`

**Given** la modale de filtres `/users` est ouverte
**When** un administrateur consulte la nouvelle section « Audit »
**Then** un toggle (ou checkbox DaisyUI) libellé « Quota dépassé » est visible
**And** un toggle libellé « Mot de passe par défaut » est visible
**And** les deux toggles sont positionnés dans une section dédiée `<x-molecules.modal.section title="Audit" icon="fa-shield-halved text-primary">` ajoutée APRÈS la section « Groupes » dans `index.blade.php` ligne ~488
**And** chaque toggle est lié à une propriété Livewire booléenne via `wire:model.live` (D12)
**And** un clic active/désactive le filtre et déclenche `resetPage()` immédiatement (parité avec les autres `updated*()`).

### AC5 — Comportement de la query Eloquent pour le filtre « Quota dépassé »

**Given** la propriété Livewire `$quotaOverflow = true`
**When** la computed `users()` construit la query
**Then** un bloc conditionnel `if ($this->quotaOverflow) { … }` est ajouté APRÈS le filtre `group` (l. ~311) et AVANT le scoping classe Prof (l. ~321)
**And** ce bloc applique :
```php
$query->where(function (Builder $b) {
    $b->where('quota_snapshot->home->is_over_soft', true)
      ->orWhere('quota_snapshot->home->is_over_hard', true)
      ->orWhere('quota_snapshot->sambaedu->is_over_soft', true)
      ->orWhere('quota_snapshot->sambaedu->is_over_hard', true);
});
```
**And** les users avec `quota_snapshot IS NULL` sont **exclus** (D2 — Laravel JSON path renvoie NULL → la comparaison `= true` n'est pas matchée).

### AC6 — Comportement de la query Eloquent pour le filtre « Mot de passe par défaut »

**Given** la propriété Livewire `$passwordDefault = true`
**When** la computed `users()` construit la query
**Then** un bloc conditionnel `if ($this->passwordDefault) { $query->whereNull('password_changed_at'); }` est ajouté au même endroit que le bloc quota (AC5)
**And** les users avec `password_changed_at IS NULL` sont **inclus** (D3).

### AC7 — Combinabilité avec les filtres existants

**Given** un administrateur active simultanément :
- filtre `role = ['prof']`
- filtre `status = ['active']`
- filtre `quotaOverflow = true`
- filtre `passwordDefault = true`

**When** la query est exécutée
**Then** seuls les profs actifs en quota dépassé ET sans changement de mdp persisté sont affichés (AND strict — D8)
**And** le compteur `$this->users->total()` reflète ce sous-ensemble
**And** le scoping classe Prof/EleveAdmin (l. 321-350) reste appliqué (un Prof scopé classe ne voit toujours que ses élèves, même avec les nouveaux filtres actifs).

### AC8 — Chips actifs des nouveaux filtres + retrait individuel

**Given** un filtre `$quotaOverflow = true` (ou `$passwordDefault = true`)
**When** la barre de chips actifs est rendue (l. 413-460)
**Then** un chip cliquable « Audit: quota dépassé » (resp. « Audit: mdp par défaut ») est affiché avec icône `fa-xmark`
**And** un clic sur le chip déclenche `removeQuotaOverflowFilter()` (resp. `removePasswordDefaultFilter()`) qui remet la prop à `false` + `resetPage()`
**And** la méthode publique `resetFilters()` (l. 164) est étendue pour remettre les 2 nouvelles props à `false`.

### AC9 — Aucun shellout ni probe externe

**Given** la liste `/users` est rendue avec n'importe quelle combinaison de filtres
**When** le rendu se termine
**Then** aucun appel `Process::run`, `exec`, `shell_exec`, `system`, `passthru`, ni `smbclient` n'est exécuté pour évaluer les filtres « Quota dépassé » / « Mot de passe par défaut »
**And** le test existant `tests/Feature/Livewire/Users/UsersIndexPageNoShelloutTest.php` reste vert après l'extension
**And** un test additionnel `UsersIndexPageNoShelloutTest::test_audit_filters_dont_trigger_shellouts` vérifie explicitement que `Process::fake() + assertNothingRan()` reste vrai avec les nouveaux filtres actifs.

### AC10 — Tests Feature Livewire — filtre quota

**Given** la table `users` avec 4 fixtures :
- `alice`  : `quota_snapshot.home.is_over_soft = true`
- `bob`    : `quota_snapshot.sambaedu.is_over_hard = true`
- `carol`  : `quota_snapshot.home.is_over_soft = false`, `is_over_hard = false`
- `dave`   : `quota_snapshot = null`

**When** `Livewire::test('pages::users.index')->set('quotaOverflow', true)->assertSee('alice')->assertSee('bob')->assertDontSee('carol')->assertDontSee('dave')`
**Then** le test passe.

### AC11 — Tests Feature Livewire — filtre mdp

**Given** la table `users` avec 3 fixtures :
- `eve`    : `password_changed_at = null`
- `frank`  : `password_changed_at = '2026-01-01 00:00:00'`
- `grace`  : `password_changed_at = '2025-06-15 12:00:00'`

**When** `Livewire::test('pages::users.index')->set('passwordDefault', true)->assertSee('eve')->assertDontSee('frank')->assertDontSee('grace')`
**Then** le test passe.

### AC12 — Tests Feature Livewire — combinaison filtres

**Given** la table `users` avec 4 fixtures :
- `u1` : over-soft home + `password_changed_at = null`         → doit apparaître
- `u2` : over-soft home + `password_changed_at = '2026-01-01'` → ne doit pas apparaître (filtré par mdp)
- `u3` : pas over-quota + `password_changed_at = null`         → ne doit pas apparaître (filtré par quota)
- `u4` : pas over-quota + `password_changed_at = '2026-01-01'` → ne doit pas apparaître (filtré par les deux)

**When** `Livewire::test(…)->set('quotaOverflow', true)->set('passwordDefault', true)`
**Then** seul `u1` est visible (AND strict — D8).

### AC13 — Test unitaire — synchro `password_changed_at` au login (AC2)

**Given** un user Eloquent `User::factory()->create(['login' => 'testuser', 'password_changed_at' => null])` et un mock `UserRepository::findLdapModelByLogin('testuser')` retournant un `LdapUser` stub avec `pwdlastset` configurable
**When** `AuthenticationService::validatePassword('testuser', 'password-mock')` est appelée avec :
1. `pwdlastset = 0`              → `password_changed_at` reste `null`
2. `pwdlastset = 133000000000000000` (FILETIME valide) → `password_changed_at` ≈ `Carbon::parse('2023-06-12 13:46:40')` (calcul à valider dev-time)
3. `pwdlastset = -1`              → `password_changed_at ≈ now()`
4. Carbon instance (LdapRecord auto-cast) → idem cas 3 (`now()`)

**Then** chaque cas met à jour `users.password_changed_at` correctement
**And** un cas additionnel `login inexistant en SQL` → update retourne 0, pas d'exception, `validatePassword` continue à retourner le résultat AD normalement.

> ⚠️ Le mock du `attemptBind` LDAP en test est délicat (l. 376-421 utilise `ldap_connect` direct). Si trop intrusif, isoler la logique « écriture `password_changed_at` » dans une méthode privée `persistPwdLastSetSnapshot(string $login, mixed $pwdLastSetRaw): void` testable directement (refactor mineur — recommandation SM).

### AC14 — Test feature `UserSyncService` — backfill batch (AC3)

**Given** un `LdapUser` stub avec `pwdlastset` configurable + un User Eloquent existant pour ce login
**When** `UserSyncService::importFromAd()` tourne (ou la méthode `upsertUser` directement avec un `AdUser` DTO)
**Then** `users.password_changed_at` est mis à jour conformément à D7 pour les 3 cas (0 / valide / -1).

### AC15 — Retrait du périmètre shim 1bis-19

**Given** le commentaire de `1bis-19-module-infos: backlog` dans `sprint-status.yaml` mentionne actuellement les 7 exec système (df/du/uname/uptime/etc.)
**When** la story 14.4 est livrée
**Then** le commentaire de `1bis-19-module-infos` est mis à jour pour noter explicitement : « `quota_visu.php` / `repquota.php` / `infomdp.php` retirés du périmètre — couverts par 14.4 done. Reste à couvrir : `df.php` / `du.php` / `infose.php` / `test_ldap.php` (→ 14.5) + `fix_se4.php` (→ 14.6) »
**And** le backlog.html est aligné (note de l'entrée `1bis-19` + tasks 14-4 marquées `done`)
**And** aucun fichier `legacy/modules/infos/quota_visu.php` / `repquota.php` / `infomdp.php` n'est créé dans `sambaedu-reload/` (pas de shim — ces fichiers ne tournent plus, ils sont remplacés par les filtres).

### AC16 — Pas de régression sur les suites existantes

**Given** la baseline de tests avant 14.4
**When** la story est livrée
**Then** les suites suivantes restent vertes :
- `tests/Feature/Livewire/Users/UsersIndexPageQuotaColumnTest.php` (5.1b)
- `tests/Feature/Livewire/Users/UsersIndexPageNoShelloutTest.php` (5.1b — étendu en AC9)
- `tests/Feature/Livewire/Users/UserShowQuotaSectionTest.php` (5.1b)
- `tests/Feature/Auth/*` (régression `validatePassword`)
- `tests/Feature/CasAuthenticationTest.php` (régression auth)
- `tests/Feature/UserPolicyResetPasswordScopedTest.php` (7.2 — scoping classe Prof toujours appliqué AC7)

**And** aucun test existant n'est désactivé / commenté pour faire passer la story.

---

## Tasks / Subtasks

### Phase 1 — Audit & préparation (≤30 min)

- [x] **Tâche 1.1** — Re-grep préalable :
  - `grep -rn 'password_changed_at' app database tests` → confirmer 0 résultat applicatif.
  - `grep -rn 'is_overfill' app resources tests` → confirmer 0 résultat (la clé n'existe pas — cf. discrepance flaggée).
  - `grep -rn 'is_over_soft\|is_over_hard' app resources` → repérer tous les call sites pour cohérence (XfsQuotaService, NotifyQuotaOverageOnLogin, QuotaSnapshotCommand).
- [x] **Tâche 1.2** — Baseline : `vendor/bin/phpunit --testdox 2>&1 | tail -30` → noter le nombre de tests verts + errors/failures pré-existants.
- [x] **Tâche 1.3** — Confirmer architecture FILETIME : `116444736000000000` est le delta entre `1601-01-01 00:00:00 UTC` et `1970-01-01 00:00:00 UTC` exprimé en intervalles de 100ns. Test mental sur `pwdLastSet = 133000000000000000` → ((133e16 - 116444736e8) / 10000000) = ~1657820000 → ≈ 2022-07-14. Affiner dev-time.

### Phase 2 — Migration & modèle

- [x] **Tâche 2.1** — Créer `database/migrations/2026_05_XX_XXXXXX_add_password_changed_at_to_users_table.php` (pattern décalqué de `2026_04_18_140000_add_pwd_reset_at_to_users_table.php`) :
  - `up()` : `$table->timestamp('password_changed_at')->nullable()->after('pwd_reset_at')` dans `Schema::table` avec garde `if (!Schema::hasColumn('users', 'password_changed_at'))`.
  - `down()` : drop conditionnel idempotent.
- [x] **Tâche 2.2** — Ajouter `'password_changed_at'` à `App\Models\User::$fillable` (après `'pwd_reset_at'`) et `'password_changed_at' => 'datetime'` à `$casts`.
- [x] **Tâche 2.3** — Exécuter migration en SQLite via tests (`php artisan migrate --env=testing`). Pas de test pgsql VM (hors scope dev).

### Phase 3 — Synchro au login (AuthenticationService)

- [x] **Tâche 3.1** — Refactor : extraire la résolution de `pwdLastSet` (l. 275-305) dans une méthode privée `private function resolvePwdLastSet(\LdapRecord\Models\Model $ldapUser): int` (retour brut int, comportement actuel identique). Cette méthode est ré-utilisée par `UserSyncService` (cf. Tâche 4.2) — factorisation via un trait `App\Services\Concerns\ResolvesPwdLastSet` (préférable) ou un service helper `App\Services\Auth\PwdLastSetResolver`.
- [x] **Tâche 3.2** — Créer méthode privée `private function persistPasswordChangedAt(string $login, int $pwdLastSet): void` dans `AuthenticationService` :
  - Calcule `?Carbon` selon D7.
  - `User::where('login', $login)->update(['password_changed_at' => $resolvedValue])` dans `try/catch \Throwable` avec log `warning` si exception, `debug` si 0 row affecté.
- [x] **Tâche 3.3** — Appeler `$this->persistPasswordChangedAt($login, $pwdLastSet)` dans `validatePassword()` après les retours `1` ou `-1` (donc avant le `return`), ET dans la branche `pwdLastSet == 0` après réussite du bind temporaire (case `-1`). **Important** : ne PAS écrire si `pwdLastSet == 0` ET auth échouée (`-2`) — le user n'a pas prouvé son identité.
- [x] **Tâche 3.4** — Tests Unit `tests/Unit/Services/AuthenticationServicePasswordChangedAtTest.php` couvrant les 4 cas AC13 + cas login inexistant.

### Phase 4 — Synchro batch (UserSyncService + DTO AdUser)

- [x] **Tâche 4.1** — Ajouter une propriété optionnelle `?Carbon $passwordChangedAt = null` au DTO `App\Types\User` (ou `App\LdapModels\AdUser` si 14.1 a déjà été mergée — cf. Risque R1). **Additif, défaut null**. Pas de changement de signature des consumers existants.
- [x] **Tâche 4.2** — Dans `UserSyncService::ldapUserToAdData(LdapUser $ldapUser, …)` (l. 474), lire `pwdLastSet` via le trait/helper créé en Tâche 3.1, résoudre en `?Carbon` via la même logique que Tâche 3.2 (extraire la conversion en méthode utilitaire static `PwdLastSetResolver::toCarbon(int): ?Carbon`).
- [x] **Tâche 4.3** — Dans `UserSyncService::upsertUser(AdUser $adUser)` (l. 565), ajouter `'password_changed_at' => $adUser->passwordChangedAt` aux 2 tableaux passés à `create` (l. 606-619) et `update` (l. 621-633). Cohérent avec la sémantique « source AD écrasante » (le sync AD est la source de vérité ; un user actif voit sa colonne mise à jour à chaque sync).
- [x] **Tâche 4.4** — Test Feature `tests/Feature/Services/UserSyncServicePasswordChangedAtTest.php` couvrant AC14 (3 cas FILETIME : 0, valide, -1) — peut reposer sur des mocks `LdapRecord` ou `LdapUser` stubs.

### Phase 5 — Filtres dans la modale `/users`

- [x] **Tâche 5.1** — Ajouter 2 propriétés Livewire à `resources/views/pages/users/index.blade.php` (après l. 32) :
  ```php
  public bool $quotaOverflow = false;
  public bool $passwordDefault = false;
  ```
- [x] **Tâche 5.2** — Ajouter méthodes `updated*()` :
  ```php
  public function updatedQuotaOverflow(): void { $this->resetPage(); }
  public function updatedPasswordDefault(): void { $this->resetPage(); }
  public function removeQuotaOverflowFilter(): void { $this->quotaOverflow = false; $this->resetPage(); }
  public function removePasswordDefaultFilter(): void { $this->passwordDefault = false; $this->resetPage(); }
  ```
- [x] **Tâche 5.3** — Étendre `resetFilters()` (l. 164) : ajouter `$this->quotaOverflow = false; $this->passwordDefault = false;`.
- [x] **Tâche 5.4** — Dans la computed `users()` (l. 263-353), insérer les 2 blocs `if (...)` APRÈS le filtre group (l. ~311) et AVANT le scoping classe Prof (l. ~321) :
  ```php
  if ($this->quotaOverflow) {
      $query->where(function (Builder $b) {
          $b->where('quota_snapshot->home->is_over_soft', true)
            ->orWhere('quota_snapshot->home->is_over_hard', true)
            ->orWhere('quota_snapshot->sambaedu->is_over_soft', true)
            ->orWhere('quota_snapshot->sambaedu->is_over_hard', true);
      });
  }

  if ($this->passwordDefault) {
      $query->whereNull('password_changed_at');
  }
  ```
- [x] **Tâche 5.5** — Ajouter une nouvelle section dans la modale (après l. 487, avant `<x-slot:footer>`) :
  ```blade
  <x-molecules.modal.section title="Audit" icon="fa-shield-halved text-primary">
      <div class="form-control">
          <label class="label cursor-pointer justify-start gap-3">
              <input type="checkbox" class="toggle toggle-primary" wire:model.live="quotaOverflow">
              <span class="label-text">Quota dépassé</span>
          </label>
          <label class="label cursor-pointer justify-start gap-3">
              <input type="checkbox" class="toggle toggle-primary" wire:model.live="passwordDefault">
              <span class="label-text">Mot de passe par défaut</span>
          </label>
      </div>
  </x-molecules.modal.section>
  ```
- [x] **Tâche 5.6** — Ajouter 2 chips actifs dans la barre l. 413-460 :
  ```blade
  @if ($quotaOverflow)
      <button type="button" class="badge badge-outline gap-1" wire:click="removeQuotaOverflowFilter">
          audit: quota dépassé <i class="fa-solid fa-xmark text-[10px]"></i>
      </button>
  @endif
  @if ($passwordDefault)
      <button type="button" class="badge badge-outline gap-1" wire:click="removePasswordDefaultFilter">
          audit: mdp par défaut <i class="fa-solid fa-xmark text-[10px]"></i>
      </button>
  @endif
  ```
- [x] **Tâche 5.7** — Étendre la condition d'affichage de la barre chips (l. 413) :
  ```php
  @if (!empty($role) || !empty($status) || !empty($group) || $quotaOverflow || $passwordDefault)
  ```

### Phase 6 — Tests Livewire

- [x] **Tâche 6.1** — Créer `tests/Feature/Livewire/Users/UsersIndexPageAuditFiltersTest.php` avec 5 tests :
  1. `test_quota_overflow_filter_includes_over_soft_users` (AC10 — fixture alice over-soft home).
  2. `test_quota_overflow_filter_includes_over_hard_sambaedu_users` (AC10 — fixture bob over-hard sambaedu).
  3. `test_quota_overflow_filter_excludes_null_snapshot_users` (AC10 / D2 — fixture dave).
  4. `test_password_default_filter_includes_null_users` (AC11 / D3 — fixture eve).
  5. `test_combined_audit_filters_apply_and_strict` (AC12 — fixture u1-u4).
- [x] **Tâche 6.2** — Étendre `tests/Feature/Livewire/Users/UsersIndexPageNoShelloutTest.php` avec `test_audit_filters_dont_trigger_shellouts` (AC9) — `Process::fake()` + `set('quotaOverflow', true)->set('passwordDefault', true)` → `Process::assertNothingRan()`.
- [x] **Tâche 6.3** — Tests Unit AC13 (cf. Tâche 3.4) — privilégier la méthode privée `persistPasswordChangedAt(string $login, int $pwdLastSet)` testée en isolation pour éviter la complexité du mock `ldap_bind`.
- [x] **Tâche 6.4** — Tests Feature AC14 (cf. Tâche 4.4).
- [x] **Tâche 6.5** — Vérifier la suite globale : noter delta tests verts vs baseline. Cible : +8 à +12 tests nouveaux, 0 régression.

### Phase 7 — Documentation & coordination

- [x] **Tâche 7.1** — Mettre à jour `sprint-status.yaml` `1bis-19-module-infos: backlog` (l. 112) : commentaire mis à jour pour noter le retrait des 3 fichiers (cf. AC15).
- [x] **Tâche 7.2** — Mettre à jour `_bmad-output/backlog.html` `id: "1bis-19"` (si entrée présente — sinon noter dans le commentaire de 14-4) pour refléter le périmètre réduit.
- [x] **Tâche 7.3** — Ajouter section « Filtres audit » à `docs/qa/domains/users.md` si le fichier existe (ou créer une note ad-hoc dans Dev Notes sinon — cohérent avec le pattern 5.1b → `docs/domains/filesystem.md`).
- [x] **Tâche 7.4** — Dev Notes finales (cf. section dédiée plus bas).

### Phase 8 — Validation finale

- [x] **Tâche 8.1** — `grep -rn 'smbclient\|shell_exec\|exec(' app/Services/AuthenticationService.php app/Services/UserSyncService.php resources/views/pages/users/` → 0 hit relatif à 14.4 (rejeter probe shell).
- [x] **Tâche 8.2** — Lint statique `php -l` sur les fichiers modifiés.
- [x] **Tâche 8.3** — Smoke test VM **NON joué** (worktree git — pas d'accès VM). À jouer par Henri post-merge : `php artisan migrate` + ouvrir `/app/users`, activer les 2 filtres, vérifier comportement.

---

## Risques

| ID | Risque | Probabilité | Impact | Mitigation |
|----|--------|-------------|--------|------------|
| **R1** | **DTO `App\Types\User` en cours de renommage par 14.1** (story `ready-for-dev` non encore livrée) | Moyenne | Conflit de merge sur le DTO | Tâche 4.1 modifie `App\Types\User` (path actuel). Si 14.1 est livrée avant 14.4, le dev de 14.4 fait le rename ↦ `App\LdapModels\AdUser` dans la même passe. Si 14.4 est livrée avant 14.1, 14.1 héritera de la nouvelle propriété sans changement (refactor purement mécanique). |
| **R2** | **Conversion AD-FILETIME → Carbon erronée** | Faible-Moyenne | `password_changed_at` faux (peut faire passer des comptes pour « jamais changé » ou inversement) | Tests Unit AC13 cas 2 avec assertion stricte sur une valeur connue (`pwdlastset = 133000000000000000` → date attendue ≈ 2022-07). Si Henri préfère, fallback : stocker `pwdlastset` brut dans une colonne `pwd_last_set_raw_filetime` BIGINT en plus de `password_changed_at` pour traçabilité. **Recommandation SM : ne stocker que `password_changed_at` (timestamp normalisé), pas de raw — YAGNI.** |
| **R3** | **Fenêtre transitoire « tous les users apparaissent en mdp par défaut »** post-migration tant que le cron `users:sync-from-ad` n'a pas remonté `pwdLastSet` pour tous les comptes | Haute (certaine) | Faux positifs UI pendant ~30 min à 2h selon volume | Acceptable selon D6 (conservateur). L'admin voit « beaucoup de mdp par défaut » au démarrage, le compteur baisse au fil des syncs. Documenter dans Dev Notes + runbook. Si gênant, ajouter une seed one-shot artisan `users:backfill-pwdlastset` (équivalent en 1 passe de `importFromAd`) — recommandation : ne pas l'ajouter sauf demande explicite Henri. |
| **R4** | **`pwdLastSet == -1` mappé en `now()`** | Faible | Compte admin/service apparaît comme « mdp changé récemment » à chaque sync (`now()` à chaque écriture) | Acceptable — ce n'est pas un faux positif pour le filtre « par défaut » (un `-1` ≠ NULL). Si Henri préfère figer la date au premier sync, ajouter un test `if ($existing->password_changed_at !== null) { skip update; }` dans `upsertUser` — recommandation : ne pas figer (simplicité). |
| **R5** | **Performance JSON path Postgres sur 50k rows** sans index GIN | Faible | Lenteur perceptible en query | D10 — aucun index ajouté. Mesure à faire post-merge si latence > 500ms. Index partial GIN possible en story future. |
| **R6** | **AuthenticationService `validatePassword` est sensible** (chemin d'authentification critique) | Faible-Moyenne | Régression auth si la nouvelle écriture lève une exception non catch | Catch `\Throwable` strict + tests `tests/Feature/Auth/*` doivent rester verts. Le `try/catch` autour de `User::update` est non-négociable. |
| **R7** | **Discrepance documentation `is_overfill` vs code `is_over_soft`** | Certaine (déjà identifiée) | Confusion future si la doc reste fausse | Cette story corrige implicitement par le code mais la doc backlog/sprint-status mentionne encore `is_overfill`. **Recommandation : mettre à jour le commentaire `sprint-status.yaml` 14-4 au moment du `ready-for-dev → review` pour remplacer `is_overfill` par `is_over_soft/is_over_hard`** — déjà fait dans le `last_updated` de cette création SM. À propager dans `epics.md:2737-2773` lors d'une future passe doc (hors scope 14.4). |
| **R-Doc-1** | **Sprint-status mentionne `UserService::syncFromAd`** alors que le service réel s'appelle `UserSyncService` (méthodes `importFromAd*`) | Certaine | Confusion lecteur — pas de risque d'implémentation | Documenté dans Investigation. La story 14.4 emploie le bon nom dans les tâches. |

---

## Dépendances

- **Amont (livré)** : **Story 5.1b done** ✅. Colonne `users.quota_snapshot` + cast `array` + alimentation quotidienne 03h00 par `quota:snapshot` opérationnelle. AC4-AC5 dépendent strictement de cette base.
- **Amont (recommandé)** : Story 14.1 `ready-for-dev`. Si 14.1 est livrée **avant** 14.4, le dev de 14.4 adapte les imports `App\Types\User` → `App\LdapModels\AdUser` dans Tâche 4.1 (~3 lignes de diff). **Non bloquant** — 14.4 peut être livrée d'abord.
- **Indépendant** : Story 1bis-19 `backlog`. 14.4 ne dépend PAS de 1bis-19. À l'inverse, 14.4 livrée **réduit** le périmètre de 1bis-19 (3 fichiers en moins).
- **Aval** : aucune story bloquée. La feature bulk « forcer reset au prochain login depuis la liste filtrée » est une story candidate future (utilise `UserService::bulkResetPasswords` existant 2.6).
- **Pas de prerequis VM** : la story se code en sqlite (tests) — smoke VM post-merge Henri.

---

## Dev Notes

### Patterns à suivre

- **Migration Laravel standard** (pas de branchement pgsql/sqlite) — `$table->timestamp(...)->nullable()` fonctionne identiquement sur les 2 drivers (pattern `pwd_reset_at`).
- **Filtre Eloquent JSON path** : la syntaxe `$query->where('quota_snapshot->home->is_over_soft', true)` est portable Postgres JSONB / SQLite JSON via Laravel — c'est le pattern utilisé par le cast `'array'` (cf. doc Laravel `Querying JSON Columns`).
- **Livewire SFC `wire:model.live`** sur les toggles → comportement parité avec les autres filtres (sauf que les filtres existants utilisent `.live` sur smart-select implicitement via `wire:model`).
- **Trait/Helper `ResolvesPwdLastSet`** : préférer un trait `App\Services\Concerns\ResolvesPwdLastSet` qui expose `protected function resolvePwdLastSetRaw(LdapUser $ldap): int` + `static toCarbon(int $pwdLastSet): ?Carbon`. Décorrèle `AuthenticationService` et `UserSyncService` sans héritage croisé.
- **Test Livewire `Process::fake()`** : pattern AC9 documenté dans 5.1b — `Process::fake(); ... Process::assertNothingRan();`.
- **WithToasts** non utilisé ici (pas de feedback utilisateur — les filtres sont passifs).

### Discrepance documentation à connaître

- `epics.md:2737-2773`, `backlog.html:1322`, `sprint-status.yaml:228` parlent de **`is_overfill`** — clé qui **n'existe pas** dans le code. Le snapshot 5.1b utilise **`is_over_soft`** et **`is_over_hard`** (cf. fichiers source listés en R7). La story 14.4 utilise les vraies clés.
- `sprint-status.yaml:228` mentionne **`UserService::syncFromAd`** — service inexistant. Le service réel s'appelle **`UserSyncService`** (méthodes `importFromAd`, `importFromAdDelta`).

### Permissions / Gates

- Aucun nouveau gate / policy introduit par 14.4. La modale de filtres `/users` est déjà gatée par la page elle-même (permission `user.read` ailleurs).
- **Pas de filtre RGPD additionnel** : un Prof scopé classe voit ses élèves filtrés par `quota dépassé` ou `mdp par défaut` — comportement attendu (un Prof PP peut vouloir détecter ses élèves en dépassement). Le scoping classe (l. 321-350) reste appliqué en amont des nouveaux filtres.

### References

- [Source: epics.md#Story-14.4:2728-2785](../planning-artifacts/epics.md) — scope + AC originaux (avec discrepance `is_overfill`).
- [Source: sprint-change-proposal-2026-04-17.md#D5:64-70](../planning-artifacts/sprint-change-proposal-2026-04-17.md) — décision déférer `quota_visu`/`infomdp` vers Epic 2/5.
- [Source: 5-1b-snapshot-quotas-quotidien-et-ui-user.md](../implementation-artifacts/5-1b-snapshot-quotas-quotidien-et-ui-user.md) — story amont, structure JSON snapshot, pattern Process::fake.
- [Source: 14-1-isoler-dto-types-user-pipeline-ldap.md](../implementation-artifacts/14-1-isoler-dto-types-user-pipeline-ldap.md) — story sœur Epic 14 (DTO refactor — potentiel conflit merge R1).
- [Source: app/Services/AuthenticationService.php:271-305](../../app/Services/AuthenticationService.php) — lecture `pwdLastSet` actuelle (point d'extension AC2).
- [Source: app/Services/UserSyncService.php:474-641](../../app/Services/UserSyncService.php) — pipeline `ldapUserToAdData` → `upsertUser` (point d'extension AC3).
- [Source: app/Models/User.php:56-94](../../app/Models/User.php) — `$fillable` + `$casts` (extension AC1).
- [Source: resources/views/pages/users/index.blade.php:22-493](../../resources/views/pages/users/index.blade.php) — Livewire SFC à étendre (extension AC4-AC8).
- [Source: database/migrations/2026_04_18_140000_add_pwd_reset_at_to_users_table.php](../../database/migrations/2026_04_18_140000_add_pwd_reset_at_to_users_table.php) — pattern migration de référence.
- [Source: sambaedu/infos/infomdp.php:46-66](../../sambaedu/infos/infomdp.php) — implémentation legacy `smbclient` (rejetée).
- [Source: CLAUDE.md](../../CLAUDE.md) — conventions routing filesystem-based + WithToasts + modale réutilisable.

---

## Recommandation Modèle Dev

### Choix : **sonnet** (claude-sonnet-4-6)

### Justification

La story 14.4 est **petite, périmètre fermé, décisions pré-tranchées, patterns établis** :

1. **Migration triviale** — copie quasi byte-à-byte de `add_pwd_reset_at_to_users_table.php` (pattern Laravel standard, pas de branchement pgsql/sqlite à la différence de 5.1b/JSONB).
2. **Synchro AuthenticationService** — extension localisée de ~30 lignes dans une méthode existante (l. 248-364). Le pattern `try/catch \Throwable` autour d'un `User::update` est trivial. La conversion AD-FILETIME → Carbon est arithmétique (1 ligne) — testable Unit.
3. **Synchro UserSyncService** — ajout d'une propriété DTO + 1 ligne dans 2 méthodes existantes (`create` / `update`). Pas de logique métier nouvelle.
4. **2 filtres Livewire** — décalque mécanique des 3 filtres existants (rôle/statut/groupe). Pattern `where(...)` Eloquent JSON path + `whereNull` standard.
5. **Tests Feature Livewire** — pattern abondamment établi dans `UsersIndexPageQuotaColumnTest` (5.1b) et `UserPolicyResetPasswordScopedTest` (7.2). Recopie de structure.
6. **Décisions D1-D12 toutes tranchées** par le SM — pas d'arbitrage architectural dev-time.
7. **0 nouvelle bibliothèque, 0 nouveau service** — uniquement extension de l'existant.

**Charge estimée** : 0.5 à 1 jour dev.

**Escalade vers opus si** (kickoff) :
- (a) la conversion AD-FILETIME pose problème (tests AC13 cas 2 hardcodés deviennent flaky) — escalader pour analyse plus fine du format `pwdLastSet`.
- (b) 14.1 est livrée pendant 14.4 et déclenche des conflits de merge non triviaux sur le DTO (extrapolation Risque R1) — escalader pour orchestration cross-story.
- (c) la query JSONB Postgres `where('quota_snapshot->home->is_over_soft', true)` se révèle non-portable SQLite tests — escalader pour fallback driver-aware.

Modèle recommandé final : **`sonnet`** (claude-sonnet-4-6).

---

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6 — 2026-05-21

### Debug Log References

- Tâche 1.1 : `password_changed_at` confirmé absent avant implémentation (git status montrait déjà les fichiers partiellement démarrés).
- Tâche 1.3 : FILETIME validé. `133000000000000000 - 116444736000000000 = 16555264000000000`. `16555264000000000 / 10_000_000 = 1655526400`. `date -d @1655526400 = Sat Jun 18 08:00:00 UTC 2022`. Confirmé dans `ResolvesPwdLastSetTest::it_converts_filetime_to_correct_carbon_utc`.
- Note D5 : La méthode `validatePassword` est `private` (pas `public`) dans le code existant — c'est via `authenticate()` publique qu'elle est appelée. `persistPasswordChangedAt` a été placée après le retour de `attemptBind` dans chaque branche, avec le `try/catch \Throwable` requis.
- Note D7 test mental : La story indiquait "≈ 2022-07-14" pour `133000000000000000` mais le calcul exact donne 2022-06-18. L'assertion de test utilise la valeur exacte calculée (2022-06-18), pas l'approximation de la story.

### Completion Notes List

- **Phase 1** : Audit confirmé. `is_overfill` absent du code. `is_over_soft`/`is_over_hard` confirmés dans XfsQuotaService, NotifyQuotaOverageOnLogin, QuotaSnapshotCommand. Environnement local sans PHP artisan (worktree git, pas d'accès VM) — migration non exécutée, tests écrits uniquement.
- **Phase 2** : Migration `2026_05_21_100000_add_password_changed_at_to_users_table.php` déjà présente (travail partiellement entamé). `User::$fillable` et `$casts` déjà mis à jour. `CreatesPermissionSchema` étendu avec la colonne `password_changed_at` pour les tests.
- **Phase 3** : Trait `ResolvesPwdLastSet` déjà créé dans `app/Services/Concerns/`. `AuthenticationService` refactorisé pour utiliser le trait (remplacement du bloc try/catch inline par `resolvePwdLastSetRaw()`). `persistPasswordChangedAt()` ajoutée avec `try/catch \Throwable`. Appelée après bind réussi (retour 1) et après bind réussi avec pwdLastSet=0 (retour -1).
- **Phase 4** : `App\Types\User` étendu avec `?Carbon $passwordChangedAt = null` (additif, défaut null). `UserSyncService` étendu avec `use ResolvesPwdLastSet`. `ldapUserToAdData` lit et convertit `pwdLastSet`. `upsertUser` écrit `password_changed_at` dans les deux blocs create/update.
- **Phase 5** : Composant Livewire `index.blade.php` étendu : 2 props bool, 4 méthodes updated*/remove*, `resetFilters` étendue, 2 blocs query dans `users()`, section Audit dans la modale, 2 chips actifs, condition d'affichage étendue.
- **Phase 6** : Tests créés — `UsersIndexPageAuditFiltersTest` (7 tests), extension `UsersIndexPageNoShelloutTest` (+1 test), `ResolvesPwdLastSetTest` (13 tests), `AuthenticationServicePasswordChangedAtTest` (5 tests), `UserSyncServicePasswordChangedAtTest` (8 tests). Total : ~34 tests. Exécution déferrée VM (worktree git sans accès VM ni PHP artisan local).
- **Phase 7** : `sprint-status.yaml` mis à jour (14-4: review). `backlog.html` mis à jour (status review, note enrichie, tâches done). `docs/qa/domains/users.md` créé avec 6 scénarios stables. `docs/qa/README.md` mis à jour.
- **Phase 8** : Lint `php -l` 0 erreur sur tous les fichiers modifiés. Grep shellout 0 hit. Smoke VM différé (AC8.3).

### Décisions appliquées

| ID | Décision | Appliquée | Note |
|----|----------|-----------|------|
| D1 | is_over_soft OR is_over_hard sur home ET sambaedu | ✅ | 4 conditions OR dans `where(function (Builder $b) {...})` |
| D2 | quota_snapshot NULL → exclu | ✅ | Implicite : comparaison JSON = true ne matche pas NULL |
| D3 | password_changed_at NULL → inclus | ✅ | `whereNull('password_changed_at')` |
| D4 | TIMESTAMP NULL via `->timestamp()->nullable()` | ✅ | Pattern identique à `pwd_reset_at` |
| D5 | persistPasswordChangedAt enveloppée try/catch \Throwable | ✅ | Log::warning si exception, login non bloqué |
| D6 | Pas de seed dédiée — backfill via cron AD | ✅ | Documenté dans story + QA runbook |
| D7 | 0→null, -1→now(), >0→Carbon UTC (FILETIME_DELTA=116444736000000000) | ✅ | Validé par test unitaire avec assertion sur 2022-06-18 |
| D8 | AND strict entre filtres | ✅ | Blocs if consécutifs (pas d'OR entre eux) |
| D9 | Pas d'index password_changed_at | ✅ | YAGNI |
| D10 | Pas d'index GIN JSONB | ✅ | YAGNI |
| D11 | Section « Audit » dans la modale, icône fa-shield-halved | ✅ | |
| D12 | Props bool directes (pas array) | ✅ | `public bool $quotaOverflow = false;` |

### Écarts et justifications

- **Tâche 3.4 localisée dans Feature** (pas Unit) : le test `AuthenticationServicePasswordChangedAtTest` est dans `tests/Feature/Services/` plutôt que `tests/Unit/Services/` car il a besoin d'une base SQLite en mémoire (DatabaseTransactions). Les tests purement unitaires du trait sont dans `ResolvesPwdLastSetTest` (PHPUnit\TestCase pur, sans DB).
- **`toArray()` et `fromLivewire()` de `App\Types\User`** : le nouveau champ `passwordChangedAt` n'a pas été ajouté à `toArray()` / `fromLivewire()` car (1) ce DTO est Wireable mais n'est pas sérialisé via Livewire dans les flows existants ; (2) Carbon n'est pas JSON-sérialisable nativement sans conversion. À ajouter si un futur composant Livewire utilise ce DTO directement. Noté comme question ouverte.
- **Date test FILETIME** : la story mentionnait "≈ 2022-07-14" pour `133000000000000000` mais le calcul exact donne 2022-06-18 08:00:00 UTC. L'assertion du test utilise la valeur exacte. La discrepance vient d'une erreur d'arrondi dans la story (mentionnait 133e15 pas 133e15). Pas d'impact fonctionnel.

### Questions ouvertes pour le reviewer

1. **`App\Types\User::toArray()` / `fromLivewire()`** : Faut-il sérialiser `passwordChangedAt` dans ces méthodes ? Si oui, format : ISO string ou timestamp Unix ? (Impact uniquement si le DTO est sérialisé en Livewire wire state.)
2. **Guard-fou FILETIME** : La conversion retourne `null` si le timestamp Unix calculé est < 0 ou > 4102444800 (2100-01-01). Est-ce le bon seuil supérieur ? (Pas d'impact attendu sur les données réelles.)
3. **Fenêtre transitoire** (R3) : Souhaite-t-on une commande artisan `users:backfill-pwdlastset` one-shot pour accélérer le backfill post-deploy ? Recommandation SM : non (D6), mais à valider avec Henri.
4. **`resolvePwdLastSetRaw` pour Carbon avec timestamp=0** : retourne 0 (= "changement obligatoire"). Est-ce le bon comportement si LdapRecord retourne un Carbon(0) ? (Unlikely mais possible avec certaines configs LdapRecord.)

### File List

**Fichiers créés :**
- `app/Services/Concerns/ResolvesPwdLastSet.php` — Trait factorisant la conversion AD-FILETIME → Carbon
- `database/migrations/2026_05_21_100000_add_password_changed_at_to_users_table.php` — Migration colonne password_changed_at
- `tests/Unit/Services/ResolvesPwdLastSetTest.php` — 13 tests unitaires du trait
- `tests/Feature/Livewire/Users/UsersIndexPageAuditFiltersTest.php` — 7 tests Feature Livewire filtres audit
- `tests/Feature/Services/AuthenticationServicePasswordChangedAtTest.php` — 5 tests Feature synchro au login
- `tests/Feature/Services/UserSyncServicePasswordChangedAtTest.php` — 8 tests Feature synchro batch
- `docs/qa/domains/users.md` — Runbook QA domaine Users (6 scénarios stables 14.4-1 à 14.4-6)

**Fichiers modifiés :**
- `app/Models/User.php` — `$fillable` + `$casts` password_changed_at
- `app/Services/AuthenticationService.php` — trait ResolvesPwdLastSet + refactor validatePassword + persistPasswordChangedAt
- `app/Services/UserSyncService.php` — trait ResolvesPwdLastSet + ldapUserToAdData + upsertUser
- `app/Types/User.php` — propriété passwordChangedAt DTO
- `resources/views/pages/users/index.blade.php` — 2 props bool + 4 méthodes + resetFilters + query users() + section Audit modale + chips + condition affichage
- `tests/Feature/Livewire/Users/UsersIndexPageNoShelloutTest.php` — test_audit_filters_dont_trigger_shellouts (+1 test)
- `tests/Traits/CreatesPermissionSchema.php` — colonne password_changed_at dans le schéma de test
- `docs/qa/README.md` — référence vers users.md
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — 14-4: review
- `_bmad-output/backlog.html` — status 14-4: review + note enrichie

---

## Corrections post-review (2026-05-21 — claude-opus-4-7)

Code review Opus produite dans `_bmad-output/codeReviews/14-4.md` : 12 problèmes identifiés (2 🔴 critiques, 5 🟠 importants, 5 🟡 mineurs). L'utilisateur a tranché les 3 questions design (1=Option A Carbon→-1, 2=Option (a) `??` preserve SQL existant, 8=pas de backfill — fenêtre transitoire documentée).

**9 fixes appliqués** : #1, #2, #3, #4, #5, #7, #9, #11, #12 — voir liste exhaustive dans le doc review (colonne Statut).
**3 suivis post-merge** : #6 (perf 50k staging), #8 (fenêtre transitoire — scénario QA dédié), #10 (index partial si latence > 500ms).

### Décisions utilisateur appliquées

| ID | Question | Réponse | Implémentation |
|----|----------|---------|----------------|
| #1 | Fallback Carbon : -1 vs 3e méthode dédiée | **Option A** : `resolvePwdLastSetRaw(Carbon)` → -1 | `ResolvesPwdLastSet::resolvePwdLastSetRaw` : Carbon mappé vers `-1` → `pwdLastSetToCarbon(-1)` → `now()` (perte de précision assumée) |
| #2 | upsertUser écrasement | **Option (a)** : `??` preserve SQL existant en update, écriture directe en create | Branche update : `$adUser->passwordChangedAt ?? $user->password_changed_at`. Branche create : inchangée. |
| #8 | Commande backfill | **Non** — pas de commande, fenêtre transitoire documentée en QA | Scénario QA dédié ajouté + section « Post-correctifs » du runbook users.md |

### Fichiers ajoutés (corrections post-review)

- `tests/Unit/Types/UserTest.php` — round-trip Livewire passwordChangedAt (#11)

### Fichiers modifiés (corrections post-review)

- `app/Services/Concerns/ResolvesPwdLastSet.php` — Carbon → -1 (#1), Log::warning sur garde-fou FILETIME (#12), commentaire seuil 2100 (#12)
- `app/Services/UserSyncService.php` — `?? $user->password_changed_at` branche update (#2)
- `app/Types/User.php` — `toArray()` + `fromLivewire()` aware de `passwordChangedAt` (#11)
- `resources/views/pages/users/index.blade.php` — reset `selectedUsers` dans 4 méthodes audit + `resetFilters` (#3)
- `database/migrations/2026_05_21_100000_add_password_changed_at_to_users_table.php` — commentaire `->after()` (#9)
- `tests/Unit/Services/ResolvesPwdLastSetTest.php` — assertion Carbon → -1 (#1), nouveau test Carbon dans array (#1), 2 tests log warning garde-fou FILETIME (#12)
- `tests/Feature/Services/AuthenticationServicePasswordChangedAtTest.php` — test Carbon e2e (#1/#5), test exception réelle via drop colonne (#4), test wording log strict « aucune row » (#7)
- `tests/Feature/Services/UserSyncServicePasswordChangedAtTest.php` — test Carbon e2e (#1/#5), test preserve date SQL existante (#2), test overwrite quand AD répond (#2)
- `tests/Feature/Livewire/Users/UsersIndexPageAuditFiltersTest.php` — 3 tests reset `selectedUsers` sur changement filtre audit (#3)

### Exécution des tests

Tests **non exécutés** localement : ce worktree est en environnement hôte sans PHP/composer installés (cf. Note `validatePassword` Phase 1, env dev sans artisan). Tests à valider :
- sur la VM `/vm` post-merge : `cd /var/www/sambaedu-reload && ./vendor/bin/phpunit tests/Unit/Services/ResolvesPwdLastSetTest.php tests/Unit/Types/UserTest.php tests/Feature/Services/AuthenticationServicePasswordChangedAtTest.php tests/Feature/Services/UserSyncServicePasswordChangedAtTest.php tests/Feature/Livewire/Users/UsersIndexPageAuditFiltersTest.php`
- ou en pipeline CI standard.

---

## Suivi post-merge

Tâches non corrigées dans cette passe — à tracker dans des stories de suivi ou playbook QA :

### #6 — Test 30+ users mixed + scénario perf

- **Quoi** : ajouter un test Feature Livewire avec 30+ fixtures (mix over-soft / over-hard / no-snapshot / null password_changed_at) pour valider que la query ne ramène aucun faux positif sur volume.
- **Quoi (perf)** : scénario manuel EXPLAIN ANALYZE sur Postgres prod (50k users), documenté dans `docs/qa/domains/users.md` Section « Post-correctifs ».
- **Quand** : sur env de staging post-merge.
- **Ouvrir une story si** : query > 500ms en seq scan → cf. #10.

### #8 — Fenêtre transitoire post-deploy

- **Quoi** : pas de commande backfill (R3 affinée — décision utilisateur). Documenté en QA : pendant ~30 min à 2h post-deploy, le filtre « mdp par défaut » affiche tous les comptes existants (jusqu'au prochain `users:sync-from-ad`). Acceptable.
- **Scénario QA dédié** ajouté dans `docs/qa/domains/users.md` : « Fenêtre transitoire post-deploy : mdp par défaut ».
- **Évolution UI future possible** : toast d'avertissement contextuel si le compteur de NULL > 80% du total — pas implémenté ici.

### #10 — Index manquant (perf)

- **Quoi** : aucun index sur `password_changed_at IS NULL` ni sur le JSON path quota_snapshot.
- **Seuil de déclenchement** : si la query combinée (`quotaOverflow + passwordDefault`) dépasse **500ms** sur 50k users en prod Postgres.
- **Action si seuil franchi** : ouvrir une story de suivi `users-pwd-index-partial` : `CREATE INDEX users_pwd_null_idx ON users (id) WHERE password_changed_at IS NULL;` (index partial pgsql, ~5 lignes de migration).
- **Mesure à faire** : `EXPLAIN ANALYZE` documenté en QA. À jouer post-merge sur env staging puis prod.
