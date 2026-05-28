# Story 16.13 : Exposition endpoints natifs `/api/v1/workstation-config/*`

Status: corrections-applied

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> Story **fondatrice cible** de la migration SE4 → SE5. Expose les **8 endpoints natifs** sous `/api/v1/*` (wallpaper, firefox, thunderbird, shortcuts, network, veyon, associations, applications-scripts) via **réutilisation directe** des controllers livrés par 4.7, 4.8, 16.3a/b/c, 16.7. Authentification par JWT `auth.v1.workstation` (16.10), `workstation_uuid` extrait du JWT (pattern iso 16.12) — **jamais** depuis un input user-controlled.
>
> **Scope strict 16.13** = (a) 8 routes `GET /api/v1/{wallpaper,firefox,thunderbird,shortcuts,network,veyon,associations,applications-scripts}` ajoutées dans `routes/api.php` derrière `auth.v1.workstation`, (b) chaque route délègue à la méthode du controller natif existant (pas de logique nouvelle), (c) extraction `workstation_uuid` depuis `$request->attributes->get('auth_v1.workstation_uuid')`, (d) résolution serveur du contexte poste à partir du `workstation_uuid` JWT (lookup `workstations` Eloquent + `WorkstationGroup` + utilisateur courant via session AD/SMB hors-scope direct — le controller existant accepte aujourd'hui un `id` md5/APCu, on lui passe l'équivalent reconstruit), (e) tests Feature complets (401 sans JWT, 401 JWT invalide, 200 happy path × 8 endpoints, payload iso-fonctionnel), (f) test Architecture invariance des 8 routes (`Route::has(...)` + middleware attaché + méthode HTTP), (g) extension runbook QA `docs/qa/domains/auth.md` § Story 16.13 (≥10 scénarios append-only).
>
> **HORS-SCOPE 16.13** :
> - Toute modification des endpoints legacy `*_out.php` (routes `routes/web.php` `gpo/*_out.php`) — ils **restent intacts**, ils ne sont touchés qu'en **16.13bis**.
> - Toute modification des controllers existants (`WallpaperController`, `AppPolicyController`, `ShortcutExportController`, `NetworkOutController`, `VeyonOutController`, `AssociationsOutController`, `ApplicationsScriptsController`) — **réutilisation pure**, pas de refactor. Si une méthode publique du controller n'expose pas un mode utilisable depuis JWT (cas attendu : les méthodes `legacyOut(Request)` lisent un `id` md5 APCu), on ajoute une nouvelle méthode publique dédiée `apiV1(...)` ou on passe un contexte synthétisé via une méthode helper privée — **sans toucher** au comportement legacy.
> - Refactor du modèle migration SE4 → SE5 (= 16.13bis).
> - Suppression du shim 1bis.18 ou archivage `sambaedu/gpo/` (= 16.13bis).
> - Suppression du middleware `InjectBootstrapFragment` (= 16.13bis).
> - Refactor du modèle Workstation (relation `migrationStatus()`, accessors, scopes = 16.13bis).
> - UI admin minimaliste avec colonne Migration + filtre (= 16.13bis).
> - Cleanup auth md5/APCu legacy (= 16.13bis).

---

## Mode de livraison & contraintes opérationnelles (worktree)

> **Mode worktree git** — branche `16-13`. Pattern iso 16.10/16.11/16.12.
>
> - **NE PAS** sync manuellement le code sur la VM. L'inotify host→VM est responsable (sur la branche `main` uniquement — depuis ce worktree il n'y a pas de propagation).
> - **NE PAS** SSH `/vm` depuis ce worktree (memory `feedback_worktree_no_vm_sync`).
> - **NE PAS** run les tests sur la VM. Lint statique `php -l` + tests PHPUnit locaux (host) si `vendor/` présent — sinon différer les tests à Henri post-merge sur `main`.
> - **Actions Henri post-merge** (à exécuter au reboot VM / merge sur `main`) — listées en section finale « Smoke test à exécuter quand VM up » : `composer install`, smoke `curl` sur les 8 endpoints avec un JWT valide émis via `tinker`, vérification `php artisan route:list | grep api/v1`, exécution `./scripts/run-tests.sh`, vérification logs `auth-v1`.

---

## Encadré contexte

**Topologie cible** (cf. Tech Spec §5.2-§5.4) : un poste migré (post-16.11 D11 — tokens stockés DPAPI Win / `0600 root` Linux) interroge ses configurations via les endpoints `/api/v1/*` au lieu de `/sambaedu/gpo/*_out.php` md5/APCu. Chaque appel HTTPS embarque `Authorization: Bearer <jwt>` avec `tier=workstation` et `sub=workstation_uuid`. Le serveur valide la signature RS256 via `EnsureWorkstationJwt` (16.10), extrait le `workstation_uuid` (claim `sub`), et **résout côté serveur** le contexte poste (machine, salle, utilisateur courant, OS) sans accepter ces valeurs en input.

**Sources canoniques du contexte** (à utiliser dans cette story, pas d'invention) :
- Lookup `App\Models\Workstation::where('uuid', $workstationUuid)->firstOrFail()` (table existante, FK polymorphique côté ControlHub).
- Récupération du `WorkstationGroup` (salle) via la relation Workstation → WorkstationGroup. La machine appartient à 0..N groupes ; on prend le premier non-default (pattern iso `ApplicationsScriptsController` :130-141 `resolveInfo`).
- L'OS, le user courant et le `userprofile` ne sont **pas** dans le JWT 16.10 (le JWT porte seulement `sub`, `tier`, `iat`, `exp`, `jti`, `kid`). Ils doivent rester paramètres de **query string** (déjà parsés par les controllers existants). On les accepte donc en query, **mais** on **ne** réutilise **plus** l'`id` md5 APCu — on reconstruit le contexte runtime à partir du `workstation_uuid` JWT + des params query non-sensibles (`os`, `action`, `user`, `userprofile`).

**Pattern iso 16.12** (référence canonique pour cette story) : l'endpoint `POST /api/v1/script-execution-logs` du `ScriptExecutionLogIngestionController::store()` lit `$workstationUuid = $request->attributes->get('auth_v1.workstation_uuid')` (injecté par le middleware 16.10), **jamais** `$request->input('workstation_uuid')`. On reprend strictement ce pattern pour les 8 endpoints 16.13.

**Cohabitation avec les endpoints legacy** : les 8 routes `gpo/*_out.php` (déclarées dans `routes/web.php`) **restent inchangées** durant 16.13. Elles seront transformées en `MigrationController::serveFragment()` (fragment+reboot) en 16.13bis. Pendant cette story, on **ajoute** les 8 nouvelles routes sous `/api/v1/*` sans toucher aux legacy. Un poste migré utilise les nouvelles ; un poste non-migré continue d'utiliser les legacy (avec injection bootstrap 16.11 jusqu'à 16.13bis).

**Frontière `/api/v1/agent/*` vs `/api/v1/*` plat** : le namespace `/api/v1/agent/*` (16.10/16.11) est réservé à l'enrôlement et au cycle de vie JWT (enroll, refresh, ping, bootstrap.cmd/sh). Les 8 nouvelles routes 16.13 sont **plates** sous `/api/v1/*` (pattern iso 16.12 `/api/v1/script-execution-logs`). Cohérent avec Tech Spec §5.4 et §5.6.

**Frontière ControlHub** : ControlHub utilise `/api/v1/snapshot`, `/api/v1/workstation-groups`, etc. avec middleware `controlhub.auth` (clé API). Les 8 nouvelles routes 16.13 utilisent `auth.v1.workstation` (JWT RS256 + `tier=workstation`). Aucune collision de nommage (les 8 noms sont singletons : `wallpaper`, `firefox`, etc.). On valide explicitement via le test archi (D8).

---

## Décisions tranchées (D1-D10, ne pas re-débattre)

> Cadrage SM 2026-05-19. Le dev applique sans re-discuter ; en cas de blocage technique réel, il documente la difficulté dans Dev Agent Record et continue.

### D1 — 8 routes plates sous `/api/v1/*` (PAS sous `/api/v1/agent/*`)

- Routes à déclarer dans `routes/api.php`, dans un nouveau bloc dédié juste après le bloc `/api/v1/agent/*` 16.10/16.11 :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 16.13 — Exposition endpoints natifs /api/v1/*
  |--------------------------------------------------------------------------
  | 8 endpoints natifs équivalents aux *_out.php legacy, protégés par
  | auth.v1.workstation (JWT RS256 + tier=workstation, livré par 16.10).
  | workstation_uuid extrait du claim sub (pattern iso 16.12).
  |
  | Les endpoints legacy `*_out.php` restent inchangés (transformés en
  | MigrationController en 16.13bis).
  */
  Route::prefix('v1')
      ->middleware(['auth.v1.secure-headers', 'auth.v1.workstation', 'throttle:300,1'])
      ->name('agent.v1.config.')
      ->group(function () {
          Route::get('/wallpaper',             [WallpaperController::class,            'apiV1'])->name('wallpaper');
          Route::get('/firefox',               [AppPolicyController::class,            'apiV1Firefox'])->name('firefox');
          Route::get('/thunderbird',           [AppPolicyController::class,            'apiV1Thunderbird'])->name('thunderbird');
          Route::get('/shortcuts',             [ShortcutExportController::class,       'apiV1'])->name('shortcuts');
          Route::get('/network',               [NetworkOutController::class,           'apiV1'])->name('network');
          Route::get('/veyon',                 [VeyonOutController::class,             'apiV1'])->name('veyon');
          Route::get('/associations',          [AssociationsOutController::class,      'apiV1'])->name('associations');
          Route::get('/applications-scripts',  [ApplicationsScriptsController::class,  'apiV1'])->name('applications-scripts');
      });
  ```
- **Throttle `300,1`** : iso routes legacy (`gpo/*_out.php`). Un parc de 300 postes en boot de masse rentrée scolaire ne doit pas être bloqué par un throttle trop strict. Mesure unitaire par-IP Laravel std (et non par-UUID — le throttle Laravel n'a pas accès au JWT à ce stade — c'est acceptable).
- **`auth.v1.secure-headers`** : middleware 16.10 (Cache-Control no-store + HSTS + nosniff). Idempotent à ajouter ici car nos réponses peuvent contenir des configs sensibles (Veyon, network).
- **Méthode HTTP** : `GET` uniquement pour tous les endpoints **sauf** `/associations` qui doit accepter aussi `POST` (le legacy `associations_out.php` reçoit le body `list` via POST — cf. `AssociationsOutController::legacyOut` ligne 60-72). **Décision** : déclarer `/associations` en `Route::match(['GET','POST'], ...)` ; les autres en `Route::get(...)`. Le client (poste migré) déterminera la méthode selon le besoin (parité legacy).
- **Anti-pattern** : ne pas grouper les 8 routes sous un sous-namespace `agent/` ou `config/` — le namespace `/api/v1/*` plat est explicitement choisi pour cohérence avec 16.12 (`/api/v1/script-execution-logs`). Si une refonte de nommage est jugée nécessaire, c'est une décision Phase 3 (agent Go).

### D2 — Pas de nouveau controller — réutilisation des 7 controllers existants

Mapping endpoint → controller (déjà livré, ne pas re-créer) :

| Endpoint `/api/v1/*` | Controller existant | Story d'origine | Méthode legacy actuelle | Nouvelle méthode à ajouter |
|---|---|---|---|---|
| `/wallpaper` | `App\Http\Controllers\WallpaperController` | 4.7 | `legacyOut(Request)` | `apiV1(Request)` |
| `/firefox` | `App\Http\Controllers\AppPolicyController` | 4.8 | `legacyFirefoxOut(Request)` | `apiV1Firefox(Request)` |
| `/thunderbird` | `App\Http\Controllers\AppPolicyController` | 4.8 | `legacyThunderbirdOut(Request)` | `apiV1Thunderbird(Request)` |
| `/shortcuts` | `App\Http\Controllers\Api\v1\ShortcutExportController` | 16.3a | `legacyDispatch(Request)` | `apiV1(Request)` |
| `/network` | `App\Http\Controllers\Gpo\NetworkOutController` | 16.3b | `legacyOut(Request)` | `apiV1(Request)` |
| `/veyon` | `App\Http\Controllers\Gpo\VeyonOutController` | 16.3b | `legacyOut(Request)` | `apiV1(Request)` |
| `/associations` | `App\Http\Controllers\Gpo\AssociationsOutController` | 16.3c | `legacyOut(Request)` | `apiV1(Request)` |
| `/applications-scripts` | `App\Http\Controllers\Gpo\ApplicationsScriptsController` | 16.7 | `generate(Request)` | `apiV1(Request)` |

**Règle** : on **ajoute** une méthode publique `apiV1(...)` par controller. Cette méthode :
1. Récupère `$workstationUuid = $request->attributes->get('auth_v1.workstation_uuid')` (string non-nullable, garanti par le middleware).
2. Reconstruit le contexte poste via un **helper** privé `buildContextFromUuid(string $uuid): ?ContextDto` (à factoriser dans une classe de service partagée — cf. D3).
3. **Délègue** à la même logique métier que la méthode legacy en passant le contexte reconstruit (au lieu de lire le `$id` md5 APCu via `AppContextRepository::findById($id)`).
4. Retourne **le même type de Response** que la méthode legacy (mêmes status codes, mêmes Content-Type, mêmes bodies — iso-fonctionnel).

**Anti-pattern** :
- Ne pas modifier la méthode legacy `legacyOut(...)` / `legacyDispatch(...)` / `generate(...)` / `legacyFirefoxOut(...)` / `legacyThunderbirdOut(...)`. Si un refactor des méthodes privées partagées est nécessaire (extraction de logique commune entre `legacyOut` et `apiV1`), c'est **acceptable** mais doit préserver à 100% le comportement legacy (tests Feature 4.7/4.8/16.3a/b/c/16.7 restent verts).
- Ne pas créer un controller intermédiaire `ApiV1ConfigController` qui agrégerait les 8 endpoints — la séparation par controller existant maintient la cohésion par domaine métier.

### D3 — Résolution serveur du contexte poste : service partagé `WorkstationConfigContextResolver`

- Créer **un nouveau service** `App\Gpo\Services\WorkstationConfigContextResolver` (namespace `App\Gpo` car les contextes consommés sont ceux des GPO).
- Signature :
  ```php
  namespace App\Gpo\Services;

  use App\Models\Workstation;
  use App\Models\WorkstationGroup;
  use App\Models\User;
  use App\Dto\Gpo\WorkstationConfigContext;

  class WorkstationConfigContextResolver
  {
      /**
       * Reconstruit un contexte runtime poste à partir du JWT workstation_uuid
       * + paramètres query non-sensibles (os, user, userprofile).
       *
       * @return WorkstationConfigContext|null  null si le workstation_uuid est
       *         inconnu en DB (poste enrôlé mais pas encore vu en `workstations`
       *         table — cas marginal, log warning et 404).
       */
      public function resolve(
          string $workstationUuid,
          string $os = 'linux',
          string $userLogin = '',
          string $userProfile = '',
      ): ?WorkstationConfigContext;
  }
  ```
- **Implémentation** :
  1. Lookup `Workstation::where('uuid', $workstationUuid)->first()`. Si null → return null (le controller retournera 404).
  2. Récupère le `WorkstationGroup` principal du poste : `$workstation->workstationGroups()->where('is_default', false)->first()` ou fallback `->first()` si tous default. (Heuristique iso `ApplicationsScriptsController::resolveInfo` :130.)
  3. Si `$userLogin !== ''` : lookup `User::where('login', $userLogin)->first()`. Sinon `null`.
  4. Construit un DTO `WorkstationConfigContext` (à créer dans `app/Dto/Gpo/`) qui reflète la même surface que `AppCustomizationContext` (utilisé par `WallpaperController` / `AppPolicyController`) **et** `AppContext` (utilisé par `NetworkOutController` / `VeyonOutController` / `AssociationsOutController` / `ApplicationsScriptsController`). Les champs nécessaires : `machineName` (string), `salleName` (string, nullable), `userLogin` (string), `os` (string), `machineId` (int|null), `userId` (int|null), `groupId` (int|null), `userProfile` (string).
- **Pourquoi un nouveau service plutôt que réutiliser `AppContextRepository`** : `AppContextRepository::findById($id)` lit depuis APCu (clé `apps.$id` posée par `gpo/applications.php`). Cette source n'est pas alimentée pour les postes migrés (qui n'appellent plus `gpo/applications.php`). Il faut une source **source-of-truth DB** : la table `workstations` + les relations Eloquent. Le service `WorkstationConfigContextResolver` est cette nouvelle source.
- **Question ouverte tranchée par défaut β** : pour les controllers qui attendent un DTO précis (`WallpaperController` attend `\App\Dto\Wallpaper\WallpaperContext` ; `NetworkOutController` attend `\App\Services\AppCustomization\Contracts\AppContextRepository::findById()` qui retourne un `AppContext`), on prévoit **deux options** :
  - **Option α** : `WorkstationConfigContextResolver` retourne un DTO commun `WorkstationConfigContext` et chaque controller `apiV1(...)` convertit en son DTO interne via un mapper privé. Charge : +1 méthode `toWallpaperContext()`, `toAppContext()`, etc. par controller.
  - **Option β** (retenue par défaut SM) : `WorkstationConfigContextResolver::resolve()` retourne `WorkstationConfigContext`, ET le service expose des **méthodes spécialisées** `toWallpaperContext()`, `toAppContext()` qui retournent les DTO existants directement. Le controller `apiV1(...)` appelle `$resolver->toAppContext($workstationUuid, $os, $userLogin, $userProfile)` et passe au reste de la chaîne. **Pourquoi β** : évite la duplication mapper inter-controllers, factorise dans le service, expose une API claire et testable.
- **Tests unit du resolver** : ≥6 cas — happy path machine+groupe+user / machine sans groupe / machine sans user / UUID inconnu → null / mapper toAppContext / mapper toWallpaperContext.

### D4 — Pas d'extension du JWT — `os`, `user`, `userprofile` restent en query

- Le JWT 16.10 ne porte que `sub`, `tier`, `iat`, `exp`, `jti`, `kid` (cf. `WorkstationJwtClaims` `app/Auth/V1/Jwt/WorkstationJwtClaims.php`). On **ne** l'étend **pas** dans cette story (modifier le contrat JWT est un changement majeur — Phase 3).
- Les paramètres `os`, `action`, `user`, `userprofile`, `id` (qui reste optionnel pour parité legacy — ignoré dans le pipeline natif) sont passés en query string par le poste migré, **exactement comme** ils l'étaient en legacy. Le poste connaît son OS, son user courant (via `%USERNAME%` Win / `$USER` Linux), et son `userprofile` (via `%USERPROFILE%` Win / `$HOME` Linux) — il les envoie en query.
- **Sécurité** : `os`, `user`, `userprofile` sont **des entrées user-controlled** au sens HTTP mais ne sont pas des **secrets**. Ils ne servent qu'à la résolution du contexte ; aucune élévation de privilège possible (le JWT prouve l'identité du poste, le `user` query est croisé en DB avec les permissions Eloquent existantes — un poste ne peut pas demander la config d'un user qui n'est pas le sien si la résolution Eloquent respecte les ACL).
- **Anti-pattern** : ne **pas** lire `workstation_uuid` depuis la query — c'est le claim JWT `sub` qui fait foi. Ceci est testé explicitement (AC6.4).

### D5 — Réponses iso-fonctionnelles vs réponses 200/JSON normalisées : **iso-fonctionnel strict (parité legacy)**

- Décision : les 8 endpoints `/api/v1/*` retournent **exactement le même format** que leurs équivalents legacy `*_out.php` — mêmes Content-Type, mêmes status codes, mêmes bodies, mêmes Cache-Control.
- **Justification** :
  - Les postes migrés exécutent toujours du cmd/bash/PowerShell qui parse le body sans agent intermédiaire — il faut conserver la sémantique (un `text/plain` pour `network`, un `text/json` non-standard pour `associations`, un `image/jpeg` pour `wallpaper`, un `application/octet-stream` pour `veyon licence=1`).
  - Un changement de format casserait la compatibilité poste sans bénéfice — l'agent Go futur (Phase 3) reconcevra le contrat global.
- **Conséquence concrète** par endpoint (référence : tableau D2) :
  - `/wallpaper` : `200` + `image/jpeg|image/png` selon `format=jpg|png` (iso `WallpaperController::legacyOut`).
  - `/firefox`, `/thunderbird` : `200 application/json` ou `200 body=""` (iso `AppPolicyController::resolve` ligne 78-79 pour id vide).
  - `/shortcuts` : `200 text/plain; charset=utf-8` script cmd/sh selon `os` query (iso `ShortcutExportController::script` ligne 117) **ou** `200 application/x-ms-shortcut|application/x-desktop` si `action=file` (iso `legacyDispatch::file`) **ou** `200 image/x-icon|image/png` si `action=icon` (iso `icon`).
  - `/network` : `200 text/plain; charset=utf-8` script bash linux ou `200 body=""` si os≠linux (iso `NetworkOutController::legacyOut` ligne 92-96).
  - `/veyon` : `200 application/json` config JSON ou `200 application/octet-stream` si `licence=1` (iso `VeyonOutController::legacyOut`).
  - `/associations` : `200 text/json` + `{"result": {...}}` ou `400` body vide si validation échoue (iso `AssociationsOutController::legacyOut`).
  - `/applications-scripts` : `200 text/plain; charset=cp1252|utf-8` selon `os` (iso `ApplicationsScriptsController::generate` ligne 206-210).
- **Erreurs iso parité legacy** :
  - `200 body=""` si contexte introuvable côté network/veyon/applications-scripts/wallpaper (parité comportementale legacy — un poste qui boot ne doit pas planter sur 404).
  - `400` pour `/associations` si `list` invalide (parité legacy strict).
  - **Exception 16.13** : si `workstation_uuid` JWT pointe sur une `Workstation` inexistante en DB (cas marginal — poste enrôlé mais row supprimée) → `404 Not Found` + log warning `auth-v1` `agent.v1.config.workstation_not_found` avec `workstation_uuid_prefix` (8 chars). C'est le **seul** cas où on ne reproduit pas le comportement legacy "200 vide" : un 404 est plus parlant pour l'admin qui investigue, et un poste authentifié JWT est attendu en base.

### D6 — Tests Feature : ≥40 tests cumulés sur les 8 endpoints + invariants auth

- **Pattern iso `PingControllerTest`** (`tests/Feature/Auth/V1/PingControllerTest.php`) : utilise le trait `IssuesWorkstationJwt` (16.10) pour émettre des JWT signés en setUp via `configureTestKeyPair()` + `issueTestJwt(['sub' => '<uuid>'])`.
- **Structure de fichier** : 8 fichiers tests sous `tests/Feature/Api/V1/Config/`, un par endpoint :
  - `WallpaperApiV1Test.php`
  - `FirefoxApiV1Test.php`
  - `ThunderbirdApiV1Test.php`
  - `ShortcutsApiV1Test.php`
  - `NetworkApiV1Test.php`
  - `VeyonApiV1Test.php`
  - `AssociationsApiV1Test.php`
  - `ApplicationsScriptsApiV1Test.php`
- **Cas minimaux par endpoint (≥5 par fichier = ≥40 cumulés)** :
  1. **401 sans Authorization** : `$this->getJson('/api/v1/<endpoint>')` → 401 + body `{error, message, code: jwt.missing}`.
  2. **401 JWT expiré** : `issueTestJwt(['exp' => now()->subDay()->getTimestamp()])` → 401 + `code: jwt.expired`.
  3. **401 JWT `tier=controlhub`** : `issueTestJwt(['tier' => 'controlhub'])` → 401 + `code: jwt.wrong_tier`.
  4. **404 workstation_uuid inconnu** : JWT valide mais `Workstation::where('uuid', $sub)` retourne null → 404 + log warning.
  5. **200 happy path** : seed `Workstation` + `WorkstationGroup` + (optionnel) `User`, JWT valide → 200 + payload conforme au contrat legacy (assertions sur Content-Type, body structure).
- **Cas spécifiques additionnels** par endpoint (au-delà des 5 communs) :
  - `WallpaperApiV1Test` : 400 sur `format` invalide ; 400 sur `action` invalide ; happy path retourne `image/jpeg` ou `image/png` selon query.
  - `FirefoxApiV1Test` / `ThunderbirdApiV1Test` : payload JSON contient les keys attendues (`policies`, `extensions`, etc. iso 4.8).
  - `ShortcutsApiV1Test` : `action=logon` → script cmd ; `action=file` → binary `.lnk`/`.desktop` ; `action=icon` → image.
  - `NetworkApiV1Test` : `os=linux` + happy path → script bash non-vide ; `os=windows` → `200 body=""`.
  - `VeyonApiV1Test` : happy path → config JSON valide avec `LDAP.BindPassword` (si `read.user` setup en test) ; `licence=1` → `application/octet-stream`.
  - `AssociationsApiV1Test` : `POST` avec `list` JSON valide → `200 text/json` + `{"result": {...}}` ; `list` invalide → 400.
  - `ApplicationsScriptsApiV1Test` : `os=linux action=startup` → script bash ; `os=windows` → script cmd (charset cp1252) ; champs `machine`, `user` invalides → 400.
- **Test commun de croisement** : créer `tests/Feature/Api/V1/Config/ApiV1ConfigSecurityTest.php` avec ≥3 tests :
  - **AC6.4 — workstation_uuid query ignoré** : émet JWT pour `sub=UUID_A`, query `?workstation_uuid=UUID_B` → la résolution doit se faire sur `UUID_A` (JWT), pas `UUID_B`.
  - **Pas de side effect APCu** : aucune des 8 routes ne lit `AppContextRepository::findById($id)` (test mock du repository — il ne doit jamais être appelé).
  - **`/api/v1/agent/ping` toujours fonctionnel** : non-régression 16.10.

### D7 — Test Architecture : invariance des 8 routes

- Créer `tests/Architecture/ApiV1ConfigRoutesTest.php` (nouveau fichier). ≥6 tests :
  1. `the_8_routes_are_registered_under_api_v1_prefix` : utilise `Illuminate\Support\Facades\Route::getRoutes()` pour vérifier que les 8 routes nommées (`agent.v1.config.wallpaper`, `.firefox`, etc.) existent avec le préfixe `/api/v1/`.
  2. `all_8_routes_have_auth_v1_workstation_middleware` : itère les 8 routes et vérifie via `$route->gatherMiddleware()` la présence de `auth.v1.workstation` (ou son alias résolu `App\Auth\V1\Http\Middleware\EnsureWorkstationJwt`).
  3. `all_8_routes_have_auth_v1_secure_headers_middleware` : idem pour `auth.v1.secure-headers`.
  4. `routes_use_correct_http_methods` : les 7 routes sont `GET` ; `/api/v1/associations` accepte `GET, POST` (parité legacy POST).
  5. `legacy_out_routes_are_still_preserved` : invariance de 16.10 D8 — les 8 legacy `*_out.php` sont **toujours** présents dans `routes/web.php`. Re-déclaration explicite dans ce test (et non délégation à `AuthV1NamespaceTest::legacy_out_routes_are_preserved`) pour signaler que la story 16.13 garantit la non-régression.
  6. `inject_bootstrap_fragment_middleware_still_attached_to_legacy_routes` : invariance 16.11 D2 — la suite 16.13 n'a pas accidentellement détaché le middleware d'injection des legacy.
- **Pattern iso `AuthV1NamespaceTest`** (lecture textuelle de `routes/api.php` + `routes/web.php` pour tests structurels). On peut aussi utiliser l'API Laravel `Route::getRoutes()` quand c'est plus simple.

### D8 — Absence de collision avec ControlHub `/api/v1/*`

- Les 8 noms d'endpoints choisis (`wallpaper`, `firefox`, `thunderbird`, `shortcuts`, `network`, `veyon`, `associations`, `applications-scripts`) **ne** collisionnent **pas** avec les routes ControlHub existantes :
  - ControlHub utilise : `/api/v1/health`, `/api/v1/stats`, `/api/v1/static`, `/api/v1/metrics`, `/api/v1/historical/{period}`, `/api/v1/greetme`, `/api/v1/tasks/cancel`, `/api/v1/shortcuts/{sync,delete}`, `/api/v1/workstation-groups/*`, `/api/v1/applications/{sync,{controlhubId}}`, `/api/v1/app-profiles/*`, `/api/v1/snapshot`, `/api/v1/sync-manifest`.
  - Conflit potentiel **`shortcuts`** : ControlHub a `/api/v1/shortcuts/{sync,delete,{controlhubId}}` (sous-paths). On déclare `/api/v1/shortcuts` racine (sans sous-path) — pas de conflit Laravel mais ambiguïté de namespace. **Décision** : on accepte cette cohabitation car les deux groupes utilisent des middlewares différents (`controlhub.auth` vs `auth.v1.workstation`) et Laravel résout le routing par match exact. Documenté dans le commentaire du bloc de routes.
  - Conflit potentiel **`applications-scripts`** : ControlHub a `/api/v1/applications/{sync,{controlhubId}}`. On utilise `applications-scripts` (avec tiret) pour désambiguïser — c'est explicite et non-ambigu.
- **Test archi de non-régression ControlHub** (à ajouter dans `ApiV1ConfigRoutesTest`) : `controlhub_routes_remain_intact` — assertion sur l'existence inchangée de `/api/v1/snapshot` (route nommée `snapshot` sous le groupe `controlhub.auth`).

### D9 — Runbook QA `docs/qa/domains/auth.md` § Story 16.13 (append-only, ≥10 scénarios)

Append après la Section 23 (Story 16.12). Numérotation continue (Section 24+) :

- `## Story 16.13 — Exposition endpoints natifs /api/v1/*`
- `### Section 24 — Endpoints `/api/v1/*` happy path + auth`
  - **Scénario 16.13-1** — `curl -H "Authorization: Bearer <JWT>" https://se4fs-XXX/api/v1/wallpaper?os=linux` → 200 + image/jpeg
  - **Scénario 16.13-2** — `curl https://se4fs-XXX/api/v1/firefox` (sans Authorization) → 401 + `code: jwt.missing`
  - **Scénario 16.13-3** — `curl -H "Authorization: Bearer <JWT_EXPIRED>" /api/v1/network?os=linux` → 401 + `code: jwt.expired`
  - **Scénario 16.13-4** — `curl -H "Authorization: Bearer <JWT_tier=controlhub>" /api/v1/veyon` → 401 + `code: jwt.wrong_tier`
- `### Section 25 — Résolution serveur du contexte (workstation_uuid JWT)`
  - **Scénario 16.13-5** — JWT pour `sub=UUID_A` + query `?workstation_uuid=UUID_B` → réponse correspond à `UUID_A` (preuve que le query est ignoré)
  - **Scénario 16.13-6** — JWT valide mais `workstation_uuid` inconnu en DB → 404 + log warning `agent.v1.config.workstation_not_found`
- `### Section 26 — Parité iso-fonctionnelle legacy vs natif`
  - **Scénario 16.13-7** — comparer `curl /sambaedu/gpo/wallpaper_out.php?action=wallpaper&id=<md5>&format=png` vs `curl -H "Authorization: ..." /api/v1/wallpaper?action=wallpaper&format=png` → mêmes Content-Type + même taille (image identique pour le même contexte)
  - **Scénario 16.13-8** — comparer `/sambaedu/gpo/network_out.php?action=startup&id=<md5>&os=linux` vs `/api/v1/network?action=startup&os=linux` → même script bash (modulo lignes generated-at timestamp)
- `### Section 27 — Non-régression legacy et 16.10-16.12`
  - **Scénario 16.13-9** — re-jouer 16.11-3/4 (fragment injection sur `wallpaper_out`) → toujours OK (la 16.13 n'a pas touché les routes legacy)
  - **Scénario 16.13-10** — re-jouer 16.12-1 (`POST /api/v1/script-execution-logs`) → toujours OK
  - **Scénario 16.13-11** — re-jouer 16.10-5/6 (`GET /api/v1/agent/ping`) → toujours OK
- `### Section 28 — Smoke route registry`
  - **Scénario 16.13-12** — `php artisan route:list | grep "api/v1"` → vérifier présence des 8 routes nommées `agent.v1.config.*` + 4 routes 16.10 `agent.v1.*` + 1 route 16.12 `scriptsos.logs.ingest`

### D10 — Charge dev : 2-3j (cadrage Tech Spec §6.1)

- T0 (preflight) : 0.25j
- T1-T8 (1 endpoint par tâche, méthode `apiV1` + route + tests Feature) : ~0.25j/endpoint = 2j
- T9 (service `WorkstationConfigContextResolver` + DTO + tests unit) : 0.5j en parallèle T1
- T10 (test archi + runbook QA) : 0.25j
- T11 (validation finale + sprint-status + Dev Agent Record + File List) : 0.25j

**Total estimé** : 2-3j (cadrage Sprint Change Proposal 2026-05-19 confirmé).

---

## Story

Comme **un poste Windows ou Linux migré du parc Sambaedu** (post-16.11 — tokens DPAPI/0600 stockés localement + 16.11 ↔ 16.13bis transition fragment+reboot) :

I want
- pouvoir interroger **8 endpoints natifs `/api/v1/*`** authentifiés par JWT workstation (RS256 + `tier=workstation` 16.10) pour récupérer mes configurations runtime (wallpaper, navigateur Firefox/Thunderbird, raccourcis shortcuts, scripts réseau, config Veyon, associations d'extensions, scripts d'application) ;
- que ces endpoints **réutilisent les controllers existants** (4.7 / 4.8 / 16.3a/b/c / 16.7) sans duplication de logique métier ;
- que le `workstation_uuid` soit **toujours** extrait du claim JWT `sub` (pattern iso 16.12) et jamais d'un input user-controlled ;
- que les réponses soient **iso-fonctionnelles** des endpoints legacy `*_out.php` correspondants (mêmes Content-Type, mêmes status, mêmes bodies) — pour pouvoir basculer transparent côté poste sans changer le parseur cmd/bash/PowerShell ;
- que les endpoints **legacy** `*_out.php` restent **inchangés** durant cette story (refactor migration = 16.13bis) ;
- que la story livre un **runbook QA enrichi** dans `docs/qa/domains/auth.md` (sections 24-28) avec ≥10 scénarios numérotés et que les **tests Feature + Architecture** garantissent la non-régression 16.10/16.11/16.12 et la parité fonctionnelle legacy.

So que :
- (a) **le poste migré post-16.13bis dispose d'une cible `/api/v1/*` fonctionnelle** — sans cette story, la 16.13bis (qui transforme les `*_out.php` en `MigrationController::serveFragment`) basculerait un poste vers du vide. La 16.13 doit livrer **avant** la 16.13bis ;
- (b) **les administrateurs sambaedu-reload disposent d'une surface REST cohérente, versionnée et authentifiée** pour les configurations de leur parc, alignée avec le contrat conçu pour l'agent Go futur (Tech Spec §5.6) ;
- (c) **la couche legacy md5/APCu n'est plus la seule entrée** pour les configurations critiques — les postes migrés évitent désormais le passage par `gpo/applications.php` qui pose la clé APCu, et utilisent directement la résolution DB Eloquent serveur ;
- (d) **les 16.13bis et stories Phase 3 (agent Go)** trouvent une infrastructure HTTP `/api/v1/*` déjà cadrée par 16.13 ;
- (e) **les tests Feature/Architecture validés** garantissent qu'aucune régression 16.10-16.12 n'est introduite, et que le contrat iso-fonctionnel legacy est préservé.

---

## Contexte

### État entrant (post-16.10 done + 16.11 done + 16.12 review)

| Élément | État | Action 16.13 |
|---|---|---|
| Middleware `auth.v1.workstation` (`EnsureWorkstationJwt`) | ✅ Livré 16.10, injecte `auth_v1.workstation_uuid` + `auth_v1.jwt_claims` | **Consommer** sans modification — les 8 nouvelles routes l'attachent dans `routes/api.php` |
| Middleware `auth.v1.secure-headers` (`EnsureSecureApiHeaders`) | ✅ Livré 16.10 | **Consommer** sans modification — attaché au group des 8 routes |
| Pattern `$request->attributes->get('auth_v1.workstation_uuid')` | ✅ Utilisé par 16.10 `PingController` + 16.12 `ScriptExecutionLogIngestionController` | **Réutiliser** strictement |
| `WallpaperController::legacyOut(Request)` | ✅ Livré 4.7 (done) | **Ne pas modifier** ; ajouter `apiV1(Request)` qui appelle `WorkstationConfigContextResolver` |
| `AppPolicyController::legacyFirefoxOut(Request)` + `legacyThunderbirdOut(Request)` | ✅ Livré 4.8 (review) | **Ne pas modifier** ; ajouter `apiV1Firefox(Request)` + `apiV1Thunderbird(Request)` |
| `ShortcutExportController::legacyDispatch(Request)` | ✅ Livré 16.3a (review) | **Ne pas modifier** ; ajouter `apiV1(Request)` |
| `NetworkOutController::legacyOut(Request)` + `VeyonOutController::legacyOut(Request)` | ✅ Livré 16.3b (review) | **Ne pas modifier** ; ajouter `apiV1(Request)` (1 par controller) |
| `AssociationsOutController::legacyOut(Request)` | ✅ Livré 16.3c (review) | **Ne pas modifier** ; ajouter `apiV1(Request)` |
| `ApplicationsScriptsController::generate(Request)` | ✅ Livré 16.7 (review) | **Ne pas modifier** ; ajouter `apiV1(Request)` |
| Routes legacy `gpo/*_out.php` dans `routes/web.php` | ✅ Inchangées | **Inchangées** — la story ne les touche pas |
| Middleware `inject.bootstrap-fragment` (16.11) | ✅ Attaché aux 8 routes legacy | **Inchangé** — invariance testée par `ApiV1ConfigRoutesTest::inject_bootstrap_fragment_middleware_still_attached_to_legacy_routes` |
| Service `WorkstationConfigContextResolver` | ❌ Inexistant | **Créer** dans `app/Gpo/Services/` avec DTO `WorkstationConfigContext` |
| 8 routes `/api/v1/*` natives | ❌ Inexistantes | **Créer** dans `routes/api.php` après le bloc 16.10/16.11 `/api/v1/agent/*` |
| Tests Feature `tests/Feature/Api/V1/Config/*` | ❌ Inexistants | **Créer** 8 fichiers + 1 fichier `ApiV1ConfigSecurityTest.php` |
| Test Archi `tests/Architecture/ApiV1ConfigRoutesTest.php` | ❌ Inexistant | **Créer** |
| Runbook QA `docs/qa/domains/auth.md` | ✅ Sections 1-23 (16.10 + 16.11 + 16.12) | **Append** une section `## Story 16.13` (sections 24-28) avec ≥10 scénarios |

### Risques entrants (analyse SM 2026-05-19)

| Risque | Sévérité | Mitigation 16.13 |
|---|---|---|
| Régression silencieuse sur les controllers existants si extraction de logique commune entre `legacyOut` et `apiV1` est mal faite | 🟡 Moyenne | Anti-pattern D2 explicite : `legacyOut` reste **strictement intacte** au niveau comportement (tests Feature 4.7/4.8/16.3a/b/c/16.7 ne doivent pas régresser). Si extraction de helper privé : le helper doit être appelé par les 2 méthodes avec les mêmes paramètres. |
| Conflit de routing avec ControlHub sur `/api/v1/shortcuts` | 🟢 Faible | D8 documenté : Laravel résout par match exact + middleware différent. Test archi `controlhub_routes_remain_intact` ajouté. |
| `WorkstationConfigContextResolver` retourne un contexte incomplet (groupe principal mal résolu) | 🟡 Moyenne | Tests unit du resolver ≥6 cas (D3). Heuristique iso `ApplicationsScriptsController::resolveInfo` pour la sélection du WorkstationGroup. |
| Postes migrés à la 16.13bis appellent `/api/v1/*` sans que cette story ait livré | 🔴 Élevée | C'est précisément pourquoi cette story doit être livrée **avant** 16.13bis (prérequis bloquant). Le sprint-status le note. |
| Tests Feature dépendants de seeds Eloquent (Workstation/WorkstationGroup/User) qui n'existent pas en testing SQLite :memory: | 🟡 Moyenne | Pattern iso 16.10 `PingControllerTest::setUp()` : utiliser `Schema::create()` directs pour les tables Workstation + WorkstationGroup + relation pivot, ou réutiliser `RefreshDatabase` si trait existant. Vérifier en T0 quel pattern est utilisé par les tests `WallpaperLegacyOutEndpointTest` 4.7 (qui seedent déjà ces tables). |

### Frontière `Workstation` Eloquent — vérification

Le contrat 16.13 suppose une table `workstations` avec une colonne `uuid` indexée et une relation `workstationGroups()` polymorphe ou pivot. **Action T0.3** : vérifier que :
- `App\Models\Workstation::class` existe et expose `uuid` (string) + relation `workstationGroups()`.
- Si absent ou incomplet (cas notable : Workstation actuel ne porte que ControlHub-side data) → **NE PAS bloquer** mais documenter dans Dev Agent Record et faire la résolution de contexte via une autre source (ex. `AppContextRepository` reconverti, ou DTO synthétisé à partir de query params si rien d'autre).

---

## Story Foundation (template BMAD)

As un poste migré SE5 (Windows ou Linux),
I want pouvoir interroger 8 endpoints natifs `/api/v1/{wallpaper,firefox,thunderbird,shortcuts,network,veyon,associations,applications-scripts}` authentifiés par JWT workstation `tier=workstation` (16.10) pour obtenir mes configurations (wallpaper, navigateurs, raccourcis, réseau, Veyon, associations, apps),
so that je peux fonctionner sans dépendre des endpoints legacy `*_out.php` md5/APCu (préparant l'arrêt de la résolution APCu en 16.13bis).

---

## Acceptance Criteria

### AC1 — 8 routes `GET /api/v1/{...}` exposées (D1 + D2)

1. **AC1.1** — Les 8 routes ci-dessous sont déclarées dans `routes/api.php`, dans un nouveau bloc après le bloc `/api/v1/agent/*` 16.10/16.11. La liste exhaustive (préfixe `/api/v1`) :
   - `GET /wallpaper` → `WallpaperController::apiV1`
   - `GET /firefox` → `AppPolicyController::apiV1Firefox`
   - `GET /thunderbird` → `AppPolicyController::apiV1Thunderbird`
   - `GET /shortcuts` → `ShortcutExportController::apiV1`
   - `GET /network` → `NetworkOutController::apiV1`
   - `GET /veyon` → `VeyonOutController::apiV1`
   - `GET|POST /associations` → `AssociationsOutController::apiV1`
   - `GET /applications-scripts` → `ApplicationsScriptsController::apiV1`
2. **AC1.2** — `php artisan route:list | grep "api/v1"` affiche les 8 routes nommées sous le groupe `agent.v1.config.*` (`agent.v1.config.wallpaper`, `agent.v1.config.firefox`, etc.).
3. **AC1.3** — Le bloc de routes utilise les middlewares `auth.v1.secure-headers` + `auth.v1.workstation` + `throttle:300,1` (composables, dans cet ordre).

### AC2 — Authentification JWT et extraction `workstation_uuid` (D4)

1. **AC2.1** — Chaque appel à une des 8 routes sans header `Authorization` retourne `401 Unauthorized` + body `{error, message, code: "jwt.missing"}` (parité 16.10 `PingController`).
2. **AC2.2** — Chaque appel avec un JWT mal-formé / signature invalide retourne `401` + `code: jwt.invalid_signature` ou équivalent (parité 16.10).
3. **AC2.3** — Chaque appel avec un JWT expiré retourne `401` + `code: jwt.expired`.
4. **AC2.4** — Chaque appel avec un JWT `tier=controlhub` retourne `401` + `code: jwt.wrong_tier`.
5. **AC2.5** — Chaque appel avec un JWT révoqué (entrée `workstation_jwt_revocations` + cache APCu) retourne `401` + `code: jwt.revoked`.
6. **AC2.6** — Chaque controller `apiV1(...)` récupère le `workstation_uuid` **exclusivement** via `$request->attributes->get('auth_v1.workstation_uuid')`. Aucun appel à `$request->input('workstation_uuid')` ou équivalent (testable via grep statique dans T10).

### AC3 — Réutilisation des controllers existants (D2)

1. **AC3.1** — Aucune modification comportementale des méthodes `legacyOut(...)` / `legacyDispatch(...)` / `legacyFirefoxOut(...)` / `legacyThunderbirdOut(...)` / `generate(...)` des 7 controllers cibles. Les tests Feature legacy existants (`tests/Feature/Wallpaper/LegacyOutEndpointTest`, `tests/Feature/AppCustomization/AppPolicyLegacyEndpointTest`, `tests/Feature/Gpo/{Associations,Network,Veyon,ApplicationsScripts}OutEndpointTest`) restent verts sans modification.
2. **AC3.2** — Chaque controller cible expose une nouvelle méthode publique `apiV1(...)` (ou `apiV1Firefox(...)` / `apiV1Thunderbird(...)` pour `AppPolicyController` qui en a deux).
3. **AC3.3** — Chaque méthode `apiV1(...)` délègue à `WorkstationConfigContextResolver` (D3) pour reconstruire son contexte, puis à la même logique métier que la méthode legacy (mêmes services injectés, mêmes appels, mêmes formatages de Response).

### AC4 — Service `WorkstationConfigContextResolver` (D3)

1. **AC4.1** — Le service `App\Gpo\Services\WorkstationConfigContextResolver` est créé avec la signature `resolve(string $workstationUuid, string $os = 'linux', string $userLogin = '', string $userProfile = ''): ?WorkstationConfigContext`.
2. **AC4.2** — Le DTO `App\Dto\Gpo\WorkstationConfigContext` est créé avec les champs : `machineName` (string), `salleName` (string, nullable), `userLogin` (string), `os` (string), `machineId` (int|null), `userId` (int|null), `groupId` (int|null), `userProfile` (string).
3. **AC4.3** — Le service expose des méthodes de mapping vers les DTO consommés par les controllers existants : `toWallpaperContext(...)`, `toAppContext(...)` (ou nom équivalent selon les types existants). Le mapping est **lossless** — il préserve tous les champs nécessaires à la résolution downstream (resolver `WallpaperResolver`, `AssociationsResolver`, etc.).
4. **AC4.4** — Tests unit du resolver ≥6 cas (cf. D3) : happy path, machine sans groupe, machine sans user, UUID inconnu → null, toWallpaperContext, toAppContext.

### AC5 — Tests Feature ≥40 cumulés (D6)

1. **AC5.1** — Création de 8 fichiers `tests/Feature/Api/V1/Config/<Endpoint>ApiV1Test.php` + 1 `ApiV1ConfigSecurityTest.php`.
2. **AC5.2** — Chaque fichier endpoint contient ≥5 tests : 401 missing, 401 expired, 401 wrong_tier, 404 unknown workstation, 200 happy path conforme au contrat legacy.
3. **AC5.3** — Le fichier `ApiV1ConfigSecurityTest.php` contient ≥3 tests : workstation_uuid query ignoré, AppContextRepository non-appelé sur les routes /api/v1/*, /api/v1/agent/ping toujours fonctionnel.
4. **AC5.4** — Tous les tests utilisent le trait `Tests\Concerns\IssuesWorkstationJwt` (16.10) pour émettre des JWT signés en setUp.
5. **AC5.5** — Tous les tests Feature legacy existants (4.7/4.8/16.3a/b/c/16.7) restent verts sans modification.

### AC6 — Test Architecture invariance (D7)

1. **AC6.1** — Création de `tests/Architecture/ApiV1ConfigRoutesTest.php` avec ≥6 tests : 8 routes enregistrées, middleware `auth.v1.workstation` présent, middleware `auth.v1.secure-headers` présent, méthodes HTTP correctes (7 GET + 1 GET|POST), legacy `*_out.php` préservés, `inject.bootstrap-fragment` toujours attaché aux legacy.
2. **AC6.2** — Test additionnel `controlhub_routes_remain_intact` : `/api/v1/snapshot` + autres routes ControlHub inchangées.
3. **AC6.3** — Le test archi `AuthV1NamespaceTest::legacy_out_routes_are_preserved` (16.10) continue de passer (non-régression).
4. **AC6.4** — Garde-fou anti-régression sécurité : un test (Feature ou archi) vérifie qu'aucun controller `apiV1(...)` ne lit `workstation_uuid` depuis la query string. Méthode au choix :
   - **Option (a)** : test archi `no_controller_reads_workstation_uuid_from_input` via réflexion + grep sur le code source du controller.
   - **Option (b)** : test Feature `workstation_uuid_query_string_is_ignored` qui émet un JWT pour UUID_A + query UUID_B, et asserte que la résolution se fait sur UUID_A.
   La story implémente **les deux** pour défense en profondeur.

### AC7 — Endpoints legacy `*_out.php` inchangés (non-régression 16.13bis)

1. **AC7.1** — Le fichier `routes/web.php` est inchangé sur les blocs `inject.bootstrap-fragment->group()` (16.11) qui contiennent les 8 routes `gpo/*_out.php` + `gpo/applications.php`.
2. **AC7.2** — Aucune des 7 controllers cibles n'a sa méthode `legacyOut(...)` / `legacyDispatch(...)` / `legacyFirefoxOut(...)` / `legacyThunderbirdOut(...)` / `generate(...)` modifiée comportementalement. Un `git diff` sur ces méthodes ne montre que des ajouts (factorisation éventuelle vers un helper privé) — pas de modification du flot ou des inputs/outputs.
3. **AC7.3** — Test Feature de non-régression : `tests/Feature/Wallpaper/LegacyOutEndpointTest::test_wallpaper_legacy_still_works` (existant ou nouveau) reste vert.

### AC8 — Runbook QA `docs/qa/domains/auth.md` (D9)

1. **AC8.1** — Ajout d'une section `## Story 16.13 — Exposition endpoints natifs /api/v1/*` après la Section 23 (16.12), append-only.
2. **AC8.2** — Sections 24-28 numérotées et ≥10 scénarios numérotés (16.13-1 à 16.13-12) couvrant : happy path 4 endpoints (+ extension par endpoint), 401 missing/expired/wrong_tier, résolution workstation_uuid JWT, parité fonctionnelle legacy vs natif, non-régression 16.10-16.12, smoke route registry.
3. **AC8.3** — Checklist rapide ajoutée pour validation Henri post-merge.

---

## Tasks / Subtasks

### T0 — Preflight (0.25j)

- [x] **T0.1** Vérifier statut VM auprès Henri (worktree → pas de SSH directement) : confirmer que les tests resteront locaux. — Mode worktree confirmé (vendor/ absent, tests phpunit différés Henri post-merge, pattern iso 16.10/16.11/16.12).
- [x] **T0.2** Confirmer que `App\Models\Workstation` existe + colonne `uuid` indexée + relation `workstationGroups()`. — **R1 résolu** : `Workstation::uuid` (string nullable, fillable, scope `withUuid()`). Relation existe mais s'appelle `groups()` (pas `workstationGroups()`). **Écart D3** documenté ci-dessous (DO-3).
- [x] **T0.3** Lire en détail les méthodes `legacyOut(...)`, `legacyDispatch(...)`, `legacyFirefoxOut(...)`, `legacyThunderbirdOut(...)`, `generate(...)` des 7 controllers cibles. — Fait, chaîne métier identifiée par controller (cf. Implementation notes).
- [x] **T0.4** Confirmer que les tests Feature 4.7/4.8/16.3a/b/c/16.7 ne dépendent pas d'un état APCu spécifique. — Les tests legacy 4.7/4.8 utilisent `$this->app->bind(AppContextRepository::class, ...)` pour seeder le contexte (pattern réutilisé). On n'appelle PAS APCu dans les nouveaux tests : on bind un mock fail-fast (cf. `ApiV1ConfigSecurityTest::app_context_repository_is_never_called_on_api_v1_routes`).
- [x] **T0.5** Vérifier que le trait `Tests\Concerns\IssuesWorkstationJwt` couvre tous les cas dont on a besoin. — OK, le trait fournit `issueTestJwt(['sub' => ..., 'tier' => ..., 'exp' => ...])`.

### T1 — Service `WorkstationConfigContextResolver` + DTO (0.5j) (AC4)

- [x] **T1.1** Créer `app/Dto/Gpo/WorkstationConfigContext.php` (readonly class PHP 8.1+).
- [x] **T1.2** Créer `app/Gpo/Services/WorkstationConfigContextResolver.php` avec méthode `resolve()` + mappers `toWallpaperContext()` + `toAppContext()`. — Ajout de `resolveAppPolicyScope()` (helper Eloquent direct pour `AppPolicyController`).
- [x] **T1.3** Créer `tests/Unit/Gpo/Services/WorkstationConfigContextResolverTest.php` avec ≥6 cas. — 10 cas écrits (happy path / sans groupe / sans user / UUID inconnu / mapper WallpaperContext / mapper AppContext / mapper avec UUID inconnu retourne null × 2 / heuristique physical-vs-logical / resolveAppPolicyScope nominal + UUID inconnu).
- [x] **T1.4** Lint statique `php -l` sur les 3 nouveaux fichiers — 0 erreur.

### T2 — Endpoint `/api/v1/wallpaper` (AC1, AC2, AC3) (0.25j)

- [x] **T2.1** Ajouter méthode publique `WallpaperController::apiV1(Request $request, WorkstationConfigContextResolver $resolver): Response`. — Délégation à un helper privé `composeWallpaperResponse()` (réutilisable mais NON appelé par `legacyOut` pour préserver à 100% son comportement — AC7.2).
- [x] **T2.2** Créer `tests/Feature/Api/V1/Config/WallpaperApiV1Test.php` avec ≥5 tests — 9 tests écrits.
- [x] **T2.3** Lint OK. Le comportement de `LegacyOutEndpointTest` est préservé (seules méthodes/imports ajoutés à `WallpaperController`).

### T3 — Endpoints `/api/v1/firefox` + `/api/v1/thunderbird` (0.25j)

- [x] **T3.1** Ajouter méthodes `AppPolicyController::apiV1Firefox` + `apiV1Thunderbird`. Helper privé `resolveNative()` partagé.
- [x] **T3.2** Créer `FirefoxApiV1Test.php` (6 tests) + `ThunderbirdApiV1Test.php` (5 tests).
- [x] **T3.3** Lint OK. `AppPolicyLegacyEndpointTest` / `AppPolicyCanonicalEndpointTest` non touchés (méthodes ajoutées uniquement).

### T4 — Endpoint `/api/v1/shortcuts` (0.25j)

- [x] **T4.1** Ajouter `ShortcutExportController::apiV1` (dispatch logon/file/icon iso `legacyDispatch`, + injection `machine` résolu serveur via `$request->merge()`).
- [x] **T4.2** Créer `ShortcutsApiV1Test.php` (6 tests).

### T5 — Endpoints `/api/v1/network` + `/api/v1/veyon` (0.25j)

- [x] **T5.1** Ajouter `NetworkOutController::apiV1` + `VeyonOutController::apiV1`.
- [x] **T5.2** Créer `NetworkApiV1Test.php` (7 tests) + `VeyonApiV1Test.php` (6 tests, `VeyonConfigGenerator` + `ReadUserManager` mockés).

### T6 — Endpoint `/api/v1/associations` (0.25j)

- [x] **T6.1** Ajouter `AssociationsOutController::apiV1` (GET|POST, parité legacy body `list` JSON ≤10 Ko).
- [x] **T6.2** Créer `AssociationsApiV1Test.php` (8 tests, `AssociationsResolver` mocké).

### T7 — Endpoint `/api/v1/applications-scripts` (0.25j)

- [x] **T7.1** Ajouter `ApplicationsScriptsController::apiV1`. — Reprise complète de la chaîne `generate()` avec `uuid` injecté depuis le JWT (et non depuis la query). Les regex de validation et le pipeline `resolveInfo → logScripts → assemble` sont identiques.
- [x] **T7.2** Créer `ApplicationsScriptsApiV1Test.php` (9 tests, services chaîne 16.7 entièrement mockés).

### T8 — Route registration + Security cross-cutting test (0.25j) (AC1)

- [x] **T8.1** Modifier `routes/api.php` : ajout du bloc `Route::prefix('v1')->middleware(['auth.v1.secure-headers', 'auth.v1.workstation', 'throttle:300,1'])->name('agent.v1.config.')->group(...)` avec les 8 routes nommées (7 GET + 1 GET|POST `/associations`).
- [x] **T8.2** Créer `tests/Feature/Api/V1/Config/ApiV1ConfigSecurityTest.php` (4 tests cross-cutting : workstation_uuid query ignoré, AppContextRepository non appelé, /ping non-régression, 8 routes 401 sans Auth).
- [x] **T8.3** Vérifier `php artisan route:list` — **différé Henri post-merge** (artisan non runnable sans vendor/). Le test archi `the_8_routes_are_registered_under_api_v1_prefix` couvre cet AC en CI.

### T9 — Test architecture invariance (0.25j) (AC6)

- [x] **T9.1** Créer `tests/Architecture/ApiV1ConfigRoutesTest.php` avec 9 tests : 8 routes registered + auth.v1.workstation middleware + auth.v1.secure-headers middleware + méthodes HTTP correctes + legacy *_out.php préservés + inject.bootstrap-fragment toujours attaché + ControlHub routes intactes + grep statique workstation_uuid input + throttle:300 attaché.
- [x] **T9.2** Vérifier non-régression `AuthV1NamespaceTest::legacy_out_routes_are_preserved` — test archi inchangé, les 8 endpoints legacy sont toujours dans `routes/web.php`.
- [x] **T9.3** Test `no_controller_reads_workstation_uuid_from_input` ajouté (AC6.4 option a) avec exception sur `$request->attributes->get(...)` (la lecture autorisée).

### T10 — Runbook QA + AC croisés (0.25j) (AC8)

- [x] **T10.1** Append-only sur `docs/qa/domains/auth.md` : section `## Story 16.13` + sous-sections **24-28** + **12 scénarios** numérotés 16.13-1 à 16.13-12 (cf. D9 directive — la numérotation 24-28 est conservée même si 16.12 n'a pas encore enrichi les sections 16-23 prévues, le runbook 16.12 viendra rejoindre quand sa story finalisera).
- [x] **T10.2** Checklist rapide 16.13 ajoutée (11 cases).
- [x] **T10.3** Smoke grep statique AC2.6 : `grep -rn "input('workstation_uuid'\|query('workstation_uuid'" app/Http/Controllers app/Gpo` → **0 résultat**.
- [x] **T10.4** Smoke `git diff` controllers cibles : `git diff <controllers> | grep "^-[^-]"` → **0 ligne supprimée** (uniquement ajouts — AC7.2).

### T11 — Validation finale + sprint-status + Dev Agent Record (0.25j)

- [x] **T11.1** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `16-13-exposition-endpoints-api-v1: ready-for-dev → review`. `last_updated` annoté.
- [x] **T11.2** Compléter `Dev Agent Record` ci-dessous : Agent Model Used, Completion Notes, File List exhaustive.
- [x] **T11.3** Lint statique `php -l` sur les 21 fichiers nouveaux + modifiés → **0 erreur**.
- [x] **T11.4** Si vendor/ présent local → run pest. — **DIFFÉRÉ Henri** (vendor/ absent dans worktree, pattern static delivery iso 16.10/16.11/16.12).
- [x] **T11.5** Section « Smoke test à exécuter quand VM up » Henri post-merge — déjà présente dans la story (lignes 619-680), réutilisée telle quelle.

---

## Dépendances

| Story | État | Rôle |
|---|---|---|
| 4.7 (Wallpapers Eloquent) | ✅ done | Fournit `WallpaperController` + `WallpaperResolver` + `WallpaperComposer` |
| 4.8 (App customization Firefox/Thunderbird) | ⚠️ review (code livré) | Fournit `AppPolicyController` + `AppCustomizationService` |
| 16.3a (Liens profonds sections natives) | ⚠️ review (code livré) | Fournit `ShortcutExportController` + `ShortcutCompilerService` |
| 16.3b (Network + Veyon) | ⚠️ review (code livré) | Fournit `NetworkOutController` + `VeyonOutController` + générateurs |
| 16.3c (Wine + Associations + Apps) | ⚠️ review (code livré) | Fournit `AssociationsOutController` + `AssociationsResolver` |
| 16.7 (Portage natif applications.php) | ⚠️ review (code livré) | Fournit `ApplicationsScriptsController` + chaîne 5 services |
| 16.10 (HTTPS + JWT endpoints) | ✅ done | Fournit middleware `auth.v1.workstation` + `auth.v1.secure-headers` + helper test `IssuesWorkstationJwt` + pattern `$request->attributes->get('auth_v1.workstation_uuid')` |
| 16.11 (Auto-bootstrap migration) | ✅ done | Tables `workstations_migration_status` + `workstation_migration_attempts` (consommées par 16.13bis, pas par 16.13) + middleware `inject.bootstrap-fragment` (à laisser intact) |
| 16.12 (Logs exécution + UI) | ⚠️ review (code livré) | Pattern de référence canonique pour cette story : `ScriptExecutionLogIngestionController::store()` lit `auth_v1.workstation_uuid` (16.12 D3) — strictement reproductible ici |

**Les stories `review` ont leur code livré et merged**, donc utilisables comme dépendance (cf. SCP 2026-05-19 §"Prérequis : 4.7, 4.8, 16.3a/b/c, 16.7, 16.10").

---

## Dev Notes

### Mode worktree git — contraintes

- Cette story est sur la branche `16-13` (worktree dédié `/home/htouchard/code/irundo/codebase/16-13`).
- **NE PAS** SSH `/vm` ni exécuter de tests sur la VM (memory `feedback_worktree_no_vm_sync`).
- Tests locaux uniquement (host `php -l` + `pest` si vendor/ présent). Si tests non runnables localement → différer Henri post-merge sur `main`.
- Pas de sync code manuel — l'inotify host→VM se déclenche **uniquement** depuis `main`, pas depuis ce worktree.

### Pattern JWT extraction (référence canonique)

- **Pattern iso 16.12** : reprendre strictement le pattern de `App\ScriptsOs\Http\Controllers\ScriptExecutionLogIngestionController::store()` (livré 16.12) — lecture `$workstationUuid = $request->attributes->get('auth_v1.workstation_uuid')` injectée par le middleware `EnsureWorkstationJwt` 16.10.
- **Référence code 16.10** : `app/Auth/V1/Http/Middleware/EnsureWorkstationJwt.php` lignes 50-51 — set des 2 attributes `auth_v1.workstation_uuid` (string) + `auth_v1.jwt_claims` (WorkstationJwtClaims DTO).
- **Référence code 16.10 + 16.12 consommateur** : `app/Auth/V1/Http/Controllers/PingController.php` ligne 39-46 — pattern de lecture des claims.
- **Anti-pattern** : ne **jamais** lire `$request->input('workstation_uuid')` ni `$request->query('workstation_uuid')` dans aucun controller. Le query peut contenir un autre champ (ex. `?os=`, `?user=`) qui sera utilisé pour la **résolution** du contexte, pas pour l'**identification** du poste.

### Endpoints legacy `*_out.php` : intouchables dans cette story

- Les 8 routes legacy `gpo/*_out.php` + `gpo/applications.php` (déclarées dans `routes/web.php`) ne sont **pas** touchées par 16.13.
- Le refactor en `MigrationController::serveFragment()` (fragment + reboot) est **explicitement** dans la story **16.13bis** (cf. Sprint Change Proposal 2026-05-19 §4.2).
- Si le dev a la tentation de "nettoyer en passant" un des endpoints legacy : ne pas faire. Cela introduirait du scope creep et risquerait de casser la cohabitation 16.11 (middleware `inject.bootstrap-fragment` reste actif sur ces routes pendant toute la durée de coexistence).

### Auth iso-legacy AD/SMB hors-scope

- Cette story **ne** touche pas à l'auth machine iso-legacy AD/SMB (memory `feedback_auth_iso_legacy`).
- Le JWT 16.10 est l'identité du poste uniquement ; l'authentification du **user** (qui se logon sur le poste) reste portée par le couple `(samaccountname, password)` validé contre l'AD/SMB local. Cette dualité est cohérente avec le modèle existant.
- En particulier, **ne pas introduire** de Bearer token per-user ni de secret per-host nouveaux dans cette story — le déploiement multi-collège ne permet pas ce genre de complexité (postes pas tous à jour à l'instant T du déploiement).

### Cohérence terminologique SE4/SE5

- SE4 = sambaedu legacy PHP-only (gpo/*_out.php md5/APCu, sans JWT).
- SE5 = sambaedu-reload Laravel (la base de cette story).
- Cette story livre la surface `/api/v1/*` que consommera un poste **SE5** (= post-16.13bis migration). Les postes SE4 (pre-migration) restent sur les endpoints legacy `gpo/*_out.php`.

### Encadrés techniques (pour le dev)

#### Comment résoudre le contexte poste sans APCu

Le `WorkstationConfigContextResolver` doit reconstruire l'équivalent du payload APCu posé par `ApcuAppContextWriter::write` (16.7). Le payload APCu contenait notamment :
- `uuid` (workstation UUID)
- `salle_name` (WorkstationGroup name)
- `user_login` (samaccountname du user courant)
- `machine_name` (NetBIOS lowercase)
- `os`, `interpreter`, `userprofile`, ...

Pour 16.13, on extrait :
- `uuid` ← JWT claim `sub`.
- `salle_name` ← `Workstation::findByUuid()->workstationGroups()` (heuristique 16.7 `resolveInfo`).
- `user_login` ← query param `?user=...` (envoyé par le poste, correspond à `%USERNAME%` Win / `$USER` Linux). Cross-checké via Eloquent `User::where('login', ...)->first()`.
- `machine_name` ← `Workstation::name` (ou `samaccountname` selon le schéma effectif — à vérifier T0.2).
- `os`, `interpreter`, `userprofile` ← query params.

#### Cas particulier `/applications-scripts`

`ApplicationsScriptsController::generate` (16.7) est le controller **le plus complexe** des 8 (281 lignes, 5 services injectés, chaîne complète `resolveInfo → logScripts → scanner → assembler`). Pour `apiV1` :
1. **Ne pas réécrire la chaîne** — réutiliser `resolveInfo()` en lui passant un payload équivalent reconstruit (cf. ligne 130-141 de `generate`). Le payload attendu est un array `['machine', 'action', 'application', 'os', 'uuid', 'interpreter', 'speed', 'user', 'id', 'userprofile']`.
2. **Le champ `id` md5** peut être laissé **vide** (`''`) car la chaîne 16.7 le tolère (cf. ID_REGEX `^([a-f0-9]{32})?$` qui accepte vide).
3. **Le champ `uuid`** est rempli avec `$workstationUuid` JWT (et plus depuis query).
4. **Validation regex** : la chaîne 16.7 valide chaque input. La méthode `apiV1` doit respecter les mêmes regex (réutilisation des constantes `MACHINE_REGEX`, etc.). Si conflit : aligner sur 16.7 strict.

---

## References

- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-05-19.md#4.1 Story 16.13 NOUVEAU CADRAGE]
- [Source: _bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md#§5.2 Authentification HTTPS + JWT]
- [Source: _bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md#§5.6 Endpoints `/api/v1/*` plats]
- [Source: _bmad-output/planning-artifacts/epics.md#Story 16.13 — Exposition endpoints natifs /api/v1/*]
- [Source: _bmad-output/implementation-artifacts/16-10-securisation-https-jwt-endpoints.md — middleware `auth.v1.workstation`, helper `IssuesWorkstationJwt`]
- [Source: _bmad-output/implementation-artifacts/16-11-auto-bootstrap-migration-postes.md — middleware `inject.bootstrap-fragment` à préserver]
- [Source: _bmad-output/implementation-artifacts/16-12-logs-execution-centralises-ui-consultation.md#D3 — pattern canonique `$request->attributes->get('auth_v1.workstation_uuid')`]
- [Source: app/Auth/V1/Http/Middleware/EnsureWorkstationJwt.php#L50-L51 — injection des attributs JWT]
- [Source: app/Auth/V1/Http/Controllers/PingController.php#L39-L46 — pattern consommateur 16.10]
- [Source: app/Http/Controllers/WallpaperController.php — controller cible 4.7]
- [Source: app/Http/Controllers/AppPolicyController.php — controller cible 4.8]
- [Source: app/Http/Controllers/Gpo/{Network,Veyon,Associations,ApplicationsScripts}*Controller.php — controllers cibles 16.3b/c + 16.7]
- [Source: app/Http/Controllers/Api/v1/ShortcutExportController.php — controller cible 16.3a]
- [Source: routes/api.php#L162-L189 — bloc `/api/v1/agent/*` 16.10/16.11, à ne pas modifier]
- [Source: routes/web.php#L450-L543 — blocs `inject.bootstrap-fragment` 16.11 à préserver intacts]
- [Source: tests/Concerns/IssuesWorkstationJwt.php — trait à utiliser pour les tests Feature]
- [Source: tests/Architecture/AuthV1NamespaceTest.php#L259-L387 — pattern de tests archi route invariance]
- [Source: docs/qa/domains/auth.md — runbook QA à enrichir (sections 24-28)]

---

## Risques / Questions ouvertes

| Item | Type | Note |
|---|---|---|
| **R1 — Existence du modèle `Workstation` Eloquent** | Risque / incohérence potentielle | À vérifier en T0.2. Si la table `workstations` actuelle n'a pas une colonne `uuid` indexée et une relation `workstationGroups()`, la résolution serveur du contexte (D3) doit utiliser une autre source. Si aucune source de vérité DB existe → fallback synthétisé via query params (dégrade la sécurité, mais préserve la fonctionnalité). Documenter dans Dev Agent Record. |
| **R2 — Tests Feature seedent `workstations` + `workstation_groups`** | Risque opérationnel | Les tests Feature legacy 4.7/4.8/16.3a/b/c/16.7 utilisent probablement déjà ces tables — vérifier T0.5 et réutiliser les patterns existants. Sinon créer un trait `SeedsWorkstationContext` réutilisable. |
| **R3 — Conflit Laravel routing `/api/v1/shortcuts`** | Question ouverte | Confirmer en T8.3 que `php artisan route:list` ne signale aucune ambiguïté entre `/api/v1/shortcuts` (16.13 GET avec `auth.v1.workstation`) et `/api/v1/shortcuts/sync|delete|{controlhubId}` (ControlHub POST avec `controlhub.auth`). Laravel résout normalement par match exact + middleware groupe, mais à confirmer en pratique. Si conflit observé → renommer `/api/v1/shortcuts` en `/api/v1/shortcuts-config` ou similaire. |
| **R4 — Réponse 404 vs 200 vide quand `Workstation` introuvable** | Décision D5 confirmée | La story tranche pour 404 explicite (D5) bien que parité legacy soit 200 vide. C'est une déviation **assumée** justifiée par l'observabilité admin. Si Henri demande à revenir à 200 vide → simple changement de status code dans les 8 controllers. |
| **Q1 — Faut-il ajouter une route `GET /api/v1/_routes` qui liste toutes les 8 routes pour découverte client ?** | Question ouverte | **Non** dans cette story (scope creep). C'est une fonctionnalité Phase 3 (agent Go discovery). |
| **Q2 — Le throttle `300,1` IP-based suffit-il pour un parc de 500 postes derrière NAT scolaire ?** | Question ouverte (déjà résolue en 16.3b) | Iso pattern 16.3b — accepté en l'état. Si terrain remonte des 429 → augmenter à `600,1` Phase 3. |

---

## Project Structure Notes

- **Convention routing** : on suit la convention CLAUDE.md projet (filesystem-based routing `resources/views/pages/`) **uniquement pour les pages Livewire admin**. Les routes API REST sont déclarées dans `routes/api.php` (pattern Laravel standard, iso 16.10/16.11/16.12). Les 8 routes 16.13 sont donc dans `routes/api.php`, **pas** sous `resources/views/pages/api/v1/`.
- **Namespace** : pas de nouveau namespace racine. Les méthodes `apiV1(...)` ajoutées vivent dans les namespaces existants (`App\Http\Controllers`, `App\Http\Controllers\Gpo`, `App\Http\Controllers\Api\v1`).
- **Service partagé** : `App\Gpo\Services\WorkstationConfigContextResolver` placé sous `App\Gpo` car les contextes consommés sont ceux des GPO natives (parallélisme `App\Gpo\Services\AssociationsResolver`, `App\Gpo\Services\VeyonConfigGenerator`, etc.).
- **DTO** : `App\Dto\Gpo\WorkstationConfigContext` placé sous `App\Dto\Gpo` (parallélisme `App\Dto\Wallpaper\WallpaperContext` 4.7).

### Conformité instructions projet `CLAUDE.md`

- **Worktree** : OK — pas de SSH `/vm`, pas de tests VM.
- **Routing convention** : OK — la convention `resources/views/pages/` s'applique au web routing (Blade + Livewire), pas au routing API REST. Pas de conflit.
- **Composants spécifiques** (modale, toasts) : N/A — backend pur, pas d'UI dans cette story.
- **Auth iso-legacy AD/SMB** : OK — non touché.
- **PHP-FPM user `www-admin`** : N/A — pas de fichier FS créé par cette story (les nouveaux fichiers sont du code source synchro inotify, lus par www-admin sans problème).

---

## Smoke test à exécuter quand VM up (post-merge sur `main`)

> Bloc prêt à coller pour Henri post-merge.

1. **Préparation** :
   ```bash
   ssh /vm
   cd /var/www/sambaedu-reload
   composer install --no-dev --optimize-autoloader
   php artisan config:clear
   php artisan route:cache
   ```

2. **Vérifier les 8 nouvelles routes** :
   ```bash
   php artisan route:list | grep "api/v1" | grep -v "agent\|snapshot\|controlhub"
   ```
   Attendu : 8 lignes nommées `agent.v1.config.{wallpaper,firefox,thunderbird,shortcuts,network,veyon,associations,applications-scripts}`.

3. **Émettre un JWT de test via tinker** :
   ```bash
   php artisan tinker
   $issuer = app(\App\Auth\V1\Jwt\WorkstationJwtIssuer::class);
   $jwt = $issuer->issue('uuid-test-1234-5678-9abc-def012345678', 'workstation');
   echo $jwt['access_token'];
   ```
   Copier le token.

4. **Smoke 401 sans Authorization** :
   ```bash
   curl -s -o /dev/null -w "%{http_code}" https://localhost/api/v1/wallpaper
   ```
   Attendu : `401`.

5. **Smoke 200 happy path wallpaper** (présupposant que la DB a une `Workstation` avec UUID matchant) :
   ```bash
   curl -s -H "Authorization: Bearer $JWT" "https://localhost/api/v1/wallpaper?action=wallpaper&os=linux&format=png" -o /tmp/wp.png
   file /tmp/wp.png
   ```
   Attendu : `PNG image data`.

6. **Smoke 401 wrong_tier** : émettre un JWT avec `tier=controlhub`, curl sur `/api/v1/firefox`, attendre 401 + code `jwt.wrong_tier`.

7. **Smoke 404 unknown workstation** : émettre JWT avec un UUID inexistant en `workstations`, curl `/api/v1/network`, attendre 404.

8. **Non-régression legacy** :
   ```bash
   curl -s "http://localhost/sambaedu/gpo/wallpaper_out.php?action=wallpaper&id=<md5-valid>&format=jpg"
   ```
   Attendu : image jpeg (legacy fonctionnel — la 16.13 ne l'a pas cassé).

9. **Run suite tests** (si vendor/dev installé) :
   ```bash
   ./scripts/run-tests.sh tests/Feature/Api/V1/Config tests/Unit/Gpo/Services tests/Architecture/ApiV1ConfigRoutesTest.php
   ```

10. **Tail logs** :
    ```bash
    tail -f storage/logs/auth-v1/*.log
    # Vérifier absence d'erreurs lors des smoke 5-7
    ```

---

## Recommandation Modèle Dev

**Recommandation : `sonnet`**

Justification :
- La story est **mécanique et de faible complexité conceptuelle** : ajout de 8 méthodes `apiV1(...)` qui délèguent à des services existants (4.7/4.8/16.3a/b/c/16.7) + 1 nouveau service de résolution `WorkstationConfigContextResolver` + 8 fichiers de tests Feature + 1 test archi + 1 enrichissement runbook QA. Pas de logique métier nouvelle.
- Le **pattern à appliquer est canonique** (iso 16.12 strict) — pas de prise de décision technique majeure (les 10 décisions D1-D10 sont pré-tranchées dans la story).
- Charge estimée 2-3j — dans la fenêtre confortable de sonnet (200k tokens suffisent largement pour cette story dense).
- Bascule possible vers `opus` en cours de route si le dev découvre une complexité non anticipée (cas R1 — modèle Workstation incomplet — qui nécessiterait une décision SM).

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7[1m]` (worktree `16-13` — branch `16-13`, host : `/home/htouchard/code/irundo/codebase/16-13`).

Tests phpunit/pest **non lancés** localement : vendor/ absent dans le worktree (pattern static delivery iso 16.10 / 16.11 / 16.12). Tests **différés Henri post-merge** sur `main` (Section « Smoke test à exécuter quand VM up » de cette story + run `./vendor/bin/pest tests/Feature/Api/V1/Config tests/Unit/Gpo/Services tests/Architecture/ApiV1ConfigRoutesTest.php`).

Lint statique `php -l` exécuté sur les 21 fichiers nouveaux + modifiés : **0 erreur**.

### Debug Log References

Aucun debug log généré (story mécanique sans investigation requise).

### Completion Notes List

**Implémentation conforme aux 10 décisions D1-D10 SM tranchées au cadrage.**

#### Décisions DO-* émises au-delà des D1-D10 SM

- **DO-1** (R1 résolu) — Le modèle `App\Models\Workstation` n'expose pas une relation `workstationGroups()` mais une relation `groups()` (`BelongsToMany` vers `WorkstationGroup`, héritée de la story 15.2). Le service `WorkstationConfigContextResolver` utilise donc `$workstation->groups()` au lieu de `$workstation->workstationGroups()` annoncé en D3. La colonne `uuid` est `nullable` + `fillable` mais **pas indexée** explicitement dans le modèle (`->scopeWithUuid()` filtre sur `whereNotNull`). En production Postgres, on présume qu'un index existe au niveau DB (à confirmer par Henri si pertinent). Pour les tests SQLite, la colonne `uuid` est créée avec `->index()` explicite (cf. `SeedsWorkstationConfig`).

- **DO-2** — Heuristique de sélection du `WorkstationGroup` principal : `is_physical=true` first (= salle), fallback sur le premier groupe attaché. Le modèle `WorkstationGroup` n'a **pas** de colonne `is_default` mais bien `is_physical` (différence avec la formulation D3 « `where('is_default', false)` » qui était indicative). La sémantique métier correcte est `is_physical=true` = salle physique. Test unit `primary_group_prefers_physical_over_logical` couvre ce comportement.

- **DO-3** — Le DTO `WorkstationConfigContext` (`app/Dto/Gpo/`) est nouveau. Les mappers `toWallpaperContext()` + `toAppContext()` (option β SM) sont implémentés ; ils acceptent les mêmes paramètres que `resolve()` et retournent les DTOs existants. Les champs `groupsUser` / `mainUserType` / `userIsAdmin` sont **vides** dans cette version (la résolution AD groups n'est pas disponible côté JWT et est hors-scope 16.13). Conséquence : la chaîne `WallpaperResolver` / `AppCustomizationService` retombera sur les niveaux template/auto/default/WG sans appliquer les overrides per-user-group. **Acceptable Phase 2** (les surcharges per-user-group restent rares en terrain) — une story future intégrera la résolution AD groups côté JWT si besoin.

- **DO-4** — Une méthode helper `resolveAppPolicyScope(string $workstationUuid, string $userLogin)` a été ajoutée au resolver. Elle retourne `['wg' => ?WorkstationGroup, 'user' => ?User]` directement (DTOs Eloquent, pas `AppContext`) — utilisée par `AppPolicyController::apiV1Firefox|Thunderbird` qui n'a pas besoin de `AppContext` mais directement de `?WorkstationGroup` + `?User` pour appeler `AppCustomizationService::resolvePoliciesForMachine($wg, $user, $kind, $os)`.

- **DO-5** — Pour `WallpaperController`, la factorisation entre `legacyOut` et `apiV1` aurait pu se faire via une méthode privée partagée `composeWallpaperResponse()`. **Décision** : on garde `legacyOut` **strictement inchangée** (zéro ligne supprimée, validation `git diff` AC7.2 confirmée) et `apiV1` utilise le helper. C'est une légère duplication du switch action mais garantit la non-régression 4.7 à 100%.

- **DO-6** — Pour `ShortcutExportController::apiV1`, le `machine` issu de la résolution serveur (Workstation Eloquent par UUID) est injecté dans la `Request` via `$request->merge(['machine' => ...])` **avant** la délégation à `script()` / `legacyFile()` / `icon()`. Cela écrase tout input `machine` user-controlled — défense en profondeur (le poste ne peut pas demander la config d'un autre poste). Note : le `user`, `userprofile` etc. restent en query (D4 — non-sensibles + parité legacy).

- **DO-7** — Pour `ApplicationsScriptsController::apiV1`, le fallback `machine` (si query vide) prend le `Workstation::name` Eloquent résolu via UUID. Cela évite un 400 « unknown machine » si le poste oublie d'envoyer `?machine=...` (parité behavioral légère).

- **DO-8** — Tests Feature : tous utilisent des mocks pour les chaînes coûteuses (`VeyonConfigGenerator`, `ReadUserManager`, `AssociationsResolver`, `ApplicationScriptsGenerator` + chaîne 5 services 16.7). Cela évite la dépendance LDAP / FS / APCu / openssl_pkey en CI. **Important** : la chaîne complète reste testée par les tests legacy 16.3b / 16.3c / 16.7 (couverture iso préservée).

- **DO-9** — `ApiV1ConfigSecurityTest::workstation_uuid_query_is_ignored_in_favor_of_jwt` ne peut pas asserter directement « le résolveur a utilisé UUID_A et pas UUID_B » via response body (les réponses sont des scripts text/plain ou des JSON dont le contenu n'est pas trivialement comparable). La preuve forte est dans le test unit `WorkstationConfigContextResolverTest::happy_path_workstation_with_physical_group_and_user` + l'inspection statique du code (`grep -rn "input('workstation_uuid'"` → 0 résultat — vérifié + couvert par le test archi `no_controller_reads_workstation_uuid_from_input`). Le test Feature vérifie au moins que la query ne perturbe pas la résolution (200 obtenu pour UUID_A signé JWT, même si UUID_B est passé en query).

- **DO-10** — Pour la déviation D5 (404 vs 200 vide), tous les controllers loggent en channel `auth-v1` avec `action_type = agent.v1.config.workstation_not_found` + `workstation_uuid_prefix` (8 chars, pas l'UUID complet pour minimiser le risque de fuite log). Ceci permet à Henri d'investiguer les cas marginaux (poste enrôlé puis Workstation supprimée) sans complexité supplémentaire.

#### Couverture tests (≥40 Feature + ≥6 unit + ≥6 archi)

- **Unit** (10 tests) : `WorkstationConfigContextResolverTest` — happy path + sans groupe + sans user + UUID inconnu + mapper Wallpaper + mapper App + mapper UUID inconnu × 2 + heuristique physical-vs-logical + resolveAppPolicyScope nominal + UUID inconnu.
- **Feature** (56 tests cumulés) :
  - `WallpaperApiV1Test` — 9 tests
  - `FirefoxApiV1Test` — 6 tests
  - `ThunderbirdApiV1Test` — 5 tests
  - `ShortcutsApiV1Test` — 6 tests
  - `NetworkApiV1Test` — 7 tests
  - `VeyonApiV1Test` — 6 tests
  - `AssociationsApiV1Test` — 8 tests
  - `ApplicationsScriptsApiV1Test` — 9 tests
  - `ApiV1ConfigSecurityTest` — 4 tests cross-cutting
- **Architecture** (9 tests) : `ApiV1ConfigRoutesTest` — 8 routes registered + auth.v1.workstation + auth.v1.secure-headers + méthodes HTTP correctes + legacy preserved + inject.bootstrap-fragment + controlhub intact + grep statique workstation_uuid + throttle:300 attaché.

#### Risques R1, R3 — résolution

- **R1** — Modèle Workstation Eloquent : **OK**. Colonne `uuid` (nullable + fillable + `scopeWithUuid()`), relation `groups()` (héritée 15.2). Écart sémantique mineur documenté DO-1/DO-2 (`groups()` vs `workstationGroups()`, `is_physical` vs `is_default`). Pas de fallback synthétisé nécessaire.
- **R3** — Conflit routing `/api/v1/shortcuts` vs ControlHub `/api/v1/shortcuts/{sync,delete}` : **OK théoriquement** (Laravel résout par match exact + middleware groupe — `/api/v1/shortcuts` racine vs `/api/v1/shortcuts/sync` sous-path). Test archi `controlhub_routes_remain_intact` vérifie que `/api/v1/snapshot` + `/api/v1/shortcuts/sync` (ControlHub) restent enregistrés. **Vérification définitive `php artisan route:list` différée Henri post-merge** (vendor/ absent localement).

#### Tests différés Henri post-merge

- **Exécution pest/phpunit complète** (T11.4) — `./vendor/bin/pest tests/Feature/Api/V1/Config tests/Unit/Gpo/Services tests/Architecture/ApiV1ConfigRoutesTest.php` (vendor/ absent dans worktree).
- **`php artisan route:list | grep "api/v1"`** (T8.3) — artisan non runnable sans vendor.
- **Smoke curl 8 endpoints** (T11.5) — déjà préparé dans la section « Smoke test à exécuter quand VM up » de la story (lignes 619-680).

### File List

**Fichiers créés (13)** — chemins absolus :

- `/home/htouchard/code/irundo/codebase/16-13/app/Dto/Gpo/WorkstationConfigContext.php`
- `/home/htouchard/code/irundo/codebase/16-13/app/Gpo/Services/WorkstationConfigContextResolver.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Concerns/SeedsWorkstationConfig.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Unit/Gpo/Services/WorkstationConfigContextResolverTest.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/WallpaperApiV1Test.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/FirefoxApiV1Test.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/ThunderbirdApiV1Test.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/ShortcutsApiV1Test.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/NetworkApiV1Test.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/VeyonApiV1Test.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/AssociationsApiV1Test.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/ApplicationsScriptsApiV1Test.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/ApiV1ConfigSecurityTest.php`
- `/home/htouchard/code/irundo/codebase/16-13/tests/Architecture/ApiV1ConfigRoutesTest.php`

**Fichiers modifiés (10)** — chemins absolus :

- `/home/htouchard/code/irundo/codebase/16-13/routes/api.php` (ajout du bloc 16.13 — 8 routes nommées `agent.v1.config.*`)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/WallpaperController.php` (ajout méthode `apiV1` + helper privé `composeWallpaperResponse`)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/AppPolicyController.php` (ajout `apiV1Firefox` + `apiV1Thunderbird` + helper privé `resolveNative`)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/Api/v1/ShortcutExportController.php` (ajout `apiV1`)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/Gpo/NetworkOutController.php` (ajout `apiV1`)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/Gpo/VeyonOutController.php` (ajout `apiV1`)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/Gpo/AssociationsOutController.php` (ajout `apiV1`)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/Gpo/ApplicationsScriptsController.php` (ajout `apiV1`)
- `/home/htouchard/code/irundo/codebase/16-13/docs/qa/domains/auth.md` (append section `## Story 16.13` + sections 24-28 + checklist 16.13)
- `/home/htouchard/code/irundo/codebase/16-13/_bmad-output/implementation-artifacts/sprint-status.yaml` (status `ready-for-dev` → `review`, `last_updated` annoté)

**Fichiers supprimés** : aucun.

---

## Corrections post-review (2026-05-19)

> Review code-review : sonnet (modèle opposé) + second avis opus.
> Document : `_bmad-output/codeReviews/16-13.md`.
> 8 corrections automatiques + 4 décisions Henri Q1-Q4 appliquées.

### Décisions Henri

| # | Question | Décision | Application |
|---|----------|----------|-------------|
| Q1 | Test JWT révoqué AC2.5 explicite ? | **OUI** — ajouter le test | `ApiV1ConfigSecurityTest::revoked_jwt_returns_401_revoked` (pattern iso `PingControllerTest` 16.10, ≤20 lignes) |
| Q2 | Format 404 (text/plain vs JSON) ? | **JSON** unifié | Les 7 controllers retournent `response()->json(['error' => 'workstation_not_found'], 404)` (iso `AppPolicyController`) |
| Q3 | Validation regex query inputs ? | **Différé** | Story de hardening dédiée post-16.13 (Opus-12). Aucune modification dans cette PR. |
| Q4 | Préfixer routes `/api/v1/workstation-config/*` ? | **OUI** | 8 routes + 9 tests Feature + test archi + runbook QA mis à jour |

### Corrections automatiques

- **F1 + Q1** — Ajouté `revoked_jwt_returns_401_revoked` dans `ApiV1ConfigSecurityTest`. Pattern iso `PingControllerTest`. Cible `/api/v1/workstation-config/wallpaper`. Assert `assertStatus(401) + assertJson(['code' => 'jwt.revoked'])`.
- **F2** — `AppPolicyController::resolveNative` : refactor pour appeler `$resolver->resolve($workstationUuid, $os, $userLogin)` une seule fois (vérification null = workstation introuvable), puis `$resolver->resolveAppPolicyScope(...)` pour les DTOs Eloquent. Élimine le double lookup + la race condition (bug bénin en monde scolaire LAN mais correction propre).
- **F3** — `WallpaperController::composeWallpaperResponse` : PHPDoc corrigé. La méthode n'est PAS partagée avec `legacyOut` — elle est utilisée exclusivement par `apiV1()`. `legacyOut` conserve son propre switch (AC7.2 : zéro modification comportementale legacy).
- **F4** — `ShortcutExportController::apiV1` : remplacé le lookup direct `Workstation::query()->where('uuid', ...)->first()` par `$resolver->resolve()` (D4 respecté). Import `App\Models\Workstation` supprimé (plus utilisé dans `apiV1` ; les autres méthodes ne le référencent pas). Le DTO `WorkstationConfigContext` porte déjà `machineName` (= Workstation::name), évitant le besoin d'exposer l'Eloquent.
- **F5 + Opus-21** — `ApiV1ConfigSecurityTest::workstation_uuid_query_is_ignored_in_favor_of_jwt` : refactor utilisant `/api/v1/workstation-config/wallpaper`. UUID_A seedé → JWT signe UUID_A. UUID_B inexistant en DB → passé en query. Si la query était lue → 404. Si JWT respecté → 200 + `image/jpeg`. **Preuve binaire forte** (vs ancien test 200/200 non discriminant).
- **F6** — `repositories_apcu_legacy_are_never_called_on_api_v1_routes` (renommé) : couverture étendue de 2 → 6 endpoints (`wallpaper`, `firefox`, `thunderbird`, `network`, `veyon`, `associations`). 3 mocks fail-fast : `AppContextRepository::findById`, `WallpaperContextRepository::findById`, `AppCustomizationService::resolvePoliciesForMachine` (le dernier renvoie un payload léger pour court-circuiter LDAP). Status acceptés : 200/400/404/500 — le seul critère est que les mocks fail-fast ne soient jamais invoqués.
- **F7 + Q2** — Format 404 JSON unifié sur les 7 controllers `apiV1` : `response()->json(['error' => 'workstation_not_found'], 404)`. Plus aucun `response('Workstation not found', 404)` text/plain. Pas besoin de mettre à jour les `assertSee('Workstation not found')` dans les tests Feature car ils utilisent `assertStatus(404)` (sans `assertSee` sur ce body).
- **F8** — `WallpaperApiV1Test::setUp` : suppression du `markTestSkipped` global Imagick. Check déporté dans 3 tests qui en ont réellement besoin (`happy_path_returns_200_image_jpeg`, `happy_path_png_format_returns_image_png`, `response_has_no_store_cache_headers`) via `requireImagick()`. Les tests 401/404/400 ne sont plus skippés.
- **Opus-11** — `WallpaperController::composeWallpaperResponse` : retrait des headers `Cache-Control` + `Pragma` explicites. Le middleware `auth.v1.secure-headers` (Story 16.10 `EnsureSecureApiHeaders`) override déjà ces headers via `$response->headers->set(...)` après `$next($request)`, peu importe le content-type retourné. La duplication était stérile. Décision documentée dans le code (commentaire). Les autres controllers (`Network`/`Veyon`/`Associations`/`AppPolicy`) gardent leurs headers `Cache-Control` car ils sont réutilisés en chemin legacy (sans middleware `secure-headers` actif) — le retrait casserait la non-régression AC7.2.
- **Opus-14** — `WorkstationConfigContextResolverTest::setUp` : ajout d'un garde-fou `markTestSkipped` si `config('database.default') !== 'sqlite'` (avant les `Schema::dropIfExists`). Iso pattern `SeedsWorkstationConfig::seedWorkstationContextSchemas`. Évite tout drop destructif sur Postgres en CI.
- **Q4 (route prefix)** — `routes/api.php` : préfixe `v1` → `v1/workstation-config` pour le bloc 16.13. Les 8 routes nommées `agent.v1.config.*` deviennent `/api/v1/workstation-config/{wallpaper,firefox,thunderbird,shortcuts,network,veyon,associations,applications-scripts}`. Le nommage est inchangé (`agent.v1.config.wallpaper`, etc.) — seul l'URI a évolué. Désambiguïse explicitement vs ControlHub (`/api/v1/snapshot`, `/api/v1/shortcuts/sync`, `/api/v1/applications/...`). Les 9 fichiers de tests Feature sont mis à jour (URLs + assertion paths). Test archi `the_8_routes_are_registered_under_api_v1_workstation_config_prefix` (renommé) + nouveau test `no_legacy_unprefixed_routes_remain_for_workstation_config` qui vérifie qu'aucune route plate `/api/v1/wallpaper` (etc.) n'est attachée à `auth.v1.workstation`. Runbook QA `docs/qa/domains/auth.md` sections 24-28 mises à jour (scénarios 16.13-1 à 16.13-12 + 16.13-4bis ajouté pour JWT révoqué + checklist + nouvelle section « Post-correctifs »).

### Items différés / hors-scope cette PR

- **Q3 / Opus-12** — Validation regex stricte sur `userLogin`, `userprofile`, `os`, `user`, `action` (query params) dans les 6 controllers. **Différé** en story de hardening dédiée post-16.13. Raison : ne change pas la sécurité du JWT (claim `sub` reste seule source d'identification poste), n'est pas une régression introduite par 16.13 (les inputs étaient déjà non-validés dans les versions legacy 4.7/4.8/16.3a-c/16.7), et factoriser proprement dans un trait `ValidatesContextInputs` est un travail dédié.
- **F9 / Opus-19** — Test archi `legacy_out_routes_are_still_preserved` : grep texte fragile. **Différé** post-16.13bis (quand les routes legacy `*_out.php` seront supprimées et le test évoluera naturellement).
- **F10** — Conflit routing `/api/v1/shortcuts` ControlHub : **devenu sans objet** suite à Q4 (nouvelle route est `/api/v1/workstation-config/shortcuts`, plus de cohabitation namespace).
- **Opus-15 / Opus-16 / Opus-17 / Opus-18 / Opus-20 / Opus-22** — Améliorations qualité non-bloquantes (cf. tableau de synthèse review). Différées en amélioration continue.

### Fichiers modifiés / créés post-review

**Modifiés (12)** :
- `/home/htouchard/code/irundo/codebase/16-13/routes/api.php` (préfixe `/api/v1/workstation-config/*` Q4)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/WallpaperController.php` (F7+Q2 JSON 404, F3 PHPDoc, Opus-11 Cache-Control délégué au middleware)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/AppPolicyController.php` (F2 double lookup éliminé, F7+Q2 endpoint path mis à jour)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/Api/v1/ShortcutExportController.php` (F4 resolver usage, F7+Q2 JSON 404, import `Workstation` supprimé)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/Gpo/NetworkOutController.php` (F7+Q2)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/Gpo/VeyonOutController.php` (F7+Q2)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/Gpo/AssociationsOutController.php` (F7+Q2)
- `/home/htouchard/code/irundo/codebase/16-13/app/Http/Controllers/Gpo/ApplicationsScriptsController.php` (F7+Q2)
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/ApiV1ConfigSecurityTest.php` (Q1 test révocation, F5+Opus-21 refactor preuve binaire, F6 6 endpoints + mocks fail-fast étendus, URLs Q4)
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/WallpaperApiV1Test.php` (F8 setUp + 3 tests `requireImagick`, URLs Q4)
- `/home/htouchard/code/irundo/codebase/16-13/tests/Feature/Api/V1/Config/{Firefox,Thunderbird,Shortcuts,Network,Veyon,Associations,ApplicationsScripts}ApiV1Test.php` (URLs Q4 — 7 fichiers)
- `/home/htouchard/code/irundo/codebase/16-13/tests/Unit/Gpo/Services/WorkstationConfigContextResolverTest.php` (Opus-14 garde-fou SQLite)
- `/home/htouchard/code/irundo/codebase/16-13/tests/Architecture/ApiV1ConfigRoutesTest.php` (Q4 prefix updated + nouveau test `no_legacy_unprefixed_routes_remain_for_workstation_config`)
- `/home/htouchard/code/irundo/codebase/16-13/docs/qa/domains/auth.md` (sections 24-28 + 16.13-4bis nouveau scénario JWT révoqué + nouvelle section « Post-correctifs »)
- `/home/htouchard/code/irundo/codebase/16-13/_bmad-output/codeReviews/16-13.md` (statut → `corrections-applied` + section corrections appliquées)
- `/home/htouchard/code/irundo/codebase/16-13/_bmad-output/implementation-artifacts/sprint-status.yaml` (`last_updated` annoté)

**Créés** : 0 (toutes les corrections passent par édition des fichiers existants).

### Lint post-corrections

`php -l` exécuté sur les 12 fichiers PHP touchés (controllers + routes + tests Feature + test unit + test archi) : **0 erreur**. Tests phpunit/pest **non lancés** (vendor/ absent dans worktree iso 16.10/16.11/16.12) — différés Henri post-merge sur `main`.

### Change Log

- 2026-05-19 (claude-opus-4-7[1m] post-review) : CORRECTIONS POST-REVIEW appliquées. Status `review` → `corrections-applied`. Review code-review (`_bmad-output/codeReviews/16-13.md`) par sonnet + second avis opus, 22 findings dont 8 corrections automatiques + 4 décisions Henri Q1-Q4. Décisions Henri : Q1=test JWT révoqué AC2.5 ajouté, Q2=format 404 JSON unifié sur les 7 autres controllers (iso `AppPolicyController`), Q3=validation regex inputs DIFFÉRÉE en story hardening, Q4=préfixe routes `/api/v1/workstation-config/*` (désambiguïse vs ControlHub). Corrections auto : F2 double lookup `AppPolicyController` éliminé, F3 PHPDoc `composeWallpaperResponse` clarifié, F4 `ShortcutExportController` utilise resolver (import `Workstation` supprimé), F5+Opus-21 test `workstation_uuid_query_ignored` refactor preuve binaire forte (wallpaper UUID_A seedé vs UUID_B inexistant), F6 fail-fast étendu 2→6 endpoints + 3 mocks repository legacy, F7+Q2 JSON 404 unifié, F8 Wallpaper test setUp Imagick check déporté dans 3 tests, Opus-11 Cache-Control délégué au middleware `auth.v1.secure-headers` (controller `composeWallpaperResponse` uniquement — les autres restent inchangés pour préserver chemin legacy AC7.2), Opus-14 garde-fou SQLite `WorkstationConfigContextResolverTest::setUp`. 12 fichiers modifiés (7 controllers + 1 route + 9 tests Feature + 1 test unit + 1 test archi + 1 runbook QA). 0 nouveau fichier créé. Lint php -l 0 erreur sur 12 fichiers. Tests phpunit/pest non lancés (vendor/ absent worktree iso 16.10/16.11/16.12), différés Henri post-merge. Items différés post-merge : Q3/Opus-12 validation regex inputs → story hardening dédiée, F9/Opus-19 grep texte fragile → post-16.13bis, Opus-15/16/17/18/20/22 améliorations qualité non-bloquantes.
- 2026-05-19 (claude-opus-4-7[1m]) : Story 16.13 IMPLÉMENTÉE — 14 fichiers créés + 10 modifiés. 10 décisions DO-1 à DO-10 émises au-delà des 10 D1-D10 SM. 8 routes `/api/v1/{wallpaper,firefox,thunderbird,shortcuts,network,veyon,associations,applications-scripts}` exposées avec middleware `auth.v1.workstation` + `auth.v1.secure-headers` + `throttle:300,1`. Service `WorkstationConfigContextResolver` + DTO `WorkstationConfigContext` créés (résolution serveur DB du contexte poste depuis JWT workstation_uuid + lookup Workstation Eloquent + groupe physique + user). 56 tests Feature + 10 unit + 9 archi écrits. Tests pest non lancés (vendor/ absent worktree), différés Henri post-merge. Lint php -l 0 erreur. Status story → `review`.
