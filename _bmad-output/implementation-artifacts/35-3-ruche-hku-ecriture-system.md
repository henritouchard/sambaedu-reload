# Story 35.3 : Ruche `HKU` — écriture SYSTEM des ruches utilisateur

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-capacites-v2.md (Epic 35 ne figure PAS dans epics.md). -->

## Story

En tant que **référent numérique**,
je veux **diffuser des clés per-user que seule la machine peut écrire (écran de logon, trees policy)**,
afin de **couvrir `HKU\.DEFAULT` (numlock au logon) et `HKCU\Software\Policies\*` sans companion**.

## Contexte & intention

Troisième mur de l'Epic 35 (Capacités v2 — GPO spéciales CD95) : certaines clés **per-user** sont hors d'atteinte du modèle actuel parce qu'aucun des deux exécutants ne peut les écrire :

- **`HKU\.DEFAULT`** (le « profil » lu par l'écran de logon / LogonUI, qui tourne en SYSTEM) : ni HKLM ni HKCU → la partie « numlock à l'écran de logon » de la GPO CD95 « Verr_num » a été EXCLUE du palier A (cf. commentaire du seed `2026_07_02_100000`, section « EXCLUS DU LOT ») ;
- **`HKCU\Software\Policies\*`** : lecture seule pour l'utilisateur → le compagnon échoue (mémoire projet `project_hkcu_policies_not_writable_by_companion`, leçon fix-Copilot) ; jusqu'ici le seul contournement était une clé HKLM équivalente quand elle existe.

**Ce que la story livre** — la ruche `HKU` comme **troisième valeur du champ `hive`** (pas un champ nouveau, pas un type nouveau) :

1. **Serveur** : une clé de `spec` `hive: 'HKU'` est émise par le provider **MACHINE** (portée `machine`, appliquée par le service SYSTEM), JAMAIS par le provider Session. `exclusiveKey()` inchangée (`hku|path|name` — identité distincte de HKLM/HKCU), `StateCompiler` INTOUCHÉ (D2). Validation d'authoring documentée + garde-fou (`hive` borné par mécanisme).
2. **Agent (service SYSTEM)** : le handler `registry` **fan-out** un item `hive: HKU` vers `HKU\.DEFAULT` ET chaque ruche utilisateur **chargée** (`HKU\<SID>` des sessions ouvertes), à chaque cycle. Une session ouverte après coup est couverte au cycle suivant (level-triggered). Drift **agrégé** : UNE ruche divergente ⇒ l'item (donc le type) rapporte `drift`. Le verbe `ensure: absent` (35.1) s'applique à TOUTES les ruches.
3. **Lot CD95** : migration de complément — `numlock_on_logon` gagne la clé HKU (`Control Panel\Keyboard\InitialKeyboardIndicators`, le handler fan-out couvrant `.DEFAULT` = écran de logon). Le commentaire de seed documente le **débouché** : toute clé `HKCU\Software\Policies\*` devient diffusable en machine/parc via HKU — le contournement HKLM type fix-Copilot n'est plus le seul chemin.

**L'identité logique prime** : un item HKU reste UN item du state — payload et hash **inchangés par le nombre de sessions** ; le fan-out physique (1 cible logique → N ruches) est **interne au handler**, invisible du contrat, du compilateur et du rapport.

**Pourquoi maintenant** : 35.1 (verbe `ensure`, agent 2.3.0) et 35.2 (`registry_list` + `name:""`, agent 2.4.0) sont LIVRÉES ET MERGÉES sur main. 35.3 est indépendante de 35.4/35.5 (elles aussi livrées) et clôt la GPO CD95 « Verr_num » (numlock à l'écran de logon).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — HKU est une VALEUR de `hive`, pas un champ : golden INCHANGÉS (décision tranchée, voir Décisions).** Aucun champ ni type nouveau au contrat → `state.v1.json`, `report.v1.json`, `FROZEN_STATE_HASH` (PHP) et `frozenStateHash` (Go) ne bougent PAS. La justification s'écrit au Dev Agent Record (règle 23.1 : « rien à recalculer, forme du wire inchangée ; la couverture parse/convergence vit dans les tests dédiés du handler »). Ne PAS ajouter d'item HKU au golden « pour la couverture » : ça imposerait le recalcul des hashes jumeaux sans rien figer de plus (le format 5-clés/4-clés est déjà figé ; HKU est de la donnée).

2. **Piège #2 — binaire antérieur : UN item HKU met TOUT le type `registry` machine en `error` → PUBLIER AVANT DE MIGRER.** `parseRegistrySpec` (shared) ne valide pas les valeurs de `hive` → un binaire ≤ 2.4.1 PARSE l'item HKU, puis `rootKey()` (windows) le refuse à la première lecture → `Test` retourne une **erreur** → `engine.go` rend `{status: error}` pour le type `registry` ENTIER et **n'appelle pas Apply** : toutes les clés HKLM du poste cessent de converger (dérives non corrigées) tant que l'item HKU est au state. Conséquence opérationnelle NON NÉGOCIABLE : la release **2.5.0 doit être publiée AVANT** de jouer la migration numlock sur /vm (elle arme un item HKU en **broadcast `on`** = flotte entière immédiatement). À écrire en toutes lettres dans la note de publication ET le Dev Agent Record.

3. **Piège #3 — StateCompiler INTOUCHÉ (D2), zéro précédence nouvelle.** `exclusiveKey()` du provider registry reste `{hive|path|name}` minuscules : `hku|control panel\keyboard|initialkeyboardindicators` est une identité DISTINCTE de la clé HKCU jumelle — les deux items coexistent (l'un en portée machine, l'autre en session). Aucune ligne dans `app/Services/Agent/StateCompiler.php`.

4. **Piège #4 — « pas de ciblage par utilisateur » est STRUCTUREL, pas un interdit à coder.** Le service SYSTEM fetch son state **sans `?user`** (`StateController::resolveUser` → contexte machine-only, `userGroupIds = []`, cf. `TargetContext::for($ws, null)`) : les overrides UserGroup/User n'atteignent JAMAIS les items que le service applique. C'est la base de la sémantique « toutes les ruches utilisateur du poste + .DEFAULT — le ciblage fin reste au Session/HKCU ». Ne rien coder d'interdit côté assignments ; le PROUVER par un test de compilation machine-only, et le DOCUMENTER (contrat + guard).

5. **Piège #5 — double-clé HKU + HKCU sur le même `{path|name}` = voulu pour numlock, mais à discipliner.** Physiquement, `HKU\<SID>\Control Panel\Keyboard` == le HKCU de cet utilisateur : la clé HKU (SYSTEM, valeur machine) et la clé HKCU (compagnon, valeur session) écrivent LE MÊME emplacement dans les ruches des sessions ouvertes. Sous broadcast, les deux maps donnent la même donnée → convergent. MAIS un override user-maille qui diverge de la valeur machine ferait se BATTRE compagnon et SYSTEM (réécriture croisée à chaque cycle, drift perpétuel des deux côtés). Règle d'authoring à DOCUMENTER (guard docblock + doc contrat + commentaire de migration) : une capacité portant une clé HKU ne doit pas être ciblée par utilisateur/groupe d'utilisateurs, et ses maps HKU/HKCU jumelles doivent être **valeur-consistantes**. Pas de garde-fou runtime au-delà de la doc (pas de choix sur-conçu — mémoire `feedback_no_overengineered_choices`).

6. **Piège #6 — le fan-out ne passe PAS par le path de la `spec`.** L'AC epic écrit « `.DEFAULT\Control Panel\Keyboard\...` » pour désigner la clé PHYSIQUE exclue du palier A. La clé de `spec`/payload est `{hive: 'HKU', path: 'Control Panel\Keyboard', ...}` — SANS `.DEFAULT` : c'est le handler qui préfixe chaque cible (`.DEFAULT\<path>`, `<SID>\<path>`). Un path de seed commençant par `.DEFAULT\` produirait un double-préfixe silencieux (`.DEFAULT\.DEFAULT\...`).

7. **Piège #7 — énumération des ruches : filtre STRICT, jamais mise en cache.** `HKEY_USERS` contient : `.DEFAULT`, les SID de service `S-1-5-18/19/20` (SYSTEM, LocalService, NetworkService), les ruches user `S-1-5-21-…` ET leurs jumelles `S-1-5-21-…_Classes` (HKCR per-user — y écrire `Control Panel\...` créerait des débris). Cibles = `.DEFAULT` + sous-clés matchant `^S-1-5-21-` SANS suffixe `_Classes` (insensible à la casse), ordre trié (logs déterministes). Les SID de service ne matchent pas `S-1-5-21-` (exclusion naturelle — le documenter). Comptes AAD (`S-1-12-1-…`) HORS périmètre (parc AD — noter la limite). Énumérer À CHAQUE appel Test/Apply (session ouverte/fermée entre deux cycles = couverte/évaporée au cycle suivant, jamais d'état retenu) ; une ruche déchargée ENTRE l'énumération et l'op (race logoff) est une erreur isolée bénigne, re-résolue au cycle suivant.

8. **Piège #8 — `RegistryOps` gagne une méthode (breaking pour les implémenteurs — il n'y en a que deux).** Nouvel op **requis** `UserHives() ([]string, error)` (pas une assertion optionnelle type `registryNotifier` : sans lui, un item HKU est inapplicable — l'échec doit être franc). Implémenteurs : `agent/windows/handler_registry_windows.go` (réel) et `fakeRegistryOps` de `agent/shared/handler_registry_test.go` (à ÉTENDRE, ne pas créer un second fake — il est aussi consommé par `handler_registry_list_test.go` : vérifier la compile du package).

9. **Piège #9 — pas de rafraîchissement shell sur HKU.** `isUserHive()` rend `false` pour HKU (déjà le cas — branche `default`) : le service écrit depuis la session 0, `SHChangeNotify` n'y rafraîchit aucun bureau interactif. NE PAS l'étendre. Effet des clés HKU dans les sessions ouvertes : au prochain re-read de l'app (iso mémoire `project_registry_apply_effect_next_logon` — pour numlock, l'effet visé est l'écran de logon, lu par LogonUI à chaque affichage).

10. **Piège #10 — sentinelle `REG_UNSUPPORTED` (review 35.1 #1) : valable PAR ruche.** `Read` rend `present=true, Kind REG_UNSUPPORTED` pour une valeur de type exotique — le fan-out doit la voir DANS CHAQUE ruche : un `ensure:absent` HKU supprime une valeur REG_BINARY résiduelle dans `HKU\<SID>` même si `.DEFAULT` est déjà propre (drift agrégé). Test avec Kind exotique sur UNE ruche parmi N obligatoire.

11. **Piège #11 — `registry_list` sur HKU : HORS SCOPE, et le guard le VERROUILLE.** L'epic ne le demande pas ; le fan-out d'une réconciliation de clé-conteneur multiplierait la surface (propriété de clé × N ruches) sans consommateur connu. Décision : les providers `registry_list` restent HKLM/HKCU (leur `expand()` filtre déjà par `hive()` — AUCUNE modification) et `CapabilitySpecCollisionGuard` refuse explicitement un conteneur `hive: HKU` (violation nommée). Documenter dans le §7.6 du contrat (« HKU non admis en registry_list — extension future si besoin réel »).

12. **Piège #12 — byte-identité des providers existants.** Le filtre de ruche vit dans `AbstractCapabilityStateProvider::expand()` (`strcasecmp($hive, $this->hive())`). L'ouverture à HKU se fait par un prédicat surchargeable (`handlesHive()`, défaut = comportement actuel) surchargé UNIQUEMENT dans `RegistryMachineCapabilityProvider` (classe finale du mécanisme `registry`) : `RegistryUserCapabilityProvider` et les deux providers `registry_list` (qui héritent du défaut via leurs propres classes) sont BYTE-IDENTIQUES. Les tests existants (`CapabilityRegistryProviderTest`, `CapabilityRegistryListProviderTest`, compilations) passent SANS modification d'attendus — c'est la garde de non-régression.

13. **Piège #13 — version courante = 2.4.1, pas 2.4.0.** Un correctif hors-epic (détection d'extinction, `shared/shutdown.go`) a été livré en 2.4.1 depuis la 35.2. Le bump de cette story est **2.4.1 → 2.5.0** (l'epic écrivait « 2.4.0 → 2.5.0 » avant la 2.4.1) ; entrée changelog dans le commentaire de `agent/shared/version.go`, style des entrées 2.3.0/2.4.0.

14. **Piège #14 — consommateurs annexes : rien à faire, le VÉRIFIER.** `UpstreamLockCollisionDetector` (null-safe sur `payload['value']`) et `ControlHubContractSeveranceService` (garde `is_scalar`) tolèrent déjà toute forme d'item registry ; `RegistryUpstreamAdapter` (canal amont) parse SA convention `key = "hive|path|name[|REG_TYPE]"` → HORS scope (le contrat amont pourra admettre HKU plus tard — additif). Grep de contrôle, zéro modification attendue.

## Décisions de design (tranchées — cadrage epic + exploration code)

1. **D1 — contrat additif** : HKU = nouvelle **valeur admise** du champ `hive` existant (types/champs inchangés). Évolution documentée §7.1 + §9 (« valeur de domaine ajoutée = mineur ; binaire antérieur : item HKU parsé puis refusé par `rootKey` à l'op → `{status: error}` pour le type `registry` machine ENTIER — publication OBLIGATOIRE avant l'armement »). **Golden inchangés** (piège #1) — décision justifiée : pas de champ nouveau, pas de type nouveau, rien à figer ; couverture par tests dédiés.
2. **D2 — StateCompiler intouché** ; `exclusiveKey()` inchangée (hku|path|name = identité distincte).
3. **Portée** : HKU = provider **MACHINE** uniquement (`RegistryMachineCapabilityProvider::handlesHive()` accepte HKLM + HKU). Le provider Session et les providers `registry_list` n'émettent JAMAIS de HKU.
4. **Sémantique agent** : fan-out interne au handler — cibles = `.DEFAULT` + `S-1-5-21-*` chargées (hors `_Classes`), énumérées à chaque appel via le nouvel op requis `RegistryOps.UserHives()`. Test conforme ssi TOUTES les cibles conformes (drift agrégé) ; Apply converge chaque cible en effort maximal ; `ensure:absent` supprime dans TOUTES les cibles. Identité/hash de l'item : inchangés par le nombre de sessions.
5. **`registry_list` HKU : HORS scope** (piège #11) — refus d'authoring explicite + doc.
6. **Validation d'authoring** (AC epic « documentée ») : `CapabilitySpecCollisionGuard` gagne le borné des ruches — `registry` : `hive ∈ {HKLM, HKCU, HKU}` ; `registry_list` : `hive ∈ {HKLM, HKCU}` (HKU refusé, message nommant la capacité). Docblock du guard + §7.1 du contrat portent la sémantique HKU (« toutes les ruches utilisateur du poste + .DEFAULT ; pas de ciblage par utilisateur — structurel : contexte machine sans user ; maps HKU/HKCU jumelles valeur-consistantes »). La double-clé HKU+HKCU même `{path|name}` (numlock) n'est PAS une violation (test nominal).
7. **Migration de complément** (`numlock_on_logon`) : la `spec` gagne `{hive: 'HKU', path: 'Control Panel\Keyboard', name: 'InitialKeyboardIndicators', type: 'REG_SZ', value: ['on' => '2', 'off' => '0']}` — map SYMÉTRIQUE miroir de la clé HKCU existante (mémoire `project_capability_value_map_symmetric_rule` : les options exposent `off` ⇒ `off` écrit une vraie valeur). PAS de `.DEFAULT` dans le path (piège #6). Idempotente, `down()` restaure la spec 1-clé du palier A.
8. **Pas de shell refresh HKU** (piège #9) ; pas de wiring nouveau (le handler `registry` du service SYSTEM existant reçoit les items HKU par la portée machine — `main_windows.go`/`companion_windows.go` INCHANGÉS).

## Acceptance Criteria

### AC1 — Serveur : émission MACHINE-only + validation d'authoring documentée

**Given** une clé de `spec` avec `hive: 'HKU'` sur une projection `windows/registry`
**When** les providers filtrent par ruche
**Then** elle est émise par `RegistryMachineCapabilityProvider` (portée Machine — appliquée par SYSTEM) et JAMAIS par `RegistryUserCapabilityProvider` ni par les providers `registry_list`
**And** l'item émis est CONCRET et iso-format : 5 clés `{hive:'HKU', path, name, type, value}` pour une écriture, 4 clés `{hive, path, name, ensure:'absent'}` si la map porte le marqueur `$ensure` (35.1) — zéro fuite d'id de capacité, zéro float
**And** `exclusiveKey()` est inchangée : l'identité `hku|…` est distincte de la clé HKCU jumelle, `StateCompiler` INTOUCHÉ (un test de compilation prouve la coexistence machine/session des deux items numlock et la précédence broadcast/parc sur la clé HKU)
**And** un test de compilation **machine-only** (`TargetContext::for($ws, null)`) prouve qu'un override UserGroup n'atteint pas l'item HKU (« pas de ciblage par utilisateur » structurel, piège #4)
**And** `CapabilitySpecCollisionGuard` borne les ruches : `registry` ∈ {HKLM, HKCU, HKU}, `registry_list` ∈ {HKLM, HKCU} — un conteneur `hive: HKU` produit une violation nommée ; la double-clé HKU+HKCU même `{path|name}` (numlock) est prouvée NON-violation
**And** la sémantique est DOCUMENTÉE (docblock guard + `docs/agent/contract-v1.md` §7.1) : « toutes les ruches utilisateur du poste + .DEFAULT — pas de ciblage par utilisateur sur cette ruche (le ciblage fin reste au Session/HKCU) ; maps HKU/HKCU jumelles valeur-consistantes (piège #5) »
**And** les providers restent Postgres purs (zéro AD/LdapRecord/APCu) et les providers NON touchés restent byte-identiques (tests existants verts sans modification d'attendus).

### AC2 — Handler Go `registry` (service SYSTEM) : fan-out `.DEFAULT` + ruches chargées

**Given** le handler Go `registry` (wiring `main_windows.go`/`companion_windows.go` INCHANGÉ)
**When** il converge un item `hive: HKU`
**Then** il l'applique à `HKU\.DEFAULT` ET à chaque ruche utilisateur chargée (`HKU\<SID>`, SID `S-1-5-21-*` hors `_Classes`, énumérés à CHAQUE appel via le nouvel op REQUIS `RegistryOps.UserHives()` — impl Windows : `registry.USERS` + `ReadSubKeyNames`, filtre piège #7, ordre trié)
**And** `rootKey()` (impl Windows) accepte `HKU`/`HKEY_USERS` → `registry.USERS` ; les ops physiques passent par le path préfixé (`.DEFAULT\<path>`, `<SID>\<path>`) — piège #6
**And** le drift est AGRÉGÉ : `Test` conforme ssi TOUTES les cibles sont conformes (une ruche divergente — y compris une valeur `REG_UNSUPPORTED`, piège #10 — ⇒ non conforme) ; `Apply` ne réécrit/supprime QUE les cibles divergentes (idempotence par cible : 2 passes stables = zéro op)
**And** un item `ensure:"absent"` HKU supprime la valeur nommée dans TOUTES les cibles ; conforme ssi absente PARTOUT
**And** une session ouverte après coup est couverte au cycle suivant (énumération par appel, jamais de cache — test : ajout d'une ruche au fake entre deux passes) ; policy STRICT démontrée À TRAVERS le moteur (`engine.go` INTOUCHÉ, iso `TestRegistryAbsentThroughEngineStrictRedrift`)
**And** l'effort maximal est préservé : une ruche en échec (accès refusé / déchargée en course) n'empêche ni les autres ruches ni les autres clés de converger (première erreur remontée à la fin) ; une erreur d'ÉNUMÉRATION rend l'item inapplicable (erreur franche → `{status: error}` pour le type)
**And** l'identité/hash de l'item est INCHANGÉE par le nombre de sessions (le fan-out est interne : `desiredSpecs`/`identity()` intouchés) ; aucun rafraîchissement shell pour HKU (`isUserHive` inchangé, piège #9).

### AC3 — Lot CD95 : migration de complément `numlock_on_logon`

**Given** la nouvelle migration `database/migrations/2026_07_03_160000_retrofit_numlock_hku_logon_screen.php` (POSTÉRIEURE à `2026_07_03_150000`)
**When** elle est jouée
**Then** la `spec` de la projection `windows/registry` de `numlock_on_logon` gagne la clé `{hive: 'HKU', path: 'Control Panel\\Keyboard', name: 'InitialKeyboardIndicators', type: 'REG_SZ', value: {'on': '2', 'off': '0'}}` (miroir symétrique de la clé HKCU du palier A — PAS de `.DEFAULT` dans le path, piège #6) ; la clé HKCU existante est INCHANGÉE
**And** la migration est IDEMPOTENTE (rejouable, `update` ciblé par `key`, garde `Schema::hasTable`) avec un `down()` restaurant la spec 1-clé du palier A (test idempotence/réversibilité, iso pattern `retrofit_migration_is_idempotent_and_reversible`)
**And** le commentaire de tête documente : (a) le débouché — toute clé `HKCU\Software\Policies\*` diffusable en machine/parc via HKU, le contournement HKLM type fix-Copilot n'est plus le seul chemin ; (b) la discipline double-clé (piège #5 : pas de ciblage user sur une capacité à clé HKU, maps jumelles valeur-consistantes) ; (c) le préalable de PUBLICATION (piège #2)
**And** un test d'intégration provider sur données réelles prouve : `numlock_on_logon` effectif `on` ⇒ le provider Machine émet l'item HKU (`'2'`) ET le provider User émet l'item HKCU (`'2'`) ; effectif `off` ⇒ `'0'` des deux côtés ; le guard passe sur le catalogue réellement seedé (invariant `CapabilitiesSchemaAndSeedTest` étendu au borné des ruches).

### AC4 — Version agent + golden + note de publication

**Given** l'agent modifié
**Then** `agent/shared/version.go` est bumpé (**2.4.1 → 2.5.0**, piège #13) avec entrée de changelog (ruche HKU : fan-out `.DEFAULT` + ruches chargées, op `UserHives`, drift agrégé)
**And** les golden (`state.v1.json`, `report.v1.json`) et les hashes figés jumeaux sont INCHANGÉS, avec justification écrite au Dev Agent Record (piège #1 : HKU = valeur de `hive`, pas un champ — aucun changement de forme du wire)
**And** la note de fin de story impose l'ORDRE : release **2.5.0 publiée manuellement AVANT** la migration AC3 sur /vm — un binaire ≤ 2.4.1 recevant un item HKU met TOUT le type `registry` machine en `{status: error}` (les clés HKLM cessent de converger, piège #2 ; update.sh ne publie jamais seul).

## Tasks / Subtasks

- [x] **Task 1 — Contrat & doc (AC1, AC4)** *(commencer ici : fige la sémantique)*
  - [x] 1.1 `docs/agent/contract-v1.md` §7.1 : ligne `hive` du tableau → `HKLM | HKCU | HKU` avec la sémantique HKU (portée machine/SYSTEM ; fan-out `.DEFAULT` + ruches `S-1-5-21-*` chargées hors `_Classes` ; session ouverte après coup = cycle suivant ; drift agrégé ; pas de ciblage par utilisateur — structurel ; maps HKU/HKCU jumelles valeur-consistantes). §7.6 : note « HKU non admis en `registry_list` (hors scope 35.3, refusé à l'authoring) ». §9 : évolution 35.3 (valeur de domaine ajoutée = mineur ; binaire antérieur → error du type registry machine → publier avant d'armer).
  - [x] 1.2 Vérifier et consigner (Dev Agent Record) : golden + hashes jumeaux INCHANGÉS (aucun recalcul — piège #1) ; `contract_test.go`/`hasher_test.go`/`loop_test.go` : aucun comptage à ajuster.
- [x] **Task 2 — Providers PHP + garde-fou (AC1)**
  - [x] 2.1 `app/Models/CapabilityProjection.php` : `public const HIVE_USERS = 'HKU';` (+ docblock : portée machine, fan-out agent).
  - [x] 2.2 `AbstractCapabilityStateProvider.php` : `protected function handlesHive(string $hive): bool` (défaut : `strcasecmp($hive, $this->hive()) === 0`) ; `expand()` du mécanisme registry utilise `! $this->handlesHive($hive)` à la place de la comparaison directe. NE PAS toucher l'`expand()` de `AbstractRegistryListCapabilityProvider` (filtre direct conservé — piège #11/#12). Docblock : trois ruches, HKU machine-only.
  - [x] 2.3 `RegistryMachineCapabilityProvider.php` : override `handlesHive()` → HKLM OU HKU (docblock : items HKU appliqués par SYSTEM, fan-out agent, jamais Session).
  - [x] 2.4 `CapabilitySpecCollisionGuard.php` : borné des ruches par mécanisme (registry : HKLM|HKCU|HKU ; registry_list : HKLM|HKCU — HKU = violation nommée) ; docblock enrichi (sémantique HKU + discipline double-clé piège #5).
  - [x] 2.5 `tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php` : (a) clé HKU émise par le provider Machine (5 clés, hive HKU) ; (b) JAMAIS par le provider User ; (c) marqueur `$ensure` sur clé HKU → item 4 clés ; (d) UNMANAGED sur clé HKU → rien ; (e) le filtre HKCU/HKLM existant est inchangé (tests existants verts sans modification).
  - [x] 2.6 `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php` : (a) items numlock HKU (machine) + HKCU (session) coexistent — identités distinctes, StateCompiler intouché ; (b) précédence broadcast/parc sur la clé HKU ; (c) compile machine-only : override UserGroup sans effet sur l'item HKU (piège #4).
  - [x] 2.7 Tests guard (fichier des invariants seed OU test unitaire du guard, iso 35.2) : conteneur `registry_list` HKU refusé ; scalaire registry `hive: 'HKX'` refusé ; double-clé HKU+HKCU même `{path|name}` = non-violation.
- [x] **Task 3 — Handler Go (AC2, AC4)**
  - [x] 3.1 `agent/shared/handler_registry.go` : nouvel op REQUIS `UserHives() ([]string, error)` sur `RegistryOps` (doc : cibles du fan-out HKU, `.DEFAULT` + SID chargés, énuméré par appel) ; helper `isUsersHive(hive string) bool` (`HKU`/`HKEY_USERS`) ; fan-out dans `Test`/`Apply` — pour un spec HKU, construire les cibles physiques `{Hive: spec.Hive, Path: <ruche>\<path>}` : Test = toutes conformes, Apply = converger chaque cible divergente (effort maximal par cible ET par clé, `firstErr` unique) ; erreur `UserHives` = erreur franche. Doc de tête mise à jour (fan-out interne, identité logique inchangée). `desiredSpecs`/`identity()`/`isUserHive()` INTOUCHÉS.
  - [x] 3.2 `agent/windows/handler_registry_windows.go` : `rootKey` accepte `HKU`/`HKEY_USERS` → `registry.USERS` (doc D-Q2 mise à jour : la 3e ruche est machine-scope) ; impl `UserHives` : `OpenKey(registry.USERS, "", ENUMERATE_SUB_KEYS)` + `ReadSubKeyNames(-1)`, filtre piège #7 (garder `.DEFAULT` + `^S-1-5-21-` sans suffixe `_Classes`, insensible à la casse), tri, erreurs remontées.
  - [x] 3.3 `agent/shared/handler_registry_test.go` : étendre `fakeRegistryOps` (`userHives []string` + erreur injectable) ; tests — (a) écriture HKU fan-out `.DEFAULT` + 2 SID, re-Test true, 2e Apply = zéro op ; (b) UNE ruche divergente ⇒ Test false, Apply ne réécrit QUE celle-là ; (c) ruche ajoutée au fake entre deux passes ⇒ couverte au cycle suivant (via moteur, STRICT re-drift) ; (d) `ensure:absent` HKU supprime dans toutes les ruches, dont une valeur `REG_UNSUPPORTED` sur une seule ruche (piège #10) ; (e) erreur d'énumération ⇒ error du type ; erreur sur UNE ruche ⇒ isolée, autres cibles + autres clés convergent ; (f) mix HKLM + HKU dans une même passe machine ; (g) aucun NotifyShellChanged pour HKU ; (h) identité/dédup : deux items même `{hku|path|name}` → dernière occurrence (comportement existant conservé).
  - [x] 3.4 `agent/shared/version.go` : bump **2.5.0** + entrée changelog (style 2.3.0/2.4.0, avec l'avertissement binaire antérieur du piège #2).
- [x] **Task 4 — Migration numlock (AC3)**
  - [x] 4.1 `database/migrations/2026_07_03_160000_retrofit_numlock_hku_logon_screen.php` : ajout de la clé HKU à la spec (update ciblé `key = 'numlock_on_logon'`, préservant la clé HKCU), idempotente, `down()` restaure la spec palier A ; commentaires de tête AC3 (débouché HKU, discipline double-clé, publication d'abord).
  - [x] 4.2 `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` : test seed (spec 2 clés, HKU + HKCU, maps symétriques) + idempotence/réversibilité + intégration provider sur données réelles (AC3) + invariant guard étendu au borné des ruches sur le catalogue seedé.
- [x] **Task 5 — Validation finale**
  - [x] 5.1 Tests HÔTE ciblés (php8.4 + sqlite, JAMAIS de run massif) : `CapabilityRegistryProviderTest|CapabilityRegistryCompilationTest`, `CapabilitiesSchemaAndSeedTest`, `ContractV1Test|StateHasherTest` (non-régression golden inchangés).
  - [x] 5.2 Tests Go (`~/go-toolchain/go/bin/go`, hors PATH) : `cd agent && go test ./...` ; `GOOS=windows go build ./...` ; `go vet ./...` (linux ET GOOS=windows).
  - [x] 5.3 `docs/agent/state-providers.md` : note HKU dans la section registry (provider Machine, fan-out agent). `docs/qa/domains/agent.md` : section « Story 35.3 » append-only (scénarios : numlock à l'écran de logon, session ouverte après coup, drift agrégé, binaire antérieur).
  - [x] 5.4 Dev Agent Record : (a) justification golden inchangés (piège #1) ; (b) ⚠️ ORDRE OPÉRATEUR : publier la release **2.5.0** PUIS jouer la migration sur /vm (`php artisan migrate` — jamais auto-appliquée) — l'inverse met le type registry machine en error sur toute la flotte (piège #2).

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `docs/agent/contract-v1.md` | §7.1 ligne `hive` + sémantique HKU ; §7.6 note HKU hors list ; §9 évolution |
| `app/Models/CapabilityProjection.php` | const `HIVE_USERS = 'HKU'` |
| `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php` | `handlesHive()` (défaut inchangé) utilisé par `expand()` registry |
| `app/Services/Agent/Providers/RegistryMachineCapabilityProvider.php` | override `handlesHive()` HKLM+HKU |
| `app/Services/Agent/Providers/CapabilitySpecCollisionGuard.php` | borné des ruches par mécanisme + doc sémantique |
| `tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php` | tests émission HKU Machine-only |
| `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php` | coexistence HKU/HKCU, précédence, machine-only |
| `agent/shared/handler_registry.go` | op `UserHives`, `isUsersHive`, fan-out Test/Apply |
| `agent/shared/handler_registry_test.go` | fake étendu + tests fan-out (a-h) |
| `agent/windows/handler_registry_windows.go` | `rootKey` +HKU, impl `UserHives` |
| `agent/shared/version.go` | bump 2.4.1 → 2.5.0 + changelog |
| `database/migrations/2026_07_03_160000_retrofit_numlock_hku_logon_screen.php` | NOUVEAU — complément numlock (idempotent, réversible) |
| `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` | tests migration + intégration + invariant guard ruches |
| `docs/agent/state-providers.md` | note HKU section registry |
| `docs/qa/domains/agent.md` | section 35.3 append-only |

**NE PAS TOUCHER** : `app/Services/Agent/StateCompiler.php` (D2), `StateHasher.php` + `agent/shared/hasher.go`, `agent/shared/engine.go` (grain par type + STRICT figés), `tests/Fixtures/Agent/state.v1.json` + `report.v1.json` + hashes figés (golden INCHANGÉS, piège #1), `AbstractRegistryListCapabilityProvider`/providers list + `RegistryListHandler` (HKU hors scope list), `RegistryUserCapabilityProvider` (byte-identique), `parseRegistrySpec` (ne valide pas les valeurs de hive — le refus vit dans `rootKey`, comportement voulu), `isUserHive()` (HKU ≠ refresh shell), wiring `main_windows.go`/`companion_windows.go`, `RegistryUpstreamAdapter`/`UpstreamLockCollisionDetector` (canal amont hors scope), seeds/retrofits antérieurs (`2026_07_02_100000`, `2026_07_03_1[0-5]0000`), `sprint-status.yaml` / `backlog.*` / `routes/web.php` (orchestrateur).

### Patterns existants à imiter

- **Fan-out via ops path-préfixé** : les ops `Read/Write/Delete` prennent déjà `(hive, path, …)` — les cibles physiques sont des `RegistrySpec` clonés à `Path` préfixé, AUCUN changement de signature hors le nouvel op `UserHives`.
- **Op additif sur `RegistryOps`** : `Delete` (35.1) puis `ValueNames` (35.2) — même style de doc d'interface (sémantique d'idempotence, erreurs) ; le `fakeRegistryOps` est étendu, jamais dupliqué.
- **Tests à travers le moteur** : `TestRegistryAbsentThroughEngineStrictRedrift` (STRICT, engine intouché) — dupliquer le pattern pour « session ouverte après coup » et le re-drift d'une ruche divergente.
- **Prédicat surchargeable minimal** : iso `mechanism()` de 35.2 (défaut = comportement historique, override dans UNE classe finale, byte-identité gardée par les tests existants).
- **Migration de retrofit** : `2026_07_03_100000_retrofit_ensure_off_on_only_capabilities.php` (update ciblé par `key`, garde `hasTable`, `down()` inverse exact, test idempotence/réversibilité).
- **Chaîne changelog version.go** : entrées 2.3.0/2.4.0 (motif, sémantique, avertissement binaire antérieur).

### Rappels transverses (garde-fous epic)

- Contrat **additif uniquement** (D1) ; ici AUCUN changement de forme → golden intouchés AVEC justification écrite (règle 23.1).
- **Drift policy STRICT** (27.8) — verdict PAR TYPE, drift agrégé multi-ruches inclus.
- **Zéro float** ; **zéro AD/LdapRecord/APCu** dans les providers (critère Keycloak) — la résolution des SID est côté POSTE (énumération HKEY_USERS), jamais en SQL.
- Tests PHP sur l'**HÔTE** (php8.4 + sqlite), **filtres ciblés uniquement** ; tests Go via `~/go-toolchain/go/bin/go`.
- Migration **à rejouer sur /vm** = à SIGNALER, jamais exécutée par le dev — et pour CETTE story, APRÈS la publication de la release (piège #2).
- Toute modif `agent/**` ⇒ bump `agent/shared/version.go` ; **update.sh ne publie jamais seul**.

### Project Structure Notes

- Serveur : tout vit sous `app/Services/Agent/Providers/` (aucun nouveau fichier PHP hors migration) ; le guard reste un service pur exécuté par l'invariant de `CapabilitiesSchemaAndSeedTest`.
- Agent : fan-out dans `agent/shared/` (testé hôte via fake) ; `agent/windows/` n'apporte que `rootKey` élargi + `UserHives` (~30 lignes).
- Aucune UI : la clé HKU est de la donnée de `spec` ; les surfaces capacités existantes ne changent pas.

### References

- [Source: _bmad-output/planning-artifacts/epics-capacites-v2.md#Story-35.3 + #Overview (mur n°3) + #Décisions-structurantes (D1, D2) + #Garde-fous-d'epic + #Couverture-finale (Verr_num)] — autorité de cadrage
- [Source: _bmad-output/implementation-artifacts/35-1-verbe-ensure-present-absent-registry.md + _bmad-output/codeReviews/35-1.md#1 (sentinelle REG_UNSUPPORTED)] — socle delete + présence indépendante du type
- [Source: _bmad-output/implementation-artifacts/35-2-type-registry-list-listes-indexees.md + _bmad-output/codeReviews/35-2.md#2 (dette : guard = invariant de test, futur câblage UI)] — généralisation provider, guard, piège silence
- [Source: agent/shared/handler_registry.go (RegistryOps, desiredSpecs, isUserHive, effort maximal) + agent/windows/handler_registry_windows.go (rootKey, Read REG_UNSUPPORTED) + agent/shared/engine.go (Test error ⇒ {status:error} sans Apply — fondement du piège #2) + agent/shared/loop.go l.454 (le service applique state.Machine)]
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php (expand()/filtre ruche/SPEC_ENSURE) + CapabilitySpecCollisionGuard.php + app/Http/Controllers/Api/V1/Agent/StateController.php (resolveUser — contexte machine-only du service)]
- [Source: database/migrations/2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php — exclusion « Numlock écran de logon (HKU\.DEFAULT) » + clé HKCU numlock à mirrorer]
- [Source: docs/agent/contract-v1.md §7.1 (ligne hive), §8 (type absent = non géré), §9 (règle d'évolution)]
- Mémoires projet : `project_hkcu_policies_not_writable_by_companion`, `project_capability_value_map_symmetric_rule`, `project_registry_apply_effect_next_logon`, `project_agent_handler_not_in_published_binary`, `project_drift_policy_strict_only`, `feedback_no_overengineered_choices`, `feedback_agent_edit_bump_version`.

## Dépendances

- **En amont (intra-epic) :**
  - **35.1 — DONE (mergée main)** : le chemin `ensure:absent` multi-ruches réutilise `RegistryOps.Delete` + la sentinelle `REG_UNSUPPORTED` (review 35.1 #1) ;
  - **35.2 — DONE (mergée main)** : la généralisation du provider abstrait (`mechanism()`, visibilités protected) et `CapabilitySpecCollisionGuard` sont le socle des extensions PHP de cette story.
- **Indépendante de 35.4 (UI override UserGroup) et 35.5 (photo_viewer)** — toutes deux livrées ; ne toucher à aucun de leurs fichiers. 35.6 (privilege) gated, orthogonale.
- **En aval** : aucune story ne dépend de 35.3.

## Recommandation Modèle Dev

**fable** — prescription explicite de l'epic (garde-fous : « fable pour les stories touchant l'agent Go », 35.3 nommée) et mémoire `feedback_epic23_model_fable5`. Profil-type confirmé par l'exploration : sémantique de convergence délicate (fan-out 1 logique → N physiques avec drift agrégé, idempotence par cible, races logoff), discipline de contrat cross-language (une valeur de domaine ajoutée SANS toucher les golden — savoir ne PAS bumper est aussi une discipline 23.1) et un piège de déploiement à fort impact (item HKU sur binaire antérieur = type registry machine en error). Aucune raison de dévier.

## Dev Agent Record

### Agent Model Used

claude-fable-5 (Fable 5) — prescription epic + mémoire `feedback_epic23_model_fable5`.

### Debug Log References

- Go : `cd agent && ~/go-toolchain/go/bin/go test ./...` → ok (shared 2.5s, +9 tests HKU) ; `GOOS=windows go build ./...` + `go vet ./...` (linux ET GOOS=windows) → OK.
- PHP hôte (filtres ciblés, php8.4 + sqlite) : `CapabilityRegistryProviderTest|CapabilityRegistryCompilationTest|CapabilityRegistryListProviderTest|CapabilityRegistryListCompilationTest|CapabilitiesSchemaAndSeedTest|ContractV1Test|StateHasherTest|StateCompilerTest` → **136 passed (1093 assertions), 0 failed**.
- Ajustement de test unique en cours de dev : `TestRegistryHkuEnumerationErrorIsFrankButOtherKeysConverge` — dans une passe mixte, `Test` court-circuite en `(false, nil)` sur une dérive HKLM rencontrée AVANT l'item HKU (ordre d'identité `hklm…` < `hku…`) ; l'assertion d'erreur franche est faite sur l'item HKU seul (le verdict moteur reste correct : Apply remonte l'erreur d'énumération). Comportement early-return EXISTANT, non modifié.

### Completion Notes List

- **Justification golden INCHANGÉS (piège #1, règle 23.1)** : HKU est une VALEUR de domaine du champ `hive` existant — aucun champ ni type nouveau, la forme du wire (items 5 clés / 4 clés) est déjà figée par les golden actuels : rien à recalculer, rien de plus à figer. `state.v1.json`, `report.v1.json`, `FROZEN_STATE_HASH` (PHP) et `frozenStateHash` (Go) sont byte-identiques (vérifié : `git diff` vide sur `tests/Fixtures/Agent/`, ContractV1Test/StateHasherTest + `contract_test.go`/`hasher_test.go`/`loop_test.go` verts SANS ajustement de comptage). La couverture parse/convergence HKU vit dans les tests dédiés du handler (9 tests Go) et des providers (5 tests PHP).
- **⚠️ ORDRE OPÉRATEUR NON NÉGOCIABLE (piège #2)** : publier la release agent **2.5.0** MANUELLEMENT (update.sh ne publie jamais seul) et attendre que la flotte l'ait remontée (version rapportée au check-in fait foi), **PUIS** jouer `php artisan migrate` sur /vm (migration `2026_07_03_160000_retrofit_numlock_hku_logon_screen`, jamais auto-appliquée). L'inverse : `numlock_on_logon` est en broadcast `on` → l'item HKU part à la flotte entière dès la migration ; un binaire ≤ 2.4.1 le PARSE puis `rootKey()` le refuse → `{status: error}` pour le type `registry` machine ENTIER, SANS Apply → toutes les clés HKLM de la flotte cessent de converger. Consigné aussi au changelog `version.go`, au §7.1/§9 du contrat, au commentaire de migration et au runbook QA (scénario 35.3.1).
- **Serveur (AC1)** : prédicat surchargeable minimal `handlesHive()` (défaut = comparaison historique, byte-identité des providers non touchés prouvée par les tests existants verts sans modification d'attendus) ; SEULE surcharge dans `RegistryMachineCapabilityProvider` (HKLM + HKU). `RegistryUserCapabilityProvider`, `AbstractRegistryListCapabilityProvider` et les deux providers list : INTOUCHÉS (diff vide). `exclusiveKey()`/`StateCompiler`/`StateHasher` intouchés — coexistence machine/session des items numlock jumeaux + précédence broadcast/parc sur la clé HKU + compile machine-only (override UserGroup sans effet) prouvées par tests de compilation.
- **Guard (AC1)** : borné des ruches par mécanisme (`registry` ∈ HKLM|HKCU|HKU ; `registry_list` ∈ HKLM|HKCU — conteneur HKU = violation NOMMÉE, ruche inconnue type `HKX` = refusée « clé silencieusement morte ») ; invariant vert sur le catalogue réellement seedé ; double-clé HKU+HKCU numlock prouvée non-violation ; docblock porte la sémantique HKU + discipline double-clé (piège #5 — règle documentaire, pas de garde-fou runtime, iso `feedback_no_overengineered_choices`).
- **Agent (AC2)** : nouvel op REQUIS `RegistryOps.UserHives()` (3e op additif après Delete 35.1 / ValueNames 35.2 — implémenteurs : windows réel + `fakeRegistryOps` ÉTENDU, jamais dupliqué) ; fan-out interne à `Test`/`Apply` via `hkuEnumeration` (mémo PAR APPEL, jamais entre cycles) + `fanOutUserHives` (clones à Path préfixé — signature des ops inchangée) ; extraction `applyTarget()` (corps par-cible byte-équivalent au corps historique) ; `desiredSpecs`/`identity()`/`parseRegistrySpec`/`isUserHive`/`engine.go` INTOUCHÉS. Drift agrégé, idempotence par cible, absent multi-ruches (dont Kind `REG_UNSUPPORTED` sur UNE ruche parmi N), erreur par ruche isolée / erreur d'énumération franche, zéro shell-refresh HKU, session ouverte après coup prouvée À TRAVERS le moteur (STRICT). Impl Windows : `rootKey` +`HKU`/`HKEY_USERS`→`registry.USERS`, `UserHives` = `OpenKey(USERS, "", ENUMERATE_SUB_KEYS)` + `ReadSubKeyNames` + filtre strict (`.DEFAULT` + `^S-1-5-21-` hors `_Classes`, insensible à la casse, trié).
- **Migration (AC3)** : `2026_07_03_160000` — clé HKU miroir SYMÉTRIQUE de la clé HKCU du palier A (`{on:'2', off:'0'}`, invariant maps symétriques), path SANS `.DEFAULT` (piège #6), clé HKCU INCHANGÉE (byte-identique après down(), testé) ; idempotente (up() rejoué = no-op, ne touche même pas updated_at si rien ne change) + réversible (down() = spec 1-clé palier A) ; commentaire de tête = débouché `HKCU\Software\Policies\*` + discipline double-clé + préalable de publication.
- **Consommateurs annexes (piège #14)** : grep de contrôle fait — `UpstreamLockCollisionDetector`/severance normalisent `strtolower(hive|path|name)` (hive-agnostiques, `hku` passe tel quel) ; `RegistryUpstreamAdapter` = convention du canal amont, hors scope. Zéro modification.
- **Limite documentée** : comptes AAD (`S-1-12-1-…`) hors périmètre du fan-out (parc AD) — notée dans la doc de l'op et le contrat.

### File List

- `docs/agent/contract-v1.md` — §7.1 ligne `hive` (3 valeurs + sémantique HKU) + note « Portée → acteur » + avertissement binaire antérieur ; §7.6 HKU non admis en registry_list ; §9 règle « valeur de domaine ajoutée » (golden inchangés justifiés)
- `app/Models/CapabilityProjection.php` — const `HIVE_USERS = 'HKU'` + docblock
- `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php` — prédicat `handlesHive()` (défaut historique) consommé par l'`expand()` registry
- `app/Services/Agent/Providers/RegistryMachineCapabilityProvider.php` — override `handlesHive()` HKLM+HKU + docblock sémantique
- `app/Services/Agent/Providers/CapabilitySpecCollisionGuard.php` — borné des ruches par mécanisme (const `ALLOWED_HIVES`) + docblock sémantique HKU/double-clé
- `agent/shared/handler_registry.go` — op requis `UserHives()`, `isUsersHive()`, `hkuEnumeration` (mémo par appel), `fanOutUserHives()`, fan-out Test/Apply, extraction `applyTarget()`, doc de tête
- `agent/windows/handler_registry_windows.go` — `rootKey` +HKU→`registry.USERS`, impl `UserHives()` + `isHkuFanOutTarget()` (filtre strict trié), import `sort`, doc de tête
- `agent/shared/handler_registry_test.go` — `fakeRegistryOps` étendu (`userHives`/`userHivesErr`/`userHivesCnt` + `UserHives()`) ; 9 tests HKU (a–h : fan-out+idempotence, drift agrégé par cible, session après coup via moteur STRICT, absent multi-ruches + REG_UNSUPPORTED, erreur énumération franche + isolation par ruche, mix HKLM+HKU, zéro shell refresh, dédup dernière occurrence)
- `agent/shared/version.go` — bump 2.4.1 → **2.5.0** + entrée changelog (avertissement binaire antérieur)
- `database/migrations/2026_07_03_160000_retrofit_numlock_hku_logon_screen.php` — NOUVEAU : clé HKU numlock (idempotente, réversible, commentaires AC3)
- `tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php` — 5 tests HKU (émission Machine 5 clés, jamais User, $ensure 4 clés, UNMANAGED, providers list muets sur conteneur HKU)
- `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php` — 3 tests (coexistence jumeaux machine/session + identités distinctes, précédence broadcast/parc sur clé HKU, compile machine-only : override UserGroup sans effet)
- `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` — 3 tests guard (conteneur HKU refusé, HKX refusé, double-clé numlock non-violation sur données seedées) + 3 tests migration (seed 2 clés symétriques, idempotence/réversibilité, intégration providers réels on/off)
- `docs/agent/state-providers.md` — note HKU section registry (bullet dédié)
- `docs/qa/domains/agent.md` — section « Story 35.3 » append-only (5 scénarios + checklist)
- `_bmad-output/implementation-artifacts/35-3-ruche-hku-ecriture-system.md` — story (checkboxes, Dev Agent Record, statut)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — ligne `35-3-ruche-hku-ecriture-system` → review

### Change Log

- 2026-07-03 — Story 35.3 implémentée intégralement (AC1–AC4) : ruche `HKU` troisième valeur de `hive` (serveur machine-only via `handlesHive()`, guard borné des ruches, fan-out agent `.DEFAULT`+ruches chargées via op `UserHives`, drift agrégé, migration numlock écran de logon, agent 2.5.0, golden inchangés justifiés). Tests : PHP 136/136 ciblés hôte, Go `./...` + GOOS=windows build/vet OK. Statut → review.
