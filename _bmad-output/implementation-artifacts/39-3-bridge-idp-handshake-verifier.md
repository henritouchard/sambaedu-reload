# Story 39.3: Brancher l'IdP fédéré du handshake sur le vérificateur JWT (canal ⑤)

Status: to-validate

<!-- Créée le 2026-07-04 (create-story, Epic 39 — epics-alignement-controlhub-se5.md). -->

## Story

As le SSO fédéré (login technicien externe via l'IdP amont),
I want que `FederatedJwtVerifier` résolve sa clé publique/`kid`/`iss` depuis l'IdP **réellement provisionné au handshake** (`controlhub_connection.idp_*`), avec l'`aud` validé contre l'**uuid d'instance**,
so that un JWT émis par l'IdP amont soit accepté **sans aucun réglage env manuel**, alors qu'aujourd'hui la clé publique/`kid`/`iss` persistés au handshake sont **morts** (le verifier ne lit que `config('federated_auth.*')`) et l'`aud` par défaut (`sambaedu.se4fs_name`) n'est **pas** l'identifiant que le central utilise (uuid d'instance).

**Périmètre : bridge de résolution, zéro réécriture de la sécurité JWT.** `FederatedJwtVerifier::verify()` (RS256 pinné, claims `sub/jti/kid/iss/aud/tier/exp/nbf`, anti-rejeu `jti` via `FederatedJwtReplayChecker`, résolution de rôle via `FederatedRoleMapper`) reste **structurellement intact** — cette story ne touche que les 3 méthodes privées de résolution de source (`buildKeyMap()`, la validation `iss`, `expectedAud()`), en leur ajoutant une source DB **prioritaire**, config **en repli explicite**.

## Acceptance Criteria

1. **Bridge clé publique + `kid` + `iss` (DB prioritaire, config en repli).** Dans `app/Auth/Federated/Jwt/FederatedJwtVerifier.php` :
   - `buildKeyMap()` (ligne 255) : si `ControlHubConnection::current()` existe et `hasFederatedIdp() === true`, retourne la map à **une entrée** `[$connection->idp_kid => new Key($connection->idp_public_key, 'RS256')]` (le PEM est lu **directement depuis la colonne texte**, sans passer par un chemin de fichier — `idp_public_key` n'est **pas** un path). Sinon, comportement **strictement inchangé** : lecture de `config('federated_auth.jwt.keys')` + `file_get_contents()` (logique actuelle conservée telle quelle, renommée en méthode privée dédiée style `buildKeyMapFromConfig()`).
   - Validation `iss` (ligne 140-144) : extraire en méthode privée `expectedIss()` symétrique à `expectedAud()`. Si `ControlHubConnection::current()?->hasFederatedIdp()` est vrai, retourne `$connection->idp_iss` ; sinon `config('federated_auth.expected_iss', '')` (comportement actuel).
   - **Pas de fusion** DB+config : quand la DB porte un IdP fédéré valide, elle est **seule source de vérité** (clé, kid, iss) — le repli config ne s'active que si la DB est **absente ou incomplète** (`hasFederatedIdp() === false`). C'est la lecture littérale du commentaire déjà présent dans `ControlHubConnection::hasFederatedIdp()` (« … à adresser dans la story de vérification JWT — review F8 ») : cette story ferme ce risque résiduel documenté.
   - **Aucune modification du constructeur** de `FederatedJwtVerifier` (reste sans dépendances, `new FederatedJwtVerifier()`) — la résolution DB passe par l'appel statique `ControlHubConnection::current()`, pas par injection. Ce choix évite de casser les 2 suites de tests existantes qui instancient le verifier directement (`FederatedJwtVerifierTest`, `FederatedLoginEndpointTest`).

2. **`aud` validé contre l'uuid d'instance.** `expectedAud()` (ligne 238-246) : le fallback dynamique passe de `config('sambaedu.se4fs_name', '')` à `config('controlHub.se4fs.instance_id', '')`. `config('federated_auth.expected_aud')` reste l'**override explicite** prioritaire (inchangé — un déploiement qui le positionne explicitement continue de fonctionner à l'identique). Ce changement est **indépendant** du bridge clé/kid/iss de l'AC1 : `instance_id` n'est **pas** une colonne de `controlhub_connection`, c'est une config résolue par ailleurs (`ControlHubService`, `getWebhookUrl()`/`getHeartbeatUrl()` du modèle l'utilisent déjà).

3. **JWT émis par l'amont (kid du handshake) accepté sans réglage env manuel — preuve end-to-end.** Test qui : seed une `ControlHubConnection` active via `ControlHubConnection::createOrUpdate([...idp_public_key, idp_kid, idp_iss...])` (paire de clés RS256 de test, PAS les fixtures `tests/fixtures/auth-v1/*.pem` habituelles — utiliser une **nouvelle** paire dédiée pour bien prouver que ce n'est PAS la config qui répond), positionne `config('controlHub.se4fs.instance_id')` à une valeur de test, émet un JWT signé avec la clé privée correspondante (`iss`=`idp_iss` de la connexion, `kid`=`idp_kid`, `aud`=instance_id) **sans jamais toucher `config('federated_auth.jwt.keys')`/`expected_iss`/`expected_aud`** (laissés à leurs défauts vides `.env`) → `verify()` retourne les claims avec succès.

4. **Repli fonctionnel en absence de handshake (NFR-A1 / non-régression totale).** Quand `ControlHubConnection::current()` est `null` (aucune ligne active) OU que la ligne active a `hasFederatedIdp() === false` (bloc `idp_federated` jamais reçu — cas legacy documenté par la migration), le comportement est **rigoureusement identique** à avant cette story : résolution 100% `config('federated_auth.*')`. Les **17 tests existants** de `FederatedJwtVerifierTest` et l'intégralité de `FederatedLoginEndpointTest` passent **sans modification de leurs assertions** (seul le setUp peut évoluer, cf. AC7).

5. **Rôle `super-admin` : vérifié et documenté, pas réimplémenté.** `SambaRole::SuperAdmin` (`'super-admin'`) est l'un des 9 rôles seedés par `PermissionSeeder::run()` (`Role::firstOrCreate`, appelé par `DatabaseSeeder`) — **déjà garanti** en base si le pipeline de déploiement exécute le seed. `FederatedRoleMapper::resolve()` + `FederatedLoginController::callback()` (rôle absent → 403, aucune session, aucune création à la volée) sont **inchangés**. Le test `super_admin_is_applied_when_it_exists` (`FederatedLoginEndpointTest.php`) reste vert sans modification. Dev Notes documente ce prérequis opérationnel (pas une lacune de cette story).

6. **Anti-régression stricte (sécurité).** `RS256` pinné (`buildKeyMap*()` ne construit toujours QUE des `Key(..., 'RS256')`, jamais de déduction d'algo) ; validation `exp`/`nbf`/`tier` **inchangée** ; anti-rejeu `jti` (`FederatedJwtReplayChecker::consumeOnce()`, appelé par le controller après provisioning) **non touché** ; `FederatedRoleMapper` **non touché**. La clé publique — qu'elle vienne du fichier (config) ou de la colonne DB — n'apparaît **jamais** dans un log (`logRejection()` ne loggue que `jwt_hash_prefix` + code, inchangé).

7. **Tables de test — prérequis bloquant (sinon régression immédiate).** `ensureFederatedTables()` (`tests/Concerns/IssuesFederatedJwt.php`) **doit** créer la table `controlhub_connection` (colonnes minimales : `id`, `is_active` bool, `expires_at` timestamp nullable, `idp_public_key` text nullable, `idp_kid` string nullable, `idp_iss` string nullable, `base_url`/`api_token`/`se4fs_api_token` si requis par le modèle) — **sans cet ajout, `ControlHubConnection::current()` lève une exception "table absente" dès le premier `verify()` appelé par les 2 suites de tests fédérés existantes**, qui utilisent un schéma SQLite manuel (pas de migrations réelles). Ajouter un helper de seed dédié (ex. `seedFederatedIdpConnection(array $overrides = []): void`) dans le même trait, réutilisable par les nouveaux tests de l'AC3.

8. **Vocabulaire (R3) / NFR-A4.** Aucun mot « central » introduit dans un identifiant/commentaire nouveau (vocabulaire existant du domaine : « amont », `ControlHub*`, IdP externe). `StateCompiler`/`ContractV1`/golden/`FROZEN_STATE_HASH`/`agent/**` : non touchés (story 100% côté SSO fédéré humain, sans rapport avec le contrat agent).

9. **Tests HÔTE** (php8.4 + pdo_sqlite, patron existant `FederatedJwtVerifierTest`/`FederatedLoginEndpointTest`) :
   - Bridge DB : JWT signé avec la clé/kid/iss de la `ControlHubConnection` active → accepté (AC3), **sans** `config('federated_auth.jwt.keys'|'expected_iss'|'expected_aud')` renseignés.
   - `aud` validé contre `config('controlHub.se4fs.instance_id')` (test dédié, indépendant du bridge clé/kid/iss — DB absente, `expected_aud` non configuré explicitement).
   - Repli sans handshake : `ControlHubConnection::current()` null → comportement config identique (réutilise/étend un test existant, ex. `no_keys_configured_rejects_all` avec DB absente en plus de config vide).
   - `hasFederatedIdp() === false` (DB présente mais incomplète — ex. `idp_kid` null) → repli config, pas de crash.
   - Non-régression complète : `FederatedJwtVerifierTest` (17/17) + `FederatedLoginEndpointTest` (tous cas, dont `super_admin_is_applied_when_it_exists`) verts **sans modification de leurs assertions**.
   - `php -l` sur les fichiers modifiés ; `grep -rin central` sur le diff = 0 hors garde-fous existants.

## Tasks / Subtasks

- [x] Task 1 — Bridge clé/kid/iss dans `FederatedJwtVerifier` (AC: 1, 6, 8)
  - [x] Extraire `buildKeyMapFromConfig()` (logique actuelle de `buildKeyMap()`, inchangée)
  - [x] `buildKeyMap()` : si `ControlHubConnection::current()?->hasFederatedIdp()`, retourner la map à une entrée depuis la colonne PEM ; sinon déléguer à `buildKeyMapFromConfig()`
  - [x] Extraire `expectedIss()` (même patron que `expectedAud()`) ; brancher la lecture `iss` de `verify()` dessus
  - [x] Import `App\Models\ControlHubConnection` dans le verifier
- [x] Task 2 — `aud` = uuid d'instance (AC: 2)
  - [x] `expectedAud()` : remplacer le fallback `config('sambaedu.se4fs_name', '')` par `config('controlHub.se4fs.instance_id', '')` ; garder `config('federated_auth.expected_aud')` comme override explicite prioritaire (inchangé)
- [x] Task 3 — Table de test `controlhub_connection` + helper de seed (AC: 7)
  - [x] Étendre `ensureFederatedTables()` dans `tests/Concerns/IssuesFederatedJwt.php` (création de la table si absente, patron des tables voisines du même fichier)
  - [x] Ajouter `seedFederatedIdpConnection(array $overrides = []): ControlHubConnection` dans le même trait, réutilisant `ControlHubConnection::createOrUpdate()` (+ `ensureFederatedIdpKeyPair()`/`issueHandshakeIdpJwt()`)
- [x] Task 4 — Tests unitaires du bridge (AC: 3, 4, 9)
  - [x] Nouvelle paire de clés RS256 de test dédiée (distincte de `tests/fixtures/auth-v1/*.pem`) — `openssl_pkey_new` en mémoire via `ensureFederatedIdpKeyPair()`
  - [x] Cas DB active + complète → JWT accepté sans config renseignée (`handshake_idp_jwt_is_accepted_from_db_without_env_config`) + corollaire clé-pivot (`…signed_with_wrong_key_is_rejected`)
  - [x] Cas DB absente (`current()` null) → repli config identique à l'existant (`db_absent_falls_back_to_config`)
  - [x] Cas DB présente incomplète (`hasFederatedIdp() === false`) → repli config (`incomplete_db_connection_falls_back_to_config`)
  - [x] Cas `aud` = instance_id (indépendant du bridge clé/kid/iss) (`aud_falls_back_to_instance_id_when_expected_aud_not_set` + `aud_bound_to_se4fs_name_is_rejected_after_instance_id_switch`)
- [x] Task 5 — Non-régression (AC: 4, 5, 6, 9)
  - [x] `php artisan test --filter=FederatedJwtVerifier` (25/25, dont 19 existants inchangés)
  - [x] `php artisan test --filter=FederatedLoginEndpoint` (15/15, dont `super_admin_is_applied_when_it_exists`)
  - [x] `php -l` sur les fichiers modifiés ; `grep -rin central` = 0 sur le diff
- [x] Task 6 (doc) — Append `docs/qa/domains/federated-login.md` (Section 12 « Story 39.3 ») : scénarios 39.3-1..5, tableau de sévérité, checklist.

## Dev Notes

### Existant à réutiliser — NE PAS réécrire

| Brique | Fichier | Rôle pour 39.3 |
|---|---|---|
| Verifier RS256 | `app/Auth/Federated/Jwt/FederatedJwtVerifier.php` | Cible du bridge. `verify()` (structure, claims, exceptions) **inchangée** ; seules `buildKeyMap()`, la lecture `iss`, `expectedAud()` évoluent. |
| Modèle IdP handshake | `app/Models/ControlHubConnection.php` | `current()` (ligne 54), `hasFederatedIdp()` (ligne 137 — **contient déjà le commentaire pointant cette story**, review F8), colonnes `idp_public_key`/`idp_kid`/`idp_iss` (fillable lignes 20-22). |
| Écriture handshake | `HandshakeResponse::extractIdpFederated()` (`app/Services/ControlHub/Data/HandshakeResponse.php:83-129`) → `ControlHubConnectionRepository::saveHandshakeConnection()` → `ControlHubConnection::createOrUpdate()` | **Déjà livré, ne pas toucher.** Validation stricte du bloc reçu (PEM réel via `openssl_pkey_get_public`, `kid` non vide, `iss` URL valide) avant persistance. |
| Anti-rejeu `jti` | `app/Auth/Federated/Jwt/FederatedJwtReplayChecker.php` + table `federated_jwt_consumptions` | Non touché — appelé par le controller, pas par le verifier. |
| Résolution de rôle | `app/Auth/Federated/FederatedRoleMapper.php` | Non touché — lookup direct table `roles` Spatie, `super-admin` déjà résolvable s'il existe (voir AC5). |
| Seed du rôle | `database/seeders/PermissionSeeder.php` (+ `DatabaseSeeder.php`) | `SambaRole::SuperAdmin` fait partie des 9 rôles seedés (`Role::firstOrCreate`, idempotent). Prérequis opérationnel, pas du code à ajouter. |
| Patron bridge DB→config existant | `app/Providers/ControlHubServiceProvider.php:25-34` | `ControlHubApiClient` résout `base_url` depuis `ControlHubConnectionRepository::getCurrentConnection()` au moment de la construction, défaut `''` si absent — **pas de fallback config** pour `base_url` (différent d'`expected_aud`, qui lui garde un repli config explicite par choix de cette story). |
| Test fixtures RS256 existantes | `tests/fixtures/auth-v1/{private,public}.pem` | Réutilisées par `IssuesFederatedJwt` pour le chemin **config**. Le chemin **DB** (AC3-4) doit utiliser une **paire distincte** pour que le test soit discriminant (sinon on ne prouve rien sur l'origine de la clé). |
| Test helper | `tests/Concerns/IssuesFederatedJwt.php` | `configureFederatedAuth()`, `issueFederatedJwt()`, `signFederatedJwt()`, `ensureFederatedTables()` (**à étendre**, Task 3). |

### Point critique — table `controlhub_connection` absente des tests fédérés existants

`FederatedJwtVerifierTest` et `FederatedLoginEndpointTest` construisent leur schéma SQLite **à la main** via `ensureFederatedTables()` (pas de migrations réelles, pas de `RefreshDatabase`). Cette table n'y est **actuellement pas créée**. Dès que `buildKeyMap()`/`expectedIss()` appellent `ControlHubConnection::current()` (nouveau code de cette story), **les 2 suites entières crashent** avec une erreur SQL "no such table: controlhub_connection" — y compris les tests qui ne concernent pas le bridge. **Task 3 est donc un prérequis bloquant de Task 1**, pas une amélioration optionnelle.

### Décision de conception — pas d'injection, résolution statique

`FederatedJwtVerifier` reste instanciable sans dépendances (`new FederatedJwtVerifier()`) : les 2 suites de tests existantes l'instancient directement, hors conteneur Laravel (`FederatedLoginEndpointTest.php` construit `FederatedLoginController` manuellement avec `new FederatedJwtVerifier()` — grep confirmé). Ajouter un constructeur avec paramètres cassant cette instanciation directe romprait ces tests pour un bénéfice nul : la résolution DB via l'appel statique `ControlHubConnection::current()` suffit et reste testable (les tests seedent simplement la table).

### Décision de conception — DB prioritaire, PAS de fusion avec la config

Quand `ControlHubConnection::current()?->hasFederatedIdp()` est vrai, la DB est la **seule** source pour clé/kid/iss (une entrée, pas de map fusionnée avec les clés de `config('federated_auth.jwt.keys')`). Le repli config ne s'active que si la DB est absente/incomplète. C'est la lecture littérale de l'intention epic (« config env comme repli explicite, pas l'inverse ») et du commentaire déjà présent dans `ControlHubConnection::hasFederatedIdp()`.

### `aud` : changement indépendant du bridge clé/kid/iss

`instance_id` n'est **pas** stocké dans `controlhub_connection` — c'est une config (`config('controlHub.se4fs.instance_id')`, défaut `env('SE4FS_INSTANCE_ID', (string) Str::uuid())`, `config/controlHub.php:15`). Le changement d'AC2 est un **remplacement de fallback d'une ligne**, sans rapport structurel avec le bridge DB de l'AC1 (qui, lui, ne concerne que clé/kid/iss). Ne pas essayer de faire porter l'`aud` par `ControlHubConnection` — ce n'est pas son rôle et l'epic ne le demande pas.

### Risque connu — non-déterminisme de `SE4FS_INSTANCE_ID` (hors périmètre, à savoir)

Si `SE4FS_INSTANCE_ID` n'est pas positionné en `.env`, `config('controlHub.se4fs.instance_id')` régénère un **nouvel UUID aléatoire à chaque chargement de config** (`Str::uuid()` en défaut, pas figé). En pratique l'UUID est écrit par `scripts/create-env.sh` au provisioning, mais un rapport de déploiement existant (`documentation/installation/DEPLOY-TEST-REPORT-2026-06-21.md:66-72`) signale un bug connu : si `uuidgen` est absent, le placeholder `.env.example` peut être conservé (risque de collision d'aud entre deux instances). **Cette story ne corrige pas ce bug** (hors scope — infra de provisioning, pas le verifier) ; le mentionner en commentaire de code au point de lecture (`expectedAud()`) comme rappel opérationnel suffit.

### Format de la clé publique — piège

`buildKeyMapFromConfig()` (actuel `buildKeyMap()`) lit un **chemin de fichier** (`config('federated_auth.jwt.keys')` → `['public' => path]` → `file_get_contents()`). La colonne `controlhub_connection.idp_public_key` contient le **PEM en clair** (`text`, pas un chemin). Ne PAS tenter de faire passer la colonne DB par le même code de lecture fichier — construire directement `new Key($connection->idp_public_key, 'RS256')`.

### Project Structure Notes

- Fichier modifié : `app/Auth/Federated/Jwt/FederatedJwtVerifier.php` (seul fichier de logique).
- Fichier modifié : `tests/Concerns/IssuesFederatedJwt.php` (table + helper de seed).
- Tests dans `tests/Unit/Auth/Federated/FederatedJwtVerifierTest.php` (nouveaux cas additifs) — ne pas créer un nouveau fichier de test séparé, le patron existant couvre déjà tout le domaine claims/sécurité, le bridge n'ajoute qu'une source de résolution.
- Aucune nouvelle route, aucun nouveau contrôleur, aucune nouvelle migration (les colonnes `idp_*` existent déjà depuis `2026_06_04_130000_add_idp_federated_to_controlhub_connection.php`).

### References

- [Source: _bmad-output/planning-artifacts/epics-alignement-controlhub-se5.md#Story-39.3] — intention, AC-skeleton, FR-A3, NFR-A1..A5
- [Source: app/Auth/Federated/Jwt/FederatedJwtVerifier.php:238-295] — `expectedAud()`, `buildKeyMap()`, points exacts à modifier
- [Source: app/Models/ControlHubConnection.php:54-66,137-142] — `current()`, `hasFederatedIdp()` (commentaire F8 pointant cette story)
- [Source: app/Services/ControlHub/Data/HandshakeResponse.php:83-129] — pipeline de validation/persistance du bloc `idp_federated` (déjà livré, non touché)
- [Source: config/controlHub.php:15] — `se4fs.instance_id`, défaut non déterministe si env absente
- [Source: config/federated_auth.php] — clés de repli existantes (`expected_iss`/`expected_aud`/`expected_tier`, `jwt.keys`)
- [Source: tests/Concerns/IssuesFederatedJwt.php] — `ensureFederatedTables()` à étendre, patron des tables existantes
- [Source: tests/Unit/Auth/Federated/FederatedJwtVerifierTest.php] — 17 tests de non-régression, aucune assertion à modifier
- [Source: tests/Feature/Auth/Federated/FederatedLoginEndpointTest.php:154-167] — `super_admin_is_applied_when_it_exists`, non-régression AC5
- [Source: app/Providers/ControlHubServiceProvider.php:25-34] — patron de bridge DB→config existant (référence de conception, pas réutilisé tel quel — cf. décision « pas d'injection »)

## Dépendances

- **Amont (DONE)** : handshake (`HandshakeResponse.idp_*`, Story antérieure à l'Epic 39 — déjà persisté, migration `2026_06_04_130000_add_idp_federated_to_controlhub_connection.php`). Rien à attendre.
- **Indépendante** de 39.1, 39.2, 39.4 — aucun fichier partagé, aucun couplage fonctionnel.
- **Aval** : aucune. Cette story ferme le risque résiduel documenté dans `ControlHubConnection::hasFederatedIdp()` (review F8) ; aucune story de suivi n'est présupposée.

### Questions ouvertes (position par défaut assumée, à confirmer par Henri)

1. **Rotation de clé (plusieurs `kid` simultanés).** Position par défaut : **hors périmètre**. `controlhub_connection` ne porte qu'**un** tuple `idp_public_key/idp_kid/idp_iss` à la fois (le handshake actuel ne remonte qu'un seul bloc `idp_federated`, cf. `extractIdpFederated()`) — le bridge résout donc une key-map à une entrée quand la DB est active. Une vraie rotation sans coupure nécessiterait que le contrat de handshake porte un **tableau** de clés (évolution du schéma d'échange R2, hors scope 39.3). Si un besoin réel apparaît : story de suivi dédiée à l'extension du bloc `idp_federated`.
2. **Divergence IdP handshake vs env.** Position par défaut : **la DB prime strictement**, sans comparaison ni fusion, dès que `hasFederatedIdp() === true` — la config n'est un repli que pour l'**absence** de handshake, jamais un correctif d'un handshake présent (cf. « Décision de conception » ci-dessus). Un mode « les deux doivent concorder » serait un durcissement distinct, non demandé par l'epic.
3. **Provisioning du rôle `super-admin`.** Déjà résolu par le code existant (§ AC5) : `SambaRole::SuperAdmin` fait partie des rôles seedés par `PermissionSeeder`/`DatabaseSeeder`. Position par défaut : **aucune action de cette story** au-delà de la vérification/documentation — le seed en déploiement est un prérequis opérationnel standard, pas une lacune à combler ici.

## Dev Agent Record

### Agent Model Used

opus (claude-opus-4-8[1m]) — conforme à la recommandation de la story.

### Debug Log References

- `php -l` OK sur les 3 fichiers modifiés.
- `php artisan test --filter=FederatedJwtVerifier` → **25 passed** (57 assertions) : 19 existants inchangés + 6 nouveaux (bridge).
- `php artisan test --filter=FederatedLoginEndpoint` → **15 passed** (45 assertions) : aucune assertion modifiée, `super_admin_is_applied_when_it_exists` vert.
- Run combiné → **40 passed** (102 assertions).
- `git diff -- app/ tests/ | grep -in central` → 0 (comment initial reformulé « IdP amont »).
- NFR-A4 : aucun fichier `agent/**`, `StateCompiler`, golden, `FROZEN_STATE_HASH` touché.

### Completion Notes List

- **Ordre respecté** : Task 3 (table `controlhub_connection` dans `ensureFederatedTables()`) livrée AVANT le branchement DB du verifier — sans quoi `ControlHubConnection::current()` aurait crashé les 2 suites fédérées (schéma SQLite manuel, sans migrations). Confirmé : les 19 tests verifier + 15 endpoint restent verts après ajout de la table.
- **Bridge (AC1)** : `buildKeyMap()` → si `current()?->hasFederatedIdp()`, map à UNE entrée `[idp_kid => new Key(idp_public_key, 'RS256')]` (PEM lu littéralement depuis la colonne texte, pas un path) ; sinon `buildKeyMapFromConfig()` (ex-`buildKeyMap()`, inchangé). `expectedIss()` créée, symétrique à `expectedAud()`, branchée dans `verify()`. **DB prioritaire, aucune fusion** avec la config.
- **`aud` (AC2)** : fallback `expectedAud()` passé de `sambaedu.se4fs_name` à `controlHub.se4fs.instance_id` ; override `federated_auth.expected_aud` reste prioritaire (inchangé). Changement indépendant du bridge clé/kid/iss.
- **Pas d'injection (décision de conception)** : résolution statique `ControlHubConnection::current()` ; `new FederatedJwtVerifier()` reste sans dépendances (2 suites l'instancient directement).
- **Anti-régression (AC6)** : `verify()` structurellement intact — RS256 pinné, claims `exp/nbf/iss/aud/tier`, anti-rejeu `jti` (`FederatedJwtReplayChecker`) et `FederatedRoleMapper` non touchés. `logRejection()` inchangé → clé publique (DB ou config) jamais loggée.
- **Rôle `super-admin` (AC5)** : aucun code ajouté — seed existant (`SambaRole::SuperAdmin` via `PermissionSeeder`/`DatabaseSeeder`), test `super_admin_is_applied_when_it_exists` vert sans modification.
- **Preuve d'origine DB (AC3)** : paire RS256 DÉDIÉE générée en mémoire (`openssl_pkey_new`), distincte des fixtures `auth-v1`. Le test vide `jwt.keys`/`expected_iss`/`expected_aud` avant de vérifier → seule la DB peut répondre. Corollaire : un jeton signé par la paire config mais au kid du handshake est rejeté `signature_invalid`.
- **Questions ouvertes** : positions par défaut de la story assumées (rotation multi-kid hors scope ; DB prime strictement sans fusion ; super-admin = prérequis opérationnel seed). Aucune n'a nécessité d'arbitrage en cours de dev.

### File List

- `app/Auth/Federated/Jwt/FederatedJwtVerifier.php` (modifié) — import `ControlHubConnection` ; `buildKeyMap()` bridge DB + délégation `buildKeyMapFromConfig()` ; `expectedIss()` (nouvelle) ; `verify()` lit `expectedIss()` ; `expectedAud()` fallback `instance_id`.
- `tests/Concerns/IssuesFederatedJwt.php` (modifié) — table `controlhub_connection` dans `ensureFederatedTables()` ; propriétés + `ensureFederatedIdpKeyPair()`, `seedFederatedIdpConnection()`, `issueHandshakeIdpJwt()`.
- `tests/Unit/Auth/Federated/FederatedJwtVerifierTest.php` (modifié) — 6 tests additifs du bridge (AC2/3/4).
- `docs/qa/domains/federated-login.md` (modifié, append-only) — Section « Story 39.3 » : scénarios 39.3-1..5, tableau de sévérité, checklist.
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modifié) — ligne `39-3-…` → `review`.

## Recommandation Modèle Dev

**opus** — confirmé après lecture du code, conforme à la préconisation de l'epic. Justification : bien que le diff final soit petit (3 méthodes privées d'un seul fichier + un fallback d'une ligne), le risque est concentré sur (1) une décision de câblage précédence DB/config qui, si inversée par erreur, ouvrirait une confusion de source de clé sur du JWT RS256 — surface de sécurité authentification ; (2) un piège non-évident découvert en exploration (la table `controlhub_connection` absente des fixtures de test des 2 suites existantes, qui casse silencieusement TOUTE la suite fédérée si l'ordre des tâches n'est pas respecté) ; (3) la nécessité de garantir, par la preuve (AC3, clé de test dédiée), qu'aucune régression config-vs-DB ne se glisse dans 17+ tests de sécurité existants qui ne doivent **pas** changer d'assertion. Un modèle qui irait vite sur « juste brancher une colonne DB » raterait facilement (2) ou fusionnerait par erreur DB+config (violation de la décision de conception).