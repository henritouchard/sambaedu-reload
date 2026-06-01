# Story 4.10 — Auth iPXE — restauration validation user/password + permissions

Status: review
Epic: 4 — Gestion des Machines, WorkstationGroups & AppProfiles SER

## Story

As a **responsable de collège**,
I want que l'accès au menu admin iPXE et aux actions sensibles (install, action, maintenance, enrollment) exige un login/mot de passe valide AD avec le bon droit applicatif,
So que personne sur le LAN ne puisse déclencher une réinstallation ou un factory-reset sur un poste sans habilitation.

## Contexte & Motivation

**Régression sécurité critique** identifiée en VM le 2026-05-28 : `IpxeService::handleAdmin()` (`app/Ipxe/Services/IpxeService.php:197-233`) ne vérifie ni le `username` ni le `password` reçus dans la POST iPXE. Le contrôleur regarde uniquement `mac` et `uuid` pour identifier la machine, puis rend le menu admin. **N'importe quel couple login/mdp random ouvre l'admin**.

Le legacy (`sambaedu/ipxe/admin.php:28-52`) faisait deux validations :
1. `login_action($config, $uuid, $username, $password)` — auth contre AD.
2. `have_right($config, SE_COMPUTER_INSTALL, $username)` — droit applicatif.

**Mitigation en place (2026-05-28)** : kill-switch via `config('ipxe.admin.enabled', false)` qui retire l'item `(1) login` du menu boot iPXE (`known.blade.php`). À ne **PAS** lever (`IPXE_ADMIN_ENABLED=true`) avant livraison de cette story.

**Périmètre étendu** : il faut **auditer** tous les endpoints iPXE qui exposent des actions sensibles (création/modification machine, install OS, factory reset, rename, enrollment) — ils ont probablement le même trou.

## Acceptance Criteria

1. **Validation login/mdp dans `handleAdmin()`** :
   - Si POST contient `username` ET `password` non vides → appel `AuthenticationService::validatePassword($username, $password)` (déjà disponible app/Services/AuthenticationService.php:261, fait le bind LDAP).
   - Si validation échoue → log warning `ipxe.admin.auth_failed` + retour script iPXE qui `goto menu` ou `echo ERREUR` + sleep + chain back to `/ipxe/boot`.
   - Si validation succède → vérifier que l'utilisateur a la permission Spatie `computer.install` (équivalent legacy `SE_COMPUTER_INSTALL`). Si KO → log `ipxe.admin.permission_denied` + retour idem.
   - Si tout OK → rend le menu admin (comportement actuel).

2. **Audit + sécurisation des autres endpoints sensibles** (à scoper précisément en Tâche 1) :
   - `/ipxe/maintenance` — accès aux outils factory reset, rescuecd, winpe (`handleMaintenance`).
   - `/ipxe/installation-linux` — déclenchement install Linux.
   - `/ipxe/installation-windows` — déclenchement install Windows.
   - `/ipxe/action/{action}` — exécution actions whitelistées (`handleAction`).
   - `/ipxe/clonezilla-menu` — sous-menu clonage.
   - `/ipxe/enrollment/{name,room,parc-add,parc-remove,byod}` — modifications PG + AD (création machine, affectation salle, rattachement parc).
   - Pour chacun : valider que l'auth est faite OU ajouter le même mécanisme.

3. **Permission Spatie `computer.install`** : créer la permission si absente (`php artisan db:seed --class=PermissionSeeder` ou migration). Mapper sur le rôle `super-admin` + `ComputerAdmin` (cf. pattern Story 4.7 wallpapers + 4.8 customizations).

4. **Templates Blade auth-aware** :
   - `known.blade.php` : retirer le kill-switch `@if($isAdminActive)` une fois l'auth restaurée (ou le laisser comme « feature disable » volontaire, à arbitrer).
   - Ajouter une vue `auth_failed.blade.php` minimaliste (echo erreur + sleep + chain back to `/ipxe/boot`).

5. **Réactivation du kill-switch** : modifier la default config `ipxe.admin.enabled` de `false` à `true`, OU documenter dans `.env.example` que `IPXE_ADMIN_ENABLED=true` doit être posé.

6. **Tests** :
   - Test feature `IpxeAdminAuthTest` : POST sans creds → handshake/erreur (pas de menu admin) ; POST creds invalides → erreur ; POST creds valides sans permission → permission_denied ; POST creds valides avec permission → menu admin rendu.
   - Test similaire pour chaque endpoint audité en AC2.
   - Pas de leak de credentials dans les logs (tester que `password` apparaît jamais en clair, même en debug).

7. **Logs sécurité** :
   - `ipxe.admin.auth_failed` avec `ip`, `username_prefix` (3 premiers chars) — pas de `mac/uuid` (irrelevant pour audit auth).
   - `ipxe.admin.permission_denied` avec `ip`, `username_prefix`, `permission='computer.install'`.
   - `ipxe.admin.auth_success` avec `ip`, `username_prefix`, `mac_prefix`, `uuid_prefix`.

8. **Runbook QA `docs/qa/domains/ipxe.md`** — append-only :
   - Scénario : login random refusé.
   - Scénario : login valide sans droit → refusé.
   - Scénario : login valide avec droit → menu accessible.
   - Scénario : kill-switch désactive complètement (env `IPXE_ADMIN_ENABLED=false`).

## Dépendances

- Story 4.9 done (observer Workstation AD sync) — pas bloquant, scope orthogonal.
- AuthenticationService::validatePassword() déjà en place.
- Spatie permissions déjà en place (cf. wallpapers/customizations stories).

## Hors scope

- Refonte de l'UI admin Laravel `/app/parc` (déjà auth web).
- 2FA, OAuth, SSO — hors périmètre.
- Audit de sécurité général des autres routes Laravel.
- Migration vers JWT iPXE (un firmware iPXE ne porte pas d'OS pour gérer un Bearer — cf. décision D3 stories 3.1/3.2).

## Risques

- **Faux positif d'auth** : un utilisateur AD valide sans permission `computer.install` ne pourra plus utiliser iPXE même pour des actions « lecture seule ». Audit nécessaire pour distinguer endpoints « publics » (boot menu, exit) vs « admin ».
- **iPXE sans clavier accessible** dans certaines UEFI mode → impossible de saisir login. Le menu boot doit conserver l'option `(3) exit` et fallback boot disque même si l'admin est inaccessible.
- **Bot mass-enrollment** légitime : si un script PXE-boot automatique enroll plusieurs postes en parallèle, il n'a pas de login. **À arbitrer** : exception pour `/ipxe/enrollment/name` (création initiale) — peut-être garder accessible sans auth (parité legacy + besoin métier) avec rate-limit fort par IP/MAC.

## Recommandation Modèle Dev

**opus** — auth touche au LAN sécu, plusieurs endpoints à auditer (≥5), pattern Spatie + AD bind cross-couches, tests de non-leak (pas de password en clair dans logs), refactor de templates Blade conditionnels, et coordination avec décisions D-3.x / 3.x scope-D3 « pas de JWT iPXE » à respecter. Sonnet ferait probablement la base mais risquerait d'oublier la non-régression sur enrollment mass-bot ou de leaker password dans un log debug. Le scope est aussi proche d'une story sécu donc tolérance zéro à l'approximation.

## Mémoire à reprendre

- `feedback_auth_iso_legacy` : auth machine reste iso-legacy (AD bind + samba), pas de Bearer/secret per-host.
- `feedback_worktree_no_vm_sync` : ne pas tester sur VM depuis un worktree.
- `project_two_repos_topology` : commit dans le repo B (`sambaedu-reload/`).

## Tasks / Subtasks

- [x] **T1 — Audit des endpoints sensibles iPXE**
  - [x] Lister tous les endpoints iPXE handler par `IpxeService` + `IpxeEnrollmentOrchestrator` + controllers Linux/Windows
  - [x] Identifier ceux qui exposent une action sensible (création/modif machine, install OS, factory reset, enrollment) vs ceux qui restent publics (boot, exit, chain back)
  - [x] Arbitrer périmètre final (cf. décision Henri : pas d'exception pour enrollment/name)

- [x] **T2 — Service AuthenticationService — méthode publique sans effet de bord**
  - [x] Ajouter `validateAdCredentials(string $login, string $password): bool` (wrap LDAP bind via `validatePassword` sans modifier `$_SESSION`)
  - [x] Documenter la convention non-leak (jamais logger password)

- [x] **T3 — IpxeAuthService centralisé**
  - [x] Créer `App\Ipxe\Services\IpxeAuthService` (extract username + base64-decode password + validateAd + check permission Spatie `computer.install`)
  - [x] Créer enum `IpxeAuthStatus` (MissingCredentials / AuthFailed / PermissionDenied / Allowed)
  - [x] Créer DTO `IpxeAuthOutcome` (status, username, user)
  - [x] Émettre logs structurés `ipxe.<context>.auth_missing/auth_failed/permission_denied/auth_success` (champs `ip`, `username_prefix`(3), `mac_prefix`(6), `uuid_prefix`(8), `permission`)
  - [x] Pas de session ni d'event Auth Laravel (firmware iPXE = pas de cookie)
  - [x] Bind dans `IpxeServiceProvider`
  - [x] Classe non-`final` (sous-classe anonyme stub pour tests)

- [x] **T4 — Renderer + view auth_failed**
  - [x] Créer `resources/views/ipxe/menu/auth_failed.blade.php` (text/plain, sleep 8s, chain back boot)
  - [x] Ajouter `IpxeMenuRenderer::renderAuthFailed(IpxeAuthStatus, $baseUrl)`
  - [x] Libellés courts FR (sans accents — contrainte ASCII iPXE)

- [x] **T5 — Plug auth dans IpxeService**
  - [x] `handleAdmin` : guard après handshake check
  - [x] `handleMaintenance` : idem
  - [x] `handleAction` : idem (applicable à TOUTES les actions whitelistées, y compris destructrices comme `factory_reset`)
  - [x] `handleInstallationLinuxMenu` : idem
  - [x] `handleInstallationWindowsMenu` : idem
  - [x] `handleClonezillaMenu` : idem
  - [x] Helper `guard()` exposé sur `IpxeService` (réutilise `safeRender`)

- [x] **T6 — Plug auth dans IpxeEnrollmentOrchestrator**
  - [x] `handleName` : guard après handshake
  - [x] `handleByod` : guard après handshake
  - [x] `handleRoom` : guard après handshake
  - [x] `handleParcAdd` / `handleParcRemove` (via `handleParcCommon`) : guard après handshake
  - [x] Helper `guard()` privé (réutilise `safeRender`)

- [x] **T7 — Templates Blade auth-aware**
  - [x] `admin.blade.php` : propager `param username` + `param password:base64` dans `params` block
  - [x] `maintenance.blade.php` : idem
  - [x] `installation-linux.blade.php` : idem
  - [x] `installation-windows.blade.php` : idem
  - [x] `clonezilla.blade.php` : idem
  - [x] `known.blade.php` : commentaire — kill-switch retiré (default true), item login toujours visible
  - [x] Tous les templates restent ASCII pur (architecture test `IpxeNamespaceTest`)

- [x] **T8 — Kill-switch et config**
  - [x] `config/ipxe.php` : default `ipxe.admin.enabled = true` (au lieu de `false`)
  - [x] Conservé pour permettre override `IPXE_ADMIN_ENABLED=false` (panne LDAP rare)

- [x] **T9 — Permission Spatie `computer.install`**
  - [x] Déjà présente dans `SambaPermission::ComputerInstall` (rien à ajouter à l'enum)
  - [x] Déjà mappée sur `ComputerAdmin` + `SuperAdmin` dans `SambaRole` (rien à ajouter)
  - [x] Seedée par `PermissionSeeder` existant (idempotent, non destructif)

- [x] **T10 — Tests**
  - [x] `IpxeAdminAuthTest` (52 tests, 146 assertions) — matrice {sans creds, creds invalides, creds valides sans perm, creds valides avec perm} × 12 endpoints sensibles
  - [x] Test non-leak password (Monolog TestHandler + scan fichiers log)
  - [x] Test non-leak password dans response body
  - [x] Test handshake reste accessible sans auth
  - [x] Test menu admin rendu sur auth OK
  - [x] Helper `Tests\Support\IpxeAuthTestHelper` (bypassIpxeAuth/stubAdAuth) pour les tests Feature existants
  - [x] Patch des 15+ tests existants (Feature + Unit) pour `bypassIpxeAuth()` en `setUp()`
  - [x] Non-régression : 0 nouvelle failure introduite (baseline 10 failures + 2 errors pré-existants conservés)

- [x] **T11 — Runbook QA**
  - [x] Section 18 dans `docs/qa/domains/ipxe.md` (append-only) : 7 scénarios + matrice endpoints + pré-requis déploiement

- [x] **T12 — Sprint status + story**
  - [x] Story passée `ready-for-dev` → `review`
  - [x] `sprint-status.yaml` : ligne `4-10-auth-ipxe-admin-permissions` → `review` avec commentaire dev

## Dev Agent Record

**Modèle** : `claude-opus-4-7[1m]`
**Date** : 2026-05-29
**Branche** : `main` (pas un worktree — VM non sollicitée)

### Décisions clés en cours de dev

- **D1 — Méthode publique vs reuse de `authenticate()`** : `authenticate()` modifie `$_SESSION['login']` + log spécifique. Pour iPXE, on veut un bind LDAP « pur » sans effet de bord. Ajout d'un wrapper `validateAdCredentials()` qui appelle `validatePassword()` (privé) et retourne `bool`. Conservation stricte du comportement legacy (refus quand pwdlastset=0 → user doit changer son mdp d'abord).

- **D2 — base64 password** : le template iPXE POST `param password ${password:base64}` (encode auto firmware). `IpxeAuthService::decodePassword()` fait `base64_decode(strict=true)` avec fallback raw si décodage KO (firmware non standard / test manuel curl sans encode). Cf. scénarios QA `printf 'pwd' | base64`.

- **D3 — Propagation chain iPXE** : pour ne pas re-prompter le user à chaque chain, on propage `${username}` + `${password:base64}` dans le `params` block de tous les templates qui chain vers un endpoint sensible. Les variables iPXE persistent dans la session jusqu'au prochain `exit`.

- **D4 — Pas de séparation handleAdmin/handleEndpoint** : un seul helper `IpxeService::guard()` réutilisé partout, idem `IpxeEnrollmentOrchestrator::guard()` privé (mêmes signatures différentes car renderer différent). Évite la duplication des 30 LOC d'auth+log.

- **D5 — IpxeAuthService non-`final`** : nécessaire pour que `Tests\Support\IpxeAuthTestHelper::bypassIpxeAuth()` puisse créer une sous-classe anonyme stubbée. Alternative testée (mock via `createMock`) cassait sur l'injection `AuthenticationService` dans le constructor. Trade-off accepté : surface API publique étendue mais tous les call-sites passent par DI singleton.

- **D6 — Non-leak — test double** : capture via `Monolog\Handler\TestHandler` poussé sur le logger Monolog du channel `ipxe` + scan défensif des fichiers log. `Log::channel(...)->listen()` n'existe pas en Laravel 11+ (bug initial corrigé en cours de dev).

- **D7 — Patch tests existants vs réécriture** : choix d'écrire un trait `IpxeAuthTestHelper::bypassIpxeAuth()` et de l'injecter dans 16 tests existants (script Python idempotent) plutôt que de retravailler chacun. Préserve le scope (tests focus sur leur sujet, pas sur l'auth).

- **D8 — Enrollment/name PAS exempté** : décision Henri 2026-05-28. Pas d'exception pour `enrollment/name` même pour mass-bot — le process admin reste manuel (entrée scolaire), un attaquant n'a pas accès à un compte AD valide avec `computer.install`.

### Hors scope confirmé / follow-ups

- Conversion vers passwordless / cert TLS iPXE (déjà arbitré D3 stories 3.1/3.2 : impossible techniquement).
- 2FA, OAuth, SSO sur iPXE — un firmware iPXE n'a pas d'OS pour porter ça.
- Refonte UI Laravel `/app/parc` (déjà gardé par middleware auth web).
- Audit de sécurité général des autres routes Laravel.
- E2E manuel VM : Henri à lancer manuellement (worktree-less ce dev — VM /vm accessible mais on n'y touche pas par convention dev-cycle dans la branche `main` sans review préalable).

### File List

**Créés** :
- `app/Ipxe/Services/IpxeAuthService.php`
- `app/Ipxe/Services/IpxeAuthStatus.php`
- `app/Ipxe/Services/IpxeAuthOutcome.php`
- `resources/views/ipxe/menu/auth_failed.blade.php`
- `tests/Support/IpxeAuthTestHelper.php`
- `tests/Feature/Ipxe/IpxeAdminAuthTest.php`

**Modifiés** :
- `app/Services/AuthenticationService.php` (+ méthode publique `validateAdCredentials`)
- `app/Ipxe/Services/IpxeService.php` (+ `guard()` helper, plug dans handleAdmin/Maintenance/Action/Clonezilla/InstallationLinux/InstallationWindows)
- `app/Ipxe/Services/IpxeEnrollmentOrchestrator.php` (+ `guard()` helper, plug dans handleName/Byod/Room/ParcAdd/ParcRemove)
- `app/Ipxe/Services/IpxeMenuRenderer.php` (+ méthode `renderAuthFailed`)
- `app/Providers/IpxeServiceProvider.php` (bind `IpxeAuthService`, injecte dans `IpxeService` + `IpxeEnrollmentOrchestrator`)
- `config/ipxe.php` (default `admin.enabled = true`)
- `resources/views/ipxe/menu/admin.blade.php` (params username/password propagés, kill-switch commentaire)
- `resources/views/ipxe/menu/maintenance.blade.php` (params username/password propagés)
- `resources/views/ipxe/menu/installation-linux.blade.php` (idem)
- `resources/views/ipxe/menu/installation-windows.blade.php` (idem)
- `resources/views/ipxe/menu/clonezilla.blade.php` (idem)
- `resources/views/ipxe/menu/known.blade.php` (commentaire kill-switch retiré)
- `docs/qa/domains/ipxe.md` (section 18 append)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (story → review)

**Tests patchés (bypassIpxeAuth en setUp)** :
- `tests/Feature/Ipxe/IpxeAdminEndpointTest.php`
- `tests/Feature/Ipxe/IpxeMaintenanceEndpointTest.php`
- `tests/Feature/Ipxe/IpxeActionEndpointTest.php`
- `tests/Feature/Ipxe/IpxeInstallationLinuxEndpointTest.php`
- `tests/Feature/Ipxe/IpxeInstallationWindowsEndpointTest.php`
- `tests/Feature/Ipxe/IpxeClonezillaMenuTest.php`
- `tests/Feature/Ipxe/IpxeEnrollmentNameEndpointTest.php`
- `tests/Feature/Ipxe/IpxeEnrollmentByodEndpointTest.php`
- `tests/Feature/Ipxe/IpxeEnrollmentRoomEndpointTest.php`
- `tests/Feature/Ipxe/IpxeEnrollmentParcEndpointTest.php`
- `tests/Unit/Ipxe/Services/IpxeServiceAdminTest.php`
- `tests/Unit/Ipxe/Services/IpxeServiceActionTest.php`
- `tests/Unit/Ipxe/Services/IpxeServiceMaintenanceTest.php`
- `tests/Unit/Ipxe/Services/IpxeServiceInstallationLinuxTest.php`
- `tests/Unit/Ipxe/Services/IpxeServiceInstallationWindowsTest.php`
- `tests/Unit/Ipxe/Services/IpxeServiceLoggingTest.php`

### Tests passants

- `IpxeAdminAuthTest` : 52/52 (146 assertions)
- Suite iPXE complète (tests/Feature/Ipxe + tests/Unit/Ipxe + tests/Architecture) : 0 régression introduite (10 failures + 2 errors pré-existants conservés à l'identique).

## Change Log

| Date       | Auteur                    | Changement                                                                                                                                                                                                              |
|------------|---------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 2026-05-29 | dev opus claude-opus-4-7  | Story dev complète, status ready-for-dev → review                                                                                                                                                                       |
| 2026-05-29 | review fix claude-opus-4-7 | Corrections post-review : #2 (propagation `param username`/`password` dans 5 templates enrollment), #3 (durcissement `decodePassword` regex+modulo), #5 (spy `validateAdCredentials`), #10 (assertion positive par endpoint), #14 (`User::findByLogin` case-insensitive). Tests : +13 cas (52→65), tous verts. |
| 2026-05-29 | review fix claude-opus-4-7 | Patchs finaux : #7 bloc DEBUG retiré de `enrollment/name.blade.php` ; #12 interface `App\Ipxe\Contracts\IpxeAuthorizes` extraite + `IpxeAuthService` redevient `final implements IpxeAuthorizes`, binding DI contrat→impl, type-hints migrés (`IpxeService`, `IpxeEnrollmentOrchestrator`), `IpxeAuthTestHelper` refactoré via `StubIpxeAuthService` ; #15 throttle abaissé `600,1`→`30,1` sur les 12 endpoints sensibles iPXE + test `it_rate_limits_admin_endpoint_after_30_failures`. Tests Ipxe : 812 passants (7 échecs pré-existants hors scope 4.10). |
