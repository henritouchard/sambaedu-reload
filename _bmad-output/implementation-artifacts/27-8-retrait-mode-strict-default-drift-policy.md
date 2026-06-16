# Story 27.8 : Retrait du mode strict|default (drift policy) — convergence stricte pure

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **⚠️ CONTRAINTE D'ISOLATION — lire EN PREMIER.** Le développement de cette story se fait
> **dans un worktree git dédié `strict-only`**, créé depuis un **main PROPRE** (c.-à-d. **après** que le
> travail en cours sur `main` — story 25.6 catalogue tools + portage wallpaper-statique — soit committé).
> Le working tree `main` actuel contient du travail **EN COURS SANS RAPPORT** à NE **JAMAIS** toucher :
> - **25.6 catalogue tools** : `app/Models/AgentTool.php`, `app/Services/Agent/Tools/`,
>   `database/migrations/2026_06_16_120000_create_agent_tools_table.php`, `ToolController.php`,
>   `config/agent.php`, `config/apache/sambaedu.conf`, `scripts/setupApache.sh`.
> - **portage wallpaper-statique** : `agent/shared/assets.go`, `assets_test.go`, `icon_assets.go`,
>   `sessionfetch_test.go`, `version.go`.
>
> Le retrait du drift est **ORTHOGONAL** à ces deux chantiers. **Depuis le worktree : AUCUNE interaction
> VM** (tests Go = hôte ; PHPUnit `/vm` + `migrate` rejoués par Henri APRÈS merge — mémoire
> `feedback_worktree_no_vm_sync`). Ne jamais SSH `/vm` ni `/lab1` depuis le worktree.

## Story

En tant qu'**équipe SambaEdu (architecte + admin d'établissement)**,
je veux **retirer entièrement le mécanisme « mode strict|default » (drift policy) de tout le pipeline
desired-state — serveur PHP, contrat agent v1, moteur Go et UI**,
afin que **le comportement de l'agent soit la convergence STRICTE universelle (la cible fait toujours loi,
pour tous les types) et que les questions ouvertes Q1–Q4 de la story 27.3 (Option A/B, granularité du
toggle, sémantique `null=strict`) disparaissent avec la feature qui les portait**.

## Contexte & intention

**Décision Henri 2026-06-16 : on RETIRE le mode strict|default.** La feature « drift policy » — un toggle
`strict`/`default` autorisant un poste à **diverger** de l'état désiré (statut agent `drifted_allowed`) — a
été introduite **par erreur** : posée sur la RÈGLE en Story 27.1 (1re exposition UI du toggle FR26), puis
déplacée vers l'ASSIGNATION en Story 27.3 (Option A). Elle a généré **trop de questions ouvertes** sans
besoin métier tranché (les Q1–Q4 de 27.3 : Option A vs B, toggle au lot vs par cible, `null = strict`). La
décision est de **la retirer entièrement** plutôt que de continuer à la raffiner.

**Comportement cible — convergence STRICTE pure, pour TOUS les types :** la cible serveur fait toujours
loi. `réel ≠ cible → l'agent réapplique → drift` ; `réel = cible → compliant`. Plus de tolérance à la
dérive humaine, plus de statut `drifted_allowed` émis par l'agent, plus de toggle UI.

**Réversibilité.** Zéro prod (mémoire `zero_prod_publish_is_test`) : aucune donnée à préserver. Les
colonnes `mode` sont droppées proprement (`down()` symétrique qui les réintroduit). L'historique git
préserve l'intégralité du mécanisme retiré (27.1 + 27.3) — on pourra le ressortir si un besoin terrain
émerge.

### Ce que cette story RETIRE (et SEULEMENT cela)

Le champ/mécanisme **`mode` strict|default** part de **tout** le pipeline : enum PHP, propriété du candidat,
agrégation au compilateur, clé `mode` de l'item compilé (item passe de **5 → 4 clés**), colonnes DB,
lecture providers, champ `StateItem.Mode` + branche `drifted_allowed` du moteur Go, toggles UI, tests
dédiés, et la §5 du contrat agent.

### Ce qui RESTE intact (NE PAS retirer)

- **Handler raccourcis 27.1** (convergence bureau via `WorkstationEnvironment`/`WorkstationEnvironmentResolver`).
- **Handlers printers/drives 27.2.**
- **Icônes uploadées 27.7** (asset statique).
- **Rendu overlay verrouillé 27.1bis** (Rainmeter).
- **La sémantique `aggregate`/`exclusive`** (`ResourceSemantics`) est **CONSERVÉE** — seul `mode` part.
- **`StateCandidate`** reste (on en retire le paramètre `?StateMode $mode`, pas la classe).
- **La persistance du dernier-appliqué** côté agent (`ShouldPersist`, `AppliedState`, écriture atomique
  per-user) est **CONSERVÉE** : c'est la traçabilité 24.4 n° 9, utile en strict aussi (compliant et drift
  persistent la cible).

### Nature technique — à acter avant de coder

1. **Retrait FORWARD (nouveau commit), PAS `git revert`.** 27.2 et 27.7 ont **bumpé le golden** APRÈS 27.1
   (printers/drives, puis `icon_asset`/`icon_checksum`) ; un revert de 27.1/27.3 produirait des conflits et
   ferait régresser des items conservés. On retire le mode **en avant**, en repartant de l'état courant.
2. **CONTRACT-BREAKING — le mode EST dans le contrat agent v1.** Il figure :
   - dans le **golden** `tests/Fixtures/Agent/state.v1.json` (clé `"mode"` sur **chaque** item — 5
     occurrences),
   - dans la **§3 du contrat** (item « exactement 5 clés »),
   - et il est **consommé par le moteur Go** (`StateItem.Mode`, parsing dans `ItemsFromScope`,
     `ResolveItemStatus(isCompliant, mode, …)` avec branche `drifted_allowed`/`humanDrift`, lecture
     `first.Mode` au dispatch).

   L'item passe de **5 clés → 4 clés** (`{type, semantics, payload, hash}`). Le **`FROZEN_STATE_HASH`** est
   recomputé et **bumpé SCIEMMENT**, **IDENTIQUE croisé PHP↔Go** (`ContractV1Test::FROZEN_STATE_HASH` ↔
   `hasher_test.go::frozenStateHash`, NFR13). C'est le premier bump du contrat depuis sa publication, donc
   admis sans bump de version majeure : **zéro consommateur figé en prod** (mémoire
   `zero_prod_publish_is_test` ; contrat §10 « tant qu'aucun consommateur n'existe »).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **CONTRACT-BREAKING assumé — c'est l'objectif, pas une régression.** Contrairement à 27.3 (qui voulait le
   golden INTACT), ici **le golden DOIT changer** (la clé `mode` sort de chaque item). Le bump de hash est
   donc **attendu et voulu**. Le piège est de l'oublier d'un côté : le hash PHP et le hash Go doivent être
   **recomputés** et **strictement identiques** (NFR13 — la valeur exacte se relève en exécutant le hasher
   sur le golden mis à jour, **pas** en l'inventant).

2. **Working tree `main` pollué par 25.6 + wallpaper-statique.** Ces fichiers (cf. encart d'isolation) sont
   **hors périmètre absolu**. Le worktree `strict-only` doit partir d'un main **propre** (après commit de
   25.6). Si le worktree est créé avant que 25.6 soit committé, le diff de 27.8 mêlerait les deux chantiers
   → interdit. **T0 vérifie cet état avant tout.**

3. **`ShouldPersist` à PRÉSERVER en strict.** Réflexe dangereux : « plus de `default` → plus besoin de
   persister le dernier-appliqué ». **FAUX.** `ResolveItemStatus` doit continuer à persister la cible après
   `compliant` et après `drift` (`ShouldPersist: true`). On retire **seulement** la branche `humanDrift →
   drifted_allowed` et le **paramètre `mode`** de la fonction ; la mécanique `AppliedState` /
   `WriteFileAtomic` per-user / `AcceptSessionChange` reste (traçabilité 24.4 n° 9). Simplification cible :
   `ResolveItemStatus(isCompliant, lastAppliedHash, targetHash)` → `compliant` (réel = cible, persist) sinon
   `drift` (réapplique, persist). `lastApplied`/`targetHash` peuvent rester en signature pour le persist,
   même si la décision ne dépend plus que de `isCompliant`.

4. **`drifted_allowed` est aussi câblé côté REPORTING — frontière de périmètre à respecter.** Le statut
   `drifted_allowed` n'existe pas que dans le moteur Go : `App\Enums\AgentResourceStatus::DriftedAllowed`
   est consommé par **`ConformityService`** (worst-status, compteurs), **`WorkstationGroupRepository`**
   (filtre conformité), **`ReportRequest`** (validation `Rule::enum(AgentResourceStatus::class)`), la **UI
   conformité parc** et de nombreux tests (`ConformityServiceTest`, `ParcConformityTest`,
   `ReportEndpointTest`, `HandlersE2eTest`, `ReportIngestServiceTest`). **DÉCISION DE PÉRIMÈTRE (AC3) :**
   cette story retire la capacité de l'**AGENT à ÉMETTRE** `drifted_allowed` (moteur + contrat §5/§6 +
   golden) ; elle **NE démantèle PAS** le sous-système de reporting conformité (FR9, épic séparé). Un agent
   qui n'émet jamais `drifted_allowed` ⇒ le compteur reste simplement toujours à 0 ; l'enum
   `AgentResourceStatus::DriftedAllowed` et son traitement défensif côté ingestion **RESTENT** (retrait =
   refonte du dashboard conformité, hors intention). Au contrat §6, `drifted_allowed` est **retiré de la
   liste des statuts qu'un agent v1 PEUT émettre** mais documenté comme « plus jamais émis (retrait 27.8) ».
   → **Question Henri n° 1** (confirmer ce périmètre).

5. **Le candidat synthétique identity d'overlay portait `mode: StateMode::Default` (neutre).** En retirant
   le paramètre `mode` de `StateCandidate`, ce `mode:` disparaît mécaniquement
   (`OverlayStateProvider::identityCandidate` ~L154). Aucune régression : il était neutre pour l'agrégation,
   qui disparaît elle aussi.

6. **`shortcuts.mode` est DÉJÀ droppé (27.3) — ne pas le re-dropper.** L'état courant du schéma (vérifié
   dans le code) : le mode a été déplacé `shortcuts → shortcut_assignables` par 27.3
   (`2026_06_16_110000_add_mode_to_shortcut_assignables.php` + `2026_06_16_110100_drop_mode_from_shortcuts.php`).
   Les colonnes `mode` VIVANTES aujourd'hui sont donc : **`shortcut_assignables.mode`**,
   **`wallpapers.mode`** (27.1), **`overlay_signals.mode`** (27.1). Ce sont CES TROIS qu'on droppe. **NE PAS
   recréer/re-dropper `shortcuts.mode`.** Ne PAS supprimer les migrations 27.1/27.3 (historique préservé).

7. **Tests SQLite n'appliquent pas le varchar PG (mémoire `sqlite_tests_no_varchar_enforcement`).** La
   couverture critique est **fonctionnelle** : item compilé à 4 clés, hash croisé PHP↔Go identique, moteur
   Go ne connaît plus `drifted_allowed`.

8. **`routes/api.php` — AUCUNE route ajoutée** (mémoire `api_routes_arch_test_window_trap`). Sans objet ici.

9. **inotify ne propage pas les deletes (mémoire `inotify_no_delete_sync`).** Cette story **supprime 2
   fichiers de test** (`StateModeCastTest.php`, `ShortcutModeTogglePageTest.php`). Comme le dev se fait en
   worktree et que les tests `/vm` sont rejoués par Henri APRÈS merge, **lister explicitement** ces 2
   suppressions à Henri pour qu'il purge les fichiers fantômes sur la VM (`trash` côté hôte ; demander avant
   tout cleanup SSH).

10. **NFR7 — zéro AD/APCu/LdapRecord introduit.** Le retrait ne touche pas la frontière auth ; grep
    `ldap|apcu|samba-tool` sur les providers doit rester (au pire) en commentaires, iso 27.1/27.2.

## Acceptance Criteria

### AC1 — Services & enum PHP : `mode` retiré du pipeline serveur (FR5 révisé)

**Given** la décision de retrait du mode strict|default
**When** on inspecte le pipeline serveur
**Then** l'enum **`App\Enums\StateMode` est SUPPRIMÉ** ; `StateCandidate` n'a plus de paramètre/propriété
`?StateMode $mode` (docblock nettoyé) ; `StateCompiler` n'a plus `aggregateMode()`, plus l'agrégation
pré-dedup du mode, et l'item compilé passe de **5 → 4 clés** `{type, semantics, payload, hash}` (la clé
`mode` est retirée du tableau ~L160-165 et du docblock « exactement 5 clés » ~L121)
**And** `ShortcutsStateProvider` ne lit plus `shortcut_assignables.mode as assignment_mode` (ni le cast
`StateMode::tryFrom`), `WallpaperStateProvider` ne lit plus `wallpapers.mode`, `OverlayStateProvider` ne
lit plus `overlay_signals.mode` ni ne passe `mode:` (y compris le candidat synthétique identity) ; aucun
provider n'a plus de méthode `mode()`
**And** les modèles `Wallpaper` / `OverlaySignal` ne portent plus `mode` (import `StateMode`, `@property`,
`$fillable`, `$casts`) et `Shortcut` n'a plus `->withPivot('mode')` sur `workstationGroups()` /
`workstations()`
**And** grep `StateMode` sur `app/` → **vide**.

### AC2 — Migrations : drop forward réversible des 3 colonnes `mode` (FR5)

**Given** que les colonnes `mode` vivantes sont `shortcut_assignables.mode`, `wallpapers.mode`,
`overlay_signals.mode` (vérifié — `shortcuts.mode` déjà droppé par 27.3)
**When** les migrations sont jouées
**Then** **3 NOUVELLES migrations** (datées après `2026_06_16_120000`) droppent ces colonnes, idempotentes
(`Schema::hasColumn` en garde), `->comment`/datées story 27.8, **`down()` symétrique** qui RÉINTRODUIT la
colonne (`string('mode', 16)->nullable()`) pour réversibilité
**And** **`shortcuts.mode` n'est NI re-dropé NI recréé** ; les migrations 27.1/27.3 ne sont PAS supprimées
(historique)
**And** action `/vm` (Henri, post-merge) : `php artisan migrate` — pas de `config:cache`/`route:cache`
(aucune clé config ni route ajoutée).

### AC3 — Contrat & golden : item 4 clés, hash bumpé identique croisé (NFR13)

**Given** le retrait CONTRACT-BREAKING de la clé `mode`
**When** on régénère le golden et recompute les hashs
**Then** `tests/Fixtures/Agent/state.v1.json` n'a plus la clé `"mode"` sur **aucun** item (4 clés
`{type, semantics, payload, hash}`) ; `report.v1.json` ne référence plus `drifted_allowed` (l'item exemple
qui le portait passe à un statut valide émis en strict, ex. `drift` ou `compliant`)
**And** `FROZEN_STATE_HASH` (PHP `ContractV1Test`) **ET** `frozenStateHash` (Go `hasher_test.go`,
`loop_test.go`, `files_test.go`, `client_test.go` qui le réfèrent) sont **recomputés et STRICTEMENT
identiques** ; le bump est **documenté dans le docblock de `ContractV1Test`** à la suite des bumps 27.1 /
27.2 / 27.7
**And** `docs/agent/contract-v1.md` est réécrit : **§5** en **convergence stricte pure** (compliant si
réel = cible, sinon drift + réapplique ; plus de `mode`/`default`/`drifted_allowed`/dérive humaine), **§3**
item **4 clés** (la ligne `mode` du tableau et l'exemple JSON retirés), **§6** `drifted_allowed` retiré des
statuts qu'un agent v1 émet (note « plus jamais émis — retrait 27.8 »), la mention « booléen `mode` » de
l'avant-propos retirée.

### AC4 — Agent Go : moteur simplifié en strict, `drifted_allowed` retiré, `ShouldPersist` conservé (FR19 supprimé)

**Given** que le moteur consommait `mode` et émettait `drifted_allowed`
**When** on inspecte `agent/shared/engine.go`
**Then** `StateItem.Mode` est retiré ; `ItemsFromScope` ne parse plus `mode` ; `ResolveItemStatus` est
simplifié (paramètre `mode` retiré, branche `humanDrift`/`drifted_allowed` supprimée → `compliant` si
conforme [persist], sinon `drift` + `ShouldApply` + persist) ; le dispatch (`RunPass`) ne lit/valide plus
`first.Mode` (plus de « mode inconnu → strict ») et adapte les appels
**And** la **persistance du dernier-appliqué est CONSERVÉE** (`AppliedState`, `WriteFileAtomic` per-user,
`AcceptSessionChange`, `ShouldPersist: true` sur compliant et drift) — traçabilité 24.4 n° 9
**And** `agent/shared/contract.go` est vérifié (l'enveloppe ne portait PAS de champ mode — confirmé : seul
`debug` y vit, pas de modif attendue)
**And** `go test ./...` + `go vet` (linux + `GOOS=windows`) + cross-compile `GOOS=windows` sont **verts** ;
les cas de test `drifted_allowed`/`default` de `engine_test.go` sont retirés/réécrits en strict,
`hasher_test.go` reflète le nouveau hash croisé.

### AC5 — UI : tous les toggles/champs mode retirés (FR26 supprimé)

**Given** le retrait du mécanisme
**When** on inspecte l'UI Livewire/blade
**Then** `shortcut-assignment-modal.blade.php` n'a plus le toggle mode (propriété `$mode`, reset, et param
`mode` du dispatch `confirm()`) ; `shortcuts/[id]/index.blade.php` n'a plus le param `$mode` de
`onAssignmentsConfirmed()` ni le `['mode' => $mode]` du `syncWithoutDetaching` (les assignations se font
sans pivot mode) ; `shortcuts/[id]/_partials/shortcut-form.blade.php` n'a plus le commentaire 27.3
résiduel ; `wallpaper-card.blade.php` n'a plus `setMode()` ni le bloc UI toggle Strict/Souple ;
`parc-settings/overlay-messages/index.blade.php` n'a plus la propriété `$mode`, ni la règle de validation
`'mode'`, ni `$signal->update(['mode' => …])`
**And** aucune régression du geste d'assignation / création (raccourcis, fonds, overlays se créent et
s'assignent normalement, simplement sans choix de mode) ; `WithToasts` et la modale réutilisable conservés.

### AC6 — Tests : suppressions + ajustements, suites vertes (NFR13)

**Then** côté **Laravel** :
- **SUPPRIMER** `tests/Unit/Models/StateModeCastTest.php` (les 3 casts disparaissent) et
  `tests/Feature/Livewire/ShortcutModeTogglePageTest.php` (la feature disparaît)
- **AJUSTER** `ShortcutsStateProviderTest`, `WallpaperStateProviderTest`, `OverlayStateProviderTest`
  (retrait des assertions/données mode), `StateCompilerTest` (item **5 → 4 clés**, retrait des cas
  d'agrégation strict/default), `ContractV1Test` (item 4 clés, **nouveau `FROZEN_STATE_HASH`**, docblock du
  bump)
- les tests de reporting qui CITENT `drifted_allowed` au titre de la conformité (`ConformityServiceTest`,
  `ParcConformityTest`, `ReportIngestServiceTest`, `ReportEndpointTest`) **RESTENT** (périmètre AC3 : le
  statut reste valide à l'ingestion) — ne les casser qu'au strict nécessaire et le justifier
- non-régression `--filter Agent` sur `/vm` (rejouée par Henri post-merge) : la baseline T0 doit être
  relevée, `ContractV1Test` vert AVEC le nouveau hash

**And** côté **agent Go** : `go test ./...` + `go vet` (linux + `GOOS=windows`) + cross-compile **verts**,
sans aucun cas `drifted_allowed`/`default` résiduel.

### AC7 — Documentation + backlog (append-only)

**Then** `docs/qa/domains/agent.md` reçoit une section **append-only** « Retrait du mode strict|default
(27.8) » : convergence stricte universelle, plus de `drifted_allowed` émis par l'agent ; **ne PAS
renuméroter** les scénarios existants (les scénarios 13.5 / 4.4 / 6.6 / 14 / etc. qui décrivent
`drifted_allowed` sont annotés « retiré en 27.8 » dans la nouvelle section, pas réécrits en place)
**And** une ligne 27.8 est ajoutée à `docs/qa/README.md` si pertinent ; `docs/agent/state-providers.md` et
`docs/agent/handlers-wallpaper-overlay.md` sont annotés (le mode/`drifted_allowed` y est documenté) — au
minimum une note de tête « mode retiré en 27.8, convergence stricte »
**And** la finalisation du backlog (marquer 27.3 `superseded`/`cancelled` dans `sprint-status.yaml`) est
notée comme **TÂCHE DE FINALISATION post-dev** (PAS faite dans cette story).

## Tasks / Subtasks

- [ ] **T0 — Baseline & isolation worktree** (pièges 1, 2)
  - [ ] Vérifier que `main` est PROPRE (25.6 catalogue tools + wallpaper-statique committés) AVANT de créer
        le worktree `strict-only`. Si non → **STOP**, notifier Henri.
  - [ ] Créer/entrer le worktree `strict-only` depuis le main propre. **Aucune interaction VM depuis le
        worktree.**
  - [ ] Relever la baseline : `FROZEN_STATE_HASH` courant (PHP) et `frozenStateHash` courant (Go), liste
        des occurrences `mode`/`StateMode`/`drifted_allowed` à traiter (grep). Ne pas inventer le futur
        hash : il se recompute en T7.

- [ ] **T1 — Services & enum PHP** (AC1)
  - [ ] Supprimer `app/Enums/StateMode.php` (`trash` côté hôte).
  - [ ] `StateCandidate.php` : retirer le param/propriété `?StateMode $mode`, l'import `StateMode`, nettoyer
        le docblock (provenance du mode).
  - [ ] `StateCompiler.php` : retirer `aggregateMode()`, le bloc `$modeCandidates`/`$mode` (~L137-156), la
        clé `'mode' => $mode` de l'item (~L163), l'import `StateMode` ; corriger le docblock « 5 clés » → « 4
        clés » (~L121). L'item compilé = `{type, semantics, payload, hash}`.
  - [ ] Providers : `ShortcutsStateProvider.php` (retirer `mode()`, la sélection `shortcut_assignables.mode
        as assignment_mode`, le `StateMode::tryFrom`, le `mode:` du candidat, l'import) ;
        `WallpaperStateProvider.php` (retirer `mode()`, `wallpapers.mode`, `mode:`, import) ;
        `OverlayStateProvider.php` (retirer `mode()`, `overlay_signals.mode`, les 2 `mode:` dont le candidat
        identity synthétique ~L154, import).

- [ ] **T2 — Modèles** (AC1)
  - [ ] `Wallpaper.php` : retirer import `StateMode`, `@property … $mode`, `'mode'` de `$fillable`, le cast
        `'mode' => StateMode::class`.
  - [ ] `OverlaySignal.php` : idem (import, `@property`, fillable, cast).
  - [ ] `Shortcut.php` : retirer `->withPivot('mode')` des relations `workstationGroups()` (~L160) et
        `workstations()` (~L174) — conserver `->withTimestamps()`.

- [ ] **T3 — Migrations (drop forward réversible)** (AC2) — piège 6
  - [ ] 3 nouvelles migrations datées après `2026_06_16_120000` (ex. `2026_06_16_1300xx`) :
        `drop_mode_from_shortcut_assignables`, `drop_mode_from_wallpapers`, `drop_mode_from_overlay_signals`.
        `Schema::hasColumn` en garde, `dropColumn('mode')`, `->comment`/daté 27.8 ; `down()` RECRÉE
        `string('mode', 16)->nullable()`.
  - [ ] **NE PAS** toucher `shortcuts.mode` (déjà droppé 27.3) ni supprimer les migrations 27.1/27.3.

- [ ] **T4 — Agent Go** (AC4) — piège 3 (ShouldPersist)
  - [ ] `engine.go` : retirer `StateItem.Mode` ; retirer le parsing `mode` de `ItemsFromScope` ; simplifier
        `ResolveItemStatus` (retirer param `mode`, retirer `humanDrift`/`drifted_allowed` → compliant
        [persist] / drift [apply+persist]) ; retirer la lecture+validation `first.Mode` du dispatch
        (`RunPass`), adapter `dispatch()` (signature sans `mode`, log sans `mode=`). **Conserver
        `ShouldPersist`, `AppliedState`, le persist atomique.**
  - [ ] `contract.go` : vérifier (a priori inchangé — pas de champ mode sur l'enveloppe).
  - [ ] Tests Go : retirer/réécrire les cas `drifted_allowed`/`default` de `engine_test.go`
        (`TestResolveItemStatusSection5Verbatim`, `TestRunPassDefaultModeFullLifecycle`,
        `TestRunPassUnknownModeTreatedAsStrict`, le cas `ItemsFromScope` `mode: "default"`) en strict ;
        `hasher_test.go` + `loop_test.go` reçoivent le nouveau hash (T7).

- [ ] **T5 — UI** (AC5)
  - [ ] `shortcut-assignment-modal.blade.php` : retirer propriété `$mode` (~L41), son reset (~L87), le
        `mode:` du dispatch `confirm()` (~L240), le bloc toggle (~L497-502).
  - [ ] `shortcuts/[id]/index.blade.php` : retirer le param `string $mode` de `onAssignmentsConfirmed()`
        (~L236), le `['mode' => $mode]` des `syncWithoutDetaching` (~L253, L260) → `syncWithoutDetaching`
        des ids nus, retirer la normalisation `$mode` (~L246).
  - [ ] `shortcuts/[id]/_partials/shortcut-form.blade.php` : retirer le commentaire 27.3 résiduel (~L113-116).
  - [ ] `wallpaper-card.blade.php` : retirer `setMode()` (~L214-233) + le bloc UI toggle Strict/Souple
        (~L309-334).
  - [ ] `overlay-messages/index.blade.php` : retirer propriété `$mode` (~L33), la règle `'mode'` (~L79), le
        `$signal->update(['mode' => …])` (~L97, L112) — la transaction reste, sans la pose du mode.

- [ ] **T6 — Tests PHP** (AC6)
  - [ ] Supprimer `tests/Unit/Models/StateModeCastTest.php` et `tests/Feature/Livewire/ShortcutModeTogglePageTest.php`
        (`trash` côté hôte ; **lister à Henri** pour purge fantômes VM — piège 9).
  - [ ] Ajuster `ShortcutsStateProviderTest`, `WallpaperStateProviderTest`, `OverlayStateProviderTest`
        (retrait mode), `StateCompilerTest` (item 4 clés, retrait agrégation strict/default), `ContractV1Test`
        (4 clés + nouveau hash + docblock bump).
  - [ ] Vérifier que les tests reporting `drifted_allowed` (Conformity/ParcConformity/ReportIngest/Report
        Endpoint) restent verts (statut conservé à l'ingestion, AC3) ; ne corriger qu'au strict nécessaire.

- [ ] **T7 — Golden & hash croisé** (AC3) — piège 1
  - [ ] Mettre à jour `tests/Fixtures/Agent/state.v1.json` (retirer `"mode"` de chaque item → 4 clés) et
        `report.v1.json` (statut exemple `drifted_allowed` → statut valide strict).
  - [ ] **Recomputer** le hash sur le golden mis à jour, le poser dans `ContractV1Test::FROZEN_STATE_HASH`
        ET `hasher_test.go::frozenStateHash` (+ réfs `loop_test.go`/`files_test.go`/`client_test.go`) —
        **valeurs IDENTIQUES PHP↔Go** (NFR13). Documenter le bump dans le docblock `ContractV1Test`.

- [ ] **T8 — Contrat + doc + QA** (AC3, AC7)
  - [ ] `docs/agent/contract-v1.md` : réécrire §5 (convergence stricte pure), §3 (4 clés), §6
        (`drifted_allowed` retiré des statuts émis), avant-propos (mention `mode`).
  - [ ] `docs/qa/domains/agent.md` : section append-only « Retrait 27.8 » ; ligne `docs/qa/README.md` ; notes
        de tête sur `state-providers.md` / `handlers-wallpaper-overlay.md`.

- [ ] **T9 — Validation finale** (AC1, AC4, AC6)
  - [ ] `php -l` sur les PHP touchés ; grep `StateMode` sur `app/` → vide ; grep NFR7
        (`ldap|apcu|samba-tool`) sur les 3 providers → vide (hors commentaires).
  - [ ] Go (hôte) : `go test ./...` + `go vet` linux + `GOOS=windows` + cross-compile `GOOS=windows` —
        **verts**, zéro `drifted_allowed`/`Mode` résiduel.
  - [ ] **Actions /vm (PAS auto — Henri, post-merge)** : `migrate:status` → `php artisan migrate` (3
        migrations drop) ; **pas** de `config:cache`/`route:cache` (aucune clé config ni route neuve) ;
        `--filter Agent` (nouveau hash). Lister les 2 fichiers de test supprimés pour purge fantômes VM.
  - [ ] **Finalisation backlog (post-dev, à NE PAS faire ici)** : marquer 27.3 `superseded`/`cancelled` dans
        `sprint-status.yaml`.

## Dev Notes

### Périmètre — retiré / conservé

| Retiré (27.8) | Conservé (intact) |
|---|---|
| `App\Enums\StateMode` (enum supprimé) | Handler raccourcis 27.1 (convergence bureau / `WorkstationEnvironment`) |
| `StateCandidate::$mode` (param/propriété) | Handlers printers/drives 27.2 |
| `StateCompiler::aggregateMode()` + clé `mode` de l'item (5→4 clés) | Icônes uploadées 27.7 |
| Lecture `mode` des 3 providers + méthodes `mode()` | Rendu overlay verrouillé 27.1bis |
| Colonnes `shortcut_assignables.mode` / `wallpapers.mode` / `overlay_signals.mode` | Sémantique `aggregate`/`exclusive` (`ResourceSemantics`) |
| `StateItem.Mode` + branche `drifted_allowed`/`humanDrift` (engine.go) | `StateCandidate` (la classe), `ShouldPersist`/`AppliedState`/persist per-user (24.4 n°9) |
| Toggles UI mode (4 vues) | `ResourceSemantics`, `StateMaille`, `TargetContext`, `StateHasher` |
| `StateModeCastTest`, `ShortcutModeTogglePageTest` | Sous-système reporting conformité (`ConformityService`, etc. — AC3 / piège 4) |

### Ce qu'on RETIRE de 27.1 + 27.3 (FORWARD, pas revert)

[Source: _bmad-output/implementation-artifacts/27-1-handler-raccourcis-convergence-bureau.md — mode par règle]
[Source: _bmad-output/implementation-artifacts/27-3-drift-policy-par-assignation.md — mode déplacé règle→assignation]

- 27.1 a introduit l'enum `StateMode`, le cast sur 3 tables, `StateCandidate::$mode`, `aggregateMode()`, le
  champ Go `StateItem.Mode` + `drifted_allowed`, les toggles UI, et a bumpé le golden.
- 27.3 a déplacé le mode `shortcuts.mode → shortcut_assignables.mode` (Option A) sans toucher le golden.
- 27.8 retire **tout** ce mécanisme en avant (27.2 et 27.7 ont bumpé le golden APRÈS → revert exclu).

### État courant du schéma (vérifié dans le code, 2026-06-16)

[Source: database/migrations/ — `2026_06_16_110000_add_mode_to_shortcut_assignables.php`,
`2026_06_16_110100_drop_mode_from_shortcuts.php`, `2026_06_15_100100_add_mode_to_wallpapers.php`,
`2026_06_15_100200_add_mode_to_overlay_signals.php`]

- Colonnes `mode` **vivantes** : `shortcut_assignables.mode`, `wallpapers.mode`, `overlay_signals.mode`.
- `shortcuts.mode` : **déjà droppé** par 27.3 → ne pas y toucher.

### Moteur Go — simplification cible (préserver le persist)

[Source: agent/shared/engine.go:48-57 (`StateItem`), :62-90 (`ItemsFromScope`), :116-154 (`Verdict`/
`ResolveItemStatus`), :219-264 (`RunPass`), :266-300 (`dispatch`)]

- `ResolveItemStatus` cible : `compliant` (réel = cible → `ShouldPersist: true`) sinon `drift`
  (`ShouldApply: true, ShouldPersist: true`). On retire **uniquement** le param `mode` et la branche
  `humanDrift → drifted_allowed`. La persistance du dernier-appliqué **reste** (utile en strict pour la
  traçabilité 24.4 n° 9 ; ne PAS la supprimer).

### Frontière reporting `drifted_allowed` (AC3 / piège 4)

[Source: app/Enums/AgentResourceStatus.php:24 ; app/Services/Agent/Reporting/ConformityService.php:51,108 ;
app/Repositories/WorkstationGroupRepository.php:236-246 ; app/Http/Requests/Api/V1/Agent/ReportRequest.php:59]

- L'enum `AgentResourceStatus::DriftedAllowed` et son traitement à l'ingestion **RESTENT** (l'agent ne
  l'émet simplement plus jamais → compteur toujours à 0). Retrait = refonte du dashboard conformité, hors
  intention. Au contrat §6 : retiré des statuts qu'un **agent v1** émet, documenté « plus jamais émis ».

### Environnement de dev — règles VM (worktree)

- Code à la RACINE (`app/`, `agent/`, …) — mémoire `project_root_is_laravel`.
- **Worktree `strict-only`** depuis main propre ; **aucune interaction VM** (mémoire
  `feedback_worktree_no_vm_sync`). Go = hôte (`~/go-toolchain/go/bin/go`, package main = `agent/windows` —
  mémoire `project_host_go_toolchain_path`). PHPUnit + `migrate` `/vm` = **actions Henri post-merge**.
- Migrations : drop de 3 colonnes (`migrate:status` avant). **Aucune** clé config ni route neuve → **pas**
  de `config:cache`/`route:cache` attendu.

### Invariants

- **NFR7** : aucune nouvelle dépendance AD dans le code agent ; le retrait ne touche pas la frontière auth.
- **NFR9** (anti-couteau-suisse) : on RETIRE une capacité, on n'en ajoute pas — réduit la surface, aligné.
- **NFR13** : golden croisé — `FROZEN_STATE_HASH` (PHP) ≡ `frozenStateHash` (Go), recomputés et identiques.
- Handlers **27.1 / 27.2 / 27.7 / 27.1bis** intouchés, sauf le retrait du champ `mode` de leur payload/flux.
- **Convergence stricte universelle** = comportement final, tous types.

### Dépendances

| Story | Rôle pour 27.8 | Statut | Bloquant ? |
|-------|----------------|--------|------------|
| 27.1 — handler raccourcis + mode par règle | A introduit le mécanisme à défaire (enum, candidate, agrégation, StateItem.Mode, toggles UI, golden bump). | `review` | **Base à défaire** — on retire en avant. |
| 27.3 — drift policy par assignation | A déplacé `shortcuts.mode → shortcut_assignables.mode` (Option A). 27.8 le supersede. | `review` | **Base à défaire** ; sera marquée `superseded` en finalisation. |
| 27.2 / 27.7 — printers/drives, icônes | Ont bumpé le golden APRÈS 27.1 → **revert exclu**, retrait forward. Payloads conservés. | `review` | Non (conservés). |
| 25.6 — catalogue tools (EN COURS) | Travail orthogonal sur `main`. **NE PAS toucher.** Worktree créé APRÈS son commit. | en cours | **Contrainte d'isolation** (encart de tête). |
| 23.1 / 23.4 / 24.6 — contrat, compilateur, moteur | Infra consommée (golden, hasher, engine) — modifiée pour retirer le mode. | `done` | Non. |

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md:33 (FR5 mode dès v1, RÉVISÉ — retiré), :56 (FR19 mode default — SUPPRIMÉ), :66/:177 (FR26 toggle — SUPPRIMÉ), :86 (NFR7), :88 (NFR9), :92 (NFR13), :698-699 (Story 27.1 toggle/drifted_allowed)] — exigences que ce retrait abroge.
- [Source: docs/agent/contract-v1.md:10 (booléen mode), :59-79 (§3 item 5 clés), :151-197 (§5 strict/default), :199-235 (§6 statuts dont drifted_allowed)] — contrat à réécrire (4 clés, strict pur).
- [Source: app/Enums/StateMode.php] — enum à SUPPRIMER.
- [Source: app/Services/Agent/StateCandidate.php:8,29-45 (`?StateMode $mode`)] — param à retirer.
- [Source: app/Services/Agent/StateCompiler.php:9 (import), :121 (« 5 clés »), :137-156 (agrégation mode), :163 (clé `mode`), :185-196 (`aggregateMode`)] — agrégation + clé item à retirer.
- [Source: app/Services/Agent/Providers/ShortcutsStateProvider.php:9,76-82,142-156] — `mode()` + lecture pivot à retirer.
- [Source: app/Services/Agent/Providers/WallpaperStateProvider.php:9,50-52,102,116-119] — `mode()` + `wallpapers.mode` à retirer.
- [Source: app/Services/Agent/Providers/OverlayStateProvider.php:9,55-57,94-97,146-154] — `mode()` + `overlay_signals.mode` + candidat identity à retirer.
- [Source: app/Models/Wallpaper.php:7,29,51,56 ; app/Models/OverlaySignal.php:8,27,43,48] — import/property/fillable/cast `mode` à retirer.
- [Source: app/Models/Shortcut.php:160,174 (`->withPivot('mode')`)] — pivot mode à retirer.
- [Source: database/migrations/2026_06_16_110000_add_mode_to_shortcut_assignables.php ; 2026_06_15_100100_add_mode_to_wallpapers.php ; 2026_06_15_100200_add_mode_to_overlay_signals.php] — colonnes vivantes à dropper (forward réversible).
- [Source: agent/shared/engine.go:51-57,79-86,116-154,219-264,266-300] — `StateItem.Mode`, parsing, `ResolveItemStatus`, dispatch à simplifier (conserver `ShouldPersist`).
- [Source: agent/shared/engine_test.go:43-118,246-256,359-373 ; hasher_test.go:27,74-99 ; loop_test.go:41] — cas drift/default à retirer, hash croisé à bumper.
- [Source: tests/Fixtures/Agent/state.v1.json:11,21,31,44,57 (clés mode) ; report.v1.json:22 (drifted_allowed)] — golden à régénérer.
- [Source: tests/Unit/Services/Agent/ContractV1Test.php:58,143 (FROZEN_STATE_HASH)] — hash + docblock bump.
- [Source: resources/views/components/organisms/shortcut-assignment-modal.blade.php:37-41,87,240,497-502 ; resources/views/pages/shortcuts/[id]/index.blade.php:231-279 ; _partials/shortcut-form.blade.php:113-116 ; components/molecules/wallpaper-card.blade.php:214-233,309-334 ; pages/parc-settings/overlay-messages/index.blade.php:33,79,97,112] — toggles/champs mode UI à retirer.
- [Source: tests/Unit/Models/StateModeCastTest.php ; tests/Feature/Livewire/ShortcutModeTogglePageTest.php] — tests à SUPPRIMER.
- [Source: app/Enums/AgentResourceStatus.php:24 ; app/Services/Agent/Reporting/ConformityService.php ; app/Repositories/WorkstationGroupRepository.php:236-246 ; app/Http/Requests/Api/V1/Agent/ReportRequest.php:59] — frontière reporting `drifted_allowed` CONSERVÉE (AC3).

## Questions pour Henri

1. **Frontière `drifted_allowed` côté reporting (piège 4 / AC3).** Le statut est câblé dans le sous-système
   conformité (`ConformityService`, filtres parc, `ReportRequest`, UI, tests). Cette story retire la
   capacité de l'**agent à l'ÉMETTRE** (moteur + contrat + golden) mais **conserve** l'enum
   `AgentResourceStatus::DriftedAllowed` et son traitement défensif à l'ingestion (un agent qui ne l'émet
   jamais ⇒ compteur toujours à 0). **Confirmes-tu ce périmètre** (vs un démantèlement complet du
   dashboard conformité — plus lourd, refonte FR9) ?

2. **Statut de l'item exemple `report.v1.json` qui portait `drifted_allowed`.** Je le remplace par un
   statut valide émis en strict — `drift` (réapplication) ou `compliant` ? (Défaut proposé : `drift`, pour
   garder un exemple non-trivial à côté du `compliant`/`error`.)

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Story **transversale et CONTRACT-BREAKING** : retrait chirurgical d'un même mécanisme à travers cinq
couches (enum/services/modèles PHP, migrations, contrat+golden, moteur Go, UI) avec un **bump de hash figé
croisé PHP↔Go** (NFR13) qui doit être recomputé et strictement identique des deux côtés. Le risque majeur
est double : (a) **casser un handler conservé** (27.1/27.2/27.7/27.1bis) ou la sémantique aggregate/exclusive
en retirant le mode, et (b) **régresser la persistance du dernier-appliqué** (`ShouldPersist`) en croyant
qu'elle suivait le mode `default`. S'y ajoute une **frontière de périmètre délicate** (`drifted_allowed`
encore câblé dans le reporting conformité, à ne PAS démanteler). Le raisonnement « ce qui part vs ce qui
reste », sur du code agent + contrat figé, justifie `opus`.
