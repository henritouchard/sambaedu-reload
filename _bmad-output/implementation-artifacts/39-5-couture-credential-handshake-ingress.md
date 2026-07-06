# Story 39.5 : Aligner le credential d'auth entrante CH→SE5 sur le token de handshake (E10) + figer « rotation = re-handshake seul »

Status: review

<!-- Créée + développée le 2026-07-06 (dev direct orchestrateur, opus). Suite au dialogue de ratification de couture avec le BMAD controlHub (bascule E10 côté central). -->

## Story

As l'autorité amont (controlHub) qui a basculé E10 (tous ses POST entrants — ingestion de contrat ET rupture de lien — présentent désormais `Authorization: Bearer <token_de_handshake>`),
I want que SE5 valide réellement l'auth entrante contre le token qu'il a reçu **au handshake** (`api_token`, `irundo_` + 32),
so that les canaux `POST /api/v1/controlhub/contract` et `POST /api/v1/controlhub/sever-link` ne repassent pas en **403 systématique** dès la bascule — alors qu'aujourd'hui `validateSE4FSToken()` compare le Bearer à une colonne (`se4fs_api_token`) alimentée par erreur avec **notre clé d'instance statique locale**, jamais avec le token de handshake.

**Périmètre : couture d'auth entrante + retrait de la rotation hors-handshake.** Le middleware `ControlHubAuth` (dual-accept handshake/legacy, 403, format permissif) reste structurellement intact ; le seul défaut est le **câblage** de la colonne validée. En corollaire, le mécanisme de renouvellement de token hors-handshake (`token/renew`) est retiré : côté central il est hors service (confirmé BMAD controlHub), et depuis E10 le token est **dual-use** (`api_token` sortant == `se4fs_api_token` entrant) — une rotation qui n'aurait mis à jour que `api_token` aurait rebasculé l'ingress en 403 jusqu'au re-handshake.

## Contexte de ratification (couture controlHub ↔ SE5)

Réponses figées avec le BMAD controlHub (leur doc QA §31) :
- **Chemins** : `POST /api/v1/controlhub/{contract,sever-link}` — inchangés (les URL sortantes du CH les composent déjà).
- **Refus** : 403 systématique (jamais 401), corps `{"success":false,"error":"Forbidden","message":...}`.
- **Rotation** : re-handshake seul, atomique, sans période de grâce, ligne la plus récente fait foi.
- **Format** : permissif `^[a-zA-Z0-9_-]{16,}$` maintenu pendant le dual-accept ; durcissement `^irundo_[A-Za-z0-9]{32}$` **différé** à la story de clôture (retrait du repli legacy) — le figer maintenant rejetterait le credential legacy encore émis.
- **Q8 `token/renew`** : MORT côté central, à NE PAS ranimer ⇒ retrait de l'appel côté SE5 (cette story), « rotation = re-handshake seul » dans les deux sens.
- **Critère de clôture** : plus aucune acceptation par le repli legacy observée sur le parc (métrique Q7 ajoutée ici).

## Acceptance Criteria

1. **Câblage E10 (le fix bloquant).** Dans `ControlHubService::performHandshake()`, l'appel `saveHandshakeConnection()` passe `se4fsApiToken: $handshakeResponse->apiToken` (le token reçu du CH) — et non plus `$this->instanceApiKey`. La colonne `se4fs_api_token`, validée par `ControlHubConnection::validateSE4FSToken()` dans le middleware, porte donc le token de handshake. Les deux colonnes (`api_token` chiffrée sortante, `se4fs_api_token` claire entrante) portent la même valeur : le bearer commun aux deux sens.

2. **Preuve de câblage (test).** Un test exécute `performHandshake()` avec une réponse de handshake portant `instance.api_token = irundo_…` (clé d'instance locale volontairement différente) et prouve que la connexion persistée a `se4fs_api_token === irundo_…` (≠ clé locale), que `api_token === irundo_…`, et que `validateSE4FSToken(irundo_…)` est vrai / `validateSE4FSToken(clé_locale)` faux. Un second test prouve la **boucle E10 réelle** : le token de handshake authentifie un POST d'ingestion (2xx) via le vrai middleware+route, un token inconnu → 403.

3. **Retrait complet de la rotation hors-handshake (Q8).** Suppression de : `ControlHubService::performTokenRenewal()` + le bloc `needsRenewal()`→renewal dans `getToken()` ; `ControlHubApiClient::renewToken()` ; `ControlHubConnectionRepository::renewToken()` + `::needsRenewal()` ; `ControlHubConnection::needsRenewal()` ; le DTO `TokenRenewalResponse` ; l'entrée `endpoints.token_renewal` de `config/controlHub.php` ; la mention README. `getToken()` retourne simplement l'`api_token` de la connexion active (appelé par `performHeartbeat`, préservé). Aucune référence résiduelle (`grep` = 0 hors commentaire d'intention).

4. **Métrique de bascule (Q7).** `ControlHubAuth` émet un signal dédié (`Log::warning` message stable `ControlHub auth: legacy instance_api_key fallback accepted` + marqueur `controlhub_legacy_fallback`) quand — et seulement quand — la branche **legacy** `instance_api_key` accepte une requête. C'est la métrique du critère de clôture. La branche handshake ne l'émet pas.

5. **Non-régression.** Suites du domaine ControlHub vertes sur l'hôte (php8.4 + pdo_sqlite). Le token n'apparaît dans aucun log (le middleware ne loggue que ip/url/méthode ; le log Q7 ne porte pas le Bearer). NFR : ni `agent/**`, ni `StateCompiler`, ni golden/`FROZEN_STATE_HASH` touchés ; aucun bump `agent/shared/version.go`. R3 : aucun mot « central » introduit.

6. **Testabilité (micro-seam, zéro changement de comportement prod).** `performHandshake()` instancie son client de handshake via une méthode protégée `makeHandshakeClient()` (comportement identique : `new ControlHubApiClient($baseUrl)`) surchargeable en test — le `new Guzzle` direct n'étant pas interceptable (`ControlHubApiClient` utilise Guzzle, pas le facade `Http`).

## Tasks / Subtasks

- [x] Task 1 — Câblage E10 (AC: 1, 6)
  - [x] `ControlHubService::performHandshake()` : `se4fsApiToken: $handshakeResponse->apiToken` + commentaire d'intention E10
  - [x] Seam `makeHandshakeClient()` (protégé, comportement inchangé)
- [x] Task 2 — Retrait rotation hors-handshake (AC: 3)
  - [x] `ControlHubService` : retrait import DTO, `performTokenRenewal()`, bloc renewal de `getToken()`
  - [x] `ControlHubApiClient::renewToken()`, `Repository::renewToken()`/`needsRenewal()`, `ControlHubConnection::needsRenewal()`
  - [x] Suppression DTO `TokenRenewalResponse` (git rm), config `token_renewal`, README
- [x] Task 3 — Métrique Q7 (AC: 4)
  - [x] `ControlHubAuth::logLegacyFallbackAccepted()` sur la branche `instance`
- [x] Task 4 — Tests (AC: 2, 5)
  - [x] `tests/Feature/ControlHub/HandshakeTokenWiringTest.php` (câblage + boucle E10 bout-en-bout)
  - [x] Correctif harness collatéral E10 (voir Dev Notes) : `ShortcutSyncTest`/`WorkstationGroupSyncTest`
- [x] Task 5 — Non-régression (AC: 5)
  - [x] `php -l` sur les fichiers modifiés ; `grep` renewal résiduel = 0 ; `grep central` diff = 0

## Dev Notes

### Le défaut d'origine (pourquoi 403 après E10)

`validateSE4FSToken()` (`ControlHubConnection:180`) valide `se4fs_api_token`. Or `performHandshake()` (`ControlHubService:80`) alimentait cette colonne avec `$this->instanceApiKey` (= `config('controlHub.se4fs.instance_api_key')`), pas avec le `api_token` reçu du CH (rangé, lui, dans la colonne chiffrée `api_token`, jamais relue par le middleware). Les DEUX branches du middleware validaient donc la même clé d'instance statique — le « dual-accept » E10 était un no-op. Dès que le CH cesse d'émettre l'instance_api_key et passe au `irundo_…`, les deux branches échouent → 403.

### Pourquoi le retrait de `token/renew` (et pas un renouvellement « sûr »)

`Repository::renewToken()` ne mettait à jour que `api_token` (jamais `se4fs_api_token`). Après E10 (token dual-use), une rotation réussie aurait désynchronisé les colonnes : le CH présente le nouveau token en ingress, SE5 valide l'ancien `se4fs_api_token` → 403 jusqu'au re-handshake. Seul appelant vivant : `getToken()` (via `needsRenewal()` à 2/3 de vie), lui-même appelé par `performHeartbeat`. Côté central l'endpoint est mort (500 avant mutation) — rien ne tournait, mais le mécanisme était une bombe à retardement. Retrait complet ; au re-handshake, `createOrUpdate` réécrit les DEUX colonnes ensemble → toujours synchrones.

### Harness collatéral E10 réparé (pré-existant, hors périmètre initial mais dans le thème)

Le commit E10 `a27e564` a introduit l'appel `ControlHubConnection::current()` dans `ControlHubAuth` **sans** mettre à jour les 2 suites à schéma SQLite manuel (`ShortcutSyncTest`, `WorkstationGroupSyncTest`) qui ne créent pas `controlhub_connection` → 21 tests « no such table » (échec confirmé sur HEAD, mes changements stashés). Réparé : création de la table (vide → repli legacy) + `dropIfExists` en tearDown. Sans ce correctif la suite ControlHub restait rouge à cause de la couture E10 elle-même.

### Rollout (à savoir, cf. note CH)

Sur toute instance déjà connectée, `se4fs_api_token` ne porte le bon token qu'**après le prochain handshake** post-déploiement. Séquence lab : déployer → re-handshake → recette (a) ingestion avec dernier token → 2xx ; (b) token périmé → 403 ; (c) sever (acquis par middleware partagé). Le repli legacy couvre l'intervalle. Métrique Q7 pilote le retrait ultérieur du repli + durcissement du format.

### Décision de conception — seam `makeHandshakeClient()`

`ControlHubApiClient` construit un client Guzzle dans son constructeur (pas le facade `Http`) → non interceptable par `Http::fake()`, et `performHandshake()` en instanciait un neuf en interne. Extraction d'une méthode protégée surchargeable, **comportement prod identique** (client neuf par handshake, client principal mis à jour sur succès seulement), qui rend la couture E10 testable sans réseau (le test surcharge le seam pour injecter un `sendHandshake` simulé).

### Project Structure Notes

- Aucune route, aucune migration, aucun contrôleur nouveau. Colonnes existantes.
- `getToken()` conservé (appelé par `performHeartbeat`) — seul le bloc renewal en est retiré.

### References

- [Source: app/Services/ControlHub/ControlHubService.php:77-84] — câblage E10 (AC1)
- [Source: app/Models/ControlHubConnection.php:180] — `validateSE4FSToken()` (colonne `se4fs_api_token`)
- [Source: app/Http/Middleware/ControlHubAuth.php] — dual-accept + métrique Q7
- [Source: routes/api.php:403,421] — routes sever-link + contract (même middleware)

## Dev Agent Record

### Agent Model Used

opus (claude-opus-4-8[1m]).

### Debug Log References

- `php -l` OK sur les 5 fichiers de code modifiés + config.
- `HandshakeTokenWiringTest` → 2/2 (12 assertions).
- Domaine ControlHub (Feature + Unit Services/ControlHub + ControlHubContractTest) → **362/362** (2575 assertions) — inclut la récupération des 21 pré-existants (harness E10).
- Fédérées (modèle ControlHubConnection touché) → `FederatedJwtVerifier`+`FederatedLoginEndpoint` **41/41** (104 assertions).
- `grep` renewal résiduel (`TokenRenewalResponse|performTokenRenewal|needsRenewal|renewToken|token_renewal`) hors commentaire d'intention = 0.

### Completion Notes List

- **AC1** livré : `se4fsApiToken: $handshakeResponse->apiToken`. **AC3** : retrait complet du mécanisme renewal (7 points de suppression, DTO git rm). **AC4** : `logLegacyFallbackAccepted()` (message/marqueur stables). **AC6** : seam `makeHandshakeClient()`.
- Preuve d'origine (AC2) : clé d'instance locale volontairement ≠ du token amont dans le test → l'acceptation ne peut venir que du câblage E10.
- Harness E10 pré-existant réparé (2 fichiers) — sinon suite ControlHub rouge.

### File List

**Modifiés :**
- `app/Services/ControlHub/ControlHubService.php` (câblage E10 + seam + retrait renewal)
- `app/Services/ControlHub/ControlHubApiClient.php` (retrait `renewToken()`)
- `app/Repositories/ControlHubConnectionRepository.php` (retrait `renewToken()`/`needsRenewal()`)
- `app/Models/ControlHubConnection.php` (retrait `needsRenewal()`)
- `app/Http/Middleware/ControlHubAuth.php` (métrique Q7)
- `config/controlHub.php` (retrait `endpoints.token_renewal`)
- `app/Services/ControlHub/README.md` (retrait DTO de l'arbo)
- `tests/Feature/ControlHub/ShortcutSyncTest.php` + `WorkstationGroupSyncTest.php` (harness E10)

**Créés :**
- `tests/Feature/ControlHub/HandshakeTokenWiringTest.php`

**Supprimés :**
- `app/Services/ControlHub/Data/TokenRenewalResponse.php`

## Recommandation Modèle Review

**sonnet** (modèle opposé) — surface de sécurité (auth entrante), mais diff net et bien cerné par les tests. Points de vigilance pour la review : (1) confirmer que `se4fs_api_token` == token de handshake ne fuit nulle part en clair hors DB ; (2) vérifier qu'aucun appelant résiduel du renewal n'a été manqué ; (3) valider que le seam `makeHandshakeClient()` n'altère pas le comportement prod.
