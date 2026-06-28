# Story 30.4: Résolution d'un item ciblant un label

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **refnum**,
I want **qu'un item du contrat amont ciblant `label:<nom>` s'applique automatiquement à tout poste appartenant à un `WorkstationGroup` portant ce label (`controlhub_label = <nom>`, mapping livré en 30.2/30.3)** — en ÉTENDANT la machinerie de résolution amont de 28.3/29.3 (source amont + maille `Upstream`/`UpstreamPermissive`), sans toucher `StateCompiler`,
so that **l'imposition se propage au bon périmètre (les postes du bon type de parc) avec la même règle verrou/permissif que l'instance, SANS ordre de spécificité inter-parcs** (FR12).

> Story **4/5** de l'Epic 30 (« Cibler par labels — types de parc »). Elle livre **uniquement** l'**expansion `target_type = label` → postes** dans la **source de candidats amont** (`UpstreamContractSource`) — le dernier maillon qui rendait les items `label` **inertes** depuis 28.3 (où ils étaient « ignorés proprement »). Elle ne livre **PAS** la validation prédictive de collision insoluble (→ **30.5**), ni le mapping refnum d'un label libre (→ **30.2**, livré), ni la garantie d'existence des groupes imposés (→ **30.3**, livré).

> **Couture refermée.** En 28.3, `UpstreamContractSource::ensureResolved()` filtrait `where('target_type', Instance)` et documentait : *« les items `target_type = label` sont différés Epic 30 — ignorés proprement ici »*. **30.4 lève ce filtre** et branche l'expansion par label. C'est un **changement de comportement attendu** : le test 28.3 `label_targeted_item_is_ignored` doit être **revu** (cf. Task 5).

## Acceptance Criteria

1. **AC1 — Un item `label:<nom>` s'applique à un poste portant le label.**
   **Given** un item amont **verrouillé** (`locked`) ciblé `target_type = label`, `target_label = <nom>`, et un `WorkstationGroup` portant `controlhub_label = <nom>` auquel le poste appartient (salle physique OU parc logique — `TargetContext::workstationGroupIds()`),
   **When** `StateCompiler` compile l'état de ce poste,
   **Then** l'item amont est injecté comme candidat `StateMaille::Upstream` (rang -1) pour le couple `(providerType, scope)` de son adaptateur **et gagne** sur tout candidat local de même `exclusiveKey()` — **exactement** comme un item `instance` (28.3), via la même précédence `StateCompiler::specificity()` (aucune logique nouvelle dans le compilateur).

2. **AC2 — Un poste NE portant PAS le label n'est pas touché.**
   **Given** le même item `label:<nom>`,
   **When** `StateCompiler` compile l'état d'un poste dont **aucun** `WorkstationGroup` (salle ni parc) ne porte `controlhub_label = <nom>`,
   **Then** l'item amont **n'est pas injecté** : le compilé est **identique** à celui d'une instance sans cet item (le réglage local gagnant subsiste, byte-identique au standalone pour ce type/clé).

3. **AC3 — Cumul de deux valeurs imposées sur la même propriété via plusieurs parcs : règle verrou/permissif, SANS ordre de spécificité inter-parcs.**
   **Given** un poste membre de **deux** groupes portant **deux** labels distincts `<A>` et `<B>`, et **deux** items amont imposant la **même** propriété (même `exclusiveKey()`), l'un via `label:<A>` l'autre via `label:<B>`,
   **When** la résolution s'exécute,
   **Then** la règle verrou/permissif **seule** tranche :
   - amont **permissif** (`UpstreamPermissive`, rang 6) → **toute** maille locale l'emporte (le permissif est un plancher) ;
   - amont **verrouillé** (`Upstream`, rang -1) → l'amont l'emporte sur le local ;
   **And** entre deux candidats amont **de même état** (donc **même maille / même rang**), **aucune** spécificité inter-parcs n'intervient — l'arbitrage retombe sur le tiebreak intra-maille existant (`updated_at` desc puis `sourceId` desc), **jamais** sur un ordre logique>physique ni un classement des parcs (≠ `specificity()` du local).

4. **AC4 — Collision insoluble (deux verrous amont contradictoires sur la même clé) : comportement DÉFINI, non silencieux.**
   **Given** deux items amont **`locked`** ciblant des labels portés par le **même** poste, imposant des **valeurs contradictoires** sur la **même** `exclusiveKey()`,
   **When** `StateCompiler` compile,
   **Then** les deux candidats sont à la **même** maille `Upstream` (rang -1) ⇒ `resolveExclusiveWinner` les détecte « tied-at-top » et **émet le warning `agent.state.conflict`** existant (avec `maille = upstream` et les `rule_ids` des items en conflit), puis applique le **tiebreak déterministe** (`updated_at`/`sourceId`) pour ne pas servir d'état vide — le compilé reste **déterministe** (NFR4).
   **And** 30.4 **ne résout PAS** la collision et **n'introduit AUCUNE** résolution silencieuse/arbitraire : la **prévention** (interception à l'assignation d'un label / liaison d'un parc, avertissement opérateur explicite) relève de **30.5** (FR13). Documenter cette frontière 30.4↔30.5 en PHPDoc.

5. **AC5 — Standalone & inertie sans label (NFR3).**
   **Given** une instance **sans** contrat actif (`link_state = active` absent), **OU** un contrat actif **sans aucun** item `target_type = label`,
   **When** `StateCompiler` compile l'état d'un poste,
   **Then** le compilé est **byte-identique** au comportement actuel (mêmes items, même ordre, même `hashState()`) : **aucune** requête « labels portés par le poste » n'est émise (court-circuit), **aucun** candidat label injecté. La résolution des labels portés par un poste n'est déclenchée **que** si le contrat actif contient au moins un item `label`.

6. **AC6 — Déterminisme (NFR4 / ETag 23.5).**
   **Given** deux compilations du même poste, même contrat actif (items label inclus), à des instants différents,
   **When** on les hache,
   **Then** `hashState()` est **identique** : l'injection des candidats label est **stable** (items ordonnés par `id` ; labels portés résolus dans un ordre figé indépendant du plan SQL ; pas de N+1 par provider — la résolution du contrat **et** la résolution des labels portés sont **mémoïsées** pour la durée de la compilation).

7. **AC7 — D2 confiné & R3.**
   **Given** le code livré,
   **When** on l'inspecte,
   **Then** (a) **aucune** ligne n'est ajoutée à `StateCompiler` (ni `specificity()`, ni `selectExclusive`, ni `resolveExclusiveWinner`) ni à `StateMaille` — toute l'expansion vit dans `UpstreamContractSource` (+ passage du `TargetContext` par `UpstreamAwareProvider`) : D2 ne fuit pas, le moteur est **réutilisé** ; (b) **aucun** identifiant livré (classe, méthode, propriété, message, test) ne contient le mot **« central »** (R3) — vocabulaire `Upstream` / `ControlHub*` / `label` / « amont ».

## Tasks / Subtasks

- [x] **Task 1 — Passer le `TargetContext` à la source amont** (AC: #1, #2, #5) — couture minimale
  - [x] `app/Services/ControlHub/Resolution/UpstreamAwareProvider.php` : dans `itemsFor(TargetContext $ctx)`, passer `$ctx` à la source : `$this->source->candidatesFor($this->inner->type(), $this->inner->scope(), $ctx)`. **Aucun** autre changement (toujours `concat`, toujours pass-through strict si la source rend `[]`). `KeyedUpstreamAwareProvider` hérite — rien à changer.
  - [x] Étendre la signature `UpstreamContractSource::candidatesFor(string $providerType, StateScope $scope, TargetContext $ctx): array`. (Le `$ctx` était déjà disponible côté provider depuis 28.3 ; il n'était simplement pas relayé.)

- [x] **Task 2 — Expansion `target_type = label` dans `UpstreamContractSource`** (AC: #1, #2, #3, #4, #5, #6) — **cœur de la story**
  - [x] `ensureResolved()` : **lever** le filtre `where('target_type', Instance)` → charger **instance ET label** (`whereIn('target_type', [Instance, Label])`, l'enum `whereIn enforcement` `[Locked, Permissive]` et `orderBy('id')` **inchangés** — `absent` toujours exclu, `severed`/inactif jamais lu). Pour chaque item :
    - `target_type = instance` ⇒ groupé comme aujourd'hui dans `$grouped[providerType|scope]` (comportement 28.3 **strictement** préservé).
    - `target_type = label` ⇒ groupé dans une **nouvelle** structure `$groupedByLabel[$item->target_label][providerType|scope][]` — **même** construction de `StateCandidate` (même maille divergente `Upstream`/`UpstreamPermissive` selon `enforcement_state`, même `toPayload()` via l'adaptateur, même `sourceId = item.id`). Le nom du label vient de `ControlHubContractItem::$target_label` (`string` NOT NULL — `''` pour instance, jamais lu côté label).
    - Item `label` sans adaptateur enregistré pour son `type` ⇒ **ignoré proprement** (même couture Epic 33 que les items instance).
  - [x] `candidatesFor($providerType, $scope, $ctx)` : retourne `instanceCandidates ∪ (⋃ labels portés par le poste) labelCandidates[label][type|scope]`.
    - **Court-circuit NFR3 (CRITIQUE)** : si `$groupedByLabel` est **vide** (aucun item label dans le contrat actif, ou pas de contrat actif), **NE PAS** résoudre les labels portés (aucune requête `WorkstationGroup`) → retour = `$grouped[...]` (28.3 inchangé, byte-identique AC #5).
  - [x] **Résolution des labels portés par le poste** — helper privé `labelsCarriedBy(TargetContext $ctx): array` :
    - Source des groupes = `$ctx->workstationGroupIds()` (salles physiques + parcs logiques **directs**, déjà résolus une fois par `TargetContext` — **ne pas** re-requêter les appartenances).
    - Requête unique : `WorkstationGroup::query()->whereIn('id', $ctx->workstationGroupIds())->whereNotNull('controlhub_label')->pluck('controlhub_label')` → `array_values(array_unique(...))` **trié** (déterminisme AC #6). (Réutilise la colonne `controlhub_label` de 30.2 ; le scope `carryingControlHubLabel()` cible UN nom — ici on lit l'inverse, les noms portés par un ensemble d'ids.)
    - **Mémoïsation par poste** : `array<int $workstationId, list<string>>` (le singleton est par-requête en prod ⇒ une seule résolution/poste/compilation ; pas de N+1 sur les ~10 providers décorés). Clé = `$ctx->workstation->id`.
  - [x] **R3** : aucun « central ». **NFR3/NFR4** documentés en PHPDoc (court-circuit + mémoïsation + ordre stable).

- [x] **Task 3 — Frontière 30.4 ↔ 30.5 (collision) : documenter, NE PAS résoudre** (AC: #4)
  - [x] En PHPDoc de `UpstreamContractSource` (et/ou de la méthode d'expansion) : préciser que **deux items `locked` contradictoires** portés par un même poste via deux labels produisent deux candidats `Upstream` de **même rang** ⇒ le warning `agent.state.conflict` existant (`StateCompiler::resolveExclusiveWinner`) **signale** la collision (observabilité), le tiebreak déterministe évite un état vide, et la **prévention prédictive** (avertir le refnum à l'assignation) est **Story 30.5** (FR13). **Aucune** branche de résolution ad hoc dans la source (D2 ne fuit pas — AC #7a).
  - [x] (Aucun code de détection à ajouter en 30.4 : le signal `agent.state.conflict` est déjà émis par le compilateur pour tout « tied-at-top ». 30.4 se contente de **vérifier par test** qu'il se déclenche pour une collision amont — Task 5.)

- [x] **Task 4 — Garde-fou « pas d'expansion des appartenances hors source »** (AC: #5, #7) — anti-régression
  - [x] **NE PAS** ajouter de propriété de label à `TargetContext` (rester contrat-agnostique : `TargetContext` est construit pour **chaque** compilation, standalone incluse — y injecter une lecture de label casserait la séparation et risquerait NFR3). La résolution des labels portés vit **dans la source amont** (déjà court-circuitée sans item label). Documenter ce choix de placement (cf. Dev Notes § « Point d'extension exact »).
  - [x] **NE PAS** modifier `AgentServiceProvider` (le câblage 28.3 enrobe déjà chaque provider ; la source reçoit le `$ctx` au runtime via le provider, pas au boot). Vérifier qu'aucun nouveau binding n'est requis.

- [x] **Task 5 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#7)
  - [x] **Étendre** `tests/Feature/ControlHub/UpstreamContractResolutionTest.php` (réutiliser `fakeProvider`/`keyedExclusiveProvider`, factories `ControlHubContract*Factory`, `WorkstationGroupFactory`). `Queue::fake()` / `WorkstationGroupObserver::disableSync()` pour neutraliser l'AD-sync au `WorkstationGroup::factory()->create()`.
  - [x] `label_item_applies_to_workstation_carrying_the_label` (AC #1) : item `locked` `target_type=label`, `target_label='salle-info'` (type `registry`) ; un `WorkstationGroup` `controlhub_label='salle-info'` rattaché au poste (`physicalRooms`/`logicalGroups`) ; candidat local même clé → **valeur amont** dans le compilé.
  - [x] `label_item_does_not_apply_when_workstation_lacks_the_label` (AC #2) : même item, mais aucun groupe du poste ne porte `'salle-info'` → **valeur locale** dans le compilé (item amont non injecté).
  - [x] **Revoir** le test 28.3 `label_targeted_item_is_ignored` (l. ~351) : son intention (« label ignoré ») change. Le **renommer/réécrire** en AC #2 (poste sans le label → non appliqué) **ou** le supprimer au profit du nouveau test ci-dessus — **documenter** le changement de comportement dans le Dev Agent Record. (Ne pas laisser un test qui prétend « les items label sont ignorés » : faux depuis 30.4.)
  - [x] `two_locked_labels_same_key_emit_conflict_and_pick_deterministically` (AC #4) : poste portant `<A>` et `<B>` ; deux items `locked` même `exclusiveKey`, valeurs différentes via `label:<A>` et `label:<B>` → assert (a) le compilé porte une valeur **stable** (tiebreak `id`/`updated_at`), (b) le warning `agent.state.conflict` est émis (capture du log `channel('agent')`, patron déjà utilisé dans `StateCompilerTest`), maille `upstream`. **Pas** de spécificité inter-parcs (les deux à rang -1).
  - [x] `permissive_label_is_overridden_by_any_local` (AC #3) : item `permissive` via `label:<A>` (maille `UpstreamPermissive` rang 6) + candidat local même clé → **valeur locale** gagne (plancher battu). Symétrique `locked_label_beats_local` couvert par AC #1.
  - [x] `standalone_and_no_label_items_are_byte_identical_and_emit_no_label_query` (AC #5, #6) : (a) sans contrat actif et (b) contrat actif **sans** item label → compilé item-par-item + `hashState()` **identique** au provider non décoré, **et** assertion `DB::enableQueryLog()` : **zéro** requête `workstation_groups` pour la résolution des labels portés (court-circuit). Comparer aussi à `travel()` pour le déterminisme.
  - [x] `r3_no_central_identifier` (AC #7b) : étendre l'introspection existante aux fichiers livrés/modifiés (scanner FQCN + littéraux des fichiers de `app/Services/ControlHub/Resolution/`).
  - [x] **Non-régression** : `--filter UpstreamContractResolution` (tests 28.3/29.3 verts, modulo la réécriture du test label) ; `--filter StateCompiler` (le moteur n'a PAS changé) ; `--filter ControlHubContract` ; `--filter WorkstationGroupLabel` (30.2).
  - [x] **Pièges SQLite** : mesurer déterminisme/court-circuit par **comparaison d'items + hash + comptage de requêtes**, jamais par contrainte varchar / unicité sur NULL. [mémoire `sqlite_tests_no_varchar_enforcement`]

- [x] **Task 6 — Doc QA (append-only)** (observabilité)
  - [x] **Enrichir** `docs/qa/domains/controlhub-contract.md` d'une **nouvelle section** « Story 30.4 — Résolution d'un item ciblant un label » (append ; ne jamais réécrire les sections existantes) : propagation `label:<nom>` → postes du parc portant le label, règle verrou/permissif sans spécificité inter-parcs, comportement collision (warning `agent.state.conflict` + frontière 30.5), standalone/sans-label byte-identique. Mettre à jour la ligne `controlhub-contract` de `docs/qa/README.md` (append, mentionner 30.4).

- [x] **Task 7 — Validation finale** (AC: #7)
  - [x] `CACHE_DRIVER=array php artisan test --filter UpstreamContractResolution` (HÔTE) → vert (l'hôte n'a pas APCu ; `vendor/bin/phpunit` lit `phpunit.xml`). [mémoire `apcu_cache_no_lock`, record 30.3]
  - [x] `--filter StateCompiler`, `--filter ControlHubContract`, `--filter WorkstationGroupLabel` → verts (non-régression).
  - [x] `grep -riE central` sur les fichiers livrés → vide (R3). Confirmer **zéro** ligne nouvelle dans `app/Services/Agent/StateCompiler.php` et `app/Enums/StateMaille.php` (D2/maille réutilisés tels quels — AC #7a).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 30.4

**DANS** : (1) relais du `TargetContext` `UpstreamAwareProvider → UpstreamContractSource::candidatesFor()` ; (2) chargement des items `target_type = label` (filtre 28.3 levé) groupés par `target_label` ; (3) résolution **par poste** des labels portés (`WorkstationGroup.controlhub_label` via `TargetContext::workstationGroupIds()`), mémoïsée + court-circuitée NFR3 ; (4) injection des candidats label aux mailles `Upstream`/`UpstreamPermissive` **déjà** définies (29.3) ; (5) tests HÔTE (s'applique/ne s'applique pas/cumul verrou-permissif/collision-conflict/standalone byte-identique/déterminisme/R3) ; (6) doc QA append.

**HORS** (ne pas déborder) :
- **Validation prédictive de collision insoluble** (avertir le refnum **à l'assignation** d'un label / liaison d'un parc, avant que la contradiction n'atteigne le poste) → **Story 30.5** (FR13). 30.4 **définit** le comportement runtime (warning `agent.state.conflict` + tiebreak déterministe) mais **ne prévient pas** et **ne résout pas** la collision.
- **Mapping refnum d'un label libre** (colonne `controlhub_label`, service assign/detach, UI) → **30.2** (livré). 30.4 **consomme** `controlhub_label`, ne le re-livre pas.
- **Garantie d'existence des groupes imposés** (réconciliation, verrou de suppression) → **30.3** (livré).
- **Changement de `StateCompiler` / `StateMaille` / `specificity()`** → **interdit** : la maille `Upstream` (28.3) et `UpstreamPermissive` (29.3) existent ; 30.4 ne fait qu'**émettre plus de candidats** à ces mailles. Aucune nouvelle précédence.
- **Représentation canonique du payload / nouveaux adaptateurs de type** → **Epic 33**. 30.4 réutilise l'adaptateur `registry` câblé (seul en prod) ; un item label dont le `type` n'a pas d'adaptateur est ignoré proprement (couture existante).

### Point d'extension EXACT dans l'archi 28.3/29.3

La machinerie amont est **déjà** en place (28.3 + 29.3) ; 30.4 n'ajoute **qu'une dimension de ciblage** dans la **source**, pas un nouveau tier :

```
StateController → StateCompiler::compile(TargetContext $ctx)
  └─ pour chaque provider décoré : UpstreamAwareProvider::itemsFor($ctx)
        └─ candidats_internes  ∪  UpstreamContractSource::candidatesFor(type, scope, $ctx)   ← 30.4 : on passe $ctx
              ├─ instance candidates  (28.3, context-free, mémoïsés)                          ← inchangé
              └─ label candidates     (30.4, filtrés par labels portés par $ctx)              ← NOUVEAU
        (maille Upstream / UpstreamPermissive selon enforcement_state — 29.3, inchangé)
  └─ StateCompiler::specificity()  arbitre la précédence  (D2, INCHANGÉ — rang -1 / rang 6)
```

- **Fichier pivot** : `app/Services/ControlHub/Resolution/UpstreamContractSource.php` — `ensureResolved()` (lever le filtre instance, pré-grouper les labels) + `candidatesFor()` (réunir instance ∪ label-portés) + helper `labelsCarriedBy()`.
- **Couture du contexte** : `app/Services/ControlHub/Resolution/UpstreamAwareProvider.php::itemsFor()` — passer `$ctx` (1 ligne). `KeyedUpstreamAwareProvider` hérite (rien à changer).
- **Pourquoi la source et pas `TargetContext`** : `TargetContext::for()` est construit pour **toute** compilation (standalone incluse) ; y résoudre les labels portés ferait une requête `WorkstationGroup` même sans contrat → casse NFR3 (byte-identique + comptage de requêtes). La source, elle, **court-circuite** déjà sans contrat actif et n'a besoin des labels portés **que** si le contrat contient au moins un item label. La séparation (contexte agent générique vs concern amont) est aussi un invariant 28.3.

### Règle verrou/permissif SANS spécificité inter-parcs — comment c'est déjà garanti

Le point délicat de FR12 (« sans ordre de spécificité inter-parcs ») **tombe gratuitement** de l'archi 29.3, à condition de **ne rien ajouter** :
- `locked` → **toujours** `StateMaille::Upstream` (rang -1), que la cible soit `instance` ou `label:<A>` ou `label:<B>`. Deux items `locked` via deux parcs ⇒ **même rang** ⇒ `resolveExclusiveWinner` ne les départage **pas** par parc (il n'a pas cette notion) mais par le tiebreak intra-maille `updated_at` desc / `sourceId` desc + warning conflit.
- `permissive` → **toujours** `StateMaille::UpstreamPermissive` (rang 6, plancher).
- Le `specificity()` du **local** (logique>physique, 27.3) ne s'applique **jamais** aux candidats amont (ils ne portent pas de maille locale). ⇒ **aucun** ordre logique>physique inter-parcs côté amont. C'est exactement l'AC #3.
- **Anti-pattern à proscrire** : faire dépendre la maille d'un candidat label du **type** de parc (physique/logique) qui porte le label, ou ordonner les labels entre eux. Ce serait réintroduire une spécificité inter-parcs **interdite** par FR12 et ferait fuiter D2 dans la source. La maille dérive **uniquement** de `enforcement_state` (source de vérité, comme en 29.3).

### Collision insoluble — frontière 30.4 / 30.5 (à documenter, pas à résoudre)

Deux items `locked` contradictoires sur la même `exclusiveKey()`, portés par le même poste via deux labels : **vraie** collision insoluble (aucune des deux valeurs ne peut être « la bonne »). 30.4 :
- **NE résout PAS** par un choix métier (interdit : ce serait arbitraire/silencieux). Elle **réutilise** le signal existant : `resolveExclusiveWinner` voit deux candidats au rang -1 « tied-at-top » → `Log::channel('agent')->warning('agent.state.conflict', {maille:'upstream', rule_ids:[...]})`. Le tiebreak déterministe (récence/`sourceId`) évite de servir un état **vide** au poste (sinon l'agent diverge), mais le warning **trace** la pathologie.
- **30.5** intercepte **en amont** (validation **prédictive** à l'assignation d'un label ou à la liaison d'un parc) : SE5 détecte que l'assignation **aboutirait** à deux verrous contradictoires sur un poste et **avertit explicitement** le refnum (item, périmètre, valeurs en conflit) **avant** application — c'est la vraie « prévention ». 30.4 fournit la **résolution runtime observable** ; 30.5 la **prévention proactive**.

### Code réel à réutiliser (ancrage exact — vérifié)

- `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (28.3/29.3) — `ensureResolved()` filtre actuel `where('target_type', Instance->value)` + `whereIn('enforcement_state', [Locked, Permissive])` + `orderBy('id')` ; maille divergente `permissive → UpstreamPermissive`, sinon `Upstream` ; `grouped[providerType|scope]`. **C'est le fichier à étendre.**
- `app/Services/ControlHub/Resolution/UpstreamAwareProvider.php` — `itemsFor()` appelle `candidatesFor($type, $scope)` **sans `$ctx`** : ajouter `$ctx`. `wrap()` choisit `KeyedUpstreamAwareProvider` si `KeyedExclusiveProvider` (inchangé).
- `app/Services/Agent/TargetContext.php` — `workstationGroupIds(): list<int>` (salles physiques + parcs logiques directs, résolus une fois ; **ne pas** re-requêter). `->workstation->id` pour la clé de mémoïsation. **Ne pas** y ajouter de label.
- `app/Models/WorkstationGroup.php` — colonne `controlhub_label` (string nullable indexée, 30.2), helpers `hasControlHubLabel()`/`controlHubLabel()`, scope `scopeCarryingControlHubLabel($q, $name)` (cible UN nom). Pour 30.4, lire l'**ensemble** des `controlhub_label` portés par un set d'ids : `whereIn('id', $ids)->whereNotNull('controlhub_label')->pluck('controlhub_label')`.
- `app/Models/ControlHubContractItem.php` — `$target_type` (cast `ControlHubContractTarget`), `$target_label` (`string` NOT NULL, `''` si instance), `$enforcement_state`, `$type`, `$key`, `$value`. Relation `contract()`.
- `app/Enums/ControlHubContractTarget.php` — `Instance`, `Label`.
- `app/Enums/ControlHubEnforcementState.php` — `Locked`, `Permissive`, `Absent`.
- `app/Services/Agent/StateCompiler.php` — `resolveExclusiveWinner()` émet `agent.state.conflict` pour « tied-at-top » (réutilisé pour la collision amont) ; `specificity()` (`Upstream => -1`, `UpstreamPermissive => 6`). **Aucun** changement.
- `app/Providers/AgentServiceProvider.php` — enrobage 28.3 des providers + singleton `UpstreamContractSource` (un seul adaptateur `registry` en prod). **Aucun** changement attendu.
- `tests/Feature/ControlHub/UpstreamContractResolutionTest.php` — helpers `fakeProvider`/`keyedExclusiveProvider`, factory items, capture des warnings (patron `StateCompilerTest`), test `label_targeted_item_is_ignored` à **revoir**.
- Factories : `database/factories/ControlHubContractFactory.php` (`active`), `ControlHubContractItemFactory.php` (`forLabel($name)`, `permissive`, `absent`), `WorkstationGroupFactory.php` (+ `controlhub_label`).

### Garde-fous projet CRITIQUES

- **R3 — Vocabulaire (BLOQUANT)** : aucun « central » dans tout identifiant/message/test livré. Vocabulaire `Upstream`/`ControlHub*`/`label`/« amont ». [mémoires `project_contrat_manage_se5_upstream`, `legacy_central_vs_local_split` ; prd#R3]
- **NFR3 — Standalone préservé (CŒUR)** : sans contrat actif **ou** sans item label, **byte-identique** + **zéro** requête « labels portés ». Le court-circuit `$groupedByLabel === []` est obligatoire (test révélateur AC #5). [prd#NFR3]
- **NFR4 — Déterminisme** : items par `id`, labels portés triés, mémoïsation par poste (pas de N+1 sur ~10 providers). [StateCompiler PHPDoc]
- **D2 ne fuit pas** : toute la précédence reste dans `specificity()` (inchangé) ; la source n'émet que des candidats **bruts** étiquetés `Upstream`/`UpstreamPermissive`. **Interdit** : trier/élire/arbitrer par parc dans la source ; faire dépendre la maille du type de parc. [StateCompiler PHPDoc ; mémoire `permissive_floor_least_specific`]
- **Pas de spécificité inter-parcs côté amont** — ne JAMAIS réintroduire `specificity()` local pour départager deux labels. [mémoire `state_precedence_logical_over_physical` = local UNIQUEMENT ; FR12]
- **Racine = projet Laravel** (artisan/app à la racine). [mémoire `root_is_laravel`]
- **Worktree** : tests HÔTE uniquement, **jamais** la VM/serveurs. [mémoires `phpunit_test_env_host_vs_vm`, `feedback_worktree_no_vm_sync`]

### Project Structure Notes

- **Modifiés** : `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (expansion label + helper), `app/Services/ControlHub/Resolution/UpstreamAwareProvider.php` (relais `$ctx`), `tests/Feature/ControlHub/UpstreamContractResolutionTest.php` (nouveaux tests + réécriture du test label), `docs/qa/domains/controlhub-contract.md` (append), `docs/qa/README.md` (append).
- **Nouveaux** : aucun fichier de production neuf attendu (extension de l'existant). Éventuellement un trait/helper de test si besoin.
- **AUCUNE migration** (colonne `controlhub_label` livrée en 30.2). **AUCUN** changement de `StateCompiler`/`StateMaille`/`AgentServiceProvider` (à vérifier — AC #7a).

### Pièges identifiés

1. **Le test 28.3 `label_targeted_item_is_ignored` devient faux** — le réécrire (poste sans le label → non appliqué) ou le remplacer. Ne pas le laisser tel quel.
2. **NFR3 byte-identique** : oublier le court-circuit `$groupedByLabel === []` ⇒ requête `WorkstationGroup` parasite en standalone ⇒ AC #5 échoue (comptage de requêtes). Garde obligatoire.
3. **N+1 par provider** : `candidatesFor()` est appelé une fois **par provider** (~10) ; sans mémoïsation de `labelsCarriedBy($ctx)`, on requête 10× les labels portés. Mémoïser par `workstation->id`.
4. **`target_label` ≠ `controlhub_label`** : le premier est sur l'item amont (nom du label ciblé), le second sur le `WorkstationGroup` (nom du label porté). La jointure se fait **par nom** (cohérent 28.1/30.2, pas de FK). Égalité exacte de chaîne.
5. **Ne pas faire dépendre la maille du type de parc** (physique/logique) — la maille vient de `enforcement_state` SEUL (sinon spécificité inter-parcs interdite + fuite D2).
6. **Collision** : ne pas ajouter de résolution métier ; s'appuyer sur le warning `agent.state.conflict` existant + tiebreak. La prévention est 30.5.
7. **SQLite** : déterminisme/court-circuit testés par items+hash+comptage de requêtes, jamais varchar/NULL.

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 30.4] — AC d'origine (item `label:<nom>` → postes du label ; cumul tranché par verrou/permissif sans spécificité inter-parcs ; FR12), l. 286-300.
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 30.5] — collision insoluble = validation prédictive à l'assignation (FR13) ; frontière 30.4/30.5.
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#NonFunctional Requirements] — NFR3 (standalone), NFR4 (déterminisme/idempotence), Additional (réutiliser `specificity()`, R3).
- [Source: _bmad-output/implementation-artifacts/28-3-resolution-amont-local-statecompiler.md] — source amont, décorateur, maille `Upstream`, court-circuit NFR3, couture `label → Epic 30` à refermer.
- [Source: _bmad-output/implementation-artifacts/30-2-mapping-label-refnum.md] — colonne `controlhub_label` (par nom), helpers/scope WG, rattachement sans FK dure.
- [Source: _bmad-output/implementation-artifacts/30-3-garantie-existence-groupes-imposes.md] — garantie d'existence des groupes imposés (les groupes portant un label réservé existent).
- [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php] — `ensureResolved()`/`candidatesFor()` (point d'extension) ; maille divergente 29.3.
- [Source: app/Services/ControlHub/Resolution/UpstreamAwareProvider.php] — relais `$ctx`.
- [Source: app/Services/Agent/TargetContext.php#workstationGroupIds] — appartenances directes résolues une fois.
- [Source: app/Models/WorkstationGroup.php#scopeCarryingControlHubLabel] — colonne `controlhub_label`, lecture par nom.
- [Source: app/Services/Agent/StateCompiler.php#resolveExclusiveWinner,#specificity] — warning `agent.state.conflict`, rangs `Upstream`/`UpstreamPermissive` (INCHANGÉS).
- [Source: tests/Feature/ControlHub/UpstreamContractResolutionTest.php] — patrons de test + test label à revoir.

## Dépendances

- **Amont (bloquantes) — toutes satisfaites dans le code (vérifié, pas seulement sprint-status)** :
  - **28.3 (`review`, code présent)** — **FONDATION DIRECTE** : `UpstreamContractSource`, `UpstreamAwareProvider`/`KeyedUpstreamAwareProvider`, maille `StateMaille::Upstream` + rang -1 dans `specificity()`, adaptateur `registry` câblé, court-circuit NFR3. **Tout présent** (`app/Services/ControlHub/Resolution/*`, `app/Enums/StateMaille.php`, `app/Providers/AgentServiceProvider.php`). 30.4 **lève** le filtre `target_type=instance` documenté ici.
  - **29.3 (`review`, code présent)** — maille `StateMaille::UpstreamPermissive` (rang 6) + injection divergente `locked→Upstream`/`permissive→UpstreamPermissive` dans `UpstreamContractSource` (vérifié l.157-159). La règle verrou/permissif de FR12 **réutilise** ce mécanisme.
  - **30.2 (`review`, code présent)** — colonne `workstation_groups.controlhub_label` (migration `2026_06_27_100000`), `$fillable`, helpers, scope `carryingControlHubLabel()` (vérifié dans `app/Models/WorkstationGroup.php`). 30.4 lit `controlhub_label`.
  - **28.1 (`review`, commité main)** — `ControlHubContractItem.target_type/target_label` (vérifié au modèle), enums `ControlHubContractTarget`. 
  - **30.3 (`review`, code présent)** — garantit que les groupes imposés (et leur `controlhub_label` réservé) existent ; non strictement bloquant pour le dev (tests seedent les WG directement) mais complète le tableau métier.
  - > **Vérification CODE (≠ sprint-status)** : tous les artefacts 28.x/29.x/30.x sont **présents et exploitables** sur la branche (rebase unifié). `UpstreamContractSource` contient déjà l'injection divergente 29.3 et le filtre `Instance` à lever. Aucun blocage de build.
- **Aval (dépend de 30.4)** :
  - **30.5** — validation prédictive de collision : s'appuie sur la résolution par label livrée ici pour prédire les contradictions **avant** application.

## Testing

- **Cible : HÔTE** (php8.4 + `pdo_sqlite`), **jamais la VM**. Lancer avec `CACHE_DRIVER=array` (hôte sans APCu — sinon boot artisan échoue). `vendor/bin/phpunit` lit `phpunit.xml` (`DB_CONNECTION=sqlite`, `RefreshDatabase`). [mémoires `phpunit_test_env_host_vs_vm`, `apcu_cache_no_lock`]
- Filtres : `--filter UpstreamContractResolution` (cœur) ; non-régression `--filter StateCompiler`, `--filter ControlHubContract`, `--filter WorkstationGroupLabel`.
- Couverture : item label appliqué au poste portant le label (AC1) ; poste sans le label → non touché (AC2) ; cumul 2 parcs même propriété → verrou>local, permissif<local, **sans** spécificité inter-parcs (AC3) ; collision 2 verrous → warning `agent.state.conflict` + tiebreak déterministe, **pas** de résolution silencieuse (AC4) ; standalone & sans-item-label byte-identique + **zéro** requête labels (AC5) ; déterminisme `hashState()` via `travel()` (AC6) ; R3 + zéro changement `StateCompiler`/`StateMaille` (AC7).
- **Neutraliser l'AD-sync** au `WorkstationGroup::factory()->create()` : `Queue::fake()` ou `WorkstationGroupObserver::disableSync()` (pas de LDAP en HÔTE). [mémoire `test_suite_env_and_systemic_fixes`]
- **Pièges SQLite** : items+hash+comptage de requêtes ; jamais varchar/unicité NULL. [mémoire `sqlite_tests_no_varchar_enforcement`]

## Recommandation Modèle Dev

**`opus`.**

Justification : story **au cœur du moteur de résolution amont**, courte en surface mais dense en invariants transverses simultanés où un dev pressé casse silencieusement :
1. **NFR3 byte-identique + comptage de requêtes** : le court-circuit `$groupedByLabel === []` (pas de requête labels en standalone/sans-label) est un raisonnement de non-régression fin ; l'oublier passe les tests fonctionnels mais casse le test révélateur de requêtes.
2. **FR12 « sans spécificité inter-parcs »** : la tentation naturelle est de départager deux labels par type de parc (logique>physique) — exactement l'anti-pattern interdit qui ferait **fuiter D2** dans la source. Comprendre que la règle tombe gratuitement de la maille `enforcement_state` (29.3) sans rien ajouter demande du jugement architectural.
3. **Frontière collision 30.4/30.5** : ne PAS résoudre, réutiliser le warning existant, documenter — distinguer « résolution runtime observable » de « prévention prédictive » est subtil.
4. **Mémoïsation par poste** (anti N+1 sur ~10 providers) + déterminisme stable de l'injection label.

Le dev-cycle routera la **review vers le modèle opposé** (sonnet/fable) ; placer **opus** sur l'implémentation met le raisonnement là où le risque d'effet de bord (régression standalone, fuite D2, spécificité inter-parcs fautive, N+1) est maximal. Cohérent avec 28.3/29.3/30.2/30.3 (toutes en opus).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- `CACHE_DRIVER=array vendor/bin/phpunit --filter UpstreamContractResolution` → **18 tests / 280 assertions, OK**.
- `CACHE_DRIVER=array vendor/bin/phpunit --filter 'StateCompiler|ControlHubContract|Upstream|Imposed|WorkstationGroupLabel'` (non-régression) → **182 tests / 953 assertions, OK** (0 régression).
- `grep -in central` sur les fichiers de résolution livrés → uniquement les commentaires garde-fou « aucun central » (zéro identifiant/littéral).

### Completion Notes List

**Net-new / modifications**

1. **`UpstreamContractSource`** (cœur) : filtre `where('target_type', Instance)` **levé** → `whereIn('target_type', [Instance, Label])` (`enforcement` `[Locked, Permissive]` et `orderBy('id')` inchangés). Les items `label` sont pré-groupés par `target_label` dans une nouvelle structure `$groupedByLabel[label][providerType|scope]` (même maille divergente `locked→Upstream`/`permissive→UpstreamPermissive`, même `toPayload()`, même `sourceId`). Nouveau `candidatesFor(type, scope, $ctx)` = `instance ∪ (labels portés par le poste)`. Helper privé `labelsCarriedBy($ctx)` : lit `WorkstationGroup.controlhub_label` via `$ctx->workstationGroupIds()`, **trié + dédupliqué**, **mémoïsé par `workstation->id`** (`$labelsCarriedByWorkstation`, anti-N+1 sur les ~10 providers décorés). PHPDoc enrichie : ciblage label, court-circuit NFR3, règle verrou/permissif sans spécificité inter-parcs (FR12), frontière collision 30.4↔30.5.
2. **`UpstreamAwareProvider::itemsFor()`** : relaie `$ctx` à `candidatesFor(...)` (1 ligne + PHPDoc). `KeyedUpstreamAwareProvider` hérite, aucun changement.
3. **`StateCompiler` / `StateMaille` / `specificity()` / `AgentServiceProvider`** : **AUCUNE** ligne modifiée (vérifié — D2 confiné, AC #7a). La règle FR12 « sans spécificité inter-parcs » tombe gratuitement de la maille dérivée de `enforcement_state` (29.3).

**Court-circuit NFR3 (CRITIQUE)** : `candidatesFor()` retourne immédiatement `$grouped[...]` si `$groupedByLabel === []` (aucun item label dans le contrat actif, ou pas de contrat). Prouvé par test : **zéro** requête `workstation_groups` même quand le poste appartient à un parc porteur de label, et compilé identique à celui d'un poste sans parc.

**Changement de comportement documenté (test 28.3 réécrit)** : `label_targeted_item_is_ignored` (qui asserrait « un item label est ignoré ») est devenu **faux** depuis 30.4. Il a été **remplacé** par deux tests : `label_item_applies_to_workstation_carrying_the_label` (AC #1) et `label_item_does_not_apply_when_workstation_lacks_the_label` (AC #2). Le commentaire de classe du fichier de test a été mis à jour (« label ignoré (Epic 30) » retiré).

**Tests ajoutés** (`UpstreamContractResolutionTest`, +6 nets, 18 au total) : AC1 (label appliqué au poste porteur), AC2 (poste sans le label → local survit), AC3 volet permissif (`permissive_label_is_overridden_by_any_local` ; le volet verrou est AC1), AC4 (`two_locked_labels_same_key_emit_conflict_and_pick_deterministically` — warning `agent.state.conflict` maille `upstream` + tiebreak `sourceId` desc déterministe, **sans** spécificité inter-parcs), AC5/6 (`active_contract_without_label_items_is_byte_identical_and_emits_no_label_query` — zéro requête WG + item instance servi ; `label_injection_is_deterministic_across_compilations` via `travel()`). `r3_no_central_identifier` étendu au scan des **littéraux** (PhpToken, pas les commentaires) des fichiers de résolution. Helpers ajoutés : `contextCarryingLabels()`, `captureAgentWarnings()` (patron `StateCompilerTest`). `WorkstationGroupObserver::disableSync()` en `setUp`/`enableSync()` en `tearDown` (neutralise l'AD-sync au `WorkstationGroup::factory()->create()`, pas de LDAP en HÔTE).

**Déviation mineure vs story** : la story suggérait pour `r3_no_central_identifier` de « scanner FQCN + littéraux des fichiers ». Un scan brut de `file_get_contents()` aurait échoué car les fichiers de résolution contiennent volontairement « central » dans leurs commentaires garde-fou (« aucun central »). Le scan ajouté tokenise donc le source (`PhpToken`) et ne vérifie que les **littéraux de chaîne** (`T_CONSTANT_ENCAPSED_STRING`/`T_STRING`), jamais les commentaires — conforme à l'intention R3 (identifiants/messages, pas les garde-fous). Les nouveaux identifiants (`labelsCarriedBy`, `groupedByLabel`, `labelsCarriedByWorkstation`) restent couverts par la boucle reflection existante (FQCN dans `$deliveredFqcns`).

**Garde-fous tenus** : R3 (aucun « central » identifiant/littéral) ; NFR3 (byte-identique + zéro requête WG sans item label, prouvé par comptage de requêtes) ; NFR4 (déterminisme `hashState()` via `travel()`, labels triés, mémoïsation par poste) ; D2 confiné (zéro ligne `StateCompiler`/`StateMaille`). Aucune migration, aucun fichier de production neuf.

**Échecs pré-existants non imputables** : aucun rencontré sur les filtres exécutés (suite verte 182/182).

#### Corrections post-review (2026-06-28)

Suite à la code review, 9 corrections auto-fixables appliquées (aucun changement de comportement du moteur — AC7a tenu : `StateCompiler`/`StateMaille`/`AgentServiceProvider` NON modifiés ; vérifié par `git diff --name-only`) :

- **C1 (P7) — Guard `target_label` vide (defense-in-depth)** : `UpstreamContractSource::ensureResolved()` ignore désormais (`continue`) un item `label` dont `target_label` est `null`/`''` avant indexation dans `$groupedByLabel` ; `labelsCarriedBy()` filtre en plus `->where('controlhub_label', '!=', '')`. Garde SYMÉTRIQUE des deux côtés du rattachement par nom — jamais d'injection via un label vide.
- **C2 (P2) — PHPDoc durée de vie du cache** : propriété `$labelsCarriedByWorkstation` documentée comme cache PAR-COMPILATION / PAR-REQUÊTE ; en worker long-running l'invalidation doit couvrir AUSSI un changement d'appartenance poste↔parc (pas seulement `ControlHubContractChanged`). Hors scope PHP-FPM actuel.
- **C3 (P1+M1+M2) — Warning sur verrous concordants documenté** : PHPDoc de la frontière 30.4↔30.5 enrichie — deux candidats `Upstream` rang -1 de même clé (2 labels distincts, OU item instance + item label = cas réaliste) déclenchent `agent.state.conflict` MÊME à valeurs identiques (`resolveExclusiveWinner` ne compare pas les payloads). Symétrique au rang 6 (2 permissifs plancher). 30.4 EXPOSE ; prévention + adoucissement = 30.5. Moteur NON touché, pas de dédup payload.
- **C4 (P9) — Commentaire R3 corrigé** : `r3_no_central_identifier` — le commentaire précise que la tokenisation vérifie les LITTÉRAUX de chaîne (`T_CONSTANT_ENCAPSED_STRING`/`T_ENCAPSED_AND_WHITESPACE`) ET les identifiants bareword (`T_STRING`), donc PLUS protecteur qu'un `file_get_contents` brut, et jamais les commentaires.
- **C5 (P3) — Assertion WG=0 (no-contract)** : `no_active_contract_output_is_byte_identical_and_single_cheap_query` asserte désormais ZÉRO requête `workstation_groups` (court-circuit `$groupedByLabel === []` sans contrat).
- **C6 (P4) — Test salle physique portant le label** : nouveau `label_item_applies_to_workstation_in_physical_group_carrying_the_label` (chemin `physicalGroupIds`, `workstationGroupIds = physical ∪ logical`). Helper `contextCarryingLabels()` paramétré (`bool $physical`) + méthode `physical()` ajoutée à `WorkstationGroupFactory`.
- **C7 (P5) — Test poste sans aucun groupe** : nouveau `label_item_does_not_apply_when_workstation_has_no_group` — exerce le court-circuit `groupIds === []` de `labelsCarriedBy()` ; item label non injecté, local survit.
- **C8 (M4) — Test union instance ∪ label** : nouveau `instance_and_label_items_are_both_served_to_a_carrying_workstation` — item instance (K1) + item label (K2) sur poste porteur → les DEUX servis (preuve de la concaténation).
- **C9 (P6) — Déterminisme du test collision** : `two_locked_labels_same_key_emit_conflict_and_pick_deterministically` force des `updated_at` ÉGAUX (timestamp figé) sur les deux items pour prouver réellement le critère secondaire `sourceId` desc, sans s'appuyer sur l'ordre de récence ; commentaire corrigé.

**Non corrigés (décision)** : P8 (doublons doc QA, dette préexistante), P1 comportement (silence warning → 30.5), M3 (sort labels inoffensif), M5 (correct).

**Résultats de tests (post-corrections, HÔTE, `CACHE_DRIVER=array`)** :
- `--filter UpstreamContractResolution` → **21 tests / 296 assertions, OK** (18 + 3 nets : C6/C7/C8).
- `--filter 'StateCompiler|ControlHubContract|Upstream|Imposed|WorkstationGroupLabel'` → **185 tests / 969 assertions, OK** (0 régression).
- `git diff --name-only` → `UpstreamContractSource.php`, `WorkstationGroupFactory.php`, `UpstreamContractResolutionTest.php` (preuve AC7a : moteur intouché).
- `grep -niE '\bcentral\b'` sur les fichiers de résolution → uniquement les commentaires garde-fou « aucun central ».

### File List

- `app/Services/ControlHub/Resolution/UpstreamContractSource.php` (modifié — expansion label + helper `labelsCarriedBy` + court-circuit NFR3 + PHPDoc)
- `app/Services/ControlHub/Resolution/UpstreamAwareProvider.php` (modifié — relais `$ctx`)
- `tests/Feature/ControlHub/UpstreamContractResolutionTest.php` (modifié — 6 tests nets initiaux + 3 post-review C6/C7/C8, réécriture du test label 28.3, helpers, R3 étendu)
- `database/factories/WorkstationGroupFactory.php` (modifié post-review C6 — méthode `physical()`)
- `docs/qa/domains/controlhub-contract.md` (append — Section 12 + checklist Story 30.4)
- `docs/qa/README.md` (append — ligne domaine controlhub-contract pour 30.4)
