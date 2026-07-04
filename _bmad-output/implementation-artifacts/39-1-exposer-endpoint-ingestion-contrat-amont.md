# Story 39.1: Exposer l'endpoint HTTP d'ingestion du contrat amont (canal ①)

Status: to-validate

<!-- Créée le 2026-07-04 (create-story, Epic 39 — epics-alignement-controlhub-se5.md). -->
<!-- Review 2026-07-04 (opus, adversariale) : APPROVE-WITH-FIXES → _bmad-output/codeReviews/39-1.md.
     Corrigé auto : #1 🟠 (garde de frontière HTTP contre un corps non-contrat coercé en désir d'état
     vide destructif — 422 avant délégation) + #2 🟡 (tests). #3 🟡 ignoré (frontière 39.1↔39.2 par
     conception). Tests HÔTE : ContractIngestionEndpointTest 9/9 ; non-régr 18/18. En attente validation Henri. -->

## Story

As l'autorité amont (controlHub),
I want POSTer le contrat `se5-contract/v1` sur un endpoint HTTP authentifié de SE5,
so that l'ingestion idempotente **déjà livrée** (Epics 28/33) devienne réellement opérante de bout en bout (canal ① du lien managé — gap OPEN-5, bloquant pour tout le reste de l'Epic 39).

**Périmètre : câblage pur.** `ControlHubContractIngestionService::ingest()` existe, est testé et est le **seul** écrivain des tables `controlhub_contract_*` (NFR-A1). Cette story ajoute une route + un contrôleur mince + le mapping des exceptions de domaine en codes HTTP. **Rien** de l'ingestion n'est réécrit, déplacé ni dupliqué.

## Acceptance Criteria

1. **Route.** `POST /api/v1/controlhub/contract` est déclarée dans `routes/api.php` sous le middleware `controlhub.auth` (déjà enregistré dans `app/Http/Kernel.php:80`), nommée `controlhub.contract.ingest`, avec son propre bloc de commentaire docblock (patron du bloc sever-link, lignes 386-404). Elle est placée **à la fin du fichier, APRÈS le groupe 16.12** — idéalement juste après le bloc `controlhub.link.sever` (32.1), jamais avant (piège fenêtre 1500 chars de `ScriptsOsNamespaceTest`). L'import `use App\Http\Controllers\Api\v1\ControlHub\ContractIngestionController;` est ajouté dans la zone d'imports ControlHub en tête de fichier (à côté de `LinkSeveranceController`).

2. **Contrôleur mince.** `app/Http/Controllers/Api/v1/ControlHub/ContractIngestionController.php` : contrôleur **invocable** calqué sur `LinkSeveranceController` (32.1). Il lit le payload via `$request->json()->all()`, appelle `ControlHubContractIngestionService::ingest($payload)` (service injecté en paramètre de `__invoke`), et construit la réponse JSON. Aucune validation métier, aucune normalisation, aucune transaction dans le contrôleur — tout cela vit déjà dans le service.

3. **200 nominal — résumé complet.** Un payload conforme `se5-contract/v1` (cf. artefact R2 `schema-echange-controlhub-se5.md`) retourne HTTP **200** avec `{"success": true}` fusionné au résumé **complet** de `ContractIngestionResult::toArray()` : `contract_created`, `mutated`, `contract_id`, `schema_version`, et les compteurs `{created, updated, deleted}` par agrégat (`items`, `labels`, `imposed_groups`, `catalog_apps`). *(Question ouverte de l'epic « résumé complet vs minimal » tranchée : complet — le DTO le construit déjà, il sert l'observabilité du central, et c'est symétrique de la verbosité du sever-link.)*

4. **No-op idempotent (NFR-A2).** La ré-réception du **même** payload retourne 200 avec `mutated=false`, compteurs à zéro : aucune écriture, `received_at`/`schema_version` inchangés, aucun événement `ControlHubContractChanged` ré-émis. C'est le service qui le garantit (NFR4 28.2/33.1) ; la story le **prouve via la route** (test Feature, `Event::assertDispatchedTimes(ControlHubContractChanged::class, 1)` après deux POST identiques).

5. **422 — exceptions de domaine, état inchangé.** Le contrôleur mappe (try/catch local) :
   - `App\Exceptions\ControlHub\UnsupportedSchemaVersionException` → **422** `{"success": false, "error": "unsupported_schema_version", "message": <message de l'exception>}` ;
   - `App\Exceptions\ControlHub\InvalidUpstreamContractException` → **422** `{"success": false, "error": "invalid_upstream_contract", "message": <message de l'exception>}`.

   Les deux sont levées par le service **en validation pure, avant la transaction** ⇒ l'état persisté est strictement inchangé (à vérifier en test : compteurs de tables identiques avant/après). Les messages des exceptions sont réutilisés tels quels (déjà R3-safe : « reçue ‹X› vs supportées ‹…› », champ fautif + raison). Toute autre exception n'est **pas** attrapée (500 standard).

6. **Auth avant corps (NFR-A3).** Auth invalide ou absente → réponse **existante** du middleware `ControlHubAuth` : **403** JSON `{"success": false, "error": "Forbidden", "message": ...}`. Ne pas modifier cette réponse, ne pas introduire de 401. L'ordre de contrôle est structurel : le middleware n'accède jamais au body (il ne lit que le header `Authorization`), et le contrôleur — seul lecteur du payload — n'est atteint qu'après auth. Le token n'apparaît dans **aucun** log (le middleware existant logge ip/url/méthode, jamais la clé — ne rien ajouter qui logge le header `Authorization` ou le Bearer).

7. **Branche morte `master_api_key` tranchée — RETRAIT.** Dans `app/Http/Middleware/ControlHubAuth.php` : supprimer la lecture `config('controlHub.master_api_key')` (ligne 35) et la branche `$keyType = 'master'` (lignes 40-42). `config/controlHub.php` n'est **pas** modifié (la clé n'y a jamais existé). Après retrait, `controlhub_key_type` vaut toujours `'instance'` ; `LinkSeveranceController` (actor label `'controlhub:'.$keyType`) continue de fonctionner sans changement. Justification en Dev Notes. Nettoyer aussi la mention `master_api_key` de `app/Services/ControlHub/README.md` (ligne ~96) si elle décrit le flux SE5 (sinon la laisser si elle documente le côté central).

8. **Non-régression (NFR-A4 / NFR-A5).** `StateCompiler`, `ContractV1`, tests golden, `FROZEN_STATE_HASH`, `agent/**` : **non touchés** (pas de bump `agent/shared/version.go`). Les tests ControlHub existants restent verts (filtres ciblés — cf. Dev Notes). Aucun mot « central » dans un identifiant, une route, un message ou un commentaire nouveau : vocabulaire « amont » / `upstream` / `ControlHub*` (R3).

9. **Tests Feature** (HÔTE php8.4 + pdo_sqlite, `RefreshDatabase`) : `tests/Feature/ControlHub/ContractIngestionEndpointTest.php`, patron `ContractSeveranceChannelsTest` (setUp : `app.key` fallback, `withoutVite()`, `WorkstationGroupObserver::disableSync()`, `Event::fake([ControlHubContractChanged::class])`). Cas couverts :
   - 200 nominal : payload avec les 4 agrégats → résumé complet, contrat créé, enfants persistés ;
   - no-op : 2e POST identique → 200, `mutated=false`, événement dispatché **une seule** fois ;
   - 422 version : `schema_version` déclarée non supportée (ex. `"99.0"`) → `error=unsupported_schema_version`, **aucune** ligne écrite ;
   - 422 contenu : payload hors domaine (ex. `enforcement_state` invalide) → `error=invalid_upstream_contract`, **aucune** ligne écrite ;
   - 403 sans header `Authorization` ;
   - 403 avec Bearer invalide (clé ≥ 16 chars mais différente de `controlHub.se4fs.instance_api_key`).

## Tasks / Subtasks

- [x] Task 1 — Contrôleur mince (AC: 2, 3, 5)
  - [x] Créer `app/Http/Controllers/Api/v1/ControlHub/ContractIngestionController.php` (invocable, docblock avec garde-fou R3, patron `LinkSeveranceController`)
  - [x] `__invoke(Request $request, ControlHubContractIngestionService $ingestionService): JsonResponse` — lecture `$request->json()->all()`, appel `ingest()`, réponse `['success' => true] + $result->toArray()`
  - [x] try/catch local : mapper les 2 exceptions de domaine en 422 (codes `unsupported_schema_version` / `invalid_upstream_contract`), laisser passer le reste
- [x] Task 2 — Route (AC: 1)
  - [x] Ajouter l'import du contrôleur dans la zone d'imports ControlHub de `routes/api.php`
  - [x] Déclarer `Route::post('/v1/controlhub/contract', ContractIngestionController::class)->middleware('controlhub.auth')->name('controlhub.contract.ingest')` en **fin de fichier**, après le bloc sever-link, avec son bloc de commentaire (rappeler : APRÈS le groupe 16.12, fenêtre 1500 chars)
- [x] Task 3 — Trancher la branche morte `master_api_key` (AC: 7)
  - [x] Retirer la lecture de `config('controlHub.master_api_key')` et la branche `master` de `ControlHubAuth::handle()`
  - [x] Vérifier qu'aucun test ni code n'attend `controlhub_key_type === 'master'` (grep : seul le middleware et `app/Services/ControlHub/README.md` référencent `master_api_key`)
  - [x] Ajuster la mention du README si elle décrit le flux SE5
- [x] Task 4 — Tests Feature (AC: 4, 6, 9)
  - [x] Créer `tests/Feature/ControlHub/ContractIngestionEndpointTest.php` (6 cas de l'AC 9)
  - [x] Clé de test : `config(['controlHub.se4fs.instance_api_key' => 'instance-key-0123456789'])` (≥ 16 chars — contrainte de format `isValidApiKeyFormat` du middleware)
- [x] Task 5 — Vérifications de non-régression (AC: 8)
  - [x] Lancer sur l'HÔTE, par filtres ciblés : `php artisan test --filter=ContractIngestionEndpointTest`, puis `--filter=ControlHubContractIngestionTest`, `--filter=ControlHubContractSchemaVersionTest`, `--filter=UnsupportedSchemaVersionRejectionTest`, `--filter=ContractSeveranceChannelsTest` (le middleware a changé — le sever-link doit rester vert)
  - [x] Confirmer zéro diff sous `agent/`, `app/Services/StateCompiler*`, golden

## Dev Notes

### Existant à réutiliser — NE PAS réécrire

| Brique | Fichier | Rôle pour 39.1 |
|---|---|---|
| Ingestion idempotente | `app/Services/ControlHub/ControlHubContractIngestionService.php` (`ingest()`) | Cible unique de la délégation. Négociation version → normalisation/validation pure → transaction → event 1× sur mutation. |
| DTO résumé | `app/Services/ControlHub/Data/ContractIngestionResult.php` (`toArray()`) | Corps de la réponse 200. |
| Exceptions de domaine | `app/Exceptions/ControlHub/UnsupportedSchemaVersionException.php`, `.../InvalidUpstreamContractException.php` | Mappées en 422 par le contrôleur. Levées AVANT toute écriture ⇒ état inchangé garanti. |
| Middleware auth | `app/Http/Middleware/ControlHubAuth.php` (alias `controlhub.auth`, `app/Http/Kernel.php:80`) | Bearer → `hash_equals` avec `controlHub.se4fs.instance_api_key`. Répond **403** (jamais 401). Porte la branche morte `master_api_key` à retirer. |
| Patron contrôleur + route | `app/Http/Controllers/Api/v1/ControlHub/LinkSeveranceController.php` + `routes/api.php:386-404` | Invocable mince, `{'success': true, ...}`, bloc commentaire, placement fin de fichier. |
| Patron test | `tests/Feature/ControlHub/ContractSeveranceChannelsTest.php` | setUp complet (Vite, observer, Event::fake), `withHeaders(['Authorization' => 'Bearer ...'])->postJson(...)`. |
| Schéma d'échange | `_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md` | Forme du payload (5 agrégats + `schema_version` racine). Rien à y changer en 39.1 (aucun champ nouveau). |

### Décision `master_api_key` : retrait (recommandé)

- La clé n'est définie **nulle part** : absente de `config/controlHub.php`, aucun `env()` associé, aucune doc d'exploitation. Seuls consommateurs : la branche du middleware (code mort par construction — `!empty('')` est toujours faux) et une mention README.
- Le modèle d'auth réel est **mono-clé** : le central s'authentifie avec la clé d'instance émise/échangée au handshake (`controlHub.se4fs.instance_api_key`). Une « master key » globale valable sur tous les endpoints serait un super-pouvoir sans cas d'usage — la définir maintenant serait un choix sur-conçu **et** un élargissement de surface d'attaque.
- Impact du retrait : `controlhub_key_type` devient invariablement `'instance'`. Le seul lecteur (`LinkSeveranceController`, actor label) reste correct. Aucun test n'exerce la branche master.

### Choix figés (questions ouvertes de l'epic)

- **Corps du 200** : résumé complet (`toArray()`) — cf. AC 3.
- **Rate-limit dédié** : **non**. Symétrie avec le sever-link (aucun throttle) ; les diffusions de contrat sont rares et authentifiées Bearer. En ajouter un serait spéculatif (règle projet : pas de choix sur-conçu). Le jour où le besoin est réel : `->middleware('throttle:...')` sur la route, une ligne.
- **Mapping des exceptions dans le contrôleur** (try/catch local) plutôt que dans le handler global : ces exceptions n'ont qu'un seul point d'entrée HTTP, le mapping reste colocalisé et lisible, et on ne pollue pas le rendu d'exceptions global de l'app.

### Pièges connus (mémoire projet)

- **`routes/api.php` — fenêtre 1500 chars** : tout nouveau bloc API se place APRÈS le groupe 16.12, sinon `ScriptsOsNamespaceTest` casse. Le bon endroit : fin de fichier, après le bloc sever-link.
- **Lecture du payload** : `$request->json()->all()` (pas `$request->all()`, qui fusionne la query string dans le payload).
- **Tests HÔTE, jamais VM** : php8.4 + pdo_sqlite sur l'hôte ; la VM n'a pas pdo_sqlite. Run massif VM = faux échecs — toujours des `--filter` ciblés.
- **Route réelle + VM** : après sync inotify, la route ne sera visible sur la VM qu'après `php artisan route:cache` + chown (cache VM non synchronisé). Hors périmètre des tests de la story (hôte), mais à savoir pour un e2e curl éventuel.
- **R3** : aucun « central » dans identifiants/messages/commentaires. Les docblocks existants du domaine portent tous le garde-fou — le reproduire.
- **NFR-A4** : ne toucher ni `agent/**` ni `StateCompiler` ; pas de bump `agent/shared/version.go` (le payload agent ne change pas).

### Project Structure Notes

- Contrôleur dans `app/Http/Controllers/Api/v1/ControlHub/` (convention du namespace existant : `LinkSeveranceController`, `InstanceStatusController`, …).
- Route **hors** du groupe `Route::prefix('v1')->middleware('controlhub.auth')` de la ligne 80 : ce groupe est en tête de fichier, AVANT le groupe 16.12 — y ajouter la route violerait la fenêtre 1500 chars. Déclaration autonome en fin de fichier, comme le sever-link (chemin complet `/v1/controlhub/contract`, middleware explicite).
- Test dans `tests/Feature/ControlHub/` (suite dédiée du domaine).

### References

- [Source: _bmad-output/planning-artifacts/epics-alignement-controlhub-se5.md#Story-39.1] — intention, AC-skeleton, FR-A1, NFR-A1..A5
- [Source: _bmad-output/planning-artifacts/schema-echange-controlhub-se5.md] — payload `se5-contract/v1`, politique de version, idempotence §4
- [Source: app/Services/ControlHub/ControlHubContractIngestionService.php] — contrat d'appel `ingest()` + exceptions
- [Source: app/Http/Controllers/Api/v1/ControlHub/LinkSeveranceController.php + routes/api.php:386-404] — patron 32.1
- [Source: tests/Feature/ControlHub/ContractSeveranceChannelsTest.php] — patron de test de route `controlhub.auth`

## Dépendances

- **Amont (DONE)** : 28.2 (ingestion idempotente), 33.1 (schéma d'échange versionné), 33.2 (rejet gracieux d'une version non supportée). Rien à attendre.
- **Aval — BLOQUANTE pour 39.4** : 39.4 étend l'ingestion (blocs `artifact`/`executable`/`delivery_mode`) et présuppose la réception HTTP livrée ici. Plus largement, sans réception il n'y a pas de lien managé opérant.
- **Indépendante** de 39.2 (émetteur de conformité) et 39.3 (bridge IdP) — parallélisables après celle-ci.
- **Coordination R2** : le chemin `POST /api/v1/controlhub/contract` est le conventionnel attendu côté central (OPEN-5, doc `CONTRAT-MANAGE-SE5-IRUNDO.md`) — à ratifier avec le BMAD controlHub ; toute divergence constatée = story d'alignement (famille OPEN). Aucun champ de schéma n'est figé/modifié par cette story ⇒ pas d'édition de l'artefact R2.

## Dev Agent Record

### Agent Model Used

claude-sonnet-5

### Debug Log References

- `php artisan test --filter=ContractIngestionEndpointTest` → **6/6** (32 assertions)
- `php artisan test --filter=ControlHubContractIngestionTest` → **14/14** (129 assertions)
- `php artisan test --filter=ControlHubContractSchemaVersionTest` → **10/10** (574 assertions)
- `php artisan test --filter=UnsupportedSchemaVersionRejectionTest` → **10/10** (54 assertions)
- `php artisan test --filter=ContractSeveranceChannelsTest` → **4/4** (15 assertions) — middleware partagé `ControlHubAuth` modifié (AC7), canal jumeau confirmé non régressé
- `git status`/`git diff --stat -- agent/` : aucun fichier sous `agent/**` touché ; `app/Services/StateCompiler*` et golden non modifiés
- `grep -rin master_api_key` : plus aucune occurrence hors historique (README/config non concernés — cf. Completion Notes)

### Completion Notes List

**Câblage pur, conforme au périmètre figé de la story.** Aucune ligne de `ControlHubContractIngestionService::ingest()` n'a été touchée : le contrôleur lit `$request->json()->all()`, délègue, mappe les 2 exceptions de domaine en 422, et renvoie `['success' => true] + $result->toArray()`. Route déclarée en fin de `routes/api.php`, après le bloc sever-link (32.1) — fenêtre 1500 chars de `ScriptsOsNamespaceTest` préservée.

- **AC7 — retrait `master_api_key`** : la branche `$masterKey = config('controlHub.master_api_key', '')` + `$keyType = 'master'` a été supprimée de `ControlHubAuth::handle()`. `controlhub_key_type` vaut désormais invariablement `'instance'` (seule valeur jamais atteignable, la clé n'existant nulle part en config). `LinkSeveranceController` (actor label `'controlhub:'.$keyType`) fonctionne sans changement — confirmé par `ContractSeveranceChannelsTest` (4/4 verts).
- **README `app/Services/ControlHub/README.md` (ligne 96)** : mention `$request->master_api_key` **conservée** — après lecture, elle documente un flux DISTINCT et sans rapport : un exemple de contrôleur appelant `ControlHubService::performHandshake($masterKey, $baseUrl)`, l'appel SORTANT (SE5 → controlHub) d'un handshake initial où SE5 transmet une clé maître qu'il détient. Ce n'est ni le flux entrant (`ControlHubAuth`, celui dont la branche morte a été retirée) ni le même concept de configuration (`config('controlHub.master_api_key')`, jamais lu ici). Décision : ne pas toucher, car cela documenterait le côté handshake/central (hors périmètre AC7, qui ne visait que la branche morte du middleware entrant).
- **200 nominal** : corps = `['success' => true] + ContractIngestionResult::toArray()` (résumé complet — décision figée de la story).
- **Tests Feature** : 6 cas de l'AC9 tous couverts dans `ContractIngestionEndpointTest.php` (patron `ContractSeveranceChannelsTest`) : 200 nominal (4 agrégats, compteurs `created` exacts), no-op (2e POST identique → `mutated=false`, compteurs à zéro, `Event::assertDispatchedTimes(ControlHubContractChanged::class, 1)` après les 2 POST), 422 version (`schema_version=99.0`), 422 contenu (`enforcement_state` hors domaine), 403 sans header, 403 Bearer invalide (≥16 car., ≠ clé d'instance).
- **R3** : `grep -rin central` sur les fichiers livrés (contrôleur, middleware, route, test, doc QA) ⇒ aucune occurrence hors garde-fous documentaires déjà existants.
- **NFR-A4/A5** : aucun fichier sous `agent/**` ni `StateCompiler*`/golden modifié ; aucun bump `agent/shared/version.go` (payload agent inchangé).
- **Reste à valider en VM/lab** (différé, hors dev-cycle) : `php artisan route:cache` + `chown www-admin` après le prochain sync inotify (la route n'est pas visible sur la VM tant que le cache de routes n'est pas régénéré — mémoire projet), puis e2e curl authentifié (200/422×2/403×2) — procédure détaillée dans `docs/qa/domains/controlhub-contract.md` Section 21, Scénario 21.2.

### File List

**Créés :**
- `app/Http/Controllers/Api/v1/ControlHub/ContractIngestionController.php`
- `tests/Feature/ControlHub/ContractIngestionEndpointTest.php`

**Modifiés :**
- `routes/api.php` (import `ContractIngestionController` + route `controlhub.contract.ingest` en fin de fichier, après le bloc sever-link)
- `app/Http/Middleware/ControlHubAuth.php` (AC7 — retrait de la branche morte `master_api_key`)
- `docs/qa/domains/controlhub-contract.md` (Section 21 — scénarios de vérification manuelle HÔTE + VM)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (ligne `39-1-exposer-endpoint-ingestion-contrat-amont` : `ready-for-dev` → `review`)

## Recommandation Modèle Dev

**sonnet** — confirmé après lecture du code. C'est du câblage pur : contrôleur invocable calqué sur un patron existant (`LinkSeveranceController`), route d'une ligne au bon endroit, try/catch de deux exceptions déjà typées, suppression d'une branche morte de 5 lignes, et un test Feature dont le squelette (`ContractSeveranceChannelsTest`) existe. Zéro logique métier neuve ; le risque est disciplinaire (ne pas réécrire l'ingestion, respecter la fenêtre 1500 chars), et il est verrouillé par les AC.
