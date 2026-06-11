# Story 23.1: Contrat v1 figé — schémas state & report, StateHasher, golden files

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant que **mainteneur SambaEdu**,
je veux **un contrat JSON `se5.desired-state/v1` versionné, hashé canoniquement et figé par golden files**,
afin que **serveur et agents (présents et futurs) évoluent sans jamais se désynchroniser**.

## Contexte & intention

Première story de l'Epic 23 (« successeur GPO — agent desired-state »). Elle **fige les irréversibles** du contrat HTTP/JSON avant qu'aucun consommateur n'existe : tant que rien n'est déployé, on peut encore décider de la forme de l'enveloppe, de la présence du booléen `mode`, et de l'algorithme de hash ; après, plus jamais (un agent déployé fige le wire format).

C'est une story **100 % serveur, sans endpoint ni base de données** : on produit les **types**, l'**algorithme de hash unique**, les **golden files** normatifs et la **documentation de contrat**. Les consommateurs viennent ensuite :

- le `StateCompiler` + premiers `StateProvider` qui *produisent* l'état → **Story 23.4** (hors-scope ici)
- `GET /api/v1/agent/state` + ETag/304 + `config/agent.php` → **Story 23.5** (hors-scope ici)
- middleware/token → **Story 23.2** (hors-scope ici)
- `POST /api/v1/agent/report` (controller, ingestion, tables) → **Story 24.1** — **seul le *schéma* du rapport + son golden file sont livrés ici** (gap 2 de l'architecture)
- tout code agent côté poste → **Epic 24**

Le golden file `state.v1.json` est, à ce stade, la **sérialisation de référence** : c'est lui qui définit ce que 23.4/23.5 devront produire, pas un `StateCompiler` qui n'existe pas encore.

> Le contrat iso-pattern le **POC overlay** déjà livré (commit `f9b3ad9`) : `app/Services/Overlay/OverlayService.php` + `app/Dto/Overlay/OverlayPayload.php` + `config/overlay.php` (`'schema' => 'se5.wallpaper-overlay/v1'`). On réutilise ce vocabulaire (enveloppe `schema`/`generated_at`/`ttl_seconds`, clés snake_case, structure plate, tableaux d'objets, timestamps UTC ISO 8601), on **ne le copie pas servilement**.

## Acceptance Criteria

### AC1 — Enveloppe & items (FR5)

**Given** le schéma `se5.desired-state/v1`
**When** le serveur sérialise un état cible
**Then** l'enveloppe contient `schema`, `generated_at` (ISO 8601 **avec timezone**), `ttl_seconds`, et les trois portées `machine`, `session`, `machine_user`
**And** chaque item porte exactement `{type, semantics, mode, payload, hash}` avec `mode ∈ {strict, default}` et `semantics ∈ {aggregate, exclusive}`
**And** « tableau vide » (rien à faire pour ce type) **et** « type absent » (type non géré par le serveur) sont **distingués** et **documentés** comme sémantiquement différents.

### AC2 — StateHasher unique & déterministe (FR7)

**Given** deux compilations du même état à des instants différents
**When** `StateHasher` calcule le hash (SHA-256 sur JSON **canonicalisé** : clés triées alphabétiquement et récursivement, UTF-8, sans espaces, champ volatil `generated_at` exclu)
**Then** les hashes sont **identiques** (le seul écart `generated_at` ne change pas le hash)
**And** l'algorithme n'existe qu'en **un seul endroit** (`App\Services\Agent\StateHasher`), destiné à être réutilisé par l'ETag (23.5) et par la comparaison des rapports (24.1)
**And** le hash d'un item est dérivé de son contenu *définissant* (sans inclure sa propre clé `hash`), de sorte que l'agent compare des hashes **opaques** sans jamais les recalculer.

### AC3 — Schéma du rapport (gap 2 — FR8)

**Given** le gap 2 (schéma du `POST /report`)
**When** le contrat v1 est rédigé
**Then** le payload de rapport est spécifié — statut par item (`compliant | drift | drifted_allowed | error`), hash, détail d'erreur, version de l'agent, identité du poste —
**And** son **golden file** (`tests/Fixtures/Agent/report.v1.json`) existe et illustre les quatre statuts.

### AC4 — Sémantique fine du mode `default` (gap 1 — FR19)

**Given** le gap 1 (mode `default`, sabotage le plus dangereux)
**When** le contrat handler est spécifié au document de contrat
**Then** la règle est écrite noir sur blanc : l'agent **persiste le dernier état APPLIQUÉ par item** ; si `réel ≠ cible` **∧** `dernier-appliqué = cible` → **dérive humaine** → l'agent **ne réapplique PAS** et rapporte `drifted_allowed`
**And** le cas `mode=strict` (toute dérive est réappliquée) est documenté en regard.

### AC5 — Golden files & règle d'évolution (FR5, NFR13)

**Given** les tests
**Then** les golden files `tests/Fixtures/Agent/{state,report}.v1.json` existent et sont **consommés par PHPUnit** (structure validée + hash de régression figé)
**And** la règle d'évolution est documentée : **champ ajouté = mineur** (l'agent ignore l'inconnu) ; **retrait / renommage / changement de sémantique = MAJEUR** (l'agent refuse un major inconnu) ; toute évolution du schéma = mise à jour des golden files **+ bump de version explicite**
**And** les identifiants de type de ressource (`wallpaper`, `overlay`, …) sont snake_case et documentés comme **figés une fois publiés** (NFR12 — jamais renommés ; en cas d'erreur : déprécier + ajouter).

### AC6 — Aucune dépendance AD (critère Keycloak, NFR7)

**Then** rien dans le code livré n'appelle l'AD / LdapRecord / Kerberos (trivialement satisfait — types + hash + fixtures purs), **vérifiable en review**.

## Tasks / Subtasks

- [x] **T1 — Enums du contrat** (AC1, AC3)
  - [x] `app/Enums/ResourceSemantics.php` — `enum: string { Aggregate = 'aggregate'; Exclusive = 'exclusive'; }`
  - [x] `app/Enums/StateScope.php` — `Machine = 'machine'; Session = 'session'; MachineUser = 'machine_user';`
  - [x] `app/Enums/StateMode.php` — `Strict = 'strict'; Default = 'default';`
  - [x] `app/Enums/AgentResourceStatus.php` — `Compliant = 'compliant'; Drift = 'drift'; DriftedAllowed = 'drifted_allowed'; Error = 'error';`
  - [x] Suivre le pattern `app/Enums/AppKind.php` (backed enum string, `declare(strict_types=1)`). Méthodes `label()` optionnelles, non requises ici.
- [x] **T2 — Source unique du nom de schéma** (AC1, AC5)
  - [x] `App\Services\Agent\StateContract` (classe finale) : `public const SCHEMA = 'se5.desired-state/v1';` + constantes des clés de portée. **Ne PAS créer `config/agent.php`** (relève de 23.5). Le nom de schéma est figé (NFR12) → constante, pas variable d'env.
- [x] **T3 — `App\Services\Agent\StateHasher`** (AC2)
  - [x] `hashState(array $state): string` — exclut `generated_at`, canonicalise, `hash('sha256', …)`.
  - [x] `hashItem(array $item): string` — exclut la clé `hash` de l'item, canonicalise, `hash('sha256', …)`.
  - [x] `private canonicalize(mixed $value): string` — **ksort récursif** sur tous les tableaux associatifs, puis `json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)` (pas de `JSON_PRETTY_PRINT` → aucun espace). ⚠️ `json_encode` PHP **ne trie pas** les clés : le tri récursif est à implémenter à la main.
- [x] **T4 — Golden file état** `tests/Fixtures/Agent/state.v1.json` (AC1, AC2)
  - [x] Enveloppe complète + 3 portées. Inclure : un item **exclusif** (`wallpaper`) et un item **agrégeable** (`overlay`) en portée `session` ; une portée illustrant un **tableau vide** ; documenter qu'un type **absent** est différent. Chaque item porte ses 5 clés ; la valeur `hash` doit être **cohérente avec `StateHasher::hashItem`** (le test le vérifie — calculer puis figer la valeur).
- [x] **T5 — Golden file rapport** `tests/Fixtures/Agent/report.v1.json` (AC3)
  - [x] Payload de rapport : `schema`, `generated_at`, `agent_version`, identité du poste (ex. `workstation` / hostname), `items: [{type, status, hash, detail?}]` couvrant les 4 statuts (`compliant`, `drift`, `drifted_allowed`, `error` avec `detail` non vide).
- [x] **T6 — Documentation du contrat** `docs/agent/contract-v1.md` (AC1, AC3, AC4, AC5)
  - [x] Enveloppe + item (5 clés) ; tableau-vide vs type-absent ; sémantique `aggregate`/`exclusive` ; `mode strict`/`default` **avec la règle `drifted_allowed` (gap 1) explicitement écrite** ; schéma du rapport ; algorithme de hash (canonicalisation, exclusion `generated_at`, source unique) ; règle d'évolution (mineur/majeur + bump) ; liste des identifiants de type figés (NFR12).
- [x] **T7 — Tests** (AC2, AC5)
  - [x] `tests/Unit/Services/Agent/StateHasherTest.php` : déterminisme (même tableau → même hash) ; **indépendance à l'ordre des clés** ; **`generated_at` exclu** (deux états ne différant que par `generated_at` → même `hashState`) ; canonicalisation unicode/sans-espaces ; `hashItem` ignore la clé `hash`.
  - [x] `tests/Unit/Services/Agent/ContractV1Test.php` : charge les golden files via `base_path('tests/Fixtures/Agent/…')` ; valide les invariants structurels (clés d'enveloppe, 3 portées présentes, chaque item a ses 5 clés, `mode ∈ StateMode`, `semantics ∈ ResourceSemantics`, `status ∈ AgentResourceStatus`) ; assert que `StateHasher::hashState(golden)` **=== valeur figée** (garde-fou de régression sur la canonicalisation) ; assert que pour chaque item `item['hash'] === StateHasher::hashItem(item)`.
- [x] **T8 — Vérifications finales**
  - [x] `php -l` sur tous les fichiers PHP créés (0 erreur).
  - [x] Suite Agent : `php artisan test --filter Agent` → **19 passed (90 assertions)** (dont les 13 nouveaux tests). Exécutée **sur l'hôte** (PHP 8.4.5 + vendor présent) ; la VM `/vm` était injoignable (« No route to host ») au moment du dev — tests purs unitaires, sans DB ni HTTP, identiques host/VM. Les 26 échecs de la suite `Unit` complète sont **pré-existants et environnementaux** (LDAP/AD, imagick/fonts, ext-zip), **aucun n'est un test Agent**.
  - [x] Relecture critère Keycloak : `grep -ri 'ldap\|kerberos\|samba-tool' app/Services/Agent app/Enums` → vide.

## Dev Notes

### Périmètre — ce qui est livré / ce qui ne l'est PAS

| Livré (23.1) | Hors-scope (story) |
|---|---|
| Enums `ResourceSemantics`, `StateScope`, `StateMode`, `AgentResourceStatus` | `StateProvider`/`StateCompiler`, `TargetContext`, providers wallpaper/overlay → **23.4** |
| `StateHasher` (algo unique, testé) | `GET /api/v1/agent/state`, ETag/304, `config/agent.php` → **23.5** |
| `StateContract::SCHEMA` (const figée) | middleware `AuthenticateAgentToken`, colonnes/cycle token → **23.2** |
| Golden files `state.v1.json` + `report.v1.json` | `ReportController`/`ReportIngestService`/tables `agent_*` → **24.1** |
| Doc de contrat `docs/agent/contract-v1.md` | tout code sous `agent/` (handlers, agent Windows) → **Epic 24** |

> **Ne créez pas de DTO `App\Dto\Agent\*`** : l'architecture (arbre de structure) ne prévoit pas de couche DTO pour l'état — le golden file est l'artefact normatif et la sérialisation réelle (tableaux plats) viendra avec le `StateCompiler` en 23.4. Rester minimal.

### Conventions de nommage (architecture — figées, NON négociables)

[Source: architecture-agent-desired-state.md#Naming Patterns]

- Namespace serveur : `App\Services\Agent\` → `App\Services\Agent\StateHasher`, `App\Services\Agent\StateContract`.
- Enums : `app/Enums/` (racine, comme les enums existants — **PAS** `app/Enums/Agent/`).
- Fixtures : `tests/Fixtures/Agent/` (iso `tests/Fixtures/Gpo/`).
- Tests : `tests/Unit/Services/Agent/`.
- **Identifiants de type de ressource** (clé de voûte, partagés serveur/agent/JSON/DB/UI) : snake_case, **figés une fois publiés** — `wallpaper`, `overlay`, `shortcuts`, `printers`, `drives`, `associations`, `registry`, `app_config`, `applications`. Un identifiant publié ne se renomme JAMAIS.

### Forme de l'enveloppe (iso POC overlay)

[Source: app/Dto/Overlay/OverlayPayload.php ; config/overlay.php ; architecture#Format Patterns]

```json
{
  "schema": "se5.desired-state/v1",
  "generated_at": "2026-06-11T08:00:00+00:00",
  "ttl_seconds": 3600,
  "machine":      [ /* items portée machine */ ],
  "session":      [ /* items portée session */ ],
  "machine_user": [ /* items portée machine×user */ ]
}
```

Chaque **item** :

```json
{ "type": "wallpaper", "semantics": "exclusive", "mode": "default",
  "payload": { /* spécifique au type — owné par la story du provider */ },
  "hash": "<sha256 hex de l'item sans sa clé hash>" }
```

- `generated_at` : `Carbon::now('UTC')->toIso8601String()` (iso `OverlayService`).
- **`payload`** : sa sous-structure par type est **owné par la story du provider correspondant** (wallpaper/overlay = 23.4). Ici, payloads **illustratifs** dans le golden file. Ce qui est figé en 23.1 = le **wrapper** (`type/semantics/mode/payload/hash`) et l'enveloppe, pas le détail interne de chaque `payload`. Le document de contrat doit l'expliciter.

### Tableau vide ≠ type absent (AC1 — distinction significative)

- **Portée contenant `"wallpaper": []`** au sein des items → impossible (les items sont une liste, pas une map). La distinction se joue ainsi : **type présent dans la liste avec payload « neutre/aucune règle »** vs **type totalement absent de la liste**. À trancher et documenter dans `contract-v1.md` :
  - *type absent de la liste* = « le serveur ne gère pas / n'a aucune règle pour ce type sur ce poste » → l'agent ne touche pas à cette ressource.
  - *un type qui veut dire explicitement « remettre à l'état neutre / aucune valeur »* doit le porter dans son `payload` (ex. `wallpaper` avec asset null = « pas de fond imposé »), **pas** par omission.
- Documenter clairement ce choix : c'est une **décision de contrat** qui sera consommée par chaque handler. Le but de l'AC est qu'aucune ambiguïté « rien à faire » vs « pas géré » ne subsiste.

### StateHasher — détails d'implémentation (AC2)

[Source: architecture#Format Patterns « Hash d'état » ; usages SHA-256 existants : `SnapshotJob`]

- **Canonicalisation** : tri **récursif** des clés des tableaux associatifs (ne pas trier les listes — l'ordre des items est significatif et fixé par le serveur), puis `json_encode(..., JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`. Pas de `JSON_PRETTY_PRINT`.
- ⚠️ Piège : `json_encode` n'ordonne **pas** les clés → le tri est manuel (fonction récursive `ksort` sur les sous-tableaux associatifs uniquement).
- `hashState` exclut `generated_at` (volatil) **avant** canonicalisation. Si d'autres champs volatils apparaissent plus tard, les exclure ici aussi (single point of truth).
- `hashItem` exclut la clé `hash` de l'item avant hash (sinon dépendance circulaire).
- Retour : SHA-256 hex (`hash('sha256', $canonical)`), opaque pour l'agent. **L'agent ne recalcule jamais** — il compare des chaînes.
- Pas d'injection de dépendances exotiques : classe pure, testable sans base ni HTTP.

### Golden files — emplacement & consommation

[Source: tests/Fixtures/Gpo/* ; `VeyonConfigGeneratorTest` charge via `base_path('tests/Fixtures/...')`]

- Stocker sous `tests/Fixtures/Agent/state.v1.json` et `report.v1.json`.
- Les tests chargent via `base_path('tests/Fixtures/Agent/…')` + `json_decode(file_get_contents(...), true, flags: JSON_THROW_ON_ERROR)`.
- Le `hash` figé dans le golden file = celui produit par `StateHasher` (procédure : écrire l'état sans hash, calculer, coller). Le test `ContractV1Test` reverifie l'égalité → toute dérive de canonicalisation casse le test (garde-fou voulu).

### Règle d'évolution (AC5 — à écrire dans la doc)

- Champ **ajouté** → version **mineure**, l'agent ignore l'inconnu (forward-compat).
- Champ **retiré/renommé** OU **sémantique changée** → version **MAJEURE** (`se5.desired-state/v2`), l'agent **refuse** un major inconnu.
- Toute évolution = **mise à jour des golden files + bump explicite**. (NFR13 : golden files partagés serveur ⇄ agent, tests croisés — l'agent n'existant pas encore, la consommation côté `agent/` est notée pour l'Epic 24.)

### Enforcement & anti-patterns applicables ici

[Source: architecture#Enforcement Guidelines / Anti-patterns]

- ✅ Tout hash d'état passe par `StateHasher` — **jamais** de `md5`/`hash('sha256', …)` ad hoc ailleurs.
- ✅ Identifiant de type publié = figé.
- ❌ Aucune dépendance AD dans le canal agent (critère Keycloak — AC6).
- ❌ Pas de table générique de règles (sans objet ici, mais ne pas en introduire « en avance »).

### Project Structure Notes

- **Racine = projet Laravel** (pas de sous-dossier `laravel/`) — `app/`, `tests/`, `config/`, `docs/` à la racine du repo. [memory `root-is-laravel`]
- Code édité sur l'hôte, **exécuté sur la VM `/vm`** ; sync auto par inotify (CRUD de fichier sous `main`). Ne **jamais** sync manuellement ; si non-synchro → notifier Henri et attendre. [CLAUDE.md]
- `config/agent.php` **n'est pas** créé ici → pas de `config:cache` à relancer pour cette story. (Quand 23.5 le créera, penser à `php artisan config:cache` + `chown www-admin` sur la VM. [memory `vm-config-cache-not-synced`])

### Testing standards

- PHPUnit. Tests unitaires purs (pas de DB, pas de HTTP) → rapides, exécutables même hors VM si vendor présent ; **exécution de référence sur `/vm`** (env hôte sans vendor — cf. memory `epic21-e2e-host-env`).
- Viser : `StateHasherTest` (≥5 cas), `ContractV1Test` (≥1 cas structure state + 1 cas structure report + assertions de hash). 0 erreur `php -l`.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 23.1] — ACs source, gaps 1 & 2.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Format Patterns] — enveloppe, item, hash canonicalisé, règle d'évolution.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Naming Patterns] — namespaces, fixtures, identifiants figés.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Gap Analysis Results] — gap 1 (mode default), gap 2 (schéma report).
- [Source: app/Dto/Overlay/OverlayPayload.php ; app/Services/Overlay/OverlayService.php ; config/overlay.php] — POC overlay (commit f9b3ad9), pattern d'enveloppe à iso-pattern.
- [Source: app/Enums/AppKind.php] — pattern enum backed string SE5.
- [Source: tests/Fixtures/Gpo/ ; tests/Unit/.../VeyonConfigGeneratorTest.php] — pattern golden file + chargement `base_path`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story direct, invoqué par Henri). NB : la story
recommandait `fable` (Fable 5) ; le dev a été lancé directement via
`/bmad-dev-story` avec le modèle de session courant.

### Debug Log References

- Calcul des hashes figés (hôte, PHP 8.4.5 + vendor) :
  - `hashItem` wallpaper = `f65db3c85252d489f7f451f0fc5933ca47aee0f5ecfc88ef2e8bf5ca2d6e2d37`
  - `hashItem` overlay = `fe63c6843f645ac02b9e5bad418d15172c883c0e54ee53cca97924da4cf2519f`
  - `hashItem` shortcuts = `2fb7f510fe56e3b7efc27407bd6af1f275f70376df459b326731ab876058335f`
  - `hashState` (golden state, items patchés) = `6c0e8135118a24538b526ede21e70a08685643d2bd056c6a79010d7cd52496b7` → figé dans `ContractV1Test::FROZEN_STATE_HASH`.
  - Procédure : items écrits sans hash → `hashItem` calculé/collé → `hashState` recalculé sur le fichier complet → figé.
- `php artisan test --filter Agent` → 19 passed (90 assertions), dont les 13 nouveaux tests Agent.

### Completion Notes List

- **Implementation Plan** : 100 % serveur, additif (nouveaux namespaces `App\Services\Agent\`, nouveaux enums racine, fixtures + doc). Aucune DB, aucun endpoint, aucun `config/agent.php` (réservé 23.5). Iso-pattern POC overlay (`f9b3ad9`) pour l'enveloppe.
- **AC1** ✅ Enveloppe `schema`/`generated_at`(+tz)/`ttl_seconds` + 3 portées ; item à 5 clés exactes (`type/semantics/mode/payload/hash`) ; `mode ∈ {strict,default}`, `semantics ∈ {aggregate,exclusive}` ; distinction « tableau vide » vs « type absent » documentée (§8 doc) et illustrée (portée `machine = []`). Test `every_state_item_has_the_five_contract_keys_and_valid_enums`.
- **AC2** ✅ `StateHasher` unique (`App\Services\Agent\StateHasher`) : SHA-256 sur JSON canonicalisé (ksort récursif manuel des maps, listes non triées), `generated_at` exclu (`hashState`), clé `hash` exclue (`hashItem`). Tests : déterminisme, indépendance ordre des clés, exclusion `generated_at`, ordre de liste significatif, `hashItem` ignore `hash`.
- **AC3** ✅ Schéma rapport (gap 2) + golden `report.v1.json` illustrant les 4 statuts (`error` avec `detail` non vide). Test `report_golden_file_has_valid_structure_and_four_statuses` (vérifie que les 4 cases d'enum sont présents).
- **AC4** ✅ Règle `mode=default` / `drifted_allowed` écrite noir sur blanc (§5 doc, pseudo-code des 3 cas), avec `mode=strict` en regard.
- **AC5** ✅ Golden files consommés par PHPUnit (structure + hash de régression figé) ; règle d'évolution mineur/majeur + bump (§9 doc) ; identifiants de type figés snake_case listés (§7 doc).
- **AC6** ✅ `grep -ri 'ldap|kerberos|samba-tool' app/Services/Agent app/Enums` → vide. Classes pures.
- **Note environnement** : VM `/vm` injoignable au moment du dev (No route to host) ; la mémoire `epic21-e2e-host-env` indiquait vendor absent sur l'hôte, mais vendor + PHP 8.4.5 sont désormais présents → tests exécutés sur l'hôte. Tests unitaires purs (sans DB/HTTP) → résultat host ≡ VM. À re-confirmer sur `/vm` quand elle remonte.

### File List

**Créés :**
- `app/Enums/ResourceSemantics.php`
- `app/Enums/StateScope.php`
- `app/Enums/StateMode.php`
- `app/Enums/AgentResourceStatus.php`
- `app/Services/Agent/StateContract.php`
- `app/Services/Agent/StateHasher.php`
- `tests/Fixtures/Agent/state.v1.json`
- `tests/Fixtures/Agent/report.v1.json`
- `tests/Unit/Services/Agent/StateHasherTest.php`
- `tests/Unit/Services/Agent/ContractV1Test.php`
- `docs/agent/contract-v1.md`

**Modifiés :**
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut 23-1 → review)
- `_bmad-output/implementation-artifacts/23-1-contrat-v1-schemas-state-report.md` (cette story)

## Change Log

| Date | Auteur | Changement |
|---|---|---|
| 2026-06-11 | DEV (claude-opus-4-8[1m]) | Implémentation story 23.1 : enums du contrat, `StateContract`, `StateHasher` (hash unique canonicalisé), golden files `state.v1.json`/`report.v1.json`, doc `docs/agent/contract-v1.md`, tests Unit Agent (13 cas, 19 passed). Status ready-for-dev → review. |
| 2026-06-11 | Code review (3 layers : Blind Hunter, Edge Case Hunter, Acceptance Auditor) + correctifs (orchestrateur) | Review : 0 bloquant, hashes figés revérifiés ✅, ACs PASS. Correctifs appliqués : `ksort(…, SORT_STRING)` dans `StateHasher::sortRecursive` (tri lexicographique figé, hash golden inchangé — vérifié) ; doc §4.1 contraintes payloads (pas de floats, `{}`≡`[]` non fiable, NFC obligatoire côté serveur + handlers) ; doc §5 règle du premier passage (pas de `dernier-appliqué` → jamais `drifted_allowed`) ; tests durcis (`array_is_list` sur les 3 portées, `workstation.uuid`). Reporté à 24.1 : validation d'entrée avant hash (JsonException non catchée sur UTF-8 invalide/NAN/INF). Tests sur `/vm` (revenue joignable) : 19 passed (94 assertions). |
