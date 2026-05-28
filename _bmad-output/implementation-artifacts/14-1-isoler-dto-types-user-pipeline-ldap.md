# Story 14.1 : Isoler le DTO `App\Types\User` au pipeline LDAP→SQL

Status: ready-for-dev

> **Origine :** Epic 14 — Refactoring. Première story de l'epic technique. Découverte lors de l'audit de la Story 7.2 (scoping classe Prof — `UserPolicy::view` / `resetPassword`).
>
> **Scope :** renommer + relocaliser le DTO `App\Types\User` (et ses voisins `UserSearchCriteria` / `UserSearchResult`) en `App\LdapModels\*`, basculer la fiche utilisateur Livewire `pages/users/[login]/` sur `App\Models\User` natif (wireable Livewire 3 + `#[Locked]`), supprimer la propriété béquille `$sqlUserModel`, ajouter 4 accessors d'alias sur l'Eloquent pour préserver l'API consommée par les vues, retirer du Blade les 5 blocs d'identifiants externes (dead code jamais populé), supprimer `UserService::getByLoginFromSql()`.
>
> **Hors scope :** audit des autres pages Livewire qui consomment encore le DTO en lecture (à traiter dans des stories ultérieures de l'epic 14), suppression des champs morts côté pipeline LDAP après confirmation, refactor du `UserResource` API.
>
> **Dépendances amont :** **7.2 done** (la béquille `$sqlUserModel` n'existe que parce que 7.2 a câblé Gates/Policies Spatie sur la fiche user — c'est cette béquille qu'on retire ici).
>
> **Stories avales :** aucune dans 14.1. Les autres dettes identifiées seront ouvertes dans 14.2+ au fil de l'eau.

---

## Story

En tant que **développeur SER**,
je veux que le DTO `App\Types\User` soit renommé `App\LdapModels\AdUser`, déplacé à proximité de `LdapUser`, et retiré des pages Livewire utilisateur au profit de `App\Models\User` natif,
afin que plus aucun composant Livewire ne wrappe inutilement l'Eloquent dans un DTO de transit LDAP, et que l'usage du DTO soit physiquement contraint au pipeline `LdapUser → AdUser → SQL` (sa raison d'être historique).

---

## Contexte & Motivation

### Pourquoi ce refactor maintenant

Au cours de la Story 7.2 (scoping classe Prof — `UserPolicy::view` / `resetPassword`), l'investigation a mis en évidence deux usages mélangés du DTO `App\Types\User` :

1. **Usage légitime — pipeline LDAP→SQL.** `App\LdapModels\LdapUser->toBusinessObject()` produit le DTO depuis les entrées AD brutes ; `App\Services\UserSyncService` lit ses champs pour peupler la table `users`. C'est sa raison d'être historique. **À conserver tel quel.**
2. **Usage redondant — vue Livewire.** Sur les pages user (`/app/users/{login}`), `UserService::getByLoginFromSql()` reconstruit le DTO à partir des colonnes SQL pour ne pas passer un Eloquent à Blade. Maintenant que `App\Models\User` est wireable nativement en Livewire 3 (avec `#[Locked]` pour la sécurité côté ID) et que les Gates/Policies Spatie ont besoin de l'Eloquent (trait `HasRoles`, relations `userGroups`), ce wrap est stérile et a provoqué l'apparition d'une propriété `$sqlUserModel` parallèle pendant la 7.2 — duplication.

### Découverte clé de l'audit

`getByLoginFromSql()` (`app/Services/UserService.php:141-171`) **ne lit jamais le LDAP** — elle construit le DTO à partir des colonnes SQL existantes. Et **5 propriétés du DTO (`idEnt`, `idAaf`, `idSiecle`, `idGpei`, `idNc`) ne sont jamais populées** : ce sont du dead code visible dans `_partials/technical-identifiers.blade.php` qui n'a jamais rien rendu pour ces blocs.

**Conséquence :** aucune migration BDD n'est nécessaire. Toutes les données utilisées par les pages sont déjà en colonnes SQL.

### Justification du nom `AdUser`

`UserSyncService.php` aliase déjà le DTO en `use App\Types\User as AdUser` — l'équipe utilise spontanément ce vocable. Le namespace `App\LdapModels\` existe déjà (héberge `LdapUser`). La relocalisation rapproche le DTO de son producteur (`LdapUser::toBusinessObject()`) et de son consommateur (`UserSyncService`).

---

## Périmètre

### Renommage + relocalisation

- `App\Types\User` → `App\LdapModels\AdUser`
- `App\Types\UserSearchCriteria` → `App\LdapModels\UserSearchCriteria`
- `App\Types\UserSearchResult` → `App\LdapModels\UserSearchResult`
- Suppression du dossier `app/Types/` après migration.

### Mise à jour des imports (rename pur, pas de changement de signature)

- `app/Services/UserService.php`
- `app/Services/UserSyncService.php` (l'alias `as AdUser` devient le nom natif)
- `app/Services/AdDataTransformer.php`
- `app/LdapModels/LdapUser.php` (signature `toBusinessObject(): AdUser`)
- `app/Repositories/UserRepository.php`
- `app/Http/Resources/UserResource.php`
- `app/Http/Middleware/RequireAdminRights.php`
- `app/Http/Middleware/Auth/SambaEduAuthGuard.php` (`ensureEloquentUser(string $login, AdUser $adUser)`)

### Bascule de la page profil utilisateur en Eloquent natif

**Fichier `resources/views/pages/users/[login]/index.blade.php`**
- Remplacer `public ?App\Types\User $user = null` par `public ?App\Models\User $user = null` (avec `#[Locked]` pour empêcher la modification client-side de l'ID).
- Supprimer la propriété temporaire `$sqlUserModel` (ajoutée pendant la story 7.2 comme béquille pour les Gates).
- Supprimer l'appel à `$this->userService->getByLoginFromSql($login)` ; remplacer par `$this->user = App\Models\User::where('login', $login)->first()`.
- `Gate::authorize('view-user', $this->user)` se fait directement sur l'Eloquent.
- Simplifier `loadSpatieState()` : plus besoin de re-query, lire les rôles/permissions directement sur `$this->user`.

**Fichiers `_partials/personal-info-form.blade.php` et `_partials/role-change-form.blade.php`**
- `use App\Types\User` → `use App\Models\User`.
- Refresh Eloquent au lieu de `getByLoginFromSql`.

**Fichier `_partials/technical-identifiers.blade.php`**
- Supprimer les blocs `idEnt`, `idAaf`, `idSiecle`, `idGpei`, `idNc` (dead code — ces propriétés du DTO ne sont jamais populées, restent `null` en permanence).
- Conserver `objectGuidDisplay` (= `ad_guid`).

### Accessors d'alias sur `App\Models\User`

Pour ne pas modifier les nombreuses vues qui consomment l'API actuelle du DTO :

- `etabCode` → alias de `school_code`
- `etabName` → alias de `school_name`
- `objectGuidDisplay` → alias de `ad_guid`
- `isDisabled(): bool` → `!$this->is_active`

### Suppression de `UserService::getByLoginFromSql()`

Plus aucun caller légitime après la bascule. À supprimer (vérifier exhaustivité par grep avant — si un caller hors-scope est découvert, il est migré dans la même story sur le pattern `User::where('login', …)`).

---

## Acceptance Criteria

### AC1 — `app/Types/` supprimé

**Given** `app/Types/` existe en début de story
**When** la story est livrée
**Then** le dossier `app/Types/` est supprimé du repo (`ls app/Types` → `No such file or directory`)
**And** aucun fichier orphelin de l'ancien namespace ne subsiste.

### AC2 — `App\LdapModels\AdUser` existe avec l'API préservée

**Given** l'ancien `App\Types\User` est porté
**When** je consulte `app/LdapModels/AdUser.php`
**Then** la classe contient les **mêmes propriétés publiques et méthodes publiques** que l'ancien `App\Types\User` (renommage pur — pas de signature modifiée)
**And** elle vit dans le namespace `App\LdapModels` (cohérent avec `LdapUser`).

### AC3 — `UserSearchCriteria` et `UserSearchResult` relocalisés

**Given** les classes `App\Types\UserSearchCriteria` et `App\Types\UserSearchResult` existaient
**When** la story est livrée
**Then** `App\LdapModels\UserSearchCriteria` et `App\LdapModels\UserSearchResult` existent avec le contenu équivalent
**And** tous les imports `use App\Types\…` sont mis à jour.

### AC4 — Fiche utilisateur sur Eloquent natif

**Given** le composant Livewire `resources/views/pages/users/[login]/index.blade.php`
**When** je consulte la propriété typée
**Then** elle est `public ?App\Models\User $user = null` annotée `#[Locked]`
**And** la propriété `$sqlUserModel` (béquille 7.2) n'existe plus
**And** plus aucune référence à `App\Types\User` ni à `UserService::getByLoginFromSql()` dans ce fichier ni dans ses partials
**And** `Gate::authorize('view-user', $this->user)` opère directement sur l'Eloquent
**And** `loadSpatieState()` lit rôles/permissions directement sur `$this->user` (pas de re-query).

### AC5 — Accessors d'alias sur `App\Models\User`

**Given** `App\Models\User`
**When** une vue ou un test accède à `etabCode`, `etabName`, `objectGuidDisplay`, `isDisabled()`
**Then** les valeurs renvoyées sont strictement équivalentes à `school_code`, `school_name`, `ad_guid`, `!is_active` respectivement
**And** un test unitaire dédié couvre les 4 accessors (cas actif/désactivé pour `isDisabled`, valeurs nulles tolérées pour `etabCode/Name/objectGuidDisplay`).

### AC6 — Dead code retiré du Blade

**Given** `_partials/technical-identifiers.blade.php`
**When** la fiche utilisateur est rendue (tout user, tout rôle)
**Then** les 5 blocs `idEnt / idAaf / idSiecle / idGpei / idNc` ne sont plus présents dans le markup généré
**And** le bloc `objectGuidDisplay` (alimenté par `ad_guid` via accessor) est conservé.

### AC7 — `UserService::getByLoginFromSql()` supprimé

**Given** la méthode `UserService::getByLoginFromSql()` existait (`app/Services/UserService.php:141-171`)
**When** la story est livrée
**Then** la méthode est supprimée du fichier
**And** `grep -rn "getByLoginFromSql" app/ resources/ tests/` ne retourne aucun résultat
**And** les éventuels tests qui mockaient cette méthode sont mis à jour (nouveau pattern `User::factory()->create([...])`).

### AC8 — Plus aucune référence à `App\Types\User`

**Given** la story est livrée
**When** je lance `grep -rn "App\\\\Types\\\\User" app/ resources/`
**Then** la commande ne retourne aucun résultat (hors PHPDoc / commentaires migratoires éventuels explicitement référencés dans le diff de revue)
**And** `grep -rn "use App\\\\Types" app/ resources/ tests/` ne retourne aucun résultat.

### AC9 — Non-régression sur les suites existantes

**Given** la suite de tests baseline avant story
**When** la story est livrée
**Then** les tests suivants passent sans régression :
- `UserPolicyResetPasswordScopedTest` (Story 7.2)
- `UserServiceBulkResetTest` (Story 2.6)
- `PermissionServiceUnionTest` (Story 7.1/7.2)
- la suite Feature globale (`php artisan test --testsuite=Feature`)

**And** aucun test existant n'est désactivé / commenté pour faire passer la story.

---

## Validation manuelle

### Scénario 1 — Scoping classe Prof (Story 7.2 préservé)

**Given** la matrice de droits 7.2 (profA enseigne 3A, eleveA en 3A, eleveB en 3B)
**When** je me connecte en tant que profA et navigue vers la fiche d'eleveA
**Then** la page rend correctement
**And** le bouton "Réinitialiser le mot de passe" est visible (scoping classe OK)
**When** je tente la fiche d'eleveB
**Then** je reçois une 403 (scoping classe OK)

### Scénario 2 — Fiche utilisateur générique

**Given** je suis Administrator
**When** j'ouvre la fiche d'un user quelconque
**Then** la page affiche correctement `etabCode`, `etabName`, `objectGuidDisplay`, le badge "Actif" / "Désactivé"
**And** les sections rendent sans erreur PHP / Livewire dans la console.

### Scénario 3 — Sync LDAP→SQL au login

**Given** un user existant dans l'AD mais pas encore en SQL
**When** ce user se connecte (déclenche `SambaEduAuthGuard::ensureEloquentUser($login, $adUser)`)
**Then** la table `users` est peuplée correctement (le pipeline `LdapUser → AdUser → SQL` continue de fonctionner — le DTO renommé est utilisé exactement comme avant).

---

## Risques

- **R1 — `RequireAdminRights` middleware** utilise le DTO. À vérifier qu'il fonctionne après rename (juste un import à mettre à jour).
- **R2 — `UserResource` API** utilise le DTO : impact sur les consommateurs API à vérifier (compat de signature préservée par le rename pur).
- **R3 — `SambaEduAuthGuard::ensureEloquentUser(string $login, AdUser $adUser)`** est le pivot du sync au login → à tester end-to-end (scénario 3 ci-dessus).
- **R4 — `personal-info-form` `forceFill` Eloquent** : déjà du code Eloquent, pas de risque, mais valider la propagation après bascule.
- **R5 — Caller hors-scope de `getByLoginFromSql`** : un grep préalable doit confirmer la liste des appelants. Si un caller imprévu est découvert, deux options : (a) le migrer dans la même story sur le pattern `User::where('login', …)` (recommandation par défaut) ; (b) si la migration excède le périmètre, escalader au PM avant de poursuivre — ne pas laisser la méthode survivre.

---

## Dépendances

- **Amont (livré)** : Story 7.2 done (la béquille `$sqlUserModel` qu'on retire ici a été introduite par 7.2).
- **Aval** : aucune story bloquée — la stabilisation Spatie/Policies de 7.2 est complète sans 14.1, 14.1 est purement du nettoyage.
- **Pas de migration BDD** : toutes les données utilisées par les pages sont déjà en colonnes SQL (audit confirmé : 5 propriétés du DTO `idEnt/Aaf/Siecle/Gpei/Nc` ne sont jamais populées).

---

## Recommandation Modèle Dev

### Choix : **sonnet** (claude-sonnet-4-6)

### Justification

- Refactor mécanique à périmètre fermé (rename + relocalisation + accessors).
- Pas de décision architecturale inédite : le pattern `App\Models\User` wireable + `#[Locked]` est déjà documenté et utilisé ailleurs ; les accessors Eloquent sont du standard Laravel.
- Les tests existants (`UserPolicyResetPasswordScopedTest`, `UserServiceBulkResetTest`, `PermissionServiceUnionTest`) servent de filet de non-régression.
- Risques bornés et documentés (R1-R5).
- Si le grep préalable AC8 / R5 fait apparaître plus de 3 callers imprévus de `getByLoginFromSql`, le SM ré-arbitre vers opus pour la coordination cross-fichiers — sinon sonnet suffit.
