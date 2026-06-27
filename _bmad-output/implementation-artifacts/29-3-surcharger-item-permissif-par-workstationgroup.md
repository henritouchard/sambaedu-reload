# Story 29.3: Surcharger un item permissif par workstationGroup

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **refnum** (administrateur local SE5),
I want **pouvoir surcharger un item imposé en état « permissif » par le contrat amont au niveau d'un `workstationGroup` précis — l'override prenant réellement effet à l'état compilé pour ce groupe — tandis que la même surcharge reste refusée sur un item « verrouillé »**,
so that **je garde une marge d'adaptation locale là où l'amont l'autorise (FR4), sans jamais pouvoir défaire un verrou (FR3)**.

> Story de **relaxation contrôlée** : elle est le **pendant exact** de la 29.2. Là où 29.2 a transformé le « défait en silence » d'un item `locked` en **refus explicite à l'écriture**, 29.3 transforme le « **override écrit mais sans effet** » d'un item `permissive` en **override qui MORD réellement au compilé**. Le geste d'écriture (override de capacité par parc) **existe déjà** (27.12) et 29.2 le laisse **déjà passer** pour un item `permissive` (le gate `modify-capability` ne refuse QUE `locked`). Le **trou** de 29.3 n'est donc PAS l'écriture — c'est la **résolution** : aujourd'hui un item `permissive` est injecté à la maille `Upstream` (rang -1, la plus spécifique) **exactement comme `locked`**, donc il bat l'override local du refnum. L'override est sauvegardé en base mais **n'apparaît jamais** dans l'état effectif → promesse creuse.

> **La couture est explicitement documentée dans le code (à fermer ICI).** [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php L.50-54] : « ⚠️ COUTURE Epic 29 — la **relaxation permissive** (un override `workstationGroup` local battant un item `permissive`) n'est PAS implémentée ici : à ce stade `permissive` se comporte EXACTEMENT comme `locked` vis-à-vis du local. La relaxation viendra en injectant `permissive` à un rang battable, ou via un mécanisme dédié — décision d'Epic 29. » **29.3 EST cette décision.**

> **⚠️ Décision Henri (2026-06-26) — pas de compat ascendante exigée** : aucun environnement de prod à préserver (seul invariant intangible = enrôlement controlHub). Les garde-fous « non-régression » ci-dessous visent le **bon design** (sans contrat amont, le comportement 27.12/28.3 est strictement inchangé — NFR3 ; avec un item `locked`, 28.3/29.2 restent intacts), pas la rétrocompat d'appelants exotiques. [Source: mémoires projet — zero_prod_publish_is_test, no_legacy_transition_state]

## Contexte du code (constat vérifié 2026-06-27)

**Ce qui existe déjà (à RÉUTILISER, ne rien réinventer) :**

- **Injection des items amont au compilé (28.3)** — `UpstreamContractSource::ensureResolved()` lit le contrat `active`, filtre `target_type = instance` et `enforcement_state IN (locked, permissive)`, puis crée pour CHAQUE item un `StateCandidate` étiqueté **`StateMaille::Upstream`** — **sans distinguer `locked` de `permissive`**. Court-circuit NFR3 si aucun contrat actif (≤ 1 requête, jamais la table `items`). C'est le **point d'injection exact** que 29.3 doit faire diverger selon l'enforcement. [Source: UpstreamContractSource.php L.129-156 — `whereIn(enforcement_state, [locked, permissive])` puis `maille: StateMaille::Upstream` en dur L.150]
- **Précédence des mailles (28.3)** — `StateCompiler::specificity()` : `Upstream => -1` (rang MINIMUM = plus spécifique = gagne, car `resolveExclusiveWinner` fait `min(...)`). La maille `Upstream` bat donc **toute** maille locale. C'est l'UNIQUE `match()` exhaustif sur `StateMaille` (tout nouveau `case` DOIT y être ajouté sous peine d'`UnhandledMatchError` en prod — garde-fou testé). [Source: app/Services/Agent/StateCompiler.php L.308-316 (`min`), L.393-404 (`specificity`)]
- **Maille interne, jamais sérialisée** — `StateMaille` est un enum **interne au serveur** : il **n'apparaît jamais** dans le JSON `se5.desired-state/v1` (le payload reste `{hive,path,name,type,value}`). Ajouter un `case` ne touche **pas** le contrat agent ni le `FROZEN_STATE_HASH`/golden (à VÉRIFIER en test). [Source: app/Enums/StateMaille.php L.7-24]
- **Override de capacité PAR PARC (= par `workstationGroup`)** — `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (Livewire SFC). Écrit `capability_assignments` avec `assignable_type = WorkstationGroup::class`, `assignable_id = groupId`, colonne `value` (`saveOverride()` L.221-298, `updateOrInsert` L.273). 29.2 y a déjà branché le garde `authorizeUpstream()` → `Gate::authorize('modify-capability', $capability)` en tête de `openAdd`/`openEdit`/`saveOverride`/`removeOverride` (L.175,193,249,300) : **refuse `locked`, laisse passer `permissive`**. [Source: capabilities-tab.blade.php L.74-76, 273-298, 372-378]
- **Émission de l'override par maille (provider)** — `AbstractCapabilityStateProvider` projette, pour chaque assignation `capability_assignments` applicable au contexte, un candidat à la maille de l'assignable : un override `WorkstationGroup` devient un candidat **`StateMaille::LogicalGroup`** (rang 3) ou **`PhysicalGroup`** (rang 4) via `mailleFor()` ; la valeur effective = `assignment.value ?? default_value`. Le défaut diffusé (`capabilities.default_value`) est émis à la maille **`Broadcast`** (rang 5). [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php L.153-181, L.389-419] **C'est pourquoi « l'override s'applique à CE groupe uniquement » est INHÉRENT** : le provider n'émet le candidat WG que pour les groupes auxquels le poste appartient — 29.3 n'a pas à recoder le ciblage par groupe, seulement à rendre ce candidat **gagnant** face à un `permissive`.
- **Enums** : `ControlHubEnforcementState` (`Locked` / `Permissive` / `Absent`), `ControlHubContractTarget` (`Instance` / `Label`), `ControlHubLinkState` (`Active` / `Severed`). [Source: app/Enums/ControlHubEnforcementState.php ; ControlHubContractTarget.php ; ControlHubLinkState.php]
- **Service de verrou (29.2)** — `UpstreamLockResolver` (set des clés `registry`/`locked`/`instance`, `isCapabilityLocked()`, court-circuit NFR3) + Gate `modify-capability` (`CapabilityPolicy`, plancher `app.customize`). **À RÉUTILISER tel quel pour le refus du `locked`** ; ne PAS bloquer `permissive` (`isCapabilityLocked` renvoie déjà `false` pour `permissive`). [Source: app/Services/ControlHub/UpstreamLockResolver.php ; app/Policies/CapabilityPolicy.php L.62-73]

**Le défaut à corriger (le cœur de 29.3) :** un item `permissive` ciblant l'instance pour une capacité C, **avec** un override `WorkstationGroup` posé par le refnum :
- aujourd'hui : candidat `Upstream` (rang -1) **bat** le candidat `LogicalGroup` (rang 3) → l'état effectif reflète la valeur **amont**, jamais celle du refnum. **Bug FR4.**
- attendu : le candidat de l'override WG **gagne** pour ce groupe ; en l'**absence** d'override, la valeur `permissive` amont s'applique comme **plancher/baseline**. **Pour `locked`, rien ne change (l'amont gagne toujours — 28.3/29.2).**

## Acceptance Criteria

1. **Given** un contrat amont **actif** impose un item `registry` en état **`permissive`** ciblant l'**instance**, dont la clé correspond à une projection d'une `Capability` C, **et** le refnum a posé un **override de C sur un `workstationGroup` G** (`capability_assignments`, assignable = G),
   **When** le `StateCompiler` résout l'état effectif d'un poste **appartenant à G**,
   **Then** la valeur retenue pour la clé est celle de **l'override du refnum** (le candidat de la maille de G **bat** l'item `permissive` amont), et non la valeur amont.

2. **Given** le même item `permissive` ciblant l'instance pour C, **mais AUCUN** override local (ni WG, ni poste, ni défaut diffusé) pour cette clé,
   **When** le `StateCompiler` résout l'état effectif d'un poste,
   **Then** la valeur **amont `permissive`** s'applique comme **baseline** (l'amont reste présent tant que le local ne le surcharge pas).

   > **(*) Précision post-review (décision Henri actée) :** le plancher permissif est à la maille **la moins spécifique** (`UpstreamPermissive` rang 6, **sous** `Broadcast` rang 5). Or **toute** `Capability` émet un candidat `default_value` à la maille `Broadcast`. Donc pour une clé **adossée à une Capability**, le défaut diffusé bat **toujours** le permissif → la **valeur** permissive amont n'est servie comme baseline que pour une clé registry **sans** Capability (ou sans défaut diffusé). C'est conforme et voulu (« permissif sous le défaut diffusé ») ; ce qui compte côté Capability est la **relaxabilité** de l'item, pas sa valeur. [Source: second avis Opus — angle A]

3. **Given** un contrat amont actif impose C en état **`locked`** ciblant l'instance,
   **When** le refnum tente de poser/éditer un override de C sur un `workstationGroup` (`openAdd`/`openEdit`/`saveOverride`/`removeOverride` de l'onglet capacités du groupe),
   **Then** l'opération est **refusée côté serveur** (Gate `modify-capability` via `authorizeUpstream`, réutilisé de 29.2 — aucune écriture `capability_assignments`) **And** un message explicite « verrouillé par un contrat amont » est rendu (override réservé aux items `permissive`).

4. **Given** un item **`locked`** ciblant l'instance pour C (cas où un override aurait malgré tout été écrit, ou un défaut local existe),
   **When** le `StateCompiler` résout l'état effectif,
   **Then** la valeur **amont `locked`** **prime** (maille `Upstream` rang -1, **inchangé** vs 28.3/29.2) — la relaxation 29.3 ne s'applique **JAMAIS** à `locked`.

5. **Given** un override `permissive` posé sur le `workstationGroup` G (et pas sur H),
   **When** la résolution s'exécute pour un poste de **H** (qui ne porte PAS l'override),
   **Then** la valeur servie à H **ne reflète PAS** l'override de G (l'override **ne fuit pas** vers H) — confirmant « l'override s'applique à CE groupe uniquement ».

   > **(*) Précision post-review (décision Henri actée) :** « ne fuit pas vers H » est l'**essence prouvée** de l'AC. La **valeur exacte** servie à H dépend de son contexte : si H possède un défaut diffusé (`Broadcast`) pour la clé, H reçoit ce **défaut diffusé** (pas la valeur amont permissive, battue car rang 6 > 5) ; sans aucun candidat local, H retombe sur le plancher permissif. Cf. AC#2 (*) et QA Scénario 8.5. [Source: review sonnet finding #1 + second avis Opus angle A]

6. **Given** un contrat amont actif impose C en état **`absent`**, **OU** aucun contrat actif n'existe (standalone), **OU** le contrat est `severed`,
   **When** le `StateCompiler` résout l'état effectif et le refnum édite un override de C,
   **Then** le comportement est **strictement identique** au comportement 27.12 / 28.3 sans contrat (NFR3) ; et dans le cas « aucun contrat actif », la table `controlhub_contract_items` n'est **pas** requêtée (court-circuit, ≤ 1 requête).

7. **Given** la précédence des mailles est l'UNIQUE arbitre (D2 : la précédence vit dans `StateCompiler::specificity()` SEUL),
   **When** la relaxation `permissive` est implémentée,
   **Then** elle l'est en **injectant le candidat `permissive` à une maille battable** (PAS via une logique de tri ad hoc dans `resolveExclusiveWinner`, qui ferait fuiter D2) ; le `match()` exhaustif de `specificity()` couvre **tous** les `case` de `StateMaille` (zéro `UnhandledMatchError`) **And** le contrat agent (`se5.desired-state/v1`), le golden et le `FROZEN_STATE_HASH` sont **INCHANGÉS** (la maille est interne, jamais sérialisée).

8. **Given** la suite de tests HÔTE (php8.4 + sqlite, `RefreshDatabase`),
   **When** elle s'exécute,
   **Then** sont **couverts et verts** : (a) relaxation résolution (permissive + override WG → override gagne ; permissive sans override → baseline amont ; locked + override → amont gagne ; scope G≠H) ; (b) refus d'écriture `locked` sur la surface override-parc (non-régression 29.2, **sans écriture**) + autorisation `permissive` (override écrit) ; (c) `specificity()` exhaustif + `Upstream` reste le minimum ; (d) **non-régression** : suites 28.3 (`UpstreamContractResolutionTest`, `StateCompilerTest`), 27.12 (capacités), 29.2 (`CapabilitiesTabUpstreamLockTest`, `ParcDefaultsUpstreamLockTest`) vertes ; standalone byte-identique (NFR3) ; golden/`ContractV1Test` intacts.

9. **Given** le garde-fou de vocabulaire R3,
   **When** on lit le code, les messages et les identifiants introduits,
   **Then** **aucun** mot « central » n'apparaît : vocabulaire « amont » / `Upstream` / `ControlHub*`.

## Tasks / Subtasks

- [x] **T0 — Cadrage de la relaxation permissive et choix du rang battable** (AC: #1, #2, #4, #7)
  - [x] Confirmer par lecture que le seul point d'injection à faire diverger est `UpstreamContractSource::ensureResolved()` L.141-155 (la boucle qui crée les `StateCandidate maille: StateMaille::Upstream` **sans** lire `enforcement_state`). [Ancrage : UpstreamContractSource.php L.129-156.]
  - [x] Confirmer que l'override `workstationGroup` est émis à la maille `LogicalGroup` (rang 3) ou `PhysicalGroup` (rang 4) par `AbstractCapabilityStateProvider::mailleFor()` (L.415-419) — donc « l'override par groupe » est déjà ciblé par groupe ; 29.3 doit seulement le rendre **gagnant** face à `permissive`.
  - [x] **DÉCISION DE RANG — TRANCHÉE PAR HENRI (2026-06-27), NE PAS RE-DÉBATTRE** : introduire une maille dédiée `StateMaille::UpstreamPermissive` injectée au rang **le plus grand → le MOINS spécifique de TOUTE la chaîne, sous `Broadcast`** (reco `=> 6`). **Règle métier** : un item `permissive` est un **plancher** ; **toute** maille locale le surcharge — défaut diffusé (`Broadcast`), groupe logique, groupe physique, poste, user — et le `permissive` ne s'applique qu'en l'**absence totale** de candidat local (AC #2). PAS de nuance « le `permissive` bat le défaut diffusé » : Henri a explicitement écarté ce découpage. **NE PAS** toucher `Upstream = -1` (le `locked` reste **inbattable**, inchangé). [Source: mémoire projet — project_permissive_floor_least_specific]
  - [x] **Scope** : 29.3 = type `registry`/capacités (cohérent 29.2/28.3) ; `target_type = instance` (label → Epic 30, déjà différé par `UpstreamContractSource`). Ne pas sur-scoper.

- [x] **T1 — Maille `UpstreamPermissive` + précédence** (AC: #1, #2, #4, #7)
  - [x] Ajouter le `case UpstreamPermissive = 'upstream_permissive';` dans `app/Enums/StateMaille.php` (PHPDoc : tier amont **battable** — un item `permissive` est un plancher que tout override local surcharge — FR4/FR12 ; distinct de `Upstream` qui reste inbattable pour `locked`). R3 : pas de « central ».
  - [x] Ajouter le `case` correspondant dans `StateCompiler::specificity()` (match exhaustif) au rang décidé en T0 (reco `=> 6`). Mettre à jour la PHPDoc de `specificity()` (chaîne de précédence). **Vérifier** que `min()` dans `resolveExclusiveWinner` fait bien perdre `permissive` face à toute maille locale ≤ 5. **NE PAS** ajouter de logique de tri ad hoc (D2 reste dans `specificity()` seul — AC #7).

- [x] **T2 — Injection divergente locked/permissive dans `UpstreamContractSource`** (AC: #1, #2, #4, #6)
  - [x] Dans `ensureResolved()` (boucle L.141-155), lire `enforcement_state` de chaque item et choisir la maille du `StateCandidate` : `Locked → StateMaille::Upstream` (inchangé) ; `Permissive → StateMaille::UpstreamPermissive` (nouveau). Le `whereIn([locked, permissive])` et le court-circuit NFR3 restent **inchangés**. (`absent` reste exclu.)
  - [x] **Garde-fou alignement** : la maille doit dériver de l'`enforcement_state` **de l'item** (pas d'un recalcul via `UpstreamLockResolver` — éviter une 2ᵉ source de vérité). PHPDoc : pointer la fin de la « COUTURE Epic 29 » (mettre à jour le bloc L.50-54 pour acter la relaxation livrée).
  - [x] NFR3 : sans contrat actif, **zéro** candidat des deux mailles, **zéro** requête `items` (inchangé).

- [x] **T3 — Écriture override par `workstationGroup` : autoriser `permissive`, refuser `locked`** (AC: #3)
  - [x] **Constat** : `capabilities-tab.blade.php` (override par parc = par WG) a **déjà** le garde `authorizeUpstream()` (29.2) qui refuse `locked` et laisse passer `permissive`. **Vérifier** (lecture + test) qu'aucune écriture `capability_assignments` n'a lieu pour un `locked` (AC #3) et qu'un `permissive` s'écrit normalement. **Ne PAS** réimplémenter le gate.
  - [x] **Décision `removeOverride` sur `permissive`** : retirer un override `permissive` est **autorisé** (le refnum reprend la valeur amont/défaut comme baseline — c'est précisément la marge d'adaptation FR4). Confirmer que `authorizeUpstream` laisse passer `permissive` sur `removeOverride` (29.2 ne bloque que `locked`). Documenter.
  - [x] Aucune nouvelle surface : l'écriture par WG est `capabilities-tab` ; le **défaut instance** (`parc-defaults`) **n'est pas** une surface « par groupe » → hors 29.3 (29.2 le couvre déjà pour `locked`).

- [x] **T4 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#9)
  - [x] **Résolution `tests/Feature/ControlHub/PermissiveOverrideResolutionTest.php`** (calqué sur `UpstreamContractResolutionTest`) : (a) item `registry`/`permissive`/`instance` + override `WorkstationGroup` G sur la même clé → l'état compilé d'un poste de G reflète la **valeur de l'override** (AC #1) ; (b) même `permissive` **sans** override → valeur **amont** (baseline, AC #2) ; (c) item `locked` + override → valeur **amont** gagne (AC #4, non-régression) ; (d) override sur G, poste de H → valeur amont pour H (scope, AC #5) ; (e) standalone (aucun contrat) → byte-identique + **court-circuit ≤ 1 requête** (compteur `DB::getQueryLog`/`DB::listen`, AC #6). Réutiliser factories `ControlHubContractFactory`/`ItemFactory` (états `permissive()`/`absent()`/`severed()`) — **type `registry`** (le défaut factory est `capabilities` : forcer `type=registry` + `key=hive|path|name`). Piège SQLite : tester des **valeurs résolues/booléens**, pas des bornes de colonnes. [Source: mémoire projet — sqlite_tests_no_varchar_enforcement]
  - [x] **`specificity()` exhaustif** — étendre/vérifier `tests/Unit/Services/Agent/StateCompilerTest.php::specificity_covers_all_mailles_and_upstream_is_the_minimum` (L.674) : tous les `case` de `StateMaille` (dont `UpstreamPermissive`) ont un rang, `Upstream` reste le **minimum**, et `UpstreamPermissive` est **> toute maille locale** au rang décidé (reco strictement `> Broadcast`).
  - [x] **Écriture override parc** `tests/Feature/.../CapabilitiesTabPermissiveOverrideTest.php` (ou étendre `CapabilitiesTabUpstreamLockTest`) : `permissive` → `saveOverride` écrit la ligne `capability_assignments` (override autorisé, AC #3 contrepoint) ; `locked` → refus **sans écriture** + toast (non-régression 29.2).
  - [x] **Non-régression** : exécuter `UpstreamContractResolutionTest`, `StateCompilerTest`, suites 27.12 capacités, 29.2 (`CapabilitiesTabUpstreamLockTest`, `ParcDefaultsUpstreamLockTest`), `ContractV1Test`/golden → **0 régression**. **Golden/hash INTACTS** (la nouvelle maille n'est jamais sérialisée — AC #7).

- [x] **T5 — Runbook QA (domaine `controlhub-contract`)** (AC: #1–#5)
  - [x] **Append** une section dédiée 29.3 à `docs/qa/domains/controlhub-contract.md` (29.2 a ajouté la **Section 7** ; 29.3 = **Section 8**, scénarios `### Scénario 8.M`, numérotation stable). Couvrir manuellement : pose d'un override sur un parc pour une capacité `permissive` (l'état effectif du poste de ce parc reflète la valeur du refnum), poste d'un autre parc (valeur amont), retrait de l'override (retour baseline amont), tentative d'override sur capacité `locked` (refus + message), standalone (inchangé). Enrichir le libellé du domaine `controlhub-contract` dans `docs/qa/README.md` (mention 29.3 — relaxation permissive). [Patron : 29.2 → Section 7 de `controlhub-contract.md`.]

- [x] **T6 — Validation finale**
  - [x] `php artisan test --filter "PermissiveOverride|UpstreamContractResolution|StateCompiler|CapabilitiesTab|ParcDefaults|ControlHubContract|ContractV1"` sur HÔTE → vert (positifs + négatifs + non-régression).
  - [x] Relecture grep R3 : `grep -rin "central" app/Enums/StateMaille.php app/Services/ControlHub/Resolution/UpstreamContractSource.php app/Services/Agent/StateCompiler.php` → **0** (hors commentaires garde-fou).
  - [x] Vérifier le court-circuit NFR3 : sans contrat actif, aucune requête `controlhub_contract_items` à la compilation.
  - [x] `php -l` sur les fichiers PHP touchés ; confirmer golden/`FROZEN_STATE_HASH` **non bumpé** (diff `git status` sur les fichiers golden = vide).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 29.3

**DANS** : la **relaxation permissive** au compilé — une maille `UpstreamPermissive` battable (enum + `specificity()`), l'injection divergente `locked`→`Upstream` / `permissive`→`UpstreamPermissive` dans `UpstreamContractSource`, la **vérification** (non la réimplémentation) du refus d'écriture `locked` / autorisation `permissive` sur la surface override-par-WG (`capabilities-tab`, gate 29.2), les tests HÔTE, le runbook QA. Verrou inchangé : `locked` reste inbattable (`Upstream = -1`).

**HORS** (ne pas déborder) :
- **Refus d'édition d'un item `locked`** (FR3) → **livré 29.2** (`UpstreamLockResolver` + Gate `modify-capability`). 29.3 le **réutilise** et le teste en non-régression ; ne le recode pas.
- **Ciblage par label / mapping label→WG** (FR12, `target_type = label`) → **Epic 30**. 29.3 reste `target_type = instance` (cohérent 28.3/29.2).
- **Lisibilité refnum (badges imposé/verrouillé/permissif)** (FR8) → **Story 29.4**. 29.3 ne livre **aucun** badge « surchargeable » ; il livre l'**effet** de l'override, pas sa signalétique.
- **Drift STRICT + audit des overrides permissifs** (NFR2/NFR5) → **Story 29.5**. 29.3 ne réapplique rien et n'audite pas la pose d'override (un log est un bonus, pas un AC).
- **Types aggregate (`shortcuts`)** → couture documentée (sémantique de surcharge ambiguë comme en 29.2) ; 29.3 ne câble que le type exclusive-par-clé (registry/capacités).
- **Aucune migration, aucun nouveau modèle de données, aucune route.** Réutilisation pure : tables Epic 28 + capacités Epic 27 + résolution 28.3.

### Le mécanisme exact (à IMITER / fermer)

- **Couture à fermer** [UpstreamContractSource.php L.50-54] : « relaxation permissive non implémentée — `permissive` se comporte comme `locked` ; la fermer en injectant `permissive` à un rang battable ». 29.3 le fait en **2 lignes conceptuelles** : (1) une maille `UpstreamPermissive` au-dessous des mailles locales ; (2) la boucle d'injection qui aiguille `locked`→`Upstream` / `permissive`→`UpstreamPermissive`.
- **D2 — la précédence vit dans `specificity()` SEUL** [StateCompiler.php L.372-404] : NE PAS arbitrer dans `resolveExclusiveWinner` ni dans les providers ni dans l'enum. `resolveExclusiveWinner` prend déjà `min(specificity)` ⇒ il suffit que `UpstreamPermissive` ait un rang plus grand (moins spécifique) que les mailles locales. [Source: StateCompiler.php L.308-316]
- **Maille interne** [StateMaille.php L.7-24] : ajouter un `case` ne touche PAS le payload `se5.desired-state/v1` (donc ni `ContractV1Test`, ni golden, ni `FROZEN_STATE_HASH`). **Prouver** par test que le golden ne bouge pas — c'est l'angle mort #1 (un dev pourrait croire qu'ajouter une maille bumpe le hash).
- **L'override « par groupe » est déjà ciblé** [AbstractCapabilityStateProvider.php L.165-181, L.415-419] : le provider n'émet le candidat WG que pour les groupes du poste. 29.3 ne recode PAS le ciblage ; il rend ce candidat gagnant. → AC #5 (scope) découle gratuitement du provider existant.
- **Réutiliser le gate 29.2 pour `locked`** [CapabilityPolicy.php L.62-73 ; capabilities-tab.blade.php L.372-378] : `isCapabilityLocked` renvoie `false` pour `permissive` → l'override `permissive` passe déjà. Ne PAS étendre le gate pour « autoriser permissive » (c'est l'état par défaut). Ne PAS bloquer `permissive` (casserait FR4).

### Garde-fous projet CRITIQUES

- **NE relaxer QUE `permissive`** : `locked` reste à `Upstream = -1` (inbattable). Confondre les deux casserait 29.2/FR3 (un verrou deviendrait surchargeable). AC #4 le verrouille par test.
- **NFR3 — standalone strictement inchangé** : sans contrat actif, `UpstreamContractSource` court-circuite (≤ 1 requête, zéro candidat des deux mailles) ; le compilé reste **byte-identique** au standalone 27.12/28.3. Test révélateur obligatoire. [Source: UpstreamContractSource.php L.111-127]
- **D2 — pas de tri ad hoc** : implémenter la relaxation **uniquement** par le rang de maille, jamais par une branche `if permissive` dans `resolveExclusiveWinner` (anti-pattern : D2 fuit hors `specificity()`). [Source: StateMaille.php L.11-19 ; StateCompiler.php D2]
- **Golden/contrat agent INTACTS** : maille interne ⇒ `FROZEN_STATE_HASH` non bumpé. Si un test golden échoue, c'est un **signal de bug** (une maille a fuité dans la sérialisation), pas un « bump attendu ». [Source: StateMaille.php L.7-10]
- **Match exhaustif** : le nouveau `case` DOIT être ajouté à `specificity()` (sinon `UnhandledMatchError` en prod). Garde-fou déjà testé (`StateCompilerTest` L.674). [Source: StateCompiler.php L.388-391]
- **Vocabulaire R3** : aucun « central » ; la maille s'appelle `UpstreamPermissive` (valeur `'upstream_permissive'`), jamais « central ». [Source: prd-contrat-manage-se5.md#R3]
- **Tests HÔTE uniquement** : php8.4 + `pdo_sqlite`, `RefreshDatabase`, **jamais la VM** (worktree git → interdit). SQLite n'applique pas varchar/enum PG : tester des **valeurs résolues / décisions**, pas des bornes. [Source: mémoires projet — phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement]

### Architecture & conventions

- **Filesystem-router + Livewire SFC** : la seule surface UI touchée (en vérification) est `capabilities-tab.blade.php` (override par WG) ; aucune mutation de méthode requise si 29.2 suffit. [Source: CLAUDE.md — routing]
- **Toasts** : trait `WithToasts` (déjà utilisé par `capabilities-tab` via `authorizeUpstream`) pour le message « verrouillé par un contrat amont » — réutilisé tel quel. [Source: capabilities-tab.blade.php L.372-378]
- **DI / cycle de vie** : aucun nouveau service requis. `UpstreamContractSource` est déjà mémoïsé par-requête (singleton) — la divergence locked/permissive n'ajoute aucune requête. [Source: AgentServiceProvider — singleton 28.3]
- **PHP-FPM = www-admin** : sans impact (tests HÔTE). [Source: mémoire projet — php_fpm_user_www_admin]

### Project Structure Notes

- **Modifiés** :
  - `app/Enums/StateMaille.php` (+1 `case UpstreamPermissive`).
  - `app/Services/Agent/StateCompiler.php` (+1 `case` dans `specificity()` + PHPDoc).
  - `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (injection divergente locked/permissive + maj de la « COUTURE Epic 29 »).
  - `docs/qa/domains/controlhub-contract.md` (Section 8 append) + `docs/qa/README.md` (libellé domaine).
  - éventuellement `tests/Unit/Services/Agent/StateCompilerTest.php` (extension du test d'exhaustivité).
- **Nouveaux** : `tests/Feature/ControlHub/PermissiveOverrideResolutionTest.php`, `tests/Feature/.../CapabilitiesTabPermissiveOverrideTest.php` (ou extension de `CapabilitiesTabUpstreamLockTest`).
- **Aucune** migration, **aucun** nouveau modèle, **aucune** route, **aucune** modification du contrat agent / golden. **Racine = projet Laravel** (pas de préfixe `laravel/`). [Source: mémoire projet — root_is_laravel]

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 29.3] — AC d'origine (FR4) : permissif → override WG appliqué à ce groupe ; verrouillé → override refusé.
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#FR4, #FR2, #FR12, #NFR3, #NFR5] — surcharge permissive par WG ; empilement local ; règle verrou/permissif ; standalone inchangé ; audit (29.5).
- [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php L.50-54, L.129-156] — **couture permissive à fermer** + point d'injection unique (maille en dur).
- [Source: app/Services/Agent/StateCompiler.php L.308-316, L.372-404] — `min(specificity)` + `specificity()` (`Upstream = -1`), match exhaustif.
- [Source: app/Enums/StateMaille.php] — enum interne jamais sérialisé ; chaîne de précédence.
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php L.153-181, L.389-419] — override WG → maille `LogicalGroup`/`PhysicalGroup` ; ciblage par groupe déjà porté.
- [Source: resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php L.74-76, L.221-298, L.372-378] — surface override par WG + garde `authorizeUpstream` (29.2).
- [Source: app/Services/ControlHub/UpstreamLockResolver.php ; app/Policies/CapabilityPolicy.php] — verrou `locked` réutilisé (refuse locked, laisse permissive).
- [Source: app/Enums/ControlHubEnforcementState.php ; ControlHubContractTarget.php ; ControlHubLinkState.php] — locked/permissive/absent ; instance/label ; active/severed.
- [Source: database/factories/ControlHubContractItemFactory.php ; ControlHubContractFactory.php] — états `permissive()`/`absent()`/`severed()`/`forLabel()` (défaut `type=capabilities`/`locked`/`instance` — forcer `type=registry` pour la résolution).
- [Source: tests/Feature/ControlHub/UpstreamContractResolutionTest.php ; tests/Unit/Services/Agent/StateCompilerTest.php L.674] — patrons de test de résolution + exhaustivité maille.
- [Source: _bmad-output/implementation-artifacts/29-2-refuser-modification-item-verrouille.md ; 29-1-scoper-gate-wpkg-par-perimetre.md] — gate `modify-capability` / `authorizeUpstream` + patron `RegistersGates`.
- [Source: mémoires projet — phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement, root_is_laravel, php_fpm_user_www_admin, zero_prod_publish_is_test, no_legacy_transition_state].

## Dépendances

- **Amont (consommées)** :
  - **Story 28.3** (`review`) — fournit l'**injection des items amont au compilé** (`UpstreamContractSource`, maille `Upstream`, court-circuit NFR3) et la **précédence** (`StateCompiler::specificity()`). **Dépendance dure** : 29.3 modifie précisément ce point. Code sur `main`.
  - **Story 29.2** (`review` / `to-validate`, `codeReviews/29-2.md`) — fournit le **gate `modify-capability`** + `authorizeUpstream` sur la surface override-par-WG (refus `locked`, autorisation `permissive`) **déjà en place** ; 29.3 le **réutilise et le teste en non-régression** (ne le recode pas). **Dépendance de réutilisation.** Code sur la branche `worktree-contract-CH`.
  - **Story 29.1** (`review`) — patron d'enregistrement de gate scopé (déjà consommé indirectement via 29.2). Pas de consommation directe en 29.3.
  - **Epic 28** (28.1/28.2, `review`) — modèle `ControlHubContract*` + enums + factories. **Dépendance dure.**
- **Prérequis fourni à (aval)** :
  - **Story 29.4** (lisibilité refnum) — affichera le statut « imposé-permissif (surchargeable) » que 29.3 rend effectif.
  - **Story 29.5** (drift STRICT + audit) — auditera la pose d'override permissif que 29.3 rend opérante.
  - **Epic 30** (ciblage par label) — étendra la relaxation aux items `target_type = label` (29.3 = instance only).
- **Réutilise** : capacités Epic 27 (`Capability`, `CapabilityProjection`, `capability_assignments`), résolution 28.3 (`StateCompiler`, `StateMaille`, `UpstreamAwareProvider`) — **livrés et stables**.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, trait `RefreshDatabase`. **Jamais la VM.** [Source: mémoire projet — phpunit_test_env_host_vs_vm]
- Filtres ciblés : `php artisan test --filter "PermissiveOverride|UpstreamContractResolution|StateCompiler|CapabilitiesTab|ParcDefaults|ControlHubContract|ContractV1"`.
- Couverture obligatoire :
  - **Relaxation résolution** : permissive + override WG sur la même clé → **override gagne** (poste du groupe) ; permissive **sans** override → **baseline amont** ; locked + override → **amont gagne** (non-régression 29.2) ; override sur G, poste de H → amont pour H (scope) ; standalone → byte-identique **+ court-circuit ≤ 1 requête**.
  - **Exhaustivité maille** : tous les `case` de `StateMaille` ont un rang ; `Upstream` minimum ; `UpstreamPermissive > Broadcast` (battable par tout le local — au rang décidé).
  - **Écriture override parc** : permissive → `saveOverride` écrit ; locked → refus **sans écriture** + toast (non-régression 29.2).
  - **Non-régression** : 28.3 (`UpstreamContractResolutionTest`, `StateCompilerTest`), 27.12, 29.2 (`CapabilitiesTabUpstreamLockTest`, `ParcDefaultsUpstreamLockTest`), `ContractV1Test`/golden vertes ; **golden/hash INTACTS**.
- **Pièges** : SQLite n'applique pas varchar/enum PG → tester **valeurs résolues / booléens**, pas bornes ; forcer `type=registry` + `key=hive|path|name` sur les items factory (défaut = `capabilities`/`locked`) ; un échec golden = bug de fuite de maille, pas un bump attendu. [Source: mémoires projet — sqlite_tests_no_varchar_enforcement]
- ⚠️ **VM** : migrations non auto-jouées par le dev-cycle — sans impact (aucune migration). [Source: mémoire projet — vm_migrations_not_auto_applied]

## Recommandation Modèle Dev

**`opus`.**

Justification : le diff est court (une maille + un `case` de `specificity()` + une injection divergente) mais c'est un **changement au cœur du moteur de résolution desired-state** sous contrainte de sécurité — symétrique inverse de 29.2. Le jugement critique porte sur le **choix du rang battable** (lecture de FR12 « local l'emporte » vs nuance Broadcast/défaut diffusé), sur le fait de **ne relaxer QUE `permissive`** sans jamais affaiblir le verrou `locked` (un faux pas rouvre FR3), sur le respect de **D2** (précédence dans `specificity()` seul, pas de tri ad hoc), et sur la **preuve** que le golden/`FROZEN_STATE_HASH` reste intact (angle mort : croire qu'une nouvelle maille bumpe le hash). C'est un raisonnement d'architecte de moteur d'état, pas une recette CRUD. Le dev-cycle routera la review vers le modèle opposé pour une 2ᵉ paire d'yeux sur les angles morts (locked accidentellement relaxé, fuite de maille au payload, scope inter-parcs).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- `php artisan test --filter "PermissiveOverride"` → 9/9 verts.
- `php artisan test --filter "PermissiveOverride|UpstreamContractResolution|StateCompiler|CapabilitiesTab|ParcDefaults|ControlHubContract|ContractV1"` → 137/137 verts, 0 régression.
- `php artisan test --filter "Capability|UpstreamAware|UpstreamContract|StateCompiler|ContractV1|PermissiveOverride"` → 104/104 verts (non-régression providers de capacités + hérédité physique).
- `git diff --name-only | grep -iE "golden|frozen|ContractV1|StateHasher"` → vide (golden/hash non bumpés ; `ContractV1Test` vert).
- `grep -rin "central"` sur les 3 fichiers PHP touchés → uniquement les commentaires garde-fou « JAMAIS central » (zéro identifiant).

### Completion Notes List

- **T0/T1 — Maille `UpstreamPermissive` + précédence.** Ajout du `case UpstreamPermissive = 'upstream_permissive'` dans `StateMaille` (PHPDoc : plancher amont battable, distinct de `Upstream` inbattable) et du `case UpstreamPermissive => 6` dans `StateCompiler::specificity()` (sous `Broadcast => 5`, le rang le MOINS spécifique de toute la chaîne — décision Henri 2026-06-27 : permissif = plancher, le défaut diffusé le bat aussi). Match exhaustif préservé (zéro `UnhandledMatchError`). `Upstream = -1` INCHANGÉ (locked inbattable). D2 respecté : aucune branche `if permissive` dans `resolveExclusiveWinner` — la relaxation vit uniquement dans le rang de maille.
- **T2 — Injection divergente.** `UpstreamContractSource::ensureResolved()` choisit la maille du `StateCandidate` selon `$item->enforcement_state` (casté en enum) : `Permissive → UpstreamPermissive`, sinon `Upstream`. Le `whereIn([locked, permissive])` et le court-circuit NFR3 sont inchangés. La maille dérive directement de l'enforcement de l'item (source de vérité unique, pas de recalcul via `UpstreamLockResolver`). Bloc « COUTURE Epic 29 » L.50-54 réécrit pour acter la relaxation livrée.
- **T3 — Écriture (vérification, pas réimplémentation).** Confirmé par lecture + nouveau test `CapabilitiesTabPermissiveOverrideTest` que le gate `authorizeUpstream` (29.2) sur `capabilities-tab.blade.php` laisse passer `permissive` (saveOverride écrit, capacité addable, removeOverride retire) et refuse `locked` (couvert par `CapabilitiesTabUpstreamLockTest`, non-régression).
- **T4 — Tests HÔTE.** Nouveaux : `PermissiveOverrideResolutionTest` (6 tests : override local bat permissif ; baseline amont sans local ; permissif sous Broadcast ; locked inbattable ; e2e WG override gagne + ne fuit pas scope G≠H via vrais `RegistryUser/MachineCapabilityProvider` ; standalone court-circuit ≤1 req / 0 items) et `CapabilitiesTabPermissiveOverrideTest` (3 tests). Étendu : `StateCompilerTest::specificity_*` (UpstreamPermissive = max > Broadcast). **Test 28.3 mis à jour** : `permissive_and_locked_both_win_over_local` renommé `locked_wins_over_local_but_permissive_is_overridden_by_local` — il encodait l'ancien comportement (permissif battait le local), désormais le permissif est battu par le local (changement INTENTIONNEL 29.3, non une régression). Valeurs résolues testées, pas de bornes SQLite ; `type=registry` + `key=hive|path|name` forcés.
- **T5 — Runbook QA.** Section 8 (scénarios 8.1-8.7 + checklist) ajoutée en append à `docs/qa/domains/controlhub-contract.md` (29.2 = Section 7, numérotation stable). Libellé du domaine enrichi dans `docs/qa/README.md` (mention 29.3 — relaxation permissive).
- **T6 — Validation.** Filtres ciblés verts, `php -l` OK sur tous les PHP touchés, golden/`FROZEN_STATE_HASH` non bumpés (`ContractV1Test` vert, aucun fichier golden modifié), R3 = 0 identifiant « central ».
- **ANGLE MORT signalé à la review.** AC#5 dit littéralement « la valeur amont permissive s'applique pour H ». Avec la décision Henri (permissif SOUS le défaut diffusé), un poste de H qui possède un défaut diffusé pour la clé retombe sur ce **défaut diffusé**, pas sur la valeur amont permissive (le permissif n'émerge qu'en l'absence TOTALE de candidat local). L'**essence** d'AC#5 (l'override de G ne fuit PAS vers H) reste prouvée par le test e2e ; seule la valeur exacte servie à H diffère du libellé pré-décision. À acter par l'orchestrateur/review.

### File List

- `app/Enums/StateMaille.php` (modifié — `case UpstreamPermissive` + PHPDoc chaîne de précédence)
- `app/Services/Agent/StateCompiler.php` (modifié — `case UpstreamPermissive => 6` dans `specificity()` + PHPDoc)
- `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (modifié — injection divergente locked/permissive + maj couture Epic 29)
- `tests/Feature/ControlHub/PermissiveOverrideResolutionTest.php` (nouveau)
- `tests/Feature/Livewire/Parc/CapabilitiesTabPermissiveOverrideTest.php` (nouveau)
- `tests/Feature/ControlHub/UpstreamContractResolutionTest.php` (modifié — test 28.3 renommé/MAJ pour le comportement 29.3)
- `tests/Unit/Services/Agent/StateCompilerTest.php` (modifié — exhaustivité `specificity()` étendue à `UpstreamPermissive`)
- `docs/qa/domains/controlhub-contract.md` (modifié — Section 8 append)
- `docs/qa/README.md` (modifié — libellé domaine `controlhub-contract` enrichi 29.3)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modifié — statut 29-3 → review + commentaire historique)
