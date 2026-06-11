# Story 23.4: StateCompiler — résolution des mailles, précédence, premiers StateProviders

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **que l'état cible d'un (poste, user) soit calculé depuis mes règles existantes (salles, parcs, bibliothèque wallpaper, signaux overlay)**,
afin de **ne rien ressaisir : mes écrans d'administration actuels SONT la source**.

## Contexte & intention

Quatrième story de l'Epic 23. La 23.1 (done) a figé le contrat v1 (enveloppe, items, StateHasher, golden files) ; la 23.2 (done) a livré l'auth du canal ; la 23.3 (done) l'enrôlement porte 1. Cette story livre **le cerveau du système** : la fonction de compilation d'état que le legacy n'a jamais matérialisée (Vérité #1 du brainstorming). C'est du **pur serveur, sans endpoint ni migration ni UI** : `StateCompiler` + `TargetContext` + interface `StateProvider` + registry + les deux premiers providers (`Wallpaper`, `Overlay`) qui projettent en lecture seule les tables métier livrées par le POC overlay (f9b3ad9).

Ce qui vient APRÈS et qui consomme cette story :

- `GET /api/v1/agent/state` (ETag = `hashState` du compilé) → **Story 23.5** — elle construira le `TargetContext` depuis le poste authentifié + le user de la requête
- les handlers wallpaper/overlay côté agent qui consomment les payloads définis ici → **Story 24.4**
- chaque bascule de ressource = 1 nouveau provider enregistré, zéro modification du compilateur → **Epic 27**

**D2 est LE cœur de cette story** : la précédence/merge vit dans le compilateur SEUL. Un provider qui trie, filtre par maille ou applique la précédence lui-même = anti-pattern **bloquant en review** (architecture, Enforcement Guidelines).

## ⚠️ Sept pièges découverts à l'analyse (lire avant de coder)

1. **`WallpaperResolver` est une fausse bonne idée.** `app/Services/Wallpaper/WallpaperResolver.php` résout déjà un wallpaper par poste/user — mais il **applique sa propre précédence** (7 niveaux, « dernier match gagne ») : le réutiliser tel quel violerait D2 (précédence = compilateur seul). Il dépend en outre de `WallpaperContext` hydraté depuis **APCu legacy** (groupes AD). S'inspirer de sa **requête SQL** (`fetchAssignments()`, lignes ~217-260 : jointure `wallpapers` × `wallpaper_assets`, filtres par `owner_type`) mais ne **ni le réutiliser, ni le modifier** — il reste le résolveur du canal legacy jusqu'à extinction (Epic 27).
2. **Golden files + `contract-v1.md` sont FIGÉS — ne pas y toucher.** Les payloads de `tests/Fixtures/Agent/state.v1.json` sont **illustratifs** (contrat §3.2 : la sous-structure de `payload` est owné par CETTE story) ; le hash d'état figé dans `ContractV1Test` (`6c0e8135…`) est un garde-fou de canonicalisation qui ne doit PAS bouger. Les payloads réels se documentent dans un doc NEUF (`docs/agent/state-providers.md`), pas dans le contrat ni les fixtures.
3. **D2 ne positionne pas les mailles user.** L'architecture fige `poste > WG physique > WG logique > broadcast` mais ne dit rien de `user` et `groupes user` (qui existent : wallpapers à owner `User`/`UserGroup`). La chaîne complète est **tranchée ici** (décision n° 1 ci-dessous), iso-legacy — à challenger en review, pas à re-trancher en dev.
4. **Stabilité du hash = exigence de déterminisme du compilateur.** Deux compilations du même état → même hash (c'est l'ETag de 23.5). Donc : ordre de sortie des items FIGÉ par le serveur (les listes ne sont pas triées par le hasher — contrat §4), **aucun float** dans les payloads (§4.1), pas de clé volatile hors `generated_at`. Un compilateur non déterministe = 304 jamais servi + faux drift.
5. **`TargetContext` ≠ `WallpaperContext`.** Le DTO existant `app/Dto/Wallpaper/WallpaperContext.php` vient d'APCu legacy (`get_apps()`, groupes AD). Le `TargetContext` du canal agent s'hydrate **exclusivement depuis Postgres** (relations Eloquent) — y faire entrer APCu ou LdapRecord = violation du critère Keycloak (NFR7, grep en review).
6. **WG physique vs logique = `is_physical`.** `Workstation::physicalRooms()` (pivot filtré `is_physical=true`, 1 salle max — invariant story 4.11) vs `logicalGroups()` (`is_physical=false`, 0..n parcs). Les deux mailles D2 distinctes se résolvent par CE flag — pas par le nom, pas par l'AD.
7. **Aucun fichier `config/`, `routes/` ni migration dans cette story.** Donc aucun `config:cache`/`route:cache`/`migrate` sur la VM. La clé `agent.ttl_seconds` n'existe pas encore (créée en 23.5) : le compilateur lit `(int) config('agent.ttl_seconds', 3600)` — le défaut code porte la valeur en attendant. NE PAS ajouter la clé dans `config/agent.php` (périmètre 23.5).

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Chaîne de spécificité complète (types exclusifs)** : `user > groupes user > poste > WG physique > WG logique > broadcast`. D2 ne fige que la partie machine ; la partie user est placée DEVANT, iso-legacy (`WallpaperResolver` : user niveau 6 > groupe 5 > salle 3 > étab 2 — un wallpaper personnel bat celui de la salle, comportement connu des admins). La distinction legacy « type principal (4) vs groupe AD (5) » s'écrase en UNE maille `groupes user` (divergence douce assumée : conflit entre deux groupes = règle intra-maille, voir n° 2).
2. **Conflit intra-maille (exclusif)** : la règle la plus récente gagne — tri `updated_at` desc puis `id` desc (tiebreak obligatoire : déterminisme du hash si deux `updated_at` identiques) — + warning loggé `agent.state.conflict` (channel `agent`, contexte `workstation_id` + `type` + ids des règles en conflit). « Exposable UI » de l'AC epic = le log structuré suffit à ce stade (l'UI conformité arrive en 24.5).
3. **La maille est un enum interne, PAS un élément du contrat.** Enum `App\Enums\StateMaille` (`user | user_group | workstation | physical_group | logical_group | broadcast`) porté par les candidats que retournent les providers ; le **rang de spécificité vit dans le compilateur** (jamais dans l'enum ni les providers — sinon D2 fuit). N'apparaît pas dans le JSON v1 → non soumis à NFR12.
4. **`itemsFor()` retourne des candidats bruts étiquetés par maille**, pas des items finaux : un petit value object `StateCandidate` (readonly : `maille`, `payload`, `recency` (`updated_at` + id pour le tiebreak), métadonnées de log). Le compilateur applique D2 (union/spécificité/conflit), calcule `hash` par item via `StateHasher::hashItem()`, et assemble l'enveloppe.
5. **Payload `wallpaper` v1** : `{asset: string|null, checksum: string|null}` — `asset` = filename content-addressed de la biblio (`<checksum>.<ext>`, cf. `WallpaperAsset::libraryPath()`), `checksum` = SHA-256 de l'asset (c'est ce que le handler comparera en `test`). `asset: null` = « pas de fond imposé » explicite (contrat §8). L'URL de téléchargement sera AJOUTÉE quand la route de serving existera (champ ajouté = mineur, contrat §9 — pas de blocage ici). Type `wallpaper` seul : `lockscreen` n'est PAS dans les identifiants figés (§7) — futur type séparé, hors scope.
6. **Constantes par type (jusqu'à l'UI du toggle, Epic 27)** : `wallpaper` = `semantics=exclusive`, `mode=default`, `scope=session` ; `overlay` = `semantics=aggregate`, `mode=strict`, `scope=session` (alignés sur le golden file). Le provider les déclare ; aucune table de config.
7. **Payload `overlay` v1 = projection des signaux POSTÉS uniquement** : un item PAR signal actif (union aggregate), payload `{kind, severity, title, text, expires_at}` (ISO 8601 ou null — pas de float). Les **alertes dérivées** (quota, multi-session — `OverlaySignalBuilder`) et l'identité/machine restent HORS desired-state v1 : volatiles à chaque poll, elles détruiraient l'ETag, et relèvent de la métrologie temps réel plus que d'un état cible (anti-couteau-suisse #30). Le POC overlay reste le fetch en prod ; l'arbitrage final (qui compose `overlay.json`) appartient à 24.4. Signal expiré (`expires_at` ≤ now) = exclu à la compilation — l'état change réellement, l'ETag aussi : correct.
8. **Mailles d'un signal overlay** : `workstation_uuid` → poste ; `workstation_group_id` → WG (physique OU logique selon le groupe) ; `user_login` → user ; tout null → broadcast. Un signal multi-critères (groupe + user) est rangé dans sa maille la plus spécifique — sans incidence de précédence (aggregate = union), mais l'étiquette doit être cohérente pour les logs/tests.
9. **Ordre de sortie déterministe** : dans chaque portée, items triés par `type` asc, puis ordre stable intra-type (wallpaper : l'unique gagnant ; overlay : `id` asc des signaux). L'ordre est fixé par le serveur et significatif (contrat §4 — les listes ne sont pas triées par le hasher).
10. **Le cas « aucune règle » = type ABSENT** (contrat §8) : pas de règle wallpaper pour ce contexte → aucun item `wallpaper` (et PAS un item `asset: null`, qui est une règle explicite). Aucun provider avec règles → portée `[]`. Conséquence assumée : pas de « fallback système » dans l'état cible (le fallback `config('wallpapers.system_default_path')`, le perso `/home/<user>/Photos` et l'override quota sont des features du canal legacy, réexaminées au handler 24.4 — le perso est d'ailleurs le cas d'école du mode `default`/`drifted_allowed`).

## Acceptance Criteria

### AC1 — L'interface StateProvider et son registry : un type = zéro modification du compilateur

**Given** l'interface `App\Services\Agent\Contracts\StateProvider` (`type(): string`, `semantics(): ResourceSemantics`, `scope(): StateScope`, `itemsFor(TargetContext): Collection`) et un registry enregistré dans `AgentServiceProvider`
**When** un nouveau provider s'enregistre (test : provider factice en plus des deux réels)
**Then** son type est servi par `StateCompiler` sans AUCUNE modification du compilateur ni du contrat
**And** le compilateur route chaque provider vers sa portée (`scope()`) et applique la sémantique déclarée (`semantics()`) — jamais l'inverse.

### AC2 — Résolution transitive des mailles + précédence D2

**Given** un poste avec ses appartenances (salle physique, parcs logiques — pivot 4.11), ses règles propres, et un user avec ses groupes (SQL `user_group_user`)
**When** `StateCompiler::compile(TargetContext)` s'exécute
**Then** les mailles sont résolues transitivement — user, groupes user, poste, WG physiques, WG logiques, broadcast — depuis Postgres uniquement
**And** type `aggregate` = **union** des candidats de toutes les mailles applicables ; type `exclusive` = la maille la plus spécifique gagne, chaîne complète `user > groupes user > poste > WG physique > WG logique > broadcast` (décision n° 1)
**And** `TargetContext` accepte un user **null** (compilation machine-only, prérequis du check-in boot de 23.5) : les mailles user sont simplement vides, aucune erreur.

### AC3 — Conflit intra-maille (exclusif) : la plus récente gagne + warning

**Given** deux règles en conflit dans la MÊME maille sur un type exclusif (ex. deux wallpapers sur le même WorkstationGroup)
**Then** la plus récente gagne (`updated_at` desc, tiebreak `id` desc — déterministe)
**And** un warning est émis : log channel `agent`, action `agent.state.conflict`, contexte `workstation_id`, `type`, maille et ids des règles — exposable UI plus tard, jamais bloquant.

### AC4 — Les deux providers : lecture seule des tables métier

**Given** `WallpaperStateProvider` (lit `wallpapers` + `wallpaper_assets` : broadcast = `owner_id` null + `is_default`, WG via owner `WorkstationGroup`, groupe user via owner `UserGroup`, user via owner `User`) et `OverlayStateProvider` (lit `overlay_signals` actifs non expirés, mailles décision n° 8)
**Then** ils sont en **lecture seule** sur les tables métier (aucun write, aucun appel AD/APCu)
**And** aucun provider n'applique la précédence, ne trie ni ne filtre par maille — il étiquette ses candidats et c'est tout (anti-pattern D2 = **bloquant en review**)
**And** les payloads émis respectent les décisions n° 5 et n° 7 (types autorisés contrat §4.1 : jamais de float).

### AC5 — Sortie conforme au contrat v1, déterministe, hashée

**Given** une compilation complète
**Then** la sortie porte l'enveloppe v1 : `schema` = `StateContract::SCHEMA`, `generated_at` (ISO 8601 + timezone), `ttl_seconds` (`config('agent.ttl_seconds', 3600)` — clé formalisée en 23.5), et **les trois portées toujours présentes** (`machine`, `session`, `machine_user`), même vides
**And** chaque item porte exactement `{type, semantics, mode, payload, hash}` avec `hash` = `StateHasher::hashItem()` (jamais de hash ad hoc)
**And** deux compilations du même état à des instants différents donnent le même `StateHasher::hashState()` (ordre de sortie déterministe — décision n° 9)
**And** golden files et `docs/agent/contract-v1.md` **intouchés** ; le hash figé de `ContractV1Test` ne bouge pas.

### AC6 — Tests unitaires : union, spécificité, conflit, « aucune règle »

**Then** `tests/Unit/Services/Agent/` couvre : union aggregate multi-mailles (overlay) ; spécificité exclusive sur TOUTE la chaîne (broadcast battu par WG logique, battu par WG physique, battu par poste, battu par groupe user, battu par user) ; conflit intra-maille → plus récente + warning loggé ; **aucune règle → type absent** (≠ item `asset: null` qui reste servi) ; user null → mailles user vides ; signal expiré exclu ; provider factice enregistré → servi ; déterminisme (deux `compile()` → même `hashState`)
**And** `php artisan test --filter Agent` reste intégralement vert sur `/vm` (baseline 23.3 : 71 passed).

### AC7 — Transversal : zéro AD, frontières, logs

**Then** aucun appel AD/LdapRecord/APCu dans `app/Services/Agent/` (critère Keycloak, grep en review)
**And** le canal agent n'écrit RIEN (cette story est 100 % lecture + calcul — aucune écriture même dans `agent_*`)
**And** compilation loggée `agent.state.compiled` (debug/info, contexte `workstation_id`, compte d'items par portée — jamais les payloads complets), conflits loggés AC3
**And** `docs/agent/state-providers.md` (nouveau) documente : interface + registry, chaîne de spécificité complète (décision n° 1 = extension de D2), payloads wallpaper/overlay v1, le hors-scope (dérivées, fallbacks legacy → 24.4).

## Tasks / Subtasks

- [x] **T1 — `TargetContext` + enum maille** (AC2)
  - [x] `App\Enums\StateMaille` : `user | user_group | workstation | physical_group | logical_group | broadcast` — enum « bête », AUCUNE méthode de rang (le rang vit dans le compilateur).
  - [x] `App\Services\Agent\TargetContext` (emplacement figé par l'arbre architecture) : readonly, construit via `TargetContext::for(Workstation $ws, ?User $user)` — résout et mémorise UNE fois `physicalGroupIds`, `logicalGroupIds`, `userGroupIds` (relations `physicalRooms()`/`logicalGroups()`/`groups()` SQL) ; les providers consomment ces listes, ne re-requêtent jamais les appartenances.
- [x] **T2 — Interface + candidat + registry** (AC1)
  - [x] `App\Services\Agent\Contracts\StateProvider` (signature exacte de l'architecture, piège : `itemsFor(TargetContext $ctx): Collection`).
  - [x] `App\Services\Agent\StateCandidate` (readonly : `maille`, `payload`, `recency` — `updated_at`+`id`, méta de log).
  - [x] Registry : tableau de providers injecté dans `StateCompiler` via `AgentServiceProvider::register()` (le commentaire « registry des StateProviders (23.4) » y attend déjà) — pattern simple (array de bindings), pas de tagged-services exotique.
- [x] **T3 — `StateCompiler`** (AC2, AC3, AC5)
  - [x] `compile(TargetContext): array` → enveloppe v1 complète (3 portées toujours présentes, items `{type, semantics, mode, payload, hash}`).
  - [x] SEUL porteur de D2 : rang de spécificité (décision n° 1), union aggregate, gagnant exclusif, conflit intra-maille (décision n° 2) + warning `agent.state.conflict`.
  - [x] `hash` par item via `StateHasher::hashItem()` ; ordre de sortie déterministe (décision n° 9) ; `ttl_seconds` défaut code 3600 ; log `agent.state.compiled`.
  - [x] `declare(strict_types=1)`, classe pure injectable (iso 23.1-23.3).
- [x] **T4 — `WallpaperStateProvider`** (AC4)
  - [x] `App\Services\Agent\Providers\WallpaperStateProvider` : requête directe `wallpapers` × `wallpaper_assets` (s'inspirer de `WallpaperResolver::fetchAssignments()` — ne PAS le réutiliser, piège n° 1), type `wallpaper` (figé §7), `exclusive`/`default`/`session`.
  - [x] Mapping owner→maille : `null`+`is_default` → broadcast ; `WorkstationGroup::class` → physical/logical selon `is_physical` du groupe (et seulement si le groupe ∈ appartenances du contexte) ; `UserGroup::class` → user_group ; `User::class` → user. Type `lockscreen` ignoré (hors scope, décision n° 5).
  - [x] Payload : `{asset, checksum}` (décision n° 5) ; règle sans asset (asset_id null) → `{asset: null, checksum: null}` (règle explicite, pas type absent).
- [x] **T5 — `OverlayStateProvider`** (AC4)
  - [x] `App\Services\Agent\Providers\OverlayStateProvider` : lit `overlay_signals` (réutiliser le scope `activeFor` ou requête équivalente — lecture seule), type `overlay` (figé §7), `aggregate`/`strict`/`session`.
  - [x] Un candidat par signal actif ; payload décision n° 7 ; étiquette maille décision n° 8 ; signaux expirés exclus ; user null → signaux à `user_login` non null exclus.
- [x] **T6 — Documentation** (AC7)
  - [x] `docs/agent/state-providers.md` (nouveau) : interface + registry + comment ajouter un type (checklist Epic 27 : identifiant figé + semantics + scope + provider + golden file), chaîne de spécificité complète et son rationale iso-legacy, payloads v1 wallpaper/overlay, hors-scope explicite (dérivées overlay, fallbacks legacy, lockscreen, URL de téléchargement → références 24.4/27).
  - [x] `contract-v1.md` et golden files **intouchés** (piège n° 2).
- [x] **T7 — Tests** (AC6)
  - [x] `tests/Unit/Services/Agent/StateCompilerTest.php` (`RefreshDatabase` + factories, convention `EnrollmentServiceTest` 23.3) : utiliser des providers factices pour tester D2 pur (union/spécificité/conflit/déterminisme/provider ajouté) — le compilateur se teste sans les vrais providers.
  - [x] `tests/Unit/Services/Agent/WallpaperStateProviderTest.php` : mapping owner→maille, lockscreen ignoré, asset null, lecture seule (factories `Wallpaper`/`WallpaperAsset`/`WorkstationGroup`/`UserGroup`/`User` existantes).
  - [x] `tests/Unit/Services/Agent/OverlayStateProviderTest.php` : mailles signal, expiration, user null (pas de factory `OverlaySignal` — `OverlaySignal::create()` direct, fillable complet).
  - [x] Test d'intégration compile complet : contexte réaliste (poste + salle + parc + user + groupes, règles des deux providers) → structure validée contre les invariants du contrat (3 portées, 5 clés par item, enums valides — iso assertions `ContractV1Test`, SANS comparer aux golden payloads illustratifs).
- [x] **T8 — Vérifications finales** (AC5, AC6, AC7)
  - [x] `php -l` sur tous les fichiers créés/modifiés.
  - [x] `php artisan test --filter Agent` sur `/vm` : 71 baseline + nouveaux, zéro régression. Suite `--filter Wallpaper` + `--filter Overlay` : vertes (lecture seule = aucun impact attendu, tout rouge = régression).
  - [x] Grep critère Keycloak (`ldap`, `samba-tool`, `apcu`, `get_apps`) sur `app/Services/Agent/` → vide.
  - [x] Aucun `config:cache`/`route:cache`/`migrate` VM nécessaire (piège n° 7) — vérifier qu'on n'a effectivement rien mis sous `config/`.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (23.4) | Hors-scope (story) |
|---|---|
| `StateCompiler` + `TargetContext` + `StateMaille` + `StateCandidate` | Endpoint `GET /state`, ETag/304, résolution du user depuis la requête → **23.5** |
| Interface `StateProvider` + registry (`AgentServiceProvider`) | Complétion `config/agent.php` (`ttl_seconds` & co) → **23.5** |
| `WallpaperStateProvider` + `OverlayStateProvider` (lecture seule) | Handlers côté poste, composition `overlay.json`, alertes dérivées → **24.4** |
| Payloads v1 wallpaper/overlay (définis + documentés) | URL de téléchargement des assets (champ mineur futur) → **24.4** |
| Doc `docs/agent/state-providers.md` | Type `lockscreen`, type `shortcuts` et suivants → **Epic 27** |
| Tests unit compiler + providers | UI conformité, warnings de conflit dans l'UI → **24.5** |
| | Toute modif de `WallpaperResolver`/`OverlayService` (canal legacy intouché) |

### Patterns existants à imiter (NE PAS réinventer)

- **Hash d'item/état** : `App\Services\Agent\StateHasher` (`hashItem()`/`hashState()`) — JAMAIS de `hash('sha256', …)` ad hoc (Enforcement n° 2).
- **Constantes de contrat** : `StateContract::SCHEMA` + `StateContract::scopes()` (clés d'enveloppe — ne pas redéclarer les strings).
- **Enums existants** : `ResourceSemantics`, `StateScope`, `StateMode` (23.1) — les réutiliser, valeurs string déjà figées.
- **Lecture biblio wallpaper** : `WallpaperResolver::fetchAssignments()` (`app/Services/Wallpaper/WallpaperResolver.php:217-260`) — la jointure et les `owner_type` (FQCN morph : `WorkstationGroup::class`, `UserGroup::class`, `User::class`) ; broadcast = `owner_id` null + `is_default = true`.
- **Lecture signaux** : `OverlaySignal::scopeActiveFor()` (`app/Models/OverlaySignal.php:60-86`) — matching jokers null + expiration ; colonnes de ciblage : `workstation_uuid`, `workstation_group_id`, `user_login`.
- **Appartenances poste** : `Workstation::physicalRooms()` / `logicalGroups()` / `groups()` (`app/Models/Workstation.php:141-223`, pivot unifié 4.11) ; user : `User::groups()` (`app/Models/User.php:125-133`, pivot SQL `user_group_user`).
- **Service provider** : `app/Providers/AgentServiceProvider.php` — singletons + commentaire « registry des StateProviders (23.4) » : c'est l'emplacement attendu.
- **Logging channel `agent`** : conventions 23.2 (`config/logging.php:173`, actions namespacées, contexte `workstation_id`).
- **Style tests** : `tests/Unit/Services/Agent/EnrollmentServiceTest.php` (Unit + `RefreshDatabase` + factories = convention validée) ; `ContractV1Test.php` pour les assertions structurelles du contrat.

### Architecture — conventions figées applicables (NON négociables)

[Source: architecture-agent-desired-state.md#Implementation Patterns / #Enforcement Guidelines / #Structure]

- Interface EXACTE : `type(): string`, `semantics(): ResourceSemantics`, `scope(): StateScope`, `itemsFor(TargetContext $ctx): Collection` — « Le StateCompiler applique D2 — JAMAIS le provider ».
- Emplacements figés : `App\Services\Agent\StateCompiler`, `App\Services\Agent\TargetContext`, `App\Services\Agent\Contracts\StateProvider`, `App\Services\Agent\Providers\{Wallpaper,Overlay}StateProvider`.
- Identifiants de types figés §7 : `wallpaper`, `overlay` — snake_case, jamais renommés (NFR12).
- StateProviders en **lecture seule** sur les tables métier ; le canal agent n'écrit que dans `agent_*` (ici : rien du tout).
- Aucune logique de compilation dans un controller (couche Services) ; aucun appel AD (critère Keycloak).
- Anti-patterns bloquants : provider qui applique la précédence ; table générique de règles ; hash ad hoc ; renommage d'identifiant publié.

### Contrat v1 — invariants consommés ici

[Source: docs/agent/contract-v1.md §3, §4, §4.1, §8]

- Item = exactement 5 clés `{type, semantics, mode, payload, hash}` ; portées = listes ordonnées (plusieurs items d'un même type aggregate possibles).
- Hash : canonicalisation = tri récursif `ksort SORT_STRING` des associatifs, listes NON triées (l'ordre serveur est significatif → décision n° 9), `generated_at` exclu de `hashState`, clé `hash` exclue de `hashItem`.
- §4.1 : **pas de floats** dans les payloads ; `{}` ≡ `[]` après décodage PHP (ne jamais faire porter du sens à la différence) ; le serveur émet en NFC.
- §8 : type absent = « aucune règle » ; « remise à neutre » = payload explicite (`asset: null`), jamais l'omission.

### Project Structure Notes

- **Racine = projet Laravel** ; code édité sur l'hôte, exécuté sur la VM `/vm` (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, `/var/www/sambaedu-reload`) ; sync inotify auto — jamais de sync manuel ; si non-synchro → notifier Henri et attendre. Jamais de VM depuis un worktree git.
- Aucune migration, route, ni clé config dans cette story → **aucune opération VM** hors lancement des tests (piège n° 7).
- inotify ne propage pas les suppressions — aucun fichier supprimé prévu ici.

### Testing standards

- PHPUnit, référence `/vm` ; SQLite `:memory:` en test (pas d'enforcement varchar — sans incidence ici, aucune écriture).
- `--filter Agent` : baseline 71 passed (23.1 : 19, 23.2 : 42 dont +4 review, 23.3 : 71 cumulés post-review) — doit rester intégralement vert + les nouveaux.
- Le compilateur se teste avec des **providers factices** (D2 pur, sans dépendre des tables wallpaper/overlay) ; les providers réels se testent chacun avec leurs factories. Un test d'intégration assemble le tout.
- Piège déterminisme : tester `compile()` deux fois avec `travel()`/timestamps différents → même `hashState` (c'est LE test qui protège l'ETag de 23.5).

### Intelligence stories précédentes

- **23.1 (done)** : contrat figé — `StateHasher` a un tri lexicographique FIGÉ (`SORT_STRING`, garde-fou `FROZEN_STATE_HASH`) ; `declare(strict_types=1)` partout ; classes pures injectables ; doc des décisions dans `docs/agent/`. REPORTÉ noté → 24.1 (validation entrée agent), rien pour 23.4.
- **23.2 (done)** : `AgentServiceProvider` existe (point d'enregistrement du registry) ; channel log `agent` opérationnel ; normalisation MAC via `MacAddressNormalizer` (sans objet ici) ; defer throttle → 23.5/24.1.
- **23.3 (done)** : convention Unit + `RefreshDatabase` validée ; piège route-cache VM (sans objet ici : aucune route) ; piège fenêtre 1500 chars `routes/api.php` (sans objet ici) ; le dev suit le code livré, pas le papier, quand ils divergent.
- **POC overlay (f9b3ad9)** : le ciblage overlay actuel fait l'union à plat de tous les signaux qui matchent (`activeFor`), AUCUNE précédence codée — la précédence D2 n'existe nulle part dans le codebase, cette story l'écrit pour la première fois.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 23.4] — ACs source, FR2/FR3/FR4.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Core Architectural Decisions D1/D2 ; #Implementation Patterns (interface StateProvider) ; #Enforcement Guidelines] — précédence, anti-patterns, interface exacte.
- [Source: docs/agent/contract-v1.md §3.2, §4.1, §7, §8] — payload owné par cette story, contraintes hash, identifiants figés, tableau vide ≠ type absent.
- [Source: app/Services/Wallpaper/WallpaperResolver.php:42-336] — résolution legacy 7 niveaux (rationale décision n° 1, requête à imiter, classe à NE PAS réutiliser).
- [Source: app/Models/OverlaySignal.php ; app/Services/Overlay/OverlayService.php:32-143] — colonnes de ciblage, matching jokers, absence de précédence.
- [Source: app/Models/Workstation.php:141-223 ; app/Models/User.php:107-133] — relations d'appartenance SQL (pivot 4.11, `user_group_user`).
- [Source: tests/Fixtures/Agent/state.v1.json ; tests/Unit/Services/Agent/ContractV1Test.php] — wrapper normatif, payloads illustratifs, hash figé à ne pas casser.
- [Source: app/Providers/AgentServiceProvider.php:25-43] — emplacement du registry.

## Dev Agent Record

### Agent Model Used

claude-fable-5 (modèle recommandé : **fable** — décision Henri pour tout l'Epic 23 : raisonnement D2/déterminisme hash + cohérence contrat sur 5 stories.)

### Debug Log References

- Suite `--filter Agent` sur /vm : **102 passed (390 assertions)** — baseline 71 + 30 nouveaux tests (StateCompiler 15, WallpaperProvider 8, OverlayProvider 7), zéro régression, vert au premier run.
- `--filter Wallpaper` : 88 passed / 10 skipped (skips préexistants, environnement) ; `--filter Overlay` : 37 passed — lecture seule confirmée, aucun impact.
- Grep critère Keycloak (`ldap|apcu|get_apps|samba-tool|WallpaperResolver|WallpaperContext`) sur `app/Services/Agent/` : seuls des commentaires docblock matchent, zéro code.
- `php -l` vert sur les 12 fichiers créés/modifiés ; golden files + `contract-v1.md` intouchés (hash figé `6c0e8135…` vérifié par la suite) ; rien sous `config/`/`routes/`/migrations → aucune op VM hors tests.
- Suite COMPLÈTE sur /vm : **4046 passed / 2 failed** — les 2 échecs sont **préexistants sur main**, hors périmètre : `WpkgReportApiTest::post_from_non_local_ip_returns_403` (SQLite `no such table: system_settings`, lecture runtime story 15.6 — préexistence PROUVÉE par revert temporaire d'`AgentServiceProvider`, seul fichier existant modifié : échec identique) et `GpoIndexExportTest::it_shows_advanced_filter_controls` (`data-testid="advanced-filters-panel"` absent de la vue Livewire GPO — échec déterministe en solo, aucun fichier UI/GPO touché par la story). À traiter hors 23.4.

### Implementation Plan

- **D2 dans le compilateur seul** : rang de spécificité = `match` privé `StateCompiler::specificity()` (pas de const enum → compat PHP 8.2 VM, et surtout pas de rang dans `StateMaille` ni les providers). Exclusif = maille de rang min ; conflit intra-maille gagnante = tri `updated_at` desc + tiebreak `id` desc + warning `agent.state.conflict` (le warning n'est émis QUE pour la maille gagnante : un conflit dans une maille battue n'arbitre rien). Aggregate = union triée `sourceId` asc.
- **Déterminisme** : providers traités par `type()` asc (`strcmp`, iso `SORT_STRING` du hasher) indépendamment de l'ordre du registry ; seul `generated_at` (ISO 8601 UTC) est volatil ; testé par double `compile()` + `travel(3h)` → même `hashState`.
- **⚠️ Décision dev à challenger en review — `mode()` ajouté à l'interface `StateProvider`** : l'interface architecture (4 méthodes) ne porte pas `mode`, mais l'item du contrat l'exige et AC1 interdit toute table type→mode dans le compilateur (un provider factice doit être servi sans modification). La décision n° 6 (« le provider les déclare ») impose donc une 5e méthode `mode(): StateMode`. Documenté dans le docblock de l'interface + `state-providers.md`.
- `TargetContext::for()` : 3 requêtes pluck max (physicalRooms/logicalGroups/groups), listes d'ids mémorisées, user nullable → listes user vides. Helper `workstationGroupIds()` (union salle+parcs) pour les ciblages indifférenciés (overlay).
- `WallpaperStateProvider` : requête inspirée de `fetchAssignments()` mais en **LEFT JOIN** (règle sans asset = candidat `{asset: null, checksum: null}` explicite, contrat §8) ; mapping owner→maille par `is_physical` via les listes du contexte (étiquetage, pas précédence) ; type lockscreen filtré par `ofType`.
- `OverlayStateProvider` : réutilise `scopeActiveFor` (jokers + expiration + garde user_login='') en lecture seule ; étiquette maille la plus spécifique (user > poste > WG > broadcast, décision n° 8) ; `expires_at` ISO 8601 UTC ou null.
- Registry = simple tableau dans `AgentServiceProvider::register()` (singleton `StateCompiler` + `StateHasher`) — ajouter un type = une ligne.

### Completion Notes List

- ✅ AC1 : interface + registry — provider factice servi sans modification du compilateur (testé : `brand_new_type` + factice à côté des 2 réels en intégration) ; le compilateur route par `scope()` et applique `semantics()` déclarés.
- ✅ AC2 : mailles résolues transitivement depuis Postgres seul ; union aggregate multi-mailles ; chaîne exclusive complète testée maillon par maillon (broadcast → logical → physical → poste → groupe user → user) ; user null = mailles vides sans erreur.
- ✅ AC3 : conflit intra-maille → plus récente gagne (tiebreak `id` desc testé à `updated_at` égal) + warning `agent.state.conflict` (channel agent, contexte complet workstation_id/type/maille/rule_ids).
- ✅ AC4 : deux providers lecture seule, zéro précédence/tri/filtre par maille, payloads décisions n° 5 et 7 (aucun float, dates ISO UTC).
- ✅ AC5 : enveloppe v1 complète (3 portées toujours présentes, `ttl_seconds` défaut code 3600), items 5 clés exactes, hash par `StateHasher::hashItem()`, déterminisme prouvé (2 compiles à 3 h d'écart → même `hashState`), golden files et hash figé intouchés.
- ✅ AC6 : 30 tests Unit (compilateur via providers factices = D2 pur ; providers réels via factories ; intégration réaliste avec assertions structurelles iso `ContractV1Test` sans comparaison aux golden payloads) — `--filter Agent` 102 passed sur /vm.
- ✅ AC7 : zéro AD/APCu (grep vide hors docblocks) ; story 100 % lecture + calcul (aucune écriture, même `agent_*`) ; logs `agent.state.compiled` (debug, comptes par portée, jamais les payloads) et `agent.state.conflict` ; doc neuve `docs/agent/state-providers.md` (interface, checklist Epic 27, chaîne de spécificité + rationale, payloads v1, hors-scope).
- Point d'attention review : ajout de `mode(): StateMode` à l'interface (cf. Implementation Plan) — déviation motivée de la signature architecture 4-méthodes.

### File List

**Créés :**
- `app/Enums/StateMaille.php`
- `app/Services/Agent/TargetContext.php`
- `app/Services/Agent/Contracts/StateProvider.php`
- `app/Services/Agent/StateCandidate.php`
- `app/Services/Agent/StateCompiler.php`
- `app/Services/Agent/Providers/WallpaperStateProvider.php`
- `app/Services/Agent/Providers/OverlayStateProvider.php`
- `docs/agent/state-providers.md`
- `tests/Unit/Services/Agent/StateCompilerTest.php`
- `tests/Unit/Services/Agent/WallpaperStateProviderTest.php`
- `tests/Unit/Services/Agent/OverlayStateProviderTest.php`

**Modifiés :**
- `app/Providers/AgentServiceProvider.php` (registry StateProviders + singletons `StateHasher`/`StateCompiler`)

## Change Log

- 2026-06-11 — **Code review adversariale (3 couches : Blind Hunter / Edge Case Hunter / Acceptance Auditor)** : 7/7 AC satisfaits, golden files + hash figé vérifiés intouchés. Déviation `mode(): StateMode` sur l'interface **validée** (sans elle, table type→mode dans le compilateur = violation AC1 ; trou de l'architecture, pas du dev — à acter comme amendement d'interface Epic 27). 2 correctifs appliqués (orchestrateur) : **P1** `default => throw LogicException` dans `WallpaperStateProvider::mailleFor()` (garde-fou morph map, inatteignable via le WHERE actuel) ; **P2** récence du conflit intra-maille comparée en **microsecondes** (`getTimestamp()*1e6 + format('u')`, TZ-safe) — théorique avec `timestamps(0)`, réel pour tout futur provider ; + test de régression `exclusive_conflict_sub_second_recency_beats_id_tiebreak`. **Deferred → 23.5** : `(int) config('agent.ttl_seconds', 3600)` rend 0 si la clé vaut `null` (le défaut ne couvre que l'absence) — câbler un défaut robuste en créant la clé. ~10 findings rejetés (faux positifs vérifiés : leftJoin sur PK 1:1, scopes() dérivé de l'enum, casts datetime). Suite Agent /vm post-fix : **103 passed (391 assertions)**.
- 2026-06-11 — Story 23.4 implémentée (claude-fable-5) : `StateCompiler` (SEUL porteur de D2 : spécificité complète user > groupes user > poste > WG physique > WG logique > broadcast, union aggregate, conflit intra-maille récence+tiebreak+warning), `TargetContext` (Postgres only, user nullable), enum interne `StateMaille`, `StateCandidate`, interface `StateProvider` (+`mode()`, déviation motivée), registry `AgentServiceProvider`, providers `Wallpaper`/`Overlay` lecture seule, doc `state-providers.md`, 30 tests. Suite Agent /vm : 102 passed, zéro régression. Status → review.
