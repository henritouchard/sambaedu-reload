# Story 2.4 : Affichage du Statut Itinérant

Status: done

## Story

En tant que **responsable de collège**,
je veux voir clairement si un utilisateur est itinérant (pas rattaché à mon établissement),
afin de comprendre immédiatement sa situation sans avoir à inspecter l'AD.

## Contexte

Chaque utilisateur a un établissement de rattachement (UAI extrait du DN). Quand il est synchronisé sur un SER qui n'est pas le sien, il est "itinérant" — mais c'est un utilisateur normal, il a juste un autre établissement d'origine. Il suffit de persister son UAI d'origine (`school_code`) et de comparer avec l'UAI du SER courant pour savoir s'il est d'ici ou pas.

Actuellement `etabCode` existe dans le DTO `User` (extrait par `LdapUser::extractEtablissement()`) mais **n'est pas stocké en SQL**.

## Acceptance Criteria

1. **Persistance de l'établissement d'origine** — Given un utilisateur est synchronisé depuis l'AD, When son UAI est extrait du DN, Then `users.school_code` contient le code UAI de son établissement de rattachement (ex: `0123456A`).

2. **Badge fiche utilisateur** — Given le `school_code` d'un utilisateur diffère de l'UAI du SER courant, When je consulte sa fiche, Then un badge "Externe" est affiché à côté de son nom.

3. **Badge liste utilisateurs** — Given des utilisateurs externes existent, When je consulte la liste, Then un indicateur visuel les identifie, And un filtre "Externe" est disponible.

4. **SER standalone / mono-établissement** — Given aucun UAI n'est configuré pour le SER (ou `school_code = '0'`), Then aucun itinérant n'apparaît.

## Tâches / Sous-tâches

- [x] **Tâche 1 : Migration — ajouter `school_code` à la table `users`** (AC: 1)
  - [x] `$table->string('school_code')->nullable()->default(null);` — code UAI de l'établissement de rattachement

- [x] **Tâche 2 : Persister `school_code` dans le sync** (AC: 1, 4)
  - [x] `LdapUser::extractEtablissement()` retourne déjà l'UAI (ou `'0'` si mono-étab) — passer cette valeur au DTO `AdUser`
  - [x] `upsertUser()` : sauvegarder `school_code` lors du upsert
  - [x] Ajouter `'school_code'` aux `$fillable` de `app/Models/User.php`
  - [x] Ajouter `isExternal(): bool` sur le modèle Eloquent → compare `$this->school_code` avec l'UAI du SER courant (`SEConfig::getCurrentEstablishmentCode()`)

- [x] **Tâche 3 : Propager au DTO `User` (Types\User)** (AC: 2, 3)
  - [x] `etabCode` existe déjà dans le DTO — alimenté depuis `school_code` SQL quand on lit depuis la base
  - [x] Ajout méthode `isExternal()` qui compare `etabCode` avec l'UAI du SER courant
  - [x] `toArray()`, `toLivewire()`, `fromLivewire()` gèrent déjà `etabCode` — pas de modification nécessaire

- [x] **Tâche 4 : Badge sur la fiche utilisateur** (AC: 2)
  - [x] Badge DaisyUI `badge-warning` "Externe" dans `user-header.blade.php` si l'utilisateur n'est pas de cet établissement

- [x] **Tâche 5 : Badge + filtre sur la liste utilisateurs** (AC: 3, 4)
  - [x] Badge `badge-warning` "Externe" dans la ligne de chaque utilisateur externe
  - [x] Ajout `'externe'` dans `statusFilterOptions()` (visible seulement si UAI configuré)
  - [x] Filtrage SQL : `->whereNotNull('school_code')->where('school_code', '!=', '0')->where('school_code', '!=', $currentSchoolCode)`

- [x] **Tâche 6 : Tests unitaires** (AC: 1-4)
  - [x] Test `isExternal()` Eloquent : school_code différent de l'UAI courant → true
  - [x] Test `isExternal()` Eloquent : school_code identique → false
  - [x] Test mono-étab : school_code `'0'` → pas d'externe
  - [x] Test school_code null → pas d'externe
  - [x] Test SER sans UAI → pas d'externe
  - [x] Test DTO `isExternal()` — mêmes cas
  - [x] Test comparaison case-insensitive

## Dev Notes

### Ce qui EXISTE déjà — ne pas recréer

| Composant | Chemin | Ce qu'il fait déjà |
|---|---|---|
| `extractEtablissement()` | `LdapUser.php:207` | Extrait l'UAI depuis le DN — retourne `'0'` si mono-étab |
| `etabCode` / `etabName` | `Types/User.php:35-36` | Déjà dans le DTO mais **PAS en SQL** |
| `SEConfig` / session `etab` | `sync-from-ad/index.blade.php:27` | L'UAI du SER courant est accessible via config ou session |
| Filtres statut | `users/index.blade.php:230` | `active/inactive/trash` — ajouter `itinerant` |

### Ce qui est HORS SCOPE

- Droits différenciés (quota réduit, profil restreint) — n'existe pas dans le legacy, prévu irundoo Phase 2
- Modification manuelle du statut — dérivé de l'AD uniquement
- Home directory — déjà géré par le mécanisme existant de création au login

### References

- [Source: epics.md#Story 2.4] — AC BDD
- [Source: prd.md#FR5] — Statut itinérant + droits différenciés automatiques
- [Source: LdapUser.php:207-223] — `extractEtablissement()` — extraction UAI depuis DN
- [Source: story 2-3] — Patterns filtres statut, scopes Eloquent, badges UI

## Dev Agent Record

### Agent Model Used

claude-opus-4-6

### Debug Log References

- Tests LDAP/infra échouent sur la machine host (pas de serveur AD accessible) — pré-existant, non lié à la story

### Completion Notes List

- Renommage "Itinérant" → "Externe" dans toute l'implémentation (demande utilisateur)
- `school_code` persisté via `LdapUser::toBusinessObject()` appelé dans `ldapUserToAdData()` pour extraire l'UAI
- `isExternal()` ajouté sur le modèle Eloquent ET le DTO Types\User avec la même logique : compare school_code/etabCode avec SEConfig::getCurrentEstablishmentCode()
- Le filtre "Externe" n'apparaît dans la modale filtres que si un UAI est configuré (pas en mono-étab)
- 9 tests unitaires couvrent tous les cas : externe, local, mono-étab, null, case-insensitive

### Change Log

- 2026-03-30 : Story 2.4 implémentée — ajout colonne school_code, persistance sync, badge + filtre "Externe" UI, 9 tests unitaires

### File List

- database/migrations/2026_03_30_100000_add_school_code_to_users_table.php (new)
- app/Models/User.php (modified)
- app/Types/User.php (modified)
- app/Services/UserSyncService.php (modified)
- app/Services/UserService.php (modified)
- resources/views/pages/users/index.blade.php (modified)
- resources/views/pages/users/[login]/_partials/user-header.blade.php (modified)
- tests/Unit/Models/UserExternalTest.php (new)
