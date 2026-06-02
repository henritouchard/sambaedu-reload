# Story 20.1 : Login fédéré — validation du JWT controlHub & ouverture de session externe

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Première story d'Epic 20** « Authentification fédérée d'utilisateurs externes ». Permet à un **technicien externe** (gérant plusieurs collèges, **absent de l'AD** de l'établissement) de se connecter à une instance SE5 via un **JWT signé émis par controlHub**, et d'obtenir une session avec un rôle. SER gagne un **fournisseur d'identité externe de confiance générique** (controlHub en est l'instance) — **aucune notion de « central » dans le code SER** (principe fondateur PRD).
>
> **Découverte de cadrage (recon code 2026-05-29) : l'infra JWT existe déjà.** Story 16-10 a livré `firebase/php-jwt` + `App\Auth\V1\Jwt\WorkstationJwtVerifier` (RS256, key-map par `kid`, validation de claims, anti-rejeu `jti` via cache + DB) et la structure `config/auth_v1.php`. **On RÉUTILISE/calque cette infra** pour un nouveau *tier* d'identité « utilisateur fédéré » — on n'introduit pas une nouvelle lib ni un nouveau paradigme.
>
> **Réconciliation iso-legacy (mémoire `feedback_auth_iso_legacy`)** : cette story introduit du JWT pour un acteur **humain et central** (controlHub, toujours à jour), **distinct de l'auth machine/poste** (qui reste AD+SMB, postes non tous à jour). De plus, le JWT existe **déjà** dans la base (tier workstation). Donc **pas de conflit** avec la contrainte « pas de Bearer/JWT per-host » — voir D-9.

---

## Scope strict & frontières

### IN-SCOPE (ce que la story livre)

1. **Vérificateur JWT fédéré** `App\Auth\Federated\Jwt\FederatedJwtVerifier` (+ DTO `FederatedUserClaims`), **calqué sur** `App\Auth\V1\Jwt\WorkstationJwtVerifier` (`app/Auth/V1/Jwt/WorkstationJwtVerifier.php:38-195`) :
   - **`RS256` uniquement** ; rejet explicite de `alg:none` et de tout algo symétrique (`HS*`) — la lib reçoit la liste d'algos autorisés, on ne déduit JAMAIS l'algo du header (faille de confusion d'algorithme).
   - Claims validés : `sub`, `jti`, `kid`, `exp`, `nbf`, `iat`, **`iss` (= émetteur configuré)**, **`aud` (= identifiant de CETTE instance SE5)**, `role`, `login`, `name`, `email`. Claim manquant/non conforme → rejet.
   - **`aud` lie le jeton à l'instance** : un JWT forgé pour le collège A ne passe pas sur le collège B (anti-rejeu inter-instance sur la flotte).
   - **Anti-rejeu `jti` à usage unique** : réutilise le pattern de `WorkstationJwtRevocationChecker` (cache store `apc` + DB) — un `jti` déjà vu → rejet (D-6).
   - **Tolérance d'horloge ±60 s** (`Firebase\JWT\JWT::$leeway = 60`).
2. **Config** `config/federated_auth.php` calquée sur `config/auth_v1.php` : émetteur(s) de confiance (clé publique PEM par `kid`), `expected_aud` (= `instance_id`), `expected_iss`, `expected_tier`, **table de mapping `rôle-externe → SambaRole`**, `leeway`.
3. **Modèle d'identité externe (minimal)** `App\Models\ExternalIdentity` + migration `external_identities` : `external_sub` (unique), `issuer`, `login`, `name`, `email`, `is_active`, `last_login_at`, **`softDeletes`**. Upsert au login (clé = `external_sub`). *(Cycle de vie complet + base RGPD = Story 20.2.)*
4. **Principal de session** : réutilisation de `App\Models\User` comme principal (marqué externe via `source='federated'` + FK nullable `external_identity_id`, **sans `dn`/`ad_guid`, jamais synchronisé AD**) — voir **D-4**. Migration ajoutant `users.source` + `users.external_identity_id`.
5. **Endpoint + controller de fédération** `App\Auth\Federated\Http\FederatedLoginController`, route **`POST /auth/federated/callback`** déclarée dans `routes/web.php` **AVANT le catchall** (`routes/web.php:240`) — voir D-3. Flux : valide le JWT → upsert `ExternalIdentity` → résout/provisionne le `User` externe → mappe `role`→`SambaRole` (rejet si inconnu → 403) → `Auth::login()` + marque la session « fédérée » → redirige dans l'app.
6. **Réconciliation du guard de session (CRITIQUE — régression)** : le guard actif `SambaEduAuthGuard` (`app/Http/Middleware/Auth/SambaEduAuthGuard.php`) **revérifie l'utilisateur dans le LDAP à chaque requête** (`findByLogin` + `isActive`). Un externe n'existe pas dans le LDAP → il serait déconnecté à la requête suivante. La story **branche** le guard actif pour les sessions fédérées : si la session est marquée « fédérée » → valider `ExternalIdentity.is_active` (sauter la vérif LDAP) ; sinon flux LDAP inchangé. Voir **D-5**.
7. **Mapping de rôle (minimal)** : résolution `rôle-externe → SambaRole` via la table de config ; rôle inconnu → **403 explicite** (jamais de fallback privilégié). L'outillage d'admin/config riche = Story 20.3.
8. **Logging** : channel dédié `federated-auth` (calqué sur le channel `auth-v1`) — logge `sub`/`jti`/`iss`/`role` (non sensible), **jamais** le JWT brut ni les clés.
9. **Tests** (couvre H2 du rapport IR) :
   - **Unit** `tests/Unit/Auth/Federated/FederatedJwtVerifierTest.php` (≥10 cas) : JWT RS256 valide ✓ ; `alg:none` → rejet ; confusion d'algo (HS256 signé avec la clé publique comme secret) → rejet ; `exp` dépassé → rejet ; `nbf` futur → rejet ; `aud` ≠ instance → rejet ; `iss`/`kid` inconnu → rejet ; claim requis manquant → rejet ; rejeu (même `jti`) → 2e rejeté ; skew dans ±60 s → accepté.
   - **Feature** `tests/Feature/Auth/Federated/FederatedLoginEndpointTest.php` (≥8 cas) : login valide → session + rôle Spatie appliqué ; rôle inconnu → 403 ; `ExternalIdentity` créée au 1er login puis réutilisée par `external_sub` ; **requête suivante dans la session fédérée NON déconnectée** (régression D-5) ; aucune fuite de secret en réponse/logs.
   - **Architecture** `tests/Architecture/FederatedRouteTest.php` (≥2 cas) : route déclarée **avant** le catchall (cf. guard-rail `IpxeNamespaceTest::ipxe_3_7_routes_are_declared_before_catchall`).
   - **Non-régression** : la suite d'auth LDAP/AD existante reste verte (le flux `SambaEduAuthGuard` LDAP est inchangé pour les sessions non fédérées).
10. **Runbook QA** : trame `docs/qa/domains/federated-login.md` (≥5 scénarios), à compléter par le dev en fin de story.

### HORS-SCOPE (ne pas faire)

- ❌ Le **cycle de vie complet de l'identité externe** (sémantique soft-delete avancée, sync profil à la reconnexion, **base légale de rétention RGPD**) = Story 20.2.
- ❌ L'**outillage d'admin/UI du mapping de rôle** et la richesse du mapping = Story 20.3.
- ❌ Le **système d'audit dénormalisé** des actions externes = Story 20.4.
- ❌ La **doc de contrat d'intégration controlHub** = Story 20.5 (rédigée *après* le code livré — mémoire `feedback_doc_follows_code`).
- ❌ Toute **logique côté controlHub** (forge du JWT, gestion des techniciens, choix d'instance) = côté irundoo, hors SER.
- ❌ Le **endpoint JWKS** / récupération dynamique des clés (clé publique statique en config au MVP — évolution si friction).
- ❌ La **révocation active** poussée par controlHub (TTL court + jti usage unique suffisent au MVP ; révocation active = évolution future, cf. décision archi ③).
- ❌ Aucune modification du flux d'auth **AD/LDAP** existant ni de l'auth **machine/poste**.

---

## Mode de livraison & contraintes opérationnelles

- **Repo cible du code** : `sambaedu-reload/` (Repo B — code). Le fichier de cette story vit dans `_bmad-output/` (Repo A parent). Cf. mémoire `project_two_repos_topology`.
- **Worktree git** : si le dev travaille en worktree, **ne jamais** SSH `/vm` ni run de tests sur la VM (mémoire `feedback_worktree_no_vm_sync`). Tests locaux PHPUnit + `php -l` host. Smoke bout-en-bout (vrai JWT controlHub → session) confié à Henri post-merge.
- **PHP-FPM = user `www-admin`** (uid 599, mémoire `project_php_fpm_user_www_admin`) → la clé publique PEM de l'émetteur (`config/federated_auth.php`) doit être lisible par `www-admin`.
- **Cache** : `CACHE_DRIVER` via `.env`, fallback APCu (mémoire `project_story_16-15_cache_driver`) — le store du cache `jti` suit le même pattern que `auth_v1.revocation` (`config/cache.php:18`).

---

## Decisions (tranchées par SM — ne pas re-débattre, confirmer via Questions)

### D-1 — Réutiliser `firebase/php-jwt` + calquer `WorkstationJwtVerifier`
Le projet a déjà `firebase/php-jwt` (`composer.json:22`) et un vérificateur de référence (`app/Auth/V1/Jwt/WorkstationJwtVerifier.php`) qui fait **exactement** ce qu'on veut (RS256, key-map par `kid`, rejet des fixtures de test en prod, anti-rejeu `jti`). On crée un `FederatedJwtVerifier` **parallèle** (tier « utilisateur fédéré »), pas une réimplémentation. DRY + cohérence + sécurité déjà éprouvée.

### D-2 — `config/federated_auth.php` calquée sur `config/auth_v1.php`
Même structure (clés par `kid`, algo, leeway) + spécifique fédération : `expected_iss`, `expected_aud` (= `controlHub.se4fs.instance_id`), `expected_tier`, table `role_map` (`rôle-externe → SambaRole::value`).

### D-3 — Transport JWT = **POST binding** sur `POST /auth/federated/callback`
Le JWT arrive par **POST** (formulaire auto-soumis rendu par controlHub, façon SAML POST binding), **pas** en query string. Justification : un jeton porteur dans l'URL fuit dans les logs d'accès, l'historique navigateur et le `Referer`. Route web (ouvre une session → besoin du cookie de session) déclarée **avant** le catchall. *(Le GET `?token=` façon Guacamole est écarté pour la fuite — voir Q-3.)* Le rendu du formulaire auto-soumis est **côté controlHub** (hors SER, documenté en 20.5).

### D-4 — Principal de session = `App\Models\User` réutilisé (marqué externe)
La session loggue un `App\Models\User` (comme `SambaEduAuthGuard::ensureEloquentUser()` provisionne déjà les users LDAP, `SambaEduAuthGuard.php:71`), **marqué externe** (`source='federated'`, `external_identity_id` FK, sans `dn`/`ad_guid`). Justification : **toutes** les Policies/Gates existantes (Epic 7) type-hint `App\Models\User` ; un principal séparé obligerait à réécrire l'autorisation. `ExternalIdentity` porte les données externes durables. ⚠️ L'observer de sync AD des users (s'il existe) **doit ignorer** `source='federated'`. *(Alternative — un `Authenticatable` séparé + guard `federated` dans `config/auth.php` — voir Q-2.)*

### D-5 — Réconciliation du guard de session (le guard actif doit gérer les sessions fédérées)
`AuthGuardInterface` (`app/Http/Middleware/Auth/AuthGuardInterface.php`) est un **middleware de validation par requête** à **binding unique** (`AppServiceProvider.php:109`), pas un login pluggable. Le login fédéré est donc un **controller d'entrée** (valide le JWT une fois, ouvre la session) ; ensuite le guard de session reprend. Mais `SambaEduAuthGuard` revérifie le LDAP à chaque requête → un externe serait éjecté. **Décision** : brancher le guard actif (modifier `SambaEduAuthGuard` ou introduire un guard dispatcher bindé à `AuthGuardInterface`) : session marquée « fédérée » → valider `ExternalIdentity.is_active` et **sauter** la vérif LDAP ; sinon flux inchangé. *(Ceci réalise concrètement la décision archi « guard distinct » — qui, face au code réel, n'est pas un 2e binding `AuthGuardInterface` mais un branchement du guard de session + un controller/vérificateur de login.)*

### D-6 — Anti-rejeu `jti` via le pattern existant + leeway ±60 s
Réutiliser le mécanisme de `WorkstationJwtRevocationChecker` (cache `apc` + DB) adapté au `jti` fédéré (usage unique, TTL = TTL du jeton). `JWT::$leeway = config('federated_auth.leeway', 60)`.

### D-7 — Mapping de rôle minimal ; défaut `technicien → SambaRole::Technicien`
La table `role_map` par défaut mappe le rôle externe `technicien` vers `SambaRole::Technicien` (`app/Enums/SambaRole.php:17`). Rôle externe absent de la table → **403**. L'adéquation du périmètre du rôle `technicien` (aujourd'hui `ComputerView`/`ComputerControl`/`WpkgAssign`) au besoin d'un technicien flotte est une **question produit** — voir Q-1.

### D-8 — Persistance d'identité minimale dans 20.1 (résout IR-M2)
Le guard ne peut ouvrir de session sans principal → 20.1 livre `ExternalIdentity` (modèle + migration + upsert) au minimum. La sémantique de cycle de vie complète (RGPD, reconnexion, sync profil) reste en 20.2.

### D-9 — Pas de violation de `feedback_auth_iso_legacy`
La contrainte « pas de Bearer/JWT per-host » vise l'auth **machine/poste** (postes non tous à jour). Ici : acteur **humain + central** (controlHub à jour), et le JWT **existe déjà** dans la base (tier workstation, Story 16-10). Aucun nouveau paradigme pour les postes. À rappeler en revue pour éviter un faux positif.

---

## Story

As a **technicien externe gérant plusieurs collèges (absent de l'AD de l'établissement)**,
I want **me connecter à une instance SE5 via un jeton signé émis par controlHub et obtenir une session avec mon rôle**,
so that **je puisse administrer l'instance selon mon rôle sans jamais exister dans l'AD local**.

## Acceptance Criteria

1. **Given** un `POST /auth/federated/callback` avec un JWT `RS256` valide, signé par l'émetteur configuré, `aud` = id de cette instance, claims complets, **When** l'endpoint le traite, **Then** la signature et tous les claims sont validés, une session est ouverte et l'utilisateur est redirigé dans l'app.
2. **Given** un JWT avec `alg:none`, **When** il est soumis, **Then** il est rejeté (aucune session) — l'algo n'est jamais déduit du header.
3. **Given** un JWT « confusion d'algorithme » (HS256 signé avec la clé publique comme secret), **Then** il est rejeté.
4. **Given** un JWT dont `exp` est dépassé au-delà du leeway, **Then** rejet.
5. **Given** un JWT dont `nbf` est dans le futur au-delà du leeway, **Then** rejet.
6. **Given** un JWT dont `aud` ≠ id de cette instance, **Then** rejet (anti-rejeu inter-instance).
7. **Given** un `iss` ou un `kid` inconnu de la config, **Then** rejet.
8. **Given** un JWT auquel manque un claim requis (`sub`/`jti`/`kid`/`exp`/`aud`/`role`), **Then** rejet.
9. **Given** un JWT déjà consommé (même `jti`), **When** il est rejoué, **Then** le second est rejeté.
10. **Given** un décalage d'horloge dans ±60 s, **Then** le jeton reste accepté.
11. **Given** un `role` absent de la table de mapping, **Then** réponse **403**, aucune session ouverte.
12. **Given** un 1er login d'un externe, **Then** une `ExternalIdentity` est créée (clé `external_sub`) ; **And** une reconnexion réutilise le même enregistrement.
13. **Given** une session fédérée ouverte, **Then** le principal porte le `SambaRole` mappé et les Policies/Gates existantes s'appliquent normalement.
14. **Given** une session fédérée active, **When** une requête authentifiée suivante arrive, **Then** l'utilisateur **n'est pas déconnecté** (la vérif LDAP du guard est sautée pour les sessions fédérées). *(Régression D-5.)*
15. **Given** un utilisateur AD/LDAP normal, **Then** son flux de login et de session est **strictement inchangé** (suite d'auth existante verte).
16. **Given** n'importe quelle requête, **Then** ni le JWT brut ni les clés n'apparaissent en réponse ou dans les logs ; le channel `federated-auth` ne logge que des claims non sensibles (`sub`/`jti`/`iss`/`role`).

## Tasks / Subtasks

- [x] **T0** — Recon (no code) : confirmé le pattern `WorkstationJwtVerifier`/`config/auth_v1.php`, le binding `AuthGuardInterface` (`AppServiceProvider.php:109` → `SambaEduAuthGuard`, invoqué via middleware `SambaEduAuth` alias `sambaedu.auth`), la position du catchall (`routes/web.php` `{path}` en fin de fichier), le store de cache `jti` (cache `array` en test / `apc` en prod). **Écart D-4** : il n'existe AUCUN observer Eloquent de sync AD sur le modèle `User` (la sync AD passe par `UserSyncService`/`findByLogin`, pas par un observer). Donc rien à amender côté observer ; le `source='federated'` suffit (un user fédéré n'est jamais ciblé par `findByLogin` LDAP). (AC: 1,14,15)
- [x] **T1** — `config/federated_auth.php` : émetteur+clés par `kid`, `expected_iss`/`expected_aud`/`expected_tier`, `role_map`, `leeway`, `replay`, `safety`. (AC: 1,6,7,11)
- [x] **T2** — `FederatedUserClaims` (DTO immuable) + `FederatedJwtVerifier` (firebase/php-jwt, RS256 only via key-map, rejet `alg:none`/symétriques, validation `iss`/`aud`/`exp`/`nbf`/`iat`/`sub`/`jti`/`kid`/`role`/`tier`, anti-rejeu `jti` cache+DB, leeway ±60s). (AC: 1-10,16)
  - [x] T2.1 Calquée sur `WorkstationJwtVerifier` (gestion exceptions firebase, codes de rejet stables `FederatedJwtErrorCodes`, log channel `federated-auth`).
- [x] **T3** — Migration `external_identities` + modèle `ExternalIdentity` (softDeletes) ; migration `users.source` + `users.external_identity_id` (nullable FK best-effort sqlite). + migration/modèle `federated_jwt_consumptions` (anti-rejeu DB). (AC: 12)
- [x] **T4** — `FederatedLoginController` + route `POST /auth/federated/callback` **avant** le catchall (+ test architecture + exemption CSRF). Flux : verify → upsert `ExternalIdentity` → provisionner/charger `User` externe → mapper rôle (403 si inconnu) → `Auth::login()` + marquer session fédérée (`FederatedSession`) → redirect `app.dashboard`. (AC: 1,11,12,13)
- [x] **T5** — Réconciliation guard de session (D-5) : branchement `SambaEduAuthGuard::handleFederatedSession()` → valide `ExternalIdentity.is_active`, **saute** la vérif LDAP ; flux LDAP strictement inchangé hors session fédérée. (Pas d'observer AD users à amender — cf. T0.) (AC: 14,15)
- [x] **T6** — Mapping de rôle minimal `FederatedRoleMapper` (table config ; unknown → null → controller 403). Défaut `technicien → SambaRole::Technicien`. (AC: 11,13)
- [x] **T7** — Channel log `federated-auth` (config/logging.php, `replace_placeholders=false` iso `auth-v1`). (AC: 16)
- [x] **T8** — Tests Unit (14) + Feature (8) + Architecture (4) verts ; non-régression guard (`AuthGuardInterfaceTest` 6/6) + flux LDAP couvert en Feature. (AC: 1-16)
- [x] **T9** — Trame runbook QA `docs/qa/domains/federated-login.md` (9 scénarios stables 20.1-1..20.1-9) + ajout au README QA. (AC: 1,11,14)

## Dev Notes

- **Réutilisation > réécriture** : le vérificateur de référence est `app/Auth/V1/Jwt/WorkstationJwtVerifier.php:38-195` ; le DTO de claims `app/Auth/V1/Jwt/WorkstationJwtClaims.php:19-57` ; la conf `config/auth_v1.php:50-76` ; l'anti-rejeu `WorkstationJwtRevocationChecker` (cache `apc` 60s + DB). **Calquer**, ne pas réinventer.
- **Deux pièges majeurs découverts en recon** (à traiter, sinon régression/blocage) :
  1. **`AuthGuardInterface` = middleware de session à binding unique** (`app/Http/Middleware/Auth/AuthGuardInterface.php`, binding `app/Providers/AppServiceProvider.php:109-112`, alias `sambaedu.auth` `app/Http/Kernel.php:70`). Le login fédéré est un controller d'entrée, pas un 2e binding.
  2. **`SambaEduAuthGuard` revérifie le LDAP à chaque requête** (`app/Http/Middleware/Auth/SambaEduAuthGuard.php` — `findByLogin` + `isActive`). Sans le branchement D-5, l'externe est déconnecté à la requête suivante.
- **Ouverture de session** : modèle `Auth::login($eloquentUser)` déjà utilisé (`SambaEduAuthGuard.php:84`) ; provisioning d'un `User` Eloquent : `ensureEloquentUser()` (`SambaEduAuthGuard.php:71`). État de session legacy via `AuthenticationService::createSession()` (`app/Services/AuthenticationService.php:231-236`).
- **Rôles Spatie** : `App\Models\User` utilise le trait `HasRoles` ; `assignRole()`/`syncRoles()` ; enum `app/Enums/SambaRole.php` (`Technicien='technicien'` l.17, `ReferentNumerique='referent-numerique'` l.18). Mapping rôle externe → `SambaRole::value`.
- **Route avant catchall** : `routes/web.php:240-250` (catchall en dernier) ; guard-rail existant `IpxeNamespaceTest::ipxe_3_7_routes_are_declared_before_catchall` à imiter.
- **Sécurité (durcissement IR, déjà en archi)** : RS256 pinné, rejet `alg:none`/symétriques, `iss`/`aud`/`exp`/`nbf`/`iat` validés, `aud`=instance (anti-rejeu inter-instance), `jti` usage unique, leeway ±60s. Cf. `architecture.md` § « Authentification Fédérée → Sécurité du jeton JWT ».
- **Domain-neutral** : aucun littéral « controlHub »/« central » dans le code ; l'émetteur est identifié par config (`expected_iss`). controlHub = une instance d'IdP externe.

### Project Structure Notes

- Namespaces proposés : `App\Auth\Federated\Jwt\*` (vérificateur, claims), `App\Auth\Federated\Http\*` (controller). Conf `config/federated_auth.php`. Modèle `App\Models\ExternalIdentity`. Cohérent avec `App\Auth\V1\*` existant.
- Migrations dans `database/migrations/` (convention `YYYY_MM_DD_HHMMSS_*`). Table `external_identities` ; colonnes ajoutées à `users` (`source`, `external_identity_id`).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Epic 20 : Authentification fédérée] (stories 20.1-20.5, décisions de cadrage)
- [Source: _bmad-output/planning-artifacts/architecture.md#Authentification Fédérée — Phase 2 IdP externe (Epic 20)] (3 décisions + bloc « Sécurité du jeton JWT »)
- [Source: _bmad-output/planning-artifacts/implementation-readiness-report-2026-05-29-epic20.md] (constats H1/H2/M1-M5 ; H1/M1/M4 traités en archi, H2 → AC de cette story)
- [Source: sambaedu-reload/app/Auth/V1/Jwt/WorkstationJwtVerifier.php:38-195] (vérificateur de référence à calquer)
- [Source: sambaedu-reload/config/auth_v1.php:50-76] (structure conf JWT par kid)
- [Source: sambaedu-reload/app/Http/Middleware/Auth/SambaEduAuthGuard.php:35-117] (guard de session, recheck LDAP, Auth::login, ensureEloquentUser)
- [Source: sambaedu-reload/app/Providers/AppServiceProvider.php:109-112] (binding AuthGuardInterface)
- [Source: sambaedu-reload/app/Enums/SambaRole.php:17-18] (Technicien, ReferentNumerique)
- [Source: sambaedu-reload/routes/web.php:240-250] (catchall — déclarer la route avant)

## Questions pour Henri — TRANCHÉES (chat 2026-05-29)

- **Q-1 (IR-M3, produit) — TRANCHÉE** : défaut retenu → mapping `technicien → SambaRole::Technicien`. L'adéquation fine du périmètre du rôle reste un sujet 20.3.
- **Q-2 (D-4) — TRANCHÉE : Option A** (principal = `App\Models\User` réutilisé, marqué externe `source='federated'`). Écartés après analyse : compte partagé/pool (casse l'attribution + course aux rôles Spatie persistés en base), User éphémère create/delete (orphelins FK + GC fragile + churn + casse l'historique audit), principal séparé Option B (réécriture de l'autorisation). A = réutilisation native (1 User/identité, recyclé par `external_sub`), autorisation intacte, attribution durable.
- **Q-3 (D-3) — TRANCHÉE : POST binding** (`POST /auth/federated/callback`, pas de token en query → pas de fuite logs/historique).
- **Q-4 (config) — TRANCHÉE : une clé publique par instance** (sécurité : confinement du rayon de souffle + défense en profondeur en plus du claim `aud`). **Transparent pour le code 20.1** : SE5 vérifie contre la/les clé(s) configurée(s) par `kid` dans `config/federated_auth.php` ; c'est une décision de provisioning côté controlHub (documentée en 20.5).
- **Q-5 (IR-M5) — REPORTÉE à 20.2** : base légale de rétention de `ExternalIdentity`. Hors scope 20.1 (mais à ne pas oublier en contexte edtech/govtech).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- Tests locaux (host) : `composer install --ignore-platform-req=ext-apcu --ignore-platform-req=ext-imagick` (vendor gitignored — réinstallé), création `bootstrap/cache/`.
- Suites neuves : `vendor/bin/phpunit tests/Unit/Auth/Federated tests/Feature/Auth/Federated tests/Architecture/FederatedRouteTest.php` → **27 tests OK** (14 Unit + 8 Feature + 4 Archi).
- Non-régression guard : `tests/Feature/AuthGuardInterfaceTest.php` → **6/6 OK**.
- Régression large `tests/Architecture tests/Feature/Auth/V1 tests/Unit/Auth/V1` : 287 tests, **2 échecs PRÉ-EXISTANTS** (`MigrationE2EScenarioTest::*` — `/api/v1/workstation-config/wallpaper` renvoie 500 sur le host sans schéma complet) — confirmés en échec **sans** mes modifs (stash/pop), donc indépendants de cette story.
- Lint `php -l` : **0 erreur** sur les 21 fichiers PHP créés/modifiés.

### Completion Notes List

**Sécurité JWT (cœur de la story)** — RS256 pinné par construction de la key-map (`Key($pem, 'RS256')`), liste d'algos jamais déduite du header. `alg:none` et confusion HS256/clé-publique rejetés (tests dédiés verts). Validation explicite `iss` (hash_equals), `aud` (= instance, anti-rejeu inter-instance, support `aud` string ou array RFC7519), `tier`, présence de tous les claims requis, `exp`/`nbf`/`iat` via la lib + `JWT::$leeway = 60`. Anti-rejeu `jti` **usage unique** (cache `add()` atomique + DB `federated_jwt_consumptions`), consommé EN DERNIER (ne brûle pas un jti sur un jeton par ailleurs invalide). Logs `federated-auth` : `sub`/`jti`/`iss`/`role` + préfixe hash du jeton uniquement — jamais le JWT brut, ni clés, ni PII (login/name/email exclus du jeu loggable).

**D-5 (régression critique)** — Le guard de session `SambaEduAuthGuard` branche `handleFederatedSession()` sur les sessions marquées « fédérées » (`FederatedSession`) : valide `ExternalIdentity.is_active` et SAUTE entièrement le LDAP. Le flux LDAP est strictement inchangé hors session fédérée (test de non-régression Feature : `findByLogin` toujours appelé pour un user AD). Le login fédéré est un **controller d'entrée** (pas un 2e binding `AuthGuardInterface`), conforme au cadrage.

**D-4** — Principal = `App\Models\User` réutilisé, marqué `source='federated'` + FK `external_identity_id`, `dn`/`ad_guid` forcés à NULL, jamais synchronisé AD. Login local stable `ext:<external_sub>` (anti-collision avec un login AD homonyme). **Écart documenté** : aucun observer Eloquent de sync AD users n'existe (la sync passe par services + `findByLogin`), donc rien à amender — le marqueur `source` suffit.

**Décisions d'implémentation à relire** :
1. **Login local `ext:<sub>`** : choix d'un préfixe pour garantir l'unicité de `users.login` sans collision AD. À valider produit (impact affichage / audit 20.4).
2. **CSRF** : endpoint ajouté à l'`except` de `VerifyCsrfToken` (`auth/federated/*`) — POST cross-site auto-soumis par l'IdP, pas de token CSRF possible ; la preuve est le JWT signé + anti-rejeu jti. À confirmer en revue sécurité.
3. **FK `users.external_identity_id`** : posée best-effort (try/catch) car sqlite :memory: ne supporte pas l'ALTER ADD CONSTRAINT ; en prod pgsql la contrainte est bien créée.
4. **`role` = 'federated'** sur le `User` (colonne legacy `users.role`) pour distinguer visuellement, le rôle d'autorisation réel restant le rôle Spatie mappé (`syncRoles`).
5. **Replay checker fail-closed** sur course/doublon DB (un même jti ne doit jamais ouvrir 2 sessions) — divergent du `WorkstationJwtRevocationChecker` qui fail-open volontairement (acteur poste, dispo > strictness). Choix assumé : ici acteur humain, login rejouable sans danger.

**Tests host** : `vendor/` réinstallé localement (gitignored). Smoke bout-en-bout (vrai JWT IdP → session sur VM) reste à la charge d'Henri post-merge (worktree → pas de SSH VM).

**Corrections post-review (2026-06-01)** — review sonnet + 2e avis opus, arbitrages Henri. Cf. `_bmad-output/codeReviews/20-1.md`.
- **#1 / Q1 (révocation)** : `upsertIdentity()` refuse le login (**403** `federated.login.identity_revoked`) si l'identité existe et est révoquée (`is_active=false` **ou** soft-deletée) ; suppression du `restore()` + du `is_active=true` réarmé. Réactivation = action admin (20.3).
- **M1 / Q2 (anti-rejeu)** : la consommation `jti` est sortie du `FederatedJwtVerifier` (devenu pur validateur) et déplacée dans le controller, **en dernier dans la transaction, après le provisioning** → un échec amont ne brûle plus le `jti` (retry légitime possible).
- **#4 / Q3 (transport)** : retrait du fallback `Authorization: Bearer` → **POST binding strict** (D-3), token absent → 400.
- **#3** : `aud` array sans match → code `aud_mismatch` (au lieu de `missing_claim`). **#6** : le guard ne logge plus `login` (AC16) mais le `sub` dérivé. **#7** : `ttlFor()` simplifiée (l'ancienne formule était un no-op). **#5** : tests AC8 ajoutés (`exp`/`aud` absents).
- **Validation** : 33/33 tests fédérés + 41/41 non-régression (guard + workstation JWT) verts ; lint 0 erreur. 2 échecs `MigrationE2EScenarioTest` pré-existants (indépendants).
- **Runbook QA** enrichi : scénarios 20.1-10 (révocation au fresh login) + 20.1-11 (`jti` non brûlé si provisioning échoue) + tableau incidents.

### File List

**Créés — code applicatif**
- `config/federated_auth.php`
- `app/Auth/Federated/Support/FederatedJwtErrorCodes.php`
- `app/Auth/Federated/Jwt/Exceptions/InvalidFederatedJwtException.php`
- `app/Auth/Federated/Jwt/FederatedUserClaims.php`
- `app/Auth/Federated/Jwt/FederatedJwtVerifier.php`
- `app/Auth/Federated/Jwt/FederatedJwtReplayChecker.php`
- `app/Auth/Federated/Models/FederatedJwtConsumption.php`
- `app/Auth/Federated/FederatedRoleMapper.php`
- `app/Auth/Federated/Session/FederatedSession.php`
- `app/Auth/Federated/Http/FederatedLoginController.php`
- `app/Models/ExternalIdentity.php`

**Créés — migrations**
- `database/migrations/2026_06_01_120000_create_external_identities_table.php`
- `database/migrations/2026_06_01_120100_add_source_and_external_identity_to_users_table.php`
- `database/migrations/2026_06_01_120200_create_federated_jwt_consumptions_table.php`

**Créés — tests**
- `tests/Concerns/IssuesFederatedJwt.php`
- `tests/Unit/Auth/Federated/FederatedJwtVerifierTest.php`
- `tests/Feature/Auth/Federated/FederatedLoginEndpointTest.php`
- `tests/Architecture/FederatedRouteTest.php`

**Créés — doc QA**
- `docs/qa/domains/federated-login.md`

**Modifiés**
- `app/Http/Middleware/Auth/SambaEduAuthGuard.php` (branchement D-5 `handleFederatedSession` + `logoutFederated`)
- `app/Http/Middleware/VerifyCsrfToken.php` (exemption `auth/federated/*`)
- `config/logging.php` (channel `federated-auth`)
- `routes/web.php` (route `POST /auth/federated/callback` avant catchall)
- `docs/qa/README.md` (ajout domaine `federated-login`)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (clé 20-1 → review)

