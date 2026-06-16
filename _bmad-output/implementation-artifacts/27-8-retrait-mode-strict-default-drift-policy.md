# Story 27.8 : Retrait du mode strict/default de la drift policy — STRICT partout

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **⚠️ RENUMÉROTATION & NATURE — lire en premier.** Dans `epics-agent-desired-state.md`, les slots
> « 27.x » décrivent les handlers de ressources. Cette story **n'ajoute aucune ressource** : elle
> **DÉMONTE** un mécanisme transverse (le `mode` strict/default de la drift policy) introduit par 27.1 et
> déplacé par 27.3. C'est une story de **simplification / retrait de fonctionnalité** sur 3 domaines
> (schéma → providers → compilateur → UI → AGENT Go → contrat/golden → docs). Elle **révise/annule** une
> grande partie de 27.1 et 27.3.

## ✅ DÉCISIONS HENRI — TRANCHÉES (2026-06-16) — ne PAS re-demander

> Les 3 décisions structurantes sont prises. **Procéder sans re-trancher.** Les inscrire telles quelles.

1. **Comportement cible = STRICT PARTOUT.** On supprime le mécanisme `mode ∈ {strict, default}` de la drift
   policy. Toute ressource desired-state est **toujours réappliquée** par l'agent : la dérive humaine est
   **toujours corrigée**. Le verdict `drifted_allowed` **disparaît** en tant que politique (réel ≠ cible →
   `drift` + apply, sans exception). C'est le comportement que `mode=strict` produisait déjà — on le rend
   **inconditionnel** et on retire toute la branche `default`.

2. **Profondeur = TOTALE, du schéma à l'agent.** Retirer le mode PARTOUT :
   - colonnes `mode` des **3 tables** (`shortcut_assignables`, `wallpapers`, `overlay_signals`) ;
   - `StateCandidate::$mode`, `StateCompiler::aggregateMode()`, `StateProvider::mode()` ;
   - l'enum **`App\Enums\StateMode`** (plus aucun consommateur après nettoyage) ;
   - **tous les toggles/sélecteurs UI** (shortcuts modale, wallpaper-card, overlay-messages) ;
   - la **simplification de l'agent Go** : `agent/shared/engine.go` (un seul comportement, plus de
     `mode`/`drifted_allowed`), `agent/shared/contract.go` (`ResourceStatuses` sans `drifted_allowed`) ;
   - l'enum PHP **`AgentResourceStatus::DriftedAllowed`** + sa comptabilité conformité ;
   - **golden files** (`mode` retiré de chaque item d'état, item `drifted_allowed` retiré du report) + bump
     **croisé PHP↔Go** des hashes figés.

3. **Zéro prod** (mémoire `zero_prod_publish_is_test`) : AUCUNE donnée à préserver. Migrations `down()`
   **symétriques** (réversibilité) mais **pas de double-écriture / pas de back-fill / pas de phase
   transitoire**. Les colonnes `mode` sont droppées proprement.

## Décisions par défaut tranchées dans cette story (micro-décisions — recommandées, signalées en bas)

> Aucune de ces micro-décisions n'est bloquante. Elles sont tranchées avec une recommandation par défaut ;
> voir **« Questions pour Henri (NON bloquantes) »**. Le dev applique le défaut ci-dessous sauf contre-ordre.

- **D-A — Schéma reste `se5.desired-state/v1` (PAS de bump v2).** Zéro prod (mémoire
  `zero_prod_publish_is_test`) → la rupture interne du payload (retrait de la clé `mode` de chaque item) est
  **assumée**. Bumper en `v2` n'apporte rien (aucun agent déployé à protéger ; FR5/§9 « major refusé » sert
  un parc en prod qui n'existe pas) et ferait diverger `ContractMajor` Go + tous les fixtures sans bénéfice.
  **On reste v1, on bumpe SCIEMMENT le hash figé** (comme 27.1/27.2/27.7 l'ont déjà fait pour des
  évolutions de payload). Voir piège « le contrat bouge ».

- **D-B — `ResolveItemStatus` simplifié, `AppliedState`/persistance CONSERVÉE.** En strict pur,
  `ResolveItemStatus` se réduit à `isCompliant ? compliant : drift+apply` : les paramètres `mode` et
  `lastAppliedHash` deviennent **inutiles au verdict** → on les **retire de la signature** (simplification
  réelle, AC2). MAIS la persistance `AppliedState` (`agent/shared/sessionstore.go` Read/WriteAppliedState,
  `companion.go`, `AppliedStatePath`) est **conservée** : elle trace le dernier-appliqué/horodatage
  (traçabilité, décision 24.4 n° 9 — « persistée même en strict ») et la retirer élargirait le périmètre
  (refonte du store per-user, loop, companion) sans gain. **Recommandation : simplifier le verdict, garder le
  store.** Si Henri veut aussi purger le store applied-state, c'est une story de suivi (pas ici).

## Story

En tant qu'**équipe SE5 (architecte + admin)**,
je veux **retirer complètement le mécanisme `strict/default` de la drift policy du desired-state**,
afin que **le comportement de convergence soit UNIQUE et honnête — l'agent réapplique toujours l'état cible,
sur tous les types, sans promesse creuse de « dérive tolérée »** dont le grain réel (`type × poste`) ne
correspondait pas à l'intention vendue (`item × cible`).

## Contexte & intention — pourquoi on démonte le mode

**Cette story révise/annule une grande partie de 27.1 et 27.3.**

- **27.1** (`27-1-handler-raccourcis-convergence-bureau.md` décision Henri n° 2, L160-168) a **introduit** le
  toggle `mode ∈ {strict, default}` : colonne `mode VARCHAR(16) nullable` sur les **3 tables de règle**
  (`shortcuts`, `wallpapers`, `overlay_signals`), cast `StateMode`, `StateCandidate::$mode`, agrégation
  `StateCompiler::aggregateMode()` (tous `default` → `default`, sinon `strict`), toggle UI sur les 3 formulaires.
- **27.3** (`27-3-drift-policy-par-assignation.md`) a **déplacé** ce mode `règle → assignation` pour
  `shortcuts` (colonne `shortcuts.mode` droppée, `shortcut_assignables.mode` ajoutée), wallpaper/overlay
  gardant le mode sur leur table.

**Ce que la review 27.3 a révélé (motivation directe de 27.8).** La review
`_bmad-output/codeReviews/27-3.md` (questions Q1 / problème #6) a établi que **le grain réel du mode est
`type × poste`, PAS `item × cible`** : le moteur agent (`engine.go::RunPass`) rend **UN seul verdict de mode
par TYPE** (un groupe = un mode, ligne `mode := first.Mode`). La promesse marketing de 27.3 — « un même
raccourci verrouillé sur un parc, modifiable sur un autre, **sur le même poste** » — est donc **creuse au
niveau agent** : deux raccourcis d'un même poste-user partagent le mode agrégé du type `shortcuts`. Un vrai
« mode par item » exigerait de porter le mode au payload **par item** (refonte du contrat) — surdimensionné
pour le besoin.

**Henri tranche : on retire complètement le mécanisme.** Plutôt que de complexifier (mode par item au
payload) ou de garder une demi-promesse, on **supprime** le mode. Comportement unique = **STRICT** :
l'agent réapplique toujours, la dérive humaine est toujours corrigée. C'est plus simple, honnête, et fidèle
au modèle « successeur GPO = état cible calculé serveur, réimposé » (mémoire `agent_desired_state_direction`).

**Ce que cette story livre :**
- **Schéma** : DROP des 3 colonnes `mode` (`shortcut_assignables`, `wallpapers`, `overlay_signals`),
  migrations idempotentes, `down()` symétrique qui RE-CRÉE la colonne (réversibilité).
- **Code serveur** : suppression de `StateMode`, `StateCandidate::$mode`, `StateProvider::mode()`,
  `StateCompiler::aggregateMode()` ; les providers ne lisent plus de colonne `mode` ; l'item du contrat passe
  de **5 clés** (`type, semantics, mode, payload, hash`) à **4 clés** (`type, semantics, payload, hash`).
- **Code agent Go** : `engine.go` simplifié (un comportement, `ResolveItemStatus` sans `mode`/`lastAppliedHash`,
  plus de `StateItem.Mode`, plus de parsing du champ `mode`) ; `contract.go` `ResourceStatuses` sans
  `drifted_allowed`.
- **UI** : retrait des toggles/sélecteurs mode des 3 surfaces.
- **Contrat / golden** : `mode` retiré de chaque item du golden d'état, item `drifted_allowed` retiré du
  golden report ; bumps **croisés PHP↔Go** (NFR13) ; enum `AgentResourceStatus` réduit à 3 statuts.
- **Docs / QA** : `docs/agent/contract-v1.md` (§5 « GAP 1 » retiré/réécrit, item à 4 clés), `state-providers.md`
  (sections mode retirées), QA append-only « ## Story 27.8 ».

**Ce que cette story N'EST PAS :**
- Le décommissionnement du canal legacy (27.6) — ZÉRO retrofit legacy, comme 27.1/27.2/27.3.
- Une refonte du store applied-state per-user (conservé — voir D-B).
- Un bump de version de schéma (reste v1 — voir D-A).
- Un changement des autres invariants du contrat (canonicalisation, hash opaque, semantics aggregate/exclusive,
  isolation par type, AggregateHash) — tous **réutilisés tels quels**.

## ⚠️ Pièges & tensions (lire AVANT de coder)

### Piège n° 1 — LE CONTRAT BOUGE : c'est un retrait de clé, croisé PHP↔Go, golden + 2 hashes figés

> **C'est la différence fondamentale avec 27.3.** 27.3 gardait le golden INTACT (le mode n'était jamais émis
> au payload). **27.8 retire le champ `mode` de CHAQUE item d'état** → le hash de chaque item change ET le
> hash d'état change. C'est un changement de wire format **assumé sciemment**.

- **L'item du contrat a 5 clés aujourd'hui.** `ContractV1Test::every_state_item_has_the_five_contract_keys_and_valid_enums`
  (L94-119) **assert exactement** `['type', 'semantics', 'mode', 'payload', 'hash']` et
  `StateMode::tryFrom($item['mode'])`. Après 27.8 : **4 clés** `['type', 'semantics', 'payload', 'hash']`, et
  l'assertion `StateMode` disparaît. [Source: tests/Unit/Services/Agent/ContractV1Test.php:105-114]
- **Golden d'état** : retirer la ligne `"mode": …` des **5 items** de `tests/Fixtures/Agent/state.v1.json`
  (L11 wallpaper, L21 overlay, L31 printers, L44 drives, L57 shortcuts), puis **recalculer** les `hash` de
  chaque item ET le hash d'état figé. [Source: tests/Fixtures/Agent/state.v1.json:11,21,31,44,57]
- **Hash figé PHP** : `ContractV1Test::FROZEN_STATE_HASH` (L58, valeur courante
  `a43e8aadd40e7ed7e98aebe7952d473a5a729630bf6ca9c12362c840e691d1c0`) — bump SCIEMMENT + commentaire daté
  27.8 (les hash d'items du golden changent aussi : `each_state_item_hash_matches_state_hasher` les revérifie
  contre `StateHasher::hashItem`). [Source: tests/Unit/Services/Agent/ContractV1Test.php:58,121-135]
- **Hash figé Go (jumeau, NFR13)** : `agent/shared/hasher_test.go::frozenStateHash` (L27, **même valeur**) —
  bump à l'IDENTIQUE. `TestHashStateGoldenMatchesFrozenHash`, `TestHashItemGoldenItemsMatchTheirHashFields`
  (5 items), `TestCanonicalizeProducesPhpCanonicalForm` re-valident sur le golden modifié.
  [Source: agent/shared/hasher_test.go:27,67-77,103-134]
- **Méthode de bump (impérative)** : les golden hashes doivent être calculés par le **StateHasher PHP RÉEL**
  (pas à la main) — c'est l'action `/vm` (`php artisan tinker` ou un script `hashItem`/`hashState`), puis la
  valeur reportée dans le golden + les 2 constantes figées. **Vérifier que PHP et Go retombent sur la MÊME
  valeur** (le test croisé Go `TestHashStateGoldenMatchesFrozenHash` est la preuve). C'est exactement le
  geste documenté par 27.1/27.2/27.7 (cf. commentaires `ContractV1Test.php:33-57` et `hasher_test.go:14-26`).
- **Golden report** : `tests/Fixtures/Agent/report.v1.json` porte un item `"status": "drifted_allowed"`
  (L21-24, type `shortcuts`). `ContractV1Test::report_golden_file_has_valid_structure_and_four_statuses`
  (L150-192) **exige que les 4 statuts d'`AgentResourceStatus::cases()` soient illustrés** (L184-191). Après
  retrait de `DriftedAllowed` : **3 statuts** restants (`compliant`, `drift`, `error`) → **retirer l'item
  `drifted_allowed`** du report ET adapter l'assertion (le test itère `AgentResourceStatus::cases()`, donc il
  s'ajuste seul une fois l'enum réduit — vérifier qu'il reste un item de chacun des 3 statuts ; renommer le
  test `…_four_statuses` → `…_three_statuses`). [Source: tests/Fixtures/Agent/report.v1.json:21-24 ;
  tests/Unit/Services/Agent/ContractV1Test.php:150-191]

### Piège n° 2 — l'agent Go : un seul comportement, simplifier sans casser les invariants conservés

- **`ResolveItemStatus`** (`engine.go:144-155`) : aujourd'hui
  `ResolveItemStatus(isCompliant, mode, lastAppliedHash, targetHash) Verdict`, avec la branche `humanDrift`
  → `drifted_allowed`. Après 27.8 : **`isCompliant ? {compliant, ShouldPersist} : {drift, ShouldApply,
  ShouldPersist}`**. Retirer les paramètres `mode` et `lastAppliedHash` de la signature (D-B). Mettre à jour
  le docblock §5 (`engine.go:123-143`). [Source: agent/shared/engine.go:144-155]
- **`StateItem.Mode`** (`engine.go:54`) + parsing `mode` (`engine.go:79,83` dans `ItemsFromScope`) : retirer
  le champ et son extraction. **Le payload serveur n'émet plus `mode`** → l'agent n'a plus à le lire.
  [Source: agent/shared/engine.go:54,79,83]
- **`RunPass`** (`engine.go:224-231,253,270,288,297`) : retirer la résolution `mode := first.Mode` + le
  warning « mode inconnu » + le passage de `mode`/`lastApplied` à `dispatch`. ⚠️ `targetHash`, `AggregateHash`,
  l'isolation par type, l'ordre serveur, la persistance `applied[typ]` = **CONSERVÉS** (D-B : on garde
  `AppliedState`/`lastApplied` n'est plus passé au verdict, mais la persistance du dernier-appliqué reste).
  [Source: agent/shared/engine.go:193-264,270-300]
- **`contract.go`** : `ResourceStatuses = {"compliant","drift","drifted_allowed","error"}` →
  retirer `drifted_allowed` (3 statuts). [Source: agent/shared/contract.go:39]
- **Handlers Windows non impactés** : `handler_shortcuts_windows.go`/`companion_windows.go` n'utilisent pas
  le `Mode` (le `Mode` vivait dans l'engine seul) — vérifié. Le `Mode` debug d'enveloppe (champ `debug`,
  `companion_windows.go:69-79`) est **un autre champ**, SANS rapport — NE PAS y toucher.
- **Tests Go à réécrire** (cas `default`/`drifted_allowed` à supprimer) :
  - `engine_test.go::TestResolveItemStatusSection5Verbatim` (L43-75) : retirer les cas `default/*` et
    `drifted_allowed` ; ne garder que `compliant` / `drift` ; adapter la signature d'appel (plus de `mode`).
  - `engine_test.go::TestRunPassDefaultModeFullLifecycle` (L83-129) : le scénario « dérive humaine →
    drifted_allowed » (L110-119) **n'existe plus** → réécrire en « dérive → toujours drift + apply ».
  - `engine_test.go` autres usages de `Mode: "default"`/`"strict"` (L80,157,250-259,359) : nettoyer (le
    champ `Mode` disparaît ; `TestRunPassUnknownModeIsStrict` L250+ devient sans objet → supprimer).
  - `handler_shortcuts_test.go:384-414`, `handler_drives_test.go:301-332` : tables `mode`/`drifted_allowed`
    → retirer les cas `default + dérive humaine → drifted_allowed`.
  - Fixtures JSON inline portant `"mode":` (`icon_assets_test.go:14`, `assets_test.go:14`,
    `overlay_logon_test.go:26-30`) : retirer la clé `mode` des payloads de test (l'agent l'ignore désormais,
    mais nettoyer pour cohérence — vérifier qu'aucun test ne l'asserte).

### Piège n° 3 — `AgentResourceStatus` & comptabilité conformité (côté serveur ingest)

- **Enum** `app/Enums/AgentResourceStatus.php:24` : retirer `case DriftedAllowed = 'drifted_allowed';` +
  ajuster le docblock (L8-16). Identifiant « figé NFR12 » → c'est un **retrait assumé** (zéro prod, aucun
  rapport historique à relire). [Source: app/Enums/AgentResourceStatus.php:24]
- **Consommateurs du statut** (l'agent ne l'émettra plus, mais le code serveur le mentionne) :
  - `app/Services/Agent/Reporting/ConformityService.php` : compteur `drifted_allowed` (L77 init, L108
    incrément) + docstring (L25-32) → retirer la clé `drifted_allowed` du tableau de comptage et adapter les
    tests de ce service. ⚠️ **Vérifier** qu'aucune migration/colonne/vue de stats `agent_report_events` ne
    s'attend à la valeur `drifted_allowed` (sinon dette résiduelle — zéro prod, pas de purge de données).
  - `app/Services/Agent/Reporting/ReportIngestService.php:139` : commentaire mentionnant `drifted_allowed` →
    nettoyer (commentaire seulement). [Source: app/Services/Agent/Reporting/ConformityService.php:77,108 ;
    ReportIngestService.php:139]

### Piège n° 4 — migrations : DROP idempotent + `down()` symétrique réversible (mémoire `idempotency`)

- **3 migrations DROP**, une par table, datées **APRÈS** les migrations existantes les plus récentes du tree
  (27.3 = `2026_06_16_1101xx`, 27.7 = `2026_06_16_100000`). Utiliser un préfixe `2026_06_16_1102xx` (ou
  `2026_06_17_…`) pour garantir l'ordre. **Pattern** (calqué sur le `down()` de
  `2026_06_16_110100_drop_mode_from_shortcuts.php` qui RE-CRÉE la colonne) :
  - `up()` : `if (Schema::hasColumn($table,'mode')) { dropColumn('mode'); }` ;
  - `down()` : `if (! Schema::hasColumn($table,'mode')) { string('mode',16)->nullable()->comment('…'); }`
    (RE-CRÉE pour réversibilité). **Pas de DEFAULT SQL** (iso 27.1/27.3).
  - Tables : `shortcut_assignables`, `wallpapers`, `overlay_signals`.
  [Source: database/migrations/2026_06_16_110100_drop_mode_from_shortcuts.php (style down réversible) ;
  database/migrations/2026_06_16_110000_add_mode_to_shortcut_assignables.php ;
  database/migrations/2026_06_15_100100_add_mode_to_wallpapers.php ;
  database/migrations/2026_06_15_100200_add_mode_to_overlay_signals.php]
- **NE PAS** recréer/toucher `shortcuts.mode` : déjà droppée par 27.3 (`2026_06_16_110100`). Rien à faire.

### Piège n° 5 — `StateMode` ne doit plus avoir AUCUN consommateur avant suppression

L'enum `app/Enums/StateMode.php` est importé par **18 fichiers** (app/ + tests/). Le supprimer N'EST POSSIBLE
qu'après avoir nettoyé **tous** les `use App\Enums\StateMode;` :
- app : `Wallpaper.php:7`, `OverlaySignal.php:8`, `Contracts/StateProvider.php:8`, `StateCandidate.php:8`,
  `StateCompiler.php:9`, et les **5 providers** (`Shortcuts:9`, `Drives:9`, `Wallpaper:9`, `Overlay:9`,
  `Printers:9`).
- tests : `StateModeCastTest.php:7`, `ShortcutsStateProviderTest.php:9`, `DrivesStateProviderTest.php:9`,
  `WallpaperStateProviderTest.php:9`, `OverlayStateProviderTest.php:9`, `PrintersStateProviderTest.php:9`,
  `StateCompilerTest.php:9`, `ContractV1Test.php:9`.

**`StateModeCastTest`** (`tests/Unit/Models/StateModeCastTest.php`) teste le cast enum sur Wallpaper/Overlay
(et le retrait du cast shortcut depuis 27.3). Après 27.8 : **le cast n'existe plus** → **supprimer ce
fichier de test** (inotify ne sync pas les deletes — mémoire `inotify_no_delete_sync` : à noter en cleanup
VM, mais on est en worktree → seulement documenter). [Source: rapport d'inventaire — `use StateMode`
exhaustif]

> **`grep -rn "StateMode" app/ tests/ database/` doit être VIDE** après nettoyage (sauf si l'on garde des
> migrations historiques `add_mode_*` qui ne référencent PAS l'enum — elles utilisent `string('mode',16)`,
> donc pas d'import). Vérification finale obligatoire (AC9).

### Piège n° 6 — providers : retirer `mode()` + la lecture de colonne, garder le reste intact

| Provider | `mode()` | Lecture colonne mode | Action |
|----------|----------|----------------------|--------|
| `ShortcutsStateProvider` | L76-83 (Strict) | L145 `shortcut_assignables.mode as assignment_mode` + L156 `StateMode::tryFrom` | retirer `mode()`, la sélection `assignment_mode`, le cast, le passage `mode:` au candidat |
| `WallpaperStateProvider` | L50-53 (Default) | L102 `wallpapers.mode` + L119 `mode: $row->mode` | retirer `mode()`, la sélection, le passage `mode:` |
| `OverlayStateProvider` | L55-58 (Strict) | L97 `$signal->mode` + L154 candidat `identity` mode | retirer `mode()`, la lecture, le passage `mode:` |
| `DrivesStateProvider` | L81-86 (Strict) | aucune | retirer `mode()` seulement |
| `PrintersStateProvider` | L77-82 (Strict) | aucune | retirer `mode()` seulement |

⚠️ Le reste de chaque provider (ciblage par maille, payload, `StateCandidate` sans `mode`) = **inchangé**.
`StateCandidate` devient `(maille, payload, updatedAt, sourceId)` — retirer le 5e paramètre `?StateMode $mode`
(`StateCandidate.php:43`) → tous les appels `new StateCandidate(..., mode: …)` perdent leur dernier argument.
[Source: rapport d'inventaire §4 ; app/Services/Agent/StateCandidate.php:43]

### Piège n° 7 — `StateCompiler` : retirer l'agrégation, garder dédup/exclusif/ordre

- `aggregateMode()` (L185-196) + tout le bloc de calcul du mode (L137-156) + la clé `'mode' => $mode` de
  l'item (L163) → **supprimés**. L'item assemblé passe à **4 clés** (`type, semantics, payload, hash`).
- **CONSERVÉS** : `selectAggregate()` (dédup par contenu, L220-239), `selectExclusive()` (L265-303),
  `specificity()` (L310-320), `contentKey()` (L249-252), l'ordre déterministe, `sortedProviders()`. La dédup
  aggregate par contenu reste pertinente (un raccourci sur 2 mailles = 1 item) — **ne PAS la retirer**.
- ⚠️ Le commentaire L137-155 explique la subtilité « strict-wins pré-dedup » (correctif review 27.3 #1) :
  il devient **sans objet** (plus de mode) → le nettoyer entièrement.
[Source: app/Services/Agent/StateCompiler.php:137-196,220-239,265-320]

### Piège n° 8 — UI : retirer 3 surfaces de toggle, ne pas régresser le geste métier

- **Shortcuts modale** (`shortcut-assignment-modal.blade.php`) : propriété `$mode` (L41), reset (L87), toggle
  checkbox (L496-498), texte (L502-504), dispatch `mode:` (L240), validation `in_array(...,['strict','default'])`
  (L240). Retirer **tout le `mode`** ; le geste d'assignation (`syncWithoutDetaching([$id => […]])`) ne pose
  plus `'mode' => …`. Côté page `shortcuts/[id]/index.blade.php` : `onAssignmentsConfirmed()` perd le param
  `$mode` et le `['mode' => …]` du pivot.
- **Wallpaper** (`wallpaper-card.blade.php`) : méthode `setMode()` (L218-234), `$currentMode` (L318), boutons
  Strict/Souple (L323-333). Retirer la méthode + les boutons + le bloc UI.
- **Overlay** (`overlay-messages/index.blade.php`) : propriété `$mode` (L33), règle de validation
  `'mode' => [...]` (L79), cast création `StateMode::tryFrom` (L97), transaction de pose du mode (L99-112),
  `<select wire:model="mode">` (L262-265). Retirer ; la création du signal ne pose plus de mode.
- **shortcut-form.blade.php** : déjà sans select mode (retiré en 27.3, commentaire L113-116) — nettoyer le
  commentaire résiduel si présent.
- **Réutilisables** (CLAUDE.md projet) : `WithToasts`, modale réutilisable — **inchangés** (on retire, on
  n'ajoute pas).
- **Tests UI à retirer/adapter** : `ShortcutModeTogglePageTest` (toggle 27.3) devient sans objet → supprimer
  ou réécrire pour prouver l'ABSENCE de champ mode dans l'assignation. Vérifier `OverlayMessages*` /
  `wallpaper` feature tests qui asserteraient un mode.
[Source: rapport d'inventaire §6]

### Piège n° 9 — environnement, conventions reconduites

- **VM migrations PAS auto-jouées** (mémoire `vm_migrations_not_auto_applied`) : dev-cycle migre SQLite only.
  Lister l'action `/vm` : `migrate:status` → `php artisan migrate --force` (3 migrations DROP). **PAS** de
  `config:cache`/`route:cache` (aucun `config/*.php` ni route ajouté). Worktree = JAMAIS de VM ici (mémoire
  `worktree_no_vm_sync`) ; l'action `/vm` se joue depuis `main` après merge.
- **Tests SQLite n'appliquent pas varchar** (mémoire `sqlite_tests_no_varchar_enforcement`) : couverture
  critique = fonctionnelle (provider sans mode, compilateur item à 4 clés, golden/hash croisés).
- **NFR7** : grep `ldap|apcu|samba-tool` sur les 5 providers doit rester vide (reconduit). On ne touche pas
  au ciblage.
- **routes/api.php** : aucune route ajoutée (mémoire `api_routes_arch_test_window_trap` sans objet).
- **inotify ne propage pas les deletes** (mémoire `inotify_no_delete_sync`) : cette story SUPPRIME des
  fichiers (`app/Enums/StateMode.php`, `tests/Unit/Models/StateModeCastTest.php`, peut-être
  `ShortcutModeTogglePageTest.php`). En worktree on ne fait que les supprimer du repo ; **noter en cleanup
  `/vm` (action humaine) que ces fichiers fantômes resteront sur la VM** après merge — à `trash` côté VM
  (mémoire sécurité : `trash`, jamais `rm -rf`).
- **Go = hôte uniquement** (`~/go-toolchain/go/bin/go`, package main = `agent/windows`) ; `go test ./...` +
  `go vet` (linux + `GOOS=windows`) + cross-compile sur l'hôte.

## Acceptance Criteria

### AC1 — Schéma : DROP des 3 colonnes `mode`, idempotent + réversible (FR26 retiré)

**Given** la décision Henri « STRICT partout, retrait total » (zéro prod)
**When** les migrations sont jouées
**Then** la colonne `mode` est **droppée** de `shortcut_assignables`, `wallpapers` et `overlay_signals` ; les
3 migrations sont **idempotentes** (`Schema::hasColumn` en garde), `down()` **symétrique** qui RE-CRÉE
`string('mode',16)->nullable()` (réversibilité), `->comment()` daté 27.8, datées APRÈS le tree existant
(`2026_06_16_1102xx` ou ultérieur), **sans DEFAULT SQL**
**And** `shortcuts.mode` n'est **pas** touchée (déjà droppée par 27.3).

### AC2 — Agent Go : un seul comportement (strict), `ResolveItemStatus` simplifié (FR21, NFR13)

**Given** que `drifted_allowed` n'est plus une politique
**When** l'agent converge un type non conforme
**Then** `ResolveItemStatus(isCompliant)` rend `compliant` si réel=cible, sinon `drift` + `ShouldApply` +
`ShouldPersist` — **toujours** (plus de branche `default`/`humanDrift`, signature sans `mode` ni
`lastAppliedHash`)
**And** `StateItem.Mode` et le parsing du champ `mode` (`ItemsFromScope`) sont **retirés** ; `RunPass` ne
résout plus de mode ; `contract.go::ResourceStatuses` ne contient plus `drifted_allowed` (3 statuts)
**And** la persistance `AppliedState` (dernier-appliqué/horodatage), `AggregateHash`, l'isolation par type,
l'ordre serveur sont **conservés** (D-B) ; `go test ./...` + `go vet` (linux + `GOOS=windows`) +
cross-compile **verts**.

### AC3 — Contrat & golden : item à 4 clés, statut `drifted_allowed` retiré, hashes bumpés croisés (NFR13, D-A)

**Given** le retrait de `mode` du payload et du report
**When** les tests de contrat tournent
**Then** chaque item d'état de `tests/Fixtures/Agent/state.v1.json` porte **exactement 4 clés**
(`type, semantics, payload, hash`) ; `ContractV1Test::every_state_item_has_…` assert ces 4 clés (l'assertion
`StateMode::tryFrom` retirée) ; les `hash` d'items et `FROZEN_STATE_HASH` (PHP) sont **recalculés par le
StateHasher RÉEL** et bumpés sciemment (commentaire daté 27.8)
**And** `agent/shared/hasher_test.go::frozenStateHash` est bumpé à la **MÊME valeur** (test croisé
`TestHashStateGoldenMatchesFrozenHash` vert)
**And** `tests/Fixtures/Agent/report.v1.json` ne contient plus d'item `drifted_allowed` ;
`AgentResourceStatus` a **3 cas** ; le test report illustre les 3 statuts (`compliant`, `drift`, `error`)
**And** le schéma reste `se5.desired-state/v1` (PAS de bump v2 — D-A).

### AC4 — `StateMode` supprimé, zéro consommateur (FR26 retiré)

**Given** le nettoyage de tous les `use App\Enums\StateMode;`
**When** on grep le code
**Then** `app/Enums/StateMode.php` est **supprimé** ; `grep -rn "StateMode" app/ tests/` est **vide** (les
migrations historiques `add_mode_*` n'importent pas l'enum — `string('mode',16)`)
**And** `StateCandidate` n'a plus de propriété `$mode` (constructeur à 4 paramètres) ;
`StateProvider::mode()` retiré de l'interface ; `StateCompiler::aggregateMode()` retiré ; l'item compilé a 4
clés.

### AC5 — Providers : plus aucune lecture/déclaration de mode (FR21, NFR7)

**Given** les 5 providers
**When** `itemsFor()` produit ses candidats
**Then** aucun provider ne déclare `mode()` ni ne lit de colonne `mode` (`shortcut_assignables.mode`,
`wallpapers.mode`, `$signal->mode`) ni ne passe `mode:` au `StateCandidate`
**And** le ciblage par maille, les payloads, la sémantique et le scope de chaque provider sont **inchangés** ;
grep `ldap|apcu|samba-tool` sur les 5 providers → **vide** (NFR7).

### AC6 — UI : tous les toggles/sélecteurs mode retirés, geste métier intact (FR26 retiré, FR19)

**Given** les 3 surfaces UI portant le mode
**When** l'admin assigne un raccourci / choisit un wallpaper / crée un signal overlay
**Then** **aucun** toggle/sélecteur strict/default n'est présent (shortcuts modale, wallpaper-card,
overlay-messages) ; l'assignation/persistance ne pose plus de `mode`
**And** le geste métier (assignation de cibles, choix d'asset, création de signal) **fonctionne sans
régression** (aucun `wire:model="mode"`, aucun `setMode`, aucun champ `mode` résiduel) ; `WithToasts` /
modale réutilisable inchangés.

### AC7 — `AgentResourceStatus` & conformité : 3 statuts, comptabilité nettoyée

**Given** le retrait de `DriftedAllowed`
**When** le service de conformité agrège un rapport
**Then** `AgentResourceStatus` a 3 cas (`Compliant`, `Drift`, `Error`) ; `ConformityService` ne compte plus
`drifted_allowed` (clé retirée du tableau, docstring à jour) ; `ReportIngestService` sans mention résiduelle
**And** aucune régression d'ingestion (un rapport sans `drifted_allowed` est ingéré normalement) ; tests
conformité adaptés.

### AC8 — Tests : serveur + agent (suppressions + non-régression) (NFR13)

**Then** côté **Laravel** :
- `ContractV1Test` : 4 clés d'item, 3 statuts report, `FROZEN_STATE_HASH` bumpé, hashes d'items cohérents
- providers (`Shortcuts`/`Wallpaper`/`Overlay`/`Drives`/`Printers`StateProviderTest) : tout cas portant
  `mode`/`StateMode` retiré ou réécrit sans mode
- `StateCompilerTest` : cas d'agrégation strict/default (mix → strict) **retirés** ; dédup/exclusif/ordre
  conservés et verts
- `StateModeCastTest` **supprimé** ; `ShortcutModeTogglePageTest` supprimé ou réécrit (absence du champ mode)
- conformité (`ConformityService` test) sans `drifted_allowed`
- non-régression `--filter Agent` sur `/vm` (baseline relevée au début ; `ContractV1Test` vert AVEC le
  nouveau hash)

**And** côté **agent Go** : `engine_test.go`, `handler_shortcuts_test.go`, `handler_drives_test.go`,
`companion_test.go`, fixtures JSON inline — cas `default`/`drifted_allowed` retirés ; `go test ./...` +
`go vet` (linux + `GOOS=windows`) + cross-compile **verts**.

### AC9 — Documentation + QA (append-only) + vérification finale

**Then** `docs/agent/contract-v1.md` : §5 « GAP 1 » (strict/default/drifted_allowed) **retiré ou réécrit**
en « convergence strict inconditionnelle » ; l'item illustratif passe à 4 clés ; le tableau des clés perd
`mode` ; l'enum `status` perd `drifted_allowed`
**And** `docs/agent/state-providers.md` : sections `mode()`/« mode par assignation » (L41-47, 218-224,
381-407) **retirées** ; note de révision « 27.8 retire le mode (27.1 l'a introduit, 27.3 déplacé) »
**And** `docs/qa/domains/agent.md` enrichi **append-only** (`## Story 27.8` sans renuméroter : convergence
strict partout, dérive humaine toujours corrigée, plus de `drifted_allowed`) ; ligne 27.8 dans
`docs/qa/README.md` ; scénarios obsolètes (4.4, 6.6, 13.5, 16) signalés comme **abrogés par 27.8** (note,
pas suppression — append-only)
**And** **vérification finale** : `grep -rn "StateMode\|drifted_allowed" app/ tests/ agent/ docs/` ne laisse
QUE des mentions historiques/abrogées documentées ; `php -l` sur les PHP touchés ; NFR7 grep vide sur les 5
providers.

## Tasks / Subtasks

- [x] **T0 — Baseline & décisions** (toutes AC)
  - [x] Relever la baseline `ContractV1Test` + `--filter Agent` (à rejouer `/vm` post-merge, pas en worktree).
  - [x] Confirmer D-A (reste v1) et D-B (verdict simplifié, store conservé) — défauts appliqués sauf
        contre-ordre Henri (questions non bloquantes en bas).

- [x] **T1 — Migrations : DROP des 3 colonnes mode** (AC1)
  - [x] `database/migrations/2026_06_16_110200_drop_mode_from_shortcut_assignables.php` — `dropColumn('mode')`
        gardé `Schema::hasColumn`, `down()` RE-CRÉE `string('mode',16)->nullable()->comment('… 27.8 …')`.
  - [x] idem `…110201_drop_mode_from_wallpapers.php` et `…110202_drop_mode_from_overlay_signals.php`.
  - [x] Style calqué sur le `down()` réversible de `2026_06_16_110100_drop_mode_from_shortcuts.php`. Pas de
        DEFAULT SQL. NE PAS toucher `shortcuts.mode`.

- [x] **T2 — Modèles** (AC4, AC5)
  - [x] `Wallpaper.php` : retirer `@property mode`, `'mode'` de `$fillable`, `'mode' =>
        StateMode::class` de `$casts`, import `StateMode`.
  - [x] `OverlaySignal.php` : idem.
  - [x] `Shortcut.php` : retirer `->withPivot('mode')` des relations `workstationGroups()` et
        `workstations()`.

- [x] **T3 — `StateCandidate` / `StateProvider` / providers** (AC4, AC5)
  - [x] `StateCandidate.php` : retirer le paramètre `?StateMode $mode`, l'import, le docblock mode.
        Constructeur à 4 paramètres.
  - [x] `Contracts/StateProvider.php` : retirer `mode()`, l'import, le docblock mode.
  - [x] 5 providers : retirer `mode()` + lecture colonne mode (`assignment_mode`, `wallpapers.mode`,
        `$signal->mode`, `identity` mode) + passage `mode:` au candidat + import `StateMode`.

- [x] **T4 — `StateCompiler`** (AC4)
  - [x] Retirer `aggregateMode()`, le bloc calcul mode, `'mode' => $mode` de l'item, l'import `StateMode`.
        Item à 4 clés. Commentaire « strict-wins pré-dedup » nettoyé.
  - [x] CONSERVÉS `selectAggregate`/`selectExclusive`/`specificity`/`contentKey`/`sortedProviders` + ordre.

- [x] **T5 — Agent Go : engine + contract** (AC2, AC3)
  - [x] `engine.go` : `ResolveItemStatus(isCompliant)` (retiré `mode`/`lastAppliedHash`/branche
        `humanDrift`), retiré `StateItem.Mode` + parsing, `RunPass` sans mode ; docblock §5 réécrit ;
        CONSERVÉS `AppliedState`/persistance/`AggregateHash`/isolation.
  - [x] `contract.go` : `ResourceStatuses` sans `drifted_allowed` (3 statuts).

- [x] **T6 — UI** (AC6)
  - [x] `shortcut-assignment-modal.blade.php` : retiré `$mode`, reset, toggle checkbox + texte,
        dispatch/validation mode.
  - [x] `shortcuts/[id]/index.blade.php` : `onAssignmentsConfirmed()` sans param `$mode` ni `['mode'=>…]`
        (syncWithoutDetaching avec ids nus).
  - [x] `wallpaper-card.blade.php` : retiré `setMode()`, `$currentMode`, boutons Strict/Souple.
  - [x] `overlay-messages/index.blade.php` : retiré `$mode`, validation, cast, transaction mode, `<select>`.
  - [x] `shortcut-form.blade.php` : commentaire résiduel mode retiré.

- [x] **T7 — `AgentResourceStatus` & conformité** (AC7)
  - [x] `AgentResourceStatus.php` : retiré `DriftedAllowed` + docstring (3 cas).
  - [x] `ConformityService.php` : retiré la clé `drifted_allowed` ($base + match), `STATUS_PRECEDENCE`,
        docstrings.
  - [x] `ReportIngestService.php` : commentaire nettoyé.
  - [x] **ÉCART périmètre (consommateurs non listés mais nécessaires)** : `WorkstationGroupRepository`
        (filtre `drifted_allowed` retiré + docstring) ; 5 surfaces UI conformité (`conformity-badge.blade.php`
        map, `stats-cards.blade.php` compteur, `machines-tab.blade.php` option select, `parc/index.blade.php`
        commentaire/valeurs, `conformity-panel.blade.php` badge groupe) — sinon filtre/compteur orphelins.

- [x] **T8 — Golden files & hashes croisés** (AC3)
  - [x] `state.v1.json` : `"mode"` retiré des 5 items, 5 `hash` d'items recalculés via le hasher Go
        (helper jetable, supprimé après).
  - [x] `report.v1.json` : item `drifted_allowed` retiré (3 items, 3 statuts).
  - [x] `ContractV1Test::FROZEN_STATE_HASH` + `hasher_test.go::frozenStateHash` bumpés à la MÊME valeur
        `4d0c2c9406c448c8febb05807f33bb8c53af17aec0c9051ca7a4d4fddbf93579` (commentaire daté 27.8).
        `every_state_item_has_…` → 4 clés (sans `StateMode::tryFrom`) ; `report_…` → 3 statuts.

- [x] **T9 — Tests** (AC8)
  - [x] PHP : adaptés `ContractV1Test`, 5 providers tests, `StateCompilerTest` (section agrégation mode
        supprimée + 4 clés), conformité (`ConformityServiceTest`/`ReportIngestServiceTest`),
        `StateHasherTest`, `StateEndpointTest`, feature (`HandlersE2eTest`/`ReportEndpointTest`/
        `AgentSkeletonE2eTest`/`ParcConformityTest`/`OverlayMessagesPageTest`) ; **supprimé**
        `StateModeCastTest` ; **réécrit** `ShortcutModeTogglePageTest` (prouve l'ABSENCE du mode).
  - [x] Go : `engine_test.go` (signature `ResolveItemStatus(isCompliant)`, cas default/drifted_allowed
        retirés, `TestRunPassUnknownModeTreatedAsStrict` supprimé), `handler_{shortcuts,drives,printers}_test.go`,
        `companion_test.go`, `contract_test.go` (3 statuts), fixtures inline (`icon_assets`/`assets`/
        `overlay_logon`/`overlay_compose`) — `mode`/`drifted_allowed` retirés.

- [x] **T10 — Suppression `StateMode` + enum** (AC4)
  - [x] `app/Enums/StateMode.php` supprimé ; `grep -rn "StateMode" app/ tests/` VIDE (occurrences restantes =
        docblocks des migrations historiques `add_mode_*` dans `database/`, sans import).

- [x] **T11 — Docs + QA** (AC9)
  - [x] `docs/agent/contract-v1.md` (§5 réécrit « STRICT inconditionnel », item 4 clés, status 3 valeurs,
        préambule).
  - [x] `docs/agent/state-providers.md` (interface `mode()` retirée, section provenance → note 27.8, titres
        providers, checklist).
  - [x] `docs/qa/domains/agent.md` append-only `## Section 18 — Story 27.8` + scénarios 4.4/6.6/13.5 et
        Section 16 notés abrogés (+ items checklist) ; ligne 27.8 `docs/qa/README.md`.
  - [x] Bonus cohérence : `report-endpoint.md`, `agent-skeleton.md`, `handlers-wallpaper-overlay.md` (counts
        3 statuts + machine d'états §5 STRICT) — sinon docs trompeuses.

- [x] **T12 — Validation finale** (toutes AC)
  - [x] `php -l` sur tous les PHP touchés : OK. NFR7 grep `ldap|apcu|samba-tool` sur 5 providers = docblocks
        seulement (zéro code). Grep final `StateMode|drifted_allowed` = mentions documentaires/abrogées.
  - [x] Go : `go test -count=1 ./...` + `go vet` (linux + `GOOS=windows`) + cross-compile windows = **VERTS**.
        Tests croisés golden (`TestHashStateGoldenMatchesFrozenHash`, `TestHashItemGoldenItemsMatchTheirHashFields`,
        `TestCanonicalizeProducesPhpCanonicalForm`) verts = preuve du hash figé.
  - [ ] **Actions `/vm` (PAS auto, post-merge depuis main, JAMAIS depuis le worktree)** :
        `migrate:status` → `php artisan migrate --force` (3 DROP) ; `trash` des fichiers fantômes
        (`StateMode.php`, `StateModeCastTest.php`) — inotify ne propage pas les deletes ;
        rejouer PHPUnit `--filter Agent` (non exécutable sur l'hôte : vendor absent). Pas de
        `config:cache`/`route:cache`. → **action humaine post-merge**.
  - [ ] **Validation lab (Windows) — ACTION HUMAINE Henri** : une suppression humaine d'un raccourci/wallpaper
        géré est **toujours recréée** à la passe suivante (plus de `drifted_allowed`, comportement strict).

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (27.8) | Hors-scope |
|---|---|
| DROP `mode` des 3 tables (migrations idempotentes + down réversible) | Décommissionnement canal legacy → 27.6 |
| Suppression `StateMode`, `StateCandidate::$mode`, `StateProvider::mode()`, `aggregateMode()` | Refonte du store applied-state per-user (conservé — D-B) |
| Providers sans lecture/déclaration de mode | Bump de version schéma v2 (reste v1 — D-A) |
| Agent Go : un comportement, `ResolveItemStatus` simplifié, `ResourceStatuses` à 3 statuts | Changement des autres invariants contrat (canonicalisation, hash opaque, semantics, AggregateHash) |
| UI : 3 toggles retirés | Mode « par item » au payload (l'alternative non retenue) |
| Golden 4 clés + report 3 statuts + bumps croisés PHP↔Go | Toute modif du ciblage (NFR7 reconduit) |
| `AgentResourceStatus` 3 statuts + conformité nettoyée | Purge des données `agent_report_events` (zéro prod) |
| Docs/QA | — |

### Ce qu'on RÉVISE / ANNULE de 27.1 et 27.3

[Source: _bmad-output/implementation-artifacts/27-1-handler-raccourcis-convergence-bureau.md L160-168]
[Source: _bmad-output/implementation-artifacts/27-3-drift-policy-par-assignation.md (toute la story)]
[Source: _bmad-output/codeReviews/27-3.md Q1, #6 — grain type×poste, pas item×cible]

- 27.1 a **introduit** le mode (3 colonnes, cast, candidate, agrégation, UI, machine d'états §5
  `drifted_allowed`). 27.8 **retire tout cela**.
- 27.3 a **déplacé** le mode shortcuts règle→assignation. 27.8 **annule ce déplacement** en droppant la
  colonne pivot.
- **Ce qui SURVIT de 27.1/27.3** : le pattern provider+compilateur+contrat (sans le mode), la dédup
  aggregate, le handler shortcuts, l'agent Go (simplifié), le golden (re-bumpé).

### L'infra réutilisée (ne PAS réinventer)

[Source: app/Services/Agent/StateCompiler.php (dédup/exclusif/ordre conservés) ; agent/shared/engine.go
(AggregateHash, isolation, AppliedState conservés) ; app/Services/Agent/StateHasher.php (canonicalisation
inchangée — c'est elle qui recalcule les hashes au bump)]

- `StateHasher` (PHP) / `hasher.go` (Go) : **inchangés** — ils recalculent simplement les hashes sur le
  payload réduit. Le bump n'est PAS un changement de hasher, juste un nouveau contenu.
- `AggregateHash`, `selectAggregate`/`selectExclusive`, `specificity` : conservés tels quels.

### Migrations existantes de référence (style)

[Source: database/migrations/2026_06_16_110100_drop_mode_from_shortcuts.php — down réversible RE-CRÉE la
colonne ; 2026_06_16_110000_add_mode_to_shortcut_assignables.php ; 2026_06_15_100100_add_mode_to_wallpapers.php ;
2026_06_15_100200_add_mode_to_overlay_signals.php]

### Environnement de dev — règles VM / worktree

- Code à la RACINE (app/, agent/, …) ; édité sur l'hôte, sync inotify auto, **jamais de sync manuelle**.
- **Worktree** : JAMAIS de SSH `/vm` ni de tests sur VM depuis ce worktree (mémoire `worktree_no_vm_sync`).
  Les actions `/vm` (migrate, trash fantômes, bump hash calculé) se jouent **depuis main après merge**.
- **Go = hôte** (`~/go-toolchain/go/bin/go`) ; PHPUnit sur `/vm` (post-merge).
- **inotify ne propage pas les deletes** : fichiers supprimés (`StateMode.php` etc.) → fantômes sur VM à
  `trash` (action humaine).

### Dépendances

| Story | Rôle pour 27.8 | Statut (sprint-status.yaml) | Bloquant ? |
|-------|----------------|------------------------------|------------|
| 27.1 — mode par règle | Porte le mode introduit (colonnes, cast, candidate, agrégation, UI, §5 agent). 27.8 le retire. | `review` | **Prérequis fort** — en `review` ; **rebaser si correctifs 27.1** (recouvrement total : migrations mode, providers, StateCompiler, engine.go, UI, golden). |
| 27.3 — drift policy par assignation | A déplacé le mode shortcuts règle→pivot. 27.8 annule (drop pivot). | `review` | **Prérequis fort** — recouvrement (migration pivot mode, ShortcutsStateProvider, UI modale, StateCompiler). Rebaser si correctifs. |
| 27.2 / 27.7 / 27.1bis | Voisins Epic 27 en review ; 27.2 a posé printers/drives (mode() Strict à retirer), 27.7 a bumpé le golden (a43e8aad… = valeur courante à re-bumper), 27.1bis touche golden overlay/report. | `review` | Recouvrement golden/hash + providers — **relever la valeur courante du tree avant bump** (mémoire : le golden a déjà bougé en 27.7). |
| 23.4 / 24.6 / 23.1 | StateCompiler/StateProvider/engine/contrat/golden — infra réutilisée et simplifiée. | `done` | Non (consommé). |

> **Recouvrement fort 27.1/27.3 (review)** : 27.8 démonte ce que 27.1/27.3 ont posé. Si l'une reçoit des
> correctifs post-review, **rebaser** avant de finaliser 27.8 (mêmes fichiers : migrations mode, providers,
> StateCompiler, engine.go, UI, golden, FROZEN hashes).

### References

- [Source: _bmad-output/codeReviews/27-3.md:18-40,106-113] — Q1/#6 : grain mode = type×poste, pas item×cible
  (motivation directe du retrait).
- [Source: _bmad-output/implementation-artifacts/27-1-handler-raccourcis-convergence-bureau.md:160-168] —
  introduction du mode (décision Henri révisée/annulée).
- [Source: _bmad-output/implementation-artifacts/27-3-drift-policy-par-assignation.md] — déplacement du mode
  (annulé).
- [Source: app/Enums/StateMode.php] — enum à supprimer.
- [Source: app/Enums/AgentResourceStatus.php:24] — `DriftedAllowed` à retirer.
- [Source: app/Services/Agent/StateCandidate.php:43] — propriété `$mode` à retirer.
- [Source: app/Services/Agent/Contracts/StateProvider.php:47] — `mode()` à retirer.
- [Source: app/Services/Agent/StateCompiler.php:137-196] — `aggregateMode()` + bloc mode à retirer ;
  dédup/exclusif/ordre conservés.
- [Source: app/Services/Agent/Providers/ShortcutsStateProvider.php:76-83,145,156] — `mode()` + lecture pivot.
- [Source: app/Services/Agent/Providers/WallpaperStateProvider.php:50-53,102,119] — `mode()` + `wallpapers.mode`.
- [Source: app/Services/Agent/Providers/OverlayStateProvider.php:55-58,97,154] — `mode()` + `$signal->mode`.
- [Source: app/Services/Agent/Providers/DrivesStateProvider.php:81-86 ; PrintersStateProvider.php:77-82] —
  `mode()` constants à retirer.
- [Source: app/Models/Wallpaper.php:7,29,51,56 ; OverlaySignal.php:8,27,43,48 ; Shortcut.php:160,174] — mode
  modèles.
- [Source: app/Services/Agent/Reporting/ConformityService.php:25-32,77,108 ; ReportIngestService.php:139] —
  comptabilité `drifted_allowed`.
- [Source: agent/shared/engine.go:54,79,83,123-155,193-300] — `StateItem.Mode`, `ResolveItemStatus`,
  `RunPass`.
- [Source: agent/shared/contract.go:39] — `ResourceStatuses`.
- [Source: agent/shared/engine_test.go:43-129,250-259,359 ; handler_shortcuts_test.go:384-414 ;
  handler_drives_test.go:301-332] — tests `default`/`drifted_allowed`.
- [Source: tests/Fixtures/Agent/state.v1.json:11,21,31,44,57 ; report.v1.json:21-24] — golden mode/statut.
- [Source: tests/Unit/Services/Agent/ContractV1Test.php:58,105-114,150-191 ; agent/shared/hasher_test.go:27] —
  hashes figés + assertions clés/statuts.
- [Source: resources/views/components/organisms/shortcut-assignment-modal.blade.php:41,87,240,496-504 ;
  resources/views/components/molecules/wallpaper-card.blade.php:218-234,318-333 ;
  resources/views/pages/parc-settings/overlay-messages/index.blade.php:33,79,97,99-112,262-265 ;
  resources/views/pages/shortcuts/[id]/index.blade.php] — toggles UI.
- [Source: docs/agent/contract-v1.md (§5 GAP 1, tableau clés, enum status) ; docs/agent/state-providers.md
  (L41-47,218-224,381-407) ; docs/qa/domains/agent.md (4.4,6.6,13.5,16)] — docs/QA.

## Questions pour Henri (NON bloquantes — défauts appliqués)

1. **Version de schéma (D-A).** Défaut appliqué : **rester `se5.desired-state/v1`**, bumper sciemment le hash
   figé (zéro prod → rupture interne assumée, aucun agent à protéger d'un major). Bumper en `v2` serait un
   geste de contrat vide de sens ici. → si Henri préfère matérialiser la rupture par un v2, le dire (impacte
   `ContractMajor` Go + tous les fixtures).

2. **Devenir du store applied-state (D-B).** Défaut appliqué : **simplifier le verdict** (`ResolveItemStatus`
   sans `mode`/`lastAppliedHash`) mais **conserver** la persistance `AppliedState` (traçabilité
   dernier-appliqué, décision 24.4 n° 9). En strict pur elle n'est plus *nécessaire* au verdict ; la retirer
   serait une refonte du store per-user (loop/companion/sessionstore) hors périmètre. → si Henri veut purger
   le store, story de suivi.

3. **Scénarios QA obsolètes (4.4, 6.6, 13.5, 16).** Défaut appliqué : les **noter abrogés par 27.8**
   (append-only, pas de suppression de l'historique QA). → OK ?

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : story **transverse à haut risque** malgré une apparence de « simple retrait ». Elle touche
**schéma → modèles → providers → compilateur → UI → AGENT Go → contrat/golden → enum statut → conformité →
docs**, soit ~25 fichiers, et surtout elle **rompt sciemment le wire format** (item 5→4 clés) avec un **bump
de hash croisé PHP↔Go** dont l'erreur (oubli d'un item, divergence PHP/Go, mauvaise valeur figée) casse le
contrat agent silencieusement (NFR13). Trois pièges exigent un raisonnement rigoureux : (a) recalculer
correctement les golden hashes via le hasher réel et prouver l'égalité PHP↔Go ; (b) simplifier `engine.go`
(machine d'états §5, signature `ResolveItemStatus`) **sans toucher** aux invariants conservés
(`AppliedState`, `AggregateHash`, isolation) ; (c) garantir `grep StateMode` vide (18 consommateurs) avant
de supprimer l'enum, et réduire `AgentResourceStatus` sans casser l'ingestion. Le coût d'une régression
contrat est élevé, le périmètre est large et croisé. `opus`.

## Dev Agent Record

### Agent Model Used

`claude-opus-4-8[1m]`

### Debug Log References

- Recalcul des golden hashes (côté Go, sur l'hôte) via helper jetable
  `agent/shared/zz_recompute_test.go` → `TestZZRecompute` (lecture du golden +
  `HashItem`/`HashState`), helper supprimé après report. **Piège de séquencement
  rencontré** : la 1re valeur de hash d'état loggée (`83ac3c29…`) avait été
  calculée AVANT le report des nouveaux hash d'items dans le golden ; `HashState`
  inclut les clés `hash` des items, donc le hash d'état correct (golden final) est
  `4d0c2c94…` (donné par `TestHashStateGoldenMatchesFrozenHash` une fois les hash
  d'items reportés). Valeur figée corrigée des deux côtés.
- Consommateurs Go de `StateItem.Mode` non listés dans la story, trouvés à la
  compilation (`go test`) : helpers `driveItem`/`printerItem`/`shortcutItem`/
  `overlayIdentityItem`/`overlayAlertItem` + `overlay_compose_test.go`. Corrigés.

### Completion Notes List

- **Comportement cible = STRICT inconditionnel.** Le mécanisme `mode ∈ {strict,
  default}` est entièrement retiré : la cible fait TOUJOURS loi, la dérive humaine
  est toujours corrigée, `drifted_allowed` disparaît comme politique ET comme
  statut. D-A (reste `v1`) et D-B (verdict simplifié, store applied-state conservé)
  appliqués.
- **Schéma (T1)** : 3 migrations DROP idempotentes (`Schema::hasColumn`), `down()`
  RE-CRÉE la colonne nullable (réversible), datées `2026_06_16_110200/01/02` (après
  le tree), sans DEFAULT SQL. `shortcuts.mode` non touchée (déjà droppée par 27.3).
- **Serveur (T2-T4, T7)** : `StateMode` supprimé (zéro consommateur, grep vide
  avant suppression) ; `StateCandidate` à 4 paramètres ; `StateProvider::mode()`
  retiré de l'interface ; `StateCompiler::aggregateMode()` + bloc mode + clé `mode`
  de l'item supprimés → **item à 4 clés** (`type, semantics, payload, hash`) ;
  5 providers sans `mode()`/lecture colonne/passage `mode:` (ciblage, payloads,
  dédup INCHANGÉS) ; modèles Wallpaper/OverlaySignal (fillable/casts/property) et
  Shortcut (`withPivot('mode')`) nettoyés ; `AgentResourceStatus` à **3 cas** ;
  `ConformityService`/`ReportIngestService` nettoyés.
- **Agent Go (T5)** : `ResolveItemStatus(isCompliant)` (signature réduite, branche
  `humanDrift` retirée) ; `StateItem.Mode` + parsing retirés ; `RunPass`/`dispatch`
  sans mode ; `contract.go::ResourceStatuses` à 3 statuts ; `AppliedState`,
  `AggregateHash`, isolation par type, ordre serveur, persistance dernier-appliqué
  **CONSERVÉS** (D-B). `go test`/`vet` linux+windows/cross-compile windows VERTS.
- **Contrat/golden (T8)** : `mode` retiré des 5 items + 5 hash d'items recalculés ;
  item `drifted_allowed` retiré du report ; **hash d'état figé bumpé croisé
  PHP↔Go** : `a43e8aad…` → **`4d0c2c9406c448c8febb05807f33bb8c53af17aec0c9051ca7a4d4fddbf93579`**.
  Les tests croisés Go prouvent l'égalité (le calcul PHP retombera sur la même
  valeur par parité prouvée du hasher — validation PHPUnit = action `/vm`).
- **UI (T6)** : 3 toggles retirés (modale assignation, carte wallpaper, création
  overlay) ; geste métier intact (`WithToasts`/modale réutilisable inchangés).
- **Tests (T9)** : adaptés (4 clés, 3 statuts, signature Go) ; `StateModeCastTest`
  supprimé ; `ShortcutModeTogglePageTest` réécrit (prouve l'ABSENCE du mode).
- **Docs/QA (T11)** : contrat-v1.md (§5 réécrit, item 4 clés, 3 statuts),
  state-providers.md (interface + provenance), QA append-only Section 18 +
  abrogations 4.4/6.6/13.5/Section 16, README QA, + cohérence report-endpoint/
  agent-skeleton/handlers-wallpaper-overlay.
- **ÉCART de périmètre signalé** : des consommateurs de `drifted_allowed`/`mode`
  hors de la liste explicite de la story ont été traités pour la cohérence (sinon
  filtre/compteur/badge orphelins ou tests cassés) : `WorkstationGroupRepository`
  (filtre conformité), 5 surfaces UI conformité parc, et 6 fichiers de tests
  feature/unit supplémentaires. Aucun consommateur `StateMode`/`drifted_allowed`
  ACTIF ne subsiste hors mentions documentaires/abrogées.
- **Validation différée `/vm`** : PHPUnit non exécutable sur l'hôte (vendor absent,
  worktree). Migrations non jouées (pas de VM worktree). À faire post-merge.

### File List

**Créés (4)**
- `database/migrations/2026_06_16_110200_drop_mode_from_shortcut_assignables.php`
- `database/migrations/2026_06_16_110201_drop_mode_from_wallpapers.php`
- `database/migrations/2026_06_16_110202_drop_mode_from_overlay_signals.php`
- `_bmad-output/implementation-artifacts/27-8-retrait-mode-strict-default-drift-policy.md` (cette story)

**Supprimés (2)** — *fichiers fantômes VM à `trash` post-merge (inotify ne propage pas les deletes)*
- `app/Enums/StateMode.php`
- `tests/Unit/Models/StateModeCastTest.php`

**Modifiés — serveur (15)**
- `app/Enums/AgentResourceStatus.php`
- `app/Models/Wallpaper.php`
- `app/Models/OverlaySignal.php`
- `app/Models/Shortcut.php`
- `app/Repositories/WorkstationGroupRepository.php`
- `app/Services/Agent/StateCandidate.php`
- `app/Services/Agent/Contracts/StateProvider.php`
- `app/Services/Agent/StateCompiler.php`
- `app/Services/Agent/StateHasher.php`
- `app/Services/Agent/Providers/ShortcutsStateProvider.php`
- `app/Services/Agent/Providers/WallpaperStateProvider.php`
- `app/Services/Agent/Providers/OverlayStateProvider.php`
- `app/Services/Agent/Providers/DrivesStateProvider.php`
- `app/Services/Agent/Providers/PrintersStateProvider.php`
- `app/Services/Agent/Reporting/ConformityService.php`
- `app/Services/Agent/Reporting/ReportIngestService.php`

**Modifiés — agent Go (13)**
- `agent/shared/engine.go`
- `agent/shared/contract.go`
- `agent/shared/engine_test.go`
- `agent/shared/contract_test.go`
- `agent/shared/hasher_test.go`
- `agent/shared/handler_shortcuts_test.go`
- `agent/shared/handler_drives_test.go`
- `agent/shared/handler_printers_test.go`
- `agent/shared/companion_test.go`
- `agent/shared/overlay_compose_test.go`
- `agent/shared/icon_assets_test.go`
- `agent/shared/assets_test.go`
- `agent/shared/overlay_logon_test.go`

**Modifiés — UI blade (12)**
- `resources/views/components/organisms/shortcut-assignment-modal.blade.php`
- `resources/views/components/molecules/wallpaper-card.blade.php`
- `resources/views/pages/parc-settings/overlay-messages/index.blade.php`
- `resources/views/pages/shortcuts/[id]/index.blade.php`
- `resources/views/pages/shortcuts/[id]/_partials/shortcut-form.blade.php`
- `resources/views/components/atoms/conformity-badge.blade.php`
- `resources/views/pages/parc/index.blade.php`
- `resources/views/pages/parc/_partials/stats-cards.blade.php`
- `resources/views/pages/parc/_partials/machines-tab.blade.php`
- `resources/views/pages/parc/groups/[id]/_partials/conformity-panel.blade.php`
- `resources/views/pages/parc/groups/[id]/index.blade.php` *(fallback summary aligné 5 clés)*
- `resources/views/pages/parc/machines/[id]/_partials/agent-conformity.blade.php` *(commentaire 3 statuts)*

**Modifiés — golden files (2)**
- `tests/Fixtures/Agent/state.v1.json`
- `tests/Fixtures/Agent/report.v1.json`

**Modifiés — tests PHP (13)**
- `tests/Unit/Services/Agent/ContractV1Test.php`
- `tests/Unit/Services/Agent/StateCompilerTest.php`
- `tests/Unit/Services/Agent/StateHasherTest.php`
- `tests/Unit/Services/Agent/ShortcutsStateProviderTest.php`
- `tests/Unit/Services/Agent/WallpaperStateProviderTest.php`
- `tests/Unit/Services/Agent/OverlayStateProviderTest.php`
- `tests/Unit/Services/Agent/DrivesStateProviderTest.php`
- `tests/Unit/Services/Agent/PrintersStateProviderTest.php`
- `tests/Unit/Services/Agent/ConformityServiceTest.php`
- `tests/Unit/Services/Agent/ReportIngestServiceTest.php`
- `tests/Feature/Api/V1/Agent/HandlersE2eTest.php`
- `tests/Feature/Api/V1/Agent/ReportEndpointTest.php`
- `tests/Feature/Api/V1/Agent/AgentSkeletonE2eTest.php`
- `tests/Feature/Api/V1/Agent/StateEndpointTest.php`
- `tests/Feature/Livewire/Parc/ParcConformityTest.php`
- `tests/Feature/Livewire/ParcSettings/OverlayMessagesPageTest.php`
- `tests/Feature/Livewire/ShortcutModeTogglePageTest.php`

**Modifiés — docs/QA (7)**
- `docs/agent/contract-v1.md`
- `docs/agent/state-providers.md`
- `docs/agent/report-endpoint.md`
- `docs/agent/agent-skeleton.md`
- `docs/agent/handlers-wallpaper-overlay.md`
- `docs/qa/domains/agent.md`
- `docs/qa/README.md`

**Modifiés — suivi (1)**
- `_bmad-output/implementation-artifacts/sprint-status.yaml`

### Change Log

- 2026-06-16 — Story 27.8 implémentée (DEV `claude-opus-4-8[1m]`) : retrait total
  du mode strict/default de la drift policy — STRICT partout. T0-T12 livrées.
  Item du contrat 5 clés → 4, statut report 4 → 3, `FROZEN_STATE_HASH` bumpé
  croisé PHP↔Go (`4d0c2c94…`). Go vert (test/vet linux+windows/cross-compile) ;
  `php -l` OK ; PHPUnit + migrations différés à l'action `/vm` post-merge. Status
  `ready-for-dev` → `review`.
- 2026-06-16 — Review (sonnet) + 2e avis indépendant (opus). **APPROUVÉ, aucun
  bloquant** : 0 finding 🔴/🟠 ; 5 résidus documentaires/commentaires (3 corrigés :
  `contract-v1.md:96` wrapper `mode`, `stats-cards.blade.php` commentaire, migration
  historique `create_agent_report_tables` commentaire colonne `status` ; 2 requalifiés :
  `AppliedState` n'est PAS du code mort — D-B intentionnel ; `report.v1.json` non-bug,
  aucun hash report). Axes à risque vérifiés conformes par les 2 relecteurs (hash croisé
  PHP↔Go, invariants agent D-B, conformité robuste sans `ValueError`/`UnhandledMatchError`,
  migrations réversibles, tests non creux). Cf. `_bmad-output/codeReviews/27-8.md`.
  Status `review` → `to-validate`.
