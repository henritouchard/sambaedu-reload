# Story 39.2: Émetteur de conformité `se5-contract-compliance/v1` (canal ③)

Status: to-validate

<!-- Créée le 2026-07-04 (create-story, Epic 39 — epics-alignement-controlhub-se5.md).
     Exploration du code réel effectuée AVANT rédaction : aucune agrégation de conformité
     par item de contrat n'existe aujourd'hui côté SE5 (ConformityService/agent_resource_states
     agrègent par TYPE de ressource × poste, jamais par clé d'item) — cette story invente cette
     politique. Découverte matérielle : le canal `capabilities` documenté par la spec centrale
     est un « pseudo-canal mort » côté SE5 — le VRAI canal câblé est `type='registry'`
     (RegistryUpstreamAdapter, seul adaptateur enregistré). Voir Dev Notes §Découvertes clés. -->

## Story

En tant qu'instance SE5 liée à l'autorité amont (controlHub),
je veux émettre périodiquement un rapport de conformité état-intégral (`se5-contract-compliance/v1`) décrivant l'état d'application de chaque item du contrat amont reçu,
afin que le central dispose d'une vue à jour de ce qui est réellement en vigueur côté SE5 (canal ③ du lien managé — gap OPEN-5).

**Périmètre.** Cette story crée le **premier émetteur** SE5 → central du lien managé (les canaux existants — ②rupture, ①ingestion 39.1 — sont tous des RÉCEPTEURS ou des services synchrones purs). Elle NE touche NI `ControlHubContractIngestionService` NI `StateCompiler` NI l'agent (`agent/**`) : elle **lit** l'état résolu (`controlhub_contracts`/`controlhub_contract_items`, Epics 28-33) et les signaux d'override locaux existants (`capability_assignments`, Epic 27/29), puis **construit et POST** l'enveloppe. Elle **invente** la politique de mapping de statut par item — cette politique n'existe nulle part ailleurs dans le code (cf. Dev Notes).

## Acceptance Criteria

1. **Enveloppe conforme.** `App\Services\ControlHub\ControlHubComplianceReportService::buildEnvelope(): ?array` construit :
   ```json
   {
     "schema_version": "1.0",
     "instance_id": "<config('controlHub.se4fs.instance_id')>",
     "link_state": "active",
     "contract_received_at": "<ControlHubContract::active()->received_at ISO8601>",
     "reported_at": "<now() ISO8601, généré à chaque appel>",
     "items": [ { "type": ..., "key": ..., "target_type": ..., "target_label": ..., "status": ..., "detail": ..., "observed_at": ... } ]
   }
   ```
   Conforme à `../irundoo/documentation/SE5-CONTRACT-COMPLIANCE-V1.md` (spec centrale, **non ratifiée**). `target_label` est **toujours une chaîne** (`''` pour `target_type=instance`, jamais `null` — invariant déjà garanti par `ControlHubContractItem`, NFR4).

2. **Guard — pas d'émission parasite (NFR-A1).** `buildEnvelope()` retourne **`null`** si `ControlHubContract::active()` est `null` (aucun contrat actif — standalone OU lien `severed`, `ControlHubContract::active()` renvoie déjà `null` dans les deux cas, cf. `ControlHubContractSeveranceService`). `ControlHubComplianceReportService::emit()` retourne alors `['sent' => false, 'reason' => 'no_active_contract']` **sans appeler** `ControlHubApiClient`. Idem si `ControlHubConnection::current()` est `null`/invalide (`reason => 'no_active_connection'`) ou si aucun token n'est disponible (`reason => 'no_token'`). Test : ces 3 gardes sont prouvées par un mock `ControlHubApiClient` qui `shouldNotReceive('callEndpoint')`.

3. **Clé naturelle miroir + filtrage `absent`.** Chaque item du rapport reprend **exactement** `(type, key, target_type, target_label)` de son `ControlHubContractItem` d'origine (miroir strict, aucune transformation de valeur). Les items `enforcement_state = Absent` sont **exclus** du rapport (l'amont n'impose rien sur ce type — rien à rapporter, symétrique de la sémantique contrat). `items: []` est un résultat **valide** (contrat actif mais 0 item non-absent) — pas de garde spéciale, c'est la conséquence naturelle du filtre.

4. **Mapping de statut — position par défaut (à confirmer, cf. Dev Notes Q1/Q2) :**
   - Item `enforcement_state = Locked` → `status = 'applied'`.
   - Item `enforcement_state = Permissive`, **ET** `type = 'registry'` **ET** `target_type = Instance` : vérifier s'il existe un override local actif (au moins une ligne `capability_assignments` pour une `Capability` dont une projection `registry` matche la clé de l'item — identité `hive|path|name`, **même algorithme de normalisation que `UpstreamLockResolver::exclusiveKey()`/`normalizeItemKey()`, ne pas réinventer une 3ᵉ normalisation**) :
     - override trouvé → `status = 'overridden'`, `detail` = libellé lisible (capacité + parc porteur, ex. `capability_label` + nom du `WorkstationGroup`) ;
     - sinon → `status = 'applied'`.
   - Tout autre item `Permissive` (type ≠ `registry`, ou `target_type = Label`, ou type `wallpapers`/`applications`/`shortcuts`/`agent_tools`/`capabilities` sans mécanisme d'override câblé aujourd'hui) → `status = 'applied'` (limitation documentée : aucune source d'override existe pour ces types en l'état du code — pas de faux `overridden`).
   - `status = 'pending'` / `'error'` : **non calculés** par cette story en v1 (aucun signal fiable au grain PAR ITEM aujourd'hui — cf. Dev Notes §Découvertes). Extension point documenté, pas construit maintenant (anti sur-engineering).
   - `observed_at` = horodatage de génération du rapport (`now()`), identique pour tous les items d'un même rapport (pas de finesse par poste — cohérent avec l'absence de grain par item du reporting agent existant).

5. **Émission HTTPS authentifiée.** `emit()` obtient le token via `ControlHubService::getToken()` (renouvellement automatique inclus, **réutilisé tel quel**, pas de nouvelle logique de token). Appelle `ControlHubApiClient::callEndpoint($endpoint, 'POST', $envelope, $token)` — **aucune modification de `ControlHubApiClient`** n'est nécessaire (`callEndpoint()`/`buildUrl()` gèrent déjà tout endpoint préfixé `/api/sambaedu/...`). Le token n'apparaît **jamais** dans un log (ne pas logger `$token` ni l'en-tête `Authorization` — grep de contrôle en test).

6. **Config — endpoint additif.** `config/controlHub.php` gagne :
   ```php
   'endpoints' => [
       // ... existants inchangés
       'contract_compliance' => '/api/sambaedu/contract-compliance/{instance_id}',
   ],
   'compliance' => [
       'enabled' => env('CONTROLHUB_COMPLIANCE_ENABLED', true),
       'interval' => env('CONTROLHUB_COMPLIANCE_INTERVAL', 15), // minutes
   ],
   ```
   Changement **strictement additif** (aucune clé existante modifiée/supprimée). ⚠️ Chevauchement possible avec la Story 39.4 sur ce même fichier (les deux ajoutent des clés distinctes) — vérifier au merge qu'aucune des deux n'a écrasé les clés de l'autre.

7. **Service + Job + Command.**
   - `App\Services\ControlHub\ControlHubComplianceReportService` : logique pure (`buildEnvelope()`, `emit()`), testable sans queue ni HTTP réel (ApiClient mocké).
   - `App\Jobs\ControlHubReportComplianceJob implements ShouldQueue` (`tries = 3`) : job **fin**, appelle `ControlHubComplianceReportService::emit()`. Permet le retry automatique Laravel sur échec HTTP transitoire sans bloquer le tick du scheduler (queue `laravel-queue-general` déjà en place, cf. `DispatchMachinePowerActionJob`).
   - `App\Console\Commands\ControlHubReportComplianceCommand` (signature `controlhub:report-compliance`) : vérifie `config('controlHub.compliance.enabled')`, vérifie qu'un contrat actif + une connexion valide existent (court-circuit **avant** de dispatcher — évite d'empiler des jobs inutiles en queue), puis `ControlHubReportComplianceJob::dispatch()`.
   - `app/Console/Kernel.php` : `$schedule->command('controlhub:report-compliance')->everyMinute()->withoutOverlapping()->runInBackground()` — la commande elle-même respecte l'intervalle configuré (patron `ControlHubHeartbeatCommand`, mais gate simplifiée : cadence **fixe** `config('controlHub.compliance.interval')`, pas de colonne BDD dédiée — cf. Dev Notes Q3, position par défaut).
   - **Ne PAS réutiliser** `App\Jobs\ControlHubHeartbeatJob` ni son patron d'auto-redispatch (`Cache::put(...); static::dispatch()->delay(...)`) : ce job n'est référencé nulle part dans le code (`ControlHubHeartbeatCommand` appelle le service **directement**, sans queue) — c'est un patron mort, ne pas le reproduire.

8. **Non-régression (NFR-A4/NFR-A5).** Zéro diff sous `agent/**`, `app/Services/Agent/StateCompiler.php`, tests golden, `FROZEN_STATE_HASH` (cette story ne touche que des chemins SORTANTS server-only). Aucun bump `agent/shared/version.go`. Aucun mot « central » dans un identifiant/message/commentaire nouveau (R3) — vocabulaire « amont » / `upstream` / `ControlHub*`.

9. **Tests** (HÔTE php8.4 + pdo_sqlite, `RefreshDatabase`) — `tests/Feature/ControlHub/ControlHubComplianceReportTest.php` :
   - Enveloppe conforme : contrat actif avec items variés → toutes les clés top-level + clés d'item présentes, types corrects, `target_label` jamais `null`.
   - Pas de contrat actif → `buildEnvelope()` retourne `null`, `emit()` → `sent=false`, mock ApiClient `shouldNotReceive`.
   - Pas de connexion valide (contrat actif mais `ControlHubConnection::current()` null) → idem, `reason=no_active_connection`.
   - `items: []` valide : contrat actif sans item non-`absent` → rapport envoyé avec `items=[]`.
   - Mapping des statuts : item `locked` → `applied` ; item `permissive`/`registry`/`instance` **sans** override → `applied` ; item `permissive`/`registry`/`instance` **avec** override (`capability_assignments` posé) → `overridden` + `detail` non vide ; item `permissive`/`wallpapers` (type sans mécanisme d'override) → `applied` (pas de faux `overridden`) ; item `absent` → **exclu** du tableau `items`.
   - `reported_at` croissant entre deux appels successifs (`Carbon::setTestNow` à deux instants distincts).
   - Command : `Queue::fake()` — commande dispatch le job ssi contrat actif + connexion valide ; **aucun dispatch** sinon.
   - Zéro occurrence du token dans les logs (`Log::spy()` ou assertion sur le contenu des messages loggés).

## Tasks / Subtasks

- [x] Task 1 — Service de construction + émission (AC 1, 2, 3, 4, 5)
  - [x] Créer `app/Services/ControlHub/ControlHubComplianceReportService.php` (constructeur injecte `ControlHubApiClient` + `ControlHubService` + `UpstreamLockResolver`)
  - [x] `buildEnvelope(): ?array` — guard contrat actif, filtre `absent`, mapping statut par item
  - [x] Détection d'override : `UpstreamLockResolver::capabilitiesForRegistryKey()` (méthode publique ajoutée, réutilise la normalisation existante — aucune 3ᵉ), puis `DB::table('capability_assignments')->whereIn('capability_id', ...)->first()` (**aucun modèle Eloquent `CapabilityAssignment`** — accès `DB::table()`)
  - [x] `emit(): array` — guards connexion/token, appel `ControlHubApiClient::callEndpoint()`, log sans token
- [x] Task 2 — Config additive (AC 6)
  - [x] Ajouter `endpoints.contract_compliance` + bloc `compliance` dans `config/controlHub.php`
- [x] Task 3 — Job + Command + scheduling (AC 7)
  - [x] `app/Jobs/ControlHubReportComplianceJob.php` (`ShouldQueue`, `tries=3`, appelle le service)
  - [x] `app/Console/Commands/ControlHubReportComplianceCommand.php` (guards + dispatch + throttle cadence fixe via Cache)
  - [x] Ajouter l'entrée `$schedule->command('controlhub:report-compliance')` dans `app/Console/Kernel.php`
- [x] Task 4 — Tests (AC 9)
  - [x] `tests/Feature/ControlHub/ControlHubComplianceReportTest.php` — tous les cas listés en AC 9 (16 tests)
- [x] Task 5 — Vérifications de non-régression (AC 8)
  - [x] `git diff --stat -- agent/ app/Services/Agent/StateCompiler.php` → vide
  - [x] `grep -rin central` sur les fichiers livrés → seul le garde-fou documentaire idiomatique `aucun mot « central »`

## Dev Notes

### Découvertes clés (exploration du code réel — critiques pour cette story)

1. **Aucune agrégation de conformité par item n'existe aujourd'hui.** `App\Services\Agent\Reporting\ConformityService::summary()`/`exceptionsFor()` agrègent par **type de ressource entier × poste** (`agent_resource_states`, unique par `(workstation_id, type)`, statut `AgentResourceStatus::compliant|drift|error` — **pas** de `pending`). La SEULE exception à grain plus fin est `AgentApplicationInventory` (par `app_id`). **Rien ne relie un `ControlHubContractItem` (type+key+target) à un statut agrégé au niveau instance.** Cette story invente cette politique — c'est pourquoi le mapping de statut (AC 4) est volontairement conservateur.

2. **Mismatch de vocabulaire `registry` vs `capabilities` — RISQUE DE RATIFICATION.** La spec centrale (`SE5-CONTRACT-COMPLIANCE-V1.md`) documente `type ∈ {applications, shortcuts, wallpapers, capabilities, agent_tools}` (enum `ContractItemType`, 422 si hors domaine). Côté SE5, le **seul canal d'imposition réellement câblé** pour les capacités est `type='registry'` (`RegistryUpstreamAdapter`, seul adaptateur enregistré dans `AgentServiceProvider`) — `type='capabilities'` est explicitement documenté comme un **« pseudo-canal mort, sans adaptateur »** (`ControlHubContractSeveranceService.php` L49-56). Cette story **mirrore tel quel** la valeur `type` stockée en base (donc `'registry'`, pas `'capabilities'`) — c'est la lecture littérale de « clé naturelle miroir ». **Conséquence probable** : si le central valide strictement son enum `ContractItemType`, il **rejettera en 422** tout rapport contenant un item `type='registry'` (« énum hors domaine »). **Ceci est un point de ratification à signaler explicitement au BMAD controlHub** (cf. Dépendances) — pas un bug à corriger dans cette story (remapper `registry→capabilities` à l'émission serait spéculatif tant que le sens exact attendu côté central n'est pas confirmé : mêmes clés ? Toutes les capacités ou seulement celles à mécanisme registry ?).

3. **`UpstreamLockResolver`** (`app/Services/ControlHub/UpstreamLockResolver.php`) est le patron de référence pour la détection d'override, mais il répond à une question différente (« cette `Capability` est-elle verrouillée/permissive amont ? », mémoïsé par requête, court-circuit NFR3) — il **ne** fournit **pas** de méthode publique « override actif pour cette clé d'item ». Réutiliser son **algorithme de normalisation** (`exclusiveKey(hive,path,name) = strtolower(hive).'|'.strtolower(path).'|'.strtolower(name)`, décomposition `hive|path|name` des 3 premiers segments) est **obligatoire** (ne pas réinventer une 3ᵉ normalisation — le commentaire du fichier l'interdit explicitement) ; l'ajout d'une méthode publique dédiée sur `UpstreamLockResolver` (ex. `capabilitiesForRegistryKey()`) est une option propre si le dev la juge plus lisible qu'une réimplémentation locale — au choix, ce n'est pas un fichier figé (NFR-A4 ne protège que `StateCompiler`/`ContractV1`/`agent/**`/golden).

4. **`capability_assignments` n'a pas de modèle Eloquent** — accès `DB::table('capability_assignments')`, colonnes `capability_id, assignable_type, assignable_id, value` (migration `2026_06_18_100200_create_capability_assignments_table.php`). Une override existe ssi une ligne existe pour un `capability_id` donné (n'importe quel `assignable`).

5. **`ControlHubHeartbeatJob` est du code mort** (`app/Jobs/ControlHubHeartbeatJob.php`) : jamais dispatché nulle part dans le code (`grep` vérifié). Le patron réellement en production est `ControlHubHeartbeatCommand` → appel **synchrone** de `ControlHubService::performHeartbeat()`, sans queue. Ne pas reproduire le patron auto-redispatch de `ControlHubHeartbeatJob` (Cache-based self-scheduling) — c'est un vestige non branché.

### Questions ouvertes — positions par défaut (à confirmer avec Henri/dev)

- **Q1 — Mismatch `registry`/`capabilities` (cf. Découverte #2).** Position par défaut : émettre `type` tel que stocké (mirror strict), documenter le risque de 422 côté central, et le signaler explicitement au BMAD controlHub comme point de ratification. Ne PAS remapper spéculativement.
- **Q2 — Statuts `pending`/`error` non calculés en v1.** Position par défaut : tout item `Locked` ou `Permissive`-non-overridé → `applied` (déclaratif : SE5 a résolu l'item dans l'état désiré ; ce n'est PAS une confirmation d'exécution poste par poste, qui n'existe pas au grain item aujourd'hui). Extension future : la Story 39.4 mentionne « échec de checksum = item en `error` (remonté par 39.2) » — cette story **n'implémente pas** ce branchement (39.4 n'existe pas encore) ; elle documente juste que `resolveStatus()` est le point d'extension naturel le jour où une source d'erreur par item existera (ne pas construire l'infrastructure en avance — anti sur-engineering).
- **Q3 — Cadence : commande dédiée à intervalle fixe (PAS de piggyback heartbeat, PAS de colonne BDD de throttle dédiée).** Argumentation : coupler conformité (potentiellement lourd — N items) et heartbeat (léger, liveness) mélangerait deux préoccupations distinctes ; une cadence fixe configurable (`controlHub.compliance.interval`, défaut 15 min) suffit car (a) le central applique de toute façon sa propre garde de fraîcheur `reported_at` (rejeu = no-op), (b) il n'y a pas de besoin de finesse sous-minute comme le heartbeat (qui doit réagir à un `heartbeat_interval` par-connexion). Si cette position est rejetée, l'alternative (gate BDD comme le heartbeat) demande une migration additive sur `controlhub_connection` — pas fait par défaut.

### Existant à réutiliser — NE PAS réécrire

| Brique | Fichier | Rôle pour 39.2 |
|---|---|---|
| Token + renouvellement | `ControlHubService::getToken()` | Fournit le Bearer (renouvellement auto inclus) |
| Client HTTP générique | `ControlHubApiClient::callEndpoint()` | POST direct, aucune modification nécessaire (`buildUrl()` gère déjà `/api/sambaedu/...`) |
| Contrat actif | `ControlHubContract::active()` | Guard NFR-A1 (retourne `null` en standalone ET en `severed`) |
| Items du contrat | `ControlHubContract::items()` / `ControlHubContractItem` | Source des items à rapporter (`type`, `key`, `enforcement_state`, `target_type`, `target_label`) |
| Détection d'override — algorithme | `UpstreamLockResolver::exclusiveKey()`/`normalizeItemKey()` | Normalisation de clé registre à réutiliser à l'identique |
| Override actif — stockage | `capability_assignments` (table, pas de modèle) | Source de vérité d'un override posé |
| Patron Command → Service synchrone | `ControlHubHeartbeatCommand` | Patron de garde + log ; **pas** de queue dans ce patron (ici on en ajoute une, volontairement, cf. AC 7) |
| Patron config additive | `config/controlHub.php` | Ajouter, ne rien renommer/retirer |

### Project Structure Notes

- Service dans `app/Services/ControlHub/` (namespace existant, à côté de `ControlHubService`/`ControlHubApiClient`).
- Job dans `app/Jobs/` (convention existante : `ControlHubHeartbeatJob`, `DispatchMachinePowerActionJob`).
- Command dans `app/Console/Commands/` (convention existante : `ControlHubHeartbeatCommand`).
- Test dans `tests/Feature/ControlHub/` (suite dédiée du domaine, patron `ContractIngestionEndpointTest`/`ContractSeveranceChannelsTest`).
- **Aucune route HTTP nouvelle** : cette story est 100% sortante — pas de risque sur la fenêtre 1500 chars de `routes/api.php` (contrairement à 39.1/39.4).

### References

- [Source: _bmad-output/planning-artifacts/epics-alignement-controlhub-se5.md#Story-39.2] — intention, AC-skeleton, FR-A2, NFR-A1..A5
- [Source: ../irundoo/documentation/SE5-CONTRACT-COMPLIANCE-V1.md] — spec centrale figée (non ratifiée) : enveloppe, clé naturelle, statuts, garde de fraîcheur, sécurité 401→422→403
- [Source: _bmad-output/planning-artifacts/schema-echange-controlhub-se5.md] — schéma des 5 agrégats reçus (source des items à rapporter)
- [Source: app/Services/ControlHub/UpstreamLockResolver.php] — algorithme de normalisation de clé registre, précédence locked>permissive>local
- [Source: app/Services/ControlHub/ControlHubContractSeveranceService.php#L49-56] — découverte du pseudo-canal `capabilities` mort vs canal réel `registry`
- [Source: app/Services/Agent/Reporting/ConformityService.php] — grain d'agrégation existant (type×poste, pas item)
- [Source: app/Console/Commands/ControlHubHeartbeatCommand.php] — patron de garde/log à suivre pour la nouvelle commande

## Dépendances

- **Amont (DONE)** : handshake (`controlhub_connection.api_token`, `ControlHubService::getToken()`) ; résolution d'état contrat amont (Epics 28-31) ; reporting agent existant (Epic 24.x, `ConformityService`/`agent_resource_states` — **grain insuffisant** pour un vrai statut par item, cf. Découvertes) ; mécanisme d'override de capacité (Epic 27/29, `capability_assignments` + `UpstreamLockResolver`).
- **Indépendante, fichiers** : aucun chevauchement avec 39.1 (routes/contrôleur entrants), 39.3 (JWT fédéré) ou 39.4 (schéma+ingestion+pull binaires) — cette story ne touche ni `routes/api.php` ni l'ingestion. **Seul chevauchement possible** : `config/controlHub.php` (39.2 ajoute `endpoints.contract_compliance` + bloc `compliance` ; si 39.4 y touche aussi, vérifier au merge que les deux ajouts additifs coexistent sans écrasement).
- **Coordination R2** : le chemin `POST /api/sambaedu/contract-compliance/{instance}` est **figé côté central mais NON ratifié** (`SE5-CONTRACT-COMPLIANCE-V1.md`, en-tête « PROPOSITION du central, NON ratifiée par SE5 »). **Point de ratification identifié par cette story** : le mismatch `type='registry'` (SE5) vs enum `ContractItemType` central (`capabilities` attendu, `registry` absent de l'enum documenté) — à porter explicitement à la discussion avec le BMAD controlHub avant tout test e2e réel contre le central. Toute divergence constatée à la ratification = story d'alignement (famille OPEN), hors périmètre de 39.2.

## Dev Agent Record

### Agent Model Used

opus (Claude Opus 4.8, 1M) — modèle recommandé par la story (invente une politique de mapping, tranche le mismatch `registry`/`capabilities` sans le corriger à l'aveugle).

### Debug Log References

- `php artisan test --filter=ControlHubComplianceReport` → 16 passed (59 assertions).
- Non-régression `UpstreamLockResolver` (service partagé modifié) : `--filter="PermissiveOverrideResolution|UpstreamLock|UpstreamContractResolution"` → 81 passed (759 assertions).
- NFR-A4 : `git diff --stat -- agent/ app/Services/Agent/StateCompiler.php app/Services/Agent/StateContract.php agent/shared/version.go` → VIDE (zéro diff). Aucun bump de version.
- NFR-A5/R3 : `grep -rin central` sur les fichiers livrés → seul le garde-fou documentaire idiomatique `aucun mot « central »` (identique aux fichiers existants du domaine).

### Completion Notes List

- **Enveloppe conforme (AC1)** : `buildEnvelope(): ?array` produit `schema_version='1.0'`, `instance_id`, `link_state='active'`, `contract_received_at` (nullable), `reported_at` (généré à chaque appel, monotone), `items[]` avec clé naturelle miroir stricte. `target_label` toujours chaîne.
- **Gardes NFR-A1 (AC2)** : pas de contrat actif → `null` / `reason=no_active_contract` ; pas de connexion valide → `no_active_connection` ; pas de token → `no_token`. Les 3 prouvées avec `ApiClient` mocké `shouldNotReceive('callEndpoint')`.
- **Filtre `absent` + `items:[]` (AC3)** : items `Absent` exclus ; `items:[]` est un rapport valide émis.
- **Mapping de statut (AC4)** : `locked`→`applied` ; `permissive`+`registry`+`instance` avec override local (`capability_assignments`) → `overridden` + `detail` lisible (capacité + parc porteur) ; sinon `applied` ; autres `permissive` → `applied` (pas de faux `overridden`). `pending`/`error` non calculés en v1 (extension documentée). `observed_at`=`reported_at`.
- **Détection d'override** : méthode publique `UpstreamLockResolver::capabilitiesForRegistryKey(string): Collection` ajoutée — réutilise `normalizeItemKey()`/`exclusiveKey()`/`specKeys()` existants (AUCUNE 3ᵉ normalisation). Match casse-insensible vérifié en test.
- **Émission HTTPS (AC5)** : `emit()` obtient le token via `ControlHubService::getToken()` (renouvellement auto réutilisé), POST via `ControlHubApiClient::callEndpoint()` (aucune modif du client). Token jamais loggé (test `Log::listen` canary).
- **Config additive (AC6)** : `endpoints.contract_compliance` + bloc `compliance` (enabled/interval) ajoutés SANS toucher aucune clé existante — localisés pour coexister avec 39.4.
- **Service+Job+Command (AC7)** : job fin `tries=3` sur la queue par défaut (worker `laravel-queue-general`) ; command `controlhub:report-compliance` court-circuite avant dispatch (contrat+connexion) + throttle cadence fixe via Cache (pas de colonne BDD, pas de piggyback heartbeat) ; entrée Kernel `everyMinute()->withoutOverlapping()->runInBackground()`. Le patron mort `ControlHubHeartbeatJob` (auto-redispatch) N'est PAS reproduit.
- **Mismatch `registry`/`capabilities` (Q1)** : mirror STRICT du `type` stocké (`'registry'`), risque de 422 côté amont documenté et laissé comme point de RATIFICATION R2 — PAS de remap silencieux.

### File List

**Créés :**
- `app/Services/ControlHub/ControlHubComplianceReportService.php`
- `app/Jobs/ControlHubReportComplianceJob.php`
- `app/Console/Commands/ControlHubReportComplianceCommand.php`
- `tests/Feature/ControlHub/ControlHubComplianceReportTest.php`

**Modifiés :**
- `app/Services/ControlHub/UpstreamLockResolver.php` (ajout méthode publique `capabilitiesForRegistryKey()`)
- `config/controlHub.php` (endpoint `contract_compliance` + bloc `compliance` — additif)
- `app/Console/Kernel.php` (entrée schedule `controlhub:report-compliance`)
- `docs/qa/domains/controlhub-contract.md` (runbook manuel canal ③)

## Recommandation Modèle Dev

**opus** — confirmé par l'exploration du code, et renforcé par elle. Ce n'est pas du câblage pur (contrairement à 39.1) : la story **invente** une politique de mapping de statut qui n'existe nulle part dans le code (aucune agrégation de conformité par item aujourd'hui), doit naviguer une découverte non triviale (le mismatch `registry`/`capabilities`, un vrai risque de ratification à documenter et non à corriger silencieusement), et doit réutiliser fidèlement l'algorithme de normalisation de clé de `UpstreamLockResolver` sans en réinventer un 3ᵉ. Le risque principal est un jugement de conception mal calibré (sur-construire une agrégation par-poste qui n'existe pas ailleurs, ou au contraire sous-documenter le risque de 422 côté central) — exactement le type de décision qu'opus est mieux placé pour trancher et documenter proprement.
