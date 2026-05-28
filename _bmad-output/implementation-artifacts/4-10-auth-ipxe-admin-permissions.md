# Story 4.10 — Auth iPXE — restauration validation user/password + permissions

Status: ready-for-dev
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
