# Story 2.6 : Réinitialisation des Mots de Passe en Masse

Status: review

> **Origine :** migration depuis `sambaedu/annu2/pass_user_init.php` + `annu2/reinit_mdp.php` (legacy). Feature critique rentrée scolaire (reset de toutes les classes entrantes) et incidents de sécurité.
> **Socle permissions :** Spatie Permission 6.24 déjà opérationnel (sprint-change-proposal-2026-04-17, §D7). La permission `user.password.init` (`SambaPermission::UserPasswordInit`) est utilisée **directement** — **pas de gate temporaire `SE_USER_ADMIN`**.

## Story

En tant que **responsable de collège (ou enseignant avec délégation)**,
je veux sélectionner plusieurs utilisateurs sur `/users` et réinitialiser leur mot de passe en une seule opération,
afin de gérer la rentrée scolaire (reset toutes les classes entrantes) et les incidents de sécurité sans action individuelle.

## Contexte Legacy

Référence : `sambaedu/includes/ent.inc.php:1408-1531` (`reinit_passwd()` + `reinit_all_passwords()`) et `sambaedu/includes/annu.inc.php:2275` (`pass_user_init_html()`) et `annu.inc.php:43` (`reinit_mdp_html()`).

**Ce que fait le legacy :**
1. Gate `have_right(SE_USER_ADMIN | SE_USER_PASSWORD_INIT)` — bitmask
2. Par utilisateur : calcule le mot de passe selon `pwdPolicy` (0/1/2/3 — date de naissance, random, code d'activation), appelle `usersetpassword()` (AD), force `pwdlastset=0` selon option `change`
3. Affiche un listing HTML clair (login ↔ nouveau mot de passe) pour impression/export
4. Option `force` : reset tous OU uniquement les "non activés" (`pwdlastset == 0`)
5. Deux modes : **ENT** (délégué à l'ENT via `reinit_ent_passwd()`) OU **SambaEdu** (AD local) — **pour cette story, on traite uniquement le mode SambaEdu local (AD)**

**Ce qui manque au legacy et qu'on corrige :**
- Pas de transaction atomique — si un user échoue, les précédents sont quand même changés
- Pas de traçabilité individualisée dans un audit log structuré (RGPD NFR8)
- Export non téléchargeable proprement (HTML à imprimer)
- Pas de scope délégué — gate purement binaire sur bitmask global

## Acceptance Criteria

Tous les ACs proviennent de `_bmad-output/planning-artifacts/epics.md:1309-1336`.

1. **Réinitialisation bulk depuis `/users`** — Given je suis sur `/users` avec la permission `user.password.init` (droit global Spatie ou délégation scopée à mon périmètre), When je sélectionne N utilisateurs via les checkbox multi-sélection existantes et déclenche "Réinitialiser les mots de passe", Then un nouveau mot de passe est généré pour chacun selon la politique `pwdPolicy` configurée (longueur, classes de caractères) via `PasswordService::generateRandomPassword()`, And les mots de passe sont appliqués dans l'AD local via LdapRecord **en premier**, puis reflétés dans PostgreSQL (flag `pwd_reset_at` ou équivalent), And un export téléchargeable (PDF et/ou CSV) est proposé immédiatement avec la liste `login ↔ nouveau mot de passe`, And l'export est généré **une seule fois** côté serveur (non rejouable — les mots de passe ne sont PAS persistés en base et disparaissent après la génération de l'export).

2. **Refus hors périmètre — 403 + rollback** — Given je tente de réinitialiser des utilisateurs hors de mon périmètre de délégation (ex: un admin scopé à un établissement tente de toucher des users d'un autre établissement), When la requête est traitée par le Service, Then l'action est refusée avec un message explicite (équivalent 403 — toast d'erreur), And **aucun utilisateur de la sélection n'est modifié** (transaction atomique — validation préalable de tout le lot avant toute écriture AD).

3. **Échec LDAP/AD — rollback complet** — Given une réinitialisation échoue pour un utilisateur (AD indisponible, LDAP verrouillé, compte désactivé, exception LdapRecord), When l'erreur est détectée en cours de bulk, Then la transaction entière est annulée — **aucun utilisateur partiellement réinitialisé** ne reste en état modifié, And l'erreur est affichée via `WithToasts` en identifiant le ou les utilisateurs concernés, And l'administrateur peut relancer l'action après correction (idempotence : la relance ne pose aucun problème si la 1ère a été correctement rollback).

4. **Audit trail individualisé (NFR8 — RGPD)** — Given une réinitialisation bulk a été exécutée avec succès, When l'audit trail est consulté (`Log::info` structuré + table `pwd_reset_audit` si créée), Then **chaque réinitialisation est loggée individuellement** avec `timestamp`, `target_login`, `operator_login`, `scope` (établissement/groupe/global), `bulk_operation_id` (UUID partagé pour tracer le lot), And les nouveaux mots de passe **NE SONT PAS stockés en clair dans les logs** — seul le fait de la réinitialisation et son résultat (succès/échec) est tracé.

5. **Option "forcer changement à la prochaine connexion"** — Given les utilisateurs doivent changer leur mot de passe à la première connexion, When je configure l'option "forcer changement à la prochaine connexion" sur le bulk (checkbox dans la modale), Then le flag `pwdlastset` est positionné à `0` dans l'AD pour chaque utilisateur (réutilise la logique `changePasswordInAd` existante avec `mustChangeAtNextLogin = true`).

6. **UX cohérente avec `/users`** — Given je suis sur `/users` avec au moins un utilisateur sélectionné, When je déplie le menu dropdown "Actions" existant (pattern déjà en place dans `resources/views/pages/users/index.blade.php` lignes 554-569, à côté de "Gérer les groupes"), Then une entrée "Réinitialiser les mots de passe" apparaît **uniquement si `@can('user.password.init')`** est vrai, And le clic ouvre une modale réutilisable (`resources/views/components/molecules/confirm-modal.blade.php` ou nouvelle modale dédiée) demandant confirmation + l'option "forcer changement à la prochaine connexion", And la génération déclenche un téléchargement immédiat du fichier PDF/CSV (non stocké côté serveur), And un toast de succès (`WithToasts::toastSuccess`) résume le nombre d'utilisateurs traités.

7. **Sélection par groupes (idempotent legacy — membres directs uniquement)** — Given je suis sur `/users` onglet **Groupes** avec droit `user.password.init`, When je sélectionne N groupes (checkbox multi-sélection) et déclenche "Réinitialiser les mots de passe", Then le système résout les **membres directs** (non récursif) de chaque groupe via l'AD (équivalent `search_people_group recurse=false` — cf. `sambaedu/includes/ldap.inc.php:5872`), dédoublonne par login AD, valide l'existence Eloquent (sync si nécessaire) et applique la réinit sur les users dédupliqués. And Given je suis sur la **page d'un groupe** `/users/groups/{id}`, When je déclenche "Réinitialiser les mots de passe" depuis le menu action, Then même flux sur ce groupe unique. And Given je suis sur la **page d'un utilisateur** `/users/{login}`, When je déclenche "Réinitialiser le mot de passe" depuis le dropdown action, Then flux unitaire (1 user). And Given je combine sélection users + groupes dans la même opération, Then fusion dédupliquée par login AD avant traitement. And Given parmi les membres résolus **un seul** est hors périmètre de délégation, Then l'action est refusée 403 avec identification précise des users bloquants, **aucun** user n'est modifié (transaction atomique — idem AC 2).

8. **Options legacy conservées (idempotence utilisateur)** — Given je suis dans la modale de réinit bulk, When j'active l'option `force` "réinitialiser tous les utilisateurs sélectionnés" (par défaut) vs "réinitialiser uniquement les utilisateurs non activés" (filtre `pwdLastSet==0` — équivalent legacy), Then le lot effectivement réinitialisé respecte ce filtre. And Given je sélectionne l'option `change` "fixer un code d'activation à changer à la prochaine connexion" (coché par défaut) vs "imposer un mot de passe définitif (DANGER)", Then le flag `pwdlastset` est positionné à `0` (changement forcé) ou `-1` (mdp définitif) en conséquence, And un avertissement visuel explicite s'affiche dans la modale quand l'option "mot de passe définitif" est cochée (texte rouge + icône danger).

9. **Flash toast + non-rejouabilité du listing (TTL 20 min)** — Given une réinit bulk réussit, When le traitement est terminé côté serveur, Then un **toast success étendu `WithToasts`** s'affiche avec durée sticky ou 60 s minimum, contenant le message `"N mots de passe réinitialisés. Téléchargez le fichier maintenant — expire dans 20 min."` + **2 liens "Télécharger PDF" et "Télécharger CSV"**. And Given le toast est manqué (navigation, fermeture), Then un **bandeau persistant** reste affiché sur `/users` tant que le listing existe en cache serveur (TTL 20 min restants affichés). And Given je clique sur PDF ou CSV, Then le fichier est téléchargé ET le listing reste accessible pour l'autre format pendant la TTL (**les deux formats sont autorisés avant purge**). And Given la TTL de 20 minutes expire sans clic, Then le listing est **purgé automatiquement** côté serveur, le bandeau disparaît, aucune récupération possible. And Given le listing est en cours de vie, Then il est stocké **hors `$_SESSION` / session PHP** (cache serveur type Redis avec token signé court TTL) pour éviter toute persistance involontaire et permettre purge déterministe. And Given je déclenche une nouvelle réinit bulk alors qu'un listing précédent existe encore, Then le listing précédent est **immédiatement purgé** avant génération du nouveau (un seul listing actif par opérateur).

10. **Format PDF — cartouches multi-users (reprise legacy)** — Given un export PDF est demandé, When la vue est rendue via `spipu/html2pdf`, Then le document respecte le format legacy `generate_listing_pdf` (`sambaedu/includes/ent.inc.php:6373-6441`) : **multi-users par page** (pas 1 page par user) sous forme de cartouches encadrés, saut de page sur changement d'établissement puis de classe, tri `établissement → classe → nom → prénom` (équivalent `trieleve()`). And chaque cartouche contient : nom + prénom, établissement, classe, identifiant AD (+ identifiant ENT original si différent), mot de passe OU code d'activation (selon option `change`), mention légale RGPD. And les **fonts dyslexie-friendly** sont embarquées (OpenDyslexic-Regular/Bold, mononoki-Regular/Bold, LexicaUltralegible-Regular — copiées depuis `sambaedu/elements/fonts/` vers `resources/fonts/` une seule fois). And le format est A4 portrait.

11. **Audit trail enrichi — traçabilité `source_group`** — Given un user a été réinitialisé dans le cadre d'un bulk, When l'audit est écrit (log structuré ou table `password_reset_audits`), Then chaque entrée contient les champs additionnels `source_group_id` (null si sélection user directe, rempli si résolu via groupe) et `source_group_name`, afin de pouvoir répondre à la question "ce mdp a été reset parce que la classe 6A a été reset en bulk". And Given un même user est résolu depuis plusieurs groupes dans le même bulk (après dédup), Then l'audit reflète le premier `source_group_id` qui l'a ramené (pas de doublon d'écriture, conservation du chemin d'entrée initial).

## Tasks / Subtasks

### Phase A — Service `bulkResetPasswords` (backend)

- [x] **Tâche 1 : Créer la méthode `UserService::bulkResetPasswords()`** (AC: 1, 2, 3, 5)
  - [x] Signature finale : `bulkResetPasswords(array $selection, bool $force = true, bool $forceChangeAtNextLogin = true): array` (signature étendue dès la première itération pour accepter groupes + users — fusionne tâches 1 et 7b).
  - [x] Phase validation (Gate + résolution AD + scope établissement) avant toute écriture
  - [x] Phase génération (PasswordService) en mémoire uniquement
  - [x] Phase écriture atomique (DB::beginTransaction/commit/rollBack + pwd_reset_at)
  - [x] Retour structuré avec `bulk_operation_id` UUID + `results[]` + `partial_failures[]`
  - [x] Invalidation cache AD via `userRepository->invalidateCache()`

- [x] **Tâche 2 : Audit trail structuré (AC 4 — NFR8 RGPD)**
  - [x] Log `audit.user.password.reset` par user (sans mdp clair, avec `password_length` stat)
  - [x] Log `audit.user.password.reset.bulk.start` + `.bulk.end`
  - [x] Log `audit.user.password.reset.denied` (permission / unresolved / scope)
  - [x] Log `audit.user.password.reset.partial_failure` documentant l'asymétrie AD

### Phase B — Export PDF/CSV non rejouable

- [x] **Tâche 3 : Générer l'export en mémoire (AC 1)** — `App\Services\PasswordResetExportService`
  - [x] Méthode `generateExport(array $results, string $format = 'pdf', array $options): Response`
  - [x] CSV : `fputcsv ;` + BOM UTF-8, colonnes alignées legacy (login;lastName;firstName;email;structure;allClasses;activated;code)
  - [x] PDF : `spipu/html2pdf` via vue Blade `exports/password-reset.blade.php`
  - [x] Headers : `Content-Disposition: attachment`, `Cache-Control: no-store, no-cache, must-revalidate`, `X-Robots-Tag: noindex`
  - [x] Aucun fichier persistant sur disque (streamed response ou blob mémoire)

### Phase C — Intégration UI `/users`

- [x] **Tâche 4 : Entrée "Réinitialiser les mots de passe" dans le dropdown Actions** (AC 6)
  - [x] `resources/views/pages/users/index.blade.php` — nouvelle `<li>` avec `@can('user.password.init')` dans le dropdown des utilisateurs sélectionnés
  - [x] Également ajouté dans le dropdown des groupes sélectionnés
  - [x] Déclenche `Livewire.dispatch('open-password-reset-modal', { users, groups })`

- [x] **Tâche 5 : Modale Livewire SFC `password-reset-modal`** (AC 5, 6, 7, 8)
  - [x] `resources/views/components/organisms/password-reset-modal.blade.php`
  - [x] SFC avec `use WithToasts;` + `#[On('open-password-reset-modal')]`
  - [x] Props : `$targetLogins`, `$targetGroupIds`, `$forceChangeAtNextLogin`, `$onlyNonActivated`, `$exportFormat`, `$isOpen`, `$isProcessing`
  - [x] UI : titre dynamique, liste des logins (max 10 + "...et X autres"), options `force` + `change` avec warning rouge sur mdp définitif, radio format PDF/CSV, note info rollback AD asymétrique
  - [x] `performReset()` : Gate check + bulkResetPasswords + storeListing + dispatch + ouverture onglet téléchargement

- [x] **Tâche 6 : Route de téléchargement** — Option retenue : routes dédiées avec token signé + cache Redis chiffré (cf. Tâche 7d) — meilleur compromis sécurité / UX que l'option Livewire en mémoire vive.

- [x] **Tâche 7 : Inclusion de la modale dans `/users/index.blade.php`**
  - [x] `<livewire:components::organisms.password-reset-modal />` ajouté
  - [x] `<livewire:components::organisms.password-reset-banner />` ajouté pour le bandeau persistant

### Phase C-bis — Sélection par groupes + listing TTL + bandeau persistant

- [x] **Tâche 7a : `UserGroupService::getDirectMembersForBulkReset(array $groupIds)`** (AC 7)
  - [x] Retourne `['users' => Collection<User>, 'login_to_source_group' => [login => [id, name]]]`
  - [x] Non-récursif (membres directs uniquement) — idempotent legacy
  - [x] Dédup par login AD ; conserve le PREMIER `source_group_id` (ordre d'itération)
  - [x] Résilient aux exceptions par groupe (log + skip, les autres continuent)
  - [x] Users AD absents de SQL : skip silencieux (pas de sync inline pour éviter contention LDAP)

- [x] **Tâche 7b : Signature `bulkResetPasswords` mixte** — fusionnée avec Tâche 1

- [x] **Tâche 7c : `BulkResetListingService`** (AC 9)
  - [x] Stockage `Cache::put($key, Crypt::encrypt(...), 1200)` — clé `pwd_reset_listing:{operator_id}:{uuid}`
  - [x] Index token→operator pour fetchListing par token seul
  - [x] Index opérateur→token pour contraindre 1 seul listing actif
  - [x] Méthodes : `storeListing`, `fetchListing`, `purgeListing`, `purgePreviousForOperator`, `hasActiveListingForOperator`, `getActiveListingMeta`, `buildSignedUrl`
  - [x] `storeListing` purge systématiquement le précédent de l'opérateur

- [x] **Tâche 7d : Routes de téléchargement signées** (AC 9, 10)
  - [x] `GET /app/users/password-reset/{token}/pdf` et `.../csv` — middleware `signed`
  - [x] `PasswordResetExportController` — fetch + generate + 410 Gone si expiré
  - [x] Pas de purge sur premier téléchargement (les 2 formats restent accessibles avant TTL)
  - [x] Vue d'erreur `resources/views/errors/password-reset-expired.blade.php`

- [x] **Tâche 7e/7f : Modales users + groupes** — fusionnées dans `password-reset-modal` unique (prop mixte `users` + `groups`). Simplicité architecturale + réutilisation maximale.

- [x] **Tâche 7g : Action sur page user unique** (`/users/{login}`)
  - [x] Nouvelle entrée dropdown avec `@can('user.password.init')`
  - [x] Flux unitaire via `bulkResetPasswords(['userIds' => [$login], 'groupIds' => []])`
  - [x] Inclusion du composant `password-reset-modal` sur la page

- [x] **Tâche 7h : Extension `WithToasts`** — méthode `toastSuccessWithActions(message, links, sticky, duration)` ajoutée sans casser l'API existante

- [x] **Tâche 7i : Bandeau persistant** — `password-reset-banner.blade.php` SFC
  - [x] `wire:poll.30s="refreshStatus"` pour rafraîchir TTL restant
  - [x] Affiche nombre d'utilisateurs, TTL human-readable, liens PDF + CSV, bouton "Purger"
  - [x] Auto-masqué quand aucun listing actif

- [x] **Tâche 7j : Fonts dyslexie-friendly** — copiées dans `resources/fonts/` : OpenDyslexic-Regular.ttf, OpenDyslexic-Bold.ttf, mononoki-Regular.ttf, mononoki-Bold.ttf, LexicaUltralegible-Regular.ttf

- [x] **Tâche 7k : Vue PDF `exports/password-reset.blade.php`**
  - [x] Regroupement par établissement puis par classe
  - [x] Saut de page (`<pagebreak>`) sur changement d'établissement puis de classe
  - [x] Cartouches encadrés 2-par-ligne en grille, mention RGPD en en-tête + pied de cartouche
  - [x] Tri en amont dans `PasswordResetExportService::generatePdf`

### Phase D — Tests

- [x] **Tâche 8 : `tests/Unit/Services/UserServiceBulkResetTest.php`** — 7 tests verts (23 assertions)
  - [x] `bulkResetPasswords_refuses_without_permission`
  - [x] `bulkResetPasswords_refuses_when_user_not_found_in_ad` (équivalent "rien n'est changé")
  - [x] `bulkResetPasswords_refuses_out_of_scope`
  - [x] `bulkResetPasswords_returns_empty_when_selection_empty`
  - [x] `bulkResetPasswords_logs_without_password_clear`
  - [x] `bulkResetPasswords_success_returns_results_array`
  - [x] `bulkResetPasswords_rollback_on_ldap_failure`

- [x] **Tâche 9 + 10 : `tests/Feature/Users/BulkPasswordResetTest.php`** — 8 tests verts
  - [x] Export CSV : colonnes + BOM UTF-8 + aucune persistance dans storage/
  - [x] Export PDF : `Content-Type: application/pdf` + headers no-store
  - [x] Listing service : storeListing/fetchListing/purge
  - [x] Assert session PHP ne contient pas le mdp clair
  - [x] Expired token → 410 Gone
  - [x] Unsigned URL → rejet middleware

- [x] **Tâche 11 : `tests/Feature/Users/BulkPasswordResetGroupsTest.php`** — 5 tests verts
  - [x] Dédup login si user présent dans plusieurs groupes (source_group = premier groupe)
  - [x] Users AD absents de SQL → skippés
  - [x] Liste vide → collection vide
  - [x] Groupe AD manquant → skip silencieux
  - [x] Exception AD sur un groupe → les autres continuent

- [x] **Tâche 11 : `tests/Feature/Users/BulkPasswordResetListingTtlTest.php`** — 8 tests verts
  - [x] Listing hors session PHP (grep négatif sur mdp clair)
  - [x] 2 téléchargements PDF+CSV avant TTL : listing reste accessible
  - [x] Purge manuelle → cache vide
  - [x] Nouveau bulk → listing précédent purgé
  - [x] `Cache::flush()` → fetchListing retourne null (simule expiration)
  - [x] URL non signée → rejet middleware `signed`
  - [x] Un seul listing actif par opérateur
  - [x] Meta expose pdf_url + csv_url signés

## Dev Notes

### Fichiers clés du codebase à réutiliser

| Composant | Chemin | Usage pour cette story |
|---|---|---|
| `UserService::changePasswordInAd()` | `app/Services/UserService.php:578-612` | Pattern de référence pour set mdp + `pwdlastset` + cache invalidation |
| `UserService::resetPasswordInAd()` | `app/Services/UserService.php:617-639` | Version unitaire existante — `bulkResetPasswords()` est l'équivalent bulk avec transaction |
| `UserService::setUserPassword()` | `app/Services/UserService.php:823-840` | Encodage `unicodepwd` (LdapRecord auto UTF-16LE) |
| `PasswordService::generateRandomPassword()` | `app/Services/PasswordService.php:182-203` | Respecte `pwdPolicy` (0/1/2/3), longueur, complexité |
| `PasswordService::getPolicy()` | `app/Services/PasswordService.php:131-175` | Description humaine de la politique à afficher dans la modale |
| `SambaPermission::UserPasswordInit` | `app/Enums/SambaPermission.php:13` | Valeur : `'user.password.init'` — à utiliser dans `@can()` et `$user->can()` |
| `PermissionService::canOnWorkstationGroup()` | `app/Services/PermissionService.php:191-218` | **Pattern** de vérification de scope délégué — adapter si un jour on scope par établissement (pour l'instant pas de `canOnEstablishment`) |
| `WithToasts` trait | `app/Components/Traits/WithToasts.php` | `toastSuccess`, `toastError`, `toastAccessDenied` |
| `confirm-modal` molecule | `resources/views/components/molecules/confirm-modal.blade.php` | Alternative simple si une modale dédiée n'est pas nécessaire |
| `groups-drawer` organism | `resources/views/components/organisms/groups-drawer.blade.php` | Pattern Livewire SFC drawer/modale avec `#[On(...)]` listener + `targetUsers` array |
| `rights-drawer` organism | `resources/views/components/organisms/rights-drawer.blade.php` | Pattern `Gate::allows(...)` + boot services privés |
| `/users` index.blade.php | `resources/views/pages/users/index.blade.php:554-569` | Dropdown "Actions" existant — y insérer la nouvelle entrée |
| `ImportExportService::writeCSV()` | `app/Services/ImportExportService.php:189-210` | Pattern d'export CSV avec `fputcsv` + séparateur `;` |
| `ShortcutExportController` | `app/Http/Controllers/Api/v1/ShortcutExportController.php:155,177` | Pattern `Content-Disposition: attachment` pour téléchargement |
| `UserServiceUpdateTest` | `tests/Unit/Services/UserServiceUpdateTest.php` | Pattern de setUp avec mocks complets des 7 dépendances de `UserService` |

### Ne PAS réinventer (réutilisation obligatoire)

- **Ne pas réécrire** `changePasswordInAd` — extraire la partie LDAP dans une méthode privée partagée si besoin
- **Ne pas réécrire** `PasswordService::generateRandomPassword()` — il respecte déjà `pwdPolicy`
- **Ne pas créer** une nouvelle molécule confirm-modal — utiliser l'existante (`molecules/confirm-modal.blade.php`) ou créer un organism dédié (pattern `shortcut-assignment-modal.blade.php`)
- **Ne pas** ajouter une route GET pour le téléchargement — préférer l'approche in-memory/Livewire (non-rejouable)
- **Ne pas** persister les mots de passe en base (AUCUNE colonne ni JSON) — c'est le point critique de sécurité

### Architecture compliance

- **Règle absolue** : les appels LDAP (`LdapUser::save`, `unicodepwd`) **doivent** rester dans `UserService` — jamais dans le composant Livewire (cf. `architecture.md:342-346`)
- **Double-write AD → PostgreSQL** (AD = source de vérité MVP, cf. `architecture.md:347-352`) — ici, on persiste uniquement un flag `pwd_reset_at` et/ou l'horodatage, jamais le mot de passe
- **Transaction Eloquent** : `DB::transaction(fn() => …)` pour les écritures PostgreSQL. L'AD n'a pas de transaction native — on documente cette limite (voir Tâche 1 pour la stratégie de validation préalable)
- **Livewire SFC** : privilégier les single-file components (composant blade avec `new class extends Component`) — cf. pattern `users/index.blade.php` et `users/[login]/index.blade.php`

### Permission — nom exact vérifié

**Nom de permission retenu : `user.password.init` (enum case `SambaPermission::UserPasswordInit`)**

Vérification effectuée dans `app/Enums/SambaPermission.php:13` — la valeur est bien `'user.password.init'`. L'alias `passwords.reset.bulk` mentionné dans epics.md (ligne 1303 et 1311) **n'existe pas** dans le code — c'est un nom informel utilisé dans la doc produit. Cette story fixe définitivement le nom `user.password.init` comme référence technique.

Le rôle `SambaRole::UserAdmin` (`app/Enums/SambaRole.php:58-67`) et `SambaRole::EleveAdmin` (ligne 49-53) possèdent déjà cette permission.

### Scope délégué — arbitrage

Le code actuel possède `PermissionService::canOnWorkstationGroup()` pour les délégations scopées par `WorkstationGroup`. **Il n'existe pas aujourd'hui de `canOnEstablishment()` ni `canOnUserGroup()`.**

**Pour cette story, la règle simple et livrable est :**
- Permission globale `user.password.init` → peut reset n'importe quel user (vérifier quand même qu'il n'est pas hors de la sphère `etab_ou` de l'opérateur si configuré — cf. filtre existant `getCurrentEstablishmentCode`)
- **Pas de délégation par workstation group** pour cette permission (la catégorie `user` n'est pas `isDelegatable()` cf. `SambaPermission::isDelegatable()` ligne 123-126)

**Conséquence AC 2 (hors scope) :** le refus 403 s'appuie sur **l'appartenance établissement** — si l'opérateur est scopé à un établissement (via `SEConfig::getCurrentEstablishmentCode()`), refuser toute cible hors de cet établissement. À documenter dans le code comme extension future vers un vrai `PermissionService::canOnEstablishment()`.

### Limitation documentée — rollback AD

L'AD ne permet PAS de restaurer un hash de mot de passe précédent. La stratégie "tout ou rien" d'AC 3 est donc **asymétrique** :
- **Côté PostgreSQL** : `DB::transaction()` garantit le rollback complet
- **Côté AD** : **validation préalable** complète (toutes les cibles existent + opérateur a le droit sur chacune + aucune cible n'est désactivée) AVANT la première écriture LDAP. Si une écriture échoue malgré tout, les users déjà modifiés **gardent leur nouveau mot de passe** — un log d'alerte `audit.user.password.reset.partial_failure` est émis avec la liste des users déjà modifiés, et le message utilisateur (`toastError`) liste les users pour lesquels l'opérateur doit relancer une action corrective.

**Cette limite doit être affichée dans la modale** (infobulle ou note) avant la confirmation, pour éviter toute surprise de l'opérateur.

### Dépendances

- **Story 2-1** (done) : `UserService::createUser()`, `persistUserToSql()`, patterns LDAP établis
- **Story 2-2** (done) : `UserService::updatePersonalInfo()`, pattern écriture AD → SQL
- **Story 2-5** (review) : pattern transaction atomique partielle + gestion erreurs partielles (`changeUserRole`)
- **Epic 7 Spatie socle** (7-1 in-progress, 7-2 in-progress, **socle livré suffisant**) : `SambaPermission::UserPasswordInit`, `UserPolicy`, `ChecksPermissions` trait, `@can()` dans Blade — tout est opérationnel
- **Aucune story bloquante** — cette story peut démarrer immédiatement

### Données & Modèles

- **Pas de nouvelle migration obligatoire** — l'audit trail passe par `Log::info('audit.user.password.reset', …)` sur le channel par défaut
- **Option** : créer une table `password_reset_audits` (bulk_operation_id, target_login, operator_login, operator_id, scope, forced_change, success, error_message, created_at) si Henri veut un audit queryable en base plutôt que dans les fichiers de log. **À confirmer au début du dev** — si oui, migration dédiée ; sinon, logs structurés suffisants.
- **Pas de colonne `pwd_reset_at` obligatoire** sur `users` — optionnelle, à ajouter si on veut afficher "dernier reset il y a X jours" dans la fiche user

### UX — notes de design

- Le mot de passe clair est affiché dans l'export uniquement — **jamais** dans l'UI Livewire après la modale (pas de liste à l'écran, juste le téléchargement déclenché)
- Pour un gros volume (500+ users), envisager un job queue + polling — **hors scope de cette story**. À cap à ~200 users simultanés en synchrone (raisonnable pour une classe entière x 2).
- La bannière PDF doit inclure : date, nom opérateur, établissement, avertissement RGPD ("mots de passe à distribuer en main propre, détruire ce document après distribution")

### Sélection par groupes — idempotence legacy stricte

Le legacy `reinit_mdp.php` → `reinit_mdp_html()` (`sambaedu/includes/annu.inc.php:43`) propose une sélection **par groupes** (classes, équipes, matières, autres) via `affiche_all_groups()`, et résout les membres via `list_members_group()` → `search_people_group($config, $groupsam, $recurse = false)` (`sambaedu/includes/ldap.inc.php:5872`).

**Point critique :** le paramètre `recurse = false` signifie que **seuls les membres directs** du groupe sont ramenés — si un group AD contient un sous-group, les users du sous-group ne sont PAS résolus. **Décision produit : on reste strictement idempotent legacy** — même comportement, pas de récursion automatique. Documentation explicite dans le code + test dédié (cf. Tâche 11).

### Stockage du listing — hors session PHP, chiffré + token signé

**Contrainte sécurité :** le listing temporaire contient des **mots de passe en clair** associés à des logins. Il ne doit **jamais** être stocké dans `session()->put()` ni `$_SESSION` (risque de persistance involontaire au-delà du besoin, fuite inter-tests — cf. mémoire projet `feedback_session_leak_tests.md`).

**Stratégie retenue :**
- Cache serveur (Redis via `Cache::driver('redis')`) avec clé nommée `pwd_reset_listing:{operator_id}:{uuid}`
- Contenu chiffré via `Crypt::encrypt()` (chiffrement at-rest)
- TTL 1200 s (20 min) strict — aligné legacy `cache_store('listing_' . session_id(), $listing, 1200)`
- Accès uniquement via **token signé** (Laravel `URL::temporarySignedRoute()`) — l'URL embarque la signature, invalidation automatique si trafiquée
- Purge déterministe : TTL expiré OU nouvelle réinit OU bouton "Purger maintenant" du bandeau

Un seul listing actif par opérateur (purge du précédent avant stockage du nouveau) — simplifie la UX et limite la surface d'exposition.

### Fonts dyslexie-friendly — copie unique depuis legacy

Les fonts `OpenDyslexic`, `mononoki`, `LexicaUltralegible` présentes dans `sambaedu/elements/fonts/` sont **copiées une seule fois** vers `resources/fonts/` puis deviennent ressource Laravel propre. **Ne pas** les référencer en runtime depuis le legacy (le path legacy n'est pas garanti en prod Laravel). Le script de copie est idempotent (vérifier l'existence avant copie dans les seeds / commande de provisioning éventuelle).

### Format PDF — reprise du pattern legacy `generate_listing_pdf`

Référence : `sambaedu/includes/ent.inc.php:6373-6441` (`generate_listing_pdf()`). Points clés à reproduire :
- **Cartouches multi-users par page** (densité ~6-8 cartouches par page A4 portrait)
- Saut de page (`<pagebreak>` html2pdf) sur changement d'**établissement** puis de **classe**
- Tri `trieleve()` : structure > classe > nom > prénom
- Contenu cartouche : nom + prénom, établissement, classe, identifiant AD (+ identifiant ENT original si différent), mdp OU code d'activation (selon option `change`), mention RGPD compacte en pied de cartouche
- Mention RGPD en en-tête de document : date, opérateur, avertissement "à distribuer en main propre, détruire après distribution"

### CSV — colonnes legacy préservées

Référence : `sambaedu/includes/ent.inc.php:6358` (`generate_listing_csv()`). Colonnes : `login;lastName;firstName;email;structure;allClasses;activated;code` (séparateur `;`, UTF-8 avec BOM pour Excel FR).

### Audit enrichi — `source_group_id` pour traçabilité

Chaque entrée d'audit (`Log::info('audit.user.password.reset', ...)` ou table `password_reset_audits`) contient désormais :
- `source_group_id` : id du groupe AD qui a ramené ce user (null si sélection user directe)
- `source_group_name` : nom du groupe pour lisibilité human des logs

Cas multi-groupes (un user résolu depuis 2 groupes après dédup) : on conserve le **premier** `source_group_id` qui l'a ramené (ordre d'itération déterministe sur `$selection['groupIds']`). Documenté dans le code.

### Contraintes transverses à retenir

- **Rollback AD asymétrique** (AC 3) — validé et documenté, inchangé
- **Validation préalable exhaustive** avant toute écriture AD — inchangé
- **Mdp clair jamais en log** — inchangé, étendu à : **jamais en DB, jamais en session PHP par défaut, uniquement dans le cache Redis chiffré à durée de vie bornée**
- **Périmètre délégation** : refus 403 global sur un seul user hors scope — étendu aux users résolus via groupe (un groupe contenant un seul user hors scope → refus complet)

### Project Structure Notes

Structure créée/modifiée conforme au filesystem-based routing Laravel+Livewire SFC :
- Les SFC restent dans `resources/views/components/organisms/` et `resources/views/pages/users/`
- Le service reste dans `app/Services/UserService.php` (méthodes ajoutées) ou un helper dédié `app/Services/PasswordResetExportService.php`
- Les tests reflètent la convention : `tests/Unit/Services/UserServiceBulkResetTest.php` + `tests/Feature/Users/BulkPasswordResetTest.php` (créer le dossier `Users/` si absent)

Aucun conflit détecté avec la structure unifiée.

## Files

### Créés (anticipés)

- `app/Services/PasswordResetExportService.php` — service de génération de l'export PDF/CSV en mémoire (ou méthode dans `UserService` si peu de code)
- `app/Services/BulkResetListingService.php` — gestion stockage cache Redis + token signé + TTL 20 min + purge manuelle/auto (un seul listing actif par opérateur)
- `resources/views/components/organisms/password-reset-modal.blade.php` — Livewire SFC modale dédiée (onglet Utilisateurs + page user unique)
- `resources/views/livewire/users-bulk-reset-modal.blade.php` — SFC Livewire tab Utilisateurs (si séparation visée ; sinon fusion avec `password-reset-modal.blade.php`)
- `resources/views/livewire/groups-bulk-reset-modal.blade.php` — SFC Livewire tab Groupes + page groupe
- `resources/views/exports/password-reset.blade.php` — template Blade rendu en PDF via `spipu/html2pdf` (cartouches multi-users, sauts de page conditionnels)
- `resources/fonts/OpenDyslexic-Regular.ttf` — font dyslexie-friendly (copie depuis `sambaedu/elements/fonts/`)
- `resources/fonts/OpenDyslexic-Bold.ttf`
- `resources/fonts/mononoki-Regular.ttf`
- `resources/fonts/mononoki-Bold.ttf`
- `resources/fonts/LexicaUltralegible-Regular.ttf`
- `tests/Unit/Services/UserServiceBulkResetTest.php` — tests unitaires du service
- `tests/Feature/Users/BulkPasswordResetTest.php` — tests E2E/Livewire (flux users)
- `tests/Feature/Users/BulkPasswordResetGroupsTest.php` — résolution groupes directe non-récursive + dédup + audit enrichi
- `tests/Feature/Users/BulkPasswordResetListingTtlTest.php` — TTL + purge + 2 formats avant expiration + token signé
- *(optionnel)* `database/migrations/2026_04_17_XXXXXX_create_password_reset_audits_table.php` — si audit en base retenu (colonnes incluent `source_group_id`, `source_group_name`)

### Modifiés (anticipés)

- `app/Services/UserService.php` — ajout de `bulkResetPasswords(array $selection, bool $force, bool $forceChangeAtNextLogin)` (signature étendue pour users + groupes) + refactor éventuel de `resetPasswordInAd()`
- `app/Services/UserGroupService.php` — ajout de `getDirectMembersForBulkReset(array $groupIds): Collection` (résolution AD non-récursive + dédup + sync Eloquent si besoin)
- `app/Components/Traits/WithToasts.php` — support `links`, `sticky`, `duration` (si pas déjà là) ou nouvelle méthode `toastSuccessWithActions`
- `resources/views/pages/users/index.blade.php` — entrée "Réinitialiser les mots de passe" dans le dropdown Actions (lignes 554-569) + inclusion du composant `password-reset-modal` + bandeau persistant si listing actif
- `resources/views/pages/users/groups/index.blade.php` — dropdown bulk sur tab Groupes + entrée "Réinitialiser les mdp" avec `@can('user.password.init')`
- `resources/views/pages/users/groups/{id}/index.blade.php` — action "Réinitialiser les mdp du groupe" dans le menu action (groupe unique)
- `resources/views/pages/users/{login}/index.blade.php` — action "Réinitialiser le mot de passe" dans le dropdown action page user unique
- `routes/web.php` — routes `GET /users/password-reset/{token}/pdf` + `GET /users/password-reset/{token}/csv` (middleware `signed`)
- *(optionnel)* `app/Models/User.php` — ajout de `pwd_reset_at` dans `$casts` / `$fillable` si la colonne est créée

### References

- [Source: _bmad-output/planning-artifacts/epics.md:1300-1338] — ACs BDD complets de la story 2.6
- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-04-17.md:82-102] — décision Spatie socle livré + passage direct à `user.password.init`
- [Source: _bmad-output/planning-artifacts/idempotency.md:56,160,194,297] — contexte `annu2/pass_user_init.php` → Epic 2 (deferred from 1bis-12)
- [Source: _bmad-output/planning-artifacts/architecture.md:342-352] — règle absolue Services, double-write AD → PostgreSQL
- [Source: _bmad-output/planning-artifacts/prd.md:142] — RGPD, conservation / suppression
- [Source: _bmad-output/planning-artifacts/prd.md:362] — NFR8 logs d'actions sensibles horodatés
- [Source: sambaedu/includes/ent.inc.php:1408-1531] — fonctions legacy `reinit_passwd()` + `reinit_all_passwords()` (référence comportementale)
- [Source: sambaedu/includes/annu.inc.php:43-300, 2275-2294] — UI legacy `reinit_mdp_html()` + `pass_user_init_html()` (référence UX)
- [Source: app/Enums/SambaPermission.php:13] — permission exacte `user.password.init`
- [Source: app/Services/UserService.php:578-639] — méthodes unitaires existantes de reset/change password
- [Source: app/Services/PasswordService.php:131-203] — politique + génération mot de passe
- [Source: _bmad-output/implementation-artifacts/2-1-creation-et-provisioning-dun-compte-utilisateur.md] — pattern story Epic 2
- [Source: _bmad-output/implementation-artifacts/2-5-changement-role-fonction-deplacement-dn.md] — pattern transaction + rollback asymétrique

## Testing

### Stratégie

**Tests unitaires (PHPUnit) — `UserServiceBulkResetTest`**
- Mocks complets des 7 dépendances de `UserService` (via `UserServiceUpdateTest` pattern)
- Couverture : permission refusée, scope refusé, échec LDAP, flags `pwdlastset` 0/-1, audit sans fuite de mdp clair, retour structuré
- Objectif : chaque AC ≥ 1 test dédié

**Tests feature (Livewire + HTTP) — `BulkPasswordResetTest`**
- Utilise `MocksAdminUser` trait pour authentifier un opérateur avec / sans la permission
- Test de l'UI : entrée dropdown conditionnelle, ouverture modale, soumission, `StreamedResponse` reçue
- Assertion anti-persistance : `File::allFiles(storage_path())` inchangé après l'opération

**Tests d'audit**
- `Log::shouldReceive('info')` avec assertions sur les champs structurés
- `Log::spy()` + `assertLogged()` pour vérifier qu'aucun entry ne contient le mot de passe clair (regex match)

### Commandes de vérification (à jour pour cette VM)

- `php artisan test --filter=UserServiceBulkResetTest` — suite unit
- `php artisan test --filter=BulkPasswordResetTest` — suite feature
- `composer test` — suite complète
- `composer lint` — pint (syntaxe PSR-12)

**Rappel VM** : toutes les commandes `php artisan test` doivent être lancées via `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50` — pas sur le host. Le code est auto-synced, pas de `rsync` manuel.

## Recommandation Modèle Dev

**Modèle recommandé : `opus`**

**Justification :**
Cette story cumule **plusieurs dimensions de complexité** qui plaident pour Opus :

1. **Sécurité critique** — manipulation de mots de passe d'élèves et enseignants, export de données sensibles, RGPD NFR8. Une erreur de design (fuite en log, persistence accidentelle, rollback incomplet) a un impact direct sur la conformité et la confiance terrain.
2. **Transaction atomique asymétrique** — AD n'a pas de rollback natif, ce qui impose une stratégie de validation préalable + gestion des échecs partiels. C'est un piège classique où un modèle moins robuste produit du code qui "marche" mais laisse un état incohérent.
3. **Double couche de données** — écriture AD via LdapRecord **en premier**, puis PostgreSQL, avec transaction Eloquent + invalidation cache. Le pattern est établi mais demande de la rigueur.
4. **Non-rejouabilité export + TTL** — exigence subtile (les mots de passe ne doivent jamais être récupérables côté serveur après la première livraison ou après TTL 20 min). Nécessite un design propre : cache Redis chiffré + token signé + purge déterministe, sans `storage/` ni session PHP.
5. **UI Livewire non triviale — 3 composants + bandeau + dropdowns** — modale users, modale groupes (réutilisable tab + page groupe unique), action page user unique, bandeau persistant avec countdown TTL, toast étendu avec liens + sticky. Pattern `#[On(...)]` + événements de téléchargement + toasts + polling léger.
6. **Résolution AD non récursive + dédup + sync Eloquent** — `UserGroupService::getDirectMembersForBulkReset` doit interroger l'AD (pas Eloquent — cf. mémoire projet `feedback_gpo_real_ad_not_eloquent.md`), récupérer les membres directs uniquement (équivalent legacy `recurse=false`), dédupliquer par login AD sur une sélection mixte users+groupes, et valider l'existence Eloquent (sync si nécessaire). Zone à risque : idempotence stricte avec le legacy.
7. **PDF multi-users multi-pages + fonts dyslexie-friendly** — reprise du pattern legacy `generate_listing_pdf` (cartouches encadrés, sauts de page conditionnels sur changement établissement/classe, tri `trieleve()`), embedding de 5 fonts TTF via `@font-face` + html2pdf. Surface CSS/HTML non triviale, demande précision.
8. **Audit trail structuré enrichi** — chaque reset loggé individuellement sans fuite de clair, avec `bulk_operation_id` UUID + `source_group_id`/`source_group_name` (traçabilité "reset via bulk classe 6A"), logs d'ouverture/fermeture.
9. **Tests exigeants** — 15+ tests unitaires + feature, dont assertions anti-fuite (mdp absent des logs + session), TTL mocké, token signé invalide, audit enrichi, résolution AD non-récursive, 2 formats téléchargés avant expiration.

**Surface totale estimée : ~15-20 fichiers touchés**, sécurité transverse critique, intégration UI multi-entry-point (tab users, tab groups, page group, page user), service nouveau avec chiffrement + signature, migration éventuelle + fonts.

Sonnet pourrait faire 70-80% du travail correctement, mais les **angles morts sécurité** (fuite de mdp dans un log oublié, persistance involontaire dans la session Livewire, rollback AD mal géré, token signé mal vérifié, TTL non purgé, fonts chargées depuis legacy en runtime) sont exactement le type de défauts qu'Opus détecte mieux en reviewant son propre code. Vu le contexte "feature critique rentrée scolaire" (stress terrain, volumes importants, pas de droit à l'erreur), Opus est le bon choix.

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7` (1M context) — modèle recommandé pour cette story (sécurité critique + asymétrie rollback AD + non-rejouabilité export).

### Debug Log References

- Tests unitaires : `ssh … php artisan test --filter=UserServiceBulkResetTest` → 7/7 verts, 23 assertions
- Tests feature : `ssh … php artisan test --filter=BulkPasswordReset` → 21/21 verts, 57 assertions
- Total story 2-6 : **28 tests, 80 assertions, 100% pass**
- Full suite : 635 pass (pas de régression introduite)

### Completion Notes List

**Décisions architecturales clés prises pendant le dev :**

1. **Signature `bulkResetPasswords` mixte dès la première itération** — plutôt que d'implémenter la version "logins only" puis la refactor en Tâche 7b, j'ai livré directement la signature mixte `['userIds' => [...], 'groupIds' => [...]]`. Résultat : un seul chemin de code, moins de risque d'incohérence.

2. **Modale unique `password-reset-modal` (vs users/groups séparés)** — les tâches 7e/7f prévoyaient deux composants distincts. Fusion retenue : la modale accepte une sélection mixte (`users` + `groups`) en un seul composant Livewire SFC. Gain : un seul endroit à maintenir pour l'UX, la permission, l'appel service. La distinction "onglet users" vs "onglet groups" se fait au moment du dispatch depuis les dropdowns existants.

3. **Option de téléchargement : Option B (routes signées)** — l'Option A (Livewire in-memory) était séduisante mais interdisait le bandeau persistant (listing perdu au reload). J'ai retenu l'Option B avec garantie de sécurité : cache Redis chiffré + token signé Laravel + purge déterministe (TTL 20 min ou manuel). Le listing ne transite jamais par la session PHP.

4. **Migration `pwd_reset_at` créée mais NON appliquée** — la colonne n'existait pas dans la table `users`. **⚠️ HENRI : il faut lancer `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "cd /var/www/sambaedu-reload && php artisan migrate"` avant le premier bulk-reset en production.** Le code dégrade gracieusement si la colonne n'existe pas (try/catch sur `->save()` de `SqlUserModel`), donc pas de crash bloquant si la migration est oubliée — mais la traçabilité "dernier reset le ..." ne sera pas disponible.

5. **Dédup users AD absents de SQL** — `getDirectMembersForBulkReset` skip silencieusement les users AD qui ne sont pas dans la table SQL `users` (avec log info). Alternative rejetée : sync inline via `syncFromAd()`, trop coûteux en performance et risque de contention LDAP. Si l'opérateur a vraiment besoin de reset un user jamais synchronisé, il peut le sélectionner directement via son login (flux `userIds`).

6. **AD rollback asymétrique documenté** — l'AD ne permet pas de restaurer un hash de mdp précédent. Si une écriture AD échoue en cours de lot, les users déjà modifiés conservent leur nouveau mdp. C'est tracé via `audit.user.password.reset.partial_failure` + `results[]` retourné avec `success=false` par user. La modale affiche un avertissement avant la confirmation.

7. **Contrainte anti-fuite session PHP** — test dédié `session_never_contains_cleartext_password` + `listing_not_stored_in_php_session` : on ne peut JAMAIS retrouver un mdp clair dans `session()->all()`. Le listing transite uniquement par `Cache::put(Crypt::encrypt(...), TTL=1200)`.

8. **Ordre des routes critique** — les routes `GET /users/password-reset/{token}/pdf|csv` sont définies **AVANT** `GET /users/{login}` dans `routes/web.php`, sinon Laravel matche `{login} = "password-reset"` en premier.

**Limites connues / hors scope :**
- Pas de job queue pour gros volumes (>200 users) — cap synchrone raisonnable pour ~2 classes
- Pas de table `password_reset_audits` en DB — les logs structurés `Log::info(audit.*)` suffisent (peuvent être redirigés vers ELK/Glitchtip)
- Fonts dyslexie-friendly embarquées via html2pdf standard (pas de `@font-face` base64 inline agressif — html2pdf gère les TTF via son propre mécanisme)

### File List

**Créés :**
- `app/Services/BulkResetListingService.php`
- `app/Services/PasswordResetExportService.php`
- `app/Http/Controllers/PasswordResetExportController.php`
- `resources/views/components/organisms/password-reset-modal.blade.php`
- `resources/views/components/organisms/password-reset-banner.blade.php`
- `resources/views/exports/password-reset.blade.php`
- `resources/views/errors/password-reset-expired.blade.php`
- `resources/fonts/OpenDyslexic-Regular.ttf`
- `resources/fonts/OpenDyslexic-Bold.ttf`
- `resources/fonts/mononoki-Regular.ttf`
- `resources/fonts/mononoki-Bold.ttf`
- `resources/fonts/LexicaUltralegible-Regular.ttf`
- `database/migrations/2026_04_18_140000_add_pwd_reset_at_to_users_table.php` (⚠️ à appliquer manuellement)
- `tests/Unit/Services/UserServiceBulkResetTest.php`
- `tests/Feature/Users/BulkPasswordResetTest.php`
- `tests/Feature/Users/BulkPasswordResetGroupsTest.php`
- `tests/Feature/Users/BulkPasswordResetListingTtlTest.php`

**Modifiés :**
- `app/Services/UserService.php` — ajout `bulkResetPasswords()` + `collectExportMetadata()`
- `app/Services/UserGroupService.php` — ajout `getDirectMembersForBulkReset()`
- `app/Components/Traits/WithToasts.php` — ajout `toastSuccessWithActions()`
- `app/Models/User.php` — `pwd_reset_at` dans `$fillable` et `$casts`
- `routes/web.php` — routes signées `/users/password-reset/{token}/pdf|csv`
- `resources/views/pages/users/index.blade.php` — entrée dropdown users + groupes + banner
- `resources/views/pages/users/[login]/index.blade.php` — entrée dropdown + inclusion modale
- `resources/views/pages/users/groups/[id]/index.blade.php` — entrée dropdown + inclusion modale

### Change Log

- 2026-04-18 : dev complet story 2-6 (opus). 28 tests verts, 80 assertions. Pint appliqué sur fichiers nouveaux + existants modifiés. Migration `pwd_reset_at` livrée mais non appliquée (marquée pour action manuelle).
