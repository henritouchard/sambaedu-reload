# Story 35.2 : Type `registry_list` — listes à sous-clés indexées `\N`

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-capacites-v2.md (Epic 35 ne figure PAS dans epics.md). -->

## Story

En tant que **référent numérique**,
je veux **imposer des listes registre à entrées numérotées (extensions forcées, exécutables interdits)**,
afin de **remplacer les GPO CD95 « ExtensionPix » et « Blocages élèves » par des capacités**.

## Contexte & intention

Deuxième mur de l'Epic 35 (Capacités v2 — GPO spéciales CD95) : les policies Windows à **sous-valeurs indexées** (`ExtensionInstallForcelist\1..N`, `DisallowRun\1..N`) sont hors d'atteinte du mécanisme `registry` actuel — l'interpréteur de `spec` n'émet que des items à `name` FIXE, et surtout **rien ne réconcilie les entrées surnuméraires** : si l'admin retire une entrée de la liste, la valeur `\3` orpheline resterait sur le poste à vie.

**Ce que la story livre** — la chaîne complète d'un NOUVEAU type de contrat :

1. **Contrat v1** : type `registry_list` additif (`semantics: exclusive`), payload `{hive, path, entry_type, values}` — `values` = liste ORDONNÉE de chaînes, `entry_type ∈ REG_SZ|REG_EXPAND_SZ`. Golden + doc §7 bumpés (hashes jumeaux PHP/Go).
2. **Providers serveur** : deux providers (Machine/HKLM, User/HKCU — mêmes casiers que `registry`), `exclusiveKey() = {hive|path}` normalisé : **la maille la plus spécifique gagne la clé-conteneur ENTIÈRE** (D2 — jamais de fusion de listes entre mailles), `StateCompiler` intouché.
3. **Garde-fou d'authoring** : une clé-conteneur ciblée à la fois par un item `registry` scalaire ET un `registry_list` ⇒ refus explicite (pas de collision silencieuse — les deux types ont des `exclusiveKey` incomparables, le compilateur ne peut PAS arbitrer).
4. **Handler Go `registry_list`** (D3 — l'agent POSSÈDE la clé-conteneur) : écrit les valeurs nommées `1..N` dans l'ordre, **supprime toute autre valeur au nom numérique** de la clé (s'appuie sur `RegistryOps.Delete` livré en 35.1) ; liste vide ⇒ purge des entrées numérotées. Portées Machine (service SYSTEM) et Session (compagnon), policy STRICT.
5. **Seed** : `pix_extension_forced` (Machine — Forcelist Chrome + Edge) et `blocked_executables` (Session, cible = override UserGroup élèves) — **première capacité bi-projection** (D5) : projection `registry` (flag `DisallowRun=1`, `$ensure: absent` en off) + projection `registry_list` (les entrées, dont `cmd.exe` qui remplace `DisableCMD` iso-intention CD95).

**Pourquoi maintenant** : 35.1 (verbe `ensure`, socle delete) est LIVRÉE et mergée sur main — la réconciliation D3 en dépendait. 35.2 clôt les GPO CD95 « ExtensionPix » et l'essentiel de « Blocages élèves » (le solde : armement UserGroup 35.4, RDP 35.6 gated).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — nouveau TYPE ≠ nouveau champ : le binaire antérieur IGNORE en SILENCE.** Contrairement à 35.1 (item `absent` → `{status: error}` visible), un agent ≤ 2.3.0 qui reçoit un type `registry_list` l'**ignore sans aucun statut** (contrat §8 / `engine.go` : type sans handler = log DEBUG, rien au rapport). Symptôme : « réglage sans effet, zéro erreur » — c'est le piège « handler absent du binaire publié » sous sa forme la plus sournoise. D'où : bump `2.4.0` OBLIGATOIRE + note de publication explicite (update.sh ne publie jamais seul).

2. **Piège #2 — StateCompiler INTOUCHÉ (D2), la maille gagne le CONTENEUR ENTIER.** Zéro ligne dans `app/Services/Agent/StateCompiler.php`. `exclusiveKey() = {hive|path}` minuscules (2 segments, PAS de `name`) : deux mailles qui définissent la même liste se disputent la clé-conteneur COMPLÈTE par la précédence existante — la plus spécifique REMPLACE toute la liste, il n'y a **jamais** d'union/merge d'entrées entre mailles. Un test de compilation le prouve (broadcast `['a','b']` battu par override de parc `['c']` → la cible est `['c']`, pas `['a','b','c']`).

3. **Piège #3 — généralisation MINIMALE du provider abstrait.** `AbstractCapabilityStateProvider::itemsFor()` code en dur `MECHANISM_REGISTRY` (whereHas + with) et `type()` le retourne ; `expand()`/`resolveKeyValue()`/`UNMANAGED` sont `private`. Refactor minimal-diff : introduire `protected function mechanism(): string` (défaut `MECHANISM_REGISTRY`), l'utiliser dans `type()` et les DEUX filtres de `itemsFor()` ; passer `expand()` et `resolveKeyValue()`/`UNMANAGED` en `protected` pour qu'un intermédiaire `AbstractRegistryListCapabilityProvider` les surcharge/réutilise. Le mécanisme `registry` doit rester **byte-identique** (les tests existants `CapabilityRegistryProviderTest`/`CapabilityRegistryCompilationTest` sont la garde de non-régression). PAS de refonte de hiérarchie au-delà (règle « pas de choix sur-conçu »).

4. **Piège #4 — payload EXACTEMENT 4 clés `{hive, path, entry_type, values}`, ordre significatif.** Ni `name`, ni `type`, ni id/key de capacité (invariant central 27.12). La canonicalisation du hash **ne trie pas les listes** (contrat §4) : l'ordre de `values` est porteur de sens — le même contenu dans un autre ordre = un autre hash = re-convergence. `values` = `list<string>` STRICTE (cast défensif, zéro float §4.1).

5. **Piège #5 — liste vide `[]` ≠ « non émis ».** `values: []` est une **vraie valeur** : « purger toutes les entrées numérotées » (le « off » honnête d'une liste). La sentinelle UNMANAGED (clé de map absente pour la valeur effective) reste « ne rien émettre = ne pas gérer ». Le marqueur `$ensure` de 35.1 n'est **PAS supporté** en `registry_list` : toute forme assoc inattendue dans la map ⇒ conteneur non émis (défensif, jamais d'exception au render) — l'idiome de suppression EST la liste vide.

6. **Piège #6 — réconciliation D3 : les noms NUMÉRIQUES seulement, jamais la clé.** L'agent possède, dans la clé-conteneur, les valeurs dont le nom est composé UNIQUEMENT de chiffres (`^[0-9]+$`). Canon = `strconv.Itoa(1..N)` : `"01"`, `"007"`, `"12"` hors canon ⇒ SUPPRIMÉS (comparaison de chaînes stricte : `"01" ≠ "1"`). Les valeurs à nom NON numérique de la même clé ne sont **JAMAIS touchées** ; la clé-conteneur elle-même n'est **JAMAIS supprimée** (même à liste vide — des valeurs voisines non gérées peuvent y vivre).

7. **Piège #7 — nouvel op `RegistryOps.ValueNames` (additif).** Le handler doit ÉNUMÉRER les valeurs de la clé — l'interface `RegistryOps` (`handler_registry.go`) n'a que Read/Write/Delete. Ajouter `ValueNames(hive, path string) ([]string, error)` : clé ABSENTE ⇒ `(nil, nil)` (pas une erreur — idempotence, iso `Delete`) ; err = accès refusé/ruche invalide. Étendre le `fakeRegistryOps` EXISTANT de `handler_registry_test.go` (même package), ne PAS créer un second fake.

8. **Piège #8 — sentinelle `REG_UNSUPPORTED` (review 35.1 #1) : ne pas régresser.** `Read` (impl Windows) rend `present=true` + Kind `"REG_UNSUPPORTED"` pour une valeur d'un type hors contrat (REG_BINARY…). Pour `registry_list` : une valeur numérotée existante de type exotique est **présente et divergente** → réécrite au `entry_type` cible (si dans le canon) ou supprimée (si surnuméraire). Tests du fake avec Kind exotique obligatoires — c'est exactement le trou que la review 35.1 a bouché.

9. **Piège #9 — verdict PAR TYPE (grain 27.8 FIGÉ), `engine.go` intouché.** La convergence interne est par clé-conteneur en **effort maximal** (une clé en échec n'empêche pas les autres, première erreur remontée à la fin — iso `RegistryHandler.Apply`), mais le rapport porte UN statut pour le type `registry_list` (worst) — l'AC epic « un statut par item » se lit à ce grain, comme pour `registry`. Le dual-scope (pix en machine, blocked en session) est fusionné par `MergeReportItemsByType` (`loop.go`) : RIEN à faire. Le warning `engine.go` « type exclusif avec N items » est un existant partagé avec `registry` (hash rapporté = dernier item) : ne pas y toucher.

10. **Piège #10 — golden = hashes jumeaux + comptages.** +1 item `registry_list` en portée machine ⇒ recalculer le hash d'item (`StateHasher::hashItem`), `ContractV1Test::FROZEN_STATE_HASH` (PHP) **ET** `frozenStateHash` (`agent/shared/hasher_test.go`, MÊME valeur), justification écrite dans les chaînes de commentaires des DEUX fichiers (règle 23.1). Comptages : `contract_test.go` machine 4→5 ; `hasher_test.go` 12→13 items. `report.v1.json` INCHANGÉ avec justification (les items de rapport `{type,status,hash[,detail]}` ne portent pas de payload ; le nouveau type entre dans `ReportRequest` via `Rule::in(StateContract::RESOURCE_TYPES)` — constante additive, zéro autre changement d'ingestion).

11. **Piège #11 — garde-fou = ÉGALITÉ stricte de conteneur, et `blocked_executables` n'est PAS une collision avec lui-même.** La collision se définit sur `{hive|path}` normalisé : un item `registry` scalaire dont le `path` ÉGALE un conteneur `registry_list` (peu importe son `name` — il vivrait DANS la clé possédée par l'agent). Le flag `…\Policies\Explorer` + `name=DisallowRun` vs le conteneur `…\Policies\Explorer\DisallowRun` : **paths DIFFÉRENTS** (parent vs enfant) → pas de collision ; le test du garde-fou doit prouver ce cas nominal ET le cas refusé (scalaire `path == conteneur`).

12. **Piège #12 — bi-projection D5 : deux LIGNES de projection, chaque provider ne voit que la sienne.** L'unique `(capability_id, os, mechanism)` autorise `registry` + `registry_list` sur la même capacité ; `itemsFor()` filtre `whereHas`/`with` par mécanisme puis prend `projections->first()` → le provider registry ne voit que le flag, le provider list que le conteneur. Le seed de `blocked_executables` écrit DEUX `updateOrInsert` de projection. Un test d'intégration provider le prouve sur données réelles.

13. **Piège #13 — HKCU : shellRefresh + effet au logon suivant.** Un changement EFFECTIF (écriture OU suppression) sur un conteneur HKCU déclenche `registryNotifier.NotifyShellChanged()` (même gate que `registry` : zéro changement = zéro notification). `DisallowRun` est lu par l'Explorer au **logon suivant** (mémoire projet « clés HKCU Explorer au logon d'après ») : à documenter, ce n'est pas un bug de convergence.

14. **Piège #14 — `entry_type` borné.** `REG_SZ | REG_EXPAND_SZ` uniquement (le contrat le fige ; les listes indexées Windows sont des chaînes). Côté agent : autre valeur ⇒ enveloppe invalide ⇒ `{status: error}` pour le type. Côté serveur : le garde-fou d'authoring refuse en amont ; au render, conteneur non émis (défensif). Défaut de `spec` : `REG_SZ`.

## Décisions de design (tranchées — cadrage epic)

1. **D1 — contrat additif** : `registry_list` s'AJOUTE à `StateContract::RESOURCE_TYPES` (constante additive, liste fermée consommée par `ReportRequest`) ; `semantics: exclusive` ; bump mineur documenté §9. Agents antérieurs : type ignoré silencieusement (§8) — assumé, motive bump + release.
2. **D2 — StateCompiler intouché** : `exclusiveKey() = strtolower(hive).'|'.strtolower(path)` sur les providers list — la maille la plus spécifique gagne le conteneur ENTIER.
3. **D3 — l'agent possède la clé-conteneur** : réconciliation au niveau de la clé (écrire `1..N`, supprimer les noms numériques hors canon), delete 35.1 réutilisé (`RegistryOps.Delete`).
4. **D5 — bi-projection** : une capacité peut porter `registry` ET `registry_list` (même OS) ; `blocked_executables` inaugure.
5. **« Off » honnête d'une liste = liste vide** (`'off' => []` dans la map) : purge des entrées numérotées — vraie action (invariant on/off), distincte de la sentinelle UNMANAGED. Le marqueur `$ensure` n'existe pas en `registry_list`.
6. **Noms numériques = digits-only**, canon = `"1".."N"` (strconv) ; comparaison de noms STRICTE (pas de normalisation `"01"→"1"`).
7. **Garde-fou d'authoring = service pur + invariant test** : l'authoring est catalogue-first (migrations/seeds — aucune UI de création de spec n'existe) ; l'enforcement vit dans un petit service réutilisable (futur geste UI) exécuté par un invariant `CapabilitiesSchemaAndSeedTest` sur les données réellement seedées.
8. **Spec `registry_list`** : même enveloppe `{ "keys": [ … ] }` que `registry`, chaque entrée = `{hive, path, entry_type, values}` où `values` est un littéral liste OU une map valeur-capacité → liste (résolution via `resolveKeyValue()` réutilisé : `array_is_list` disambiguïse, clé de map absente ⇒ UNMANAGED).

## Acceptance Criteria

### AC1 — Contrat v1 : type `registry_list` publié (D1)

**Given** le contrat `se5.desired-state/v1`
**When** le type `registry_list` est publié
**Then** `StateContract::RESOURCE_TYPES` gagne `'registry_list'` (ajout additif — la constante, `ReportRequest` et l'ingestion 24.1 l'acceptent sans autre changement)
**And** le payload est EXACTEMENT `{hive, path, entry_type, values}` : `hive ∈ HKLM|HKCU`, `path` = clé-conteneur, `entry_type ∈ REG_SZ|REG_EXPAND_SZ`, `values` = liste ORDONNÉE de chaînes (vide admise = purge) — jamais de `name`, jamais d'id de capacité, zéro float
**And** `docs/agent/contract-v1.md` est mis à jour : §7 (liste des identifiants), nouvelle sous-section payload `registry_list` (tableau + exemple + sémantique D3 : propriété de la clé-conteneur, noms numériques, liste vide, valeurs non numériques jamais touchées, « la maille la plus spécifique gagne le conteneur entier »), §9 (évolution : nouveau type = mineur ; agent antérieur = type ignoré silencieusement, publication requise)
**And** le golden `state.v1.json` gagne UN item `registry_list` en portée machine (conteneur Forcelist Chrome de `pix_extension_forced`) avec justification écrite dans `ContractV1Test.php` ET `hasher_test.go` ; `FROZEN_STATE_HASH` (PHP) = `frozenStateHash` (Go) recalculés à l'identique ; comptages ajustés (`contract_test.go` machine 4→5, `hasher_test.go` 12→13) ; `report.v1.json` INCHANGÉ avec justification écrite (le rapport ne porte pas de payload).

### AC2 — Providers serveur : deux providers `registry_list`, compilateur intouché (D2)

**Given** une capacité active portant une projection `windows/registry_list`
**When** les providers l'expansent
**Then** `RegistryListMachineCapabilityProvider` (scope Machine) n'émet que les conteneurs `HKLM`, `RegistryListUserCapabilityProvider` (scope Session) que les conteneurs `HKCU` — mêmes casiers que `registry`, câblés dans `AgentServiceProvider` (2 lignes, enrobage `UpstreamAwareProvider` iso autres providers, aucun adaptateur amont)
**And** `exclusiveKey(payload) = {hive|path}` minuscules (2 segments) : un test de compilation (StateCompiler INTOUCHÉ) prouve qu'un override de parc REMPLACE la liste ENTIÈRE du broadcast pour ce conteneur (jamais d'union), et que deux conteneurs distincts s'accumulent
**And** la map valeur-capacité → liste fonctionne comme pour `registry` (`'on' => ['a','b']` ; valeur effective absente de la map ⇒ conteneur NON émis — sentinelle UNMANAGED) ; `'off' => []` émet `values: []` (purge) ; littéral liste = toujours émis ; forme assoc inattendue (dont `$ensure`) ⇒ non émis défensif
**And** le mécanisme `registry` reste **byte-identique** après la généralisation (`mechanism()` paramétré, `expand()` protected) — les tests existants `CapabilityRegistryProviderTest` + `CapabilityRegistryCompilationTest` passent SANS modification de leurs attendus
**And** les providers restent Postgres purs (zéro AD/LdapRecord/APCu), payload 4 clés sans fuite d'id, zéro float.

### AC3 — Garde-fou d'authoring : collision scalaire/liste refusée

**Given** l'ensemble des projections `windows` du catalogue (mécanismes `registry` + `registry_list`)
**When** une clé de `spec` `registry` scalaire a un `{hive|path}` normalisé ÉGAL au `{hive|path}` d'un conteneur `registry_list` (toutes capacités confondues)
**Then** la validation d'authoring REFUSE avec une erreur explicite nommant les deux capacités et le conteneur (pas de collision silencieuse au poste)
**And** l'enforcement est un service pur réutilisable (données en entrée, violations en sortie) + un invariant `CapabilitiesSchemaAndSeedTest` exécuté sur les données réellement seedées (authoring catalogue-first)
**And** le garde-fou valide aussi `entry_type ∈ REG_SZ|REG_EXPAND_SZ` et `values` bien formées (listes de chaînes dans littéraux et maps)
**And** le cas `blocked_executables` (flag `…\Policies\Explorer` name `DisallowRun` vs conteneur `…\Policies\Explorer\DisallowRun`) est prouvé NON-collision (paths parent/enfant distincts).

### AC4 — Handler Go `registry_list` : réconciliation de la clé-conteneur (D3)

**Given** le handler Go `registry_list` (nouveau, instancié par le SERVICE SYSTEM pour HKLM et le COMPAGNON pour HKCU — une entrée `"registry_list"` dans chaque map `Handlers`)
**When** il converge un conteneur `{hive, path, entry_type, values}`
**Then** `Test` est conforme ssi : les valeurs nommées `"1".."N"` existent avec le Kind `entry_type` et les contenus `values[i-1]` (comparaison NFC), ET aucune AUTRE valeur au nom numérique (digits-only, comparaison stricte — `"01"` ≠ `"1"`) n'existe dans la clé ; `values: []` ⇒ conforme ssi aucune valeur numérique (clé absente = conforme)
**And** `Apply` écrit les `1..N` divergents/manquants dans l'ordre (via `RegistryOps.Write`, création de clé au besoin), supprime chaque nom numérique hors canon (via `RegistryOps.Delete`, socle 35.1) — JAMAIS une valeur à nom non numérique, JAMAIS la clé-conteneur ; idempotent (2 passes stables = zéro op) ; effort maximal par conteneur (première erreur remontée après avoir tout tenté)
**And** l'énumération passe par le NOUVEL op `RegistryOps.ValueNames(hive, path)` (clé absente ⇒ liste vide sans erreur ; impl Windows : `OpenKey(QUERY_VALUE)` + `ReadValueNames`, `ErrNotExist` ⇒ nil) ; le `fakeRegistryOps` existant est ÉTENDU
**And** une valeur numérotée de Kind exotique (`REG_UNSUPPORTED`, review 35.1 #1) est vue présente → réécrite ou supprimée selon le canon
**And** la policy STRICT est démontrée À TRAVERS le moteur (`engine.go` INTOUCHÉ) : entrée surnuméraire (ré)apparue ⇒ `drift` + suppression, re-drift au cycle suivant si elle revient ; un changement effectif HKCU déclenche le rafraîchissement shell (même gate que `registry`)
**And** payload invalide (`hive/path` vides, `entry_type` inconnu, `values` non-liste-de-chaînes) ⇒ enveloppe invalide ⇒ `{status: error}` pour le type ; le verdict rapporté reste PAR TYPE (grain 27.8), `MergeReportItemsByType` couvrant le dual-scope sans changement.

### AC5 — Seed : `pix_extension_forced` + `blocked_executables` (bi-projection D5)

**Given** la nouvelle migration de seed (datée 2026-07-03, POSTÉRIEURE au retrofit `2026_07_03_100000`), pattern iso lot CD95 (`updateOrInsert` par `key` puis par `(capability_id, os, mechanism)`, idempotente, garde `hasTable`, `down()` par suppression des `key`)
**When** elle est jouée
**Then** `pix_extension_forced` naît : toggle, opt-in (`default_value = 'unmanaged'`, options `[unmanaged: 'Non géré', on: 'Forcée']`), UNE projection `windows/registry_list` à DEUX conteneurs HKLM (portée Machine) : Chrome `SOFTWARE\Policies\Google\Chrome\ExtensionInstallForcelist` et Edge `SOFTWARE\Policies\Microsoft\Edge\ExtensionInstallForcelist`, `entry_type: REG_SZ`, `'on' => ['pgpjajcmfbfdmcgjlbiengidaknopaok;https://clients2.google.com/service/update2/crx']` (format Forcelist `id;update_url` — l'extension Pix vient du Chrome Web Store, y compris pour Edge ; iso-GPO CD95)
**And** `blocked_executables` naît : toggle, opt-in (`default_value = 'unmanaged'`, options `[unmanaged: 'Non géré', on: 'Activé', off: 'Désactivé (valeurs supprimées)']` — libellés convention sujet+état, « Non géré » réservé à la sentinelle), cible métier = override UserGroup élèves (armement = donnée/35.4, PAS le seed), DEUX projections windows (D5) :
- `registry` : `{hive: HKCU, path: Software\Microsoft\Windows\CurrentVersion\Policies\Explorer, name: DisallowRun, type: REG_DWORD, value: {'on' => 1, 'off' => {'$ensure': 'absent'}}}` (tree restrictions user-writable — PAS `HKCU\Software\Policies`, non écrivable par le compagnon)
- `registry_list` : conteneur `{hive: HKCU, path: Software\Microsoft\Windows\CurrentVersion\Policies\Explorer\DisallowRun, entry_type: REG_SZ, values: {'on' => ['powershell.exe','powershell_ise.exe','pwsh.exe','mstsc.exe','cmd.exe'], 'off' => []}}` — `cmd.exe` remplace `DisableCMD` (iso-intention CD95 : la GPO laissait les scripts autorisés, bloquer l'exécutable interactif suffit ; zéro broker d'élévation)
**And** le « off » est une VRAIE action combinée : flag supprimé (marqueur 35.1) + entrées numérotées purgées (liste vide) ; l'invariant `on_off_capabilities_emit_a_real_value_for_off` est ÉTENDU au mécanisme `registry_list` (off valide = liste, y compris vide, OU marqueur `$ensure` côté registry)
**And** des tests d'intégration provider sur données réelles prouvent : pix `on` ⇒ 2 items `registry_list` HKLM (broadcast machine) ; blocked `on` ⇒ 1 item `registry` (flag) via provider User + 1 item `registry_list` (5 entrées, ordre préservé) via provider list User ; blocked `off` ⇒ 1 item `ensure: absent` + 1 item `values: []` ; `unmanaged` ⇒ rien.

### AC6 — Version agent + note de publication

**Given** l'agent modifié
**Then** `agent/shared/version.go` est bumpé (2.3.0 → **2.4.0**) avec entrée de changelog (type `registry_list`, réconciliation de clé-conteneur, `RegistryOps.ValueNames`)
**And** la note de fin de story rappelle : **un binaire ≤ 2.3.0 IGNORE le type en silence** (contrat §8 — aucun statut au rapport, aucune erreur visible) → la release 2.4.0 DOIT être publiée manuellement (update.sh ne publie jamais seul), sinon « réglage sans effet » sans symptôme.

## Tasks / Subtasks

- [x] **Task 1 — Contrat & golden files (AC1)** *(commencer ici : fige le wire format)*
  - [x] 1.1 `app/Services/Agent/StateContract.php` : `'registry_list'` dans `RESOURCE_TYPES` (constante additive — `ReportRequest::rules()` suit automatiquement).
  - [x] 1.2 `docs/agent/contract-v1.md` : §7 liste des identifiants ; nouvelle sous-section payload `registry_list` (tableau 4 clés + exemple + sémantique D3 complète : exclusif PAR CLÉ-CONTENEUR, noms numériques possédés, liste vide = purge, non-numériques intouchés, jamais la clé) ; §9 mention (nouveau type = mineur, agent antérieur ignore silencieusement).
  - [x] 1.3 `tests/Fixtures/Agent/state.v1.json` : +1 item machine `{"type":"registry_list","semantics":"exclusive","payload":{"hive":"HKLM","path":"SOFTWARE\\Policies\\Google\\Chrome\\ExtensionInstallForcelist","entry_type":"REG_SZ","values":["pgpjajcmfbfdmcgjlbiengidaknopaok;https://clients2.google.com/service/update2/crx"]},"hash":"<recalculé via StateHasher::hashItem>"}`.
  - [x] 1.4 `ContractV1Test.php` : `FROZEN_STATE_HASH` recalculé + justification 35.2 dans la chaîne de commentaires (règle 23.1) ; vérifier les assertions de structure (nouveau type accepté).
  - [x] 1.5 `agent/shared/hasher_test.go` : `frozenStateHash` = MÊME valeur + justification ; comptage 12→13. `agent/shared/contract_test.go` : machine 4→5 (commentaire motivé). `loop_test.go` (`mustReadGolden`) : vérifier, normalement aucun ajustement (le golden est un corps HTTP non compté).
  - [x] 1.6 Justification écrite `report.v1.json` INCHANGÉ (aucun payload au rapport ; type validé par `Rule::in(RESOURCE_TYPES)`).
- [x] **Task 2 — Providers serveur + garde-fou (AC2, AC3)**
  - [x] 2.1 `app/Models/CapabilityProjection.php` : `public const MECHANISM_REGISTRY_LIST = 'registry_list';` (+ docblock).
  - [x] 2.2 `AbstractCapabilityStateProvider.php` : `protected function mechanism(): string` (défaut `MECHANISM_REGISTRY`) utilisé par `type()` et les deux filtres de `itemsFor()` ; `expand()` private→**protected** ; `resolveKeyValue()` + `UNMANAGED` private→**protected** ; docblocks mis à jour (le provider abstrait devient la base des mécanismes de capacité, registry = défaut historique).
  - [x] 2.3 NOUVEAU `AbstractRegistryListCapabilityProvider.php` (extends AbstractCapabilityStateProvider) : `mechanism()` = MECHANISM_REGISTRY_LIST ; `exclusiveKey()` = `{hive|path}` minuscules ; `expand()` : itère `spec.keys`, filtre `hive()` du provider, résout `values` via `resolveKeyValue()` (UNMANAGED ⇒ continue ; assoc inattendue dont `$ensure` ⇒ continue défensif ; résolu non-liste ⇒ continue) ; `entry_type` défaut `REG_SZ`, hors `{REG_SZ, REG_EXPAND_SZ}` ⇒ non émis ; émet EXACTEMENT `{hive, path, entry_type, values: list<string> castée}`.
  - [x] 2.4 NOUVEAUX `RegistryListMachineCapabilityProvider.php` (scope Machine, hive HKLM) + `RegistryListUserCapabilityProvider.php` (scope Session, hive HKCU) — coquilles iso providers registry.
  - [x] 2.5 `app/Providers/AgentServiceProvider.php` : +2 lignes dans le tableau de providers du `StateCompiler` (avec commentaire 35.2), enrobées `UpstreamAwareProvider::wrap` comme les autres. NE PAS toucher `UpstreamLockCollisionDetector` (canal amont = registry only, hors scope, iso piège 35.1 #12) ni ajouter d'adaptateur amont.
  - [x] 2.6 NOUVEAU garde-fou d'authoring (service pur, ex. `app/Services/Agent/Providers/CapabilitySpecCollisionGuard.php`) : entrée = projections windows (registry + registry_list), sortie = violations (collision `{hive|path}` scalaire↔conteneur, `entry_type` invalide, `values` mal formées) ; message d'erreur nommant capacités + conteneur.
  - [x] 2.7 NOUVEAU `tests/Unit/Services/Agent/CapabilityRegistryListProviderTest.php` : (a) map on→liste émise 4 clés ; (b) `'off' => []` émet `values: []` ; (c) UNMANAGED n'émet rien ; (d) littéral liste toujours émis ; (e) assoc inattendue/`$ensure` non émis ; (f) `entry_type` invalide non émis ; (g) filtre par ruche (HKLM vs HKCU) ; (h) pas de fuite d'id, exactement 4 clés, strings only ; (i) `exclusiveKey` = 2 segments minuscules.
  - [x] 2.8 Test de compilation (`CapabilityRegistryCompilationTest.php` étendu OU nouveau fichier dédié, StateCompiler INTOUCHÉ) : override de parc remplace la liste ENTIÈRE du broadcast (jamais d'union) ; deux conteneurs distincts s'accumulent ; UserGroup bat Broadcast (blocked_executables est Session/UserGroup-ciblée).
  - [x] 2.9 Invariant `CapabilitiesSchemaAndSeedTest` : `no_container_is_targeted_by_both_registry_scalar_and_registry_list` (guard sur données seedées réelles) + cas non-collision `blocked_executables` (parent/enfant).
- [x] **Task 3 — Handler Go `registry_list` (AC4)**
  - [x] 3.1 `agent/shared/handler_registry.go` : méthode `ValueNames(hive, path string) ([]string, error)` sur l'interface `RegistryOps` (doc : clé absente ⇒ nil,nil).
  - [x] 3.2 NOUVEAU `agent/shared/handler_registry_list.go` : `RegistryListHandler{Ops RegistryOps, Log *Logger}` ; parse payload (4 clés, `entry_type` borné, `values` []string, vide admis) ; dédoublonnage par identité de conteneur `{hive|path}` minuscules (dernière occurrence, ordre trié — iso `desiredSpecs`) ; helpers noms numériques (digits-only) + canon strconv ; `Test`/`Apply` selon AC4 (effort maximal, idempotence, shellRefresh HKCU via `registryNotifier` sur changement effectif, réutilise `RegistrySpec`/`RegistryValue`/`isUserHive` existants pour l'écriture) ; doc de tête (D3 : le handler POSSÈDE les noms numériques de la clé, ne touche jamais le reste).
  - [x] 3.3 `agent/windows/handler_registry_windows.go` : impl `ValueNames` (`registry.OpenKey(root, path, registry.QUERY_VALUE)` + `ReadValueNames(-1)` ; `registry.ErrNotExist` ⇒ nil,nil).
  - [x] 3.4 `agent/windows/main_windows.go` (map SYSTEM) + `agent/windows/companion_windows.go` (map compagnon) : entrée `"registry_list": &shared.RegistryListHandler{Ops: &registryOps{log: logger}, Log: logger}` avec commentaire 35.2.
  - [x] 3.5 `agent/shared/handler_registry_test.go` : `fakeRegistryOps.ValueNames` (+ compteurs). NOUVEAU `handler_registry_list_test.go` : (a) écriture 1..N ordonnée + relecture conforme ; (b) surnuméraire `"3"` supprimé, non-numérique `"NoDriveTypeAutoRun"` intouché ; (c) `"01"`/`"007"` hors canon supprimés ; (d) liste vide purge les numérotés, clé absente = compliant ; (e) idempotence 2 passes = zéro op ; (f) valeur numérotée Kind `REG_UNSUPPORTED` réécrite/supprimée ; (g) re-drift STRICT à travers le moteur (iso `TestRegistryAbsentThroughEngineStrictRedrift`) ; (h) shellRefresh HKCU sur changement effectif, silence sinon ; (i) payloads invalides ⇒ error ; (j) mix multi-conteneurs avec isolation des erreurs ; (k) la clé-conteneur n'est JAMAIS supprimée.
  - [x] 3.6 `agent/shared/version.go` : bump `2.4.0` + entrée changelog.
- [x] **Task 4 — Seed du lot (AC5)**
  - [x] 4.1 NOUVELLE migration `database/migrations/2026_07_03_110000_seed_capabilities_registry_list_lot.php` : les 2 capacités + 3 lignes de projection (pix: 1 registry_list ; blocked: 1 registry + 1 registry_list), pattern CD95 exact, idempotente, `down()` par `whereIn('key', …)->delete()` ; commentaires de tête : bi-projection D5, off honnête, cmd.exe≈DisableCMD, armement UserGroup = donnée/35.4.
  - [x] 4.2 `CapabilitiesSchemaAndSeedTest.php` : tests seed (options/défauts/projections des 2 capacités, bi-projection = 2 lignes) + extension de `on_off_capabilities_emit_a_real_value_for_off` au mécanisme `registry_list` (off = liste y compris vide OU marqueur) + tests d'intégration provider AC5 (on/off/unmanaged, ordre des 5 entrées préservé).
- [x] **Task 5 — Validation finale**
  - [x] 5.1 Tests HÔTE ciblés (php8.4 + sqlite, JAMAIS de run massif) : `ContractV1Test|StateHasherTest`, `CapabilityRegistryProviderTest|CapabilityRegistryListProviderTest|CapabilityRegistryCompilationTest`, `CapabilitiesSchemaAndSeedTest`.
  - [x] 5.2 Tests Go (`~/go-toolchain/go/bin/go`) : `cd agent && go test ./...` ; `GOOS=windows go build ./...` ; `go vet ./...` (linux ET GOOS=windows).
  - [x] 5.3 `docs/agent/state-providers.md` : section `registry_list` (iso section registry : mécanisme, bi-projection D5, exclusiveKey conteneur, collapse machine+session en une ligne d'état `agent_resource_states(poste, 'registry_list')`). `docs/qa/domains/agent.md` : section « Story 35.2 » append-only (scénarios + checklist).
  - [x] 5.4 Signaler en Dev Agent Record : migration seed **à rejouer sur /vm** (`php artisan migrate` — jamais auto-appliquée) + release agent **2.4.0 à publier manuellement** (binaire antérieur = type ignoré EN SILENCE).

- [x] **Task 6 — SCOPE AJOUTÉ (orchestrateur) : valeur PAR DÉFAUT d'une clé (`name: ""`) sur le type `registry`** *(livré dans le même bump 2.4.0 — besoin 35.5 : `Applications\photoviewer.dll\shell\open\command`)*
  - [x] 6.1 `agent/shared/handler_registry.go` : `parseRegistrySpec` accepte `name` PRÉSENT et vide (`""` = valeur par défaut de la clé, `(Default)`) ; l'ABSENCE de la clé `name` (ou un non-string) reste une enveloppe invalide. Doc de la fonction : comportement documenté des API registre (RegQueryValueEx/RegSetValueEx/RegDeleteValue via x/sys — `GetValue("")`/`SetStringValue("")`/`DeleteValue("")` ciblent la valeur par défaut, aucun cas particulier handler).
  - [x] 6.2 Vérif ops Windows : `handler_registry_windows.go` relaie le `name` verbatim aux API (aucune garde) ; commentaire ajouté sur `ValueNames` (la valeur par défaut est énumérée sous `""` — jamais numérique, jamais touchée par la réconciliation de liste).
  - [x] 6.3 Côté PHP : vérifié — ni `AbstractCapabilityStateProvider::expand()` (émet `name` tel quel, `''` inclus), ni `StateHasher` (canonicalisation générique), ni `ReportRequest` ne refusent un `name` vide. Aucun correctif nécessaire ; test additif `empty_name_default_value_key_is_emitted_and_hashable` (CapabilityRegistryProviderTest).
  - [x] 6.4 Doc contrat §7.1 (ligne `name` du tableau : `""` légitime, clé toujours présente) + tests Go dédiés : `TestRegistryDefaultValueNameParsesWriteAndAbsent` (parse write/absent/clé absente) et `TestRegistryDefaultValueNameConvergesTestApplyDelete` (Test/Apply/delete d'une valeur par défaut via le fake) ; les 2 anciens cas « name vide = invalide » remplacés par « name absent/non-string = invalide ».

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `app/Services/Agent/StateContract.php` | `RESOURCE_TYPES` += `'registry_list'` |
| `docs/agent/contract-v1.md` | §7 liste + sous-section payload registry_list + §9 |
| `docs/agent/state-providers.md` | section `registry_list` |
| `tests/Fixtures/Agent/state.v1.json` | +1 item registry_list (machine, Forcelist Chrome) |
| `tests/Unit/Services/Agent/ContractV1Test.php` | `FROZEN_STATE_HASH` + justification |
| `agent/shared/hasher_test.go` | `frozenStateHash` jumeau + justification + comptage 12→13 |
| `agent/shared/contract_test.go` | comptage machine 4→5 |
| `app/Models/CapabilityProjection.php` | const `MECHANISM_REGISTRY_LIST` |
| `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php` | `mechanism()` + visibilités protected (registry byte-identique) |
| `app/Services/Agent/Providers/AbstractRegistryListCapabilityProvider.php` | NOUVEAU — expand/exclusiveKey list |
| `app/Services/Agent/Providers/RegistryListMachineCapabilityProvider.php` | NOUVEAU (Machine/HKLM) |
| `app/Services/Agent/Providers/RegistryListUserCapabilityProvider.php` | NOUVEAU (Session/HKCU) |
| `app/Services/Agent/Providers/CapabilitySpecCollisionGuard.php` | NOUVEAU — garde-fou d'authoring (nom/placement ajustables) |
| `app/Providers/AgentServiceProvider.php` | +2 providers au StateCompiler |
| `tests/Unit/Services/Agent/CapabilityRegistryListProviderTest.php` | NOUVEAU |
| `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php` (ou fichier dédié) | précédence conteneur entier |
| `agent/shared/handler_registry.go` | `RegistryOps.ValueNames` (additif) |
| `agent/shared/handler_registry_list.go` | NOUVEAU handler |
| `agent/shared/handler_registry_list_test.go` | NOUVEAU |
| `agent/shared/handler_registry_test.go` | extension `fakeRegistryOps.ValueNames` |
| `agent/windows/handler_registry_windows.go` | impl `ValueNames` |
| `agent/windows/main_windows.go` | +1 entrée handler (SYSTEM) |
| `agent/windows/companion_windows.go` | +1 entrée handler (compagnon) |
| `agent/shared/version.go` | bump 2.4.0 + changelog |
| `database/migrations/2026_07_03_110000_seed_capabilities_registry_list_lot.php` | NOUVEAU seed (2 capacités, 3 projections) |
| `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` | seed tests + invariants + garde-fou |
| `docs/qa/domains/agent.md` | section 35.2 append-only |

**NE PAS TOUCHER** : `app/Services/Agent/StateCompiler.php` (D2), `StateHasher.php` + `agent/shared/hasher.go` (canonicalisation générique), `agent/shared/engine.go` (grain par type + STRICT figés), `RegistryHandler` (Test/Apply du type `registry` — seul l'interface `RegistryOps` gagne un op), `RegistryUpstreamAdapter` / `UpstreamLockCollisionDetector` (contrat amont = registry only, hors scope), seeds/retrofits antérieurs (`2026_06_18_*`, `2026_07_02_100000`, `2026_07_03_100000` — le lot 35.2 est une NOUVELLE migration), `sprint-status.yaml` / `backlog.data.js` / `backlog.html` / `routes/web.php` (orchestrateur), fichiers des stories 35.4/35.5 (créées en parallèle).

### Patterns existants à imiter

- **Chaîne de justification golden** : commentaires numérotés `ContractV1Test.php` (l.44-131, entrée 35.1 en dernier) et `hasher_test.go` (l.11-80) — ajouter l'entrée 35.2 dans le MÊME style, hash jumeau vérifié croisé.
- **Providers registry** : `RegistryMachineCapabilityProvider`/`RegistryUserCapabilityProvider` (coquilles scope+hive) et `AbstractCapabilityStateProvider::expand()` (filtre ruche, résolution map/littéral, défensif sans exception au render) — l'expand list suit la même discipline.
- **Handler Go** : `RegistryHandler` (`desiredSpecs` dédoublonnage+tri, effort maximal, `firstErr`, gate `shellRefresh`, `registryNotifier`) et son test à travers le moteur (`TestRegistryAbsentThroughEngineStrictRedrift`) ; `fakeRegistryOps` en mémoire à ÉTENDRE.
- **Seed** : `2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php` (doctrine des défauts de diffusion en tête, `$optionsUnmanagedOn`, `$userPoliciesExplorer`, `updateOrInsert` double niveau) — le lot 35.2 reprend le pattern EXACT, avec 2 projections pour `blocked_executables`.
- **Constantes marqueur 35.1** : `AbstractCapabilityStateProvider::SPEC_ENSURE`/`ENSURE_ABSENT` publiques — le seed du flag `DisallowRun` réutilise la FORME (`['$ensure' => 'absent']`, littéraux dupliqués dans la migration, iso décision 35.1 : les migrations ne référencent pas le code applicatif).
- **Wiring compilateur** : `AgentServiceProvider` — « ajouter un type = ajouter UNE ligne » (ici deux), commentaire de story attenant, enrobage `UpstreamAwareProvider` systématique.

### Rappels transverses (garde-fous epic)

- Contrat **additif uniquement** (D1) ; golden AVEC justification (règle 23.1) ; hashes figés JUMEAUX PHP⇄Go.
- **Drift policy STRICT** (27.8) — pas de mode ; verdict PAR TYPE.
- **Zéro float** (`values` = strings) ; **zéro AD/LdapRecord/APCu** dans les providers (critère Keycloak).
- Tests PHP sur l'**HÔTE** (php8.4 + sqlite), **filtres ciblés uniquement** ; tests Go via `~/go-toolchain/go/bin/go` (hors PATH).
- Migration **à rejouer sur /vm** = à SIGNALER (Dev Agent Record), jamais exécutée par le dev.
- Toute modif `agent/**` ⇒ bump `agent/shared/version.go` ; **update.sh ne publie jamais seul** — et ici le symptôme d'un binaire antérieur est un SILENCE, pas une erreur.

### Project Structure Notes

- Serveur : nouveaux providers sous `app/Services/Agent/Providers/` (foyer du mécanisme) ; le garde-fou d'authoring y vit aussi (service pur sans état — pas de nouveau dossier).
- Agent : logique de réconciliation dans `agent/shared/` (testée hôte, fake), `agent/windows/` n'apporte que `ValueNames` (+2 lignes de wiring).
- Aucune UI dans cette story : les 2 capacités apparaissent automatiquement dans les surfaces capacités existantes (options data-driven) ; l'armement élèves de `blocked_executables` = Story 35.4 (geste UserGroup) ou donnée.
- `machine_user` n'est pas concerné (deux portées seulement, iso registry).

### References

- [Source: _bmad-output/planning-artifacts/epics-capacites-v2.md#Story-35.2 + #Décisions-structurantes (D1, D2, D3, D5) + #Garde-fous-d'epic + #Overview (découverte DisableCMD≈DisallowRun)] — autorité de cadrage
- [Source: _bmad-output/implementation-artifacts/35-1-verbe-ensure-present-absent-registry.md — socle delete, pièges golden/byte-identité] + [_bmad-output/codeReviews/35-1.md#1 — sentinelle REG_UNSUPPORTED : présence indépendante du type réel]
- [Source: docs/agent/contract-v1.md#7 (identifiants figés), #7.1 (payload registry + trois régimes), #4 (canonicalisation : listes NON triées), #4.1 (zéro float), #8 (type absent = non géré — l'agent antérieur ignore un type inconnu), #9 (règle d'évolution)]
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php — itemsFor()/expand()/resolveKeyValue()/exclusiveKey()/SPEC_ENSURE ; app/Providers/AgentServiceProvider.php — registry des providers]
- [Source: agent/shared/handler_registry.go (RegistryOps, desiredSpecs, effort maximal, registryNotifier) + agent/shared/engine.go (grain par type, dispatch, §8) + agent/shared/dropcollect.go#MergeReportItemsByType + agent/windows/{main,companion}_windows.go (maps Handlers)]
- [Source: database/migrations/2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php — pattern de seed + `$userPoliciesExplorer` (tree restrictions user-writable) + exclusions palier B (ExtensionInstallForcelist/DisallowRun)]
- [Source: app/Http/Requests/Api/V1/Agent/ReportRequest.php — `Rule::in(StateContract::RESOURCE_TYPES)`]
- Mémoires projet : `project_capability_value_map_symmetric_rule` (off proposé = vraie valeur), `project_capability_label_subject_state_convention`, `project_registry_apply_effect_next_logon`, `project_hkcu_policies_not_writable_by_companion`, `project_agent_handler_not_in_published_binary`, `feedback_no_overengineered_choices`.

## Dépendances

- **En amont (intra-epic) : 35.1 — DONE (mergée sur main, review corrigée).** La réconciliation D3 s'appuie sur `RegistryOps.Delete` (suppression idempotente de valeur nommée, `ErrNotExist` ⇒ nil) et sur la sentinelle `REG_UNSUPPORTED` de `Read` (une valeur de type exotique est VUE) ; le « off » du flag `DisallowRun` réutilise le marqueur `$ensure` (constantes publiques `SPEC_ENSURE`/`ENSURE_ABSENT`).
- **En parallèle** : 35.4 (UI override UserGroup — armera `blocked_executables` pour les élèves) et 35.5 (seed pur) sont indépendantes de 35.2 ; ne toucher à AUCUN de leurs fichiers.
- **En aval** : aucune story ne dépend de 35.2 (35.3 HKU et 35.6 privilege sont orthogonales).

## Recommandation Modèle Dev

**fable** — prescription explicite de l'epic (garde-fous : « fable pour les stories touchant l'agent Go », 35.2 nommée) et mémoire `feedback_epic23_model_fable5`. La story est le profil-type : nouveau TYPE de contrat cross-language (RESOURCE_TYPES ⇄ handler Go ⇄ golden files avec hashes figés jumeaux à recalculer à l'identique), sémantique de réconciliation délicate (canon numérique, propriété de clé-conteneur, idempotence) et généralisation d'un provider abstrait sous contrainte de byte-identité — la discipline de contrat prime sur tout. Aucune raison de dévier.

## Dev Agent Record

### Agent Model Used

claude-fable-5 (Fable 5) — prescription epic (stories agent Go).

### Debug Log References

- Hashes golden recalculés via le `StateHasher` RÉEL (script scratchpad, hôte php8.4) :
  item `registry_list` = `e0a9c51ec51ec3ed14ae76c542e3957e3e5aae20e55efdd0234c72a60e9b0759`,
  état = `fe8eb6eae22994ed2e35c45a726d9b53c5a562fca34fedbf63aebd25ba43fb44` — vérifiés
  JUMEAUX par `TestHashStateGoldenMatchesFrozenHash` (Go) et `state_hash_is_frozen_regression_guard` (PHP).
- Incident d'environnement worktree (résolu par l'orchestrateur en cours de dev) :
  `vendor/` était un SYMLINK vers le repo principal → l'autoload composer (classmap
  optimisé, chemins realpath) résolvait `App\`/`Tests\` vers le repo PRINCIPAL (tests
  exécutant un autre code + base_path principal). Remplacé par une copie hardlink par
  l'orchestrateur ; le contournement temporaire dans `tests/bootstrap.php` a été REVERTI
  (fichier inchangé). Tous les tests relancés après correction.

### Completion Notes List

1. **AC1 — Contrat.** `'registry_list'` ajouté à `RESOURCE_TYPES` (PHP) et `ResourceTypes`
   (Go) — `ReportRequest` (Rule::in) suit sans autre changement. Golden `state.v1.json` :
   +1 item machine (conteneur Forcelist **Chrome**) ; comptages ajustés (`contract_test.go`
   machine 4→5 + types 10→11, `hasher_test.go` 12→13) ; hashes figés jumeaux recalculés
   avec justification 23.1 dans les DEUX chaînes de commentaires. **`report.v1.json`
   INCHANGÉ** (justifié : les items de rapport `{type,status,hash[,detail]}` ne portent
   aucun payload ; le type entre à l'ingestion par la constante additive). Doc contrat :
   §7 liste + **§7.6** (payload + sémantique D3 complète + garde-fou + piège silence) +
   §9 (« type ajouté = mineur, agent antérieur ignore EN SILENCE »).
2. **⚠️ Écart assumé vs Task 1.3 de la story** : le golden porte
   `values: ["pgpjajcmfbfdmcgjlbiengidaknopaok"]` (id SEUL) et non `id;update_url` —
   données iso-GPO EXACTES fournies par l'orchestrateur (vérifiées à la source
   `Registry.xml` CD95) : Chrome = id seul (Web Store = défaut), SEUL Edge porte
   `id;https://clients2.google.com/service/update2/crx`. Le golden illustre le conteneur
   Chrome du seed réel — cohérence seed⇄golden préférée au littéral de la story.
3. **AC2 — Providers.** Généralisation MINIMALE de `AbstractCapabilityStateProvider`
   (`mechanism()` protected défaut registry, utilisé par `type()` + les 2 filtres
   d'`itemsFor()` ; `expand()`/`resolveKeyValue()`/`UNMANAGED` private→protected) —
   mécanisme `registry` byte-identique (28 tests existants verts SANS modification
   d'attendus). Nouveau `AbstractRegistryListCapabilityProvider` (exclusiveKey 2 segments,
   expand list : littéral/map→liste, `[]` émis, `$ensure`/assoc/scalaire non émis
   défensif, entry_type borné défaut REG_SZ) + coquilles Machine/User + 2 lignes wiring
   `AgentServiceProvider` (enrobage UpstreamAwareProvider iso autres ; canal amont
   registry-only NON touché).
4. **AC3 — Garde-fou.** `CapabilitySpecCollisionGuard` (service PUR : projections en
   entrée, violations nommées en sortie — collision {hive|path} scalaire↔conteneur
   normalisée, entry_type borné, values bien formées). Invariant
   `no_container_is_targeted_by_both_registry_scalar_and_registry_list` sur les données
   RÉELLEMENT seedées + cas nominal parent/enfant blocked_executables prouvé NON-collision
   + cas refusé fabriqué (violation nommant capacités + conteneur).
5. **AC4 — Handler Go.** `RegistryListHandler` (shared, pur) : parse strict 4 clés,
   dédoublonnage par identité de conteneur (dernière occurrence, ordre trié), Test/Apply
   D3 (canon strconv strict `"01"≠"1"`, non-numériques et clé-conteneur JAMAIS touchées,
   liste vide = purge, clé absente = compliant), effort maximal intra ET inter-conteneurs,
   Kind exotique `REG_UNSUPPORTED` réécrit/supprimé, shellRefresh HKCU sur changement
   effectif (même gate que registry). Nouvel op additif `RegistryOps.ValueNames` (fake
   EXISTANT étendu + impl Windows `OpenKey(QUERY_VALUE)+ReadValueNames`, ErrNotExist ⇒
   nil,nil). Handler câblé dans les maps SYSTEM (`main_windows.go`) et compagnon
   (`companion_windows.go`). STRICT re-drift prouvé À TRAVERS le moteur (engine.go
   INTOUCHÉ). 16 tests Go dédiés.
6. **AC5 — Seed.** Migration `2026_07_03_110000_seed_capabilities_registry_list_lot`
   (pattern CD95 exact, idempotente + réversible — testé) : `pix_extension_forced`
   (1 projection registry_list, 2 conteneurs HKLM, données iso-GPO : Chrome id seul /
   Edge id;url) + `blocked_executables` (PREMIÈRE bi-projection D5 : flag registry
   `$ensure:absent` en off + conteneur registry_list 5 entrées ordonnées, off = `[]`).
   Invariant on/off ÉTENDU au mécanisme registry_list (off = liste, vide incluse).
   Tests d'intégration provider sur données réelles : pix on ⇒ 2 items HKLM ; blocked
   on ⇒ flag + 5 entrées (chaque provider ne voit que SA projection) ; off ⇒
   `ensure:absent` + `values:[]` ; unmanaged ⇒ rien.
7. **AC6 + Task 6 — Version + name "".** Bump `2.3.0 → 2.4.0` (changelog complet).
   `parseRegistrySpec` accepte `name: ""` (valeur PAR DÉFAUT `(Default)` — la clé `name`
   doit être PRÉSENTE, absence/non-string = invalide) ; comportement API documenté en
   commentaire (Get/Set/DeleteValue("") ciblent la default value) ; PHP vérifié sans
   garde (test additif provider + hash) ; doc §7.1 ; tests Go parse + Test/Apply/delete
   via le fake. La réconciliation list ne touche jamais `""` (non numérique).
8. **⚠️ À FAIRE HORS STORY (opérateur)** :
   - migration seed **à rejouer sur /vm** (`php artisan migrate` — jamais auto-appliquée) ;
   - release agent **2.4.0 à PUBLIER MANUELLEMENT** (update.sh ne publie jamais seul) —
     un binaire ≤ 2.3.0 ignore `registry_list` EN SILENCE (aucun statut, aucune erreur).

### File List

Serveur (PHP) :
- app/Services/Agent/StateContract.php (M — +'registry_list')
- app/Models/CapabilityProjection.php (M — const MECHANISM_REGISTRY_LIST)
- app/Services/Agent/Providers/AbstractCapabilityStateProvider.php (M — mechanism() + visibilités protected)
- app/Services/Agent/Providers/AbstractRegistryListCapabilityProvider.php (A)
- app/Services/Agent/Providers/RegistryListMachineCapabilityProvider.php (A)
- app/Services/Agent/Providers/RegistryListUserCapabilityProvider.php (A)
- app/Services/Agent/Providers/CapabilitySpecCollisionGuard.php (A)
- app/Providers/AgentServiceProvider.php (M — +2 providers)
- database/migrations/2026_07_03_110000_seed_capabilities_registry_list_lot.php (A)

Agent (Go) :
- agent/shared/contract.go (M — ResourceTypes +registry_list)
- agent/shared/handler_registry.go (M — RegistryOps.ValueNames + parseRegistrySpec name "")
- agent/shared/handler_registry_list.go (A — RegistryListHandler)
- agent/shared/version.go (M — 2.4.0 + changelog)
- agent/windows/handler_registry_windows.go (M — impl ValueNames)
- agent/windows/main_windows.go (M — +handler registry_list SYSTEM)
- agent/windows/companion_windows.go (M — +handler registry_list compagnon)

Golden & docs :
- tests/Fixtures/Agent/state.v1.json (M — +1 item registry_list machine)
- docs/agent/contract-v1.md (M — §7, §7.1 name "", §7.6, §9)
- docs/agent/state-providers.md (M — section registry_list)
- docs/qa/domains/agent.md (M — section Story 35.2 append-only)

Tests :
- tests/Unit/Services/Agent/ContractV1Test.php (M — FROZEN_STATE_HASH + justification)
- agent/shared/hasher_test.go (M — frozenStateHash jumeau + 12→13)
- agent/shared/contract_test.go (M — machine 4→5, types 10→11)
- agent/shared/handler_registry_test.go (M — fake ValueNames, cas name ""/name absent, tests default value)
- agent/shared/handler_registry_list_test.go (A — 16 tests)
- tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php (M — test additif name "")
- tests/Unit/Services/Agent/CapabilityRegistryListProviderTest.php (A — 11 tests)
- tests/Unit/Services/Agent/CapabilityRegistryListCompilationTest.php (A — 5 tests, compilateur intouché)
- tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php (M — invariant off étendu + 8 tests 35.2)

NON touchés (garde-fous vérifiés) : app/Services/Agent/StateCompiler.php,
app/Services/Agent/StateHasher.php, agent/shared/hasher.go, agent/shared/engine.go,
tests/Fixtures/Agent/report.v1.json, RegistryUpstreamAdapter/UpstreamLockCollisionDetector,
seeds antérieurs, sprint-status.yaml, backlog.*, routes/web.php.
