# Story 28.3: Résolution amont > local dans StateCompiler

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **refnum**,
I want **que l'état effectif d'un poste/groupe combine le contrat amont et la configuration locale avec l'amont prioritaire** — via un **tier de précédence « amont »** au-dessus du local, branché dans `StateCompiler::specificity()` (le moteur de résolution existant, on ne le réinvente pas),
so that **ce que l'autorité amont impose prime sur le réglage local de la même clé, tout en laissant le local empiler ses propres réglages sans équivalent amont** (FR2) — et **sans rien changer** au comportement d'une instance qui n'a reçu aucun contrat (NFR3).

> Story **3/3** de l'Epic 28. Elle s'appuie sur le **modèle** livré par la Story **28.1** (`controlhub_contract_*`, `ControlHubContract*`, enums) et sur l'**ingestion** livrée par la Story **28.2** (`ControlHubContractIngestionService`, event `ControlHubContractChanged`, singleton « ≤ 1 contrat actif »). Elle est la **première** story qui **lit** le contrat persisté pour influencer un chemin de code existant : c'est ici, et seulement ici, que `StateCompiler` cesse d'être inerte vis-à-vis du contrat.
>
> Elle livre **uniquement** le **tier de précédence amont > local**. Elle ne livre **PAS** l'enforcement (refus d'édition d'un item verrouillé, override permissif par `workstationGroup`, drift STRICT → **Epic 29**), ni le ciblage par label → `WorkstationGroup` (**Epic 30**), ni le bornage d'install (**Epic 31**), ni la release des verrous (**Epic 32**), ni le schéma versionné (**Epic 33**).

## Acceptance Criteria

1. **Given** un item est imposé par le contrat amont **actif** et un réglage **local** existe pour la **même clé** (même `exclusiveKey()` côté type exclusif, ex. une clé de registre `{hive,path,name}` identique), **When** `StateCompiler` calcule l'état effectif d'un poste, **Then** c'est la **valeur amont** qui figure dans le compilé (l'item amont gagne parce qu'il porte une maille **strictement plus spécifique** que toute maille locale — `User`, `UserGroup`, `Workstation`, `LogicalGroup`, `PhysicalGroup`, `Broadcast`), la précédence étant arbitrée par `StateCompiler::specificity()` **et nulle part ailleurs** (D2 préservé).

2. **Given** des réglages locaux **sans équivalent amont** (clés/types qu'aucun item du contrat n'impose), **When** `StateCompiler` calcule l'état effectif, **Then** ces réglages locaux **restent appliqués** : un item amont **n'efface jamais** un item local distinct (empilement — pour un type `aggregate`, l'item amont **s'ajoute** à l'union ; pour un type `exclusive` par clé, les **clés** non imposées par l'amont conservent leur valeur locale gagnante).

3. **Given** une instance SE5 **sans aucun contrat actif** (aucune ligne `controlhub_contracts` à `link_state = active`, cas standalone dominant), **When** `StateCompiler` calcule l'état effectif, **Then** le résultat est **strictement identique** au comportement actuel sans contrat (NFR3) : **mêmes items, même ordre, même `StateHasher::hashState()`** qu'avant cette story — et le chemin n'ajoute **au plus qu'une** requête bon marché (résolution du contrat actif), idéalement **court-circuitée** quand aucun lien n'est actif (pas de N+1 par provider).

4. **Given** deux compilations du même état (même contrat amont actif, même contexte) à des instants différents, **When** on les hache, **Then** le `StateHasher::hashState()` est **identique** (déterminisme NFR4 / ETag 23.5) : l'ordre de sortie reste figé (types asc, `sourceId` asc intra-aggregate), l'injection des candidats amont est **stable** et n'introduit **aucune** source de non-déterminisme (ordre SQL, ordre d'itération des providers).

5. **Given** le code livré, **When** on l'inspecte, **Then** (a) la précédence amont > local vit **dans `StateCompiler::specificity()`** (ajout d'un rang pour la nouvelle maille amont) — **aucun** provider ne trie/filtre/arbitre par maille (D2 ne fuit pas) ; (b) **aucun** identifiant livré (classe, méthode, propriété, enum, case, event, exception) ne contient le mot **« central »** (garde-fou **R3**) — vocabulaire `Upstream` / `ControlHub*` / « amont » uniquement ; (c) sans contrat actif, **aucun** comportement observable ne change (NFR3, prouvé par AC #3).

6. **Given** un item amont en état **`absent`** (l'autorité amont déclare explicitement « je n'impose pas cette clé »), **When** `StateCompiler` calcule l'état effectif, **Then** cet item **n'est pas injecté** comme candidat gagnant (il ne prime sur rien) ; les items en état **`locked`** et **`permissive`** sont, eux, injectés au tier amont (à ce stade ils priment **tous deux** sur le local — la **relaxation permissive** qui laissera un override `workstationGroup` local reprendre la main est **Epic 29**, hors scope ici ; documenter la couture).

## Tasks / Subtasks

- [x] **Task 1 — Tier de maille « amont » dans l'enum + `specificity()`** (AC: #1, #5a)
  - [x] Ajouter un case `Upstream` à `app/Enums/StateMaille.php` (valeur `string` `'upstream'`). **R3** : `Upstream`, jamais « central ». Mettre à jour la PHPDoc d'en-tête de l'enum (la chaîne de spécificité documentée) pour faire apparaître le tier amont **au-dessus** de `user`. **Ne PAS** ajouter de méthode de rang dans l'enum (le rang vit dans le compilateur SEUL — anti-pattern bloquant sinon).
  - [x] Ajouter le case `StateMaille::Upstream` à `StateCompiler::specificity()` (`app/Services/Agent/StateCompiler.php`, l. 383-393) avec un rang **strictement inférieur** à `User` (le plus spécifique de tous). Deux écritures possibles, au choix du dev : (a) `Upstream => 0` et **décaler** les autres de +1 ; (b) `Upstream => -1` en gardant `User => 0`. L'**invariant** à tenir : `specificity(Upstream) < specificity(User)`. Mettre à jour le commentaire de `specificity()` pour documenter le tier amont (et **pourquoi** il prime — FR2). ⚠️ `specificity()` est l'**unique** `match(StateMaille)` exhaustif du code (les `mailleFor()` des providers utilisent `match($assignableType)` avec `default` → **non** impactés) : c'est donc le seul point qui DOIT gérer le nouveau case (un `match` sans arm lèverait `UnhandledMatchError`).
  - [x] **Garde-fou architecture** : un test (Task 5) doit asserter que **toutes** les valeurs de `StateMaille` ont un rang dans `specificity()` (réflexion sur les cases de l'enum) — empêche un futur ajout de maille de planter en prod.

- [x] **Task 2 — Source des candidats amont (lecture du contrat actif)** (AC: #1, #2, #3, #6)
  - [x] Créer un composant qui, à partir du **contrat actif** (`ControlHubContract::query()->where('link_state', ControlHubLinkState::Active)->first()` — singleton garanti par 28.2), expose les `ControlHubContractItem` **groupés par type de ressource du contrat agent** (= `provider->type()`), prêts à devenir des `StateCandidate` portant `StateMaille::Upstream`. Patron de DI : binding **singleton** dans `AgentServiceProvider` (cf. Task 4), résolution du contrat **mémoïsée pour la durée de la compilation** (pas de re-requête par provider).
  - [x] **NFR3 — court-circuit (CRITIQUE)** : si **aucun** contrat actif n'existe, la source est **vide** et le chemin amont est **inerte** — **au plus une** requête (résolution du contrat actif, qui renvoie `null`), **zéro** candidat injecté, **zéro** N+1 par provider. La requête doit être **partagée** entre tous les providers d'une même compilation (résoudre une fois, réutiliser).
  - [x] **État d'enforcement (AC #6)** : n'injecter que les items `locked` **et** `permissive` (les deux priment sur le local à ce stade) ; **exclure** les items `absent` (non imposés). Documenter en PHPDoc que la **relaxation permissive** (un override `workstationGroup` local battant un item `permissive`) relève d'**Epic 29** — à ce stade `permissive` se comporte comme `locked` vis-à-vis du local.
  - [x] **Ciblage (bornage de scope)** : en 28.3, ne traiter que les items dont la **cible est l'instance** (`target_type = instance`). Les items ciblant un **label** (`target_type = label`) sont **différés Epic 30** (mapping label → `WorkstationGroup`) : les **ignorer proprement** ici (ne pas tenter de résoudre un label, ne pas planter). Documenter ce filtre.
  - [x] **Représentation du payload (POINT D'ARCHITECTURE — voir Dev Notes § « Décision d'architecture à trancher »)** : transformer un `ControlHubContractItem` `{type, key, value, …}` en un payload de candidat **compatible avec l'`exclusiveKey()` du provider cible** (pour les types exclusifs par clé) ou avec le contenu dédupliqué (pour les types aggregate). C'est le **cœur subtil** de la story : suivre la direction recommandée (injection **type-agnostique** + adaptateur minimal démontré sur un type représentatif) et **ne pas sur-spécifier** l'expansion par type au-delà de ce qu'exigent les AC.

- [x] **Task 3 — Injection des candidats amont dans le moteur (décorateur de provider)** (AC: #1, #2, #4)
  - [x] Implémenter l'injection via un **décorateur** `UpstreamAwareProvider` (nom indicatif) qui **enrobe** chaque `StateProvider` enregistré : il **délègue** `type()`, `scope()`, `semantics()` (et `exclusiveKey()` si le provider enrobé implémente `KeyedExclusiveProvider` — relayer l'interface pour préserver la sélection par clé) au provider interne, et son `itemsFor(ctx)` retourne **`candidats_internes ∪ candidats_amont`** où les candidats amont sont ceux de la source (Task 2) dont le type mappé == `inner->type()`, étiquetés `StateMaille::Upstream`. **Aucune** précédence/tri/dédup dans le décorateur (D2 reste au compilateur — le décorateur n'est qu'une **source supplémentaire de candidats bruts**, exactement comme la double source Broadcast/maille de `AbstractCapabilityStateProvider`).
  - [x] **Préserver `KeyedExclusiveProvider`** : si le provider interne l'implémente, le décorateur DOIT aussi l'exposer (sinon `StateCompiler::selectExclusive()` retombe sur « un seul gagnant pour tout le type » et casse le registry/associations). Vérifier ce point explicitement (test dédié).
  - [x] **NE PAS** modifier la logique de sélection de `StateCompiler` (ni `selectExclusive`, ni `selectAggregate`, ni `resolveExclusiveWinner`) : le seul changement dans le compilateur est l'**ajout du rang** dans `specificity()` (Task 1). Tout le reste passe par l'**injection de candidats** + la spécificité — c'est la preuve qu'on **réutilise** le moteur et qu'on ne le réinvente pas (exigence explicite de l'epic).

- [x] **Task 4 — Câblage du registry / `AgentServiceProvider`** (AC: #3, #5)
  - [x] Dans `app/Providers/AgentServiceProvider.php`, **enrober** chaque provider du tableau injecté au `StateCompiler` par le décorateur Task 3 (ex. via un `array_map` qui wrappe chaque `$app->make(XxxProvider::class)`), en injectant la source amont partagée (Task 2). Conserver l'ordre et la liste des providers (zéro provider retiré/ajouté). **NFR3** : le décorateur ne doit **rien** changer tant qu'aucun contrat n'est actif (source vide → pass-through strict).
  - [x] Binding **singleton** de la source amont (Task 2). S'assurer que la résolution du contrat est **paresseuse** (au premier `itemsFor`, pas au boot) pour ne pas requêter la DB à chaque résolution de conteneur (tests, requêtes sans agent…).
  - [x] (Optionnel, à évaluer) S'**abonner** à l'event `App\Events\ControlHubContractChanged` (28.2) pour invalider un éventuel cache de la source. **Par défaut, NE PAS** ajouter de cache en 28.3 (résolution directe par requête, plus simple et déterministe) ; n'introduire un listener que si un cache est réellement ajouté — sinon laisser l'event **sans listener** (cohérent avec 28.2, NFR3). Documenter le choix.

- [x] **Task 5 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#6)
  - [x] **Étendre** `tests/Unit/Services/Agent/StateCompilerTest.php` (patron `fakeProvider` / `keyedExclusiveProvider` + helpers déjà présents) et/ou créer un test ciblé `tests/Feature/ControlHub/UpstreamContractResolutionTest.php` pour le câblage bout-en-bout (contrat persisté via factories 28.1 → providers décorés → compilé).
  - [x] `test_upstream_beats_local_same_key` (AC #1) : un item amont `locked` ciblant l'instance + un candidat **local** de même `exclusiveKey` → le compilé porte la **valeur amont**. (S'appuyer sur le type **`registry`** = exclusive par clé, exemple le plus naturel de « même clé ».)
  - [x] `test_local_without_upstream_equivalent_survives` (AC #2) : items locaux sur des clés/types **non imposés** → présents dans le compilé ; un type **aggregate** (ex. `shortcuts`) → l'item amont **s'ajoute** à l'union sans effacer les locaux.
  - [x] `test_no_active_contract_output_is_byte_identical` (AC #3, #5c — **test révélateur NFR3**) : compiler un contexte **avec** les providers décorés mais **sans** contrat actif, et comparer **item par item + `hashState()`** au compilé des **mêmes providers non décorés** (ou à un golden capturé avant). Doit être **strictement identique**. Asserter aussi le **nombre de requêtes** (`DB::enableQueryLog()` ou `assertQueryCount`-like) : au plus 1 requête « contrat actif » qui renvoie `null`, **pas** de N+1 par provider.
  - [x] `test_deterministic_hash_with_active_contract` (AC #4) : deux compilations à instants différents (via `travel()`) avec contrat actif → **même** `hashState()` (seul `generated_at` volatil).
  - [x] `test_specificity_covers_all_mailles` (Task 1 garde-fou) : réflexion sur `StateMaille::cases()` → chaque maille a un rang dans `specificity()` (pas d'`UnhandledMatchError`), et `specificity(Upstream)` est **le minimum** (plus spécifique que `User`).
  - [x] `test_keyed_exclusive_marker_preserved_through_decorator` (Task 3) : un provider interne `KeyedExclusiveProvider` enrobé reste vu comme tel par le compilateur (clés distinctes s'accumulent, pas « un seul gagnant pour le type »).
  - [x] `test_absent_item_not_injected` + `test_permissive_and_locked_both_win_over_local` (AC #6).
  - [x] `test_r3_no_central_identifier` (AC #5b) : introspection — aucun identifiant livré (FQCN/méthodes/propriétés/case d'enum) ne contient « central » (réutiliser le patron du test R3 de 28.2 ; scanner les **identifiants**, pas le contenu des commentaires garde-fou).
  - [x] **Non-régression** : relancer **toute** `StateCompilerTest` existante → **verte inchangée** (preuve que le tier amont ne perturbe pas la résolution locale). Relancer `--filter ControlHubContract` (48 tests de 28.1/28.2 verts).
  - [x] **Pièges SQLite** : pas de test de longueur varchar ; mesurer l'idempotence/déterminisme par **comparaison d'items + hash + comptage de requêtes**, jamais par contrainte de chaîne.

- [x] **Task 6 — Runbook QA (append-only)** (observabilité)
  - [x] **Enrichir** `docs/qa/domains/controlhub-contract.md` d'une **nouvelle section append-only** (Story 28.3 — résolution amont > local) : scénarios de vérification (amont prime sur local, empilement local, standalone byte-identique, déterminisme du hash). **NE PAS** réécrire les sections existantes. (Contenu rédigé par le **dev-agent**, pas dans cette story.)

- [x] **Task 7 — Validation finale**
  - [x] `php artisan test --filter StateCompiler` (HÔTE) → vert (existant + nouveaux).
  - [x] `php artisan test --filter UpstreamContractResolution` (si fichier Feature créé) → vert.
  - [x] `php artisan test --filter ControlHubContract` → 48 verts (non-régression 28.1/28.2).
  - [x] Grep : aucun identifiant livré ne contient « central » (R3) ; le seul changement dans `StateCompiler` est l'ajout du rang `Upstream` dans `specificity()` (D2 non dispersé).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 28.3

**DANS** : (1) une **maille `StateMaille::Upstream`** + son **rang** dans `StateCompiler::specificity()` (plus spécifique que `User`) ; (2) une **source** qui lit le **contrat actif** (28.2) et expose ses items `instance` `locked|permissive` groupés par type de ressource ; (3) un **décorateur de provider** qui injecte ces items comme **candidats bruts** étiquetés `Upstream` (réutilise toute la sélection D2 existante) ; (4) le **câblage** dans `AgentServiceProvider` ; (5) les **tests HÔTE** (amont prime, empilement local, standalone byte-identique, déterminisme, R3) ; (6) l'enrichissement **append-only** du runbook QA.

**HORS** (ne pas déborder) :
- **Enforcement / refus d'édition** d'un item verrouillé (UI + service + Gate), **drift STRICT** sur item verrouillé, **lisibilité refnum** (badges imposé/verrouillé/permissif) → **Epic 29** (FR3, FR8, NFR1, NFR2, NFR5). 28.3 ne **bloque** aucune édition : elle ne fait que **résoudre** la précédence dans le compilé. Un refnum peut toujours modifier sa config locale ; simplement, l'amont **gagne au compilé**.
- **Override permissif par `workstationGroup`** (un item `permissive` qu'un override local bat sur un groupe précis) → **Epic 29** (FR4). En 28.3, `permissive` se comporte **comme `locked`** vis-à-vis du local (les deux priment). La **relaxation** viendra en injectant `permissive` à un rang battable par un override WG, ou via un mécanisme dédié — **décision d'Epic 29**. Documenter la couture (PHPDoc de la source Task 2), ne pas l'implémenter.
- **Ciblage par label → `WorkstationGroup`** (`target_type = label`) → **Epic 30** (FR9–FR12). En 28.3, on ne traite **que** `target_type = instance` ; les items `label` sont **ignorés proprement**.
- **Bornage du catalogue / déclenchement d'install** → **Epic 31** (FR5, FR6).
- **Release des verrous à la rupture du lien** (`severed`) → **Epic 32** (FR7). En 28.3, on lit le contrat **actif** ; un contrat `severed` n'a pas de candidat à injecter (il n'est pas `active`) — comportement naturel, rien à coder de spécifique.
- **Schéma d'échange versionné** controlHub↔SE5 + **représentation canonique du payload** → **Epic 33**. Voir la décision d'architecture ci-dessous : 28.3 introduit **le minimum** de bridge payload nécessaire à ses AC, et **documente** la couture sans figer un format d'échange.

### Décision d'architecture à trancher (exposée pour le dev + validation Henri)

Le cœur de 28.3 est : **comment faire qu'un item du contrat amont gagne au `StateCompiler`**. Deux approches macro :

- **(a) Tier de maille `Upstream` + injection de candidats** *(RECOMMANDÉE)* — on ajoute `StateMaille::Upstream` (plus spécifique que `User`) à `specificity()`, et on **injecte** les items du contrat comme `StateCandidate(maille: Upstream, …)` via un **décorateur** enrobant chaque provider. **Avantage décisif** : on **réutilise** intégralement la machinerie existante (`selectExclusive` par `exclusiveKey`, `selectAggregate` + dédup, récence, déterminisme, hash) — exactement l'exigence de l'epic « **Réutiliser `specificity()` — ne pas réinventer** ». Le seul changement dans le compilateur est **une ligne** dans `specificity()`. NFR3 est trivial à garantir (source vide ⇒ décorateur pass-through). C'est la déclinaison directe du patron « double source de candidats » déjà éprouvé dans `AbstractCapabilityStateProvider` (Broadcast + maille).
- **(b) Étape de résolution amont distincte par-dessus la sortie locale** — on compile le local, puis on applique un **post-traitement** qui écrase/ajoute les items amont. **Rejetée** : c'est une **seconde** logique de précédence **en dehors** de `specificity()` (viole l'exigence epic), elle duplique la sélection exclusive/aggregate, et elle est plus fragile pour le déterminisme du hash. À ne retenir que si (a) se révèle infaisable — ce qui n'est pas le cas.

**→ Recommandation : approche (a).** Laisser au dev le détail (forme exacte du décorateur, du binding, du groupage par type), mais tenir les **invariants** : D2 dans `specificity()` seul ; NFR3 byte-identique sans contrat ; déterminisme du hash ; R3.

**POINT OUVERT à valider (Henri) — représentation du payload amont.** Le modèle 28.1 stocke un item comme `{type (string), key (string), value (text nullable), enforcement_state, target_type, target_label}` : `value` est un **scalaire texte**, pas un payload structuré. Or, pour qu'un item amont **entre en concurrence** avec un candidat local sur un type **exclusif par clé** (ex. `registry`, dont `exclusiveKey()` lit `payload['hive'|'path'|'name']`), le candidat amont doit produire un **payload de la même forme** que le provider cible. Il y a donc un **écart de représentation** entre ce que 28.1 stocke et ce que le moteur attend. Deux directions :
- **(i)** 28.3 introduit un **adaptateur minimal, type-agnostique** : convention `key` → champs identifiants + `value` → valeur, **démontrée sur `registry`** (et un type aggregate comme `shortcuts`), extensible plus tard. Borné, suffit aux AC. *(direction proposée)*
- **(ii)** On considère que le contrat **doit** porter un payload **structuré** (JSON) — ce qui implique une **évolution de schéma** (28.1) et/ou une **formalisation Epic 33**. Plus lourd ; à ne pas embarquer dans 28.3.

→ **Proposition** : retenir **(i)** pour 28.3 (injection type-agnostique + bridge minimal sur 1–2 types représentatifs), et **déférer** la représentation canonique du payload à **Epic 33**. **À confirmer par Henri** : est-ce acceptable que 28.3 ne couvre l'« amont prime » que sur les types démontrés (registry + un aggregate), le reste suivant quand le schéma d'échange sera figé ? (cf. epic : « définit unilatéralement le format d'ingestion » + Epic 33 le durcit.)

> **🔒 DÉCISION HENRI (2026-06-26) — approche (i) RETENUE.** 28.3 livre un **adaptateur minimal type-agnostique**, démontré sur **`registry`** (exclusive par clé) **et un type aggregate** (`shortcuts`). L'« amont prime » n'est couvert QUE sur les types démontrés ; l'**expansion par-type complète** et la **représentation canonique** du payload sont **déférées à Epic 33** (schéma d'échange figé). **PAS** d'évolution de schéma 28.1, **PAS** de migration. Le dev-agent NE doit PAS re-délibérer (i) vs (ii) : (i) est tranché. Garder le bridge **extensible** (ajouter un type plus tard = enregistrer un mapping, pas refondre) et **documenter** la couture des types non encore démontrés.

### Comment le moteur existant fonctionne (ancrage exact — à NE PAS réinventer)

- `StateCompiler::compile(TargetContext)` (`app/Services/Agent/StateCompiler.php`) itère les providers **triés par `type()` asc** (`sortedProviders()`), appelle `compileProvider()` qui récupère `provider->itemsFor($ctx)` (une `Collection<StateCandidate>`), puis :
  - type **`Exclusive`** → `selectExclusive()` : si le provider est `KeyedExclusiveProvider`, **groupe par `exclusiveKey(payload)`** et élit le gagnant **par clé** ; sinon « un seul gagnant pour tout le type ». Le gagnant est la **maille la plus spécifique** (`resolveExclusiveWinner` → `min(specificity)`), récence intra-maille en départage.
  - type **`Aggregate`** → `selectAggregate()` : **union** de toutes les mailles, **dédupliquée par contenu** (`contentKey` = hash du payload), ordre stable `sourceId` asc.
- `specificity(StateMaille): int` (l. 383-393) = **rang**, 0 = plus spécifique, **`min` gagne**. C'est le **SEUL** porteur de D2. **C'est ICI** qu'on insère le tier amont (plus spécifique que `User`).
- `StateCandidate` (`app/Services/Agent/StateCandidate.php`) : `readonly` `{maille, payload, updatedAt, sourceId, depth?}`. Un candidat amont portera `maille: StateMaille::Upstream`, `payload: <forme du provider>`, `sourceId: <id de l'item contrat>` (stable → déterminisme), `updatedAt: <item->updated_at>`.
- **Déterminisme (NFR4 / ETag 23.5)** : ordre de sortie figé (types asc, `sourceId` asc), tiebreak `id` desc, seul `generated_at` volatil. Toute injection amont doit être **stable** : trier/ordonner les items amont par un id stable, ne jamais dépendre de l'ordre SQL.
- **Patron « double source » déjà éprouvé** : `AbstractCapabilityStateProvider::itemsFor()` émet DEUX lots de candidats (Broadcast `default_value` + overrides par maille) **dans le même provider**, et laisse le compilateur arbitrer. Le décorateur amont est le **même patron**, généralisé : `candidats_internes ∪ candidats_amont(Upstream)`.

### Code livré par 28.1 / 28.2 (noms à réutiliser tels quels)

- **Modèle racine** : `App\Models\ControlHubContract` — `hasMany` `items()`, `labels()`, `imposedGroups()`, `catalogApps()` ; cast `link_state → ControlHubLinkState`. **Singleton** « ≤ 1 actif » garanti par 28.2 (`ControlHubContractIngestionService::resolveActiveContract`).
- **Item** : `App\Models\ControlHubContractItem` — `{type (string libre), key (string), value (text?), enforcement_state, target_type, target_label}` ; casts `enforcement_state → ControlHubEnforcementState`, `target_type → ControlHubContractTarget`. `target_label` est **`NOT NULL DEFAULT ''`** (28.1 review) ; `''` pour le cas `instance`.
- **Enums** (`app/Enums/`) : `ControlHubLinkState` (`Active`, `Severed`), `ControlHubEnforcementState` (`Locked`, `Permissive`, `Absent`), `ControlHubContractTarget` (`Instance`, `Label`), `ControlHubLabelMode` (`Free`, `Reserved`).
- **Event** : `App\Events\ControlHubContractChanged` (28.2) — émis 1× sur mutation, **after-commit**. **Sans listener** aujourd'hui. 28.3 peut s'y abonner **uniquement** si un cache est introduit (déconseillé par défaut, cf. Task 4).
- **Factories** : `database/factories/ControlHubContract*Factory.php` (états `active`, `severed`, `permissive`, `absent`, `forLabel`, `reserved`, `withLabel`…) — pour préparer l'état initial des tests.
- ⚠️ **Distinguer** : `App\Services\Agent\StateContract` = contrat *desired-state agent* (homonymie « contract », domaine différent — **ne pas** y toucher). `App\Models\ControlHubConnection` = **lien/transport** (≠ contrat). `App\Models\ControlHubTask` = tâches (≠ contrat).
- **Mapping type** : le `type` d'item amont est un **vocabulaire libre** (applications, wallpapers, capabilities, shortcuts, agent_tools…) — il peut **ne pas** coïncider exactement avec les `StateContract::RESOURCE_TYPES` (`wallpaper`, `registry`, `shortcuts`, `applications`…) ni avec `provider->type()` (ex. amont « capabilities » → provider `registry`). Le composant Task 2 porte ce **mapping type-amont → type-provider** ; le garder explicite et testé. (Pour le scope démontré, aligner sur le `type()` du/des providers ciblés.)

### Garde-fous projet CRITIQUES (contraintes de la story)

- **R3 — Vocabulaire (BLOQUANT)** : **INTERDIT** — le mot `central` dans tout identifiant (classe, méthode, propriété, enum, **case**, event, exception) ou commentaire d'identifiant. Vocabulaire : `Upstream` / `ControlHub*` / `authority` / « amont ». La nouvelle maille est `StateMaille::Upstream` (valeur `'upstream'`). [mémoires `project_contrat_manage_se5_upstream`, `legacy_central_vs_local_split` ; prd#R3]
- **NFR3 — Standalone préservé (CŒUR DE LA STORY)** : sans contrat actif, le compilé est **byte-identique** à aujourd'hui (mêmes items, même ordre, même hash) et **au plus 1** requête (le « contrat actif ? » qui renvoie `null`), **court-circuitée** si possible. Le décorateur DOIT être un **pass-through strict** quand la source est vide. Test révélateur obligatoire (Task 5). [prd#NFR3 ; epics#Story 28.3 AC2]
- **NFR4 — Déterminisme / idempotence du compilé** : injection stable, ordre figé, hash inchangé entre deux compilations identiques. Ne jamais dépendre de l'ordre SQL ni de l'ordre d'itération des providers. [StateCompiler PHPDoc ; story 23.5 ETag]
- **D2 ne fuit pas** : la précédence amont > local vit **dans `specificity()` seul**. Le décorateur/la source **n'arbitrent rien** — ils ne font qu'émettre des candidats **bruts** étiquetés `Upstream`. Un provider/décorateur qui trie/filtre/élit par maille = **violation bloquante** (Enforcement Guidelines). [StateCompiler PHPDoc l. 18-26 ; StateProvider PHPDoc]
- **Réutiliser `specificity()` — ne pas réinventer** : exigence explicite de l'epic (Additional Requirements). Le compilateur ne gagne **aucune** nouvelle logique de sélection ; uniquement un **rang** supplémentaire. [epics#Additional Requirements]
- **Racine = projet Laravel** (artisan/app à la racine ; pas de préfixe `laravel/`). [mémoire `root_is_laravel`]

### Project Structure Notes

- **Modifiés** (chemins existants — assumés et bornés) : `app/Enums/StateMaille.php` (case `Upstream` + PHPDoc), `app/Services/Agent/StateCompiler.php` (case dans `specificity()` **uniquement**), `app/Providers/AgentServiceProvider.php` (enrobage des providers + binding de la source).
- **Nouveaux** : le décorateur `UpstreamAwareProvider` (sous `app/Services/Agent/...` ou `app/Services/ControlHub/...` — choix dev, cohérent avec le domaine), la source de candidats amont (Task 2), les tests (`StateCompilerTest` étendu et/ou `tests/Feature/ControlHub/UpstreamContractResolutionTest.php`).
- **Aucune migration** attendue (le schéma vient de 28.1). Si la décision (ii) du point ouvert était retenue (payload structuré), elle impliquerait une migration — **hors scope 28.3** par défaut.
- **Aucune** route/controller/vue/Gate touché (28.3 est purement moteur de résolution). L'enforcement UI/Gate est Epic 29.

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 28.3] — AC d'origine (amont prime, empilement local, standalone inchangé), lignes 142-157.
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Additional Requirements] — « Réutiliser `StateCompiler::specificity()` — ne pas réinventer » ; garde-fou R3 « aucun central ».
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#§5.1, #§5.2, #§8 (Décision A)] — tier de résolution amont, état 3 positions, granularité par item, réutilisation `specificity()`.
- [Source: _bmad-output/planning-artifacts/prd-contrat-manage-se5.md#NFR3, #NFR4, #R3] — standalone, déterminisme, vocabulaire.
- [Source: app/Services/Agent/StateCompiler.php#specificity, #compileProvider, #selectExclusive, #selectAggregate] — moteur D2, point d'insertion du tier amont.
- [Source: app/Enums/StateMaille.php] — enum à étendre (case `Upstream`).
- [Source: app/Services/Agent/Contracts/StateProvider.php, #KeyedExclusiveProvider.php] — interface provider + marqueur clé à relayer dans le décorateur.
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php] — patron « double source de candidats bruts » (Broadcast + maille) à généraliser pour l'amont.
- [Source: app/Providers/AgentServiceProvider.php] — registry des providers injecté au StateCompiler (point de câblage / enrobage).
- [Source: app/Models/ControlHubContract.php, #ControlHubContractItem.php] — modèle du contrat persisté (28.1) ; relations + casts d'enum.
- [Source: app/Services/ControlHub/ControlHubContractIngestionService.php, #app/Events/ControlHubContractChanged.php] — singleton « contrat actif » + event de changement (28.2).
- [Source: tests/Unit/Services/Agent/StateCompilerTest.php] — patron de test (`fakeProvider`, `keyedExclusiveProvider`, capture des warnings, déterminisme).
- [Source: _bmad-output/implementation-artifacts/28-1-modele-et-persistance-du-contrat-amont.md, #28-2-reception-idempotente-contrat-amont.md] — fondations, clés naturelles, singleton, normalisation.

## Dépendances

- **Amont (bloquantes)** :
  - **28.1** (`review`) — **LIVRÉE** : modèle `controlhub_contract_*`, `ControlHubContract*`, enums, factories. Statut `review`, mais **le code est committé sur `main`** (sprint-status) → exploitable pour le build de 28.3.
  - **28.2** (`review`) — **LIVRÉE** : `ControlHubContractIngestionService`, singleton « ≤ 1 contrat actif » (`link_state = active`), event `ControlHubContractChanged`. Statut `review` ; code stagé/sur `main`. 28.3 **lit** le contrat actif que 28.2 persiste.
  - > Les deux dépendances sont en statut `review` (non encore `done`) mais **fonctionnellement présentes** dans le code — le build de 28.3 n'est pas bloqué. Si un correctif de review 28.1/28.2 changeait une clé naturelle ou le nom du singleton, re-vérifier l'ancrage.
- **Aval (dépendent de 28.3)** :
  - **Epic 29** (enforcement verrou/permissif, drift STRICT, gates scopés, lisibilité refnum) : consomme le tier amont et la couture `permissive` documentée ici.
  - **Epic 30** (labels & mapping `target_type = label`) : étend l'injection au ciblage par label.
  - **Epic 31/32/33** : install bornée, release des verrous, schéma versionné (et représentation canonique du payload).

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), **jamais la VM** (VM sans `pdo_sqlite`). [mémoire `phpunit_test_env_host_vs_vm`]
- `DB_CONNECTION=sqlite` (cf. `phpunit.xml`), trait `RefreshDatabase`.
- Filtres ciblés : `php artisan test --filter StateCompiler` (existant + nouveaux) ; `--filter UpstreamContractResolution` (Feature, si créé) ; non-régression `--filter ControlHubContract` (48 verts).
- **Couverture** : amont prime sur local pour même clé (registry, exclusive par clé) ; empilement local sans équivalent amont (aggregate `shortcuts`) ; **standalone byte-identique** (items + `hashState()` identiques **et** ≤ 1 requête, court-circuit) ; déterminisme du hash avec contrat actif ; garde-fou « toutes les mailles ont un rang » ; marqueur `KeyedExclusiveProvider` préservé au travers du décorateur ; `absent` non injecté / `permissive`+`locked` priment ; R3 (aucun « central »).
- **Test révélateur NFR3** : la comparaison « providers décorés sans contrat » vs « providers non décorés » DOIT être byte-identique — il échouerait si le décorateur introduisait le moindre écart (item fantôme, réordonnancement, requête N+1).
- **Pièges SQLite** : pas de longueur varchar ; mesurer par comparaison d'items + hash + comptage de requêtes, jamais par contrainte de chaîne. [mémoire `sqlite_tests_no_varchar_enforcement`]
- ⚠️ **VM** : migrations **pas auto-jouées** par le dev-cycle (migre SQLite uniquement). [mémoire `vm_migrations_not_auto_applied`]
- ⚠️ **VM PHPUnit** : run massif = faux échecs ; valider par **filtres ciblés**. [mémoire `vm_phpunit_bulk_run_false_failures`]

## Recommandation Modèle Dev

**`opus`.**

Justification : story au **cœur du moteur de résolution** (`StateCompiler`), avec une **logique subtile** et trois garde-fous transverses à tenir simultanément :
1. **D2 ne doit pas fuir** : toute la précédence reste dans `specificity()` ; le décorateur n'est qu'une source de candidats bruts. Un dev pressé serait tenté de trier/filtrer dans le décorateur (post-traitement « amont écrase local ») — exactement l'anti-pattern bloquant et l'approche (b) rejetée. Distinguer « ajouter un rang » de « réinventer la sélection » demande un raisonnement architectural précis.
2. **NFR3 byte-identique** : prouver que le chemin décoré sans contrat est **strictement** inchangé (items + ordre + hash + comptage de requêtes) est un raisonnement de non-régression fin (court-circuit, pass-through, pas de N+1).
3. **Déterminisme du hash / ETag** : l'injection amont doit être stable (id stable, ordre figé) sous peine de casser le cache 304 agent — piège silencieux.
4. **Point d'architecture ouvert** (représentation du payload + mapping type-amont → type-provider + couture `permissive`/`absent`/`label`) : exige du jugement pour **borner** le scope sans casser l'extensibilité.

Le dev-cycle routera la **review vers le modèle opposé** (sonnet/fable) ; placer **opus** sur l'implémentation met le raisonnement là où le risque d'effet de bord (régression standalone, fuite D2, non-déterminisme) est le plus élevé. (Cohérent avec 28.2, story à idempotence subtile, également développée en opus.)

## Dev Agent Record

### Agent Model Used

opus `claude-opus-4-8[1m]` (dev-story BMAD)

### Debug Log References

- `php artisan test --filter UpstreamContractResolution` → **11/11** (98 assertions).
- `php artisan test --filter StateCompiler` → **27/29** ; les **2 échecs sont PRÉEXISTANTS** (sync AD : `ldap_search(): Can't contact LDAP server` levé à `WorkstationGroup::factory()->create()` via `WorkstationGroupObserver`, dans deux tests qui n'appellent pas `disableSync()` — `target_context_resolves_memberships_from_postgres_relations`, `full_compile_with_real_providers_and_a_fake_one_respects_contract_invariants`). Hors périmètre 28.3 (aucun code amont exécuté avant la création du WorkstationGroup) ; documentés dans sprint-status (« StateCompiler 2 échecs PRÉEXISTANTS sync AD »). Les **2 nouveaux** tests 28.3 (`specificity_covers_all_mailles_and_upstream_is_the_minimum`, `keyed_exclusive_marker_preserved_through_decorator`) passent.
- `php artisan test --filter ControlHubContract` → **48/48** (246 assertions, non-régression 28.1 + 28.2).
- Environnement worktree : `vendor/` absent → `composer install` local + `.env` copié du repo principal (CACHE_DRIVER=file) — ne touche pas la VM (lecture/tests HÔTE uniquement).

### Completion Notes List

- **Approche (i) verrouillée Henri** appliquée sans re-délibération : adaptateur minimal type-agnostique, démontré sur `registry` (exclusive par clé) **et** `shortcuts` (aggregate). Pas de migration, pas d'évolution du schéma 28.1.
- **D2 confiné** : le seul changement dans `StateCompiler` est `StateMaille::Upstream => -1` dans `specificity()` (rang strictement < `User`). `selectExclusive`/`selectAggregate`/`resolveExclusiveWinner` **intacts**. Le décorateur et la source n'arbitrent rien (candidats bruts).
- **NFR3 byte-identique PROUVÉ** : test révélateur `no_active_contract_output_is_byte_identical_and_single_cheap_query` compare item-par-item + `hashState()` (décoré sans contrat vs non décoré) et asserte **exactement 1** requête sur `controlhub_contracts`, **0** sur `controlhub_contract_items` (court-circuit + résolution partagée/mémoïsée, pas de N+1).
- **KeyedExclusiveProvider préservé** : `UpstreamAwareProvider::wrap()` instancie `KeyedUpstreamAwareProvider` (relais `exclusiveKey()`) quand l'interne porte le marqueur ; tests `keyed_exclusive_marker_preserved_through_decorator` + `container_wires_every_provider_through_the_decorator`.
- **Déterminisme** : candidats amont ordonnés par `id` stable (`orderBy('id')`), `sourceId = item.id` ; test `deterministic_hash_with_active_contract` (via `travel()`).
- **Scope borné** : seuls `target_type = instance`, états `locked` + `permissive` (priment tous deux) ; `absent` exclu ; `label` ignoré proprement (test) ; `severed` inerte (seul l'actif est lu).
- **Coutures documentées en PHPDoc** : relaxation permissive → **Epic 29** ; mapping label → `WorkstationGroup` → **Epic 30** ; types amont non démontrés + représentation canonique du payload → **Epic 33** (bridge extensible = enregistrer un adaptateur). Cache : aucun ; event `ControlHubContractChanged` laissé **sans listener** (cohérent 28.2/NFR3).
- **R3** : aucun identifiant livré ne contient « central » (test introspection `r3_no_central_identifier` sur les 7 FQCN + cases d'enum) ; seules occurrences = commentaires garde-fou « aucun central » (non scannés, conforme à la consigne Task 5).
- **Câblage** : `AgentServiceProvider` enrobe chaque provider via `array_map(UpstreamAwareProvider::wrap(...))`, ordre et liste préservés (zéro provider retiré/ajouté). Le couple (type, scope) discrimine les deux providers `registry` (HKLM/machine vs HKCU/session) — routage d'enveloppe, pas une précédence de maille.

### File List

**Nouveaux :**
- `app/Services/ControlHub/Resolution/UpstreamPayloadAdapter.php` (interface du bridge)
- `app/Services/ControlHub/Resolution/RegistryUpstreamAdapter.php` (adaptateur `registry`, exclusive par clé)
- `app/Services/ControlHub/Resolution/ShortcutsUpstreamAdapter.php` (adaptateur `shortcuts`, aggregate)
- `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (source des candidats amont, lecture contrat actif mémoïsée)
- `app/Services/ControlHub/Resolution/UpstreamAwareProvider.php` (décorateur de provider + fabrique `wrap()`)
- `app/Services/ControlHub/Resolution/KeyedUpstreamAwareProvider.php` (variante relayant `KeyedExclusiveProvider`)
- `tests/Feature/ControlHub/UpstreamContractResolutionTest.php` (11 tests end-to-end)

**Modifiés :**
- `app/Enums/StateMaille.php` (case `Upstream` + PHPDoc chaîne de spécificité + R3)
- `app/Services/Agent/StateCompiler.php` (`specificity()` : `Upstream => -1` + PHPDoc — **seul** changement)
- `app/Providers/AgentServiceProvider.php` (binding singleton `UpstreamContractSource` + enrobage des providers)
- `tests/Unit/Services/Agent/StateCompilerTest.php` (+2 tests : garde-fou specificity, marqueur keyed préservé)
- `docs/qa/domains/controlhub-contract.md` (Section 6 + checklist 28.3, **append-only**)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (28.3 → `review`)

### Change Log

| Date | Auteur | Changement |
|------|--------|------------|
| 2026-06-26 | DEV opus `claude-opus-4-8[1m]` | Implémentation 28.3 : tier de maille `Upstream` + rang -1 dans `specificity()` ; source `UpstreamContractSource` (contrat actif, court-circuit NFR3) ; décorateur `UpstreamAwareProvider`/`KeyedUpstreamAwareProvider` ; bridge de payload type-agnostique (`registry` + `shortcuts`) ; câblage `AgentServiceProvider` ; 13 tests HÔTE (11 Feature + 2 Unit) ; runbook QA Section 6. Statut ready-for-dev → review. |
